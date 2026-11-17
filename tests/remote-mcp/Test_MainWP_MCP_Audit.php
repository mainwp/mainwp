<?php // phpcs:disable WordPress.Files.FileName -- test doubles live beside their test class, not in class-*.php files.
/**
 * Remote MCP audit writer tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\Connector_Remote_MCP;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Audit;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Events;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Request;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Approval_Page;

require_once __DIR__ . '/fixtures/approval-gate-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Generic.Files.OneObjectStructurePerFile.MultipleFound

// The suite runs with the logs module off, so its connector base class is loaded here.
if ( ! class_exists( '\MainWP\Dashboard\Module\Log\Log_Connector' ) ) {
	require_once dirname( __DIR__, 2 ) . '/modules/logs/classes/class-log-connector.php';
}
if ( ! class_exists( Connector_Remote_MCP::class, false ) && file_exists( dirname( __DIR__, 2 ) . '/modules/remote-mcp/class-connector-remote-mcp.php' ) ) {
	require_once dirname( __DIR__, 2 ) . '/modules/remote-mcp/class-connector-remote-mcp.php';
}

if ( class_exists( Connector_Remote_MCP::class, false ) ) {
	/**
	 * The module's connector with the log write captured instead of stored.
	 */
	class Audit_Capturing_Connector extends Connector_Remote_MCP {
		/**
		 * Rows written.
		 *
		 * @var array
		 */
		public $rows = array();

		/**
		 * Capture a log row.
		 *
		 * @param string   $message Message.
		 * @param array    $args    Args.
		 * @param int      $site_id Site.
		 * @param string   $context Context.
		 * @param string   $action  Action.
		 * @param int|null $state   State.
		 * @param int|null $user_id User.
		 * @return bool
		 */
		public function log( $message, $args, $site_id, $context, $action, $state = null, $user_id = null ) {
			$this->rows[] = compact( 'message', 'args', 'site_id', 'context', 'action', 'state', 'user_id' );
			return true;
		}
	}
}

/**
 * Class Test_MainWP_MCP_Audit
 */
class Test_MainWP_MCP_Audit extends \WP_UnitTestCase {

	use Remote_MCP_Store_Fixtures, Approval_Gate_Fixtures {
		Approval_Gate_Fixtures::set_up insteadof Remote_MCP_Store_Fixtures;
		Remote_MCP_Store_Fixtures::set_up as store_set_up;
		Approval_Gate_Fixtures::tear_down as gate_tear_down;
	}

	const RESOURCE  = 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp';
	const CLIENT_ID = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
	const REDIRECT  = 'https://claude.ai/api/mcp/auth_callback';
	const VERIFIER  = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz';

	/**
	 * Connector capturing rows.
	 *
	 * @var Audit_Capturing_Connector
	 */
	private $connector;

	/**
	 * Saved globals.
	 *
	 * @var array
	 */
	private $saved = array();

	/**
	 * Audit writer hooked to the module event, writing through the capturing connector.
	 *
	 * @return Remote_MCP_Audit
	 */
	private function writer(): Remote_MCP_Audit {
		$this->connector = new Audit_Capturing_Connector( null );
		$audit           = new Remote_MCP_Audit( $this->services, $this->connector );
		$audit->register_hooks( $this->services );
		return $audit;
	}

	public function tear_down() {
		if ( $this->saved ) {
			$_SERVER = $this->saved['server'];
			$_COOKIE = $this->saved['cookie'];
		}
		$this->gate_tear_down();
	}

	public function test_tool_call_logs_user_client_host_and_grant_id() {
		$this->writer();
		Remote_MCP_Events::audit(
			'mcp_call',
			array(
				'user_id'     => 7,
				'client_host' => 'claude.ai',
				'grant_id'    => 42,
				'tool'        => 'delete_site_v1',
				'ability'     => 'mainwp/delete-site-v1',
				'outcome'     => 'ok',
				'duration_ms' => 12,
			)
		);
		$this->assertCount( 1, $this->connector->rows );
		$row = $this->connector->rows[0];
		$this->assertSame( 'mcp_call', $row['action'] );
		$this->assertSame( 'remote_mcp', $row['context'] );
		$this->assertSame( 7, $row['user_id'] );
		$this->assertSame( 'claude.ai', $row['args']['client_host'] );
		$this->assertSame( 42, $row['args']['grant_id'] );
		$this->assertSame( 'delete_site_v1', $row['args']['tool'] );

		// The gate's own events carry the same identity.
		$ctx = $this->ctx();
		$this->ability( 'mainwp-test/delete-thing-v1', array( 'site_ids' => array( 'type' => 'array' ) ) );
		$this->entry( 'mainwp-test/delete-thing-v1', 'dashboard', array( 'resolver' => 'sites' ) );
		$this->call( $ctx, 'mainwp-test/delete-thing-v1', array( 'site_ids' => array( 3 ) ) );
		$gate = end( $this->connector->rows );
		$this->assertSame( 'approval_required', $gate['action'] );
		$this->assertSame( $this->user_id, $gate['user_id'] );
		$this->assertSame( $ctx->grant_id, $gate['args']['grant_id'] );
		$this->assertSame( 'claude.ai', $gate['args']['client_host'] );
		$this->assertSame( 'mainwp-test/delete-thing-v1', $gate['args']['ability'] );
		$this->assertSame( 'dashboard', $gate['args']['tier'] );
		$this->assertSame( '3', $gate['args']['site_ids'], 'site ids as text, the one argument value the log keeps' );
		$this->assertIsInt( $gate['args']['approval_id'] );
	}

