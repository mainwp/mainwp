<?php
/**
 * MainWP Backup Diagnostics Tests
 *
 * Tests for the opt-in diagnostics recorder: JSONL event capture from
 * the backup/restore pipeline seams, the hard off-switch, slot events,
 * and the guarantee that a recorder failure never breaks a backup.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Diagnostics;
use MainWP\Dashboard\MainWP_Backup_Executor;
use MainWP\Dashboard\MainWP_Backup_Monitor;
use MainWP\Dashboard\MainWP_Backup_Restore_Job_Store;
use MainWP\Dashboard\MainWP_Backup_Restore_Orchestrator;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Backup_Error_Codes;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Exception;
use MainWP\Dashboard\MainWP_Manage_Backups_Handler;

/**
 * Class MainWP_Backup_Diagnostics_Test
 */
class MainWP_Backup_Diagnostics_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Encoding failures must not leave a corrupt bundle available for download.
	 */
	public function test_json_fallback_rejects_unencodable_section() {
		$writer = new \ReflectionMethod( MainWP_Backup_Diagnostics::class, 'write_json_bundle' );
		$path   = $this->diag_dir . '/invalid-bundle.json';
		$result = $writer->invoke(
			MainWP_Backup_Diagnostics::instance(),
			$path,
			array( 'env' => array( 'invalid' => INF ) ),
			array(
				'files' => array(),
				'blobs' => array(),
			)
		);
		$this->assertSame( '', $result );
		$this->assertFileDoesNotExist( $path );
	}

	/**
	 * Streaming must preserve the fallback's original JSON encoding and shape.
	 */
	public function test_json_fallback_matches_two_file_payload() {
		$files = array( $this->diag_dir . '/one.jsonl', $this->diag_dir . '/two.jsonl' );
		file_put_contents( $files[0], "{\"run_key\":\"1-2\"}\n\n0\n" );
		file_put_contents( $files[1], "{\"message\":\"quote \\\" slash /\"}\r\nlast line" );
		$sections           = array(
			'env'   => array( 'version' => 'test' ),
			'tasks' => array(),
		);
		$events             = array(
			'files' => $files,
			'blobs' => array( 'global.jsonl' => "first\n\n0\nlast\n" ),
		);
		$expected           = $sections;
		$expected['events'] = array();
		foreach ( $files as $file ) {
			$expected['events'][ basename( $file ) ] = array_values( array_filter( explode( "\n", file_get_contents( $file ) ) ) );
		}
		$expected['events']['global.jsonl'] = array( 'first', 'last' );
		$writer                             = new \ReflectionMethod( MainWP_Backup_Diagnostics::class, 'write_json_bundle' );
		$path                               = $this->diag_dir . '/bundle.json';
		$this->assertSame( $path, $writer->invoke( MainWP_Backup_Diagnostics::instance(), $path, $sections, $events ) );
		$actual = file_get_contents( $path );
		$this->assertSame( wp_json_encode( $expected ), $actual );
		$this->assertSame( wp_json_encode( json_decode( wp_json_encode( $expected ), true ) ), $actual );
	}

	/**
	 * Keep fixture lookups tied to the private filename contract.
	 *
	 * @param string $run_key Run identifier.
	 * @return string Event filename.
	 */
	private function event_filename( $run_key ) {
		return \MainWP\Dashboard\MainWP_System_Utility::get_private_filename( MainWP_Backup_Diagnostics::SUBDIR, $run_key, 'events' ) . '.jsonl';
	}

	/**
	 * Scratch diagnostics directory for the test.
	 *
	 * @var string
	 */
	private $diag_dir;

	/**
	 * Set up: enable native backups and point the recorder at scratch.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		update_option( 'mainwp_enableNativeBackups', 1 );

		$this->diag_dir = sys_get_temp_dir() . '/mainwp-diag-test-' . uniqid();
		mkdir( $this->diag_dir );
		add_filter( 'mainwp_backup_diagnostics_dir', array( $this, 'filter_diag_dir' ) );
	}

	/**
	 * Clean up scratch files, progress rows, and options.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_wp_backup_progress" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_wp_backup" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup.
		$wpdb->query( 'DELETE FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ) ); // phpcs:ignore WordPress.DB -- test cleanup.
		delete_option( 'mainwp_enableNativeBackups' );
		delete_option( MainWP_Backup_Diagnostics::OPTION_ENABLED );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( MainWP_Backup_Monitor::SLOT_OPTION_PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test cleanup of the DB semaphore.
		unset( $_POST['security'], $_REQUEST['security'] );

		if ( is_dir( $this->diag_dir ) ) {
			foreach ( (array) glob( $this->diag_dir . '/*' ) as $file ) {
				unlink( $file );
			}
			rmdir( $this->diag_dir );
		}
		parent::tearDown();
	}

	/**
	 * Point the recorder at the scratch directory.
	 *
	 * @return string Scratch dir.
	 */
	public function filter_diag_dir() {
		return $this->diag_dir;
	}

	/**
	 * Enable the recorder and register its hooks.
	 *
	 * @return void
	 */
	private function enable_recorder() {
		update_option( MainWP_Backup_Diagnostics::OPTION_ENABLED, '1' );
		MainWP_Backup_Diagnostics::instance()->init();
	}

	/**
	 * Decode every JSONL line in the scratch directory.
	 *
	 * @return array Decoded events.
	 */
	private function recorded_events() {
		$events = array();
		foreach ( (array) glob( $this->diag_dir . '/*.jsonl' ) as $file ) {
			foreach ( array_filter( explode( "\n", (string) file_get_contents( $file ) ) ) as $line ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- test read.
				$decoded = json_decode( $line, true );
				$this->assertIsArray( $decoded, 'Every recorded line must be valid JSON: ' . $line );
				$events[] = $decoded;
			}
		}
		return $events;
	}

	/**
	 * Build a backup task object for backup_site().
	 *
	 * @param int   $userid    Owner user id.
	 * @param array $overrides Field overrides.
	 *
	 * @return object Task.
	 */
	private function make_task( $userid, $overrides = array() ) {
		return (object) array_merge(
			array(
				'id'                             => 1,
				'userid'                         => $userid,
				'type'                           => 'full',
				'exclude'                        => '',
				'excludebackup'                  => 0,
				'excludecache'                   => 0,
				'excludenonwp'                   => 0,
				'excludezip'                     => 0,
				'filename'                       => '',
				'archiveFormat'                  => 'tar.gz',
				'zip_force_override'             => 0,
				'loadFilesBeforeZip'             => 1,
				'maximumFileDescriptorsOverride' => 0,
				'maximumFileDescriptorsAuto'     => 1,
				'maximumFileDescriptors'         => 150,
				'last_run'                       => time() - 100,
				'subfolder'                      => 'backups',
			),
			$overrides
		);
	}

	/**
	 * Mock per-action child responses.
	 *
	 * @param array $responses Map of action => response array.
	 *
	 * @return void
	 */
	private function mock_child_actions( $responses ) {
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what ) use ( $responses ) {
				unset( $website );
				return isset( $responses[ $what ] ) ? $responses[ $what ] : $result;
			},
			10,
			3
		);
	}

	/**
	 * A fired poll seam hook lands as one decodable JSONL event carrying
	 * the full payload, the poll duration, and the run identity.
	 *
	 * @return void
	 */
	public function test_recorder_writes_jsonl_event_when_enabled() {
		$this->enable_recorder();

		$payload = array(
			'task_id'   => 'bk123',
			'phase'     => 'dumping_db',
			'sub_state' => array( 'db_cursor' => array( 'table' => 'wp_posts' ) ),
		);
		do_action(
			'mainwp_backup_diag_poll',
			$payload,
			0.123,
			array(
				'op'      => 'run',
				'site_id' => 7,
				'task_id' => 42,
			)
		);

		$events = $this->recorded_events();
		$this->assertCount( 1, $events );
		$event = $events[0];

		$this->assertSame( 'poll', $event['kind'] );
		$this->assertSame( 7, $event['site_id'] );
		$this->assertSame( 42, $event['task_id'] );
		$this->assertSame( '42-7-bk123', $event['run_key'] );
		$this->assertIsFloat( $event['ts'] );
		$this->assertSame( 'dumping_db', $event['payload']['phase'] );
		$this->assertSame( array( 'db_cursor' => array( 'table' => 'wp_posts' ) ), $event['payload']['sub_state'], 'The full sub_state must be recorded, not the flattened projection.' );
		$this->assertSame( 0.123, $event['payload']['poll_duration_s'] );
		$this->assertSame( 'run', $event['payload']['op'] );

		$files = (array) glob( $this->diag_dir . '/*.jsonl' );
		$this->assertCount( 1, $files );
		$this->assertSame( $this->event_filename( '42-7-bk123' ), basename( $files[0] ) );
		$this->assertNotSame( '42-7-bk123.jsonl', basename( $files[0] ) );
	}

	/**
	 * With the option off (the default) init() registers nothing and
	 * firing the seams writes nothing: a literal zero-overhead off state.
	 *
	 * @return void
	 */
	public function test_recorder_registers_nothing_when_disabled() {
		MainWP_Backup_Diagnostics::instance()->init();

		foreach ( array(
			'mainwp_backup_diag_poll',
			'mainwp_backup_diag_restore_poll',
			'mainwp_backup_diag_restore_transfer',
			'mainwp_backup_diag_preflight',
			'mainwp_backup_diag_slot',
		) as $hook ) {
			$this->assertFalse( has_action( $hook ), "No callback may register on {$hook} while disabled." );
		}

		do_action(
			'mainwp_backup_diag_poll',
			array( 'phase' => 'dumping_db' ),
			0.1,
			array(
				'op'      => 'run',
				'site_id' => 1,
				'task_id' => 1,
			)
		);
		do_action( 'mainwp_backup_diag_slot', 'acquired', 'tok', 1, 2 );

		$this->assertSame( array(), (array) glob( $this->diag_dir . '/*.jsonl' ), 'A disabled recorder must write nothing.' );
	}

	/**
	 * The task-create slice fires the poll seam with the create response
	 * and a measured duration.
	 *
	 * @return void
	 */
	public function test_backup_create_seam_fires_with_response_and_duration() {
		$captured = array();
		add_action(
			'mainwp_backup_diag_poll',
			function ( $payload, $duration, $context ) use ( &$captured ) {
				$captured[] = array(
					'payload'  => $payload,
					'duration' => $duration,
					'context'  => $context,
				);
			},
			10,
			3
		);

		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$task    = $this->make_task( $website->userid );

		$this->mock_child_actions(
			array(
				'backup_preflight'   => array(
					'disk_free'  => 10 * GB_IN_BYTES,
					'task_spine' => 1,
				),
				'backup_task_create' => array(
					'task_id' => 'bk-diag-create',
					'phase'   => 'queued',
				),
			)
		);

		$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
		$this->assertFalse( $result, 'Precondition: a spine create slice queues and yields.' );

		$creates = array_filter( $captured, fn( $event ) => 'create' === $event['context']['op'] );
		$this->assertCount( 1, $creates );
		$create = array_shift( $creates );
		$this->assertSame( 'bk-diag-create', $create['payload']['task_id'] );
		$this->assertSame( $site_id, $create['context']['site_id'] );
		$this->assertSame( 1, $create['context']['task_id'] );
		$this->assertIsFloat( $create['duration'] );
		$this->assertGreaterThanOrEqual( 0, $create['duration'] );
	}

	/**
	 * The backup_task_run poll fires the seam with the untouched full
	 * child payload - sub_state and timing fields included - which the
	 * progress projection otherwise discards.
	 *
	 * @return void
	 */
	public function test_backup_poll_seam_fires_with_full_payload() {
		$captured = array();
		add_action(
			'mainwp_backup_diag_poll',
			function ( $payload, $duration, $context ) use ( &$captured ) {
				$captured[] = array(
					'payload'  => $payload,
					'duration' => $duration,
					'context'  => $context,
				);
			},
			10,
			3
		);

		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$task    = $this->make_task( $website->userid );

		MainWP_DB_Backup::instance()->add_backup_task_progress( $task->id, $site_id, array() );
		MainWP_DB_Backup::instance()->update_backup_task_progress(
			$task->id,
			$site_id,
			array(
				'dtsFetched'           => time(),
				'child_backup_task_id' => 'bk-diag-run',
				'attempts'             => 0,
			)
		);

		$child_payload = array(
			'task_id'          => 'bk-diag-run',
			'phase'            => 'dumping_db',
			'type'             => 'full',
			'sub_state'        => array(
				'db_cursor' => array(
					'table' => 'wp_posts',
					'index' => 3,
				),
			),
			'started_at'       => '2026-07-02 10:00:00',
			'last_progress_at' => '2026-07-02 10:05:00',
			'resume_count'     => 2,
			'progress'         => array(
				'bytes_done'   => 1024,
				'bytes_total'  => 4096,
				'current_file' => 'wp_posts',
			),
		);
		$this->mock_child_actions( array( 'backup_task_run' => $child_payload ) );

		$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
		$this->assertFalse( $result, 'Precondition: a mid-flight poll yields for the next cycle.' );

		$runs = array_filter( $captured, fn( $event ) => 'run' === $event['context']['op'] );
		$this->assertCount( 1, $runs );
		$run = array_shift( $runs );
		$this->assertSame( $child_payload, $run['payload'], 'The seam must carry the untouched full child payload.' );
		$this->assertSame( $site_id, $run['context']['site_id'] );
		$this->assertIsFloat( $run['duration'] );
	}

	/**
	 * Each restore_task_run poll in phase_running fires the restore seam,
	 * including the non-terminal observations the orchestrator discards.
	 *
	 * @return void
	 */
	public function test_restore_poll_seam_fires_for_non_terminal_phase() {
		$captured = array();
		add_action(
			'mainwp_backup_diag_restore_poll',
			function ( $job_id, $site_id, $payload, $duration ) use ( &$captured ) {
				$captured[] = array(
					'job_id'   => $job_id,
					'site_id'  => $site_id,
					'payload'  => $payload,
					'duration' => $duration,
				);
			},
			10,
			4
		);

		$site_id = $this->create_test_site();

		$responses = array(
			array(
				'task_id'   => 'restore-diag-1',
				'phase'     => 'restoring_files',
				'sub_state' => array( 'progress' => array( 'bytes_done' => 512 ) ),
			),
			array(
				'task_id'   => 'restore-diag-1',
				'phase'     => 'completed',
				'sub_state' => array( 'verification' => array( 'row_counts_ok' => true ) ),
			),
		);
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what ) use ( &$responses ) {
				unset( $website );
				if ( 'restore_task_run' === $what ) {
					return array_shift( $responses );
				}
				return $result;
			},
			10,
			3
		);

		$store  = MainWP_Backup_Restore_Job_Store::instance();
		$job_id = $store->create_job(
			$site_id,
			5,
			array(
				'restore_id' => 'diagrestore',
				'sub_type'   => 'db',
				'plan'       => array(),
				'options'    => array(
					'pre_snapshot'  => false,
					'file_rollback' => false,
				),
				'transfer'   => array(),
			)
		);
		$store->set_status( $job_id, MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, array( 'child_task_id' => 'restore-diag-1' ) );

		MainWP_Backup_Restore_Orchestrator::instance()->run_job( $store->get_job( $job_id ), microtime( true ) + 30 );

		$this->assertCount( 2, $captured, 'Every poll in the slice must fire the seam, non-terminal ones included.' );
		$this->assertSame( 'restoring_files', $captured[0]['payload']['phase'] );
		$this->assertSame( $job_id, $captured[0]['job_id'] );
		$this->assertSame( $site_id, $captured[0]['site_id'] );
		$this->assertIsFloat( $captured[0]['duration'] );
		$this->assertSame( 'completed', $captured[1]['payload']['phase'] );
	}

	/**
	 * A successful preflight negotiation fires the preflight seam with the
	 * child's readiness report.
	 *
	 * @return void
	 */
	public function test_preflight_seam_fires_with_child_report() {
		$captured = array();
		add_action(
			'mainwp_backup_diag_preflight',
			function ( $site_id, $task_id, $preflight, $status ) use ( &$captured ) {
				$captured[] = array(
					'site_id'   => $site_id,
					'task_id'   => $task_id,
					'preflight' => $preflight,
					'status'    => $status,
				);
			},
			10,
			4
		);

		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );

		$report = array(
			'disk_free'       => 10 * GB_IN_BYTES,
			'estimated_bytes' => 100 * MB_IN_BYTES,
			'file_count'      => 1200,
			'task_spine'      => 1,
		);
		$this->mock_child_actions( array( 'backup_preflight' => $report ) );

		$ext = 'tar.gz';
		MainWP_Backup_Monitor::negotiate_backup_preflight( $website, $ext, null );

		$this->assertCount( 1, $captured );
		$this->assertSame( $site_id, $captured[0]['site_id'] );
		$this->assertSame( 'ok', $captured[0]['status'] );
		$this->assertSame( $report, $captured[0]['preflight'], 'The seam must carry the untouched preflight report.' );
	}

	/**
	 * Every restore chunk send fires the transfer seam with the outcome
	 * classification, making adaptive chunk shrinking visible.
	 *
	 * @return void
	 */
	public function test_restore_transfer_seam_fires_per_chunk() {
		$captured = array();
		add_action(
			'mainwp_backup_diag_restore_transfer',
			function ( $child_task_id, $file, $cursor, $result, $chunk_len, $duration ) use ( &$captured ) {
				$captured[] = array(
					'child_task_id' => $child_task_id,
					'file'          => $file,
					'cursor'        => $cursor,
					'result'        => $result,
					'chunk_len'     => $chunk_len,
					'duration'      => $duration,
				);
			},
			10,
			6
		);

		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );

		$staged = tempnam( sys_get_temp_dir(), 'mainwp-diag-chunk' );
		file_put_contents( $staged, 'artifact-bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		$this->mock_child_actions(
			array(
				'restore_artifact_chunk' => array( 'bytes_received' => strlen( 'artifact-bytes' ) ),
			)
		);

		$plan   = array(
			array(
				'role'        => 'db',
				'file'        => 'db.zip',
				'size'        => strlen( 'artifact-bytes' ),
				'sha256'      => hash( 'sha256', 'artifact-bytes' ),
				'staged_path' => $staged,
				'adopt_only'  => false,
			),
		);
		$cursor = array();

		$outcome = \MainWP\Dashboard\MainWP_Backup_Restore_Transfer_Driver::instance()->transfer_artifacts( $website, 'restore-diag-2', $plan, $cursor, microtime( true ) + 30 );
		unlink( $staged );

		$this->assertTrue( $outcome['done'], 'Precondition: the single staged chunk transfers completely.' );
		$this->assertCount( 1, $captured );
		$this->assertSame( 'restore-diag-2', $captured[0]['child_task_id'] );
		$this->assertSame( 'db.zip', $captured[0]['file'] );
		$this->assertSame( 'ok', $captured[0]['result'] );
		$this->assertSame( strlen( 'artifact-bytes' ), $captured[0]['chunk_len'] );
		$this->assertIsFloat( $captured[0]['duration'] );
	}

	/**
	 * A transfer chunk that fails in transport records its error string on
	 * the restore_transfer event, so a bundle can distinguish a timeout
	 * from a reset from a proxy rejection.
	 *
	 * @return void
	 */
	public function test_restore_transfer_event_carries_error_string() {
		$this->enable_recorder();

		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );

		$staged = tempnam( sys_get_temp_dir(), 'mainwp-diag-err' );
		file_put_contents( $staged, 'artifact-bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		$threw = false;
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what ) use ( &$threw ) {
				unset( $website );
				if ( 'restore_artifact_chunk' !== $what ) {
					return $result;
				}
				if ( ! $threw ) {
					$threw = true;
					throw new \RuntimeException( 'The connection died mid-send.' );
				}
				return array( 'bytes_received' => strlen( 'artifact-bytes' ) );
			},
			10,
			3
		);

		$plan   = array(
			array(
				'role'        => 'db',
				'file'        => 'db.zip',
				'size'        => strlen( 'artifact-bytes' ),
				'sha256'      => hash( 'sha256', 'artifact-bytes' ),
				'staged_path' => $staged,
				'adopt_only'  => false,
			),
		);
		$cursor = array();

		$outcome = \MainWP\Dashboard\MainWP_Backup_Restore_Transfer_Driver::instance()->transfer_artifacts( $website, 'restore-diag-err', $plan, $cursor, microtime( true ) + 30 );
		unlink( $staged );

		$this->assertTrue( $outcome['done'], 'Precondition: the transfer recovers after the single transport failure.' );

		$events = array_values( array_filter( $this->recorded_events(), fn( $event ) => 'restore_transfer' === $event['kind'] ) );
		$this->assertCount( 2, $events );
		$this->assertSame( 'shrink', $events[0]['payload']['result'] );
		$this->assertSame( 'The connection died mid-send.', $events[0]['payload']['error'], 'The transport error string must reach the event.' );
		$this->assertSame( 'ok', $events[1]['payload']['result'] );
		$this->assertSame( '', $events[1]['payload']['error'], 'A clean send carries no error string.' );
	}

	/**
	 * A retryable restore failure records a restore_retry event carrying
	 * the attempt number, the backoff target, and whether progress reset
	 * the budget - the attempt boundaries a bundle otherwise has to infer
	 * from stream gaps.
	 *
	 * @return void
	 */
	public function test_restore_retry_event_recorded_on_failed_retryable() {
		$this->enable_recorder();

		$site_id = $this->create_test_site();

		$staged = tempnam( sys_get_temp_dir(), 'mainwp-diag-retry' );
		file_put_contents( $staged, 'artifact-bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		$this->mock_child_actions(
			array(
				'restore_artifact_chunk' => array( 'error' => 'The site rejected the chunk.' ),
			)
		);

		$store  = MainWP_Backup_Restore_Job_Store::instance();
		$job_id = $store->create_job(
			$site_id,
			5,
			array(
				'restore_id' => 'diagretry',
				'sub_type'   => 'db',
				'plan'       => array(
					array(
						'role'        => 'db',
						'file'        => 'db.zip',
						'size'        => strlen( 'artifact-bytes' ),
						'sha256'      => hash( 'sha256', 'artifact-bytes' ),
						'staged_path' => $staged,
						'adopt_only'  => false,
					),
				),
				'options'    => array(
					'pre_snapshot'  => false,
					'file_rollback' => false,
				),
				'transfer'   => array(),
			)
		);
		$store->set_status( $job_id, MainWP_Backup_Restore_Job_Store::STATUS_TRANSFERRING, array( 'child_task_id' => 'restore-diag-3' ) );

		MainWP_Backup_Restore_Orchestrator::instance()->run_job( $store->get_job( $job_id ), microtime( true ) + 30 );
		unlink( $staged );

		$job = $store->get_job( $job_id );
		$this->assertSame( MainWP_Backup_Restore_Job_Store::STATUS_FAILED_RETRYABLE, $job->status, 'Precondition: the rejected chunk fails the job retryably.' );

		$events = array_values( array_filter( $this->recorded_events(), fn( $event ) => 'restore_retry' === $event['kind'] ) );
		$this->assertCount( 1, $events );
		$this->assertSame( $site_id, $events[0]['site_id'] );
		$this->assertSame( 'restore-' . $job_id, $events[0]['run_key'] );
		$this->assertSame( 1, $events[0]['payload']['attempt'] );
		$this->assertSame( '', $events[0]['payload']['error_code'] );
		$this->assertGreaterThan( time(), $events[0]['payload']['next_retry_at'] );
		$this->assertFalse( $events[0]['payload']['progress_reset'] );
	}

	/**
	 * A refused run claim leaves a run_reinit event: the trace the
	 * 2026-07-02 bundle lacked, where a duplicate initiate reopened a
	 * closed run and showed up only as a second preflight.
	 *
	 * @return void
	 */
	public function test_run_reinit_event_recorded() {
		$this->enable_recorder();

		$site_id = $this->create_test_site();
		$user    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$task    = MainWP_DB_Backup::instance()->add_backup_task( $user, 'Reinit diag test task', 'daily', 'full', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'tar.gz', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task, 'Precondition: the task row must insert.' );
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			(int) $task->id,
			array(
				'last_run'  => time() - 30,
				'completed' => time() - 4,
			)
		);
		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );

		$result = MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, true, 15 );
		$this->assertFalse( $result, 'Precondition: the settle window refuses the claim.' );

		$events = array_values( array_filter( $this->recorded_events(), fn( $event ) => 'run_reinit' === $event['kind'] ) );
		$this->assertCount( 1, $events );
		$this->assertSame( (int) $task->id, $events[0]['task_id'] );
		$this->assertSame( 'claim_refused', $events[0]['payload']['reason'] );
		$this->assertSame( (int) $task->last_run, $events[0]['payload']['last_run'] );
		$this->assertSame( (int) $task->completed, $events[0]['payload']['completed'] );
	}

	/**
	 * A refused create carries no child task id, so its events used to
	 * collapse into one shared task-site file across every run that ever
	 * hit the refusal. The run-open stamp scopes a create failure to its
	 * run the same way the child task id scopes a successful one.
	 *
	 * @return void
	 */
	public function test_diag_refused_create_gets_run_scoped_key() {
		$this->enable_recorder();

		$refusal = array( 'error' => 'Another backup process is running on this site.' );
		do_action(
			'mainwp_backup_diag_poll',
			$refusal,
			0.5,
			array(
				'op'      => 'create',
				'site_id' => 7,
				'task_id' => 42,
				'run'     => 1111,
			)
		);
		do_action(
			'mainwp_backup_diag_poll',
			$refusal,
			0.5,
			array(
				'op'      => 'create',
				'site_id' => 7,
				'task_id' => 42,
				'run'     => 2222,
			)
		);

		$events = $this->recorded_events();
		$this->assertCount( 2, $events );
		$keys = array_column( $events, 'run_key' );
		$this->assertContains( '42-7-1111', $keys, 'A refused create must land under its own run\'s key.' );
		$this->assertContains( '42-7-2222', $keys, 'A later run\'s refusal must not share the earlier run\'s key.' );
	}

	/**
	 * The losing driver of a create-window race leaves a run_reinit trace:
	 * before this event the bundle showed nothing at the moment a racing
	 * driver was absorbed, which is how a mid-flight run close went
	 * undiagnosed until the guardrail evidence was pieced together by hand.
	 *
	 * @return void
	 */
	public function test_diag_create_race_yield_event_recorded() {
		$this->enable_recorder();

		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$task    = $this->make_task( $website->userid );

		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what ) use ( $task, $site_id ) {
				unset( $website );
				if ( 'backup_preflight' === $what ) {
					return array(
						'time_limit_ok' => true,
						'task_spine'    => 1,
					);
				}
				if ( 'backup_task_create' === $what ) {
					MainWP_DB_Backup::instance()->update_backup_task_progress(
						(int) $task->id,
						$site_id,
						array( 'child_backup_task_id' => 'task-other-driver' )
					);
					return array(
						'error'      => 'Another backup process is running on this site.',
						'error_code' => MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING,
					);
				}
				return $result;
			},
			10,
			3
		);

		$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
		$this->assertFalse( $result, 'Precondition: the losing driver must yield.' );

		$events = array_values( array_filter( $this->recorded_events(), fn( $event ) => 'run_reinit' === $event['kind'] && 'create_race_yield' === $event['payload']['reason'] ) );
		$this->assertCount( 1, $events );
		$this->assertSame( (int) $task->id, $events[0]['task_id'] );
		$this->assertSame( (int) $task->last_run, $events[0]['payload']['last_run'] );
	}

	/**
	 * A close refused because a create for the current cycle is still in
	 * flight leaves a run_reinit trace, so a bundle shows why a run's close
	 * arrived later than its last site completion.
	 *
	 * @return void
	 */
	public function test_diag_close_refused_live_child_event_recorded() {
		$this->enable_recorder();

		$site_id = $this->create_test_site();
		$user    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$task    = MainWP_DB_Backup::instance()->add_backup_task( $user, 'Close refusal diag task', 'daily', 'full', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'tar.gz', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task, 'Precondition: the task row must insert.' );
		MainWP_DB_Backup::instance()->update_backup_task_with_values( (int) $task->id, array( 'last_run' => time() - 100 ) );
		MainWP_DB_Backup::instance()->update_completed_sites( $task->id, array( $site_id => true ) );
		MainWP_DB_Backup::instance()->add_backup_task_progress( (int) $task->id, $site_id, array() );
		MainWP_DB_Backup::instance()->update_backup_task_progress(
			(int) $task->id,
			$site_id,
			array(
				'dtsFetched'           => time(),
				'child_backup_task_id' => '__creating_diagtoken0001',
			)
		);

		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, false );

		$events = array_values( array_filter( $this->recorded_events(), fn( $event ) => 'run_reinit' === $event['kind'] && 'close_refused_live_child' === $event['payload']['reason'] ) );
		$this->assertCount( 1, $events );
		$this->assertSame( (int) $task->id, $events[0]['task_id'] );
	}

	/**
	 * A site marked complete after a terminal exception leaves a site_mark
	 * event naming the exception: the 01:24:26 close that lost a snapshot
	 * was untraceable exactly because no event recorded who marked the
	 * site or why.
	 *
	 * @return void
	 */
	public function test_diag_site_mark_error_event_names_the_exception() {
		$this->enable_recorder();

		$site_id = $this->create_test_site();
		$user    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$task    = MainWP_DB_Backup::instance()->add_backup_task( $user, 'Site mark diag task', 'daily', 'full', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'tar.gz', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task, 'Precondition: the task row must insert.' );
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			(int) $task->id,
			array(
				'last_run'  => time() - 100,
				'completed' => time() - 500,
			)
		);
		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );

		$this->mock_child_actions(
			array(
				'backup_preflight'   => array(
					'zip_support' => true,
					'task_spine'  => 1,
				),
				'backup_task_create' => array( 'error' => 'Could not write backup data.' ),
			)
		);

		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, false );

		$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertStringContainsString( (string) $site_id, (string) $fresh->completed_sites, 'Precondition: the terminal failure must mark the site.' );

		$events = array_values( array_filter( $this->recorded_events(), fn( $event ) => 'site_mark' === $event['kind'] ) );
		$this->assertCount( 1, $events );
		$this->assertSame( (int) $task->id, $events[0]['task_id'] );
		$this->assertSame( $site_id, $events[0]['site_id'] );
		$this->assertSame( 'error', $events[0]['payload']['outcome'] );
		$this->assertStringContainsString( 'MainWP_Exception', $events[0]['payload']['detail'], 'The mark event must name the exception class.' );
		$this->assertStringContainsString( 'Could not write backup data', $events[0]['payload']['detail'], 'The mark event must carry the exception message.' );
	}

	/**
	 * A run close leaves a run_close event naming its trigger: the close
	 * path was invisible in every bundle, which is why the driver that
	 * closed a run mid-record could not be identified.
	 *
	 * @return void
	 */
	public function test_diag_run_close_event_names_the_trigger() {
		$this->enable_recorder();

		$site_id = $this->create_test_site();
		$user    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$task    = MainWP_DB_Backup::instance()->add_backup_task( $user, 'Run close diag task', 'daily', 'full', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'tar.gz', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task, 'Precondition: the task row must insert.' );
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			(int) $task->id,
			array(
				'last_run'  => time() - 100,
				'completed' => time() - 500,
			)
		);
		MainWP_DB_Backup::instance()->update_completed_sites( $task->id, array( $site_id => true ) );
		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );

		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, false );

		$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertGreaterThan( (int) $fresh->last_run, (int) $fresh->completed, 'Precondition: the all-sites-complete run must close.' );

		$events = array_values( array_filter( $this->recorded_events(), fn( $event ) => 'run_close' === $event['kind'] ) );
		$this->assertCount( 1, $events );
		$this->assertSame( (int) $task->id, $events[0]['task_id'] );
		$this->assertSame( 'all_sites_complete', $events[0]['payload']['trigger'] );
		$this->assertSame( (int) $task->last_run, $events[0]['payload']['last_run'] );
		$this->assertSame( 1, $events[0]['payload']['sites_total'] );
	}

	/**
	 * Slot acquisition, refusal, and release each leave a diagnostics
	 * event, so slot exhaustion is no longer traceless.
	 *
	 * @return void
	 */
	public function test_slot_events_fire_on_acquire_refuse_release() {
		$this->enable_recorder();

		$token   = MainWP_Backup_Monitor::acquire_backup_slot( 1 );
		$refused = MainWP_Backup_Monitor::acquire_backup_slot( 1 );
		MainWP_Backup_Monitor::release_backup_slot( $token );

		$this->assertIsString( $token );
		$this->assertFalse( $refused );

		$events = array_values( array_filter( $this->recorded_events(), fn( $event ) => 'slot' === $event['kind'] ) );
		$this->assertCount( 3, $events );

		$this->assertSame( 'acquired', $events[0]['payload']['event'] );
		$this->assertSame( 1, $events[0]['payload']['active'] );
		$this->assertSame( 1, $events[0]['payload']['max_concurrent'] );

		$this->assertSame( 'refused', $events[1]['payload']['event'] );
		$this->assertSame( 1, $events[1]['payload']['active'] );

		$this->assertSame( 'released', $events[2]['payload']['event'] );
	}

	/**
	 * Terminal backup outcomes and backup-related log warnings are
	 * recorded through the existing pipeline hooks; unrelated dashboard
	 * log noise is not.
	 *
	 * @return void
	 */
	public function test_terminal_and_guardrail_events_recorded() {
		$this->enable_recorder();

		do_action(
			'mainwp_native_backup_completed',
			7,
			'bk-diag-9',
			true,
			array(
				'type' => 'full',
				'file' => 'full.tar.gz',
				'size' => 1024,
			)
		);
		do_action( 'mainwp_log_warning', 'Low disk space on dashboard server: 100 MB available. Large backups may fail.' );
		do_action( 'mainwp_log_warning', 'Client email could not be sent.' );

		$events = $this->recorded_events();

		$terminals = array_values( array_filter( $events, fn( $event ) => 'terminal' === $event['kind'] ) );
		$this->assertCount( 1, $terminals );
		$this->assertSame( 7, $terminals[0]['site_id'] );
		$this->assertTrue( $terminals[0]['payload']['success'] );
		$this->assertSame( 'bk-diag-9', $terminals[0]['payload']['child_task_id'] );
		$this->assertSame( 'full.tar.gz', $terminals[0]['payload']['meta']['file'] );

		$guardrails = array_values( array_filter( $events, fn( $event ) => 'guardrail' === $event['kind'] ) );
		$this->assertCount( 1, $guardrails, 'Only backup-related log messages may be recorded.' );
		$this->assertStringContainsString( 'Low disk space', $guardrails[0]['payload']['message'] );
	}

	/**
	 * The export bundle is a zip carrying the environment summary, the
	 * bounded table dumps, and the raw event files.
	 *
	 * @return void
	 */
	public function test_export_bundle_contains_env_tables_and_events() {
		if ( ! class_exists( \ZipArchive::class ) ) {
			$this->markTestSkipped( 'ZipArchive is unavailable in this environment.' );
		}

		$this->enable_recorder();

		$site_id = $this->create_test_site();
		$user    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$task    = MainWP_DB_Backup::instance()->add_backup_task( $user, 'Diagnostics export test task', 'daily', 'full', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'tar.gz', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task, 'Precondition: the task row must insert.' );

		MainWP_Backup_Diagnostics::instance()->record( '1-' . $site_id . '-bkx', 'poll', $site_id, 1, array( 'phase' => 'dumping_db' ) );

		$bundle = MainWP_Backup_Diagnostics::instance()->export_bundle();
		$this->assertNotSame( '', $bundle );
		$this->assertFileExists( $bundle );
		$this->assertStringEndsWith( '.zip', $bundle );

		$zip = new \ZipArchive();
		$this->assertTrue( $zip->open( $bundle ) );

		$env = json_decode( (string) $zip->getFromName( 'env.json' ), true );
		$this->assertIsArray( $env );
		$this->assertSame( PHP_VERSION, $env['php_version'] );
		$this->assertFalse( $env['truncated'] );

		$tasks = json_decode( (string) $zip->getFromName( 'tasks.json' ), true );
		$this->assertIsArray( $tasks );
		$this->assertContains( 'Diagnostics export test task', array_column( $tasks, 'name' ) );

		$events = (string) $zip->getFromName( 'events/' . $this->event_filename( '1-' . $site_id . '-bkx' ) );
		$this->assertStringContainsString( 'dumping_db', $events );
		$zip->close();
	}

	/**
	 * The bundle names each site's deployed child plugin version and its
	 * negotiated capability flags: confirming what a site actually runs
	 * cost an hour of behavioral inference last round, and the sync data
	 * already holds it.
	 *
	 * @return void
	 */
	public function test_export_bundle_sites_section_carries_child_version_and_capabilities() {
		if ( ! class_exists( \ZipArchive::class ) ) {
			$this->markTestSkipped( 'ZipArchive is unavailable in this environment.' );
		}

		$this->enable_recorder();

		$site_id = $this->create_test_site();
		MainWP_DB::instance()->update_website_sync_values( $site_id, array( 'version' => '6.1.9-test' ) );
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		MainWP_DB::instance()->update_website_option( $website, 'task_spine_support', '1' );
		MainWP_DB::instance()->update_website_option( $website, 'download_token_support', '' );

		MainWP_Backup_Diagnostics::instance()->record( 'sites-check', 'poll', $site_id, 1, array( 'phase' => 'queued' ) );

		$bundle = MainWP_Backup_Diagnostics::instance()->export_bundle();
		$this->assertNotSame( '', $bundle );

		$zip = new \ZipArchive();
		$this->assertTrue( $zip->open( $bundle ) );
		$sites = json_decode( (string) $zip->getFromName( 'sites.json' ), true );
		$zip->close();

		$this->assertIsArray( $sites, 'The bundle must carry a sites section.' );
		$rows = array_values(
			array_filter(
				$sites,
				function ( $row ) use ( $site_id ) {
					return isset( $row['id'] ) && (int) $row['id'] === $site_id;
				}
			)
		);
		$this->assertCount( 1, $rows, 'The test site must appear exactly once in the sites section.' );
		$this->assertSame( '6.1.9-test', $rows[0]['child_version'], 'The deployed child plugin version must ride along per site.' );
		$this->assertSame( '1', $rows[0]['capabilities']['task_spine_support'], 'A negotiated capability flag must be readable per site.' );
		$this->assertArrayHasKey( 'download_token_support', $rows[0]['capabilities'], 'Cleared flags must still be listed so a downgrade is visible.' );
	}

	/**
	 * The export endpoint is gated on the global execute_backups
	 * capability, which says nothing about which sites the caller may
	 * reach. A site-scoped user must get a bundle carrying only their own
	 * sites: the per-site table rows, the per-site event files, and the
	 * shared _global stream filtered line by line.
	 *
	 * @return void
	 */
	public function test_export_bundle_scopes_rows_and_events_to_allowed_sites() {
		if ( ! class_exists( \ZipArchive::class ) ) {
			$this->markTestSkipped( 'ZipArchive is unavailable in this environment.' );
		}

		$this->enable_recorder();

		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );

		$site_allowed = $this->create_test_site();
		$site_denied  = $this->create_test_site();

		$task_allowed = MainWP_DB_Backup::instance()->add_backup_task( $user, 'Scoped allowed task', 'daily', 'full', '', (string) $site_allowed, '', '', '', 0, 0, 0, 0, 0, 'tar.gz', 0, 1, 150, 0, 0 );
		$task_denied  = MainWP_DB_Backup::instance()->add_backup_task( $user, 'Scoped denied task', 'daily', 'full', '', (string) $site_denied, '', '', '', 0, 0, 0, 0, 0, 'tar.gz', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task_allowed, 'Precondition: the allowed task row must insert.' );
		$this->assertNotFalse( $task_denied, 'Precondition: the denied task row must insert.' );

		MainWP_DB_Backup::instance()->add_backup_task_progress( (int) $task_allowed->id, $site_allowed, array() );
		MainWP_DB_Backup::instance()->add_backup_task_progress( (int) $task_denied->id, $site_denied, array() );

		MainWP_Backup_Diagnostics::instance()->record( 'terminal-' . $site_allowed, 'terminal', $site_allowed, 0, array( 'success' => true ) );
		MainWP_Backup_Diagnostics::instance()->record( 'terminal-' . $site_denied, 'terminal', $site_denied, 0, array( 'success' => true ) );
		MainWP_Backup_Diagnostics::instance()->record( '_global', 'site_mark', $site_allowed, (int) $task_allowed->id, array( 'outcome' => 'allowed-mark' ) );
		MainWP_Backup_Diagnostics::instance()->record( '_global', 'site_mark', $site_denied, (int) $task_denied->id, array( 'outcome' => 'denied-mark' ) );

		$deny = function ( $allowed, $cap_type, $cap ) use ( $site_denied ) {
			if ( 'site' === $cap_type && (int) $cap === (int) $site_denied ) {
				return false;
			}
			return $allowed;
		};
		add_filter( 'mainwp_currentusercan', $deny, 10, 3 );

		$bundle = MainWP_Backup_Diagnostics::instance()->export_bundle();

		remove_filter( 'mainwp_currentusercan', $deny, 10 );

		$this->assertNotSame( '', $bundle );

		$zip = new \ZipArchive();
		$this->assertTrue( $zip->open( $bundle ) );
		$sites    = json_decode( (string) $zip->getFromName( 'sites.json' ), true );
		$tasks    = json_decode( (string) $zip->getFromName( 'tasks.json' ), true );
		$progress = json_decode( (string) $zip->getFromName( 'progress.json' ), true );
		$global   = (string) $zip->getFromName( 'events/' . $this->event_filename( '_global' ) );
		$kept     = $zip->locateName( 'events/' . $this->event_filename( 'terminal-' . $site_allowed ) );
		$dropped  = $zip->locateName( 'events/' . $this->event_filename( 'terminal-' . $site_denied ) );
		$zip->close();

		$site_ids = array_map( 'intval', array_column( (array) $sites, 'id' ) );
		$this->assertContains( $site_allowed, $site_ids, 'The reachable site must stay in the bundle.' );
		$this->assertNotContains( $site_denied, $site_ids, 'A site the caller cannot reach must not be exported.' );

		$task_names = array_column( (array) $tasks, 'name' );
		$this->assertContains( 'Scoped allowed task', $task_names );
		$this->assertNotContains( 'Scoped denied task', $task_names, 'A task over an out-of-scope site must not be exported.' );

		$progress_sites = array_map( 'intval', array_column( (array) $progress, 'wp_id' ) );
		$this->assertContains( $site_allowed, $progress_sites );
		$this->assertNotContains( $site_denied, $progress_sites, 'Progress rows must be narrowed to the allowed sites.' );

		$this->assertNotFalse( $kept, 'The allowed site\'s own event file must ride along.' );
		$this->assertFalse( $dropped, 'An out-of-scope site\'s event file must not be exported.' );

		$this->assertStringContainsString( 'allowed-mark', $global, 'The shared stream keeps the caller\'s own lines.' );
		$this->assertStringNotContainsString( 'denied-mark', $global, 'The shared stream must drop out-of-scope lines.' );
	}

	/**
	 * With no recorded events there is nothing worth exporting.
	 *
	 * @return void
	 */
	public function test_export_bundle_returns_empty_without_events() {
		$this->enable_recorder();
		$this->assertSame( '', MainWP_Backup_Diagnostics::instance()->export_bundle() );
	}

	/**
	 * Table dumps respect the row cap and flag the truncation, bounding
	 * the bundle on a large fleet.
	 *
	 * @return void
	 */
	public function test_export_bundle_row_cap_truncates() {
		if ( ! class_exists( \ZipArchive::class ) ) {
			$this->markTestSkipped( 'ZipArchive is unavailable in this environment.' );
		}

		$this->enable_recorder();
		add_filter(
			'mainwp_backup_diagnostics_export_row_cap',
			function () {
				return 1;
			}
		);

		$site_a = $this->create_test_site();
		$site_b = $this->create_test_site();
		MainWP_DB_Backup::instance()->add_backup_task_progress( 11, $site_a, array() );
		MainWP_DB_Backup::instance()->add_backup_task_progress( 12, $site_b, array() );

		MainWP_Backup_Diagnostics::instance()->record( 'capcheck', 'poll', $site_a, 11, array( 'phase' => 'queued' ) );

		$bundle = MainWP_Backup_Diagnostics::instance()->export_bundle();
		$this->assertNotSame( '', $bundle );

		$zip = new \ZipArchive();
		$this->assertTrue( $zip->open( $bundle ) );
		$progress = json_decode( (string) $zip->getFromName( 'progress.json' ), true );
		$env      = json_decode( (string) $zip->getFromName( 'env.json' ), true );
		$zip->close();

		$this->assertCount( 1, $progress, 'The row cap must bound every table dump.' );
		$this->assertTrue( $env['truncated'], 'Capped output must be flagged as truncated.' );
	}

	/**
	 * The retention prune removes files past the age limit and then
	 * deletes oldest-first until the directory fits the size cap.
	 *
	 * @return void
	 */
	public function test_retention_prune_removes_old_and_oversized() {
		$this->enable_recorder();

		$old    = $this->diag_dir . '/ancient.jsonl';
		$big_a  = $this->diag_dir . '/big-a.jsonl';
		$big_b  = $this->diag_dir . '/big-b.jsonl';
		$filler = str_repeat( 'x', 100 ) . "\n";
		file_put_contents( $old, $filler ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		file_put_contents( $big_a, $filler ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		file_put_contents( $big_b, $filler ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		touch( $old, time() - 20 * DAY_IN_SECONDS );
		touch( $big_a, time() - 2 * HOUR_IN_SECONDS );
		touch( $big_b, time() - 1 * HOUR_IN_SECONDS );

		add_filter(
			'mainwp_backup_diagnostics_cap_bytes',
			function () {
				return 150;
			}
		);

		MainWP_Backup_Diagnostics::instance()->prune();

		$this->assertFileDoesNotExist( $old, 'Files past the age limit must be pruned.' );
		$this->assertFileDoesNotExist( $big_a, 'The oldest file must go first when the directory exceeds the size cap.' );
		$this->assertFileExists( $big_b, 'The newest file must survive the size-cap prune.' );
	}

	/**
	 * Invoke the export AJAX handler with a valid nonce and return the
	 * decoded JSON response.
	 *
	 * @return array|null Decoded response.
	 */
	private function invoke_export_handler() {
		$nonce                = wp_create_nonce( 'mainwp_backup_diagnostics_export' );
		$_POST['security']    = $nonce;
		$_REQUEST['security'] = $nonce;

		$throw = static function () {
			return static function () {
				throw new \RuntimeException( 'wp_die' );
			};
		};
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $throw );

		$raw = '';
		ob_start();
		try {
			\MainWP\Dashboard\MainWP_Post_Backup_Handler::instance()->mainwp_backup_diagnostics_export();
		} catch ( \RuntimeException $e ) {
			// wp_send_json terminated the request as designed.
		} finally {
			$raw = ob_get_clean();
			remove_filter( 'wp_die_ajax_handler', $throw );
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}

		return json_decode( $raw, true );
	}

	/**
	 * The export endpoint hands back a signed mwpdl download URL for the
	 * built bundle, and refuses while diagnostics is disabled.
	 *
	 * @return void
	 */
	public function test_export_handler_returns_signed_download_url() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );

		$response = $this->invoke_export_handler();
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'error', $response, 'A disabled recorder must refuse the export.' );

		$this->enable_recorder();
		MainWP_Backup_Diagnostics::instance()->record( 'exporturl', 'poll', 1, 1, array( 'phase' => 'queued' ) );

		// The bundle must land inside the mainwp uploads tree for the mwpdl
		// download path to serve it, so the scratch-dir filter is lifted here.
		remove_filter( 'mainwp_backup_diagnostics_dir', array( $this, 'filter_diag_dir' ) );
		$real_dir = MainWP_Backup_Diagnostics::instance()->diagnostics_dir();
		copy( $this->diag_dir . '/' . $this->event_filename( 'exporturl' ), $real_dir . $this->event_filename( 'exporturl' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.

		$response = $this->invoke_export_handler();

		foreach ( (array) glob( $real_dir . '*' ) as $file ) {
			if ( is_file( $file ) && false === strpos( basename( $file ), '.htaccess' ) && 'index.php' !== basename( $file ) ) {
				unlink( $file );
			}
		}

		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'url', $response );
		$this->assertStringContainsString( 'mwpdl=', $response['url'] );
		$this->assertStringContainsString( 'sig=', $response['url'] );
	}

	/**
	 * A recorder that cannot write must swallow the failure: the seams
	 * stay silent and the calling pipeline continues unharmed.
	 *
	 * @return void
	 */
	public function test_recorder_never_throws_on_write_failure() {
		remove_filter( 'mainwp_backup_diagnostics_dir', array( $this, 'filter_diag_dir' ) );

		// A path under a regular file can never be created or written.
		$blocker = tempnam( sys_get_temp_dir(), 'mainwp-diag-block' );
		add_filter(
			'mainwp_backup_diagnostics_dir',
			function () use ( $blocker ) {
				return $blocker . '/nested';
			}
		);

		$this->enable_recorder();

		do_action(
			'mainwp_backup_diag_poll',
			array( 'phase' => 'dumping_db' ),
			0.1,
			array(
				'op'      => 'run',
				'site_id' => 1,
				'task_id' => 1,
			)
		);
		do_action( 'mainwp_backup_diag_slot', 'acquired', 'tok', 1, 2 );
		do_action( 'mainwp_backup_diag_restore_poll', 1, 1, array( 'phase' => 'restoring_files' ), 0.1 );

		unlink( $blocker );
		$this->assertTrue( true, 'Reaching this line proves no recorder failure escaped into the pipeline.' );
	}
}
