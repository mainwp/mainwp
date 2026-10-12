<?php
/**
 * MainWP Backup Upload Driver Tests
 *
 * Verifies the resumable upload driver end to end against a real Local
 * destination: jobs enqueue when snapshots record, a full run lands
 * chunk objects, whole files, and the manifest at the destination,
 * checkpoints survive across cron ticks, re-entry verifies before it
 * skips, auth rejections park the destination, network errors schedule
 * backoff, and exhausted attempts go permanent.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

require_once __DIR__ . '/trait-backup-private-root-fixture.php';

use MainWP\Dashboard\MainWP_Backup_Archive_Cipher;
use MainWP\Dashboard\MainWP_Backup_Chunker;
use MainWP\Dashboard\MainWP_Backup_Encryption;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_Backup_Storage_Destinations;
use MainWP\Dashboard\MainWP_Backup_Upload_Driver;
use MainWP\Dashboard\MainWP_Backup_Upload_Job_Store;
use MainWP\Dashboard\MainWP_Keys_Manager;
use MainWP\Dashboard\MainWP_DB;

/**
 * Class MainWP_Backup_Upload_Driver_Test
 */
class MainWP_Backup_Upload_Driver_Test extends MainWP_Abilities_Test_Case {

	use Backup_Private_Root_Fixture;

