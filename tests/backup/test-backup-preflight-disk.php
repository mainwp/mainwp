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
use MainWP\Dashboard\MainWP_Backup_Private_Root;
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
		MainWP_Backup_TestRestore_Sandbox_Test::$free_space     = null;
		MainWP_Backup_TestRestore_Sandbox_Test::$last_directory = null;
		$this->tear_down_private_root();
		parent::tearDown();
	}

	/**
	 * Supply readiness and stop at the first backup request.
	 *
	 * @param array $overrides Readiness fields.
	 * @param array $stop Mocked response after preflight.
	 * @return void
	 */
	private function mock_preflight( array $overrides, array $stop = array() ) {
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
			function ( $result, $website, $what, $params ) use ( $preflight, $stop ) {
				$this->child_calls[] = array( $what, $params );
				if ( 'backup_preflight' === $what ) {
					return $preflight;
				}
				return $stop ? $stop : array(
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
		$this->assertSame( $this->estimate_message( 'child', '3 GB' ), $exception->getMessage() );
		$this->assertSame( array( 'disk_estimate' => true ), $exception->get_data() );
		$this->assertEmpty( $exception->get_message_extra() );
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
		$this->assertSame( $this->estimate_message( 'dashboard', '3 GB' ), $exception->getMessage() );
		$this->assertSame( array( 'disk_estimate' => true ), $exception->get_data() );
		$this->assertEmpty( $exception->get_message_extra() );
		$this->assertSame( MainWP_Backup_Private_Root::resolve(), MainWP_Backup_TestRestore_Sandbox_Test::$last_directory );
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
	 * @param array       $options Continuation, owner, handle and sibling fixtures.
	 * @return mixed Executor result or refusal.
	 */
	private function run_executor( $path, $type, array $overrides, $with_base = false, array $options = array() ) {
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
		if ( ! empty( $options['sibling'] ) ) {
			$db     = MainWP_DB_Backup::instance();
			$holder = $db->add_backup_task( $website->userid, 'Sibling fixture', 'daily', 'full', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'tar.gz', 0, 1, 150, 0, 0 );
			$this->assertIsObject( $holder );
			$db->update_backup_task_with_values(
				$holder->id,
				array(
					'last_run'  => time() - 50,
					'completed' => 0,
				)
			);
			$db->add_backup_task_progress( $holder->id, $site_id, array() );
			$db->update_backup_task_progress(
				$holder->id,
				$site_id,
				array(
					'dtsFetched'           => time(),
					'child_backup_task_id' => 'task-sibling-live',
				)
			);
			$this->assertSame( (int) $holder->id, $db->get_sibling_site_holder( $site_id, $task->id ) );
		}
		if ( isset( $options['handle'] ) ) {
			MainWP_DB::instance()->update_website_option( $website, 'adhoc_backup_task', wp_json_encode( $options['handle'] ) );
		}
		$this->mock_preflight( $overrides, $options['stop'] ?? array() );
		try {
			if ( 'scheduled' === $path ) {
				return MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
			} elseif ( 'run-now' === $path ) {
				return MainWP_Backup_Executor::backup( $site_id, $type, '', '', 0, 0, 0, 0, null, '', false, false, false, false, false, false, $options['append'] ?? false );
			} else {
				return MainWP_Backup_Executor::trigger_backup( $site_id, $type, $options['owner'] ?? '' );
			}
		} catch ( MainWP_Exception $e ) {
			return $e;
		}
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

	/**
	 * The sibling's partial archive has already consumed space on the Child,
	 * but not on the Dashboard, and the holder row may outlive its Child task.
	 *
	 * @dataProvider sibling_disk_cases
	 * @param string      $type Requested type.
	 * @param bool|string $base Incremental fixture.
	 * @param int         $child_free Child free bytes.
	 * @param int         $dashboard_free Dashboard free bytes.
	 * @param string      $expect yield|dashboard|floor.
	 * @return void
	 */
	public function test_sibling_holder_skips_only_child_estimate( $type, $base, $child_free, $dashboard_free, $expect ) {
		$this->dashboard_free = $dashboard_free;
		$reasons              = array();
		$capture              = static function ( $task_id, $reason ) use ( &$reasons ) {
			$reasons[] = $reason;
		};
		add_action( 'mainwp_backup_diag_run_reinit', $capture, 10, 2 );
		try {
			$result = $this->run_executor(
				'scheduled',
				$type,
				array(
					'disk_free'      => $child_free,
					'manifest_chunk' => 1,
				),
				$base,
				array(
					'sibling' => true,
					'stop'    => array(
						'error'      => 'Sibling holds the site.',
						'error_code' => MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING,
					),
				)
			);
		} finally {
			remove_action( 'mainwp_backup_diag_run_reinit', $capture );
		}
		if ( 'floor' === $expect ) {
			$this->assertInstanceOf( MainWP_Exception::class, $result );
			$this->assertSame( MainWP_Backup_Error_Codes::ERR_DISK_FULL, $result->get_message_error_code() );
			$this->assertEmpty( $result->get_data() );
			$this->assertSame( array( 'backup_preflight' ), array_column( $this->child_calls, 0 ) );
		} elseif ( 'dashboard' === $expect ) {
			$this->assertInstanceOf( MainWP_Exception::class, $result );
			$this->assertSame( $this->estimate_message( 'dashboard', '100 MB' ), $result->getMessage() );
			$this->assertNotContains( 'backup_task_create', array_column( $this->child_calls, 0 ) );
		} else {
			$this->assertFalse( $result );
			$this->assertContains( 'sibling_holds_site_yield', $reasons );
			$expected = 'chunk-failure' === $base ? array( 'backup_preflight', 'backup_manifest_chunk', 'backup_task_create' ) : array( 'backup_preflight', 'backup_task_create' );
			$this->assertSame( $expected, array_column( $this->child_calls, 0 ) );
			$this->assertSame( 'files' === $type ? 'files' : 'full', end( $this->child_calls )[1]['type'] );
		}
	}

	/**
	 * Both disks may be short while an existing archive holds the site.
	 *
	 * @return array
	 */
	public static function sibling_disk_cases() {
		return array(
			'full-child-short'                => array( 'full', false, 100 * MB_IN_BYTES, 10 * GB_IN_BYTES, 'yield' ),
			'files-child-short'               => array( 'files', false, 100 * MB_IN_BYTES, 10 * GB_IN_BYTES, 'yield' ),
			'incremental-no-base-child-short' => array( 'incremental', false, 100 * MB_IN_BYTES, 10 * GB_IN_BYTES, 'yield' ),
			'incremental-chunk-child-short'   => array( 'incremental', 'chunk-failure', 100 * MB_IN_BYTES, 10 * GB_IN_BYTES, 'yield' ),
			'full-dashboard-short'            => array( 'full', false, 10 * GB_IN_BYTES, 100 * MB_IN_BYTES, 'dashboard' ),
			'files-dashboard-short'           => array( 'files', false, 10 * GB_IN_BYTES, 100 * MB_IN_BYTES, 'dashboard' ),
			'incremental-no-base-dashboard'   => array( 'incremental', false, 10 * GB_IN_BYTES, 100 * MB_IN_BYTES, 'dashboard' ),
			'incremental-chunk-dashboard'     => array( 'incremental', 'chunk-failure', 10 * GB_IN_BYTES, 100 * MB_IN_BYTES, 'dashboard' ),
			'50mb-still-refuses'              => array( 'full', false, 49 * MB_IN_BYTES, 10 * GB_IN_BYTES, 'floor' ),
		);
	}

	/**
	 * Resuming an owned archive needs no capacity for another whole archive.
	 *
	 * @dataProvider owned_handle_cases
	 * @param string $owner Handle owner.
	 * @param string $type Requested type.
	 * @param string $saved_type Persisted type.
	 * @return void
	 */
	public function test_owned_handle_resumes_after_preflight_without_estimate_check( $owner, $type, $saved_type ) {
		$this->dashboard_free = 100 * MB_IN_BYTES;
		$result               = $this->run_executor(
			'adhoc',
			$type,
			array( 'disk_free' => 100 * MB_IN_BYTES ),
			false,
			array(
				'owner'  => $owner,
				'handle' => array(
					'task_id' => 'task-owned',
					'type'    => $saved_type,
					'owner'   => $owner,
					'started' => time(),
				),
			)
		);
		$this->assertSame( 'task-owned', $result );
		$this->assertSame( array( 'backup_preflight' ), array_column( $this->child_calls, 0 ) );
	}

	/**
	 * Older persisted handles may omit the type.
	 *
	 * @return array
	 */
	public static function owned_handle_cases() {
		$cases = array();
		foreach ( array(
			''    => 'update',
			'123' => 'restore',
		) as $owner => $label ) {
			foreach ( array( 'full', 'files' ) as $type ) {
				foreach ( array( $type, '' ) as $saved_type ) {
					$cases[ $label . '-' . $type . '-' . ( $saved_type ? 'same' : 'empty' ) ] = array( (string) $owner, $type, $saved_type );
				}
			}
		}
		return $cases;
	}

	/**
	 * Restore safety archives remain on the Child.
	 *
	 * @dataProvider restore_create_cases
	 * @param string $type Requested type.
	 * @param string $disk Short disk.
	 * @return void
	 */
	public function test_restore_safety_create_checks_only_child_disk( $type, $disk ) {
		$this->dashboard_free = 100 * MB_IN_BYTES;
		$result               = $this->run_executor(
			'adhoc',
			$type,
			array( 'disk_free' => 'child' === $disk ? 100 * MB_IN_BYTES : 10 * GB_IN_BYTES ),
			false,
			array(
				'owner' => '123',
				'stop'  => array(
					'task_id' => 'task-safety',
					'phase'   => 'queued',
				),
			)
		);
		if ( 'child' === $disk ) {
			$this->assertInstanceOf( MainWP_Exception::class, $result );
			$this->assertSame( MainWP_Backup_Error_Codes::ERR_DISK_FULL, $result->get_message_error_code() );
			$this->assertSame( array( 'backup_preflight' ), array_column( $this->child_calls, 0 ) );
		} else {
			$this->assertSame( 'task-safety', $result );
			$this->assertSame( array( 'backup_preflight', 'backup_task_create' ), array_column( $this->child_calls, 0 ) );
		}
	}

	/**
	 * File-including restore types use only the Child's capacity.
	 *
	 * @return array
	 */
	public static function restore_create_cases() {
		return array(
			'full-child'      => array( 'full', 'child' ),
			'full-dashboard'  => array( 'full', 'dashboard' ),
			'files-child'     => array( 'files', 'child' ),
			'files-dashboard' => array( 'files', 'dashboard' ),
		);
	}

	/**
	 * A live handle owned elsewhere must retain the ownership refusal.
	 *
	 * @dataProvider foreign_handle_cases
	 * @param string $owner Requested owner.
	 * @param string $saved_owner Persisted owner.
	 * @return void
	 */
	public function test_other_owner_keeps_already_running_refusal( $owner, $saved_owner ) {
		$this->dashboard_free = 100 * MB_IN_BYTES;
		$result               = $this->run_executor(
			'adhoc',
			'full',
			array( 'disk_free' => 100 * MB_IN_BYTES ),
			false,
			array(
				'owner'  => $owner,
				'handle' => array(
					'task_id' => 'task-foreign',
					'type'    => 'full',
					'owner'   => $saved_owner,
					'started' => time(),
				),
			)
		);
		$this->assertInstanceOf( MainWP_Exception::class, $result );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING, $result->get_message_error_code() );
		$this->assertSame( MainWP_Backup_Error_Codes::message( MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING ), $result->getMessage() );
		$this->assertSame( array( 'backup_preflight' ), array_column( $this->child_calls, 0 ) );
	}

	/**
	 * Update gates and restore jobs can each hold the ad-hoc slot.
	 *
	 * @return array
	 */
	public static function foreign_handle_cases() {
		return array(
			'update-vs-restore' => array( '', '123' ),
			'restore-vs-update' => array( '123', '' ),
		);
	}

	/**
	 * Append continues an archive already on the Child, which the Dashboard
	 * has not downloaded yet.
	 *
	 * @dataProvider append_cases
	 * @param string $type Requested type.
	 * @param mixed  $append Legacy continuation flag.
	 * @param string $short Short disk: child|dashboard.
	 * @return void
	 */
	public function test_legacy_append_skips_only_child_estimate( $type, $append, $short ) {
		$this->dashboard_free = 'dashboard' === $short ? 100 * MB_IN_BYTES : 10 * GB_IN_BYTES;
		$child_free           = 'child' === $short ? 100 * MB_IN_BYTES : 10 * GB_IN_BYTES;
		$result               = $this->run_executor( 'run-now', $type, array( 'disk_free' => $child_free ), false, array( 'append' => $append ) );
		$this->assertInstanceOf( MainWP_Exception::class, $result );
		$proceeds = $append && 'child' === $short;
		$this->assertSame( $proceeds ? MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT : MainWP_Backup_Error_Codes::ERR_DISK_FULL, $result->get_message_error_code() );
		$this->assertSame( $proceeds ? array( 'backup_preflight', 'backup' ) : array( 'backup_preflight' ), array_column( $this->child_calls, 0 ) );
		if ( $proceeds ) {
			$this->assertEquals( 1, $this->child_calls[1][1]['append'] );
		} else {
			$this->assertSame( $this->estimate_message( $short, '100 MB' ), $result->getMessage() );
		}
	}

	/**
	 * The browser uses integer one; truthy legacy values share that behavior.
	 *
	 * @return array
	 */
	public static function append_cases() {
		return array(
			'full-append-child-short'      => array( 'full', 1, 'child' ),
			'files-append-child-short'     => array( 'files', '1', 'child' ),
			'full-append-dashboard-short'  => array( 'full', 2, 'dashboard' ),
			'files-append-dashboard-short' => array( 'files', 1, 'dashboard' ),
			'fresh-control'                => array( 'full', false, 'child' ),
		);
	}

	/**
	 * The estimate remedies differ from generic disk-full failures.
	 *
	 * @dataProvider handler_message_cases
	 * @param string $disk Refusal source.
	 * @return void
	 */
	public function test_handler_preserves_estimate_message_and_registry_controls( $disk ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$site_id = $this->create_test_site();
		$task    = MainWP_DB_Backup::instance()->add_backup_task( get_current_user_id(), 'Disk message fixture', 'daily', 'full', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'tar.gz', 0, 1, 150, 0, 0 );
		$this->assertIsObject( $task );
		MainWP_Backup_TestRestore_Sandbox_Test::$free_space = 'dashboard' === $disk ? 100 * MB_IN_BYTES : 10 * GB_IN_BYTES;
		$child_free = '50mb' === $disk ? 49 * MB_IN_BYTES : ( 'child' === $disk ? 100 * MB_IN_BYTES : 10 * GB_IN_BYTES );
		$this->mock_preflight(
			array( 'disk_free' => $child_free ),
			array(
				'error'      => 'Child reported disk full.',
				'error_code' => MainWP_Backup_Error_Codes::ERR_DISK_FULL,
			)
		);
		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, true );
		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$expected = in_array( $disk, array( 'child', 'dashboard' ), true ) ? $this->estimate_message( $disk, '100 MB' ) : MainWP_Backup_Error_Codes::message( MainWP_Backup_Error_Codes::ERR_DISK_FULL );
		$this->assertSame( $expected, $progress->last_error );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_DISK_FULL, $progress->fetchResult['error_code'] );
	}

	/**
	 * Unmarked low-space and Child errors must keep the registry message.
	 *
	 * @return array
	 */
	public static function handler_message_cases() {
		return array(
			'child'          => array( 'child' ),
			'dashboard'      => array( 'dashboard' ),
			'50mb'           => array( '50mb' ),
			'child-reported' => array( 'child-reported' ),
		);
	}

	/**
	 * Excludes do not reduce the Child's estimate.
	 *
	 * @param string $disk Short disk.
	 * @param string $free Formatted free bytes.
	 * @return string
	 */
	private function estimate_message( $disk, $free ) {
		$message = 'child' === $disk
			? 'The child site does not have enough disk space for this backup: estimated site size %1$s, free space %2$s. No backup was started. The estimate counts the database and the wp-content folder of the child site, including excluded folders. Free up space on the child site and try again.'
			: 'The Dashboard server does not have enough disk space to store this backup: estimated site size %1$s, free space %2$s. No backup was started. The estimate counts the database and the wp-content folder of the child site, including excluded folders. Free up space on the Dashboard server and try again.';
		return sprintf( $message, '5 GB', $free );
	}
}
