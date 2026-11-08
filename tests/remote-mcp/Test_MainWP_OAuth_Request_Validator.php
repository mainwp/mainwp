<?php
/**
 * Authorization request validation tests, driven through the authorization endpoint.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\OAuth_Store;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings;

require_once __DIR__ . '/class-oauth-authorize-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName, WordPress.Files.FileName.NotHyphenatedLowercase

/**
 * Class Test_MainWP_OAuth_Request_Validator
 */
class Test_MainWP_OAuth_Request_Validator extends OAuth_Authorize_Test_Case {

	/**
	 * Assert the request reached the consent screen.
	 *
	 * @param array  $overrides Parameter overrides.
	 * @param string $message   Context.
	 */
	private function assert_consent( array $overrides, string $message = '' ): void {
		$response = $this->authorize( $overrides );
		$this->assertSame( 200, $response->status(), $message . ': ' . $response->body() );
		$this->assertStringContainsString( 'data-page="consent"', $response->body(), $message );
	}

	/**
	 * Assert a redirect URI is refused on the error page.
	 *
	 * @param string $client_id Client.
	 * @param string $redirect  Presented redirect.
	 */
	private function assert_redirect_refused( string $client_id, string $redirect ): void {
		$this->assert_error_page(
			$this->authorize(
				array(
					'client_id'    => $client_id,
					'redirect_uri' => $redirect,
				)
			),
			'redirect_mismatch',
			$redirect
		);
	}

	/**
	 * Assert a redirect URI reaches consent.
	 *
	 * @param string $client_id Client.
	 * @param string $redirect  Presented redirect.
	 */
	private function assert_redirect_allowed( string $client_id, string $redirect ): void {
		$this->assert_consent(
			array(
				'client_id'    => $client_id,
				'redirect_uri' => $redirect,
			),
			$redirect
		);
	}

	public function test_unknown_client_id_renders_error_page_before_login() {
		wp_set_current_user( 0 );
		foreach ( array(
			'https://evil.example/oauth/client.json',
			self::CLIENT_ID . '/',
			'HTTPS://claude.ai/oauth/claude-code-client-metadata',
			'https://claude.ai/oauth/claude%2Dcode-client-metadata',
			'https://claude.ai:443/oauth/claude-code-client-metadata',
			'MAINWP-CURSOR',
		) as $client_id ) {
			$response = $this->authorize( array( 'client_id' => $client_id ) );
			$this->assert_error_page( $response, 'unsupported_client', $client_id );
			$this->assertStringNotContainsString( 'wp-login.php', $response->body(), 'no login hand-off for an unknown app' );
		}
		// A switched-off client is refused the same way, before login.
		$this->clients->clients[ self::CURSOR ]['enabled'] = false;
		$this->assert_error_page(
			$this->authorize(
				array(
					'client_id'    => self::CURSOR,
					'redirect_uri' => 'http://localhost:8787/callback',
				)
			),
			'unsupported_client'
		);
		$this->assertSame( array(), $this->requests() );
	}

	public function test_redirect_uri_is_exact_raw_string_match() {
		$this->assert_redirect_allowed( self::CLAUDE_AI, self::CLAUDE_AI_CB );
		foreach ( array(
			self::CLAUDE_AI_CB . '/',
			'https://CLAUDE.ai/api/mcp/auth_callback',
			'https://claude.ai/api/mcp/auth_callback?x=1',
			'https://claude.ai:443/api/mcp/auth_callback',
			'https://claude.ai/api/mcp/auth%5Fcallback',
			'https://claude.ai/api/mcp/./auth_callback',
		) as $redirect ) {
			$this->assert_redirect_refused( self::CLAUDE_AI, $redirect );
		}
	}

	public function test_redirect_fragment_rejected_without_redirect() {
		foreach ( array( self::CLAUDE_AI_CB . '#frag', self::CLAUDE_AI_CB . '#', 'http://127.0.0.1:53682/callback#x' ) as $redirect ) {
			$this->clients->clients[ self::CLAUDE_AI ]['redirect_uris'][] = $redirect;
			$this->assert_error_page(
				$this->authorize(
					array(
						'client_id'    => self::CLAUDE_AI,
						'redirect_uri' => $redirect,
					)
				),
				'invalid_redirect_uri',
				$redirect
			);
		}
	}

