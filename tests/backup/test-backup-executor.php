<?php
/**
 * MainWP Backup Handler Tests
 *
 * Tests for backup_site() retry/state handling, silent failure paths,
 * task-spine lifecycle, and child error code propagation.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

require_once __DIR__ . '/trait-backup-private-root-fixture.php';

use MainWP\Dashboard\MainWP_Backup_Executor;
use MainWP\Dashboard\MainWP_Backup_Monitor;
use MainWP\Dashboard\MainWP_Backup_Error_Codes;
use MainWP\Dashboard\MainWP_Backup_File_Manager;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Exception;
use MainWP\Dashboard\MainWP_Logger;
use MainWP\Dashboard\MainWP_Manage_Backups_Handler;
use MainWP\Dashboard\MainWP_Post_Backup_Handler;

/**
 * Class MainWP_Backup_Executor_Test
 */
class MainWP_Backup_Executor_Test extends MainWP_Abilities_Test_Case {

	use Backup_Private_Root_Fixture;

	/**
	 * An exact-size retry file must pass the downloaded archive validation.
	 *
	 * @dataProvider exact_size_archive_cases
	 * @param string $role Archive role.
	 * @param bool   $valid Whether the cached archive is valid.
	 */
	public function test_b155_exact_size_archive_retry( $role, $valid ) {
		list( $site_id, $task ) = $this->site_and_task( array( 'type' => 'incremental' ) );
		$content                = "PK\x03\x04" . str_repeat( 'x', 4092 );
		$child_task_id          = 'b155-' . $site_id;
		$this->give_site_real_privkey( $site_id );
		$this->seed_progress( $task->id, $site_id, array( 'child_backup_task_id' => $child_task_id ) );
		$this->mock_child_action(
			'backup_task_run',
			array(
				'task_id'   => $child_task_id,
				'phase'     => 'completed',
				'artifacts' => array(
					array(
						'file' => 'cached.zip',
						'size' => strlen( $content ),
						'role' => $role,
					),
				),
			)
		);
		$run_dir = \MainWP\Dashboard\MainWP_Backup_Private_Root::site_dir( $site_id ) . 'snapshot-' . $child_task_id . '/';
		wp_mkdir_p( $run_dir );
		$local = $run_dir . 'cached.zip';
		file_put_contents( $local, $valid ? $content : str_repeat( 'x', strlen( $content ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		$downloads = 0;
		$download  = function ( $pre, $url, $file ) use ( &$downloads, $content, $local ) {
			++$downloads;
			$this->assertSame( $local, $file );
			$this->assertFileDoesNotExist( $file, 'Discard the invalid archive before downloading it again.' );
			file_put_contents( $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
			return true;
		};
		add_filter( 'mainwp_download_to_file_pre', $download, 10, 3 );
		try {
			$this->assertNotFalse( MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' ) );
		} finally {
			remove_filter( 'mainwp_download_to_file_pre', $download );
		}
		$this->assertSame( $valid ? 0 : 1, $downloads, 'Only a validated cached archive may skip the download.' );
		$this->assertSame( $content, file_get_contents( $local ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- test fixture.
		$snapshot = MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, (int) $task->id );
		$this->assertNotNull( $snapshot );
		$this->assertSame( $local, $snapshot->archives[0]['path'] );
	}

	/**
	 * Cached archives use the same validation for every archive role.
	 *
	 * @return array Retry cases.
	 */
	public static function exact_size_archive_cases() {
		return array(
			'valid full'   => array( 'full', true ),
			'invalid full' => array( 'full', false ),
			'valid part'   => array( 'part', true ),
			'invalid part' => array( 'part', false ),
			'valid db'     => array( 'db', true ),
			'invalid db'   => array( 'db', false ),
		);
	}

	/**
	 * A pre-write storage failure must reach the normal task error path.
	 */
	public function test_unusable_private_root_fails_without_artifacts() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->seed_download_ready_progress( $task->id, $site_id, 4096 );
		mkdir( $this->private_root, 0500 );
		try {
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
			$this->fail( 'Unavailable storage must fail the backup.' );
		} catch ( MainWP_Exception $error ) {
			$this->assertSame( MainWP_Backup_Error_Codes::ERR_PRIVATE_ROOT_UNAVAILABLE, $error->get_message_error_code() );
			$this->assertStringContainsString( $this->private_root, $error->getMessage() );
			$this->assertStringContainsString( 'write probe', $error->getMessage() );
			$this->assertSame( array( '.', '..' ), scandir( $this->private_root ) );
			$this->assertNull( MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, (int) $task->id ) );
		} finally {
			chmod( $this->private_root, 0700 );
		}
	}

	/**
	 * Original snapshot singleton, restored after each test.
	 *
	 * @var MainWP_Backup_Snapshots|null
	 */
	private $snapshot_instance;

	/**
	 * Downloaded fixtures owned by snapshot failure tests.
	 *
	 * @var string[]
	 */
	private $snapshot_files = array();

	/**
	 * Set up: enable the legacy backup feature.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->set_up_private_root();
		update_option( 'mainwp_enableNativeBackups', 1 );
		$property                = new \ReflectionProperty( MainWP_Backup_Snapshots::class, 'instance' );
		$this->snapshot_instance = $property->getValue();
	}

	/**
	 * Clean up: progress rows and option.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		$property = new \ReflectionProperty( MainWP_Backup_Snapshots::class, 'instance' );
		$property->setValue( null, $this->snapshot_instance );
		foreach ( $this->snapshot_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		$this->snapshot_files = array();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_wp_backup_progress" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup.
		delete_option( 'mainwp_enableNativeBackups' );
		$this->tear_down_private_root();
		parent::tearDown();
	}

	/**
	 * Build a backup task object for backup_site().
	 *
	 * @param int   $userid    Owner user id.
	 * @param array $overrides Field overrides.
	 *
	 * @return object Task.
	 */
	private function make_task( $userid, $overrides = array() ) {
		return (object) array_merge(
			array(
				'id'                             => 1,
				'userid'                         => $userid,
				'type'                           => 'full',
				'exclude'                        => '',
				'excludebackup'                  => 0,
				'excludecache'                   => 0,
				'excludenonwp'                   => 0,
				'excludezip'                     => 0,
				'filename'                       => '',
				'archiveFormat'                  => 'zip',
				'loadFilesBeforeZip'             => 1,
				'maximumFileDescriptorsOverride' => 0,
				'maximumFileDescriptorsAuto'     => 1,
				'maximumFileDescriptors'         => 150,
				'last_run'                       => time() - 100,
				'subfolder'                      => 'backups',
			),
			$overrides
		);
	}

	/**
	 * Create a site and a matching task.
	 *
	 * @param array $task_overrides Task field overrides.
	 *
	 * @return array Site ID and task.
	 */
	private function site_and_task( $task_overrides = array() ) {
		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$task    = $this->make_task( $website->userid, $task_overrides );
		return array( $site_id, $task );
	}

	/**
	 * Seed a progress row that routes backup_site() into the status-check branch.
	 *
	 * @param int   $task_id Task ID.
	 * @param int   $site_id Site ID.
	 * @param array $values  Extra progress values.
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
	 * Invoke the on-demand progress poll and return its decoded JSON payload.
	 *
	 * @param int $site_id Site to poll.
	 * @param int $since   Click-time run stamp.
	 *
	 * @return array|null Decoded response.
	 */
	private function poll_on_demand( $site_id, $since ) {
		$nonce                = wp_create_nonce( 'mainwp_native_backup_progress' );
		$_POST['security']    = $nonce;
		$_REQUEST['security'] = $nonce;
		$_POST['site_id']     = $site_id;
		$_POST['since']       = (string) $since;

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
			unset( $e ); // wp_send_json terminated the request as designed.
		} finally {
			$raw = ob_get_clean();
			remove_filter( 'wp_die_ajax_handler', $throw );
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}

		return json_decode( $raw, true );
	}

	/**
	 * Mock a child response for a specific action.
	 *
	 * @param string $action   Action name (e.g. 'backup_task_run').
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
	 * Disabled legacy backup feature is reported, not silently ignored.
	 *
	 * @return void
	 */
	public function test_backup_site_disabled_feature_throws_legacy_disabled() {
		delete_option( 'mainwp_enableNativeBackups' );
		list( $site_id, $task ) = $this->site_and_task();

		try {
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
			$this->fail( 'Expected exception when the legacy backup feature is disabled.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_legacy_disabled', $e->get_message_error_code() );
		}
	}

	/**
	 * Disabled legacy backup feature is reported from the direct backup path too.
	 *
	 * @return void
	 */
	public function test_backup_disabled_feature_throws_legacy_disabled() {
		delete_option( 'mainwp_enableNativeBackups' );
		$site_id = $this->create_test_site();

		try {
			MainWP_Backup_Executor::backup( $site_id, 'full', '', '', 1, 1, 1, 1 );
			$this->fail( 'Expected exception when the legacy backup feature is disabled.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_legacy_disabled', $e->get_message_error_code() );
		}
	}

	/**
	 * Child error responses carry their error code through to the exception.
	 *
	 * @return void
	 */
	public function test_child_error_code_propagates_to_exception() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->mock_child_action( 'backup_preflight', array( 'task_spine' => 1 ) );
		$this->mock_child_action(
			'backup_task_create',
			array(
				'error'      => 'Could not write backup data.',
				'error_code' => 'mainwp_backup_disk_full',
			)
		);

		try {
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
			$this->fail( 'Expected child error exception.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_disk_full', $e->get_message_error_code() );
		}
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
	 * Seed a progress row whose fetch result is ready for the download phase.
	 *
	 * @param int $task_id Task ID.
	 * @param int $site_id Site ID.
	 * @param int $size    Reported backup size.
	 *
	 * @return void
	 */
	private function seed_download_ready_progress( $task_id, $site_id, $size ) {
		$this->give_site_real_privkey( $site_id );
		MainWP_DB_Backup::instance()->add_backup_task_progress( $task_id, $site_id, array() );
		MainWP_DB_Backup::instance()->update_backup_task_progress(
			$task_id,
			$site_id,
			array(
				'dtsFetched'   => time(),
				'pid'          => 12345,
				'removedFiles' => 1,
				'fetchResult'  => wp_json_encode(
					array(
						'full' => 'backup-file.zip',
						'db'   => false,
						'size' => $size,
					)
				),
			)
		);
	}

	/**
	 * Failed full downloads retain the HTTP status and consume one retry.
	 */
	public function test_thrown_full_download_is_recorded_on_the_diag_seam() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->seed_download_ready_progress( $task->id, $site_id, 4096 );
		MainWP_DB_Backup::instance()->update_backup_task_progress(
			$task->id,
			$site_id,
			array(
				'attempts'             => 2,
				'child_backup_task_id' => 'download-task',
			)
		);
		$throw     = static function () {
			throw new MainWP_Exception( 'Backup download failed: the child site returned an unexpected response.', 'HTTP 504', 'mainwp_backup_http_error' );
		};
		$downloads = array();
		$listen    = static function ( $payload, $duration, $context ) use ( &$downloads ) {
			if ( 'download' === $context['op'] ) {
				$downloads[] = array( $payload, $duration, $context );
			}
		};
		$logger    = MainWP_Logger::instance();
		$priority  = new \ReflectionProperty( $logger, 'logPriority' );
		$priority->setAccessible( true );
		$previous = $priority->getValue( $logger );
		$priority->setValue( $logger, MainWP_Logger::WARNING );
		$warnings = array();
		$capture  = static function ( $text ) use ( &$warnings ) {
			if ( false !== strpos( $text, 'Backup download failed; the next cycle retries it:' ) ) {
				$warnings[] = $text;
			}
		};
		add_filter( 'mainwp_log_do_to_db', '__return_true' );
		add_action( 'mainwp_before_log_data', $capture );
		add_filter( 'mainwp_download_to_file_pre', $throw );
		add_action( 'mainwp_backup_diag_poll', $listen, 10, 3 );
		try {
			$this->assertFalse( MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' ) );
			$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
			$this->assertSame( 3, (int) $progress->attempts, 'The failed download must consume exactly one retry attempt.' );
			$this->assertCount( 1, $downloads );
			list( $payload, $duration, $context ) = $downloads[0];
			$this->assertTrue( $payload['thrown'] );
			$this->assertTrue( $payload['sent'] );
			$this->assertSame( 'download-task', $payload['task_id'] );
			$this->assertCount( 1, $warnings );
			$this->assertStringContainsString( '(HTTP 504)', $warnings[0] );
			$this->assertSame( 'full', $payload['artifact'] );
			$this->assertSame( 2, $payload['attempt'] );
			$this->assertSame( 'Backup download failed: the child site returned an unexpected response.', $payload['message'] );
			$this->assertSame( 'HTTP 504', $payload['extra'] );
			$this->assertSame( 0, $payload['code'] );
			$this->assertSame( 'mainwp_backup_http_error', $payload['error_code'] );
			$this->assertIsFloat( $duration );
			$this->assertGreaterThanOrEqual( 0, $duration );
			$this->assertSame( (int) $task->id, $context['task_id'] );
			$this->assertSame( (int) $site_id, $context['site_id'] );
		} finally {
			$priority->setValue( $logger, $previous );
			remove_action( 'mainwp_before_log_data', $capture );
			remove_filter( 'mainwp_log_do_to_db', '__return_true' );
			remove_filter( 'mainwp_download_to_file_pre', $throw );
			remove_action( 'mainwp_backup_diag_poll', $listen, 10 );
		}
	}

	/**
	 * Failed database downloads retain the HTTP status and consume one retry.
	 */
	public function test_thrown_db_download_is_recorded_on_the_diag_seam() {
		list( $site_id, $task ) = $this->site_and_task( array( 'type' => 'db' ) );
		$this->seed_download_ready_progress( $task->id, $site_id, 4096 );
		MainWP_DB_Backup::instance()->update_backup_task_progress(
			$task->id,
			$site_id,
			array(
				'attempts'             => 2,
				'child_backup_task_id' => 'download-task',
				'fetchResult'          => wp_json_encode(
					array(
						'db'   => 'backup.sql.zip',
						'full' => false,
						'size' => 4096,
					)
				),
			)
		);
		$throw     = static function () {
			throw new MainWP_Exception( 'Backup download failed: the child site returned an unexpected response.', 'HTTP 504', 'mainwp_backup_http_error' );
		};
		$downloads = array();
		$listen    = static function ( $payload, $duration, $context ) use ( &$downloads ) {
			if ( 'download' === $context['op'] ) {
				$downloads[] = array( $payload, $duration, $context );
			}
		};
		$logger    = MainWP_Logger::instance();
		$priority  = new \ReflectionProperty( $logger, 'logPriority' );
		$priority->setAccessible( true );
		$previous = $priority->getValue( $logger );
		$priority->setValue( $logger, MainWP_Logger::WARNING );
		$warnings = array();
		$capture  = static function ( $text ) use ( &$warnings ) {
			if ( false !== strpos( $text, 'Backup download failed; the next cycle retries it:' ) ) {
				$warnings[] = $text;
			}
		};
		add_filter( 'mainwp_log_do_to_db', '__return_true' );
		add_action( 'mainwp_before_log_data', $capture );
		add_filter( 'mainwp_download_to_file_pre', $throw );
		add_action( 'mainwp_backup_diag_poll', $listen, 10, 3 );
		try {
			$this->assertFalse( MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' ) );
			$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
			$this->assertSame( 3, (int) $progress->attempts, 'The failed download must consume exactly one retry attempt.' );
			$this->assertCount( 1, $downloads );
			list( $payload, $duration, $context ) = $downloads[0];
			$this->assertTrue( $payload['thrown'] );
			$this->assertTrue( $payload['sent'] );
			$this->assertSame( 'download-task', $payload['task_id'] );
			$this->assertCount( 1, $warnings );
			$this->assertStringContainsString( '(HTTP 504)', $warnings[0] );
			$this->assertSame( 'db', $payload['artifact'] );
			$this->assertSame( 2, $payload['attempt'] );
			$this->assertSame( 'Backup download failed: the child site returned an unexpected response.', $payload['message'] );
			$this->assertSame( 'HTTP 504', $payload['extra'] );
			$this->assertSame( 0, $payload['code'] );
			$this->assertSame( 'mainwp_backup_http_error', $payload['error_code'] );
			$this->assertIsFloat( $duration );
			$this->assertGreaterThanOrEqual( 0, $duration );
			$this->assertSame( (int) $task->id, $context['task_id'] );
			$this->assertSame( (int) $site_id, $context['site_id'] );
		} finally {
			$priority->setValue( $logger, $previous );
			remove_action( 'mainwp_before_log_data', $capture );
			remove_filter( 'mainwp_log_do_to_db', '__return_true' );
			remove_filter( 'mainwp_download_to_file_pre', $throw );
			remove_action( 'mainwp_backup_diag_poll', $listen, 10 );
		}
	}

	/**
	 * A diagnostic listener cannot consume the download retry or replace its yield.
	 */
	public function test_download_listener_failure_preserves_retry() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->seed_download_ready_progress( $task->id, $site_id, 4096 );
		$throw          = static function () {
			throw new MainWP_Exception( 'Download failed.', 'HTTP 504', 'mainwp_backup_http_error' );
		};
		$listener_calls = 0;
		$listen         = static function ( $payload, $duration, $context ) use ( &$listener_calls ) {
			if ( 'download' === $context['op'] ) {
				++$listener_calls;
				throw new \Error( 'Diagnostic listener failed.' );
			}
		};
		add_filter( 'mainwp_download_to_file_pre', $throw );
		add_action( 'mainwp_backup_diag_poll', $listen, 10, 3 );
		try {
			$this->assertFalse( MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' ) );
			$this->assertSame( 1, $listener_calls );
			$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
			$this->assertSame( 1, (int) $progress->attempts );
		} finally {
			remove_filter( 'mainwp_download_to_file_pre', $throw );
			remove_action( 'mainwp_backup_diag_poll', $listen, 10 );
		}
	}

	/**
	 * A held download lock stops a second process from downloading the
	 * same backup file.
	 *
	 * @return void
	 */
	public function test_download_lock_held_reports_already_running() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->seed_download_ready_progress( $task->id, $site_id, 4096 );
		$this->mock_child_action( 'delete_backup', array( 'result' => 'ok' ) );

		$locked_file = null;
		add_filter(
			'mainwp_download_to_file_pre',
			function ( $pre, $url, $file ) use ( &$locked_file ) {
				unset( $pre, $url );
				$locked_file = $file;
				return true; // Skip the real transfer; validation will reject the missing file.
			},
			10,
			3
		);

		// First run captures the local file path and stores it in the progress row.
		try {
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
		} catch ( MainWP_Exception $e ) {
			unset( $e );
		}
		$this->assertNotNull( $locked_file );

		// Another process now holds the lock; a new run must refuse to download.
		$this->assertTrue( MainWP_Backup_File_Manager::acquire_download_lock( $locked_file, 999, 999 ) );
		try {
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
			$this->fail( 'Expected already-running exception while the download lock is held.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_already_running', $e->get_message_error_code() );
		}

		MainWP_Backup_File_Manager::release_download_lock( $locked_file );
	}

	/**
	 * A completed full run through the download branch records a snapshot
	 * row carrying the downloaded artifact, exactly once per run.
	 *
	 * @return void
	 */
	public function test_completed_full_run_records_snapshot() {
		list( $site_id, $task ) = $this->site_and_task();
		$content                = 'PK' . "\x03\x04" . str_repeat( 'x', 4092 );
		$this->seed_download_ready_progress( $task->id, $site_id, strlen( $content ) );
		$this->mock_child_action( 'delete_backup', array( 'result' => 'ok' ) );

		add_filter(
			'mainwp_download_to_file_pre',
			function ( $pre, $url, $file ) use ( $content ) {
				unset( $pre, $url );
				file_put_contents( $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
				return true;
			},
			10,
			3
		);

		$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
		$this->assertNotFalse( $result );

		$snapshot = \MainWP\Dashboard\MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, (int) $task->id );

		$this->assertNotNull( $snapshot, 'A completed full run must record a snapshot row.' );
		$this->assertSame( 'full', $snapshot->type );
		$this->assertNull( $snapshot->parent_id );
		$this->assertCount( 1, $snapshot->archives );
		$this->assertSame( 'full', $snapshot->archives[0]['role'] );
		$this->assertSame( strlen( $content ), (int) $snapshot->archives[0]['size'] );
		$this->assertFileExists( $snapshot->archives[0]['path'] );
	}

	/**
	 * Manifest failures preserve the validated archive and postprocess hook.
	 *
	 * @dataProvider manifest_failure_cases
	 * @param string $failure Manifest failure to simulate.
	 */
	public function test_manifest_failure_still_records_snapshot_and_postprocesses( $failure ) {
		list( $site_id, $task ) = $this->site_and_task();
		$content                = "PK\x03\x04" . str_repeat( 'x', 4092 );
		$this->seed_download_ready_progress( $task->id, $site_id, strlen( $content ) );
		$progress           = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$fetch              = $progress->fetchResult;
		$fetch['artifacts'] = array(
			array(
				'file' => 'files.list',
				'size' => 'invalid' === $failure ? 0 : 50,
				'role' => 'manifest',
			),
		);
		MainWP_DB_Backup::instance()->update_backup_task_progress( $task->id, $site_id, array( 'fetchResult' => wp_json_encode( $fetch ) ) );
		$this->mock_child_action( 'delete_backup', array( 'result' => 'ok' ) );
		$manifest_path = '';
		if ( 'invalid' === $failure ) {
			$run_dir = \MainWP\Dashboard\MainWP_Backup_Private_Root::site_dir( $site_id ) . 'snapshot-/';
			wp_mkdir_p( $run_dir );
			$manifest_path          = $run_dir . 'files.list';
			$this->snapshot_files[] = $manifest_path;
			file_put_contents( $manifest_path, 'partial' );
		}
		add_filter(
			'mainwp_download_to_file_pre',
			function ( $pre, $url, $file ) use ( $content, $failure, &$manifest_path ) {
				$this->snapshot_files[] = $file;
				if ( 'files.list' === basename( $file ) ) {
					$manifest_path = $file;
					file_put_contents( $file, 'partial' );
					if ( 'download' === $failure ) {
						throw new MainWP_Exception( 'Manifest download failed.' );
					}
				} else {
					file_put_contents( $file, $content );
				}
				return true;
			},
			10,
			3
		);
		$postprocessed = array();
		add_action(
			'mainwp_postprocess_backup_site',
			static function ( $file ) use ( &$postprocessed ) {
				$postprocessed[] = $file;
			}
		);
		$result   = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
		$snapshot = MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, (int) $task->id );
		$this->assertNotFalse( $result );
		$this->assertNotNull( $snapshot );
		$this->assertSame( '', $snapshot->manifest_path );
		$this->assertSame( array( 'full' ), array_column( $snapshot->archives, 'role' ) );
		$this->assertFileExists( $snapshot->archives[0]['path'] );
		$this->assertSame( array( $snapshot->archives[0]['path'] ), $postprocessed );
		$this->assertNotSame( '', $manifest_path );
		$this->assertFileDoesNotExist( $manifest_path );
	}

	/**
	 * Each failure happens after the full archive has been downloaded.
	 *
	 * @return array
	 */
	public static function manifest_failure_cases() {
		return array(
			'invalid artifact'   => array( 'invalid' ),
			'download exception' => array( 'download' ),
			'size mismatch'      => array( 'size' ),
		);
	}

	/**
	 * Child identity survives the full download and snapshot path.
	 */
	public function test_b11_completed_full_run_records_child_run_key() {
		list( $site_id, $task ) = $this->site_and_task();
		$content                = 'PK' . "\x03\x04" . str_repeat( 'x', 4092 );
		$this->seed_download_ready_progress( $task->id, $site_id, strlen( $content ) );
		MainWP_DB_Backup::instance()->update_backup_task_progress( $task->id, $site_id, array( 'child_backup_task_id' => 'b11-full-run' ) );
		$this->mock_child_action( 'delete_backup', array( 'result' => 'ok' ) );

		add_filter(
			'mainwp_download_to_file_pre',
			function ( $pre, $url, $file ) use ( $content ) {
				unset( $pre, $url );
				$this->snapshot_files[] = $file;
				file_put_contents( $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
				return true;
			},
			10,
			3
		);

		$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
		$this->assertNotFalse( $result );

		$snapshot = \MainWP\Dashboard\MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, (int) $task->id );

		$this->assertNotNull( $snapshot, 'A completed full run must record a snapshot row.' );
		$this->assertSame( $site_id . ':' . $task->id . ':c:b11-full-run', $snapshot->run_key );
		$this->assertSame( 'full', $snapshot->type );
		$this->assertNull( $snapshot->parent_id );
		$this->assertCount( 1, $snapshot->archives );
		$this->assertSame( 'full', $snapshot->archives[0]['role'] );
		$this->assertSame( strlen( $content ), (int) $snapshot->archives[0]['size'] );
		$this->assertFileExists( $snapshot->archives[0]['path'] );
	}

	/**
	 * Both producers must survive another finisher inserting after their read guard.
	 *
	 * @dataProvider b11_producer_types
	 * @param string $type Task type.
	 */
	public function test_b11_interleaved_record_adopts_child_run( $type ) {
		list( $site_id, $task ) = $this->site_and_task( array( 'type' => $type ) );
		$content                = 'PK' . "\x03\x04" . str_repeat( 'x', 4092 );
		$this->seed_download_ready_progress( $task->id, $site_id, strlen( $content ) );
		$fields = array( 'child_backup_task_id' => 'b11-race-' . $site_id );
		if ( 'incremental' === $type ) {
			$fields['fetchResult'] = wp_json_encode(
				array(
					'artifacts' => array(
						array(
							'file' => 'full.zip',
							'size' => strlen( $content ),
							'role' => 'full',
						),
					),
				)
			);
		}
		MainWP_DB_Backup::instance()->update_backup_task_progress( $task->id, $site_id, $fields );
		$this->mock_child_action( 'delete_backup', array( 'result' => 'ok' ) );
		add_filter(
			'mainwp_download_to_file_pre',
			function ( $pre, $url, $file ) use ( $content ) {
				$this->snapshot_files[] = $file;
				file_put_contents( $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
				return true;
			},
			10,
			3
		);
		$recorder = new class() extends MainWP_Backup_Snapshots {
			/**
			 * Winning row inserted after the executor's guard.
			 *
			 * @var int
			 */
			public $winner_id = 0;

			/**
			 * Interleave a competing insert before this finisher records.
			 *
			 * @param array $data Snapshot fields.
			 * @return int Adopted snapshot id.
			 */
			public function record_snapshot( array $data ): int {
				$this->winner_id = parent::record_snapshot( $data );
				return parent::record_snapshot( $data );
			}
		};
		$property = new \ReflectionProperty( MainWP_Backup_Snapshots::class, 'instance' );
		$property->setValue( null, $recorder );
		$before = did_action( 'mainwp_backup_snapshot_recorded' );

		$this->assertNotFalse( MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' ) );

		$rows = $recorder->get_snapshots_for_site( $site_id );
		$this->assertCount( 1, $rows );
		$this->assertGreaterThan( 0, $recorder->winner_id );
		$this->assertSame( $recorder->winner_id, (int) $rows[0]->id );
		$this->assertSame( $site_id . ':' . $task->id . ':c:b11-race-' . $site_id, $rows[0]->run_key );
		$this->assertSame( $before + 1, did_action( 'mainwp_backup_snapshot_recorded' ) );
	}

	/**
	 * Both producers can race after their initial snapshot lookup.
	 *
	 * @return array Both snapshot producer paths.
	 */
	public function b11_producer_types() {
		return array(
			'ordinary full'    => array( 'full' ),
			'incremental base' => array( 'incremental' ),
		);
	}

	/**
	 * The legacy token must exist before any bytes arrive and survive a retry.
	 */
	public function test_b11_legacy_token_is_persisted_before_download_and_reused() {
		list( $site_id, $task ) = $this->site_and_task();
		$content                = 'PK' . "\x03\x04" . str_repeat( 'x', 4092 );
		$this->seed_download_ready_progress( $task->id, $site_id, strlen( $content ) );
		$this->mock_child_action( 'delete_backup', array( 'result' => 'ok' ) );
		$token = null;
		add_filter(
			'mainwp_download_to_file_pre',
			function ( $pre, $url, $file ) use ( $content, $site_id, $task, &$token ) {
				$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
				$this->assertNotEmpty( $progress->fetchResult['snapshot_run_token'] );
				$token                  = $progress->fetchResult['snapshot_run_token'];
				$this->snapshot_files[] = $file;
				file_put_contents( $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
				return true;
			},
			10,
			3
		);

		$this->assertNotFalse( MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' ) );
		$this->assertNotEmpty( $token );
		$first = MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, (int) $task->id );
		$this->assertSame( $site_id . ':' . $task->id . ':l:' . $token, $first->run_key );
		$this->assertNotFalse( MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' ) );
		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( $token, $progress->fetchResult['snapshot_run_token'] );
		$this->assertCount( 1, MainWP_Backup_Snapshots::instance()->get_snapshots_for_site( $site_id ) );
	}

	/**
	 * A transient creation claim is not the child's durable operation identity.
	 */
	public function test_b11_creating_marker_is_not_a_child_run_key() {
		$method = new \ReflectionMethod( MainWP_Backup_Executor::class, 'snapshot_run_key' );
		$method->setAccessible( true );
		$progress = (object) array(
			'child_backup_task_id' => '__creating_b11-token',
			'fetchResult'          => array(),
		);
		$this->assertTrue( MainWP_Backup_Executor::is_creating_marker( $progress->child_backup_task_id ) );
		$this->assertNull( $method->invoke( null, (object) array( 'id' => 22 ), (object) array( 'id' => 11 ), $progress ) );
	}

	/**
	 * A scheduled database backup records its downloaded database archive.
	 */
	public function test_completed_db_run_records_snapshot() {
		list( $site_id, $task ) = $this->site_and_task( array( 'type' => 'db' ) );
		$content                = 'PK' . "\x03\x04" . str_repeat( 'x', 4092 );
		$this->seed_download_ready_progress( $task->id, $site_id, strlen( $content ) );
		MainWP_DB_Backup::instance()->update_backup_task_progress(
			$task->id,
			$site_id,
			array(
				'fetchResult'          => wp_json_encode(
					array(
						'full' => false,
						'db'   => 'backup-db.sql.zip',
						'size' => strlen( $content ),
					)
				),
				'downloadedDB'         => '',
				'downloadedDBComplete' => 0,
			)
		);
		$this->mock_child_action( 'delete_backup', array( 'result' => 'ok' ) );
		add_filter(
			'mainwp_download_to_file_pre',
			function ( $pre, $url, $file ) use ( $content ) {
				$this->snapshot_files[] = $file;
				file_put_contents( $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
				return true;
			},
			10,
			3
		);

		$result   = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
		$snapshot = MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, (int) $task->id );
		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );

		$this->assertNotFalse( $result );
		$this->assertNotNull( $snapshot, 'A completed database run must record a snapshot row.' );
		$this->assertSame( 'db', $snapshot->type );
		$this->assertCount( 1, $snapshot->archives );
		$this->assertSame( 'db', $snapshot->archives[0]['role'] );
		$this->assertSame( strlen( $content ), (int) $snapshot->archives[0]['size'] );
		$this->assertSame( 1, (int) $progress->downloadedDBComplete );
		$this->assertSame( $progress->downloadedDB, $snapshot->archives[0]['path'] );
		$this->assertSame( 0, (int) $progress->downloadedFULLComplete );
		$this->assertFileExists( $snapshot->archives[0]['path'] );
	}

	/**
	 * A database-only archive leaves the child after its validated download,
	 * like a full one; a child that fails the delete does not fail the run.
	 *
	 * @dataProvider data_db_delete_answers
	 *
	 * @param mixed $answer Child answer to delete_backup, or an exception it throws.
	 */
	public function test_completed_db_run_deletes_child_archive( $answer ) {
		list( $site_id, $task ) = $this->site_and_task( array( 'type' => 'db' ) );
		$content                = 'PK' . "\x03\x04" . str_repeat( 'x', 4092 );
		$this->seed_download_ready_progress( $task->id, $site_id, strlen( $content ) );
		MainWP_DB_Backup::instance()->update_backup_task_progress(
			$task->id,
			$site_id,
			array(
				'fetchResult'          => wp_json_encode(
					array(
						'full' => false,
						'db'   => 'backup-db.sql.zip',
						'size' => strlen( $content ),
					)
				),
				'downloadedDB'         => '',
				'downloadedDBComplete' => 0,
			)
		);
		$deletes = array();
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what, $params ) use ( &$deletes, $answer, $task, $site_id ) {
				unset( $website );
				if ( 'delete_backup' !== $what ) {
					return $result;
				}
				$deletes[] = array(
					'del'      => $params['del'] ?? null,
					'complete' => (int) MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id )->downloadedDBComplete,
				);
				if ( $answer instanceof \Throwable ) {
					throw $answer;
				}
				return $answer;
			},
			10,
			4
		);
		add_filter(
			'mainwp_download_to_file_pre',
			function ( $pre, $url, $file ) use ( $content ) {
				$this->snapshot_files[] = $file;
				file_put_contents( $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
				return true;
			},
			10,
			3
		);

		$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );

		$this->assertNotFalse( $result );
		$this->assertSame(
			array(
				array(
					'del'      => 'backup-db.sql.zip',
					'complete' => 1,
				),
			),
			$deletes,
			'One delete, sent after the complete flag is stored.'
		);
		$this->assertNotNull( MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, (int) $task->id ) );
	}

	/**
	 * Child answers to the post-download delete.
	 *
	 * @return array
	 */
	public function data_db_delete_answers() {
		return array(
			'deleted'     => array( array( 'result' => 'ok' ) ),
			'child error' => array( new MainWP_Exception( 'The child site is busy.' ) ),
		);
	}

	/**
	 * A complete flag the re-read row does not confirm leaves the child copy
	 * in place, and the run still records its snapshot and completes. The
	 * snapshot's run key names the child task, which is how retention's
	 * child prune removes the copy later.
	 *
	 * @dataProvider data_unrecorded_download_types
	 *
	 * @param string $type   Task type.
	 * @param string $remote Child archive name.
	 * @param string $flag   Progress column holding the complete flag.
	 * @param string $write  How the flag write goes wrong: dropped, or landing on a row another run reset.
	 */
	public function test_child_archive_kept_when_download_is_unrecorded( $type, $remote, $flag, $write ) {
		list( $site_id, $task ) = $this->site_and_task( array( 'type' => $type ) );
		$content                = 'PK' . "\x03\x04" . str_repeat( 'x', 4092 );
		$child_task_id          = 'unrecorded-' . $site_id;
		$this->seed_download_ready_progress( $task->id, $site_id, strlen( $content ) );
		MainWP_DB_Backup::instance()->update_backup_task_progress(
			$task->id,
			$site_id,
			array(
				'child_backup_task_id' => $child_task_id,
				'fetchResult'          => wp_json_encode(
					array(
						'full' => 'db' === $type ? false : $remote,
						'db'   => 'db' === $type ? $remote : false,
						'size' => strlen( $content ),
					)
				),
			)
		);
		$deletes = array();
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what, $params ) use ( &$deletes ) {
				unset( $website );
				if ( 'delete_backup' !== $what ) {
					return $result;
				}
				$deletes[] = $params['del'] ?? null;
				return array( 'result' => 'ok' );
			},
			10,
			4
		);
		add_filter(
			'mainwp_download_to_file_pre',
			function ( $pre, $url, $file ) use ( $content ) {
				$this->snapshot_files[] = $file;
				file_put_contents( $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
				return true;
			},
			10,
			3
		);
		$break_flag = static function ( $query ) use ( $flag, $write ) {
			if ( ! str_starts_with( $query, 'UPDATE ' ) || ! str_contains( $query, 'mainwp_wp_backup_progress' ) || ! preg_match( "/`{$flag}` = '?1'?/", $query ) ) {
				return $query;
			}
			return 'dropped' === $write ? $query . ' AND 1 = 0' : preg_replace( "/(`{$flag}` = '?1'?)/", '$1, `dtsFetched` = 1', $query );
		};
		add_filter( 'query', $break_flag );
		try {
			$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
		} finally {
			remove_filter( 'query', $break_flag );
		}

		$this->assertNotFalse( $result, 'The run completes on its validated local copy.' );
		$this->assertSame( array(), $deletes, 'The child copy must stay while the row does not show the download.' );
		$snapshot = MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, (int) $task->id );
		$this->assertNotNull( $snapshot, 'The snapshot is recorded from the validated download.' );
		$this->assertSame( $type, $snapshot->type );
		$this->assertSame( $site_id . ':' . $task->id . ':c:' . $child_task_id, $snapshot->run_key );
		$this->assertFileExists( $snapshot->archives[0]['path'] );
	}

	/**
	 * Download slots whose complete flag gates the child delete, and the two
	 * ways the re-read row can fail to confirm it.
	 *
	 * @return array
	 */
	public function data_unrecorded_download_types() {
		return array(
			'database archive, write dropped' => array( 'db', 'backup-db.sql.zip', 'downloadedDBComplete', 'dropped' ),
			'full archive, write dropped'     => array( 'full', 'backup-file.zip', 'downloadedFULLComplete', 'dropped' ),
			'files archive, write dropped'    => array( 'files', 'backup-file.zip', 'downloadedFULLComplete', 'dropped' ),
			'database archive, other run'     => array( 'db', 'backup-db.sql.zip', 'downloadedDBComplete', 'other run' ),
			'full archive, other run'         => array( 'full', 'backup-file.zip', 'downloadedFULLComplete', 'other run' ),
		);
	}

	/**
	 * Isolate record failure from archive download and validation failures.
	 *
	 * @return string Archive bytes.
	 */
	private function mock_snapshot_record_failure() {
		$property = new \ReflectionProperty( MainWP_Backup_Snapshots::class, 'instance' );
		$property->setValue(
			null,
			new class() extends MainWP_Backup_Snapshots {
				/**
				 * Snapshot insert attempts.
				 *
				 * @var int
				 */
				public $record_attempts = 0;

				/**
				 * Fail before a snapshot or its success action can be created.
				 *
				 * @param array $data Snapshot fields.
				 * @return int Failed record id.
				 */
				public function record_snapshot( array $data ): int {
					++$this->record_attempts;
					return 0;
				}
			}
		);

		$content = 'PK' . "\x03\x04" . str_repeat( 'x', 4092 );
		add_filter(
			'mainwp_download_to_file_pre',
			function ( $pre, $url, $file ) use ( $content ) {
				$this->snapshot_files[] = $file;
				file_put_contents( $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
				return true;
			},
			10,
			3
		);
		return $content;
	}

	/**
	 * A full archive must remain available when its snapshot cannot be saved.
	 *
	 * @return void
	 */
	public function test_full_run_snapshot_record_failure_is_loud() {
		list( $site_id, $task ) = $this->site_and_task();
		$content                = $this->mock_snapshot_record_failure();
		$this->seed_download_ready_progress( $task->id, $site_id, strlen( $content ) );
		$this->mock_child_action( 'delete_backup', array( 'result' => 'ok' ) );

		try {
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
			$this->fail( 'Expected snapshot record failure.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_snapshot_record_failed', $e->get_message_error_code() );
			$this->assertSame( 'task id ' . $task->id . ', site id ' . $site_id, $e->get_message_extra() );
		}

		$this->assertSame( 0, did_action( 'mainwp_backup_snapshot_recorded' ) );
		$this->assertNull( MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, (int) $task->id ) );
		$this->assertCount( 1, $this->snapshot_files );
		$this->assertFileExists( $this->snapshot_files[0] );
		$this->assertSame( $content, file_get_contents( $this->snapshot_files[0] ) );
	}

	/**
	 * Incremental completion must not report a zero snapshot id as success.
	 *
	 * @return void
	 */
	public function test_incremental_snapshot_record_failure_is_loud() {
		list( $site_id, $task ) = $this->site_and_task( array( 'type' => 'incremental' ) );
		$content                = $this->mock_snapshot_record_failure();
		$this->give_site_real_privkey( $site_id );
		$this->seed_progress( $task->id, $site_id, array( 'child_backup_task_id' => 'record-failure-' . $site_id ) );
		$this->mock_child_action(
			'backup_task_run',
			array(
				'task_id'   => 'record-failure-' . $site_id,
				'phase'     => 'completed',
				'artifacts' => array(
					array(
						'file' => 'uploads.part001.zip',
						'size' => strlen( $content ),
						'role' => 'part',
					),
				),
			)
		);

		try {
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
			$this->fail( 'Expected snapshot record failure.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_snapshot_record_failed', $e->get_message_error_code() );
			$this->assertSame( 'task id ' . $task->id . ', site id ' . $site_id, $e->get_message_extra() );
		}

		$this->assertSame( 0, did_action( 'mainwp_backup_snapshot_recorded' ) );
		$this->assertNull( MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, (int) $task->id ) );
		$this->assertCount( 1, $this->snapshot_files );
		$this->assertFileExists( $this->snapshot_files[0] );
		$this->assertSame( $content, file_get_contents( $this->snapshot_files[0] ) );
	}

	/**
	 * A known record failure must surface instead of yielding until the record TTL expires.
	 *
	 * @return void
	 */
	public function test_snapshot_record_failure_is_terminal_through_handler() {
		$site_id = $this->create_test_site();
		$user    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$task    = MainWP_DB_Backup::instance()->add_backup_task( $user, 'Snapshot failure task', 'daily', 'full', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task );
		MainWP_DB_Backup::instance()->update_backup_task_with_values( (int) $task->id, array( 'last_run' => time() - 100 ) );
		$task    = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$content = $this->mock_snapshot_record_failure();
		$this->seed_download_ready_progress( $task->id, $site_id, strlen( $content ) );
		MainWP_DB_Backup::instance()->update_backup_task_progress(
			$task->id,
			$site_id,
			array(
				'child_backup_task_id' => 'record-failure-handler',
				'child_phase'          => 'completed',
			)
		);
		$this->mock_child_action( 'delete_backup', array( 'result' => 'ok' ) );
		$marks = array();
		add_action(
			'mainwp_backup_diag_site_mark',
			function ( $task_id, $marked_site_id, $outcome, $detail ) use ( &$marks ) {
				$marks[] = array( $outcome, $detail );
			},
			10,
			4
		);

		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, false );

		$fresh    = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( array( 'error' ), array_column( $marks, 0 ) );
		$this->assertStringContainsString( 'mainwp_backup_snapshot_record_failed', $marks[0][1] );
		$this->assertStringContainsString( MainWP_Backup_Error_Codes::message( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_RECORD_FAILED ), $fresh->backup_errors );
		$this->assertTrue( json_decode( $fresh->completed_sites, true )[ $site_id ] );
		$this->assertSame( '', $progress->child_backup_task_id );
		$this->assertSame( 'failed', $progress->child_phase );
		$this->assertFalse( MainWP_DB_Backup::instance()->is_backup_site_resumable_for_run( $fresh, $site_id ) );
		$this->assertGreaterThanOrEqual( (int) $fresh->last_run, (int) $fresh->completed );
		$this->assertSame( 0, did_action( 'mainwp_backup_snapshot_recorded' ) );
		$this->assertNull( MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, (int) $task->id ) );
		$this->assertCount( 1, $this->snapshot_files );
		$this->assertFileExists( $this->snapshot_files[0] );
	}

	/**
	 * A continuation must not retry a failed insert or append its error again.
	 *
	 * @return void
	 */
	public function test_snapshot_record_failure_is_not_retried_on_second_tick() {
		$site_id = $this->create_test_site();
		$user    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$task    = MainWP_DB_Backup::instance()->add_backup_task( $user, 'Snapshot failure task', 'daily', 'full', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task );
		MainWP_DB_Backup::instance()->update_backup_task_with_values( (int) $task->id, array( 'last_run' => time() - 100 ) );
		$task    = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$content = $this->mock_snapshot_record_failure();
		$this->seed_download_ready_progress( $task->id, $site_id, strlen( $content ) );
		$this->mock_child_action( 'delete_backup', array( 'result' => 'ok' ) );

		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, false );
		$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertSame( 1, MainWP_Backup_Snapshots::instance()->record_attempts );

		MainWP_Manage_Backups_Handler::execute_backup_task( $fresh, 1, false );
		$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertSame( 1, MainWP_Backup_Snapshots::instance()->record_attempts );
		$this->assertSame( 1, substr_count( $fresh->backup_errors, MainWP_Backup_Error_Codes::message( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_RECORD_FAILED ) ) );
		$this->assertGreaterThanOrEqual( (int) $fresh->last_run, (int) $fresh->completed );
		$this->assertFileExists( $this->snapshot_files[0] );
	}

	/**
	 * A later successful run clears the previous run's snapshot record error.
	 *
	 * @return void
	 */
	public function test_clean_run_clears_previous_snapshot_record_error() {
		$site_id = $this->create_test_site();
		$user    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$task    = MainWP_DB_Backup::instance()->add_backup_task( $user, 'Snapshot recovery task', 'daily', 'full', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task );
		MainWP_DB_Backup::instance()->update_backup_task_with_values( (int) $task->id, array( 'last_run' => time() - 100 ) );
		$task    = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$content = $this->mock_snapshot_record_failure();
		$this->seed_download_ready_progress( $task->id, $site_id, strlen( $content ) );
		$this->mock_child_action( 'delete_backup', array( 'result' => 'ok' ) );

		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, false );
		$failed  = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$message = MainWP_Backup_Error_Codes::message( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_RECORD_FAILED );
		$this->assertStringContainsString( $message, $failed->backup_errors );
		$this->assertTrue( json_decode( $failed->completed_sites, true )[ $site_id ] );
		$this->assertSame( 1, MainWP_Backup_Snapshots::instance()->record_attempts );
		$this->assertNull( MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, (int) $task->id ) );

		$property = new \ReflectionProperty( MainWP_Backup_Snapshots::class, 'instance' );
		$property->setValue( null, $this->snapshot_instance );
		// Model a later run without sleeping or pushing its run stamp into the future.
		MainWP_DB_Backup::instance()->update_backup_task_with_values( (int) $task->id, array( 'completed' => time() - 2 ) );
		$this->seed_download_ready_progress( $task->id, $site_id, strlen( $content ) );
		MainWP_DB_Backup::instance()->update_backup_task_progress(
			$task->id,
			$site_id,
			array(
				'dtsFetched'             => time() + 60, // Keep the prepared download fresh across the run-open second boundary.
				'downloadedFULL'         => '',
				'downloadedFULLComplete' => 0,
				'child_phase'            => '',
			)
		);

		MainWP_Manage_Backups_Handler::execute_backup_task( $failed, 1, true );
		$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertGreaterThan( (int) $failed->last_run, (int) $fresh->last_run );
		$this->assertStringNotContainsString( $message, $fresh->backup_errors );
		$this->assertSame( '', $fresh->backup_errors );
		$this->assertTrue( json_decode( $fresh->completed_sites, true )[ $site_id ] );
		$this->assertGreaterThanOrEqual( (int) $fresh->last_run, (int) $fresh->completed );
		$this->assertNotNull( MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, (int) $task->id ) );
		$this->assertSame( 1, did_action( 'mainwp_backup_snapshot_recorded' ) );
	}

	/**
	 * A spine task completing with the child's Woo order-storage facts
	 * threads them into the persisted fetchResult, so the snapshot record
	 * step can stamp them without another child round trip.
	 *
	 * @return void
	 */
	public function test_spine_completion_threads_woo_into_fetch_result() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->give_site_real_privkey( $site_id );
		$this->seed_progress( $task->id, $site_id, array( 'child_backup_task_id' => 'bk-woo-1' ) );

		$this->mock_child_action(
			'backup_task_run',
			array(
				'task_id' => 'bk-woo-1',
				'phase'   => 'completed',
				'file'    => 'backup-woo.tar.gz',
				'size'    => 4096,
				'woo'     => array(
					'has_wc_orders'          => true,
					'woocommerce_db_version' => '9.4.0',
				),
			)
		);

		// The completion projection persists fetchResult before the download
		// branch runs; the download itself fails on the missing file and that
		// is fine - the threading is what this test pins.
		try {
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
		} catch ( \Throwable $e ) {
			unset( $e );
		}

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertIsArray( $progress->fetchResult );
		$this->assertArrayHasKey( 'woo', $progress->fetchResult );
		$this->assertTrue( $progress->fetchResult['woo']['has_wc_orders'] );
		$this->assertSame( '9.4.0', $progress->fetchResult['woo']['woocommerce_db_version'] );
	}

	/**
	 * A completed run whose fetchResult carries the child's Woo facts
	 * records them on the snapshot row, where the restore initiate
	 * handler reads them for the preserve-orders gate.
	 *
	 * @return void
	 */
	public function test_completed_run_snapshot_carries_woo_meta() {
		list( $site_id, $task ) = $this->site_and_task();
		$content                = 'PK' . "\x03\x04" . str_repeat( 'x', 4092 );

		$this->give_site_real_privkey( $site_id );
		MainWP_DB_Backup::instance()->add_backup_task_progress( $task->id, $site_id, array() );
		MainWP_DB_Backup::instance()->update_backup_task_progress(
			$task->id,
			$site_id,
			array(
				'dtsFetched'   => time(),
				'pid'          => 12345,
				'removedFiles' => 1,
				'fetchResult'  => wp_json_encode(
					array(
						'full' => 'backup-file.zip',
						'db'   => false,
						'size' => strlen( $content ),
						'woo'  => array(
							'has_wc_orders'          => true,
							'woocommerce_db_version' => '9.4.0',
						),
					)
				),
			)
		);
		$this->mock_child_action( 'delete_backup', array( 'result' => 'ok' ) );

		add_filter(
			'mainwp_download_to_file_pre',
			function ( $pre, $url, $file ) use ( $content ) {
				unset( $pre, $url );
				file_put_contents( $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
				return true;
			},
			10,
			3
		);

		$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
		$this->assertNotFalse( $result );

		$snapshot = \MainWP\Dashboard\MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, (int) $task->id );
		$this->assertNotNull( $snapshot );
		$this->assertIsArray( $snapshot->woo_meta );
		$this->assertTrue( $snapshot->woo_meta['has_wc_orders'] );
		$this->assertSame( '9.4.0', $snapshot->woo_meta['woocommerce_db_version'] );
	}

	/**
	 * The scheduled-task download sweep must skip archives recorded by
	 * complete backup snapshots: snapshot retention owns their lifecycle.
	 *
	 * @return void
	 */
	public function test_executor_sweep_skips_snapshot_recorded_file() {
		global $wpdb;

		list( $site_id, $task ) = $this->site_and_task();
		$content                = 'PK' . "\x03\x04" . str_repeat( 'x', 4092 );

		// Like seed_download_ready_progress(), but with the sweep still due.
		$this->give_site_real_privkey( $site_id );
		MainWP_DB_Backup::instance()->add_backup_task_progress( $task->id, $site_id, array() );
		MainWP_DB_Backup::instance()->update_backup_task_progress(
			$task->id,
			$site_id,
			array(
				'dtsFetched'   => time(),
				'pid'          => 12345,
				'removedFiles' => 0,
				'fetchResult'  => wp_json_encode(
					array(
						'full' => 'backup-file.zip',
						'db'   => false,
						'size' => strlen( $content ),
					)
				),
			)
		);
		$this->mock_child_action( 'delete_backup', array( 'result' => 'ok' ) );

		add_filter(
			'mainwp_download_to_file_pre',
			function ( $pre, $url, $file ) use ( $content ) {
				unset( $pre, $url );
				file_put_contents( $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
				return true;
			},
			10,
			3
		);

		$dir = \MainWP\Dashboard\MainWP_Backup_Private_Root::site_dir( $site_id );
		wp_mkdir_p( $dir );
		$protected   = $dir . 'protected.zip';
		$unprotected = $dir . 'unprotected.zip';
		file_put_contents( $protected, 'PK' . "\x03\x04" . str_repeat( 'p', 32 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		touch( $protected, time() - 600 );
		file_put_contents( $unprotected, 'PK' . "\x03\x04" . str_repeat( 'u', 32 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		touch( $unprotected, time() - 300 );
		$legacy_dir = \MainWP\Dashboard\MainWP_Backup_Private_Root::legacy_site_dir( $site_id );
		wp_mkdir_p( $legacy_dir );
		$legacy_protected   = $legacy_dir . 'protected.zip';
		$legacy_unprotected = $legacy_dir . 'unprotected.zip';
		file_put_contents( $legacy_protected, 'recorded legacy' );
		file_put_contents( $legacy_unprotected, 'unrecorded legacy' );
		touch( $legacy_unprotected, filemtime( $unprotected ) );

		\MainWP\Dashboard\MainWP_Backup_Snapshots::instance()->record_snapshot(
			array(
				'task_id'  => 990500,
				'site_id'  => $site_id,
				'type'     => 'full',
				'archives' => array(
					array(
						'file' => 'protected.zip',
						'size' => 36,
						'role' => 'full',
						'path' => $protected,
					),
					array(
						'file' => 'protected.zip',
						'size' => 15,
						'role' => 'full',
						'path' => $legacy_protected,
					),
				),
			)
		);

		update_option( 'mainwp_backupsOnServer', 1 );

		try {
			$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
			$this->assertNotFalse( $result );

			$this->assertFileExists( $protected, 'A snapshot-recorded archive must survive the scheduled-task sweep.' );
			$this->assertFileDoesNotExist( $unprotected, 'An unrecorded archive past the retention count must be deleted.' );
			$this->assertFileExists( $legacy_protected );
			$this->assertFileDoesNotExist( $legacy_unprotected );
		} finally {
			wp_delete_file( $legacy_protected );
			wp_delete_file( $legacy_unprotected );
			delete_option( 'mainwp_backupsOnServer' );
			$snapshot_table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$snapshot_table} WHERE site_id = %d", $site_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.
			array_map( 'unlink', glob( $dir . '*' ) ?: array() ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test cleanup.
		}
	}

	/**
	 * The local-file sweep must never delete the current run's own
	 * downloaded artifact. A driver whose progress view predates the
	 * winner's sweep re-ran the sweep late and deleted the freshly
	 * validated archive before its snapshot recorded - the run then
	 * closed with ERR_FILE_CORRUPT and the backup was lost (observed
	 * live on 73: verified download, artifact gone at record time).
	 *
	 * @return void
	 */
	public function test_sweep_never_deletes_current_run_artifact() {
		global $wpdb;

		list( $site_id, $task ) = $this->site_and_task();
		$content                = 'PK' . "\x03\x04" . str_repeat( 'x', 4092 );

		$dir = \MainWP\Dashboard\MainWP_Backup_Private_Root::site_dir( $site_id );
		wp_mkdir_p( $dir );
		$artifact = $dir . 'full-current-run.zip';
		file_put_contents( $artifact, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		$stale = $dir . 'full-old-run.zip';
		file_put_contents( $stale, 'PK' . "\x03\x04" . str_repeat( 'o', 32 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		touch( $stale, time() - 600 );

		// The lagging-driver view persisted: sweep still due while the
		// winner's validated artifact is already flagged complete.
		$this->give_site_real_privkey( $site_id );
		$this->seed_progress(
			$task->id,
			$site_id,
			array(
				'removedFiles'           => 0,
				'fetchResult'            => wp_json_encode(
					array(
						'full' => 'backup-file.zip',
						'db'   => false,
						'size' => strlen( $content ),
					)
				),
				'downloadedFULL'         => $artifact,
				'downloadedFULLComplete' => 1,
			)
		);

		update_option( 'mainwp_backupsOnServer', 1 );

		try {
			$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
			$this->assertNotFalse( $result, 'The run must complete: its artifact is on disk and flagged.' );

			$this->assertFileExists( $artifact, 'The sweep must never delete the current run\'s own artifact.' );
			$this->assertFileDoesNotExist( $stale, 'Old unrecorded archives must still be pruned.' );

			$snapshot = \MainWP\Dashboard\MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, (int) $task->id );
			$this->assertNotNull( $snapshot, 'The snapshot record must land on the surviving artifact.' );
		} finally {
			delete_option( 'mainwp_backupsOnServer' );
			$snapshot_table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$snapshot_table} WHERE site_id = %d", $site_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.
			array_map( 'unlink', glob( $dir . '*' ) ?: array() ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test cleanup.
		}
	}

	/**
	 * Interleaved tasks must retain each other's downloads only while their runs are open.
	 *
	 * @return void
	 */
	public function test_sweep_preserves_other_open_run_downloads() {
		global $wpdb;

		list( $site_id, $task ) = $this->site_and_task();
		$db                     = MainWP_DB_Backup::instance();
		$other                  = $db->add_backup_task( $task->userid, 'Interleaved task', 'daily', 'full', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $other );
		$closed = $db->add_backup_task( $task->userid, 'Closed task', 'daily', 'full', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $closed );
		$previous = $db->add_backup_task( $task->userid, 'Previous run', 'daily', 'full', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $previous );
		$task->id = (int) $previous->id + 1;
		$db->update_backup_task_with_values(
			$previous->id,
			array(
				'last_run'  => 100,
				'completed' => 0,
			)
		);
		$db->update_backup_task_with_values(
			$closed->id,
			array(
				'last_run'  => 100,
				'completed' => 100,
			)
		);
		$db->update_backup_task_with_values(
			$other->id,
			array(
				'last_run'  => 100,
				'completed' => 0,
			)
		);
		$content = 'PK' . "\x03\x04" . str_repeat( 'x', 4092 );

		$dir = \MainWP\Dashboard\MainWP_Backup_Private_Root::site_dir( $site_id );
		wp_mkdir_p( $dir );
		$artifact = $dir . 'full-current-run.zip';
		file_put_contents( $artifact, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		$stale = $dir . 'full-old-run.zip';
		file_put_contents( $stale, 'PK' . "\x03\x04" . str_repeat( 'o', 32 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		touch( $stale, time() - 600 );
		$this->seed_progress( $other->id, $site_id, array( 'downloadedFULL' => $stale ) );
		$closed_artifact = $dir . 'full-closed-run.zip';
		file_put_contents( $closed_artifact, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		$this->seed_progress( $closed->id, $site_id, array( 'downloadedFULL' => $closed_artifact ) );

		$previous_artifact = $dir . 'full-previous-run.zip';
		file_put_contents( $previous_artifact, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		$this->seed_progress(
			$previous->id,
			$site_id,
			array(
				'dtsFetched'     => 99,
				'downloadedFULL' => $previous_artifact,
			)
		);

		$this->give_site_real_privkey( $site_id );
		$this->seed_progress(
			$task->id,
			$site_id,
			array(
				'removedFiles'           => 0,
				'fetchResult'            => wp_json_encode(
					array(
						'full' => 'backup-file.zip',
						'db'   => false,
						'size' => strlen( $content ),
					)
				),
				'downloadedFULL'         => $artifact,
				'downloadedFULLComplete' => 1,
			)
		);

		update_option( 'mainwp_backupsOnServer', 1 );

		try {
			$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
			$this->assertNotFalse( $result, 'The run must complete: its artifact is on disk and flagged.' );

			$this->assertFileExists( $artifact, 'The sweep must never delete the current run\'s own artifact.' );
			$this->assertFileExists( $stale );
			$this->assertFileDoesNotExist( $closed_artifact );
			$this->assertFileDoesNotExist( $previous_artifact );

			$snapshot = \MainWP\Dashboard\MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, (int) $task->id );
			$this->assertNotNull( $snapshot, 'The snapshot record must land on the surviving artifact.' );
		} finally {
			$db->remove_backup_task( $other->id );
			$db->remove_backup_task( $closed->id );
			$db->remove_backup_task( $previous->id );
			delete_option( 'mainwp_backupsOnServer' );
			$snapshot_table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$snapshot_table} WHERE site_id = %d", $site_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.
			array_map( 'unlink', glob( $dir . '*' ) ?: array() ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test cleanup.
		}
	}

	/**
	 * Build the sweep fixture: an interleaved open-run task, the current
	 * run's flagged artifact, and an older archive the sweep would prune.
	 *
	 * @return array Site id, task, sibling task row, artifact path, site dir.
	 */
	private function sweep_fixture_with_open_sibling() {
		list( $site_id, $task ) = $this->site_and_task();
		$db                     = MainWP_DB_Backup::instance();
		$other                  = $db->add_backup_task( $task->userid, 'Interleaved task', 'daily', 'full', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $other );
		$task->id = (int) $other->id + 1;
		$db->update_backup_task_with_values(
			$other->id,
			array(
				'last_run'  => 100,
				'completed' => 0,
			)
		);
		$this->seed_progress( $other->id, $site_id );

		$content = 'PK' . "\x03\x04" . str_repeat( 'x', 4092 );
		$dir     = \MainWP\Dashboard\MainWP_Backup_Private_Root::site_dir( $site_id );
		wp_mkdir_p( $dir );
		$artifact = $dir . 'full-current-run.zip';
		file_put_contents( $artifact, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.

		$this->give_site_real_privkey( $site_id );
		$this->seed_progress(
			$task->id,
			$site_id,
			array(
				'removedFiles'           => 0,
				'fetchResult'            => wp_json_encode(
					array(
						'full' => 'backup-file.zip',
						'db'   => false,
						'size' => strlen( $content ),
					)
				),
				'downloadedFULL'         => $artifact,
				'downloadedFULLComplete' => 1,
			)
		);
		update_option( 'mainwp_backupsOnServer', 1 );

		return array( $site_id, $task, $other, $artifact, $dir );
	}

	/**
	 * Remove what sweep_fixture_with_open_sibling() created.
	 *
	 * @param int    $site_id Site id.
	 * @param object $other   Sibling task row.
	 * @param string $dir     Site backup dir.
	 *
	 * @return void
	 */
	private function tear_down_sweep_fixture( $site_id, $other, $dir ) {
		global $wpdb;
		MainWP_DB_Backup::instance()->remove_backup_task( $other->id );
		delete_option( 'mainwp_backupsOnServer' );
		$snapshot_table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$snapshot_table} WHERE site_id = %d", $site_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.
		array_map( 'unlink', glob( $dir . '*' ) ?: array() ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test cleanup.
	}

	/**
	 * A lane worker records its download path and takes the lock after the
	 * sweep's protection snapshot, so a live .downloading sidecar is the
	 * only sign that its file is in flight.
	 *
	 * @return void
	 */
	public function test_sweep_skips_file_with_live_download_sidecar() {
		list( $site_id, $task, $other, $artifact, $dir ) = $this->sweep_fixture_with_open_sibling();

		$in_flight = $dir . 'full-in-flight.zip';
		file_put_contents( $in_flight, 'PK' . "\x03\x04" . str_repeat( 'i', 32 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		touch( $in_flight, time() - 600 );
		touch( $in_flight . '.downloading' );
		$stale = $dir . 'full-old-run.zip';
		file_put_contents( $stale, 'PK' . "\x03\x04" . str_repeat( 'o', 32 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		touch( $stale, time() - 900 );

		try {
			$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
			$this->assertNotFalse( $result, 'The run must complete: its artifact is on disk and flagged.' );
			$this->assertFileExists( $artifact );
			$this->assertFileExists( $in_flight, 'A file with a live download lock must survive the sweep.' );
			$this->assertFileDoesNotExist( $stale, 'Old unlocked archives must still be pruned.' );
		} finally {
			$this->tear_down_sweep_fixture( $site_id, $other, $dir );
		}
	}

	/**
	 * A download path another open run records while the scan is running
	 * must be protected by the re-read taken right before the deletes.
	 *
	 * @return void
	 */
	public function test_sweep_rereads_open_run_paths_before_deleting() {
		global $wpdb;

		list( $site_id, $task, $other, $artifact, $dir ) = $this->sweep_fixture_with_open_sibling();

		$late = $dir . 'full-late-recorded.zip';
		file_put_contents( $late, 'PK' . "\x03\x04" . str_repeat( 'l', 32 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		touch( $late, time() - 600 );
		$stale = $dir . 'full-old-run.zip';
		file_put_contents( $stale, 'PK' . "\x03\x04" . str_repeat( 'o', 32 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		touch( $stale, time() - 900 );

		// The sibling's download is recorded between the first open-run read
		// (before the scan) and the next one, as a concurrent lane worker would.
		$reads    = 0;
		$progress = MainWP_DB::instance()->get_table_name( 'wp_backup_progress' );
		$record   = function ( $query ) use ( &$reads, $wpdb, $progress, $other, $site_id, $late ) {
			if ( false !== strpos( $query, 'SELECT p.downloadedDB, p.downloadedFULL' ) && 2 === ++$reads ) {
				$wpdb->update(
					$progress,
					array( 'downloadedFULL' => $late ),
					array(
						'task_id' => (int) $other->id,
						'wp_id'   => $site_id,
					)
				); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test simulates a concurrent writer.
			}
			return $query;
		};
		add_filter( 'query', $record );

		try {
			$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
			$this->assertNotFalse( $result, 'The run must complete: its artifact is on disk and flagged.' );
			$this->assertFileExists( $artifact );
			$this->assertFileExists( $late, 'A download recorded after the first snapshot must survive the sweep.' );
			$this->assertGreaterThanOrEqual( 2, $reads, 'The late record must come from the re-read, not the first snapshot.' );
			$this->assertFileDoesNotExist( $stale, 'Old unrecorded archives must still be pruned.' );
		} finally {
			remove_filter( 'query', $record );
			$this->tear_down_sweep_fixture( $site_id, $other, $dir );
		}
	}

	/**
	 * A download the re-read protects keeps no retention slot, so the
	 * sweep still keeps the configured number of older archives.
	 *
	 * @return void
	 */
	public function test_sweep_reread_protection_keeps_no_retention_slot() {
		global $wpdb;

		list( $site_id, $task, $other, $artifact, $dir ) = $this->sweep_fixture_with_open_sibling();
		update_option( 'mainwp_backupsOnServer', 2 );

		$late = $dir . 'full-late-recorded.zip';
		file_put_contents( $late, 'PK' . "\x03\x04" . str_repeat( 'l', 32 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		touch( $late, time() - 300 );
		$kept = $dir . 'full-kept-run.zip';
		file_put_contents( $kept, 'PK' . "\x03\x04" . str_repeat( 'k', 32 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		touch( $kept, time() - 600 );
		$stale = $dir . 'full-old-run.zip';
		file_put_contents( $stale, 'PK' . "\x03\x04" . str_repeat( 'o', 32 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		touch( $stale, time() - 900 );

		$reads    = 0;
		$progress = MainWP_DB::instance()->get_table_name( 'wp_backup_progress' );
		$record   = function ( $query ) use ( &$reads, $wpdb, $progress, $other, $site_id, $late ) {
			if ( false !== strpos( $query, 'SELECT p.downloadedDB, p.downloadedFULL' ) && 2 === ++$reads ) {
				$wpdb->update(
					$progress,
					array( 'downloadedFULL' => $late ),
					array(
						'task_id' => (int) $other->id,
						'wp_id'   => $site_id,
					)
				); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test simulates a concurrent writer.
			}
			return $query;
		};
		add_filter( 'query', $record );

		try {
			$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
			$this->assertNotFalse( $result );
			$this->assertFileExists( $late );
			$this->assertFileExists( $kept, 'The re-read protected file must not take the one older archive slot.' );
			$this->assertFileDoesNotExist( $stale );
		} finally {
			remove_filter( 'query', $record );
			$this->tear_down_sweep_fixture( $site_id, $other, $dir );
		}
	}

	/**
	 * A task deleted while its sweep scans leaves no progress row for the
	 * re-read, so the run must stop instead of reading a null row.
	 *
	 * @return void
	 */
	public function test_sweep_stops_when_progress_row_is_deleted_mid_sweep() {
		global $wpdb;

		list( $site_id, $task, $other, $artifact, $dir ) = $this->sweep_fixture_with_open_sibling();

		// The snapshot read after the scan is the re-read's first query, so the
		// row vanishes right before the re-read fetches it.
		$reads    = 0;
		$deleted  = false;
		$progress = MainWP_DB::instance()->get_table_name( 'wp_backup_progress' );
		$delete   = function ( $query ) use ( &$reads, &$deleted, $wpdb, $progress, $task, $site_id ) {
			if ( false !== strpos( $query, 'SELECT p.downloadedDB, p.downloadedFULL' ) ) {
				++$reads;
			}
			if ( 1 === $reads && ! $deleted && false !== strpos( $query, 'SELECT archives, manifest_path' ) ) {
				$deleted = true;
				$wpdb->delete(
					$progress,
					array(
						'task_id' => (int) $task->id,
						'wp_id'   => $site_id,
					)
				); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test simulates a concurrent task delete.
			}
			return $query;
		};
		add_filter( 'query', $delete );

		try {
			$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
			$this->assertFalse( $result, 'A run whose progress row vanished mid-sweep must stop.' );
			$this->assertTrue( $deleted, 'The row must vanish before the re-read.' );
			$this->assertFileExists( $artifact );
		} finally {
			remove_filter( 'query', $delete );
			$this->tear_down_sweep_fixture( $site_id, $other, $dir );
		}
	}

	/**
	 * A sibling that records its snapshot and closes its run while the scan
	 * runs leaves the open-run set; the re-read must see the snapshot.
	 *
	 * @return void
	 */
	public function test_sweep_rereads_snapshot_protection_before_deleting() {
		list( $site_id, $task, $other, $artifact, $dir ) = $this->sweep_fixture_with_open_sibling();

		$late = $dir . 'full-late-snapshot.zip';
		file_put_contents( $late, 'PK' . "\x03\x04" . str_repeat( 'l', 32 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		touch( $late, time() - 600 );
		$stale = $dir . 'full-old-run.zip';
		file_put_contents( $stale, 'PK' . "\x03\x04" . str_repeat( 'o', 32 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		touch( $stale, time() - 900 );

		// The first open-run read follows the first snapshot read, so a record
		// made there is invisible to the initial protection set.
		$reads  = 0;
		$record = function ( $query ) use ( &$reads, $other, $site_id, $late ) {
			if ( false !== strpos( $query, 'SELECT p.downloadedDB, p.downloadedFULL' ) && 1 === ++$reads ) {
				\MainWP\Dashboard\MainWP_Backup_Snapshots::instance()->record_snapshot(
					array(
						'task_id'  => (int) $other->id,
						'site_id'  => $site_id,
						'type'     => 'full',
						'archives' => array(
							array(
								'file' => 'full-late-snapshot.zip',
								'size' => 36,
								'role' => 'full',
								'path' => $late,
							),
						),
					)
				);
			}
			return $query;
		};
		add_filter( 'query', $record );

		try {
			$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
			$this->assertNotFalse( $result );
			$this->assertGreaterThanOrEqual( 2, $reads, 'The snapshot must be recorded after the first protection read.' );
			$this->assertFileExists( $late, 'An archive a snapshot recorded during the scan must survive the sweep.' );
			$this->assertFileDoesNotExist( $stale );
		} finally {
			remove_filter( 'query', $record );
			$this->tear_down_sweep_fixture( $site_id, $other, $dir );
		}
	}

	/**
	 * A stale download sidecar in the legacy dir is cleared by the sweep,
	 * so it cannot shield its archive from retention.
	 *
	 * @return void
	 */
	public function test_sweep_clears_stale_legacy_sidecar() {
		list( $site_id, $task, $other, $artifact, $dir ) = $this->sweep_fixture_with_open_sibling();

		$legacy_dir = \MainWP\Dashboard\MainWP_Backup_Private_Root::legacy_site_dir( $site_id );
		wp_mkdir_p( $legacy_dir );
		$orphan = $legacy_dir . 'full-crashed-download.zip';
		file_put_contents( $orphan, 'PK' . "\x03\x04" . str_repeat( 'c', 32 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		touch( $orphan, time() - 900 );
		file_put_contents( $orphan . '.downloading', wp_json_encode( array( 'started' => time() - 2 * HOUR_IN_SECONDS ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.

		try {
			$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
			$this->assertNotFalse( $result );
			$this->assertFileExists( $artifact );
			$this->assertFileDoesNotExist( $orphan . '.downloading', 'The stale legacy sidecar must be cleared.' );
			$this->assertFileDoesNotExist( $orphan, 'An archive behind a stale sidecar must still be pruned.' );
		} finally {
			// Fixture teardown first: a warning from rmdir() must not skip it.
			$this->tear_down_sweep_fixture( $site_id, $other, $dir );
			array_map( 'unlink', glob( $legacy_dir . '*' ) ?: array() ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test cleanup.
			rmdir( $legacy_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test cleanup.
		}
	}

	/**
	 * The sweep gate is claimed SQL-side: exactly one driver of a run can
	 * win it, so a driver holding a stale in-memory removedFiles view can
	 * never re-run the sweep after the winner.
	 *
	 * @return void
	 */
	public function test_claim_backup_file_sweep_is_single_winner() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->seed_progress( $task->id, $site_id, array( 'removedFiles' => 0 ) );

		$this->assertTrue( MainWP_DB_Backup::instance()->claim_backup_file_sweep( $task->id, $site_id ), 'The first claim must win.' );
		$this->assertFalse( MainWP_DB_Backup::instance()->claim_backup_file_sweep( $task->id, $site_id ), 'A second claim must lose: the sweep already ran.' );

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( 1, (int) $progress->removedFiles );
	}

	/**
	 * A downloaded file that fails validation is never marked complete:
	 * each rejection consumes a retry attempt and yields, and once the
	 * budget is spent the validation failure surfaces terminally.
	 *
	 * @return void
	 */
	public function test_invalid_downloaded_file_not_marked_complete() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->seed_download_ready_progress( $task->id, $site_id, strlen( '<html><body>Fatal error on child site</body></html>' ) );
		$this->mock_child_action( 'delete_backup', array( 'result' => 'ok' ) );

		add_filter(
			'mainwp_download_to_file_pre',
			function ( $pre, $url, $file ) {
				unset( $pre, $url );
				file_put_contents( $file, '<html><body>Fatal error on child site</body></html>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
				return true;
			},
			10,
			3
		);

		$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
		$this->assertFalse( $result, 'A rejected download must yield for retry while budget remains.' );

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( 1, (int) $progress->attempts, 'The rejected download must consume a retry attempt.' );
		$this->assertSame( 0, (int) $progress->downloadedFULLComplete, 'A failed validation must not mark the download complete.' );

		MainWP_DB_Backup::instance()->update_backup_task_progress( $task->id, $site_id, array( 'attempts' => 5 ) );
		try {
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
			$this->fail( 'Expected header validation to reject the error-page download once the retry budget is spent.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_header_invalid', $e->get_message_error_code() );
		}

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( 0, (int) $progress->downloadedFULLComplete, 'A failed validation must not mark the download complete.' );
	}

	/**
	 * A child that advertises download tokens gets the executor download
	 * phase routed through the token flow: the token callable is consulted
	 * and the download URL carries the token.
	 *
	 * @return void
	 */
	public function test_download_phase_requests_token_when_child_supports_it() {
		list( $site_id, $task ) = $this->site_and_task();
		$content                = 'PK' . "\x03\x04" . str_repeat( 'x', 4092 );
		$this->seed_download_ready_progress( $task->id, $site_id, strlen( $content ) );
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		MainWP_DB::instance()->update_website_option( $website, 'download_token_support', '1' );

		$token_calls = 0;
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what ) use ( &$token_calls, $content ) {
				unset( $website );
				if ( 'backup_download_token' === $what ) {
					++$token_calls;
					return array(
						'token' => str_repeat( 'g', 32 ),
						'hash'  => hash( 'sha256', $content ),
						'size'  => strlen( $content ),
					);
				}
				if ( 'delete_backup' === $what ) {
					return array( 'result' => 'ok' );
				}
				return $result;
			},
			10,
			3
		);

		$urls = array();
		add_filter(
			'mainwp_download_to_file_pre',
			function ( $pre, $url, $file ) use ( &$urls, $content ) {
				unset( $pre );
				$urls[] = $url;
				file_put_contents( $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
				return true;
			},
			10,
			3
		);

		MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );

		$this->assertSame( 1, $token_calls, 'The download phase must request a token from a capable child.' );
		$this->assertCount( 1, $urls );
		$this->assertStringContainsString( 'dltoken=' . str_repeat( 'g', 32 ), $urls[0] );

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( 1, (int) $progress->downloadedFULLComplete );
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
	 * A child that advertises the resumable task machine gets a task
	 * created and started on the first call; continuation handles later slices.
	 *
	 * @return void
	 */
	public function test_backup_site_on_spine_capable_site_creates_task_normally() {
		$advanced               = false;
		list( $site_id, $task ) = $this->site_and_task();
		$this->mock_child_action( 'backup_preflight', array( 'task_spine' => 1 ) );
		$this->mock_child_action(
			'backup_task_create',
			array(
				'task_id' => 'task-1749600000-abcdef',
				'phase'   => 'queued',
			)
		);

		$this->mock_child_action( 'backup_task_run', array( 'phase' => 'dumping_db' ) );

		$called = array();
		$this->record_child_actions( $called );

		$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups', $advanced, true );

		$this->assertFalse( $result, 'A running first slice leaves the task open for continuation.' );
		$this->assertSame( array( 'backup_preflight', 'backup_task_create', 'backup_task_run' ), $called, 'Run the first slice before returning to the fleet.' );
		$this->assertNotContains( 'backup', $called, 'The legacy backup action must not fire on the resumable path.' );

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( 'task-1749600000-abcdef', $progress->child_backup_task_id );
	}

	/**
	 * Each fleet member starts before the next child task is queued.
	 */
	public function test_fleet_starts_each_created_task_before_creating_the_next() {
		$this->mock_child_action( 'backup_preflight', array( 'task_spine' => 1 ) );
		$sites   = array( $this->create_test_site(), $this->create_test_site(), $this->create_test_site() );
		$website = MainWP_DB::instance()->get_website_by_id( $sites[0] );
		$task    = MainWP_DB_Backup::instance()->add_backup_task( $website->userid, 'First slices', 'daily', 'full', '', implode( ',', $sites ), '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task );
		$calls = array();
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what, $params ) use ( &$calls, $task ) {
				if ( 'backup_task_create' === $what ) {
					$calls[] = 'create-' . $website->id;
					return array(
						'task_id' => 'task-fleet-' . $website->id,
						'phase'   => 'queued',
					);
				}
				if ( 'backup_task_run' === $what ) {
					$this->assertNotFalse( get_option( MainWP_Backup_Monitor::LANE_LEASE_OPTION, false ), 'The starter must exclude continuation before running the persisted handle.' );
					$calls[] = 'run-' . $website->id;
					$row     = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $website->id );
					$this->assertSame( $row->child_backup_task_id, $params['task_id'], 'Persist the handle before running it.' );
					return array( 'phase' => 'dumping_db' );
				}
				return $result;
			},
			20,
			4
		);
		$expected = array();
		foreach ( $sites as $site_id ) {
			$expected[] = 'create-' . $site_id;
			$expected[] = 'run-' . $site_id;
		}
		MainWP_Manage_Backups_Handler::execute_backup_task( $task );
		$this->assertSame( $expected, $calls );
		global $wpdb;
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", MainWP_Backup_Monitor::LANE_LEASE_OPTION ) ), 'The starter releases its lane.' );
	}

	/**
	 * A competing lane prevents a starter from claiming or contacting a child.
	 */
	public function test_fleet_start_yields_to_a_live_continuation_lane() {
		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$task    = MainWP_DB_Backup::instance()->add_backup_task( $website->userid, 'Lane fixture', 'daily', 'full', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task );
		$calls = array();
		$this->record_child_actions( $calls );
		$holder = wp_json_encode(
			array(
				'token'     => 'other-worker',
				'heartbeat' => time(),
				'lease'     => 600,
			)
		);
		add_option( MainWP_Backup_Monitor::LANE_LEASE_OPTION, $holder );
		try {
			$this->assertFalse( MainWP_Manage_Backups_Handler::execute_backup_task( $task ) );
			$this->assertSame( array(), $calls );
			$this->assertSame( $holder, get_option( MainWP_Backup_Monitor::LANE_LEASE_OPTION ) );
			$this->assertSame( $task->last_run, MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id )->last_run );
		} finally {
			delete_option( MainWP_Backup_Monitor::LANE_LEASE_OPTION );
		}
	}

	/**
	 * An outer continuation scope survives a nested task driver.
	 */
	public function test_task_driver_preserves_the_callers_lane_scope() {
		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		$task    = MainWP_DB_Backup::instance()->add_backup_task( $website->userid, 'Lane fixture', 'daily', 'full', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task );
		$this->mock_child_action( 'backup_preflight', array( 'task_spine' => 1 ) );
		$this->mock_child_action(
			'backup_task_create',
			array(
				'task_id' => 'nested-task',
				'phase'   => 'queued',
			)
		);
		$this->assertIsString( MainWP_Backup_Monitor::acquire_lane_lease() );
		try {
			MainWP_Manage_Backups_Handler::execute_backup_task( $task );
			$this->assertTrue( MainWP_Backup_Monitor::has_lane_lease_scope() );
			$this->assertTrue( MainWP_Backup_Monitor::confirm_lane_lease() );
		} finally {
			MainWP_Backup_Monitor::release_lane_lease();
		}
	}

	/**
	 * A response arriving after lane loss cannot advance persisted progress.
	 */
	public function test_first_slice_response_is_fenced_after_lane_loss() {
		$advanced               = false;
		list( $site_id, $task ) = $this->site_and_task();
		$this->mock_child_action( 'backup_preflight', array( 'task_spine' => 1 ) );
		$this->mock_child_action(
			'backup_task_create',
			array(
				'task_id' => 'lost-response',
				'phase'   => 'queued',
			)
		);
		$this->mock_child_action( 'backup_task_run', array( 'phase' => 'dumping_db' ) );
		$this->assertIsString( MainWP_Backup_Monitor::acquire_lane_lease() );
		$before = null;
		$lose   = static function ( $payload, $duration, $context ) use ( &$before, $task, $site_id ) {
			if ( 'run' === $context['op'] ) {
				$before = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
				delete_option( MainWP_Backup_Monitor::LANE_LEASE_OPTION );
			}
		};
		add_action( 'mainwp_backup_diag_poll', $lose, 10, 3 );
		try {
			$this->assertFalse( MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups', $advanced, true ) );
			$this->assertNotNull( $before );
			$this->assertEquals( $before, MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id ) );
		} finally {
			remove_action( 'mainwp_backup_diag_poll', $lose, 10 );
			MainWP_Backup_Monitor::release_lane_lease();
		}
	}

	/**
	 * A diagnostic listener cannot replace a create failure or strand its marker.
	 */
	public function test_thrown_create_clears_marker_when_diag_listener_throws() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->mock_child_action( 'backup_preflight', array( 'task_spine' => 1 ) );
		$failure = new MainWP_Exception( 'Create request failed.', 'HTTP 504', 'mainwp_backup_http_error' );
		$throw   = static function ( $result, $website, $what ) use ( $failure ) {
			if ( 'backup_task_create' === $what ) {
				throw $failure;
			}
			return $result;
		};
		$creates = array();
		$listen  = static function ( $payload, $duration, $context ) use ( &$creates ) {
			if ( 'create' === $context['op'] ) {
				$creates[] = array( $payload, $duration, $context );
				throw new \Error( 'Diagnostic listener failed.' );
			}
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $throw, 20, 3 );
		add_action( 'mainwp_backup_diag_poll', $listen, 10, 3 );
		try {
			try {
				MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
				$this->fail( 'The original create failure must propagate.' );
			} catch ( MainWP_Exception $e ) {
				$this->assertSame( $failure, $e );
			}
			$this->assertCount( 1, $creates );
			list( $payload, $duration, $context ) = $creates[0];
			$this->assertSame( 'create', $context['op'] );
			$this->assertTrue( $payload['thrown'] );
			$this->assertTrue( $payload['sent'] );
			$this->assertSame( 'mainwp_backup_http_error', $payload['error_code'] );
			$this->assertSame( 0, $payload['code'] );
			$this->assertSame( 'HTTP 504', $payload['extra'] );
			$this->assertGreaterThanOrEqual( 0, $duration );
			$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
			$this->assertSame( '', $progress->child_backup_task_id );
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $throw, 20 );
			remove_action( 'mainwp_backup_diag_poll', $listen, 10 );
		}
	}

	/**
	 * A readiness refusal must not look like a create request reached the Child.
	 */
	public function test_preflight_refusal_records_create_as_not_sent() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->mock_child_action(
			'backup_preflight',
			array(
				'error'      => 'Uploads directory is not writable.',
				'error_code' => '',
			)
		);
		$requests = array();
		$capture  = static function ( $result, $website, $what ) use ( &$requests ) {
			$requests[] = $what;
			return $result;
		};
		$creates  = array();
		$listen   = static function ( $payload, $duration, $context ) use ( &$creates ) {
			if ( 'create' === $context['op'] ) {
				$creates[] = $payload;
			}
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $capture, 20, 3 );
		add_action( 'mainwp_backup_diag_poll', $listen, 10, 3 );
		try {
			try {
				MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
				$this->fail( 'Readiness refusal must propagate.' );
			} catch ( MainWP_Exception $e ) {
				$this->assertSame( MainWP_Backup_Error_Codes::ERR_CHILD_PREFLIGHT_FAILED, $e->get_message_error_code() );
			}
			$this->assertNotContains( 'backup_task_create', $requests );
			$this->assertCount( 1, $creates );
			$this->assertFalse( $creates[0]['sent'] );
			$this->assertSame( MainWP_Backup_Error_Codes::ERR_CHILD_PREFLIGHT_FAILED, $creates[0]['error_code'] );
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $capture, 20 );
			remove_action( 'mainwp_backup_diag_poll', $listen, 10 );
		}
	}

	/**
	 * A run request that throws still leaves a diag line marked as thrown.
	 */
	public function test_thrown_run_request_is_recorded_on_the_diag_seam() {
		$advanced               = false;
		list( $site_id, $task ) = $this->site_and_task();
		$this->mock_child_action( 'backup_preflight', array( 'task_spine' => 1 ) );
		$this->mock_child_action(
			'backup_task_create',
			array(
				'task_id' => 'thrown-run',
				'phase'   => 'queued',
			)
		);
		$throw = static function ( $result, $website, $what ) {
			unset( $website );
			if ( 'backup_task_run' === $what ) {
				throw new MainWP_Exception( 'HTTPERROR', 'cURL error 52: Empty reply from server' );
			}
			return $result;
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $throw, 20, 3 );
		$runs   = array();
		$listen = static function ( $payload, $duration, $context ) use ( &$runs ) {
			if ( 'run' === $context['op'] ) {
				$runs[] = array( $payload, $duration, $context );
			}
		};
		add_action( 'mainwp_backup_diag_poll', $listen, 10, 3 );
		$this->assertIsString( MainWP_Backup_Monitor::acquire_lane_lease() );
		try {
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups', $advanced, true );
			$this->assertCount( 1, $runs );
			list( $payload, $duration, $context ) = $runs[0];
			$this->assertArrayHasKey( 'thrown', $payload, 'A run payload without the thrown key means the request did not throw.' );
			$this->assertTrue( $payload['thrown'] );
			$this->assertSame( 'HTTPERROR', $payload['message'] );
			$this->assertSame( 'cURL error 52: Empty reply from server', $payload['extra'] );
			$this->assertTrue( $payload['sent'] );
			$this->assertSame( 'thrown-run', $payload['task_id'] );
			$this->assertSame( '', $payload['error_code'] );
			$this->assertIsFloat( $duration );
			$this->assertGreaterThanOrEqual( 0, $duration );
			$this->assertSame( (int) $task->id, $context['task_id'] );
			$this->assertSame( (int) $site_id, $context['site_id'] );
		} finally {
			remove_action( 'mainwp_backup_diag_poll', $listen, 10 );
			remove_filter( 'mainwp_fetch_url_authed_pre', $throw, 20 );
			MainWP_Backup_Monitor::release_lane_lease();
		}
	}

	/**
	 * A failed status read needs diagnostic evidence without changing the caller's exception.
	 */
	public function test_thrown_status_read_is_recorded_and_rethrown() {
		$site_id = $this->create_test_site();
		$failure = new MainWP_Exception( 'HTTPERROR', 'cURL error 52: Empty reply from server' );
		$throw   = static function ( $result, $website, $what ) use ( $failure ) {
			if ( 'backup_task_status' === $what ) {
				throw $failure;
			}
			return $result;
		};
		$polls   = array();
		$listen  = static function ( $payload, $duration, $context ) use ( &$polls ) {
			$polls[] = array( $payload, $duration, $context );
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $throw, 20, 3 );
		add_action( 'mainwp_backup_diag_poll', $listen, 10, 3 );
		try {
			try {
				MainWP_Backup_Executor::get_backup_task_status( $site_id, 'status-handle' );
				$this->fail( 'The original status failure must propagate.' );
			} catch ( MainWP_Exception $e ) {
				$this->assertSame( $failure, $e );
			}
			$this->assertCount( 1, $polls );
			list( $payload, $duration, $context ) = $polls[0];
			$this->assertSame( 'status', $context['op'] );
			$this->assertSame( (int) $site_id, $context['site_id'] );
			$this->assertSame( 0, $context['task_id'] );
			$this->assertTrue( $payload['thrown'] );
			$this->assertTrue( $payload['sent'] );
			$this->assertSame( 'status-handle', $payload['task_id'] );
			$this->assertSame( 'HTTPERROR', $payload['message'] );
			$this->assertSame( 'cURL error 52: Empty reply from server', $payload['extra'] );
			$this->assertGreaterThanOrEqual( 0, $duration );
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $throw, 20 );
			remove_action( 'mainwp_backup_diag_poll', $listen, 10 );
		}
	}

	/**
	 * A budgeted run failure must survive a throwing diagnostic listener.
	 */
	public function test_thrown_budgeted_run_survives_diag_listener_failure() {
		$site_id = $this->create_test_site();
		$failure = new MainWP_Exception( 'HTTPERROR', 'cURL error 52: Empty reply from server' );
		$throw   = static function ( $result, $website, $what ) use ( $failure ) {
			if ( 'backup_task_run' === $what ) {
				throw $failure;
			}
			return $result;
		};
		$polls   = array();
		$listen  = static function ( $payload, $duration, $context ) use ( &$polls ) {
			$polls[] = array( $payload, $duration, $context );
			throw new \Error( 'Diagnostic listener failed.' );
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $throw, 20, 3 );
		add_action( 'mainwp_backup_diag_poll', $listen, 10, 3 );
		try {
			try {
				MainWP_Backup_Executor::get_backup_task_status( $site_id, 'run-handle', 1 );
				$this->fail( 'The original run failure must propagate.' );
			} catch ( MainWP_Exception $e ) {
				$this->assertSame( $failure, $e );
			}
			$this->assertCount( 1, $polls );
			list( $payload, $duration, $context ) = $polls[0];
			$this->assertSame( 'run', $context['op'] );
			$this->assertSame( (int) $site_id, $context['site_id'] );
			$this->assertSame( 0, $context['task_id'] );
			$this->assertTrue( $payload['thrown'] );
			$this->assertTrue( $payload['sent'] );
			$this->assertSame( 'run-handle', $payload['task_id'] );
			$this->assertSame( 'HTTPERROR', $payload['message'] );
			$this->assertSame( 'cURL error 52: Empty reply from server', $payload['extra'] );
			$this->assertGreaterThanOrEqual( 0, $duration );
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $throw, 20 );
			remove_action( 'mainwp_backup_diag_poll', $listen, 10 );
		}
	}

	/**
	 * A lane lost during creation cannot start the returned child task.
	 */
	public function test_first_slice_waits_when_creation_loses_the_lane() {
		$advanced               = false;
		list( $site_id, $task ) = $this->site_and_task();
		$this->mock_child_action( 'backup_preflight', array( 'task_spine' => 1 ) );
		$this->mock_child_action(
			'backup_task_create',
			array(
				'task_id' => 'task-lost-lane',
				'phase'   => 'queued',
			)
		);
		$calls = array();
		$this->record_child_actions( $calls );
		$this->assertIsString( MainWP_Backup_Monitor::acquire_lane_lease() );
		$lose = static function ( $payload, $duration, $context ) {
			if ( 'create' === $context['op'] ) {
				delete_option( MainWP_Backup_Monitor::LANE_LEASE_OPTION );
			}
		};
		add_action( 'mainwp_backup_diag_poll', $lose, 10, 3 );
		try {
			$this->assertFalse( MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups', $advanced, true ) );
			$this->assertNotContains( 'backup_task_run', $calls );
			$row = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
			$this->assertSame( 'task-lost-lane', $row->child_backup_task_id, 'Keep the handle for a later owner.' );
		} finally {
			remove_action( 'mainwp_backup_diag_poll', $lose, 10 );
			MainWP_Backup_Monitor::release_lane_lease();
			delete_option( MainWP_Backup_Monitor::LANE_LEASE_OPTION );
		}
	}

	/**
	 * A failed handle write must not run a creating marker on the child.
	 */
	public function test_first_slice_waits_until_the_child_handle_is_persisted() {
		$advanced               = false;
		list( $site_id, $task ) = $this->site_and_task();
		$this->mock_child_action( 'backup_preflight', array( 'task_spine' => 1 ) );
		$this->mock_child_action(
			'backup_task_create',
			array(
				'task_id' => 'task-failed-handle-write',
				'phase'   => 'queued',
			)
		);
		$calls = array();
		$this->record_child_actions( $calls );
		$skip = static function ( $query ) {
			return str_starts_with( $query, 'UPDATE ' ) && str_contains( $query, 'mainwp_wp_backup_progress' ) && str_contains( $query, 'task-failed-handle-write' ) ? $query . ' AND 1 = 0' : $query;
		};
		add_filter( 'query', $skip );
		try {
			$this->assertFalse( MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups', $advanced, true ) );
			$this->assertNotContains( 'backup_task_run', $calls );
			$pending = MainWP_DB::instance()->get_website_option( $site_id, MainWP_Manage_Backups_Handler::PENDING_CANCEL_OPTION, null, true );
			$this->assertArrayHasKey( 'task-failed-handle-write', $pending, 'Retain cancellation ownership when the handle cannot be saved.' );
		} finally {
			remove_filter( 'query', $skip );
		}
	}

	/**
	 * A late create error cannot erase a replacement driver's task.
	 */
	public function test_first_slice_preserves_a_replacement_after_create_error() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->mock_child_action( 'backup_preflight', array( 'task_spine' => 1 ) );
		$replace = static function ( $result, $website, $what ) use ( $task ) {
			if ( 'backup_task_create' === $what ) {
				MainWP_DB_Backup::instance()->update_backup_task_progress(
					$task->id,
					$website->id,
					array(
						'child_backup_task_id' => 'task-replacement',
						'fetchResult'          => '{"replacement":true}',
					)
				);
				return array( 'error' => 'Create failed.' );
			}
			return $result;
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $replace, 20, 3 );
		try {
			$advanced = false;
			$this->assertFalse( MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups', $advanced, true ) );
			$row = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
			$this->assertSame( 'task-replacement', $row->child_backup_task_id );
			$this->assertSame( array( 'replacement' => true ), $row->fetchResult );
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $replace, 20 );
		}
	}

	/**
	 * The task-spine gate reads the capability written by this cycle's
	 * preflight, rather than refusing a current child whose stored option was
	 * previously unset.
	 *
	 * @return void
	 */
	public function test_backup_site_uses_task_spine_flag_refreshed_by_preflight() {
		list( $site_id, $task ) = $this->site_and_task();
		$website                = MainWP_DB::instance()->get_website_by_id( $site_id );

		$this->assertEmpty( MainWP_DB::instance()->get_website_option( $website, 'task_spine_support' ), 'Precondition: the capability option is unset before the first backup.' );

		$this->mock_child_action( 'backup_preflight', array( 'task_spine' => 1 ) );
		$this->mock_child_action(
			'backup_task_create',
			array(
				'task_id' => 'task-1749600000-refreshed',
				'phase'   => 'queued',
			)
		);

		$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );

		$this->assertFalse( $result, 'A capability learned during preflight must allow task creation in the same cycle.' );
		$this->assertSame( '1', (string) MainWP_DB::instance()->get_website_option( $website, 'task_spine_support' ) );
		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( 'task-1749600000-refreshed', $progress->child_backup_task_id );
	}

	/**
	 * A child without the task machine is refused with a coded update error
	 * and never reaches the legacy backup action.
	 *
	 * @return void
	 */
	public function test_no_spine_support_throws_child_update_required_without_legacy_backup() {
		list( $site_id, $task ) = $this->site_and_task();
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
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
			$this->fail( 'Expected a child-update-required exception.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error_Codes::ERR_CHILD_UPDATE_REQUIRED, $e->get_message_error_code() );
		}

		$this->assertNotContains( 'backup', $called, 'The scheduled flow must not invoke the legacy backup engine.' );
		$this->assertNotContains( 'backup_task_create', $called );
		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( '', $progress->child_backup_task_id, 'The existing catch must clear this driver\'s create marker before rethrowing the coded exception.' );
	}

	/**
	 * A fresh run resets any stale child task id so a new run never drives
	 * a previous run's task.
	 *
	 * @return void
	 */
	public function test_fresh_run_resets_stale_child_task_id() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->seed_progress( $task->id, $site_id, array( 'child_backup_task_id' => 'task-old' ) );
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}mainwp_wp_backup_progress SET dtsFetched = %d WHERE task_id = %d", $task->last_run - 50, $task->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test fixture.

		$this->mock_child_action( 'backup_preflight', array( 'task_spine' => 1 ) );
		$this->mock_child_action( 'backup_task_create', array( 'error' => 'Could not write backup data.' ) );

		try {
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
		} catch ( MainWP_Exception $e ) {
			unset( $e );
		}

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( '', $progress->child_backup_task_id );
	}

	/**
	 * An in-flight run with a child task id advances the task and defers while
	 * the task is still working.
	 *
	 * @return void
	 */
	public function test_spine_in_flight_runs_task_and_defers() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->seed_progress( $task->id, $site_id, array( 'child_backup_task_id' => 'task-abc' ) );

		$run_params = null;
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what, $params ) use ( &$run_params ) {
				unset( $website );
				if ( 'backup_task_run' === $what ) {
					$run_params = $params;
					return array(
						'task_id' => 'task-abc',
						'phase'   => 'archiving_files',
						'type'    => 'full',
					);
				}
				return $result;
			},
			10,
			4
		);

		$called = array();
		$this->record_child_actions( $called );

		$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );

		$this->assertFalse( $result, 'A working task defers to the next cycle.' );
		$this->assertSame( 'task-abc', $run_params['task_id'] );
		$this->assertNotContains( 'backup_checkpid', $called, 'The task-spine path must not invoke the removed PID polling callable.' );
	}

	/**
	 * A completed task feeds the artifact into the fetch result, entering
	 * the unchanged download flow.
	 *
	 * @return void
	 */
	public function test_spine_completed_populates_fetch_result() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->give_site_real_privkey( $site_id );
		$this->seed_progress(
			$task->id,
			$site_id,
			array(
				'child_backup_task_id' => 'task-abc',
				'removedFiles'         => 1,
			)
		);
		$this->mock_child_action(
			'backup_task_run',
			array(
				'task_id' => 'task-abc',
				'phase'   => 'completed',
				'type'    => 'full',
				'file'    => 'backup-1749600000.tar',
				'size'    => 4096,
			)
		);
		$this->mock_child_action( 'delete_backup', array( 'result' => 'ok' ) );
		add_filter( 'mainwp_download_to_file_pre', '__return_true', 10, 1 );

		try {
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
		} catch ( MainWP_Exception $e ) {
			unset( $e ); // The skipped download fails validation; only the fetch result matters here.
		}

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( 'backup-1749600000.tar', $progress->fetchResult['full'] );
		$this->assertFalse( $progress->fetchResult['db'] );
		$this->assertSame( 4096, $progress->fetchResult['size'] );
	}

	/**
	 * A completed db task lands its artifact under the db key.
	 *
	 * @return void
	 */
	public function test_spine_completed_db_kind_populates_db_key() {
		list( $site_id, $task ) = $this->site_and_task( array( 'type' => 'db' ) );
		$this->give_site_real_privkey( $site_id );
		$this->seed_progress(
			$task->id,
			$site_id,
			array(
				'child_backup_task_id' => 'task-db',
				'removedFiles'         => 1,
			)
		);
		$this->mock_child_action(
			'backup_task_run',
			array(
				'task_id' => 'task-db',
				'phase'   => 'completed',
				'type'    => 'db',
				'file'    => 'dbBackup-1749600000.sql.tar',
				'size'    => 2048,
			)
		);
		$this->mock_child_action( 'delete_backup', array( 'result' => 'ok' ) );
		add_filter( 'mainwp_download_to_file_pre', '__return_true', 10, 1 );

		try {
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
		} catch ( MainWP_Exception $e ) {
			unset( $e );
		}

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( 'dbBackup-1749600000.sql.tar', $progress->fetchResult['db'] );
		$this->assertFalse( $progress->fetchResult['full'] );
	}

	/**
	 * A completed files task is an archive: it lands under the full key
	 * and enters the same download flow, never the db slot.
	 *
	 * @return void
	 */
	public function test_spine_completed_files_kind_populates_full_key() {
		list( $site_id, $task ) = $this->site_and_task( array( 'type' => 'files' ) );
		$this->give_site_real_privkey( $site_id );
		$this->seed_progress(
			$task->id,
			$site_id,
			array(
				'child_backup_task_id' => 'task-files',
				'removedFiles'         => 1,
			)
		);
		$this->mock_child_action(
			'backup_task_run',
			array(
				'task_id' => 'task-files',
				'phase'   => 'completed',
				'type'    => 'files',
				'file'    => 'backup-1749600000.tar',
				'size'    => 4096,
			)
		);
		$this->mock_child_action( 'delete_backup', array( 'result' => 'ok' ) );
		add_filter( 'mainwp_download_to_file_pre', '__return_true', 10, 1 );

		try {
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
		} catch ( MainWP_Exception $e ) {
			unset( $e ); // The skipped download fails validation; only the fetch result matters here.
		}

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( 'backup-1749600000.tar', $progress->fetchResult['full'] );
		$this->assertFalse( $progress->fetchResult['db'] );
	}

	/**
	 * A completed files run records a files-typed snapshot so retention
	 * counts it as its own unit and the restore catalog can refuse it.
	 *
	 * @return void
	 */
	public function test_completed_files_run_records_files_snapshot() {
		list( $site_id, $task ) = $this->site_and_task( array( 'type' => 'files' ) );
		$content                = 'PK' . "\x03\x04" . str_repeat( 'x', 4092 );
		$this->seed_download_ready_progress( $task->id, $site_id, strlen( $content ) );
		$this->mock_child_action( 'delete_backup', array( 'result' => 'ok' ) );

		add_filter(
			'mainwp_download_to_file_pre',
			function ( $pre, $url, $file ) use ( $content ) {
				unset( $pre, $url );
				file_put_contents( $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
				return true;
			},
			10,
			3
		);

		$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
		$this->assertNotFalse( $result );

		$snapshot = \MainWP\Dashboard\MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, (int) $task->id );

		$this->assertNotNull( $snapshot, 'A completed files run must record a snapshot row.' );
		$this->assertSame( 'files', $snapshot->type );
		$this->assertNull( $snapshot->parent_id );
		$this->assertCount( 1, $snapshot->archives );
		$this->assertSame( 'full', $snapshot->archives[0]['role'] );
	}

	/**
	 * A failed task surfaces the child's recorded error and code.
	 *
	 * @return void
	 */
	public function test_spine_failed_task_throws_child_error() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->seed_progress( $task->id, $site_id, array( 'child_backup_task_id' => 'task-abc' ) );
		$this->mock_child_action(
			'backup_task_run',
			array(
				'task_id'    => 'task-abc',
				'phase'      => 'failed',
				'type'       => 'full',
				'error'      => 'Could not read table data for the backup.',
				'error_code' => 'mainwp_backup_db_query_failed',
			)
		);

		try {
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
			$this->fail( 'Expected the failed task to propagate.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_db_query_failed', $e->get_message_error_code() );
			$this->assertStringContainsString( 'Could not read table data', $e->getMessage() );
		}
	}

	/**
	 * A failed run call consumes a retry attempt, mirroring the legacy
	 * status-check semantics; exhausted attempts stop the run.
	 *
	 * @return void
	 */
	public function test_spine_run_failure_consumes_attempt() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->seed_progress( $task->id, $site_id, array( 'child_backup_task_id' => 'task-abc' ) );
		$this->mock_child_action( 'backup_task_run', new MainWP_Exception( 'connection failed' ) );

		try {
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
		} catch ( MainWP_Exception $e ) {
			unset( $e );
		}

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( 1, (int) $progress->attempts, 'A failed run call must consume one retry attempt.' );

		MainWP_DB_Backup::instance()->update_backup_task_progress( $task->id, $site_id, array( 'attempts' => 5 ) );
		try {
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
			$this->fail( 'Expected retry-exhausted exception.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_retry_exhausted', $e->get_message_error_code() );
		}
	}

	/**
	 * Mock the child wire like the real driver race on the native spine
	 * path: the preflight advertises the task machine, and by the time the
	 * child refuses the duplicate create, the concurrent driver of the SAME
	 * run has already registered its child task id on the shared progress
	 * row. The refusal payload is byte-exact from the 2026-07-13 live
	 * diagnostics capture: a coded error whose message the legacy substring
	 * does not match.
	 *
	 * @param int  $task_id  Task id whose progress row the other driver owns.
	 * @param int  $site_id  Site id.
	 * @param int  $refusals Incremented by reference when the refusal fires.
	 * @param bool $register Whether the other driver registers the child task
	 *                       id before the refusal returns (true = same-run
	 *                       collision, false = a foreign backup holds the slot).
	 *
	 * @return void
	 */
	private function mock_already_running_race( $task_id, $site_id, &$refusals, $register = true ) {
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what ) use ( $task_id, $site_id, &$refusals, $register ) {
				unset( $website );
				if ( 'backup_preflight' === $what ) {
					return array( 'task_spine' => 1 );
				}
				if ( 'backup_task_create' === $what ) {
					++$refusals;
					if ( $register ) {
						MainWP_DB_Backup::instance()->update_backup_task_progress(
							$task_id,
							$site_id,
							array( 'child_backup_task_id' => 'task-other-driver' )
						);
					}
					return array(
						'error'      => 'Another backup process is already running on this site. Wait for it to finish or for the stale process to be detected, then try again.',
						'error_code' => 'mainwp_backup_already_running',
					);
				}
				return $result;
			},
			10,
			3
		);
	}

	/**
	 * The initiate slice, the progress poll, and the kicked continue cron can
	 * all drive the same run concurrently. When the child refuses a duplicate
	 * start because another driver of THIS run already holds its single
	 * backup slot, the refused driver must yield (return false, like the
	 * queued-spine path) instead of throwing: the collision is the run
	 * succeeding, not failing.
	 *
	 * @return void
	 */
	public function test_same_run_already_running_refusal_yields_to_owning_driver() {
		list( $site_id, $task ) = $this->site_and_task();

		$refusals = 0;
		$this->mock_already_running_race( $task->id, $site_id, $refusals, true );

		$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );

		$this->assertSame( 1, $refusals, 'Precondition: the mocked child must refuse the duplicate start.' );
		$this->assertFalse( $result, 'A same-run slot collision must yield (return false), not throw.' );
	}

	/**
	 * The first marker-less refusal is still ambiguous with a same-run create
	 * race, so it starts the persistence clock and yields silently.
	 *
	 * @return void
	 */
	public function test_first_foreign_refusal_stamps_and_yields() {
		list( $site_id, $task ) = $this->site_and_task();

		$refusals = 0;
		$this->mock_already_running_race( $task->id, $site_id, $refusals, false );

		$result   = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );

		$this->assertSame( 1, $refusals, 'Precondition: the mocked child must refuse the start.' );
		$this->assertFalse( $result, 'The first foreign refusal must yield while its persistence is still ambiguous.' );
		$this->assertGreaterThan( 0, (int) $progress->foreign_refused_since, 'The first foreign refusal must start the persistence clock.' );
	}

	/**
	 * The native child's real refusal, byte-exact from the 2026-07-13 live
	 * diagnostics capture, carries error_code mainwp_backup_already_running.
	 * The code must engage the foreign-refusal persistence gate - first refusal
	 * stamps the clock and yields - instead of falling through to the generic
	 * create-error throw.
	 *
	 * @return void
	 */
	public function test_native_coded_refusal_engages_persistence_gate() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->mock_child_action( 'backup_preflight', array( 'task_spine' => 1 ) );
		$this->mock_child_action(
			'backup_task_create',
			array(
				'error'            => 'Another backup process is already running on this site. Wait for it to finish or for the stale process to be detected, then try again.',
				'error_code'       => 'mainwp_backup_already_running',
				'fetch_url_output' => array(
					'http_status'      => 200,
					'connection_step'  => 'verify_credentials',
					'child_error_code' => 'mainwp_backup_already_running',
				),
			)
		);

		$result   = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );

		$this->assertFalse( $result, 'The first native coded refusal must yield while its persistence is still ambiguous.' );
		$this->assertGreaterThan( 0, (int) $progress->foreign_refused_since, 'The native coded refusal must start the persistence clock.' );
	}

	/**
	 * A transient mutex write failure gets the same-run persistence window.
	 *
	 * @return void
	 */
	public function test_first_mutex_write_refusal_stamps_and_yields() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->mock_child_action( 'backup_preflight', array( 'task_spine' => 1 ) );
		$this->mock_child_action(
			'backup_task_create',
			array(
				'error'      => 'Could not acquire the backup lock.',
				'error_code' => 'mainwp_backup_mutex_write_failed',
			)
		);

		$result   = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );

		$this->assertFalse( $result, 'The first mutex write refusal must yield for a same-run retry.' );
		$this->assertGreaterThan( 0, (int) $progress->foreign_refused_since, 'The mutex write refusal must start the persistence clock.' );
		$this->assertSame( 0, (int) $progress->dtsFetched, 'The create window must reset so the next cycle retries.' );
	}

	/**
	 * Persistent mutex write failures must surface once the retry window ends.
	 *
	 * @return void
	 */
	public function test_persistent_mutex_write_refusal_surfaces() {
		$last_run               = time() - MainWP_Backup_Executor::FOREIGN_REFUSAL_SURFACE_SECONDS - 60;
		list( $site_id, $task ) = $this->site_and_task( array( 'last_run' => $last_run ) );
		$this->seed_progress(
			$task->id,
			$site_id,
			array(
				'dtsFetched'            => $last_run - 1,
				'foreign_refused_since' => time() - MainWP_Backup_Executor::FOREIGN_REFUSAL_SURFACE_SECONDS - 30,
			)
		);
		$this->mock_child_action( 'backup_preflight', array( 'task_spine' => 1 ) );
		$this->mock_child_action(
			'backup_task_create',
			array(
				'error'      => 'Could not acquire the backup lock.',
				'error_code' => 'mainwp_backup_mutex_write_failed',
			)
		);

		try {
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
			$this->fail( 'Expected the persistent mutex write refusal to propagate.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( MainWP_Backup_Error_Codes::ERR_MUTEX_WRITE_FAILED, $e->get_message_error_code() );
			$this->assertNull( $e->get_message_extra( false ) );
			$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
			$this->assertEmpty( $progress->fetchResult, 'A persistent mutex write refusal must not become a cached fetch result.' );
		}
	}

	/**
	 * Live drill 2026-07-13: after the first refusal yielded, the next cycle
	 * skipped the create branch because dtsFetched was still fresh instead of
	 * retrying the child task create. A refusal-yielded row must hand the create
	 * window back so every following cycle re-attempts the create until the hold
	 * clears or the surface window elapses.
	 *
	 * @return void
	 */
	public function test_repeated_refusal_within_window_yields_again_instead_of_stalling() {
		list( $site_id, $task ) = $this->site_and_task();
		$refusals               = 0;
		$this->mock_already_running_race( $task->id, $site_id, $refusals, false );

		$this->assertFalse( MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' ), 'Cycle 1 must yield on the first refusal.' );

		$result2 = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );

		$this->assertSame( 2, $refusals, 'Cycle 2 must re-attempt the create against the child.' );
		$this->assertFalse( $result2, 'Cycle 2 within the surface window must yield again, not throw.' );
	}

	/**
	 * Once the foreign hold clears, the next cycle's re-attempted create
	 * claims the slot and the run proceeds - the refusal must not wedge the
	 * row past the hold's lifetime.
	 *
	 * @return void
	 */
	public function test_refusal_then_freed_slot_creates_task_next_cycle() {
		list( $site_id, $task ) = $this->site_and_task();
		$refuse                 = true;
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what ) use ( &$refuse ) {
				unset( $website );
				if ( 'backup_preflight' === $what ) {
					return array( 'task_spine' => 1 );
				}
				if ( 'backup_task_create' === $what ) {
					if ( $refuse ) {
						return array(
							'error'      => 'Another backup process is already running on this site. Wait for it to finish or for the stale process to be detected, then try again.',
							'error_code' => 'mainwp_backup_already_running',
						);
					}
					return array(
						'task_id' => 'task-1749600001-freed1',
						'phase'   => 'queued',
					);
				}
				return $result;
			},
			10,
			3
		);

		$this->assertFalse( MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' ), 'The refused cycle yields.' );

		$refuse = false;
		$this->assertFalse( MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' ), 'The freed cycle queues the child task and defers.' );

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( 'task-1749600001-freed1', $progress->child_backup_task_id, 'The freed slot must be claimed by a fresh create.' );
		$this->assertSame( 0, (int) $progress->foreign_refused_since, 'A granted create must clear the persistence clock.' );
	}

	/**
	 * A marker-less refusal that persists past the create window is genuinely
	 * foreign and surfaces with the marker consumed by the site loop.
	 *
	 * @return void
	 */
	public function test_persistent_foreign_refusal_surfaces() {
		$last_run               = time() - MainWP_Backup_Executor::FOREIGN_REFUSAL_SURFACE_SECONDS - 60;
		list( $site_id, $task ) = $this->site_and_task( array( 'last_run' => $last_run ) );
		$this->seed_progress(
			$task->id,
			$site_id,
			array(
				'dtsFetched'            => $last_run - 1,
				'foreign_refused_since' => time() - MainWP_Backup_Executor::FOREIGN_REFUSAL_SURFACE_SECONDS - 30,
			)
		);

		$refusals = 0;
		$this->mock_already_running_race( $task->id, $site_id, $refusals, false );

		try {
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
			$this->fail( 'Expected the persistent foreign refusal to propagate.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 1, $refusals, 'Precondition: the mocked child must refuse the start.' );
			$this->assertSame( MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING, $e->get_message_error_code() );
			$this->assertSame( MainWP_Backup_Executor::EXTRA_FOREIGN_ALREADY_RUNNING, $e->get_message_extra() );
		}
	}

	/**
	 * A sibling run of this Dashboard holding the Child's one-task slot is
	 * a delay: the refused task yields with the persistence clock cleared.
	 * A foreign holder, a paused sibling nothing will advance, or a sibling
	 * whose Child task already finished or failed on the site still surfaces
	 * once the refusal outlives the window.
	 *
	 * @dataProvider sibling_holder_cases
	 * @param string|null $sibling Null for no sibling row, else 'active', 'paused', 'finished', 'child failed' or 'child completed'.
	 * @param bool        $yields  Whether the refused create must yield.
	 *
	 * @return void
	 */
	public function test_refusal_while_sibling_task_holds_site( $sibling, $yields ) {
		$last_run               = time() - MainWP_Backup_Executor::FOREIGN_REFUSAL_SURFACE_SECONDS - 60;
		list( $site_id, $task ) = $this->site_and_task( array( 'last_run' => $last_run ) );
		$db                     = MainWP_DB_Backup::instance();

		$holder = null;
		if ( null !== $sibling ) {
			$holder = $db->add_backup_task( $task->userid, 'Sibling holder', 'daily', 'full', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
			$this->assertNotFalse( $holder );
			$db->update_backup_task_with_values(
				$holder->id,
				array(
					'last_run'  => time() - 50,
					'completed' => 0,
					'paused'    => 'paused' === $sibling ? 1 : 0,
				)
			);
			$sibling_row = array( 'child_backup_task_id' => 'task-sibling-live' );
			if ( 'finished' === $sibling ) {
				// A completed site keeps its task id for the snapshot run key.
				$sibling_row['fetchResult'] = wp_json_encode(
					array(
						'full' => 'backup-file.zip',
						'db'   => false,
					)
				);
			} elseif ( 'child failed' === $sibling ) {
				// The phase lands before the failure cleanup clears the task id.
				$sibling_row['child_phase'] = 'failed';
			} elseif ( 'child completed' === $sibling ) {
				// The phase lands before the fetchResult claim.
				$sibling_row['child_phase'] = 'completed';
			}
			$this->seed_progress( $holder->id, $site_id, $sibling_row );
			$task->id = (int) $holder->id + 1;
		}

		$this->seed_progress(
			$task->id,
			$site_id,
			array(
				'dtsFetched'            => $last_run - 1,
				'foreign_refused_since' => time() - MainWP_Backup_Executor::FOREIGN_REFUSAL_SURFACE_SECONDS - 30,
			)
		);

		$reasons = array();
		$capture = function ( $task_id, $reason ) use ( &$reasons ) {
			unset( $task_id );
			$reasons[] = $reason;
		};
		add_action( 'mainwp_backup_diag_run_reinit', $capture, 10, 2 );

		$refusals = 0;
		$this->mock_already_running_race( $task->id, $site_id, $refusals, false );

		$result = null;
		$thrown = null;
		try {
			$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
		} catch ( MainWP_Exception $e ) {
			$thrown = $e;
		} finally {
			remove_action( 'mainwp_backup_diag_run_reinit', $capture, 10 );
			if ( $holder ) {
				$db->remove_backup_task( $holder->id );
			}
		}

		$this->assertSame( 1, $refusals, 'Precondition: the mocked child must refuse the start.' );
		if ( $yields ) {
			$this->assertNull( $thrown, 'A sibling-held site must not fail the refused task.' );
			$this->assertFalse( $result );
			$progress = $db->get_backup_task_progress( $task->id, $site_id );
			$this->assertSame( 0, (int) $progress->foreign_refused_since, 'The persistence clock must not run for a sibling holder.' );
			$this->assertContains( 'sibling_holds_site_yield', $reasons );
		} else {
			$this->assertNotNull( $thrown, 'A holder that is not a live sibling must still surface after the window.' );
			$this->assertSame( MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING, $thrown->get_message_error_code() );
			$this->assertSame( MainWP_Backup_Executor::EXTRA_FOREIGN_ALREADY_RUNNING, $thrown->get_message_extra() );
			$this->assertNotContains( 'sibling_holds_site_yield', $reasons );
		}
	}

	/**
	 * Holder shapes for the refused create.
	 *
	 * @return array
	 */
	public static function sibling_holder_cases() {
		return array(
			'active sibling holds the site' => array( 'active', true ),
			'foreign holder'                => array( null, false ),
			'paused sibling'                => array( 'paused', false ),
			'sibling finished on the site'  => array( 'finished', false ),
			'sibling child task failed'     => array( 'child failed', false ),
			'sibling child task completed'  => array( 'child completed', false ),
		);
	}

	/**
	 * A same-second re-claim puts last_run one second ahead of the clock.
	 * The create write must still stamp the row as this run's, or the next
	 * cycle re-creates the Child task and the open-run and sibling-holder
	 * lookups miss the row for a cycle. The test uses a wider gap so a slow
	 * run cannot hide a regression.
	 *
	 * @return void
	 */
	public function test_create_stamp_never_behind_same_second_reclaim() {
		$site_id  = $this->create_test_site();
		$website  = MainWP_DB::instance()->get_website_by_id( $site_id );
		$db       = MainWP_DB_Backup::instance();
		$last_run = time() + 30;
		$owner    = $db->add_backup_task( $website->userid, 'Reclaimed run', 'daily', 'full', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		$other    = $db->add_backup_task( $website->userid, 'Refused sibling', 'daily', 'full', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $owner );
		$this->assertNotFalse( $other );
		try {
			$db->update_backup_task_with_values(
				$owner->id,
				array(
					'last_run'  => $last_run,
					'completed' => 0,
					'paused'    => 0,
				)
			);
			$task = $this->make_task(
				$website->userid,
				array(
					'id'       => (int) $owner->id,
					'last_run' => $last_run,
				)
			);

			$this->mock_child_action( 'backup_preflight', array( 'task_spine' => 1 ) );
			$this->mock_child_action(
				'backup_task_create',
				array(
					'task_id' => 'task-1749600002-reclm1',
					'phase'   => 'queued',
				)
			);

			$this->assertFalse( MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' ), 'Creating the task defers the work to the next cycle.' );

			$progress   = $db->get_backup_task_progress( $task->id, $site_id );
			$open_ids   = $db->get_open_run_child_task_ids( $site_id );
			$sibling_of = $db->get_sibling_site_holder( $site_id, (int) $other->id );
		} finally {
			$db->remove_backup_task( $owner->id );
			$db->remove_backup_task( $other->id );
		}

		$this->assertSame( 'task-1749600002-reclm1', $progress->child_backup_task_id, 'Precondition: the create must record the Child task id.' );
		$this->assertGreaterThanOrEqual( $last_run, (int) $progress->dtsFetched, 'The first stamp of a run must not fall behind its claim.' );
		$this->assertContains( 'task-1749600002-reclm1', $open_ids, 'The open-run lookup must see the current run row.' );
		$this->assertSame( (int) $owner->id, $sibling_of, 'The sibling-holder lookup must see the current run row.' );
	}

	/**
	 * Through the full task runner, a same-run collision must leave no trace
	 * that reads as failure: backup_errors stays empty, the failure email
	 * never fires, and the run stays open so the owning driver can finish it.
	 * Before the fix this exact path recorded the refusal, closed the run,
	 * and emailed a failure for a backup that succeeded.
	 *
	 * @return void
	 */
	public function test_same_run_refusal_records_no_error_and_sends_no_failure_email() {
		$site_id = $this->create_test_site();
		$user    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$task    = MainWP_DB_Backup::instance()->add_backup_task(
			$user,
			'Driver race test task',
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

		$mails = 0;
		add_filter(
			'pre_wp_mail',
			function () use ( &$mails ) {
				++$mails;
				return true;
			}
		);

		$refusals = 0;
		$this->mock_already_running_race( (int) $task->id, $site_id, $refusals, true );

		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, true );

		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertSame( 1, $refusals, 'Precondition: the run must reach the mocked child refusal.' );
		$this->assertSame( '', (string) $task->backup_errors, 'A same-run collision must not be recorded as a run error.' );
		$this->assertLessThan( (int) $task->last_run, (int) $task->completed, 'The run must stay open for the owning driver to finish it.' );
		$this->assertSame( 0, $mails, 'A same-run collision must not fire the failure email.' );
	}

	/**
	 * When the child's refusal lands before another same-run driver claims the
	 * shared row, the first refusal stamps and yields: the winning driver may
	 * still be mid-create, so terminal bookkeeping must not close under it.
	 *
	 * @return void
	 */
	public function test_same_run_create_race_yields_through_full_handler() {
		$site_id = $this->create_test_site();
		$user    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$task    = MainWP_DB_Backup::instance()->add_backup_task( $user, 'Create window race task', 'daily', 'full', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task, 'Precondition: the task row must insert.' );

		$mails = 0;
		add_filter(
			'pre_wp_mail',
			function () use ( &$mails ) {
				++$mails;
				return true;
			}
		);

		$refusals = 0;
		$this->mock_already_running_race( (int) $task->id, $site_id, $refusals, false );

		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, true );

		$task     = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( 1, $refusals, 'Precondition: the run must reach the mocked child refusal.' );
		$this->assertSame( '', (string) $task->backup_errors, 'A refusal against an open run must not be recorded as a run error.' );
		$this->assertLessThan( (int) $task->last_run, (int) $task->completed, 'The run must stay open for the driver that holds the slot.' );
		$this->assertSame( 0, $mails, 'A refusal against an open run must not fire the failure email.' );
		$this->assertGreaterThan( 0, (int) $progress->foreign_refused_since, 'The ambiguous refusal must start the persistence clock.' );
	}

	/**
	 * A persistent foreign refusal must pass through the site loop's normal
	 * error-recording path instead of its same-run already-running yield.
	 *
	 * @return void
	 */
	public function test_persistent_foreign_refusal_records_error_through_full_handler() {
		$site_id = $this->create_test_site();
		$user    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$task    = MainWP_DB_Backup::instance()->add_backup_task( $user, 'Persistent foreign refusal task', 'daily', 'full', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task, 'Precondition: the task row must insert.' );
		$last_run = time() - MainWP_Backup_Executor::FOREIGN_REFUSAL_SURFACE_SECONDS - 60;
		MainWP_DB_Backup::instance()->update_backup_task_with_values( (int) $task->id, array( 'last_run' => $last_run ) );
		$this->seed_progress(
			(int) $task->id,
			$site_id,
			array(
				'dtsFetched'            => $last_run - 1,
				'foreign_refused_since' => time() - MainWP_Backup_Executor::FOREIGN_REFUSAL_SURFACE_SECONDS - 30,
			)
		);

		$refusals = 0;
		$this->mock_already_running_race( (int) $task->id, $site_id, $refusals, false );
		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, false );

		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertSame( 1, $refusals, 'Precondition: the run must reach the mocked child refusal.' );
		$this->assertNotSame( '', (string) $task->backup_errors, 'A persistent foreign refusal must be recorded as a run error.' );
		$this->assertStringContainsString( MainWP_Backup_Error_Codes::message( MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING ), (string) $task->backup_errors );
	}

	/**
	 * The on-demand poll must surface a recorded already-running refusal rather
	 * than replacing it with the generic missing-snapshot failure.
	 *
	 * @return void
	 */
	public function test_poll_surfaces_recorded_foreign_already_running_error() {
		$site_id = $this->create_test_site();
		$website = MainWP_DB::instance()->get_website_by_id( $site_id );
		wp_set_current_user( $website->userid );
		$task = MainWP_DB_Backup::instance()->ensure_manual_backup_task( $website );
		$this->assertNotEmpty( $task, 'Precondition: the on-demand task must exist.' );

		$last_run = time() - 10;
		$message  = MainWP_Backup_Error_Codes::message( MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING );
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			(int) $task->id,
			array(
				'last_run'      => $last_run,
				'completed'     => $last_run,
				'backup_errors' => $message,
			)
		);

		$response = $this->poll_on_demand( $site_id, $last_run );

		$this->assertSame( 'failed', $response['status'] );
		$this->assertSame( $message, $response['error'] );
		$this->assertStringNotContainsString( 'did not produce a snapshot', $response['error'] );
	}

	/**
	 * The all-sites-complete close must not stamp the run finished while a
	 * creating marker for the current cycle is live on a progress row: a
	 * create round trip is still in flight, and closing under it is the
	 * mid-flight close this guard exists to prevent.
	 *
	 * @return void
	 */
	public function test_run_close_refused_while_creating_marker_live() {
		$site_id = $this->create_test_site();
		$user    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$task    = MainWP_DB_Backup::instance()->add_backup_task( $user, 'Close guard task', 'daily', 'full', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task, 'Precondition: the task row must insert.' );
		MainWP_DB_Backup::instance()->update_backup_task_with_values( (int) $task->id, array( 'last_run' => time() - 100 ) );
		MainWP_DB_Backup::instance()->update_completed_sites( $task->id, array( $site_id => true ) );
		$this->seed_progress( (int) $task->id, $site_id, array( 'child_backup_task_id' => '__creating_livetoken0001' ) );

		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, false );

		$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertSame( 0, (int) $fresh->completed, 'The close must wait while a create for this cycle is in flight.' );
	}

	/**
	 * A creating marker past its window no longer holds the close hostage:
	 * whatever planted it died mid-request, and the guard healing itself is
	 * what keeps an orphaned marker from wedging the run open forever.
	 *
	 * @return void
	 */
	public function test_run_close_proceeds_when_marker_stale() {
		$site_id = $this->create_test_site();
		$user    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$task    = MainWP_DB_Backup::instance()->add_backup_task( $user, 'Close guard stale task', 'daily', 'full', '', (string) $site_id, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		$this->assertNotFalse( $task, 'Precondition: the task row must insert.' );
		MainWP_DB_Backup::instance()->update_backup_task_with_values( (int) $task->id, array( 'last_run' => time() - 100 ) );
		MainWP_DB_Backup::instance()->update_completed_sites( $task->id, array( $site_id => true ) );
		$this->seed_progress(
			(int) $task->id,
			$site_id,
			array(
				'child_backup_task_id' => '__creating_staletoken001',
				'dtsFetched'           => time() - 61,
			)
		);

		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, false );

		$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertGreaterThan( 0, (int) $fresh->completed, 'A stale marker must not block the close.' );
	}

	/**
	 * Before the create request leaves for the child, the progress row must
	 * already carry the creating marker: the row is what a concurrent driver
	 * reads to decide whether this run's child slot is spoken for, and the
	 * create round trip is seconds long on real hosting.
	 *
	 * @return void
	 */
	public function test_spine_create_marks_row_before_calling_child() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->mock_child_action( 'backup_preflight', array( 'task_spine' => 1 ) );

		$row_at_create = null;
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what ) use ( $task, $site_id, &$row_at_create ) {
				unset( $website );
				if ( 'backup_task_create' === $what ) {
					$progress      = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
					$row_at_create = $progress ? (string) $progress->child_backup_task_id : null;
					return array(
						'task_id' => 'task-1749600000-abcdef',
						'phase'   => 'queued',
					);
				}
				return $result;
			},
			10,
			3
		);

		$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );

		$this->assertFalse( $result, 'Creating the task defers the work to the next cycle.' );
		$this->assertStringStartsWith( '__creating_', (string) $row_at_create, 'The creating marker must be on the row before the create request goes out.' );

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( 'task-1749600000-abcdef', $progress->child_backup_task_id, 'The real child task id must replace the marker once the create returns.' );
	}

	/**
	 * A driver that finds a fresh creating marker on the row yields without
	 * calling the child at all: another driver's create request for this run
	 * is still in flight, and polling with the marker as a task id would ask
	 * the child about a task that does not exist.
	 *
	 * @return void
	 */
	public function test_spine_racing_driver_yields_on_fresh_marker_without_polling_child() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->seed_progress( $task->id, $site_id, array( 'child_backup_task_id' => '__creating_testtoken0001' ) );
		$this->mock_child_action(
			'backup_task_run',
			array(
				'task_id' => '__creating_testtoken0001',
				'phase'   => 'archiving_files',
				'type'    => 'full',
			)
		);

		$called = array();
		$this->record_child_actions( $called );

		$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );

		$this->assertFalse( $result, 'A fresh creating marker defers to the in-flight create.' );
		$this->assertSame( array(), $called, 'No child call may fire while another driver\'s create is in flight.' );
	}

	/**
	 * A creating marker older than the in-flight window can only be a driver
	 * that died mid-request. It must not wedge the site: the row resets so
	 * the next cycle creates the task again from scratch.
	 *
	 * @return void
	 */
	public function test_spine_stale_marker_is_cleared_and_create_retried() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->seed_progress(
			$task->id,
			$site_id,
			array(
				'child_backup_task_id' => '__creating_testtoken0002',
				'dtsFetched'           => time() - 61,
			)
		);
		$this->mock_child_action(
			'backup_task_run',
			array(
				'task_id' => '__creating_testtoken0002',
				'phase'   => 'archiving_files',
				'type'    => 'full',
			)
		);

		$called = array();
		$this->record_child_actions( $called );

		$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );

		$this->assertFalse( $result, 'Clearing the stale marker defers to the next cycle.' );
		$this->assertSame( array(), $called, 'The stale marker is cleared without a child call.' );

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( '', $progress->child_backup_task_id, 'The abandoned marker must be cleared.' );
		$this->assertSame( 0, (int) $progress->dtsFetched, 'The row must reset so the next cycle re-enters the create branch.' );

		$called = array();
		$this->mock_child_action( 'backup_preflight', array( 'task_spine' => 1 ) );
		$this->mock_child_action(
			'backup_task_create',
			array(
				'task_id' => 'task-retry-1',
				'phase'   => 'queued',
			)
		);

		$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );

		$this->assertFalse( $result, 'The retried create defers to the next cycle.' );
		$this->assertContains( 'backup_task_create', $called, 'The next cycle must create the task again.' );

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( 'task-retry-1', $progress->child_backup_task_id );
	}

	/**
	 * A create that resolves without a task id throws, and the marker must
	 * not stay behind on the row: the site is recorded as failed, so nothing
	 * would ever clear it, and a leftover marker reads as an in-flight create
	 * to every later guard.
	 *
	 * @return void
	 */
	public function test_spine_create_failure_without_task_id_clears_marker() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->mock_child_action( 'backup_preflight', array( 'task_spine' => 1 ) );

		$row_at_create = null;
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what ) use ( $task, $site_id, &$row_at_create ) {
				unset( $website );
				if ( 'backup_task_create' === $what ) {
					$progress      = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
					$row_at_create = $progress ? (string) $progress->child_backup_task_id : null;
					return array( 'phase' => 'queued' );
				}
				return $result;
			},
			10,
			3
		);

		try {
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
			$this->fail( 'Expected the missing task id to propagate.' );
		} catch ( MainWP_Exception $e ) {
			unset( $e );
		}

		$this->assertStringStartsWith( '__creating_', (string) $row_at_create, 'Precondition: the marker must be on the row during the create request.' );

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( '', $progress->child_backup_task_id, 'A failed create must not leave the marker behind.' );
	}

	/**
	 * The fetchResult claim is a conditional write: it lands on an
	 * unclaimed row (empty string or the entry reset's encoded empty
	 * array) and refuses once any driver's terminal projection is there.
	 *
	 * @return void
	 */
	public function test_claim_backup_fetch_result_is_conditional() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->seed_progress( $task->id, $site_id, array( 'fetchResult' => wp_json_encode( array() ) ) );

		$first = MainWP_DB_Backup::instance()->claim_backup_fetch_result( $task->id, $site_id, wp_json_encode( array( 'full' => 'a.tar.gz' ) ) );
		$this->assertTrue( $first, 'An unclaimed row must accept the terminal projection.' );

		$second = MainWP_DB_Backup::instance()->claim_backup_fetch_result( $task->id, $site_id, wp_json_encode( array( 'full' => 'b.tar.gz' ) ) );
		$this->assertFalse( $second, 'A claimed row must refuse a second terminal projection.' );

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( 'a.tar.gz', $progress->fetchResult['full'], 'The losing claim must not clobber the winning projection.' );
	}

	/**
	 * Two drivers polling the same completed child task: the one whose
	 * claim loses yields without firing a second completion event, so the
	 * terminal is consumed exactly once and the loser never enters the
	 * download phase against an artifact the winner is consuming.
	 *
	 * @return void
	 */
	public function test_terminal_claim_loser_yields_without_duplicate_completion_event() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->give_site_real_privkey( $site_id );
		$this->seed_progress( $task->id, $site_id, array( 'child_backup_task_id' => 'bk-term-race' ) );

		// The concurrent winner lands its projection while this driver's
		// poll round trip is in flight - between the row read at entry and
		// the write after the child reports completed.
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what ) use ( $task, $site_id ) {
				unset( $website );
				if ( 'backup_task_run' === $what ) {
					MainWP_DB_Backup::instance()->update_backup_task_progress(
						$task->id,
						$site_id,
						array( 'fetchResult' => wp_json_encode( array( 'full' => 'winner.tar.gz', 'db' => false, 'size' => 4096 ) ) ) // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- compact test fixture.
					);
					return array(
						'task_id' => 'bk-term-race',
						'phase'   => 'completed',
						'file'    => 'loser.tar.gz',
						'size'    => 4096,
					);
				}
				return $result;
			},
			10,
			3
		);

		$completed_events = 0;
		add_action(
			'mainwp_native_backup_completed',
			function () use ( &$completed_events ) {
				++$completed_events;
			}
		);

		$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );

		$this->assertFalse( $result, 'The losing driver must yield, not proceed into the download phase.' );
		$this->assertSame( 0, $completed_events, 'The losing driver must not fire a duplicate completion event.' );

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( 'winner.tar.gz', $progress->fetchResult['full'], 'The winning projection must survive the race.' );
	}

	/**
	 * A transient download failure consumes a retry attempt and yields
	 * instead of failing the site terminally: the progress row is fully
	 * resumable and the next driver cycle retries the transfer.
	 *
	 * @return void
	 */
	public function test_download_failure_yields_and_consumes_attempt() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->seed_download_ready_progress( $task->id, $site_id, 4096 );
		$this->mock_child_action( 'delete_backup', array( 'result' => 'ok' ) );

		$locked_file = null;
		add_filter(
			'mainwp_download_to_file_pre',
			function ( $pre, $url, $file ) use ( &$locked_file ) {
				unset( $pre, $url );
				$locked_file = $file;
				return true; // No file lands; validation reports the transfer failure.
			},
			10,
			3
		);

		$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );

		$this->assertFalse( $result, 'A first download failure must yield for retry, not fail the site.' );

		$progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
		$this->assertSame( 1, (int) $progress->attempts, 'The failed transfer must consume one retry attempt.' );
		$this->assertSame( 0, (int) $progress->downloadedFULLComplete, 'The transfer must stay incomplete for the retry.' );
		$this->assertNotNull( $locked_file );
		$this->assertFileDoesNotExist( $locked_file . '.downloading', 'The download lock must be released on the failure path.' );
	}

	/**
	 * A download that keeps failing after the retry budget is spent is a
	 * real terminal failure and propagates.
	 *
	 * @return void
	 */
	public function test_download_failure_with_exhausted_attempts_throws() {
		list( $site_id, $task ) = $this->site_and_task();
		$this->seed_download_ready_progress( $task->id, $site_id, 4096 );
		MainWP_DB_Backup::instance()->update_backup_task_progress( $task->id, $site_id, array( 'attempts' => 5 ) );
		$this->mock_child_action( 'delete_backup', array( 'result' => 'ok' ) );

		add_filter(
			'mainwp_download_to_file_pre',
			function ( $pre ) {
				unset( $pre );
				return true; // No file lands; validation reports the transfer failure.
			}
		);

		$this->expectException( MainWP_Exception::class );
		MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
	}

	/**
	 * The downloaded-complete flag is persisted before the remote artifact
	 * is deleted, so a driver dying between the two leaves a resumable
	 * validated download rather than a deleted remote with no local record.
	 *
	 * @return void
	 */
	public function test_download_complete_flag_persists_before_remote_delete() {
		list( $site_id, $task ) = $this->site_and_task();
		$content                = 'PK' . "\x03\x04" . str_repeat( 'x', 4092 );
		$this->seed_download_ready_progress( $task->id, $site_id, strlen( $content ) );

		$flag_at_delete = null;
		add_filter(
			'mainwp_fetch_url_authed_pre',
			function ( $result, $website, $what ) use ( $task, $site_id, &$flag_at_delete ) {
				unset( $website );
				if ( 'delete_backup' === $what ) {
					$progress       = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site_id );
					$flag_at_delete = $progress ? (int) $progress->downloadedFULLComplete : null;
					return array( 'result' => 'ok' );
				}
				return $result;
			},
			10,
			3
		);

		add_filter(
			'mainwp_download_to_file_pre',
			function ( $pre, $url, $file ) use ( $content ) {
				unset( $pre, $url );
				file_put_contents( $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
				return true;
			},
			10,
			3
		);

		$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );

		$this->assertNotFalse( $result );
		$this->assertSame( 1, $flag_at_delete, 'The complete flag must already be persisted when the remote delete leaves.' );
	}

	/**
	 * A failed remote cleanup after a validated download is child-side
	 * litter, not a run failure: the snapshot still lands and the run
	 * completes.
	 *
	 * @return void
	 */
	public function test_remote_delete_failure_still_records_snapshot() {
		list( $site_id, $task ) = $this->site_and_task();
		$content                = 'PK' . "\x03\x04" . str_repeat( 'x', 4092 );
		$this->seed_download_ready_progress( $task->id, $site_id, strlen( $content ) );
		$this->mock_child_action( 'delete_backup', new MainWP_Exception( 'The child site is busy.' ) );

		add_filter(
			'mainwp_download_to_file_pre',
			function ( $pre, $url, $file ) use ( $content ) {
				unset( $pre, $url );
				file_put_contents( $file, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
				return true;
			},
			10,
			3
		);

		$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );

		$this->assertNotFalse( $result, 'A failed remote cleanup must not fail a run whose artifact is validated locally.' );
		$snapshot = \MainWP\Dashboard\MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, (int) $task->id );
		$this->assertNotNull( $snapshot, 'The snapshot must be recorded despite the failed remote cleanup.' );
	}

	/**
	 * The snapshot-record phase runs under the artifact's download lock:
	 * a concurrent holder forces a yield, and the retry after release
	 * records the snapshot.
	 *
	 * @return void
	 */
	public function test_record_phase_lock_contention_yields_then_records() {
		list( $site_id, $task ) = $this->site_and_task();
		$content                = 'PK' . "\x03\x04" . str_repeat( 'x', 4092 );

		$dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'mainwp-test-record-lock/';
		wp_mkdir_p( $dir );
		$local = $dir . 'full-record-lock-test.tar.gz';
		file_put_contents( $local, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		MainWP_Backup_File_Manager::release_download_lock( $local ); // A prior aborted run may have left its lock behind.

		$this->give_site_real_privkey( $site_id );
		MainWP_DB_Backup::instance()->add_backup_task_progress( $task->id, $site_id, array() );
		MainWP_DB_Backup::instance()->update_backup_task_progress(
			$task->id,
			$site_id,
			array(
				'dtsFetched'             => time(),
				'pid'                    => 12345,
				'removedFiles'           => 1,
				'downloadedFULL'         => $local,
				'downloadedFULLComplete' => 1,
				'fetchResult'            => wp_json_encode(
					array(
						'full' => 'backup-file.tar.gz',
						'db'   => false,
						'size' => strlen( $content ),
					)
				),
			)
		);
		$this->mock_child_action( 'delete_backup', array( 'result' => 'ok' ) );

		$this->assertTrue( MainWP_Backup_File_Manager::acquire_download_lock( $local, 999, 999 ) );
		try {
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
			$this->fail( 'Expected an already-running yield while another process holds the artifact lock at record time.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_already_running', $e->get_message_error_code() );
		}
		$this->assertNull(
			\MainWP\Dashboard\MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, (int) $task->id ),
			'No snapshot may be recorded while the lock holder owns the artifact.'
		);

		MainWP_Backup_File_Manager::release_download_lock( $local );
		$result = MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );

		$this->assertNotFalse( $result );
		$snapshot = \MainWP\Dashboard\MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, (int) $task->id );
		$this->assertNotNull( $snapshot, 'The retry after the lock is released must record the snapshot.' );
	}

	/**
	 * A run whose flagged artifact vanished before the record lands is a
	 * loud failure, never a silent success with no snapshot: the exact
	 * shape of the lost-record race is surfaced instead of swallowed.
	 *
	 * @return void
	 */
	public function test_record_with_missing_artifact_is_loud() {
		list( $site_id, $task ) = $this->site_and_task();

		$dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'mainwp-test-record-missing/';
		wp_mkdir_p( $dir );

		$this->give_site_real_privkey( $site_id );
		MainWP_DB_Backup::instance()->add_backup_task_progress( $task->id, $site_id, array() );
		MainWP_DB_Backup::instance()->update_backup_task_progress(
			$task->id,
			$site_id,
			array(
				'dtsFetched'             => time(),
				'pid'                    => 12345,
				'removedFiles'           => 1,
				'downloadedFULL'         => $dir . 'full-vanished.tar.gz',
				'downloadedFULLComplete' => 1,
				'fetchResult'            => wp_json_encode(
					array(
						'full' => 'backup-file.tar.gz',
						'db'   => false,
						'size' => 4096,
					)
				),
			)
		);
		$this->mock_child_action( 'delete_backup', array( 'result' => 'ok' ) );

		try {
			MainWP_Backup_Executor::backup_site( $site_id, $task, 'backups' );
			$this->fail( 'A flagged-complete run with no artifact on disk must fail loudly, not report success without a snapshot.' );
		} catch ( MainWP_Exception $e ) {
			$this->assertSame( 'mainwp_backup_file_corrupt', $e->get_message_error_code() );
		}

		$this->assertNull(
			\MainWP\Dashboard\MainWP_Backup_Snapshots::instance()->get_latest_complete( $site_id, (int) $task->id ),
			'No snapshot may be fabricated for a vanished artifact.'
		);
	}
}
