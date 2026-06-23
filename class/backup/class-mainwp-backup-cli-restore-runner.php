<?php
/**
 * MainWP Backup CLI Restore Runner.
 *
 * The engine-agnostic core behind `wp mainwp backup restore`. It lets an
 * operator who has dashboard CLI but no child wp-admin drive a remote restore
 * of a child from the command line. It owns no restore logic of its own: it
 * mirrors the AJAX initiate path - read the snapshot, apply the same
 * destructive-confirm gate the modal enforces, then hand off to the restore
 * orchestrator - and adds only the one thing CLI needs that the browser gets
 * from WP-Cron: it drives the job to a terminal state synchronously instead of
 * waiting for a scheduled continuation. The thin WP-CLI command wraps this and
 * renders the result.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Class MainWP_Backup_CLI_Restore_Runner
 *
 * @package MainWP\Dashboard
 */
class MainWP_Backup_CLI_Restore_Runner {

    /**
     * Restore orchestrator (injected for the test seam).
     *
     * @var MainWP_Backup_Restore_Orchestrator
     */
    protected $orchestrator;

    /**
     * Restore job store, polled to follow the driven job.
     *
     * @var MainWP_Backup_Restore_Job_Store
     */
    protected $store;

    /**
     * Snapshot reader, used for the confirm gate and its facts.
     *
     * @var MainWP_Backup_Snapshots
     */
    protected $snapshots;

    /**
     * Seconds budgeted to each orchestrator slice.
     *
     * @var int
     */
    protected $budget_seconds;

    /**
     * Seconds to pause between slices while a phase is still working, so a
     * long child phase is paced rather than busy-polled. Zeroed in tests.
     *
     * @var int
     */
    protected $tick_pause_seconds;

    /**
     * Cap on the wait honoured for a retryable backoff before the next
     * slice, so a long delay does not strand the command. Zeroed in tests.
     *
     * @var int
     */
    protected $max_retry_wait_seconds;

    /**
     * Overall wall-clock ceiling for the drive, or 0 for none. When hit, the
     * command returns and the restore continues under the scheduled cron.
     *
     * @var int
     */
    protected $max_total_seconds;

    /**
     * Constructor.
     *
     * @param MainWP_Backup_Restore_Orchestrator|null $orchestrator           Orchestrator.
     * @param MainWP_Backup_Restore_Job_Store|null    $store                  Job store.
     * @param MainWP_Backup_Snapshots|null            $snapshots              Snapshot reader.
     * @param int                                     $budget_seconds         Slice budget.
     * @param int                                     $tick_pause_seconds     Pause between working slices.
     * @param int                                     $max_retry_wait_seconds Cap on a retry backoff wait.
     * @param int                                     $max_total_seconds      Overall ceiling, 0 for none.
     */
    public function __construct(
        ?MainWP_Backup_Restore_Orchestrator $orchestrator = null,
        ?MainWP_Backup_Restore_Job_Store $store = null,
        ?MainWP_Backup_Snapshots $snapshots = null,
        int $budget_seconds = 60,
        int $tick_pause_seconds = 2,
        int $max_retry_wait_seconds = 120,
        int $max_total_seconds = 0
    ) {
        $this->orchestrator           = null !== $orchestrator ? $orchestrator : MainWP_Backup_Restore_Orchestrator::instance();
        $this->store                  = null !== $store ? $store : MainWP_Backup_Restore_Job_Store::instance();
        $this->snapshots              = null !== $snapshots ? $snapshots : MainWP_Backup_Snapshots::instance();
        $this->budget_seconds         = $budget_seconds;
        $this->tick_pause_seconds     = $tick_pause_seconds;
        $this->max_retry_wait_seconds = $max_retry_wait_seconds;
        $this->max_total_seconds      = $max_total_seconds;
    }

