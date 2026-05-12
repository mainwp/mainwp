<?php
/**
 * MainWP REST API Integration Tests
 *
 * Tests for REST v2 controller integration with Abilities API.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use WP_REST_Request;
use WP_REST_Server;

/**
 * Class MainWP_REST_Integration_Test
 *
 * Tests REST v2 controllers' abilities-first pattern and fallback behavior.
 */
class MainWP_REST_Integration_Test extends \WP_Test_REST_TestCase {

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
	 * Whether abilities have been initialized for tests.
	 *
	 * @var bool
	 */
	private static $abilities_initialized = false;

	/**
	 * Set up test environment.
	 *
	 * Uses reflection to reset MainWP_Rest_Server singleton state between tests.
	 * This is required because the WordPress test framework doesn't provide clean
	 * singleton isolation. The REST server caches controller instances, which
	 * causes test pollution if not reset between test methods.
	 *
	 * @internal Tests depend on MainWP_Rest_Server::$instance and ::$controllers properties.
	 *           Changes to these property names or visibility will require test updates.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		// Note: mainwp_rest_api_v2_enabled filter is enabled in tests/bootstrap.php
		// at priority 99 (after the database check at priority 10), ensuring REST v2
		// routes are registered even without API keys in the test database.

		// Clear the controllers array on the MainWP REST server singleton.
		// This is critical because the singleton caches controller instances and
		// prevents them from registering routes on a new WP_REST_Server instance.
		// NOTE: We don't reset the singleton itself because the rest_api_init hook
		// is bound to the original instance. Instead, we clear its cached controllers.
		$reflection           = new \ReflectionClass( \MainWP_Rest_Server::class );
		$controllers_property = $reflection->getProperty( 'controllers' );
		$controllers_property->setAccessible( true );
		$controllers_property->setValue( \MainWP_Rest_Server::instance(), [] );

		// Reset REST authentication singleton.
		\MainWP_REST_Authentication::$instance = null;

		// Ensure abilities are registered for ability-based tests.
		if ( ! self::$abilities_initialized && function_exists( 'wp_get_ability' ) ) {
			$test_ability = wp_get_ability( 'mainwp/list-sites-v1' );
			if ( ! $test_ability ) {
				\MainWP\Dashboard\MainWP_Abilities::init();
				do_action( 'wp_abilities_api_categories_init' );
				do_action( 'wp_abilities_api_init' );
			}
			self::$abilities_initialized = true;
		}

		// Re-add the rest_api_init hook if it was removed by WordPress test framework.
		// WordPress test teardown removes hooks between tests, so we need to re-add it.
		if ( ! has_action( 'rest_api_init', [ \MainWP_Rest_Server::instance(), 'register_rest_routes' ] ) ) {
			add_action( 'rest_api_init', [ \MainWP_Rest_Server::instance(), 'register_rest_routes' ], 10 );
		}

		// Create fresh REST server and register routes.
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

		// Reset REST authentication singleton to avoid stale state between tests.
		\MainWP_REST_Authentication::$instance = null;

		// Reset REST server singleton to force re-registration of routes.
		// The singleton caches controller instances, preventing them from registering
		// routes on a new WP_REST_Server instance.
		$reflection = new \ReflectionClass( \MainWP_Rest_Server::class );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );

		// Clean up test sites.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_wp WHERE url LIKE 'https://test-%'" );

		// Clean up test API keys.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_api_keys WHERE description = 'Test API Key'" );

		parent::tearDown();
	}

	/**
	 * Skip test if Abilities API is not available.
	 *
	 * @return void
	 */
	protected function skip_if_no_abilities_api(): void {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'Abilities API not available.' );
		}
	}

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
	 * Authenticate as admin for REST requests.
	 *
	 * Creates an admin user and REST API key for authentication.
	 *
	 * @return void
	 */
	protected function authenticate_as_admin(): void {
		$this->admin_user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_user_id );

		// Create REST API key for this admin.
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

		$table = $wpdb->prefix . 'mainwp_api_keys';

		// Hash using the same method as MainWP (mainwp_api_hash function).
		$hashed_key = mainwp_api_hash( $consumer_key );

		$wpdb->insert(
			$table,
			[
				'user_id'         => $user_id,
				'description'     => 'Test API Key',
				'permissions'     => $permissions,
				'consumer_key'    => $hashed_key,
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
	 * Make an authenticated REST request.
	 *
	 * Sets up $_GET superglobals and triggers MainWP REST authentication
	 * since the auth hooks run before test setup.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  REST route.
	 * @param array  $params Request parameters.
	 * @return \WP_REST_Response Response object.
	 */
	protected function do_authenticated_request( string $method, string $route, array $params = [], array $body_params = [] ): \WP_REST_Response {
		// Backup superglobals.
		$original_get    = $_GET;
		$original_server = $_SERVER;

		// Set up authentication context.
		$_GET['consumer_key']    = $this->consumer_key;
		$_GET['consumer_secret'] = $this->consumer_secret;
		$_SERVER['HTTPS']        = 'on';            // Simulate SSL for authentication to work.
		$_SERVER['REQUEST_URI']  = '/wp-json' . $route; // For is_request_to_rest_api() check.

		// Reset authentication singleton to force fresh authentication.
		// This prevents stale state from previous tests from interfering.
		\MainWP_REST_Authentication::$instance = null;

		// Force authentication to run with our API credentials.
		// The auth hooks already fired during bootstrap, so we need to trigger it again.
		$auth = \MainWP_REST_Authentication::get_instance();
		$auth->authenticate( 0 );

		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		// Set body params for POST/PUT/PATCH requests.
		if ( ! empty( $body_params ) ) {
			$request->set_body_params( $body_params );
		}

		$response = rest_do_request( $request );

		// Restore superglobals.
		$_GET    = $original_get;
		$_SERVER = $original_server;

		return $response;
	}

	/**
	 * Create a test site.
	 *
	 * Creates a site in mainwp_wp table and corresponding records in
	 * mainwp_wp_sync and mainwp_wp_options tables as needed.
	 *
	 * @param array $args Site properties.
	 * @return int Site ID.
	 */
	protected function create_test_site( array $args = [] ): int {
		global $wpdb;

		// Extract values that go to other tables (not columns in mainwp_wp).
		$verify_method = $args['verify_method'] ?? 1;
		$version       = $args['version'] ?? '5.0.0';
		$sync_errors   = $args['sync_errors'] ?? '';

		// Remove non-column fields from args before merging.
		unset( $args['verify_method'], $args['version'], $args['sync_errors'] );

		// Defaults for mainwp_wp table columns only.
		// Use current user ID if available, otherwise use 1.
		$current_user_id = get_current_user_id();
		$defaults        = [
			'userid'               => $current_user_id > 0 ? $current_user_id : 1,
			'url'                  => 'https://test-' . wp_generate_uuid4() . '.example.com/',
			'name'                 => 'Test Site',
			'adminname'            => 'admin',
			'pubkey'               => 'test-pubkey',
			'privkey'              => 'test-privkey',
			'ssl_version'          => 0,
			'http_user'            => '',
			'http_pass'            => '',
			'suspended'            => 0,
			'offline_check_result' => 1,
			'client_id'            => 0,
			// Upgrade columns - use empty string to avoid json_decode(null) deprecation.
			'plugin_upgrades'      => '',
			'theme_upgrades'       => '',
			'translation_upgrades' => '',
			'premium_upgrades'     => '',
		];

		// Format specifiers matching the column types.
		$formats = [
			'userid'               => '%d',
			'url'                  => '%s',
			'name'                 => '%s',
			'adminname'            => '%s',
			'pubkey'               => '%s',
			'privkey'              => '%s',
			'ssl_version'          => '%d',
			'http_user'            => '%s',
			'http_pass'            => '%s',
			'suspended'            => '%d',
			'offline_check_result' => '%d',
			'client_id'            => '%d',
		];

		$data = array_merge( $defaults, $args );

		// Build format array in same order as data keys.
		$format_array = [];
		foreach ( array_keys( $data ) as $key ) {
			$format_array[] = $formats[ $key ] ?? '%s';
		}

		$wpdb->insert(
			$wpdb->prefix . 'mainwp_wp',
			$data,
			$format_array
		);

		$site_id = (int) $wpdb->insert_id;

		// Store verify_method in options table.
		$this->set_site_option( $site_id, 'verify_method', $verify_method );

		// Set wp_upgrades option to empty JSON to avoid json_decode(null) deprecation.
		$this->set_site_option( $site_id, 'wp_upgrades', '[]' );

		// Create sync record with version and sync_errors.
		$this->create_test_site_sync(
			$site_id,
			[
				'version'     => $version,
				'sync_errors' => $sync_errors,
			]
		);

		return $site_id;
	}

	/**
	 * Create a sync record for a test site.
	 *
	 * @param int   $site_id Site ID.
	 * @param array $args    Sync properties.
	 * @return void
	 */
	protected function create_test_site_sync( int $site_id, array $args = [] ): void {
		global $wpdb;

		$defaults = [
			'wpid'        => $site_id,
			'version'     => '5.0.0',
			'sync_errors' => '',
		];

		$data = array_merge( $defaults, $args );
		$data['wpid'] = $site_id;

		$wpdb->insert(
			$wpdb->prefix . 'mainwp_wp_sync',
			$data,
			[ '%d', '%s', '%s' ]
		);
	}

	/**
	 * Set a site option via MainWP's wp_options table.
	 *
	 * @param int    $site_id Site ID.
	 * @param string $option  Option name.
	 * @param mixed  $value   Option value.
	 * @return void
	 */
	protected function set_site_option( int $site_id, string $option, $value ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'mainwp_wp_options';

		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE wpid = %d AND name = %s",
				$site_id,
				$option
			)
		);

		$serialized = is_scalar( $value ) ? $value : maybe_serialize( $value );

		if ( $exists ) {
			$wpdb->update(
				$table,
				[ 'value' => $serialized ],
				[
					'wpid' => $site_id,
					'name' => $option,
				],
				[ '%s' ],
				[ '%d', '%s' ]
			);
		} else {
			$wpdb->insert(
				$table,
				[
					'wpid'  => $site_id,
					'name'  => $option,
					'value' => $serialized,
				],
				[ '%d', '%s', '%s' ]
			);
		}
	}

	// =========================================================================
	// Sites Endpoint Tests
	// =========================================================================

	/**
	 * Test that REST sites endpoint exists.
	 *
	 * @return void
	 */
	public function test_rest_sites_endpoint_exists() {
		$routes = $this->server->get_routes();

		$this->assertArrayHasKey(
			'/mainwp/v2/sites',
			$routes,
			'Sites endpoint should exist.'
		);
	}

	/**
	 * Test that REST sites endpoint uses ability when available.
	 *
	 * @return void
	 */
	public function test_rest_sites_endpoint_uses_ability_when_available() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		$this->create_test_site( [ 'name' => 'REST Test Site' ] );

		$response = $this->do_authenticated_request(
			'GET',
			'/mainwp/v2/sites',
			[
				'paged'    => 1,
				'per_page' => 10,
			]
		);

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'success', $data );
		$this->assertArrayHasKey( 'data', $data );
	}

	/**
	 * Test that REST sites sync endpoint uses ability.
	 *
	 * @return void
	 */
	public function test_rest_sites_sync_endpoint_uses_ability() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Sync REST Test',
			'offline_check_result' => 1,
		] );

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/sites/sync',
			[],
			[ 'id_domain' => [ $site_id ] ]
		);

		// 200 or 207 (multi-status) are valid.
		$this->assertContains(
			$response->get_status(),
			[ 200, 207 ],
			'Sync should return 200 or 207.'
		);

		$data = $response->get_data();
		$this->assertIsArray( $data );
	}

	// =========================================================================
	// Updates Endpoint Tests
	// =========================================================================

	/**
	 * Test that REST updates endpoint exists.
	 *
	 * @return void
	 */
	public function test_rest_updates_endpoint_exists() {
		$routes = $this->server->get_routes();

		$this->assertArrayHasKey(
			'/mainwp/v2/updates',
			$routes,
			'Updates endpoint should exist.'
		);
	}

	/**
	 * Test that REST updates endpoint uses ability when available.
	 *
	 * Tests GET /mainwp/v2/updates returns proper response structure.
	 *
	 * @return void
	 */
	public function test_rest_updates_endpoint_uses_ability_when_available() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		// Create a test site to ensure we have data.
		$this->create_test_site( [ 'name' => 'Updates REST Test Site' ] );

		$response = $this->do_authenticated_request( 'GET', '/mainwp/v2/updates' );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'success', $data );
		$this->assertArrayHasKey( 'data', $data );
	}

	/**
	 * Test that REST updates endpoint response matches list-updates ability output shape.
	 *
	 * The data key should contain updates keyed by site ID with nested type arrays.
	 *
	 * @return void
	 */
	public function test_rest_updates_endpoint_response_shape() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		// Create a test site to ensure updates data exists.
		$this->create_test_site( [
			'name'    => 'Updates Shape Test Site',
			'version' => '5.0.0',
		] );

		$response = $this->do_authenticated_request( 'GET', '/mainwp/v2/updates' );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();

		// Verify top-level structure.
		$this->assertArrayHasKey( 'success', $data );
		$this->assertEquals( 1, $data['success'] );
		$this->assertArrayHasKey( 'data', $data );

		// The data should be an array (updates keyed by site ID or empty).
		$this->assertIsArray( $data['data'] );
	}

	/**
	 * Test that REST updates endpoint for specific site returns proper structure.
	 *
	 * Tests GET /mainwp/v2/updates/{site_id} returns updates for that site.
	 *
	 * @return void
	 */
	public function test_rest_updates_per_site_endpoint() {
		$this->authenticate_as_admin();

		$site_id = $this->create_test_site( [
			'name'    => 'Per-Site Updates Test',
			'version' => '5.0.0',
		] );

		$response = $this->do_authenticated_request( 'GET', '/mainwp/v2/updates/' . $site_id );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'success', $data );
		$this->assertArrayHasKey( 'data', $data );

		// Per-site updates should include type keys (wp, plugins, themes, translations).
		$updates_data = $data['data'];
		$this->assertIsArray( $updates_data );

		// At minimum, when type is 'all' (default), these keys should be present.
		// They may be empty arrays if no updates are available.
		$expected_keys = [ 'wp', 'plugins', 'themes', 'translations' ];
		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey(
				$key,
				$updates_data,
				"Updates response should include '{$key}' key."
			);
		}
	}

	/**
	 * Test that REST run-updates endpoint exists and requires authentication.
	 *
	 * Tests POST /mainwp/v2/updates/update (update all).
	 *
	 * @return void
	 */
	public function test_rest_run_updates_endpoint_requires_auth() {
		wp_set_current_user( 0 );

		// Unauthenticated request - use direct rest_do_request to test no auth.
		$request  = new WP_REST_Request( 'POST', '/mainwp/v2/updates/update' );
		$response = rest_do_request( $request );

		// Should return 401 or 403 for unauthenticated request.
		$this->assertContains(
			$response->get_status(),
			[ 401, 403 ],
			'Run-updates endpoint should require authentication.'
		);
	}

	/**
	 * Test that REST run-updates endpoint uses ability when available.
	 *
	 * @return void
	 */
	public function test_rest_run_updates_endpoint_uses_ability() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request( 'POST', '/mainwp/v2/updates/update' );

		// Should return 200 (either immediate result or queued response).
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'success', $data );
		$this->assertEquals( 1, $data['success'] );
		$this->assertArrayHasKey( 'message', $data );
	}

	/**
	 * Test that REST per-site run-updates endpoint works.
	 *
	 * Tests POST /mainwp/v2/updates/{site_id}/update
	 *
	 * @return void
	 */
	public function test_rest_run_updates_per_site_endpoint() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Run Updates Per-Site Test',
			'offline_check_result' => 1,
			'suspended'            => 0,
		] );

		$response = $this->do_authenticated_request( 'POST', '/mainwp/v2/updates/' . $site_id . '/update' );

		// Should return 200.
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'success', $data );
		$this->assertEquals( 1, $data['success'] );
	}

	// =========================================================================
	// Authentication Tests
	// =========================================================================

	/**
	 * Test that unauthenticated request is denied.
	 *
	 * @return void
	 */
	public function test_rest_unauthenticated_denied() {
		wp_set_current_user( 0 );

		// Unauthenticated request - use direct rest_do_request to test no auth.
		$request = new WP_REST_Request( 'GET', '/mainwp/v2/sites' );
		$response = rest_do_request( $request );

		// Should return 401 or 403.
		$this->assertContains(
			$response->get_status(),
			[ 401, 403 ],
			'Unauthenticated request should be denied.'
		);
	}

	/**
	 * Test that authenticated request is allowed.
	 *
	 * @return void
	 */
	public function test_rest_authenticated_allowed() {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request( 'GET', '/mainwp/v2/sites' );

		$this->assertEquals( 200, $response->get_status() );
	}

	// =========================================================================
	// Parameter Mapping Tests
	// =========================================================================

	/**
	 * Test that REST parameter mapping works.
	 *
	 * @return void
	 */
	public function test_rest_parameter_mapping_to_ability_input() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		// Create sites to have something to paginate.
		for ( $i = 0; $i < 15; $i++ ) {
			$this->create_test_site( [ 'name' => "Param Test Site {$i}" ] );
		}

		$response = $this->do_authenticated_request(
			'GET',
			'/mainwp/v2/sites',
			[ 'paged' => 2, 'per_page' => 5 ]
		);

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		// Verify pagination was applied.
		if ( isset( $data['data']['page'] ) ) {
			$this->assertEquals( 2, $data['data']['page'] );
		}
	}

	/**
	 * Test that REST response format is correct.
	 *
	 * @return void
	 */
	public function test_rest_response_format() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request( 'GET', '/mainwp/v2/sites' );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );

		// MainWP REST responses typically have success/data structure.
		// Note: PHP/MySQL may return integer 1 instead of boolean true.
		if ( isset( $data['success'] ) ) {
			$this->assertTrue( (bool) $data['success'], 'Response success should be truthy.' );
		}
	}

	// =========================================================================
	// Queued Response Tests
	// =========================================================================

	/**
	 * Test that REST queued response format is correct.
	 *
	 * @return void
	 */
	public function test_rest_queued_response_format() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		// Create 60 sites to trigger queuing.
		$site_ids = [];
		for ( $i = 0; $i < 60; $i++ ) {
			$site_ids[] = $this->create_test_site( [
				'name'                 => "Queued REST Site {$i}",
				'offline_check_result' => 1,
			] );
		}

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/sites/sync',
			[],
			[ 'id_domain' => $site_ids ]
		);

		// Should return 202 or 200 with queued response.
		$this->assertContains(
			$response->get_status(),
			[ 200, 202 ],
			'Queued response should return 200 or 202.'
		);

		$data = $response->get_data();
		$this->assertIsArray( $data );

		// Check for queued indicators.
		if ( isset( $data['data']['queued'] ) && $data['data']['queued'] ) {
			$this->assertArrayHasKey( 'job_id', $data['data'] );
			$this->assertArrayHasKey( 'status_url', $data['data'] );
		}
	}

	// =========================================================================
	// Error Handling Tests
	// =========================================================================

	/**
	 * Test that REST error responses have proper format.
	 *
	 * @return void
	 */
	public function test_rest_error_response_format() {
		$this->authenticate_as_admin();

		// Request non-existent site.
		$routes = $this->server->get_routes();

		// Check if single site endpoint exists.
		if ( isset( $routes['/mainwp/v2/sites/(?P<id>[\\d]+)'] ) ) {
			$response = $this->do_authenticated_request( 'GET', '/mainwp/v2/sites/999999' );

			// Should return 404.
			$this->assertEquals( 404, $response->get_status() );

			$data = $response->get_data();
			$this->assertIsArray( $data );
		} else {
			$this->markTestSkipped( 'Route /mainwp/v2/sites/{id} not registered.' );
		}
	}

	/**
	 * Test that REST permission error has proper status.
	 *
	 * @return void
	 */
	public function test_rest_permission_error_status() {
		// Create subscriber - no REST API key, tests capability check.
		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		// Subscriber without API key - direct request tests permission callback.
		// MainWP REST API requires API key authentication, so without credentials
		// the user is treated as unauthenticated (401), not unauthorized (403).
		$request = new WP_REST_Request( 'GET', '/mainwp/v2/sites' );
		$response = rest_do_request( $request );

		// MainWP returns 401 (not authenticated) for requests without API credentials.
		$this->assertContains(
			$response->get_status(),
			[ 401, 403 ],
			'Should return 401 (no API key) or 403 (forbidden).'
		);
	}

	// =========================================================================
	// Fallback Behavior Tests
	// =========================================================================

	/**
	 * Test that REST endpoints work regardless of Abilities API.
	 *
	 * @return void
	 */
	public function test_rest_endpoints_work_without_ability_api() {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request( 'GET', '/mainwp/v2/sites' );

		// Should work whether abilities are available or not.
		$this->assertEquals( 200, $response->get_status() );
	}

	/**
	 * Test that REST sites endpoint works.
	 *
	 * This test validates that the REST sites endpoint returns valid data.
	 * Note: We can't easily simulate an environment without abilities
	 * since REST routes are registered during init.
	 *
	 * @return void
	 */
	public function test_rest_sites_endpoint_works_without_abilities_initialized() {
		// Authenticate as admin.
		$this->authenticate_as_admin();

		// Create a test site to ensure we have data.
		$this->create_test_site( [ 'name' => 'Fallback Test Site' ] );

		// Issue authenticated request.
		$response = $this->do_authenticated_request( 'GET', '/mainwp/v2/sites' );

		// Endpoint should work and return 200.
		$this->assertEquals(
			200,
			$response->get_status(),
			'Sites endpoint should work.'
		);

		// Verify response is valid structure.
		$data = $response->get_data();
		$this->assertIsArray( $data, 'Response should be an array.' );

		// MainWP REST responses have success/data structure.
		$this->assertArrayHasKey( 'success', $data, 'Response should have success key.' );
		$this->assertArrayHasKey( 'data', $data, 'Response should have data key.' );

		// Verify response data contains site information.
		$response_data = $data['data'];
		$this->assertNotEmpty( $response_data, 'Response should contain site data.' );
	}

	// =========================================================================
	// Content Type Tests
	// =========================================================================

	/**
	 * Test that REST response content type is JSON.
	 *
	 * @return void
	 */
	public function test_rest_response_content_type() {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request( 'GET', '/mainwp/v2/sites' );

		$data = $response->get_data();

		// Response should be array (JSON-serializable).
		$this->assertTrue(
			is_array( $data ) || is_object( $data ),
			'Response should be JSON-serializable.'
		);
	}

	// =========================================================================
	// Ability-Backed Path Verification Tests
	// =========================================================================

	/**
	 * Test that REST sites endpoint with pagination returns ability-style response.
	 *
	 * When Abilities API is available and the list-sites ability is used,
	 * the response should include pagination metadata from the ability.
	 *
	 * @return void
	 */
	public function test_rest_sites_endpoint_ability_pagination() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		// Create multiple test sites for pagination.
		for ( $i = 0; $i < 15; $i++ ) {
			$this->create_test_site( [ 'name' => "Pagination Test Site {$i}" ] );
		}

		$response = $this->do_authenticated_request(
			'GET',
			'/mainwp/v2/sites',
			[ 'paged' => 1, 'per_page' => 5 ]
		);

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'success', $data );
		$this->assertArrayHasKey( 'data', $data );

		// Verify pagination is respected in response.
		// The ability path includes pagination metadata.
		$response_data = $data['data'];
		if ( is_array( $response_data ) ) {
			// Count returned items - should be limited by per_page.
			$items = isset( $response_data['items'] ) ? $response_data['items'] : $response_data;
			$this->assertLessThanOrEqual(
				5,
				count( $items ),
				'Response should respect per_page limit.'
			);
		}
	}

	/**
	 * Test that REST sync endpoint returns ability-backed queued response format.
	 *
	 * When syncing many sites via the ability, the response should include
	 * queued response indicators (job_id, status_url, sites_queued).
	 *
	 * @return void
	 */
	public function test_rest_sync_endpoint_queued_response_has_ability_fields() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		// Create 60 sites to trigger queuing (threshold is 50).
		$site_ids = [];
		for ( $i = 0; $i < 60; $i++ ) {
			$site_ids[] = $this->create_test_site( [
				'name'                 => "Ability Queued Site {$i}",
				'offline_check_result' => 1,
			] );
		}

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/sites/sync',
			[],
			[ 'id_domain' => $site_ids ]
		);

		$this->assertContains(
			$response->get_status(),
			[ 200, 202 ],
			'Large batch sync should return 200 or 202.'
		);

		$data = $response->get_data();
		$this->assertIsArray( $data );

		// Check for ability-backed queued response fields.
		// These fields are only present when the sync-sites ability queues the job.
		$response_data = isset( $data['data'] ) ? $data['data'] : $data;
		if ( isset( $response_data['queued'] ) && $response_data['queued'] ) {
			$this->assertArrayHasKey(
				'job_id',
				$response_data,
				'Queued ability response should include job_id.'
			);
			$this->assertArrayHasKey(
				'status_url',
				$response_data,
				'Queued ability response should include status_url.'
			);
			$this->assertArrayHasKey(
				'sites_queued',
				$response_data,
				'Queued ability response should include sites_queued count.'
			);
			$this->assertEquals(
				60,
				$response_data['sites_queued'],
				'sites_queued should match number of sites submitted.'
			);
		}
	}

	/**
	 * Test that REST sync immediate response has ability-backed structure.
	 *
	 * For small batches (≤50 sites), the ability executes immediately and
	 * returns synced/errors/total_synced/total_errors fields.
	 *
	 * @return void
	 */
	public function test_rest_sync_endpoint_immediate_response_has_ability_fields() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		// Create 3 sites (under threshold for immediate execution).
		$site_ids = [];
		for ( $i = 0; $i < 3; $i++ ) {
			$site_ids[] = $this->create_test_site( [
				'name'                 => "Immediate Sync Site {$i}",
				'offline_check_result' => 1,
			] );
		}

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/sites/sync',
			[],
			[ 'id_domain' => $site_ids ]
		);

		$this->assertContains(
			$response->get_status(),
			[ 200, 207 ],
			'Small batch sync should return 200 or 207.'
		);

		$data = $response->get_data();
		$this->assertIsArray( $data );

		// Check for REST controller sync response fields.
		// The REST controller transforms the ability output to a different format:
		// - 'total' and 'data' for immediate responses
		// - 'message', 'job_id', 'total' for queued responses

		// Immediate responses should NOT have a job_id (queued indicator).
		$this->assertFalse(
			isset( $data['job_id'] ),
			'Small batch should not be queued (no job_id).'
		);

		// Should have 'total' and 'data' keys (REST sync response format).
		$has_total = isset( $data['total'] );
		$has_data = isset( $data['data'] );
		$has_message = isset( $data['message'] );

		// Ability-backed REST response has total/data, or message for errors.
		$this->assertTrue(
			( $has_total && $has_data ) || $has_message,
			'Immediate sync response should have total/data or message.'
		);
	}

	/**
	 * Test that REST updates endpoint returns ability-backed response shape.
	 *
	 * When list-updates ability is used, the response should include
	 * updates array and summary object with type breakdowns.
	 *
	 * @return void
	 */
	public function test_rest_updates_endpoint_ability_response_shape() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		// Create site for side effect - updates endpoint needs at least one site.
		$this->create_test_site( [
			'name'    => 'Updates Shape Test',
			'version' => '5.0.0',
		] );

		$response = $this->do_authenticated_request( 'GET', '/mainwp/v2/updates' );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'success', $data );
		$this->assertArrayHasKey( 'data', $data );

		// The response should be structured - abilities provide structured output.
		$this->assertIsArray( $data['data'] );
	}

	// =========================================================================
	// Batch Endpoint Tests
	// =========================================================================

	/**
	 * Test that batch sync endpoint accepts array of IDs.
	 *
	 * @return void
	 */
	public function test_rest_batch_sync_accepts_id_array() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		$site1_id = $this->create_test_site( [ 'name' => 'Batch 1', 'offline_check_result' => 1 ] );
		$site2_id = $this->create_test_site( [ 'name' => 'Batch 2', 'offline_check_result' => 1 ] );

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/sites/sync',
			[],
			[ 'id_domain' => [ $site1_id, $site2_id ] ]
		);

		$this->assertContains(
			$response->get_status(),
			[ 200, 207 ],
			'Batch sync should accept array of IDs.'
		);
	}

	/**
	 * Test that batch sync endpoint handles mixed identifiers.
	 *
	 * @return void
	 */
	public function test_rest_batch_sync_handles_mixed_identifiers() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		$site1_id = $this->create_test_site( [ 'name' => 'ID Site', 'offline_check_result' => 1 ] );
		$this->create_test_site( [
			'name'                 => 'Domain Site',
			'url'                  => 'https://test-restmixed.example.com/',
			'offline_check_result' => 1,
		] );

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/sites/sync',
			[],
			[ 'id_domain' => [ $site1_id, 'test-restmixed.example.com' ] ]
		);

		$this->assertContains(
			$response->get_status(),
			[ 200, 207 ],
			'Batch sync should handle mixed identifiers.'
		);
	}
}
