<?php
/**
 * MainWP REST v2 Cleanup Round 7 Tests
 *
 * Covers client_id on a site edit, the update and delete groups on the global
 * batch endpoint, force_use_ipv4 on the two site input routes, the Cost Tracker
 * schema types and callback spelling, and the dead tags schema callback.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use WP_REST_Request;
use WP_REST_Server;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- test classes use PSR-style Test_* filenames, not the mainwp-* convention.

/**
 * Class Test_REST_V2_Cleanup_Round_7
 */
class Test_REST_V2_Cleanup_Round_7 extends \WP_Test_REST_TestCase {

	/**
	 * Description of the API key rows this class creates.
	 *
	 * @var string
	 */
	const KEY_DESCRIPTION = 'rest-v2-cleanup-round-7 Test API Key';

	/**
	 * Name of the tag rows this class inserts.
	 *
	 * @var string
	 */
	const TAG_NAME = 'rest-v2-cleanup-round-7 Batch Tag';

	/**
	 * Name of the site rows this class inserts.
	 *
	 * @var string
	 */
	const SITE_NAME = 'rest-v2-cleanup-round-7 Site';

	/**
	 * Name of the cost rows this class inserts.
	 *
	 * @var string
	 */
	const COST_NAME = 'rest-v2-cleanup-round-7 Cost';

