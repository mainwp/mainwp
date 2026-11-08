<?php
/**
 * Remote MCP bearer authentication tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\MCP_Bearer_Auth;
use MainWP\Dashboard\Remote_MCP\MCP_Endpoint;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings;

require_once __DIR__ . '/fixtures/endpoint-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_MCP_Bearer_Auth
 */
class Test_MainWP_MCP_Bearer_Auth extends \WP_UnitTestCase {

	use Remote_MCP_Endpoint_Fixtures;

	const RESOURCE  = 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp';
	const CLIENT_ID = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
	const REDIRECT  = 'https://claude.ai/api/mcp/auth_callback';
	const VERIFIER  = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz';

	/**
	 * A legacy ping, the cheapest authenticated request.
	 *
	 * @param string|null $token    Token.
	 * @param array       $headers  Extra server keys.
	 * @param mixed       $endpoint Endpoint.
	 * @return \MainWP\Dashboard\Remote_MCP\Remote_MCP_Response
	 */
	private function ping( ?string $token, array $headers = array(), $endpoint = null ) {
		return $this->legacy( 'ping', array(), $token, $endpoint, array_merge( array( 'HTTP_MCP_PROTOCOL_VERSION' => '2025-11-25' ), $headers ) );
	}

	public function test_header_recovered_from_redirect_variants_and_getallheaders() {
		$auth  = new MCP_Bearer_Auth( $this->services() );
		$fake  = 'mwpat_' . str_repeat( 'A', 43 );
		$cases = array(
			array( 'HTTP_AUTHORIZATION' => 'Bearer ' . $fake ),
			array( 'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer ' . $fake ),
			array( 'REDIRECT_REDIRECT_HTTP_AUTHORIZATION' => 'bearer ' . $fake ),
		);
		foreach ( $cases as $server ) {
			$this->assertSame(
				array(
					'state' => 'ok',
					'token' => $fake,
				),
				$auth->recover( $server ),
				'recovered from ' . key( $server )
			);
		}

		$headers          = new class( $this->services() ) extends MCP_Bearer_Auth {
			public $headers = array();
			protected function all_headers(): array {
				return $this->headers;
			}
		};
		$headers->headers = array( 'authorization' => 'BEARER ' . $fake );
		$this->assertSame( $fake, $headers->recover( array() )['token'], 'getallheaders, matched case-insensitively' );

		$this->assertSame( 'missing', $auth->recover( array() )['state'] );
		$this->assertSame( 'invalid', $auth->recover( array( 'HTTP_AUTHORIZATION' => 'Bearer ' . $fake . ', Bearer ' . $fake ) )['state'], 'two credentials' );
		$this->assertSame( 'invalid', $auth->recover( array( 'HTTP_AUTHORIZATION' => 'Basic dXNlcjpwYXNz' ) )['state'], 'other scheme' );
		$this->assertSame( 'invalid', $auth->recover( array( 'HTTP_AUTHORIZATION' => 'Bearer mwpat_' . str_repeat( '%', 43 ) ) )['state'], 'charset checked before lookup' );

		$token    = $this->token();
		$response = $this->ping( null, array( 'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer ' . $token ) );
		$this->assertSame( 200, $response->status(), 'a real token in the redirect variant authenticates' );
	}

	public function test_only_bearer_scheme_is_case_insensitive() {
		$auth     = new MCP_Bearer_Auth( $this->services() );
		$warnings = array();
		set_error_handler(
			function ( $severity, $message ) use ( &$warnings ) {
				$warnings[] = $message;
				return true;
			}
		);
		try {
			foreach ( array( 'MWPAT_', 'MwpAt_', 'MWPCT_', 'MwpcT_' ) as $prefix ) {
				$response = $this->ping( $prefix . str_repeat( 'A', 43 ) );
				$this->assertSame( 401, $response->status(), $prefix );
				$this->assertStringContainsString( 'invalid_token', (string) $response->header( 'WWW-Authenticate' ) );
			}
		} finally {
			restore_error_handler();
		}
		$this->assertSame( array(), $warnings, 'invalid token prefixes must not cause a PHP warning' );
		foreach ( array( 'Bearer', 'bearer', 'BEARER', 'bEaReR' ) as $scheme ) {
			foreach ( array( 'mwpat_', 'mwpct_' ) as $prefix ) {
				$token = $prefix . str_repeat( 'aZ', 21 ) . '_';
				$this->assertSame(
					array(
						'state' => 'ok',
						'token' => $token,
					),
					$auth->recover( array( 'HTTP_AUTHORIZATION' => $scheme . ' ' . $token ) )
				);
			}
		}
		foreach ( array( 'MWPAT_', 'MwpAt_', 'MWPCT_', 'MwpcT_' ) as $prefix ) {
			$this->assertSame(
				array(
					'state' => 'invalid',
					'token' => null,
				),
				$auth->recover( array( 'HTTP_AUTHORIZATION' => 'Bearer ' . $prefix . str_repeat( 'A', 43 ) ) )
			);
		}
	}

