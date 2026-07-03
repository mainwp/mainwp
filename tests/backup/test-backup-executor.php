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

use MainWP\Dashboard\MainWP_Backup_Executor;
use MainWP\Dashboard\MainWP_Backup_File_Manager;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Exception;
use MainWP\Dashboard\MainWP_Manage_Backups_Handler;

/**
 * Class MainWP_Backup_Executor_Test
 */
class MainWP_Backup_Executor_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Set up: enable the legacy backup feature.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		update_option( 'mainwp_enableNativeBackups', 1 );
	}

	/**
	 * Clean up: progress rows and option.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_wp_backup_progress" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup.
		delete_option( 'mainwp_enableNativeBackups' );
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
		delete_option( 'mainwp_enableNativeBackups' );
		list( $site_id, $task ) = $this->site_and_task();

		try {
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
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
		delete_option( 'mainwp_enableNativeBackups' );
		$site_id = $this->create_test_site();

		try {
			MainWP_Backup_Executor::backup( $site_id, 'full', '', '', 1, 1, 1, 1 );
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
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
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
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
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
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
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

		$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );

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
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
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
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
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
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
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
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
		} catch ( MainWP_Exception $e ) {
			unset( $e );
		}
		$this->assertNotNull( $locked_file );

		// Another process now holds the lock; a new run must refuse to download.
		$this->assertTrue( MainWP_Backup_File_Manager::acquire_download_lock( $locked_file, 999, 999 ) );
		try {
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
			$this->fail( 'Expected already-running exception while the download lock is held.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_already_running', $e->get_message_error_code() );
		}

		MainWP_Backup_File_Manager::release_download_lock( $locked_file );
	}

	/**
	 * A completed full run through the download branch records a snapshot
	 * row carrying the downloaded artifact, exactly once per run.
	 *
	 * @return void
	 */
	public function test_completed_full_run_records_snapshot() {
		list( $site_id, $task ) = $this->site_and_task();
		$content                = 'PK' . "\x03\x04" . str_repeat( 'x', 4092 );
		$this->seed_download_ready_progress( $task->id, $site_id, strlen( $content ) );
		$this->mock_child_action( 'delete_backup', array( 'result' => 'ok' ) );

		add_filter(
			'mainwp_download_to_file_pre',
			function ( $pre, $url, $file ) use ( $content ) {
				unset( $pre, $url );
				file_put_contents( $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
				return true;
			},
			10,
			3
		);

		$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
		$this->assertNotFalse( $result );

		$snapshot = \MainWP\Dashboard\MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, (int) $task->id );

		$this->assertNotNull( $snapshot, 'A completed full run must record a snapshot row.' );
		$this->assertSame( 'full', $snapshot->type );
		$this->assertNull( $snapshot->parent_id );
		$this->assertCount( 1, $snapshot->archives );
		$this->assertSame( 'full', $snapshot->archives[0]['role'] );
		$this->assertSame( strlen( $content ), (int) $snapshot->archives[0]['size'] );
		$this->assertFileExists( $snapshot->archives[0]['path'] );
	}

	/**
	 * A spine task completing with the child's Woo order-storage facts
	 * threads them into the persisted fetchResult, so the snapshot record
	 * step can stamp them without another child round trip.
	 *
	 * @return void
	 */
	public function test_spine_completion_threads_woo_into_fetch_result() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->give_site_real_privkey( $site_id );
		$this->seed_progress( $task->id, $site_id, array( 'child_backup_task_id' => 'bk-woo-1' ) );

		$this->mock_child_action(
			'backup_task_run',
			array(
				'task_id' => 'bk-woo-1',
				'phase'   => 'completed',
				'file'    => 'backup-woo.tar.gz',
				'size'    => 4096,
				'woo'     => array(
					'has_wc_orders'          => true,
					'woocommerce_db_version' => '9.4.0',
				),
			)
		);

		// The completion projection persists fetchResult before the download
		// branch runs; the download itself fails on the missing file and that
		// is fine - the threading is what this test pins.
		try {
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertIsArray( $progress->fetchResult );
		$this->assertArrayHasKey( 'woo', $progress->fetchResult );
		$this->assertTrue( $progress->fetchResult['woo']['has_wc_orders'] );
		$this->assertSame( '9.4.0', $progress->fetchResult['woo']['woocommerce_db_version'] );
	}

	/**
	 * A completed run whose fetchResult carries the child's Woo facts
	 * records them on the snapshot row, where the restore initiate
	 * handler reads them for the preserve-orders gate.
	 *
	 * @return void
	 */
	public function test_completed_run_snapshot_carries_woo_meta() {
		list( $site_id, $task ) = $this->site_and_task();
		$content                = 'PK' . "\x03\x04" . str_repeat( 'x', 4092 );

		$this->give_site_real_privkey( $site_id );
		MainWP_DB_Backup::instance()->add_backup_task_progress( $task->id, $site_id, array() );
		MainWP_DB_Backup::instance()->update_backup_task_progress(
			$task->id,
			$site_id,
			array(
				'dtsFetched'   => time(),
				'pid'          => 12345,
				'removedFiles' => 1,
				'fetchResult'  => wp_json_encode(
					array(
						'full' => 'backup-file.zip',
						'db'   => false,
						'size' => strlen( $content ),
						'woo'  => array(
							'has_wc_orders'          => true,
							'woocommerce_db_version' => '9.4.0',
						),
					)
				),
			)
		);
		$this->mock_child_action( 'delete_backup', array( 'result' => 'ok' ) );

		add_filter(
			'mainwp_download_to_file_pre',
			function ( $pre, $url, $file ) use ( $content ) {
				unset( $pre, $url );
				file_put_contents( $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
				return true;
			},
			10,
			3
		);

		$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
		$this->assertNotFalse( $result );

		$snapshot = \MainWP\Dashboard\MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, (int) $task->id );
		$this->assertNotNull( $snapshot );
		$this->assertIsArray( $snapshot->woo_meta );
		$this->assertTrue( $snapshot->woo_meta['has_wc_orders'] );
		$this->assertSame( '9.4.0', $snapshot->woo_meta['woocommerce_db_version'] );
	}

	/**
	 * The scheduled-task download sweep must skip archives recorded by
	 * complete backup snapshots: snapshot retention owns their lifecycle.
	 *
	 * @return void
	 */
	public function test_executor_sweep_skips_snapshot_recorded_file() {
		global $wpdb;

		list( $site_id, $task ) = $this->site_and_task();
		$content                = 'PK' . "\x03\x04" . str_repeat( 'x', 4092 );

		// Like seed_download_ready_progress(), but with the sweep still due.
		$this->give_site_real_privkey( $site_id );
		MainWP_DB_Backup::instance()->add_backup_task_progress( $task->id, $site_id, array() );
		MainWP_DB_Backup::instance()->update_backup_task_progress(
			$task->id,
			$site_id,
			array(
				'dtsFetched'   => time(),
				'pid'          => 12345,
				'removedFiles' => 0,
				'fetchResult'  => wp_json_encode(
					array(
						'full' => 'backup-file.zip',
						'db'   => false,
						'size' => strlen( $content ),
					)
				),
			)
		);
		$this->mock_child_action( 'delete_backup', array( 'result' => 'ok' ) );

		add_filter(
			'mainwp_download_to_file_pre',
			function ( $pre, $url, $file ) use ( $content ) {
				unset( $pre, $url );
				file_put_contents( $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
				return true;
			},
			10,
			3
		);

		$dir = \MainWP\Dashboard\MainWP_System_Utility::get_mainwp_specific_dir( $site_id );
		wp_mkdir_p( $dir );
		$protected   = $dir . 'protected.zip';
		$unprotected = $dir . 'unprotected.zip';
		file_put_contents( $protected, 'PK' . "\x03\x04" . str_repeat( 'p', 32 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		touch( $protected, time() - 600 );
		file_put_contents( $unprotected, 'PK' . "\x03\x04" . str_repeat( 'u', 32 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		touch( $unprotected, time() - 300 );

		\MainWP\Dashboard\MainWP_Backup_Snapshots::instance()->record_snapshot(
			array(
				'task_id'  => 990500,
				'site_id'  => $site_id,
				'type'     => 'full',
				'archives' => array(
					array(
						'file' => 'protected.zip',
						'size' => 36,
						'role' => 'full',
						'path' => $protected,
					),
				),
			)
		);

		update_option( 'mainwp_backupsOnServer', 1 );

		try {
			$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
			$this->assertNotFalse( $result );

			$this->assertFileExists( $protected, 'A snapshot-recorded archive must survive the scheduled-task sweep.' );
			$this->assertFileDoesNotExist( $unprotected, 'An unrecorded archive past the retention count must be deleted.' );
		} finally {
			delete_option( 'mainwp_backupsOnServer' );
			$snapshot_table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$snapshot_table} WHERE site_id = %d", $site_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.
			array_map( 'unlink', glob( $dir . '*' ) ?: array() ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test cleanup.
		}
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
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
			$this->fail( 'Expected header validation to reject the error-page download.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_header_invalid', $e->get_message_error_code() );
		}

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( 0, (int) $progress->downloadedFULLComplete, 'A failed validation must not mark the download complete.' );
	}

	/**
	 * A child that advertises download tokens gets the executor download
	 * phase routed through the token flow: the token callable is consulted
	 * and the download URL carries the token.
	 *
	 * @return void
	 */
	public function test_download_phase_requests_token_when_child_supports_it() {
		list( $site_id, $task ) = $this->site_and_task();
		$content = 'PK' . "\x03\x04" . str_repeat( 'x', 4092 );
		$this->seed_download_ready_progress( $task->id, $site_id, strlen( $content ) );
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		MainWP_DB::instance()->update_website_option( $website, 'download_token_support', '1' );

		$token_calls = 0;
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what ) use ( &$token_calls, $content ) {
				unset( $website );
				if ( 'backup_download_token' === $what ) {
					++$token_calls;
					return array(
						'token' => str_repeat( 'g', 32 ),
						'hash'  => hash( 'sha256', $content ),
						'size'  => strlen( $content ),
					);
				}
				if ( 'delete_backup' === $what ) {
					return array( 'result' => 'ok' );
				}
				return $result;
			},
			10,
			3
		);

		$urls = array();
		add_filter(
			'mainwp_download_to_file_pre',
			function ( $pre, $url, $file ) use ( &$urls, $content ) {
				unset( $pre );
				$urls[] = $url;
				file_put_contents( $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
				return true;
			},
			10,
			3
		);

		MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );

		$this->assertSame( 1, $token_calls, 'The download phase must request a token from a capable child.' );
		$this->assertCount( 1, $urls );
		$this->assertStringContainsString( 'dltoken=' . str_repeat( 'g', 32 ), $urls[0] );

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( 1, (int) $progress->downloadedFULLComplete );
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

		$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );

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
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
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
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
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

		$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );

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
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
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
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
		} catch ( MainWP_Exception $e ) {
			unset( $e );
		}

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( 'dbBackup-1749600000.sql.tar', $progress->fetchResult['db'] );
		$this->assertFalse( $progress->fetchResult['full'] );
	}

	/**
	 * A completed files task is an archive: it lands under the full key
	 * and enters the same download flow, never the db slot.
	 *
	 * @return void
	 */
	public function test_spine_completed_files_kind_populates_full_key() {
		list( $site_id, $task ) = $this->site_and_task( array( 'type' => 'files' ) );
		$this->give_site_real_privkey( $site_id );
		$this->seed_progress(
			$task->id,
			$site_id,
			array(
				'child_backup_task_id' => 'task-files',
				'removedFiles'         => 1,
			)
		);
		$this->mock_child_action(
			'backup_task_run',
			array(
				'task_id' => 'task-files',
				'phase'   => 'completed',
				'type'    => 'files',
				'file'    => 'backup-1749600000.tar',
				'size'    => 4096,
			)
		);
		$this->mock_child_action( 'delete_backup', array( 'result' => 'ok' ) );
		add_filter( 'mainwp_download_to_file_pre', '__return_true', 10, 1 );

		try {
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
		} catch ( MainWP_Exception $e ) {
			unset( $e ); // The skipped download fails validation; only the fetch result matters here.
		}

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( 'backup-1749600000.tar', $progress->fetchResult['full'] );
		$this->assertFalse( $progress->fetchResult['db'] );
	}

	/**
	 * A completed files run records a files-typed snapshot so retention
	 * counts it as its own unit and the restore catalog can refuse it.
	 *
	 * @return void
	 */
	public function test_completed_files_run_records_files_snapshot() {
		list( $site_id, $task ) = $this->site_and_task( array( 'type' => 'files' ) );
		$content                = 'PK' . "\x03\x04" . str_repeat( 'x', 4092 );
		$this->seed_download_ready_progress( $task->id, $site_id, strlen( $content ) );
		$this->mock_child_action( 'delete_backup', array( 'result' => 'ok' ) );

		add_filter(
			'mainwp_download_to_file_pre',
			function ( $pre, $url, $file ) use ( $content ) {
				unset( $pre, $url );
				file_put_contents( $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
				return true;
			},
			10,
			3
		);

		$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
		$this->assertNotFalse( $result );

		$snapshot = \MainWP\Dashboard\MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, (int) $task->id );

		$this->assertNotNull( $snapshot, 'A completed files run must record a snapshot row.' );
		$this->assertSame( 'files', $snapshot->type );
		$this->assertNull( $snapshot->parent_id );
		$this->assertCount( 1, $snapshot->archives );
		$this->assertSame( 'full', $snapshot->archives[0]['role'] );
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
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
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
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
		} catch ( MainWP_Exception $e ) {
			unset( $e );
		}

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( 1, (int) $progress->attempts, 'A failed run call must consume one retry attempt.' );

		MainWP_DB_Backup::instance()->update_backup_task_progress( $task->id, $site_id, array( 'attempts' => 5 ) );
		try {
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
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
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
			$this->fail( 'Expected the stalled-retry failure to propagate.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertStringContainsString( 'child unreachable during retry', $e->getMessage() );
		}
	}

	/**
	 * Mock the child wire like the real driver race: the preflight succeeds,
	 * and by the time the child refuses the duplicate start, the concurrent
	 * driver of the SAME run has already registered its child task id on the
	 * shared progress row.
	 *
	 * @param int  $task_id  Task id whose progress row the other driver owns.
	 * @param int  $site_id  Site id.
	 * @param int  $refusals Incremented by reference when the refusal fires.
	 * @param bool $register Whether the other driver registers the child task
	 *                       id before the refusal returns (true = same-run
	 *                       collision, false = a foreign backup holds the slot).
	 *
	 * @return void
	 */
	private function mock_already_running_race( $task_id, $site_id, &$refusals, $register = true ) {
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what ) use ( $task_id, $site_id, &$refusals, $register ) {
				unset( $website );
				if ( 'backup_preflight' === $what ) {
					return array( 'time_limit_ok' => true );
				}
				if ( 'backup' === $what ) {
					++$refusals;
					if ( $register ) {
						MainWP_DB_Backup::instance()->update_backup_task_progress(
							$task_id,
							$site_id,
							array( 'child_backup_task_id' => 'task-other-driver' )
						);
					}
					return array( 'error' => 'Another backup process is running on this site.' );
				}
				return $result;
			},
			10,
			3
		);
	}

	/**
	 * The initiate slice, the progress poll, and the kicked continue cron can
	 * all drive the same run concurrently. When the child refuses a duplicate
	 * start because another driver of THIS run already holds its single
	 * backup slot, the refused driver must yield (return false, like the
	 * queued-spine path) instead of throwing: the collision is the run
	 * succeeding, not failing.
	 *
	 * @return void
	 */
	public function test_same_run_already_running_refusal_yields_to_owning_driver() {
		list( $site_id, $task ) = $this->site_and_task();

		$refusals = 0;
		$this->mock_already_running_race( $task->id, $site_id, $refusals, true );

		$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );

		$this->assertSame( 1, $refusals, 'Precondition: the mocked child must refuse the duplicate start.' );
		$this->assertFalse( $result, 'A same-run slot collision must yield (return false), not throw.' );
	}

	/**
	 * A refusal from a genuinely foreign backup (no driver of this run has
	 * registered a child task) still surfaces as ERR_ALREADY_RUNNING so the
	 * operator sees the blocker and the Cancel escape hatch stays reachable.
	 *
	 * @return void
	 */
	public function test_foreign_already_running_refusal_still_throws() {
		list( $site_id, $task ) = $this->site_and_task();

		$refusals = 0;
		$this->mock_already_running_race( $task->id, $site_id, $refusals, false );

		try {
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
			$this->fail( 'Expected the foreign already-running refusal to propagate.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 1, $refusals, 'Precondition: the mocked child must refuse the start.' );
			$this->assertSame( 'mainwp_backup_already_running', $e->get_message_error_code() );
		}
	}

	/**
	 * Through the full task runner, a same-run collision must leave no trace
	 * that reads as failure: backup_errors stays empty, the failure email
	 * never fires, and the run stays open so the owning driver can finish it.
	 * Before the fix this exact path recorded the refusal, closed the run,
	 * and emailed a failure for a backup that succeeded.
	 *
	 * @return void
	 */
	public function test_same_run_refusal_records_no_error_and_sends_no_failure_email() {
		$site_id = $this->create_test_site();
		$user    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$task    = MainWP_DB_Backup::instance()->add_backup_task(
			$user,
			'Driver race test task',
			'daily',
			'full',
			'',
			(string) $site_id,
			'',
			'',
			'',
			0,
			0,
			0,
			0,
			0,
			'zip',
			0,
			1,
			150,
			0,
			0
		);
		$this->assertNotFalse( $task, 'Precondition: the task row must insert.' );

		$mails = 0;
		add_filter(
			'pre_wp_mail',
			function () use ( &$mails ) {
				++$mails;
				return true;
			}
		);

		$refusals = 0;
		$this->mock_already_running_race( (int) $task->id, $site_id, $refusals, true );

		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, true );

		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertSame( 1, $refusals, 'Precondition: the run must reach the mocked child refusal.' );
		$this->assertSame( '', (string) $task->backup_errors, 'A same-run collision must not be recorded as a run error.' );
		$this->assertLessThan( (int) $task->last_run, (int) $task->completed, 'The run must stay open for the owning driver to finish it.' );
		$this->assertSame( 0, $mails, 'A same-run collision must not fire the failure email.' );
	}
}
