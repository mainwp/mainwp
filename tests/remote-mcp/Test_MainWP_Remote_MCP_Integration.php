<?php
/**
 * Remote MCP integration tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Backup_Storage_Destinations;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Backup;
use MainWP\Dashboard\MainWP_Manage_Backups_Handler;
use MainWP\Dashboard\Remote_MCP\Call_Plan;
use MainWP\Dashboard\Remote_MCP\MCP_Redactor;
use MainWP\Dashboard\Remote_MCP\MCP_Approval_Gate;
use MainWP\Dashboard\Remote_MCP\MCP_Control_Contracts;
use MainWP\Dashboard\Remote_MCP\MCP_Job_Cron;
use MainWP\Dashboard\Remote_MCP\MCP_Extension_Contracts;
use MainWP\Dashboard\Remote_MCP\OAuth_Store;
use MainWP\Dashboard\Remote_MCP\MCP_Bearer_Auth;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Install;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Notices;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Admin;
use MainWP\Dashboard\Remote_MCP\MCP_Front_Controller;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Urls;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Approval_Page;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Context;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Events;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Request;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Services;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings;

require_once __DIR__ . '/class-remote-mcp-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Exercises the merged services against the WordPress test database.
 */
class Test_MainWP_Remote_MCP_Integration extends \WP_UnitTestCase {

	use Remote_MCP_Store_Fixtures, Site_Fixture;

	const RESOURCE  = 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp';
	const CLIENT_ID = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
	const REDIRECT  = 'https://claude.ai/api/mcp/auth_callback';
	const VERIFIER  = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz';

	/** @var Remote_MCP_Services */
	private $services;

	/** @var array */
	private $saved_server;

	/** @var array */
	private $saved_cookie;

	/** @var int */
	private $body_reads = 0;

	/** @var \WP_Rewrite */
	private $saved_rewrite;

