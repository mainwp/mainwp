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

use MainWP\Dashboard\MainWP_Backup_Handler;
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
		update_option( 'mainwp_enableLegacyBackupFeature', 1 );
	}

	/**
	 * Clean up.
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
			MainWP_Backup_Handler::backup_site( $site_id, $task, 'backups' );
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