	public function test_audit_entry_never_contains_arguments_or_results() {
		$this->writer();
		$ctx = $this->ctx();
		$this->ability(
			'mainwp-test/suspend-thing-v1',
			array(
				'site_ids' => array( 'type' => 'array' ),
				'note'     => array( 'type' => 'string' ),
				'dry_run'  => array( 'type' => 'boolean' ),
			),
			array(
				'result' => function () {
					return array( 'message' => 'RESULT-MARKER' );
				},
			)
		);
		$this->entry( 'mainwp-test/suspend-thing-v1', 'confirm', array( 'resolver' => 'sites' ) );
		$args  = array(
			'site_ids' => array( 3 ),
			'note'     => 'ARGUMENT-MARKER',
		);
		$token = $this->structured( $this->call( $ctx, 'mainwp-test/suspend-thing-v1', $args )['result'] )['confirmation_token'];
		$this->call(
			$ctx,
			'mainwp-test/suspend-thing-v1',
			$args + array(
				'user_confirmed'     => true,
				'confirmation_token' => $token,
			)
		);
		$emitted = (string) wp_json_encode( $this->audit );

		// A direct emitter can't smuggle values either.
		do_action(
			Remote_MCP_Events::AUDIT,
			'confirmed_chat',
			array(
				'user_id'   => 1,
				'arguments' => 'ARGUMENT-MARKER',
				'result'    => 'RESULT-MARKER',
				'tool'      => array( 'ARGUMENT-MARKER' ),
			)
		);
		$this->assertNotEmpty( $this->connector->rows );
		$logged = (string) wp_json_encode( $this->connector->rows ) . $emitted;
		foreach ( array( 'ARGUMENT-MARKER', 'RESULT-MARKER', $token ) as $value ) {
			$this->assertStringNotContainsString( $value, $logged );
		}
	}

