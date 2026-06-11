<?php
/**
 * MainWP Backup Upload Driver.
 *
 * Drives resumable snapshot uploads to configured storage destinations.
 * When a snapshot records, the driver enqueues one job per destination on
 * the task. Each cron tick acquires one runnable job under a lease lock
 * and works through its phases with a time budget: chunk-plan the
 * artifacts, upload only what the destination is missing (verifying size
 * before skipping anything "already present"), then submit a manifest
 * object. Every unit of work checkpoints, so an interrupted pass resumes
 * from verified state instead of restarting. Failures map by kind: auth
 * rejections park the destination for re-entered credentials, transient
 * trouble backs off exponentially, exhausted attempts go permanent.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Backup_Upload_Driver
 *
 * @package MainWP\Dashboard
 */
class MainWP_Backup_Upload_Driver { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Retryable failures allowed before a job goes permanent.
     *
     * @var int
     */
    const MAX_ATTEMPTS = 7;

    /**
     * Base retry delay in seconds; doubles per attempt.
     *
     * @var int
     */
    const RETRY_BASE_SECONDS = 300;

    /**
     * Retry delay ceiling in seconds (4 hours).
     *
     * @var int
     */
    const RETRY_MAX_SECONDS = 14400;

    /**
     * Default per-tick work budget in seconds.
     *
     * @var int
     */
    const DEFAULT_BUDGET_SECONDS = 240;

    /**
     * Instance variable class.
     *
     * @static
     * @var (self|null) $instance Instance of MainWP_Backup_Upload_Driver or null.
     */
    private static $instance = null;

    /**
     * Method instance()
     *
     * Create public static instance.
     *
     * @static
     * @return MainWP_Backup_Upload_Driver
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Register the snapshot listener that enqueues upload jobs.
     *
     * @return void
     */
    public function init(): void {
        add_action( 'mainwp_backup_snapshot_recorded', array( $this, 'on_snapshot_recorded' ), 10, 3 );
    }

    /**
     * Enqueue one upload job per destination configured on the task.
     * Tasks without destinations upload nowhere; cloud storage is
     * explicit opt-in per task.
     *
     * @param int $snapshot_id Snapshot id.
     * @param int $task_id     Scheduled task id.
     * @param int $site_id     Child site id.
     *
     * @return void
     */
    public function on_snapshot_recorded( $snapshot_id, $task_id, $site_id ): void {
        unset( $site_id );

        if ( empty( $task_id ) ) {
            return;
        }

        $task = MainWP_DB_Backup::instance()->get_backup_task_by_id( (int) $task_id );
        if ( empty( $task ) || empty( $task->destinations ) ) {
            return;
        }

        $destination_ids = json_decode( (string) $task->destinations, true );
        if ( ! is_array( $destination_ids ) || empty( $destination_ids ) ) {
            return;
        }

        MainWP_Backup_Upload_Job_Store::instance()->enqueue_for_snapshot( (int) $snapshot_id, array_map( 'intval', $destination_ids ) );
    }

    /**
     * Acquire and run one pending upload job within a time budget. Rides
     * the backups-continue cron lane.
     *
     * @param int      $budget_seconds Work budget for this tick.
     * @param int|null $now            Clock override for tests.
     *
     * @return void
     */
    public function process_pending_jobs( int $budget_seconds = self::DEFAULT_BUDGET_SECONDS, ?int $now = null ): void {
        $now   = null === $now ? time() : $now;
        $store = MainWP_Backup_Upload_Job_Store::instance();
        $token = uniqid( 'upload_', true );

        $job = $store->acquire_next_job( $token, max( 300, $budget_seconds + 60 ), $now );
        if ( null === $job ) {
            return;
        }

        try {
            $this->run_job( $job, microtime( true ) + $budget_seconds, $now );
        } finally {
            $store->release_lock( (int) $job->id, $token );
        }
    }

