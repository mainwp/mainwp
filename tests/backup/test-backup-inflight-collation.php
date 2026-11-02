<?php
/**
 * MainWP Backup In-flight Incremental Check Under Mixed Collations
 *
 * Installs upgraded from older releases can keep the legacy backup task and
 * progress tables in one collation while the snapshot table uses another.
 * The in-flight incremental check must still read correctly there, and a
 * failed read must keep snapshots without claiming a backup is running.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

require_once __DIR__ . '/trait-backup-private-root-fixture.php';

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Backup_Retention_Executor;
use MainWP\Dashboard\MainWP_Backup_Snapshots;

/**
 * Class MainWP_Backup_Inflight_Collation_Test
 *
 * @group backup
 */
class MainWP_Backup_Inflight_Collation_Test extends \WP_UnitTestCase {

	use Backup_Private_Root_Fixture;

	/**
	 * Site the fixtures belong to; no site row is needed.
	 *
	 * @var int
	 */
	private $site_id = 990133;

	/**
	 * Real incremental task id.
	 *
	 * @var int
	 */
	private $task_id = 0;

	/**
	 * Legacy table names mapped to their CREATE TABLE before the test changed them.
	 *
	 * @var array<string,string>
	 */
	private $original_tables = array();

	/**
	 * Created snapshot dirs.
	 *
	 * @var string[]
	 */
	private $fixture_dirs = array();