	/**
	 * Edit route key, as WP_REST_Server stores it.
	 *
	 * @var string
	 */
	const EDIT_ROUTE = '/mainwp/v2/sites/(?P<id_domain>[a-zA-Z0-9\-\.\_]+)/edit';

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
	 * Mirrors Test_REST_V2_Cleanup_Round_6: the MainWP REST server singleton
	 * caches controller instances, so they are cleared before a fresh
	 * WP_REST_Server receives the route registrations.
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

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}mainwp_api_keys WHERE description = %s", self::KEY_DESCRIPTION ) );
		// A renamed row keeps the name as a prefix, so match the prefix rather than the exact name.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}mainwp_group WHERE name LIKE %s", $wpdb->esc_like( self::TAG_NAME ) . '%' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}mainwp_wp WHERE name LIKE %s", $wpdb->esc_like( self::SITE_NAME ) . '%' ) );

		$cost_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}mainwp_cost_tracker WHERE name LIKE %s", $wpdb->esc_like( self::COST_NAME ) . '%' ) );
		foreach ( $cost_ids as $cost_id ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}mainwp_lookup_item_objects WHERE item_name = 'cost' AND item_id = %d", $cost_id ) );
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}mainwp_cost_tracker WHERE name LIKE %s", $wpdb->esc_like( self::COST_NAME ) . '%' ) );

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
	 * @return array Array with 'consumer_key', 'consumer_secret' and 'key_id'.
	 */
	protected function create_rest_api_key( int $user_id, string $permissions = 'read_write' ): array {
		global $wpdb;

		$consumer_key    = 'ck_' . bin2hex( random_bytes( 16 ) );
		$consumer_secret = 'cs_' . bin2hex( random_bytes( 16 ) );

		$wpdb->insert(
			$wpdb->prefix . 'mainwp_api_keys',
			[
				'user_id'         => $user_id,
				'description'     => self::KEY_DESCRIPTION,
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
			'key_id'          => (int) $wpdb->insert_id,
		];
	}

	/**
	 * Insert a tag row directly, so a batch update or delete has something to hit.
	 *
	 * @return int Tag ID.
	 */
	protected function create_tag(): int {
		$tag = \MainWP\Dashboard\MainWP_DB_Common::instance()->add_tag( [ 'name' => self::TAG_NAME ] );

		return (int) $tag->id;
	}

	/**
	 * Read a tag name straight from the table.
	 *
	 * @param int $tag_id Tag ID.
	 * @return string|null Name, or null when the row is gone.
	 */
	protected function get_tag_name( int $tag_id ) {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}mainwp_group WHERE id = %d", $tag_id ) );
	}

	/**
	 * Insert a site row directly, so an edit has a real row to write to.
	 *
	 * @param string $url Site URL.
	 * @return int Site ID.
	 */
	protected function create_site( string $url ): int {
		$site_id = \MainWP\Dashboard\MainWP_DB::instance()->add_website(
			$this->admin_user_id,
			self::SITE_NAME,
			$url,
			'admin',
			base64_encode( 'fake-pub-' . wp_generate_uuid4() ),
			base64_encode( 'fake-priv-' . wp_generate_uuid4() ),
			[
				// Left unset these default to null, and the columns are NOT NULL.
				'http_user' => '',
				'http_pass' => '',
			]
		);

		$this->assertNotFalse( $site_id, 'add_website should insert a site row' );

		// add_website() leaves the wp_upgrades option row for a first sync to write, and
		// the collection query selects it through a subquery that yields null until then.
		// Seeding it keeps this fixture on the code path a connected site takes.
		\MainWP\Dashboard\MainWP_DB::instance()->update_website_option(
			\MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $site_id ),
			'wp_upgrades',
			''
		);

		return (int) $site_id;
	}

	/**
	 * Read one site column straight from the table.
	 *
	 * @param int    $site_id Site ID.
	 * @param string $column  Column name, from a fixed allowlist.
	 * @return string|null Column value, or null when the row is gone.
	 */
	protected function get_site_column( int $site_id, string $column ) {
		global $wpdb;

		$this->assertContains( $column, [ 'name', 'client_id', 'force_use_ipv4' ], 'unexpected column' );

		return $wpdb->get_var( $wpdb->prepare( "SELECT `{$column}` FROM {$wpdb->prefix}mainwp_wp WHERE id = %d", $site_id ) );
	}

	/**
	 * Make an authenticated REST request.
	 *
	 * @param string $method       HTTP method.
	 * @param string $route        REST route.
	 * @param array  $json_body    Body payload, encoded as JSON.
	 * @param array  $body_params  Form body parameters, as WP_REST_Server sets them from an unslashed $_POST.
	 * @param string $raw_body     Raw body, left for core's own parsing.
	 * @param array  $query_params Query-string parameters, as core sets them from $_GET.
	 * @param string $content_type Content type header sent with a raw body.
	 * @return \WP_REST_Response Response object.
	 */
	protected function do_authenticated_request( string $method, string $route, array $json_body = [], array $body_params = [], string $raw_body = '', array $query_params = [], string $content_type = '' ): \WP_REST_Response {
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

		if ( ! empty( $json_body ) ) {
			$request->set_header( 'content-type', 'application/json' );
			$request->set_body( wp_json_encode( $json_body ) );
		}

		if ( ! empty( $body_params ) ) {
			$request->set_body_params( $body_params );
		}

		if ( '' !== $raw_body ) {
			$request->set_body( $raw_body );
		}

		if ( '' !== $content_type ) {
			$request->set_header( 'content-type', $content_type );
		}

		if ( ! empty( $query_params ) ) {
			$request->set_query_params( $query_params );
		}

		$response = rest_do_request( $request );

		$_GET    = $original_get;
		$_SERVER = $original_server;

		return $response;
	}

	/**
	 * Skip when the Cost Tracker module has not registered its REST controller.
	 *
	 * @return void
	 */
	protected function skip_if_no_costs_controller(): void {
		if ( ! class_exists( '\MainWP_Rest_Costs_Controller' ) ) {
			$this->markTestSkipped( 'Cost Tracker module is not loaded, so no costs controller exists.' );
		}

		if ( ! array_key_exists( '/mainwp/v2/costs/batch', $this->server->get_routes() ) ) {
			$this->markTestSkipped( 'Cost Tracker REST routes are not registered in this harness.' );
		}
	}

	/**
	 * Item 1: an edit that does not carry client_id leaves the field out of the
	 * update payload, so the DB layer never writes it.
	 */
	public function test_prepare_object_for_update_omits_absent_client_id(): void {
		$controller = new \MainWP_Rest_Sites_Controller();
		$method     = new \ReflectionMethod( $controller, 'prepare_object_for_update' );
		$method->setAccessible( true );

		$request = new WP_REST_Request( 'PUT', '/mainwp/v2/sites/1/edit' );
		$request->set_body_params( [ 'name' => 'x' ] );

		$data = $method->invoke( $controller, $request );

		$this->assertArrayNotHasKey( 'client_id', $data );

		// Zero stays the explicit unassign.
		$request = new WP_REST_Request( 'PUT', '/mainwp/v2/sites/1/edit' );
		$request->set_body_params( [ 'client_id' => 0 ] );

		$data = $method->invoke( $controller, $request );

		$this->assertSame( 0, $data['client_id'] );

		$request = new WP_REST_Request( 'PUT', '/mainwp/v2/sites/1/edit' );
		$request->set_body_params( [ 'client_id' => '7' ] );

		$data = $method->invoke( $controller, $request );

		$this->assertSame( 7, $data['client_id'] );
	}

	/**
	 * Item 1: a name-only edit of a real site row leaves its client assignment alone.
	 */
	public function test_name_only_edit_keeps_the_site_client(): void {
		$this->authenticate_as_admin();
		$site_id = $this->create_site( 'https://round7-client.example/' );
		\MainWP\Dashboard\MainWP_DB::instance()->update_website_values( $site_id, [ 'client_id' => 5 ] );

		$response = $this->do_authenticated_request(
			'PUT',
			'/mainwp/v2/sites/' . $site_id . '/edit',
			[ 'name' => self::SITE_NAME . ' renamed' ]
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( self::SITE_NAME . ' renamed', $this->get_site_column( $site_id, 'name' ) );
		$this->assertSame( '5', (string) $this->get_site_column( $site_id, 'client_id' ) );

		// An explicit zero still unassigns.
		$response = $this->do_authenticated_request(
			'PUT',
			'/mainwp/v2/sites/' . $site_id . '/edit',
			[ 'client_id' => 0 ]
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( '0', (string) $this->get_site_column( $site_id, 'client_id' ) );
	}

	/**
	 * Item 2: the global batch validates a tags update item against the registered args.
	 */
	public function test_global_batch_dispatches_tags_update(): void {
		$this->authenticate_as_admin();
		$tag_id = $this->create_tag();

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/batch',
			[ 'tags' => [ 'update' => [ [ 'id' => $tag_id, 'name' => [ 'x' ] ] ] ] ]
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'tags', $data, wp_json_encode( $data ) );
		$this->assertArrayHasKey( 'update', $data['tags'], wp_json_encode( $data ) );
		$this->assertSame( 'rest_invalid_param', $data['tags']['update'][0]['error']['code'], wp_json_encode( $data ) );
		$this->assertSame( $tag_id, $data['tags']['update'][0]['id'] );
		$this->assertSame( self::TAG_NAME, $this->get_tag_name( $tag_id ) );
	}

	/**
	 * Item 2: a valid tags update item through the global batch reaches the handler.
	 */
	public function test_global_batch_tags_update_reaches_handler(): void {
		$this->authenticate_as_admin();
		$tag_id = $this->create_tag();

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/batch',
			[ 'tags' => [ 'update' => [ [ 'id' => $tag_id, 'name' => self::TAG_NAME . ' renamed' ] ] ] ]
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertArrayNotHasKey( 'error', $data['tags']['update'][0], wp_json_encode( $data ) );
		$this->assertSame( 1, $data['tags']['update'][0]['success'], wp_json_encode( $data ) );
		$this->assertSame( self::TAG_NAME . ' renamed', $this->get_tag_name( $tag_id ) );
	}

	/**
	 * Item 2: the global batch dispatches deletes, and validates each id as sent.
	 */
	public function test_global_batch_dispatches_tags_delete(): void {
		$this->authenticate_as_admin();
		$tag_id = $this->create_tag();

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/batch',
			[ 'tags' => [ 'delete' => [ $tag_id ] ] ]
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'delete', $data['tags'], wp_json_encode( $data ) );
		$this->assertArrayNotHasKey( 'error', $data['tags']['delete'][0], wp_json_encode( $data ) );
		$this->assertNull( $this->get_tag_name( $tag_id ) );

		$survivor = $this->create_tag();

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/batch',
			[ 'tags' => [ 'delete' => [ true, $survivor + 0.9 ] ] ]
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertCount( 2, $data['tags']['delete'], wp_json_encode( $data ) );
		foreach ( $data['tags']['delete'] as $i => $item ) {
			$this->assertSame( 'rest_invalid_param', $item['error']['code'], "item $i: " . wp_json_encode( $item ) );
		}
		$this->assertNotNull( $this->get_tag_name( $survivor ) );
	}

	/**
	 * Item 3: both site input routes register force_use_ipv4 as boolean, integer or string.
	 */
	public function test_force_use_ipv4_registered_on_both_input_routes(): void {
		$routes = $this->server->get_routes();

		foreach ( [ '/mainwp/v2/sites/add', self::EDIT_ROUTE ] as $key ) {
			$this->assertArrayHasKey( $key, $routes, $key );
			$args = $routes[ $key ][0]['args'];

			$this->assertArrayHasKey( 'force_use_ipv4', $args, $key );
			$this->assertSame( [ 'boolean', 'integer', 'string' ], $args['force_use_ipv4']['type'], $key );
		}

		$this->assertArrayNotHasKey( 'default', $routes[ self::EDIT_ROUTE ][0]['args']['force_use_ipv4'] );
	}

	/**
	 * Item 3: a JSON boolean and the string "1" both pass arg validation on the
	 * add and edit routes, matching what OpenAPI documents and the DB layer accepts.
	 */
	public function test_force_use_ipv4_accepts_boolean_and_string(): void {
		$this->authenticate_as_admin();
		$url = 'https://round7-ipv4.example/';
		$this->create_site( $url );

		foreach ( [ true, '1', 2 ] as $value ) {
			$label = wp_json_encode( $value );

			// The URL is already connected, so the add handler refuses it before any
			// network call. Only the arg validation in front of it is under test.
			$response = $this->do_authenticated_request(
				'POST',
				'/mainwp/v2/sites/add',
				[
					'url'            => $url,
					'name'           => self::SITE_NAME,
					'force_use_ipv4' => $value,
				]
			);

			$this->assertNotSame( 'rest_invalid_param', $this->error_code_of( $response ), 'add ' . $label . ': ' . wp_json_encode( $response->get_data() ) );

			$response = $this->do_authenticated_request(
				'PUT',
				'/mainwp/v2/sites/999999/edit',
				[ 'force_use_ipv4' => $value ]
			);

			$this->assertNotSame( 'rest_invalid_param', $this->error_code_of( $response ), 'edit ' . $label . ': ' . wp_json_encode( $response->get_data() ) );
		}
	}

	/**
	 * Item 3: a boolean reaches the column as the 1 the DB layer stores for it.
	 */
	public function test_force_use_ipv4_boolean_reaches_the_column(): void {
		$this->authenticate_as_admin();
		$site_id = $this->create_site( 'https://round7-ipv4-column.example/' );

		$response = $this->do_authenticated_request(
			'PUT',
			'/mainwp/v2/sites/' . $site_id . '/edit',
			[ 'force_use_ipv4' => true ]
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( '1', (string) $this->get_site_column( $site_id, 'force_use_ipv4' ) );

		$response = $this->do_authenticated_request(
			'PUT',
			'/mainwp/v2/sites/' . $site_id . '/edit',
			[ 'force_use_ipv4' => 2 ]
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( '2', (string) $this->get_site_column( $site_id, 'force_use_ipv4' ) );
	}

	/**
	 * Item 4: the costs schema types the id lists as arrays, which is what the
	 * create handler requires, so a validated create can actually succeed.
	 */
	public function test_costs_batch_create_accepts_site_ids(): void {
		$this->skip_if_no_costs_controller();
		$this->authenticate_as_admin();
		global $wpdb;

		$site_id = $this->create_site( 'https://round7-cost.example/' );

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/batch',
			[
				'costs' => [
					'create' => [
						[
							'name'                => self::COST_NAME,
							'payment_type'        => 'subscription',
							'product_type'        => 'other',
							'license_type'        => 'single_site',
							'cost_tracker_status' => 'active',
							'payment_method'      => 'visa',
							'renewal_type'        => 'monthly',
							'product_color'       => '#ff0000',
							'price'               => 9.99,
							'sites'               => [ $site_id ],
						],
					],
				],
			]
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertArrayNotHasKey( 'error', $data['costs']['create'][0], wp_json_encode( $data ) );

		$stored = $wpdb->get_var( $wpdb->prepare( "SELECT sites FROM {$wpdb->prefix}mainwp_cost_tracker WHERE name = %s", self::COST_NAME ) );
		$this->assertSame( wp_json_encode( [ $site_id ] ), $stored );
	}

	/**
	 * Item 4: a scalar where the handler wants a list is now a validation error
	 * instead of the handler's own invalid-data exception.
	 */
	public function test_costs_batch_create_rejects_scalar_sites(): void {
		$this->skip_if_no_costs_controller();
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/batch',
			[ 'costs' => [ 'create' => [ [ 'name' => self::COST_NAME, 'sites' => 'x' ] ] ] ]
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertSame( 'rest_invalid_param', $data['costs']['create'][0]['error']['code'], wp_json_encode( $data ) );
	}

	/**
	 * Item 4: every costs schema property names a callback that exists.
	 */
	public function test_costs_schema_validate_callbacks_are_spelled_correctly(): void {
		$this->skip_if_no_costs_controller();

		$controller = new \MainWP_Rest_Costs_Controller();
		$schema     = $controller->get_item_schema();

		foreach ( $schema['properties'] as $name => $property ) {
			if ( ! isset( $property['validate_callback'] ) ) {
				continue;
			}
			$this->assertTrue( is_callable( $property['validate_callback'] ), $name . ': ' . $property['validate_callback'] );
		}
	}

	/**
	 * Item 5: the tags schema id carries no top-level validate_callback. Core never
	 * reads one, and wp_parse_id_list returns an array rather than a bool.
	 */
	public function test_tags_schema_id_has_no_validate_callback(): void {
		$controller = new \MainWP_Rest_Tags_Controller();
		$schema     = $controller->get_item_schema();

		$this->assertArrayNotHasKey( 'validate_callback', $schema['properties']['id'] );
	}

	/**
	 * Error code of a response, whether it carries a WP_Error or a handler payload.
	 *
	 * @param \WP_REST_Response $response Response object.
	 * @return string Error code, or an empty string when the response is not an error.
	 */
	protected function error_code_of( \WP_REST_Response $response ): string {
		$error = $response->as_error();

		return $error ? (string) $error->get_error_code() : '';
	}

}
