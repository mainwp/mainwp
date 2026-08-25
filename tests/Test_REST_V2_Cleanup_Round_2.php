<?php
/**
 * MainWP REST v2 Cleanup Round 2 Tests
 *
 * Covers the error status codes, read-back fixes and schema declarations
 * cleaned up after PR #204 across the settings, clients, monitors, batch
 * and tags controllers.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use WP_REST_Request;
use WP_REST_Server;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- test classes use PSR-style Test_* filenames, not the mainwp-* convention.

/**
 * Class Test_REST_V2_Cleanup_Round_2
 */
class Test_REST_V2_Cleanup_Round_2 extends \WP_Test_REST_TestCase {

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
	 * Make an authenticated REST request with a raw body.
	 *
	 * The JSON helper above skips the content-type header for an empty payload,
	 * so the empty-body path needs a request that still declares the type.
	 *
	 * @param string $method       HTTP method.
	 * @param string $route        REST route.
	 * @param string $body         Raw request body.
	 * @param string $content_type Content type header value.
	 * @return \WP_REST_Response Response object.
	 */
	protected function do_authenticated_raw_request( string $method, string $route, string $body = '', string $content_type = 'application/json' ): \WP_REST_Response {
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
		$request->set_header( 'content-type', $content_type );
		$request->set_body( $body );

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
	 * Collect every `new WP_Error( ... )` construction in a PHP file.
	 *
	 * Tokenizing rather than matching parentheses in raw text keeps braces and
	 * parentheses inside message strings from ending a construction early.
	 *
	 * @param string $file Absolute path to the PHP file.
	 * @return array List of [ 'line' => int, 'source' => string ] entries.
	 */
	protected function collect_wp_error_constructions( string $file ): array {
		$tokens = token_get_all( file_get_contents( $file ) );
		$found  = [];
		$count  = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! is_array( $tokens[ $i ] ) || T_NEW !== $tokens[ $i ][0] ) {
				continue;
			}

			$j = $i + 1;
			while ( $j < $count && is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], [ T_WHITESPACE, T_NS_SEPARATOR ], true ) ) {
				++$j;
			}

			if ( $j >= $count || ! is_array( $tokens[ $j ] ) || T_STRING !== $tokens[ $j ][0] || 'WP_Error' !== $tokens[ $j ][1] ) {
				continue;
			}

			$line   = $tokens[ $j ][2];
			$source = '';
			$depth  = 0;
			for ( $k = $j + 1; $k < $count; $k++ ) {
				$text    = is_array( $tokens[ $k ] ) ? $tokens[ $k ][1] : $tokens[ $k ];
				$source .= $text;
				if ( '(' === $tokens[ $k ] ) {
					++$depth;
				} elseif ( ')' === $tokens[ $k ] ) {
					--$depth;
					if ( 0 === $depth ) {
						break;
					}
				}
			}

			$found[] = [
				'line'   => $line,
				'source' => $source,
			];
		}

		return $found;
	}

	/**
	 * Item 1: every settings controller error declares the HTTP status it should return.
	 *
	 * Without a status WordPress falls back to 500, so validation and not-found
	 * errors surfaced as server errors.
	 */
	public function test_settings_controller_errors_declare_a_status(): void {
		$file = dirname( __DIR__ ) . '/includes/rest-api/controller/version2/class-mainwp-rest-settings-controller.php';
		$this->assertFileExists( $file );

		$constructions = $this->collect_wp_error_constructions( $file );
		$this->assertGreaterThan( 50, count( $constructions ), 'Expected the settings controller to construct many errors.' );

		$missing = [];
		foreach ( $constructions as $construction ) {
			if ( false === strpos( $construction['source'], "'status'" ) ) {
				$missing[] = $construction['line'];
			}
		}

		$this->assertSame( [], $missing, 'WP_Error constructions without a status, at lines: ' . implode( ', ', $missing ) );
	}

	/**
	 * Item 1: a bad settings write is a client error, not a server error.
	 */
	public function test_general_settings_edit_rejects_empty_body_with_400(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_raw_request( 'PUT', '/mainwp/v2/settings/general/edit' );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'empty_body', $response->get_data()['code'] );
	}

	/**
	 * Item 1: an unknown job id is a not-found, not a server error.
	 */
	public function test_destroy_sessions_status_returns_404_for_unknown_job(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request( 'GET', '/mainwp/v2/settings/tools/destroy-sessions-status/no-such-job' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'destroy_session_job_not_found', $response->get_data()['code'] );
	}

	/**
	 * Item 2: the general settings read returns the stored sidebar position.
	 *
	 * The read used a misspelled option name and a defaults key that does not
	 * exist, so it always missed and raised an undefined index warning. Warnings
	 * are exceptions in this suite, so reaching the assertions proves it is gone.
	 */
	public function test_general_settings_returns_stored_sidebar_position(): void {
		$this->authenticate_as_admin();

		update_user_option( $this->admin_user_id, 'mainwp_sidebarPosition', 0, true );

		$response = $this->do_authenticated_request( 'GET', '/mainwp/v2/settings/general' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'sidebar_position', $data['data'] );
		$this->assertSame( 0, (int) $data['data']['sidebar_position'] );

		update_user_option( $this->admin_user_id, 'mainwp_sidebarPosition', 1, true );

		$data = $this->do_authenticated_request( 'GET', '/mainwp/v2/settings/general' )->get_data();
		$this->assertSame( 1, (int) $data['data']['sidebar_position'] );
	}

	/**
	 * Item 2: the write and the read agree on the option name.
	 */
	public function test_general_settings_sidebar_position_round_trips_through_the_api(): void {
		$this->authenticate_as_admin();

		update_user_option( $this->admin_user_id, 'mainwp_sidebarPosition', 1, true );

		$response = $this->do_authenticated_request( 'PUT', '/mainwp/v2/settings/general/edit', [ 'sidebar_position' => 0 ] );
		$this->assertSame( 200, $response->get_status() );

		$data = $this->do_authenticated_request( 'GET', '/mainwp/v2/settings/general' )->get_data();
		$this->assertSame( 0, (int) $data['data']['sidebar_position'] );
	}

	/**
	 * Item 8: all four daily update params describe the integer value they accept.
	 */
	public function test_all_daily_update_params_registered_as_integer_enum(): void {
		$args = $this->get_route_args( '/mainwp/v2/settings/general/edit', 'PUT' );

		$params = [
			'plugin_automatic_daily_update',
			'theme_automatic_daily_update',
			'trans_automatic_daily_update',
			'automatic_daily_update',
		];

		foreach ( $params as $param ) {
			$this->assertArrayHasKey( $param, $args );
			$this->assertSame( 'integer', $args[ $param ]['type'], $param . ' should be declared as integer.' );
			$this->assertSame( [ 0, 1 ], $args[ $param ]['enum'], $param . ' should declare its allowed values.' );
		}
	}

	/**
	 * Item 8: the item schema agrees with the integer the read endpoint returns.
	 */
	public function test_all_daily_update_item_schema_types_are_integer(): void {
		$schema = \MainWP_Rest_Settings_Controller::instance()->get_item_schema();

		foreach ( [ 'plugin_automatic_daily_update', 'theme_automatic_daily_update', 'trans_automatic_daily_update', 'automatic_daily_update' ] as $param ) {
			$this->assertSame( 'integer', $schema['properties'][ $param ]['type'], $param . ' should be an integer in the item schema.' );
		}
	}

	/**
	 * Item 3: clients fields/add accepts a JSON body.
	 */
	public function test_client_fields_add_accepts_json_body(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/clients/fields/add',
			[
				'name'        => 'REST V2 Cleanup Field',
				'description' => 'REST V2 Cleanup Field description',
			]
		);

		$data = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $data['success'] );
		$this->assertSame( 'REST V2 Cleanup Field', $data['data']['name'] );

		$this->delete_client_field( 'REST V2 Cleanup Field' );
	}

	/**
	 * Item 3: an add with no body is a 400, not a 500.
	 */
	public function test_client_fields_add_without_body_returns_400(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request( 'POST', '/mainwp/v2/clients/fields/add' );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Item 3: clients fields/edit accepts a JSON body and reports its own errors as 4xx.
	 */
	public function test_client_fields_edit_accepts_json_body(): void {
		$this->authenticate_as_admin();

		$field = \MainWP\Dashboard\MainWP_DB_Client::instance()->add_client_field(
			[
				'field_name' => 'REST V2 Cleanup Edit Field',
				'field_desc' => 'before',
				'client_id'  => 0,
			]
		);
		$this->assertNotEmpty( $field, 'Could not create the client field the test edits.' );

		$response = $this->do_authenticated_request(
			'PUT',
			'/mainwp/v2/clients/fields/' . $field->field_id . '/edit',
			[ 'description' => 'after' ]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['success'] );

		$updated = \MainWP\Dashboard\MainWP_DB_Client::instance()->get_client_fields_by( 'field_id', $field->field_id );
		$this->assertSame( 'after', $updated->field_desc );

		$this->delete_client_field( 'REST V2 Cleanup Edit Field' );
	}

	/**
	 * Item 3: the edit route's own errors carry a 4xx status instead of falling back to 500.
	 */
	public function test_client_fields_edit_errors_carry_4xx_status(): void {
		$this->authenticate_as_admin();

		$field = \MainWP\Dashboard\MainWP_DB_Client::instance()->add_client_field(
			[
				'field_name' => 'REST V2 Cleanup Status Field',
				'field_desc' => 'before',
				'client_id'  => 0,
			]
		);
		$this->assertNotEmpty( $field, 'Could not create the client field the test edits.' );

		$response = $this->do_authenticated_request( 'PUT', '/mainwp/v2/clients/fields/' . $field->field_id . '/edit' );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'empty_body', $response->get_data()['code'] );

		$this->delete_client_field( 'REST V2 Cleanup Status Field' );

		$response = $this->do_authenticated_request(
			'PUT',
			'/mainwp/v2/clients/fields/REST V2 Cleanup Missing Field/edit',
			[ 'description' => 'after' ]
		);

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'invalid_field_id', $response->get_data()['code'] );
	}

	/**
	 * Item 4: a global monitoring settings write stores what the registered sanitizers produced,
	 * not the raw body value.
	 *
	 * This passed before the fix as well: WordPress writes the sanitized value back into the JSON
	 * body, so reading the body happened to give the sanitized value. The test pins the behavior
	 * now that the handler reads the params instead.
	 */
	public function test_monitors_global_settings_write_runs_registered_sanitizers(): void {
		$this->authenticate_as_admin();

		$before = \MainWP\Dashboard\MainWP_Uptime_Monitoring_Handle::get_global_monitoring_settings();

		$response = $this->do_authenticated_request(
			'PUT',
			'/mainwp/v2/monitors/settings',
			[ 'interval' => '5m' ]
		);

		$this->assertSame( 200, $response->get_status() );

		// sanitize_interval_text_field() maps the '5m' label onto the 5 minute key it is stored as.
		$settings = \MainWP\Dashboard\MainWP_Uptime_Monitoring_Handle::get_global_monitoring_settings();
		$this->assertSame( 5, (int) $settings['interval'] );
		$this->assertNotSame( '5m', (string) $settings['interval'] );

		\MainWP\Dashboard\MainWP_Uptime_Monitoring_Handle::update_uptime_global_settings( $before );
	}

	/**
	 * Item 5: the costs group has no controller, so it gets the per-group error.
	 */
	public function test_batch_costs_group_returns_group_error(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/batch',
			[
				'costs' => [
					'create' => [
						[ 'name' => 'REST V2 Cleanup Cost' ],
					],
				],
			]
		);

		$data = $response->get_data();

		$this->assertArrayHasKey( 'costs', $data );
		$this->assertSame( 'rest_batch_group_not_supported', $data['costs']['error']['code'] );
		$this->assertSame( 400, $data['costs']['error']['data']['status'] );
		$this->assertArrayNotHasKey( 'create', $data['costs'] );
	}

	/**
	 * Item 5: costs items still count toward the batch limit.
	 */
	public function test_batch_limit_counts_costs_items(): void {
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
						[ 'name' => 'REST V2 Cleanup Limit Client A' ],
						[ 'name' => 'REST V2 Cleanup Limit Client B' ],
					],
				],
				'costs'   => [
					'create' => [
						[ 'name' => 'REST V2 Cleanup Cost' ],
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
	 * Item 6: a group the batch endpoint does not handle is reported, not ignored.
	 */
	public function test_batch_unknown_group_returns_group_error(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/batch',
			[
				'unknown_group' => [
					'create' => [
						[ 'name' => 'nothing' ],
					],
				],
			]
		);

		$data = $response->get_data();

		$this->assertArrayHasKey( 'unknown_group', $data );
		$this->assertSame( 'rest_batch_group_not_supported', $data['unknown_group']['error']['code'] );
		$this->assertSame( 400, $data['unknown_group']['error']['data']['status'] );
	}

	/**
	 * Item 7: the tags list returns the integer types its schema declares.
	 */
	public function test_tags_list_returns_declared_integer_types(): void {
		$this->authenticate_as_admin();

		$tag = \MainWP\Dashboard\MainWP_DB_Common::instance()->add_tag( [ 'name' => 'REST V2 Cleanup Tag' ] );
		$this->assertNotEmpty( $tag, 'Could not create the tag the test reads back.' );

		$response = $this->do_authenticated_request( 'GET', '/mainwp/v2/tags' );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data()['data'];
		$this->assertArrayHasKey( $tag->id, $data );
		$this->assertIsInt( $data[ $tag->id ]['id'] );
		$this->assertIsInt( $data[ $tag->id ]['count_sites'] );
		$this->assertSame( (int) $tag->id, $data[ $tag->id ]['id'] );

		\MainWP\Dashboard\MainWP_DB_Common::instance()->remove_group( $tag->id );
	}

	/**
	 * Delete a client field created by a test.
	 *
	 * @param string $field_name Field name.
	 * @return void
	 */
	private function delete_client_field( string $field_name ): void {
		$field = \MainWP\Dashboard\MainWP_DB_Client::instance()->get_client_fields_by( 'field_name', $field_name );

		if ( ! empty( $field ) ) {
			\MainWP\Dashboard\MainWP_DB_Client::instance()->delete_client_field_by( 'field_id', $field->field_id, 0 );
		}
	}
}
