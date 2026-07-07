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

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Backup_Retention_Executor;
use MainWP\Dashboard\MainWP_Backup_Retention_Query;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_Backup_Upload_Job_Store;

/**
 * Class MainWP_Backup_Retention_Executor_Test
 */
class MainWP_Backup_Retention_Executor_Test extends MainWP_Abilities_Test_Case {

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

		foreach ( $this->fixture_dirs as $dir ) {
			if ( is_dir( $dir ) ) {
				array_map( 'unlink', glob( $dir . '/*' ) ?: array() ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test cleanup.
				rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- test cleanup.
			}
		}
		$this->fixture_dirs = array();

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
	 * incremental and spine runs use) with artifact files and record a
	 * snapshot row over them.
	 *
	 * @param array $fields Snapshot row overrides.
	 *
	 * @return array{0: int, 1: string} Snapshot id and its local dir.
	 */
	private function make_snapshot_with_dir( array $fields = array() ) {
		global $wpdb;

		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . 'mainwp/snapshot-task-reten-' . uniqid();
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
					'path'   => '/tmp/big.tar',
					'file'   => 'big.tar',
					'role'   => 'full',
					'size'   => 5000,
					'mode'   => 'chunked',
					'chunks' => array(
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
		$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $inc )->status );
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
		list( $base )          = $this->make_snapshot_with_dir();
		list( $inc )           = $this->make_snapshot_with_dir(
			array(
				'type'      => 'incremental',
				'parent_id' => $base,
			)
		);
		list( $standalone )    = $this->make_snapshot_with_dir();

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
	 * Pruning notifies the child site so it cleans its own task rows and
	 * artifacts, passing the chain's child task ids.
	 *
	 * @return void
	 */
	public function test_prune_notifies_child_with_task_ids() {
		$site_id = $this->create_test_site();

		global $wpdb;
		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . 'mainwp/snapshot-task-prunetest1';
		wp_mkdir_p( $dir );
		$this->fixture_dirs[] = $dir;
		file_put_contents( $dir . '/artifact.tar', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			$table,
			array(
				'task_id'       => $this->task_id,
				'site_id'       => $site_id,
				'type'          => 'full',
				'created'       => time(),
				'archives'      => wp_json_encode( array( array( 'file' => 'artifact.tar', 'size' => 1, 'role' => 'full', 'path' => $dir . '/artifact.tar' ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
				'manifest_path' => '',
				'status'        => 'complete',
			)
		);
		$snapshot_id = (int) $wpdb->insert_id;

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

		MainWP_Backup_Retention_Executor::instance()->run_prune( $candidates, $this->make_task( array( 'delete_cloud_copies' => 0 ) ), false );

		$this->assertCount( 1, $captured, 'One child call per pruned site.' );
		$this->assertSame( 'backup_task_prune', $captured[0]['what'] );
		$this->assertSame( $site_id, $captured[0]['website_id'] );
		$this->assertContains( 'task-prunetest1', (array) json_decode( $captured[0]['params']['task_ids'], true ) );

		// Snapshot cleanup for the real site id.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE site_id = %d", $site_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.
	}

	/**
	 * A failing child call never blocks the prune.
	 *
	 * @return void
	 */
	public function test_prune_survives_child_call_failure() {
		$site_id = $this->create_test_site();

		global $wpdb;
		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . 'mainwp/snapshot-task-prunetest2';
		wp_mkdir_p( $dir );
		$this->fixture_dirs[] = $dir;
		file_put_contents( $dir . '/artifact.tar', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			$table,
			array(
				'task_id'       => $this->task_id,
				'site_id'       => $site_id,
				'type'          => 'full',
				'created'       => time(),
				'archives'      => wp_json_encode( array( array( 'file' => 'artifact.tar', 'size' => 1, 'role' => 'full', 'path' => $dir . '/artifact.tar' ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
				'manifest_path' => '',
				'status'        => 'complete',
			)
		);
		$snapshot_id = (int) $wpdb->insert_id;

		add_filter(
			'mainwp_fetch_url_authed_pre',
			function () {
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

		$result = MainWP_Backup_Retention_Executor::instance()->run_prune( $candidates, $this->make_task( array( 'delete_cloud_copies' => 0 ) ), false );

		$this->assertSame( 1, $result['pruned'], 'The prune completes despite the unreachable child.' );
		$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id )->status );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE site_id = %d", $site_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.
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
}
