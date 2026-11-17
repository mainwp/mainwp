<?php
/**
 * Registration endpoint tests.
 *
 * The handler is driven directly with a request object; the kill-switch case also goes through
 * the front controller, which answers before any handler runs.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\MCP_Front_Controller;
use MainWP\Dashboard\Remote_MCP\OAuth_Registration_Endpoint;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings;

require_once __DIR__ . '/fixtures/client-registration-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

/**
 * Class Test_MainWP_OAuth_Registration_Endpoint
 */
class Test_MainWP_OAuth_Registration_Endpoint extends \WP_UnitTestCase {

	use Remote_MCP_Registration_Fixtures;

	const RESOURCE  = 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp';
	const CLIENT_ID = 'https://claude.ai/oauth/claude-code-client-metadata';
	const REDIRECT  = 'http://localhost:53682/callback';
	const VERIFIER  = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz';

	/**
	 * Assert a refusal with an RFC 7591 error and nothing stored.
	 *
	 * @param array  $body   Body.
	 * @param string $error  Expected error code.
	 * @param string $label  Case label.
	 */
	private function assert_refused( $body, string $error, string $label = '' ): void {
		$before   = count( $this->client_rows() );
		$response = $this->register( $body );
		$this->assertSame( 400, $response->status(), $label );
		$data = $this->json( $response );
		$this->assertSame( $error, $data['error'], $label );
		$this->assertSame( array( 'error', 'error_description' ), array_keys( $data ), $label );
		$this->assertCount( $before, $this->client_rows(), $label );
	}

	/**
	 * A minimal valid body.
	 *
	 * @param array $extra Extra members.
	 * @return array
	 */
	private function body( array $extra = array() ): array {
		return array_merge( array( 'redirect_uris' => array( 'http://127.0.0.1:3000/callback' ) ), $extra );
	}

	/**
	 * JSON objects with numeric keys must retain their object type.
	 */
	public function test_registration_rejects_numeric_object_redirect_uris() {
		$this->assert_refused( '{"redirect_uris":{"0":"http://127.0.0.1/cb"}}', 'invalid_redirect_uri' );
	}

	/**
	 * JSON objects with numeric keys must retain their object type.
	 */
	public function test_registration_rejects_numeric_object_grant_types() {
		$this->assert_refused( '{"redirect_uris":["http://127.0.0.1/cb"],"grant_types":{"0":"authorization_code"}}', 'invalid_client_metadata' );
	}

	/**
	 * JSON objects with numeric keys must retain their object type.
	 */
	public function test_registration_rejects_numeric_object_response_types() {
		$this->assert_refused( '{"redirect_uris":["http://127.0.0.1/cb"],"response_types":{"0":"code"}}', 'invalid_client_metadata' );
	}

	public function test_registration_strips_direction_controls_from_the_name() {
		$response = $this->register(
			array(
				'redirect_uris' => array( 'http://127.0.0.1:3000/callback' ),
				'client_name'   => "\u{202E}Desk\u{200F} Helper\u{2066}\u{061C}",
			)
		);
		$this->assertSame( 201, $response->status(), $response->body() );
		$this->assertSame( 'Desk Helper', $this->json( $response )['client_name'] );

		// esc_html() keeps valid entities, so the browser would decode these into the same controls.
		foreach ( array( '&#x202E;Desk Helper', '&rlm;Desk Helper', '&&#1;#x202E;Desk Helper' ) as $name ) {
			$response = $this->register(
				array(
					'redirect_uris' => array( 'http://127.0.0.1:3000/callback' ),
					'client_name'   => $name,
				)
			);
			$this->assertSame( 201, $response->status(), $response->body() );
			$this->assertStringNotContainsString( ';', $this->json( $response )['client_name'], $name );
		}
	}