	public function test_duplicate_authorization_parameters_rejected_before_client_lookup() {
		$valid = $this->query( $this->auth_params() );
		foreach ( array(
			$valid . '&client_id=' . rawurlencode( self::CLAUDE_AI ),
			$valid . '&client_id%5B%5D=' . rawurlencode( self::CLAUDE_AI ),
			$valid . '&state=other',
			$valid . '&redirect_uri=' . rawurlencode( 'http://localhost/callback' ),
			$valid . '&code_challenge_method=S256',
		) as $query ) {
			$this->clients->lookups = array();
			$this->assert_error_page( $this->get_raw( $query ), 'duplicate_parameter', $query );
			$this->assertSame( array(), $this->clients->lookups, 'no client lookup' );
		}
		$this->assertSame( array(), $this->requests() );
	}

	public function test_empty_parameter_treated_as_omitted() {
		// An empty value is no value: neither a duplicate nor a state to echo.
		$query = $this->query( $this->auth_params( array( 'state' => null ) ) ) . '&state=&client_id=';
		$this->assertSame( 200, $this->get_raw( $query )->status() );
		$rows = $this->requests();
		$this->assertCount( 1, $rows );
		$this->assertNull( $rows[0]['state'] );

		$this->assert_error_page( $this->authorize( array( 'code_challenge' => '' ) ), 'invalid_code_challenge', 'empty challenge is missing' );
		$this->assert_error_page( $this->authorize( array( 'client_id' => '' ) ), 'unsupported_client', 'empty client_id is missing' );
	}

	public function test_multiple_resource_parameters_rejected_as_invalid_target() {
		$valid = $this->query( $this->auth_params() );
		foreach ( array( $valid . '&resource=' . rawurlencode( self::RESOURCE ), $valid . '&resource=' . rawurlencode( 'https://other.example/mcp' ), $valid . '&resource%5B%5D=' . rawurlencode( self::RESOURCE ) ) as $query ) {
			$this->clients->lookups = array();
			$this->assert_error_page( $this->get_raw( $query ), 'invalid_target', $query );
			$this->assertSame( array(), $this->clients->lookups, 'refused before the client is verified' );
		}
	}

	public function test_missing_resource_at_authorization_rejected() {
		$this->assert_error_page( $this->authorize( array( 'resource' => null ) ), 'invalid_target' );
		$this->assert_error_page( $this->authorize( array( 'resource' => '' ) ), 'invalid_target', 'empty counts as missing' );
		$this->assertSame( array(), $this->requests() );
	}

	public function test_redirect_uri_is_not_rewritten_by_esc_url_raw() {
		$raw = 'https://claude.ai/api/mcp/auth_callback?ctx=a^b&x={y}';
		$this->assertNotSame( $raw, esc_url_raw( $raw ), 'the sanitizer would change this URI' );
		$this->clients->clients[ self::CLAUDE_AI ]['redirect_uris'] = array( $raw );

		$consent = $this->consent(
			array(
				'client_id'    => self::CLAUDE_AI,
				'redirect_uri' => $raw,
			)
		);
		$this->assertSame( $raw, $this->requests()[0]['redirect_uri'], 'stored as received' );

		$response = $this->decide( $consent );
		$this->assertSame( 302, $response->status(), $response->body() );
		$this->assertStringStartsWith( $raw . '&code=', (string) $response->header( 'Location' ), 'redirected to the raw string' );
	}

	public function test_http_redirect_rejected_unless_loopback() {
		$this->clients->clients[ self::CLIENT_ID ]['redirect_uris'][] = 'http://example.com/callback';
		$this->clients->clients[ self::CLIENT_ID ]['redirect_uris'][] = 'ftp://127.0.0.1/callback';

		$this->assert_redirect_allowed( self::CLIENT_ID, 'http://127.0.0.1/callback' );
		// Listed or not, plain http to a host that isn't loopback never receives a code.
		$this->assert_redirect_refused( self::CLIENT_ID, 'http://example.com/callback' );
		$this->assert_redirect_refused( self::CLIENT_ID, 'http://example.com:8080/callback' );
		$this->assert_redirect_refused( self::CLIENT_ID, 'ftp://127.0.0.1/callback' );
		$this->assert_redirect_refused( self::CLIENT_ID, 'http://127.0.0.2/callback' );
	}

