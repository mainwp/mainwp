<?php
/**
 * Remote MCP kill switch tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\Remote_MCP;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings;

require_once __DIR__ . '/class-remote-mcp-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Generic.Files.OneObjectStructurePerFile.MultipleFound

if ( class_exists( Remote_MCP_Settings::class ) ) {

	/**
	 * Settings reading a test-only constant name, so the real constant path runs without defining
	 * MAINWP_DISABLE_REMOTE_MCP for the rest of the process.
	 */
	class Remote_MCP_Test_Kill_Settings extends Remote_MCP_Settings {
		const KILL_SWITCH_CONSTANT = 'MAINWP_REMOTE_MCP_TEST_KILL_SWITCH';
	}

	/**
	 * Settings whose constant is never defined.
	 */
	class Remote_MCP_Test_Unset_Settings extends Remote_MCP_Settings {
		const KILL_SWITCH_CONSTANT = 'MAINWP_REMOTE_MCP_TEST_KILL_SWITCH_UNSET';
	}
}

/**
 * Class Test_MainWP_Remote_MCP_Kill_Switch
 */
class Test_MainWP_Remote_MCP_Kill_Switch extends \WP_UnitTestCase {

	use Remote_MCP_Front_Fixtures;

	/**
	 * Every module path, as [ REQUEST_URI, method, query vars ].
	 *
	 * @return array
	 */
	private function module_paths(): array {
		return array(
			array( '/wp-json/mainwp-remote/v1/mcp', 'POST', $this->rest( '/mainwp-remote/v1/mcp' ) ),
			array( '/wp-json/mainwp-remote/v1/oauth/token', 'POST', $this->rest( '/mainwp-remote/v1/oauth/token' ) ),
			array( '/wp-json/mainwp-remote/v1/oauth-protected-resource', 'GET', $this->rest( '/mainwp-remote/v1/oauth-protected-resource' ) ),
			array( '/wp-json/mainwp-remote/v1/register', 'POST', $this->rest( '/mainwp-remote/v1/register' ) ),
			array( '/.well-known/oauth-authorization-server', 'GET', array() ),
			array( '/.well-known/openid-configuration', 'GET', array() ),
			array( '/mainwp-oauth/authorize?client_id=x', 'GET', array() ),
			array( '/mainwp-oauth/approve?approval=3', 'GET', array() ),
		);
	}

	public function test_constant_forces_connector_off_even_when_enabled() {
		$this->assertSame( 'MAINWP_DISABLE_REMOTE_MCP', Remote_MCP_Settings::KILL_SWITCH_CONSTANT );
		$this->assertSame( 'constant', Remote_MCP_Settings::forced_off_reason( true, false ) );
		$this->assertSame( 'constant', Remote_MCP_Settings::forced_off_reason( true, true ) );
		$this->assertNull( Remote_MCP_Settings::forced_off_reason( false, false ) );

		$this->assertTrue( Remote_MCP_Test_Unset_Settings::is_active(), 'enabled and not forced off' );
		if ( ! defined( Remote_MCP_Test_Kill_Settings::KILL_SWITCH_CONSTANT ) ) {
			define( Remote_MCP_Test_Kill_Settings::KILL_SWITCH_CONSTANT, true );
		}
		$this->assertTrue( Remote_MCP_Test_Kill_Settings::enabled(), 'the stored toggle is untouched' );
		$this->assertFalse( Remote_MCP_Test_Kill_Settings::is_active() );
		$this->assertSame( 'constant', Remote_MCP_Test_Kill_Settings::forced_off() );

		// The toggle can't turn it back on.
		update_option( Remote_MCP_Settings::OPTION_ENABLED, '' );
		$remote = new Remote_MCP( $this->spy_services(), Remote_MCP_Test_Kill_Settings::class );
		$this->assertContains( 'forced_off', $remote->enable() );
		$this->assertFalse( Remote_MCP_Settings::enabled() );
	}

