<?php
/**
 * Remote MCP front controller tests.
 *
 * The controller is driven through dispatch(), which returns the response object instead of
 * emitting it: headers_list() is empty under the CLI, so wire-level checks are real-HTTP checks
 * in the end-to-end harness.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\Remote_MCP;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Response;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings;

require_once __DIR__ . '/class-remote-mcp-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_MCP_Front_Controller
 */
class Test_MainWP_MCP_Front_Controller extends \WP_UnitTestCase {

	use Remote_MCP_Front_Fixtures;

	/**
	 * A challenge response like the one the MCP endpoint sends.
	 *
	 * @return Remote_MCP_Response
	 */
	private function challenge(): Remote_MCP_Response {
		return Remote_MCP_Response::json(
			401,
			array( 'error' => 'invalid_token' ),
			array( 'WWW-Authenticate' => 'Bearer resource_metadata="https://dash.example.org/wp-json/mainwp-remote/v1/oauth-protected-resource", scope="mainwp:read mainwp:write"' )
		);
	}

	public function test_module_routes_are_served_before_rest_api_loaded() {
		$remote = new Remote_MCP( $this->spy_services() );
		$remote->register_hooks();

		// WordPress fills rest_route before parse_request runs, and core serves REST at priority 10.
		$this->assertSame( 1, has_action( 'parse_request', array( $remote->front_controller(), 'on_parse_request' ) ) );
		$this->assertSame( 10, has_action( 'parse_request', 'rest_api_loaded' ) );

		$controller = $this->controller();
		$response   = $this->dispatch( $controller, '/wp-json/mainwp-remote/v1/mcp', 'POST', $this->rest( '/mainwp-remote/v1/mcp' ) );
		$this->assertInstanceOf( Remote_MCP_Response::class, $response );
		$this->assertCount( 1, $this->spies['route_mcp']->calls );

		// The plain-permalink form and a differently cased route reach the same handler.
		$this->dispatch( $controller, '/?rest_route=/mainwp-remote/v1/mcp', 'POST', $this->rest( '/MainWP-Remote/v1/mcp/' ) );
		$this->assertCount( 2, $this->spies['route_mcp']->calls );

		// Other REST routes are left to WordPress.
		$this->assertNull( $this->dispatch( $controller, '/wp-json/mainwp/v2/sites', 'GET', $this->rest( '/mainwp/v2/sites' ) ) );
		$this->assertNull( $this->dispatch( $controller, '/wp-json/wp/v2/posts', 'GET', $this->rest( '/wp/v2/posts' ) ) );
	}

	public function test_envelope_jsonp_and_fields_parameters_have_no_effect() {
		$controller = $this->controller( null, array( 'route_mcp' => $this->challenge() ) );
		$plain      = $this->dispatch( $controller, '/wp-json/mainwp-remote/v1/mcp', 'POST', $this->rest( '/mainwp-remote/v1/mcp' ), '{"jsonrpc":"2.0","id":1,"method":"ping"}' );
		$tampered   = $this->dispatch(
			$controller,
			'/wp-json/mainwp-remote/v1/mcp?_envelope=1&_jsonp=cb&_fields=id&_embed=1&_method=GET',
			'POST',
			array(
				'rest_route' => '/mainwp-remote/v1/mcp',
				'_envelope'  => '1',
				'_jsonp'     => 'cb',
				'_fields'    => 'id',
			),
			'{"jsonrpc":"2.0","id":1,"method":"ping"}'
		);

		$this->assertSame( 401, $tampered->status() );
		$this->assertSame( $plain->status(), $tampered->status() );
		$this->assertSame( $plain->headers(), $tampered->headers() );
		$this->assertSame( $plain->body(), $tampered->body() );
		$this->assertStringStartsWith( 'Bearer resource_metadata=', $tampered->header( 'WWW-Authenticate' ) );

		// _method never changes the method the handler sees.
		$this->assertSame( 'POST', $this->spies['route_mcp']->calls[1]->method() );
	}