	public function test_loopback_redirect_ignores_port_but_compares_path_and_query() {
		$this->assert_redirect_allowed( self::CLIENT_ID, 'http://127.0.0.1:53682/callback' );
		$this->assert_redirect_allowed( self::CLIENT_ID, 'http://localhost:1234/callback' );
		$this->assert_redirect_refused( self::CLIENT_ID, 'http://127.0.0.1:53682/callback2' );
		$this->assert_redirect_refused( self::CLIENT_ID, 'http://127.0.0.1:53682/Callback' );
		$this->assert_redirect_refused( self::CLIENT_ID, 'http://127.0.0.1:53682/callback?x=1' );
		$this->assert_redirect_refused( self::CLIENT_ID, 'http://127.0.0.1:53682/' );

		// A registered entry with a port and a query: only the port is ignored.
		$this->clients->clients[ self::CLIENT_ID ]['redirect_uris'] = array( 'http://127.0.0.1:9000/cb?mode=a' );
		$this->assert_redirect_allowed( self::CLIENT_ID, 'http://127.0.0.1:4000/cb?mode=a' );
		$this->assert_redirect_allowed( self::CLIENT_ID, 'http://127.0.0.1/cb?mode=a' );
		$this->assert_redirect_refused( self::CLIENT_ID, 'http://127.0.0.1:4000/cb?mode=b' );
		$this->assert_redirect_refused( self::CLIENT_ID, 'http://127.0.0.1:4000/cb' );
	}

	public function test_portless_registered_loopback_matches_any_request_port() {
		foreach ( array( 'http://127.0.0.1/callback', 'http://127.0.0.1:1/callback', 'http://127.0.0.1:65535/callback', 'http://localhost:8080/callback' ) as $redirect ) {
			$this->assert_redirect_allowed( self::CLIENT_ID, $redirect );
		}
		foreach ( array( 'http://127.0.0.1:0/callback', 'http://127.0.0.1:65536/callback', 'http://127.0.0.1:/callback', 'http://127.0.0.1:123456/callback', 'http://[::1]:5000/callback' ) as $redirect ) {
			$this->assert_redirect_refused( self::CLIENT_ID, $redirect );
		}
		$this->clients->clients[ self::CLIENT_ID ]['redirect_uris'] = array( 'http://[::1]/callback' );
		$this->assert_redirect_allowed( self::CLIENT_ID, 'http://[::1]:5000/callback' );
	}

	public function test_any_port_rule_never_applies_to_https_or_non_loopback() {
		$this->assert_redirect_refused( self::CLAUDE_AI, 'https://claude.ai:8443/api/mcp/auth_callback' );

		$this->clients->clients[ self::CLIENT_ID ]['redirect_uris'] = array( 'https://127.0.0.1/cb', 'http://localhost/only' );
		$this->assert_redirect_allowed( self::CLIENT_ID, 'https://127.0.0.1/cb' );
		$this->assert_redirect_refused( self::CLIENT_ID, 'https://127.0.0.1:9000/cb' );
		// localhost never stands in for 127.0.0.1, or the reverse.
		$this->assert_redirect_allowed( self::CLIENT_ID, 'http://localhost:7000/only' );
		$this->assert_redirect_refused( self::CLIENT_ID, 'http://127.0.0.1:7000/only' );
		$this->assert_redirect_refused( self::CLIENT_ID, 'http://127.0.0.1/cb' );
	}

	public function test_only_code_response_type_accepted() {
		foreach ( array( 'token', 'code token', 'CODE', 'code ', null ) as $type ) {
			$response = $this->authorize( array( 'response_type' => $type ) );
			$this->assert_error_page( $response, 'unsupported_response_type', (string) $type );
		}
		$this->assertSame( array(), $this->requests() );
	}

	public function test_unknown_scope_rejected_with_invalid_scope() {
		foreach ( array( 'mainwp:read mainwp:admin', 'mcp:read offline_access', 'MAINWP:READ' ) as $scope ) {
			$query = $this->assert_client_redirect( $this->authorize( array( 'scope' => $scope ) ), self::REDIRECT );
			$this->assertSame( 'invalid_scope', ( $query['error'] ?? '' ), $scope );
			$this->assertSame( 'client-state-123', ( $query['state'] ?? '' ) );
			$this->assertSame( self::HOME, ( $query['iss'] ?? '' ) );
			$this->assertStringNotContainsString( 'admin', ( $query['error_description'] ?? '' ), 'nothing from the request is echoed' );
		}
		$this->assertSame( array(), $this->requests() );
		$this->assert_consent( array( 'scope' => 'offline_access mainwp:read mainwp:write' ), 'every known token' );
	}

