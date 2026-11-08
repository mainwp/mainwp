<?php
/**
 * Remote MCP token boundary tests: a bearer token authenticates the MCP route
 * and nothing else, and MainWP API keys never reach the MCP route.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

require_once __DIR__ . '/fixtures/endpoint-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_MCP_Token_Boundary
 */
class Test_MainWP_MCP_Token_Boundary extends \WP_UnitTestCase {

	use Remote_MCP_Endpoint_Fixtures {
		Remote_MCP_Endpoint_Fixtures::tear_down as endpoint_tear_down;
	}

	const RESOURCE  = 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp';
	const CLIENT_ID = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
	const REDIRECT  = 'https://claude.ai/api/mcp/auth_callback';
	const VERIFIER  = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz';

	/**
	 * Hooks a bearer token could ride on if anything in the module read it there.
	 *
	 * @var string[]
	 */
	const AUTH_HOOKS = array( 'determine_current_user', 'rest_authentication_errors', 'authenticate', 'auth_cookie_valid', 'auth_cookie_malformed', 'set_current_user' );

	public function tear_down() {
		unset( $_GET['consumer_key'], $_GET['consumer_secret'], $GLOBALS['wp']->query_vars['rest_route'] );
		\MainWP_REST_Authentication::$instance = null;
		$this->endpoint_tear_down();
	}

	/**
	 * Resolve the current user the way WordPress does for a request carrying this bearer header.
	 *
	 * @param string $token Bearer token.
	 * @param string $route REST route WordPress parsed, or '' for none.
	 * @return int
	 */
	private function resolve_user( string $token, string $route = '' ): int {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		if ( '' !== $route ) {
			$GLOBALS['wp']->query_vars['rest_route'] = $route;
		}
		wp_set_current_user( 0 );
		$user = apply_filters( 'determine_current_user', false );
		wp_set_current_user( (int) $user );
		return get_current_user_id();
	}

