<?php
/**
 * MainWP Abilities API REST Execution Tests
 *
 * Tests for executing MainWP abilities directly via the Abilities API REST endpoints.
 * This tests the `/wp-abilities/v1/abilities/{name}/run` endpoints, NOT the MainWP
 * REST v2 controllers (which are tested in test-rest-integration.php).
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use WP_REST_Request;
use WP_REST_Server;

/**
 * Class MainWP_REST_API_Execution_Test
 *
 * Tests direct execution of MainWP abilities via Abilities API REST endpoints.
 *
 * Abilities tested:
 * - Read-only (GET): list-sites-v1, get-site-v1, get-site-plugins-v1, get-site-themes-v1,
 *                    list-updates-v1, list-ignored-updates-v1
 * - Write (POST): sync-sites-v1, run-updates-v1, set-ignored-updates-v1
 */
class MainWP_REST_API_Execution_Test extends \WP_Test_REST_TestCase {

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
	 * Created site IDs for cleanup.
	 *
	 * @var array
	 */
	protected $created_site_ids = [];

	/**
	 * Base route for Abilities API.
	 *
	 * @var string
	 */
	const ABILITIES_BASE = '/wp-abilities/v1/abilities';

	/**
	 * Whether abilities have been initialized for tests.
	 *
	 * @var bool
	 */
	private static $abilities_initialized = false;

	/**
	 * Set up test environment.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure abilities are registered BEFORE creating REST server.
		// The Abilities API REST controller needs abilities to exist when routes are built.
		if ( ! self::$abilities_initialized && function_exists( 'wp_get_ability' ) ) {
			$test_ability = wp_get_ability( 'mainwp/list-sites-v1' );
			if ( ! $test_ability ) {
				\MainWP\Dashboard\MainWP_Abilities::init();
				do_action( 'wp_abilities_api_categories_init' );
				do_action( 'wp_abilities_api_init' );
			}
			self::$abilities_initialized = true;
		}

		// Create REST server AFTER abilities are registered.
		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->created_site_ids = [];
	}

	/**
	 * Tear down test environment.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wpdb, $wp_rest_server;
		$wp_rest_server = null;

		// Get site IDs before deleting main records.
		$site_ids = $this->created_site_ids;

		// Clean up related tables for tracked site IDs.
		if ( ! empty( $site_ids ) ) {
			$ids_placeholder = implode( ',', array_map( 'intval', $site_ids ) );
			$wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_wp_sync WHERE wpid IN ({$ids_placeholder})" );
			$wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_wp_options WHERE wpid IN ({$ids_placeholder})" );
		}

		// Also clean by URL pattern (catches any sites not in created_site_ids).
		$wpdb->query(
			"DELETE FROM {$wpdb->prefix}mainwp_wp_sync
			 WHERE wpid IN (SELECT id FROM {$wpdb->prefix}mainwp_wp WHERE url LIKE 'https://test-%')"
		);
		$wpdb->query(
			"DELETE FROM {$wpdb->prefix}mainwp_wp_options
			 WHERE wpid IN (SELECT id FROM {$wpdb->prefix}mainwp_wp WHERE url LIKE 'https://test-%')"
		);

		// Clean up test sites (main table - do this AFTER related tables).
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mainwp_wp WHERE url LIKE 'https://test-%'" );

		// Clean up any job transients.
		foreach ( $site_ids as $site_id ) {
			delete_transient( 'mainwp_sync_job_' . $site_id );
		}

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
	 * Authenticate as admin for REST requests.
	 *
	 * @return void
	 */
	protected function authenticate_as_admin(): void {
		$this->admin_user_id = $this->factory->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_user_id );

		// Grant MainWP API access.
		update_user_meta( $this->admin_user_id, 'mainwp_api_enabled', 1 );
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
		$this->created_site_ids[] = $site_id;

		// Store verify_method in options table.
		$this->set_site_option( $site_id, 'verify_method', $verify_method );

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

		// Build format array dynamically to match $data keys/values.
		$formats = [];
		foreach ( $data as $value ) {
			$formats[] = is_int( $value ) ? '%d' : '%s';
		}

