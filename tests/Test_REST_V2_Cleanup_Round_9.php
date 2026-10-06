<?php
/**
 * MainWP REST v2 Cleanup Round 9 Tests
 *
 * Covers the body id on a cost create, the cost lookup rows for every selector
 * list, force_use_ipv4 on a site create, and the upgrade counters of a site that
 * has no option rows yet.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use WP_REST_Request;
use WP_REST_Server;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- test classes use PSR-style Test_* filenames, not the mainwp-* convention.

/**
 * Class Test_REST_V2_Cleanup_Round_9
 */
class Test_REST_V2_Cleanup_Round_9 extends \WP_Test_REST_TestCase {

	/**
	 * Description of the API key rows this class creates.
	 *
	 * @var string
	 */
	const KEY_DESCRIPTION = 'rest-v2-cleanup-round-9 Test API Key';

	/**
	 * Name of the tag rows this class inserts.
	 *
	 * @var string
	 */
	const TAG_NAME = 'rest-v2-cleanup-round-9 Batch Tag';

	/**
	 * Name of the site rows this class inserts.
	 *
	 * @var string
	 */
	const SITE_NAME = 'rest-v2-cleanup-round-9 Site';

	/**
	 * Name of the cost rows this class inserts.
	 *
	 * @var string
	 */
	const COST_NAME = 'rest-v2-cleanup-round-9 Cost';

	/**
	 * Name of the client rows this class inserts.
	 *
	 * @var string
	 */
	const CLIENT_NAME = 'rest-v2-cleanup-round-9 Client';

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
	 * Mirrors Test_REST_V2_Cleanup_Round_8: the MainWP REST server singleton
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

		// A test that stops before its own delete_client() call leaves the client row behind.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}mainwp_wp_clients WHERE name LIKE %s", $wpdb->esc_like( self::CLIENT_NAME ) . '%' ) );

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
	 * Insert a tag row directly, so a cost has a real tag to point at.
	 *
	 * @return int Tag ID.
	 */
	protected function create_tag(): int {
		$tag = \MainWP\Dashboard\MainWP_DB_Common::instance()->add_tag( [ 'name' => self::TAG_NAME ] );

		return (int) $tag->id;
	}

	/**
	 * Insert a client row directly, so a cost has a real client to point at.
	 *
	 * @return int Client ID.
	 */
	protected function create_client(): int {
		$client = \MainWP\Dashboard\MainWP_DB_Client::instance()->update_client(
			[
				'name'         => self::CLIENT_NAME,
				'client_email' => 'round9-client-' . wp_generate_uuid4() . '@example.test',
				'created'      => time(),
			]
		);

		$this->assertNotFalse( $client, 'update_client should insert a client row' );

		return (int) $client->client_id;
	}

	/**
	 * Insert a site row directly, so a cost has a real site to point at.
	 *
	 * @param string $url    Site URL.
	 * @param array  $params Extra params for add_website().
	 * @return int Site ID.
	 */
	protected function create_site( string $url, array $params = [] ): int {
		$site_id = $this->add_site_row( $url, $params );

		// add_website() leaves the wp_upgrades option row for a first sync to write, and
		// the collection query selects it through a subquery that yields null until then.
		// Seeding it keeps this fixture on the code path a connected site takes.
		\MainWP\Dashboard\MainWP_DB::instance()->update_website_option(
			\MainWP\Dashboard\MainWP_DB::instance()->get_website_by_id( $site_id ),
			'wp_upgrades',
			''
		);

		return $site_id;
	}

