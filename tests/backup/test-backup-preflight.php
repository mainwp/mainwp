<?php
/**
 * MainWP Backup Preflight Tests
 *
 * Tests for the capability negotiation the dashboard performs before
 * requesting a backup: storing the child backup directory hash,
 * blocking on critically low child disk space, and proceeding without
 * preflight when the child site does not support it.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Executor;
use MainWP\Dashboard\MainWP_Backup_Monitor;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Exception;

/**
 * Class MainWP_Backup_Preflight_Test
 */
class MainWP_Backup_Preflight_Test extends MainWP_Abilities_Test_Case {

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
	 * Clean up.
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
	 * A healthy preflight response.
	 *
	 * @param array $overrides Key overrides.
	 *
	 * @return array Preflight payload.
	 */
	private function preflight_response( $overrides = array() ) {
		return array_merge(
			array(
				'disk_free'       => 10 * GB_IN_BYTES,
				'dir_writable'    => true,
				'db_connected'    => true,
				'memory_limit'    => '256M',
				'max_exec_time'   => '30',
				'zip_support'     => true,
				'gz_support'      => true,
				'bz2_support'     => true,
				'time_limit_ok'   => true,
				'backup_dir_hash' => 'abc123def456gh78',
			),
			$overrides
		);
	}

	/**
	 * Mock per-action child responses.
	 *
	 * @param array $responses Map of action => response array.
	 *
	 * @return void
	 */
	private function mock_child_actions( $responses ) {
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what ) use ( $responses ) {
				unset( $website );
				return isset( $responses[ $what ] ) ? $responses[ $what ] : $result;
			},
			10,
			3
		);
	}

	/**
	 * Run backup_site() with a mocked child, tolerating the expected
	 * failure after the negotiation phase.
	 *
	 * @param int   $site_id   Site ID.
	 * @param array $responses Child action responses.
	 *
	 * @return MainWP_Exception|null The exception raised, if any.
	 */
	private function run_backup_site( $site_id, $responses ) {
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$task    = (object) array(
			'id'                             => 1,
			'userid'                         => $website->userid,
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
		);

		$this->mock_child_actions( $responses );

		try {
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
		} catch ( MainWP_Exception $e ) {
			return $e;
		}
		return null;
	}

	/**
	 * The child backup directory hash from preflight is stored in site metadata.
	 *
	 * @return void
	 */
	public function test_preflight_hash_stored_in_site_metadata() {
		$site_id = $this->create_test_site();

		$this->run_backup_site(
			$site_id,
			array(
				'backup_preflight' => $this->preflight_response(),
				'backup'           => array( 'error' => 'stop here' ),
			)
		);

		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$this->assertSame( 'abc123def456gh78', MainWP_DB::instance()->get_website_option( $website, 'backup_dir_hash' ) );
	}

	/**
	 * A child that advertises download token support gets the capability
	 * stored in site metadata.
	 *
	 * @return void
	 */
	public function test_preflight_stores_download_token_support() {
		$site_id = $this->create_test_site();

		$this->run_backup_site(
			$site_id,
			array(
				'backup_preflight' => $this->preflight_response( array( 'download_token' => 1 ) ),
				'backup'           => array( 'error' => 'stop here' ),
			)
		);

		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$this->assertSame( '1', MainWP_DB::instance()->get_website_option( $website, 'download_token_support' ) );
		$this->assertTrue( MainWP_Backup_Monitor::child_supports_download_token( $website ) );
	}

	/**
	 * A preflight without the capability clears a previously stored flag,
	 * covering a child downgrade.
	 *
	 * @return void
	 */
	public function test_preflight_clears_download_token_support_when_omitted() {
		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		MainWP_DB::instance()->update_website_option( $website, 'download_token_support', '1' );

		$this->run_backup_site(
			$site_id,
			array(
				'backup_preflight' => $this->preflight_response(),
				'backup'           => array( 'error' => 'stop here' ),
			)
		);

		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$this->assertFalse( MainWP_Backup_Monitor::child_supports_download_token( $website ) );
	}

	/**
	 * A site that never reported the capability does not support it.
	 *
	 * @return void
	 */
	public function test_child_supports_download_token_false_when_never_reported() {
		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );

		$this->assertFalse( MainWP_Backup_Monitor::child_supports_download_token( $website ) );
	}

	/**
	 * A child that advertises incremental backup support gets the capability
	 * stored in site metadata.
	 *
	 * @return void
	 */
	public function test_preflight_stores_incremental_support() {
		$site_id = $this->create_test_site();

		$this->run_backup_site(
			$site_id,
			array(
				'backup_preflight' => $this->preflight_response( array( 'incremental' => 1 ) ),
				'backup'           => array( 'error' => 'stop here' ),
			)
		);

		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$this->assertSame( '1', MainWP_DB::instance()->get_website_option( $website, 'incremental_support' ) );
		$this->assertTrue( MainWP_Backup_Monitor::child_supports_incremental( $website ) );
	}

	/**
	 * A preflight without the incremental capability clears a previously
	 * stored flag, covering a child downgrade.
	 *
	 * @return void
	 */
	public function test_preflight_clears_incremental_support_when_omitted() {
		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		MainWP_DB::instance()->update_website_option( $website, 'incremental_support', '1' );

		$this->run_backup_site(
			$site_id,
			array(
				'backup_preflight' => $this->preflight_response(),
				'backup'           => array( 'error' => 'stop here' ),
			)
		);

		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$this->assertFalse( MainWP_Backup_Monitor::child_supports_incremental( $website ) );
	}

	/**
	 * A child that advertises files-only restore support gets the
	 * capability stored in site metadata.
	 *
	 * @return void
	 */
	public function test_preflight_stores_restore_files_support() {
		$site_id = $this->create_test_site();

		$this->run_backup_site(
			$site_id,
			array(
				'backup_preflight' => $this->preflight_response( array( 'restore_files' => 1 ) ),
				'backup'           => array( 'error' => 'stop here' ),
			)
		);

		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$this->assertSame( '1', MainWP_DB::instance()->get_website_option( $website, 'restore_files_support' ) );
		$this->assertTrue( MainWP_Backup_Monitor::child_supports_restore_files( $website ) );
	}

	/**
	 * A preflight without the files-only restore capability clears a
	 * previously stored flag, covering a child downgrade.
	 *
	 * @return void
	 */
	public function test_preflight_clears_restore_files_support_when_omitted() {
		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		MainWP_DB::instance()->update_website_option( $website, 'restore_files_support', '1' );

		$this->run_backup_site(
			$site_id,
			array(
				'backup_preflight' => $this->preflight_response(),
				'backup'           => array( 'error' => 'stop here' ),
			)
		);

		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$this->assertFalse( MainWP_Backup_Monitor::child_supports_restore_files( $website ) );
	}

	/**
	 * A child that advertises cloud-pull restore support gets the
	 * capability stored in site metadata.
	 *
	 * @return void
	 */
	public function test_preflight_stores_cloud_restore_pull_support() {
		$site_id = $this->create_test_site();

		$this->run_backup_site(
			$site_id,
			array(
				'backup_preflight' => $this->preflight_response( array( 'cloud_restore_pull' => 1 ) ),
				'backup'           => array( 'error' => 'stop here' ),
			)
		);

		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$this->assertSame( '1', MainWP_DB::instance()->get_website_option( $website, 'cloud_restore_pull_support' ) );
		$this->assertTrue( MainWP_Backup_Monitor::child_supports_cloud_pull( $website ) );
	}

	/**
	 * A preflight without the cloud-pull capability clears a previously
	 * stored flag, covering a child downgrade.
	 *
	 * @return void
	 */
	public function test_preflight_clears_cloud_restore_pull_support_when_omitted() {
		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		MainWP_DB::instance()->update_website_option( $website, 'cloud_restore_pull_support', '1' );

		$this->run_backup_site(
			$site_id,
			array(
				'backup_preflight' => $this->preflight_response(),
				'backup'           => array( 'error' => 'stop here' ),
			)
		);

		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$this->assertFalse( MainWP_Backup_Monitor::child_supports_cloud_pull( $website ) );
	}

	/**
	 * A multisite child that advertises network restore gets both flags
	 * stored; omitting them clears previously stored values.
	 *
	 * @return void
	 */
	public function test_preflight_stamps_multisite_flags() {
		$site_id = $this->create_test_site();

		$this->run_backup_site(
			$site_id,
			array(
				'backup_preflight' => $this->preflight_response(
					array(
						'is_multisite'      => 1,
						'restore_multisite' => 1,
					)
				),
				'backup'           => array( 'error' => 'stop here' ),
			)
		);

		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$this->assertTrue( MainWP_Backup_Monitor::child_is_multisite( $website ) );
		$this->assertTrue( MainWP_Backup_Monitor::child_supports_restore_multisite( $website ) );
	}

	/**
	 * A preflight without the multisite keys clears previously stored
	 * flags, covering a downgrade or a dissolved network.
	 *
	 * @return void
	 */
	public function test_preflight_clears_multisite_flags_when_omitted() {
		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		MainWP_DB::instance()->update_website_option( $website, 'child_is_multisite', '1' );
		MainWP_DB::instance()->update_website_option( $website, 'restore_multisite_support', '1' );

		$this->run_backup_site(
			$site_id,
			array(
				'backup_preflight' => $this->preflight_response(),
				'backup'           => array( 'error' => 'stop here' ),
			)
		);

		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$this->assertFalse( MainWP_Backup_Monitor::child_is_multisite( $website ), 'An omitted key clears the stored flag.' );
		$this->assertFalse( MainWP_Backup_Monitor::child_supports_restore_multisite( $website ) );
	}

	/**
	 * A site that never reported incremental support does not support it.
	 *
	 * @return void
	 */
	public function test_child_supports_incremental_false_when_never_reported() {
		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );

		$this->assertFalse( MainWP_Backup_Monitor::child_supports_incremental( $website ) );
	}

	/**
	 * Critically low disk space on the child site blocks the backup
	 * before any backup work starts.
	 *
	 * @return void
	 */
	public function test_low_child_disk_space_blocks_backup() {
		$site_id = $this->create_test_site();

		$exception = $this->run_backup_site(
			$site_id,
			array(
				'backup_preflight' => $this->preflight_response( array( 'disk_free' => 10 * MB_IN_BYTES ) ),
				'backup'           => array( 'error' => 'must not reach the backup action' ),
			)
		);

		$this->assertNotNull( $exception );
		$this->assertSame( 'mainwp_backup_disk_full', $exception->get_message_error_code() );
	}

	/**
	 * An old child without the preflight action does not block backups.
	 *
	 * @return void
	 */
	public function test_preflight_error_response_proceeds_with_backup() {
		$site_id = $this->create_test_site();

		$exception = $this->run_backup_site(
			$site_id,
			array(
				'backup_preflight' => array( 'error' => 'Unknown action' ),
				'backup'           => array(
					'error'      => 'reached the backup action',
					'error_code' => 'mainwp_backup_disk_full',
				),
			)
		);

		$this->assertNotNull( $exception );
		$this->assertStringContainsString( 'reached the backup action', $exception->getMessage(), 'The backup request must still happen when preflight is unsupported.' );
	}

	/**
	 * A disabled set_time_limit on the child logs a warning and proceeds.
	 *
	 * @return void
	 */
	public function test_time_limit_disabled_warns_and_proceeds() {
		$site_id  = $this->create_test_site();
		$warnings = array();
		add_action(
			'mainwp_log_warning',
			function ( $message ) use ( &$warnings ) {
				$warnings[] = $message;
			}
		);

		$exception = $this->run_backup_site(
			$site_id,
			array(
				'backup_preflight' => $this->preflight_response( array( 'time_limit_ok' => false ) ),
				'backup'           => array( 'error' => 'reached the backup action' ),
			)
		);

		$this->assertNotNull( $exception );
		$this->assertStringContainsString( 'reached the backup action', $exception->getMessage() );
		$this->assertNotEmpty(
			array_filter(
				$warnings,
				function ( $message ) {
					return false !== stripos( $message, 'set_time_limit' );
				}
			),
			'A warning about set_time_limit must be logged.'
		);
	}
}
