<?php
/**
 * MainWP Backup Settings View Tests
 *
 * Tests for the global and per-site backup settings sections.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Settings_View;
use MainWP\Dashboard\MainWP_Backup_Settings;

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
		parent::tear_down();
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
