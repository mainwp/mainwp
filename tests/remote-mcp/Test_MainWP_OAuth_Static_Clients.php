<?php
/**
 * Static client tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings;

require_once __DIR__ . '/fixtures/client-registration-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_OAuth_Static_Clients
 */
class Test_MainWP_OAuth_Static_Clients extends \WP_UnitTestCase {

	use Remote_MCP_Registration_Fixtures;

	const RESOURCE  = 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp';
	const CLIENT_ID = 'mainwp-cursor';
	const REDIRECT  = 'http://localhost:8787/callback';
	const VERIFIER  = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz';

	public function test_static_client_redirect_must_match_fixed_set() {
		$clients = $this->fixture_clients();
		$cursor  = $clients->lookup( 'mainwp-cursor' );
		$this->assertSame( 'static', $cursor['kind'] );
		$this->assertSame( 'cursor_exact', $cursor['redirect_rule'] );
		$this->assertSame( array( 'none' ), $cursor['token_endpoint_auth_methods'] );
		$this->assertSame( 'result', $cursor['approval_delivery'] );
		$this->assertSame( 'result', $cursor['step_up'] );
		$this->assertTrue( $clients->redirect_allowed( $cursor, 'http://localhost:8787/callback' ) );
		$this->assertTrue( $clients->redirect_allowed( $cursor, 'https://www.cursor.com/agents/mcp/oauth/callback' ) );
		foreach ( array( 'https://cursor.com/agents/mcp/oauth/callback', 'https://www.cursor.com/agents/mcp/oauth/callback/', 'https://evil.example.com/callback', 'http://localhost:8787/oauth/callback' ) as $uri ) {
			$this->assertFalse( $clients->redirect_allowed( $cursor, $uri ), $uri );
		}

		$gemini = $clients->lookup( 'mainwp-gemini-cli' );
		$this->assertSame( 'gemini_pattern', $gemini['redirect_rule'] );
		foreach ( array( 'http://localhost:1/oauth/callback', 'http://localhost:7777/oauth/callback', 'http://localhost:65535/oauth/callback' ) as $uri ) {
			$this->assertTrue( $clients->redirect_allowed( $gemini, $uri ), $uri );
		}
		foreach ( array( 'http://localhost:0/oauth/callback', 'http://localhost:65536/oauth/callback', 'http://localhost:123456/oauth/callback', 'http://localhost/oauth/callback', 'http://127.0.0.1:7777/oauth/callback', 'http://localhost:7777/oauth/callback/', 'http://localhost:7777/oauth/callback?x=1', "http://localhost:7777/oauth/callback\n", 'https://localhost:7777/oauth/callback' ) as $uri ) {
			$this->assertFalse( $clients->redirect_allowed( $gemini, $uri ), $uri );
		}
	}

	public function test_cursor_redirect_byte_exact_including_port() {
		$clients = $this->fixture_clients();
		$cursor  = $clients->lookup( 'mainwp-cursor' );
		foreach ( array( 'http://localhost:8788/callback', 'http://localhost:80/callback', 'http://localhost:08787/callback', 'http://localhost/callback', 'http://LOCALHOST:8787/callback', 'http://localhost:8787/Callback', 'http://localhost:8787/callback/', 'http://127.0.0.1:8787/callback', 'https://www.cursor.com:443/agents/mcp/oauth/callback' ) as $uri ) {
			$this->assertFalse( $clients->redirect_allowed( $cursor, $uri ), $uri );
		}
	}

