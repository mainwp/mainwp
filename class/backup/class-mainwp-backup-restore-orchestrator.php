<?php
/**
 * MainWP Backup Restore Orchestrator - the dashboard side of a restore.
 *
 * A restore job moves through dashboard phases on budgeted cron slices:
 * an optional pre-restore safety backup runs to completion as a normal
 * child backup task (its artifacts stay on the child - they are the
 * file rollback source and adopt with zero transfer); staging makes
 * every plan artifact a verified local file; the child restore task is
 * created and the artifacts stream over as hash-verified chunks; the
 * child task is then driven one slice per tick to its terminal phase;
 * and a final external reachability check gates completion.
 *
 * The child enforces one active task per site, which is why the
 * pre-restore backup runs and finishes before the restore task is
 * created. The dashboard mirrors that with one active restore job per
 * site.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Class MainWP_Backup_Restore_Orchestrator
 */
class MainWP_Backup_Restore_Orchestrator { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Cron slice budget, seconds. Below the upload driver's 240 so both
     * fit one continuation tick with headroom.
     */
    const DEFAULT_BUDGET_SECONDS = 200;

    /** No further work belongs to this caller. */
    const TERMINAL_OUTCOME_TERMINAL = 'terminal';

    /** A durable decision or child confirmation must be retried. */
    const TERMINAL_OUTCOME_DEFERRED = 'deferred';

    /** The normal completion path may continue. */
    const TERMINAL_OUTCOME_DRIVE_ON = 'drive_on';

    /**
     * Singleton.
     *
     * @var MainWP_Backup_Restore_Orchestrator|null
     */
    private static $instance = null;

    /**
     * The lease token for the slice currently driven by
     * process_pending_restore_jobs(), threaded into every job-store write
     * so a worker whose lease was reclaimed is fenced out. Null outside a
     * slice: direct run_job() callers hold no lease and write unfenced.
     *
     * @var string|null
     */
    private $lock_token = null;

    /**
     * The lease duration of the current slice, used by renewals.
     *
     * @var int
     */
    private $lease_seconds = 0;

