<?php
/**
 * MainWP REST v2 Cleanup Round 5 Tests
 *
 * Covers registered-arg validation on batch create, the enum factories with a
 * nested array value, the users raw-body fallback, and the /sites/add field
 * spellings and password pass-throughs.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use WP_REST_Request;
use WP_REST_Server;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- test classes use PSR-style Test_* filenames, not the mainwp-* convention.

/**
 * Class Test_REST_V2_Cleanup_Round_5
 */
class Test_REST_V2_Cleanup_Round_5 extends \WP_Test_REST_TestCase {

	/**
	 * Description of the API key rows this class creates.
	 *
	 * @var string
	 */
	const KEY_DESCRIPTION = 'Round 5 Test API Key';

	/**
	 * Name of the tag rows the batch create test inserts.
	 *
	 * @var string
	 */
	const TAG_NAME = 'Round 5 Batch Tag';

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
	 * Mirrors Test_REST_V2_Cleanup_Round_4: the MainWP REST server singleton
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
		// The second create is renamed to "<name> (1)" by check_group_name(), so match the prefix.
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
	 * Item 1: a batch create item is validated against the create route's args,
	 * on both the per-controller and the global batch endpoint.
	 */
	public function test_batch_create_validates_registered_args(): void {
		$this->authenticate_as_admin();

		$item = [
			'url'   => 'https://example.com',
			'name'  => 'x',
			'admin' => [ 'x' ],
		];

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/sites/batch',
			[ 'create' => [ $item ] ]
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'error', $data['create'][0], wp_json_encode( $data ) );
		$this->assertSame( 'rest_invalid_param', $data['create'][0]['error']['code'] );
		$this->assertSame( 0, $data['create'][0]['id'] );

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/batch',
			[ 'sites' => [ 'create' => [ $item ] ] ]
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'error', $data['sites']['create'][0], wp_json_encode( $data ) );
		$this->assertSame( 'rest_invalid_param', $data['sites']['create'][0]['error']['code'] );
		$this->assertSame( 0, $data['sites']['create'][0]['id'] );
	}

	/**
	 * Item 1 regression: a batch create that passes validation still reaches the
	 * handler. The tags schema carried a misspelled validate_callback that only
	 * batch validation could trigger, so a valid tag has to go through both routes.
	 */
	public function test_batch_create_valid_tag_reaches_handler(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/tags/batch',
			[ 'create' => [ [ 'name' => self::TAG_NAME ] ] ]
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertArrayNotHasKey( 'error', $data['create'][0], wp_json_encode( $data ) );
		$this->assertSame( 1, $data['create'][0]['success'], wp_json_encode( $data ) );

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/batch',
			[ 'tags' => [ 'create' => [ [ 'name' => self::TAG_NAME ] ] ] ]
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertArrayNotHasKey( 'error', $data['tags']['create'][0], wp_json_encode( $data ) );
		$this->assertSame( 1, $data['tags']['create'][0]['success'], wp_json_encode( $data ) );
	}

	/**
	 * Item 2: the enum factories treat a nested array element as a plain enum
	 * mismatch instead of handing it to trim().
	 */
	public function test_users_roles_enum_rejects_nested_array_element(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request(
			'GET',
			'/mainwp/v2/users',
			[],
			[],
			'',
			[ 'roles' => [ 'administrator', [ 'x' ] ] ]
		);

		$this->assertSame( 400, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertSame( 'rest_invalid_param', $data['code'] );
		$this->assertSame( 'invalid_roles', $data['data']['details']['roles']['code'] );
	}

	/**
	 * Item 3: the users controller must not decode a raw body core never parsed.
	 *
	 * The route declares password required, so it is sent on the query string to
	 * get past the required-param check and reach the handler.
	 */
	public function test_users_update_admin_password_ignores_unparsed_body(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request(
			'PUT',
			'/mainwp/v2/users/update-admin-password',
			[],
			[],
			'[]',
			[ 'password' => 'round-5-password' ],
			'application/json'
		);

		$this->assertSame( 'empty_body', $response->as_error()->get_error_code(), wp_json_encode( $response->get_data() ) );
	}

	/**
	 * Item 4: the add route accepts the uniqueId spelling it registers, passes
	 * passwords through untouched, and exposes force_use_ipv4 to the edit context.
	 */
	public function test_sites_add_field_spelling_and_password_pass_through(): void {
		$controller = new \MainWP_Rest_Sites_Controller();

		$request = new WP_REST_Request( 'POST', '/mainwp/v2/sites/add' );
		$request->set_body_params(
			[
				'uniqueId'      => 'abc',
				'adminpassword' => 'p<a>ss"w',
				'http_pass'     => 'h<t>tp',
			]
		);

		$method = new \ReflectionMethod( $controller, 'prepare_object_for_database' );
		$method->setAccessible( true );
		$item = $method->invoke( $controller, $request );

		$this->assertSame( 'abc', $item['unique_id'] );
		$this->assertSame( 'p<a>ss"w', $item['adminpwd'] );
		$this->assertSame( 'h<t>tp', $item['http_pass'] );

		$schema = $controller->get_item_schema();
		$this->assertContains( 'edit', $schema['properties']['force_use_ipv4']['context'] );
	}

}
