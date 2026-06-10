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
