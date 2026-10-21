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
	 * The legacy completion paths must refuse the same executable extension.
	 *
	 * @dataProvider compromised_child_legacy_callers
	 * @param string $caller Completion entry point.
	 */
	public function test_compromised_child_legacy_archive_php_refused( $caller ) {
		$site_id = $this->create_test_site();
		$this->mock_child_action(
			'backup_preflight',
			array(
				'task_spine'    => 1,
				'disk_free'     => 10 * GB_IN_BYTES,
				'dir_writable'  => true,
				'db_connected'  => true,
				'zip_support'   => true,
				'time_limit_ok' => true,
			)
		);
		$this->mock_child_action(
			'backup_checkpid',
			array(
				'status' => 'done',
				'file'   => 'a.php',
				'size'   => 66,
			)
		);
		$this->mock_child_action(
			'backup',
			array(
				'full' => 'a.php',
				'db'   => false,
				'size' => 66,
			)
		);
		try {
			if ( 'monitor' === $caller ) {
				\MainWP\Dashboard\MainWP_Backup_Monitor::backup_check_pid( $site_id, 'hostile', 'full', '', null );
			} else {
				MainWP_Backup_Executor::backup( $site_id, 'full', '', '', 0, 0, 0, 0 );
			}
			$this->fail( 'The executable archive extension was accepted.' );
		} catch ( MainWP_Exception $error ) {
			$this->assertSame( \MainWP\Dashboard\MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT, $error->get_message_error_code() );
		}
	}

	/**
	 * Legacy archive callers.
	 *
	 * @return array
	 */
	public static function compromised_child_legacy_callers() {
		return array(
			'monitor'       => array( 'monitor' ),
			'legacy backup' => array( 'backup' ),
		);
	}

	/** A compromised Child must not choose the local archive's PHP extension. */
	public function test_compromised_child_single_archive_php_refused() {
		$site_id = $this->create_test_site();
		$this->give_site_real_privkey( $site_id );
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		MainWP_DB::instance()->update_website_option(
			$website,
			'adhoc_backup_task',
			wp_json_encode(
				array(
					'task_id' => 'hostile-archive',
					'type'    => 'full',
					'started' => time(),
				)
			)
		);
		$this->mock_child_action(
			'backup_task_run',
			array(
				'phase' => 'completed',
				'type'  => 'full',
				'file'  => 'a.php',
				'size'  => 66,
			)
		);
		$this->stub_download_with_valid_gzip();
		$this->expectException( MainWP_Exception::class );
		$this->expectExceptionMessage( \MainWP\Dashboard\MainWP_Backup_Error_Codes::message( \MainWP\Dashboard\MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT ) );
		MainWP_Backup_Executor::get_backup_task_status( $site_id, 'hostile-archive', 60 );
	}

	/** A gate download retry must reuse its persisted reservation. */
	public function test_n1_update_gate_retry_reuses_reserved_path() {
		$site_id = $this->create_test_site();
		$this->give_site_real_privkey( $site_id );
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		MainWP_DB::instance()->update_website_option(
			$website,
			'adhoc_backup_task',
			wp_json_encode(
				array(
					'task_id' => 'n1-gate',
					'type'    => 'full',
					'started' => time(),
				)
			)
		);
		$this->mock_child_action(
			'backup_task_run',
			array(
				'phase' => 'completed',
				'type'  => 'full',
				'file'  => 'gate.zip',
				'size'  => 66,
			)
		);
		$this->mock_child_action( 'delete_backup', array( 'result' => 'ok' ) );
		$paths    = array();
		$download = function ( $pre, $url, $file ) use ( &$paths ) {
			$paths[] = $file;
			if ( 1 === count( $paths ) ) {
				$this->assertFileExists( $file . '.reserved' );
				$this->assertFileDoesNotExist( $file );
				file_put_contents( $file, "PK\x03\x04" );
				return new MainWP_Exception( 'Interrupted transfer.' );
			}
			file_put_contents( $file, "PK\x03\x04" . str_repeat( 'x', 62 ) );
			return true;
		};
		add_filter( 'mainwp_download_to_file_pre', $download, 10, 3 );
		ob_start();
		try {
			try {
				MainWP_Backup_Executor::get_backup_task_status( $site_id, 'n1-gate', 60 );
				$this->fail( 'The first transfer must be interrupted.' );
			} catch ( MainWP_Exception $error ) {
				$this->assertSame( 'Interrupted transfer.', $error->getMessage() );
			}
			$handle = json_decode( MainWP_DB::instance()->get_website_option( $website, 'adhoc_backup_task' ), true );
			$this->assertSame( $paths[0], $handle['local'] ?? '' );
			$result = MainWP_Backup_Executor::get_backup_task_status( $site_id, 'n1-gate', 60 );
			$this->assertSame( 'completed', $result['status'] );
			$this->assertSame( $paths[0], $paths[1] );
		} finally {
			ob_end_clean();
			remove_filter( 'mainwp_download_to_file_pre', $download );
		}
	}

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
	 * A one-task prune counts as confirmed only when a clean answer accounts
	 * for exactly that task as pruned or missing.
	 *
	 * @dataProvider data_prune_answer_shapes
	 *
	 * @param mixed $answer    Child answer.
	 * @param bool  $confirmed Expected verdict.
	 *
	 * @return void
	 */
	public function test_child_prune_confirmed( $answer, $confirmed ) {
		$this->assertSame( $confirmed, MainWP_Backup_Executor::child_prune_confirmed( $answer ) );
	}

	/**
	 * Prune answers and their verdicts.
	 *
	 * @return array
	 */
	public static function data_prune_answer_shapes() {
		$answer = static function ( $over ) {
			return array_merge(
				array(
					'result'  => 'ok',
					'pruned'  => 1,
					'active'  => 0,
					'missing' => 0,
				),
				$over
			);
		};
		return array(
			'pruned'              => array( $answer( array() ), true ),
			'missing'             => array( $answer( array( 'pruned' => 0, 'missing' => 1 ) ), true ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- compact fixture.
			'digit strings'       => array( $answer( array( 'pruned' => '1', 'active' => '0', 'missing' => '0' ) ), true ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- compact fixture.
			'still active'        => array( $answer( array( 'pruned' => 0, 'active' => 1 ) ), false ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- compact fixture.
			'array count'         => array( $answer( array( 'pruned' => array( 'junk' ) ) ), false ),
			'negative count'      => array( $answer( array( 'pruned' => -1, 'missing' => 2 ) ), false ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- compact fixture.
			'two targets'         => array( $answer( array( 'pruned' => 2 ) ), false ),
			'pruned and active'   => array( $answer( array( 'active' => 1 ) ), false ),
			'error key'           => array( $answer( array( 'error' => 'Denied' ) ), false ),
			'missing count'       => array(
				array(
					'result' => 'ok',
					'pruned' => 1,
				),
				false,
			),
			'result not ok'       => array( $answer( array( 'result' => 'OK' ) ), false ),
			'float count'         => array( $answer( array( 'pruned' => 1.0 ) ), false ),
			'signed digit string' => array( $answer( array( 'pruned' => '+1' ) ), false ),
			'not an array'        => array( 'ok', false ),
		);
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
			$now                 = time();
			$handle['started']   = $now - $age;
			$handle['driven_at'] = $now - 30;
			$before              = wp_json_encode( $handle );
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
			if ( 'resume' === $expect ) {
				$persisted = json_decode( $after, true );
				$this->assertIsInt( $persisted['driven_at'] );
				$this->assertGreaterThanOrEqual( $now, $persisted['driven_at'] );
				$this->assertLessThanOrEqual( $finished, $persisted['driven_at'] );
				$handle['driven_at'] = $persisted['driven_at'];
				$this->assertSame( $handle, $persisted, 'Owner drive preserves every other handle field.' );
			} else {
				$this->assertSame( $before, $after );
			}
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
	 * Update-gate downloads use completed size and account for a partial file.
	 *
	 * @dataProvider update_gate_disk_cases
	 * @param mixed $free Measured capacity.
	 * @param mixed $size Completed size, or null when missing.
	 * @param int   $partial Bytes already stored.
	 * @param bool  $refused Expected refusal.
	 * @param mixed $after_sweep Capacity after deleting an old archive, or null.
	 */
	public function test_update_gate_download_disk_guard( $free, $size, $partial, $refused, $after_sweep = null ) {
		global $wp_filesystem;
		$original_filesystem = $wp_filesystem;
		$site_id             = $this->create_test_site();
		$this->give_site_real_privkey( $site_id );
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		MainWP_DB::instance()->update_website_option(
			$website,
			'adhoc_backup_task',
			wp_json_encode(
				array(
					'task_id' => 'gate-disk',
					'type'    => 'full',
					'started' => time() - 60,
				)
			)
		);
		\MainWP\Dashboard\MainWP_Backup_Run_History::start( 0, $site_id, time() - 60, 'update_gate', (int) $website->userid, null, 'gate-disk' );
		$payload = array(
			'phase' => 'completed',
			'type'  => 'full',
			'file'  => 'gate.zip',
		);
		if ( null !== $size ) {
			$payload['size'] = $size;
		}
		$this->mock_child_action( 'backup_task_run', $payload );
		$this->mock_child_action( 'delete_backup', array( 'result' => 'ok' ) );
		$called = array();
		$this->record_child_actions( $called );
		$builder = new \ReflectionMethod( MainWP_Backup_Executor::class, 'build_local_backup_file' );
		$local   = $builder->invoke( null, $website, 'full', 'gate.zip' );
		MainWP_DB::instance()->update_website_option(
			$website,
			'adhoc_backup_task',
			wp_json_encode(
				array(
					'task_id' => 'gate-disk',
					'type'    => 'full',
					'started' => time() - 60,
					'local'   => $local,
				)
			)
		);
		$old = dirname( $local ) . '/older-gate.zip';
		if ( null !== $after_sweep ) {
			delete_option( 'mainwp_backupsOnServer' );
			file_put_contents( $old, "PK\x03\x04" . str_repeat( 'o', 62 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- retention fixture.
			$wp_filesystem                    = new class( null ) extends \WP_Filesystem_Direct { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- retain real deletion with deterministic capacity.
				/**
				 * Archive whose removal releases capacity.
				 *
				 * @var string
				 */
				public $old_archive;
				/**
				 * Capacity once the archive is gone.
				 *
				 * @var float
				 */
				public $free_after_delete;
				/**
				 * Capacity must change only after the real deletion succeeds.
				 *
				 * @param string $file Path.
				 * @param bool   $recursive Whether to delete recursively.
				 * @param string $type File type.
				 * @return bool
				 */
				public function delete( $file, $recursive = false, $type = false ) {
					$deleted = parent::delete( $file, $recursive, $type );
					if ( $deleted && $file === $this->old_archive ) {
						MainWP_Backup_TestRestore_Sandbox_Test::$free_space = $this->free_after_delete;
					}
					return $deleted;
				}
			};
			$wp_filesystem->old_archive       = $old;
			$wp_filesystem->free_after_delete = $after_sweep;
		}
		if ( $partial > 0 ) {
			file_put_contents( $local, 'PK' . str_repeat( 'x', $partial - 2 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- resume fixture.
		}
		$downloads              = array();
		$stored_before_transfer = array();
		add_filter(
			'mainwp_download_to_file_pre',
			static function ( $pre, $url, $file, $expected ) use ( &$downloads, &$stored_before_transfer ) {
				$downloads[]              = $expected;
				$stored_before_transfer[] = is_file( $file ) ? filesize( $file ) : 0;
				file_put_contents( $file, "PK\x03\x04" . str_repeat( 'z', 62 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- transfer fixture.
				return true;
			},
			10,
			4
		);
		MainWP_Backup_TestRestore_Sandbox_Test::$free_space = $free;
		ob_start();
		try {
			$status = MainWP_Backup_Executor::get_backup_task_status( $site_id, 'gate-disk', 60 );
		} finally {
			ob_end_clean();
			MainWP_Backup_TestRestore_Sandbox_Test::$free_space = null;
			$wp_filesystem                                      = $original_filesystem; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restore the shared fixture.
		}
		if ( null !== $after_sweep ) {
			$this->assertFileDoesNotExist( $old, 'Capacity must be measured after retention removes the old gate archive.' );
		}
		$this->assertSame( $refused ? 'failed' : 'completed', $status['status'] );
		if ( $refused ) {
			$this->assertSame( \MainWP\Dashboard\MainWP_Backup_Error_Codes::ERR_DISK_FULL, $status['meta']['error_code'] );
			$this->assertSame( array(), $downloads );
			$this->assertFileDoesNotExist( $local );
		} else {
			$this->assertFileExists( $local );
			$this->assertSame( array( $size ?? false ), $downloads );
			$this->assertSame( array( $partial ), $stored_before_transfer, 'The retention sweep must preserve the partial used by the guard.' );
		}
		$this->assertContains( 'delete_backup', $called );
		$this->assertSame( '', (string) MainWP_DB::instance()->get_website_option( $website, 'adhoc_backup_task' ) );
	}

	/**
	 * Update-gate capacity and resume cases.
	 *
	 * @return array
	 */
	public static function update_gate_disk_cases() {
		$margin = 100 * MB_IN_BYTES;
		return array(
			'refused'                 => array( $margin + 65, 66, 0, true ),
			'fits exactly'            => array( $margin + 66, 66, 0, false ),
			'free unknown'            => array( false, 66, 0, false ),
			'size missing'            => array( 1, null, 0, false ),
			'resume fits'             => array( $margin + 33, 66, 33, false ),
			'resume rejected'         => array( $margin + 32, 66, 33, true ),
			'retention fits exactly'  => array( $margin, 66, 0, false, $margin + 66 ),
			'retention still refuses' => array( $margin, 66, 0, true, $margin + 65 ),
		);
	}

	/**
	 * A database-only ad-hoc backup also leaves the child after its download,
	 * and a child that fails the delete still gets the completion hook and
	 * the cleared handle.
	 *
	 * @dataProvider data_adhoc_delete_answers
	 *
	 * @param string $type   Backup type the child reports.
	 * @param string $file   Archive name.
	 * @param mixed  $answer Child answer to delete_backup, or an exception it throws.
	 *
	 * @return void
	 */
	public function test_get_backup_task_status_deletes_child_archive( $type, $file, $answer ) {
		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$this->give_site_real_privkey( $site_id );
		MainWP_DB::instance()->update_website_option(
			$website,
			'adhoc_backup_task',
			wp_json_encode(
				array(
					'task_id' => 'task-adhoc',
					'type'    => $type,
					'started' => time() - 60,
				)
			)
		);
		$this->mock_child_action(
			'backup_task_run',
			array(
				'task_id' => 'task-adhoc',
				'phase'   => 'completed',
				'type'    => $type,
				'file'    => $file,
				'size'    => 66,
			)
		);
		$this->mock_child_action( 'delete_backup', $answer );
		$deletes = array();
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $site, $what, $params ) use ( &$deletes ) {
				unset( $site );
				if ( 'delete_backup' === $what ) {
					$deletes[] = $params['del'] ?? null;
				}
				return $result;
			},
			5,
			4
		);
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
		$this->assertSame( array( $file ), $deletes );
		$this->assertCount( 1, $events );
		$this->assertTrue( $events[0]['success'] );
		$this->assertSame( '', (string) MainWP_DB::instance()->get_website_option( $website, 'adhoc_backup_task' ), 'A terminal task must clear the persisted handle.' );
	}

	/**
	 * Archive types and child answers to the delete.
	 *
	 * @return array
	 */
	public function data_adhoc_delete_answers() {
		return array(
			'database archive'      => array( 'db', 'dbBackup-1749600000.sql.tar.gz', array( 'result' => 'ok' ) ),
			'full archive, error'   => array( 'full', 'backup-1749600000.tar.gz', new MainWP_Exception( 'The child site is busy.' ) ),
			'database archive, err' => array( 'db', 'dbBackup-1749600000.sql.tar.gz', new MainWP_Exception( 'The child site is busy.' ) ),
		);
	}

	/**
	 * An ad-hoc handle that cannot be cleared keeps the child copy: the next
	 * call resumes the same task and downloads the archive again, and deletes
	 * the child copy once the handle is gone. A read-back that errors proves
	 * nothing about the handle, so it keeps the child copy too.
	 *
	 * @dataProvider data_handle_clear_failures
	 *
	 * @param bool $read_fails Whether the read-back of the handle errors as well.
	 *
	 * @return void
	 */
	public function test_get_backup_task_status_keeps_child_archive_until_handle_clears( $read_fails ) {
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
		$deletes = array();
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $site, $what, $params ) use ( &$deletes ) {
				unset( $site );
				if ( 'delete_backup' !== $what ) {
					return $result;
				}
				$deletes[] = $params['del'] ?? null;
				return array( 'result' => 'ok' );
			},
			10,
			4
		);
		$this->stub_download_with_valid_gzip();
		$keep_handle = static function ( $query ) use ( $read_fails ) {
			if ( ! str_contains( $query, 'mainwp_wp_options' ) || ! str_contains( $query, MainWP_Backup_Executor::ADHOC_TASK_OPTION ) ) {
				return $query;
			}
			if ( str_starts_with( $query, 'UPDATE ' ) && str_contains( $query, "`value` = ''" ) ) {
				return $query . ' AND 1 = 0';
			}
			return $read_fails && str_starts_with( $query, 'SELECT value ' ) ? $query . ' AND no_such_column = 1' : $query;
		};

		global $wpdb;
		$ob_level = ob_get_level();
		ob_start();
		// The failing read-back is the point of the case; wpdb would print its error past the buffer.
		$suppressed = $wpdb->suppress_errors( true );
		add_filter( 'query', $keep_handle );
		try {
			$first = MainWP_Backup_Executor::get_backup_task_status( $site_id, 'task-adhoc', 60 );
		} finally {
			remove_filter( 'query', $keep_handle );
			$wpdb->suppress_errors( $suppressed );
			while ( ob_get_level() > $ob_level ) {
				ob_end_clean();
			}
		}

		$this->assertSame( 'completed', $first['status'] );
		$this->assertFileExists( $first['meta']['local'] );
		$this->assertSame( array(), $deletes, 'The child copy must stay while the handle still names the task.' );
		$this->assertSame( 'task-adhoc', json_decode( (string) MainWP_DB::instance()->get_website_option( $website, 'adhoc_backup_task' ), true )['task_id'] );

		ob_start();
		try {
			$second = MainWP_Backup_Executor::get_backup_task_status( $site_id, 'task-adhoc', 60 );
		} finally {
			while ( ob_get_level() > $ob_level ) {
				ob_end_clean();
			}
		}

		$this->assertSame( 'completed', $second['status'] );
		$this->assertSame( array( 'backup-1749600000.tar.gz' ), $deletes, 'The child copy goes once the handle is cleared.' );
		$this->assertSame( '', (string) MainWP_DB::instance()->get_website_option( $website, 'adhoc_backup_task' ) );
	}

	/**
	 * Ways the handle clear can go unconfirmed.
	 *
	 * @return array
	 */
	public function data_handle_clear_failures() {
		return array(
			'clear write fails'                => array( false ),
			'clear write and read-back failed' => array( true ),
		);
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
		// The legacy directory is keyed by site id and lives in the shared test
		// uploads dir, so a reused id can inherit an archive another run left behind.
		$legacy_dir = \MainWP\Dashboard\MainWP_Backup_Private_Root::legacy_site_dir( $native_site );
		array_map( 'unlink', array_filter( glob( $legacy_dir . '*' ) ?: array(), 'is_file' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture isolation.
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