	public function test_response_object_carries_no_cors_headers() {
		$_SERVER['HTTP_ORIGIN'] = 'https://evil.example.com';
		$leaky                  = Remote_MCP_Response::json(
			200,
			array( 'ok' => true ),
			array(
				'Access-Control-Allow-Origin'      => 'https://evil.example.com',
				'access-control-allow-credentials' => 'true',
				'Access-Control-Expose-Headers'    => 'WWW-Authenticate',
			)
		);
		$controller             = $this->controller( null, array( 'route_mcp' => $leaky ) );

		$responses = array(
			$this->dispatch( $controller, '/wp-json/mainwp-remote/v1/mcp', 'POST', $this->rest( '/mainwp-remote/v1/mcp' ) ),
			$this->dispatch( $controller, '/wp-json/mainwp-remote/v1/mcp', 'OPTIONS', $this->rest( '/mainwp-remote/v1/mcp' ) ),
			$this->dispatch( $controller, '/wp-json/mainwp-remote/v1/oauth/token', 'GET', $this->rest( '/mainwp-remote/v1/oauth/token' ) ),
		);
		foreach ( $responses as $response ) {
			$this->assertInstanceOf( Remote_MCP_Response::class, $response );
			foreach ( array_keys( $response->headers() ) as $name ) {
				$this->assertStringStartsNotWith( 'access-control-', strtolower( $name ) );
			}
		}
		$this->assertSame( 'no-store', $responses[0]->header( 'Cache-Control' ) );
	}

	public function test_rest_authentication_errors_filter_cannot_preempt_challenge() {
		add_filter(
			'rest_authentication_errors',
			function () {
				return new \WP_Error( 'rest_forbidden', 'Anonymous REST is blocked.', array( 'status' => 403 ) );
			}
		);
		add_filter(
			'rest_pre_dispatch',
			function () {
				return new \WP_Error( 'blocked', 'Blocked.', array( 'status' => 403 ) );
			}
		);
		$controller = $this->controller( null, array( 'route_mcp' => $this->challenge() ) );

		$response = $this->dispatch( $controller, '/wp-json/mainwp-remote/v1/mcp', 'POST', $this->rest( '/mainwp-remote/v1/mcp' ) );

		$this->assertCount( 1, $this->spies['route_mcp']->calls );
		$this->assertSame( 401, $response->status() );
		$this->assertSame( $this->challenge()->header( 'WWW-Authenticate' ), $response->header( 'WWW-Authenticate' ) );
		$this->assertSame( $this->challenge()->body(), $response->body() );
	}

	public function test_options_request_gets_405() {
		$controller = $this->controller();

		$mcp = $this->dispatch( $controller, '/wp-json/mainwp-remote/v1/mcp', 'OPTIONS', $this->rest( '/mainwp-remote/v1/mcp' ) );
		$this->assertSame( 405, $mcp->status() );
		$this->assertSame( 'POST', $mcp->header( 'Allow' ) );

		$prm = $this->dispatch( $controller, '/wp-json/mainwp-remote/v1/oauth-protected-resource', 'OPTIONS', $this->rest( '/mainwp-remote/v1/oauth-protected-resource' ) );
		$this->assertSame( 405, $prm->status() );
		$this->assertSame( 'GET', $prm->header( 'Allow' ) );

		$token = $this->dispatch( $controller, '/wp-json/mainwp-remote/v1/oauth/token', 'OPTIONS', $this->rest( '/mainwp-remote/v1/oauth/token' ) );
		$this->assertSame( 405, $token->status() );
		$this->assertSame( 'POST', $token->header( 'Allow' ) );

		$this->assertSame( 0, $this->spy_calls(), 'no handler runs for a refused method' );
		$this->assertSame( 0, $this->body_reads, 'the body is never read for a refused method' );
	}

