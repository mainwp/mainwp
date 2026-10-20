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
use MainWP\Dashboard\MainWP_Backup_Schedule_Calculator;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Manage_Backups_Handler;


require_once __DIR__ . '/../backup/class-mainwp-backup-read-fixtures.php';

/** Consent and preview purity apply to every backup action. */
class MainWP_Backups_Task_Abilities_Test extends MainWP_Abilities_Test_Case {
	use Backup_Read_Purity_Guard;

	/**
	 * Site owned by this case.
	 *
	 * @var int
	 */
	private $site_id;
	/**
	 * Task owned by this case.
	 *
	 * @var int
	 */
	private $task_id;
	/**
	 * Created rows need cleanup even when an assertion fails.
	 *
	 * @var array
	 */
	private $created_ids = array();

	/** Allocated identities keep each case independent of later test runs. */
	public function set_up() {
		parent::set_up();
		$this->set_current_user_as_admin();
		MainWP_Backup_Operations::reset_authorized_site_ids_memo();
		update_option( 'mainwp_enableNativeBackups', 1 );
		$this->site_id = $this->create_test_site();
		$task          = MainWP_DB_Backup::instance()->add_backup_task( get_current_user_id(), 'Test backup action', 'daily', 'full', '', (string) $this->site_id, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		$this->assertIsObject( $task );
		$this->task_id     = (int) $task->id;
		$this->created_ids = array( $this->task_id );
	}

	/** Fixture cleanup owns only the rows allocated to this case. */
	public function tear_down() {
		global $wpdb;
		$manual = MainWP_DB_Backup::instance()->get_manual_backup_task( $this->site_id );
		foreach ( array_merge( $this->created_ids, array_filter( array( $manual->id ?? null ) ) ) as $id ) {
			$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup' ), array( 'id' => $id ) );
		}
		$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'site_id' => $this->site_id ) );
		MainWP_Backup_Operations::reset_authorized_site_ids_memo();
		parent::tear_down();
	}

	/**
	 * Consent applies to every task action, including actions that only stop work.
	 *
	 * @return array
	 */
	public static function actions() {
		return array(
			array( 'create-backup-task', 'POST', false, 'add_backup_tasks', false ),
			array( 'update-backup-task', 'POST', false, 'edit_backup_tasks', true ),
			array( 'delete-backup-task', 'POST', false, 'delete_backup_tasks', true ),
			array( 'pause-backup-task', 'POST', true, 'pause_resume_backup_tasks', false ),
			array( 'resume-backup-task', 'POST', true, 'pause_resume_backup_tasks', false ),
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
		if ( 'create-backup-task' === $name ) {
			return array(
				'name'                => 'Created task',
				'type'                => 'full',
				'schedule'            => 'daily',
				'site_ids_or_domains' => array( $this->site_id ),
			);
		}
		return array( 'task_id' => $this->task_id ) + ( 'update-backup-task' === $name ? array( 'name' => 'Updated task' ) : array() );
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
		$output = $call();
		if ( is_array( $output ) && isset( $output['task']['id'] ) ) {
			$this->created_ids[] = $output['task']['id'];
		}
		return $output;
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
	 * Discovery must advertise the operation right and destructive annotations.
	 *
	 * @dataProvider actions

	 * @param string $name Slug.

	 * @param string $method Method.

	 * @param bool   $idempotent Annotation.

	 * @param string $right Right.
	 * @param bool   $destructive Annotation.
	 */
	public function test_ability_is_registered( $name, $method, $idempotent, $right, $destructive ) {
		$ability = $this->ability( $name );
		$this->assertSame( 'mainwp-backups', $ability->get_category() );
		$this->assertNotEmpty( $ability->get_label() );
		$this->assertStringContainsString( 'Possible errors:', $ability->get_description() );
		$this->assertSame( array( $right ), MainWP_Abilities_Util::required_rights_for_ability( $ability->get_name() ) );
		$meta = $ability->get_meta();
		$this->assertTrue( $meta['show_in_rest'] );
		$this->assertFalse( $meta['annotations']['readonly'] );
		$this->assertSame( $destructive, $meta['annotations']['destructive'] );
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
		$output = $this->execute( $name, $this->input( $name ) + array( 'dry_run' => true ) );
		$this->assert_output( $name, $output );
		$this->assertTrue( $output['dry_run'] );
		$this->assertTrue( $output['can_proceed'] );
		$this->assertSame( array(), $output['refusals'] );
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
	 * Pausing must remain available while native backups are off.
	 *
	 * @dataProvider actions
	 * @param string $name Slug.
	 */
	public function test_native_disabled_refuses_writes_and_allows_pause( $name ) {
		update_option( 'mainwp_enableNativeBackups', 0 );
		foreach ( array( 'dry_run', 'confirm' ) as $mode ) {
			$output = $this->execute( $name, $this->input( $name ) + array( $mode => true ) );
			if ( 'pause-backup-task' === $name ) {
				$this->assert_output( $name, $output );
			} else {
				$this->assertWPError( $output );
				$this->assertSame( 'mainwp_backup_legacy_disabled', $output->get_error_code() );
				$this->assertSame( 409, $output->get_error_data()['status'] );
			}
		}
	}

	/** Hidden on-demand rows must never become editable scheduled tasks. */
	public function test_all_task_id_actions_refuse_template_tasks() {
		$manual = MainWP_DB_Backup::instance()->ensure_manual_backup_task( MainWP_DB::instance()->get_website_by_id( $this->site_id ) );
		foreach ( array_slice( self::actions(), 1 ) as $action ) {
			foreach ( array( 'dry_run', 'confirm' ) as $mode ) {
				$input = array_replace(
					$this->input( $action[0] ),
					array(
						'task_id' => (int) $manual->id,
						$mode     => true,
					)
				);
				$error = $this->execute( $action[0], $input );
				$this->assertSame( 'mainwp_backup_task_not_found', $error->get_error_code() );
				$this->assertSame( 404, $error->get_error_data()['status'] );
			}
		}
	}

	/**
	 * Database inactivity markers must survive a partial edit without becoming input.
	 *
	 * @return array
	 */
	public static function calendar_schedules() {
		return array( array( 'daily' ), array( 'weekly' ), array( 'monthly' ) );
	}

	/**
	 * Stored zero interval hours are inactive on calendar schedules.
	 *
	 * @dataProvider calendar_schedules
	 * @param string $schedule Stored schedule.
	 */
	public function test_partial_update_keeps_non_interval_schedule_with_zero_hours( $schedule ) {
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			$this->task_id,
			array(
				'schedule'       => $schedule,
				'interval_hours' => 0,
				'time_of_day'    => '14:20',
			)
		);
		$input   = $this->input( 'update-backup-task' );
		$preview = $this->execute( 'update-backup-task', $input + array( 'dry_run' => true ) );
		$this->assert_output( 'update-backup-task', $preview );
		$this->assertTrue( $preview['can_proceed'] );
		$output = $this->execute( 'update-backup-task', $input + array( 'confirm' => true ) );
		$this->assert_output( 'update-backup-task', $output );
		$this->assertSame( $schedule, $output['task']['schedule'] );
		$this->assertNull( $output['schedule_detail']['interval_hours'] );
		$this->assertSame( '14:20', $output['schedule_detail']['time_of_day'] );
		$stored = MainWP_DB_Backup::instance()->get_backup_task_by_id( $this->task_id );
		$this->assertSame( 0, (int) $stored->interval_hours );
		$this->assertSame( '14:20', $stored->time_of_day );
	}

	/**
	 * Every schedule has an output contract distinct from its submitted fields.
	 *
	 * @return array
	 */
	public static function schedules() {
		return array(
			array(
				array(
					'schedule'       => 'interval',
					'interval_hours' => 3,
				),
				'interval',
			),
			array( array( 'schedule' => 'daily' ), 'elapsed' ),
			array( array( 'schedule' => 'weekly' ), 'elapsed' ),
			array( array( 'schedule' => 'monthly' ), 'elapsed' ),
			array(
				array(
					'schedule'    => 'daily',
					'time_of_day' => '15:10',
				),
				'time_of_day',
			),
			array(
				array(
					'schedule'    => 'weekly',
					'time_of_day' => '15:10',
					'day_of_week' => 3,
				),
				'time_of_day',
			),
			array(
				array(
					'schedule'     => 'monthly',
					'time_of_day'  => '15:10',
					'day_of_month' => 12,
				),
				'time_of_day',
			),
		);
	}

	/**
	 * Preview defaults must agree with the row created after confirmation.
	 *
	 * @dataProvider schedules
	 * @param array  $fields Schedule fields.
	 * @param string $timing Normalized timing.
	 */
	public function test_create_preview_and_confirm_normalize_all_schedules( $fields, $timing ) {
		$input   = array_replace( $this->input( 'create-backup-task' ), $fields );
		$preview = $this->execute( 'create-backup-task', $input + array( 'dry_run' => true ) );
		$this->assert_output( 'create-backup-task', $preview );
		$this->assertSame( $timing, $preview['would_affect']['normalized']['timing'] );
		$this->assertSame( 7, $preview['would_affect']['normalized']['retention']['count'] );
		$this->assertSame( array( $this->site_id ), $preview['would_affect']['resolved_site_ids'] );
		$output = $this->execute( 'create-backup-task', $input + array( 'confirm' => true ) );
		$this->assert_output( 'create-backup-task', $output );
		$this->assertSame( $fields['schedule'], $output['task']['schedule'] );
		$this->assertSame( $preview['would_affect']['normalized']['interval_hours'], $output['schedule_detail']['interval_hours'] );
		$this->assertSame( $preview['would_affect']['normalized']['time_of_day'], $output['schedule_detail']['time_of_day'] );
		if ( 'interval' === $timing ) {
			$this->assertLessThanOrEqual( 2, abs( strtotime( $preview['would_affect']['next_run_at'] ) - strtotime( $output['task']['next_run_at'] ) ) );
		} else {
			$this->assertSame( $preview['would_affect']['next_run_at'], $output['task']['next_run_at'] );
		}
	}

	/**
	 * A transition can start from any stored schedule.
	 *
	 * @return array
	 */
	public static function transitions() {
		$rows = array();
		foreach ( array( 'daily', 'weekly', 'monthly', 'interval' ) as $from ) {
			foreach ( self::schedules() as $to ) {
				$rows[] = array( $to[0], $to[1], $from );
			}
		}
		return $rows;
	}

	/**
	 * Schedule changes must clear fields that the new schedule does not use.
	 *
	 * @dataProvider transitions
	 * @param array  $fields Schedule fields.
	 * @param string $timing Normalized timing.
	 * @param string $from Stored schedule.
	 */
	public function test_update_changes_schedules_with_valid_preview_and_result( $fields, $timing, $from ) {
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			$this->task_id,
			array(
				'schedule'       => $from,
				'interval_hours' => 'interval' === $from ? 2 : 0,
				'time_of_day'    => 'interval' === $from ? '' : '11:40',
			)
		);
		$input = array( 'task_id' => $this->task_id ) + $fields;
		if ( 'interval' !== $fields['schedule'] && 'elapsed' === $timing ) {
			$input['time_of_day'] = null;
		}
		$preview = $this->execute( 'update-backup-task', $input + array( 'dry_run' => true ) );
		$this->assert_output( 'update-backup-task', $preview );
		$output = $this->execute( 'update-backup-task', $input + array( 'confirm' => true ) );
		$this->assert_output( 'update-backup-task', $output );
		$this->assertSame( $fields['schedule'], $output['task']['schedule'] );
		$this->assertSame( $fields['interval_hours'] ?? null, $output['schedule_detail']['interval_hours'] );
		$this->assertSame( $fields['time_of_day'] ?? null, $output['schedule_detail']['time_of_day'] );
	}

	/**
	 * A paused edit keeps its stored slot until resume, including the elapsed sentinel.
	 *
	 * @dataProvider update_schedule_slots
	 * @param bool $paused Whether the task is paused.
	 * @param bool $has_slot Whether the stored next run is positive.
	 */
	public function test_update_schedule_preview_matches_confirmed_slot( $paused, $has_slot ) {
		$db       = MainWP_DB_Backup::instance();
		$old_next = $has_slot ? time() - HOUR_IN_SECONDS : 0;
		$last_run = time() - 5 * HOUR_IN_SECONDS;
		$db->update_backup_task_with_values(
			$this->task_id,
			array(
				'paused'   => (int) $paused,
				'next_run' => $old_next,
				'last_run' => $last_run,
			)
		);
		$input   = array(
			'task_id'        => $this->task_id,
			'schedule'       => 'interval',
			'interval_hours' => 3,
		);
		$preview = $this->execute( 'update-backup-task', $input + array( 'dry_run' => true ) );
		$this->assert_output( 'update-backup-task', $preview );
		$output = $this->execute( 'update-backup-task', $input + array( 'confirm' => true ) );
		$this->assert_output( 'update-backup-task', $output );
		$this->assertSame( $output['task']['next_run_at'], $preview['would_affect']['next_run_at'] );
		$saved      = $db->get_backup_task_by_id( $this->task_id );
		$calculator = new MainWP_Backup_Schedule_Calculator( wp_timezone(), time() );
		$expected   = $paused ? $old_next : $calculator->compute_next_run( $saved, $last_run );
		$this->assertSame( $expected, (int) $saved->next_run );
		if ( $expected > 0 ) {
			$this->assertSame( $expected, strtotime( $preview['would_affect']['next_run_at'] ) );
		} else {
			$this->assertNull( $preview['would_affect']['next_run_at'] );
		}
	}

	/**
	 * Preview must follow the same paused rule for zero and positive slots.
	 *
	 * @return array
	 */
	public static function update_schedule_slots() {
		return array(
			'paused elapsed' => array( true, false ),
			'paused stale'   => array( true, true ),
			'active elapsed' => array( false, false ),
			'active stale'   => array( false, true ),
		);
	}

	/**
	 * Clearing a time must re-enter elapsed scheduling rather than retain a stale next run.
	 *
	 * @dataProvider calendar_schedules
	 * @param string $schedule Stored schedule.
	 */
	public function test_update_null_time_clears_time_and_next_run( $schedule ) {
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			$this->task_id,
			array(
				'schedule'    => $schedule,
				'time_of_day' => '14:20',
				'next_run'    => time() + 86400,
			)
		);
		$input   = array(
			'task_id'     => $this->task_id,
			'time_of_day' => null,
		);
		$preview = $this->execute( 'update-backup-task', $input + array( 'dry_run' => true ) );
		$this->assert_output( 'update-backup-task', $preview );
		$this->assertNull( $preview['would_affect']['next_run_at'] );
		$output = $this->execute( 'update-backup-task', $input + array( 'confirm' => true ) );
		$this->assert_output( 'update-backup-task', $output );
		$this->assertNull( $output['schedule_detail']['time_of_day'] );
		$this->assertNull( $output['task']['next_run_at'] );
		$stored = MainWP_DB_Backup::instance()->get_backup_task_by_id( $this->task_id );
		$this->assertSame( '', $stored->time_of_day );
		$this->assertSame( 0, (int) $stored->next_run );
	}

	/** An update containing only consent and identity cannot transfer ownership. */
	public function test_empty_partial_update_is_refused() {
		$error = $this->execute(
			'update-backup-task',
			array(
				'task_id' => $this->task_id,
				'dry_run' => true,
			)
		);
		$this->assertSame( 'mainwp_invalid_input', $error->get_error_code() );
		$this->assertSame( 400, $error->get_error_data()['status'] );
	}

	/**
	 * IDs must retain their type and spelling before WordPress can sanitize them.
	 *
	 * @return array
	 */
	public static function invalid_ids() {
		$rows = array();
		foreach ( array( 0, -1, 1.0, 1.5, true, false, null, '', '0', '01', '+1', '-1', '1.0', '1e2', ' 1', '1 ', "1\n", 9007199254740992, '9007199254740992', array( 1 ), (object) array( 'id' => 1 ) ) as $id ) {
			$rows[] = array( $id );
		}
		return $rows;
	}

	/**
	 * Both scalar identities and selection arrays must reject coercible inputs.
	 *
	 * @dataProvider invalid_ids
	 * @param mixed $id Rejected identity.
	 */
	public function test_every_id_input_rejects_noncanonical_values( $id ) {
		foreach ( self::actions() as $action ) {
			$name = $action[0];
			$keys = 'create-backup-task' === $name ? array( 'site_ids_or_domains', 'group_ids', 'destination_ids' ) : ( 'update-backup-task' === $name ? array( 'task_id', 'site_ids_or_domains', 'group_ids', 'destination_ids' ) : array( 'task_id' ) );
			foreach ( $keys as $key ) {
				$input = array_replace(
					$this->input( $name ),
					array(
						$key      => 'task_id' === $key ? $id : array( $id ),
						'dry_run' => true,
					)
				);
				$error = $this->execute( $name, $input );
				$this->assertWPError( $error, $name . ':' . $key );
				$this->assertContains( $error->get_error_code(), array( 'ability_invalid_input', 'mainwp_invalid_input' ), $name . ':' . $key );
				$error = $this->execute( $name, $input, true );
				$this->assertSame( 'mainwp_invalid_input', $error->get_error_code(), $name . ':' . $key );
				$this->assertSame( 400, $error->get_error_data()['status'] );
				$response = $this->body_preview( $name, $input );
				$this->assertSame( 400, $response->get_status(), $name . ':' . $key . ':' . wp_json_encode( $response->get_data() ) );
			}
		}
	}

	/**
	 * JSON bodies must preserve whole floats until the raw identity guard runs.
	 *
	 * @param string $name Slug.
	 * @param array  $input Caller input.
	 * @return \WP_REST_Response
	 */
	private function body_preview( $name, array $input ) {
		global $wp_rest_server;
		$previous       = $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );
		$request = new \WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/mainwp/' . $name . '-v1/run' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'input' => $input ), JSON_PRESERVE_ZERO_FRACTION ) );
		try {
			if ( empty( $input['dry_run'] ) ) {
				return rest_do_request( $request );
			}
			return $this->guarded(
				static function () use ( $request ) {
					return rest_do_request( $request );
				}
			);
		} finally {
			$wp_rest_server = $previous;
		}
	}

	/** Selection IDs at the exact ceiling must remain valid identities. */
	public function test_maximum_ids_are_accepted_without_float_coercion() {
		foreach ( array( 9007199254740991, '9007199254740991' ) as $id ) {
			foreach ( array( 'site_ids_or_domains', 'destination_ids', 'task_id' ) as $key ) {
				$name  = 'task_id' === $key ? 'update-backup-task' : 'create-backup-task';
				$input = array_replace(
					$this->input( $name ),
					array(
						$key      => 'task_id' === $key ? $id : array( $id ),
						'dry_run' => true,
					)
				);
				$error = $this->execute( $name, $input );
				$this->assertSame( 404, $error->get_error_data()['status'] );
				$this->assertSame( 404, $this->body_preview( $name, $input )->get_status() );
			}
			$input   = $this->input( 'create-backup-task' ) + array(
				'group_ids' => array( $id ),
				'dry_run'   => true,
			);
			$preview = $this->execute( 'create-backup-task', $input );
			$this->assert_output( 'create-backup-task', $preview );
			$this->assertSame( array( 9007199254740991 ), $preview['would_affect']['normalized']['group_ids'] );
		}
	}

	/** An out-of-scope selection must be hidden before a preview or save. */
	public function test_task_create_and_update_refuse_denied_site_scope() {
		$deny = function ( $allowed, $type, $id ) {
			return 'site' === $type && (int) $id === $this->site_id ? false : $allowed;
		};
		add_filter( 'mainwp_currentusercan', $deny, 10, 3 );
		try {
			foreach ( array( 'create-backup-task', 'update-backup-task' ) as $name ) {
				foreach ( array( 'dry_run', 'confirm' ) as $mode ) {
					$error = $this->execute( $name, $this->input( $name ) + array( $mode => true ) );
					$this->assertSame( 'create-backup-task' === $name ? 'mainwp_site_not_found' : 'mainwp_backup_task_not_found', $error->get_error_code() );
					$this->assertSame( 404, $error->get_error_data()['status'] );
				}
			}
		} finally {
			remove_filter( 'mainwp_currentusercan', $deny, 10 );
		}
	}

	/**
	 * Preview and confirmation must agree through both supported transports.
	 *
	 * @return array
	 */
	public static function update_transports() {
		return array(
			'PHP'  => array( false ),
			'REST' => array( true ),
		);
	}

	/**
	 * Historical fixtures must use database-allocated snapshot identities.
	 *
	 * @param int      $site_id Historical site.
	 * @param int|null $created Snapshot timestamp, or null for an expired snapshot.
	 * @return int
	 */
	private function create_task_history_snapshot( $site_id, $created = null ) {
		global $wpdb;
		$this->assertNotFalse(
			$wpdb->insert(
				MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ),
				array(
					'site_id'  => $site_id,
					'task_id'  => $this->task_id,
					'type'     => 'full',
					'status'   => 'complete',
					'created'  => $created ?? time() - 2 * DAY_IN_SECONDS,
					'archives' => '[]',
				)
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Missing historical sites remain purgeable without exposing hidden existing sites.
	 *
	 * @dataProvider delete_history_scopes
	 * @param string $state Historical site state.
	 * @param string $transport Deletion entry point.
	 */
	public function test_delete_history_site_scope_on_preview_and_delete( $state, $transport ) {
		$this->skip_when_capabilities_bypassed();
		global $wpdb;
		$history_site = $this->create_test_site();
		$snapshot_id  = $this->create_task_history_snapshot( $history_site );
		$before       = MainWP_DB_Backup::instance()->get_backup_task_by_id( $this->task_id );
		if ( 'deleted' === $state ) {
			$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp' ), array( 'id' => $history_site ) );
		}
		$deny  = static function ( $allowed, $type, $id ) use ( $history_site, $state ) {
			return 'hidden' === $state && 'site' === $type && (int) $id === $history_site ? false : $allowed;
		};
		$scope = function () use ( $history_site, $state ) {
			return 'hidden' === $state ? array( $this->site_id ) : array( $this->site_id, $history_site );
		};
		add_filter( 'mainwp_is_single_user', '__return_false' );
		add_filter( 'mainwp_currentusercan', $deny, 10, 3 );
		add_filter( 'mainwp_currentuserallowedaccesssites', $scope );
		try {
			$this->assertNotWPError( MainWP_Backup_Operations::authorize_site( $this->site_id, 'delete_backup_tasks' ) );
			if ( 'deleted' === $state ) {
				$this->assertEmpty( MainWP_DB::instance()->get_website_by_id_unscoped( $history_site ) );
			} else {
				$this->assertNotEmpty( MainWP_DB::instance()->get_website_by_id_unscoped( $history_site ) );
			}
			if ( 'hidden' === $state ) {
				$this->assertEmpty( MainWP_DB::instance()->get_website_by_id( $history_site ) );
			}
			$input = array(
				'task_id'          => $this->task_id,
				'delete_snapshots' => true,
			);
			foreach ( array( 'dry_run', 'confirm' ) as $mode ) {
				if ( 'rest' === $transport ) {
					$response = $this->body_preview( 'delete-backup-task', $input + array( $mode => true ) );
					$this->assertSame( 'hidden' === $state ? 404 : 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
					$output = $response->get_data();
				} elseif ( 'handler' === $transport ) {
					$output = 'dry_run' === $mode ? MainWP_Backup_Operations::preview_task_delete( $this->task_id ) : MainWP_Manage_Backups_Handler::remove_backup_result( $this->task_id );
				} else {
					$output = $this->execute( 'delete-backup-task', $input + array( $mode => true ) );
				}
				if ( 'hidden' === $state ) {
					if ( 'rest' === $transport ) {
						$this->assertSame( 'mainwp_backup_task_not_found', $output['code'] );
					} elseif ( 'handler' === $transport && 'confirm' === $mode ) {
						$this->assertArrayHasKey( 'error', $output );
					} else {
						$this->assertWPError( $output );
						$this->assertSame( 'mainwp_backup_task_not_found', $output->get_error_code() );
					}
				} elseif ( 'dry_run' === $mode ) {
					$this->assertIsArray( $output, is_wp_error( $output ) ? $output->get_error_message() : '' );
					$this->assertSame( array( $snapshot_id ), array_column( $output['would_affect']['snapshots'], 'id' ) );
					$this->assertSame( $history_site, $output['would_affect']['snapshots'][0]['site_id'] );
				} elseif ( 'handler' === $transport ) {
					$this->assertSame( 'SUCCESS', $output['result'] ?? null );
				} else {
					$this->assert_output( 'delete-backup-task', $output );
					$this->assertTrue( $output['snapshots_deleted'] );
				}
				$task     = MainWP_DB_Backup::instance()->get_backup_task_by_id( $this->task_id );
				$snapshot = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ) . ' WHERE id = %d', $snapshot_id ) );
				if ( 'hidden' === $state || 'dry_run' === $mode ) {
					$this->assertEquals( $before, $task );
					$this->assertNotNull( $snapshot );
				} else {
					$this->assertNull( $task );
					$this->assertSame( 'pruned', $snapshot->status, 'Deletion must purge history even without a site row.' );
				}
			}
		} finally {
			remove_filter( 'mainwp_is_single_user', '__return_false' );
			remove_filter( 'mainwp_currentusercan', $deny, 10 );
			remove_filter( 'mainwp_currentuserallowedaccesssites', $scope );
			$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'id' => $snapshot_id ) );
		}
	}

	/**
	 * Every transport must enforce the same historical site scope.
	 *
	 * @return array
	 */
	public static function delete_history_scopes() {
		$rows = array();
		foreach ( array( 'deleted', 'hidden', 'accessible' ) as $state ) {
			foreach ( array( 'ability', 'rest', 'handler' ) as $transport ) {
				$rows[ $state . '-' . $transport ] = array( $state, $transport );
			}
		}
		return $rows;
	}

	/**
	 * Deleted sites still participate in retention without blocking a task edit.
	 *
	 * @dataProvider update_transports
	 * @param bool $rest Whether to use the REST run route.
	 */
	public function test_update_allows_deleted_historical_site_and_lists_retention_impact( $rest ) {
		global $wpdb;
		$history_site = $this->create_test_site();
		$snapshot_id  = $this->create_task_history_snapshot( $history_site );
		// Retention preserves the newest restorable unit even when older units have expired.
		$this->create_task_history_snapshot( $history_site, time() );
		$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp' ), array( 'id' => $history_site ) );
		$this->assertEmpty( MainWP_DB::instance()->get_website_by_id_unscoped( $history_site ) );
		$input = array(
			'task_id'   => $this->task_id,
			'retention' => array( 'days' => 1 ),
		);
		try {
			foreach ( array( 'dry_run', 'confirm' ) as $mode ) {
				if ( $rest ) {
					$response = $this->body_preview( 'update-backup-task', $input + array( $mode => true ) );
					$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
					$output = $response->get_data();
				} else {
					$output = $this->execute( 'update-backup-task', $input + array( $mode => true ) );
				}
				$this->assert_output( 'update-backup-task', $output );
				if ( 'dry_run' === $mode ) {
					$this->assertSame(
						array(
							array(
								'site_id'                  => $history_site,
								'would_prune_snapshot_ids' => array( $snapshot_id ),
								'reasons'                  => array( 'age' ),
							),
						),
						$output['would_affect']['retention_impact']
					);
				} else {
					$this->assertSame( 1, $output['retention']['days'] );
					$this->assertSame( 1, (int) MainWP_DB_Backup::instance()->get_backup_task_by_id( $this->task_id )->retention_days );
				}
			}
		} finally {
			$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'site_id' => $history_site ) );
		}
	}

	/**
	 * An owner cannot lower retention for an existing historical site outside their scope.
	 *
	 * @dataProvider update_transports
	 * @param bool $rest Whether to use the REST run route.
	 */
	public function test_update_refuses_out_of_scope_historical_site_on_preview_confirm_and_ui( $rest ) {
		$this->skip_when_capabilities_bypassed();
		global $wpdb;
		$history_site = $this->create_test_site();
		$snapshot_id  = $this->create_task_history_snapshot( $history_site );
		$before       = MainWP_DB_Backup::instance()->get_backup_task_by_id( $this->task_id );
		$deny         = static function ( $allowed, $type, $id ) use ( $history_site ) {
			return 'site' === $type && (int) $id === $history_site ? false : $allowed;
		};
		$scope        = function () {
			return array( $this->site_id );
		};
		add_filter( 'mainwp_is_single_user', '__return_false' );
		add_filter( 'mainwp_currentusercan', $deny, 10, 3 );
		add_filter( 'mainwp_currentuserallowedaccesssites', $scope );
		$input = array(
			'task_id'   => $this->task_id,
			'retention' => array( 'days' => 1 ),
		);
		try {
			$this->assertSame( get_current_user_id(), (int) $before->userid );
			$this->assertNotWPError( MainWP_Backup_Operations::authorize_site( $this->site_id, 'edit_backup_tasks' ) );
			$this->assertEmpty( MainWP_DB::instance()->get_website_by_id( $history_site ) );
			$this->assertNotEmpty( MainWP_DB::instance()->get_website_by_id_unscoped( $history_site ) );
			foreach ( array( 'dry_run', 'confirm' ) as $mode ) {
				if ( $rest ) {
					$response = $this->body_preview( 'update-backup-task', $input + array( $mode => true ) );
					$this->assertSame( 404, $response->get_status(), wp_json_encode( $response->get_data() ) );
					$this->assertSame( 'mainwp_backup_task_not_found', $response->get_data()['code'] );
				} else {
					$error = $this->execute( 'update-backup-task', $input + array( $mode => true ) );
					$this->assertWPError( $error );
					$this->assertSame( 'mainwp_backup_task_not_found', $error->get_error_code() );
					$this->assertSame( 404, $error->get_error_data()['status'] );
				}
				$this->assertSame( (array) $before, (array) MainWP_DB_Backup::instance()->get_backup_task_by_id( $this->task_id ) );
			}
			$error = MainWP_Backup_Operations::update_task( $this->task_id, array( 'retention' => $input['retention'] ), 'ui' );
			$this->assertWPError( $error );
			$this->assertSame( 'mainwp_backup_task_not_found', $error->get_error_code() );
			$this->assertSame( 404, $error->get_error_data()['status'] );
			$this->assertSame( (array) $before, (array) MainWP_DB_Backup::instance()->get_backup_task_by_id( $this->task_id ) );
		} finally {
			remove_filter( 'mainwp_is_single_user', '__return_false' );
			remove_filter( 'mainwp_currentusercan', $deny, 10 );
			remove_filter( 'mainwp_currentuserallowedaccesssites', $scope );
			$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'id' => $snapshot_id ) );
		}
	}

	/** Preview field diffs must contain only actual changes to normalized values. */
	public function test_update_preview_diff_and_nested_values_match_confirmed_task() {
		$input   = array(
			'task_id'       => $this->task_id,
			'name'          => 'A renamed task',
			'retention'     => array( 'days' => 5 ),
			'exclude_flags' => array( 'cache' => true ),
		);
		$preview = $this->execute( 'update-backup-task', $input + array( 'dry_run' => true ) );
		$this->assert_output( 'update-backup-task', $preview );
		$this->assertSame( array( 'name', 'retention.days', 'exclude_flags.cache' ), array_column( $preview['would_affect']['changes'], 'field' ) );
		$output = $this->execute( 'update-backup-task', $input + array( 'confirm' => true ) );
		$this->assert_output( 'update-backup-task', $output );
		$this->assertSame( 5, $output['retention']['days'] );
		$this->assertTrue( $output['exclude_flags']['cache'] );
		$this->assertSame( 'A renamed task', $output['task']['name'] );
	}

	/** Resume previews must agree with the next run stored on confirmation. */
	public function test_pause_and_resume_preview_next_run_matches_result() {
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			$this->task_id,
			array(
				'time_of_day' => '14:20',
				'next_run'    => time() - 3600,
			)
		);
		foreach ( array( 'pause-backup-task', 'resume-backup-task' ) as $name ) {
			$preview = $this->execute( $name, $this->input( $name ) + array( 'dry_run' => true ) );
			$this->assert_output( $name, $preview );
			$this->assertSame( 'resume-backup-task' === $name, $preview['would_affect']['paused_now'] );
			$output = $this->execute( $name, $this->input( $name ) + array( 'confirm' => true ) );
			$this->assert_output( $name, $output );
			$this->assertSame( $preview['would_affect']['next_run_at'], $output['next_run_at'] );
			$this->assertSame( 'pause-backup-task' === $name, $output['paused'] );
		}
	}

	/**
	 * A resume preview recomputes a zero slot the way the resume does.
	 *
	 * @dataProvider resume_zero_slot_schedules
	 * @param array $values Stored schedule fields of the paused task.
	 */
	public function test_resume_preview_matches_result_for_zero_slot( $values ) {
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			$this->task_id,
			$values + array(
				'paused'   => 1,
				'next_run' => 0,
				'last_run' => time() - 5 * HOUR_IN_SECONDS,
			)
		);
		$preview = $this->execute( 'resume-backup-task', $this->input( 'resume-backup-task' ) + array( 'dry_run' => true ) );
		$this->assert_output( 'resume-backup-task', $preview );
		$output = $this->execute( 'resume-backup-task', $this->input( 'resume-backup-task' ) + array( 'confirm' => true ) );
		$this->assert_output( 'resume-backup-task', $output );
		$this->assertSame( $output['next_run_at'], $preview['would_affect']['next_run_at'] );
		if ( ! empty( $values['interval_hours'] ) || ! empty( $values['time_of_day'] ) ) {
			$this->assertNotNull( $output['next_run_at'] );
		} else {
			$this->assertNull( $output['next_run_at'] );
		}
	}

	/**
	 * Paused schedules whose stored slot is the elapsed sentinel.
	 *
	 * @return array
	 */
	public static function resume_zero_slot_schedules() {
		return array(
			'edited to interval' => array(
				array(
					'schedule'       => 'interval',
					'interval_hours' => 3,
					'time_of_day'    => '',
				),
			),
			'given a time'       => array(
				array(
					'schedule'       => 'daily',
					'interval_hours' => 0,
					'time_of_day'    => '14:20',
				),
			),
			'still elapsed'      => array(
				array(
					'schedule'       => 'daily',
					'interval_hours' => 0,
					'time_of_day'    => '',
				),
			),
		);
	}

	/** Canonical IDs and domains must resolve without changing the authorized site. */
	public function test_canonical_id_strings_and_domains_are_resolved() {
		$site = MainWP_DB::instance()->get_website_by_id( $this->site_id );
		foreach ( array( $this->site_id, (string) $this->site_id, $site->url ) as $id ) {
			$preview = $this->execute(
				'create-backup-task',
				array_replace(
					$this->input( 'create-backup-task' ),
					array(
						'site_ids_or_domains' => array( $id ),
						'dry_run'             => true,
					)
				)
			);
			$this->assert_output( 'create-backup-task', $preview );
			$this->assertSame( array( $this->site_id ), $preview['would_affect']['normalized']['site_ids'] );
		}
		foreach ( array_slice( self::actions(), 1 ) as $action ) {
			$preview = $this->execute(
				$action[0],
				array_replace(
					$this->input( $action[0] ),
					array(
						'task_id' => (string) $this->task_id,
						'dry_run' => true,
					)
				)
			);
			$this->assert_output( $action[0], $preview );
		}
	}

	/** Open runs must not be stranded by task deletion. */
	public function test_delete_preview_and_confirm_refuse_open_run() {
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			$this->task_id,
			array(
				'last_run'  => time(),
				'completed' => 0,
			)
		);
		$preview = $this->execute( 'delete-backup-task', $this->input( 'delete-backup-task' ) + array( 'dry_run' => true ) );
		$this->assert_output( 'delete-backup-task', $preview );
		$this->assertFalse( $preview['can_proceed'] );
		$this->assertSame( 'mainwp_backup_already_running', $preview['refusals'][0]['code'] );
		$error = $this->execute( 'delete-backup-task', $this->input( 'delete-backup-task' ) + array( 'confirm' => true ) );
		$this->assertSame( 'mainwp_backup_already_running', $error->get_error_code() );
		$this->assertSame( 409, $error->get_error_data()['status'] );
	}

	/** Retention previews must identify stored snapshots and the pending owner transfer. */
	public function test_update_retention_and_delete_snapshot_cloud_preview() {
		global $wpdb;
		$ids = array();
		for ( $i = 0; $i < 3; ++$i ) {
			$this->assertNotFalse(
				$wpdb->insert(
					MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ),
					array(
						'site_id'  => $this->site_id,
						'task_id'  => $this->task_id,
						'type'     => 'full',
						'status'   => 'complete',
						'created'  => time() - ( 3 - $i ) * 60,
						'archives' => '[]',
					)
				)
			);
			$ids[] = (int) $wpdb->insert_id;
		}
		$this->assertNotFalse(
			$wpdb->insert(
				MainWP_DB::instance()->get_table_name( 'wp_backup_storage' ),
				array(
					'name'     => 'Cloud fixture',
					'provider' => 's3',
					'settings' => '',
				)
			)
		);
		$destination = (int) $wpdb->insert_id;
		$this->assertNotFalse(
			$wpdb->insert(
				MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ),
				array(
					'snapshot_id'    => $ids[0],
					'destination_id' => $destination,
					'provider'       => 's3',
					'status'         => 'complete',
					'checkpoint'     => '',
					'last_error'     => '',
				)
			)
		);
		$job   = (int) $wpdb->insert_id;
		$owner = self::factory()->user->create( array( 'role' => 'administrator' ) );
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			$this->task_id,
			array(
				'userid'              => $owner,
				'delete_cloud_copies' => 1,
			)
		);
		try {
			$preview = $this->execute(
				'update-backup-task',
				array(
					'task_id'   => $this->task_id,
					'retention' => array( 'count' => 1 ),
					'dry_run'   => true,
				)
			);
			$this->assert_output( 'update-backup-task', $preview );
			$this->assertSame( array_slice( $ids, 0, 2 ), $preview['would_affect']['retention_impact'][0]['would_prune_snapshot_ids'] );
			$this->assertSame( array( 'count' ), $preview['would_affect']['retention_impact'][0]['reasons'] );
			$this->assertSame( get_current_user_id(), $preview['would_affect']['owner_changes_to_user_id'] );
			$preview = $this->execute(
				'delete-backup-task',
				array(
					'task_id' => $this->task_id,
					'dry_run' => true,
				)
			);
			$this->assert_output( 'delete-backup-task', $preview );
			$this->assertSame( $ids, array_column( $preview['would_affect']['snapshots'], 'id' ) );
			$this->assertSame(
				array(
					array(
						'snapshot_id'      => $ids[0],
						'destination_id'   => $destination,
						'destination_name' => 'Cloud fixture',
					),
				),
				$preview['would_affect']['cloud_copies']
			);
			$preview = $this->execute(
				'delete-backup-task',
				array(
					'task_id'          => $this->task_id,
					'delete_snapshots' => false,
					'dry_run'          => true,
				)
			);
			$this->assertSame( array(), $preview['would_affect']['snapshots'] );
			$this->assertSame( array(), $preview['would_affect']['cloud_copies'] );
			$output = $this->execute(
				'delete-backup-task',
				array(
					'task_id'          => $this->task_id,
					'delete_snapshots' => false,
					'confirm'          => true,
				)
			);
			$this->assert_output( 'delete-backup-task', $output );
			$this->assertFalse( $output['snapshots_deleted'] );
			$this->assertSame( 3, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ) . ' WHERE task_id = %d', $this->task_id ) ) );
		} finally {
			$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ), array( 'id' => $job ) );
			$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup_storage' ), array( 'id' => $destination ) );
		}
	}
	/**
	 * Partial uploads can leave objects just as complete Local Path uploads do.
	 *
	 * @dataProvider task_delete_copy_cases
	 * @param string $provider Destination provider.
	 * @param string $status Upload status.
	 * @param int    $delete_cloud Whether copies should be deleted.
	 */
	public function test_delete_cloud_preview_matches_confirm( $provider, $status, $delete_cloud ) {
		global $wpdb;
		$db = MainWP_DB::instance();
		$this->assertNotFalse(
			$wpdb->insert(
				$db->get_table_name( 'wp_backup_snapshot' ),
				array(
					'site_id'  => $this->site_id,
					'task_id'  => $this->task_id,
					'type'     => 'full',
					'status'   => 'complete',
					'created'  => time(),
					'archives' => '[]',
				)
			)
		);
		$snapshot_id  = (int) $wpdb->insert_id;
		$destinations = array();
		$expected     = array();
		try {
			foreach ( array( array( $provider, $status ), array( 's3', 'complete' ) ) as $case ) {
				$this->assertNotFalse(
					$wpdb->insert(
						$db->get_table_name( 'wp_backup_storage' ),
						array(
							'name'     => 'Copy fixture',
							'provider' => $case[0],
							'settings' => '',
						)
					)
				);
				$destination    = (int) $wpdb->insert_id;
				$destinations[] = $destination;
				$this->assertNotFalse(
					$wpdb->insert(
						$db->get_table_name( 'wp_backup_upload_job' ),
						array(
							'snapshot_id'    => $snapshot_id,
							'destination_id' => $destination,
							'provider'       => $case[0],
							'status'         => $case[1],
							'checkpoint'     => '',
							'last_error'     => '',
						)
					)
				);
				if ( $delete_cloud ) {
					$expected[] = array(
						'snapshot_id'      => $snapshot_id,
						'destination_id'   => $destination,
						'destination_name' => 'Copy fixture',
					);
				}
			}
			MainWP_DB_Backup::instance()->update_backup_task_with_values( $this->task_id, array( 'delete_cloud_copies' => $delete_cloud ) );
			$preview = $this->execute(
				'delete-backup-task',
				array(
					'task_id' => $this->task_id,
					'dry_run' => true,
				)
			);
			$this->assert_output( 'delete-backup-task', $preview );
			$this->assertSame( $expected, $preview['would_affect']['cloud_copies'] );
			$purge_result = null;
			$capture      = static function ( $task_id, $result ) use ( &$purge_result ) {
				$purge_result = $result;
			};
			add_action( 'mainwp_backup_task_snapshots_purged', $capture, 10, 2 );
			try {
				$output = $this->execute(
					'delete-backup-task',
					array(
						'task_id' => $this->task_id,
						'confirm' => true,
					)
				);
			} finally {
				remove_action( 'mainwp_backup_task_snapshots_purged', $capture );
			}
			$this->assert_output( 'delete-backup-task', $output );
			$this->assertTrue( $output['snapshots_deleted'] );
			$this->assertIsArray( $purge_result );
			foreach ( $preview['would_affect']['snapshots'] as $snapshot ) {
				$for_snapshot = static function ( $copy ) use ( $snapshot ) {
					return $copy['snapshot_id'] === $snapshot['id'];
				};
				$this->assertSame(
					array_column( array_filter( $preview['would_affect']['cloud_copies'], $for_snapshot ), 'destination_id' ),
					array_column( array_filter( $purge_result['cloud_deletes_queued'], $for_snapshot ), 'destination_id' )
				);
			}
		} finally {
			$wpdb->delete( $db->get_table_name( 'wp_backup_upload_job' ), array( 'snapshot_id' => $snapshot_id ) );
			foreach ( $destinations as $destination ) {
				$wpdb->delete( $db->get_table_name( 'wp_backup_storage' ), array( 'id' => $destination ) );
			}
		}
	}

	/**
	 * Delete intent applies equally to every upload provider and terminal state.
	 *
	 * @return array Cases.
	 */
	public static function task_delete_copy_cases() {
		return array(
			'local complete'   => array( 'local', 'complete', 1 ),
			'failed permanent' => array( 's3', 'failed_permanent', 1 ),
			'copies retained'  => array( 'local', 'complete', 0 ),
		);
	}

	/** Older form tasks can have names beyond the machine-input ceiling. */
	public function test_delete_preview_preserves_long_stored_task_name() {
		$name = str_repeat( 'Task', 100 );
		MainWP_DB_Backup::instance()->update_backup_task_with_values( $this->task_id, array( 'name' => $name ) );
		$preview = $this->execute( 'delete-backup-task', $this->input( 'delete-backup-task' ) + array( 'dry_run' => true ) );
		$this->assert_output( 'delete-backup-task', $preview );
		$this->assertSame( $name, $preview['would_affect']['task_name'] );
	}

	/** A long stored name must not turn every untouched field into a reported change. */
	public function test_update_preview_of_long_stored_name_lists_only_submitted_changes() {
		MainWP_DB_Backup::instance()->update_backup_task_with_values( $this->task_id, array( 'name' => str_repeat( 'Task', 100 ) ) );
		$preview = $this->execute(
			'update-backup-task',
			array(
				'task_id'   => $this->task_id,
				'retention' => array( 'count' => 3 ),
				'dry_run'   => true,
			)
		);
		$this->assert_output( 'update-backup-task', $preview );
		$this->assertSame( array( 'retention.count' ), array_column( $preview['would_affect']['changes'], 'field' ) );
	}
}
