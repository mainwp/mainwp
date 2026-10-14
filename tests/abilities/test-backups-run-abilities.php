<?php
/**
 * Backup actions must preserve consent, scope and the evidence of an asynchronous run.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Abilities_Backups;
use MainWP\Dashboard\MainWP_Abilities_Util;
use MainWP\Dashboard\MainWP_Backup_Operations;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_System_Cron_Jobs;

require_once __DIR__ . '/../backup/class-mainwp-backup-read-fixtures.php';

/** Consent and preview purity apply to every backup action. */
class MainWP_Backups_Run_Abilities_Test extends MainWP_Abilities_Test_Case {
	use Backup_Read_Purity_Guard;

	/**
	 * Fixture state belongs to this test invocation.
	 *
	 * @var int
	 */
	private $site_id;
	/**
	 * Fixture state belongs to this test invocation.
	 *
	 * @var int
	 */
	private $task_id;
	/**
	 * Fixture state belongs to this test invocation.
	 *
	 * @var array
	 */
	private $child_calls = array();
	/**
	 * Fixture state belongs to this test invocation.
	 *
	 * @var array
	 */
	private $cancel_reply = array( 'result' => 'ok' );
	/**
	 * Fixture state belongs to this test invocation.
	 *
	 * @var int
	 */
	private $polls = 0;