	public function test_http_mcp_request_never_dispatches() {
		unset( $_SERVER['HTTPS'] );
		$_SERVER['SERVER_PORT'] = '80';
		$controller             = $this->controller();

		$mcp = $this->dispatch( $controller, '/wp-json/mainwp-remote/v1/mcp', 'POST', $this->rest( '/mainwp-remote/v1/mcp' ), '{"jsonrpc":"2.0","id":7,"method":"tools/list"}' );
		$this->assertSame( 400, $mcp->status() );
		$body = json_decode( $mcp->body(), true );
		$this->assertSame( -32600, $body['error']['code'] );
		$this->assertNull( $body['id'], 'the request id is never read' );

		$prm = $this->dispatch( $controller, '/wp-json/mainwp-remote/v1/oauth-protected-resource', 'GET', $this->rest( '/mainwp-remote/v1/oauth-protected-resource' ) );
		$this->assertSame( 400, $prm->status() );
		$this->assertSame( -32600, json_decode( $prm->body(), true )['error']['code'] );

		$token = $this->dispatch( $controller, '/wp-json/mainwp-remote/v1/oauth/token', 'POST', $this->rest( '/mainwp-remote/v1/oauth/token' ), 'grant_type=authorization_code&code=mwpac_x' );
		$this->assertSame( 400, $token->status() );
		$this->assertSame( 'invalid_request', json_decode( $token->body(), true )['error'] );

		$register = $this->dispatch( $controller, '/wp-json/mainwp-remote/v1/register', 'POST', $this->rest( '/mainwp-remote/v1/register' ), '{}' );
		$this->assertSame( 400, $register->status() );

		$authorize = $this->dispatch( $controller, '/mainwp-oauth/authorize?client_id=x', 'GET' );
		$this->assertSame( 400, $authorize->status() );
		$this->assertSame( 'DENY', $authorize->header( 'X-Frame-Options' ) );
		$this->assertNull( $authorize->header( 'Location' ), 'never a redirect to the client' );

		// Well-known metadata falls through to WordPress's 404.
		$this->assertNull( $this->dispatch( $controller, '/.well-known/oauth-authorization-server', 'GET' ) );

		$this->assertSame( 0, $this->spy_calls() );
		$this->assertSame( 0, $this->body_reads );
		foreach ( array( $mcp, $prm, $token, $register, $authorize ) as $response ) {
			$this->assertNull( $response->header( 'Location' ) );
		}
	}

	public function test_https_from_wp_config_proxy_line_is_honored() {
		// What WordPress's documented reverse-proxy line in wp-config.php sets.
		$_SERVER['HTTPS']                  = 'on';
		$_SERVER['SERVER_PORT']            = '80';
		$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';
		$controller                        = $this->controller();

		$response = $this->dispatch( $controller, '/wp-json/mainwp-remote/v1/mcp', 'POST', $this->rest( '/mainwp-remote/v1/mcp' ), '{}' );

		$this->assertSame( 200, $response->status() );
		$this->assertCount( 1, $this->spies['route_mcp']->calls );
		$this->assertSame( '{}', $this->spies['route_mcp']->calls[0]->body() );
	}

	public function test_forwarded_proto_header_alone_is_not_trusted() {
		unset( $_SERVER['HTTPS'] );
		$_SERVER['SERVER_PORT']            = '80';
		$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
		$_SERVER['HTTP_X_FORWARDED_SSL']   = 'on';
		$_SERVER['HTTP_FORWARDED']         = 'proto=https';
		$_SERVER['HTTP_CF_VISITOR']        = '{"scheme":"https"}';
		$controller                        = $this->controller();

		$response = $this->dispatch( $controller, '/wp-json/mainwp-remote/v1/mcp', 'POST', $this->rest( '/mainwp-remote/v1/mcp' ), '{}' );

		$this->assertSame( 400, $response->status() );
		$this->assertSame( 0, $this->spy_calls() );
		$this->assertSame( 0, $this->body_reads );
	}

