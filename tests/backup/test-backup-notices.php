<?php
/**
 * MainWP Backup Notices Tests
 *
 * The large-site ZIP auto-switch produces an informational notice, not a
 * failure. These tests pin the separation between the two channels: notices
 * land in wp_backup.backup_notices and never flip a successful run to
 * "failed" in the on-demand poll, never trigger the failure email, and are
 * rendered as information rather than errors.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Executor;
use MainWP\Dashboard\MainWP_Backup_Monitor;
use MainWP\Dashboard\MainWP_Backup_Restore_Job_Store;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Exception;
use MainWP\Dashboard\MainWP_Manage_Backups_Handler;
use MainWP\Dashboard\MainWP_Post_Backup_Handler;

/**
 * Class MainWP_Backup_Notices_Test
 */
class MainWP_Backup_Notices_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Set up: enable native backups.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		update_option( 'mainwp_enableNativeBackups', 1 );
	}

	/**
	 * Clean up rows and request state created by the tests.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_wp_backup_progress" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_wp_backup" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_wp_backup_snapshot" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_wp_backup_restore_job" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup.
		delete_option( 'mainwp_enableNativeBackups' );
		delete_option( 'mainwp_notificationOnBackupFail' );
		unset( $_POST['security'], $_POST['site_id'], $_POST['since'], $_REQUEST['security'], $_REQUEST['site_id'] );
		parent::tearDown();
	}

	/**
	 * A healthy preflight response.
	 *
	 * @param array $overrides Key overrides.
	 *
	 * @return array Preflight payload.
	 */
	private function preflight_response( $overrides = array() ) {
		return array_merge(
			array(
				'disk_free'       => 10 * GB_IN_BYTES,
				'dir_writable'    => true,
				'db_connected'    => true,
				'memory_limit'    => '256M',
				'max_exec_time'   => '30',
				'zip_support'     => true,
				'gz_support'      => true,
				'bz2_support'     => true,
				'time_limit_ok'   => true,
				'backup_dir_hash' => 'abc123def456gh78',
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
	 * Create a real scheduled backup task row targeting one site.
	 *
	 * @param int   $site_id Site id the task targets.
	 * @param array $over    Field overrides applied after insert.
	 *
	 * @return object Task row.
	 */
	private function create_task_row( $site_id, array $over = array() ) {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$task = MainWP_DB_Backup::instance()->add_backup_task(
			$user,
			'Notices test task',
			'daily',
			'full',
			'',
			(string) $site_id,
			'',
			'',
			'',
			0,
			0,
			0,
			0,
			0,
			'zip',
			0,
			1,
			150,
			0,
			0
		);
		$this->assertNotFalse( $task, 'Precondition: the task row must insert.' );
		if ( ! empty( $over ) ) {
			MainWP_DB_Backup::instance()->update_backup_task_with_values( (int) $task->id, $over );
			$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		}
		return $task;
	}

	/**
	 * Invoke the on-demand progress poll for a site and return the decoded
	 * JSON payload, using the ajax-termination pattern shared with the
	 * restore progress tests.
	 *
	 * @param int $site_id Site to poll.
	 * @param int $since   Click-time last_run baseline sent by the page.
	 *
	 * @return array|null Decoded response payload.
	 */
	private function poll_on_demand( $site_id, $since = 0 ) {
		$nonce                = wp_create_nonce( 'mainwp_native_backup_progress' );
		$_POST['security']    = $nonce;
		$_REQUEST['security'] = $nonce;
		$_POST['site_id']     = $site_id;
		if ( $since > 0 ) {
			$_POST['since'] = (string) $since;
		} else {
			unset( $_POST['since'] );
		}

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
			MainWP_Post_Backup_Handler::instance()->mainwp_native_backup_progress();
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
	 * Free every DB-semaphore slot row so an acquisition can succeed.
	 *
	 * @return void
	 */
	private function purge_slot_rows() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( MainWP_Backup_Monitor::SLOT_OPTION_PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test cleanup of the DB semaphore.
	}

	/**
	 * Seed the hidden manual task for a site into a given run state.
	 *
	 * @param int   $site_id Site id.
	 * @param array $over    Field overrides.
	 *
	 * @return object Task row.
	 */
	private function manual_task_in_state( $site_id, array $over ) {
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$task    = MainWP_DB_Backup::instance()->ensure_manual_backup_task( $website );
		$this->assertNotEmpty( $task, 'Precondition: the on-demand task must exist.' );
		MainWP_DB_Backup::instance()->update_backup_task_with_values( (int) $task->id, $over );
		return MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
	}

	/**
	 * The exact reported bug: a duplicate initiate bumped last_run after
	 * the snapshot landed, and the freshness check keyed to last_run could
	 * never pass. Keyed to the click's own timestamp, the poll reports the
	 * completed backup that exists.
	 *
	 * @return void
	 */
	public function test_on_demand_poll_reports_completed_using_since_despite_later_reinit_bump() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$site_id = $this->create_test_site();
		$click   = time() - 40;

		$task = $this->manual_task_in_state(
			$site_id,
			array(
				'last_run'  => $click,
				'completed' => time() - 20,
			)
		);
		MainWP_Backup_Snapshots::instance()->record_snapshot(
			array(
				'task_id' => (int) $task->id,
				'site_id' => $site_id,
				'type'    => 'full',
			)
		);

		// The later re-init: last_run bumps past the snapshot, run closes again.
		$this->manual_task_in_state(
			$site_id,
			array(
				'last_run'  => time() + 10,
				'completed' => time() + 20,
			)
		);

		$response = $this->poll_on_demand( $site_id, $click );

		$this->assertIsArray( $response );
		$this->assertSame( 'completed', $response['status'] ?? null, 'The click that produced the snapshot must see completed, whatever a later re-init did to last_run.' );
	}

	/**
	 * Without a since value (a tab running pre-fix JavaScript) the poll
	 * keeps today's last_run comparison unchanged.
	 *
	 * @return void
	 */
	public function test_on_demand_poll_without_since_falls_back_to_last_run_backward_compat() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$site_id = $this->create_test_site();

		$task = $this->manual_task_in_state(
			$site_id,
			array(
				'last_run'  => time() - 40,
				'completed' => time() - 20,
			)
		);
		MainWP_Backup_Snapshots::instance()->record_snapshot(
			array(
				'task_id' => (int) $task->id,
				'site_id' => $site_id,
				'type'    => 'full',
			)
		);
		$this->manual_task_in_state(
			$site_id,
			array(
				'last_run'  => time() + 10,
				'completed' => time() + 20,
			)
		);

		$response = $this->poll_on_demand( $site_id );

		$this->assertIsArray( $response );
		$this->assertSame( 'failed', $response['status'] ?? null, 'Absent since, the last_run comparison stays exactly as before.' );
	}

	/**
	 * A later run's recorded errors belong to that run: they must not fail
	 * a click whose own snapshot exists.
	 *
	 * @return void
	 */
	public function test_on_demand_poll_ignores_error_from_later_reinit() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$site_id = $this->create_test_site();
		$click   = time() - 40;

		$task = $this->manual_task_in_state(
			$site_id,
			array(
				'last_run'  => $click,
				'completed' => time() - 20,
			)
		);
		MainWP_Backup_Snapshots::instance()->record_snapshot(
			array(
				'task_id' => (int) $task->id,
				'site_id' => $site_id,
				'type'    => 'full',
			)
		);

		// A later run starts past the snapshot and fails.
		$this->manual_task_in_state(
			$site_id,
			array(
				'last_run'  => time() + 10,
				'completed' => time() + 20,
			)
		);
		MainWP_DB_Backup::instance()->update_backup_errors( (int) $task->id, 'The later run failed on the child site.' );

		$response = $this->poll_on_demand( $site_id, $click );

		$this->assertIsArray( $response );
		$this->assertSame( 'completed', $response['status'] ?? null, 'A later run\'s failure must not poison a click whose snapshot exists.' );
	}

	/**
	 * A same-run destination failure (snapshot recorded, then errors in
	 * that same run) still reports failed with its errors - the reorder
	 * must not soften genuine partial failures.
	 *
	 * @return void
	 */
	public function test_on_demand_poll_still_reports_own_run_destination_failure() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$site_id = $this->create_test_site();
		$click   = time() - 100;

		$task = $this->manual_task_in_state(
			$site_id,
			array(
				'last_run'  => $click,
				'completed' => time(),
			)
		);
		MainWP_DB_Backup::instance()->update_backup_errors( (int) $task->id, 'The offsite upload failed after the snapshot was recorded.' );
		MainWP_Backup_Snapshots::instance()->record_snapshot(
			array(
				'task_id' => (int) $task->id,
				'site_id' => $site_id,
				'type'    => 'full',
			)
		);

		$response = $this->poll_on_demand( $site_id, $click );

		$this->assertIsArray( $response );
		$this->assertSame( 'failed', $response['status'] ?? null, 'Errors from the run that produced the snapshot must still fail the poll.' );
		$this->assertStringContainsString( 'offsite upload failed', (string) ( $response['error'] ?? '' ) );
	}

	/**
	 * A run that failed before producing any snapshot surfaces its own
	 * errors when polled with since - the no-snapshot attribution branch.
	 *
	 * @return void
	 */
	public function test_on_demand_poll_shows_own_errors_when_no_snapshot() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$site_id = $this->create_test_site();
		$click   = time() - 60;

		$task = $this->manual_task_in_state(
			$site_id,
			array(
				'last_run'  => time() - 50,
				'completed' => time(),
			)
		);
		MainWP_DB_Backup::instance()->update_backup_errors( (int) $task->id, 'The database dump failed before any snapshot.' );

		$response = $this->poll_on_demand( $site_id, $click );

		$this->assertIsArray( $response );
		$this->assertSame( 'failed', $response['status'] ?? null );
		$this->assertStringContainsString( 'database dump failed', (string) ( $response['error'] ?? '' ), 'My own run\'s errors must surface, not the generic message.' );
	}

	/**
	 * Invoke the on-demand initiate handler and return the decoded
	 * response.
	 *
	 * @param int $site_id Site id.
	 *
	 * @return array|null Decoded response payload.
	 */
	private function initiate_on_demand( $site_id ) {
		$nonce                = wp_create_nonce( 'mainwp_native_backup_initiate' );
		$_POST['security']    = $nonce;
		$_REQUEST['security'] = $nonce;
		$_POST['site_id']     = $site_id;

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
			MainWP_Post_Backup_Handler::instance()->mainwp_native_backup_initiate();
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
	 * A duplicate initiate landing seconds after a successful close is
	 * absorbed: no re-init, no second child task, and the response points
	 * the poll at the run that produced the snapshot.
	 *
	 * @return void
	 */
	public function test_initiate_absorbs_duplicate_click_within_settle_window() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$site_id      = $this->create_test_site();
		$old_last_run = time() - 30;

		$this->manual_task_in_state(
			$site_id,
			array(
				'last_run'  => $old_last_run,
				'completed' => time() - 4,
			)
		);

		$child_calls = array();
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what ) use ( &$child_calls ) {
				unset( $website );
				$child_calls[] = $what;
				return is_array( $result ) ? $result : array( 'result' => 'ok' );
			},
			10,
			3
		);

		$response = $this->initiate_on_demand( $site_id );

		$this->assertIsArray( $response );
		$this->assertSame( 'queued', $response['status'] ?? null );
		$this->assertSame( $old_last_run, (int) ( $response['started'] ?? 0 ), 'The absorbed duplicate must key its poll to the run that produced the snapshot, not to its own click.' );
		$this->assertSame( array(), $child_calls, 'An absorbed duplicate must reach no child site.' );

		$task = MainWP_DB_Backup::instance()->get_manual_backup_task( $site_id );
		$this->assertSame( $old_last_run, (int) $task->last_run, 'An absorbed duplicate must not reopen the run.' );
	}

	/**
	 * The large-site ZIP switch records its notice on the task's notices
	 * channel and leaves backup_errors untouched: an informational format
	 * switch is not a failure and must not read as one anywhere errors do.
	 *
	 * @return void
	 */
	public function test_zip_switch_notice_recorded_as_notice_not_error() {
		$site_id = $this->create_test_site();
		$task    = $this->create_task_row( $site_id );

		$this->mock_child_actions(
			array(
				'backup_preflight' => $this->preflight_response( array( 'estimated_bytes' => 3 * GB_IN_BYTES ) ),
				'backup'           => array( 'error' => 'stop here' ),
			)
		);

		try {
			MainWP_Backup_Executor::backup_site( $site_id, $task, '' );
		} catch ( MainWP_Exception $e ) {
			// The mocked child stops the run after negotiation, as intended.
		}

		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertStringNotContainsString( 'switched', (string) $task->backup_errors, 'The switch notice must not pollute backup_errors.' );
		$this->assertStringContainsString( 'switched to tar.gz', (string) $task->backup_notices, 'The switch notice must be recorded on the notices channel.' );
	}

	/**
	 * A closed on-demand run with a fresh snapshot and only a notice recorded
	 * reports completed, carrying the notice for display. This is the exact
	 * inversion bug: the switch used to land in backup_errors and flip a
	 * successful backup to "failed".
	 *
	 * @return void
	 */
	public function test_on_demand_poll_completes_when_only_notice_recorded() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );

		$task = MainWP_DB_Backup::instance()->ensure_manual_backup_task( $website );
		$this->assertNotEmpty( $task, 'Precondition: the on-demand task must exist.' );

		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			(int) $task->id,
			array(
				'last_run'  => time() - 100,
				'completed' => time(),
			)
		);
		MainWP_DB_Backup::instance()->update_backup_notices( (int) $task->id, 'ZIP was switched to tar.gz for this large site (estimated 3 GB).' );
		MainWP_Backup_Snapshots::instance()->record_snapshot(
			array(
				'task_id' => (int) $task->id,
				'site_id' => $site_id,
				'type'    => 'full',
			)
		);

		$response = $this->poll_on_demand( $site_id );

		$this->assertIsArray( $response );
		$this->assertSame( 'completed', $response['status'] ?? null, 'A notice-only successful run must report completed, not failed.' );
		$this->assertStringContainsString( 'switched to tar.gz', (string) ( $response['notice'] ?? '' ), 'The completed response must carry the notice for display.' );
	}

	/**
	 * A closed on-demand run with a real recorded error still reports failed:
	 * separating notices must not soften genuine failures.
	 *
	 * @return void
	 */
	public function test_on_demand_poll_reports_failure_when_error_recorded() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );

		$task = MainWP_DB_Backup::instance()->ensure_manual_backup_task( $website );
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			(int) $task->id,
			array(
				'last_run'  => time() - 100,
				'completed' => time(),
			)
		);
		MainWP_DB_Backup::instance()->update_backup_errors( (int) $task->id, 'The database dump failed on the child site.' );
		MainWP_Backup_Snapshots::instance()->record_snapshot(
			array(
				'task_id' => (int) $task->id,
				'site_id' => $site_id,
				'type'    => 'full',
			)
		);

		$response = $this->poll_on_demand( $site_id );

		$this->assertIsArray( $response );
		$this->assertSame( 'failed', $response['status'] ?? null, 'A run with a recorded error must still report failed.' );
		$this->assertStringContainsString( 'database dump failed', (string) ( $response['error'] ?? '' ) );
	}

	/**
	 * Notices append with de-duplication (per-site negotiation of a
	 * multi-site task repeats the same message) and clear on an empty write,
	 * mirroring the errors channel's reset-at-run-start contract.
	 *
	 * @return void
	 */
	public function test_update_backup_notices_dedupes_and_clears() {
		$site_id = $this->create_test_site();
		$task    = $this->create_task_row( $site_id );
		$db      = MainWP_DB_Backup::instance();

		$db->update_backup_notices( (int) $task->id, "Switched to tar.gz.\n" );
		$db->update_backup_notices( (int) $task->id, "Switched to tar.gz.\n" );
		$this->assertSame( 1, substr_count( (string) $db->get_backup_task_by_id( $task->id )->backup_notices, 'Switched to tar.gz.' ), 'A repeated notice must be stored once.' );

		$db->update_backup_notices( (int) $task->id, "A different note.\n" );
		$this->assertStringContainsString( 'A different note.', (string) $db->get_backup_task_by_id( $task->id )->backup_notices, 'Distinct notices must append.' );

		$db->update_backup_notices( (int) $task->id, '' );
		$this->assertSame( '', (string) $db->get_backup_task_by_id( $task->id )->backup_notices, 'An empty write must clear the channel.' );
	}

	/**
	 * The switch notice tells scheduled-task users where the override lives,
	 * but the hidden on-demand task has no settings form, so its variant must
	 * not advertise a "keep ZIP" control the user cannot reach.
	 *
	 * @return void
	 */
	public function test_switch_notice_for_hidden_on_demand_task_omits_keep_zip_hint() {
		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );

		$this->mock_child_actions(
			array( 'backup_preflight' => $this->preflight_response( array( 'estimated_bytes' => 3 * GB_IN_BYTES ) ) )
		);

		$captured = array();
		add_action(
			'mainwp_log_warning',
			function ( $message ) use ( &$captured ) {
				$captured[] = $message;
			}
		);

		$ext = 'zip';
		MainWP_Backup_Monitor::negotiate_backup_preflight(
			$website,
			$ext,
			(object) array(
				'id'                 => 0,
				'template'           => 1,
				'zip_force_override' => 0,
			)
		);

		$this->assertSame( 'tar.gz', $ext, 'The hidden task still auto-switches.' );
		$switch_messages = array_values(
			array_filter(
				$captured,
				function ( $message ) {
					return false !== stripos( $message, 'switched to tar.gz' );
				}
			)
		);
		$this->assertNotEmpty( $switch_messages, 'The switch must still be surfaced.' );
		$this->assertStringNotContainsString( 'keep ZIP', $switch_messages[0], 'The on-demand variant must not advertise the task-form override.' );
	}

	/**
	 * A scheduled task's switch notice keeps the override hint: the checkbox
	 * exists on its settings form.
	 *
	 * @return void
	 */
	public function test_switch_notice_for_scheduled_task_keeps_keep_zip_hint() {
		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );

		$this->mock_child_actions(
			array( 'backup_preflight' => $this->preflight_response( array( 'estimated_bytes' => 3 * GB_IN_BYTES ) ) )
		);

		$captured = array();
		add_action(
			'mainwp_log_warning',
			function ( $message ) use ( &$captured ) {
				$captured[] = $message;
			}
		);

		$ext = 'zip';
		MainWP_Backup_Monitor::negotiate_backup_preflight(
			$website,
			$ext,
			(object) array(
				'id'                 => 0,
				'template'           => 0,
				'zip_force_override' => 0,
			)
		);

		$switch_messages = array_values(
			array_filter(
				$captured,
				function ( $message ) {
					return false !== stripos( $message, 'switched to tar.gz' );
				}
			)
		);
		$this->assertNotEmpty( $switch_messages );
		$this->assertStringContainsString( 'keep ZIP', $switch_messages[0], 'The scheduled-task variant keeps the override hint.' );
	}

	/**
	 * The failure email fires for a run with real errors even though WP
	 * returns the option as a string across requests ("1", not int 1) — the
	 * old strict int comparison made the gate dead code on cron runs.
	 *
	 * @return void
	 */
	public function test_backup_error_email_sends_for_real_errors_despite_string_option() {
		$site_id = $this->create_test_site();
		$task    = $this->create_task_row( $site_id );
		MainWP_DB_Backup::instance()->update_backup_errors( (int) $task->id, 'Site: child. The database dump failed.' );

		update_option( 'mainwp_notificationOnBackupFail', '1' );

		$sent = array();
		add_filter(
			'pre_wp_mail',
			function ( $short_circuit, $atts ) use ( &$sent ) {
				$sent[] = $atts;
				return true;
			},
			10,
			2
		);

		MainWP_Manage_Backups_Handler::maybe_send_backup_error_email( MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id ) );

		$this->assertCount( 1, $sent, 'A run with real errors must send the failure email when notifications are on.' );
		$this->assertStringContainsString( 'Error', (string) $sent[0]['subject'] );
		$this->assertSame( 'Site: child. The database dump failed.', (string) MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id )->backup_errors, 'The on-demand poll surfaces the failure after close; the run-open reset is what keeps the next run clean, not the email.' );
	}

	/**
	 * A run whose only record is a notice sends no failure email.
	 *
	 * @return void
	 */
	public function test_backup_error_email_not_sent_for_notice_only_run() {
		$site_id = $this->create_test_site();
		$task    = $this->create_task_row( $site_id );
		MainWP_DB_Backup::instance()->update_backup_notices( (int) $task->id, 'ZIP was switched to tar.gz for this large site.' );

		update_option( 'mainwp_notificationOnBackupFail', '1' );

		$sent = array();
		add_filter(
			'pre_wp_mail',
			function ( $short_circuit, $atts ) use ( &$sent ) {
				$sent[] = $atts;
				return true;
			},
			10,
			2
		);

		MainWP_Manage_Backups_Handler::maybe_send_backup_error_email( MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id ) );

		$this->assertCount( 0, $sent, 'A notice-only run must not trigger the failure email.' );
	}

	/**
	 * A click whose initiate lost the concurrency-slot race never opened a
	 * run: the row it finds on poll is a stale closed run from long before.
	 * With every slot busy the retry the poll attempts cannot claim one
	 * either, so the honest report is that the backup is still queued, not
	 * that it failed - failing here would misreport the stale closed run as
	 * this click's outcome.
	 *
	 * @return void
	 */
	public function test_on_demand_poll_reports_queued_when_click_never_opened_and_slots_busy() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$site_id = $this->create_test_site();

		$this->manual_task_in_state(
			$site_id,
			array(
				'last_run'      => time() - 3600,
				'completed'     => time() - 3500,
				'backup_errors' => '',
			)
		);

		// Hold both default slots so the retry claim has no capacity.
		$held_a = MainWP_Backup_Monitor::acquire_backup_slot( 2 );
		$held_b = MainWP_Backup_Monitor::acquire_backup_slot( 2 );
		$this->assertIsString( $held_a, 'Precondition: the first slot must be held.' );
		$this->assertIsString( $held_b, 'Precondition: the second slot must be held.' );

		try {
			$since    = time() - 5;
			$response = $this->poll_on_demand( $site_id, $since );
		} finally {
			MainWP_Backup_Monitor::release_backup_slot( $held_a );
			MainWP_Backup_Monitor::release_backup_slot( $held_b );
		}

		$this->assertIsArray( $response );
		$this->assertSame( 'queued', $response['status'] ?? null, 'A dropped click with no free slot must report queued, never the stale closed run as failed.' );
	}

	/**
	 * The same dropped-click shape, but with a slot free: the poll's retry
	 * claim opens the run and drives the child instead of leaving the click
	 * stranded on the stale closed run forever.
	 *
	 * @return void
	 */
	public function test_on_demand_poll_retries_claim_for_never_opened_click_when_slot_free() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$site_id = $this->create_test_site( array( 'url' => 'http://127.0.0.1:9' ) );

		$this->manual_task_in_state(
			$site_id,
			array(
				'last_run'      => time() - 3600,
				'completed'     => time() - 3500,
				'backup_errors' => '',
			)
		);

		$this->purge_slot_rows();

		try {
			$since    = time() - 5;
			$response = $this->poll_on_demand( $site_id, $since );
		} finally {
			$this->purge_slot_rows();
		}

		$task = MainWP_DB_Backup::instance()->get_manual_backup_task( $site_id );
		$this->assertGreaterThanOrEqual( $since, (int) $task->last_run, 'A dropped click with a free slot must open the run for this poll, not leave last_run on the stale closed run.' );
		$this->assertIsArray( $response );
		$this->assertNotSame( 'queued', $response['status'] ?? null, 'A free slot must actually drive the retried run rather than reporting queued again.' );
		if ( 'failed' === ( $response['status'] ?? null ) ) {
			$this->assertStringNotContainsString( 'did not produce a snapshot', (string) ( $response['error'] ?? '' ), 'A failed retry must surface the real child error, not the generic no-snapshot message meant for an unopened run.' );
		}
	}

	/**
	 * The poll's retry claim can OPEN a run, so it must honor the same
	 * active-restore refusal as the initiate handler. A stale tab's poll
	 * (or one carrying another site's since after a switch) arriving while
	 * a restore is in flight must refuse instead of starting a backup that
	 * collides with the restore on the child.
	 *
	 * @return void
	 */
	public function test_on_demand_poll_claim_refused_while_restore_active() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$site_id = $this->create_test_site( array( 'url' => 'http://127.0.0.1:9' ) );

		$task_before = $this->manual_task_in_state(
			$site_id,
			array(
				'last_run'      => time() - 3600,
				'completed'     => time() - 3500,
				'backup_errors' => '',
			)
		);

		MainWP_Backup_Restore_Job_Store::instance()->create_job(
			$site_id,
			5,
			array(
				'restore_id' => 'm8claim0001',
				'sub_type'   => 'db',
				'plan'       => array(),
				'options'    => array(
					'pre_snapshot'  => false,
					'file_rollback' => false,
				),
				'transfer'   => array(),
			)
		);

		// A free slot: without the guard, this exact shape opens the run
		// (see the retries-claim test above).
		$this->purge_slot_rows();

		$since    = time() - 5;
		$response = $this->poll_on_demand( $site_id, $since );

		$task_after = MainWP_DB_Backup::instance()->get_manual_backup_task( $site_id );
		$this->assertSame( (int) $task_before->last_run, (int) $task_after->last_run, 'The claim must not open a run while a restore is active for the site.' );
		$this->assertIsArray( $response );
		$this->assertArrayHasKey( 'error', $response, 'The poll must refuse with an error, not report the stale run.' );
		$this->assertStringContainsString( 'restore is in progress', (string) $response['error'] );
	}

	/**
	 * The gp1 field failure: the site's only snapshot is an OLD one, the
	 * current run recorded a real child error (disk full) and closed, and
	 * the poll follows the click that opened that run. The stale snapshot
	 * must not disown the current run's error - keying failure-surface
	 * error ownership to the snapshot is exactly how a child's disk-full
	 * message reached the page as the generic no-snapshot line.
	 *
	 * @return void
	 */
	public function test_on_demand_poll_surfaces_current_run_error_despite_stale_snapshot() {
		global $wpdb;
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$site_id = $this->create_test_site();
		$click   = time() - 30;

		$task = $this->manual_task_in_state(
			$site_id,
			array(
				'last_run'      => $click,
				'completed'     => time() - 10,
				'backup_errors' => 'Site: <strong>child</strong><br />Could not write backup data. The server may be out of disk space.',
			)
		);

		// The site's only snapshot predates this run by days.
		$snapshot_id = MainWP_Backup_Snapshots::instance()->record_snapshot(
			array(
				'task_id' => (int) $task->id,
				'site_id' => $site_id,
				'type'    => 'full',
			)
		);
		$this->assertGreaterThan( 0, $snapshot_id, 'Precondition: the stale snapshot must record.' );
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'created' => $click - 10 * DAY_IN_SECONDS ), array( 'id' => (int) $snapshot_id ) ); // phpcs:ignore WordPress.DB -- test fixture adjust.

		$response = $this->poll_on_demand( $site_id, $click );

		$this->assertIsArray( $response );
		$this->assertSame( 'failed', $response['status'] ?? null, 'A closed run with no fresh snapshot reports failed.' );
		$this->assertStringContainsString( 'Could not write backup data', (string) ( $response['error'] ?? '' ), 'The current run\'s recorded child error must surface; the stale snapshot must not disown it.' );
	}
}
