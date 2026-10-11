<?php
/**
 * Durable history through the backup and restore drivers.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

require_once __DIR__ . '/trait-backup-private-root-fixture.php';

use MainWP\Dashboard\MainWP_Backup_Executor;
use MainWP\Dashboard\MainWP_Backup_Error_Codes;
use MainWP\Dashboard\MainWP_Backup_Operations;
use MainWP\Dashboard\MainWP_Backup_Restore_Job_Store;
use MainWP\Dashboard\MainWP_Backup_Restore_Orchestrator;
use MainWP\Dashboard\MainWP_Backup_Run_History;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Install;
use MainWP\Dashboard\MainWP_Logger;
use MainWP\Dashboard\MainWP_Manage_Backups_Handler;
use MainWP\Dashboard\MainWP_System;

/**
 * Exercises the production lanes with isolated storage and Child responses.
 */
class MainWP_Backup_Run_History_Test extends MainWP_Abilities_Test_Case {

	use Backup_Private_Root_Fixture;
	use Backup_Cancel_Transport_Guard;

	/**
	 * The isolated cron process lets the WordPress harness define its own constants.
	 *
	 * @param \PHPUnit\Framework\TestResult|null $result Current result collector.
	 * @return \PHPUnit\Framework\TestResult
	 */
	public function run( ?\PHPUnit\Framework\TestResult $result = null ): \PHPUnit\Framework\TestResult {
		if ( 'test_scheduled_lane_start_and_success_finalize' !== $this->getName( false ) ) {
			return parent::run( $result );
		}
		$configuration                           = $GLOBALS['__PHPUNIT_CONFIGURATION_FILE'] ?? null;
		$GLOBALS['__PHPUNIT_CONFIGURATION_FILE'] = '';
		try {
			return parent::run( $result );
		} finally {
			$GLOBALS['__PHPUNIT_CONFIGURATION_FILE'] = $configuration;
		}
	}

	/**
	 * Scripted terminal answer.
	 *
	 * @var array
	 */
	private $answer;

	/**
	 * Child calls observed in this request.
	 *
	 * @var array
	 */
	private $calls;

	/**
	 * Signing fixture.
	 *
	 * @var string
	 */
	private $private_key;

	/**
	 * Options saved around DDL.
	 *
	 * @var array
	 */
	private $saved_options;

	/**
	 * Network options saved around DDL.
	 *
	 * @var array
	 */
	private $saved_site_options;

