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
use MainWP\Dashboard\MainWP_Install;

/**
 * Class MainWP_Backup_Flag_Migration_Test
 */
class MainWP_Backup_Flag_Migration_Test extends \WP_UnitTestCase {

	/**
	 * Stored mainwp_db_version before the test.
	 *
	 * @var mixed
	 */
	private $saved_db_version;

	/**
	 * Snapshot the db version that install() mutates.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->saved_db_version = get_site_option( 'mainwp_db_version' );
	}

	/**
	 * Remove both flag options and restore the db version.
	 *
	 * @return void
	 */
	public function tear_down() {
		delete_option( 'mainwp_enableNativeBackups' );
		delete_option( 'mainwp_enableLegacyBackupFeature' );
		if ( false === $this->saved_db_version ) {
			delete_site_option( 'mainwp_db_version' );
		} else {
			update_site_option( 'mainwp_db_version', $this->saved_db_version );
		}
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

	/**
	 * The upgrade routine copies the old flag to the new option and
	 * removes the old one.
	 *
	 * @return void
	 */
	public function test_migration_copies_and_deletes_old_option() {
		delete_option( 'mainwp_enableNativeBackups' );
		update_option( 'mainwp_enableLegacyBackupFeature', 1 );
		update_site_option( 'mainwp_db_version', '9.0.2.5' );

		MainWP_Install::instance()->install();

		$this->assertSame( 1, (int) get_option( 'mainwp_enableNativeBackups' ), 'The enabled state must carry over to the new option.' );
		$this->assertFalse( get_option( 'mainwp_enableLegacyBackupFeature' ), 'The old option must be deleted by the migration.' );
	}

	/**
	 * A disabled flag stays disabled through the rename.
	 *
	 * @return void
	 */
	public function test_migration_preserves_disabled_state() {
		delete_option( 'mainwp_enableNativeBackups' );
		update_option( 'mainwp_enableLegacyBackupFeature', 0 );
		update_site_option( 'mainwp_db_version', '9.0.2.5' );

		MainWP_Install::instance()->install();

		$this->assertSame( 0, (int) get_option( 'mainwp_enableNativeBackups' ) );
		$this->assertFalse( MainWP_Backup_Settings::is_native_backups_enabled() );
		$this->assertFalse( get_option( 'mainwp_enableLegacyBackupFeature' ) );
	}

	/**
	 * Re-running the migration never overwrites an already-written new
	 * option, but still clears a lingering old one.
	 *
	 * @return void
	 */
	public function test_migration_does_not_overwrite_existing_new_option() {
		update_option( 'mainwp_enableNativeBackups', 0 );
		update_option( 'mainwp_enableLegacyBackupFeature', 1 );
		update_site_option( 'mainwp_db_version', '9.0.2.5' );

		MainWP_Install::instance()->install();

		$this->assertSame( 0, (int) get_option( 'mainwp_enableNativeBackups' ), 'An existing new option must win over a lingering old one.' );
		$this->assertFalse( get_option( 'mainwp_enableLegacyBackupFeature' ) );
	}

	/**
	 * An existing install that never wrote either flag is grandfathered
	 * in as enabled, matching the old install() behavior.
	 *
	 * @return void
	 */
	public function test_existing_install_without_flags_is_grandfathered_enabled() {
		delete_option( 'mainwp_enableNativeBackups' );
		delete_option( 'mainwp_enableLegacyBackupFeature' );
		update_site_option( 'mainwp_db_version', '9.0.2.5' );

		MainWP_Install::instance()->install();

		$this->assertTrue( MainWP_Backup_Settings::is_native_backups_enabled() );
	}
}
