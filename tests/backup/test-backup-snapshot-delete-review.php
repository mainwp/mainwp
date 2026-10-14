<?php
/**
 * Single-delete admission must survive download and reader interleavings.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard;

/**
 * Capture shutdown callbacks only while a regression simulates request death.
 *
 * @param callable $callback Shutdown cleanup.
 * @return void
 */
function register_shutdown_function( callable $callback ) {
	if ( null !== Tests\MainWP_Backup_Snapshot_Delete_Review_Test::$shutdown_callbacks ) {
		Tests\MainWP_Backup_Snapshot_Delete_Review_Test::$shutdown_callbacks[] = $callback;
		return;
	}
	\register_shutdown_function( $callback );
}

namespace MainWP\Dashboard\Tests; // phpcs:ignore Universal.Namespaces.OneDeclarationPerFile.MultipleFound -- The switchable shutdown stub belongs with its tests.

use MainWP\Dashboard\MainWP_Abilities_Backups;
use MainWP\Dashboard\MainWP_Backup_Executor;
use MainWP\Dashboard\MainWP_Connect;
use MainWP\Dashboard\MainWP_Exception;
use MainWP\Dashboard\MainWP_Logger;
use MainWP\Dashboard\MainWP_Backup_Operations;
use MainWP\Dashboard\MainWP_Backup_Retention_Executor;
use MainWP\Dashboard\MainWP_Backup_Retention_Query;
use MainWP\Dashboard\MainWP_Backup_Restore_Job_Store;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Post_Backup_Handler;

require_once __DIR__ . '/trait-snapshot-delete-fixture.php';

/** Real rows and files expose holds that a manual-task fixture bypasses. */
class MainWP_Backup_Snapshot_Delete_Review_Test extends MainWP_Abilities_Test_Case { // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed, Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- The shutdown stub shares this test file.
	use Snapshot_Delete_Fixture {
		tear_down as fixture_tear_down;
	}

	/**
	 * Null delegates to PHP; an array captures callbacks for simulated shutdown.
	 *
	 * @var array|null
	 */
	public static $shutdown_callbacks = null;

	/**
	 * Reservation allocated by the lane matrix.
	 *
	 * @var int
	 */
	private $lane_reservation = 0;

	/**
	 * Open archive reader from the lane matrix.
	 *
	 * @var resource|null
	 */
	private $lane_reader = null;

	/** Remove the marker and release resources owned by this case. */
	public function tear_down() {
		self::$shutdown_callbacks = null;
		delete_option( 'mainwp_backup_in_progress_' . $this->site_id );
		$this->clear_adhoc_handle();
		if ( $this->lane_reservation > 0 ) {
			MainWP_Backup_Restore_Job_Store::instance()->release_reservation( $this->lane_reservation );
		}
		if ( is_resource( $this->lane_reader ) ) {
			fclose( $this->lane_reader );
		}
		MainWP_Backup_Incremental_Sender_Test::$fixed_time = null;
		$this->fixture_tear_down();
	}

	/**
	 * Capture the list without ending PHPUnit.
	 *
	 * @return array
	 */
	private function snapshot_list() {
		$action   = 'mainwp_restore_snapshot_list';
		$_POST    = array(
			'site_id'  => $this->site_id,
			'security' => wp_create_nonce( $action ),
		);
		$_REQUEST = $_POST;
		$die      = static function () {
			return static function () {
				throw new \RuntimeException( 'snapshot-list-end' );
			};
		};
		$ajax     = static function () {
			return true;
		};
		add_filter( 'wp_doing_ajax', $ajax );
		add_filter( 'wp_die_ajax_handler', $die );
		ob_start();
		try {
			MainWP_Post_Backup_Handler::instance()->mainwp_restore_snapshot_list();
		} catch ( \RuntimeException $error ) {
			$this->assertSame( 'snapshot-list-end', $error->getMessage() );
		} finally {
			$raw = ob_get_clean();
			remove_filter( 'wp_die_ajax_handler', $die );
			remove_filter( 'wp_doing_ajax', $ajax );
		}
		return json_decode( $raw, true );
	}

