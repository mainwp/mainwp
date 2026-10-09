<?php
/**
 * MainWP Backup Schedule Cron Integration Tests
 *
 * Covers the next_run lane: the due-task query the hourly cron merges
 * with the legacy threshold queries, the post-run next_run advancement,
 * the schedule fields save path, and the timezone-change recompute.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

require_once __DIR__ . '/trait-backup-private-root-fixture.php';

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Manage_Backups_Handler;
use MainWP\Dashboard\MainWP_Backup_Snapshots;
use MainWP\Dashboard\MainWP_Backup_Private_Root;
use MainWP\Dashboard\MainWP_System;
use MainWP\Dashboard\MainWP_System_Cron_Jobs;

/**
 * Class MainWP_Backup_Schedule_Cron_Test
 */
class MainWP_Backup_Schedule_Cron_Test extends MainWP_Abilities_Test_Case {

	use Backup_Private_Root_Fixture;

	/**
	 * The minutely slice event follows the native-backup toggle.
	 */
	public function test_slice_event_registration_and_unscheduling() {
		$cron = MainWP_System_Cron_Jobs::instance();
		$cron->init_cron_jobs();
		$this->assertNotFalse( has_action( 'mainwp_cronbackups_slice_action', array( $cron, 'cron_backups_slice' ) ) );
		update_option( 'mainwp_enableNativeBackups', 1 );
		$this->assertSame( 'minutely', $cron->get_cron_jobs()['mainwp_cronbackups_slice_action'] );
		$this->assertSame( '5minutely', $cron->get_cron_jobs()['mainwp_cronbackups_continue_action'] );
		$cron->init_mainwp_cron( true, 'mainwp_cronbackups_slice_action', 'minutely' );
		$this->assertSame( 'minutely', wp_get_schedule( 'mainwp_cronbackups_slice_action' ) );
		$this->assertNotFalse( wp_next_scheduled( 'mainwp_cronbackups_slice_action' ) );
		update_option( 'mainwp_enableNativeBackups', 0 );
		$this->assertArrayNotHasKey( 'mainwp_cronbackups_slice_action', $cron->get_cron_jobs() );
		$cron->init_cron();
		$this->assertFalse( wp_next_scheduled( 'mainwp_cronbackups_slice_action' ) );
		delete_option( 'mainwp_cron_last_backups_slice' );
		$cron->cron_backups_slice();
		$this->assertFalse( get_option( 'mainwp_cron_last_backups_slice' ), 'A disabled tick never enters the slice.' );
	}