	public function test_missing_scope_defaults_to_read() {
		$consent = $this->consent( array( 'scope' => null ) );
		$this->assertSame( 'mainwp:read', $this->requests()[0]['scope'] );
		// View access is described, and there is nothing to choose.
		$this->assertStringNotContainsString( 'name="access"', $consent['body'] );
		$this->assertStringNotContainsString( 'name="policy"', $consent['body'] );

		$response = $this->decide(
			$consent,
			array(
				'access' => null,
				'policy' => null,
			)
		);
		$this->assertSame( 302, $response->status(), $response->body() );
		$codes = $this->codes();
		$this->assertCount( 1, $codes );
		$this->assertSame( 'mainwp:read', $codes[0]['scope'] );
		$this->assertSame( 'strict', $codes[0]['approval_policy'] );

		// Write implies read: the stored request always carries read with write.
		$this->consent( array( 'scope' => 'mainwp:write offline_access' ) );
		$this->assertSame( 'mainwp:read mainwp:write offline_access', $this->requests()[1]['scope'] );
	}

	/**
	 * View-only consent must grant the read access it describes.
	 */
	public function test_scope_without_mainwp_token_defaults_to_read() {
		$consent = $this->consent( array( 'scope' => 'offline_access' ) );
		$this->assertStringContainsString( '<p class="mainwp-oauth-access"><strong>View only</strong>', $consent['body'] );
		$this->assertStringNotContainsString( 'name="access"', $consent['body'] );
		$this->assertSame( 'mainwp:read offline_access', $this->requests()[0]['scope'], 'the stored request carries read' );

		$query    = $this->assert_client_redirect(
			$this->decide(
				$consent,
				array(
					'access' => null,
					'policy' => null,
				)
			),
			self::REDIRECT
		);
		$redeemed = $this->store->redeem_code( ( $query['code'] ?? '' ), self::CLIENT_ID, self::VERIFIER );
		$this->assertSame( OAuth_Store::OK, $redeemed['outcome'] );
		$grant = $this->grant( $redeemed['grant_id'] );
		$this->assertSame( 'mainwp:read offline_access', $grant['scope'], 'the grant is the view-only access the user approved' );
		$this->assertSame( 'strict', $grant['approval_policy'] );
	}

	public function test_resource_must_equal_canonical_mcp_url() {
		foreach ( array(
			self::RESOURCE . '/',
			'https://dash.example.com/wp-json/mainwp-remote/v1/MCP',
			self::RESOURCE . '?x=1',
			'http://dash.example.com/wp-json/mainwp-remote/v1/mcp',
			'https://other.example.com/wp-json/mainwp-remote/v1/mcp',
			'https://dash.example.com:8443/wp-json/mainwp-remote/v1/mcp',
			'https://dash.example.com/?rest_route=/mainwp-remote/v1/mcp',
			'https://dash.example.com',
		) as $resource ) {
			$this->assert_error_page( $this->authorize( array( 'resource' => $resource ) ), 'invalid_target', $resource );
		}
		$this->assertSame( array(), $this->requests() );
	}

	public function test_resource_with_uppercase_scheme_and_host_accepted() {
		$this->assert_consent( array( 'resource' => 'HTTPS://DASH.Example.COM/wp-json/mainwp-remote/v1/mcp' ), 'uppercase scheme and host' );
		$this->assert_consent( array( 'resource' => 'https://dash.example.com:443/wp-json/mainwp-remote/v1/mcp' ), 'default port' );
		foreach ( $this->requests() as $row ) {
			$this->assertSame( self::RESOURCE, $row['resource'], 'stored in canonical form' );
		}
	}

