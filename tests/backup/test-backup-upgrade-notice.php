<?php
/**
 * MainWP Backup Upgrade Notice Tests
 *
 * Tests for the one-time admin notice telling the never-used-backups
 * upgrade cohort that Native Backups was defaulted off.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_System_View;

/**
 * Class MainWP_Backup_Upgrade_Notice_Test
 */
class MainWP_Backup_Upgrade_Notice_Test extends \WP_UnitTestCase {

	/**
	 * Remove the marker and any per-user dismissal.
	 *
	 * @return void
	 */
	public function tear_down() {
		delete_option( 'mainwp_native_backups_defaulted_off_on_upgrade' );
		delete_option( 'mainwp_enableNativeBackups' );
		delete_option( 'mainwp_enableLegacyBackupFeature' );
		if ( get_current_user_id() ) {
			delete_user_option( get_current_user_id(), 'mainwp_notice_saved_status' );
		}
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Render the notice and capture its output.
	 *
	 * @return string
	 */
	private function render_notice() {
		ob_start();
		MainWP_System_View::render_native_backups_default_off_notice();
		return (string) ob_get_clean();
	}

	/**
	 * The marker makes the notice render, dismissible under its notice id.
	 *
	 * @return void
	 */
	public function test_marker_renders_dismissible_notice() {
		update_option( 'mainwp_native_backups_defaulted_off_on_upgrade', 1 );
		update_option( 'mainwp_enableNativeBackups', 0 );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$output = $this->render_notice();

		$this->assertStringContainsString( 'mainwp-notice-dismiss', $output );
		$this->assertStringContainsString( 'mainwp_native_backups_defaulted_off_on_upgrade', $output );
		$this->assertStringContainsString( 'admin.php?page=BackupSettings', $output );
		$this->assertStringContainsString( 'ui blue message', $output );
	}

	/**
	 * Without the marker (fresh install, or an install that made an
	 * explicit choice) nothing renders.
	 *
	 * @return void
	 */
	public function test_no_marker_renders_nothing() {
		delete_option( 'mainwp_native_backups_defaulted_off_on_upgrade' );

		$this->assertSame( '', $this->render_notice() );
	}

	/**
	 * A user who dismissed the notice never sees it again.
	 *
	 * @return void
	 */
	public function test_dismissed_notice_stays_hidden() {
		update_option( 'mainwp_native_backups_defaulted_off_on_upgrade', 1 );
		update_option( 'mainwp_enableNativeBackups', 0 );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$this->assertNotSame( '', $this->render_notice(), 'Undismissed, the notice must render.' );

		update_user_option( $admin, 'mainwp_notice_saved_status', array( 'mainwp_native_backups_defaulted_off_on_upgrade' => 1 ) );

		$this->assertSame( '', $this->render_notice() );
	}
	/**
	 * Enabling native backups makes the upgrade guidance obsolete.
	 */
	public function test_enabled_backups_hide_upgrade_notice() {
		update_option( 'mainwp_native_backups_defaulted_off_on_upgrade', 1 );
		update_option( 'mainwp_enableNativeBackups', 1 );

		$this->assertSame( '', $this->render_notice() );
	}
}
