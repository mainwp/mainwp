<?php
/**
 * MainWP Backup Test-Restore Scheduler - the sampling lane that makes a
 * Level-5 verified restore periodic instead of a manual drill.
 *
 * The Stage-1 driver (MainWP_Backup_TestRestore_Driver) proves a snapshot
 * reconstitutes when an operator asks. This lane runs that proof on a sampling
 * cadence with nobody pressing anything: on each tick it picks the newest
 * complete snapshot per site that has not been test-restored inside the
 * cadence window, claims it atomically, hands it to the driver, and lets the
 * result land on the snapshot row. It never contacts a child site - everything
 * happens against artifacts the dashboard already holds, reconstituted in a
 * scratch database on the dashboard host - and it skips a host that cannot
 * create a scratch database rather than retrying it on every snapshot.
 *
 * The lane carries no job table: the snapshot row's test_restore_at column is
 * the state, and the atomic claim doubles as the concurrency guard and the
 * backoff that stops a snapshot looping when its run cannot record a result.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Class MainWP_Backup_TestRestore_Scheduler
 */
class MainWP_Backup_TestRestore_Scheduler { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Per-tick slice budget, seconds. With one snapshot per tick this only
     * bounds the rare case where a single test-restore runs long.
     */
    const DEFAULT_BUDGET_SECONDS = 120;

    /**
     * Cadence window: a site's newest complete snapshot becomes eligible again
     * once its last test-restore is this old.
     */
    const CADENCE_SECONDS = WEEK_IN_SECONDS;

    /**
     * Snapshots test-restored per tick. One keeps the lane a gentle drip on the
     * five-minute backup-continue lane; a backlog drains through the
     * continuation hook rather than a single burst.
     */
    const MAX_PER_TICK = 1;

    /**
     * Option caching the host capability probe ({capable, reason, checked_at}).
     */
    const CAPABILITY_OPTION = 'mainwp_testrestore_capability';

    /**
     * How long a capability verdict is trusted before re-probing, so a host
     * that later gains the CREATE privilege is picked up without a manual reset.
     */
    const CAPABILITY_TTL = MONTH_IN_SECONDS;

    /**
     * Single-event continuation hook. Mirrors the restore orchestrator's
     * dedicated continue action (never a recurring hook, which WordPress would
     * de-duplicate the single event against).
     */
    const CONTINUE_HOOK = 'mainwp_testrestore_continue_action';

    /**
     * Recorded when the chain cannot be resolved (artifacts pruned or missing).
     * A neutral "could not test here" rather than a reconstitution fail; the
     * snapshot is already shown as unavailable to restore.
     */
    const STATE_UNAVAILABLE = 'unavailable';

    /**
     * Singleton.
     *
     * @var MainWP_Backup_TestRestore_Scheduler|null
     */
    private static $instance = null;

    /**
     * Get the singleton instance.
     *
     * @return MainWP_Backup_TestRestore_Scheduler
     */
    public static function instance(): self {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Run one budgeted sampling slice.
     *
     * Gated on native backups and on the host being able to host a sandbox.
     * Picks the overdue newest-per-site snapshots, claims each atomically (so
     * overlapping ticks never double-run one snapshot), and test-restores up to
     * MAX_PER_TICK of them. When more remain than this tick handled, a
     * continuation is scheduled so a backlog drains without waiting for the next
     * five-minute lane; in steady state there is at most one candidate and
     * nothing is scheduled.
     *
     * @param int      $budget_seconds Slice budget.
     * @param int|null $now            Injected clock for tests; defaults to time().
     *
     * @return void
     */
    public function process_pending_jobs( int $budget_seconds = self::DEFAULT_BUDGET_SECONDS, ?int $now = null ): void {
        if ( ! MainWP_Backup_Settings::is_native_backups_enabled() ) {
            return;
        }

        $now = null === $now ? time() : $now;
        if ( ! $this->ensure_capable( $now ) ) {
            return;
        }

        $cutoff     = $now - self::CADENCE_SECONDS;
        $snapshots  = MainWP_Backup_Snapshots::instance();
        $candidates = $snapshots->get_test_restore_candidates( self::MAX_PER_TICK + 1, $cutoff );
        $deadline   = microtime( true ) + $budget_seconds;
        $processed  = 0;

        foreach ( $candidates as $candidate ) {
            if ( $processed >= self::MAX_PER_TICK || microtime( true ) >= $deadline ) {
                break;
            }

            $snapshot_id = (int) $candidate->id;
            if ( ! $snapshots->claim_for_test_restore( $snapshot_id, $now, $cutoff ) ) {
                continue; // Another tick already claimed it.
            }

            $result = $this->verify( $snapshot_id, $deadline );
            if ( MainWP_Backup_TestRestore_Driver::ERR_DEFERRED === ( $result['error_code'] ?? '' ) ) {
                // Undo only this tick's claim; preserve the earlier outcome.
                global $wpdb;
                $restored = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- release only this sampler tick's cadence claim.
                    $wpdb->prepare( 'UPDATE ' . MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ) . ' SET test_restore_at = %d WHERE id = %d AND test_restore_at = %d', (int) $candidate->test_restore_at, $snapshot_id, $now ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- custom table identifier from get_table_name(); all values bind through placeholders.
                );
                if ( false === $restored ) {
                    MainWP_Logger::instance()->warning( 'Test restore could not release its cadence claim for snapshot ' . $snapshot_id );
                }
                continue;
            }
            if ( isset( $result['error_code'] )
                && in_array( $result['error_code'], array( MainWP_Backup_TestRestore_Driver::ERR_UNRESOLVABLE, MainWP_Backup_TestRestore_Driver::ERR_DEADLINE_EXCEEDED ), true ) ) {
                // The driver reports an unresolvable chain, or a tick budget that
                // ran out before the sandbox could apply the plan, without
                // recording either: stamp a neutral result so the row surfaces
                // "couldn't test" rather than "fail" - the claim already backed
                // this snapshot off for the cadence window, so there is no
                // sooner retry to preserve either way.
                $snapshots->set_test_restore_result( $snapshot_id, self::STATE_UNAVAILABLE, $now );
            }

            ++$processed;
        }

        if ( $processed > 0 && count( $candidates ) > self::MAX_PER_TICK ) {
            $this->schedule_continuation();
        }
    }

