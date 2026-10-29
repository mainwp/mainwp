<?php
/**
 * MainWP Backup Cleanup Tests
 *
 * cleanup_site_backups() is the on-demand disk-recovery pass behind the
 * Snapshots page's Clean up button: it sweeps snapshot-* directories no
 * complete row claims (interrupted runs leave those rowless forever),
 * then prunes the site's on-demand task down to a keep-count through
 * the same leaf-first prune sequence retention uses. Broken chains -
 * units the policy can never restore or select - prune unconditionally.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Backup_Retention_Executor;
use MainWP\Dashboard\MainWP_Post_Backup_Handler;

/**
 * Class MainWP_Backup_Cleanup_Test
 */
class MainWP_Backup_Cleanup_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Fixture site id.
	 *
	 * @var int
	 */
	private $site_id = 990310;

	/**
	 * Created fixture dirs for cleanup.
	 *
	 * @var string[]
	 */
	private $fixture_dirs = array();

	/**
	 * Created backup task ids for cleanup.
	 *
	 * @var int[]
	 */
	private $fixture_task_ids = array();

	/**
	 * Clean fixture rows and dirs.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wpdb;

		$snapshot_table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$snapshot_table} WHERE site_id = %d", $this->site_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.

		$task_table = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		foreach ( $this->fixture_task_ids as $task_id ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$task_table} WHERE id = %d", $task_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.
		}
		$this->fixture_task_ids = array();

		foreach ( $this->fixture_dirs as $dir ) {
			if ( is_dir( $dir ) ) {
				array_map( 'unlink', glob( $dir . '/*' ) ?: array() ); // phpcs:ignore WordPress.WP.AlternativeFunctions, Universal.Operators.DisallowShortTernary.Found -- test cleanup.
				rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- test cleanup.
			}
		}
		$this->fixture_dirs = array();

		delete_option( 'mainwp_on_demand_retention_count' );

		parent::tear_down();
	}

	/**
	 * Create the site's hidden on-demand task row, the same shape
	 * ensure_manual_backup_task() writes.
	 *
	 * @param int $retention_count Retention count for the task.
	 *
	 * @return object Task row.
	 */
	private function make_manual_task( $retention_count = 5 ) {
		MainWP_DB_Backup::instance()->add_backup_task(
			1,
			'On-demand backup',
			'manual',
			'full',
			'',
			(string) $this->site_id,
			'',
			'',
			'',
			1,
			1,
			1,
			0,
			0,
			'site',
			0,
			1,
			150,
			0,
			0,
			$this->site_id
		);

		$task = MainWP_DB_Backup::instance()->get_manual_backup_task( $this->site_id );
		$this->assertNotEmpty( $task, 'manual task fixture created' );
		$this->fixture_task_ids[] = (int) $task->id;

		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			(int) $task->id,
			array(
				'retention_count'     => (int) $retention_count,
				'delete_cloud_copies' => 0,
			)
		);

		return MainWP_DB_Backup::instance()->get_manual_backup_task( $this->site_id );
	}

	/**
	 * Create a snapshot-* dir with one artifact file under the site's
	 * backup tree, optionally back-dated.
	 *
	 * @param int $age_seconds How long ago the dir was last written.
	 *
	 * @return array{0: string, 1: string} Dir and artifact path.
	 */
	private function make_dir_with_artifact( $age_seconds = 0 ) {
		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . 'mainwp/0/' . $this->site_id . '/snapshot-cleanup-' . uniqid();
		wp_mkdir_p( $dir );
		$this->fixture_dirs[] = $dir;

		$path = $dir . '/artifact.tar';
		file_put_contents( $path, str_repeat( 'x', 100 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		if ( $age_seconds > 0 ) {
			touch( $path, time() - $age_seconds ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
			touch( $dir, time() - $age_seconds ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		}

		return array( $dir, $path );
	}

	/**
	 * Record a complete snapshot row over a fresh dir + artifact.
	 *
	 * @param array $fields Snapshot row overrides.
	 *
	 * @return array{0: int, 1: string} Snapshot id and its dir.
	 */
	private function make_snapshot_with_dir( array $fields = array() ) {
		global $wpdb;

		list( $dir, $path ) = $this->make_dir_with_artifact();

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			$table,
			array_merge(
				array(
					'task_id'       => 0,
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
	 * Read a snapshot row's status.
	 *
	 * @param int $snapshot_id Snapshot id.
	 *
	 * @return string
	 */
	private function snapshot_status( $snapshot_id ) {
		global $wpdb;
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d", $snapshot_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test read, identifiers cannot be prepared.
	}

	/**
	 * The orphan sweep removes an idle rowless dir, spares a dir a
	 * complete row protects, and spares a dir written too recently -
	 * that one may still have a live run writing into it. The complete row
	 * belongs to a live task within its keep-count, so it survives
	 * untouched.
	 *
	 * @return void
	 */
	public function test_cleanup_sweeps_orphans_and_spares_protected_and_fresh() {
		$task                                = $this->make_manual_task();
		list( $snapshot_id, $protected_dir ) = $this->make_snapshot_with_dir( array( 'task_id' => (int) $task->id ) );
		list( $orphan_dir )                  = $this->make_dir_with_artifact( 7 * HOUR_IN_SECONDS );
		list( $fresh_dir )                   = $this->make_dir_with_artifact();

		$result = MainWP_Backup_Retention_Executor::instance()->cleanup_site_backups( $this->site_id );

		$this->assertSame( 1, $result['orphan_dirs_removed'] );
		$this->assertSame( array(), $result['errors'] );
		$this->assertDirectoryDoesNotExist( $orphan_dir );
		$this->assertDirectoryExists( $protected_dir );
		$this->assertFileExists( $protected_dir . '/artifact.tar' );
		$this->assertDirectoryExists( $fresh_dir );
		$this->assertSame( 'complete', $this->snapshot_status( $snapshot_id ) );
	}

	/**
	 * The prune pass keeps the newest keep-count complete units of the
	 * on-demand task and prunes the rest oldest-first: rows flip to
	 * pruned and their dirs are removed, exactly like retention.
	 *
	 * @return void
	 */
	public function test_cleanup_prunes_manual_units_beyond_keep() {
		$task = $this->make_manual_task();

		list( $old_id, $old_dir )       = $this->make_snapshot_with_dir(
			array(
				'task_id' => (int) $task->id,
				'created' => time() - 300,
			)
		);
		list( $middle_id, $middle_dir ) = $this->make_snapshot_with_dir(
			array(
				'task_id' => (int) $task->id,
				'created' => time() - 200,
			)
		);
		list( $new_id, $new_dir )       = $this->make_snapshot_with_dir(
			array(
				'task_id' => (int) $task->id,
				'created' => time() - 100,
			)
		);

		$result = MainWP_Backup_Retention_Executor::instance()->cleanup_site_backups( $this->site_id, 1 );

		$this->assertSame( 2, $result['pruned'] );
		$this->assertSame( 2, $result['snapshots_pruned'] );
		$this->assertSame( 'pruned', $this->snapshot_status( $old_id ) );
		$this->assertSame( 'cleanup', \MainWP\Dashboard\MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $old_id )->removal_reason );
		$this->assertSame( 'pruned', $this->snapshot_status( $middle_id ) );
		$this->assertSame( 'cleanup', \MainWP\Dashboard\MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $middle_id )->removal_reason );
		$this->assertSame( 'complete', $this->snapshot_status( $new_id ) );
		$this->assertSame( '', \MainWP\Dashboard\MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $new_id )->removal_reason );
		$this->assertDirectoryDoesNotExist( $old_dir );
		$this->assertDirectoryDoesNotExist( $middle_dir );
		$this->assertDirectoryExists( $new_dir );
		$this->assertFileExists( $new_dir . '/artifact.tar' );
	}

	/**
	 * A lowered live setting applies before another on-demand backup runs.
	 *
	 * @return void
	 */
	public function test_cleanup_default_keep_uses_live_retention_count() {
		update_option( 'mainwp_on_demand_retention_count', 2 );
		$task = $this->make_manual_task( 25 );

		list( $old_id ) = $this->make_snapshot_with_dir(
			array(
				'task_id' => (int) $task->id,
				'created' => time() - 300,
			)
		);
		list( $mid_id ) = $this->make_snapshot_with_dir(
			array(
				'task_id' => (int) $task->id,
				'created' => time() - 200,
			)
		);
		list( $new_id ) = $this->make_snapshot_with_dir(
			array(
				'task_id' => (int) $task->id,
				'created' => time() - 100,
			)
		);

		$result = MainWP_Backup_Retention_Executor::instance()->cleanup_site_backups( $this->site_id );

		$this->assertSame( 1, $result['pruned'] );
		$this->assertSame( 'pruned', $this->snapshot_status( $old_id ) );
		$this->assertSame( 'complete', $this->snapshot_status( $mid_id ) );
		$this->assertSame( 'complete', $this->snapshot_status( $new_id ) );
	}

	/**
	 * A keep of zero floors at one: a cleanup can never delete the
	 * site's last restorable backup.
	 *
	 * @return void
	 */
	public function test_cleanup_keep_floors_at_one() {
		$task = $this->make_manual_task();

		list( $old_id ) = $this->make_snapshot_with_dir(
			array(
				'task_id' => (int) $task->id,
				'created' => time() - 300,
			)
		);
		list( $new_id ) = $this->make_snapshot_with_dir(
			array(
				'task_id' => (int) $task->id,
				'created' => time() - 100,
			)
		);

		MainWP_Backup_Retention_Executor::instance()->cleanup_site_backups( $this->site_id, 0 );

		$this->assertSame( 'pruned', $this->snapshot_status( $old_id ) );
		$this->assertSame( 'complete', $this->snapshot_status( $new_id ) );
	}

	/**
	 * A chain whose parent link is missing can never be restored and
	 * the policy never selects it, so cleanup prunes it no matter how
	 * generous the keep-count is; the intact unit stays.
	 *
	 * @return void
	 */
	public function test_cleanup_prunes_broken_chain_regardless_of_keep() {
		$task = $this->make_manual_task();

		list( $full_id, $full_dir )     = $this->make_snapshot_with_dir(
			array(
				'task_id' => (int) $task->id,
				'created' => time() - 200,
			)
		);
		list( $broken_id, $broken_dir ) = $this->make_snapshot_with_dir(
			array(
				'task_id'   => (int) $task->id,
				'type'      => 'incremental',
				'parent_id' => 999999999,
				'created'   => time() - 100,
			)
		);

		$result = MainWP_Backup_Retention_Executor::instance()->cleanup_site_backups( $this->site_id, 10 );

		$this->assertSame( 1, $result['pruned'] );
		$this->assertSame( 'pruned', $this->snapshot_status( $broken_id ) );
		$this->assertSame( 'cleanup', \MainWP\Dashboard\MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $broken_id )->removal_reason );
		$this->assertDirectoryDoesNotExist( $broken_dir );
		$this->assertSame( 'complete', $this->snapshot_status( $full_id ) );
		$this->assertDirectoryExists( $full_dir );
	}

	/**
	 * The cleanup AJAX action registers for a user holding
	 * execute_backups and points at a real method, and does not
	 * register when the capability is denied - the same gate every
	 * snapshots-page endpoint sits behind.
	 *
	 * @return void
	 */
	public function test_cleanup_action_capability_gated() {
		global $wp_filter;

		$action = 'mainwp_snapshots_cleanup';
		unset( $wp_filter[ 'wp_ajax_' . $action ] );

		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		MainWP_Post_Backup_Handler::instance()->init();

		$this->assertTrue( $this->cleanup_handler_registered(), 'registered for execute_backups' );
		$this->assertTrue( method_exists( MainWP_Post_Backup_Handler::instance(), $action ) );

		if ( $this->capabilities_bypassed() ) {
			// Not a skip: the registration assertions above already ran and
			// should count; only the denial half is bypassed by design here.
			return;
		}

		unset( $wp_filter[ 'wp_ajax_' . $action ] );
		$deny = function ( $allowed, $cap_type, $cap ) {
			if ( 'dashboard' === $cap_type && 'execute_backups' === $cap ) {
				return false;
			}
			return $allowed;
		};
		add_filter( 'mainwp_currentusercan', $deny, 10, 3 );
		MainWP_Post_Backup_Handler::instance()->init();
		remove_filter( 'mainwp_currentusercan', $deny, 10 );

		$this->assertFalse( $this->cleanup_handler_registered(), 'not registered without execute_backups' );
	}

	/**
	 * Whether the cleanup AJAX hook has a handler-owned callback.
	 *
	 * @return bool
	 */
	private function cleanup_handler_registered() {
		global $wp_filter;
		if ( ! isset( $wp_filter['wp_ajax_mainwp_snapshots_cleanup'] ) ) {
			return false;
		}
		foreach ( $wp_filter['wp_ajax_mainwp_snapshots_cleanup']->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];
				if ( is_array( $function ) && isset( $function[0] ) && $function[0] instanceof MainWP_Post_Backup_Handler ) {
					return true;
				}
			}
		}
		return false;
	}

	/** Orphaned task candidates must record Clean Up as their cause. */
	public function test_cleanup_orphaned_task_records_cleanup_reason() {
		list( $base ) = $this->make_snapshot_with_dir( array( 'task_id' => 999999991 ) );
		list( $leaf ) = $this->make_snapshot_with_dir(
			array(
				'task_id'   => 999999991,
				'parent_id' => $base,
				'type'      => 'incremental',
			)
		);
		$result       = MainWP_Backup_Retention_Executor::instance()->cleanup_site_backups( $this->site_id );
		$this->assertSame( 2, $result['orphaned_snapshots_pruned'] );
		$this->assertSame( array(), $result['errors'] );
		foreach ( array( $base, $leaf ) as $id ) {
			$this->assertSame( 'pruned', $this->snapshot_status( $id ) );
			$this->assertSame( 'cleanup', \MainWP\Dashboard\MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id )->removal_reason );
		}
	}
}
