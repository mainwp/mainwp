<?php
/**
 * Backup reads must expose authorized evidence without advancing work.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Abilities_Backups;
use MainWP\Dashboard\MainWP_Backup_Operations;
use MainWP\Dashboard\MainWP_Backup_Run_History;
use MainWP\Dashboard\MainWP_Backup_Snapshot_Catalog;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;

require_once __DIR__ . '/../backup/class-mainwp-backup-read-fixtures.php';
require_once __DIR__ . '/../backup/test-backup-safe-error.php';

/** Reads must enforce the same gates in both callbacks. */
class MainWP_Backups_Read_Abilities_Test extends MainWP_Abilities_Test_Case {
	use Backup_Read_Fixtures;
	use Backup_Read_Purity_Guard;

	/**
	 * Allocated history identity prevents collisions across fixture seeds.
	 *
	 * @var int
	 */
	private $history_id;

	/**
	 * Each read starts with recorded evidence and a fresh authorization cache.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		MainWP_Backup_Operations::reset_authorized_site_ids_memo();
		$this->seed_read_fixtures();
		$this->seed_configuration_and_history();
	}

	/**
	 * Global configuration and recent history exercise the remaining read contracts.
	 *
	 * @return void
	 */
	private function seed_configuration_and_history() {
		global $wpdb;
		$this->insert_read_fixture(
			'wp_backup_storage',
			array(
				'id'       => 987676,
				'name'     => 'Read destination',
				'provider' => 's3',
				'settings' => 'unreadable',
				'created'  => time(),
				'updated'  => time(),
			)
		);
		$this->insert_read_fixture(
			'wp_backup_run_history',
			array(
				'task_id'           => 987671,
				'site_id'           => $this->site_id,
				'started'           => time() - 60,
				'finished'          => time(),
				'trigger_source'    => 'manual',
				'initiator_user_id' => get_current_user_id(),
				'outcome'           => 'failed',
				'error_code'        => 'mainwp_backup_disk_full',
				'message'           => 'Private old message',
				'snapshot_id'       => 987673,
			)
		);
		$this->history_id = (int) $wpdb->insert_id;
	}