	public function test_registration_accepts_loopback_redirects_only() {
		$uris     = array( 'http://127.0.0.1:3000/callback', 'http://[::1]:8080/cb', 'http://localhost/oauth/callback', 'http://127.0.0.1', 'http://localhost:65535/a.b_c~d-e/f' );
		$response = $this->register(
			array(
				'redirect_uris'              => $uris,
				'client_name'                => '  LM Studio  ',
				'grant_types'                => array( 'authorization_code', 'refresh_token' ),
				'response_types'             => array( 'code' ),
				'token_endpoint_auth_method' => 'none',
			)
		);
		$this->assertSame( 201, $response->status(), $response->body() );
		$this->assertSame( 'application/json', $response->header( 'Content-Type' ) );
		$this->assertSame( 'no-store', $response->header( 'Cache-Control' ) );
		$data = $this->json( $response );
		$this->assertMatchesRegularExpression( '/^mwplc_[A-Za-z0-9_-]{43}$/', $data['client_id'] );
		$this->assertSame( $uris, $data['redirect_uris'] );
		$this->assertSame( array( 'authorization_code', 'refresh_token' ), $data['grant_types'] );
		$this->assertSame( array( 'code' ), $data['response_types'] );
		$this->assertSame( 'none', $data['token_endpoint_auth_method'] );
		$this->assertSame( 'LM Studio', $data['client_name'] );
		$this->assertIsInt( $data['client_id_issued_at'] );
		$this->assertArrayNotHasKey( 'client_secret', $data );

		$row = $this->store->get_client( $data['client_id'] );
		$this->assertSame( 'registered', $row['kind'] );
		$this->assertSame( $uris, $row['redirect_uris'] );
		$this->assertSame( '127.0.0.1', $row['created_ip'] );
		$this->assertNull( $row['first_consented_at'] );
		$this->assertSame( 'registered', $this->real_clients()->lookup( $data['client_id'] )['kind'] );

		// No name: the response leaves it out.
		$plain = $this->json( $this->register( $this->body() ) );
		$this->assertArrayNotHasKey( 'client_name', $plain );

		// Anything that isn't a list of 1 to 5 loopback strings is refused, and nothing is stored.
		$this->assert_refused( array( 'redirect_uris' => array() ), 'invalid_redirect_uri', 'empty' );
		$this->assert_refused( array( 'redirect_uris' => array_fill( 0, 6, 'http://127.0.0.1/cb' ) ), 'invalid_redirect_uri', 'six' );
		$this->assert_refused( array( 'redirect_uris' => array( 'http://127.0.0.1/cb', 7 ) ), 'invalid_redirect_uri', 'non-string' );
		$this->assert_refused( array( 'redirect_uris' => array( 'a' => 'http://127.0.0.1/cb' ) ), 'invalid_redirect_uri', 'object' );
		$this->assert_refused( array( 'redirect_uris' => 'http://127.0.0.1/cb' ), 'invalid_redirect_uri', 'string' );
		$this->assert_refused( array( 'client_name' => 'No redirects' ), 'invalid_redirect_uri', 'missing' );
		$this->assert_refused( array( 'redirect_uris' => array( 'http://127.0.0.1/cb', 'https://app.example.com/cb' ) ), 'invalid_redirect_uri', 'mixed' );
		$this->assert_refused( array( 'redirect_uris' => array( 'http://127.0.0.1:0/cb' ) ), 'invalid_redirect_uri', 'port 0' );
		$this->assert_refused( array( 'redirect_uris' => array( 'http://127.0.0.1:65536/cb' ) ), 'invalid_redirect_uri', 'port 65536' );
		$this->assert_refused( array( 'redirect_uris' => array( 'myapp://callback' ) ), 'invalid_redirect_uri', 'private scheme' );
		$this->assert_refused( '[]', 'invalid_client_metadata', 'list body' );
		$this->assert_refused( 'not json', 'invalid_client_metadata', 'not json' );
		$this->assert_refused(
			array(
				'redirect_uris' => array( 'http://127.0.0.1/cb' ),
				'client_name'   => array( 'x' ),
			),
			'invalid_client_metadata',
			'name not a string'
		);
	}

	public function test_registration_refuses_hosted_redirect_with_admin_hint() {
		$response = $this->register( array( 'redirect_uris' => array( 'https://chat.example.com/oauth/callback' ) ) );
		$this->assertSame( 400, $response->status() );
		$data = $this->json( $response );
		$this->assertSame( 'invalid_redirect_uri', $data['error'] );
		$this->assertStringContainsString( 'admin', $data['error_description'] );
		$this->assertStringNotContainsString( 'chat.example.com', $response->body() );
		$this->assertSame( array(), $this->client_rows() );
	}

	public function test_registration_refuses_userinfo_percent_backslash_query_fragment() {
		$cases = array( 'http://user@127.0.0.1/cb', 'http://127.0.0.1@evil.com/cb', 'http://127.0.0.1/c%62', 'http://127.0.0.1:8080\\@evil.com/cb', 'http://127.0.0.1/cb?x=1', 'http://127.0.0.1/cb?', 'http://127.0.0.1/cb#x', 'http://127.0.0.1/cb#', "http://127.0.0.1/c\tb", 'http://127.0.0.1/c b', "http://127.0.0.1/cb\n", "http://127.0.0.1/c\x00b" );
		foreach ( $cases as $uri ) {
			$this->assert_refused( array( 'redirect_uris' => array( $uri ) ), 'invalid_redirect_uri', $uri );
		}
	}