	public function test_missing_header_logged_separately_from_bad_token() {
		$this->ping( null );
		$this->ping( null );
		$this->ping( 'mwpat_' . str_repeat( 'B', 43 ) );
		$counts = Remote_MCP_Settings::counters();
		$this->assertSame( 2, $counts['auth_header_missing'] );
		$this->assertSame( 1, $counts['auth_token_rejected'] );
	}

	public function test_request_with_a_header_is_noted_and_one_without_is_not() {
		$this->ping( null );
		$this->assertSame( 0, Remote_MCP_Settings::counters()['auth_header_received'], 'discovery proves nothing about the header' );
		$this->ping( 'mwpat_' . str_repeat( 'B', 43 ) );
		$this->assertSame( 1, Remote_MCP_Settings::counters()['auth_header_received'], 'even a rejected token got here' );
	}

	public function test_token_in_query_string_is_rejected_unread() {
		$token  = $this->token();
		$before = $this->grant( $this->store->check_access_token( $token )['grant_id'] );
		$lookup = 0;
		add_filter(
			'query',
			function ( $sql ) use ( &$lookup ) {
				if ( false !== strpos( $sql, 'token_hash' ) ) {
					++$lookup;
				}
				return $sql;
			}
		);
		$response = $this->raw(
            (string) wp_json_encode(
                array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'ping',
                )
            ),
            $token,
            array(),
            null,
            'access_token=' . rawurlencode( $token )
        );
		$this->assertSame( 400, $response->status() );
		$this->assertStringContainsString( 'invalid_request', (string) $response->header( 'WWW-Authenticate' ) );
		$this->assertSame( 0, $lookup, 'no token lookup, even with a valid header present' );
		$this->assertSame( $before['last_used_at'], $this->grant( $before['id'] )['last_used_at'] );
	}

	public function test_token_in_body_is_rejected_unread() {
		$token  = $this->token();
		$lookup = 0;
		add_filter(
			'query',
			function ( $sql ) use ( &$lookup ) {
				if ( false !== strpos( $sql, 'token_hash' ) ) {
					++$lookup;
				}
				return $sql;
			}
		);
		$response = $this->raw( 'access_token=' . rawurlencode( $token ), $token, array( 'CONTENT_TYPE' => 'application/x-www-form-urlencoded' ) );
		$this->assertSame( 400, $response->status() );
		$this->assertStringContainsString( 'invalid_request', (string) $response->header( 'WWW-Authenticate' ) );
		$this->assertSame( 0, $lookup );
	}

	public function test_refresh_token_cannot_authenticate_mcp() {
		$connection = $this->connection();
		$before     = $this->grant( $connection['grant_id'] );
		$response = $this->ping( $connection['refresh_token'] );
		$this->assertSame( 401, $response->status() );
		$this->assertStringContainsString( 'error="invalid_token"', (string) $response->header( 'WWW-Authenticate' ) );
		$after = $this->grant( $connection['grant_id'] );
		$this->assertSame( $before['version'], $after['version'], 'no grant write' );
		$this->assertSame( $before['current_used'], $after['current_used'] );
	}

	public function test_unauthenticated_request_gets_401_with_resource_metadata_and_scope() {
		$response = $this->ping( null );
		$this->assertSame( 401, $response->status() );
		$this->assertSame(
			'Bearer resource_metadata="https://dash.example.org/wp-json/mainwp-remote/v1/oauth-protected-resource", scope="mainwp:read mainwp:write"',
			$response->header( 'WWW-Authenticate' )
		);
		$this->assertSame( 'no-store', $response->header( 'Cache-Control' ) );
		$this->assertStringNotContainsString( 'offline_access', (string) $response->header( 'WWW-Authenticate' ) );
	}

	public function test_expired_token_gets_401_invalid_token() {
		$token = $this->token();
		$this->assertSame( 200, $this->ping( $token )->status() );
		$this->tick( 1801 );
		$response = $this->ping( $token );
		$this->assertSame( 401, $response->status() );
		$this->assertSame(
			'Bearer resource_metadata="https://dash.example.org/wp-json/mainwp-remote/v1/oauth-protected-resource", scope="mainwp:read mainwp:write", error="invalid_token"',
			$response->header( 'WWW-Authenticate' )
		);
	}

	/**
	 * Exercise the endpoint resource check independently of the store's token hashing.
	 *
	 * @param array  $checked Store success result from the original Dashboard.
	 * @param string $home    Dashboard receiving the copied credential.
	 */
	private function assert_resource_check_refuses_store_success( array $checked, string $home ) {
		$store          = new class( $this->hasher, self::RESOURCE, $this->clock ) extends Remote_MCP_Test_Store {
			public $checked;
			public function check_access_token( string $token ): array {
				return $this->checked;
			}
		};
		$store->checked = $checked;
		$endpoint       = $this->endpoint( $this->services( array(), $home, $store ) );
		$response       = $this->ping( 'mwpat_' . str_repeat( 'A', 43 ), array(), $endpoint );
		$this->assertSame( 401, $response->status(), 'the endpoint must reject a store success for a different Dashboard resource' );
	}

	public function test_token_for_other_resource_is_rejected() {
		$other       = $this->new_store( 'https://other.example.org/wp-json/mainwp-remote/v1/mcp' );
		$this->store = $other;
		$token       = $this->token();
		$checked     = $other->check_access_token( $token );
		$checked['resource'] = 'https://other.example.org/wp-json/mainwp-remote/v1/mcp';
		$this->store = $this->new_store();
		$this->assert_resource_check_refuses_store_success( $checked, 'https://dash.example.org' );
		$response = $this->ping( $token, array(), $this->endpoint( $this->services( array(), null, $this->store ) ) );
		$this->assertSame( 401, $response->status() );
		$this->assertStringContainsString( 'error="invalid_token"', (string) $response->header( 'WWW-Authenticate' ) );
	}

	public function test_clone_with_changed_home_rejects_copied_token() {
		$token = $this->token();
		$this->assertSame( 200, $this->ping( $token )->status() );
		$checked = $this->store->check_access_token( $token );
		$this->assert_resource_check_refuses_store_success( $checked, 'https://clone.example.org' );
		update_option( 'home', 'https://clone.example.org' );
		$clone    = $this->new_store( 'https://clone.example.org/wp-json/mainwp-remote/v1/mcp' );
		$endpoint = $this->endpoint( $this->services( array(), 'https://clone.example.org', $clone ) );
		$response = $this->ping( $token, array(), $endpoint );
		$this->assertSame( 401, $response->status() );
		$this->assertStringContainsString( 'resource_metadata="https://clone.example.org/', (string) $response->header( 'WWW-Authenticate' ) );
	}

	public function test_handler_refuses_when_mainwp_rest_api_doing_defined() {
		$token    = $this->token();
		$services = $this->services();
		$auth     = new class( $services ) extends MCP_Bearer_Auth {
			protected function rest_api_doing(): bool {
				return true;
			}
		};
		$lookup   = 0;
		add_filter(
			'query',
			function ( $sql ) use ( &$lookup ) {
				if ( false !== strpos( $sql, 'token_hash' ) ) {
					++$lookup;
				}
				return $sql;
			}
		);
		$response = $this->ping( $token, array(), new MCP_Endpoint( $services, $auth ) );
		$this->assertGreaterThanOrEqual( 500, $response->status() );
		$this->assertSame( 0, $lookup, 'no token lookup' );
		$this->assertSame( 0, get_current_user_id() );
	}

	public function test_grant_for_client_removed_from_registry_is_refused() {
		$token = $this->token();
		$this->assertSame( 200, $this->ping( $token )->status() );
		unset( $this->clients->clients[ self::CLIENT_ID ] );
		$response = $this->ping( $token );
		$this->assertSame( 401, $response->status() );
		$this->assertStringContainsString( 'error="invalid_token"', (string) $response->header( 'WWW-Authenticate' ) );
	}

	public function test_bearer_success_records_use_and_refusal_records_failure() {
		$connection = $this->connection();
		$this->assertSame( 200, $this->ping( $connection['access_token'] )->status() );
		$grant = $this->grant( $connection['grant_id'] );
		$this->assertNotNull( $grant['last_used_at'] );
		$this->assertSame( '203.0.113.9', $grant['last_ip'] );
		$this->assertNull( $grant['last_failure'] );

		// The user lost manage_options: the bearer check refuses and records why.
		$user = get_userdata( $this->user_id );
		$user->set_role( 'subscriber' );
		$this->assertSame( 401, $this->ping( $connection['access_token'] )->status() );
		$grant = $this->grant( $connection['grant_id'] );
		$this->assertSame( 'cap_lost', $grant['last_failure'] );
		$this->assertNotNull( $grant['last_failure_at'] );
	}
}