	public function test_filter_forces_connector_off_but_cannot_override_constant() {
		$this->assertSame( 'filter', Remote_MCP_Settings::forced_off_reason( false, true ) );

		$consulted = 0;
		$force     = true;
		add_filter(
			'mainwp_remote_mcp_force_disabled',
			function ( $value ) use ( &$consulted, &$force ) {
				++$consulted;
				return $force;
			}
		);
		$this->assertFalse( Remote_MCP_Test_Unset_Settings::is_active() );
		$this->assertSame( 'filter', Remote_MCP_Test_Unset_Settings::forced_off() );

		// A filter can only add "off": with the constant set it is never consulted, so returning
		// false can't turn a constant-disabled connector back on.
		$force     = false;
		$consulted = 0;
		if ( ! defined( Remote_MCP_Test_Kill_Settings::KILL_SWITCH_CONSTANT ) ) {
			define( Remote_MCP_Test_Kill_Settings::KILL_SWITCH_CONSTANT, true );
		}
		$this->assertFalse( Remote_MCP_Test_Kill_Settings::is_active() );
		$this->assertSame( 'constant', Remote_MCP_Test_Kill_Settings::forced_off() );
		$this->assertSame( 0, $consulted );

		$this->assertTrue( Remote_MCP_Test_Unset_Settings::is_active(), 'filter returning false leaves it on' );
		$this->assertGreaterThan( 0, $consulted );
	}

	public function test_forced_off_module_paths_return_404_without_challenge() {
		add_filter( 'mainwp_remote_mcp_force_disabled', '__return_true' );
		$controller = $this->controller( array( Remote_MCP_Test_Unset_Settings::class, 'is_active' ) );

		foreach ( $this->module_paths() as list( $uri, $method, $vars ) ) {
			$response = $this->dispatch( $controller, $uri, $method, $vars, '{}' );
			if ( false !== strpos( $uri, '/.well-known/' ) ) {
				$this->assertNull( $response, $uri . ' is left to WordPress and other handlers' );
				continue;
			}
			$this->assertNotNull( $response, $uri );
			$this->assertSame( 404, $response->status(), $uri . ' gets a final 404' );
			$this->assertNull( $response->header( 'WWW-Authenticate' ), $uri . ' carries no challenge' );
		}
		update_option( 'permalink_structure', '' );
		$plain = $this->dispatch( $controller, '/?mainwp_oauth=authorize&client_id=x', 'GET' );
		$this->assertSame( 404, $plain ? $plain->status() : null, 'plain-permalink authorize selector' );
		update_option( 'permalink_structure', '/%postname%/' );
		$this->assertSame( 0, $this->spy_calls(), 'no handler, so no challenge and no lookup' );
		$this->assertSame( 0, $this->body_reads );

		// The production predicate is the one the wiring hands the controller.
		$remote = new Remote_MCP( $this->spy_services() );
		$this->assertSame( 404, $this->dispatch( $remote->front_controller(), '/wp-json/mainwp-remote/v1/mcp', 'POST', $this->rest( '/mainwp-remote/v1/mcp' ), '{}' )->status() );
	}

	public function test_late_registered_filter_takes_effect_per_request() {
		$remote     = new Remote_MCP( $this->spy_services() );
		$controller = $remote->front_controller();
		$mcp        = array( '/wp-json/mainwp-remote/v1/mcp', 'POST', $this->rest( '/mainwp-remote/v1/mcp' ) );

		$this->assertNotNull( $this->dispatch( $controller, ...$mcp ) );

		// Added after the module booted, the way a later plugin or an mu-plugin would.
		add_filter( 'mainwp_remote_mcp_force_disabled', '__return_true' );
		$this->assertSame( 404, $this->dispatch( $controller, ...$mcp )->status() );
		$this->assertCount( 1, $this->spies['route_mcp']->calls, 'the forced-off request reached no handler' );

		remove_filter( 'mainwp_remote_mcp_force_disabled', '__return_true' );
		$this->assertNotNull( $this->dispatch( $controller, ...$mcp ), 'lifting it restores service' );
	}
}
