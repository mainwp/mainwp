<?php
/**
 * MainWP REST v2 Cleanup Round 6 Tests
 *
 * Covers registered-arg validation on batch update and delete, the /sites/{id}/edit
 * route args and its update field map, the ssl_verify arg on /sites/add, the
 * empty_body status code, and the dead costs group on the global batch endpoint.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use WP_REST_Request;
use WP_REST_Server;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- test classes use PSR-style Test_* filenames, not the mainwp-* convention.

/**
 * Class Test_REST_V2_Cleanup_Round_6
 */
class Test_REST_V2_Cleanup_Round_6 extends \WP_Test_REST_TestCase {

	/**
	 * Description of the API key rows this class creates.
	 *
	 * @var string
	 */
	const KEY_DESCRIPTION = 'rest-v2-cleanup-round-6 Test API Key';

	/**
	 * Name of the tag rows this class inserts.
	 *
	 * @var string
	 */
	const TAG_NAME = 'rest-v2-cleanup-round-6 Batch Tag';

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
	 * Mirrors Test_REST_V2_Cleanup_Round_5: the MainWP REST server singleton
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
	 * Item 1: a batch update item is validated against the update route's args.
	 */
	public function test_batch_update_validates_registered_args(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/sites/batch',
			[ 'update' => [ [ 'id' => 1, 'name' => [ 'x' ] ] ] ]
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'error', $data['update'][0], wp_json_encode( $data ) );
		$this->assertSame( 'rest_invalid_param', $data['update'][0]['error']['code'] );
		$this->assertSame( 1, $data['update'][0]['id'] );

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/sites/batch',
			[ 'update' => [ [ 'name' => 'x' ] ] ]
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'error', $data['update'][0], wp_json_encode( $data ) );
		$this->assertSame( 'rest_missing_callback_param', $data['update'][0]['error']['code'] );
		$this->assertSame( 0, $data['update'][0]['id'] );
	}

	/**
	 * Item 1 regression: a batch update that passes validation still reaches the handler.
	 */
	public function test_batch_update_valid_tag_reaches_handler(): void {
		$this->authenticate_as_admin();
		$tag_id = $this->create_tag();

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/tags/batch',
			[ 'update' => [ [ 'id' => $tag_id, 'name' => self::TAG_NAME . ' renamed' ] ] ]
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertArrayNotHasKey( 'error', $data['update'][0], wp_json_encode( $data ) );
		$this->assertSame( 1, $data['update'][0]['success'], wp_json_encode( $data ) );
		$this->assertSame( self::TAG_NAME . ' renamed', $this->get_tag_name( $tag_id ) );
	}

	/**
	 * Item 1: the delete loop declares its own args, and a valid delete still runs.
	 */
	public function test_batch_delete_validates_registered_args(): void {
		$controller = new \MainWP_Rest_Tags_Controller();
		$args       = $controller->get_batch_delete_args();

		$this->assertSame( 'integer', $args['id']['type'] );
		$this->assertTrue( $args['id']['required'] );
		$this->assertSame( 'boolean', $args['force']['type'] );

		$this->authenticate_as_admin();
		$tag_id = $this->create_tag();

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/tags/batch',
			[ 'delete' => [ $tag_id ] ]
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertArrayNotHasKey( 'error', $data['delete'][0], wp_json_encode( $data ) );
		$this->assertNull( $this->get_tag_name( $tag_id ) );
	}

	/**
	 * Item 2: the edit route registers the args it accepts, minus the collection id filter.
	 */
	public function test_sites_edit_route_registers_args(): void {
		$routes = $this->server->get_routes();
		$key    = '/mainwp/v2/sites/(?P<id_domain>[a-zA-Z0-9\-\.\_]+)/edit';

		$this->assertArrayHasKey( $key, $routes );
		$args = $routes[ $key ][0]['args'];

		foreach ( [ 'name', 'adminname', 'admin', 'uniqueid', 'sslversion', 'disablehealthchecking', 'ignore_core_updates' ] as $arg ) {
			$this->assertArrayHasKey( $arg, $args, $arg );
		}

		$this->assertArrayNotHasKey( 'id', $args );

		// Core injects arg defaults as request params and update_item() writes every
		// present field, so a default on the edit route would turn a name-only edit
		// into a suspended/automatic_update reset.
		foreach ( $args as $name => $arg ) {
			$this->assertArrayNotHasKey( 'default', $arg, $name );
		}
	}

	/**
	 * Item 2: arg validation runs before the site lookup, so no site row is needed.
	 */
	public function test_sites_edit_rejects_array_name(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request(
			'PUT',
			'/mainwp/v2/sites/999999/edit',
			[ 'name' => [ 'x' ] ]
		);

		$this->assertSame( 400, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'rest_invalid_param', $response->as_error()->get_error_code() );
	}

	/**
	 * Item 2: the update field map reads the documented spelling first and the
	 * item-schema spelling as a fallback.
	 */
	public function test_prepare_object_for_update_reads_both_spellings(): void {
		$controller = new \MainWP_Rest_Sites_Controller();
		$method     = new \ReflectionMethod( $controller, 'prepare_object_for_update' );
		$method->setAccessible( true );

		$request = new WP_REST_Request( 'PUT', '/mainwp/v2/sites/1/edit' );
		$request->set_body_params(
			[
				'sslversion' => 'tlsv1.2',
				'uniqueId'   => 'abc',
				'adminname'  => 'bob',
			]
		);

		$data = $method->invoke( $controller, $request );

		$this->assertSame( 'tlsv1.2', $data['sslversion'] );
		$this->assertSame( 'abc', $data['uniqueid'] );
		$this->assertSame( 'bob', $data['admin'] );

		$request = new WP_REST_Request( 'PUT', '/mainwp/v2/sites/1/edit' );
		$request->set_body_params(
			[
				'admin'     => 'alice',
				'adminname' => 'bob',
			]
		);

		$data = $method->invoke( $controller, $request );

		$this->assertSame( 'alice', $data['admin'] );
	}

	/**
	 * Item 3: ssl_verify is consumed by the add handler, so the add route has to type it.
	 */
	public function test_sites_add_rejects_array_ssl_verify(): void {
		$this->authenticate_as_admin();

		$item = [
			'url'        => 'https://example.com',
			'name'       => 'x',
			'ssl_verify' => [ '1' ],
		];

		$response = $this->do_authenticated_request( 'POST', '/mainwp/v2/sites/add', $item );

		$this->assertSame( 400, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'rest_invalid_param', $response->as_error()->get_error_code() );

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/sites/batch',
			[ 'create' => [ $item ] ]
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertSame( 'rest_invalid_param', $data['create'][0]['error']['code'], wp_json_encode( $data ) );

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/batch',
			[ 'sites' => [ 'create' => [ $item ] ] ]
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertSame( 'rest_invalid_param', $data['sites']['create'][0]['error']['code'], wp_json_encode( $data ) );
	}

	/**
	 * Item 4: empty_body is a client error, not the 500 an undeclared status produces.
	 */
	public function test_empty_body_is_a_400(): void {
		$this->authenticate_as_admin();
		$target = $this->create_rest_api_key( $this->admin_user_id, 'read' );

		$response = $this->do_authenticated_request( 'PUT', '/mainwp/v2/rest-api/edit-key/' . $target['key_id'] );

		$this->assertSame( 400, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'empty_body', $response->as_error()->get_error_code() );

		$controller = new \MainWP_Rest_API_Keys_Controller();
		$method     = new \ReflectionMethod( $controller, 'get_request_body_data' );
		$method->setAccessible( true );

		$error = $method->invoke( $controller, new WP_REST_Request( 'POST', '/mainwp/v2/rest-api/add-key' ) );

		$this->assertWPError( $error );
		$this->assertSame( 400, $error->get_error_data()['status'] );
	}

	/**
	 * Item 5: no costs controller is registered, so the group is neither dispatched
	 * nor counted toward the batch limit.
	 */
	public function test_global_batch_ignores_costs_group_without_counting_it(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/batch',
			[
				'costs' => [ 'create' => array_fill( 0, 100, [ 'x' => 1 ] ) ],
				'tags'  => [ 'create' => [ [ 'name' => self::TAG_NAME ] ] ],
			]
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertArrayNotHasKey( 'error', $data['tags']['create'][0], wp_json_encode( $data ) );
		$this->assertFalse( array_key_exists( 'costs', $data ), wp_json_encode( $data ) );
	}

}