		$wpdb->insert(
			$wpdb->prefix . 'mainwp_wp_sync',
			$data,
			$formats
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

	/**
	 * Set plugins data for a test site.
	 *
	 * @param int   $site_id Site ID.
	 * @param array $plugins Array of plugin data.
	 * @return void
	 */
	protected function set_site_plugins( int $site_id, array $plugins ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'mainwp_wp',
			[ 'plugins' => wp_json_encode( $plugins ) ],
			[ 'id' => $site_id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Set plugin upgrades data for a test site.
	 *
	 * @param int   $site_id  Site ID.
	 * @param array $upgrades Array of plugin upgrade data.
	 * @return void
	 */
	protected function set_site_plugin_upgrades( int $site_id, array $upgrades ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'mainwp_wp',
			[ 'plugin_upgrades' => wp_json_encode( $upgrades ) ],
			[ 'id' => $site_id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Set themes data for a test site.
	 *
	 * @param int   $site_id Site ID.
	 * @param array $themes  Array of theme data.
	 * @return void
	 */
	protected function set_site_themes( int $site_id, array $themes ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'mainwp_wp',
			[ 'themes' => wp_json_encode( $themes ) ],
			[ 'id' => $site_id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Set theme upgrades data for a test site.
	 *
	 * @param int   $site_id  Site ID.
	 * @param array $upgrades Array of theme upgrade data.
	 * @return void
	 */
	protected function set_site_theme_upgrades( int $site_id, array $upgrades ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'mainwp_wp',
			[ 'theme_upgrades' => wp_json_encode( $upgrades ) ],
			[ 'id' => $site_id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Set ignored plugins data for a test site.
	 *
	 * @param int   $site_id Site ID.
	 * @param array $ignored Array of ignored plugin data.
	 * @return void
	 */
	protected function set_site_ignored_plugins( int $site_id, array $ignored ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'mainwp_wp',
			[ 'ignored_plugins' => wp_json_encode( $ignored ) ],
			[ 'id' => $site_id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Set ignored themes data for a test site.
	 *
	 * @param int   $site_id Site ID.
	 * @param array $ignored Array of ignored theme data.
	 * @return void
	 */
	protected function set_site_ignored_themes( int $site_id, array $ignored ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'mainwp_wp',
			[ 'ignored_themes' => wp_json_encode( $ignored ) ],
			[ 'id' => $site_id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Build ability run URL.
	 *
	 * @param string $ability_name Ability name (e.g., 'mainwp/list-sites-v1').
	 * @return string Full route path.
	 */
	protected function ability_run_url( string $ability_name ): string {
		return self::ABILITIES_BASE . '/' . $ability_name . '/run';
	}

	/**
	 * Set JSON input for an ability POST request.
	 *
	 * The Abilities API expects input as JSON in the request body for POST.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @param array           $input   The input data.
	 * @return void
	 */
	protected function set_ability_input( WP_REST_Request $request, array $input ): void {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'input' => $input ] ) );
	}

	/**
	 * Set query param input for an ability GET request.
	 *
	 * The Abilities API expects input as a query param for GET requests.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @param array           $input   The input data.
	 * @return void
	 */
	protected function set_ability_input_query_param( WP_REST_Request $request, array $input ): void {
		$request->set_query_params( [ 'input' => $input ] );
	}

	// =========================================================================
	// Read-only Abilities Tests (GET requests)
	// =========================================================================

	/**
	 * Test list-sites-v1 ability via GET with no input.
	 *
	 * Read-only abilities should work with GET and no input parameter,
	 * using schema defaults for pagination and filtering.
	 *
	 * @return void
	 */
	public function test_list_sites_via_rest_get_no_input() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		// Create test sites.
		$this->create_test_site( [ 'name' => 'List Sites Test 1' ] );
		$this->create_test_site( [ 'name' => 'List Sites Test 2' ] );

		// list-sites-v1 is readonly, so it requires GET method.
		$request = new WP_REST_Request( 'GET', $this->ability_run_url( 'mainwp/list-sites-v1' ) );
		$response = rest_do_request( $request );

		$this->assertEquals(
			200,
			$response->get_status(),
			'list-sites-v1 via GET should return 200 OK.'
		);

		$data = $response->get_data();
		$this->assertIsArray( $data, 'Response should be an array.' );

		// Verify output schema structure.
		$this->assertArrayHasKey( 'items', $data, 'Response should have items key.' );
		$this->assertArrayHasKey( 'page', $data, 'Response should have page key.' );
		$this->assertArrayHasKey( 'per_page', $data, 'Response should have per_page key.' );
		$this->assertArrayHasKey( 'total', $data, 'Response should have total key.' );

		// Verify defaults are applied.
		$this->assertEquals( 1, $data['page'], 'Default page should be 1.' );
		$this->assertEquals( 20, $data['per_page'], 'Default per_page should be 20.' );
		$this->assertIsArray( $data['items'], 'Items should be an array.' );
		$this->assertGreaterThanOrEqual( 2, $data['total'], 'Should have at least 2 sites.' );
	}

	/**
	 * Test get-site-v1 ability via GET with input via query params.
	 *
	 * Note: Read-only abilities must use GET per Abilities API spec.
	 * Input is passed as a query param for GET requests.
	 *
	 * @return void
	 */
	public function test_get_site_via_rest_get_with_query_params() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		$site_id = $this->create_test_site( [ 'name' => 'Get Site Test' ] );

		// get-site-v1 is readonly, so it requires GET method with query params.
		$request = new WP_REST_Request( 'GET', $this->ability_run_url( 'mainwp/get-site-v1' ) );
		$this->set_ability_input_query_param( $request, [ 'site_id_or_domain' => $site_id ] );

		$response = rest_do_request( $request );

		$this->assertEquals(
			200,
			$response->get_status(),
			'get-site-v1 via GET with input should return 200 OK.'
		);

		$data = $response->get_data();
		$this->assertIsArray( $data, 'Response should be an array.' );
		$this->assertArrayHasKey( 'id', $data, 'Response should have id key.' );
		$this->assertArrayHasKey( 'url', $data, 'Response should have url key.' );
		$this->assertArrayHasKey( 'name', $data, 'Response should have name key.' );
		$this->assertArrayHasKey( 'status', $data, 'Response should have status key.' );
		$this->assertEquals( $site_id, $data['id'], 'Response should contain correct site ID.' );
		$this->assertEquals( 'Get Site Test', $data['name'], 'Response should contain correct site name.' );
	}

	/**
	 * Test get-site-plugins-v1 ability via GET with query params.
	 *
	 * Uses GET with query params since this is a read-only ability.
	 *
	 * @return void
	 */
	public function test_get_site_plugins_via_rest_get() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		$site_id = $this->create_test_site( [ 'name' => 'Plugins Test Site' ] );

		// Seed plugin data.
		$this->set_site_plugins(
			$site_id,
			[
				'akismet/akismet.php' => [
					'name'    => 'Akismet',
					'version' => '5.0',
					'active'  => 1,
				],
				'hello-dolly/hello.php' => [
					'name'    => 'Hello Dolly',
					'version' => '1.7.2',
					'active'  => 0,
				],
			]
		);

		// get-site-plugins-v1 is readonly, so it requires GET method.
		$request = new WP_REST_Request( 'GET', $this->ability_run_url( 'mainwp/get-site-plugins-v1' ) );
		$this->set_ability_input_query_param( $request, [ 'site_id_or_domain' => $site_id ] );

		$response = rest_do_request( $request );

		$this->assertEquals(
			200,
			$response->get_status(),
			'get-site-plugins-v1 should return 200 OK.'
		);

		$data = $response->get_data();
		$this->assertArrayHasKey( 'plugins', $data, 'Response should have plugins key.' );
		$this->assertArrayHasKey( 'total', $data, 'Response should have total key.' );
		$this->assertIsArray( $data['plugins'], 'Plugins should be an array.' );
		$this->assertEquals( 2, $data['total'], 'Should have 2 plugins.' );
	}

	/**
	 * Test get-site-themes-v1 ability via GET with query params.
	 *
	 * Uses GET with query params since this is a read-only ability.
	 *
	 * @return void
	 */
	public function test_get_site_themes_via_rest_get() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		$site_id = $this->create_test_site( [ 'name' => 'Themes Test Site' ] );

		// Seed theme data.
		$this->set_site_themes(
			$site_id,
			[
				'twentytwentyfour' => [
					'name'    => 'Twenty Twenty-Four',
					'version' => '1.0',
					'active'  => 1,
				],
				'twentytwentythree' => [
					'name'    => 'Twenty Twenty-Three',
					'version' => '1.2',
					'active'  => 0,
				],
			]
		);

		// get-site-themes-v1 is readonly, so it requires GET method.
		$request = new WP_REST_Request( 'GET', $this->ability_run_url( 'mainwp/get-site-themes-v1' ) );
		$this->set_ability_input_query_param( $request, [ 'site_id_or_domain' => $site_id ] );

		$response = rest_do_request( $request );

		$this->assertEquals(
			200,
			$response->get_status(),
			'get-site-themes-v1 should return 200 OK.'
		);

		$data = $response->get_data();
		$this->assertArrayHasKey( 'themes', $data, 'Response should have themes key.' );
		$this->assertArrayHasKey( 'active_theme', $data, 'Response should have active_theme key.' );
		$this->assertArrayHasKey( 'total', $data, 'Response should have total key.' );
		$this->assertIsArray( $data['themes'], 'Themes should be an array.' );
		$this->assertEquals( 2, $data['total'], 'Should have 2 themes.' );
		$this->assertEquals( 'twentytwentyfour', $data['active_theme'], 'Active theme should be twentytwentyfour.' );
	}

	/**
	 * Test list-updates-v1 ability via GET with no input.
	 *
	 * @return void
	 */
	public function test_list_updates_via_rest_get_no_input() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		$site_id = $this->create_test_site( [ 'name' => 'Updates Test Site' ] );

		// Seed plugin upgrade data.
		$this->set_site_plugin_upgrades(
			$site_id,
			[
				'akismet/akismet.php' => [
					'Name'       => 'Akismet',
					'Version'    => '5.0',
					'new_version' => '5.1',
				],
			]
		);

		// list-updates-v1 is readonly, so it requires GET method.
		$request = new WP_REST_Request( 'GET', $this->ability_run_url( 'mainwp/list-updates-v1' ) );
		$response = rest_do_request( $request );

		$this->assertEquals(
			200,
			$response->get_status(),
			'list-updates-v1 via GET should return 200 OK.'
		);

		$data = $response->get_data();
		$this->assertArrayHasKey( 'updates', $data, 'Response should have updates key.' );
		$this->assertArrayHasKey( 'summary', $data, 'Response should have summary key.' );
		$this->assertArrayHasKey( 'page', $data, 'Response should have page key.' );
		$this->assertArrayHasKey( 'per_page', $data, 'Response should have per_page key.' );
		$this->assertArrayHasKey( 'total', $data, 'Response should have total key.' );
		$this->assertIsArray( $data['updates'], 'Updates should be an array.' );
	}

	/**
	 * Test list-ignored-updates-v1 ability via GET.
	 *
	 * @return void
	 */
	public function test_list_ignored_updates_via_rest_get() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		$site_id = $this->create_test_site( [ 'name' => 'Ignored Updates Test Site' ] );

		// Seed ignored plugin data.
		$this->set_site_ignored_plugins(
			$site_id,
			[
				'akismet/akismet.php' => [
					'Name'             => 'Akismet',
					'ignored_versions' => [ 'all_versions' ],
				],
			]
		);

		// list-ignored-updates-v1 is readonly, so it requires GET method.
		$request = new WP_REST_Request( 'GET', $this->ability_run_url( 'mainwp/list-ignored-updates-v1' ) );
		$response = rest_do_request( $request );

		$this->assertEquals(
			200,
			$response->get_status(),
			'list-ignored-updates-v1 via GET should return 200 OK.'
		);

		$data = $response->get_data();
		$this->assertArrayHasKey( 'ignored', $data, 'Response should have ignored key.' );
		$this->assertArrayHasKey( 'total', $data, 'Response should have total key.' );
		$this->assertIsArray( $data['ignored'], 'Ignored should be an array.' );
	}

	// =========================================================================
	// Write Abilities Tests (POST requests)
	// =========================================================================

	/**
	 * Test sync-sites-v1 ability via POST with no input.
	 *
	 * When no input is provided, the ability syncs all sites.
	 *
	 * @return void
	 */
	public function test_sync_sites_via_rest_post_no_input() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		// Create test sites.
		$this->create_test_site( [ 'name' => 'Sync Test 1', 'offline_check_result' => 1 ] );
		$this->create_test_site( [ 'name' => 'Sync Test 2', 'offline_check_result' => 1 ] );

		$request = new WP_REST_Request( 'POST', $this->ability_run_url( 'mainwp/sync-sites-v1' ) );
		$response = rest_do_request( $request );

		// 200 or 207 are valid for sync operations.
		$this->assertContains(
			$response->get_status(),
			[ 200, 207 ],
			'sync-sites-v1 via POST should return 200 or 207.'
		);

		$data = $response->get_data();
		$this->assertIsArray( $data, 'Response should be an array.' );

		// Should have immediate execution fields (since < 50 sites).
		$this->assertArrayHasKey( 'synced', $data, 'Response should have synced key.' );
		$this->assertArrayHasKey( 'errors', $data, 'Response should have errors key.' );
		$this->assertArrayHasKey( 'total_synced', $data, 'Response should have total_synced key.' );
		$this->assertArrayHasKey( 'total_errors', $data, 'Response should have total_errors key.' );
	}

	/**
	 * Test sync-sites-v1 ability via POST with specific sites.
	 *
	 * @return void
	 */
	public function test_sync_sites_via_rest_post_with_input() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		$site1_id = $this->create_test_site( [ 'name' => 'Sync Specific 1', 'offline_check_result' => 1 ] );
		$site2_id = $this->create_test_site( [ 'name' => 'Sync Specific 2', 'offline_check_result' => 1 ] );

		// Not in the request: if the run endpoint ever ignores the input again,
		// the all-sites fallback drags this site into the response.
		$control_id = $this->create_test_site( [ 'name' => 'Sync Control', 'offline_check_result' => 1 ] );

		$request = new WP_REST_Request( 'POST', $this->ability_run_url( 'mainwp/sync-sites-v1' ) );
		// set_body_params() form input never reaches the run endpoint (it reads the
		// JSON body only), which silently turned this into an all-sites sync.
		$this->set_ability_input( $request, [
			'site_ids_or_domains' => [ $site1_id, $site2_id ],
		] );

		$response = rest_do_request( $request );

		$this->assertContains(
			$response->get_status(),
			[ 200, 207 ],
			'sync-sites-v1 with specific sites should return 200 or 207.'
		);

		$data = $response->get_data();
		$this->assertIsArray( $data, 'Response should be an array.' );
		$this->assertArrayHasKey( 'synced', $data, 'Response should have synced key.' );

		$touched_ids = array_merge(
			array_map( static fn( $entry ) => (int) $entry['id'], $data['synced'] ),
			array_map( static fn( $entry ) => (int) $entry['identifier'], $data['errors'] ?? [] )
		);
		$this->assertNotContains( $control_id, $touched_ids, 'Unrequested control site should not be touched.' );
	}

	/**
	 * Test sync-sites-v1 ability via POST triggers batch queuing for >50 sites.
	 *
	 * @return void
	 */
	public function test_sync_sites_via_rest_post_batch_queuing() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		// Lower threshold to 5 for efficient testing.
		$threshold_callback = function() { return 5; };
		add_filter( 'mainwp_abilities_batch_threshold', $threshold_callback );

		try {
			// Create sites to exceed batch threshold (lowered to 5 for testing).
			// The seventh site stays out of the request: if the run endpoint ever
			// ignores the input again, the all-sites fallback queues 7, not 6.
			$site_ids = [];
			for ( $i = 0; $i < 7; $i++ ) {
				$site_ids[] = $this->create_test_site( [
					'name'                 => "Batch Test Site {$i}",
					'offline_check_result' => 1,
				] );
			}
			$site_ids = array_slice( $site_ids, 0, 6 );

			$request = new WP_REST_Request( 'POST', $this->ability_run_url( 'mainwp/sync-sites-v1' ) );
			// set_body_params() form input never reaches the run endpoint (it reads the
			// JSON body only), which silently turned this into an all-sites sync.
			$this->set_ability_input( $request, [
				'site_ids_or_domains' => $site_ids,
			] );

			$response = rest_do_request( $request );

			// Should return 200 or 202 for queued operations.
			$this->assertContains(
				$response->get_status(),
				[ 200, 202 ],
				'sync-sites-v1 above threshold should return 200 or 202 (queued).'
			);

			$data = $response->get_data();
			$this->assertIsArray( $data, 'Response should be an array.' );

			// Verify queued response fields.
			$this->assertArrayHasKey( 'queued', $data, 'Response should have queued key.' );
			$this->assertTrue( $data['queued'], 'queued should be true for sites above threshold.' );
			$this->assertArrayHasKey( 'job_id', $data, 'Response should have job_id key.' );
			$this->assertArrayHasKey( 'status_url', $data, 'Response should have status_url key.' );
			$this->assertArrayHasKey( 'sites_queued', $data, 'Response should have sites_queued key.' );
			$this->assertEquals( 6, $data['sites_queued'], 'sites_queued should be 6.' );
		} finally {
			remove_filter( 'mainwp_abilities_batch_threshold', $threshold_callback );
		}
	}

	/**
	 * Test run-updates-v1 ability via POST.
	 *
	 * @return void
	 */
	public function test_run_updates_via_rest_post() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Run Updates Test',
			'offline_check_result' => 1,
		] );

		// Seed plugin upgrade data.
		$this->set_site_plugin_upgrades(
			$site_id,
			[
				'akismet/akismet.php' => [
					'Name'        => 'Akismet',
					'Version'     => '5.0',
					'new_version' => '5.1',
				],
			]
		);

		$request = new WP_REST_Request( 'POST', $this->ability_run_url( 'mainwp/run-updates-v1' ) );
		$response = rest_do_request( $request );

		$this->assertEquals(
			200,
			$response->get_status(),
			'run-updates-v1 via POST should return 200 OK.'
		);

		$data = $response->get_data();
		$this->assertIsArray( $data, 'Response should be an array.' );

		// Immediate execution response.
		$this->assertArrayHasKey( 'updated', $data, 'Response should have updated key.' );
		$this->assertArrayHasKey( 'errors', $data, 'Response should have errors key.' );
		$this->assertArrayHasKey( 'summary', $data, 'Response should have summary key.' );
	}

	/**
	 * Test set-ignored-updates-v1 ability via POST.
	 *
	 * @return void
	 */
	public function test_set_ignored_updates_via_rest_post() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		$site_id = $this->create_test_site( [ 'name' => 'Set Ignored Test' ] );

		$request = new WP_REST_Request( 'POST', $this->ability_run_url( 'mainwp/set-ignored-updates-v1' ) );
		// Abilities API POST requests require JSON body with {"input": {...}}.
		// Use set_header + set_body (not set_body_params which is for form data).
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [
			'input' => [
				'action'            => 'ignore',
				'site_id_or_domain' => $site_id,
				'type'              => 'plugin',
				'slug'              => 'akismet/akismet.php',
			],
		] ) );

		$response = rest_do_request( $request );

		$this->assertEquals(
			200,
			$response->get_status(),
			'set-ignored-updates-v1 via POST should return 200 OK.'
		);

		$data = $response->get_data();
		$this->assertArrayHasKey( 'success', $data, 'Response should have success key.' );
		$this->assertTrue( $data['success'], 'success should be true.' );
		$this->assertArrayHasKey( 'action', $data, 'Response should have action key.' );
		$this->assertEquals( 'ignore', $data['action'], 'action should be ignore.' );
		$this->assertArrayHasKey( 'site_id', $data, 'Response should have site_id key.' );
		$this->assertEquals( $site_id, $data['site_id'], 'site_id should match.' );
	}

	// =========================================================================
	// HTTP Method Validation Tests
	// =========================================================================

	/**
	 * Test that write abilities reject GET method.
	 *
	 * Write abilities (readonly: false) require POST. Using GET should return 405.
	 *
	 * @return void
	 */
	public function test_mainwp_abilities_reject_get_method() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		// sync-sites-v1 has readonly: false, so GET should fail.
		$request = new WP_REST_Request( 'GET', $this->ability_run_url( 'mainwp/sync-sites-v1' ) );
		$response = rest_do_request( $request );

		$this->assertEquals(
			405,
			$response->get_status(),
			'GET to write ability (readonly: false) should return 405 Method Not Allowed.'
		);

		$data = $response->get_data();
		$this->assertArrayHasKey( 'code', $data, 'Error response should have code key.' );
		$this->assertEquals(
			'rest_ability_invalid_method',
			$data['code'],
			'Error code should be rest_ability_invalid_method.'
		);
	}

	/**
	 * Test that read-only abilities reject POST method.
	 *
	 * Read-only abilities (readonly: true) require GET. Using POST should return 405.
	 *
	 * @return void
	 */
	public function test_mainwp_abilities_accept_post_method() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		// list-sites-v1 has readonly: true, so POST should fail with 405.
		$request = new WP_REST_Request( 'POST', $this->ability_run_url( 'mainwp/list-sites-v1' ) );
		$response = rest_do_request( $request );

		$this->assertEquals(
			405,
			$response->get_status(),
			'POST to read-only ability (readonly: true) should return 405 Method Not Allowed.'
		);

		$data = $response->get_data();
		$this->assertArrayHasKey( 'code', $data, 'Error response should have code key.' );
		$this->assertEquals(
			'rest_ability_invalid_method',
			$data['code'],
			'Error code should be rest_ability_invalid_method.'
		);
	}

	// =========================================================================
	// Authentication Tests
	// =========================================================================

	/**
	 * Test that ability requires authentication.
	 *
	 * @return void
	 */
	public function test_rest_ability_requires_authentication() {
		$this->skip_if_no_abilities_api();

		// Set current user to 0 (unauthenticated).
		wp_set_current_user( 0 );

		// list-sites-v1 is readonly, so it requires GET method.
		$request = new WP_REST_Request( 'GET', $this->ability_run_url( 'mainwp/list-sites-v1' ) );
		$response = rest_do_request( $request );

		// Should return 401 or 403 for unauthenticated request.
		$this->assertContains(
			$response->get_status(),
			[ 401, 403 ],
			'Unauthenticated request should return 401 or 403.'
		);
	}

	/**
	 * Test that subscriber user is denied access.
	 *
	 * @return void
	 */
	public function test_rest_ability_permission_denied_for_subscriber() {
		$this->skip_if_no_abilities_api();

		// Create subscriber user.
		$subscriber_id = $this->factory->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		// list-sites-v1 is readonly, so it requires GET method.
		$request = new WP_REST_Request( 'GET', $this->ability_run_url( 'mainwp/list-sites-v1' ) );
		$response = rest_do_request( $request );

		$this->assertEquals(
			403,
			$response->get_status(),
			'Subscriber should receive 403 Forbidden.'
		);

		$data = $response->get_data();
		$this->assertArrayHasKey( 'code', $data, 'Error response should have code key.' );
		// Check for permission-related error codes.
		// MainWP returns 'mainwp_permission_denied', Abilities API can return others.
		$this->assertContains(
			$data['code'],
			[ 'ability_invalid_permissions', 'rest_ability_cannot_execute', 'rest_forbidden', 'mainwp_permission_denied' ],
			'Error code should indicate permission denied.'
		);
	}

	// =========================================================================
	// Error Response Tests
	// =========================================================================

	/**
	 * Test that non-existent ability returns 404.
	 *
	 * @return void
	 */
	public function test_rest_ability_not_found() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		// Expect _doing_it_wrong notice when trying to fetch non-existent ability.
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::get_registered' );

		$request = new WP_REST_Request( 'POST', $this->ability_run_url( 'mainwp/nonexistent-v1' ) );
		$response = rest_do_request( $request );

		$this->assertEquals(
			404,
			$response->get_status(),
			'Non-existent ability should return 404 Not Found.'
		);

		$data = $response->get_data();
		$this->assertArrayHasKey( 'code', $data, 'Error response should have code key.' );
		$this->assertEquals(
			'rest_ability_not_found',
			$data['code'],
			'Error code should be rest_ability_not_found.'
		);
	}

	/**
	 * Test that get-site-v1 without required input returns error.
	 *
	 * The get-site-v1 ability requires site_id_or_domain input.
	 *
	 * @return void
	 */
	public function test_rest_ability_missing_required_input() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		// get-site-v1 is readonly, so it requires GET method.
		$request = new WP_REST_Request( 'GET', $this->ability_run_url( 'mainwp/get-site-v1' ) );
		// No input provided - should fail validation.
		$response = rest_do_request( $request );

		$this->assertEquals(
			400,
			$response->get_status(),
			'Missing required input should return 400 Bad Request.'
		);

		$data = $response->get_data();
		$this->assertArrayHasKey( 'code', $data, 'Error response should have code key.' );
		// Could be ability_invalid_input or ability_missing_input_schema.
		$this->assertContains(
			$data['code'],
			[ 'ability_invalid_input', 'ability_missing_input_schema' ],
			'Error code should indicate invalid/missing input.'
		);
	}

	/**
	 * Test that get-site-v1 with non-existent site returns 404.
	 *
	 * @return void
	 */
	public function test_rest_ability_site_not_found() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		// get-site-v1 is readonly, so it requires GET method with query params.
		$request = new WP_REST_Request( 'GET', $this->ability_run_url( 'mainwp/get-site-v1' ) );
		$this->set_ability_input_query_param( $request, [ 'site_id_or_domain' => 999999 ] );

		$response = rest_do_request( $request );

		// Could be 404 or 403 depending on permission callback order.
		$this->assertContains(
			$response->get_status(),
			[ 403, 404 ],
			'Non-existent site should return 403 or 404.'
		);

		$data = $response->get_data();
		$this->assertArrayHasKey( 'code', $data, 'Error response should have code key.' );
		$this->assertEquals(
			'mainwp_site_not_found',
			$data['code'],
			'Error code should be mainwp_site_not_found for non-existent site.'
		);
	}

	/**
	 * Test that error responses have proper structure.
	 *
	 * WordPress REST API error responses should have code, message, and data keys.
	 *
	 * @return void
	 */
	public function test_rest_ability_error_response_structure() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		// Abilities API triggers _doing_it_wrong() for non-existent abilities.
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::get_registered' );

		// Trigger an error by requesting non-existent ability.
		$request = new WP_REST_Request( 'POST', $this->ability_run_url( 'mainwp/nonexistent-v1' ) );
		$response = rest_do_request( $request );

		$data = $response->get_data();

		$this->assertArrayHasKey( 'code', $data, 'Error response should have code key.' );
		$this->assertArrayHasKey( 'message', $data, 'Error response should have message key.' );
		$this->assertArrayHasKey( 'data', $data, 'Error response should have data key.' );
		$this->assertArrayHasKey( 'status', $data['data'], 'Error data should have status key.' );

		$this->assertIsString( $data['code'], 'code should be a string.' );
		$this->assertIsString( $data['message'], 'message should be a string.' );
		$this->assertEquals( 404, $data['data']['status'], 'status should match HTTP status.' );
	}

	// =========================================================================
	// Input Handling Tests
	// =========================================================================

	/**
	 * Test that POST with empty input object uses defaults.
	 *
	 * For abilities with all-optional params, empty input {} should work.
	 *
	 * @return void
	 */
	public function test_rest_ability_post_with_empty_input_object() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		$this->create_test_site( [ 'name' => 'Empty Input Test' ] );

		// sync-sites-v1 has all optional params - empty object uses defaults.
		$request = new WP_REST_Request( 'POST', $this->ability_run_url( 'mainwp/sync-sites-v1' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'input' => new \stdClass() ] ) );

		$response = rest_do_request( $request );

		$this->assertContains(
			$response->get_status(),
			[ 200, 207 ],
			'Empty input object should use defaults and succeed.'
		);
	}

	/**
	 * Test that POST with partial input applies defaults for missing params.
	 *
	 * @return void
	 */
	public function test_rest_ability_post_with_partial_input() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		$this->create_test_site( [ 'name' => 'Partial Input Test' ] );

		// list-updates-v1 with only page param, missing per_page and types.
		$request = new WP_REST_Request( 'POST', $this->ability_run_url( 'mainwp/run-updates-v1' ) );
		$request->set_body_params( [
			'input' => [
				'types' => [ 'plugins' ],
				// site_ids_or_domains defaults to all sites.
			],
		] );

		$response = rest_do_request( $request );

		$this->assertEquals(
			200,
			$response->get_status(),
			'Partial input should apply defaults for missing params.'
		);
	}

	/**
	 * Test that invalid input type (string instead of object) returns error.
	 *
	 * When input is passed as a plain string instead of an object/array,
	 * the Abilities API should return a 400 error with ability_invalid_input code.
	 *
	 * @return void
	 */
	public function test_rest_ability_invalid_input_type_returns_error() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		// POST to sync-sites-v1 with input as plain string instead of object.
		$request = new WP_REST_Request( 'POST', $this->ability_run_url( 'mainwp/sync-sites-v1' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'input' => 'not-an-object' ] ) );

		$response = rest_do_request( $request );

		$this->assertEquals(
			400,
			$response->get_status(),
			'Invalid input type should return 400 Bad Request.'
		);

		$data = $response->get_data();
		$this->assertArrayHasKey( 'code', $data, 'Error response should have code key.' );
		$this->assertEquals(
			'ability_invalid_input',
			$data['code'],
			'Error code should be ability_invalid_input.'
		);
	}

	/**
	 * Test that empty input query param on GET uses defaults.
	 *
	 * Passing an explicit empty input parameter should behave the same
	 * as omitting the input parameter entirely (use schema defaults).
	 *
	 * @return void
	 */
	public function test_rest_ability_empty_input_query_param_uses_defaults() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		// Create a site to ensure we have data.
		$this->create_test_site( [ 'name' => 'Empty Input Param Test' ] );

		// list-sites-v1 is readonly, so it requires GET method.
		// Testing empty input query param.
		$request = new WP_REST_Request( 'GET', $this->ability_run_url( 'mainwp/list-sites-v1' ) );
		$request->set_query_params( [ 'input' => '' ] );

		$response = rest_do_request( $request );

		// Should succeed and use defaults (same as no input).
		$this->assertEquals(
			200,
			$response->get_status(),
			'Empty input query param should use defaults and return 200 OK.'
		);

		$data = $response->get_data();
		$this->assertArrayHasKey( 'items', $data, 'Response should have items key.' );
		$this->assertArrayHasKey( 'page', $data, 'Response should have page key.' );
		$this->assertEquals( 1, $data['page'], 'Default page should be 1.' );
		$this->assertEquals( 20, $data['per_page'], 'Default per_page should be 20.' );
	}

	// =========================================================================
	// Response Structure Validation Tests
	// =========================================================================

	/**
	 * Test that list-sites-v1 response matches output schema.
	 *
	 * @return void
	 */
	public function test_list_sites_response_matches_output_schema() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		$site_id = $this->create_test_site( [ 'name' => 'Schema Test Site' ] );

		// list-sites-v1 is readonly, so it requires GET method.
		$request = new WP_REST_Request( 'GET', $this->ability_run_url( 'mainwp/list-sites-v1' ) );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();

		// Required fields per output schema.
		$this->assertArrayHasKey( 'items', $data );
		$this->assertArrayHasKey( 'page', $data );
		$this->assertArrayHasKey( 'per_page', $data );
		$this->assertArrayHasKey( 'total', $data );

		// Verify field types.
		$this->assertIsArray( $data['items'] );
		$this->assertIsInt( $data['page'] );
		$this->assertIsInt( $data['per_page'] );
		$this->assertIsInt( $data['total'] );

		// Verify items structure and that created site is in response.
		$this->assertNotEmpty( $data['items'], 'Items should not be empty after creating a test site' );

		$item = $data['items'][0];
		$this->assertArrayHasKey( 'id', $item );
		$this->assertArrayHasKey( 'url', $item );
		$this->assertArrayHasKey( 'name', $item );
		$this->assertArrayHasKey( 'status', $item );

		// Verify the created site appears in the response.
		$site_ids = array_column( $data['items'], 'id' );
		$this->assertContains( $site_id, $site_ids, 'Created test site should appear in list-sites-v1 response' );
	}

	/**
	 * Test that list-updates-v1 response matches output schema.
	 *
	 * @return void
	 */
	public function test_list_updates_response_matches_output_schema() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		$site_id = $this->create_test_site( [ 'name' => 'Updates Schema Test' ] );
		$this->set_site_plugin_upgrades(
			$site_id,
			[
				'test-plugin/test-plugin.php' => [
					'Name'        => 'Test Plugin',
					'Version'     => '1.0',
					'new_version' => '2.0',
				],
			]
		);

		// list-updates-v1 is readonly, so it requires GET method.
		$request = new WP_REST_Request( 'GET', $this->ability_run_url( 'mainwp/list-updates-v1' ) );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();

		// Required fields per output schema.
		$this->assertArrayHasKey( 'updates', $data );
		$this->assertArrayHasKey( 'summary', $data );
		$this->assertArrayHasKey( 'page', $data );
		$this->assertArrayHasKey( 'per_page', $data );
		$this->assertArrayHasKey( 'total', $data );

		// Verify summary structure.
		$summary = $data['summary'];
		$this->assertArrayHasKey( 'core', $summary );
		$this->assertArrayHasKey( 'plugins', $summary );
		$this->assertArrayHasKey( 'themes', $summary );
		$this->assertArrayHasKey( 'translations', $summary );
		$this->assertArrayHasKey( 'total', $summary );

		// Verify updates item structure if present.
		if ( ! empty( $data['updates'] ) ) {
			$update = $data['updates'][0];
			$this->assertArrayHasKey( 'site_id', $update );
			$this->assertArrayHasKey( 'site_url', $update );
			$this->assertArrayHasKey( 'site_name', $update );
			$this->assertArrayHasKey( 'type', $update );
			$this->assertArrayHasKey( 'slug', $update );
			$this->assertArrayHasKey( 'name', $update );
			$this->assertArrayHasKey( 'current_version', $update );
			$this->assertArrayHasKey( 'new_version', $update );
		}
	}

	/**
	 * Test that sync-sites-v1 immediate response matches output schema.
	 *
	 * @return void
	 */
	public function test_sync_sites_immediate_response_matches_output_schema() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Sync Schema Test',
			'offline_check_result' => 1,
		] );

		$request = new WP_REST_Request( 'POST', $this->ability_run_url( 'mainwp/sync-sites-v1' ) );
		$request->set_body_params( [
			'input' => [
				'site_ids_or_domains' => [ $site_id ],
			],
		] );

		$response = rest_do_request( $request );

		$this->assertContains(
			$response->get_status(),
			[ 200, 207 ]
		);

		$data = $response->get_data();

		// Immediate execution fields.
		$this->assertArrayHasKey( 'synced', $data, 'Response should have synced key.' );
		$this->assertArrayHasKey( 'errors', $data, 'Response should have errors key.' );
		$this->assertArrayHasKey( 'total_synced', $data, 'Response should have total_synced key.' );
		$this->assertArrayHasKey( 'total_errors', $data, 'Response should have total_errors key.' );

		$this->assertIsArray( $data['synced'] );
		$this->assertIsArray( $data['errors'] );
		$this->assertIsInt( $data['total_synced'] );
		$this->assertIsInt( $data['total_errors'] );
	}

	/**
	 * Test that get-site-v1 response matches output schema.
	 *
	 * @return void
	 */
	public function test_get_site_response_matches_output_schema() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		$site_id = $this->create_test_site( [
			'name'    => 'Get Site Schema Test',
			'version' => '6.4.0',
		] );

		// get-site-v1 is readonly, so it requires GET method with query params.
		$request = new WP_REST_Request( 'GET', $this->ability_run_url( 'mainwp/get-site-v1' ) );
		$this->set_ability_input_query_param( $request, [ 'site_id_or_domain' => $site_id ] );

		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status(), 'get-site-v1 should return 200 OK.' );

		$data = $response->get_data();

		// Required fields per output schema.
		$this->assertArrayHasKey( 'id', $data, 'Response should have id key.' );
		$this->assertArrayHasKey( 'url', $data, 'Response should have url key.' );
		$this->assertArrayHasKey( 'name', $data, 'Response should have name key.' );
		$this->assertArrayHasKey( 'status', $data, 'Response should have status key.' );

		// Verify field types.
		$this->assertIsInt( $data['id'], 'id should be an integer.' );
		$this->assertIsString( $data['url'], 'url should be a string.' );
		$this->assertIsString( $data['name'], 'name should be a string.' );
		$this->assertIsString( $data['status'], 'status should be a string.' );

		// Verify correct values.
		$this->assertEquals( $site_id, $data['id'], 'id should match the created site.' );
		$this->assertEquals( 'Get Site Schema Test', $data['name'], 'name should match.' );
	}

	/**
	 * Test that get-site-plugins-v1 response matches output schema.
	 *
	 * @return void
	 */
	public function test_get_site_plugins_response_matches_output_schema() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		$site_id = $this->create_test_site( [ 'name' => 'Plugins Schema Test' ] );

		// Seed plugin data.
		$this->set_site_plugins(
			$site_id,
			[
				'akismet/akismet.php' => [
					'name'    => 'Akismet Anti-spam',
					'version' => '5.3',
					'active'  => 1,
				],
				'hello-dolly/hello.php' => [
					'name'    => 'Hello Dolly',
					'version' => '1.7.2',
					'active'  => 0,
				],
			]
		);

		// get-site-plugins-v1 is readonly, so it requires GET method with query params.
		$request = new WP_REST_Request( 'GET', $this->ability_run_url( 'mainwp/get-site-plugins-v1' ) );
		$this->set_ability_input_query_param( $request, [ 'site_id_or_domain' => $site_id ] );

		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status(), 'get-site-plugins-v1 should return 200 OK.' );

		$data = $response->get_data();

		// Required fields per output schema.
		$this->assertArrayHasKey( 'plugins', $data, 'Response should have plugins key.' );
		$this->assertArrayHasKey( 'total', $data, 'Response should have total key.' );

		// Verify field types.
		$this->assertIsArray( $data['plugins'], 'plugins should be an array.' );
		$this->assertIsInt( $data['total'], 'total should be an integer.' );
		$this->assertEquals( 2, $data['total'], 'total should be 2.' );

		// Verify per-plugin structure if non-empty.
		if ( ! empty( $data['plugins'] ) ) {
			$plugin = $data['plugins'][0];
			$this->assertArrayHasKey( 'slug', $plugin, 'Plugin should have slug key.' );
			$this->assertArrayHasKey( 'name', $plugin, 'Plugin should have name key.' );
			$this->assertArrayHasKey( 'version', $plugin, 'Plugin should have version key.' );
			$this->assertArrayHasKey( 'active', $plugin, 'Plugin should have active key.' );

			$this->assertIsString( $plugin['slug'], 'Plugin slug should be a string.' );
			$this->assertIsString( $plugin['name'], 'Plugin name should be a string.' );
			$this->assertIsString( $plugin['version'], 'Plugin version should be a string.' );
			$this->assertIsBool( $plugin['active'], 'Plugin active should be a boolean.' );
		}
	}

	/**
	 * Test that get-site-themes-v1 response matches output schema.
	 *
	 * @return void
	 */
	public function test_get_site_themes_response_matches_output_schema() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		$site_id = $this->create_test_site( [ 'name' => 'Themes Schema Test' ] );

		// Seed theme data.
		$this->set_site_themes(
			$site_id,
			[
				'twentytwentyfour' => [
					'name'    => 'Twenty Twenty-Four',
					'version' => '1.0',
					'active'  => 1,
				],
				'twentytwentythree' => [
					'name'    => 'Twenty Twenty-Three',
					'version' => '1.2',
					'active'  => 0,
				],
			]
		);

		// get-site-themes-v1 is readonly, so it requires GET method with query params.
		$request = new WP_REST_Request( 'GET', $this->ability_run_url( 'mainwp/get-site-themes-v1' ) );
		$this->set_ability_input_query_param( $request, [ 'site_id_or_domain' => $site_id ] );

		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status(), 'get-site-themes-v1 should return 200 OK.' );

		$data = $response->get_data();

		// Required fields per output schema.
		$this->assertArrayHasKey( 'themes', $data, 'Response should have themes key.' );
		$this->assertArrayHasKey( 'active_theme', $data, 'Response should have active_theme key.' );
		$this->assertArrayHasKey( 'total', $data, 'Response should have total key.' );

		// Verify field types.
		$this->assertIsArray( $data['themes'], 'themes should be an array.' );
		$this->assertIsString( $data['active_theme'], 'active_theme should be a string.' );
		$this->assertIsInt( $data['total'], 'total should be an integer.' );
		$this->assertEquals( 2, $data['total'], 'total should be 2.' );
		$this->assertEquals( 'twentytwentyfour', $data['active_theme'], 'active_theme should be twentytwentyfour.' );

		// Verify per-theme structure if non-empty.
		if ( ! empty( $data['themes'] ) ) {
			$theme = $data['themes'][0];
			$this->assertArrayHasKey( 'slug', $theme, 'Theme should have slug key.' );
			$this->assertArrayHasKey( 'name', $theme, 'Theme should have name key.' );
			$this->assertArrayHasKey( 'version', $theme, 'Theme should have version key.' );
			$this->assertArrayHasKey( 'active', $theme, 'Theme should have active key.' );

			$this->assertIsString( $theme['slug'], 'Theme slug should be a string.' );
			$this->assertIsString( $theme['name'], 'Theme name should be a string.' );
			$this->assertIsString( $theme['version'], 'Theme version should be a string.' );
			$this->assertIsBool( $theme['active'], 'Theme active should be a boolean.' );
		}
	}

	/**
	 * Test that run-updates-v1 response matches output schema.
	 *
	 * @return void
	 */
	public function test_run_updates_response_matches_output_schema() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		$this->create_test_site( [
			'name'                 => 'Run Updates Schema Test',
			'offline_check_result' => 1,
		] );

		$request = new WP_REST_Request( 'POST', $this->ability_run_url( 'mainwp/run-updates-v1' ) );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status(), 'run-updates-v1 should return 200 OK.' );

		$data = $response->get_data();

		// Required fields per output schema.
		$this->assertArrayHasKey( 'updated', $data, 'Response should have updated key.' );
		$this->assertArrayHasKey( 'errors', $data, 'Response should have errors key.' );
		$this->assertArrayHasKey( 'summary', $data, 'Response should have summary key.' );

		// Verify field types.
		$this->assertIsArray( $data['updated'], 'updated should be an array.' );
		$this->assertIsArray( $data['errors'], 'errors should be an array.' );
		$this->assertIsArray( $data['summary'], 'summary should be an array.' );
	}

	/**
	 * Test that list-ignored-updates-v1 response matches output schema.
	 *
	 * @return void
	 */
	public function test_list_ignored_updates_response_matches_output_schema() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		$site_id = $this->create_test_site( [ 'name' => 'Ignored Updates Schema Test' ] );

		// Seed ignored plugin data.
		$this->set_site_ignored_plugins(
			$site_id,
			[
				'akismet/akismet.php' => [
					'Name' => 'Akismet',
				],
			]
		);

		// list-ignored-updates-v1 is readonly, so it requires GET method.
		$request = new WP_REST_Request( 'GET', $this->ability_run_url( 'mainwp/list-ignored-updates-v1' ) );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status(), 'list-ignored-updates-v1 should return 200 OK.' );

		$data = $response->get_data();

		// Required fields per output schema.
		$this->assertArrayHasKey( 'ignored', $data, 'Response should have ignored key.' );
		$this->assertArrayHasKey( 'total', $data, 'Response should have total key.' );

		// Verify field types.
		$this->assertIsArray( $data['ignored'], 'ignored should be an array.' );
		$this->assertIsInt( $data['total'], 'total should be an integer.' );

		// Verify per-item structure if non-empty.
		if ( ! empty( $data['ignored'] ) ) {
			$item = $data['ignored'][0];
			$this->assertArrayHasKey( 'site_id', $item, 'Ignored item should have site_id key.' );
			$this->assertArrayHasKey( 'type', $item, 'Ignored item should have type key.' );
			$this->assertArrayHasKey( 'slug', $item, 'Ignored item should have slug key.' );
			$this->assertArrayHasKey( 'name', $item, 'Ignored item should have name key.' );

			$this->assertIsInt( $item['site_id'], 'site_id should be an integer.' );
			$this->assertIsString( $item['type'], 'type should be a string.' );
			$this->assertIsString( $item['slug'], 'slug should be a string.' );
			$this->assertIsString( $item['name'], 'name should be a string.' );
		}
	}

	/**
	 * Test that set-ignored-updates-v1 response matches output schema.
	 *
	 * @return void
	 */
	public function test_set_ignored_updates_response_matches_output_schema() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		$site_id = $this->create_test_site( [ 'name' => 'Set Ignored Schema Test' ] );

		$request = new WP_REST_Request( 'POST', $this->ability_run_url( 'mainwp/set-ignored-updates-v1' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( [
			'input' => [
				'action'            => 'ignore',
				'site_id_or_domain' => $site_id,
				'type'              => 'plugin',
				'slug'              => 'test-plugin/test-plugin.php',
			],
		] ) );

		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status(), 'set-ignored-updates-v1 should return 200 OK.' );

		$data = $response->get_data();

		// Required fields per output schema.
		$this->assertArrayHasKey( 'success', $data, 'Response should have success key.' );
		$this->assertArrayHasKey( 'action', $data, 'Response should have action key.' );
		$this->assertArrayHasKey( 'site_id', $data, 'Response should have site_id key.' );
		$this->assertArrayHasKey( 'type', $data, 'Response should have type key.' );
		$this->assertArrayHasKey( 'slug', $data, 'Response should have slug key.' );

		// Verify field types.
		$this->assertIsBool( $data['success'], 'success should be a boolean.' );
		$this->assertIsString( $data['action'], 'action should be a string.' );
		$this->assertIsInt( $data['site_id'], 'site_id should be an integer.' );
		$this->assertIsString( $data['type'], 'type should be a string.' );
		$this->assertIsString( $data['slug'], 'slug should be a string.' );

		// Verify correct values.
		$this->assertTrue( $data['success'], 'success should be true.' );
		$this->assertEquals( 'ignore', $data['action'], 'action should be ignore.' );
		$this->assertEquals( $site_id, $data['site_id'], 'site_id should match.' );
		$this->assertEquals( 'plugin', $data['type'], 'type should be plugin.' );
		$this->assertEquals( 'test-plugin/test-plugin.php', $data['slug'], 'slug should match.' );
	}

	// =========================================================================
	// Content Type Tests
	// =========================================================================

	/**
	 * Test that ability response is JSON-serializable.
	 *
	 * @return void
	 */
	public function test_rest_ability_response_is_json() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		// list-sites-v1 is readonly, so it requires GET method.
		$request = new WP_REST_Request( 'GET', $this->ability_run_url( 'mainwp/list-sites-v1' ) );
		$response = rest_do_request( $request );

		$data = $response->get_data();

		// Response should be array or object (JSON-serializable).
		$this->assertTrue(
			is_array( $data ) || is_object( $data ),
			'Response should be JSON-serializable (array or object).'
		);

		// Verify it can be encoded to JSON.
		$json = wp_json_encode( $data );
		$this->assertNotFalse( $json, 'Response should encode to valid JSON.' );
	}

	// =========================================================================
	// Edge Case Tests
	// =========================================================================

	/**
	 * Test get-site-v1 with domain string instead of ID.
	 *
	 * @return void
	 */
	public function test_get_site_via_domain_string() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		$site_id = $this->create_test_site( [
			'name' => 'Domain Test Site',
			'url'  => 'https://test-domain-lookup.example.com/',
		] );

		// Use GET with query params (read-only ability).
		$request = new WP_REST_Request( 'GET', $this->ability_run_url( 'mainwp/get-site-v1' ) );
		$this->set_ability_input_query_param( $request, [ 'site_id_or_domain' => 'test-domain-lookup.example.com' ] );

		$response = rest_do_request( $request );

		$this->assertEquals(
			200,
			$response->get_status(),
			'get-site-v1 with domain string should return 200 OK.'
		);

		$data = $response->get_data();
		$this->assertEquals( $site_id, $data['id'], 'Should resolve to correct site ID.' );
	}

	/**
	 * Test list-sites-v1 with status filter.
	 *
	 * Uses GET with query params since this is a read-only ability.
	 *
	 * @return void
	 */
	public function test_list_sites_with_status_filter() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		// Create sites with different statuses.
		// Note: DB status filter uses sync_errors column, not offline_check_result.
		// - connected: sync_errors = ''
		// - disconnected: sync_errors != ''
		$this->create_test_site( [
			'name'                 => 'Connected Site',
			'offline_check_result' => 1,
			'suspended'            => 0,
			'sync_errors'          => '',  // Empty = connected.
		] );
		$this->create_test_site( [
			'name'                 => 'Disconnected Site',
			'offline_check_result' => 1,
			'suspended'            => 0,
			'sync_errors'          => 'Connection failed',  // Non-empty = disconnected.
		] );
		$this->create_test_site( [
			'name'        => 'Suspended Site',
			'suspended'   => 1,
			'sync_errors' => '',
		] );

		// Filter for connected only (GET with query params for readonly ability).
		$request = new WP_REST_Request( 'GET', $this->ability_run_url( 'mainwp/list-sites-v1' ) );
		$this->set_ability_input_query_param( $request, [ 'status' => 'connected' ] );

		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();

		// All returned sites should be connected.
		foreach ( $data['items'] as $item ) {
			$this->assertEquals(
				'connected',
				$item['status'],
				'Filtered sites should all have connected status.'
			);
		}
	}

	/**
	 * Test sync with offline site returns appropriate error.
	 *
	 * @return void
	 */
	public function test_sync_offline_site_returns_error() {
		$this->skip_if_no_abilities_api();
		$this->authenticate_as_admin();

		$site_id = $this->create_test_site( [
			'name'                 => 'Offline Site',
			'offline_check_result' => -1, // -1 = offline.
		] );

		// Not in the request: if the run endpoint ever ignores the input again,
		// the all-sites fallback drags this site into the response.
		$this->create_test_site( [
			'name'                 => 'Online Control Site',
			'offline_check_result' => 1,
		] );

		$request = new WP_REST_Request( 'POST', $this->ability_run_url( 'mainwp/sync-sites-v1' ) );
		// set_body_params() form input never reaches the run endpoint (it reads the
		// JSON body only), which silently turned this into an all-sites sync.
		$this->set_ability_input( $request, [
			'site_ids_or_domains' => [ $site_id ],
		] );

		$response = rest_do_request( $request );

		// Should still succeed but with error for the offline site.
		$this->assertContains(
			$response->get_status(),
			[ 200, 207 ]
		);

		$data = $response->get_data();

		// Site should appear in errors, not synced.
		$this->assertEmpty( $data['synced'], 'Offline site should not be in synced.' );
		$this->assertCount( 1, $data['errors'], 'Only the requested offline site should be in errors.' );
		$this->assertEquals(
			'mainwp_site_offline',
			$data['errors'][0]['code'],
			'Error code should be mainwp_site_offline.'
		);
	}
}
