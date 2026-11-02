<?php
/**
 * MainWP Backup Progress Dialog Error Tests
 *
 * The Backup Now dialog shows a short failure label and the full error once.
 * The poll therefore carries the failing site's error code next to the
 * flattened text, and the text keeps its line and paragraph breaks.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Error_Codes;
use MainWP\Dashboard\MainWP_Backup_Private_Root;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Manage_Backups_Handler;
use MainWP\Dashboard\MainWP_Post_Backup_Handler;

/**
 * Class MainWP_Backup_Progress_Dialog_Error_Test
 */
class MainWP_Backup_Progress_Dialog_Error_Test extends MainWP_Abilities_Test_Case {

	use Backup_Private_Root_Fixture;

	/**
	 * Child actions the driver attempted.
	 *
	 * @var array
	 */
	private $calls = array();

	/**
	 * Native backups on, an administrator, and a test-owned private root.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->set_up_private_root();
		update_option( 'mainwp_enableNativeBackups', 1 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->calls = array();
		add_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'refuse_child' ), 10, 3 );
	}

	/**
	 * Remove committed rows and request state.
	 */
	public function tearDown(): void {
		global $wpdb;
		remove_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'refuse_child' ), 10 );
		foreach ( array( 'wp_backup_run_history', 'wp_backup_snapshot', 'wp_backup_progress', 'wp_backup' ) as $key ) {
			$wpdb->query( 'DELETE FROM ' . MainWP_DB::instance()->get_table_name( $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- fixed test tables.
		}
		delete_option( 'mainwp_enableNativeBackups' );
		unset( $_POST['security'], $_POST['site_id'], $_POST['since'], $_REQUEST['security'] );
		$this->tear_down_private_root();
		parent::tearDown();
	}

	/**
	 * No test here may reach a Child.
	 *
	 * @param mixed  $pre  Transport result.
	 * @param object $site Site row.
	 * @param string $what Child action.
	 * @return mixed
	 * @throws \RuntimeException Unexpected network action.
	 */
	public function refuse_child( $pre, $site, $what ) {
		$this->calls[] = $what;
		throw new \RuntimeException( 'Unexpected Child action: ' . $what ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test-only exception.
	}

	/**
	 * Run the on-demand poll and decode its JSON.
	 *
	 * @param int $site_id Site to poll.
	 * @param int $since   Click baseline, 0 for none.
	 * @return array|null
	 */
	private function poll( int $site_id, int $since ) {
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
		ob_start();
		try {
			MainWP_Post_Backup_Handler::instance()->mainwp_native_backup_progress();
		} catch ( \RuntimeException $e ) {
			unset( $e ); // wp_send_json ended the request.
		} finally {
			$raw = ob_get_clean();
			remove_filter( 'wp_die_ajax_handler', $throw );
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}
		return json_decode( $raw, true );
	}

	/**
	 * The site's hidden on-demand task.
	 *
	 * @param int $site_id Site id.
	 * @return object
	 */
	private function manual_task( int $site_id ): object {
		$task = MainWP_DB_Backup::instance()->ensure_manual_backup_task( MainWP_DB::instance()->get_website_by_id( $site_id ) );
		$this->assertNotEmpty( $task, 'Precondition: the on-demand task must exist.' );
		return $task;
	}

	/**
	 * A closed on-demand run whose stored error and progress row are seeded directly.
	 *
	 * @param int    $site_id    Site id.
	 * @param int    $last_run   Run identity.
	 * @param string $last_error Progress row error column.
	 * @param string $code       Stored error code.
	 * @return object Task row.
	 */
	private function closed_failed_run( int $site_id, int $last_run, string $last_error, string $code ): object {
		$task = $this->manual_task( $site_id );
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			(int) $task->id,
			array(
				'last_run'      => $last_run,
				'completed'     => $last_run + 5,
				'backup_errors' => '<ol><li>Site: <strong>child.example</strong><br />Stored failure text.</li></ol>',
			)
		);
		MainWP_DB_Backup::instance()->add_backup_task_progress( (int) $task->id, $site_id, array() );
		MainWP_DB_Backup::instance()->update_backup_task_progress(
			(int) $task->id,
			$site_id,
			array(
				'last_error'  => $last_error,
				'fetchResult' => wp_json_encode( array( 'error_code' => $code ) ),
			)
		);
		return $task;
	}

	/**
	 * The customer's case end to end: the real driver refuses an unusable
	 * private root, and the poll names the code with the readable text.
	 */
	public function test_private_root_failure_poll_carries_code_and_line_breaks(): void {
		$site_id = $this->create_test_site( array( 'userid' => get_current_user_id() ) );
		$task    = $this->manual_task( $site_id );
		$this->assertTrue( touch( $this->private_root ), 'Precondition: a file where the root directory belongs.' );
		MainWP_Backup_Private_Root::reset();

		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, true );
		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );

		$response = $this->poll( $site_id, (int) $task->last_run );

		$this->assertSame( array(), $this->calls, 'The storage refusal comes before any Child request.' );
		$this->assertSame( 'failed', $response['status'] ?? null );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_PRIVATE_ROOT_UNAVAILABLE, $response['error_code'] ?? null );
		$this->assertStringContainsString( 'could not create directory', $response['error'] );
		$this->assertMatchesRegularExpression( '/^Site: [^\n<>]+\nPrivate backup directory /', $response['error'], 'The site stays named on its own line, ahead of the Dashboard-side message.' );
		$this->assertStringNotContainsString( '<', $response['error'], 'The dialog receives plain text, never markup.' );
	}

	/**
	 * The code belongs to the failure the progress row describes. Run-state
	 * JSON there means the row was not written by this failure.
	 */
	public function test_poll_omits_code_when_progress_row_holds_run_state(): void {
		$site_id = $this->create_test_site();
		$this->closed_failed_run( $site_id, time() - 30, (string) wp_json_encode( array( 'reconcile_pending' => array() ) ), MainWP_Backup_Error_Codes::ERR_PRIVATE_ROOT_UNAVAILABLE );

		$response = $this->poll( $site_id, time() - 30 );

		$this->assertSame( 'failed', $response['status'] ?? null );
		$this->assertArrayNotHasKey( 'error_code', $response );
	}

	/**
	 * A click whose run never opened sees the read model's generic line, so
	 * an older run's code must not ride along with it. The poll claims such
	 * a run before reading, so the guard is exercised on the helper itself.
	 */
	public function test_failure_code_ignores_errors_of_an_earlier_run(): void {
		$site_id = $this->create_test_site();
		$this->closed_failed_run( $site_id, time() - 60, 'Stored failure text.', MainWP_Backup_Error_Codes::ERR_PRIVATE_ROOT_UNAVAILABLE );
		$code = new \ReflectionMethod( MainWP_Post_Backup_Handler::class, 'on_demand_failure_code' );
		$code->setAccessible( true );

		$this->assertSame( '', $code->invoke( null, $site_id, time() - 10 ), 'A newer click does not own the stored failure.' );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_PRIVATE_ROOT_UNAVAILABLE, $code->invoke( null, $site_id, time() - 60 ), 'The click that opened the run does.' );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_PRIVATE_ROOT_UNAVAILABLE, $code->invoke( null, $site_id, 0 ), 'Older pages send no since and keep the run-level answer.' );
	}

	/**
	 * Only registered codes reach the page; anything else gets the generic label.
	 */
	public function test_poll_passes_registered_codes_only(): void {
		$site_id = $this->create_test_site();
		$this->closed_failed_run( $site_id, time() - 30, 'Stored failure text.', 'not_a_registered_code<b>' );
		$response = $this->poll( $site_id, time() - 30 );
		$this->assertArrayNotHasKey( 'error_code', $response );

		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_progress' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- fixed test table.
		$this->closed_failed_run( $site_id, time() - 30, 'Stored failure text.', MainWP_Backup_Error_Codes::ERR_TIMEOUT );
		$response = $this->poll( $site_id, time() - 30 );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_TIMEOUT, $response['error_code'] ?? null );
	}

	/**
	 * Stored HTML becomes plain text with its breaks: br is a line break,
	 * each list item a paragraph, and blank lines inside a message survive.
	 */
	public function test_humanize_keeps_line_and_paragraph_breaks(): void {
		$stored = "<ol><li>Site: <strong>one.example</strong><br />First paragraph &quot;quoted&quot;.\r\n\r\n\r\n   Second  paragraph.<script>alert(1)</script></li><li>Site: <strong>two.example</strong><br/>Other &amp; failure.</li></ol>";

		$this->assertSame(
			"Site: one.example\nFirst paragraph \"quoted\".\n\nSecond paragraph.\n\nSite: two.example\nOther & failure.",
			MainWP_Post_Backup_Handler::humanize_backup_errors( $stored )
		);
		$this->assertSame( '', MainWP_Post_Backup_Handler::humanize_backup_errors( '<ol></ol>' ), 'An empty list still reads as no error.' );
		$this->assertSame( 'Plain text.', MainWP_Post_Backup_Handler::humanize_backup_errors( '  Plain   text.  ' ) );
	}
}
