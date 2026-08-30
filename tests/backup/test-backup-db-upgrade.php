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
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Install;

/**
 * Class MainWP_Backup_DB_Upgrade_Test
 */
class MainWP_Backup_DB_Upgrade_Test extends \WP_UnitTestCase {

	/**
	 * Task id the progress-dedupe fixture rows hang off.
	 */
	const DEDUPE_TASK_ID = 888899;

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
		foreach ( $this->schedule_retention_columns() as $column => $definition ) {
			if ( ! $this->column_exists( $task_table, $column ) ) {
				$wpdb->query( "ALTER TABLE {$task_table} ADD COLUMN {$column} {$definition}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.
			}
		}
		if ( ! $this->column_exists( $task_table, 'zip_force_override' ) ) {
			$wpdb->query( "ALTER TABLE {$task_table} ADD COLUMN zip_force_override tinyint(1) NOT NULL DEFAULT 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.
		}
		if ( ! $this->column_exists( $task_table, 'backup_notices' ) ) {
			$wpdb->query( "ALTER TABLE {$task_table} ADD COLUMN backup_notices text NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.
		}
		if ( ! $this->index_exists( $task_table, 'idx_next_run' ) ) {
			$wpdb->query( "ALTER TABLE {$task_table} ADD INDEX idx_next_run (next_run)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.
		}

		$upload_job_table = MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' );
		if ( $this->table_exists( $upload_job_table ) ) {
			if ( ! $this->column_exists( $upload_job_table, 'kind' ) ) {
				$wpdb->query( "ALTER TABLE {$upload_job_table} ADD COLUMN kind varchar(10) NOT NULL DEFAULT 'upload'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.
			}
			if ( ! $this->column_exists( $upload_job_table, 'provider' ) ) {
				$wpdb->query( "ALTER TABLE {$upload_job_table} ADD COLUMN provider varchar(20) NOT NULL DEFAULT ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.
			}
			if ( ! $this->column_exists( $upload_job_table, 'reclaim_count' ) ) {
				$wpdb->query( "ALTER TABLE {$upload_job_table} ADD COLUMN reclaim_count int(11) NOT NULL DEFAULT 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.
			}
			if ( ! $this->index_exists( $upload_job_table, 'uniq_snap_dest_kind' ) ) {
				$wpdb->query( "ALTER TABLE {$upload_job_table} ADD UNIQUE KEY uniq_snap_dest_kind (snapshot_id, destination_id, kind)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.
			}
			if ( $this->index_exists( $upload_job_table, 'uniq_snap_dest' ) ) {
				$wpdb->query( "ALTER TABLE {$upload_job_table} DROP INDEX uniq_snap_dest" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.
			}
		}

		$restore_job_table = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		if ( $this->table_exists( $restore_job_table ) && ! $this->column_exists( $restore_job_table, 'reclaim_count' ) ) {
			$wpdb->query( "ALTER TABLE {$restore_job_table} ADD COLUMN reclaim_count int(11) NOT NULL DEFAULT 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.
		}

		$snapshot_table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		if ( $this->table_exists( $snapshot_table ) ) {
			if ( ! $this->column_exists( $snapshot_table, 'offsite_state' ) ) {
				$wpdb->query( "ALTER TABLE {$snapshot_table} ADD COLUMN offsite_state varchar(20) NOT NULL DEFAULT ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.
			}
			if ( ! $this->column_exists( $snapshot_table, 'verified_at' ) ) {
				$wpdb->query( "ALTER TABLE {$snapshot_table} ADD COLUMN verified_at int(11) NOT NULL DEFAULT 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.
			}
			if ( ! $this->column_exists( $snapshot_table, 'encryption' ) ) {
				$wpdb->query( "ALTER TABLE {$snapshot_table} ADD COLUMN encryption text NOT NULL DEFAULT ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.
			}
			if ( ! $this->column_exists( $snapshot_table, 'test_restore_state' ) ) {
				$wpdb->query( "ALTER TABLE {$snapshot_table} ADD COLUMN test_restore_state varchar(20) NOT NULL DEFAULT ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.
			}
			if ( ! $this->column_exists( $snapshot_table, 'test_restore_at' ) ) {
				$wpdb->query( "ALTER TABLE {$snapshot_table} ADD COLUMN test_restore_at int(11) NOT NULL DEFAULT 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.
			}
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
		if ( $this->index_exists( $table, 'task_id' ) ) {
			$wpdb->query( "ALTER TABLE {$table} DROP INDEX task_id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.
		}
		if ( ! $this->index_exists( $table, 'idx_task_id' ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD INDEX idx_task_id (task_id)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.
		}
		// The dedupe fixture's rows outlive a failed test - the install()
		// under test implicitly commits them - and leftover duplicates make
		// the unique key uninsertable, so they go before it is restored.
		$wpdb->query( 'DELETE FROM ' . $table . ' WHERE task_id = ' . self::DEDUPE_TASK_ID ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.
		if ( ! $this->index_exists( $table, 'uniq_task_wp' ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY uniq_task_wp (task_id, wp_id)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.
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
	 * The schedule and retention columns on the task table and their definitions.
	 *
	 * @return array Column name => SQL definition.
	 */
	private function schedule_retention_columns() {
		return array(
			'interval_hours'       => 'smallint(5) unsigned NOT NULL DEFAULT 0',
			'time_of_day'          => "varchar(5) NOT NULL DEFAULT ''",
			'day_of_week'          => 'tinyint(1) unsigned NOT NULL DEFAULT 0',
			'day_of_month'         => 'tinyint(2) unsigned NOT NULL DEFAULT 1',
			'next_run'             => 'int(11) NOT NULL DEFAULT 0',
			'retention_count'      => 'int(11) NOT NULL DEFAULT 0',
			'retention_days'       => 'int(11) NOT NULL DEFAULT 0',
			'retention_storage_mb' => 'int(11) NOT NULL DEFAULT 0',
			'delete_cloud_copies'  => 'tinyint(1) NOT NULL DEFAULT 1',
			'max_chain_length'     => 'int(11) NOT NULL DEFAULT 0',
		);
	}

	/**
	 * Whether an index exists on a table.
	 *
	 * @param string $table Table name.
	 * @param string $key   Index name.
	 *
	 * @return bool
	 */
	private function index_exists( $table, $key ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- identifiers cannot be prepared.
		return ! empty( $rows );
	}

	/**
	 * The column names an index covers, in sequence order.
	 *
	 * @param string $table Table name.
	 * @param string $key   Index name.
	 *
	 * @return array
	 */
	private function index_columns( $table, $key ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- identifiers cannot be prepared.
		usort( $rows, fn( $a, $b ) => (int) $a->Seq_in_index <=> (int) $b->Seq_in_index );
		return array_values( wp_list_pluck( $rows, 'Column_name' ) );
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
	 * An install whose stored db version predates the offsite-verified
	 * state machine must receive its columns when install() runs:
	 * offsite_state / verified_at on the snapshot table, the denormalized
	 * provider on the upload-job table, and the idx_offsite_state index.
	 * Re-running install() stays idempotent.
	 *
	 * @return void
	 */
	public function test_upgrade_adds_offsite_state_machine_columns() {
		global $wpdb;

		$snapshot_table   = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$upload_job_table = MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' );

		$this->assertTrue( $this->column_exists( $snapshot_table, 'offsite_state' ), 'Precondition: fresh install has offsite_state.' );
		$this->assertTrue( $this->column_exists( $snapshot_table, 'verified_at' ), 'Precondition: fresh install has verified_at.' );
		$this->assertTrue( $this->column_exists( $upload_job_table, 'provider' ), 'Precondition: fresh install has provider.' );

		$wpdb->query( "ALTER TABLE {$snapshot_table} DROP COLUMN offsite_state" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture, identifiers cannot be prepared.
		$wpdb->query( "ALTER TABLE {$snapshot_table} DROP COLUMN verified_at" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture, identifiers cannot be prepared.
		$wpdb->query( "ALTER TABLE {$upload_job_table} DROP COLUMN provider" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture, identifiers cannot be prepared.
		$this->assertFalse( $this->column_exists( $snapshot_table, 'offsite_state' ), 'Precondition: offsite_state dropped to simulate an older install.' );
		$this->assertFalse( $this->column_exists( $upload_job_table, 'provider' ), 'Precondition: provider dropped to simulate an older install.' );

		update_site_option( 'mainwp_db_version', '9.0.2.12' );
		MainWP_Install::instance()->install();

		$this->assertTrue( $this->column_exists( $snapshot_table, 'offsite_state' ), 'Upgrade must add offsite_state.' );
		$this->assertTrue( $this->column_exists( $snapshot_table, 'verified_at' ), 'Upgrade must add verified_at.' );
		$this->assertTrue( $this->column_exists( $upload_job_table, 'provider' ), 'Upgrade must add provider.' );
		$this->assertTrue( $this->index_exists( $snapshot_table, 'idx_offsite_state' ), 'Upgrade must add the idx_offsite_state index.' );

		update_site_option( 'mainwp_db_version', '9.0.2.12' );
		MainWP_Install::instance()->install();
		$this->assertTrue( $this->column_exists( $snapshot_table, 'offsite_state' ), 'Re-running install must remain idempotent.' );
	}

	/**
	 * An install whose stored db version predates the archive-encryption
	 * column must receive it when install() runs: encryption holds the
	 * per-snapshot wrapped data key and cipher markers. Re-running install()
	 * stays idempotent.
	 *
	 * @return void
	 */
	public function test_upgrade_adds_encryption_column_to_snapshot_table() {
		global $wpdb;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$this->assertTrue( $this->column_exists( $table, 'encryption' ), 'Precondition: fresh install has encryption.' );

		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN encryption" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture, identifiers cannot be prepared.
		$this->assertFalse( $this->column_exists( $table, 'encryption' ), 'Precondition: column dropped to simulate an older install.' );

		update_site_option( 'mainwp_db_version', '9.0.2.13' );
		MainWP_Install::instance()->install();

		$this->assertTrue(
			$this->column_exists( $table, 'encryption' ),
			'Upgrade must add encryption to existing installs; the shipped db version needs a bump or dbDelta never runs.'
		);

		update_site_option( 'mainwp_db_version', '9.0.2.13' );
		MainWP_Install::instance()->install();
		$this->assertTrue( $this->column_exists( $table, 'encryption' ), 'Re-running install must remain idempotent.' );
	}

	/**
	 * An install whose stored db version predates the woo_meta column must
	 * receive it when install() runs - it carries the Woo order-storage
	 * facts a database-bearing backup captured at dump time, which the
	 * restore initiate handler reads for the preserve-orders gate.
	 * Re-running install() stays idempotent.
	 *
	 * @return void
	 */
	public function test_upgrade_adds_woo_meta_column_to_snapshot_table() {
		global $wpdb;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$this->assertTrue( $this->column_exists( $table, 'woo_meta' ), 'Precondition: fresh install has woo_meta.' );

		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN woo_meta" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture, identifiers cannot be prepared.
		$this->assertFalse( $this->column_exists( $table, 'woo_meta' ), 'Precondition: column dropped to simulate an older install.' );

		update_site_option( 'mainwp_db_version', '9.0.2.17' );
		MainWP_Install::instance()->install();

		$this->assertTrue(
			$this->column_exists( $table, 'woo_meta' ),
			'Upgrade must add woo_meta to existing installs; the shipped db version needs a bump or dbDelta never runs.'
		);

		update_site_option( 'mainwp_db_version', '9.0.2.17' );
		MainWP_Install::instance()->install();
		$this->assertTrue( $this->column_exists( $table, 'woo_meta' ), 'Re-running install must remain idempotent.' );
	}

	/**
	 * An install whose stored db version predates the verified-restore columns
	 * must receive them when install() runs: test_restore_state and
	 * test_restore_at hold the sandbox test-restore outcome and the time it ran.
	 * Re-running install() stays idempotent.
	 *
	 * @return void
	 */
	public function test_upgrade_adds_test_restore_columns_to_snapshot_table() {
		global $wpdb;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$this->assertTrue( $this->column_exists( $table, 'test_restore_state' ), 'Precondition: fresh install has test_restore_state.' );
		$this->assertTrue( $this->column_exists( $table, 'test_restore_at' ), 'Precondition: fresh install has test_restore_at.' );

		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN test_restore_state" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture, identifiers cannot be prepared.
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN test_restore_at" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture, identifiers cannot be prepared.
		$this->assertFalse( $this->column_exists( $table, 'test_restore_state' ), 'Precondition: column dropped to simulate an older install.' );

		update_site_option( 'mainwp_db_version', '9.0.2.14' );
		MainWP_Install::instance()->install();

		$this->assertTrue(
			$this->column_exists( $table, 'test_restore_state' ),
			'Upgrade must add test_restore_state to existing installs; the shipped db version needs a bump or dbDelta never runs.'
		);
		$this->assertTrue( $this->column_exists( $table, 'test_restore_at' ), 'Upgrade must add test_restore_at.' );
		$this->assertTrue( $this->index_exists( $table, 'idx_test_restore_state' ), 'Upgrade must add the idx_test_restore_state index.' );

		update_site_option( 'mainwp_db_version', '9.0.2.14' );
		MainWP_Install::instance()->install();
		$this->assertTrue( $this->column_exists( $table, 'test_restore_state' ), 'Re-running install must remain idempotent.' );
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

	/**
	 * An install whose stored db version predates the schedule and retention
	 * columns must receive all of them when install() runs. The schedule
	 * columns drive interval and time-of-day runs; the retention columns hold
	 * the per-task prune policy.
	 *
	 * @return void
	 */
	public function test_upgrade_adds_schedule_and_retention_columns_to_backup_task_table() {
		global $wpdb;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup' );

		foreach ( array_keys( $this->schedule_retention_columns() ) as $column ) {
			$this->assertTrue( $this->column_exists( $table, $column ), "Precondition: fresh install has {$column}." );
		}
		// Drop the index before its column can be dropped.
		if ( $this->index_exists( $table, 'idx_next_run' ) ) {
			$wpdb->query( "ALTER TABLE {$table} DROP INDEX idx_next_run" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture, identifiers cannot be prepared.
		}
		foreach ( array_keys( $this->schedule_retention_columns() ) as $column ) {
			$wpdb->query( "ALTER TABLE {$table} DROP COLUMN {$column}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture, identifiers cannot be prepared.
		}

		update_site_option( 'mainwp_db_version', '9.0.2.9' );

		MainWP_Install::instance()->install();

		foreach ( array_keys( $this->schedule_retention_columns() ) as $column ) {
			$this->assertTrue(
				$this->column_exists( $table, $column ),
				"Upgrade must add {$column} to existing installs; the shipped db version needs a bump or dbDelta never runs."
			);
		}
	}

	/**
	 * An install whose stored db version predates the incremental
	 * chain-length limit must receive the max_chain_length column when
	 * install() runs.
	 *
	 * @return void
	 */
	public function test_upgrade_adds_max_chain_length_column_to_backup_task_table() {
		global $wpdb;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$this->assertTrue( $this->column_exists( $table, 'max_chain_length' ), 'Precondition: fresh install has max_chain_length.' );

		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN max_chain_length" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture, identifiers cannot be prepared.
		$this->assertFalse( $this->column_exists( $table, 'max_chain_length' ), 'Precondition: column dropped to simulate an older install.' );

		update_site_option( 'mainwp_db_version', '9.0.2.11' );

		MainWP_Install::instance()->install();

		$this->assertTrue(
			$this->column_exists( $table, 'max_chain_length' ),
			'Upgrade must add max_chain_length to existing installs; the shipped db version needs a bump or dbDelta never runs.'
		);
	}

	/**
	 * An install whose stored db version predates the ZIP force-override flag
	 * must receive the zip_force_override column when install() runs. The column
	 * records whether the user opted to keep ZIP over the large-site guardrail
	 * instead of the auto-switch to tar.gz.
	 *
	 * @return void
	 */
	public function test_upgrade_adds_zip_force_override_column_to_backup_task_table() {
		global $wpdb;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$this->assertTrue( $this->column_exists( $table, 'zip_force_override' ), 'Precondition: fresh install has zip_force_override.' );

		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN zip_force_override" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture, identifiers cannot be prepared.
		$this->assertFalse( $this->column_exists( $table, 'zip_force_override' ), 'Precondition: column dropped to simulate an older install.' );

		update_site_option( 'mainwp_db_version', '9.0.2.15' );

		MainWP_Install::instance()->install();

		$this->assertTrue(
			$this->column_exists( $table, 'zip_force_override' ),
			'Upgrade must add zip_force_override to existing installs; the shipped db version needs a bump or dbDelta never runs.'
		);
	}

	/**
	 * An install whose stored db version predates the notices channel must
	 * receive the backup_notices column when install() runs. The column keeps
	 * informational run notes (like the large-site ZIP auto-switch) out of
	 * backup_errors, which downstream consumers treat as failure state.
	 *
	 * @return void
	 */
	public function test_upgrade_adds_backup_notices_column_to_backup_task_table() {
		global $wpdb;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$this->assertTrue( $this->column_exists( $table, 'backup_notices' ), 'Precondition: fresh install has backup_notices.' );

		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN backup_notices" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture, identifiers cannot be prepared.
		$this->assertFalse( $this->column_exists( $table, 'backup_notices' ), 'Precondition: column dropped to simulate an older install.' );

		update_site_option( 'mainwp_db_version', '9.0.2.16' );

		MainWP_Install::instance()->install();

		$this->assertTrue(
			$this->column_exists( $table, 'backup_notices' ),
			'Upgrade must add backup_notices to existing installs; the shipped db version needs a bump or dbDelta never runs.'
		);
	}

	/**
	 * The next_run lookup the hourly cron runs must be indexed, both on fresh
	 * installs and through the upgrade path.
	 *
	 * @return void
	 */
	public function test_upgrade_adds_next_run_index_to_backup_task_table() {
		global $wpdb;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$this->assertTrue( $this->index_exists( $table, 'idx_next_run' ), 'Precondition: fresh install has the next_run index.' );

		$wpdb->query( "ALTER TABLE {$table} DROP INDEX idx_next_run" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture, identifiers cannot be prepared.
		$this->assertFalse( $this->index_exists( $table, 'idx_next_run' ), 'Precondition: index dropped to simulate an older install.' );

		update_site_option( 'mainwp_db_version', '9.0.2.9' );

		MainWP_Install::instance()->install();

		$this->assertTrue(
			$this->index_exists( $table, 'idx_next_run' ),
			'Upgrade must add the next_run index to existing installs.'
		);
	}

	/**
	 * An install whose stored db version predates the upload job kind column
	 * must receive it, and the unique key must widen to include kind so a
	 * cloud delete job can coexist with the original upload row for the same
	 * snapshot and destination.
	 *
	 * @return void
	 */
	public function test_upgrade_adds_kind_column_and_widens_unique_key_on_upload_job_table() {
		global $wpdb;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' );

		// Recreate the table from scratch so the fresh-install assertions are
		// not polluted by an old-shape table persisting across suite runs.
		$this->with_real_tables(
			function () use ( $wpdb, $table ) {
				$wpdb->query( "DROP TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.RestrictedFunctions -- test fixture, identifiers cannot be prepared.
				update_site_option( 'mainwp_db_version', '9.0.2.8' );
				MainWP_Install::instance()->install();
			}
		);

		$this->assertTrue( $this->column_exists( $table, 'kind' ), 'Precondition: fresh install has kind.' );
		$this->assertSame(
			array( 'snapshot_id', 'destination_id', 'kind' ),
			$this->index_columns( $table, 'uniq_snap_dest_kind' ),
			'Precondition: fresh install unique key covers kind.'
		);

		// Simulate the pre-kind shape: narrow unique key, no kind column.
		$wpdb->query( "ALTER TABLE {$table} DROP INDEX uniq_snap_dest_kind" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture, identifiers cannot be prepared.
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN kind" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture, identifiers cannot be prepared.
		$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY uniq_snap_dest (snapshot_id, destination_id)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture, identifiers cannot be prepared.
		$this->assertFalse( $this->column_exists( $table, 'kind' ), 'Precondition: column dropped to simulate an older install.' );

		update_site_option( 'mainwp_db_version', '9.0.2.9' );

		MainWP_Install::instance()->install();

		$this->assertTrue(
			$this->column_exists( $table, 'kind' ),
			'Upgrade must add kind to existing installs; the shipped db version needs a bump or dbDelta never runs.'
		);
		$this->assertFalse(
			$this->index_exists( $table, 'uniq_snap_dest' ),
			'Upgrade must drop the narrow unique key; a delete job for the same snapshot and destination would otherwise be uninsertable.'
		);
		$this->assertSame(
			array( 'snapshot_id', 'destination_id', 'kind' ),
			$this->index_columns( $table, 'uniq_snap_dest_kind' ),
			'Upgrade must add the widened unique key.'
		);
	}

	/**
	 * An install whose stored db version predates the reclaim counter must
	 * receive the reclaim_count column when install() runs. The column lets
	 * acquire_next_job() stop reclaiming an expired-lease job forever - past
	 * a ceiling it routes the job to fail_permanent instead. Re-running
	 * install() stays idempotent.
	 *
	 * @return void
	 */
	public function test_upgrade_adds_reclaim_count_column_to_upload_job_table() {
		global $wpdb;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' );
		$this->assertTrue( $this->column_exists( $table, 'reclaim_count' ), 'Precondition: fresh install has reclaim_count.' );

		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN reclaim_count" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture, identifiers cannot be prepared.
		$this->assertFalse( $this->column_exists( $table, 'reclaim_count' ), 'Precondition: column dropped to simulate an older install.' );

		update_site_option( 'mainwp_db_version', '9.0.2.18' );

		MainWP_Install::instance()->install();

		$this->assertTrue(
			$this->column_exists( $table, 'reclaim_count' ),
			'Upgrade must add reclaim_count to existing installs; the shipped db version needs a bump or dbDelta never runs.'
		);

		update_site_option( 'mainwp_db_version', '9.0.2.18' );
		MainWP_Install::instance()->install();
		$this->assertTrue( $this->column_exists( $table, 'reclaim_count' ), 'Re-running install must remain idempotent.' );
	}

	/**
	 * The restore-job table's upgrade path was previously untested. An
	 * install whose stored db version predates the reclaim counter must
	 * receive the reclaim_count column when install() runs, mirroring the
	 * upload-job table's coverage above. Re-running install() stays
	 * idempotent.
	 *
	 * @return void
	 */
	public function test_upgrade_adds_reclaim_count_column_to_restore_job_table() {
		global $wpdb;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$this->assertTrue( $this->column_exists( $table, 'reclaim_count' ), 'Precondition: fresh install has reclaim_count.' );

		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN reclaim_count" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture, identifiers cannot be prepared.
		$this->assertFalse( $this->column_exists( $table, 'reclaim_count' ), 'Precondition: column dropped to simulate an older install.' );

		update_site_option( 'mainwp_db_version', '9.0.2.18' );

		MainWP_Install::instance()->install();

		$this->assertTrue(
			$this->column_exists( $table, 'reclaim_count' ),
			'Upgrade must add reclaim_count to existing installs; the shipped db version needs a bump or dbDelta never runs.'
		);

		update_site_option( 'mainwp_db_version', '9.0.2.18' );
		MainWP_Install::instance()->install();
		$this->assertTrue( $this->column_exists( $table, 'reclaim_count' ), 'Re-running install must remain idempotent.' );
	}

	/**
	 * Installs upgraded from <= 8.53 carry a legacy unnamed UNIQUE (task_id)
	 * key on the progress table, and dbDelta never drops indexes, so only one
	 * per-site progress row could ever exist per task: the second
	 * add_backup_task_progress() insert dies on the duplicate key. The upgrade
	 * must drop the unique key (MySQL names an unnamed UNIQUE after its first
	 * column, so it is Key_name task_id), keep the non-unique idx_task_id the
	 * read path uses, and let one row per site insert cleanly. Re-running
	 * install() stays idempotent.
	 *
	 * @return void
	 */
	public function test_upgrade_drops_legacy_unique_task_id_key_from_progress_table() {
		global $wpdb;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_progress' );
		$this->assertTrue( $this->index_exists( $table, 'idx_task_id' ), 'Precondition: fresh install has idx_task_id.' );
		$this->assertFalse( $this->index_exists( $table, 'task_id' ), 'Precondition: fresh install has no unique task_id key.' );

		// A prior run's DDL can implicitly commit the fixture rows past the
		// transaction rollback, and leftover duplicates would break ADD UNIQUE,
		// so clear them first.
		$wpdb->query( "DELETE FROM {$table} WHERE task_id = 888888" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture cleanup, identifiers cannot be prepared.

		// Simulate the legacy shape the <= 8.53 CREATE clause produced.
		$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE ( task_id )" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture, identifiers cannot be prepared.
		$this->assertTrue( $this->index_exists( $table, 'task_id' ), 'Precondition: legacy unique key added to simulate an upgraded install.' );

		update_site_option( 'mainwp_db_version', '9.0.2.19' );

		MainWP_Install::instance()->install();

		$this->assertFalse(
			$this->index_exists( $table, 'task_id' ),
			'Upgrade must drop the legacy unique task_id key; a second per-site progress row for the same task is otherwise uninsertable.'
		);
		$this->assertTrue( $this->index_exists( $table, 'idx_task_id' ), 'Upgrade must keep the non-unique idx_task_id.' );

		// One progress row per site for the same task must now insert cleanly.
		$first  = MainWP_DB_Backup::instance()->add_backup_task_progress( 888888, 888801, array() );
		$second = MainWP_DB_Backup::instance()->add_backup_task_progress( 888888, 888802, array() );
		$this->assertNotNull( $first, 'The first per-site progress row must insert.' );
		$this->assertNotNull( $second, 'The second per-site progress row for the same task must insert; the legacy unique key used to reject it.' );
		$wpdb->query( "DELETE FROM {$table} WHERE task_id = 888888" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture cleanup, identifiers cannot be prepared.

		update_site_option( 'mainwp_db_version', '9.0.2.19' );
		MainWP_Install::instance()->install();
		$this->assertFalse( $this->index_exists( $table, 'task_id' ), 'Re-running install must remain idempotent.' );
		$this->assertTrue( $this->index_exists( $table, 'idx_task_id' ), 'Re-running install must keep idx_task_id.' );
	}

	/**
	 * (task_id, wp_id) was not unique before db 9.0.2.24, so the executor's
	 * read-then-insert could leave a site with several progress rows and
	 * every read path took an arbitrary one. The upgrade must collapse an
	 * existing install's duplicates to the freshest row of each pair -
	 * older rows carry a stale phase, artifact paths and child task id -
	 * leave a second site's row alone, and only then add the unique key,
	 * which ADD UNIQUE would otherwise refuse. Re-running install() stays
	 * idempotent.
	 *
	 * @return void
	 */
	public function test_upgrade_dedupes_progress_rows_then_adds_unique_task_site_key() {
		global $wpdb;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_progress' );
		$this->assertTrue( $this->index_exists( $table, 'uniq_task_wp' ), 'Precondition: fresh install has the (task_id, wp_id) unique key.' );

		// Simulate the pre-9.0.2.24 shape: no unique key, and a site
		// carrying the duplicate rows the insert race could leave behind.
		$wpdb->query( "ALTER TABLE {$table} DROP INDEX uniq_task_wp" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture, identifiers cannot be prepared.
		$wpdb->query( 'DELETE FROM ' . $table . ' WHERE task_id = ' . self::DEDUPE_TASK_ID ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture cleanup, identifiers cannot be prepared.

		$racing_rows = array(
			1000 => 'oldest-row',
			2000 => 'freshest-row',
			1500 => 'middle-row',
		);

		foreach ( $racing_rows as $fetched => $marker ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
				$table,
				array(
					'task_id'              => self::DEDUPE_TASK_ID,
					'wp_id'                => 888801,
					'dtsFetched'           => $fetched,
					'child_backup_task_id' => $marker,
				)
			);
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			$table,
			array(
				'task_id'              => self::DEDUPE_TASK_ID,
				'wp_id'                => 888802,
				'dtsFetched'           => 1200,
				'child_backup_task_id' => 'other-site-row',
			)
		);

		// A third site holds the tie the freshest-wins rule cannot break:
		// two same-second race inserts, which the count-based cull handles.
		foreach ( array( 'tied-row-a', 'tied-row-b' ) as $marker ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
				$table,
				array(
					'task_id'              => self::DEDUPE_TASK_ID,
					'wp_id'                => 888803,
					'dtsFetched'           => 1300,
					'child_backup_task_id' => $marker,
				)
			);
		}

		update_site_option( 'mainwp_db_version', '9.0.2.23' );

		MainWP_Install::instance()->install();

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT wp_id, child_backup_task_id FROM {$table} WHERE task_id = %d ORDER BY wp_id", self::DEDUPE_TASK_ID ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test probe, identifiers cannot be prepared.

		$this->assertCount( 3, $rows, 'The upgrade must leave one row per site: the duplicates collapse, the single-row site keeps its own.' );
		$this->assertSame( 'freshest-row', $rows[0]->child_backup_task_id, 'The surviving row must be the freshest by dtsFetched; an older row replays a stale child task id.' );
		$this->assertSame( 'other-site-row', $rows[1]->child_backup_task_id, 'A site with a single row must be left alone.' );
		$this->assertContains( $rows[2]->child_backup_task_id, array( 'tied-row-a', 'tied-row-b' ), 'A tie on dtsFetched must still collapse to one of the tied rows.' );

		$this->assertTrue( $this->index_exists( $table, 'uniq_task_wp' ), 'The upgrade must add the (task_id, wp_id) unique key once the duplicates are gone.' );
		$this->assertSame( array( 'task_id', 'wp_id' ), $this->index_columns( $table, 'uniq_task_wp' ), 'The unique key must cover the pair, in that order.' );

		$wpdb->query( 'DELETE FROM ' . $table . ' WHERE task_id = ' . self::DEDUPE_TASK_ID ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture cleanup, identifiers cannot be prepared.

		update_site_option( 'mainwp_db_version', '9.0.2.23' );
		MainWP_Install::instance()->install();
		$this->assertTrue( $this->index_exists( $table, 'uniq_task_wp' ), 'Re-running install must remain idempotent.' );
	}

	/**
	 * A true fresh installation - no plugin tables and no stored
	 * mainwp_db_version - must create the complete schema in one install()
	 * run: every plugin table, the version-gated primary keys, the backup
	 * indexes dbDelta cannot express, the shipped db version stamp, and the
	 * fresh-install options. The empty-$currentVersion path used to crash
	 * dbDelta's index parser on PHP 8.4 via the legacy unnamed UNIQUE
	 * (task_id) clause (removed at db 9.0.2.20), and nothing asserted on
	 * this path before, so a fresh-install regression only surfaced on real
	 * new sites.
	 *
	 * @return void
	 */
	public function test_fresh_install_creates_complete_schema() {
		global $wpdb;

		$like = $wpdb->esc_like( $wpdb->prefix . 'mainwp_' ) . '%';

		// Scope the drop-and-recreate to the tables MainWP_Install::install()
		// actually owns, named from the CREATE TABLE statements it runs.
		// Deriving the set from an allowlist - not from every mainwp_-prefixed
		// table present - is what keeps the test from dropping tables another
		// module or plugin owns (the cost tracker, the logs meta tables, the
		// Assistant tables, ...). Those are never recreated by install(), so an
		// exclusion approach both destroys unrelated fixtures and fails the
		// set-equality below whenever one happens to be present. A new
		// install-owned table must be added here or it goes uncovered on the
		// fresh-install path.
		$install_owned = array_map(
			static function ( $suffix ) {
				return MainWP_DB::instance()->get_table_name( $suffix );
			},
			array(
				'wp',
				'wp_sync',
				'wp_options',
				'wp_settings_backup',
				'users',
				'wp_status',
				'group',
				'wp_group',
				'lookup_item_objects',
				'wp_backup_progress',
				'wp_backup',
				'wp_backup_snapshot',
				'wp_backup_storage',
				'wp_backup_upload_job',
				'wp_backup_restore_job',
				'api_keys',
				'action_log',
				'request_log',
				'schedule_processes',
			)
		);

		$before = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test probe.
		$this->assertNotEmpty( $before, 'Precondition: the installed schema is visible.' );

		$owned = array_values( array_intersect( $before, $install_owned ) );
		$this->assertNotEmpty( $owned, 'Precondition: the installer-owned tables are present to drop.' );

		// An unrelated mainwp_-prefixed table stands in for a fixture another
		// module leaves in the schema; the fresh-install cycle must leave it
		// untouched rather than sweeping it up. It is created, asserted, and
		// dropped inside with_real_tables so the harness's temporary-table
		// rewrite does not turn it into an invisible TEMPORARY table.
		$foreign = $wpdb->prefix . 'mainwp_fixture_external';

		$this->with_real_tables(
			function () use ( $wpdb, $owned, $like, $foreign ) {
				$wpdb->query( "CREATE TABLE IF NOT EXISTS {$foreign} ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (id) )" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- fresh-install fixture, identifiers cannot be prepared.

				foreach ( $owned as $table ) {
					$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- fresh-install fixture, identifiers cannot be prepared.
				}
				delete_site_option( 'mainwp_db_version' );
				delete_option( 'mainwp_enableNativeBackups' );

				MainWP_Install::instance()->install();

				$after = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test probe.
				$this->assertSame( array(), array_values( array_diff( $owned, $after ) ), 'Fresh install must create every plugin table the installer owns.' );
				$this->assertContains( $foreign, $after, 'The fresh-install cycle must not drop a table it does not own.' );

				$wpdb->query( "DROP TABLE IF EXISTS {$foreign}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- test fixture cleanup, identifiers cannot be prepared.
			}
		);

		// The version-gated PRIMARY KEY clauses only fire on this path.
		$this->assertTrue( $this->index_exists( MainWP_DB::instance()->get_table_name( 'wp' ), 'PRIMARY' ), 'Fresh install must create the wp table primary key.' );
		$this->assertTrue( $this->index_exists( MainWP_DB::instance()->get_table_name( 'schedule_processes' ), 'PRIMARY' ), 'Fresh install must create the schedule_processes primary key.' );

		// Backup indexes added outside dbDelta must exist on a fresh install too.
		$task_table = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$this->assertTrue( $this->index_exists( $task_table, 'idx_next_run' ), 'Fresh install must create idx_next_run.' );
		$progress_table = MainWP_DB::instance()->get_table_name( 'wp_backup_progress' );
		$this->assertTrue( $this->index_exists( $progress_table, 'idx_task_id' ), 'Fresh install must create idx_task_id.' );
		$this->assertFalse( $this->index_exists( $progress_table, 'task_id' ), 'Fresh install must not recreate the legacy unique task_id key.' );
		$this->assertTrue( $this->index_exists( $progress_table, 'uniq_task_wp' ), 'Fresh install must create the (task_id, wp_id) unique key.' );
		$upload_job_table = MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' );
		$this->assertTrue( $this->index_exists( $upload_job_table, 'uniq_snap_dest_kind' ), 'Fresh install must create the kind-widened upload-job unique key.' );

		$property = new \ReflectionProperty( MainWP_Install::class, 'mainwp_db_version' );
		$property->setAccessible( true );
		$this->assertSame(
			$property->getValue( MainWP_Install::instance() ),
			get_site_option( 'mainwp_db_version' ),
			'Fresh install must stamp the shipped db version.'
		);

		$this->assertSame( 'yes', get_option( 'mainwp_run_quick_setup' ), 'Fresh install must queue the quick-setup wizard.' );
		$this->assertNotFalse( get_option( 'mainwp_enableNativeBackups' ), 'Fresh install must write the native-backups flag explicitly.' );
		$this->assertSame( 0, (int) get_option( 'mainwp_enableNativeBackups' ), 'Fresh install must default native backups to disabled.' );
	}
}
