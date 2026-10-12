<?php
/**
 * Backup size checks for the Child and Dashboard disks.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

require_once __DIR__ . '/trait-backup-private-root-fixture.php';

use MainWP\Dashboard\MainWP_Backup_Error_Codes;
use MainWP\Dashboard\MainWP_Backup_Executor;
use MainWP\Dashboard\MainWP_Backup_Monitor;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Exception;
use MainWP\Dashboard\MainWP_Manage_Backups_Handler;

/** Regression coverage for estimate-based disk refusals. */
class MainWP_Backup_Preflight_Disk_Test extends MainWP_Abilities_Test_Case {

	use Backup_Private_Root_Fixture;
	use Backup_Cancel_Transport_Guard;

	/**
	 * Dashboard free-space fixture.
	 *
	 * @var float|false|string
	 */
	private $dashboard_free;

	/**
	 * Child requests captured before the mocked stop response.
	 *
	 * @var array
	 */
	private $child_calls;

	/**
	 * Isolate storage and disk readings.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->set_up_private_root();
		mkdir( $this->private_root, 0700 );
		update_option( 'mainwp_enableNativeBackups', 1 );
		$this->dashboard_free = 10 * GB_IN_BYTES;
		$this->child_calls    = array();
	}

	/**
	 * Remove the test-owned disk override and storage.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		MainWP_Backup_TestRestore_Sandbox_Test::$free_space = null;
		$this->tear_down_private_root();
		parent::tearDown();
	}

	/**
	 * Supply readiness and stop at the first backup request.
	 *
	 * @param array $overrides Readiness fields.
	 * @return void
	 */
	private function mock_preflight( array $overrides ) {
		$preflight = array_merge(
			array(
				'disk_free'       => 10 * GB_IN_BYTES,
				'estimated_bytes' => 5 * GB_IN_BYTES,
				'task_spine'      => 1,
				'incremental'     => 1,
				'gz_support'      => true,
			),
			$overrides
		);
		if ( array_key_exists( 'estimated_bytes', $overrides ) && null === $overrides['estimated_bytes'] ) {
			unset( $preflight['estimated_bytes'] );
		}
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what, $params ) use ( $preflight ) {
				$this->child_calls[] = array( $what, $params );
				if ( 'backup_preflight' === $what ) {
					return $preflight;
				}
				return array(
					'error'      => 'Fixture stops after preflight.',
					'error_code' => MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT,
				);
			},
			10,
			4
		);
	}

	/**
	 * Run the preflight directly, optionally omitting the new argument.
	 *
	 * @param array       $overrides Readiness fields.
	 * @param string|null $type Effective backup type, null to omit it.
	 * @return MainWP_Exception|null Refusal, if any.
	 */
	private function negotiate( array $overrides = array(), $type = 'full' ) {
		MainWP_Backup_TestRestore_Sandbox_Test::$free_space = $this->dashboard_free;
		$website = MainWP_DB::instance()->get_website_by_id( $this->create_test_site() );
		$ext     = 'tar.gz';
		$this->mock_preflight( $overrides );
		try {
			if ( null === $type ) {
				MainWP_Backup_Monitor::negotiate_backup_preflight( $website, $ext );
			} else {
				MainWP_Backup_Monitor::negotiate_backup_preflight( $website, $ext, null, true, $type );
			}
		} catch ( MainWP_Exception $e ) {
			return $e;
		}
		return null;
	}

	/** Child refusal includes both byte quantities. */
	public function test_full_refuses_child_estimate_larger_than_free_space() {
		$exception = $this->negotiate( array( 'disk_free' => (string) ( 3 * GB_IN_BYTES ) ) );
		$this->assertInstanceOf( MainWP_Exception::class, $exception );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_DISK_FULL, $exception->get_message_error_code() );
		$this->assertStringContainsString( 'child site', $exception->getMessage() );
		$this->assertStringContainsString( '5 GB', $exception->getMessage() );
		$this->assertStringContainsString( '3 GB', $exception->getMessage() );
	}

	/** Dashboard refusal measures the same private root used for downloads. */
	public function test_full_refuses_dashboard_estimate_larger_than_free_space() {
		$this->dashboard_free = (string) ( 3 * GB_IN_BYTES );
		$exception            = $this->negotiate();
		$this->assertInstanceOf( MainWP_Exception::class, $exception );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_DISK_FULL, $exception->get_message_error_code() );
		$this->assertStringContainsString( 'Dashboard server', $exception->getMessage() );
		$this->assertStringContainsString( '5 GB', $exception->getMessage() );
		$this->assertStringContainsString( '3 GB', $exception->getMessage() );
	}

	/** Float zero must still render as 0 B. */
	public function test_dashboard_zero_free_space_reports_zero_bytes() {
		$this->dashboard_free = 0.0;
		$exception            = $this->negotiate();
		$this->assertInstanceOf( MainWP_Exception::class, $exception );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_DISK_FULL, $exception->get_message_error_code() );
		$this->assertStringContainsString( '0 B', $exception->getMessage() );
	}

	/** Room on both disks permits the run. */
	public function test_full_passes_when_both_disks_have_room() {
		$this->assertNull( $this->negotiate() );
	}

	/** The estimate has no safety multiplier, and equality passes. */
	public function test_full_passes_when_estimate_equals_both_free_readings() {
		$this->dashboard_free = 5 * GB_IN_BYTES;
		$this->assertNull( $this->negotiate( array( 'disk_free' => 5 * GB_IN_BYTES ) ) );
	}

	/** A file estimate must not block a database dump. */
	public function test_database_only_ignores_file_estimate() {
		$this->dashboard_free = 100 * MB_IN_BYTES;
		$this->assertNull( $this->negotiate( array( 'disk_free' => 100 * MB_IN_BYTES ), 'db' ) );
	}

	/** Unknown callers retain the original 50 MB check only. */
	public function test_caller_without_type_keeps_existing_behavior() {
		$this->dashboard_free = 100 * MB_IN_BYTES;
		$this->assertNull( $this->negotiate( array( 'disk_free' => 100 * MB_IN_BYTES ), null ) );
	}

	/**
	 * Unavailable measurements are advisory.
	 *
	 * @dataProvider advisory_cases
	 * @param array $overrides Readiness fields.
	 * @param mixed $dashboard_free Dashboard reading.
	 * @return void
	 */
	public function test_advisory_values_pass( $overrides, $dashboard_free ) {
		$this->dashboard_free = $dashboard_free;
		$this->assertNull( $this->negotiate( $overrides ) );
	}

	/**
	 * Each unavailable reading skips only its own check.
	 *
	 * @return array
	 */
	public static function advisory_cases() {
		return array(
			'child false'          => array( array( 'disk_free' => false ), 10 * GB_IN_BYTES ),
			'child nonnumeric'     => array( array( 'disk_free' => 'unknown' ), 10 * GB_IN_BYTES ),
			'dashboard false'      => array( array(), false ),
			'dashboard nonnumeric' => array( array(), 'unknown' ),
			'estimate absent'      => array(
				array(
					'disk_free'       => 100 * MB_IN_BYTES,
					'estimated_bytes' => null,
				),
				100 * MB_IN_BYTES,
			),
			'estimate nonnumeric'  => array(
				array(
					'disk_free'       => 100 * MB_IN_BYTES,
					'estimated_bytes' => 'unknown',
				),
				100 * MB_IN_BYTES,
			),
		);
	}

	/**
	 * Exercise each executor entry without creating an archive.
	 *
	 * @param string      $path Executor path.
	 * @param string      $type Backup type.
	 * @param array       $overrides Readiness fields.
	 * @param bool|string $with_base Seed a usable base or a named fallback fixture.
	 * @return MainWP_Exception|null Refusal or mocked stop response.
	 */
	private function run_executor( $path, $type, array $overrides, $with_base = false ) {
		MainWP_Backup_TestRestore_Sandbox_Test::$free_space = $this->dashboard_free;
		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$task    = (object) array(
			'id'                             => 987654,
			'userid'                         => $website->userid,
			'type'                           => $type,
			'exclude'                        => '',
			'excludebackup'                  => 0,
			'excludecache'                   => 0,
			'excludenonwp'                   => 0,
			'excludezip'                     => 0,
			'filename'                       => '',
			'archiveFormat'                  => 'tar.gz',
			'loadFilesBeforeZip'             => 1,
			'maximumFileDescriptorsOverride' => 0,
			'maximumFileDescriptorsAuto'     => 1,
			'maximumFileDescriptors'         => 150,
			'last_run'                       => time() - 100,
			'subfolder'                      => 'backups',
		);
		if ( $with_base ) {
			$manifest = $this->private_root . '/files.list';
			file_put_contents( $manifest, 'chunk-failure' === $with_base ? str_repeat( 'm', 4500001 ) : "a.php\t10\t100\n" );
			$base_id = MainWP_Backup_Snapshots::instance()->record_snapshot(
				array(
					'task_id'       => $task->id,
					'site_id'       => $site_id,
					'type'          => 'broken' === $with_base ? 'incremental' : 'full',
					'parent_id'     => 'broken' === $with_base ? 99999999 : null,
					'archives'      => array(),
					'manifest_path' => $manifest,
					'run_key'       => $site_id . ':' . $task->id . ':c:fixture-base',
				)
			);
			$this->assertGreaterThan( 0, $base_id );
			if ( 'limit' === $with_base ) {
				$task->max_chain_length = 1;
			} elseif ( 'missing' === $with_base ) {
				MainWP_DB_Backup::instance()->add_backup_task_progress( $task->id, $site_id, array() );
				MainWP_DB_Backup::instance()->update_backup_task_progress(
					$task->id,
					$site_id,
					array(
						'dtsFetched' => 0,
						'last_error' => wp_json_encode(
							array(
								'incremental_sent' => 'push',
								'run'              => $task->last_run,
							)
						),
					)
				);
				$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
				$this->assertSame( 0, (int) $progress->dtsFetched );
				$this->assertSame( 'push', json_decode( $progress->last_error, true )['incremental_sent'] );
			}
		}
		$this->mock_preflight( $overrides );
		try {
			if ( 'scheduled' === $path ) {
				MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
			} elseif ( 'run-now' === $path ) {
				MainWP_Backup_Executor::backup( $site_id, $type, '', '', 0, 0, 0, 0 );
			} else {
				MainWP_Backup_Executor::trigger_backup( $site_id, $type );
			}
		} catch ( MainWP_Exception $e ) {
			return $e;
		}
		return null;
	}

	/**
	 * All three callers carry full/files type information into the preflight.
	 *
	 * @dataProvider executor_refusal_cases
	 * @param string $path Executor entry.
	 * @param string $type Backup type.
	 * @param string $disk Disk that lacks room.
	 * @return void
	 */
	public function test_executor_paths_refuse_before_backup_start( $path, $type, $disk ) {
		$this->dashboard_free = 'dashboard' === $disk ? 3 * GB_IN_BYTES : 10 * GB_IN_BYTES;
		$exception            = $this->run_executor( $path, $type, array( 'disk_free' => 'child' === $disk ? 3 * GB_IN_BYTES : 10 * GB_IN_BYTES ) );
		$this->assertInstanceOf( MainWP_Exception::class, $exception );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_DISK_FULL, $exception->get_message_error_code() );
		$this->assertSame( array( 'backup_preflight' ), array_column( $this->child_calls, 0 ), 'No backup or task-create request may start.' );
	}

	/**
	 * File-including types and both disks across all callers.
	 *
	 * @return array
	 */
	public static function executor_refusal_cases() {
		$cases = array();
		foreach ( array( 'scheduled', 'run-now', 'adhoc' ) as $path ) {
			foreach ( array( 'full', 'files' ) as $type ) {
				foreach ( array( 'child', 'dashboard' ) as $disk ) {
					$cases[ $path . '-' . $type . '-' . $disk ] = array( $path, $type, $disk );
				}
			}
		}
		return $cases;
	}

	/**
	 * Database-only entry points still reach the backup request.
	 *
	 * @dataProvider executor_paths
	 * @param string $path Executor entry.
	 * @return void
	 */
	public function test_executor_paths_allow_database_only( $path ) {
		$this->dashboard_free = 100 * MB_IN_BYTES;
		$exception            = $this->run_executor( $path, 'db', array( 'disk_free' => 100 * MB_IN_BYTES ) );
		$this->assertInstanceOf( MainWP_Exception::class, $exception );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT, $exception->get_message_error_code() );
		$this->assertCount( 2, $this->child_calls );
		$this->assertSame( 'db', $this->child_calls[1][1]['type'] );
	}

	/**
	 * Preflight callers.
	 *
	 * @return array
	 */
	public static function executor_paths() {
		return array( array( 'scheduled' ), array( 'run-now' ), array( 'adhoc' ) );
	}

	/** A usable base archives changed files, so the site estimate is irrelevant. */
	public function test_incremental_with_base_ignores_full_site_estimate() {
		$this->dashboard_free = 100 * MB_IN_BYTES;
		$exception            = $this->run_executor( 'scheduled', 'incremental', array( 'disk_free' => 100 * MB_IN_BYTES ), true );
		$this->assertInstanceOf( MainWP_Exception::class, $exception );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT, $exception->get_message_error_code() );
		$this->assertCount( 2, $this->child_calls );
		$this->assertSame( 'incremental', $this->child_calls[1][1]['type'] );
	}

	/**
	 * A first incremental archives the full site and needs room on both disks.
	 *
	 * @dataProvider short_disks
	 * @param string $disk Disk with insufficient room.
	 * @return void
	 */
	public function test_incremental_without_base_refuses_full_backup( $disk ) {
		$this->dashboard_free = 'dashboard' === $disk ? 100 * MB_IN_BYTES : 10 * GB_IN_BYTES;
		$exception            = $this->run_executor( 'scheduled', 'incremental', array( 'disk_free' => 'child' === $disk ? 100 * MB_IN_BYTES : 10 * GB_IN_BYTES ) );
		$this->assertInstanceOf( MainWP_Exception::class, $exception );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_DISK_FULL, $exception->get_message_error_code() );
		$this->assertSame( array( 'backup_preflight' ), array_column( $this->child_calls, 0 ) );
	}

	/**
	 * Both possible shortfalls.
	 *
	 * @return array
	 */
	public static function short_disks() {
		return array(
			'child'     => array( 'child' ),
			'dashboard' => array( 'dashboard' ),
		);
	}

	/**
	 * Existing bases can still require a full restart.
	 *
	 * @dataProvider incremental_fallback_cases
	 * @param string $base Base/chain fixture.
	 * @param string $disk Disk with insufficient room.
	 * @return void
	 */
	public function test_incremental_chain_fallback_refuses_full_backup( $base, $disk ) {
		$this->dashboard_free = 'dashboard' === $disk ? 100 * MB_IN_BYTES : 10 * GB_IN_BYTES;
		$exception            = $this->run_executor(
			'scheduled',
			'incremental',
			array(
				'disk_free'      => 'child' === $disk ? 100 * MB_IN_BYTES : 10 * GB_IN_BYTES,
				'incremental'    => 'unsupported' === $base ? 0 : 1,
				'manifest_chunk' => 'chunk-failure' === $base ? 1 : 0,
			),
			$base
		);
		$this->assertInstanceOf( MainWP_Exception::class, $exception );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_DISK_FULL, $exception->get_message_error_code() );
		$expected = 'chunk-failure' === $base ? array( 'backup_preflight', 'backup_manifest_chunk' ) : array( 'backup_preflight' );
		$this->assertSame( $expected, array_column( $this->child_calls, 0 ), 'No create or run request may reach the Child.' );
	}

	/**
	 * Full fallbacks decided by the resolver or a failed chunk transfer.
	 *
	 * @return array
	 */
	public static function incremental_fallback_cases() {
		$cases = array();
		foreach ( array( 'broken', 'limit', 'unsupported', 'missing', 'chunk-failure' ) as $base ) {
			foreach ( array( 'child', 'dashboard' ) as $disk ) {
				$cases[ $base . '-' . $disk ] = array( $base, $disk );
			}
		}
		return $cases;
	}

	/** Numeric strings do not turn a usable incremental into a disk refusal. */
	public function test_incremental_with_base_and_numeric_readings_stays_incremental() {
		$this->dashboard_free = (string) ( 100 * MB_IN_BYTES );
		$exception            = $this->run_executor(
			'scheduled',
			'incremental',
			array(
				'disk_free'       => (string) ( 100 * MB_IN_BYTES ),
				'estimated_bytes' => (string) ( 5 * GB_IN_BYTES ),
			),
			true
		);
		$this->assertInstanceOf( MainWP_Exception::class, $exception );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT, $exception->get_message_error_code() );
		$this->assertSame( 'incremental', $this->child_calls[1][1]['type'] );
	}

	/** Each negotiation hands its own readings back to its caller. */
	public function test_preflight_returns_each_sites_negotiated_readings() {
		$first  = $this->create_test_site();
		$second = $this->create_test_site();
		$reads  = array(
			$first  => array(
				'disk_free'       => 3 * GB_IN_BYTES,
				'estimated_bytes' => 5 * GB_IN_BYTES,
			),
			$second => array(
				'disk_free'       => 4 * GB_IN_BYTES,
				'estimated_bytes' => 6 * GB_IN_BYTES,
			),
		);
		add_filter(
			'mainwp_fetch_url_authed_pre',
			static function ( $result, $website, $what ) use ( $reads ) {
				return 'backup_preflight' === $what ? $reads[ $website->id ] : $result;
			},
			10,
			3
		);
		foreach ( $reads as $site_id => $expected ) {
			$website = MainWP_DB::instance()->get_website_by_id( $site_id );
			$ext     = 'tar.gz';
			$this->assertSame( $expected, MainWP_Backup_Monitor::negotiate_backup_preflight( $website, $ext, null, true, 'incremental' ) );
		}
	}

	/**
	 * Full and escalated-full refusals use the same terminal recording path.
	 *
	 * @dataProvider recorded_refusal_cases
	 * @param string $type Requested backup type.
	 * @param string $disk Disk with insufficient room.
	 * @return void
	 */
	public function test_disk_refusal_records_history_diagnostics_and_site_mark( $type, $disk ) {
		global $wpdb;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$site_id = $this->create_test_site();
		$task    = MainWP_DB_Backup::instance()->add_backup_task( get_current_user_id(), 'Disk refusal fixture', 'daily', $type, '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'tar.gz', 0, 1, 150, 0, 0 );
		$this->assertIsObject( $task );
		$this->dashboard_free = 'dashboard' === $disk ? 100 * MB_IN_BYTES : 10 * GB_IN_BYTES;
		$this->mock_preflight( array( 'disk_free' => 'child' === $disk ? 100 * MB_IN_BYTES : 10 * GB_IN_BYTES ) );
		$polls     = array();
		$marks     = array();
		$preflight = array();
		$poll      = static function ( $payload, $duration, $context ) use ( &$polls ) {
			$polls[] = array( $payload, $context );
		};
		$mark      = static function ( $task_id, $site, $outcome, $detail ) use ( &$marks ) {
			$marks[] = array( $task_id, $site, $outcome, $detail );
		};
		$ready     = static function ( $site, $task_id, $readings, $status ) use ( &$preflight ) {
			$preflight[] = array( $site, $task_id, $readings, $status );
		};
		add_action( 'mainwp_backup_diag_poll', $poll, 10, 3 );
		add_action( 'mainwp_backup_diag_site_mark', $mark, 10, 4 );
		add_action( 'mainwp_backup_diag_preflight', $ready, 10, 4 );
		try {
			MainWP_Backup_TestRestore_Sandbox_Test::$free_space = $this->dashboard_free;
			MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, true );
		} finally {
			remove_action( 'mainwp_backup_diag_poll', $poll );
			remove_action( 'mainwp_backup_diag_site_mark', $mark );
			remove_action( 'mainwp_backup_diag_preflight', $ready );
		}
		$history = $wpdb->get_row( $wpdb->prepare( 'SELECT outcome, error_code FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_run_history' ) . ' WHERE task_id = %d AND site_id = %d', $task->id, $site_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed test table.
		$this->assertSame(
			array(
				'outcome'    => 'failed',
				'error_code' => MainWP_Backup_Error_Codes::ERR_DISK_FULL,
			),
			$history
		);
		$this->assertCount( 1, $marks );
		$this->assertSame( array( (int) $task->id, $site_id, 'error' ), array_slice( $marks[0], 0, 3 ) );
		$this->assertStringContainsString( MainWP_Backup_Error_Codes::ERR_DISK_FULL, $marks[0][3] );
		$this->assertCount( 1, $polls );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_DISK_FULL, $polls[0][0]['error_code'] );
		$this->assertFalse( $polls[0][0]['sent'] );
		$this->assertSame( (int) $task->id, $polls[0][1]['task_id'] );
		$this->assertSame( $site_id, $polls[0][1]['site_id'] );
		$this->assertSame( array( 'ok', 'failed' ), array_column( $preflight, 3 ) );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_DISK_FULL, $preflight[1][2]['error_code'] );
		$this->assertSame( array( 'backup_preflight' ), array_column( $this->child_calls, 0 ) );
		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( '', $progress->child_backup_task_id );
		$closed = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertTrue( json_decode( $closed->completed_sites, true )[ $site_id ] );
	}

	/**
	 * Compare both refusal points with either disk shortfall.
	 *
	 * @return array
	 */
	public static function recorded_refusal_cases() {
		return array(
			'full-child'            => array( 'full', 'child' ),
			'full-dashboard'        => array( 'full', 'dashboard' ),
			'incremental-child'     => array( 'incremental', 'child' ),
			'incremental-dashboard' => array( 'incremental', 'dashboard' ),
		);
	}
}
