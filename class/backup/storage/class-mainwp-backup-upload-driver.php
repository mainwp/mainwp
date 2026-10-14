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
     * Default per-tick work budget in seconds.
     *
     * @var int
     */
    const DEFAULT_BUDGET_SECONDS = 240;

    /**
     * Distinguish operator-fixable private storage failures from provider errors.
     *
     * @var int
     */
    private const PRIVATE_ROOT_FAILURE = 10001;

    /**
     * A KEK read failure can recover without changing the snapshot's key version.
     *
     * @var int
     */
    private const KEK_UNAVAILABLE = 10002;

    /**
     * Maximum jobs attempted in one tick.
     *
     * @var int
     */
    const MAX_JOBS_PER_TICK = 10;

    /**
     * Instance variable class.
     *
     * @static
     * @var (self|null) $instance Instance of MainWP_Backup_Upload_Driver or null.
     */
    private static $instance = null;

    /**
     * The lease token held for the job being run, null outside a run.
     * Every store write during the run is fenced on it, so a worker whose
     * lease was reclaimed can no longer overwrite the new holder's state.
     *
     * @var string|null
     */
    private ?string $lock_token = null;

    /**
     * The lease duration renewals extend by, matching the acquisition.
     *
     * @var int
     */
    private int $lease_seconds = 300;

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
        add_action( 'mainwp_backup_upload_job_settled', array( $this, 'resolve_snapshot_offsite_state' ), 10, 1 );
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
        $this->resolve_snapshot_offsite_state( (int) $snapshot_id );
    }

    /**
     * Recompute a snapshot's offsite-verified state from its non-local
     * upload jobs and persist it. Runs at enqueue and on every terminal
     * upload transition (via mainwp_backup_upload_job_settled), so an
     * all-failed snapshot reaches failed instead of sticking at pending.
     *
     * Local-provider jobs never count - offsite must mean off the
     * dashboard too - and a blank provider (a legacy, pre-migration row)
     * is unknown and can never grant verified.
     *
     * @param int $snapshot_id Snapshot id.
     *
     * @return void
     */
    public function resolve_snapshot_offsite_state( int $snapshot_id ): void {
        $jobs   = MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( $snapshot_id, MainWP_Backup_Upload_Job_Store::KIND_UPLOAD );
        $active = MainWP_Backup_Upload_Job_Store::active_statuses();

        $relevant = array();
        foreach ( $jobs as $job ) {
            $provider = isset( $job->provider ) ? (string) $job->provider : '';
            if ( '' === $provider || 'local' === $provider ) {
                continue;
            }
            $relevant[] = $job;
        }

        $snapshots = MainWP_Backup_Snapshots::instance();

        if ( empty( $relevant ) ) {
            $snapshots->set_offsite_state( $snapshot_id, '' );
            return;
        }

        $complete = 0;
        foreach ( $relevant as $job ) {
            if ( MainWP_Backup_Upload_Job_Store::STATUS_COMPLETE === $job->status ) {
                ++$complete;
            } elseif ( in_array( $job->status, $active, true ) ) {
                $snapshots->set_offsite_state( $snapshot_id, 'pending' );
                return;
            }
        }

        if ( count( $relevant ) === $complete ) {
            $snapshots->set_offsite_state( $snapshot_id, 'verified', time() );

            /**
             * Fires when every non-local copy of a snapshot is verified
             * present at its destination (chunks, whole artifacts and the
             * manifest all HEAD-confirmed).
             *
             * @param int $snapshot_id Snapshot id.
             */
            do_action( 'mainwp_backup_snapshot_offsite_verified', $snapshot_id );
            return;
        }

        $snapshots->set_offsite_state( $snapshot_id, $complete > 0 ? 'partial' : 'failed' );
    }

    /**
     * Run pending uploads within one shared time budget on the
     * backups-continue cron lane. Stop after MAX_JOBS_PER_TICK attempts
     * or a repeated acquisition, so a parked job cannot spin within the tick.
     * A repeated acquisition ends the tick; jobs behind it wait for the next one.
     * A zero budget still allows one attempt to checkpoint.
     *
     * @param int      $budget_seconds Work budget for this tick.
     * @param int|null $now            Clock override for tests.
     *
     * @return void
     */
    public function process_pending_jobs( int $budget_seconds = self::DEFAULT_BUDGET_SECONDS, ?int $now = null ): void {
        $deadline      = microtime( true ) + $budget_seconds;
        $store         = MainWP_Backup_Upload_Job_Store::instance();
        $lease_seconds = max( 300, $budget_seconds + 60 );
        $attempted     = array();
        $job_count     = 0;

        do {
            $iteration_now = null === $now ? time() : $now;
            $token         = uniqid( 'upload_', true );
            $job           = $store->acquire_next_job( $token, $lease_seconds, $iteration_now );
            if ( null === $job ) {
                return;
            }

            $this->lock_token    = $token;
            $this->lease_seconds = $lease_seconds;
            try {
                if ( isset( $attempted[ (int) $job->id ] ) ) {
                    return;
                }
                $attempted[ (int) $job->id ] = true;
                ++$job_count;
                $this->run_job( $job, $deadline, $iteration_now );
            } finally {
                $this->lock_token = null;
                $store->release_lock( (int) $job->id, $token );
            }
        } while ( $job_count < self::MAX_JOBS_PER_TICK && microtime( true ) < $deadline );
    }

    /**
     * Renew the run's lease between long provider round trips.
     *
     * False means the lease was already reclaimed: the caller stops its
     * work instead of writing over the new holder. Every store write is
     * fenced on the token anyway; this probe stops the wasted provider
     * traffic (and duplicate uploads/deletes) as early as possible.
     *
     * @param object $job Job row.
     *
     * @return bool True when work on the job may continue.
     */
    private function lease_still_held( object $job ): bool {
        if ( null === $this->lock_token ) {
            return true;
        }
        return MainWP_Backup_Upload_Job_Store::instance()->renew_lease( (int) $job->id, $this->lock_token, $this->lease_seconds );
    }

    /**
     * Run one job until it completes, fails, or the deadline passes.
     *
     * @param object $job      The acquired job row.
     * @param float  $deadline Monotonic deadline from microtime(true).
     * @param int    $now      Wall-clock for retry scheduling.
     *
     * @return void
     * @throws \RuntimeException When a local archive differs from its recorded size; handled as a permanent upload failure.
     */
    private function run_job( object $job, float $deadline, int $now ): void {
        $store = MainWP_Backup_Upload_Job_Store::instance();

        $snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( (int) $job->snapshot_id );
        if ( null === $snapshot ) {
            $error = 'Snapshot record is gone.';
            // Terminal writes are fenced and checked: a worker whose lease
            // was reclaimed gets a refusal and must not clean staging or
            // fire events for a job the new holder now owns.
            if ( $store->fail_permanent( (int) $job->id, $error, $this->lock_token ) ) {
                $this->cleanup_staging( $job );
                $this->fire_upload_failed( $job, null, $error );
            }
            return;
        }

        $destination = MainWP_Backup_Storage_Destinations::instance()->get_destination( (int) $job->destination_id );
        if ( null === $destination ) {
            $error = 'Storage destination was deleted.';
            if ( $store->fail_permanent( (int) $job->id, $error, $this->lock_token ) ) {
                $this->cleanup_staging( $job );
                $this->fire_upload_failed( $job, null, $error );
            }
            return;
        }

        if ( $destination->credentials_invalid ) {
            $error = 'Stored credentials no longer decrypt; re-enter them on the destination.';
            if ( $store->invalidate_credentials( (int) $job->id, $error, $this->lock_token ) ) {
                $this->cleanup_staging( $job );
                $this->fire_upload_failed( $job, $destination, $error );
            }
            return;
        }

        try {
            $provider = MainWP_Backup_Storage_Destinations::instance()->make_provider( $destination );
        } catch ( \RuntimeException $e ) {
            if ( $store->fail_permanent( (int) $job->id, $e->getMessage(), $this->lock_token ) ) {
                $this->cleanup_staging( $job );
                $this->fire_upload_failed( $job, $destination, $e->getMessage() );
            }
            return;
        }

        try {
            // Check persisted encryption intent before any upload phase can
            // write archive bytes or a manifest to the provider.
            $enc = $this->encryption_context_for( $snapshot );

            $checkpoint = $job->checkpoint;
            if ( empty( $checkpoint['artifacts'] ) ) {
                $checkpoint = $this->new_checkpoint( $snapshot );
                if ( empty( $checkpoint['artifacts'] ) ) {
                    $error = 'The snapshot has no eligible artifacts to upload.';
                    if ( $store->fail_permanent( (int) $job->id, $error, $this->lock_token ) ) {
                        $this->cleanup_staging( $job );
                        $this->fire_upload_failed( $job, $destination, $error );
                    }
                    return;
                }
                if ( ! $store->save_checkpoint( (int) $job->id, $checkpoint, $this->lock_token ) ) {
                    // The artifact index is what a later restore reads to locate
                    // the offsite copies. A refused fenced write - transient DB
                    // error or a lost lease - means it did not land, so park and
                    // let the next tick rebuild it rather than upload and complete
                    // over an empty checkpoint the restore cannot use.
                    return;
                }
            }

            $status = $job->status;

            if ( in_array( $status, array( MainWP_Backup_Upload_Job_Store::STATUS_PENDING, MainWP_Backup_Upload_Job_Store::STATUS_FAILED_RETRYABLE, MainWP_Backup_Upload_Job_Store::STATUS_CHUNKING, MainWP_Backup_Upload_Job_Store::STATUS_UPLOADING ), true ) ) {
                $recorded_sizes = array();
                foreach ( (array) $snapshot->archives as $archive ) {
                    $recorded_sizes[ $archive['path'] ?? '' ] = $archive['size'] ?? null;
                }
                foreach ( $checkpoint['artifacts'] as $artifact ) {
                    if ( empty( $artifact['done'] ) && is_file( $artifact['path'] ) && ! MainWP_Backup_File_Manager::local_archive_size_matches( $artifact['path'], $recorded_sizes[ $artifact['path'] ] ?? null ) ) {
                        throw new \RuntimeException( __( 'The backup file on this Dashboard no longer matches this backup. It cannot be uploaded.', 'mainwp' ), 410 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Upload errors are escaped by their output consumers.
                    }
                }
            }

            if ( MainWP_Backup_Upload_Job_Store::STATUS_PENDING === $status || MainWP_Backup_Upload_Job_Store::STATUS_FAILED_RETRYABLE === $status ) {
                $status = MainWP_Backup_Upload_Job_Store::STATUS_CHUNKING;
                $store->set_status( (int) $job->id, $status, $this->lock_token );
            }

            if ( MainWP_Backup_Upload_Job_Store::STATUS_CHUNKING === $status ) {
                if ( ! $this->phase_chunking( $job, $checkpoint, $deadline ) ) {
                    return; // Deadline or lost lease; the next tick (or the new holder) resumes from the checkpoint.
                }
                $status = MainWP_Backup_Upload_Job_Store::STATUS_UPLOADING;
                $store->set_status( (int) $job->id, $status, $this->lock_token );
            }

            if ( MainWP_Backup_Upload_Job_Store::STATUS_UPLOADING === $status ) {
                if ( ! $this->phase_uploading( $job, $checkpoint, $provider, $deadline, $enc ) ) {
                    return;
                }
                $status = MainWP_Backup_Upload_Job_Store::STATUS_SUBMITTING;
                $store->set_status( (int) $job->id, $status, $this->lock_token );
            }

            if ( MainWP_Backup_Upload_Job_Store::STATUS_SUBMITTING === $status ) {
                if ( microtime( true ) >= $deadline || ! $this->lease_still_held( $job ) ) {
                    return;
                }
                if ( ! $this->phase_submitting( $job, $checkpoint, $snapshot, $provider, $enc ) ) {
                    // The manifest is at the destination, but its checkpoint did
                    // not persist. Do not complete over an unpersisted terminal
                    // state; the manifest re-PUT next tick is idempotent by
                    // construction, so the job resumes and completes once the
                    // checkpoint has actually landed.
                    return;
                }
            }

            if ( ! $store->complete_job( (int) $job->id, $this->lock_token ) ) {
                // Already terminal, or this worker's lease was reclaimed -
                // the completion (and its event below) belongs to the live
                // holder, and the staging dir is now theirs to clean.
                return;
            }
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
        foreach ( (array) $snapshot->archives as $entry ) {
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
     * @throws \RuntimeException When a source artifact disappeared or could not be read or staged.
     */
    private function phase_chunking( object $job, array &$checkpoint, float $deadline ): bool {
        $store = MainWP_Backup_Upload_Job_Store::instance();

        foreach ( $checkpoint['artifacts'] as $index => $artifact ) {
            if ( 'chunked' !== $artifact['mode'] || is_array( $artifact['chunks'] ) ) {
                continue;
            }
            if ( microtime( true ) >= $deadline ) {
                $store->save_checkpoint( (int) $job->id, $checkpoint, $this->lock_token );
                return false;
            }
            // Planning hashes multi-GB artifacts; renew so a slow-but-alive
            // pass keeps its lease, and stop when it was already reclaimed.
            if ( ! $this->lease_still_held( $job ) ) {
                return false;
            }

            $chunks = MainWP_Backup_Chunker::plan_artifact( $artifact['path'], $this->staging_dir( $job ), (int) $checkpoint['chunk_size'] );
            if ( false === $chunks ) {
                if ( ! is_file( $artifact['path'] ) ) {
                    throw new \RuntimeException(
                        sprintf( /* translators: %s: artifact file name. */ __( 'Backup file is missing from this Dashboard: %s', 'mainwp' ), basename( $artifact['file'] ) ), // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Upload errors are escaped by their output consumers.
                        410
                    );
                }
                throw new \RuntimeException(
                    sprintf( /* translators: %s: artifact file name. */ __( 'Backup file could not be read or prepared for upload: %s', 'mainwp' ), basename( $artifact['file'] ) ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Upload errors are escaped by their output consumers.
                );
            }

            foreach ( $chunks as &$chunk ) {
                $chunk['remote_present'] = false;
            }
            unset( $chunk );

            $checkpoint['artifacts'][ $index ]['chunks'] = $chunks;
            $store->save_checkpoint( (int) $job->id, $checkpoint, $this->lock_token );
        }

        $store->save_checkpoint( (int) $job->id, $checkpoint, $this->lock_token );
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
     * @param array                          $enc        Encryption context for the snapshot.
     *
     * @return bool True when the phase finished; false on deadline.
     *
     * @throws \RuntimeException When a source artifact disappeared.
     */
    private function phase_uploading( object $job, array &$checkpoint, MainWP_Backup_Storage_Provider $provider, float $deadline, array $enc ): bool {
        $store  = MainWP_Backup_Upload_Job_Store::instance();
        $prefix = $this->remote_prefix( $job );

        foreach ( $checkpoint['artifacts'] as $index => $artifact ) {
            if ( ! empty( $artifact['done'] ) ) {
                continue;
            }

            // The artifact's whole-file hash rides the checkpoint and the
            // manifest, so a later restore can verify the bytes end to end
            // no matter how they travel.
            if ( empty( $artifact['sha256'] ) ) {
                if ( microtime( true ) >= $deadline ) {
                    $store->save_checkpoint( (int) $job->id, $checkpoint, $this->lock_token );
                    return false;
                }
                if ( ! $this->lease_still_held( $job ) ) {
                    return false;
                }
                if ( ! is_file( $artifact['path'] ) ) {
                    throw new \RuntimeException( 'Backup file is missing from this Dashboard: ' . $artifact['file'], 410 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Upload errors are escaped by their output consumers.
                }
                $hash                                        = hash_file( 'sha256', $artifact['path'] );
                $artifact['sha256']                          = is_string( $hash ) ? $hash : '';
                $checkpoint['artifacts'][ $index ]['sha256'] = $artifact['sha256'];
                $store->save_checkpoint( (int) $job->id, $checkpoint, $this->lock_token );
            }

            if ( 'whole' === $artifact['mode'] ) {
                if ( microtime( true ) >= $deadline ) {
                    $store->save_checkpoint( (int) $job->id, $checkpoint, $this->lock_token );
                    return false;
                }
                // Renew across the blocking provider round trips (head,
                // put, confirming head); a reclaimed lease stops the
                // upload before it duplicates the new holder's work.
                if ( ! $this->lease_still_held( $job ) ) {
                    return false;
                }
                $this->upload_whole_artifact( $artifact, $prefix, $provider, $enc );
                $checkpoint['artifacts'][ $index ]['done'] = true;
                $store->save_checkpoint( (int) $job->id, $checkpoint, $this->lock_token );
                continue;
            }

            $chunks = is_array( $artifact['chunks'] ) ? $artifact['chunks'] : array();
            $cursor = (int) $artifact['chunks_cursor'];

            $total = count( $chunks );
            for ( $i = $cursor; $i < $total; $i++ ) {
                if ( microtime( true ) >= $deadline ) {
                    $store->save_checkpoint( (int) $job->id, $checkpoint, $this->lock_token );
                    return false;
                }
                if ( ! $this->lease_still_held( $job ) ) {
                    return false;
                }

                $this->upload_chunk( $artifact, $chunks[ $i ], $prefix, $provider, $job, $enc );

                $checkpoint['artifacts'][ $index ]['chunks'][ $i ]['remote_present'] = true;
                $checkpoint['artifacts'][ $index ]['chunks_cursor']                  = $i + 1;
                $store->save_checkpoint( (int) $job->id, $checkpoint, $this->lock_token );
            }

            $checkpoint['artifacts'][ $index ]['done'] = true;
            $store->save_checkpoint( (int) $job->id, $checkpoint, $this->lock_token );
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
     * @param array                          $enc        Encryption context for the snapshot.
     *
     * @return bool True when the terminal checkpoint landed; false when the
     *              fenced write was refused (transient DB error or lost lease)
     *              and the job must not complete this tick.
     */
    private function phase_submitting( object $job, array &$checkpoint, object $snapshot, MainWP_Backup_Storage_Provider $provider, array $enc ): bool {
        $artifacts = array();
        foreach ( $checkpoint['artifacts'] as $artifact ) {
            $entry = array(
                'file'   => $artifact['file'],
                'role'   => $artifact['role'],
                'size'   => $artifact['size'],
                'mode'   => $artifact['mode'],
                'sha256' => isset( $artifact['sha256'] ) ? (string) $artifact['sha256'] : '',
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

        // For an encrypted snapshot the manifest carries the keying material a
        // disaster recovery needs: the wrapped DEK, the passphrase-wrapped KEK
        // recovery blob, and the cipher markers. The manifest itself stays
        // plaintext - it holds only wrapped keys, never archive bytes - and a
        // sibling HMAC keyed by the KEK lets a recovering dashboard trust the
        // chunk list and hashes it reads back from the bucket.
        if ( ! empty( $enc['encrypt'] ) ) {
            $meta                   = is_array( $enc['meta'] ) ? $enc['meta'] : array();
            $manifest['encryption'] = array(
                // The recovering child gates on this marker (meta_is_encrypted);
                // without it every encrypted bundle is refused as plaintext.
                'enc'            => 1,
                'cipher'         => MainWP_Backup_Encryption::CIPHER_ID,
                'overhead'       => MainWP_Backup_Archive_Cipher::OVERHEAD,
                'wrapped_dek'    => isset( $meta['wrapped_dek'] ) ? (string) $meta['wrapped_dek'] : '',
                'kek_version_id' => isset( $meta['kek_version_id'] ) ? (string) $meta['kek_version_id'] : '',
            );
            $manifest['recovery']   = MainWP_Backup_Encryption::instance()->get_recovery_blob();
            $manifest['site_url']   = $this->snapshot_site_url( $snapshot );
        }

        $json = (string) wp_json_encode( $manifest );
        $key  = $this->remote_prefix( $job ) . 'manifest.json';
        $provider->put_object( $key, $json, 'application/json' );
        $this->confirm_remote_object( $provider, $key, strlen( $json ) );

        if ( ! empty( $enc['encrypt'] ) ) {
            $hmac     = MainWP_Backup_Encryption::instance()->manifest_hmac( $json, $enc['kek'] );
            $hmac_key = $this->remote_prefix( $job ) . 'manifest.json.hmac';
            $provider->put_object( $hmac_key, $hmac, 'text/plain' );
            $this->confirm_remote_object( $provider, $hmac_key, strlen( $hmac ) );
        }

        $checkpoint['manifest_submitted'] = true;
        return MainWP_Backup_Upload_Job_Store::instance()->save_checkpoint( (int) $job->id, $checkpoint, $this->lock_token );
    }

    /**
     * Upload a whole (unchunked) artifact unless the destination already
     * holds it at the right size.
     *
     * @param array                          $artifact Artifact checkpoint entry.
     * @param string                         $prefix   Remote key prefix.
     * @param MainWP_Backup_Storage_Provider $provider Destination provider.
     * @param array                          $enc      Encryption context for the snapshot.
     *
     * @return void
     *
     * @throws \RuntimeException When the source file disappeared or could not be read.
     */
    private function upload_whole_artifact( array $artifact, string $prefix, MainWP_Backup_Storage_Provider $provider, array $enc ): void {
        $key = $prefix . 'files/' . $artifact['file'];

        if ( ! is_file( $artifact['path'] ) ) {
            throw new \RuntimeException( 'Backup file is missing from this Dashboard: ' . $artifact['file'], 410 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Upload errors are escaped by their output consumers.
        }

        clearstatcache( true, $artifact['path'] );
        $size = (int) filesize( $artifact['path'] );

        // When sealed, the remote object carries the cipher framing overhead,
        // so the already-present check compares against the ciphertext size.
        $expected_remote = $size + $this->seal_overhead( $enc );

        $head = $provider->head_object( $key );
        if ( is_array( $head ) && (int) $head['size'] === $expected_remote ) {
            return; // Verified present.
        }

        $body = file_get_contents( $artifact['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local artifact read.
        if ( false === $body ) {
            // The file passed is_file() above, so a failed read can be transient;
            // a file that is really gone hits the 410 check on the next attempt.
            throw new \RuntimeException( 'Could not read backup artifact for upload: ' . $artifact['file'] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Upload errors are escaped by their output consumers.
        }

        $body = $this->maybe_seal_whole( $body, $enc, (string) $artifact['file'] );

        $provider->put_object( $key, $body );
        $this->confirm_remote_object( $provider, $key, strlen( $body ) );
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
     * @param array                          $enc      Encryption context for the snapshot.
     *
     * @return void
     *
     * @throws \RuntimeException When neither the staged chunk nor the source artifact can supply the bytes.
     */
    private function upload_chunk( array $artifact, array $chunk, string $prefix, MainWP_Backup_Storage_Provider $provider, object $job, array $enc ): void {
        $key    = $prefix . 'chunks/' . $chunk['hash'] . '.bin';
        $staged = $this->staging_dir( $job ) . DIRECTORY_SEPARATOR . $chunk['hash'] . '.bin';

        // When sealed, the remote object carries the cipher framing overhead,
        // so the already-present check compares against the ciphertext size.
        $expected_remote = (int) $chunk['length'] + $this->seal_overhead( $enc );

        $head = $provider->head_object( $key );
        if ( is_array( $head ) && (int) $head['size'] === $expected_remote ) {
            if ( is_file( $staged ) ) {
                unlink( $staged ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- progressive staging reclaim.
            }
            return; // Verified present at the destination.
        }

        $bytes = $this->read_chunk_bytes( $artifact, $chunk, $staged );
        $bytes = $this->maybe_seal_chunk( $bytes, $enc, (string) $chunk['hash'] );

        $provider->put_object( $key, $bytes );
        $this->confirm_remote_object( $provider, $key, strlen( $bytes ) );

        if ( is_file( $staged ) ) {
            unlink( $staged ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- progressive staging reclaim.
        }
    }

    /**
     * Confirm a just-written object is present at the destination at the
     * expected byte size. A mismatch throws retryable, so the job never
     * reaches complete - and the snapshot never reaches offsite-verified -
     * on a torn or truncated write. The verified state therefore implies a
     * remotely restorable copy, not just bytes that left the dashboard.
     *
     * @param MainWP_Backup_Storage_Provider $provider      Destination provider.
     * @param string                         $key           Object key just written.
     * @param int                            $expected_size Bytes written.
     *
     * @return void
     *
     * @throws \RuntimeException When the destination did not return the expected size.
     */
    private function confirm_remote_object( MainWP_Backup_Storage_Provider $provider, string $key, int $expected_size ): void {
        $head = $provider->head_object( $key );
        if ( ! is_array( $head ) || ! isset( $head['size'] ) || (int) $head['size'] !== $expected_size ) {
            throw new \RuntimeException( 'Upload verification failed; the destination did not confirm ' . $key . ' at the expected size.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Upload errors are escaped by their output consumers.
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

        // Only a source that can never supply these bytes again is permanent. A
        // full-length staged chunk (possibly the last copy once the archive is
        // gone) or an archive that still covers the chunk failed to read, so retry.
        clearstatcache( true, $artifact['path'] );
        clearstatcache( true, $staged );
        $covers = ( is_file( $staged ) && (int) filesize( $staged ) === (int) $chunk['length'] )
            || ( is_file( $artifact['path'] ) && (int) filesize( $artifact['path'] ) >= (int) $chunk['offset'] + (int) $chunk['length'] );
        throw new \RuntimeException( 'Chunk bytes are unavailable locally for ' . $artifact['file'], $covers ? 0 : 410 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Upload errors are escaped by their output consumers.
    }

    // phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing -- The registry factory returns MainWP_Exception.
    /**
     * Resolve a snapshot's encryption context for a tick: whether to seal, the
     * data key, the dashboard KEK (for the manifest HMAC), and the stored
     * metadata to echo into the manifest. Snapshots with neither a requirement
     * nor an encrypted marker may use the plaintext context.
     *
     * @param object $snapshot Snapshot row with decoded encryption metadata.
     *
     * @return array
     *
     * @throws MainWP_Exception When required encryption metadata or keys are unusable.
     * @throws \RuntimeException When the KEK cannot be read for this attempt.
     */
    private function encryption_context_for( object $snapshot ): array {
        $meta      = isset( $snapshot->encryption ) ? $snapshot->encryption : array();
        $encrypted = MainWP_Backup_Encryption::meta_is_encrypted( $meta );
        if ( ! $encrypted && empty( $meta['required'] ) ) {
            return array( 'encrypt' => false );
        }

        if ( ! $encrypted || ! isset( $meta['cipher'], $meta['overhead'], $meta['wrapped_dek'], $meta['kek_version_id'] )
            || MainWP_Backup_Encryption::CIPHER_ID !== $meta['cipher']
            || MainWP_Backup_Archive_Cipher::OVERHEAD !== (int) $meta['overhead']
            || ! is_string( $meta['wrapped_dek'] ) ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_ENCRYPTION_REQUIRED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Registry messages are pre-escaped.
        }

        $encryption = MainWP_Backup_Encryption::instance();
        $kek        = $encryption->load_kek();
        if ( null === $kek ) {
            throw new \RuntimeException( MainWP_Backup_Error_Codes::message( MainWP_Backup_Error_Codes::ERR_ENCRYPTION_REQUIRED ), self::KEK_UNAVAILABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Registry messages are pre-escaped.
        }
        if ( $encryption->kek_version_id( $kek ) !== $meta['kek_version_id'] ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_ENCRYPTION_REQUIRED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Registry messages are pre-escaped.
        }

        try {
            $dek = $encryption->dek_from_meta( $meta, (int) $snapshot->id, $kek );
        } catch ( \RuntimeException $e ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_ENCRYPTION_REQUIRED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Registry messages are pre-escaped.
        }
        if ( MainWP_Backup_Archive_Cipher::KEY_LEN !== strlen( $dek ) ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_ENCRYPTION_REQUIRED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Registry messages are pre-escaped.
        }

        return array(
            'encrypt'     => true,
            'dek'         => $dek,
            'kek'         => $kek,
            'snapshot_id' => (int) $snapshot->id,
            'meta'        => $meta,
        );
    }
    // phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.Missing

    /**
     * The framing overhead a seal adds, or zero when the context is plaintext.
     *
     * @param array $enc Encryption context.
     *
     * @return int
     */
    private function seal_overhead( array $enc ): int {
        return empty( $enc['encrypt'] ) ? 0 : MainWP_Backup_Archive_Cipher::OVERHEAD;
    }

    /**
     * Seal a whole-artifact body when the context is encrypting, binding it to
     * its snapshot and filename. A pass-through otherwise.
     *
     * @param string $bytes Plaintext body.
     * @param array  $enc   Encryption context.
     * @param string $file  Artifact filename.
     *
     * @return string
     */
    private function maybe_seal_whole( string $bytes, array $enc, string $file ): string {
        if ( empty( $enc['encrypt'] ) ) {
            return $bytes;
        }
        return MainWP_Backup_Archive_Cipher::seal( $bytes, $enc['dek'], 'snapshot:' . $enc['snapshot_id'] . ':whole:' . $file );
    }

    /**
     * Seal a chunk body when the context is encrypting, binding it to its
     * snapshot and content hash. The binding is the hash, not the offset, so
     * identical chunks that dedupe to one remote object stay decryptable at
     * every position they appear. A pass-through otherwise.
     *
     * @param string $bytes Plaintext chunk.
     * @param array  $enc   Encryption context.
     * @param string $hash  Chunk content hash.
     *
     * @return string
     */
    private function maybe_seal_chunk( string $bytes, array $enc, string $hash ): string {
        if ( empty( $enc['encrypt'] ) ) {
            return $bytes;
        }
        return MainWP_Backup_Archive_Cipher::seal( $bytes, $enc['dek'], 'snapshot:' . $enc['snapshot_id'] . ':chunk:' . $hash );
    }

    /**
     * Best-effort child site URL for the manifest, so a disaster recovery can
     * tell which site an archive belongs to. Empty when the site is gone.
     *
     * @param object $snapshot Snapshot row.
     *
     * @return string
     */
    private function snapshot_site_url( object $snapshot ): string {
        $website = MainWP_DB::instance()->get_website_by_id( (int) $snapshot->site_id );
        return ( $website && ! empty( $website->url ) ) ? (string) $website->url : '';
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

        $label              = self::PRIVATE_ROOT_FAILURE === $code ? MainWP_Backup_Error_Codes::ERR_PRIVATE_ROOT_UNAVAILABLE : $this->error_code_for_provider( (string) $destination->provider );
        $encryption_refused = $e instanceof MainWP_Exception && MainWP_Backup_Error_Codes::ERR_ENCRYPTION_REQUIRED === $e->get_message_error_code();
        if ( $encryption_refused || self::KEK_UNAVAILABLE === $code ) {
            $label = MainWP_Backup_Error_Codes::ERR_ENCRYPTION_REQUIRED;
        }
        $error = '[' . $label . '] ' . $e->getMessage();

        // Every outcome write is fenced and checked: a worker whose lease
        // was reclaimed must not relabel the new holder's job, clean its
        // staging, or announce a failure the live run may yet avoid.
        if ( 401 === $code || 403 === $code ) {
            if ( $store->invalidate_credentials( (int) $job->id, $error, $this->lock_token ) ) {
                $this->cleanup_staging( $job );
                $this->fire_upload_failed( $job, $destination, $error );
            }
            return;
        }

        $attempts = (int) $job->attempt_count + 1;
        if ( $encryption_refused || 410 === $code || MainWP_Backup_Backoff::attempts_exhausted( $attempts ) ) {
            if ( $store->fail_permanent( (int) $job->id, $error, $this->lock_token ) ) {
                $this->cleanup_staging( $job );
                $this->fire_upload_failed( $job, $destination, $error );
            }
            return;
        }

        $store->fail_retryable( (int) $job->id, $error, $now + MainWP_Backup_Backoff::delay_for_attempt( $attempts ), $this->lock_token );
    }

    /**
     * Announce a terminal upload failure (credentials invalidated or
     * permanently failed) so the activity log can record it. Never fired
     * for a retryable failure, which the backoff will attempt again.
     *
     * A null destination (the row was deleted, or the snapshot vanished
     * before the destination loaded) falls back to the id and provider
     * denormalized onto the job row at enqueue time.
     *
     * @param object      $job         Job row.
     * @param object|null $destination Destination row, when it still exists.
     * @param string      $error       Error detail.
     *
     * @return void
     */
    private function fire_upload_failed( object $job, ?object $destination, string $error ): void {
        $snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( (int) $job->snapshot_id );

        /**
         * Fires after a snapshot upload to a destination terminally fails.
         *
         * The logs connector records the event.
         *
         * @param array $event Site, task, snapshot, destination, and error details.
         */
        do_action(
            'mainwp_backup_upload_failed',
            array(
                'site_id'          => null !== $snapshot ? (int) $snapshot->site_id : 0,
                'task_id'          => null !== $snapshot ? (int) $snapshot->task_id : 0,
                'snapshot_id'      => (int) $job->snapshot_id,
                'destination_id'   => null !== $destination ? (int) $destination->id : (int) $job->destination_id,
                'destination_name' => null !== $destination ? (string) $destination->name : '',
                'provider'         => null !== $destination ? (string) $destination->provider : (string) $job->provider,
                'error'            => $error,
            )
        );
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
            case 'gcs':
                return MainWP_Backup_Error_Codes::ERR_GCS_FAILED;
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
     * @throws \RuntimeException When private staging is unavailable.
     */
    private function staging_dir( object $job ): string {
        $dir = MainWP_Backup_Private_Root::staging_dir( 'upload-staging', (int) $job->snapshot_id . '-' . (int) $job->destination_id );
        if ( is_wp_error( $dir ) ) {
            throw new \RuntimeException( $dir->get_error_message(), self::PRIVATE_ROOT_FAILURE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Upload errors are escaped by their output consumers.
        }
        return untrailingslashit( $dir );
    }

    /**
     * Remove a job's staging directory.
     *
     * @param object $job Job row.
     *
     * @return void
     */
    private function cleanup_staging( object $job ): void {
        $this->cleanup_staging_for( (int) $job->snapshot_id, (int) $job->destination_id );
    }

    /**
     * Discard staged chunks once the upload no longer needs them.
     *
     * @param int $snapshot_id    Snapshot id.
     * @param int $destination_id Storage destination id.
     *
     * @return void
     */
    public function cleanup_staging_for( int $snapshot_id, int $destination_id ): void {
        $name   = $snapshot_id . '-' . $destination_id;
        $upload = apply_filters( 'mainwp_get_wp_upload_dir', wp_upload_dir( null, false ), null, false );
        $legacy = trailingslashit( $upload['basedir'] ) . 'mainwp/upload-staging/' . $name . '/';
        foreach ( array( MainWP_Backup_Private_Root::staging_path( 'upload-staging', $name ), $legacy ) as $dir ) {
            if ( is_wp_error( $dir ) || ! is_dir( $dir ) || is_link( untrailingslashit( $dir ) ) || is_link( dirname( untrailingslashit( $dir ) ) ) || is_link( dirname( untrailingslashit( $dir ), 2 ) ) ) {
                continue;
            }
            foreach ( (array) scandir( $dir ) as $name ) {
                $file = $dir . $name;
                if ( is_file( $file ) && MainWP_Backup_File_Manager::path_is_confined_to( $file, $dir ) ) {
                    wp_delete_file( $file );
                }
            }
            @rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions -- best-effort staging cleanup.
        }
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