    /**
     * Run one job until it completes, fails, or the deadline passes.
     *
     * @param object $job      The acquired job row.
     * @param float  $deadline Monotonic deadline from microtime(true).
     * @param int    $now      Wall-clock for retry scheduling.
     *
     * @return void
     */
    private function run_job( object $job, float $deadline, int $now ): void {
        $store = MainWP_Backup_Upload_Job_Store::instance();

        $snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( (int) $job->snapshot_id );
        if ( null === $snapshot ) {
            $store->fail_permanent( (int) $job->id, 'Snapshot record is gone.' );
            $this->cleanup_staging( $job );
            return;
        }

        $destination = MainWP_Backup_Storage_Destinations::instance()->get_destination( (int) $job->destination_id );
        if ( null === $destination ) {
            $store->fail_permanent( (int) $job->id, 'Storage destination was deleted.' );
            $this->cleanup_staging( $job );
            return;
        }

        if ( $destination->credentials_invalid ) {
            $store->invalidate_credentials( (int) $job->id, 'Stored credentials no longer decrypt; re-enter them on the destination.' );
            $this->cleanup_staging( $job );
            return;
        }

        try {
            $provider = MainWP_Backup_Storage_Destinations::instance()->make_provider( $destination );
        } catch ( \RuntimeException $e ) {
            $store->fail_permanent( (int) $job->id, $e->getMessage() );
            $this->cleanup_staging( $job );
            return;
        }

        try {
            $checkpoint = $job->checkpoint;
            if ( empty( $checkpoint['artifacts'] ) ) {
                $checkpoint = $this->new_checkpoint( $snapshot );
                $store->save_checkpoint( (int) $job->id, $checkpoint );
            }

            $status = $job->status;

            if ( MainWP_Backup_Upload_Job_Store::STATUS_PENDING === $status || MainWP_Backup_Upload_Job_Store::STATUS_FAILED_RETRYABLE === $status ) {
                $status = MainWP_Backup_Upload_Job_Store::STATUS_CHUNKING;
                $store->set_status( (int) $job->id, $status );
            }

            if ( MainWP_Backup_Upload_Job_Store::STATUS_CHUNKING === $status ) {
                if ( ! $this->phase_chunking( $job, $checkpoint, $deadline ) ) {
                    return; // Deadline; the next tick resumes from the checkpoint.
                }
                $status = MainWP_Backup_Upload_Job_Store::STATUS_UPLOADING;
                $store->set_status( (int) $job->id, $status );
            }

            if ( MainWP_Backup_Upload_Job_Store::STATUS_UPLOADING === $status ) {
                if ( ! $this->phase_uploading( $job, $checkpoint, $provider, $deadline ) ) {
                    return;
                }
                $status = MainWP_Backup_Upload_Job_Store::STATUS_SUBMITTING;
                $store->set_status( (int) $job->id, $status );
            }

            if ( MainWP_Backup_Upload_Job_Store::STATUS_SUBMITTING === $status ) {
                if ( microtime( true ) >= $deadline ) {
                    return;
                }
                $this->phase_submitting( $job, $checkpoint, $snapshot, $provider );
            }

            $store->complete_job( (int) $job->id );
            $this->cleanup_staging( $job );

            /**
             * Fires after a snapshot finishes uploading to a destination.
             *
             * @param array $event Upload details: site_id, task_id, snapshot_id,
             *                     destination_id, destination_name, provider,
             *                     objects, bytes.
             */
            do_action(
                'mainwp_backup_uploaded',
                array(
                    'site_id'          => (int) $snapshot->site_id,
                    'task_id'          => (int) $snapshot->task_id,
                    'snapshot_id'      => (int) $snapshot->id,
                    'destination_id'   => (int) $destination->id,
                    'destination_name' => (string) $destination->name,
                    'provider'         => (string) $destination->provider,
                    'objects'          => $this->count_objects( $checkpoint ),
                    'bytes'            => $this->count_bytes( $checkpoint ),
                )
            );
        } catch ( \Throwable $e ) {
            $this->handle_failure( $job, $destination, $e, $now );
        }
    }

