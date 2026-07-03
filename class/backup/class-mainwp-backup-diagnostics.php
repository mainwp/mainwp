<?php
/**
 * MainWP Backup Diagnostics.
 *
 * Opt-in, append-only recorder for backup and restore runs. When the
 * diagnostics setting is on, the pipeline's seam hooks (every child
 * poll, restore poll, transfer chunk, preflight report, and concurrency
 * slot decision) are captured as JSONL events in a protected uploads
 * subdirectory, one file per run. The raw child payloads are recorded
 * near-verbatim; all interpretation (phase durations, throughput,
 * stall detection) happens offline in the analyzer, so the hot path
 * only ever pays for one appended line.
 *
 * When the setting is off (the default) init() registers nothing, so
 * the feature costs nothing beyond the no-subscriber hook calls.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Class MainWP_Backup_Diagnostics
 */
class MainWP_Backup_Diagnostics { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Option holding the on/off switch ('1' on, '0'/absent off).
     */
    const OPTION_ENABLED = 'mainwp_backup_diagnostics_enabled';

    /**
     * Uploads subdirectory holding the event files.
     */
    const SUBDIR = 'backup-diagnostics';

    /**
     * Days an event file survives before the daily prune removes it.
     */
    const RETENTION_DAYS = 14;

    /**
     * Directory size cap in bytes (200MB); the prune deletes oldest-first
     * past it.
     */
    const RETENTION_CAP_BYTES = 209715200;

    /**
     * Singleton.
     *
     * @var MainWP_Backup_Diagnostics|null
     */
    private static $instance = null;

    /**
     * Get the singleton instance.
     *
     * @return MainWP_Backup_Diagnostics
     */
    public static function instance(): self {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Whether diagnostics recording is enabled.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return '1' === (string) get_option( self::OPTION_ENABLED, '0' );
    }

    /**
     * Subscribe to the pipeline seams. Registers nothing when the
     * setting is off, so a disabled recorder has literally zero hooks.
     *
     * @return void
     */
    public function init(): void {
        if ( ! static::is_enabled() ) {
            return;
        }

        add_action( 'mainwp_backup_diag_poll', array( $this, 'on_poll' ), 10, 3 );
        add_action( 'mainwp_backup_diag_restore_poll', array( $this, 'on_restore_poll' ), 10, 4 );
        add_action( 'mainwp_backup_diag_restore_transfer', array( $this, 'on_restore_transfer' ), 10, 7 );
        add_action( 'mainwp_backup_diag_restore_retry', array( $this, 'on_restore_retry' ), 10, 6 );
        add_action( 'mainwp_backup_diag_preflight', array( $this, 'on_preflight' ), 10, 4 );
        add_action( 'mainwp_backup_diag_slot', array( $this, 'on_slot' ), 10, 4 );

        // Existing pipeline hooks: terminal outcomes and lifecycle events.
        add_action( 'mainwp_native_backup_completed', array( $this, 'on_backup_terminal' ), 10, 4 );
        add_action( 'mainwp_backup_restored', array( $this, 'on_restore_terminal' ), 10, 1 );
        add_action( 'mainwp_backup_uploaded', array( $this, 'on_lifecycle_upload' ), 10, 1 );
        add_action( 'mainwp_backup_downloaded', array( $this, 'on_lifecycle_download' ), 10, 1 );
        add_action( 'mainwp_backup_pruned', array( $this, 'on_lifecycle_pruned' ), 10, 1 );
        add_action( 'mainwp_backup_snapshot_recorded', array( $this, 'on_snapshot_recorded' ), 10, 3 );
        add_action( 'mainwp_backup_upload_job_settled', array( $this, 'on_upload_job_settled' ), 10, 1 );

        // Guardrail/advisory messages, filtered to backup-related ones.
        add_action( 'mainwp_log_warning', array( $this, 'on_log_warning' ), 10, 1 );
        add_action( 'mainwp_log_info', array( $this, 'on_log_info' ), 10, 1 );

        // Retention rides the existing daily backups prune cron.
        add_action( 'mainwp_cronbackups_prune_action', array( $this, 'prune' ) );
    }

