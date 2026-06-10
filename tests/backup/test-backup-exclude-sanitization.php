<?php
/**
 * MainWP Backup Exclude Sanitization Tests
 *
 * The exclude list a backup sends to the child site names folders
 * relative to the child WordPress root. Entries must not be able to
 * carry path traversal sequences, absolute paths, or null bytes to the
 * child. These tests cover the sanitizer contract and verify the value
 * actually transmitted to the child is clean.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Executor;
use MainWP\Dashboard\MainWP_Backup_Settings;

/**
 * Class MainWP_Backup_Exclude_Sanitization_Test
 */
class MainWP_Backup_Exclude_Sanitization_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Set up: the backup entry point requires the legacy feature flag.
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
		delete_option( 'mainwp_enableLegacyBackupFeature' );
		parent::tearDown();
	}

	/**
	 * Traversal sequences are removed while the harmless remainder of the
	 * entry survives as a relative path.
	 *
	 * @return void
	 */
	public function test_traversal_sequences_are_removed() {
		$this->assertSame( 'etc/passwd', MainWP_Backup_Settings::sanitize_exclude_list( '../../etc/passwd' ) );
		$this->assertSame( 'wp-content/etc', MainWP_Backup_Settings::sanitize_exclude_list( 'wp-content/../../etc' ) );
	}

	/**
	 * Null bytes and control characters cannot reach the child.
	 *
	 * @return void
	 */
	public function test_null_bytes_and_control_characters_are_stripped() {
		$result = MainWP_Backup_Settings::sanitize_exclude_list( "wp-content/ca\0che/../secret\x01" );

		$this->assertStringNotContainsString( "\0", $result );
		$this->assertStringNotContainsString( "\x01", $result );
		$this->assertStringNotContainsString( '..', $result );
	}

	/**
	 * Absolute paths become child-relative: unix leading slashes and
	 * Windows drive prefixes are dropped.
	 *
	 * @return void
	 */
	public function test_absolute_paths_become_relative() {
		$this->assertSame( 'etc/passwd', MainWP_Backup_Settings::sanitize_exclude_list( '/etc/passwd' ) );
		$this->assertSame( 'windows/system32', MainWP_Backup_Settings::sanitize_exclude_list( 'C:\\windows\\system32' ) );
	}

	/**
	 * Entries with nothing safe left are dropped instead of sending empty
	 * or dot-only values.
	 *
	 * @return void
	 */
	public function test_entries_reduced_to_nothing_are_dropped() {
		$this->assertSame( '', MainWP_Backup_Settings::sanitize_exclude_list( '..' ) );
		$this->assertSame( '', MainWP_Backup_Settings::sanitize_exclude_list( '.' ) );
		$this->assertSame( 'wp-content/cache', MainWP_Backup_Settings::sanitize_exclude_list( "..\nwp-content/cache" ) );
		$this->assertSame( '', MainWP_Backup_Settings::sanitize_exclude_list( '' ) );
	}

	/**
	 * Legitimate child-relative folders pass through untouched, from
	 * newline-separated, comma-separated, and array input alike.
	 *
	 * @return void
	 */
	public function test_valid_entries_survive_all_input_shapes() {
		$expected = 'wp-content/cache,wp-content/uploads/large';

		$this->assertSame( $expected, MainWP_Backup_Settings::sanitize_exclude_list( "wp-content/cache\nwp-content/uploads/large" ) );
		$this->assertSame( $expected, MainWP_Backup_Settings::sanitize_exclude_list( 'wp-content/cache,wp-content/uploads/large' ) );
		$this->assertSame( $expected, MainWP_Backup_Settings::sanitize_exclude_list( array( 'wp-content/cache', 'wp-content/uploads/large' ) ) );
	}

	/**
	 * Mixed lists keep the valid entries and neutralize the hostile ones.
	 *
	 * @return void
	 */
	public function test_mixed_list_neutralizes_only_hostile_entries() {
		$this->assertSame(
			'wp-content/cache,etc',
			MainWP_Backup_Settings::sanitize_exclude_list( 'wp-content/cache,../etc' )
		);
	}

	/**
	 * The exclude value actually transmitted to the child during a manual
	 * backup request is sanitized, regardless of what the caller passed.
	 *
	 * @return void
	 */
	public function test_transmitted_exclude_is_sanitized() {
		$site_id  = $this->create_test_site();
		$captured = null;

		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what, $params ) use ( &$captured ) {
				unset( $website );
				if ( 'backup_preflight' === $what ) {
					return array( 'error' => 'Unknown action' );
				}
				if ( 'backup' === $what ) {
					$captured = $params['exclude'];
					return array( 'error' => 'stop here' );
				}
				return $result;
			},
			10,
			4
		);

		// backup() flushes open output buffers via end_session(); give it a
		// sacrificial buffer so it cannot close the PHPUnit wrapper buffer.
		$ob_level = ob_get_level();
		ob_start();
		try {
			MainWP_Backup_Executor::backup( $site_id, 'full', '', '../../etc/passwd,wp-content/uploads', 0, 0, 0, 0, '', '', 'zip' );
		} catch ( \Exception $e ) {
			unset( $e ); // The mocked child response ends the request after transmission.
		}
		while ( ob_get_level() > $ob_level ) {
			ob_end_clean();
		}

		$this->assertSame( 'etc/passwd,wp-content/uploads', $captured, 'The transmitted exclude list must be sanitized.' );
	}
}
