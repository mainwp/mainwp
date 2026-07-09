<?php
/**
 * MainWP Backup Task Reset Handler Tests
 *
 * Invocation-level tests for mainwp_native_backup_task_reset: the
 * dashboard-side escape hatch for a scheduled backup task whose run
 * crashed and never advanced `completed` past `last_run`. Unlike
 * mainwp_native_backup_cancel (site-scoped, contacts the child) this is
 * task-scoped and dashboard-only - a scheduled task can span many sites,
 * and driving a multi-site child-side cancel is deferred to a later phase.
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
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_wp_backup" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup.
		delete_option( 'mainwp_enableNativeBackups' );
		unset( $_POST['security'], $_POST['task_id'], $_REQUEST['security'], $_REQUEST['task_id'] );
		parent::tearDown();
	}

	/**
	 * Skip a denial test when another suite in this process defined one of
	 * the constants that bypass capability checks by design (cron, WP-CLI,
	 * REST): the deny filter never fires and the endpoint's plain die()
	 * would kill the phpunit process.
	 *
	 * @return void
	 */
	private function skip_when_capabilities_bypassed() {
		if ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'MAINWP_REST_API_DOING' ) && MAINWP_REST_API_DOING ) ) {
			$this->markTestSkipped( 'Capability checks are bypassed by design under cron, WP-CLI, and REST constants; another suite defined one for this process.' );
		}
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
	 * Create a scheduled backup task over one site, with last_run/completed
	 * seeded so the run looks like it started $elapsed_seconds ago and
	 * never closed.
	 *
	 * @param int $site_id         Site id in the task's sites column.
	 * @param int $elapsed_seconds Seconds since the run opened.
	 *
	 * @return object Task row.
	 */
	private function create_running_task( $site_id, $elapsed_seconds ) {
		$user = get_current_user_id();
		$task = MainWP_DB_Backup::instance()->add_backup_task( $user, 'Nightly', 'daily', 'full', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
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
	 * the decoded JSON payload.
	 *
	 * @param int $task_id Task id to reset.
	 *
	 * @return array|null Decoded response.
	 */
	private function invoke_reset( $task_id ) {
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
	 * The reset never contacts a child site: a scheduled task can span
	 * many sites and driving a multi-site child-side cancel is deferred,
	 * so this is a dashboard-only bookkeeping reset.
	 *
	 * @return void
	 */
	public function test_reset_does_not_contact_the_child() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$site_id = $this->create_test_site();
		$task    = $this->create_running_task( $site_id, 2 * HOUR_IN_SECONDS );

		$called = false;
		$filter = function ( $result ) use ( &$called ) {
			$called = true;
			return $result;
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $filter, 10, 1 );

		$this->invoke_reset( $task->id );

		remove_filter( 'mainwp_fetch_url_authed_pre', $filter, 10 );

		$this->assertFalse( $called, 'The dashboard-side reset must not contact the child (multi-site cancel is deferred).' );
	}
}
