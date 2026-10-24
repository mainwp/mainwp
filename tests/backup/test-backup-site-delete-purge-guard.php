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
use MainWP\Dashboard\MainWP_Backup_Cloud_Delete_Driver;
use MainWP\Dashboard\MainWP_Backup_Retention_Scheduler;
use MainWP\Dashboard\MainWP_Backup_Storage_Destinations;
use MainWP\Dashboard\MainWP_Backup_Upload_Job_Store;
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

	/** A deleted site's held backups remain owned until the daily purge finishes. */
	public function test_mwp_1962_daily_retry_finishes_deleted_site_purge_after_minimum() {
		global $wpdb;

		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$site_id      = $this->create_test_site( array( 'userid' => $user ) );
		$website      = MainWP_DB::instance()->get_website_by_id( $site_id );
		$task         = MainWP_DB_Backup::instance()->ensure_manual_backup_task( $website );
		$task_id      = (int) $task->id;
		$backup_table = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$wpdb->update(
			$backup_table,
			array(
				'retention_count'      => 0,
				'retention_days'       => 0,
				'retention_storage_mb' => 0,
				'delete_cloud_copies'  => 1,
			),
			array( 'id' => $task_id )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$live_site = $this->create_test_site( array( 'userid' => $user ) );
		$live_task = MainWP_DB_Backup::instance()->ensure_manual_backup_task( MainWP_DB::instance()->get_website_by_id( $live_site ) );
		$wpdb->update( $backup_table, array( 'retention_count' => 0 ), array( 'id' => $live_task->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
		$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_sync' ), array( 'wpid' => $live_site ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a live site can lack its sync row after an interrupted write.

		$dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'mainwp/0/' . $site_id . '/snapshot-site-delete-retry-' . uniqid();
		wp_mkdir_p( $dir );
		$this->fixture_dirs[] = $dir;
		$path                 = $dir . '/archive.tar';
		file_put_contents( $path, 'archive' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		file_put_contents( $dir . '/manifest.json', '{}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		file_put_contents( $dir . '/leftover.bin', 'partial' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		$snapshot_table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$wpdb->insert( $snapshot_table, array( 'task_id' => $task_id, 'site_id' => $site_id, 'type' => 'full', 'created' => time(), 'archives' => wp_json_encode( array( array( 'file' => 'archive.tar', 'size' => 7, 'role' => 'full', 'path' => $path ) ) ), 'manifest_path' => $dir . '/manifest.json', 'status' => 'complete' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.Arrays.ArrayDeclarationSpacing -- test fixture.
		$snapshot_id = (int) $wpdb->insert_id;
		$destination = MainWP_Backup_Storage_Destinations::instance()->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Site deletion retry test',
				'settings' => array(
					'bucket'                        => 'mwp1962',
					'region'                        => 'us-east-1',
					'access_key'                    => 'fixture',
					'secret_key'                    => 'fixture',
					'path_style'                    => true,
					'minimum_remote_retention_days' => 1,
				),
			)
		);
		$store       = MainWP_Backup_Upload_Job_Store::instance();
		$this->assertSame( 1, $store->enqueue_for_snapshot( $snapshot_id, array( $destination ) ) );
		$job        = $store->get_jobs_for_snapshot( $snapshot_id )[0];
		$checkpoint = array(
			'uploaded_at'        => time(),
			'manifest_submitted' => true,
			'artifacts'          => array( array( 'file' => 'archive.tar' ) ),
		);
		$this->assertTrue( $store->save_checkpoint( (int) $job->id, $checkpoint ) );
		$this->assertTrue( $store->complete_job( (int) $job->id ) );

		$prefix        = $site_id . '/' . $snapshot_id . '/';
		$objects       = array_fill_keys( array( $prefix . 'files/archive.tar', $prefix . 'manifest.json', $prefix . 'chunks/partial.bin', 'other-site/manifest.json' ), true );
		$requests      = array();
		$http          = static function ( $pre, $args, $url ) use ( &$objects, &$requests ) {
			$requests[] = $args['method'];
			$body       = '';
			if ( 'DELETE' === $args['method'] ) {
				$key = substr( rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) ), strlen( '/mwp1962/' ) );
				unset( $objects[ $key ] );
			} else {
				parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
				$body = '<ListBucketResult><IsTruncated>false</IsTruncated>';
				foreach ( array_keys( $objects ) as $key ) {
					if ( 0 === strpos( $key, $query['prefix'] ) ) {
						$body .= '<Contents><Key>' . esc_html( $key ) . '</Key><Size>7</Size></Contents>';
					}
				}
				$body .= '</ListBucketResult>';
			}
			return array(
				'headers'  => array(),
				'body'     => $body,
				'response' => array(
					'code'    => 'DELETE' === $args['method'] ? 204 : 200,
					'message' => 'OK',
				),
			);
		};
		$dry_run       = '__return_true';
		$delete_failed = false;
		$refuse_delete = static function ( $query ) use ( $backup_table, $task_id, &$delete_failed ) {
			if ( ! $delete_failed && preg_match( '/^DELETE FROM `?' . preg_quote( $backup_table, '/' ) . '`? WHERE id = ' . $task_id . '\\b/', $query ) ) {
				$delete_failed = true;
				return str_replace( $backup_table, $backup_table . '_missing_mwp1962', $query );
			}
			return $query;
		};
		add_filter( 'pre_http_request', $http, 10, 3 );
		update_option( MainWP_Backup_Retention_Scheduler::CHILD_PRUNE_RESEND_OPTION, time(), false );
		$purged   = 0;
		$listener = static function () use ( &$purged ) {
			++$purged;
		};
		add_action( 'mainwp_backup_task_snapshots_purged', $listener );
		try {
			$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp' ), array( 'id' => $site_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- simulate the already-deleted site.
			$this->assertFalse( MainWP_Post_Backup_Handler::instance()->hook_delete_manual_backup_task( $website ) );
			wp_set_current_user( 0 );
			MainWP_Backup_Retention_Scheduler::instance()->cron_backups_prune();
			$this->assertNotNull( MainWP_DB_Backup::instance()->get_manual_backup_task( $site_id ) );
			$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id )->status );
			$this->assertFileExists( $path );
			$this->assertCount( 4, $objects );
			$this->assertSame( array(), $requests, 'A protected site deletion never contacts a provider.' );
			$this->assertSame( 0, $purged );

			$checkpoint['uploaded_at'] = time() - 2 * DAY_IN_SECONDS;
			$this->assertTrue( $store->save_checkpoint( (int) $job->id, $checkpoint ) );
			add_filter( 'mainwp_backup_retention_dry_run', $dry_run );
			MainWP_Backup_Retention_Scheduler::instance()->cron_backups_prune();
			remove_filter( 'mainwp_backup_retention_dry_run', $dry_run );
			$this->assertFileExists( $path, 'Dry-run cron must keep even an expired deleted-site backup.' );
			$this->assertSame( array(), $store->get_jobs_for_snapshot( $snapshot_id, 'delete' ) );

			add_filter( 'query', $refuse_delete );
			$suppress = $wpdb->suppress_errors();
			try {
				MainWP_Backup_Retention_Scheduler::instance()->cron_backups_prune();
			} finally {
				$wpdb->suppress_errors( $suppress );
				remove_filter( 'query', $refuse_delete );
			}
			$this->assertTrue( $delete_failed, 'The daily retry must reach the task delete even when its retention limits are zero.' );
			$this->assertNotNull( MainWP_DB_Backup::instance()->get_manual_backup_task( $site_id ), 'A refused task delete must leave a retry owner.' );
			$this->assertSame( 0, $purged, 'Diagnostics remain until the task row is confirmed removed.' );
			$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id )->status );
			$this->assertFileDoesNotExist( $path );
			$this->assertDirectoryDoesNotExist( $dir, 'The retry removes the owned manifest and leftover files with the snapshot directory.' );
			$this->assertCount( 1, $store->get_jobs_for_snapshot( $snapshot_id, 'delete' ) );
			$this->assertSame( array(), $store->get_jobs_for_snapshot( $snapshot_id ) );
			wp_mkdir_p( $dir );
			file_put_contents( $dir . '/leftover.bin', 'interrupted cleanup' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- simulate files left beside a pruned snapshot after an interrupted worker.

			MainWP_Backup_Retention_Scheduler::instance()->cron_backups_prune();
			$this->assertDirectoryDoesNotExist( $dir, 'A later purge must finish owned files even when the snapshot is already pruned.' );
			$this->assertNull( MainWP_DB_Backup::instance()->get_manual_backup_task( $site_id ), 'The next daily pass must finish a previously refused task delete.' );
			$this->assertSame( 1, $purged );
			$this->assertNotNull( MainWP_DB_Backup::instance()->get_manual_backup_task( $live_site ), 'An unscoped lookup must preserve a task whose site still exists.' );
			MainWP_Backup_Cloud_Delete_Driver::instance()->process_pending_delete_jobs();
			$this->assertSame( array( 'other-site/manifest.json' => true ), $objects, 'The queued driver removes checkpointed and stray keys only beneath this snapshot.' );
			$this->assertSame( 'complete', $store->get_jobs_for_snapshot( $snapshot_id, 'delete' )[0]->status );
		} finally {
			remove_filter( 'pre_http_request', $http );
			remove_filter( 'mainwp_backup_retention_dry_run', $dry_run );
			remove_filter( 'query', $refuse_delete );
			remove_action( 'mainwp_backup_task_snapshots_purged', $listener );
			delete_option( MainWP_Backup_Retention_Scheduler::CHILD_PRUNE_RESEND_OPTION );
			$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ), array( 'snapshot_id' => $snapshot_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test cleanup.
			MainWP_Backup_Storage_Destinations::instance()->delete_destination( $destination );
		}
	}
}
