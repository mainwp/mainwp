<?php
/**
 * Remote MCP connection tokens on the MCP route.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

require_once __DIR__ . '/fixtures/endpoint-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_MCP_Connection_Token
 */
class Test_MainWP_MCP_Connection_Token extends \WP_UnitTestCase {

	use Remote_MCP_Endpoint_Fixtures {
		Remote_MCP_Endpoint_Fixtures::tear_down as endpoint_tear_down;
	}

	const RESOURCE  = 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp';
	const CLIENT_ID = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
	const REDIRECT  = 'https://claude.ai/api/mcp/auth_callback';
	const VERIFIER  = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz';

	public function tear_down() {
		unset( $GLOBALS['wp']->query_vars['rest_route'], $_SERVER['HTTP_AUTHORIZATION'] );
		$this->endpoint_tear_down();
	}

	/**
	 * Current user WordPress resolves for a request carrying this bearer header.
	 *
	 * @param string $token Token.
	 * @param string $route REST route, or ''.
	 * @return int
	 */
	private function resolve_user( string $token, string $route ): int {
		$_SERVER['HTTP_AUTHORIZATION']           = 'Bearer ' . $token;
		$GLOBALS['wp']->query_vars['rest_route'] = $route;
		wp_set_current_user( 0 );
		wp_set_current_user( (int) apply_filters( 'determine_current_user', false ) );
		return get_current_user_id();
	}

	public function test_connection_token_accepted_on_mcp_route_only() {
		$minted = $this->connect_token( 'mainwp:read mainwp:write' );
		$this->ability( 'mainwp/remote-test-read-v1' );
		$this->assertSame( 200, $this->legacy( 'ping', array(), $minted['token'] )->status() );
		$tools = $this->json( $this->legacy( 'tools/list', array(), $minted['token'] ) )['result']['tools'];
		$this->assertNotNull( $this->tool( $tools, 'remote_test_read_v1' ) );
		$call = $this->json( $this->legacy( 'tools/call', array( 'name' => 'remote_test_read_v1' ), $minted['token'] ) )['result'];
		$this->assertFalse( $call['isError'] );
		$context = $this->gate->checks[0]['context'];
		$this->assertSame( 'connect', $context->token_type );
		$this->assertSame( 'token', $context->client_kind );
		$this->assertSame( $minted['grant_id'], $context->grant_id );

		// Never through the form or query channels, even with the right value.
		$this->assertSame( 400, $this->raw( '{"jsonrpc":"2.0","id":1,"method":"ping"}', null, array(), null, 'access_token=' . rawurlencode( $minted['token'] ) )->status() );
		$this->assertSame( 0, $this->resolve_user( $minted['token'], '/mainwp-remote/v1/mcp' ), 'the MCP route itself never sets a user from the header' );
	}

	public function test_connection_token_refused_on_abilities_rest_and_ajax() {
		$minted = $this->connect_token( 'mainwp:read mainwp:write' );
		foreach ( array( '/wp-abilities/v1/abilities/mainwp/list-sites-v1/run', '/mainwp/v2/sites', '' ) as $route ) {
			$this->assertSame( 0, $this->resolve_user( $minted['token'], $route ), $route ? $route : 'admin-ajax' );
		}
		$this->assertFalse( is_user_logged_in() );
		$request = new \WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/mainwp/list-sites-v1/run' );
		$request->set_header( 'Authorization', 'Bearer ' . $minted['token'] );
		$this->assertContains( rest_get_server()->dispatch( $request )->get_status(), array( 401, 403 ) );
	}

	public function test_expired_connection_token_gets_expiry_401() {
		$minted = $this->connect_token( 'mainwp:read', 30 );
		$this->assertSame( 200, $this->legacy( 'ping', array(), $minted['token'] )->status() );
		$this->tick( 31 * DAY_IN_SECONDS );
		$response = $this->legacy( 'ping', array(), $minted['token'] );
		$this->assertSame( 401, $response->status() );
		$this->assertSame(
			'Bearer resource_metadata="https://dash.example.org/wp-json/mainwp-remote/v1/oauth-protected-resource", scope="mainwp:read mainwp:write", error="invalid_token", error_description="This MainWP connection token has expired. Create a new one in AI > MainWP MCP > Connect an app."',
			$response->header( 'WWW-Authenticate' )
		);

		// An unknown token of the same type gets no expiry text.
		$unknown = $this->legacy( 'ping', array(), 'mwpct_' . str_repeat( 'C', 43 ) );
		$this->assertStringNotContainsString( 'expired', (string) $unknown->header( 'WWW-Authenticate' ) );
	}
}