    /**
     * Get the singleton instance.
     *
     * @return MainWP_Backup_Restore_Orchestrator
     */
    public static function instance(): self {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    // phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber -- all throws are MainWP_Exception.
    /**
     * Create a restore job for a snapshot.
     *
     * @param int   $site_id     Child site id.
     * @param int   $snapshot_id Snapshot to restore.
     * @param array $options     { pre_snapshot: bool, file_rollback: bool }.
     *
     * @throws MainWP_Exception When the restore cannot start.
     *
     * @return int Job id.
     */
    public function initiate_restore( int $site_id, int $snapshot_id, array $options = array() ): int {
        // phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber
        if ( ! MainWP_Backup_Settings::is_native_backups_enabled() ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_LEGACY_DISABLED ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }

        $website = MainWP_DB::instance()->get_website_by_id( $site_id );
        if ( empty( $website ) ) {
            throw new MainWP_Exception( esc_html__( 'Site not found.', 'mainwp' ) );
        }

        if ( null !== MainWP_Backup_Restore_Job_Store::instance()->get_active_job_for_site( $site_id ) ) {
            throw new MainWP_Exception( esc_html__( 'A restore is already running for this site. Wait for it to finish before starting another.', 'mainwp' ) );
        }

        if ( ! MainWP_Backup_Monitor::child_supports_restore_create_idempotency( $website ) ) {
            // The stored flag goes stale on exactly the update this refusal asks
            // for, so re-negotiate once before taking Restore away.
            MainWP_Backup_Monitor::refresh_negotiated_capabilities( $website );
            if ( MainWP_Backup_Monitor::child_requires_restore_create_idempotency_update( $website ) ) {
                throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_RESTORE_CHILD_UPDATE_REQUIRED ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
            }
        }

        $resolved = MainWP_Backup_Snapshot_Catalog::instance()->resolve_restorable_chain( $snapshot_id, $site_id );
        if ( ! $resolved['ok'] ) {
            throw new MainWP_Exception( esc_html( $resolved['error'] ) );
        }

        $checkpoint = array(
            'restore_id' => strtolower( wp_generate_password( 12, false ) ),
            'sub_type'   => $resolved['sub_type'],
            'plan'       => $resolved['plan'],
            'options'    => array(
                'pre_snapshot'    => ! empty( $options['pre_snapshot'] ),
                'file_rollback'   => ! empty( $options['file_rollback'] ),
                'preserve_orders' => ! empty( $options['preserve_orders'] ),
                'woo_order_since' => isset( $options['woo_order_since'] ) ? (int) $options['woo_order_since'] : 0,
            ),
            'transfer'   => array(),
        );

        $job_id = MainWP_Backup_Restore_Job_Store::instance()->create_job( $site_id, $snapshot_id, $checkpoint );
        if ( $job_id <= 0 ) {
            if ( 0 === $job_id && null !== MainWP_Backup_Restore_Job_Store::instance()->get_active_job_for_site( $site_id ) ) {
                // A concurrent submit won the create race for this site.
                throw new MainWP_Exception( esc_html__( 'A restore is already running for this site. Wait for it to finish before starting another.', 'mainwp' ) );
            }
            throw new MainWP_Exception( esc_html__( 'The restore job could not be recorded.', 'mainwp' ) );
        }

        $this->schedule_continuation();
        return $job_id;
    }

    /**
     * Whether a preserve-orders restore is certain to be refused by the
     * child's apply-phase gate, answered at initiate time so the refusal
     * costs a second instead of the whole transfer.
     *
     * Best effort with a hard no-false-refusals invariant. The two live
     * option checks mirror the child gate exactly (absent options refuse
     * the same way the gate would). The backup-content checks run only on
     * unambiguous meta: a database-bearing single snapshot that carries
     * woo_meta - an incremental chain's post-replay woocommerce_db_version
     * depends on which dump in the chain last wrote the options table,
     * which meta cannot answer. Any doubt (unreachable child, an older
     * child without the callable, a malformed response, absent meta)
     * defers to the apply-phase gate, which stays authoritative.
     *
     * @param object      $website  Website object.
     * @param object|null $snapshot Leaf snapshot row being restored.
     *
     * @return string|null Error code to refuse with, or null to proceed.
     */
    public function woo_preserve_refusal( object $website, ?object $snapshot ): ?string {
        try {
            $preflight = MainWP_Connect::fetch_url_authed( $website, 'restore_preflight', array(), false, false, false );
        } catch ( \Throwable $e ) {
            return null; // Unreachable: the apply-phase gate backstops.
        }
        if ( ! is_array( $preflight ) || isset( $preflight['error'] ) || ! array_key_exists( 'woo_hpos_enabled', $preflight ) ) {
            return null; // Older child or malformed response: defer.
        }

        if ( empty( $preflight['woo_hpos_enabled'] ) ) {
            return MainWP_Backup_Error_Codes::ERR_RESTORE_WOO_LEGACY_ORDERS;
        }
        if ( ! empty( $preflight['woo_sync_enabled'] ) ) {
            return MainWP_Backup_Error_Codes::ERR_RESTORE_WOO_SYNC_MODE;
        }

        if ( null === $snapshot || ! in_array( (string) $snapshot->type, array( 'full', 'db' ), true ) ) {
            return null;
        }
        $meta = isset( $snapshot->woo_meta ) && is_array( $snapshot->woo_meta ) ? $snapshot->woo_meta : array();
        if ( empty( $meta ) ) {
            return null;
        }

        if ( empty( $meta['has_wc_orders'] ) ) {
            return MainWP_Backup_Error_Codes::ERR_RESTORE_WOO_LEGACY_ORDERS;
        }

        $backup_ver = isset( $meta['woocommerce_db_version'] ) ? (string) $meta['woocommerce_db_version'] : '';
        $live_ver   = isset( $preflight['woo_db_version'] ) ? (string) $preflight['woo_db_version'] : '';
        if ( '' !== $backup_ver && '' !== $live_ver && $backup_ver !== $live_ver ) {
            return MainWP_Backup_Error_Codes::ERR_RESTORE_WOO_VERSION_SKEW;
        }

        return null;
    }

    /**
     * Whether this job still owns an available safety backup.
     *
     * A successor rollback owns the retry action even when it fails. Its
     * checkpoint link also prevents a completed rollback from being offered again.
     *
     * @param object $job Restore job with a decoded checkpoint.
     *
     * @return bool Whether the safety backup is available to this job.
     */
    public function has_rollback_source( object $job ): bool {
        if ( MainWP_Backup_Restore_Job_Store::STATUS_COMPLETED === $job->status || empty( $job->checkpoint['pre_snapshot_artifacts'] ) ) {
            return false;
        }

        global $wpdb;
        $checkpoints = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- existing restore job table.
            $wpdb->prepare(
                'SELECT checkpoint FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ) . ' WHERE site_id = %d AND id > %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from trusted DB helper; values use placeholders.
                (int) $job->site_id,
                (int) $job->id
            )
        );
        foreach ( $checkpoints as $checkpoint_json ) {
            $checkpoint = json_decode( $checkpoint_json, true );
            if ( (int) ( $checkpoint['rollback_of'] ?? 0 ) === (int) $job->id ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Start a rollback restore from a completed pre-restore safety
     * backup: its artifacts are still on the child and adopt with zero
     * transfer.
     *
     * @param object $failed_job Failed restore job carrying
     *                           pre_snapshot_artifacts in its checkpoint.
     *
     * @throws MainWP_Exception When the job has no rollback source.
     *
     * @return int New job id.
     */
    public function initiate_rollback( object $failed_job ): int {
        if ( ! in_array( $failed_job->status, array( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT, MainWP_Backup_Restore_Job_Store::STATUS_CANCELLED ), true ) ) {
            throw new MainWP_Exception( esc_html__( 'Only failed or cancelled restores can be rolled back.', 'mainwp' ), '', 'mainwp_restore_rollback_status' );
        }

        if ( null !== MainWP_Backup_Restore_Job_Store::instance()->get_active_job_for_site( (int) $failed_job->site_id ) ) {
            throw new MainWP_Exception( esc_html__( 'A restore is already running for this site. Wait for it to finish before rolling back.', 'mainwp' ) );
        }

        $artifacts = isset( $failed_job->checkpoint['pre_snapshot_artifacts'] ) ? (array) $failed_job->checkpoint['pre_snapshot_artifacts'] : array();
        if ( ! $this->has_rollback_source( $failed_job ) ) {
            throw new MainWP_Exception( esc_html__( 'No pre-restore safety backup is recorded for this restore, so there is nothing to roll back to.', 'mainwp' ) );
        }

        $plan = array();
        foreach ( $artifacts as $artifact ) {
            $plan[] = array(
                'role'        => isset( $artifact['role'] ) ? (string) $artifact['role'] : 'full',
                'file'        => (string) $artifact['file'],
                'size'        => isset( $artifact['size'] ) ? (int) $artifact['size'] : 0,
                'sha256'      => '',
                'snapshot_id' => 0,
                'source'      => 'adopt',
                'local_path'  => '',
                'remote_key'  => '',
                'adopt_only'  => true,
            );
        }

        $checkpoint = array(
            'restore_id'             => strtolower( wp_generate_password( 12, false ) ),
            'sub_type'               => 'full',
            'is_rollback'            => true,
            'rollback_of'            => (int) $failed_job->id,
            'pre_snapshot_artifacts' => $artifacts,
            'plan'                   => $plan,
            'options'                => array(
                'pre_snapshot'  => false,
                'file_rollback' => false,
            ),
            'transfer'               => array(),
        );

        $job_id = MainWP_Backup_Restore_Job_Store::instance()->create_job( (int) $failed_job->site_id, (int) $failed_job->snapshot_id, $checkpoint );
        if ( $job_id <= 0 ) {
            if ( 0 === $job_id && null !== MainWP_Backup_Restore_Job_Store::instance()->get_active_job_for_site( (int) $failed_job->site_id ) ) {
                // A concurrent submit won the create race for this site.
                throw new MainWP_Exception( esc_html__( 'A restore is already running for this site. Wait for it to finish before rolling back.', 'mainwp' ) );
            }
            throw new MainWP_Exception( esc_html__( 'The rollback job could not be recorded.', 'mainwp' ) );
        }

        $this->schedule_continuation();
        return $job_id;
    }

    /**
     * Cancel a restore job, deferring a busy child to the cron lane.
     *
     * Admission ends when verification begins. The cancellation matrix owns
     * child confirmation, durable decisions, cleanup and safety-backup release.
     *
     * @param int $job_id Job id.
     *
     * @throws MainWP_Exception When the job is past the cancel window,
     *                          or another lane already finished it.
     *
     * @return string 'cancelled' for a cancelled or permanently failed job,
     *                or 'cancel_pending' when the decision must be retried.
     */
    public function cancel_job( int $job_id ): string {
        $store = MainWP_Backup_Restore_Job_Store::instance();
        $job   = $store->get_job( $job_id );
        if ( null === $job ) {
            throw new MainWP_Exception( esc_html__( 'Restore job not found.', 'mainwp' ) );
        }

        switch ( $job->status ) {
            case MainWP_Backup_Restore_Job_Store::STATUS_CANCELLED:
                throw new MainWP_Exception( esc_html__( 'The restore is already cancelled.', 'mainwp' ) );
            case MainWP_Backup_Restore_Job_Store::STATUS_VERIFYING:
            case MainWP_Backup_Restore_Job_Store::STATUS_COMPLETED:
            case MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT:
                throw new MainWP_Exception( esc_html__( 'This restore has already finished on the site and can no longer be cancelled. If it failed, you can roll it back instead.', 'mainwp' ) );
            default:
                break;
        }

        $outcome = $this->record_terminal_failure( $job, null, '', null );
        if ( self::TERMINAL_OUTCOME_DEFERRED === $outcome ) {
            return 'cancel_pending';
        }
        if ( self::TERMINAL_OUTCOME_TERMINAL === $outcome ) {
            $fresh = $store->get_job( $job_id );
            if ( null !== $fresh && in_array( $fresh->status, array( $store::STATUS_CANCELLED, $store::STATUS_FAILED_PERMANENT ), true ) ) {
                return 'cancelled';
            }
            if ( null === $fresh || ! in_array( $fresh->status, array( $store::STATUS_VERIFYING, $store::STATUS_COMPLETED ), true ) ) {
                throw new MainWP_Exception( esc_html__( 'The restore reached its final state while the cancellation was confirmed and no longer needs cancelling.', 'mainwp' ) );
            }
        }
        throw new MainWP_Exception( esc_html__( 'The restore already finished on the site and can no longer be cancelled.', 'mainwp' ) );
    }

    /**
     * Cancel and release the safety-backup handle this job owns, if any.
     *
     * Every caller runs this only after the job's own cancellation is
     * durable, so the handle is never given up by a job that is still
     * active. The option is per site: another lane's handle must stay
     * intact, and an absent handle means the task is terminal or already
     * released. Release runs in a finally so an unreachable child cannot
     * leave the handle set, and the whole thing is best effort: a site
     * that cannot be reached must not turn a completed cancellation into
     * a failure the caller reports.
     *
     * @param object $job Job row.
     *
     * @return void
     */
    private function cancel_owned_pre_snapshot_handle( object $job ): void {
        try {
            $website = $this->website( $job );
            // A handle this restore did not create belongs to another lane;
            // an absent handle means the task is terminal or already released.
            $raw    = MainWP_DB::instance()->get_website_option( (int) $website->id, MainWP_Backup_Executor::ADHOC_TASK_OPTION );
            $handle = json_decode( (string) $raw, true );
            $owner  = (string) $job->id;
            $owned  = is_array( $handle ) && isset( $handle['task_id'], $handle['owner'] ) && is_scalar( $handle['task_id'] ) && '' !== (string) $handle['task_id'] && is_scalar( $handle['owner'] ) && $owner === (string) $handle['owner'];
            if ( $owned ) {
                $task_id = (string) $handle['task_id'];
                try {
                    MainWP_Connect::fetch_url_authed( $website, 'backup_task_cancel', array( 'task_id' => $task_id ), false, false, false );
                } finally {
                    $this->release_pre_snapshot_handle( $website, $task_id, $owner );
                }
            }
        } catch ( \Throwable $e ) {
            do_action( 'mainwp_log_info', sprintf( 'Restore job %d: the site did not confirm cancelling its safety backup: %s', (int) $job->id, $e->getMessage() ), (int) $job->site_id, 0 );
        }
    }

    /**
     * Release an owned safety-backup handle so later restores cannot adopt it.
     *
     * The option is per site: a newer backup can replace it while a child call
     * is in flight, and another lane may own the same task id.
     *
     * @param object $website Website object.
     * @param string $task_id Job's pre-snapshot task id.
     * @param string $owner   Restore job id as a string.
     *
     * @return bool Whether the matching handle was cleared.
     */
    private function release_pre_snapshot_handle( object $website, string $task_id, string $owner ): bool {
        $db     = MainWP_DB::instance();
        $raw    = $db->get_website_option( (int) $website->id, MainWP_Backup_Executor::ADHOC_TASK_OPTION );
        $handle = json_decode( (string) $raw, true );
        if ( ! is_array( $handle ) || ! isset( $handle['task_id'] ) || ! is_scalar( $handle['task_id'] ) ) {
            return false;
        }

        $handle_task_id = (string) $handle['task_id'];
        $handle_owner   = $handle['owner'] ?? '';
        if ( '' === $handle_task_id || $handle_task_id !== $task_id || ! is_scalar( $handle_owner ) || (string) $handle_owner !== $owner ) {
            return false;
        }

        return $db->compare_and_swap_website_option( (int) $website->id, MainWP_Backup_Executor::ADHOC_TASK_OPTION, (string) $raw, '' );
    }

    /**
     * Ask the child to cancel its in-flight restore task, once.
     *
     * The child refuses while another runner holds the task lease (a
     * slice in flight) - that answer is surfaced as busy so the caller
     * can defer instead of waiting. A child task that already completed
     * cannot be cancelled - the restore happened - while one that
     * already failed needs no child-side work.
     *
     * @param object $job Job row.
     *
     * @throws MainWP_Exception When the child returned an error.
     *
     * @return string 'ok' (cancelled), 'failed' (already failed, nothing
     *                to cancel), 'completed' (restore happened),
     *                'busy' (slice in flight), or 'rolling_back' (rollback in progress).
     */
    private function request_child_cancel( object $job ): string {
        $website = $this->website( $job );

        $payload = MainWP_Connect::fetch_url_authed( $website, 'restore_task_cancel', array( 'task_id' => $job->child_task_id ), false, false, false );
        $result  = is_array( $payload ) && isset( $payload['result'] ) ? (string) $payload['result'] : '';
        $phase   = is_array( $payload ) && isset( $payload['phase'] ) ? (string) $payload['phase'] : '';

        if ( 'ok' === $result ) {
            return 'ok';
        }
        if ( 'already_terminal' === $result ) {
            return 'completed' === $phase ? 'completed' : 'failed';
        }
        if ( in_array( $result, array( 'busy', 'rolling_back' ), true ) ) {
            return $result;
        }

        $message = is_array( $payload ) && isset( $payload['error'] ) ? (string) $payload['error'] : esc_html__( 'The child site did not confirm the cancellation.', 'mainwp' );
        throw new MainWP_Exception( esc_html( $message ) );
    }

    /**
     * Apply a recorded cancel intent before driving the child.
     *
     * @param object $job Job row.
     *
     * @return string One of the TERMINAL_OUTCOME_* constants.
     */
    private function apply_cancel_intent( object $job ): string {
        $failure = $job->checkpoint['terminal_failure'] ?? array();
        return $this->record_terminal_failure( $job, $failure['error'] ?? null, $failure['error_code'] ?? '', $this->lock_token );
    }

    /**
     * Schedule an immediate continuation tick on the dedicated restore
     * lane so a job advances without waiting for the 5-minute backup
     * continuation cron.
     *
     * WordPress de-duplicates an identical pending single event within its
     * ~10-minute window, and spawn_cron only spawns once per
     * WP_CRON_LOCK_TIMEOUT, so repeated calls collapse into a natural
     * throttle rather than a busy loop. Under DISABLE_WP_CRON spawn_cron
     * is a no-op and the periodic cron remains the backstop.
     */
    protected function schedule_continuation(): void {
        if ( ! wp_next_scheduled( 'mainwp_restore_continue_action' ) ) {
            wp_schedule_single_event( time(), 'mainwp_restore_continue_action' );
        }
        spawn_cron();
    }

    /**
     * Drive pending restore jobs for one budgeted cron slice.
     *
     * Cheap phases (pending -> preflight -> staging ...) are drained in a
     * single slice instead of one phase per 5-minute tick: the loop keeps
     * advancing the job while it makes forward progress to another active
     * phase and the budget holds. A phase that returns without changing the
     * status (the child is still working), reaches a terminal state, or
     * backs off (failed_retryable) ends the drain. When the slice ends with
     * the job still mid-flight, a near-term continuation is scheduled so the
     * next poll does not wait for the periodic cron.
     *
     * @throws \Throwable When the reclaim sweep or lease acquisition fails.
     *
     * @param int      $budget_seconds Slice budget.
     * @param int|null $only_job_id    Drive only this job (a foreground poll
     *                                 advancing the restore it polls); null
     *                                 drives the next runnable job of any site,
     *                                 the cron behavior.
     */
    public function process_pending_restore_jobs( int $budget_seconds = self::DEFAULT_BUDGET_SECONDS, ?int $only_job_id = null ): void {
        $deadline = microtime( true ) + $budget_seconds;
        $token    = 'restore-' . wp_generate_password( 12, false );
        $store    = MainWP_Backup_Restore_Job_Store::instance();

        $lease_seconds = $budget_seconds + 60;

        /**
         * Filters the bound applied to child round trips during a restore
         * slice, so a single hung request cannot outlive the slice's lease
         * (the connect layer's own backstop is 20 hours).
         *
         * @param int $bound Timeout bound in seconds; defaults to the lease.
         */
        $request_bound = (int) apply_filters( 'mainwp_restore_child_request_timeout', $lease_seconds );
        $cap_timeout   = function ( $timeout ) use ( $request_bound ) {
            return min( (int) $timeout, max( 30, $request_bound ) );
        };
        add_filter( 'mainwp_fetch_url_site_timeout', $cap_timeout );

        // The sweep terminalizes jobs, which fires a third-party action and
        // touches the filesystem, so it can throw. Leaving the cap registered
        // would then bound every later child request in this process,
        // including backup calls that have nothing to do with a restore.
        try {
            $now = time();
            foreach ( $store->find_jobs_over_reclaim_ceiling( $store::active_statuses(), $now, $only_job_id ) as $expired_job ) {
                $this->record_terminal_failure(
                    $expired_job,
                    sprintf( 'Reclaimed %d times without completing a phase; the worker keeps dying on this restore, so it is stopped instead of retried forever.', $store::RECLAIM_CEILING ),
                    '',
                    null
                );
            }

            $job = $store->acquire_next_job( $token, $lease_seconds, $now, $only_job_id );
        } catch ( \Throwable $e ) {
            remove_filter( 'mainwp_fetch_url_site_timeout', $cap_timeout );
            throw $e;
        }

        if ( null === $job ) {
            remove_filter( 'mainwp_fetch_url_site_timeout', $cap_timeout );
            return;
        }
        $job_id = (int) $job->id;

        $this->lock_token    = $token;
        $this->lease_seconds = $lease_seconds;

        try {
            while ( true ) {
                // A slice that lost its lease (reclaimed after an expiry)
                // stops driving: the new holder owns the job now, and every
                // fenced write below would be refused anyway.
                if ( ! $store->renew_lease( $job_id, $token, $this->lease_seconds ) ) {
                    break;
                }

                $before = $job->status;
                $this->run_job( $job, $deadline );

                $job = $store->get_job( $job_id );
                if ( null === $job ) {
                    break;
                }
                $after = $job->status;
                if ( $after === $before
                    || MainWP_Backup_Restore_Job_Store::STATUS_FAILED_RETRYABLE === $after
                    || ! in_array( $after, MainWP_Backup_Restore_Job_Store::active_statuses(), true )
                    || microtime( true ) >= $deadline
                ) {
                    break;
                }
            }
        } catch ( \Throwable $e ) {
            $code = ( $e instanceof MainWP_Exception ) ? $e->get_message_error_code() : '';
            $this->record_failure( $job, MainWP_Backup_Error_Codes::sanitize_message( $e->getMessage() ), true, $code );
        } finally {
            remove_filter( 'mainwp_fetch_url_site_timeout', $cap_timeout );
            $store->release_lock( $job_id, $token );
            $this->lock_token    = null;
            $this->lease_seconds = 0;
        }

        // Still mid-flight on a phase that polls the child: kick a near-term
        // continuation rather than idling until the 5-minute cron. Skipped
        // for terminal jobs and for failed_retryable (its next_retry_at
        // backoff gates re-acquisition, so a spawn now would only no-op).
        $job = $store->get_job( $job_id );
        if ( null !== $job
            && MainWP_Backup_Restore_Job_Store::STATUS_FAILED_RETRYABLE !== $job->status
            && in_array( $job->status, MainWP_Backup_Restore_Job_Store::active_statuses(), true )
        ) {
            $this->schedule_continuation();
        }
    }

    /**
     * Renew the current slice's lease between long operations.
     *
     * Called inside the child poll loops so a slow-but-alive worker keeps
     * its lease instead of drifting toward a reclaim. False means the
     * lease was already reclaimed - the caller stops its phase; the new
     * holder owns the job. Callers outside a leased slice (direct
     * run_job() consumers) hold no token and always continue.
     *
     * @param object $job Job row.
     *
     * @return bool True when work on the job may continue.
     */
    private function lease_still_held( object $job ): bool {
        if ( null === $this->lock_token ) {
            return true;
        }
        return MainWP_Backup_Restore_Job_Store::instance()->renew_lease( (int) $job->id, $this->lock_token, $this->lease_seconds );
    }

    /**
     * Advance one job within the deadline.
     *
     * @param object $job      Locked job row.
     * @param float  $deadline Slice deadline.
     */
    public function run_job( object $job, float $deadline ): void { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- phase dispatch.
        $status = $job->status;
        if ( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_RETRYABLE === $status ) {
            // Resume the phase that failed.
            $status = isset( $job->checkpoint['resume_status'] ) ? (string) $job->checkpoint['resume_status'] : MainWP_Backup_Restore_Job_Store::STATUS_PENDING;
        }

        // A pending cancel is applied before any slice work: the restore
        // must not be driven forward once the user asked it to stop.
        $intent_phases = array(
            MainWP_Backup_Restore_Job_Store::STATUS_STAGING,
            MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING,
            MainWP_Backup_Restore_Job_Store::STATUS_RUNNING,
        );
        if ( ! empty( $job->checkpoint['cancel_intent'] ) && ( in_array( $status, $intent_phases, true ) || ! empty( $job->checkpoint['terminal_failure'] ) ) ) {
            if ( self::TERMINAL_OUTCOME_DRIVE_ON !== $this->apply_cancel_intent( $job ) ) {
                return;
            }
            // The child completed its restore: the intent was cleared on
            // the row, so the stale copy must not be written back below.
            $job = MainWP_Backup_Restore_Job_Store::instance()->get_job( (int) $job->id );
            if ( null === $job ) {
                return;
            }
            $status = $job->status;
        }

        switch ( $status ) {
            case MainWP_Backup_Restore_Job_Store::STATUS_PENDING:
                $this->phase_pending( $job );
                break;
            case MainWP_Backup_Restore_Job_Store::STATUS_PREFLIGHT:
                $this->phase_preflight( $job );
                break;
            case MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT:
                $this->phase_pre_snapshot( $job, $deadline );
                break;
            case MainWP_Backup_Restore_Job_Store::STATUS_STAGING:
                $this->phase_staging( $job, $deadline );
                break;
            case MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING:
                $this->phase_transferring( $job, $deadline );
                break;
            case MainWP_Backup_Restore_Job_Store::STATUS_RUNNING:
                $this->phase_running( $job, $deadline );
                break;
            case MainWP_Backup_Restore_Job_Store::STATUS_VERIFYING:
                $this->phase_verifying( $job );
                break;
            default:
                break; // Terminal states have nothing to drive.
        }
    }

    /**
     * Pending: route to the pre-restore safety backup or straight to
     * staging.
     *
     * @param object $job Job row.
     */
    private function phase_pending( object $job ): void {
        // Preflight runs first so a doomed restore (a vanished cloud artifact)
        // is stopped before the optional safety backup spends real work.
        MainWP_Backup_Restore_Job_Store::instance()->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_PREFLIGHT, array(), $this->lock_token );
    }

    /**
     * Preflight: fail a doomed restore fast, before any transfer or
     * destructive work, then route to the optional safety backup or
     * straight to staging.
     *
     * The cheap, high-value dashboard check is remote-artifact
     * availability: a snapshot the child would pull from cloud storage must
     * still exist there. Disk, database-permission, and writeability checks
     * run child-side as the restore task starts.
     *
     * @param object $job Job row.
     */
    private function phase_preflight( object $job ): void {
        $store = MainWP_Backup_Restore_Job_Store::instance();
        $plan  = isset( $job->checkpoint['plan'] ) ? (array) $job->checkpoint['plan'] : array();

        if ( ! $this->transfer_driver()->remote_artifacts_present( $plan ) ) {
            $this->record_failure(
                $job,
                MainWP_Backup_Error_Codes::message( MainWP_Backup_Error_Codes::ERR_RESTORE_ARTIFACT_MISSING ),
                false,
                MainWP_Backup_Error_Codes::ERR_RESTORE_ARTIFACT_MISSING
            );
            return;
        }

        if ( ! empty( $job->checkpoint['options']['pre_snapshot'] ) ) {
            $task_id = MainWP_Backup_Executor::trigger_backup( (int) $job->site_id, 'full', (string) $job->id );
            $store->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT, array( 'pre_snapshot_task_id' => $task_id ), $this->lock_token );
            return;
        }

        $store->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_STAGING, array(), $this->lock_token );
    }