	/**
	 * Fixture evidence and memoized permissions must not escape the test.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wpdb;
		$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup_run_history' ), array( 'id' => $this->history_id ) );
		MainWP_Backup_Operations::reset_authorized_site_ids_memo();
		$this->clear_read_fixtures();
		parent::tear_down();
	}

	/**
	 * Independent history fixtures must not collide or rewrite an earlier row.
	 */
	public function test_history_fixture_allocates_independent_rows() {
		global $wpdb;
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_run_history' );
		$first = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE site_id = %d ORDER BY id DESC LIMIT 1', $this->site_id ) );
		$this->assertNotNull( $first );
		$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup_storage' ), array( 'id' => 987676 ) );
		$previous = $wpdb->suppress_errors( true );
		try {
			$this->seed_configuration_and_history();
			$second = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE site_id = %d ORDER BY id DESC LIMIT 1', $this->site_id ) );
			$this->assertNotSame( $first->id, $second->id );
			$this->assertEquals( $first, $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE id = %d', $first->id ) ) );
		} finally {
			$wpdb->suppress_errors( $previous );
			$wpdb->delete( $table, array( 'site_id' => $this->site_id ) );
		}
	}

	/**
	 * One provider keeps the required checks identical across all reads.
	 *
	 * @return array
	 */
	public static function reads() {
		return array(
			'tasks'        => array( 'list-backup-tasks', array(), 'execute_backups' ),
			'task'         => array( 'get-backup-task', array( 'task_id' => 987671 ), 'edit_backup_tasks' ),
			'run'          => array( 'get-backup-task-run', array( 'task_id' => 987671 ), 'run_backup_tasks' ),
			'progress'     => array( 'get-site-backup-progress', array( 'site_id_or_domain' => 987670 ), 'execute_backups' ),
			'snapshots'    => array( 'list-backup-snapshots', array( 'site_id_or_domain' => 987670 ), 'execute_backups' ),
			'snapshot'     => array( 'get-backup-snapshot', array( 'snapshot_id' => 987673 ), 'execute_backups' ),
			'status'       => array( 'get-backup-status', array(), 'execute_backups' ),
			'history'      => array( 'list-backup-history', array(), 'execute_backups' ),
			'destinations' => array( 'list-backup-destinations', array(), 'edit_backup_tasks' ),
			'destination'  => array( 'get-backup-destination', array( 'destination_id' => 987676 ), 'edit_backup_tasks' ),
			'settings'     => array( 'get-backup-settings', array(), 'manage_dashboard_settings' ),
			'jobs'         => array( 'list-restore-jobs', array(), 'execute_backups' ),
			'job'          => array( 'get-restore-job', array( 'restore_job_id' => 987674 ), 'execute_backups' ),
		);
	}

	/**
	 * Missing registration must fail before a callback is reached.
	 *
	 * @param string $name Ability slug.
	 * @return \WP_Ability
	 */
	private function ability( $name ) {
		$ability = wp_get_ability( 'mainwp/' . $name . '-v1' );
		$this->assertNotNull( $ability, $name . ' must be registered.' );
		return $ability;
	}

	/**
	 * Every execution is guarded against externally visible work.
	 *
	 * @param string $name Ability slug.
	 * @param array  $input Valid input fixture.
	 * @return mixed
	 */
	private function read( $name, $input = null ) {
		$ability = $this->ability( $name );
		return $this->guarded(
			static function () use ( $ability, $input ) {
				return $ability->execute( $input );
			}
		);
	}

	/**
	 * Direct calls must retain authorization even when core wraps permission errors.
	 *
	 * @param string $name Ability slug.
	 * @param array  $input Valid input fixture.
	 * @return mixed
	 */
	private function callback_read( $name, $input ) {
		$callback = 'execute_' . str_replace( '-', '_', $name );
		return $this->guarded(
			static function () use ( $callback, $input ) {
				return MainWP_Abilities_Backups::$callback( $input );
			}
		);
	}

	/**
	 * Core documents output contracts independently of PHP array types.
	 *
	 * @param string $name Ability slug.
	 * @param mixed  $output Returned evidence.
	 * @return void
	 */
	private function assert_output( $name, $output ) {
		$this->assertIsArray( $output, is_wp_error( $output ) ? $output->get_error_message() : '' );
		$valid = rest_validate_value_from_schema( $output, $this->ability( $name )->get_output_schema() );
		$this->assertTrue( $valid, is_wp_error( $valid ) ? $valid->get_error_message() : '' );
	}

	/**
	 * Discovery must expose the category, rights and read annotations.
	 *
	 * @dataProvider reads
	 * @param string $name Ability slug.
	 * @param array  $input Valid input fixture.
	 * @param string $right Required operation right.
	 * @return void
	 */
	public function test_ability_is_registered( $name, $input, $right ) {
		$ability = $this->ability( $name );
		$this->assertSame( 'mainwp-backups', $ability->get_category() );
		$this->assertNotEmpty( $ability->get_label() );
		$this->assertStringContainsString( 'Possible errors:', $ability->get_description() );
		$this->assertArrayHasKey( 'mainwp-backups', wp_get_ability_categories() );
		$this->assertSame( $right, MainWP_Abilities_Backups::READ_RIGHTS[ $ability->get_name() ] );
		$meta = $ability->get_meta();
		$this->assertTrue( $meta['show_in_rest'] );
		$this->assertTrue( $meta['annotations']['readonly'] );
		$this->assertFalse( $meta['annotations']['destructive'] );
		$this->assertTrue( $meta['annotations']['idempotent'] );
	}

	/**
	 * Recorded evidence must satisfy the advertised output contract.
	 *
	 * @dataProvider reads
	 * @param string $name Ability slug.
	 * @param array  $input Valid input fixture.
	 * @return void
	 */
	public function test_read_returns_expected_structure( $name, $input ) {
		$output = $this->read( $name, $input );
		$this->assert_output( $name, $output );
		if ( in_array( $name, array( 'list-backup-tasks', 'list-restore-jobs', 'list-backup-snapshots', 'list-backup-history', 'list-backup-destinations' ), true ) ) {
			$this->assertSame( 1, 'get-backup-status' === $name ? $output['totals']['sites'] : $output['total'] );
			$this->assertCount( 1, $output['items'] );
			$this->assertSame( 1, $output['page'] );
			$this->assertSame( 50, $output['per_page'] );
		} elseif ( 'get-backup-status' === $name ) {
			$this->assertSame( 1, $output['totals']['sites'] );
			$this->assertSame( 'native_snapshot', $output['items'][0]['source'] );
		} elseif ( 'get-backup-destination' === $name ) {
			$this->assertSame( 987676, $output['id'] );
			$this->assertTrue( $output['credentials_invalid'] );
		} elseif ( 'get-backup-settings' === $name ) {
			$this->assertTrue( $output['native_backups_enabled'] );
		} elseif ( 'get-backup-task' === $name ) {
			$this->assertSame( 987671, $output['task']['id'] );
			$this->assertSame( array( $this->site_id ), $output['scope']['site_ids'] );
			$this->assertNull( $output['schedule_detail']['interval_hours'] );
			$this->assertNull( $output['schedule_detail']['time_of_day'] );
			$this->assertSame( 'mainwp_backup_disk_full', $output['last_run_summary']['errors'][0]['code'] );
		} elseif ( 'get-backup-task-run' === $name ) {
			$this->assertSame( 'completed_with_errors', $output['status'] );
			$this->assertSame( $this->site_id, $output['sites'][0]['site_id'] );
			$this->assertSame( 987673, $output['sites'][0]['snapshot']['id'] );
		} elseif ( 'get-site-backup-progress' === $name ) {
			$this->assertSame( 'failed', $output['on_demand']['status'] );
			$this->assertSame( $this->site_id, $output['site_id'] );
		} elseif ( 'get-backup-snapshot' === $name ) {
			$this->assertSame( array( 987673 ), $output['chain_ids'] );
			$this->assertSame( 'full.zip', $output['archives'][0]['file'] );
		} else {
			$this->assertSame( 'failed', $output['job']['outcome'] );
			$this->assertFalse( $output['files_rollback_available'] );
			$this->assertSame( 512, $output['progress']['bytes_transferred'] );
		}
	}

	/**
	 * Userless callers must fail before target evidence is read.
	 *
	 * @dataProvider reads
	 * @param string $name Ability slug.
	 * @param array  $input Valid input fixture.
	 * @return void
	 */
	public function test_read_requires_authentication( $name, $input ) {
		wp_set_current_user( 0 );
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		$this->assertSame( 'ability_invalid_permissions', $this->read( $name, $input )->get_error_code() );
		$result = $this->callback_read( $name, $input );
		$this->assertWPError( $result );
		$this->assertSame( 'mainwp_permission_denied', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	/**
	 * Site ownership does not replace the base capability.
	 *
	 * @dataProvider reads
	 * @param string $name Ability slug.
	 * @param array  $input Valid input fixture.
	 * @return void
	 */
	public function test_read_requires_manage_options( $name, $input ) {
		$this->set_current_user_as_subscriber();
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		$this->assertSame( 'ability_invalid_permissions', $this->read( $name, $input )->get_error_code() );
		$result = $this->callback_read( $name, $input );
		$this->assertWPError( $result );
		$this->assertSame( 'mainwp_permission_denied', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * Closed inputs must refuse undocumented fields.
	 *
	 * @dataProvider reads
	 * @param string $name Ability slug.
	 * @return void
	 */
	public function test_read_validates_input( $name ) {
		$result = $this->read( $name, array( 'unexpected' => true ) );
		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	/**
	 * Turning native backups off must not hide existing recovery evidence.
	 *
	 * @dataProvider reads
	 * @param string $name Ability slug.
	 * @param array  $input Valid input fixture.
	 * @return void
	 */
	public function test_read_works_with_native_disabled( $name, $input ) {
		update_option( 'mainwp_enableNativeBackups', 0 );
		$this->assert_output( $name, $this->read( $name, $input ) );
	}

	/**
	 * A granular denial must not alter ownership or site access.
	 *
	 * @param string $right Required operation right.
	 * @return callable
	 */
	private function withhold_right( $right ) {
		return static function ( $allowed, $type, $key ) use ( $right ) {
			return 'dashboard' === $type && $key === $right ? false : $allowed;
		};
	}

	/**
	 * Owning an accessible task must not bypass its operation right.
	 *
	 * @dataProvider reads
	 * @param string $name Ability slug.
	 * @param array  $input Valid input fixture.
	 * @param string $right Required operation right.
	 * @return void
	 */
	public function test_missing_right_denies_even_owned_accessible_tasks( $name, $input, $right ) {
		add_filter( 'mainwp_is_single_user', '__return_false' );
		$this->assertIsObject( MainWP_Backup_Operations::authorize_site( $this->site_id, '' ) );
		$this->assertFalse( is_wp_error( MainWP_Backup_Operations::authorize_task( 987671, '' ) ) );
		$deny = $this->withhold_right( $right );
		add_filter( 'mainwp_currentusercan', $deny, 10, 3 );
		try {
			$permission = $this->ability( $name )->check_permissions( array( 'site_id_or_domain' => $this->site_id ) );
			$result     = $this->callback_read( $name, $input );
			if ( '' === $right ) {
				$this->assertTrue( $permission );
				$this->assertSame( 1, $result['total'] );
			} else {
				foreach ( array( $permission, $result ) as $denied ) {
					$this->assertWPError( $denied );
					$this->assertSame( 'mainwp_insufficient_right', $denied->get_error_code() );
					$this->assertSame( 403, $denied->get_error_data()['status'] );
					$this->assertSame( $right, $denied->get_error_data()['right'] );
				}
			}
		} finally {
			remove_filter( 'mainwp_currentusercan', $deny, 10 );
			remove_filter( 'mainwp_is_single_user', '__return_false' );
		}
	}

	/**
	 * An empty right must not deny a permitted scoped task owner.
	 *
	 * @return void
	 */
	public function test_permitted_non_superadministrator_can_list_owned_task() {
		$user = self::factory()->user->create( array( 'role' => 'editor' ) );
		get_user_by( 'id', $user )->add_cap( 'manage_options' );
		wp_set_current_user( $user );
		global $wpdb;
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp' ), array( 'userid' => $user ), array( 'id' => $this->site_id ) );
		MainWP_DB_Backup::instance()->update_backup_task_with_values( 987671, array( 'userid' => $user ) );
		$grants = static function ( $allowed, $type, $key ) {
			if ( '' === $key ) {
				return false;
			}
			if ( 'dashboard' === $type ) {
				return in_array( $key, array( 'edit_backup_tasks', 'run_backup_tasks', 'execute_backups' ), true );
			}
			return 'site' === $type ? 987670 === (int) $key : $allowed;
		};
		add_filter( 'mainwp_is_single_user', '__return_false' );
		add_filter( 'mainwp_currentusercan', $grants, 10, 3 );
		try {
			$this->assertFalse( is_super_admin() );
			$output = $this->read( 'list-backup-tasks' );
			$this->assert_output( 'list-backup-tasks', $output );
			$this->assertSame( 1, $output['total'] );
			$this->assertSame( 987671, $output['items'][0]['id'] );
		} finally {
			remove_filter( 'mainwp_currentusercan', $grants, 10 );
			remove_filter( 'mainwp_is_single_user', '__return_false' );
		}
	}

	/**
	 * Each denial isolates one independent permission predicate.
	 *
	 * @return array
	 */
	public static function fleet_denials() {
		$cases = array();
		foreach ( array( 'list-backup-tasks', 'list-restore-jobs', 'get-backup-status', 'list-backup-history' ) as $name ) {
			foreach ( array( 'access', 'owner', 'site-right' ) as $kind ) {
				$cases[ $name . '-' . $kind ] = array( $name, $kind );
			}
		}
		return $cases;
	}

	/**
	 * Hidden sites must not consume a page or inflate totals.
	 *
	 * @dataProvider fleet_denials
	 * @param string $name Ability slug.
	 * @param string $kind Denial source.
	 * @return void
	 */
	public function test_fleet_denials_trim_rows_totals_and_pagination( $name, $kind ) {
		$other = $this->create_test_site();
		$task  = MainWP_DB_Backup::instance()->add_backup_task( get_current_user_id(), 'Other task', 'daily', 'full', '', (string) $other, '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		$this->insert_read_fixture(
			'wp_backup_restore_job',
			array(
				'site_id'     => $other,
				'snapshot_id' => 987673,
				'status'      => 'completed',
				'checkpoint'  => '{"sub_type":"full"}',
			)
		);
		$this->insert_read_fixture(
			'wp_backup_run_history',
			array(
				'task_id'        => $task->id,
				'site_id'        => $other,
				'started'        => time(),
				'trigger_source' => 'schedule',
				'outcome'        => 'running',
				'message'        => '',
			)
		);
		$before = $this->read( $name );
		$this->assertSame( 2, 'get-backup-status' === $name ? $before['totals']['sites'] : $before['total'] );
		$access = static function ( $allowed, $id ) {
			return 987670 === (int) $id ? false : $allowed;
		};
		$right  = static function ( $allowed, $type, $key ) {
			return 'site' === $type && 987670 === (int) $key ? false : $allowed;
		};
		if ( 'access' === $kind ) {
			add_filter( 'mainwp_check_site_access', $access, 10, 2 );
		} elseif ( 'site-right' === $kind ) {
			add_filter( 'mainwp_currentusercan', $right, 10, 3 );
		} else {
			add_filter( 'mainwp_is_single_user', '__return_false' );
			global $wpdb;
			$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp' ), array( 'userid' => get_current_user_id() + 1000 ), array( 'id' => $this->site_id ) );
		}
		MainWP_Backup_Operations::reset_authorized_site_ids_memo();
		try {
			$output = $this->read( $name, array( 'per_page' => 1 ) );
			$this->assert_output( $name, $output );
			$this->assertSame( 1, 'get-backup-status' === $name ? $output['totals']['sites'] : $output['total'] );
			$this->assertCount( 1, $output['items'] );
			$this->assertSame( 'list-backup-tasks' === $name ? (int) $task->id : $other, $output['items'][0][ 'list-backup-tasks' === $name ? 'id' : 'site_id' ] );
			$this->assertSame(
				array(),
				$this->read(
					$name,
					array(
						'per_page' => 1,
						'page'     => 2,
					)
				)['items']
			);
		} finally {
			remove_filter( 'mainwp_check_site_access', $access, 10 );
			remove_filter( 'mainwp_currentusercan', $right, 10 );
			remove_filter( 'mainwp_is_single_user', '__return_false' );
		}
	}

	/**
	 * Out-of-scope targets must look missing to the caller.
	 *
	 * @dataProvider reads
	 * @param string $name Ability slug.
	 * @param array  $input Valid input fixture.
	 * @return void
	 */
	public function test_restricted_scope_conceals_targets( $name, $input ) {
		$other = $this->create_test_site();
		$scope = static function () use ( $other ) {
			return array( $other );
		};
		add_filter( 'mainwp_currentuserallowedaccesssites', $scope );
		try {
			$result = $this->read( $name, $input );
			if ( in_array( $name, array( 'list-backup-destinations', 'get-backup-destination', 'get-backup-settings' ), true ) ) {
				$this->assert_output( $name, $result );
			} elseif ( 'get-backup-status' === $name ) {
				$this->assertSame( 1, $result['totals']['sites'] );
				$this->assertSame( $other, $result['items'][0]['site_id'] );
			} elseif ( empty( $input ) ) {
				$this->assertSame( 0, $result['total'] );
				$this->assertSame( array(), $result['items'] );
			} else {
				$this->assertWPError( $result );
				$this->assertSame( 404, $result->get_error_data()['status'] );
				$this->assertContains( $result->get_error_code(), array( 'mainwp_site_not_found', 'mainwp_backup_task_not_found', 'mainwp_backup_snapshot_not_found', 'mainwp_backup_restore_job_not_found' ) );
			}
		} finally {
			remove_filter( 'mainwp_currentuserallowedaccesssites', $scope );
		}
	}

	/**
	 * Target errors must survive the permission-stage status rewrite.
	 *
	 * @dataProvider reads
	 * @param string $name Ability slug.
	 * @param array  $input Valid input fixture.
	 * @return void
	 */
	public function test_target_errors_are_deferred_to_execute( $name, $input ) {
		if ( in_array( $name, array( 'get-backup-status', 'list-backup-destinations', 'get-backup-settings' ), true ) ) {
			$this->assertTrue( $this->ability( $name )->check_permissions( $input ) );
			$this->assert_output( $name, $this->read( $name, $input ) );
			return;
		}
		if ( empty( $input ) ) {
			$input = array( 'site_id_or_domain' => 999999999 );
		} else {
			$input = array_fill_keys( array_keys( $input ), 999999999 );
		}
		$this->assertTrue( $this->ability( $name )->check_permissions( $input ) );
		$result = $this->read( $name, $input );
		$this->assertWPError( $result );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	/**
	 * Embedded sensitive values must not become messages or output data.
	 *
	 * @dataProvider \MainWP\Dashboard\Tests\MainWP_Backup_Safe_Error_Test::sensitive_sources
	 * @param string $secret Embedded sensitive value.
	 * @return void
	 */
	public function test_safe_errors_and_paths_never_escape( $secret ) {
		global $wpdb;
		$text   = 'Could not finish because ' . $secret . ' was unavailable.';
		$source = '[mainwp_backup_disk_full] ' . $text;
		foreach ( array( 987671, 987672 ) as $id ) {
			MainWP_DB_Backup::instance()->update_backup_task_with_values(
				$id,
				array(
					'backup_errors'  => $source,
					'backup_notices' => $text,
				)
			);
		}
		$wpdb->update(
			MainWP_DB::instance()->get_table_name( 'wp_backup_progress' ),
			array(
				'last_error'  => $source,
				'fetchResult' => wp_json_encode( array( 'current_file' => $secret ) ),
			),
			array( 'wp_id' => $this->site_id )
		);
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ), array( 'last_error' => $source ), array( 'id' => 987675 ) );
		$wpdb->update(
			MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ),
			array(
				'status'        => 'pruned',
				'created'       => 1699999990,
				'manifest_path' => $secret,
				'encryption'    => '{"wrapped_dek":"secret-dek"}',
			),
			array( 'id' => 987673 )
		);
		$wpdb->update(
			MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ),
			array(
				'last_error' => $text,
				'checkpoint' => wp_json_encode(
					array(
						'sub_type'         => 'full',
						'last_error_code'  => 'mainwp_restore_disk_full',
						'last_error'       => $text,
						'terminal_failure' => array( 'error' => $text ),
						'plan'             => array(
							array(
								'local_path'  => $secret,
								'staged_path' => $secret,
							),
						),
					)
				),
			),
			array( 'id' => 987674 )
		);
		$wpdb->update(
			MainWP_DB::instance()->get_table_name( 'wp_backup_run_history' ),
			array(
				'message'    => $text,
				'error_code' => 'mainwp_backup_disk_full',
			),
			array( 'id' => $this->history_id )
		);
		foreach ( self::reads() as list( $name, $input ) ) {
			if ( 'list-backup-snapshots' === $name ) {
				$input['include_pruned'] = true;
			}
			$result = $this->read( $name, $input );
			$this->assert_output( $name, $result );
			$json = wp_json_encode( $result, JSON_UNESCAPED_SLASHES );
			$this->assertStringNotContainsString( substr( wp_json_encode( $secret, JSON_UNESCAPED_SLASHES ), 1, -1 ), $json, $name );
			$this->assertStringNotContainsString( __FILE__, $json, $name );
			$this->assertStringNotContainsString( ABSPATH, $json, $name );
			if ( false !== strpos( $name, 'snapshot' ) ) {
				foreach ( array( 'encryption', 'wrapped_dek', 'path', 'manifest_path' ) as $field ) {
					$this->assertStringNotContainsString( '"' . $field . '"', $json, $name );
				}
			}
		}
		$this->assertSame( 'mainwp_backup_disk_full', $this->read( 'get-backup-task-run', array( 'task_id' => 987671 ) )['sites'][0]['error']['code'] );
		$this->assertSame( 'mainwp_backup_unclassified_error', $this->read( 'get-backup-task', array( 'task_id' => 987671 ) )['last_run_summary']['notices'][0]['code'] );
	}

	/**
	 * Full admission must override the catalog list flag.
	 *
	 * @return array
	 */
	public static function restorability() {
		return array( array( 'base_missing' ), array( 'chain_pruned' ), array( 'artifact_unavailable' ), array( '' ) );
	}

	/**
	 * Every reported restore verdict must match actual admission.
	 *
	 * @dataProvider restorability
	 * @param string $reason Admission refusal suffix.
	 * @return void
	 */
	public function test_restorable_matches_full_admission( $reason ) {
		global $wpdb;
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		if ( 'base_missing' === $reason ) {
			$wpdb->update(
				$table,
				array(
					'type'      => 'incremental',
					'parent_id' => 987679,
				),
				array( 'id' => 987673 )
			);
		} elseif ( 'chain_pruned' === $reason ) {
			$this->insert_read_fixture(
				'wp_backup_snapshot',
				array(
					'id'       => 987679,
					'site_id'  => $this->site_id,
					'task_id'  => 987671,
					'type'     => 'full',
					'status'   => 'pruned',
					'archives' => '[]',
				)
			);
			$wpdb->update( $table, array( 'parent_id' => 987679 ), array( 'id' => 987673 ) );
		} elseif ( 'artifact_unavailable' === $reason ) {
			$wpdb->update( $table, array( 'archives' => '[{"file":"full.zip","role":"full","path":"/missing/full.zip"},{"file":"db.sql.gz","role":"db","path":"/missing/db.sql.gz"}]' ), array( 'id' => 987673 ) );
			$wpdb->update(
				MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ),
				array(
					'status'     => 'complete',
					'checkpoint' => '{"artifacts":[{"file":"full.zip","mode":"chunked"}]}',
				),
				array( 'id' => 987675 )
			);
		}
		$catalog = MainWP_Backup_Snapshot_Catalog::instance();
		$entries = array_values(
			array_filter(
				$catalog->list_snapshots_for_site( $this->site_id ),
				static function ( $entry ) {
					return 987673 === (int) $entry['snapshot']->id;
				}
			)
		);
		$this->assertTrue( $entries[0]['restorable'] );
		$admission = $catalog->resolve_restorable_chain( 987673, $this->site_id );
		$list      = $this->read( 'list-backup-snapshots', array( 'site_id_or_domain' => $this->site_id ) );
		$this->assert_output( 'list-backup-snapshots', $list );
		$detail = $this->read( 'get-backup-snapshot', array( 'snapshot_id' => 987673 ) );
		$this->assert_output( 'get-backup-snapshot', $detail );
		foreach ( array( $list['items'][0]['snapshot'], $detail['snapshot'] ) as $snapshot ) {
			$this->assertSame( $admission['ok'], $snapshot['restorable'] );
			$this->assertSame( '' === $reason ? null : $admission['code'], $snapshot['restore_reason']['code'] ?? null );
		}
		$queries = array();
		$count   = static function ( $sql ) use ( &$queries ) {
			if ( preg_match( '/mainwp_wp_backup_(snapshot|upload_job|storage)\b/', $sql ) && ! str_contains( $sql, 'JOIN ( SELECT site_id' ) ) {
				$queries[] = $sql;
			}
			return $sql;
		};
		add_filter( 'query', $count );
		try {
			$status = $this->read( 'get-backup-status', array( 'per_page' => 1 ) );
		} finally {
			remove_filter( 'query', $count );
		}
		$this->assert_output( 'get-backup-status', $status );
		$this->assertSame( array_intersect_key( $detail['snapshot'], array_flip( array( 'id', 'type', 'created_at', 'evidence', 'offsite', 'test_restore' ) ) ), $status['items'][0]['latest_snapshot'] );
		$this->assertSame( array( 'SELECT * FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ) . ' WHERE id = 987673' ), $queries, 'Only the returned snapshot needs a presence check; admission and upload queries are unnecessary.' );
	}

	/**
	 * Existing stored names must remain readable at their original length.
	 *
	 * @return void
	 */
	public function test_stored_names_are_not_capped_at_255() {
		$name             = str_repeat( 'n', 300 );
		$destination_name = str_repeat( 'é', 200 );
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			987671,
			array(
				'name'         => $name,
				'destinations' => '[987676]',
				'completed'    => 1699999999,
			)
		);
		global $wpdb;
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_storage' ), array( 'name' => $destination_name ), array( 'id' => 987676 ) );
		$this->assertSame( $name, $this->read( 'list-backup-tasks' )['items'][0]['name'] );
		$task = $this->read( 'get-backup-task', array( 'task_id' => 987671 ) );
		$this->assert_output( 'get-backup-task', $task );
		$this->assertSame( $name, $task['task']['name'] );
		$this->assertSame( $destination_name, $task['destinations'][0]['name'] );
		$this->assertSame( $name, $this->read( 'get-site-backup-progress', array( 'site_id_or_domain' => $this->site_id ) )['scheduled_runs'][0]['task_name'] );
		$this->assertSame( $name, $this->read( 'list-backup-history' )['items'][0]['task_name'] );
		foreach ( array( 'list-backup-destinations', 'get-backup-destination' ) as $slug ) {
			$dest = $this->read( $slug, 'get-backup-destination' === $slug ? array( 'destination_id' => 987676 ) : array() );
			$this->assert_output( $slug, $dest );
			$this->assertSame( $destination_name, 'get-backup-destination' === $slug ? $dest['name'] : $dest['items'][0]['name'] );
		}
		$this->assertSame( $destination_name, $this->read( 'get-backup-snapshot', array( 'snapshot_id' => 987673 ) )['uploads'][0]['destination_name'] );
	}

	/**
	 * Absent or unfamiliar progress must retain its actual meaning.
	 *
	 * @return void
	 */
	public function test_progress_idle_not_started_unknown_phase_and_never_run() {
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			987671,
			array(
				'last_run'  => 0,
				'completed' => 0,
			)
		);
		$never = $this->read( 'get-backup-task-run', array( 'task_id' => 987671 ) );
		$this->assertSame( 'never_run', $never['status'] );
		$this->assertNotEmpty( $never['sites'] );
		$this->assertSame( array( 'pending' ), array_values( array_unique( array_column( $never['sites'], 'status' ) ) ) );
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			987672,
			array(
				'last_run'  => 0,
				'completed' => 0,
			)
		);
		$this->assertSame( 'idle', $this->read( 'get-site-backup-progress', array( 'site_id_or_domain' => $this->site_id ) )['on_demand']['status'] );
		$this->assertSame(
			'not_started',
			$this->read(
				'get-site-backup-progress',
				array(
					'site_id_or_domain' => $this->site_id,
					'since'             => 1700000000,
				)
			)['on_demand']['status']
		);
		MainWP_DB_Backup::instance()->update_backup_task_with_values( 987672, array( 'last_run' => 1700000000 ) );
		global $wpdb;
		$wpdb->update(
			MainWP_DB::instance()->get_table_name( 'wp_backup_progress' ),
			array(
				'child_phase'  => 'future_phase',
				'current_file' => ABSPATH . 'secret.zip',
			),
			array( 'task_id' => 987672 )
		);
		$output = $this->read( 'get-site-backup-progress', array( 'site_id_or_domain' => $this->site_id ) );
		$this->assert_output( 'get-site-backup-progress', $output );
		$this->assertSame( 'unknown', $output['on_demand']['phase'] );
		$this->assertStringNotContainsString( ABSPATH, wp_json_encode( $output, JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * An unconfirmed cancellation must not imply the site recovered.
	 *
	 * @return void
	 */
	public function test_restore_outcomes_safety_and_partial_state_follow_checkpoint() {
		global $wpdb;
		$checkpoint = array(
			'sub_type'               => 'full',
			'options'                => array( 'pre_snapshot' => true ),
			'pre_snapshot_artifacts' => array( array( 'path' => __FILE__ ) ),
			'cancel_intent'          => true,
			'cancel_confirmation'    => array( 'outcome' => 'unconfirmed' ),
			'last_error_code'        => 'mainwp_restore_disk_full',
			'verification'           => array(
				'site_responds' => true,
				'woo_preserve'  => array(
					'customers_restored' => 2,
					'stock_applied'      => 3,
				),
				'woo_orphans'   => array( 'orphan_customer_orders' => 1 ),
			),
		);
		$wpdb->update(
			MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ),
			array(
				'child_task_id' => 'child-apply',
				'checkpoint'    => wp_json_encode( $checkpoint ),
			),
			array( 'id' => 987674 )
		);
		$output = $this->read( 'get-restore-job', array( 'restore_job_id' => 987674 ) );
		$this->assert_output( 'get-restore-job', $output );
		$this->assertSame( 'cancel_unconfirmed', $output['job']['outcome'] );
		$this->assertSame( 'taken', $output['job']['safety_backup'] );
		$this->assertSame( 'possible', $output['partial_state_risk'] );
		$this->assertTrue( $output['verification']['woo_preserve_applied'] );
		$this->assertEqualsCanonicalizing( array( 'child_apply_started', 'no_files_rollback', 'db_applied_after_files', 'cancel_unconfirmed' ), $output['partial_state_reasons'] );
		$list = $this->read( 'list-restore-jobs', array( 'outcome' => 'cancel_unconfirmed' ) );
		$this->assertSame( $output['job'], $list['items'][0] );
		$this->assertSame( 0, $this->read( 'list-restore-jobs', array( 'outcome' => 'completed' ) )['total'] );
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ), array( 'status' => 'reserved' ), array( 'id' => 987674 ) );
		$this->assertSame( 0, $this->read( 'list-restore-jobs' )['total'] );
		$this->assertSame( 'mainwp_backup_restore_job_not_found', $this->read( 'get-restore-job', array( 'restore_job_id' => 987674 ) )->get_error_code() );
	}

	/**
	 * Unexpected failures must not expose source text or arbitrary data.
	 *
	 * @dataProvider reads
	 * @param string $name Ability slug.
	 * @param array  $input Valid input fixture.
	 * @return void
	 */
	public function test_exception_messages_are_not_reflected( $name, $input ) {
		if ( 'get-backup-settings' === $name ) {
			update_option( 'mainwp_primaryBackup', 'test-provider', false );
			wp_cache_delete( 'mainwp_primaryBackup', 'options' );
		}
		$secret = 'C:\\private\\backups\\secret.zip';
		$throw  = static function ( $sql ) use ( $secret, $name ) {
			if ( 'get-backup-settings' === $name && false === strpos( $sql, 'mainwp_primaryBackup' ) ) {
				return $sql;
			}
			throw new \RuntimeException( 'Failed at ' . $secret );
		};
		add_filter( 'query', $throw );
		try {
			$output = $this->callback_read( $name, $input );
			$this->assertWPError( $output );
			$this->assertSame( 'mainwp_internal_error', $output->get_error_code() );
			$this->assertSame( 500, $output->get_error_data()['status'] );
			$this->assertStringNotContainsString( $secret, $output->get_error_message() );
			$this->assertSame( array( 'status' => 500 ), $output->get_error_data() );
		} finally {
			remove_filter( 'query', $throw );
		}
	}

	/**
	 * Scalar inputs must not silently become an unrestricted fleet read.
	 *
	 * @return void
	 */
	public function test_direct_callbacks_reject_scalar_inputs() {
		foreach ( array( 'list-backup-tasks', 'list-restore-jobs' ) as $name ) {
			$result = $this->callback_read( $name, true );
			$this->assertWPError( $result );
			$this->assertSame( 'mainwp_invalid_input', $result->get_error_code() );
			$this->assertSame( 400, $result->get_error_data()['status'] );
		}
	}
	/**
	 * Failed legacy marks are attempts, even when the runner marked the site done.
	 *
	 * @return void
	 */
	public function test_failed_legacy_run_moves_only_last_attempted() {
		global $wpdb;
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'status' => 'pruned' ), array( 'id' => 987673 ) );
		$this->set_site_option( $this->site_id, 'primary_lasttime_backup', 1600000000 );
		$before = $this->read( 'get-backup-status' )['items'][0];
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			987671,
			array(
				'last_run'        => time(),
				'completed'       => time() + 1,
				'completed_sites' => wp_json_encode( array( $this->site_id => true ) ),
				'backup_errors'   => '[mainwp_backup_disk_full] failed',
			)
		);
		$after = $this->read( 'get-backup-status' )['items'][0];
		$this->assertSame( $before['last_backup'], $after['last_backup'] );
		$this->assertSame( 'child_reported', $after['source'] );
		$this->assertNotSame( $before['last_attempted'], $after['last_attempted'] );
		$this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z' ), $after['last_attempted'] );
	}

	/**
	 * Existing status clients rely on the original pagination and evidence keys.
	 *
	 * @return void
	 */
	public function test_status_compatibility_filters_totals_and_native_ties() {
		$input  = array(
			'threshold_days' => 365,
			'only_stale'     => false,
			'page'           => 1,
			'per_page'       => 1,
		);
		$output = $this->read( 'get-backup-status', $input );
		$this->assert_output( 'get-backup-status', $output );
		foreach ( array( 'items', 'totals', 'page', 'per_page', 'filters', 'note' ) as $key ) {
			$this->assertArrayHasKey( $key, $output );
		}
		foreach ( array( 'site_id', 'url', 'name', 'last_backup', 'last_attempted', 'source', 'is_stale' ) as $key ) {
			$this->assertArrayHasKey( $key, $output['items'][0] );
		}
		$this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z', 1700000000 ), $output['items'][0]['last_attempted'] );
		$this->assertSame( '', $output['note'] );
		$this->assertSame( 'No sites matched the applied filters.', $this->read( 'get-backup-status', array( 'page' => 2 ) )['note'] );
		$this->assertSame( 365, $output['filters']['threshold_days'] );
		$this->assertSame( 1, $output['per_page'] );
		$this->set_site_option( $this->site_id, 'primary_lasttime_backup', 2000000000 );
		$this->assertSame( 'native_snapshot', $this->read( 'get-backup-status' )['items'][0]['source'] );
		$this->assertSame( array(), $this->read( 'get-backup-status', array( 'only_stale' => true ) )['items'] );
		$this->assertSame( 1, $this->read( 'get-backup-status', array( 'only_stale' => true ) )['totals']['sites'] );
		$this->assertSame(
			array(),
			$this->read(
				'get-backup-status',
				array(
					'page'     => 2,
					'per_page' => 1,
				)
			)['items']
		);
		$filtered = $this->read( 'get-backup-status', array( 'site_ids_or_domains' => array( 'parity.example.org', 999999 ) ) );
		$this->assertSame( array( $this->site_id ), $filtered['filters']['site_ids'] );
	}

	/**
	 * Filtering and pagination must precede any snapshot summary work.
	 *
	 * @return void
	 */
	public function test_status_does_not_query_snapshots_off_page() {
		global $wpdb;
		$other = $this->create_test_site();
		$this->insert_read_fixture(
			'wp_backup_snapshot',
			array(
				'site_id'  => $other,
				'task_id'  => 987671,
				'type'     => 'db',
				'created'  => 2000000001,
				'archives' => '[]',
			)
		);
		$queries = array();
		$count   = static function ( $sql ) use ( &$queries ) {
			if ( preg_match( '/mainwp_wp_backup_(snapshot|upload_job|storage)\b/', $sql ) && ! str_contains( $sql, 'JOIN ( SELECT site_id' ) ) {
				$queries[] = $sql;
			}
			return $sql;
		};
		add_filter( 'query', $count );
		try {
			$page  = $this->read(
				'get-backup-status',
				array(
					'page'     => 1,
					'per_page' => 1,
				)
			);
			$empty = $this->read(
				'get-backup-status',
				array(
					'page'     => 3,
					'per_page' => 1,
				)
			);
			$stale = $this->read( 'get-backup-status', array( 'only_stale' => true ) );
		} finally {
			remove_filter( 'query', $count );
		}
		$this->assertCount( 1, $page['items'] );
		$this->assertSame( array(), $empty['items'] );
		$this->assertSame( array(), $stale['items'] );
		$this->assertSame( 2, $stale['totals']['sites'] );
		$this->assertSame( array( 'SELECT * FROM ' . MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ) . ' WHERE id = 987673' ), $queries, 'Excluded snapshots must not be queried, and the returned row needs no admission.' );
	}

	/**
	 * The settings form accepts values above the ability write bounds.
	 *
	 * @return void
	 */
	public function test_settings_preserve_stored_values_above_write_bounds() {
		update_option( 'mainwp_on_demand_retention_count', 101 );
		update_option( 'mainwp_backup_max_concurrent', 12 );
		update_option( \MainWP\Dashboard\MainWP_Backup_Encryption::RECOVERY_OPTION, array( 'wrapped_dek' => '/private/key-material' ) );
		$output = $this->read( 'get-backup-settings' );
		$this->assert_output( 'get-backup-settings', $output );
		$this->assertSame( 101, $output['on_demand_retention_count'] );
		$this->assertSame( 12, $output['max_concurrent_backups'] );
		$this->assertStringNotContainsString( 'wrapped_dek', wp_json_encode( $output ) );
	}

	/**
	 * Decryption may succeed, but only explicitly public location fields can escape.
	 *
	 * @return void
	 */
	public function test_destinations_strip_all_secret_and_path_fields() {
		$store     = \MainWP\Dashboard\MainWP_Backup_Storage_Destinations::instance();
		$fields    = array_merge( \MainWP\Dashboard\MainWP_Backup_Storage_Handler::SECRET_FIELDS, array( 'access_key', 'app_key', 'username', 'host_fingerprint', 'base_path', 'path', 'folder' ) );
		$settings  = array_fill_keys( $fields, '/private/backup-credential' );
		$settings += array(
			'bucket'     => 'public-bucket',
			'region'     => 'us-east-1',
			'endpoint'   => 'https://storage.example.org',
			'path_style' => true,
		);
		$id        = $store->add_destination(
			array(
				'provider' => 's3',
				'name'     => 'Readable destination',
				'settings' => $settings,
			)
		);
		$this->assertGreaterThan( 0, $id );
		MainWP_DB_Backup::instance()->update_backup_task_with_values( 987671, array( 'destinations' => wp_json_encode( array( $id ) ) ) );
		MainWP_DB_Backup::instance()->update_backup_task_with_values( 987672, array( 'destinations' => wp_json_encode( array( $id ) ) ) );
		try {
			foreach ( array( 'list-backup-destinations', 'get-backup-destination' ) as $name ) {
				$output = $this->read( $name, 'get-backup-destination' === $name ? array( 'destination_id' => $id ) : array( 'provider' => 's3' ) );
				$this->assert_output( $name, $output );
				$json = wp_json_encode( $output, JSON_UNESCAPED_SLASHES );
				foreach ( $fields as $field ) {
					$this->assertStringNotContainsString( '"' . $field . '"', $json );
				}
				$this->assertStringNotContainsString( '/private/backup-credential', $json );
			}
			$one = $this->read( 'get-backup-destination', array( 'destination_id' => $id ) );
			$this->assertSame( 'public-bucket', $one['location']['bucket'] );
			$this->assertFalse( $one['credentials_invalid'] );
			$this->assertSame( 1, $one['task_count'] );
			$this->assertSame( 2, $this->read( 'list-backup-destinations', array( 'per_page' => 1 ) )['total'] );
			$this->assertCount(
				1,
				$this->read(
					'list-backup-destinations',
					array(
						'page'     => 2,
						'per_page' => 1,
					)
				)['items']
			);
		} finally {
			$store->delete_destination( $id );
		}
	}

	/**
	 * Stored values the writers accept, each with the read that must keep returning it.
	 *
	 * @return array
	 */
	public static function stored_values_writers_allow() {
		return array(
			'sftp port left blank'      => array( 'sftp_port', '' ),
			'sftp port out of range'    => array( 'sftp_port', '70000' ),
			'sftp port kept'            => array( 'sftp_port', '2222' ),
			'provider with no location' => array( 'empty_location', '' ),
			'long location strings'     => array( 'long_location', '' ),
			'long exclude list'         => array( 'long_exclude', '' ),
			'exclude list as saved'     => array( 'saved_exclude', '' ),
			'legacy task type'          => array( 'legacy_type', '' ),
			'upload with no provider'   => array( 'upload_provider', '' ),
			'child verification values' => array( 'child_verification', '' ),
			'long site name'            => array( 'long_site_name', '' ),
			'long primary method'       => array( 'long_primary_method', '' ),
		);
	}

	/**
	 * WordPress validates ability output, so an output bound no writer enforces fails the whole read.
	 *
	 * @dataProvider stored_values_writers_allow
	 *
	 * @param string $kind Stored value under test.
	 * @param string $value Stored port for the SFTP cases.
	 * @return void
	 */
	public function test_reads_accept_every_stored_value_the_writers_allow( $kind, $value ) {
		global $wpdb;
		$store = \MainWP\Dashboard\MainWP_Backup_Storage_Destinations::instance();
		$long  = str_repeat( 'x', 300 );
		$id    = 0;
		try {
			if ( in_array( $kind, array( 'sftp_port', 'empty_location', 'long_location' ), true ) ) {
				$destinations = array(
					'sftp_port'      => array(
						'provider' => 'sftp',
						'settings' => array(
							'host'     => 'sftp.example.org',
							'port'     => $value,
							'username' => 'backup',
							'password' => 'secret',
						),
					),
					'empty_location' => array(
						'provider' => 'dropbox',
						'settings' => array(
							'app_key'   => 'key',
							'base_path' => '/backups',
						),
					),
					'long_location'  => array(
						'provider' => 's3',
						'settings' => array(
							'bucket'   => $long,
							'region'   => $long,
							'endpoint' => 'https://' . $long . '.example.org',
						),
					),
				);
				$id           = $store->add_destination( $destinations[ $kind ] + array( 'name' => 'Stored destination' ) );
				$this->assertGreaterThan( 0, $id );
				$one  = $this->read( 'get-backup-destination', array( 'destination_id' => $id ) );
				$list = $this->read( 'list-backup-destinations', array( 'provider' => $destinations[ $kind ]['provider'] ) );
				$this->assert_output( 'get-backup-destination', $one );
				$this->assert_output( 'list-backup-destinations', $list );
				$this->assertEquals( array( $one ), array_values( wp_list_filter( $list['items'], array( 'id' => $id ) ) ) );
				if ( 'sftp_port' === $kind ) {
					$this->assertSame( '2222' === $value ? 2222 : null, $one['location']['port'] ?? null );
				} elseif ( 'empty_location' === $kind ) {
					$this->assertSame( '{}', wp_json_encode( $one['location'] ) );
				} else {
					$this->assertSame( $long, $one['location']['bucket'] );
				}
			} elseif ( 'long_exclude' === $kind ) {
				// 201 entries of 256 characters, joined the way the task writer stores them: past both old caps and inside the TEXT column.
				$this->assertNotFalse( MainWP_DB_Backup::instance()->update_backup_task_with_values( 987671, array( 'exclude' => \MainWP\Dashboard\MainWP_Backup_Settings::sanitize_exclude_list( array_fill( 0, 201, str_repeat( 'x', 256 ) ) ) ) ) );
				$task = $this->read( 'get-backup-task', array( 'task_id' => 987671 ) );
				$this->assert_output( 'get-backup-task', $task );
				$this->assertCount( 201, $task['exclude'] );
			} elseif ( 'saved_exclude' === $kind ) {
				MainWP_DB_Backup::instance()->update_backup_task_with_values( 987671, array( 'exclude' => \MainWP\Dashboard\MainWP_Backup_Settings::sanitize_exclude_list( "wp-content/cache\nwp-content/uploads" ) ) );
				$task = $this->read( 'get-backup-task', array( 'task_id' => 987671 ) );
				$this->assert_output( 'get-backup-task', $task );
				$this->assertSame( array( 'wp-content/cache', 'wp-content/uploads' ), $task['exclude'] );
			} elseif ( 'legacy_type' === $kind ) {
				MainWP_DB_Backup::instance()->update_backup_task_with_values( 987671, array( 'type' => '' ) );
				$list = $this->read( 'list-backup-tasks' );
				$task = $this->read( 'get-backup-task', array( 'task_id' => 987671 ) );
				$this->assert_output( 'list-backup-tasks', $list );
				$this->assert_output( 'get-backup-task', $task );
				$this->assertSame( 'legacy', $task['task']['type'] );
			} elseif ( 'upload_provider' === $kind ) {
				$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_upload_job' ), array( 'provider' => '' ), array( 'id' => 987675 ) );
				$one  = $this->read( 'get-backup-snapshot', array( 'snapshot_id' => 987673 ) );
				$list = $this->read( 'list-backup-snapshots', array( 'site_id_or_domain' => $this->site_id ) );
				$this->assert_output( 'get-backup-snapshot', $one );
				$this->assert_output( 'list-backup-snapshots', $list );
				$this->assertNull( $one['uploads'][0]['provider'] );
			} elseif ( 'child_verification' === $kind ) {
				$checkpoint = array(
					'verification' => array(
						'site_responds' => 'yes',
						'woo_orphans'   => array( 'orphan_customer_orders' => -3 ),
					),
				);
				$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ), array( 'checkpoint' => wp_json_encode( $checkpoint ) ), array( 'id' => 987674 ) );
				$job = $this->read( 'get-restore-job', array( 'restore_job_id' => 987674 ) );
				$this->assert_output( 'get-restore-job', $job );
				$this->assertNull( $job['verification']['site_responds'] );
				$this->assertNull( $job['verification']['woo_orphan_customer_orders'] );
			} elseif ( 'long_site_name' === $kind ) {
				$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp' ), array( 'name' => $long ), array( 'id' => $this->site_id ) );
				$history = $this->read( 'list-backup-history' );
				$this->assert_output( 'list-backup-history', $history );
				$this->assertSame( $long, $history['items'][0]['site_name'] );
			} else {
				update_option( 'mainwp_primaryBackup', str_repeat( 'p', 100 ) );
				$settings = $this->read( 'get-backup-settings' );
				$this->assert_output( 'get-backup-settings', $settings );
				$this->assertSame( str_repeat( 'p', 100 ), $settings['primary_backup_method'] );
			}
		} finally {
			if ( $id ) {
				$store->delete_destination( $id );
			}
			delete_option( 'mainwp_primaryBackup' );
		}
	}

	/**
	 * Machine callers may pass booleans and lists the way form encodings do; REST coerces them, PHP callers must get the same reading.
	 *
	 * @return void
	 */
	public function test_string_booleans_and_site_lists_read_as_rest_coerces_them() {
		foreach ( array( 'false', '0' ) as $value ) {
			$status = $this->read( 'get-backup-status', array( 'only_stale' => $value ) );
			$this->assert_output( 'get-backup-status', $status );
			$this->assertFalse( $status['filters']['only_stale'], $value );
		}
		$listed = $this->read( 'get-backup-status', array( 'site_ids_or_domains' => (string) $this->site_id ) );
		$this->assert_output( 'get-backup-status', $listed );
		$this->assertSame( array( $this->site_id ), array_column( $listed['items'], 'site_id' ) );
		global $wpdb;
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'status' => 'pruned' ), array( 'id' => 987673 ) );
		$snapshots = $this->read(
			'list-backup-snapshots',
			array(
				'site_id_or_domain' => $this->site_id,
				'include_pruned'    => 'false',
			)
		);
		$this->assert_output( 'list-backup-snapshots', $snapshots );
		$this->assertSame( 0, $snapshots['total'] );
		foreach ( array( 987671, '987671' ) as $id ) {
			$task = $this->read( 'get-backup-task', array( 'task_id' => $id ) );
			$this->assert_output( 'get-backup-task', $task );
			$this->assertSame( 987671, $task['task']['id'] );
		}
	}

	/**
	 * Rows another request deletes between two reads of the same call: the query that finds nothing, and the read it hits.
	 *
	 * @return array
	 */
	public static function rows_deleted_mid_read() {
		return array(
			'snapshot gone before the catalog read'  => array( '/(?<=backup_snapshot )WHERE site_id = /', 'get-backup-snapshot', array( 'snapshot_id' => 987673 ), 'not_found' ),
			'snapshot gone before the status detail' => array( '/(?<=backup_snapshot )WHERE id = /', 'get-backup-status', array(), 'no_snapshot' ),
			'site gone before the status row'        => array( '/WHERE id = 987670\b/', 'get-backup-status', array(), 'no_items' ),
			'destination gone before its detail'     => array( '/(?<=backup_storage )WHERE id = /', 'list-backup-destinations', array(), 'no_items' ),
		);
	}

	/**
	 * A row deleted mid-read is a missing row: never an internal error, never invalid output.
	 *
	 * @dataProvider rows_deleted_mid_read
	 *
	 * @param string $pattern WHERE clause of the second read, which then finds nothing.
	 * @param string $name Ability slug.
	 * @param array  $input Ability input.
	 * @param string $expected not_found, no_snapshot or no_items.
	 * @return void
	 */
	public function test_reads_survive_rows_deleted_mid_read( $pattern, $name, $input, $expected ) {
		$gone = static function ( $sql ) use ( $pattern ) {
			return preg_replace_callback(
				$pattern,
				static function ( $m ) {
					return 'WHERE 0 AND ' . substr( $m[0], 6 );
				},
				$sql,
				1
			);
		};
		add_filter( 'query', $gone );
		try {
			$output = $this->read( $name, $input );
		} finally {
			remove_filter( 'query', $gone );
		}
		if ( 'not_found' === $expected ) {
			$this->assertWPError( $output );
			$this->assertSame( 'mainwp_backup_snapshot_not_found', $output->get_error_code() );
			return;
		}
		$this->assert_output( $name, $output );
		if ( 'no_items' === $expected ) {
			$this->assertSame( array(), $output['items'] );
			if ( 'get-backup-status' === $name ) {
				$this->assertSame( 0, $output['totals']['sites'] );
			}
		} else {
			$this->assertNull( $output['items'][0]['latest_snapshot'] );
		}
	}

	/**
	 * Precision boundaries must be exercised before either transport can coerce them.
	 *
	 * @return array
	 */
	public static function id_inputs() {
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
	 * Cookie authentication keeps REST tests independent of machine credentials.
	 *
	 * @param string $name Ability slug.
	 * @param array  $input Caller input.
	 * @param array  $query Extra query parameters.
	 * @return \WP_REST_Response
	 */
	private function rest_read( $name, array $input, array $query = array() ) {
		global $wp_rest_server;
		$previous       = $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init' );
		wp_set_current_user( get_current_user_id() );
		$request = new \WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/mainwp/' . $name . '-v1/run' );
		$request->set_query_params( array_merge( $query, array( 'input' => $input ) ) );
		try {
			return $this->guarded(
				static function () use ( $request ) {
					return rest_do_request( $request );
				}
			);
		} finally {
			$wp_rest_server = $previous;
		}
	}

	/**
	 * Exact lookup aliases exercise large ids without advancing a shared sequence.
	 *
	 * @param int[] $lookups Requested task ids.
	 * @return callable
	 */
	private function task_lookup_alias( array &$lookups ) {
		$table = preg_quote( MainWP_DB::instance()->get_table_name( 'wp_backup' ), '/' );
		return static function ( $sql ) use ( $table, &$lookups ) {
			if ( preg_match( '/^SELECT \* FROM ' . $table . ' WHERE id= ([0-9]+)/', $sql, $match ) ) {
				$lookups[] = (int) $match[1];
				if ( in_array( $match[1], array( '5', '9007199254740991' ), true ) ) {
					return preg_replace( '/WHERE id= [0-9]+/', 'WHERE id= 987671', $sql, 1 );
				}
			}
			return $sql;
		};
	}

	/**
	 * Both execution boundaries must refuse ambiguous identities before a lookup.
	 *
	 * @dataProvider id_inputs
	 * @param mixed    $id Input value.
	 * @param int|null $expected Exact id, or refusal.
	 * @param bool     $schema_accepts Whether WordPress accepts the raw shape.
	 * @return void
	 */
	public function test_id_inputs_read_one_task_or_are_refused( $id, $expected, $schema_accepts ) {
		$lookups = array();
		$alias   = $this->task_lookup_alias( $lookups );
		add_filter( 'query', $alias );
		try {
			$output   = $this->read( 'get-backup-task', array( 'task_id' => $id ) );
			$response = $this->rest_read( 'get-backup-task', array( 'task_id' => $id ) );
		} finally {
			remove_filter( 'query', $alias );
		}
		if ( null !== $expected ) {
			$this->assert_output( 'get-backup-task', $output );
			$this->assertSame( 987671, $output['task']['id'] );
			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( $output, $response->get_data() );
			$this->assertNotEmpty( $lookups );
			$this->assertSame( array( $expected ), array_values( array_unique( $lookups ) ) );
			return;
		}
		$this->assertWPError( $output );
		$this->assertSame( 'mainwp_invalid_input', $output->get_error_code() );
		$this->assertSame( 400, $output->get_error_data()['status'] );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( $schema_accepts ? 'mainwp_invalid_input' : 'rest_invalid_param', $response->get_data()['code'] );
		$this->assertSame( array(), $lookups );
	}

	/**
	 * Restricted users must authorize the row that either accepted shape reads.
	 *
	 * @return void
	 */
	public function test_restricted_id_shapes_read_same_task_and_hide_denied_job() {
		global $wpdb;
		$user = self::factory()->user->create( array( 'role' => 'editor' ) );
		get_user_by( 'id', $user )->add_cap( 'manage_options' );
		wp_set_current_user( $user );
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp' ), array( 'userid' => $user ), array( 'id' => $this->site_id ) );
		MainWP_DB_Backup::instance()->update_backup_task_with_values( 987671, array( 'userid' => $user ) );
		$denied_site = $this->create_test_site();
		$this->insert_read_fixture(
			'wp_backup_restore_job',
			array(
				'site_id'     => $denied_site,
				'snapshot_id' => 987673,
				'status'      => 'completed',
				'checkpoint'  => '{"sub_type":"full"}',
			)
		);
		$denied_job = (int) $wpdb->insert_id;
		$grants     = function ( $allowed, $type, $key ) {
			return 'site' === $type ? $this->site_id === (int) $key : $allowed;
		};
		$lookups    = array();
		$alias      = $this->task_lookup_alias( $lookups );
		add_filter( 'query', $alias );
		add_filter( 'mainwp_is_single_user', '__return_false' );
		add_filter( 'mainwp_currentusercan', $grants, 10, 3 );
		MainWP_Backup_Operations::reset_authorized_site_ids_memo();
		try {
			$tasks = array();
			foreach ( array( 5, '5' ) as $id ) {
				$tasks[] = $this->read( 'get-backup-task', array( 'task_id' => $id ) );
				$this->assertSame( $tasks[0], $this->rest_read( 'get-backup-task', array( 'task_id' => $id ) )->get_data() );
			}
			$this->assert_output( 'get-backup-task', $tasks[0] );
			$this->assertSame( $tasks[0], $tasks[1] );
			$this->assertSame( 987671, $tasks[0]['task']['id'] );
			foreach ( array( $denied_job, (string) $denied_job ) as $id ) {
				$output   = $this->read( 'get-restore-job', array( 'restore_job_id' => $id ) );
				$response = $this->rest_read( 'get-restore-job', array( 'restore_job_id' => $id ) );
				$this->assertWPError( $output );
				$this->assertSame( 'mainwp_backup_restore_job_not_found', $output->get_error_code() );
				$this->assertSame( 404, $response->get_status() );
				$this->assertSame( $output->get_error_code(), $response->get_data()['code'] );
			}
			$float = $this->read( 'get-backup-task', array( 'task_id' => 5.0 ) );
			$this->assertWPError( $float );
			$this->assertSame( 'mainwp_invalid_input', $float->get_error_code() );
		} finally {
			remove_filter( 'query', $alias );
			remove_filter( 'mainwp_currentusercan', $grants, 10 );
			remove_filter( 'mainwp_is_single_user', '__return_false' );
		}
	}

	/**
	 * Every list shares the same overflow refusal at both execution boundaries.
	 *
	 * @return array
	 */
	public static function overflowing_pages() {
		$cases = array();
		foreach ( array( 'list-backup-tasks', 'list-backup-snapshots', 'list-restore-jobs', 'get-backup-status', 'list-backup-history', 'list-backup-destinations' ) as $name ) {
			foreach ( array( PHP_INT_MAX, '10000000000000000000', '99999999999999999999' ) as $page ) {
				$input = array( 'page' => $page );
				if ( 'list-backup-snapshots' === $name ) {
					$input['site_id_or_domain'] = 987670;
				}
				$cases[ $name . '-' . $page ] = array( $name, $input );
			}
		}
		return $cases;
	}

	/**
	 * Overflow must remain a client refusal rather than an internal read failure.
	 *
	 * @dataProvider overflowing_pages
	 * @param string $name Ability slug.
	 * @param array  $input Excessive page.
	 * @return void
	 */
	public function test_list_page_overflow_is_400_on_both_paths( $name, $input ) {
		$output   = $this->read( $name, $input );
		$response = $this->rest_read( $name, $input );
		$this->assertWPError( $output );
		$this->assertSame( 'ability_invalid_input', $output->get_error_code() );
		$this->assertSame( 400, $output->get_error_data()['status'] );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
	}

	/**
	 * Every request shape the run-route input check can see, by the name WordPress resolves.
	 *
	 * @return array
	 */
	public static function run_route_names() {
		return array(
			'V1 registered read, valid id'          => array( 'get-backup-task', array( 'task_id' => 987671 ), array(), true, 200, null ),
			'V2 registered read, schema refusal'    => array( 'get-backup-task', array( 'task_id' => '05' ), array(), true, 400, 'rest_invalid_param' ),
			'V3 registered read, exact id refusal'  => array( 'get-backup-task', array( 'task_id' => '9007199254740992' ), array(), true, 400, 'mainwp_invalid_input' ),
			'V4 unregistered backup read'           => array( 'get-backup-task', array( 'task_id' => 987671 ), array(), false, 404, 'rest_ability_not_found' ),
			'V5 name parameter as an array'         => array( 'get-backup-task', array( 'task_id' => 987671 ), array( 'name' => array( 'x' ) ), true, 400, 'rest_invalid_param' ),
			'V6 registered ability outside backups' => array( 'count-sites', array(), array(), true, 200, null ),
			'V7 JSON-body POST'                     => array(
				'cleanup-site-backups',
				array(
					'site_id_or_domain' => 987670,
					'dry_run'           => true,
				),
				array(),
				true,
				200,
				null,
				'POST',
				'json',
			),
			'V8 form-encoded POST'                  => array(
				'cleanup-site-backups',
				array(
					'site_id_or_domain' => 987670,
					'dry_run'           => true,
				),
				array(),
				true,
				400,
				'rest_invalid_param',
				'POST',
				'form',
			),
			'V9 GET with JSON body'                 => array( 'get-backup-task', array( 'task_id' => 987671 ), array(), true, 200, null, 'GET', 'json' ),
		);
	}

	/**
	 * Registered backup actions and reads validate the input their controller executes.
	 *
	 * @dataProvider run_route_names
	 * @param string      $name Ability slug in the route.
	 * @param array       $input Caller input.
	 * @param array       $query Extra query parameters.
	 * @param bool        $registered Whether the ability stays registered.
	 * @param int         $status Expected HTTP status.
	 * @param string|null $code Expected error code.
	 * @param string      $method Request method.
	 * @param string      $transport Input carrier.
	 * @return void
	 */
	public function test_run_route_input_check_follows_the_resolved_name( $name, $input, $query, $registered, $status, $code, $method = 'GET', $transport = 'query' ) {
		$registry = \WP_Abilities_Registry::get_instance();
		$property = new \ReflectionProperty( $registry, 'registered_abilities' );
		$property->setAccessible( true );
		$abilities = $property->getValue( $registry );
		if ( ! $registered ) {
			$remaining = $abilities;
			unset( $remaining[ 'mainwp/' . $name . '-v1' ] );
			$property->setValue( $registry, $remaining );
			// Controllers without input coercion look up missing abilities without a registration check.
			if ( ! method_exists( \WP_REST_Abilities_V1_Run_Controller::class, 'sanitize_input_for_ability' ) ) {
				$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::get_registered' );
			}
		}
		try {
			if ( 'query' === $transport ) {
				$response = $this->rest_read( $name, $input, $query );
			} else {
				global $wp_rest_server;
				$previous       = $wp_rest_server;
				$wp_rest_server = new \WP_REST_Server();
				do_action( 'rest_api_init' );
				$request = new \WP_REST_Request( $method, '/wp-abilities/v1/abilities/mainwp/' . $name . '-v1/run' );
				if ( 'GET' === $method ) {
					$request->set_query_params( array( 'input' => $input ) );
					$request->set_header( 'Content-Type', 'application/json' );
					$request->set_body( wp_json_encode( array( 'input' => array( 'task_id' => '05' ) ) ) );
				} elseif ( 'form' === $transport ) {
					$request->set_header( 'Content-Type', 'application/x-www-form-urlencoded' );
					$request->set_body_params( array( 'input' => $input ) );
				} else {
					$request->set_header( 'Content-Type', 'application/json' );
					$request->set_body( wp_json_encode( array( 'input' => $input ) ) );
				}
				try {
					$response = $this->guarded(
						static function () use ( $request ) {
							return rest_do_request( $request );
						}
					);
				} finally {
					$wp_rest_server = $previous;
				}
			}
		} finally {
			$property->setValue( $registry, $abilities );
		}
		$this->assertSame( $status, $response->get_status() );
		if ( isset( $query['name'] ) && ! is_string( $query['name'] ) ) {
			$this->assertArrayHasKey( 'name', $response->get_data()['data']['params'] );
			$this->assertSame( 'rest_invalid_type', $response->get_data()['data']['details']['name']['code'] );
		}
		if ( null === $code ) {
			$this->assertFalse( $response->is_error() );
			return;
		}
		$this->assertSame( $code, $response->get_data()['code'] );
	}

	/**
	 * Counts a Child reports, and what the restore read must show for each.
	 *
	 * @return array
	 */
	public static function child_counts() {
		return array(
			'int'                     => array( 3, 3 ),
			'zero'                    => array( 0, 0 ),
			'PHP_INT_MAX'             => array( PHP_INT_MAX, PHP_INT_MAX ),
			'numeric string'          => array( '3', 3 ),
			'padded string'           => array( ' 3 ', 3 ),
			'leading zero string'     => array( '03', 3 ),
			'whole string'            => array( '3.0', 3 ),
			'string past 2^53'        => array( '9007199254740993', 9007199254740993 ),
			'PHP_INT_MAX string'      => array( '9223372036854775807', PHP_INT_MAX ),
			'string past PHP_INT_MAX' => array( '9223372036854775808', null ),
			'near-whole string'       => array( '3.00000000000000001', null ),
			'exponent string'         => array( '1e3', null ),
			'whole float'             => array( 3.0, 3 ),
			'negative'                => array( -3, null ),
			'fraction'                => array( 3.7, null ),
			'overflow'                => array( 1e19, null ),
			'overflow string'         => array( '1e19', null ),
			'word'                    => array( 'abc', null ),
			'boolean'                 => array( true, null ),
			'list'                    => array( array( 3 ), null ),
			'absent'                  => array( null, null ),
		);
	}

	/**
	 * A Child-reported count outside what a count can be reads null instead of failing the whole read.
	 *
	 * @dataProvider child_counts
	 *
	 * @param mixed    $reported Stored Child value.
	 * @param int|null $expected Output value.
	 * @return void
	 */
	public function test_child_reported_counts_read_as_counts_or_null( $reported, $expected ) {
		global $wpdb;
		$orphans    = null === $reported ? array() : array( 'orphan_customer_orders' => $reported );
		$checkpoint = array( 'verification' => array( 'woo_orphans' => $orphans ) );
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_restore_job' ), array( 'checkpoint' => wp_json_encode( $checkpoint ) ), array( 'id' => 987674 ) );
		$job = $this->read( 'get-restore-job', array( 'restore_job_id' => 987674 ) );
		$this->assert_output( 'get-restore-job', $job );
		$this->assertSame( $expected, $job['verification']['woo_orphan_customer_orders'] );
	}

	/**
	 * Uncoded terminal outcomes need fixed explanations, and open rows need a caveat.
	 *
	 * @return void
	 */
	public function test_history_outcomes_filters_and_deferred_running_rows() {
		global $wpdb;
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_run_history' );
		foreach ( array( 'cancelled', 'reset', 'incomplete' ) as $outcome ) {
			$wpdb->update(
				$table,
				array(
					'outcome'    => $outcome,
					'error_code' => '',
					'message'    => '/private/raw-error',
				),
				array( 'id' => $this->history_id )
			);
			$output = $this->read(
				'list-backup-history',
				array(
					'outcome'           => $outcome,
					'trigger'           => 'manual',
					'task_id'           => 987671,
					'site_id_or_domain' => 'parity.example.org',
					'days'              => 90,
				)
			);
			$this->assert_output( 'list-backup-history', $output );
			$this->assertSame( 1, $output['total'] );
			$this->assertSame( 'mainwp_backup_run_' . $outcome, $output['items'][0]['error']['code'] );
			$this->assertStringNotContainsString( '/private/raw-error', wp_json_encode( $output ) );
		}
		foreach ( array( 'update_gate', 'restore_safety' ) as $trigger ) {
			$wpdb->update(
				$table,
				array(
					'task_id'           => 0,
					'outcome'           => 'completed',
					'trigger_source'    => $trigger,
					'initiator_user_id' => 0,
					'snapshot_id'       => null,
					'restore_job_id'    => 'restore_safety' === $trigger ? 987674 : null,
					'ref'               => 'child-history-id',
				),
				array( 'id' => $this->history_id )
			);
			$row = $this->read( 'list-backup-history' )['items'][0];
			$this->assertNull( $row['task_id'] );
			$this->assertNull( $row['task_name'] );
			$this->assertSame( 0, $row['initiator_user_id'] );
			$this->assertNull( $row['error'] );
			$this->assertFalse( $row['snapshot_recorded'] );
			$this->assertSame( 'child-history-id', $row['ref'] );
		}
		$wpdb->update(
			$table,
			array(
				'outcome'  => 'running',
				'finished' => 0,
				'started'  => time() - 40 * DAY_IN_SECONDS,
			),
			array( 'id' => $this->history_id )
		);
		$this->assertSame( 0, $this->read( 'list-backup-history' )['total'] );
		$this->assertSame( 1, $this->read( 'list-backup-history', array( 'days' => 90 ) )['total'] );
		$this->assertNull( $this->read( 'list-backup-history', array( 'days' => 90 ) )['items'][0]['finished_at'] );
		$this->assertStringContainsString( 'reconciliation', $this->ability( 'list-backup-history' )->get_meta()['annotations']['instructions'] );
		foreach ( array( array( 'days' => 'bad' ), array( 'outcome' => array( 'failed' ) ), array( 'trigger' => true ), array( 'page' => 0 ) ) as $input ) {
			$this->assertSame( 'mainwp_invalid_input', $this->callback_read( 'list-backup-history', $input )->get_error_code() );
		}
	}

	/**
	 * Override history must explain which backup check the caller bypassed.
	 *
	 * @return void
	 */
	public function test_history_lists_overridden_gate_verdicts() {
		global $wpdb;
		$input  = array( 'site_id_or_domain' => $this->site_id );
		$before = $this->read( 'list-backup-history', $input );
		$this->assert_output( 'list-backup-history', $before );
		$this->assertCount( 1, $before['items'] );
		$this->assertNull( $before['items'][0]['gate_verdict'] );
		$expected = array( $before['items'][0]['id'] => null );
		$ids      = array();
		try {
			foreach ( array( 'pending_offsite', 'missing' ) as $verdict ) {
				$id = MainWP_Backup_Run_History::record_gate_override( $this->site_id, $verdict );
				$this->assertGreaterThan( 0, $id );
				$ids[]           = $id;
				$expected[ $id ] = $verdict;
			}
			$output = $this->read( 'list-backup-history', $input );
			$this->assert_output( 'list-backup-history', $output );
			$this->assertSame( 3, $output['total'] );
			$actual = array_column( $output['items'], 'gate_verdict', 'id' );
			ksort( $expected );
			ksort( $actual );
			$this->assertSame( $expected, $actual );
		} finally {
			foreach ( $ids as $id ) {
				$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup_run_history' ), array( 'id' => $id ) );
			}
		}
	}

	/**
	 * Other stored text must not acquire the meaning of an overridden verdict.
	 *
	 * @return void
	 */
	public function test_history_gate_verdict_is_null_for_unrecognized_or_non_override_messages() {
		global $wpdb;
		$table = MainWP_DB::instance()->get_table_name( 'wp_backup_run_history' );
		$id    = MainWP_Backup_Run_History::record_gate_override( $this->site_id, 'missing' );
		$this->assertGreaterThan( 0, $id );
		try {
			foreach ( array( '', 'missing', 'backup_pending_offsite /private/raw-error' ) as $message ) {
				$wpdb->update( $table, array( 'message' => $message ), array( 'id' => $id ) );
				$output = $this->read(
					'list-backup-history',
					array(
						'site_id_or_domain' => $this->site_id,
						'outcome'           => 'gate_overridden',
					)
				);
				$this->assert_output( 'list-backup-history', $output );
				$this->assertCount( 1, $output['items'] );
				$this->assertNull( $output['items'][0]['gate_verdict'] );
				$this->assertStringNotContainsString( '/private/raw-error', wp_json_encode( $output ) );
			}
			foreach ( array( 'backup_missing', 'backup_pending_offsite' ) as $message ) {
				$wpdb->update( $table, array( 'message' => $message ), array( 'id' => $this->history_id ) );
				$output = $this->read(
					'list-backup-history',
					array(
						'site_id_or_domain' => $this->site_id,
						'outcome'           => 'failed',
					)
				);
				$this->assert_output( 'list-backup-history', $output );
				$this->assertCount( 1, $output['items'] );
				$this->assertNull( $output['items'][0]['gate_verdict'] );
			}
		} finally {
			$wpdb->delete( $table, array( 'id' => $id ) );
		}
	}

	/**
	 * A failed SQL read must not masquerade as an empty history page.
	 *
	 * @return void
	 */
	public function test_history_failed_query_is_safe_error() {
		global $wpdb;
		$broken = static function ( $sql ) {
			return false !== strpos( $sql, 'mainwp_wp_backup_run_history' ) ? 'SELECT * FROM mainwp_missing_history_table' : $sql;
		};
		$old    = $wpdb->suppress_errors( true );
		add_filter( 'query', $broken );
		try {
			$output = $this->callback_read( 'list-backup-history', array() );
			$this->assertWPError( $output );
			$this->assertSame( 'mainwp_internal_error', $output->get_error_code() );
			$this->assertSame( 500, $output->get_error_data()['status'] );
		} finally {
			remove_filter( 'query', $broken );
			$wpdb->suppress_errors( $old );
		}
	}

	/**
	 * The status verdict must follow the enforcing gate's native, global and site switches.
	 *
	 * @return void
	 */
	public function test_status_update_gate_matches_enforcing_policy() {
		global $wpdb;
		foreach ( array( array( 0, 1, 1, false ), array( 1, 0, 1, false ), array( 1, 1, 0, false ), array( 1, 1, -1, true ), array( 1, 1, 1, true ) ) as list( $native, $global, $site_flag, $applies ) ) {
			update_option( 'mainwp_enableNativeBackups', $native );
			update_option( 'mainwp_backup_before_upgrade', $global );
			update_option( 'mainwp_backup_before_upgrade_days', 7 );
			$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp' ), array( 'backup_before_upgrade' => $site_flag ), array( 'id' => $this->site_id ) );
			$site = MainWP_DB::instance()->get_website_by_id( $this->site_id );
			$gate = $this->guarded(
				static function () use ( $site ) {
					return MainWP_Backup_Operations::update_gate_for_site( $site );
				}
			);
			$this->assertSame( $applies, $gate['applies'] );
			$this->assertSame( $gate, $this->read( 'get-backup-status' )['items'][0]['update_gate'] );
			$this->assertSame( $applies ? 'pass' : 'not_applicable', $gate['verdict'] );
		}
		update_option( 'mainwp_backup_before_upgrade_require_offsite', 1 );
		$this->assertSame( 'pending_offsite', $this->read( 'get-backup-status' )['items'][0]['update_gate']['verdict'] );
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'offsite_state' => 'verified' ), array( 'id' => 987673 ) );
		$this->assertSame( 'pass', $this->read( 'get-backup-status' )['items'][0]['update_gate']['verdict'] );
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'created' => 1 ), array( 'id' => 987673 ) );
		$this->set_site_option( $this->site_id, 'primary_lasttime_backup', time() );
		$this->assertSame( 'missing', $this->read( 'get-backup-status' )['items'][0]['update_gate']['verdict'] );
	}
	/**
	 * A legacy success needs both the processed mark and an archive from that run.
	 *
	 * @return void
	 */
	public function test_legacy_success_needs_fresh_archive_and_no_error_mark() {
		global $wpdb;
		$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'status' => 'pruned' ), array( 'id' => 987673 ) );
		$started = time() - 10;
		MainWP_DB_Backup::instance()->update_backup_task_with_values(
			987671,
			array(
				'last_run'        => $started,
				'completed'       => $started + 1,
				'completed_sites' => wp_json_encode( array( $this->site_id => true ) ),
				'backup_errors'   => '',
			)
		);
		$wpdb->update(
			MainWP_DB::instance()->get_table_name( 'wp_backup_progress' ),
			array(
				'dtsFetched' => $started,
				'last_error' => '',
			),
			array( 'task_id' => 987671 )
		);
		$root    = untrailingslashit( get_temp_dir() ) . '/mainwp-status-archives-' . wp_generate_uuid4();
		$uploads = static function ( $upload ) use ( $root ) {
			$upload['basedir'] = $root;
			return $upload;
		};
		add_filter( 'mainwp_get_wp_upload_dir', $uploads );
		$file = $root . '/mainwp/0/' . $this->site_id . '/full.zip';
		try {
			$this->assertSame( 'none', $this->read( 'get-backup-status' )['items'][0]['source'] );
			wp_mkdir_p( dirname( $file ) );
			file_put_contents( $file, 'fixture archive' );
			touch( $file, $started );
			$output = $this->read( 'get-backup-status' );
			$this->assert_output( 'get-backup-status', $output );
			$this->assertSame( 'legacy_task', $output['items'][0]['source'] );
			$this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z', $started ), $output['items'][0]['last_backup'] );
			$this->assertStringNotContainsString( $root, wp_json_encode( $output, JSON_UNESCAPED_SLASHES ) );
			$wpdb->update( MainWP_DB::instance()->get_table_name( 'wp_backup_progress' ), array( 'last_error' => '[mainwp_backup_disk_full] failed' ), array( 'task_id' => 987671 ) );
			$this->assertSame( 'none', $this->read( 'get-backup-status' )['items'][0]['source'] );
			$this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z', $started ), $this->read( 'get-backup-status' )['items'][0]['last_attempted'] );
		} finally {
			remove_filter( 'mainwp_get_wp_upload_dir', $uploads );
			if ( file_exists( $file ) ) {
				unlink( $file ); }
			foreach ( array( dirname( $file ), $root . '/mainwp/0', $root . '/mainwp', $root ) as $dir ) {
				if ( is_dir( $dir ) ) {
					rmdir( $dir ); }
			}
		}
	}
}