	public function set_up() {
		parent::set_up();
		// Captured before the skip, because tear_down() still runs after a skipped set_up().
		$this->saved_server  = $_SERVER;
		$this->saved_cookie  = $_COOKIE;
		$this->saved_rewrite = clone $GLOBALS['wp_rewrite'];
		if ( ! class_exists( OAuth_Store::class ) ) {
			$this->markTestSkipped( 'Remote MCP requires PHP 8.1+ and a single site.' );
		}
		update_option( 'home', 'https://dash.example.org' );
		update_option( 'permalink_structure', '/%postname%/' );
		update_option( Remote_MCP_Settings::OPTION_ENABLED, '1' );
		delete_option( Remote_MCP_Settings::OPTION_COMPETING_RULES );
		$_SERVER['HTTPS']       = 'on';
		$_SERVER['SERVER_PORT'] = '443';
		$this->user_id          = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->user_id );
		$this->services = new Remote_MCP_Services();
		$this->store    = $this->services->store();
		$this->assertSame( OAuth_Store::class, get_class( $this->store ), 'production store' );
		$this->assertNotEmpty( wp_get_abilities(), 'core abilities registered' );
	}

	public function tear_down() {
		$_SERVER = $this->saved_server;
		$_COOKIE = $this->saved_cookie;
		$GLOBALS['wp_rewrite'] = $this->saved_rewrite;
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * A real grant and its bearer-derived context.
	 *
	 * @param string $policy Approval policy.
	 * @param string $scope Scope.
	 * @return Remote_MCP_Context
	 */
	private function context( string $policy = 'standard', string $scope = 'mainwp:read mainwp:write' ): Remote_MCP_Context {
		$minted = $this->store->mint_connection_token( $this->user_id, $scope, $policy, 'Integration', time() + DAY_IN_SECONDS );
		$this->assertSame( OAuth_Store::OK, $minted['outcome'] );
		$check = $this->store->check_connect_token( $minted['token'] );
		$this->assertSame( OAuth_Store::OK, $check['outcome'] );
		return Remote_MCP_Context::from_check( $check );
	}

	/**
	 * Use the saved log setting through the gate's existing predicate seam. The test bootstrap
	 * hard-disables the logs module to avoid its archive-table installer before MainWP's tables exist.
	 * No service slot, policy, tier, contract, resolver, adapter or executor is replaced.
	 *
	 * @return MCP_Approval_Gate
	 */
	private function gate_with_log_setting(): MCP_Approval_Gate {
		$gate     = $this->services->get( 'approval_gate' );
		$property = new \ReflectionProperty( MCP_Approval_Gate::class, 'log_available' );
		$property->setAccessible( true );
		$property->setValue(
			$gate,
			static function () {
				$settings = get_option( 'mainwp_module_log_settings' );
				return is_array( $settings ) && ! empty( $settings['enabled'] );
			}
		);
		return $gate;
	}

	/**
	 * Approval-page POST under a real cookie session and action nonce.
	 *
	 * @param int    $id Approval id.
	 * @param string $decision Page action.
	 * @return \MainWP\Dashboard\Remote_MCP\Remote_MCP_Response
	 */
	private function page_post( int $id, string $decision ) {
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $this->user_id, time() + HOUR_IN_SECONDS, 'logged_in' );
		wp_set_current_user( $this->user_id );
		$body = http_build_query(
			array(
				'approval' => $id,
				'decision' => $decision,
				'_wpnonce' => wp_create_nonce( Remote_MCP_Approval_Page::nonce_action( $id, $decision ) ),
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
		return $this->services->get( 'route_approve' )->handle( new Remote_MCP_Request( 'route_approve', 'POST', 'approval=' . $id, $body, $_SERVER ) );
	}

	public function test_reads_run_with_activity_log_off_and_writes_refuse() {
		$context = $this->context();
		$tools   = $this->services->get( 'tool_executor' );
		update_option( 'mainwp_module_log_settings', array( 'enabled' => 0 ) );
		$this->assertFalse( Remote_MCP_Settings::activity_log_available() );
		$result = $tools->call_tool( $context, 'list_tags_v1', array() );
		$this->assertFalse( $result['isError'], 'read executes with activity log off' );
		$this->assertArrayHasKey( 'items', $result['structuredContent'] );

		$gate = $this->services->get( 'approval_gate' );
		foreach ( array( 'mainwp/add-tag-v1', 'mainwp/add-client-v1', 'mainwp/add-site-v1' ) as $ability ) {
			$decision = $gate->check( $context, $ability, array( 'name' => 'Blocked' ) );
			$this->assertSame( 'refused', $decision['decision'], $ability );
			$this->assertSame( 'activity_log_off', $decision['reason'], $ability );
		}
		$this->assertTrue( $tools->call_tool( $context, 'add_tag_v1', array( 'name' => 'Blocked' ) )['isError'] );
		$this->assertSame( array(), $this->rows( 'approvals' ), 'no write approvals with the log off' );

		update_option( 'mainwp_module_log_settings', array( 'enabled' => 1 ) );
		$gate   = $this->gate_with_log_setting();
		$strict = $this->context( 'strict' );
		$first  = $gate->check( $strict, 'mainwp/add-client-v1', array( 'name' => 'Pending' ) );
		$ready  = $gate->check( $strict, 'mainwp/add-client-v1', array( 'name' => 'Ready' ) );
		$this->assertSame( 'approval_required', $first['decision'], wp_json_encode( $first ) );
		$this->assertSame( 'approval_required', $ready['decision'] );
		$this->page_post( $ready['approval_id'], 'approve' );
		$this->assertSame( 'approved', $this->store->get_approval( $ready['approval_id'] )['status'] );

		$plan = Call_Plan::from_entries( array( array( 'ability' => 'mainwp/add-tag-v1', 'args' => array( 'name' => 'Job blocked' ), 'target_ids' => null ) ) );
		$job  = $this->services->get( 'job_runner' )->enqueue( $context, $plan, null );
		$this->assertIsInt( $job );
		update_option( 'mainwp_module_log_settings', array( 'enabled' => 0 ) );
		foreach ( array( $first['approval_id'] => 'approve', $ready['approval_id'] => 'run' ) as $id => $action ) {
			$page = $this->page_post( $id, $action );
			$this->assertStringContainsString( 'activity log', $page->body() );
			$this->assertSame( 'activity_log_off', $gate->page_decide( $id, $this->user_id, $action )['reason'] );
		}
		$this->assertSame( 'pending', $this->store->get_approval( $first['approval_id'] )['status'] );
		$this->assertSame( 'approved', $this->store->get_approval( $ready['approval_id'] )['status'] );
		$this->assertFalse( $this->services->get( 'job_runner' )->tick( $job ) );
		$this->assertSame( 'cancelled', $this->store->get_job( $job )['status'] );
		$this->assertSame( 'audit_log_off', $this->store->get_job( $job )['last_error'] );
		$this->assertSame( 0, (int) $this->store->get_job( $job )['next_entry'] );
		$this->assertFalse( $tools->call_tool( $context, 'list_tags_v1', array() )['isError'], 'reads still run after writes refuse' );
	}

	public function test_audited_write_contract_is_accepted() {
		$entry = $this->services->get( 'tier_registry' )->entry( 'mainwp/add-tag-v1' );
		$this->assertSame( 'core', $entry['source'] );
		$this->assertSame( 'write', $entry['contract']['tier'] );
		$contracts = $this->services->get( 'control_contract' );
		$contract  = $contracts->from_entry( 'mainwp/add-tag-v1', $entry );
		$this->assertIsArray( $contract, 'audited write contract is accepted' );
		$this->assertSame( 'write', $contract['tier'] );
		$this->assertSame( 'audit', $contract['source'] );
		$derived           = $entry['contract'];
		$derived['source'] = 'derived';
		$this->assertSame( 'write', MCP_Extension_Contracts::shape( $derived )['tier'], 'derived write contract is accepted' );
	}

	public function test_extension_declared_write_contract_is_refused() {
		$entry = $this->services->get( 'tier_registry' )->entry( 'mainwp/add-tag-v1' );
		$raw   = $entry['contract'];
		unset( $raw['source'] );
		add_filter(
			MCP_Extension_Contracts::FILTER,
			static function ( $contracts ) use ( $raw ) {
				$contracts['integration/write-claim-v1']   = $raw;
				$contracts['integration/confirm-claim-v1'] = array_merge( $raw, array( 'tier' => 'confirm' ) );
				return $contracts;
			}
		);
		$contracts = $this->services->get( 'control_contract' );
		$this->assertIsArray( $contracts->declared_contract( 'integration/confirm-claim-v1' ), 'valid declaration reaches the validator' );
		$this->assertNull( $contracts->declared_contract( 'integration/write-claim-v1' ), 'an extension cannot claim write through the filter' );
	}

	public function test_tier_outcomes_through_real_scope_policy_and_gate() {
		update_option( 'mainwp_module_log_settings', array( 'enabled' => 1 ) );
		$gate   = $this->gate_with_log_setting();
		$policy = $this->services->get( 'scope_policy' );
		foreach ( array( 'scope_policy', 'tier_registry', 'approval_gate', 'control_contract', 'target_resolver', 'execution_adapter', 'tool_executor' ) as $slot ) {
			$this->assertFalse( $this->services->is_stub( $slot ), $slot );
			$this->assertSame( 'MainWP\\Dashboard\\Remote_MCP\\' . Remote_MCP_Services::SLOTS[ $slot ][1], get_class( $this->services->get( $slot ) ), $slot );
		}
		$site_id = $this->create_test_site();
		$cases = array(
			array( 'mainwp/list-tags-v1', array(), 'read' ),
			array( 'mainwp/add-tag-v1', array( 'name' => 'Integration tag' ), 'write' ),
			array( 'mainwp/add-client-v1', array( 'name' => 'Integration client', 'selected_sites' => array( $site_id ) ), 'confirm' ),
			array( 'mainwp/add-site-v1', array( 'url' => 'https://child.example.org' ), 'dashboard' ),
		);
		foreach ( array( 'standard', 'strict', 'hands_off' ) as $approval_policy ) {
			$context = $this->context( $approval_policy );
			foreach ( $cases as list( $ability, $args, $standard_tier ) ) {
				$tier = 'strict' === $approval_policy && 'read' !== $standard_tier ? 'dashboard' : $standard_tier;
				$this->assertSame( $tier, $policy->effective_tier( $context, $ability, $args ), $approval_policy . ' ' . $ability );
				$decision = $gate->check( $context, $ability, $args );
				$expected = in_array( $tier, array( 'read', 'write' ), true ) ? 'run' : ( 'confirm' === $tier ? 'confirmation_required' : 'approval_required' );
				$this->assertSame( $expected, $decision['decision'], $approval_policy . ' ' . $ability . ': ' . wp_json_encode( $decision ) );
				$this->assertSame( $tier, $decision['tier'] );
				if ( 'run' === $expected ) {
					$this->assertNull( $decision['approval_id'] );
					$this->assertInstanceOf( Call_Plan::class, $decision['plan'] );
				} else {
					$row = $this->store->get_approval( $decision['approval_id'] );
					$this->assertSame( $tier, $row['tier'] );
					$this->assertSame( 'confirm' === $tier ? 'approved' : 'pending', $row['status'] );
					$this->assertSame( 'confirm' === $tier ? 'chat' : null, $row['decided_via'] );
				}
			}
		}
	}

	public function test_backup_restore_and_delete_stay_dashboard_and_run_nothing_before_the_click() {
		update_option( 'mainwp_module_log_settings', array( 'enabled' => 1 ) );
		$gate     = $this->gate_with_log_setting();
		$policy   = $this->services->get( 'scope_policy' );
		$site_id  = $this->create_test_site();
		$cases    = array(
			'mainwp/restore-site-snapshot-v1'  => array(
				'site_id_or_domain' => $site_id,
				'snapshot_id'       => 41,
			),
			'mainwp/delete-backup-snapshot-v1' => array(
				'site_id_or_domain'     => $site_id,
				'snapshot_id'           => 41,
				'expected_snapshot_ids' => array( 41 ),
			),
		);
		$executed = array();
		$observe  = static function ( $name ) use ( &$executed ) {
			$executed[] = $name;
		};
		add_action( 'wp_before_execute_ability', $observe );
		foreach ( array( 'standard', 'hands_off' ) as $approval_policy ) {
			$context = $this->context( $approval_policy );
			foreach ( $cases as $ability => $args ) {
				$label = $approval_policy . ' ' . $ability;
				$this->assertSame( 'core', $this->services->get( 'tier_registry' )->entry( $ability )['source'], $label );
				$this->assertSame( 'dashboard', $policy->effective_tier( $context, $ability, $args ), $label );
				$decision = $gate->check( $context, $ability, $args );
				$this->assertSame( 'approval_required', $decision['decision'], $label . ': ' . wp_json_encode( $decision ) );
				$this->assertSame( 'dashboard', $decision['tier'], $label );
				$row = $this->store->get_approval( $decision['approval_id'] );
				$this->assertSame( array( 'dashboard', 'pending', null ), array( $row['tier'], $row['status'], $row['decided_via'] ), $label );
				$plan = $this->store->approval_call_plan( $row );
				$this->assertInstanceOf( Call_Plan::class, $plan, $label );
				$this->assertTrue( $plan->is_pending(), $label . ': the row holds a plan nothing can execute' );
				$this->assertSame( array( $site_id ), $plan->target_ids(), $label );
				$result = $gate->deliver( $context, $decision );
				$this->assertFalse( $result['isError'], $label );
				$this->assertSame( 'APPROVAL_REQUIRED', $result['structuredContent']['status'], $label );
			}
		}
		remove_action( 'wp_before_execute_ability', $observe );
		$this->assertSame( array(), $executed, 'no ability, native preview included, runs before the click' );
	}

	public function test_backup_destination_takes_no_credential_and_a_sent_one_is_never_stored() {
		update_option( 'mainwp_module_log_settings', array( 'enabled' => 1 ) );
		update_option( 'mainwp_enableNativeBackups', 1 );
		$registry = $this->services->get( 'tier_registry' );
		foreach ( array( 'test', 'set-default', 'update', 'delete' ) as $action ) {
			$name   = 'mainwp/' . $action . '-backup-destination-v1';
			$schema = wp_get_ability( $name )->get_input_schema();
			$this->assertSame( array(), MCP_Redactor::credential_fields( $schema['properties'], array() ), $name . ' declares no credential input' );
			$this->assertSame( array( 'core', array(), false ), array( $registry->entry( $name )['source'], $registry->entry( $name )['sensitive'], $registry->entry( $name )['credential_bearing'] ), $name );
		}

		$dir          = sys_get_temp_dir() . '/mainwp-remote-mcp-destination-' . uniqid();
		$destinations = MainWP_Backup_Storage_Destinations::instance();
		mkdir( $dir );
		$id = $destinations->add_destination(
			array(
				'provider' => 'local',
				'name'     => 'Remote MCP destination',
				'settings' => array( 'base_path' => $dir ),
			)
		);
		try {
			$gate     = $this->gate_with_log_setting();
			$context  = $this->context();
			$ability  = 'mainwp/update-backup-destination-v1';
			$declared = array(
				'destination_id' => $id,
				'name'           => 'Renamed',
			);
			$secrets  = array(
				'access_key' => 'AKIA-REMOTE-MCP-CANARY',
				'secret_key' => 'secret-remote-mcp-canary',
				'password'   => 'password-remote-mcp-canary',
				'settings'   => array( 'base_path' => '/srv/remote-mcp-canary' ),
			);
			$this->assertSame( 'confirm', $this->services->get( 'scope_policy' )->effective_tier( $context, $ability, $declared ) );
			$decision = $gate->check( $context, $ability, $declared + $secrets );
			$this->assertSame( 'confirmation_required', $decision['decision'], wp_json_encode( $decision ) );
			$row  = $this->store->get_approval( $decision['approval_id'] );
			$plan = $this->store->approval_call_plan( $row );
			$this->assertInstanceOf( Call_Plan::class, $plan );
			$this->assertSame( $gate->args_digest( $ability, $declared ), $row['args_digest'], 'the row binds the declared arguments only' );
			$args = $plan->entries()[0]['args'];
			$this->assertSame( array(), array_diff( array_keys( $args ), array_keys( wp_get_ability( $ability )->get_input_schema()['properties'] ) ), 'the plan carries declared keys only' );
			$stored = wp_json_encode( array( $this->rows( 'approvals' ), $plan->to_json(), $decision, $gate->deliver( $context, $decision ) ) );
			foreach ( array( 'AKIA-REMOTE-MCP-CANARY', 'secret-remote-mcp-canary', 'password-remote-mcp-canary', 'remote-mcp-canary' ) as $canary ) {
				$this->assertStringNotContainsString( $canary, $stored );
			}
			$this->assertSame( 'Remote MCP destination', $destinations->get_destination( $id )->name, 'the preview changed nothing' );
		} finally {
			$destinations->delete_destination( $id );
			foreach ( glob( $dir . '/*' ) ?: array() as $file ) {
				unlink( $file );
			}
			rmdir( $dir );
		}
	}

	public function test_cancel_restore_stays_dashboard_and_runs_nothing_before_the_click() {
		update_option( 'mainwp_module_log_settings', array( 'enabled' => 1 ) );
		$gate     = $this->gate_with_log_setting();
		$ability  = 'mainwp/cancel-restore-v1';
		$args     = array( 'restore_job_id' => 41 );
		$executed = array();
		$observe  = static function ( $name ) use ( &$executed ) {
			$executed[] = $name;
		};
		add_action( 'wp_before_execute_ability', $observe );
		foreach ( array( 'standard', 'hands_off' ) as $approval_policy ) {
			$context = $this->context( $approval_policy );
			$this->assertSame( 'dashboard', $this->services->get( 'scope_policy' )->effective_tier( $context, $ability, $args ), $approval_policy );
			$decision = $gate->check( $context, $ability, $args );
			$this->assertSame( 'approval_required', $decision['decision'], $approval_policy . ': ' . wp_json_encode( $decision ) );
			$row = $this->store->get_approval( $decision['approval_id'] );
			$this->assertSame( array( 'dashboard', 'pending', null ), array( $row['tier'], $row['status'], $row['decided_via'] ), $approval_policy );
			$this->assertTrue( $this->store->approval_call_plan( $row )->is_pending(), $approval_policy );
			$this->assertSame( 'APPROVAL_REQUIRED', $gate->deliver( $context, $decision )['structuredContent']['status'], $approval_policy );
		}
		remove_action( 'wp_before_execute_ability', $observe );
		$this->assertSame( array(), $executed, 'no ability, native preview included, runs before the click' );
	}

	/**
	 * A native backup task on the given sites.
	 *
	 * @param int[] $site_ids Sites.
	 * @return int Task id.
	 */
	private function backup_task( array $site_ids ): int {
		update_option( 'mainwp_enableNativeBackups', 1 );
		$task = MainWP_DB_Backup::instance()->add_backup_task( $this->user_id, 'Remote MCP task', 'daily', 'full', '', implode( ',', $site_ids ), '', '', '', 0, 0, 0, 0, 0, 'zip', 0, 1, 150, 0, 0 );
		$this->assertIsObject( $task );
		MainWP_Manage_Backups_Handler::stamp_task_scope_snapshot( (int) $task->id, implode( ',', $site_ids ), '' );
		return (int) $task->id;
	}

	/**
	 * A site added to a task the way a task edit saves it.
	 *
	 * @param int   $task_id  Task.
	 * @param int[] $site_ids The task's sites after the edit.
	 */
	private function set_task_sites( int $task_id, array $site_ids ): void {
		MainWP_DB_Backup::instance()->update_backup_task_with_values( $task_id, array( 'sites' => implode( ',', $site_ids ) ) );
		MainWP_Manage_Backups_Handler::stamp_task_scope_snapshot( $task_id, implode( ',', $site_ids ), '' );
	}

	/**
	 * A completed snapshot of a site taken by a task.
	 *
	 * @param int $task_id Task.
	 * @param int $site_id Site.
	 * @return int Snapshot id.
	 */
	private function task_snapshot( int $task_id, int $site_id ): int {
		global $wpdb;
		$this->assertNotFalse(
			$wpdb->insert(
				MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ),
				array(
					'site_id'  => $site_id,
					'task_id'  => $task_id,
					'type'     => 'full',
					'status'   => 'complete',
					'created'  => time() - DAY_IN_SECONDS,
					'archives' => '[]',
				)
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Remove a task fixture and its snapshots.
	 *
	 * @param int $task_id Task.
	 */
	private function drop_backup_task( int $task_id ): void {
		global $wpdb;
		$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' ), array( 'task_id' => $task_id ) );
		$wpdb->delete( MainWP_DB::instance()->get_table_name( 'wp_backup' ), array( 'id' => $task_id ) );
	}

	/**
	 * Records every ability call made with confirm: true, and keeps the Child unreachable.
	 *
	 * @param array $confirmed Names of the confirmed calls, filled by reference.
	 * @return callable[] The hooks, for remove_hooks().
	 */
	private function watch_confirmed_calls( array &$confirmed ): array {
		$watch = static function ( $name, $input ) use ( &$confirmed ) {
			if ( is_array( $input ) && true === ( $input['confirm'] ?? null ) ) {
				$confirmed[] = $name;
			}
		};
		$block = static function () {
			return new \WP_Error( 'http_blocked', 'No outbound request in this test.' );
		};
		add_action( 'wp_before_execute_ability', $watch, 10, 2 );
		add_filter( 'pre_http_request', $block );
		return array( $watch, $block );
	}

	/**
	 * Remove the hooks of watch_confirmed_calls().
	 *
	 * @param callable[] $hooks Hooks.
	 */
	private function remove_hooks( array $hooks ): void {
		remove_action( 'wp_before_execute_ability', $hooks[0], 10 );
		remove_filter( 'pre_http_request', $hooks[1] );
	}

	public function test_approved_run_backup_task_goes_stale_when_the_task_gains_a_site() {
		update_option( 'mainwp_module_log_settings', array( 'enabled' => 1 ) );
		$gate      = $this->gate_with_log_setting();
		$ability   = 'mainwp/run-backup-task-v1';
		$first     = $this->create_test_site();
		$added     = $this->create_test_site();
		$task_id   = $this->backup_task( array( $first ) );
		$args      = array( 'task_id' => $task_id );
		$confirmed = array();
		$hooks     = $this->watch_confirmed_calls( $confirmed );
		try {
			$standard = $this->context();
			$chat     = $gate->check( $standard, $ability, $args );
			$this->assertSame( 'confirmation_required', $chat['decision'], wp_json_encode( $chat ) );
			$this->assertSame( array( $first ), array_map( 'intval', $chat['gate']['preview']['would_affect']['site_ids'] ), 'the preview lists the task\'s sites' );
			$confirm = $args + array(
				'user_confirmed'     => true,
				'confirmation_token' => $chat['gate']['handle'],
			);
			$this->assertSame( 'run', $gate->check( $standard, $ability, $confirm )['decision'], 'an unchanged task keeps its confirmation' );

			$strict_retry = $this->context( 'strict' );
			$strict_page  = $this->context( 'strict' );
			$retry        = $gate->check( $strict_retry, $ability, $args );
			$page         = $gate->check( $strict_page, $ability, $args );
			foreach ( array( $retry, $page ) as $decision ) {
				$this->assertSame( 'approval_required', $decision['decision'], wp_json_encode( $decision ) );
				$this->assertSame( 'approved', $gate->page_decide( $decision['approval_id'], $this->user_id, 'approve' )['outcome'] );
			}
			$this->assertSame( 'run', $gate->check( $strict_retry, $ability, $args )['decision'], 'an unchanged task keeps its approval' );
			$this->assertTrue( $gate->page_view( $page['approval_id'], $this->user_id )['can_run'], 'the page can re-check this one-entry plan' );

			$this->set_task_sites( $task_id, array( $first, $added ) );

			$again = $gate->check( $standard, $ability, $confirm );
			$this->assertSame( 'confirmation_required', $again['decision'], 'retry: ' . wp_json_encode( $again ) );
			$this->assertSame( 'stale', $this->store->get_approval( $chat['approval_id'] )['status'] );
			$this->assertSame( array( $first, $added ), array_map( 'intval', $again['gate']['preview']['would_affect']['site_ids'] ), 'the new confirmation shows both sites' );

			$again = $gate->check( $strict_retry, $ability, $args );
			$this->assertSame( 'approval_required', $again['decision'], 'dashboard retry: ' . wp_json_encode( $again ) );
			$this->assertNotSame( $retry['approval_id'], $again['approval_id'] );
			$this->assertSame( 'stale', $this->store->get_approval( $retry['approval_id'] )['status'] );

			$this->assertSame(
				array(
					'outcome' => 'stale',
					'reason'  => 'preview_changed',
				),
				$gate->page_decide( $page['approval_id'], $this->user_id, 'run' )
			);
			$this->assertSame( 'stale', $this->store->get_approval( $page['approval_id'] )['status'] );
			$this->assertSame( array(), $confirmed, 'nothing ran after the task changed' );
		} finally {
			$this->remove_hooks( $hooks );
			$this->drop_backup_task( $task_id );
		}
	}

	public function test_approved_delete_backup_task_goes_stale_when_the_task_gains_a_site() {
		global $wpdb;
		update_option( 'mainwp_module_log_settings', array( 'enabled' => 1 ) );
		$gate      = $this->gate_with_log_setting();
		$ability   = 'mainwp/delete-backup-task-v1';
		$first     = $this->create_test_site();
		$added     = $this->create_test_site();
		$task_id   = $this->backup_task( array( $first ) );
		$kept      = array( $this->task_snapshot( $task_id, $first ) );
		$args      = array( 'task_id' => $task_id );
		$confirmed = array();
		$hooks     = $this->watch_confirmed_calls( $confirmed );
		try {
			$retry_context = $this->context();
			$page_context  = $this->context();
			$retry         = $gate->check( $retry_context, $ability, $args );
			$page          = $gate->check( $page_context, $ability, $args );
			foreach ( array( $retry, $page ) as $decision ) {
				$this->assertSame( 'approval_required', $decision['decision'], wp_json_encode( $decision ) );
				$this->assertSame( 'dashboard', $decision['tier'] );
				$this->assertSame( 'approved', $gate->page_decide( $decision['approval_id'], $this->user_id, 'approve' )['outcome'] );
			}
			$this->assertSame( 'run', $gate->check( $retry_context, $ability, $args )['decision'], 'an unchanged task keeps its approval' );
			$this->assertTrue( $gate->page_view( $page['approval_id'], $this->user_id )['can_run'], 'the page can re-check this one-entry plan' );

			// Deleting a task purges the snapshots it took, so the new site widens the deletion
			// once the task has backed it up.
			$this->set_task_sites( $task_id, array( $first, $added ) );
			$kept[]  = $this->task_snapshot( $task_id, $added );
			$preview = MCP_Control_Contracts::run_ability( $ability, $args + array( 'dry_run' => true ), $this->user_id );
			$this->assertSame( array( $first, $added ), array_map( 'intval', array_column( $preview['would_affect']['snapshots'], 'site_id' ) ), 'the preview lists the snapshots and their sites' );

			$again = $gate->check( $retry_context, $ability, $args );
			$this->assertSame( 'approval_required', $again['decision'], 'retry: ' . wp_json_encode( $again ) );
			$this->assertNotSame( $retry['approval_id'], $again['approval_id'] );
			$this->assertSame( 'stale', $this->store->get_approval( $retry['approval_id'] )['status'] );

			$this->assertSame(
				array(
					'outcome' => 'stale',
					'reason'  => 'preview_changed',
				),
				$gate->page_decide( $page['approval_id'], $this->user_id, 'run' )
			);
			$this->assertSame( 'stale', $this->store->get_approval( $page['approval_id'] )['status'] );
			$this->assertSame( array(), $confirmed, 'nothing ran after the task changed' );
			$this->assertIsObject( MainWP_DB_Backup::instance()->get_backup_task_by_id( $task_id ) );
			$table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
			foreach ( $kept as $snapshot_id ) {
				$this->assertSame( 'complete', $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . $table . ' WHERE id = %d', $snapshot_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		} finally {
			$this->remove_hooks( $hooks );
			$this->drop_backup_task( $task_id );
		}
	}

	/**
	 * Completed snapshots of a site taken by a task, inserted in one statement.
	 *
	 * @param int $task_id Task.
	 * @param int $site_id Site.
	 * @param int $count   How many.
	 * @return int[] Snapshot ids.
	 */
	private function task_snapshots( int $task_id, int $site_id, int $count ): array {
		global $wpdb;
		$table  = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
		$values = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$values[] = $wpdb->prepare( '(%d, %d, %s, %s, %d, %s)', $site_id, $task_id, 'full', 'complete', time() - DAY_IN_SECONDS, '[]' );
		}
		$this->assertSame( $count, $wpdb->query( 'INSERT INTO ' . $table . ' (site_id, task_id, type, status, created, archives) VALUES ' . implode( ',', $values ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . $table . ' WHERE task_id = %d AND site_id = %d ORDER BY id', $task_id, $site_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * A cloud copy of every snapshot on each of several new destinations.
	 *
	 * @param int[] $snapshot_ids Snapshots.
	 * @param int   $count        Destinations.
	 * @return int[] Destination ids, for cloud_cleanup().
	 */
	private function cloud_copies( array $snapshot_ids, int $count ): array {
		global $wpdb;
		$db           = MainWP_DB::instance();
		$destinations = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$this->assertNotFalse(
				$wpdb->insert(
					$db->get_table_name( 'wp_backup_storage' ),
					array(
						'name'     => 'Copy fixture ' . $i,
						'provider' => 's3',
						'settings' => '',
					)
				)
			);
			$destination    = (int) $wpdb->insert_id;
			$destinations[] = $destination;
			$values         = array();
			foreach ( $snapshot_ids as $snapshot_id ) {
				$values[] = $wpdb->prepare( '(%d, %d, %s, %s, %s, %s)', $snapshot_id, $destination, 's3', 'complete', '', '' );
			}
			$this->assertSame( count( $snapshot_ids ), $wpdb->query( 'INSERT INTO ' . $db->get_table_name( 'wp_backup_upload_job' ) . ' (snapshot_id, destination_id, provider, status, checkpoint, last_error) VALUES ' . implode( ',', $values ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return $destinations;
	}

	/**
	 * Remove the destinations of cloud_copies() and their copies.
	 *
	 * @param int[] $destinations Destination ids.
	 */
	private function cloud_cleanup( array $destinations ): void {
		global $wpdb;
		$db = MainWP_DB::instance();
		foreach ( $destinations as $destination ) {
			$wpdb->delete( $db->get_table_name( 'wp_backup_upload_job' ), array( 'destination_id' => $destination ) );
			$wpdb->delete( $db->get_table_name( 'wp_backup_storage' ), array( 'id' => $destination ) );
		}
	}

	public function test_activation_shows_the_native_preview_before_confirmation() {
		update_option( 'mainwp_module_log_settings', array( 'enabled' => 1 ) );
		$gate    = $this->gate_with_log_setting();
		$site_id = $this->create_test_site(
			array(
				'plugins' => wp_json_encode(
					array(
						array(
							'slug'   => 'akismet/akismet.php',
							'name'   => 'Akismet',
							'active' => 0,
						),
					)
				),
			)
		);
		$calls   = array(
			'mainwp/activate-site-plugins-v1'   => array( 'plugins' => array( 'akismet/akismet.php' ) ),
			'mainwp/deactivate-site-plugins-v1' => array( 'plugins' => array( 'akismet/akismet.php' ) ),
			'mainwp/activate-site-theme-v1'     => array( 'theme' => 'twentytwentyfive' ),
		);
		$input   = array();
		$observe = static function ( $name, $ability_input ) use ( &$input ) {
			$input[ $name ] = $ability_input;
		};
		add_action( 'wp_before_execute_ability', $observe, 10, 2 );
		try {
			foreach ( $calls as $ability => $arguments ) {
				$decision = $gate->check( $this->context(), $ability, array( 'site_id_or_domain' => $site_id ) + $arguments );
				$this->assertSame( 'confirmation_required', $decision['decision'], $ability . ': ' . wp_json_encode( $decision ) );
				$this->assertSame( 'preview', $decision['gate']['kind'], $ability );
				$this->assertArrayHasKey( 'would_affect', $decision['gate']['preview'], $ability );
				$this->assertTrue( $input[ $ability ]['dry_run'], $ability . ' ran only as a preview' );
			}
		} finally {
			remove_action( 'wp_before_execute_ability', $observe, 10 );
		}
	}

	public function test_create_backup_task_with_groups_is_refused_before_any_row() {
		update_option( 'mainwp_module_log_settings', array( 'enabled' => 1 ) );
		update_option( 'mainwp_enableNativeBackups', 1 );
		$gate     = $this->gate_with_log_setting();
		$ability  = 'mainwp/create-backup-task-v1';
		// Backup tasks refuse a site whose Child is older than 6.4.
		$site_id  = $this->create_test_site( array( 'version' => '6.4.0' ) );
		$base     = array(
			'name'     => 'Remote MCP created task',
			'type'     => 'full',
			'schedule' => 'daily',
		);
		$grouped  = $base + array( 'group_ids' => array( 7 ) );
		$wording  = 'AI apps can\'t create backup tasks that use groups yet, because the sites in a group can change after you approve. Use the site IDs instead, or create the task in MainWP under Backups.';
		$executed = array();
		$observe  = static function ( $name ) use ( &$executed ) {
			$executed[] = $name;
		};
		add_action( 'wp_before_execute_ability', $observe );
		try {
			foreach ( array( 'standard', 'strict', 'hands_off' ) as $approval_policy ) {
				$context  = $this->context( $approval_policy );
				$decision = $gate->check( $context, $ability, $grouped + array( 'site_ids_or_domains' => array( $site_id ) ) );
				$this->assertSame( array( 'refused', 'backup_task_groups' ), array( $decision['decision'], $decision['reason'] ), $approval_policy . ': ' . wp_json_encode( $decision ) );
				$result = $this->services->get( 'tool_executor' )->call_tool( $context, 'create_backup_task_v1', $grouped );
				$this->assertTrue( $result['isError'], $approval_policy );
				$this->assertSame( $wording, $result['content'][0]['text'], $approval_policy );
			}
			$this->assertSame( array(), $this->rows( 'approvals' ), 'no approval row for a task with groups' );
			$this->assertSame( array(), $executed, 'no ability, its preview included, ran' );

			$named = $gate->check( $this->context(), $ability, $base + array( 'site_ids_or_domains' => array( $site_id ), 'group_ids' => array() ) );
			$this->assertSame( 'confirmation_required', $named['decision'], 'a task naming its sites is still offered: ' . wp_json_encode( $named ) );
			$this->assertSame( array( $site_id ), array_map( 'intval', $named['gate']['preview']['would_affect']['resolved_site_ids'] ) );
		} finally {
			remove_action( 'wp_before_execute_ability', $observe );
		}
	}

	public function test_create_backup_task_naming_a_site_by_domain_is_refused_before_any_row() {
		update_option( 'mainwp_module_log_settings', array( 'enabled' => 1 ) );
		update_option( 'mainwp_enableNativeBackups', 1 );
		$gate     = $this->gate_with_log_setting();
		$site_id  = $this->create_test_site();
		$domain   = MainWP_DB::instance()->get_website_by_id( $site_id )->url;
		$args     = array(
			'name'                => 'Remote MCP created task',
			'type'                => 'full',
			'schedule'            => 'daily',
			'site_ids_or_domains' => array( $site_id, $domain ),
		);
		$executed = array();
		$observe  = static function ( $name ) use ( &$executed ) {
			$executed[] = $name;
		};
		add_action( 'wp_before_execute_ability', $observe );
		try {
			foreach ( array( 'standard', 'strict', 'hands_off' ) as $approval_policy ) {
				$decision = $gate->check( $this->context( $approval_policy ), 'mainwp/create-backup-task-v1', $args );
				$this->assertSame( array( 'refused', 'backup_task_domains' ), array( $decision['decision'], $decision['reason'] ), $approval_policy . ': ' . wp_json_encode( $decision ) );
			}
			$this->assertSame( array(), $this->rows( 'approvals' ), 'no approval row for a task naming a domain' );
			$this->assertSame( array(), $executed, 'no ability, its preview included, ran' );
		} finally {
			remove_action( 'wp_before_execute_ability', $observe );
		}
	}

	public function test_update_backup_task_is_refused_in_every_policy_and_on_every_later_path() {
		update_option( 'mainwp_module_log_settings', array( 'enabled' => 1 ) );
		$gate      = $this->gate_with_log_setting();
		$ability   = 'mainwp/update-backup-task-v1';
		$task_id   = $this->backup_task( array( $this->create_test_site() ) );
		$args      = array(
			'name'    => 'Renamed remotely',
			'task_id' => $task_id,
		);
		$wording   = 'AI apps can\'t edit backup tasks yet. Edit the task in MainWP under Backups.';
		$executed  = array();
		$observe   = static function ( $name ) use ( &$executed ) {
			$executed[] = $name;
		};
		$confirmed = array();
		$hooks     = $this->watch_confirmed_calls( $confirmed );
		add_action( 'wp_before_execute_ability', $observe );
		try {
			foreach ( array( 'standard', 'strict', 'hands_off' ) as $approval_policy ) {
				$context  = $this->context( $approval_policy );
				$decision = $gate->check( $context, $ability, $args );
				$this->assertSame( array( 'refused', 'backup_task_update' ), array( $decision['decision'], $decision['reason'] ), $approval_policy . ': ' . wp_json_encode( $decision ) );
				$result = $this->services->get( 'tool_executor' )->call_tool( $context, 'update_backup_task_v1', $args );
				$this->assertTrue( $result['isError'], $approval_policy );
				$this->assertSame( $wording, $result['content'][0]['text'], $approval_policy );
			}
			$this->assertSame( array(), $this->rows( 'approvals' ), 'no approval row for a task edit' );
			$this->assertSame( array(), $executed, 'no ability, its preview included, ran' );

			// Rows written before the refusal existed: one waiting for the click, one approved.
			$context = $this->context();
			$rows    = array();
			foreach ( array( 'pending', 'approved' ) as $status ) {
				$created = $this->store->create_approval(
					array(
						'grant_id'    => $context->grant_id,
						'ability'     => $ability,
						'tier'        => 'dashboard',
						'args_digest' => $gate->args_digest( $ability, $args ),
						'summary'     => '{}',
						'call_plan'   => Call_Plan::pending(
							array(
								'ability'    => $ability,
								'args'       => $args,
								'target_ids' => null,
							)
						),
					)
				);
				$this->assertSame( OAuth_Store::OK, $created['outcome'] );
				$rows[ $status ] = (int) $created['approval_id'];
			}
			$plan = Call_Plan::from_entries(
				array(
					array(
						'ability'    => $ability,
						'args'       => $args + array(
							'confirm' => true,
							'dry_run' => false,
						),
						'target_ids' => null,
					),
				)
			);
			$this->assertTrue( $this->store->decide_approval( $rows['approved'], $this->user_id, true, array( 'call_plan' => $plan ) ) );

			$this->assertSame( array( 'refused', 'backup_task_update' ), array_values( $gate->page_decide( $rows['pending'], $this->user_id, 'approve' ) ), 'page approve' );
			$this->assertSame( 'pending', $this->store->get_approval( $rows['pending'] )['status'] );
			$this->assertSame( array( 'refused', 'backup_task_update' ), array_values( $gate->page_decide( $rows['approved'], $this->user_id, 'run' ) ), 'page Run' );
			$retry = $gate->check( $context, $ability, $args );
			$this->assertSame( array( 'refused', 'backup_task_update' ), array( $retry['decision'], $retry['reason'] ), 'retry of an approved row' );
			$this->assertSame( 'approved', $this->store->get_approval( $rows['approved'] )['status'], 'the approved row was never consumed' );
			$this->assertSame( array(), $executed, 'nothing ran from an existing row' );
			$this->assertSame( 'Remote MCP task', MainWP_DB_Backup::instance()->get_backup_task_by_id( $task_id )->name );
		} finally {
			remove_action( 'wp_before_execute_ability', $observe );
			$this->remove_hooks( $hooks );
			$this->drop_backup_task( $task_id );
		}
	}

	public function test_delete_backup_task_with_snapshots_is_refused_when_the_click_preview_is_capped() {
		global $wpdb;
		update_option( 'mainwp_module_log_settings', array( 'enabled' => 1 ) );
		$gate      = $this->gate_with_log_setting();
		$ability   = 'mainwp/delete-backup-task-v1';
		$site_id   = $this->create_test_site();
		$task_id   = $this->backup_task( array( $site_id ) );
		$snapshots = $this->task_snapshots( $task_id, $site_id, 501 );
		$args      = array( 'task_id' => $task_id );
		$confirmed = array();
		$hooks     = $this->watch_confirmed_calls( $confirmed );
		try {
			$context  = $this->context();
			$decision = $gate->check( $context, $ability, $args );
			$this->assertSame( 'approval_required', $decision['decision'], wp_json_encode( $decision ) );
			$this->assertSame(
				array(
					'outcome' => 'refused',
					'reason'  => 'backup_preview_capped',
				),
				$gate->page_decide( $decision['approval_id'], $this->user_id, 'approve' ),
				'the click\'s preview lists only 500 of the snapshots'
			);
			$this->assertSame( 'stale', $this->store->get_approval( $decision['approval_id'] )['status'] );
			$this->assertSame( array(), $confirmed );
			$this->assertSame(
				'This task has too many backups to list for approval, so an AI app can\'t delete it together with its backups. Delete it in MainWP under Backups, or delete the task and keep its backups.',
				MCP_Approval_Gate::refusal_text( 'backup_preview_capped' )
			);

			// Keeping the snapshots deletes only the task, so the capped list doesn't matter.
			$keep     = $args + array( 'delete_snapshots' => false );
			$decision = $gate->check( $context, $ability, $keep );
			$this->assertSame( 'approval_required', $decision['decision'], wp_json_encode( $decision ) );
			$this->assertSame( 'approved', $gate->page_decide( $decision['approval_id'], $this->user_id, 'approve' )['outcome'] );
			$retry = $gate->check( $context, $ability, $keep );
			$this->assertSame( 'run', $retry['decision'], wp_json_encode( $retry ) );
			$result = $gate->deliver( $context, $retry );
			$this->assertFalse( $result['isError'], wp_json_encode( $result ) );
			$this->assertSame( array( $ability ), $confirmed );
			$this->assertEmpty( MainWP_DB_Backup::instance()->get_backup_task_by_id( $task_id ) );
			$table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
			$this->assertSame( '501', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE task_id = %d AND status = 'complete'", $task_id ) ), 'every snapshot kept' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->assertCount( 501, $snapshots );
		} finally {
			$this->remove_hooks( $hooks );
			$this->drop_backup_task( $task_id );
		}
	}

	public function test_approved_delete_backup_task_is_refused_when_its_preview_grows_past_the_cap() {
		update_option( 'mainwp_module_log_settings', array( 'enabled' => 1 ) );
		$gate         = $this->gate_with_log_setting();
		$ability      = 'mainwp/delete-backup-task-v1';
		$site_id      = $this->create_test_site();
		$tasks        = array();
		$destinations = array();
		$confirmed    = array();
		$hooks        = $this->watch_confirmed_calls( $confirmed );
		try {
			// One task grows past 500 snapshots, the other past 500 cloud copies with 250 snapshots.
			foreach ( array( 'snapshots', 'cloud_copies' ) as $cap ) {
				$task_id = $this->backup_task( array( $site_id ) );
				$tasks[] = $task_id;
				MainWP_DB_Backup::instance()->update_backup_task_with_values( $task_id, array( 'delete_cloud_copies' => 1 ) );
				$this->task_snapshots( $task_id, $site_id, 1 );
				$args          = array( 'task_id' => $task_id );
				$retry_context = $this->context();
				$page_context  = $this->context();
				$retry         = $gate->check( $retry_context, $ability, $args );
				$page          = $gate->check( $page_context, $ability, $args );
				foreach ( array( $retry, $page ) as $decision ) {
					$this->assertSame( 'approval_required', $decision['decision'], $cap . ': ' . wp_json_encode( $decision ) );
					$this->assertSame( 'approved', $gate->page_decide( $decision['approval_id'], $this->user_id, 'approve' )['outcome'], $cap );
				}

				if ( 'snapshots' === $cap ) {
					$this->task_snapshots( $task_id, $site_id, 500 );
				} else {
					$destinations = array_merge( $destinations, $this->cloud_copies( $this->task_snapshots( $task_id, $site_id, 250 ), 2 ) );
				}
				$preview = MCP_Control_Contracts::run_ability( $ability, $args + array( 'dry_run' => true ), $this->user_id );
				$this->assertSame( 'snapshots' === $cap, $preview['would_affect']['snapshots_truncated'], $cap );
				$this->assertSame( 'cloud_copies' === $cap, $preview['would_affect']['cloud_copies_truncated'], $cap );
				$this->assertCount( 'snapshots' === $cap ? 0 : 500, $preview['would_affect']['cloud_copies'], $cap );

				$again = $gate->check( $retry_context, $ability, $args );
				$this->assertSame( array( 'refused', 'backup_preview_capped' ), array( $again['decision'], $again['reason'] ), $cap . ' retry: ' . wp_json_encode( $again ) );
				$this->assertSame( 'stale', $this->store->get_approval( $retry['approval_id'] )['status'], $cap );
				$this->assertSame(
					array(
						'outcome' => 'refused',
						'reason'  => 'backup_preview_capped',
					),
					$gate->page_decide( $page['approval_id'], $this->user_id, 'run' ),
					$cap . ' page Run'
				);
				$this->assertSame( 'stale', $this->store->get_approval( $page['approval_id'] )['status'], $cap );
				$this->assertIsObject( MainWP_DB_Backup::instance()->get_backup_task_by_id( $task_id ), $cap );
			}
			$this->assertSame( array(), $confirmed, 'nothing ran once the preview was capped' );
		} finally {
			$this->remove_hooks( $hooks );
			$this->cloud_cleanup( $destinations );
			foreach ( $tasks as $task_id ) {
				$this->drop_backup_task( $task_id );
			}
		}
	}

	public function test_update_tools_do_not_publish_the_backup_gate_override() {
		$listed = (string) wp_json_encode( $this->services->get( 'tool_executor' )->list_tools( $this->context() ) );
		foreach ( array( 'run_updates_v1', 'update_all_v1', 'update_site_core_v1', 'update_site_plugins_v1', 'update_site_themes_v1', 'update_site_translations_v1' ) as $tool ) {
			$this->assertStringContainsString( '"' . $tool . '"', $listed );
		}
		$this->assertStringNotContainsString( 'ignore_backup_gate', $listed );
	}

	// The SDK that MCP clients use rejects the whole tools/list reply over one tool whose inputSchema type isn't exactly "object".
	public function test_every_listed_tool_publishes_an_object_input_schema() {
		$tools = $this->services->get( 'tool_executor' )->list_tools( $this->context() );
		$this->assertNotEmpty( $tools );
		$wrong = array();
		foreach ( $tools as $tool ) {
			if ( 'object' !== ( $tool['inputSchema']['type'] ?? null ) ) {
				$wrong[] = $tool['name'];
			}
		}
		$this->assertSame( array(), $wrong );
	}

	public function test_failed_preview_tells_the_client_why_for_an_outdated_child() {
		update_option( 'mainwp_module_log_settings', array( 'enabled' => 1 ) );
		update_option( 'mainwp_enableNativeBackups', 1 );
		$gate     = $this->gate_with_log_setting();
		$context  = $this->context();
		$decision = $gate->check(
			$context,
			'mainwp/create-backup-task-v1',
			array(
				'name'                => 'Old child',
				'type'                => 'full',
				'schedule'            => 'daily',
				'site_ids_or_domains' => array( $this->create_test_site( array( 'version' => '6.3' ) ) ),
			)
		);
		$this->assertSame( 'preview_failed', $decision['reason'], (string) wp_json_encode( $decision ) );
		$this->assertSame(
			MCP_Approval_Gate::refusal_text( 'preview_failed' ) . ' The MainWP Child plugin on this site is too old to run backups. Update MainWP Child on the site, then sync it to run backups again.',
			$gate->deliver( $context, $decision )['content'][0]['text']
		);
	}

	public function test_gate_refusal_wording_reaches_the_client() {
		$context = $this->context();
		$events  = array();
		update_option( 'mainwp_module_log_settings', array( 'enabled' => 0 ) );
		add_action(
			Remote_MCP_Events::AUDIT,
			static function ( $event, $fields ) use ( &$events ) {
				if ( 'mcp_call' === $event ) {
					$events[] = $fields;
				}
			},
			10,
			2
		);
		$gate     = $this->services->get( 'approval_gate' );
		$args     = array( 'name' => 'Blocked' );
		$decision = $gate->check( $context, 'mainwp/add-tag-v1', $args );
		$this->assertSame( 'refused', $decision['decision'] );
		$expected = $gate->deliver( $context, $decision );
		$result   = $this->services->get( 'tool_executor' )->call_tool( $context, 'add_tag_v1', $args );
		$this->assertTrue( $result['isError'] );
		$this->assertSame( $expected['content'][0]['text'], $result['content'][0]['text'], 'gate refusal text reaches the client' );
		$this->assertSame( 'activity_log_off', end( $events )['reason'] );
		$this->assertSame( 'refused', end( $events )['outcome'] );
		$this->assertSame( 'activity_log_off', $this->store->get_grant( $context->grant_id )['last_failure'] );

		update_option( 'mainwp_module_log_settings', array( 'enabled' => 1 ) );
		$gate     = $this->gate_with_log_setting();
		$args     = array( 'url' => 'https://private-user:private-password@child.example.org' );
		$decision = $gate->check( $context, 'mainwp/add-site-v1', $args );
		$this->assertSame( 'url_userinfo', $decision['reason'] );
		$result = $this->services->get( 'tool_executor' )->call_tool( $context, 'add_site_v1', $args );
		$this->assertSame( $gate->deliver( $context, $decision )['content'][0]['text'], $result['content'][0]['text'] );
		$this->assertStringNotContainsString( 'private-password', wp_json_encode( $result ) );
		$this->assertSame( 'url_userinfo', end( $events )['reason'] );
	}

	public function test_panel_directions_name_the_mcp_page() {
		$context = $this->context( 'standard', 'mainwp:read' );
		$result  = $this->services->get( 'tool_executor' )->call_tool( $context, 'add_tag_v1', array( 'name' => 'Step up' ) );
		$this->assertStringContainsString( 'in AI > MainWP MCP > Connect an app.', $result['content'][0]['text'], 'connection-token step-up location' );

		$minted = $this->store->mint_connection_token( $this->user_id, 'mainwp:read', 'standard', 'Expired', time() + DAY_IN_SECONDS );
		$this->assertSame( OAuth_Store::OK, $minted['outcome'] );
		global $wpdb;
		$wpdb->update( Remote_MCP_Install::table( 'tokens' ), array( 'expires_at' => time() - 1 ), array( 'grant_id' => $minted['grant_id'] ) );
		$server                       = $_SERVER;
		$server['HTTP_AUTHORIZATION'] = 'Bearer ' . $minted['token'];
		$auth = ( new MCP_Bearer_Auth( $this->services ) )->authenticate( new Remote_MCP_Request( 'route_mcp', 'POST', '', '', $server ) );
		$this->assertFalse( $auth['ok'] );
		$this->assertSame( 401, $auth['response']->status() );
		$this->assertStringContainsString( 'in AI > MainWP MCP > Connect an app.', $auth['response']->header( 'WWW-Authenticate' ), 'expired-token location' );

		wp_set_current_user( $this->user_id );
		$minted = $this->store->mint_connection_token( $this->user_id, 'mainwp:read', 'standard', 'Expiring', time() + 6 * DAY_IN_SECONDS );
		$this->assertSame( OAuth_Store::OK, $minted['outcome'] );
		$mail = array();
		add_filter(
			'pre_wp_mail',
			static function ( $sent, $args ) use ( &$mail ) {
				$mail[] = $args;
				return true;
			},
			10,
			2
		);
		$notices = new Remote_MCP_Notices( $this->services );
		$this->assertGreaterThanOrEqual( 1, $notices->send_expiry_reminders() );
		foreach ( $mail as $message ) {
			$this->assertStringContainsString( 'in AI > MainWP MCP > Connect an app:', $message['message'], 'reminder-email location' );
		}

		$server                 = $_SERVER;
		$server['CONTENT_TYPE'] = 'application/json';
		$server['REMOTE_ADDR']  = '192.0.2.45';
		$response = $this->services->get( 'route_register' )->handle(
			new Remote_MCP_Request( 'route_register', 'POST', '', '{"redirect_uris":["https://hosted.example.org/callback"]}', $server )
		);
		$this->assertSame( 400, $response->status() );
		$this->assertStringContainsString( 'in AI > MainWP MCP > Connect an app.', json_decode( $response->body(), true )['error_description'], 'registration-error location' );
	}

	public function test_webapp_redirect_error_explains_query_and_fragment_restrictions() {
		$admin = $this->services->get( 'hooks_admin' );
		foreach ( array( 'https://app.example.org/callback?state=1', 'https://app.example.org/callback#return' ) as $uri ) {
			$result = $admin->handle_post(
				array(
					'mainwp_remote_mcp_action' => 'webapp_add',
					'target'                   => '',
					'_wpnonce'                 => wp_create_nonce( Remote_MCP_Admin::nonce_action( 'webapp_add', '' ) ),
					'name'                     => 'Hosted app',
					'redirect_uris'            => $uri,
				)
			);
			$this->assertFalse( $result['ok'] );
			$this->assertSame( 'invalid_redirect', $result['code'] );
			$this->assertStringContainsString( 'query strings', $result['message'], 'redirect error explains query restriction' );
			$this->assertStringContainsString( 'fragments', $result['message'], 'redirect error explains fragment restriction' );
		}
		$this->assertSame( array(), $this->store->clients_by_kind( 'webapp' ) );
	}

	/**
	 * Authorize through the endpoint and the production client registry.
	 *
	 * @param array $overrides Request parameters.
	 * @return \MainWP\Dashboard\Remote_MCP\Remote_MCP_Response
	 */
	private function authorize_request( array $overrides = array() ) {
		wp_set_current_user( $this->user_id );
		$params = array_merge(
			array(
				'client_id'             => self::CLIENT_ID,
				'redirect_uri'          => self::REDIRECT,
				'response_type'         => 'code',
				'code_challenge'        => $this->challenge( self::VERIFIER ),
				'code_challenge_method' => 'S256',
				'resource'              => self::RESOURCE,
				'scope'                 => 'mainwp:read mainwp:write offline_access',
				'state'                 => 'integration-state',
			),
			$overrides
		);
		$query = http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
		return $this->services->get( 'route_authorize' )->handle( new Remote_MCP_Request( 'route_authorize', 'GET', $query, '', $_SERVER ) );
	}

	public function test_cursor_cloud_consent_does_not_claim_a_local_computer() {
		foreach ( array( 'https://www.cursor.com/agents/mcp/oauth/callback', 'http://localhost:8787/callback' ) as $redirect ) {
			$response = $this->authorize_request( array( 'client_id' => 'mainwp-cursor', 'redirect_uri' => $redirect ) );
			$this->assertSame( 200, $response->status(), $response->body() );
			$this->assertSame( 1, preg_match( '#<h1 class="mainwp-oauth-headline">(.*?)</h1>#s', $response->body(), $match ) );
			$headline = html_entity_decode( wp_strip_all_tags( $match[1] ), ENT_QUOTES );
			$this->assertStringNotContainsString( 'on your computer', $headline, 'Cursor consent must describe local and cloud callbacks accurately' );
			$this->assertSame( 'Cursor wants to connect', $headline );
			$text = html_entity_decode( $response->body(), ENT_QUOTES );
			$this->assertStringContainsString( "Or another program using Cursor's settings.", $text );
			$this->assertStringNotContainsString( 'on this computer', $text, 'Cursor may call back to its cloud' );
		}
	}

	/**
	 * Dispatch through the production front controller without emitting or exiting.
	 *
	 * @param string $uri Request path and query.
	 * @param string $method HTTP method.
	 * @param array $vars WordPress query variables.
	 * @param string $body Request body.
	 * @return \MainWP\Dashboard\Remote_MCP\Remote_MCP_Response|null
	 */
	private function dispatch_request( string $uri, string $method = 'GET', array $vars = array(), string $body = '' ) {
		$_SERVER['REQUEST_URI']    = $uri;
		$_SERVER['REQUEST_METHOD'] = $method;
		$_SERVER['QUERY_STRING']   = (string) wp_parse_url( $uri, PHP_URL_QUERY );
		$wp                       = new \WP();
		$wp->query_vars            = $vars;
		return ( new MCP_Front_Controller( $this->services ) )->dispatch(
			$wp,
			function () use ( $body ) {
				++$this->body_reads;
				return $body;
			}
		);
	}

	/**
	 * Real MCP request using the legacy protocol envelope.
	 *
	 * @param string $token Bearer token.
	 * @return \MainWP\Dashboard\Remote_MCP\Remote_MCP_Response
	 */
	private function ping( string $token ) {
		$server                        = $_SERVER;
		$server['HTTP_AUTHORIZATION']  = 'Bearer ' . $token;
		$server['HTTP_CONTENT_TYPE']   = 'application/json';
		$server['HTTP_MCP_PROTOCOL_VERSION'] = '2025-11-25';
		return $this->services->get( 'route_mcp' )->handle( new Remote_MCP_Request( 'route_mcp', 'POST', '', '{"jsonrpc":"2.0","id":1,"method":"ping"}', $server ) );
	}

	/**
	 * Queue real local work without executing an entry.
	 *
	 * @return int Job id.
	 */
	private function open_job(): int {
		$context = $this->context();
		$entries = array();
		foreach ( array( 'First blocked entry', 'Second blocked entry' ) as $name ) {
			$entries[] = array( 'ability' => 'mainwp/add-tag-v1', 'args' => array( 'name' => $name ), 'target_ids' => null );
		}
		$plan = Call_Plan::from_entries( $entries );
		$this->assertInstanceOf( Call_Plan::class, $plan );
		$job = $this->services->get( 'job_runner' )->enqueue( $context, $plan, null );
		$this->assertIsInt( $job );
		$this->assertSame( 'running', $this->store->get_job( $job )['status'] );
		$this->assertNotFalse( wp_next_scheduled( MCP_Job_Cron::HOOK, array( $job ) ) );
		return $job;
	}

	public function test_kill_switch_404s_approval_page_and_stops_jobs() {
		$job = $this->open_job();
		update_option( 'mainwp_module_log_settings', array( 'enabled' => 1 ) );
		$gate     = $this->gate_with_log_setting();
		$approval = $gate->check( $this->context( 'strict' ), 'mainwp/add-client-v1', array( 'name' => 'Pending approval' ) );
		$this->assertSame( 'approval_required', $approval['decision'] );
		$id = $approval['approval_id'];
		add_filter( 'mainwp_remote_mcp_force_disabled', '__return_true' );
		$this->assertFalse( Remote_MCP_Settings::is_active() );
		foreach ( array( '/%postname%/', '' ) as $permalinks ) {
			update_option( 'permalink_structure', $permalinks );
			$paths = array(
				array( '/mainwp-oauth/authorize', 'GET', array() ),
				array( '/mainwp-oauth/authorize', 'POST', array() ),
				array( '/mainwp-oauth/approve?approval=' . $id, 'GET', array() ),
				array( '/mainwp-oauth/approve?approval=' . $id, 'POST', array() ),
			);
			if ( '' === $permalinks ) {
				$paths = array(
					array( '/?mainwp_oauth=authorize', 'GET', array() ),
					array( '/?mainwp_oauth=authorize', 'POST', array() ),
					array( '/?mainwp_oauth=approve&approval=' . $id, 'GET', array() ),
					array( '/?mainwp_oauth=approve&approval=' . $id, 'POST', array() ),
				);
			}
			foreach ( array( Remote_MCP_Urls::ROUTE_MCP => 'POST', Remote_MCP_Urls::ROUTE_TOKEN => 'POST', Remote_MCP_Urls::ROUTE_REGISTER => 'POST', Remote_MCP_Urls::ROUTE_PRM => 'GET' ) as $route => $method ) {
				$paths[] = array( '' === $permalinks ? '/?rest_route=' . $route : '/wp-json' . $route, $method, array( 'rest_route' => $route ) );
			}
			foreach ( $paths as list( $uri, $method, $vars ) ) {
				$response = $this->dispatch_request( $uri, $method, $vars, '{invalid body' );
				$this->assertNotNull( $response, $uri );
				$this->assertSame( 404, $response->status(), $uri );
				$this->assertNull( $response->header( 'WWW-Authenticate' ), $uri );
			}
		}
		$this->assertSame( 0, $this->body_reads, 'kill switch precedes body parsing' );
		$GLOBALS['wp_rewrite']->set_permalink_structure( '/%postname%/' );
		$GLOBALS['wp_rewrite']->flush_rules( false );
		foreach ( array( '/.well-known/oauth-authorization-server', '/.well-known/openid-configuration' ) as $uri ) {
			$this->assertNull( $this->dispatch_request( $uri ), 'shared well-known paths remain available to other handlers' );
			$this->go_to( 'https://dash.example.org' . $uri );
			$this->assertTrue( is_404(), 'WordPress returns 404 when no other handler owns ' . $uri );
		}
		$this->assertSame( 404, $this->page_post( $id, 'approve' )->status(), 'direct page POST is also disabled' );
		$this->assertSame( 'pending', $this->store->get_approval( $id )['status'] );
		$this->assertFalse( $this->services->get( 'job_runner' )->tick( $job ) );
		$this->assertSame( 'cancelled', $this->store->get_job( $job )['status'] );
		$this->assertSame( 'kill_switch', $this->store->get_job( $job )['last_error'] );
		$this->assertSame( 0, (int) $this->store->get_job( $job )['next_entry'] );
	}

	public function test_forced_off_handlers_refuse_tokens_without_lookup_or_grant_write() {
		$connection = $this->connect();
		$minted     = $this->store->mint_connection_token( $this->user_id, 'mainwp:read', 'standard', 'Local app', time() + DAY_IN_SECONDS );
		$this->assertSame( OAuth_Store::OK, $minted['outcome'] );
		$before  = $this->rows( 'grants' );
		$queries = 0;
		$watch   = static function ( $sql ) use ( &$queries ) {
			foreach ( Remote_MCP_Install::all_tables() as $name ) {
				if ( false !== strpos( $sql, Remote_MCP_Install::table( $name ) ) ) {
					++$queries;
					break;
				}
			}
			return $sql;
		};
		add_filter( 'mainwp_remote_mcp_force_disabled', '__return_true' );
		add_filter( 'query', $watch );
		foreach ( array( $connection['access_token'], $minted['token'] ) as $token ) {
			$response = $this->ping( $token );
			$this->assertSame( 401, $response->status() );
			$this->assertStringContainsString( 'invalid_token', $response->header( 'WWW-Authenticate' ) );
		}
		$body     = http_build_query( array( 'grant_type' => 'refresh_token', 'client_id' => self::CLIENT_ID, 'refresh_token' => $connection['refresh_token'] ), '', '&', PHP_QUERY_RFC3986 );
		$response = $this->services->get( 'route_token' )->handle( new Remote_MCP_Request( 'route_token', 'POST', '', $body, $_SERVER ) );
		$this->assertSame( 400, $response->status() );
		$this->assertSame( 'invalid_request', json_decode( $response->body(), true )['error'] );
		remove_filter( 'query', $watch );
		$this->assertSame( 0, $queries, 'disabled handlers perform no module-table lookup or write' );
		$this->assertSame( $before, $this->rows( 'grants' ), 'no grant write' );
	}

	public function test_forced_off_keeps_grants_and_secret_and_lifting_restores_connections() {
		$connection = $this->connect();
		$minted     = $this->store->mint_connection_token( $this->user_id, 'mainwp:read', 'standard', 'Local app', time() + DAY_IN_SECONDS );
		$this->assertSame( OAuth_Store::OK, $minted['outcome'] );
		foreach ( array( $connection['access_token'], $minted['token'] ) as $token ) {
			$this->assertSame( 200, $this->ping( $token )->status() );
		}
		$grants = $this->rows( 'grants' );
		$tokens = $this->rows( 'tokens' );
		$secret = hash( 'sha256', wp_json_encode( get_option( Remote_MCP_Settings::OPTION_SECRET ) ) );
		add_filter( 'mainwp_remote_mcp_force_disabled', '__return_true' );
		$this->assertSame( 404, $this->dispatch_request( '/wp-json' . Remote_MCP_Urls::ROUTE_MCP, 'POST', array( 'rest_route' => Remote_MCP_Urls::ROUTE_MCP ) )->status() );
		$this->assertTrue( Remote_MCP_Settings::enabled(), 'stored toggle stays on' );
		$this->assertSame( $grants, $this->rows( 'grants' ) );
		$this->assertSame( $tokens, $this->rows( 'tokens' ) );
		$this->assertTrue( hash_equals( $secret, hash( 'sha256', wp_json_encode( get_option( Remote_MCP_Settings::OPTION_SECRET ) ) ) ), 'module secret stays unchanged' );
		remove_filter( 'mainwp_remote_mcp_force_disabled', '__return_true' );
		$this->assertTrue( Remote_MCP_Settings::is_active() );
		foreach ( array( $connection['access_token'], $minted['token'] ) as $token ) {
			$this->assertSame( 200, $this->ping( $token )->status(), 'same connection works after lifting the switch' );
		}
	}

	public function test_kill_switch_cancels_running_job_and_lifting_it_resumes_nothing() {
		$job = $this->open_job();
		remove_all_actions( MCP_Job_Cron::HOOK );
		$this->services->get( 'hooks_jobs' )->register_hooks( $this->services );
		add_filter( 'mainwp_remote_mcp_force_disabled', '__return_true' );
		do_action( 'init' );
		$this->assertNotFalse( wp_next_scheduled( MCP_Job_Cron::HOOK, array( $job ) ), 'a disabled init must retain the cancelling tick' );
		do_action( MCP_Job_Cron::HOOK, $job );
		$cancelled = $this->store->get_job( $job );
		$this->assertSame( 'cancelled', $cancelled['status'] );
		$this->assertSame( 'kill_switch', $cancelled['last_error'] );
		$this->assertSame( 0, (int) $cancelled['next_entry'], 'no entry ran' );
		$this->assertFalse( wp_next_scheduled( MCP_Job_Cron::HOOK, array( $job ) ) );
		remove_filter( 'mainwp_remote_mcp_force_disabled', '__return_true' );
		do_action( 'init' );
		$this->assertTrue( Remote_MCP_Settings::is_active() );
		$this->assertFalse( $this->services->get( 'job_runner' )->tick( $job ) );
		$this->assertSame( $cancelled, $this->store->get_job( $job ), 'lifting the switch resumes no cancelled work' );
		$this->assertFalse( wp_next_scheduled( MCP_Job_Cron::HOOK, array( $job ) ) );
	}

	/**
	 * Disabling registration must keep bundled consent working without fetching unknown apps.
	 */
	public function test_authorization_stays_offline_for_bundled_and_registration_disabled_clients() {
		Remote_MCP_Settings::set_registration_enabled( false );
		$calls = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$calls ) {
				++$calls;
				return new \WP_Error( 'unexpected_outbound', 'This authorization must not fetch.' );
			}
		);
		$response = $this->authorize_request();
		$this->assertSame( 200, $response->status() );
		$this->assertSame( 1, preg_match( '/name="request_key" value="([^"]+)"/', $response->body(), $key ) );
		$this->assertSame( 1, preg_match( '/name="_wpnonce" value="([^"]+)"/', $response->body(), $nonce ) );
		$body = http_build_query( array( 'request_key' => html_entity_decode( $key[1] ), '_wpnonce' => html_entity_decode( $nonce[1] ), 'decision' => 'approve', 'access' => 'write', 'policy' => 'standard' ), '', '&', PHP_QUERY_RFC3986 );
		$approved = $this->services->get( 'route_authorize' )->handle( new Remote_MCP_Request( 'route_authorize', 'POST', '', $body, $_SERVER ) );
		$this->assertSame( 302, $approved->status() );
		parse_str( wp_parse_url( $approved->header( 'Location' ), PHP_URL_QUERY ), $params );
		$this->assertSame( 'integration-state', $params['state'] );
		$this->assertArrayHasKey( 'code', $params );
		$this->assertSame( 'approved', $this->store->find_request( html_entity_decode( $key[1] ) )['status'] );

		$unknown = 'https://unknown-client.example.org/oauth-client.json';
		$this->assertSame( 400, $this->authorize_request( array( 'client_id' => $unknown ) )->status() );
		$request = $this->store->create_request( $this->request_params( $this->user_id, array( 'client_id' => $unknown ) ) );
		$this->assertIsArray( $request );
		$body = http_build_query( array( 'request_key' => $request['request_key'], '_wpnonce' => wp_create_nonce( 'mainwp_oauth_consent_' . $request['request_key'] ), 'decision' => 'approve', 'access' => 'write', 'policy' => 'standard' ), '', '&', PHP_QUERY_RFC3986 );
		$refused = $this->services->get( 'route_authorize' )->handle( new Remote_MCP_Request( 'route_authorize', 'POST', '', $body, $_SERVER ) );
		$this->assertSame( 400, $refused->status() );
		$this->assertNull( $refused->header( 'Location' ) );
		$this->assertSame( 'failed', $this->store->find_request( $request['request_key'] )['status'], 'unknown client consent fails closed' );
		$this->assertSame( 0, $calls, 'bundled and registration-disabled authorization and consent stay offline' );
	}


	public function test_annotation_reads_need_the_log_but_audited_reads_do_not() {
		$category   = 'remote-mcp-integration';
		$annotated  = 'mainwp/integration-annotated-read-v1';
		$audited    = 'mainwp/get-site-cache-control-status-v1';
		$runs       = array( $annotated => 0, $audited => 0 );
		$categories = \WP_Ability_Categories_Registry::get_instance();
		$categories->register( $category, array( 'label' => 'Integration', 'description' => 'Integration test abilities.' ) );
		$registry = \WP_Abilities_Registry::get_instance();
		try {
			foreach ( array( $annotated, $audited ) as $ability ) {
				$this->assertFalse( wp_has_ability( $ability ) );
				$registered = $registry->register(
					$ability,
					array(
						'label'               => 'Integration read',
						'description'         => 'Read integration state.',
						'category'            => $category,
						'input_schema'        => array( 'type' => 'object', 'properties' => new \stdClass() ),
						'permission_callback' => static function () {
							return current_user_can( 'manage_options' );
						},
						'execute_callback'    => static function () use ( &$runs, $ability ) {
							++$runs[ $ability ];
							return array( 'ran' => $ability );
						},
						'meta'                => array(
							'show_in_rest' => true,
							'annotations'  => array( 'readonly' => true, 'destructive' => false ),
						),
					)
				);
				$this->assertInstanceOf( \WP_Ability::class, $registered );
			}
			$context = $this->context();
			$gate    = $this->gate_with_log_setting();
			$tools   = $this->services->get( 'tool_executor' );
			$tiers   = $this->services->get( 'tier_registry' );
			$this->assertSame( 'annotations', $tiers->entry( $annotated )['source'] );
			$this->assertSame( 'audit', $tiers->entry( $audited )['source'] );
			$this->assertSame( 'core', $tiers->entry( 'mainwp/list-tags-v1' )['source'] );
			update_option( 'mainwp_module_log_settings', array( 'enabled' => 0 ) );
			$decision = $gate->check( $context, $annotated, array() );
			$this->assertSame( 'refused', $decision['decision'] );
			$this->assertSame( 'activity_log_off', $decision['reason'] );
			$refused = $tools->call_tool( $context, 'integration_annotated_read_v1', array() );
			$this->assertTrue( $refused['isError'] );
			$this->assertSame( "Changes and tools MainWP hasn't reviewed need the MainWP activity log, so each call is recorded. Turn on Enable Network Activity logging under MainWP Settings > Network Activity Settings.", $refused['content'][0]['text'] );
			$this->assertSame( 0, $runs[ $annotated ], 'a refused read never runs' );
			$this->assertFalse( $tools->call_tool( $context, 'get_site_cache_control_status_v1', array() )['isError'] );
			$this->assertSame( 1, $runs[ $audited ] );
			$core = $tools->call_tool( $context, 'list_tags_v1', array() );
			$this->assertFalse( $core['isError'] );
			$this->assertArrayHasKey( 'items', $core['structuredContent'] );

			update_option( 'mainwp_module_log_settings', array( 'enabled' => 1 ) );
			$this->assertFalse( $tools->call_tool( $context, 'integration_annotated_read_v1', array() )['isError'] );
			$this->assertSame( 1, $runs[ $annotated ] );
			$this->assertFalse( $tools->call_tool( $context, 'get_site_cache_control_status_v1', array() )['isError'] );
			$this->assertSame( 2, $runs[ $audited ] );
			$core = $tools->call_tool( $context, 'list_tags_v1', array() );
			$this->assertFalse( $core['isError'] );
			$this->assertArrayHasKey( 'items', $core['structuredContent'] );
		} finally {
			foreach ( array( $annotated, $audited ) as $ability ) {
				if ( wp_has_ability( $ability ) ) {
					wp_unregister_ability( $ability );
				}
			}
			$categories->unregister( $category );
		}
	}

	public function test_sites_resolver_reads_site_ids_and_exclude_ids_like_the_ability() {
		$ids = array();
		foreach ( array( 'one', 'two', 'three' ) as $name ) {
			$ids[] = (int) \MainWP\Dashboard\MainWP_DB::instance()->add_website(
				$this->user_id,
				$name,
				'https://' . $name . '.example/',
				'admin',
				base64_encode( 'fake-pub-' . wp_generate_uuid4() ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test key material.
				base64_encode( 'fake-priv-' . wp_generate_uuid4() ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- test key material.
				array(
					'http_user' => '',
					'http_pass' => '',
				)
			);
		}
		$resolver = $this->services->get( 'target_resolver' );
		$context  = $this->context();
		$one      = $resolver->resolve( $context, 'mainwp/sync-sites-v1', array( 'site_ids' => array( $ids[0] ) ) );
		$this->assertSame( array( $ids[0] ), $one['site_ids'], 'site_ids selects that site, never every site' );
		$rest = $resolver->resolve( $context, 'mainwp/sync-sites-v1', array( 'exclude_ids' => array( $ids[1] ) ) );
		$this->assertNotContains( $ids[1], $rest['site_ids'], 'exclude_ids removes a site from "every site"' );
		$this->assertContains( $ids[0], $rest['site_ids'] );
		$this->assertContains( $ids[2], $rest['site_ids'] );
		$listed = $resolver->resolve(
			$context,
			'mainwp/sync-sites-v1',
			array(
				'site_ids_or_domains' => array( $ids[0], $ids[1] ),
				'exclude_ids'         => array( $ids[1] ),
			)
		);
		$this->assertSame( array( $ids[0] ), $listed['site_ids'], 'and from an explicit list' );
		$selected = $resolver->resolve(
			$context,
			'mainwp/sync-sites-v1',
			array(
				'site_ids'    => array( $ids[0], $ids[1] ),
				'exclude_ids' => array( $ids[1] ),
			)
		);
		$this->assertSame( array( $ids[0] ), $selected['site_ids'], 'exclusions also apply to site_ids' );
		$this->assertNull(
			$resolver->resolve(
				$context,
				'mainwp/sync-sites-v1',
				array(
					'site_ids'    => array( $ids[0] ),
					'exclude_ids' => array( $ids[0] ),
				)
			),
			'excluding every selected site refuses the call'
		);
	}

	public function test_sites_plan_keeps_only_the_pinned_selector() {
		$id        = (int) $this->create_test_site( array( 'userid' => $this->user_id ) );
		$context   = $this->context();
		$arguments = array(
			'site_ids_or_domains' => array( $id ),
			'site_ids'            => array( 0 ),
			'exclude_ids'         => array( $id + 1 ),
		);
		$targets   = $this->services->get( 'target_resolver' )->resolve( $context, 'mainwp/sync-sites-v1', $arguments );
		$this->assertSame( array( $id ), $targets['site_ids'] );
		$plan = $this->services->get( 'execution_adapter' )->build( $context, 'mainwp/sync-sites-v1', $arguments, $targets, null );
		$this->assertInstanceOf( Call_Plan::class, $plan );
		$this->assertCount( 1, $plan->entries() );
		$this->assertSame( array( 'site_ids_or_domains' => array( $id ) ), $plan->entries()[0]['args'] );
	}

}
