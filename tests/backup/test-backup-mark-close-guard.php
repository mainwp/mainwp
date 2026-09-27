<?php
/**
 * MainWP Backup Mark/Close Guard Tests
 *
 * Tests for the site-loop mark discipline and the run-close guard: an
 * exception against a site whose progress row is still resumable for the
 * open run yields instead of marking the site complete, yields consume
 * the shared retry budget so a repeating throw can never wedge the run,
 * and a downloaded-but-unrecorded artifact holds the close (time-bounded)
 * and gets re-driven so its snapshot lands. The drill this hardens: a
 * racing driver's unidentified exception marked a site complete and
 * closed the run while the snapshot record was in flight - the snapshot
 * was lost although the validated artifact sat on disk.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Error_Codes;
use MainWP\Dashboard\MainWP_Backup_File_Manager;
use MainWP\Dashboard\MainWP_Backup_Monitor;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Exception;
use MainWP\Dashboard\MainWP_Manage_Backups_Handler;

/**
 * Class MainWP_Backup_Mark_Close_Guard_Test
 */
class MainWP_Backup_Mark_Close_Guard_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Local artifact files created during a test.
	 *
	 * @var string[]
	 */
	private $local_files = array();

	/**
	 * Captured mainwp_backup_diag_site_mark events.
	 *
	 * @var array[]
	 */
	private $mark_events = array();

	/**
	 * Set up: enable native backups and capture site-mark events.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		update_option( 'mainwp_enableNativeBackups', 1 );

		$this->mark_events = array();
		add_action(
			'mainwp_backup_diag_site_mark',
			function ( $task_id, $site_id, $outcome, $detail ) {
				$this->mark_events[] = array(
					'task_id' => $task_id,
					'site_id' => $site_id,
					'outcome' => $outcome,
					'detail'  => $detail,
				);
			},
			10,
			4
		);
	}

	/**
	 * Clean up task, progress, and snapshot rows plus artifact files.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_wp_backup_progress" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_wp_backup" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_wp_backup_snapshot" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup.
		delete_option( 'mainwp_enableNativeBackups' );

		foreach ( $this->local_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		$this->local_files = array();
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
		$task    = MainWP_DB_Backup::instance()->add_backup_task( $user, 'Mark guard test task', 'daily', 'full', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'tar.gz', 0, 1, 150, 0, 0 );
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
	 * Write a small valid-looking artifact into the uploads dir.
	 *
	 * @param string $name File name.
	 *
	 * @return string Absolute path.
	 */
	private function make_artifact( $name ) {
		$dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'mainwp-test-mark-guard/';
		wp_mkdir_p( $dir );
		$local = $dir . $name;
		file_put_contents( $local, 'PK' . "\x03\x04" . str_repeat( 'x', 4092 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		$this->local_files[] = $local;
		return $local;
	}

	/**
	 * A mid-download progress row: fetchResult claimed, no download
	 * complete. The stored projection is empty for the task type, so the
	 * executor throws OUTSIDE its budgeted download catch - the same
	 * shape as the drill's unidentified non-yield exception.
	 *
	 * @param int $task_id  Task ID.
	 * @param int $site_id  Site ID.
	 * @param int $attempts Seeded attempts value.
	 *
	 * @return void
	 */
	private function seed_mid_download_throwing_row( $task_id, $site_id, $attempts ) {
		$this->seed_progress(
			$task_id,
			$site_id,
			array(
				'attempts'    => $attempts,
				'fetchResult' => wp_json_encode(
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
	 * A record-pending progress row: download complete, artifact on disk,
	 * no snapshot recorded, and a stored projection whose error field
	 * throws out of the executor before the record can run.
	 *
	 * @param int    $task_id Task ID.
	 * @param int    $site_id Site ID.
	 * @param string $local   Artifact path on disk.
	 *
	 * @return void
	 */
	private function seed_record_pending_throwing_row( $task_id, $site_id, $local ) {
		$this->seed_progress(
			$task_id,
			$site_id,
			array(
				'attempts'               => 1,
				'removedFiles'           => 1,
				'downloadedFULL'         => $local,
				'downloadedFULLComplete' => 1,
				'fetchResult'            => wp_json_encode( array( 'error' => 'stored terminal projection error' ) ),
			)
		);
	}

	/**
	 * Give a test site a real RSA private key so signed child requests work.
	 *
	 * @param int $site_id Site ID.
	 *
	 * @return void
	 */
	private function give_site_real_privkey( $site_id ) {
		global $wpdb;
		$key = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		openssl_pkey_export( $key, $privkey_pem );
		$wpdb->update( $wpdb->prefix . 'mainwp_wp', array( 'privkey' => base64_encode( $privkey_pem ) ), array( 'id' => $site_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.PHP.DiscouragedPHPFunctions -- test fixture.
	}

	/**
	 * Mock a child response for a specific action.
	 *
	 * @param string $action   Action name.
	 * @param mixed  $response Response array, or a MainWP_Exception to throw.
	 *
	 * @return void
	 */
	private function mock_child_action( $action, $response ) {
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what ) use ( $action, $response ) {
				unset( $website );
				if ( $what === $action ) {
					if ( $response instanceof MainWP_Exception ) {
						throw $response; // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test mock.
					}
					return $response;
				}
				return $result;
			},
			10,
			3
		);
	}

	/**
	 * A record-pending row whose stored projection is VALID - the re-drive
	 * can finish the record - seeded for a site already wrongly marked
	 * complete, the exact end state of the drill's attempt 3.
	 *
	 * @param int    $task_id Task ID.
	 * @param int    $site_id Site ID.
	 * @param string $local   Artifact path on disk.
	 *
	 * @return void
	 */
	private function seed_recordable_pending_row( $task_id, $site_id, $local ) {
		$this->give_site_real_privkey( $site_id );
		$this->mock_child_action( 'delete_backup', array( 'result' => 'ok' ) );
		$this->seed_progress(
			$task_id,
			$site_id,
			array(
				'removedFiles'           => 1,
				'downloadedFULL'         => $local,
				'downloadedFULLComplete' => 1,
				'fetchResult'            => wp_json_encode(
					array(
						'full' => basename( $local ),
						'db'   => false,
						'size' => filesize( $local ),
					)
				),
			)
		);
	}

	/**
	 * Filter captured site-mark events by outcome.
	 *
	 * @param string $outcome Outcome to match.
	 *
	 * @return array[] Matching events.
	 */
	private function marks( $outcome ) {
		return array_values(
			array_filter(
				$this->mark_events,
				function ( $event ) use ( $outcome ) {
					return $event['outcome'] === $outcome;
				}
			)
		);
	}

	/**
	 * An exception against a site whose row is still mid-download for the
	 * open run yields instead of marking the site complete, and the yield
	 * consumes one retry attempt so a repeating throw stays bounded.
	 *
	 * @return void
	 */
	public function test_generic_throw_against_mid_download_row_yields_and_consumes_attempt() {
		list( $site_id, $task ) = $this->site_and_open_task();
		$this->seed_mid_download_throwing_row( (int) $task->id, $site_id, 2 );

		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, false );

		$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertStringNotContainsString( (string) $site_id, (string) $fresh->completed_sites, 'A resumable site must not be marked complete by a terminal-looking exception.' );
		$this->assertLessThan( (int) $fresh->last_run, (int) $fresh->completed, 'The run must stay open while the site is resumable.' );
		$this->assertSame( '', (string) $fresh->backup_errors, 'A yield must not record a site failure.' );

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( 3, (int) $progress->attempts, 'The yield must consume exactly one retry attempt.' );

		$yields = $this->marks( 'yield_resumable' );
		$this->assertCount( 1, $yields );
		$this->assertSame( $site_id, $yields[0]['site_id'] );
		$this->assertStringContainsString( 'MainWP_Exception', $yields[0]['detail'], 'The yield event must name the exception that would have marked the site.' );
	}

	/**
	 * An exhausted retry budget is a real terminal failure: the same
	 * exception with attempts at the cap marks the site and the run
	 * closes with the honest error recorded.
	 *
	 * @return void
	 */
	public function test_generic_throw_with_exhausted_attempts_marks_site() {
		list( $site_id, $task ) = $this->site_and_open_task();
		$this->seed_mid_download_throwing_row( (int) $task->id, $site_id, 5 );

		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, false );

		$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertStringContainsString( (string) $site_id, (string) $fresh->completed_sites, 'An exhausted budget must mark the site so the run can close.' );
		$this->assertGreaterThan( (int) $fresh->last_run, (int) $fresh->completed, 'The run must close once its only site is terminally failed.' );

		$errors = $this->marks( 'error' );
		$this->assertCount( 1, $errors );
		$this->assertSame( $site_id, $errors[0]['site_id'] );
	}

	/**
	 * The saved-task failure must retain the reason the operator needs to fix.
	 */
	public function test_preflight_failure_last_error_retains_child_message() {
		list( $site_id, $task ) = $this->site_and_open_task();
		$this->mock_child_action(
			'backup_preflight',
			array(
				'error'      => 'The uploads directory is not writable.',
				'error_code' => '',
			)
		);

		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, false );

		$errors = $this->marks( 'error' );
		$this->assertCount( 1, $errors );
		$this->assertStringContainsString( MainWP_Backup_Error_Codes::ERR_CHILD_PREFLIGHT_FAILED, $errors[0]['detail'] );
		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( 'The child site could not get ready for a backup: The uploads directory is not writable.', $progress->last_error );
	}

	/**
	 * An exhausted download failure keeps its HTTP status in the site mark.
	 */
	public function test_download_failure_site_mark_retains_http_status() {
		list( $site_id, $task ) = $this->site_and_open_task();
		$this->give_site_real_privkey( $site_id );
		$this->seed_progress(
			(int) $task->id,
			$site_id,
			array(
				'attempts'    => 5,
				'fetchResult' => wp_json_encode( array( 'full' => 'backup-file.zip', 'db' => false, 'size' => 4096 ) ),
			)
		);
		$throw = static function () {
			throw new MainWP_Exception( 'Backup download failed: the child site returned an unexpected response.', 'HTTP 504', 'mainwp_backup_http_error' );
		};
		add_filter( 'mainwp_download_to_file_pre', $throw );
		try {
			MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, false );
			$errors = $this->marks( 'error' );
			$this->assertCount( 1, $errors );
			$this->assertSame( $site_id, $errors[0]['site_id'] );
			$this->assertStringContainsString( '(HTTP 504)', $errors[0]['detail'] );
			$this->assertSame( MainWP_Exception::class . ' [mainwp_backup_http_error]: Backup download failed: the child site returned an unexpected response. (HTTP 504)', $errors[0]['detail'] );
		} finally {
			remove_filter( 'mainwp_download_to_file_pre', $throw );
		}
	}

	/**
	 * A throw that repeats identically on every pass - a stored
	 * projection that re-throws from the row itself - cannot yield
	 * forever: each yield consumes budget and the site marks within the
	 * cap, so the run always closes. This is the eternal-yield regression
	 * guard on the resumable-yield design itself.
	 *
	 * @return void
	 */
	public function test_repeating_throw_cannot_yield_forever() {
		list( $site_id, $task ) = $this->site_and_open_task();
		$this->seed_mid_download_throwing_row( (int) $task->id, $site_id, 0 );

		$marked_after = null;
		for ( $pass = 1; $pass <= 7; $pass++ ) {
			$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
			MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, false );
			$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
			if ( false !== strpos( (string) $fresh->completed_sites, (string) $site_id ) ) {
				$marked_after = $pass;
				break;
			}
		}

		$this->assertNotNull( $marked_after, 'A repeating throw must exhaust the budget and mark the site, never yield forever.' );
		$this->assertLessThanOrEqual( 6, $marked_after, 'The budget must bound the yields to at most the retry cap.' );

		$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertGreaterThan( (int) $fresh->last_run, (int) $fresh->completed, 'The run must close after the honest terminal mark.' );
	}

	/**
	 * An exception against a site whose validated artifact is on disk but
	 * whose snapshot has not landed yields without consuming the download
	 * retry budget: the record-pending window is bounded by its own TTL,
	 * and burning attempts here would terminally fail a healthy site.
	 *
	 * @return void
	 */
	public function test_generic_throw_against_record_pending_row_yields_without_consuming_attempt() {
		list( $site_id, $task ) = $this->site_and_open_task();
		$local                  = $this->make_artifact( 'full-record-pending.tar.gz' );
		$this->seed_record_pending_throwing_row( (int) $task->id, $site_id, $local );

		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, false );

		$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertStringNotContainsString( (string) $site_id, (string) $fresh->completed_sites, 'A record-pending site must not be marked complete by an exception.' );
		$this->assertLessThan( (int) $fresh->last_run, (int) $fresh->completed, 'The run must stay open while the record is pending.' );

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( 1, (int) $progress->attempts, 'A record-pending yield must not consume the download retry budget.' );

		$this->assertCount( 1, $this->marks( 'yield_resumable' ) );
	}

	/**
	 * The record-pending window is time-bounded: once the artifact has
	 * sat unrecorded past the TTL, the same exception marks the site so a
	 * permanently failing record can never wedge the run open.
	 *
	 * @return void
	 */
	public function test_generic_throw_against_expired_record_pending_row_marks_site() {
		list( $site_id, $task ) = $this->site_and_open_task();
		$local                  = $this->make_artifact( 'full-record-expired.tar.gz' );
		$this->seed_record_pending_throwing_row( (int) $task->id, $site_id, $local );
		touch( $local, time() - MainWP_DB_Backup::RECORD_PENDING_TTL_SECONDS - 300 );

		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, false );

		$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertStringContainsString( (string) $site_id, (string) $fresh->completed_sites, 'An expired record-pending window must mark the site with the honest failure.' );
		$this->assertGreaterThan( (int) $fresh->last_run, (int) $fresh->completed, 'The run must close after the expired window marks its only site.' );

		$this->assertCount( 1, $this->marks( 'error' ) );
	}

	/**
	 * The close guard alone protects the snapshot: a site wrongly marked
	 * complete while its record is pending refuses the run close even
	 * when the record itself cannot finish this pass (a concurrent holder
	 * owns the artifact lock). The guard scans the progress table, not
	 * completed_sites, so the bogus mark cannot blind it.
	 *
	 * @return void
	 */
	public function test_close_refused_while_record_pending_even_when_site_already_marked() {
		list( $site_id, $task ) = $this->site_and_open_task();
		$local                  = $this->make_artifact( 'full-close-guard.tar.gz' );
		$this->seed_recordable_pending_row( (int) $task->id, $site_id, $local );
		MainWP_DB_Backup::instance()->update_completed_sites( $task->id, array( $site_id => true ) );
		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );

		$refusals = array();
		add_action(
			'mainwp_backup_diag_run_reinit',
			function ( $task_id, $reason ) use ( &$refusals ) {
				unset( $task_id );
				$refusals[] = $reason;
			},
			10,
			2
		);

		MainWP_Backup_File_Manager::release_download_lock( $local );
		$this->assertTrue( MainWP_Backup_File_Manager::acquire_download_lock( $local, 999, 999 ), 'Precondition: the test must own the artifact lock so the record cannot finish.' );
		try {
			MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, false );
		} finally {
			MainWP_Backup_File_Manager::release_download_lock( $local );
		}

		$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertLessThan( (int) $fresh->last_run, (int) $fresh->completed, 'The close must be refused while a validated artifact has no snapshot.' );
		$this->assertContains( 'close_refused_pending_record', $refusals, 'The refusal must leave its named trace.' );
		$this->assertNull(
			MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, (int) $task->id ),
			'Precondition: no snapshot may exist while the lock holder owns the artifact.'
		);
	}

	/**
	 * A wrongly-marked site whose record is pending is re-driven instead
	 * of skipped: the re-entry lands the snapshot from the validated
	 * artifact, and the close then proceeds with the snapshot credited -
	 * the exact save the drill's attempt 3 needed.
	 *
	 * @return void
	 */
	public function test_marked_site_with_pending_record_is_redriven_and_close_proceeds() {
		list( $site_id, $task ) = $this->site_and_open_task();
		$local                  = $this->make_artifact( 'full-redrive.tar.gz' );
		$this->seed_recordable_pending_row( (int) $task->id, $site_id, $local );
		MainWP_DB_Backup::instance()->update_completed_sites( $task->id, array( $site_id => true ) );
		MainWP_Backup_File_Manager::release_download_lock( $local );
		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );

		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, false );

		$snapshot = MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, (int) $task->id );
		$this->assertNotNull( $snapshot, 'The re-drive must land the pending snapshot.' );
		$this->assertGreaterThanOrEqual( (int) $task->last_run, (int) $snapshot->created, 'The snapshot must be credited to the current run.' );

		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}mainwp_wp_backup_snapshot WHERE site_id = %d AND task_id = %d", $site_id, (int) $task->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test assertion.
		$this->assertSame( 1, $count, 'Exactly one snapshot may land for the run.' );

		$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertGreaterThan( (int) $fresh->last_run, (int) $fresh->completed, 'The close must proceed once the snapshot has landed.' );
	}

	/**
	 * A pending record past its TTL neither re-drives nor holds the
	 * close: the run closes without the snapshot rather than wedging on a
	 * record that will never finish.
	 *
	 * @return void
	 */
	public function test_close_proceeds_after_pending_record_ttl_expires() {
		list( $site_id, $task ) = $this->site_and_open_task();
		$local                  = $this->make_artifact( 'full-ttl-close.tar.gz' );
		$this->seed_recordable_pending_row( (int) $task->id, $site_id, $local );
		MainWP_DB_Backup::instance()->update_completed_sites( $task->id, array( $site_id => true ) );
		touch( $local, time() - MainWP_DB_Backup::RECORD_PENDING_TTL_SECONDS - 300 );
		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );

		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, false );

		$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertGreaterThan( (int) $fresh->last_run, (int) $fresh->completed, 'An expired pending record must not wedge the close.' );
	}

	/**
	 * The drill's attempt-3 shape end to end: a racing driver's exception
	 * hits while the artifact is downloaded but unrecorded. The exception
	 * yields instead of marking, the run stays open, the next pass lands
	 * the record, and the close credits exactly one snapshot. On the old
	 * build this exact sequence marked the site, closed the run
	 * mid-record, and lost the snapshot.
	 *
	 * @return void
	 */
	public function test_attempt3_shape_exception_yields_then_record_lands_then_close() {
		list( $site_id, $task ) = $this->site_and_open_task();
		$local                  = $this->make_artifact( 'full-attempt3.tar.gz' );
		$this->seed_recordable_pending_row( (int) $task->id, $site_id, $local );
		MainWP_Backup_File_Manager::release_download_lock( $local );

		// Pass 1: a concurrent holder owns the artifact lock (the racing
		// driver mid-download-phase), so this driver's record attempt
		// throws. The site must yield, not mark.
		$this->assertTrue( MainWP_Backup_File_Manager::acquire_download_lock( $local, 999, 999 ) );
		try {
			MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, false );
		} finally {
			MainWP_Backup_File_Manager::release_download_lock( $local );
		}

		$mid = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertLessThan( (int) $mid->last_run, (int) $mid->completed, 'The run must stay open while the record is pending.' );
		$this->assertNull( MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, (int) $task->id ), 'Precondition: no snapshot yet after the contended pass.' );

		// Pass 2: the lock is free; the retry lands the record and the
		// run closes with the snapshot credited.
		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, false );

		$snapshot = MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, (int) $task->id );
		$this->assertNotNull( $snapshot, 'The retry pass must land the snapshot.' );

		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}mainwp_wp_backup_snapshot WHERE site_id = %d AND task_id = %d", $site_id, (int) $task->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test assertion.
		$this->assertSame( 1, $count, 'Exactly one snapshot may land for the run.' );

		$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertGreaterThan( (int) $fresh->last_run, (int) $fresh->completed, 'The run must close after the snapshot lands.' );
	}

	/**
	 * update_backup_completed() closes an open run exactly once: the first
	 * call against an open run wins and stamps completed forward, and an
	 * immediate second call against the now-closed run reports it did not
	 * win. This is the guard the close block leans on so a repeat close
	 * cannot re-fire the failure email while backup_errors still holds the
	 * run's real error for the on-demand poll.
	 *
	 * @return void
	 */
	public function test_update_backup_completed_reports_close_winner() {
		list( , $task ) = $this->site_and_open_task(
			array(
				'last_run'  => time() - 30,
				'completed' => time() - 60,
			)
		);

		$first = MainWP_DB_Backup::instance()->update_backup_completed( $task->id );
		$this->assertTrue( $first, 'The first call against an open run must win the close.' );

		$closed = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertGreaterThanOrEqual( (int) $closed->last_run, (int) $closed->completed, 'A won close must stamp completed at or past last_run.' );

		$second = MainWP_DB_Backup::instance()->update_backup_completed( $task->id );
		$this->assertFalse( $second, 'A second call against an already-closed run must not report a win.' );
	}

	/**
	 * A run timestamp ahead of the local clock still closes exactly once.
	 *
	 * @return void
	 */
	public function test_close_never_precedes_last_run() {
		list( , $task ) = $this->site_and_open_task(
			array(
				'last_run'  => time() + 60,
				'completed' => time() - 60,
			)
		);

		$this->assertTrue( MainWP_DB_Backup::instance()->update_backup_completed( $task->id ) );
		$closed = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertSame( (int) $closed->last_run, (int) $closed->completed, 'The close must reach last_run even when the local clock is behind.' );
		$this->assertFalse( MainWP_DB_Backup::instance()->update_backup_completed( $task->id ), 'The same run cannot close twice.' );
	}

	/**
	 * The close path emails exactly once for a run with a real recorded
	 * error even when a second driver reaches the close block after the
	 * first has already closed it, and the errors stay on the row both
	 * times: the winner-gated email must not depend on clearing
	 * backup_errors, because the on-demand poll needs it to still be there.
	 *
	 * @return void
	 */
	public function test_close_path_emails_once_and_keeps_errors() {
		list( $site_id, $task ) = $this->site_and_open_task(
			array(
				'last_run'  => time() - 30,
				'completed' => time() - 60,
			)
		);
		MainWP_DB_Backup::instance()->update_completed_sites( $task->id, array( $site_id => true ) );
		MainWP_DB_Backup::instance()->update_backup_errors( (int) $task->id, 'Site: child. The database dump failed.' );
		update_option( 'mainwp_notificationOnBackupFail', '1' );
		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );

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

		$this->purge_slot_rows();
		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, false );

		$this->assertCount( 1, $sent, 'The site already marked complete reaches the close block with no child contact, and a run with real errors must email once.' );
		$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertNotSame( '', (string) $fresh->backup_errors, 'The close must not clear backup_errors; the on-demand poll still needs to surface them.' );
		$this->assertGreaterThanOrEqual( (int) $fresh->last_run, (int) $fresh->completed, 'The winning close must stamp completed at or past last_run.' );

		$task_reread = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->purge_slot_rows();
		MainWP_Manage_Backups_Handler::execute_backup_task( $task_reread, 1, false );

		$this->assertCount( 1, $sent, 'A second close attempt against an already-closed run must not win, and so must not re-email even though the errors persist.' );
	}
}
