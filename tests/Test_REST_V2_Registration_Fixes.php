<?php
/**
 * MainWP REST v2 Registration Fixes Tests
 *
 * Covers registration/schema declarations and the handler behavior that
 * depends on them for the batch, settings, clients, tags and monitors
 * controllers.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use WP_REST_Request;
use WP_REST_Server;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- test classes use PSR-style Test_* filenames, not the mainwp-* convention.

/**
 * Class Test_REST_V2_Registration_Fixes
 */
class Test_REST_V2_Registration_Fixes extends \WP_Test_REST_TestCase {

	/**
	 * REST server instance.
	 *
	 * @var WP_REST_Server
	 */
	protected $server;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	protected $admin_user_id;

	/**
	 * Consumer key for REST API authentication.
	 *
	 * @var string
	 */
	protected $consumer_key;

	/**
	 * Consumer secret for REST API authentication.
	 *
	 * @var string
	 */
	protected $consumer_secret;

	/**
	 * Set up test environment.
	 *
	 * Mirrors tests/abilities/test-rest-integration.php: the MainWP REST server
	 * singleton caches controller instances, so its cached controllers have to be
	 * cleared before a fresh WP_REST_Server can receive the route registrations.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		$reflection           = new \ReflectionClass( \MainWP_Rest_Server::class );
		$controllers_property = $reflection->getProperty( 'controllers' );
		$controllers_property->setAccessible( true );
		$controllers_property->setValue( \MainWP_Rest_Server::instance(), [] );

		\MainWP_REST_Authentication::$instance = null;

		// WordPress test teardown removes hooks between tests, so re-add the route registration.
		if ( ! has_action( 'rest_api_init', [ \MainWP_Rest_Server::instance(), 'register_rest_routes' ] ) ) {
			add_action( 'rest_api_init', [ \MainWP_Rest_Server::instance(), 'register_rest_routes' ], 10 );
		}

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	/**
	 * Tear down test environment.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wpdb, $wp_rest_server;
		$wp_rest_server = null;

		\MainWP_REST_Authentication::$instance = null;

		$reflection = new \ReflectionClass( \MainWP_Rest_Server::class );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}mainwp_api_keys WHERE description = %s", 'Test API Key' ) );

		parent::tearDown();
	}

	/**
	 * Authenticate as admin for REST requests.
	 *
	 * @return void
	 */
	protected function authenticate_as_admin(): void {
		$this->admin_user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_user_id );