    /**
     * Build a fresh checkpoint from the snapshot's artifact list.
     *
     * @param object $snapshot Snapshot row with decoded archives.
     *
     * @return array
     */
    private function new_checkpoint( object $snapshot ): array {
        /**
         * Filters the upload chunk size in bytes.
         *
         * @param int $size Chunk size.
         */
        $chunk_size = (int) apply_filters( 'mainwp_backup_upload_chunk_size', MainWP_Backup_Chunker::chunk_size() );

        /**
         * Filters the whole-file upload threshold in bytes. Artifacts under
         * it upload as single objects instead of chunking.
         *
         * @param int $threshold Threshold.
         */
        $threshold = (int) apply_filters( 'mainwp_backup_upload_whole_file_threshold', MainWP_Backup_Chunker::small_file_threshold() );

        $artifacts = array();
        foreach ( $snapshot->archives as $entry ) {
            if ( empty( $entry['path'] ) ) {
                continue;
            }
            $size        = isset( $entry['size'] ) ? (int) $entry['size'] : 0;
            $artifacts[] = array(
                'path'          => (string) $entry['path'],
                'file'          => isset( $entry['file'] ) ? (string) $entry['file'] : basename( $entry['path'] ),
                'role'          => isset( $entry['role'] ) ? (string) $entry['role'] : '',
                'size'          => $size,
                'mode'          => $size >= $threshold ? 'chunked' : 'whole',
                'chunks'        => null,
                'chunks_cursor' => 0,
                'done'          => false,
            );
        }

        return array(
            'artifacts'          => $artifacts,
            'chunk_size'         => max( 1024, $chunk_size ),
            'whole_threshold'    => $threshold,
            'manifest_submitted' => false,
        );
    }

    /**
     * Chunk-plan every chunked artifact, checkpointing per artifact.
     *
     * @param object $job        Job row.
     * @param array  $checkpoint Checkpoint, modified in place.
     * @param float  $deadline   Monotonic deadline.
     *
     * @return bool True when the phase finished; false on deadline.
     *
     * @throws \RuntimeException When a source artifact disappeared.
     */
    private function phase_chunking( object $job, array &$checkpoint, float $deadline ): bool {
        $store = MainWP_Backup_Upload_Job_Store::instance();

        foreach ( $checkpoint['artifacts'] as $index => $artifact ) {
            if ( 'chunked' !== $artifact['mode'] || is_array( $artifact['chunks'] ) ) {
                continue;
            }
            if ( microtime( true ) >= $deadline ) {
                $store->save_checkpoint( (int) $job->id, $checkpoint );
                return false;
            }

            $chunks = MainWP_Backup_Chunker::plan_artifact( $artifact['path'], $this->staging_dir( $job ), (int) $checkpoint['chunk_size'] );
            if ( false === $chunks ) {
                throw new \RuntimeException( esc_html( 'Backup artifact is missing locally: ' . $artifact['file'] ), 410 );
            }

            foreach ( $chunks as &$chunk ) {
                $chunk['remote_present'] = false;
            }
            unset( $chunk );

            $checkpoint['artifacts'][ $index ]['chunks'] = $chunks;
            $store->save_checkpoint( (int) $job->id, $checkpoint );
        }

        $store->save_checkpoint( (int) $job->id, $checkpoint );
        return true;
    }