	/**
	 * System cron needs a daily retention entry point when WP-Cron is off.
	 *
	 * @return void
	 */
	public function test_system_prune_action_runs_retention_sweep() {
		global $wpdb;

		$this->set_up_private_root();
		$site_id      = $this->create_test_site();
		$task_id      = $this->insert_task( array( 'retention_days' => 1 ) );
		$table        = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$now          = time();
		$snapshot_ids = array();
		$paths        = array();
		try {
			$dir = MainWP_Backup_Private_Root::site_dir( $site_id );
			$this->assertIsString( $dir );
			foreach ( array( $now - 2 * DAY_IN_SECONDS, $now ) as $index => $created ) {
				$path = $dir . 'cron-retention-' . $index . '.tar';
				file_put_contents( $path, str_repeat( 'x', 100 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- test fixture.
				$this->assertSame(
					1,
					$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
						$table,
						array(
							'task_id'       => $task_id,
							'site_id'       => $site_id,
							'type'          => 'full',
							'parent_id'     => null,
							'created'       => $created,
							'archives'      => wp_json_encode( array( array( 'file' => basename( $path ), 'size' => 100, 'role' => 'full', 'path' => $path ) ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing -- compact fixture.
							'manifest_path' => '',
							'status'        => 'complete',
						)
					)
				);
				$snapshot_ids[] = (int) $wpdb->insert_id;
				$paths[]        = $path;
			}

			delete_option( 'mainwp_cron_last_backups_prune' );
			update_option( 'mainwp_enableNativeBackups', 0 );
			MainWP_System::instance()->mainwp_cronbackups_prune_action();
			$this->assertFalse( get_option( 'mainwp_cron_last_backups_prune' ), 'Disabled native backups must not enter retention.' );
			foreach ( $snapshot_ids as $snapshot_id ) {
				$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_id )->status );
			}

			update_option( 'mainwp_enableNativeBackups', 1 );
			$before = time();
			MainWP_System::instance()->mainwp_cronbackups_prune_action();

			$this->assertGreaterThanOrEqual( $before, (int) get_option( 'mainwp_cron_last_backups_prune' ) );
			$this->assertLessThanOrEqual( time(), (int) get_option( 'mainwp_cron_last_backups_prune' ) );
			$this->assertSame( 'pruned', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_ids[0] )->status );
			$this->assertFileDoesNotExist( $paths[0] );
			$this->assertSame( 'complete', MainWP_Backup_Snapshots::instance()->get_snapshot_by_id( $snapshot_ids[1] )->status, 'The newest complete unit must survive retention.' );
			$this->assertFileExists( $paths[1] );
		} finally {
			foreach ( $snapshot_ids as $snapshot_id ) {
				$wpdb->delete( $table, array( 'id' => $snapshot_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test cleanup.
			}
			delete_option( 'mainwp_cron_last_backups_prune' );
			delete_option( 'mainwp_enableNativeBackups' );
			$this->tear_down_private_root();
		}
	}

	/**
	 * An interleaved schedule save must survive either cron recomputation path.
	 */
	public function test_next_run_recomputation_preserves_concurrent_schedule_save() {
		$db = MainWP_DB_Backup::instance();
		foreach ( array( 'advance', 'recompute' ) as $mode ) {
			$id          = $this->insert_task(
				array(
					'userid'         => get_current_user_id(),
					'next_run'       => time() - 60,
					'last_run'       => time(),
					'interval_hours' => 1,
				)
			);
			$saved_next  = time() + 5 * DAY_IN_SECONDS;
			$interleaved = false;
			$save        = static function ( $sql ) use ( &$save, &$interleaved, $db, $id, $saved_next ) {
				if ( str_starts_with( $sql, 'UPDATE' ) && str_contains( $sql, 'mainwp_wp_backup' ) && str_contains( $sql, '`next_run`' ) ) {
					remove_filter( 'query', $save );
					$interleaved = true;
					$db->update_backup_task_with_values( $id, array( 'next_run' => $saved_next ) );
				}
				return $sql;
			};
			add_filter( 'query', $save );
			try {
				if ( 'advance' === $mode ) {
					MainWP_System_Cron_Jobs::instance()->maybe_advance_backup_next_run( $id, 0 );
				} else {
					MainWP_System_Cron_Jobs::instance()->recompute_backup_next_runs();
				}
			} finally {
				remove_filter( 'query', $save );
			}
			$this->assertTrue( $interleaved, $mode );
			$this->assertSame( $saved_next, (int) $db->get_backup_task_by_id( $id )->next_run, $mode );
			$db->update_backup_task_with_values( $id, array( 'interval_hours' => 0 ) );
		}
	}

	/**
	 * Clean fixture rows and request state.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wpdb;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$wpdb->query( "DELETE FROM {$table} WHERE name LIKE 'Schedule cron test%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- test cleanup, identifiers cannot be prepared.

		foreach ( array( 'schedule_interval_hours', 'schedule_time_of_day', 'schedule_day_of_week', 'schedule_day_of_month', 'retention_count', 'retention_days', 'retention_storage_mb', 'delete_cloud_copies' ) as $key ) {
			unset( $_POST[ $key ] );
		}

		delete_option( 'timezone_string' );

		parent::tear_down();
	}

	/**
	 * Insert a backup task row.
	 *
	 * @param array $fields Field overrides.
	 *
	 * @return int Task id.
	 */
	private function insert_task( array $fields = array() ) {
		global $wpdb;

		$table = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture.
			$table,
			array_merge(
				array(
					'userid'            => 1,
					'name'              => 'Schedule cron test ' . uniqid(),
					'schedule'          => 'daily',
					'type'              => 'full',
					'exclude'           => '',
					'sites'             => '',
					'groups'            => '',
					'last'              => 0,
					'last_run'          => 0,
					'last_run_manually' => 0,
					'completed_sites'   => '',
					'completed'         => 0,
					'backup_errors'     => '',
					'subfolder'         => '',
					'filename'          => '',
					'paused'            => 0,
					'archiveFormat'     => 'global',
				),
				$fields
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * The due query returns tasks whose next_run has arrived.
	 *
	 * @return void
	 */
	public function test_due_query_returns_due_tasks() {
		$id = $this->insert_task( array( 'next_run' => time() - 60 ) );

		$due = MainWP_DB_Backup::instance()->get_backup_tasks_due_by_next_run();

		$this->assertContains( $id, array_map( 'intval', wp_list_pluck( $due, 'id' ) ) );
	}

	/**
	 * The due query excludes tasks scheduled in the future.
	 *
	 * @return void
	 */
	public function test_due_query_excludes_future_tasks() {
		$id = $this->insert_task( array( 'next_run' => time() + HOUR_IN_SECONDS ) );

		$due = MainWP_DB_Backup::instance()->get_backup_tasks_due_by_next_run();

		$this->assertNotContains( $id, array_map( 'intval', wp_list_pluck( $due, 'id' ) ) );
	}

	/**
	 * The due query excludes legacy elapsed-mode tasks; those stay on the
	 * threshold queries.
	 *
	 * @return void
	 */
	public function test_due_query_excludes_legacy_tasks() {
		$id = $this->insert_task( array( 'next_run' => 0 ) );

		$due = MainWP_DB_Backup::instance()->get_backup_tasks_due_by_next_run();

		$this->assertNotContains( $id, array_map( 'intval', wp_list_pluck( $due, 'id' ) ) );
	}

	/**
	 * The due query excludes paused tasks.
	 *
	 * @return void
	 */
	public function test_due_query_excludes_paused_tasks() {
		$id = $this->insert_task(
			array(
				'next_run' => time() - 60,
				'paused'   => 1,
			)
		);

		$due = MainWP_DB_Backup::instance()->get_backup_tasks_due_by_next_run();

		$this->assertNotContains( $id, array_map( 'intval', wp_list_pluck( $due, 'id' ) ) );
	}

	/**
	 * A pause during an earlier task must take effect before the next dispatch.
	 *
	 * @return void
	 */
	public function test_cron_skips_task_paused_during_an_earlier_task() {
		$owner_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$next_run = time() - 60;
		$fields   = array(
			'userid'         => $owner_id,
			'schedule'       => 'interval',
			'interval_hours' => 1,
			'next_run'       => $next_run,
		);
		$task_ids = array( $this->insert_task( $fields ), $this->insert_task( $fields ) );
		$due_ids  = array_map( 'intval', wp_list_pluck( MainWP_DB_Backup::instance()->get_backup_tasks_due_by_next_run(), 'id' ) );
		foreach ( $task_ids as $task_id ) {
			$this->assertContains( $task_id, $due_ids, 'Both tasks must be due before cron starts.' );
		}

		$executed_id = 0;
		$paused_id   = 0;
		$pause_next  = static function ( $task_id ) use ( $task_ids, &$executed_id, &$paused_id ) {
			if ( 0 !== $executed_id || ! in_array( $task_id, $task_ids, true ) ) {
				return;
			}
			$executed_id = $task_id;
			$paused_id   = $task_id === $task_ids[0] ? $task_ids[1] : $task_ids[0];
			MainWP_DB_Backup::instance()->update_backup_task_with_values( $paused_id, array( 'paused' => 1 ) );
		};

		add_action( 'mainwp_backup_diag_run_close', $pause_next );
		try {
			$this->run_cron_with_native_backups_enabled();
		} finally {
			remove_action( 'mainwp_backup_diag_run_close', $pause_next );
		}

		$this->assertContains( $executed_id, $task_ids, 'The first task must reach the run-close hook inside cron.' );
		$this->assertGreaterThan( 0, (int) MainWP_DB_Backup::instance()->get_backup_task_by_id( $executed_id )->last_run );
		$paused_task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $paused_id );
		$this->assertSame( 1, (int) $paused_task->paused, 'The hook must pause the other task during execution.' );
		$this->assertSame( 0, (int) $paused_task->last_run, 'A task paused mid-loop must not start.' );
		$this->assertSame( $next_run, (int) $paused_task->next_run, 'A skipped task must retain its next_run.' );
	}

	/**
	 * A stale unpaused object must not bypass a pause stored before the claim.
	 *
	 * @return void
	 */
	public function test_execute_skips_task_paused_after_row_read() {
		$id   = $this->insert_task(
			array(
				'userid'         => self::factory()->user->create( array( 'role' => 'administrator' ) ),
				'schedule'       => 'interval',
				'interval_hours' => 1,
				'next_run'       => time() - 60,
			)
		);
		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $id );
		$this->assertSame( 0, (int) $task->paused );

		MainWP_Manage_Backups_Handler::execute_backup_task( $task );

		$last_run = (int) MainWP_DB_Backup::instance()->get_backup_task_by_id( $id )->last_run;
		$this->assertGreaterThan( (int) $task->last_run, $last_run, 'The unpaused task must claim a run before testing the pause.' );

		MainWP_DB_Backup::instance()->update_backup_task_with_values( $id, array( 'paused' => 1 ) );
		$this->assertSame( 1, (int) MainWP_DB_Backup::instance()->get_backup_task_by_id( $id )->paused );

		MainWP_Manage_Backups_Handler::execute_backup_task( $task );

		$this->assertSame( $last_run, (int) MainWP_DB_Backup::instance()->get_backup_task_by_id( $id )->last_run, 'A pause stored after the read must prevent the claim.' );
	}

	/**
	 * Both due tasks must run so an early loop exit cannot mask the pause regression.
	 *
	 * @return void
	 */
	public function test_cron_runs_both_unpaused_due_tasks() {
		$fields   = array(
			'userid'         => self::factory()->user->create( array( 'role' => 'administrator' ) ),
			'schedule'       => 'interval',
			'interval_hours' => 1,
			'next_run'       => time() - 60,
		);
		$task_ids = array( $this->insert_task( $fields ), $this->insert_task( $fields ) );
		$due_ids  = array_map( 'intval', wp_list_pluck( MainWP_DB_Backup::instance()->get_backup_tasks_due_by_next_run(), 'id' ) );
		foreach ( $task_ids as $task_id ) {
			$this->assertContains( $task_id, $due_ids, 'Both tasks must be due before cron starts.' );
		}

		$this->run_cron_with_native_backups_enabled();

		foreach ( $task_ids as $task_id ) {
			$this->assertGreaterThan( 0, (int) MainWP_DB_Backup::instance()->get_backup_task_by_id( $task_id )->last_run, 'Cron must start each unpaused due task.' );
		}
	}

	/**
	 * Preserve the native-backups option so cron cases cannot affect each other.
	 *
	 * @return void
	 */
	private function run_cron_with_native_backups_enabled() {
		$enabled_before = get_option( 'mainwp_enableNativeBackups' );
		update_option( 'mainwp_enableNativeBackups', 1 );
		try {
			MainWP_System_Cron_Jobs::instance()->cron_backups();
		} finally {
			if ( false === $enabled_before ) {
				delete_option( 'mainwp_enableNativeBackups' );
			} else {
				update_option( 'mainwp_enableNativeBackups', $enabled_before );
			}
		}
	}

	/**
	 * Saving an interval task computes a next_run and clamps the hours.
	 *
	 * @return void
	 */
	public function test_save_schedule_fields_interval_clamps_and_computes_next_run() {
		$id = $this->insert_task( array( 'schedule' => 'interval' ) );

		$_POST['schedule_interval_hours'] = '99999';

		$values = MainWP_Manage_Backups_Handler::save_task_schedule_fields( $id );

		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $id );
		$this->assertSame( 8760, (int) $task->interval_hours, 'Interval hours must clamp to a year.' );
		$this->assertGreaterThan( 0, (int) $task->next_run, 'An interval task gets a computed next_run.' );
		$this->assertLessThanOrEqual( time(), (int) $task->next_run, 'A never-ran interval task is due immediately.' );
		$this->assertSame( (int) $task->next_run, (int) $values['next_run'] );
	}

	/**
	 * An interval task that already ran projects next_run from last_run
	 * instead of firing immediately after an edit.
	 *
	 * @return void
	 */
	public function test_save_schedule_fields_interval_projects_from_last_run() {
		$last_run = time() - HOUR_IN_SECONDS;
		$id       = $this->insert_task(
			array(
				'schedule' => 'interval',
				'last_run' => $last_run,
			)
		);

		$_POST['schedule_interval_hours'] = '4';

		MainWP_Manage_Backups_Handler::save_task_schedule_fields( $id );

		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $id );
		$this->assertSame( $last_run + 4 * HOUR_IN_SECONDS, (int) $task->next_run );
	}

