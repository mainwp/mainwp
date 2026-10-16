<?php
/**
 * MainWP Backup Retention Scheduler.
 *
 * Wires the retention engine to its triggers. A freshly recorded
 * snapshot queues its task and site pair into a small options-backed
 * list - never pruning synchronously in the snapshot path - and the
 * backups-continue lane drains one pair per tick. A daily cron sweeps
 * every task carrying a policy as the backstop for pairs the queue
 * missed. Each pass loads the pair's units, evaluates the task's
 * policy, and hands the candidates to the executor; the
 * mainwp_backup_retention_dry_run filter forces report-only passes.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Backup_Retention_Scheduler
 *
 * @package MainWP\Dashboard
 */
class MainWP_Backup_Retention_Scheduler { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Option holding the queued task and site pairs.
     *
     * @var string
     */
    const QUEUE_OPTION = 'mainwp_backup_retention_queue';

    /**
     * Overall budget for the daily sweep in seconds.
     *
     * @var int
     */
    const DAILY_BUDGET_SECONDS = 120;

    /**
     * Budget per drained pair in seconds.
     *
     * @var int
     */
    const PAIR_BUDGET_SECONDS = 60;

    /**
     * Instance variable class.
     *
     * @static
     * @var (self|null) $instance Instance of MainWP_Backup_Retention_Scheduler or null.
     */
    private static $instance = null;

    /**
     * Method instance()
     *
     * Create public static instance.
     *
     * @static
     * @return MainWP_Backup_Retention_Scheduler
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Register the snapshot listener. Priority 20: the upload driver
     * enqueues its jobs at 10, so retention always sees the upload rows.
     *
     * @return void
     */
    public function init(): void {
        add_action( 'mainwp_backup_snapshot_recorded', array( $this, 'on_snapshot_recorded' ), 20, 3 );
    }

    /**
     * Queue a task and site pair for retention after a snapshot records.
     * Tasks without a policy never queue.
     *
     * @param int $snapshot_id Snapshot id.
     * @param int $task_id     Scheduled task id.
     * @param int $site_id     Child site id.
     *
     * @return void
     */
    public function on_snapshot_recorded( $snapshot_id, $task_id, $site_id ): void {
        unset( $snapshot_id );

        if ( empty( $task_id ) || empty( $site_id ) ) {
            return;
        }

        $task = MainWP_DB_Backup::instance()->get_backup_task_by_id( (int) $task_id );
        if ( empty( $task ) || ! $this->task_has_policy( $task ) ) {
            return;
        }

        $queue = get_option( static::QUEUE_OPTION, array() );
        if ( ! is_array( $queue ) ) {
            $queue = array();
        }

        $key = (int) $task_id . '-' . (int) $site_id;
        if ( isset( $queue[ $key ] ) ) {
            return;
        }

        $queue[ $key ] = array(
            'task_id' => (int) $task_id,
            'site_id' => (int) $site_id,
        );

        update_option( static::QUEUE_OPTION, $queue, false );
    }

    /**
     * Drain one queued pair. Rides the backups-continue cron lane after
     * the upload and delete drivers.
     *
     * @param int      $budget_seconds Work budget for the pair.
     * @param int|null $now            Clock override for tests.
     *
     * @return void
     */
    public function process_post_snapshot_queue( int $budget_seconds = self::PAIR_BUDGET_SECONDS, ?int $now = null ): void {
        $queue = get_option( static::QUEUE_OPTION, array() );
        if ( ! is_array( $queue ) || empty( $queue ) ) {
            return;
        }

        $key  = array_key_first( $queue );
        $pair = $queue[ $key ];
        unset( $queue[ $key ] );
        update_option( static::QUEUE_OPTION, $queue, false );

        $task = MainWP_DB_Backup::instance()->get_backup_task_by_id( (int) $pair['task_id'] );
        if ( empty( $task ) ) {
            return;
        }

        $report = $this->run_retention_for_pair( $task, (int) $pair['site_id'], $budget_seconds, $now );

        if ( ! empty( $report['skipped'] ) ) {
            // The budget expired with candidates left: put the pair back at
            // the tail so the next continue tick finishes the cleanup
            // instead of waiting for the daily sweep. Errors deliberately
            // do not requeue - a permanent failure would churn every tick,
            // and the daily sweep retries those anyway.
            $queue = get_option( static::QUEUE_OPTION, array() );
            if ( ! is_array( $queue ) ) {
                $queue = array();
            }
            if ( ! isset( $queue[ $key ] ) ) {
                $queue[ $key ] = $pair;
                update_option( static::QUEUE_OPTION, $queue, false );
            }
        }
    }