    /**
     * Upload everything the destination is missing, verifying size before
     * skipping anything already present, checkpointing per unit.
     *
     * @param object                         $job        Job row.
     * @param array                          $checkpoint Checkpoint, modified in place.
     * @param MainWP_Backup_Storage_Provider $provider   Destination provider.
     * @param float                          $deadline   Monotonic deadline.
     *
     * @return bool True when the phase finished; false on deadline.
     *
     * @throws \RuntimeException When a source artifact disappeared.
     */
    private function phase_uploading( object $job, array &$checkpoint, MainWP_Backup_Storage_Provider $provider, float $deadline ): bool {
        $store  = MainWP_Backup_Upload_Job_Store::instance();
        $prefix = $this->remote_prefix( $job );

        foreach ( $checkpoint['artifacts'] as $index => $artifact ) {
            if ( ! empty( $artifact['done'] ) ) {
                continue;
            }

            if ( 'whole' === $artifact['mode'] ) {
                if ( microtime( true ) >= $deadline ) {
                    $store->save_checkpoint( (int) $job->id, $checkpoint );
                    return false;
                }
                $this->upload_whole_artifact( $artifact, $prefix, $provider );
                $checkpoint['artifacts'][ $index ]['done'] = true;
                $store->save_checkpoint( (int) $job->id, $checkpoint );
                continue;
            }

            $chunks = is_array( $artifact['chunks'] ) ? $artifact['chunks'] : array();
            $cursor = (int) $artifact['chunks_cursor'];

            $total = count( $chunks );
            for ( $i = $cursor; $i < $total; $i++ ) {
                if ( microtime( true ) >= $deadline ) {
                    $store->save_checkpoint( (int) $job->id, $checkpoint );
                    return false;
                }

                $this->upload_chunk( $artifact, $chunks[ $i ], $prefix, $provider, $job );

                $checkpoint['artifacts'][ $index ]['chunks'][ $i ]['remote_present'] = true;
                $checkpoint['artifacts'][ $index ]['chunks_cursor']                  = $i + 1;
                $store->save_checkpoint( (int) $job->id, $checkpoint );
            }

            $checkpoint['artifacts'][ $index ]['done'] = true;
            $store->save_checkpoint( (int) $job->id, $checkpoint );
        }

        return true;
    }

    /**
     * Submit the per-snapshot manifest object. A re-PUT of the same
     * manifest is idempotent by construction.
     *
     * @param object                         $job        Job row.
     * @param array                          $checkpoint Checkpoint.
     * @param object                         $snapshot   Snapshot row.
     * @param MainWP_Backup_Storage_Provider $provider   Destination provider.
     *
     * @return void
     */
    private function phase_submitting( object $job, array &$checkpoint, object $snapshot, MainWP_Backup_Storage_Provider $provider ): void {
        $artifacts = array();
        foreach ( $checkpoint['artifacts'] as $artifact ) {
            $entry = array(
                'file' => $artifact['file'],
                'role' => $artifact['role'],
                'size' => $artifact['size'],
                'mode' => $artifact['mode'],
            );
            if ( 'chunked' === $artifact['mode'] && is_array( $artifact['chunks'] ) ) {
                $entry['chunks'] = array_map(
                    function ( $chunk ) {
                        return array(
                            'hash'   => $chunk['hash'],
                            'algo'   => $chunk['algo'],
                            'offset' => $chunk['offset'],
                            'length' => $chunk['length'],
                        );
                    },
                    $artifact['chunks']
                );
            } else {
                $entry['key'] = 'files/' . $artifact['file'];
            }
            $artifacts[] = $entry;
        }

        $manifest = array(
            'format'      => 1,
            'snapshot_id' => (int) $snapshot->id,
            'task_id'     => (int) $snapshot->task_id,
            'site_id'     => (int) $snapshot->site_id,
            'type'        => (string) $snapshot->type,
            'created'     => (int) $snapshot->created,
            'parent_id'   => empty( $snapshot->parent_id ) ? null : (int) $snapshot->parent_id,
            'artifacts'   => $artifacts,
        );

        $provider->put_object( $this->remote_prefix( $job ) . 'manifest.json', (string) wp_json_encode( $manifest ), 'application/json' );

        $checkpoint['manifest_submitted'] = true;
        MainWP_Backup_Upload_Job_Store::instance()->save_checkpoint( (int) $job->id, $checkpoint );
    }

