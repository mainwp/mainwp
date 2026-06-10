<?php
/**
 * MainWP Backup Flag Migration Tests
 *
 * Tests for the mainwp_enableNativeBackups read helper and the option
 * rename migration from mainwp_enableLegacyBackupFeature.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Settings;

/**
 * Class MainWP_Backup_Flag_Migration_Test
 */
class MainWP_Backup_Flag_Migration_Test extends \WP_UnitTestCase {

	/**
	 * Remove both flag options.
	 *
	 * @return void
	 */
	public function tear_down() {
		delete_option( 'mainwp_enableNativeBackups' );
		delete_option( 'mainwp_enableLegacyBackupFeature' );
		parent::tear_down();
	}

	/**
	 * The helper reads the new option when present.
	 *
	 * @return void
	 */
	public function test_helper_reads_new_option() {
		update_option( 'mainwp_enableNativeBackups', 1 );

		$this->assertTrue( MainWP_Backup_Settings::is_native_backups_enabled() );
	}

	/**
	 * An explicit zero on the new option wins over the old option.
	 *
	 * @return void
	 */
	public function test_helper_prefers_new_option_over_old() {
		update_option( 'mainwp_enableNativeBackups', 0 );
		update_option( 'mainwp_enableLegacyBackupFeature', 1 );

		$this->assertFalse( MainWP_Backup_Settings::is_native_backups_enabled() );
	}

	/**
	 * Before the migration runs, the helper falls back to the old option
	 * so cron entry points keep the correct state.
	 *
	 * @return void
	 */
	public function test_helper_falls_back_to_old_option() {
		delete_option( 'mainwp_enableNativeBackups' );
		update_option( 'mainwp_enableLegacyBackupFeature', 1 );

		$this->assertTrue( MainWP_Backup_Settings::is_native_backups_enabled() );
	}

	/**
	 * With neither option present, the feature reads as disabled.
	 *
	 * @return void
	 */
	public function test_helper_defaults_to_disabled() {
		delete_option( 'mainwp_enableNativeBackups' );
		delete_option( 'mainwp_enableLegacyBackupFeature' );

		$this->assertFalse( MainWP_Backup_Settings::is_native_backups_enabled() );
	}
}
