<?php
/**
 * MainWP Backup Task Reset Handler Tests
 *
 * Invocation-level tests for mainwp_native_backup_task_reset: the
 * dashboard-side escape hatch for a scheduled backup task whose run
 * crashed and never advanced `completed` past `last_run`. The reset also
 * drives a backup_task_cancel on every site in the task so the child-side
 * run actually stops; a site that cannot cancel (unreachable, child error,
 * or a live runner holding the lease) falls back to the dashboard-only
 * bookkeeping close.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Post_Backup_Handler;

/**
 * Class MainWP_Backup_Task_Reset_Handler_Test
 */
class MainWP_Backup_Task_Reset_Handler_Test extends MainWP_Abilities_Test_Case {

	use Backup_Cancel_Transport_Guard;

	/**
	 * Child backup_task_cancel calls captured during invoke_reset, each
	 * as array{site: int, params: array}.
	 *
	 * @var array
	 */
	private $child_calls = array();

	/**
	 * Set up: enable native backups.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		update_option( 'mainwp_enableNativeBackups', 1 );
	}

	/**
	 * Clean up rows and request state created by the tests.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_wp_backup_progress" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_wp_backup" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup.
		delete_option( 'mainwp_enableNativeBackups' );
		unset( $_POST['security'], $_POST['task_id'], $_REQUEST['security'], $_REQUEST['task_id'] );
		parent::tearDown();
	}

	/**
	 * Deny the per-site capability for one site id via the capability
	 * filter, simulating a Team Control site restriction.
	 *
	 * @param int $site_id Site id to deny.
	 *
	 * @return callable The filter, for later removal.
	 */
	private function deny_site( $site_id ) {
		$deny = function ( $allowed, $cap_type, $cap ) use ( $site_id ) {
			if ( 'site' === $cap_type && (int) $cap === (int) $site_id ) {
				return false;
			}
			return $allowed;
		};
		add_filter( 'mainwp_currentusercan', $deny, 10, 3 );
		return $deny;
	}

	/**
	 * Create a scheduled backup task over one or more sites, with
	 * last_run/completed seeded so the run looks like it started
	 * $elapsed_seconds ago and never closed.
	 *
	 * @param int|int[] $site_ids        Site id(s) for the task's sites column.
	 * @param int       $elapsed_seconds Seconds since the run opened.
	 *
	 * @return object Task row.
	 */
	private function create_running_task( $site_ids, $elapsed_seconds ) {
		$sites = implode( ',', array_map( 'intval', (array) $site_ids ) );
		$user  = get_current_user_id();
		$task  = MainWP_DB_Backup::instance()->add_backup_task( $user, 'Nightly', 'daily', 'full', '', $sites, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task, 'Precondition: the task row must insert.' );

		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			(int) $task->id,
			array(
				'last_run'  => time() - $elapsed_seconds,
				'completed' => time() - $elapsed_seconds - 10,
			)
		);

