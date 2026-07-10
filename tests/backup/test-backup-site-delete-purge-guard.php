<?php
/**
 * MainWP Site-Delete Purge Guard Tests
 *
 * Deleting a site purges its hidden on-demand backup task's restorable
 * snapshots, cloud copies and upload metadata before dropping the task row
 * (hook_delete_manual_backup_task(), hooked on mainwp_delete_site). When the
 * purge cannot remove an artifact (recorded outside the uploads root, an
 * unlink refused) the task and its diagnostics must be kept and the
 * mainwp_backup_task_snapshots_purged action must not fire - dropping the
 * task anyway would orphan the surviving artifacts with no owning task for
 * retention to retry, and report nothing.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Post_Backup_Handler;

/**
 * Class MainWP_Backup_Site_Delete_Purge_Guard_Test
 */
class MainWP_Backup_Site_Delete_Purge_Guard_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Created fixture dirs for cleanup.
	 *
	 * @var string[]
	 */
	private $fixture_dirs = array();

	/**
	 * Enable native backups so no unrelated kill switch interferes.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		update_option( 'mainwp_enableNativeBackups', 1 );
	}

	/**
	 * Clean fixture rows, dirs, and the enablement option.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wpdb;

		$backup_table   = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$snapshot_table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$wpdb->query( "DELETE FROM {$backup_table} WHERE name = 'On-demand backup'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup.
		$wpdb->query( "DELETE FROM {$snapshot_table} WHERE task_id >= 970000" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup.

		foreach ( $this->fixture_dirs as $dir ) {
			foreach ( (array) glob( $dir . '/*' ) as $file ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test cleanup.
			}
			if ( is_dir( $dir ) ) {
				rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test cleanup.
			}
		}

		delete_option( 'mainwp_enableNativeBackups' );
		parent::tearDown();
	}

	/**
	 * Record a complete snapshot for a task/site whose artifact path points
	 * at a real file outside the uploads root, so the purge refuses it and
	 * returns an error.
	 *
	 * @param int    $task_id Task id.
	 * @param int    $site_id Site id.
	 * @param string $outside Path to a real file outside uploads/mainwp.
	 *
	 * @return int Snapshot id.
	 */
	private function record_outside_root_snapshot( $task_id, $site_id, $outside ) {
		global $wpdb;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			$table,
			array(
				'task_id'  => $task_id,
				'site_id'  => $site_id,
				'type'     => 'full',
				'created'  => time(),
				'archives' => wp_json_encode( array( array( 'file' => 'outside.tar', 'size' => 50, 'role' => 'full', 'path' => $outside ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
				'status'   => 'complete',
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * A manual task whose purge fails is not removed and the
	 * snapshots-purged action does not fire - the deleted site's task and
	 * its diagnostics survive for retention to retry.
	 *
	 * @return void
	 */
	public function test_delete_site_preserves_task_when_purge_fails() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$site_id = $this->create_test_site( array( 'userid' => $user ) );
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );

		$task = MainWP_DB_Backup::instance()->ensure_manual_backup_task( $website );
		$this->assertNotFalse( $task, 'Precondition: the manual task row must insert.' );

		$outside = sys_get_temp_dir() . '/mainwp-site-delete-outside-' . uniqid() . '.tar';
		file_put_contents( $outside, str_repeat( 'x', 50 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		$snapshot_id = $this->record_outside_root_snapshot( (int) $task->id, $site_id, $outside );

		$purged_fired = false;
		$listener     = function () use ( &$purged_fired ) {
			$purged_fired = true;
		};
		add_action( 'mainwp_backup_task_snapshots_purged', $listener );

		try {
			$result = MainWP_Post_Backup_Handler::instance()->hook_delete_manual_backup_task( $website );

			$this->assertFalse( $result, 'A failed purge must report the bail, not a completed cleanup.' );
			$this->assertFalse( $purged_fired, 'The snapshots-purged hook must not fire when the purge failed.' );
			$this->assertNotNull( MainWP_DB_Backup::instance()->get_manual_backup_task( $site_id ), 'A failed purge must keep the manual task for retention to retry.' );
			$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id )->status, 'The kept snapshot must stay complete.' );
			$this->assertFileExists( $outside, 'The out-of-tree file must never be touched.' );
		} finally {
			remove_action( 'mainwp_backup_task_snapshots_purged', $listener );
			unlink( $outside ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test cleanup.
		}
	}

	/**
	 * The positive control: a manual task whose purge succeeds is removed
	 * and the snapshots-purged action fires once.
	 *
	 * @return void
	 */
	public function test_delete_site_removes_task_when_purge_succeeds() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$site_id = $this->create_test_site( array( 'userid' => $user ) );
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );

		$task = MainWP_DB_Backup::instance()->ensure_manual_backup_task( $website );
		$this->assertNotFalse( $task, 'Precondition: the manual task row must insert.' );

		$purged_count = 0;
		$listener     = function () use ( &$purged_count ) {
			++$purged_count;
		};
		add_action( 'mainwp_backup_task_snapshots_purged', $listener );

		try {
			$result = MainWP_Post_Backup_Handler::instance()->hook_delete_manual_backup_task( $website );

			$this->assertTrue( $result, 'A successful purge must report the cleanup as completed.' );
			$this->assertSame( 1, $purged_count, 'The snapshots-purged hook must fire exactly once on a clean purge.' );
			$this->assertNull( MainWP_DB_Backup::instance()->get_manual_backup_task( $site_id ), 'A successful purge must drop the manual task row.' );
		} finally {
			remove_action( 'mainwp_backup_task_snapshots_purged', $listener );
		}
	}
}
