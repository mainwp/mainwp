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
use MainWP\Dashboard\MainWP_System;
use MainWP\Dashboard\MainWP_System_Handler;
use MainWP\Dashboard\MainWP_System_Utility;
use MainWP\Dashboard\MainWP_Post_Backup_Handler;

/**
 * Class MainWP_Backup_Diagnostics_Test
 */
class MainWP_Backup_Diagnostics_Test extends MainWP_Abilities_Test_Case {

	use Backup_Cancel_Transport_Guard;

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
				'files'  => array(),
				'scopes' => array(),
			)
		);
		$this->assertSame( '', $result );
		$this->assertFileDoesNotExist( $path );
	}

	/**
	 * Streaming preserves valid events and omits lines that cannot be redacted.
	 */
	public function test_json_fallback_matches_two_file_payload() {
		$files = array( $this->diag_dir . '/one.jsonl', $this->diag_dir . '/two.jsonl' );
		file_put_contents( $files[0], "{\"run_key\":\"1-2\"}\n\n0\n" );
		file_put_contents( $files[1], "{\"message\":\"quote \\\" slash /\"}\r\nlast line" );
		$files[] = $this->diag_dir . '/global.jsonl';
		file_put_contents( $files[2], "{\"kind\":\"first\"}\n\n0\nlast\n" );
		$sections                           = array(
			'env'   => array( 'version' => 'test' ),
			'tasks' => array(),
		);
		$events                             = array(
			'files'  => $files,
			'scopes' => array( $files[2] => array( array( 1 ), array() ) ),
		);
		$expected                           = $sections;
		$expected['events']                 = array();
		$expected['events']['one.jsonl']    = array( '{"run_key":"1-2"}' );
		$expected['events']['two.jsonl']    = array( wp_json_encode( array( 'message' => 'quote " slash /' ) ) );
		$expected['events']['global.jsonl'] = array( '{"kind":"first"}' );
		$writer                             = new \ReflectionMethod( MainWP_Backup_Diagnostics::class, 'write_json_bundle' );
		$path                               = $this->diag_dir . '/bundle.json';
		$this->assertSame( $path, $writer->invoke( MainWP_Backup_Diagnostics::instance(), $path, $sections, $events ) );
		$actual = file_get_contents( $path );
		$this->assertSame( wp_json_encode( $expected ), $actual );
		$this->assertSame( wp_json_encode( json_decode( wp_json_encode( $expected ), true ) ), $actual );
	}

	/**
	 * Event filtering must yield each redacted line without buffering the file.
	 */
	public function test_event_redaction_is_lazy_and_streams_to_disk() {
		$file = $this->diag_dir . '/stream.jsonl';
		$line = wp_json_encode( array( 'payload' => $this->restore_payload_with_secrets() ) );
		file_put_contents( $file, $line . "\ninvalid\n" . $line . "\n" );
		$reader = new \ReflectionMethod( MainWP_Backup_Diagnostics::class, 'filter_event_lines' );
		$lines  = $reader->invoke( MainWP_Backup_Diagnostics::instance(), $file, null, array() );
		$this->assertInstanceOf( \Generator::class, $lines );
		$this->assertCount( 2, iterator_to_array( $lines ) );
		$writer = new \ReflectionMethod( MainWP_Backup_Diagnostics::class, 'write_redacted_event_file' );
		$path   = $this->diag_dir . '/redacted-test';
		$writer->invoke( MainWP_Backup_Diagnostics::instance(), $file, $path, array( null, array() ) );
		$written = file( $path, FILE_IGNORE_NEW_LINES );
		$this->assertCount( 2, $written );
		foreach ( $written as $event ) {
			$this->assert_restore_event_redacted( $event );
		}
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
	 * Site transients can outlive the test transaction in an object cache.
	 *
	 * @var string[]
	 */
	private $download_keys = array();

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
		foreach ( $this->download_keys as $key ) {
			delete_site_transient( $key );
		}
		$this->download_keys = array();
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
			'mainwp_backup_diag_lane',
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
	 * Old child responses must not leave credentials in the recorder.
	 */
	public function test_restore_poll_redacts_manifest_and_identity_before_recording() {
		$this->enable_recorder();
		$site_id = $this->create_test_site();
		$payload = $this->restore_payload_with_secrets();
		add_filter(
			'mainwp_fetch_url_authed_pre',
			static function ( $pre, $website, $what ) use ( $payload ) {
				return 'restore_task_run' === $what ? $payload : $pre;
			},
			10,
			3
		);
		$store  = MainWP_Backup_Restore_Job_Store::instance();
		$job_id = $store->create_job(
			$site_id,
			5,
			array(
				'restore_id' => 'diag-redaction',
				'sub_type'   => 'db',
				'plan'       => array(),
				'options'    => array(
					'pre_snapshot'  => false,
					'file_rollback' => false,
				),
				'transfer'   => array(),
			)
		);
		$store->set_status( $job_id, MainWP_Backup_Restore_Job_Store::STATUS_RUNNING, array( 'child_task_id' => 'restore-redaction' ) );
		MainWP_Backup_Restore_Orchestrator::instance()->run_job( $store->get_job( $job_id ), microtime( true ) + 30 );

		$file = $this->diag_dir . '/' . $this->event_filename( 'restore-' . $job_id );
		$this->assertFileExists( $file );
		$lines = array_values(
			array_filter(
				file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ),
				static fn( $line ) => 'restore_poll' === json_decode( $line, true )['kind']
			)
		);
		$this->assertCount( 1, $lines );
		$this->assert_restore_event_redacted( $lines[0] );
		$event = json_decode( $lines[0], true );
		$this->assertSame( 'restore_poll', $event['kind'] );
		$this->assertArrayHasKey( 'poll_duration_s', $event['payload'] );
	}

	/**
	 * A bundle must also redact files written before the recorder was fixed.
	 */
	public function test_zip_export_redacts_pre_existing_restore_event() {
		if ( ! class_exists( \ZipArchive::class ) ) {
			$this->markTestSkipped( 'ZipArchive is unavailable in this environment.' );
		}
		$this->enable_recorder();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$site_id = $this->create_test_site();
		$name    = $this->event_filename( 'terminal-' . $site_id );
		$line    = wp_json_encode(
			array(
				'run_key' => 'terminal-' . $site_id,
				'site_id' => $site_id,
				'kind'    => 'restore_poll',
				'payload' => $this->restore_payload_with_secrets(),
			)
		);
		file_put_contents( $this->diag_dir . '/' . $name, $line . "\n" );
		$temporary_files = array();
		$observe_cleanup = function ( $path ) use ( &$temporary_files ) {
			if ( str_starts_with( basename( $path ), 'redacted-' ) ) {
				$this->assertFileExists( $path );
				$this->assertSame( realpath( $this->diag_dir ), realpath( dirname( $path ) ) ); // macOS resolves /var to /private/var.
				$temporary_files[] = $path;
			}
			return $path;
		};
		add_filter( 'wp_delete_file', $observe_cleanup );
		try {
			$bundle = MainWP_Backup_Diagnostics::instance()->export_bundle();
		} finally {
			remove_filter( 'wp_delete_file', $observe_cleanup );
		}
		$this->assertCount( 1, $temporary_files );
		$this->assertFileDoesNotExist( $temporary_files[0] );
		$this->assertStringEndsWith( '.zip', $bundle );
		$zip = new \ZipArchive();
		$this->assertTrue( $zip->open( $bundle ) );
		$event = $zip->getFromName( 'events/' . $name );
		$zip->close();
		$this->assertIsString( $event );
		$this->assert_restore_event_redacted( $event );
		$this->assertSame( $line . "\n", file_get_contents( $this->diag_dir . '/' . $name ) );
	}

	/**
	 * Older event files can still contain the Child's secret backup directory.
	 *
	 * @dataProvider pre_existing_backup_secret_events
	 * @param string $kind     Event kind.
	 * @param array  $payload Recorded payload.
	 * @param array  $expected Fields that must survive export.
	 * @return void
	 */
	public function test_export_redacts_pre_existing_backup_secrets( $kind, $payload, $expected ) {
		if ( ! class_exists( \ZipArchive::class ) ) {
			$this->markTestSkipped( 'ZipArchive is unavailable in this environment.' );
		}
		$this->enable_recorder();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$site_id = $this->create_test_site();
		$name    = $this->event_filename( 'terminal-' . $site_id );
		$line    = wp_json_encode(
			array(
				'run_key' => 'terminal-' . $site_id,
				'site_id' => $site_id,
				'kind'    => $kind,
				'payload' => $payload,
			)
		);
		file_put_contents( $this->diag_dir . '/' . $name, $line . "\n" );

		$bundle = MainWP_Backup_Diagnostics::instance()->export_bundle();
		$this->assertStringEndsWith( '.zip', $bundle );
		$zip = new \ZipArchive();
		$this->assertTrue( $zip->open( $bundle ) );
		$exported = $zip->getFromName( 'events/' . $name );
		$zip->close();
		$this->assertIsString( $exported );
		$this->assertStringNotContainsString( 'backup_dir_hash', $exported, 'Exported events must omit backup_dir_hash at every depth.' );
		$this->assertStringNotContainsString( 'backup-AbCdEfGhIjKlMnOp', $exported, 'Exported events must omit the Child backup directory name.' );
		$event = json_decode( $exported, true );
		$this->assertIsArray( $event );
		$this->assertSame( $kind, $event['kind'] );
		foreach ( $expected as $key => $value ) {
			$this->assertSame( $value, $event['payload'][ $key ] );
		}
		$this->assertSame( $line . "\n", file_get_contents( $this->diag_dir . '/' . $name ) );
	}

	/**
	 * Supply Child payloads from event files written before the recorder fix.
	 *
	 * @return array Event payloads and fields retained after export.
	 */
	public static function pre_existing_backup_secret_events() {
		$directory = 'wp-content/uploads/mainwp/backup-AbCdEfGhIjKlMnOp/';
		return array(
			'preflight success' => array(
				'preflight',
				array(
					'status'    => 'ok',
					'preflight' => array(
						'backup_dir_hash' => 'AbCdEfGhIjKlMnOp',
						'disk_free'       => 123456,
						'db_prefix'       => $directory . 'database.sql',
					),
				),
				array(
					'status'    => 'ok',
					'preflight' => array(
						'disk_free' => 123456,
						'db_prefix' => 'wp-content/uploads/mainwp/backup-[redacted]/database.sql',
					),
				),
			),
			'preflight failure' => array(
				'preflight',
				array(
					'status'    => 'failed',
					'preflight' => array(
						'disk_free' => 123456,
						'error'     => 'Unable to create directory wp-content/uploads/mainwp/backup-AbCdEfGhIjKlMnOp. Is its parent directory writable by the server?',
					),
				),
				array(
					'status'    => 'failed',
					'preflight' => array(
						'disk_free' => 123456,
						'error'     => 'Unable to create directory wp-content/uploads/mainwp/backup-[redacted]. Is its parent directory writable by the server?',
					),
				),
			),
			'poll'              => array(
				'poll',
				array(
					'op'        => 'run',
					'disk_free' => 123456,
					'sub_state' => array(
						'backup_dir_hash' => 'AbCdEfGhIjKlMnOp',
						'directory'       => $directory,
					),
				),
				array(
					'op'        => 'run',
					'disk_free' => 123456,
				),
			),
		);
	}

	/**
	 * The recorder must discard the hash before writing a preflight event.
	 */
	public function test_preflight_recording_omits_backup_dir_hash() {
		$this->enable_recorder();
		$report = array(
			'backup_dir_hash' => 'AbCdEfGhIjKlMnOp',
			'disk_free'       => 123456,
			'nested'          => array( 'backup_dir_hash' => 'AbCdEfGhIjKlMnOp' ),
		);
		MainWP_Backup_Diagnostics::instance()->on_preflight( 7, 42, $report, 'ok' );
		$file = $this->diag_dir . '/' . $this->event_filename( '42-7-preflight' );
		$this->assertFileExists( $file );
		$line = file_get_contents( $file );
		$this->assertStringNotContainsString( 'backup_dir_hash', $line );
		$this->assertSame( 123456, json_decode( $line, true )['payload']['preflight']['disk_free'] );
	}

	/**
	 * Poll secrets must be removed from the event file before export.
	 */
	public function test_poll_recording_omits_backup_dir_hash() {
		$this->enable_recorder();
		MainWP_Backup_Diagnostics::instance()->on_poll(
			array(
				'task_id'         => 'bk-hash-test',
				'phase'           => 'dumping_db',
				'backup_dir_hash' => 'AbCdEfGh12345678',
				'sub_state'       => array(
					'backup_dir_hash' => 'AbCdEfGh12345678',
					'table'           => 'wp_posts',
				),
			),
			0.123,
			array(
				'op'      => 'run',
				'site_id' => 7,
				'task_id' => 42,
			)
		);

		$file = $this->diag_dir . '/' . $this->event_filename( '42-7-bk-hash-test' );
		$this->assertFileExists( $file );
		$line = file_get_contents( $file );
		$this->assertStringNotContainsString( 'backup_dir_hash', $line, 'The stored poll event must omit nested and top-level hash keys.' );
		$event = json_decode( $line, true );
		$this->assertSame( 'dumping_db', $event['payload']['phase'] );
		$this->assertSame( 'wp_posts', $event['payload']['sub_state']['table'] );
	}

	/**
	 * A failed redaction removes both the incomplete ZIP and its staging files.
	 */
	public function test_zip_export_cleans_temporary_files_on_redaction_failure() {
		if ( ! class_exists( \ZipArchive::class ) ) {
			$this->markTestSkipped( 'ZipArchive is unavailable in this environment.' );
		}
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$site_id = $this->create_test_site();
		$file    = $this->diag_dir . '/' . $this->event_filename( 'terminal-' . $site_id );
		file_put_contents( $file, '{"run_key":"terminal-' . $site_id . '","metric":1e9999}' . "\n" );
		$temporary_files = array();
		$observe_cleanup = function ( $path ) use ( &$temporary_files ) {
			if ( str_starts_with( basename( $path ), 'redacted-' ) ) {
				$temporary_files[] = $path;
			}
			return $path;
		};
		add_filter( 'wp_delete_file', $observe_cleanup );
		try {
			$this->assertSame( '', MainWP_Backup_Diagnostics::instance()->export_bundle() );
		} finally {
			remove_filter( 'wp_delete_file', $observe_cleanup );
		}
		$this->assertCount( 1, $temporary_files );
		$this->assertFileDoesNotExist( $temporary_files[0] );
		$this->assertSame( array(), glob( $this->diag_dir . '/*.zip' ) );
		$this->assertFileExists( $file );
	}

	/**
	 * The fallback must redact both files and scoped shared streams.
	 */
	public function test_json_fallback_redacts_pre_existing_restore_events() {
		$line = wp_json_encode( array( 'payload' => $this->restore_payload_with_secrets() ) );
		$file = $this->diag_dir . '/legacy.jsonl';
		file_put_contents( $file, $line . "\n" );
		$global = $this->diag_dir . '/global.jsonl';
		file_put_contents(
			$global,
			$line . "\n" . wp_json_encode(
				array(
					'site_id' => 99,
					'secret'  => 'excluded',
				)
			) . "\n"
		);
		$writer = new \ReflectionMethod( MainWP_Backup_Diagnostics::class, 'write_json_bundle' );
		$path   = $this->diag_dir . '/legacy-bundle.json';
		$result = $writer->invoke(
			MainWP_Backup_Diagnostics::instance(),
			$path,
			array(),
			array(
				'files'  => array( $file, $global ),
				'scopes' => array( $global => array( array( 1 ), array() ) ),
			)
		);
		$this->assertSame( $path, $result );
		$bundle = json_decode( file_get_contents( $path ), true );
		$this->assertCount( 2, $bundle['events'] );
		foreach ( $bundle['events'] as $lines ) {
			$this->assertCount( 1, $lines );
			$this->assert_restore_event_redacted( $lines[0] );
		}
	}

	/**
	 * Include nested manifests because old logs may contain more than the status shape.
	 *
	 * @return array Child payload.
	 */
	private function restore_payload_with_secrets(): array {
		$manifest = array(
			'url'    => 'https://example.test/db?X-Amz-Signature=manifest-secret',
			'chunks' => array( array( 'url' => 'https://example.test/chunk?X-Amz-Signature=chunk-secret' ) ),
		);
		return array(
			'task_id'   => 'restore-redaction',
			'phase'     => 'completed',
			'sub_state' => array(
				'params'            => array( 'pull_manifest' => $manifest ),
				'identity_preserve' => array( 'mainwp_child_pubkey' => 'identity-option-secret' ),
				'progress'          => array( 'bytes_done' => 512 ),
			),
			'nested'    => array(
				array(
					'pull_manifest'      => $manifest,
					'pull_manifest_json' => wp_json_encode( $manifest ),
					'identity_preserve'  => array( 'key' => 'identity-option-secret' ),
				),
				array( 'pull_manifest' => false ),
			),
			'url'       => 'https://example.test/public',
		);
	}

	/**
	 * Check both secrecy and the diagnostic fields that must survive.
	 *
	 * @param string $line Encoded event.
	 * @return void
	 */
	private function assert_restore_event_redacted( string $line ): void {
		$this->assertStringNotContainsString( 'manifest-secret', $line );
		$this->assertStringNotContainsString( 'chunk-secret', $line );
		$this->assertStringNotContainsString( 'identity-option-secret', $line );
		$event   = json_decode( $line, true );
		$payload = $event['payload'];
		$this->assertTrue( $payload['sub_state']['params']['pull_manifest'] );
		$this->assertTrue( $payload['sub_state']['identity_preserve'] );
		$this->assertTrue( $payload['nested'][0]['pull_manifest'] );
		$this->assertTrue( $payload['nested'][0]['pull_manifest_json'] );
		$this->assertTrue( $payload['nested'][0]['identity_preserve'] );
		$this->assertFalse( $payload['nested'][1]['pull_manifest'] );
		$this->assertSame( 512, $payload['sub_state']['progress']['bytes_done'] );
		$this->assertSame( 'completed', $payload['phase'] );
		$this->assertSame( 'https://example.test/public', $payload['url'] );
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
	 * Tick events retain the runner counts and the queue observed by the lane.
	 *
	 * @return void
	 */
	public function test_diag_tick_event_records_outcome() {
		$this->enable_recorder();
		$outcome = array(
			'sites_visited'  => 3,
			'sites_advanced' => 2,
			'sites_finished' => 1,
			'sites_yielded'  => 1,
			'slot_refused'   => false,
			'lease_lost'     => false,
			'run_closed'     => false,
			'wall_time'      => 1.25,
		);
		$tick    = array(
			'started'              => 1234.5,
			'wall_time'            => 2.5,
			'candidates'           => array( '42', '7' ),
			'unfinished_remaining' => true,
		);
		do_action( 'mainwp_backup_diag_tick', 42, $outcome, $tick );
		$events = array_values( array_filter( $this->recorded_events(), fn( $event ) => 'tick' === $event['kind'] ) );
		$this->assertCount( 1, $events );
		$this->assertSame( 42, $events[0]['task_id'] );
		$this->assertSame( 0, $events[0]['site_id'] );
		$tick['candidates'] = array( 42, 7 );
		$tick['outcome']    = $outcome;
		$this->assertSame( $tick, $events[0]['payload'] );
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
	 * Lane decisions use their own diagnostics kind and lease duration.
	 */
	public function test_lane_events_are_recorded() {
		$this->enable_recorder();
		try {
			$token = MainWP_Backup_Monitor::acquire_lane_lease();
			$this->assertIsString( $token );
			$this->assertFalse( MainWP_Backup_Monitor::acquire_lane_lease() );
		} finally {
			MainWP_Backup_Monitor::release_lane_lease();
		}
		$events = array_values( array_filter( $this->recorded_events(), fn( $event ) => 'lane' === $event['kind'] ) );
		$this->assertSame( array( 'acquired', 'refused', 'released' ), array_column( array_column( $events, 'payload' ), 'event' ) );
		$this->assertSame( $token, $events[0]['payload']['token'] );
		$this->assertSame( 600, $events[0]['payload']['lease_seconds'] );
		$this->assertSame( 1, $events[0]['payload']['active'] );
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
	 * The stored Child create refusal must not expose its directory in the table dump.
	 */
	public function test_export_bundle_redacts_progress_fetch_result() {
		if ( ! class_exists( \ZipArchive::class ) ) {
			$this->markTestSkipped( 'ZipArchive is unavailable in this environment.' );
		}

		$this->enable_recorder();
		$site_id = $this->create_test_site();
		$user    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$task = MainWP_DB_Backup::instance()->add_backup_task( $user, 'Progress export test', 'daily', 'full', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'tar.gz', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task );
		MainWP_DB_Backup::instance()->add_backup_task_progress( (int) $task->id, $site_id, array() );
		MainWP_DB_Backup::instance()->update_backup_task_progress(
			(int) $task->id,
			$site_id,
			array(
				'fetchResult' => wp_json_encode(
					array(
						'error'      => 'Unable to create wp-content/uploads/mainwp/backup-AbCdEfGh12345678/ after UPDATE wp_options SET x=1',
						'error_code' => 'mainwp_backup_dir_unwritable',
					)
				),
			)
		);
		MainWP_Backup_Diagnostics::instance()->record( '1-' . $site_id . '-bkx', 'poll', $site_id, (int) $task->id, array( 'phase' => 'failed' ) );

		$bundle = MainWP_Backup_Diagnostics::instance()->export_bundle();
		$this->assertStringEndsWith( '.zip', $bundle );
		$zip = new \ZipArchive();
		$this->assertTrue( $zip->open( $bundle ) );
		$progress = $zip->getFromName( 'progress.json' );
		$zip->close();
		$this->assertIsString( $progress );
		$this->assertCount( 1, json_decode( $progress, true ) );
		$this->assertStringNotContainsString( 'backup-AbCdEfGh12345678', $progress, 'The progress section must omit the Child backup directory hash.' );
		$this->assertStringContainsString( 'backup-[redacted]', $progress );
		$fetch = json_decode( json_decode( $progress, true )[0]['fetchResult'], true );
		$this->assertIsArray( $fetch, 'fetchResult must stay valid JSON after redaction.' );
		$this->assertSame( 'mainwp_backup_dir_unwritable', $fetch['error_code'], 'Fields after a SQL-looking error must survive.' );
	}

	/**
	 * Every shape an exported Child-text column can hold.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public function data_exported_text_shapes() {
		$dir = 'wp-content/uploads/mainwp/backup-AbCdEfGh12345678';
		return array(
			'plain text'                 => array( 'Unable to create directory ' . $dir . '.', false ),
			'plain code'                 => array( 'mainwp_backup_disk_full', false ),
			'response, path in a value'  => array(
				(string) wp_json_encode(
					array(
						'error'      => 'Unable to create ' . $dir . '/ after UPDATE wp_options SET x=1',
						'error_code' => 'mainwp_backup_dir_unwritable',
					)
				),
				true,
			),
			'response, path in a key'    => array(
				(string) wp_json_encode(
					array(
						$dir . '/archive.zip' => 12,
						'error_code'          => 'mainwp_backup_dir_unwritable',
					)
				),
				true,
			),
			'response, nested hash key'  => array(
				(string) wp_json_encode(
					array(
						'sub_state'  => array(
							'backup_dir_hash' => 'AbCdEfGh12345678',
							'db_prefix'       => $dir . '/dbBackup',
						),
						'error_code' => 'mainwp_backup_dir_unwritable',
					)
				),
				true,
			),
			'response, list of paths'    => array( (string) wp_json_encode( array( $dir . '/a.zip', $dir . '/b.zip' ) ), true ),
			'encoded string, not a list' => array( (string) wp_json_encode( 'Unable to create ' . $dir ), false ),
		);
	}

	/**
	 * An exported column never names the Child backup directory, whatever shape it holds,
	 * and a Child response stays a readable response.
	 *
	 * @dataProvider data_exported_text_shapes
	 *
	 * @param string $stored      Stored column value.
	 * @param bool   $is_response Whether the value is a JSON object or list.
	 */
	public function test_exported_text_never_names_the_backup_directory( $stored, $is_response ) {
		$redact   = new \ReflectionMethod( MainWP_Backup_Diagnostics::class, 'redact_exported_text' );
		$exported = $redact->invoke( MainWP_Backup_Diagnostics::instance(), $stored );

		$this->assertStringNotContainsString( 'AbCdEfGh12345678', $exported, 'The exported column must not name the Child backup directory.' );
		if ( $is_response ) {
			$decoded = json_decode( $exported, true );
			$this->assertIsArray( $decoded, 'A Child response must stay valid JSON after redaction.' );
			if ( str_contains( $stored, 'error_code' ) ) {
				$this->assertSame( 'mainwp_backup_dir_unwritable', $decoded['error_code'], 'Fields next to a redacted one must survive.' );
			}
		}
		if ( 'mainwp_backup_disk_full' === $stored ) {
			$this->assertSame( $stored, $exported, 'Text with nothing to redact must pass through unchanged.' );
		}
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
		do_action( 'mainwp_log_warning', 'Backup retention allowed guardrail', $site_allowed, (int) $task_allowed->id );
		do_action( 'mainwp_log_warning', 'Backup retention denied guardrail', $site_denied, (int) $task_denied->id );
		do_action( 'mainwp_log_info', 'Restore denied guardrail', $site_denied, 0 );
		do_action( 'mainwp_log_warning', 'Backup host guardrail' );
		do_action( 'mainwp_log_info', 'Restore host guardrail' );
		do_action(
			'mainwp_backup_diag_tick',
			(int) $task_allowed->id,
			null,
			array(
				'started'              => 1.0,
				'wall_time'            => 0.5,
				'candidates'           => array( (int) $task_allowed->id, (int) $task_denied->id ),
				'unfinished_remaining' => true,
			)
		);

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
		$this->assertStringContainsString( 'Backup retention allowed guardrail', $global );
		$this->assertStringNotContainsString( 'Backup retention denied guardrail', $global );
		$this->assertStringNotContainsString( 'Restore denied guardrail', $global );
		$this->assertStringContainsString( 'Backup host guardrail', $global );
		$this->assertStringContainsString( 'Restore host guardrail', $global );

		$ticks = array_values( array_filter( array_map( fn( $line ) => json_decode( $line, true ), explode( "\n", trim( $global ) ) ), fn( $event ) => is_array( $event ) && 'tick' === $event['kind'] ) );
		$this->assertCount( 1, $ticks, 'The tick line on the caller\'s own task rides along.' );
		$this->assertSame( array( (int) $task_allowed->id ), $ticks[0]['payload']['candidates'], 'A scoped bundle must not name out-of-scope candidate tasks.' );
	}

	/**
	 * Missing site ids must never grant unrestricted export access.
	 *
	 * @dataProvider empty_site_lookup_cases
	 * @param array|null $site_ids Site query result.
	 * @return void
	 */
	public function test_export_scope_is_empty_when_site_lookup_is_empty( $site_ids ) {
		global $wpdb;
		$original     = $wpdb;
		$wpdb         = $this->getMockBuilder( \wpdb::class )->disableOriginalConstructor()->onlyMethods( array( 'get_col' ) )->getMock(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- isolate the failed lookup without breaking the test database.
		$wpdb->prefix = $original->prefix;
		// MainWP_DB pings the handle before it hands out a table name, and a mock with no dbh takes the pre-mysqli branch.
		$wpdb->dbh = $original->dbh;
		$wpdb->expects( $this->once() )->method( 'get_col' )->willReturn( $site_ids );

		try {
			$scope = new \ReflectionMethod( MainWP_Backup_Diagnostics::class, 'export_scope_site_ids' );
			$this->assertSame( array(), $scope->invoke( MainWP_Backup_Diagnostics::instance() ) );
		} finally {
			$wpdb = $original; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restore the database for subsequent tests.
		}
	}

	/**
	 * Empty and failed lookups share the same closed scope.
	 *
	 * @return array
	 */
	public static function empty_site_lookup_cases() {
		return array(
			'empty'  => array( array() ),
			'failed' => array( null ),
		);
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
	 * Files recorded before diagnostics was disabled still need to expire.
	 *
	 * @return void
	 */
	public function test_retention_hook_prunes_when_diagnostics_disabled() {
		$recorder = MainWP_Backup_Diagnostics::instance();
		update_option( MainWP_Backup_Diagnostics::OPTION_ENABLED, '0' );
		remove_action( 'mainwp_cronbackups_prune_action', array( $recorder, 'prune' ) );
		$recorder->init();

		$old   = $this->diag_dir . '/disabled-old.jsonl';
		$fresh = $this->diag_dir . '/disabled-fresh.jsonl';
		file_put_contents( $old, "{}\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		file_put_contents( $fresh, "{}\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		touch( $old, time() - ( MainWP_Backup_Diagnostics::RETENTION_DAYS + 1 ) * DAY_IN_SECONDS );

		do_action( 'mainwp_cronbackups_prune_action' );

		$this->assertFileDoesNotExist( $old, 'Disabled recording must not prevent retention cleanup.' );
		$this->assertFileExists( $fresh, 'Files within the retention window must survive.' );
	}

	/**
	 * The system cron prune script calls the lane directly instead of firing
	 * the WP-Cron hook, so it must prune diagnostics files itself.
	 *
	 * @return void
	 */
	public function test_system_cron_prune_script_prunes_diagnostics() {
		update_option( MainWP_Backup_Diagnostics::OPTION_ENABLED, '0' );
		remove_all_actions( 'mainwp_cronbackups_prune_action' );

		$old   = $this->diag_dir . '/system-cron-old.jsonl';
		$fresh = $this->diag_dir . '/system-cron-fresh.jsonl';
		file_put_contents( $old, "{}\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		file_put_contents( $fresh, "{}\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
		touch( $old, time() - ( MainWP_Backup_Diagnostics::RETENTION_DAYS + 1 ) * DAY_IN_SECONDS );

		MainWP_System::instance()->mainwp_cronbackups_prune_action();

		$this->assertFileDoesNotExist( $old, 'The system cron prune must expire old diagnostics files.' );
		$this->assertFileExists( $fresh, 'Files within the retention window must survive.' );
	}

	/**
	 * Manifest provenance must remain visible in diagnostics for each site.
	 *
	 * @return void
	 */
	public function test_sites_section_includes_manifest_writer_identity_capability() {
		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		MainWP_DB::instance()->update_website_option( $website, 'manifest_writer_identity_support', '1' );

		$reader   = new \ReflectionMethod( MainWP_Backup_Diagnostics::class, 'bundle_sections' );
		$sections = $reader->invoke( MainWP_Backup_Diagnostics::instance(), $this->diag_dir, array( $site_id ) );

		$this->assertArrayHasKey( 'env', $sections );
		$this->assertCount( 1, $sections['sites'] );
		$this->assertArrayHasKey( 'manifest_writer_identity_support', $sections['sites'][0]['capabilities'] );
		$this->assertSame( '1', $sections['sites'][0]['capabilities']['manifest_writer_identity_support'] );
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
	 * The exported bundle needs a user-bound download rather than a bearer URL.
	 *
	 * @return void
	 */
	public function test_export_handler_returns_authenticated_download_url() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );

		$response = $this->invoke_export_handler();
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'error', $response, 'A disabled recorder must refuse the export.' );

		$this->enable_recorder();
		MainWP_Backup_Diagnostics::instance()->record( 'exporturl', 'poll', 1, 1, array( 'phase' => 'queued' ) );

		$response = $this->invoke_export_handler();
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'url', $response );
		$this->assertSame( wp_parse_url( admin_url( 'admin-post.php' ), PHP_URL_PATH ), wp_parse_url( $response['url'], PHP_URL_PATH ) );
		parse_str( wp_parse_url( $response['url'], PHP_URL_QUERY ), $query );
		$this->assertSame( 'mainwp_backup_diagnostics_download', $query['action'] );
		$this->assertMatchesRegularExpression( '/^[a-zA-Z0-9]{32}$/', $query['token'] );
		$this->assertNotFalse( wp_verify_nonce( $query['_wpnonce'], 'mainwp_backup_diagnostics_download' ) );
		$this->assertArrayNotHasKey( 'mwpdl', $query );
		$this->assertArrayNotHasKey( 'sig', $query );
		$this->assertStringNotContainsString( '&amp;', $response['url'] );
		$this->assertStringNotContainsString( '&#038;', $response['url'] );
		$key                   = 'mainwp_backup_diag_download_' . hash( 'sha256', $query['token'] );
		$this->download_keys[] = $key;
		$record                = get_site_transient( $key );
		$this->assertSame( get_current_user_id(), $record['user_id'] );
		$this->assertSame( basename( $record['file'] ), $record['file'] );
		$this->assertFileExists( $this->diag_dir . '/' . $record['file'] );
		$this->assertGreaterThan( time() + 590, $record['expires'] );
		$this->assertLessThanOrEqual( time() + 600, $record['expires'] );
		$this->assertSame( file_get_contents( $this->diag_dir . '/' . $record['file'] ), $this->request_diagnostics_download( $query )['bytes'] );
	}

	/**
	 * Replace only the exit-producing streamer and wp_die to retain endpoint checks.
	 *
	 * @param array $query Request parameters.
	 * @param bool  $legacy Whether to use the old download route.
	 * @return array Captured bytes and refusal details.
	 */
	private function request_diagnostics_download( array $query, bool $legacy = false ): array {
		$streamer = new class() extends MainWP_System_Handler {
			/**
			 * The production streamer's exit would terminate the test process.
			 *
			 * @param string $file Authorized path.
			 * @throws \RuntimeException To replace the streamer's exit.
			 * @throws \LogicException When a record has not been consumed before streaming.
			 */
			public function upload_file( $file ) {
				$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
				if ( '' !== $token && false !== get_site_transient( 'mainwp_backup_diag_download_' . hash( 'sha256', $token ) ) ) {
					throw new \LogicException( 'The download record must be consumed before streaming.' );
				}
				throw new \RuntimeException( file_get_contents( $file ) );
			}
		};
		$response = array(
			'bytes'   => null,
			'message' => null,
			'status'  => null,
		);
		$die      = static function () use ( &$response ) {
			return static function ( $message, $title, $args ) use ( &$response ) {
				$response['message'] = $message;
				$response['status']  = $args['response'];
				throw new \RuntimeException( 'download refused' );
			};
		};
		$property = new \ReflectionProperty( MainWP_System_Handler::class, 'instance' );
		$original = $property->getValue();
		$get      = $_GET;
		$request  = $_REQUEST;
		add_filter( 'wp_die_handler', $die );
		try {
			$property->setValue( null, $streamer );
			$_GET     = $query;
			$_REQUEST = $query;
			try {
				if ( $legacy ) {
					MainWP_System::instance()->parse_init();
				} else {
					MainWP_Post_Backup_Handler::instance()->init();
					do_action( 'admin_post_mainwp_backup_diagnostics_download' );
				}
			} catch ( \RuntimeException $error ) {
				if ( null === $response['message'] ) {
					$response['bytes'] = $error->getMessage();
				} else {
					$this->assertSame( 'download refused', $error->getMessage() );
				}
			}
			return $response;
		} finally {
			$property->setValue( null, $original );
			$_GET     = $get;
			$_REQUEST = $request;
			remove_filter( 'wp_die_handler', $die );
		}
	}

	/**
	 * Download validation must also be exercised independently of URL creation.
	 *
	 * @return array Query parameters and transient key.
	 */
	private function diagnostics_download_fixture(): array {
		$this->set_current_user_as_admin();
		$token = wp_generate_password( 32, false );
		$key   = 'mainwp_backup_diag_download_' . hash( 'sha256', $token );
		set_site_transient(
			$key,
			array(
				'user_id' => get_current_user_id(),
				'file'    => 'bundle.json',
				'expires' => time() + 600,
			),
			600
		);
		$this->download_keys[] = $key;
		file_put_contents( $this->diag_dir . '/bundle.json', 'diagnostics bundle bytes' );
		return array(
			array(
				'action'   => 'mainwp_backup_diagnostics_download',
				'token'    => $token,
				'_wpnonce' => wp_create_nonce( 'mainwp_backup_diagnostics_download' ),
			),
			$key,
		);
	}

	/** The token must be consumed before the selected bytes reach the streamer. */
	public function test_diagnostics_download_serves_bundle_once() {
		list( $query, $key ) = $this->diagnostics_download_fixture();
		$this->assertSame( 'diagnostics bundle bytes', $this->request_diagnostics_download( $query )['bytes'] );
		$this->assertFalse( get_site_transient( $key ) );
		$second = $this->request_diagnostics_download( $query );
		$this->assertNull( $second['bytes'] );
		$this->assertSame( 403, $second['status'] );
		$this->assertSame( 'This download link is not valid or has expired. Export the diagnostics again.', $second['message'] );
	}

	/**
	 * Authorization and nonce refusals cannot consume another user's valid record.
	 *
	 * @dataProvider diagnostics_refusals
	 * @param string $state Request mutation.
	 * @param bool   $consumed Whether the record itself is invalid.
	 */
	public function test_diagnostics_download_refusals( string $state, bool $consumed ) {
		list( $query, $key ) = $this->diagnostics_download_fixture();
		$record              = get_site_transient( $key );
		$outside             = '';
		$deny                = static function ( $can, $type, $right ) use ( $state ) {
			return 'denied right' === $state && 'dashboard' === $type && 'execute_backups' === $right ? false : $can;
		};
		add_filter( 'mainwp_currentusercan', $deny, 10, 3 );
		if ( 'logged out' === $state ) {
			wp_set_current_user( 0 );
		} elseif ( 'other admin' === $state || 'subscriber' === $state ) {
			wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' === $state ? 'subscriber' : 'administrator' ) ) );
			$query['_wpnonce'] = wp_create_nonce( 'mainwp_backup_diagnostics_download' );
		} elseif ( 'wrong nonce' === $state ) {
			$query['_wpnonce'] = 'invalid';
		} elseif ( 'missing nonce' === $state ) {
			unset( $query['_wpnonce'] );
		} elseif ( 'expired' === $state ) {
			$record['expires'] = time() - 1;
		} elseif ( 'malformed record' === $state ) {
			$record = 'invalid';
		} elseif ( 'traversal' === $state ) {
			$record['file'] = '../outside.json';
		} elseif ( 'backslash' === $state ) {
			$record['file'] = 'subdir\\bundle.json';
		} elseif ( 'missing file' === $state ) {
			$record['file'] = 'missing.json';
		} elseif ( 'symlink' === $state ) {
			$outside = wp_tempnam( 'outside-diagnostics' );
			file_put_contents( $outside, 'outside bytes' );
			symlink( $outside, $this->diag_dir . '/escape.json' );
			$record['file'] = 'escape.json';
		}
		set_site_transient( $key, $record, 600 );
		try {
			$response = $this->request_diagnostics_download( $query );
			$this->assertNull( $response['bytes'] );
			$this->assertSame( 403, $response['status'] );
			$this->assertSame( 'This download link is not valid or has expired. Export the diagnostics again.', $response['message'] );
			$this->assertSame( $consumed ? false : $record, get_site_transient( $key ) );
		} finally {
			remove_filter( 'mainwp_currentusercan', $deny, 10 );
			if ( '' !== $outside ) {
				unlink( $outside );
			}
		}
	}

	/**
	 * Failed privilege checks preserve records; invalid records cannot be reused.
	 *
	 * @return array Refusal cases and consumption expectations.
	 */
	public static function diagnostics_refusals(): array {
		return array(
			'logged out'       => array( 'logged out', false ),
			'other admin'      => array( 'other admin', true ),
			'subscriber'       => array( 'subscriber', false ),
			'denied right'     => array( 'denied right', false ),
			'wrong nonce'      => array( 'wrong nonce', false ),
			'missing nonce'    => array( 'missing nonce', false ),
			'expired'          => array( 'expired', true ),
			'malformed record' => array( 'malformed record', true ),
			'traversal'        => array( 'traversal', true ),
			'backslash'        => array( 'backslash', true ),
			'missing file'     => array( 'missing file', true ),
			'symlink'          => array( 'symlink', true ),
		);
	}

	/**
	 * Older signed links must not bypass the authenticated diagnostics route.
	 *
	 * @dataProvider legacy_diagnostics_paths
	 * @param string $prefix Diagnostics directory spelling in the request.
	 */
	public function test_legacy_diagnostics_link_is_refused( string $prefix ) {
		$root = MainWP_System_Utility::get_mainwp_dir()[0];
		$dir  = $root . ltrim( rawurldecode( $prefix ), '/' );
		wp_mkdir_p( $dir );
		$file = $dir . 'diagnostics-' . wp_generate_uuid4() . '.json';
		file_put_contents( $file, 'legacy diagnostics bytes' );
		wp_set_current_user( 0 );
		try {
			$query = array(
				'mwpdl' => $prefix . basename( $file ),
				'sig'   => MainWP_System_Utility::get_download_sig( $file ),
			);
			$this->assertNull( $this->request_diagnostics_download( $query, true )['bytes'] );
		} finally {
			unlink( $file );
		}
	}

	/**
	 * Directory matching must survive the legacy route's decoding and slashes.
	 *
	 * @return array Legacy directory spellings.
	 */
	public static function legacy_diagnostics_paths(): array {
		return array(
			'plain'            => array( 'backup-diagnostics/' ),
			'case and slashes' => array( '//BACKUP-DIAGNOSTICS/' ),
			'encoded slash'    => array( '/backup-diagnostics%2F' ),
			'dot prefix'       => array( '/./backup-diagnostics/' ),
		);
	}

	/** Bulk-install and other legacy callers still depend on signed public downloads. */
	public function test_legacy_other_subdirectory_download_still_served() {
		$dir = MainWP_System_Utility::get_mainwp_dir()[0] . 'bulk-install/';
		wp_mkdir_p( $dir );
		$file = $dir . 'archive-' . wp_generate_uuid4() . '.zip';
		file_put_contents( $file, 'legacy archive bytes' );
		wp_set_current_user( 0 );
		try {
			$query = array(
				'mwpdl' => 'bulk-install/' . basename( $file ),
				'sig'   => MainWP_System_Utility::get_download_sig( $file ),
			);
			$this->assertSame( 'legacy archive bytes', $this->request_diagnostics_download( $query, true )['bytes'] );
		} finally {
			unlink( $file );
		}
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
