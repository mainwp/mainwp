<?php
/**
 * MainWP Backup Retention Executor Tests
 *
 * The executor carries out a prune list: per snapshot it enqueues cloud
 * delete jobs from the upload checkpoints, hard-deletes the upload rows,
 * removes the local snapshot directory, flips the row to pruned, and
 * fires the logging action once per candidate. Dry-run reports without
 * mutating, and a budget bounds the work per tick.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

require_once __DIR__ . '/trait-backup-private-root-fixture.php';
require_once __DIR__ . '/test-backup-now-continuation.php';
require_once __DIR__ . '/class-mainwp-backup-read-fixtures.php';

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Backup_Private_Root;
use MainWP\Dashboard\MainWP_Backup_Restore_Job_Store;
use MainWP\Dashboard\MainWP_Backup_Retention_Executor;
use MainWP\Dashboard\MainWP_Backup_Retention_Query;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_Backup_Upload_Job_Store;

/**
 * Class MainWP_Backup_Retention_Executor_Test
 */
class MainWP_Backup_Retention_Executor_Test extends MainWP_Abilities_Test_Case {

	use Backup_Cancel_Transport_Guard;

	use Backup_Private_Root_Fixture;
	use Backup_Read_Purity_Guard;

	/**
	 * Isolate native writes.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->set_up_private_root();
	}


	/**
	 * Fixture site id.
	 *
	 * @var int
	 */
	private $site_id = 990010;

	/**
	 * Fixture task id counter base.
	 *
	 * @var int
	 */
	private $task_id = 990010;

	/**
	 * Created fixture dirs for cleanup.
	 *
	 * @var string[]
	 */
	private $fixture_dirs = array();

	/**
	 * Failed and interrupted uploads use one durable first-observation floor.
	 *
	 * @dataProvider unknown_upload_statuses
	 * @param string $status Upload state.
	 */
	public function test_unknown_upload_minimum_has_a_defined_end( string $status ): void {
		list( $id, $job_id, $destination ) = $this->make_unknown_age_upload( $status );
		$protection                        = \MainWP\Dashboard\MainWP_Backup_Retention_Protection::class;
		$now                               = time();
		$first                             = $protection::observe_remote_protection( array( $id ), $now );
		$this->assertSame( $now + DAY_IN_SECONDS, $first['protected_until'] );
		$this->assertFalse( $first['unknown_age'] );
		$this->assertTrue( $protection::holds_automatic_removal( array( $id ), $now + DAY_IN_SECONDS - 1 ) );
		$this->assertFalse( $protection::holds_automatic_removal( array( $id ), $now + 2 * DAY_IN_SECONDS ) );
		$this->assertFalse( $protection::holds_automatic_removal( array( $id ), $now + 1000 * DAY_IN_SECONDS ) );
		$this->assertSame( $now, MainWP_Backup_Upload_Job_Store::instance()->get_job( $job_id )->checkpoint['storage_age_floor'] );
	}

	/**
	 * Upload states without completed age evidence.
	 *
	 * @return array Upload states.
	 */
	public static function unknown_upload_statuses(): array {
		return array(
			'failed'      => array( 'failed_permanent' ),
			'retryable'   => array( 'failed_retryable' ),
			'interrupted' => array( 'uploading' ),
		);
	}

	/** Claims and an upload resuming from an old checkpoint cannot slide the floor. */
	public function test_unknown_upload_floor_survives_prune_claims_and_checkpoint_replacement(): void {
		list( $id, $job_id ) = $this->make_unknown_age_upload( 'failed_retryable' );
		$store               = MainWP_Backup_Upload_Job_Store::instance();
		$stale               = $store->get_job( $job_id )->checkpoint;
		$protection          = \MainWP\Dashboard\MainWP_Backup_Retention_Protection::class;
		$now                 = time();
		$first               = $protection::observe_remote_protection( array( $id ), $now );
		$this->assertSame( $now + DAY_IN_SECONDS, $first['protected_until'] );
		foreach ( array( 'prune:first', 'prune:second' ) as $offset => $token ) {
			MainWP_Backup_Incremental_Sender_Test::$fixed_time = $now + ( 5 + $offset ) * DAY_IN_SECONDS;
			try {
				$this->assertTrue( $store->claim_uploads_for_prune( array( $id ), $token ) );
				$this->assertSame( $first, $protection::remote_protection( array( $id ) ) );
			} finally {
				$store->release_prune_claims( $token );
				MainWP_Backup_Incremental_Sender_Test::$fixed_time = null;
			}
		}
		$this->assertTrue( $store->save_checkpoint( $job_id, $stale ) );
		$this->assertSame( $now, $store->get_job( $job_id )->checkpoint['storage_age_floor'] );
		$this->assertFalse( $protection::holds_automatic_removal( array( $id ), $now + 2 * DAY_IN_SECONDS ) );
	}

	/** The query preview and direct protection expose the same persisted date. */
	public function test_unknown_upload_preview_uses_the_sweep_deadline(): void {
		list( $id ) = $this->make_unknown_age_upload( 'failed_permanent' );
		$protection = \MainWP\Dashboard\MainWP_Backup_Retention_Protection::class;
		$direct     = $protection::observe_remote_protection( array( $id ) );
		$this->assertGreaterThan( time(), $direct['protected_until'] );
		$units = MainWP_Backup_Retention_Query::instance()->get_units_for_site_task( $this->site_id, $this->task_id );
		$this->assertCount( 1, $units );
		$this->assertSame( $direct['protected_until'], $units[0]['protected_until'] );
		$this->assertSame( $direct['unknown_age'], $units[0]['unknown_age'] );
	}

	/**
	 * Previews report unknown age without changing the upload checkpoint.
	 *
	 * @dataProvider unknown_upload_statuses
	 * @param string $status Upload state.
	 */
	public function test_unknown_upload_previews_do_not_record_age( string $status ): void {
		list( $id, $job_id ) = $this->make_unknown_age_upload( $status );
		$store               = MainWP_Backup_Upload_Job_Store::instance();
		$before              = $store->get_job( $job_id );
		$age                 = $this->guarded(
			function () use ( $id ) {
				$protection = \MainWP\Dashboard\MainWP_Backup_Retention_Protection::class;
				$this->assertTrue( $protection::holds_automatic_removal( array( $id ) ) );
				$units = MainWP_Backup_Retention_Query::instance()->get_units_for_site_task( $this->site_id, $this->task_id );
				$this->assertTrue( $units[0]['unknown_age'] );
				MainWP_Backup_Retention_Executor::instance()->purge_snapshots_for_task( $this->task_id, true, false, true, null, true );
				return $protection::remote_protection( array( $id ), null, false );
			}
		);
		$this->assertTrue( $age['unknown_age'] );
		$this->assertSame( 0, $age['protected_until'] );
		$this->assertEquals( $before, $store->get_job( $job_id ) );
	}

	/** Destinations without a minimum never need an upload age checkpoint. */
	public function test_zero_remote_minimum_does_not_record_age_during_execution(): void {
		list( $id, $job_id ) = $this->make_unknown_age_upload( 'failed_permanent', 'complete', 0 );
		$store               = MainWP_Backup_Upload_Job_Store::instance();
		$before              = $store->get_job( $job_id );
		$age                 = $this->guarded(
			static function () use ( $id ) {
				return \MainWP\Dashboard\MainWP_Backup_Retention_Protection::observe_remote_protection( array( $id ) );
			}
		);
		$this->assertFalse( $age['unknown_age'] );
		$this->assertSame( 0, $age['protected_until'] );
		$this->assertEquals( $before, $store->get_job( $job_id ) );
	}

