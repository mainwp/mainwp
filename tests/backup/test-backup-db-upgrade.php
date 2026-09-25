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
use MainWP\Dashboard\MainWP_Logger;
use MainWP\Dashboard\MainWP_System_Cron_Jobs;
use MainWP\Dashboard\MainWP_Backup_Snapshots;

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
			MainWP_Install::instance()->ensure_backup_snapshot_run_key();
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

		// install() implicitly commits past the transaction rollback, so a
		// repair marker a test set must be cleared by hand.
		delete_site_option( MainWP_Install::BACKUP_PROGRESS_UNIQ_KEY_REPAIR_PENDING );
		delete_site_option( MainWP_Install::BACKUP_SNAPSHOT_RUN_KEY_REPAIR_PENDING );
		delete_site_option( MainWP_Install::BACKUP_TASK_LAST_CONTINUED_REPAIR_PENDING );

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
			'last_continued'       => 'int(11) NOT NULL DEFAULT 0',
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
	 * Existing snapshot rows must survive the additive identity migration.
	 */
	public function test_b11_upgrade_from_90225_adds_snapshot_run_key() {
		global $wpdb;
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$wpdb->query( "ALTER TABLE {$table} DROP INDEX uniq_run_key, DROP COLUMN run_key" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture.
		$wpdb->insert(
			$table,
			array(
				'site_id' => 888812,
				'task_id' => 888812,
				'type'    => 'full',
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- legacy fixture.
		$legacy_id = (int) $wpdb->insert_id;
		update_site_option( MainWP_Install::BACKUP_SNAPSHOT_RUN_KEY_REPAIR_PENDING, time() );
		update_site_option( 'mainwp_db_version', '9.0.2.25' );

		MainWP_Install::instance()->install();

		$this->assertTrue( MainWP_Install::backup_snapshot_run_key_ready() );
		$this->assertTrue( $this->column_exists( $table, 'run_key' ) );
		$this->assertTrue( $this->index_exists( $table, 'uniq_run_key' ) );
		$this->assertSame( array( 'run_key' ), $this->index_columns( $table, 'uniq_run_key' ) );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $legacy_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test probe.
		$this->assertNotNull( $row );
		$this->assertNull( $row->run_key );
		$this->assertSame( '9.0.2.27', get_site_option( 'mainwp_db_version' ) );
		$this->assertFalse( get_site_option( MainWP_Install::BACKUP_SNAPSHOT_RUN_KEY_REPAIR_PENDING ) );
		$wpdb->delete( $table, array( 'id' => $legacy_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- DDL committed this fixture.
	}

	/**
	 * Current-version installs retry a failed column and key addition after one hour.
	 */
	public function test_b11_stale_run_key_marker_repairs_current_install() {
		global $wpdb;
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		update_site_option( 'mainwp_db_version', '9.0.2.25' );
		MainWP_Install::instance()->install();
		$wpdb->query( "ALTER TABLE {$table} DROP INDEX uniq_run_key, DROP COLUMN run_key" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture.
		update_site_option( MainWP_Install::BACKUP_SNAPSHOT_RUN_KEY_REPAIR_PENDING, time() - HOUR_IN_SECONDS - 1 );

		MainWP_Install::instance()->install();

		$this->assertTrue( MainWP_Install::backup_snapshot_run_key_ready() );
		$this->assertTrue( $this->column_exists( $table, 'run_key' ) );
		$this->assertTrue( $this->index_exists( $table, 'uniq_run_key' ) );
		$this->assertFalse( get_site_option( MainWP_Install::BACKUP_SNAPSHOT_RUN_KEY_REPAIR_PENDING ) );
	}

	/**
	 * A failed ALTER must leave an hourly repair marker even after the version is current.
	 */
	public function test_b11_failed_run_key_alter_marks_pending() {
		global $wpdb;
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$wpdb->query( "ALTER TABLE {$table} DROP INDEX uniq_run_key" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture.
		$skip_alter = static function ( $sql ) use ( $table ) {
			return "ALTER TABLE {$table} ADD UNIQUE KEY uniq_run_key (run_key)" === $sql ? 'SELECT mainwp_run_key_forced_failure' : $sql;
		};
		$logger     = MainWP_Logger::instance();
		$priority   = new \ReflectionProperty( $logger, 'logPriority' );
		$priority->setAccessible( true );
		$previous = $priority->getValue( $logger );
		$priority->setValue( $logger, MainWP_Logger::WARNING );
		$messages = array();
		$capture  = static function ( $text ) use ( &$messages ) {
			$messages[] = $text;
		};
		add_filter( 'mainwp_log_do_to_db', '__return_true' );
		add_action( 'mainwp_before_log_data', $capture );
		add_filter( 'query', $skip_alter );
		try {
			$this->assertFalse( MainWP_Install::instance()->ensure_backup_snapshot_run_key() );
			$this->assertFalse( MainWP_Install::backup_snapshot_run_key_ready() );
			$this->assertCount( 1, $messages );
			$this->assertStringContainsString( 'mainwp_run_key_forced_failure', $messages[0] );
			$this->assertFalse( $this->index_exists( $table, 'uniq_run_key' ) );
			$this->assertGreaterThanOrEqual( time() - 5, (int) get_site_option( MainWP_Install::BACKUP_SNAPSHOT_RUN_KEY_REPAIR_PENDING ) );
		} finally {
			remove_filter( 'query', $skip_alter );
			remove_action( 'mainwp_before_log_data', $capture );
			remove_filter( 'mainwp_log_do_to_db', '__return_true' );
			$priority->setValue( $logger, $previous );
			foreach ( $messages as $message ) {
				$wpdb->delete( MainWP_DB::instance()->get_table_name( 'action_log' ), array( 'log_content' => $message ) ); // phpcs:ignore WordPress.DB -- test cleanup.
				$wpdb->delete( MainWP_DB::instance()->get_table_name( 'action_log' ), array( 'log_content' => 'CRON :: ' . $message ) ); // phpcs:ignore WordPress.DB -- test cleanup.
			}
		}
		$this->assertTrue( MainWP_Install::instance()->ensure_backup_snapshot_run_key() );
		$this->assertTrue( MainWP_Install::backup_snapshot_run_key_ready() );
		$this->assertFalse( get_site_option( MainWP_Install::BACKUP_SNAPSHOT_RUN_KEY_REPAIR_PENDING ) );
	}

	/**
	 * A same-name non-unique index must fall back to legacy inserts.
	 */
	public function test_b11_malformed_run_key_index_inserts_legacy_rows() {
		global $wpdb;
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		try {
			$wpdb->query( "ALTER TABLE {$table} DROP INDEX uniq_run_key, ADD INDEX uniq_run_key (run_key)" ); // phpcs:ignore WordPress.DB -- test fixture.
			$this->assertFalse( MainWP_Install::instance()->ensure_backup_snapshot_run_key() );
			$this->assertGreaterThanOrEqual( time() - 5, (int) get_site_option( MainWP_Install::BACKUP_SNAPSHOT_RUN_KEY_REPAIR_PENDING ) );
			$this->assertFalse( MainWP_Install::backup_snapshot_run_key_ready() );
			$data   = array(
				'site_id' => 888899,
				'run_key' => 'malformed-index-run',
			);
			$first  = MainWP_Backup_Snapshots::instance()->record_snapshot( $data );
			$second = MainWP_Backup_Snapshots::instance()->record_snapshot( $data );
			$this->assertGreaterThan( 0, $first );
			$this->assertGreaterThan( 0, $second );
			$this->assertNotSame( $first, $second );
			$this->assertCount( 2, MainWP_Backup_Snapshots::instance()->get_snapshots_for_site( 888899 ) );
			$this->assertNull( MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $first )->run_key );
			$this->assertNull( MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $second )->run_key );
		} finally {
			$wpdb->query( "ALTER TABLE {$table} DROP INDEX uniq_run_key" ); // phpcs:ignore WordPress.DB -- test cleanup.
			$wpdb->delete( $table, array( 'site_id' => 888899 ) ); // phpcs:ignore WordPress.DB -- test cleanup.
			MainWP_Install::instance()->ensure_backup_snapshot_run_key();
			delete_site_option( MainWP_Install::BACKUP_SNAPSHOT_RUN_KEY_REPAIR_PENDING );
		}
	}

	/**
	 * A denied ALTER must not be retried on every request.
	 */
	public function test_b11_fresh_run_key_marker_throttles_retry() {
		global $wpdb;
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		update_site_option( 'mainwp_db_version', '9.0.2.25' );
		MainWP_Install::instance()->install();
		$wpdb->query( "ALTER TABLE {$table} DROP INDEX uniq_run_key" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture.
		$marked = time() - 60;
		update_site_option( MainWP_Install::BACKUP_SNAPSHOT_RUN_KEY_REPAIR_PENDING, $marked );

		MainWP_Install::instance()->install();

		$this->assertFalse( $this->index_exists( $table, 'uniq_run_key' ) );
		$this->assertSame( $marked, (int) get_site_option( MainWP_Install::BACKUP_SNAPSHOT_RUN_KEY_REPAIR_PENDING ) );
		update_site_option( MainWP_Install::BACKUP_SNAPSHOT_RUN_KEY_REPAIR_PENDING, time() - HOUR_IN_SECONDS - 1 );
		MainWP_Install::instance()->install();
		$this->assertTrue( $this->index_exists( $table, 'uniq_run_key' ) );
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
	 * A denied column repair must not stop the continue lane from advancing tasks.
	 *
	 * @return void
	 */
	public function test_continue_lane_runs_while_last_continued_repair_is_pending() {
		global $wpdb;
		$db      = MainWP_DB_Backup::instance();
		$table   = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$pending = get_site_option( MainWP_Install::BACKUP_TASK_LAST_CONTINUED_REPAIR_PENDING );
		$enabled = get_option( 'mainwp_enableNativeBackups' );
		$task    = $db->add_backup_task( self::factory()->user->create(), 'Pending repair', 'daily', 'full', '', '', '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task );
		$queries = array();
		$capture = static function ( $sql ) use ( &$queries ) {
			$queries[] = $sql;
			return $sql;
		};
		try {
			$db->update_backup_task_with_values(
				$task->id,
				array(
					'last_run'  => 100,
					'completed' => 0,
					'paused'    => 0,
				)
			);
			$wpdb->query( "ALTER TABLE {$table} DROP COLUMN last_continued" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture.
			$this->assertFalse( $this->column_exists( $table, 'last_continued' ) );
			update_site_option( MainWP_Install::BACKUP_TASK_LAST_CONTINUED_REPAIR_PENDING, time() );
			update_option( 'mainwp_enableNativeBackups', 1 );
			$this->assertFalse( MainWP_Install::backup_task_last_continued_ready() );
			$tasks = $db->get_backup_tasks_to_complete();
			$this->assertSame( '', $wpdb->last_error );
			$this->assertContains( (int) $task->id, array_map( 'intval', wp_list_pluck( $tasks, 'id' ) ) );

			$lane = new class() extends MainWP_System_Cron_Jobs {
				/**
				 * Attempted task IDs.
				 *
				 * @var int[]
				 */
				public $picked = array();

				/**
				 * Keep the task unfinished so the test needs no child connection.
				 *
				 * @param object $task Backup task.
				 * @return bool
				 */
				protected function continue_backup_task( $task ) {
					$this->picked[] = (int) $task->id;
					return false;
				}
			};
			add_filter( 'query', $capture );
			$lane->cron_backups_continue();
			remove_filter( 'query', $capture );
			$this->assertSame( array( (int) $task->id ), $lane->picked );
			foreach ( $queries as $sql ) {
				if ( str_contains( $sql, $table ) ) {
					$this->assertStringNotContainsString( 'last_continued', $sql );
				}
			}
		} finally {
			remove_filter( 'query', $capture );
			MainWP_Install::instance()->ensure_backup_task_last_continued();
			if ( false === $pending ) {
				delete_site_option( MainWP_Install::BACKUP_TASK_LAST_CONTINUED_REPAIR_PENDING );
			} else {
				update_site_option( MainWP_Install::BACKUP_TASK_LAST_CONTINUED_REPAIR_PENDING, $pending );
			}
			if ( false === $enabled ) {
				delete_option( 'mainwp_enableNativeBackups' );
			} else {
				update_option( 'mainwp_enableNativeBackups', $enabled );
			}
			$db->remove_backup_task( $task->id );
		}
	}

	/**
	 * A failed dbDelta column addition must remain repairable after the version stamp.
	 *
	 * @return void
	 */
	public function test_ensure_last_continued_repairs_missing_column() {
		global $wpdb;
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN last_continued" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture.
		update_site_option( MainWP_Install::BACKUP_TASK_LAST_CONTINUED_REPAIR_PENDING, time() );

		$this->assertTrue( MainWP_Install::instance()->ensure_backup_task_last_continued() );
		$this->assertTrue( $this->column_exists( $table, 'last_continued' ) );
		$this->assertFalse( get_site_option( MainWP_Install::BACKUP_TASK_LAST_CONTINUED_REPAIR_PENDING ) );
	}

	/**
	 * Current-version installs must retry an overdue repair without retrying every request.
	 *
	 * @return void
	 */
	public function test_stale_last_continued_marker_repairs_current_install() {
		global $wpdb;
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		update_site_option( 'mainwp_db_version', '9.0.2.27' );
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN last_continued" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture.
		$marked = time() - 60;
		update_site_option( MainWP_Install::BACKUP_TASK_LAST_CONTINUED_REPAIR_PENDING, $marked );

		MainWP_Install::instance()->install();

		$this->assertFalse( $this->column_exists( $table, 'last_continued' ) );
		$this->assertSame( $marked, (int) get_site_option( MainWP_Install::BACKUP_TASK_LAST_CONTINUED_REPAIR_PENDING ) );
		update_site_option( MainWP_Install::BACKUP_TASK_LAST_CONTINUED_REPAIR_PENDING, time() - HOUR_IN_SECONDS - 1 );

		MainWP_Install::instance()->install();

		$this->assertTrue( $this->column_exists( $table, 'last_continued' ) );
		$this->assertFalse( get_site_option( MainWP_Install::BACKUP_TASK_LAST_CONTINUED_REPAIR_PENDING ) );
	}

	/**
	 * A failed ALTER must leave an hourly repair marker even after the version is current.
	 *
	 * @return void
	 */
	public function test_failed_last_continued_alter_marks_pending() {
		global $wpdb;
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN last_continued" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture.
		$skip_alter = static function ( $sql ) use ( $table ) {
			return "ALTER TABLE {$table} ADD COLUMN last_continued int(11) NOT NULL DEFAULT 0" === $sql ? 'SELECT mainwp_last_continued_forced_failure' : $sql;
		};
		$logger     = MainWP_Logger::instance();
		$priority   = new \ReflectionProperty( $logger, 'logPriority' );
		$priority->setAccessible( true );
		$previous = $priority->getValue( $logger );
		$priority->setValue( $logger, MainWP_Logger::WARNING );
		$messages = array();
		$capture  = static function ( $text ) use ( &$messages ) {
			$messages[] = $text;
		};
		add_filter( 'mainwp_log_do_to_db', '__return_true' );
		add_action( 'mainwp_before_log_data', $capture );
		add_filter( 'query', $skip_alter );
		try {
			$this->assertFalse( MainWP_Install::instance()->ensure_backup_task_last_continued() );
			$this->assertCount( 1, $messages );
			$this->assertFalse( $this->column_exists( $table, 'last_continued' ) );
			$this->assertGreaterThanOrEqual( time() - 5, (int) get_site_option( MainWP_Install::BACKUP_TASK_LAST_CONTINUED_REPAIR_PENDING ) );
		} finally {
			remove_filter( 'query', $skip_alter );
			remove_action( 'mainwp_before_log_data', $capture );
			remove_filter( 'mainwp_log_do_to_db', '__return_true' );
			$priority->setValue( $logger, $previous );
			foreach ( $messages as $message ) {
				$wpdb->delete( MainWP_DB::instance()->get_table_name( 'action_log' ), array( 'log_content' => $message ) ); // phpcs:ignore WordPress.DB -- test cleanup.
				$wpdb->delete( MainWP_DB::instance()->get_table_name( 'action_log' ), array( 'log_content' => 'CRON :: ' . $message ) ); // phpcs:ignore WordPress.DB -- test cleanup.
			}
		}
		$this->assertTrue( MainWP_Install::instance()->ensure_backup_task_last_continued() );
		$this->assertFalse( get_site_option( MainWP_Install::BACKUP_TASK_LAST_CONTINUED_REPAIR_PENDING ) );
	}

	/**
	 * The continue lane orders unfinished tasks by last_continued, so an
	 * upgraded install needs the column or every tick falls over on the query.
	 *
	 * @return void
	 */
	public function test_upgrade_adds_last_continued_column_to_backup_task_table() {
		global $wpdb;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$this->assertTrue( $this->column_exists( $table, 'last_continued' ), 'Precondition: fresh install has last_continued.' );

		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN last_continued" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture, identifiers cannot be prepared.
		$this->assertFalse( $this->column_exists( $table, 'last_continued' ), 'Precondition: column dropped to simulate an older install.' );

		update_site_option( 'mainwp_db_version', '9.0.2.26' );

		MainWP_Install::instance()->install();

		$this->assertTrue(
			$this->column_exists( $table, 'last_continued' ),
			'Upgrade must add last_continued to existing installs; the shipped db version needs a bump or dbDelta never runs.'
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
	 * existing install's duplicates to one row per pair - a row naming the
	 * child's backup task first, then the freshest dtsFetched, then a
	 * count-based cull for what is left - leave a second site's row alone,
	 * and only then add the unique key, which ADD UNIQUE would otherwise
	 * refuse. Re-running install() stays idempotent.
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

		// A fourth site holds the shape freshest-wins gets wrong: the
		// executor stamps child_backup_task_id without advancing dtsFetched,
		// so a dead driver's later reset row outranks the only row that can
		// still poll or cancel the child's queued run.
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			$table,
			array(
				'task_id'              => self::DEDUPE_TASK_ID,
				'wp_id'                => 888804,
				'dtsFetched'           => 1400,
				'child_backup_task_id' => 'child-task-still-running',
			)
		);
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			$table,
			array(
				'task_id'              => self::DEDUPE_TASK_ID,
				'wp_id'                => 888804,
				'dtsFetched'           => 1900,
				'child_backup_task_id' => '',
			)
		);

		update_site_option( 'mainwp_db_version', '9.0.2.23' );

		MainWP_Install::instance()->install();

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT wp_id, child_backup_task_id FROM {$table} WHERE task_id = %d ORDER BY wp_id", self::DEDUPE_TASK_ID ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test probe, identifiers cannot be prepared.

		$this->assertCount( 4, $rows, 'The upgrade must leave one row per site: the duplicates collapse, the single-row site keeps its own.' );
		$this->assertSame( 'freshest-row', $rows[0]->child_backup_task_id, 'Among rows that all name a child task, the freshest by dtsFetched must survive; an older row replays a stale child task id.' );
		$this->assertSame( 'other-site-row', $rows[1]->child_backup_task_id, 'A site with a single row must be left alone.' );
		$this->assertContains( $rows[2]->child_backup_task_id, array( 'tied-row-a', 'tied-row-b' ), 'A tie on dtsFetched must still collapse to one of the tied rows.' );
		$this->assertSame( 'child-task-still-running', $rows[3]->child_backup_task_id, 'A row naming the child task must outlive a later row that names none; the child task id is the only handle left on a run already queued on the child.' );

		$this->assertTrue( $this->index_exists( $table, 'uniq_task_wp' ), 'The upgrade must add the (task_id, wp_id) unique key once the duplicates are gone.' );
		$this->assertSame( array( 'task_id', 'wp_id' ), $this->index_columns( $table, 'uniq_task_wp' ), 'The unique key must cover the pair, in that order.' );

		$wpdb->query( 'DELETE FROM ' . $table . ' WHERE task_id = ' . self::DEDUPE_TASK_ID ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture cleanup, identifiers cannot be prepared.

		update_site_option( 'mainwp_db_version', '9.0.2.23' );
		MainWP_Install::instance()->install();
		$this->assertTrue( $this->index_exists( $table, 'uniq_task_wp' ), 'Re-running install must remain idempotent.' );
	}

	/**
	 * The unique key is the whole point of the 9.0.2.24 bump, so an upgrade
	 * that installed it must leave no repair marker behind - a marker would
	 * make every later request pay the hourly retry for nothing.
	 *
	 * @return void
	 */
	public function test_successful_upgrade_leaves_no_uniq_key_repair_marker() {
		global $wpdb;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_progress' );

		$wpdb->query( "ALTER TABLE {$table} DROP INDEX uniq_task_wp" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture, identifiers cannot be prepared.
		update_site_option( MainWP_Install::BACKUP_PROGRESS_UNIQ_KEY_REPAIR_PENDING, time() );

		update_site_option( 'mainwp_db_version', '9.0.2.23' );
		MainWP_Install::instance()->install();

		$this->assertTrue( $this->index_exists( $table, 'uniq_task_wp' ), 'The upgrade must add the unique key.' );
		$this->assertFalse(
			get_site_option( MainWP_Install::BACKUP_PROGRESS_UNIQ_KEY_REPAIR_PENDING ),
			'An upgrade that installed the key must clear the repair marker.'
		);
	}

	/**
	 * dbDelta and the migration ALTER both run error-suppressed, and
	 * install() early-returns once the stored version matches the shipped
	 * one, so a key install that silently failed would never be retried and
	 * add_backup_task_progress() would quietly fall back to plain inserts.
	 * A stale repair marker must make the next install() call reinstall the
	 * key, collapsing any duplicates that landed while it was gone - ADD
	 * UNIQUE refuses otherwise, and the retry would be stuck forever.
	 *
	 * @return void
	 */
	public function test_stale_repair_marker_reinstalls_the_uniq_key_and_dedupes_first() {
		global $wpdb;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_progress' );

		// Bring the stored version up to the shipped one so install() takes
		// the early-return path the retry hangs off.
		update_site_option( 'mainwp_db_version', '9.0.2.23' );
		MainWP_Install::instance()->install();

		// Simulate the ALTER having failed: no key, a marker older than the
		// throttle, and a duplicate pair inserted while the key was absent.
		$wpdb->query( "ALTER TABLE {$table} DROP INDEX uniq_task_wp" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture, identifiers cannot be prepared.
		$wpdb->query( 'DELETE FROM ' . $table . ' WHERE task_id = ' . self::DEDUPE_TASK_ID ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture cleanup, identifiers cannot be prepared.
		$racing_rows = array(
			1000 => 'child-task-live',
			2000 => '',
		);
		foreach ( $racing_rows as $fetched => $marker ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
				$table,
				array(
					'task_id'              => self::DEDUPE_TASK_ID,
					'wp_id'                => 888811,
					'dtsFetched'           => $fetched,
					'child_backup_task_id' => $marker,
				)
			);
		}
		update_site_option( MainWP_Install::BACKUP_PROGRESS_UNIQ_KEY_REPAIR_PENDING, time() - HOUR_IN_SECONDS - 1 );

		MainWP_Install::instance()->install();

		$this->assertTrue( $this->index_exists( $table, 'uniq_task_wp' ), 'A stale repair marker must make install() reinstall the unique key.' );
		$this->assertSame( array( 'task_id', 'wp_id' ), $this->index_columns( $table, 'uniq_task_wp' ), 'The reinstalled key must cover the pair, in that order.' );
		$this->assertFalse(
			get_site_option( MainWP_Install::BACKUP_PROGRESS_UNIQ_KEY_REPAIR_PENDING ),
			'A retry that installed the key must clear the repair marker.'
		);

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT child_backup_task_id FROM {$table} WHERE task_id = %d", self::DEDUPE_TASK_ID ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test probe, identifiers cannot be prepared.
		$this->assertCount( 1, $rows, 'The retry must collapse duplicates that landed while the key was missing; ADD UNIQUE refuses otherwise.' );
		$this->assertSame( 'child-task-live', $rows[0]->child_backup_task_id, 'The retry must apply the same survivor rule as the migration.' );

		$wpdb->query( 'DELETE FROM ' . $table . ' WHERE task_id = ' . self::DEDUPE_TASK_ID ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture cleanup, identifiers cannot be prepared.
	}

	/**
	 * An install whose DB user cannot ALTER would pay two SHOW INDEX and a
	 * failing ALTER on every request, so a repair marker younger than an
	 * hour must make the retry a no-op.
	 *
	 * @return void
	 */
	public function test_fresh_repair_marker_throttles_the_uniq_key_retry() {
		global $wpdb;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_progress' );

		update_site_option( 'mainwp_db_version', '9.0.2.23' );
		MainWP_Install::instance()->install();

		$wpdb->query( "ALTER TABLE {$table} DROP INDEX uniq_task_wp" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture, identifiers cannot be prepared.
		$marked = time() - 60;
		update_site_option( MainWP_Install::BACKUP_PROGRESS_UNIQ_KEY_REPAIR_PENDING, $marked );

		MainWP_Install::instance()->install();

		$this->assertFalse( $this->index_exists( $table, 'uniq_task_wp' ), 'A marker younger than an hour must not trigger a retry.' );
		$this->assertSame(
			$marked,
			(int) get_site_option( MainWP_Install::BACKUP_PROGRESS_UNIQ_KEY_REPAIR_PENDING ),
			'A throttled retry must leave the marker timestamp untouched.'
		);

		// tear_down restores the key, but assert the throttle is a throttle
		// and not a permanent give-up before handing it back.
		update_site_option( MainWP_Install::BACKUP_PROGRESS_UNIQ_KEY_REPAIR_PENDING, time() - HOUR_IN_SECONDS - 1 );
		MainWP_Install::instance()->install();
		$this->assertTrue( $this->index_exists( $table, 'uniq_task_wp' ), 'Once the marker ages past the hour, the retry must run.' );
	}

	/**
	 * The count-based cull reads a COUNT in one statement and deletes by it
	 * in another, so two requests collapsing the same pair at once would
	 * each delete one row and leave the site with none. A request that
	 * cannot take the dedupe lock must leave the rows alone, skip the ALTER
	 * the surviving duplicates would refuse anyway, and leave the repair
	 * marker stamped - and the retry must then finish the job once the lock
	 * is free.
	 *
	 * @return void
	 */
	public function test_dedupe_skips_and_marks_pending_while_another_request_holds_the_lock() {
		global $wpdb;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_progress' );

		update_site_option( 'mainwp_db_version', '9.0.2.23' );
		MainWP_Install::instance()->install();

		$wpdb->query( "ALTER TABLE {$table} DROP INDEX uniq_task_wp" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture, identifiers cannot be prepared.
		$wpdb->query( 'DELETE FROM ' . $table . ' WHERE task_id = ' . self::DEDUPE_TASK_ID ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture cleanup, identifiers cannot be prepared.

		// A pair only the count-based cull can resolve: same dtsFetched, and
		// both rows name a child task, so neither self-join pass touches them.
		foreach ( array( 'tied-lock-a', 'tied-lock-b' ) as $marker ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
				$table,
				array(
					'task_id'              => self::DEDUPE_TASK_ID,
					'wp_id'                => 888821,
					'dtsFetched'           => 1700,
					'child_backup_task_id' => $marker,
				)
			);
		}

		// The racing request. A second wpdb rather than raw mysqli because
		// wpdb parses the socket form DB_HOST takes on this harness, and the
		// advisory lock is scoped to the connection that took it.
		$holder = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );

		try {
			$this->assertSame(
				'1',
				(string) $holder->get_var( "SELECT GET_LOCK('mainwp_backup_progress_dedupe', 0)" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- advisory lock on the stand-in connection.
				'Precondition: the second connection must own the dedupe lock.'
			);

			$this->assertFalse(
				MainWP_Install::instance()->ensure_backup_progress_unique_key(),
				'A request that cannot take the dedupe lock must report the key install as unfinished.'
			);
			$this->assertFalse( $this->index_exists( $table, 'uniq_task_wp' ), 'The skipped attempt must not add the key over surviving duplicates.' );
			$this->assertSame(
				2,
				(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE task_id = %d", self::DEDUPE_TASK_ID ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test probe, identifiers cannot be prepared.
				'The skipped attempt must leave both rows; acting on a stale count is what empties the pair.'
			);
			$this->assertNotEmpty(
				get_site_option( MainWP_Install::BACKUP_PROGRESS_UNIQ_KEY_REPAIR_PENDING ),
				'A skipped attempt must leave the repair marker stamped for the hourly retry.'
			);
		} finally {
			$holder->get_var( "SELECT RELEASE_LOCK('mainwp_backup_progress_dedupe')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- advisory lock on the stand-in connection.
			$holder->close();
		}

		$this->assertTrue(
			MainWP_Install::instance()->ensure_backup_progress_unique_key(),
			'Once the lock is free the retry must finish the key install.'
		);
		$this->assertTrue( $this->index_exists( $table, 'uniq_task_wp' ), 'The retry must add the unique key.' );
		$this->assertSame(
			1,
			(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE task_id = %d", self::DEDUPE_TASK_ID ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test probe, identifiers cannot be prepared.
			'The retry must collapse the tied pair to a single row.'
		);
		$this->assertFalse(
			get_site_option( MainWP_Install::BACKUP_PROGRESS_UNIQ_KEY_REPAIR_PENDING ),
			'A retry that installed the key must clear the repair marker.'
		);

		$wpdb->query( 'DELETE FROM ' . $table . ' WHERE task_id = ' . self::DEDUPE_TASK_ID ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture cleanup, identifiers cannot be prepared.
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

		$this->assertSame( '9.0.2.27', get_site_option( 'mainwp_db_version' ), 'Fresh install must preserve the merged native-backups db version.' );

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

	/**
	 * Upgrading from before 9.0.2.4 must run main's index repair.
	 *
	 * @return void
	 */
	public function test_install_runs_backup_progress_index_repair_on_upgrade() {
		$installer = new class() extends MainWP_Install {
			/**
			 * Recorded repairs.
			 *
			 * @var array
			 */
			public $calls = array();

			/**
			 * Record the migration's repair without changing indexes.
			 *
			 * @return bool
			 */
			public function repair_backup_progress_index() {
				$this->calls[] = 'repair_backup_progress_index';
				return true;
			}
		};

		$this->with_real_tables(
			function () use ( $installer ) {
				update_site_option( 'mainwp_db_version', '9.0.2.3' );
				$installer->install();
			}
		);

		$this->assertSame( array( 'repair_backup_progress_index', 'repair_backup_progress_index' ), $installer->calls );
		$this->assertSame( '9.0.2.27', get_site_option( 'mainwp_db_version' ) );
	}

	/**
	 * A current installation retries main's index repair before the branch's key repair.
	 *
	 * @return void
	 */
	public function test_install_retries_index_repair_before_unique_key_on_current_version() {
		$installer     = new class() extends MainWP_Install {
			/**
			 * Recorded repairs in execution order.
			 *
			 * @var array
			 */
			public $calls = array();

			/**
			 * Record the retry and retain its pending-marker check.
			 *
			 * @return bool
			 */
			public function maybe_retry_backup_progress_index_repair() {
				$this->calls[] = 'maybe_retry_backup_progress_index_repair';
				return parent::maybe_retry_backup_progress_index_repair();
			}

			/**
			 * Record the repair without changing indexes.
			 *
			 * @return bool
			 */
			public function repair_backup_progress_index() {
				$this->calls[] = 'repair_backup_progress_index';
				return true;
			}

			/**
			 * Record the branch's subsequent retry.
			 *
			 * @return bool
			 */
			public function maybe_retry_backup_progress_unique_key() {
				$this->calls[] = 'maybe_retry_backup_progress_unique_key';
				return true;
			}
		};
		$saved_pending = get_site_option( MainWP_Install::BACKUP_PROGRESS_INDEX_REPAIR_PENDING );

		try {
			update_site_option( 'mainwp_db_version', '9.0.2.27' );
			update_site_option( MainWP_Install::BACKUP_PROGRESS_INDEX_REPAIR_PENDING, time() - 2 * HOUR_IN_SECONDS );
			$installer->install();

			$this->assertSame(
				array(
					'maybe_retry_backup_progress_index_repair',
					'repair_backup_progress_index',
					'maybe_retry_backup_progress_unique_key',
				),
				$installer->calls,
				'The current-version path must retry both repairs in merge order.'
			);
		} finally {
			if ( false === $saved_pending ) {
				delete_site_option( MainWP_Install::BACKUP_PROGRESS_INDEX_REPAIR_PENDING );
			} else {
				update_site_option( MainWP_Install::BACKUP_PROGRESS_INDEX_REPAIR_PENDING, $saved_pending );
			}
		}
	}
	/**
	 * Failed markerless drops survive upgrades from 9.0.2.20.
	 */
	public function test_install_repairs_markerless_progress_key_from_90220() {
		$this->assert_markerless_progress_key_repaired( '9.0.2.20' );
	}

	/**
	 * The previous shipped version also missed the markerless cohort.
	 */
	public function test_install_repairs_markerless_progress_key_from_90224() {
		$this->assert_markerless_progress_key_repaired( '9.0.2.24' );
	}

	/**
	 * Both keys must coexist before the upgrade to catch first-column matching.
	 *
	 * @param string $version Stored version before repair.
	 */
	private function assert_markerless_progress_key_repaired( $version ) {
		global $wpdb;
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_progress' );
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB -- test cleanup.
		$this->assertTrue( $this->index_exists( $table, 'uniq_task_wp' ) );
		$this->assertNotFalse( $wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY task_id (task_id)" ) ); // phpcs:ignore WordPress.DB -- test fixture.
		delete_site_option( MainWP_Install::BACKUP_PROGRESS_INDEX_REPAIR_PENDING );
		delete_site_option( MainWP_Install::BACKUP_PROGRESS_UNIQ_KEY_REPAIR_PENDING );

		$this->with_real_tables(
			function () use ( $version ) {
				update_site_option( 'mainwp_db_version', $version );
				MainWP_Install::instance()->install();
			}
		);
		$this->assertFalse( $this->index_exists( $table, 'task_id' ) );
		$this->assertTrue( $this->index_exists( $table, 'uniq_task_wp' ) );
		$property = new \ReflectionProperty( MainWP_Install::class, 'mainwp_db_version' );
		$this->assertSame( $property->getValue( MainWP_Install::instance() ), get_site_option( 'mainwp_db_version' ) );
		$this->assertNotNull( MainWP_DB_Backup::instance()->add_backup_task_progress( self::DEDUPE_TASK_ID, 88001, array() ) );
		$this->assertNotNull( MainWP_DB_Backup::instance()->add_backup_task_progress( self::DEDUPE_TASK_ID, 88002, array() ) );
		$this->assertSame( 2, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE task_id = %d", self::DEDUPE_TASK_ID ) ) ); // phpcs:ignore WordPress.DB -- test probe.
	}

	/**
	 * Duplicate manual tasks keep their ids because snapshots reference them.
	 */
	public function test_install_backfills_manual_site_identity_and_keeps_duplicate_rows() {
		global $wpdb;
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$ids   = array();
		try {
			$wpdb->query( "ALTER TABLE {$table} DROP INDEX uniq_manual_site, DROP COLUMN manual_site_id" ); // phpcs:ignore WordPress.DB -- test fixture.
			foreach ( array( array( '88001', 'manual', 1 ), array( '088001', 'manual', 1 ), array( '88001', 'daily', 0 ), array( '88001,88002', 'manual', 1 ), array( '88002', 'manual', 1 ) ) as $fixture ) {
				$this->assertSame(
					1,
					$wpdb->insert( // phpcs:ignore WordPress.DB -- test fixture.
						$table,
						array(
							'userid'   => 1,
							'name'     => 'Manual identity migration',
							'sites'    => $fixture[0],
							'schedule' => $fixture[1],
							'template' => $fixture[2],
						)
					)
				);
				$ids[] = (int) $wpdb->insert_id;
			}
			$this->with_real_tables(
				function () {
					update_site_option( 'mainwp_db_version', '9.0.2.24' );
					MainWP_Install::instance()->install();
				}
			);
			$this->assertTrue( $this->index_exists( $table, 'uniq_manual_site' ) );
			foreach ( $ids as $index => $id ) {
				$row = MainWP_DB_Backup::instance()->get_backup_task_by_id( $id );
				$this->assertNotNull( $row );
				if ( 0 === $index || 4 === $index ) {
					$this->assertSame( 0 === $index ? 88001 : 88002, (int) $row->manual_site_id );
				} else {
					$this->assertNull( $row->manual_site_id );
				}
			}
			$this->assertSame( $ids[0], (int) MainWP_DB_Backup::instance()->get_manual_backup_task( 88001 )->id );
		} finally {
			foreach ( $ids as $id ) {
				$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB -- test cleanup.
			}
			MainWP_Install::instance()->repair_backup_site_unique_keys();
			if ( false === $this->saved_db_version ) {
				delete_site_option( 'mainwp_db_version' );
			} else {
				update_site_option( 'mainwp_db_version', $this->saved_db_version );
			}
			self::commit_transaction();
		}
	}

	/**
	 * A failed ALTER must remain retryable after the version advances.
	 */
	public function test_site_identity_key_repair_retries_failed_alters_on_current_version() {
		global $wpdb;
		$backup  = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$restore = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$block   = static function ( $query ) {
			if ( str_starts_with( $query, 'ALTER TABLE ' ) && ( str_contains( $query, 'ADD UNIQUE KEY uniq_manual_site' ) || str_contains( $query, 'ADD UNIQUE KEY uniq_active_site' ) ) ) {
				return 'SELECT 1';
			}
			return $query;
		};
		try {
			$wpdb->query( "ALTER TABLE {$backup} DROP INDEX uniq_manual_site" ); // phpcs:ignore WordPress.DB -- test fixture.
			$wpdb->query( "ALTER TABLE {$restore} DROP INDEX uniq_active_site" ); // phpcs:ignore WordPress.DB -- test fixture.
			add_filter( 'query', $block );
			$this->assertFalse( MainWP_Install::instance()->repair_backup_site_unique_keys() );
			$this->assertGreaterThan( 0, (int) get_site_option( MainWP_Install::BACKUP_SITE_UNIQ_KEYS_REPAIR_PENDING ) );
			$property = new \ReflectionProperty( MainWP_Install::class, 'mainwp_db_version' );
			update_site_option( 'mainwp_db_version', $property->getValue( MainWP_Install::instance() ) );
			remove_filter( 'query', $block );

			MainWP_Install::instance()->install();
			$this->assertFalse( $this->index_exists( $backup, 'uniq_manual_site' ), 'A recent failed repair must be throttled.' );
			$this->assertFalse( $this->index_exists( $restore, 'uniq_active_site' ) );

			update_site_option( MainWP_Install::BACKUP_SITE_UNIQ_KEYS_REPAIR_PENDING, time() - 2 * HOUR_IN_SECONDS );
			MainWP_Install::instance()->install();
			$this->assertTrue( $this->index_exists( $backup, 'uniq_manual_site' ) );
			$this->assertTrue( $this->index_exists( $restore, 'uniq_active_site' ) );
			$this->assertFalse( get_site_option( MainWP_Install::BACKUP_SITE_UNIQ_KEYS_REPAIR_PENDING ) );
		} finally {
			remove_filter( 'query', $block );
			MainWP_Install::instance()->repair_backup_site_unique_keys();
			if ( false === $this->saved_db_version ) {
				delete_site_option( 'mainwp_db_version' );
			} else {
				update_site_option( 'mainwp_db_version', $this->saved_db_version );
			}
			self::commit_transaction();
		}
	}

	/**
	 * Restore repair decisions for pristine and progressed rows.
	 *
	 * @return array Fixtures and surviving row offset, or null when repair must defer.
	 */
	public static function restore_repair_matrix() {
		return array(
			'no active rows'       => array( array(), null, true ),
			'one active row'       => array( array( array() ), 0, true ),
			'all pristine'         => array( array( array(), array() ), 1, true ),
			'newer progressed'     => array( array( array(), array( 'status' => 'running' ) ), 1, true ),
			'older progressed'     => array( array( array( 'status' => 'failed_retryable' ), array() ), 0, true ),
			'child handle'         => array( array( array( 'child_task_id' => 'child-1' ), array() ), 0, true ),
			'snapshot handle'      => array( array( array(), array( 'pre_snapshot_task_id' => 'snapshot-1' ) ), 1, true ),
			'lease owner'          => array( array( array( 'locked_by' => 'worker-1' ), array() ), 0, true ),
			'lease deadline'       => array( array( array( 'locked_until' => 123 ), array() ), 0, true ),
			'negative lease'       => array( array( array( 'locked_until' => -1 ), array() ), 1, true ),
			'transfer progress'    => array( array( array( 'checkpoint' => '{"transfer":{"offset":12}}' ), array() ), 0, true ),
			'resume status'        => array( array( array( 'checkpoint' => '{"resume_status":"staging"}' ), array() ), 0, true ),
			'failure transfer'     => array( array( array( 'checkpoint' => '{"last_failure_transfer":[]}' ), array() ), 0, true ),
			'non-array checkpoint' => array( array( array( 'checkpoint' => 'null' ), array() ), 0, true ),
			'empty checkpoint'     => array( array( array( 'checkpoint' => '' ), array() ), 0, true ),
			'invalid checkpoint'   => array( array( array( 'checkpoint' => 'invalid' ), array() ), 0, true ),
			'two progressed'       => array( array( array( 'status' => 'running' ), array( 'child_task_id' => 'child-2' ), array() ), null, false ),
		);
	}

	/**
	 * Only pristine duplicates may be cancelled by the migration.
	 *
	 * @dataProvider restore_repair_matrix
	 * @param array    $fixtures Active row overrides, in insertion order.
	 * @param int|null $keep     Surviving offset.
	 * @param bool     $repaired Expected repair result.
	 */
	public function test_restore_site_repair_matrix( $fixtures, $keep, $repaired ) {
		global $wpdb;
		$table    = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$ids      = array();
		$logger   = MainWP_Logger::instance();
		$priority = new \ReflectionProperty( $logger, 'logPriority' );
		$priority->setAccessible( true );
		$previous = $priority->getValue( $logger );
		$priority->setValue( $logger, MainWP_Logger::DISABLED );
		$messages = array();
		$capture  = static function ( $text ) use ( &$messages ) {
			$messages[] = $text;
		};
		add_filter( 'mainwp_log_do_to_db', '__return_true' );
		add_action( 'mainwp_before_log_data', $capture );
		try {
			$wpdb->query( "ALTER TABLE {$table} DROP INDEX uniq_active_site" ); // phpcs:ignore WordPress.DB -- test fixture.
			foreach ( $fixtures as $fixture ) {
				$this->assertSame(
					1,
					$wpdb->insert(
						$table,
						array_merge(
							array(
								'site_id'     => 889901,
								'snapshot_id' => 42,
								'status'      => 'pending',
								'checkpoint'  => wp_json_encode(
									array(
										'restore_id' => 'repair01',
										'sub_type'   => 'db',
										'plan'       => array( array( 'snapshot_id' => 42 ) ),
										'options'    => array(
											'pre_snapshot' => false,
											'file_rollback' => false,
											'preserve_orders' => false,
											'woo_order_since' => 0,
										),
										'transfer'   => array(),
									)
								),
							),
							$fixture
						)
					)
				); // phpcs:ignore WordPress.DB -- test fixture.
				$ids[] = (int) $wpdb->insert_id;
			}
			$before = $wpdb->get_results( "SELECT * FROM {$table} WHERE site_id = 889901 ORDER BY id", ARRAY_A ); // phpcs:ignore WordPress.DB -- test probe.
			delete_site_option( MainWP_Install::BACKUP_SITE_UNIQ_KEYS_REPAIR_PENDING );
			update_site_option( MainWP_Install::BACKUP_SITE_UNIQ_KEYS_REPAIR_REASONS, array( 'stale' ) );
			$this->assertSame( $repaired, MainWP_Install::instance()->repair_backup_site_unique_keys() );
			$this->assertSame( $repaired, $this->index_exists( $table, 'uniq_active_site' ) );
			if ( ! $repaired ) {
				$recorded = get_site_option( MainWP_Install::BACKUP_SITE_UNIQ_KEYS_REPAIR_REASONS );
				$this->assertSame( array( $table ), array_keys( $recorded ) );
				$this->assertSame( array( array( 'code' => 'progressed_duplicates' ) ), $recorded[ $table ]['reasons'] );
				$this->assertSame( 1, $recorded[ $table ]['site_count'] );
				$this->assertSame(
					array(
						array(
							'site_id'   => 889901,
							'job_ids'   => array_reverse( array_slice( $ids, 0, 2 ) ),
							'job_count' => 2,
						),
					),
					$recorded[ $table ]['sites']
				);
				$this->assertSame( 1, $recorded[ $table ]['attempts'] );
				$this->assertGreaterThan( 0, $recorded[ $table ]['first_seen'] );
				$this->assertSame( array(), $messages );
				$priority->setValue( $logger, MainWP_Logger::WARNING );
				$this->assertFalse( MainWP_Install::instance()->repair_backup_site_unique_keys() );
				$this->assertContains( sprintf( 'Backup identity repair deferred for %s: blocked by progressed duplicate jobs; site_id=889901; job_ids=%s.', $table, implode( ',', array_reverse( array_slice( $ids, 0, 2 ) ) ) ), $messages );
				$this->assertGreaterThan( 0, (int) get_site_option( MainWP_Install::BACKUP_SITE_UNIQ_KEYS_REPAIR_PENDING ) );
				$this->assertSame( $before, $wpdb->get_results( "SELECT * FROM {$table} WHERE site_id = 889901 ORDER BY id", ARRAY_A ) ); // phpcs:ignore WordPress.DB -- test probe.
				return;
			}
			$this->assertFalse( get_site_option( MainWP_Install::BACKUP_SITE_UNIQ_KEYS_REPAIR_REASONS ) );
			$this->assertFalse( get_site_option( MainWP_Install::BACKUP_SITE_UNIQ_KEYS_REPAIR_PENDING ) );
			foreach ( $ids as $offset => $id ) {
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB -- test probe.
				if ( $offset === $keep ) {
					$this->assertSame( $before[ $offset ]['status'], $row->status );
					$this->assertSame( 889901, (int) $row->active_site_id );
					foreach ( array( 'checkpoint', 'child_task_id', 'pre_snapshot_task_id', 'locked_by', 'locked_until' ) as $column ) {
						$this->assertSame( $before[ $offset ][ $column ], $row->$column );
					}
				} else {
					$this->assertSame( 'cancelled', $row->status );
					$this->assertNull( $row->active_site_id );
					$this->assertSame( '', $row->locked_by );
					$this->assertSame( 0, (int) $row->locked_until );
					$this->assertSame( 'Superseded by the active restore uniqueness repair.', $row->last_error );
				}
			}
		} finally {
			remove_action( 'mainwp_before_log_data', $capture );
			remove_filter( 'mainwp_log_do_to_db', '__return_true' );
			$priority->setValue( $logger, $previous );
			foreach ( $messages as $message ) {
				$wpdb->delete( MainWP_DB::instance()->get_table_name( 'action_log' ), array( 'log_content' => $message ) ); // phpcs:ignore WordPress.DB -- test cleanup.
				$wpdb->delete( MainWP_DB::instance()->get_table_name( 'action_log' ), array( 'log_content' => 'CRON :: ' . $message ) ); // phpcs:ignore WordPress.DB -- test cleanup.
			}
			$wpdb->query( "DELETE FROM {$table} WHERE site_id = 889901" ); // phpcs:ignore WordPress.DB -- test cleanup.
			MainWP_Install::instance()->repair_backup_site_unique_keys();
			self::commit_transaction();
		}
	}

	/**
	 * Same-name keys must have exactly the required definition.
	 *
	 * @return array Table suffix, key name, and foreign definition.
	 */
	public static function foreign_site_indexes() {
		return array(
			'manual nonunique'  => array( 'wp_backup', 'uniq_manual_site', 'INDEX uniq_manual_site (manual_site_id)' ),
			'manual wrong'      => array( 'wp_backup', 'uniq_manual_site', 'UNIQUE INDEX uniq_manual_site (id)' ),
			'manual composite'  => array( 'wp_backup', 'uniq_manual_site', 'UNIQUE INDEX uniq_manual_site (manual_site_id, id)' ),
			'restore nonunique' => array( 'wp_backup_restore_job', 'uniq_active_site', 'INDEX uniq_active_site (active_site_id)' ),
			'restore wrong'     => array( 'wp_backup_restore_job', 'uniq_active_site', 'UNIQUE INDEX uniq_active_site (id)' ),
			'restore composite' => array( 'wp_backup_restore_job', 'uniq_active_site', 'UNIQUE INDEX uniq_active_site (active_site_id, id)' ),
		);
	}

	/**
	 * A foreign index is preserved and leaves the retry marker armed.
	 *
	 * @dataProvider foreign_site_indexes
	 * @param string $suffix     Table suffix.
	 * @param string $key        Index name.
	 * @param string $definition Foreign index SQL.
	 */
	public function test_site_identity_repair_rejects_foreign_index( $suffix, $key, $definition ) {
		global $wpdb;
		$table    = MainWP_DB::instance()->get_table_name( $suffix );
		$logger   = MainWP_Logger::instance();
		$priority = new \ReflectionProperty( $logger, 'logPriority' );
		$priority->setAccessible( true );
		$previous = $priority->getValue( $logger );
		$priority->setValue( $logger, MainWP_Logger::DISABLED );
		$messages = array();
		$capture  = static function ( $text ) use ( &$messages ) {
			$messages[] = $text;
		};
		add_filter( 'mainwp_log_do_to_db', '__return_true' );
		add_action( 'mainwp_before_log_data', $capture );
		try {
			$this->assertNotFalse( $wpdb->query( "ALTER TABLE {$table} DROP INDEX {$key}, ADD {$definition}" ) ); // phpcs:ignore WordPress.DB -- test fixture.
			$before = $wpdb->get_results( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $key ), ARRAY_A ); // phpcs:ignore WordPress.DB -- test probe.
			delete_site_option( MainWP_Install::BACKUP_SITE_UNIQ_KEYS_REPAIR_PENDING );
			$this->assertFalse( MainWP_Install::instance()->repair_backup_site_unique_keys() );
			$recorded = get_site_option( MainWP_Install::BACKUP_SITE_UNIQ_KEYS_REPAIR_REASONS );
			$this->assertSame( array( $table ), array_keys( $recorded ) );
			$this->assertSame( array( array( 'code' => 'malformed_index' ) ), $recorded[ $table ]['reasons'] );
			$this->assertArrayNotHasKey( 'reason', $recorded[ $table ] );
			$this->assertArrayNotHasKey( 'error', $recorded[ $table ] );
			$this->assertSame( array(), $messages );
			$priority->setValue( $logger, MainWP_Logger::WARNING );
			$this->assertFalse( MainWP_Install::instance()->repair_backup_site_unique_keys() );
			$this->assertContains( sprintf( 'Backup identity repair deferred for %s: malformed same-name index %s.', $table, $key ), $messages );
			$this->assertGreaterThan( 0, (int) get_site_option( MainWP_Install::BACKUP_SITE_UNIQ_KEYS_REPAIR_PENDING ) );
			$after = $wpdb->get_results( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $key ), ARRAY_A ); // phpcs:ignore WordPress.DB -- test probe.
			$this->assertSame( array_column( $before, 'Column_name' ), array_column( $after, 'Column_name' ) );
			$this->assertSame( array_column( $before, 'Non_unique' ), array_column( $after, 'Non_unique' ) );
		} finally {
			remove_action( 'mainwp_before_log_data', $capture );
			remove_filter( 'mainwp_log_do_to_db', '__return_true' );
			$priority->setValue( $logger, $previous );
			foreach ( $messages as $message ) {
				$wpdb->delete( MainWP_DB::instance()->get_table_name( 'action_log' ), array( 'log_content' => $message ) ); // phpcs:ignore WordPress.DB -- test cleanup.
				$wpdb->delete( MainWP_DB::instance()->get_table_name( 'action_log' ), array( 'log_content' => 'CRON :: ' . $message ) ); // phpcs:ignore WordPress.DB -- test cleanup.
			}
			$wpdb->query( "ALTER TABLE {$table} DROP INDEX {$key}" ); // phpcs:ignore WordPress.DB -- test cleanup.
			MainWP_Install::instance()->repair_backup_site_unique_keys();
			self::commit_transaction();
		}
	}

	/**
	 * A failed ADD reports its database error; an absent key repaired normally stays quiet.
	 *
	 * @dataProvider site_identity_add_results
	 * @param bool $fail Whether to fail the ADD statement.
	 */
	public function test_site_identity_repair_add_unique_diagnostics( $fail ) {
		global $wpdb;
		$table    = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$logger   = MainWP_Logger::instance();
		$priority = new \ReflectionProperty( $logger, 'logPriority' );
		$priority->setAccessible( true );
		$previous = $priority->getValue( $logger );
		$priority->setValue( $logger, MainWP_Logger::WARNING );
		$messages = array();
		$capture  = static function ( $text ) use ( &$messages ) {
			$messages[] = $text;
		};
		$block    = static function ( $query ) use ( $table, $fail ) {
			if ( $fail && 0 === strpos( $query, "ALTER TABLE {$table} ADD UNIQUE KEY uniq_active_site" ) ) {
				return 'SELECT mainwp_site_repair_forced_failure';
			}
			return $query;
		};
		add_filter( 'mainwp_log_do_to_db', '__return_true' );
		add_action( 'mainwp_before_log_data', $capture );
		try {
			$this->assertNotFalse( $wpdb->query( "ALTER TABLE {$table} DROP INDEX uniq_active_site" ) ); // phpcs:ignore WordPress.DB -- test fixture.
			delete_site_option( MainWP_Install::BACKUP_SITE_UNIQ_KEYS_REPAIR_REASONS );
			add_filter( 'query', $block );
			$this->assertSame( ! $fail, MainWP_Install::instance()->repair_backup_site_unique_keys() );
			$this->assertSame( ! $fail, $this->index_exists( $table, 'uniq_active_site' ) );
			if ( $fail ) {
				$recorded = get_site_option( MainWP_Install::BACKUP_SITE_UNIQ_KEYS_REPAIR_REASONS );
				$this->assertSame( array( $table ), array_keys( $recorded ) );
				$this->assertSame( $table, $recorded[ $table ]['table'] );
				$this->assertSame( 'add_unique_failed', $recorded[ $table ]['reasons'][0]['code'] );
				// The engine's own message is what makes the deferral actionable.
				$this->assertStringContainsString( 'mainwp_site_repair_forced_failure', $recorded[ $table ]['reasons'][0]['error'] );
				$this->assertCount( 1, $messages );
				$this->assertStringContainsString( "Backup identity repair deferred for {$table}: failed to add unique key uniq_active_site:", $messages[0] );
				$this->assertStringContainsString( 'mainwp_site_repair_forced_failure', $messages[0] );
			} else {
				$this->assertFalse( get_site_option( MainWP_Install::BACKUP_SITE_UNIQ_KEYS_REPAIR_REASONS ) );
				$this->assertSame( array(), $messages );
			}
			remove_filter( 'query', $block );
			$this->assertTrue( MainWP_Install::instance()->repair_backup_site_unique_keys() );
			$this->assertFalse( get_site_option( MainWP_Install::BACKUP_SITE_UNIQ_KEYS_REPAIR_REASONS ) );
			$this->assertFalse( get_site_option( MainWP_Install::BACKUP_SITE_UNIQ_KEYS_REPAIR_PENDING ) );
		} finally {
			remove_filter( 'query', $block );
			remove_action( 'mainwp_before_log_data', $capture );
			remove_filter( 'mainwp_log_do_to_db', '__return_true' );
			$priority->setValue( $logger, $previous );
			foreach ( $messages as $message ) {
				$wpdb->delete( MainWP_DB::instance()->get_table_name( 'action_log' ), array( 'log_content' => $message ) ); // phpcs:ignore WordPress.DB -- test cleanup.
				$wpdb->delete( MainWP_DB::instance()->get_table_name( 'action_log' ), array( 'log_content' => 'CRON :: ' . $message ) ); // phpcs:ignore WordPress.DB -- test cleanup.
			}
			MainWP_Install::instance()->repair_backup_site_unique_keys();
			self::commit_transaction();
		}
	}

	/**
	 * A concurrent key creation must not leave a successful repair deferred.
	 */
	public function test_site_identity_repair_accepts_concurrently_created_unique_key() {
		global $wpdb;
		$table  = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$manual = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$probes = 0;
		$adds   = 0;
		$block  = static function ( $query ) use ( $table, $manual, &$probes, &$adds ) {
			// The unrelated manual self-joins cannot run against harness temporary tables.
			if ( str_starts_with( $query, "UPDATE {$manual} task " ) ) {
				return 'SELECT 1';
			}
			if ( str_starts_with( $query, "SHOW INDEX FROM {$table} WHERE Key_name = 'uniq_active_site'" ) ) {
				++$probes;
				if ( 1 === $probes ) {
					return "SHOW INDEX FROM {$table} WHERE Key_name = 'mainwp_missing_fixture_key'";
				}
			}
			if ( str_starts_with( $query, "ALTER TABLE {$table} ADD UNIQUE KEY uniq_active_site" ) ) {
				++$adds;
			}
			return $query;
		};
		try {
			$this->assertTrue( $this->index_exists( $table, 'uniq_active_site' ) );
			update_site_option( MainWP_Install::BACKUP_SITE_UNIQ_KEYS_REPAIR_PENDING, time() );
			delete_site_option( MainWP_Install::BACKUP_SITE_UNIQ_KEYS_REPAIR_REASONS );
			add_filter( 'query', $block );
			$this->assertTrue( MainWP_Install::instance()->repair_backup_site_unique_keys() );
			$this->assertSame( 1, $adds );
			$this->assertSame( 2, $probes );
			$this->assertFalse( get_site_option( MainWP_Install::BACKUP_SITE_UNIQ_KEYS_REPAIR_REASONS ) );
			$this->assertFalse( get_site_option( MainWP_Install::BACKUP_SITE_UNIQ_KEYS_REPAIR_PENDING ) );
			remove_filter( 'query', $block );
			$index = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Key_name = 'uniq_active_site'" ); // phpcs:ignore WordPress.DB -- test probe.
			$this->assertCount( 1, $index );
			$this->assertSame( 'active_site_id', $index[0]->Column_name );
			$this->assertSame( 0, (int) $index[0]->Non_unique );
			$this->assertSame( 1, (int) $index[0]->Seq_in_index );
		} finally {
			remove_filter( 'query', $block );
			delete_site_option( MainWP_Install::BACKUP_SITE_UNIQ_KEYS_REPAIR_REASONS );
			delete_site_option( MainWP_Install::BACKUP_SITE_UNIQ_KEYS_REPAIR_PENDING );
			self::commit_transaction();
		}
	}

	/**
	 * Repeated cancellation failures must not grow the stored diagnostic without bounds.
	 */
	public function test_site_identity_repair_bounds_pristine_cancel_failures() {
		global $wpdb;
		$table    = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$manual   = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$ids      = array();
		$failures = 0;
		$block    = static function ( $query ) use ( $table, $manual, &$failures ) {
			// The unrelated manual self-joins cannot run against harness temporary tables.
			if ( str_starts_with( $query, "UPDATE {$manual} task " ) ) {
				return 'SELECT 1';
			}
			if ( str_starts_with( $query, "UPDATE {$table} SET status = 'cancelled'" ) ) {
				++$failures;
				return 1 === $failures ? 'SELECT mainwp_first_cancel_failure' : 'SELECT mainwp_later_cancel_failure';
			}
			return $query;
		};
		try {
			$this->assertNotFalse( $wpdb->query( "ALTER TABLE {$table} DROP INDEX uniq_active_site" ) ); // phpcs:ignore WordPress.DB -- test fixture.
			for ( $site = 889940; $site < 889942; ++$site ) {
				for ( $job = 0; $job < 13; ++$job ) {
					$this->assertSame(
						1,
						$wpdb->insert(
							$table,
							array(
								'site_id'     => $site,
								'snapshot_id' => 42,
								'status'      => 'pending',
								'checkpoint'  => '[]',
							)
						)
					); // phpcs:ignore WordPress.DB -- test fixture.
					$ids[ $site ][] = (int) $wpdb->insert_id;
				}
			}
			delete_site_option( MainWP_Install::BACKUP_SITE_UNIQ_KEYS_REPAIR_REASONS );
			add_filter( 'query', $block );
			$this->assertFalse( MainWP_Install::instance()->repair_backup_site_unique_keys() );
			$this->assertSame( 24, $failures );
			$recorded = get_site_option( MainWP_Install::BACKUP_SITE_UNIQ_KEYS_REPAIR_REASONS );
			$this->assertSame( array( $table ), array_keys( $recorded ) );
			$this->assertCount( 1, $recorded[ $table ]['reasons'] );
			$reason = $recorded[ $table ]['reasons'][0];
			$this->assertSame( 'pristine_cancel_failed', $reason['code'] );
			$this->assertSame( 24, $reason['job_count'] );
			$this->assertSame(
				array_slice( array_merge( array_slice( array_reverse( $ids[889941] ), 1 ), array_slice( array_reverse( $ids[889940] ), 1 ) ), 0, 20 ),
				$reason['job_ids']
			);
			$this->assertStringContainsString( 'mainwp_first_cancel_failure', $reason['error'] );
			$this->assertStringNotContainsString( 'mainwp_later_cancel_failure', $reason['error'] );
			$this->assertGreaterThan( 0, (int) get_site_option( MainWP_Install::BACKUP_SITE_UNIQ_KEYS_REPAIR_PENDING ) );
		} finally {
			remove_filter( 'query', $block );
			foreach ( array( 889940, 889941 ) as $site ) {
				$wpdb->delete( $table, array( 'site_id' => $site ) ); // phpcs:ignore WordPress.DB -- DDL can commit fixture rows.
			}
			if ( ! $this->index_exists( $table, 'uniq_active_site' ) ) {
				$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY uniq_active_site (active_site_id)" ); // phpcs:ignore WordPress.DB -- test cleanup.
			}
			delete_site_option( MainWP_Install::BACKUP_SITE_UNIQ_KEYS_REPAIR_REASONS );
			delete_site_option( MainWP_Install::BACKUP_SITE_UNIQ_KEYS_REPAIR_PENDING );
			self::commit_transaction();
		}
	}

	/**
	 * A failed ADD UNIQUE must be diagnosed; an absent key on a normal first
	 * run must stay silent.
	 *
	 * @return array ADD UNIQUE failure and normal absent-key cases.
	 */
	public static function site_identity_add_results() {
		return array(
			'failed ADD' => array( true ),
			'absent key' => array( false ),
		);
	}

	/**
	 * Database failures must remain visible when later queries clear last_error.
	 *
	 * @dataProvider site_identity_statement_failures
	 * @param string $suffix    Table suffix.
	 * @param string $code      Reason code.
	 * @param string $statement Failing SQL prefix, with a table placeholder.
	 * @param bool   $no_change Whether cancellation loses its row predicate.
	 */
	public function test_site_identity_repair_statement_diagnostics( $suffix, $code, $statement, $no_change = false ) {
		global $wpdb;
		$table    = MainWP_DB::instance()->get_table_name( $suffix );
		$restore  = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$column   = 'wp_backup' === $suffix ? 'manual_site_id' : 'active_site_id';
		$logger   = MainWP_Logger::instance();
		$priority = new \ReflectionProperty( $logger, 'logPriority' );
		$priority->setAccessible( true );
		$previous = $priority->getValue( $logger );
		$priority->setValue( $logger, MainWP_Logger::WARNING );
		$messages = array();
		$capture  = static function ( $text ) use ( &$messages ) {
			$messages[] = $text;
		};
		$block    = static function ( $query ) use ( $table, $statement, $no_change ) {
			if ( str_starts_with( $query, sprintf( $statement, $table ) ) ) {
				return $no_change ? "UPDATE {$table} SET status = 'cancelled' WHERE 1 = 0" : 'SELECT mainwp_site_statement_forced_failure';
			}
			return $query;
		};
		add_filter( 'mainwp_log_do_to_db', '__return_true' );
		add_action( 'mainwp_before_log_data', $capture );
		try {
			delete_site_option( MainWP_Install::BACKUP_SITE_UNIQ_KEYS_REPAIR_REASONS );
			if ( 'add_column_failed' === $code ) {
				$this->assertNotFalse( $wpdb->query( "ALTER TABLE {$table} DROP COLUMN {$column}" ) ); // phpcs:ignore WordPress.DB -- test fixture.
			}
			if ( 'pristine_cancel_failed' === $code ) {
				for ( $i = 0; $i < 2; ++$i ) {
					$this->assertSame(
						1,
						$wpdb->insert(
							$restore,
							array(
								'site_id'     => 889902,
								'snapshot_id' => 42,
								'status'      => 'pending',
								'checkpoint'  => '[]',
							)
						)
					); // phpcs:ignore WordPress.DB -- test fixture.
				}
			}
			add_filter( 'query', $block );
			$this->assertFalse( MainWP_Install::instance()->repair_backup_site_unique_keys() );
			$recorded = get_site_option( MainWP_Install::BACKUP_SITE_UNIQ_KEYS_REPAIR_REASONS );
			$this->assertSame( array( $table ), array_keys( $recorded ) );
			$this->assertCount( 1, $recorded[ $table ]['reasons'] );
			$this->assertSame( $code, $recorded[ $table ]['reasons'][0]['code'] );
			if ( 'pristine_cancel_failed' === $code ) {
				$this->assertSame( 1, $recorded[ $table ]['reasons'][0]['job_count'] );
				$this->assertCount( 1, $recorded[ $table ]['reasons'][0]['job_ids'] );
			}
			$this->assertArrayNotHasKey( 'reason', $recorded[ $table ] );
			$this->assertArrayNotHasKey( 'error', $recorded[ $table ] );
			$this->assertSame( 1, $recorded[ $table ]['attempts'] );
			$this->assertGreaterThan( 0, $recorded[ $table ]['first_seen'] );
			$this->assertGreaterThan( 0, (int) get_site_option( MainWP_Install::BACKUP_SITE_UNIQ_KEYS_REPAIR_PENDING ) );
			$this->assertCount( 1, $messages );
			$this->assertStringContainsString( "Backup identity repair deferred for {$table}:", $messages[0] );
			if ( $no_change ) {
				$this->assertArrayNotHasKey( 'error', $recorded[ $table ]['reasons'][0] );
			} else {
				$this->assertStringContainsString( 'mainwp_site_statement_forced_failure', $recorded[ $table ]['reasons'][0]['error'] );
				$this->assertStringContainsString( 'mainwp_site_statement_forced_failure', $messages[0] );
			}
			remove_filter( 'query', $block );
			$this->assertTrue( MainWP_Install::instance()->repair_backup_site_unique_keys() );
			$this->assertFalse( get_site_option( MainWP_Install::BACKUP_SITE_UNIQ_KEYS_REPAIR_REASONS ) );
		} finally {
			remove_filter( 'query', $block );
			$wpdb->delete( $restore, array( 'site_id' => 889902 ) ); // phpcs:ignore WordPress.DB -- DDL can commit fixture rows.
			MainWP_Install::instance()->repair_backup_site_unique_keys();
			remove_action( 'mainwp_before_log_data', $capture );
			remove_filter( 'mainwp_log_do_to_db', '__return_true' );
			$priority->setValue( $logger, $previous );
			foreach ( $messages as $message ) {
				$wpdb->delete( MainWP_DB::instance()->get_table_name( 'action_log' ), array( 'log_content' => $message ) ); // phpcs:ignore WordPress.DB -- test cleanup.
				$wpdb->delete( MainWP_DB::instance()->get_table_name( 'action_log' ), array( 'log_content' => 'CRON :: ' . $message ) ); // phpcs:ignore WordPress.DB -- test cleanup.
			}
			self::commit_transaction();
		}
	}

	/**
	 * Failure paths that previously armed the marker without a reason.
	 *
	 * @return array Table, code, SQL prefix, and optional lost cancellation predicate.
	 */
	public static function site_identity_statement_failures() {
		return array(
			'manual column'    => array( 'wp_backup', 'add_column_failed', 'ALTER TABLE %s ADD COLUMN manual_site_id' ),
			'restore column'   => array( 'wp_backup_restore_job', 'add_column_failed', 'ALTER TABLE %s ADD COLUMN active_site_id' ),
			'active select'    => array( 'wp_backup_restore_job', 'active_rows_select_failed', 'SELECT * FROM %s WHERE status IN' ),
			'manual dedupe'    => array( 'wp_backup', 'manual_dedupe_failed', 'UPDATE %s task INNER JOIN' ),
			'manual backfill'  => array( 'wp_backup', 'manual_backfill_failed', 'UPDATE %s task LEFT JOIN' ),
			'pristine cancel'  => array( 'wp_backup_restore_job', 'pristine_cancel_failed', "UPDATE %s SET status = 'cancelled'" ),
			'cancel race'      => array( 'wp_backup_restore_job', 'pristine_cancel_failed', "UPDATE %s SET status = 'cancelled'", true ),
			'restore backfill' => array( 'wp_backup_restore_job', 'restore_backfill_failed', 'UPDATE %s SET active_site_id = CASE' ),
		);
	}

	/**
	 * Retry history belongs to each table even when its blocking condition changes.
	 */
	public function test_site_identity_repair_merges_retry_history_per_table() {
		global $wpdb;
		$backup   = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$restore  = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$attempt  = 1;
		$logger   = MainWP_Logger::instance();
		$priority = new \ReflectionProperty( $logger, 'logPriority' );
		$priority->setAccessible( true );
		$previous = $priority->getValue( $logger );
		$priority->setValue( $logger, MainWP_Logger::WARNING );
		$messages = array();
		$capture  = static function ( $text ) use ( &$messages ) {
			$messages[] = $text;
		};
		$block    = static function ( $query ) use ( $backup, $restore, &$attempt ) {
			if ( ( 1 === $attempt && str_starts_with( $query, "UPDATE {$backup} task INNER JOIN" ) ) ||
				( 1 === $attempt && str_starts_with( $query, "SELECT * FROM {$restore} WHERE status IN" ) ) ||
				( 2 === $attempt && str_starts_with( $query, "UPDATE {$restore} SET active_site_id = CASE" ) ) ) {
				return 'SELECT mainwp_site_retry_forced_failure';
			}
			return $query;
		};
		add_filter( 'mainwp_log_do_to_db', '__return_true' );
		add_action( 'mainwp_before_log_data', $capture );
		try {
			delete_site_option( MainWP_Install::BACKUP_SITE_UNIQ_KEYS_REPAIR_REASONS );
			add_filter( 'query', $block );
			$this->assertFalse( MainWP_Install::instance()->repair_backup_site_unique_keys() );
			$first = get_site_option( MainWP_Install::BACKUP_SITE_UNIQ_KEYS_REPAIR_REASONS );
			$this->assertSame( array( $backup, $restore ), array_keys( $first ) );
			$this->assertSame( 'manual_dedupe_failed', $first[ $backup ]['reasons'][0]['code'] );
			$this->assertSame( 'active_rows_select_failed', $first[ $restore ]['reasons'][0]['code'] );
			$this->assertSame( 1, $first[ $restore ]['attempts'] );
			$first[ $restore ]['first_seen'] = time() - HOUR_IN_SECONDS;
			update_site_option( MainWP_Install::BACKUP_SITE_UNIQ_KEYS_REPAIR_REASONS, $first );
			$attempt = 2;
			$this->assertFalse( MainWP_Install::instance()->repair_backup_site_unique_keys() );
			$second = get_site_option( MainWP_Install::BACKUP_SITE_UNIQ_KEYS_REPAIR_REASONS );
			$this->assertSame( array( $restore ), array_keys( $second ) );
			$this->assertSame( $first[ $restore ]['first_seen'], $second[ $restore ]['first_seen'] );
			$this->assertSame( 2, $second[ $restore ]['attempts'] );
			$this->assertCount( 1, $second[ $restore ]['reasons'] );
			$this->assertSame( 'restore_backfill_failed', $second[ $restore ]['reasons'][0]['code'] );
			$this->assertStringContainsString( 'mainwp_site_retry_forced_failure', $second[ $restore ]['reasons'][0]['error'] );
			remove_filter( 'query', $block );
			$this->assertTrue( MainWP_Install::instance()->repair_backup_site_unique_keys() );
			$this->assertFalse( get_site_option( MainWP_Install::BACKUP_SITE_UNIQ_KEYS_REPAIR_REASONS ) );
			$this->assertFalse( get_site_option( MainWP_Install::BACKUP_SITE_UNIQ_KEYS_REPAIR_PENDING ) );
		} finally {
			remove_filter( 'query', $block );
			MainWP_Install::instance()->repair_backup_site_unique_keys();
			remove_action( 'mainwp_before_log_data', $capture );
			remove_filter( 'mainwp_log_do_to_db', '__return_true' );
			$priority->setValue( $logger, $previous );
			foreach ( $messages as $message ) {
				$wpdb->delete( MainWP_DB::instance()->get_table_name( 'action_log' ), array( 'log_content' => $message ) ); // phpcs:ignore WordPress.DB -- test cleanup.
				$wpdb->delete( MainWP_DB::instance()->get_table_name( 'action_log' ), array( 'log_content' => 'CRON :: ' . $message ) ); // phpcs:ignore WordPress.DB -- test cleanup.
			}
			self::commit_transaction();
		}
	}

	/**
	 * Row deferrals must not hide bad indexes or grow the durable sample without bounds.
	 *
	 * @dataProvider site_identity_duplicate_samples
	 * @param int  $site_count Number of duplicate sites.
	 * @param int  $job_count  Progressed jobs per site.
	 * @param bool $malformed Whether a foreign same-name index exists.
	 */
	public function test_site_identity_repair_duplicate_diagnostics( $site_count, $job_count, $malformed ) {
		global $wpdb;
		$table    = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$ids      = array();
		$adds     = 0;
		$logger   = MainWP_Logger::instance();
		$priority = new \ReflectionProperty( $logger, 'logPriority' );
		$priority->setAccessible( true );
		$previous = $priority->getValue( $logger );
		$priority->setValue( $logger, MainWP_Logger::WARNING );
		$messages = array();
		$capture  = static function ( $text ) use ( &$messages ) {
			$messages[] = $text;
		};
		$probe    = static function ( $query ) use ( $table, &$adds ) {
			if ( str_starts_with( $query, "ALTER TABLE {$table} ADD UNIQUE KEY uniq_active_site" ) ) {
				++$adds;
			}
			return $query;
		};
		add_filter( 'mainwp_log_do_to_db', '__return_true' );
		add_action( 'mainwp_before_log_data', $capture );
		try {
			$this->assertNotFalse( $wpdb->query( "ALTER TABLE {$table} DROP INDEX uniq_active_site" ) ); // phpcs:ignore WordPress.DB -- test fixture.
			if ( $malformed ) {
				$this->assertNotFalse( $wpdb->query( "ALTER TABLE {$table} ADD INDEX uniq_active_site (active_site_id)" ) ); // phpcs:ignore WordPress.DB -- test fixture.
			}
			for ( $site = 889910; $site < 889910 + $site_count; ++$site ) {
				for ( $job = 0; $job < $job_count; ++$job ) {
					$this->assertSame(
						1,
						$wpdb->insert(
							$table,
							array(
								'site_id'     => $site,
								'snapshot_id' => 42,
								'status'      => 'running',
								'checkpoint'  => '[]',
							)
						)
					); // phpcs:ignore WordPress.DB -- test fixture.
					$ids[ $site ][] = (int) $wpdb->insert_id;
				}
			}
			delete_site_option( MainWP_Install::BACKUP_SITE_UNIQ_KEYS_REPAIR_REASONS );
			add_filter( 'query', $probe );
			$this->assertFalse( MainWP_Install::instance()->repair_backup_site_unique_keys() );
			$this->assertSame( 0, $adds );
			$recorded = get_site_option( MainWP_Install::BACKUP_SITE_UNIQ_KEYS_REPAIR_REASONS );
			$this->assertSame( array( $table ), array_keys( $recorded ) );
			$codes = array_column( $recorded[ $table ]['reasons'], 'code' );
			sort( $codes );
			$this->assertSame( $malformed ? array( 'malformed_index', 'progressed_duplicates' ) : array( 'progressed_duplicates' ), $codes );
			foreach ( $recorded[ $table ]['reasons'] as $reason ) {
				$this->assertArrayNotHasKey( 'error', $reason );
			}
			$this->assertSame( $site_count, $recorded[ $table ]['site_count'] );
			$this->assertCount( min( 25, $site_count ), $recorded[ $table ]['sites'] );
			foreach ( $recorded[ $table ]['sites'] as $sample ) {
				$this->assertSame( $job_count, $sample['job_count'] );
				$this->assertSame( array_slice( array_reverse( $ids[ $sample['site_id'] ] ), 0, 20 ), $sample['job_ids'] );
			}
			$this->assertCount( $site_count + (int) $malformed, $messages );
		} finally {
			remove_filter( 'query', $probe );
			foreach ( array_keys( $ids ) as $site ) {
				$wpdb->delete( $table, array( 'site_id' => $site ) ); // phpcs:ignore WordPress.DB -- DDL can commit fixture rows.
			}
			if ( $malformed && $this->index_exists( $table, 'uniq_active_site' ) ) {
				$wpdb->query( "ALTER TABLE {$table} DROP INDEX uniq_active_site" ); // phpcs:ignore WordPress.DB -- test cleanup.
			}
			MainWP_Install::instance()->repair_backup_site_unique_keys();
			remove_action( 'mainwp_before_log_data', $capture );
			remove_filter( 'mainwp_log_do_to_db', '__return_true' );
			$priority->setValue( $logger, $previous );
			foreach ( $messages as $message ) {
				$wpdb->delete( MainWP_DB::instance()->get_table_name( 'action_log' ), array( 'log_content' => $message ) ); // phpcs:ignore WordPress.DB -- test cleanup.
				$wpdb->delete( MainWP_DB::instance()->get_table_name( 'action_log' ), array( 'log_content' => 'CRON :: ' . $message ) ); // phpcs:ignore WordPress.DB -- test cleanup.
			}
			self::commit_transaction();
		}
	}

	/**
	 * Both sample limits apply independently of index diagnostics.
	 *
	 * @return array Site count, progressed job count, and malformed index flag.
	 */
	public static function site_identity_duplicate_samples() {
		return array(
			'duplicates and malformed index' => array( 1, 2, true ),
			'job sample cap'                 => array( 1, 25, false ),
			'site sample cap'                => array( 26, 2, false ),
		);
	}
}
