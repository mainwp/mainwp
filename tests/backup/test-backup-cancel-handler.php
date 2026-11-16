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

	/** Verify both transfer cancellation outcomes fence a late worker and permit retry. */
	public function test_cancel_download_fences_worker_and_allows_retry() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		foreach ( array(
			array( 'result' => 'ok' ),
			array(
				'result' => 'already_terminal',
				'phase'  => 'completed',
			),
		) as $reply ) {
			list( $site_id, $task ) = $this->make_in_flight_run();
			$db                     = MainWP_DB_Backup::instance();
			$db->update_backup_task_progress(
				(int) $task->id,
				$site_id,
				array(
					'child_phase'            => 'completed',
					'downloadedFULL'         => '/interrupted/archive.zip',
					'downloadedFULLComplete' => 0,
				)
			);
			list( $response ) = $this->invoke_cancel( $site_id, $reply );
			$this->assertSame( 'cancelled', $response['result'] );
			if ( 'already_terminal' === $reply['result'] ) {
				$pending = $this->pending_manual_child_prunes( $site_id );
				$this->assertSame( array( 'child-task-123' ), $pending );
			}
			$this->assertNotSame( 'running', \MainWP\Dashboard\MainWP_Backup_Read_Model::get_site_backup_progress( $site_id )['status'] );
			$this->assert_worker_refused( $task, $site_id );
			$started = 0;
			$this->assertTrue( $db->claim_backup_run( $task->id, 30, $started ), 'Cancelled runs must allow immediate retry.' );
			$next = $db->get_backup_task_by_id( $task->id );
			$this->assertGreaterThan( (int) $task->last_run, (int) $next->last_run );
			$db->update_backup_task_progress( (int) $task->id, $site_id, array( 'child_backup_task_id' => 'next-child-task' ) );
			$this->assert_worker_refused( $task, $site_id );
			$this->assertSame( 'next-child-task', $db->get_backup_task_progress( (int) $task->id, $site_id )->child_backup_task_id );
			$this->assertSame(
				42,
				\MainWP\Dashboard\MainWP_Backup_Executor::with_run_publication(
					$next,
					static function () {
						return 42;
					}
				)
			);
			$this->assertTrue( $db->update_backup_completed( $task->id, (int) $next->last_run ) );
		}
	}

	/**
	 * Exercise the progress publisher used by returning downloads.
	 *
	 * @param object $task Original run.
	 * @param int    $site_id Site identity.
	 */
	private function assert_worker_refused( $task, $site_id ) {
		$method = new \ReflectionMethod( \MainWP\Dashboard\MainWP_Backup_Executor::class, 'publish_run_progress' );
		$method->setAccessible( true );
		try {
			$method->invoke( null, $task, 'update_backup_task_progress', array( $task->id, $site_id, array( 'downloadedFULLComplete' => 1 ) ) );
			$this->fail( 'A cancelled or superseded downloader published its result.' );
		} catch ( \MainWP\Dashboard\MainWP_Exception $error ) {
			$this->assertSame( \MainWP\Dashboard\MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING, $error->get_message_error_code() );
		}
		try {
			$method->invoke( null, $task, 'claim_backup_fetch_result', array( $task->id, $site_id, wp_json_encode( array( 'full' => 'stale.zip' ) ) ) );
			$this->fail( 'A stale completed response claimed the successor artifact.' );
		} catch ( \MainWP\Dashboard\MainWP_Exception $error ) {
			$this->assertSame( \MainWP\Dashboard\MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING, $error->get_message_error_code() );
		}
		$published = false;
		$archive   = wp_tempnam( 'mainwp-cancelled-worker' );
		$this->assertFileExists( $archive );
		try {
			\MainWP\Dashboard\MainWP_Backup_Executor::with_run_publication(
				$task,
				static function () use ( &$published, $archive ) {
					$published = true;
					wp_delete_file( $archive );
				}
			);
			$this->fail( 'A stale worker reached snapshot publication.' );
		} catch ( \MainWP\Dashboard\MainWP_Exception $error ) {
			$this->assertFalse( $published );
			$this->assertFileExists( $archive, 'A stale worker must not delete the archive.' );
		} finally {
			wp_delete_file( $archive );
		}
		$retry = new \ReflectionMethod( \MainWP\Dashboard\MainWP_Backup_Executor::class, 'requeue_incremental_after_base_missing' );
		$retry->setAccessible( true );
		try {
			$retry->invoke( null, (object) array( 'id' => $site_id ), $task, (object) array( 'last_error' => '' ) );
			$this->fail( 'A stale incremental retry reset the progress row.' );
		} catch ( \MainWP\Dashboard\MainWP_Exception $error ) {
			$this->assertSame( \MainWP\Dashboard\MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING, $error->get_message_error_code() );
		}
	}

	/** A failed close preserves the archive and trace until a successful retry. */
	public function test_failed_cancel_close_preserves_child_identity_for_retry() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		list( $site_id, $task ) = $this->make_in_flight_run();
		$db                     = MainWP_DB_Backup::instance();
		$archive                = wp_tempnam( 'mainwp-failed-cancel' );
		$this->assertFileExists( $archive );
		$db->update_backup_task_progress(
			$task->id,
			$site_id,
			array(
				'downloadedFULL'         => $archive,
				'downloadedFULLComplete' => 0,
			)
		);
		$reply        = array(
			'result' => 'already_terminal',
			'phase'  => 'completed',
		);
		$refuse_close = static function ( $sql ) {
			return false !== strpos( $sql, 'SET backup.completed = GREATEST' ) ? 'UPDATE ' . MainWP_DB::instance()->get_table_name( 'wp_backup' ) . ' SET completed = completed WHERE id = -1' : $sql;
		};
		add_filter( 'query', $refuse_close );
		try {
			list( $response ) = $this->invoke_cancel( $site_id, $reply );
			$this->assertSame( 'busy', $response['result'] );
			$this->assertSame( 'child-task-123', $db->get_backup_task_progress( $task->id, $site_id )->child_backup_task_id );
			$current = $db->get_backup_task_by_id( $task->id );
			$this->assertLessThan( (int) $current->last_run, (int) $current->completed );
			$this->assertSame( (string) $task->backup_errors, (string) $current->backup_errors );
			$this->assertSame( (string) $task->backup_notices, (string) $current->backup_notices );
			$this->assertEmpty( $this->pending_manual_child_prunes( $site_id ), 'An open run must retain its Child archive.' );
			$this->assertFileExists( $archive, 'A failed close must retain the incomplete local archive.' );
		} finally {
			remove_filter( 'query', $refuse_close );
			wp_delete_file( $archive );
		}
		list( $response ) = $this->invoke_cancel( $site_id, $reply );
		$this->assertSame( 'cancelled', $response['result'] );
		$current = $db->get_backup_task_by_id( $task->id );
		$this->assertSame( 1, substr_count( (string) $current->backup_errors, 'Backup cancelled from the dashboard.' ) );
		$this->assertSame( 1, substr_count( (string) $current->backup_notices, 'Backup cancelled from the dashboard.' ) );
		$pending = $this->pending_manual_child_prunes( $site_id );
		$this->assertSame( array( 'child-task-123' ), $pending );
		$this->assert_worker_refused( $task, $site_id );
		list( $response ) = $this->invoke_cancel( $site_id, $reply );
		$this->assertSame( 'not_running', $response['result'] );
		$this->assertSame( (string) $current->backup_errors, (string) $db->get_backup_task_by_id( $task->id )->backup_errors );
	}

	/** A refused cleanup slot leaves closure and the Child identity retryable. */
	public function test_cancel_retries_when_cleanup_cannot_be_saved() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		list( $site_id, $task ) = $this->make_in_flight_run();
		$db                     = MainWP_DB_Backup::instance();
		$reply                  = array(
			'result' => 'already_terminal',
			'phase'  => 'completed',
		);
		$refused                = false;
		$refuse_insert          = static function ( $sql ) use ( &$refused ) {
			if ( str_starts_with( $sql, 'INSERT INTO' ) && str_contains( $sql, "'backup_manual_child_prune'" ) ) {
				$refused = true;
				return 'UPDATE ' . MainWP_DB::instance()->get_table_name( 'wp_options' ) . ' SET value = value WHERE opt_id = -1';
			}
			return $sql;
		};
		add_filter( 'query', $refuse_insert );
		try {
			$this->assertSame( 'busy', $this->invoke_cancel( $site_id, $reply )[0]['result'] );
		} finally {
			remove_filter( 'query', $refuse_insert );
		}
		$this->assertTrue( $refused );
		$this->assertSame( (int) $task->completed, (int) $db->get_backup_task_by_id( $task->id )->completed );
		$this->assertSame( 'child-task-123', $db->get_backup_task_progress( $task->id, $site_id )->child_backup_task_id );
		$this->assertSame( array(), $this->pending_manual_child_prunes( $site_id ) );
		$this->assertSame( 'cancelled', $this->invoke_cancel( $site_id, $reply )[0]['result'] );
		$this->assertSame( array( 'child-task-123' ), $this->pending_manual_child_prunes( $site_id ) );
	}

	/** A terminal failed Child reply must retry local persistence before closing. */
	public function test_failed_child_reply_retries_failed_local_close() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		list( $site_id, $task ) = $this->make_in_flight_run();
		$db                     = MainWP_DB_Backup::instance();
		$reply                  = array(
			'result' => 'already_terminal',
			'phase'  => 'failed',
		);
		$refuse_close           = static function ( $sql ) {
			return false !== strpos( $sql, 'SET backup.completed = GREATEST' ) ? 'UPDATE ' . MainWP_DB::instance()->get_table_name( 'wp_backup' ) . ' SET completed = completed WHERE id = -1' : $sql;
		};
		add_filter( 'query', $refuse_close );
		try {
			$this->assertSame( 'busy', $this->invoke_cancel( $site_id, $reply )[0]['result'] );
			$current = $db->get_backup_task_by_id( $task->id );
			$this->assertLessThan( (int) $current->last_run, (int) $current->completed );
			$this->assertSame( (string) $task->backup_errors, (string) $current->backup_errors );
			$this->assertSame( 'child-task-123', $db->get_backup_task_progress( $task->id, $site_id )->child_backup_task_id );
			$this->assertEmpty( $this->pending_manual_child_prunes( $site_id ) );
		} finally {
			remove_filter( 'query', $refuse_close );
		}
		$this->assertSame( 'already_failed', $this->invoke_cancel( $site_id, $reply )[0]['result'] );
		$current = $db->get_backup_task_by_id( $task->id );
		$this->assertGreaterThanOrEqual( (int) $current->last_run, (int) $current->completed );
		$this->assertSame( 1, substr_count( (string) $current->backup_errors, 'Backup cancelled from the dashboard.' ) );
		$this->assertSame( '', $db->get_backup_task_progress( $task->id, $site_id )->child_backup_task_id );
		$pending = $this->pending_manual_child_prunes( $site_id );
		$this->assertSame( array( 'child-task-123' ), $pending, 'A closed failed Child task must remain owned until pruning.' );
		$this->assert_worker_refused( $task, $site_id );
	}

	/** A publisher must release ownership before cancellation clears its run. */
	public function test_cancel_waits_for_local_publisher() {
		global $wpdb;
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		list( $site_id, $task ) = $this->make_in_flight_run();
		$other                  = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$key                    = 'mainwp-backup-' . md5( DB_NAME . ':' . $wpdb->prefix . ':' . (int) $task->id );
		$this->assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $key ) ) );
		try {
			list( $response ) = $this->invoke_cancel(
				$site_id,
				array(
					'result' => 'already_terminal',
					'phase'  => 'completed',
				)
			);
			$this->assertSame( 'busy', $response['result'] );
			$current = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
			$this->assertLessThan( (int) $current->last_run, (int) $current->completed );
		} finally {
			$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
			$other->close();
		}
		list( $response ) = $this->invoke_cancel(
			$site_id,
			array(
				'result' => 'already_terminal',
				'phase'  => 'completed',
			)
		);
		$this->assertSame( 'cancelled', $response['result'] );
	}

	/**
	 * A snapshot published before cancellation keeps its Child artifacts.
	 *
	 * @dataProvider published_snapshot_identities
	 * @param bool $legacy Whether the snapshot predates durable run keys.
	 */
	public function test_cancel_preserves_already_published_snapshot_artifacts( bool $legacy ) {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		list( $site_id, $task ) = $this->make_in_flight_run();
		$snapshots              = \MainWP\Dashboard\MainWP_Backup_Snapshots::instance();
		$record                 = array(
			'task_id' => $task->id,
			'site_id' => $site_id,
		);
		if ( $legacy ) {
			$archive_path       = '/backup/published.zip';
			$record['archives'] = array(
				array(
					'file' => 'published.zip',
					'size' => 123,
					'role' => 'full',
					'path' => $archive_path,
				),
			);
			MainWP_DB_Backup::instance()->update_backup_task_progress( $task->id, $site_id, array( 'downloadedFULL' => $archive_path ) );
		} else {
			$record['run_key'] = \MainWP\Dashboard\MainWP_Backup_Snapshots::child_run_key( $site_id, (int) $task->id, 'child-task-123' );
		}
		$id = $snapshots->record_snapshot( $record );
		$this->assertGreaterThan( 0, $id );
		list( $response ) = $this->invoke_cancel(
			$site_id,
			array(
				'result' => 'already_terminal',
				'phase'  => 'completed',
			)
		);
		$this->assertSame( 'cancelled', $response['result'] );
		$this->assertEmpty( $this->pending_manual_child_prunes( $site_id ) );
		$this->assertNotNull( $snapshots->get_snapshot_by_id( $id ) );
	}

	/**
	 * Published snapshot identity variants.
	 *
	 * @return array
	 */
	public static function published_snapshot_identities(): array {
		return array(
			'Child task key' => array( false ),
			'legacy path'    => array( true ),
		);
	}

	/** A same-second snapshot from another run leaves this Child task eligible for cleanup. */
	public function test_cancel_prunes_only_the_unpublished_child_task() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		list( $site_id, $task ) = $this->make_in_flight_run();
		$db                     = MainWP_DB_Backup::instance();
		$snapshots              = \MainWP\Dashboard\MainWP_Backup_Snapshots::instance();
		$snapshot_id            = $snapshots->record_snapshot(
			array(
				'task_id' => $task->id,
				'site_id' => $site_id,
				'run_key' => \MainWP\Dashboard\MainWP_Backup_Snapshots::child_run_key( $site_id, (int) $task->id, 'previous-child' ),
			)
		);
		$snapshot               = $snapshots->get_snapshot_by_id( $snapshot_id );
		$this->assertNotNull( $snapshot );
		$db->update_backup_task_with_values(
			$task->id,
			array(
				'last'      => (int) $snapshot->created,
				'last_run'  => (int) $snapshot->created + 1,
				'completed' => (int) $snapshot->created,
			)
		);
		$reply = array(
			'result' => 'already_terminal',
			'phase'  => 'completed',
		);
		$this->assertSame( 'cancelled', $this->invoke_cancel( $site_id, $reply )[0]['result'] );
		$started = 0;
		$this->assertTrue( $db->claim_backup_run( $task->id, 30, $started ) );
		$db->update_backup_task_progress( $task->id, $site_id, array( 'child_backup_task_id' => 'successor-child' ) );

		$requests = array();
		$prune    = static function ( $result, $site, $action, $params ) use ( &$requests ) {
			if ( 'backup_task_prune' !== $action ) {
				return $result;
			}
			$requests[] = $params;
			if ( 1 === count( $requests ) ) {
				return array( 'result' => 'busy' );
			}
			return array(
				'result'  => 'ok',
				'pruned'  => 1,
				'active'  => 0,
				'missing' => 0,
			);
		};
		$website  = MainWP_DB::instance()->get_website_by_id( $site_id );
		add_filter( 'mainwp_fetch_url_authed_pre', $prune, 20, 4 );
		try {
			\MainWP\Dashboard\MainWP_Manage_Backups_Handler::flush_pending_child_cancel( $website );
			$this->assertSame( array( 'child-task-123' ), $this->pending_manual_child_prunes( $site_id ) );
			\MainWP\Dashboard\MainWP_Manage_Backups_Handler::flush_pending_child_cancel( $website );
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $prune, 20 );
		}
		$expected = array(
			'task_ids' => wp_json_encode( array( 'child-task-123' ) ),
			'sweep'    => 0,
		);
		$this->assertSame( array( $expected, $expected ), $requests );
		$this->assertSame( array(), $this->pending_manual_child_prunes( $site_id ) );
		$this->assertSame( 'successor-child', $db->get_backup_task_progress( $task->id, $site_id )->child_backup_task_id );
		$this->assertNotNull( $snapshots->get_snapshot_by_id( $snapshot_id ) );
	}

	/**
	 * Read pending terminal Child tasks for assertions.
	 *
	 * @param int $site_id Site identity.
	 * @return string[]
	 */
	private function pending_manual_child_prunes( int $site_id ): array {
		return array_column( MainWP_DB_Backup::instance()->get_pending_manual_child_prunes( $site_id ), 'value' );
	}

	/** Never-claimed tasks retain their existing creation admission. */
	public function test_unclaimed_manual_task_can_publish_initial_progress() {
		list( $site_id, $task ) = $this->make_in_flight_run();
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			$task->id,
			array(
				'last_run'  => 0,
				'completed' => 0,
			)
		);
		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertTrue(
			\MainWP\Dashboard\MainWP_Backup_Executor::with_run_publication(
				$task,
				static function () {
					return true;
				}
			)
		);
		$method = new \ReflectionMethod( \MainWP\Dashboard\MainWP_Backup_Executor::class, 'task_run_accepts_create' );
		$method->setAccessible( true );
		$this->assertTrue( $method->invoke( null, $task ) );
	}

	/** Publication failures survive releasing the mutex. */
	public function test_publication_release_preserves_database_write_errors() {
		global $wpdb;
		list( $site_id, $task ) = $this->make_in_flight_run();
		$previous               = $wpdb->suppress_errors( true );
		$error                  = '';
		try {
			$result = \MainWP\Dashboard\MainWP_Backup_Executor::with_run_publication(
				$task,
				static function () use ( $wpdb, &$error ) {
					$result = $wpdb->query( 'UPDATE mwp2187_missing_publication_table SET id = 0' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- inject a real write failure.
					$error  = $wpdb->last_error;
					return $result;
				}
			);
			$this->assertFalse( $result );
			$this->assertNotEmpty( $error );
			$this->assertSame( $error, $wpdb->last_error, 'Releasing the mutex must preserve the publication failure for its caller.' );
		} finally {
			$wpdb->suppress_errors( $previous );
			$wpdb->last_error = '';
		}
	}

	/** Database mutex errors must surface rather than pretending another worker owns it. */
	public function test_publication_mutex_error_is_not_contention() {
		list( $site_id, $task ) = $this->make_in_flight_run();
		$fail_lock              = static function ( $sql ) {
			return false !== strpos( $sql, 'SELECT GET_LOCK(' ) ? 'SELECT NULL' : $sql;
		};
		add_filter( 'query', $fail_lock );
		try {
			\MainWP\Dashboard\MainWP_Backup_Executor::with_run_publication(
				$task,
				static function () {
					return true;
				}
			);
			$this->fail( 'A failed mutex must refuse publication.' );
		} catch ( \MainWP\Dashboard\MainWP_Exception $error ) {
			$this->assertSame( \MainWP\Dashboard\MainWP_Backup_Error_Codes::ERR_MUTEX_WRITE_FAILED, $error->get_message_error_code() );
		} finally {
			remove_filter( 'query', $fail_lock );
		}
	}

	/** A failed publication mutex yields without pruning the completed Child archive. */
	public function test_publication_mutex_failure_keeps_completed_child_retryable() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		list( $site_id, $task ) = $this->make_in_flight_run();
		$db                     = MainWP_DB_Backup::instance();
		$db->update_backup_task_progress(
			$task->id,
			$site_id,
			array(
				'child_phase'            => 'completed',
				'downloadedFULLComplete' => 0,
			)
		);
		$calls         = array();
		$replied       = false;
		$locks         = 0;
		$fail_lock     = static function ( $sql ) use ( &$replied, &$locks ) {
			if ( $replied && false !== strpos( $sql, 'SELECT GET_LOCK(' ) ) {
				++$locks;
				return 'SELECT NULL';
			}
			return $sql;
		};
		$observe_reply = static function ( $payload, $elapsed, $context ) use ( &$replied ) {
			if ( 'run' === ( $context['op'] ?? '' ) && 'completed' === ( $payload['phase'] ?? '' ) ) {
				$replied = true;
			}
		};
		$transport     = static function ( $value, $site, $what ) use ( &$calls ) {
			$calls[] = $what;
			return array(
				'task_id' => 'child-task-123',
				'phase'   => 'completed',
			);
		};
		add_filter( 'query', $fail_lock );
		add_filter( 'mainwp_fetch_url_authed_pre', $transport, 20, 3 );
		add_action( 'mainwp_backup_diag_poll', $observe_reply, 20, 3 );
		try {
			$method = new \ReflectionMethod( \MainWP\Dashboard\MainWP_Manage_Backups_Handler::class, 'execute_backup_task_run' );
			$method->setAccessible( true );
			$claimed = null;
			$started = 0;
			$outcome = array();
			$method->invokeArgs( null, array( $task, 5, false, 0, &$claimed, &$started, false, &$outcome ) );
		} finally {
			remove_filter( 'query', $fail_lock );
			remove_filter( 'mainwp_fetch_url_authed_pre', $transport, 20 );
			remove_action( 'mainwp_backup_diag_poll', $observe_reply, 20 );
		}
		$current = $db->get_backup_task_by_id( $task->id );
		$this->assertLessThan( (int) $current->last_run, (int) $current->completed );
		$this->assertSame( '', (string) $current->backup_errors );
		$this->assertSame( 'child-task-123', $db->get_backup_task_progress( $task->id, $site_id )->child_backup_task_id );
		$this->assertSame( 1, $outcome['sites_yielded'] );
		$this->assertSame( 0, $outcome['sites_finished'] );
		$this->assertNotContains( 'backup_task_prune', $calls );
		$this->assertContains( 'backup_task_run', $calls );
		$this->assertTrue( $replied, 'The driver must observe the completed reply before publication is refused.' );
		$this->assertGreaterThan( 0, $locks, 'The driver must reach its publication mutex after the completed reply.' );
	}

	use Backup_Cancel_Transport_Guard;

	/** Persistent Child mutex refusals must finish with an error after their retry window. */
	public function test_persistent_child_mutex_refusal_is_terminal_in_site_loop() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		list( $site_id, $task ) = $this->make_in_flight_run();
		$db                     = MainWP_DB_Backup::instance();
		$db->update_backup_task_with_values( $task->id, array( 'last_run' => time() - 300 ) );
		$task = $db->get_backup_task_by_id( $task->id );
		$db->update_backup_task_progress(
			$task->id,
			$site_id,
			array(
				'child_backup_task_id'  => '',
				'dtsFetched'            => 0,
				'foreign_refused_since' => time() - 200,
			)
		);
		$transport = static function ( $value, $site, $what ) {
			if ( 'backup_preflight' === $what ) {
				return array( 'task_spine' => 1 );
			}
			return array(
				'error'      => 'Could not acquire backup lock.',
				'error_code' => 'mainwp_backup_mutex_write_failed',
			);
		};
		$reported  = null;
		$capture   = static function ( $site, $params, $result ) use ( &$reported ) {
			$reported = $result;
		};
		add_action( 'mainwp_managesite_schedule_backup', $capture, 10, 3 );
		add_filter( 'mainwp_fetch_url_authed_pre', $transport, 20, 3 );
		try {
			$this->drive_manual_run( $task, $outcome );
		} finally {
			remove_action( 'mainwp_managesite_schedule_backup', $capture, 10 );
			remove_filter( 'mainwp_fetch_url_authed_pre', $transport, 20 );
		}
		$this->assertSame( 0, $outcome['sites_yielded'] );
		$this->assertSame( 1, $outcome['sites_finished'] );
		$this->assertNotEmpty( $db->get_backup_task_by_id( $task->id )->backup_errors );
		$this->assertSame( \MainWP\Dashboard\MainWP_Backup_Error_Codes::message( \MainWP\Dashboard\MainWP_Backup_Error_Codes::ERR_MUTEX_WRITE_FAILED ), $reported );
	}

	/** A refused diagnostic write must preserve the create error and clear its marker. */
	public function test_create_error_survives_refused_diagnostic_publication() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		foreach ( array( 'SELECT NULL', 'SELECT 0' ) as $lock_reply ) {
			list( $site_id, $task ) = $this->make_in_flight_run();
			$db                     = MainWP_DB_Backup::instance();
			$db->update_backup_task_progress(
				$task->id,
				$site_id,
				array(
					'child_backup_task_id' => '',
					'dtsFetched'           => 0,
				)
			);
			$original  = new \MainWP\Dashboard\MainWP_Exception( 'Create transport failed.', 'create-extra' );
			$failed    = false;
			$transport = static function ( $value, $site, $what ) use ( &$failed, $original ) {
				if ( 'backup_preflight' === $what ) {
					return array( 'task_spine' => 1 );
				}
				if ( 'backup_task_create' === $what ) {
					$failed = true;
					MainWP_Backup_Now_Continuation_Test::$fixed_microtime += 31;
					throw $original;
				}
				return array( 'result' => 'ok' );
			};
			$fail_lock = static function ( $sql ) use ( &$failed, $lock_reply ) {
				return $failed && false !== strpos( $sql, 'SELECT GET_LOCK(' ) ? $lock_reply : $sql;
			};
			MainWP_Backup_Now_Continuation_Test::$fixed_microtime = 1000.0;
			add_filter( 'query', $fail_lock );
			add_filter( 'mainwp_fetch_url_authed_pre', $transport, 20, 3 );
			try {
				$advanced = false;
				try {
					\MainWP\Dashboard\MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups', $advanced, true );
					$this->fail( 'The original create failure must propagate.' );
				} catch ( \MainWP\Dashboard\MainWP_Exception $error ) {
					$this->assertSame( $original, $error );
				}
				$this->assertTrue( $failed, 'The actual create transport must execute.' );
				$this->assertSame( '', $db->get_backup_task_progress( $task->id, $site_id )->child_backup_task_id );
			} finally {
				remove_filter( 'query', $fail_lock );
				remove_filter( 'mainwp_fetch_url_authed_pre', $transport, 20 );
				MainWP_Backup_Now_Continuation_Test::$fixed_microtime = null;
			}
		}
	}

	/** Exhausted late downloads must not terminally mark a closed or replacement run. */
	public function test_exhausted_late_download_does_not_touch_closed_or_successor_run() {
		global $wpdb;
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		foreach ( array( array( false, false ), array( true, false ), array( false, true ), array( true, true ) ) as list( $restart, $after_check ) ) {
			list( $site_id, $task ) = $this->make_in_flight_run();
			$db                     = MainWP_DB_Backup::instance();
			$key                    = openssl_pkey_new(
				array(
					'private_key_bits' => 2048,
					'private_key_type' => OPENSSL_KEYTYPE_RSA,
				)
			);
			openssl_pkey_export( $key, $pem );
			$wpdb->update( $wpdb->prefix . 'mainwp_wp', array( 'privkey' => base64_encode( $pem ) ), array( 'id' => $site_id ) );
			$db->update_backup_task_progress(
				$task->id,
				$site_id,
				array(
					'attempts'     => 5,
					'dtsFetched'   => time(),
					'pid'          => 12345,
					'removedFiles' => 1,
					'child_phase'  => 'completed',
					'fetchResult'  => wp_json_encode(
						array(
							'full' => 'backup-file.zip',
							'db'   => false,
							'size' => 4096,
						)
					),
				)
			);
			$landed     = 0;
			$closed     = 0;
			$close      = function () use ( $db, $task, $site_id, $restart, &$closed ) {
				++$closed;
				$this->assertTrue( $db->complete_cancelled_manual_run( (int) $task->id, (int) $task->last_run, $site_id, 'child-task-123' ) );
				if ( $restart ) {
					$started = 0;
					$this->assertTrue( $db->claim_backup_run( $task->id, 30, $started ) );
					$db->update_backup_task_progress(
						$task->id,
						$site_id,
						array(
							'child_backup_task_id' => 'successor-task',
							'child_phase'          => 'completed',
							'attempts'             => 5,
							'fetchResult'          => wp_json_encode( array( 'full' => 'successor.zip' ) ),
						)
					);
				}
			};
			$interleave = static function ( $sql ) use ( $close, &$closed ) {
				if ( ! $closed && false !== strpos( $sql, 'wp_backup_progress WHERE task_id=' ) ) {
					$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- select the driver boundary for this interleaving test.
					foreach ( $trace as $index => $frame ) {
						if ( 'get_backup_task_progress' === ( $frame['function'] ?? '' ) && 'execute_backup_task_run' === ( $trace[ $index + 1 ]['function'] ?? '' ) ) {
							$close();
							break;
						}
					}
				}
				return $sql;
			};
			$download   = static function () use ( $close, $interleave, $after_check, &$landed ) {
				++$landed;
				if ( $after_check ) {
					add_filter( 'query', $interleave );
				} else {
					$close();
				}
				throw new \MainWP\Dashboard\MainWP_Exception( 'Late download failed.' );
			};
			$calls      = array();
			$transport  = static function ( $value, $site, $what ) use ( &$calls ) {
				$calls[] = $what;
				return array( 'result' => 'ok' );
			};
			add_filter( 'mainwp_download_to_file_pre', $download );
			add_filter( 'mainwp_fetch_url_authed_pre', $transport, 20, 3 );
			try {
				$this->drive_manual_run( $task, $outcome );
			} finally {
				remove_filter( 'query', $interleave );
				remove_filter( 'mainwp_download_to_file_pre', $download );
				remove_filter( 'mainwp_fetch_url_authed_pre', $transport, 20 );
			}
			$this->assertSame( 1, $landed, 'The test must reach the actual download path.' );
			$this->assertSame( 1, $closed, 'Cancellation must interleave at the selected boundary.' );
			$this->assertNotContains( 'backup_task_cancel', $calls );
			$this->assertNotContains( 'backup_task_prune', $calls );
			$this->assertNotContains( 'delete_backup', $calls );
			$current = $db->get_backup_task_by_id( $task->id );
			$this->assertSame( 1, substr_count( (string) $current->backup_errors, 'Backup cancelled from the dashboard.' ) );
			$this->assertSame( $restart ? 'successor-task' : '', $db->get_backup_task_progress( $task->id, $site_id )->child_backup_task_id );
			$this->assertSame( 0, $outcome['sites_finished'] );
		}
	}

	/**
	 * Run the real driver against a persisted manual task.
	 *
	 * @param object $task Observed task.
	 * @param array  $outcome Driver counts.
	 */
	private function drive_manual_run( $task, &$outcome ) {
		$method = new \ReflectionMethod( \MainWP\Dashboard\MainWP_Manage_Backups_Handler::class, 'execute_backup_task_run' );
		$method->setAccessible( true );
		$claimed = null;
		$started = 0;
		$outcome = array();
		return $method->invokeArgs( null, array( $task, 5, false, 0, &$claimed, &$started, false, &$outcome ) );
	}

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
		foreach ( $cases as list( $reply, $service_result ) ) {
			foreach ( array( false, true ) as $ajax ) {
				$expected               = $ajax && 'already_finished' === $service_result ? 'cancelled' : $service_result;
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
				$fresh  = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
				$closed = in_array( $expected, array( 'cancelled', 'already_failed' ), true );
				$this->assertSame( $closed, (int) $fresh->completed >= (int) $fresh->last_run );
				$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( (int) $task->id, $site_id );
				$this->assertSame( $closed ? '' : 'child-task-123', $progress->child_backup_task_id );
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
