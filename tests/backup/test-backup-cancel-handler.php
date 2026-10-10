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

	use Backup_Cancel_Transport_Guard;

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
	 * Child errors must not expose the secret directory that protects backups.
	 *
	 * @return void
	 */
	public function test_cancel_child_error_redacts_backup_directory() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		list( $site_id, ) = $this->make_in_flight_run();

		list( $response, ) = $this->invoke_cancel(
			$site_id,
			array( 'error' => 'Unable to create directory wp-content/uploads/mainwp/backup-tnQjEHx99TA2ZYTh/. Is its parent directory writable by the server?' )
		);

		$this->assertIsArray( $response );
		$this->assertNotEmpty( $response['error'] ?? '' );
		$this->assertStringNotContainsString( 'tnQjEHx99TA2ZYTh', (string) ( $response['error'] ?? '' ) );
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
		$this->assertSame( \MainWP\Dashboard\MainWP_Backup_Operations::safe_error( 'mainwp_operation_failed' )['message'], $response['error'] );

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
		$this->assertSame( 'busy', $response['result'] ?? null );

		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertLessThan( (int) $task->last_run, (int) $task->completed, 'A busy cancel must not close the local run.' );

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( (int) $task->id, $site_id );
		$this->assertSame( 'child-task-123', (string) $progress->child_backup_task_id, 'A busy cancel must keep the live child task id on the row.' );
	}

	/**
	 * Every Child reply has an explicit local transition in both entry points.
	 *
	 * @return void
	 */
	public function test_cancel_reply_table_service_and_ajax() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$cases = array(
			array( array( 'result' => 'ok' ), 'cancelled' ),
			array(
				array(
					'result' => 'already_terminal',
					'phase'  => 'completed',
				),
				'already_finished',
			),
			array(
				array(
					'result' => 'already_terminal',
					'phase'  => 'failed',
				),
				'already_failed',
			),
			array( array( 'result' => 'busy' ), 'busy' ),
			array( array( 'result' => 'no_active' ), 'no_active_on_site' ),
			array(
				array(
					'result' => 'already_terminal',
					'phase'  => 'waiting',
				),
				null,
			),
			array( array( 'error' => 'secret' ), null ),
			array( array(), null ),
			array( array( 'result' => 'unknown' ), null ),
			array( array( 'result' => true ), null ),
			array(
				array(
					'result' => 'ok',
					'error'  => null,
				),
				null,
			),
			array(
				array(
					'result' => 'already_terminal',
					'phase'  => array(),
				),
				null,
			),
			array( 'invalid', null ),
			array( new \RuntimeException( 'secret' ), null ),
		);
		foreach ( $cases as list( $reply, $expected ) ) {
			foreach ( array( false, true ) as $ajax ) {
				list( $site_id, $task ) = $this->make_in_flight_run();
				$calls                  = 0;
				$stub                   = function ( $value, $site, $what ) use ( $reply, &$calls ) {
					if ( 'backup_task_cancel' !== $what ) {
						return $value;
					}
					++$calls;
					if ( $reply instanceof \Exception ) {
						throw $reply;
					}
					return $reply;
				};
				add_filter( 'mainwp_fetch_url_authed_pre', $stub, 20, 3 );
				try {
					$response = $ajax ? $this->invoke_cancel( $site_id, array() )[0] : \MainWP\Dashboard\MainWP_Backup_Operations::cancel_site_backup( $site_id );
				} finally {
					remove_filter( 'mainwp_fetch_url_authed_pre', $stub, 20 );
				}
				$this->assertSame( 1, $calls );
				if ( null === $expected ) {
					if ( $ajax ) {
						$this->assertNotEmpty( $response['error'] );
					} else {
						$this->assertWPError( $response );
						$this->assertSame( 'mainwp_operation_failed', $response->get_error_code() );
						$this->assertSame( 502, $response->get_error_data()['status'] );
					}
				} else {
					$this->assertSame( $expected, $response['result'] );
					$this->assertSame( 'cancelled' === $expected, 'cancelled' === $response['status'] );
				}
				$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
				$this->assertSame( 'cancelled' === $expected, (int) $fresh->completed >= (int) $fresh->last_run );
				$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( (int) $task->id, $site_id );
				$this->assertSame( 'cancelled' === $expected ? '' : 'child-task-123', $progress->child_backup_task_id );
			}
		}
	}

	/**
	 * Unknown ownership cannot target a sibling task or an update-gate handle.
	 *
	 * @return void
	 */
	public function test_cancel_unknown_identity_and_closed_run_never_send() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		foreach ( array( '', '__creating_123', 'closed' ) as $identity ) {
			list( $site_id, $task ) = $this->make_in_flight_run();
			MainWP_DB_Backup::instance()->update_backup_task_progress( (int) $task->id, $site_id, array( 'child_backup_task_id' => 'closed' === $identity ? 'child-task-123' : $identity ) );
			if ( 'closed' === $identity ) {
				MainWP_DB_Backup::instance()->update_backup_task_with_values( (int) $task->id, array( 'completed' => time() + 1 ) );
			}
			$sibling = MainWP_DB_Backup::instance()->add_backup_task( get_current_user_id(), 'Paused sibling', 'daily', 'full', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
			MainWP_DB_Backup::instance()->update_backup_task_with_values( (int) $sibling->id, array( 'paused' => 1 ) );
			MainWP_DB_Backup::instance()->add_backup_task_progress( (int) $sibling->id, $site_id, array() );
			MainWP_DB_Backup::instance()->update_backup_task_progress( (int) $sibling->id, $site_id, array( 'child_backup_task_id' => 'sibling' ) );
			MainWP_DB::instance()->update_website_option(
				$site_id,
				\MainWP\Dashboard\MainWP_Backup_Executor::ADHOC_TASK_OPTION,
				wp_json_encode(
					array(
						'task_id' => 'gate-owned',
						'owner'   => 'update-gate',
					)
				)
			);
			$before  = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
			$service = \MainWP\Dashboard\MainWP_Backup_Operations::cancel_site_backup( $site_id );
			$this->assertSame( 'closed' === $identity ? 'not_running' : 'busy', $service['result'] );
			$this->assertSame( $before->completed, MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id )->completed );
			list( $response, $params ) = $this->invoke_cancel( $site_id, array( 'result' => 'ok' ) );
			$this->assertNull( $params );
			$this->assertSame( 'closed' === $identity ? 'not_running' : 'busy', $response['result'] );
			MainWP_DB::instance()->remove_website_option( $site_id, array( \MainWP\Dashboard\MainWP_Backup_Executor::ADHOC_TASK_OPTION ) );
		}
	}

	/**
	 * The transport rejects both unknown and provisional identities itself.
	 *
	 * @return void
	 */
	public function test_guarded_cancel_transport_rejects_unknown_identity() {
		$website = MainWP_DB::instance()->get_website_by_id( $this->create_test_site() );
		foreach ( array( '', '__creating_123' ) as $id ) {
			$this->assertNull( \MainWP\Dashboard\MainWP_Backup_Executor::send_backup_task_cancel( $website, $id ) );
		}
	}
}