	/**
	 * IPv6 homes must reach consent under the same comparison rules as DNS homes.
	 */
	public function test_resource_with_bracketed_ipv6_authority_accepted() {
		$this->set_site_option( 'home', 'https://[2001:db8::1]' );
		$resource = 'https://[2001:db8::1]/wp-json/mainwp-remote/v1/mcp';
		$this->assertSame( $resource, (string) $this->services()->urls()->mcp_url() );
		$this->assert_consent( array( 'resource' => $resource ), 'IPv6 home' );
		$this->assert_consent( array( 'resource' => 'HTTPS://[2001:DB8::1]:443/wp-json/mainwp-remote/v1/mcp' ), 'uppercase scheme and host, default port' );

		$this->set_site_option( 'home', 'https://[2001:db8::1]:8443/sub' );
		$resource = 'https://[2001:db8::1]:8443/sub/wp-json/mainwp-remote/v1/mcp';
		$this->assertSame( $resource, (string) $this->services()->urls()->mcp_url() );
		$this->assert_consent( array( 'resource' => $resource ), 'IPv6 home with port and path' );
		foreach ( $this->requests() as $index => $row ) {
			$this->assertSame( $index < 2 ? 'https://[2001:db8::1]/wp-json/mainwp-remote/v1/mcp' : $resource, $row['resource'], 'stored in canonical form' );
		}

		foreach ( array(
			'https://[2001:db8::1]/sub/wp-json/mainwp-remote/v1/mcp',
			'https://[2001:db8::1]:8444/sub/wp-json/mainwp-remote/v1/mcp',
			'https://[2001:db8::2]:8443/sub/wp-json/mainwp-remote/v1/mcp',
			'https://[2001:0db8::1]:8443/sub/wp-json/mainwp-remote/v1/mcp',
			'https://[2001:db8::1]:8443/sub/wp-json/mainwp-remote/v1/MCP',
			$resource . '/',
			$resource . '?x=1',
			'https://[2001:db8::1:8443/sub/wp-json/mainwp-remote/v1/mcp',
			'https://user@[2001:db8::1]:8443/sub/wp-json/mainwp-remote/v1/mcp',
		) as $presented ) {
			$this->assert_error_page( $this->authorize( array( 'resource' => $presented ) ), 'invalid_target', $presented );
		}
		$this->assertCount( 3, $this->requests() );
	}

	public function test_malformed_code_challenge_rejected() {
		$good = $this->challenge( self::VERIFIER );
		foreach ( array( substr( $good, 0, 42 ), $good . 'A', substr( $good, 0, 42 ) . '+', substr( $good, 0, 42 ) . '=', substr( $good, 0, 42 ) . ' ', null ) as $challenge ) {
			$this->assert_error_page( $this->authorize( array( 'code_challenge' => $challenge ) ), 'invalid_code_challenge', (string) $challenge );
		}
		$this->assertSame( array(), $this->requests() );
	}

	public function test_plain_pkce_method_rejected() {
		foreach ( array( 'plain', 's256', 'S256 ', null ) as $method ) {
			$this->assert_error_page( $this->authorize( array( 'code_challenge_method' => $method ) ), 'invalid_code_challenge', (string) $method );
		}
		$this->assertSame( array(), $this->requests() );
	}

	public function test_unknown_registered_id_at_authorize_shows_re_add_instructions() {
		wp_set_current_user( 0 );
		$response = $this->authorize(
			array(
				'client_id'    => 'mwplc_' . str_repeat( 'Z', 43 ),
				'redirect_uri' => 'http://127.0.0.1/cb',
			)
		);
		$this->assert_error_page( $response, 'registration_ended' );
		$this->assertStringContainsString( 'Remove MainWP from the app and add it again.', $response->body() );

		// Any other unknown id gets the plain refusal.
		$this->assert_error_page( $this->authorize( array( 'client_id' => 'mwpwa_' . str_repeat( 'Z', 43 ) ) ), 'unsupported_client' );
	}

	public function test_unknown_client_attempt_counted_not_logged() {
		$this->assertSame( 0, Remote_MCP_Settings::counters()['unknown_client'] );
		$this->authorize( array( 'client_id' => 'https://evil.example/client.json' ) );
		$this->authorize( array( 'client_id' => 'mwplc_' . str_repeat( 'Q', 43 ) ) );
		$this->assertSame( 2, Remote_MCP_Settings::counters()['unknown_client'] );
		$this->assertSame( array(), $this->audits, 'no per-request log row for a pre-login refusal' );

		// A known client is not counted.
		$this->authorize();
		$this->assertSame( 2, Remote_MCP_Settings::counters()['unknown_client'] );
	}
}