		return MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
	}

	/**
	 * Invoke the reset handler with the ajax-termination pattern and return
	 * the decoded JSON payload. Child backup_task_cancel calls are mocked
	 * through the mainwp_fetch_url_authed_pre short-circuit and captured
	 * into $this->child_calls.
	 *
	 * @param int        $task_id         Task id to reset.
	 * @param array|null $child_responses Per-site child responses keyed by
	 *                                    site id; a site with no entry (or
	 *                                    null) answers {result: ok}.
	 *
	 * @return array|null Decoded response.
	 */
	private function invoke_reset( $task_id, $child_responses = null ) {
		$this->child_calls = array();
		$capture           = function ( $result, $website, $what, $params ) use ( $child_responses ) {
			if ( 'backup_task_cancel' !== $what ) {
				return $result;
			}
			$site_id             = (int) $website->id;
			$this->child_calls[] = array(
				'site'   => $site_id,
				'params' => $params,
			);
			if ( is_array( $child_responses ) && array_key_exists( $site_id, $child_responses ) ) {
				if ( $child_responses[ $site_id ] instanceof \Exception ) {
					throw $child_responses[ $site_id ];
				}
				return $child_responses[ $site_id ];
			}
			return array( 'result' => 'ok' );
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $capture, 10, 4 );

		$nonce                = wp_create_nonce( 'mainwp_native_backup_task_reset' );
		$_POST['security']    = $nonce;
		$_REQUEST['security'] = $nonce;
		$_POST['task_id']     = $task_id;

		$throw = static function () {
			return static function () {
				throw new \RuntimeException( 'wp_die' );
			};
		};
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $throw );

		$raw = '';
		ob_start();
		try {
			MainWP_Post_Backup_Handler::instance()->mainwp_native_backup_task_reset();
		} catch ( \RuntimeException $e ) {
			// wp_send_json terminated the request as designed.
		} finally {
			$raw = ob_get_clean();
			remove_filter( 'mainwp_fetch_url_authed_pre', $capture, 10 );
			remove_filter( 'wp_die_ajax_handler', $throw );
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}

		return json_decode( $raw, true );
	}

	/**
	 * A stale task (elapsed past the executor's stall threshold) is closed
	 * by the reset: completed catches up to last_run so the next poll or
	 * schedule is no longer blocked.
	 *
	 * @return void
	 */
	public function test_reset_clears_stale_task() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$site_id = $this->create_test_site();
		$task    = $this->create_running_task( $site_id, 2 * HOUR_IN_SECONDS );

		$response = $this->invoke_reset( $task->id );

		$this->assertIsArray( $response );
		$this->assertSame( 'reset', $response['status'] ?? null );

		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertGreaterThanOrEqual( (int) $task->last_run, (int) $task->completed, 'The stuck run must be closed.' );
		$this->assertStringContainsString( 'reset', (string) $task->backup_notices, 'The reset must leave a diagnosable trace on the task.' );
	}

	/**
	 * A task that is running but not yet past the stall threshold is
	 * refused: the reset is an escape hatch for a confirmed-stale run, not
	 * a general-purpose way to interrupt an in-progress backup.
	 *
	 * @return void
	 */
	public function test_reset_refuses_task_not_yet_stale() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$site_id = $this->create_test_site();
		$task    = $this->create_running_task( $site_id, 10 * MINUTE_IN_SECONDS );

		$response = $this->invoke_reset( $task->id );

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'error', $response );

		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertLessThan( (int) $task->last_run, (int) $task->completed, 'A not-yet-stale run must stay open.' );
	}

	/**
	 * A Team Control site restriction refuses the reset even for a stale
	 * task, the same per-site authorization the snapshots-view cancel
	 * enforces via authorize_restore_site().
	 *
	 * @return void
	 */
	public function test_reset_denies_team_control_restricted_site() {
		$this->skip_when_capabilities_bypassed();

		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$site_id = $this->create_test_site();
		$task    = $this->create_running_task( $site_id, 2 * HOUR_IN_SECONDS );

		$deny     = $this->deny_site( $site_id );
		$response = null;
		try {
			$response = $this->invoke_reset( $task->id );
		} finally {
			remove_filter( 'mainwp_currentusercan', $deny, 10 );
		}

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'error', $response );

		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertLessThan( (int) $task->last_run, (int) $task->completed, 'A denied reset must not close the run.' );
	}

	/**
	 * The reset drives a backup_task_cancel on every site in the task,
	 * passing the child task id the Dashboard still holds (from the task's
	 * own progress row) and an empty id where the child must resolve its
	 * single active backup itself.
	 *
	 * @return void
	 */
	public function test_reset_cancels_the_run_on_each_child_site() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$site_a = $this->create_test_site();
		$site_b = $this->create_test_site();
		$task   = $this->create_running_task( array( $site_a, $site_b ), 2 * HOUR_IN_SECONDS );

		MainWP_DB_Backup::instance()->add_backup_task_progress( (int) $task->id, $site_a, array() );
		MainWP_DB_Backup::instance()->update_backup_task_progress( (int) $task->id, $site_a, array( 'child_backup_task_id' => 'child-task-a' ) );

		$response = $this->invoke_reset( $task->id );

		$this->assertIsArray( $response );
		$this->assertSame( 'reset', $response['status'] ?? null );
		$this->assertSame( 1, $response['cancelled'] ?? null );
		$this->assertSame( 1, $response['uncancelled'] ?? null );

		$by_site = array();
		foreach ( $this->child_calls as $call ) {
			$by_site[ $call['site'] ] = $call['params'];
		}
		$this->assertCount( 1, $by_site );
		$this->assertSame( 'child-task-a', $by_site[ $site_a ]['task_id'] ?? null, 'A known child task id must be passed through.' );
		$this->assertArrayNotHasKey( $site_b, $by_site );

		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertGreaterThanOrEqual( (int) $task->last_run, (int) $task->completed, 'The stuck run must be closed.' );
	}

	/**
	 * A site whose child cannot cancel (unreachable, error, or a live
	 * runner holding the lease) does not block the reset: the dashboard
	 * bookkeeping close is the fallback, and the outcome is recorded on
	 * the task so a partial stop is diagnosable.
	 *
	 * @return void
	 */
	public function test_reset_still_closes_task_when_children_cannot_cancel() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$site_a = $this->create_test_site();
		$site_b = $this->create_test_site();
		$site_c = $this->create_test_site();
		$task   = $this->create_running_task( array( $site_a, $site_b, $site_c ), 2 * HOUR_IN_SECONDS );

		foreach ( array( $site_a, $site_b, $site_c ) as $site_id ) {
			MainWP_DB_Backup::instance()->add_backup_task_progress( (int) $task->id, $site_id, array() );
			MainWP_DB_Backup::instance()->update_backup_task_progress( (int) $task->id, $site_id, array( 'child_backup_task_id' => 'child-' . $site_id ) );
		}

		$response = $this->invoke_reset(
			$task->id,
			array(
				$site_a => array( 'error' => 'child exploded' ),
				$site_b => array( 'result' => 'busy' ),
			)
		);

		$this->assertIsArray( $response );
		$this->assertSame( 'reset', $response['status'] ?? null );
		$this->assertSame( 1, $response['cancelled'] ?? null );
		$this->assertSame( 2, $response['uncancelled'] ?? null );

		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertGreaterThanOrEqual( (int) $task->last_run, (int) $task->completed, 'The reset must still close the run.' );
		$this->assertStringContainsString( 'could not be stopped', (string) $task->backup_notices, 'A partial stop must leave a diagnosable trace.' );
	}

	/**
	 * Only explicit terminal replies count as confirmed stops during reset.
	 *
	 * @return void
	 */
	public function test_reset_reply_table_and_unknown_identity() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$cases = array(
			array( 'known', array( 'result' => 'ok' ), true ),
			array(
				'known',
				array(
					'result' => 'already_terminal',
					'phase'  => 'completed',
				),
				true,
			),
			array(
				'known',
				array(
					'result' => 'already_terminal',
					'phase'  => 'failed',
				),
				true,
			),
			array(
				'known',
				array(
					'result' => 'already_terminal',
					'phase'  => 'other',
				),
				false,
			),
			array( 'known', array( 'result' => 'busy' ), false ),
			array( 'known', array( 'result' => 'no_active' ), false ),
			array( 'known', array( 'error' => 'bad' ), false ),
			array( 'known', array(), false ),
			array( 'known', 'bad', false ),
			array( 'known', array( 'result' => 'unknown' ), false ),
			array( 'known', new \RuntimeException( 'unreachable' ), false ),
			array( '', array( 'result' => 'ok' ), false ),
			array( '__creating_123', array( 'result' => 'ok' ), false ),
		);
		foreach ( $cases as list( $id, $reply, $confirmed ) ) {
			$site_id = $this->create_test_site();
			$task    = $this->create_running_task( $site_id, 2 * HOUR_IN_SECONDS );
			MainWP_DB_Backup::instance()->add_backup_task_progress( (int) $task->id, $site_id, array() );
			MainWP_DB_Backup::instance()->update_backup_task_progress( (int) $task->id, $site_id, array( 'child_backup_task_id' => $id ) );
			if ( in_array( $id, array( '', '__creating_123' ), true ) ) {
				$sibling = $this->create_running_task( $site_id, 2 * HOUR_IN_SECONDS );
				MainWP_DB_Backup::instance()->update_backup_task_with_values( (int) $sibling->id, array( 'paused' => 1 ) );
				MainWP_DB_Backup::instance()->add_backup_task_progress( (int) $sibling->id, $site_id, array() );
				MainWP_DB_Backup::instance()->update_backup_task_progress( (int) $sibling->id, $site_id, array( 'child_backup_task_id' => 'sibling' ) );
				$db = \MainWP\Dashboard\MainWP_DB::instance();
				$db->update_website_option( $site_id, \MainWP\Dashboard\MainWP_Backup_Executor::ADHOC_TASK_OPTION, '{"task_id":"gate-owned","owner":"update-gate"}' );
				$deny = function () {
					$this->fail( 'Unknown run identity must never target another lane.' );
				};
				add_filter( 'mainwp_fetch_url_authed_pre', $deny );
				try {
					$reset = \MainWP\Dashboard\MainWP_Backup_Operations::reset_task_run( (int) $task->id );
				} finally {
					remove_filter( 'mainwp_fetch_url_authed_pre', $deny );
				}
				$this->assertSame( array( $site_id ), $reset['sites_uncancelled'] );
				$this->assertGreaterThanOrEqual( (int) $task->last_run, (int) MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id )->completed );
				$this->assertSame( 'sibling', MainWP_DB_Backup::instance()->get_backup_task_progress( (int) $sibling->id, $site_id )->child_backup_task_id );
				$this->assertSame( '{"task_id":"gate-owned","owner":"update-gate"}', $db->get_website_option( $site_id, \MainWP\Dashboard\MainWP_Backup_Executor::ADHOC_TASK_OPTION ) );
				MainWP_DB_Backup::instance()->update_backup_task_with_values( (int) $task->id, array( 'completed' => (int) $task->completed ) );
				$db->remove_website_option( $site_id, array( \MainWP\Dashboard\MainWP_Backup_Executor::ADHOC_TASK_OPTION ) );
			}
			$response = $this->invoke_reset( $task->id, array( $site_id => $reply ) );
			$this->assertSame( $confirmed ? 1 : 0, $response['cancelled'] );
			$this->assertSame( $confirmed ? 0 : 1, $response['uncancelled'] );
			$this->assertCount( in_array( $id, array( '', '__creating_123' ), true ) ? 0 : 1, $this->child_calls );
			$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
			$this->assertGreaterThanOrEqual( (int) $fresh->last_run, (int) $fresh->completed );
			$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( (int) $task->id, $site_id );
			$this->assertSame( $confirmed ? '' : $id, $progress->child_backup_task_id );
		}
	}
}
