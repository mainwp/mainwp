<?php
/**
 * MainWP REST v2 Cleanup Round 8 Tests
 *
 * Covers non-array batch update items, the Cost Tracker schema wire names and
 * direct write route args, the Cost Tracker selector chain, the force_use_ipv4
 * enum message, and the omitted force_use_ipv4 on sites/add.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use WP_REST_Request;
use WP_REST_Server;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- test classes use PSR-style Test_* filenames, not the mainwp-* convention.

/**
 * Class Test_REST_V2_Cleanup_Round_8
 */
class Test_REST_V2_Cleanup_Round_8 extends \WP_Test_REST_TestCase {

	/**
	 * Description of the API key rows this class creates.
	 *
	 * @var string
	 */
	const KEY_DESCRIPTION = 'rest-v2-cleanup-round-8 Test API Key';

	/**
	 * Name of the tag rows this class inserts.
	 *
	 * @var string
	 */
	const TAG_NAME = 'rest-v2-cleanup-round-8 Batch Tag';

	/**
	 * Name of the site rows this class inserts.
	 *
	 * @var string
	 */
	const SITE_NAME = 'rest-v2-cleanup-round-8 Site';

	/**
	 * Name of the cost rows this class inserts.
	 *
	 * @var string
	 */
	const COST_NAME = 'rest-v2-cleanup-round-8 Cost';

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

