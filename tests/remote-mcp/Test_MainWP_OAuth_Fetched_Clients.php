<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase -- PHPUnit test filenames follow the suite convention.
/**
 * Authorization-time client metadata fetching tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\MCP_Bearer_Auth;
use MainWP\Dashboard\Remote_MCP\OAuth_Known_Clients;
use MainWP\Dashboard\Remote_MCP\OAuth_Registration_Rate_Limiter;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Request;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings;

require_once __DIR__ . '/class-oauth-authorize-test-support.php';

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- Private helpers and test names describe the assertions.

/**
 * Class Test_MainWP_OAuth_Fetched_Clients
 */
class Test_MainWP_OAuth_Fetched_Clients extends OAuth_Authorize_Test_Case {

	const CLIENT_ID = 'https://app.example.org/oauth/client.json';

	/**
	 * HTTP attempts, including attempts core refuses before transport.
	 *
	 * @var array
	 */
	private $http = array();

	/**
	 * Optional HTTP answer; null serves a valid document for the requested URL.
	 *
	 * @var array|\WP_Error|false|null
	 */
	private $http_answer;

	public function set_up() {
		parent::set_up();
		$_SERVER['REMOTE_ADDR'] = '192.0.2.225';
		$this->http             = array();
		$this->http_answer      = null;
		$this->fresh_clients();
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				$this->http[] = array( $url, $args );
				return null === $this->http_answer ? $this->json_response( $this->document( $url ) ) : $this->http_answer;
			},
			10,
			3
		);
	}

	protected function auth_params( array $overrides = array() ): array {
		return parent::auth_params( array_merge( array( 'client_id' => self::CLIENT_ID ), $overrides ) );
	}

	private function fresh_clients(): void {
		$this->clients           = new OAuth_Known_Clients( $this->services() );
		$this->services_instance = null;
	}

	private function document( string $client_id = self::CLIENT_ID, string $name = 'Example app' ): string {
		return wp_json_encode(
			array(
				'client_id'                  => $client_id,
				'client_name'                => $name,
				'redirect_uris'              => array( 'http://127.0.0.1/callback', 'https://app.example.org/callback' ),
				'token_endpoint_auth_method' => 'none',
			)
		);
	}

	private function json_response( string $body, string $type = 'application/json', int $status = 200 ): array {
		return array(
			'headers'  => array( 'content-type' => $type ),
			'body'     => $body,
			'response' => array( 'code' => $status ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private function assert_reaches_consent( array $overrides = array() ): void {
		$response = $this->authorize( $overrides );
		$this->assertSame( 200, $response->status(), 'fetched client reaches consent' );
		$this->assertStringContainsString( 'data-page="consent"', $response->body() );
	}

	private function token( array $fields ): array {
		$this->fresh_clients();
		$response = $this->handler( 'route_token' )->handle(
			new Remote_MCP_Request( 'route_token', 'POST', '', $this->query( $fields ), array_merge( $_SERVER, array( 'CONTENT_TYPE' => 'application/x-www-form-urlencoded' ) ) )
		);
		$this->assertSame( 200, $response->status(), $response->body() );
		return json_decode( $response->body(), true );
	}

	public function test_fetched_client_authorize_exchange_refresh_and_bearer_without_refetch() {
		$consent = $this->consent();
		$this->assertCount( 1, $this->http );
		$args = $this->http[0][1];
		$this->assertSame( 'GET', $args['method'] );
		$this->assertSame( 0, $args['redirection'] );
		$this->assertSame( 5, $args['timeout'] );
		$this->assertSame( 16385, $args['limit_response_size'] );
		$this->assertSame( 'application/json', $args['headers']['Accept'] );
		$this->assertTrue( $args['reject_unsafe_urls'] );
		$this->fresh_clients();
		$query = $this->assert_client_redirect( $this->decide( $consent ), self::REDIRECT );
		$this->assertNotEmpty( $query['code'] );
		delete_transient( 'mainwp_remote_mcp_cimd_' . md5( self::CLIENT_ID ) );
		$this->http        = array();
		$this->http_answer = new \WP_Error( 'http_request_failed', 'offline' );
		$tokens            = $this->token(
			array(
				'grant_type'    => 'authorization_code',
				'client_id'     => self::CLIENT_ID,
				'code'          => $query['code'],
				'code_verifier' => self::VERIFIER,
				'redirect_uri'  => self::REDIRECT,
				'resource'      => self::RESOURCE,
			)
		);
		$tokens            = $this->token(
			array(
				'grant_type'    => 'refresh_token',
				'client_id'     => self::CLIENT_ID,
				'refresh_token' => $tokens['refresh_token'],
			)
		);
		$this->fresh_clients();
		$descriptor = $this->clients->lookup( self::CLIENT_ID );
		$this->assertSame( 'bundled', $descriptor['kind'] );
		$this->assertTrue( $descriptor['fetched'] );
		$this->assertNull( $descriptor['client_name'] );
		$this->assertSame( array(), $descriptor['redirect_uris'] );
		$this->assertFalse( $this->clients->redirect_allowed( $descriptor, self::REDIRECT ) );
		$auth   = new MCP_Bearer_Auth( $this->services() );
		$result = $auth->authenticate( new Remote_MCP_Request( 'route_mcp', 'POST', '', '{}', array_merge( $_SERVER, array( 'HTTP_AUTHORIZATION' => 'Bearer ' . $tokens['access_token'] ) ) ) );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( array(), $this->http, 'token and MCP paths stay fetch-free after cache expiry' );
	}

	public function test_fetched_client_registration_switch_blocks_cached_and_uncached_clients() {
		$this->assert_reaches_consent();
		update_option( Remote_MCP_Settings::OPTION_REGISTRATION_ENABLED, '0' );
		$this->http = array();
		$this->fresh_clients();
		$this->assert_error_page( $this->authorize(), 'unsupported_client' );
		delete_transient( 'mainwp_remote_mcp_cimd_' . md5( self::CLIENT_ID ) );
		$this->fresh_clients();
		$this->assert_error_page( $this->authorize(), 'unsupported_client' );
		$this->assertSame( array(), $this->http );
	}

	public function test_fetched_client_failures_are_counted_without_audit_or_cache() {
		$failures = array(
			$this->json_response( $this->document( 'https://other.example.org/client.json' ) ),
			$this->json_response( $this->document(), 'text/html' ),
			array_merge( $this->json_response( $this->document() ), array( 'headers' => array( 'content-type' => array( 'application/json', 'application/json' ) ) ) ),
			$this->json_response( str_pad( $this->document(), 16385, ' ' ) ),
			$this->json_response( $this->document(), 'application/json', 302 ),
			$this->json_response( $this->document(), 'application/json', 503 ),
			new \WP_Error( 'http_request_failed', 'offline' ),
		);
		foreach ( $failures as $index => $answer ) {
			$this->http_answer = $answer;
			$this->fresh_clients();
			$this->assert_error_page( $this->authorize(), 'unsupported_client', (string) $index );
			$this->assertSame( $index + 1, Remote_MCP_Settings::counters()['unknown_client'] );
			$this->assertFalse( get_transient( 'mainwp_remote_mcp_cimd_' . md5( self::CLIENT_ID ) ) );
			$this->assertSame( array(), $this->audits );
			$this->assertCount( $index + 1, $this->http, 'each uncached failure attempts HTTP' );
		}
		foreach ( array( 'application/json', 'application/json; charset=utf-8', 'application/oauth-client+json', 'application/oauth-client+json; charset=utf-8' ) as $type ) {
			$this->http_answer = $this->json_response( str_pad( $this->document(), 16384, ' ' ), $type );
			$this->fresh_clients();
			$this->assert_reaches_consent();
			$expires = (int) get_option( '_transient_timeout_mainwp_remote_mcp_cimd_' . md5( self::CLIENT_ID ) );
			$this->assertGreaterThanOrEqual( time() + HOUR_IN_SECONDS - 5, $expires );
			$this->assertLessThanOrEqual( time() + HOUR_IN_SECONDS, $expires );
			delete_transient( 'mainwp_remote_mcp_cimd_' . md5( self::CLIENT_ID ) );
		}
	}

	public function test_fetched_client_url_rules_and_core_private_address_refusal() {
		foreach ( array(
			'http://app.example.org/client.json',
			'https://app.example.org:8443/client.json',
			'https://user@app.example.org/client.json',
			'https://app.example.org/../client.json',
			'https://app.example.org/./client.json',
			'https://app.example.org/client.json#fragment',
			'https://app.example.org',
			'https://app.example.org/' . str_repeat( 'x', 490 ),
		) as $id ) {
			$this->assert_error_page( $this->authorize( array( 'client_id' => $id ) ), 'unsupported_client', $id );
		}
		$this->assertSame( array(), $this->http, 'invalid syntax is refused before HTTP' );
		$this->http_answer = false;
		$transport         = array();
		add_action(
			'http_api_debug',
			function ( $response ) use ( &$transport ) {
				$transport[] = $response;
			},
			10,
			1
		);
		foreach ( array( 'https://127.0.0.1/x', 'https://10.0.0.1/x' ) as $id ) {
			$this->assert_error_page( $this->authorize( array( 'client_id' => $id ) ), 'unsupported_client', $id );
		}
		$this->assertCount( 2, $this->http, 'private URLs reach core safety validation' );
		$this->assertCount( 2, $transport );
		foreach ( $transport as $response ) {
			$this->assertWPError( $response );
			$this->assertSame( 'http_request_failed', $response->get_error_code() );
			$this->assertSame( __( 'A valid URL was not provided.' ), $response->get_error_message() );
		}
		$this->http_answer = null;
		$this->assert_reaches_consent( array( 'client_id' => 'https://app.example.org:443/client.json' ) );
	}

	public function test_fetched_client_fetch_leaves_bundled_clients_offline() {
		foreach ( array_keys( OAuth_Known_Clients::REGISTRY ) as $id ) {
			$client = $this->clients->lookup( $id, true );
			$this->assertSame( 'bundled', $client['kind'] );
			$this->assertArrayNotHasKey( 'fetched', $client );
		}
		$this->assert_reaches_consent(
			array(
				'client_id'    => self::CLAUDE_AI,
				'redirect_uri' => self::CLAUDE_AI_CB,
			)
		);
		$this->assertSame( array(), $this->http );
		$this->assert_reaches_consent();
		$this->assertCount( 1, $this->http );
	}

	public function test_fetched_client_redirects_require_document_match_and_allow_loopback_port() {
		$response = $this->authorize( array( 'redirect_uri' => 'https://other.example.org/callback' ) );
		$this->assert_error_page( $response, 'redirect_mismatch' );
		$this->assertStringNotContainsString( 'updating MainWP Dashboard may fix this', $response->body() );
		$this->assert_reaches_consent( array( 'redirect_uri' => 'http://127.0.0.1:61234/callback' ) );
		$this->assert_reaches_consent( array( 'redirect_uri' => 'https://app.example.org/callback' ) );
		$this->assertCount( 1, $this->http );
	}

	public function test_fetched_client_malformed_authorization_never_fetches() {
		foreach ( array(
			array( 'resource' => 'https://other.example.org/mcp' ),
			array( 'code_challenge' => 'bad' ),
			array( 'redirect_uri' => 'invalid' ),
			array( 'response_type' => 'token' ),
			array( 'state' => str_repeat( 's', 2049 ) ),
		) as $index => $overrides ) {
			$this->assert_error_page( $this->authorize( $overrides ), array( 'invalid_target', 'invalid_code_challenge', 'invalid_redirect_uri', 'unsupported_response_type', 'invalid_state' )[ $index ] );
		}
		$this->assertSame( array(), $this->http );
		$this->assert_reaches_consent();
	}

	public function test_fetched_client_rate_limit_counts_fetches_and_exempts_cache_hits() {
		add_filter(
			'mainwp_remote_mcp_client_ip',
			static function () {
				return '198.51.100.225';
			}
		);
		$this->assert_reaches_consent();
		for ( $index = 0; $index < OAuth_Registration_Rate_Limiter::LIMIT + 1; ++$index ) {
			$this->fresh_clients();
			$this->assert_reaches_consent();
		}
		$this->assertCount( 1, $this->http );
		for ( $index = 1; $index < OAuth_Registration_Rate_Limiter::LIMIT; ++$index ) {
			$this->fresh_clients();
			$this->assert_reaches_consent( array( 'client_id' => self::CLIENT_ID . '?i=' . $index ) );
		}
		$this->assertCount( OAuth_Registration_Rate_Limiter::LIMIT, $this->http );
		$this->fresh_clients();
		$this->assert_error_page( $this->authorize( array( 'client_id' => self::CLIENT_ID . '?over=1' ) ), 'unsupported_client' );
		$this->assertCount( OAuth_Registration_Rate_Limiter::LIMIT, $this->http );
		$this->assertSame( 0, ( new OAuth_Registration_Rate_Limiter() )->retry_after( '198.51.100.225' ), 'registration bucket is independent' );
		$this->fresh_clients();
		$this->assert_reaches_consent();
		remove_all_filters( 'mainwp_remote_mcp_client_ip' );
		$this->fresh_clients();
		$this->assert_reaches_consent( array( 'client_id' => self::CLIENT_ID . '?new-address=1' ) );
	}

	public function test_fetched_client_consent_escapes_name_and_identifies_host() {
		$name              = '<script>alert("x")</script>' . str_repeat( 'n', 100 );
		$this->http_answer = $this->json_response( $this->document( self::CLIENT_ID, $name ) );
		$consent           = $this->consent();
		$this->assertStringContainsString( 'An app at <strong>app.example.org</strong> (not checked by MainWP)', $consent['body'] );
		$this->assertStringContainsString( esc_html( mb_substr( $name, 0, 80 ) ), $consent['body'] );
		$this->assertStringNotContainsString( '<script>', $consent['body'] );
		$this->assertStringNotContainsString( esc_html( mb_substr( $name, 0, 81 ) ), $consent['body'] );
	}

	public function test_fetched_client_approval_refetches_after_cache_expiry() {
		$consent = $this->consent();
		delete_transient( 'mainwp_remote_mcp_cimd_' . md5( self::CLIENT_ID ) );
		$this->fresh_clients();
		$query = $this->assert_client_redirect( $this->decide( $consent ), self::REDIRECT );
		$this->assertNotEmpty( $query['code'] );
		$this->assertCount( 2, $this->http );
		$this->assertIsArray( get_transient( 'mainwp_remote_mcp_cimd_' . md5( self::CLIENT_ID ) ) );
	}
}
