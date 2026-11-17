<?php
/**
 * Authorization endpoint flow tests: error delivery, login hand-off and HTTPS.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\MCP_Front_Controller;

require_once __DIR__ . '/class-oauth-authorize-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName, WordPress.Files.FileName.NotHyphenatedLowercase

/**
 * Class Test_MainWP_OAuth_Authorize_Endpoint
 */
class Test_MainWP_OAuth_Authorize_Endpoint extends OAuth_Authorize_Test_Case {

	public function test_unverified_client_error_renders_page_without_redirect() {
		// Until the client and its redirect are verified, no error may travel to the redirect URI.
		$this->assert_error_page(
			$this->authorize(
				array(
					'client_id' => 'https://evil.example/client.json',
					'scope'     => 'bogus',
				)
			),
			'unsupported_client'
		);
		$this->assert_error_page(
			$this->authorize(
				array(
					'redirect_uri' => 'https://attacker.example/cb',
					'scope'        => 'bogus',
				)
			),
			'redirect_mismatch'
		);
		$this->clients->allow_redirects = false;
		$this->assert_error_page( $this->authorize( array( 'scope' => 'bogus' ) ), 'redirect_mismatch', 'the client lookup refuses the redirect' );
		$this->assertSame( array(), $this->requests() );
	}

	public function test_verified_client_errors_redirect_with_state_and_iss() {
		$query = $this->assert_client_redirect( $this->authorize( array( 'scope' => 'bogus' ) ), self::REDIRECT );
		$this->assertSame( 'invalid_scope', ( $query['error'] ?? '' ) );
		$this->assertSame( 'client-state-123', ( $query['state'] ?? '' ) );
		$this->assertSame( self::HOME, ( $query['iss'] ?? '' ) );
		$this->assertNotEmpty( ( $query['error_description'] ?? '' ) );

		foreach ( array( 'request', 'request_uri' ) as $name ) {
			$response = $this->get_raw( $this->query( $this->auth_params() ) . '&' . $name . '=x' );
			$query    = $this->assert_client_redirect( $response, self::REDIRECT );
			$this->assertSame( 'request_not_supported', ( $query['error'] ?? '' ), $name );
			$this->assertSame( self::HOME, ( $query['iss'] ?? '' ) );
		}

		// Without a state there is none to echo.
		$query = $this->assert_client_redirect(
			$this->authorize(
				array(
					'scope' => 'bogus',
					'state' => null,
				)
			),
			self::REDIRECT
		);
		$this->assertArrayNotHasKey( 'state', $query );
		$this->assertSame( array(), $this->requests() );
	}

	public function test_logged_out_user_with_known_client_goes_to_login() {
		wp_set_current_user( 0 );
		$query    = $this->query( $this->auth_params() );
		$response = $this->get_raw( $query );

		$this->assertSame( 302, $response->status() );
		$location = (string) $response->header( 'Location' );
		$this->assertStringStartsWith( self::HOME . '/wp-login.php?', $location, 'our own login, never the client' );
		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $login );
		$this->assertSame( self::HOME . '/mainwp-oauth/authorize?' . $query, ( $login['redirect_to'] ?? '' ) );
		$this->assertSame( array(), $this->requests(), 'nothing stored before login' );

		$this->set_site_option( 'permalink_structure', '' );
		$query    = 'mainwp_oauth=authorize&' . $this->query( $this->auth_params( array( 'resource' => self::HOME . '/?rest_route=/mainwp-remote/v1/mcp' ) ) );
		$response = $this->get_raw( $query );
		parse_str( (string) wp_parse_url( (string) $response->header( 'Location' ), PHP_URL_QUERY ), $login );
		$this->assertSame( self::HOME . '/?' . $query, ( $login['redirect_to'] ?? '' ), 'the plain-permalink form returns to itself' );
	}

	public function test_http_authorize_request_renders_error_without_client_lookup() {
		$_SERVER['HTTPS']       = '';
		$_SERVER['SERVER_PORT'] = '80';
		$this->assert_error_page( $this->authorize(), 'https_required', 'handler reached directly' );
		$this->assertSame( array(), $this->clients->lookups );

		// Through the front controller the request never reaches the handler at all.
		$_SERVER['REQUEST_URI']    = '/mainwp-oauth/authorize?' . $this->query( $this->auth_params() );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['QUERY_STRING']   = $this->query( $this->auth_params() );
		$controller                = new MCP_Front_Controller( $this->services(), '__return_true' );
		$response                  = $controller->dispatch(
			new \WP(),
			function () {
				return '';
			}
		);
		$this->assertSame( 400, $response->status() );
		$this->assertNull( $response->header( 'Location' ) );
		$this->assertSame( array(), $this->clients->lookups );
		$this->assertSame( array(), $this->requests() );
	}
}