	public function test_registration_refuses_alternate_loopback_notations() {
		foreach ( array( 'http://127.1/cb', 'http://2130706433/cb', 'http://0x7f.0.0.1/cb', 'http://localhost.evil.com/cb', 'http://127.0.0.1.nip.io/cb', 'http://LOCALHOST/cb', 'http://[0:0:0:0:0:0:0:1]/cb', 'http://127.0.0.2/cb', 'http://0.0.0.0/cb', 'HTTP://127.0.0.1/cb', 'http:/127.0.0.1/cb' ) as $uri ) {
			$this->assert_refused( array( 'redirect_uris' => array( $uri ) ), 'invalid_redirect_uri', $uri );
		}
	}

	public function test_registration_refuses_confidential_auth_method_and_other_grants() {
		foreach ( array( 'client_secret_basic', 'client_secret_post', 'private_key_jwt', 'None', '' ) as $method ) {
			$this->assert_refused( $this->body( array( 'token_endpoint_auth_method' => $method ) ), 'invalid_client_metadata', $method );
		}
		foreach ( array( array( 'client_credentials' ), array( 'authorization_code', 'implicit' ), array( 'urn:ietf:params:oauth:grant-type:device_code' ), array(), 'authorization_code' ) as $grants ) {
			$this->assert_refused( $this->body( array( 'grant_types' => $grants ) ), 'invalid_client_metadata', (string) wp_json_encode( $grants ) );
		}
		foreach ( array( array( 'token' ), array( 'code', 'id_token' ), array(), 'code' ) as $types ) {
			$this->assert_refused( $this->body( array( 'response_types' => $types ) ), 'invalid_client_metadata', (string) wp_json_encode( $types ) );
		}
		$this->assertSame( 201, $this->register( $this->body( array( 'grant_types' => array( 'authorization_code' ) ) ) )->status() );
		$this->assertSame( 201, $this->register( $this->body( array( 'token_endpoint_auth_method' => 'none' ) ) )->status() );
	}

	public function test_registration_ignores_logo_and_uri_fields_and_makes_no_outbound_request() {
		$response = $this->register(
			$this->body(
				array(
					'client_name'        => 'Local App',
					'logo_uri'           => 'https://evil.example.com/logo.png',
					'client_uri'         => 'https://evil.example.com/',
					'policy_uri'         => 'https://evil.example.com/policy',
					'tos_uri'            => 'https://evil.example.com/tos',
					'jwks_uri'           => 'https://evil.example.com/jwks',
					'software_statement' => 'eyJhbGciOiJub25lIn0.e30.',
					'contacts'           => array( 'a@evil.example.com' ),
				)
			)
		);
		$this->assertSame( 201, $response->status() );
		$this->assertStringNotContainsString( 'evil.example.com', $response->body() );
		$this->assertStringNotContainsString( 'software_statement', $response->body() );
		$data = $this->json( $response );
		$this->assertSame( array( 'client_id', 'client_id_issued_at', 'redirect_uris', 'grant_types', 'response_types', 'token_endpoint_auth_method', 'client_name' ), array_keys( $data ) );
		foreach ( $this->client_rows() as $row ) {
			$this->assertStringNotContainsString( 'evil.example.com', (string) wp_json_encode( $row ) );
		}
		$this->assertSame( array(), $this->outbound );
	}

	public function test_registration_body_over_8kb_refused() {
		$padding = str_repeat( 'x', 8192 );
		$body    = (string) wp_json_encode( $this->body( array( 'client_name' => 'App' ) ) );
		$big     = substr( $body, 0, -1 ) . ',"pad":"' . substr( $padding, 0, 8192 - strlen( $body ) - 9 ) . '"}';
		$this->assertSame( 8192, strlen( $big ) );
		$this->assertSame( 201, $this->register( $big )->status() );

		$over = substr( $big, 0, -2 ) . 'x"}';
		$this->assertSame( 8193, strlen( $over ) );
		$before = count( $this->client_rows() );
		$this->assert_refused( $over, 'invalid_client_metadata', 'over 8 KB' );
		$this->assertCount( $before, $this->client_rows() );
	}