    /**
     * Upload a whole (unchunked) artifact unless the destination already
     * holds it at the right size.
     *
     * @param array                          $artifact Artifact checkpoint entry.
     * @param string                         $prefix   Remote key prefix.
     * @param MainWP_Backup_Storage_Provider $provider Destination provider.
     *
     * @return void
     *
     * @throws \RuntimeException When the source file disappeared.
     */
    private function upload_whole_artifact( array $artifact, string $prefix, MainWP_Backup_Storage_Provider $provider ): void {
        $key = $prefix . 'files/' . $artifact['file'];

        if ( ! is_file( $artifact['path'] ) ) {
            throw new \RuntimeException( esc_html( 'Backup artifact is missing locally: ' . $artifact['file'] ), 410 );
        }

        clearstatcache( true, $artifact['path'] );
        $size = (int) filesize( $artifact['path'] );

        $head = $provider->head_object( $key );
        if ( is_array( $head ) && (int) $head['size'] === $size ) {
            return; // Verified present.
        }

        $provider->put_object( $key, (string) file_get_contents( $artifact['path'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local artifact read.
    }

    /**
     * Upload one chunk unless the destination already holds it at the
     * right size; reclaim the staged copy once it is confirmed remote.
     *
     * @param array                          $artifact Artifact checkpoint entry.
     * @param array                          $chunk    Chunk entry: hash, algo, offset, length.
     * @param string                         $prefix   Remote key prefix.
     * @param MainWP_Backup_Storage_Provider $provider Destination provider.
     * @param object                         $job      Job row, for the staging dir.
     *
     * @return void
     *
     * @throws \RuntimeException When neither the staged chunk nor the source artifact can supply the bytes.
     */
    private function upload_chunk( array $artifact, array $chunk, string $prefix, MainWP_Backup_Storage_Provider $provider, object $job ): void {
        $key    = $prefix . 'chunks/' . $chunk['hash'] . '.bin';
        $staged = $this->staging_dir( $job ) . DIRECTORY_SEPARATOR . $chunk['hash'] . '.bin';

        $head = $provider->head_object( $key );
        if ( is_array( $head ) && (int) $head['size'] === (int) $chunk['length'] ) {
            if ( is_file( $staged ) ) {
                unlink( $staged ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- progressive staging reclaim.
            }
            return; // Verified present at the destination.
        }

        $bytes = $this->read_chunk_bytes( $artifact, $chunk, $staged );

        $provider->put_object( $key, $bytes );

        if ( is_file( $staged ) ) {
            unlink( $staged ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- progressive staging reclaim.
        }
    }

    /**
     * Read a chunk's bytes: a size-verified staged copy first, the source
     * artifact slice as the fallback.
     *
     * @param array  $artifact Artifact checkpoint entry.
     * @param array  $chunk    Chunk entry.
     * @param string $staged   Staged chunk path.
     *
     * @return string
     *
     * @throws \RuntimeException When no readable source remains.
     */
    private function read_chunk_bytes( array $artifact, array $chunk, string $staged ): string {
        clearstatcache();

        if ( is_file( $staged ) && filesize( $staged ) === (int) $chunk['length'] ) {
            $bytes = file_get_contents( $staged ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- staged chunk read.
            if ( false !== $bytes ) {
                return $bytes;
            }
        }

        if ( is_file( $artifact['path'] ) ) {
            $handle = fopen( $artifact['path'], 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- sliced artifact read.
            if ( false !== $handle ) {
                try {
                    if ( 0 === fseek( $handle, (int) $chunk['offset'] ) ) {
                        $bytes = fread( $handle, (int) $chunk['length'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- sliced artifact read.
                        if ( false !== $bytes && strlen( $bytes ) === (int) $chunk['length'] ) {
                            return $bytes;
                        }
                    }
                } finally {
                    fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- paired with fopen above.
                }
            }
        }

        throw new \RuntimeException( esc_html( 'Chunk bytes are unavailable locally for ' . $artifact['file'] ), 410 );
    }

    /**
     * Map a failure to its terminal or retryable outcome.
     *
     * @param object     $job         Job row.
     * @param object     $destination Destination row.
     * @param \Throwable $e           The failure.
     * @param int        $now         Wall-clock for retry scheduling.
     *
     * @return void
     */
    private function handle_failure( object $job, object $destination, \Throwable $e, int $now ): void {
        $store = MainWP_Backup_Upload_Job_Store::instance();
        $code  = (int) $e->getCode();

        $error = '[' . $this->error_code_for_provider( (string) $destination->provider ) . '] ' . $e->getMessage();

        if ( 401 === $code || 403 === $code ) {
            $store->invalidate_credentials( (int) $job->id, $error );
            $this->cleanup_staging( $job );
            return;
        }

        if ( 410 === $code || (int) $job->attempt_count + 1 >= static::MAX_ATTEMPTS ) {
            $store->fail_permanent( (int) $job->id, $error );
            $this->cleanup_staging( $job );
            return;
        }

        $delay = min( static::RETRY_MAX_SECONDS, static::RETRY_BASE_SECONDS * ( 2 ** (int) $job->attempt_count ) );
        $store->fail_retryable( (int) $job->id, $error, $now + $delay );
    }

    /**
     * The registry error code matching a provider.
     *
     * @param string $provider Provider slug.
     *
     * @return string
     */
    private function error_code_for_provider( string $provider ): string {
        switch ( $provider ) {
            case 's3':
                return MainWP_Backup_Error_Codes::ERR_S3_FAILED;
            case 'dropbox':
                return MainWP_Backup_Error_Codes::ERR_DROPBOX_FAILED;
            case 'ftp':
            case 'ftps':
            case 'sftp':
                return MainWP_Backup_Error_Codes::ERR_FTP_FAILED;
            default:
                return MainWP_Backup_Error_Codes::ERR_S3_FAILED;
        }
    }

    /**
     * The remote key prefix for a job's snapshot at its destination.
     *
     * @param object $job Job row.
     *
     * @return string
     */
    private function remote_prefix( object $job ): string {
        $snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( (int) $job->snapshot_id );
        $site_id  = null !== $snapshot ? (int) $snapshot->site_id : 0;
        return $site_id . '/' . (int) $job->snapshot_id . '/';
    }

    /**
     * The local staging directory for a job's chunks.
     *
     * @param object $job Job row.
     *
     * @return string
     */
    private function staging_dir( object $job ): string {
        $upload = wp_upload_dir();
        return trailingslashit( $upload['basedir'] ) . 'mainwp/upload-staging/' . (int) $job->snapshot_id . '-' . (int) $job->destination_id;
    }

    /**
     * Remove a job's staging directory.
     *
     * @param object $job Job row.
     *
     * @return void
     */
    private function cleanup_staging( object $job ): void {
        $dir = $this->staging_dir( $job );
        if ( ! is_dir( $dir ) ) {
            return;
        }

        foreach ( glob( $dir . DIRECTORY_SEPARATOR . '*' ) as $file ) {
            if ( is_file( $file ) ) {
                unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- staging cleanup.
            }
        }
        rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- staging cleanup.
    }

    /**
     * Total objects the upload produced (chunks, whole files, manifest).
     *
     * @param array $checkpoint Checkpoint.
     *
     * @return int
     */
    private function count_objects( array $checkpoint ): int {
        $count = 1; // The manifest.
        foreach ( $checkpoint['artifacts'] as $artifact ) {
            $count += 'chunked' === $artifact['mode'] && is_array( $artifact['chunks'] ) ? count( $artifact['chunks'] ) : 1;
        }
        return $count;
    }

    /**
     * Total artifact bytes the upload covered.
     *
     * @param array $checkpoint Checkpoint.
     *
     * @return int
     */
    private function count_bytes( array $checkpoint ): int {
        $bytes = 0;
        foreach ( $checkpoint['artifacts'] as $artifact ) {
            $bytes += (int) $artifact['size'];
        }
        return $bytes;
    }
}