	public function test_calls_refused_when_activity_log_disabled() {
		if ( ! defined( Gate_Test_Log_Settings::LOG_MODULE_CONSTANT ) ) {
			define( Gate_Test_Log_Settings::LOG_MODULE_CONSTANT, true );
		}
		update_option( 'mainwp_module_log_settings', array( 'enabled' => 1 ) );
		$this->gate                             = new \MainWP\Dashboard\Remote_MCP\MCP_Approval_Gate( $this->services, array( Gate_Test_Log_Settings::class, 'activity_log_available' ) );
		$this->services->slots['approval_gate'] = $this->gate;
		$this->assertTrue( Gate_Test_Log_Settings::activity_log_available() );
		$ctx = $this->ctx();
		foreach ( array( 'read', 'write', 'confirm', 'dashboard' ) as $tier ) {
			$name = 'mainwp-test/' . $tier . '-thing-v1';
			$this->ability(
				$name,
				array(
					'site_ids' => array( 'type' => 'array' ),
					'dry_run'  => array( 'type' => 'boolean' ),
				)
			);
			$this->entry( $name, $tier, array( 'resolver' => 'sites' ) );
		}
		$args    = array( 'site_ids' => array( 3 ) );
		$pending = (int) $this->call( $ctx, 'mainwp-test/dashboard-thing-v1', $args )['decision']['approval_id'];
		$ready   = (int) $this->call( $ctx, 'mainwp-test/dashboard-thing-v1', array( 'site_ids' => array( 4 ) ) )['decision']['approval_id'];
		$this->approve( $ready );
		$this->calls = array();

		update_option( 'mainwp_module_log_settings', array( 'enabled' => 0 ) );
		$this->assertFalse( Gate_Test_Log_Settings::activity_log_available() );
		foreach ( array( 'write', 'confirm', 'dashboard' ) as $tier ) {
			$decision = $this->gate->check( $ctx, 'mainwp-test/' . $tier . '-thing-v1', $args );
			$this->assertSame( 'refused', $decision['decision'], $tier );
			$this->assertSame( 'activity_log_off', $decision['reason'], $tier );
		}
		$this->assertCount( 2, $this->approvals( $ctx->grant_id ), 'no row is created' );

		// Approve and "Run it here instead" on the page refuse too, and run nothing.
		$this->saved                 = array(
			'server' => $_SERVER,
			'cookie' => $_COOKIE,
		);
		$_SERVER['HTTPS']            = 'on';
		$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $this->user_id, time() + HOUR_IN_SECONDS, 'logged_in' );
		wp_set_current_user( $this->user_id );
		$approve_nonce = wp_create_nonce( Remote_MCP_Approval_Page::nonce_action( $pending, 'approve' ) );
		$run_nonce     = wp_create_nonce( Remote_MCP_Approval_Page::nonce_action( $ready, 'run' ) );
		wp_set_current_user( 0 );
		$page = new Remote_MCP_Approval_Page( $this->services );
		$page->handle( new Remote_MCP_Request( 'route_approve', 'POST', 'approval=' . $pending, 'approval=' . $pending . '&decision=approve&_wpnonce=' . $approve_nonce, $_SERVER ) );
		$page->handle( new Remote_MCP_Request( 'route_approve', 'POST', 'approval=' . $ready, 'approval=' . $ready . '&decision=run&_wpnonce=' . $run_nonce, $_SERVER ) );
		$this->assertSame( 'pending', $this->store->get_approval( $pending )['status'] );
		$this->assertSame( 'approved', $this->store->get_approval( $ready )['status'] );
		$this->assertSame( 'activity_log_off', $this->gate->page_decide( $ready, $this->user_id, 'run' )['reason'] );
		$this->assertSame( array(), $this->calls );
		$this->assertSame( array(), $this->executor->calls );
		foreach ( array( array(), 'enabled', null ) as $settings ) {
			if ( null === $settings ) {
				delete_option( 'mainwp_module_log_settings' );
			} else {
				update_option( 'mainwp_module_log_settings', $settings );
			}
			$this->assertSame( 'run', $this->gate->check( $ctx, 'mainwp-test/read-thing-v1', $args )['decision'] );
		}
		update_option( 'mainwp_module_log_settings', array( 'enabled' => 1 ) );
		$this->assertSame( 'run', $this->call( $ctx, 'mainwp-test/read-thing-v1', $args )['decision']['decision'], 'settings are read again on the next call' );
	}

	public function test_audit_writer_records_every_enum_event_and_rejects_unknown() {
		$this->writer();
		$fields = array(
			'user_id'       => 3,
			'grant_id'      => 9,
			'ability'       => 'mainwp/delete-site-v1',
			'policy_before' => 'strict',
			'policy_after'  => 'standard',
			'scope_before'  => 'mainwp:read mainwp:write',
			'scope_after'   => 'mainwp:read',
			'site_ids'      => array( 4, 5 ),
		);
		foreach ( Remote_MCP_Events::AUDIT_EVENTS as $event ) {
			$this->assertTrue( Remote_MCP_Events::audit( $event, $fields ) );
		}
		$this->assertSame( Remote_MCP_Events::AUDIT_EVENTS, array_column( $this->connector->rows, 'action' ) );
		foreach ( $this->connector->rows as $row ) {
			foreach ( array( 'policy_before', 'policy_after', 'scope_before', 'scope_after', 'ability', 'grant_id' ) as $field ) {
				$this->assertSame( $fields[ $field ], $row['args'][ $field ], $row['action'] . ' keeps ' . $field );
			}
			$this->assertSame( '4,5', $row['args']['site_ids'] );
			$this->assertSame( 3, $row['user_id'] );
		}

		$count = count( $this->connector->rows );
		$this->assertFalse( Remote_MCP_Events::audit( 'made_up_event', $fields ) );
		do_action( Remote_MCP_Events::AUDIT, 'made_up_event', $fields );
		do_action( Remote_MCP_Events::AUDIT, array( 'mcp_call' ), $fields );
		$this->assertCount( $count, $this->connector->rows, 'unknown events are never written' );

		do_action(
			Remote_MCP_Events::AUDIT,
			'mcp_call',
			array(
				'user_id'      => 3,
				'policy_after' => 'anything',
				'raw_args'     => 'x',
			)
		);
		$last = end( $this->connector->rows );
		$this->assertSame( 'mcp_call', $last['action'] );
		$this->assertArrayNotHasKey( 'policy_after', $last['args'], 'a malformed typed field is dropped' );
		$this->assertArrayNotHasKey( 'raw_args', $last['args'] );

		$labels = $this->connector->get_action_labels();
		foreach ( Remote_MCP_Events::AUDIT_EVENTS as $event ) {
			$this->assertArrayHasKey( $event, $labels );
		}
		$this->assertSame( 'remote_mcp', $this->connector->name );
	}
}