	/**
	 * Empty and malformed artifact lists must never verify an offsite copy.
	 */
	public function test_empty_or_scalar_archives_fail_permanently() {
		global $wpdb;
		list( $dest_id ) = $this->make_local_destination();
		$driver          = MainWP_Backup_Upload_Driver::instance();
		$build           = new \ReflectionMethod( $driver, 'new_checkpoint' );
		foreach ( array( array(), 'x' ) as $archives ) {
			$this->assertSame( array(), $build->invoke( $driver, (object) array( 'archives' => $archives ) )['artifacts'] );
			$snapshot_id = $this->record_bare_snapshot();
			$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'archives' => wp_json_encode( $archives ) ), array( 'id' => $snapshot_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- malformed persisted fixture.
			$store = MainWP_Backup_Upload_Job_Store::instance();
			$store->enqueue_for_snapshot( $snapshot_id, array( $dest_id ) );
			$driver->process_pending_jobs( 240 );
			$jobs = $store->get_jobs_for_snapshot( $snapshot_id );
			$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_FAILED_PERMANENT, $jobs[0]->status );
			$this->assertStringContainsString( 'no eligible artifacts', $jobs[0]->last_error );
			$this->assertNotSame( 'verified', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id )->offsite_state );
		}
	}

	/**
	 * Temp dirs created during a test, removed in tear_down.
	 *
	 * @var string[]
	 */
	private $temp_dirs = array();

	/**
	 * Small chunk and whole-file thresholds so tests stay tiny.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		$this->set_up_private_root();
		remove_all_actions( 'mainwp_backup_snapshot_recorded' );
		remove_all_actions( 'mainwp_backup_upload_job_settled' );
		remove_all_actions( 'mainwp_backup_snapshot_offsite_verified' );
		add_filter(
			'mainwp_backup_upload_chunk_size',
			function () {
				return 1024;
			}
		);
		add_filter(
			'mainwp_backup_upload_whole_file_threshold',
			function () {
				return 2000;
			}
		);
	}

	/**
	 * Remove temp fixtures and any leftover staging dirs.
	 *
	 * @return void
	 */
	public function tear_down() {
		foreach ( $this->temp_dirs as $dir ) {
			$this->remove_dir( $dir );
		}
		$this->temp_dirs = array();

		$upload = wp_upload_dir();
		$this->remove_dir( $this->private_root . '/upload-staging' );

		// Encryption state lives in a filesystem KEK file and options that
		// outlive the per-test DB rollback; clear it so an armed test does not
		// silently arm a later plaintext one.
		$enc = MainWP_Backup_Encryption::instance();
		MainWP_Keys_Manager::instance()->delete_key_file( $enc->kek_filename() );
		delete_option( MainWP_Backup_Encryption::ENABLED_OPTION );
		delete_option( MainWP_Backup_Encryption::RECOVERY_OPTION );

		$this->tear_down_private_root();
		parent::tear_down();
	}

	/**
	 * Arm encryption: turn the toggle on and set a recovery passphrase, which
	 * creates the dashboard KEK. Returns the encryption singleton.
	 *
	 * @return MainWP_Backup_Encryption
	 */
	private function arm_encryption() {
		$enc = MainWP_Backup_Encryption::instance();
		update_option( MainWP_Backup_Encryption::ENABLED_OPTION, '1' );
		$enc->set_recovery_passphrase( 'upload driver encryption passphrase' );
		return $enc;
	}

	/**
	 * Recursively remove a directory.
	 *
	 * @param string $dir Directory path.
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
		foreach ( scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $entry;
			if ( is_link( $path ) ) {
				unlink( $path );
			} elseif ( is_dir( $path ) ) {
				$this->remove_dir( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}

	/**
	 * Create a temp working dir.
	 *
	 * @return string
	 */
	private function make_temp_dir() {
		$dir = get_temp_dir() . 'mainwp-upload-driver-' . uniqid();
		mkdir( $dir, 0755, true );
		$this->temp_dirs[] = $dir;
		return $dir;
	}

	/**
	 * Register a Local destination over a fresh temp dir.
	 *
	 * @return array{0: int, 1: string} Destination id and its base path.
	 */
	private function make_local_destination() {
		$base = $this->make_temp_dir();
		$id   = MainWP_Backup_Storage_Destinations::instance()->add_destination(
			array(
				'provider' => 'local',
				'name'     => 'Driver test ' . uniqid(),
				'settings' => array( 'base_path' => $base ),
			)
		);
		return array( $id, $base );
	}

	/**
	 * Insert a backup task row carrying destination ids.
	 *
	 * @param array $destination_ids Destination ids for the destinations column.
	 *
	 * @return int Task id.
	 */
	private function insert_task( array $destination_ids ) {
		global $wpdb;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			$table,
			array(
				'userid'            => 1,
				'name'              => 'Upload driver task',
				'schedule'          => 'daily',
				'type'              => 'incremental',
				'exclude'           => '',
				'sites'             => '',
				'groups'            => '',
				'last'              => 0,
				'last_run'          => 0,
				'last_run_manually' => 0,
				'completed_sites'   => '',
				'completed'         => 0,
				'backup_errors'     => '',
				'subfolder'         => '',
				'filename'          => '',
				'paused'            => 0,
				'archiveFormat'     => 'global',
				'destinations'      => wp_json_encode( array_map( 'intval', $destination_ids ) ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Write artifact fixtures and record a snapshot over them.
	 *
	 * @param array $files   Map of filename => array{size: int, role: string}.
	 * @param int   $task_id Task id for the snapshot row.
	 * @param int   $site_id Site id for the snapshot row.
	 *
	 * @return int Snapshot id.
	 */
	private function make_snapshot( array $files, $task_id = 0, $site_id = 4 ) {
		$src      = $this->make_temp_dir();
		$archives = array();

		foreach ( $files as $name => $spec ) {
			$path = $src . DIRECTORY_SEPARATOR . $name;

			// Position-unique content so no two chunks dedupe to one object.
			$content = '';
			$block   = 0;
			$length  = 0;
			while ( $length < $spec['size'] ) {
				$content .= md5( $name . '-' . $block, true );
				$length  += 16; // Raw md5 appends 16 bytes.
				++$block;
			}
			$content = substr( $content, 0, $spec['size'] );
			file_put_contents( $path, $content );
			$archives[] = array(
				'file' => $name,
				'size' => $spec['size'],
				'role' => $spec['role'],
				'path' => $path,
			);
		}

		return MainWP_Backup_Snapshots::instance()->record_snapshot(
			array(
				'task_id'       => $task_id,
				'site_id'       => $site_id,
				'type'          => 'full',
				'parent_id'     => null,
				'archives'      => $archives,
				'manifest_path' => '',
			)
		);
	}

	/**
	 * Record a snapshot row with no artifacts, for state-machine tests that
	 * do not drive a real upload.
	 *
	 * @param int $site_id Site id for the snapshot row.
	 *
	 * @return int Snapshot id.
	 */
	private function record_bare_snapshot( $site_id = 4 ) {
		return MainWP_Backup_Snapshots::instance()->record_snapshot(
			array(
				'task_id'       => 0,
				'site_id'       => $site_id,
				'type'          => 'full',
				'parent_id'     => null,
				'archives'      => array(),
				'manifest_path' => '',
			)
		);
	}

	/**
	 * Insert an upload-job row directly with a chosen provider and status,
	 * for resolver tests that exercise the rollup buckets.
	 *
	 * @param int    $snapshot_id    Snapshot id.
	 * @param int    $destination_id Destination id.
	 * @param string $provider       Denormalized provider slug.
	 * @param string $status         Job status.
	 *
	 * @return void
	 */
	private function seed_upload_job( $snapshot_id, $destination_id, $provider, $status ) {
		global $wpdb;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			$table,
			array(
				'snapshot_id'    => $snapshot_id,
				'destination_id' => $destination_id,
				'kind'           => 'upload',
				'provider'       => $provider,
				'status'         => $status,
				'checkpoint'     => '',
				'created'        => time(),
				'updated'        => time(),
			)
		);
	}

	/**
	 * The standard artifact set: one chunked, one whole, one manifest.
	 *
	 * @return array
	 */
	private function standard_files() {
		return array(
			'big.bin'    => array(
				'size' => 3000,
				'role' => 'part',
			),
			'small.txt'  => array(
				'size' => 100,
				'role' => 'db',
			),
			'files.list' => array(
				'size' => 50,
				'role' => 'manifest',
			),
		);
	}

	/**
	 * Recording a snapshot enqueues one job per task destination through
	 * the driver's listener.
	 *
	 * @return void
	 */
	public function test_snapshot_recording_enqueues_jobs_for_task_destinations() {
		list( $dest_a ) = $this->make_local_destination();
		list( $dest_b ) = $this->make_local_destination();
		$task_id        = $this->insert_task( array( $dest_a, $dest_b ) );

		MainWP_Backup_Upload_Driver::instance()->init();

		$snapshot_id = $this->make_snapshot( $this->standard_files(), $task_id );

		$jobs = MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( $snapshot_id );
		$this->assertCount( 2, $jobs );
		$this->assertSame( array( $dest_a, $dest_b ), array_map( 'intval', array_column( $jobs, 'destination_id' ) ) );
	}

	/**
	 * A scheduled snapshot reaches both local destinations in one tick.
	 */
	public function test_two_local_destinations_complete_in_one_tick() {
		list( $dest_a, $base_a ) = $this->make_local_destination();
		list( $dest_b, $base_b ) = $this->make_local_destination();
		$task_id                 = $this->insert_task( array( $dest_a, $dest_b ) );
		MainWP_Backup_Upload_Driver::instance()->init();
		$snapshot_id = $this->make_snapshot( $this->standard_files(), $task_id );

		MainWP_Backup_Upload_Driver::instance()->process_pending_jobs( 240 );

		$jobs = MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( $snapshot_id );
		$this->assertSame( array( 'complete', 'complete' ), array_column( $jobs, 'status' ) );
		$this->assertFileExists( $base_a . '/4/' . $snapshot_id . '/manifest.json' );
		$this->assertFileExists( $base_b . '/4/' . $snapshot_id . '/manifest.json' );
		$this->assertSame( '', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id )->offsite_state );
	}

	/**
	 * An expired budget permits one checkpoint attempt, leaving later jobs pending.
	 */
	public function test_zero_budget_attempts_only_one_job() {
		list( $dest_a ) = $this->make_local_destination();
		list( $dest_b ) = $this->make_local_destination();
		$snapshot_id    = $this->make_snapshot( $this->standard_files() );
		$store          = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $snapshot_id, array( $dest_a, $dest_b ) );

		MainWP_Backup_Upload_Driver::instance()->process_pending_jobs( 0, time() );

		$jobs = $store->get_jobs_for_snapshot( $snapshot_id );
		$this->assertSame( array( 'chunking', 'pending' ), array_column( $jobs, 'status' ) );
		$this->assertNotEmpty( $jobs[0]->checkpoint['artifacts'] );
		$this->assertEmpty( $jobs[1]->checkpoint );
		$this->assertSame( array( '', '' ), array_column( $jobs, 'locked_by' ) );
	}

	/**
	 * A large queue cannot monopolize the continuation lane.
	 */
	public function test_tick_stops_after_maximum_jobs() {
		list( $dest_id ) = $this->make_local_destination();
		$store           = MainWP_Backup_Upload_Job_Store::instance();
		$snapshots       = array();
		for ( $i = 0; $i <= MainWP_Backup_Upload_Driver::MAX_JOBS_PER_TICK; ++$i ) {
			$snapshot_id = $this->make_snapshot( $this->standard_files() );
			$store->enqueue_for_snapshot( $snapshot_id, array( $dest_id ) );
			$snapshots[] = $snapshot_id;
		}

		MainWP_Backup_Upload_Driver::instance()->process_pending_jobs( 240 );

		foreach ( $snapshots as $index => $snapshot_id ) {
			$expected = $index < MainWP_Backup_Upload_Driver::MAX_JOBS_PER_TICK ? 'complete' : 'pending';
			$this->assertSame( $expected, $store->get_jobs_for_snapshot( $snapshot_id )[0]->status, 'Unexpected status for queued job ' . $index );
		}
	}

	/**
	 * A task without destinations enqueues nothing.
	 *
	 * @return void
	 */
	public function test_snapshot_without_destinations_enqueues_nothing() {
		$task_id = $this->insert_task( array() );

		MainWP_Backup_Upload_Driver::instance()->init();

		$snapshot_id = $this->make_snapshot( $this->standard_files(), $task_id );

		$this->assertSame( array(), MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( $snapshot_id ) );
	}

	/**
	 * A full run lands chunks, whole files, and the manifest at the
	 * destination, cleans its staging, completes the job, and announces
	 * itself.
	 *
	 * @return void
	 */
	public function test_full_run_uploads_snapshot_to_destination() {
		list( $dest_id, $base ) = $this->make_local_destination();
		$snapshot_id            = $this->make_snapshot( $this->standard_files(), 0, 4 );
		MainWP_Backup_Upload_Job_Store::instance()->enqueue_for_snapshot( $snapshot_id, array( $dest_id ) );

		$events = array();
		add_action(
			'mainwp_backup_uploaded',
			function ( $event ) use ( &$events ) {
				$events[] = $event;
			}
		);

		MainWP_Backup_Upload_Driver::instance()->process_pending_jobs( 240 );

		$jobs = MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( $snapshot_id );
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_COMPLETE, $jobs[0]->status );

		$prefix  = $base . '/4/' . $snapshot_id;
		$big     = file_get_contents( $this->temp_dirs[1] . '/big.bin' );
		$algo    = \MainWP\Dashboard\MainWP_Backup_Chunker::hash_algo();
		$offsets = array( 0, 1024, 2048 );
		foreach ( $offsets as $i => $offset ) {
			$slice = substr( $big, $offset, 1024 );
			$hash  = hash( $algo, $slice );
			$this->assertFileExists( $prefix . '/chunks/' . $hash . '.bin', "Chunk {$i} must land at the destination." );
			$this->assertSame( $slice, file_get_contents( $prefix . '/chunks/' . $hash . '.bin' ) );
		}

		$this->assertFileExists( $prefix . '/files/small.txt' );
		$this->assertFileExists( $prefix . '/files/files.list' );

		$this->assertFileExists( $prefix . '/manifest.json' );
		$manifest = json_decode( file_get_contents( $prefix . '/manifest.json' ), true );
		$this->assertSame( $snapshot_id, $manifest['snapshot_id'] );
		$this->assertCount( 3, $manifest['artifacts'] );
		$by_file = array_column( $manifest['artifacts'], null, 'file' );
		$this->assertSame( 'chunked', $by_file['big.bin']['mode'] );
		$this->assertCount( 3, $by_file['big.bin']['chunks'] );
		$this->assertSame( 'whole', $by_file['small.txt']['mode'] );

		// Every artifact records its whole-file hash, in the manifest and
		// on the job checkpoint, so restores can verify end to end.
		$big_sha   = hash_file( 'sha256', $this->temp_dirs[1] . '/big.bin' );
		$small_sha = hash_file( 'sha256', $this->temp_dirs[1] . '/small.txt' );
		$this->assertSame( $big_sha, $by_file['big.bin']['sha256'] );
		$this->assertSame( $small_sha, $by_file['small.txt']['sha256'] );

		$by_checkpoint = array_column( $jobs[0]->checkpoint['artifacts'], null, 'file' );
		$this->assertSame( $big_sha, $by_checkpoint['big.bin']['sha256'] );
		$this->assertSame( $small_sha, $by_checkpoint['small.txt']['sha256'] );

		$upload = wp_upload_dir();
		$this->assertDirectoryDoesNotExist( $this->private_root . '/upload-staging/' . $snapshot_id . '-' . $dest_id );

		$this->assertCount( 1, $events );
		$this->assertSame( 4, $events[0]['site_id'] );
		$this->assertSame( $snapshot_id, $events[0]['snapshot_id'] );
		$this->assertSame( $dest_id, $events[0]['destination_id'] );
		$this->assertSame( 'local', $events[0]['provider'] );
	}

	/**
	 * Hosts can recreate web-served staging trees without their deny files.
	 *
	 * @return void
	 */
	public function test_chunking_protects_staging_after_creation_and_recreation() {
		list( $dest_id ) = $this->make_local_destination();
		$snapshot_id     = $this->make_snapshot( $this->standard_files() );
		$store           = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $snapshot_id, array( $dest_id ) );
		$job      = $store->get_jobs_for_snapshot( $snapshot_id )[0];
		$driver   = MainWP_Backup_Upload_Driver::instance();
		$build    = new \ReflectionMethod( $driver, 'new_checkpoint' );
		$chunking = new \ReflectionMethod( $driver, 'phase_chunking' );
		$snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id );
		$base     = $this->make_temp_dir();
		$root     = $this->private_root . '/upload-staging';
		$upload   = static function ( $dirs ) use ( $base ) {
			$dirs['basedir'] = $base;
			return $dirs;
		};
		$filtered = static function ( $dirs ) use ( $base ) {
			$dirs['basedir'] = $base . '/filtered';
			return $dirs;
		};
		add_filter( 'upload_dir', $upload );
		add_filter( 'mainwp_get_wp_upload_dir', $filtered );
		try {
			for ( $pass = 0; $pass < 3; ++$pass ) {
				if ( 1 === $pass ) {
					$this->remove_dir( $root );
					$this->assertDirectoryDoesNotExist( $root );
				} elseif ( 2 === $pass ) {
					unlink( $root . '/.htaccess' );
					unlink( $root . '/index.php' );
				}
				$checkpoint = $build->invoke( $driver, $snapshot );
				$args       = array( $job, &$checkpoint, microtime( true ) + 60 );
				$this->assertTrue( $chunking->invokeArgs( $driver, $args ) );
				$this->assertNotEmpty( glob( $root . '/' . $snapshot_id . '-' . $dest_id . '/*.bin' ), 'Chunking must write staging bytes.' );
				if ( 2 === $pass ) {
					$this->assertFileDoesNotExist( $root . '/.htaccess' );
					$this->assertFileDoesNotExist( $root . '/index.php' );
					continue;
				}
				$this->assertFileExists( $root . '/.htaccess' );
				$this->assertSame( 'deny from all', file_get_contents( $root . '/.htaccess' ) );
				$this->assertFileExists( $root . '/index.php' );
				$this->assertSame( '', file_get_contents( $root . '/index.php' ) );
				$this->assertDirectoryDoesNotExist( $base . '/filtered/mainwp/upload-staging' );
			}
			$cleanup = new \ReflectionMethod( $driver, 'cleanup_staging' );
			$cleanup->invoke( $driver, $job );
			$this->assertDirectoryDoesNotExist( $root . '/' . $snapshot_id . '-' . $dest_id );
			$this->assertFileDoesNotExist( $root . '/.htaccess' );
			$this->assertFileDoesNotExist( $root . '/index.php' );
		} finally {
			remove_filter( 'upload_dir', $upload );
			remove_filter( 'mainwp_get_wp_upload_dir', $filtered );
		}
	}

	/**
	 * A zero-budget tick still checkpoints, and the next tick finishes
	 * from the checkpoint.
	 *
	 * @return void
	 */
	public function test_checkpoint_survives_across_ticks() {
		list( $dest_id, $base ) = $this->make_local_destination();
		$snapshot_id            = $this->make_snapshot( $this->standard_files() );
		$store                  = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $snapshot_id, array( $dest_id ) );

		$now = time();
		MainWP_Backup_Upload_Driver::instance()->process_pending_jobs( 0, $now );

		$job = $store->get_jobs_for_snapshot( $snapshot_id )[0];
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_CHUNKING, $job->status, 'A zero-budget tick must still advance into chunking.' );
		$this->assertNotEmpty( $job->checkpoint['artifacts'], 'The checkpoint must persist.' );

		MainWP_Backup_Upload_Driver::instance()->process_pending_jobs( 240, $now + 301 );

		$job = $store->get_jobs_for_snapshot( $snapshot_id )[0];
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_COMPLETE, $job->status );
		$this->assertFileExists( $base . '/4/' . $snapshot_id . '/manifest.json' );
	}

	/**
	 * Re-entry verifies remote objects before skipping them: a torn remote
	 * chunk re-uploads, an intact one is left alone.
	 *
	 * @return void
	 */
	public function test_reentry_reuploads_torn_remote_chunk_only() {
		list( $dest_id, $base ) = $this->make_local_destination();
		$snapshot_id            = $this->make_snapshot( $this->standard_files() );
		$store                  = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $snapshot_id, array( $dest_id ) );

		MainWP_Backup_Upload_Driver::instance()->process_pending_jobs( 240 );

		$job = $store->get_jobs_for_snapshot( $snapshot_id )[0];
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_COMPLETE, $job->status );

		$prefix = $base . '/4/' . $snapshot_id;
		$chunks = glob( $prefix . '/chunks/*.bin' );
		$this->assertCount( 3, $chunks );

		// Tear one remote chunk, backdate another to detect rewrites.
		$torn   = $chunks[0];
		$intact = $chunks[1];
		file_put_contents( $torn, 'short' );
		$past = time() - 3600;
		touch( $intact, $past );

		// Rewind the job to the upload phase distrusting all prior work.
		$checkpoint = $job->checkpoint;
		foreach ( $checkpoint['artifacts'] as &$artifact ) {
			$artifact['chunks_cursor'] = 0;
			$artifact['done']          = false;
			if ( is_array( $artifact['chunks'] ) ) {
				foreach ( $artifact['chunks'] as &$chunk ) {
					$chunk['remote_present'] = false;
				}
				unset( $chunk );
			}
		}
		unset( $artifact );
		$checkpoint['manifest_submitted'] = false;
		$store->save_checkpoint( (int) $job->id, $checkpoint );
		$store->set_status( (int) $job->id, MainWP_Backup_Upload_Job_Store::STATUS_UPLOADING );

		MainWP_Backup_Upload_Driver::instance()->process_pending_jobs( 240 );

		clearstatcache();
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_COMPLETE, $store->get_jobs_for_snapshot( $snapshot_id )[0]->status );
		$this->assertSame( 1024, strlen( file_get_contents( $torn ) ), 'The torn remote chunk must be re-uploaded.' );
		$this->assertSame( $past, filemtime( $intact ), 'The intact remote chunk must be skipped, not rewritten.' );
	}

	/**
	 * An auth rejection parks the job as credentials_invalid instead of
	 * retrying forever.
	 *
	 * @return void
	 */
	public function test_auth_rejection_parks_credentials_invalid() {
		$dest_id = MainWP_Backup_Storage_Destinations::instance()->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Locked out',
				'settings' => array(
					'bucket'     => 'b',
					'region'     => 'us-east-1',
					'access_key' => 'k',
					'secret_key' => 's',
				),
			)
		);

		$snapshot_id = $this->make_snapshot(
			array(
				'small.txt' => array(
					'size' => 100,
					'role' => 'db',
				),
			)
		);
		$store       = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $snapshot_id, array( $dest_id ) );

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

		MainWP_Backup_Upload_Driver::instance()->process_pending_jobs( 240 );

		$job = $store->get_jobs_for_snapshot( $snapshot_id )[0];
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_CREDENTIALS_INVALID, $job->status );
		$this->assertStringContainsString( '403', $job->last_error );
	}

	/**
	 * A network failure schedules a retry with backoff.
	 *
	 * @return void
	 */
	public function test_network_failure_schedules_retry() {
		$dest_id = MainWP_Backup_Storage_Destinations::instance()->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Unreachable',
				'settings' => array(
					'bucket'     => 'b',
					'region'     => 'us-east-1',
					'access_key' => 'k',
					'secret_key' => 's',
				),
			)
		);

		$snapshot_id = $this->make_snapshot(
			array(
				'small.txt' => array(
					'size' => 100,
					'role' => 'db',
				),
			)
		);
		$store       = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $snapshot_id, array( $dest_id ) );

		add_filter(
			'pre_http_request',
			function () {
				return new \WP_Error( 'http_request_failed', 'timed out' );
			}
		);

		$now = time();
		MainWP_Backup_Upload_Driver::instance()->process_pending_jobs( 240, $now );

		$job = $store->get_jobs_for_snapshot( $snapshot_id )[0];
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_FAILED_RETRYABLE, $job->status );
		$this->assertSame( 1, (int) $job->attempt_count );
		$this->assertSame( $now + 300, (int) $job->next_retry_at, 'First retry must back off five minutes.' );
		$this->assertStringContainsString( 'timed out', $job->last_error );
	}

	/**
	 * Tooltips escape at output, so a Local refusal must retain its quotes in storage.
	 *
	 * @return void
	 */
	public function test_refused_local_destination_stores_plain_text_error() {
		$dest_id = MainWP_Backup_Storage_Destinations::instance()->add_destination(
			array(
				'provider' => 'local',
				'name'     => 'Refused Local destination',
				'settings' => array( 'base_path' => WP_CONTENT_DIR . '/mainwp-refused-upload-' . uniqid() ),
			)
		);
		$this->assertGreaterThan( 0, $dest_id );

		$snapshot_id = $this->make_snapshot( $this->standard_files() );
		$store       = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $snapshot_id, array( $dest_id ) );
		MainWP_Backup_Upload_Driver::instance()->process_pending_jobs( 240 );

		$jobs = $store->get_jobs_for_snapshot( $snapshot_id );
		$this->assertCount( 1, $jobs );
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_FAILED_RETRYABLE, $jobs[0]->status );
		$this->assertStringContainsString( 'Backup directory "', $jobs[0]->last_error );
		$this->assertStringNotContainsString( '&quot;', $jobs[0]->last_error );
	}

	/**
	 * Existing artifacts must stay retryable when chunk staging fails.
	 *
	 * @return void
	 */
	public function test_chunk_staging_failure_schedules_retry() {
		list( $dest_id ) = $this->make_local_destination();
		$snapshot_id     = $this->make_snapshot(
			array(
				'large.zip' => array(
					'size' => 3000,
					'role' => 'full',
				),
			)
		);
		$snapshot        = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id );
		$artifact        = $snapshot->archives[0];
		$hash            = hash( MainWP_Backup_Chunker::hash_algo(), substr( file_get_contents( $artifact['path'] ), 0, 1024 ) );
		$staging         = $this->private_root . '/upload-staging/' . $snapshot_id . '-' . $dest_id;
		$blocked         = $staging . '/' . $hash . '.bin';
		mkdir( $blocked, 0755, true );
		$store = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $snapshot_id, array( $dest_id ) );

		$warnings = array();
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- The expected filesystem warning must not interrupt the failure-state assertion.
			static function ( $severity, $message ) use ( $blocked, &$warnings ) {
				if ( E_WARNING === $severity && false !== strpos( $message, 'rename(' ) && false !== strpos( $message, $blocked ) ) {
					$warnings[] = $message;
					return true;
				}
				return false;
			}
		);
		$now = time();
		try {
			MainWP_Backup_Upload_Driver::instance()->process_pending_jobs( 240, $now );
		} finally {
			restore_error_handler();
		}

		$job = $store->get_jobs_for_snapshot( $snapshot_id )[0];
		$this->assertCount( 1, $warnings, 'The fixture must fail while promoting a staged chunk.' );
		$this->assertFileExists( $artifact['path'] );
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_FAILED_RETRYABLE, $job->status );
		$this->assertSame( 1, (int) $job->attempt_count );
		$this->assertSame( $now + 300, (int) $job->next_retry_at );
		$this->assertStringContainsString( 'could not be read or prepared for upload: large.zip', $job->last_error );
		$this->assertStringNotContainsString( $artifact['path'], $job->last_error );
		$this->assertStringNotContainsString( 'is missing from this Dashboard', $job->last_error );
	}

	/**
	 * Missing source files still fail permanently without scheduling retries.
	 *
	 * @return void
	 */
	public function test_missing_chunked_artifact_fails_permanent_with_410() {
		list( $dest_id ) = $this->make_local_destination();
		$snapshot_id     = $this->make_snapshot(
			array(
				'large.zip' => array(
					'size' => 3000,
					'role' => 'full',
				),
			)
		);
		$snapshot        = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id );
		unlink( $snapshot->archives[0]['path'] );
		$store = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $snapshot_id, array( $dest_id ) );
		$job        = $store->get_jobs_for_snapshot( $snapshot_id )[0];
		$driver     = MainWP_Backup_Upload_Driver::instance();
		$build      = new \ReflectionMethod( $driver, 'new_checkpoint' );
		$chunking   = new \ReflectionMethod( $driver, 'phase_chunking' );
		$checkpoint = $build->invoke( $driver, $snapshot );
		$args       = array( $job, &$checkpoint, microtime( true ) + 60 );
		try {
			$chunking->invokeArgs( $driver, $args );
			$this->fail( 'A missing artifact must throw before upload.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 410, $e->getCode() );
		}

		$driver->process_pending_jobs( 240 );
		$job = $store->get_jobs_for_snapshot( $snapshot_id )[0];
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_FAILED_PERMANENT, $job->status );
		$this->assertSame( 0, (int) $job->next_retry_at );
		$this->assertStringContainsString( 'is missing from this Dashboard: large.zip', $job->last_error );
	}

	/**
	 * A whole-file artifact that exists but cannot be read stays retryable.
	 *
	 * @return void
	 */
	public function test_unreadable_whole_artifact_schedules_retry() {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Root ignores file permissions, so the artifact cannot be made unreadable.' );
		}

		list( $dest_id ) = $this->make_local_destination();
		$snapshot_id     = $this->make_snapshot(
			array(
				'small.txt' => array(
					'size' => 100,
					'role' => 'db',
				),
			)
		);
		$snapshot        = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id );
		$path            = $snapshot->archives[0]['path'];
		$store           = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $snapshot_id, array( $dest_id ) );

		$warnings = array();
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- The expected filesystem warning must not interrupt the failure-state assertion.
			static function ( $severity, $message ) use ( $path, &$warnings ) {
				// The whole-file hash runs first and trips over the same permission error.
				if ( E_WARNING === $severity && false !== strpos( $message, $path ) && ( 0 === strpos( $message, 'file_get_contents(' ) || 0 === strpos( $message, 'hash_file(' ) ) ) {
					$warnings[] = $message;
					return true;
				}
				return false;
			}
		);
		$now = time();
		chmod( $path, 0000 );
		try {
			MainWP_Backup_Upload_Driver::instance()->process_pending_jobs( 240, $now );
		} finally {
			chmod( $path, 0644 );
			restore_error_handler();
		}

		$job = $store->get_jobs_for_snapshot( $snapshot_id )[0];
		$this->assertNotEmpty(
			array_filter(
				$warnings,
				static function ( $message ) {
					return 0 === strpos( $message, 'file_get_contents(' );
				}
			),
			'The fixture must fail while reading the whole artifact.'
		);
		$this->assertFileExists( $path );
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_FAILED_RETRYABLE, $job->status );
		$this->assertSame( 1, (int) $job->attempt_count );
		$this->assertSame( $now + 300, (int) $job->next_retry_at );
		$this->assertStringContainsString( 'Could not read backup artifact for upload', $job->last_error );
	}

	/**
	 * A chunk whose staged copy is gone and whose source still covers it but
	 * cannot be read stays retryable.
	 *
	 * @return void
	 */
	public function test_unreadable_chunk_source_schedules_retry() {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Root ignores file permissions, so the artifact cannot be made unreadable.' );
		}

		list( $dest_id ) = $this->make_local_destination();
		$snapshot_id     = $this->make_snapshot(
			array(
				'large.zip' => array(
					'size' => 3000,
					'role' => 'full',
				),
			)
		);
		$snapshot        = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id );
		$path            = $snapshot->archives[0]['path'];
		$store           = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $snapshot_id, array( $dest_id ) );
		$job        = $store->get_jobs_for_snapshot( $snapshot_id )[0];
		$driver     = MainWP_Backup_Upload_Driver::instance();
		$build      = new \ReflectionMethod( $driver, 'new_checkpoint' );
		$chunking   = new \ReflectionMethod( $driver, 'phase_chunking' );
		$checkpoint = $build->invoke( $driver, $snapshot );
		$args       = array( $job, &$checkpoint, microtime( true ) + 60 );
		$this->assertTrue( $chunking->invokeArgs( $driver, $args ) );

		// Hash up front so the permission error lands on the chunk read alone.
		$checkpoint['artifacts'][0]['sha256'] = hash_file( 'sha256', $path );
		$store->save_checkpoint( (int) $job->id, $checkpoint );
		$store->set_status( (int) $job->id, MainWP_Backup_Upload_Job_Store::STATUS_UPLOADING );

		$staged = glob( $this->private_root . '/upload-staging/' . $snapshot_id . '-' . $dest_id . '/*.bin' );
		$this->assertNotEmpty( $staged, 'Chunking must stage chunk bytes.' );
		foreach ( $staged as $file ) {
			unlink( $file );
		}

		$warnings = array();
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- The expected filesystem warning must not interrupt the failure-state assertion.
			static function ( $severity, $message ) use ( $path, &$warnings ) {
				if ( E_WARNING === $severity && 0 === strpos( $message, 'fopen(' ) && false !== strpos( $message, $path ) ) {
					$warnings[] = $message;
					return true;
				}
				return false;
			}
		);
		$now = time();
		chmod( $path, 0000 );
		try {
			$driver->process_pending_jobs( 240, $now );
		} finally {
			chmod( $path, 0644 );
			restore_error_handler();
		}

		$job = $store->get_jobs_for_snapshot( $snapshot_id )[0];
		$this->assertCount( 1, $warnings, 'The fixture must fail while opening the source slice.' );
		$this->assertFileExists( $path );
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_FAILED_RETRYABLE, $job->status );
		$this->assertSame( 1, (int) $job->attempt_count );
		$this->assertSame( $now + 300, (int) $job->next_retry_at );
		$this->assertStringContainsString( 'Chunk bytes are unavailable locally', $job->last_error );
	}

	/**
	 * A complete staged copy must survive a read failure after the original is gone.
	 *
	 * @dataProvider staged_chunk_sources
	 * @param string $staged_state Staged copy condition.
	 * @param int    $expected_code Failure classification.
	 */
	public function test_missing_original_classifies_staged_chunk( string $staged_state, int $expected_code ) {
		if ( 'unreadable' === $staged_state && function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Root ignores file permissions, so the chunk cannot be made unreadable.' );
		}
		mkdir( $this->private_root );
		$staged   = $this->private_root . '/chunk.bin';
		$artifact = array(
			'path' => $this->private_root . '/missing.zip',
			'file' => 'missing.zip',
		);
		$chunk    = array(
			'offset' => 1024,
			'length' => 4,
		);
		if ( 'absent' !== $staged_state ) {
			file_put_contents( $staged, 'unreadable' === $staged_state ? 'data' : 'bad' );
		}
		$warnings = array();
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- The expected read warning must not replace the classified exception.
			static function ( $severity, $message ) use ( $staged, &$warnings ) {
				if ( E_WARNING === $severity && str_starts_with( $message, 'file_get_contents(' ) && str_contains( $message, $staged ) ) {
					$warnings[] = $message;
					return true;
				}
				return false;
			}
		);
		if ( 'unreadable' === $staged_state ) {
			chmod( $staged, 0000 );
		}
		$code = null;
		try {
			$driver = MainWP_Backup_Upload_Driver::instance();
			$read   = new \ReflectionMethod( $driver, 'read_chunk_bytes' );
			try {
				$read->invoke( $driver, $artifact, $chunk, $staged );
			} catch ( \RuntimeException $e ) {
				$code = $e->getCode();
			}
		} finally {
			if ( 'unreadable' === $staged_state ) {
				chmod( $staged, 0644 );
			}
			restore_error_handler();
		}
		$this->assertCount( 'unreadable' === $staged_state ? 1 : 0, $warnings );
		$this->assertSame( $expected_code, $code, 'A complete staged chunk must remain retryable when its read fails.' );
		if ( 'unreadable' === $staged_state ) {
			$this->assertSame( 'data', file_get_contents( $staged ) );
		}
	}

	/**
	 * Missing or short staged copies cannot replace the lost original.
	 *
	 * @return array
	 */
	public static function staged_chunk_sources() {
		return array(
			'unreadable complete copy' => array( 'unreadable', 0 ),
			'absent copy'              => array( 'absent', 410 ),
			'wrong size copy'          => array( 'wrong-size', 410 ),
		);
	}

	/**
	 * A chunk whose staged copy is gone and whose source was cut short can
	 * never be rebuilt, so the job fails permanently.
	 *
	 * @return void
	 */
	public function test_truncated_chunk_source_fails_permanent() {
		list( $dest_id ) = $this->make_local_destination();
		$snapshot_id     = $this->make_snapshot(
			array(
				'large.zip' => array(
					'size' => 3000,
					'role' => 'full',
				),
			)
		);
		$snapshot        = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id );
		$path            = $snapshot->archives[0]['path'];
		$store           = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $snapshot_id, array( $dest_id ) );
		$job        = $store->get_jobs_for_snapshot( $snapshot_id )[0];
		$driver     = MainWP_Backup_Upload_Driver::instance();
		$build      = new \ReflectionMethod( $driver, 'new_checkpoint' );
		$chunking   = new \ReflectionMethod( $driver, 'phase_chunking' );
		$checkpoint = $build->invoke( $driver, $snapshot );
		$args       = array( $job, &$checkpoint, microtime( true ) + 60 );
		$this->assertTrue( $chunking->invokeArgs( $driver, $args ) );

		$store->save_checkpoint( (int) $job->id, $checkpoint );
		$store->set_status( (int) $job->id, MainWP_Backup_Upload_Job_Store::STATUS_UPLOADING );

		$staged = glob( $this->private_root . '/upload-staging/' . $snapshot_id . '-' . $dest_id . '/*.bin' );
		$this->assertNotEmpty( $staged, 'Chunking must stage chunk bytes.' );
		foreach ( $staged as $file ) {
			unlink( $file );
		}

		// Shorter than the first chunk's offset plus length (0 + 1024).
		file_put_contents( $path, substr( file_get_contents( $path ), 0, 500 ) );
		clearstatcache();

		$driver->process_pending_jobs( 240, time() );

		$job = $store->get_jobs_for_snapshot( $snapshot_id )[0];
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_FAILED_PERMANENT, $job->status );
		$this->assertSame( 0, (int) $job->next_retry_at );
		$this->assertStringContainsString( 'Chunk bytes are unavailable locally', $job->last_error );
	}

	/**
	 * Exhausted attempts go permanent.
	 *
	 * @return void
	 */
	public function test_exhausted_attempts_fail_permanent() {
		$dest_id = MainWP_Backup_Storage_Destinations::instance()->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Hopeless',
				'settings' => array(
					'bucket'     => 'b',
					'region'     => 'us-east-1',
					'access_key' => 'k',
					'secret_key' => 's',
				),
			)
		);

		$snapshot_id = $this->make_snapshot(
			array(
				'small.txt' => array(
					'size' => 100,
					'role' => 'db',
				),
			)
		);
		$store       = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $snapshot_id, array( $dest_id ) );

		add_filter(
			'pre_http_request',
			function () {
				return new \WP_Error( 'http_request_failed', 'still down' );
			}
		);

		$now = time();
		for ( $i = 0; $i < 7; $i++ ) {
			MainWP_Backup_Upload_Driver::instance()->process_pending_jobs( 240, $now );
			$now += 20000;
		}

		$job = $store->get_jobs_for_snapshot( $snapshot_id )[0];
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_FAILED_PERMANENT, $job->status );
	}

	/**
	 * A deleted destination fails the job permanently.
	 *
	 * @return void
	 */
	public function test_deleted_destination_fails_permanent() {
		list( $dest_id ) = $this->make_local_destination();
		$snapshot_id     = $this->make_snapshot( $this->standard_files() );
		$store           = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $snapshot_id, array( $dest_id ) );

		MainWP_Backup_Storage_Destinations::instance()->delete_destination( $dest_id );

		MainWP_Backup_Upload_Driver::instance()->process_pending_jobs( 240 );

		$job = $store->get_jobs_for_snapshot( $snapshot_id )[0];
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_FAILED_PERMANENT, $job->status );
		$this->assertStringContainsString( 'destination', strtolower( $job->last_error ) );
	}

	/**
	 * A job whose snapshot record vanished announces the terminal failure
	 * so the activity log records it, with site unattributed and the
	 * destination taken from the job row.
	 *
	 * @return void
	 */
	public function test_missing_snapshot_fires_upload_failed() {
		list( $dest_id ) = $this->make_local_destination();
		$snapshot_id     = $this->make_snapshot( $this->standard_files() );
		$store           = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $snapshot_id, array( $dest_id ) );

		global $wpdb;
		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ),
			array( 'id' => $snapshot_id ),
			array( '%d' )
		);

		$events = array();
		add_action(
			'mainwp_backup_upload_failed',
			function ( $event ) use ( &$events ) {
				$events[] = $event;
			}
		);

		MainWP_Backup_Upload_Driver::instance()->process_pending_jobs( 240 );

		$job = $store->get_jobs_for_snapshot( $snapshot_id )[0];
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_FAILED_PERMANENT, $job->status );
		$this->assertCount( 1, $events );
		$this->assertSame( 0, $events[0]['site_id'] );
		$this->assertSame( $dest_id, $events[0]['destination_id'] );
		$this->assertSame( 'local', $events[0]['provider'] );
		$this->assertStringContainsString( 'Snapshot record is gone', $events[0]['error'] );
	}

	/**
	 * A job whose destination row was deleted announces the terminal
	 * failure with the destination id and provider preserved from the
	 * job row.
	 *
	 * @return void
	 */
	public function test_deleted_destination_fires_upload_failed() {
		list( $dest_id ) = $this->make_local_destination();
		$snapshot_id     = $this->make_snapshot( $this->standard_files() );
		$store           = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $snapshot_id, array( $dest_id ) );

		MainWP_Backup_Storage_Destinations::instance()->delete_destination( $dest_id );

		$events = array();
		add_action(
			'mainwp_backup_upload_failed',
			function ( $event ) use ( &$events ) {
				$events[] = $event;
			}
		);

		MainWP_Backup_Upload_Driver::instance()->process_pending_jobs( 240 );

		$this->assertCount( 1, $events );
		$this->assertSame( 4, $events[0]['site_id'] );
		$this->assertSame( $dest_id, $events[0]['destination_id'] );
		$this->assertSame( 'local', $events[0]['provider'] );
		$this->assertStringContainsString( 'destination', strtolower( $events[0]['error'] ) );
	}

	/**
	 * A destination whose stored credentials no longer decrypt announces
	 * the terminal failure when its job parks credentials-invalid.
	 *
	 * @return void
	 */
	public function test_undecryptable_credentials_fire_upload_failed() {
		list( $dest_id ) = $this->make_local_destination();
		$snapshot_id     = $this->make_snapshot( $this->standard_files() );
		$store           = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $snapshot_id, array( $dest_id ) );

		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			MainWP_DB::instance()->get_table_name( 'wp_backup_storage' ),
			array( 'settings' => 'not-a-credential-envelope' ),
			array( 'id' => $dest_id )
		);

		$events = array();
		add_action(
			'mainwp_backup_upload_failed',
			function ( $event ) use ( &$events ) {
				$events[] = $event;
			}
		);

		MainWP_Backup_Upload_Driver::instance()->process_pending_jobs( 240 );

		$job = $store->get_jobs_for_snapshot( $snapshot_id )[0];
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_CREDENTIALS_INVALID, $job->status );
		$this->assertCount( 1, $events );
		$this->assertSame( 4, $events[0]['site_id'] );
		$this->assertSame( $dest_id, $events[0]['destination_id'] );
		$this->assertStringContainsString( 'credentials', strtolower( $events[0]['error'] ) );
	}

	/**
	 * A destination whose provider slug no longer constructs announces
	 * the terminal failure when provider construction throws.
	 *
	 * @return void
	 */
	public function test_unknown_provider_fires_upload_failed() {
		list( $dest_id ) = $this->make_local_destination();
		$snapshot_id     = $this->make_snapshot( $this->standard_files() );
		$store           = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $snapshot_id, array( $dest_id ) );

		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			MainWP_DB::instance()->get_table_name( 'wp_backup_storage' ),
			array( 'provider' => 'bogus' ),
			array( 'id' => $dest_id )
		);

		$events = array();
		add_action(
			'mainwp_backup_upload_failed',
			function ( $event ) use ( &$events ) {
				$events[] = $event;
			}
		);

		MainWP_Backup_Upload_Driver::instance()->process_pending_jobs( 240 );

		$job = $store->get_jobs_for_snapshot( $snapshot_id )[0];
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_FAILED_PERMANENT, $job->status );
		$this->assertCount( 1, $events );
		$this->assertSame( 4, $events[0]['site_id'] );
		$this->assertSame( $dest_id, $events[0]['destination_id'] );
		$this->assertStringContainsString( 'Unknown storage provider', $events[0]['error'] );
	}

	/**
	 * Enqueue stamps each job with its destination's provider so the
	 * offsite rollup can tell local from cloud even if the destination is
	 * later deleted.
	 *
	 * @return void
	 */
	public function test_enqueue_stamps_destination_provider() {
		$s3_id            = MainWP_Backup_Storage_Destinations::instance()->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Stamp test',
				'settings' => array(
					'bucket'     => 'b',
					'region'     => 'us-east-1',
					'access_key' => 'k',
					'secret_key' => 's',
				),
			)
		);
		list( $local_id ) = $this->make_local_destination();
		$snapshot_id      = $this->record_bare_snapshot();

		MainWP_Backup_Upload_Job_Store::instance()->enqueue_for_snapshot( $snapshot_id, array( $s3_id, $local_id ) );

		$by_dest = array_column( MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( $snapshot_id ), null, 'destination_id' );
		$this->assertSame( 's3', $by_dest[ $s3_id ]->provider );
		$this->assertSame( 'local', $by_dest[ $local_id ]->provider );
	}

	/**
	 * A snapshot whose non-local copies all completed resolves to
	 * verified, stamps verified_at, and announces itself.
	 *
	 * @return void
	 */
	public function test_resolve_offsite_state_verified_when_all_nonlocal_complete() {
		$snapshot_id = $this->record_bare_snapshot();
		$this->seed_upload_job( $snapshot_id, 1, 's3', MainWP_Backup_Upload_Job_Store::STATUS_COMPLETE );
		$this->seed_upload_job( $snapshot_id, 2, 'gcs', MainWP_Backup_Upload_Job_Store::STATUS_COMPLETE );

		$verified = array();
		add_action(
			'mainwp_backup_snapshot_offsite_verified',
			function ( $id ) use ( &$verified ) {
				$verified[] = $id;
			}
		);

		MainWP_Backup_Upload_Driver::instance()->resolve_snapshot_offsite_state( $snapshot_id );

		$snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id );
		$this->assertSame( 'verified', $snapshot->offsite_state );
		$this->assertGreaterThan( 0, (int) $snapshot->verified_at );
		$this->assertSame( array( $snapshot_id ), $verified );
	}

	/**
	 * A local-only snapshot never reaches verified: local uploads are off
	 * the dashboard's own disk, not offsite.
	 *
	 * @return void
	 */
	public function test_resolve_offsite_state_local_only_stays_empty() {
		$snapshot_id = $this->record_bare_snapshot();
		$this->seed_upload_job( $snapshot_id, 1, 'local', MainWP_Backup_Upload_Job_Store::STATUS_COMPLETE );

		MainWP_Backup_Upload_Driver::instance()->resolve_snapshot_offsite_state( $snapshot_id );

		$this->assertSame( '', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id )->offsite_state );
	}

	/**
	 * A snapshot with no upload jobs at all stays unset.
	 *
	 * @return void
	 */
	public function test_resolve_offsite_state_no_destinations_stays_empty() {
		$snapshot_id = $this->record_bare_snapshot();

		MainWP_Backup_Upload_Driver::instance()->resolve_snapshot_offsite_state( $snapshot_id );

		$this->assertSame( '', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id )->offsite_state );
	}

	/**
	 * A legacy upload row with a blank provider is treated as unknown and
	 * can never grant verified.
	 *
	 * @return void
	 */
	public function test_resolve_offsite_state_blank_provider_never_verifies() {
		$snapshot_id = $this->record_bare_snapshot();
		$this->seed_upload_job( $snapshot_id, 1, '', MainWP_Backup_Upload_Job_Store::STATUS_COMPLETE );

		MainWP_Backup_Upload_Driver::instance()->resolve_snapshot_offsite_state( $snapshot_id );

		$this->assertSame( '', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id )->offsite_state );
	}

	/**
	 * One non-local copy complete and another failed resolves to partial.
	 *
	 * @return void
	 */
	public function test_resolve_offsite_state_partial_on_mixed_outcome() {
		$snapshot_id = $this->record_bare_snapshot();
		$this->seed_upload_job( $snapshot_id, 1, 's3', MainWP_Backup_Upload_Job_Store::STATUS_COMPLETE );
		$this->seed_upload_job( $snapshot_id, 2, 'gcs', MainWP_Backup_Upload_Job_Store::STATUS_FAILED_PERMANENT );

		MainWP_Backup_Upload_Driver::instance()->resolve_snapshot_offsite_state( $snapshot_id );

		$this->assertSame( 'partial', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id )->offsite_state );
	}

	/**
	 * All non-local copies terminal-failed resolves to failed, not stuck
	 * pending - the rollup runs on the terminal-failure transition.
	 *
	 * @return void
	 */
	public function test_resolve_offsite_state_failed_when_all_terminal_failed() {
		$snapshot_id = $this->record_bare_snapshot();
		$this->seed_upload_job( $snapshot_id, 1, 's3', MainWP_Backup_Upload_Job_Store::STATUS_FAILED_PERMANENT );
		$this->seed_upload_job( $snapshot_id, 2, 'gcs', MainWP_Backup_Upload_Job_Store::STATUS_CREDENTIALS_INVALID );

		MainWP_Backup_Upload_Driver::instance()->resolve_snapshot_offsite_state( $snapshot_id );

		$this->assertSame( 'failed', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id )->offsite_state );
	}

	/**
	 * Any non-terminal non-local job keeps the snapshot pending.
	 *
	 * @return void
	 */
	public function test_resolve_offsite_state_pending_while_in_flight() {
		$snapshot_id = $this->record_bare_snapshot();
		$this->seed_upload_job( $snapshot_id, 1, 's3', MainWP_Backup_Upload_Job_Store::STATUS_COMPLETE );
		$this->seed_upload_job( $snapshot_id, 2, 'gcs', MainWP_Backup_Upload_Job_Store::STATUS_UPLOADING );

		MainWP_Backup_Upload_Driver::instance()->resolve_snapshot_offsite_state( $snapshot_id );

		$this->assertSame( 'pending', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id )->offsite_state );
	}

	/**
	 * Completing a real local-destination upload drives the rollup through
	 * the settle signal: the job reaches complete and the snapshot, being
	 * local-only, settles to empty (never verified).
	 *
	 * @return void
	 */
	public function test_terminal_transition_drives_rollup_local_stays_empty() {
		MainWP_Backup_Upload_Driver::instance()->init();
		list( $dest_id ) = $this->make_local_destination();
		$snapshot_id     = $this->make_snapshot( $this->standard_files() );
		MainWP_Backup_Upload_Job_Store::instance()->enqueue_for_snapshot( $snapshot_id, array( $dest_id ) );

		MainWP_Backup_Upload_Driver::instance()->process_pending_jobs( 240 );

		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_COMPLETE, MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( $snapshot_id )[0]->status );
		$this->assertSame( '', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id )->offsite_state );
	}

	/**
	 * A post-write HEAD whose size does not match keeps the job retryable
	 * and the snapshot out of verified: complete must imply a remotely
	 * present object.
	 *
	 * @return void
	 */
	public function test_head_size_mismatch_keeps_job_retryable() {
		MainWP_Backup_Upload_Driver::instance()->init();
		$dest_id     = MainWP_Backup_Storage_Destinations::instance()->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Torn writer',
				'settings' => array(
					'bucket'     => 'b',
					'region'     => 'us-east-1',
					'access_key' => 'k',
					'secret_key' => 's',
				),
			)
		);
		$snapshot_id = $this->make_snapshot(
			array(
				'small.txt' => array(
					'size' => 100,
					'role' => 'db',
				),
			)
		);
		MainWP_Backup_Upload_Job_Store::instance()->enqueue_for_snapshot( $snapshot_id, array( $dest_id ) );

		// PUTs succeed; every HEAD reports a size that never matches what
		// was written, so the post-write confirmation always fails.
		add_filter(
			'pre_http_request',
			function ( $pre, $args ) {
				$method = isset( $args['method'] ) ? strtoupper( $args['method'] ) : 'GET';
				if ( 'HEAD' === $method ) {
					return array(
						'headers'  => array( 'content-length' => '1' ),
						'body'     => '',
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
					);
				}
				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
				);
			},
			10,
			2
		);

		MainWP_Backup_Upload_Driver::instance()->process_pending_jobs( 240 );

		$job = MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( $snapshot_id )[0];
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_FAILED_RETRYABLE, $job->status, 'A HEAD size mismatch must keep the job retryable.' );
		$this->assertNotSame( 'verified', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id )->offsite_state );
	}

	/**
	 * An armed run lands ciphertext objects, not the source bytes: each chunk
	 * and whole file is sealed (plaintext + 28), decrypts back under the
	 * snapshot DEK, and the manifest carries the encryption block, the recovery
	 * blob, and a sibling HMAC over its exact bytes.
	 *
	 * @return void
	 */
	public function test_armed_run_uploads_ciphertext_and_signed_manifest() {
		$enc                    = $this->arm_encryption();
		list( $dest_id, $base ) = $this->make_local_destination();
		$snapshot_id            = $this->make_snapshot( $this->standard_files(), 0, 4 );
		MainWP_Backup_Upload_Job_Store::instance()->enqueue_for_snapshot( $snapshot_id, array( $dest_id ) );

		MainWP_Backup_Upload_Driver::instance()->process_pending_jobs( 240 );

		$jobs = MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( $snapshot_id );
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_COMPLETE, $jobs[0]->status, 'Sealed upload must complete; the +28 confirm-size must match.' );

		$snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id );
		$paths    = array_column( $snapshot->archives, 'path', 'file' );
		$kek      = $enc->load_kek();
		$dek      = $enc->dek_from_meta( $snapshot->encryption, $snapshot_id, $kek );
		$prefix   = $base . '/4/' . $snapshot_id;

		// Each chunk object is ciphertext (plaintext + 28) and opens to the
		// source slice under the snapshot DEK and the chunk-hash AAD.
		$big  = file_get_contents( $paths['big.bin'] );
		$algo = MainWP_Backup_Chunker::hash_algo();
		foreach ( array( 0, 1024, 2048 ) as $offset ) {
			$slice = substr( $big, $offset, 1024 );
			$hash  = hash( $algo, $slice );
			$blob  = file_get_contents( $prefix . '/chunks/' . $hash . '.bin' );
			$this->assertSame( strlen( $slice ) + 28, strlen( $blob ), 'Chunk object must be ciphertext + framing.' );
			$this->assertNotSame( $slice, $blob, 'Chunk object must not be plaintext.' );
			$this->assertSame( $slice, MainWP_Backup_Archive_Cipher::open( $blob, $dek, 'snapshot:' . $snapshot_id . ':chunk:' . $hash ) );
		}

		// The whole small file is sealed too and opens under its whole AAD.
		$small      = file_get_contents( $paths['small.txt'] );
		$small_blob = file_get_contents( $prefix . '/files/small.txt' );
		$this->assertSame( strlen( $small ) + 28, strlen( $small_blob ) );
		$this->assertSame( $small, MainWP_Backup_Archive_Cipher::open( $small_blob, $dek, 'snapshot:' . $snapshot_id . ':whole:small.txt' ) );

		// The manifest stays readable JSON and carries the keying material.
		$json     = file_get_contents( $prefix . '/manifest.json' );
		$manifest = json_decode( $json, true );
		$this->assertSame( 'aes-256-gcm', $manifest['encryption']['cipher'] );
		$this->assertSame( 28, $manifest['encryption']['overhead'] );
		$this->assertNotEmpty( $manifest['encryption']['wrapped_dek'] );
		$this->assertNotEmpty( $manifest['recovery']['wrapped_kek'] );

		// The sibling HMAC authenticates the exact manifest bytes under the KEK.
		$this->assertFileExists( $prefix . '/manifest.json.hmac' );
		$this->assertSame( $enc->manifest_hmac( $json, $kek ), file_get_contents( $prefix . '/manifest.json.hmac' ) );

		$new_blob = $enc->set_recovery_passphrase( 'replacement upload passphrase', 'upload driver encryption passphrase' );
		$this->assertNotSame( $manifest['recovery'], $new_blob );
		$this->assertSame( $manifest['recovery']['kek_version_id'], $new_blob['kek_version_id'] );
		$recovered = $enc->recover_kek( 'upload driver encryption passphrase', $manifest['recovery'] );
		$this->assertSame( $enc->load_kek(), $recovered );
		$this->assertSame( $dek, $enc->dek_from_meta( $manifest['encryption'], $snapshot_id, $recovered ) );
		$this->assertSame( $small, MainWP_Backup_Archive_Cipher::open( $small_blob, $dek, 'snapshot:' . $snapshot_id . ':whole:small.txt' ) );
		$this->assertSame( $enc->manifest_hmac( $json, $enc->load_kek() ), file_get_contents( $prefix . '/manifest.json.hmac' ) );
		$this->assertSame( $json, file_get_contents( $prefix . '/manifest.json' ) );
	}

	/**
	 * Creation-time encryption intent controls uploads even after key repair
	 * or a setting change. Count every provider PUT, including the manifest.
	 *
	 * @dataProvider encryption_creation_states
	 * @param string $state Encryption state at creation and before upload.
	 * @param string $outcome Expected upload outcome.
	 * @param string $mutation Metadata or key damage before upload.
	 */
	public function test_b151_encryption_intent_controls_provider_writes( $state, $outcome, $mutation = '' ) {
		$enc = MainWP_Backup_Encryption::instance();
		update_option( MainWP_Backup_Encryption::ENABLED_OPTION, 'disabled' === $state ? '' : '1' );
		if ( 'armed' === $state ) {
			$this->arm_encryption();
		}

		$dest_id = MainWP_Backup_Storage_Destinations::instance()->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Encryption intent target',
				'settings' => array(
					'bucket'     => 'b',
					'region'     => 'us-east-1',
					'access_key' => 'k',
					'secret_key' => 's',
				),
			)
		);
		$task_id = $this->insert_task( array( $dest_id ) );
		$driver  = MainWP_Backup_Upload_Driver::instance();
		$driver->init();
		$snapshot_id = $this->make_snapshot( $this->standard_files(), $task_id );
		$this->assertGreaterThan( 0, $snapshot_id );
		$snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id );

		if ( 'repaired' === $state ) {
			$enc->set_recovery_passphrase( 'repaired upload encryption passphrase' );
			$this->assertTrue( $enc->is_armed() );
		} elseif ( 'toggle-off' === $state ) {
			update_option( MainWP_Backup_Encryption::ENABLED_OPTION, '' );
		}
		if ( '' !== $mutation ) {
			global $wpdb;
			$meta             = $snapshot->encryption;
			$meta['required'] = 1;
			if ( 'missing-key' === $mutation ) {
				MainWP_Keys_Manager::instance()->delete_key_file( $enc->kek_filename() );
			} elseif ( 'key-version' === $mutation ) {
				$meta['kek_version_id'] = 'different-key-version';
			} elseif ( 'corrupt-dek' === $mutation ) {
				$meta['wrapped_dek'] = 'not-valid-base64!';
			} else {
				unset( $meta[ $mutation ] );
			}
			$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'encryption' => wp_json_encode( $meta ) ), array( 'id' => $snapshot_id ) );
			$snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id );
		}

		$staging = $this->private_root . '/upload-staging/' . $snapshot_id . '-' . $dest_id;
		if ( in_array( $mutation, array( 'missing-key', 'key-version' ), true ) ) {
			wp_mkdir_p( $staging );
			file_put_contents( $staging . '/partial.bin', 'existing staged chunk' );
			$this->assertFileExists( $staging . '/partial.bin' );
			if ( 'missing-key' === $mutation ) {
				$this->assertNull( $enc->load_kek(), 'The key must be unreadable at upload time.' );
			} else {
				$this->assertNotNull( $enc->load_kek() );
				$this->assertNotSame( $snapshot->encryption['kek_version_id'], $enc->kek_version_id( $enc->load_kek() ) );
			}
		}

		$writes  = array();
		$objects = array();
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$writes, &$objects ) {
				$key    = wp_parse_url( $url, PHP_URL_PATH );
				$method = strtoupper( $args['method'] );
				if ( 'PUT' === $method ) {
					$writes[]        = $key;
					$objects[ $key ] = $args['body'];
				}
				$present = isset( $objects[ $key ] );
				return array(
					'headers'  => $present ? array( 'content-length' => (string) strlen( $objects[ $key ] ) ) : array(),
					'body'     => '',
					'response' => array(
						'code'    => $present ? 200 : 404,
						'message' => $present ? 'OK' : 'Not Found',
					),
				);
			},
			10,
			3
		);
		$events = array();
		add_action(
			'mainwp_backup_upload_failed',
			function ( $event ) use ( &$events ) {
				$events[] = $event;
			}
		);

		$driver->process_pending_jobs( 240 );
		$job = MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( $snapshot_id )[0];
		if ( 'refused' === $outcome || 'retryable' === $outcome ) {
			$this->assertCount( 0, $writes, 'Encryption-required snapshots must refuse before any provider write.' );
			$this->assertStringContainsString( '[mainwp_backup_encryption_required]', $job->last_error );
			if ( 'retryable' === $outcome ) {
				$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_FAILED_RETRYABLE, $job->status );
				$this->assertGreaterThan( time(), (int) $job->next_retry_at );
				$this->assertSame( 'existing staged chunk', file_get_contents( $staging . '/partial.bin' ) );
				$this->assertSame( array(), $events );
			} else {
				$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_FAILED_PERMANENT, $job->status );
				$this->assertDirectoryDoesNotExist( $staging );
				$this->assertCount( 1, $events );
				$this->assertSame( $job->last_error, $events[0]['error'] );
				$this->assertSame( $snapshot_id, $events[0]['snapshot_id'] );
				$this->assertSame( 'failed', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id )->offsite_state );
			}
			$this->assertSame( 1, $snapshot->encryption['required'] ?? null );
			$this->assertSame( $snapshot->encryption, MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id )->encryption );
			return;
		}

		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_COMPLETE, $job->status );
		$this->assertCount( 'encrypted' === $outcome ? 7 : 6, $writes );
		$this->assertSame( array(), $events );
		$prefix   = '/4/' . $snapshot_id . '/';
		$paths    = array_column( $snapshot->archives, 'path', 'file' );
		$manifest = json_decode( $objects[ $prefix . 'manifest.json' ], true );
		if ( 'encrypted' === $outcome ) {
			$this->assertSame( 1, $snapshot->encryption['required'] ?? null );
			$this->assertSame( 1, $manifest['encryption']['enc'] );
			$dek = $enc->dek_from_meta( $snapshot->encryption, $snapshot_id, $enc->load_kek() );
			$this->assertSame( $enc->manifest_hmac( $objects[ $prefix . 'manifest.json' ], $enc->load_kek() ), $objects[ $prefix . 'manifest.json.hmac' ] );
		} else {
			$this->assertSame( array(), $snapshot->encryption );
			$this->assertArrayNotHasKey( 'encryption', $manifest );
		}
		foreach ( array( 'small.txt', 'files.list' ) as $file ) {
			$body = $objects[ $prefix . 'files/' . $file ];
			if ( 'encrypted' === $outcome ) {
				$this->assertNotSame( file_get_contents( $paths[ $file ] ), $body );
				$body = MainWP_Backup_Archive_Cipher::open( $body, $dek, 'snapshot:' . $snapshot_id . ':whole:' . $file );
			}
			$this->assertSame( file_get_contents( $paths[ $file ] ), $body );
		}
		foreach ( array( 0, 1024, 2048 ) as $offset ) {
			$slice = substr( file_get_contents( $paths['big.bin'] ), $offset, 1024 );
			$hash  = hash( MainWP_Backup_Chunker::hash_algo(), $slice );
			$body  = $objects[ $prefix . 'chunks/' . $hash . '.bin' ];
			if ( 'encrypted' === $outcome ) {
				$this->assertNotSame( $slice, $body );
				$body = MainWP_Backup_Archive_Cipher::open( $body, $dek, 'snapshot:' . $snapshot_id . ':chunk:' . $hash );
			}
			$this->assertSame( $slice, $body );
		}
	}

	/**
	 * Encryption state changes must not erase a snapshot's requirement.
	 *
	 * @return array Creation states and upload outcomes.
	 */
	public static function encryption_creation_states() {
		return array(
			'enabled and armed'              => array( 'armed', 'encrypted' ),
			'enabled and unarmed'            => array( 'unarmed', 'refused' ),
			'key repaired after creation'    => array( 'repaired', 'refused' ),
			'disabled at creation'           => array( 'disabled', 'plaintext' ),
			'toggle disabled after creation' => array( 'toggle-off', 'refused' ),
			'missing encryption flag'        => array( 'armed', 'refused', 'enc' ),
			'missing wrapped DEK'            => array( 'armed', 'refused', 'wrapped_dek' ),
			'corrupt wrapped DEK'            => array( 'armed', 'refused', 'corrupt-dek' ),
			'key unreadable before upload'   => array( 'armed', 'retryable', 'missing-key' ),
			'key version mismatch'           => array( 'armed', 'refused', 'key-version' ),
		);
	}

	/**
	 * Two destinations for one snapshot seal under the same per-snapshot DEK,
	 * so one key recovers the bytes from either copy. The DEK is minted once at
	 * record time, not per upload job, so the destinations cannot diverge.
	 *
	 * @return void
	 */
	public function test_armed_multi_destination_shares_one_dek() {
		$enc                     = $this->arm_encryption();
		list( $dest_a, $base_a ) = $this->make_local_destination();
		list( $dest_b, $base_b ) = $this->make_local_destination();
		$snapshot_id             = $this->make_snapshot(
			array(
				'small.txt' => array(
					'size' => 100,
					'role' => 'db',
				),
			),
			0,
			4
		);
		MainWP_Backup_Upload_Job_Store::instance()->enqueue_for_snapshot( $snapshot_id, array( $dest_a, $dest_b ) );

		MainWP_Backup_Upload_Driver::instance()->process_pending_jobs( 240 );
		MainWP_Backup_Upload_Driver::instance()->process_pending_jobs( 240 );

		$snapshot = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id );
		$paths    = array_column( $snapshot->archives, 'path', 'file' );
		$dek      = $enc->dek_from_meta( $snapshot->encryption, $snapshot_id, $enc->load_kek() );
		$small    = file_get_contents( $paths['small.txt'] );
		$aad      = 'snapshot:' . $snapshot_id . ':whole:small.txt';

		$blob_a = file_get_contents( $base_a . '/4/' . $snapshot_id . '/files/small.txt' );
		$blob_b = file_get_contents( $base_b . '/4/' . $snapshot_id . '/files/small.txt' );

		$this->assertNotSame( $blob_a, $blob_b, 'Independent seals use fresh nonces, so the ciphertexts differ.' );
		$this->assertSame( $small, MainWP_Backup_Archive_Cipher::open( $blob_a, $dek, $aad ) );
		$this->assertSame( $small, MainWP_Backup_Archive_Cipher::open( $blob_b, $dek, $aad ) );
	}

	/**
	 * Re-entry over an encrypted destination skips intact ciphertext objects
	 * instead of re-uploading: the already-present check accounts for the
	 * cipher framing overhead.
	 *
	 * @return void
	 */
	public function test_armed_reentry_skips_intact_ciphertext() {
		$this->arm_encryption();
		list( $dest_id, $base ) = $this->make_local_destination();
		$snapshot_id            = $this->make_snapshot( $this->standard_files() );
		$store                  = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $snapshot_id, array( $dest_id ) );

		MainWP_Backup_Upload_Driver::instance()->process_pending_jobs( 240 );

		$prefix = $base . '/4/' . $snapshot_id;
		$chunks = glob( $prefix . '/chunks/*.bin' );
		$this->assertNotEmpty( $chunks );
		$intact = $chunks[0];
		$past   = time() - 3600;
		touch( $intact, $past );

		// Rewind to the upload phase distrusting prior work, then run again.
		$job        = $store->get_jobs_for_snapshot( $snapshot_id )[0];
		$checkpoint = $job->checkpoint;
		foreach ( $checkpoint['artifacts'] as &$artifact ) {
			$artifact['chunks_cursor'] = 0;
			$artifact['done']          = false;
			if ( is_array( $artifact['chunks'] ) ) {
				foreach ( $artifact['chunks'] as &$chunk ) {
					$chunk['remote_present'] = false;
				}
				unset( $chunk );
			}
		}
		unset( $artifact );
		$checkpoint['manifest_submitted'] = false;
		$store->save_checkpoint( (int) $job->id, $checkpoint );
		$store->set_status( (int) $job->id, MainWP_Backup_Upload_Job_Store::STATUS_UPLOADING );

		MainWP_Backup_Upload_Driver::instance()->process_pending_jobs( 240 );

		clearstatcache();
		$this->assertSame( $past, filemtime( $intact ), 'An intact ciphertext chunk must be skipped, not rewritten.' );
	}

	/**
	 * A lease lost mid-run - a second lane reclaims it between the
	 * pre-upload presence check and the confirming write - stops the
	 * zombie cold: its post-reclaim checkpoint and status writes are
	 * fenced-refused, it never fires the uploaded event, and its own
	 * finally-block release_lock() must not clear the new holder's lock.
	 *
	 * @return void
	 */
	public function test_lease_loss_mid_run_stops_writes_and_fires_no_events() {
		$dest_id     = MainWP_Backup_Storage_Destinations::instance()->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Lease loss target',
				'settings' => array(
					'bucket'     => 'b',
					'region'     => 'us-east-1',
					'access_key' => 'k',
					'secret_key' => 's',
				),
			)
		);
		$snapshot_id = $this->make_snapshot(
			array(
				'small.txt' => array(
					'size' => 100,
					'role' => 'db',
				),
			)
		);
		$store       = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $snapshot_id, array( $dest_id ) );
		$job_id = (int) $store->get_jobs_for_snapshot( $snapshot_id )[0]->id;

		global $wpdb;
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' );

		// The first HEAD is the pre-upload presence check. It simulates a
		// second lane winning the reclaim while this request is in flight:
		// force-expire the lease, then win it under a second token, before
		// answering "not present" so the run proceeds to upload as normal.
		$reclaimed = false;
		add_filter(
			'pre_http_request',
			function ( $pre, $args ) use ( &$reclaimed, $job_id, $store, $wpdb, $table ) {
				$method = isset( $args['method'] ) ? strtoupper( $args['method'] ) : 'GET';
				if ( 'HEAD' !== $method ) {
					return array(
						'headers'  => array(),
						'body'     => '',
						'response' => array(
							'code'    => 200,
							'message' => 'OK',
						),
					);
				}

				if ( ! $reclaimed ) {
					$reclaimed = true;
					$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture forcing lease expiry mid-run.
						$table,
						array( 'locked_until' => 1 ),
						array( 'id' => $job_id )
					);
					$store->acquire_next_job( 'token-thief', 300, time() );
					return array(
						'headers'  => array(),
						'body'     => '',
						'response' => array(
							'code'    => 404,
							'message' => 'Not Found',
						),
					);
				}

				// The post-write confirming HEAD: report the exact size just
				// written so the zombie's upload does not throw and the run
				// proceeds to its natural fenced refusal instead.
				return array(
					'headers'  => array( 'content-length' => '100' ),
					'body'     => '',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
				);
			},
			10,
			2
		);

		$events = array();
		add_action(
			'mainwp_backup_uploaded',
			function ( $event ) use ( &$events ) {
				$events[] = $event;
			}
		);

		MainWP_Backup_Upload_Driver::instance()->process_pending_jobs( 240 );

		$job = $store->get_job( $job_id );
		$this->assertSame( 'token-thief', $job->locked_by, "The zombie's own finally-block release must not clear the new holder's lock." );
		$this->assertNotSame( MainWP_Backup_Upload_Job_Store::STATUS_COMPLETE, $job->status, 'The zombie must not complete a job it no longer owns.' );
		$this->assertSame( array(), $events, 'No uploaded event may fire for a run whose lease was lost mid-flight.' );
		$this->assertTrue( empty( $job->checkpoint['artifacts'][0]['done'] ), "The zombie's post-reclaim checkpoint write must not land." );
	}

	/**
	 * A transient DB error on the initial artifact-index checkpoint must park
	 * the job for the next tick, never complete it over an empty checkpoint.
	 *
	 * The index built at process_one_job's first save is what a later restore
	 * reads to locate the offsite artifacts. If that write is silently ignored
	 * the job can still reach COMPLETE with an empty persisted checkpoint, and
	 * the "offsite-verified" snapshot becomes unrestorable once retention
	 * prunes the local copy.
	 *
	 * @return void
	 */
	public function test_initial_checkpoint_save_failure_parks_job() {
		list( $dest_id ) = $this->make_local_destination();
		$snapshot_id     = $this->make_snapshot( $this->standard_files() );

		$failing = new class() extends MainWP_Backup_Upload_Job_Store {
			/**
			 * Fail the first checkpoint write (the artifact index), then behave.
			 *
			 * @var int
			 */
			public $calls = 0;

			/**
			 * Model a transient DB error on the initial index checkpoint.
			 *
			 * @param int         $job_id     Job id.
			 * @param array       $checkpoint Checkpoint data.
			 * @param string|null $lock_token Lease token.
			 *
			 * @return bool
			 */
			public function save_checkpoint( int $job_id, array $checkpoint, ?string $lock_token = null ): bool {
				++$this->calls;
				if ( 1 === $this->calls ) {
					return false;
				}
				return parent::save_checkpoint( $job_id, $checkpoint, $lock_token );
			}
		};

		$prop = new \ReflectionProperty( MainWP_Backup_Upload_Job_Store::class, 'instance' );
		$prop->setAccessible( true );
		$saved = $prop->getValue();
		$prop->setValue( null, $failing );

		try {
			MainWP_Backup_Upload_Job_Store::instance()->enqueue_for_snapshot( $snapshot_id, array( $dest_id ) );
			MainWP_Backup_Upload_Driver::instance()->process_pending_jobs( 240 );
			$job = MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( $snapshot_id )[0];
		} finally {
			$prop->setValue( null, $saved );
		}

		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_PENDING, $job->status, 'A failed initial index save must leave the job pending for the next tick, not complete it.' );
		$this->assertSame( 1, $failing->calls, 'A parked job must not be attempted twice within one tick.' );
		$this->assertSame( '', $job->locked_by, 'The repeated acquisition must release its lock.' );
		$this->assertEmpty( $job->checkpoint['artifacts'] ?? array(), 'The unpersisted checkpoint must stay empty; the job must not have completed over it.' );
	}

	/**
	 * A transient DB error on the terminal manifest checkpoint must not let the
	 * job reach COMPLETE over an unpersisted terminal state. The remote
	 * manifest re-PUT on the next tick is idempotent by construction, so the
	 * job resumes and completes only once its checkpoint has actually landed.
	 *
	 * @return void
	 */
	public function test_manifest_checkpoint_save_failure_does_not_complete_job() {
		list( $dest_id ) = $this->make_local_destination();
		$snapshot_id     = $this->make_snapshot( $this->standard_files() );

		$failing = new class() extends MainWP_Backup_Upload_Job_Store {
			/**
			 * Fail only the terminal manifest_submitted checkpoint write.
			 *
			 * @param int         $job_id     Job id.
			 * @param array       $checkpoint Checkpoint data.
			 * @param string|null $lock_token Lease token.
			 *
			 * @return bool
			 */
			public function save_checkpoint( int $job_id, array $checkpoint, ?string $lock_token = null ): bool {
				if ( ! empty( $checkpoint['manifest_submitted'] ) ) {
					return false;
				}
				return parent::save_checkpoint( $job_id, $checkpoint, $lock_token );
			}
		};

		$prop = new \ReflectionProperty( MainWP_Backup_Upload_Job_Store::class, 'instance' );
		$prop->setAccessible( true );
		$saved = $prop->getValue();
		$prop->setValue( null, $failing );

		try {
			MainWP_Backup_Upload_Job_Store::instance()->enqueue_for_snapshot( $snapshot_id, array( $dest_id ) );
			MainWP_Backup_Upload_Driver::instance()->process_pending_jobs( 240 );
			$job = MainWP_Backup_Upload_Job_Store::instance()->get_jobs_for_snapshot( $snapshot_id )[0];
		} finally {
			$prop->setValue( null, $saved );
		}

		$this->assertNotSame( MainWP_Backup_Upload_Job_Store::STATUS_COMPLETE, $job->status, 'A failed terminal manifest checkpoint must not complete the job.' );
		$this->assertNotEmpty( $job->checkpoint['artifacts'], 'The artifact index persisted earlier must survive; only the terminal write failed.' );
	}

	/**
	 * Operators can repair private storage while the upload waits in backoff.
	 */
	public function test_private_staging_failure_is_retryable_and_cleans_legacy() {
		list( $dest_id ) = $this->make_local_destination();
		$snapshot_id     = $this->make_snapshot( $this->standard_files() );
		$store           = MainWP_Backup_Upload_Job_Store::instance();
		$store->enqueue_for_snapshot( $snapshot_id, array( $dest_id ) );
		add_filter( 'mainwp_backup_private_root', '__return_false', 99 );
		\MainWP\Dashboard\MainWP_Backup_Private_Root::reset();
		$now = time();
		MainWP_Backup_Upload_Driver::instance()->process_pending_jobs( 240, $now );
		$job = $store->get_jobs_for_snapshot( $snapshot_id )[0];
		$this->assertSame( MainWP_Backup_Upload_Job_Store::STATUS_FAILED_RETRYABLE, $job->status );
		$this->assertGreaterThan( $now, (int) $job->next_retry_at );
		$this->assertStringContainsString( 'Private backup directory', $job->last_error );
		$this->assertStringStartsWith( '[' . \MainWP\Dashboard\MainWP_Backup_Error_Codes::ERR_PRIVATE_ROOT_UNAVAILABLE . '] ', $job->last_error );
		$base   = $this->make_temp_dir();
		$filter = static function ( $dirs ) use ( $base ) {
			$dirs['basedir'] = $base;
			return $dirs;
		};
		add_filter( 'mainwp_get_wp_upload_dir', $filter );
		$upload = apply_filters( 'mainwp_get_wp_upload_dir', wp_upload_dir( null, false ), null, false );
		$legacy = trailingslashit( $upload['basedir'] ) . 'mainwp/upload-staging/' . $snapshot_id . '-' . $dest_id . '/';
		wp_mkdir_p( $legacy );
		file_put_contents( $legacy . 'partial.bin', 'partial' );
		$cleanup = new \ReflectionMethod( MainWP_Backup_Upload_Driver::class, 'cleanup_staging' );
		$cleanup->invoke( MainWP_Backup_Upload_Driver::instance(), $job );
		remove_filter( 'mainwp_get_wp_upload_dir', $filter );
		$this->assertDirectoryDoesNotExist( $legacy );
	}
}