    /**
     * Restore a snapshot to its site, end to end.
     *
     * Mirrors the AJAX initiate handler: the snapshot is read, the same
     * destructive-confirm gate is applied (a database-bearing restore is
     * refused until the operator confirms), the orchestrator resolves the
     * chain and creates the job, and the job is then driven to a terminal
     * state. The orchestrator throws rather than returning a code, so an
     * unknown snapshot, a cross-site snapshot, or a second restore for a busy
     * site surfaces here as a clean failure result.
     *
     * @param int           $site_id     Child site id.
     * @param int           $snapshot_id Snapshot to restore.
     * @param array         $options     { pre_snapshot: bool }.
     * @param bool          $confirmed   Operator passed the destructive confirm.
     * @param callable|null $progress    Optional per-slice callback, given the job row.
     *
     * @return array {
     *     @type bool   $ok                   True when the restore reached completed.
     *     @type bool   $needs_confirmation   True when a destructive restore was refused for want of consent.
     *     @type int    $job_id               The restore job id, or 0.
     *     @type string $status               Final job status, or ''.
     *     @type string $error                Human-readable failure message, or ''.
     *     @type string $error_code           Stable error code, or ''.
     *     @type bool   $site_has_woocommerce Whether the site runs WooCommerce (confirm facts).
     *     @type int    $age_days             Age of the snapshot in days (confirm facts).
     * }
     */
    public function run( int $site_id, int $snapshot_id, array $options, bool $confirmed, $progress = null ): array {
        $snapshot = $this->snapshots->get_snapshot_by_id( $snapshot_id );

        // Re-derive destructive risk exactly as the AJAX path does: anything
        // but a files-only snapshot rewrites the live database, so it is
        // refused until the operator confirms. The facts the modal shows
        // (WooCommerce presence, backup age) ride along so the command can
        // explain why.
        if ( MainWP_Post_Backup_Handler::restore_needs_confirmation( $snapshot, $confirmed ) ) {
            return array(
                'ok'                   => false,
                'needs_confirmation'   => true,
                'job_id'               => 0,
                'status'               => '',
                'error'                => '',
                'error_code'           => '',
                'site_has_woocommerce' => MainWP_Utility::site_has_woocommerce( $site_id ),
                'age_days'             => max( 0, (int) floor( ( time() - (int) $snapshot->created ) / DAY_IN_SECONDS ) ),
            );
        }

        // Order preservation applies only to a database-bearing restore; the
        // backup's created time bounds the customer capture on the child.
        $preserve_orders = ! empty( $options['preserve_orders'] ) && null !== $snapshot && 'files' !== (string) $snapshot->type;
        $woo_order_since = $preserve_orders ? (int) $snapshot->created : 0;

        try {
            $job_id = $this->orchestrator->initiate_restore(
                $site_id,
                $snapshot_id,
                array(
                    'pre_snapshot'    => ! empty( $options['pre_snapshot'] ),
                    'file_rollback'   => ! empty( $options['pre_snapshot'] ),
                    'preserve_orders' => $preserve_orders,
                    'woo_order_since' => $woo_order_since,
                )
            );
        } catch ( MainWP_Exception $e ) {
            return $this->fail( 0, '', $e->getMessage(), $e->get_message_error_code() );
        }

        $job = $this->drive_to_terminal( $job_id, $progress );
        if ( null === $job ) {
            return $this->fail( $job_id, '', __( 'The restore job vanished mid-run.', 'mainwp' ), '' );
        }

        if ( MainWP_Backup_Restore_Job_Store::STATUS_COMPLETED === $job->status ) {
            return array(
                'ok'                   => true,
                'needs_confirmation'   => false,
                'job_id'               => $job_id,
                'status'               => $job->status,
                'error'                => '',
                'error_code'           => '',
                'site_has_woocommerce' => false,
                'age_days'             => 0,
            );
        }

        // Still active means the overall ceiling was hit; the scheduled
        // continuation carries the restore on from here.
        if ( in_array( $job->status, MainWP_Backup_Restore_Job_Store::active_statuses(), true ) ) {
            return $this->fail( $job_id, $job->status, __( 'The restore did not finish within the time limit. It continues in the background; check its progress on the dashboard.', 'mainwp' ), '' );
        }

        $checkpoint = is_array( $job->checkpoint ) ? $job->checkpoint : array();
        $error      = '' !== (string) $job->last_error ? (string) $job->last_error : __( 'The restore failed.', 'mainwp' );
        $code       = isset( $checkpoint['last_error_code'] ) ? (string) $checkpoint['last_error_code'] : '';
        return $this->fail( $job_id, $job->status, $error, $code );
    }

    /**
     * Drive one restore job to a terminal state.
     *
     * The orchestrator slice runner is built for cron: one call acquires the
     * job under a lock, drains as many phases as keep changing status within
     * the budget, then returns - returning early while the child is still
     * working, on a retryable backoff, on a terminal state, or at the
     * deadline. A synchronous caller therefore loops: it paces a still-working
     * phase rather than busy-polling, honours a retryable backoff so the next
     * slice can re-acquire the job (the orchestrator promotes the job to a
     * permanent failure once its attempts are exhausted, which is what bounds
     * this loop), and stops at an optional overall ceiling. A still-unchanged
     * status is the normal signal of a long phase, not a stall, so it is never
     * treated as a reason to give up.
     *
     * @param int           $job_id   Job id.
     * @param callable|null $progress Optional callback given each job row.
     *
     * @return object|null Final job row, or null if it vanished.
     */
    public function drive_to_terminal( int $job_id, $progress = null ) {
        $active = MainWP_Backup_Restore_Job_Store::active_statuses();
        $start  = time();
        $job    = $this->store->get_job( $job_id );

        while ( null !== $job && in_array( $job->status, $active, true ) ) {
            if ( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_RETRYABLE === $job->status ) {
                // The orchestrator will not re-acquire a backed-off job until
                // its next_retry_at, so wait for it (bounded) before the next
                // slice rather than spinning.
                $this->sleep_seconds( min( max( 0, (int) $job->next_retry_at - time() ), $this->max_retry_wait_seconds ) );
            }

            $this->orchestrator->process_pending_restore_jobs( $this->budget_seconds );

            $job = $this->store->get_job( $job_id );
            if ( null !== $job && null !== $progress ) {
                call_user_func( $progress, $job );
            }

            if ( $this->max_total_seconds > 0 && time() - $start >= $this->max_total_seconds ) {
                break;
            }

            if ( null !== $job
                && in_array( $job->status, $active, true )
                && MainWP_Backup_Restore_Job_Store::STATUS_FAILED_RETRYABLE !== $job->status
            ) {
                $this->sleep_seconds( $this->tick_pause_seconds );
            }
        }

        return $job;
    }

    /**
     * Sleep, isolated so tests can run with zeroed pauses.
     *
     * @param int $seconds Seconds to sleep.
     */
    protected function sleep_seconds( int $seconds ) {
        if ( $seconds > 0 ) {
            sleep( $seconds );
        }
    }

    /**
     * Shape a failure result.
     *
     * @param int    $job_id Job id, or 0.
     * @param string $status Job status, or ''.
     * @param string $error  Human-readable message.
     * @param string $code   Stable error code, or ''.
     *
     * @return array
     */
    protected function fail( $job_id, $status, $error, $code ) {
        return array(
            'ok'                   => false,
            'needs_confirmation'   => false,
            'job_id'               => (int) $job_id,
            'status'               => (string) $status,
            'error'                => (string) $error,
            'error_code'           => (string) $code,
            'site_has_woocommerce' => false,
            'age_days'             => 0,
        );
    }
}