	/** Each test owns its rows without assigning database identities. */
	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		MainWP_Backup_Operations::reset_authorized_site_ids_memo();
		update_option( 'mainwp_enableNativeBackups', 1 );
		update_option( 'mainwp_backup_manual_settle_seconds', 0 );
		$this->site_id = $this->create_test_site();
		$task          = MainWP_DB_Backup::instance()->add_backup_task( get_current_user_id(), 'Test backup action', 'daily', 'full', '', (string) $this->site_id, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		$this->assertIsObject( $task );
		$this->task_id      = (int) $task->id;
		$this->child_calls  = array();
		$this->polls        = 0;
		$this->cancel_reply = array( 'result' => 'ok' );
		wp_clear_scheduled_hook( 'mainwp_native_backup_continue_action' );
		add_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'child_reply' ), 10, 4 );
		add_filter( 'pre_http_request', array( $this, 'block_http' ) );
		add_filter( 'mainwp_backup_slice_tick_budget', array( $this, 'short_budget' ) );
		add_filter( 'mainwp_backup_slice_poll_interval', '__return_zero' );
		add_filter( 'mainwp_backup_status_poll_budget', array( $this, 'short_budget' ) );
	}

	/** The continuation fixture must not leave a scheduled event or transport mock behind. */
	public function tear_down() {
		remove_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'child_reply' ), 10 );
		remove_filter( 'pre_http_request', array( $this, 'block_http' ) );
		remove_filter( 'mainwp_backup_slice_tick_budget', array( $this, 'short_budget' ) );
		remove_filter( 'mainwp_backup_slice_poll_interval', '__return_zero' );
		remove_filter( 'mainwp_backup_status_poll_budget', array( $this, 'short_budget' ) );
		wp_clear_scheduled_hook( 'mainwp_native_backup_continue_action' );
		global $wpdb;
		$manual = MainWP_DB_Backup::instance()->get_manual_backup_task( $this->site_id );
		$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'site_id' => $this->site_id ) );
		$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ), array( 'site_id' => $this->site_id ) );
		foreach ( array_filter( array( $this->task_id, $manual->id ?? null ) ) as $id ) {
			$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup_progress' ), array( 'task_id' => $id ) );
			$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup_run_history' ), array( 'task_id' => $id ) );
			$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup' ), array( 'id' => $id ) );
		}
		MainWP_Backup_Operations::reset_authorized_site_ids_memo();
		parent::tear_down();
	}

	/**
	 * A short test budget makes cron continuation observable without daily cadence.
	 *
	 * @return float
	 */
	public function short_budget() {
		return 0.03;
	}

	/**
	 * Offline tests must not send the cron loopback to a live server.
	 *
	 * @return \WP_Error
	 */
	public function block_http() {
		return new \WP_Error( 'offline_fixture', 'Unexpected network request.' );
	}

	/**
	 * The fixture stays nonterminal so cron advancement can be observed without downloading archives.
	 *
	 * @param mixed  $result Previous response.
	 * @param object $site Site row.
	 * @param string $action Child operation.
	 * @param array  $params Request parameters.
	 * @return array
	 */
	public function child_reply( $result, $site, $action, $params ) {
		$this->child_calls[] = array( $action, $params );
		switch ( $action ) {
			case 'backup_preflight':
				return array(
					'disk_free'       => 10 * GB_IN_BYTES,
					'dir_writable'    => true,
					'db_connected'    => true,
					'zip_support'     => true,
					'time_limit_ok'   => true,
					'backup_dir_hash' => 'abc123def456gh78',
					'task_spine'      => 1,
				);
			case 'backup_task_create':
				return array(
					'task_id' => 'ability-child',
					'phase'   => 'db_dump',
				);
			case 'backup_task_status':
			case 'backup_task_run':
				++$this->polls;
				return array(
					'task_id'     => 'ability-child',
					'phase'       => $this->polls > 1 ? 'archiving_files' : 'db_dump',
					'bytes_done'  => $this->polls * 100,
					'bytes_total' => 10000,
				);
			case 'backup_task_cancel':
				return $this->cancel_reply;
			default:
				$this->fail( 'Unexpected Child operation: ' . $action );
		}
	}

	/**
	 * One matrix applies the same consent and access checks to every action.
	 *
	 * @return array
	 */
	public static function actions() {
		return array(
			'site start' => array( 'run-site-backup', 'POST', false, 'execute_backups' ),
			'task start' => array( 'run-backup-task', 'POST', false, 'run_backup_tasks' ),
			'cancel'     => array( 'cancel-site-backup', 'DELETE', true, 'execute_backups' ),
			'reset'      => array( 'reset-backup-task-run', 'DELETE', true, 'pause_resume_backup_tasks' ),
			'cleanup'    => array( 'cleanup-site-backups', 'POST', false, 'execute_backups' ),
		);
	}

	/**
	 * A missing registration must fail before invoking a callback.
	 *
	 * @param string $name Slug.

	 * @return \WP_Ability
	 */
	private function ability( $name ) {
		$full = 'mainwp/' . $name . '-v1';
		$this->assertTrue( wp_has_ability( $full ), $full . ' must be registered.' );
		return wp_get_ability( $full );
	}

	/**
	 * Fixtures use only identities allocated to this test.
	 *
	 * @param string $name Slug.

	 * @return array
	 */
	private function input( $name ) {
		return false !== strpos( $name, 'task' ) ? array( 'task_id' => $this->task_id ) : array( 'site_id_or_domain' => $this->site_id );
	}

	/**
	 * Every preview execution, including refusals, uses the full purity guard.
	 *
	 * @param string $name Slug.
	 * @param array  $input Caller input.
	 * @param bool   $direct Whether to bypass WordPress's permission wrapper.
	 * @return mixed
	 */
	private function execute( $name, array $input, $direct = false ) {
		$ability = $this->ability( $name );
		$call    = static function () use ( $ability, $name, $input, $direct ) {
			if ( $direct ) {
				$method = 'execute_' . str_replace( '-', '_', $name );
				return MainWP_Abilities_Backups::$method( $input );
			}
			return $ability->execute( $input );
		};
		if ( ! empty( $input['dry_run'] ) ) {
			$deny = function () {
				$this->fail( 'A preview fired a task write hook.' );
			};
			add_action( 'mainwp_add_backuptask', $deny );
			add_action( 'mainwp_update_backuptask', $deny );
			try {
				return $this->guarded( $call );
			} finally {
				remove_action( 'mainwp_add_backuptask', $deny );
				remove_action( 'mainwp_update_backuptask', $deny );
			}
		}
		return $call();
	}

	/**
	 * An advertised output contract must agree with the returned evidence.
	 *
	 * @param string $name Slug.

	 * @param array  $output Result.
	 */
	private function assert_output( $name, $output ) {
		$this->assertIsArray( $output, is_wp_error( $output ) ? $output->get_error_message() : '' );
		$valid = rest_validate_value_from_schema( $output, $this->ability( $name )->get_output_schema() );
		$this->assertTrue( $valid, is_wp_error( $valid ) ? $valid->get_error_message() : '' );
	}

	/**
	 * A stalled run allows reset and cancellation without starting a backup.
	 *
	 * @param int $task_id Run identity.

	 * @return void
	 */
	private function open_run( $task_id ) {
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			$task_id,
			array(
				'last_run'  => time() - 7200,
				'completed' => 0,
			)
		);
		MainWP_DB_Backup::instance()->add_backup_task_progress( $task_id, $this->site_id, array() );
		MainWP_DB_Backup::instance()->update_backup_task_progress(
			$task_id,
			$this->site_id,
			array(
				'child_backup_task_id' => 'ability-child',
				'child_phase'          => 'db_dump',
			)
		);
	}

	/**
	 * Discovery must advertise the operation right and destructive annotations.
	 *
	 * @dataProvider actions

	 * @param string $name Slug.

	 * @param string $method Method.

	 * @param bool   $idempotent Annotation.

	 * @param string $right Right.
	 */
	public function test_ability_is_registered( $name, $method, $idempotent, $right ) {
		$ability = $this->ability( $name );
		$this->assertSame( 'mainwp-backups', $ability->get_category() );
		$this->assertNotEmpty( $ability->get_label() );
		$this->assertStringContainsString( 'Possible errors:', $ability->get_description() );
		$this->assertSame( array( $right ), MainWP_Abilities_Util::required_rights_for_ability( $ability->get_name() ) );
		$meta = $ability->get_meta();
		$this->assertTrue( $meta['show_in_rest'] );
		$this->assertFalse( $meta['annotations']['readonly'] );
		$this->assertTrue( $meta['annotations']['destructive'] );
		$this->assertSame( $idempotent, $meta['annotations']['idempotent'] );
		$this->assertNotEmpty( $meta['annotations']['instructions'] );
	}

	/**
	 * Confirmed actions must satisfy their closed output contract.
	 *
	 * @dataProvider actions

	 * @param string $name Slug.
	 */
	public function test_action_returns_expected_structure( $name ) {
		if ( 'reset-backup-task-run' === $name ) {
			$this->open_run( $this->task_id );
		}
		$output = $this->execute( $name, $this->input( $name ) + array( 'confirm' => true ) );
		$this->assert_output( $name, $output );
		$this->assertFalse( $output['dry_run'] );
	}

	/**
	 * An unauthenticated caller must fail before work or preview reads.
	 *
	 * @dataProvider actions

	 * @param string $name Slug.
	 */
	public function test_action_requires_authentication( $name ) {
		wp_set_current_user( 0 );
		$input = $this->input( $name ) + array( 'dry_run' => true );
		$error = $this->execute( $name, $input, true );
		$this->assertSame( 'mainwp_permission_denied', $error->get_error_code() );
		$this->assertSame( 401, $error->get_error_data()['status'] );
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		$this->assertSame( 'ability_invalid_permissions', $this->execute( $name, $input )->get_error_code() );
	}

	/**
	 * Site ownership cannot substitute for the Dashboard capability.
	 *
	 * @dataProvider actions

	 * @param string $name Slug.
	 */
	public function test_action_requires_manage_options( $name ) {
		$this->set_current_user_as_subscriber();
		$input = $this->input( $name ) + array( 'dry_run' => true );
		$error = $this->execute( $name, $input, true );
		$this->assertSame( 'mainwp_permission_denied', $error->get_error_code() );
		$this->assertSame( 403, $error->get_error_data()['status'] );
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		$this->assertSame( 'ability_invalid_permissions', $this->execute( $name, $input )->get_error_code() );
	}

	/**
	 * Undocumented fields must not reach an action.
	 *
	 * @dataProvider actions

	 * @param string $name Slug.
	 */
	public function test_action_validates_input( $name ) {
		$error = $this->execute( $name, array( 'unexpected' => true ) );
		$this->assertSame( 'ability_invalid_input', $error->get_error_code() );
	}

	/**
	 * Previews must produce authorized evidence without dispatch or writes.
	 *
	 * @dataProvider actions

	 * @param string $name Slug.
	 */
	public function test_action_dry_run_returns_preview( $name ) {
		if ( 'reset-backup-task-run' === $name ) {
			$this->open_run( $this->task_id );
		}
		$output = $this->execute( $name, $this->input( $name ) + array( 'dry_run' => true ) );
		$this->assert_output( $name, $output );
		$this->assertTrue( $output['dry_run'] );
		$this->assertTrue( $output['can_proceed'] );
		$this->assertSame( array(), $output['refusals'] );
		$this->assertSame( array(), $this->child_calls );
		$this->assertFalse( wp_next_scheduled( 'mainwp_native_backup_continue_action' ) );
	}

	/**
	 * Conflicting modes must not reach a write path.
	 *
	 * @dataProvider actions

	 * @param string $name Slug.
	 */
	public function test_action_rejects_dry_run_and_confirm_together( $name ) {
		$error = $this->execute(
			$name,
			$this->input( $name ) + array(
				'dry_run' => true,
				'confirm' => true,
			)
		);
		$this->assertSame( 'mainwp_invalid_input', $error->get_error_code() );
		$this->assertSame( 400, $error->get_error_data()['status'] );
	}

	/**
	 * An omitted confirmation must never perform the action.
	 *
	 * @dataProvider actions

	 * @param string $name Slug.
	 */
	public function test_action_requires_confirmation( $name ) {
		$error = $this->execute( $name, $this->input( $name ) );
		$this->assertSame( 'mainwp_confirmation_required', $error->get_error_code() );
		$this->assertSame( 400, $error->get_error_data()['status'] );
	}

	/**
	 * Turning native backups off must still allow open work to be stopped.
	 *
	 * @dataProvider actions

	 * @param string $name Slug.
	 */
	public function test_native_disabled_refuses_starts_and_allows_stops( $name ) {
		$this->open_run( $this->task_id );
		update_option( 'mainwp_enableNativeBackups', 0 );
		foreach ( array( 'dry_run', 'confirm' ) as $mode ) {
			$output = $this->execute( $name, $this->input( $name ) + array( $mode => true ) );
			if ( in_array( $name, array( 'cancel-site-backup', 'reset-backup-task-run' ), true ) ) {
				$this->assert_output( $name, $output );
			} else {
				$this->assertSame( 'mainwp_backup_legacy_disabled', $output->get_error_code() );
				$this->assertSame( 409, $output->get_error_data()['status'] );
			}
		}
	}

	/** Template rows must remain inaccessible through previews and confirms. */
	public function test_task_actions_refuse_template_tasks() {
		$task = MainWP_DB_Backup::instance()->ensure_manual_backup_task( MainWP_DB::instance()->get_website_by_id( $this->site_id ) );
		foreach ( array( 'run-backup-task', 'reset-backup-task-run' ) as $name ) {
			foreach ( array( 'dry_run', 'confirm' ) as $mode ) {
				$error = $this->execute(
					$name,
					array(
						'task_id' => (int) $task->id,
						$mode     => true,
					)
				);
				$this->assertSame( 'mainwp_backup_task_not_found', $error->get_error_code() );
				$this->assertSame( 404, $error->get_error_data()['status'] );
			}
		}
	}

	/**
	 * Both start actions must expose a usable asynchronous handle.
	 *
	 * @return array
	 */
	public static function starts() {
		return array( array( 'run-site-backup', 'get-site-backup-progress', 30 ), array( 'run-backup-task', 'get-backup-task-run', 60 ) );
	}

	/**
	 * A run must keep advancing when its caller stops polling.
	 *
	 * @dataProvider starts

	 * @param string $name Start slug.

	 * @param string $poll Poll slug.

	 * @param int    $interval Poll interval.
	 */
	public function test_start_schedules_and_cron_advances_without_polling( $name, $poll, $interval ) {
		MainWP_System_Cron_Jobs::instance();
		$output = $this->execute( $name, $this->input( $name ) + array( 'confirm' => true ) );
		$this->assert_output( $name, $output );
		$this->assertSame( 'started', $output['status'] );
		$this->assertSame( 'mainwp/' . $poll . '-v1', $output['poll']['ability'] );
		$this->assertSame( $interval, $output['poll']['interval_seconds'] );
		$this->assertNotFalse( wp_next_scheduled( 'mainwp_native_backup_continue_action' ) );
		$task_id = 'run-backup-task' === $name ? $this->task_id : (int) MainWP_DB_Backup::instance()->get_manual_backup_task( $this->site_id )->id;
		$before  = MainWP_DB_Backup::instance()->get_backup_task_progress( $task_id, $this->site_id );
		$this->assertNotEmpty( $before->child_backup_task_id );
		$polls_before = $this->polls;
		do_action( 'mainwp_native_backup_continue_action' );
		$after = MainWP_DB_Backup::instance()->get_backup_task_progress( $task_id, $this->site_id );
		// How many Child status calls fit in one slice depends on the runner's
		// clock, so the proof is that cron itself drove the Child and the row
		// moved, not which mocked phase it reached.
		$this->assertGreaterThan( $polls_before, $this->polls, 'The continue action must drive the Child without a poll.' );
		$this->assertNotSame( $before->child_phase, $after->child_phase );
		$this->assertContains( $after->child_phase, array( 'db_dump', 'archiving_files' ) );
	}

	/**
	 * Cancellation cannot claim more than the Child response proves.
	 *
	 * @return array
	 */
	public static function cancellation_replies() {
		return array(
			array( array( 'result' => 'ok' ), 'cancelled', true ),
			array(
				array(
					'result' => 'already_terminal',
					'phase'  => 'completed',
				),
				'already_finished',
				false,
			),
			array(
				array(
					'result' => 'already_terminal',
					'phase'  => 'failed',
				),
				'already_failed',
				false,
			),
			array( array( 'result' => 'no_active' ), 'no_active_on_site', false ),
			array( array( 'result' => 'busy' ), 'busy', false ),
			array( array(), null, false ),
			array( array( 'error' => 'private /server/path token' ), null, false ),
			array( array( 'result' => 'unknown' ), null, false ),
		);
	}

	/**
	 * Only an explicit cancellation closes the local on-demand run.
	 *
	 * @dataProvider cancellation_replies

	 * @param array       $reply Child answer.

	 * @param string|null $result Honest result.

	 * @param bool        $closes Local closure.
	 */
	public function test_cancel_returns_honest_results( $reply, $result, $closes ) {
		$task = MainWP_DB_Backup::instance()->ensure_manual_backup_task( MainWP_DB::instance()->get_website_by_id( $this->site_id ) );
		$this->open_run( (int) $task->id );
		$this->cancel_reply = $reply;
		$output             = $this->execute(
			'cancel-site-backup',
			array(
				'site_id_or_domain' => $this->site_id,
				'confirm'           => true,
			)
		);
		if ( null === $result ) {
			$this->assertSame( 'mainwp_operation_failed', $output->get_error_code() );
			$this->assertSame( 502, $output->get_error_data()['status'] );
			$this->assertStringNotContainsString( '/server/path', wp_json_encode( $output ) );
		} else {
			$this->assert_output( 'cancel-site-backup', $output );
			$this->assertSame( $result, $output['result'] );
			$this->assertNotEmpty( $output['message'] );
		}
		$task = MainWP_DB_Backup::instance()->get_manual_backup_task( $this->site_id );
		$this->assertSame( $closes, (int) $task->completed >= (int) $task->last_run );
	}

	/** A creating marker cannot be sent as a Child task identity. */
	public function test_cancel_unknown_child_task_is_busy_without_dispatch() {
		$task = MainWP_DB_Backup::instance()->ensure_manual_backup_task( MainWP_DB::instance()->get_website_by_id( $this->site_id ) );
		$this->open_run( (int) $task->id );
		MainWP_DB_Backup::instance()->update_backup_task_progress( (int) $task->id, $this->site_id, array( 'child_backup_task_id' => '' ) );
		$output = $this->execute(
			'cancel-site-backup',
			array(
				'site_id_or_domain' => $this->site_id,
				'confirm'           => true,
			)
		);
		$this->assertSame( 'busy', $output['result'] );
		$this->assertSame( array(), $this->child_calls );
	}

	/**
	 * Known refusals must keep their identities through previews and confirmations.
	 *
	 * @return array
	 */
	public static function refusals() {
		return array(
			array( 'paused', 'run-backup-task', 'mainwp_backup_task_paused' ),
			array( 'no_sites', 'run-backup-task', 'mainwp_backup_no_sites' ),
			array( 'disconnected', 'run-site-backup', 'mainwp_backup_site_disconnected' ),
			array( 'restore', 'run-site-backup', 'mainwp_backup_already_running' ),
			array( 'restore', 'run-backup-task', 'mainwp_backup_already_running' ),
		);
	}

	/**
	 * A caller must be able to distinguish configuration refusals from unexpected failures.
	 *
	 * @dataProvider refusals

	 * @param string $state Fixture state.

	 * @param string $name Slug.

	 * @param string $code Refusal code.
	 */
	public function test_known_start_refusals_have_stable_codes( $state, $name, $code ) {
		global $wpdb;
		if ( 'paused' === $state ) {
			MainWP_DB_Backup::instance()->update_backup_task_with_values( $this->task_id, array( 'paused' => 1 ) );
		} elseif ( 'no_sites' === $state ) {
			MainWP_DB_Backup::instance()->update_backup_task_with_values( $this->task_id, array( 'sites' => '' ) );
		} elseif ( 'disconnected' === $state ) {
			$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_sync' ), array( 'sync_errors' => 'disconnected' ), array( 'wpid' => $this->site_id ) );
		} else {
			$this->assertNotFalse(
				$wpdb->insert(
					MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ),
					array(
						'site_id'     => $this->site_id,
						'snapshot_id' => 1,
						'status'      => 'running',
						'created'     => time(),
						'updated'     => time(),
						'checkpoint'  => '{}',
					)
				)
			);
		}
		$preview = $this->execute( $name, $this->input( $name ) + array( 'dry_run' => true ) );
		$this->assert_output( $name, $preview );
		$this->assertFalse( $preview['can_proceed'] );
		$this->assertSame( $code, $preview['refusals'][0]['code'] );
		$error = $this->execute( $name, $this->input( $name ) + array( 'confirm' => true ) );
		$this->assertSame( $code, $error->get_error_code() );
		$this->assertSame( 409, $error->get_error_data()['status'] );
	}

	/**
	 * Numeric coercion must never change the identity a caller supplied.
	 *
	 * @return array
	 */
	public static function id_shapes() {
		return array(
			'I01' => array( '1.9999999999999999', null, false ),
			'I02' => array( '1.00000000000000001', null, false ),
			'I03' => array( '9007199254740993.0', null, false ),
			'I04' => array( '9.007199254740993e15', null, false ),
			'I05' => array( '9223372036854775808', null, false ),
			'I06' => array( '9007199254740991', 9007199254740991, true ),
			'I07' => array( '+5', null, false ),
			'I08' => array( ' 05 ', null, false ),
			'I09' => array( '5.0', null, false ),
			'I10' => array( '5e0', null, false ),
			'I11' => array( 5, 5, true ),
			'I12' => array( '5', 5, true ),
			'I13' => array( 0, null, false ),
			'I14' => array( -1, null, false ),
			'I15' => array( 9007199254740991, 9007199254740991, true ),
			'I16' => array( 9007199254740992, null, false ),
			'I17' => array( 5.0, null, true ),
			'I18' => array( true, null, false ),
			'I19' => array( null, null, false ),
			'I20' => array( array(), null, false ),
			'I21' => array( '9007199254740992', null, true ),
			'I22' => array( '05', null, false ),
			'I23' => array( "5\n", null, true ),
		);
	}

	/**
	 * Body transports and PHP calls must preserve each identity before WordPress coercion.
	 *
	 * @dataProvider id_shapes
	 * @param mixed    $id Raw identity.
	 * @param int|null $expected Accepted integer.
	 * @param bool     $schema_accepts Schema acceptance.
	 */
	public function test_all_action_ids_follow_exact_contract( $id, $expected, $schema_accepts ) {
		foreach ( self::actions() as $action ) {
			list( $name, $method ) = $action;
			$key                   = false !== strpos( $name, 'task' ) ? 'task_id' : 'site_id_or_domain';
			$input                 = array(
				$key      => $id,
				'dry_run' => true,
			);
			$output                = $this->execute( $name, $input );
			$this->assertWPError( $output );
			$this->assertSame( null === $expected ? 400 : 404, $output->get_error_data()['status'], $name );
			global $wp_rest_server;
			$previous       = $wp_rest_server;
			$wp_rest_server = new \WP_REST_Server();
			do_action( 'rest_api_init' );
			$request = new \WP_REST_Request( $method, '/wp-abilities/v1/abilities/mainwp/' . $name . '-v1/run' );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( array( 'input' => $input ), JSON_PRESERVE_ZERO_FRACTION ) );
			try {
				$response = $this->guarded(
					static function () use ( $request ) {
						return rest_do_request( $request );
					}
				);
			} finally {
				$wp_rest_server = $previous;
			}
			$this->assertSame( null === $expected ? 400 : 404, $response->get_status(), $name . ': ' . wp_json_encode( $response->get_data() ) );
		}
	}
	/** Valid numeric strings must resolve to the same allocated identities on both transports. */
	public function test_canonical_fixture_ids_succeed_on_php_and_body_routes() {
		foreach ( self::actions() as $action ) {
			list( $name, $method ) = $action;
			$input                 = array_map( 'strval', $this->input( $name ) ) + array( 'dry_run' => true );
			$this->assert_output( $name, $this->execute( $name, $input ) );
			global $wp_rest_server;
			$previous       = $wp_rest_server;
			$wp_rest_server = new \WP_REST_Server();
			do_action( 'rest_api_init' );
			$request = new \WP_REST_Request( $method, '/wp-abilities/v1/abilities/mainwp/' . $name . '-v1/run' );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( array( 'input' => $input ) ) );
			try {
				$response = $this->guarded(
					static function () use ( $request ) {
						return rest_do_request( $request );
					}
				);
			} finally {
				$wp_rest_server = $previous;
			}
			$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
			$this->assert_output( $name, $response->get_data() );
		}
	}

	/** Missing site permission remains a coded shared refusal while the ability hides task existence. */
	public function test_site_permission_refusal_is_coded_and_task_scope_is_hidden() {
		$deny = function ( $allowed, $type, $id ) {
			return 'site' === $type && (int) $id === $this->site_id ? false : $allowed;
		};
		add_filter( 'mainwp_currentusercan', $deny, 10, 3 );
		try {
			$code    = '';
			$stale   = array();
			$task    = MainWP_DB_Backup::instance()->get_backup_task_by_id( $this->task_id );
			$message = \MainWP\Dashboard\MainWP_Manage_Backups_Handler::manual_task_start_error( $task, $stale, $code );
			$this->assertSame( 'mainwp_backup_no_permission', $code );
			$this->assertSame( 'You are not allowed to run backups for one or more of this task\'s sites.', $message );
			foreach ( array( 'dry_run', 'confirm' ) as $mode ) {
				$error = $this->execute(
					'run-backup-task',
					array(
						'task_id' => $this->task_id,
						$mode     => true,
					)
				);
				$this->assertSame( 'mainwp_backup_task_not_found', $error->get_error_code() );
				$this->assertSame( 404, $error->get_error_data()['status'] );
			}
		} finally {
			remove_filter( 'mainwp_currentusercan', $deny, 10 );
		}
	}

	/**
	 * The asynchronous handle must describe the run that owns the work.
	 *
	 * @dataProvider starts
	 * @param string $name Start slug.
	 * @param string $poll Poll slug.
	 * @param int    $interval Poll interval.
	 */
	public function test_start_failure_after_dispatch_has_stable_code( $name, $poll, $interval ) {
		$vanished = false;
		$mark     = static function ( $result, $site, $action ) use ( &$vanished ) {
			if ( 'backup_task_create' === $action ) {
				$vanished = true;
			}
			return $result;
		};
		$table    = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$hide     = static function ( $sql ) use ( $table, &$vanished ) {
			if ( $vanished && str_starts_with( $sql, 'SELECT * FROM ' . $table . ' WHERE ' ) ) {
				return 'SELECT * FROM ' . $table . ' WHERE id = -1';
			}
			return $sql;
		};
		add_filter( 'mainwp_fetch_url_authed_pre', $mark, 1, 3 );
		add_filter( 'query', $hide );
		try {
			$error = $this->execute( $name, $this->input( $name ) + array( 'confirm' => true ) );
			$this->assertWPError( $error );
			$this->assertSame( 'mainwp_backup_start_failed', $error->get_error_code() );
			$this->assertSame( 502, $error->get_error_data()['status'] );
			$this->assertSame( \MainWP\Dashboard\MainWP_Backup_Error_Codes::message( 'mainwp_backup_start_failed' ), $error->get_error_message() );
		} finally {
			remove_filter( 'query', $hide );
			remove_filter( 'mainwp_fetch_url_authed_pre', $mark, 1 );
		}
	}

	/**
	 * The asynchronous handle must describe the run that owns the work.
	 *
	 * @dataProvider starts
	 * @param string $name Start slug.
	 * @param string $poll Poll slug.
	 * @param int    $interval Poll interval.
	 */
	public function test_open_run_attaches_without_another_create( $name, $poll, $interval ) {
		$first             = $this->execute( $name, $this->input( $name ) + array( 'confirm' => true ) );
		$this->child_calls = array();
		$second            = $this->execute( $name, $this->input( $name ) + array( 'confirm' => true ) );
		$this->assertSame( 'already_running', $second['status'] );
		$this->assertSame( $first['started_at'], $second['started_at'] );
		$this->assertSame( $first['poll'], $second['poll'] );
		$this->assertSame( array(), $this->child_calls );
	}

	/**
	 * Matrix S rows for both start abilities.
	 *
	 * @return array
	 */
	public static function start_status_matrix() {
		$rows = array();
		foreach ( self::starts() as $start ) {
			// S7 and S8 need the task path's two reads; S9 needs the site path's re-read.
			$only = 'run-site-backup' === $start[0] ? array( 'S9' ) : array( 'S7', 'S8' );
			foreach ( array_merge( array( 'S1', 'S2', 'S3', 'S4', 'S5', 'S6' ), $only ) as $row ) {
				$rows[ $row . ' ' . $start[0] ] = array_merge( array( $row ), $start );
			}
		}
		return $rows;
	}

	/**
	 * An agent acts on the start status, so it must name the run this call claimed or attached to.
	 *
	 * Rows are matrix S in the review findings: S1 run open before the call, S2 claim won,
	 * S3 claim won and the first preflight fails, S4 clean run closed within the settle
	 * window, S5 slot refused, S6 a racing run opens the row during the claim, S7 a run opens
	 * between the task path's two reads, S8 a run closes with errors between those reads and
	 * this call's claim wins, S9 another caller claims a newer run before the site path's re-read.
	 *
	 * @dataProvider start_status_matrix
	 * @param string $row Matrix row.
	 * @param string $name Start slug.
	 * @param string $poll Poll slug.
	 * @param int    $interval Poll interval.
	 */
	public function test_start_status_follows_matrix_s( $row, $name, $poll, $interval ) {
		global $wpdb;
		$task_id  = 'run-backup-task' === $name ? $this->task_id : (int) MainWP_DB_Backup::instance()->ensure_manual_backup_task( MainWP_DB::instance()->get_website_by_id( $this->site_id ) )->id;
		$table    = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$jobs     = MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' );
		$cleanup  = array();
		$at       = null;
		$contacts = false;
		$fired    = false;
		$watch    = static function ( $filter, $callback ) use ( &$cleanup ) {
			add_filter( $filter, $callback, 10, 4 );
			$cleanup[] = static function () use ( $filter, $callback ) {
				remove_filter( $filter, $callback, 10 );
			};
		};
		$fail     = static function ( $result, $site, $action ) {
			return 'backup_preflight' === $action ? array( 'disk_free' => 0 ) : $result;
		};
		switch ( $row ) {
			case 'S1':
				$this->open_run( $task_id );
				$expect = 'already_running';
				$at     = (int) MainWP_DB_Backup::instance()->get_backup_task_by_id( $task_id )->last_run;
				break;
			case 'S2':
				$expect   = 'started';
				$contacts = true;
				break;
			case 'S3':
				add_filter( 'mainwp_fetch_url_authed_pre', $fail, 11, 3 );
				$cleanup[] = static function () use ( $fail ) {
					remove_filter( 'mainwp_fetch_url_authed_pre', $fail, 11 );
				};
				$expect    = 'started';
				$contacts  = true;
				break;
			case 'S4':
				$at = time() - 5;
				update_option( 'mainwp_backup_manual_settle_seconds', 60 );
				MainWP_DB_Backup::instance()->update_backup_task_with_values(
					$task_id,
					array(
						'last_run'          => $at,
						'last_run_manually' => $at,
						'completed'         => $at,
						'completed_sites'   => wp_json_encode( array( $this->site_id => true ) ),
						'backup_errors'     => '',
					)
				);
				MainWP_DB_Backup::instance()->add_backup_task_progress( $task_id, $this->site_id, array() );
				MainWP_DB_Backup::instance()->update_backup_task_progress(
					$task_id,
					$this->site_id,
					array(
						'dtsFetched'  => $at,
						'child_phase' => 'completed',
					)
				);
				$this->assertNotFalse(
					$wpdb->insert(
						MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ),
						array(
							'site_id'  => $this->site_id,
							'task_id'  => $task_id,
							'type'     => 'full',
							'status'   => 'complete',
							'created'  => $at,
							'archives' => '[]',
						)
					)
				);
				$expect = 'already_running';
				break;
			case 'S5':
				update_option( 'mainwp_backup_max_concurrent', 1 );
				$token = \MainWP\Dashboard\MainWP_Backup_Monitor::acquire_backup_slot( 1 );
				$this->assertIsString( $token );
				$cleanup[] = static function () use ( $token ) {
					\MainWP\Dashboard\MainWP_Backup_Monitor::release_backup_slot( $token );
				};
				$expect    = 'not_started';
				break;
			case 'S6':
				// The racing run starts before this call's click, so a click-time handle would miss it.
				$at  = time() - 30;
				$spy = static function ( $sql ) use ( $table, $task_id, $at, &$fired ) {
					if ( ! $fired && str_starts_with( $sql, 'UPDATE ' . $table . ' SET last_run = GREATEST(' ) && str_contains( $sql, 'WHERE id = ' . $task_id . ' AND' ) ) {
						$fired = true;
						MainWP_DB_Backup::instance()->update_backup_task_with_values(
							$task_id,
							array(
								'last_run'  => $at,
								'completed' => 0,
							)
						);
					}
					return $sql;
				};
				$watch( 'query', $spy );
				$expect = 'already_running';
				break;
			case 'S7':
			case 'S8':
				// Operations reads the row first; its restore check is the first restore-job read
				// before the handler reads the row again.
				$at = 'S7' === $row ? time() - 30 : null;
				if ( 'S8' === $row ) {
					MainWP_DB_Backup::instance()->update_backup_task_with_values(
						$task_id,
						array(
							'last_run'  => time() - 100,
							'completed' => 0,
						)
					);
				}
				$spy = static function ( $sql ) use ( $jobs, $task_id, $row, $at, &$fired ) {
					if ( ! $fired && str_contains( $sql, $jobs ) ) {
						$fired = true;
						MainWP_DB_Backup::instance()->update_backup_task_with_values(
							$task_id,
							'S7' === $row ? array(
								'last_run'  => $at,
								'completed' => 0,
							) : array(
								'completed'     => time() - 50,
								'backup_errors' => 'Earlier run failed.',
							)
						);
					}
					return $sql;
				};
				$watch( 'query', $spy );
				$expect   = 'S7' === $row ? 'already_running' : 'started';
				$contacts = 'S8' === $row;
				break;
			default:
				// This call's run fails its preflight and closes; another caller then claims a
				// newer run before the re-read, so the row no longer carries this call's start.
				add_filter( 'mainwp_fetch_url_authed_pre', $fail, 11, 3 );
				$cleanup[]  = static function () use ( $fail ) {
					remove_filter( 'mainwp_fetch_url_authed_pre', $fail, 11 );
				};
				$claim_seen = false;
				$spy        = static function ( $sql ) use ( $table, $task_id, &$claim_seen, &$fired, &$at ) {
					global $wpdb;
					if ( $fired ) {
						return $sql;
					}
					if ( str_starts_with( $sql, 'UPDATE ' . $table . ' SET last_run = GREATEST(' ) && str_contains( $sql, 'WHERE id = ' . $task_id . ' AND' ) ) {
						$claim_seen = true;
						return $sql;
					}
					if ( $claim_seen && str_contains( $sql, 'manual_site_id' ) ) {
						$fired = true;
						$mine  = $wpdb->get_row( $wpdb->prepare( 'SELECT last_run, completed FROM ' . $table . ' WHERE id = %d', $task_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- trusted table name.
						if ( (int) $mine->completed >= (int) $mine->last_run ) {
							$at = (int) $mine->last_run;
							MainWP_DB_Backup::instance()->update_backup_task_with_values(
								$task_id,
								array(
									'last_run'  => $at + 10,
									'completed' => 0,
								)
							);
						}
					}
					return $sql;
				};
				$watch( 'query', $spy );
				$expect   = 'started';
				$contacts = true;
		}
		$click             = time();
		$this->child_calls = array();
		try {
			$output = $this->execute( $name, $this->input( $name ) + array( 'confirm' => true ) );
		} finally {
			foreach ( $cleanup as $undo ) {
				$undo();
			}
		}
		if ( in_array( $row, array( 'S6', 'S7', 'S8', 'S9' ), true ) ) {
			$this->assertTrue( $fired, 'The competing change must land inside the call.' );
		}
		$this->assert_output( $name, $output );
		$this->assertSame( $expect, $output['status'] );
		$this->assertSame( 'mainwp/' . $poll . '-v1', $output['poll']['ability'] );
		$this->assertSame( $interval, $output['poll']['interval_seconds'] );
		$after = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task_id );
		if ( 'S9' === $row ) {
			$this->assertNotNull( $at, 'The run must have closed before the newer claim.' );
			$this->assertSame( $at + 10, (int) $after->last_run );
		} elseif ( 'started' === $expect ) {
			$at = (int) $after->last_run;
		}
		if ( 'started' === $expect ) {
			$this->assertGreaterThanOrEqual( $click, $at );
		}
		if ( 'S3' === $row || 'S9' === $row ) {
			$this->assertSame( array( 'backup_preflight' ), array_column( $this->child_calls, 0 ) );
		}
		if ( 'S3' === $row ) {
			$this->assertGreaterThanOrEqual( (int) $after->last_run, (int) $after->completed );
			$this->assertNotEmpty( $after->backup_errors );
		}
		if ( 'S4' === $row ) {
			$this->assertSame( $at, (int) $after->last_run );
			$this->assertSame( $at, (int) $after->completed );
		}
		if ( null === $at ) {
			$this->assertNull( $output['started_at'] );
		} else {
			$this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z', $at ), $output['started_at'] );
		}
		if ( 'run-site-backup' === $name ) {
			$this->assertSame( null === $at ? $output['since'] : $at, $output['since'] );
			$this->assertSame( $output['since'], $output['poll']['input']['since'] );
			if ( null === $at ) {
				$this->assertGreaterThanOrEqual( $click, $output['since'] );
			}
		}
		$this->assertSame( $contacts, array() !== $this->child_calls );
	}

	/** Reset must refuse a recent or closed run without contacting the Child. */
	public function test_reset_refuses_before_stall_threshold() {
		foreach ( array(
			array(
				'last_run'  => time(),
				'completed' => 0,
			),
			array(
				'last_run'  => time() - 7200,
				'completed' => time(),
			),
		) as $state ) {
			MainWP_DB_Backup::instance()->update_backup_task_with_values( $this->task_id, $state );
			$preview = $this->execute(
				'reset-backup-task-run',
				array(
					'task_id' => $this->task_id,
					'dry_run' => true,
				)
			);
			$this->assertFalse( $preview['can_proceed'] );
			$this->assertSame( 'mainwp_backup_not_stalled', $preview['refusals'][0]['code'] );
			$error = $this->execute(
				'reset-backup-task-run',
				array(
					'task_id' => $this->task_id,
					'confirm' => true,
				)
			);
			$this->assertSame( 'mainwp_backup_not_stalled', $error->get_error_code() );
			$this->assertSame( 409, $error->get_error_data()['status'] );
		}
		$this->assertSame( array(), $this->child_calls );
	}

	/** Retention and cleanup previews must identify actual stored units without exposing their paths. */
	public function test_retention_previews_and_cleanup_use_recorded_units() {
		global $wpdb;
		update_option( 'mainwp_on_demand_retention_count', 1 );
		$manual = MainWP_DB_Backup::instance()->ensure_manual_backup_task( MainWP_DB::instance()->get_website_by_id( $this->site_id ) );
		MainWP_DB_Backup::instance()->update_backup_task_with_values( $this->task_id, array( 'retention_count' => 2 ) );
		$manual_ids    = array();
		$scheduled_ids = array();
		foreach ( array( (int) $manual->id, $this->task_id ) as $task_id ) {
			for ( $i = 0; $i < 3; ++$i ) {
				$this->assertNotFalse(
					$wpdb->insert(
						MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ),
						array(
							'site_id'  => $this->site_id,
							'task_id'  => $task_id,
							'type'     => 'full',
							'status'   => 'complete',
							'created'  => time() - ( 3 - $i ) * 60,
							'archives' => wp_json_encode(
								array(
									array(
										'file' => 'fixture.zip',
										'path' => '/private/ability-fixture.zip',
										'role' => 'full',
										'size' => 100,
									),
								)
							),
						)
					)
				);
				if ( $task_id === (int) $manual->id ) {
					$manual_ids[] = (int) $wpdb->insert_id;
				} else {
					$scheduled_ids[] = (int) $wpdb->insert_id;
				}
			}
		}
		$site = $this->execute(
			'run-site-backup',
			array(
				'site_id_or_domain' => $this->site_id,
				'dry_run'           => true,
			)
		);
		$this->assertSame( $manual_ids, $site['would_affect']['would_prune_snapshot_ids'] );
		$task = $this->execute(
			'run-backup-task',
			array(
				'task_id' => $this->task_id,
				'dry_run' => true,
			)
		);
		$this->assertSame( array_slice( $scheduled_ids, 0, 2 ), $task['would_affect']['retention_impact'][0]['would_prune_snapshot_ids'] );
		$this->assertSame( array( 'count' ), $task['would_affect']['retention_impact'][0]['reasons'] );
		$cleanup = $this->execute(
			'cleanup-site-backups',
			array(
				'site_id_or_domain' => $this->site_id,
				'keep'              => 1,
				'dry_run'           => true,
			)
		);
		$this->assertEqualsCanonicalizing( array_slice( $manual_ids, 0, 2 ), array_merge( ...array_column( $cleanup['would_affect']['on_demand_units'], 'snapshot_ids' ) ) );
		$this->assertSame( 200, $cleanup['would_affect']['bytes_to_prune'] );
		$this->assertStringNotContainsString( '/private/', wp_json_encode( array( $site, $task, $cleanup ) ) );
		$result = $this->execute(
			'cleanup-site-backups',
			array(
				'site_id_or_domain' => $this->site_id,
				'keep'              => 1,
				'confirm'           => true,
			)
		);
		$this->assert_output( 'cleanup-site-backups', $result );
		$this->assertSame( 2, $result['snapshots_pruned'] );
		$this->assertSame( 2, $result['units_pruned'] );
		foreach ( $scheduled_ids as $id ) {
			$snapshot = \MainWP\Dashboard\MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $id );
			$this->assertSame( 'complete', $snapshot->status );
		}
	}

	/** Cleanup adapters retain snapshot identities and the specific hold reason. */
	public function test_x1_cleanup_ability_and_ajax_report_remaining_ids_and_error_detail() {
		global $wpdb;
		$manual = MainWP_DB_Backup::instance()->ensure_manual_backup_task( MainWP_DB::instance()->get_website_by_id( $this->site_id ) );
		$ids    = array();
		for ( $i = 0; $i < 2; ++$i ) {
			$wpdb->insert(
				MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ),
				array(
					'site_id'  => $this->site_id,
					'task_id'  => $manual->id,
					'type'     => 'full',
					'status'   => 'complete',
					'created'  => time() - ( 2 - $i ) * 60,
					'archives' => wp_json_encode(
						array(
							array(
								'file' => 'fixture.zip',
								'path' => '/private/fixture.zip',
								'role' => 'full',
								'size' => 100,
							),
						)
					),
				)
			);
			$ids[] = (int) $wpdb->insert_id;
		}
		$store = \MainWP\Dashboard\MainWP_Backup_Restore_Job_Store::instance();
		$hold  = $store->reserve_site( $this->site_id );
		try {
			$result = $this->execute(
				'cleanup-site-backups',
				array(
					'site_id_or_domain' => $this->site_id,
					'keep'              => 1,
					'confirm'           => true,
				)
			);
			$this->assert_output( 'cleanup-site-backups', $result );
			$this->assertArrayHasKey( 'remaining_ids', $result );
			$this->assertSame( array( $ids[0] ), $result['remaining_ids'] );
			$this->assertSame( array(), $result['deleted_ids'] );
			$this->assertSame( array(), $result['cloud_deletes_queued'] );
			$this->assertStringContainsString( 'another backup action holds this site', $result['errors'][0]['message'] );
			$this->assertStringNotContainsString( '/private/', wp_json_encode( $result ) );
			$post                 = $_POST;
			$request              = $_REQUEST;
			$_POST                = array(
				'site_id'  => $this->site_id,
				'keep'     => 1,
				'security' => wp_create_nonce( 'mainwp_snapshots_cleanup' ),
			);
			$_REQUEST['security'] = wp_create_nonce( 'mainwp_snapshots_cleanup' );
			$throw                = static function () {
				return static function () {
					throw new \RuntimeException( 'wp_die' );
				};
			};
			add_filter( 'wp_doing_ajax', '__return_true' );
			add_filter( 'wp_die_ajax_handler', $throw );
			ob_start();
			try {
				\MainWP\Dashboard\MainWP_Post_Backup_Handler::instance()->mainwp_snapshots_cleanup();
			} catch ( \RuntimeException $e ) {
				$this->assertSame( 'wp_die', $e->getMessage() );
			} finally {
				$raw = ob_get_clean();
				remove_filter( 'wp_die_ajax_handler', $throw );
				remove_filter( 'wp_doing_ajax', '__return_true' );
				$_POST    = $post;
				$_REQUEST = $request;
			}
			$ajax = json_decode( $raw, true );
			$this->assertSame( array( $ids[0] ), $ajax['remaining_ids'] );
			$this->assertSame( array(), $ajax['deleted_ids'] );
			$this->assertSame( array(), $ajax['cloud_deletes_queued'] );
		} finally {
			$store->release_reservation( $hold );
		}
	}
}