	/**
	 * A sweep claim precedes the downloads and the runner's completion mark.
	 *
	 * @param int $removed Sweep claim.
	 */
	private function start_download( $removed = 1 ) {
		global $wpdb;
		$started = time() + 1;
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			$this->task->id,
			array(
				'last_run'        => $started,
				'completed'       => 0,
				'completed_sites' => '[]',
			)
		);
		$wpdb->insert(
			MainWP_DB::instance()->get_table_name( 'wp_backup_progress' ),
			array(
				'task_id'              => $this->task->id,
				'wp_id'                => $this->site_id,
				'dtsFetched'           => $started,
				'removedFiles'         => $removed,
				'child_phase'          => 'complete',
				'child_backup_task_id' => 'scheduled-download',
			)
		);
		foreach ( MainWP_Backup_Snapshots::instance()->get_snapshots_for_site( $this->site_id ) as $row ) {
			$this->assertLessThan( $started, (int) $row->created );
		}
	}

	/**
	 * All entry points use the same admission evidence.
	 *
	 * @return array
	 */
	public static function admission_paths() {
		return array( array( 'preview' ), array( 'list' ), array( 'confirm' ) );
	}

	/**
	 * No current-run snapshot exists while the scheduled archives download.
	 *
	 * @dataProvider admission_paths
	 * @param string $path Entry point.
	 */
	public function test_scheduled_download_holds_each_entry_point( $path ) {
		$this->use_scheduled_task();
		$id   = $this->snapshot();
		$plan = $this->preview( $id );
		$this->start_download();
		if ( 'preview' === $path ) {
			$this->assertFalse( $this->preview( $id )['can_proceed'] );
		} elseif ( 'list' === $path ) {
			$this->assertTrue( $this->snapshot_list()['snapshot_delete_busy'] );
		} else {
			$result = $this->confirm( $id, $plan );
			$this->assertSame( 'refused', $result['status'] );
			$this->assertSame( array(), $result['deleted_ids'] );
		}
		$row = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id );
		$this->assertSame( 'complete', $row->status );
		$this->assertFileExists( $row->archives[0]['path'] );
	}

	/**
	 * The completion mark also retires a site that finished with an error.
	 *
	 * @return array
	 */
	public static function sweep_claims() {
		return array( array( 0 ), array( 1 ) );
	}

	/**
	 * A paused fleet task must release each finished site independently.
	 *
	 * @dataProvider sweep_claims
	 * @param int $removed Sweep flag.
	 */
	public function test_completed_scheduled_site_releases_all_entry_points( $removed ) {
		$this->use_scheduled_task();
		$id = $this->snapshot();
		$this->start_download( $removed );
		MainWP_DB_Backup::instance()->update_completed_sites( $this->task->id, array( $this->site_id => true ) );
		$plan = $this->preview( $id );
		$this->assertTrue( $plan['can_proceed'] );
		$this->assertFalse( $this->snapshot_list()['snapshot_delete_busy'] );
		$this->assertSame( 'complete', $this->confirm( $id, $plan )['status'] );
	}

	/**
	 * Every lane that writes a backup for the site, with its idle and busy evidence.
	 *
	 * @return array
	 */
	public static function running_lanes() {
		return array(
			'L01 scheduled'          => array( 'scheduled_download', true ),
			'L02 incremental'        => array( 'scheduled_incremental', true ),
			'L03 Back Up Now'        => array( 'manual_open', true ),
			'L04 required create'    => array( 'marker', true ),
			'L05 browser create'     => array( 'marker', true ),
			'L06 task create'        => array( 'marker', true ),
			'L07 compatibility'      => array( 'marker', true ),
			'L08 adhoc create'       => array( 'marker', true ),
			'L09 owned handle'       => array( 'adhoc_driven', true ),
			'L10 safety backup'      => array( 'restore', true ),
			'L11 PID recovery'       => array( 'marker', true ),
			'L12 browser transfer'   => array( 'marker', true ),
			'L13 restore reader'     => array( 'restore', true ),
			'L14 test restore'       => array( 'reservation', true ),
			'L15 archive stream'     => array( 'stream', false ),
			'L16 retention'          => array( 'reservation', true ),
			'L17 snapshot cleanup'   => array( 'reservation', true ),
			'L18 orphan sweep'       => array( 'orphan', false ),
			'L19 other delete'       => array( 'reservation', true ),
			'L20 upload reader'      => array( 'upload', false ),
			'scheduled before sweep' => array( 'scheduled_unswept', true ),
			'scheduled completed'    => array( 'scheduled_completed', false ),
			'scheduled not started'  => array( 'scheduled_not_started', false ),
			'scheduled failed'       => array( 'scheduled_failed', false ),
			'manual closed'          => array( 'manual_closed', false ),
			'handle cleared'         => array( 'adhoc_cleared', false ),
			'handle without task'    => array( 'adhoc_no_task', false ),
			'handle abandoned'       => array( 'adhoc_abandoned', false ),
			'handle last drive old'  => array( 'adhoc_drive_expired', false ),
		);
	}

	/**
	 * One predicate answers for every backup lane, so each lane is pinned here.
	 *
	 * @dataProvider running_lanes
	 * @param string $lane Lane state.
	 * @param bool   $held Expected hold.
	 */
	public function test_running_backup_predicate_covers_every_lane( $lane, $held ) {
		$this->assertFalse( MainWP_Backup_Operations::snapshot_delete_busy( $this->site_id ), 'Idle inventory lane.' );
		$this->set_lane( $lane );
		$this->assertSame( $held, MainWP_Backup_Operations::snapshot_delete_busy( $this->site_id ), 'Active inventory lane.' );
	}

	/**
	 * The pre-update lane keeps no task or progress row, only the site handle.
	 *
	 * @dataProvider admission_paths
	 * @param string $path Entry point.
	 */
	public function test_pre_update_backup_holds_each_entry_point( $path ) {
		$id   = $this->snapshot();
		$plan = $this->preview( $id );
		try {
			$this->set_lane( 'adhoc_fresh' );
			if ( 'preview' === $path ) {
				$preview = $this->preview( $id );
				$this->assertFalse( $preview['can_proceed'] );
				$this->assertSame( MainWP_Backup_Operations::snapshot_delete_hold_message( false ), $preview['refusals'][0]['message'] );
			} elseif ( 'list' === $path ) {
				$this->assertTrue( $this->snapshot_list()['snapshot_delete_busy'] );
			} else {
				$result = $this->confirm( $id, $plan );
				$this->assertSame( 'refused', $result['status'] );
				$this->assertSame( array(), $result['deleted_ids'] );
			}
		} finally {
			$this->clear_adhoc_handle();
		}
		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id )->status );
	}

	/**
	 * An owner can still resume a handle after the foreign-owner window.
	 *
	 * @dataProvider admission_paths
	 * @param string $path Entry point.
	 */
	public function test_old_pre_update_handle_holds_each_entry_point( $path ) {
		$id   = $this->snapshot();
		$plan = $this->preview( $id );
		$this->set_lane( 'adhoc_driven' );
		$this->assert_admission_held( $path, $id, $plan );
		$this->clear_adhoc_handle();
		$this->assertFalse( MainWP_Backup_Operations::snapshot_delete_busy( $this->site_id ) );
	}

	/**
	 * All new evidence must reach preview, list and confirmation.
	 *
	 * @param string $path Admission path.
	 * @param int    $id Snapshot id.
	 * @param array  $plan Preview captured before work started.
	 */
	private function assert_admission_held( $path, $id, $plan ) {
		if ( 'preview' === $path ) {
			$result = $this->preview( $id );
			$this->assertFalse( $result['can_proceed'] );
			$this->assertSame( 'A backup is running on this site. Try again when it finishes.', $result['refusals'][0]['message'] );
		} elseif ( 'list' === $path ) {
			$this->assertTrue( $this->snapshot_list()['snapshot_delete_busy'] );
		} else {
			$result = $this->confirm( $id, $plan );
			$this->assertSame( 'refused', $result['status'] );
			$this->assertSame( array(), $result['deleted_ids'] );
		}
		$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id )->status );
	}

	/**
	 * Transport boundaries expose each new lane before it has other evidence.
	 *
	 * @return array
	 */
	public static function new_lane_paths() {
		$rows = array();
		foreach ( array( 'sync', 'sync_browser', 'sync_direct', 'create', 'pid', 'download' ) as $lane ) {
			foreach ( array( 'preview', 'list', 'confirm' ) as $path ) {
				$rows[ $lane . ' ' . $path ] = array( $lane, $path );
			}
		}
		return $rows;
	}

	/**
	 * Exercise the real executor/handler while only Child transport is replaced.
	 *
	 * @dataProvider new_lane_paths
	 * @param string $lane Driver.
	 * @param string $path Admission path.
	 */
	public function test_new_backup_lanes_hold_each_entry_point( $lane, $path ) {
		if ( 'download' === $lane ) {
			global $wpdb;
			$key = openssl_pkey_new( array( 'private_key_bits' => 2048 ) );
			openssl_pkey_export( $key, $pem );
			$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp' ), array( 'privkey' => base64_encode( $pem ) ), array( 'id' => $this->site_id ) );
		}
		$id   = $this->snapshot();
		$plan = $this->preview( $id );
		MainWP_Backup_Incremental_Sender_Test::$fixed_time = time();
		$actions  = array(
			'sync'         => 'mainwp_backup_run_site',
			'sync_browser' => 'mainwp_backup',
			'pid'          => 'mainwp_backup_checkpid',
		);
		$action   = $actions[ $lane ] ?? 'mainwp_backup_download_file';
		$_POST    = array(
			'site_id'  => $this->site_id,
			'security' => wp_create_nonce( $action ),
			'pid'      => 1,
			'type'     => 'full',
			'url'      => 'backup.zip',
			'local'    => dirname( MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id )->archives[0]['path'] ) . '/incoming.zip',
		);
		$_REQUEST = $_POST;
		$seen     = false;
		$reply    = function ( $pre, $website, $what ) use ( $lane, $path, $id, $plan, &$seen ) {
			if ( 'backup_preflight' === $what ) {
				return array( 'task_spine' => 1 );
			}
			$target = array(
				'sync'         => 'backup',
				'sync_browser' => 'backup',
				'sync_direct'  => 'backup',
				'create'       => 'backup_task_create',
				'pid'          => 'backup_checkpid',
			);
			if ( ( $target[ $lane ] ?? '' ) === $what ) {
				$seen = true;
				$this->assert_admission_held( $path, $id, $plan );
				return 'create' === $lane ? array( 'task_id' => 'created-handle' ) : ( 'pid' === $lane ? array( 'status' => 'busy' ) : array(
					'full' => 'backup.zip',
					'size' => 6,
				) );
			}
			return $pre;
		};
		$download = function () use ( $path, $id, $plan, &$seen ) {
			$seen = true;
			$this->assert_admission_held( $path, $id, $plan );
			throw new \RuntimeException( 'download-observed' );
		};
		$die      = static function () {
			return static function () {
				throw new \RuntimeException( 'response-observed' );
			};
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $reply, 20, 3 );
		add_filter( 'mainwp_download_to_file_pre', $download );
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $die );
		ob_start();
		try {
			if ( 'sync' === $lane ) {
				MainWP_Post_Backup_Handler::instance()->mainwp_backup_run_site();
			} elseif ( 'sync_browser' === $lane ) {
				MainWP_Post_Backup_Handler::instance()->ajax_mainwp_backup();
			} elseif ( 'sync_direct' === $lane ) {
				MainWP_Backup_Executor::backup( $this->site_id, 'full', '', '', 1, 1, 1, 1 );
			} elseif ( 'create' === $lane ) {
				MainWP_Backup_Executor::trigger_backup( $this->site_id );
			} elseif ( 'pid' === $lane ) {
				MainWP_Post_Backup_Handler::instance()->mainwp_backup_checkpid();
			} else {
				MainWP_Post_Backup_Handler::instance()->ajax_mainwp_backup_download_file();
			}
		} catch ( \RuntimeException $error ) {
			$this->assertContains( $error->getMessage(), array( 'download-observed', 'response-observed' ) );
		} finally {
			ob_end_clean();
			remove_filter( 'mainwp_fetch_url_authed_pre', $reply, 20 );
			remove_filter( 'mainwp_download_to_file_pre', $download );
			remove_filter( 'wp_doing_ajax', '__return_true' );
			remove_filter( 'wp_die_ajax_handler', $die );
		}
		$this->assertTrue( $seen, 'The requested driver reached its transport.' );
		if ( 'sync_direct' === $lane ) {
			$this->assertFalse( MainWP_Backup_Operations::snapshot_delete_busy( $this->site_id ) );
		} elseif ( 'create' === $lane ) {
			$this->assertTrue( MainWP_Backup_Operations::snapshot_delete_busy( $this->site_id ) );
			$this->clear_adhoc_handle();
			$this->assertFalse( MainWP_Backup_Operations::snapshot_delete_busy( $this->site_id ) );
		} else {
			MainWP_Backup_Incremental_Sender_Test::$fixed_time += 59;
			$this->assertTrue( MainWP_Backup_Operations::snapshot_delete_busy( $this->site_id ) );
			++MainWP_Backup_Incremental_Sender_Test::$fixed_time;
			$this->assertFalse( MainWP_Backup_Operations::snapshot_delete_busy( $this->site_id ) );
		}
	}

	/**
	 * Owner drive paths share the same persisted heartbeat.
	 *
	 * @return array
	 */
	public static function owner_drive_paths() {
		return array( array( 'resume', '' ), array( 'resume', '42' ), array( 'budget', '' ) );
	}

	/**
	 * Resuming and spending a run budget both renew an old owned handle.
	 *
	 * @dataProvider owner_drive_paths
	 * @param string $drive Owner entry point.
	 * @param string $owner Handle owner.
	 */
	public function test_s93_r1_owner_drive_stamps_handle( $drive, $owner ) {
		MainWP_Backup_Incremental_Sender_Test::$fixed_time = time();
		$now    = MainWP_Backup_Incremental_Sender_Test::$fixed_time;
		$db     = MainWP_DB::instance();
		$handle = array(
			'task_id'   => 'owned-task',
			'type'      => 'full',
			'started'   => $now - 14400,
			'driven_at' => $now - 7201,
			'owner'     => $owner,
			'local'     => 'keep-local-path',
		);
		$db->update_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION, wp_json_encode( $handle ) );
		$reply = static function ( $pre, $site, $what ) {
			if ( 'backup_preflight' === $what ) {
				return array( 'task_spine' => 1 );
			}
			if ( 'backup_task_run' === $what ) {
				throw new \RuntimeException( 'budget-observed' );
			}
			return $pre;
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $reply, 20, 3 );
		try {
			if ( 'resume' === $drive ) {
				$this->assertSame( 'owned-task', MainWP_Backup_Executor::trigger_backup( $this->site_id, 'full', $owner ) );
			} else {
				try {
					MainWP_Backup_Executor::get_backup_task_status( $this->site_id, 'owned-task', 1 );
					$this->fail( 'The budgeted driver did not dispatch.' );
				} catch ( \RuntimeException $error ) {
					$this->assertSame( 'budget-observed', $error->getMessage() );
				}
			}
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $reply, 20 );
		}
		$handle['driven_at'] = $now;
		$this->assertSame( $handle, json_decode( $db->get_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION ), true ) );
		$this->assertTrue( MainWP_Backup_Operations::snapshot_delete_busy( $this->site_id ) );
		MainWP_Backup_Incremental_Sender_Test::$fixed_time += 7201;
		$this->assertFalse( MainWP_Backup_Operations::snapshot_delete_busy( $this->site_id ) );
	}

	/** Shutdown removes only the unfinished request's token; normal exit is inert. */
	public function test_s93_r1_shutdown_releases_unfinished_request() {
		self::$shutdown_callbacks = array();
		$fiber                    = new \Fiber(
			function () {
				MainWP_Backup_Executor::with_backup_hold(
					$this->site_id,
					static function () {
						apply_filters( 'mainwp_fetch_url_site_timeout', 72000 );
						\Fiber::suspend();
					}
				);
			}
		);
		try {
			$fiber->start();
			$this->assertTrue( MainWP_Backup_Operations::snapshot_delete_busy( $this->site_id ) );
			foreach ( self::$shutdown_callbacks as $callback ) {
				$callback();
			}
			$this->assertFalse( MainWP_Backup_Operations::snapshot_delete_busy( $this->site_id ), 'Shutdown must release a request whose finally did not run.' );
		} finally {
			if ( $fiber->isSuspended() ) {
				$fiber->resume();
			}
		}
		self::$shutdown_callbacks = array();
		MainWP_Backup_Executor::with_backup_hold( $this->site_id, '__return_true', true );
		global $wpdb;
		$reads = 0;
		$count = static function ( $query ) use ( &$reads ) {
			if ( str_contains( $query, 'mainwp_backup_in_progress_' ) ) {
				++$reads;
			}
			return $query;
		};
		add_filter( 'query', $count );
		try {
			foreach ( self::$shutdown_callbacks as $callback ) {
				$callback();
			}
			$this->assertSame( 0, $reads, 'Completed scopes do nothing at shutdown.' );
		} finally {
			remove_filter( 'query', $count );
		}
		$this->assertTrue( MainWP_Backup_Operations::snapshot_delete_busy( $this->site_id ), 'The browser owner survives shutdown.' );
	}

	/**
	 * Malformed owner sets must be repairable without dropping valid siblings.
	 *
	 * @return array
	 */
	public static function malformed_markers() {
		return array( array( 'null' ), array( '"broken"' ), array( '{"bad":"never","sibling":9999999999}' ) );
	}

	/**
	 * Readers fail closed until the next writer repairs the marker with a CAS.
	 *
	 * @dataProvider malformed_markers
	 * @param string $raw Stored malformed JSON.
	 */
	public function test_s93_r1_next_writer_repairs_malformed_marker( $raw ) {
		$key = 'mainwp_backup_in_progress_' . $this->site_id;
		update_option( $key, $raw, false );
		$this->assertTrue( MainWP_Backup_Operations::snapshot_delete_busy( $this->site_id ) );
		try {
			MainWP_Backup_Executor::with_backup_hold(
				$this->site_id,
				function () {
					$this->assertTrue( MainWP_Backup_Operations::snapshot_delete_busy( $this->site_id ) );
				}
			);
		} catch ( MainWP_Exception $error ) {
			$this->fail( 'The next writer must repair the malformed marker: ' . $error->getMessage() );
		}
		global $wpdb;
		$owners = json_decode( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) ), true );
		$this->assertSame( str_contains( $raw, 'sibling' ) ? array( 'sibling' => 9999999999 ) : array(), $owners );
		$this->assertSame( str_contains( $raw, 'sibling' ), MainWP_Backup_Operations::snapshot_delete_busy( $this->site_id ) );
	}

	/** Fast chunks skip marker reads; elapsed intervals and larger deadlines renew. */
	public function test_s93_r1_refresh_throttles_marker_reads() {
		MainWP_Backup_Incremental_Sender_Test::$fixed_time = time();
		$reads = 0;
		$count = static function ( $query ) use ( &$reads ) {
			if ( str_starts_with( $query, 'SELECT option_value' ) && str_contains( $query, 'mainwp_backup_in_progress_' ) ) {
				++$reads;
			}
			return $query;
		};
		add_filter( 'query', $count );
		try {
			MainWP_Backup_Executor::with_backup_hold(
				$this->site_id,
				function () use ( &$reads ) {
					apply_filters( 'mainwp_fetch_url_site_timeout', 72000 );
					$baseline = $reads;
					for ( $chunk = 0; $chunk < 25; ++$chunk ) {
						do_action( 'mainwp_backup_hash_progress' );
						do_action( 'mainwp_download_to_file_progress' );
					}
					$this->assertSame( $baseline, $reads, 'Fast chunks must skip the ownership read.' );
					MainWP_Backup_Incremental_Sender_Test::$fixed_time += 9;
					do_action( 'mainwp_backup_hash_progress' );
					$this->assertSame( $baseline, $reads );
					++MainWP_Backup_Incremental_Sender_Test::$fixed_time;
					do_action( 'mainwp_backup_hash_progress' );
					$this->assertSame( $baseline + 1, $reads, 'Ten seconds permits a refresh.' );
					apply_filters( 'mainwp_fetch_url_site_timeout', 120000 );
					$this->assertSame( $baseline + 2, $reads, 'A larger deadline bypasses the throttle.' );
					MainWP_Backup_Incremental_Sender_Test::$fixed_time += 120030;
					$this->assertTrue( MainWP_Backup_Operations::snapshot_delete_busy( $this->site_id ) );
				}
			);
		} finally {
			remove_filter( 'query', $count );
		}
		$this->assertFalse( MainWP_Backup_Operations::snapshot_delete_busy( $this->site_id ) );
	}

	/**
	 * Both success and a coded exception survive a failed release.
	 *
	 * @return array
	 */
	public static function release_outcomes() {
		return array( array( false ), array( true ) );
	}

	/**
	 * A failed release leaves bounded evidence and preserves the original outcome.
	 *
	 * @dataProvider release_outcomes
	 * @param bool $throws Whether the operation throws.
	 */
	public function test_s93_r1_release_failure_preserves_operation_outcome( $throws ) {
		global $wpdb;
		$finished = false;
		$fail     = static function ( $query ) use ( &$finished ) {
			return $finished && str_starts_with( $query, 'UPDATE' ) && str_contains( $query, 'mainwp_backup_in_progress_' ) ? 'SELECT snapshot_delete_missing_column' : $query;
		};
		$suppress = $wpdb->suppress_errors();
		$original = new MainWP_Exception( 'original-operation', 'operation-detail', 'ERR_ALREADY_RUNNING' );
		$logger   = MainWP_Logger::instance();
		$priority = new \ReflectionProperty( $logger, 'logPriority' );
		$previous = $priority->getValue( $logger );
		$priority->setValue( $logger, MainWP_Logger::WARNING );
		$warnings = array();
		$capture  = static function ( $text, $level ) use ( &$warnings ) {
			$warnings[] = array( $text, $level );
		};
		add_filter( 'mainwp_log_do_to_db', '__return_true' );
		add_action( 'mainwp_before_log_data', $capture, 10, 2 );
		add_filter( 'query', $fail );
		try {
			try {
				$result = MainWP_Backup_Executor::with_backup_hold(
					$this->site_id,
					static function () use ( &$finished, $throws, $original ) {
						$finished = true;
						if ( $throws ) {
							throw $original;
						}
						return array( 'saved' => 42 );
					}
				);
				$this->assertFalse( $throws );
				$this->assertSame( array( 'saved' => 42 ), $result );
			} catch ( MainWP_Exception $error ) {
				$this->assertTrue( $throws, 'Release must not replace a successful result.' );
				$this->assertSame( $original, $error, 'Release must preserve the coded operation exception.' );
				$this->assertSame( 'ERR_ALREADY_RUNNING', $error->get_message_error_code() );
			}
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $suppress );
			remove_action( 'mainwp_before_log_data', $capture, 10 );
			remove_filter( 'mainwp_log_do_to_db', '__return_true' );
			$priority->setValue( $logger, $previous );
			foreach ( $warnings as $warning ) {
				$wpdb->delete( MainWP_DB::instance()->get_table_name( 'action_log' ), array( 'log_content' => $warning[0] ) );
				$wpdb->delete( MainWP_DB::instance()->get_table_name( 'action_log' ), array( 'log_content' => 'CRON :: ' . $warning[0] ) );
			}
		}
		$this->assertCount( 1, $warnings );
		$this->assertSame( MainWP_Logger::WARNING, $warnings[0][1] );
		$this->assertStringContainsString( 'release', $warnings[0][0] );
		$this->assertTrue( MainWP_Backup_Operations::snapshot_delete_busy( $this->site_id ), 'A failed release keeps bounded evidence.' );
	}

	/** A marker read failure cannot establish idleness. */
	public function test_backup_marker_read_failure_holds_site() {
		global $wpdb;
		$fail     = static function ( $query ) {
			return str_contains( $query, 'SELECT option_value' ) && str_contains( $query, 'mainwp_backup_in_progress_' ) ? 'SELECT snapshot_delete_missing_column' : $query;
		};
		$suppress = $wpdb->suppress_errors();
		add_filter( 'query', $fail );
		try {
			$this->assertTrue( MainWP_Backup_Operations::snapshot_delete_busy( $this->site_id ) );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $suppress );
		}
	}

	/** Expired crash evidence and empty handles release every admission path. */
	public function test_backup_marker_expiry_releases_all_entry_points() {
		$id   = $this->snapshot();
		$plan = $this->preview( $id );
		$this->set_lane( 'marker' );
		$this->assert_admission_held( 'preview', $id, $plan );
		global $wpdb;
		$wpdb->update( $wpdb->options, array( 'option_value' => wp_json_encode( array( 'browser' => time() - 1 ) ) ), array( 'option_name' => 'mainwp_backup_in_progress_' . $this->site_id ) );
		$this->assertTrue( $this->preview( $id )['can_proceed'] );
		$this->assertFalse( $this->snapshot_list()['snapshot_delete_busy'] );
		$this->assertSame( 'complete', $this->confirm( $id, $plan )['status'] );
	}

	/**
	 * Every exit releases synchronous/create evidence, including exceptions.
	 *
	 * @return array
	 */
	public static function request_exits() {
		return array( array( 'backup', false ), array( 'backup', true ), array( 'backup_task_create', false ), array( 'backup_task_create', true ) );
	}

	/**
	 * A cleanup without an observed busy state could pass against missing code.
	 *
	 * @dataProvider request_exits
	 * @param string $action Child request.
	 * @param bool   $throws Whether transport throws.
	 */
	public function test_backup_marker_is_cleared_after_each_request_exit( $action, $throws ) {
		$reply = function ( $pre, $site, $what ) use ( $action, $throws ) {
			if ( 'backup_preflight' === $what ) {
				return array( 'task_spine' => 1 );
			}
			if ( $what === $action ) {
				$this->assertTrue( MainWP_Backup_Operations::snapshot_delete_busy( $this->site_id ) );
				if ( $throws ) {
					throw new MainWP_Exception( 'transport-failed' );
				}
				return 'backup' === $action ? array(
					'full' => 'backup.zip',
					'size' => 6,
				) : array( 'task_id' => 'created-handle' );
			}
			return $pre;
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $reply, 20, 3 );
		try {
			if ( 'backup' === $action ) {
				MainWP_Backup_Executor::backup( $this->site_id, 'full', '', '', 1, 1, 1, 1 );
			} else {
				MainWP_Backup_Executor::trigger_backup( $this->site_id );
			}
			$this->assertFalse( $throws );
		} catch ( MainWP_Exception $error ) {
			$this->assertTrue( $throws );
			$this->assertSame( 'transport-failed', $error->getMessage() );
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $reply, 20 );
		}
		$this->clear_adhoc_handle();
		$this->assertFalse( MainWP_Backup_Operations::snapshot_delete_busy( $this->site_id ) );
	}

	/** Long transport bounds refresh evidence without acquiring a site reservation. */
	public function test_long_backup_steps_refresh_marker_without_reserving_site() {
		MainWP_Backup_Incremental_Sender_Test::$fixed_time = time();
		$observed    = array();
		$reply       = function ( $pre, $site, $what ) use ( &$observed ) {
			if ( 'backup_preflight' === $what ) {
				$observed[] = MainWP_Backup_Retention_Query::instance()->has_running_backup_for_site( $this->site_id );
				$this->assertSame( 72000, MainWP_Connect::resolve_child_request_timeout( $what ) );
				MainWP_Backup_Incremental_Sender_Test::$fixed_time += 72030;
				$observed[] = MainWP_Backup_Retention_Query::instance()->has_running_backup_for_site( $this->site_id );
				return array( 'task_spine' => 1 );
			}
			if ( 'backup' === $what ) {
				$this->assertSame( array( true, true ), $observed, 'The marker covers a long preflight.' );
				$this->assertSame( 72000, MainWP_Connect::resolve_child_request_timeout( $what ) );
				MainWP_Backup_Incremental_Sender_Test::$fixed_time += 72030;
				$this->assertTrue( MainWP_Backup_Operations::snapshot_delete_busy( $this->site_id ) );
				return array(
					'full' => 'backup.zip',
					'size' => 6,
				);
			}
			return $pre;
		};
		$reservation = MainWP_Backup_Restore_Job_Store::instance()->reserve_site( $this->site_id );
		add_filter( 'mainwp_fetch_url_authed_pre', $reply, 20, 3 );
		try {
			MainWP_Backup_Executor::backup( $this->site_id, 'full', '', '', 1, 1, 1, 1 );
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $reply, 20 );
			MainWP_Backup_Restore_Job_Store::instance()->release_reservation( $reservation );
		}
		$this->assertFalse( MainWP_Backup_Operations::snapshot_delete_busy( $this->site_id ) );
	}

	/** Ending the newer request must not erase the older request's evidence. */
	public function test_backup_marker_preserves_overlapping_requests() {
		$reply  = static function ( $pre, $site, $what ) {
			if ( 'backup_preflight' === $what ) {
				return array( 'task_spine' => 1 );
			}
			if ( 'backup' === $what ) {
				\Fiber::suspend();
				return array(
					'full' => 'backup.zip',
					'size' => 6,
				);
			}
			return $pre;
		};
		$call   = function () {
			return MainWP_Backup_Executor::backup( $this->site_id, 'full', '', '', 1, 1, 1, 1 );
		};
		$first  = new \Fiber( $call );
		$second = new \Fiber( $call );
		add_filter( 'mainwp_fetch_url_authed_pre', $reply, 20, 3 );
		try {
			$first->start();
			$this->assertTrue( MainWP_Backup_Operations::snapshot_delete_busy( $this->site_id ) );
			$second->start();
			$second->resume();
			$this->assertTrue( MainWP_Backup_Operations::snapshot_delete_busy( $this->site_id ) );
			$first->resume();
			$this->assertFalse( MainWP_Backup_Operations::snapshot_delete_busy( $this->site_id ) );
		} finally {
			foreach ( array( $first, $second ) as $fiber ) {
				if ( $fiber->isSuspended() ) {
					$fiber->resume();
				}
			}
			remove_filter( 'mainwp_fetch_url_authed_pre', $reply, 20 );
		}
	}

	/**
	 * Long steps renew the same marker at their existing transport boundaries.
	 *
	 * @return array
	 */
	public static function long_step_kinds() {
		return array( array( 'request' ), array( 'transfer' ), array( 'retry' ), array( 'progress' ), array( 'hash' ) );
	}

	/**
	 * Deadlines may be raised by settings/filters without changing admission.
	 *
	 * @dataProvider long_step_kinds
	 * @param string $kind Long step.
	 */
	public function test_backup_marker_refreshes_inside_long_steps( $kind ) {
		$this->assertTrue( is_callable( array( MainWP_Backup_Executor::class, 'with_backup_hold' ) ), 'Backup hold scope is missing.' );
		MainWP_Backup_Incremental_Sender_Test::$fixed_time = time();
		MainWP_Backup_Executor::with_backup_hold(
			$this->site_id,
			function () use ( $kind ) {
				$this->assertTrue( MainWP_Backup_Operations::snapshot_delete_busy( $this->site_id ) );
				MainWP_Backup_Incremental_Sender_Test::$fixed_time += 50;
				if ( 'request' === $kind ) {
					$this->assertSame( 120000, apply_filters( 'mainwp_fetch_url_site_timeout', 120000, 'backup' ) );
					MainWP_Backup_Incremental_Sender_Test::$fixed_time += 120030;
				} elseif ( 'transfer' === $kind ) {
					$this->assertSame( array( 'timeout' => 1800 ), apply_filters( 'mainwp_download_to_file_timeouts', array( 'timeout' => 1800 ) ) );
					MainWP_Backup_Incremental_Sender_Test::$fixed_time += 1830;
				} elseif ( 'retry' === $kind ) {
					$this->assertSame( 180, apply_filters( 'mainwp_backup_retry_delay', 180, 1, 'backup_preflight' ) );
					MainWP_Backup_Incremental_Sender_Test::$fixed_time += 210;
				} else {
					do_action( 'progress' === $kind ? 'mainwp_download_to_file_progress' : 'mainwp_backup_hash_progress' );
					MainWP_Backup_Incremental_Sender_Test::$fixed_time += 30;
				}
				$this->assertTrue( MainWP_Backup_Operations::snapshot_delete_busy( $this->site_id ) );
			}
		);
		$this->assertFalse( MainWP_Backup_Operations::snapshot_delete_busy( $this->site_id ) );
	}

	/** Hashing has no transport timeout, so each chunk must renew the live hold. */
	public function test_archive_hash_refreshes_backup_marker() {
		$this->assertTrue( is_callable( array( MainWP_Backup_Executor::class, 'with_backup_hold' ) ), 'Backup hold scope is missing.' );
		$id   = $this->snapshot();
		$path = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id )->archives[0]['path'];
		file_put_contents( $path, str_repeat( 'archive', 2000000 ) );
		$expected = hash_file( 'sha256', $path );
		$seen     = 0;
		MainWP_Backup_Incremental_Sender_Test::$fixed_time = time();
		$tick  = static function () use ( &$seen ) {
			++$seen;
			MainWP_Backup_Incremental_Sender_Test::$fixed_time += 61;
		};
		$check = function () {
			$this->assertTrue( MainWP_Backup_Operations::snapshot_delete_busy( $this->site_id ) );
		};
		add_action( 'mainwp_backup_hash_progress', $tick, 1 );
		add_action( 'mainwp_backup_hash_progress', $check, 20 );
		try {
			MainWP_Backup_Executor::with_backup_hold(
				$this->site_id,
				function () use ( $path, $expected ) {
					$hash = new \ReflectionMethod( \MainWP\Dashboard\MainWP_Backup_File_Manager::class, 'hash_artifact' );
					$this->assertSame( $expected, $hash->invoke( null, $path ) );
				}
			);
			$this->assertGreaterThan( 1, $seen );
		} finally {
			remove_action( 'mainwp_backup_hash_progress', $tick, 1 );
			remove_action( 'mainwp_backup_hash_progress', $check, 20 );
		}
	}

	/**
	 * Entry and transport renewal both refuse work if state cannot be saved.
	 *
	 * @return array
	 */
	public static function marker_write_phases() {
		return array( array( 'entry' ), array( 'renewal' ) );
	}

	/**
	 * A failed marker write must never send an unheld request.
	 *
	 * @dataProvider marker_write_phases
	 * @param string $phase Failure point.
	 */
	public function test_backup_marker_persistence_failure_refuses_work( $phase ) {
		$this->assertTrue( is_callable( array( MainWP_Backup_Executor::class, 'with_backup_hold' ) ), 'Backup hold scope is missing.' );
		global $wpdb;
		$started  = false;
		$sent     = false;
		$fail     = static function ( $query ) use ( $phase, &$started ) {
			$write = str_starts_with( $query, 'INSERT' ) || str_starts_with( $query, 'UPDATE' );
			return $write && str_contains( $query, 'mainwp_backup_in_progress_' ) && ( 'entry' === $phase || $started ) ? 'SELECT snapshot_delete_missing_column' : $query;
		};
		$suppress = $wpdb->suppress_errors();
		add_filter( 'query', $fail );
		try {
			MainWP_Backup_Executor::with_backup_hold(
				$this->site_id,
				static function () use ( &$started, &$sent ) {
					$started = true;
					apply_filters( 'mainwp_fetch_url_site_timeout', 120000, 'backup' );
					$sent = true;
				}
			);
			$this->fail( 'Failed persistence allowed the request.' );
		} catch ( MainWP_Exception $error ) {
			$this->assertSame( 'The backup could not save its running state. Try again.', $error->getMessage() );
			$this->assertFalse( $sent );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $suppress );
		}
	}

	/** A failed handle write must not return a successful, unheld create. */
	public function test_adhoc_create_requires_persisted_handle_before_marker_release() {
		global $wpdb;
		$reply    = function ( $pre, $site, $what ) {
			if ( 'backup_preflight' === $what ) {
				return array( 'task_spine' => 1 );
			}
			if ( 'backup_task_create' === $what ) {
				$this->assertTrue( MainWP_Backup_Operations::snapshot_delete_busy( $this->site_id ) );
				return array( 'task_id' => 'created-handle' );
			}
			return $pre;
		};
		$fail     = static function ( $query ) {
			return str_starts_with( $query, 'INSERT' ) && str_contains( $query, 'adhoc_backup_task' ) ? 'SELECT snapshot_delete_missing_column' : $query;
		};
		$suppress = $wpdb->suppress_errors();
		add_filter( 'query', $fail );
		add_filter( 'mainwp_fetch_url_authed_pre', $reply, 20, 3 );
		try {
			MainWP_Backup_Executor::trigger_backup( $this->site_id );
			$this->fail( 'An unpersisted handle was returned.' );
		} catch ( MainWP_Exception $error ) {
			$this->assertSame( 'The backup could not save its running state. Try again.', $error->getMessage() );
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $reply, 20 );
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $suppress );
		}
		$this->assertFalse( MainWP_Backup_Operations::snapshot_delete_busy( $this->site_id ) );
	}

	/**
	 * Put one lane into the named state.
	 *
	 * @param string $lane Lane state from running_lanes().
	 */
	private function set_lane( $lane ) {
		global $wpdb;
		if ( 'marker' === $lane ) {
			update_option( 'mainwp_backup_in_progress_' . $this->site_id, wp_json_encode( array( 'browser' => time() + 60 ) ), false );
		} elseif ( 'restore' === $lane ) {
			$this->assertGreaterThan( 0, MainWP_Backup_Restore_Job_Store::instance()->create_job( $this->site_id, 0, array() ) );
		} elseif ( 'reservation' === $lane ) {
			$this->lane_reservation = MainWP_Backup_Restore_Job_Store::instance()->reserve_site( $this->site_id );
			$this->assertGreaterThan( 0, $this->lane_reservation );
		} elseif ( 'stream' === $lane ) {
			$id                = $this->snapshot();
			$row               = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id );
			$this->lane_reader = fopen( $row->archives[0]['path'], 'rb' );
			$this->assertIsResource( $this->lane_reader );
		} elseif ( 'orphan' === $lane ) {
			$id  = $this->snapshot();
			$row = MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id );
			mkdir( dirname( $row->archives[0]['path'] ) . '/snapshot-orphan', 0700 );
		} elseif ( 'upload' === $lane ) {
			$this->assertSame(
				1,
				$wpdb->insert(
					MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ),
					array(
						'snapshot_id'    => $this->snapshot(),
						'destination_id' => 1,
						'checkpoint'     => '{}',
						'status'         => 'uploading',
						'locked_by'      => 'reader',
						'locked_until'   => time() + 300,
					)
				)
			);
		} elseif ( 'manual_open' === $lane || 'manual_closed' === $lane ) {
			$now = time();
			MainWP_DB_Backup::instance()->update_backup_task_with_values(
				$this->task->id,
				array(
					'last_run'  => $now,
					'completed' => 'manual_open' === $lane ? $now - 10 : $now + 10,
				)
			);
		} elseif ( str_starts_with( $lane, 'scheduled_' ) ) {
			$this->use_scheduled_task();
			if ( 'scheduled_not_started' === $lane ) {
				MainWP_DB_Backup::instance()->update_backup_task_with_values(
					$this->task->id,
					array(
						'last_run'  => time() + 1,
						'completed' => 0,
					)
				);
				return;
			}
			$this->start_download( 'scheduled_unswept' === $lane ? 0 : 1 );
			if ( 'scheduled_incremental' === $lane ) {
				$wpdb->update(
					MainWP_DB::instance()->get_table_name( 'wp_backup_progress' ),
					array( 'child_phase' => 'pack' ),
					array(
						'task_id' => $this->task->id,
						'wp_id'   => $this->site_id,
					)
				);
			}
			if ( 'scheduled_completed' === $lane ) {
				MainWP_DB_Backup::instance()->update_completed_sites( $this->task->id, array( $this->site_id => true ) );
			} elseif ( 'scheduled_failed' === $lane ) {
				$wpdb->update(
					MainWP_DB::instance()->get_table_name( 'wp_backup_progress' ),
					array( 'child_phase' => 'failed' ),
					array(
						'task_id' => $this->task->id,
						'wp_id'   => $this->site_id,
					)
				);
			}
		} elseif ( str_starts_with( $lane, 'adhoc_' ) ) {
			$handles = array(
				'adhoc_fresh'         => wp_json_encode(
					array(
						'task_id' => 'pre-update-task',
						'type'    => 'full',
						'started' => time(),
						'owner'   => '',
					)
				),
				'adhoc_abandoned'     => wp_json_encode(
					array(
						'task_id' => 'pre-update-task',
						'type'    => 'full',
						'started' => time() - MainWP_Backup_Executor::ADHOC_TASK_ABANDON_SECONDS - 60,
						'owner'   => '',
					)
				),
				'adhoc_driven'        => wp_json_encode(
					array(
						'task_id'   => 'pre-update-task',
						'type'      => 'full',
						'started'   => time() - 14400,
						'driven_at' => time() - 1,
						'owner'     => '',
					)
				),
				'adhoc_drive_expired' => wp_json_encode(
					array(
						'task_id'   => 'pre-update-task',
						'type'      => 'full',
						'started'   => time() - 14400,
						'driven_at' => time() - 7201,
						'owner'     => '',
					)
				),
				'adhoc_cleared'       => '',
				'adhoc_no_task'       => wp_json_encode( array( 'started' => time() ) ),
			);
			MainWP_DB::instance()->update_website_option( $this->site_id, MainWP_Backup_Executor::ADHOC_TASK_OPTION, $handles[ $lane ] );
		}
	}

	/** The fixture tear-down does not know this lane's option row. */
	private function clear_adhoc_handle() {
		global $wpdb;
		$wpdb->delete(
			MainWP_DB::instance()->get_table_name( 'wp_options' ),
			array(
				'wpid' => $this->site_id,
				'name' => MainWP_Backup_Executor::ADHOC_TASK_OPTION,
			)
		);
	}

	/** A failed handle read cannot prove the pre-update lane is idle. */
	public function test_pre_update_handle_read_failure_holds_site() {
		global $wpdb;
		$table    = MainWP_DB::instance()->get_table_name( 'wp_options' );
		$fail     = static function ( $query ) use ( $table ) {
			return str_contains( $query, 'FROM ' . $table . ' WHERE wpid' ) && str_contains( $query, MainWP_Backup_Executor::ADHOC_TASK_OPTION ) ? 'SELECT snapshot_delete_missing_column FROM ' . $table : $query;
		};
		$suppress = $wpdb->suppress_errors();
		add_filter( 'query', $fail );
		try {
			$this->assertTrue( MainWP_Backup_Retention_Query::instance()->has_running_backup_for_site( $this->site_id ) );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $suppress );
		}
	}

	/** A failed task read cannot prove the site is idle. */
	public function test_running_backup_read_failure_holds_site() {
		global $wpdb;
		$table    = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$fail     = static function ( $query ) use ( $table ) {
			return str_contains( $query, 'FROM ' . $table . ' b LEFT JOIN' ) ? 'SELECT snapshot_delete_missing_column FROM ' . $table : $query;
		};
		$suppress = $wpdb->suppress_errors();
		add_filter( 'query', $fail );
		try {
			$this->assertTrue( MainWP_Backup_Retention_Query::instance()->has_running_backup_for_site( $this->site_id ) );
		} finally {
			remove_filter( 'query', $fail );
			$wpdb->suppress_errors( $suppress );
		}
	}

	/** Reader locks survive their reservation timestamp during a long download. */
	public function test_expired_reader_reservation_holds_preview_and_confirm() {
		global $wpdb;
		$id          = $this->snapshot();
		$plan        = $this->preview( $id );
		$store       = MainWP_Backup_Restore_Job_Store::instance();
		$reservation = $store->reserve_site( $this->site_id );
		try {
			$this->assertTrue( $store->renew_reservation( $reservation ) );
			$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ), array( 'locked_until' => time() - 1 ), array( 'id' => $reservation ) );
			$this->assertTrue( $store->site_is_reserved( $this->site_id ) );
			$this->assertSame( 0, $store->reserve_site( $this->site_id ) );
			$this->assertFalse( $this->preview( $id )['can_proceed'] );
			$this->assertTrue( $this->snapshot_list()['snapshot_delete_busy'] );
			$this->assertSame( 'refused', $this->confirm( $id, $plan )['status'] );
		} finally {
			$store->release_reservation( $reservation );
		}
	}

	/**
	 * Daily retention and Clean Up retain their existing result semantics.
	 *
	 * @return array
	 */
	public static function missing_rows() {
		return array(
			array( 'first', 'single_snapshot_delete' ),
			array( 'leaf', 'single_snapshot_delete' ),
			array( 'first', 'beyond_keep' ),
			array( 'leaf', 'manual_cleanup' ),
		);
	}

	/**
	 * Missing rows keep ids remaining, with a reason for single deletion only.
	 *
	 * @dataProvider missing_rows
	 * @param string $missing Missing member.
	 * @param string $reason Prune caller.
	 */
	public function test_missing_prune_row_reports_reason( $missing, $reason ) {
		global $wpdb;
		$base = $this->snapshot();
		$leaf = $this->snapshot( $base );
		$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'id' => 'first' === $missing ? $base : $leaf ) );
		$store       = MainWP_Backup_Restore_Job_Store::instance();
		$reservation = $store->reserve_site( $this->site_id );
		$prune       = new \ReflectionMethod( MainWP_Backup_Retention_Executor::class, 'prune_reserved_candidate' );
		try {
			$result = $prune->invoke(
				MainWP_Backup_Retention_Executor::instance(),
				array(
					'snapshot_ids' => array( $base, $leaf ),
					'reason'       => $reason,
				),
				$this->task
			);
			$this->assertSame( array( $base, $leaf ), $result['remaining_ids'] );
			$this->assertSame( array(), $result['deleted_ids'] );
			$this->assertSame( 'single_snapshot_delete' === $reason ? array( 'Some of these backups were removed by another action. Check the list, then try again.' ) : array(), $result['errors'] );
		} finally {
			$store->release_reservation( $reservation );
		}
	}

	/** A failing refresh must still leave a schema-valid changed result. */
	public function test_changed_set_preview_error_is_null_and_reported() {
		global $wpdb;
		$id   = $this->snapshot();
		$plan = $this->preview( $id );
		$this->snapshot( $id );
		$table    = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$disabled = false;
		$between  = static function ( $query ) use ( $table, &$disabled ) {
			if ( ! $disabled && str_starts_with( $query, 'DELETE FROM ' . $table . ' WHERE id = ' ) ) {
				$disabled = true;
				update_option( 'mainwp_enableNativeBackups', 0 );
			}
			return $query;
		};
		add_filter( 'query', $between );
		try {
			$result = $this->confirm( $id, $plan );
			$this->assertTrue( $disabled, 'The failure must occur after reserved revalidation.' );
			$this->assertSame( 'changed', $result['status'] );
			$this->assertNull( $result['preview'] );
			$this->assertSame( MainWP_Abilities_Backups::cleanup_error( array( 'code' => 'mainwp_backup_legacy_disabled' ) ), $result['errors'][1] );
			$schema = new \ReflectionMethod( MainWP_Abilities_Backups::class, 'delete_backup_snapshot_output_schema' );
			$this->assertTrue( rest_validate_value_from_schema( $result, $schema->invoke( null ) ) );
		} finally {
			remove_filter( 'query', $between );
			update_option( 'mainwp_enableNativeBackups', 1 );
		}
	}
}
