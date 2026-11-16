<?php
/**
 * MainWP Abilities Permission Tests
 *
 * Tests for permission callbacks and ACL enforcement.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Abilities_Util;

/**
 * Class MainWP_Abilities_Permission_Test
 *
 * Tests for permission callbacks, ACL enforcement, and REST API key authentication.
 */
class MainWP_Abilities_Permission_Test extends MainWP_Abilities_Test_Case { //phpcs:ignore -- NOSONAR - multi methods.

	/** @var string[] Test registrations must not leak into other registry tests. */
	private $staff_gate_abilities = array();

	/** @var bool A role edit must take effect within the same execution. */
	private $staff_gate_right = true;

	/**
	 * The WordPress bootstrap defines these constants again in isolated processes.
	 *
	 * @param \PHPUnit\Framework\TestResult|null $result Current result collector.
	 * @return \PHPUnit\Framework\TestResult
	 */
	public function run( ?\PHPUnit\Framework\TestResult $result = null ): \PHPUnit\Framework\TestResult {
		$configuration                           = $GLOBALS['__PHPUNIT_CONFIGURATION_FILE'] ?? null;
		$GLOBALS['__PHPUNIT_CONFIGURATION_FILE'] = '';
		try {
			return parent::run( $result );
		} finally {
			$GLOBALS['__PHPUNIT_CONFIGURATION_FILE'] = $configuration;
		}
	}

	/** Remove fixtures because the ability registry survives database rollbacks. */
	public function tearDown(): void {
		foreach ( $this->staff_gate_abilities as $name ) {
			wp_unregister_ability( $name );
		}
		remove_filter( 'mainwp_currentusercan', array( $this, 'filter_staff_ability_right' ), 10 );
		remove_role( 'mainwp_ability_staff' );
		parent::tearDown();
	}

	/**
	 * Match the Team Control right exactly so unrelated rights cannot grant access.
	 *
	 * @param bool   $allowed Existing permission.
	 * @param string $category Right category.
	 * @param string $right Right name.
	 * @return bool
	 */
	public function filter_staff_ability_right( $allowed, $category, $right ) {
		return 'rest_api' === $category && 'use_abilities' === $right ? $this->staff_gate_right : $allowed;
	}

