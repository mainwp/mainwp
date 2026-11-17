<?php
/**
 * Remote MCP fail-closed stub tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\Call_Plan;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Context;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Request;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Services;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Stubs;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Generic.Files.OneObjectStructurePerFile.MultipleFound

if ( class_exists( Remote_MCP_Services::class ) && ! ( new \ReflectionClass( Remote_MCP_Services::class ) )->isFinal() ) {

	/**
	 * Registry with three test-only slots whose designated files live in fixtures/.
	 */
	class Remote_MCP_Test_Probe_Services extends Remote_MCP_Services {

		const SLOTS = parent::SLOTS + array(
			'probe_file'    => array( \MainWP\Dashboard\Remote_MCP\Approval_Gate::class, 'Remote_MCP_Test_Probe_Gate' ),
			'probe_outside' => array( \MainWP\Dashboard\Remote_MCP\Approval_Gate::class, 'Remote_MCP_Test_Outside_Gate' ),
			'probe_alias'   => array( \MainWP\Dashboard\Remote_MCP\Approval_Gate::class, 'Remote_MCP_Test_Alias_Gate' ),
		);

		/**
		 * Designated file of an implementing class, under fixtures/.
		 *
		 * @param string $short Implementing class short name.
		 * @return string
		 */
		protected function slot_file( string $short ): string {
			return __DIR__ . '/fixtures/' . strtolower( str_replace( '_', '-', $short ) ) . '.inc';
		}
	}
}

/**
 * Class Test_MainWP_Remote_MCP_Stubs
 */