/** Dynamic identities keep destination scope tests independent of recorded fixtures. */
class MainWP_Backups_Destination_Count_Test extends MainWP_Abilities_Test_Case {
	use Backup_Read_Purity_Guard;

	/**
	 * Task visibility includes ownership, full run scope and hidden templates.
	 *
	 * @return array Destination ids and the visible site id.
	 */
	private function seed_counts(): array {
		$this->set_current_user_as_admin();
		$site  = $this->create_test_site();
		$other = $this->create_test_site();
		$this->create_test_site_sync( $site );
		$this->create_test_site_sync( $other );
		$store = \MainWP\Dashboard\MainWP_Backup_Storage_Destinations::instance();
		$ids   = array();
		foreach ( array( 'First count destination', 'Second count destination' ) as $name ) {
			$ids[] = $store->add_destination(
				array(
					'name'     => $name,
					'provider' => 'local',
					'settings' => array(),
				)
			);
			$this->assertGreaterThan( 0, end( $ids ) );
		}
		$user  = get_current_user_id();
		$owner = self::factory()->user->create( array( 'role' => 'administrator' ) );
		global $wpdb;
		foreach ( array( array( $user, (string) $site, 0 ), array( $user, $site . ',' . $other, 0 ), array( $user, (string) $other, 0 ), array( $owner, (string) $site, 0 ), array( $user, (string) $site, 1 ) ) as $row ) {
			$this->assertNotFalse(
				$wpdb->insert(
					MainWP_DB::instance()->get_table_name( 'wp_backup' ),
					array(
						'userid'       => $row[0],
						'name'         => 'Destination count task',
						'schedule'     => 'daily',
						'type'         => 'full',
						'sites'        => $row[1],
						'groups'       => '',
						'template'     => $row[2],
						'destinations' => wp_json_encode( $ids ),
					)
				),
				$wpdb->last_error
			);
		}
		return array( $ids, $site );
	}

