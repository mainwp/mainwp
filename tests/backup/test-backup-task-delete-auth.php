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

require_once __DIR__ . '/trait-backup-private-root-fixture.php';

use MainWP\Dashboard\MainWP_Backup_Private_Root;
use MainWP\Dashboard\MainWP_Backup_Snapshot_Catalog;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Manage_Backups_Handler;
use MainWP\Dashboard\MainWP_Post_Backup_Handler;

/**
 * Class MainWP_Backup_Task_Delete_Auth_Test
 */
class MainWP_Backup_Task_Delete_Auth_Test extends MainWP_Abilities_Test_Case {

	use Backup_Private_Root_Fixture;

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

	/**
	 * Record a complete full snapshot whose archive sits in the site's shared
	 * folder under the private root, as production writes it.
	 *
	 * @param int $task_id Task id.
	 * @param int $site_id Site id.
	 *
	 * @return array{0: int, 1: string} Snapshot id and archive path.
	 */
	private function record_local_snapshot( $task_id, $site_id ) {
		global $wpdb;
		$dir = MainWP_Backup_Private_Root::site_dir( $site_id );
		wp_mkdir_p( $dir );
		$path = $dir . 'full-example.test-08-10-2026-' . uniqid() . '.tar.gz';
		file_put_contents( $path, str_repeat( 'x', 2048 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ),
			array(
				'task_id'  => $task_id,
				'site_id'  => $site_id,
				'type'     => 'full',
				'created'  => time(),
				'archives' => wp_json_encode( array( array( 'file' => basename( $path ), 'size' => 2048, 'role' => 'full', 'path' => $path ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
				'status'   => 'complete',
			)
		);
		return array( (int) $wpdb->insert_id, $path );
	}

	/** UI task deletion does not retry an already-pruned snapshot's leftover files. */
	public function test_ui_delete_ignores_unremovable_pruned_snapshot_directory() {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Root bypasses filesystem permission failures.' );
		}
		$this->set_up_private_root();
		$dir = '';
		try {
			$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
			wp_set_current_user( $user );
			$site_id                    = $this->create_test_site( array( 'userid' => $user ) );
			$task                       = $this->create_task( $site_id );
			list( $snapshot_id, $path ) = $this->record_local_snapshot( (int) $task->id, $site_id );
			$dir                        = dirname( $path ) . '/snapshot-ui-delete-' . $snapshot_id;
			wp_mkdir_p( $dir );
			$new_path = $dir . '/' . basename( $path );
			rename( $path, $new_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- owned fixture.
			$snapshot                      = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id );
			$snapshot->archives[0]['path'] = $new_path;
			global $wpdb;
			$wpdb->update(
				MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ),
				array(
					'archives' => wp_json_encode( $snapshot->archives ),
					'status'   => 'pruned',
				),
				array( 'id' => $snapshot_id )
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- owned pruned fixture.
			chmod( $dir, 0500 ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- force the actual unlink refusal as the web user.
			$this->assertFalse( is_writable( $dir ), 'The leftover directory must refuse file removal.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- permission precondition.

			$response = MainWP_Manage_Backups_Handler::remove_backup_result( $task->id, true );
			$this->assertSame( 'SUCCESS', $response['result'] ?? null, wp_json_encode( $response ) );
			$this->assertNull( MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id ) );
			$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id )->status );
			$this->assertFileExists( $new_path );
		} finally {
			if ( '' !== $dir && is_dir( $dir ) ) {
				chmod( $dir, 0700 ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- restore the owned fixture before cleanup.
			}
			$this->tear_down_private_root();
		}
	}

	/**
	 * Run a snapshots-page AJAX handler with its nonce and return the decoded response.
	 *
	 * @param string $action Handler method and nonce action.
	 * @param array  $fields POST fields.
	 *
	 * @return array|null Decoded response.
	 */
	private function post_snapshots_ajax( $action, array $fields ) {
		$nonce                = wp_create_nonce( $action );
		$_POST                = array_merge( $_POST, $fields, array( 'security' => $nonce ) );
		$_REQUEST['security'] = $nonce;
		$throw                = static function () {
			return static function () {
				throw new \RuntimeException( 'wp_die' );
			};
		};
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $throw );
		ob_start();
		try {
			MainWP_Post_Backup_Handler::instance()->{$action}();
		} catch ( \RuntimeException $e ) {
			unset( $e ); // wp_send_json ended the request as designed.
		} finally {
			$raw = ob_get_clean();
			remove_filter( 'wp_die_ajax_handler', $throw );
			remove_filter( 'wp_doing_ajax', '__return_true' );
			foreach ( array_keys( $fields ) as $field ) {
				unset( $_POST[ $field ] );
			}
			unset( $_POST['security'], $_REQUEST['security'] );
		}
		return json_decode( $raw, true );
	}

	/**
	 * Deleting a task can keep its snapshots: the task row goes, the
	 * snapshots stay complete with their files, and Snapshots & Restore
	 * still lists them as restorable. Without the choice the delete purges
	 * as before.
	 *
	 * @dataProvider data_delete_snapshot_choices
	 *
	 * @param bool $delete_snapshots Whether the delete also removes the snapshots.
	 *
	 * @return void
	 */
	public function test_delete_can_keep_the_task_snapshots( $delete_snapshots ) {
		$this->set_up_private_root();
		try {
			$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
			wp_set_current_user( $user );
			$site_id                    = $this->create_test_site( array( 'userid' => $user ) );
			$task                       = $this->create_task( $site_id );
			list( $snapshot_id, $path ) = $this->record_local_snapshot( (int) $task->id, $site_id );

			$response = MainWP_Manage_Backups_Handler::remove_backup_result( $task->id, $delete_snapshots );

			$this->assertSame( 'SUCCESS', $response['result'] ?? null );
			$this->assertNull( MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id ) );
			$this->assertSame( $delete_snapshots ? 'pruned' : 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id )->status );
			$this->assertSame( ! $delete_snapshots, file_exists( $path ) );
			if ( $delete_snapshots ) {
				return;
			}

			$rows = array_column( $this->post_snapshots_ajax( 'mainwp_restore_snapshot_list', array( 'site_id' => (string) $site_id ) )['snapshots'], null, 'id' );
			$this->assertArrayHasKey( $snapshot_id, $rows, 'A kept snapshot is still listed.' );
			$this->assertTrue( $rows[ $snapshot_id ]['restorable'] );
			$this->assertSame( 'local', $rows[ $snapshot_id ]['source'] );
			$this->assertTrue( MainWP_Backup_Snapshot_Catalog::instance()->resolve_restorable_chain( $snapshot_id, $site_id )['ok'] );
		} finally {
			$this->tear_down_private_root();
		}
	}

	/**
	 * Delete choices.
	 *
	 * @return array
	 */
	public static function data_delete_snapshot_choices() {
		return array(
			'delete snapshots' => array( true ),
			'keep snapshots'   => array( false ),
		);
	}

	/**
	 * Clean Up removes the site's snapshots no task owns any more, through
	 * the task purge path, and reports how many and how much; snapshots of
	 * a live task stay, and the earlier response keys are unchanged.
	 *
	 * @return void
	 */
	public function test_cleanup_removes_snapshots_of_deleted_tasks() {
		$this->set_up_private_root();
		try {
			$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
			wp_set_current_user( $user );
			$site_id                      = $this->create_test_site( array( 'userid' => $user ) );
			$deleted                      = $this->create_task( $site_id );
			$live                         = $this->create_task( $site_id );
			list( $orphan_id, $orphan )   = $this->record_local_snapshot( (int) $deleted->id, $site_id );
			list( $orphan2_id, $orphan2 ) = $this->record_local_snapshot( (int) $deleted->id, $site_id );
			list( $live_id, $live_path )  = $this->record_local_snapshot( (int) $live->id, $site_id );
			$this->assertSame( 'SUCCESS', MainWP_Manage_Backups_Handler::remove_backup_result( $deleted->id, false )['result'] ?? null );

			$response = $this->post_snapshots_ajax( 'mainwp_snapshots_cleanup', array( 'site_id' => (string) $site_id ) );

			$this->assertSame( 'ok', $response['result'] );
			foreach ( array( 'orphan_dirs_removed', 'units_pruned', 'snapshots_pruned', 'errors' ) as $key ) {
				$this->assertArrayHasKey( $key, $response );
			}
			$this->assertSame( array(), $response['errors'] );
			$this->assertSame( 2, $response['orphaned_snapshots_pruned'] );
			$this->assertSame( 4096, $response['bytes_pruned'] );
			$this->assertSame( 2, $response['snapshots_pruned'] );
			foreach ( array( $orphan_id, $orphan2_id ) as $id ) {
				$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id )->status );
			}
			$this->assertFileDoesNotExist( $orphan );
			$this->assertFileDoesNotExist( $orphan2 );
			$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $live_id )->status );
			$this->assertFileExists( $live_path );
		} finally {
			$this->tear_down_private_root();
		}
	}

	/**
	 * Clean Up on a site with sync errors still prunes the Dashboard's copies
	 * and answers without an error: the Child prune fails like it would for
	 * an unreachable site, and that failure is only logged.
	 *
	 * @return void
	 */
	public function test_cleanup_on_disconnected_site_prunes_without_error() {
		global $wpdb;
		$this->set_up_private_root();
		$prunes = array();
		$refuse = static function ( $result, $website, $what ) use ( &$prunes ) {
			unset( $website );
			if ( 'backup_task_prune' !== $what ) {
				return $result;
			}
			$prunes[] = $what;
			throw new \MainWP\Dashboard\MainWP_Exception( 'HTTPERROR', 'cURL error 7: Failed to connect' );
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $refuse, 10, 3 );
		try {
			$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
			wp_set_current_user( $user );
			$site_id                  = $this->create_test_site( array( 'userid' => $user ) );
			$deleted                  = $this->create_task( $site_id );
			list( $orphan_id, $path ) = $this->record_local_snapshot( (int) $deleted->id, $site_id );
			$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'run_key' => $site_id . ':' . $deleted->id . ':c:task-on-child' ), array( 'id' => $orphan_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			$this->assertSame( 'SUCCESS', MainWP_Manage_Backups_Handler::remove_backup_result( $deleted->id, false )['result'] ?? null );
			$wpdb->update( $wpdb->prefix . 'mainwp_wp_sync', array( 'sync_errors' => 'HTTP error 500' ), array( 'wpid' => $site_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			$this->assertTrue( MainWP_Post_Backup_Handler::site_is_disconnected( MainWP_DB::instance()->get_website_by_id( $site_id ) ), 'Precondition: the site has sync errors.' );

			$response = $this->post_snapshots_ajax( 'mainwp_snapshots_cleanup', array( 'site_id' => (string) $site_id ) );

			$this->assertSame( 'ok', $response['result'] ?? null );
			$this->assertArrayNotHasKey( 'error', $response );
			$this->assertSame( array(), $response['errors'] );
			$this->assertSame( 1, $response['snapshots_pruned'] );
			$this->assertSame( array( 'backup_task_prune' ), $prunes, 'The Child prune was tried and failed.' );
			$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $orphan_id )->status );
			$this->assertFileDoesNotExist( $path );
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $refuse, 10 );
			$this->tear_down_private_root();
		}
	}

	/**
	 * A task with a run in flight cannot be deleted either way: its Child
	 * task handles live only in the progress rows the delete would drop.
	 *
	 * @dataProvider data_delete_snapshot_choices
	 *
	 * @param bool $delete_snapshots Whether the delete also removes the snapshots.
	 *
	 * @return void
	 */
	public function test_delete_refuses_a_running_task( $delete_snapshots ) {
		$this->set_up_private_root();
		try {
			$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
			wp_set_current_user( $user );
			$site_id = $this->create_test_site( array( 'userid' => $user ) );
			$task    = $this->create_task( $site_id );
			MainWP_DB_Backup::instance()->update_backup_task_with_values(
				(int) $task->id,
				array(
					'last_run'  => time() - 100,
					'completed' => time() - 500,
				)
			);
			list( $snapshot_id, $path ) = $this->record_local_snapshot( (int) $task->id, $site_id );

			$response = MainWP_Manage_Backups_Handler::remove_backup_result( $task->id, $delete_snapshots );

			$this->assertSame( 'This backup task is still running. Wait for the run to finish, or use Reset if it is stuck, then delete the task.', $response['error'] ?? null );
			$this->assertNotNull( MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id ) );
			$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id )->status );
			$this->assertFileExists( $path );
		} finally {
			$this->tear_down_private_root();
		}
	}
}
