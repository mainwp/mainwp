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
			 * @return bool Always false.
			 */
			public function enqueue_delete_job( int $snapshot_id, int $destination_id, array $checkpoint ): bool {
				unset( $snapshot_id, $destination_id, $checkpoint );
				return false;
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
		$this->assertSame( array(), $events, 'An unrecorded prune must not emit a successful prune event.' );
		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $leaf )->status );
		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $base )->status );
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
		$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $second_id )->status );
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
		$this->assertSame( 0, $result['pruned'] );
		$this->assertSame( array( $leaf ), $result['deleted_ids'] );
		$this->assertSame( array( $base ), $result['remaining_ids'] );
		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $base )->status );
		$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $leaf )->status );
		$this->assertCount( 1, $events );
		$this->assertSame( array( $leaf ), $events[0]['snapshot_ids'], 'The event must exclude the retained ancestor.' );
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
			 * @return bool Queued.
			 */
			public function enqueue_delete_job( int $snapshot_id, int $destination_id, array $checkpoint ): bool {
				$this->acquired = $this->acquired ?? $this->acquire_next_job( 'x1-late-upload' );
				return parent::enqueue_delete_job( $snapshot_id, $destination_id, $checkpoint );
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
}