class Test_MainWP_Remote_MCP_Stubs extends \WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( \MainWP\Dashboard\Remote_MCP\Remote_MCP_Install::class ) ) {
			$this->markTestSkipped( 'The remote MCP module is not loaded (it needs PHP 8.1+ on a single site).' );
		}
	}

	/**
	 * The most permissive connection there is: write scope under Hands-off.
	 *
	 * @return Remote_MCP_Context
	 */
	private function context(): Remote_MCP_Context {
		return new Remote_MCP_Context( 7, 1, 'https://claude.ai/oauth/claude-code-client-metadata', 'bundled', array( 'mainwp:read', 'mainwp:write' ), 'hands_off', 1, 'access' );
	}

	public function test_gate_stub_deliver_refuses_run_decision() {
		$context  = $this->context();
		$plan     = Call_Plan::from_entries(
			array(
				array(
					'ability'    => 'mainwp/delete-site-v1',
					'args'       => array( 'site_id_or_domain' => 3 ),
					'target_ids' => array( 3 ),
				),
			)
		);
		$decision = array(
			'decision'    => 'run',
			'tier'        => 'dashboard',
			'reason'      => null,
			'approval_id' => 9,
			'plan'        => $plan,
		);

		$result = Remote_MCP_Stubs::for_slot( 'approval_gate' )->deliver( $context, $decision );

		$this->assertTrue( $result['isError'], 'a run decision handed to the gate stub runs nothing' );
		$this->assertArrayNotHasKey( 'structuredContent', $result, 'no job handle' );
		$this->assertStringNotContainsString( 'JOB_STARTED', wp_json_encode( $result ) );
	}

	public function test_tier_registry_stub_entry_is_null_and_declared_contract_stub_is_null() {
		$this->assertSame( array( \MainWP\Dashboard\Remote_MCP\Tier_Registry::class, 'MCP_Tier_Registry' ), Remote_MCP_Services::SLOTS['tier_registry'] );
		$registry = Remote_MCP_Stubs::for_slot( 'tier_registry' );
		foreach ( array( 'mainwp/list-sites-v1', 'mainwp/delete-site-v1', 'mainwp/unknown-v1' ) as $ability ) {
			$this->assertNull( $registry->entry( $ability ), $ability );
			$this->assertNull( Remote_MCP_Stubs::for_slot( 'control_contract' )->declared_contract( $ability ), $ability );
		}
		$plain = new Remote_MCP_Services();
		if ( $plain->is_stub( 'tier_registry' ) ) {
			$this->assertNull( $plain->get( 'tier_registry' )->entry( 'mainwp/list-sites-v1' ) );
		}
	}

	public function test_tool_executor_stub_catalog_is_empty() {
		$this->assertSame( array(), Remote_MCP_Stubs::for_slot( 'tool_executor' )->catalog() );
		$plain = new Remote_MCP_Services();
		if ( $plain->is_stub( 'tool_executor' ) ) {
			$this->assertSame( array(), $plain->get( 'tool_executor' )->catalog() );
		}
	}

	public function test_control_contract_stub_refresh_native_token_is_null() {
		$plan = Call_Plan::from_entries(
			array(
				array(
					'ability'    => 'mainwp/team-control-delete-role-v1',
					'args'       => array( 'role_id' => 3, 'preview_token' => 'old' ),
					'target_ids' => null,
				),
			)
		);
		$contracts = Remote_MCP_Stubs::for_slot( 'control_contract' );

		$this->assertNull( $contracts->refresh_native_token( $plan, 0, 1 ) );
		$plain = new Remote_MCP_Services();
		if ( $plain->is_stub( 'control_contract' ) ) {
			$this->assertNull( $plain->get( 'control_contract' )->refresh_native_token( $plan, 0, 1 ) );
		}
	}

	public function test_job_runner_stub_status_denies() {
		$runner = Remote_MCP_Stubs::for_slot( 'job_runner' );

		$this->assertNull( $runner->status( 1, 1 ) );
		$this->assertNull( $runner->status( 0, 0 ) );
		$plain = new Remote_MCP_Services();
		if ( $plain->is_stub( 'job_runner' ) ) {
			$this->assertNull( $plain->get( 'job_runner' )->status( 1, 1 ) );
		}
	}

	public function test_known_clients_stub_validate_redirect_refuses() {
		$clients = Remote_MCP_Stubs::for_slot( 'known_clients' );

		foreach ( array( 'registered' => 'http://127.0.0.1:7777/callback', 'webapp' => 'https://chat.example.com/oauth/callback', 'static' => 'http://localhost:8787/callback', 'bundled' => 'http://localhost/callback' ) as $kind => $uri ) {
			$this->assertFalse( $clients->validate_redirect( $kind, $uri ), $kind );
		}
		$plain = new Remote_MCP_Services();
		if ( $plain->is_stub( 'known_clients' ) ) {
			$this->assertFalse( $plain->get( 'known_clients' )->validate_redirect( 'webapp', 'https://chat.example.com/oauth/callback' ) );
		}
	}

	public function test_plan_entry_executor_stub_refuses_execution() {
		$plan     = Call_Plan::from_entries(
			array(
				array(
					'ability'    => 'mainwp/run-updates-v1',
					'args'       => array(
						'site_ids_or_domains' => array( 3 ),
						'types'               => array( 'plugins' ),
						'specific_items'      => array( 'akismet' ),
						'confirm'             => true,
					),
					'target_ids' => array( 3 ),
				),
			)
		);
		$executor = Remote_MCP_Stubs::for_slot( 'tool_executor' );

		$result = $executor->execute_plan_entry( $plan, 0, 1, 7 );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'not_available', $result['reason'] );
		$this->assertNull( $result['result'], 'nothing ran' );
		$this->assertFalse( $result['queued'] );
		$plain = new Remote_MCP_Services();
		$this->assertSame( ! is_readable( MAINWP_PLUGIN_DIR . 'modules/remote-mcp/class-mcp-tool-executor.php' ), $plain->is_stub( 'tool_executor' ) );
		if ( $plain->is_stub( 'tool_executor' ) ) {
			$this->assertFalse( $plain->get( 'tool_executor' )->execute_plan_entry( $plan, 0, 1, 7 )['ok'] );
		}
	}

	public function test_slot_class_from_outside_its_designated_file_is_rejected() {
		$context = $this->context();

		// The real path: the class comes from its designated file and is used.
		$services = new Remote_MCP_Test_Probe_Services();
		$this->assertFalse( $services->is_stub( 'probe_file' ) );
		$this->assertSame( 'run', $services->get( 'probe_file' )->check( $context, 'mainwp/delete-site-v1', array() )['decision'] );

		// A correctly typed class an extension declared first under the slot's class name, while
		// the designated file exists: refused, and the designated file is never loaded over it.
		require_once __DIR__ . '/fixtures/external-outside-gate.inc';
		$outside = new \ReflectionClass( 'MainWP\\Dashboard\\Remote_MCP\\Remote_MCP_Test_Outside_Gate' );
		$this->assertTrue( $outside->implementsInterface( \MainWP\Dashboard\Remote_MCP\Approval_Gate::class ) );
		$this->assertTrue( $services->is_stub( 'probe_outside' ) );
		$this->assertSame( 'refused', $services->get( 'probe_outside' )->check( $context, 'mainwp/delete-site-v1', array() )['decision'] );

		// An alias of an extension's class under the slot's class name: refused.
		require_once __DIR__ . '/fixtures/external-alias-target.inc';
		if ( ! class_exists( 'MainWP\\Dashboard\\Remote_MCP\\Remote_MCP_Test_Alias_Gate', false ) ) {
			class_alias( 'MainWP\\Dashboard\\Remote_MCP\\Remote_MCP_Test_External_Gate', 'MainWP\\Dashboard\\Remote_MCP\\Remote_MCP_Test_Alias_Gate' );
		}
		$this->assertTrue( $services->is_stub( 'probe_alias' ) );
		$this->assertSame( 'refused', $services->get( 'probe_alias' )->check( $context, 'mainwp/delete-site-v1', array() )['decision'] );
	}

	public function test_every_stub_refuses_execution() {
		$context = $this->context();
		$plan    = Call_Plan::from_entries(
			array(
				array(
					'ability'    => 'mainwp/delete-site-v1',
					'args'       => array( 'site_id_or_domain' => 3 ),
					'target_ids' => array( 3 ),
				),
			)
		);
		$this->assertInstanceOf( Call_Plan::class, $plan );

		$gate     = Remote_MCP_Stubs::for_slot( 'approval_gate' );
		$decision = $gate->check( $context, 'mainwp/delete-site-v1', array( 'site_id_or_domain' => 3 ) );
		$this->assertSame( 'refused', $decision['decision'] );
		$this->assertSame( 'not_available', $decision['reason'] );
		$this->assertNull( $decision['plan'] );
		$this->assertNull(
			$gate->consume(
				$context,
				array(
					'decision'    => 'run',
					'approval_id' => 1,
					'tier'        => 'dashboard',
					'plan'        => $plan,
				)
			)
		);
		$this->assertTrue( $gate->deliver( $context, $decision )['isError'] );

		$executor = Remote_MCP_Stubs::for_slot( 'tool_executor' );
		$this->assertSame( array(), $executor->list_tools( $context ) );
		$this->assertTrue( $executor->call_tool( $context, 'delete_site_v1', array( 'site_id_or_domain' => 3 ) )['isError'] );

		$jobs = Remote_MCP_Stubs::for_slot( 'job_runner' );
		$this->assertNull( $jobs->enqueue( $context, $plan, null ) );
		$this->assertFalse( $jobs->tick( 1 ) );

		$this->assertNull( Remote_MCP_Stubs::for_slot( 'known_clients' )->lookup( 'https://claude.ai/oauth/claude-code-client-metadata' ) );
		$this->assertNull( Remote_MCP_Stubs::for_slot( 'known_clients' )->lookup( 'mainwp-connection-token' ) );
		$this->assertFalse( Remote_MCP_Stubs::for_slot( 'known_clients' )->redirect_allowed( array( 'client_id' => 'x' ), 'http://127.0.0.1/callback' ) );
		$this->assertSame( array(), Remote_MCP_Stubs::for_slot( 'scope_policy' )->effective_scopes( 'mainwp:read mainwp:write', 'mainwp:read mainwp:write' ) );
		$this->assertNull( Remote_MCP_Stubs::for_slot( 'scope_policy' )->effective_tier( $context, 'mainwp/list-sites-v1', array() ) );
		$this->assertNull( Remote_MCP_Stubs::for_slot( 'control_contract' )->contract_for( $context, 'mainwp/delete-site-v1' ) );
		$this->assertNull( Remote_MCP_Stubs::for_slot( 'target_resolver' )->resolve( $context, 'mainwp/delete-site-v1', array() ) );
		$this->assertNull( Remote_MCP_Stubs::for_slot( 'execution_adapter' )->build( $context, 'mainwp/delete-site-v1', array(), null, null ) );
		$this->assertNotSame( array(), Remote_MCP_Stubs::for_slot( 'enable_check' )->blocking_reasons() );

		$request = new Remote_MCP_Request( 'route_mcp', 'POST', '', '{}', array() );
		foreach ( Remote_MCP_Services::ROUTE_SLOTS as $slot ) {
			$this->assertGreaterThanOrEqual( 500, Remote_MCP_Stubs::for_slot( $slot )->handle( $request )->status(), $slot );
		}

		// Production wiring falls back to the stub for every slot whose implementing class isn't
		// present, and for an override that doesn't implement the slot's interface.
		$services = new Remote_MCP_Services( array( 'approval_gate' => new \stdClass() ) );
		$this->assertTrue( $services->is_stub( 'approval_gate' ) );
		$this->assertSame( 'refused', $services->get( 'approval_gate' )->check( $context, 'mainwp/delete-site-v1', array() )['decision'] );
		$plain = new Remote_MCP_Services();
		foreach ( Remote_MCP_Services::SLOTS as $slot => $definition ) {
			$slot_file = MAINWP_PLUGIN_DIR . 'modules/remote-mcp/class-' . strtolower( str_replace( '_', '-', $definition[1] ) ) . '.php';
			$this->assertSame( ! is_readable( $slot_file ), $plain->is_stub( $slot ), $slot );
			$this->assertInstanceOf( $definition[0], $plain->get( $slot ), $slot );
		}

		// A hook-provider stub registers nothing. Count callbacks, not hook names, so a callback
		// added to an existing hook such as init is caught too.
		$callbacks = function () {
			global $wp_filter;
			$count = 0;
			foreach ( $wp_filter as $hook ) {
				foreach ( $hook->callbacks as $priority ) {
					$count += count( $priority );
				}
			}
			return $count;
		};
		$before = $callbacks();
		Remote_MCP_Stubs::for_slot( 'hooks_admin' )->register_hooks( $plain );
		$this->assertSame( $before, $callbacks() );
	}
}
