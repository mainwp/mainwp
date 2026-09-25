<?php
/**
 * MainWP Backup Executor Provider Contract Tests
 *
 * Covers the consumer-facing contract of the backup executor: starting
 * an ad-hoc resumable backup task, reading and driving task status,
 * the completion hook, and the backup freshness check.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

require_once __DIR__ . '/trait-backup-private-root-fixture.php';

use MainWP\Dashboard\MainWP_Backup_Executor;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Exception;
use MainWP\Dashboard\MainWP_System_Utility;

/**
 * Class MainWP_Backup_Executor_Contract_Test
 */
class MainWP_Backup_Executor_Contract_Test extends MainWP_Abilities_Test_Case {

	use Backup_Private_Root_Fixture;

	/**
	 * Set up: enable the legacy backup feature.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->set_up_private_root();
		update_option( 'mainwp_enableNativeBackups', 1 );
	}

	/**
	 * Clean up.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wpdb;
		// The shared teardown drops the site rows but not their options, and
		// wp_options has no cascade, so handle rows would outlive their site.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . MainWP_DB::instance()->get_table_name( 'wp_options' ) . ' WHERE name = %s', MainWP_Backup_Executor::ADHOC_TASK_OPTION ) ); // phpcs:ignore WordPress.DB -- test cleanup.
		delete_option( 'mainwp_enableNativeBackups' );
		$this->tear_down_private_root();
		parent::tearDown();
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
	 * Record which child actions get called, in order.
	 *
	 * @param array $called Reference to the recorder array.
	 *
	 * @return void
	 */
	private function record_child_actions( &$called ) {
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what ) use ( &$called ) {
				unset( $website );
				$called[] = $what;
				return $result;
			},
			5,
			3
		);
	}

	/**
	 * Give a test site a real RSA private key so download URL signing works.
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
		// The connect layer base64-decodes the stored key before signing.
		$wpdb->update( $wpdb->prefix . 'mainwp_wp', array( 'privkey' => base64_encode( $privkey_pem ) ), array( 'id' => $site_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.PHP.DiscouragedPHPFunctions -- test fixture.
	}

	/**
	 * Short-circuit downloads by writing a valid gzip header to the target.
	 *
	 * @return void
	 */
	private function stub_download_with_valid_gzip() {
		add_filter(
			'mainwp_download_to_file_pre',
			function ( $pre, $url, $file ) {
				unset( $pre, $url );
				file_put_contents( $file, "\x1f\x8b" . str_repeat( 'x', 64 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
				return true;
			},
			10,
			3
		);
	}

	/**
	 * Collect mainwp_native_backup_completed hook firings.
	 *
	 * @param array $events Reference to the recorder array.
	 *
	 * @return void
	 */
	private function record_completion_hook( &$events ) {
		add_action(
			'mainwp_native_backup_completed',
			function ( $site_id, $task_id, $success, $meta ) use ( &$events ) {
				$events[] = array(
					'site_id' => $site_id,
					'task_id' => $task_id,
					'success' => $success,
					'meta'    => $meta,
				);
			},
			10,
			4
		);
	}

	/**
	 * Triggering a backup on a task-machine child creates a task and
	 * persists the handle for later status calls.
	 *
	 * @return void
	 */
	public function test_trigger_backup_creates_task_and_persists_id() {
		$site_id = $this->create_test_site();
		$this->mock_child_action( 'backup_preflight', array( 'task_spine' => 1 ) );
		$this->mock_child_action(
			'backup_task_create',
			array(
				'task_id' => 'task-1749600000-adhoc1',
				'phase'   => 'queued',
			)
		);

		$task_id = MainWP_Backup_Executor::trigger_backup( $site_id, 'full', 'job-9' );

		$this->assertSame( 'task-1749600000-adhoc1', $task_id );

		$website   = MainWP_DB::instance()->get_website_by_id( $site_id );
		$persisted = json_decode( (string) MainWP_DB::instance()->get_website_option( $website, 'adhoc_backup_task' ), true );
		$this->assertIsArray( $persisted );
		$this->assertSame( 'task-1749600000-adhoc1', $persisted['task_id'] );
		$this->assertSame( 'full', $persisted['type'] );
		$this->assertSame( 'job-9', $persisted['owner'] );
	}

	/**
	 * Triggering again while an ad-hoc task is persisted reuses the handle
	 * instead of colliding with the active task on the child.
	 *
	 * @return void
	 */
	public function test_trigger_backup_reuses_persisted_active_task() {
		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		MainWP_DB::instance()->update_website_option(
			$website,
			'adhoc_backup_task',
			wp_json_encode(
				array(
					'task_id' => 'task-live',
					'type'    => 'full',
					'started' => time() - 60,
				)
			)
		);
		$this->mock_child_action( 'backup_preflight', array( 'task_spine' => 1 ) );

		$called = array();
		$this->record_child_actions( $called );

		$task_id = MainWP_Backup_Executor::trigger_backup( $site_id, 'full' );

		$this->assertSame( 'task-live', $task_id );
		$this->assertNotContains( 'backup_task_create', $called, 'An active ad-hoc task must be reused, not recreated.' );
	}

	/**
	 * Ownership prevents one lane from adopting another lane's active task.
	 *
	 * @dataProvider trigger_backup_adoption_matrix
	 *
	 * @param array  $handle Persisted handle fixture.
	 * @param string $owner Requesting lane's identity.
	 * @param string $expect Expected result: resume, refuse, or create.
	 * @param int    $age    Handle age in seconds at the start of the call.
	 *
	 * @return void
	 */
	public function test_trigger_backup_adoption_matrix( $handle, $owner, $expect, $age = 60 ) {
		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$this->mock_child_action( 'backup_preflight', array( 'task_spine' => 1 ) );
		$this->mock_child_action(
			'backup_task_create',
			array(
				'task_id' => 'task-new',
				'phase'   => 'queued',
			)
		);

		$called = array();
		$this->record_child_actions( $called );

		// Providers run before the tests. Date the handle here, and retry a
		// boundary measurement only if the call crossed a wall-clock second.
		for ( $attempt = 0; $attempt < 3; ++$attempt ) {
			$now               = time();
			$handle['started'] = $now - $age;
			$before            = wp_json_encode( $handle );
			MainWP_DB::instance()->update_website_option( $website, MainWP_Backup_Executor::ADHOC_TASK_OPTION, $before );
			$called  = array();
			$error   = null;
			$task_id = null;
			try {
				$task_id = MainWP_Backup_Executor::trigger_backup( $site_id, 'full', $owner );
			} catch ( MainWP_Exception $e ) {
				$error = $e;
			}
			$finished = time();
			if ( MainWP_Backup_Executor::ADHOC_TASK_ABANDON_SECONDS !== $age || $now === $finished ) {
				break;
			}
		}
		if ( MainWP_Backup_Executor::ADHOC_TASK_ABANDON_SECONDS === $age ) {
			$this->assertSame( $now, $finished, 'The boundary check must complete within the fixture second.' );
		}
		if ( 'refuse' === $expect ) {
			$this->assertInstanceOf( MainWP_Exception::class, $error, 'Expected an already-running exception.' );
			$this->assertSame( \MainWP\Dashboard\MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING, $error->get_message_error_code() );
		} else {
			$this->assertNull( $error );
			$this->assertSame( 'create' === $expect ? 'task-new' : 'task-live', $task_id );
		}

		$after = MainWP_DB::instance()->get_website_option( $website, MainWP_Backup_Executor::ADHOC_TASK_OPTION );
		if ( 'create' === $expect ) {
			$this->assertCount( 1, array_keys( $called, 'backup_task_create', true ) );
			$persisted = json_decode( $after, true );
			$this->assertSame( 'task-new', $persisted['task_id'] );
			$this->assertSame( $owner, $persisted['owner'] );
		} else {
			$this->assertNotContains( 'backup_task_create', $called );
			$this->assertSame( $before, $after );
		}
	}

	/**
	 * The auto-update lane retains its identity on handles predating the owner field.
	 *
	 * @return array
	 */
	public static function trigger_backup_adoption_matrix() {
		$young = array(
			'task_id' => 'task-live',
			'type'    => 'full',
		);

		return array(
			'own handle resumes'                      => array( $young + array( 'owner' => 'job-9' ), 'job-9', 'resume' ),
			'legacy handle resumes for the auto-update lane' => array( $young, '', 'resume' ),
			'auto-update handle refused to a restore' => array( $young + array( 'owner' => '' ), 'job-9', 'refuse' ),
			'restore handle refused to the auto-update lane' => array( $young + array( 'owner' => 'job-9' ), '', 'refuse' ),
			'other restore refused'                   => array( $young + array( 'owner' => 'job-8' ), 'job-9', 'refuse' ),
			'foreign handle at the bound refused'     => array( $young + array( 'owner' => 'job-8' ), 'job-9', 'refuse', MainWP_Backup_Executor::ADHOC_TASK_ABANDON_SECONDS ),
			'abandoned foreign handle replaced'       => array( $young + array( 'owner' => 'job-8' ), 'job-9', 'create', MainWP_Backup_Executor::ADHOC_TASK_ABANDON_SECONDS + 1 ),
			'same owner past the bound still resumes' => array( $young + array( 'owner' => 'job-9' ), 'job-9', 'resume', MainWP_Backup_Executor::ADHOC_TASK_ABANDON_SECONDS + 1 ),
		);
	}

	/**
	 * A child without the task machine is refused with a coded update error
	 * instead of running the synchronous legacy backup.
	 *
	 * @return void
	 */
	public function test_trigger_backup_without_spine_throws_child_update_required() {
		$site_id = $this->create_test_site();
		$this->mock_child_action( 'backup_preflight', array( 'zip_support' => true ) );
		$this->mock_child_action(
			'backup',
			array(
				'error'      => 'Could not write backup data.',
				'error_code' => 'mainwp_backup_disk_full',
			)
		);

		$called = array();
		$this->record_child_actions( $called );

		try {
			MainWP_Backup_Executor::trigger_backup( $site_id, 'full' );
			$this->fail( 'Expected a child-update-required exception.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_child_update_required', $e->get_message_error_code() );
		}

		$this->assertNotContains( 'backup', $called, 'The ad-hoc task flow must not invoke the legacy backup engine.' );
		$this->assertNotContains( 'backup_task_create', $called );
	}

	/**
	 * A zero budget performs a pure status read without advancing the task.
	 *
	 * @return void
	 */
	public function test_get_backup_task_status_pure_read() {
		$site_id = $this->create_test_site();
		$this->mock_child_action(
			'backup_task_status',
			array(
				'task_id' => 'task-abc',
				'phase'   => 'archiving_files',
				'type'    => 'full',
			)
		);

		$called = array();
		$this->record_child_actions( $called );

		$status = MainWP_Backup_Executor::get_backup_task_status( $site_id, 'task-abc' );

		$this->assertSame( 'running', $status['status'] );
		$this->assertSame( 'archiving_files', $status['meta']['phase'] );
		$this->assertContains( 'backup_task_status', $called );
		$this->assertNotContains( 'backup_task_run', $called, 'A pure read must not advance the task.' );
	}

	/**
	 * A budgeted status call drives run slices to completion, downloads the
	 * artifact, fires the completion hook once, and clears the persisted
	 * ad-hoc handle.
	 *
	 * @return void
	 */
	public function test_get_backup_task_status_budget_drives_to_completed() {
		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$this->give_site_real_privkey( $site_id );
		MainWP_DB::instance()->update_website_option(
			$website,
			'adhoc_backup_task',
			wp_json_encode(
				array(
					'task_id' => 'task-adhoc',
					'type'    => 'full',
					'started' => time() - 60,
				)
			)
		);
		$this->mock_child_action(
			'backup_task_run',
			array(
				'task_id' => 'task-adhoc',
				'phase'   => 'completed',
				'type'    => 'full',
				'file'    => 'backup-1749600000.tar.gz',
				'size'    => 66,
			)
		);
		$this->mock_child_action( 'delete_backup', array( 'result' => 'ok' ) );
		$this->stub_download_with_valid_gzip();

		$events = array();
		$this->record_completion_hook( $events );

		$ob_level = ob_get_level();
		ob_start();
		try {
			$status = MainWP_Backup_Executor::get_backup_task_status( $site_id, 'task-adhoc', 60 );
		} finally {
			while ( ob_get_level() > $ob_level ) {
				ob_end_clean();
			}
		}

		$this->assertSame( 'completed', $status['status'] );
		$this->assertSame( 'backup-1749600000.tar.gz', $status['meta']['file'] );
		$this->assertFileExists( $status['meta']['local'] );

		$this->assertCount( 1, $events );
		$this->assertTrue( $events[0]['success'] );
		$this->assertSame( 'task-adhoc', $events[0]['task_id'] );

		$this->assertSame( '', (string) MainWP_DB::instance()->get_website_option( $website, 'adhoc_backup_task' ), 'A terminal task must clear the persisted handle.' );
	}

	/**
	 * A failed task reports failed status, fires the completion hook with
	 * success false, and clears the persisted ad-hoc handle.
	 *
	 * @return void
	 */
	public function test_get_backup_task_status_failed_fires_hook() {
		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		MainWP_DB::instance()->update_website_option(
			$website,
			'adhoc_backup_task',
			wp_json_encode(
				array(
					'task_id' => 'task-adhoc',
					'type'    => 'full',
					'started' => time() - 60,
				)
			)
		);
		$this->mock_child_action(
			'backup_task_run',
			array(
				'task_id'    => 'task-adhoc',
				'phase'      => 'failed',
				'type'       => 'full',
				'error'      => 'Could not read table data for the backup.',
				'error_code' => 'mainwp_backup_db_query_failed',
			)
		);

		$events = array();
		$this->record_completion_hook( $events );

		$status = MainWP_Backup_Executor::get_backup_task_status( $site_id, 'task-adhoc', 60 );

		$this->assertSame( 'failed', $status['status'] );
		$this->assertSame( 'mainwp_backup_db_query_failed', $status['meta']['error_code'] );

		$this->assertCount( 1, $events );
		$this->assertFalse( $events[0]['success'] );

		$this->assertSame( '', (string) MainWP_DB::instance()->get_website_option( $website, 'adhoc_backup_task' ), 'A terminal task must clear the persisted handle.' );
	}

	/**
	 * The freshness check sees a recent full archive and ignores stale
	 * archives and database dumps.
	 *
	 * @return void
	 */
	public function test_has_recent_backup_detects_fresh_and_stale() {
		$site_id = $this->create_test_site();
		$dir     = \MainWP\Dashboard\MainWP_Backup_Private_Root::site_dir( $site_id );
		wp_mkdir_p( $dir );

		$this->assertFalse( MainWP_Backup_Executor::has_recent_backup( $site_id, 7 ), 'An empty backup directory has no recent backup.' );

		$sql_archive = $dir . 'db-example-June-1-2026.sql.tar.gz';
		file_put_contents( $sql_archive, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		$this->assertFalse( MainWP_Backup_Executor::has_recent_backup( $site_id, 7 ), 'Database dumps do not count as full backups.' );

		$full_archive = $dir . 'full-example-June-1-2026.tar.gz';
		file_put_contents( $full_archive, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		$this->assertTrue( MainWP_Backup_Executor::has_recent_backup( $site_id, 7 ), 'A fresh full archive is a recent backup.' );

		touch( $full_archive, time() - 10 * DAY_IN_SECONDS );
		$this->assertFalse( MainWP_Backup_Executor::has_recent_backup( $site_id, 7 ), 'An archive older than the window is not recent.' );

		unlink( $sql_archive );
		unlink( $full_archive );
	}

	/**
	 * has_recent_native_backup() sees a fresh complete native snapshot and
	 * returns false for a site that has none.
	 *
	 * @return void
	 */
	public function test_has_recent_native_backup_true_for_fresh_snapshot() {
		$site_id = $this->create_test_site();

		$this->assertFalse(
			MainWP_Backup_Executor::has_recent_native_backup( $site_id, 7 ),
			'A site with no snapshots has no recent native backup.'
		);

		$this->record_native_snapshot( $site_id );

		$this->assertTrue(
			MainWP_Backup_Executor::has_recent_native_backup( $site_id, 7 ),
			'A fresh complete full snapshot is a recent native backup.'
		);
	}

	/**
	 * Stale, pruned, and files-only snapshots do not count as a recent
	 * native backup.
	 *
	 * @return void
	 */
	public function test_has_recent_native_backup_ignores_stale_pruned_and_files_only() {
		$site_id = $this->create_test_site();

		$stale = $this->record_native_snapshot( $site_id, array( 'created' => time() - 10 * DAY_IN_SECONDS ) );
		$this->assertFalse(
			MainWP_Backup_Executor::has_recent_native_backup( $site_id, 7 ),
			'A snapshot older than the window is not recent.'
		);
		$this->delete_native_snapshot( $stale );

		$pruned = $this->record_native_snapshot( $site_id, array( 'status' => 'pruned' ) );
		$this->assertFalse(
			MainWP_Backup_Executor::has_recent_native_backup( $site_id, 7 ),
			'A pruned snapshot does not count.'
		);
		$this->delete_native_snapshot( $pruned );

		$this->record_native_snapshot( $site_id, array( 'type' => 'files' ) );
		$this->assertFalse(
			MainWP_Backup_Executor::has_recent_native_backup( $site_id, 7 ),
			'A files-only snapshot has no database to protect an update.'
		);
	}

	/**
	 * With the require-offsite policy on, only an offsite-verified snapshot
	 * satisfies the freshness check.
	 *
	 * @return void
	 */
	public function test_has_recent_native_backup_require_offsite() {
		$site_id = $this->create_test_site();
		$id      = $this->record_native_snapshot( $site_id );

		$this->assertFalse(
			MainWP_Backup_Executor::has_recent_native_backup( $site_id, 7, true ),
			'A fresh but unverified snapshot fails the offsite-verified bar.'
		);

		MainWP_Backup_Snapshots::instance()->set_offsite_state( $id, 'verified', time() );

		$this->assertTrue(
			MainWP_Backup_Executor::has_recent_native_backup( $site_id, 7, true ),
			'A fresh verified snapshot satisfies the offsite-verified bar.'
		);
	}

	/**
	 * Native and legacy freshness are independent signals, so the gate's
	 * OR-combine passes on either alone: a native snapshot with no archive on
	 * disk, or a legacy archive with no native snapshot.
	 *
	 * @return void
	 */
	public function test_native_and_legacy_freshness_are_independent() {
		$native_site = $this->create_test_site();
		$this->record_native_snapshot( $native_site );

		$this->assertTrue( MainWP_Backup_Executor::has_recent_native_backup( $native_site, 7 ) );
		$this->assertFalse( MainWP_Backup_Executor::has_recent_backup( $native_site, 7 ), 'No legacy archive on disk.' );

		$legacy_site = $this->create_test_site();
		$dir         = \MainWP\Dashboard\MainWP_Backup_Private_Root::site_dir( $legacy_site );
		wp_mkdir_p( $dir );
		$archive = $dir . 'full-example-June-1-2026.tar.gz';
		file_put_contents( $archive, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.

		$this->assertFalse( MainWP_Backup_Executor::has_recent_native_backup( $legacy_site, 7 ), 'No native snapshot recorded.' );
		$this->assertTrue( MainWP_Backup_Executor::has_recent_backup( $legacy_site, 7 ) );

		unlink( $archive );
	}

	/**
	 * Record a native snapshot row with controllable created/status/state.
	 *
	 * record_snapshot() always stamps a fresh complete row, so age, status
	 * and offsite-state overrides are applied with a direct update.
	 *
	 * @param int   $site_id Child site id.
	 * @param array $args    Overrides: type, created, status, offsite_state.
	 *
	 * @return int Snapshot id.
	 */
	private function record_native_snapshot( $site_id, $args = array() ) {
		$id = MainWP_Backup_Snapshots::instance()->record_snapshot(
			array(
				'task_id'       => 1,
				'site_id'       => (int) $site_id,
				'type'          => isset( $args['type'] ) ? (string) $args['type'] : 'full',
				'archives'      => array(),
				'manifest_path' => '',
			)
		);

		$update = array();
		if ( isset( $args['created'] ) ) {
			$update['created'] = (int) $args['created'];
		}
		if ( isset( $args['status'] ) ) {
			$update['status'] = (string) $args['status'];
		}
		if ( isset( $args['offsite_state'] ) ) {
			$update['offsite_state'] = (string) $args['offsite_state'];
		}
		if ( ! empty( $update ) ) {
			global $wpdb;
			$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), $update, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB -- test fixture adjust.
		}

		return (int) $id;
	}

	/**
	 * Delete a snapshot row by id between assertions in one test.
	 *
	 * @param int $id Snapshot id.
	 *
	 * @return void
	 */
	private function delete_native_snapshot( $id ) {
		global $wpdb;
		$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB -- test fixture cleanup.
	}
}
