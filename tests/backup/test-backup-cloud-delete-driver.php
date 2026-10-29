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
		$this->assertArrayNotHasKey( 'storage_age_floor', $job->checkpoint, 'Minimum zero keeps the existing delete path.' );
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

	/** A short local delete must leave time for the remaining jobs. */
	public function test_one_tick_drains_three_jobs_with_distinct_released_leases() {
		list( $dest_id, $base ) = $this->make_local_destination();
		$tokens                 = array();
		$capture                = static function ( $sql ) use ( &$tokens ) {
			if ( str_contains( $sql, 'SET reclaim_count = CASE' ) && preg_match( "/locked_by = '([^']+)'/", $sql, $matches ) ) {
				$tokens[] = $matches[1];
			}
			return $sql;
		};
		foreach ( array( 980020, 980021, 980022 ) as $id ) {
			$prefix = '5/' . $id . '/';
			$this->write_remote_objects( $base, $prefix, array( 'manifest.json' ) );
			$this->enqueue_delete( $id, $dest_id, $prefix, array( $prefix . 'manifest.json' ) );
		}
		add_filter( 'query', $capture );
		try {
			MainWP_Backup_Cloud_Delete_Driver::instance()->process_pending_delete_jobs( 60 );
		} finally {
			remove_filter( 'query', $capture );
		}
		foreach ( array( 980020, 980021, 980022 ) as $id ) {
			$job = MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( $id, 'delete' )[0];
			$this->assertSame( 'complete', $job->status );
			$this->assertSame( 0, (int) $job->locked_until );
			$this->assertSame( '', $job->locked_by );
			$this->assertFileDoesNotExist( $base . '/5/' . $id . '/manifest.json' );
		}
		$this->assertGreaterThanOrEqual( 3, count( $tokens ) );
		$this->assertCount( count( $tokens ), array_unique( $tokens ), 'Every acquisition needs a fresh fence.' );
	}

	/**
	 * An exhausted budget must checkpoint once and yield before another claim.
	 *
	 * @dataProvider exhausted_budgets
	 * @param int $budget Tick budget.
	 */
	public function test_exhausted_budget_checkpoints_one_job_without_claiming_another( $budget ) {
		list( $dest_id, $base ) = $this->make_local_destination();
		foreach ( array( 980023, 980024 ) as $id ) {
			$prefix = '5/' . $id . '/';
			$this->write_remote_objects( $base, $prefix, array( 'manifest.json' ) );
			$this->enqueue_delete( $id, $dest_id, $prefix, array( $prefix . 'manifest.json' ) );
		}
		$claims  = 0;
		$saves   = 0;
		$capture = static function ( $sql ) use ( &$claims, &$saves ) {
			if ( str_contains( $sql, 'SET reclaim_count = CASE' ) ) {
				++$claims;
			}
			if ( str_starts_with( $sql, 'UPDATE' ) && str_contains( $sql, '`checkpoint` =' ) ) {
				++$saves;
			}
			return $sql;
		};
		add_filter( 'query', $capture );
		try {
			MainWP_Backup_Cloud_Delete_Driver::instance()->process_pending_delete_jobs( $budget );
		} finally {
			remove_filter( 'query', $capture );
		}
		$this->assertSame( 1, $claims );
		$this->assertSame( 1, $saves );
		foreach ( array( 980023, 980024 ) as $id ) {
			$job = MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( $id, 'delete' )[0];
			$this->assertSame( 'pending', $job->status );
			$this->assertSame( 0, $job->checkpoint['cursor'] );
			$this->assertSame( 0, (int) $job->locked_until );
			$this->assertFileExists( $base . '/5/' . $id . '/manifest.json' );
		}
	}

	/**
	 * Tick budgets with no remaining time.
	 *
	 * @return array
	 */
	public static function exhausted_budgets() {
		return array(
			'zero'    => array( 0 ),
			'expired' => array( -1 ),
		);
	}

	/** A future retry must not block later runnable jobs or spin. */
	public function test_drain_skips_backoff_and_preserves_job_order() {
		list( $dest_id, $base ) = $this->make_local_destination();
		$store                  = MainWP_Backup_Upload_Job_Store::instance();
		$now                    = time();
		foreach ( array( 980025, 980026, 980027 ) as $id ) {
			$prefix = '5/' . $id . '/';
			$this->write_remote_objects( $base, $prefix, array( 'manifest.json' ) );
			$this->enqueue_delete( $id, $dest_id, $prefix, array( $prefix . 'manifest.json' ) );
		}
		$backoff = $store->get_jobs_for_snapshot( 980025, 'delete' )[0];
		$store->fail_retryable( (int) $backoff->id, 'retry later', $now + 300 );
		$order   = array();
		$capture = static function ( $sql ) use ( &$order, $store ) {
			if ( str_contains( $sql, '`checkpoint` =' ) && str_starts_with( $sql, 'UPDATE' ) ) {
				foreach ( array( 980026, 980027 ) as $id ) {
					$job = $store->get_jobs_for_snapshot( $id, 'delete' )[0];
					if ( '' !== $job->locked_by && ! in_array( $id, $order, true ) ) {
						$order[] = $id;
					}
				}
			}
			return $sql;
		};
		add_filter( 'query', $capture );
		try {
			MainWP_Backup_Cloud_Delete_Driver::instance()->process_pending_delete_jobs( 60, $now );
		} finally {
			remove_filter( 'query', $capture );
		}
		$this->assertSame( array( 980026, 980027 ), $order );
		$this->assertSame( 'complete', $store->get_jobs_for_snapshot( 980027, 'delete' )[0]->status );
		$after = $store->get_jobs_for_snapshot( 980025, 'delete' )[0];
		$this->assertSame( 1, (int) $after->attempt_count );
		$this->assertSame( $now + 300, (int) $after->next_retry_at );
		$this->assertSame( 0, $after->checkpoint['cursor'] );
		$this->assertFileExists( $base . '/5/980025/manifest.json' );
	}

	/**
	 * Provider round trips expose both an overlapping tick and an expiring deadline.
	 *
	 * @dataProvider interrupted_delete_cases
	 * @param bool $expire Whether the provider consumes the remaining budget.
	 */
	public function test_delete_walk_yields_to_deadline_and_fences_overlapping_ticks( $expire ) {
		list( $dest_id ) = $this->make_local_destination();
		$this->enqueue_delete( 980028, $dest_id, '', array( 'first', 'second' ) );
		if ( $expire ) {
			$this->enqueue_delete( 980029, $dest_id, '', array( 'later' ) );
		}
		$keys     = array();
		$provider = $this->createMock( \MainWP\Dashboard\MainWP_Backup_Storage_Provider::class );
		$provider->method( 'delete_object' )->willReturnCallback(
			function ( $key ) use ( &$keys, $expire ) {
				$keys[] = $key;
				if ( $expire ) {
					usleep( 1100000 );
				} elseif ( 'first' === $key ) {
					$other = new MainWP_Backup_Cloud_Delete_Driver();
					$other->process_pending_delete_jobs( 60 );
				}
				return true;
			}
		);
		$destinations = $this->createMock( MainWP_Backup_Storage_Destinations::class );
		$destinations->method( 'get_destination' )->willReturn( MainWP_Backup_Storage_Destinations::instance()->get_destination( $dest_id ) );
		$destinations->method( 'make_provider' )->willReturn( $provider );
		$instance = new \ReflectionProperty( MainWP_Backup_Storage_Destinations::class, 'instance' );
		$original = $instance->getValue();
		$instance->setValue( null, $destinations );
		try {
			MainWP_Backup_Cloud_Delete_Driver::instance()->process_pending_delete_jobs( $expire ? 1 : 60 );
		} finally {
			$instance->setValue( null, $original );
		}
		$job = MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( 980028, 'delete' )[0];
		$this->assertSame( $expire ? array( 'first' ) : array( 'first', 'second' ), $keys );
		$this->assertSame( $expire ? 'pending' : 'complete', $job->status );
		$this->assertSame( $expire ? 1 : 2, $job->checkpoint['cursor'] );
		$this->assertSame( 0, (int) $job->locked_until );
		if ( $expire ) {
			$later = MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( 980029, 'delete' )[0];
			$this->assertSame( 0, $later->checkpoint['cursor'] );
			$this->assertSame( 'pending', $later->status );
			$this->assertSame( 0, (int) $later->locked_until );
		}
	}

	/**
	 * Provider interruptions.
	 *
	 * @return array
	 */
	public static function interrupted_delete_cases() {
		return array(
			'deadline' => array( true ),
			'overlap'  => array( false ),
		);
	}

	/** Time remains after a reclaimed lease, so only the yield keeps the tick from claiming the next job. */
	public function test_lost_lease_stops_the_drain_before_another_claim() {
		global $wpdb;

		list( $dest_id ) = $this->make_local_destination();
		$this->enqueue_delete( 980030, $dest_id, '', array( 'first', 'second' ) );
		$this->enqueue_delete( 980031, $dest_id, '', array( 'later' ) );
		$store    = MainWP_Backup_Upload_Job_Store::instance();
		$job_id   = (int) $store->get_jobs_for_snapshot( 980030, 'delete' )[0]->id;
		$table    = MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' );
		$keys     = array();
		$provider = $this->createMock( \MainWP\Dashboard\MainWP_Backup_Storage_Provider::class );
		$provider->method( 'delete_object' )->willReturnCallback(
			function ( $key ) use ( &$keys, $wpdb, $table, $job_id ) {
				$keys[] = $key;
				if ( 'first' === $key ) {
					$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture handing the lease to another worker mid-walk.
						$table,
						array(
							'locked_by'    => 'delete_thief',
							'locked_until' => time() + 300,
						),
						array( 'id' => $job_id )
					);
				}
				return true;
			}
		);
		$destinations = $this->createMock( MainWP_Backup_Storage_Destinations::class );
		$destinations->method( 'get_destination' )->willReturn( MainWP_Backup_Storage_Destinations::instance()->get_destination( $dest_id ) );
		$destinations->method( 'make_provider' )->willReturn( $provider );
		$instance = new \ReflectionProperty( MainWP_Backup_Storage_Destinations::class, 'instance' );
		$original = $instance->getValue();
		$instance->setValue( null, $destinations );
		try {
			MainWP_Backup_Cloud_Delete_Driver::instance()->process_pending_delete_jobs( 60 );
		} finally {
			$instance->setValue( null, $original );
		}
		$this->assertSame( array( 'first' ), $keys );
		$this->assertSame( 'delete_thief', $store->get_jobs_for_snapshot( 980030, 'delete' )[0]->locked_by );
		$later = $store->get_jobs_for_snapshot( 980031, 'delete' )[0];
		$this->assertSame( 'pending', $later->status );
		$this->assertSame( 0, $later->checkpoint['cursor'] );
		$this->assertSame( '', $later->locked_by );
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
	/** A queued automatic delete rechecks a minimum raised after enqueue. */
	public function test_delayed_automatic_delete_waits_for_current_minimum_then_expires() {
		list( $destination, $base ) = $this->make_local_destination();
		$prefix                     = '5/980099/';
		$this->write_remote_objects( $base, $prefix, array( 'files/a.tar', 'chunks/partial.bin' ) );
		$store = MainWP_Backup_Upload_Job_Store::instance();
		$now   = time();
		$store->enqueue_delete_job(
			980099,
			$destination,
			array(
				'prefix'          => $prefix,
				'keys'            => array(
					array(
						'key'     => $prefix . 'files/a.tar',
						'deleted' => false,
					),
				),
				'storage_age'     => array(
					'uploaded_at' => $now,
					'unknown_age' => false,
				),
				'manual_override' => false,
			)
		);
		MainWP_Backup_Storage_Destinations::instance()->update_destination(
			$destination,
			array(
				'settings' => array(
					'base_path'                     => $base,
					'minimum_remote_retention_days' => 30,
				),
			)
		);
		$driver = MainWP_Backup_Cloud_Delete_Driver::instance();
		$driver->process_pending_delete_jobs( 60, $now + 30 * DAY_IN_SECONDS - 1 );
		$this->assertFileExists( $base . '/' . $prefix . 'files/a.tar' );
		$this->assertFileExists( $base . '/' . $prefix . 'chunks/partial.bin' );
		$driver->process_pending_delete_jobs( 60, $now + 30 * DAY_IN_SECONDS );
		$this->assertFileDoesNotExist( $base . '/' . $prefix . 'files/a.tar' );
		$this->assertFileDoesNotExist( $base . '/' . $prefix . 'chunks/partial.bin' );
		$job = $store->get_jobs_for_snapshot( 980099, 'delete' )[0];
		$this->assertSame(
			array(
				'uploaded_at' => $now,
				'unknown_age' => false,
			),
			$job->checkpoint['storage_age']
		);
		$this->assertArrayNotHasKey( 'storage_age_floor', $job->checkpoint );
	}

	/** Unknown-age partial objects need an explicit manual bypass at positive minimum. */
	public function test_unknown_age_delete_holds_keys_and_prefix_until_manual_override() {
		list( $destination, $base ) = $this->make_local_destination();
		MainWP_Backup_Storage_Destinations::instance()->update_destination(
			$destination,
			array(
				'settings' => array(
					'base_path'                     => $base,
					'minimum_remote_retention_days' => 30,
				),
			)
		);
		$prefix = '5/980098/';
		$this->write_remote_objects( $base, $prefix, array( 'chunks/partial.bin' ) );
		$store = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_delete_job(
			980098,
			$destination,
			array(
				'prefix'          => $prefix,
				'storage_age'     => array(
					'uploaded_at' => 0,
					'unknown_age' => true,
				),
				'manual_override' => false,
			)
		);
		MainWP_Backup_Cloud_Delete_Driver::instance()->process_pending_delete_jobs( 60 );
		$this->assertFileExists( $base . '/' . $prefix . 'chunks/partial.bin' );
		$job = $store->get_jobs_for_snapshot( 980098, 'delete' )[0];
		$this->assertSame( 'pending', $job->status );
		$checkpoint                    = $job->checkpoint;
		$checkpoint['manual_override'] = true;
		$store->save_checkpoint( $job->id, $checkpoint );
		global $wpdb;
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ), array( 'next_retry_at' => 0 ), array( 'id' => $job->id ) );
		MainWP_Backup_Cloud_Delete_Driver::instance()->process_pending_delete_jobs( 60 );
		$this->assertFileDoesNotExist( $base . '/' . $prefix . 'chunks/partial.bin' );
	}
	/** A protected first job does not block a later expired copy or consume failures. */
	public function test_protected_delete_defers_without_starving_eligible_job() {
		list( $destination, $base ) = $this->make_local_destination();
		MainWP_Backup_Storage_Destinations::instance()->update_destination(
			$destination,
			array(
				'settings' => array(
					'base_path'                     => $base,
					'minimum_remote_retention_days' => 30,
				),
			)
		);
		$store = MainWP_Backup_Upload_Job_Store::instance();
		$now   = time();
		foreach ( array(
			980096 => $now,
			980097 => $now - 30 * DAY_IN_SECONDS,
		) as $id => $uploaded_at ) {
			$prefix = '5/' . $id . '/';
			$this->write_remote_objects( $base, $prefix, array( 'chunks/data.bin' ) );
			$store->enqueue_delete_job(
				$id,
				$destination,
				array(
					'prefix'          => $prefix,
					'storage_age'     => array(
						'uploaded_at' => $uploaded_at,
						'unknown_age' => false,
					),
					'manual_override' => false,
				)
			);
		}
		MainWP_Backup_Cloud_Delete_Driver::instance()->process_pending_delete_jobs( 60, $now );
		$this->assertFileExists( $base . '/5/980096/chunks/data.bin' );
		$this->assertFileDoesNotExist( $base . '/5/980097/chunks/data.bin' );
		$held = $store->get_jobs_for_snapshot( 980096, 'delete' )[0];
		$this->assertSame( 'pending', $held->status );
		$this->assertSame( 0, (int) $held->attempt_count );
		$this->assertSame( 0, (int) $held->reclaim_count );
		$this->assertGreaterThan( $now, (int) $held->next_retry_at );
	}
	/** Legacy jobs persist a conservative floor before waiting or deleting. */
	public function test_legacy_delete_floor_expires_from_queue_time_or_first_observation() {
		global $wpdb;
		foreach ( array( true, false ) as $has_queue_time ) {
			list( $destination, $base ) = $this->make_local_destination();
			MainWP_Backup_Storage_Destinations::instance()->update_destination(
				$destination,
				array(
					'settings' => array(
						'base_path'                     => $base,
						'minimum_remote_retention_days' => 2,
					),
				)
			);
			$id     = $has_queue_time ? 980110 : 980111;
			$prefix = '5/' . $id . '/';
			$this->write_remote_objects( $base, $prefix, array( 'archive.tar', 'partial.bin' ) );
			$this->enqueue_delete( $id, $destination, $prefix, array( $prefix . 'archive.tar' ) );
			$store  = MainWP_Backup_Upload_Job_Store::instance();
			$job    = $store->get_jobs_for_snapshot( $id, 'delete' )[0];
			$now    = time();
			$queued = $has_queue_time ? $now - DAY_IN_SECONDS : 0;
			$floor  = $has_queue_time ? $queued : $now;
			$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ), array( 'created' => $queued ), array( 'id' => $job->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- fixture queue timestamp.
			$driver = MainWP_Backup_Cloud_Delete_Driver::instance();
			$driver->process_pending_delete_jobs( 60, $now );
			$job = $store->get_job( $job->id );
			$this->assertSame( $floor, $job->checkpoint['storage_age_floor'] ?? null );
			$this->assertArrayNotHasKey( 'storage_age', $job->checkpoint );
			$this->assertSame( 0, (int) $job->attempt_count );
			$this->assertSame( '', $job->locked_by );
			$driver->process_pending_delete_jobs( 60, $floor + 2 * DAY_IN_SECONDS - 1 );
			$this->assertFileExists( $base . '/' . $prefix . 'archive.tar' );
			$this->assertFileExists( $base . '/' . $prefix . 'partial.bin' );
			$this->assertSame( $floor, $store->get_job( $job->id )->checkpoint['storage_age_floor'] );
			$driver->process_pending_delete_jobs( 60, $floor + 2 * DAY_IN_SECONDS );
			$this->assertFileDoesNotExist( $base . '/' . $prefix . 'archive.tar' );
			$this->assertFileDoesNotExist( $base . '/' . $prefix . 'partial.bin' );
			$this->assertSame( 'complete', $store->get_job( $job->id )->status );
		}
	}

	/** An expired floor cannot authorize deletion until its checkpoint write succeeds. */
	public function test_legacy_delete_floor_write_failure_stops_deletion() {
		global $wpdb;
		list( $destination, $base ) = $this->make_local_destination();
		MainWP_Backup_Storage_Destinations::instance()->update_destination(
			$destination,
			array(
				'settings' => array(
					'base_path'                     => $base,
					'minimum_remote_retention_days' => 1,
				),
			)
		);
		$prefix = '5/980112/';
		$this->write_remote_objects( $base, $prefix, array( 'archive.tar' ) );
		$this->enqueue_delete( 980112, $destination, $prefix, array( $prefix . 'archive.tar' ) );
		$store = MainWP_Backup_Upload_Job_Store::instance();
		$job   = $store->get_jobs_for_snapshot( 980112, 'delete' )[0];
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' );
		$wpdb->update( $table, array( 'created' => time() - 2 * DAY_IN_SECONDS ), array( 'id' => $job->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- fixture queue timestamp.
		$writes     = 0;
		$fail       = static function ( $sql ) use ( $table, &$writes ) {
			if ( str_starts_with( $sql, 'UPDATE ' . $table ) && str_contains( $sql, 'storage_age_floor' ) ) {
				++$writes;
				return 'UPDATE ' . $table . ' SET mainwp_missing_floor_column = 1';
			}
			return $sql;
		};
		$suppressed = $wpdb->suppress_errors();
		add_filter( 'query', $fail );
		try {
			MainWP_Backup_Cloud_Delete_Driver::instance()->process_pending_delete_jobs( 60 );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $suppressed );
		}
		$this->assertSame( 1, $writes );
		$this->assertFileExists( $base . '/' . $prefix . 'archive.tar' );
		$job = $store->get_job( $job->id );
		$this->assertArrayNotHasKey( 'storage_age_floor', $job->checkpoint );
		$this->assertSame( 'pending', $job->status );
		$this->assertSame( 0, (int) $job->attempt_count );
		$this->assertSame( '', $job->locked_by );
		MainWP_Backup_Cloud_Delete_Driver::instance()->process_pending_delete_jobs( 60 );
		$this->assertFileDoesNotExist( $base . '/' . $prefix . 'archive.tar' );
		$this->assertSame( 'complete', $store->get_job( $job->id )->status );
	}
}