	/**
	 * Dispatch a REST request as WordPress would after resolving the user.
	 *
	 * @param string $method Method.
	 * @param string $route  Route.
	 * @param string $token  Bearer token.
	 * @return \WP_REST_Response
	 */
	private function rest_as_bearer( string $method, string $route, string $token ) {
		$this->assertSame( 0, $this->resolve_user( $token, $route ), 'no user from the bearer header' );
		$request = new \WP_REST_Request( $method, $route );
		$request->set_header( 'Authorization', 'Bearer ' . $token );
		$error = apply_filters( 'rest_authentication_errors', null );
		if ( is_wp_error( $error ) ) {
			return rest_convert_error_to_response( $error );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * A MainWP API key for the default user, as [ consumer key, consumer secret ].
	 *
	 * @return array
	 */
	private function api_key(): array {
		$key    = 'ck_' . bin2hex( random_bytes( 20 ) );
		$secret = 'cs_' . bin2hex( random_bytes( 20 ) );
		wp_set_current_user( $this->user_id );
		$this->assertNotFalse( \MainWP\Dashboard\MainWP_DB::instance()->insert_rest_api_key( $key, $secret, 'read_write', 'Boundary test', 1 ) );
		wp_set_current_user( 0 );
		return array( $key, $secret );
	}

	/**
	 * Fresh MainWP API-key authenticator.
	 *
	 * @return \MainWP_REST_Authentication
	 */
	private function api_auth() {
		\MainWP_REST_Authentication::$instance = null;
		return \MainWP_REST_Authentication::get_instance();
	}

	public function test_bearer_token_does_not_authenticate_wp_abilities_route() {
		$token    = $this->token();
		$response = $this->rest_as_bearer( 'GET', '/wp-abilities/v1/abilities/mainwp/list-sites-v1/run', $token );
		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	public function test_bearer_token_does_not_authenticate_mainwp_rest_v2_route() {
		$token    = $this->token();
		$response = $this->rest_as_bearer( 'GET', '/mainwp/v2/sites', $token );
		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	public function test_bearer_token_does_not_authenticate_other_plugin_route() {
		$token = $this->token();
		add_action(
			'rest_api_init',
			function () {
				register_rest_route(
					'other-plugin/v1',
					'/secret',
					array(
						'methods'             => 'GET',
						'callback'            => function () {
							return array( 'secret' => true );
						},
						'permission_callback' => 'is_user_logged_in',
					)
				);
			}
		);
		// A fresh server runs rest_api_init again, so the route registers the way a plugin's would.
		$GLOBALS['wp_rest_server'] = null;
		rest_get_server();
		$response = $this->rest_as_bearer( 'GET', '/other-plugin/v1/secret', $token );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_bearer_token_does_not_authenticate_admin_ajax() {
		$token = $this->token();
		$this->assertSame( 0, $this->resolve_user( $token ) );
		$this->assertFalse( is_user_logged_in(), 'admin-ajax would only run nopriv handlers' );
		global $wp_filter;
		foreach ( $wp_filter as $hook => $callbacks ) {
			if ( ! in_array( $hook, self::AUTH_HOOKS, true ) && 0 !== strpos( (string) $hook, 'wp_ajax_nopriv_' ) ) {
				continue;
			}
			foreach ( $callbacks->callbacks as $priority ) {
				foreach ( $priority as $callback ) {
					$function = $callback['function'];
					$owner    = is_array( $function ) ? ( is_object( $function[0] ) ? get_class( $function[0] ) : (string) $function[0] ) : ( is_string( $function ) ? $function : '' );
					$this->assertStringNotContainsString( 'Remote_MCP', $owner, 'the module hooks nothing on ' . $hook );
				}
			}
		}
	}

	public function test_determine_current_user_unchanged_by_bearer_header() {
		$token = $this->token();
		$this->assertSame( 0, $this->resolve_user( $token, '/mainwp-remote/v1/mcp' ) );
		$this->assertSame( 0, $this->resolve_user( $token, '/mainwp/v2/sites' ) );
		$this->assertSame( 0, $this->resolve_user( $token ) );
	}

	public function test_api_key_on_mcp_route_is_ignored_and_does_not_define_rest_api_doing() {
		list( $key, $secret )                    = $this->api_key();
		$GLOBALS['wp']->query_vars['rest_route'] = '/mainwp-remote/v1/mcp';
		$_GET['consumer_key']                    = $key;
		$_GET['consumer_secret']                 = $secret;
		$this->assertFalse( apply_filters( 'mainwp_rest_is_request_to_rest_api', true ), 'the exclusion filter claims the module route' );
		$this->assertFalse( $this->api_auth()->authenticate( false ), 'the key does not authenticate on the MCP route' );

		$query    = 'consumer_key=' . rawurlencode( $key ) . '&consumer_secret=' . rawurlencode( $secret );
		$response = $this->raw(
            (string) wp_json_encode(
                array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'ping',
                )
            ),
            null,
            array(),
            null,
            $query
        );
		$this->assertSame( 401, $response->status() );
		$this->assertFalse( defined( 'MAINWP_REST_API_DOING' ) );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_cookie_session_without_bearer_gets_401_on_mcp_route() {
		wp_set_current_user( $this->user_id );
		$response = $this->legacy( 'tools/list', array(), null );
		$this->assertSame( 401, $response->status() );
		$this->assertSame( 0, get_current_user_id(), 'ambient identity dropped' );
	}

	public function test_existing_api_key_auth_on_mainwp_v2_unchanged() {
		list( $key, $secret )                    = $this->api_key();
		$GLOBALS['wp']->query_vars['rest_route'] = '/mainwp/v2/sites';
		$_GET['consumer_key']                    = $key;
		$_GET['consumer_secret']                 = $secret;
		$this->assertTrue( apply_filters( 'mainwp_rest_is_request_to_rest_api', true ) );
		$this->assertSame( $this->user_id, (int) $this->api_auth()->authenticate( false ) );
	}
}
