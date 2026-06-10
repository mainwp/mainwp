<?php
/**
 * MainWP Backup DB Upgrade Tests
 *
 * Verifies that schema changes to the wp_backup_progress table reach
 * existing installs through the dbDelta upgrade path. The upgrade only
 * runs when the stored mainwp_db_version differs from the shipped
 * version, so any new column must be accompanied by a version bump.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Install;

/**
 * Class MainWP_Backup_DB_Upgrade_Test
 */
class MainWP_Backup_DB_Upgrade_Test extends \WP_UnitTestCase {

	/**
	 * Stored mainwp_db_version before the test.
	 *
	 * @var mixed
	 */
	private $saved_db_version;

	/**
	 * Stored mainwp_enableLegacyBackupFeature before the test.
	 *
	 * @var mixed
	 */
	private $saved_legacy_flag;

	/**
	 * Snapshot options that install() mutates.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->saved_db_version  = get_site_option( 'mainwp_db_version' );
		$this->saved_legacy_flag = get_option( 'mainwp_enableLegacyBackupFeature' );
	}

	/**
	 * Restore options and make sure the column exists again, even when the
	 * test failed. ALTER TABLE is DDL, so the test-case transaction rollback
	 * cannot undo the DROP COLUMN.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wpdb;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_progress' );
		if ( ! $this->column_exists( $table, 'busy_since' ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN busy_since int(11) NOT NULL DEFAULT 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.
		}

		if ( false === $this->saved_db_version ) {
			delete_site_option( 'mainwp_db_version' );
		} else {
			update_site_option( 'mainwp_db_version', $this->saved_db_version );
		}

		if ( false === $this->saved_legacy_flag ) {
			delete_option( 'mainwp_enableLegacyBackupFeature' );
		} else {
			update_option( 'mainwp_enableLegacyBackupFeature', $this->saved_legacy_flag );
		}

		parent::tear_down();
	}

	/**
	 * Whether a column exists on a table.
	 *
	 * @param string $table  Table name.
	 * @param string $column Column name.
	 *
	 * @return bool
	 */
	private function column_exists( $table, $column ) {
		global $wpdb;
		$columns = $wpdb->get_col( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- identifiers cannot be prepared.
		return ! empty( $columns );
	}

	/**
	 * An install whose stored db version predates the busy_since column must
	 * receive the column when install() runs. This simulates an upgrade from
	 * a release shipped before the column existed: the column is dropped and
	 * the stored version is set to the last value shipped without it.
	 *
	 * @return void
	 */
	public function test_upgrade_adds_busy_since_column_to_existing_install() {
		global $wpdb;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_progress' );
		$this->assertTrue( $this->column_exists( $table, 'busy_since' ), 'Precondition: fresh install has busy_since.' );

		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN busy_since" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture, identifiers cannot be prepared.
		$this->assertFalse( $this->column_exists( $table, 'busy_since' ), 'Precondition: column dropped to simulate an older install.' );

		update_site_option( 'mainwp_db_version', '9.0.2.3' );

		MainWP_Install::instance()->install();

		$this->assertTrue(
			$this->column_exists( $table, 'busy_since' ),
			'Upgrade must add busy_since to existing installs; the shipped db version needs a bump or dbDelta never runs.'
		);
	}
}
