<?php
/**
 * MainWP Backup Cloud Delete Driver Tests
 *
 * The delete driver drains kind=delete jobs from the upload job table:
 * it walks the checkpointed key list through the destination provider,
 * then sweeps the snapshot's remote prefix for anything the checkpoint
 * missed (partial uploads, drift), and never touches keys outside that
 * prefix. Failures map exactly like uploads: auth parks the job for
 * re-entered credentials, transient trouble backs off exponentially.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Backup_Cloud_Delete_Driver;
use MainWP\Dashboard\MainWP_Backup_Storage_Destinations;
use MainWP\Dashboard\MainWP_Backup_Upload_Driver;
use MainWP\Dashboard\MainWP_Backup_Upload_Job_Store;

/**
 * Class MainWP_Backup_Cloud_Delete_Driver_Test
 */
class MainWP_Backup_Cloud_Delete_Driver_Test extends \WP_UnitTestCase {

	/**
	 * Created temp dirs for cleanup.
	 *
	 * @var string[]
	 */
	private $temp_dirs = array();

	/**
	 * Clean fixture rows and dirs.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wpdb;

		$job_table = MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' );
		$wpdb->query( "DELETE FROM {$job_table} WHERE snapshot_id >= 980000" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.

		$storage_table = MainWP_DB::instance()->get_table_name( 'wp_backup_storage' );
		$wpdb->query( "DELETE FROM {$storage_table} WHERE name LIKE 'Delete driver test%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.

		foreach ( $this->temp_dirs as $dir ) {
			$this->remove_dir( $dir );
		}
		$this->temp_dirs = array();

		parent::tear_down();
	}

	/**
	 * Remove a directory tree.
	 *
	 * @param string $dir Directory.
	 *
	 * @return void
	 */
	private function remove_dir( $dir ) {
		if ( is_link( $dir ) ) {
			unlink( $dir );
			return;
		}
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			if ( $item->isLink() ) {
				unlink( $item->getPathname() );
			} elseif ( $item->isDir() ) {
				rmdir( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- test cleanup.
			} else {
				unlink( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test cleanup.
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- test cleanup.
	}

	/**
	 * Create a local storage destination over a temp base dir.
	 *
	 * @return array{0: int, 1: string} Destination id and base path.
	 */
	private function make_local_destination() {
		$base = sys_get_temp_dir() . '/mainwp-delete-driver-' . uniqid();
		wp_mkdir_p( $base );
		$this->temp_dirs[] = $base;

		$id = MainWP_Backup_Storage_Destinations::instance()->add_destination(
			array(
				'provider' => 'local',
				'name'     => 'Delete driver test ' . uniqid(),
				'settings' => array( 'base_path' => $base ),
			)
		);
		return array( $id, $base );
	}

	/**
	 * Create an S3 destination whose requests the test intercepts.
	 *
	 * @return int Destination id.
	 */
	private function make_s3_destination() {
		return MainWP_Backup_Storage_Destinations::instance()->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Delete driver test s3 ' . uniqid(),
				'settings' => array(
					'bucket'     => 'b',
					'region'     => 'us-east-1',
					'access_key' => 'k',
					'secret_key' => 's',
				),
			)
		);
	}

	/**
	 * Write remote object fixtures under a snapshot prefix.
	 *
	 * @param string $base        Destination base path.
	 * @param string $prefix      Snapshot prefix, e.g. '5/12/'.
	 * @param array  $names       Relative key names under the prefix.
	 *
	 * @return void
	 */
	private function write_remote_objects( $base, $prefix, array $names ) {
		foreach ( $names as $name ) {
			$path = $base . '/' . $prefix . $name;
			wp_mkdir_p( dirname( $path ) );
			file_put_contents( $path, 'data' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		}
	}

	/**
	 * Enqueue a delete job.
	 *
	 * @param int    $snapshot_id    Snapshot id.
	 * @param int    $destination_id Destination id.
	 * @param string $prefix         Snapshot prefix.
	 * @param array  $keys           Full remote keys.
	 *
	 * @return void
	 */
	private function enqueue_delete( $snapshot_id, $destination_id, $prefix, array $keys ) {
		MainWP_Backup_Upload_Job_Store::instance()->enqueue_delete_job(
			$snapshot_id,
			$destination_id,
			array(
				'prefix' => $prefix,
				'keys'   => array_map(
					static function ( $key ) {
						return array(
							'key'     => $key,
							'deleted' => false,
						);
					},
					$keys
				),
				'cursor' => 0,
				'swept'  => false,
			)
		);
	}

	/**
	 * A delete job removes its checkpointed keys, sweeps leftovers under
	 * the prefix, leaves other prefixes alone, and completes.
	 *
	 * @return void
	 */
	public function test_delete_job_removes_keys_sweeps_prefix_and_completes() {
		list( $dest_id, $base ) = $this->make_local_destination();

		$prefix = '5/980001/';
		$this->write_remote_objects( $base, $prefix, array( 'chunks/aaa.bin', 'files/small.txt', 'manifest.json', 'chunks/orphan.bin' ) );
		$this->write_remote_objects( $base, '5/980002/', array( 'manifest.json' ) );

		$this->enqueue_delete(
			980001,
			$dest_id,
			$prefix,
			array( $prefix . 'chunks/aaa.bin', $prefix . 'files/small.txt', $prefix . 'manifest.json' )
		);

		MainWP_Backup_Cloud_Delete_Driver::instance()->process_pending_delete_jobs( 60 );

		$this->assertFileDoesNotExist( $base . '/' . $prefix . 'chunks/aaa.bin' );
		$this->assertFileDoesNotExist( $base . '/' . $prefix . 'files/small.txt' );
		$this->assertFileDoesNotExist( $base . '/' . $prefix . 'manifest.json' );
		$this->assertFileDoesNotExist( $base . '/' . $prefix . 'chunks/orphan.bin', 'The sweep removes objects the checkpoint missed.' );
		$this->assertFileExists( $base . '/5/980002/manifest.json', 'Other snapshots are untouched.' );

		$job = MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( 980001, 'delete' )[0];
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_COMPLETE, $job->status );
		$this->assertTrue( $job->checkpoint['swept'] );
	}

	/**
	 * A zero budget makes no destructive progress; the job stays runnable
	 * and a later tick finishes it from the checkpoint.
	 *
	 * @return void
	 */
	public function test_budget_interrupt_resumes_from_checkpoint() {
		list( $dest_id, $base ) = $this->make_local_destination();

		$prefix = '5/980003/';
		$this->write_remote_objects( $base, $prefix, array( 'manifest.json' ) );
		$this->enqueue_delete( 980003, $dest_id, $prefix, array( $prefix . 'manifest.json' ) );

		MainWP_Backup_Cloud_Delete_Driver::instance()->process_pending_delete_jobs( -1 );

		$job = MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( 980003, 'delete' )[0];
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_PENDING, $job->status, 'A deadline interrupt leaves the job runnable.' );
		$this->assertFileExists( $base . '/' . $prefix . 'manifest.json' );

		// The lease from the interrupted tick was released; the next tick finishes.
		MainWP_Backup_Cloud_Delete_Driver::instance()->process_pending_delete_jobs( 60 );

		$job = MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( 980003, 'delete' )[0];
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_COMPLETE, $job->status );
		$this->assertFileDoesNotExist( $base . '/' . $prefix . 'manifest.json' );
	}

	/**
	 * Auth rejections park the job for re-entered credentials.
	 *
	 * @return void
	 */
	public function test_auth_failure_parks_credentials() {
		$dest_id = $this->make_s3_destination();
		$this->enqueue_delete( 980004, $dest_id, '5/980004/', array( '5/980004/manifest.json' ) );

		add_filter(
			'pre_http_request',
			function () {
				return array(
					'headers'  => array(),
					'body'     => 'AccessDenied',
					'response' => array(
						'code'    => 403,
						'message' => 'Forbidden',
					),
				);
			}
		);

		MainWP_Backup_Cloud_Delete_Driver::instance()->process_pending_delete_jobs( 60 );

		$job = MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( 980004, 'delete' )[0];
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_CREDENTIALS_INVALID, $job->status );
	}

	/**
	 * Network failures back off exponentially like uploads.
	 *
	 * @return void
	 */
	public function test_network_failure_backs_off() {
		$dest_id = $this->make_s3_destination();
		$this->enqueue_delete( 980005, $dest_id, '5/980005/', array( '5/980005/manifest.json' ) );

		add_filter(
			'pre_http_request',
			function () {
				return new \WP_Error( 'http_request_failed', 'timed out' );
			}
		);

		$now = time();
		MainWP_Backup_Cloud_Delete_Driver::instance()->process_pending_delete_jobs( 60, $now );

		$job = MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( 980005, 'delete' )[0];
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_FAILED_RETRYABLE, $job->status );
		$this->assertSame( 1, (int) $job->attempt_count );
		$this->assertSame( $now + 300, (int) $job->next_retry_at );
	}

	/**
	 * A false provider result must leave the object eligible for retry.
	 *
	 * @dataProvider false_delete_cases
	 * @param bool $sweep Whether the failed key is found by the prefix sweep.
	 * @return void
	 */
	public function test_false_delete_backs_off_without_advancing_checkpoint( $sweep ) {
		list( $dest_id ) = $this->make_local_destination();
		$key             = '5/980011/manifest.json';
		$this->enqueue_delete( 980011, $dest_id, '5/980011/', $sweep ? array() : array( $key ) );

		$provider = $this->createMock( \MainWP\Dashboard\MainWP_Backup_Storage_Provider::class );
		$provider->expects( $this->once() )->method( 'delete_object' )->with( $key )->willReturn( false );
		$provider->method( 'list_objects' )->willReturn(
			array(
				'objects'    => array( array( 'key' => $key ) ),
				'truncated'  => false,
				'next_token' => null,
			)
		);
		$destination  = MainWP_Backup_Storage_Destinations::instance()->get_destination( $dest_id );
		$destinations = $this->createMock( MainWP_Backup_Storage_Destinations::class );
		$destinations->method( 'get_destination' )->with( $dest_id )->willReturn( $destination );
		$destinations->method( 'make_provider' )->willReturn( $provider );
		$instance = new \ReflectionProperty( MainWP_Backup_Storage_Destinations::class, 'instance' );
		$original = $instance->getValue();
		$instance->setValue( null, $destinations );

		try {
			$now = time();
			MainWP_Backup_Cloud_Delete_Driver::instance()->process_pending_delete_jobs( 60, $now );
			$job = MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( 980011, 'delete' )[0];
			$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_FAILED_RETRYABLE, $job->status );
			$this->assertSame( 1, (int) $job->attempt_count );
			$this->assertStringContainsString( $key, $job->last_error );
			$this->assertSame( $now + 300, (int) $job->next_retry_at );
			$this->assertSame( 0, $job->checkpoint['cursor'] );
			$this->assertFalse( $job->checkpoint['swept'] );
			if ( ! $sweep ) {
				$this->assertFalse( $job->checkpoint['keys'][0]['deleted'] );
			}
		} finally {
			$instance->setValue( null, $original );
		}
	}

	/**
	 * Both deletion paths must preserve failed work.
	 *
	 * @return array
	 */
	public static function false_delete_cases() {
		return array(
			'checkpoint' => array( false ),
			'sweep'      => array( true ),
		);
	}

	/**
	 * A deleted destination fails the job permanently; its objects are
	 * unreachable anyway.
	 *
	 * @return void
	 */
	public function test_deleted_destination_fails_permanent() {
		$this->enqueue_delete( 980006, 999999, '5/980006/', array( '5/980006/manifest.json' ) );

		MainWP_Backup_Cloud_Delete_Driver::instance()->process_pending_delete_jobs( 60 );

		$job = MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( 980006, 'delete' )[0];
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_FAILED_PERMANENT, $job->status );
	}

	/**
	 * The upload driver never acquires delete jobs.
	 *
	 * @return void
	 */
	public function test_upload_driver_ignores_delete_jobs() {
		list( $dest_id, $base ) = $this->make_local_destination();

		$prefix = '5/980007/';
		$this->write_remote_objects( $base, $prefix, array( 'manifest.json' ) );
		$this->enqueue_delete( 980007, $dest_id, $prefix, array( $prefix . 'manifest.json' ) );

		MainWP_Backup_Upload_Driver::instance()->process_pending_jobs( 60 );

		$job = MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( 980007, 'delete' )[0];
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_PENDING, $job->status, 'The upload driver must leave delete jobs alone.' );
		$this->assertFileExists( $base . '/' . $prefix . 'manifest.json' );
	}

	/**
	 * The delete driver never acquires upload jobs.
	 *
	 * @return void
	 */
	public function test_delete_driver_ignores_upload_jobs() {
		list( $dest_id ) = $this->make_local_destination();

		MainWP_Backup_Upload_Job_Store::instance()->enqueue_for_snapshot( 980008, array( $dest_id ) );

		MainWP_Backup_Cloud_Delete_Driver::instance()->process_pending_delete_jobs( 60 );

		$job = MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( 980008, 'upload' )[0];
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_PENDING, $job->status, 'The delete driver must leave upload jobs alone.' );
	}

	/**
	 * An upload job and a delete job coexist for the same snapshot and
	 * destination thanks to the kind-aware unique key.
	 *
	 * @return void
	 */
	public function test_upload_and_delete_jobs_coexist() {
		list( $dest_id ) = $this->make_local_destination();
		$store           = MainWP_Backup_Upload_Job_Store::instance();

		$store->enqueue_for_snapshot( 980009, array( $dest_id ) );
		$this->enqueue_delete( 980009, $dest_id, '5/980009/', array() );

		$this->assertCount( 1, $store->get_jobs_for_snapshot( 980009, 'upload' ) );
		$this->assertCount( 1, $store->get_jobs_for_snapshot( 980009, 'delete' ) );
	}
}
