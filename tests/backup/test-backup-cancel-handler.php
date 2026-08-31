<?php
/**
 * MainWP Backup Cancel Handler Tests
 *
 * Invocation-level tests for mainwp_native_backup_cancel: the child error
 * surface, the local run bookkeeping, and the diagnostic trace a cancel
 * leaves behind so support can tell a cancelled run from a completed one.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Post_Backup_Handler;

/**
 * Class MainWP_Backup_Cancel_Handler_Test
 */
class MainWP_Backup_Cancel_Handler_Test extends MainWP_Abilities_Test_Case {

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
		unset( $_POST['security'], $_POST['site_id'], $_REQUEST['security'], $_REQUEST['site_id'] );
		parent::tearDown();
	}

	/**
	 * Create a site with an in-flight on-demand run whose progress row holds
	 * a known child task id.
	 *
	 * @return array{0:int,1:object} Site id and the manual task row.
	 */
	private function make_in_flight_run() {
		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$task    = MainWP_DB_Backup::instance()->ensure_manual_backup_task( $website );
		$this->assertNotEmpty( $task, 'Precondition: the on-demand task must exist.' );

		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			(int) $task->id,
			array(
				'last_run'  => time(),
				'completed' => time() - 500,
			)
		);
		MainWP_DB_Backup::instance()->add_backup_task_progress( (int) $task->id, $site_id, array() );
		MainWP_DB_Backup::instance()->update_backup_task_progress( (int) $task->id, $site_id, array( 'child_backup_task_id' => 'child-task-123' ) );

		return array( $site_id, MainWP_DB_Backup::instance()->get_manual_backup_task( $site_id ) );
	}

	/**
	 * Invoke the cancel handler with a mocked child response and return the
	 * decoded JSON payload plus the params sent to the child.
	 *
	 * @param int   $site_id        Site to cancel on.
	 * @param array $child_response Mocked backup_task_cancel response.
	 *
	 * @return array{0:array|null,1:array|null} Decoded response and captured child params.
	 */
	private function invoke_cancel( $site_id, $child_response ) {
		$captured = array( 'params' => null );
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what, $params ) use ( &$captured, $child_response ) {
				unset( $website );
				if ( 'backup_task_cancel' === $what ) {
					$captured['params'] = $params;
					return $child_response;
				}
				return $result;
			},
			10,
			4
		);

		$nonce                = wp_create_nonce( 'mainwp_native_backup_cancel' );
		$_POST['security']    = $nonce;
		$_REQUEST['security'] = $nonce;
		$_POST['site_id']     = $site_id;

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
			MainWP_Post_Backup_Handler::instance()->mainwp_native_backup_cancel();
		} catch ( \RuntimeException $e ) {
			// wp_send_json terminated the request as designed.
		} finally {
			$raw = ob_get_clean();
			remove_filter( 'wp_die_ajax_handler', $throw );
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}

		return array( json_decode( $raw, true ), $captured['params'] );
	}

	/**
	 * A successful cancel passes the known child task id, closes the local
	 * run, and records a cancelled note on the task so the run's outcome is
	 * diagnosable later instead of reading like an ordinary completion.
	 *
	 * @return void
	 */
	public function test_cancel_success_closes_run_and_records_trace() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		list( $site_id, $task ) = $this->make_in_flight_run();

		list( $response, $params ) = $this->invoke_cancel( $site_id, array( 'result' => 'ok' ) );

		$this->assertIsArray( $response );
		$this->assertSame( 'cancelled', $response['status'] ?? null );
		$this->assertSame( 'child-task-123', $params['task_id'] ?? null, 'The known child task id must be passed for a precise cancel.' );

		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertGreaterThanOrEqual( (int) $task->last_run, (int) $task->completed, 'The local run must be closed.' );
		$this->assertStringContainsString( 'cancelled', (string) $task->backup_notices, 'The cancel must leave a diagnosable trace on the task.' );
	}

	/**
	 * A successful cancel clears the progress row's child task id and
	 * phase, so the next progress poll projects idle instead of the
	 * cancelled task's "running / Waiting to start" forever.
	 *
	 * @return void
	 */
	public function test_cancel_success_clears_progress_projection() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		list( $site_id, $task ) = $this->make_in_flight_run();
		MainWP_DB_Backup::instance()->update_backup_task_progress( (int) $task->id, $site_id, array( 'child_phase' => 'dumping_db' ) );

		$this->invoke_cancel( $site_id, array( 'result' => 'ok' ) );

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( (int) $task->id, $site_id );
		$this->assertSame( '', (string) $progress->child_backup_task_id, 'A successful cancel must clear the child task id from the row.' );
		$this->assertSame( '', (string) $progress->child_phase, 'A successful cancel must clear the phase so the row projects idle.' );

		$response = MainWP_Post_Backup_Handler::build_task_progress_response( $progress );
		$this->assertSame( 'idle', $response['status'], 'A cancelled run must project idle to the progress poll.' );
	}

	/**
	 * A refused cancel leaves the progress row untouched: the child task
	 * is still live, so the poll must keep reporting it.
	 *
	 * @return void
	 */
	public function test_cancel_child_error_keeps_progress_row() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		list( $site_id, $task ) = $this->make_in_flight_run();

		$this->invoke_cancel( $site_id, array( 'error' => 'The backup is mid-slice; try again shortly.' ) );

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( (int) $task->id, $site_id );
		$this->assertSame( 'child-task-123', (string) $progress->child_backup_task_id, 'A refused cancel must keep the live child task id on the row.' );
	}

	/**
	 * A child-side refusal is surfaced verbatim and the local run stays open:
	 * the child task was not cancelled, so the dashboard must not pretend the
	 * run ended.
	 *
	 * @return void
	 */
	public function test_cancel_child_error_is_surfaced_and_run_stays_open() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		list( $site_id, $task ) = $this->make_in_flight_run();

		list( $response, ) = $this->invoke_cancel( $site_id, array( 'error' => 'The backup is mid-slice; try again shortly.' ) );

		$this->assertIsArray( $response );
		$this->assertStringContainsString( 'mid-slice', (string) ( $response['error'] ?? '' ), 'The child refusal must reach the user.' );

		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertLessThan( (int) $task->last_run, (int) $task->completed, 'A refused cancel must not close the local run.' );
	}

	/**
	 * A "busy" result is the child refusing because a live runner still owns
	 * the lease mid-slice. The backup is still running, so the handler must
	 * report that instead of closing the run and clearing the projection.
	 *
	 * @return void
	 */
	public function test_cancel_busy_result_is_reported_and_run_stays_open() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		list( $site_id, $task ) = $this->make_in_flight_run();

		list( $response, ) = $this->invoke_cancel( $site_id, array( 'result' => 'busy' ) );

		$this->assertIsArray( $response );
		$this->assertNotSame( 'cancelled', $response['status'] ?? null, 'A busy child must not read as a completed cancel.' );
		$this->assertNotEmpty( $response['error'] ?? '', 'A busy child must surface an error the user can act on.' );

		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertLessThan( (int) $task->last_run, (int) $task->completed, 'A busy cancel must not close the local run.' );

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( (int) $task->id, $site_id );
		$this->assertSame( 'child-task-123', (string) $progress->child_backup_task_id, 'A busy cancel must keep the live child task id on the row.' );
	}
}
