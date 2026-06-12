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

use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_Backup_Storage_Destinations;
use MainWP\Dashboard\MainWP_Backup_Upload_Driver;
use MainWP\Dashboard\MainWP_Backup_Upload_Job_Store;
use MainWP\Dashboard\MainWP_DB;

/**
 * Class MainWP_Backup_Upload_Driver_Test
 */
class MainWP_Backup_Upload_Driver_Test extends \WP_UnitTestCase {

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
		remove_all_actions( 'mainwp_backup_snapshot_recorded' );
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
		$this->remove_dir( trailingslashit( $upload['basedir'] ) . 'mainwp/upload-staging' );

		parent::tear_down();
	}

	/**
	 * Recursively remove a directory.
	 *
	 * @param string $dir Directory path.
	 *
	 * @return void
	 */
	private function remove_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $entry;
			if ( is_dir( $path ) ) {
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
			while ( strlen( $content ) < $spec['size'] ) {
				$content .= md5( $name . '-' . $block, true );
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
		$this->assertDirectoryDoesNotExist( trailingslashit( $upload['basedir'] ) . 'mainwp/upload-staging/' . $snapshot_id . '-' . $dest_id );

		$this->assertCount( 1, $events );
		$this->assertSame( 4, $events[0]['site_id'] );
		$this->assertSame( $snapshot_id, $events[0]['snapshot_id'] );
		$this->assertSame( $dest_id, $events[0]['destination_id'] );
		$this->assertSame( 'local', $events[0]['provider'] );
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

		$snapshot_id = $this->make_snapshot( array( 'small.txt' => array( 'size' => 100, 'role' => 'db' ) ) );
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

		$snapshot_id = $this->make_snapshot( array( 'small.txt' => array( 'size' => 100, 'role' => 'db' ) ) );
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

		$snapshot_id = $this->make_snapshot( array( 'small.txt' => array( 'size' => 100, 'role' => 'db' ) ) );
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
}