    /**
     * Record a terminal backup outcome from mainwp_native_backup_completed.
     *
     * @param int         $site_id       Child site id.
     * @param string|null $child_task_id Child backup task handle, null on the synchronous path.
     * @param bool        $success       Whether the backup completed.
     * @param array       $meta          Artifact and error details.
     *
     * @return void
     */
    public function on_backup_terminal( $site_id, $child_task_id, $success, $meta ): void {
        try {
            $this->record(
                'terminal-' . (int) $site_id,
                'terminal',
                (int) $site_id,
                0,
                array(
                    'success'       => (bool) $success,
                    'child_task_id' => is_scalar( $child_task_id ) ? (string) $child_task_id : '',
                    'meta'          => is_array( $meta ) ? $meta : array(),
                )
            );
        } catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- diagnostics must never break a backup.
            return;
        }
    }

    /**
     * Record a verified restore completion from mainwp_backup_restored.
     *
     * @param array $event Site, snapshot, job, and verification details.
     *
     * @return void
     */
    public function on_restore_terminal( $event ): void {
        try {
            $event  = is_array( $event ) ? $event : array();
            $job_id = isset( $event['job_id'] ) ? (int) $event['job_id'] : 0;
            $this->record( 'restore-' . $job_id, 'restore_terminal', isset( $event['site_id'] ) ? (int) $event['site_id'] : 0, 0, $event );
        } catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- diagnostics must never break a restore.
            return;
        }
    }

    /**
     * Record an offsite upload completion.
     *
     * @param array $event Upload details (site, task, snapshot, destination, bytes).
     *
     * @return void
     */
    public function on_lifecycle_upload( $event ): void {
        $this->record_lifecycle( 'upload', $event );
    }

    /**
     * Record an artifact download completion.
     *
     * @param array $event Download details.
     *
     * @return void
     */
    public function on_lifecycle_download( $event ): void {
        $this->record_lifecycle( 'download', $event );
    }

    /**
     * Record a retention prune of snapshots.
     *
     * @param array $event Prune details.
     *
     * @return void
     */
    public function on_lifecycle_pruned( $event ): void {
        $this->record_lifecycle( 'pruned', $event );
    }

    /**
     * Record a snapshot row being written.
     *
     * @param int $snapshot_id Snapshot id.
     * @param int $task_id     Task id.
     * @param int $site_id     Site id.
     *
     * @return void
     */
    public function on_snapshot_recorded( $snapshot_id, $task_id = 0, $site_id = 0 ): void {
        try {
            $this->record(
                '_global',
                'snapshot_recorded',
                (int) $site_id,
                (int) $task_id,
                array( 'snapshot_id' => (int) $snapshot_id )
            );
        } catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- diagnostics must never break a backup.
            return;
        }
    }

    /**
     * Record an upload job settling (every destination reached a final state).
     *
     * @param int $snapshot_id Snapshot id.
     *
     * @return void
     */
    public function on_upload_job_settled( $snapshot_id ): void {
        try {
            $this->record( '_global', 'upload_job_settled', 0, 0, array( 'snapshot_id' => (int) $snapshot_id ) );
        } catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- diagnostics must never break a backup.
            return;
        }
    }

    /**
     * Record a backup-related warning from the dashboard log channel.
     *
     * @param mixed $message Log message.
     *
     * @return void
     */
    public function on_log_warning( $message ): void {
        $this->record_guardrail( 'warning', $message );
    }

    /**
     * Record a backup-related info line from the dashboard log channel.
     *
     * @param mixed $message Log message.
     *
     * @return void
     */
    public function on_log_info( $message ): void {
        $this->record_guardrail( 'info', $message );
    }

    /**
     * Record a lifecycle event under the shared global stream.
     *
     * @param string $kind  Event kind.
     * @param mixed  $event Hook payload.
     *
     * @return void
     */
    private function record_lifecycle( string $kind, $event ): void {
        try {
            $event = is_array( $event ) ? $event : array();
            $this->record(
                '_global',
                $kind,
                isset( $event['site_id'] ) ? (int) $event['site_id'] : 0,
                isset( $event['task_id'] ) ? (int) $event['task_id'] : 0,
                $event
            );
        } catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- diagnostics must never break a backup.
            return;
        }
    }

    /**
     * Record a log-channel message when it looks backup-related. The
     * mainwp_log_* hooks are dashboard-wide, so a keyword check keeps
     * unrelated noise out; a missed unusual phrasing is acceptable for a
     * diagnostics aid.
     *
     * @param string $level   warning|info.
     * @param mixed  $message Log message.
     *
     * @return void
     */
    private function record_guardrail( string $level, $message ): void {
        try {
            if ( ! is_string( $message ) || '' === $message ) {
                return;
            }
            $related = false;
            foreach ( array( 'backup', 'restore', 'zip', 'disk', 'memory', 'snapshot' ) as $keyword ) {
                if ( false !== stripos( $message, $keyword ) ) {
                    $related = true;
                    break;
                }
            }
            if ( ! $related ) {
                return;
            }
            $this->record(
                '_global',
                'guardrail',
                0,
                0,
                array(
                    'level'   => $level,
                    'message' => $message,
                )
            );
        } catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- diagnostics must never break a backup.
            return;
        }
    }

    /**
     * Record one backup poll round trip: the task-create response or a
     * backup_task_run status payload, with the request duration.
     *
     * @param array $payload  Full child response payload.
     * @param float $duration HTTP round-trip duration in seconds.
     * @param array $context  { op: create|run|status|pre_snapshot, site_id: int, task_id: int }.
     *
     * @return void
     */
    public function on_poll( $payload, $duration, $context ): void {
        try {
            $payload = is_array( $payload ) ? $payload : array();
            $context = is_array( $context ) ? $context : array();
            $site_id = isset( $context['site_id'] ) ? (int) $context['site_id'] : 0;
            $task_id = isset( $context['task_id'] ) ? (int) $context['task_id'] : 0;

            $child_task = isset( $payload['task_id'] ) && is_scalar( $payload['task_id'] ) ? (string) $payload['task_id'] : '';
            $run_key    = $task_id . '-' . $site_id . ( '' !== $child_task ? '-' . $child_task : '' );

            $payload['poll_duration_s'] = (float) $duration;
            $payload['op']              = isset( $context['op'] ) ? (string) $context['op'] : '';

            $this->record( $run_key, 'poll', $site_id, $task_id, $payload );
        } catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- diagnostics must never break a backup.
            return;
        }
    }

    /**
     * Record one restore poll round trip, non-terminal phases included.
     *
     * @param int   $job_id   Restore job id.
     * @param int   $site_id  Child site id.
     * @param array $payload  Full child response payload.
     * @param float $duration HTTP round-trip duration in seconds.
     *
     * @return void
     */
    public function on_restore_poll( $job_id, $site_id, $payload, $duration ): void {
        try {
            $payload                    = is_array( $payload ) ? $payload : array();
            $payload['poll_duration_s'] = (float) $duration;

            $this->record( 'restore-' . (int) $job_id, 'restore_poll', (int) $site_id, 0, $payload );
        } catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- diagnostics must never break a restore.
            return;
        }
    }

    /**
     * Record one restore transfer chunk send with its classified outcome,
     * making the silent adaptive chunk shrinking visible.
     *
     * @param string      $child_task_id Child restore task id.
     * @param string      $file          Artifact file name.
     * @param array       $cursor        Transfer cursor before the send.
     * @param string      $result        ok|realign|shrink|error.
     * @param int         $chunk_len     Bytes sent in this chunk.
     * @param float       $duration      Send duration in seconds.
     * @param string|null $error         Transport/child error string, null on ok.
     *
     * @return void
     */
    public function on_restore_transfer( $child_task_id, $file, $cursor, $result, $chunk_len, $duration, $error = null ): void {
        try {
            $this->record(
                'rtransfer-' . (string) $child_task_id,
                'restore_transfer',
                0,
                0,
                array(
                    'child_task_id'   => (string) $child_task_id,
                    'file'            => (string) $file,
                    'cursor'          => is_array( $cursor ) ? $cursor : array(),
                    'result'          => (string) $result,
                    'chunk_len'       => (int) $chunk_len,
                    'send_duration_s' => (float) $duration,
                    'error'           => is_string( $error ) && '' !== $error ? MainWP_Backup_Error_Codes::sanitize_message( $error ) : '',
                )
            );
        } catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- diagnostics must never break a restore.
            return;
        }
    }

    /**
     * Record a retryable restore failure: the attempt boundary the event
     * stream otherwise leaves to be inferred from gaps.
     *
     * @param int    $job_id         Restore job id.
     * @param int    $site_id        Child site id.
     * @param int    $attempt        Attempt count after this failure.
     * @param string $error_code     Stable error code, '' when none.
     * @param int    $next_retry_at  Epoch the backoff releases the job.
     * @param bool   $progress_reset Whether transfer progress reset the budget.
     *
     * @return void
     */
    public function on_restore_retry( $job_id, $site_id, $attempt, $error_code, $next_retry_at, $progress_reset ): void {
        try {
            $this->record(
                'restore-' . (int) $job_id,
                'restore_retry',
                (int) $site_id,
                0,
                array(
                    'attempt'        => (int) $attempt,
                    'error_code'     => (string) $error_code,
                    'next_retry_at'  => (int) $next_retry_at,
                    'progress_reset' => (bool) $progress_reset,
                )
            );
        } catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- diagnostics must never break a restore.
            return;
        }
    }

    /**
     * Record a preflight negotiation outcome.
     *
     * @param int    $site_id   Child site id.
     * @param int    $task_id   Backup task id, 0 when none drives the run.
     * @param array  $preflight Child readiness report, empty when unavailable.
     * @param string $status    ok|unavailable|unsupported.
     *
     * @return void
     */
    public function on_preflight( $site_id, $task_id, $preflight, $status ): void {
        try {
            $this->record(
                (int) $task_id . '-' . (int) $site_id . '-preflight',
                'preflight',
                (int) $site_id,
                (int) $task_id,
                array(
                    'status'    => (string) $status,
                    'preflight' => is_array( $preflight ) ? $preflight : array(),
                )
            );
        } catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- diagnostics must never break a backup.
            return;
        }
    }

    /**
     * Record a concurrency slot decision.
     *
     * @param string $event          acquired|refused|released.
     * @param string $token          Slot token, '' on refusal.
     * @param int    $active         Active slot count after the decision.
     * @param int    $max_concurrent Configured slot limit.
     *
     * @return void
     */
    public function on_slot( $event, $token, $active, $max_concurrent ): void {
        try {
            $this->record(
                '_global',
                'slot',
                0,
                0,
                array(
                    'event'          => (string) $event,
                    'token'          => (string) $token,
                    'active'         => (int) $active,
                    'max_concurrent' => (int) $max_concurrent,
                )
            );
        } catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- diagnostics must never break a backup.
            return;
        }
    }

    /**
     * Append one event line to the run's JSONL file. Every failure mode
     * (unwritable directory, encoding failure, filesystem error) is
     * swallowed: diagnostics must never break the pipeline it observes.
     *
     * @param string $run_key Run grouping key; becomes the file name.
     * @param string $kind    Event kind.
     * @param int    $site_id Child site id, 0 when not applicable.
     * @param int    $task_id Backup task id, 0 when not applicable.
     * @param array  $payload Event payload.
     *
     * @return void
     */
    public function record( string $run_key, string $kind, int $site_id, int $task_id, array $payload ): void {
        try {
            $dir = $this->diagnostics_dir();
            if ( '' === $dir ) {
                return;
            }

            $line = wp_json_encode(
                array(
                    'ts'      => microtime( true ),
                    'run_key' => $run_key,
                    'kind'    => $kind,
                    'site_id' => $site_id,
                    'task_id' => $task_id,
                    'payload' => $payload,
                )
            );
            if ( ! is_string( $line ) ) {
                return;
            }

            $file = $dir . sanitize_file_name( $run_key ) . '.jsonl';

            // phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- locked append on the hot path; failures are silent by design.
            $handle = @fopen( $file, 'ab' );
            if ( false === $handle ) {
                return;
            }
            @flock( $handle, LOCK_EX );
            @fwrite( $handle, $line . "\n" );
            @flock( $handle, LOCK_UN );
            @fclose( $handle );
            // phpcs:enable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged
        } catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- diagnostics must never break a backup.
            return;
        }
    }

    /**
     * Build the export bundle: env.json, bounded table dumps, and the
     * raw event files, zipped inside the diagnostics directory. Falls
     * back to a single concatenated .json when ZipArchive is missing.
     *
     * The wp_backup_storage table is deliberately absent - it holds
     * provider credentials - and the snapshot rows are exported without
     * their encryption metadata.
     *
     * @return string Bundle path, or '' when there is nothing to export
     *                or the bundle could not be written.
     */
    public function export_bundle(): string {
        try {
            $dir = $this->diagnostics_dir();
            if ( '' === $dir || ! is_dir( $dir ) ) {
                return '';
            }

            $event_files = (array) glob( $dir . '*.jsonl' );
            if ( empty( $event_files ) ) {
                return '';
            }

            $sections = $this->bundle_sections( $dir );
            $base     = $dir . 'diagnostics-bundle-' . gmdate( 'Ymd-His' );

            if ( class_exists( \ZipArchive::class ) ) {
                $path = $base . '.zip';
                $zip  = new \ZipArchive();
                if ( true !== $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
                    return '';
                }
                foreach ( $sections as $name => $data ) {
                    $zip->addFromString( $name . '.json', (string) wp_json_encode( $data, JSON_PRETTY_PRINT ) );
                }
                foreach ( $event_files as $event_file ) {
                    $zip->addFile( $event_file, 'events/' . basename( $event_file ) );
                }
                $zip->close();
                return $path;
            }

            // No ZipArchive on this host: one plain JSON document instead.
            $payload           = $sections;
            $payload['events'] = array();
            foreach ( $event_files as $event_file ) {
                $lines                                        = (string) file_get_contents( $event_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- bounded local read.
                $payload['events'][ basename( $event_file ) ] = array_values( array_filter( explode( "\n", $lines ) ) );
            }
            $path = $base . '.json';
            if ( false === file_put_contents( $path, wp_json_encode( $payload ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- local bundle write.
                return '';
            }
            return $path;
        } catch ( \Throwable $e ) {
            return '';
        }
    }

    /**
     * The bundle's JSON sections: the environment summary plus bounded
     * dumps of the backup tables.
     *
     * @param string $dir Diagnostics directory (for the disk-free probe).
     *
     * @return array Section name => data.
     */
    private function bundle_sections( string $dir ): array {
        global $wpdb;

        /**
         * Filters the per-table row cap for the diagnostics export.
         *
         * @param int $cap Maximum rows exported per table.
         */
        $cap       = max( 1, (int) apply_filters( 'mainwp_backup_diagnostics_export_row_cap', 5000 ) );
        $truncated = false;

        $tables   = array(
            'tasks'        => 'wp_backup',
            'snapshots'    => 'wp_backup_snapshot',
            'progress'     => 'wp_backup_progress',
            'restore_jobs' => 'wp_backup_restore_job',
            'upload_jobs'  => 'wp_backup_upload_job',
        );
        $sections = array();
        foreach ( $tables as $name => $table ) {
            $table_name = MainWP_DB::instance()->get_table_name( $table );
            $rows       = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $table_name . ' LIMIT %d', $cap + 1 ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- bounded diagnostic dump of a known internal table.
            $rows       = is_array( $rows ) ? $rows : array();
            if ( count( $rows ) > $cap ) {
                $rows      = array_slice( $rows, 0, $cap );
                $truncated = true;
            }
            if ( 'snapshots' === $name ) {
                foreach ( $rows as $index => $row ) {
                    unset( $rows[ $index ]['encryption'] );
                }
            }
            $sections[ $name ] = $rows;
        }

        $disk_free = null;
        if ( function_exists( 'disk_free_space' ) ) {
            $free = @disk_free_space( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- may be disabled by the host.
            if ( false !== $free ) {
                $disk_free = (float) $free;
            }
        }

        $sections['env'] = array(
            'php_version'    => PHP_VERSION,
            'wp_version'     => get_bloginfo( 'version' ),
            'mainwp_version' => class_exists( __NAMESPACE__ . '\\MainWP_System' ) ? (string) MainWP_System::$version : '',
            'memory_limit'   => (string) ini_get( 'memory_limit' ),
            'disk_free'      => $disk_free,
            'generated_at'   => gmdate( 'c' ),
            'row_cap'        => $cap,
            'truncated'      => $truncated,
        );

        return $sections;
    }

    /**
     * Retention prune: delete diagnostics files past the age limit, then
     * oldest-first until the directory fits the size cap. Runs on the
     * daily backups prune cron.
     *
     * @return void
     */
    public function prune(): void {
        try {
            $dir = $this->diagnostics_dir();
            if ( '' === $dir || ! is_dir( $dir ) ) {
                return;
            }

            /**
             * Filters how many days diagnostics files are kept.
             *
             * @param int $days Retention window in days.
             */
            $days = max( 1, (int) apply_filters( 'mainwp_backup_diagnostics_retention_days', self::RETENTION_DAYS ) );

            /**
             * Filters the diagnostics directory size cap in bytes.
             *
             * @param int $cap Size cap in bytes.
             */
            $cap = max( 1, (int) apply_filters( 'mainwp_backup_diagnostics_cap_bytes', self::RETENTION_CAP_BYTES ) );

            $cutoff = time() - $days * DAY_IN_SECONDS;
            $files  = array();
            $total  = 0;
            foreach ( (array) glob( $dir . '*' ) as $file ) {
                if ( ! is_file( $file ) || in_array( basename( $file ), array( 'index.php', '.htaccess' ), true ) ) {
                    continue;
                }
                $mtime = (int) filemtime( $file );
                if ( $mtime < $cutoff ) {
                    wp_delete_file( $file );
                    continue;
                }
                $size    = (int) filesize( $file );
                $files[] = array(
                    'path'  => $file,
                    'mtime' => $mtime,
                    'size'  => $size,
                );
                $total  += $size;
            }

            if ( $total <= $cap ) {
                return;
            }

            usort( $files, static fn( $a, $b ) => $a['mtime'] <=> $b['mtime'] );
            foreach ( $files as $file ) {
                if ( $total <= $cap ) {
                    break;
                }
                wp_delete_file( $file['path'] );
                $total -= $file['size'];
            }
        } catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- diagnostics must never break a backup.
            return;
        }
    }

    /**
     * The diagnostics directory with a trailing slash, or '' when it
     * cannot be resolved. The real directory is the .htaccess-protected
     * uploads subdirectory; the filter exists for tests and for
     * relocating the files on unusual hosts.
     *
     * @return string Directory path or ''.
     */
    public function diagnostics_dir(): string {
        try {
            /**
             * Filters the directory the diagnostics recorder writes to.
             *
             * @param string $dir Custom directory; '' uses the default
             *                    protected uploads subdirectory.
             */
            $custom = apply_filters( 'mainwp_backup_diagnostics_dir', '' );
            if ( is_string( $custom ) && '' !== $custom ) {
                return trailingslashit( $custom );
            }
            return trailingslashit( MainWP_System_Utility::get_mainwp_sub_dir( self::SUBDIR ) );
        } catch ( \Throwable $e ) {
            return '';
        }
    }
}