	/**
	 * Insert a site row through add_website() and leave it exactly as that call left it.
	 *
	 * @param string $url    Site URL.
	 * @param array  $params Extra params for add_website().
	 * @return int Site ID.
	 */
	protected function add_site_row( string $url, array $params = [] ): int {
		$site_id = \MainWP\Dashboard\MainWP_DB::instance()->add_website(
			$this->admin_user_id,
			self::SITE_NAME,
			$url,
			'admin',
			base64_encode( 'fake-pub-' . wp_generate_uuid4() ),
			base64_encode( 'fake-priv-' . wp_generate_uuid4() ),
			array_merge(
				[
					// Left unset these default to null, and the columns are NOT NULL.
					'http_user' => '',
					'http_pass' => '',
				],
				$params
			)
		);

		$this->assertNotFalse( $site_id, 'add_website should insert a site row' );

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
			'product_slug'        => 'round9-cost',
			'license_type'        => 'single_site',
			'cost_tracker_status' => 'active',
			'payment_method'      => 'visa',
			'renewal_type'        => 'monthly',
			'product_color'       => '#ff0000',
			'icon_hidden'         => 'deficon:round9',
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
	 * Read the lookup object ids recorded for one cost and one object name.
	 *
	 * @param int    $cost_id     Cost ID.
	 * @param string $object_name Lookup object name: site, tag or client.
	 * @return array Object ids, ascending.
	 */
	protected function get_lookup_object_ids( int $cost_id, string $object_name ): array {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT object_id FROM {$wpdb->prefix}mainwp_lookup_item_objects WHERE item_name = 'cost' AND item_id = %d AND object_name = %s ORDER BY object_id ASC",
				$cost_id,
				$object_name
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Item 1: the create route inserts, whatever id the body carries. The handler treats a
	 * non-empty id as "update this row", and the body bag outranks everything the create
	 * route registers.
	 */
	public function test_costs_add_with_a_body_id_inserts_a_new_row(): void {
		$this->skip_if_no_costs_controller();
		$this->authenticate_as_admin();

		$site_id  = $this->create_site( 'https://round9-cost-body-id.example/' );
		$existing = $this->create_cost( $site_id, self::COST_NAME . ' A' );

		$payload          = $this->costs_payload( $site_id );
		$payload['name']  = self::COST_NAME . ' B';
		$payload['price'] = 42.5;
		$payload['id']    = (int) $existing->id;

		$response = $this->do_authenticated_request( 'POST', '/mainwp/v2/costs/add', $payload );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertSame( 1, $data['success'], wp_json_encode( $data ) );
		$this->assertNotSame( (int) $existing->id, (int) $data['data']->id, 'the create should have inserted a new row' );

		$inserted = $this->get_cost_row( self::COST_NAME . ' B' );
		$this->assertNotNull( $inserted, 'the create should have inserted a new row' );
		$this->assertSame( (int) $data['data']->id, (int) $inserted->id );

		$untouched = $this->get_cost_row( self::COST_NAME . ' A' );
		$this->assertNotNull( $untouched, 'the cost the body named should still exist' );
		$this->assertSame( (float) $existing->price, (float) $untouched->price );
	}

	/**
	 * Item 1: the batch route has no path id, so its update items still address themselves
	 * by the id in the item body.
	 */
	public function test_costs_batch_update_still_writes_to_the_item_id(): void {
		$this->skip_if_no_costs_controller();
		$this->authenticate_as_admin();

		$site_id  = $this->create_site( 'https://round9-cost-batch-update.example/' );
		$existing = $this->create_cost( $site_id, self::COST_NAME . ' A' );

		$payload         = $this->costs_payload( $site_id );
		$payload['id']   = (int) $existing->id;
		$payload['name'] = self::COST_NAME . ' renamed by body';

		$response = $this->do_authenticated_request( 'POST', '/mainwp/v2/costs/batch', [ 'update' => [ $payload ] ] );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertArrayNotHasKey( 'error', $data['update'][0], wp_json_encode( $data ) );

		$renamed = $this->get_cost_row( self::COST_NAME . ' renamed by body' );
		$this->assertNotNull( $renamed, 'the batch update should have renamed a cost row' );
		$this->assertSame( (int) $existing->id, (int) $renamed->id );
		$this->assertNull( $this->get_cost_row( self::COST_NAME . ' A' ), 'the batch update should not have inserted a second row' );
	}

	/**
	 * Item 1: the schema documents id as readonly, so core leaves it out of the args the
	 * create route registers while the batch update route keeps its own id arg.
	 */
	public function test_costs_add_drops_the_id_arg_and_batch_update_keeps_one(): void {
		$this->skip_if_no_costs_controller();

		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( '/mainwp/v2/costs/add', $routes );

		$this->assertArrayNotHasKey( 'id', $routes['/mainwp/v2/costs/add'][0]['args'] );
		$this->assertArrayNotHasKey( 'id', $routes['/mainwp/v2/costs/(?P<id>[\d]+)/edit'][0]['args'] );

		$controller = new \MainWP_Rest_Costs_Controller();
		$this->assertArrayHasKey( 'id', $controller->get_batch_update_args() );
	}

	/**
	 * Item 2: sites, tags and clients are three independent lists, so a cost that carries
	 * more than one gets lookup rows for each of them. The chain this replaces recorded the
	 * first non-empty list only.
	 */
	public function test_costs_create_records_lookup_rows_for_every_selector_list(): void {
		$this->skip_if_no_costs_controller();
		$this->authenticate_as_admin();

		$site_id            = $this->create_site( 'https://round9-cost-lookup.example/' );
		$tag_id             = $this->create_tag();
		$payload            = $this->costs_payload( $site_id );
		$payload['groups']  = [ $tag_id ];
		$payload['clients'] = [ 7 ];

		$response = $this->do_authenticated_request( 'POST', '/mainwp/v2/costs/batch', [ 'create' => [ $payload ] ] );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertArrayNotHasKey( 'error', $data['create'][0], wp_json_encode( $data ) );

		$row = $this->get_cost_row();
		$this->assertNotNull( $row, 'the batch create should have inserted a cost row' );

		$cost_id = (int) $row->id;
		$this->assertSame( [ $site_id ], $this->get_lookup_object_ids( $cost_id, 'site' ) );
		$this->assertSame( [ $tag_id ], $this->get_lookup_object_ids( $cost_id, 'tag' ) );
		$this->assertSame( [ 7 ], $this->get_lookup_object_ids( $cost_id, 'client' ) );
	}

	/**
	 * Item 2: deleting a client or a tag prunes the cost lookup rows that pointed at it, the
	 * way deleting a site already did. A stale client row still matches the Costs page client
	 * filter, so the cost keeps showing up under an owner that no longer exists.
	 */
	public function test_deleting_a_client_or_a_tag_prunes_its_cost_lookup_rows(): void {
		$this->skip_if_no_costs_controller();
		$this->authenticate_as_admin();

		$site_id   = $this->create_site( 'https://round9-cost-lookup-delete.example/' );
		$tag_id    = $this->create_tag();
		$client_id = $this->create_client();

		$payload            = $this->costs_payload( $site_id );
		$payload['groups']  = [ $tag_id ];
		$payload['clients'] = [ $client_id ];

		$response = $this->do_authenticated_request( 'POST', '/mainwp/v2/costs/batch', [ 'create' => [ $payload ] ] );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertArrayNotHasKey( 'error', $data['create'][0], wp_json_encode( $data ) );

		$row = $this->get_cost_row();
		$this->assertNotNull( $row, 'the batch create should have inserted a cost row' );

		$cost_id = (int) $row->id;
		$this->assertSame( [ $site_id ], $this->get_lookup_object_ids( $cost_id, 'site' ) );
		$this->assertSame( [ $tag_id ], $this->get_lookup_object_ids( $cost_id, 'tag' ) );
		$this->assertSame( [ $client_id ], $this->get_lookup_object_ids( $cost_id, 'client' ) );

		$this->assertTrue( \MainWP\Dashboard\MainWP_DB_Client::instance()->delete_client( $client_id ), 'delete_client should have removed the client row' );
		$this->assertNotEmpty( \MainWP\Dashboard\MainWP_DB_Common::instance()->remove_group( $tag_id ), 'remove_group should have removed the tag row' );

		$this->assertSame( [ $site_id ], $this->get_lookup_object_ids( $cost_id, 'site' ) );
		$this->assertSame( [], $this->get_lookup_object_ids( $cost_id, 'tag' ) );
		$this->assertSame( [], $this->get_lookup_object_ids( $cost_id, 'client' ) );
	}

	/**
	 * Item 2: a list the caller drops is pruned from the lookup table, and only that list.
	 */
	public function test_costs_edit_prunes_only_the_dropped_selector_list(): void {
		$this->skip_if_no_costs_controller();
		$this->authenticate_as_admin();

		$site_id            = $this->create_site( 'https://round9-cost-lookup-prune.example/' );
		$payload            = $this->costs_payload( $site_id );
		$payload['clients'] = [ 7 ];

		$response = $this->do_authenticated_request( 'POST', '/mainwp/v2/costs/add', $payload );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$row = $this->get_cost_row();
		$this->assertNotNull( $row, 'the add route should have inserted a cost row' );
		$cost_id = (int) $row->id;

		$this->assertSame( [ $site_id ], $this->get_lookup_object_ids( $cost_id, 'site' ) );
		$this->assertSame( [ 7 ], $this->get_lookup_object_ids( $cost_id, 'client' ) );

		$response = $this->do_authenticated_request( 'PUT', '/mainwp/v2/costs/' . $cost_id . '/edit', $this->costs_payload( $site_id ) );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( [ $site_id ], $this->get_lookup_object_ids( $cost_id, 'site' ) );
		$this->assertSame( [], $this->get_lookup_object_ids( $cost_id, 'client' ) );
	}

	/**
	 * Item 3: add_website() writes the force_use_ipv4 it is given, so a value sent on a
	 * create no longer waits for an edit to reach the column. Absent and null leave the
	 * column at its NOT NULL default.
	 */
	public function test_add_website_persists_force_use_ipv4(): void {
		$this->admin_user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_user_id );

		$cases = [
			'use the global setting' => [ [ 'force_use_ipv4' => 2 ], '2' ],
			'forced on'              => [ [ 'force_use_ipv4' => 1 ], '1' ],
			'forced off'             => [ [ 'force_use_ipv4' => 0 ], '0' ],
			'null'                   => [ [ 'force_use_ipv4' => null ], '0' ],
			'absent'                 => [ [], '0' ],
		];

		$index = 0;
		foreach ( $cases as $label => list( $params, $expected ) ) {
			++$index;
			$site_id = $this->add_site_row( 'https://round9-ipv4-' . $index . '.example/', $params );

			$this->assertSame( $expected, (string) $this->get_site_column( $site_id, 'force_use_ipv4' ), $label );
		}
	}

	/**
	 * Item 4: the upgrade counters guard against an empty column, but the option columns are
	 * SQL NULL until a first sync writes them, and PHP 8.1+ deprecates json_decode( null ).
	 */
	public function test_listing_a_never_synced_site_emits_no_deprecation(): void {
		$this->admin_user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_user_id );

		$site_id = $this->add_site_row( 'https://round9-never-synced.example/' );

		$deprecations = [];
		$previous     = set_error_handler(
			function ( $errno, $errstr, $errfile = '', $errline = 0 ) use ( &$deprecations, &$previous ) {
				if ( E_DEPRECATED === $errno ) {
					$deprecations[] = $errstr;
					return true;
				}
				return $previous ? call_user_func( $previous, $errno, $errstr, $errfile, $errline ) : false;
			}
		);

		try {
			$websites = \MainWP\Dashboard\MainWP_DB::instance()->get_websites_for_current_user( [ 'full_data' => true ] );
		} finally {
			restore_error_handler();
		}

		$this->assertArrayHasKey( $site_id, $websites, 'the fixture site should be in the listing' );
		$this->assertSame( 0, (int) $websites[ $site_id ]->sum_of_upgrades );
		$this->assertSame( [], $deprecations, implode( "\n", $deprecations ) );
	}
}
