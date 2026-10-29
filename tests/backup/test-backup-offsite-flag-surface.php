<?php
/**
 * Offsite-required flag surface tests.
 *
 * The mainwp_backup_before_upgrade_require_offsite option must carry a
 * default-indicator entry: without one, the "Changed from default" badge
 * renders for every install, including those on the default value.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Settings_Indicator;

/**
 * Class MainWP_Backup_Offsite_Flag_Surface_Test
 */
class MainWP_Backup_Offsite_Flag_Surface_Test extends \WP_UnitTestCase {

	/**
	 * On the default value the badge stays hidden; a changed value shows it.
	 *
	 * @return void
	 */
	public function test_offsite_flag_indicator_tracks_default() {
		$default_state = MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_backup_before_upgrade_require_offsite', 0, false );
		$this->assertStringNotContainsString( 'visible-indicator', $default_state, 'The badge must stay hidden on the default value.' );

		$changed_state = MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_backup_before_upgrade_require_offsite', 1, false );
		$this->assertStringContainsString( 'visible-indicator', $changed_state, 'The badge must show once the value is changed from default.' );
	}

	/**
	 * The registered default matches the option's real production default.
	 *
	 * @return void
	 */
	public function test_offsite_flag_default_is_disabled() {
		$this->assertSame( 0, MainWP_Settings_Indicator::get_defaults_value( 'mainwp_backup_before_upgrade_require_offsite' ) );
	}
	/**
	 * Native backup defaults match their wrappers and hide unchanged badges.
	 *
	 * @return void
	 */
	public function test_native_backup_indicators_match_declared_defaults() {
		$defaults = array(
			'mainwp_on_demand_retention_count'  => 5,
			'mainwp_backup_diagnostics_enabled' => 0,
			'mainwp_backup_encryption_enabled'  => 0,
		);
		foreach ( $defaults as $option => $default ) {
			$this->assertSame( $default, MainWP_Settings_Indicator::get_defaults_value( $option ), $option );
			$this->assertStringNotContainsString( 'visible-indicator', MainWP_Settings_Indicator::render_not_default_indicator( $option, $default, false ), $option );
			$this->assertStringContainsString( 'visible-indicator', MainWP_Settings_Indicator::render_not_default_indicator( $option, $default + 1, false ), $option );
		}
	}
}
