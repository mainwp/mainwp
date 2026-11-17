<?php
/**
 * Remote MCP on/off wiring and path matching tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\Remote_MCP;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Urls;

require_once __DIR__ . '/class-remote-mcp-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_Remote_MCP_Toggle
 */
class Test_MainWP_Remote_MCP_Toggle extends \WP_UnitTestCase {

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
			array( '/mainwp-oauth/authorize', 'POST', array() ),
			array( '/mainwp-oauth/approve?approval=3', 'GET', array() ),
		);
	}

	/**
	 * Route slot the controller picks for a path, or null.
	 *
	 * @param string $uri        REQUEST_URI.
	 * @param array  $query_vars Query vars.
	 * @return string|null
	 */
	private function slot_for( string $uri, array $query_vars = array() ): ?string {
		$_SERVER['REQUEST_URI']  = $uri;
		$_SERVER['QUERY_STRING'] = (string) wp_parse_url( $uri, PHP_URL_QUERY );
		$wp                      = new \WP();
		$wp->query_vars          = $query_vars;
		return $this->controller()->match( $wp );
	}

	public function test_disabled_paths_return_404_and_front_controller_unhooked() {
		update_option( Remote_MCP_Settings::OPTION_ENABLED, '' );
		$remote = new Remote_MCP( $this->spy_services() );
		$remote->register_hooks();
		$this->assertFalse( has_action( 'parse_request', array( $remote->front_controller(), 'on_parse_request' ) ), 'not hooked while off' );
		$this->assertSame( 1, has_action( 'parse_request', array( $remote->front_controller(), 'on_parse_request_disabled' ) ), 'only the not-found guard is' );

		// Every module path gets a final 404 from the module itself, before anything else is read:
		// the plain-permalink authorize selector would otherwise fall through to the home page.
		// Well-known paths are left to WordPress and to any other authorization server.
		$controller = $this->controller( array( Remote_MCP_Settings::class, 'is_active' ) );
		foreach ( $this->module_paths() as list( $uri, $method, $vars ) ) {
			$wp             = new \WP();
			$wp->query_vars = $vars;
			if ( false !== strpos( $uri, '/.well-known/' ) ) {
				$this->assertNull( $this->dispatch( $controller, $uri, $method, $vars, '{}' ), $uri );
				$this->assertNull( $controller->not_found( $wp ), $uri . ' through the guard' );
				continue;
			}
			$this->assert_final_404( $this->dispatch( $controller, $uri, $method, $vars, '{}' ), $uri );
			$this->assert_final_404( $controller->not_found( $wp ), $uri . ' through the guard' );
		}
		$this->assertSame( 0, $this->spy_calls() );
		$this->assertSame( 0, $this->body_reads );

		// On: hooked at priority 1 and served.
		update_option( Remote_MCP_Settings::OPTION_ENABLED, '1' );
		$on = new Remote_MCP( $this->spy_services() );
		$on->register_hooks();
		$this->assertSame( 1, has_action( 'parse_request', array( $on->front_controller(), 'on_parse_request' ) ) );
		$this->assertNotNull( $this->dispatch( $this->controller( array( Remote_MCP_Settings::class, 'is_active' ) ), '/wp-json/mainwp-remote/v1/mcp', 'POST', $this->rest( '/mainwp-remote/v1/mcp' ) ) );
	}

	/**
	 * A final 404 with nothing a client could act on.
	 *
	 * @param mixed  $response Response.
	 * @param string $label    Message.
	 */
	private function assert_final_404( $response, string $label ): void {
		$this->assertInstanceOf( \MainWP\Dashboard\Remote_MCP\Remote_MCP_Response::class, $response, $label );
		$this->assertSame( 404, $response->status(), $label );
		$this->assertNull( $response->header( 'WWW-Authenticate' ), $label );
		$this->assertNull( $response->header( 'Location' ), $label );
		$this->assertSame( 'no-store', $response->header( 'Cache-Control' ), $label );
	}

	public function test_inactive_plain_authorize_selector_gets_final_404() {
		update_option( 'permalink_structure', '' );
		update_option( Remote_MCP_Settings::OPTION_ENABLED, '' );
		$controller = $this->controller( array( Remote_MCP_Settings::class, 'is_active' ) );

		foreach ( array( '/?mainwp_oauth=authorize&client_id=x', '/?mainwp_oauth=approve&approval=3' ) as $uri ) {
			$this->assert_final_404( $this->dispatch( $controller, $uri, 'GET' ), $uri );
		}
		$this->assert_final_404( $this->dispatch( $controller, '/?rest_route=/mainwp-remote/v1/mcp', 'POST', $this->rest( '/mainwp-remote/v1/mcp' ), '{}' ), 'plain MCP' );
		$this->assertSame( 0, $this->spy_calls() );
		$this->assertSame( 0, $this->body_reads );

		// The site's own pages are untouched.
		$this->assertNull( $this->dispatch( $controller, '/?p=1', 'GET' ) );
		$this->assertNull( $this->dispatch( $controller, '/', 'GET' ) );
	}

	public function test_module_registers_no_rewrite_rules() {
		global $wp_rewrite;
		$top_before   = $wp_rewrite->extra_rules_top;
		$extra_before = $wp_rewrite->extra_rules;
		$rules_before = get_option( 'rewrite_rules' );

		$remote = new Remote_MCP( $this->spy_services() );
		$remote->register_hooks();
		do_action( 'init' );

		$this->assertSame( $top_before, $wp_rewrite->extra_rules_top );
		$this->assertSame( $extra_before, $wp_rewrite->extra_rules );
		$this->assertSame( $rules_before, get_option( 'rewrite_rules' ), 'nothing flushed' );
		// Absolute as well as relative: the module booted with the plugin, before the snapshot.
		$registered = array_merge( array_keys( (array) $wp_rewrite->extra_rules_top ), array_keys( (array) $wp_rewrite->extra_rules ), array_keys( (array) $wp_rewrite->rewrite_rules() ) );
		foreach ( $registered as $regex ) {
			$this->assertStringNotContainsString( 'well-known/oauth', $regex );
			$this->assertStringNotContainsString( 'well-known/openid', $regex );
		}
		foreach ( $registered as $regex ) {
			$this->assertStringNotContainsString( 'mainwp-oauth', $regex );
			$this->assertStringNotContainsString( 'mainwp-remote', $regex );
		}
	}

	public function test_matcher_claims_only_oauth_well_known_paths_never_acme() {
		$this->assertSame( 'route_as_metadata', $this->slot_for( '/.well-known/oauth-authorization-server' ) );
		$this->assertSame( 'route_openid', $this->slot_for( '/.well-known/openid-configuration' ) );

		foreach ( array(
			'/.well-known/acme-challenge/abc123',
			'/.well-known/',
			'/.well-known',
			'/.well-known/oauth-protected-resource',
			'/.well-known/oauth-authorization-server/extra',
			'/.well-known/oauth-authorization-serverx',
			'/.well-known/security.txt',
			'/x/.well-known/oauth-authorization-server',
			'/.WELL-KNOWN/oauth-authorization-server',
			'/mainwp-oauth/authorize/extra',
		) as $uri ) {
			$this->assertNull( $this->slot_for( $uri ), $uri );
		}
	}

	public function test_existing_openid_configuration_rule_is_not_overridden() {
		update_option(
			'rewrite_rules',
			array(
				'^\.well-known/openid-configuration/?$' => 'index.php?other_oidc=1',
				'(.?.+?)(?:/([0-9]+))?/?$'              => 'index.php?pagename=$matches[1]',
			)
		);
		$remote = new Remote_MCP( $this->spy_services() );
		$found  = $remote->refresh_competing_rules();

		$this->assertTrue( $found['openid'] );
		$this->assertFalse( $found['authorization_server'] );
		$this->assertNull( $this->slot_for( '/.well-known/openid-configuration' ), 'the other plugin keeps its path' );
		$this->assertSame( 'route_as_metadata', $this->slot_for( '/.well-known/oauth-authorization-server' ) );
		$this->assertNotContains( 'competing_authorization_server', $remote->enable(), 'a competing OIDC rule only warns' );
	}

	public function test_core_permalink_match_does_not_suppress_oauth_endpoints() {
		// Core's attachment and page rules match these paths before WordPress knows nothing is there.
		$vars = array(
			'attachment' => 'oauth-authorization-server',
			'pagename'   => 'mainwp-oauth/authorize',
		);
		$wp   = new \WP();

		$_SERVER['REQUEST_URI'] = '/.well-known/oauth-authorization-server';
		$wp->query_vars         = $vars;
		$wp->matched_rule       = '.?.+?/attachment/([^/]+)/?$';
		$this->assertSame( 'route_as_metadata', $this->controller()->match( $wp ) );

		$_SERVER['REQUEST_URI'] = '/mainwp-oauth/authorize?response_type=code';
		$wp->matched_rule       = '(.?.+?)(?:/([0-9]+))?/?$';
		$this->assertSame( 'route_authorize', $this->controller()->match( $wp ) );
	}

	public function test_competing_oauth_authorization_server_rule_blocks_enable() {
		update_option( Remote_MCP_Settings::OPTION_ENABLED, '' );
		update_option( 'rewrite_rules', array( '^\.well-known\/oauth-authorization-server$' => 'index.php?other_as=1' ) );
		$remote = new Remote_MCP( $this->spy_services() );
		$remote->refresh_competing_rules();

		$blockers = $remote->enable();

		$this->assertContains( 'competing_authorization_server', $blockers );
		$this->assertFalse( Remote_MCP_Settings::enabled() );

		// The check reruns whenever the rewrite table changes.
		update_option( 'rewrite_rules', array( '(.?.+?)(?:/([0-9]+))?/?$' => 'index.php?pagename=$matches[1]' ) );
		$this->assertFalse( $remote->refresh_competing_rules()['authorization_server'] );
		$this->assertNotContains( 'competing_authorization_server', $remote->enable() );
	}

	public function test_well_known_served_from_request_uri_when_nginx_fallback_adds_q() {
		// Local's stock nginx 404s dot-paths and hands them to index.php with q= appended.
		$this->assertSame(
			'route_as_metadata',
			$this->slot_for( '/.well-known/oauth-authorization-server?q=/.well-known/oauth-authorization-server', array( 'q' => '/.well-known/oauth-authorization-server' ) )
		);
		$this->assertSame( 'route_openid', $this->slot_for( '/.well-known/openid-configuration?q=%2F.well-known%2Fopenid-configuration' ) );
	}

	public function test_subdirectory_serves_both_path_append_forms() {
		update_option( 'home', 'https://example.com/sub' );

		$this->assertSame( 'route_as_metadata', $this->slot_for( '/sub/.well-known/oauth-authorization-server' ) );
		$this->assertSame( 'route_openid', $this->slot_for( '/sub/.well-known/openid-configuration' ) );
		$this->assertSame( 'route_as_metadata', $this->slot_for( '/.well-known/oauth-authorization-server/sub' ) );
		$this->assertSame( 'route_openid', $this->slot_for( '/.well-known/openid-configuration/sub' ) );
		$this->assertSame( 'route_authorize', $this->slot_for( '/sub/mainwp-oauth/authorize' ) );
		$this->assertNull( $this->slot_for( '/mainwp-oauth/authorize' ) );
	}

	public function test_nested_install_answers_path_insert_only_for_its_own_path() {
		update_option( 'home', 'https://example.com/sub' );

		$this->assertNull( $this->slot_for( '/.well-known/oauth-authorization-server' ), 'the root form belongs to the root install' );
		$this->assertNull( $this->slot_for( '/.well-known/openid-configuration' ) );
		$this->assertNull( $this->slot_for( '/.well-known/oauth-authorization-server/other' ) );
		$this->assertNull( $this->slot_for( '/.well-known/oauth-authorization-server/sub/deeper' ) );
		$this->assertNull( $this->slot_for( '/.well-known/oauth-authorization-server/SUB' ) );
		$this->assertSame( 'route_as_metadata', $this->slot_for( '/.well-known/oauth-authorization-server/sub' ) );
	}

	public function test_plain_permalinks_use_rest_route_resource_and_query_authorize_endpoint() {
		update_option( 'permalink_structure', '' );
		$urls = new Remote_MCP_Urls();

		$this->assertSame( 'https://dash.example.org/?rest_route=/mainwp-remote/v1/mcp', $urls->mcp_url() );
		$this->assertSame( 'https://dash.example.org/?rest_route=/mainwp-remote/v1/oauth/token', $urls->token_endpoint() );
		$this->assertSame( 'https://dash.example.org/?rest_route=/mainwp-remote/v1/oauth-protected-resource', $urls->prm_url() );
		$this->assertSame( 'https://dash.example.org/?mainwp_oauth=authorize', $urls->authorization_endpoint() );
		$this->assertSame( 'https://dash.example.org', $urls->issuer(), 'the issuer stays query-free' );

		$this->assertSame( 'route_authorize', $this->slot_for( '/?mainwp_oauth=authorize&client_id=x' ) );
		$this->assertSame( 'route_mcp', $this->slot_for( '/?rest_route=/mainwp-remote/v1/mcp', $this->rest( '/mainwp-remote/v1/mcp' ) ) );
		$this->assertNull( $this->slot_for( '/?mainwp_oauth=other' ) );
		$this->assertNull( $this->slot_for( '/?mainwp_oauth=authorize&mainwp_oauth=authorize' ), 'a repeated selector is not ours' );

		// The query form belongs to plain permalinks only.
		update_option( 'permalink_structure', '/%postname%/' );
		$this->assertNull( $this->slot_for( '/?mainwp_oauth=authorize' ) );
	}
}
