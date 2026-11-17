<?php
/**
 * Redirect rules and OAuth acceptance through Known_Clients. The authorize endpoint's use of
 * them is covered by that endpoint's own tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

require_once __DIR__ . '/fixtures/client-registration-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_OAuth_Known_Clients_Redirects
 */
class Test_MainWP_OAuth_Known_Clients_Redirects extends \WP_UnitTestCase {

	use Remote_MCP_Registration_Fixtures;

	const RESOURCE  = 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp';
	const CLIENT_ID = 'https://goose-docs.ai/oauth/client-metadata.json';
	const REDIRECT  = 'http://127.0.0.1:61234/oauth_callback';
	const VERIFIER  = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz';

	public function test_loopback_redirect_matches_with_port_ignored() {
		$clients = $this->fixture_clients();
		$goose   = $clients->lookup( 'https://goose-docs.ai/oauth/client-metadata.json' );
		$this->assertTrue( $goose['oauth_endpoints'] );
		$this->assertSame( 'loopback_any_port', $goose['redirect_rule'] );
		foreach ( array( 'http://127.0.0.1/oauth_callback', 'http://127.0.0.1:61234/oauth_callback', 'http://127.0.0.1:1/oauth_callback' ) as $uri ) {
			$this->assertTrue( $clients->redirect_allowed( $goose, $uri ), $uri );
		}
		// Host, scheme and path still compare exactly; localhost never stands in for 127.0.0.1.
		foreach ( array( 'http://localhost:61234/oauth_callback', 'http://[::1]:61234/oauth_callback', 'https://127.0.0.1:61234/oauth_callback', 'http://127.0.0.1:61234/oauth_callback2', 'http://127.0.0.1:61234/', 'http://127.0.0.2:61234/oauth_callback' ) as $uri ) {
			$this->assertFalse( $clients->redirect_allowed( $goose, $uri ), $uri );
		}

		// A registered app's stored port is ignored the same way, and only the port.
		$registered = $clients->lookup( $this->registered_client( array( 'http://127.0.0.1:8080/cb', 'http://[::1]/cb' ) ) );
		$this->assertTrue( $registered['oauth_endpoints'] );
		$this->assertSame( 'registered_loopback', $registered['redirect_rule'] );
		foreach ( array( 'http://127.0.0.1:9999/cb', 'http://127.0.0.1/cb', 'http://[::1]:4567/cb' ) as $uri ) {
			$this->assertTrue( $clients->redirect_allowed( $registered, $uri ), $uri );
		}
		foreach ( array( 'http://localhost:9999/cb', 'http://127.0.0.1:9999/cb2', 'http://127.0.0.1:9999/', 'http://[::1]:4567/CB' ) as $uri ) {
			$this->assertFalse( $clients->redirect_allowed( $registered, $uri ), $uri );
		}

		// The any-port rule never reaches an https entry.
		$https = $clients->lookup( 'https://claude.ai/oauth/mcp-oauth-client-metadata' );
		$this->assertFalse( $clients->redirect_allowed( $https, 'https://claude.ai:8443/api/mcp/auth_callback' ) );
	}

	public function test_registered_redirect_with_query_refused_at_authorize() {
		$clients    = $this->fixture_clients();
		$registered = $clients->lookup( $this->registered_client( array( 'http://127.0.0.1:8080/cb' ) ) );
		$this->assertTrue( $clients->redirect_allowed( $registered, 'http://127.0.0.1:8080/cb' ) );
		foreach ( array( 'http://127.0.0.1:8080/cb?x=1', 'http://127.0.0.1:8080/cb?', 'http://127.0.0.1:8080/cb#x', 'http://127.0.0.1:8080/cb#' ) as $uri ) {
			$this->assertFalse( $clients->redirect_allowed( $registered, $uri ), $uri );
		}
		// Even when the stored entry itself carries a query, the presented one fails the grammar.
		$stored = $clients->lookup( $this->registered_client( array( 'http://127.0.0.1/cb?x=1' ) ) );
		$this->assertFalse( $clients->redirect_allowed( $stored, 'http://127.0.0.1/cb?x=1' ) );
		$this->assertFalse( $clients->redirect_allowed( $stored, 'http://127.0.0.1:5000/cb?x=1' ) );
	}