    /**
     * The daily sweep: every task with a policy, every site it has
     * snapshots for, within one overall budget.
     *
     * @return void
     */
    public function cron_backups_prune(): void {
        if ( ! MainWP_Backup_Settings::is_native_backups_enabled() ) {
            return;
        }

        MainWP_Logger::instance()->info( 'backups prune' );

        MainWP_Utility::update_option( 'mainwp_cron_last_backups_prune', time() );

        // Cleanup owed by earlier runs must not be starved by the retention deadline.
        try {
            MainWP_Backup_Retention_Executor::instance()->resend_child_prunes();
        } catch ( \Throwable $e ) {
            MainWP_Logger::instance()->warning( 'Backup child prune retry failed: ' . $e->getMessage() );
        }

        $deadline = microtime( true ) + static::DAILY_BUDGET_SECONDS;

        foreach ( MainWP_Backup_Retention_Query::instance()->list_tasks_with_policy() as $task ) {
            foreach ( MainWP_Backup_Retention_Query::instance()->list_site_ids_for_task( (int) $task->id ) as $site_id ) {
                if ( microtime( true ) >= $deadline ) {
                    return; // Tomorrow's sweep picks up where this one stopped.
                }
                $this->run_retention_for_pair( $task, $site_id, static::PAIR_BUDGET_SECONDS );
            }
        }
    }

    /**
     * Run one retention pass for a task and site pair.
     *
     * @param object   $task           Task row carrying the policy fields.
     * @param int      $site_id        Child site id.
     * @param int      $budget_seconds Work budget for the executor.
     * @param int|null $now            Clock override for tests.
     *
     * @return array The executor result, or an empty-report shape when
     *               the policy found nothing to prune.
     */
    public function run_retention_for_pair( object $task, int $site_id, int $budget_seconds = self::PAIR_BUDGET_SECONDS, ?int $now = null ): array {
        $now = null === $now ? time() : $now;

        $units  = MainWP_Backup_Retention_Query::instance()->get_units_for_site_task( $site_id, (int) $task->id );
        $policy = new MainWP_Backup_Retention_Policy( (int) $task->retention_count, (int) $task->retention_days, (int) $task->retention_storage_mb );

        $candidates = $policy->evaluate( $units, $now );
        if ( empty( $candidates ) ) {
            return array(
                'pruned'               => 0,
                'snapshots_pruned'     => 0,
                'deleted_ids'          => array(),
                'remaining_ids'        => array(),
                'cloud_deletes_queued' => array(),
                'skipped'              => 0,
                'errors'               => array(),
                'dry_run'              => false,
                'candidates'           => array(),
            );
        }

        /**
         * Filters whether retention runs report-only. With true, the
         * executor returns its candidate report and deletes nothing -
         * for staging a policy before letting it loose.
         *
         * @param bool $dry_run Defaults to false.
         * @param int  $task_id The task being evaluated.
         */
        $dry_run = (bool) apply_filters( 'mainwp_backup_retention_dry_run', false, (int) $task->id );

        $report = MainWP_Backup_Retention_Executor::instance()->run_prune( $candidates, $task, $dry_run, $budget_seconds );

        if ( ! empty( $report['errors'] ) ) {
            // Both cron triggers (the post-snapshot drain and the daily
            // sweep) run through here and otherwise drop the report, so a
            // failed cleanup would leave no trace. The warning channel also
            // lands in the backup diagnostics guardrail records.
            do_action( 'mainwp_log_warning', 'Backup retention for task ' . (int) $task->id . ' on site ' . (int) $site_id . ' reported errors: ' . implode( ' | ', array_map( 'strval', (array) $report['errors'] ) ), (int) $site_id, (int) $task->id );
        }

        return $report;
    }

    /**
     * Whether a task carries any retention constraint.
     *
     * @param object $task Task row.
     *
     * @return bool
     */
    private function task_has_policy( object $task ): bool {
        return (int) $task->retention_count > 0 || (int) $task->retention_days > 0 || (int) $task->retention_storage_mb > 0;
    }
}
