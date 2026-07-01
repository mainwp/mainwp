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
		delete_option( 'mainwp_enableNativeBackups' );
		delete_option( 'mainwp_notificationOnBackupFail' );
		unset( $_POST['security'], $_POST['site_id'], $_REQUEST['security'], $_REQUEST['site_id'] );
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
	 *
	 * @return array|null Decoded response payload.
	 */
	private function poll_on_demand( $site_id ) {
		$nonce                = wp_create_nonce( 'mainwp_native_backup_progress' );
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
		$this->assertSame( '', (string) MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id )->backup_errors, 'The emailed errors are cleared, matching the previous contract.' );
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
}
