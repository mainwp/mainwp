<?php
/**
 * Scheduled run cancellation and error ownership tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

require_once __DIR__ . '/trait-backup-private-root-fixture.php';

use MainWP\Dashboard\MainWP_Backup_Diagnostics;
use MainWP\Dashboard\MainWP_Backup_Executor;
use MainWP\Dashboard\MainWP_Backup_Monitor;
use MainWP\Dashboard\MainWP_Backup_Operations;
use MainWP\Dashboard\MainWP_Backup_Read_Model;
use MainWP\Dashboard\MainWP_Backup_Run_Cancel;
use MainWP\Dashboard\MainWP_Backup_Run_History;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Install;
use MainWP\Dashboard\MainWP_Manage_Backups_Handler;
use MainWP\Dashboard\MainWP_Post_Backup_Handler;
use MainWP\Dashboard\MainWP_System_Cron_Jobs;
use MainWP\Dashboard\MainWP_System_Utility;

/** Persistent rows and deterministic interleavings exercise each entry point. */
class MainWP_Backup_Run_Cancel_Test extends MainWP_Abilities_Test_Case {

	use Backup_Private_Root_Fixture;
	use Backup_Cancel_Transport_Guard;

	/**
	 * Captured Child calls.
	 *
	 * @var array
	 */
	private $calls = array();
	/**
	 * Scripted cancel answer or callback.
	 *
	 * @var mixed
	 */
	private $reply = array( 'result' => 'ok' );
	/**
	 * Fixed production clock.
	 *
	 * @var int
	 */
	private $now;
	/**
	 * Diagnostics scratch directory.
	 *
	 * @var string
	 */
	private $diag;
	/**
	 * Owned transient keys.
	 *
	 * @var array
	 */
	private $copies = array();

	/**
	 * Close events observed during the test.
	 *
	 * @var array
	 */
	private $closes = array();

