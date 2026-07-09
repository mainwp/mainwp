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

    /**
     * Singleton.
     *
     * @var MainWP_Backup_Restore_Orchestrator|null
     */
    private static $instance = null;

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
        $site_id = (int) $failed_job->site_id;
        if ( null !== MainWP_Backup_Restore_Job_Store::instance()->get_active_job_for_site( $site_id ) ) {
            throw new MainWP_Exception( esc_html__( 'A restore is already running for this site. Wait for it to finish before rolling back.', 'mainwp' ) );
        }

        $artifacts = isset( $failed_job->checkpoint['pre_snapshot_artifacts'] ) ? (array) $failed_job->checkpoint['pre_snapshot_artifacts'] : array();
        if ( empty( $artifacts ) ) {
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
            'restore_id' => strtolower( wp_generate_password( 12, false ) ),
            'sub_type'   => 'full',
            'plan'       => $plan,
            'options'    => array(
                'pre_snapshot'  => false,
                'file_rollback' => false,
            ),
            'transfer'   => array(),
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
     * Cancel a restore job.
     *
     * Before the child task starts the cancel is dashboard-only. While
     * the child task is running, the child is asked to cancel first and
     * the job is only marked cancelled once the child confirmed - so a
     * cancelled job never hides a still-running child task. A child
     * mid-slice answers busy; instead of blocking the request on
     * retries, the cancel is recorded as an intent the cron lane
     * applies between slices. Once the dashboard-side verify began the
     * child's work is done and the offer becomes a rollback instead.
     *
     * @param int $job_id Job id.
     *
     * @throws MainWP_Exception When the job is past the cancel window,
     *                          or the child could not confirm the cancel.
     *
     * @return string 'cancelled' when the job was cancelled, or
     *                'cancel_pending' when the busy child deferred the
     *                cancel to the cron lane.
     */
    public function cancel_job( int $job_id ): string {
        $store = MainWP_Backup_Restore_Job_Store::instance();
        $job   = $store->get_job( $job_id );
        if ( null === $job ) {
            throw new MainWP_Exception( esc_html__( 'Restore job not found.', 'mainwp' ) );
        }

        if ( MainWP_Backup_Restore_Job_Store::STATUS_RUNNING === $job->status ) {
            $outcome = $this->request_child_cancel( $job );

            if ( 'completed' === $outcome ) {
                throw new MainWP_Exception( esc_html__( 'The restore already finished on the site and can no longer be cancelled.', 'mainwp' ) );
            }
            if ( 'busy' === $outcome ) {
                $this->record_cancel_intent( $job_id );
                return 'cancel_pending';
            }

            // The cron lane may have advanced the job while the child
            // confirmation was in flight; a job that reached verifying or
            // completed meanwhile finished its restore and must never be
            // relabeled cancelled.
            $fresh    = $store->get_job( $job_id );
            $finished = array(
                MainWP_Backup_Restore_Job_Store::STATUS_VERIFYING,
                MainWP_Backup_Restore_Job_Store::STATUS_COMPLETED,
            );
            if ( null === $fresh || in_array( $fresh->status, $finished, true ) ) {
                throw new MainWP_Exception( esc_html__( 'The restore already finished on the site and can no longer be cancelled.', 'mainwp' ) );
            }

            MainWP_Backup_Restore_Transfer_Driver::instance()->cleanup_staging( $job_id );
            if ( ! $store->cancel_job( $job_id ) ) {
                throw new MainWP_Exception( esc_html__( 'The restore reached its final state while the cancellation was confirmed and no longer needs cancelling.', 'mainwp' ) );
            }
            return 'cancelled';
        }

        $cancellable = array(
            MainWP_Backup_Restore_Job_Store::STATUS_PENDING,
            MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT,
            MainWP_Backup_Restore_Job_Store::STATUS_STAGING,
            MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING,
            MainWP_Backup_Restore_Job_Store::STATUS_FAILED_RETRYABLE,
        );
        if ( ! in_array( $job->status, $cancellable, true ) ) {
            throw new MainWP_Exception( esc_html__( 'The restore finished its work on the site and can no longer be cancelled. Use the rollback once it completes or fails.', 'mainwp' ) );
        }

        // A restore in the transferring phase already created a child task to
        // receive chunks. Ask the child to drop it so a cancelled job does not
        // leave a half-streamed task open. This is best-effort: the dashboard
        // cancels the job regardless of how the child answers.
        if ( MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING === $job->status && ! empty( $job->child_task_id ) ) {
            try {
                $this->request_child_cancel( $job );
            } catch ( \Throwable $e ) {
                do_action( 'mainwp_log_info', sprintf( 'Restore job %d: the site did not confirm cancelling its transfer task: %s', (int) $job->id, $e->getMessage() ) );
            }
        }

        MainWP_Backup_Restore_Transfer_Driver::instance()->cleanup_staging( $job_id );
        if ( ! $store->cancel_job( $job_id ) ) {
            throw new MainWP_Exception( esc_html__( 'The restore reached its final state while the cancellation was confirmed and no longer needs cancelling.', 'mainwp' ) );
        }
        return 'cancelled';
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
     *                to cancel), 'completed' (restore happened), or
     *                'busy' (slice in flight).
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
        if ( 'busy' === $result ) {
            return 'busy';
        }

        $message = is_array( $payload ) && isset( $payload['error'] ) ? (string) $payload['error'] : esc_html__( 'The child site did not confirm the cancellation.', 'mainwp' );
        throw new MainWP_Exception( esc_html( $message ) );
    }

    /**
     * Record the cancel intent on the job's checkpoint for the cron
     * lane to apply between child slices.
     *
     * The checkpoint is re-read fresh so an in-flight phase write is
     * not clobbered. A job that left the running window meanwhile
     * finished its restore and refuses, mirroring the synchronous path.
     *
     * @param int $job_id Job id.
     *
     * @throws MainWP_Exception When the job finished meanwhile.
     */
    private function record_cancel_intent( int $job_id ): void {
        $store = MainWP_Backup_Restore_Job_Store::instance();
        $fresh = $store->get_job( $job_id );

        $intent_holders = array(
            MainWP_Backup_Restore_Job_Store::STATUS_RUNNING,
            MainWP_Backup_Restore_Job_Store::STATUS_FAILED_RETRYABLE,
        );
        if ( null === $fresh || ! in_array( $fresh->status, $intent_holders, true ) ) {
            throw new MainWP_Exception( esc_html__( 'The restore already finished on the site and can no longer be cancelled.', 'mainwp' ) );
        }

        $checkpoint                  = $fresh->checkpoint;
        $checkpoint['cancel_intent'] = true;
        $store->save_checkpoint( $job_id, $checkpoint );
    }

    /**
     * Apply a recorded cancel intent before driving the child.
     *
     * @param object $job Job row.
     *
     * @return bool True when the child already completed its restore -
     *              the intent clears and the normal completion path
     *              takes over. False when the job was cancelled or the
     *              child is still busy (nothing to drive this tick).
     */
    private function apply_cancel_intent( object $job ): bool {
        $store   = MainWP_Backup_Restore_Job_Store::instance();
        $outcome = $this->request_child_cancel( $job );

        if ( 'busy' === $outcome ) {
            return false; // The intent stays; the next tick retries.
        }

        if ( 'completed' === $outcome ) {
            $fresh = $store->get_job( (int) $job->id );
            if ( null !== $fresh ) {
                $checkpoint = $fresh->checkpoint;
                unset( $checkpoint['cancel_intent'] );
                $store->save_checkpoint( (int) $job->id, $checkpoint );
            }
            return true;
        }

        // ok / already failed: the child confirmed - cancel the job.
        MainWP_Backup_Restore_Transfer_Driver::instance()->cleanup_staging( (int) $job->id );
        $store->cancel_job( (int) $job->id );
        return false;
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

        $job = $store->acquire_next_job( $token, $budget_seconds + 60, null, $only_job_id );
        if ( null === $job ) {
            return;
        }
        $job_id = (int) $job->id;

        try {
            while ( true ) {
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
            $store->release_lock( $job_id, $token );
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
        if ( MainWP_Backup_Restore_Job_Store::STATUS_RUNNING === $status && ! empty( $job->checkpoint['cancel_intent'] ) ) {
            if ( ! $this->apply_cancel_intent( $job ) ) {
                return; // Cancelled, or the child is still busy.
            }
            // The child completed its restore: the intent was cleared on
            // the row, so the stale copy must not be written back below.
            $job = MainWP_Backup_Restore_Job_Store::instance()->get_job( (int) $job->id );
            if ( null === $job ) {
                return;
            }
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
        MainWP_Backup_Restore_Job_Store::instance()->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_PREFLIGHT );
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
            $task_id = MainWP_Backup_Executor::trigger_backup( (int) $job->site_id, 'full' );
            if ( null === $task_id ) {
                // Synchronous fallback children completed the backup inline.
                $store->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_STAGING );
                return;
            }
            $store->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT, array( 'pre_snapshot_task_id' => $task_id ) );
            return;
        }

        $store->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_STAGING );
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
                $store->save_checkpoint( (int) $job->id, $checkpoint );

                // Release the ad-hoc handle: a completed safety backup left
                // persisted would short-circuit the NEXT restore's
                // trigger_backup() into reusing this stale task - and a
                // later success would prune an artifact another job still
                // counts on for rollback.
                MainWP_DB::instance()->update_website_option( $website, MainWP_Backup_Executor::ADHOC_TASK_OPTION, '' );

                $store->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_STAGING );
                return;
            }
            if ( 'failed' === $phase || '' === $phase ) {
                $error      = is_array( $payload ) && isset( $payload['error'] ) ? (string) $payload['error'] : esc_html__( 'The pre-restore safety backup failed.', 'mainwp' );
                $error_code = is_array( $payload ) && isset( $payload['error_code'] ) ? (string) $payload['error_code'] : '';
                $this->record_failure( $job, $error, false, $error_code );
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
                $store->save_checkpoint( (int) $job->id, $checkpoint );

                $created = $this->create_child_restore_task( $job, $checkpoint, $plan, $pull_manifest );
                if ( '' === $created['task_id'] ) {
                    return; // Failure already recorded.
                }

                if ( $created['pull_rejected'] ) {
                    // The child created the task but refused the
                    // manifest: stage and stream into that same task.
                    $checkpoint['cloud_pull_tried'] = true;
                    $store->save_checkpoint( (int) $job->id, $checkpoint );
                    $store->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_STAGING, array( 'child_task_id' => $created['task_id'] ) );
                    do_action( 'mainwp_log_warning', sprintf( 'Restore job %d: the site declined the direct cloud download; using the standard transfer.', (int) $job->id ) );
                    return;
                }

                $store->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, array( 'child_task_id' => $created['task_id'] ) );
                $artifact_count = count( $pull_manifest['artifacts'] );
                do_action(
                    'mainwp_log_info',
                    1 === $artifact_count
                        ? sprintf( 'Restore job %d: the site downloads 1 backup file directly from cloud storage.', (int) $job->id )
                        : sprintf( 'Restore job %d: the site downloads %d backup files directly from cloud storage.', (int) $job->id, $artifact_count )
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
            $store->save_checkpoint( (int) $job->id, $checkpoint );

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
            $store->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING );
            return;
        }

        $created = $this->create_child_restore_task( $job, $checkpoint, $plan );
        if ( '' === $created['task_id'] ) {
            return; // Failure already recorded.
        }

        $store->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING, array( 'child_task_id' => $created['task_id'] ) );
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
        $store      = MainWP_Backup_Restore_Job_Store::instance();
        $checkpoint = $job->checkpoint;
        $cursor     = isset( $checkpoint['transfer'] ) ? (array) $checkpoint['transfer'] : array();

        $outcome = MainWP_Backup_Restore_Transfer_Driver::instance()->transfer_artifacts(
            $this->website( $job ),
            (string) $job->child_task_id,
            (array) $checkpoint['plan'],
            $cursor,
            $deadline
        );

        $checkpoint['transfer'] = $cursor;
        $store->save_checkpoint( (int) $job->id, $checkpoint );

        if ( null !== $outcome['error'] ) {
            $this->record_failure( $job, $outcome['error'], $outcome['retryable'] );
            return;
        }
        if ( $outcome['done'] ) {
            $store->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_RUNNING );
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
                $checkpoint['verification'] = isset( $payload['sub_state']['verification'] ) ? $payload['sub_state']['verification'] : array();
                // Carry the order-preservation outcome (customers and stock
                // re-applied) alongside verification so the dashboard reports
                // what was kept rather than hiding it.
                if ( isset( $payload['sub_state']['progress']['woo_preserve'] ) ) {
                    $checkpoint['verification']['woo_preserve'] = $payload['sub_state']['progress']['woo_preserve'];
                }
                $store->save_checkpoint( (int) $job->id, $checkpoint );
                $store->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_VERIFYING );
                return;
            }
            if ( 'failed' === $phase ) {
                $error_code = isset( $payload['error_code'] ) ? (string) $payload['error_code'] : '';
                if ( '' === $error_code && isset( $payload['sub_state']['error']['code'] ) ) {
                    $error_code = (string) $payload['sub_state']['error']['code'];
                }

                // A failed cloud pull never fails the restore: the job goes
                // back through staging once, taking the standard transfer.
                if ( 'mainwp_cloud_pull_failed' === $error_code && empty( $job->checkpoint['cloud_pull_tried'] ) ) {
                    $checkpoint                     = $job->checkpoint;
                    $checkpoint['cloud_pull_tried'] = true;
                    $checkpoint['transfer']         = array();
                    $store->save_checkpoint( (int) $job->id, $checkpoint );
                    $store->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_STAGING, array( 'child_task_id' => '' ) );
                    do_action( 'mainwp_log_warning', sprintf( 'Restore job %d: the direct cloud download failed on the site; retrying with the standard transfer.', (int) $job->id ) );
                    return;
                }

                $error = isset( $payload['error'] ) ? (string) $payload['error'] : esc_html__( 'The restore failed on the child site.', 'mainwp' );
                if ( isset( $payload['sub_state']['error']['message'] ) ) {
                    $error = (string) $payload['sub_state']['error']['message'];
                }
                $this->record_failure( $job, $error, false, $error_code );
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
        $checkpoint['verification']['site_responds'] = $reachable;
        $store->save_checkpoint( (int) $job->id, $checkpoint );

        if ( ! $reachable ) {
            $this->record_failure( $job, esc_html__( 'The site did not respond after the restore. The restore completed on the child; check the site directly.', 'mainwp' ), true );
            return;
        }

        MainWP_Backup_Restore_Transfer_Driver::instance()->cleanup_staging( (int) $job->id );
        $this->prune_pre_snapshot( $job );
        $store->complete_job( (int) $job->id );

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
     *                 pull_rejected: bool }.
     */
    private function create_child_restore_task( object $job, array $checkpoint, array $plan, ?array $pull_manifest = null ): array {
        $artifacts = array();
        foreach ( $plan as $entry ) {
            $artifacts[] = array(
                'role'       => $entry['role'],
                'file'       => $entry['file'],
                'size'       => ! empty( $entry['staged_path'] ) && file_exists( $entry['staged_path'] ) ? (int) filesize( $entry['staged_path'] ) : (int) $entry['size'],
                'sha256'     => (string) $entry['sha256'],
                'adopt_only' => ! empty( $entry['adopt_only'] ),
            );
        }

        $params = array(
            'restore_id'     => (string) $checkpoint['restore_id'],
            'source_prefix'  => '',
            'artifacts_json' => wp_json_encode( $artifacts ),
            'options_json'   => wp_json_encode(
                array(
                    'sub_type'         => (string) $checkpoint['sub_type'],
                    'has_foreign_keys' => null,
                    'file_rollback'    => ! empty( $checkpoint['options']['file_rollback'] ) || ! empty( $checkpoint['pre_snapshot_artifacts'] ),
                    'preserve_orders'  => ! empty( $checkpoint['options']['preserve_orders'] ),
                    'woo_order_since'  => isset( $checkpoint['options']['woo_order_since'] ) ? (int) $checkpoint['options']['woo_order_since'] : 0,
                )
            ),
        );
        if ( null !== $pull_manifest ) {
            $params['pull_manifest_json'] = wp_json_encode( $pull_manifest );
        }

        $website = $this->website( $job );
        $payload = MainWP_Connect::fetch_url_authed( $website, 'restore_task_create', $params, false, false, false );

        if ( ! is_array( $payload ) || empty( $payload['task_id'] ) ) {
            $message    = is_array( $payload ) && isset( $payload['error'] ) ? (string) $payload['error'] : esc_html__( 'The child site did not accept the restore task.', 'mainwp' );
            $error_code = is_array( $payload ) && isset( $payload['error_code'] ) ? (string) $payload['error_code'] : '';
            $retryable  = 'mainwp_backup_already_running' === $error_code;
            $this->record_failure( $job, $message, $retryable, $error_code );
            return array(
                'task_id'       => '',
                'pull_rejected' => false,
            );
        }

        return array(
            'task_id'       => sanitize_text_field( (string) $payload['task_id'] ),
            'pull_rejected' => ! empty( $payload['pull_manifest_rejected'] ),
        );
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
            $this->stamp_error_code( $job, $error_code );
            $store->fail_permanent( (int) $job->id, $error );

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

            return;
        }

        $checkpoint['resume_status'] = MainWP_Backup_Restore_Job_Store::STATUS_FAILED_RETRYABLE === $job->status && isset( $checkpoint['resume_status'] )
            ? (string) $checkpoint['resume_status']
            : (string) $job->status;
        // Keep the code in step with last_error so the progress poll can carry
        // a stable machine-readable code for support and AI diagnosis.
        $checkpoint['last_error_code']       = $error_code;
        $checkpoint['last_failure_transfer'] = $this->transfer_position( $checkpoint );
        $store->save_checkpoint( (int) $job->id, $checkpoint );

        $next_retry_at = time() + MainWP_Backup_Backoff::delay_for_attempt( $attempts );
        $store->fail_retryable( (int) $job->id, $error, $attempts, $next_retry_at );

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
     * @param object $job        Job row.
     * @param string $error_code Error code, or '' when the failure carried none.
     */
    private function stamp_error_code( object $job, string $error_code ): void {
        $store                         = MainWP_Backup_Restore_Job_Store::instance();
        $fresh                         = $store->get_job( (int) $job->id );
        $checkpoint                    = null !== $fresh ? $fresh->checkpoint : $job->checkpoint;
        $checkpoint['last_error_code'] = $error_code;
        $store->save_checkpoint( (int) $job->id, $checkpoint );
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
     * @param object $website Website object.
     *
     * @return bool True when the site answers with a non-server-error.
     */
    protected function site_responds( object $website ): bool {
        $response = wp_remote_get(
            $website->url,
            array(
                'timeout'   => 15,
                'sslverify' => false,
            )
        );
        if ( is_wp_error( $response ) ) {
            return false;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        return $code >= 200 && $code < 500;
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