		// add_website() writes a per-site key file straight to disk, outside the DB
		// transaction, so deleting the mainwp_wp row alone leaves it orphaned on disk.
		$site_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}mainwp_wp WHERE name LIKE %s", $wpdb->esc_like( self::SITE_NAME ) . '%' ) );
		foreach ( $site_ids as $site_id ) {
			\MainWP\Dashboard\MainWP_Encrypt_Data_Lib::remove_key_file( (int) $site_id );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}mainwp_wp_options WHERE wpid = %d", (int) $site_id ) );
		}
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
	 * Item 1: every shape of update item goes through one helper, so a scalar item is
	 * turned into an error instead of reaching set_body_params()'s array type hint.
	 */
	public function test_prepare_batch_update_request_input_matrix(): void {
		$controller = new \MainWP_Rest_Tags_Controller();
		$args       = $controller->get_batch_update_args();
		$method     = new \ReflectionMethod( $controller, 'prepare_batch_update_request' );
		$method->setAccessible( true );

		// The batch id arg is required, so an array item without an id is reported as a
		// missing param, not an invalid one.
		$rejected = [
			'scalar string'   => [ 'x', 'rest_invalid_param' ],
			'boolean'         => [ true, 'rest_invalid_param' ],
			'non-numeric id'  => [ [ 'id' => 'abc' ], 'rest_invalid_param' ],
			'no id'           => [ [ 'name' => 'x' ], 'rest_missing_callback_param' ],
			'nested list'     => [ [ 'x' ], 'rest_missing_callback_param' ],
		];

		foreach ( $rejected as $label => $case ) {
			list( $input, $code ) = $case;

			$result = $method->invoke( $controller, '/mainwp/v2/tags/batch', $input, $args );

			$this->assertWPError( $result, $label );
			$this->assertSame( $code, $result->get_error_code(), $label );
		}

		// A numeric string is a valid integer to core, and comes back out of the request
		// bag as an int.
		foreach ( [ 'integer id' => 5, 'numeric string id' => '5' ] as $label => $id ) {
			$result = $method->invoke( $controller, '/mainwp/v2/tags/batch', [ 'id' => $id, 'name' => self::TAG_NAME ], $args );

			$this->assertInstanceOf( WP_REST_Request::class, $result, $label );
			$this->assertSame( 5, $result['id'], $label );
		}
	}

	/**
	 * Item 1: a non-array update item on a per-controller batch route is reported as a
	 * per-item error, with no id to report it against.
	 */
	public function test_batch_update_rejects_non_array_item(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/tags/batch',
			[ 'update' => [ 'x', [ 'name' => 'x' ] ] ]
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertCount( 2, $data['update'], wp_json_encode( $data ) );
		$this->assertSame( 'rest_invalid_param', $data['update'][0]['error']['code'], wp_json_encode( $data ) );
		$this->assertSame( 0, $data['update'][0]['id'] );
		$this->assertSame( 'rest_missing_callback_param', $data['update'][1]['error']['code'], wp_json_encode( $data ) );
		$this->assertSame( 0, $data['update'][1]['id'] );
	}

	/**
	 * Item 1: the global batch update loop rejects the same shapes the per-controller
	 * loop does.
	 */
	public function test_global_batch_update_rejects_non_array_item(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/batch',
			[ 'tags' => [ 'update' => [ 'x', [ 'name' => 'x' ] ] ] ]
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertCount( 2, $data['tags']['update'], wp_json_encode( $data ) );
		$this->assertSame( 'rest_invalid_param', $data['tags']['update'][0]['error']['code'], wp_json_encode( $data ) );
		$this->assertSame( 0, $data['tags']['update'][0]['id'] );
		$this->assertSame( 'rest_missing_callback_param', $data['tags']['update'][1]['error']['code'], wp_json_encode( $data ) );
		$this->assertSame( 0, $data['tags']['update'][1]['id'] );
	}

	/**
	 * Item 1: a bad item does not stop the items after it, on either batch route.
	 */
	public function test_batch_update_continues_past_non_array_item(): void {
		$this->authenticate_as_admin();
		$tag_id = $this->create_tag();

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/tags/batch',
			[ 'update' => [ 'x', [ 'id' => $tag_id, 'name' => self::TAG_NAME . ' renamed' ] ] ]
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertSame( 'rest_invalid_param', $data['update'][0]['error']['code'], wp_json_encode( $data ) );
		$this->assertArrayNotHasKey( 'error', $data['update'][1], wp_json_encode( $data ) );
		$this->assertSame( 1, $data['update'][1]['success'], wp_json_encode( $data ) );
		$this->assertSame( self::TAG_NAME . ' renamed', $this->get_tag_name( $tag_id ) );

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/batch',
			[ 'tags' => [ 'update' => [ 'x', [ 'id' => $tag_id, 'name' => self::TAG_NAME . ' renamed twice' ] ] ] ]
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertSame( 'rest_invalid_param', $data['tags']['update'][0]['error']['code'], wp_json_encode( $data ) );
		$this->assertArrayNotHasKey( 'error', $data['tags']['update'][1], wp_json_encode( $data ) );
		$this->assertSame( 1, $data['tags']['update'][1]['success'], wp_json_encode( $data ) );
		$this->assertSame( self::TAG_NAME . ' renamed twice', $this->get_tag_name( $tag_id ) );
	}

	/**
	 * Review round 1: no create arg is required, so a scalar item used to pass validation
	 * and reach set_body_params()'s array type hint. Both batch routes report it per item.
	 */
	public function test_batch_create_rejects_non_array_item(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/tags/batch',
			[ 'create' => [ 'x' ] ]
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertCount( 1, $data['create'], wp_json_encode( $data ) );
		$this->assertSame( 'rest_invalid_param', $data['create'][0]['error']['code'], wp_json_encode( $data ) );
		$this->assertSame( 0, $data['create'][0]['id'] );

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/batch',
			[ 'tags' => [ 'create' => [ 'x' ] ] ]
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertCount( 1, $data['tags']['create'], wp_json_encode( $data ) );
		$this->assertSame( 'rest_invalid_param', $data['tags']['create'][0]['error']['code'], wp_json_encode( $data ) );
		$this->assertSame( 0, $data['tags']['create'][0]['id'] );
	}

	/**
	 * Review round 1: a JSON list is an array without string keys, so sanitize_params()
	 * would drop its entries as unregistered and the handler would build a row out of the
	 * schema defaults alone. It is refused the same way a scalar is, and neither shape
	 * stops the items after it.
	 */
	public function test_batch_create_rejects_list_item_and_continues(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/tags/batch',
			[ 'create' => [ [ 'x' ], [ 'name' => self::TAG_NAME ] ] ]
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertCount( 2, $data['create'], wp_json_encode( $data ) );
		$this->assertSame( 'rest_invalid_param', $data['create'][0]['error']['code'], wp_json_encode( $data ) );
		$this->assertSame( 0, $data['create'][0]['id'] );
		$this->assertArrayNotHasKey( 'error', $data['create'][1], wp_json_encode( $data ) );
		$this->assertSame( 1, $data['create'][1]['success'], wp_json_encode( $data ) );

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/batch',
			[ 'tags' => [ 'create' => [ [ 'x' ], [ 'name' => self::TAG_NAME ] ] ] ]
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertCount( 2, $data['tags']['create'], wp_json_encode( $data ) );
		$this->assertSame( 'rest_invalid_param', $data['tags']['create'][0]['error']['code'], wp_json_encode( $data ) );
		$this->assertSame( 0, $data['tags']['create'][0]['id'] );
		$this->assertArrayNotHasKey( 'error', $data['tags']['create'][1], wp_json_encode( $data ) );
		$this->assertSame( 1, $data['tags']['create'][1]['success'], wp_json_encode( $data ) );
	}

	/**
	 * A cost payload the handler accepts, spelled the way the wire and the docs spell it.
	 *
	 * @param int $site_id Site the cost applies to.
	 * @return array Payload.
	 */
	protected function costs_payload( int $site_id ): array {
		return [
			'name'                => self::COST_NAME,
			'payment_type'        => 'subscription',
			'product_type'        => 'other',
			'product_slug'        => 'round8-cost',
			'license_type'        => 'single_site',
			'cost_tracker_status' => 'active',
			'payment_method'      => 'visa',
			'renewal_type'        => 'monthly',
			'product_color'       => '#ff0000',
			'icon_hidden'         => 'deficon:round8',
			'price'               => 9.99,
			'sites'               => [ $site_id ],
		];
	}

	/**
	 * Add one cost through the REST route and read the row back.
	 *
	 * @param int    $site_id Site the cost applies to.
	 * @param string $name    Cost name.
	 * @return object Row.
	 */
	protected function create_cost( int $site_id, string $name ) {
		$payload         = $this->costs_payload( $site_id );
		$payload['name'] = $name;

		$response = $this->do_authenticated_request( 'POST', '/mainwp/v2/costs/add', $payload );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$row = $this->get_cost_row( $name );
		$this->assertNotNull( $row, 'the add route should have inserted ' . $name );

		return $row;
	}

	/**
	 * Read one cost row by the name this class inserts under.
	 *
	 * @param string $name Cost name.
	 * @return object|null Row, or null when nothing was inserted.
	 */
	protected function get_cost_row( string $name = self::COST_NAME ) {
		global $wpdb;

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mainwp_cost_tracker WHERE name = %s", $name ) );
	}

	/**
	 * Item 2: the direct write routes register the item-schema args, so a wire-named
	 * field of the wrong type is a 400 instead of reaching the handler.
	 */
	public function test_costs_write_routes_reject_wrong_typed_wire_params(): void {
		$this->skip_if_no_costs_controller();
		$this->authenticate_as_admin();

		$site_id = $this->create_site( 'https://round8-cost-type.example/' );
		$payload = array_merge( $this->costs_payload( $site_id ), [ 'payment_type' => [ 'x' ] ] );

		$response = $this->do_authenticated_request( 'POST', '/mainwp/v2/costs/add', $payload );

		$this->assertSame( 400, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'rest_invalid_param', $this->error_code_of( $response ) );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'payment_type', $data['data']['params'], wp_json_encode( $data ) );

		// Core validates the registered args before it dispatches to update_item(), so the
		// id in the path never has to resolve for the type check to fire.
		$response = $this->do_authenticated_request( 'PUT', '/mainwp/v2/costs/999999/edit', [ 'cost_tracker_status' => [ 'x' ] ] );

		$this->assertSame( 400, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'rest_invalid_param', $this->error_code_of( $response ) );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'cost_tracker_status', $data['data']['params'], wp_json_encode( $data ) );
	}

	/**
	 * Item 2: a valid add still reaches the handler, and every renamed property lands in
	 * the column the handler maps it to.
	 */
	public function test_costs_add_maps_wire_names_to_columns(): void {
		$this->skip_if_no_costs_controller();
		$this->authenticate_as_admin();

		$site_id                 = $this->create_site( 'https://round8-cost-map.example/' );
		$payload                 = $this->costs_payload( $site_id );
		$payload['last_renewal'] = '2026-01-15';

		$response = $this->do_authenticated_request( 'POST', '/mainwp/v2/costs/add', $payload );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertSame( 1, $data['success'], wp_json_encode( $data ) );

		$row = $this->get_cost_row();
		$this->assertNotNull( $row, 'the add route should have inserted a cost row' );
		$this->assertSame( 'subscription', $row->type );
		$this->assertSame( 'active', $row->cost_status );
		$this->assertSame( 'round8-cost', $row->slug );
		$this->assertSame( 'deficon:round8', $row->cost_icon );
		$this->assertSame( '#ff0000', $row->cost_color );
		$this->assertSame( strtotime( '2026-01-15' ), (int) $row->last_renewal );
	}

	/**
	 * Item 2: both write routes carry the wire-named args, the edit route leaves id to
	 * its URL segment, and none of the old DB-column spellings survive.
	 */
	public function test_costs_write_routes_register_wire_named_args(): void {
		$this->skip_if_no_costs_controller();

		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/mainwp/v2/costs/add', $routes );
		$this->assertArrayHasKey( '/mainwp/v2/costs/(?P<id>[\d]+)/edit', $routes );

		$add_args  = $routes['/mainwp/v2/costs/add'][0]['args'];
		$edit_args = $routes['/mainwp/v2/costs/(?P<id>[\d]+)/edit'][0]['args'];

		foreach ( [ 'payment_type', 'cost_tracker_status', 'icon_hidden', 'product_color', 'product_slug', 'last_renewal' ] as $wire_name ) {
			$this->assertArrayHasKey( $wire_name, $add_args, $wire_name );
			$this->assertArrayHasKey( $wire_name, $edit_args, $wire_name );
		}

		// The handler reads last_renewal through strtotime(), so an integer arg would
		// reject the date string the docs document.
		$this->assertSame( 'string', $add_args['last_renewal']['type'] );
		$this->assertSame( 'string', $edit_args['last_renewal']['type'] );

		$this->assertArrayNotHasKey( 'id', $edit_args );

		// Derived on write and rendered as date strings on read, so they are readonly
		// and core leaves them out of the args a read-then-write round trip is checked against.
		foreach ( [ 'next_renewal', 'last_alert' ] as $derived ) {
			$this->assertArrayNotHasKey( $derived, $add_args, $derived );
			$this->assertArrayNotHasKey( $derived, $edit_args, $derived );
		}

		foreach ( [ 'type', 'slug', 'cost_status', 'cost_icon', 'cost_color' ] as $column_name ) {
			$this->assertArrayNotHasKey( $column_name, $add_args, $column_name );
			$this->assertArrayNotHasKey( $column_name, $edit_args, $column_name );
		}
	}

	/**
	 * Item 2: batch create validated last_renewal against the schema all along, so the
	 * integer type turned the documented date string into a rest_invalid_type 400.
	 */
	public function test_costs_batch_create_accepts_a_date_string_last_renewal(): void {
		$this->skip_if_no_costs_controller();
		$this->authenticate_as_admin();

		$site_id                 = $this->create_site( 'https://round8-cost-batch.example/' );
		$payload                 = $this->costs_payload( $site_id );
		$payload['last_renewal'] = '2026-01-15';

		$response = $this->do_authenticated_request( 'POST', '/mainwp/v2/costs/batch', [ 'create' => [ $payload ] ] );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertArrayNotHasKey( 'error', $data['create'][0], wp_json_encode( $data ) );

		$row = $this->get_cost_row();
		$this->assertNotNull( $row, 'the batch create should have inserted a cost row' );
		$this->assertSame( strtotime( '2026-01-15' ), (int) $row->last_renewal );
	}

	/**
	 * Item 3: sites, groups and clients are three independent lists, so a payload that
	 * carries more than one keeps them all. The elseif chain dropped everything after
	 * the first non-empty list.
	 */
	public function test_costs_create_persists_every_selector_list(): void {
		$this->skip_if_no_costs_controller();
		$this->authenticate_as_admin();

		$site_id            = $this->create_site( 'https://round8-cost-selectors.example/' );
		$payload            = $this->costs_payload( $site_id );
		$payload['groups']  = [ 2 ];
		$payload['clients'] = [ 1 ];

		$response = $this->do_authenticated_request( 'POST', '/mainwp/v2/costs/add', $payload );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$row = $this->get_cost_row();
		$this->assertNotNull( $row, 'the add route should have inserted a cost row' );
		$this->assertSame( [ $site_id ], json_decode( $row->sites, true ) );
		$this->assertSame( [ 2 ], json_decode( $row->groups, true ) );
		$this->assertSame( [ 1 ], json_decode( $row->clients, true ) );
	}

	/**
	 * Review round 1: WP_REST_Request ranks body params above URL params, so an edit used
	 * to write to whichever cost the body named. The batch route has no path id, so its
	 * items still address themselves by the id in the item body.
	 */
	public function test_costs_edit_writes_to_the_path_id_not_the_body_id(): void {
		$this->skip_if_no_costs_controller();
		$this->authenticate_as_admin();

		$site_id = $this->create_site( 'https://round8-cost-path-id.example/' );
		$first   = $this->create_cost( $site_id, self::COST_NAME . ' A' );
		$second  = $this->create_cost( $site_id, self::COST_NAME . ' B' );

		$payload         = $this->costs_payload( $site_id );
		$payload['id']   = (int) $second->id;
		$payload['name'] = self::COST_NAME . ' renamed by path';

		$response = $this->do_authenticated_request( 'PUT', '/mainwp/v2/costs/' . (int) $first->id . '/edit', $payload );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$renamed = $this->get_cost_row( self::COST_NAME . ' renamed by path' );
		$this->assertNotNull( $renamed, 'the edit should have renamed a cost row' );
		$this->assertSame( (int) $first->id, (int) $renamed->id );
		$this->assertNotNull( $this->get_cost_row( self::COST_NAME . ' B' ), 'the cost the body named should be untouched' );

		// Same payload through the batch route, where the item body is the only place an
		// id can come from.
		$payload['name'] = self::COST_NAME . ' renamed by body';

		$response = $this->do_authenticated_request( 'POST', '/mainwp/v2/costs/batch', [ 'update' => [ $payload ] ] );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertArrayNotHasKey( 'error', $data['update'][0], wp_json_encode( $data ) );

		$renamed = $this->get_cost_row( self::COST_NAME . ' renamed by body' );
		$this->assertNotNull( $renamed, 'the batch update should have renamed a cost row' );
		$this->assertSame( (int) $second->id, (int) $renamed->id );
	}

	/**
	 * Review round 1: the read routes resolve through the same get_request_item(), where
	 * a ?id= query param outranked the path before the pin moved into it.
	 */
	public function test_costs_get_returns_the_path_id_not_the_query_id(): void {
		$this->skip_if_no_costs_controller();
		$this->authenticate_as_admin();

		$site_id = $this->create_site( 'https://round8-cost-get-path-id.example/' );
		$first   = $this->create_cost( $site_id, self::COST_NAME . ' get A' );
		$second  = $this->create_cost( $site_id, self::COST_NAME . ' get B' );

		$response = $this->do_authenticated_request( 'GET', '/mainwp/v2/costs/' . (int) $first->id, [], [], '', [ 'id' => (int) $second->id ] );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertSame( (int) $first->id, (int) $data['data']->id, wp_json_encode( $data ) );
	}

	/**
	 * Review round 1: WP_REST_Request reads body and JSON params on a DELETE too, so the
	 * remove route is pinned to its path id the same way the edit route is.
	 */
	public function test_costs_remove_deletes_the_path_id_not_the_body_id(): void {
		$this->skip_if_no_costs_controller();
		$this->authenticate_as_admin();

		$site_id = $this->create_site( 'https://round8-cost-remove-id.example/' );
		$first   = $this->create_cost( $site_id, self::COST_NAME . ' A' );
		$second  = $this->create_cost( $site_id, self::COST_NAME . ' B' );

		$response = $this->do_authenticated_request(
			'DELETE',
			'/mainwp/v2/costs/' . (int) $first->id . '/remove',
			[ 'id' => (int) $second->id ]
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertNull( $this->get_cost_row( self::COST_NAME . ' A' ), 'the cost the path named should be gone' );
		$this->assertNotNull( $this->get_cost_row( self::COST_NAME . ' B' ), 'the cost the body named should be untouched' );
	}

	/**
	 * Item 5: an omitted force_use_ipv4 stays null, so the handshake falls back to the
	 * mainwp_forceUseIPv4 option the way the UI and the v1 add handler leave it. A sent
	 * value still maps to what the DB layer stores.
	 */
	public function test_prepare_object_for_database_leaves_omitted_force_use_ipv4_null(): void {
		$controller = new \MainWP_Rest_Sites_Controller();
		$method     = new \ReflectionMethod( $controller, 'prepare_object_for_database' );
		$method->setAccessible( true );

		$request = new WP_REST_Request( 'POST', '/mainwp/v2/sites/add' );
		$request->set_body_params( [ 'url' => 'https://round8-ipv4.example/' ] );

		$fields = $method->invoke( $controller, $request );

		$this->assertArrayHasKey( 'force_use_ipv4', $fields );
		$this->assertNull( $fields['force_use_ipv4'], 'absent' );

		$cases = [
			[ 0, 0 ],
			[ '0', 0 ],
			[ 2, 2 ],
		];

		foreach ( $cases as list( $input, $expected ) ) {
			$request = new WP_REST_Request( 'POST', '/mainwp/v2/sites/add' );
			$request->set_body_params(
				[
					'url'            => 'https://round8-ipv4.example/',
					'force_use_ipv4' => $input,
				]
			);

			$fields = $method->invoke( $controller, $request );

			$this->assertSame( $expected, $fields['force_use_ipv4'], wp_json_encode( $input ) );
		}
	}

	/**
	 * Review round 1: has_valid_params() only checks the highest-priority bag, while
	 * sanitize_params() runs the sanitizer over every bag. A valid body value alongside an
	 * invalid query value is refused by the sanitizer, which used to carry core's own enum
	 * wording instead of the values the route documents.
	 */
	public function test_force_use_ipv4_rewords_an_enum_rejection_from_any_bag(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request(
			'PUT',
			'/mainwp/v2/sites/999999/edit',
			[ 'force_use_ipv4' => 1 ],
			[],
			'',
			[ 'force_use_ipv4' => '-1' ]
		);

		$this->assertSame( 400, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertSame( 'rest_invalid_param', $data['code'], wp_json_encode( $data ) );
		$this->assertStringContainsString( '0, 1, or 2', $data['data']['params']['force_use_ipv4'], wp_json_encode( $data ) );
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
