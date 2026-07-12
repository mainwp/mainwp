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
		delete_option( 'mainwp_native_backups_defaulted_off_on_upgrade' );
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
	 * The upgrade routine copies the old flag to the new option and keeps
	 * the old name present with the same effective value: a rollback to a
	 * pre-rename dashboard recreates a MISSING flag as enabled on
	 * install(), so deleting it would silently re-enable legacy backups.
	 *
	 * @return void
	 */
	public function test_migration_copies_and_mirrors_old_option() {
		delete_option( 'mainwp_enableNativeBackups' );
		update_option( 'mainwp_enableLegacyBackupFeature', 1 );
		update_site_option( 'mainwp_db_version', '9.0.2.5' );

		MainWP_Install::instance()->install();

		$this->assertSame( 1, (int) get_option( 'mainwp_enableNativeBackups' ), 'The enabled state must carry over to the new option.' );
		$this->assertSame( 1, (int) get_option( 'mainwp_enableLegacyBackupFeature' ), 'The old option must stay present, mirroring the effective value, for the rollback window.' );
	}

	/**
	 * A disabled flag stays disabled through the rename - including on the
	 * mirrored old option, so a rollback stays disabled too.
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
		$this->assertNotFalse( get_option( 'mainwp_enableLegacyBackupFeature' ), 'The old option must stay present for the rollback window.' );
		$this->assertSame( 0, (int) get_option( 'mainwp_enableLegacyBackupFeature' ) );
	}

	/**
	 * Re-running the migration never overwrites an already-written new
	 * option; a lingering old one is re-mirrored to the effective value.
	 *
	 * @return void
	 */
	public function test_migration_does_not_overwrite_existing_new_option() {
		update_option( 'mainwp_enableNativeBackups', 0 );
		update_option( 'mainwp_enableLegacyBackupFeature', 1 );
		update_site_option( 'mainwp_db_version', '9.0.2.5' );

		MainWP_Install::instance()->install();

		$this->assertSame( 0, (int) get_option( 'mainwp_enableNativeBackups' ), 'An existing new option must win over a lingering old one.' );
		$this->assertSame( 0, (int) get_option( 'mainwp_enableLegacyBackupFeature' ), 'The lingering old option must be re-mirrored to the effective value.' );
	}

	/**
	 * Every toggle write mirrors into the old option name, so the state a
	 * rollback would resurrect always matches the user's last choice.
	 *
	 * @return void
	 */
	public function test_toggle_write_mirrors_old_option() {
		update_option( 'mainwp_enableNativeBackups', 0 );

		MainWP_Backup_Settings::update_native_backups_option( 1 );
		$this->assertSame( 1, (int) get_option( 'mainwp_enableNativeBackups' ) );
		$this->assertSame( 1, (int) get_option( 'mainwp_enableLegacyBackupFeature' ) );

		MainWP_Backup_Settings::update_native_backups_option( 0 );
		$this->assertSame( 0, (int) get_option( 'mainwp_enableNativeBackups' ) );
		$this->assertSame( 0, (int) get_option( 'mainwp_enableLegacyBackupFeature' ) );
	}

	/**
	 * Before the rename migration has run the mirror is a no-op: it must
	 * never clobber a still-authoritative legacy flag with a default.
	 *
	 * @return void
	 */
	public function test_mirror_is_noop_before_migration() {
		delete_option( 'mainwp_enableNativeBackups' );
		update_option( 'mainwp_enableLegacyBackupFeature', 1 );

		MainWP_Backup_Settings::mirror_native_flag_for_rollback();

		$this->assertSame( 1, (int) get_option( 'mainwp_enableLegacyBackupFeature' ) );
	}

	/**
	 * An existing install that never wrote either flag defaults to disabled,
	 * matching a fresh install. Only a lingering legacy flag (handled by the
	 * <9.0.2.6 migration above) carries a prior enabled state forward.
	 *
	 * @return void
	 */
	public function test_existing_install_without_flags_defaults_to_disabled() {
		delete_option( 'mainwp_enableNativeBackups' );
		delete_option( 'mainwp_enableLegacyBackupFeature' );
		update_site_option( 'mainwp_db_version', '9.0.2.5' );

		MainWP_Install::instance()->install();

		$this->assertFalse( MainWP_Backup_Settings::is_native_backups_enabled() );
	}

	/**
	 * The never-wrote-a-flag upgrade cohort gets a one-time notice marker
	 * so the dashboard can tell them the feature was defaulted off.
	 *
	 * @return void
	 */
	public function test_existing_install_without_flags_sets_upgrade_notice_marker() {
		delete_option( 'mainwp_enableNativeBackups' );
		delete_option( 'mainwp_enableLegacyBackupFeature' );
		update_site_option( 'mainwp_db_version', '9.0.2.5' );

		MainWP_Install::instance()->install();

		$this->assertSame( 1, (int) get_option( 'mainwp_native_backups_defaulted_off_on_upgrade' ) );
	}

	/**
	 * An install that carried a real legacy flag through the rename is not
	 * part of the defaulted-off cohort and gets no notice marker.
	 *
	 * @return void
	 */
	public function test_migration_with_legacy_flag_sets_no_upgrade_notice_marker() {
		// A prior test's install() can commit mid-test via DDL, leaking the
		// marker past the rollback; reset it here like the flags above.
		delete_option( 'mainwp_native_backups_defaulted_off_on_upgrade' );
		delete_option( 'mainwp_enableNativeBackups' );
		update_option( 'mainwp_enableLegacyBackupFeature', 1 );
		update_site_option( 'mainwp_db_version', '9.0.2.5' );

		MainWP_Install::instance()->install();

		$this->assertFalse( get_option( 'mainwp_native_backups_defaulted_off_on_upgrade' ) );
	}

	/**
	 * An install with an explicit new-option value already made its choice
	 * and gets no notice marker.
	 *
	 * @return void
	 */
	public function test_explicit_flag_sets_no_upgrade_notice_marker() {
		delete_option( 'mainwp_native_backups_defaulted_off_on_upgrade' );
		update_option( 'mainwp_enableNativeBackups', 1 );
		delete_option( 'mainwp_enableLegacyBackupFeature' );
		update_site_option( 'mainwp_db_version', '9.0.2.5' );

		MainWP_Install::instance()->install();

		$this->assertFalse( get_option( 'mainwp_native_backups_defaulted_off_on_upgrade' ) );
	}

	/**
	 * A fresh install (no mainwp_db_version option at all) defaults to
	 * disabled, the same as an existing install with neither flag written,
	 * and is not part of the defaulted-off-on-upgrade notice cohort.
	 *
	 * Runs install() end to end with an empty $currentVersion: every
	 * version-gated index clause fires here against already-existing
	 * tables, so this test also guards dbDelta's index parsing on that
	 * path. The crash that once forced a skip ("undefined array key
	 * index_type" on PHP 8.4) came from the legacy unnamed UNIQUE
	 * (task_id) clause on the progress table, removed at db 9.0.2.20 -
	 * dbDelta trims field lines, so the PRIMARY KEY fragments the old
	 * skip note blamed were never the offender.
	 *
	 * @return void
	 */
	public function test_fresh_install_defaults_to_disabled() {
		delete_option( 'mainwp_native_backups_defaulted_off_on_upgrade' );
		delete_option( 'mainwp_enableNativeBackups' );
		delete_option( 'mainwp_enableLegacyBackupFeature' );
		delete_site_option( 'mainwp_db_version' );

		MainWP_Install::instance()->install();

		$this->assertNotFalse( get_option( 'mainwp_enableNativeBackups' ), 'Fresh install must write the flag explicitly, not leave it unset.' );
		$this->assertSame( 0, (int) get_option( 'mainwp_enableNativeBackups' ) );
		$this->assertFalse( get_option( 'mainwp_native_backups_defaulted_off_on_upgrade' ), 'Fresh installs are not the upgraded cohort and get no notice marker.' );
	}
}