	/** The legacy level must not supply manage_options to the staff fixture. */
	private function prepare_staff_ability_gate(): void {
		$this->skip_if_no_abilities_api();
		$this->reset_rest_authentication();
		add_role( 'mainwp_ability_staff', 'Ability staff', array( 'read' => true, 'level_10' => true ) );
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'mainwp_ability_staff' ) ) );
		$this->assertSame( array( 'read' => true, 'level_10' => true ), get_role( 'mainwp_ability_staff' )->capabilities );
		$this->assertFalse( current_user_can( 'manage_options' ) );
		add_filter( 'mainwp_currentusercan', array( $this, 'filter_staff_ability_right' ), 10, 3 );
	}

	/**
	 * The registry applies registration filters without rerunning every plugin's init hook.
	 *
	 * @param string        $name Ability name.
	 * @param mixed         $flag Opt-in value; null omits the key.
	 * @param callable|null $permission Permission callback override.
	 * @param callable|null $execute Execute callback override.
	 * @return \WP_Ability
	 */
	private function register_staff_gate_ability( $name, $flag = true, $permission = null, $execute = null ) {
		$meta = array(
			'show_in_rest' => true,
			'annotations'  => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
		);
		if ( null !== $flag ) {
			$meta['mainwp_team_control'] = $flag;
		}
		$ability = \WP_Abilities_Registry::get_instance()->register(
			$name,
			array(
				'label'               => 'Staff permission fixture',
				'description'         => 'Checks permission during execution.',
				'category'            => 'mainwp-sites',
				'input_schema'        => array( 'type' => 'object' ),
				'permission_callback' => $permission ?? array( MainWP_Abilities_Util::class, 'check_rest_api_permission' ),
				'execute_callback'    => $execute ?? static function ( $input ) {
					$permission = MainWP_Abilities_Util::check_rest_api_permission();
					return true === $permission ? $input : $permission;
				},
				'meta'                => $meta,
			)
		);
		$this->assertInstanceOf( \WP_Ability::class, $ability );
		$this->staff_gate_abilities[] = $name;
		return $ability;
	}

	/**
	 * Use the real run route without retaining a REST server between tests.
	 *
	 * @param string $name Ability name.
	 * @param array  $input Ability input.
	 * @return \WP_REST_Response
	 */
	private function run_staff_gate_rest_request( $name, array $input ) {
		global $wp_rest_server;
		$previous_server = $wp_rest_server;
		try {
			$wp_rest_server = new \WP_REST_Server();
			do_action( 'rest_api_init' );
			$request = new \WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/' . $name . '/run' );
			$request->set_query_params( array( 'input' => $input ) );
			return rest_do_request( $request );
		} finally {
			$wp_rest_server = $previous_server;
		}
	}

	/** Other components use these constants to detect the shared gate contract. */
	public function test_staff_ability_gate_contract_constants(): void {
		$this->assertSame( 'mainwp_team_control', MainWP_Abilities_Util::TEAM_CONTROL_META_KEY );
		$this->assertSame( 1, MainWP_Abilities_Util::STAFF_ABILITY_GATE_VERSION );
	}

	/** The opt-in boundary must remain explicit when core abilities are added. */
	public function test_staff_ability_gate_core_opt_in_set(): void {
		$this->skip_if_no_abilities_api();
		$expected = array(
			'mainwp/activate-site-plugins-v1',
			'mainwp/activate-site-theme-v1',
			'mainwp/add-client-v1',
			'mainwp/add-tag-v1',
			'mainwp/cancel-restore-v1',
			'mainwp/cancel-site-backup-v1',
			'mainwp/check-site-v1',
			'mainwp/check-sites-v1',
			'mainwp/cleanup-site-backups-v1',
			'mainwp/count-client-sites-v1',
			'mainwp/count-clients-v1',
			'mainwp/count-sites-v1',
			'mainwp/create-backup-task-v1',
			'mainwp/create-knowledge-record-v1',
			'mainwp/deactivate-site-plugins-v1',
			'mainwp/delete-backup-snapshot-v1',
			'mainwp/delete-backup-task-v1',
			'mainwp/delete-client-v1',
			'mainwp/delete-knowledge-record-v1',
			'mainwp/delete-site-plugins-v1',
			'mainwp/delete-site-themes-v1',
			'mainwp/delete-site-v1',
			'mainwp/delete-tag-v1',
			'mainwp/disconnect-site-v1',
			'mainwp/disconnect-sites-v1',
			'mainwp/get-abandoned-plugins-v1',
			'mainwp/get-abandoned-themes-v1',
			'mainwp/get-activity-summary-v1',
			'mainwp/get-backup-destination-v1',
			'mainwp/get-backup-settings-v1',
			'mainwp/get-backup-snapshot-v1',
			'mainwp/get-backup-status-v1',
			'mainwp/get-backup-task-run-v1',
			'mainwp/get-backup-task-v1',
			'mainwp/get-client-costs-v1',
			'mainwp/get-client-knowledge-v1',
			'mainwp/get-client-sites-v1',
			'mainwp/get-client-v1',
			'mainwp/get-fleet-versions-v1',
			'mainwp/get-knowledge-record-v1',
			'mainwp/get-network-snapshot-v1',
			'mainwp/get-restore-job-v1',
			'mainwp/get-site-backup-progress-v1',
			'mainwp/get-site-changes-v1',
			'mainwp/get-site-client-v1',
			'mainwp/get-site-costs-v1',
			'mainwp/get-site-knowledge-v1',
			'mainwp/get-site-plugins-v1',
			'mainwp/get-site-security-v1',
			'mainwp/get-site-themes-v1',
			'mainwp/get-site-updates-v1',
			'mainwp/get-site-v1',
			'mainwp/get-sites-basic-v1',
			'mainwp/get-tag-clients-v1',
			'mainwp/get-tag-sites-v1',
			'mainwp/get-tag-v1',
			'mainwp/get-uptime-stats-v1',
			'mainwp/ignore-site-core-v1',
			'mainwp/ignore-site-plugins-v1',
			'mainwp/ignore-site-themes-v1',
			'mainwp/list-backup-destinations-v1',
			'mainwp/list-backup-history-v1',
			'mainwp/list-backup-snapshots-v1',
			'mainwp/list-backup-tasks-v1',
			'mainwp/list-clients-v1',
			'mainwp/list-ignored-updates-v1',
			'mainwp/list-knowledge-v1',
			'mainwp/list-monitor-incidents-v1',
			'mainwp/list-restore-jobs-v1',
			'mainwp/list-sites-v1',
			'mainwp/list-tags-v1',
			'mainwp/list-updates-v1',
			'mainwp/pause-backup-task-v1',
			'mainwp/query-activity-v1',
			'mainwp/query-sites-segment-v1',
			'mainwp/reconnect-site-v1',
			'mainwp/reconnect-sites-v1',
			'mainwp/reset-backup-task-run-v1',
			'mainwp/restore-site-snapshot-v1',
			'mainwp/resume-backup-task-v1',
			'mainwp/rollback-restore-v1',
			'mainwp/run-backup-task-v1',
			'mainwp/run-site-backup-v1',
			'mainwp/run-updates-v1',
			'mainwp/set-ignored-updates-v1',
			'mainwp/suspend-client-v1',
			'mainwp/suspend-site-v1',
			'mainwp/suspend-sites-v1',
			'mainwp/unignore-site-updates-v1',
			'mainwp/unsuspend-client-v1',
			'mainwp/unsuspend-site-v1',
			'mainwp/update-all-v1',
			'mainwp/update-backup-task-v1',
			'mainwp/update-client-v1',
			'mainwp/update-knowledge-record-v1',
			'mainwp/update-site-core-v1',
			'mainwp/update-site-plugins-v1',
			'mainwp/update-site-themes-v1',
			'mainwp/update-site-translations-v1',
			'mainwp/update-site-v1',
			'mainwp/update-tag-v1',
		);
		$actual = array();
		foreach ( wp_get_abilities() as $name => $ability ) {
			if ( 0 === strpos( $name, 'mainwp/' ) && true === $ability->get_meta_item( 'mainwp_team_control' ) ) {
				$actual[] = $name;
			}
		}
		sort( $actual );
		$this->assertCount( 101, $actual );
		$this->assertSame( $expected, $actual );
		foreach ( array(
			'mainwp/add-site-v1',
			'mainwp/list-upcoming-renewals-v1',
			'mainwp/list-api-backups-providers-v1',
			'mainwp/list-api-backups-site-status-v1',
			'mainwp/sync-sites-v1',
			'mainwp/get-batch-job-status-v1',
			'mainwp/update-backup-settings-v1',
			'mainwp/test-backup-destination-v1',
			'mainwp/set-default-backup-destination-v1',
			'mainwp/update-backup-destination-v1',
			'mainwp/delete-backup-destination-v1',
		) as $name ) {
			$ability = wp_get_ability( $name );
			$this->assertInstanceOf( \WP_Ability::class, $ability, $name );
			$this->assertNotSame( true, $ability->get_meta_item( 'mainwp_team_control' ), $name );
		}
	}

	/** Both callbacks must retain context and pass the input and result through. */
	public function test_staff_ability_gate_flagged_direct_execution(): void {
		$this->prepare_staff_ability_gate();
		$stages  = array();
		$ability = $this->register_staff_gate_ability(
			'mainwp/staff-gate-flagged-v1',
			true,
			static function ( $input ) use ( &$stages ) {
				$stages[] = array( 'permission', $input );
				return MainWP_Abilities_Util::check_rest_api_permission();
			},
			static function ( $input ) use ( &$stages ) {
				$stages[] = array( 'execute', $input );
				$allowed  = MainWP_Abilities_Util::check_rest_api_permission();
				return true === $allowed ? $input : $allowed;
			}
		);
		$input   = array( 'number' => 7, 'enabled' => false, 'nested' => array( 'value' => 'unchanged' ) );
		$this->assertSame( $input, $ability->execute( $input ) );
		$this->assertSame( array( array( 'permission', $input ), array( 'execute', $input ) ), $stages );
		$this->assertWPError( MainWP_Abilities_Util::check_rest_api_permission() );
	}

	/** REST checks permission in the controller and again inside execute(). */
	public function test_staff_ability_gate_flagged_rest_execution(): void {
		$this->prepare_staff_ability_gate();
		$stages = array();
		$this->register_staff_gate_ability(
			'mainwp/staff-gate-rest-v1',
			true,
			static function ( $input ) use ( &$stages ) {
				$stages[] = array( 'permission', $input );
				return MainWP_Abilities_Util::check_rest_api_permission();
			},
			static function ( $input ) use ( &$stages ) {
				$stages[] = array( 'execute', $input );
				$allowed  = MainWP_Abilities_Util::check_rest_api_permission();
				return true === $allowed ? $input : $allowed;
			}
		);
		$input    = array( 'value' => 'rest input' );
		$response = $this->run_staff_gate_rest_request( 'mainwp/staff-gate-rest-v1', $input );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( $input, $response->get_data() );
		$this->assertSame( array( array( 'permission', $input ), array( 'permission', $input ), array( 'execute', $input ) ), $stages );
		$this->assertWPError( MainWP_Abilities_Util::check_rest_api_permission() );
	}

	/** The real executor repeats the base check through check_site_access(). */
	public function test_staff_ability_gate_get_site_direct_execution(): void {
		$this->prepare_staff_ability_gate();
		$site_id = $this->create_test_site( array( 'name' => 'Staff direct site' ) );
		add_filter(
			'mainwp_currentuserallowedaccesssites',
			static function () use ( $site_id ) {
				return array( $site_id );
			}
		);
		$result = wp_get_ability( 'mainwp/get-site-v1' )->execute( array( 'site_id_or_domain' => $site_id ) );
		$this->assertNotWPError( $result );
		$this->assertSame( $site_id, $result['id'] );
		$this->assertSame( 'Staff direct site', $result['name'] );
		$this->assertWPError( MainWP_Abilities_Util::check_rest_api_permission() );
		$other_site_id = $this->create_test_site( array( 'name' => 'Staff direct other site' ) );
		$denied        = wp_get_ability( 'mainwp/get-site-v1' )->execute( array( 'site_id_or_domain' => $other_site_id ) );
		$this->assertWPError( $denied );
		$this->assertSame( 'mainwp_site_not_found', $denied->get_error_code() );
	}

	/** The REST route must reach the real site executor. */
	public function test_staff_ability_gate_get_site_rest_execution(): void {
		$this->prepare_staff_ability_gate();
		$site_id = $this->create_test_site( array( 'name' => 'Staff REST site' ) );
		add_filter(
			'mainwp_currentuserallowedaccesssites',
			static function () use ( $site_id ) {
				return array( $site_id );
			}
		);
		$response = $this->run_staff_gate_rest_request( 'mainwp/get-site-v1', array( 'site_id_or_domain' => $site_id ) );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( $site_id, $response->get_data()['id'] );
		$this->assertSame( 'Staff REST site', $response->get_data()['name'] );
		$this->assertWPError( MainWP_Abilities_Util::check_rest_api_permission() );
		$other_site_id = $this->create_test_site( array( 'name' => 'Staff REST other site' ) );
		$denied        = $this->run_staff_gate_rest_request( 'mainwp/get-site-v1', array( 'site_id_or_domain' => $other_site_id ) );
		$this->assertSame( 404, $denied->get_status(), wp_json_encode( $denied->get_data() ) );
		$this->assertSame( 'mainwp_site_not_found', $denied->get_data()['code'] );
	}

	/** Missing metadata must preserve the existing denial. */
	public function test_staff_ability_gate_unflagged_denied(): void {
		$this->prepare_staff_ability_gate();
		$ability = $this->register_staff_gate_ability( 'mainwp/staff-gate-unflagged-v1', null );
		$result  = $ability->check_permissions( array() );
		$this->assertWPError( $result );
		$this->assertSame( 'mainwp_permission_denied', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( 403, $this->run_staff_gate_rest_request( $ability->get_name(), array() )->get_status() );
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		$this->assertSame( 'ability_invalid_permissions', $ability->execute( array() )->get_error_code() );
	}

	/** Truthy metadata must not opt an ability in. */
	public function test_staff_ability_gate_requires_strict_opt_in(): void {
		$this->prepare_staff_ability_gate();
		foreach ( array( 1, 'true', false ) as $index => $flag ) {
			$ability = $this->register_staff_gate_ability( 'mainwp/staff-gate-strict-' . $index . '-v1', $flag );
			$this->assertWPError( $ability->check_permissions( array() ) );
		}
	}

	/** Absent Team Control and an explicit denial must both default to denial. */
	public function test_staff_ability_gate_without_right_denied(): void {
		$this->prepare_staff_ability_gate();
		$ability = $this->register_staff_gate_ability( 'mainwp/staff-gate-no-right-v1' );
		remove_filter( 'mainwp_currentusercan', array( $this, 'filter_staff_ability_right' ), 10 );
		$this->assertWPError( $ability->check_permissions( array() ) );
		$this->staff_gate_right = false;
		add_filter( 'mainwp_currentusercan', array( $this, 'filter_staff_ability_right' ), 10, 3 );
		$this->assertWPError( $ability->check_permissions( array() ) );
		$this->assertSame( 403, $this->run_staff_gate_rest_request( $ability->get_name(), array() )->get_status() );
	}

	/** The gate only replaces the manage_options check; per-operation rights still apply. */
	public function test_staff_ability_gate_flagged_without_operation_right_denied(): void {
		$this->prepare_staff_ability_gate();
		add_filter(
			'mainwp_currentusercan',
			static function ( $allowed, $category, $right ) {
				return 'edit_sites' === $right ? false : $allowed;
			},
			20,
			3
		);
		$ability = wp_get_ability( 'mainwp/check-site-v1' );
		$this->assertInstanceOf( \WP_Ability::class, $ability );
		$result = $ability->check_permissions( array( 'site_id_or_domain' => 1 ) );
		$this->assertWPError( $result );
		$this->assertSame( 'mainwp_insufficient_right', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/** UI callers have no ability frame even when the staff role has the right. */
	public function test_staff_ability_gate_no_context_requires_manage_options(): void {
		$this->prepare_staff_ability_gate();
		$result = MainWP_Abilities_Util::check_rest_api_permission();
		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->set_current_user_as_admin();
		$this->staff_gate_right = false;
		$this->assertTrue( MainWP_Abilities_Util::check_rest_api_permission() );
	}

	/** An opted-in context cannot replace authentication. */
	public function test_staff_ability_gate_anonymous_denied(): void {
		$this->prepare_staff_ability_gate();
		$ability = $this->register_staff_gate_ability( 'mainwp/staff-gate-anonymous-v1' );
		wp_set_current_user( 0 );
		$result = $ability->check_permissions( array() );
		$this->assertWPError( $result );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	/** Inner permission checks must mask the parent's opt-in in either namespace. */
	public function test_staff_ability_gate_nested_permissions_restore_parent(): void {
		$this->prepare_staff_ability_gate();
		$inners = array(
			$this->register_staff_gate_ability( 'mainwp/staff-gate-inner-v1', null ),
			$this->register_staff_gate_ability( 'other/staff-gate-inner-v1', true ),
		);
		$parent = $this->register_staff_gate_ability(
			'mainwp/staff-gate-parent-v1',
			true,
			null,
			static function () use ( $inners ) {
				$results = array();
				foreach ( $inners as $inner ) {
					$results[] = $inner->check_permissions( array() );
					$results[] = MainWP_Abilities_Util::check_rest_api_permission();
				}
				return $results;
			}
		);
		$result = $parent->execute( array() );
		$this->assertNotWPError( $result );
		foreach ( array( 0, 2 ) as $index ) {
			$this->assertWPError( $result[ $index ] );
			$this->assertSame( 403, $result[ $index ]->get_error_data()['status'] );
			$this->assertTrue( $result[ $index + 1 ] );
		}
		$this->assertWPError( MainWP_Abilities_Util::check_rest_api_permission() );
	}

	/** Inner executors also need their own frame when their permission is independent. */
	public function test_staff_ability_gate_nested_executors_restore_parent(): void {
		$this->prepare_staff_ability_gate();
		$inners = array(
			$this->register_staff_gate_ability( 'mainwp/staff-gate-inner-execute-v1', null, '__return_true' ),
			$this->register_staff_gate_ability( 'other/staff-gate-inner-execute-v1', true, '__return_true' ),
		);
		$parent = $this->register_staff_gate_ability(
			'mainwp/staff-gate-parent-execute-v1',
			true,
			null,
			static function () use ( $inners ) {
				$results = array();
				foreach ( $inners as $inner ) {
					$results[] = $inner->execute( array() );
					$results[] = MainWP_Abilities_Util::check_rest_api_permission();
				}
				return $results;
			}
		);
		$result = $parent->execute( array() );
		$this->assertNotWPError( $result );
		foreach ( array( 0, 2 ) as $index ) {
			$this->assertWPError( $result[ $index ] );
			$this->assertSame( 403, $result[ $index ]->get_error_data()['status'] );
			$this->assertTrue( $result[ $index + 1 ] );
		}
		$this->assertWPError( MainWP_Abilities_Util::check_rest_api_permission() );
	}

	/**
	 * Core 7.x converts callback exceptions to a WP_Error; 6.9 lets them escape.
	 *
	 * @param callable $call Call that may throw.
	 * @return mixed
	 */
	private function catch_ability_exception( callable $call ) {
		try {
			return $call();
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 'ability_callback_exception', $e->getMessage() );
		}
	}

	/** Core may or may not catch callback exceptions depending on version, so assert the grant observed before the throw too. */
	public function test_staff_ability_gate_throwing_permission_restores_frames(): void {
		$this->prepare_staff_ability_gate();
		$before  = null;
		$ability = $this->register_staff_gate_ability(
			'mainwp/staff-gate-throw-permission-v1',
			true,
			static function () use ( &$before ) {
				$before = MainWP_Abilities_Util::check_rest_api_permission();
				throw new \RuntimeException( 'Permission fixture exception' );
			}
		);
		$result = $this->catch_ability_exception(
			static function () use ( $ability ) {
				return $ability->check_permissions( array() );
			}
		);
		$this->assertTrue( $before );
		$this->assertWPError( $result );
		$this->assertSame( 'ability_callback_exception', $result->get_error_code() );
		$this->assertWPError( MainWP_Abilities_Util::check_rest_api_permission() );
		$parent = $this->register_staff_gate_ability(
			'mainwp/staff-gate-throw-permission-parent-v1',
			true,
			null,
			function () use ( $ability ) {
				$inner = $this->catch_ability_exception(
					static function () use ( $ability ) {
						return $ability->check_permissions( array() );
					}
				);
				return array( $inner, MainWP_Abilities_Util::check_rest_api_permission() );
			}
		);
		$result = $parent->execute( array() );
		$this->assertNotWPError( $result );
		$this->assertSame( 'ability_callback_exception', $result[0]->get_error_code() );
		$this->assertTrue( $result[1] );
		$this->assertWPError( MainWP_Abilities_Util::check_rest_api_permission() );
	}

	/** Finally must restore both an empty stack and an active parent after an execute throw. */
	public function test_staff_ability_gate_throwing_execute_restores_frames(): void {
		$this->prepare_staff_ability_gate();
		$before  = null;
		$ability = $this->register_staff_gate_ability(
			'mainwp/staff-gate-throw-execute-v1',
			true,
			'__return_true',
			static function () use ( &$before ) {
				$before = MainWP_Abilities_Util::check_rest_api_permission();
				throw new \RuntimeException( 'Execute fixture exception' );
			}
		);
		$result = $this->catch_ability_exception(
			static function () use ( $ability ) {
				return $ability->execute( array() );
			}
		);
		$this->assertTrue( $before );
		$this->assertWPError( $result );
		$this->assertSame( 'ability_callback_exception', $result->get_error_code() );
		$this->assertWPError( MainWP_Abilities_Util::check_rest_api_permission() );
		$parent = $this->register_staff_gate_ability(
			'mainwp/staff-gate-throw-execute-parent-v1',
			true,
			null,
			function () use ( $ability ) {
				$inner = $this->catch_ability_exception(
					static function () use ( $ability ) {
						return $ability->execute( array() );
					}
				);
				return array( $inner, MainWP_Abilities_Util::check_rest_api_permission() );
			}
		);
		$result = $parent->execute( array() );
		$this->assertNotWPError( $result );
		$this->assertSame( 'ability_callback_exception', $result[0]->get_error_code() );
		$this->assertTrue( $result[1] );
		$this->assertWPError( MainWP_Abilities_Util::check_rest_api_permission() );
	}

	/** A right revoked during execution must take effect at the next check. */
	public function test_staff_ability_gate_rechecks_current_right(): void {
		$this->prepare_staff_ability_gate();
		$ability = $this->register_staff_gate_ability(
			'mainwp/staff-gate-revoke-v1',
			true,
			null,
			function () {
				$before                 = MainWP_Abilities_Util::check_rest_api_permission();
				$this->staff_gate_right = false;
				return array( $before, MainWP_Abilities_Util::check_rest_api_permission() );
			}
		);
		$result = $ability->execute( array() );
		$this->assertNotWPError( $result );
		$this->assertTrue( $result[0] );
		$this->assertWPError( $result[1] );
		$this->assertSame( 403, $result[1]->get_error_data()['status'] );
	}

	/**
	 * Constants cannot be undone, so each machine context needs its own process.
	 *
	 * @return array
	 */
	public function staff_gate_machine_contexts(): array {
		return array( 'cron' => array( 'DOING_CRON' ), 'cli' => array( 'WP_CLI' ) );
	}

	/**
	 * Machine contexts keep the existing login and capability requirements.
	 *
	 * @dataProvider staff_gate_machine_contexts
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @param string $constant Machine context constant.
	 */
	public function test_staff_ability_gate_machine_context_unchanged_denials( $constant ): void {
		define( $constant, true );
		$this->prepare_staff_ability_gate();
		$this->assertWPError( MainWP_Abilities_Util::check_rest_api_permission() );
		$unflagged = $this->register_staff_gate_ability( 'mainwp/staff-gate-machine-unflagged-v1', null );
		$this->assertWPError( $unflagged->check_permissions( array() ) );
		$flagged = $this->register_staff_gate_ability( 'mainwp/staff-gate-machine-flagged-v1' );
		$this->staff_gate_right = false;
		$this->assertWPError( $flagged->check_permissions( array() ) );
		wp_set_current_user( 0 );
		$this->assertSame( 401, MainWP_Abilities_Util::check_rest_api_permission()->get_error_data()['status'] );
		$this->assertSame( 401, $flagged->check_permissions( array() )->get_error_data()['status'] );
		$this->set_current_user_as_admin();
		$this->assertTrue( MainWP_Abilities_Util::check_rest_api_permission() );
		$this->assertSame( array( 'value' => 'admin' ), $unflagged->execute( array( 'value' => 'admin' ) ) );
	}

	/**
	 * Cron and CLI must consult the filter rather than the permissive helper.
	 *
	 * @dataProvider staff_gate_machine_contexts
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @param string $constant Machine context constant.
	 */
	public function test_staff_ability_gate_machine_context_requires_fresh_right( $constant ): void {
		define( $constant, true );
		$this->test_staff_ability_gate_rechecks_current_right();
	}

	/**
	 * Test that permission is denied for subscriber (no manage_options).
	 *
	 * Note: The Abilities API wraps permission_callback errors with the code
	 * 'ability_invalid_permissions'. The original error is preserved in the message.
	 *
	 * @return void
	 */
	public function test_permission_denied_for_subscriber() {
		$this->skip_if_no_abilities_api();

		// Abilities API triggers _doing_it_wrong() when permission_callback returns WP_Error.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		// Create subscriber user.
		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$result = $this->execute_ability( 'mainwp/list-sites-v1', [] );

		$this->assertWPError( $result );
		// Abilities API wraps permission errors with ability_invalid_permissions.
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Test that permission is granted for administrator.
	 *
	 * @return void
	 */
	public function test_permission_granted_for_administrator() {
		$this->skip_if_no_abilities_api();

		$this->set_current_user_as_admin();

		$result = $this->execute_ability( 'mainwp/list-sites-v1', [] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
	}

	/**
	 * Test that per-site access is denied when ACL check fails.
	 *
	 * Note: The Abilities API wraps permission_callback errors with the code
	 * 'ability_invalid_permissions'. The original ACL error is preserved in the message.
	 *
	 * @return void
	 */
	public function test_per_site_access_denied() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		// Abilities API triggers _doing_it_wrong() when permission_callback returns WP_Error.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$site_id = $this->create_test_site( [
			'name' => 'ACL Denied Site',
		] );

		// Mock site access denial.
		add_filter(
			'mainwp_check_site_access',
			function () {
				return false;
			}
		);

		$result = $this->execute_ability( 'mainwp/get-site-v1', [
			'site_id_or_domain' => $site_id,
		] );

		$this->assertWPError( $result );
		// Abilities API wraps permission errors with ability_invalid_permissions.
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Test that per-site access is granted when ACL check passes.
	 *
	 * @return void
	 */
	public function test_per_site_access_granted() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site( [
			'name' => 'ACL Granted Site',
		] );

		// Don't mock ACL - should allow by default.
		$result = $this->execute_ability( 'mainwp/get-site-v1', [
			'site_id_or_domain' => $site_id,
		] );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertEquals( $site_id, $result['id'] );
	}

	/**
	 * Test that batch access check filters denied sites.
	 *
	 * @return void
	 */
	public function test_batch_access_check_filters_denied_sites() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site1_id = $this->create_test_site( [ 'name' => 'Allowed Site 1' ] );
		$site2_id = $this->create_test_site( [ 'name' => 'Denied Site 2' ] );
		$site3_id = $this->create_test_site( [ 'name' => 'Allowed Site 3' ] );

		// Mock ACL to deny site2.
		add_filter(
			'mainwp_check_site_access',
			function ( $access, $site_id ) use ( $site2_id ) {
				if ( (int) $site_id === $site2_id ) {
					return false;
				}
				return $access;
			},
			10,
			2
		);

		// Call utility directly.
		$result = \MainWP\Dashboard\MainWP_Abilities_Util::check_batch_site_access(
			[ $site1_id, $site2_id, $site3_id ],
			[]
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'allowed', $result );
		$this->assertArrayHasKey( 'denied', $result );

		// Should have 2 allowed.
		$allowed_ids = array_map(
			function ( $site ) {
				return (int) $site->id;
			},
			$result['allowed']
		);
		$this->assertContains( $site1_id, $allowed_ids );
		$this->assertContains( $site3_id, $allowed_ids );

		// Should have 1 denied.
		$this->assertCount( 1, $result['denied'] );
		$this->assertEquals( 'mainwp_access_denied', $result['denied'][0]['code'] );
	}

	/**
	 * Test that batch sync excludes ACL-denied sites.
	 *
	 * @return void
	 */
	public function test_batch_sync_excludes_acl_denied_sites() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site1_id = $this->create_test_site( [
			'name'                 => 'Sync Allowed Site',
			'offline_check_result' => 1,
		] );

		$site2_id = $this->create_test_site( [
			'name'                 => 'Sync Denied Site',
			'offline_check_result' => 1,
		] );

		// Mock ACL to deny site2.
		add_filter(
			'mainwp_check_site_access',
			function ( $access, $site_id ) use ( $site2_id ) {
				if ( (int) $site_id === $site2_id ) {
					return false;
				}
				return $access;
			},
			10,
			2
		);

		$result = $this->execute_ability( 'mainwp/sync-sites-v1', [
			'site_ids_or_domains' => [ $site1_id, $site2_id ],
		] );

		$this->assertNotWPError( $result );

		// Site2 should be in errors with permission denied.
		// Errors use 'identifier' key per the documented schema.
		$denied_ids = [];
		foreach ( $result['errors'] as $error ) {
			if ( $error['code'] === 'mainwp_access_denied' || $error['code'] === 'mainwp_permission_denied' ) {
				// Normalize identifier to int when it's numeric for comparison.
				$error_id     = is_numeric( $error['identifier'] ) ? (int) $error['identifier'] : $error['identifier'];
				$denied_ids[] = $error_id;
			}
		}

		$this->assertContains( $site2_id, $denied_ids, 'ACL-denied site should be in errors.' );
	}

	/**
	 * Test that unauthenticated request is denied.
	 *
	 * Note: The Abilities API wraps permission_callback errors with the code
	 * 'ability_invalid_permissions'. The original error is preserved in the message.
	 *
	 * @return void
	 */
	public function test_unauthenticated_request_denied() {
		$this->skip_if_no_abilities_api();

		// Abilities API triggers _doing_it_wrong() when permission_callback returns WP_Error.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		// Ensure no user is logged in.
		wp_set_current_user( 0 );

		$result = $this->execute_ability( 'mainwp/list-sites-v1', [] );

		$this->assertWPError( $result );
		// Abilities API wraps permission errors with ability_invalid_permissions.
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Test that permission callback returns proper error code and message.
	 *
	 * Per Abilities API documentation, permission errors are wrapped with
	 * 'ability_invalid_permissions' error code. The error message should
	 * contain meaningful information about the permission failure.
	 *
	 * Note: The Abilities API may or may not preserve error_data from the
	 * underlying WP_Error when wrapping permission errors.
	 *
	 * @return void
	 */
	public function test_permission_error_has_status() {
		$this->skip_if_no_abilities_api();

		// Abilities API triggers _doing_it_wrong() when permission_callback returns WP_Error.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		// No user logged in.
		wp_set_current_user( 0 );

		$result = $this->execute_ability( 'mainwp/list-sites-v1', [] );

		$this->assertWPError( $result, 'Unauthenticated request should return WP_Error.' );

		// Per Abilities API docs, permission errors use 'ability_invalid_permissions' code.
		$this->assertEquals(
			'ability_invalid_permissions',
			$result->get_error_code(),
			'Permission error should have ability_invalid_permissions code.'
		);

		// Verify error has a meaningful message.
		$error_message = $result->get_error_message();
		$this->assertNotEmpty( $error_message, 'Permission error should have a message.' );

		// If error_data is preserved by the Abilities API, verify it has proper status.
		$error_data = $result->get_error_data();
		if ( is_array( $error_data ) && isset( $error_data['status'] ) ) {
			$this->assertContains(
				$error_data['status'],
				[ 401, 403 ],
				'HTTP status should be 401 (Unauthorized) or 403 (Forbidden).'
			);
		}
	}

	/**
	 * Test that editor role (without manage_options) is denied access.
	 *
	 * Note: The Abilities API wraps permission_callback errors with the code
	 * 'ability_invalid_permissions'. The original error is preserved in the message.
	 *
	 * @return void
	 */
	public function test_permission_checks_manage_options_capability() {
		$this->skip_if_no_abilities_api();

		// Abilities API triggers _doing_it_wrong() when permission_callback returns WP_Error.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		// Create editor.
		$editor_id = $this->factory->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $editor_id );

		// Editor doesn't have manage_options.
		$result = $this->execute_ability( 'mainwp/list-sites-v1', [] );

		$this->assertWPError( $result );
		// Abilities API wraps permission errors with ability_invalid_permissions.
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Test that site resolution error is returned before ACL check.
	 *
	 * Note: The Abilities API wraps permission_callback errors with the code
	 * 'ability_invalid_permissions'. The original error is preserved in the message.
	 *
	 * @return void
	 */
	public function test_site_not_found_returned_before_acl() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$result = $this->execute_ability( 'mainwp/get-site-v1', [
			'site_id_or_domain' => 999999,
		] );

		$this->assertWPError( $result );
		$this->assertEquals( 'mainwp_site_not_found', $result->get_error_code() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		// Original error message should mention the site was not found.
		$this->assertStringContainsString( 'site', strtolower( $result->get_error_message() ) );
	}

	/**
	 * Test that permission check function returns correct types.
	 *
	 * @return void
	 */
	public function test_check_rest_api_permission_return_types() {
		$this->skip_if_no_abilities_api();

		// Unauthenticated should return WP_Error.
		wp_set_current_user( 0 );
		$result = \MainWP\Dashboard\MainWP_Abilities_Util::check_rest_api_permission( null );
		$this->assertWPError( $result );

		// Admin should return true.
		$this->set_current_user_as_admin();
		$result = \MainWP\Dashboard\MainWP_Abilities_Util::check_rest_api_permission( null );
		$this->assertTrue( $result );
	}

	/**
	 * Test that check_site_access validates site parameter.
	 *
	 * @return void
	 */
	public function test_check_site_access_validates_site() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site();

		// With valid site.
		$result = \MainWP\Dashboard\MainWP_Abilities_Util::check_site_access( $site_id, null );
		$this->assertTrue( $result );

		// With site object.
		$site = $this->get_test_site( $site_id );
		$result = \MainWP\Dashboard\MainWP_Abilities_Util::check_site_access( $site, null );
		$this->assertTrue( $result );
	}

	/**
	 * A WP_Error from the site-access filter refuses access instead of allowing it.
	 *
	 * @return void
	 */
	public function test_check_site_access_returns_filter_error() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site();
		$filter  = static function () {
			return new \WP_Error( 'mainwp_assistant_confirmation_required', 'Needs confirmation.' );
		};
		add_filter( 'mainwp_check_site_access', $filter, 99 );
		try {
			$result = \MainWP\Dashboard\MainWP_Abilities_Util::check_site_access( $site_id, null );
		} finally {
			remove_filter( 'mainwp_check_site_access', $filter, 99 );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'mainwp_assistant_confirmation_required', $result->get_error_code() );
	}

	/**
	 * Test that can_access_site helper returns boolean.
	 *
	 * @return void
	 */
	public function test_can_access_site_returns_boolean() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site_id = $this->create_test_site();

		$result = \MainWP\Dashboard\MainWP_Abilities_Util::can_access_site( $site_id, null );
		$this->assertIsBool( $result );
		$this->assertTrue( $result );
	}

	/**
	 * Test that permission denied for write operations without proper access.
	 *
	 * Note: The Abilities API wraps permission_callback errors with the code
	 * 'ability_invalid_permissions'. The original error is preserved in the message.
	 *
	 * @return void
	 */
	public function test_write_operation_permission_denied() {
		$this->skip_if_no_abilities_api();

		// Abilities API triggers _doing_it_wrong() when permission_callback returns WP_Error.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$result = $this->execute_ability( 'mainwp/set-ignored-updates-v1', [
			'action'            => 'ignore',
			'site_id_or_domain' => 1,
			'type'              => 'plugin',
			'slug'              => 'test-plugin',
		] );

		$this->assertWPError( $result );
		// Abilities API wraps permission errors with ability_invalid_permissions.
		$this->assertEquals( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Test that multiple permission errors accumulate in batch operations.
	 *
	 * @return void
	 */
	public function test_batch_accumulates_permission_errors() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$site1_id = $this->create_test_site( [ 'name' => 'Batch Test 1' ] );
		$site2_id = $this->create_test_site( [ 'name' => 'Batch Test 2' ] );
		$site3_id = $this->create_test_site( [ 'name' => 'Batch Test 3' ] );

		// Mock to deny sites 1 and 3.
		add_filter(
			'mainwp_check_site_access',
			function ( $access, $site_id ) use ( $site1_id, $site3_id ) {
				if ( in_array( (int) $site_id, [ $site1_id, $site3_id ], true ) ) {
					return false;
				}
				return $access;
			},
			10,
			2
		);

		$result = \MainWP\Dashboard\MainWP_Abilities_Util::check_batch_site_access(
			[ $site1_id, $site2_id, $site3_id ],
			[]
		);

		$this->assertCount( 1, $result['allowed'], 'Only 1 site should be allowed.' );
		$this->assertCount( 2, $result['denied'], '2 sites should be denied.' );
	}

	/**
	 * Test that nonexistent sites in batch are returned as errors.
	 *
	 * @return void
	 */
	public function test_batch_nonexistent_sites_are_errors() {
		$this->skip_if_no_abilities_api();
		$this->set_current_user_as_admin();

		$valid_id = $this->create_test_site();

		$result = \MainWP\Dashboard\MainWP_Abilities_Util::check_batch_site_access(
			[ $valid_id, 999999, 888888 ],
			[]
		);

		$this->assertCount( 1, $result['allowed'] );
		$this->assertCount( 2, $result['denied'] );

		// Both denied should have not_found code.
		foreach ( $result['denied'] as $denied ) {
			$this->assertEquals( 'mainwp_site_not_found', $denied['code'] );
		}
	}

	// =========================================================================
	// REST API Key Authentication Tests
	// =========================================================================

	/**
	 * Test that REST API key user with manage_options is granted access.
	 *
	 * @return void
	 */
	public function test_rest_api_key_user_with_manage_options_granted() {
		$this->skip_if_no_abilities_api();

		// Create admin user.
		$admin_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		// Mock the REST authentication to return a valid user.
		$this->mock_rest_api_key_user( $admin_id );

		$result = \MainWP\Dashboard\MainWP_Abilities_Util::check_rest_api_permission( null );

		$this->assertTrue( $result, 'REST API key user with manage_options should be granted access.' );

		// Clean up.
		$this->reset_rest_authentication();
	}

	/**
	 * Test that REST API key user without manage_options is denied.
	 *
	 * @return void
	 */
	public function test_rest_api_key_user_without_manage_options_denied() {
		$this->skip_if_no_abilities_api();

		// Create subscriber user (no manage_options).
		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		// Mock the REST authentication to return this user.
		$this->mock_rest_api_key_user( $subscriber_id );

		$result = \MainWP\Dashboard\MainWP_Abilities_Util::check_rest_api_permission( null );

		$this->assertWPError( $result );
		$this->assertEquals( 'mainwp_permission_denied', $result->get_error_code() );

		$error_data = $result->get_error_data();
		$this->assertIsArray( $error_data );
		$this->assertEquals( 403, $error_data['status'] );

		// Clean up.
		$this->reset_rest_authentication();
	}

	/**
	 * Mock the REST API key authentication by setting the user property on the singleton.
	 *
	 * Uses reflection to directly set the authenticated user on MainWP_REST_Authentication
	 * without requiring actual HTTP requests or database API key records. This enables
	 * testing permission logic for REST API key users without complex integration setup.
	 *
	 * @internal Tests depend on MainWP_REST_Authentication::$user property structure.
	 *           If this property name, type, or structure changes, this method and
	 *           reset_rest_authentication() must be updated accordingly.
	 *
	 * @param int $user_id User ID to set as the authenticated REST API user.
	 * @return void
	 */
	private function mock_rest_api_key_user( int $user_id ): void {
		if ( ! class_exists( 'MainWP_REST_Authentication' ) ) {
			$this->markTestSkipped( 'MainWP_REST_Authentication class not available.' );
		}

		$auth = \MainWP_REST_Authentication::get_instance();

		// Use reflection to set the protected $user property.
		$reflection = new \ReflectionClass( $auth );
		$user_prop  = $reflection->getProperty( 'user' );
		$user_prop->setAccessible( true );

		// Create a mock user object similar to what REST auth would store.
		$mock_user          = new \stdClass();
		$mock_user->user_id = $user_id;

		$user_prop->setValue( $auth, $mock_user );
	}

	/**
	 * Reset the REST authentication singleton's user property.
	 *
	 * @return void
	 */
	private function reset_rest_authentication(): void {
		if ( ! class_exists( 'MainWP_REST_Authentication' ) ) {
			return;
		}

		$auth = \MainWP_REST_Authentication::get_instance();

		$reflection = new \ReflectionClass( $auth );
		$user_prop  = $reflection->getProperty( 'user' );
		$user_prop->setAccessible( true );
		$user_prop->setValue( $auth, null );
	}
}
