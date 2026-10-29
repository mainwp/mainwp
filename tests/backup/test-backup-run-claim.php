<?php
/**
 * Backup run claim tests.
 *
 * On-demand runs bump a task's last_run to open a new run. An
 * unconditional bump let a duplicate initiate reopen a run that had just
 * closed, poisoning the poll's freshness check and reporting a successful
 * backup as failed. The claim makes the manual-path bump refusable: a
 * still-open run and a successful close inside the settle window both
 * refuse, while the scheduled cron path allows stuck-run recovery but
 * refuses paused rows so a stale task object cannot bypass a pause.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Manage_Backups_Handler;

/**
 * Class MainWP_Backup_Run_Claim_Test
 */
class MainWP_Backup_Run_Claim_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Clean up task rows and options.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_wp_backup_progress" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_wp_backup" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup.
		delete_option( 'mainwp_enableNativeBackups' );
		parent::tearDown();
	}

	/**
	 * Create a real backup task row in the given run state.
	 *
	 * @param array $over Field overrides applied after insert.
	 *
	 * @return object Task row.
	 */
	private function task_in_state( array $over = array() ) {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$task = MainWP_DB_Backup::instance()->add_backup_task( $user, 'Run claim test task', 'daily', 'full', '', (string) $this->create_test_site(), '', '', '', 0, 0, 0, 0, 0, 'tar.gz', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task, 'Precondition: the task row must insert.' );
		if ( ! empty( $over ) ) {
			MainWP_DB_Backup::instance()->update_backup_task_with_values( (int) $task->id, $over );
			$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		}
		return $task;
	}

	/**
	 * Claim modes both preserve the boolean contract and return the exact stamp.
	 *
	 * @return array Grace windows.
	 */
	public static function claim_grace_windows() {
		return array(
			'scheduled' => array( 0 ),
			'manual'    => array( 15 ),
		);
	}

	/**
	 * The returned identity includes the same-second close adjustment.
	 *
	 * @dataProvider claim_grace_windows
	 * @param int $grace_seconds Claim window.
	 */
	public function test_claim_returns_exact_same_second_identity( $grace_seconds ) {
		$closed  = time() + 10;
		$task    = $this->task_in_state(
			array(
				'last_run'      => $closed,
				'completed'     => $closed,
				'backup_errors' => 'Retry.',
			)
		);
		$started = 0;
		$this->assertTrue( MainWP_DB_Backup::instance()->claim_backup_run( $task->id, $grace_seconds, $started ) );
		$this->assertSame( $closed + 1, $started );
		$this->assertSame( $started, (int) MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id )->last_run );
	}

	/**
	 * A changed close between read and UPDATE refuses instead of guessing identity.
	 *
	 * @dataProvider claim_grace_windows
	 * @param int $grace_seconds Claim window.
	 */
	public function test_claim_refuses_changed_row_before_update( $grace_seconds ) {
		global $wpdb;
		$task       = $this->task_in_state(
			array(
				'last_run'  => time() - 100,
				'completed' => time() - 90,
			)
		);
		$closed     = time() + 10;
		$interleave = static function ( $query ) use ( &$interleave, $wpdb, $task, $closed ) {
			if ( str_starts_with( $query, 'UPDATE ' . $wpdb->prefix . 'mainwp_wp_backup SET last_run = GREATEST(' ) ) {
				remove_filter( 'query', $interleave );
				$wpdb->update(
					$wpdb->prefix . 'mainwp_wp_backup',
					array(
						'completed'     => $closed,
						'backup_errors' => 'Retry.',
					),
					array( 'id' => $task->id )
				);
			}
			return $query;
		};
		$started    = 123;
		add_filter( 'query', $interleave );
		try {
			$this->assertFalse( MainWP_DB_Backup::instance()->claim_backup_run( $task->id, $grace_seconds, $started ) );
			$this->assertSame( 0, $started );
			$after = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
			$this->assertSame( (int) $task->last_run, (int) $after->last_run );
			$this->assertSame( $closed, (int) $after->completed );
		} finally {
			remove_filter( 'query', $interleave );
		}
	}

	/**
	 * An idle task whose last close is old claims normally: the run opens
	 * with a fresh last_run and a reset per-run site list.
	 */
	public function test_claim_backup_run_succeeds_when_task_is_idle() {
		$task = $this->task_in_state(
			array(
				'last_run'  => time() - 7200,
				'completed' => time() - 3600,
			)
		);

		$this->assertTrue( MainWP_DB_Backup::instance()->claim_backup_run( (int) $task->id, 15 ) );

		$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertGreaterThan( time() - 5, (int) $fresh->last_run, 'A successful claim must stamp last_run.' );
		$this->assertSame( '[]', (string) $fresh->completed_sites, 'A successful claim must reset the per-run site list.' );
	}

	/**
	 * A run that closed successfully seconds ago refuses the claim: a
	 * duplicate initiate must not reopen the run that just finished and
	 * poison the freshness check of the click that started it.
	 */
	public function test_claim_backup_run_grace_refuses_reopen_right_after_success() {
		$old_last_run = time() - 30;
		$task         = $this->task_in_state(
			array(
				'last_run'  => $old_last_run,
				'completed' => time() - 4,
			)
		);

		$this->assertFalse( MainWP_DB_Backup::instance()->claim_backup_run( (int) $task->id, 15 ) );

		$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertSame( $old_last_run, (int) $fresh->last_run, 'A refused claim must leave last_run untouched.' );
	}

	/**
	 * Once the settle window has elapsed, a new run claims normally - the
	 * debounce never blocks a deliberate later backup.
	 */
	public function test_claim_backup_run_grace_allows_reopen_after_window_elapses() {
		$task = $this->task_in_state(
			array(
				'last_run'  => time() - 60,
				'completed' => time() - 20,
			)
		);

		$this->assertTrue( MainWP_DB_Backup::instance()->claim_backup_run( (int) $task->id, 15 ) );
	}

	/**
	 * A run that closed with errors may retry immediately: failure
	 * retries are never debounced.
	 */
	public function test_claim_backup_run_grace_allows_immediate_retry_after_failure() {
		$task = $this->task_in_state(
			array(
				'last_run'      => time() - 30,
				'completed'     => time() - 4,
				'backup_errors' => 'Site: child. The database dump failed.',
			)
		);

		$this->assertTrue( MainWP_DB_Backup::instance()->claim_backup_run( (int) $task->id, 15 ) );
	}

	/**
	 * A still-open run refuses the claim under a settle window: two
	 * initiates racing within one request cycle cannot both open the run.
	 */
	public function test_claim_backup_run_grace_refuses_open_run() {
		$task = $this->task_in_state(
			array(
				'last_run'  => time() - 100,
				'completed' => time() - 500,
			)
		);

		$this->assertFalse( MainWP_DB_Backup::instance()->claim_backup_run( (int) $task->id, 15 ) );
	}

	/**
	 * With no settle window, an unpaused task can recover a stuck open run
	 * so scheduled tasks are not blocked by a previous run that never closed.
	 */
	public function test_claim_backup_run_grace_zero_recovers_stuck_open_run() {
		$task = $this->task_in_state(
			array(
				'last_run'  => time() - 100,
				'completed' => time() - 500,
			)
		);

		$this->assertTrue( MainWP_DB_Backup::instance()->claim_backup_run( (int) $task->id, 0 ), 'A zero-grace claim must recover a stuck open run on an unpaused task.' );

		$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertGreaterThan( time() - 5, (int) $fresh->last_run );
	}

	/**
	 * Stuck-run recovery must not bypass a stored pause.
	 */
	public function test_claim_backup_run_grace_zero_refuses_paused_task() {
		$old_last_run = time() - 100;
		$task         = $this->task_in_state(
			array(
				'last_run'  => $old_last_run,
				'completed' => time() - 500,
				'paused'    => 1,
			)
		);

		$this->assertFalse( MainWP_DB_Backup::instance()->claim_backup_run( (int) $task->id, 0 ), 'A zero-grace claim must refuse a paused task.' );

		$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertSame( $old_last_run, (int) $fresh->last_run, 'A refused claim must leave last_run untouched.' );
	}

	/**
	 * A failure retry claimed in the same second the previous run closed
	 * must stamp last_run strictly after completed - otherwise the new
	 * run's close gate (completed < last_run) can never fire and the run
	 * wedges open forever.
	 */
	public function test_claim_after_same_second_failure_lands_after_close() {
		$close = time() + 2; // The previous close, at/after the claim's own clock second.
		$task  = $this->task_in_state(
			array(
				'last_run'      => $close,
				'completed'     => $close,
				'backup_errors' => 'Site: child. The database dump failed.',
			)
		);

		$this->assertTrue( MainWP_DB_Backup::instance()->claim_backup_run( (int) $task->id, 15 ) );

		$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertGreaterThan( (int) $fresh->completed, (int) $fresh->last_run, 'A reclaim must land strictly after the previous close.' );
		$this->assertTrue( MainWP_DB_Backup::instance()->update_backup_completed( (int) $task->id, (int) MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id )->last_run ), 'The reopened run must be closable.' );
	}

	/**
	 * The graceless cron bump gets the same guard: reopening a run whose
	 * close landed in the same clock second still stamps last_run past
	 * completed, keeping the close gate reachable.
	 */
	public function test_claim_grace_zero_same_second_lands_after_close() {
		$close = time() + 2;
		$task  = $this->task_in_state(
			array(
				'last_run'  => $close,
				'completed' => $close,
			)
		);

		$this->assertTrue( MainWP_DB_Backup::instance()->claim_backup_run( (int) $task->id, 0 ) );

		$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertGreaterThan( (int) $fresh->completed, (int) $fresh->last_run, 'The cron-path bump must also land strictly after the previous close.' );
	}

	/**
	 * A refused claim yields the whole execute path: no error recorded,
	 * no failure email, and - the observed damage - no second preflight
	 * or child task ever fires.
	 */
	public function test_execute_backup_task_run_yields_when_claim_refused() {
		update_option( 'mainwp_enableNativeBackups', 1 );

		$old_last_run = time() - 30;
		$task         = $this->task_in_state(
			array(
				'last_run'  => $old_last_run,
				'completed' => time() - 4,
			)
		);

		$child_calls = array();
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what ) use ( &$child_calls ) {
				unset( $website );
				$child_calls[] = $what;
				return is_array( $result ) ? $result : array( 'result' => 'ok' );
			},
			10,
			3
		);

		$sent = array();
		add_filter(
			'pre_wp_mail',
			function ( $short_circuit, $atts ) use ( &$sent ) {
				$sent[] = $atts;
				return true;
			},
			10,
			2
		);

		$result = MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, true, 15 );

		$this->assertFalse( $result, 'A refused claim must yield, not run.' );
		$this->assertSame( array(), $child_calls, 'A refused claim must reach no child site - no second preflight, no orphan task.' );
		$this->assertSame( array(), $sent, 'A refused claim must not email a failure.' );

		$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertSame( $old_last_run, (int) $fresh->last_run );
		$this->assertSame( '', (string) $fresh->backup_errors );
	}

	/**
	 * The close guard sees exactly one thing: a live creating marker from
	 * the current cycle. Real child task ids never block a close - a run
	 * that exhausted its retries must still be able to close - and neither
	 * do rows left over from earlier cycles or markers past their window.
	 */
	public function test_has_live_child_task_for_run() {
		$task  = $this->task_in_state( array( 'last_run' => time() - 100 ) );
		$wp_id = 990101;

		MainWP_DB_Backup::instance()->add_backup_task_progress( (int) $task->id, $wp_id, array() );

		// A fresh current-cycle marker is a create in flight.
		MainWP_DB_Backup::instance()->update_backup_task_progress(
			(int) $task->id,
			$wp_id,
			array(
				'dtsFetched'           => time(),
				'child_backup_task_id' => '__creating_claimtoken01',
			)
		);
		$this->assertTrue( MainWP_DB_Backup::instance()->has_live_child_task_for_run( (int) $task->id ), 'A fresh current-cycle marker must read as a live create.' );

		// A real, non-terminal child task id never blocks the close.
		MainWP_DB_Backup::instance()->update_backup_task_progress(
			(int) $task->id,
			$wp_id,
			array(
				'child_backup_task_id' => 'task-real-1',
				'child_phase'          => 'archiving_files',
			)
		);
		$this->assertFalse( MainWP_DB_Backup::instance()->has_live_child_task_for_run( (int) $task->id ), 'A real child task id must never block the close.' );

		// A marker stamped before this run opened belongs to an earlier cycle.
		MainWP_DB_Backup::instance()->update_backup_task_progress(
			(int) $task->id,
			$wp_id,
			array(
				'dtsFetched'           => (int) $task->last_run - 50,
				'child_backup_task_id' => '__creating_claimtoken02',
			)
		);
		$this->assertFalse( MainWP_DB_Backup::instance()->has_live_child_task_for_run( (int) $task->id ), 'A previous-cycle marker must not block this run\'s close.' );

		// A current-cycle marker past its window is abandoned, not live.
		MainWP_DB_Backup::instance()->update_backup_task_progress(
			(int) $task->id,
			$wp_id,
			array(
				'dtsFetched'           => time() - 61,
				'child_backup_task_id' => '__creating_claimtoken03',
			)
		);
		$this->assertFalse( MainWP_DB_Backup::instance()->has_live_child_task_for_run( (int) $task->id ), 'A marker past its window must not block the close.' );
	}
}