	/** Scheduler dry runs leave unknown age alone; real passes record one floor. */
	public function test_scheduler_records_unknown_age_only_during_execution(): void {
		global $wpdb;
		list( $id, $job_id ) = $this->make_unknown_age_upload( 'failed_permanent' );
		$task                = (object) array(
			'id'                   => $this->task_id,
			'retention_count'      => 1,
			'retention_days'       => 0,
			'retention_storage_mb' => 0,
		);
		$store               = MainWP_Backup_Upload_Job_Store::instance();
		$scheduler           = \MainWP\Dashboard\MainWP_Backup_Retention_Scheduler::instance();
		$now                 = time();
		add_filter( 'mainwp_backup_retention_dry_run', '__return_true' );
		try {
			$this->guarded(
				function () use ( $scheduler, $task, $now ) {
					$scheduler->run_retention_for_pair( $task, $this->site_id, 60, $now );
				}
			);
		} finally {
			remove_filter( 'mainwp_backup_retention_dry_run', '__return_true' );
		}
		$this->assertArrayNotHasKey( 'storage_age_floor', $store->get_job( $job_id )->checkpoint );
		$scheduler->run_retention_for_pair( $task, $this->site_id, 60, $now );
		$this->assertSame( $now, $store->get_job( $job_id )->checkpoint['storage_age_floor'] );
		$scheduler->run_retention_for_pair( $task, $this->site_id, 60, $now + 10 );
		$this->assertSame( $now, $store->get_job( $job_id )->checkpoint['storage_age_floor'] );
		$this->make_snapshot_with_dir( array( 'created' => $now - 50 * DAY_IN_SECONDS ) );
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ), array( 'checkpoint' => wp_json_encode( array( 'storage_age_floor' => $now - 2 * DAY_IN_SECONDS ) ) ), array( 'id' => $job_id ) );
		$this->assertFalse( \MainWP\Dashboard\MainWP_Backup_Retention_Protection::holds_automatic_removal( array( $id ), $now ) );
		add_filter( 'mainwp_backup_retention_dry_run', '__return_true' );
		try {
			$report = $this->guarded(
				function () use ( $scheduler, $task, $now ) {
					return $scheduler->run_retention_for_pair( $task, $this->site_id, 60, $now );
				}
			);
		} finally {
			remove_filter( 'mainwp_backup_retention_dry_run', '__return_true' );
		}
		$this->assertSame( array( array( $id ) ), array_column( $report['candidates'], 'snapshot_ids' ), 'The expired floor must stop blocking policy selection.' );
	}

	/** Deleted-site cleanup releases a unit after its first-observed hold expires. */
	public function test_unknown_upload_deleted_site_purge_releases_after_floor(): void {
		global $wpdb;
		list( $id, $job_id ) = $this->make_unknown_age_upload( 'failed_permanent', 'partial' );
		$protection          = \MainWP\Dashboard\MainWP_Backup_Retention_Protection::class;
		$executor            = MainWP_Backup_Retention_Executor::instance();
		$first               = $executor->purge_snapshots_for_task( $this->task_id, true, false, true );
		$this->assertSame( array( $id ), $first['remaining_ids'] );
		$store = MainWP_Backup_Upload_Job_Store::instance();
		$job   = $store->get_job( $job_id );
		$this->assertGreaterThan( 0, $job->checkpoint['storage_age_floor'] ?? 0 );
		$job->checkpoint['storage_age_floor'] -= 2 * DAY_IN_SECONDS;
		// Advance the fixture's age without waiting two days in real time.
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ), array( 'checkpoint' => wp_json_encode( $job->checkpoint ) ), array( 'id' => $job_id ) );
		$this->assertFalse( $protection::holds_automatic_removal( array( $id ) ) );
		$released = $executor->purge_snapshots_for_task( $this->task_id, true, false, true );
		$this->assertSame( array( $id ), $released['deleted_ids'] );
		$this->assertSame( array(), $released['remaining_ids'] );
		$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id )->status );
		$deletes = $store->get_jobs_for_snapshot( $id, 'delete' );
		$this->assertCount( 1, $deletes );
		$this->assertSame( $job->checkpoint['storage_age_floor'], $deletes[0]->checkpoint['storage_age']['uploaded_at'] );
	}

	/** A failed floor write keeps the upload held until evidence is durable. */
	public function test_unknown_upload_floor_write_failure_stays_held(): void {
		global $wpdb;
		list( $id, $job_id ) = $this->make_unknown_age_upload( 'failed_permanent' );
		$table               = MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' );
		$filter              = static function ( $sql ) use ( $table ) {
			return str_contains( $sql, 'UPDATE ' ) && str_contains( $sql, $table ) && str_contains( $sql, 'storage_age_floor' ) ? 'INVALID FLOOR WRITE' : $sql;
		};
		$old                 = $wpdb->suppress_errors( true );
		add_filter( 'query', $filter );
		try {
			$age = \MainWP\Dashboard\MainWP_Backup_Retention_Protection::observe_remote_protection( array( $id ) );
			$this->assertTrue( $age['unknown_age'] );
			$this->assertSame( 0, $age['protected_until'] );
			$this->assertArrayNotHasKey( 'storage_age_floor', MainWP_Backup_Upload_Job_Store::instance()->get_job( $job_id )->checkpoint );
		} finally {
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $old );
		}
		$this->assertGreaterThan( time(), \MainWP\Dashboard\MainWP_Backup_Retention_Protection::observe_remote_protection( array( $id ) )['protected_until'] );
	}

	/** Large checkpoints retain the existing single-checkpoint packet budget. */
	public function test_unknown_upload_floor_preserves_large_checkpoint_packet_budget(): void {
		global $wpdb;
		list( $id, $job_id ) = $this->make_unknown_age_upload( 'failed_retryable' );
		$store               = MainWP_Backup_Upload_Job_Store::instance();
		$checkpoint          = array( 'opaque_metadata' => str_repeat( 'x', 2200000 ) );
		$this->assertTrue( $store->save_checkpoint( $job_id, $checkpoint ) );
		$job = $store->acquire_next_job( 'packet-worker' );
		$this->assertSame( $job_id, (int) $job->id );
		$table  = MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' );
		$filter = static function ( $sql ) use ( $table ) {
			return str_contains( $sql, 'UPDATE ' ) && str_contains( $sql, $table ) && strlen( $sql ) > 4 * 1024 * 1024 ? 'INVALID PACKET BUDGET' : $sql;
		};
		$old    = $wpdb->suppress_errors( true );
		add_filter( 'query', $filter );
		try {
			$age = \MainWP\Dashboard\MainWP_Backup_Retention_Protection::observe_remote_protection( array( $id ) );
			$this->assertFalse( $age['unknown_age'], 'The first floor must fit the same packet budget as the original checkpoint.' );
			$floor               = $store->get_job( $job_id )->checkpoint['storage_age_floor'];
			$checkpoint['phase'] = 'uploading';
			$this->assertTrue( $store->save_checkpoint( $job_id, $checkpoint, 'packet-worker' ), 'A fenced upload checkpoint must not send the old blob a second time.' );
			$saved = $store->get_job( $job_id )->checkpoint;
			$this->assertSame( $floor, $saved['storage_age_floor'] );
			$this->assertSame( 'uploading', $saved['phase'] );
			$this->assertSame( hash( 'sha256', $checkpoint['opaque_metadata'] ), hash( 'sha256', $saved['opaque_metadata'] ) );
		} finally {
			remove_filter( 'query', $filter );
			$wpdb->suppress_errors( $old );
			$store->release_lock( $job_id, 'packet-worker' );
		}
	}

	/**
	 * Create a real destination, snapshot and unfinished upload row.
	 *
	 * @param string $status Upload state.
	 * @param string $snapshot_status Snapshot state.
	 * @param int    $minimum_days Destination minimum retention period.
	 * @return array Snapshot, job and destination identities.
	 */
	private function make_unknown_age_upload( string $status, string $snapshot_status = 'complete', int $minimum_days = 1 ): array {
		$destination = \MainWP\Dashboard\MainWP_Backup_Storage_Destinations::instance()->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Unknown upload floor',
				'settings' => array( 'minimum_remote_retention_days' => $minimum_days ),
			)
		);
		list( $id )  = $this->make_snapshot_with_dir(
			array(
				'created' => time() - 100 * DAY_IN_SECONDS,
				'status'  => $snapshot_status,
			)
		);
		$store       = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $id, array( $destination ) );
		$job = $store->get_jobs_for_snapshot( $id )[0];
		$store->set_status( (int) $job->id, $status );
		return array( $id, (int) $job->id, $destination );
	}

	/**
	 * Terminal lifecycle rows remain distinct from upload state.
	 *
	 * @return array
	 */
	public static function deleted_site_terminal_statuses(): array {
		return array(
			'failed'  => array( 'failed' ),
			'partial' => array( 'partial' ),
		);
	}

	/**
	 * Deleted-site cleanup uses the existing tombstone and remote-delete lifecycle.
	 *
	 * @dataProvider deleted_site_terminal_statuses
	 * @param string $status Snapshot lifecycle status.
	 */
	public function test_deleted_site_terminal_purge_removes_owned_artifacts( string $status ): void {
		list( $id, $dir ) = $this->make_snapshot_with_dir( array( 'status' => $status ) );
		file_put_contents( $dir . '/files.list', 'manifest' );
		$this->insert_upload_job( $id, 77 );
		$executor = MainWP_Backup_Retention_Executor::instance();
		$this->assertSame( array(), $executor->purge_snapshots_for_task( $this->task_id, true )['candidates'], 'Ordinary task removal keeps terminal rows outside this deleted-site lane.' );
		$this->assertFileExists( $dir . '/artifact.tar' );

		$result = $executor->purge_snapshots_for_task( $this->task_id, true, false, true );
		$this->assertSame( array( $id ), $result['deleted_ids'] );
		$this->assertSame( array(), $result['remaining_ids'] );
		$this->assertSame( array(), $result['errors'] );
		$this->assertDirectoryDoesNotExist( $dir );
		$row = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id );
		$this->assertSame( 'pruned', $row->status );
		$this->assertSame( 'task_deleted', $row->removal_reason );
		$store = MainWP_Backup_Upload_Job_Store::instance();
		$this->assertSame( array(), $store->get_jobs_for_snapshot( $id, 'upload' ) );
		$jobs = $store->get_jobs_for_snapshot( $id, 'delete' );
		$this->assertCount( 1, $jobs );
		$this->assertSame( 77, (int) $jobs[0]->destination_id );
		$this->assertContains( $this->site_id . '/' . $id . '/chunks/aaa111.bin', array_column( $jobs[0]->checkpoint['keys'], 'key' ) );
	}

	/**
	 * A missing sync row does not make an existing site eligible for cleanup.
	 *
	 * @dataProvider deleted_site_terminal_statuses
	 * @param string $status Snapshot lifecycle status.
	 */
	public function test_deleted_site_terminal_purge_keeps_existing_site( string $status ): void {
		global $wpdb;

		$this->set_current_user_as_admin();
		$this->site_id    = $this->create_test_site();
		list( $id, $dir ) = $this->make_snapshot_with_dir( array( 'status' => $status ) );
		$this->insert_upload_job( $id, 77 );
		$this->assertNotNull( MainWP_DB::instance()->get_website_by_id( $this->site_id ) );
		$executor = MainWP_Backup_Retention_Executor::instance();
		$this->assertSame( array(), $executor->purge_snapshots_for_task( $this->task_id, true, false, true )['candidates'] );
		$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_sync' ), array( 'wpid' => $this->site_id ) );
		$this->assertEmpty( MainWP_DB::instance()->get_website_by_id( $this->site_id ) );
		$result = $executor->purge_snapshots_for_task( $this->task_id, true, false, true );
		$this->assertSame( array(), $result['candidates'] );
		$this->assertSame( $status, MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id )->status );
		$this->assertFileExists( $dir . '/artifact.tar' );
		$this->assertCount( 1, MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( $id, 'upload' ) );
		$this->assertSame( array(), MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( $id, 'delete' ) );
	}

	/**
	 * Preview includes terminal rows without modifying uploads, files or traversal.
	 *
	 * @dataProvider deleted_site_terminal_statuses
	 * @param string $status Snapshot lifecycle status.
	 */
	public function test_deleted_site_terminal_purge_dry_run_preserves_state( string $status ): void {
		list( $id, $dir ) = $this->make_snapshot_with_dir( array( 'status' => $status ) );
		$this->insert_upload_job( $id, 77 );
		$store    = MainWP_Backup_Upload_Job_Store::instance();
		$before   = $store->get_jobs_for_snapshot( $id );
		$progress = array(
			'last_task' => 123,
			'tasks'     => array( $this->task_id => 0 ),
		);
		update_option( MainWP_Backup_Retention_Executor::PURGE_CURSOR_OPTION, $progress, false );
		try {
			$result = MainWP_Backup_Retention_Executor::instance()->purge_snapshots_for_task( $this->task_id, true, false, true, 0.0, true );
			$this->assertSame(
				array(
					array(
						'snapshot_ids' => array( $id ),
						'reason'       => 'task_deleted',
					),
				),
				$result['candidates']
			);
			$this->assertSame( 1, $result['local'] );
			$this->assertSame( array( $id ), $result['remaining_ids'] );
			$this->assertSame( 0, $result['attempted'] );
			$this->assertSame( array(), $result['deleted_ids'] );
			$this->assertSame( $progress, get_option( MainWP_Backup_Retention_Executor::PURGE_CURSOR_OPTION ) );
			$this->assertEquals( $before, $store->get_jobs_for_snapshot( $id ) );
			$this->assertSame( $status, MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id )->status );
			$this->assertFileExists( $dir . '/artifact.tar' );
		} finally {
			delete_option( MainWP_Backup_Retention_Executor::PURGE_CURSOR_OPTION );
		}
	}

	/**
	 * An invalid site ID is not a deleted-site cleanup candidate.
	 *
	 * @dataProvider deleted_site_terminal_statuses
	 * @param string $status Snapshot lifecycle status.
	 */
	public function test_deleted_site_terminal_purge_excludes_zero_site_id( string $status ): void {
		global $wpdb;

		list( $id, $dir ) = $this->make_snapshot_with_dir( array( 'status' => $status ) );
		$this->assertSame( 1, $wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'site_id' => 0 ), array( 'id' => $id ) ) );
		$this->insert_upload_job( $id, 77 );
		$store    = MainWP_Backup_Upload_Job_Store::instance();
		$before   = $store->get_jobs_for_snapshot( $id );
		$progress = array( 'last_task' => 123 );
		update_option( MainWP_Backup_Retention_Executor::PURGE_CURSOR_OPTION, $progress, false );
		try {
			foreach ( array( true, false ) as $dry_run ) {
				$result = MainWP_Backup_Retention_Executor::instance()->purge_snapshots_for_task( $this->task_id, true, false, true, 0.0, $dry_run );
				$this->assertSame( array(), $result['candidates'], 'A site ID of zero must stay outside the deleted-site inventory.' );
				$this->assertSame( array(), $result['remaining_ids'] );
				$this->assertSame( 0, $result['attempted'] );
				$this->assertSame( array(), $result['deleted_ids'] );
				$this->assertSame( $progress, get_option( MainWP_Backup_Retention_Executor::PURGE_CURSOR_OPTION ) );
				$this->assertSame( $status, MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id )->status );
				$this->assertFileExists( $dir . '/artifact.tar' );
				$this->assertEquals( $before, $store->get_jobs_for_snapshot( $id ) );
			}
		} finally {
			delete_option( MainWP_Backup_Retention_Executor::PURGE_CURSOR_OPTION );
		}
	}

	/** In-progress rows remain unselected, as on the parent deleted-site purge. */
	public function test_deleted_site_terminal_purge_keeps_inprogress_snapshots(): void {
		list( $queued, $queued_dir )   = $this->make_snapshot_with_dir( array( 'status' => 'queued' ) );
		list( $running, $running_dir ) = $this->make_snapshot_with_dir( array( 'status' => 'running' ) );
		$this->insert_upload_job( $queued, 77 );
		$this->insert_upload_job( $running, 77 );
		$store    = MainWP_Backup_Upload_Job_Store::instance();
		$uploads  = array( $store->get_jobs_for_snapshot( $queued ), $store->get_jobs_for_snapshot( $running ) );
		$progress = array( 'last_task' => 123 );
		update_option( MainWP_Backup_Retention_Executor::PURGE_CURSOR_OPTION, $progress, false );
		try {
			foreach ( array( true, false ) as $dry_run ) {
				$result = MainWP_Backup_Retention_Executor::instance()->purge_snapshots_for_task( $this->task_id, true, false, true, 0.0, $dry_run );
				$this->assertSame( array(), $result['candidates'] );
				$this->assertSame( array(), $result['remaining_ids'] );
				$this->assertSame( 0, $result['attempted'] );
				$this->assertSame( array(), $result['deleted_ids'] );
				$this->assertSame( $progress, get_option( MainWP_Backup_Retention_Executor::PURGE_CURSOR_OPTION ) );
				$this->assertSame( 'queued', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $queued )->status );
				$this->assertSame( 'running', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $running )->status );
				$this->assertFileExists( $queued_dir . '/artifact.tar' );
				$this->assertFileExists( $running_dir . '/artifact.tar' );
				$this->assertEquals( $uploads, array( $store->get_jobs_for_snapshot( $queued ), $store->get_jobs_for_snapshot( $running ) ) );
			}
		} finally {
			delete_option( MainWP_Backup_Retention_Executor::PURGE_CURSOR_OPTION );
		}
	}

	/**
	 * A failed-only or partial-only hidden task survives a minimum hold and retries.
	 *
	 * @dataProvider deleted_site_terminal_statuses
	 * @param string $status Snapshot lifecycle status.
	 */
	public function test_deleted_site_terminal_purge_daily_retries_after_remote_minimum( string $status ): void {
		global $wpdb;

		$task          = \MainWP\Dashboard\MainWP_DB_Backup::instance()->ensure_manual_backup_task( (object) array( 'id' => $this->site_id ) );
		$this->task_id = (int) $task->id;
		$wpdb->update(
			MainWP_DB::instance()->get_table_name( 'wp_backup' ),
			array(
				'retention_count'      => 0,
				'retention_days'       => 0,
				'retention_storage_mb' => 0,
				'delete_cloud_copies'  => 1,
			),
			array( 'id' => $this->task_id )
		);
		list( $id, $dir ) = $this->make_snapshot_with_dir( array( 'status' => $status ) );
		$destinations     = \MainWP\Dashboard\MainWP_Backup_Storage_Destinations::instance();
		$destination      = $destinations->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Terminal cleanup minimum',
				'settings' => array( 'minimum_remote_retention_days' => 1 ),
			)
		);
		$store            = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $id, array( $destination ) );
		$job        = $store->get_jobs_for_snapshot( $id )[0];
		$checkpoint = array(
			'uploaded_at'        => time(),
			'manifest_submitted' => true,
			'artifacts'          => array( array( 'file' => 'artifact.tar' ) ),
		);
		$this->assertTrue( $store->save_checkpoint( (int) $job->id, $checkpoint ) );
		$this->assertTrue( $store->complete_job( (int) $job->id ) );
		update_option( 'mainwp_enableNativeBackups', 1 );
		update_option( \MainWP\Dashboard\MainWP_Backup_Retention_Scheduler::CHILD_PRUNE_RESEND_OPTION, time(), false );
		try {
			$this->assertFalse(
				\MainWP\Dashboard\MainWP_Post_Backup_Handler::instance()->hook_delete_manual_backup_task(
					(object) array(
						'id'   => $this->site_id,
						'name' => '',
						'url'  => '',
					)
				)
			);
			\MainWP\Dashboard\MainWP_Backup_Retention_Scheduler::instance()->cron_backups_prune();
			$this->assertNotNull( \MainWP\Dashboard\MainWP_DB_Backup::instance()->get_backup_task_by_id( $this->task_id ) );
			$this->assertSame( $status, MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id )->status );
			$this->assertFileExists( $dir . '/artifact.tar' );
			$this->assertCount( 1, $store->get_jobs_for_snapshot( $id, 'upload' ) );
			$this->assertSame( array(), $store->get_jobs_for_snapshot( $id, 'delete' ) );
			$checkpoint['uploaded_at'] = time() - 2 * DAY_IN_SECONDS;
			$this->assertTrue( $store->save_checkpoint( (int) $job->id, $checkpoint ) );
			\MainWP\Dashboard\MainWP_Backup_Retention_Scheduler::instance()->cron_backups_prune();
			$this->assertNull( \MainWP\Dashboard\MainWP_DB_Backup::instance()->get_backup_task_by_id( $this->task_id ) );
			$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id )->status );
			$this->assertDirectoryDoesNotExist( $dir );
			$this->assertSame( array(), $store->get_jobs_for_snapshot( $id, 'upload' ) );
			$this->assertCount( 1, $store->get_jobs_for_snapshot( $id, 'delete' ) );
		} finally {
			$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup' ), array( 'id' => $this->task_id ) );
			$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ), array( 'destination_id' => $destination ) );
			$destinations->delete_destination( $destination );
			delete_option( 'mainwp_enableNativeBackups' );
			delete_option( MainWP_Backup_Retention_Executor::PURGE_CURSOR_OPTION );
		}
	}

	/** An unreadable site inventory must keep terminal rows and their artifacts. */
	public function test_deleted_site_terminal_purge_inventory_failure_keeps_files(): void {
		global $wpdb;

		list( $failed, $failed_dir )   = $this->make_snapshot_with_dir( array( 'status' => 'failed' ) );
		list( $partial, $partial_dir ) = $this->make_snapshot_with_dir( array( 'status' => 'partial' ) );
		$site_table                    = MainWP_DB::instance()->get_table_name( 'wp' );
		$deny                          = static function ( $sql ) use ( $site_table ) {
			return false !== strpos( $sql, 'LEFT JOIN `' . $site_table . '`' ) ? 'SELECT mainwp_terminal_purge_inventory_failure' : $sql;
		};
		$suppress                      = $wpdb->suppress_errors();
		add_filter( 'query', $deny );
		try {
			$result = MainWP_Backup_Retention_Executor::instance()->purge_snapshots_for_task( $this->task_id, true, false, true );
		} finally {
			remove_filter( 'query', $deny );
			$wpdb->suppress_errors( $suppress );
		}
		$this->assertNotEmpty( $result['errors'], 'An unreadable actual site table must stop cleanup.' );
		$this->assertSame( array(), $result['candidates'] );
		$this->assertSame( 'failed', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $failed )->status );
		$this->assertSame( 'partial', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $partial )->status );
		$this->assertFileExists( $failed_dir . '/artifact.tar' );
		$this->assertFileExists( $partial_dir . '/artifact.tar' );
	}

	/** Terminal rows share the same deadline and resume cursor as complete history. */
	public function test_deleted_site_terminal_purge_resumes_under_expired_budget(): void {
		list( $failed, $failed_dir )                          = $this->make_snapshot_with_dir( array( 'status' => 'failed' ) );
		list( $complete, $complete_dir )                      = $this->make_snapshot_with_dir();
		list( $partial, $partial_dir )                        = $this->make_snapshot_with_dir( array( 'status' => 'partial' ) );
		MainWP_Backup_Now_Continuation_Test::$fixed_microtime = 1000.0;
		try {
			$executor = MainWP_Backup_Retention_Executor::instance();
			$first    = $executor->purge_snapshots_for_task( $this->task_id, false, false, true, 999.0 );
			$this->assertSame( array( $failed ), $first['deleted_ids'] );
			$this->assertSame( array( $complete, $partial ), $first['remaining_ids'] );
			$this->assertSame( 2, $first['skipped'] );
			$this->assertFileExists( $complete_dir . '/artifact.tar' );
			$this->assertFileExists( $partial_dir . '/artifact.tar' );
			$second = $executor->purge_snapshots_for_task( $this->task_id, false, false, true, 999.0 );
			$this->assertSame( array( $complete ), $second['deleted_ids'] );
			$third = $executor->purge_snapshots_for_task( $this->task_id, false, false, true, 999.0 );
			$this->assertSame( array( $partial ), $third['deleted_ids'] );
			$this->assertSame( array(), $third['remaining_ids'] );
			$this->assertDirectoryDoesNotExist( $failed_dir );
			$this->assertDirectoryDoesNotExist( $complete_dir );
			$this->assertDirectoryDoesNotExist( $partial_dir );
		} finally {
			MainWP_Backup_Now_Continuation_Test::$fixed_microtime = null;
			delete_option( MainWP_Backup_Retention_Executor::PURGE_CURSOR_OPTION );
		}
	}

	/**
	 * Terminal snapshot status cannot establish the age of a failed upload.
	 *
	 * @dataProvider deleted_site_terminal_statuses
	 * @param string $status Snapshot lifecycle status.
	 */
	public function test_deleted_site_terminal_purge_keeps_unknown_age_upload( string $status ): void {
		list( $id, $dir ) = $this->make_snapshot_with_dir(
			array(
				'status'  => $status,
				'created' => time() - 100 * DAY_IN_SECONDS,
			)
		);
		$destinations     = \MainWP\Dashboard\MainWP_Backup_Storage_Destinations::instance();
		$destination      = $destinations->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Terminal unknown age',
				'settings' => array( 'minimum_remote_retention_days' => 1 ),
			)
		);
		$store            = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $id, array( $destination ) );
		$store->fail_permanent( (int) $store->get_jobs_for_snapshot( $id )[0]->id, 'Partial remote write' );
		try {
			$result = MainWP_Backup_Retention_Executor::instance()->purge_snapshots_for_task( $this->task_id, true, false, true );
			$this->assertSame( array( $id ), $result['remaining_ids'] );
			$this->assertNotEmpty( $result['errors'] );
			$this->assertSame( $status, MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id )->status );
			$this->assertFileExists( $dir . '/artifact.tar' );
			$this->assertCount( 1, $store->get_jobs_for_snapshot( $id, 'upload' ) );
			$this->assertSame( array(), $store->get_jobs_for_snapshot( $id, 'delete' ) );
		} finally {
			$destinations->delete_destination( $destination );
		}
	}


	/** Settled tombstones spend no attempt; pending cleanup shares the ordered traversal. */
	public function test_deleted_task_purge_rotates_complete_and_unfinished_pruned_work() {
		global $wpdb;

		$protection                    = \MainWP\Dashboard\MainWP_Backup_Retention_Protection::class;
		list( $settled, $settled_dir ) = $this->make_snapshot_with_dir( array( 'status' => 'pruned' ) );
		unlink( $settled_dir . '/artifact.tar' );
		rmdir( $settled_dir );
		$archives = array(
			array(
				'path'                       => $settled_dir . '/artifact.tar',
				'retention_requires_uploads' => true,
			),
		);
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'archives' => wp_json_encode( $archives ) ), array( 'id' => $settled ) );
		list( $complete, $complete_dir ) = $this->make_snapshot_with_dir();
		list( $pending, $pending_dir )   = $this->make_snapshot_with_dir( array( 'status' => 'pruned' ) );
		update_option( $protection::REQUIRED_OPTION_PREFIX . $pending, array( 123 ), false );
		MainWP_Backup_Now_Continuation_Test::$fixed_microtime = 1000.0;
		try {
			$executor = MainWP_Backup_Retention_Executor::instance();
			$first    = $executor->purge_snapshots_for_task( $this->task_id, false, false, true, 999.0 );
			$this->assertSame( array( $complete ), $first['deleted_ids'], 'The settled descriptor flag must not consume the guaranteed attempt.' );
			$this->assertSame( array( $pending ), $first['remaining_ids'], 'An unfinished tombstone remains pending even with no complete site IDs.' );
			$this->assertSame( 1, $first['skipped'] );
			$this->assertFileDoesNotExist( $complete_dir . '/artifact.tar' );
			$this->assertFileExists( $pending_dir . '/artifact.tar' );
			$this->assertSame( array( 123 ), get_option( $protection::REQUIRED_OPTION_PREFIX . $pending ) );
			$second = $executor->purge_snapshots_for_task( $this->task_id, false, false, true, 999.0 );
			$this->assertSame( array( $pending ), $second['candidates'][0]['snapshot_ids'] );
			$this->assertSame( array(), $second['remaining_ids'] );
			$this->assertFileDoesNotExist( $pending_dir . '/artifact.tar' );
			$this->assertFalse( get_option( $protection::REQUIRED_OPTION_PREFIX . $pending ) );
			$progress = get_option( 'mainwp_backup_deleted_task_cursor' );
			$this->assertSame( $pending, $progress['tasks'][ $this->task_id ] );
			$this->assertSame( $this->task_id, $progress['last_task'] );
			$this->assertSame( 'off', $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'mainwp_backup_deleted_task_cursor' ) ) );
		} finally {
			MainWP_Backup_Now_Continuation_Test::$fixed_microtime = null;
			delete_option( 'mainwp_backup_deleted_task_cursor' );
			delete_option( $protection::REQUIRED_OPTION_PREFIX . $pending );
		}
	}

	/** A purge preview includes complete chains and tombstones without cleaning either. */
	public function test_deleted_task_purge_dry_run_returns_candidates_without_cleanup() {
		list( $base, $base_dir )       = $this->make_snapshot_with_dir();
		list( $leaf, $leaf_dir )       = $this->make_snapshot_with_dir(
			array(
				'parent_id' => $base,
				'type'      => 'incremental',
			)
		);
		list( $pending, $pending_dir ) = $this->make_snapshot_with_dir( array( 'status' => 'pruned' ) );
		$result                        = MainWP_Backup_Retention_Executor::instance()->purge_snapshots_for_task( $this->task_id, true, false, true, null, true );
		$this->assertTrue( $result['dry_run'] );
		$this->assertSame(
			array(
				array(
					'snapshot_ids' => array( $base, $leaf ),
					'reason'       => 'task_deleted',
				),
				array(
					'snapshot_ids' => array( $pending ),
					'reason'       => 'task_deleted',
				),
			),
			$result['candidates']
		);
		$this->assertSame( array(), $result['deleted_ids'] );
		$this->assertSame( array(), $result['cloud_deletes_queued'] );
		$this->assertFalse( get_option( 'mainwp_backup_deleted_task_cursor' ) );
		foreach ( array( $base_dir, $leaf_dir, $pending_dir ) as $dir ) {
			$this->assertFileExists( $dir . '/artifact.tar' );
		}
	}

	/** Remote preview counts cleanup targets only when cloud deletion is enabled. */
	public function test_deleted_task_dry_run_remote_targets_exclude_local_jobs() {
		global $wpdb;

		list( $id ) = $this->make_snapshot_with_dir();
		$table      = MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' );
		foreach ( array(
			77 => 'local',
			78 => 's3',
			79 => 's3',
		) as $destination => $provider ) {
			$this->insert_upload_job( $id, $destination );
			$wpdb->update(
				$table,
				array( 'provider' => $provider ),
				array(
					'snapshot_id'    => $id,
					'destination_id' => $destination,
				)
			);
		}
		$executor = MainWP_Backup_Retention_Executor::instance();
		$this->assertSame( 2, $executor->purge_snapshots_for_task( $this->task_id, true, false, true, null, true )['remote'] );
		$this->assertSame( 0, $executor->purge_snapshots_for_task( $this->task_id, false, false, true, null, true )['remote'] );
		$this->assertCount( 3, MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( $id ) );
		$this->assertSame( array(), MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( $id, 'delete' ) );
	}

	/** Pruned upload records add no remote work to the purge preview. */
	public function test_deleted_task_dry_run_excludes_pruned_remote_targets() {
		global $wpdb;

		list( $id, $dir ) = $this->make_snapshot_with_dir( array( 'status' => 'pruned' ) );
		$this->insert_upload_job( $id, 78 );
		$wpdb->update(
			MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ),
			array( 'provider' => 's3' ),
			array( 'snapshot_id' => $id )
		);
		$store    = MainWP_Backup_Upload_Job_Store::instance();
		$executor = MainWP_Backup_Retention_Executor::instance();
		$preview  = $executor->purge_snapshots_for_task( $this->task_id, true, false, true, null, true );
		$this->assertSame( 0, $preview['remote'] );
		$this->assertSame( 1, $preview['local'] );
		$this->assertCount( 1, $store->get_jobs_for_snapshot( $id ) );
		$this->assertFileExists( $dir . '/artifact.tar' );
		$actual = $executor->purge_snapshots_for_task( $this->task_id, true, false, true );
		$this->assertSame( array(), $actual['errors'] );
		$this->assertSame( array(), $actual['cloud_deletes_queued'] );
		$this->assertSame( array(), $store->get_jobs_for_snapshot( $id, MainWP_Backup_Upload_Job_Store::KIND_DELETE ) );
		$this->assertSame( array(), $store->get_jobs_for_snapshot( $id ) );
		$this->assertFileDoesNotExist( $dir . '/artifact.tar' );
	}

	/**
	 * Protected shared artifacts are settled for a deleted task's pruned row.
	 *
	 * @dataProvider b03_shared_artifacts
	 * @param bool $snapshot_dir Whether another artifact protects the snapshot directory.
	 */
	public function test_deleted_task_purge_shared_pruned_artifacts_are_settled( $snapshot_dir ) {
		list( $pruned, $dir ) = $this->make_snapshot_with_dir( array( 'status' => 'pruned' ) );
		$row                  = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $pruned );
		$kept_path            = $snapshot_dir ? $dir . '/live.tar' : $dir . '//artifact.tar';
		if ( $snapshot_dir ) {
			file_put_contents( $kept_path, 'retained bytes' );
		}
		$row->archives[0]['path'] = $kept_path;
		list( $live )             = $this->make_snapshot_with_dir(
			array(
				'task_id'       => $this->task_id + 1,
				'archives'      => wp_json_encode( $row->archives ),
				'manifest_path' => '',
			)
		);
		$result                   = MainWP_Backup_Retention_Executor::instance()->purge_snapshots_for_task( $this->task_id, false, false, true, 0.0 );
		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( array(), $result['remaining_ids'] );
		$this->assertSame( 0, $result['attempted'], 'Another snapshot owns this file, so it spends no cleanup attempt.' );
		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $live )->status );
		$this->assertFileExists( $dir . '/artifact.tar' );
		$this->assertFileExists( $kept_path );
	}

	/**
	 * Clean fixture rows and dirs.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wpdb;

		$snapshot_table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$snapshot_table} WHERE site_id = %d", $this->site_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.

		$job_table = MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' );
		$wpdb->query( "DELETE FROM {$job_table} WHERE snapshot_id >= 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.

		$restore_table = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$restore_table} WHERE site_id = %d", $this->site_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.

		foreach ( $this->fixture_dirs as $dir ) {
			if ( is_dir( $dir ) ) {
				array_map( 'unlink', glob( $dir . '/*' ) ?: array() ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test cleanup.
				rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- test cleanup.
			}
		}
		$this->fixture_dirs = array();

		$this->tear_down_private_root();
		parent::tear_down();
	}

	/**
	 * Build a task row object with retention fields.
	 *
	 * @param array $fields Field overrides.
	 *
	 * @return object
	 */
	private function make_task( array $fields = array() ) {
		return (object) array_merge(
			array(
				'id'                  => $this->task_id,
				'delete_cloud_copies' => 1,
			),
			$fields
		);
	}

	/**
	 * Create a local per-snapshot dir (the snapshot-{child_task_id} layout
	 * incremental runs use; full and database archives live in the shared
	 * site folder, see make_production_layout_snapshot()) with artifact files
	 * and record a snapshot row over them.
	 *
	 * @param array  $fields   Snapshot row overrides.
	 * @param string $site_dir Site storage dir to create the snapshot under; the private root when empty.
	 *
	 * @return array{0: int, 1: string} Snapshot id and its local dir.
	 */
	private function make_snapshot_with_dir( array $fields = array(), string $site_dir = '' ) {
		global $wpdb;

		$upload  = wp_upload_dir();
		$site_id = isset( $fields['site_id'] ) ? (int) $fields['site_id'] : $this->site_id;
		$dir     = ( '' !== $site_dir ? $site_dir : \MainWP\Dashboard\MainWP_Backup_Private_Root::site_dir( $site_id ) ) . 'snapshot-task-reten-' . uniqid();
		wp_mkdir_p( $dir );
		$this->fixture_dirs[] = $dir;

		$path = $dir . '/artifact.tar';
		file_put_contents( $path, str_repeat( 'x', 100 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			$table,
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
	 * Cleanup's default and explicit count must select the same units as its preview.
	 *
	 * @dataProvider cleanup_count_cases
	 * @param int|null $keep Explicit override.
	 * @param int      $count Expected retained count.
	 */
	public function test_cleanup_and_preview_use_live_hidden_count_with_override( $keep, $count ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'mainwp_enableNativeBackups', 1 );
		update_option( 'mainwp_on_demand_retention_count', 5 );
		$this->site_id = $this->create_test_site();
		$db            = \MainWP\Dashboard\MainWP_DB_Backup::instance();
		$task          = $db->ensure_manual_backup_task( MainWP_DB::instance()->get_website_by_id( $this->site_id ) );
		$db->update_backup_task_with_values( $task->id, array( 'retention_count' => 25 ) );
		$ids = array();
		for ( $index = 0; $index < 7; ++$index ) {
			list( $ids[] ) = $this->make_snapshot_with_dir(
				array(
					'task_id' => $task->id,
					'created' => time() - 700 + 100 * $index,
				)
			);
		}
		$executor = MainWP_Backup_Retention_Executor::instance();
		$plan     = $executor->plan_site_cleanup( $this->site_id, $keep );
		$preview  = \MainWP\Dashboard\MainWP_Backup_Operations::preview_site_cleanup( $this->site_id, $keep );
		$this->assertIsArray( $preview );
		$this->assertSame( $count, $preview['would_affect']['keep'] );
		$this->assertSame( $plan['on_demand_units'], $preview['would_affect']['on_demand_units'] );
		$selected = array_merge( array(), ...array_column( $plan['on_demand_units'], 'snapshot_ids' ) );
		sort( $selected );
		$this->assertSame( array_slice( $ids, 0, 7 - $count ), $selected );
		$result = $executor->cleanup_site_backups( $this->site_id, $keep );
		$this->assertSame( 7 - $count, $result['pruned'] );
		foreach ( $ids as $index => $id ) {
			$this->assertSame( $index < 7 - $count ? 'pruned' : 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id )->status );
		}
		delete_option( 'mainwp_on_demand_retention_count' );
	}

	/**
	 * Cleanup count precedence.
	 *
	 * @return array
	 */
	public static function cleanup_count_cases() {
		return array(
			'live default'      => array( null, 5 ),
			'explicit override' => array( 6, 6 ),
		);
	}

	/**
	 * Scheduler and public cleanup previews agree on damage-aware coverage.
	 *
	 * @dataProvider damaged_retention_cases
	 * @param string $path Retention entry point.
	 * @param string $copies Available archive copies.
	 */
	public function test_damaged_archive_retention_preview_and_execution( string $path, string $copies ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'mainwp_enableNativeBackups', 1 );
		update_option( 'mainwp_on_demand_retention_count', 1 );
		$this->site_id = $this->create_test_site();
		$db            = \MainWP\Dashboard\MainWP_DB_Backup::instance();
		$task          = $db->ensure_manual_backup_task( MainWP_DB::instance()->get_website_by_id( $this->site_id ) );
		$db->update_backup_task_with_values( $task->id, array( 'retention_count' => 25 ) );
		$task                  = $db->get_backup_task_by_id( $task->id );
		list( $old, $old_dir ) = $this->make_snapshot_with_dir(
			array(
				'task_id' => $task->id,
				'created' => time() - 200,
			)
		);
		list( $new, $new_dir ) = $this->make_snapshot_with_dir(
			array(
				'task_id' => $task->id,
				'created' => time() - 100,
			)
		);
		$snapshots             = MainWP_Backup_Snapshots::instance();
		$snapshots->mark_artifact_damaged( $new, 'artifact.tar', 'checksum_mismatch' );
		if ( 'all damaged' === $copies ) {
			$snapshots->mark_artifact_damaged( $old, 'artifact.tar', 'checksum_mismatch' );
		}
		$destination = null;
		if ( 'cloud' === $copies ) {
			$destination = $this->make_retention_destination();
			$store       = MainWP_Backup_Upload_Job_Store::instance();
			$store->enqueue_for_snapshot( $new, array( $destination ) );
			$job = $store->get_jobs_for_snapshot( $new )[0];
			$this->assertTrue( $store->save_checkpoint( $job->id, array( 'artifacts' => $this->verified_artifacts( $new ) ) ) );
			$this->assertTrue( $store->complete_job( $job->id ) );
			$catalog = \MainWP\Dashboard\MainWP_Backup_Snapshot_Catalog::instance()->assemble_plan( array( $snapshots->get_snapshot_by_id( $new ) ) );
			$this->assertSame( 'remote', $catalog[0]['source'] );
		}
		$expected = 'cloud' === $copies ? array( $old ) : array();
		try {
			$units = MainWP_Backup_Retention_Query::instance()->get_units_for_site_task( $this->site_id, (int) $task->id );
			$this->assertSame( array( 'all damaged' !== $copies, 'cloud' === $copies ), array_column( $units, 'is_restorable' ) );
			$this->assertTrue( MainWP_Backup_Snapshots::snapshot_has_damage( $snapshots->get_snapshot_by_id( $new ) ) );
			if ( 'scheduler' === $path ) {
				add_filter( 'mainwp_backup_retention_dry_run', '__return_true' );
				try {
					$preview = \MainWP\Dashboard\MainWP_Backup_Retention_Scheduler::instance()->run_retention_for_pair( $task, $this->site_id );
				} finally {
					remove_filter( 'mainwp_backup_retention_dry_run', '__return_true' );
				}
				$selected = array_merge( array(), ...array_column( $preview['candidates'], 'snapshot_ids' ) );
			} else {
				$preview = \MainWP\Dashboard\MainWP_Backup_Operations::preview_site_cleanup( $this->site_id, null );
				$this->assertNotWPError( $preview );
				$selected = array_merge( array(), ...array_column( $preview['would_affect']['on_demand_units'], 'snapshot_ids' ) );
				$dry_run  = \MainWP\Dashboard\MainWP_Backup_Operations::cleanup_site_backups( $this->site_id, null, true );
				$this->assertNotWPError( $dry_run );
				$this->assertSame( $preview['would_affect']['on_demand_units'], $dry_run['on_demand_units'] );
			}
			$this->assertSame( $expected, $selected );
			foreach ( array(
				$old => $old_dir,
				$new => $new_dir,
			) as $id => $dir ) {
				$this->assertSame( 'complete', $snapshots->get_snapshot_by_id( $id )->status );
				$this->assertFileExists( $dir . '/artifact.tar' );
			}
			$result = 'scheduler' === $path
				? \MainWP\Dashboard\MainWP_Backup_Retention_Scheduler::instance()->run_retention_for_pair( $task, $this->site_id )
				: \MainWP\Dashboard\MainWP_Backup_Operations::cleanup_site_backups( $this->site_id, null );
			$this->assertNotWPError( $result );
			$this->assertSame( array(), $result['errors'] );
			$this->assertSame( $selected, $result['deleted_ids'] );
			foreach ( array(
				$old => $old_dir,
				$new => $new_dir,
			) as $id => $dir ) {
				$pruned = in_array( $id, $expected, true );
				$this->assertSame( $pruned ? 'pruned' : 'complete', $snapshots->get_snapshot_by_id( $id )->status );
				if ( $pruned ) {
					$this->assertFileDoesNotExist( $dir . '/artifact.tar' );
				} else {
					$this->assertFileExists( $dir . '/artifact.tar' );
				}
			}
		} finally {
			$db->remove_backup_task( $task->id );
			if ( null !== $destination ) {
				\MainWP\Dashboard\MainWP_Backup_Storage_Destinations::instance()->delete_destination( $destination );
			}
			delete_option( 'mainwp_on_demand_retention_count' );
		}
	}

	/**
	 * Coverage combinations through both retention entry points.
	 *
	 * @return array
	 */
	public static function damaged_retention_cases() {
		$cases = array();
		foreach ( array( 'scheduler', 'cleanup' ) as $path ) {
			foreach ( array( 'local', 'cloud', 'all damaged' ) as $copies ) {
				$cases[ $path . ': ' . $copies ] = array( $path, $copies );
			}
		}
		return $cases;
	}

	/** An open incremental run keeps its parent units despite a lowered setting. */
	public function test_live_hidden_count_preserves_units_held_by_open_run() {
		$db   = \MainWP\Dashboard\MainWP_DB_Backup::instance();
		$task = $db->add_backup_task( 1, 'Open retention run', 'manual', 'incremental', '', (string) $this->site_id, '', '', '', 1, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0, $this->site_id );
		$run  = time();
		$db->update_backup_task_with_values(
			$task->id,
			array(
				'last_run'        => $run,
				'completed'       => 0,
				'retention_count' => 25,
			)
		);
		$db->add_backup_task_progress( $task->id, $this->site_id, array() );
		$db->update_backup_task_progress(
			$task->id,
			$this->site_id,
			array(
				'dtsFetched'           => $run,
				'child_backup_task_id' => 'open-retention-run',
				'child_phase'          => '',
			)
		);
		update_option( 'mainwp_on_demand_retention_count', 1 );
		list( $old, $dir ) = $this->make_snapshot_with_dir(
			array(
				'task_id' => $task->id,
				'created' => $run - 200,
			)
		);
		list( $new )       = $this->make_snapshot_with_dir(
			array(
				'task_id' => $task->id,
				'created' => $run - 100,
			)
		);
		$task              = $db->get_backup_task_by_id( $task->id );
		try {
			$daily   = \MainWP\Dashboard\MainWP_Backup_Retention_Scheduler::instance()->run_retention_for_pair( $task, $this->site_id );
			$cleanup = MainWP_Backup_Retention_Executor::instance()->cleanup_site_backups( $this->site_id );
			$this->assertSame( array(), $daily['deleted_ids'] );
			$this->assertSame( array( $old ), $daily['remaining_ids'], 'The live count must reach the existing open-run guard.' );
			$this->assertSame( 0, $cleanup['pruned'] );
			$this->assertNotEmpty( $cleanup['errors'] );
			$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $old )->status );
			$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $new )->status );
			$this->assertFileExists( $dir . '/artifact.tar' );
		} finally {
			$db->remove_backup_task( $task->id );
			delete_option( 'mainwp_on_demand_retention_count' );
		}
	}

	/**
	 * Insert a completed upload job row carrying a checkpoint.
	 *
	 * @param int $snapshot_id    Snapshot id.
	 * @param int $destination_id Destination id.
	 *
	 * @return void
	 */
	private function insert_upload_job( $snapshot_id, $destination_id ) {
		global $wpdb;

		$checkpoint = array(
			'artifacts'          => array(
				array(
					'path'          => '/tmp/big.tar',
					'file'          => 'big.tar',
					'role'          => 'full',
					'size'          => 5000,
					'mode'          => 'chunked',
					'chunks'        => array(
						array(
							'hash'           => 'aaa111',
							'algo'           => 'sha256',
							'offset'         => 0,
							'length'         => 2500,
							'remote_present' => true,
						),
						array(
							'hash'           => 'bbb222',
							'algo'           => 'sha256',
							'offset'         => 2500,
							'length'         => 2500,
							'remote_present' => true,
						),
					),
					'chunks_cursor' => 2,
					'done'          => true,
				),
				array(
					'path'          => '/tmp/small.txt',
					'file'          => 'small.txt',
					'role'          => 'db',
					'size'          => 100,
					'mode'          => 'whole',
					'chunks'        => null,
					'chunks_cursor' => 0,
					'done'          => true,
				),
			),
			'chunk_size'         => 2500,
			'whole_threshold'    => 2000,
			'manifest_submitted' => true,
		);

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			$table,
			array(
				'snapshot_id'    => $snapshot_id,
				'destination_id' => $destination_id,
				'kind'           => 'upload',
				'status'         => 'complete',
				'checkpoint'     => wp_json_encode( $checkpoint ),
				'last_error'     => '',
				'created'        => time(),
				'updated'        => time(),
			)
		);
	}

	/**
	 * A chain referenced by a nonterminal restore job is held: no
	 * snapshot is flipped, no dir removed, no cloud delete enqueued. The
	 * hold surfaces in errors so the sweep retries once the restore
	 * settles, and the same candidate prunes normally afterwards.
	 *
	 * @return void
	 */
	public function test_prune_skips_chain_referenced_by_active_restore() {
		list( $base, $base_dir ) = $this->make_snapshot_with_dir();
		list( $inc, $inc_dir )   = $this->make_snapshot_with_dir(
			array(
				'type'      => 'incremental',
				'parent_id' => $base,
			)
		);

		// A restore paused before staging (any nonterminal status) that
		// references the chain's leaf.
		$job_id = MainWP_Backup_Restore_Job_Store::instance()->create_job(
			$this->site_id,
			$inc,
			array( 'restore_id' => 'm5aholdtest1' )
		);

		$candidates = array(
			array(
				'snapshot_ids'   => array( $base, $inc ),
				'reason'         => 'count',
				'size_bytes'     => 200,
				'newest_created' => time(),
			),
		);

		$task   = $this->make_task( array( 'delete_cloud_copies' => 0 ) );
		$result = MainWP_Backup_Retention_Executor::instance()->run_prune( $candidates, $task, false );

		$this->assertSame( 0, $result['snapshots_pruned'], 'No snapshot may be pruned while a restore references the chain.' );
		$this->assertSame( 0, $result['pruned'], 'A held unit must not count as pruned - units_pruned would otherwise report a removal that never happened.' );
		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $base )->status );
		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $inc )->status );
		$this->assertDirectoryExists( $base_dir );
		$this->assertDirectoryExists( $inc_dir );
		$this->assertNotEmpty( $result['errors'], 'The hold must surface so the operator sees why nothing was removed.' );
		$this->assertStringContainsString( 'restore in progress', $result['errors'][0] );

		// Once the restore settles terminal, the same candidate prunes.
		MainWP_Backup_Restore_Job_Store::instance()->cancel_job( $job_id );

		$result = MainWP_Backup_Retention_Executor::instance()->run_prune( $candidates, $task, false );

		$this->assertSame( 2, $result['snapshots_pruned'], 'A settled restore must release the hold.' );
		$this->assertSame( 1, $result['pruned'], 'The released unit now counts as pruned.' );
		$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $base )->status );
		$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $inc )->status );
		$this->assertDirectoryDoesNotExist( $base_dir );
		$this->assertDirectoryDoesNotExist( $inc_dir );
	}

	/**
	 * Any restore on the site holds every prune there: restore admission
	 * takes the same site slot, so a prune cannot race a restore being
	 * admitted for the snapshot it is deleting. The unit is skipped, and
	 * the next run prunes it once the restore settles.
	 *
	 * @return void
	 */
	public function test_prune_waits_while_a_restore_holds_the_site() {
		list( $held, $held_dir )     = $this->make_snapshot_with_dir();
		list( $target, $target_dir ) = $this->make_snapshot_with_dir();

		$job_id = MainWP_Backup_Restore_Job_Store::instance()->create_job(
			$this->site_id,
			$held,
			array( 'restore_id' => 'm5aother0001' )
		);

		$candidates = array(
			array(
				'snapshot_ids'   => array( $target ),
				'reason'         => 'count',
				'size_bytes'     => 100,
				'newest_created' => time(),
			),
		);
		$task       = $this->make_task( array( 'delete_cloud_copies' => 0 ) );

		$result = MainWP_Backup_Retention_Executor::instance()->run_prune( $candidates, $task, false );

		$this->assertSame( 0, $result['snapshots_pruned'] );
		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $target )->status );
		$this->assertDirectoryExists( $target_dir );
		$this->assertStringContainsString( 'restore in progress', $result['errors'][0] );

		MainWP_Backup_Restore_Job_Store::instance()->cancel_job( $job_id );
		$result = MainWP_Backup_Retention_Executor::instance()->run_prune( $candidates, $task, false );

		$this->assertSame( 1, $result['snapshots_pruned'], 'The next run prunes once the restore settles.' );
		$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $target )->status );
		$this->assertDirectoryDoesNotExist( $target_dir );
		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $held )->status );
		$this->assertDirectoryExists( $held_dir );
	}

	/**
	 * Purged jobs must not leave a second archive copy in upload staging.
	 *
	 * @return void
	 */
	public function test_prune_removes_upload_staging_for_purged_jobs() {
		list( $snapshot_id ) = $this->make_snapshot_with_dir();
		$store               = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $snapshot_id, array( 77, 88 ) );
		$staging_dirs = array();
		foreach ( array( 77, 88 ) as $destination_id ) {
			$dir = MainWP_Backup_Private_Root::staging_dir( 'upload-staging', $snapshot_id . '-' . $destination_id );
			$this->assertNotWPError( $dir );
			file_put_contents( $dir . 'chunk.bin', 'staged archive bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
			$staging_dirs[] = $dir;
		}

		$result = MainWP_Backup_Retention_Executor::instance()->run_prune(
			array(
				array(
					'snapshot_ids' => array( $snapshot_id ),
					'reason'       => 'count',
					'size_bytes'   => 100,
				),
			),
			$this->make_task( array( 'delete_cloud_copies' => 0 ) )
		);

		$this->assertSame( 1, $result['snapshots_pruned'] );
		$this->assertSame( array(), $store->get_jobs_for_snapshot( $snapshot_id ) );
		foreach ( $staging_dirs as $dir ) {
			$this->assertDirectoryDoesNotExist( $dir );
		}
	}

	/**
	 * A destination waiting for work or a retry must not block disk recovery.
	 *
	 * @dataProvider unleased_upload_statuses
	 * @param string $status Upload status.
	 * @return void
	 */
	public function test_prune_does_not_hold_for_unleased_pending_upload( string $status ) {
		list( $snapshot_id, $dir ) = $this->make_snapshot_with_dir();
		$store                     = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $snapshot_id, array( 77 ) );
		$job = $store->get_jobs_for_snapshot( $snapshot_id )[0];
		$this->assertTrue( $store->set_status( (int) $job->id, $status ) );
		$job = $store->get_job( (int) $job->id );
		$this->assertSame( $status, $job->status );
		$this->assertSame( '', $job->locked_by );
		$this->assertSame( 0, (int) $job->locked_until );

		$result = MainWP_Backup_Retention_Executor::instance()->run_prune(
			array(
				array(
					'snapshot_ids' => array( $snapshot_id ),
					'reason'       => 'count',
					'size_bytes'   => 100,
				),
			),
			$this->make_task( array( 'delete_cloud_copies' => 0 ) )
		);

		$this->assertSame( 1, $result['snapshots_pruned'] );
		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id )->status );
		$this->assertDirectoryDoesNotExist( $dir );
		$this->assertSame( array(), $store->get_jobs_for_snapshot( $snapshot_id ) );
	}

	/**
	 * Both waiting states can persist indefinitely at a broken destination.
	 *
	 * @return array
	 */
	public static function unleased_upload_statuses(): array {
		return array(
			'pending'   => array( MainWP_Backup_Upload_Job_Store::STATUS_PENDING ),
			'retryable' => array( MainWP_Backup_Upload_Job_Store::STATUS_FAILED_RETRYABLE ),
		);
	}

	/**
	 * An upload using a chain member must keep the whole recovery source.
	 *
	 * @return void
	 */
	public function test_prune_holds_unit_while_upload_lease_is_live() {
		global $wpdb;

		list( $base, $base_dir ) = $this->make_snapshot_with_dir();
		list( $inc, $inc_dir )   = $this->make_snapshot_with_dir(
			array(
				'type'      => 'incremental',
				'parent_id' => $base,
			)
		);
		$store                   = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $base, array( 77 ) );
		$job = $store->acquire_next_job( 'tok', 300 );
		$this->assertNotNull( $job );
		$store->enqueue_for_snapshot( $inc, array( 77 ) );
		$this->assertTrue( $store->set_status( (int) $job->id, MainWP_Backup_Upload_Job_Store::STATUS_UPLOADING, 'tok' ) );
		$staging_dir = MainWP_Backup_Private_Root::staging_dir( 'upload-staging', $base . '-77' );
		$this->assertNotWPError( $staging_dir );
		file_put_contents( $staging_dir . 'chunk.bin', 'upload in progress' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		$candidates = array(
			array(
				'snapshot_ids' => array( $base, $inc ),
				'reason'       => 'count',
				'size_bytes'   => 200,
			),
		);
		$task       = $this->make_task( array( 'delete_cloud_copies' => 0 ) );

		$result = MainWP_Backup_Retention_Executor::instance()->run_prune( $candidates, $task );

		$this->assertSame( 0, $result['snapshots_pruned'] );
		$this->assertSame( 0, $result['pruned'] );
		$this->assertSame( 0, $result['skipped'], 'A held unit retries on the daily sweep, not the budget continuation loop.' );
		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $base )->status );
		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $inc )->status );
		$this->assertFileExists( $base_dir . '/artifact.tar' );
		$this->assertFileExists( $inc_dir . '/artifact.tar' );
		$this->assertFileExists( $staging_dir . 'chunk.bin' );
		$this->assertCount( 1, $store->get_jobs_for_snapshot( $base ) );
		$this->assertSame( 'tok', $store->get_job( (int) $job->id )->locked_by );
		$this->assertSame( '', $store->get_jobs_for_snapshot( $inc )[0]->locked_by, 'A hold releases the free leaf claimed before discovering the busy base.' );
		$this->assertSame( 0, (int) $store->get_jobs_for_snapshot( $inc )[0]->reclaim_count );
		$this->assertStringContainsString( 'upload in progress', $result['errors'][0] );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- expire the fixture lease without waiting.
			MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ),
			array( 'locked_until' => time() - 1 ),
			array( 'id' => (int) $job->id )
		);
		$result = MainWP_Backup_Retention_Executor::instance()->run_prune( $candidates, $task );
		$this->assertSame( 0, $result['snapshots_pruned'], 'An expired worker lease must wait for upload-lane reclamation.' );
		$reclaimed = $store->acquire_next_job( 'tok-reclaimed' );
		$this->assertNotNull( $reclaimed );
		$this->assertSame( (int) $job->id, (int) $reclaimed->id );
		$this->assertSame( 1, (int) $reclaimed->reclaim_count );
		$this->assertTrue( $store->complete_job( (int) $reclaimed->id, 'tok-reclaimed' ) );
		$result = MainWP_Backup_Retention_Executor::instance()->run_prune( $candidates, $task );

		$this->assertSame( 2, $result['snapshots_pruned'] );
		$this->assertSame( array(), $result['errors'] );
		$this->assertDirectoryDoesNotExist( $base_dir );
		$this->assertDirectoryDoesNotExist( $inc_dir );
		$this->assertDirectoryDoesNotExist( $staging_dir );
		$this->assertSame( array(), $store->get_jobs_for_snapshot( $base ) );
	}

	/**
	 * Pruning flips snapshot rows to pruned and removes their local dirs,
	 * leaf first so a crash mid-chain never orphans children.
	 *
	 * @return void
	 */
	public function test_prune_flips_status_and_removes_local_dirs() {
		list( $base, $base_dir ) = $this->make_snapshot_with_dir();
		list( $inc, $inc_dir )   = $this->make_snapshot_with_dir(
			array(
				'type'      => 'incremental',
				'parent_id' => $base,
			)
		);

		$candidates = array(
			array(
				'snapshot_ids'   => array( $base, $inc ),
				'reason'         => 'count',
				'size_bytes'     => 200,
				'newest_created' => time(),
			),
		);

		$result = MainWP_Backup_Retention_Executor::instance()->run_prune( $candidates, $this->make_task( array( 'delete_cloud_copies' => 0 ) ), false );

		$this->assertSame( 1, $result['pruned'] );
		$this->assertSame( 2, $result['snapshots_pruned'] );
		$this->assertSame( array(), $result['errors'] );

		$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $base )->status );
		$this->assertSame( 'retention', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $base )->removal_reason );
		$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $inc )->status );
		$this->assertSame( 'retention', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $inc )->removal_reason );
		$this->assertDirectoryDoesNotExist( $base_dir );
		$this->assertDirectoryDoesNotExist( $inc_dir );
	}

	/**
	 * With cloud copies enabled, pruning enqueues one delete job per
	 * destination carrying the remote keys from the upload checkpoint,
	 * and hard-deletes the upload rows.
	 *
	 * @return void
	 */
	public function test_prune_enqueues_delete_jobs_and_purges_upload_rows() {
		list( $snapshot_id ) = $this->make_snapshot_with_dir();
		$this->insert_upload_job( $snapshot_id, 77 );
		$this->insert_upload_job( $snapshot_id, 88 );

		$candidates = array(
			array(
				'snapshot_ids'   => array( $snapshot_id ),
				'reason'         => 'age',
				'size_bytes'     => 100,
				'newest_created' => time(),
			),
		);

		MainWP_Backup_Retention_Executor::instance()->run_prune( $candidates, $this->make_task(), false );

		$store       = MainWP_Backup_Upload_Job_Store::instance();
		$delete_jobs = $store->get_jobs_for_snapshot( $snapshot_id, 'delete' );
		$upload_jobs = $store->get_jobs_for_snapshot( $snapshot_id, 'upload' );

		$this->assertSame( array(), $upload_jobs, 'Upload rows are hard-deleted once their keys are collected.' );
		$this->assertCount( 2, $delete_jobs, 'One delete job per destination.' );

		$prefix = $this->site_id . '/' . $snapshot_id . '/';
		$keys   = wp_list_pluck( $delete_jobs[0]->checkpoint['keys'], 'key' );

		$this->assertContains( $prefix . 'chunks/aaa111.bin', $keys );
		$this->assertContains( $prefix . 'chunks/bbb222.bin', $keys );
		$this->assertContains( $prefix . 'files/small.txt', $keys );
		$this->assertContains( $prefix . 'manifest.json', $keys );
		$this->assertSame( $prefix, $delete_jobs[0]->checkpoint['prefix'] );
	}

	/**
	 * A symlinked uploads root must not break retention: the artifact
	 * paths are realpath()ed before the containment check, so the root
	 * must be canonicalized the same way (as purge_orphaned_snapshot_dirs
	 * already does) or every removal is refused as outside the tree and
	 * nothing ever prunes.
	 *
	 * @return void
	 */
	public function test_prune_removes_dirs_under_symlinked_uploads_root() {
		if ( ! function_exists( 'symlink' ) ) {
			$this->markTestSkipped( 'symlink() unavailable on this platform.' );
		}

		$real = sys_get_temp_dir() . '/mainwp-reten-real-' . uniqid();
		$link = sys_get_temp_dir() . '/mainwp-reten-link-' . uniqid();
		mkdir( $real, 0777, true );
		symlink( $real, $link );

		$filter = function ( $dirs ) use ( $link ) {
			$dirs['basedir'] = $link;
			return $dirs;
		};
		add_filter( 'upload_dir', $filter );

		try {
			$legacy = \MainWP\Dashboard\MainWP_Backup_Private_Root::legacy_site_dir( $this->site_id );
			$this->assertStringStartsWith( trailingslashit( $link ), $legacy, 'The artifact must sit under the symlinked uploads root.' );
			list( $snapshot_id, $dir ) = $this->make_snapshot_with_dir( array(), $legacy );

			$candidates = array(
				array(
					'snapshot_ids'   => array( $snapshot_id ),
					'reason'         => 'count',
					'size_bytes'     => 100,
					'newest_created' => time(),
				),
			);

			$result = MainWP_Backup_Retention_Executor::instance()->run_prune( $candidates, $this->make_task( array( 'delete_cloud_copies' => 0 ) ), false );

			$this->assertSame( array(), $result['errors'], 'A symlinked uploads root must not be refused as outside the backup tree.' );
			$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id )->status );
			$this->assertDirectoryDoesNotExist( $dir );
		} finally {
			remove_filter( 'upload_dir', $filter );
			if ( is_link( $link ) ) {
				unlink( $link ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test cleanup.
			}
			if ( is_link( $real ) ) {
				unlink( $real );
			} elseif ( is_dir( $real ) ) {
				$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $real, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
				foreach ( $iterator as $left_over ) {
					if ( $left_over->isLink() ) {
						unlink( $left_over->getPathname() );
					} elseif ( $left_over->isDir() ) {
						rmdir( $left_over->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test cleanup.
					} else {
						unlink( $left_over->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test cleanup.
					}
				}
				rmdir( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test cleanup.
			}
		}
	}

	/**
	 * A snapshot whose cloud delete job cannot be queued must NOT be marked
	 * pruned, and its upload rows (the only record of the remote keys) must
	 * survive - otherwise the retries end and the cloud copy is orphaned
	 * forever. The row stays 'complete' so the next sweep retries.
	 *
	 * @return void
	 */
	public function test_prune_holds_when_delete_enqueue_fails() {
		list( $snapshot_id, $dir ) = $this->make_snapshot_with_dir();
		$this->insert_upload_job( $snapshot_id, 77 );

		$failing = new class() extends MainWP_Backup_Upload_Job_Store {
			/**
			 * Model a store whose delete-enqueue write fails.
			 *
			 * @param int   $snapshot_id    Snapshot id.
			 * @param int   $destination_id Destination id.
			 * @param array $checkpoint     Checkpoint.
			 *
			 * @return string Always failed.
			 */
			public function enqueue_delete_job_result( int $snapshot_id, int $destination_id, array $checkpoint ): string {
				unset( $snapshot_id, $destination_id, $checkpoint );
				return 'failed';
			}
		};
		$prop    = new \ReflectionProperty( MainWP_Backup_Upload_Job_Store::class, 'instance' );
		$prop->setAccessible( true );
		$saved = $prop->getValue();
		$prop->setValue( null, $failing );

		$candidates = array(
			array(
				'snapshot_ids'   => array( $snapshot_id ),
				'reason'         => 'age',
				'size_bytes'     => 100,
				'newest_created' => time(),
			),
		);

		try {
			$result = MainWP_Backup_Retention_Executor::instance()->run_prune( $candidates, $this->make_task(), false );
		} finally {
			$prop->setValue( null, $saved );
		}

		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id )->status, 'A snapshot whose cloud delete could not be queued must stay retryable.' );
		$this->assertCount( 1, MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( $snapshot_id, 'upload' ), 'The upload metadata must survive a failed enqueue.' );
		$this->assertSame( 0, $result['snapshots_pruned'] );
		$this->assertNotEmpty( $result['errors'], 'The failed enqueue must be reported.' );
		$this->assertFileExists( $dir . '/artifact.tar' );
		$this->assertSame( array(), $result['deleted_ids'] );
		$this->assertSame( array( $snapshot_id ), $result['remaining_ids'] );
		$this->assertSame( array(), $result['cloud_deletes_queued'] );
		$this->assertSame( '', MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( $snapshot_id )[0]->locked_by );
	}

	/**
	 * A failed status write must not report success or prune the chain's base.
	 *
	 * @dataProvider data_status_write_failure_paths
	 *
	 * @param string $path Retention entry point.
	 *
	 * @return void
	 */
	public function test_failed_status_write_does_not_count_snapshot_as_pruned( $path ) {
		global $wpdb;

		list( $base ) = $this->make_snapshot_with_dir();
		list( $leaf ) = $this->make_snapshot_with_dir( array( 'parent_id' => $base, 'type' => 'incremental' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
		$table        = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$failed       = false;
		$fail         = static function ( $query ) use ( $table, &$failed ) {
			if ( str_starts_with( $query, 'UPDATE `' . $table . '` SET ' ) && str_contains( $query, '`status`' ) ) {
				$failed = true;
				return str_replace( '`status`', '`missing_status_column`', $query );
			}
			return $query;
		};
		$events       = array();
		$capture      = static function ( $event ) use ( &$events ) {
			$events[] = $event;
		};
		$suppress     = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail );
		add_action( 'mainwp_backup_pruned', $capture );
		try {
			$executor = MainWP_Backup_Retention_Executor::instance();
			if ( 'cleanup' === $path ) {
				$result = $executor->cleanup_site_backups( $this->site_id );
			} else {
				$result = $executor->run_prune(
					array(
						array(
							'snapshot_ids' => array( $base, $leaf ),
							'reason'       => 'count',
						),
					),
					$this->make_task( array( 'delete_cloud_copies' => 0 ) ),
					false
				);
			}
		} finally {
			remove_filter( 'query', $fail );
			remove_action( 'mainwp_backup_pruned', $capture );
			$wpdb->suppress_errors( $suppress );
		}

		$this->assertTrue( $failed, 'The fixture must reach the failing status UPDATE.' );
		$this->assertSame( 0, $result['snapshots_pruned'] );
		$this->assertSame( 0, $result['pruned'] );
		$this->assertNotEmpty( $result['errors'], 'A failed status write must be reported.' );
		$this->assertStringContainsString( 'could not be marked as removed', implode( ' ', $result['errors'] ) );
		$this->assertContains( sprintf( 'Snapshot %d: the files were deleted but the backup could not be marked as removed. Try again later.', $leaf ), $result['errors'] );
		$this->assertSame( array(), $events, 'An unrecorded prune must not emit a successful prune event.' );
		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $leaf )->status );
		$this->assertSame( '', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $leaf )->removal_reason );
		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $base )->status );
		$this->assertSame( '', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $base )->removal_reason );
		if ( 'cleanup' === $path ) {
			$this->assertSame( 0, $result['bytes_pruned'], 'Unrecorded prunes must not contribute reclaimed bytes.' );
		}
	}

	/**
	 * Both retention entry points must report failed status writes.
	 *
	 * @return array
	 */
	public static function data_status_write_failure_paths() {
		return array(
			'scheduled retention' => array( 'run_prune' ),
			'site cleanup'        => array( 'cleanup' ),
		);
	}

	/**
	 * Without delete_cloud_copies no delete jobs are enqueued, but upload
	 * rows still purge.
	 *
	 * @return void
	 */
	public function test_prune_without_cloud_copies_skips_delete_jobs() {
		list( $snapshot_id ) = $this->make_snapshot_with_dir();
		$this->insert_upload_job( $snapshot_id, 77 );

		$candidates = array(
			array(
				'snapshot_ids'   => array( $snapshot_id ),
				'reason'         => 'age',
				'size_bytes'     => 100,
				'newest_created' => time(),
			),
		);

		MainWP_Backup_Retention_Executor::instance()->run_prune( $candidates, $this->make_task( array( 'delete_cloud_copies' => 0 ) ), false );

		$store = MainWP_Backup_Upload_Job_Store::instance();
		$this->assertSame( array(), $store->get_jobs_for_snapshot( $snapshot_id, 'delete' ) );
		$this->assertSame( array(), $store->get_jobs_for_snapshot( $snapshot_id, 'upload' ) );
	}

	/**
	 * Dry-run reports the candidates without touching anything.
	 *
	 * @return void
	 */
	public function test_dry_run_makes_no_mutations() {
		list( $snapshot_id, $dir ) = $this->make_snapshot_with_dir();
		$this->insert_upload_job( $snapshot_id, 77 );

		$fired = 0;
		add_action(
			'mainwp_backup_pruned',
			function () use ( &$fired ) {
				++$fired;
			}
		);

		$candidates = array(
			array(
				'snapshot_ids'   => array( $snapshot_id ),
				'reason'         => 'age',
				'size_bytes'     => 100,
				'newest_created' => time(),
			),
		);

		$result = MainWP_Backup_Retention_Executor::instance()->run_prune( $candidates, $this->make_task(), true );

		$this->assertTrue( $result['dry_run'] );
		$this->assertSame( 1, $result['pruned'], 'Dry-run reports what would prune.' );
		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id )->status );
		$this->assertSame( '', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id )->removal_reason );
		$this->assertDirectoryExists( $dir );
		$this->assertCount( 1, MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( $snapshot_id, 'upload' ) );
		$this->assertSame( array(), MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( $snapshot_id, 'delete' ) );
		$this->assertSame( 0, $fired, 'Dry-run never fires the logging action.' );
	}

	/**
	 * The pruned action fires once per candidate with the event shape the
	 * logs connector expects.
	 *
	 * @return void
	 */
	public function test_pruned_action_fires_once_per_candidate() {
		list( $base )       = $this->make_snapshot_with_dir();
		list( $inc )        = $this->make_snapshot_with_dir(
			array(
				'type'      => 'incremental',
				'parent_id' => $base,
			)
		);
		list( $standalone ) = $this->make_snapshot_with_dir();

		$events = array();
		add_action(
			'mainwp_backup_pruned',
			function ( $event ) use ( &$events ) {
				$events[] = $event;
			}
		);

		$candidates = array(
			array(
				'snapshot_ids'   => array( $base, $inc ),
				'reason'         => 'count',
				'size_bytes'     => 200,
				'newest_created' => time(),
			),
			array(
				'snapshot_ids'   => array( $standalone ),
				'reason'         => 'age',
				'size_bytes'     => 100,
				'newest_created' => time(),
			),
		);

		MainWP_Backup_Retention_Executor::instance()->run_prune( $candidates, $this->make_task( array( 'delete_cloud_copies' => 0 ) ), false );

		$this->assertCount( 2, $events );
		$this->assertSame( $this->site_id, $events[0]['site_id'] );
		$this->assertSame( $this->task_id, $events[0]['task_id'] );
		$this->assertSame( array( $base, $inc ), $events[0]['snapshot_ids'] );
		$this->assertSame( 'count', $events[0]['reason'] );
		$this->assertSame( 200, $events[0]['size_bytes'] );
	}

	/**
	 * The budget bounds work per tick: at least one candidate makes
	 * progress, the rest are skipped for the next tick.
	 *
	 * @return void
	 */
	public function test_budget_processes_at_least_one_candidate_then_stops() {
		list( $first )  = $this->make_snapshot_with_dir();
		list( $second ) = $this->make_snapshot_with_dir();

		$candidates = array(
			array(
				'snapshot_ids'   => array( $first ),
				'reason'         => 'age',
				'size_bytes'     => 100,
				'newest_created' => time(),
			),
			array(
				'snapshot_ids'   => array( $second ),
				'reason'         => 'age',
				'size_bytes'     => 100,
				'newest_created' => time(),
			),
		);

		$result = MainWP_Backup_Retention_Executor::instance()->run_prune( $candidates, $this->make_task( array( 'delete_cloud_copies' => 0 ) ), false, 0 );

		$this->assertSame( 1, $result['pruned'], 'A zero budget still guarantees progress on one candidate.' );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $first )->status );
		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $second )->status );
	}

	/**
	 * After a chain prunes, the surviving units stay complete: no live
	 * snapshot is left with a pruned ancestor.
	 *
	 * @return void
	 */
	public function test_surviving_units_stay_complete_after_prune() {
		// Old chain.
		list( $old_base ) = $this->make_snapshot_with_dir( array( 'created' => time() - 200 ) );
		list( $old_inc )  = $this->make_snapshot_with_dir(
			array(
				'type'      => 'incremental',
				'parent_id' => $old_base,
				'created'   => time() - 150,
			)
		);
		// Newer chain.
		list( $new_base ) = $this->make_snapshot_with_dir( array( 'created' => time() - 100 ) );
		list( $new_inc )  = $this->make_snapshot_with_dir(
			array(
				'type'      => 'incremental',
				'parent_id' => $new_base,
				'created'   => time() - 50,
			)
		);

		$candidates = array(
			array(
				'snapshot_ids'   => array( $old_base, $old_inc ),
				'reason'         => 'count',
				'size_bytes'     => 200,
				'newest_created' => time() - 150,
			),
		);

		MainWP_Backup_Retention_Executor::instance()->run_prune( $candidates, $this->make_task( array( 'delete_cloud_copies' => 0 ) ), false );

		$units = MainWP_Backup_Retention_Query::instance()->get_units_for_site_task( $this->site_id, $this->task_id );

		$this->assertCount( 1, $units, 'Only the newer chain survives.' );
		$this->assertTrue( $units[0]['is_complete'], 'The surviving chain must remain restorable.' );
		$this->assertSame( array( $new_base, $new_inc ), array_map( 'intval', wp_list_pluck( $units[0]['snapshots'], 'id' ) ) );
	}

	/**
	 * A snapshot whose local artifact cannot be deleted must not be marked
	 * pruned: metadata claiming the files are gone while they sit on disk
	 * breaks retention accounting and hides a restorable snapshot. The
	 * chain stops there, leaf first, so no pruned base orphans a kept leaf.
	 *
	 * @return void
	 */
	public function test_failed_local_delete_keeps_snapshot_restorable() {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Root bypasses directory write permissions.' );
		}
		list( $base, $base_dir ) = $this->make_snapshot_with_dir();
		list( $inc, $inc_dir )   = $this->make_snapshot_with_dir(
			array(
				'type'      => 'incremental',
				'parent_id' => $base,
			)
		);

		// A read-only directory makes the leaf's artifact unlink fail.
		chmod( $inc_dir, 0555 ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		try {
			$candidates = array(
				array(
					'snapshot_ids'   => array( $base, $inc ),
					'reason'         => 'count',
					'size_bytes'     => 200,
					'newest_created' => time(),
				),
			);

			$result = MainWP_Backup_Retention_Executor::instance()->run_prune( $candidates, $this->make_task( array( 'delete_cloud_copies' => 0 ) ), false );

			$this->assertNotSame( array(), $result['errors'], 'The failed deletion must surface a diagnostic.' );
			$this->assertSame( 0, $result['snapshots_pruned'], 'Nothing was actually removed, so nothing may count as pruned.' );

			$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $inc )->status, 'The undeleted leaf must stay restorable.' );
			$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $base )->status, 'The base must not be pruned under a kept leaf.' );
			$this->assertFileExists( $inc_dir . '/artifact.tar', 'The artifact is still on disk.' );
			$this->assertFileExists( $base_dir . '/artifact.tar', 'The base artifact must be untouched.' );
		} finally {
			chmod( $inc_dir, 0755 ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test cleanup.
		}
	}

	/**
	 * When the local delete fails and cloud copies are enabled, the offsite
	 * objects and the upload metadata must be left intact: a snapshot kept
	 * restorable must not have its offsite copies queued for deletion or its
	 * upload-job rows purged. The irreversible offsite/metadata steps run
	 * only after the local delete succeeds.
	 *
	 * @return void
	 */
	public function test_failed_local_delete_preserves_cloud_copies_and_upload_rows() {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Root bypasses directory write permissions.' );
		}
		list( $snapshot_id, $dir ) = $this->make_snapshot_with_dir();
		$this->insert_upload_job( $snapshot_id, 77 );
		$this->insert_upload_job( $snapshot_id, 88 );

		// A read-only directory makes the artifact unlink fail.
		chmod( $dir, 0555 ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		try {
			$candidates = array(
				array(
					'snapshot_ids'   => array( $snapshot_id ),
					'reason'         => 'age',
					'size_bytes'     => 100,
					'newest_created' => time(),
				),
			);

			$result = MainWP_Backup_Retention_Executor::instance()->run_prune( $candidates, $this->make_task( array( 'delete_cloud_copies' => 1 ) ), false );

			$this->assertSame( 0, $result['snapshots_pruned'], 'A failed local delete prunes nothing.' );

			$store = MainWP_Backup_Upload_Job_Store::instance();
			$this->assertSame( array(), $store->get_jobs_for_snapshot( $snapshot_id, 'delete' ), 'No cloud delete may be queued for a snapshot kept restorable.' );
			$this->assertCount( 2, $store->get_jobs_for_snapshot( $snapshot_id, 'upload' ), 'The upload metadata must survive so the next sweep can retry.' );

			$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id )->status, 'The snapshot must stay restorable.' );
		} finally {
			chmod( $dir, 0755 ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test cleanup.
		}
	}

	/**
	 * A snapshot row whose artifact path points at a real file outside
	 * uploads/mainwp is refused, and the refusal must count as an unclean
	 * delete: the snapshot stays complete and none of the irreversible
	 * offsite/metadata steps run. A migrated dashboard whose recorded
	 * absolute paths still point at the old uploads tree hits exactly this.
	 *
	 * @return void
	 */
	public function test_outside_root_artifact_refusal_keeps_snapshot_complete() {
		$outside = sys_get_temp_dir() . '/mainwp-retention-outside-' . uniqid() . '.tar';
		file_put_contents( $outside, str_repeat( 'x', 50 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		list( $snapshot_id ) = $this->make_snapshot_with_dir(
			array(
				'archives' => wp_json_encode( array( array( 'file' => 'outside.tar', 'size' => 50, 'role' => 'full', 'path' => $outside ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
			)
		);
		$this->insert_upload_job( $snapshot_id, 77 );

		try {
			$candidates = array(
				array(
					'snapshot_ids'   => array( $snapshot_id ),
					'reason'         => 'age',
					'size_bytes'     => 50,
					'newest_created' => time(),
				),
			);

			$result = MainWP_Backup_Retention_Executor::instance()->run_prune( $candidates, $this->make_task( array( 'delete_cloud_copies' => 1 ) ), false );

			$this->assertNotSame( array(), $result['errors'], 'The refusal must surface a diagnostic.' );
			$this->assertSame( 0, $result['snapshots_pruned'], 'A refused artifact prunes nothing.' );

			$store = MainWP_Backup_Upload_Job_Store::instance();
			$this->assertSame( array(), $store->get_jobs_for_snapshot( $snapshot_id, 'delete' ), 'No cloud delete may be queued for a snapshot kept restorable.' );
			$this->assertCount( 1, $store->get_jobs_for_snapshot( $snapshot_id, 'upload' ), 'The upload metadata must survive.' );

			$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id )->status, 'The snapshot must stay complete while its named artifact exists.' );
			$this->assertFileExists( $outside, 'The out-of-tree file must never be touched.' );
		} finally {
			unlink( $outside ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test cleanup.
		}
	}

	/**
	 * A snapshot row whose artifact path resolves inside uploads/mainwp/ -
	 * so it passes the old, root-only confinement - but under a different
	 * site's subtree must be refused the same way an out-of-tree path is: a
	 * tampered or copied row must never let one site's prune reach into
	 * another site's backup files. The refused snapshot stays complete and
	 * none of the irreversible offsite/metadata steps run.
	 *
	 * The positive control at the end confirms the tightened confinement
	 * still lets a site prune its own subtree.
	 *
	 * @return void
	 */
	public function test_other_site_subtree_refusal_keeps_snapshot_complete() {
		$upload     = wp_upload_dir();
		$other_site = $this->site_id + 1;
		$other_dir  = trailingslashit( $upload['basedir'] ) . 'mainwp/0/' . $other_site . '/snapshot-other-' . uniqid();
		wp_mkdir_p( $other_dir );
		$this->fixture_dirs[] = $other_dir;
		$other_file           = $other_dir . '/artifact.tar';
		file_put_contents( $other_file, str_repeat( 'x', 50 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		list( $snapshot_id ) = $this->make_snapshot_with_dir(
			array(
				'archives' => wp_json_encode( array( array( 'file' => 'artifact.tar', 'size' => 50, 'role' => 'full', 'path' => $other_file ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
			)
		);
		$this->insert_upload_job( $snapshot_id, 77 );

		$candidates = array(
			array(
				'snapshot_ids'   => array( $snapshot_id ),
				'reason'         => 'age',
				'size_bytes'     => 50,
				'newest_created' => time(),
			),
		);

		$result = MainWP_Backup_Retention_Executor::instance()->run_prune( $candidates, $this->make_task( array( 'delete_cloud_copies' => 1 ) ), false );

		$this->assertNotSame( array(), $result['errors'], 'The refusal must surface a diagnostic.' );
		$this->assertSame( 0, $result['snapshots_pruned'], 'A refused artifact prunes nothing.' );

		$store = MainWP_Backup_Upload_Job_Store::instance();
		$this->assertSame( array(), $store->get_jobs_for_snapshot( $snapshot_id, 'delete' ), 'No cloud delete may be queued for a snapshot kept restorable.' );
		$this->assertCount( 1, $store->get_jobs_for_snapshot( $snapshot_id, 'upload' ), 'The upload metadata must survive.' );

		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id )->status, 'The snapshot must stay complete while its named artifact exists.' );
		$this->assertFileExists( $other_file, "Another site's backup file must never be touched." );

		// Positive control: this site's own subtree still prunes.
		list( $own_id, $own_dir ) = $this->make_snapshot_with_dir();
		$own_candidates           = array(
			array(
				'snapshot_ids'   => array( $own_id ),
				'reason'         => 'age',
				'size_bytes'     => 100,
				'newest_created' => time(),
			),
		);

		$own_result = MainWP_Backup_Retention_Executor::instance()->run_prune( $own_candidates, $this->make_task( array( 'delete_cloud_copies' => 0 ) ), false );

		$this->assertSame( array(), $own_result['errors'] );
		$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $own_id )->status );
		$this->assertDirectoryDoesNotExist( $own_dir );
	}

	/**
	 * Legacy and db runs share one site directory rather than per-snapshot
	 * dirs. Pruning one snapshot there deletes only its own artifact files;
	 * sibling snapshots' files and the directory itself survive.
	 *
	 * @return void
	 */
	public function test_prune_in_shared_dir_only_removes_own_files() {
		global $wpdb;

		$upload = wp_upload_dir();
		$shared = trailingslashit( $upload['basedir'] ) . 'mainwp/0/' . $this->site_id;
		wp_mkdir_p( $shared );
		$this->fixture_dirs[] = $shared;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$ids   = array();
		foreach ( array( 'old-db.sql.tar.gz', 'new-db.sql.tar.gz' ) as $index => $file ) {
			file_put_contents( $shared . '/' . $file, 'data' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
				$table,
				array(
					'task_id'       => $this->task_id,
					'site_id'       => $this->site_id,
					'type'          => 'db',
					'created'       => time() - 100 + $index,
					'archives'      => wp_json_encode( array( array( 'file' => $file, 'size' => 4, 'role' => 'db', 'path' => $shared . '/' . $file ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
					'manifest_path' => '',
					'status'        => 'complete',
				)
			);
			$ids[] = (int) $wpdb->insert_id;
		}

		$candidates = array(
			array(
				'snapshot_ids'   => array( $ids[0] ),
				'reason'         => 'count',
				'size_bytes'     => 4,
				'newest_created' => time() - 100,
			),
		);

		$result = MainWP_Backup_Retention_Executor::instance()->run_prune( $candidates, $this->make_task( array( 'delete_cloud_copies' => 0 ) ), false );

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $ids[0] )->status );
		$this->assertFileDoesNotExist( $shared . '/old-db.sql.tar.gz', 'The pruned snapshot loses its own artifact.' );
		$this->assertFileExists( $shared . '/new-db.sql.tar.gz', 'A sibling snapshot keeps its artifact.' );
		$this->assertDirectoryExists( $shared, 'A shared directory is never removed.' );
	}

	/**
	 * Keeping one row must preserve bytes shared with the row being pruned.
	 *
	 * @dataProvider b03_shared_artifacts
	 * @param bool $snapshot_dir Whether owners share a run directory rather than one archive.
	 */
	public function test_b03_keep_one_preserves_shared_artifacts( $snapshot_dir ) {
		global $wpdb;
		list( $older, $dir ) = $this->make_snapshot_with_dir( array( 'created' => time() - 100 ) );
		if ( ! $snapshot_dir ) {
			$shared = dirname( $dir ) . '/shared-' . basename( $dir );
			rename( $dir, $shared );
			$dir                  = $shared;
			$this->fixture_dirs[] = $dir;
		}
		$table        = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$path         = $dir . '/artifact.tar';
		$old_archives = array(
			array(
				'file' => 'artifact.tar',
				'size' => 100,
				'role' => 'full',
				'path' => $path,
			),
		);
		$wpdb->update(
			$table,
			array(
				'archives'      => wp_json_encode( $old_archives ),
				'manifest_path' => '',
			),
			array( 'id' => $older )
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- fixture.
		$kept_path = $snapshot_dir ? $dir . '/new.tar' : $dir . '//artifact.tar';
		if ( $snapshot_dir ) {
			file_put_contents( $kept_path, 'retained bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- fixture.
		}
		list( $newer ) = $this->make_snapshot_with_dir(
			array(
				'archives'      => wp_json_encode(
					array(
						array(
							'file' => basename( $kept_path ),
							'size' => 100,
							'role' => 'full',
							'path' => $kept_path,
						),
					)
				),
				'manifest_path' => '',
			)
		);
		$task          = $this->make_task(
			array(
				'delete_cloud_copies' => 0,
				'retention_count'     => 1,
			)
		);
		$policy        = new \MainWP\Dashboard\MainWP_Backup_Retention_Policy( 1, 0, 0 );
		$units         = MainWP_Backup_Retention_Query::instance()->get_units_for_site_task( $this->site_id, $this->task_id );
		$candidates    = $policy->evaluate( $units, time() );
		$this->assertCount( 1, $candidates );

		$result = MainWP_Backup_Retention_Executor::instance()->run_prune( $candidates, $task );

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( 1, $result['snapshots_pruned'] );
		$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $older )->status );
		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $newer )->status );
		$this->assertFileExists( $path );
		$this->assertFileExists( $kept_path );
		$this->assertDirectoryExists( $dir );
	}

	/**
	 * Shared archive and shared directory cases need different deletion guards.
	 *
	 * @return array Ownership boundaries exercised by retention.
	 */
	public function b03_shared_artifacts() {
		return array(
			'same normalized archive' => array( false ),
			'same snapshot directory' => array( true ),
		);
	}

	/**
	 * Task deletion cannot remove another task's shared archive.
	 */
	public function test_b03_task_purge_preserves_another_tasks_archive() {
		list( $older, $dir ) = $this->make_snapshot_with_dir();
		$old                 = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $older );
		list( $newer )       = $this->make_snapshot_with_dir(
			array(
				'task_id'       => $this->task_id + 1,
				'archives'      => wp_json_encode( $old->archives ),
				'manifest_path' => $old->manifest_path,
			)
		);

		$result = MainWP_Backup_Retention_Executor::instance()->purge_snapshots_for_task( $this->task_id, false );

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $older )->status );
		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $newer )->status );
		$this->assertFileExists( $dir . '/artifact.tar' );
	}

	/**
	 * An incomplete row's manifest still owns its directory during an orphan sweep.
	 */
	public function test_b03_site_cleanup_preserves_non_pruned_manifest_owner() {
		list( $id, $dir ) = $this->make_snapshot_with_dir(
			array(
				'status'   => 'failed',
				'archives' => '[]',
			)
		);
		file_put_contents( $dir . '/files.list', 'manifest' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- fixture.

		$result = MainWP_Backup_Retention_Executor::instance()->cleanup_site_backups( $this->site_id, 1, 0 );

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( 0, $result['orphan_dirs_removed'] );
		$this->assertSame( 'failed', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id )->status );
		$this->assertFileExists( $dir . '/files.list' );
		$this->assertFileExists( $dir . '/artifact.tar' );
	}

	/**
	 * A paused run can idle past the freshness window before it records its
	 * snapshot; the sweep must leave its staging directory alone while still
	 * removing a genuinely orphaned one beside it.
	 *
	 * @return void
	 */
	public function test_orphan_sweep_spares_open_paused_run_dir() {
		$db   = \MainWP\Dashboard\MainWP_DB_Backup::instance();
		$task = $db->add_backup_task( 1, 'Paused open run', 'daily', 'full', '', (string) $this->site_id, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task );
		try {
			$db->update_backup_task_with_values(
				$task->id,
				array(
					'last_run'  => time() - 8 * HOUR_IN_SECONDS,
					'completed' => 0,
					'paused'    => 1,
				)
			);
			$db->add_backup_task_progress( $task->id, $this->site_id, array() );
			$db->update_backup_task_progress(
				$task->id,
				$this->site_id,
				array(
					'dtsFetched'           => time() - 7 * HOUR_IN_SECONDS,
					'child_backup_task_id' => 'open-run-42',
				)
			);

			$root     = \MainWP\Dashboard\MainWP_Backup_Private_Root::site_dir( $this->site_id );
			$open_dir = $root . 'snapshot-open-run-42';
			$orphan   = $root . 'snapshot-orphan-' . uniqid();
			foreach ( array( $open_dir, $orphan ) as $dir ) {
				wp_mkdir_p( $dir );
				$this->fixture_dirs[] = $dir;
				file_put_contents( $dir . '/artifact.tar', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
				touch( $dir . '/artifact.tar', time() - 7 * HOUR_IN_SECONDS );
				touch( $dir, time() - 7 * HOUR_IN_SECONDS );
			}

			$result = MainWP_Backup_Retention_Executor::instance()->cleanup_site_backups( $this->site_id, 1 );

			$this->assertSame( array(), $result['errors'] );
			$this->assertSame( 1, $result['orphan_dirs_removed'] );
			$this->assertFileExists( $open_dir . '/artifact.tar', 'An open paused run still owns its staging directory.' );
			$this->assertDirectoryDoesNotExist( $orphan, 'A directory no run or snapshot owns must still be swept.' );
		} finally {
			$db->remove_backup_task( $task->id );
		}
	}

	/**
	 * A symlink alias must not let the sweep delete an open paused run's files.
	 *
	 * @return void
	 */
	public function test_orphan_sweep_spares_symlink_to_open_paused_run_dir() {
		$db    = \MainWP\Dashboard\MainWP_DB_Backup::instance();
		$alias = null;
		$task  = $db->add_backup_task( 1, 'Paused open run alias', 'daily', 'full', '', (string) $this->site_id, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task );
		try {
			$db->update_backup_task_with_values(
				$task->id,
				array(
					'last_run'  => time() - 8 * HOUR_IN_SECONDS,
					'completed' => 0,
					'paused'    => 1,
				)
			);
			$db->add_backup_task_progress( $task->id, $this->site_id, array() );
			$db->update_backup_task_progress(
				$task->id,
				$this->site_id,
				array(
					'dtsFetched'           => time() - 7 * HOUR_IN_SECONDS,
					'child_backup_task_id' => 'open-run-42',
				)
			);

			$root     = \MainWP\Dashboard\MainWP_Backup_Private_Root::site_dir( $this->site_id );
			$open_dir = $root . 'snapshot-open-run-42';
			$alias    = $root . 'snapshot-alias-' . uniqid();
			wp_mkdir_p( $open_dir );
			$this->fixture_dirs[] = $open_dir;
			file_put_contents( $open_dir . '/artifact.tar', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
			$this->assertTrue( symlink( $open_dir, $alias ), 'Precondition: the alias must point to the open run directory.' );
			touch( $open_dir . '/artifact.tar', time() - 7 * HOUR_IN_SECONDS );
			touch( $alias, time() - 7 * HOUR_IN_SECONDS );

			$result = MainWP_Backup_Retention_Executor::instance()->cleanup_site_backups( $this->site_id, 1 );

			$this->assertSame( array(), $result['errors'] );
			$this->assertFileExists( $open_dir . '/artifact.tar', 'An alias must not expose an open paused run to the orphan sweep.' );
		} finally {
			$db->remove_backup_task( $task->id );
			if ( null !== $alias && is_link( $alias ) ) {
				unlink( $alias ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test cleanup.
			}
		}
	}

	/**
	 * Record one snapshot row in the layout production writes: full and
	 * database archives land in the site's shared folder under their own
	 * names, incremental runs get a snapshot-{child_task_id} folder.
	 *
	 * @param int         $site_id       Site id.
	 * @param string      $type          Snapshot type.
	 * @param string      $child_task_id Child task id the run used.
	 * @param string|null $run_key       Run key, or null for a row recorded without one.
	 *
	 * @return int Snapshot id.
	 */
	private function make_production_layout_snapshot( $site_id, $type, $child_task_id, $run_key ) {
		global $wpdb;
		$site_dir = \MainWP\Dashboard\MainWP_Backup_Private_Root::site_dir( $site_id );
		if ( 'incremental' === $type ) {
			$dir  = $site_dir . 'snapshot-' . $child_task_id;
			$file = 'artifact.tar';
		} else {
			$dir  = untrailingslashit( $site_dir );
			$file = ( 'db' === $type ? 'db-example.test-08-10-2026-1791460037.sql.zip' : 'full-example.test-08-10-2026-1791460037.tar.gz' );
		}
		wp_mkdir_p( $dir );
		$this->fixture_dirs[] = $dir;
		file_put_contents( $dir . '/' . $file, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ),
			array(
				'task_id'       => $this->task_id,
				'site_id'       => $site_id,
				'type'          => $type,
				'created'       => time(),
				'archives'      => wp_json_encode( array( array( 'file' => $file, 'size' => 1, 'role' => 'db' === $type ? 'db' : 'full', 'path' => $dir . '/' . $file ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
				'manifest_path' => '',
				'status'        => 'complete',
				'run_key'       => $run_key,
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Pruning notifies the child site so it cleans its own task rows and
	 * artifacts, for every backup type, taking the child task id from the
	 * run key and from the folder only for a row recorded without one.
	 *
	 * @dataProvider data_child_prune_layouts
	 *
	 * @param string $type        Snapshot type.
	 * @param string $run_key_tag Run key kind: child, legacy or none.
	 * @param array  $expected    Child task ids the prune names.
	 *
	 * @return void
	 */
	public function test_prune_notifies_child_with_task_ids( $type, $run_key_tag, $expected ) {
		global $wpdb;
		$site_id       = $this->create_test_site();
		$child_task_id = 'task-1791460037-' . $type;
		$run_keys      = array(
			'child'  => $site_id . ':' . $this->task_id . ':c:' . $child_task_id,
			'legacy' => $site_id . ':' . $this->task_id . ':l:legacytoken1',
			'none'   => null,
		);
		$snapshot_id   = $this->make_production_layout_snapshot( $site_id, $type, $child_task_id, $run_keys[ $run_key_tag ] );

		$captured = array();
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $pre, $website, $what, $params ) use ( &$captured ) {
				$captured[] = array(
					'website_id' => (int) $website->id,
					'what'       => $what,
					'params'     => $params,
				);
				return array( 'result' => 'ok' );
			},
			10,
			4
		);

		$candidates = array(
			array(
				'snapshot_ids'   => array( $snapshot_id ),
				'reason'         => 'age',
				'size_bytes'     => 1,
				'newest_created' => time(),
			),
		);

		try {
			MainWP_Backup_Retention_Executor::instance()->run_prune( $candidates, $this->make_task( array( 'delete_cloud_copies' => 0 ) ), false );

			$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id )->status );
			if ( empty( $expected ) ) {
				$this->assertSame( array(), $captured, 'A run with no child task has nothing to clean on the child.' );
				return;
			}
			$this->assertCount( 1, $captured, 'One child call per pruned site.' );
			$this->assertSame( 'backup_task_prune', $captured[0]['what'] );
			$this->assertSame( $site_id, $captured[0]['website_id'] );
			$this->assertSame( $expected, json_decode( $captured[0]['params']['task_ids'], true ) );
		} finally {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ) . ' WHERE site_id = %d', $site_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.
		}
	}

	/**
	 * Snapshot types and run key kinds.
	 *
	 * @return array
	 */
	public static function data_child_prune_layouts() {
		return array(
			'full in the shared folder'        => array( 'full', 'child', array( 'task-1791460037-full' ) ),
			'database in the shared folder'    => array( 'db', 'child', array( 'task-1791460037-db' ) ),
			'incremental in its own folder'    => array( 'incremental', 'child', array( 'task-1791460037-incremental' ) ),
			'incremental without a run key'    => array( 'incremental', 'none', array( 'task-1791460037-incremental' ) ),
			'legacy run in the shared folder'  => array( 'full', 'legacy', array() ),
			'full without a run key or folder' => array( 'full', 'none', array() ),
		);
	}

	/**
	 * A failing child call never blocks the prune.
	 *
	 * @return void
	 */
	public function test_prune_survives_child_call_failure() {
		global $wpdb;
		$site_id     = $this->create_test_site();
		$snapshot_id = $this->make_production_layout_snapshot( $site_id, 'full', 'task-prunetest2', $site_id . ':' . $this->task_id . ':c:task-prunetest2' );

		$calls = 0;
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function () use ( &$calls ) {
				++$calls;
				throw new \Exception( 'child unreachable' );
			}
		);

		$candidates = array(
			array(
				'snapshot_ids'   => array( $snapshot_id ),
				'reason'         => 'age',
				'size_bytes'     => 1,
				'newest_created' => time(),
			),
		);

		try {
			$result = MainWP_Backup_Retention_Executor::instance()->run_prune( $candidates, $this->make_task( array( 'delete_cloud_copies' => 0 ) ), false );

			$this->assertSame( 1, $calls, 'The child prune was attempted.' );
			$this->assertSame( 1, $result['pruned'], 'The prune completes despite the unreachable child.' );
			$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id )->status );
		} finally {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ) . ' WHERE site_id = %d', $site_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.
		}
	}

	/**
	 * The list view's latest-snapshot lookup never reports a pruned row.
	 *
	 * @return void
	 */
	public function test_get_latest_for_task_skips_pruned_rows() {
		list( $live )   = $this->make_snapshot_with_dir( array( 'created' => time() - 100 ) );
		list( $pruned ) = $this->make_snapshot_with_dir( array( 'status' => 'pruned' ) );
		unset( $pruned );

		$latest = MainWP_Backup_Snapshots::instance()->get_latest_for_task( $this->task_id );

		$this->assertSame( $live, (int) $latest->id, 'A pruned snapshot must not summarize the task upload state.' );
	}

	/**
	 * Deleting a task purges every restorable unit it owns: unlike
	 * retention's prune-worthy subset, task deletion has nothing left to
	 * keep, so a whole chain flips to pruned and its local directories
	 * come down.
	 *
	 * @return void
	 */
	public function test_purge_snapshots_for_task_prunes_full_chain_and_removes_dirs() {
		list( $base, $base_dir ) = $this->make_snapshot_with_dir();
		list( $inc, $inc_dir )   = $this->make_snapshot_with_dir(
			array(
				'type'      => 'incremental',
				'parent_id' => $base,
			)
		);

		$result = MainWP_Backup_Retention_Executor::instance()->purge_snapshots_for_task( $this->task_id, false );

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( 2, $result['snapshots_pruned'] );
		$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $base )->status );
		$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $inc )->status );
		$this->assertDirectoryDoesNotExist( $base_dir );
		$this->assertDirectoryDoesNotExist( $inc_dir );
	}

	/**
	 * delete_cloud_copies = false purges local state and upload rows but
	 * never enqueues a cloud delete job.
	 *
	 * @return void
	 */
	public function test_purge_snapshots_for_task_without_cloud_copies_skips_delete_jobs() {
		list( $snapshot_id ) = $this->make_snapshot_with_dir();
		$this->insert_upload_job( $snapshot_id, 77 );

		MainWP_Backup_Retention_Executor::instance()->purge_snapshots_for_task( $this->task_id, false );

		$store = MainWP_Backup_Upload_Job_Store::instance();
		$this->assertSame( array(), $store->get_jobs_for_snapshot( $snapshot_id, 'delete' ) );
		$this->assertSame( array(), $store->get_jobs_for_snapshot( $snapshot_id, 'upload' ) );
	}

	/**
	 * delete_cloud_copies = true enqueues one cloud delete job per
	 * destination from the upload checkpoints, honoring the per-task
	 * column value passed in by the caller.
	 *
	 * @return void
	 */
	public function test_purge_snapshots_for_task_with_cloud_copies_enqueues_delete_jobs() {
		list( $snapshot_id ) = $this->make_snapshot_with_dir();
		$this->insert_upload_job( $snapshot_id, 77 );
		$this->insert_upload_job( $snapshot_id, 88 );

		MainWP_Backup_Retention_Executor::instance()->purge_snapshots_for_task( $this->task_id, true );

		$store       = MainWP_Backup_Upload_Job_Store::instance();
		$delete_jobs = $store->get_jobs_for_snapshot( $snapshot_id, 'delete' );

		$this->assertCount( 2, $delete_jobs, 'One delete job per destination.' );
		$this->assertSame( array(), $store->get_jobs_for_snapshot( $snapshot_id, 'upload' ) );
	}

	/**
	 * A task spanning two sites purges every site's units, not just the
	 * first one found.
	 *
	 * @return void
	 */
	public function test_purge_snapshots_for_task_spans_two_sites() {
		global $wpdb;

		$second_site = $this->create_test_site();

		list( $first_id, $first_dir ) = $this->make_snapshot_with_dir();

		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . 'mainwp/0/' . $second_site . '/snapshot-task-reten-second-' . uniqid();
		wp_mkdir_p( $dir );
		$this->fixture_dirs[] = $dir;
		$path                 = $dir . '/artifact.tar';
		file_put_contents( $path, str_repeat( 'x', 100 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			$table,
			array(
				'task_id'       => $this->task_id,
				'site_id'       => $second_site,
				'type'          => 'full',
				'created'       => time(),
				'archives'      => wp_json_encode( array( array( 'file' => 'artifact.tar', 'size' => 100, 'role' => 'full', 'path' => $path ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
				'manifest_path' => $dir . '/files.list',
				'status'        => 'complete',
			)
		);
		$second_id = (int) $wpdb->insert_id;

		$result = MainWP_Backup_Retention_Executor::instance()->purge_snapshots_for_task( $this->task_id, false );

		$this->assertSame( 2, $result['snapshots_pruned'] );
		$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $first_id )->status );
		$this->assertSame( 'task_deleted', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $first_id )->removal_reason );
		$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $second_id )->status );
		$this->assertSame( 'task_deleted', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $second_id )->removal_reason );
		$this->assertDirectoryDoesNotExist( $first_dir );
		$this->assertDirectoryDoesNotExist( $dir );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE site_id = %d", $second_site ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.
	}

	/**
	 * The #24 incident: a kept unit whose local file was already removed
	 * out-of-band still reads 'complete' because get_units_for_site_task()
	 * has no is_file() check. Purging its own task must still succeed
	 * cleanly (a missing file is not an error), and must never disturb a
	 * kept unit that belongs to a different task.
	 *
	 * @return void
	 */
	public function test_purge_snapshots_for_task_missing_file_unit_does_not_cascade() {
		list( $intact_id, $intact_dir )   = $this->make_snapshot_with_dir();
		list( $missing_id, $missing_dir ) = $this->make_snapshot_with_dir();

		// Out-of-band deletion: the file is gone but the row still reads 'complete'.
		unlink( $missing_dir . '/artifact.tar' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		$other_task_id   = $this->task_id + 1;
		list( $kept_id ) = $this->make_snapshot_with_dir( array( 'task_id' => $other_task_id ) );

		$result = MainWP_Backup_Retention_Executor::instance()->purge_snapshots_for_task( $this->task_id, false );

		$this->assertSame( array(), $result['errors'], 'A file already gone out-of-band is not an error.' );
		$this->assertSame( 2, $result['snapshots_pruned'] );
		$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $intact_id )->status );
		$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $missing_id )->status );
		$this->assertDirectoryDoesNotExist( $intact_dir );

		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $kept_id )->status, "An unrelated task's kept unit must not be touched." );
	}
	/**
	 * An interrupted chain reports only the leaf removed in this pass.
	 *
	 * @return void
	 */
	public function test_partial_prune_event_contains_only_pruned_snapshots() {
		$outside = tempnam( sys_get_temp_dir(), 'retention-outside-' );
		file_put_contents( $outside, 'retained artifact' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		list( $base ) = $this->make_snapshot_with_dir(
			array(
				'archives' => wp_json_encode(
					array(
						array(
							'file' => 'outside.tar',
							'path' => $outside,
						),
					)
				),
			)
		);
		list( $leaf ) = $this->make_snapshot_with_dir( array( 'parent_id' => $base ) );
		$events       = array();
		$capture      = static function ( $event ) use ( &$events ) {
			$events[] = $event;
		};
		add_action( 'mainwp_backup_pruned', $capture );
		try {
			$result = MainWP_Backup_Retention_Executor::instance()->run_prune(
				array(
					array(
						'snapshot_ids' => array( $base, $leaf ),
						'reason'       => 'count',
					),
				),
				$this->make_task( array( 'delete_cloud_copies' => 0 ) ),
				false
			);
		} finally {
			remove_action( 'mainwp_backup_pruned', $capture );
			unlink( $outside );
		}
		$this->assertSame( 1, $result['snapshots_pruned'] );
		$this->assertSame( 'retention', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $leaf )->removal_reason );
		$this->assertSame( '', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $base )->removal_reason );
		$this->assertSame( 0, $result['pruned'] );
		$this->assertSame( array( $leaf ), $result['deleted_ids'] );
		$this->assertSame( array( $base ), $result['remaining_ids'] );
		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $base )->status );
		$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $leaf )->status );
		$this->assertCount( 1, $events );
		$this->assertSame( array( $leaf ), $events[0]['snapshot_ids'], 'The event must exclude the retained ancestor.' );
	}

	/**
	 * An unlink failure withdraws only this pass's unclaimed cloud deletes.
	 *
	 * @dataProvider s90_r1_delete_claims
	 * @param bool $claim_delete Whether the delete lane wins before the failed unlink.
	 */
	public function test_s90_r1_failed_unlink_withdraws_new_cloud_deletes( bool $claim_delete ) {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Root bypasses directory write permissions.' );
		}
		global $wpdb;
		list( $id, $dir ) = $this->make_snapshot_with_dir();
		$this->insert_upload_job( $id, 77 );
		$this->insert_upload_job( $id, 78 );
		$store = MainWP_Backup_Upload_Job_Store::instance();
		$this->assertTrue( $store->enqueue_delete_job( $id, 78, array( 'owner' => 'destination removal' ) ) );
		$table     = MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' );
		$armed     = false;
		$changed   = false;
		$claimed   = null;
		$intervene = function ( $sql ) use ( &$intervene, &$armed, &$changed, &$claimed, $table, $dir, $store, $claim_delete ) {
			if ( $armed ) {
				remove_filter( 'query', $intervene );
				$changed = chmod( $dir, 0555 );
				clearstatcache();
				if ( $claim_delete ) {
					// The older destination-removal row is deferred, so this pickup claims the new row.
					$claimed = $store->acquire_next_job( 's90-delete', 300, null, MainWP_Backup_Upload_Job_Store::KIND_DELETE );
				}
			} elseif ( str_starts_with( $sql, 'INSERT IGNORE INTO ' . $table ) && str_contains( $sql, "'delete'" ) ) {
				$armed = true;
			}
			return $sql;
		};
		$existing  = $store->get_jobs_for_snapshot( $id, MainWP_Backup_Upload_Job_Store::KIND_DELETE )[0];
		$wpdb->update( $table, array( 'next_retry_at' => time() + 600 ), array( 'id' => $existing->id ) );
		add_filter( 'query', $intervene );
		try {
			$result = MainWP_Backup_Retention_Executor::instance()->run_prune(
				array(
					array(
						'snapshot_ids' => array( $id ),
						'reason'       => 'count',
					),
				),
				$this->make_task()
			);
		} finally {
			remove_filter( 'query', $intervene );
			chmod( $dir, 0755 );
			clearstatcache();
		}
		$this->assertTrue( $changed, 'Permissions must change after preflight and enqueue.' );
		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id )->status );
		$this->assertFileExists( $dir . '/artifact.tar' );
		$this->assertSame( array( $id ), $result['remaining_ids'] );
		$this->assertSame( array(), $result['deleted_ids'] );
		$this->assertSame( 0, $result['snapshots_pruned'] );
		$deletes = $store->get_jobs_for_snapshot( $id, MainWP_Backup_Upload_Job_Store::KIND_DELETE );
		$this->assertCount( $claim_delete ? 2 : 1, $deletes );
		$this->assertSame( $existing->checkpoint, $store->get_job( (int) $existing->id )->checkpoint );
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_PENDING, $store->get_job( (int) $existing->id )->status );
		$destinations = array_column( $result['cloud_deletes_queued'], 'destination_id' );
		$this->assertContains( 78, $destinations, 'A pre-existing delete remains owned by its original flow.' );
		$message = sprintf( 'Snapshot %d: its cloud copy is already being deleted, but the local files could not be removed. Try again later.', $id );
		if ( $claim_delete ) {
			$this->assertNotNull( $claimed );
			$this->assertSame( 77, (int) $claimed->destination_id );
			$this->assertContains( 77, $destinations );
			$this->assertSame( 1, count( array_keys( $result['errors'], $message, true ) ) );
			$this->assertSame( 's90-delete', $store->get_job( (int) $claimed->id )->locked_by );
		} else {
			$this->assertNotContains( 77, $destinations );
			$this->assertNotContains( $message, $result['errors'] );
		}
	}

	/** A later enqueue failure must withdraw the earlier destination inserted by the same pass. */
	public function test_s90_r1_partial_enqueue_withdraws_new_cloud_deletes() {
		global $wpdb;
		list( $id, $dir ) = $this->make_snapshot_with_dir();
		$this->insert_upload_job( $id, 77 );
		$this->insert_upload_job( $id, 78 );
		$table    = MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' );
		$failed   = false;
		$fail     = static function ( $sql ) use ( $table, $id, &$failed ) {
			if ( str_starts_with( $sql, 'INSERT IGNORE INTO ' . $table ) && str_contains( $sql, "({$id}, 78, 'delete'" ) ) {
				$failed = true;
				return str_replace( '(snapshot_id,', '(missing_snapshot_id,', $sql );
			}
			return $sql;
		};
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail );
		try {
			$result = MainWP_Backup_Retention_Executor::instance()->run_prune(
				array(
					array(
						'snapshot_ids' => array( $id ),
						'reason'       => 'count',
					),
				),
				$this->make_task()
			);
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $suppress );
		}
		$this->assertTrue( $failed );
		$this->assertFileExists( $dir . '/artifact.tar' );
		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id )->status );
		$this->assertSame( array( $id ), $result['remaining_ids'] );
		$this->assertSame( array(), MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( $id, MainWP_Backup_Upload_Job_Store::KIND_DELETE ) );
		$this->assertSame( array(), $result['cloud_deletes_queued'] );
	}

	/**
	 * Delete lane interleavings.
	 *
	 * @return array Claim fixtures.
	 */
	public static function s90_r1_delete_claims(): array {
		return array(
			'unclaimed' => array( false ),
			'claimed'   => array( true ),
		);
	}

	/** Prune must leave an expired worker lease for the upload lane to reclaim and count. */
	public function test_s90_r1_prune_preserves_dead_upload_worker_reclaim_evidence() {
		global $wpdb;
		list( $id, $dir ) = $this->make_snapshot_with_dir();
		$this->insert_upload_job( $id, 77 );
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' );
		$wpdb->update(
			$table,
			array(
				'status'        => MainWP_Backup_Upload_Job_Store::STATUS_UPLOADING,
				'locked_by'     => 's90-dead-worker',
				'locked_until'  => time() - 60,
				'reclaim_count' => 1,
			),
			array( 'snapshot_id' => $id )
		);
		$result = MainWP_Backup_Retention_Executor::instance()->run_prune(
			array(
				array(
					'snapshot_ids' => array( $id ),
					'reason'       => 'count',
				),
			),
			$this->make_task()
		);
		$this->assertSame( array( $id ), $result['remaining_ids'] );
		$this->assertStringContainsString( 'an upload in progress', implode( ' ', $result['errors'] ) );
		$this->assertFileExists( $dir . '/artifact.tar' );
		$store = MainWP_Backup_Upload_Job_Store::instance();
		$job   = $store->get_jobs_for_snapshot( $id, MainWP_Backup_Upload_Job_Store::KIND_UPLOAD )[0];
		$this->assertSame( 's90-dead-worker', $job->locked_by );
		$this->assertSame( 1, (int) $job->reclaim_count );
		$reclaimed = $store->acquire_next_job( 's90-replacement' );
		$this->assertNotNull( $reclaimed );
		$this->assertSame( (int) $job->id, (int) $reclaimed->id );
		$this->assertSame( 2, (int) $reclaimed->reclaim_count );
	}

	/** A worker lease abandoned past the stale window no longer holds prune, as when native backups are off and no lane reclaims it. */
	public function test_s90_r2_prune_takes_stale_dead_upload_worker_lease() {
		global $wpdb;
		list( $id, $dir ) = $this->make_snapshot_with_dir();
		$this->insert_upload_job( $id, 77 );
		$wpdb->update(
			MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ),
			array(
				'status'        => MainWP_Backup_Upload_Job_Store::STATUS_UPLOADING,
				'locked_by'     => 's90-dead-worker',
				'locked_until'  => time() - HOUR_IN_SECONDS - 60,
				'reclaim_count' => 1,
			),
			array( 'snapshot_id' => $id )
		);
		$result = MainWP_Backup_Retention_Executor::instance()->run_prune(
			array(
				array(
					'snapshot_ids' => array( $id ),
					'reason'       => 'count',
				),
			),
			$this->make_task()
		);
		$this->assertSame( array( $id ), $result['deleted_ids'] );
		$this->assertSame( array(), $result['remaining_ids'] );
		$this->assertFileDoesNotExist( $dir . '/artifact.tar' );
		$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id )->status );
	}

	/** An incremental run that starts during a chain prune holds the rest of the walk. */
	public function test_s90_r2_incremental_run_started_mid_walk_holds_remaining_snapshots() {
		global $wpdb;
		$db   = \MainWP\Dashboard\MainWP_DB_Backup::instance();
		$task = $db->add_backup_task( get_current_user_id(), 'Mid-walk incremental', 'daily', 'incremental', '', (string) $this->site_id, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0 );
		$run  = time() - 100;
		$db->update_backup_task_with_values(
			$task->id,
			array(
				'last_run'  => $run,
				'completed' => $run + 10,
			)
		);
		list( $base, $base_dir ) = $this->make_snapshot_with_dir();
		list( $leaf, $leaf_dir ) = $this->make_snapshot_with_dir(
			array(
				'type'      => 'incremental',
				'parent_id' => $base,
			)
		);
		$table                   = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$started                 = false;
		$start                   = function ( $sql ) use ( &$started, $db, $task, $run, $table, $leaf ) {
			if ( ! $started && str_starts_with( $sql, "UPDATE `{$table}` SET `status` = 'pruned'" ) && str_contains( $sql, '`id` = ' . $leaf ) ) {
				// The run claims its window and writes its creating marker before it picks a parent.
				$started = true;
				$db->update_backup_task_with_values( $task->id, array( 'completed' => 0 ) );
				$db->add_backup_task_progress( $task->id, $this->site_id, array() );
				$db->update_backup_task_progress(
					$task->id,
					$this->site_id,
					array(
						'dtsFetched'           => $run,
						'child_backup_task_id' => \MainWP\Dashboard\MainWP_Backup_Executor::CREATING_MARKER_PREFIX . 's90-r2',
						'child_phase'          => '',
					)
				);
			}
			return $sql;
		};
		add_filter( 'query', $start );
		try {
			$result = MainWP_Backup_Retention_Executor::instance()->run_prune(
				array(
					array(
						'snapshot_ids' => array( $base, $leaf ),
						'reason'       => 'count',
					),
				),
				$this->make_task()
			);
		} finally {
			remove_filter( 'query', $start );
		}
		$this->assertTrue( $started, 'The run must start after the leaf is pruned.' );
		$this->assertSame( array( $leaf ), $result['deleted_ids'] );
		$this->assertSame( array( $base ), $result['remaining_ids'] );
		$this->assertSame( 0, $result['pruned'] );
		$this->assertFileDoesNotExist( $leaf_dir . '/artifact.tar' );
		$this->assertFileExists( $base_dir . '/artifact.tar' );
		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $base )->status );
		$this->assertContains( sprintf( 'Snapshots %d: an incremental backup is running on this site, so they were kept. Try the cleanup again once that backup finishes.', $base ), $result['errors'] );
	}

	/** An incremental run that starts between admission and the first snapshot's deletion holds the unit. */
	public function test_s90_r3_incremental_run_started_after_admission_holds_first_snapshot() {
		$db   = \MainWP\Dashboard\MainWP_DB_Backup::instance();
		$task = $db->add_backup_task( get_current_user_id(), 'Post-admission incremental', 'daily', 'incremental', '', (string) $this->site_id, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0 );
		$run  = time() - 100;
		$db->update_backup_task_with_values(
			$task->id,
			array(
				'last_run'  => $run,
				'completed' => $run + 10,
			)
		);
		list( $id, $dir ) = $this->make_snapshot_with_dir();
		$upload_table     = MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' );
		$started          = false;
		$start            = function ( $sql ) use ( &$started, $db, $task, $run, $upload_table ) {
			if ( ! $started && str_starts_with( $sql, 'UPDATE ' . $upload_table . " SET locked_by = 'prune:" ) ) {
				// Admission has passed; the run claims its window and writes its creating marker now.
				$started = true;
				$db->update_backup_task_with_values( $task->id, array( 'completed' => 0 ) );
				$db->add_backup_task_progress( $task->id, $this->site_id, array() );
				$db->update_backup_task_progress(
					$task->id,
					$this->site_id,
					array(
						'dtsFetched'           => $run,
						'child_backup_task_id' => \MainWP\Dashboard\MainWP_Backup_Executor::CREATING_MARKER_PREFIX . 's90-r3',
						'child_phase'          => '',
					)
				);
			}
			return $sql;
		};
		add_filter( 'query', $start );
		try {
			$result = MainWP_Backup_Retention_Executor::instance()->run_prune(
				array(
					array(
						'snapshot_ids' => array( $id ),
						'reason'       => 'count',
					),
				),
				$this->make_task()
			);
		} finally {
			remove_filter( 'query', $start );
		}
		$this->assertTrue( $started, 'The run must start after prune admission.' );
		$this->assertSame( array(), $result['deleted_ids'] );
		$this->assertSame( array( $id ), $result['remaining_ids'] );
		$this->assertFileExists( $dir . '/artifact.tar' );
		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id )->status );
		$this->assertContains( sprintf( 'Snapshots %d: an incremental backup is running on this site, so they were kept. Try the cleanup again once that backup finishes.', $id ), $result['errors'] );
	}

	/** An upload pickup at prune admission cannot outlive the cloud delete sweep. */
	public function test_x1_upload_acquisition_interleaves_with_prune_claim() {
		global $wpdb;
		list( $id ) = $this->make_snapshot_with_dir();
		$this->insert_upload_job( $id, 77 );
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ), array( 'status' => 'pending' ), array( 'snapshot_id' => $id ) );
		$store = new class() extends MainWP_Backup_Upload_Job_Store {
			/**
			 * Upload won at admission.
			 *
			 * @var object|null
			 */
			public $acquired = null;
			/**
			 * Admission seam was reached.
			 *
			 * @var bool
			 */
			public $attempted = false;
			/**
			 * Exercise the interleaving seam.
			 *
			 * @param array $ids Unit ids.
			 * @return bool Existing admission seam.
			 */
			public function has_live_upload_lease( array $ids ): bool {
				$live            = parent::has_live_upload_lease( $ids );
				$this->attempted = true;
				$this->acquired  = $this->acquire_next_job( 'x1-upload' );
				return $live;
			}
			/**
			 * Exercise the interleaving seam.
			 *
			 * @param array  $ids Unit ids.
			 * @param string $token Prune token.
			 * @param int    $lease_seconds Lease.
			 * @return bool Claimed.
			 */
			public function claim_uploads_for_prune( array $ids, string $token, int $lease_seconds = 300 ): bool {
				$claimed         = parent::claim_uploads_for_prune( $ids, $token, $lease_seconds );
				$this->attempted = true;
				$this->acquired  = $this->acquire_next_job( 'x1-upload' );
				return $claimed;
			}
			/**
			 * Exercise the interleaving seam.
			 *
			 * @param int   $snapshot_id Snapshot.
			 * @param int   $destination_id Destination.
			 * @param array $checkpoint Checkpoint.
			 * @return string Enqueue outcome.
			 */
			public function enqueue_delete_job_result( int $snapshot_id, int $destination_id, array $checkpoint ): string {
				$this->acquired = $this->acquired ?? $this->acquire_next_job( 'x1-late-upload' );
				return parent::enqueue_delete_job_result( $snapshot_id, $destination_id, $checkpoint );
			}
		};
		$prop  = new \ReflectionProperty( MainWP_Backup_Upload_Job_Store::class, 'instance' );
		$saved = $prop->getValue();
		$prop->setValue( null, $store );
		try {
			$result = MainWP_Backup_Retention_Executor::instance()->run_prune(
				array(
					array(
						'snapshot_ids' => array( $id ),
						'reason'       => 'count',
					),
				),
				$this->make_task()
			);
		} finally {
			$prop->setValue( null, $saved );
		}
		$this->assertTrue( $store->attempted );
		$this->assertNull( $store->acquired, 'A pickup after admission or during enqueue must lose to the prune claim.' );
		$this->assertSame( array( $id ), $result['deleted_ids'] );
		$this->assertSame( array(), $result['remaining_ids'] );
		$this->assertSame(
			array(
				array(
					'snapshot_id'    => $id,
					'destination_id' => 77,
				),
			),
			$result['cloud_deletes_queued']
		);
	}

	/** A failed tombstone after deleting a leaf leaves only that leaf in deleted_ids. */
	public function test_x1_tombstone_failure_reports_exact_ids() {
		global $wpdb;
		list( $base ) = $this->make_snapshot_with_dir();
		list( $leaf ) = $this->make_snapshot_with_dir( array( 'parent_id' => $base ) );
		$this->insert_upload_job( $base, 77 );
		$this->insert_upload_job( $leaf, 77 );
		$table    = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$fail     = static function ( $query ) use ( $table, $base ) {
			if ( str_starts_with( $query, 'UPDATE `' . $table . '` SET ' ) && str_contains( $query, '`status`' ) && str_contains( $query, '`id` = ' . $base ) ) {
				return str_replace( '`status`', '`missing_status_column`', $query );
			}
			return $query;
		};
		$suppress = $wpdb->suppress_errors( true );
		add_filter( 'query', $fail );
		try {
			$result = MainWP_Backup_Retention_Executor::instance()->run_prune(
				array(
					array(
						'snapshot_ids' => array( $base, $leaf ),
						'reason'       => 'count',
					),
				),
				$this->make_task()
			);
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $suppress );
		}
		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $base )->status );
		$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $leaf )->status );
		$this->assertSame( 0, $result['pruned'] );
		$this->assertSame( array( $leaf ), $result['deleted_ids'] );
		$this->assertSame( array( $base ), $result['remaining_ids'] );
		$this->assertSame(
			array(
				array(
					'snapshot_id'    => $leaf,
					'destination_id' => 77,
				),
				array(
					'snapshot_id'    => $base,
					'destination_id' => 77,
				),
			),
			$result['cloud_deletes_queued']
		);
	}

	/** Flat archive reservations are removed only with their deleted archive. */
	public function test_x1_prune_removes_only_deleted_archive_reservation_sidecars() {
		$id      = $this->make_production_layout_snapshot( $this->site_id, 'full', '', '' );
		$archive = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id )->archives[0]['path'];
		file_put_contents( $archive . '.reserved', '' );
		$kept = dirname( $archive ) . '/kept.zip';
		file_put_contents( $kept, 'kept' );
		file_put_contents( $kept . '.reserved', '' );
		MainWP_Backup_Snapshots::instance()->record_snapshot(
			array(
				'site_id'  => $this->site_id,
				'task_id'  => $this->task_id,
				'type'     => 'full',
				'archives' => array(
					array(
						'file' => 'kept.zip',
						'path' => $kept,
						'role' => 'full',
						'size' => 4,
					),
				),
			)
		);
		$result = MainWP_Backup_Retention_Executor::instance()->run_prune(
			array(
				array(
					'snapshot_ids' => array( $id ),
					'reason'       => 'count',
				),
			),
			$this->make_task()
		);
		$this->assertSame( 1, $result['snapshots_pruned'] );
		$this->assertFileDoesNotExist( $archive );
		$this->assertFileDoesNotExist( $archive . '.reserved' );
		$this->assertFileExists( $kept );
		$this->assertFileExists( $kept . '.reserved' );
	}

	/** A stale candidate must neither re-delete files nor report another prune. */
	public function test_already_pruned_leaf_is_skipped() {
		global $wpdb;
		list( $base ) = $this->make_snapshot_with_dir( array( 'run_key' => $this->site_id . ':' . $this->task_id . ':c:base-child' ) );
		list( $leaf ) = $this->make_snapshot_with_dir(
			array(
				'parent_id' => $base,
				'type'      => 'incremental',
				'run_key'   => $this->site_id . ':' . $this->task_id . ':c:leaf-child',
			)
		);
		$executor     = MainWP_Backup_Retention_Executor::instance();
		$first        = $executor->delete_snapshot( $this->site_id, $leaf, array( $leaf ), $this->make_task() );
		$this->assertSame( array( $leaf ), $first['deleted_ids'] );
		$this->create_test_site( array( 'id' => $this->site_id ) );
		$this->insert_upload_job( $leaf, 77 );
		$table   = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$outside = tempnam( sys_get_temp_dir(), 'pruned-outside-' );
		file_put_contents( $outside, 'protected' );
		$wpdb->update(
			$table,
			array(
				'archives' => wp_json_encode(
					array(
						array(
							'path' => $outside,
							'size' => 999,
						),
					)
				),
			),
			array( 'id' => $leaf )
		);
		$events    = array();
		$child     = array();
		$writes    = array();
		$capture   = static function ( $event ) use ( &$events ) {
			$events[] = $event;
		};
		$transport = static function ( $value, $website, $what, $params ) use ( &$child ) {
			if ( 'backup_task_prune' === $what ) {
				$child[] = $params;
			}
			return array( 'result' => 'ok' );
		};
		$query     = static function ( $sql ) use ( &$writes, $table ) {
			if ( str_starts_with( $sql, 'UPDATE `' . $table . '`' ) ) {
				$writes[] = $sql;
			}
			return $sql;
		};
		add_action( 'mainwp_backup_pruned', $capture );
		add_filter( 'mainwp_fetch_url_authed_pre', $transport, 10, 4 );
		add_filter( 'query', $query );
		try {
			$result = $executor->run_prune(
				array(
					array(
						'snapshot_ids' => array( $base, $leaf ),
						'reason'       => 'count',
					),
				),
				$this->make_task()
			);
		} finally {
			remove_action( 'mainwp_backup_pruned', $capture );
			remove_filter( 'mainwp_fetch_url_authed_pre', $transport, 10 );
			remove_filter( 'query', $query );
			$outside_survived = file_exists( $outside );
			unlink( $outside );
		}
		$this->assertTrue( $outside_survived );
		$this->assertSame( array( $base ), $result['deleted_ids'] );
		$this->assertSame( array(), $result['remaining_ids'] );
		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( array(), $result['cloud_deletes_queued'] );
		$this->assertCount( 1, $writes );
		$this->assertSame( array( $base ), $events[0]['snapshot_ids'] );
		$this->assertCount( 1, $events );
		$this->assertSame( 100, $events[0]['size_bytes'] );
		$this->assertSame( 'manual', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $leaf )->removal_reason );
		$this->assertCount( 1, MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( $leaf, 'upload' ) );
		$this->assertSame( array(), MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( $leaf, 'delete' ) );
		$this->assertCount( 1, $child );
		$this->assertSame( array( 'base-child' ), json_decode( $child[0]['task_ids'], true ) );
	}

	/** The guarded UPDATE must not attribute a rival's removal to this pass. */
	public function test_guarded_prune_loser_preserves_first_reason() {
		$this->create_test_site( array( 'id' => $this->site_id ) );
		list( $base ) = $this->make_snapshot_with_dir( array( 'run_key' => $this->site_id . ':' . $this->task_id . ':c:base-child' ) );
		list( $leaf ) = $this->make_snapshot_with_dir(
			array(
				'parent_id' => $base,
				'type'      => 'incremental',
				'run_key'   => $this->site_id . ':' . $this->task_id . ':c:leaf-child',
			)
		);
		$this->insert_upload_job( $leaf, 77 );
		$table     = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$raced     = false;
		$race      = static function ( $sql ) use ( $table, $leaf, &$raced ) {
			if ( ! $raced && str_starts_with( $sql, 'UPDATE `' . $table . '` SET ' ) && str_contains( $sql, '`status`' ) ) {
				$raced = true;
				MainWP_Backup_Snapshots::instance()->mark_pruned( $leaf, 'manual' );
			}
			return $sql;
		};
		$events    = array();
		$capture   = static function ( $event ) use ( &$events ) {
			$events[] = $event;
		};
		$child     = array();
		$transport = static function ( $value, $website, $what, $params ) use ( &$child ) {
			if ( 'backup_task_prune' === $what ) {
				$child[] = $params;
			}
			return array( 'result' => 'ok' );
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $transport, 10, 4 );
		add_filter( 'query', $race );
		add_action( 'mainwp_backup_pruned', $capture );
		try {
			$result = MainWP_Backup_Retention_Executor::instance()->run_prune(
				array(
					array(
						'snapshot_ids' => array( $base, $leaf ),
						'reason'       => 'count',
					),
				),
				$this->make_task()
			);
		} finally {
			remove_filter( 'query', $race );
			remove_filter( 'mainwp_fetch_url_authed_pre', $transport, 10 );
			remove_action( 'mainwp_backup_pruned', $capture );
		}
		$this->assertTrue( $raced );
		$this->assertCount( 1, $child );
		$this->assertSame( array( 'base-child' ), json_decode( $child[0]['task_ids'], true ) );
		$this->assertSame( 'manual', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $leaf )->removal_reason );
		$this->assertSame( array( $base ), $result['deleted_ids'] );
		$this->assertSame( array(), $result['remaining_ids'] );
		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( array(), $result['cloud_deletes_queued'] );
		$this->assertCount( 1, MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( $leaf, 'delete' ), 'Both prunes may rely on the same cloud job once local files are gone.' );
		$this->assertCount( 1, $events );
		$this->assertSame( array( $base ), $events[0]['snapshot_ids'] );
		$this->assertSame( 100, $events[0]['size_bytes'] );
	}

	/** Invalid values must not become durable causes, and repeat writes lose. */
	public function test_mark_pruned_normalizes_unknown_reasons() {
		foreach ( array( '', 'constructor', 'x' ) as $reason ) {
			list( $id ) = $this->make_snapshot_with_dir();
			$store      = MainWP_Backup_Snapshots::instance();
			$this->assertTrue( $store->mark_pruned( $id, $reason ) );
			$this->assertSame( 'pruned', $store->get_snapshot_by_id( $id )->status );
			$this->assertSame( '', $store->get_snapshot_by_id( $id )->removal_reason );
			$this->assertFalse( $store->mark_pruned( $id, 'manual' ) );
			$this->assertSame( '', $store->get_snapshot_by_id( $id )->removal_reason );
		}
	}
	/**
	 * Produce a completed index for the fixture's actual recorded artifacts.
	 *
	 * @param int $snapshot_id Snapshot identity.
	 * @return array Matching remote artifacts.
	 */
	private function verified_artifacts( int $snapshot_id ): array {
		return array_map(
			static function ( $archive ) {
				return array(
					'file'   => $archive['file'],
					'role'   => $archive['role'],
					'size'   => $archive['size'],
					'sha256' => hash_file( 'sha256', $archive['path'] ),
					'mode'   => 'whole',
					'done'   => true,
				);
			},
			MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id )->archives
		);
	}

	/**
	 * Create a readable remote destination without making provider requests.
	 *
	 * @return int Destination identity.
	 */
	private function make_retention_destination(): int {
		return \MainWP\Dashboard\MainWP_Backup_Storage_Destinations::instance()->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Retention fixture',
				'settings' => array(),
			)
		);
	}

	/** A captured destination remains required after an enqueue failure and task edits. */
	public function test_remote_only_capture_holds_missing_upload_and_preserves_metadata() {
		$first  = $this->make_retention_destination();
		$second = $this->make_retention_destination();
		$this->assertTrue( class_exists( '\MainWP\Dashboard\MainWP_Backup_Retention_Protection' ), 'Remote-only retention protection must be available.' );
		$protection = '\MainWP\Dashboard\MainWP_Backup_Retention_Protection';
		$protection::save_task_remove_local_archives( $this->task_id, true );
		list( $id, $dir ) = $this->make_snapshot_with_dir();
		file_put_contents( $dir . '/files.list', 'child manifest' );
		$before = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id );
		$this->assertTrue( $protection::capture_required_destinations( $id, array( $first, $second ) ) );
		$store = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $id, array( $first ) );
		global $wpdb;
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' );
		$wpdb->update(
			$table,
			array(
				'provider'   => 's3',
				'status'     => 'complete',
				'checkpoint' => wp_json_encode(
					array(
						'manifest_submitted'  => true,
						'destination_binding' => \MainWP\Dashboard\MainWP_Backup_Retention_Protection::destination_binding( \MainWP\Dashboard\MainWP_Backup_Storage_Destinations::instance()->get_destination( $first ) ),
						'artifacts'           => $this->verified_artifacts( $id ),
						'uploaded_at'         => time(),
					)
				),
			),
			array( 'snapshot_id' => $id )
		);
		$executor = MainWP_Backup_Retention_Executor::instance();
		$executor->evict_local_archives_for_pair( $this->site_id, $this->task_id );
		$this->assertFileExists( $dir . '/artifact.tar' );
		$this->assertFalse( $protection::capture_required_destinations( $id, array( $first ) ), 'The initial requirement cannot be replaced.' );
		$store->enqueue_for_snapshot( $id, array( $second ) );
		$wpdb->update(
			$table,
			array(
				'provider'   => 's3',
				'status'     => 'complete',
				'checkpoint' => wp_json_encode(
					array(
						'manifest_submitted'  => true,
						'destination_binding' => \MainWP\Dashboard\MainWP_Backup_Retention_Protection::destination_binding( \MainWP\Dashboard\MainWP_Backup_Storage_Destinations::instance()->get_destination( $first ) ),
						'artifacts'           => $this->verified_artifacts( $id ),
						'uploaded_at'         => time(),
					)
				),
			),
			array( 'snapshot_id' => $id )
		);
		$executor->evict_local_archives_for_pair( $this->site_id, $this->task_id );
		$this->assertFileDoesNotExist( $dir . '/artifact.tar' );
		$this->assertSame( 'child manifest', file_get_contents( $dir . '/files.list' ) );
		$this->assertEquals( $before, MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id ) );
		$this->assertCount( 2, $store->get_jobs_for_snapshot( $id ) );
		$executor->evict_local_archives_for_pair( $this->site_id, $this->task_id );
		$this->assertFileExists( $dir . '/files.list', 'Repeated eviction keeps the Child manifest.' );
	}

	/** Automatic pruning holds young remote copies even under every retention cap. */
	public function test_remote_minimum_holds_automatic_pruning_but_manual_override_deletes() {
		$destinations     = \MainWP\Dashboard\MainWP_Backup_Storage_Destinations::instance();
		$destination      = $destinations->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Retention minimum',
				'settings' => array( 'minimum_remote_retention_days' => 30 ),
			)
		);
		list( $id, $dir ) = $this->make_snapshot_with_dir( array( 'created' => time() - 100 * DAY_IN_SECONDS ) );
		$store            = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $id, array( $destination ) );
		$job = $store->get_jobs_for_snapshot( $id )[0];
		$store->save_checkpoint(
			$job->id,
			array(
				'uploaded_at'        => time(),
				'manifest_submitted' => true,
			)
		);
		$store->complete_job( $job->id );
		$executor = MainWP_Backup_Retention_Executor::instance();
		$result   = $executor->run_prune(
			array(
				array(
					'snapshot_ids' => array( $id ),
					'reason'       => 'count',
					'size_bytes'   => 100,
				),
			),
			$this->make_task()
		);
		$this->assertSame( array(), $result['deleted_ids'], 'Snapshot age does not replace remote upload age.' );
		$this->assertFileExists( $dir . '/artifact.tar' );
		$this->assertCount( 1, $store->get_jobs_for_snapshot( $id ) );
		$result = $executor->purge_snapshots_for_task( $this->task_id, true, true );
		$this->assertSame( array( $id ), $result['deleted_ids'] );
		$this->assertTrue( $store->get_jobs_for_snapshot( $id, 'delete' )[0]->checkpoint['manual_override'] );
	}

	/** Failed partial uploads have no trustworthy storage age. */
	public function test_remote_minimum_holds_partial_upload_without_legacy_fallback() {
		$destinations     = \MainWP\Dashboard\MainWP_Backup_Storage_Destinations::instance();
		$destination      = $destinations->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Partial retention minimum',
				'settings' => array( 'minimum_remote_retention_days' => 30 ),
			)
		);
		list( $id, $dir ) = $this->make_snapshot_with_dir( array( 'created' => time() - 100 * DAY_IN_SECONDS ) );
		$store            = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $id, array( $destination ) );
		$store->fail_permanent( $store->get_jobs_for_snapshot( $id )[0]->id, 'Failed after a chunk upload' );
		$result = MainWP_Backup_Retention_Executor::instance()->purge_snapshots_for_task( $this->task_id, true );
		$this->assertSame( array(), $result['deleted_ids'] );
		$this->assertFileExists( $dir . '/artifact.tar' );
		$this->assertSame( array(), $store->get_jobs_for_snapshot( $id, 'delete' ) );
	}

	/** Each destination uses upload age, with legacy fallback limited to complete jobs. */
	public function test_remote_minimum_exact_boundary_legacy_fallback_and_unknown_age() {
		$this->assertTrue( class_exists( '\MainWP\Dashboard\MainWP_Backup_Retention_Protection' ) );
		$protection   = '\MainWP\Dashboard\MainWP_Backup_Retention_Protection';
		$now          = time();
		$destinations = \MainWP\Dashboard\MainWP_Backup_Storage_Destinations::instance();
		$destination  = $destinations->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Boundary minimum',
				'settings' => array( 'minimum_remote_retention_days' => 30 ),
			)
		);
		list( $id )   = $this->make_snapshot_with_dir( array( 'created' => $now - 100 * DAY_IN_SECONDS ) );
		$store        = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $id, array( $destination ) );
		$job = $store->get_jobs_for_snapshot( $id )[0];
		$store->save_checkpoint(
			$job->id,
			array(
				'uploaded_at'        => $now - 30 * DAY_IN_SECONDS,
				'manifest_submitted' => true,
			)
		);
		$store->complete_job( $job->id );
		$this->assertSame( $now, $protection::remote_protection( array( $id ), $now )['protected_until'] );
		$this->assertTrue( $protection::holds_automatic_removal( array( $id ), $now - 1 ) );
		$this->assertFalse( $protection::holds_automatic_removal( array( $id ), $now ) );
		$store->save_checkpoint( $job->id, array() );
		$this->assertSame( $now - 70 * DAY_IN_SECONDS, $protection::remote_protection( array( $id ), $now )['protected_until'] );
		$store->set_status( $job->id, 'failed_permanent' );
		$failed = $protection::observe_remote_protection( array( $id ), $now );
		$this->assertFalse( $failed['unknown_age'] );
		$this->assertSame( $now + 30 * DAY_IN_SECONDS, $failed['protected_until'] );
	}

	/** A failed destination read cannot make its unknown minimum disappear. */
	public function test_remote_minimum_unreadable_destination_keeps_snapshot_metadata() {
		$destination      = \MainWP\Dashboard\MainWP_Backup_Storage_Destinations::instance()->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Unreadable minimum',
				'settings' => array( 'minimum_remote_retention_days' => 30 ),
			)
		);
		list( $id, $dir ) = $this->make_snapshot_with_dir();
		$store            = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $id, array( $destination ) );
		global $wpdb;
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_storage' ), array( 'settings' => 'unreadable-settings' ), array( 'id' => $destination ) );
		$result = MainWP_Backup_Retention_Executor::instance()->purge_snapshots_for_task( $this->task_id, true );
		$this->assertSame( array(), $result['deleted_ids'] );
		$this->assertFileExists( $dir . '/artifact.tar' );
		$this->assertCount( 1, $store->get_jobs_for_snapshot( $id ) );
	}

	/** Eviction waits on restore reservations, live transfers and unfinished checkpoints. */
	public function test_remote_only_eviction_holds_and_resumes_without_metadata_changes() {
		$destination = $this->make_retention_destination();
		$this->assertTrue( class_exists( '\MainWP\Dashboard\MainWP_Backup_Retention_Protection' ) );
		$protection = '\MainWP\Dashboard\MainWP_Backup_Retention_Protection';
		$protection::save_task_remove_local_archives( $this->task_id, true );
		list( $id, $dir ) = $this->make_snapshot_with_dir();
		$protection::capture_required_destinations( $id, array( $destination ) );
		$store = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $id, array( $destination ) );
		$job      = $store->get_jobs_for_snapshot( $id )[0];
		$executor = MainWP_Backup_Retention_Executor::instance();
		foreach ( array( 'pending', 'failed_retryable', 'failed_permanent', 'credentials_invalid', 'complete' ) as $status ) {
			$store->set_status( $job->id, $status );
			$executor->evict_local_archives_for_pair( $this->site_id, $this->task_id );
			$this->assertFileExists( $dir . '/artifact.tar', $status . ' without verified checkpoint keeps source' );
		}
		global $wpdb;
		$wpdb->update(
			MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ),
			array(
				'provider'   => 's3',
				'status'     => 'complete',
				'checkpoint' => wp_json_encode(
					array(
						'uploaded_at'         => time(),
						'manifest_submitted'  => true,
						'destination_binding' => \MainWP\Dashboard\MainWP_Backup_Retention_Protection::destination_binding( \MainWP\Dashboard\MainWP_Backup_Storage_Destinations::instance()->get_destination( $destination ) ),
						'artifacts'           => $this->verified_artifacts( $id ),
					)
				),
			),
			array( 'id' => $job->id )
		);
		$reservation = MainWP_Backup_Restore_Job_Store::instance()->reserve_site( $this->site_id );
		$this->assertGreaterThan( 0, $reservation );
		$executor->evict_local_archives_for_pair( $this->site_id, $this->task_id );
		$this->assertFileExists( $dir . '/artifact.tar' );
		MainWP_Backup_Restore_Job_Store::instance()->release_reservation( $reservation );
		$store->claim_uploads_for_prune( array( $id ), 'prune:other' );
		$executor->evict_local_archives_for_pair( $this->site_id, $this->task_id );
		$this->assertFileExists( $dir . '/artifact.tar' );
		$store->release_prune_claims( 'prune:other' );
		$executor->evict_local_archives_for_pair( $this->site_id, $this->task_id, 0 );
		$this->assertFileExists( $dir . '/artifact.tar' );
		$executor->evict_local_archives_for_pair( $this->site_id, $this->task_id );
		$this->assertFileDoesNotExist( $dir . '/artifact.tar' );
		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id )->status );
	}
	/** A confirmed retry upgrades an existing automatic delete without losing its cursor. */
	public function test_manual_override_updates_existing_automatic_delete_consent() {
		$destination = \MainWP\Dashboard\MainWP_Backup_Storage_Destinations::instance()->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Existing delete',
				'settings' => array( 'minimum_remote_retention_days' => 30 ),
			)
		);
		list( $id )  = $this->make_snapshot_with_dir();
		$store       = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $id, array( $destination ) );
		$store->enqueue_delete_job(
			$id,
			$destination,
			array(
				'manual_override' => false,
				'cursor'          => 3,
				'keys'            => array(),
				'storage_age'     => array( 'unknown_age' => true ),
			)
		);
		$result = MainWP_Backup_Retention_Executor::instance()->purge_snapshots_for_task( $this->task_id, true, true );
		$this->assertSame( array( $id ), $result['deleted_ids'] );
		$job = $store->get_jobs_for_snapshot( $id, 'delete' )[0];
		$this->assertTrue( $job->checkpoint['manual_override'] );
		$this->assertSame( 3, $job->checkpoint['cursor'] );
	}
	/** Enabling remote-only behavior does not change ordinary retention of legacy rows. */
	public function test_legacy_snapshot_without_capture_keeps_ordinary_retention() {
		$this->assertTrue( class_exists( '\MainWP\Dashboard\MainWP_Backup_Retention_Protection' ) );
		$protection       = '\MainWP\Dashboard\MainWP_Backup_Retention_Protection';
		list( $id, $dir ) = $this->make_snapshot_with_dir();
		$protection::save_task_remove_local_archives( $this->task_id, true );
		$this->assertFalse( $protection::holds_automatic_removal( array( $id ) ) );
		MainWP_Backup_Retention_Executor::instance()->evict_local_archives_for_pair( $this->site_id, $this->task_id );
		$this->assertFileExists( $dir . '/artifact.tar' );
		$result = MainWP_Backup_Retention_Executor::instance()->purge_snapshots_for_task( $this->task_id, false );
		$this->assertSame( array( $id ), $result['deleted_ids'] );
	}
	/** A failed second unlink resumes without deleting the Child manifest or upload records. */
	public function test_remote_only_interrupted_eviction_resumes_after_backup_hold() {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Root bypasses directory write permissions.' );
		}
		$destination = $this->make_retention_destination();
		$this->assertTrue( class_exists( '\MainWP\Dashboard\MainWP_Backup_Retention_Protection' ) );
		$protection = '\MainWP\Dashboard\MainWP_Backup_Retention_Protection';
		$protection::save_task_remove_local_archives( $this->task_id, true );
		list( $id, $dir ) = $this->make_snapshot_with_dir();
		$second_dir       = $dir . '/second';
		wp_mkdir_p( $second_dir );
		file_put_contents( $second_dir . '/second.tar', 'second archive' );
		file_put_contents( $dir . '/files.list', 'incremental manifest' );
		global $wpdb;
		$snapshot   = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id );
		$archives   = $snapshot->archives;
		$archives[] = array(
			'file' => 'second.tar',
			'role' => 'part',
			'size' => 14,
			'path' => $second_dir . '/second.tar',
		);
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'archives' => wp_json_encode( $archives ) ), array( 'id' => $id ) );
		$protection::capture_required_destinations( $id, array( $destination ) );
		$store = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $id, array( $destination ) );
		$wpdb->update(
			MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ),
			array(
				'provider'   => 's3',
				'status'     => 'complete',
				'checkpoint' => wp_json_encode(
					array(
						'uploaded_at'         => time(),
						'manifest_submitted'  => true,
						'destination_binding' => \MainWP\Dashboard\MainWP_Backup_Retention_Protection::destination_binding( \MainWP\Dashboard\MainWP_Backup_Storage_Destinations::instance()->get_destination( $destination ) ),
						'artifacts'           => $this->verified_artifacts( $id ),
					)
				),
			),
			array( 'snapshot_id' => $id )
		);
		$executor = MainWP_Backup_Retention_Executor::instance();
		$hold     = \MainWP\Dashboard\MainWP_Backup_Executor::BACKUP_HOLD_OPTION_PREFIX . $this->site_id;
		update_option( $hold, wp_json_encode( array( 'owner' => time() + 300 ) ) );
		$executor->evict_local_archives_for_pair( $this->site_id, $this->task_id );
		$this->assertFileExists( $dir . '/artifact.tar' );
		delete_option( $hold );
		chmod( $second_dir, 0500 );
		try {
			$result = $executor->evict_local_archives_for_pair( $this->site_id, $this->task_id );
			$this->assertFileDoesNotExist( $dir . '/artifact.tar' );
			$this->assertFileExists( $second_dir . '/second.tar' );
			$this->assertNotEmpty( $result['errors'] );
		} finally {
			chmod( $second_dir, 0700 );
		}
		$executor->evict_local_archives_for_pair( $this->site_id, $this->task_id );
		$this->assertFileDoesNotExist( $second_dir . '/second.tar' );
		$this->assertSame( 'incremental manifest', file_get_contents( $dir . '/files.list' ) );
		$this->assertCount( 1, $store->get_jobs_for_snapshot( $id ) );
		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id )->status );
		rmdir( $second_dir );
	}

	/** Destination minimums protect the entire incremental unit independently. */
	public function test_destination_minimums_hold_whole_chain_independently() {
		$this->assertTrue( class_exists( '\MainWP\Dashboard\MainWP_Backup_Retention_Protection' ) );
		$protection                = '\MainWP\Dashboard\MainWP_Backup_Retention_Protection';
		$destinations              = \MainWP\Dashboard\MainWP_Backup_Storage_Destinations::instance();
		$first                     = $destinations->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Long minimum',
				'settings' => array( 'minimum_remote_retention_days' => 30 ),
			)
		);
		$second                    = $destinations->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Short minimum',
				'settings' => array( 'minimum_remote_retention_days' => 7 ),
			)
		);
		list( $base, $base_dir )   = $this->make_snapshot_with_dir();
		list( $child, $child_dir ) = $this->make_snapshot_with_dir(
			array(
				'type'      => 'incremental',
				'parent_id' => $base,
			)
		);
		$now                       = time();
		$store                     = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $child, array( $first, $second ) );
		foreach ( $store->get_jobs_for_snapshot( $child ) as $job ) {
			$store->save_checkpoint(
				$job->id,
				array(
					'uploaded_at'        => $now,
					'manifest_submitted' => true,
				)
			);
			$store->complete_job( $job->id );
		}
		$this->assertSame( $now + 30 * DAY_IN_SECONDS, $protection::remote_protection( array( $base, $child ) )['protected_until'] );
		$result = MainWP_Backup_Retention_Executor::instance()->purge_snapshots_for_task( $this->task_id, true );
		$this->assertSame( array(), $result['deleted_ids'] );
		$this->assertFileExists( $base_dir . '/artifact.tar' );
		$this->assertFileExists( $child_dir . '/artifact.tar' );
		$destinations->update_destination( $first, array( 'settings' => array( 'minimum_remote_retention_days' => 0 ) ) );
		$this->assertSame( $now + 7 * DAY_IN_SECONDS, $protection::remote_protection( array( $base, $child ) )['protected_until'] );
		$destinations->update_destination( $second, array( 'settings' => array( 'minimum_remote_retention_days' => 0 ) ) );
		$this->assertFalse( $protection::holds_automatic_removal( array( $base, $child ) ) );
	}
	/** Terminal flags cannot replace complete, matching remote artifact evidence. */
	public function test_remote_only_refuses_missing_mismatched_or_incomplete_artifact_indexes() {
		$destination = $this->make_retention_destination();
		$this->assertTrue( class_exists( '\MainWP\Dashboard\MainWP_Backup_Retention_Protection' ) );
		$protection = '\MainWP\Dashboard\MainWP_Backup_Retention_Protection';
		$protection::save_task_remove_local_archives( $this->task_id, true );
		list( $id, $dir ) = $this->make_snapshot_with_dir();
		global $wpdb;
		$snapshot              = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id );
		$archives              = $snapshot->archives;
		$hash                  = hash_file( 'sha256', $dir . '/artifact.tar' );
		$archives[0]['sha256'] = $hash;
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'archives' => wp_json_encode( $archives ) ), array( 'id' => $id ) );
		$protection::capture_required_destinations( $id, array( $destination ) );
		$store = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $id, array( $destination ) );
		$valid   = array(
			'file'   => 'artifact.tar',
			'role'   => 'full',
			'size'   => 100,
			'sha256' => $hash,
			'mode'   => 'whole',
			'done'   => true,
		);
		$invalid = array(
			'missing artifact'       => array(),
			'wrong size'             => array( array_merge( $valid, array( 'size' => 101 ) ) ),
			'wrong immutable hash'   => array( array_merge( $valid, array( 'sha256' => str_repeat( '0', 64 ) ) ) ),
			'incomplete chunk index' => array(
				array_merge(
					$valid,
					array(
						'mode'   => 'chunked',
						'chunks' => array(),
					)
				),
			),
		);
		foreach ( $invalid as $case => $artifacts ) {
			$wpdb->update(
				MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ),
				array(
					'provider'   => 's3',
					'status'     => 'complete',
					'checkpoint' => wp_json_encode(
						array(
							'uploaded_at'         => time(),
							'manifest_submitted'  => true,
							'destination_binding' => \MainWP\Dashboard\MainWP_Backup_Retention_Protection::destination_binding( \MainWP\Dashboard\MainWP_Backup_Storage_Destinations::instance()->get_destination( $destination ) ),
							'artifacts'           => $artifacts,
						)
					),
				),
				array( 'snapshot_id' => $id )
			);
			$this->assertFalse( $protection::required_uploads_verified( $id ), $case );
			MainWP_Backup_Retention_Executor::instance()->evict_local_archives_for_pair( $this->site_id, $this->task_id );
			$this->assertFileExists( $dir . '/artifact.tar', $case );
		}
		$wpdb->update(
			MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ),
			array(
				'checkpoint' => wp_json_encode(
					array(
						'uploaded_at'         => time(),
						'manifest_submitted'  => true,
						'destination_binding' => \MainWP\Dashboard\MainWP_Backup_Retention_Protection::destination_binding( \MainWP\Dashboard\MainWP_Backup_Storage_Destinations::instance()->get_destination( $destination ) ),
						'artifacts'           => array( $valid ),
					)
				),
			),
			array( 'snapshot_id' => $id )
		);
		$this->assertTrue( $protection::required_uploads_verified( $id ) );
		MainWP_Backup_Retention_Executor::instance()->evict_local_archives_for_pair( $this->site_id, $this->task_id );
		$this->assertFileDoesNotExist( $dir . '/artifact.tar' );
	}
	/**
	 * Build a captured verified upload against a real readable destination row.
	 *
	 * @return array Snapshot id, local directory and destination id.
	 */
	private function make_verified_retention_snapshot(): array {
		$destination      = \MainWP\Dashboard\MainWP_Backup_Storage_Destinations::instance()->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Verified retention fixture',
				'settings' => array(),
			)
		);
		list( $id, $dir ) = $this->make_snapshot_with_dir();
		$protection       = '\MainWP\Dashboard\MainWP_Backup_Retention_Protection';
		$protection::save_task_remove_local_archives( $this->task_id, true );
		$protection::capture_required_destinations( $id, array( $destination ) );
		$store = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $id, array( $destination ) );
		$job = $store->get_jobs_for_snapshot( $id )[0];
		$store->save_checkpoint(
			$job->id,
			array(
				'manifest_submitted'  => true,
				'destination_binding' => \MainWP\Dashboard\MainWP_Backup_Retention_Protection::destination_binding( \MainWP\Dashboard\MainWP_Backup_Storage_Destinations::instance()->get_destination( $destination ) ),
				'uploaded_at'         => time(),
				'artifacts'           => $this->verified_artifacts( $id ),
			)
		);
		$store->complete_job( $job->id );
		return array( $id, $dir, $destination );
	}

	/** Deleting a destination during a restore hold must keep the remaining local source. */
	public function test_remote_only_deleted_destination_keeps_source_after_hold_releases() {
		list( $id, $dir, $destination ) = $this->make_verified_retention_snapshot();
		$protection                     = '\MainWP\Dashboard\MainWP_Backup_Retention_Protection';
		$this->assertTrue( $protection::required_uploads_verified( $id ) );
		$restores    = MainWP_Backup_Restore_Job_Store::instance();
		$reservation = $restores->reserve_site( $this->site_id );
		$this->assertGreaterThan( 0, $reservation );
		$executor = MainWP_Backup_Retention_Executor::instance();
		$executor->evict_local_archives_for_pair( $this->site_id, $this->task_id );
		$this->assertFileExists( $dir . '/artifact.tar' );
		$result = \MainWP\Dashboard\MainWP_Backup_Storage_Handler::delete_destination_flow( $destination, true );
		$this->assertSame( array( 'deleted' => true ), $result );
		$job = MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( $id )[0];
		$this->assertSame( 'complete', $job->status, 'Destination removal retains completed upload history.' );
		$provider = new \ReflectionMethod( '\MainWP\Dashboard\MainWP_Backup_Restore_Transfer_Driver', 'provider_for_destination' );
		$this->assertNull( $provider->invoke( \MainWP\Dashboard\MainWP_Backup_Restore_Transfer_Driver::instance(), $destination ) );
		$restores->release_reservation( $reservation );
		$this->assertFalse( $protection::required_uploads_verified( $id ) );
		$executor->evict_local_archives_for_pair( $this->site_id, $this->task_id );
		$this->assertFileExists( $dir . '/artifact.tar' );
		$this->assertSame( array( $destination ), get_option( $protection::REQUIRED_OPTION_PREFIX . $id ) );
	}

	/**
	 * Changed or unreadable destinations cannot supply the recorded remote copy.
	 *
	 * @dataProvider unavailable_required_destinations
	 * @param array $mutation Stored destination changes.
	 */
	public function test_remote_only_unavailable_destination_keeps_source( array $mutation ) {
		list( $id, $dir, $destination ) = $this->make_verified_retention_snapshot();
		$protection                     = '\MainWP\Dashboard\MainWP_Backup_Retention_Protection';
		$this->assertTrue( $protection::required_uploads_verified( $id ) );
		global $wpdb;
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_storage' ), $mutation, array( 'id' => $destination ) );
		$this->assertFalse( $protection::required_uploads_verified( $id ) );
		MainWP_Backup_Retention_Executor::instance()->evict_local_archives_for_pair( $this->site_id, $this->task_id );
		$this->assertFileExists( $dir . '/artifact.tar' );
		$this->assertSame( array( $destination ), get_option( $protection::REQUIRED_OPTION_PREFIX . $id ) );
	}

	/**
	 * Destination mutations that make the recorded copy unavailable.
	 *
	 * @return array Mutations.
	 */
	public static function unavailable_required_destinations(): array {
		return array(
			'unreadable settings' => array( array( 'settings' => 'unreadable-settings' ) ),
			'changed provider'    => array( array( 'provider' => 'gcs' ) ),
		);
	}

	/** A failed pruning status write preserves verification for an automatic retry. */
	public function test_remote_only_failed_prune_status_retains_upload_evidence_for_retry() {
		list( $id ) = $this->make_verified_retention_snapshot();
		global $wpdb;
		$table     = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$fail      = static function ( $sql ) use ( $table, $id ) {
			if ( 0 === strpos( $sql, 'UPDATE `' . $table . '` SET ' ) && false !== strpos( $sql, '`id` = ' . $id ) ) {
				return str_replace( '`status`', '`missing_status_column`', $sql );
			}
			return $sql;
		};
		$candidate = array(
			array(
				'snapshot_ids' => array( $id ),
				'reason'       => 'count',
				'size_bytes'   => 100,
			),
		);
		$executor  = MainWP_Backup_Retention_Executor::instance();
		$store     = MainWP_Backup_Upload_Job_Store::instance();
		add_filter( 'query', $fail );
		$suppress = $wpdb->suppress_errors( true );
		try {
			$result = $executor->run_prune( $candidate, $this->make_task() );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $suppress );
		}
		$this->assertNotEmpty( $result['errors'] );
		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id )->status );
		$this->assertCount( 1, $store->get_jobs_for_snapshot( $id ), 'The retry needs the persisted required-upload evidence.' );
		$result = $executor->run_prune( $candidate, $this->make_task() );
		$this->assertSame( array( $id ), $result['deleted_ids'] );
		$this->assertSame( array(), $store->get_jobs_for_snapshot( $id ) );
		$this->assertFalse( get_option( \MainWP\Dashboard\MainWP_Backup_Retention_Protection::REQUIRED_OPTION_PREFIX . $id, false ) );
	}

	/** Confirmed pruned state completes evidence cleanup after an interrupted worker. */
	public function test_remote_only_already_pruned_retry_cleans_retained_upload_evidence() {
		list( $id, $dir ) = $this->make_verified_retention_snapshot();
		global $wpdb;
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'status' => 'pruned' ), array( 'id' => $id ) );
		unlink( $dir . '/artifact.tar' );
		$result = MainWP_Backup_Retention_Executor::instance()->run_prune(
			array(
				array(
					'snapshot_ids' => array( $id ),
					'reason'       => 'count',
					'size_bytes'   => 100,
				),
			),
			$this->make_task()
		);
		$this->assertSame( array(), $result['remaining_ids'] );
		$this->assertSame( array(), MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( $id ) );
		$this->assertFalse( get_option( \MainWP\Dashboard\MainWP_Backup_Retention_Protection::REQUIRED_OPTION_PREFIX . $id, false ) );
	}

	/** Same-provider target edits preserve local data while policy-only edits remain compatible. */
	public function test_remote_only_target_binding_rejects_target_edits_and_allows_minimum_change() {
		list( $id, $dir, $destination ) = $this->make_verified_retention_snapshot();
		$protection                     = '\MainWP\Dashboard\MainWP_Backup_Retention_Protection';
		$destinations                   = \MainWP\Dashboard\MainWP_Backup_Storage_Destinations::instance();
		$this->assertTrue( $protection::required_uploads_verified( $id ) );
		$destinations->update_destination( $destination, array( 'settings' => array( 'minimum_remote_retention_days' => 30 ) ) );
		$this->assertTrue( $protection::required_uploads_verified( $id ), 'Retention policy does not change remote object location.' );
		$destinations->update_destination(
			$destination,
			array(
				'settings' => array(
					'bucket'                        => 'replacement-bucket',
					'minimum_remote_retention_days' => 30,
				),
			)
		);
		$this->assertFalse( $protection::required_uploads_verified( $id ) );
		MainWP_Backup_Retention_Executor::instance()->evict_local_archives_for_pair( $this->site_id, $this->task_id );
		$this->assertFileExists( $dir . '/artifact.tar' );
		$this->assertSame( array( $destination ), get_option( $protection::REQUIRED_OPTION_PREFIX . $id ) );
	}

	/** Completed uploads without original target evidence retain the local archive. */
	public function test_remote_only_legacy_upload_without_binding_keeps_source() {
		list( $id, $dir ) = $this->make_verified_retention_snapshot();
		$store            = MainWP_Backup_Upload_Job_Store::instance();
		$job              = $store->get_jobs_for_snapshot( $id )[0];
		$checkpoint       = $job->checkpoint;
		unset( $checkpoint['destination_binding'] );
		$store->save_checkpoint( $job->id, $checkpoint );
		$this->assertFalse( \MainWP\Dashboard\MainWP_Backup_Retention_Protection::required_uploads_verified( $id ) );
		MainWP_Backup_Retention_Executor::instance()->evict_local_archives_for_pair( $this->site_id, $this->task_id );
		$this->assertFileExists( $dir . '/artifact.tar' );
	}

	/** A reused chain candidate skips its confirmed-pruned leaf and retries a failed base. */
	public function test_remote_only_partial_chain_retry_preserves_verification_and_skips_pruned_leaf() {
		list( $base ) = $this->make_verified_retention_snapshot();
		list( $leaf ) = $this->make_verified_retention_snapshot();
		global $wpdb;
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$wpdb->update(
			$table,
			array(
				'parent_id' => $base,
				'type'      => 'incremental',
			),
			array( 'id' => $leaf )
		);
		$fail      = static function ( $sql ) use ( $table, $base ) {
			return 0 === strpos( $sql, 'UPDATE `' . $table . '` SET ' ) && false !== strpos( $sql, '`id` = ' . $base ) ? str_replace( '`status`', '`missing_status_column`', $sql ) : $sql;
		};
		$executor  = MainWP_Backup_Retention_Executor::instance();
		$candidate = array(
			array(
				'snapshot_ids' => array( $base, $leaf ),
				'reason'       => 'count',
				'size_bytes'   => 200,
			),
		);
		add_filter( 'query', $fail );
		$suppress = $wpdb->suppress_errors( true );
		try {
			$first = $executor->run_prune( $candidate, $this->make_task() );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $suppress );
		}
		$this->assertSame( array( $leaf ), $first['deleted_ids'] );
		$this->assertSame( array( $base ), $first['remaining_ids'] );
		$this->assertCount( 1, MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( $base ) );
		$second = $executor->run_prune( $candidate, $this->make_task() );
		$this->assertSame( array( $base ), $second['deleted_ids'] );
		$this->assertSame( array(), $second['remaining_ids'] );
		$this->assertSame( array(), $second['errors'] );
	}
	/** Retained manifests and already-offloaded payloads do not consume eviction work. */
	public function test_remote_only_skips_offloaded_history_and_reaches_new_local_payload() {
		list( $old, $old_dir )     = $this->make_verified_retention_snapshot();
		list( $alias, $alias_dir ) = $this->make_verified_retention_snapshot();
		list( $fresh, $fresh_dir ) = $this->make_verified_retention_snapshot();
		file_put_contents( $old_dir . '/files.list', 'retained manifest' );
		file_put_contents( $old_dir . '/tombstones.list', 'retained tombstones' );
		$old_snapshot   = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $old );
		$old_archives   = $old_snapshot->archives;
		$old_archives[] = array(
			'file' => 'files.list',
			'role' => 'manifest',
			'path' => $old_dir . '/files.list',
			'size' => 17,
		);
		$old_archives[] = array(
			'file' => 'tombstones.list',
			'role' => 'tombstones',
			'path' => $old_dir . '/tombstones.list',
			'size' => 19,
		);
		global $wpdb;
		$snapshot_table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$wpdb->update( $snapshot_table, array( 'archives' => wp_json_encode( $old_archives ) ), array( 'id' => $old ) );
		$old_job                 = MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( $old )[0];
		$checkpoint              = $old_job->checkpoint;
		$checkpoint['artifacts'] = $this->verified_artifacts( $old );
		MainWP_Backup_Upload_Job_Store::instance()->save_checkpoint( $old_job->id, $checkpoint );
		unlink( $old_dir . '/artifact.tar' );
		// An archive descriptor can point at manifest_path even without a manifest role.
		$wpdb->update( $snapshot_table, array( 'manifest_path' => $alias_dir . '/artifact.tar' ), array( 'id' => $alias ) );
		$job_table       = MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' );
		$claims          = array();
		$protected_reads = array();
		$watch           = static function ( $sql ) use ( &$claims, &$protected_reads, $job_table, $snapshot_table, $old, $alias ) {
			if ( 0 === strpos( $sql, 'UPDATE ' . $job_table . ' SET locked_by' ) && preg_match( '/snapshot_id IN \(([0-9, ]+)\)/', $sql, $matches ) ) {
				foreach ( array_map( 'intval', explode( ',', $matches[1] ) ) as $id ) {
					$claims[] = $id;
					if ( in_array( $id, array( $old, $alias ), true ) ) {
						// Model slow historical verification without a wall-clock sleep.
						MainWP_Backup_Now_Continuation_Test::$fixed_microtime += 120;
					}
				}
			}
			if ( 0 === strpos( $sql, 'SELECT archives, manifest_path FROM ' . $snapshot_table ) && preg_match( '/id <> ([0-9]+)/', $sql, $matches ) ) {
				$protected_reads[] = (int) $matches[1];
			}
			return $sql;
		};
		MainWP_Backup_Now_Continuation_Test::$fixed_microtime = microtime( true );
		add_filter( 'query', $watch );
		try {
			$result = MainWP_Backup_Retention_Executor::instance()->evict_local_archives_for_pair( $this->site_id, $this->task_id, 60 );
		} finally {
			remove_filter( 'query', $watch );
			MainWP_Backup_Now_Continuation_Test::$fixed_microtime = null;
		}
		$this->assertSame( array( $fresh ), $result['evicted_ids'] );
		$this->assertSame( array( $fresh ), $claims, 'Only snapshots with an evictable payload need upload claims.' );
		$this->assertSame( array( $fresh ), $protected_reads, 'Already-offloaded history needs no site-wide protected-path scan.' );
		$this->assertFileDoesNotExist( $fresh_dir . '/artifact.tar' );
		$this->assertFileExists( $old_dir . '/files.list' );
		$this->assertFileExists( $old_dir . '/tombstones.list' );
		$this->assertFileExists( $alias_dir . '/artifact.tar' );
	}
}
