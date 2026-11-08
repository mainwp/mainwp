<?php
/**
 * Well-known metadata served through the front controller.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\MCP_Front_Controller;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Response;

require_once __DIR__ . '/class-oauth-authorize-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName, WordPress.Files.FileName.NotHyphenatedLowercase

/**
 * Class Test_MainWP_OAuth_Well_Known
 */
class Test_MainWP_OAuth_Well_Known extends OAuth_Authorize_Test_Case {

	/**
	 * Dispatch a GET through the front controller with the real metadata handlers.
	 *
	 * @param string $uri        REQUEST_URI.
	 * @param array  $query_vars Query vars WordPress resolved.
	 * @return Remote_MCP_Response|null
	 */
	private function get_path( string $uri, array $query_vars = array() ): ?Remote_MCP_Response {
		$_SERVER['REQUEST_URI']    = $uri;
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['QUERY_STRING']   = (string) wp_parse_url( $uri, PHP_URL_QUERY );
		$wp                        = new \WP();
		$wp->query_vars            = $query_vars;
		$controller                = new MCP_Front_Controller( $this->services(), '__return_true' );
		return $controller->dispatch(
			$wp,
			function () {
				return '';
			}
		);
	}

	/**
	 * Metadata document served at a path.
	 *
	 * @param string $uri        REQUEST_URI.
	 * @param array  $query_vars Query vars.
	 * @return array
	 */
	private function document_at( string $uri, array $query_vars = array() ): array {
		$response = $this->get_path( $uri, $query_vars );
		$this->assertInstanceOf( Remote_MCP_Response::class, $response, $uri );
		$this->assertSame( 200, $response->status(), $uri . ': ' . $response->body() );
		$document = json_decode( $response->body(), true );
		$this->assertIsArray( $document, $uri );
		return $document;
	}

	public function test_well_known_served_from_request_uri_when_nginx_fallback_adds_q() {
		// Local's stock nginx 404s dot-paths and hands them to index.php with q= appended.
		$document = $this->document_at( '/.well-known/oauth-authorization-server?q=/.well-known/oauth-authorization-server', array( 'q' => '/.well-known/oauth-authorization-server' ) );
		$this->assertSame( self::HOME, $document['issuer'] );
		$this->assertSame( self::HOME . '/mainwp-oauth/authorize', $document['authorization_endpoint'] );

		$document = $this->document_at( '/.well-known/openid-configuration?q=%2F.well-known%2Fopenid-configuration' );
		$this->assertSame( self::HOME, $document['issuer'] );
	}

	public function test_subdirectory_serves_both_path_append_forms() {
		$this->set_site_option( 'home', 'https://example.com/sub' );
		$base = 'https://example.com/sub';

		// ChatGPT asks for RFC 8414 path-append, claude.ai and Claude Code for OIDC path-append.
		foreach ( array( '/sub/.well-known/oauth-authorization-server', '/sub/.well-known/openid-configuration', '/.well-known/oauth-authorization-server/sub', '/.well-known/openid-configuration/sub' ) as $uri ) {
			$document = $this->document_at( $uri );
			$this->assertSame( $base, $document['issuer'], $uri );
			$this->assertSame( $base . '/mainwp-oauth/authorize', $document['authorization_endpoint'], $uri );
			$this->assertSame( $base . '/wp-json/mainwp-remote/v1/oauth/token', $document['token_endpoint'], $uri );
		}
	}

	public function test_nested_install_answers_path_insert_only_for_its_own_path() {
		$this->set_site_option( 'home', 'https://example.com/sub' );

		// The root forms belong to whatever answers at the root.
		$this->assertNull( $this->get_path( '/.well-known/oauth-authorization-server' ) );
		$this->assertNull( $this->get_path( '/.well-known/openid-configuration' ) );
		$this->assertNull( $this->get_path( '/.well-known/oauth-authorization-server/other' ) );
		$this->assertSame( 'https://example.com/sub', $this->document_at( '/.well-known/oauth-authorization-server/sub' )['issuer'] );
	}

	public function test_plain_permalinks_use_rest_route_resource_and_query_authorize_endpoint() {
		$this->set_site_option( 'permalink_structure', '' );

		$document = $this->document_at( '/.well-known/oauth-authorization-server' );
		$this->assertSame( self::HOME, $document['issuer'], 'the issuer stays query-free' );
		$this->assertSame( self::HOME . '/?mainwp_oauth=authorize', $document['authorization_endpoint'] );
		$this->assertSame( self::HOME . '/?rest_route=/mainwp-remote/v1/oauth/token', $document['token_endpoint'] );
		$this->assertSame( self::HOME . '/?rest_route=/mainwp-remote/v1/register', $document['registration_endpoint'] );

		$prm = $this->document_at( '/?rest_route=/mainwp-remote/v1/oauth-protected-resource', array( 'rest_route' => '/mainwp-remote/v1/oauth-protected-resource' ) );
		$this->assertSame( self::HOME . '/?rest_route=/mainwp-remote/v1/mcp', $prm['resource'] );
		$this->assertSame( array( self::HOME ), $prm['authorization_servers'] );
	}
}