	public function test_approve_route_dispatches_to_its_slot_with_the_authorize_rules() {
		$controller = $this->controller();

		foreach ( array( 'GET', 'POST' ) as $method ) {
			$this->dispatch( $controller, '/mainwp-oauth/approve?approval=5', $method, array(), 'decision=approve' );
		}
		$this->assertCount( 2, $this->spies['route_approve']->calls );
		$this->assertSame( 'approval=5', $this->spies['route_approve']->calls[0]->raw_query() );
		$this->assertSame( 'decision=approve', $this->spies['route_approve']->calls[1]->body() );
		$this->assertCount( 0, $this->spies['route_authorize']->calls );

		$options = $this->dispatch( $controller, '/mainwp-oauth/approve?approval=5', 'OPTIONS' );
		$this->assertSame( 405, $options->status() );
		$this->assertSame( 'GET, POST', $options->header( 'Allow' ) );
		$this->assertSame( 413, $this->dispatch( $controller, '/mainwp-oauth/approve?approval=5', 'POST', array(), str_repeat( 'a', 1048577 ) )->status() );
		$this->assertNull( $this->dispatch( $controller, '/mainwp-oauth/approve/extra', 'GET' ) );

		unset( $_SERVER['HTTPS'] );
		$_SERVER['SERVER_PORT'] = '80';
		$insecure               = $this->dispatch( $controller, '/mainwp-oauth/approve?approval=5', 'POST', array(), 'decision=approve' );
		$this->assertSame( 400, $insecure->status() );
		$this->assertSame( 'DENY', $insecure->header( 'X-Frame-Options' ) );
		$_SERVER['HTTPS'] = 'on';

		$off = $this->controller( '__return_false' );
		$this->assertSame( 404, $this->dispatch( $off, '/mainwp-oauth/approve?approval=5', 'GET' )->status() );
		$this->assertSame( 0, $this->spy_calls(), 'off: no handler runs' );

		$urls = new \MainWP\Dashboard\Remote_MCP\Remote_MCP_Urls();
		$this->assertSame( 'https://dash.example.org/mainwp-oauth/approve?approval=5', $urls->approval_url( 5 ) );

		update_option( 'permalink_structure', '' );
		$plain = $this->controller();
		$this->dispatch( $plain, '/?mainwp_oauth=approve&approval=7', 'GET' );
		$this->assertCount( 1, $this->spies['route_approve']->calls );
		$this->assertNull( $this->dispatch( $plain, '/mainwp-oauth/approve?approval=7', 'GET' ), 'the path form belongs to pretty permalinks' );
		$this->assertSame( 'https://dash.example.org/?mainwp_oauth=approve&approval=7', ( new \MainWP\Dashboard\Remote_MCP\Remote_MCP_Urls() )->approval_url( 7 ) );

		update_option( 'home', 'https://example.com/sub' );
		update_option( 'permalink_structure', '/%postname%/' );
		$this->assertSame( 'https://example.com/sub/mainwp-oauth/approve?approval=8', ( new \MainWP\Dashboard\Remote_MCP\Remote_MCP_Urls() )->approval_url( 8 ) );
		$this->assertSame( 'route_approve', $this->controller()->match( $this->wp_for( '/sub/mainwp-oauth/approve?approval=8' ) ) );
	}

	/**
	 * A WP object for a REQUEST_URI.
	 *
	 * @param string $uri REQUEST_URI.
	 * @return \WP
	 */
	private function wp_for( string $uri ): \WP {
		$_SERVER['REQUEST_URI']  = $uri;
		$_SERVER['QUERY_STRING'] = (string) wp_parse_url( $uri, PHP_URL_QUERY );
		return new \WP();
	}

