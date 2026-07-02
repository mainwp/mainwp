<?php
/**
 * MainWP Backup Settings View Tests
 *
 * Tests for the global and per-site backup settings sections.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Diagnostics;
use MainWP\Dashboard\MainWP_Backup_Settings_View;
use MainWP\Dashboard\MainWP_Backup_Settings;
use MainWP\Dashboard\MainWP_Backup_Encryption;
use MainWP\Dashboard\MainWP_Keys_Manager;

/**
 * Class MainWP_Backup_Settings_View_Test
 */
class MainWP_Backup_Settings_View_Test extends \WP_UnitTestCase {

	/**
	 * Reset the on-demand retention option between tests.
	 *
	 * @return void
	 */
	public function tear_down() {
		delete_option( 'mainwp_on_demand_retention_count' );
		delete_option( 'mainwp_enableNativeBackups' );
		delete_option( MainWP_Backup_Diagnostics::OPTION_ENABLED );

		$enc = MainWP_Backup_Encryption::instance();
		MainWP_Keys_Manager::instance()->delete_key_file( $enc->kek_filename() );
		delete_option( MainWP_Backup_Encryption::ENABLED_OPTION );
		delete_option( MainWP_Backup_Encryption::RECOVERY_OPTION );

		parent::tear_down();
	}

