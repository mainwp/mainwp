<?php
/**
 * Remote MCP token endpoint tests.
 *
 * Every request goes through the route handler with a store that shares the test clock and a
 * client lookup double standing in for the client registry.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Remote_MCP\Known_Clients;
use MainWP\Dashboard\Remote_MCP\MCP_Front_Controller;
use MainWP\Dashboard\Remote_MCP\OAuth_Rate_Limiter;
use MainWP\Dashboard\Remote_MCP\OAuth_Store;
use MainWP\Dashboard\Remote_MCP\OAuth_Token_Endpoint;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Events;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Request;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Response;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Services;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Settings;
use MainWP\Dashboard\Remote_MCP\Remote_MCP_Urls;

require_once __DIR__ . '/class-remote-mcp-test-support.php';

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Basic credentials in test requests.

if ( interface_exists( Known_Clients::class ) ) {

	/**
	 * Client lookup double: the bundled Claude id, the Cursor static client, the connection-token
	 * kind, and registered and web-app rows read from the store, the way the client registry resolves them.
	 */
	class Remote_MCP_Token_Test_Clients implements Known_Clients {

		/**
		 * Store holding registered and web-app rows.
		 *
		 * @var OAuth_Store
		 */
		private $store;

		/**
		 * Ids dropped from the registry, as a plugin update would drop one.
		 *
		 * @var string[]
		 */
		public $removed = array();

		/**
		 * Constructor.
		 *
		 * @param OAuth_Store $store Store.
		 */
		public function __construct( OAuth_Store $store ) {
			$this->store = $store;
		}

		/**
		 * Descriptor for an exact id.
		 *
		 * @param string $client_id Client id.
		 * @return array|null
		 */
		public function lookup( string $client_id ): ?array {
			if ( in_array( $client_id, $this->removed, true ) ) {
				return null;
			}
			if ( Test_MainWP_OAuth_Token_Endpoint::CLIENT_ID === $client_id ) {
				return $this->descriptor( $client_id, 'bundled', array( 'none' ), true );
			}
			if ( 'mainwp-cursor' === $client_id ) {
				return Remote_MCP_Settings::static_client_enabled( $client_id ) ? $this->descriptor( $client_id, 'static', array( 'none' ), true ) : null;
			}
			if ( OAuth_Store::CONNECTION_TOKEN_CLIENT_ID === $client_id ) {
				$token                    = $this->descriptor( $client_id, 'token', array( 'none' ), true );
				$token['oauth_endpoints'] = false;
				return $token;
			}
			if ( 0 === strpos( $client_id, 'mwplc_' ) || 0 === strpos( $client_id, 'mwpwa_' ) ) {
				$row = $this->store->get_client( $client_id );
				if ( null === $row || 'active' !== $row['status'] ) {
					return null;
				}
				$methods = 'webapp' === $row['kind'] ? array( 'client_secret_basic', 'client_secret_post' ) : array( 'none' );
				return $this->descriptor( $client_id, (string) $row['kind'], $methods, true );
			}
			return null;
		}

		/**
		 * Not used at the token endpoint.
		 *
		 * @param array  $client       Descriptor.
		 * @param string $redirect_uri Redirect.
		 * @return bool
		 */
		public function redirect_allowed( array $client, string $redirect_uri ): bool {
			return false;
		}

		/**
		 * Not used at the token endpoint.
		 *
		 * @param string $kind         Kind.
		 * @param string $redirect_uri Redirect.
		 * @return bool
		 */
		public function validate_redirect( string $kind, string $redirect_uri ): bool {
			return false;
		}

		/**
		 * Descriptor shape (Known_Clients docblock).
		 *
		 * @param string $client_id Id.
		 * @param string $kind      Kind.
		 * @param array  $methods   Auth methods.
		 * @param bool   $enabled   Switch.
		 * @return array
		 */
		private function descriptor( string $client_id, string $kind, array $methods, bool $enabled ): array {
			return array(
				'client_id'                   => $client_id,
				'kind'                        => $kind,
				'label'                       => $client_id,
				'client_name'                 => null,
				'redirect_rule'               => 'exact',
				'redirect_uris'               => array(),
				'token_endpoint_auth_methods' => $methods,
				'oauth_endpoints'             => true,
				'approval_delivery'           => 'result',
				'enabled'                     => $enabled,
				'step_up'                     => 'result',
			);
		}
	}

	/**
	 * Registry whose store is the test's clocked store.
	 */
	class Remote_MCP_Token_Test_Services extends Remote_MCP_Services {

		/**
		 * Store to hand out.
		 *
		 * @var OAuth_Store
		 */
		public $test_store;

		/**
		 * The test store.
		 *
		 * @return OAuth_Store|null
		 */
		public function store(): ?OAuth_Store {
			return $this->test_store;
		}
	}
}

/**
 * Class Test_MainWP_OAuth_Token_Endpoint
 */
class Test_MainWP_OAuth_Token_Endpoint extends \WP_UnitTestCase {

	use Remote_MCP_Store_Fixtures {
		set_up as store_set_up;
	}

	const RESOURCE  = 'https://dash.example.org/wp-json/mainwp-remote/v1/mcp';
	const CLIENT_ID = 'https://claude.ai/oauth/mcp-oauth-client-metadata';
	const REDIRECT  = 'https://claude.ai/api/mcp/auth_callback';
	const VERIFIER  = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz';
	const CURSOR    = 'mainwp-cursor';
	const CURSOR_CB = 'http://localhost:8787/callback';
	const WEBAPP_CB = 'https://chat.example.com/oauth/callback';
	const LOCAL_CB  = 'http://127.0.0.1/callback';

	/**
	 * $_SERVER before the test.
	 *
	 * @var array
	 */
	private $saved_server;

	/**
	 * Client lookup double.
	 *
	 * @var Remote_MCP_Token_Test_Clients
	 */
	private $clients;

	/**
	 * Registry handed to the endpoint.
	 *
	 * @var Remote_MCP_Token_Test_Services
	 */
	private $services;