	public function test_disabled_guard_leaves_another_handlers_well_known_response_intact() {
		update_option( Remote_MCP_Settings::OPTION_ENABLED, '' );
		$remote     = new Remote_MCP( $this->spy_services() );
		$remote->register_hooks();
		$controller = $remote->front_controller();
		$served     = array();

		// Another plugin's authorization server answers the well-known paths after us.
		add_action(
			'parse_request',
			function ( $wp ) use ( &$served ) {
				$served[] = $wp->request;
			},
			5
		);
		foreach ( array( '/.well-known/oauth-authorization-server', '/.well-known/openid-configuration' ) as $uri ) {
			$_SERVER['REQUEST_URI'] = $uri;
			$wp                     = new \WP();
			$wp->request            = $uri;
			$this->assertNull( $controller->not_found( $wp ), $uri . ' is not claimed while off' );
			$this->assertNull( $this->dispatch( $this->controller( '__return_false' ), $uri, 'GET' ), $uri . ' is not claimed while forced off' );
			do_action_ref_array( 'parse_request', array( &$wp ) );
		}
		$this->assertSame( array( '/.well-known/oauth-authorization-server', '/.well-known/openid-configuration' ), $served );

		// The module's own paths are still answered with the final 404.
		$_SERVER['REQUEST_URI'] = '/mainwp-oauth/authorize';
		$this->assertSame( 404, $controller->not_found( new \WP() )->status() );
		$this->assertSame( 404, $controller->not_found( $this->wp_for( '/mainwp-oauth/approve?approval=1' ) )->status() );
		$rest             = new \WP();
		$rest->query_vars = $this->rest( '/mainwp-remote/v1/mcp' );
		$this->assertSame( 404, $controller->not_found( $rest )->status() );
	}

	public function test_oversized_body_is_refused_before_dispatch() {
		$controller = $this->controller();

		$response = $this->dispatch( $controller, '/wp-json/mainwp-remote/v1/mcp', 'POST', $this->rest( '/mainwp-remote/v1/mcp' ), str_repeat( 'a', 1048577 ) );

		$this->assertSame( 413, $response->status() );
		$this->assertSame( 0, $this->spy_calls() );
	}

	public function test_unknown_module_route_is_answered_without_a_handler() {
		$controller = $this->controller();

		$response = $this->dispatch( $controller, '/wp-json/mainwp-remote/v1/other', 'POST', $this->rest( '/mainwp-remote/v1/other' ) );

		$this->assertSame( 404, $response->status() );
		$this->assertSame( 0, $this->spy_calls() );
	}

	public function test_module_routes_excluded_from_mainwp_api_key_auth() {
		global $wp;
		$saved  = $wp;
		$remote = new Remote_MCP( $this->spy_services() );
		$remote->register_hooks();
		try {
			$wp             = new \WP();
			$wp->query_vars = $this->rest( '/mainwp-remote/v1/mcp' );
			$this->assertFalse( apply_filters( 'mainwp_rest_is_request_to_rest_api', true ) );
			$this->assertSame( PHP_INT_MAX, has_filter( 'mainwp_rest_is_request_to_rest_api', array( $remote, 'exclude_module_routes' ) ) );

			$wp->query_vars = $this->rest( '/mainwp/v2/sites' );
			$this->assertTrue( apply_filters( 'mainwp_rest_is_request_to_rest_api', true ), 'API keys keep working on mainwp/v2' );
		} finally {
			$wp = $saved;
		}
	}

	// mainwp.php skips MainWP_System on these requests, so the activity log never loads and nothing would be recorded.
	public function test_module_paths_get_a_404_when_the_dashboard_did_not_boot() {
		$system = new \ReflectionProperty( \MainWP\Dashboard\MainWP_System::class, 'instance' );
		$system->setAccessible( true );
		$saved = $system->getValue();
		$system->setValue( null, null );
		try {
			$response = $this->dispatch( $this->controller(), '/wp-json/mainwp-remote/v1/mcp', 'POST', $this->rest( '/mainwp-remote/v1/mcp' ), '{}' );
		} finally {
			$system->setValue( null, $saved );
		}
		$this->assertSame( 404, $response->status() );
		$this->assertSame( 0, $this->body_reads, 'the body is never read' );
	}
}