	public function test_bundled_loopback_redirect_query_must_match_exactly() {
		$raw     = $this->fixture( 'loopback-query-shape.json' );
		$clients = $this->fixture_clients( array( 'claude-code.json' => $raw ) );
		$clients->test_registry['https://claude.ai/oauth/claude-code-client-metadata']['sha256'] = hash( 'sha256', $raw );
		$entry = $clients->lookup( 'https://claude.ai/oauth/claude-code-client-metadata' );
		$this->assertTrue( $clients->redirect_allowed( $entry, 'http://127.0.0.1:4000/cb?mode=desktop' ) );
		$this->assertTrue( $clients->redirect_allowed( $entry, 'http://127.0.0.1/cb?mode=desktop' ) );
		foreach ( array( 'http://127.0.0.1:4000/cb', 'http://127.0.0.1:4000/cb?mode=web', 'http://127.0.0.1:4000/cb?mode=desktop&x=1', 'http://127.0.0.1:4000/cb?Mode=desktop', 'http://127.0.0.1:4000/cb?', 'http://127.0.0.1:4000/cb?mode=desktop#' ) as $uri ) {
			$this->assertFalse( $clients->redirect_allowed( $entry, $uri ), $uri );
		}
		$this->assertTrue( $clients->redirect_allowed( $entry, 'https://app.example.com/cb' ) );
		$this->assertFalse( $clients->redirect_allowed( $entry, 'https://app.example.com/cb?mode=desktop' ) );
	}

	public function test_token_kind_client_refused_at_authorize() {
		$clients = $this->fixture_clients();
		$token   = $clients->lookup( 'mainwp-connection-token' );
		$this->assertSame( 'token', $token['kind'] );
		$this->assertFalse( $token['oauth_endpoints'] );
		$this->assertSame( array(), $token['token_endpoint_auth_methods'] );
		$this->assertSame( 'result', $token['approval_delivery'] );
		$this->assertSame( 'result', $token['step_up'] );
		foreach ( array( 'http://127.0.0.1/cb', 'http://localhost:8787/callback', 'https://dash.example.org/cb' ) as $uri ) {
			$this->assertFalse( $clients->redirect_allowed( $token, $uri ), $uri );
		}
		// Every other kind is accepted by the OAuth endpoints.
		foreach ( array( 'https://claude.ai/oauth/claude-code-client-metadata', 'mainwp-cursor', $this->registered_client( array( 'http://127.0.0.1/cb' ) ), $this->webapp_client( array( 'https://chat.example.com/cb' ) ) ) as $client_id ) {
			$this->assertTrue( $clients->lookup( $client_id )['oauth_endpoints'], $client_id );
		}
		$this->assertNull( $clients->lookup( 'Mainwp-connection-token' ) );
	}

	public function test_validate_redirect_matches_authorize_rule_for_webapp_and_registered() {
		$clients  = $this->fixture_clients();
		$cases    = array(
			'registered' => array( 'http://127.0.0.1/cb', 'http://127.0.0.1:8080/cb', 'http://[::1]:8080/a/b', 'http://localhost', 'http://localhost:3000/callback', 'http://127.0.0.1:0/cb', 'http://127.0.0.1:65536/cb', 'http://127.0.0.1/cb?x=1', 'http://127.0.0.1/cb#x', 'http://127.0.0.1/c%62', 'http://u@127.0.0.1/cb', 'https://127.0.0.1/cb', 'http://127.1/cb', 'http://localhost.evil.com/cb', 'http://127.0.0.1/c b' ),
			'webapp'     => array( 'https://chat.example.com/oauth/callback', 'https://chat.example.com:8443/cb', 'https://chat.example.com/cb?x=1', 'http://chat.example.com/cb', 'https://chat.example.com/cb#x', 'https://*.example.com/cb', 'https://chat.example.com/a%2Fb', 'https://u@chat.example.com/cb', 'https://chat.example.com/a\\b', 'https:///cb' ),
		);
		$accepted = 0;
		foreach ( $cases as $kind => $uris ) {
			foreach ( $uris as $uri ) {
				$valid     = $clients->validate_redirect( $kind, $uri );
				$client_id = 'registered' === $kind ? $this->registered_client( array( $uri ) ) : $this->webapp_client( array( $uri ) );
				$entry     = $clients->lookup( $client_id );
				$this->assertSame( $valid, $clients->redirect_allowed( $entry, $uri ), $kind . ' ' . $uri );
				$accepted += $valid ? 1 : 0;
			}
		}
		$this->assertSame( 7, $accepted );
		// Only these two kinds can be added.
		foreach ( array( 'bundled', 'static', 'token', '' ) as $kind ) {
			$this->assertFalse( $clients->validate_redirect( $kind, 'https://chat.example.com/cb' ), $kind );
			$this->assertFalse( $clients->validate_redirect( $kind, 'http://127.0.0.1/cb' ), $kind );
		}
	}
}