	/**
	 * Convert the legacy tables before the test transaction opens: ALTER TABLE
	 * commits implicitly, so it must not run while fixture rows are pending.
	 *
	 * @return void
	 */
	public function set_up() {
		global $wpdb;

		$snapshot = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$column   = $wpdb->get_row( $wpdb->prepare( 'SHOW FULL COLUMNS FROM %i LIKE %s', $snapshot, 'run_key' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture reads column metadata.
		$this->assertNotEmpty( $column->Collation, 'The snapshot run_key column must exist.' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MySQL column name.
		$snapshot_collation = (string) $column->Collation; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MySQL column name.
		$charset            = strtok( $snapshot_collation, '_' );
		$legacy_collation   = $charset . '_unicode_ci' === $snapshot_collation ? $charset . '_general_ci' : $charset . '_unicode_ci';

		foreach ( array( 'wp_backup', 'wp_backup_progress' ) as $name ) {
			$table                           = MainWP_DB::instance()->get_table_name( $name );
			$this->original_tables[ $table ] = $this->create_table_sql( $table );
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i CONVERT TO CHARACTER SET %s COLLATE %s', $table, $charset, $legacy_collation ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.QuotedSimplePlaceholder -- test fixture recreates an upgraded install's legacy collation.
			$this->assertSame( '', $wpdb->last_error, 'The legacy table collation must change.' );
		}
		$progress = MainWP_DB::instance()->get_table_name( 'wp_backup_progress' );
		$column   = $wpdb->get_row( $wpdb->prepare( 'SHOW FULL COLUMNS FROM %i LIKE %s', $progress, 'child_backup_task_id' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture reads column metadata.
		$this->assertNotSame( $snapshot_collation, $column->Collation, 'Progress and snapshot tables must disagree on collation.' ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MySQL column name.

		parent::set_up();
		$this->set_up_private_root();

		$db            = MainWP_DB_Backup::instance();
		$task          = $db->add_backup_task( get_current_user_id(), 'Mixed collation incremental', 'daily', 'incremental', '', (string) $this->site_id, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0 );
		$this->task_id = (int) $task->id;
	}

	/**
	 * Roll back the fixture rows first, then restore the original collations.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wpdb;

		foreach ( $this->fixture_dirs as $dir ) {
			if ( is_dir( $dir ) ) {
				array_map( 'unlink', glob( $dir . '/*' ) ?: array() ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test cleanup.
				rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- test cleanup.
			}
		}
		$this->fixture_dirs = array();
		$this->tear_down_private_root();
		parent::tear_down();

		foreach ( $this->original_tables as $table => $create ) {
			preg_match( '/DEFAULT CHARSET=(\w+)(?: COLLATE=(\w+))?/', $create, $match );
			$collation = $match[2] ?? $wpdb->get_var( $wpdb->prepare( 'SELECT DEFAULT_COLLATION_NAME FROM information_schema.CHARACTER_SETS WHERE CHARACTER_SET_NAME = %s', $match[1] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test cleanup reads the charset default.
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i CONVERT TO CHARACTER SET %s COLLATE %s', $table, $match[1], $collation ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.QuotedSimplePlaceholder -- test cleanup restores the collation.
			$this->assertSame( $create, $this->create_table_sql( $table ), 'The legacy table must be restored exactly.' );
		}
		$this->original_tables = array();
	}

	/**
	 * Read a table definition, without the auto-increment counter that rows advance.
	 *
	 * @param string $table Table name.
	 * @return string CREATE TABLE statement.
	 */
	private function create_table_sql( string $table ): string {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture reads the table definition.
		return (string) preg_replace( '/ AUTO_INCREMENT=\d+/', '', (string) $row[1] );
	}

	/**
	 * Open the task's run window and record the site's progress in it.
	 *
	 * @param string $child_id Child handle or creating marker.
	 * @return void
	 */
	private function start_incremental_run( string $child_id ) {
		$db  = MainWP_DB_Backup::instance();
		$run = time() - 100;
		$db->update_backup_task_with_values(
			$this->task_id,
			array(
				'last_run'  => $run,
				'completed' => 0,
			)
		);
		$db->add_backup_task_progress( $this->task_id, $this->site_id, array() );
		$db->update_backup_task_progress(
			$this->task_id,
			$this->site_id,
			array(
				'dtsFetched'           => $run,
				'child_backup_task_id' => $child_id,
				'child_phase'          => '',
			)
		);
	}

	/**
	 * Record a snapshot row over a local dir with one artifact.
	 *
	 * @param array $fields Snapshot row overrides.
	 * @return array{0: int, 1: string} Snapshot id and its local dir.
	 */
	private function make_snapshot_with_dir( array $fields = array() ) {
		global $wpdb;

		$dir = \MainWP\Dashboard\MainWP_Backup_Private_Root::site_dir( $this->site_id ) . 'snapshot-collation-' . uniqid();
		wp_mkdir_p( $dir );
		$this->fixture_dirs[] = $dir;
		$path                 = $dir . '/artifact.tar';
		file_put_contents( $path, str_repeat( 'x', 100 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ),
			array_merge(
				array(
					'task_id'       => $this->task_id,
					'site_id'       => $this->site_id,
					'type'          => 'full',
					'created'       => time(),
					'archives'      => wp_json_encode( array( array( 'file' => 'artifact.tar', 'size' => 100, 'role' => 'full', 'path' => $path ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
					'manifest_path' => $dir . '/files.list',
					'status'        => 'complete',
				),
				$fields
			)
		);
		return array( (int) $wpdb->insert_id, $dir );
	}

	/**
	 * Run one prune candidate through the executor.
	 *
	 * @param int    $id Snapshot id.
	 * @param string $reason Candidate reason.
	 * @return array Prune result.
	 */
	private function prune( int $id, string $reason = 'count' ): array {
		return MainWP_Backup_Retention_Executor::instance()->run_prune(
			array(
				array(
					'snapshot_ids' => array( $id ),
					'reason'       => $reason,
				),
			),
			(object) array(
				'id'                  => $this->task_id,
				'delete_cloud_copies' => 0,
			)
		);
	}

	/**
	 * Cleanup and single delete on an idle site with full snapshots only.
	 *
	 * @return array Candidate reasons.
	 */
	public static function removal_reasons(): array {
		return array(
			'retention cleanup' => array( 'count' ),
			'snapshot delete'   => array( 'single_snapshot_delete' ),
		);
	}

	/**
	 * Mixed collations no longer read as a running backup on an idle site.
	 *
	 * @dataProvider removal_reasons
	 * @param string $reason Candidate reason.
	 */
	public function test_idle_site_full_snapshots_are_removed_under_mixed_collations( string $reason ) {
		list( $id, $dir ) = $this->make_snapshot_with_dir();

		$this->assertFalse( MainWP_DB_Backup::instance()->has_inflight_incremental_for_site( $this->site_id ) );
		$result = $this->prune( $id, $reason );

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( array( $id ), $result['deleted_ids'] );
		$this->assertFileDoesNotExist( $dir . '/artifact.tar' );
		$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id )->status );
	}

	/** A started incremental with no recorded snapshot still holds the site's snapshots. */
	public function test_active_incremental_still_holds_under_mixed_collations() {
		list( $id, $dir ) = $this->make_snapshot_with_dir();
		$this->start_incremental_run( 'child-run-active' );

		$result = $this->prune( $id );

		$this->assertSame( array(), $result['deleted_ids'] );
		$this->assertSame( array( $id ), $result['remaining_ids'] );
		$this->assertFileExists( $dir . '/artifact.tar' );
		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id )->status );
		$this->assertSame( array( sprintf( 'Snapshots %d: an incremental backup is running on this site, so they were kept. Try the cleanup again once that backup finishes.', $id ) ), $result['errors'] );
		$this->assertTrue( MainWP_DB_Backup::instance()->get_inflight_incremental_state( $this->site_id ) );
	}

	/** A finished incremental whose snapshot is recorded no longer holds, while the fleet run stays open. */
	public function test_completed_incremental_with_recorded_snapshot_allows_removal_under_mixed_collations() {
		list( $id, $dir ) = $this->make_snapshot_with_dir();
		$this->make_snapshot_with_dir(
			array(
				'type'    => 'incremental',
				'run_key' => $this->site_id . ':' . $this->task_id . ':c:child-run-done',
			)
		);
		$this->start_incremental_run( 'child-run-done' );

		$result = $this->prune( $id );

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( array( $id ), $result['deleted_ids'] );
		$this->assertFileDoesNotExist( $dir . '/artifact.tar' );
		$this->assertFalse( MainWP_DB_Backup::instance()->get_inflight_incremental_state( $this->site_id ) );
	}

	/**
	 * Each read the check makes.
	 *
	 * @return array Table each failure targets, and whether a run is open so the second read happens.
	 */
	public static function failing_reads(): array {
		return array(
			'progress read' => array( 'wp_backup_progress', false ),
			'snapshot read' => array( 'wp_backup_snapshot', true ),
		);
	}

	/**
	 * A failed read keeps the snapshots but does not claim a backup is running.
	 *
	 * @dataProvider failing_reads
	 * @param string $failing_table Table whose read fails.
	 * @param bool   $open_run Whether a finished run makes the check read snapshots.
	 */
	public function test_failed_inflight_read_holds_with_its_own_message( string $failing_table, bool $open_run ) {
		global $wpdb;
		list( $id, $dir ) = $this->make_snapshot_with_dir();
		if ( $open_run ) {
			$this->make_snapshot_with_dir(
				array(
					'type'    => 'incremental',
					'run_key' => $this->site_id . ':' . $this->task_id . ':c:child-run-read',
				)
			);
			$this->start_incremental_run( 'child-run-read' );
		}
		$table  = MainWP_DB::instance()->get_table_name( $failing_table );
		$failed = 0;
		$break  = static function ( $sql ) use ( $table, &$failed ) {
			// Only the in-flight read selects run_key or child_backup_task_id from these tables.
			if ( str_starts_with( $sql, 'SELECT' ) && str_contains( $sql, $table ) && ( str_contains( $sql, 'child_backup_task_id' ) || str_contains( $sql, 'run_key =' ) ) ) {
				++$failed;
				return str_replace( $table, $table . '_mwp2133_missing', $sql );
			}
			return $sql;
		};
		$broken     = static function ( callable $read ) use ( $wpdb, $break ) {
			add_filter( 'query', $break );
			$suppressed = $wpdb->suppress_errors( true );
			try {
				return $read();
			} finally {
				$wpdb->suppress_errors( $suppressed );
				remove_filter( 'query', $break );
			}
		};
		$result = $broken(
			function () use ( $id ) {
				return $this->prune( $id );
			}
		);

		$this->assertGreaterThan( 0, $failed, 'The in-flight read must have been broken.' );
		$this->assertSame( array(), $result['deleted_ids'] );
		$this->assertSame( array( $id ), $result['remaining_ids'] );
		$this->assertFileExists( $dir . '/artifact.tar' );
		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id )->status );
		$this->assertSame( array( sprintf( "Snapshots %d: couldn't check whether a backup is running on this site, so they were kept. Check the server error log for the database error.", $id ) ), $result['errors'] );
		$db = MainWP_DB_Backup::instance();
		$this->assertTrue(
			$broken(
				function () use ( $db ) {
					return $db->has_inflight_incremental_for_site( $this->site_id );
				}
			),
			'A failed read still holds deletion.'
		);
		$this->assertNull(
			$broken(
				function () use ( $db ) {
					return $db->get_inflight_incremental_state( $this->site_id );
				}
			)
		);
	}
}