	/**
	 * Enabled connector on an HTTPS root install, store and client double.
	 */
	public function set_up() {
		$this->store_set_up();
		$this->saved_server = $_SERVER;
		update_option( 'home', 'https://dash.example.org' );
		update_option( 'permalink_structure', '/%postname%/' );
		update_option( Remote_MCP_Settings::OPTION_ENABLED, '1' );
		$_SERVER['HTTPS']       = 'on';
		$_SERVER['SERVER_PORT'] = '443';
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] );

		$this->clients              = new Remote_MCP_Token_Test_Clients( $this->store );
		$this->services             = new Remote_MCP_Token_Test_Services( array( 'known_clients' => $this->clients ) );
		$this->services->test_store = $this->store;
	}

	/**
	 * Restore $_SERVER.
	 */
	public function tear_down() {
		if ( is_array( $this->saved_server ) ) {
			$_SERVER = $this->saved_server;
		}
		parent::tear_down();
	}

	/**
	 * The token endpoint: the implementing class the registry resolves, or one built with a limiter.
	 *
	 * @param OAuth_Rate_Limiter|null $limiter Limiter.
	 * @return \MainWP\Dashboard\Remote_MCP\Route_Handler
	 */
	private function endpoint( $limiter = null ) {
		if ( null !== $limiter && class_exists( OAuth_Token_Endpoint::class ) ) {
			return new OAuth_Token_Endpoint( $this->services, $limiter );
		}
		return $this->services->get( 'route_token' );
	}

	/**
	 * POST a form body to the endpoint.
	 *
	 * @param array|string            $body    Fields, or a raw body.
	 * @param array                   $server  Extra server variables.
	 * @param OAuth_Rate_Limiter|null $limiter Limiter.
	 * @return Remote_MCP_Response
	 */
	private function post( $body, array $server = array(), $limiter = null ): Remote_MCP_Response {
		$raw     = is_array( $body ) ? http_build_query( $body ) : $body;
		$request = new Remote_MCP_Request( 'route_token', 'POST', '', $raw, array_merge( $_SERVER, $server ) );
		return $this->endpoint( $limiter )->handle( $request );
	}

	/**
	 * Decoded JSON body.
	 *
	 * @param Remote_MCP_Response $response Response.
	 * @return array
	 */
	private function json( Remote_MCP_Response $response ): array {
		$data = json_decode( $response->body(), true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Assert an OAuth error response.
	 *
	 * @param Remote_MCP_Response $response Response.
	 * @param int                 $status   Status.
	 * @param string              $error    Error code.
	 */
	private function assert_error( Remote_MCP_Response $response, int $status, string $error ): void {
		$data = $this->json( $response );
		$this->assertSame( $error, $data['error'] ?? '', 'error code' );
		$this->assertSame( $status, $response->status(), 'status' );
		$this->assertArrayNotHasKey( 'access_token', $data );
	}

	/**
	 * Assert a successful token response and return it.
	 *
	 * @param Remote_MCP_Response $response Response.
	 * @return array
	 */
	private function assert_issued( Remote_MCP_Response $response ): array {
		$data = $this->json( $response );
		$this->assertSame( 200, $response->status(), 'status; body ' . $response->body() );
		$this->assertSame( 'Bearer', $data['token_type'] ?? '' );
		$this->assertStringStartsWith( 'mwpat_', (string) ( $data['access_token'] ?? '' ) );
		return $data;
	}

	/**
	 * Authorization code request body.
	 *
	 * @param string $code      Code.
	 * @param array  $overrides Fields; null removes one.
	 * @return array
	 */
	private function exchange_body( string $code, array $overrides = array() ): array {
		$body = array_merge(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => self::CLIENT_ID,
				'code_verifier' => self::VERIFIER,
				'redirect_uri'  => self::REDIRECT,
				'resource'      => self::RESOURCE,
			),
			$overrides
		);
		return array_filter(
			$body,
			function ( $value ) {
				return null !== $value;
			}
		);
	}

	/**
	 * Refresh request body.
	 *
	 * @param string $token     Refresh token.
	 * @param array  $overrides Fields; null removes one.
	 * @return array
	 */
	private function refresh_body( string $token, array $overrides = array() ): array {
		$body = array_merge(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $token,
				'client_id'     => self::CLIENT_ID,
			),
			$overrides
		);
		return array_filter(
			$body,
			function ( $value ) {
				return null !== $value;
			}
		);
	}

	/**
	 * Code, exchanged through the endpoint.
	 *
	 * @param array $code_overrides     Consent request overrides.
	 * @param array $exchange_overrides Token request overrides.
	 * @param array $server             Server variables.
	 * @return array Token response.
	 */
	private function connect_via_endpoint( array $code_overrides = array(), array $exchange_overrides = array(), array $server = array() ): array {
		$code = $this->issue_code( 0, $code_overrides );
		return $this->assert_issued( $this->post( $this->exchange_body( $code, $exchange_overrides ), $server ) );
	}

	/**
	 * A code whose consent approved an explicit scope.
	 *
	 * @param string $scope Requested and approved scope.
	 * @return string
	 */
	private function issue_code_with_scope( string $scope ): string {
		$params  = $this->request_params( 0, array( 'scope' => $scope ) );
		$request = $this->store->create_request( $params );
		$this->assertTrue( $this->store->claim_request( $request['id'], $this->user_id, true, $scope, OAuth_Store::POLICY_STANDARD ) );
		$code = $this->store->issue_code( $request['id'] );
		$this->assertIsString( $code );
		return $code;
	}

	/**
	 * Code row by its value.
	 *
	 * @param string $code Code.
	 * @return array
	 */
	private function code_row( string $code ): array {
		$hash = $this->hasher->hash( $code, self::RESOURCE );
		$rows = $this->rows( 'codes', "code_hash = '" . esc_sql( $hash ) . "'" );
		$this->assertCount( 1, $rows, 'code row' );
		return $rows[0];
	}

	/**
	 * Grant id behind a refresh token.
	 *
	 * @param string $refresh Refresh token.
	 * @return int
	 */
	private function grant_of( string $refresh ): int {
		$row = $this->store->lookup_token( $refresh, 'refresh' );
		$this->assertIsArray( $row );
		return (int) $row['id'];
	}

	/**
	 * Basic credentials header.
	 *
	 * @param string $client_id Client id.
	 * @param string $secret    Secret.
	 * @return array
	 */
	private function basic( string $client_id, string $secret ): array {
		return array( 'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode( rawurlencode( $client_id ) . ':' . rawurlencode( $secret ) ) );
	}

	/**
	 * A web app and a code issued to it.
	 *
	 * @return array `client_id`, `client_secret`, `code`.
	 */
	private function webapp_code(): array {
		$app = $this->store->create_client(
			'webapp',
			array(
				'redirect_uris' => array( self::WEBAPP_CB ),
				'client_name'   => 'Open WebUI',
			)
		);
		$this->assertSame( OAuth_Store::OK, $app['outcome'] );
		$app['code'] = $this->issue_code(
			0,
			array(
				'client_id'    => $app['client_id'],
				'redirect_uri' => self::WEBAPP_CB,
			)
		);
		return $app;
	}

	/**
	 * Web-app exchange overrides.
	 *
	 * @param string $client_id Client id.
	 * @return array
	 */
	private function webapp_overrides( string $client_id ): array {
		return array(
			'client_id'    => $client_id,
			'redirect_uri' => self::WEBAPP_CB,
		);
	}

	/**
	 * Record audit events.
	 *
	 * @return \ArrayObject Events as [ event, fields ].
	 */
	private function capture_audit(): \ArrayObject {
		$events = new \ArrayObject();
		add_action(
			Remote_MCP_Events::AUDIT,
			function ( $event, $fields ) use ( $events ) {
				$events[] = array( $event, $fields );
			},
			10,
			2
		);
		return $events;
	}

	/**
	 * Names of recorded events.
	 *
	 * @param \ArrayObject $events Events.
	 * @return string[]
	 */
	private function event_names( \ArrayObject $events ): array {
		return array_map(
			function ( $entry ) {
				return $entry[0];
			},
			$events->getArrayCopy()
		);
	}

	public function test_code_exchange_with_offline_access_issues_access_and_refresh() {
		$data = $this->connect_via_endpoint();
		$this->assertStringStartsWith( 'mwprt_', (string) ( $data['refresh_token'] ?? '' ) );
		$this->assertSame( 1800, $data['expires_in'] ?? null );
		$this->assertSame( 'mainwp:read offline_access', $data['scope'] ?? '' );
		$this->assertTrue( $this->access_ok( $data['access_token'] ) );
	}

	public function test_code_exchange_without_offline_access_issues_no_refresh() {
		$data = $this->connect_via_endpoint( array( 'scope' => 'mainwp:read' ) );
		$this->assertArrayNotHasKey( 'refresh_token', $data );
		$this->assertSame( 'mainwp:read', $data['scope'] ?? '' );
		$this->assertTrue( $this->access_ok( $data['access_token'] ) );
	}

	public function test_client_id_mismatch_is_invalid_grant_and_burns_code() {
		$code = $this->issue_code();
		// Another known client: it authenticates, then the code row's client_id doesn't match.
		$this->assert_error( $this->post( $this->exchange_body( $code, array( 'client_id' => self::CURSOR ) ) ), 400, 'invalid_grant' );
		$this->assertSame( 'burned', $this->code_row( $code )['status'] );
		$this->assert_error( $this->post( $this->exchange_body( $code ) ), 400, 'invalid_grant' );
	}

	public function test_redirect_uri_mismatch_is_invalid_grant() {
		$code = $this->issue_code();
		$this->assert_error( $this->post( $this->exchange_body( $code, array( 'redirect_uri' => self::REDIRECT . '/x' ) ) ), 400, 'invalid_grant' );
		$this->assertSame( 'burned', $this->code_row( $code )['status'] );
	}

	public function test_code_exchange_without_redirect_uri_succeeds() {
		$data = $this->connect_via_endpoint( array(), array( 'redirect_uri' => null ) );
		$this->assertTrue( $this->access_ok( $data['access_token'] ) );
	}

	public function test_code_exchange_without_resource_uses_authorized_resource() {
		$data = $this->connect_via_endpoint( array(), array( 'resource' => null ) );
		$this->assertTrue( $this->access_ok( $data['access_token'] ) );
		foreach ( $this->rows( 'tokens' ) as $row ) {
			$this->assertSame( self::RESOURCE, $row['resource'] );
		}
	}

	public function test_refresh_without_resource_uses_grant_resource() {
		$data  = $this->connect_via_endpoint();
		$fresh = $this->assert_issued( $this->post( $this->refresh_body( $data['refresh_token'] ) ) );
		$this->assertTrue( $this->access_ok( $fresh['access_token'] ) );
		$grant = $this->grant_of( $fresh['refresh_token'] );
		foreach ( $this->rows( 'tokens', 'grant_id = ' . $grant ) as $row ) {
			$this->assertSame( self::RESOURCE, $row['resource'] );
		}
	}

	public function test_valid_code_replay_without_resource_revokes_grant() {
		$code = $this->issue_code();
		$data = $this->assert_issued( $this->post( $this->exchange_body( $code ) ) );
		$this->assert_error( $this->post( $this->exchange_body( $code, array( 'resource' => null ) ) ), 400, 'invalid_grant' );
		$grant = $this->grant( (int) $this->code_row( $code )['grant_id'] );
		$this->assertSame( 'revoked', $grant['status'] );
		$this->assertSame( 'code_replay', $grant['revoke_reason'] );
		$this->assertFalse( $this->access_ok( $data['access_token'] ) );
	}

	public function test_password_change_before_code_exchange_prevents_issuance() {
		$code = $this->issue_code();
		$this->assertNotNull( $this->store->bump_user_generation( $this->user_id, 'password_changed' ) );
		$this->assert_error( $this->post( $this->exchange_body( $code ) ), 400, 'invalid_grant' );
		$this->assertCount( 0, $this->rows( 'grants', "status = 'live'" ) );
	}

	public function test_password_change_racing_grant_creation_leaves_no_usable_tokens() {
		$code   = $this->issue_code();
		$minted = array();
		$this->store->on(
			'before_publish',
			function ( $grant_id, $tokens ) use ( &$minted ) {
				$minted = $tokens;
				// The trigger's bump has landed; its grant-row revoke hasn't run yet.
				$this->store->bump_generation( $this->user_id );
			}
		);
		$this->assert_error( $this->post( $this->exchange_body( $code ) ), 400, 'invalid_grant' );
		$this->assertNotEmpty( $minted['access_token'] ?? '' );
		$this->assertFalse( $this->access_ok( $minted['access_token'] ) );
		$this->assertSame( OAuth_Store::INVALID_GRANT, $this->refresh( (string) $minted['refresh_token'] )['outcome'] );
		$this->assertCount( 0, $this->rows( 'grants', "status = 'live'" ) );
	}

	public function test_code_exchange_rechecks_user_capability() {
		$code = $this->issue_code();
		( new \WP_User( $this->user_id ) )->set_role( 'subscriber' );
		$this->assert_error( $this->post( $this->exchange_body( $code ) ), 400, 'invalid_grant' );
		$this->assertCount( 0, $this->rows( 'grants', "status = 'live'" ) );
	}

	public function test_refresh_rechecks_user_capability_and_generation() {
		$first = $this->connect_via_endpoint();
		( new \WP_User( $this->user_id ) )->set_role( 'editor' );
		$this->assert_error( $this->post( $this->refresh_body( $first['refresh_token'] ) ), 400, 'invalid_grant' );
		$this->assertSame( 'cap_lost', $this->grant( $this->grant_of( $first['refresh_token'] ) )['revoke_reason'] );

		$other  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$second = $this->assert_issued( $this->post( $this->exchange_body( $this->issue_code( $other ) ) ) );
		// The generation moved but the trigger's grant-row revoke never landed: refresh still refuses.
		$this->assertTrue( $this->store->bump_generation( $other ) );
		$this->assert_error( $this->post( $this->refresh_body( $second['refresh_token'] ) ), 400, 'invalid_grant' );
		$this->assertSame( 'generation_changed', $this->grant( $this->grant_of( $second['refresh_token'] ) )['revoke_reason'] );
	}

	public function test_wrong_verifier_burns_code() {
		$code = $this->issue_code();
		$this->assert_error( $this->post( $this->exchange_body( $code, array( 'code_verifier' => strrev( self::VERIFIER ) ) ) ), 400, 'invalid_grant' );
		$this->assertSame( 'burned', $this->code_row( $code )['status'] );
		$this->assert_error( $this->post( $this->exchange_body( $code ) ), 400, 'invalid_grant' );
	}

	public function test_malformed_verifier_rejected() {
		$code = $this->issue_code();
		$this->assert_error( $this->post( $this->exchange_body( $code, array( 'code_verifier' => 'too short' ) ) ), 400, 'invalid_grant' );
		$this->assertCount( 0, $this->rows( 'grants', "status = 'live'" ) );

		// A missing verifier is a malformed request, refused before the code is looked up.
		$other = $this->issue_code();
		$this->assert_error( $this->post( $this->exchange_body( $other, array( 'code_verifier' => null ) ) ), 400, 'invalid_request' );
		$this->assertSame( 'live', $this->code_row( $other )['status'] );
	}

	public function test_resource_mismatch_is_invalid_target() {
		$code = $this->issue_code();
		$this->assert_error( $this->post( $this->exchange_body( $code, array( 'resource' => 'https://dash.example.org/wp-json/mainwp-remote/v1/MCP' ) ) ), 400, 'invalid_target' );
		$this->assertCount( 0, $this->rows( 'grants', "status = 'live'" ) );

		// Only the scheme and host are case-folded and the default port dropped.
		$data = $this->connect_via_endpoint( array(), array( 'resource' => 'HTTPS://DASH.Example.org:443/wp-json/mainwp-remote/v1/mcp' ) );
		$this->assertTrue( $this->access_ok( $data['access_token'] ) );
	}

	public function test_valid_code_replay_returns_invalid_grant_and_revokes_tokens() {
		$code = $this->issue_code();
		$data = $this->assert_issued( $this->post( $this->exchange_body( $code ) ) );
		$this->assert_error( $this->post( $this->exchange_body( $code ) ), 400, 'invalid_grant' );
		$this->assertFalse( $this->access_ok( $data['access_token'] ) );
		$this->assert_error( $this->post( $this->refresh_body( $data['refresh_token'] ) ), 400, 'invalid_grant' );
		$this->assertSame( 'code_replay', $this->grant( (int) $this->code_row( $code )['grant_id'] )['revoke_reason'] );
	}

	public function test_valid_code_replay_without_redirect_uri_revokes_grant() {
		$code = $this->issue_code();
		$data = $this->assert_issued( $this->post( $this->exchange_body( $code ) ) );
		$this->assert_error( $this->post( $this->exchange_body( $code, array( 'redirect_uri' => null ) ) ), 400, 'invalid_grant' );
		$this->assertSame( 'revoked', $this->grant( (int) $this->code_row( $code )['grant_id'] )['status'] );
		$this->assertFalse( $this->access_ok( $data['access_token'] ) );
	}

	public function test_redeemed_code_with_wrong_verifier_does_not_revoke_grant() {
		$code = $this->issue_code();
		$data = $this->assert_issued( $this->post( $this->exchange_body( $code ) ) );
		$this->assert_error( $this->post( $this->exchange_body( $code, array( 'code_verifier' => strrev( self::VERIFIER ) ) ) ), 400, 'invalid_grant' );
		$this->assertSame( 'live', $this->grant( (int) $this->code_row( $code )['grant_id'] )['status'] );
		$this->assertTrue( $this->access_ok( $data['access_token'] ) );
	}

	public function test_redeemed_code_replay_with_wrong_client_does_not_revoke_grant() {
		$code = $this->issue_code();
		$data = $this->assert_issued( $this->post( $this->exchange_body( $code ) ) );
		$this->assert_error( $this->post( $this->exchange_body( $code, array( 'client_id' => self::CURSOR ) ) ), 400, 'invalid_grant' );
		$this->assertSame( 'live', $this->grant( (int) $this->code_row( $code )['grant_id'] )['status'] );
		$this->assertTrue( $this->access_ok( $data['access_token'] ) );
	}

	public function test_refresh_token_is_bound_to_client() {
		$app     = $this->webapp_code();
		$auth    = $this->basic( $app['client_id'], $app['client_secret'] );
		$data    = $this->assert_issued( $this->post( $this->exchange_body( $app['code'], $this->webapp_overrides( $app['client_id'] ) ), $auth ) );
		$grant   = $this->grant_of( $data['refresh_token'] );
		$before  = $this->grant( $grant );
		$clock   = $this->clock;
		$limiter = new OAuth_Rate_Limiter(
			function () use ( $clock ) {
				return (int) $clock->now;
			}
		);
		$events  = $this->capture_audit();
		$wrong   = $this->refresh_body( $data['refresh_token'], array( 'client_id' => self::CURSOR ) );
		$server  = array( 'REMOTE_ADDR' => '203.0.113.8' );

		for ( $attempt = 0; $attempt < 30; ++$attempt ) {
			$this->assert_error( $this->post( $wrong, $server, $limiter ), 400, 'invalid_grant' );
		}
		$this->assertSame( $before, $this->grant( $grant ), 'wrong-client refreshes leave the victim grant and failure status untouched' );
		$this->assertCount( 0, $events, 'wrong-client refreshes emit no grant diagnostics' );
		$this->assert_error( $this->post( $wrong, $server, $limiter ), 429, 'invalid_request' );
		$this->assertArrayNotHasKey( 'grant_id', $events[0][1], 'endpoint-wide refusal names no victim grant' );

		$this->tick( 61 );
		$this->assert_error( $this->post( $wrong + array( 'resource' => 'https://other.example.org/mcp' ), $server, $limiter ), 400, 'invalid_grant' );
		$this->assertSame( $before, $this->grant( $grant ), 'client binding precedes resource handling' );

		// A separate client address avoids the attacker's endpoint limit while spending the full grant allowance.
		$auth['REMOTE_ADDR'] = '203.0.113.9';
		for ( $attempt = 0; $attempt < 30; ++$attempt ) {
			$data = $this->assert_issued( $this->post( $this->refresh_body( $data['refresh_token'], array( 'client_id' => $app['client_id'] ) ), $auth, $limiter ) );
		}
		$this->tick( 61 );
		$this->assert_error( $this->post( $this->refresh_body( $data['refresh_token'], array( 'client_id' => $app['client_id'] ) ), $auth, $limiter ), 429, 'invalid_request' );
	}

	public function test_access_token_cannot_be_used_as_refresh_token() {
		$data    = $this->connect_via_endpoint();
		$grant   = $this->grant_of( $data['refresh_token'] );
		$version = $this->grant( $grant )['version'];
		$this->assert_error( $this->post( $this->refresh_body( $data['access_token'] ) ), 400, 'invalid_grant' );
		$this->assertSame( $version, $this->grant( $grant )['version'] );
		$this->assertSame( 'live', $this->grant( $grant )['status'] );
		$this->assert_issued( $this->post( $this->refresh_body( $data['refresh_token'] ) ) );
	}

	public function test_code_presented_as_refresh_token_is_rejected_without_mutation() {
		$code = $this->issue_code();
		$this->assert_error( $this->post( $this->refresh_body( $code ) ), 400, 'invalid_grant' );
		$this->assertSame( 'live', $this->code_row( $code )['status'] );
		$this->assertCount( 0, $this->rows( 'grants' ) );
		$this->assert_issued( $this->post( $this->exchange_body( $code ) ) );
	}

	public function test_refresh_cannot_widen_scope() {
		$data = $this->connect_via_endpoint();
		$this->assert_error( $this->post( $this->refresh_body( $data['refresh_token'], array( 'scope' => 'mainwp:read mainwp:write offline_access' ) ) ), 400, 'invalid_scope' );
		$fresh = $this->assert_issued( $this->post( $this->refresh_body( $data['refresh_token'] ) ) );
		$this->assertSame( 'mainwp:read offline_access', $fresh['scope'] ?? '' );
	}

	public function test_refresh_cannot_change_resource() {
		$data  = $this->connect_via_endpoint();
		$grant = $this->grant_of( $data['refresh_token'] );
		$this->assert_error( $this->post( $this->refresh_body( $data['refresh_token'], array( 'resource' => 'https://other.example.org/wp-json/mainwp-remote/v1/mcp' ) ) ), 400, 'invalid_target' );
		$this->assertSame( '1', (string) $this->grant( $grant )['current_pair'] );
		$this->assert_issued( $this->post( $this->refresh_body( $data['refresh_token'], array( 'resource' => self::RESOURCE ) ) ) );
	}

	public function test_refresh_may_narrow_scope() {
		$code  = $this->issue_code_with_scope( 'mainwp:read mainwp:write offline_access' );
		$data  = $this->assert_issued( $this->post( $this->exchange_body( $code ) ) );
		$fresh = $this->assert_issued( $this->post( $this->refresh_body( $data['refresh_token'], array( 'scope' => 'mainwp:read offline_access' ) ) ) );
		$this->assertSame( 'mainwp:read offline_access', $fresh['scope'] ?? '' );
		$check = $this->store->check_access_token( $fresh['access_token'] );
		$this->assertSame( 'mainwp:read offline_access', $check['scope'] ?? '' );
	}

	public function test_duplicate_refresh_grace_response_is_a_normal_token_response() {
		$data   = $this->connect_via_endpoint();
		$first  = $this->assert_issued( $this->post( $this->refresh_body( $data['refresh_token'] ) ) );
		$second = $this->assert_issued( $this->post( $this->refresh_body( $data['refresh_token'] ) ) );
		$this->assertSame( array_keys( $first ), array_keys( $second ) );
		$this->assertStringStartsWith( 'mwprt_', (string) ( $second['refresh_token'] ?? '' ) );
		$this->assertNotSame( $first['access_token'], $second['access_token'] );
		$this->assertTrue( $this->access_ok( $second['access_token'] ) );
	}

	public function test_refresh_replay_returns_invalid_grant_and_revokes_family() {
		$data  = $this->connect_via_endpoint();
		$fresh = $this->assert_issued( $this->post( $this->refresh_body( $data['refresh_token'] ) ) );
		$this->tick( 31 );
		$this->assert_error( $this->post( $this->refresh_body( $data['refresh_token'] ) ), 400, 'invalid_grant' );
		$grant = $this->grant( $this->grant_of( $fresh['refresh_token'] ) );
		$this->assertSame( 'revoked', $grant['status'] );
		$this->assertSame( 'refresh_replay', $grant['revoke_reason'] );
		$this->assertFalse( $this->access_ok( $fresh['access_token'] ) );
	}

	public function test_late_duplicate_refresh_returns_invalid_grant_without_revocation() {
		$events = $this->capture_audit();
		$data   = $this->connect_via_endpoint();
		$fresh  = $this->assert_issued( $this->post( $this->refresh_body( $data['refresh_token'] ) ) );
		$this->assertTrue( $this->access_ok( $fresh['access_token'] ) );
		$this->assert_error( $this->post( $this->refresh_body( $data['refresh_token'] ) ), 400, 'invalid_grant' );
		$this->assertSame( 'live', $this->grant( $this->grant_of( $fresh['refresh_token'] ) )['status'] );
		$this->assertContains( 'refresh_duplicate_late', $this->event_names( $events ) );
		$this->assert_issued( $this->post( $this->refresh_body( $fresh['refresh_token'] ) ) );
	}

	public function test_unsupported_grant_type_rejected() {
		foreach ( array( 'password', 'client_credentials', 'urn:ietf:params:oauth:grant-type:device_code' ) as $type ) {
			$this->assert_error( $this->post( array( 'grant_type' => $type ) + $this->refresh_body( 'mwprt_x' ) ), 400, 'unsupported_grant_type' );
		}
		$this->assert_error( $this->post( array( 'client_id' => self::CLIENT_ID ) ), 400, 'invalid_request' );
	}

	public function test_http_token_request_is_refused_without_mutation() {
		$code                   = $this->issue_code();
		$_SERVER['HTTPS']       = 'off';
		$_SERVER['SERVER_PORT'] = '80';
		$this->assert_error( $this->post( $this->exchange_body( $code ) ), 400, 'invalid_request' );
		$this->assertSame( 'live', $this->code_row( $code )['status'] );
		$this->assertCount( 0, $this->rows( 'grants' ) );

		$_SERVER['HTTPS']       = 'on';
		$_SERVER['SERVER_PORT'] = '443';
		$this->assert_issued( $this->post( $this->exchange_body( $code ) ) );
	}

	public function test_duplicate_token_parameters_rejected_without_mutation() {
		$code = $this->issue_code();
		$base = http_build_query( $this->exchange_body( $code ) );
		foreach ( array( '&code=' . rawurlencode( $code ), '&client_id%5B%5D=' . rawurlencode( self::CLIENT_ID ), '&grant_type=refresh_token' ) as $repeat ) {
			$this->assert_error( $this->post( $base . $repeat ), 400, 'invalid_request' );
		}
		$this->assertSame( 'live', $this->code_row( $code )['status'] );
		$this->assertCount( 0, $this->rows( 'grants' ) );
		$this->assert_issued( $this->post( $base ) );
	}

	public function test_multiple_resource_values_at_token_endpoint_are_invalid_target() {
		$code = $this->issue_code();
		$this->assert_error( $this->post( http_build_query( $this->exchange_body( $code ) ) . '&resource=' . rawurlencode( self::RESOURCE ) ), 400, 'invalid_target' );
		$this->assertSame( 'live', $this->code_row( $code )['status'] );

		$data  = $this->assert_issued( $this->post( $this->exchange_body( $code ) ) );
		$grant = $this->grant_of( $data['refresh_token'] );
		$this->assert_error( $this->post( http_build_query( $this->refresh_body( $data['refresh_token'], array( 'resource' => self::RESOURCE ) ) ) . '&resource=' . rawurlencode( self::RESOURCE ) ), 400, 'invalid_target' );
		$this->assertSame( '1', (string) $this->grant( $grant )['current_pair'] );
	}

	public function test_client_secret_or_basic_auth_is_invalid_client() {
		// Bundled client.
		$code     = $this->issue_code();
		$response = $this->post( $this->exchange_body( $code, array( 'client_secret' => 'anything' ) ) );
		$this->assert_error( $response, 401, 'invalid_client' );
		$response = $this->post( $this->exchange_body( $code, array( 'client_id' => null ) ), $this->basic( self::CLIENT_ID, 'anything' ) );
		$this->assert_error( $response, 401, 'invalid_client' );
		$this->assertStringStartsWith( 'Basic', (string) $response->header( 'WWW-Authenticate' ) );
		$this->assertSame( 'live', $this->code_row( $code )['status'] );

		// Static client.
		$static = $this->issue_code(
			0,
			array(
				'client_id'    => self::CURSOR,
				'redirect_uri' => self::CURSOR_CB,
			)
		);
		$body   = $this->exchange_body(
			$static,
			array(
				'client_id'     => self::CURSOR,
				'redirect_uri'  => self::CURSOR_CB,
				'client_secret' => 'anything',
			)
		);
		$this->assert_error( $this->post( $body ), 401, 'invalid_client' );

		// Registered client, on refresh as well.
		$app        = $this->store->create_client( 'registered', array( 'redirect_uris' => array( self::LOCAL_CB ) ) );
		$overrides  = array(
			'client_id'    => $app['client_id'],
			'redirect_uri' => self::LOCAL_CB,
		);
		$registered = $this->issue_code( 0, $overrides );
		$this->assert_error( $this->post( $this->exchange_body( $registered, $overrides ), $this->basic( $app['client_id'], 'anything' ) ), 401, 'invalid_client' );
		$data = $this->assert_issued( $this->post( $this->exchange_body( $registered, $overrides ) ) );
		$this->assert_error(
			$this->post(
				$this->refresh_body(
					$data['refresh_token'],
					array(
						'client_id'     => $app['client_id'],
						'client_secret' => 'anything',
					)
				)
			),
			401,
			'invalid_client'
		);
	}

	public function test_token_responses_send_no_store_and_pragma() {
		$code      = $this->issue_code();
		$responses = array(
			$this->post( $this->exchange_body( $code ) ),
			$this->post( $this->exchange_body( $code ) ),
			$this->post( array( 'grant_type' => 'password' ) ),
			$this->post( $this->exchange_body( $code, array( 'client_secret' => 'x' ) ) ),
		);
		$this->assertSame( 200, $responses[0]->status() );
		foreach ( $responses as $response ) {
			$this->assertSame( 'no-store', $response->header( 'Cache-Control' ) );
			$this->assertSame( 'no-cache', $response->header( 'Pragma' ) );
			$this->assertStringStartsWith( 'application/json', (string) $response->header( 'Content-Type' ) );
		}
	}

	public function test_error_description_never_echoes_request_input() {
		$marker       = 'zzmarker' . wp_generate_password( 12, false );
		$code         = $this->issue_code();
		$responses    = array(
			$this->post( array( 'grant_type' => $marker ) ),
			$this->post( $this->exchange_body( 'mwpac_' . $marker, array( 'redirect_uri' => self::REDIRECT . $marker ) ) ),
			$this->post( $this->exchange_body( $code, array( 'resource' => self::RESOURCE . $marker ) ) ),
			$this->post( $this->exchange_body( $code, array( 'client_id' => 'mwplc_' . $marker ) ) ),
			$this->post( $this->exchange_body( $code, array( 'client_id' => 'https://' . $marker . '.example' ) ) ),
			$this->post( $this->refresh_body( 'mwprt_' . $marker, array( 'scope' => $marker ) ) ),
			$this->post( 'grant_type=refresh_token&refresh_token=' . $marker . '&refresh_token=' . $marker ),
		);
		$descriptions = array();
		foreach ( $responses as $response ) {
			$this->assertStringNotContainsString( $marker, $response->body() );
			$data = $this->json( $response );
			$this->assertNotSame( '', $data['error'] ?? '' );
			$descriptions[ $data['error'] ][] = $data['error_description'] ?? '';
		}
		$this->assertGreaterThanOrEqual( 4, count( $descriptions ) );
		foreach ( $descriptions as $error => $texts ) {
			$this->assertCount( 1, array_unique( $texts ), 'one fixed description for ' . $error );
			$this->assertNotSame( '', $texts[0] );
		}
	}

	// A handful of addresses must not be able to stop every connected app from refreshing.
	public function test_token_requests_from_many_addresses_share_no_limit() {
		$limiter = new OAuth_Rate_Limiter(
			function () {
				return 1800000000;
			}
		);
		for ( $address = 1; $address <= 6; $address++ ) {
			for ( $i = 0; $i < OAuth_Rate_Limiter::IP_LIMIT; $i++ ) {
				$this->assertNull( $limiter->endpoint_hit( '198.51.100.' . $address ), $address . ':' . $i );
			}
		}
		$this->assertSame( 'ip', $limiter->endpoint_hit( '198.51.100.1' )['limit'] );
	}

	public function test_refresh_rate_limit_returns_429_with_retry_after() {
		$this->assertTrue( class_exists( OAuth_Rate_Limiter::class ), 'rate limiter class' );
		$clock   = $this->clock;
		$limiter = new OAuth_Rate_Limiter(
			function () use ( $clock ) {
				return (int) $clock->now;
			}
		);
		$events  = $this->capture_audit();
		$data    = $this->connect_via_endpoint();
		$token   = $data['refresh_token'];
		for ( $i = 1; $i <= 30; $i++ ) {
			$fresh = $this->assert_issued( $this->post( $this->refresh_body( $token ), array( 'REMOTE_ADDR' => '198.51.100.' . $i ), $limiter ) );
			$token = $fresh['refresh_token'];
		}
		$grant   = $this->grant_of( $token );
		$version = $this->grant( $grant )['version'];

		$limited = $this->post( $this->refresh_body( $token ), array( 'REMOTE_ADDR' => '198.51.100.99' ), $limiter );
		$this->assertSame( 429, $limited->status() );
		$retry = (int) $limited->header( 'Retry-After' );
		$this->assertGreaterThan( 0, $retry );
		$this->assertLessThanOrEqual( 3600, $retry );
		$this->assertSame( 'no-store', $limited->header( 'Cache-Control' ) );
		$this->assertSame( $version, $this->grant( $grant )['version'], 'nothing rotated' );
		$this->assertContains( 'rate_limited', $this->event_names( $events ) );

		// The limit is per grant: another connection still refreshes now.
		$other = $this->connect_via_endpoint();
		$this->assert_issued( $this->post( $this->refresh_body( $other['refresh_token'] ), array( 'REMOTE_ADDR' => '198.51.100.100' ), $limiter ) );

		// After the window the refused token was never spent.
		$this->tick( $retry );
		$this->assert_issued( $this->post( $this->refresh_body( $token ), array( 'REMOTE_ADDR' => '198.51.100.101' ), $limiter ) );
	}

	public function test_logs_contain_no_codes_verifiers_or_tokens() {
		$log      = tempnam( get_temp_dir(), 'rmcp-log' );
		$previous = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- capture anything the endpoint writes to the PHP log.
		$records  = array();
		add_action(
			Remote_MCP_Events::AUDIT,
			function ( $event, $fields ) use ( &$records ) {
				$records[] = array( $event, $fields );
			},
			10,
			2
		);
		add_action(
			Remote_MCP_Events::GRANT_ISSUED,
			function ( ...$args ) use ( &$records ) {
				$records[] = $args;
			},
			10,
			5
		);
		try {
			$app      = $this->webapp_code();
			$code     = $this->issue_code();
			$data     = $this->assert_issued( $this->post( $this->exchange_body( $code ) ) );
			$fresh    = $this->assert_issued( $this->post( $this->refresh_body( $data['refresh_token'] ) ) );
			$app_data = $this->assert_issued( $this->post( $this->exchange_body( $app['code'], $this->webapp_overrides( $app['client_id'] ) ), $this->basic( $app['client_id'], $app['client_secret'] ) ) );
			$this->post( $this->exchange_body( $code ) );
			$this->tick( 31 );
			$this->post( $this->refresh_body( $data['refresh_token'] ) );
			$this->post( $this->exchange_body( $app['code'], $this->webapp_overrides( $app['client_id'] ) + array( 'client_secret' => $app['client_secret'] . 'x' ) ) );
		} finally {
			ini_set( 'error_log', (string) $previous ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- restore.
		}
		$written = (string) file_get_contents( $log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temp file.
		wp_delete_file( $log );

		$this->assertNotEmpty( $records );
		$logged  = wp_json_encode( $records ) . $written;
		$secrets = array(
			$code,
			$app['code'],
			self::VERIFIER,
			$this->challenge( self::VERIFIER ),
			$data['access_token'],
			$data['refresh_token'],
			$fresh['access_token'],
			$fresh['refresh_token'],
			$app_data['access_token'],
			$app['client_secret'],
			'client-state',
		);
		foreach ( $secrets as $secret ) {
			$this->assertStringNotContainsString( $secret, $logged );
		}
	}

	public function test_token_endpoint_404_when_disabled() {
		$code     = $this->issue_code();
		$services = new Remote_MCP_Services();
		$this->assertFalse( $services->is_stub( 'route_token' ), 'the implementing endpoint fills route_token' );
		$body     = http_build_query( $this->exchange_body( $code ) );
		$dispatch = function () use ( $services, $body ) {
			$_SERVER['REQUEST_METHOD'] = 'POST';
			$_SERVER['QUERY_STRING']   = '';
			$wp                        = new \WP();
			$wp->query_vars            = array( 'rest_route' => Remote_MCP_Urls::ROUTE_TOKEN );
			return ( new MCP_Front_Controller( $services ) )->dispatch(
				$wp,
				function () use ( $body ) {
					return $body;
				}
			);
		};

		update_option( Remote_MCP_Settings::OPTION_ENABLED, '' );
		$response = $dispatch();
		$this->assertInstanceOf( Remote_MCP_Response::class, $response );
		$this->assertSame( 404, $response->status() );
		$this->assertNull( $response->header( 'WWW-Authenticate' ) );
		$this->assertSame( 'live', $this->code_row( $code )['status'] );

		// Reached directly while off, the handler refuses before reading the body.
		$this->assert_error( $this->post( $this->exchange_body( $code ) ), 400, 'invalid_request' );
		$this->assertSame( 'live', $this->code_row( $code )['status'] );

		update_option( Remote_MCP_Settings::OPTION_ENABLED, '1' );
		add_filter( Remote_MCP_Settings::FORCE_DISABLED_FILTER, '__return_true' );
		$this->assertSame( 404, $dispatch()->status() );
		$this->assert_error( $this->post( $this->exchange_body( $code ) ), 400, 'invalid_request' );
		remove_filter( Remote_MCP_Settings::FORCE_DISABLED_FILTER, '__return_true' );
		$this->assertSame( 'live', $this->code_row( $code )['status'] );

		// Switched on, the same request reaches the endpoint instead of the module's off-state
		// 404. What the endpoint then answers depends on which client registry is loaded.
		$this->assertNotSame( 404, $dispatch()->status() );
		$this->assertNotSame( 'live', $this->code_row( $code )['status'], 'the endpoint acted on the code' );
	}

	public function test_webapp_client_requires_secret_at_token_endpoint() {
		$app       = $this->webapp_code();
		$overrides = $this->webapp_overrides( $app['client_id'] );
		$this->assert_error( $this->post( $this->exchange_body( $app['code'], $overrides ) ), 401, 'invalid_client' );
		$this->assert_error( $this->post( $this->exchange_body( $app['code'], $overrides ), $this->basic( $app['client_id'], $app['client_secret'] . 'x' ) ), 401, 'invalid_client' );
		$this->assert_error( $this->post( $this->exchange_body( $app['code'], $overrides + array( 'client_secret' => 'mwpws_wrong' ) ) ), 401, 'invalid_client' );
		$this->assertSame( 'live', $this->code_row( $app['code'] )['status'] );
		$this->assert_issued( $this->post( $this->exchange_body( $app['code'], $overrides ), $this->basic( $app['client_id'], $app['client_secret'] ) ) );
	}

	public function test_webapp_client_secret_basic_and_post_both_accepted() {
		$app       = $this->webapp_code();
		$overrides = $this->webapp_overrides( $app['client_id'] );
		$basic     = $this->assert_issued( $this->post( $this->exchange_body( $app['code'], array( 'client_id' => null ) + $overrides ), $this->basic( $app['client_id'], $app['client_secret'] ) ) );
		$this->assertTrue( $this->access_ok( $basic['access_token'] ) );

		$second = $this->issue_code( 0, $overrides );
		$post   = $this->assert_issued( $this->post( $this->exchange_body( $second, $overrides + array( 'client_secret' => $app['client_secret'] ) ) ) );
		$this->assertTrue( $this->access_ok( $post['access_token'] ) );

		$third = $this->issue_code( 0, $overrides );
		$this->assert_error( $this->post( $this->exchange_body( $third, $overrides + array( 'client_secret' => $app['client_secret'] ) ), $this->basic( $app['client_id'], $app['client_secret'] ) ), 401, 'invalid_client' );
		$this->assertSame( 'live', $this->code_row( $third )['status'] );
	}

	public function test_webapp_client_auth_enforced_on_refresh() {
		$app  = $this->webapp_code();
		$auth = $this->basic( $app['client_id'], $app['client_secret'] );
		$data = $this->assert_issued( $this->post( $this->exchange_body( $app['code'], $this->webapp_overrides( $app['client_id'] ) ), $auth ) );
		$body = $this->refresh_body( $data['refresh_token'], array( 'client_id' => $app['client_id'] ) );
		$this->assert_error( $this->post( $body ), 401, 'invalid_client' );
		$this->assert_error( $this->post( $body, $this->basic( $app['client_id'], 'mwpws_wrong' ) ), 401, 'invalid_client' );
		$this->assertSame( '1', (string) $this->grant( $this->grant_of( $data['refresh_token'] ) )['current_pair'] );
		$this->assert_issued( $this->post( $body, $auth ) );
	}

	public function test_registered_client_with_secret_refused() {
		$app       = $this->store->create_client( 'registered', array( 'redirect_uris' => array( self::LOCAL_CB ) ) );
		$overrides = array(
			'client_id'    => $app['client_id'],
			'redirect_uri' => self::LOCAL_CB,
		);
		$code      = $this->issue_code( 0, $overrides );
		$this->assert_error( $this->post( $this->exchange_body( $code, $overrides + array( 'client_secret' => 'mwpws_guess' ) ) ), 401, 'invalid_client' );
		$this->assertSame( 'live', $this->code_row( $code )['status'] );
		$this->assert_issued( $this->post( $this->exchange_body( $code, $overrides ) ) );
	}

	public function test_token_kind_client_refused_at_token_endpoint() {
		$minted = $this->store->mint_connection_token( $this->user_id, 'mainwp:read', OAuth_Store::POLICY_STRICT, 'Laptop', $this->clock->now + DAY_IN_SECONDS );
		$this->assertSame( OAuth_Store::OK, $minted['outcome'] );
		$code = $this->issue_code();
		$this->assert_error( $this->post( $this->exchange_body( $code, array( 'client_id' => OAuth_Store::CONNECTION_TOKEN_CLIENT_ID ) ) ), 401, 'invalid_client' );
		$this->assert_error( $this->post( $this->refresh_body( 'mwprt_' . str_repeat( 'a', 43 ), array( 'client_id' => OAuth_Store::CONNECTION_TOKEN_CLIENT_ID ) ) ), 401, 'invalid_client' );
		$this->assertSame( 'live', $this->code_row( $code )['status'] );
	}

	public function test_webapp_secret_survives_module_secret_rotation() {
		$app  = $this->webapp_code();
		$auth = $this->basic( $app['client_id'], $app['client_secret'] );
		$data = $this->assert_issued( $this->post( $this->exchange_body( $app['code'], $this->webapp_overrides( $app['client_id'] ) ), $auth ) );

		$this->store->rotate_secret();
		$this->assert_error( $this->post( $this->refresh_body( $data['refresh_token'], array( 'client_id' => $app['client_id'] ) ), $auth ), 400, 'invalid_grant' );

		// Fresh consent after the rotation: the same id and secret still authenticate.
		$code  = $this->issue_code( 0, $this->webapp_overrides( $app['client_id'] ) );
		$fresh = $this->assert_issued( $this->post( $this->exchange_body( $code, $this->webapp_overrides( $app['client_id'] ) ), $auth ) );
		$this->assertTrue( $this->access_ok( $fresh['access_token'] ) );
	}

	public function test_code_and_refresh_refused_when_client_left_registry() {
		// A bundled client dropped from the registry by a plugin update.
		$data                     = $this->connect_via_endpoint();
		$code                     = $this->issue_code();
		$this->clients->removed[] = self::CLIENT_ID;
		$this->assert_error( $this->post( $this->exchange_body( $code ) ), 400, 'invalid_grant' );
		$this->assert_error( $this->post( $this->refresh_body( $data['refresh_token'] ) ), 400, 'invalid_grant' );
		$this->clients->removed = array();

		// A static client switched off in the panel.
		$cursor     = array(
			'client_id'    => self::CURSOR,
			'redirect_uri' => self::CURSOR_CB,
		);
		$static     = $this->assert_issued( $this->post( $this->exchange_body( $this->issue_code( 0, $cursor ), $cursor ) ) );
		$later_code = $this->issue_code( 0, $cursor );
		Remote_MCP_Settings::set_static_client_enabled( self::CURSOR, false );
		$this->assert_error( $this->post( $this->exchange_body( $later_code, $cursor ) ), 400, 'invalid_grant' );
		$this->assert_error( $this->post( $this->refresh_body( $static['refresh_token'], array( 'client_id' => self::CURSOR ) ) ), 400, 'invalid_grant' );

		// A registration removed in the panel: invalid_client, so the app registers again.
		$app        = $this->store->create_client( 'registered', array( 'redirect_uris' => array( self::LOCAL_CB ) ) );
		$overrides  = array(
			'client_id'    => $app['client_id'],
			'redirect_uri' => self::LOCAL_CB,
		);
		$registered = $this->assert_issued( $this->post( $this->exchange_body( $this->issue_code( 0, $overrides ), $overrides ) ) );
		$pending    = $this->issue_code( 0, $overrides );
		$this->assertTrue( $this->store->delete_client( $app['client_id'] ) );
		$this->assert_error( $this->post( $this->exchange_body( $pending, $overrides ) ), 401, 'invalid_client' );
		$this->assert_error( $this->post( $this->refresh_body( $registered['refresh_token'], array( 'client_id' => $app['client_id'] ) ) ), 401, 'invalid_client' );
	}

	public function test_code_exchange_fires_grant_issued_once() {
		$fired = array();
		add_action(
			Remote_MCP_Events::GRANT_ISSUED,
			function ( ...$args ) use ( &$fired ) {
				$fired[] = $args;
			},
			10,
			5
		);
		$code = $this->issue_code();
		$data = $this->assert_issued( $this->post( $this->exchange_body( $code ) ) );
		$this->assert_issued( $this->post( $this->refresh_body( $data['refresh_token'] ) ) );
		$this->post( $this->exchange_body( $code ) );
		$this->post( $this->exchange_body( $this->issue_code(), array( 'code_verifier' => strrev( self::VERIFIER ) ) ) );

		$grant = (int) $this->code_row( $code )['grant_id'];
		$this->assertSame( array( array( $grant, $this->user_id, self::CLIENT_ID, 'bundled', 'code_exchange' ) ), $fired );
	}

	public function test_token_endpoint_fires_audit_events_for_code_refresh_and_replay() {
		$events = $this->capture_audit();
		$code   = $this->issue_code();
		$data   = $this->assert_issued( $this->post( $this->exchange_body( $code ) ) );
		$grant  = (int) $this->code_row( $code )['grant_id'];
		$before = $this->grant( $grant );
		foreach ( array(
			'client_id'     => self::CURSOR,
			'code_verifier' => strrev( self::VERIFIER ),
			'redirect_uri'  => self::CURSOR_CB,
			'resource'      => 'https://other.example.org/mcp',
		) as $field => $value ) {
			$this->assert_error( $this->post( $this->exchange_body( $code, array( $field => $value ) ) ), 400, 'invalid_grant' );
			$this->assertSame( $before, $this->grant( $grant ), 'invalid replays leave the grant and failure status untouched' );
		}
		$this->assert_error( $this->post( $this->exchange_body( 'mwpac_' . str_repeat( 'b', 43 ) ) ), 400, 'invalid_grant' );
		$this->assertSame( $before, $this->grant( $grant ), 'unknown codes leave known grants untouched' );
		$this->assertSame( array( 'token_issued' ), $this->event_names( $events ), 'invalid and unknown replays emit no grant audit' );
		$this->assert_issued( $this->post( $this->refresh_body( $data['refresh_token'] ) ) );
		$this->tick( 31 );
		$this->post( $this->refresh_body( $data['refresh_token'] ) );
		$this->post( $this->exchange_body( $code ) );

		$this->assertSame( array( 'token_issued', 'token_refreshed', 'refresh_replay', 'code_replay' ), $this->event_names( $events ) );
		foreach ( array( 0, 1, 2, 3 ) as $index ) {
			$fields = $events[ $index ][1];
			$this->assertSame( $grant, $fields['grant_id'] ?? null );
			$this->assertSame( $this->user_id, $fields['user_id'] ?? null );
			$this->assertSame( 'claude.ai', $fields['client_host'] ?? null );
			$this->assertSame( 'token', $fields['endpoint'] ?? '' );
			$this->assertSame( 'bundled', $fields['client_kind'] ?? '' );
			$this->assertSame( '203.0.113.7', $fields['client_ip'] ?? '' );
		}
		$this->assertSame( OAuth_Store::INVALID_GRANT, $events[3][1]['outcome'] ?? null );
		$this->assertSame( 'code_replay', $this->grant( $grant )['last_failure'], 'confirmed code replay records its grant failure' );
		$this->assertSame( (string) $this->clock->now, (string) $this->grant( $grant )['last_failure_at'] );
	}

	public function test_refresh_failure_on_known_grant_records_failure() {
		$data  = $this->connect_via_endpoint();
		$grant = $this->grant_of( $data['refresh_token'] );
		$this->assertNull( $this->grant( $grant )['last_failure'] );
		$this->assert_error( $this->post( $this->refresh_body( $data['refresh_token'], array( 'scope' => 'mainwp:read mainwp:write offline_access' ) ) ), 400, 'invalid_scope' );
		$row = $this->grant( $grant );
		$this->assertSame( 'invalid_scope', $row['last_failure'] );
		$this->assertSame( (string) $this->clock->now, (string) $row['last_failure_at'] );

		// An unknown token names no grant, so nothing is recorded anywhere.
		$this->assert_error( $this->post( $this->refresh_body( 'mwprt_' . str_repeat( 'b', 43 ) ) ), 400, 'invalid_grant' );
		$this->assertSame( 'invalid_scope', $this->grant( $grant )['last_failure'] );
		$this->assertCount( 1, $this->rows( 'grants', 'last_failure IS NOT NULL' ) );
	}
}
