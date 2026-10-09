<?php
/**
 * MainWP Backup Retention Query Tests
 *
 * The query layer groups a site and task pair's snapshot rows into
 * restorable units for the policy engine: an incremental chain hangs
 * together under its base full, standalone full and db snapshots stand
 * alone, and a chain with a broken parent link is flagged incomplete.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Backup_Retention_Query;

/**
 * Class MainWP_Backup_Retention_Query_Test
 */
class MainWP_Backup_Retention_Query_Test extends \WP_UnitTestCase {

	/**
	 * Clean fixture rows.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wpdb;

		$snapshot_table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$wpdb->query( "DELETE FROM {$snapshot_table} WHERE site_id = 990001" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.

		$task_table = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$wpdb->query( "DELETE FROM {$task_table} WHERE name LIKE 'Retention query test%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.

		parent::tear_down();
	}

	/**
	 * Insert a snapshot row.
	 *
	 * @param array $fields Field overrides.
	 *
	 * @return int Snapshot id.
	 */
	private function insert_snapshot( array $fields = array() ) {
		global $wpdb;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			$table,
			array_merge(
				array(
					'task_id'       => 990001,
					'site_id'       => 990001,
					'type'          => 'full',
					'created'       => time(),
					'archives'      => wp_json_encode( array( array( 'file' => 'a.tar', 'size' => 1000, 'role' => 'full', 'path' => '/tmp/a.tar' ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
					'manifest_path' => '',
					'status'        => 'complete',
				),
				$fields
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Insert a backup task row.
	 *
	 * @param array $fields Field overrides.
	 *
	 * @return int Task id.
	 */
	private function insert_task( array $fields = array() ) {
		global $wpdb;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			$table,
			array_merge(
				array(
					'userid'            => 1,
					'name'              => 'Retention query test ' . uniqid(),
					'schedule'          => 'daily',
					'type'              => 'full',
					'exclude'           => '',
					'sites'             => '',
					'groups'            => '',
					'last'              => 0,
					'last_run'          => 0,
					'last_run_manually' => 0,
					'completed_sites'   => '',
					'completed'         => 0,
					'backup_errors'     => '',
					'subfolder'         => '',
					'filename'          => '',
					'paused'            => 0,
					'archiveFormat'     => 'global',
				),
				$fields
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * An incremental chain groups under its base as one complete unit,
	 * base first, with sizes summed.
	 *
	 * @return void
	 */
	public function test_chain_groups_under_base() {
		$now  = time();
		$base = $this->insert_snapshot( array( 'created' => $now - 300 ) );
		$inc1 = $this->insert_snapshot(
			array(
				'type'      => 'incremental',
				'parent_id' => $base,
				'created'   => $now - 200,
			)
		);
		$inc2 = $this->insert_snapshot(
			array(
				'type'      => 'incremental',
				'parent_id' => $inc1,
				'created'   => $now - 100,
			)
		);

		$units = MainWP_Backup_Retention_Query::instance()->get_units_for_site_task( 990001, 990001 );

		$this->assertCount( 1, $units );
		$this->assertTrue( $units[0]['is_complete'] );
		$this->assertSame( array( $base, $inc1, $inc2 ), array_map( 'intval', wp_list_pluck( $units[0]['snapshots'], 'id' ) ) );
		$this->assertSame( 3000, $units[0]['size_bytes'] );
		$this->assertSame( $now - 100, $units[0]['newest_created'] );
	}

	/**
	 * Standalone full and db snapshots are individual units; separate
	 * chains stay separate.
	 *
	 * @return void
	 */
	public function test_standalone_and_separate_chains() {
		$db_snap = $this->insert_snapshot( array( 'type' => 'db' ) );
		$full_a  = $this->insert_snapshot();
		$full_b  = $this->insert_snapshot();
		$inc_b   = $this->insert_snapshot(
			array(
				'type'      => 'incremental',
				'parent_id' => $full_b,
			)
		);

		$units = MainWP_Backup_Retention_Query::instance()->get_units_for_site_task( 990001, 990001 );

		$this->assertCount( 3, $units );

		$by_first = array();
		foreach ( $units as $unit ) {
			$by_first[ (int) $unit['snapshots'][0]->id ] = $unit;
		}

		$this->assertCount( 1, $by_first[ $db_snap ]['snapshots'] );
		$this->assertCount( 1, $by_first[ $full_a ]['snapshots'] );
		$this->assertSame( array( $full_b, $inc_b ), array_map( 'intval', wp_list_pluck( $by_first[ $full_b ]['snapshots'], 'id' ) ) );
	}

	/**
	 * A files-typed root snapshot is its own complete, prunable unit.
	 *
	 * @return void
	 */
	public function test_files_typed_root_is_complete_unit() {
		$files_snap = $this->insert_snapshot( array( 'type' => 'files' ) );

		$units = MainWP_Backup_Retention_Query::instance()->get_units_for_site_task( 990001, 990001 );

		$this->assertCount( 1, $units );
		$this->assertTrue( $units[0]['is_complete'] );
		$this->assertSame( array( $files_snap ), array_map( 'intval', wp_list_pluck( $units[0]['snapshots'], 'id' ) ) );
	}

	/**
	 * A chain whose parent link points at a missing row is incomplete.
	 *
	 * @return void
	 */
	public function test_broken_parent_marks_unit_incomplete() {
		$orphan = $this->insert_snapshot(
			array(
				'type'      => 'incremental',
				'parent_id' => 88888888,
			)
		);

		$units = MainWP_Backup_Retention_Query::instance()->get_units_for_site_task( 990001, 990001 );

		$this->assertCount( 1, $units );
		$this->assertFalse( $units[0]['is_complete'] );
		$this->assertSame( array( $orphan ), array_map( 'intval', wp_list_pluck( $units[0]['snapshots'], 'id' ) ) );
	}

	/**
	 * Pruned rows are invisible to the grouping.
	 *
	 * @return void
	 */
	public function test_pruned_rows_excluded() {
		$this->insert_snapshot( array( 'status' => 'pruned' ) );
		$live = $this->insert_snapshot();

		$units = MainWP_Backup_Retention_Query::instance()->get_units_for_site_task( 990001, 990001 );

		$this->assertCount( 1, $units );
		$this->assertSame( array( $live ), array_map( 'intval', wp_list_pluck( $units[0]['snapshots'], 'id' ) ) );
	}

	/**
	 * Only tasks with a non-zero policy turn up for the retention cron.
	 *
	 * @return void
	 */
	public function test_list_tasks_with_policy() {
		$with_count = $this->insert_task( array( 'retention_count' => 3 ) );
		$with_days  = $this->insert_task( array( 'retention_days' => 30 ) );
		$with_cap   = $this->insert_task( array( 'retention_storage_mb' => 500 ) );
		$without    = $this->insert_task();

		$ids = array_map( 'intval', wp_list_pluck( MainWP_Backup_Retention_Query::instance()->list_tasks_with_policy(), 'id' ) );

		$this->assertContains( $with_count, $ids );
		$this->assertContains( $with_days, $ids );
		$this->assertContains( $with_cap, $ids );
		$this->assertNotContains( $without, $ids );
	}

	/**
	 * Site ids for a task come back distinct.
	 *
	 * @return void
	 */
	public function test_list_site_ids_for_task_distinct() {
		$this->insert_snapshot( array( 'task_id' => 990002 ) );
		$this->insert_snapshot( array( 'task_id' => 990002 ) );

		$site_ids = MainWP_Backup_Retention_Query::instance()->list_site_ids_for_task( 990002 );

		$this->assertSame( array( 990001 ), array_map( 'intval', $site_ids ) );
	}
}