	public function test_redirect_with_userinfo_backslash_or_percent_refused() {
		$clients = $this->fixture_clients();
		$cursor  = $clients->lookup( 'mainwp-cursor' );
		$gemini  = $clients->lookup( 'mainwp-gemini-cli' );
		foreach ( array( 'http://localhost:8787@evil.com/callback', 'http://user@localhost:8787/callback', 'http://localhost:8787\\@evil.com/callback', 'http://localhost:8787/%63allback', "http://localhost:8787/callback\t" ) as $uri ) {
			$this->assertFalse( $clients->redirect_allowed( $cursor, $uri ), $uri );
			$this->assertFalse( $clients->redirect_allowed( $gemini, str_replace( '8787/callback', '8787/oauth/callback', $uri ) ), $uri );
		}

		// The refusal runs before the comparison, so even a stored entry holding the character
		// never matches: rows written before a rule change, or by a bug elsewhere, stay refused.
		$odd    = array( 'https://chat.example.com/a%2Fb', 'https://chat.example.com/a\\b', 'https://u@chat.example.com/cb' );
		$webapp = $clients->lookup( $this->webapp_client( $odd ) );
		foreach ( $odd as $uri ) {
			$this->assertFalse( $clients->redirect_allowed( $webapp, $uri ), $uri );
		}
		$registered = $clients->lookup( $this->registered_client( array( 'http://127.0.0.1/a%2Fb' ) ) );
		$this->assertFalse( $clients->redirect_allowed( $registered, 'http://127.0.0.1:5000/a%2Fb' ) );
	}

	public function test_static_client_switch_off_refuses_authorize_and_token() {
		$clients = $this->fixture_clients();
		$this->assertNotNull( $clients->lookup( 'mainwp-cursor' ) );
		$entry = $clients->lookup( 'mainwp-cursor' );

		$this->assertTrue( Remote_MCP_Settings::set_static_client_enabled( 'mainwp-cursor', false ) );
		// The switch is read per call: authorize and the token endpoint both look up again.
		$this->assertNull( $clients->lookup( 'mainwp-cursor' ) );
		$this->assertNull( $this->fixture_clients()->lookup( 'mainwp-cursor' ) );
		$this->assertFalse( $clients->redirect_allowed( $entry, 'http://localhost:8787/callback' ) );
		$this->assertSame( 'static', $clients->lookup( 'mainwp-gemini-cli' )['kind'] );

		// A malformed stored switch reads as off.
		update_option( Remote_MCP_Settings::OPTION_STATIC_CLIENTS, 'garbage' );
		$this->assertNull( $clients->lookup( 'mainwp-gemini-cli' ) );

		delete_option( Remote_MCP_Settings::OPTION_STATIC_CLIENTS );
		$this->assertSame( 'static', $clients->lookup( 'mainwp-cursor' )['kind'] );
		$this->assertTrue( $clients->lookup( 'mainwp-cursor' )['enabled'] );
	}

	public function test_loopback_port_rule_does_not_apply_to_static_clients() {
		$clients = $this->fixture_clients();
		$cursor  = $clients->lookup( 'mainwp-cursor' );
		$gemini  = $clients->lookup( 'mainwp-gemini-cli' );
		foreach ( array( 'http://localhost:9999/callback', 'http://localhost:1/callback', 'http://localhost:65535/callback' ) as $uri ) {
			$this->assertFalse( $clients->redirect_allowed( $cursor, $uri ), $uri );
		}
		// A forged descriptor can't switch a static client onto another kind's rule.
		$forged                  = $cursor;
		$forged['redirect_rule'] = 'loopback_any_port';
		$forged['kind']          = 'bundled';
		$forged['redirect_uris'] = array( 'http://localhost/callback' );
		$this->assertFalse( $clients->redirect_allowed( $forged, 'http://localhost:9999/callback' ) );
		$forged['redirect_rule'] = 'registered_loopback';
		$this->assertFalse( $clients->redirect_allowed( $forged, 'http://localhost:9999/callback' ) );
		// Gemini's pattern needs the port and the fixed path; nothing else is matched port-free.
		$this->assertFalse( $clients->redirect_allowed( $gemini, 'http://localhost/oauth/callback' ) );
		$this->assertFalse( $clients->redirect_allowed( $gemini, 'http://localhost:7777/callback' ) );
	}
}