    /**
     * Pre-snapshot: drive the safety backup task; its artifacts stay on
     * the child as the rollback source.
     *
     * @param object $job      Job row.
     * @param float  $deadline Slice deadline.
     */
    private function phase_pre_snapshot( object $job, float $deadline ): void {
        $store   = MainWP_Backup_Restore_Job_Store::instance();
        $website = $this->website( $job );

        while ( microtime( true ) < $deadline ) {
            $diag_start = microtime( true );
            $payload    = MainWP_Connect::fetch_url_authed( $website, 'backup_task_run', array( 'task_id' => $job->pre_snapshot_task_id ), false, false, false );

            /** This action is documented in class/backup/class-mainwp-backup-executor.php */
            do_action(
                'mainwp_backup_diag_poll',
                is_array( $payload ) ? $payload : array(),
                microtime( true ) - $diag_start,
                array(
                    'op'      => 'pre_snapshot',
                    'site_id' => (int) $job->site_id,
                    'task_id' => 0,
                )
            );

            // The child round trip may have outlasted the lease; a worker
            // that lost it stops here instead of writing over the new holder.
            if ( ! $this->lease_still_held( $job ) ) {
                return;
            }

            $phase = is_array( $payload ) && isset( $payload['phase'] ) ? (string) $payload['phase'] : '';

            if ( 'completed' === $phase ) {
                $artifacts = array();
                if ( ! empty( $payload['artifacts'] ) && is_array( $payload['artifacts'] ) ) {
                    $artifacts = $payload['artifacts'];
                } elseif ( ! empty( $payload['file'] ) ) {
                    $artifacts[] = array(
                        'role' => 'full',
                        'file' => basename( (string) $payload['file'] ),
                        'size' => isset( $payload['size'] ) ? (int) $payload['size'] : 0,
                    );
                }
                $checkpoint                           = $job->checkpoint;
                $checkpoint['pre_snapshot_artifacts'] = $artifacts;
                $store->save_checkpoint( (int) $job->id, $checkpoint, $this->lock_token );

                // A persisted handle would short-circuit the NEXT restore's
                // trigger_backup() into reusing this finished task, and its
                // later success would prune an artifact this job still counts
                // on for rollback.
                $this->release_pre_snapshot_handle( $website, (string) $job->pre_snapshot_task_id, (string) $job->id );

                $store->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_STAGING, array(), $this->lock_token );
                return;
            }
            if ( 'failed' === $phase || '' === $phase ) {
                $error      = is_array( $payload ) && isset( $payload['error'] ) ? MainWP_Backup_Error_Codes::sanitize_message( (string) $payload['error'] ) : esc_html__( 'The pre-restore safety backup failed.', 'mainwp' );
                $error_code = is_array( $payload ) && isset( $payload['error_code'] ) ? (string) $payload['error_code'] : '';
                if ( 'failed' === $phase || ( is_array( $payload ) && isset( $payload['error'] ) ) ) {
                    $this->release_pre_snapshot_handle( $website, (string) $job->pre_snapshot_task_id, (string) $job->id );
                    $this->record_failure( $job, $error, false, $error_code );
                } else {
                    // The child task is still running: a shapeless envelope is a
                    // transport hiccup. Keep polling within the attempt budget.
                    // No apostrophe in this string: it is escaped on the way in
                    // and the restore modal prints last_error as stored.
                    $this->record_failure( $job, esc_html__( 'The site did not send a readable answer about the safety backup. The backup may still be running, so MainWP will try again.', 'mainwp' ), true, '' );
                }
                return;
            }
        }
    }

    /**
     * Staging: make every plan artifact a verified local file, then
     * create the child restore task.
     *
     * @param object $job      Job row.
     * @param float  $deadline Slice deadline.
     */
    private function phase_staging( object $job, float $deadline ): void {
        $store      = MainWP_Backup_Restore_Job_Store::instance();
        $checkpoint = $job->checkpoint;
        $plan       = (array) $checkpoint['plan'];
        $driver     = $this->transfer_driver();

        // Cloud pull: when the destination can presign and the child can
        // download, the dashboard never touches the bytes - the child
        // task is created with a presigned manifest and the job goes
        // straight to running. Any gap (gate fails, manifest incomplete,
        // child rejects it, pull fails later) lands on the staged
        // transfer below instead.
        if ( empty( $job->child_task_id ) && $driver->can_use_cloud_pull( $this->website( $job ), $plan, $checkpoint ) ) {
            $pull_manifest = $driver->generate_pull_manifest( $plan );
            if ( null !== $pull_manifest ) {
                $checkpoint['plan'] = $plan; // Expected hashes filled from the upload records.
                $store->save_checkpoint( (int) $job->id, $checkpoint, $this->lock_token );

                $created = $this->create_child_restore_task( $job, $checkpoint, $plan, $pull_manifest );
                if ( '' === $created['task_id'] || ! empty( $created['reused'] ) ) {
                    return; // Reuse already reconciled the job; a failed create must also stop here.
                }

                if ( $created['pull_rejected'] ) {
                    // The child created the task but refused the
                    // manifest: stage and stream into that same task.
                    $checkpoint['cloud_pull_tried'] = true;
                    $store->save_checkpoint( (int) $job->id, $checkpoint, $this->lock_token );
                    if ( ! $store->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_STAGING, array( 'child_task_id' => $created['task_id'] ), $this->lock_token ) ) {
                        // Lease lost after the child task was created: its id
                        // never landed, so surface the orphan and stop.
                        do_action( 'mainwp_log_warning', sprintf( 'Restore job %d: the lease was lost after creating child restore task %s; it may need manual cleanup on the site.', (int) $job->id, $created['task_id'] ), (int) $job->site_id, 0 );
                        return;
                    }
                    do_action( 'mainwp_log_warning', sprintf( 'Restore job %d: the site declined the direct cloud download; using the standard transfer.', (int) $job->id ), (int) $job->site_id, 0 );
                    return;
                }

                if ( ! $store->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, array( 'child_task_id' => $created['task_id'] ), $this->lock_token ) ) {
                    // The lease was lost in the window between creating the
                    // child task and recording its id. Surface the orphaned
                    // child task and stop; the reclaiming worker owns the job.
                    do_action( 'mainwp_log_warning', sprintf( 'Restore job %d: the lease was lost after creating child restore task %s; it may need manual cleanup on the site.', (int) $job->id, $created['task_id'] ), (int) $job->site_id, 0 );
                    return;
                }
                $artifact_count = count( $pull_manifest['artifacts'] );
                do_action(
                    'mainwp_log_info',
                    1 === $artifact_count
                        ? sprintf( 'Restore job %d: the site downloads 1 backup file directly from cloud storage.', (int) $job->id )
                        : sprintf( 'Restore job %d: the site downloads %d backup files directly from cloud storage.', (int) $job->id, $artifact_count ),
                    (int) $job->site_id,
                    0
                );
                return;
            }
        }

        $needs_staging = false;
        foreach ( $plan as $entry ) {
            if ( empty( $entry['adopt_only'] ) ) {
                $needs_staging = true;
                break;
            }
        }

        if ( $needs_staging ) {
            $outcome            = MainWP_Backup_Restore_Transfer_Driver::instance()->stage_artifacts( (int) $job->id, $plan, $deadline );
            $checkpoint['plan'] = $plan;
            $store->save_checkpoint( (int) $job->id, $checkpoint, $this->lock_token );

            if ( null !== $outcome['error'] ) {
                $this->record_failure( $job, (string) $outcome['error'], false, isset( $outcome['error_code'] ) ? (string) $outcome['error_code'] : '' );
                return;
            }
            if ( ! $outcome['done'] ) {
                return; // More staging next tick.
            }
        }

        // A child task may already exist when a rejected pull manifest
        // routed this job back through staging.
        if ( ! empty( $job->child_task_id ) ) {
            $store->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING, array(), $this->lock_token );
            return;
        }

        $created = $this->create_child_restore_task( $job, $checkpoint, $plan );
        if ( '' === $created['task_id'] || ! empty( $created['reused'] ) ) {
            return; // Reuse already reconciled the job; a failed create must also stop here.
        }

        if ( ! $store->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING, array( 'child_task_id' => $created['task_id'] ), $this->lock_token ) ) {
            // Lease lost between creating the child task and recording its id.
            do_action( 'mainwp_log_warning', sprintf( 'Restore job %d: the lease was lost after creating child restore task %s; it may need manual cleanup on the site.', (int) $job->id, $created['task_id'] ), (int) $job->site_id, 0 );
            return;
        }
    }

    /**
     * The restore transfer driver (test seam).
     *
     * @return MainWP_Backup_Restore_Transfer_Driver
     */
    protected function transfer_driver(): MainWP_Backup_Restore_Transfer_Driver {
        return MainWP_Backup_Restore_Transfer_Driver::instance();
    }

    /**
     * Transferring: stream staged artifacts to the child.
     *
     * @param object $job      Job row.
     * @param float  $deadline Slice deadline.
     */
    private function phase_transferring( object $job, float $deadline ): void {
        $store         = MainWP_Backup_Restore_Job_Store::instance();
        $fresh         = $store->get_job( (int) $job->id );
        $still_driving = array(
            MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING,
            MainWP_Backup_Restore_Job_Store::STATUS_FAILED_RETRYABLE,
        );
        if ( null === $fresh || ! in_array( $fresh->status, $still_driving, true ) ) {
            return;
        }
        // A UI cancel may have arrived after run_job read its entry checkpoint.
        $job = $fresh;
        if ( ! empty( $job->checkpoint['cancel_intent'] ) ) {
            if ( self::TERMINAL_OUTCOME_DRIVE_ON !== $this->apply_cancel_intent( $job ) ) {
                return;
            }
            $job = $store->get_job( (int) $job->id );
            // If the child finished during cancel, record_terminal_failure() moved the row to running; artifact transfer must never resume.
            if ( null === $job || ! in_array( $job->status, $still_driving, true ) ) {
                return;
            }
        }

        $checkpoint = $job->checkpoint;
        $cursor     = isset( $checkpoint['transfer'] ) ? (array) $checkpoint['transfer'] : array();

        $outcome = MainWP_Backup_Restore_Transfer_Driver::instance()->transfer_artifacts(
            $this->website( $job ),
            (string) $job->child_task_id,
            (array) $checkpoint['plan'],
            $cursor,
            $deadline,
            function () use ( $store, $job, $still_driving ): bool {
                $fresh = $store->get_job( (int) $job->id );
                return null !== $fresh && empty( $fresh->checkpoint['cancel_intent'] ) && in_array( $fresh->status, $still_driving, true );
            },
            (int) $job->id
        );

        // Another lane may have taken over during the chunk round trip.
        $fresh = $store->get_job( (int) $job->id );
        if ( null === $fresh || ! in_array( $fresh->status, $still_driving, true ) ) {
            return;
        }

        $checkpoint             = $fresh->checkpoint;
        $checkpoint['transfer'] = $cursor;
        if ( ! empty( $outcome['restage'] ) ) {
            $checkpoint['plan'] = $outcome['plan'];
        }
        $store->save_checkpoint( (int) $job->id, $checkpoint, $this->lock_token );

        if ( ! empty( $fresh->checkpoint['cancel_intent'] ) ) {
            // Cancellation owns the outcome, including an already-completed child.
            $this->apply_cancel_intent( $fresh );
            return;
        }

        if ( null !== $outcome['error'] ) {
            $this->record_failure( $job, $outcome['error'], $outcome['retryable'], (string) ( $outcome['error_code'] ?? '' ) );
            return;
        }
        if ( ! empty( $outcome['restage'] ) ) {
            $store->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_STAGING, array(), $this->lock_token );
            return;
        }
        if ( $outcome['done'] ) {
            $store->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, array(), $this->lock_token );
        }
    }

    /**
     * Running: drive the child restore task within the slice budget.
     *
     * The child advances its restore one step per restore_task_run round
     * trip, so the poll loops until the child reaches a terminal phase or
     * the slice deadline passes - the same shape as the pre_snapshot,
     * staging, and transferring phases. Polling within the slice (rather
     * than one round trip per cron tick) is what lets a restore finish in
     * a single continuation: the post-slice continuation kick is a no-op
     * when fired from inside a cron run (WP-Cron holds its own lock), so a
     * once-per-tick poll would otherwise fall back to the 5-minute backstop
     * between child steps on a low-traffic dashboard.
     *
     * @param object $job      Job row.
     * @param float  $deadline Slice deadline.
     */
    private function phase_running( object $job, float $deadline ): void {
        $store   = MainWP_Backup_Restore_Job_Store::instance();
        $website = $this->website( $job );

        while ( microtime( true ) < $deadline ) {
            // A cancel recorded while the previous round trip was in
            // flight must stop the restore before the child is driven
            // again: re-read the row and apply the intent exactly as
            // run_job's entry check does. Working from the fresh row also
            // rebases this loop's checkpoint writes, so a slice that
            // outlasted a cancel can no longer resurrect its stale copy.
            $fresh         = $store->get_job( (int) $job->id );
            $still_driving = array(
                MainWP_Backup_Restore_Job_Store::STATUS_RUNNING,
                // A retry-resume drives the child while the row still says
                // failed_retryable; run_job mapped it here via resume_status.
                MainWP_Backup_Restore_Job_Store::STATUS_FAILED_RETRYABLE,
            );
            if ( null === $fresh || ! in_array( $fresh->status, $still_driving, true ) ) {
                return; // Cancelled, completed, or advanced by another lane.
            }
            $job = $fresh;
            if ( ! empty( $job->checkpoint['cancel_intent'] ) ) {
                if ( self::TERMINAL_OUTCOME_DRIVE_ON !== $this->apply_cancel_intent( $job ) ) {
                    return; // Cancelled, or the child is still busy.
                }
                // The child already completed its restore: the intent was
                // cleared, and the next round trip reports completed.
                $job = $store->get_job( (int) $job->id );
                if ( null === $job ) {
                    return;
                }
            }

            $diag_start = microtime( true );
            $payload    = MainWP_Connect::fetch_url_authed( $website, 'restore_task_run', array( 'task_id' => $job->child_task_id ), false, false, false );

            /**
             * Fires after each restore poll round trip with the full child
             * response, so the diagnostics recorder can capture the
             * non-terminal observations this loop otherwise discards.
             *
             * @param int   $job_id   Restore job id.
             * @param int   $site_id  Child site id.
             * @param array $payload  Full child response payload.
             * @param float $duration HTTP round-trip duration in seconds.
             */
            do_action( 'mainwp_backup_diag_restore_poll', (int) $job->id, (int) $job->site_id, is_array( $payload ) ? $payload : array(), microtime( true ) - $diag_start );

            // The child round trip may have outlasted the lease; a worker
            // that lost it stops here instead of writing over the new holder.
            if ( ! $this->lease_still_held( $job ) ) {
                return;
            }

            // A terminal failed phase carries an error key too - phase wins.
            if ( ! is_array( $payload ) || ( ! isset( $payload['phase'] ) && isset( $payload['error'] ) ) ) {
                $message    = is_array( $payload ) && isset( $payload['error'] ) ? (string) $payload['error'] : esc_html__( 'The child site returned no restore status.', 'mainwp' );
                $error_code = is_array( $payload ) && isset( $payload['error_code'] ) ? (string) $payload['error_code'] : '';
                $this->record_failure( $job, $message, true, $error_code );
                return;
            }

            $phase = isset( $payload['phase'] ) ? (string) $payload['phase'] : '';
            if ( 'completed' === $phase ) {
                $checkpoint                 = $job->checkpoint;
                $checkpoint['verification'] = isset( $payload['sub_state']['verification'] ) && is_array( $payload['sub_state']['verification'] ) ? $payload['sub_state']['verification'] : array();
                // Carry the order-preservation outcome (customers and stock
                // re-applied) alongside verification so the dashboard reports
                // what was kept rather than hiding it.
                if ( isset( $payload['sub_state']['progress']['woo_preserve'] ) ) {
                    $checkpoint['verification']['woo_preserve'] = $payload['sub_state']['progress']['woo_preserve'];
                }
                $store->save_checkpoint( (int) $job->id, $checkpoint, $this->lock_token );
                $store->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_VERIFYING, array(), $this->lock_token );
                return;
            }
            if ( 'failed' === $phase ) {
                $this->handle_failed_child_task( $job, $payload );
                return;
            }
            // Still working within this slice: poll the child again.
        }
        // Slice budget spent with the child still working: the next
        // continuation (or the periodic cron) polls again.
    }

    /**
     * Verifying: external reachability check, completion hook, cleanup.
     *
     * @param object $job Job row.
     */
    private function phase_verifying( object $job ): void {
        $store   = MainWP_Backup_Restore_Job_Store::instance();
        $website = $this->website( $job );

        $reachable = $this->site_responds( $website );

        $checkpoint                                  = $job->checkpoint;
        $checkpoint['verification']                  = isset( $checkpoint['verification'] ) && is_array( $checkpoint['verification'] ) ? $checkpoint['verification'] : array();
        $checkpoint['verification']['site_responds'] = $reachable;
        $store->save_checkpoint( (int) $job->id, $checkpoint, $this->lock_token );

        if ( ! $reachable ) {
            $this->record_failure( $job, esc_html__( 'The site did not respond after the restore. The restore completed on the child; check the site directly.', 'mainwp' ), true );
            return;
        }

        if ( ! $store->complete_job( (int) $job->id, $this->lock_token ) ) {
            // Already terminal, or this worker's lease was reclaimed - the
            // completion (and its event below) belongs to the live holder.
            return;
        }
        MainWP_Backup_Restore_Transfer_Driver::instance()->cleanup_staging( (int) $job->id );
        $this->prune_pre_snapshot( $job );

        /**
         * Fires after a restore completes and the site responds.
         *
         * The logs connector records the event.
         *
         * @param array $event Site, snapshot, job, and verification details.
         */
        do_action(
            'mainwp_backup_restored',
            array(
                'site_id'      => (int) $job->site_id,
                'snapshot_id'  => (int) $job->snapshot_id,
                'job_id'       => (int) $job->id,
                'verification' => $checkpoint['verification'],
            )
        );
    }

    /**
     * Create the child restore task from the staged plan.
     *
     * @param object     $job           Job row.
     * @param array      $checkpoint    Checkpoint.
     * @param array      $plan          Staged plan.
     * @param array|null $pull_manifest Presigned pull manifest, when the
     *                                  child downloads from cloud storage.
     *
     * @return array { task_id: string ('' after recording a failure),
     *                 pull_rejected: bool, reused?: bool }.
     */
    private function create_child_restore_task( object $job, array $checkpoint, array $plan, ?array $pull_manifest = null ): array {
        // Re-check the lease immediately before this irreversible side effect.
        // Staging can run long enough for the lease to expire and another lane
        // to reclaim the job; without this a lease-lost worker would create a
        // real child restore task the Dashboard job no longer owns (and would
        // not record, since the fenced writes afterward are refused), leaving
        // an orphaned runnable restore on the child. A lost lease returns no
        // task id so the caller stops and the reclaiming worker drives instead.
        if ( ! $this->lease_still_held( $job ) ) {
            return array(
                'task_id'       => '',
                'pull_rejected' => false,
            );
        }

        $options   = array(
            'sub_type'         => (string) $checkpoint['sub_type'],
            'has_foreign_keys' => null,
            'file_rollback'    => ! empty( $checkpoint['options']['file_rollback'] ) || ! empty( $checkpoint['pre_snapshot_artifacts'] ),
            'preserve_orders'  => ! empty( $checkpoint['options']['preserve_orders'] ),
            'woo_order_since'  => isset( $checkpoint['options']['woo_order_since'] ) ? (int) $checkpoint['options']['woo_order_since'] : 0,
        );
        $artifacts = array();
        foreach ( $plan as $entry ) {
            $artifacts[] = array(
                'role'       => $entry['role'],
                'file'       => $entry['file'],
                'size'       => ! empty( $entry['staged_path'] ) && file_exists( $entry['staged_path'] ) ? (int) filesize( $entry['staged_path'] ) : (int) $entry['size'],
                'sha256'     => (string) $entry['sha256'],
                'adopt_only' => ! empty( $entry['adopt_only'] ),
            );
            if ( 'db' !== $options['sub_type'] && 'manifest' === $entry['role'] && (int) $entry['snapshot_id'] === (int) $job->snapshot_id && isset( $entry['walk'] ) && is_array( $entry['walk'] ) ) {
                $options['reconcile'] = array(
                    'manifest' => $entry['file'],
                    'walk'     => $entry['walk'],
                );
            }
        }

        $params = array(
            'restore_id'     => (string) $checkpoint['restore_id'],
            'source_prefix'  => '',
            'artifacts_json' => wp_json_encode( $artifacts ),
            'options_json'   => wp_json_encode( $options ),
        );
        if ( null !== $pull_manifest ) {
            $params['pull_manifest_json'] = wp_json_encode( $pull_manifest );
        }

        $website = $this->website( $job );
        $payload = MainWP_Connect::fetch_url_authed( $website, 'restore_task_create', $params, false, false, false );

        if ( ! is_array( $payload ) || empty( $payload['task_id'] ) ) {
            $message    = is_array( $payload ) && isset( $payload['error'] ) ? (string) $payload['error'] : esc_html__( 'The child site did not accept the restore task.', 'mainwp' );
            $error_code = is_array( $payload ) && isset( $payload['error_code'] ) ? (string) $payload['error_code'] : '';
            $retryable  = in_array( $error_code, array( MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING, MainWP_Backup_Error_Codes::ERR_MUTEX_WRITE_FAILED ), true );
            $this->record_failure( $job, $message, $retryable, $error_code );
            return array(
                'task_id'       => '',
                'pull_rejected' => false,
            );
        }

        $task_id = sanitize_text_field( (string) $payload['task_id'] );
        $reused  = ! empty( $payload['reused'] );
        if ( $reused ) {
            $store  = MainWP_Backup_Restore_Job_Store::instance();
            $phase  = isset( $payload['phase'] ) ? (string) $payload['phase'] : '';
            $status = MainWP_Backup_Restore_Job_Store::STATUS_RUNNING;
            if ( 'receiving' === $phase || ( 'queued' === $phase && empty( $payload['sub_state']['params']['pull_manifest'] ) ) ) {
                $status = null !== $pull_manifest ? MainWP_Backup_Restore_Job_Store::STATUS_STAGING : MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING;
            } elseif ( 'completed' === $phase ) {
                $status                     = MainWP_Backup_Restore_Job_Store::STATUS_VERIFYING;
                $checkpoint['verification'] = isset( $payload['sub_state']['verification'] ) && is_array( $payload['sub_state']['verification'] ) ? $payload['sub_state']['verification'] : array();
                if ( isset( $payload['sub_state']['progress']['woo_preserve'] ) ) {
                    $checkpoint['verification']['woo_preserve'] = $payload['sub_state']['progress']['woo_preserve'];
                }
                if ( ! $store->save_checkpoint( (int) $job->id, $checkpoint, $this->lock_token ) ) {
                    return array(
                        'task_id'       => '',
                        'pull_rejected' => false,
                    );
                }
            }
            if ( 'failed' === $phase ) {
                if ( ! $this->handle_failed_child_task( $job, $payload, $task_id, $checkpoint ) ) {
                    return array(
                        'task_id'       => '',
                        'pull_rejected' => false,
                    );
                }
            } elseif ( ! $store->set_status( (int) $job->id, $status, array( 'child_task_id' => $task_id ), $this->lock_token ) ) {
                return array(
                    'task_id'       => '',
                    'pull_rejected' => false,
                );
            }
        }

        return array(
            'task_id'       => $task_id,
            'pull_rejected' => ! empty( $payload['pull_manifest_rejected'] ),
            'reused'        => $reused,
        );
    }

    /**
     * Handle a failed child task, falling back from cloud pull once.
     *
     * @param object     $job        Restore job.
     * @param array      $payload    Child response.
     * @param string     $task_id    Reused handle to retain if no fallback is needed.
     * @param array|null $checkpoint Live checkpoint, or null to use the job row.
     *
     * @return bool Whether the handle can be returned after recording failure.
     */
    private function handle_failed_child_task( object $job, array $payload, string $task_id = '', ?array $checkpoint = null ): bool {
        $store      = MainWP_Backup_Restore_Job_Store::instance();
        $error_code = isset( $payload['error_code'] ) ? (string) $payload['error_code'] : '';
        if ( '' === $error_code && isset( $payload['sub_state']['error']['code'] ) ) {
            $error_code = (string) $payload['sub_state']['error']['code'];
        }

        // A failed cloud pull never fails the restore: the job goes
        // back through staging once, taking the standard transfer.
        if ( 'mainwp_cloud_pull_failed' === $error_code && empty( $job->checkpoint['cloud_pull_tried'] ) ) {
            $checkpoint                     = $checkpoint ?? $job->checkpoint;
            $checkpoint['cloud_pull_tried'] = true;
            $checkpoint['transfer']         = array();
            // The child pins each restore_id to its first task for replay safety; the fallback needs its own identity.
            $checkpoint['restore_id'] = strtolower( wp_generate_password( 12, false ) );
            if ( ! $store->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_STAGING, array( 'child_task_id' => '' ), $this->lock_token ) ) {
                return false;
            }
            if ( ! $store->save_checkpoint( (int) $job->id, $checkpoint, $this->lock_token ) ) {
                return false;
            }
            do_action( 'mainwp_log_warning', sprintf( 'Restore job %d: the direct cloud download failed on the site; retrying with the standard transfer.', (int) $job->id ), (int) $job->site_id, 0 );
            return false;
        }

        $error = isset( $payload['error'] ) ? (string) $payload['error'] : esc_html__( 'The restore failed on the child site.', 'mainwp' );
        if ( isset( $payload['sub_state']['error']['message'] ) ) {
            $error = (string) $payload['sub_state']['error']['message'];
        }
        if ( '' !== $task_id && ! $store->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, array( 'child_task_id' => $task_id ), $this->lock_token ) ) {
            return false;
        }
        $this->record_failure( $job, $error, false, $error_code );
        return true;
    }

    /**
     * Record terminal failure before releasing ownership and publishing the event.
     *
     * Cancellation decision matrix (rows apply in order):
     * failed_retryable uses resume_status (pending when absent).
     * A child task can still restore in staging after a rejected pull manifest,
     * and in transferring/running. Pending, preflight and pre_snapshot precede
     * restore-task creation; verifying follows confirmed child completion.
     * Terminal rows cannot drive work. A missing child task needs no request.
     *
     * Child can restore?            Child answer       Decision
     * terminal row                  not requested      retain terminal verdict
     * advanced in flight            any reply          retain verification or terminal verdict
     * verifying, stored failure     not requested      clear pending decision; poll completion
     * verifying, no stored failure  not requested      terminalize fresh failure; otherwise drive on
     * no                            not requested      terminalize failure or cancellation
     * yes                           ok/failed          terminalize failure or cancellation
     * yes                           completed          clear pending decision; poll completion
     * yes                           busy/rolling_back  defer until 15 minutes have elapsed
     * yes                           unconfirmed        defer until the same elapsed-time limit
     * yes                           any deferred       at timeout, terminalize with warning
     *
     * Durability, crossed with every row above:
     * - A stored terminal_failure wins over a later error. An authoritative
     *   completed child outranks both because the child knows the restore happened.
     * - Without an incoming or stored failure, cancel_intent means user
     *   cancellation. A retry record supplies elapsed time, not a verdict.
     *   Missing retry records start now.
     * - Intent, original failure and retry time must be durable before the child
     *   round trip, so a new lease holder inherits the gate while it is in flight.
     * - A refused combined write defers without a request or cleanup; arm intent
     *   and original failure separately before returning. Existing intent is sticky.
     * - A refused terminal/drive-on write retains ownership and staging.
     * - Only a successful terminal write releases the safety-backup handle and
     *   cleans staging. Defer, drive on and already terminal do neither;
     *   phase_verifying() and the UI cancel lane obey the same ordering.
     *
     * A thrown or unreadable reply is unconfirmed, not proof the child stopped.
     * Verification never consults it because child completion settled that already.
     * Poll frequency must not shorten the rollback window or replace the cause.
     *
     * Returns: terminal for an absent, already terminal or foreign-lease row,
     * or a row that reached verification or a terminal status during the request,
     * and for a successful terminal write or an unfenced terminal refusal.
     * Deferred covers refused decision writes, replies within the timeout,
     * refused completion checkpoint writes and fenced terminal refusals.
     * Drive_on means the completion checkpoint cleared successfully.
     *
     * @param object      $job        Job row.
     * @param string|null $error      Error text, or null for a user cancellation.
     * @param string      $error_code Stable error code, or empty when none.
     * @param string|null $lock_token Worker lease token, or null for an expired-lease sweep.
     *
     * @return string One of the TERMINAL_OUTCOME_* constants.
     */
    private function record_terminal_failure( object $job, ?string $error, string $error_code, ?string $lock_token ): string {
        $store = MainWP_Backup_Restore_Job_Store::instance();
        $job   = $store->get_job( (int) $job->id );
        if ( null === $job || ! in_array( $job->status, $store::active_statuses(), true ) || ( null !== $lock_token && $job->locked_by !== $lock_token ) ) {
            return self::TERMINAL_OUTCOME_TERMINAL;
        }

        $failure            = $job->checkpoint['terminal_failure'] ?? array();
        $had_stored_failure = ! empty( $failure );
        $error              = $failure['error'] ?? $error;
        $error_code         = $failure['error_code'] ?? $error_code;
        $phase              = $store::STATUS_FAILED_RETRYABLE === $job->status ? ( $job->checkpoint['resume_status'] ?? $store::STATUS_PENDING ) : $job->status;
        $can_cancel         = ! empty( $job->child_task_id ) && in_array( $phase, array( $store::STATUS_STAGING, $store::STATUS_TRANSFERRING, $store::STATUS_RUNNING ), true );
        $outcome            = $store::STATUS_VERIFYING === $phase ? 'completed' : 'ok';
        if ( $can_cancel ) {
            $deferred = $store->defer_terminal_failure( (int) $job->id, $error, $error_code, $lock_token );
            if ( null === $deferred ) {
                // A failed combined write must not let the next worker resume a restore slice.
                $failure = null === $error ? array() : array(
                    'error'      => $error,
                    'error_code' => $error_code,
                );
                if ( ! $store->arm_cancel_intent( (int) $job->id, $failure, $lock_token ) ) {
                    do_action( 'mainwp_log_info', sprintf( 'Restore job %d: cancellation intent could not be persisted.', (int) $job->id ), (int) $job->site_id, 0 );
                }
                return self::TERMINAL_OUTCOME_DEFERRED;
            }
            $outcome = 'unconfirmed';
            try {
                $outcome = $this->request_child_cancel( $job );
            } catch ( \Throwable $e ) {
                do_action( 'mainwp_log_info', sprintf( 'Restore job %d: the site did not confirm cancelling its child task: %s', (int) $job->id, $e->getMessage() ), (int) $job->site_id, 0 );
            }
            $fresh = $store->get_job( (int) $job->id );
            if ( null === $fresh || ! in_array( $fresh->status, $store::active_statuses(), true ) || $store::STATUS_VERIFYING === $fresh->status ) {
                return self::TERMINAL_OUTCOME_TERMINAL;
            }
        }

        if ( in_array( $outcome, array( 'busy', 'rolling_back', 'unconfirmed' ), true ) ) {
            if ( time() - $deferred['first_attempt_at'] < $store::CANCEL_CONFIRMATION_TIMEOUT_SECONDS ) {
                return self::TERMINAL_OUTCOME_DEFERRED;
            }
            $warning    = esc_html__( 'The child site did not confirm that it stopped restoring. The site may need manual cleanup.', 'mainwp' );
            $error_code = null === $error ? 'mainwp_restore_cancel_unconfirmed' : $error_code;
            $error      = null === $error ? $warning : $error . ' ' . $warning;
        }

        if ( 'completed' === $outcome && ( $can_cancel || null === $error || $had_stored_failure ) ) {
            // Preserve verification and never resume artifact transfer after child completion.
            if ( $can_cancel && ! $store->set_status( (int) $job->id, $store::STATUS_RUNNING, array(), $lock_token ) ) {
                return self::TERMINAL_OUTCOME_DEFERRED;
            }
            $fresh = $store->get_job( (int) $job->id );
            if ( null === $fresh ) {
                return self::TERMINAL_OUTCOME_TERMINAL;
            }
            $checkpoint = $fresh->checkpoint;
            unset( $checkpoint['cancel_confirmation'], $checkpoint['terminal_failure'], $checkpoint['last_error_code'] );
            return $store->save_checkpoint( (int) $job->id, $checkpoint, $lock_token, true ) ? self::TERMINAL_OUTCOME_DRIVE_ON : self::TERMINAL_OUTCOME_DEFERRED;
        }

        if ( null === $error ) {
            if ( $store->cancel_job( (int) $job->id, $lock_token ) ) {
                MainWP_Backup_Restore_Transfer_Driver::instance()->cleanup_staging( (int) $job->id );
                $this->cancel_owned_pre_snapshot_handle( $job );
                return self::TERMINAL_OUTCOME_TERMINAL;
            }
            return null === $lock_token ? self::TERMINAL_OUTCOME_TERMINAL : self::TERMINAL_OUTCOME_DEFERRED;
        }

        $this->stamp_error_code( $job, $error_code, $lock_token );
        if ( ! $store->fail_permanent( (int) $job->id, $error, $lock_token ) ) {
            return null === $lock_token ? self::TERMINAL_OUTCOME_TERMINAL : self::TERMINAL_OUTCOME_DEFERRED;
        }

        MainWP_Backup_Restore_Transfer_Driver::instance()->cleanup_staging( (int) $job->id );
        // The owner is terminal and no other lane may release its handle.
        $website = MainWP_DB::instance()->get_website_by_id( (int) $job->site_id );
        // A deleted site can leave its job behind. Throwing here inside a
        // catch block would prevent the failure event below from firing.
        if ( ! empty( $job->pre_snapshot_task_id ) && ! empty( $website ) ) {
            $this->release_pre_snapshot_handle( $website, (string) $job->pre_snapshot_task_id, (string) $job->id );
        }

        /**
         * Fires after a restore fails permanently (not retryable, or
         * retries exhausted).
         *
         * The logs connector records the event.
         *
         * @param array $event Site, snapshot, job, and error details.
         */
        do_action(
            'mainwp_backup_restore_failed',
            array(
                'site_id'     => (int) $job->site_id,
                'snapshot_id' => (int) $job->snapshot_id,
                'job_id'      => (int) $job->id,
                'error'       => $error,
                'error_code'  => $error_code,
            )
        );
        return self::TERMINAL_OUTCOME_TERMINAL;
    }

    /**
     * Record a failure with backoff; exhausted attempts go permanent.
     *
     * The current status is saved as the resume point so a retry
     * continues the phase that failed instead of starting over.
     *
     * @param object $job        Job row.
     * @param string $error      Error text.
     * @param bool   $retryable  Whether a retry can succeed.
     * @param string $error_code Stable error code matching $error, or '' when none.
     */
    private function record_failure( object $job, string $error, bool $retryable, string $error_code = '' ): void {
        $store = MainWP_Backup_Restore_Job_Store::instance();
        $error = $this->humanize_stored_error( $error );

        // The phase that failed may have checkpointed progress after this
        // row was read; a stale write here would erase that cursor.
        $fresh      = $store->get_job( (int) $job->id );
        $checkpoint = null !== $fresh ? $fresh->checkpoint : $job->checkpoint;

        // A failure that follows real transfer progress starts a fresh
        // attempt budget: MAX_ATTEMPTS bounds consecutive no-progress
        // failures, not the total hiccups a large transfer on a flaky
        // host rides through while still moving forward.
        $advanced = $this->transfer_advanced_since_last_failure( $checkpoint );
        $attempts = $advanced ? 1 : (int) $job->attempt_count + 1;

        if ( ! $retryable || MainWP_Backup_Backoff::attempts_exhausted( $attempts ) ) {
            $this->record_terminal_failure( $job, $error, $error_code, $this->lock_token );

            return;
        }

        $checkpoint['resume_status'] = MainWP_Backup_Restore_Job_Store::STATUS_FAILED_RETRYABLE === $job->status && isset( $checkpoint['resume_status'] )
            ? (string) $checkpoint['resume_status']
            : (string) $job->status;
        // Keep the code in step with last_error so the progress poll can carry
        // a stable machine-readable code for support and AI diagnosis.
        $checkpoint['last_error_code']       = $error_code;
        $checkpoint['last_failure_transfer'] = $this->transfer_position( $checkpoint );
        $store->save_checkpoint( (int) $job->id, $checkpoint, $this->lock_token );

        $next_retry_at = time() + MainWP_Backup_Backoff::delay_for_attempt( $attempts );
        if ( ! $store->fail_retryable( (int) $job->id, $error, $attempts, $next_retry_at, $this->lock_token ) ) {
            // The lease was reclaimed; the live holder owns the retry
            // bookkeeping, so the diag event below is not emitted here.
            return;
        }

        /**
         * Fires after a restore failure is recorded as retryable, so the
         * diagnostics recorder can capture attempt boundaries instead of
         * leaving them to be inferred from stream gaps.
         *
         * @param int    $job_id         Restore job id.
         * @param int    $site_id        Child site id.
         * @param int    $attempts       Attempt count after this failure.
         * @param string $error_code     Stable error code, '' when none.
         * @param int    $next_retry_at  Epoch the backoff releases the job.
         * @param bool   $progress_reset Whether transfer progress reset the budget.
         */
        do_action( 'mainwp_backup_diag_restore_retry', (int) $job->id, (int) $job->site_id, $attempts, $error_code, $next_retry_at, $advanced );
    }

    /**
     * Replace the connect layer's raw token messages with sentences a
     * person can act on. Every stored last_error funnels through
     * record_failure, so this is the single choke point - transfer
     * failures reach it through send_chunk's catch and never touch the
     * process_pending catch. Diagnostics events keep the raw detail; only
     * the stored, user-facing message is rewritten.
     *
     * @param string $error Raw error text.
     *
     * @return string Humanized error text.
     */
    private function humanize_stored_error( string $error ): string {
        if ( 0 === strpos( $error, 'HTTPERROR' ) ) {
            return esc_html__( 'The site could not be reached during the restore. It may be down, overloaded, or briefly blocking dashboard requests; the restore retries automatically.', 'mainwp' );
        }
        if ( 0 === strpos( $error, 'NOMAINWP' ) ) {
            return esc_html__( 'The MainWP Child plugin did not answer on the site. Check that the site loads and the MainWP Child plugin is active; the restore retries automatically.', 'mainwp' );
        }
        if ( 0 === strpos( $error, 'WPERROR' ) ) {
            return esc_html__( 'The site returned an unexpected response during the restore; the restore retries automatically.', 'mainwp' );
        }
        return $error;
    }

    /**
     * The transfer cursor's position as an (artifact_index, offset) pair.
     *
     * @param array $checkpoint Job checkpoint.
     *
     * @return array { artifact_index: int, offset: int }
     */
    private function transfer_position( array $checkpoint ): array {
        $cursor = isset( $checkpoint['transfer'] ) ? (array) $checkpoint['transfer'] : array();
        return array(
            'artifact_index' => isset( $cursor['artifact_index'] ) ? (int) $cursor['artifact_index'] : 0,
            'offset'         => isset( $cursor['offset'] ) ? (int) $cursor['offset'] : 0,
        );
    }

    /**
     * Whether the transfer cursor moved forward since the last recorded
     * failure. Compared as an (artifact_index, offset) tuple because the
     * offset resets to zero when the index advances. Phases that never
     * touch the cursor leave it equal to the watermark, so they keep the
     * plain counting behavior.
     *
     * @param array $checkpoint Job checkpoint.
     *
     * @return bool
     */
    private function transfer_advanced_since_last_failure( array $checkpoint ): bool {
        if ( ! isset( $checkpoint['last_failure_transfer'] ) ) {
            return false;
        }
        $mark        = (array) $checkpoint['last_failure_transfer'];
        $mark_index  = isset( $mark['artifact_index'] ) ? (int) $mark['artifact_index'] : 0;
        $mark_offset = isset( $mark['offset'] ) ? (int) $mark['offset'] : 0;
        $now         = $this->transfer_position( $checkpoint );

        return $now['artifact_index'] > $mark_index
            || ( $now['artifact_index'] === $mark_index && $now['offset'] > $mark_offset );
    }

    /**
     * Persist the error code that matches the job's current last_error.
     *
     * Rides in the checkpoint JSON so the restore progress poll can report a
     * stable code without a schema change. Reads the row fresh so a concurrent
     * checkpoint from the failed phase is not clobbered.
     *
     * @param object      $job        Job row.
     * @param string      $error_code Error code, or '' when the failure carried none.
     * @param string|null $lock_token Worker lease token, or null for an expired-lease sweep.
     */
    private function stamp_error_code( object $job, string $error_code, ?string $lock_token ): void {
        $store                         = MainWP_Backup_Restore_Job_Store::instance();
        $fresh                         = $store->get_job( (int) $job->id );
        $checkpoint                    = null !== $fresh ? $fresh->checkpoint : $job->checkpoint;
        $checkpoint['last_error_code'] = $error_code;
        $store->save_checkpoint( (int) $job->id, $checkpoint, $lock_token );
    }

    /**
     * Best-effort retirement of the pre-restore safety backup after a
     * verified restore.
     *
     * @param object $job Job row.
     */
    private function prune_pre_snapshot( object $job ): void {
        if ( empty( $job->pre_snapshot_task_id ) ) {
            return;
        }
        try {
            $website = $this->website( $job );
            MainWP_Connect::fetch_url_authed(
                $website,
                'backup_task_prune',
                array(
                    'task_ids' => wp_json_encode( array( (string) $job->pre_snapshot_task_id ) ),
                    'sweep'    => 0,
                ),
                false,
                false,
                false
            );
        } catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- best-effort cleanup never blocks completion.
            return;
        }
    }

    /**
     * External reachability check (test seam).
     *
     * This is not a bare liveness ping: phase_verifying() completes the
     * restore on a true and permanently fails it on a false, so the answer
     * is the restore's verdict. Certificate verification therefore follows
     * the site's own verify_certificate setting (fetch_url() semantics,
     * verification on by default) instead of being switched off: a restore
     * that left the site serving a broken certificate must not be reported
     * as verified.
     *
     * @param object $website Website object.
     *
     * @return bool True when the site answers with a non-server-error.
     */
    protected function site_responds( object $website ): bool {
        $response = wp_remote_get(
            $website->url,
            array(
                'timeout'   => 15,
                'sslverify' => static::ssl_verify_for( $website ),
            )
        );
        if ( is_wp_error( $response ) ) {
            return false;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        return $code >= 200 && $code < 500;
    }

    /**
     * Resolve the sslverify argument for a site, mirroring fetch_url()
     * semantics: 1 verifies, 0 does not, and 2 (or an unset value) defers to
     * the dashboard-wide mainwp_sslVerifyCertificate option, which verifies
     * unless it was explicitly turned off.
     *
     * @param object $website Website object.
     *
     * @return bool Whether to verify the certificate.
     */
    protected static function ssl_verify_for( object $website ): bool {
        $verify = isset( $website->verify_certificate ) ? (int) $website->verify_certificate : 2;
        if ( 1 === $verify ) {
            return true;
        }
        if ( 0 === $verify ) {
            return false;
        }
        return false === get_option( 'mainwp_sslVerifyCertificate' ) || 1 === (int) get_option( 'mainwp_sslVerifyCertificate' );
    }

    /**
     * Resolve the job's website row.
     *
     * @param object $job Job row.
     *
     * @throws MainWP_Exception When the site no longer exists.
     *
     * @return object Website.
     */
    private function website( object $job ): object {
        $website = MainWP_DB::instance()->get_website_by_id( (int) $job->site_id );
        if ( empty( $website ) ) {
            throw new MainWP_Exception( esc_html__( 'Site not found.', 'mainwp' ) );
        }
        return $website;
    }
}
