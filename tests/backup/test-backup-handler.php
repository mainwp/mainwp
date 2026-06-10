<?php
/**
 * MainWP Backup Handler Tests
 *
 * Tests for backup_site() retry/state handling: the attempts counter,
 * busy-status timeout, silent failure paths, and child error code
 * propagation.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Handler;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Exception;

/**
 * Class MainWP_Backup_Handler_Test
 */
class MainWP_Backup_Handler_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Set up: enable the legacy backup feature.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		update_option( 'mainwp_enableLegacyBackupFeature', 1 );
	}

	/**
	 * Clean up: progress rows and option.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_wp_backup_progress" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup.
		delete_option( 'mainwp_enableLegacyBackupFeature' );
		parent::tearDown();
	}

	/**
	 * Build a backup task object for backup_site().
	 *
	 * @param int   $userid    Owner user id.
	 * @param array $overrides Field overrides.
	 *
	 * @return object Task.
	 */
	private function make_task( $userid, $overrides = array() ) {
		return (object) array_merge(
			array(
				'id'                             => 1,
				'userid'                         => $userid,
				'type'                           => 'full',
				'exclude'                        => '',
				'excludebackup'                  => 0,
				'excludecache'                   => 0,
				'excludenonwp'                   => 0,
				'excludezip'                     => 0,
				'filename'                       => '',
				'archiveFormat'                  => 'zip',
				'loadFilesBeforeZip'             => 1,
				'maximumFileDescriptorsOverride' => 0,
				'maximumFileDescriptorsAuto'     => 1,
				'maximumFileDescriptors'         => 150,
				'last_run'                       => time() - 100,
				'subfolder'                      => 'backups',
			),
			$overrides
		);
	}

	/**
	 * Create a site and a matching task.
	 *
	 * @param array $task_overrides Task field overrides.
	 *
	 * @return array Site ID and task.
	 */
	private function site_and_task( $task_overrides = array() ) {
		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$task    = $this->make_task( $website->userid, $task_overrides );
		return array( $site_id, $task );
	}

	/**
	 * Seed a progress row that routes backup_site() into the status-check branch.
	 *
	 * @param int   $task_id Task ID.
	 * @param int   $site_id Site ID.
	 * @param array $values  Extra progress values.
	 *
	 * @return void
	 */
	private function seed_progress( $task_id, $site_id, $values = array() ) {
		MainWP_DB_Backup::instance()->add_backup_task_progress( $task_id, $site_id, array() );
		MainWP_DB_Backup::instance()->update_backup_task_progress(
			$task_id,
			$site_id,
			array_merge(
				array(
					'dtsFetched' => time(),
					'pid'        => 12345,
					'attempts'   => 0,
				),
				$values
			)
		);
	}

	/**
	 * Mock a child response for a specific action.
	 *
	 * @param string $action   Action name (e.g. 'backup_checkpid').
	 * @param mixed  $response Response array, or a MainWP_Exception to throw.
	 *
	 * @return void
	 */
	private function mock_child_action( $action, $response ) {
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what ) use ( $action, $response ) {
				unset( $website );
				if ( $what === $action ) {
					if ( $response instanceof MainWP_Exception ) {
						throw $response; // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test mock.
					}
					return $response;
				}
				return $result;
			},
			10,
			3
		);
	}

	/**
	 * Disabled legacy backup feature is reported, not silently ignored.
	 *
	 * @return void
	 */
	public function test_backup_site_disabled_feature_throws_legacy_disabled() {
		delete_option( 'mainwp_enableLegacyBackupFeature' );
		list( $site_id, $task ) = $this->site_and_task();

		try {
			MainWP_Backup_Handler::backup_site( $site_id, $task, 'backups' );
			$this->fail( 'Expected exception when the legacy backup feature is disabled.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_legacy_disabled', $e->get_message_error_code() );
		}
	}

	/**
	 * Disabled legacy backup feature is reported from the direct backup path too.
	 *
	 * @return void
	 */
	public function test_backup_disabled_feature_throws_legacy_disabled() {
		delete_option( 'mainwp_enableLegacyBackupFeature' );
		$site_id = $this->create_test_site();

		try {
			MainWP_Backup_Handler::backup( $site_id, 'full', '', '', 1, 1, 1, 1 );
			$this->fail( 'Expected exception when the legacy backup feature is disabled.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_legacy_disabled', $e->get_message_error_code() );
		}
	}

	/**
	 * The attempts counter actually increments in the database when the
	 * child status check returns nothing.
	 *
	 * @return void
	 */
	public function test_attempts_counter_increments_in_database() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->seed_progress( $task->id, $site_id );
		$this->mock_child_action( 'backup_checkpid', array() );

		try {
			MainWP_Backup_Handler::backup_site( $site_id, $task, 'backups' );
		} catch ( MainWP_Exception $e ) {
			unset( $e ); // The empty fetch result is reported separately; only the counter matters here.
		}

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( 1, (int) $progress->attempts, 'A failed status check must consume one retry attempt.' );
	}

	/**
	 * A failed child status check (exception) is not swallowed silently:
	 * it consumes a retry attempt.
	 *
	 * @return void
	 */
	public function test_checkpid_exception_consumes_attempt() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->seed_progress( $task->id, $site_id );
		$this->mock_child_action( 'backup_checkpid', new MainWP_Exception( 'connection failed' ) );

		try {
			MainWP_Backup_Handler::backup_site( $site_id, $task, 'backups' );
		} catch ( MainWP_Exception $e ) {
			unset( $e ); // The empty fetch result is reported separately; only the counter matters here.
		}

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( 1, (int) $progress->attempts, 'A failed status check must consume one retry attempt.' );
	}

	/**
	 * Exhausted attempts surface the retry-exhausted error code.
	 *
	 * @return void
	 */
	public function test_exhausted_attempts_throw_retry_exhausted() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->seed_progress( $task->id, $site_id, array( 'attempts' => 5 ) );
		$this->mock_child_action( 'backup_checkpid', array() );

		try {
			MainWP_Backup_Handler::backup_site( $site_id, $task, 'backups' );
			$this->fail( 'Expected retry-exhausted exception.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_retry_exhausted', $e->get_message_error_code() );
		}
	}

	/**
	 * The first busy status records the busy start and defers.
	 *
	 * @return void
	 */
	public function test_busy_status_records_start_and_defers() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->seed_progress( $task->id, $site_id );
		$this->mock_child_action( 'backup_checkpid', array( 'status' => 'busy' ) );

		$result = MainWP_Backup_Handler::backup_site( $site_id, $task, 'backups' );

		$this->assertFalse( $result, 'A fresh busy status defers to the next cycle.' );
		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertGreaterThan( 0, (int) $progress->busy_since, 'The busy start time must be recorded.' );
	}

	/**
	 * A site busy for more than an hour stops deferring and reports a stall.
	 *
	 * @return void
	 */
	public function test_busy_status_over_one_hour_throws_stalled() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->seed_progress( $task->id, $site_id, array( 'busy_since' => time() - 2 * HOUR_IN_SECONDS ) );
		$this->mock_child_action( 'backup_checkpid', array( 'status' => 'busy' ) );

		try {
			MainWP_Backup_Handler::backup_site( $site_id, $task, 'backups' );
			$this->fail( 'Expected stall exception after one hour of busy status.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_stalled', $e->get_message_error_code() );
		}
	}

	/**
	 * An already-running child backup is reported with its error code
	 * instead of silently deferring forever.
	 *
	 * @return void
	 */
	public function test_another_backup_running_throws_already_running() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->mock_child_action( 'backup', array( 'error' => 'Another backup process is running on the child site.' ) );

		try {
			MainWP_Backup_Handler::backup_site( $site_id, $task, 'backups' );
			$this->fail( 'Expected already-running exception.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_already_running', $e->get_message_error_code() );
		}
	}

	/**
	 * Child error responses carry their error code through to the exception.
	 *
	 * @return void
	 */
	public function test_child_error_code_propagates_to_exception() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->mock_child_action(
			'backup',
			array(
				'error'      => 'Could not write backup data.',
				'error_code' => 'mainwp_backup_disk_full',
			)
		);

		try {
			MainWP_Backup_Handler::backup_site( $site_id, $task, 'backups' );
			$this->fail( 'Expected child error exception.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_disk_full', $e->get_message_error_code() );
		}
	}

	/**
	 * Give a test site a real RSA private key so download URL signing works.
	 *
	 * @param int $site_id Site ID.
	 *
	 * @return void
	 */
	private function give_site_real_privkey( $site_id ) {
		global $wpdb;
		$key = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		openssl_pkey_export( $key, $privkey_pem );
		// The connect layer base64-decodes the stored key before signing.
		$wpdb->update( $wpdb->prefix . 'mainwp_wp', array( 'privkey' => base64_encode( $privkey_pem ) ), array( 'id' => $site_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.PHP.DiscouragedPHPFunctions -- test fixture.
	}

	/**
	 * Seed a progress row whose fetch result is ready for the download phase.
	 *
	 * @param int $task_id Task ID.
	 * @param int $site_id Site ID.
	 * @param int $size    Reported backup size.
	 *
	 * @return void
	 */
	private function seed_download_ready_progress( $task_id, $site_id, $size ) {
		$this->give_site_real_privkey( $site_id );
		MainWP_DB_Backup::instance()->add_backup_task_progress( $task_id, $site_id, array() );
		MainWP_DB_Backup::instance()->update_backup_task_progress(
			$task_id,
			$site_id,
			array(
				'dtsFetched'   => time(),
				'pid'          => 12345,
				'removedFiles' => 1,
				'fetchResult'  => wp_json_encode(
					array(
						'full' => 'backup-file.zip',
						'db'   => false,
						'size' => $size,
					)
				),
			)
		);
	}

	/**
	 * A held download lock stops a second process from downloading the
	 * same backup file.
	 *
	 * @return void
	 */
	public function test_download_lock_held_reports_already_running() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->seed_download_ready_progress( $task->id, $site_id, 4096 );
		$this->mock_child_action( 'delete_backup', array( 'result' => 'ok' ) );

		$locked_file = null;
		add_filter(
			'mainwp_download_to_file_pre',
			function ( $pre, $url, $file ) use ( &$locked_file ) {
				unset( $pre, $url );
				$locked_file = $file;
				return true; // Skip the real transfer; validation will reject the missing file.
			},
			10,
			3
		);

		// First run captures the local file path and stores it in the progress row.
		try {
			MainWP_Backup_Handler::backup_site( $site_id, $task, 'backups' );
		} catch ( MainWP_Exception $e ) {
			unset( $e );
		}
		$this->assertNotNull( $locked_file );

		// Another process now holds the lock; a new run must refuse to download.
		$this->assertTrue( MainWP_Backup_Handler::acquire_download_lock( $locked_file, 999, 999 ) );
		try {
			MainWP_Backup_Handler::backup_site( $site_id, $task, 'backups' );
			$this->fail( 'Expected already-running exception while the download lock is held.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_already_running', $e->get_message_error_code() );
		}

		MainWP_Backup_Handler::release_download_lock( $locked_file );
	}

	/**
	 * A downloaded file that fails validation is reported and the download
	 * is not marked complete.
	 *
	 * @return void
	 */
	public function test_invalid_downloaded_file_not_marked_complete() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->seed_download_ready_progress( $task->id, $site_id, 52 );
		$this->mock_child_action( 'delete_backup', array( 'result' => 'ok' ) );

		add_filter(
			'mainwp_download_to_file_pre',
			function ( $pre, $url, $file ) {
				unset( $pre, $url );
				file_put_contents( $file, '<html><body>Fatal error on child site</body></html>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
				return true;
			},
			10,
			3
		);

		try {
			MainWP_Backup_Handler::backup_site( $site_id, $task, 'backups' );
			$this->fail( 'Expected header validation to reject the error-page download.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_header_invalid', $e->get_message_error_code() );
		}

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( 0, (int) $progress->downloadedFULLComplete, 'A failed validation must not mark the download complete.' );
	}

	/**
	 * Record which child actions get called, in order.
	 *
	 * @param array $called Reference to the recorder array.
	 *
	 * @return void
	 */
	private function record_child_actions( &$called ) {
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what ) use ( &$called ) {
				unset( $website );
				$called[] = $what;
				return $result;
			},
			5,
			3
		);
	}

	/**
	 * A child that advertises the resumable task machine gets a task
	 * created on the first call; the task id lands on the progress row and
	 * the run defers to the next cycle.
	 *
	 * @return void
	 */
	public function test_spine_first_call_creates_task_and_defers() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->mock_child_action( 'backup_preflight', array( 'task_spine' => 1 ) );
		$this->mock_child_action(
			'backup_task_create',
			array(
				'task_id' => 'task-1749600000-abcdef',
				'phase'   => 'queued',
			)
		);

		$called = array();
		$this->record_child_actions( $called );

		$result = MainWP_Backup_Handler::backup_site( $site_id, $task, 'backups' );

		$this->assertFalse( $result, 'Creating the task defers the work to the next cycle.' );
		$this->assertContains( 'backup_task_create', $called );
		$this->assertNotContains( 'backup', $called, 'The legacy backup action must not fire on the resumable path.' );

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( 'task-1749600000-abcdef', $progress->child_backup_task_id );
	}

	/**
	 * A child without the task machine takes the legacy path untouched.
	 *
	 * @return void
	 */
	public function test_no_spine_support_takes_legacy_path() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->mock_child_action( 'backup_preflight', array( 'zip_support' => true ) );
		$this->mock_child_action(
			'backup',
			array(
				'error'      => 'Could not write backup data.',
				'error_code' => 'mainwp_backup_disk_full',
			)
		);

		$called = array();
		$this->record_child_actions( $called );

		try {
			MainWP_Backup_Handler::backup_site( $site_id, $task, 'backups' );
			$this->fail( 'Expected the legacy child error to propagate.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_disk_full', $e->get_message_error_code() );
		}

		$this->assertContains( 'backup', $called );
		$this->assertNotContains( 'backup_task_create', $called );
	}

	/**
	 * A fresh run resets any stale child task id so a new run never drives
	 * a previous run's task.
	 *
	 * @return void
	 */
	public function test_fresh_run_resets_stale_child_task_id() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->seed_progress( $task->id, $site_id, array( 'child_backup_task_id' => 'task-old' ) );
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}mainwp_wp_backup_progress SET dtsFetched = %d WHERE task_id = %d", $task->last_run - 50, $task->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture.

		$this->mock_child_action( 'backup_preflight', array( 'zip_support' => true ) );
		$this->mock_child_action( 'backup', array( 'error' => 'Could not write backup data.' ) );

		try {
			MainWP_Backup_Handler::backup_site( $site_id, $task, 'backups' );
		} catch ( MainWP_Exception $e ) {
			unset( $e );
		}

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( '', $progress->child_backup_task_id );
	}

	/**
	 * An in-flight run with a child task id advances the task instead of
	 * polling the legacy pid, and defers while the task is still working.
	 *
	 * @return void
	 */
	public function test_spine_in_flight_runs_task_and_defers() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->seed_progress( $task->id, $site_id, array( 'child_backup_task_id' => 'task-abc' ) );

		$run_params = null;
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what, $params ) use ( &$run_params ) {
				unset( $website );
				if ( 'backup_task_run' === $what ) {
					$run_params = $params;
					return array(
						'task_id' => 'task-abc',
						'phase'   => 'archiving_files',
						'type'    => 'full',
					);
				}
				return $result;
			},
			10,
			4
		);

		$called = array();
		$this->record_child_actions( $called );

		$result = MainWP_Backup_Handler::backup_site( $site_id, $task, 'backups' );

		$this->assertFalse( $result, 'A working task defers to the next cycle.' );
		$this->assertSame( 'task-abc', $run_params['task_id'] );
		$this->assertNotContains( 'backup_checkpid', $called, 'The resumable path must not poll the legacy pid.' );
	}

	/**
	 * A completed task feeds the artifact into the fetch result, entering
	 * the unchanged download flow.
	 *
	 * @return void
	 */
	public function test_spine_completed_populates_fetch_result() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->give_site_real_privkey( $site_id );
		$this->seed_progress(
			$task->id,
			$site_id,
			array(
				'child_backup_task_id' => 'task-abc',
				'removedFiles'         => 1,
			)
		);
		$this->mock_child_action(
			'backup_task_run',
			array(
				'task_id' => 'task-abc',
				'phase'   => 'completed',
				'type'    => 'full',
				'file'    => 'backup-1749600000.tar',
				'size'    => 4096,
			)
		);
		$this->mock_child_action( 'delete_backup', array( 'result' => 'ok' ) );
		add_filter( 'mainwp_download_to_file_pre', '__return_true', 10, 1 );

		try {
			MainWP_Backup_Handler::backup_site( $site_id, $task, 'backups' );
		} catch ( MainWP_Exception $e ) {
			unset( $e ); // The skipped download fails validation; only the fetch result matters here.
		}

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( 'backup-1749600000.tar', $progress->fetchResult['full'] );
		$this->assertFalse( $progress->fetchResult['db'] );
		$this->assertSame( 4096, $progress->fetchResult['size'] );
	}

	/**
	 * A completed db task lands its artifact under the db key.
	 *
	 * @return void
	 */
	public function test_spine_completed_db_kind_populates_db_key() {
		list( $site_id, $task ) = $this->site_and_task( array( 'type' => 'db' ) );
		$this->give_site_real_privkey( $site_id );
		$this->seed_progress(
			$task->id,
			$site_id,
			array(
				'child_backup_task_id' => 'task-db',
				'removedFiles'         => 1,
			)
		);
		$this->mock_child_action(
			'backup_task_run',
			array(
				'task_id' => 'task-db',
				'phase'   => 'completed',
				'type'    => 'db',
				'file'    => 'dbBackup-1749600000.sql.tar',
				'size'    => 2048,
			)
		);
		$this->mock_child_action( 'delete_backup', array( 'result' => 'ok' ) );
		add_filter( 'mainwp_download_to_file_pre', '__return_true', 10, 1 );

		try {
			MainWP_Backup_Handler::backup_site( $site_id, $task, 'backups' );
		} catch ( MainWP_Exception $e ) {
			unset( $e );
		}

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( 'dbBackup-1749600000.sql.tar', $progress->fetchResult['db'] );
		$this->assertFalse( $progress->fetchResult['full'] );
	}

	/**
	 * A failed task surfaces the child's recorded error and code.
	 *
	 * @return void
	 */
	public function test_spine_failed_task_throws_child_error() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->seed_progress( $task->id, $site_id, array( 'child_backup_task_id' => 'task-abc' ) );
		$this->mock_child_action(
			'backup_task_run',
			array(
				'task_id'    => 'task-abc',
				'phase'      => 'failed',
				'type'       => 'full',
				'error'      => 'Could not read table data for the backup.',
				'error_code' => 'mainwp_backup_db_query_failed',
			)
		);

		try {
			MainWP_Backup_Handler::backup_site( $site_id, $task, 'backups' );
			$this->fail( 'Expected the failed task to propagate.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_db_query_failed', $e->get_message_error_code() );
			$this->assertStringContainsString( 'Could not read table data', $e->getMessage() );
		}
	}

	/**
	 * A failed run call consumes a retry attempt, mirroring the legacy
	 * status-check semantics; exhausted attempts stop the run.
	 *
	 * @return void
	 */
	public function test_spine_run_failure_consumes_attempt() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->seed_progress( $task->id, $site_id, array( 'child_backup_task_id' => 'task-abc' ) );
		$this->mock_child_action( 'backup_task_run', new MainWP_Exception( 'connection failed' ) );

		try {
			MainWP_Backup_Handler::backup_site( $site_id, $task, 'backups' );
		} catch ( MainWP_Exception $e ) {
			unset( $e );
		}

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( 1, (int) $progress->attempts, 'A failed run call must consume one retry attempt.' );

		MainWP_DB_Backup::instance()->update_backup_task_progress( $task->id, $site_id, array( 'attempts' => 5 ) );
		try {
			MainWP_Backup_Handler::backup_site( $site_id, $task, 'backups' );
			$this->fail( 'Expected retry-exhausted exception.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_retry_exhausted', $e->get_message_error_code() );
		}
	}

	/**
	 * A failed retry of a stalled backup re-throws instead of returning false.
	 *
	 * @return void
	 */
	public function test_stalled_retry_failure_rethrows() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->seed_progress( $task->id, $site_id );
		$this->mock_child_action(
			'backup_checkpid',
			array(
				'status' => 'stalled',
				'file'   => 'backup-file.zip',
			)
		);
		$this->mock_child_action( 'backup', new MainWP_Exception( 'child unreachable during retry' ) );

		try {
			MainWP_Backup_Handler::backup_site( $site_id, $task, 'backups' );
			$this->fail( 'Expected the stalled-retry failure to propagate.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertStringContainsString( 'child unreachable during retry', $e->getMessage() );
		}
	}
}
