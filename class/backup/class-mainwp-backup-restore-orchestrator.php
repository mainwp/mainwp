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
        $website = MainWP_DB::instance()->get_website_by_id( $site_id );
        if ( empty( $website ) ) {
            throw new MainWP_Exception( esc_html__( 'Site not found.', 'mainwp' ) );
        }

        if ( null !== MainWP_Backup_Restore_Job_Store::instance()->get_active_job_for_site( $site_id ) ) {
            throw new MainWP_Exception( esc_html__( 'A restore is already running for this site. Wait for it to finish before starting another.', 'mainwp' ) );
        }

        $resolved = MainWP_Backup_Snapshot_Catalog::instance()->resolve_restorable_chain( $snapshot_id );
        if ( ! $resolved['ok'] ) {
            throw new MainWP_Exception( esc_html( $resolved['error'] ) );
        }

        $checkpoint = array(
            'restore_id' => strtolower( wp_generate_password( 12, false ) ),
            'sub_type'   => $resolved['sub_type'],
            'plan'       => $resolved['plan'],
            'options'    => array(
                'pre_snapshot'  => ! empty( $options['pre_snapshot'] ),
                'file_rollback' => ! empty( $options['file_rollback'] ),
            ),
            'transfer'   => array(),
        );

        $job_id = MainWP_Backup_Restore_Job_Store::instance()->create_job( $site_id, $snapshot_id, $checkpoint );
        if ( 0 === $job_id ) {
            throw new MainWP_Exception( esc_html__( 'The restore job could not be recorded.', 'mainwp' ) );
        }

        return $job_id;
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
        if ( 0 === $job_id ) {
            throw new MainWP_Exception( esc_html__( 'The rollback job could not be recorded.', 'mainwp' ) );
        }
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
     * Drive pending restore jobs for one budgeted cron slice.
     *
     * @param int $budget_seconds Slice budget.
     */
    public function process_pending_restore_jobs( int $budget_seconds = self::DEFAULT_BUDGET_SECONDS ): void {
        $deadline = microtime( true ) + $budget_seconds;
        $token    = 'restore-' . wp_generate_password( 12, false );
        $store    = MainWP_Backup_Restore_Job_Store::instance();

        $job = $store->acquire_next_job( $token, $budget_seconds + 60 );
        if ( null === $job ) {
            return;
        }

        try {
            $this->run_job( $job, $deadline );
        } catch ( \Throwable $e ) {
            $this->record_failure( $job, $e->getMessage(), true );
        }

        $store->release_lock( (int) $job->id, $token );
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
                $this->phase_running( $job );
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
        $store = MainWP_Backup_Restore_Job_Store::instance();

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
            $payload = MainWP_Connect::fetch_url_authed( $website, 'backup_task_run', array( 'task_id' => $job->pre_snapshot_task_id ), false, false, false );
            $phase   = is_array( $payload ) && isset( $payload['phase'] ) ? (string) $payload['phase'] : '';

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
                $error = is_array( $payload ) && isset( $payload['error'] ) ? (string) $payload['error'] : esc_html__( 'The pre-restore safety backup failed.', 'mainwp' );
                $store->fail_permanent( (int) $job->id, $error );
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
                    return;
                }

                $store->set_status( (int) $job->id, MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, array( 'child_task_id' => $created['task_id'] ) );
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
                $store->fail_permanent( (int) $job->id, $outcome['error'] );
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
     * Running: drive the child restore task one slice per round trip.
     *
     * @param object $job Job row.
     */
    private function phase_running( object $job ): void {
        $store   = MainWP_Backup_Restore_Job_Store::instance();
        $website = $this->website( $job );
        $payload = MainWP_Connect::fetch_url_authed( $website, 'restore_task_run', array( 'task_id' => $job->child_task_id ), false, false, false );

        // A terminal failed phase carries an error key too - phase wins.
        if ( ! is_array( $payload ) || ( ! isset( $payload['phase'] ) && isset( $payload['error'] ) ) ) {
            $message = is_array( $payload ) && isset( $payload['error'] ) ? (string) $payload['error'] : esc_html__( 'The child site returned no restore status.', 'mainwp' );
            $this->record_failure( $job, $message, true );
            return;
        }

        $phase = isset( $payload['phase'] ) ? (string) $payload['phase'] : '';
        if ( 'completed' === $phase ) {
            $checkpoint                 = $job->checkpoint;
            $checkpoint['verification'] = isset( $payload['sub_state']['verification'] ) ? $payload['sub_state']['verification'] : array();
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
                return;
            }

            $error = isset( $payload['error'] ) ? (string) $payload['error'] : esc_html__( 'The restore failed on the child site.', 'mainwp' );
            if ( isset( $payload['sub_state']['error']['message'] ) ) {
                $error = (string) $payload['sub_state']['error']['message'];
            }
            $store->fail_permanent( (int) $job->id, $error );
            return;
        }
        // Still working: the next tick polls again.
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
                )
            ),
        );
        if ( null !== $pull_manifest ) {
            $params['pull_manifest_json'] = wp_json_encode( $pull_manifest );
        }

        $website = $this->website( $job );
        $payload = MainWP_Connect::fetch_url_authed( $website, 'restore_task_create', $params, false, false, false );

        if ( ! is_array( $payload ) || empty( $payload['task_id'] ) ) {
            $message   = is_array( $payload ) && isset( $payload['error'] ) ? (string) $payload['error'] : esc_html__( 'The child site did not accept the restore task.', 'mainwp' );
            $retryable = is_array( $payload ) && isset( $payload['error_code'] ) && 'mainwp_backup_already_running' === $payload['error_code'];
            $this->record_failure( $job, $message, $retryable );
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
     * @param object $job       Job row.
     * @param string $error     Error text.
     * @param bool   $retryable Whether a retry can succeed.
     */
    private function record_failure( object $job, string $error, bool $retryable ): void {
        $store    = MainWP_Backup_Restore_Job_Store::instance();
        $attempts = (int) $job->attempt_count + 1;

        if ( ! $retryable || MainWP_Backup_Backoff::attempts_exhausted( $attempts ) ) {
            $store->fail_permanent( (int) $job->id, $error );
            return;
        }

        // The phase that failed may have checkpointed progress after this
        // row was read; a stale write here would erase that cursor.
        $fresh                       = $store->get_job( (int) $job->id );
        $checkpoint                  = null !== $fresh ? $fresh->checkpoint : $job->checkpoint;
        $checkpoint['resume_status'] = MainWP_Backup_Restore_Job_Store::STATUS_FAILED_RETRYABLE === $job->status && isset( $checkpoint['resume_status'] )
            ? (string) $checkpoint['resume_status']
            : (string) $job->status;
        $store->save_checkpoint( (int) $job->id, $checkpoint );

        $store->fail_retryable( (int) $job->id, $error, $attempts, time() + MainWP_Backup_Backoff::delay_for_attempt( $attempts ) );
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