	/**
	 * The global settings section renders the encryption toggle and the
	 * recovery passphrase field.
	 *
	 * @return void
	 */
	public function test_render_settings_outputs_encryption_fields() {
		update_option( 'mainwp_enableNativeBackups', 1 );

		ob_start();
		MainWP_Backup_Settings_View::render_settings();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'mainwp_options_enableBackupEncryption', $output );
		$this->assertStringContainsString( 'mainwp_options_backupEncryptionPassphrase', $output );
	}

	/**
	 * Saving with the encryption toggle on and a recovery passphrase arms
	 * encryption: the toggle, the key, and the recovery blob all land.
	 *
	 * @return void
	 */
	public function test_handle_settings_post_arms_encryption_with_passphrase() {
		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$_POST['submit']                                    = '1';
		$_POST['wp_nonce']                                  = wp_create_nonce( 'Settings' );
		$_POST['mainwp_options_enableBackupEncryption']     = '1';
		$_POST['mainwp_options_backupEncryptionPassphrase'] = 'a strong settings passphrase';

		$this->assertTrue( MainWP_Backup_Settings::handle_settings_post() );

		$enc = MainWP_Backup_Encryption::instance();
		$this->assertTrue( $enc->is_enabled_setting() );
		$this->assertNotEmpty( $enc->get_recovery_blob() );
		$this->assertNotNull( $enc->load_kek() );
		$this->assertTrue( $enc->is_armed() );

		unset( $_POST['submit'], $_POST['wp_nonce'], $_POST['mainwp_options_enableBackupEncryption'], $_POST['mainwp_options_backupEncryptionPassphrase'] );
	}

	/**
	 * The toggle cannot arm encryption without a recovery passphrase: a
	 * backup must never be sealed without a way to recover it.
	 *
	 * @return void
	 */
	public function test_handle_settings_post_refuses_to_arm_without_passphrase() {
		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$_POST['submit']                                = '1';
		$_POST['wp_nonce']                              = wp_create_nonce( 'Settings' );
		$_POST['mainwp_options_enableBackupEncryption'] = '1';

		MainWP_Backup_Settings::handle_settings_post();

		$this->assertFalse( MainWP_Backup_Encryption::instance()->is_enabled_setting(), 'Encryption must not arm without a recovery passphrase.' );

		unset( $_POST['submit'], $_POST['wp_nonce'], $_POST['mainwp_options_enableBackupEncryption'] );
	}

	/**
	 * Turning encryption off leaves the key material in place so existing
	 * encrypted snapshots can still be restored.
	 *
	 * @return void
	 */
	public function test_handle_settings_post_disable_keeps_key_material() {
		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$enc = MainWP_Backup_Encryption::instance();
		update_option( MainWP_Backup_Encryption::ENABLED_OPTION, '1' );
		$enc->set_recovery_passphrase( 'keep this passphrase set please' );
		$kek = $enc->load_kek();

		$_POST['submit']   = '1';
		$_POST['wp_nonce'] = wp_create_nonce( 'Settings' );
		// The encryption toggle is absent, which means off.

		MainWP_Backup_Settings::handle_settings_post();

		$this->assertFalse( $enc->is_enabled_setting(), 'The toggle is off.' );
		$this->assertSame( $kek, $enc->load_kek(), 'Disabling must not delete the KEK.' );
		$this->assertNotEmpty( $enc->get_recovery_blob(), 'Disabling must not delete the recovery blob.' );

		unset( $_POST['submit'], $_POST['wp_nonce'] );
	}

	/**
	 * The global settings section renders the header and the legacy
	 * feature toggle.
	 *
	 * @return void
	 */
	public function test_render_settings_outputs_header_and_toggle() {
		ob_start();
		MainWP_Backup_Settings_View::render_settings();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Backup Settings', $output );
		$this->assertStringContainsString( 'mainwp_options_enableNativeBackups', $output );
	}

	/**
	 * The global settings section renders the archive format selector.
	 *
	 * @return void
	 */
	public function test_render_settings_outputs_archive_format_select() {
		ob_start();
		MainWP_Backup_Settings_View::render_settings();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'mainwp_archiveFormat', $output );
	}

	/**
	 * The global settings section renders the on-demand retention field.
	 *
	 * @return void
	 */
	public function test_render_settings_outputs_on_demand_retention_field() {
		update_option( 'mainwp_enableNativeBackups', 1 );

		ob_start();
		MainWP_Backup_Settings_View::render_settings();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'mainwp_options_onDemandRetentionCount', $output );
	}

	/**
	 * Saving the settings form persists the on-demand retention count.
	 *
	 * @return void
	 */
	public function test_handle_settings_post_saves_on_demand_retention() {
		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$_POST['submit']                              = '1';
		$_POST['wp_nonce']                            = wp_create_nonce( 'Settings' );
		$_POST['mainwp_options_onDemandRetentionCount'] = '3';

		$saved = MainWP_Backup_Settings::handle_settings_post();

		$this->assertTrue( $saved );
		$this->assertSame( 3, MainWP_Backup_Settings::get_on_demand_retention_count() );

		unset( $_POST['submit'], $_POST['wp_nonce'], $_POST['mainwp_options_onDemandRetentionCount'] );
	}

	/**
	 * The global settings section renders the diagnostics toggle.
	 *
	 * @return void
	 */
	public function test_render_settings_outputs_diagnostics_toggle() {
		update_option( 'mainwp_enableNativeBackups', 1 );

		ob_start();
		MainWP_Backup_Settings_View::render_settings();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'mainwp_options_backupDiagnosticsEnabled', $output );
	}

	/**
	 * The export button appears only while diagnostics is enabled: with
	 * nothing being recorded there is nothing to export.
	 *
	 * @return void
	 */
	public function test_render_settings_outputs_export_button_only_when_enabled() {
		update_option( 'mainwp_enableNativeBackups', 1 );

		ob_start();
		MainWP_Backup_Settings_View::render_settings();
		$without = ob_get_clean();
		$this->assertStringNotContainsString( 'mainwp-backup-diagnostics-export', $without );

		update_option( MainWP_Backup_Diagnostics::OPTION_ENABLED, '1' );

		ob_start();
		MainWP_Backup_Settings_View::render_settings();
		$with = ob_get_clean();
		$this->assertStringContainsString( 'mainwp-backup-diagnostics-export', $with );
		$this->assertStringContainsString( 'mainwp_backup_diagnostics_export', $with, 'The button must post the export AJAX action.' );
	}

	/**
	 * Saving the settings form persists the diagnostics toggle both ways.
	 *
	 * @return void
	 */
	public function test_handle_settings_post_saves_diagnostics_toggle() {
		$admin = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$_POST['submit']   = '1';
		$_POST['wp_nonce'] = wp_create_nonce( 'Settings' );
		$_POST['mainwp_options_backupDiagnosticsEnabled'] = '1';

		$this->assertTrue( MainWP_Backup_Settings::handle_settings_post() );
		$this->assertTrue( MainWP_Backup_Diagnostics::is_enabled() );

		unset( $_POST['mainwp_options_backupDiagnosticsEnabled'] );
		$_POST['wp_nonce'] = wp_create_nonce( 'Settings' );

		$this->assertTrue( MainWP_Backup_Settings::handle_settings_post() );
		$this->assertFalse( MainWP_Backup_Diagnostics::is_enabled(), 'An absent checkbox must turn the recorder off.' );

		unset( $_POST['submit'], $_POST['wp_nonce'] );
	}

	/**
	 * The per-site section renders the accordion and the primary backup
	 * selector.
	 *
	 * @return void
	 */
	public function test_render_individual_settings_outputs_accordion() {
		$website = (object) array(
			'id'                    => 1,
			'primary_backup_method' => '',
		);

		ob_start();
		MainWP_Backup_Settings_View::render_individual_settings( $website );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'mainwp-edit-site-backup-settings-accordion', $output );
		$this->assertStringContainsString( 'mainwp_primaryBackup', $output );
	}
}