    /**
     * Whether this host can host a sandbox, cached for CAPABILITY_TTL.
     *
     * @param int $now Current epoch.
     *
     * @return bool
     */
    protected function ensure_capable( int $now ): bool {
        $cached = get_option( self::CAPABILITY_OPTION );
        if ( is_array( $cached ) && isset( $cached['checked_at'] )
            && ( $now - (int) $cached['checked_at'] ) < self::CAPABILITY_TTL ) {
            return ! empty( $cached['capable'] );
        }

        $probe = MainWP_Backup_TestRestore_Sandbox::probe_capability();
        if ( MainWP_Backup_Error_Codes::ERR_PRIVATE_ROOT_UNAVAILABLE === ( $probe['error_code'] ?? '' ) ) {
            return false;
        }
        update_option(
            self::CAPABILITY_OPTION,
            array(
                'capable'    => ! empty( $probe['capable'] ),
                'reason'     => isset( $probe['reason'] ) ? (string) $probe['reason'] : '',
                'checked_at' => $now,
            ),
            false
        );

        return ! empty( $probe['capable'] );
    }

    /**
     * One-line operator notice when the host cannot host a sandbox; empty
     * otherwise. Reads only the cached verdict the lane populates - never
     * probes - so a surfacing layer can call it cheaply on every render.
     *
     * @return string
     */
    public function get_capability_notice(): string {
        $cached = get_option( self::CAPABILITY_OPTION );
        if ( ! is_array( $cached ) || ! isset( $cached['capable'] ) || $cached['capable'] ) {
            return '';
        }

        $reason = isset( $cached['reason'] ) ? (string) $cached['reason'] : '';
        return '' !== $reason
            ? $reason
            : esc_html__( 'Test-restore needs the database CREATE privilege on this dashboard host.', 'mainwp' );
    }

    /**
     * Hand a snapshot to the Stage-1 engine. A seam so tests can drive
     * selection, claiming and continuation without a real sandbox.
     *
     * @param int   $snapshot_id Snapshot id.
     * @param float $deadline    Absolute microtime(true) deadline for this tick's budget.
     *
     * @return array Driver result.
     */
    protected function verify( int $snapshot_id, float $deadline ): array {
        return MainWP_Backup_TestRestore_Driver::instance()->verify_snapshot( $snapshot_id, array( 'deadline' => $deadline ) );
    }

    /**
     * Schedule an immediate continuation so a backlog drains without waiting
     * for the five-minute lane. Mirrors the restore orchestrator: a
     * wp_next_scheduled guard plus spawn_cron collapse repeated calls into a
     * natural throttle rather than a busy loop, and under DISABLE_WP_CRON the
     * five-minute lane remains the backstop.
     *
     * @return void
     */
    protected function schedule_continuation(): void {
        if ( ! wp_next_scheduled( self::CONTINUE_HOOK ) ) {
            wp_schedule_single_event( time(), self::CONTINUE_HOOK );
        }
        spawn_cron();
    }
}