	public function test_registration_rate_limited_per_ip() {
		for ( $i = 0; $i < 20; $i++ ) {
			$this->assertSame( 201, $this->register( $this->body(), '198.51.100.7', array( 'HTTP_X_FORWARDED_FOR' => '203.0.113.' . $i ) )->status(), (string) $i );
		}
		// Proxy headers never change the key.
		$limited = $this->register( $this->body(), '198.51.100.7', array( 'HTTP_X_FORWARDED_FOR' => '203.0.113.99' ) );
		$this->assertSame( 429, $limited->status() );
		$this->assertGreaterThan( 0, (int) $limited->header( 'Retry-After' ) );
		$this->assertSame( 'application/json', $limited->header( 'Content-Type' ) );
		$this->assertCount( 20, $this->client_rows() );

		// Another address isn't affected.
		$this->assertSame( 201, $this->register( $this->body(), '198.51.100.8' )->status() );

		// The limit is logged at most once an hour.
		$this->register( $this->body(), '198.51.100.7' );
		$this->assertCount( 1, $this->audited( 'registration_limited' ) );
		$this->assertSame( 'per_ip', $this->audited( 'registration_limited' )[0]['reason'] );
	}

	public function test_registration_limit_uses_the_sites_client_address_mapping() {
		$mapped = '203.0.113.50';
		add_filter(
			'mainwp_remote_mcp_client_ip',
			function () use ( &$mapped ) {
				return $mapped;
			}
		);
		for ( $i = 0; $i < 20; $i++ ) {
			$this->assertSame( 201, $this->register( $this->body(), '198.51.100.7' )->status(), (string) $i );
		}
		$this->assertSame( 429, $this->register( $this->body(), '198.51.100.7' )->status() );

		// Another client behind the same proxy has its own count.
		$mapped = '203.0.113.51';
		$this->assertSame( 201, $this->register( $this->body(), '198.51.100.7' )->status() );

		// A mapping that returns something else leaves REMOTE_ADDR in charge.
		$mapped = 'not an address';
		$this->assertSame( 201, $this->register( $this->body(), '198.51.100.9' )->status() );
		$created = $this->audited( 'registration_created' );
		$this->assertSame( '198.51.100.9', end( $created )['client_ip'] ?? null );
	}

	public function test_unconsented_registration_cap_returns_429() {
		for ( $i = 0; $i < 100; $i++ ) {
			$this->registered_client( array( 'http://127.0.0.1/cb' . $i ) );
		}
		$response = $this->register( $this->body(), '198.51.100.20' );
		$this->assertSame( 429, $response->status() );
		$this->assertGreaterThan( 0, (int) $response->header( 'Retry-After' ) );
		$this->assertSame( 100, $this->store->count_unconsented_clients() );

		// Consented registrations don't count toward the cap.
		$rows = $this->client_rows();
		$this->assertTrue( $this->store->mark_client_consented( $rows[0]['client_id'] ) );
		$this->assertSame( 201, $this->register( $this->body(), '198.51.100.21' )->status() );
		$this->assertSame( 100, $this->store->count_unconsented_clients() );
	}

	public function test_unconsented_registration_purged_after_seven_days() {
		$client_id = $this->json( $this->register( $this->body() ) )['client_id'];

		$this->tick( 7 * DAY_IN_SECONDS - 1 );
		$this->store->purge();
		$this->assertNotNull( $this->store->get_client( $client_id ) );
		$this->assertNotNull( $this->real_clients()->lookup( $client_id ) );

		$this->tick( 2 );
		$this->store->purge();
		$this->assertNull( $this->store->get_client( $client_id ) );
		$this->assertNull( $this->real_clients()->lookup( $client_id ) );
	}

	public function test_full_cap_evicts_oldest_unconsented_older_than_one_hour_else_429() {
		$ids = array();
		for ( $i = 0; $i < 100; $i++ ) {
			$ids[] = $this->registered_client( array( 'http://127.0.0.1/cb' . $i ) );
		}
		// All younger than an hour: refused.
		$this->set_created_at( $ids[5], $this->clock->now - 3599 );
		$this->assertSame( 429, $this->register( $this->body(), '198.51.100.30' )->status() );

		// An older consented one is never evicted.
		$this->set_created_at( $ids[3], $this->clock->now - 7200 );
		$this->assertTrue( $this->store->mark_client_consented( $ids[3] ) );
		$this->registered_client( array( 'http://127.0.0.1/refill' ) );
		$this->assertSame( 100, $this->store->count_unconsented_clients() );
		$this->set_created_at( $ids[7], $this->clock->now - 5000 );
		$this->set_created_at( $ids[9], $this->clock->now - 3700 );
		$response = $this->register( $this->body(), '198.51.100.31' );
		$this->assertSame( 201, $response->status() );
		$this->assertNull( $this->store->get_client( $ids[7] ) );
		$this->assertNotNull( $this->store->get_client( $ids[9] ) );
		$this->assertNotNull( $this->store->get_client( $ids[3] ) );
		$this->assertSame( 100, $this->store->count_unconsented_clients() );
	}

