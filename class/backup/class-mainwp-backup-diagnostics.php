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
     * Hash tokens so stored records cannot themselves act as download links.
     */
    const DOWNLOAD_TRANSIENT_PREFIX = 'mainwp_backup_diag_download_';

    /**
     * Days an event file survives before the daily prune removes it.
     */
    const RETENTION_DAYS = 14;

    /**
     * Seconds an exported bundle survives (one hour): long enough for a
     * download in progress. A literal so the class loads without the
     * WordPress time constants, as the isolated download-stream tests do.
     */
    const BUNDLE_TTL_SECONDS = 3600;

    /**
     * Size cap in bytes (200MB) for everything in the directory except
     * bundles; the prune deletes oldest-first past it. Bundles stay outside
     * it because they last at most BUNDLE_TTL_SECONDS and only an
     * administrator's export writes them.
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
     * Subscribe to the pipeline seams. Recording registers nothing when
     * the setting is off, so a disabled recorder costs nothing on the hot
     * path. Both the task-delete purge and retention cleanup hooks stay
     * registered so files written before diagnostics was switched off
     * still leave with their task or expire.
     *
     * @return void
     */
    public function init(): void {
        add_action( 'mainwp_backup_task_snapshots_purged', array( $this, 'purge_task_files' ), 10, 1 );
        add_action( 'mainwp_cronbackups_prune_action', array( $this, 'prune' ) );

        if ( ! static::is_enabled() ) {
            return;
        }

        add_action( 'mainwp_backup_diag_poll', array( $this, 'on_poll' ), 10, 3 );
        add_action( 'mainwp_backup_diag_restore_poll', array( $this, 'on_restore_poll' ), 10, 4 );
        add_action( 'mainwp_backup_diag_restore_transfer', array( $this, 'on_restore_transfer' ), 10, 7 );
        add_action( 'mainwp_backup_diag_restore_retry', array( $this, 'on_restore_retry' ), 10, 6 );
        add_action( 'mainwp_backup_diag_run_reinit', array( $this, 'on_run_reinit' ), 10, 4 );
        add_action( 'mainwp_backup_diag_site_mark', array( $this, 'on_site_mark' ), 10, 4 );
        add_action( 'mainwp_backup_diag_run_close', array( $this, 'on_run_close' ), 10, 4 );
        add_action( 'mainwp_backup_diag_tick', array( $this, 'on_tick' ), 10, 3 );
        add_action( 'mainwp_backup_diag_preflight', array( $this, 'on_preflight' ), 10, 4 );
        add_action( 'mainwp_backup_diag_slot', array( $this, 'on_slot' ), 10, 4 );
        add_action( 'mainwp_backup_diag_lane', array( $this, 'on_lane' ), 10, 4 );

        // Existing pipeline hooks: terminal outcomes and lifecycle events.
        add_action( 'mainwp_native_backup_completed', array( $this, 'on_backup_terminal' ), 10, 4 );
        add_action( 'mainwp_backup_restored', array( $this, 'on_restore_terminal' ), 10, 1 );
        add_action( 'mainwp_backup_uploaded', array( $this, 'on_lifecycle_upload' ), 10, 1 );
        add_action( 'mainwp_backup_downloaded', array( $this, 'on_lifecycle_download' ), 10, 1 );
        add_action( 'mainwp_backup_pruned', array( $this, 'on_lifecycle_pruned' ), 10, 1 );
        add_action( 'mainwp_backup_snapshot_recorded', array( $this, 'on_snapshot_recorded' ), 10, 3 );
        add_action( 'mainwp_backup_upload_job_settled', array( $this, 'on_upload_job_settled' ), 10, 1 );

        // Guardrail/advisory messages, filtered to backup-related ones.
        add_action( 'mainwp_log_warning', array( $this, 'on_log_warning' ), 10, 3 );
        add_action( 'mainwp_log_info', array( $this, 'on_log_info' ), 10, 3 );
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
     * @param int   $site_id Site id, or zero for a host-level message.
     * @param int   $task_id Backup task id, or zero when unrelated to a backup task.
     *
     * @return void
     */
    public function on_log_warning( $message, $site_id = 0, $task_id = 0 ): void {
        $this->record_guardrail( 'warning', $message, (int) $site_id, (int) $task_id );
    }

    /**
     * Record a backup-related info line from the dashboard log channel.
     *
     * @param mixed $message Log message.
     * @param int   $site_id Site id, or zero for a host-level message.
     * @param int   $task_id Backup task id, or zero when unrelated to a backup task.
     *
     * @return void
     */
    public function on_log_info( $message, $site_id = 0, $task_id = 0 ): void {
        $this->record_guardrail( 'info', $message, (int) $site_id, (int) $task_id );
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
     * @param int    $site_id Site id, or zero for a host-level message.
     * @param int    $task_id Backup task id, or zero when unrelated to a backup task.
     *
     * @return void
     */
    private function record_guardrail( string $level, $message, int $site_id = 0, int $task_id = 0 ): void {
        try {
            if ( ! is_string( $message ) || '' === $message ) {
                return;
            }
            $topic = '';
            foreach ( array( 'disk', 'backup', 'restore', 'zip', 'memory', 'snapshot' ) as $keyword ) {
                if ( false !== stripos( $message, $keyword ) ) {
                    $topic = $keyword;
                    break;
                }
            }
            if ( '' === $topic ) {
                return;
            }
            $this->record(
                '_global',
                'guardrail',
                $site_id,
                $task_id,
                array(
                    'level'   => $level,
                    'message' => '[redacted]',
                    'topic'   => $topic,
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
     * @param array $context  { op: create|run|status|pre_snapshot, site_id: int, task_id: int, run: int (create only) }.
     *
     * @return void
     */
    public function on_poll( $payload, $duration, $context ): void {
        try {
            $payload = is_array( $payload ) ? $payload : array();
            $context = is_array( $context ) ? $context : array();
            $site_id = isset( $context['site_id'] ) ? (int) $context['site_id'] : 0;
            $task_id = isset( $context['task_id'] ) ? (int) $context['task_id'] : 0;

            // A refused create returns no child task id, so without another
            // scope every refusal for this task and site would share one
            // file across every run that ever hit it. The run-open stamp
            // scopes a create failure to its run the same way the child
            // task id scopes a successful one.
            $child_task = isset( $payload['task_id'] ) && is_scalar( $payload['task_id'] ) ? (string) $payload['task_id'] : '';
            $run        = isset( $context['run'] ) ? (int) $context['run'] : 0;
            $run_key    = $task_id . '-' . $site_id . ( '' !== $child_task ? '-' . $child_task : ( 0 !== $run ? '-' . $run : '' ) );

            $payload['poll_duration_s'] = (float) $duration;
            $payload['op']              = isset( $context['op'] ) ? (string) $context['op'] : '';

            $this->record( $run_key, 'poll', $site_id, $task_id, $this->redact_backup_payload( $payload ) );
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
            $payload                    = is_array( $payload ) ? $this->redact_restore_secrets( $payload ) : array();
            $payload['poll_duration_s'] = (float) $duration;

            $this->record( 'restore-' . (int) $job_id, 'restore_poll', (int) $site_id, 0, $payload );
        } catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- diagnostics must never break a restore.
            return;
        }
    }

    /**
     * Keep presence markers without retaining restore credentials or signed URLs.
     *
     * @param array $payload Diagnostic payload or decoded event.
     * @param int   $depth   Current nesting depth.
     * @param bool  $in_sub_state Whether Child cursor paths must be projected.
     * @return array Redacted payload.
     */
    private function redact_restore_secrets( array $payload, int $depth = 0, bool $in_sub_state = false ): array {
        if ( $depth >= 64 ) {
            return array();
        }
        $redacted = array();
        foreach ( $payload as $key => $value ) {
            $child_state = $in_sub_state || 'sub_state' === $key;
            if ( $in_sub_state && is_string( $key ) ) {
                $key = $this->redact_sub_state_path( $key );
            }
            if ( 'identity_preserve' === $key || str_starts_with( (string) $key, 'pull_manifest' ) ) {
                $value = (bool) $value;
            } elseif ( is_array( $value ) ) {
                $value = $this->redact_restore_secrets( $value, $depth + 1, $child_state );
            } elseif ( $child_state && is_string( $value ) ) {
                $value = $this->redact_sub_state_path( $value );
            }
            $redacted[ $key ] = $value;
        }
        return $redacted;
    }

    /**
     * Keep the Child's backup directory hash out of diagnostics and support bundles.
     *
     * @param array $payload           Diagnostic payload or decoded event.
     * @param bool  $sanitize_messages Whether to sanitize string keys and values.
     * @param int   $depth             Current nesting depth.
     * @param bool  $in_sub_state      Whether Child cursor paths must be projected.
     * @return array Redacted payload.
     */
    private function redact_backup_payload( array $payload, bool $sanitize_messages = false, int $depth = 0, bool $in_sub_state = false ): array {
        if ( $depth >= 64 ) {
            return array();
        }
        $redacted = array();
        foreach ( $payload as $key => $value ) {
            if ( 'backup_dir_hash' === $key ) {
                continue;
            }
            $child_state = $in_sub_state || 'sub_state' === $key;
            if ( $in_sub_state && is_string( $key ) ) {
                $key = $this->redact_sub_state_path( $key );
            }
            if ( $child_state && is_string( $value ) ) {
                $value = $this->redact_sub_state_path( $value );
            }
            if ( $sanitize_messages && is_string( $key ) ) {
                $key = MainWP_Backup_Error_Codes::sanitize_message( $key );
            }
            if ( is_array( $value ) ) {
                $value = $this->redact_backup_payload( $value, $sanitize_messages, $depth + 1, $child_state );
            } elseif ( $sanitize_messages && is_string( $value ) ) {
                $value = MainWP_Backup_Error_Codes::sanitize_message( $value );
            }
            $redacted[ $key ] = $value;
        }
        return $redacted;
    }

    /**
     * Beta Child cursors can contain host paths and the private archive directory.
     *
     * @param string $value Child cursor key or value.
     * @return string Projected value.
     */
    private function redact_sub_state_path( string $value ): string {
        $normalized = str_replace( '\\', '/', $value );
        if ( str_starts_with( $normalized, '/' ) || preg_match( '~^[A-Za-z]:/~', $normalized ) ) {
            $value = basename( rtrim( $normalized, '/' ) );
        }
        return preg_replace( '/(?<![A-Za-z0-9])backup-[A-Za-z0-9]{16}(?![A-Za-z0-9])/', 'backup-[redacted]', $value );
    }

    /**
     * Redact one exported table column that holds Child text.
     *
     * @param string $value Stored column value: plain text or a JSON-encoded Child response.
     * @return string Redacted value, still valid JSON when the input was a JSON object or list.
     */
    private function redact_exported_text( string $value ): string {
        // The sanitizer's SQL patterns run over an encoded response would swallow
        // the closing quote and every field after it, so a response is redacted per field.
        $decoded = json_decode( $value, true );
        if ( is_array( $decoded ) ) {
            $encoded = wp_json_encode( $this->redact_backup_payload( $decoded, true ) );
            if ( is_string( $encoded ) ) {
                return $encoded;
            }
        }
        return MainWP_Backup_Error_Codes::sanitize_message( $value );
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
     * Record a refused run (re)open: the explicit trace of a duplicate
     * initiate being absorbed, which earlier bundles showed only as a
     * second preflight.
     *
     * @param int    $task_id   Task id.
     * @param string $reason    Refusal reason.
     * @param int    $last_run  The run-open stamp the row kept.
     * @param int    $completed The close stamp the row kept.
     *
     * @return void
     */
    public function on_run_reinit( $task_id, $reason, $last_run, $completed ): void {
        try {
            $this->record(
                '_global',
                'run_reinit',
                0,
                (int) $task_id,
                array(
                    'reason'    => (string) $reason,
                    'last_run'  => (int) $last_run,
                    'completed' => (int) $completed,
                )
            );
        } catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- diagnostics must never break a backup.
            return;
        }
    }

    /**
     * Record a site-fate decision from the run loop: a completed_sites
     * write (success or terminal error, with the exception named) or a
     * yield. Who marked a site, and why, was invisible before this event.
     *
     * @param int    $task_id Task id.
     * @param int    $site_id Child site id.
     * @param string $outcome success|error|yield_already_running|yield_resumable.
     * @param string $detail  Exception class, code, and message for error marks.
     *
     * @return void
     */
    public function on_site_mark( $task_id, $site_id, $outcome, $detail ): void {
        try {
            $this->record(
                '_global',
                'site_mark',
                (int) $site_id,
                (int) $task_id,
                array(
                    'outcome' => (string) $outcome,
                    'detail'  => (string) $detail,
                )
            );
        } catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- diagnostics must never break a backup.
            return;
        }
    }

    /**
     * Record a run close with its trigger - the counterpart of site_mark
     * at the run level, so a bundle shows which path stamped a run closed.
     *
     * @param int    $task_id     Task id.
     * @param int    $last_run    The run-open stamp being closed.
     * @param string $trigger     Close trigger.
     * @param int    $sites_total Number of sites in the run.
     *
     * @return void
     */
    public function on_run_close( $task_id, $last_run, $trigger, $sites_total ): void {
        try {
            $this->record(
                '_global',
                'run_close',
                0,
                (int) $task_id,
                array(
                    'trigger'     => (string) $trigger,
                    'last_run'    => (int) $last_run,
                    'sites_total' => (int) $sites_total,
                )
            );
        } catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- diagnostics must never break a backup.
            return;
        }
    }

    /**
     * Record the work attempted by one continuation tick.
     *
     * @param int        $picked_task_id Attempted task, or zero when none was picked.
     * @param array|null $outcome        Execution outcome, or null without a pick.
     * @param array      $tick           Tick timing and queue state.
     * @return void
     */
    public function on_tick( $picked_task_id, $outcome, $tick ): void {
        try {
            // The tick's wall time covers queue maintenance too, so the runner's
            // own timing stays under its own key instead of being merged over.
            $payload               = $tick;
            $payload['candidates'] = array_map( 'intval', (array) ( $tick['candidates'] ?? array() ) );
            $payload['outcome']    = is_array( $outcome ) ? $outcome : null;
            $this->record( '_global', 'tick', 0, (int) $picked_task_id, $payload );
        } catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- diagnostics must never break a backup.
            return;
        }
    }

    /**
     * Record a preflight negotiation outcome.
     *
     * @param int    $site_id   Child site id.
     * @param int    $task_id   Backup task id, 0 when none drives the run.
     * @param array  $preflight Child readiness report, empty when unavailable.
     * @param string $status    ok|unavailable|unsupported|failed.
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
                    'preflight' => is_array( $preflight ) ? $this->redact_backup_payload( $preflight ) : array(),
                )
            );
        } catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- diagnostics must never break a backup.
            return;
        }
    }

    /**
     * Record a concurrency slot decision.
     *
     * @param string $event          acquired|refused|released|reclaimed_stale|lost.
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
     * Record a lane lease decision.
     *
     * @param string $event         acquired|refused|released|reclaimed_stale|lost.
     * @param string $token         Lane token, '' on refusal.
     * @param int    $active        Active lane holder count after the decision.
     * @param int    $lease_seconds Lane lease duration.
     *
     * @return void
     */
    public function on_lane( $event, $token, $active, $lease_seconds ): void {
        try {
            $this->record(
                '_global',
                'lane',
                0,
                0,
                array(
                    'event'         => (string) $event,
                    'token'         => (string) $token,
                    'active'        => (int) $active,
                    'lease_seconds' => (int) $lease_seconds,
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
     * @param string $run_key Run grouping key used to derive a private filename.
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

            $file = $dir . MainWP_System_Utility::get_private_filename( self::SUBDIR, $run_key, 'events' ) . '.jsonl';

            // phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- locked append on the hot path; failures are silent by design.
            $handle = @fopen( $file, 'ab' );
            if ( false === $handle ) {
                return;
            }
            // The flush must happen INSIDE the lock: fwrite lands in PHP's
            // stream buffer, and without the explicit fflush the real write()
            // happens at fclose - after the unlock - so two contending
            // processes interleave partial lines exactly when the events
            // matter most. A failed lock skips the write for the same reason.
            if ( @flock( $handle, LOCK_EX ) ) {
                @fwrite( $handle, $line . "\n" );
                @fflush( $handle );
                @flock( $handle, LOCK_UN );
            }
            @fclose( $handle );
            // phpcs:enable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged
        } catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- diagnostics must never break a backup.
            return;
        }
    }

    /**
     * Bind an exported bundle to its requesting user for ten minutes.
     *
     * @param string $bundle Exported bundle path.
     * @return string Download URL with raw query separators for the Settings script.
     */
    public function create_download_url( string $bundle ): string {
        $token = wp_generate_password( 32, false );
        set_site_transient(
            self::DOWNLOAD_TRANSIENT_PREFIX . hash( 'sha256', $token ),
            array(
                'user_id' => get_current_user_id(),
                'file'    => basename( $bundle ),
                'expires' => time() + 600,
            ),
            600
        );
        return add_query_arg(
            array(
                'action'   => 'mainwp_backup_diagnostics_download',
                'token'    => $token,
                '_wpnonce' => wp_create_nonce( 'mainwp_backup_diagnostics_download' ),
            ),
            admin_url( 'admin-post.php' )
        );
    }

    /**
     * Require the exporting user's current authorization before serving a bundle.
     *
     * @return void
     */
    public function download_bundle(): void {
        $message = __( 'This download link is not valid or has expired. Export the diagnostics again.', 'mainwp' );
        if ( ! is_user_logged_in() ) {
            wp_die( esc_html( $message ), '', array( 'response' => 403 ) );
        }
        if ( ! MainWP_System_Utility::is_admin() || ! \mainwp_current_user_can( 'dashboard', 'execute_backups' ) ) {
            wp_die( esc_html__( 'You do not have permission to download backup diagnostics.', 'mainwp' ), '', array( 'response' => 403 ) );
        }
        $nonce = isset( $_GET['_wpnonce'] ) && is_string( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'mainwp_backup_diagnostics_download' ) ) {
            wp_die( esc_html( $message ), '', array( 'response' => 403 ) );
        }
        $token = isset( $_GET['token'] ) && is_string( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
        $key   = self::DOWNLOAD_TRANSIENT_PREFIX . hash( 'sha256', $token );
        if ( ! preg_match( '/^[a-zA-Z0-9]{32}$/', $token ) ) {
            wp_die( esc_html( $message ), '', array( 'response' => 403 ) );
        }
        $record = get_site_transient( $key );
        $dir    = $this->diagnostics_dir();
        $file   = is_array( $record ) && isset( $record['file'] ) && is_string( $record['file'] ) ? $record['file'] : '';
        $path   = $dir . $file;
        if (
            ! is_array( $record ) || ! isset( $record['user_id'], $record['expires'] ) ||
            ! is_int( $record['user_id'] ) || get_current_user_id() !== $record['user_id'] ||
            ! is_int( $record['expires'] ) || time() >= $record['expires'] ||
            '' === $file || basename( $file ) !== $file ||
            false !== strpos( $file, '/' ) || false !== strpos( $file, '\\' ) ||
            '' === $dir || ! MainWP_Backup_File_Manager::path_is_confined_to( $path, $dir ) || ! is_file( $path )
        ) {
            delete_site_transient( $key );
            wp_die( esc_html( $message ), '', array( 'response' => 403 ) );
        }
        // Consume before streaming so a completed download cannot be replayed.
        if ( ! delete_site_transient( $key ) ) {
            wp_die( esc_html( $message ), '', array( 'response' => 403 ) );
        }
        while ( ob_get_level() ) {
            ob_end_clean();
        }
        $result = MainWP_Backup_Download::stream_file( $path );
        if ( true === $result ) {
            wp_delete_file( $path );
        } elseif ( is_wp_error( $result ) ) {
            wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 500 ) );
        }
    }

    // phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing -- export failures are caught and returned as an empty path.
    /**
     * Build the export bundle: env.json, bounded table dumps, and the
     * redacted event files, zipped inside the diagnostics directory. Falls
     * back to a single concatenated .json when ZipArchive is missing.
     *
     * The wp_backup_storage table is deliberately absent - it holds
     * provider credentials - and the snapshot rows are exported without
     * their encryption metadata.
     *
     * The export endpoint is gated on the global execute_backups
     * capability, which says nothing about which sites the caller may
     * reach, so a site-scoped user gets a bundle narrowed to their own
     * sites: the per-site table rows, the per-site event files, and the
     * shared _global stream line by line.
     *
     * @return string Bundle path, or '' when there is nothing to export
     *                or the bundle could not be written.
     */
    public function export_bundle(): string {
        // phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.Missing
        $temporary_files = array();
        $zip             = null;
        $path            = '';
        $complete        = false;
        try {
            $dir = $this->diagnostics_dir();
            if ( '' === $dir || ! is_dir( $dir ) ) {
                return '';
            }

            $this->prune_bundles( $dir );

            $event_files = (array) glob( $dir . '*.jsonl' );
            if ( empty( $event_files ) ) {
                return '';
            }

            $allowed_sites = $this->export_scope_site_ids();
            $sections      = $this->bundle_sections( $dir, $allowed_sites );
            $events        = $this->scope_event_files( $event_files, $allowed_sites, $sections['tasks'] );
            $base          = $dir . MainWP_System_Utility::get_private_filename( self::SUBDIR, wp_generate_uuid4(), 'bundle' );

            if ( class_exists( \ZipArchive::class ) ) {
                $path = $base . '.zip';
                $zip  = new \ZipArchive();
                if ( true !== $zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
                    $zip = null;
                    return '';
                }
                foreach ( $sections as $name => $data ) {
                    $zip->addFromString( $name . '.json', (string) wp_json_encode( $data, JSON_PRETTY_PRINT ) );
                }
                foreach ( $events['files'] as $event_file ) {
                    $temporary = tempnam( $dir, 'redacted-' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- private export staging.
                    if ( false === $temporary ) {
                        throw new \RuntimeException( 'Could not create a redacted event file.' );
                    }
                    $temporary_files[] = $temporary;
                    if ( realpath( dirname( $temporary ) ) !== realpath( $dir ) ) {
                        throw new \RuntimeException( 'Could not stage diagnostics in the private directory.' );
                    }
                    $scope = $events['scopes'][ $event_file ] ?? array( null, array() );
                    $this->write_redacted_event_file( $event_file, $temporary, $scope );
                    if ( ! $zip->addFile( $temporary, 'events/' . basename( $event_file ) ) ) {
                        throw new \RuntimeException( 'Could not add an event file to the diagnostics bundle.' );
                    }
                }
                $closed = $zip->close();
                $zip    = null;
                if ( ! $closed ) {
                    return '';
                }
                $complete = true;
                return $path;
            }

            return $this->write_json_bundle( $base . '.json', $sections, $events );
        } catch ( \Throwable $e ) {
            return '';
        } finally {
            try {
                if ( null !== $zip ) {
                    $zip->close();
                }
            } catch ( \Throwable $e ) {
                return '';
            } finally {
                foreach ( $temporary_files as $temporary ) {
                    wp_delete_file( $temporary );
                }
                if ( ! $complete && '' !== $path ) {
                    wp_delete_file( $path );
                }
            }
        }
    }

    /**
     * Stream redacted events to a private file kept until the ZIP closes.
     *
     * @param string $source Event file.
     * @param string $path Temporary destination.
     * @param array  $scope Allowed sites and tasks.
     * @return void
     * @throws \RuntimeException When the temporary file cannot be written.
     */
    private function write_redacted_event_file( string $source, string $path, array $scope ): void {
        // phpcs:disable WordPress.WP.AlternativeFunctions -- streaming private diagnostics files.
        $output = fopen( $path, 'wb' );
        if ( false === $output ) {
            throw new \RuntimeException( 'Could not open a redacted event file.' );
        }
        try {
            foreach ( $this->filter_event_lines( $source, $scope[0], $scope[1] ) as $line ) {
                $line .= "\n";
                if ( strlen( $line ) !== fwrite( $output, $line ) ) {
                    throw new \RuntimeException( 'Could not write a redacted event file.' );
                }
            }
            if ( ! fflush( $output ) ) {
                throw new \RuntimeException( 'Could not flush a redacted event file.' );
            }
        } finally {
            fclose( $output );
        }
        // phpcs:enable WordPress.WP.AlternativeFunctions
    }

    // phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing -- export failures are caught and returned as an empty path.
    /**
     * Stream the fallback so retention-sized event directories do not have
     * to fit in memory alongside the encoded bundle.
     *
     * @param string $path Destination path.
     * @param array  $sections Non-event bundle sections.
     * @param array  $events Scoped event files and per-file scope filters.
     * @return string Written path, or an empty string on failure.
     */
    private function write_json_bundle( string $path, array $sections, array $events ): string {
        // phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.Missing
        // phpcs:disable WordPress.WP.AlternativeFunctions -- streaming local diagnostics.
        $output = fopen( $path, 'wb' );
        if ( false === $output ) {
            return '';
        }
        $complete = false;
        try {
            $encode = static function ( $value ): string {
                $json = wp_json_encode( $value );
                if ( false === $json ) {
                    throw new \RuntimeException( 'Could not encode the diagnostics bundle.' );
                }
                return $json;
            };
            $write  = static function ( string $value ) use ( $output ) {
                if ( strlen( $value ) !== fwrite( $output, $value ) ) {
                    throw new \RuntimeException( 'Could not write the diagnostics bundle.' );
                }
            };
            $write( '{' );
            foreach ( $sections as $name => $data ) {
                $write( $encode( (string) $name ) . ':' . $encode( $data ) . ',' );
            }
            $has_events = ! empty( $events['files'] );
            $write( '"events":' . ( $has_events ? '{' : '[]' ) );
            $separator = '';
            foreach ( $events['files'] as $event_file ) {
                $write( $separator . $encode( basename( $event_file ) ) . ':[' );
                $separator      = ',';
                $scope          = $events['scopes'][ $event_file ] ?? array( null, array() );
                $line_separator = '';
                foreach ( $this->filter_event_lines( $event_file, $scope[0], $scope[1] ) as $line ) {
                    $write( $line_separator . $encode( $line ) );
                    $line_separator = ',';
                }
                $write( ']' );
            }
            $write( $has_events ? '}}' : '}' );
            if ( ! fflush( $output ) ) {
                throw new \RuntimeException( 'Could not flush the diagnostics bundle.' );
            }
            $complete = true;
        } catch ( \Throwable $e ) {
            return '';
        } finally {
            fclose( $output );
            if ( ! $complete ) {
                wp_delete_file( $path );
            }
        }
        return $path;
        // phpcs:enable WordPress.WP.AlternativeFunctions
    }

    /**
     * The site ids the current user may receive rows for, or null when the
     * user can reach every site the dashboard manages (the common
     * single-user case, which exports exactly as before).
     *
     * The two checks are the ones every per-site backup path already
     * applies - MainWP_Post_Backup_Handler::authorize_restore_site() and
     * MainWP_Manage_Backups_Handler::authorize_task_scope() both gate on
     * can_edit_website() plus the 'site' right.
     *
     * @return int[]|null Allowed site ids, or null when unrestricted.
     */
    private function export_scope_site_ids(): ?array {
        global $wpdb;

        $site_ids = (array) $wpdb->get_col( 'SELECT id FROM ' . MainWP_DB::instance()->get_table_name( 'wp' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- id list of a known internal table, no user input.

        if ( empty( $site_ids ) ) {
            return array();
        }

        $allowed = array();
        foreach ( $site_ids as $site_id ) {
            $site_id = (int) $site_id;
            $website = MainWP_DB::instance()->get_website_by_id( $site_id );
            if ( MainWP_System_Utility::can_edit_website( $website ) && \mainwp_current_user_can( 'site', $site_id ) ) {
                $allowed[] = $site_id;
            }
        }

        return count( $allowed ) === count( $site_ids ) ? null : $allowed;
    }

    /**
     * Select event files and shared-stream lines within the caller's site scope.
     *
     * Run keys carry their site: "terminal-<site>", "<task>-<site>" and
     * "<task>-<site>-preflight" name it directly, and "restore-<job>" /
     * "rtransfer-<child task>" resolve through the restore job table. The
     * shared _global stream mixes sites, so it is filtered by each line's
     * own site_id and task_id instead of being dropped. A run key whose
     * site cannot be resolved is left out rather than guessed at.
     *
     * @param string[]   $event_files Absolute paths of the run event files.
     * @param int[]|null $allowed     Allowed site ids, null when unrestricted.
     * @param array      $tasks       The exported task rows, already scoped.
     *
     * @return array{files:string[],scopes:array}
     */
    private function scope_event_files( array $event_files, ?array $allowed, array $tasks ): array {
        // A file can vanish between the glob and this read, and the stat cache can be stale within a request.
        clearstatcache();
        $event_files = array_values( array_filter( $event_files, static fn( $file ) => is_file( $file ) && 0 !== filesize( $file ) ) );
        if ( null === $allowed ) {
            return array(
                'files'  => $event_files,
                'scopes' => array(),
            );
        }

        $allowed_tasks = array_map( static fn( $task ) => (int) $task['id'], $tasks );
        $by_run_key    = $this->restore_run_key_sites();

        // Scope is carried in the event because private filenames are opaque.
        $global_key = sanitize_file_name( '_global' );

        $files  = array();
        $scopes = array();
        foreach ( $event_files as $event_file ) {
            $run_key = $this->event_file_run_key( $event_file );
            if ( $global_key === $run_key ) {
                $files[]               = $event_file;
                $scopes[ $event_file ] = array( $allowed, $allowed_tasks );
                continue;
            }

            $site_id = isset( $by_run_key[ $run_key ] ) ? $by_run_key[ $run_key ] : $this->run_key_site_id( $run_key );
            if ( null !== $site_id && in_array( $site_id, $allowed, true ) ) {
                $files[] = $event_file;
            }
        }

        return array(
            'files'  => $files,
            'scopes' => $scopes,
        );
    }

    /**
     * The site behind every restore-scoped run key, keyed by run key.
     *
     * @return array<string,int> Run key => site id.
     */
    private function restore_run_key_sites(): array {
        global $wpdb;

        $rows = (array) $wpdb->get_results( 'SELECT id, site_id, child_task_id FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- known internal table, no user input.

        // Match the normalized run keys read from the event metadata.
        $map = array();
        foreach ( $rows as $row ) {
            $map[ sanitize_file_name( 'restore-' . (int) $row['id'] ) ] = (int) $row['site_id'];
            if ( '' !== (string) $row['child_task_id'] ) {
                $map[ sanitize_file_name( 'rtransfer-' . (string) $row['child_task_id'] ) ] = (int) $row['site_id'];
            }
        }

        return $map;
    }

    /**
     * The site id a name-scoped run key belongs to, or null when the key
     * does not name one.
     *
     * @param string $run_key Run key from the event metadata.
     *
     * @return int|null Site id, or null when unresolvable.
     */
    private function run_key_site_id( string $run_key ): ?int {
        if ( 0 === strpos( $run_key, 'terminal-' ) ) {
            $site_id = (int) substr( $run_key, strlen( 'terminal-' ) );
            return $site_id > 0 ? $site_id : null;
        }

        // "<task>-<site>", plus the optional child task id or "preflight"
        // suffix the poll and preflight keys carry.
        $parts = explode( '-', $run_key );
        if ( count( $parts ) >= 2 && ctype_digit( $parts[0] ) && ctype_digit( $parts[1] ) && (int) $parts[1] > 0 ) {
            return (int) $parts[1];
        }

        return null;
    }

    /**
     * Read one event file and keep only the lines within the caller's
     * scope: a line naming no site and no task (a host-level slot or
     * guardrail record) stays, one naming either must name an allowed one.
     *
     * @param string     $file          Event file path.
     * @param int[]|null $allowed_sites Allowed site ids, null when unrestricted.
     * @param int[]      $allowed_tasks Allowed task ids.
     *
     * @throws \RuntimeException When an event cannot be read or encoded.
     * @return \Generator Redacted JSON lines within the caller's scope.
     */
    private function filter_event_lines( string $file, ?array $allowed_sites, array $allowed_tasks ): \Generator {
        $handle = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- stream local diagnostics.
        if ( false === $handle ) {
            throw new \RuntimeException( 'Could not read a diagnostics event file.' );
        }

        try {
            while ( false !== ( $line = fgets( $handle ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
                $line = rtrim( $line, "\n" );
                if ( '' === trim( $line ) ) {
                    continue;
                }
                $event = json_decode( $line, true );
                if ( ! is_array( $event ) ) {
                    continue;
                }
                $site_id = isset( $event['site_id'] ) ? (int) $event['site_id'] : 0;
                $task_id = isset( $event['task_id'] ) ? (int) $event['task_id'] : 0;
                if ( null !== $allowed_sites && 0 !== $site_id && ! in_array( $site_id, $allowed_sites, true ) ) {
                    continue;
                }
                if ( null !== $allowed_sites && 0 !== $task_id && ! in_array( $task_id, $allowed_tasks, true ) ) {
                    continue;
                }
                // A tick line passes on its picked task, but its candidate list
                // came from the unscoped due query and would name other users' tasks.
                if ( null !== $allowed_sites && 'tick' === ( $event['kind'] ?? '' ) && isset( $event['payload']['candidates'] ) ) {
                    $candidates = array_map( 'intval', (array) $event['payload']['candidates'] );
                    $candidates = array_filter( $candidates, static fn( $id ) => in_array( $id, $allowed_tasks, true ) );

                    $event['payload']['candidates'] = array_values( $candidates );
                }
                $event = $this->redact_backup_payload( $this->redact_restore_secrets( $event ) );
                if ( isset( $event['payload'] ) && is_array( $event['payload'] ) ) {
                    $event['payload'] = $this->redact_backup_payload( $event['payload'], true );
                }
                $redacted = wp_json_encode( $event );
                if ( false === $redacted ) {
                    throw new \RuntimeException( 'Could not encode a diagnostics event.' );
                }
                yield $redacted;
            }
        } finally {
            fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- stream local diagnostics.
        }
    }

    /**
     * Recover the scope identifier without exposing it in the filename.
     *
     * @param string $file Event file path.
     *
     * @return string Run key, or empty when unreadable.
     */
    private function event_file_run_key( string $file ): string {
        $handle = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- stream local diagnostics.
        if ( false === $handle ) {
            return '';
        }
        try {
            while ( false !== ( $line = fgets( $handle ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
                $event = json_decode( $line, true );
                if ( is_array( $event ) && isset( $event['run_key'] ) ) {
                    return sanitize_file_name( (string) $event['run_key'] );
                }
            }
        } finally {
            fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- stream local diagnostics.
        }
        return '';
    }

    /**
     * The bundle's JSON sections: the environment summary plus bounded
     * dumps of the backup tables.
     *
     * @param string     $dir     Diagnostics directory (for the disk-free probe).
     * @param int[]|null $allowed Site ids the caller may receive rows for;
     *                            null exports every site.
     *
     * @return array Section name => data.
     */
    private function bundle_sections( string $dir, ?array $allowed = null ): array {
        global $wpdb;

        /**
         * Filters the per-table row cap for the diagnostics export.
         *
         * @param int $cap Maximum rows exported per table.
         */
        $cap       = max( 1, (int) apply_filters( 'mainwp_backup_diagnostics_export_row_cap', 5000 ) );
        $truncated = false;

        // A scoped caller gets the same tables narrowed to their own sites.
        // The narrowing is pushed into SQL for the site-keyed tables so the
        // row cap is spent on rows they may actually see, rather than
        // trimming a full-network page down to nothing.
        $snapshot_table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
        $scoped         = null !== $allowed;
        $site_list      = $scoped ? implode( ',', array_map( 'intval', $allowed ) ) : '';

        $tables = array(
            'tasks'        => array( 'wp_backup', '' ),
            'snapshots'    => array( 'wp_backup_snapshot', 'site_id IN (' . $site_list . ')' ),
            'progress'     => array( 'wp_backup_progress', 'wp_id IN (' . $site_list . ')' ),
            'restore_jobs' => array( 'wp_backup_restore_job', 'site_id IN (' . $site_list . ')' ),
            'upload_jobs'  => array( 'wp_backup_upload_job', 'snapshot_id IN ( SELECT id FROM ' . $snapshot_table . ' WHERE site_id IN (' . $site_list . ') )' ),
        );

        $message_columns = array(
            'tasks'        => array( 'backup_errors', 'backup_notices' ),
            'progress'     => array( 'fetchResult', 'last_error', 'current_file' ),
            'restore_jobs' => array( 'last_error' ),
            'upload_jobs'  => array( 'last_error' ),
        );

        $sections = array();
        foreach ( $tables as $name => $spec ) {
            list( $table, $scope_where ) = $spec;

            if ( $scoped && '' !== $scope_where && empty( $allowed ) ) {
                // No reachable site: no per-site rows, and no "IN ()" query.
                $sections[ $name ] = array();
                continue;
            }

            $table_name = MainWP_DB::instance()->get_table_name( $table );
            $where      = $scoped && '' !== $scope_where ? ' WHERE ' . $scope_where : '';
            // nosemgrep: AIK_tainted-sql-string -- Table names are internal, site ids pass through intval(), and LIMIT is prepared.
            $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $table_name . $where . ' LIMIT %d', $cap + 1 ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- bounded diagnostic dump of a known internal table; the scope clause interpolates only intval'd site ids.
            $rows = is_array( $rows ) ? $rows : array();
            if ( count( $rows ) > $cap ) {
                $rows      = array_slice( $rows, 0, $cap );
                $truncated = true;
            }
            if ( 'snapshots' === $name ) {
                foreach ( $rows as $index => $row ) {
                    unset( $rows[ $index ]['encryption'] );
                }
            }
            // The task table names its sites in a comma list (and through
            // groups), so it cannot be narrowed in SQL: a task is exported
            // only when every site it targets is one the caller may reach,
            // the same rule the task edit gate applies.
            if ( 'tasks' === $name && $scoped ) {
                $rows = array_values(
                    array_filter(
                        $rows,
                        static function ( $row ) use ( $allowed ) {
                            $task_sites = MainWP_Manage_Backups_Handler::expand_task_site_ids( $row['sites'] ?? '', $row['groups'] ?? '' );
                            return array() === array_diff( $task_sites, $allowed );
                        }
                    )
                );
            }
            foreach ( $rows as $index => $row ) {
                foreach ( $message_columns[ $name ] ?? array() as $column ) {
                    if ( ! isset( $row[ $column ] ) || ! is_string( $row[ $column ] ) || '' === $row[ $column ] ) {
                        continue;
                    }
                    $rows[ $index ][ $column ] = $this->redact_exported_text( $row[ $column ] );
                }
            }
            $sections[ $name ] = $rows;
        }

        // Per-site provenance: the deployed child plugin version (sync data)
        // and the negotiated backup capability flags. Confirming what a site
        // actually runs required behavioral inference without these.
        $flag_names = array_keys( MainWP_Backup_Monitor::NEGOTIATED_CAPABILITY_FLAGS );

        $wp_table      = MainWP_DB::instance()->get_table_name( 'wp' );
        $sync_table    = MainWP_DB::instance()->get_table_name( 'wp_sync' );
        $options_table = MainWP_DB::instance()->get_table_name( 'wp_options' );
        $site_where    = $scoped ? ' WHERE w.id IN (' . $site_list . ')' : '';
        $site_rows     = array();
        if ( ! $scoped || ! empty( $allowed ) ) {
            // nosemgrep: AIK_tainted-sql-string -- Table names are internal, site ids pass through intval(), and LIMIT is prepared.
            $site_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT w.id, w.name, w.url, s.version FROM ' . $wp_table . ' w LEFT JOIN ' . $sync_table . ' s ON s.wpid = w.id' . $site_where . ' LIMIT %d', $cap + 1 ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- bounded diagnostic dump of a known internal table; the scope clause interpolates only intval'd site ids.
        }
        $site_rows = is_array( $site_rows ) ? $site_rows : array();
        if ( count( $site_rows ) > $cap ) {
            $site_rows = array_slice( $site_rows, 0, $cap );
            $truncated = true;
        }

        $flags_by_site = array();
        if ( ! empty( $site_rows ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $flag_names ), '%s' ) );
            $option_rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT wpid, name, value FROM ' . $options_table . ' WHERE name IN (' . $placeholders . ')', $flag_names ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery -- the IN list is only %s placeholders; values are prepared.
            foreach ( (array) $option_rows as $option_row ) {
                $flags_by_site[ (int) $option_row['wpid'] ][ (string) $option_row['name'] ] = (string) $option_row['value'];
            }
        }

        $sections['sites'] = array_map(
            function ( $row ) use ( $flags_by_site ) {
                return array(
                    'id'            => (int) $row['id'],
                    'name'          => (string) $row['name'],
                    'url'           => (string) $row['url'],
                    'child_version' => isset( $row['version'] ) ? (string) $row['version'] : '',
                    'capabilities'  => isset( $flags_by_site[ (int) $row['id'] ] ) ? $flags_by_site[ (int) $row['id'] ] : array(),
                );
            },
            $site_rows
        );

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
     * Bundle downloads need a shorter expiry than retained event history.
     *
     * @param string $dir Diagnostics directory with trailing slash.
     * @return void
     */
    private function prune_bundles( string $dir ): void {
        $cutoff = time() - self::BUNDLE_TTL_SECONDS;
        foreach ( array_merge( (array) glob( $dir . '*.zip' ), (array) glob( $dir . '*.json' ) ) as $file ) {
            if ( is_file( $file ) && (int) filemtime( $file ) < $cutoff ) {
                wp_delete_file( $file );
            }
        }
    }

    /**
     * Retention prune: delete bundles past their TTL, then other
     * diagnostics files past the age limit, then those oldest-first until
     * they fit the size cap. Runs on the daily backups prune cron.
     *
     * @return void
     */
    public function prune(): void {
        try {
            $dir = $this->diagnostics_dir();
            if ( '' === $dir || ! is_dir( $dir ) ) {
                return;
            }

            $this->prune_bundles( $dir );

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
                // Bundles left after prune_bundles() are inside their TTL and may be mid-download.
                if ( in_array( pathinfo( $file, PATHINFO_EXTENSION ), array( 'zip', 'json' ), true ) ) {
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
     * Delete a deleted task's event files. Task-scoped run keys all start
     * with "<task_id>-" (poll, preflight); the shared _global, terminal-*, and restore-* streams carry
     * events for other tasks and stay. Runs off
     * mainwp_backup_task_snapshots_purged, which both task-delete paths
     * fire.
     *
     * @param int $task_id Deleted task id.
     *
     * @return void
     */
    public function purge_task_files( $task_id ): void {
        try {
            $task_id = (int) $task_id;
            if ( $task_id <= 0 ) {
                return;
            }

            $dir = $this->diagnostics_dir();
            if ( '' === $dir || ! is_dir( $dir ) ) {
                return;
            }

            foreach ( (array) glob( $dir . '*.jsonl' ) as $file ) {
                if ( ! is_file( $file ) ) {
                    continue;
                }
                $run_key = $this->event_file_run_key( $file );
                if ( str_starts_with( $run_key, $task_id . '-' ) || ( '' === $run_key && 0 === filesize( $file ) ) ) {
                    wp_delete_file( $file );
                }
            }
        } catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- cleanup must never break the task delete.
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
            $dir = trailingslashit( MainWP_System_Utility::get_mainwp_sub_dir( self::SUBDIR ) );
            if ( ! file_exists( $dir . 'index.php' ) ) {
                MainWP_System_Utility::touch( $dir . 'index.php' );
            }
            return $dir;
        } catch ( \Throwable $e ) {
            return '';
        }
    }
}
