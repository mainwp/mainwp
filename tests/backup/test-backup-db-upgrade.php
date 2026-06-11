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
	 * Stored mainwp_enableNativeBackups before the test.
	 *
	 * @var mixed
	 */
	private $saved_native_flag;

	/**
	 * Snapshot options that install() mutates.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->saved_db_version  = get_site_option( 'mainwp_db_version' );
		$this->saved_legacy_flag = get_option( 'mainwp_enableLegacyBackupFeature' );
		$this->saved_native_flag = get_option( 'mainwp_enableNativeBackups' );
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

		// A failed table-level test can leave a table missing for later
		// tests; rerun the installer against an older stored version first.
		$snapshot_table   = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$storage_table    = MainWP_DB::instance()->get_table_name( 'wp_backup_storage' );
		$upload_job_table = MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' );
		if ( ! $this->table_exists( $snapshot_table ) || ! $this->table_exists( $storage_table ) || ! $this->table_exists( $upload_job_table ) ) {
			$this->with_real_tables(
				function () {
					update_site_option( 'mainwp_db_version', '9.0.2.7' );
					MainWP_Install::instance()->install();
				}
			);
		}

		$task_table = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		if ( ! $this->column_exists( $task_table, 'destinations' ) ) {
			$wpdb->query( "ALTER TABLE {$task_table} ADD COLUMN destinations text NOT NULL DEFAULT ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.
		}

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_progress' );
		if ( ! $this->column_exists( $table, 'busy_since' ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN busy_since int(11) NOT NULL DEFAULT 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.
		}
		if ( ! $this->column_exists( $table, 'child_backup_task_id' ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN child_backup_task_id varchar(64) NOT NULL DEFAULT ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.
		}
		foreach ( $this->progress_columns() as $column => $definition ) {
			if ( ! $this->column_exists( $table, $column ) ) {
				$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$column} {$definition}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.
			}
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

		if ( false === $this->saved_native_flag ) {
			delete_option( 'mainwp_enableNativeBackups' );
		} else {
			update_option( 'mainwp_enableNativeBackups', $this->saved_native_flag );
		}

		parent::tear_down();
	}

	/**
	 * The granular progress columns and their definitions.
	 *
	 * @return array Column name => SQL definition.
	 */
	private function progress_columns() {
		return array(
			'child_phase'      => "varchar(20) NOT NULL DEFAULT ''",
			'bytes_downloaded' => 'bigint(20) NOT NULL DEFAULT 0',
			'bytes_expected'   => 'bigint(20) NOT NULL DEFAULT 0',
			'current_file'     => "varchar(255) NOT NULL DEFAULT ''",
			'files_processed'  => 'int(11) NOT NULL DEFAULT 0',
			'files_total'      => 'int(11) NOT NULL DEFAULT 0',
		);
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
	 * Whether a table exists.
	 *
	 * @param string $table Table name.
	 *
	 * @return bool
	 */
	private function table_exists( $table ) {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test probe.
		return ! empty( $found );
	}

	/**
	 * Run a callback with the test harness temporary-table query rewrite
	 * suspended. The harness rewrites CREATE TABLE and DROP TABLE to their
	 * TEMPORARY forms, which breaks table-level fixtures: a real table
	 * cannot be dropped and dbDelta would create an invisible temporary
	 * table (SHOW TABLES does not list temporary tables).
	 *
	 * @param callable $callback The work to run against real tables.
	 *
	 * @return void
	 */
	private function with_real_tables( $callback ) {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		try {
			$callback();
		} finally {
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
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

	/**
	 * An install whose stored db version predates the child task id column
	 * must receive it when install() runs - the column links a progress row
	 * to the resumable task on the child site.
	 *
	 * @return void
	 */
	public function test_upgrade_adds_child_backup_task_id_column_to_existing_install() {
		global $wpdb;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_progress' );
		$this->assertTrue( $this->column_exists( $table, 'child_backup_task_id' ), 'Precondition: fresh install has child_backup_task_id.' );

		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN child_backup_task_id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture, identifiers cannot be prepared.
		$this->assertFalse( $this->column_exists( $table, 'child_backup_task_id' ), 'Precondition: column dropped to simulate an older install.' );

		update_site_option( 'mainwp_db_version', '9.0.2.4' );

		MainWP_Install::instance()->install();

		$this->assertTrue(
			$this->column_exists( $table, 'child_backup_task_id' ),
			'Upgrade must add child_backup_task_id to existing installs; the shipped db version needs a bump or dbDelta never runs.'
		);

		// The column stores the child's 64-char task ids intact. A prior
		// run's DDL can implicitly commit this fixture row past the
		// transaction rollback, so clear any leftover first.
		$task_id = str_pad( 'task-', 64, 'x' );
		$wpdb->query( "DELETE FROM {$table} WHERE task_id = 999999 AND wp_id = 999999" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture cleanup, identifiers cannot be prepared.
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- test fixture.
			$table,
			array(
				'task_id'              => 999999,
				'wp_id'                => 999999,
				'child_backup_task_id' => $task_id,
			)
		);
		$stored = $wpdb->get_var( "SELECT child_backup_task_id FROM {$table} WHERE task_id = 999999 AND wp_id = 999999" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test probe, identifiers cannot be prepared.
		$this->assertSame( $task_id, $stored );
	}

	/**
	 * An install whose stored db version predates the granular progress
	 * columns must receive all of them when install() runs.
	 *
	 * @return void
	 */
	public function test_upgrade_adds_granular_progress_columns_to_existing_install() {
		global $wpdb;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_progress' );

		foreach ( array_keys( $this->progress_columns() ) as $column ) {
			$this->assertTrue( $this->column_exists( $table, $column ), "Precondition: fresh install has {$column}." );
			$wpdb->query( "ALTER TABLE {$table} DROP COLUMN {$column}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture, identifiers cannot be prepared.
		}

		update_site_option( 'mainwp_db_version', '9.0.2.6' );

		MainWP_Install::instance()->install();

		foreach ( array_keys( $this->progress_columns() ) as $column ) {
			$this->assertTrue(
				$this->column_exists( $table, $column ),
				"Upgrade must add {$column} to existing installs; the shipped db version needs a bump or dbDelta never runs."
			);
		}
	}

	/**
	 * An install whose stored db version predates the snapshot table must
	 * receive it when install() runs. Snapshot rows are the durable per-run
	 * records that incremental backup chains hang off.
	 *
	 * @return void
	 */
	public function test_upgrade_creates_snapshot_table_on_existing_install() {
		global $wpdb;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$this->assertTrue( $this->table_exists( $table ), 'Precondition: fresh install has the snapshot table.' );

		$this->with_real_tables(
			function () use ( $wpdb, $table ) {
				$wpdb->query( "DROP TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.RestrictedFunctions -- test fixture, identifiers cannot be prepared.
				$this->assertFalse( $this->table_exists( $table ), 'Precondition: table dropped to simulate an older install.' );

				update_site_option( 'mainwp_db_version', '9.0.2.7' );

				MainWP_Install::instance()->install();
			}
		);

		$this->assertTrue(
			$this->table_exists( $table ),
			'Upgrade must create the snapshot table on existing installs; the shipped db version needs a bump or dbDelta never runs.'
		);

		foreach ( array( 'id', 'task_id', 'site_id', 'type', 'parent_id', 'created', 'archives', 'manifest_path', 'status' ) as $column ) {
			$this->assertTrue(
				$this->column_exists( $table, $column ),
				"Snapshot table must have the {$column} column."
			);
		}
	}

	/**
	 * An install whose stored db version predates the storage table must
	 * receive it when install() runs. Storage rows hold the configured
	 * cloud destinations with encrypted settings.
	 *
	 * @return void
	 */
	public function test_upgrade_creates_storage_table_on_existing_install() {
		global $wpdb;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_storage' );
		$this->assertTrue( $this->table_exists( $table ), 'Precondition: fresh install has the storage table.' );

		$this->with_real_tables(
			function () use ( $wpdb, $table ) {
				$wpdb->query( "DROP TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.RestrictedFunctions -- test fixture, identifiers cannot be prepared.
				$this->assertFalse( $this->table_exists( $table ), 'Precondition: table dropped to simulate an older install.' );

				update_site_option( 'mainwp_db_version', '9.0.2.8' );

				MainWP_Install::instance()->install();
			}
		);

		$this->assertTrue(
			$this->table_exists( $table ),
			'Upgrade must create the storage table on existing installs; the shipped db version needs a bump or dbDelta never runs.'
		);

		foreach ( array( 'id', 'provider', 'name', 'settings', 'is_default', 'created', 'updated' ) as $column ) {
			$this->assertTrue(
				$this->column_exists( $table, $column ),
				"Storage table must have the {$column} column."
			);
		}
	}

	/**
	 * An install whose stored db version predates the upload job table must
	 * receive it when install() runs. Upload job rows are the resumable
	 * per-snapshot-per-destination upload state.
	 *
	 * @return void
	 */
	public function test_upgrade_creates_upload_job_table_on_existing_install() {
		global $wpdb;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' );
		$this->assertTrue( $this->table_exists( $table ), 'Precondition: fresh install has the upload job table.' );

		$this->with_real_tables(
			function () use ( $wpdb, $table ) {
				$wpdb->query( "DROP TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.RestrictedFunctions -- test fixture, identifiers cannot be prepared.
				$this->assertFalse( $this->table_exists( $table ), 'Precondition: table dropped to simulate an older install.' );

				update_site_option( 'mainwp_db_version', '9.0.2.8' );

				MainWP_Install::instance()->install();
			}
		);

		$this->assertTrue(
			$this->table_exists( $table ),
			'Upgrade must create the upload job table on existing installs; the shipped db version needs a bump or dbDelta never runs.'
		);

		foreach ( array( 'id', 'snapshot_id', 'destination_id', 'status', 'attempt_count', 'next_retry_at', 'locked_until', 'locked_by', 'checkpoint', 'last_error', 'created', 'updated' ) as $column ) {
			$this->assertTrue(
				$this->column_exists( $table, $column ),
				"Upload job table must have the {$column} column."
			);
		}
	}

	/**
	 * An install whose stored db version predates the destinations column
	 * must receive it when install() runs. The column holds the storage
	 * destination ids a task uploads to.
	 *
	 * @return void
	 */
	public function test_upgrade_adds_destinations_column_to_backup_task_table() {
		global $wpdb;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$this->assertTrue( $this->column_exists( $table, 'destinations' ), 'Precondition: fresh install has destinations.' );

		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN destinations" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture, identifiers cannot be prepared.
		$this->assertFalse( $this->column_exists( $table, 'destinations' ), 'Precondition: column dropped to simulate an older install.' );

		update_site_option( 'mainwp_db_version', '9.0.2.8' );

		MainWP_Install::instance()->install();

		$this->assertTrue(
			$this->column_exists( $table, 'destinations' ),
			'Upgrade must add destinations to existing installs; the shipped db version needs a bump or dbDelta never runs.'
		);
	}
}