	/**
	 * Keep every path on the fixture transport and private root.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->set_up_private_root();
		$this->saved_options = array();
		foreach ( array( 'mainwp_enableNativeBackups', 'mainwp_enableLegacyBackupFeature', 'mainwp_backup_manual_settle_seconds' ) as $name ) {
			$this->saved_options[ $name ] = get_option( $name );
		}
		$this->saved_site_options = array();
		foreach ( array( 'mainwp_db_version', MainWP_Install::NATIVE_BACKUP_TABLES_CHECKED ) as $name ) {
			$this->saved_site_options[ $name ] = get_site_option( $name );
		}
		update_option( 'mainwp_enableNativeBackups', 1 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		MainWP_Backup_Run_History::set_context( '', 0 );
		$key = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);
		openssl_pkey_export( $key, $this->private_key );
		$this->calls  = array();
		$this->answer = array(
			'phase' => 'completed',
			'file'  => 'history.zip',
			'size'  => 4096,
		);
		add_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'child_response' ), 10, 4 );
		add_filter( 'mainwp_download_to_file_pre', array( $this, 'download_response' ), 10, 3 );
	}

	/**
	 * DDL commits fixture rows, so remove them before the harness rolls back.
	 */
	public function tearDown(): void {
		global $wpdb;
		remove_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'child_response' ), 10 );
		remove_filter( 'mainwp_download_to_file_pre', array( $this, 'download_response' ), 10 );
		remove_action( 'mainwp_cronbackups_prune_action', array( MainWP_Backup_Run_History::class, 'prune' ) );
		MainWP_Backup_Run_History::set_context( '', 0 );
		foreach ( array( 'wp_backup_run_history', 'wp_backup_snapshot', 'wp_backup_restore_job', 'wp_backup_progress', 'wp_backup' ) as $key ) {
			$wpdb->query( 'DELETE FROM ' . MainWP_DB::instance()->get_table_name( $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed test tables.
		}
		foreach ( $this->saved_options as $name => $value ) {
			false === $value ? delete_option( $name ) : update_option( $name, $value );
		}
		foreach ( $this->saved_site_options as $name => $value ) {
			false === $value ? delete_site_option( $name ) : update_site_option( $name, $value );
		}
		$this->tear_down_private_root();
		parent::tearDown();
	}

	/**
	 * Refuse any unexpected network action.
	 *
	 * @param mixed  $pre Transport result.
	 * @param object $site Site row.
	 * @param string $what Child action.
	 * @param array  $params Request values.
	 * @return array
	 * @throws \RuntimeException Unexpected network action.
	 */
	public function child_response( $pre, $site, $what, $params ) {
		$this->calls[] = array( $what, (int) $site->id, $params );
		if ( 'backup_preflight' === $what ) {
			return array(
				'task_spine'  => 1,
				'zip_support' => true,
			);
		}
		if ( 'backup_task_create' === $what ) {
			return array( 'task_id' => 'history-child-' . $site->id );
		}
		if ( in_array( $what, array( 'backup_task_run', 'backup_task_status' ), true ) ) {
			return array_merge( array( 'task_id' => $params['task_id'] ?? '' ), $this->answer );
		}
		if ( in_array( $what, array( 'backup_task_cancel', 'delete_backup', 'backup_task_prune' ), true ) ) {
			return array(
				'result'  => 'ok',
				'pruned'  => 1,
				'active'  => 0,
				'missing' => 0,
			);
		}
		throw new \RuntimeException( 'Unexpected Child action: ' . $what );
	}

	/**
	 * Validated download bytes avoid a live Child dependency.
	 *
	 * @param mixed  $pre Transport result.
	 * @param string $url Download URL.
	 * @param string $file Local target.
	 * @return bool
	 */
	public function download_response( $pre, $url, $file ) {
		file_put_contents( $file, 'PK' . "\x03\x04" . str_repeat( 'x', 4092 ) );
		return true;
	}

	/**
	 * Give the download path a usable signing key.
	 *
	 * @return int
	 */
	private function site(): int {
		return $this->create_test_site(
			array(
				'userid'  => get_current_user_id(),
				'privkey' => base64_encode( $this->private_key ),
			)
		);
	}

	/**
	 * Persist a task so claiming and closing use their real SQL guards.
	 *
	 * @param array $sites Task scope.
	 * @return object
	 */
	private function task( array $sites ): object {
		$task = MainWP_DB_Backup::instance()->add_backup_task( get_current_user_id(), 'History fixture', 'daily', 'full', '', implode( ',', $sites ), '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		$this->assertIsObject( $task );
		return $task;
	}

	/**
	 * Let a claimed run finish through the same driver as cron continuation.
	 *
	 * @param object $task Task row.
	 */
	private function finish_task( object $task ): void {
		MainWP_Manage_Backups_Handler::execute_backup_task( MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id ), 5, false );
	}

	/**
	 * Read durable rows independently of the service's projection.
	 *
	 * @return array
	 */
	private function rows(): array {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_run_history' ) . ' ORDER BY id', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table.
	}

	/**
	 * Cron provenance uses the owner even when no Dashboard user is present.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_scheduled_lane_start_and_success_finalize(): void {
		define( 'DOING_CRON', true );
		$site = $this->site();
		$task = $this->task( array( $site ) );
		wp_set_current_user( 0 );
		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, true );
		$this->assertCount( 1, $this->rows(), 'A claimed cron run must have one history row per site.' );
		$this->finish_task( $task );
		$row      = $this->rows()[0];
		$snapshot = MainWP_Backup_Snapshots::instance()->get_latest_complete( $site, $task->id );
		$this->assertSame( 'schedule', $row['trigger_source'] );
		$this->assertSame( (int) $task->userid, (int) $row['initiator_user_id'] );
		$this->assertSame( 'completed', $row['outcome'] );
		$this->assertSame( (int) $snapshot->id, (int) $row['snapshot_id'] );
		$this->assertSame( (int) MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id )->last_run, (int) $row['started'] );
	}

	/**
	 * A hidden task carries the transport provenance and its own snapshot.
	 *
	 * @dataProvider operation_contexts
	 * @param string $context Calling transport.
	 * @param string $trigger Expected provenance.
	 */
	public function test_on_demand_lane_start_and_finalize( string $context, string $trigger ): void {
		$site   = $this->site();
		$result = MainWP_Backup_Operations::start_site_backup( $site, $context );
		$this->assertIsArray( $result );
		$this->assertCount( 1, $this->rows() );
		$task = MainWP_DB_Backup::instance()->get_manual_backup_task( $site );
		$this->finish_task( $task );
		$row = $this->rows()[0];
		$this->assertSame( $trigger, $row['trigger_source'] );
		$this->assertSame( get_current_user_id(), (int) $row['initiator_user_id'] );
		$this->assertSame( 'completed', $row['outcome'] );
		$this->assertNotNull( $row['snapshot_id'] );
		$this->assertNull( $row['ref'] );
		$this->assertNull( $row['restore_job_id'] );
	}

	/**
	 * Context is temporary so a later cron entry cannot inherit it.
	 *
	 * @dataProvider operation_contexts
	 * @param string $context Calling transport.
	 * @param string $trigger Expected provenance.
	 */
	public function test_run_now_lane_context( string $context, string $trigger ): void {
		$task = $this->task( array( $this->site() ) );
		$this->assertIsArray( MainWP_Backup_Operations::run_task( $task->id, $context ) );
		$this->finish_task( $task );
		$this->assertCount( 1, $this->rows() );
		$this->assertSame( $trigger, $this->rows()[0]['trigger_source'] );
		$this->assertSame( 'completed', $this->rows()[0]['outcome'] );
		$previous = MainWP_Backup_Run_History::set_context( '', 0 );
		$this->assertSame( '', $previous['trigger'] );
	}

	/**
	 * Transport expectations.
	 *
	 * @return array
	 */
	public static function operation_contexts(): array {
		return array(
			'UI'      => array( 'ui', 'manual' ),
			'ability' => array( 'ability', 'ability' ),
		);
	}

	/**
	 * Error marks count as finished sites but must remain failed in history.
	 */
	public function test_driver_error_finalize_and_safe_message(): void {
		$site = $this->site();
		$task = $this->task( array( $site ) );
		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, true );
		$this->answer = array(
			'phase'      => 'failed',
			'error'      => '/private/secret provider-token',
			'error_code' => MainWP_Backup_Error_Codes::ERR_ZIP_FAILED,
		);
		$this->finish_task( $task );
		$this->assertCount( 1, $this->rows() );
		$row  = $this->rows()[0];
		$safe = MainWP_Backup_Operations::safe_error( MainWP_Backup_Error_Codes::ERR_ZIP_FAILED );
		$this->assertSame( 'failed', $row['outcome'] );
		$this->assertSame( $safe['code'], $row['error_code'] );
		$this->assertSame( $safe['message'], $row['message'] );
		$this->assertNull( $row['snapshot_id'] );
	}

	/**
	 * A close with a retired site leaves an honest incomplete outcome.
	 */
	public function test_run_close_finalizes_remaining_rows_as_incomplete(): void {
		$sites        = array( $this->site(), $this->site() );
		$task         = $this->task( $sites );
		$this->answer = array( 'phase' => 'archiving_files' );
		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, true );
		$this->assertCount( 2, $this->rows() );
		$rows = $this->rows();
		MainWP_DB_Backup::instance()->update_completed_sites( $task->id, array_fill_keys( $sites, true ) );
		MainWP_DB_Backup::instance()->update_backup_task_progress(
			$task->id,
			$sites[0],
			array(
				'child_backup_task_id' => '',
				'child_phase'          => '',
			)
		);
		$this->finish_task( $task );
		$this->assertSame( array( 'incomplete', 'incomplete' ), array_column( $this->rows(), 'outcome' ) );
		$this->assertSame( array_column( $rows, 'started' ), array_column( $this->rows(), 'started' ) );
	}

	/**
	 * Only a confirmed Child cancellation can claim a cancelled outcome.
	 *
	 * @dataProvider cancel_answers
	 * @param array  $answer Child response.
	 * @param string $outcome Expected history outcome.
	 */
	public function test_cancel_lane( array $answer, string $outcome ): void {
		$site = $this->site();
		MainWP_Backup_Operations::start_site_backup( $site, 'ui' );
		$override = static function ( $pre, $website, $what ) use ( $answer ) {
			return 'backup_task_cancel' === $what ? $answer : $pre;
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $override, 20, 3 );
		try {
			MainWP_Backup_Operations::cancel_site_backup( $site );
			$this->assertCount( 1, $this->rows() );
			$this->assertSame( $outcome, $this->rows()[0]['outcome'] );
		} finally {
			remove_filter( 'mainwp_fetch_url_authed_pre', $override, 20 );
		}
	}

	/**
	 * Confirmation and refusal responses.
	 *
	 * @return array
	 */
	public static function cancel_answers(): array {
		return array(
			'confirmed' => array( array( 'result' => 'ok' ), 'cancelled' ),
			'finished'  => array(
				array(
					'result' => 'already_terminal',
					'phase'  => 'completed',
				),
				'running',
			),
			'busy'      => array( array( 'result' => 'busy' ), 'running' ),
			'failure'   => array( array( 'error' => 'secret' ), 'running' ),
		);
	}

	/**
	 * Reset closes every running site while preserving earlier terminal rows.
	 */
	public function test_reset_lane(): void {
		$sites = array( $this->site(), $this->site() );
		$task  = $this->task( $sites );
		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, true );
		$this->assertCount( 2, $this->rows() );
		$started = time() - 7200;
		MainWP_DB_Backup::instance()->update_backup_task_with_values( $task->id, array( 'last_run' => $started ) );
		global $wpdb;
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_run_history' ), array( 'started' => $started ), array( 'task_id' => $task->id ) );
		$this->assertSame( 'reset', MainWP_Backup_Operations::reset_task_run( $task->id )['status'] );
		$this->assertSame( array( 'completed', 'reset' ), array_column( $this->rows(), 'outcome' ) );
	}

	/**
	 * Ad-hoc pre-update backups have a download, never a snapshot record.
	 *
	 * @dataProvider terminal_answers
	 * @param bool $success Child terminal verdict.
	 */
	public function test_update_gate_lane( bool $success ): void {
		$site = $this->site();
		$ref  = MainWP_Backup_Executor::trigger_backup( $site );
		$this->assertSame( $ref, MainWP_Backup_Executor::trigger_backup( $site ), 'Resuming the handle must not duplicate history.' );
		$this->assertCount( 1, $this->rows() );
		if ( ! $success ) {
			$this->answer = array(
				'phase'      => 'failed',
				'error'      => '/secret-provider',
				'error_code' => MainWP_Backup_Error_Codes::ERR_ZIP_FAILED,
			);
		}
		MainWP_Backup_Executor::get_backup_task_status( $site, $ref, 1 );
		$row = $this->rows()[0];
		$this->assertSame( 'update_gate', $row['trigger_source'] );
		$this->assertSame( 0, (int) $row['task_id'] );
		$this->assertSame( $ref, $row['ref'] );
		$this->assertSame( $success ? 'completed' : 'failed', $row['outcome'] );
		$this->assertSame( $success ? 'Pre-update backup downloaded; no snapshot recorded.' : MainWP_Backup_Operations::safe_error( MainWP_Backup_Error_Codes::ERR_ZIP_FAILED )['message'], $row['message'] );
		$this->assertNull( $row['snapshot_id'] );
		$this->assertNull( $row['restore_job_id'] );
	}

	/**
	 * The restore job owns safety evidence once its artifact checkpoint lands.
	 *
	 * @dataProvider terminal_answers
	 * @param bool $success Child terminal verdict.
	 */
	public function test_restore_safety_lane( bool $success ): void {
		$site  = $this->site();
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$id    = $store->create_job(
			$site,
			5,
			array(
				'plan'    => array(),
				'options' => array( 'pre_snapshot' => true ),
			)
		);
		$store->set_status( $id, $store::STATUS_PREFLIGHT );
		$orchestrator = MainWP_Backup_Restore_Orchestrator::instance();
		$orchestrator->run_job( $store->get_job( $id ), microtime( true ) + 2 );
		$this->assertCount( 1, $this->rows() );
		$job = $store->get_job( $id );
		$this->assertSame( $store::STATUS_PRE_SNAPSHOT, $job->status );
		if ( ! $success ) {
			$this->answer = array(
				'phase'      => 'failed',
				'error'      => '/secret-provider',
				'error_code' => MainWP_Backup_Error_Codes::ERR_ZIP_FAILED,
			);
		}
		$orchestrator->run_job( $job, microtime( true ) + 2 );
		$row = $this->rows()[0];
		$this->assertSame( 'restore_safety', $row['trigger_source'] );
		$this->assertSame( (int) $id, (int) $row['restore_job_id'] );
		$this->assertSame( $job->pre_snapshot_task_id, $row['ref'] );
		$this->assertSame( $success ? 'completed' : 'failed', $row['outcome'] );
		$this->assertSame( $success ? $store::STATUS_STAGING : $store::STATUS_FAILED_PERMANENT, $store->get_job( $id )->status );
		$this->assertStringNotContainsString( 'secret-provider', $row['message'] );
	}

	/**
	 * Success and failure paths.
	 *
	 * @return array
	 */
	public static function terminal_answers(): array {
		return array(
			'success' => array( true ),
			'failure' => array( false ),
		);
	}

	/**
	 * Auto-updates retry a failed download on the same persisted Child handle.
	 */
	public function test_update_gate_download_retry_stays_running(): void {
		$site = $this->site();
		$ref  = MainWP_Backup_Executor::trigger_backup( $site );
		$fail = static function () {
			throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_TIMEOUT );
		};
		add_filter( 'mainwp_download_to_file_pre', $fail, 20 );
		try {
			try {
				MainWP_Backup_Executor::get_backup_task_status( $site, $ref, 1 );
				$this->fail( 'The scripted download must throw.' );
			} catch ( \MainWP\Dashboard\MainWP_Exception $e ) {
				$this->assertSame( MainWP_Backup_Error_Codes::ERR_TIMEOUT, $e->get_message_error_code() );
			}
			$this->assertSame( 'running', $this->rows()[0]['outcome'] );
		} finally {
			remove_filter( 'mainwp_download_to_file_pre', $fail, 20 );
		}
		$this->assertSame( $ref, MainWP_Backup_Executor::trigger_backup( $site ) );
		MainWP_Backup_Executor::get_backup_task_status( $site, $ref, 1 );
		$this->assertCount( 1, $this->rows() );
		$this->assertSame( 'completed', $this->rows()[0]['outcome'] );
	}

	/**
	 * Observing completion cannot claim the download that a passive poll skips.
	 */
	public function test_update_gate_passive_poll_waits_for_download(): void {
		$site = $this->site();
		$ref  = MainWP_Backup_Executor::trigger_backup( $site );
		MainWP_Backup_Executor::get_backup_task_status( $site, $ref );
		$this->assertSame( 'running', $this->rows()[0]['outcome'] );
		MainWP_Backup_Executor::get_backup_task_status( $site, $ref, 1 );
		$this->assertSame( 'completed', $this->rows()[0]['outcome'] );
	}

	/**
	 * A Child failure is terminal even when the poll has no driving budget.
	 */
	public function test_update_gate_passive_failure_finalizes(): void {
		$site         = $this->site();
		$ref          = MainWP_Backup_Executor::trigger_backup( $site );
		$this->answer = array(
			'phase'      => 'failed',
			'error'      => '/secret-provider',
			'error_code' => MainWP_Backup_Error_Codes::ERR_ZIP_FAILED,
		);
		MainWP_Backup_Executor::get_backup_task_status( $site, $ref );
		$this->assertSame( 'failed', $this->rows()[0]['outcome'] );
		$this->assertSame( MainWP_Backup_Operations::safe_error( MainWP_Backup_Error_Codes::ERR_ZIP_FAILED )['message'], $this->rows()[0]['message'] );
	}

	/**
	 * A late terminal result cannot overwrite the first recorded verdict.
	 */
	public function test_finalize_no_op_and_unclassified_error(): void {
		$id = MainWP_Backup_Run_History::start( 1, $this->site(), time() );
		$this->assertTrue( MainWP_Backup_Run_History::finalize( $id, 'failed', '/secret unknown' ) );
		$before = $this->rows();
		$this->assertFalse( MainWP_Backup_Run_History::finalize( $id, 'completed', '', 999 ) );
		$this->assertSame( $before, $this->rows() );
		$this->assertSame( MainWP_Backup_Operations::safe_error( '' )['message'], $before[0]['message'] );
		$this->assertSame( MainWP_Backup_Error_Codes::ERR_UNCLASSIFIED_ERROR, $before[0]['error_code'] );
	}

	/**
	 * Totals and offsets must never include another user's sites.
	 */
	public function test_query_authorized_rows_filters_and_safe_errors(): void {
		$site = $this->site();
		$deny = $this->site();
		$ids  = array();
		foreach ( array( $deny, $site, $deny, $site ) as $index => $id ) {
			$ids[] = MainWP_Backup_Run_History::start( 7, $id, time() - $index );
		}
		MainWP_Backup_Run_History::finalize( $ids[1], 'failed', MainWP_Backup_Error_Codes::ERR_ZIP_FAILED );
		global $wpdb;
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_run_history' ), array( 'message' => '/secret-path' ), array( 'id' => $ids[1] ) );
		$allowed = MainWP_Backup_Operations::authorized_site_ids( 'execute_backups' );
		$allowed = array_values( array_diff( $allowed, array( $deny ) ) );
		$result  = MainWP_Backup_Run_History::query( array(), 2, 1, $allowed );
		$this->assertSame( 2, $result['total'] );
		$this->assertCount( 1, $result['items'] );
		$this->assertSame( $ids[3], (int) $result['items'][0]['id'] );
		$result = MainWP_Backup_Run_History::query(
			array(
				'task_id' => 7,
				'site_id' => $site,
				'outcome' => 'failed',
				'trigger' => 'manual',
				'days'    => 90,
			),
			1,
			10,
			$allowed
		);
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( MainWP_Backup_Operations::safe_error( MainWP_Backup_Error_Codes::ERR_ZIP_FAILED ), $result['items'][0]['error'] );
		$this->assertStringNotContainsString( 'secret-path', wp_json_encode( $result ) );
		$this->assertSame(
			array(
				'items' => array(),
				'total' => 0,
			),
			MainWP_Backup_Run_History::query( array(), 1, 10, array() )
		);
		$this->assertSame( 0, MainWP_Backup_Run_History::query( array( 'site_id' => $deny ), 1, 10, $allowed )['total'] );
		$this->assertSame( 0, MainWP_Backup_Run_History::query( array( 'outcome' => "failed' OR 1=1 --" ), 1, 10, $allowed )['total'] );
	}

	/**
	 * Seed against the actual prepared cutoff to avoid a clock-tick race.
	 */
	public function test_prune_exact_boundary_and_batches(): void {
		global $wpdb;
		$table   = MainWP_DB::instance()->get_table_name( 'wp_backup_run_history' );
		$batches = 0;
		$seed    = static function ( $sql ) use ( $wpdb, $table, &$batches ) {
			if ( 0 === strpos( $sql, 'DELETE FROM ' . $table . ' WHERE (finished' ) ) {
				++$batches;
				if ( 1 === $batches && preg_match( '/finished < (\d+)/', $sql, $match ) ) {
					$cutoff = (int) $match[1];
					foreach ( array( array( 'completed', $cutoff - 1, $cutoff - 1 ), array( 'completed', $cutoff - 1, $cutoff ), array( 'running', $cutoff - 1, 0 ), array( 'running', $cutoff, 0 ), array( 'incomplete', $cutoff - 1, 0 ) ) as $row ) {
						$wpdb->insert(
							$table,
							array(
								'site_id'  => 1,
								'outcome'  => $row[0],
								'started'  => $row[1],
								'finished' => $row[2],
								'message'  => '',
							)
						);
					}
					for ( $i = 0; $i < 1000; ++$i ) {
						$wpdb->insert(
							$table,
							array(
								'site_id'  => 1,
								'outcome'  => 'completed',
								'started'  => $cutoff - 2,
								'finished' => $cutoff - 1,
								'message'  => '',
							)
						);
					}
				}
			}
			return $sql;
		};
		add_filter( 'query', $seed );
		try {
			MainWP_Backup_Run_History::prune();
			$this->assertSame( 2, $batches );
			$this->assertSame( array( 'completed', 'running', 'incomplete' ), array_column( $this->rows(), 'outcome' ) );
		} finally {
			remove_filter( 'query', $seed );
		}
	}

	/**
	 * Both WP-Cron and the server-cron entry must run history retention.
	 */
	public function test_prune_hook_and_server_cron_entry(): void {
		$old = time() - 91 * DAY_IN_SECONDS;
		$id  = MainWP_Backup_Run_History::start( 1, $this->site(), $old );
		MainWP_Backup_Run_History::init();
		$this->assertNotFalse( has_action( 'mainwp_cronbackups_prune_action', array( MainWP_Backup_Run_History::class, 'prune' ) ) );
		do_action( 'mainwp_cronbackups_prune_action' );
		$this->assertSame( array(), $this->rows() );
		MainWP_Backup_Run_History::start( 1, $this->site(), $old );
		MainWP_System::instance()->mainwp_cronbackups_prune_action();
		$this->assertSame( array(), $this->rows() );
		update_option( 'mainwp_enableNativeBackups', 0 );
		$this->assertSame( 0, MainWP_Backup_Run_History::start( 1, 1, time() ) );
		$this->assertGreaterThan( 0, $id );
	}

	/**
	 * Table creation must use real DDL, including on a current-version retry.
	 *
	 * @dataProvider install_paths
	 * @param string|false $version Stored schema version.
	 */
	public function test_table_creation_fresh_and_hourly_retry( $version ): void {
		global $wpdb;
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_run_history' );
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		try {
			$wpdb->query( 'DROP TABLE ' . $table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed test table.
			false === $version ? delete_site_option( 'mainwp_db_version' ) : update_site_option( 'mainwp_db_version', $version );
			update_site_option( MainWP_Install::NATIVE_BACKUP_TABLES_CHECKED, time() - HOUR_IN_SECONDS - 1 );
			MainWP_Install::instance()->install();
			$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) );
			$this->assertSame( '9.0.2.30', get_site_option( 'mainwp_db_version' ) );
			$this->assertCount( 13, $wpdb->get_results( 'SHOW COLUMNS FROM ' . $table ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed test table.
		} finally {
			update_site_option( 'mainwp_db_version', '9.0.2.29' );
			MainWP_Install::instance()->install();
			foreach ( $this->saved_options as $name => $value ) {
				false === $value ? delete_option( $name ) : update_option( $name, $value );
			}
			foreach ( $this->saved_site_options as $name => $value ) {
				false === $value ? delete_site_option( $name ) : update_site_option( $name, $value );
			}
			self::commit_transaction();
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}

	/**
	 * Fresh install and current-version retry.
	 *
	 * @return array
	 */
	public static function install_paths(): array {
		return array(
			'fresh'        => array( false ),
			'hourly retry' => array( '9.0.2.30' ),
		);
	}

	/**
	 * A failed insert and terminal update must leave the backup functional.
	 */
	public function test_history_database_failures_do_not_break_backup(): void {
		$site     = $this->site();
		$task     = $this->task( array( $site ) );
		$logger   = MainWP_Logger::instance();
		$priority = new \ReflectionProperty( $logger, 'logPriority' );
		$previous = $priority->getValue( $logger );
		$priority->setValue( $logger, MainWP_Logger::WARNING );
		$logs  = array();
		$log   = static function ( $data ) use ( &$logs ) {
			$logs[] = wp_json_encode( $data );
		};
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_run_history' );
		$deny  = static function ( $sql ) use ( $table ) {
			if ( preg_match( '/^(INSERT INTO|UPDATE) `?' . preg_quote( $table, '/' ) . '`? /', $sql ) ) {
				return 'SELECT mainwp_history_forced_database_failure';
			}
			return $sql;
		};
		add_filter( 'mainwp_log_do_to_db', '__return_true' );
		add_action( 'mainwp_before_log_data', $log );
		add_filter( 'query', $deny );
		try {
			MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, true );
			$this->finish_task( $task );
			$this->assertIsObject( MainWP_Backup_Snapshots::instance()->get_latest_complete( $site, $task->id ) );
			$this->assertGreaterThanOrEqual( (int) MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id )->last_run, (int) MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id )->completed );
			$this->assertStringContainsString( 'Backup run history write failed: start', implode( '\n', $logs ) );
			remove_filter( 'query', $deny );
			$id = MainWP_Backup_Run_History::start( $task->id, $site, time() );
			add_filter( 'query', $deny );
			$this->assertFalse( MainWP_Backup_Run_History::finalize( $id, 'failed' ) );
			$this->assertStringContainsString( 'Backup run history write failed: finalize', implode( '\n', $logs ) );
			$this->assertSame( 'running', $this->rows()[0]['outcome'] );
		} finally {
			remove_filter( 'query', $deny );
			remove_action( 'mainwp_before_log_data', $log );
			remove_filter( 'mainwp_log_do_to_db', '__return_true' );
			$priority->setValue( $logger, $previous );
		}
	}
}