	/**
	 * Saving a daily task with a valid time of day stores it and computes
	 * the next wall-clock slot.
	 *
	 * @return void
	 */
	public function test_save_schedule_fields_daily_time_of_day() {
		$id = $this->insert_task( array( 'schedule' => 'daily' ) );

		$_POST['schedule_time_of_day'] = '3:5';

		MainWP_Manage_Backups_Handler::save_task_schedule_fields( $id );

		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $id );
		$this->assertSame( '03:05', $task->time_of_day, 'Time of day normalizes to HH:MM.' );
		$this->assertGreaterThan( time(), (int) $task->next_run, 'The next 03:05 slot is in the future.' );
		$this->assertLessThanOrEqual( time() + DAY_IN_SECONDS, (int) $task->next_run, 'The next slot is within a day.' );
	}

	/**
	 * Invalid times of day are rejected and the task stays in legacy mode.
	 *
	 * @return void
	 */
	public function test_save_schedule_fields_rejects_invalid_time_of_day() {
		foreach ( array( '25:00', 'abc', '12:75', '-1:30' ) as $invalid ) {
			$id = $this->insert_task( array( 'schedule' => 'daily' ) );

			$_POST['schedule_time_of_day'] = $invalid;

			MainWP_Manage_Backups_Handler::save_task_schedule_fields( $id );

			$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $id );
			$this->assertSame( '', $task->time_of_day, "'{$invalid}' must be rejected." );
			$this->assertSame( 0, (int) $task->next_run, "'{$invalid}' must leave the task in legacy mode." );
		}
	}

	/**
	 * Day fields clamp to their valid ranges.
	 *
	 * @return void
	 */
	public function test_save_schedule_fields_clamps_day_fields() {
		$id = $this->insert_task( array( 'schedule' => 'monthly' ) );

		$_POST['schedule_time_of_day']  = '02:00';
		$_POST['schedule_day_of_week']  = '9';
		$_POST['schedule_day_of_month'] = '31';

		MainWP_Manage_Backups_Handler::save_task_schedule_fields( $id );

		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $id );
		$this->assertSame( 6, (int) $task->day_of_week, 'Day of week clamps to 0-6.' );
		$this->assertSame( 28, (int) $task->day_of_month, 'Day of month clamps to 1-28.' );
	}

	/**
	 * Clearing the schedule fields drops the task back to legacy mode.
	 *
	 * @return void
	 */
	public function test_save_schedule_fields_cleared_returns_to_legacy_mode() {
		$id = $this->insert_task(
			array(
				'schedule'    => 'daily',
				'time_of_day' => '06:00',
				'next_run'    => time() + HOUR_IN_SECONDS,
			)
		);

		$_POST['schedule_time_of_day'] = '';

		MainWP_Manage_Backups_Handler::save_task_schedule_fields( $id );

		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $id );
		$this->assertSame( '', $task->time_of_day );
		$this->assertSame( 0, (int) $task->next_run, 'A cleared task returns to legacy elapsed mode.' );
	}

	/**
	 * The retention fields save as integers with zero meaning no limit,
	 * and the cloud copies toggle persists.
	 *
	 * @return void
	 */
	public function test_save_retention_fields_persists_policy() {
		$id = $this->insert_task();

		$_POST['retention_count']      = '5';
		$_POST['retention_days']       = '90';
		$_POST['retention_storage_mb'] = '500';
		$_POST['delete_cloud_copies']  = '1';
		$_POST['max_chain_length']     = '30';

		MainWP_Manage_Backups_Handler::save_task_retention_fields( $id );

		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $id );
		$this->assertSame( 5, (int) $task->retention_count );
		$this->assertSame( 90, (int) $task->retention_days );
		$this->assertSame( 500, (int) $task->retention_storage_mb );
		$this->assertSame( 1, (int) $task->delete_cloud_copies );
		$this->assertSame( 30, (int) $task->max_chain_length );
	}

	/**
	 * Absent or junk retention values save as no-limit, and an unchecked
	 * cloud copies toggle saves off.
	 *
	 * @return void
	 */
	public function test_save_retention_fields_defaults_and_junk() {
		$id = $this->insert_task(
			array(
				'retention_count'     => 3,
				'delete_cloud_copies' => 1,
			)
		);

		$_POST['retention_count'] = 'abc';

		MainWP_Manage_Backups_Handler::save_task_retention_fields( $id );

		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $id );
		$this->assertSame( 0, (int) $task->retention_count, 'Junk input saves as no limit.' );
		$this->assertSame( 0, (int) $task->retention_days );
		$this->assertSame( 0, (int) $task->retention_storage_mb );
		$this->assertSame( 0, (int) $task->delete_cloud_copies, 'An unchecked toggle saves off.' );
		$this->assertSame( 0, (int) $task->max_chain_length, 'An absent chain limit saves as no limit.' );
	}

	/**
	 * A new scheduled task keeps the last 7 backups unless the request says
	 * otherwise; an explicit 0 or a cleared field still keeps everything.
	 *
	 * @dataProvider data_new_task_retention_counts
	 *
	 * @param string|null $posted   Posted retention_count, or null when absent.
	 * @param int         $expected Saved retention_count.
	 *
	 * @return void
	 */
	public function test_new_task_retention_count_defaults_to_seven( $posted, $expected ) {
		$id = $this->insert_task();
		if ( null !== $posted ) {
			$_POST['retention_count'] = $posted;
		}

		MainWP_Manage_Backups_Handler::save_task_retention_fields( $id, true );

		$this->assertSame( $expected, (int) MainWP_DB_Backup::instance()->get_backup_task_by_id( $id )->retention_count );
		$this->assertSame( 7, MainWP_Manage_Backups_Handler::NEW_TASK_RETENTION_COUNT );
	}

	/**
	 * Posted retention counts for a new task.
	 *
	 * @return array
	 */
	public static function data_new_task_retention_counts() {
		return array(
			'absent'        => array( null, 7 ),
			'explicit zero' => array( '0', 0 ),
			'cleared field' => array( '', 0 ),
			'explicit 12'   => array( '12', 12 ),
		);
	}

	/**
	 * A run that started advances next_run past the stored slot.
	 *
	 * @return void
	 */
	public function test_advance_next_run_after_run_started() {
		$now          = time();
		$old_last_run = $now - 5 * HOUR_IN_SECONDS;
		$id           = $this->insert_task(
			array(
				'schedule'       => 'interval',
				'interval_hours' => 4,
				'last_run'       => $now, // The run just stamped last_run.
				'next_run'       => $now - 60,
			)
		);

		MainWP_System_Cron_Jobs::instance()->maybe_advance_backup_next_run( $id, $old_last_run );

		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $id );
		$this->assertSame( $now + 4 * HOUR_IN_SECONDS, (int) $task->next_run, 'next_run projects one interval past the fresh last_run.' );
	}

	/**
	 * A refused run (concurrency slot denied, last_run untouched) must not
	 * advance next_run; the task stays due for the next tick.
	 *
	 * @return void
	 */
	public function test_advance_next_run_skipped_when_run_never_started() {
		$last_run = time() - 5 * HOUR_IN_SECONDS;
		$next_run = time() - 60;
		$id       = $this->insert_task(
			array(
				'schedule'       => 'interval',
				'interval_hours' => 4,
				'last_run'       => $last_run,
				'next_run'       => $next_run,
			)
		);

		MainWP_System_Cron_Jobs::instance()->maybe_advance_backup_next_run( $id, $last_run );

		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $id );
		$this->assertSame( $next_run, (int) $task->next_run, 'A task whose run never started stays due.' );
	}

	/**
	 * Legacy tasks are never touched by the advancement.
	 *
	 * @return void
	 */
	public function test_advance_next_run_ignores_legacy_tasks() {
		$id = $this->insert_task(
			array(
				'schedule' => 'daily',
				'last_run' => time(),
				'next_run' => 0,
			)
		);

		MainWP_System_Cron_Jobs::instance()->maybe_advance_backup_next_run( $id, time() - DAY_IN_SECONDS );

		$task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $id );
		$this->assertSame( 0, (int) $task->next_run );
	}

	/**
	 * Changing the site timezone recomputes stored next_run values so a
	 * wall-clock slot keeps meaning the same local time.
	 *
	 * @return void
	 */
	public function test_timezone_change_recomputes_next_run() {
		update_option( 'timezone_string', 'UTC' );

		$id                            = $this->insert_task(
			array(
				'schedule'    => 'daily',
				'time_of_day' => '06:00',
			)
		);
		$_POST['schedule_time_of_day'] = '06:00';
		MainWP_Manage_Backups_Handler::save_task_schedule_fields( $id );

		$utc_next = (int) MainWP_DB_Backup::instance()->get_backup_task_by_id( $id )->next_run;

		update_option( 'timezone_string', 'Asia/Karachi' );
		MainWP_System_Cron_Jobs::instance()->recompute_backup_next_runs();

		$karachi_next = (int) MainWP_DB_Backup::instance()->get_backup_task_by_id( $id )->next_run;

		$this->assertNotSame( $utc_next, $karachi_next, 'A timezone change must move the UTC instant.' );
		$this->assertSame(
			0,
			( $karachi_next + 5 * HOUR_IN_SECONDS ) % DAY_IN_SECONDS - 6 * HOUR_IN_SECONDS,
			'06:00 in Karachi (UTC+5) is 01:00 UTC.'
		);
	}
}
