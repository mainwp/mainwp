<?php
/**
 * MainWP Backup Task Delete Authorization Tests
 *
 * Deleting a backup task purges the restorable snapshots and cloud copies
 * of every site the task covers - an irreversible cross-site action. The
 * delete handler was gated only by can_edit_backuptask(), a no-op under
 * the default single-user mode, so a Team Control-restricted user could
 * destroy the backups of a site outside their scope. It must gate on the
 * same per-site access the run/reset/list paths enforce. Separately, when
 * the purge cannot remove an artifact (recorded outside root, unlink
 * refused) the task and its diagnostics must be kept and the failure
 * surfaced, not reported as a successful delete.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Manage_Backups_Handler;

/**
 * Class MainWP_Backup_Task_Delete_Auth_Test
 */
class MainWP_Backup_Task_Delete_Auth_Test extends MainWP_Abilities_Test_Case {

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
		$wpdb->query( "DELETE FROM {$backup_table} WHERE name LIKE 'Delete auth test%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup.
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
	 * Skip a denial test when another suite in this process defined one of
	 * the constants that bypass capability checks by design.
	 *
	 * @return void
	 */
	private function skip_when_capabilities_bypassed() {
		if ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'MAINWP_REST_API_DOING' ) && MAINWP_REST_API_DOING ) ) {
			$this->markTestSkipped( 'Capability checks are bypassed by design under cron, WP-CLI, and REST constants; another suite defined one for this process.' );
		}
	}

	/**
	 * Deny the per-site capability for one site id, simulating a Team
	 * Control site restriction.
	 *
	 * @param int $site_id Site id to deny.
	 *
	 * @return callable The filter, for later removal.
	 */
	private function deny_site( $site_id ) {
		$deny = function ( $allowed, $cap_type, $cap ) use ( $site_id ) {
			if ( 'site' === $cap_type && (int) $cap === (int) $site_id ) {
				return false;
			}
			return $allowed;
		};
		add_filter( 'mainwp_currentusercan', $deny, 10, 3 );
		return $deny;
	}

	/**
	 * Create a backup task over one site.
	 *
	 * @param int $site_id Site id in the task's sites column.
	 *
	 * @return object Task row.
	 */
	private function create_task( $site_id ) {
		$task = MainWP_DB_Backup::instance()->add_backup_task( get_current_user_id(), 'Delete auth test ' . uniqid(), 'daily', 'full', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'site', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task, 'Precondition: the task row must insert.' );
		return $task;
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
	 * A Team Control-restricted site refuses the delete: the task survives
	 * and no purge runs. This is the security gate.
	 *
	 * @return void
	 */
	public function test_delete_denies_site_restricted_user() {
		$this->skip_when_capabilities_bypassed();

		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$site_id = $this->create_test_site( array( 'userid' => $user ) );
		$task    = $this->create_task( $site_id );

		$deny = $this->deny_site( $site_id );
		try {
			$response = MainWP_Manage_Backups_Handler::remove_backup_result( $task->id );
		} finally {
			remove_filter( 'mainwp_currentusercan', $deny, 10 );
		}

		$this->assertArrayHasKey( 'error', $response, 'A restricted site must refuse the delete.' );
		$this->assertNotNull( MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id ), 'A refused delete must not drop the task.' );
	}

	/**
	 * The positive control: a caller with access to the task's site deletes
	 * it and the row is gone.
	 *
	 * @return void
	 */
	public function test_delete_allows_accessible_site() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$site_id = $this->create_test_site( array( 'userid' => $user ) );
		$task    = $this->create_task( $site_id );

		$response = MainWP_Manage_Backups_Handler::remove_backup_result( $task->id );

		$this->assertSame( 'SUCCESS', $response['result'] ?? null, 'An accessible task must delete.' );
		$this->assertNull( MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id ), 'A successful delete must drop the task row.' );
	}

	/**
	 * When the purge cannot remove an artifact, the task and its
	 * diagnostics are kept, the snapshots-purged hook does not fire, and the
	 * response is an error - never a false SUCCESS.
	 *
	 * @return void
	 */
	public function test_delete_preserves_task_when_purge_fails() {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$site_id = $this->create_test_site( array( 'userid' => $user ) );
		$task    = $this->create_task( $site_id );

		$outside = sys_get_temp_dir() . '/mainwp-delete-outside-' . uniqid() . '.tar';
		file_put_contents( $outside, str_repeat( 'x', 50 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		$snapshot_id = $this->record_outside_root_snapshot( (int) $task->id, $site_id, $outside );

		$purged_fired = false;
		$listener     = function () use ( &$purged_fired ) {
			$purged_fired = true;
		};
		add_action( 'mainwp_backup_task_snapshots_purged', $listener );

		try {
			$response = MainWP_Manage_Backups_Handler::remove_backup_result( $task->id );

			$this->assertArrayHasKey( 'error', $response, 'A failed purge must not report SUCCESS.' );
			$this->assertArrayNotHasKey( 'result', $response, 'A failed purge must not report SUCCESS.' );
			$this->assertFalse( $purged_fired, 'The diagnostics-purge hook must not fire when the purge failed.' );
			$this->assertNotNull( MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id ), 'A failed purge must keep the task for retention to retry.' );
			$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id )->status, 'The kept snapshot must stay complete.' );
			$this->assertFileExists( $outside, 'The out-of-tree file must never be touched.' );
		} finally {
			remove_action( 'mainwp_backup_task_snapshots_purged', $listener );
			unlink( $outside ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test cleanup.
		}
	}
}
