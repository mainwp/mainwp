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
use MainWP\Dashboard\MainWP_Backup_Handler;
use MainWP\Dashboard\MainWP_Backup_Monitor;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Exception;
use MainWP\Dashboard\MainWP_Manage_Backups_Handler;

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
				'task_spine'      => 1,
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
	 * @param int    $site_id            Site ID.
	 * @param array  $responses          Child action responses.
	 * @param string $archive_format     Task archive format (zip, tar.gz, ...).
	 * @param int    $zip_force_override Task zip-force override flag.
	 * @param string $filename           Task filename template.
	 *
	 * @return MainWP_Exception|null The exception raised, if any.
	 */
	private function run_backup_site( $site_id, $responses, $archive_format = 'zip', $zip_force_override = 0, $filename = '' ) {
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
			'filename'                       => $filename,
			'archiveFormat'                  => $archive_format,
			'zip_force_override'             => $zip_force_override,
			'loadFilesBeforeZip'             => 1,
			'maximumFileDescriptorsOverride' => 0,
			'maximumFileDescriptorsAuto'     => 1,
			'maximumFileDescriptors'         => 150,
			'last_run'                       => time() - 100,
			'subfolder'                      => 'backups',
		);

		if ( isset( $responses['backup'] ) && ! isset( $responses['backup_task_create'] ) ) {
			$responses['backup_task_create'] = $responses['backup'];
		}
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
	 * Capture mainwp_log_warning messages fired during a backup run.
	 *
	 * @param int    $site_id            Site ID.
	 * @param array  $preflight          Preflight response overrides.
	 * @param string $archive_format     Task archive format.
	 * @param int    $zip_force_override Task zip-force override flag.
	 *
	 * @return array The warning messages logged.
	 */
	private function capture_backup_warnings( $site_id, $preflight, $archive_format, $zip_force_override = 0 ) {
		$warnings = array();
		add_action(
			'mainwp_log_warning',
			function ( $message ) use ( &$warnings ) {
				$warnings[] = $message;
			}
		);

		$this->run_backup_site(
			$site_id,
			array(
				'backup_preflight' => $preflight,
				'backup'           => array( 'error' => 'stop here' ),
			),
			$archive_format,
			$zip_force_override
		);

		return $warnings;
	}

	/**
	 * Capture the archive extension the dashboard actually sends the child in
	 * the backup request, after preflight negotiation. This is what proves the
	 * ZIP auto-switch reaches the wire, not just the logged warning.
	 *
	 * @param int    $site_id            Site ID.
	 * @param array  $preflight          Preflight response.
	 * @param string $archive_format     Task archive format.
	 * @param int    $zip_force_override 1 to keep ZIP over the guardrail.
	 *
	 * @return string|null The ext param sent to the child, or null.
	 */
	private function captured_backup_ext( $site_id, $preflight, $archive_format, $zip_force_override = 0 ) {
		$captured = array( 'ext' => null );

		// Priority 5, 4 args: observe the negotiated params without
		// short-circuiting, so mock_child_actions still supplies the responses.
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what, $params ) use ( &$captured ) {
				unset( $website );
				if ( 'backup_task_create' === $what && isset( $params['ext'] ) ) {
					$captured['ext'] = $params['ext'];
				}
				return $result;
			},
			5,
			4
		);

		$this->run_backup_site(
			$site_id,
			array(
				'backup_preflight' => $preflight,
				'backup'           => array( 'error' => 'stop here' ),
			),
			$archive_format,
			$zip_force_override
		);

		return $captured['ext'];
	}

	/**
	 * Capture the requested filename the dashboard sends the child in the
	 * backup request, after preflight negotiation. The filename extension
	 * must follow the negotiated format, not the pre-negotiation one.
	 *
	 * @param int    $site_id            Site ID.
	 * @param array  $preflight          Preflight response.
	 * @param string $archive_format     Task archive format.
	 * @param int    $zip_force_override 1 to keep ZIP over the guardrail.
	 *
	 * @return string|null The f param sent to the child, or null.
	 */
	private function captured_backup_filename( $site_id, $preflight, $archive_format, $zip_force_override = 0 ) {
		$captured = array( 'f' => null );

		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what, $params ) use ( &$captured ) {
				unset( $website );
				if ( 'backup_task_create' === $what && isset( $params['f'] ) ) {
					$captured['f'] = $params['f'];
				}
				return $result;
			},
			5,
			4
		);

		$this->run_backup_site(
			$site_id,
			array(
				'backup_preflight' => $preflight,
				'backup'           => array( 'error' => 'stop here' ),
			),
			$archive_format,
			$zip_force_override,
			'backup-%sitename%'
		);

		return $captured['f'];
	}

	/**
	 * When the large-site guardrail switches ZIP to tar.gz, the requested
	 * filename must carry the switched extension too. A stale .zip in the
	 * name misleads any consumer that reads the format off the filename.
	 *
	 * @return void
	 */
	public function test_zip_auto_switch_renames_requested_file_to_targz() {
		$file = $this->captured_backup_filename(
			$this->create_test_site(),
			$this->preflight_response( array( 'estimated_bytes' => 3 * GB_IN_BYTES ) ),
			'zip'
		);

		$this->assertNotEmpty( $file, 'The filename template must reach the wire.' );
		$this->assertStringEndsWith( '.tar.gz', $file, 'A switched backup must request a .tar.gz filename.' );
		$this->assertStringNotContainsString( '.zip', $file, 'The pre-switch .zip must not survive anywhere in the filename.' );
	}

	/**
	 * With the override keeping ZIP, the requested filename keeps its .zip
	 * extension.
	 *
	 * @return void
	 */
	public function test_zip_force_override_keeps_zip_filename() {
		$file = $this->captured_backup_filename(
			$this->create_test_site(),
			$this->preflight_response( array( 'estimated_bytes' => 3 * GB_IN_BYTES ) ),
			'zip',
			1
		);

		$this->assertNotEmpty( $file, 'The filename template must reach the wire.' );
		$this->assertStringEndsWith( '.zip', $file, 'An override-kept ZIP must request a .zip filename.' );
	}

	/**
	 * Keeping ZIP on a large site (the user checked the override) still warns
	 * before the backup runs. ZIP restarts from zero on each slice timeout;
	 * tar.gz appends and resumes.
	 *
	 * @return void
	 */
	public function test_zip_on_large_site_warns_when_override_set() {
		$warnings = $this->capture_backup_warnings(
			$this->create_test_site(),
			$this->preflight_response( array( 'estimated_bytes' => 3 * GB_IN_BYTES ) ),
			'zip',
			1
		);

		$this->assertNotEmpty(
			array_filter(
				$warnings,
				function ( $message ) {
					return false !== stripos( $message, 'ZIP is not recommended' );
				}
			),
			'Keeping ZIP over the guardrail must still log the large-site warning.'
		);
	}

	/**
	 * With no override, a large ZIP site is auto-switched to tar.gz: the
	 * extension the dashboard actually sends the child is tar.gz, not zip.
	 *
	 * @return void
	 */
	public function test_zip_auto_switched_to_targz_when_over_threshold() {
		$ext = $this->captured_backup_ext(
			$this->create_test_site(),
			$this->preflight_response( array( 'estimated_bytes' => 3 * GB_IN_BYTES ) ),
			'zip'
		);

		$this->assertSame( 'tar.gz', $ext, 'A large ZIP site with no override must be switched to tar.gz on the wire.' );
	}

	/**
	 * With the override set, a large ZIP site stays on ZIP: the user made an
	 * explicit choice, so the dashboard sends zip.
	 *
	 * @return void
	 */
	public function test_zip_force_override_honored_when_set() {
		$ext = $this->captured_backup_ext(
			$this->create_test_site(),
			$this->preflight_response( array( 'estimated_bytes' => 3 * GB_IN_BYTES ) ),
			'zip',
			1
		);

		$this->assertSame( 'zip', $ext, 'The override must keep ZIP on the wire over the guardrail.' );
	}

	/**
	 * A ZIP site under the threshold is not switched.
	 *
	 * @return void
	 */
	public function test_zip_auto_switch_not_triggered_when_under_threshold() {
		$ext = $this->captured_backup_ext(
			$this->create_test_site(),
			$this->preflight_response( array( 'estimated_bytes' => 100 * MB_IN_BYTES ) ),
			'zip'
		);

		$this->assertSame( 'zip', $ext, 'A small ZIP site must stay on ZIP.' );
	}

	/**
	 * A tar.gz task over the threshold is untouched: the switch only applies
	 * to ZIP.
	 *
	 * @return void
	 */
	public function test_zip_auto_switch_not_triggered_for_targz_format() {
		$ext = $this->captured_backup_ext(
			$this->create_test_site(),
			$this->preflight_response( array( 'estimated_bytes' => 3 * GB_IN_BYTES ) ),
			'tar.gz'
		);

		$this->assertSame( 'tar.gz', $ext, 'A tar.gz task must be left as tar.gz.' );
	}

	/**
	 * A task-less negotiation (no $task passed, e.g. an ad-hoc path) must not
	 * fatal on the missing override and must still auto-switch a large ZIP.
	 *
	 * @return void
	 */
	public function test_zip_auto_switch_with_null_task_does_not_fatal() {
		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );

		$this->mock_child_actions(
			array( 'backup_preflight' => $this->preflight_response( array( 'estimated_bytes' => 3 * GB_IN_BYTES ) ) )
		);

		$ext = 'zip';
		MainWP_Backup_Monitor::negotiate_backup_preflight( $website, $ext, null );

		$this->assertSame( 'tar.gz', $ext, 'A null-task negotiation must still auto-switch a large ZIP without fataling.' );
	}

	/**
	 * A small site keeps ZIP available without any guardrail warning.
	 *
	 * @return void
	 */
	public function test_zip_under_threshold_is_silent() {
		$warnings = $this->capture_backup_warnings(
			$this->create_test_site(),
			$this->preflight_response( array( 'estimated_bytes' => 100 * MB_IN_BYTES ) ),
			'zip'
		);

		$this->assertEmpty(
			array_filter(
				$warnings,
				function ( $message ) {
					return false !== stripos( $message, 'ZIP is not recommended' );
				}
			),
			'A small ZIP site must not trigger the guardrail.'
		);
	}

	/**
	 * The default tar.gz path resumes across timeouts, so a large site on
	 * tar.gz never sees the ZIP guardrail warning.
	 *
	 * @return void
	 */
	public function test_targz_on_large_site_is_silent() {
		$warnings = $this->capture_backup_warnings(
			$this->create_test_site(),
			$this->preflight_response( array( 'estimated_bytes' => 3 * GB_IN_BYTES ) ),
			'tar.gz'
		);

		$this->assertEmpty(
			array_filter(
				$warnings,
				function ( $message ) {
					return false !== stripos( $message, 'ZIP is not recommended' );
				}
			),
			'tar.gz must not trigger the ZIP guardrail.'
		);
	}

	/**
	 * The guardrail threshold is filterable, so a site can be flagged at a
	 * lower size than the 2GB default.
	 *
	 * @return void
	 */
	public function test_zip_guardrail_threshold_is_filterable() {
		add_filter(
			'mainwp_zip_guardrail_bytes',
			function () {
				return 1 * MB_IN_BYTES;
			}
		);

		$warnings = $this->capture_backup_warnings(
			$this->create_test_site(),
			$this->preflight_response( array( 'estimated_bytes' => 50 * MB_IN_BYTES ) ),
			'zip',
			1
		);

		$this->assertNotEmpty(
			array_filter(
				$warnings,
				function ( $message ) {
					return false !== stripos( $message, 'ZIP is not recommended' );
				}
			),
			'Lowering the guardrail threshold must flag a smaller site.'
		);
	}

	/**
	 * The guardrail compares byte quantities as floats, so a float threshold
	 * from the filter and a string-numeric estimate from the child JSON still
	 * switch the format on the wire. On 32-bit PHP the old (int) casts
	 * overflowed on the 2GB default and silently disabled the guardrail; that
	 * regression cannot execute on a 64-bit machine, so this locks the
	 * float-safe handling by construction.
	 *
	 * @return void
	 */
	public function test_guardrail_compares_bytes_as_floats() {
		add_filter(
			'mainwp_zip_guardrail_bytes',
			function () {
				return 1.5 * MB_IN_BYTES; // Float threshold.
			}
		);

		$ext = $this->captured_backup_ext(
			$this->create_test_site(),
			$this->preflight_response( array( 'estimated_bytes' => (string) ( 50 * MB_IN_BYTES ) ) ),
			'zip'
		);

		$this->assertSame( 'tar.gz', $ext, 'A string-numeric estimate over a float threshold must still switch the wire format.' );
	}

	/**
	 * An old child that does not report a size estimate gets no guardrail
	 * warning and no fatal error.
	 *
	 * @return void
	 */
	public function test_zip_without_estimate_is_silent() {
		$warnings = $this->capture_backup_warnings(
			$this->create_test_site(),
			$this->preflight_response(),
			'zip'
		);

		$this->assertEmpty(
			array_filter(
				$warnings,
				function ( $message ) {
					return false !== stripos( $message, 'ZIP is not recommended' );
				}
			),
			'A preflight without estimated_bytes must not trigger the guardrail.'
		);
	}

	/**
	 * A very large file count logs a memory advisory regardless of the
	 * archive format, since the file-walker holds the entry list in memory.
	 *
	 * @return void
	 */
	public function test_large_file_count_logs_memory_advisory() {
		$warnings = $this->capture_backup_warnings(
			$this->create_test_site(),
			$this->preflight_response( array( 'file_count' => 200000 ) ),
			'tar.gz'
		);

		$this->assertNotEmpty(
			array_filter(
				$warnings,
				function ( $message ) {
					return false !== stripos( $message, 'large file count' );
				}
			),
			'A large file count must log a memory advisory.'
		);
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
	 * A completely full child disk (0 bytes free) blocks the backup AND still
	 * renders the free space in the error message: size_format() returns
	 * false for a float zero (its zero case is a strict int check), so the
	 * display cast must stay int even while the comparisons go float.
	 *
	 * @return void
	 */
	public function test_zero_disk_free_reports_zero_bytes_in_error() {
		$site_id = $this->create_test_site();

		$exception = $this->run_backup_site(
			$site_id,
			array(
				'backup_preflight' => $this->preflight_response( array( 'disk_free' => 0 ) ),
				'backup'           => array( 'error' => 'must not reach the backup action' ),
			)
		);

		$this->assertNotNull( $exception );
		$this->assertSame( 'mainwp_backup_disk_full', $exception->get_message_error_code() );
		$this->assertStringContainsString( '0 B', $exception->getMessage(), 'The message must render the free space, not a blank.' );
	}

	/**
	 * An old child without the preflight action is refused by the task-spine gate.
	 *
	 * @return void
	 */
	public function test_preflight_error_response_requires_child_update() {
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
		$this->assertSame( 'mainwp_backup_child_update_required', $exception->get_message_error_code() );
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

	/**
	 * Run a real DB task against one site through the "Run now" per-site flow
	 * (MainWP_Manage_Backups_Handler::backup), capturing the ext param the
	 * dashboard sends the child.
	 *
	 * @param int $site_id            Site id.
	 * @param int $zip_force_override 1 to keep ZIP over the guardrail.
	 *
	 * @return array{0:string|null,1:object} Captured ext and the task row after the run.
	 */
	private function run_site_flow_captured_ext( $site_id, $zip_force_override ) {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$task = MainWP_DB_Backup::instance()->add_backup_task(
			$user,
			'Run-site override test',
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
			$zip_force_override
		);
		$this->assertNotFalse( $task, 'Precondition: the task row must insert.' );

		$captured = array( 'ext' => null );
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what, $params ) use ( &$captured ) {
				unset( $website );
				if ( 'backup' === $what && isset( $params['ext'] ) ) {
					$captured['ext'] = $params['ext'];
				}
				return $result;
			},
			5,
			4
		);

		$this->mock_child_actions(
			array(
				'backup_preflight' => $this->preflight_response( array( 'estimated_bytes' => 3 * GB_IN_BYTES ) ),
				'backup'           => array( 'error' => 'stop here' ),
			)
		);

		try {
			MainWP_Manage_Backups_Handler::backup( $task->id, $site_id, 'uid-run-site' );
		} catch ( MainWP_Exception $e ) {
			// The mocked child stops the run after negotiation, as intended.
		}

		return array( $captured['ext'], MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id ) );
	}

	/**
	 * The "Run now" per-site flow must honor the task's keep-ZIP override
	 * exactly like the scheduled runner: the same task must not change
	 * archive format depending on which button started it.
	 *
	 * @return void
	 */
	public function test_run_site_path_honors_zip_force_override() {
		list( $ext, ) = $this->run_site_flow_captured_ext( $this->create_test_site(), 1 );

		$this->assertSame( 'zip', $ext, 'The per-site run must keep ZIP when the task forces it.' );
	}

	/**
	 * Without the override, the per-site run auto-switches a large ZIP site
	 * and records the switch on the task's notices channel, so the change of
	 * format is never silent.
	 *
	 * @return void
	 */
	public function test_run_site_path_switches_and_records_notice_without_override() {
		list( $ext, $task ) = $this->run_site_flow_captured_ext( $this->create_test_site(), 0 );

		$this->assertSame( 'tar.gz', $ext, 'The per-site run must auto-switch a large ZIP site.' );
		$this->assertStringContainsString( 'switched to tar.gz', (string) $task->backup_notices, 'The switch must be recorded on the task, not applied silently.' );
	}

	/**
	 * The deprecated MainWP_Backup_Handler shim takes the extension by value,
	 * so its callers can never receive a negotiated format. It must therefore
	 * negotiate warn-only: logging "was switched" while the legacy caller
	 * keeps sending ZIP would make the log lie about what went on the wire.
	 *
	 * @return void
	 */
	public function test_deprecated_shim_negotiates_warn_only() {
		$this->setExpectedDeprecated( 'MainWP\Dashboard\MainWP_Backup_Handler::negotiate_backup_preflight' );

		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );

		$this->mock_child_actions(
			array( 'backup_preflight' => $this->preflight_response( array( 'estimated_bytes' => 3 * GB_IN_BYTES ) ) )
		);

		$captured = array();
		add_action(
			'mainwp_log_warning',
			function ( $message ) use ( &$captured ) {
				$captured[] = $message;
			}
		);

		MainWP_Backup_Handler::negotiate_backup_preflight( $website, 'zip' );

		$this->assertEmpty(
			array_filter(
				$captured,
				function ( $message ) {
					return false !== stripos( $message, 'switched' );
				}
			),
			'The shim must not log a switch its caller cannot receive.'
		);
		$this->assertNotEmpty(
			array_filter(
				$captured,
				function ( $message ) {
					return false !== stripos( $message, 'not recommended' );
				}
			),
			'The shim still warns about ZIP on a large site.'
		);
	}
}