		$api_key               = $this->create_rest_api_key( $this->admin_user_id );
		$this->consumer_key    = $api_key['consumer_key'];
		$this->consumer_secret = $api_key['consumer_secret'];
	}

	/**
	 * Create a REST API key for testing.
	 *
	 * @param int    $user_id     User ID to associate with the key.
	 * @param string $permissions Permissions level: 'read', 'write', or 'read_write'.
	 * @return array Array with 'consumer_key' and 'consumer_secret'.
	 */
	protected function create_rest_api_key( int $user_id, string $permissions = 'read_write' ): array {
		global $wpdb;

		$consumer_key    = 'ck_' . bin2hex( random_bytes( 16 ) );
		$consumer_secret = 'cs_' . bin2hex( random_bytes( 16 ) );

		$wpdb->insert(
			$wpdb->prefix . 'mainwp_api_keys',
			[
				'user_id'         => $user_id,
				'description'     => 'Test API Key',
				'permissions'     => $permissions,
				'consumer_key'    => mainwp_api_hash( $consumer_key ),
				'consumer_secret' => $consumer_secret,
				'truncated_key'   => substr( $consumer_key, -7 ),
				'enabled'         => 1,
				'last_access'     => current_time( 'mysql' ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
		);

		return [
			'consumer_key'    => $consumer_key,
			'consumer_secret' => $consumer_secret,
		];
	}

	/**
	 * Make an authenticated REST request with a JSON body.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  REST route.
	 * @param array  $body   Body payload, encoded as JSON.
	 * @param array  $params Query parameters.
	 * @return \WP_REST_Response Response object.
	 */
	protected function do_authenticated_request( string $method, string $route, array $body = [], array $params = [] ): \WP_REST_Response {
		$original_get    = $_GET;
		$original_server = $_SERVER;

		$_GET['consumer_key']    = $this->consumer_key;
		$_GET['consumer_secret'] = $this->consumer_secret;
		$_SERVER['HTTPS']        = 'on';
		$_SERVER['REQUEST_URI']  = '/wp-json' . $route;

		\MainWP_REST_Authentication::$instance = null;
		$auth                                  = \MainWP_REST_Authentication::get_instance();
		$auth->authenticate( 0 );

		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		if ( ! empty( $body ) ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}

		$response = rest_do_request( $request );

		$_GET    = $original_get;
		$_SERVER = $original_server;

		return $response;
	}

	/**
	 * Get the registered args of a route.
	 *
	 * @param string $route  Route path.
	 * @param string $method HTTP method the handler must accept.
	 * @return array Registered args.
	 */
	protected function get_route_args( string $route, string $method ): array {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( $route, $routes, 'Route not registered: ' . $route );

		foreach ( $routes[ $route ] as $handler ) {
			if ( ! empty( $handler['methods'][ $method ] ) ) {
				return isset( $handler['args'] ) ? $handler['args'] : [];
			}
		}

		$this->fail( 'No ' . $method . ' handler registered for ' . $route );
	}

	/**
	 * Fix 1: the batch endpoint reports the unsupported updates group once, not per item.
	 */
	public function test_batch_updates_group_returns_group_error(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/batch',
			[
				'updates' => [
					'create' => [
						[ 'site_id' => 1 ],
					],
				],
			]
		);

		$data = $response->get_data();

		$this->assertArrayHasKey( 'updates', $data );
		$this->assertArrayHasKey( 'error', $data['updates'] );
		$this->assertSame( 'rest_batch_group_not_supported', $data['updates']['error']['code'] );
		$this->assertSame( 400, $data['updates']['error']['data']['status'] );
		$this->assertArrayNotHasKey( 'create', $data['updates'] );
	}

	/**
	 * Fix 1: other groups keep processing and unknown groups stay silently ignored.
	 */
	public function test_batch_processes_other_groups_and_ignores_unknown_groups(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/batch',
			[
				'updates'    => [
					'create' => [
						[ 'site_id' => 1 ],
					],
				],
				'clients'    => [
					'create' => [
						[ 'name' => 'REST V2 Fixes Client' ],
					],
				],
				'unknown_group' => [
					'create' => [
						[ 'name' => 'nothing' ],
					],
				],
			]
		);

		$data = $response->get_data();

		$this->assertSame( 'rest_batch_group_not_supported', $data['updates']['error']['code'] );
		$this->assertArrayHasKey( 'clients', $data );
		$this->assertArrayHasKey( 'create', $data['clients'] );
		$this->assertArrayNotHasKey( 'unknown_group', $data );
	}

	/**
	 * Fix 1: the updates group is rejected as a group but still counts toward the batch limit,
	 * so an oversized request is refused before any other group is dispatched.
	 */
	public function test_batch_limit_counts_updates_items_and_rejects_before_mutation(): void {
		$this->authenticate_as_admin();

		$lower_limit = static function () {
			return 2;
		};
		add_filter( 'mainwp_rest_batch_items_limit', $lower_limit );

		$before = (int) \MainWP\Dashboard\MainWP_DB_Client::instance()->get_wp_clients( [ 'count_only' => true ] );

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/batch',
			[
				'clients' => [
					'create' => [
						[ 'name' => 'REST V2 Batch Limit Client A' ],
						[ 'name' => 'REST V2 Batch Limit Client B' ],
					],
				],
				'updates' => [
					'create' => [
						[ 'site_id' => 1 ],
					],
				],
			]
		);

		remove_filter( 'mainwp_rest_batch_items_limit', $lower_limit );

		$this->assertSame( 413, $response->get_status() );
		$this->assertSame( 'mainwp_rest_request_entity_too_large', $response->get_data()['code'] );

		$after = (int) \MainWP\Dashboard\MainWP_DB_Client::instance()->get_wp_clients( [ 'count_only' => true ] );
		$this->assertSame( $before, $after, 'A request over the batch limit must be rejected before any item is created.' );
	}

	/**
	 * Fix 4: per_page enforces its declared bounds.
	 */
	public function test_client_fields_per_page_enforces_bounds(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request( 'GET', '/mainwp/v2/clients/fields', [], [ 'per_page' => 201 ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );

		$response = $this->do_authenticated_request( 'GET', '/mainwp/v2/clients/fields', [], [ 'per_page' => 50 ] );

		$this->assertNotSame( 400, $response->get_status() );
	}

	/**
	 * Fix 4: the pre_page alias enforces the same bounds.
	 */
	public function test_client_fields_pre_page_enforces_bounds(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request( 'GET', '/mainwp/v2/clients/fields', [], [ 'pre_page' => 201 ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );

		$response = $this->do_authenticated_request( 'GET', '/mainwp/v2/clients/fields', [], [ 'pre_page' => 50 ] );

		$this->assertNotSame( 400, $response->get_status() );
	}

	/**
	 * Fix 2: daily update params describe the integer value they actually accept.
	 */
	public function test_daily_update_params_registered_as_integer_enum(): void {
		$args = $this->get_route_args( '/mainwp/v2/settings/general/edit', 'PUT' );

		foreach ( [ 'trans_automatic_daily_update', 'automatic_daily_update' ] as $param ) {
			$this->assertArrayHasKey( $param, $args );
			$this->assertSame( 'integer', $args[ $param ]['type'], $param . ' should be declared as integer.' );
			$this->assertSame( [ 0, 1 ], $args[ $param ]['enum'], $param . ' should declare its allowed values.' );
		}
	}

	/**
	 * Fix 2: a non-scalar value on a scalar enum param is a clean error, not a fatal.
	 */
	public function test_enum_sanitizer_rejects_non_scalar_value(): void {
		$controller = \MainWP_Rest_Settings_Controller::instance();
		$sanitizer  = $controller->make_enum_sanitizer( [ 0, 1 ] );
		$request    = new WP_REST_Request( 'PUT', '/mainwp/v2/settings/general/edit' );

		$result = $sanitizer( [ 1 ], $request, 'automatic_daily_update' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_automatic_daily_update', $result->get_error_code() );
	}

	/**
	 * Fix 2: the validator mirrors the sanitizer for non-scalar values.
	 */
	public function test_enum_validator_rejects_non_scalar_value(): void {
		$controller = \MainWP_Rest_Settings_Controller::instance();
		$validator  = $controller->make_enum_validator( [ 0, 1 ] );
		$request    = new WP_REST_Request( 'PUT', '/mainwp/v2/settings/general/edit' );

		$result = $validator( [ 1 ], $request, 'automatic_daily_update' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_automatic_daily_update', $result->get_error_code() );
	}

	/**
	 * Fix 2: scalar values keep their existing behavior.
	 */
	public function test_enum_helpers_keep_scalar_behavior(): void {
		$controller = \MainWP_Rest_Settings_Controller::instance();
		$sanitizer  = $controller->make_enum_sanitizer( [ 0, 1 ] );
		$validator  = $controller->make_enum_validator( [ 0, 1 ] );
		$request    = new WP_REST_Request( 'PUT', '/mainwp/v2/settings/general/edit' );

		$this->assertSame( 1, $sanitizer( '1', $request, 'automatic_daily_update' ) );
		$this->assertTrue( $validator( '1', $request, 'automatic_daily_update' ) );
		$this->assertSame( '', $sanitizer( '', $request, 'automatic_daily_update' ) );
		$this->assertTrue( $validator( '', $request, 'automatic_daily_update' ) );
		$this->assertInstanceOf( \WP_Error::class, $sanitizer( '7', $request, 'automatic_daily_update' ) );
	}

	/**
	 * Fix 3: uptime timeout/interval accept label strings, so they must declare them.
	 */
	public function test_uptime_timeout_and_interval_registered_as_string_enum(): void {
		$args = $this->get_route_args( '/mainwp/v2/settings/monitoring/edit', 'PUT' );

		$this->assertSame( 'string', $args['mainwp_uptime_monitoring_timeout']['type'] );
		$this->assertContains( '30s', $args['mainwp_uptime_monitoring_timeout']['enum'] );
		$this->assertContains( 'No limit', $args['mainwp_uptime_monitoring_timeout']['enum'] );

		$this->assertSame( 'string', $args['mainwp_uptime_monitoring_interval']['type'] );
		$this->assertContains( '5m', $args['mainwp_uptime_monitoring_interval']['enum'] );
		$this->assertContains( '1h', $args['mainwp_uptime_monitoring_interval']['enum'] );
	}

	/**
	 * Fix 3: the public item schema agrees with the label strings the read endpoint returns.
	 */
	public function test_uptime_timeout_and_interval_item_schema_is_string(): void {
		$schema = \MainWP_Rest_Settings_Controller::instance()->get_item_schema();

		$this->assertSame( 'string', $schema['properties']['mainwp_uptime_monitoring_timeout']['type'] );
		$this->assertSame( 'string', $schema['properties']['mainwp_uptime_monitoring_interval']['type'] );
	}

	/**
	 * Fix 3: an empty timeout no longer writes a bogus value.
	 */
	public function test_monitoring_edit_rejects_empty_timeout(): void {
		$this->authenticate_as_admin();

		$before = \MainWP\Dashboard\MainWP_Uptime_Monitoring_Handle::get_global_monitoring_settings();

		$response = $this->do_authenticated_request(
			'PUT',
			'/mainwp/v2/settings/monitoring/edit',
			[ 'mainwp_uptime_monitoring_timeout' => '' ]
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );

		$after = \MainWP\Dashboard\MainWP_Uptime_Monitoring_Handle::get_global_monitoring_settings();
		$this->assertSame( $before['timeout'], $after['timeout'] );
	}

	/**
	 * Fix 3: an empty interval is rejected the same way.
	 */
	public function test_monitoring_edit_rejects_empty_interval(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request(
			'PUT',
			'/mainwp/v2/settings/monitoring/edit',
			[ 'mainwp_uptime_monitoring_interval' => '' ]
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
	}

	/**
	 * Fix 3: a valid label still saves.
	 */
	public function test_monitoring_edit_accepts_valid_timeout_label(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request(
			'PUT',
			'/mainwp/v2/settings/monitoring/edit',
			[ 'mainwp_uptime_monitoring_timeout' => '60s' ]
		);

		$this->assertSame( 200, $response->get_status() );

		$settings = \MainWP\Dashboard\MainWP_Uptime_Monitoring_Handle::get_global_monitoring_settings();
		$this->assertSame( 60, (int) $settings['timeout'] );
	}

	/**
	 * Fix 4: client fields expose a working per_page and keep the pre_page alias.
	 */
	public function test_client_fields_registers_per_page_and_keeps_pre_page(): void {
		$args = $this->get_route_args( '/mainwp/v2/clients/fields', 'GET' );

		$this->assertArrayHasKey( 'per_page', $args );
		$this->assertSame( 'integer', $args['per_page']['type'] );
		$this->assertSame( 1, $args['per_page']['minimum'] );
		$this->assertSame( 200, $args['per_page']['maximum'] );

		$this->assertArrayHasKey( 'pre_page', $args );
	}

	/**
	 * Fix 4: pre_page still sizes the page, per_page wins when both are sent.
	 */
	public function test_client_fields_pre_page_aliases_per_page(): void {
		$controller = \MainWP_Rest_Clients_Controller::instance();

		$request = new WP_REST_Request( 'GET', '/mainwp/v2/clients/fields' );
		$request->set_param( 'pre_page', 5 );
		$controller->client_fields( $request );
		$this->assertSame( 5, (int) $request->get_param( 'per_page' ) );

		$request = new WP_REST_Request( 'GET', '/mainwp/v2/clients/fields' );
		$request->set_param( 'pre_page', 5 );
		$request->set_param( 'per_page', 7 );
		$controller->client_fields( $request );
		$this->assertSame( 7, (int) $request->get_param( 'per_page' ) );
	}

	/**
	 * Fix 5: count_sites is an integer, not the invalid 'absint' JSON-Schema type.
	 */
	public function test_tags_count_sites_schema_type_is_integer(): void {
		$schema = \MainWP_Rest_Tags_Controller::instance()->get_item_schema();

		$this->assertSame( 'integer', $schema['properties']['count_sites']['type'] );
		$this->assertSame( 'absint', $schema['properties']['count_sites']['sanitize_callback'] );
	}

	/**
	 * Fix 6: expected_status only ever accepts a comma separated string.
	 */
	public function test_monitors_expected_status_registered_as_string(): void {
		$args = $this->get_route_args( '/mainwp/v2/monitors/settings', 'PUT' );

		$this->assertSame( 'string', $args['expected_status']['type'] );
	}

	/**
	 * Fix 6: the expected_status callbacks accept a status code list and reject bad codes.
	 */
	public function test_monitors_expected_status_callbacks_handle_code_list(): void {
		$controller = \MainWP_Rest_Monitors_Controller::instance();
		$request    = new WP_REST_Request( 'PUT', '/mainwp/v2/monitors/settings' );

		$this->assertSame( '200,301', $controller->sanitize_expected_status_text_field( '200,301', $request ) );
		$this->assertTrue( $controller->settings_validate_expected_status_param( '200,301', $request, 'expected_status' ) );

		$this->assertInstanceOf( \WP_Error::class, $controller->sanitize_expected_status_text_field( '999', $request ) );
		$this->assertInstanceOf( \WP_Error::class, $controller->settings_validate_expected_status_param( '999', $request, 'expected_status' ) );
	}
}