	public function test_consented_registration_survives_grant_expiry_and_revocation() {
		$client_id = $this->json( $this->register( $this->body() ) )['client_id'];
		$this->assertTrue( $this->store->mark_client_consented( $client_id ) );
		$connection = $this->connect(
			0,
			array(
				'client_id'    => $client_id,
				'client_host'  => '',
				'redirect_uri' => 'http://127.0.0.1:3000/callback',
			)
		);
		$this->assertTrue( $this->store->revoke_grant( $connection['grant_id'], 'manual' ) );
		$second = $this->connect(
			0,
			array(
				'client_id'    => $client_id,
				'client_host'  => '',
				'redirect_uri' => 'http://127.0.0.1:3000/callback',
			)
		);
		// Past the 7-day registration window, the session end and the 30-day grant retention.
		$this->tick( 70 * DAY_IN_SECONDS );
		$this->store->purge();
		$this->assertNull( $this->store->get_grant( $connection['grant_id'] ) );
		$this->assertNull( $this->store->get_grant( $second['grant_id'] ) );
		$this->assertNotNull( $this->store->get_client( $client_id ) );
		$this->assertSame( 'registered', $this->real_clients()->lookup( $client_id )['kind'] );
		// The row grants nothing on its own; reconnecting still needs fresh consent.
		$this->assertFalse( $this->access_ok( $connection['access_token'] ) );
	}

	public function test_registration_endpoint_404_under_kill_switch_and_when_switched_off() {
		// Switched off in the panel: the route is gone, existing registrations keep working.
		$existing = $this->json( $this->register( $this->body() ) )['client_id'];
		$this->assertTrue( Remote_MCP_Settings::set_registration_enabled( false ) );
		$off = $this->register( $this->body() );
		$this->assertSame( 404, $off->status() );
		$this->assertCount( 1, $this->client_rows() );
		$this->assertNotNull( $this->real_clients()->lookup( $existing ) );
		$this->assertTrue( Remote_MCP_Settings::set_registration_enabled( true ) );

		// Kill switch: the front controller answers 404 before any handler, and the handler
		// refuses on its own too.
		add_filter( Remote_MCP_Settings::FORCE_DISABLED_FILTER, '__return_true' );
		$this->assertSame( 404, $this->register( $this->body() )->status() );
		$_SERVER['REQUEST_URI']    = '/wp-json/mainwp-remote/v1/register';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['HTTPS']          = 'on';
		$wp                        = new \WP();
		$wp->query_vars            = array( 'rest_route' => '/mainwp-remote/v1/register' );
		$controller                = new MCP_Front_Controller( $this->services( array( 'route_register' => new OAuth_Registration_Endpoint( $this->services() ) ) ) );
		$reads                     = 0;
		$response                  = $controller->dispatch(
			$wp,
			function () use ( &$reads ) {
				++$reads;
				return (string) wp_json_encode( $this->body() );
			}
		);
		$this->assertSame( 404, $response->status() );
		$this->assertSame( 0, $reads );
		$this->assertCount( 1, $this->client_rows() );
		remove_filter( Remote_MCP_Settings::FORCE_DISABLED_FILTER, '__return_true' );

		// Other methods get 405 with Allow: POST.
		$get = $this->register( '', '127.0.0.1', array(), 'GET' );
		$this->assertSame( 405, $get->status() );
		$this->assertSame( 'POST', $get->header( 'Allow' ) );
	}

	public function test_registration_fires_audit_event_without_client_name() {
		$marker   = 'Name-Marker-7f3a';
		$response = $this->register( $this->body( array( 'client_name' => $marker ) ), '198.51.100.40' );
		$this->assertSame( 201, $response->status() );
		$created = $this->audited( 'registration_created' );
		$this->assertCount( 1, $created );
		$this->assertSame(
			array(
				'outcome'     => 'ok',
				'client_ip'   => '198.51.100.40',
				'client_kind' => 'registered',
			),
			$created[0]
		);
		$client_id = $this->json( $response )['client_id'];
		foreach ( $this->audits as list( $event, $fields ) ) {
			$this->assertStringNotContainsString( $marker, (string) wp_json_encode( $fields ), $event );
			$this->assertStringNotContainsString( $client_id, (string) wp_json_encode( $fields ), $event );
		}
		// A refused registration logs nothing per request.
		$this->register(
			array(
				'redirect_uris' => array( 'https://chat.example.com/cb' ),
				'client_name'   => $marker,
			)
		);
		$this->assertCount( 1, $this->audits );
	}
}