	/**
	 * Separate cases keep a list failure from hiding a detail-scope failure.
	 *
	 * @return array Read names, scopes and expected counts.
	 */
	public static function destination_count_cases(): array {
		$cases = array();
		foreach ( array( 'list-backup-destinations', 'get-backup-destination' ) as $name ) {
			foreach ( array(
				'all'     => 4,
				'partial' => 1,
				'empty'   => 0,
			) as $scope => $count ) {
				$cases[ $name . '-' . $scope ] = array( $name, $scope, $count );
			}
		}
		return $cases;
	}

	/**
	 * Both registered reads must retain their required count field under scope.
	 *
	 * @dataProvider destination_count_cases
	 * @param string $name Read name.
	 * @param string $kind Scope case.
	 * @param int    $expected Visible task count.
	 * @return void
	 */
	public function test_destination_counts_follow_visible_tasks( string $name, string $kind, int $expected ): void {
		list( $ids, $site ) = $this->seed_counts();
		$scope              = static function () use ( $kind, $site ) {
			return 'all' === $kind ? 'all' : ( 'partial' === $kind ? array( $site ) : array() );
		};
		add_filter( 'mainwp_is_single_user', '__return_false' );
		add_filter( 'mainwp_currentuserallowedaccesssites', $scope );
		MainWP_Backup_Operations::reset_authorized_site_ids_memo();
		try {
			$ability = wp_get_ability( 'mainwp/' . $name . '-v1' );
			$this->assertNotNull( $ability );
			$output = $this->guarded(
				static function () use ( $ability, $name, $ids ) {
					return $ability->execute( 'get-backup-destination' === $name ? array( 'destination_id' => $ids[0] ) : null );
				}
			);
			$this->assertIsArray( $output, is_wp_error( $output ) ? $output->get_error_message() : '' );
			$this->assertTrue( rest_validate_value_from_schema( $output, $ability->get_output_schema() ) );
			$items = 'get-backup-destination' === $name ? array( $output ) : $output['items'];
			$this->assertCount( 'get-backup-destination' === $name ? 1 : 2, $items );
			foreach ( $items as $item ) {
				$this->assertSame( $expected, $item['task_count'], $name . ': ' . $kind );
			}
		} finally {
			remove_filter( 'mainwp_currentuserallowedaccesssites', $scope );
			remove_filter( 'mainwp_is_single_user', '__return_false' );
			MainWP_Backup_Operations::reset_authorized_site_ids_memo();
		}
	}

