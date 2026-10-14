<?php
/**
 * Single-delete admission must survive download and reader interleavings.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Abilities_Backups;
use MainWP\Dashboard\MainWP_Backup_Operations;
use MainWP\Dashboard\MainWP_Backup_Retention_Executor;
use MainWP\Dashboard\MainWP_Backup_Retention_Query;
use MainWP\Dashboard\MainWP_Backup_Restore_Job_Store;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Post_Backup_Handler;

require_once __DIR__ . '/trait-snapshot-delete-fixture.php';

/** Real rows and files expose holds that a manual-task fixture bypasses. */
class MainWP_Backup_Snapshot_Delete_Review_Test extends MainWP_Abilities_Test_Case {
	use Snapshot_Delete_Fixture;

	/**
	 * Capture the list without ending PHPUnit.
	 *
	 * @return array
	 */
	private function snapshot_list() {
		$action   = 'mainwp_restore_snapshot_list';
		$_POST    = array(
			'site_id'  => $this->site_id,
			'security' => wp_create_nonce( $action ),
		);
		$_REQUEST = $_POST;
		$die      = static function () {
			return static function () {
				throw new \RuntimeException( 'snapshot-list-end' );
			};
		};
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $die );
		ob_start();
		try {
			MainWP_Post_Backup_Handler::instance()->mainwp_restore_snapshot_list();
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 'snapshot-list-end', $error->getMessage() );
		} finally {
			$raw = ob_get_clean();
			remove_filter( 'wp_die_ajax_handler', $die );
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}
		return json_decode( $raw, true );
	}

	/**
	 * A sweep claim precedes the downloads and the runner's completion mark.
	 *
	 * @param int $removed Sweep claim.
	 */
	private function start_download( $removed = 1 ) {
		global $wpdb;
		$started = time() + 1;
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			$this->task->id,
			array(
				'last_run'        => $started,
				'completed'       => 0,
				'completed_sites' => '[]',
			)
		);
		$wpdb->insert(
			MainWP_DB::instance()->get_table_name( 'wp_backup_progress' ),
			array(
				'task_id'              => $this->task->id,
				'wp_id'                => $this->site_id,
				'dtsFetched'           => $started,
				'removedFiles'         => $removed,
				'child_phase'          => 'complete',
				'child_backup_task_id' => 'scheduled-download',
			)
		);
		foreach ( MainWP_Backup_Snapshots::instance()->get_snapshots_for_site( $this->site_id ) as $row ) {
			$this->assertLessThan( $started, (int) $row->created );
		}
	}

	/**
	 * All entry points use the same admission evidence.
	 *
	 * @return array
	 */
	public static function admission_paths() {
		return array( array( 'preview' ), array( 'list' ), array( 'confirm' ) );
	}

	/**
	 * No current-run snapshot exists while the scheduled archives download.
	 *
	 * @dataProvider admission_paths
	 * @param string $path Entry point.
	 */
	public function test_scheduled_download_holds_each_entry_point( $path ) {
		$this->use_scheduled_task();
		$id   = $this->snapshot();
		$plan = $this->preview( $id );
		$this->start_download();
		if ( 'preview' === $path ) {
			$this->assertFalse( $this->preview( $id )['can_proceed'] );
		} elseif ( 'list' === $path ) {
			$this->assertTrue( $this->snapshot_list()['snapshot_delete_busy'] );
		} else {
			$result = $this->confirm( $id, $plan );
			$this->assertSame( 'refused', $result['status'] );
			$this->assertSame( array(), $result['deleted_ids'] );
		}
		$row = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id );
		$this->assertSame( 'complete', $row->status );
		$this->assertFileExists( $row->archives[0]['path'] );
	}

	/**
	 * The completion mark also retires a site that finished with an error.
	 *
	 * @return array
	 */
	public static function sweep_claims() {
		return array( array( 0 ), array( 1 ) );
	}

	/**
	 * A paused fleet task must release each finished site independently.
	 *
	 * @dataProvider sweep_claims
	 * @param int $removed Sweep flag.
	 */
	public function test_completed_scheduled_site_releases_all_entry_points( $removed ) {
		$this->use_scheduled_task();
		$id = $this->snapshot();
		$this->start_download( $removed );
		MainWP_DB_Backup::instance()->update_completed_sites( $this->task->id, array( $this->site_id => true ) );
		$plan = $this->preview( $id );
		$this->assertTrue( $plan['can_proceed'] );
		$this->assertFalse( $this->snapshot_list()['snapshot_delete_busy'] );
		$this->assertSame( 'complete', $this->confirm( $id, $plan )['status'] );
	}

	/** A failed task read cannot prove the site is idle. */
	public function test_running_backup_read_failure_holds_site() {
		global $wpdb;
		$table    = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$fail     = static function ( $query ) use ( $table ) {
			return str_contains( $query, 'FROM ' . $table . ' b LEFT JOIN' ) ? 'SELECT snapshot_delete_missing_column FROM ' . $table : $query;
		};
		$suppress = $wpdb->suppress_errors();
		add_filter( 'query', $fail );
		try {
			$this->assertTrue( MainWP_Backup_Retention_Query::instance()->has_running_backup_for_site( $this->site_id ) );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $suppress );
		}
	}

	/** Reader locks survive their reservation timestamp during a long download. */
	public function test_expired_reader_reservation_holds_preview_and_confirm() {
		global $wpdb;
		$id          = $this->snapshot();
		$plan        = $this->preview( $id );
		$store       = MainWP_Backup_Restore_Job_Store::instance();
		$reservation = $store->reserve_site( $this->site_id );
		try {
			$this->assertTrue( $store->renew_reservation( $reservation ) );
			$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ), array( 'locked_until' => time() - 1 ), array( 'id' => $reservation ) );
			$this->assertTrue( $store->site_is_reserved( $this->site_id ) );
			$this->assertSame( 0, $store->reserve_site( $this->site_id ) );
			$this->assertFalse( $this->preview( $id )['can_proceed'] );
			$this->assertTrue( $this->snapshot_list()['snapshot_delete_busy'] );
			$this->assertSame( 'refused', $this->confirm( $id, $plan )['status'] );
		} finally {
			$store->release_reservation( $reservation );
		}
	}

	/**
	 * Daily retention and Clean Up retain their existing result semantics.
	 *
	 * @return array
	 */
	public static function missing_rows() {
		return array(
			array( 'first', 'single_snapshot_delete' ),
			array( 'leaf', 'single_snapshot_delete' ),
			array( 'first', 'beyond_keep' ),
			array( 'leaf', 'manual_cleanup' ),
		);
	}

	/**
	 * Missing rows keep ids remaining, with a reason for single deletion only.
	 *
	 * @dataProvider missing_rows
	 * @param string $missing Missing member.
	 * @param string $reason Prune caller.
	 */
	public function test_missing_prune_row_reports_reason( $missing, $reason ) {
		global $wpdb;
		$base = $this->snapshot();
		$leaf = $this->snapshot( $base );
		$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'id' => 'first' === $missing ? $base : $leaf ) );
		$store       = MainWP_Backup_Restore_Job_Store::instance();
		$reservation = $store->reserve_site( $this->site_id );
		$prune       = new \ReflectionMethod( MainWP_Backup_Retention_Executor::class, 'prune_reserved_candidate' );
		try {
			$result = $prune->invoke(
				MainWP_Backup_Retention_Executor::instance(),
				array(
					'snapshot_ids' => array( $base, $leaf ),
					'reason'       => $reason,
				),
				$this->task
			);
			$this->assertSame( array( $base, $leaf ), $result['remaining_ids'] );
			$this->assertSame( array(), $result['deleted_ids'] );
			$this->assertSame( 'single_snapshot_delete' === $reason ? array( 'Some of these backups were removed by another action. Check the list, then try again.' ) : array(), $result['errors'] );
		} finally {
			$store->release_reservation( $reservation );
		}
	}

	/** A failing refresh must still leave a schema-valid changed result. */
	public function test_changed_set_preview_error_is_null_and_reported() {
		global $wpdb;
		$id   = $this->snapshot();
		$plan = $this->preview( $id );
		$this->snapshot( $id );
		$table    = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$disabled = false;
		$between  = static function ( $query ) use ( $table, &$disabled ) {
			if ( ! $disabled && str_starts_with( $query, 'DELETE FROM ' . $table . ' WHERE id = ' ) ) {
				$disabled = true;
				update_option( 'mainwp_enableNativeBackups', 0 );
			}
			return $query;
		};
		add_filter( 'query', $between );
		try {
			$result = $this->confirm( $id, $plan );
			$this->assertTrue( $disabled, 'The failure must occur after reserved revalidation.' );
			$this->assertSame( 'changed', $result['status'] );
			$this->assertNull( $result['preview'] );
			$this->assertSame( MainWP_Abilities_Backups::cleanup_error( array( 'code' => 'mainwp_backup_legacy_disabled' ) ), $result['errors'][1] );
			$schema = new \ReflectionMethod( MainWP_Abilities_Backups::class, 'delete_backup_snapshot_output_schema' );
			$this->assertTrue( rest_validate_value_from_schema( $result, $schema->invoke( null ) ) );
		} finally {
			remove_filter( 'query', $between );
			update_option( 'mainwp_enableNativeBackups', 1 );
		}
	}
}
