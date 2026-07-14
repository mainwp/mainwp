<?php
/**
 * MainWP Backup Terminal Cleanup Tests
 *
 * Tests for the terminal-failure child-task cleanup and the bounded
 * run-op already-running yield: a run that goes terminal-failed while
 * its progress row still carries a real child task id fires a
 * best-effort precise-id cancel and clears the row (so the child does
 * not hold the site for the full wall clock and the progress poll stops
 * projecting "running / Waiting to start"), a creating marker is
 * cleared without a cancel (an empty-id cancel could kill a foreign
 * backup - the exact collision being handled), and a coded
 * already-running refusal on the RUN op yields briefly like the create
 * path instead of failing the job on its first slice.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Error_Codes;
use MainWP\Dashboard\MainWP_Backup_Executor;
use MainWP\Dashboard\MainWP_Backup_Monitor;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Manage_Backups_Handler;
use MainWP\Dashboard\MainWP_Post_Backup_Handler;

/**
 * Class MainWP_Backup_Terminal_Cleanup_Test
 */
class MainWP_Backup_Terminal_Cleanup_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Captured backup_task_cancel calls: one entry per call, holding the
	 * params array the dashboard sent to the child.
	 *
	 * @var array[]
	 */
	private $cancel_calls = array();

	/**
	 * What the stubbed child answers a backup_task_cancel with. Defaults to
	 * a landed cancel; a test that needs a lease-holding child overrides it
	 * with the child's real busy shape.
	 *
	 * @var array
	 */
	private $cancel_response = array( 'result' => 'ok' );

	/**
	 * Set up: enable native backups and capture child cancel calls.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		update_option( 'mainwp_enableNativeBackups', 1 );

		$this->cancel_calls    = array();
		$this->cancel_response = array( 'result' => 'ok' );
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what, $params ) {
				unset( $website );
				if ( 'backup_task_cancel' === $what ) {
					$this->cancel_calls[] = is_array( $params ) ? $params : array();
					return $this->cancel_response;
				}
				return $result;
			},
			10,
			4
		);
	}

	/**
	 * Clean up task, progress, and slot rows.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_wp_backup_progress" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_wp_backup" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup.
		delete_option( 'mainwp_enableNativeBackups' );
		$this->purge_slot_rows();
		parent::tearDown();
	}

	/**
	 * Free every DB-semaphore slot row so an acquisition can succeed.
	 *
	 * @return void
	 */
	private function purge_slot_rows() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( MainWP_Backup_Monitor::SLOT_OPTION_PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test cleanup of the DB semaphore.
	}

	/**
	 * Create a real backup task row with an open run over one site.
	 *
	 * @param array $over Field overrides applied after insert.
	 *
	 * @return array Site ID and task row.
	 */
	private function site_and_open_task( array $over = array() ) {
		$site_id = $this->create_test_site();
		$user    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$task    = MainWP_DB_Backup::instance()->add_backup_task( $user, 'Terminal cleanup test task', 'daily', 'full', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'tar.gz', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task, 'Precondition: the task row must insert.' );
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			(int) $task->id,
			array_merge(
				array(
					'last_run'  => time() - 100,
					'completed' => time() - 500,
				),
				$over
			)
		);
		return array( $site_id, MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id ) );
	}

	/**
	 * One task spanning two sites, with an open run.
	 *
	 * @param int $site_a First site.
	 * @param int $site_b Second site.
	 *
	 * @return object The task row.
	 */
	private function open_task_over( $site_a, $site_b ) {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$task = MainWP_DB_Backup::instance()->add_backup_task( $user, 'Gate multi-site task', 'daily', 'full', '', $site_a . ',' . $site_b, '', '', '', 0, 0, 0, 0, 0, 'tar.gz', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task, 'Precondition: the two-site task row must insert.' );
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			(int) $task->id,
			array(
				'last_run'  => time() - 100,
				'completed' => time() - 500,
			)
		);
		return MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
	}

	/**
	 * A site whose child is too old to run the task spine fails its own slice
	 * and nothing more: the healthy site in the same task is still reached and
	 * started, and the gated site is marked complete so the run can close.
	 * Without that, one un-updated child would wedge every other site on the
	 * task, which is the failure mode the gate must not introduce.
	 *
	 * @return void
	 */
	public function test_gated_site_does_not_wedge_the_other_site_in_the_task() {
		$old_site     = $this->create_test_site();
		$current_site = $this->create_test_site();
		$task         = $this->open_task_over( $old_site, $current_site );

		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what ) use ( $old_site ) {
				$is_old = (int) $website->id === (int) $old_site;
				if ( 'backup_preflight' === $what ) {
					return $is_old
						? array(
							'error'      => 'Required version has not been detected.',
							'error_code' => MainWP_Backup_Monitor::CHILD_INCOMPATIBLE_CODE,
						)
						: array(
							'task_spine'  => 1,
							'zip_support' => true,
						);
				}
				if ( 'backup_task_create' === $what ) {
					// Only a spine-capable child may ever be asked to create.
					return array( 'task_id' => 'task-current-site-1' );
				}
				return $result;
			},
			10,
			3
		);

		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 5, false );

		$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertStringContainsString( (string) $old_site, (string) $fresh->completed_sites, 'The gated site must be marked complete so the run can close, not left pending forever.' );
		$this->assertStringContainsString( 'too old to run backups', (string) $fresh->backup_errors, 'The task must carry the coded refusal for the old site.' );

		$old_row = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $old_site );
		$this->assertSame( '', (string) $old_row->child_backup_task_id, 'The gate throws before any child task exists, so the old site holds none.' );

		$current_row = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $current_site );
		$this->assertSame( 'task-current-site-1', (string) $current_row->child_backup_task_id, 'The healthy site must still be reached and started: one old child cannot wedge the task.' );
	}

	/**
	 * Seed a current-run progress row.
	 *
	 * @param int   $task_id Task ID.
	 * @param int   $site_id Site ID.
	 * @param array $values  Progress overrides.
	 *
	 * @return void
	 */
	private function seed_progress( $task_id, $site_id, $values = array() ) {
		MainWP_DB_Backup::instance()->add_backup_task_progress( $task_id, $site_id, array() );
		MainWP_DB_Backup::instance()->update_backup_task_progress(
			$task_id,
			$site_id,
			array_merge(
				array(
					'dtsFetched' => time(),
					'pid'        => 12345,
					'attempts'   => 0,
				),
				$values
			)
		);
	}

	/**
	 * Seed a row whose stored projection throws terminally with the retry
	 * budget already exhausted, carrying the given child task id.
	 *
	 * @param int    $task_id       Task ID.
	 * @param int    $site_id       Site ID.
	 * @param string $child_task_id Child task id left on the row.
	 *
	 * @return void
	 */
	private function seed_exhausted_throwing_row( $task_id, $site_id, $child_task_id ) {
		$this->seed_progress(
			$task_id,
			$site_id,
			array(
				'attempts'             => 5,
				'child_backup_task_id' => $child_task_id,
				'fetchResult'          => wp_json_encode(
					array(
						'full' => false,
						'db'   => false,
						'size' => 0,
					)
				),
			)
		);
	}

	/**
	 * Mock the child's backup_task_run response.
	 *
	 * @param array $response Response the child returns for the run op.
	 *
	 * @return void
	 */
	private function mock_run_response( array $response ) {
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what ) use ( $response ) {
				unset( $website );
				if ( 'backup_task_run' === $what ) {
					return $response;
				}
				return $result;
			},
			10,
			3
		);
	}

	/**
	 * A terminal error mark against a row carrying a real child task id
	 * fires a best-effort precise-id cancel and clears the row: the child
	 * stops holding the site for the wall clock, and the progress poll
	 * stops projecting "running / Waiting to start" for a failed run.
	 *
	 * @return void
	 */
	public function test_terminal_mark_cancels_child_task_and_clears_row() {
		list( $site_id, $task ) = $this->site_and_open_task();
		$this->seed_exhausted_throwing_row( (int) $task->id, $site_id, 'task-1783893277-real' );

		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, false );

		$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertStringContainsString( (string) $site_id, (string) $fresh->completed_sites, 'Precondition: the exhausted throw must mark the site.' );

		$this->assertCount( 1, $this->cancel_calls, 'A terminal mark over a live child task must fire exactly one cancel.' );
		$this->assertSame( 'task-1783893277-real', $this->cancel_calls[0]['task_id'] ?? null, 'The cancel must pass the precise child task id, never an empty auto-resolve.' );

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( '', (string) $progress->child_backup_task_id, 'The terminal mark must clear the child task id from the row.' );
		$this->assertSame( 'failed', (string) $progress->child_phase, 'The terminal mark must stamp a terminal phase so the poll projects failed, not running.' );

		$response = MainWP_Post_Backup_Handler::build_task_progress_response( $progress );
		$this->assertSame( 'failed', $response['status'], 'The cleared row must project a terminal status to the progress poll.' );
	}

	/**
	 * The cleanup helper against a row still holding a creating marker
	 * clears the marker locally but sends NO cancel: the marker is not an
	 * id the child knows, and an empty-id cancel would auto-resolve to
	 * whatever backup is running on the child - possibly a foreign one,
	 * which is the very collision that failed this run. (Driven directly:
	 * the executor clears its own marker before its throws, so this state
	 * only reaches the helper through edge races.)
	 *
	 * @return void
	 */
	public function test_terminal_mark_with_creating_marker_clears_without_cancel() {
		list( $site_id, $task ) = $this->site_and_open_task();
		$this->seed_progress(
			(int) $task->id,
			$site_id,
			array( 'child_backup_task_id' => MainWP_Backup_Executor::CREATING_MARKER_PREFIX . 'testmarker99' )
		);

		MainWP_Manage_Backups_Handler::close_child_task_after_terminal_mark( (int) $task->id, $site_id );

		$this->assertCount( 0, $this->cancel_calls, 'A creating marker is not a child-known id: no cancel may be sent for it.' );

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( '', (string) $progress->child_backup_task_id, 'The marker must still be cleared from the row.' );
		$this->assertSame( 'failed', (string) $progress->child_phase, 'The terminal mark must stamp a terminal phase.' );
	}

	/**
	 * A coded already-running refusal on the RUN op yields within the
	 * persistence window instead of failing the job on its first slice:
	 * the lock holder is often a short legacy one-shot, and the create
	 * path already treats the same condition as retryable.
	 *
	 * @return void
	 */
	public function test_run_op_coded_already_running_yields_within_window() {
		list( $site_id, $task ) = $this->site_and_open_task();
		$this->seed_progress(
			(int) $task->id,
			$site_id,
			array( 'child_backup_task_id' => 'task-run-yield-1' )
		);
		$this->mock_run_response(
			array(
				'error'      => 'Another backup process is running. Please, try again later.',
				'error_code' => MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING,
			)
		);

		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, false );

		$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertStringNotContainsString( (string) $site_id, (string) $fresh->completed_sites, 'A first coded refusal on the run op must yield, not mark the site.' );
		$this->assertSame( '', (string) $fresh->backup_errors, 'A yielded refusal must not record a failure.' );

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertGreaterThan( 0, (int) $progress->foreign_refused_since, 'The first refusal must start the persistence clock.' );
		$this->assertSame( 'task-run-yield-1', (string) $progress->child_backup_task_id, 'The yield must keep the child task id so the next cycle retries the run op.' );

		// A second pass inside the window must still yield.
		$this->purge_slot_rows();
		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, false );

		$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertStringNotContainsString( (string) $site_id, (string) $fresh->completed_sites, 'A refusal still inside the window must keep yielding.' );
		$this->assertCount( 0, $this->cancel_calls, 'No cancel may fire while the run is still yielding.' );
	}

	/**
	 * A coded already-running refusal that outlives the persistence window
	 * is a real blocker: the run fails honestly, the abandoned child task
	 * gets its precise-id cancel, and the row is cleared - instead of the
	 * old instant first-slice failure plus a 48-minute child-side hold.
	 *
	 * @return void
	 */
	public function test_run_op_coded_already_running_beyond_window_marks_cancels_and_clears() {
		list( $site_id, $task ) = $this->site_and_open_task( array( 'last_run' => time() - 300 ) );
		$this->seed_progress(
			(int) $task->id,
			$site_id,
			array(
				'child_backup_task_id'  => 'task-run-fail-1',
				'foreign_refused_since' => time() - MainWP_Backup_Executor::FOREIGN_REFUSAL_SURFACE_SECONDS - 30,
			)
		);
		$this->mock_run_response(
			array(
				'error'      => 'Another backup process is running. Please, try again later.',
				'error_code' => MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING,
			)
		);

		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, false );

		$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertStringContainsString( (string) $site_id, (string) $fresh->completed_sites, 'A refusal past the window must mark the site honestly.' );
		$this->assertNotSame( '', (string) $fresh->backup_errors, 'The surfaced refusal must record the real blocker.' );

		$this->assertCount( 1, $this->cancel_calls, 'The abandoned child task must get its best-effort cancel.' );
		$this->assertSame( 'task-run-fail-1', $this->cancel_calls[0]['task_id'] ?? null, 'The cancel must use the precise child task id.' );

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( '', (string) $progress->child_backup_task_id, 'The row must not keep projecting the failed child task.' );
		$this->assertSame( 'failed', (string) $progress->child_phase, 'The row must project the terminal failure.' );
	}

	/**
	 * An unreachable child cannot block the dashboard-side cleanup: the
	 * cancel attempt throws, and the row is still cleared so the UI stops
	 * projecting a run that will never advance.
	 *
	 * @return void
	 */
	public function test_terminal_mark_clears_row_even_when_cancel_throws() {
		list( $site_id, $task ) = $this->site_and_open_task();
		$this->seed_exhausted_throwing_row( (int) $task->id, $site_id, 'task-unreachable-1' );

		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what ) {
				unset( $website );
				if ( 'backup_task_cancel' === $what ) {
					throw new \Exception( 'cURL error 7: connection refused' );
				}
				return $result;
			},
			5,
			3
		);

		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, false );

		$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertStringContainsString( (string) $site_id, (string) $fresh->completed_sites, 'The mark must land despite the failed cancel.' );

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( '', (string) $progress->child_backup_task_id, 'The row must be cleared even when the child is unreachable.' );
		$this->assertSame( 'failed', (string) $progress->child_phase, 'The terminal stamp must not depend on the child answering.' );
	}

	/**
	 * Read the cancels this site still owes the child.
	 *
	 * @param int $site_id Site ID.
	 *
	 * @return array Decoded pending-cancel map (child task id => attempts).
	 */
	private function pending_cancel( $site_id ) {
		$website = MainWP_DB::instance()->get_website_by_id( (int) $site_id );
		$raw     = MainWP_DB::instance()->get_website_option( $website, MainWP_Manage_Backups_Handler::PENDING_CANCEL_OPTION );
		$decoded = json_decode( (string) $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * A child whose runner holds the task lease refuses the cancel as busy -
	 * a plain result, not an error. The terminal mark must not read that as
	 * a landed cancel: the task is still ACTIVE on the child and refuses the
	 * site's next backup, so the cancel is owed to a later pass.
	 *
	 * @return void
	 */
	public function test_busy_cancel_refusal_is_recorded_as_owed() {
		list( $site_id, $task ) = $this->site_and_open_task();
		$this->seed_exhausted_throwing_row( (int) $task->id, $site_id, 'task-leased-1' );
		$this->cancel_response = array(
			'result' => 'busy',
			'phase'  => 'archiving',
		);

		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, false );

		$this->assertCount( 1, $this->cancel_calls, 'The terminal mark must still attempt the cancel once.' );

		$pending = $this->pending_cancel( $site_id );
		$this->assertArrayHasKey( 'task-leased-1', $pending, 'A busy refusal must leave the precise child task id owed to a later pass.' );
		$this->assertSame( 0, (int) $pending['task-leased-1'], 'The owed cancel starts with a clean retry count.' );

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( '', (string) $progress->child_backup_task_id, 'The row clear must stay immediate: only the child-side cancel gains persistence.' );
		$this->assertSame( 'failed', (string) $progress->child_phase, 'The terminal stamp must not wait on the child taking the cancel.' );
	}

	/**
	 * The next run over the site lands the owed cancel BEFORE it asks for a
	 * new backup - which is the whole point: an abandoned task refuses the
	 * create, so it has to be cleared first.
	 *
	 * @return void
	 */
	public function test_next_run_lands_the_owed_cancel_before_creating() {
		list( $site_id, $task ) = $this->site_and_open_task();
		$website                = MainWP_DB::instance()->get_website_by_id( (int) $site_id );
		MainWP_DB::instance()->update_website_option(
			$website,
			MainWP_Manage_Backups_Handler::PENDING_CANCEL_OPTION,
			wp_json_encode(
				array(
					'task_id'  => 'task-abandoned-9',
					'attempts' => 0,
				)
			)
		);
		$this->seed_exhausted_throwing_row( (int) $task->id, $site_id, 'task-current-1' );

		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, false );

		$this->assertNotEmpty( $this->cancel_calls, 'The run must talk to the child about the abandoned task.' );
		$this->assertSame( 'task-abandoned-9', $this->cancel_calls[0]['task_id'] ?? null, 'The owed cancel must be retried first, before this run touches the site.' );
		$this->assertSame( array(), $this->pending_cancel( $site_id ), 'A cancel the child takes must stop being owed.' );
	}

	/**
	 * A site can belong to more than one backup task, so two busy-refused
	 * terminal marks close together must BOTH stay owed: the second record
	 * merging into the map instead of overwriting it is what keeps the first
	 * task's abandoned child task from being silently forgotten until the
	 * child's own wall clock. The flush then lands every owed cancel, not
	 * just the last one written.
	 *
	 * @return void
	 */
	public function test_two_tasks_on_one_site_both_keep_their_owed_cancels() {
		list( $site_id, $task_a ) = $this->site_and_open_task();
		$user                     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$task_b                   = MainWP_DB_Backup::instance()->add_backup_task( $user, 'Second task same site', 'daily', 'full', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'tar.gz', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task_b, 'Precondition: the second task row must insert.' );
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			(int) $task_b->id,
			array(
				'last_run'  => time() - 100,
				'completed' => time() - 500,
			)
		);
		$task_b = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task_b->id );

		$this->seed_exhausted_throwing_row( (int) $task_a->id, $site_id, 'task-child-a' );
		$this->seed_exhausted_throwing_row( (int) $task_b->id, $site_id, 'task-child-b' );
		$this->cancel_response = array( 'result' => 'busy' );

		MainWP_Manage_Backups_Handler::execute_backup_task( $task_a, 1, false );
		MainWP_Manage_Backups_Handler::execute_backup_task( $task_b, 1, false );

		$pending = $this->pending_cancel( $site_id );
		$this->assertArrayHasKey( 'task-child-a', $pending, 'The first task\'s owed cancel must survive the second terminal mark.' );
		$this->assertArrayHasKey( 'task-child-b', $pending, 'The second task\'s owed cancel must be owed alongside the first, not instead of it.' );

		// The child frees up: the next flush must land BOTH owed cancels.
		$this->cancel_response = array( 'result' => 'ok' );
		$this->cancel_calls    = array();
		$website               = MainWP_DB::instance()->get_website_by_id( (int) $site_id );
		MainWP_Manage_Backups_Handler::flush_pending_child_cancel( $website );

		$sent = array_map( fn( $call ) => $call['task_id'] ?? '', $this->cancel_calls );
		$this->assertContains( 'task-child-a', $sent, 'The flush must retry the first owed cancel.' );
		$this->assertContains( 'task-child-b', $sent, 'The flush must retry the second owed cancel.' );
		$this->assertSame( array(), $this->pending_cancel( $site_id ), 'Landed cancels must stop being owed.' );
	}

	/**
	 * An error payload proves nothing about the task: the child may be mid
	 * bootstrap failure, or the id may simply be one it already reclaimed,
	 * and the not-found message is translatable so it cannot be matched.
	 * Either way the cancel must stay owed - reading an error as "cancelled"
	 * would drop the retry while the task may still be active, which is the
	 * exact bug the busy check fixed, arriving through a different shape.
	 *
	 * @return void
	 */
	public function test_error_response_keeps_the_cancel_owed() {
		list( $site_id ) = $this->site_and_open_task();
		$website         = MainWP_DB::instance()->get_website_by_id( (int) $site_id );
		MainWP_DB::instance()->update_website_option(
			$website,
			MainWP_Manage_Backups_Handler::PENDING_CANCEL_OPTION,
			wp_json_encode( array( 'task-erroring-3' => 0 ) )
		);
		$this->cancel_response = array( 'error' => 'La tâche de sauvegarde est introuvable.' );

		MainWP_Manage_Backups_Handler::flush_pending_child_cancel( $website );

		$this->assertCount( 1, $this->cancel_calls, 'The flush must still attempt the cancel.' );
		$pending = $this->pending_cancel( $site_id );
		$this->assertSame( 1, (int) ( $pending['task-erroring-3'] ?? -1 ), 'An error answer must consume one attempt and stay owed, never read as landed.' );
	}

	/**
	 * A response without an explicit result is equally unproven: only the
	 * child saying something about the task counts as landed.
	 *
	 * @return void
	 */
	public function test_malformed_response_keeps_the_cancel_owed() {
		list( $site_id ) = $this->site_and_open_task();
		$website         = MainWP_DB::instance()->get_website_by_id( (int) $site_id );
		MainWP_DB::instance()->update_website_option(
			$website,
			MainWP_Manage_Backups_Handler::PENDING_CANCEL_OPTION,
			wp_json_encode( array( 'task-odd-4' => 0 ) )
		);
		$this->cancel_response = array( 'unexpected' => 'shape' );

		MainWP_Manage_Backups_Handler::flush_pending_child_cancel( $website );

		$pending = $this->pending_cancel( $site_id );
		$this->assertSame( 1, (int) ( $pending['task-odd-4'] ?? -1 ), 'A shape without a result key must stay owed.' );
	}

	/**
	 * The retry is bounded. The child has its own queued reclaim and wall
	 * clock; a cancel that never lands must not be retried forever against a
	 * task id that has long since expired.
	 *
	 * @return void
	 */
	public function test_owed_cancel_gives_up_at_the_cap() {
		list( $site_id ) = $this->site_and_open_task();
		$website         = MainWP_DB::instance()->get_website_by_id( (int) $site_id );
		MainWP_DB::instance()->update_website_option(
			$website,
			MainWP_Manage_Backups_Handler::PENDING_CANCEL_OPTION,
			wp_json_encode(
				array(
					'task_id'  => 'task-stuck-7',
					'attempts' => MainWP_Manage_Backups_Handler::PENDING_CANCEL_MAX_ATTEMPTS - 1,
				)
			)
		);
		$this->cancel_response = array( 'result' => 'busy' );

		MainWP_Manage_Backups_Handler::flush_pending_child_cancel( $website );

		$this->assertCount( 1, $this->cancel_calls, 'The final attempt still goes out.' );
		$this->assertSame( array(), $this->pending_cancel( $site_id ), 'At the cap the owed cancel is dropped instead of retried forever.' );
	}
}