	/**
	 * Each list call must resolve visibility once without retaining another caller's scope.
	 *
	 * @return void
	 */
	public function test_destination_list_resolves_visible_tasks_once_per_call(): void {
		list( $ids, $site ) = $this->seed_counts();
		$scope              = static function () use ( $site ) {
			return array( $site );
		};
		$table              = MainWP_DB::instance()->get_table_name( 'wp_backup' );
		$scans              = 0;
		$query              = static function ( $sql ) use ( $table, &$scans ) {
			if ( preg_match( '/SELECT\s+\*\s+FROM\s+' . preg_quote( $table, '/' ) . '\b/i', $sql ) ) {
				++$scans;
			}
			return $sql;
		};
		add_filter( 'mainwp_is_single_user', '__return_false' );
		add_filter( 'mainwp_currentuserallowedaccesssites', $scope );
		add_filter( 'query', $query );
		MainWP_Backup_Operations::reset_authorized_site_ids_memo();
		try {
			$list = \MainWP\Dashboard\MainWP_Backup_Read_Model::list_destinations();
			$this->assertSame( 1, $scans );
			$this->assertSame( array( 1, 1 ), array_column( $list['items'], 'task_count' ) );
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
			$list = \MainWP\Dashboard\MainWP_Backup_Read_Model::list_destinations();
			$this->assertSame( 2, $scans );
			$this->assertSame( array( 0, 0 ), array_column( $list['items'], 'task_count' ) );
		} finally {
			remove_filter( 'query', $query );
			remove_filter( 'mainwp_currentuserallowedaccesssites', $scope );
			remove_filter( 'mainwp_is_single_user', '__return_false' );
			MainWP_Backup_Operations::reset_authorized_site_ids_memo();
		}
	}
}
