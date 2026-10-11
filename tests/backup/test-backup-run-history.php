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
	 * Malformed Child codes must not warn on the progress request.
	 */
	public function test_driver_array_error_code_finalizes_without_warning(): void {
		$site = $this->site();
		$task = $this->task( array( $site ) );
		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, true );
		$this->answer = array(
			'phase' => 'failed',
			'error' => '/private/secret provider-token',
		);
		$malformed    = static function ( $task_id, $site_id, $outcome ) {
			if ( 'error' === $outcome ) {
				MainWP_DB_Backup::instance()->update_backup_task_progress(
					$task_id,
					$site_id,
					array( 'fetchResult' => wp_json_encode( array( 'error_code' => array( 'unexpected' ) ) ) )
				);
			}
		};
		$warnings     = array();
		add_action( 'mainwp_backup_diag_site_mark', $malformed, 10, 3 );
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Warnings must remain observable even if a driver contains them.
			static function ( $severity, $message ) use ( &$warnings ) {
				$warnings[] = $message;
				return true;
			},
			E_WARNING | E_NOTICE
		);
		try {
			$this->finish_task( $task );
		} finally {
			restore_error_handler();
			remove_action( 'mainwp_backup_diag_site_mark', $malformed, 10 );
		}
		$this->assertSame( array(), $warnings );
		$this->assertIsArray( MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $site )->fetchResult['error_code'] );
		$row  = $this->rows()[0];
		$safe = MainWP_Backup_Operations::safe_error( '' );
		$this->assertSame( 'failed', $row['outcome'] );
		$this->assertSame( $safe['code'], $row['error_code'] );
		$this->assertSame( $safe['message'], $row['message'] );
	}

	/**
	 * Revoked scope retires the open run before any further site work.
	 */
	public function test_rescope_pause_finalizes_open_history(): void {
		$sites = array( $this->site(), $this->site() );
		$task  = $this->task( $sites );
		MainWP_Manage_Backups_Handler::stamp_task_scope_snapshot( $task->id, $task->sites, $task->groups );
		$this->answer = array( 'phase' => 'archiving_files' );
		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, true );
		$this->assertSame( array( 'running', 'running' ), array_column( $this->rows(), 'outcome' ) );
		$open = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertGreaterThan( (int) $open->completed, (int) $open->last_run );
		$allowed = static function () use ( $sites ) {
			return array( $sites[0] );
		};
		add_filter( 'mainwp_currentuserallowedaccesssites', $allowed );
		try {
			$this->assertSame( 'narrowed', MainWP_Manage_Backups_Handler::resolve_task_scope_pause( $open ) );
			MainWP_Manage_Backups_Handler::pause_task_for_rescope( $task );
		} finally {
			remove_filter( 'mainwp_currentuserallowedaccesssites', $allowed );
		}
		$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertSame( 1, (int) $fresh->paused );
		$this->assertSame( (int) $open->last_run, (int) $fresh->completed );
		$this->assertSame( array( 'incomplete', 'incomplete' ), array_column( $this->rows(), 'outcome' ) );
	}

	/**
	 * A zero-grace re-claim replaces the run identity without losing evidence.
	 */
	public function test_zero_grace_reclaim_finalizes_only_superseded_history(): void {
		$site         = $this->site();
		$task         = $this->task( array( $site ) );
		$this->answer = array( 'phase' => 'archiving_files' );
		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, true );
		$this->assertSame( 'running', $this->rows()[0]['outcome'] );
		$first = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertGreaterThan( (int) $first->completed, (int) $first->last_run );
		$other = $this->task( array( $site ) );
		MainWP_Backup_Run_History::start( $other->id, $site, (int) $first->last_run );
		$finished = MainWP_Backup_Run_History::start( $task->id, $site, (int) $first->last_run - 1 );
		MainWP_Backup_Run_History::finalize( $finished, 'completed' );
		MainWP_Manage_Backups_Handler::execute_backup_task( $first, 1, true, 0 );
		$second = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$rows   = $this->rows();
		$this->assertGreaterThan( (int) $first->last_run, (int) $second->last_run );
		$this->assertCount( 4, $rows );
		$this->assertSame( array( 'incomplete', 'running', 'completed', 'running' ), array_column( $rows, 'outcome' ) );
		$this->assertSame( (int) $first->last_run, (int) $rows[0]['started'] );
		$this->assertSame( (int) $second->last_run, (int) $rows[3]['started'] );
		$this->assertSame( (int) $task->id, (int) $rows[3]['task_id'] );
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
	 * A refused task close leaves the driver able to record the real outcome.
	 *
	 * @dataProvider refused_task_close_lanes
	 * @param string $lane Reset or scope pause.
	 * @param bool   $success Later Child verdict.
	 */
	public function test_refused_task_close_preserves_history_for_driver( string $lane, bool $success ): void {
		global $wpdb;
		$sites = array( $this->site(), $this->site() );
		$task  = $this->task( $sites );
		MainWP_Manage_Backups_Handler::stamp_task_scope_snapshot( $task->id, $task->sites, $task->groups );
		$this->answer = array( 'phase' => 'archiving_files' );
		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, true );
		$started = time() - 7200;
		MainWP_DB_Backup::instance()->update_backup_task_with_values( $task->id, array( 'last_run' => $started ) );
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_run_history' ), array( 'started' => $started ), array( 'task_id' => $task->id ) );
		$this->assertSame( array( 'running', 'running' ), array_column( $this->rows(), 'outcome' ) );
		$table   = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$refused = false;
		$deny    = static function ( $query ) use ( $table, &$refused ) {
			if ( preg_match( '/^UPDATE `?' . preg_quote( $table, '/' ) . '`? /', $query ) && false !== strpos( $query, '`completed`' ) ) {
				$refused = true;
				return 'SELECT * FROM missing_table_history_task_close';
			}
			return $query;
		};
		$allowed = static function () use ( $sites ) {
			return array( $sites[0] );
		};
		$busy    = static function ( $pre, $child, $what ) {
			return 'backup_task_cancel' === $what ? array( 'result' => 'busy' ) : $pre;
		};
		add_filter( 'query', $deny );
		add_filter( 'mainwp_fetch_url_authed_pre', $busy, 20, 3 );
		$suppress = $wpdb->suppress_errors( true );
		try {
			if ( 'reset' === $lane ) {
				$this->assertSame(
					array(
						'status'            => 'reset',
						'cancelled'         => 0,
						'uncancelled'       => 2,
						'sites_uncancelled' => $sites,
					),
					MainWP_Backup_Operations::reset_task_run( $task->id )
				);
			} else {
				add_filter( 'mainwp_currentuserallowedaccesssites', $allowed );
				MainWP_Manage_Backups_Handler::pause_task_for_rescope( $task );
			}
		} finally {
			$wpdb->suppress_errors( $suppress );
			remove_filter( 'query', $deny );
			remove_filter( 'mainwp_fetch_url_authed_pre', $busy, 20 );
			remove_filter( 'mainwp_currentuserallowedaccesssites', $allowed );
		}
		$this->assertTrue( $refused, 'The task close must reach the refused UPDATE.' );
		$open = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertSame( $started, (int) $open->last_run );
		$this->assertLessThan( $started, (int) $open->completed );
		$this->assertSame( 0, (int) $open->paused );
		$this->assertSame( array( 'running', 'running' ), array_column( $this->rows(), 'outcome' ) );
		$this->answer = $success ? array(
			'phase' => 'completed',
			'file'  => 'history.zip',
			'size'  => 4096,
		) : array(
			'phase'      => 'failed',
			'error_code' => MainWP_Backup_Error_Codes::ERR_ZIP_FAILED,
		);
		$this->finish_task( $task );
		$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertSame( $started, (int) $fresh->last_run );
		$this->assertGreaterThanOrEqual( $started, (int) $fresh->completed );
		$this->assertSame( array( $success ? 'completed' : 'failed', $success ? 'completed' : 'failed' ), array_column( $this->rows(), 'outcome' ) );
		foreach ( $this->rows() as $row ) {
			$this->assertSame( $started, (int) $row['started'] );
			$this->assertSame( $success ? '' : MainWP_Backup_Error_Codes::ERR_ZIP_FAILED, $row['error_code'] );
		}
	}

	/**
	 * Each refused close must preserve evidence for a later terminal verdict.
	 *
	 * @return array
	 */
	public static function refused_task_close_lanes(): array {
		return array(
			'reset then completed' => array( 'reset', true ),
			'pause then failed'    => array( 'pause', false ),
		);
	}

	/**
	 * A thrown fault in the history-only confirmation read cannot interrupt the close.
	 *
	 * @dataProvider confirmation_read_fault_lanes
	 * @param string $lane Reset or scope pause.
	 */
	public function test_confirmation_read_fault_does_not_interrupt_close( string $lane ): void {
		global $wpdb;
		$sites = array( $this->site(), $this->site() );
		$task  = $this->task( $sites );
		MainWP_Manage_Backups_Handler::stamp_task_scope_snapshot( $task->id, $task->sites, $task->groups );
		$this->answer = array( 'phase' => 'archiving_files' );
		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, true );
		$started = time() - 7200;
		MainWP_DB_Backup::instance()->update_backup_task_with_values( $task->id, array( 'last_run' => $started ) );
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_run_history' ), array( 'started' => $started ), array( 'task_id' => $task->id ) );
		$table   = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$closed  = false;
		$thrown  = false;
		$fault   = static function ( $query ) use ( $table, &$closed, &$thrown ) {
			if ( preg_match( '/^UPDATE `?' . preg_quote( $table, '/' ) . '`? /', $query ) && false !== strpos( $query, '`completed`' ) ) {
				$closed = true;
			} elseif ( $closed && ! $thrown && 0 === stripos( ltrim( $query ), 'SELECT * FROM ' . $table . ' ' ) ) {
				$thrown = true;
				throw new \RuntimeException( 'adapter fault' );
			}
			return $query;
		};
		$allowed = static function () use ( $sites ) {
			return array( $sites[0] );
		};
		$busy    = static function ( $pre, $child, $what ) {
			return 'backup_task_cancel' === $what ? array( 'result' => 'busy' ) : $pre;
		};
		$paused  = 0;
		$hook    = static function () use ( &$paused ) {
			++$paused;
		};
		add_filter( 'query', $fault );
		add_filter( 'mainwp_fetch_url_authed_pre', $busy, 20, 3 );
		add_action( 'mainwp_backup_task_auto_paused', $hook );
		try {
			if ( 'reset' === $lane ) {
				$this->assertSame(
					array(
						'status'            => 'reset',
						'cancelled'         => 0,
						'uncancelled'       => 2,
						'sites_uncancelled' => $sites,
					),
					MainWP_Backup_Operations::reset_task_run( $task->id )
				);
			} else {
				add_filter( 'mainwp_currentuserallowedaccesssites', $allowed );
				MainWP_Manage_Backups_Handler::pause_task_for_rescope( $task );
			}
		} finally {
			remove_filter( 'query', $fault );
			remove_filter( 'mainwp_fetch_url_authed_pre', $busy, 20 );
			remove_filter( 'mainwp_currentuserallowedaccesssites', $allowed );
			remove_action( 'mainwp_backup_task_auto_paused', $hook );
		}
		$this->assertTrue( $thrown, 'The fault must hit the confirmation read.' );
		$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertGreaterThanOrEqual( $started, (int) $fresh->completed );
		if ( 'pause' === $lane ) {
			$this->assertSame( 1, (int) $fresh->paused );
			$this->assertStringContainsString( 'Re-save the task to confirm its scope', (string) $fresh->backup_notices );
			$this->assertSame( 1, $paused );
		}
		$this->assertSame( array( 'running', 'running' ), array_column( $this->rows(), 'outcome' ) );
	}

	/**
	 * Closes whose history finalize waits on a confirmation read.
	 *
	 * @return array
	 */
	public static function confirmation_read_fault_lanes(): array {
		return array(
			'stale reset' => array( 'reset' ),
			'scope pause' => array( 'pause' ),
		);
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
	 * Terminal restore evidence cannot depend on a live site or owned handle.
	 *
	 * @dataProvider restore_terminal_history_cases
	 * @param bool   $cancelled User cancellation rather than permanent failure.
	 * @param string $handle_state Owned, foreign, deleted site or completed safety backup.
	 */
	public function test_restore_terminal_failure_finalizes_safety_history( bool $cancelled, string $handle_state ): void {
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
		$ref   = MainWP_Backup_Executor::trigger_backup( $site, 'full', (string) $id );
		if ( 'preflight' === $handle_state ) {
			// The handle and its row exist before the job stores the Child task id.
			$this->assertTrue( $store->set_status( $id, $store::STATUS_PREFLIGHT ) );
			$this->assertEmpty( $store->get_job( $id )->pre_snapshot_task_id );
		} else {
			$this->assertTrue( $store->set_status( $id, $store::STATUS_PRE_SNAPSHOT, array( 'pre_snapshot_task_id' => $ref ) ) );
		}
		$this->assertSame( 'running', $this->rows()[0]['outcome'] );
		$orchestrator = MainWP_Backup_Restore_Orchestrator::instance();
		$before       = null;
		if ( 'completed' === $handle_state ) {
			$orchestrator->run_job( $store->get_job( $id ), microtime( true ) + 2 );
			$this->assertSame( 'completed', $this->rows()[0]['outcome'] );
			$before = $this->rows();
		} elseif ( 'foreign' === $handle_state ) {
			$website         = MainWP_DB::instance()->get_website_by_id( $site );
			$handle          = json_decode( MainWP_DB::instance()->get_website_option( $website, MainWP_Backup_Executor::ADHOC_TASK_OPTION ), true );
			$handle['owner'] = 'another-job';
			MainWP_DB::instance()->update_website_option( $website, MainWP_Backup_Executor::ADHOC_TASK_OPTION, wp_json_encode( $handle ) );
		} elseif ( 'deleted' === $handle_state ) {
			global $wpdb;
			$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp' ), array( 'id' => $site ) );
			$this->assertEmpty( MainWP_DB::instance()->get_website_by_id( $site ) );
		}
		if ( $cancelled ) {
			$this->assertSame( 'cancelled', $orchestrator->cancel_job( $id ) );
		} else {
			$terminal = new \ReflectionMethod( $orchestrator, 'record_terminal_failure' );
			$terminal->setAccessible( true );
			$this->assertSame(
				$orchestrator::TERMINAL_OUTCOME_TERMINAL,
				$terminal->invoke( $orchestrator, $store->get_job( $id ), '/private/secret provider-token', MainWP_Backup_Error_Codes::ERR_TIMEOUT, null )
			);
		}
		$this->assertSame( $cancelled ? $store::STATUS_CANCELLED : $store::STATUS_FAILED_PERMANENT, $store->get_job( $id )->status );
		if ( null !== $before ) {
			$this->assertSame( $before, $this->rows() );
			return;
		}
		$row = $this->rows()[0];
		$this->assertSame( $cancelled ? 'cancelled' : 'failed', $row['outcome'] );
		$this->assertSame( $cancelled ? '' : MainWP_Backup_Error_Codes::ERR_TIMEOUT, $row['error_code'] );
		$this->assertSame( $cancelled ? '' : MainWP_Backup_Operations::safe_error( MainWP_Backup_Error_Codes::ERR_TIMEOUT )['message'], $row['message'] );
		$this->assertGreaterThan( 0, (int) $row['finished'] );
	}

	/**
	 * Site and handle loss must preserve the same terminal verdict.
	 *
	 * @return array
	 */
	public static function restore_terminal_history_cases(): array {
		return array(
			'cancel owned'      => array( true, 'owned' ),
			'cancel foreign'    => array( true, 'foreign' ),
			'cancel deleted'    => array( true, 'deleted' ),
			'failure owned'     => array( false, 'owned' ),
			'failure foreign'   => array( false, 'foreign' ),
			'failure deleted'   => array( false, 'deleted' ),
			'failure later'     => array( false, 'completed' ),
			'cancel preflight'  => array( true, 'preflight' ),
			'failure preflight' => array( false, 'preflight' ),
		);
	}

	/**
	 * One site's terminal record, across every shape the driver can hand over.
	 *
	 * @dataProvider site_history_matrix
	 * @param bool        $success Site mark.
	 * @param int         $claim_offset Claim wall-clock time relative to the run identity.
	 * @param int|null    $snapshot_offset Snapshot created time relative to the run identity, null for none.
	 * @param mixed       $fetch_code Child error_code stored on the progress row.
	 * @param string      $last_error Progress last_error text.
	 * @param bool        $linked Whether the snapshot belongs to this run.
	 * @param string|null $code Expected stored error code, null for the unclassified code.
	 */
	public function test_site_history_matrix( bool $success, int $claim_offset, ?int $snapshot_offset, $fetch_code, string $last_error, bool $linked, ?string $code ): void {
		global $wpdb;
		$site    = $this->site();
		$task    = $this->task( array( $site ) );
		$started = time() - 100;
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			$task->id,
			array(
				'last_run' => $started,
				'last'     => $started + $claim_offset,
			)
		);
		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
		$this->assertGreaterThan( 0, MainWP_Backup_Run_History::start( (int) $task->id, $site, $started ) );
		$snapshot_id = null;
		if ( null !== $snapshot_offset ) {
			$snapshot_id = MainWP_Backup_Snapshots::instance()->record_snapshot(
				array(
					'task_id' => $task->id,
					'site_id' => $site,
				)
			);
			$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'created' => $started + $snapshot_offset ), array( 'id' => $snapshot_id ) );
		}
		MainWP_DB_Backup::instance()->add_backup_task_progress( $task->id, $site, array() );
		MainWP_DB_Backup::instance()->update_backup_task_progress(
			$task->id,
			$site,
			array(
				'fetchResult' => wp_json_encode( array( 'error_code' => $fetch_code ) ),
				'last_error'  => $last_error,
			)
		);
		$warnings = array();
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Warnings must remain observable.
			static function ( $severity, $message ) use ( &$warnings ) {
				$warnings[] = $message;
				return true;
			},
			E_WARNING | E_NOTICE
		);
		try {
			$finalize = new \ReflectionMethod( MainWP_Manage_Backups_Handler::class, 'finalize_site_history' );
			$finalize->setAccessible( true );
			$finalize->invoke( null, $task, $site, $started, $success );
		} finally {
			restore_error_handler();
		}
		$this->assertSame( array(), $warnings );
		$row = $this->rows()[0];
		$this->assertSame( $success ? 'completed' : 'failed', $row['outcome'] );
		$this->assertSame( $linked ? (int) $snapshot_id : 0, (int) $row['snapshot_id'] );
		$expected = $success ? '' : MainWP_Backup_Operations::safe_error( (string) $code )['code'];
		$this->assertSame( $expected, $row['error_code'] );
	}

	/**
	 * Rows: success with this run's snapshot; the claim second before a run
	 * identity bumped by GREATEST; a snapshot older than the claim; no snapshot;
	 * failures with a string code, a non-string code, and a code only in last_error.
	 *
	 * @return array
	 */
	public static function site_history_matrix(): array {
		return array(
			'snapshot after start'      => array( true, 0, 3, '', '', true, null ),
			'snapshot in claim second'  => array( true, -1, -1, '', '', true, null ),
			'snapshot before claim'     => array( true, 0, -5, '', '', false, null ),
			'no snapshot'               => array( true, 0, null, '', '', false, null ),
			'failure string code'       => array( false, 0, null, MainWP_Backup_Error_Codes::ERR_TIMEOUT, '', false, MainWP_Backup_Error_Codes::ERR_TIMEOUT ),
			'failure array code'        => array( false, 0, null, array( 'unexpected' ), '', false, '' ),
			'failure code in lasterror' => array( false, 0, null, '', '[' . MainWP_Backup_Error_Codes::ERR_TIMEOUT . '] Child timed out', false, MainWP_Backup_Error_Codes::ERR_TIMEOUT ),
		);
	}

	/**
	 * Displacing an abandoned handle, across every way the replacement can end.
	 *
	 * @dataProvider displacement_matrix
	 * @param string $mode Replacement or refusal case.
	 * @param string $old_outcome Expected outcome of the displaced row.
	 * @param bool   $new_row Whether the replacement opens a row.
	 * @param string $owner_status Status of the displaced restore owner, if any.
	 */
	public function test_displacement_matrix( string $mode, string $old_outcome, bool $new_row, string $owner_status = '' ): void {
		global $wpdb;
		$site  = $this->site();
		$store = MainWP_Backup_Restore_Job_Store::instance();
		$owner = '';
		if ( '' !== $owner_status ) {
			$owner = (string) $store->create_job(
				$site,
				5,
				array(
					'plan'    => array(),
					'options' => array( 'pre_snapshot' => true ),
				)
			);
			$this->assertGreaterThan( 0, (int) $owner );
		}
		$old_ref = MainWP_Backup_Executor::trigger_backup( $site, 'full', $owner );
		if ( '' !== $owner_status ) {
			$this->assertTrue( $store->set_status( (int) $owner, $owner_status, array( 'pre_snapshot_task_id' => $old_ref ) ) );
		}
		$website           = MainWP_DB::instance()->get_website_by_id( $site );
		$handle            = json_decode( MainWP_DB::instance()->get_website_option( $website, MainWP_Backup_Executor::ADHOC_TASK_OPTION ), true );
		$handle['started'] = time() - MainWP_Backup_Executor::ADHOC_TASK_ABANDON_SECONDS - 1;
		MainWP_DB::instance()->update_website_option( $website, MainWP_Backup_Executor::ADHOC_TASK_OPTION, wp_json_encode( $handle ) );
		$new_ref  = 'replacement-safety-child';
		$created  = false;
		$create   = static function ( $pre, $child, $what ) use ( $mode, $new_ref, &$created ) {
			if ( 'backup_task_create' !== $what ) {
				return $pre;
			}
			$created = true;
			return 'create_refused' === $mode ? array( 'error' => 'Child refused the task.' ) : array( 'task_id' => $new_ref );
		};
		$options  = MainWP_DB::instance()->get_table_name( 'wp_options' );
		$jobs     = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$readback = false;
		$job_read = false;
		$fault    = static function ( $query ) use ( $mode, $options, $jobs, $new_ref, &$readback, &$created, &$job_read ) {
			if ( 'owner_read_fails' === $mode && $created && 0 === stripos( ltrim( $query ), 'SELECT * FROM ' . $jobs ) ) {
				$job_read = true;
				return 'SELECT * FROM missing_table_s72_owner';
			}
			if ( 'write_refused' === $mode && 0 === stripos( ltrim( $query ), 'UPDATE' ) && false !== strpos( $query, $options ) && false !== strpos( $query, $new_ref ) ) {
				return 'SELECT * FROM missing_table_s71_handle';
			}
			// After the create, the handle write reads `SELECT name`; only the history readback reads `SELECT value`.
			if ( 'readback_throws' === $mode && $created && ! $readback && 0 === stripos( ltrim( $query ), 'SELECT value FROM ' . $options ) && false !== strpos( $query, MainWP_Backup_Executor::ADHOC_TASK_OPTION ) ) {
				$readback = true;
				throw new \RuntimeException( 'adapter fault' );
			}
			return $query;
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $create, 20, 3 );
		add_filter( 'query', $fault );
		$suppress = $wpdb->suppress_errors( true );
		$returned = null;
		try {
			$returned = MainWP_Backup_Executor::trigger_backup( $site, 'full', '' === $owner ? '71' : '' );
		} catch ( \MainWP\Dashboard\MainWP_Exception $e ) {
			$this->assertSame( 'create_refused', $mode, $e->getMessage() );
		} finally {
			$wpdb->suppress_errors( $suppress );
			remove_filter( 'query', $fault );
			remove_filter( 'mainwp_fetch_url_authed_pre', $create, 20 );
		}
		if ( 'create_refused' !== $mode ) {
			$this->assertSame( $new_ref, $returned );
		}
		if ( 'readback_throws' === $mode ) {
			$this->assertTrue( $readback );
		}
		if ( 'owner_read_fails' === $mode ) {
			$this->assertTrue( $job_read );
		}
		$stored = json_decode( MainWP_DB::instance()->get_website_option( $site, MainWP_Backup_Executor::ADHOC_TASK_OPTION ), true );
		$this->assertSame( in_array( $mode, array( 'replaced', 'readback_throws', 'owner_read_fails' ), true ) ? $new_ref : $old_ref, $stored['task_id'] );
		$rows = $this->rows();
		$this->assertSame( $old_ref, $rows[0]['ref'] );
		$this->assertSame( '' === $owner ? 'update_gate' : 'restore_safety', $rows[0]['trigger_source'] );
		$this->assertSame( $old_outcome, $rows[0]['outcome'] );
		$this->assertCount( $new_row ? 2 : 1, $rows );
		if ( $new_row ) {
			$this->assertSame( array( '' === $owner ? 'restore_safety' : 'update_gate', $new_ref, 'running', '' === $owner ? 71 : 0 ), array( $rows[1]['trigger_source'], $rows[1]['ref'], $rows[1]['outcome'], (int) $rows[1]['restore_job_id'] ) );
		}
		if ( $store::STATUS_PRE_SNAPSHOT === $owner_status ) {
			MainWP_Backup_Restore_Orchestrator::instance()->run_job( $store->get_job( (int) $owner ), microtime( true ) + 2 );
			$this->assertSame( $store::STATUS_STAGING, $store->get_job( (int) $owner )->status );
			$this->assertSame( array( 'completed', 'running' ), array_column( $this->rows(), 'outcome' ) );
			$this->assertSame( $new_ref, json_decode( MainWP_DB::instance()->get_website_option( $site, MainWP_Backup_Executor::ADHOC_TASK_OPTION ), true )['task_id'] );
			$polls = array_values(
				array_filter(
					$this->calls,
					static function ( $call ) {
						return 'backup_task_run' === $call[0];
					}
				)
			);
			$this->assertSame( $old_ref, $polls[0][2]['task_id'] );
		}
	}

	/**
	 * Replacement needs confirmation; an active or unreadable restore owner keeps its safety row open.
	 *
	 * @return array
	 */
	public static function displacement_matrix(): array {
		return array(
			'D4 replaced'        => array( 'replaced', 'incomplete', true ),
			'D5 create refused'  => array( 'create_refused', 'running', false ),
			'D6 write refused'   => array( 'write_refused', 'running', true ),
			'D7 readback throws' => array( 'readback_throws', 'running', true ),
			'D8 active restore'  => array( 'replaced', 'running', true, MainWP_Backup_Restore_Job_Store::STATUS_PRE_SNAPSHOT ),
			'D9 terminal owner'  => array( 'replaced', 'incomplete', true, MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT ),
			'D10 owner unread'   => array( 'owner_read_fails', 'running', true, MainWP_Backup_Restore_Job_Store::STATUS_FAILED_PERMANENT ),
		);
	}

	/**
	 * A thrown database fault in history work cannot interrupt the lane it records.
	 *
	 * @dataProvider thrown_history_fault_lanes
	 * @param string $lane driver, reset or restore_cancel.
	 */
	public function test_thrown_history_fault_does_not_interrupt_lane( string $lane ): void {
		$site    = $this->site();
		$history = MainWP_DB::instance()->get_table_name( 'wp_backup_run_history' );
		$fault   = static function ( $query ) use ( $history ) {
			$frames = array_column( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ), 'function' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- the fault targets history work only.
			if ( false !== strpos( $query, $history ) || in_array( 'finalize_site_history', $frames, true ) ) {
				throw new \RuntimeException( 'adapter fault' );
			}
			return $query;
		};
		if ( 'driver' === $lane ) {
			$task = $this->task( array( $site ) );
			MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, true );
			add_filter( 'query', $fault );
			try {
				$this->finish_task( $task );
			} finally {
				remove_filter( 'query', $fault );
			}
			$fresh = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
			$this->assertIsObject( MainWP_Backup_Snapshots::instance()->get_latest_complete( $site, $task->id ) );
			$this->assertArrayHasKey( $site, json_decode( (string) $fresh->completed_sites, true ) );
			$this->assertGreaterThanOrEqual( (int) $fresh->last_run, (int) $fresh->completed );
			$this->assertSame( 'running', $this->rows()[0]['outcome'] );
		} elseif ( 'reset' === $lane ) {
			$task = $this->task( array( $site ) );
			MainWP_DB_Backup::instance()->update_backup_task_with_values(
				$task->id,
				array(
					'last_run'  => time() - 7200,
					'completed' => 0,
				)
			);
			add_filter( 'query', $fault );
			try {
				$result = MainWP_Backup_Operations::reset_task_run( $task->id );
			} finally {
				remove_filter( 'query', $fault );
			}
			$this->assertSame( 'reset', $result['status'] );
		} else {
			$store = MainWP_Backup_Restore_Job_Store::instance();
			$id    = $store->create_job(
				$site,
				5,
				array(
					'plan'    => array(),
					'options' => array( 'pre_snapshot' => true ),
				)
			);
			$ref   = MainWP_Backup_Executor::trigger_backup( $site, 'full', (string) $id );
			$this->assertTrue( $store->set_status( $id, $store::STATUS_PRE_SNAPSHOT, array( 'pre_snapshot_task_id' => $ref ) ) );
			add_filter( 'query', $fault );
			try {
				$this->assertSame( 'cancelled', MainWP_Backup_Restore_Orchestrator::instance()->cancel_job( $id ) );
			} finally {
				remove_filter( 'query', $fault );
			}
			$this->assertSame( $store::STATUS_CANCELLED, $store->get_job( $id )->status );
			$this->assertSame( 'running', $this->rows()[0]['outcome'] );
		}
	}

	/**
	 * Lanes whose history work runs after the recorded operation's own writes.
	 *
	 * @return array
	 */
	public static function thrown_history_fault_lanes(): array {
		return array(
			'driver'         => array( 'driver' ),
			'stale reset'    => array( 'reset' ),
			'restore cancel' => array( 'restore_cancel' ),
		);
	}

	/**
	 * A failure returned in the backup result, not thrown, fails the site's row.
	 */
	public function test_returned_result_failure_finalizes_failed(): void {
		$site     = $this->site();
		$task     = $this->task( array( $site ) );
		$feedback = static function () {
			return array( 'ftp' => 'Upload refused' );
		};
		MainWP_Manage_Backups_Handler::execute_backup_task( $task, 1, true );
		add_filter( 'mainwp_postprocess_backup_sites_feedback', $feedback );
		try {
			$this->finish_task( $task );
		} finally {
			remove_filter( 'mainwp_postprocess_backup_sites_feedback', $feedback );
		}
		$this->assertStringContainsString( 'Upload refused', (string) MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id )->backup_errors );
		$row = $this->rows()[0];
		$this->assertSame( 'failed', $row['outcome'] );
		$this->assertSame( MainWP_Backup_Operations::safe_error( '' )['code'], $row['error_code'] );
		$this->assertNull( $row['snapshot_id'] );
	}

	/**
	 * A completed update-gate task keeps no Child error code.
	 */
	public function test_update_gate_completed_row_carries_no_error_code(): void {
		$site         = $this->site();
		$this->answer = array(
			'phase'      => 'completed',
			'file'       => 'history.zip',
			'size'       => 4096,
			'error_code' => MainWP_Backup_Error_Codes::ERR_ZIP_FAILED,
		);
		$ref          = MainWP_Backup_Executor::trigger_backup( $site );
		MainWP_Backup_Executor::get_backup_task_status( $site, $ref, 1 );
		$row = $this->rows()[0];
		$this->assertSame( 'completed', $row['outcome'] );
		$this->assertSame( '', $row['error_code'] );
	}

	/**
	 * A restore that ends while its safety create is in flight closes the row
	 * the create then opens; a lost lease on a live job leaves it to the next holder.
	 *
	 * @dataProvider publication_race_cases
	 * @param string $event cancel, failure or lease.
	 * @param string $expected Expected safety row outcome.
	 */
	public function test_safety_row_published_after_restore_ended( string $event, string $expected ): void {
		$site         = $this->site();
		$store        = MainWP_Backup_Restore_Job_Store::instance();
		$orchestrator = MainWP_Backup_Restore_Orchestrator::instance();
		$id           = $store->create_job(
			$site,
			5,
			array(
				'plan'    => array(),
				'options' => array( 'pre_snapshot' => true ),
			)
		);
		$this->assertTrue( $store->set_status( $id, $store::STATUS_PREFLIGHT ) );
		$terminal = new \ReflectionMethod( $orchestrator, 'record_terminal_failure' );
		$terminal->setAccessible( true );
		$create = static function ( $pre, $child, $what ) use ( $event, $id, $store, $orchestrator, $terminal ) {
			if ( 'backup_task_create' !== $what ) {
				return $pre;
			}
			if ( 'cancel' === $event ) {
				$orchestrator->cancel_job( $id );
			} elseif ( 'failure' === $event ) {
				$terminal->invoke( $orchestrator, $store->get_job( $id ), 'Child unreachable', MainWP_Backup_Error_Codes::ERR_TIMEOUT, null );
			}
			return array( 'task_id' => 'raced-safety-child' );
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $create, 20, 3 );
		$token = new \ReflectionProperty( $orchestrator, 'lock_token' );
		$token->setAccessible( true );
		$saved = $token->getValue( $orchestrator );
		// A token the job does not hold makes the phase write lose its fence.
		$token->setValue( $orchestrator, 'lease' === $event ? 'stale-worker-token' : null );
		try {
			$preflight = new \ReflectionMethod( $orchestrator, 'phase_preflight' );
			$preflight->setAccessible( true );
			$preflight->invoke( $orchestrator, $store->get_job( $id ) );
		} finally {
			$token->setValue( $orchestrator, $saved );
			remove_filter( 'mainwp_fetch_url_authed_pre', $create, 20 );
		}
		$rows = $this->rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'restore_safety', $rows[0]['trigger_source'] );
		$this->assertSame( $id, (int) $rows[0]['restore_job_id'] );
		$this->assertSame( $expected, $rows[0]['outcome'] );
		$this->assertSame( 'failure' === $event ? MainWP_Backup_Error_Codes::ERR_TIMEOUT : '', $rows[0]['error_code'] );
	}

	/**
	 * Cancel and failure end the job before the row exists; a stale lease does not end it.
	 *
	 * @return array
	 */
	public static function publication_race_cases(): array {
		return array(
			'cancel during create'  => array( 'cancel', 'cancelled' ),
			'failure during create' => array( 'failure', 'failed' ),
			'lease lost, job live'  => array( 'lease', 'running' ),
		);
	}

	/**
	 * The request advancing a system lane is not its durable initiator.
	 *
	 * @dataProvider system_history_lanes
	 * @param string $trigger Explicit system lane.
	 */
	public function test_explicit_system_lane_has_no_initiating_user( string $trigger ): void {
		$this->assertGreaterThan( 0, get_current_user_id() );
		$this->assertGreaterThan( 0, MainWP_Backup_Run_History::start( 0, $this->site(), time(), $trigger, 0, 71, 'system-child' ) );
		$this->assertSame( 0, (int) $this->rows()[0]['initiator_user_id'] );
		$this->assertSame( $trigger, $this->rows()[0]['trigger_source'] );
	}

	/**
	 * Both explicit lanes lack an initiating-user field at their source.
	 *
	 * @return array
	 */
	public static function system_history_lanes(): array {
		return array(
			'update gate'    => array( 'update_gate' ),
			'restore safety' => array( 'restore_safety' ),
		);
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
	 * An unreadable passive answer keeps the handle, so the row stays open; a
	 * reported failure with a malformed code still closes it without warnings.
	 */
	public function test_update_gate_passive_unreadable_answer_stays_running(): void {
		$site         = $this->site();
		$ref          = MainWP_Backup_Executor::trigger_backup( $site );
		$this->answer = array( 'error' => '/secret-provider' );
		MainWP_Backup_Executor::get_backup_task_status( $site, $ref );
		$this->assertSame( 'running', $this->rows()[0]['outcome'] );
		$this->answer = array(
			'phase'      => 'failed',
			'error_code' => array( 'unexpected' ),
		);
		$warnings     = array();
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Warnings must remain observable.
			static function ( $severity, $message ) use ( &$warnings ) {
				$warnings[] = $message;
				return true;
			},
			E_WARNING | E_NOTICE
		);
		try {
			MainWP_Backup_Executor::get_backup_task_status( $site, $ref );
		} finally {
			restore_error_handler();
		}
		$this->assertSame( array(), $warnings );
		$this->assertSame( 'failed', $this->rows()[0]['outcome'] );
		$this->assertSame( MainWP_Backup_Operations::safe_error( '' )['code'], $this->rows()[0]['error_code'] );
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
			$version = $this->saved_site_options['mainwp_db_version'];
			false === $version ? delete_site_option( 'mainwp_db_version' ) : update_site_option( 'mainwp_db_version', $version );
			if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
				update_site_option( MainWP_Install::NATIVE_BACKUP_TABLES_CHECKED, 0 );
				MainWP_Install::instance()->install();
			}
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
	 * Strict-mode MySQL refuses literal TEXT defaults even when inserts supply values.
	 */
	public function test_history_message_definition_has_no_default_and_start_supplies_empty_message(): void {
		$definition = new \ReflectionMethod( MainWP_Install::class, 'native_backup_table_sql' );
		$definition->setAccessible( true );
		$sql = $definition->invoke( MainWP_Install::instance(), 'wp_backup_run_history', '9.0.2.30', '' );
		$this->assertMatchesRegularExpression( '/^\s*message text NOT NULL,\s*$/m', $sql );
		$this->assertDoesNotMatchRegularExpression( '/^\s*message\b[^\n]*\bDEFAULT\b/im', $sql );
		$this->assertGreaterThan( 0, MainWP_Backup_Run_History::start( 1, $this->site(), time() ) );
		$this->assertSame( '', $this->rows()[0]['message'] );
	}

	/**
	 * A failed insert and terminal update must leave the backup functional.
	 */
	public function test_history_database_failures_do_not_break_backup(): void {
		$site     = $this->site();
		$task     = $this->task( array( $site ) );
		$logger   = MainWP_Logger::instance();
		$priority = new \ReflectionProperty( $logger, 'logPriority' );
		$priority->setAccessible( true );
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
	/**
	 * An impossible offset must not wrap around to the first history row.
	 *
	 * @return void
	 */
	public function test_query_overflowing_page_keeps_total_and_returns_no_rows(): void {
		$site = $this->create_test_site();
		$id   = MainWP_Backup_Run_History::start( 1, $site, time(), 'manual', get_current_user_id() );
		$this->assertGreaterThan( 0, $id );
		$result = MainWP_Backup_Run_History::query( array(), PHP_INT_MAX, 100, array( $site ) );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( array(), $result['items'] );
	}
}
