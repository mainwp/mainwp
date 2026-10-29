<?php
/**
 * Task writes reject malformed selections before authorization or persistence.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Operations;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Manage_Backups_Handler;
use MainWP\Dashboard\MainWP_Post_Backup_Handler;

/** Shared task validation and AJAX compatibility. */
class MainWP_Backup_Task_Input_Allowlist_Test extends MainWP_Abilities_Test_Case {

	/**
	 * Each case needs an authenticated owner and an enabled native engine.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->set_current_user_as_admin();
		update_option( 'mainwp_enableNativeBackups', 1 );
		add_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'child_reply' ) );
	}

	/**
	 * Fixture options and request values cannot leak into neighboring cases.
	 */
	public function tearDown(): void {
		$_POST    = array();
		$_REQUEST = array();
		delete_option( 'mainwp_enableNativeBackups' );
		remove_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'child_reply' ) );
		parent::tearDown();
	}

	/**
	 * A failed guard must not contact a real Child during the baseline run.
	 *
	 * @return array
	 */
	public function child_reply(): array {
		return array( 'error' => 'No Child calls are expected.' );
	}

	/**
	 * Valid input isolates the field each negative case changes.
	 *
	 * @param int $site_id Selected site.
	 * @return array Valid service input.
	 */
	private function fields( int $site_id ): array {
		return array(
			'name'     => 'Input allowlist task',
			'type'     => 'full',
			'schedule' => 'daily',
			'site_ids' => array( $site_id ),
		);
	}

	/**
	 * The WordPress termination hook lets each case inspect the resulting task row.
	 *
	 * @param string $action AJAX method.
	 * @param array  $fields Posted fields.
	 * @return array Captured JSON response.
	 */
	private function ajax( string $action, array $fields ): array {
		$_POST                = $fields;
		$nonce                = wp_create_nonce( $action );
		$_POST['security']    = $nonce;
		$_REQUEST['security'] = $nonce;
		$throw                = static function () {
			return static function () {
				throw new \RuntimeException( 'wp_die' );
			};
		};
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $throw );
		ob_start();
		try {
			MainWP_Post_Backup_Handler::instance()->$action();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_die', $e->getMessage() );
		} finally {
			$raw = ob_get_clean();
			remove_filter( 'wp_doing_ajax', '__return_true' );
			remove_filter( 'wp_die_ajax_handler', $throw );
		}
		$response = json_decode( $raw, true );
		$this->assertIsArray( $response, $raw );
		return $response;
	}

	/**
	 * Service inputs use the same raw selections as the form.
	 *
	 * @param array $fields Service fields.
	 * @return array UI form field names.
	 */
	private function posted( array $fields ): array {
		foreach ( array(
			'site_ids'        => 'sites',
			'group_ids'       => 'groups',
			'destination_ids' => 'destinations',
			'archive_format'  => 'archiveFormat',
		) as $key => $ui_key ) {
			if ( array_key_exists( $key, $fields ) ) {
				$fields[ $ui_key ] = $fields[ $key ];
				unset( $fields[ $key ] );
			}
		}
		return $fields;
	}

	/**
	 * Every malformed list must refuse as a whole, including mixed valid/bad lists.
	 *
	 * @return array
	 */
	public static function invalid_inputs(): array {
		$inputs = array(
			'schedule' => array( 'schedule', 'hourly' ),
			'archive'  => array( 'archive_format', 'rar' ),
		);
		$bad    = array( '5abc', ' 5', '05', '0', '-1', '1.0', '1e3', true, 1.5, null, array( 5 ), (object) array( 'id' => 5 ), (string) PHP_INT_MAX . '0', 0, -1 );
		foreach ( array( 'site_ids', 'group_ids', 'destination_ids' ) as $field ) {
			$inputs[ $field . '-scalar' ] = array( $field, '5' );
			$inputs[ $field . '-object' ] = array( $field, (object) array( 'id' => 5 ) );
			$inputs[ $field . '-null' ]   = array( $field, null );
			$inputs[ $field . '-bool' ]   = array( $field, true );
			$inputs[ $field . '-float' ]  = array( $field, 1.5 );
			foreach ( $bad as $index => $value ) {
				$inputs[ $field . '-' . $index ] = array( $field, array( 5, $value ) );
			}
		}
		$cases = array();
		foreach ( array( 'ajax-create', 'ajax-update', 'service-create', 'service-update' ) as $transport ) {
			foreach ( $inputs as $label => $input ) {
				$cases[ $transport . '-' . $label ] = array( $transport, $input[0], $input[1] );
			}
		}
		return $cases;
	}

	/**
	 * Malformed input must refuse before any task write.
	 *
	 * @dataProvider invalid_inputs
	 * @param string $transport Entry point.
	 * @param string $field Invalid field.
	 * @param mixed  $value Invalid value.
	 */
	public function test_invalid_input_leaves_tasks_unchanged( string $transport, string $field, $value ): void {
		$site   = $this->create_test_site();
		$fields = $this->fields( $site );
		$db     = MainWP_DB_Backup::instance();
		$task   = $db->add_backup_task( get_current_user_id(), 'Original', 'daily', 'full', '', (string) $site, '', '', '', 0, 0, 0, 0, 0, 'site', 0, 0, 150, 0 );
		$this->assertNotFalse( $task );
		$before           = $db->get_backup_tasks();
		$fields[ $field ] = $value;
		if ( str_starts_with( $transport, 'ajax' ) ) {
			$posted       = $this->posted( $fields );
			$posted['id'] = (int) $task->id;
			$response     = $this->ajax( 'ajax-create' === $transport ? 'mainwp_addbackup' : 'mainwp_updatebackup', $posted );
			$this->assertArrayHasKey( 'error', $response );
		} else {
			$response = 'service-create' === $transport ? MainWP_Backup_Operations::create_task( $fields, 'ability' ) : MainWP_Backup_Operations::update_task( (int) $task->id, $fields, 'ability' );
			$this->assertWPError( $response );
			$this->assertSame( 'mainwp_invalid_input', $response->get_error_code() );
		}
		$this->assertEquals( $before, $db->get_backup_tasks(), 'Refusal must precede every task write.' );
	}

	/**
	 * Hidden on-demand rows must remain inaccessible through every task action.
	 *
	 * @return array
	 */
	public static function task_actions(): array {
		$cases = array();
		foreach ( array( 'update', 'remove', 'pause', 'resume', 'reset', 'run' ) as $action ) {
			foreach ( array( 'ajax', 'service', 'public' ) as $transport ) {
				if ( 'public' !== $transport || in_array( $action, array( 'remove', 'pause', 'resume', 'run' ), true ) ) {
					$cases[ $transport . '-' . $action ] = array( $transport, $action );
				}
			}
		}
		return $cases;
	}

	/**
	 * A hidden task is indistinguishable from a missing task to task actions.
	 *
	 * @dataProvider task_actions
	 * @param string $transport Entry point.
	 * @param string $action Task operation.
	 */
	public function test_template_task_is_hidden_and_unchanged( string $transport, string $action ): void {
		$db   = MainWP_DB_Backup::instance();
		$site = $this->create_test_site();
		$task = $db->add_backup_task( get_current_user_id(), 'Hidden task', 'daily', 'full', '', (string) $site, '', '', '', 1, 0, 0, 0, 0, 'site', 0, 0, 150, 0 );
		$this->assertNotFalse( $task );
		$id   = (int) $task->id;
		$maps = array(
			'update' => 'mainwp_updatebackup',
			'remove' => 'mainwp_removebackup',
			'pause'  => 'mainwp_pausebackup',
			'resume' => 'mainwp_resumebackup',
			'reset'  => 'mainwp_native_backup_task_reset',
			'run'    => 'mainwp_backuptask_run_now',
		);
		if ( 'ajax' === $transport ) {
			$response = $this->ajax(
				$maps[ $action ],
				array_merge(
					$this->posted( $this->fields( $site ) ),
					array(
						'id'      => $id,
						'task_id' => $id,
					)
				)
			);
			$this->assertArrayHasKey( in_array( $action, array( 'remove', 'pause', 'resume' ), true ) ? 'notask' : 'error', $response );
		} elseif ( 'public' === $transport ) {
			$methods  = array(
				'remove' => 'remove_backup_result',
				'pause'  => 'pause_backup_result',
				'resume' => 'resume_backup_result',
				'run'    => 'run_backup_task_now',
			);
			$response = MainWP_Manage_Backups_Handler::{$methods[ $action ]}( $id );
			$this->assertArrayHasKey( 'run' === $action ? 'error' : 'notask', $response );
		} else {
			$methods  = array(
				'remove' => 'delete_task',
				'pause'  => 'pause_task',
				'resume' => 'resume_task',
				'reset'  => 'reset_task_run',
				'run'    => 'run_task',
			);
			$response = 'update' === $action ? MainWP_Backup_Operations::update_task( $id, $this->fields( $site ), 'ability' ) : MainWP_Backup_Operations::{$methods[ $action ]}( $id );
			$this->assertWPError( $response );
			$this->assertSame( 'mainwp_backup_task_not_found', $response->get_error_code() );
		}
		$this->assertEquals( $task, $db->get_backup_task_by_id( $id ) );
	}

	/**
	 * Deduplication cannot change the authorized selection after the scope check.
	 */
	public function test_duplicate_ids_authorize_and_store_the_same_selection(): void {
		$first  = $this->create_test_site();
		$second = $this->create_test_site();
		$seen   = array();
		$filter = static function ( $allowed, $type, $id ) use ( &$seen ) {
			if ( 'site' === $type ) {
				$seen[] = (int) $id;
			}
			return $allowed;
		};
		add_filter( 'mainwp_currentusercan', $filter, 10, 3 );
		try {
			$this->ajax( 'mainwp_addbackup', array_merge( $this->posted( $this->fields( $first ) ), array( 'sites' => array( (string) $first, $first, (string) $second ) ) ) );
		} finally {
			remove_filter( 'mainwp_currentusercan', $filter, 10 );
		}
		$tasks = MainWP_DB_Backup::instance()->get_backup_tasks();
		$this->assertCount( 1, $tasks );
		$this->assertSame( $first . ',' . $second, $tasks[0]->sites );
		$this->assertSame( $tasks[0]->sites, $tasks[0]->authorized_sites );
		$this->assertSame( array( $first, $second ), array_values( array_unique( $seen ) ) );
	}

	/**
	 * Partial edits keep omitted values and schedule changes clear inactive columns.
	 */
	public function test_partial_updates_and_all_schedule_transitions(): void {
		$site = $this->create_test_site();
		foreach ( array( 'daily', 'weekly', 'monthly', 'interval' ) as $from ) {
			foreach ( array( 'daily', 'weekly', 'monthly', 'interval' ) as $to ) {
				$fields = array_merge(
					$this->fields( $site ),
					array(
						'schedule'       => $from,
						'retention'      => array( 'days' => 20 ),
						'archive_format' => 'tar.gz',
					)
				);
				$fields[ 'interval' === $from ? 'interval_hours' : 'time_of_day' ] = 'interval' === $from ? 4 : '06:30';
				$created = MainWP_Backup_Operations::create_task( $fields, 'ability' );
				$this->assertIsArray( $created );
				$id = $created['task_id'];
				$this->assertIsArray( MainWP_Backup_Operations::update_task( $id, array( 'name' => 'Renamed' ), 'ability' ) );
				$before = MainWP_DB_Backup::instance()->get_backup_task_by_id( $id );
				$this->assertSame( 20, (int) $before->retention_days );
				$this->assertSame( 7, (int) $before->retention_count );
				$this->assertSame( 'tar.gz', $before->archiveFormat );
				$update = array( 'schedule' => $to );
				if ( 'interval' === $to ) {
					$update['interval_hours'] = 6;
				}
				$this->assertIsArray( MainWP_Backup_Operations::update_task( $id, $update, 'ability' ) );
				$row = MainWP_DB_Backup::instance()->get_backup_task_by_id( $id );
				$this->assertSame( 'interval' === $to ? 6 : 0, (int) $row->interval_hours );
				$this->assertSame( 'interval' === $to || 'interval' === $from ? '' : '06:30', $row->time_of_day );
				if ( 'interval' !== $to ) {
					$this->assertIsArray( MainWP_Backup_Operations::update_task( $id, array( 'time_of_day' => null ), 'ability' ) );
					$row = MainWP_DB_Backup::instance()->get_backup_task_by_id( $id );
					$this->assertSame( '', $row->time_of_day );
					$this->assertSame( 0, (int) $row->next_run );
				}
			}
		}
	}

	/**
	 * Schedule input rules distinguish omitted fields from explicitly inapplicable ones.
	 */
	public function test_schedule_fields_and_normalized_output(): void {
		$site = $this->create_test_site();
		foreach ( array( 'daily', 'weekly', 'monthly', 'interval' ) as $schedule ) {
			foreach ( array( false, true ) as $timed ) {
				if ( 'interval' === $schedule && $timed ) {
					continue;
				}
				$fields = array_merge( $this->fields( $site ), array( 'schedule' => $schedule ) );
				if ( 'interval' === $schedule ) {
					$fields['interval_hours'] = 4;
				} elseif ( $timed ) {
					$fields['time_of_day'] = '06:30';
				}
				$result = MainWP_Backup_Operations::create_task( $fields, 'ability' );
				$this->assertIsArray( $result );
				$normalized = $result['normalized'];
				$this->assertSame( 'interval' === $schedule ? 'interval' : ( $timed ? 'time_of_day' : 'elapsed' ), $normalized['timing'] );
				$this->assertSame( 'interval' === $schedule ? 4 : null, $normalized['interval_hours'] );
				$this->assertSame( $timed ? '06:30' : null, $normalized['time_of_day'] );
				$this->assertSame( 'weekly' === $schedule ? 0 : null, $normalized['day_of_week'] );
				$this->assertSame( 'monthly' === $schedule ? 1 : null, $normalized['day_of_month'] );
				$this->assertSame( 7, $normalized['retention']['count'] );
				$this->assertSame( 150, $normalized['archive_tuning']['max_file_descriptors'] );
				if ( ! $timed && 'interval' !== $schedule ) {
					$this->assertNull( $result['next_run_at'] );
				}
				$id = $result['task_id'];
				$this->assertWPError( MainWP_Backup_Operations::update_task( $id, array(), 'ability' ) );
				foreach ( array(
					'interval_hours' => 2,
					'time_of_day'    => '06:30',
					'day_of_week'    => 2,
					'day_of_month'   => 10,
				) as $key => $value ) {
					$applies = array(
						'interval_hours' => 'interval',
						'time_of_day'    => 'interval' === $schedule ? '' : $schedule,
						'day_of_week'    => 'weekly',
						'day_of_month'   => 'monthly',
					);
					if ( $schedule !== $applies[ $key ] ) {
						$before = MainWP_DB_Backup::instance()->get_backup_task_by_id( $id );
						$this->assertWPError( MainWP_Backup_Operations::update_task( $id, array( $key => $value ), 'ability' ) );
						$this->assertEquals( $before, MainWP_DB_Backup::instance()->get_backup_task_by_id( $id ) );
					}
				}
				if ( 'interval' === $schedule ) {
					$this->assertIsArray( MainWP_Backup_Operations::update_task( $id, array( 'time_of_day' => null ), 'ability' ) );
				}
			}
		}
		$this->assertWPError( MainWP_Backup_Operations::create_task( array_merge( $this->fields( $site ), array( 'schedule' => 'interval' ) ), 'ability' ) );
		$this->assertWPError( MainWP_Backup_Operations::create_task( array_merge( $this->fields( $site ), array( 'time_of_day' => null ) ), 'ability' ) );
		$this->assertWPError( MainWP_Backup_Operations::create_task( array_merge( $this->fields( $site ), array( 'site_ids' => array() ) ), 'ability' ) );
	}

	/**
	 * The service must classify a disabled engine before invoking the legacy runner.
	 */
	public function test_run_task_disabled_error_code(): void {
		$created = MainWP_Backup_Operations::create_task( $this->fields( $this->create_test_site() ), 'ability' );
		$this->assertIsArray( $created );
		update_option( 'mainwp_enableNativeBackups', 0 );
		$error = MainWP_Backup_Operations::run_task( $created['task_id'], 'ability' );
		$this->assertWPError( $error );
		$this->assertSame( 'mainwp_backup_legacy_disabled', $error->get_error_code() );
		$this->assertSame( 409, $error->get_error_data()['status'] );
		$this->assertSame( array( 'result' => 'SUCCESS' ), MainWP_Backup_Operations::pause_task( $created['task_id'] ) );
	}

	/** A paused task refusal must not claim that another run is active. */
	public function test_run_task_paused_refusal_has_stable_code(): void {
		$created = MainWP_Backup_Operations::create_task( $this->fields( $this->create_test_site() ), 'ability' );
		$this->assertIsArray( $created );
		MainWP_DB_Backup::instance()->update_backup_task_with_values( $created['task_id'], array( 'paused' => 1 ) );
		$error = MainWP_Backup_Operations::run_task( $created['task_id'], 'ability' );
		$this->assertWPError( $error );
		$this->assertSame( 'mainwp_backup_task_paused', $error->get_error_code() );
		$this->assertNotSame( 'mainwp_backup_already_running', $error->get_error_code() );
		$this->assertSame( 409, $error->get_error_data()['status'] );
		$this->assertSame( 'This task is paused. Resume it before running it.', $error->get_error_message() );
	}

	/** The existing form saves, preserves and disables local archive removal. */
	public function test_ajax_task_remove_local_after_upload_roundtrip(): void {
		$store                               = \MainWP\Dashboard\MainWP_Backup_Storage_Destinations::instance();
		$destination                         = $store->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Form retention fixture',
				'settings' => array(),
			)
		);
		$posted                              = $this->posted( $this->fields( $this->create_test_site() ) );
		$posted['destinations']              = array( (string) $destination );
		$posted['remove_local_after_upload'] = '1';
		try {
			$response = $this->ajax( 'mainwp_addbackup', $posted );
			$this->assertSame( array( 'result' => 'Task created successfully.' ), $response );
			$tasks      = MainWP_DB_Backup::instance()->get_backup_tasks();
			$task       = end( $tasks );
			$protection = \MainWP\Dashboard\MainWP_Backup_Retention_Protection::class;
			$this->assertTrue( $protection::task_removes_local_archives( (int) $task->id ) );
			$posted['id'] = $task->id;
			unset( $posted['remove_local_after_upload'] );
			$this->assertSame( array( 'result' => 'Task updated successfully.' ), $this->ajax( 'mainwp_updatebackup', $posted ) );
			$this->assertTrue( $protection::task_removes_local_archives( (int) $task->id ) );
			$posted['remove_local_after_upload'] = '0';
			$posted['destinations']              = array();
			$this->assertSame( array( 'result' => 'Task updated successfully.' ), $this->ajax( 'mainwp_updatebackup', $posted ) );
			$this->assertFalse( $protection::task_removes_local_archives( (int) $task->id ) );
		} finally {
			$store->delete_destination( $destination );
		}
	}

	/** The form's existing limits must survive the shared validator. */
	public function test_ajax_create_accepts_older_form_limits(): void {
		$db     = MainWP_DB_Backup::instance();
		$before = $db->get_backup_tasks();
		$posted = $this->posted( $this->fields( $this->create_test_site() ) );

		$posted['retention_count']                = '2000';
		$posted['maximumFileDescriptors']         = '250000';
		$posted['maximumFileDescriptorsOverride'] = '1';

		$response = $this->ajax( 'mainwp_addbackup', $posted );
		$this->assertSame( array( 'result' => 'Task created successfully.' ), $response );
		$tasks = $db->get_backup_tasks();
		$this->assertCount( count( $before ) + 1, $tasks );
		$task = end( $tasks );
		$this->assertSame( 2000, (int) $task->retention_count );
		$this->assertSame( 250000, (int) $task->maximumFileDescriptors );
	}

	/** Reposting an older task's policy must not prevent a name edit. */
	public function test_ajax_edit_preserves_older_form_retention(): void {
		$fields  = $this->fields( $this->create_test_site() );
		$created = MainWP_Backup_Operations::create_task( $fields, 'ability' );
		$this->assertIsArray( $created );
		$id = $created['task_id'];
		MainWP_DB_Backup::instance()->update_backup_task_with_values( $id, array( 'retention_count' => 2000 ) );
		$posted                    = $this->posted( $fields );
		$posted['id']              = $id;
		$posted['name']            = 'Renamed older task';
		$posted['retention_count'] = '2000';
		$response                  = $this->ajax( 'mainwp_updatebackup', $posted );
		$this->assertSame( array( 'result' => 'Task updated successfully.' ), $response );
		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $id );
		$this->assertSame( 'Renamed older task', $task->name );
		$this->assertSame( 2000, (int) $task->retention_count );
	}

	/** Form compatibility must not relax machine write ceilings. */
	public function test_ability_create_refuses_older_form_limits(): void {
		$fields = $this->fields( $this->create_test_site() );
		$db     = MainWP_DB_Backup::instance();
		$before = $db->get_backup_tasks();
		foreach ( array(
			array( 'retention' => array( 'count' => 2000 ) ),
			array( 'archive_tuning' => array( 'max_file_descriptors' => 250000 ) ),
		) as $limits ) {
			$error = MainWP_Backup_Operations::create_task( array_merge( $fields, $limits ), 'ability' );
			$this->assertWPError( $error );
			$this->assertSame( 'mainwp_invalid_input', $error->get_error_code() );
			$this->assertEquals( $before, $db->get_backup_tasks(), 'A refused ability create must leave the task table unchanged.' );
		}
	}

	/**
	 * Machine task runs cannot create a browser click's baseline transient.
	 */
	public function test_ability_run_has_no_ui_click_state(): void {
		$created = MainWP_Backup_Operations::create_task( $this->fields( $this->create_test_site() ), 'ability' );
		$this->assertIsArray( $created );
		$clicks  = array();
		$capture = static function ( $key ) use ( &$clicks ) {
			if ( str_starts_with( $key, '_transient_mainwp_backup_click_' ) ) {
				$clicks[] = $key;
			}
		};
		add_action( 'added_option', $capture );
		try {
			$result = MainWP_Backup_Operations::run_task( $created['task_id'], 'ability' );
		} finally {
			remove_action( 'added_option', $capture );
		}
		$this->assertIsArray( $result );
		$this->assertSame( array(), $clicks );
		$this->assertArrayNotHasKey( 'initiation_id', $result );
	}

	/**
	 * Input ceilings cannot invalidate an omitted value the older form already stored.
	 */
	public function test_partial_update_preserves_older_retention_values(): void {
		$created = MainWP_Backup_Operations::create_task( $this->fields( $this->create_test_site() ), 'ability' );
		$this->assertIsArray( $created );
		$id = $created['task_id'];
		MainWP_DB_Backup::instance()->update_backup_task_with_values( $id, array( 'retention_count' => 2000 ) );
		$result = MainWP_Backup_Operations::update_task( $id, array( 'retention' => array( 'days' => 20 ) ), 'ability' );
		$this->assertIsArray( $result );
		$this->assertSame( 2000, $result['normalized']['retention']['count'] );
		$this->assertSame( 2000, (int) MainWP_DB_Backup::instance()->get_backup_task_by_id( $id )->retention_count );
		$this->assertSame( 20, (int) MainWP_DB_Backup::instance()->get_backup_task_by_id( $id )->retention_days );
	}

	/**
	 * The ceiling matrix: form context keeps its unbounded values, machine callers get the schema.
	 *
	 * @return array
	 */
	public static function ceiling_matrix(): array {
		$wide  = str_repeat( "\u{5907}", 256 );
		$rows  = array(
			'M1 name 255 ascii'                => array( array( 'name' => str_repeat( 'a', 255 ) ), array(), null, null ),
			'M2 name 256 ascii'                => array( array( 'name' => str_repeat( 'a', 256 ) ), array(), null, 'name' ),
			'M3 name 86 wide'                  => array( array( 'name' => str_repeat( "\u{5907}", 86 ) ), array(), null, null ),
			'M4 name 256 wide'                 => array( array( 'name' => $wide ), array(), null, 'name' ),
			'M5 name whitespace'               => array( array( 'name' => '   ' ), array(), 'name', 'name' ),
			'M6 name not a string'             => array( array( 'name' => 5 ), array(), 'name', 'name' ),
			'M7 count 1000'                    => array( array( 'retention' => array( 'count' => 1000 ) ), array(), null, null ),
			'M8 count 1001'                    => array( array( 'retention' => array( 'count' => 1001 ) ), array(), null, 'retention' ),
			'M9 count negative'                => array( array( 'retention' => array( 'count' => -1 ) ), array(), 'retention', 'retention' ),
			'M10 stored count omitted'         => array( array( 'name' => 'Renamed' ), array( 'retention_count' => 2000 ), null, null ),
			'M11 descriptors 100001'           => array( array( 'archive_tuning' => array( 'max_file_descriptors' => 100001 ) ), array(), null, 'archive_tuning' ),
			'M12 days 3651'                    => array( array( 'retention' => array( 'days' => 3651 ) ), array(), null, 'retention' ),
			'M12 storage 104857601'            => array( array( 'retention' => array( 'storage_mb' => 104857601 ) ), array(), null, 'retention' ),
			'M12 chain 1001'                   => array( array( 'retention' => array( 'max_chain_length' => 1001 ) ), array(), null, 'retention' ),
			'M13 exclude 255 wide'             => array( array( 'exclude' => array( str_repeat( "\u{5907}", 255 ) ) ), array(), null, null ),
			'M14 exclude 256'                  => array( array( 'exclude' => array( str_repeat( 'a', 256 ) ) ), array(), null, 'exclude' ),
			'M15 exclude not a string'         => array( array( 'exclude' => array( 5 ) ), array(), 'exclude', 'exclude' ),
			'M16 exclude long string'          => array( array( 'exclude' => str_repeat( 'a', 300 ) ), array(), null, null ),
			'M17 stored name omitted'          => array( array( 'retention' => array( 'days' => 2 ) ), array( 'name' => str_repeat( 'a', 300 ) ), null, null ),
			'M18 stored long name resubmitted' => array( array( 'name' => str_repeat( 'a', 300 ) ), array( 'name' => str_repeat( 'a', 300 ) ), null, 'name' ),
			'M19 update exclude 256'           => array( array( 'exclude' => array( str_repeat( 'a', 256 ) ) ), array( 'name' => 'Stored' ), null, 'exclude' ),
		);
		$cases = array();
		foreach ( $rows as $label => $row ) {
			$cases[ $label . ' ui' ]      = array( 'ui', $row[0], $row[1], $row[2] );
			$cases[ $label . ' ability' ] = array( 'ability', $row[0], $row[1], $row[3] );
		}
		return $cases;
	}

	/**
	 * Every ceiling is one rule: unbounded for the form, schema-bounded for machine callers.
	 *
	 * @dataProvider ceiling_matrix
	 * @param string      $context Caller context.
	 * @param array       $input Submitted fields.
	 * @param array       $stored Stored column overrides; empty means a create.
	 * @param string|null $refused Expected refused field, or null when accepted.
	 */
	public function test_ceiling_matrix( string $context, array $input, array $stored, ?string $refused ): void {
		$fields = $this->fields( $this->create_test_site() );
		$task   = null;
		if ( $stored ) {
			$created = MainWP_Backup_Operations::create_task( $fields, 'ability' );
			$this->assertIsArray( $created );
			MainWP_DB_Backup::instance()->update_backup_task_with_values( $created['task_id'], $stored );
			$task   = MainWP_DB_Backup::instance()->get_backup_task_by_id( $created['task_id'] );
			$fields = array();
		}
		$result = MainWP_Backup_Operations::normalize_task_fields( array_merge( $fields, $input ), $task, $context );
		if ( null === $refused ) {
			$this->assertIsArray( $result );
			return;
		}
		$this->assertWPError( $result );
		$this->assertSame( 'mainwp_invalid_input', $result->get_error_code() );
		$this->assertSame( $refused, $result->get_error_data()['field'] );
	}

	/** The form saves a long multibyte name as it always did. */
	public function test_ajax_create_accepts_multibyte_name(): void {
		$name     = str_repeat( "\u{5907}", 86 );
		$posted   = $this->posted( array_merge( $this->fields( $this->create_test_site() ), array( 'name' => $name ) ) );
		$response = $this->ajax( 'mainwp_addbackup', $posted );
		$this->assertSame( array( 'result' => 'Task created successfully.' ), $response );
		$tasks = MainWP_DB_Backup::instance()->get_backup_tasks();
		$this->assertSame( $name, html_entity_decode( end( $tasks )->name, ENT_QUOTES, 'UTF-8' ) );
	}
	/** Unrelated edits preserve an upload hold after its destination is removed. */
	public function test_partial_edit_after_remote_destination_deletion(): void {
		$store                     = \MainWP\Dashboard\MainWP_Backup_Storage_Destinations::instance();
		$destination               = $store->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Partial edit fixture',
				'settings' => array(),
			)
		);
		$fields                    = $this->fields( $this->create_test_site() );
		$fields['destination_ids'] = array( $destination );
		$fields['retention']       = array( 'remove_local_after_upload' => true );
		$created                   = MainWP_Backup_Operations::create_task( $fields, 'ability' );
		$this->assertIsArray( $created );
		$id = $created['task_id'];
		$this->assertTrue( MainWP_Backup_Operations::delete_destination( $destination )['deleted'] );
		$this->assertIsArray( MainWP_Backup_Operations::update_task( $id, array( 'name' => 'Renamed after destination deletion' ), 'ability' ) );
		$this->assertSame( 'Renamed after destination deletion', MainWP_DB_Backup::instance()->get_backup_task_by_id( $id )->name );
		$this->assertTrue( \MainWP\Dashboard\MainWP_Backup_Retention_Protection::task_removes_local_archives( $id ) );
		foreach ( array( array( 'destination_ids' => array() ), array( 'retention' => array( 'remove_local_after_upload' => true ) ) ) as $edit ) {
			$this->assertWPError( MainWP_Backup_Operations::update_task( $id, $edit, 'ability' ) );
		}
		$this->assertIsArray( MainWP_Backup_Operations::update_task( $id, array( 'retention' => array( 'remove_local_after_upload' => false ) ), 'ability' ) );
		$this->assertFalse( \MainWP\Dashboard\MainWP_Backup_Retention_Protection::task_removes_local_archives( $id ) );
	}
}