	/** Set up isolated storage, clock, and transport. */
	public function setUp(): void {
		parent::setUp();
		$this->now = time();
		MainWP_Backup_Incremental_Sender_Test::$fixed_time = $this->now;
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'mainwp_enableNativeBackups', 1 );
		delete_site_option( MainWP_Install::BACKUP_TASK_RUN_CANCEL_REPAIR_PENDING );
		$this->set_up_private_root();
		$this->diag = sys_get_temp_dir() . '/mainwp-run-cancel-' . uniqid();
		wp_mkdir_p( $this->diag );
		add_filter( 'mainwp_backup_diagnostics_dir', array( $this, 'diag_dir' ) );
		add_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'transport' ), 10, 4 );
		add_action( 'mainwp_backup_diag_run_close', array( $this, 'capture_close' ), 10, 3 );
	}

	/** Restore the clock and remove only owned filesystem fixtures. */
	public function tearDown(): void {
		MainWP_Backup_Incremental_Sender_Test::$fixed_time = null;
		remove_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'transport' ), 10 );
		remove_action( 'mainwp_backup_diag_run_close', array( $this, 'capture_close' ), 10 );
		remove_filter( 'mainwp_backup_diagnostics_dir', array( $this, 'diag_dir' ) );
		foreach ( glob( $this->diag . '/*' ) as $file ) {
			unlink( $file );
		}
		rmdir( $this->diag );
		foreach ( $this->copies as $key ) {
			delete_transient( $key );
		}
		$this->tear_down_private_root();
		MainWP_Backup_Monitor::release_lane_lease();
		delete_option( MainWP_Backup_Monitor::LANE_LEASE_OPTION );
		delete_site_option( MainWP_Install::BACKUP_TASK_RUN_CANCEL_REPAIR_PENDING );
		delete_option( 'mainwp_enableNativeBackups' );
		unset( $_POST['security'], $_POST['task_id'], $_POST['run'], $_REQUEST['security'] );
		parent::tearDown();
	}

	/**
	 * Capture only winner-owned close events.
	 *
	 * @param int    $task_id Task identity.
	 * @param int    $run Run identity.
	 * @param string $trigger Close reason.
	 */
	public function capture_close( $task_id, $run, $trigger ) {
		$this->closes[] = array( $task_id, $run, $trigger );
	}

	/**
	 * Route diagnostic writes to scratch.
	 *
	 * @return string Diagnostics scratch directory.
	 */
	public function diag_dir() {
		return $this->diag;
	}

	/**
	 * Script every Child round trip.
	 *
	 * @param mixed  $pre Earlier response.
	 * @param object $website Child site.
	 * @param string $what Child action.
	 * @param array  $params Request arguments.
	 * @return mixed Scripted response.
	 */
	public function transport( $pre, $website, $what, $params ) {
		$this->calls[] = array( $what, (int) $website->id, $params );
		if ( 'backup_task_cancel' === $what ) {
			return is_callable( $this->reply ) ? ( $this->reply )( $website, $params ) : $this->reply;
		}
		if ( 'backup_preflight' === $what ) {
			return array(
				'task_spine' => 1,
				'gz_support' => 1,
			);
		}
		if ( 'backup_task_create' === $what ) {
			return array(
				'task_id' => 'created-' . $website->id,
				'phase'   => 'queued',
			);
		}
		return array(
			'phase'   => 'queued',
			'task_id' => $params['task_id'] ?? '',
		);
	}

	/**
	 * Seed an open scheduled run.
	 *
	 * @param int   $count Site count.
	 * @param array $over Task overrides.
	 * @return array Task and site ids.
	 */
	private function running( int $count = 1, array $over = array() ): array {
		$sites = array();
		for ( $i = 0; $i < $count; ++$i ) {
			$sites[] = $this->create_test_site( array( 'name' => 'Site ' . $i ) );
		}
		$db   = MainWP_DB_Backup::instance();
		$task = $db->add_backup_task( get_current_user_id(), 'Nightly', 'daily', 'full', '', implode( ',', $sites ), '', '', '', 0, 0, 0, 0, 0, 'tar.gz', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task );
		$db->update_backup_task_with_values(
			$task->id,
			array_merge(
				array(
					'last_run'         => $this->now - 100,
					'completed'        => $this->now - 200,
					'next_run'         => $this->now + 3600,
					'authorized_sites' => implode( ',', $sites ),
				),
				$over
			)
		);
		$task = $db->get_backup_task_by_id( $task->id );
		foreach ( $sites as $site ) {
			$this->assertGreaterThan( 0, MainWP_Backup_Run_History::start( (int) $task->id, $site, (int) $task->last_run, 'schedule', get_current_user_id() ) );
		}
		return array( $task, $sites );
	}

	/**
	 * Seed current-run progress.
	 *
	 * @param object $task Task row.
	 * @param int    $site Site id.
	 * @param string $id Child id or marker.
	 * @param string $phase Child phase.
	 */
	private function progress( object $task, int $site, string $id, string $phase = 'dumping' ): void {
		$db = MainWP_DB_Backup::instance();
		$db->add_backup_task_progress( $task->id, $site, array() );
		$db->update_backup_task_progress(
			$task->id,
			$site,
			array(
				'dtsFetched'           => $this->now,
				'child_backup_task_id' => $id,
				'child_phase'          => $phase,
				'pid'                  => 42,
			)
		);
	}

	/**
	 * Persist a request without running a pass.
	 *
	 * @param object $task Fresh task row.
	 * @param array  $over Request overrides.
	 * @return string Stored request bytes.
	 */
	private function request( object $task, array $over = array() ): string {
		$state = array_merge(
			array(
				'run'         => (int) $task->last_run,
				'requested'   => $this->now,
				'deadline'    => $this->now + 1800,
				'user'        => get_current_user_id(),
				'lease'       => '',
				'lease_until' => 0,
				'settled'     => (object) array(),
			),
			$over
		);
		$bytes = wp_json_encode( $state );
		$this->assertTrue( MainWP_DB_Backup::instance()->compare_and_swap_run_cancel( (int) $task->id, (int) $task->last_run, $this->fresh( $task )->run_cancel, $bytes ) );
		return $bytes;
	}

	/**
	 * Read the row without the option cache.
	 *
	 * @param object $task Task identity.
	 * @return object Fresh persisted row.
	 */
	private function fresh( object $task ): object {
		return MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
	}

	/**
	 * Invoke the driver without an outer lane.
	 *
	 * @param object $task Task identity.
	 * @param bool   $start Whether to claim a new run.
	 * @return mixed Driver result.
	 */
	private function drive( object $task, bool $start = false ) {
		$method = new \ReflectionMethod( MainWP_Manage_Backups_Handler::class, 'execute_backup_task_run' );
		$method->setAccessible( true );
		return $method->invokeArgs( null, array( $task, 5, $start ) );
	}

	/**
	 * Capture the AJAX response through WordPress termination.
	 *
	 * @param object $task Task identity.
	 * @param mixed  $id Submitted id, null uses task identity.
	 * @param mixed  $run Submitted run, null uses task identity.
	 * @return array Decoded AJAX reply.
	 */
	private function ajax( object $task, $id = null, $run = null ): array {
		$_POST['task_id']     = $id ?? $task->id;
		$_POST['run']         = $run ?? $task->last_run;
		$security             = wp_create_nonce( 'mainwp_native_backup_task_cancel_run' );
		$_POST['security']    = $security;
		$_REQUEST['security'] = $security;
		$throw                = static function () {
			return static function () {
				throw new \RuntimeException( 'wp_die' );
			};
		};
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $throw );
		ob_start();
		try {
			MainWP_Post_Backup_Handler::instance()->mainwp_native_backup_task_cancel_run();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_die', $e->getMessage() );
		} finally {
			$raw = ob_get_clean();
			remove_filter( 'wp_die_ajax_handler', $throw );
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}
		return json_decode( $raw, true ) ?? array( 'raw' => $raw );
	}

	/**
	 * Assert the late-error diagnostic payload.
	 *
	 * @param object $task Task identity.
	 * @param string $reason Expected refusal reason.
	 * @param string $text Expected error text.
	 */
	private function refused_error( object $task, string $reason, string $text ): void {
		$file = $this->diag . '/' . MainWP_System_Utility::get_private_filename( MainWP_Backup_Diagnostics::SUBDIR, '_global', 'events' ) . '.jsonl';
		$this->assertFileExists( $file );
		$events = array_map( static fn( $line ) => json_decode( $line, true ), file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) );
		$events = array_values( array_filter( $events, static fn( $e ) => 'error_append_refused' === $e['kind'] && (int) $task->id === $e['task_id'] && str_contains( $e['payload']['errors'], $text ) ) );
		$this->assertCount( 1, $events );
		$this->assertSame( (int) $task->last_run, $events[0]['payload']['last_run'] );
		$this->assertSame( $reason, $events[0]['payload']['reason'] );
	}

	/** Errors accepted before close survive the next starter's copy. */
	public function test_error_before_close_survives_closed_run_copy() {
		list( $task ) = $this->running();
		$db           = MainWP_DB_Backup::instance();
		$this->assertTrue( $db->append_backup_errors( (int) $task->id, (int) $task->last_run, 'accepted' ) );
		$this->assertTrue( $db->update_backup_completed( $task->id, (int) $task->last_run ) );
		$this->drive( $this->fresh( $task ), true );
		$key            = 'mainwp_backup_run_' . $task->id . '_' . $task->last_run;
		$this->copies[] = $key;
		$this->assertSame( 'accepted', get_transient( $key )['task']->backup_errors );
		$this->assertSame( '', $this->fresh( $task )->backup_errors );
	}

	/** A nested append in the first SQL window cannot overwrite either error. */
	public function test_atomic_error_appends_preserve_both_drivers() {
		list( $task ) = $this->running();
		$interleave   = function ( $sql ) use ( &$interleave, $task ) {
			if ( str_contains( $sql, 'SET backup_errors = CONCAT(' ) ) {
				remove_filter( 'query', $interleave );
				$this->assertTrue( MainWP_DB_Backup::instance()->append_backup_errors( (int) $task->id, (int) $task->last_run, 'second;' ) );
			}
			return $sql;
		};
		add_filter( 'query', $interleave );
		try {
			$this->assertTrue( MainWP_DB_Backup::instance()->append_backup_errors( (int) $task->id, (int) $task->last_run, 'first;' ) );
			$this->assertSame( 'second;first;', $this->fresh( $task )->backup_errors );
		} finally {
			remove_filter( 'query', $interleave );
		}
	}

	/** Diagnostics remains writable when the optional recorder is disabled. */
	public function test_error_after_close_is_logged_and_never_enters_next_run() {
		list( $task ) = $this->running();
		delete_option( MainWP_Backup_Diagnostics::OPTION_ENABLED );
		$db = MainWP_DB_Backup::instance();
		$this->assertTrue( $db->update_backup_completed( $task->id, (int) $task->last_run ) );
		$this->assertFalse( $db->append_backup_errors( (int) $task->id, (int) $task->last_run, 'late' ) );
		$this->refused_error( $task, 'closed', 'late' );
		$this->drive( $this->fresh( $task ), true );
		$this->copies[] = 'mainwp_backup_run_' . $task->id . '_' . $task->last_run;
		$this->assertSame( '', $this->fresh( $task )->backup_errors );
	}

	/** The append happens after the saved copy and before the claim UPDATE. */
	public function test_error_between_copy_and_claim_is_refused_and_logged() {
		list( $task ) = $this->running();
		$db           = MainWP_DB_Backup::instance();
		$db->append_backup_errors( (int) $task->id, (int) $task->last_run, 'before' );
		$db->update_backup_completed( $task->id, (int) $task->last_run );
		$key            = 'mainwp_backup_run_' . $task->id . '_' . $task->last_run;
		$this->copies[] = $key;
		$seen           = false;
		$interleave     = function ( $sql ) use ( &$interleave, &$seen, $db, $task, $key ) {
			if ( str_contains( $sql, 'SET last_run = GREATEST(' ) ) {
				remove_filter( 'query', $interleave );
				$this->assertSame( 'before', get_transient( $key )['task']->backup_errors );
				$this->assertFalse( $db->append_backup_errors( (int) $task->id, (int) $task->last_run, 'between' ) );
				$seen = true;
			}
			return $sql;
		};
		add_filter( 'query', $interleave );
		try {
			$this->drive( $this->fresh( $task ), true );
			$this->assertTrue( $seen );
			$this->refused_error( $task, 'closed', 'between' );
			$this->assertSame( '', $this->fresh( $task )->backup_errors );
		} finally {
			remove_filter( 'query', $interleave );
		}
	}

	/** An open successor owns its own error channel. */
	public function test_superseded_error_append_is_logged() {
		list( $task ) = $this->running();
		$db           = MainWP_DB_Backup::instance();
		$this->assertTrue( $db->claim_backup_run( $task->id ) );
		$this->assertFalse( $db->append_backup_errors( (int) $task->id, (int) $task->last_run, 'old-run' ) );
		$this->refused_error( $task, 'superseded', 'old-run' );
		$this->assertSame( '', $this->fresh( $task )->backup_errors );
	}

	/** A late starter cannot erase a successor's errors. */
	public function test_error_clear_requires_current_run_identity() {
		list( $task ) = $this->running( 1, array( 'backup_errors' => 'keep' ) );
		$this->assertFalse( MainWP_DB_Backup::instance()->clear_backup_errors( (int) $task->id, (int) $task->last_run - 1 ) );
		$this->assertSame( 'keep', $this->fresh( $task )->backup_errors );
	}

	/** A stale closer cannot win; the current identity closes once. */
	public function test_close_requires_current_identity_and_wins_once() {
		list( $task ) = $this->running();
		$db           = MainWP_DB_Backup::instance();
		$this->assertFalse( $db->update_backup_completed( $task->id, (int) $task->last_run - 1 ) );
		$this->assertTrue( $db->update_backup_completed( $task->id, (int) $task->last_run ) );
		$this->assertFalse( $db->update_backup_completed( $task->id, (int) $task->last_run ) );
	}

	/** No Child id means nothing needs to be stopped remotely. */
	public function test_cancel_before_start_closes_in_ajax_pass() {
		global $wpdb;
		list( $task ) = $this->running();
		$this->assertSame( array( 'result' => 'cancelled' ), $this->ajax( $task ) );
		$this->assertSame( array(), $this->calls );
		$this->assertSame( "Cancelled.\n", $this->fresh( $task )->backup_notices );
		$this->assertNull( MainWP_Backup_Run_Cancel::pending_request( $this->fresh( $task ) ), 'The closed run shows no pending cancel.' );
		$this->assertTrue( MainWP_Backup_Run_Cancel::blocks_run( $this->fresh( $task ), (int) $task->last_run ), 'The request outlives the close so a late driver still starts nothing.' );
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_run_history' );
		$this->assertSame( array( 'cancelled' ), $wpdb->get_col( $wpdb->prepare( 'SELECT outcome FROM ' . $table . ' WHERE task_id = %d', $task->id ) ) );
	}

	/** Busy is retried while finished site history and snapshot evidence remain intact. */
	public function test_busy_cancel_retries_and_preserves_finished_site() {
		global $wpdb;
		list( $task, $sites ) = $this->running( 2 );
		$this->progress( $task, $sites[0], 'active' );
		$this->progress( $task, $sites[1], 'done', 'completed' );
		$snapshots   = MainWP_Backup_Snapshots::instance();
		$snapshot_id = $snapshots->record_snapshot(
			array(
				'task_id' => (int) $task->id,
				'site_id' => $sites[1],
				'type'    => 'full',
			)
		);
		$this->assertGreaterThan( 0, $snapshot_id );
		$snapshot_before = $snapshots->get_snapshot_by_id( $snapshot_id );
		MainWP_Backup_Run_History::finalize_run( (int) $task->id, (int) $task->last_run, 'completed', $sites[1], '', $snapshot_id );
		$history     = MainWP_DB::instance()->get_table_name( 'wp_backup_run_history' );
		$before      = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $history . ' WHERE task_id = %d AND site_id = %d', $task->id, $sites[1] ) );
		$this->reply = array( 'result' => 'busy' );
		$this->assertSame(
			array(
				'result'  => 'cancelling',
				'waiting' => 1,
			),
			$this->ajax( $task )
		);
		$requested   = MainWP_Backup_Run_Cancel::pending_request( $this->fresh( $task ) );
		$this->reply = array( 'result' => 'ok' );
		$this->assertSame( array( 'result' => 'cancelled' ), $this->ajax( $task ) );
		$this->assertCount( 2, $this->calls );
		$this->assertSame( 'active', $this->calls[0][2]['task_id'] );
		$this->assertEquals( $before, $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $history . ' WHERE task_id = %d AND site_id = %d', $task->id, $sites[1] ) ) );
		$this->assertEquals( $snapshot_before, $snapshots->get_snapshot_by_id( $snapshot_id ) );
		$this->assertSame( $this->now + 1800, $requested['deadline'] );
		$this->assertSame( '', MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $sites[0] )->child_backup_task_id );
	}

	/** Another driver's lane can stay live throughout cancellation. */
	public function test_cancel_runs_while_another_task_renews_the_lane() {
		list( $task, $sites ) = $this->running();
		$this->progress( $task, $sites[0], 'active' );
		$this->assertIsString( MainWP_Backup_Monitor::acquire_lane_lease() );
		$this->reply = function () {
			$this->assertTrue( MainWP_Backup_Monitor::refresh_lane_lease() );
			return array( 'result' => 'ok' );
		};
		$this->assertSame( array( 'result' => 'cancelled' ), $this->ajax( $task ) );
		$this->assertTrue( MainWP_Backup_Monitor::confirm_lane_lease() );
	}

	/** The CAS compares token case and refuses stale bytes. */
	public function test_cancel_state_cas_refuses_displaced_and_expired_holders() {
		list( $task ) = $this->running();
		$old          = $this->request(
			$task,
			array(
				'lease'       => 'AbCd',
				'lease_until' => $this->now - 1,
			)
		);
		$new          = str_replace( 'AbCd', 'aBcD', $old );
		$db           = MainWP_DB_Backup::instance();
		$this->assertFalse( $db->compare_and_swap_run_cancel( (int) $task->id, (int) $task->last_run, $new, null ) );
		$this->assertTrue( $db->compare_and_swap_run_cancel( (int) $task->id, (int) $task->last_run, $old, $new ) );
		$this->assertFalse( $db->compare_and_swap_run_cancel( (int) $task->id, (int) $task->last_run, $old, null ) );
		$state = json_decode( $new, true );
		$raw   = $new;
		$renew = new \ReflectionMethod( MainWP_Backup_Run_Cancel::class, 'renew' );
		$renew->setAccessible( true );
		$this->assertFalse( $renew->invokeArgs( null, array( $task, &$state, &$raw, 'aBcD' ) ) );
		$this->assertSame( $new, $this->fresh( $task )->run_cancel );
		$this->assertSame( 'closed', MainWP_Backup_Run_Cancel::process( (int) $task->id, 'new-holder' )['status'] );
	}

	/** A lost answer is retried by id and a terminal reply settles it. */
	public function test_lost_cancel_answer_retries_already_terminal() {
		list( $task, $sites ) = $this->running();
		$this->progress( $task, $sites[0], 'acted' );
		$this->reply = static function () {
			throw new \RuntimeException( 'answer lost' );
		};
		$this->assertSame( 'cancelling', $this->ajax( $task )['result'] );
		$this->reply = array(
			'result' => 'already_terminal',
			'phase'  => 'anything',
		);
		$this->assertSame( 'closed', MainWP_Backup_Run_Cancel::process( (int) $task->id, 'cron' )['status'] );
		$this->assertCount( 2, $this->calls );
	}

	/** The marker is held until the driver publishes a real Child id. */
	public function test_create_marker_waits_then_cancels_returned_id() {
		list( $task, $sites ) = $this->running();
		$this->progress( $task, $sites[0], '__creating_AbCd12345678', '' );
		$this->assertSame(
			array(
				'result'  => 'cancelling',
				'waiting' => 1,
			),
			$this->ajax( $task )
		);
		$this->assertCount( 0, $this->calls );
		MainWP_DB_Backup::instance()->update_backup_task_progress( $task->id, $sites[0], array( 'child_backup_task_id' => 'returned' ) );
		$this->assertSame( 'closed', MainWP_Backup_Run_Cancel::process( (int) $task->id, 'cron' )['status'] );
		$this->assertSame( 'returned', $this->calls[0][2]['task_id'] );
	}

	/** Cancellation wins after admission but before marker publication. */
	public function test_cancel_closes_between_precheck_and_marker_publication() {
		$this->marker_race( false );
	}

	/** Cancellation sees the published marker before the driver rechecks. */
	public function test_cancel_after_marker_publication_hands_create_window_back() {
		$this->marker_race( true );
	}

	/**
	 * Interleave cancellation at the marker UPDATE.
	 *
	 * @param bool $publish_first Whether the marker UPDATE executes before cancellation.
	 */
	private function marker_race( bool $publish_first ): void {
		global $wpdb;
		list( $task, $sites ) = $this->running();
		$seen                 = false;
		$interleave           = function ( $sql ) use ( &$interleave, &$seen, $publish_first, $wpdb, $task ) {
			if ( str_starts_with( $sql, 'UPDATE' ) && str_contains( $sql, '__creating_' ) && str_contains( $sql, 'child_backup_task_id' ) ) {
				remove_filter( 'query', $interleave );
				if ( $publish_first ) {
					$this->assertSame( 1, $wpdb->query( $sql ) );
				}
				$this->assertSame( $publish_first ? 'cancelling' : 'cancelled', $this->ajax( $task )['result'] );
				$seen = true;
				return $publish_first ? 'SELECT 1' : $sql;
			}
			return $sql;
		};
		add_filter( 'query', $interleave );
		try {
			$this->assertFalse( MainWP_Backup_Executor::backup_site( $sites[0], $task, 'backups' ) );
			$this->assertTrue( $seen );
			$this->assertCount( 0, $this->calls );
			$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $sites[0] );
			$this->assertSame( 0, (int) $progress->dtsFetched );
			$this->assertSame( '', $progress->child_backup_task_id );
			if ( $publish_first ) {
				$this->assertSame( 'closed', MainWP_Backup_Run_Cancel::process( (int) $task->id, 'cron' )['status'] );
			}
			$this->assertSame( "Cancelled.\n", $this->fresh( $task )->backup_notices );
		} finally {
			remove_filter( 'query', $interleave );
		}
	}

	/** A lost create remains unconfirmed even after its normal marker TTL. */
	public function test_lost_marker_closes_only_at_deadline_without_sending() {
		list( $task, $sites ) = $this->running();
		$this->progress( $task, $sites[0], '__creating_lost12345678', '' );
		$this->request( $task );
		MainWP_Backup_Incremental_Sender_Test::$fixed_time = $this->now + 1799;
		$this->assertSame( 'pending', MainWP_Backup_Run_Cancel::process( (int) $task->id, 'cron' )['status'] );
		MainWP_Backup_Incremental_Sender_Test::$fixed_time = $this->now + 1800;
		$this->assertSame( 'not_confirmed', MainWP_Backup_Run_Cancel::process( (int) $task->id, 'cron' )['closed_as'] );
		$this->assertSame( "Cancelled. Not confirmed for: Site 0.\n", $this->fresh( $task )->backup_notices );
		$this->assertSame( array(), $this->calls );
	}

	/** A known missing id is settled. */
	public function test_not_found_settles_site() {
		list( $task, $sites ) = $this->running();
		$this->progress( $task, $sites[0], 'gone' );
		$this->reply = array( 'result' => 'not_found' );
		$this->assertSame( array( 'result' => 'cancelled' ), $this->ajax( $task ) );
	}

	/** An empty error code is still an error and cannot confirm a stop. */
	public function test_empty_error_code_retries_until_deadline() {
		list( $task, $sites ) = $this->running();
		$this->progress( $task, $sites[0], 'gone-with-error' );
		$this->reply = array(
			'error'      => '',
			'error_code' => '',
		);
		$this->assertSame(
			array(
				'result'  => 'cancelling',
				'waiting' => 1,
			),
			$this->ajax( $task )
		);
		$this->assertSame( 'pending', MainWP_Backup_Run_Cancel::process( (int) $task->id, 'cron' )['status'] );
		$this->assertCount( 2, $this->calls );
		MainWP_Backup_Incremental_Sender_Test::$fixed_time = $this->now + 1800;
		$this->assertSame( array( 'result' => 'not_confirmed' ), $this->ajax( $task ) );
		$this->assertCount( 2, $this->calls );
	}

	/** Pausing a task never excludes its persisted request from the sweep. */
	public function test_pending_sweep_includes_paused_task() {
		list( $task ) = $this->running( 1, array( 'paused' => 1 ) );
		$this->request( $task );
		MainWP_Backup_Run_Cancel::process_pending( 'cron' );
		$this->assertSame( "Cancelled.\n", $this->fresh( $task )->backup_notices );
	}

	/** The cron off switch still permits stops without acquiring a backup lane. */
	public function test_native_backups_off_still_processes_cancellation() {
		list( $task ) = $this->running();
		$this->request( $task );
		update_option( 'mainwp_enableNativeBackups', 0 );
		MainWP_System_Cron_Jobs::instance()->cron_backups_continue();
		$this->assertSame( "Cancelled.\n", $this->fresh( $task )->backup_notices );
		$this->assertSame( array(), $this->calls );
		$this->assertFalse( get_option( MainWP_Backup_Monitor::LANE_LEASE_OPTION ) );
	}

	/** A nested status caller finds the first caller's live cancellation lease. */
	public function test_cron_and_status_poll_share_one_cancel_lease() {
		list( $task, $sites ) = $this->running();
		$this->progress( $task, $sites[0], 'only-once' );
		$this->request( $task );
		$nested      = null;
		$this->reply = function () use ( $task, &$nested ) {
			$nested = MainWP_Backup_Run_Cancel::process( (int) $task->id, 'status' );
			MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, (int) $task->last_run );
			return array( 'result' => 'ok' );
		};
		$this->assertSame( 'closed', MainWP_Backup_Run_Cancel::process( (int) $task->id, 'cron' )['status'] );
		$this->assertSame( 'busy', $nested['status'] );
		$this->assertCount( 1, array_filter( $this->calls, static fn( $call ) => 'backup_task_cancel' === $call[0] ) );
	}

	/** Every send has a renewed lease and a transport bound restored after return. */
	public function test_cancel_timeout_is_scoped_and_lease_renews_per_send() {
		list( $task, $sites ) = $this->running( 2 );
		foreach ( $sites as $site ) {
			$this->progress( $task, $site, 'active-' . $site );
		}
		$this->request( $task, array( 'deadline' => $this->now + 12 ) );
		$baseline    = apply_filters( 'mainwp_fetch_url_site_timeout', 72000 );
		$this->reply = function () use ( $task ) {
			$state = json_decode( $this->fresh( $task )->run_cancel, true );
			$now   = MainWP_Backup_Incremental_Sender_Test::$fixed_time;
			$this->assertSame( $now + 120, $state['lease_until'] );
			$this->assertSame( min( 30, $state['lease_until'] - $now, $state['deadline'] - $now ), apply_filters( 'mainwp_fetch_url_site_timeout', 72000 ) );
			MainWP_Backup_Incremental_Sender_Test::$fixed_time += 2;
			return array( 'result' => 'ok' );
		};
		$this->assertSame( 'closed', MainWP_Backup_Run_Cancel::process( (int) $task->id, 'cron' )['status'] );
		$this->assertCount( 2, $this->calls );
		$this->assertSame( $baseline, apply_filters( 'mainwp_fetch_url_site_timeout', 72000 ) );
	}

	/** A successor's CAS prevents the old holder from sending the next site. */
	public function test_cancel_lease_takeover_stops_old_holder_before_next_send() {
		list( $task, $sites ) = $this->running( 2 );
		foreach ( $sites as $site ) {
			$this->progress( $task, $site, 'active-' . $site );
		}
		$this->request( $task );
		$this->reply = function () use ( $task ) {
			MainWP_Backup_Incremental_Sender_Test::$fixed_time += 121;
			$this->request(
				$this->fresh( $task ),
				array(
					'lease'       => 'successor',
					'lease_until' => MainWP_Backup_Incremental_Sender_Test::$fixed_time + 120,
				)
			);
			return array( 'result' => 'busy' );
		};
		$this->assertSame( 'busy', MainWP_Backup_Run_Cancel::process( (int) $task->id, 'old' )['status'] );
		$this->assertCount( 1, $this->calls );
		$this->assertSame( 'successor', json_decode( $this->fresh( $task )->run_cancel, true )['lease'] );
		MainWP_Backup_Incremental_Sender_Test::$fixed_time += 121;
		$this->reply                                        = array( 'result' => 'ok' );
		$this->assertSame( 'closed', MainWP_Backup_Run_Cancel::process( (int) $task->id, 'new' )['status'] );
	}

	/** Time crossing the deadline inside one request forbids the next send. */
	public function test_deadline_reached_mid_pass_stops_further_sends() {
		list( $task, $sites ) = $this->running( 2 );
		foreach ( $sites as $site ) {
			$this->progress( $task, $site, 'active-' . $site );
		}
		$this->request( $task, array( 'deadline' => $this->now + 10 ) );
		$this->reply = function () {
			MainWP_Backup_Incremental_Sender_Test::$fixed_time += 10;
			return array( 'result' => 'busy' );
		};
		$this->assertSame( 'not_confirmed', MainWP_Backup_Run_Cancel::process( (int) $task->id, 'cron' )['closed_as'] );
		$this->assertCount( 1, $this->calls );
	}

	/** Partial settlement persists, then a fresh sweep sends only the remaining ids. */
	public function test_three_send_cap_and_restart_resume_from_database() {
		list( $task, $sites ) = $this->running( 5 );
		foreach ( $sites as $site ) {
			$this->progress( $task, $site, 'active-' . $site );
		}
		$this->assertSame(
			array(
				'result'  => 'cancelling',
				'waiting' => 2,
			),
			$this->ajax( $task )
		);
		$this->assertCount( 3, $this->calls );
		$state = json_decode( $this->fresh( $task )->run_cancel, true );
		$this->assertCount( 3, $state['settled'] );
		$this->assertSame( '', $state['lease'] );
		MainWP_Backup_Run_Cancel::process_pending( 'restart' );
		$this->assertCount( 5, $this->calls );
		$this->assertCount( 5, array_unique( array_column( array_column( $this->calls, 2 ), 'task_id' ) ) );
		$this->assertSame( "Cancelled.\n", $this->fresh( $task )->backup_notices );
	}

	/** A new claim clears closed-run state, and a mismatched open request is stale. */
	public function test_claim_clears_stale_request_and_new_run_can_create() {
		list( $task, $sites ) = $this->running();
		$this->request( $task );
		$db = MainWP_DB_Backup::instance();
		$db->update_backup_completed( $task->id, (int) $task->last_run );
		$this->assertTrue( $db->claim_backup_run( $task->id ) );
		$fresh = $this->fresh( $task );
		$this->assertNull( $fresh->run_cancel );
		$this->request( $fresh, array( 'run' => (int) $task->last_run ) );
		$this->assertNull( MainWP_Backup_Run_Cancel::pending_request( $this->fresh( $task ) ) );
		$this->assertSame( 'stale', MainWP_Backup_Run_Cancel::process( (int) $task->id, 'cron' )['status'] );
		$this->assertNull( $this->fresh( $task )->run_cancel );
		MainWP_Backup_Executor::backup_site( $sites[0], $this->fresh( $task ), 'backups' );
		$this->assertCount( 1, array_filter( $this->calls, static fn( $call ) => 'backup_task_create' === $call[0] ) );
	}

	/** A due scheduler drives cancellation first and claims only on the next tick. */
	public function test_due_starter_finishes_cancel_before_claiming_next_run() {
		list( $task ) = $this->running(
			1,
			array(
				'schedule'       => 'interval',
				'interval_hours' => 2,
				'next_run'       => $this->now - 1,
			)
		);
		$this->request( $task );
		MainWP_System_Cron_Jobs::instance()->cron_backups();
		$this->assertSame( (int) $task->last_run, (int) $this->fresh( $task )->last_run );
		$this->assertSame( "Cancelled.\n", $this->fresh( $task )->backup_notices );
		$this->assertSame( (int) $task->next_run, (int) $this->fresh( $task )->next_run );
		MainWP_System_Cron_Jobs::instance()->cron_backups();
		$this->assertGreaterThan( (int) $task->last_run, (int) $this->fresh( $task )->last_run );
	}

	/** The driver closes inside the cancel pass's guarded-close SQL window. */
	public function test_competing_driver_close_wins_without_cancel_side_effects() {
		global $wpdb;
		list( $task ) = $this->running();
		$this->request( $task );
		$seen       = false;
		$interleave = function ( $sql ) use ( &$interleave, &$seen, $task ) {
			if ( str_contains( $sql, 'SET completed = GREATEST(' ) ) {
				remove_filter( 'query', $interleave );
				$this->assertTrue( MainWP_DB_Backup::instance()->update_backup_completed( $task->id, (int) $task->last_run ) );
				MainWP_Backup_Run_History::finalize_run( (int) $task->id, (int) $task->last_run, 'incomplete' );
				do_action( 'mainwp_backup_diag_run_close', (int) $task->id, (int) $task->last_run, 'all_sites_complete', 1 );
				$seen = true;
			}
			return $sql;
		};
		add_filter( 'query', $interleave );
		try {
			$this->assertSame( 'stale', MainWP_Backup_Run_Cancel::process( (int) $task->id, 'cron' )['status'] );
			$this->assertTrue( $seen );
			$this->assertSame( array( array( (int) $task->id, (int) $task->last_run, 'all_sites_complete' ) ), $this->closes );
			$this->assertSame( '', $this->fresh( $task )->backup_notices );
			$this->assertNull( MainWP_Backup_Run_Cancel::pending_request( $this->fresh( $task ) ) );
			$table = MainWP_DB::instance()->get_table_name( 'wp_backup_run_history' );
			$this->assertSame( array( 'incomplete' ), $wpdb->get_col( $wpdb->prepare( 'SELECT outcome FROM ' . $table . ' WHERE task_id = %d', $task->id ) ) );
		} finally {
			remove_filter( 'query', $interleave );
		}
	}

	/** The driver's all-sites close yields ownership to the cancel pass. */
	public function test_driver_defers_complete_run_close_to_cancellation() {
		list( $task, $sites ) = $this->running();
		MainWP_DB_Backup::instance()->update_completed_sites( $task->id, array( $sites[0] => true ) );
		$this->request( $task );
		$this->drive( $this->fresh( $task ) );
		$this->assertLessThan( (int) $task->last_run, (int) $this->fresh( $task )->completed );
		$this->assertSame( 'closed', MainWP_Backup_Run_Cancel::process( (int) $task->id, 'cron' )['status'] );
	}

	/** A poll and the cron driver can both enter without duplicating a cancel or close. */
	public function test_cron_driver_and_status_poll_close_cancel_once() {
		list( $task, $sites ) = $this->running();
		$this->progress( $task, $sites[0], 'once' );
		$this->request( $task );
		$this->drive( $this->fresh( $task ) );
		$this->calls = array();
		MainWP_Manage_Backups_Handler::get_backup_task_run_status( (int) $task->id, (int) $task->last_run );
		MainWP_Backup_Run_Cancel::process_pending( 'cron' );
		$this->assertCount( 1, array_filter( $this->calls, static fn( $call ) => 'backup_task_cancel' === $call[0] ) );
		$this->assertSame( "Cancelled.\n", $this->fresh( $task )->backup_notices );
		$this->assertSame( array( array( (int) $task->id, (int) $task->last_run, 'cancelled' ) ), $this->closes );
	}

	/** Request, pending pass, and close leave dispatch scheduling untouched. */
	public function test_cancel_never_advances_next_run() {
		list( $task, $sites ) = $this->running();
		$this->progress( $task, $sites[0], 'active' );
		$this->reply = array( 'result' => 'busy' );
		$this->ajax( $task );
		$this->assertSame( $task->next_run, $this->fresh( $task )->next_run );
		$this->reply = array( 'result' => 'ok' );
		MainWP_Backup_Run_Cancel::process_pending( 'cron' );
		$this->assertSame( $task->next_run, $this->fresh( $task )->next_run );
	}

	/**
	 * Exercise identity and rights refusals.
	 *
	 * @return array Refused identities and authorization modes.
	 */
	public static function refusal_cases(): array {
		return array(
			'right'       => array( 'right' ),
			'template'    => array( 'template' ),
			'owner'       => array( 'owner' ),
			'site'        => array( 'site' ),
			'non_numeric' => array( 'non_numeric' ),
			'zero'        => array( 'zero' ),
			'foreign'     => array( 'foreign' ),
			'run'         => array( 'run' ),
			'closed'      => array( 'closed' ),
		);
	}

	/**
	 * Refusals cannot mutate the task or contact a Child.
	 *
	 * @dataProvider refusal_cases
	 * @param string $mode Refusal under test.
	 */
	public function test_ajax_refuses_invalid_identity_or_rights_without_writes( string $mode ) {
		list( $task, $sites ) = $this->running();
		$id                   = $task->id;
		$run                  = $task->last_run;
		if ( 'template' === $mode ) {
			MainWP_DB_Backup::instance()->update_backup_task_with_values( $task->id, array( 'template' => 1 ) );
		} elseif ( 'owner' === $mode ) {
			MainWP_DB_Backup::instance()->update_backup_task_with_values( $task->id, array( 'userid' => 999999 ) );
		} elseif ( 'closed' === $mode ) {
			MainWP_DB_Backup::instance()->update_backup_completed( $task->id, (int) $task->last_run );
		} elseif ( 'non_numeric' === $mode ) {
			$id = 'garbage';
		} elseif ( 'zero' === $mode ) {
			$id = 0;
		} elseif ( 'foreign' === $mode ) {
			$id = 999999;
		} elseif ( 'run' === $mode ) {
			--$run;
		}
		$deny   = static function ( $allowed, $type, $cap ) use ( $mode, $sites ) {
			if ( ( 'right' === $mode && 'dashboard' === $type && 'pause_resume_backup_tasks' === $cap ) || ( 'site' === $mode && 'site' === $type && (int) $cap === $sites[0] ) ) {
				return false;
			}
			return $allowed;
		};
		$before = $this->fresh( $task );
		if ( 'owner' === $mode ) {
			add_filter( 'mainwp_is_single_user', '__return_false' );
		}
		add_filter( 'mainwp_currentusercan', $deny, 10, 3 );
		try {
			$response = $this->ajax( $task, $id, $run );
			$this->assertTrue( isset( $response['error'] ), wp_json_encode( $response ) );
			$this->assertEquals( $before, $this->fresh( $task ) );
			$this->assertSame( array(), $this->calls );
		} finally {
			remove_filter( 'mainwp_currentusercan', $deny, 10 );
			remove_filter( 'mainwp_is_single_user', '__return_false' );
		}
	}

	/**
	 * A failed nonce ends in a bare die(), so the handler is checked for the action it verifies
	 * and the nonce check itself is exercised without exiting.
	 */
	public function test_ajax_handler_verifies_its_own_nonce() {
		$method = new \ReflectionMethod( MainWP_Post_Backup_Handler::class, 'mainwp_native_backup_task_cancel_run' );
		$lines  = file( $method->getFileName() );
		$body   = implode( '', array_slice( $lines, $method->getStartLine(), $method->getEndLine() - $method->getStartLine() ) );
		$this->assertStringContainsString( '$this->secure_request( \'mainwp_native_backup_task_cancel_run\' );', $body, 'The nonce check must run before any input is read.' );
		$this->assertLessThan( strpos( $body, '$_POST' ), strpos( $body, 'secure_request' ) );
		$handler = MainWP_Post_Backup_Handler::instance();
		$cases   = array(
			''                                     => false,
			'mainwp_native_backup_task_reset'      => false,
			'mainwp_native_backup_task_cancel_run' => true,
		);
		foreach ( $cases as $action => $expected ) {
			$_REQUEST['security'] = '' === $action ? '' : wp_create_nonce( $action );
			$this->assertSame( $expected, $handler->check_security( 'mainwp_native_backup_task_cancel_run', 'security', false ), 'nonce for ' . ( '' === $action ? 'nothing' : $action ) );
		}
		unset( $_REQUEST['security'] );
	}

	/** Missing schema refuses cancellation while legacy claims remain usable. */
	public function test_readiness_gate_avoids_cancel_sql() {
		global $wpdb;
		list( $task ) = $this->running();
		update_site_option( MainWP_Install::BACKUP_TASK_RUN_CANCEL_REPAIR_PENDING, $this->now );
		$sqls    = array();
		$capture = static function ( $sql ) use ( &$sqls ) {
			$sqls[] = $sql;
			return $sql;
		};
		add_filter( 'query', $capture );
		try {
			$this->assertSame( array( 'error' => 'Cancel run is not available until the database update finishes.' ), $this->ajax( $task ) );
			$this->assertSame( 'none', MainWP_Backup_Run_Cancel::process( (int) $task->id, 'cron' )['status'] );
			MainWP_Backup_Run_Cancel::process_pending( 'cron' );
			$this->assertTrue( MainWP_DB_Backup::instance()->claim_backup_run( $task->id ) );
			foreach ( $sqls as $sql ) {
				if ( str_contains( $sql, $wpdb->prefix . 'mainwp_wp_backup' ) ) {
					$this->assertStringNotContainsString( 'run_cancel', $sql );
				}
			}
			$this->assertSame( '', $wpdb->last_error );
		} finally {
			remove_filter( 'query', $capture );
		}
	}

	/** A crash immediately after the request write needs no in-memory continuation. */
	public function test_request_written_before_crash_resumes_in_next_cron_pass() {
		list( $task, $sites ) = $this->running();
		$this->progress( $task, $sites[0], 'persisted-only' );
		$this->request( $task );
		MainWP_Backup_Run_Cancel::process_pending( 'cron' );
		$this->assertSame( "Cancelled.\n", $this->fresh( $task )->backup_notices );
		$this->assertSame( 'persisted-only', $this->calls[0][2]['task_id'] );
	}

	/** Both claim modes produce strictly increasing identities in one clock second. */
	public function test_claim_identities_never_repeat() {
		list( $task ) = $this->running();
		$db           = MainWP_DB_Backup::instance();
		$last         = (int) $task->last_run;
		for ( $i = 0; $i < 4; ++$i ) {
			$db->update_backup_completed( $task->id, $last );
			$db->update_backup_errors( $task->id, 'retry' );
			$this->assertTrue( $db->claim_backup_run( $task->id, $i % 2 ? 15 : 0, $next ) );
			$this->assertGreaterThan( $last, $next );
			$last = $next;
		}
	}

	/** A null response cannot settle a Child task or leave its timeout filter installed. */
	public function test_null_cancel_answer_remains_pending() {
		list( $task, $sites ) = $this->running();
		$this->progress( $task, $sites[0], 'null-answer' );
		$this->reply = null;
		$timeout     = apply_filters( 'mainwp_fetch_url_site_timeout', 72000 );
		$this->assertSame(
			array(
				'result'  => 'cancelling',
				'waiting' => 1,
			),
			$this->ajax( $task )
		);
		$this->assertSame( $timeout, apply_filters( 'mainwp_fetch_url_site_timeout', 72000 ) );
		$this->assertCount( 1, $this->calls );
	}

	/** A terminal Child row cannot discard a validated artifact waiting on its record. */
	public function test_cancel_waits_for_pending_snapshot_record() {
		list( $task, $sites ) = $this->running();
		$this->progress( $task, $sites[0], 'completed-child', 'completed' );
		$local = $this->diag . '/pending.tar.gz';
		file_put_contents( $local, 'validated archive' );
		MainWP_DB_Backup::instance()->update_backup_task_progress(
			$task->id,
			$sites[0],
			array(
				'downloadedFULL'         => $local,
				'downloadedFULLComplete' => 1,
			)
		);
		$this->assertTrue( MainWP_DB_Backup::instance()->has_pending_record_for_run( $task->id ) );
		$this->assertSame(
			array(
				'result'  => 'cancelling',
				'waiting' => 0,
			),
			$this->ajax( $task )
		);
		$this->assertLessThan( (int) $task->last_run, (int) $this->fresh( $task )->completed );
		MainWP_Backup_Snapshots::instance()->record_snapshot(
			array(
				'task_id' => (int) $task->id,
				'site_id' => $sites[0],
				'type'    => 'full',
			)
		);
		$this->assertSame( 'closed', MainWP_Backup_Run_Cancel::process( (int) $task->id, 'cron' )['status'] );
	}

	/** Refreshing the same request cannot move its deadline. */
	public function test_duplicate_cancel_request_keeps_original_deadline() {
		list( $task, $sites ) = $this->running();
		$this->progress( $task, $sites[0], 'busy' );
		$this->reply = array( 'result' => 'busy' );
		$this->ajax( $task );
		$before = MainWP_Backup_Run_Cancel::pending_request( $this->fresh( $task ) );
		MainWP_Backup_Incremental_Sender_Test::$fixed_time += 100;
		$this->ajax( $task );
		$this->assertSame( $before, MainWP_Backup_Run_Cancel::pending_request( $this->fresh( $task ) ) );
	}

	/** Missing rows still leave diagnostic evidence under the attempted run identity. */
	public function test_missing_task_error_append_is_logged() {
		$task = (object) array(
			'id'       => 999999,
			'last_run' => $this->now,
		);
		$this->assertFalse( MainWP_DB_Backup::instance()->append_backup_errors( (int) $task->id, (int) $task->last_run, 'missing-task' ) );
		$this->refused_error( $task, 'missing', 'missing-task' );
	}

	/** The UI projection is pure and output projections never expose raw cancellation state. */
	public function test_pending_request_projection_is_pure_and_private() {
		list( $task ) = $this->running();
		$this->assertNull( MainWP_Backup_Run_Cancel::pending_request( $task ) );
		$this->request( $task );
		$fresh = $this->fresh( $task );
		$this->assertSame(
			array(
				'requested' => $this->now,
				'deadline'  => $this->now + 1800,
			),
			MainWP_Backup_Run_Cancel::pending_request( $fresh )
		);
		$this->assertStringNotContainsString( 'run_cancel', wp_json_encode( MainWP_Backup_Read_Model::get_task( $fresh ) ) );
		unset( $fresh->run_cancel );
		$this->assertNull( MainWP_Backup_Run_Cancel::pending_request( $fresh ) );
	}
}
