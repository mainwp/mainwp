<?php
/**
 * MainWP REST v2 Cleanup Round 4 Tests
 *
 * Covers the backup progress unique-index migration, the shared request body
 * reader on the API keys routes, and the typing of the extra /sites/add args.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use WP_REST_Request;
use WP_REST_Server;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- test classes use PSR-style Test_* filenames, not the mainwp-* convention.

/**
 * Class Test_REST_V2_Cleanup_Round_4
 */
class Test_REST_V2_Cleanup_Round_4 extends \WP_Test_REST_TestCase {

	/**
	 * A value with the backslash shapes WordPress REST delivers unslashed.
	 *
	 * @var string
	 */
	const BACKSLASH_VALUE = 'C:\\Temp\\O\'Brien';

	/**
	 * Task id used by the backup progress rows this class inserts.
	 *
	 * @var int
	 */
	const PROGRESS_TASK_ID = 987654;

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
	 * Mirrors Test_REST_V2_Cleanup_Round_3: the MainWP REST server singleton
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

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}mainwp_api_keys WHERE description IN (%s, %s)", 'Test API Key', self::BACKSLASH_VALUE ) );

		$suppress = $wpdb->suppress_errors();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}mainwp_wp_backup_progress WHERE task_id = %d", self::PROGRESS_TASK_ID ) );
		$this->drop_progress_key( 'task_id' );
		$this->drop_progress_key( 'task_wp' );
		$wpdb->suppress_errors( $suppress );

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
			'key_id'          => (int) $wpdb->insert_id,
		];
	}

	/**
	 * Make an authenticated REST request.
	 *
	 * @param string $method      HTTP method.
	 * @param string $route       REST route.
	 * @param array  $json_body   Body payload, encoded as JSON.
	 * @param array  $body_params Form body parameters, as WP_REST_Server sets them from an unslashed $_POST.
	 * @param string $raw_body    Raw body with no content type, left for core's own parsing.
	 * @param array  $query_params Query-string parameters, as core sets them from $_GET.
	 * @return \WP_REST_Response Response object.
	 */
	protected function do_authenticated_request( string $method, string $route, array $json_body = [], array $body_params = [], string $raw_body = '', array $query_params = [] ): \WP_REST_Response {
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

		if ( ! empty( $query_params ) ) {
			$request->set_query_params( $query_params );
		}

		$response = rest_do_request( $request );

		$_GET    = $original_get;
		$_SERVER = $original_server;

		return $response;
	}

	/**
	 * Read the stored description of an API key row.
	 *
	 * @param int $key_id Key row id.
	 * @return string|null
	 */
	protected function get_key_description( int $key_id ): ?string {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( "SELECT description FROM {$wpdb->prefix}mainwp_api_keys WHERE key_id = %d", $key_id ) );
	}

	/**
	 * Read the stored enabled flag of an API key row.
	 *
	 * @param int $key_id Key row id.
	 * @return string|null
	 */
	protected function get_key_enabled( int $key_id ): ?string {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( "SELECT enabled FROM {$wpdb->prefix}mainwp_api_keys WHERE key_id = %d", $key_id ) );
	}

	/**
	 * Backup progress table name.
	 *
	 * @return string
	 */
	protected function progress_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mainwp_wp_backup_progress';
	}

	/**
	 * Column names of one index on the backup progress table.
	 *
	 * @param string $key_name Index name.
	 * @return array
	 */
	protected function progress_key_columns( string $key_name ): array {
		global $wpdb;
		$table = $this->progress_table();
		return $wpdb->get_col( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $key_name ), 4 );
	}

	/**
	 * Drop one index from the backup progress table, ignoring a missing key.
	 *
	 * @param string $key_name Index name.
	 * @return void
	 */
	protected function drop_progress_key( string $key_name ): void {
		global $wpdb;
		$table = $this->progress_table();
		if ( ! empty( $this->progress_key_columns( $key_name ) ) ) {
			$wpdb->query( "ALTER TABLE {$table} DROP INDEX `{$key_name}`" );
		}
	}

	/**
	 * Item 1: the migration drops a task_id-only unique key so two sites of one task can both insert.
	 */
	public function test_install_migration_drops_backup_progress_unique_index(): void {
		global $wpdb;
		$table = $this->progress_table();

		$this->drop_progress_key( 'task_id' );
		$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY task_id (task_id)" );
		$this->assertSame( [ 'task_id' ], $this->progress_key_columns( 'task_id' ), 'the stray unique key was not created' );

		$had_lookup_key = ! empty( $this->progress_key_columns( 'idx_task_id' ) );

		$repaired = \MainWP\Dashboard\MainWP_Install::instance()->drop_backup_progress_unique_index();

		$this->assertTrue( $repaired );
		$unique_on_task_id = $wpdb->get_col( "SHOW INDEX FROM {$table} WHERE Non_unique = 0 AND Column_name = 'task_id'", 2 );
		$this->assertSame( [], $unique_on_task_id );

		if ( $had_lookup_key ) {
			$this->assertSame( [ 'task_id' ], $this->progress_key_columns( 'idx_task_id' ), 'the non-unique lookup key must survive' );
		}

		$db = \MainWP\Dashboard\MainWP_DB_Backup::instance();
		$this->assertNotNull( $db->add_backup_task_progress( self::PROGRESS_TASK_ID, 1, [] ) );
		$this->assertNotNull( $db->add_backup_task_progress( self::PROGRESS_TASK_ID, 2, [] ) );
	}

	/**
	 * Item 1: a composite unique key over task_id and wp_id is legitimate and must survive.
	 */
	public function test_install_migration_keeps_composite_unique_index(): void {
		global $wpdb;
		$table = $this->progress_table();

		$this->drop_progress_key( 'task_wp' );
		$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY task_wp (task_id, wp_id)" );
		$this->assertSame( [ 'task_id', 'wp_id' ], $this->progress_key_columns( 'task_wp' ) );

		$repaired = \MainWP\Dashboard\MainWP_Install::instance()->drop_backup_progress_unique_index();

		$this->assertTrue( $repaired, 'nothing to drop still counts as repaired' );
		$this->assertSame( [ 'task_id', 'wp_id' ], $this->progress_key_columns( 'task_wp' ) );
	}

	/**
	 * Item 1: a repair that cannot confirm its result leaves a retry marker, and a
	 * later successful run clears it.
	 */
	public function test_install_repair_marker_tracks_unconfirmed_repair(): void {
		delete_site_option( \MainWP\Dashboard\MainWP_Install::BACKUP_PROGRESS_INDEX_REPAIR_PENDING );

		$failing = new class() extends \MainWP\Dashboard\MainWP_Install {
			// A lookup error is the shape a locked or unreadable table produces.
			protected function find_backup_progress_task_id_unique_keys() {
				return null;
			}
		};

		$this->assertFalse( $failing->repair_backup_progress_index() );
		$this->assertNotEmpty( get_site_option( \MainWP\Dashboard\MainWP_Install::BACKUP_PROGRESS_INDEX_REPAIR_PENDING ), 'an unconfirmed repair must leave the retry marker' );

		$this->assertTrue( \MainWP\Dashboard\MainWP_Install::instance()->repair_backup_progress_index() );
		$this->assertFalse( get_site_option( \MainWP\Dashboard\MainWP_Install::BACKUP_PROGRESS_INDEX_REPAIR_PENDING ), 'a confirmed repair must clear the retry marker' );
	}

	/**
	 * The repair retry runs at most once an hour while the marker is set.
	 */
	public function test_install_repair_retry_is_throttled(): void {
		delete_site_option( \MainWP\Dashboard\MainWP_Install::BACKUP_PROGRESS_INDEX_REPAIR_PENDING );

		$install = \MainWP\Dashboard\MainWP_Install::instance();

		$this->assertFalse( $install->maybe_retry_backup_progress_index_repair(), 'no marker means nothing to retry' );

		update_site_option( \MainWP\Dashboard\MainWP_Install::BACKUP_PROGRESS_INDEX_REPAIR_PENDING, time() );
		$this->assertFalse( $install->maybe_retry_backup_progress_index_repair(), 'a marker younger than an hour must not trigger another ALTER' );

		update_site_option( \MainWP\Dashboard\MainWP_Install::BACKUP_PROGRESS_INDEX_REPAIR_PENDING, time() - 2 * HOUR_IN_SECONDS );
		$this->assertTrue( $install->maybe_retry_backup_progress_index_repair() );
		$this->assertFalse( get_site_option( \MainWP\Dashboard\MainWP_Install::BACKUP_PROGRESS_INDEX_REPAIR_PENDING ), 'a confirmed retry must clear the marker' );
	}

	/**
	 * The permissions validator runs before the permission callback, so an array
	 * value must be rejected by type instead of reaching explode().
	 */
	public function test_api_keys_add_key_rejects_array_permissions(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/rest-api/add-key',
			[
				'active'      => 1,
				'permissions' => [ [ 'write' ] ],
				'description' => 'Test API Key',
			]
		);

		$this->assertSame( 400, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertSame( 'rest_invalid_param', $data['code'] );

		$target   = $this->create_rest_api_key( $this->admin_user_id, 'read' );
		$response = $this->do_authenticated_request(
			'PUT',
			'/mainwp/v2/rest-api/edit-key/' . $target['key_id'],
			[ 'permissions' => [ 'write' ] ]
		);

		$this->assertSame( 400, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertSame( 'rest_invalid_param', $data['code'] );
	}

	/**
	 * A form body casts an array to true, so active needs its declared type validated.
	 */
	public function test_api_keys_edit_key_rejects_array_active(): void {
		$this->authenticate_as_admin();
		$target = $this->create_rest_api_key( $this->admin_user_id, 'read' );

		$response = $this->do_authenticated_request(
			'PUT',
			'/mainwp/v2/rest-api/edit-key/' . $target['key_id'],
			[],
			[ 'active' => [ 'false' ] ]
		);

		$this->assertSame( 400, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertSame( 'rest_invalid_param', $data['code'] );
		$this->assertSame( '1', (string) $this->get_key_enabled( $target['key_id'] ) );
	}

	/**
	 * Core validates the required args from every bag, so a value that arrived
	 * on the query string must be the one the handler stores.
	 */
	public function test_api_keys_add_key_honours_query_permissions(): void {
		global $wpdb;
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/rest-api/add-key',
			[ 'active' => true, 'description' => 'Test API Key' ],
			[],
			'',
			[ 'permissions' => 'write' ]
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$permissions = $wpdb->get_var( $wpdb->prepare( "SELECT permissions FROM {$wpdb->prefix}mainwp_api_keys WHERE description = %s ORDER BY key_id DESC LIMIT 1", 'Test API Key' ) );
		$this->assertSame( 'write', $permissions );
	}

	/**
	 * Same for edit-key: a query-string active=0 next to a JSON description must disable the key.
	 */
	public function test_api_keys_edit_key_honours_query_active(): void {
		$this->authenticate_as_admin();
		$target = $this->create_rest_api_key( $this->admin_user_id, 'read' );

		$response = $this->do_authenticated_request(
			'PUT',
			'/mainwp/v2/rest-api/edit-key/' . $target['key_id'],
			[ 'description' => self::BACKSLASH_VALUE ],
			[],
			'',
			[ 'active' => '0' ]
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( '0', $this->get_key_enabled( $target['key_id'] ) );
		$this->assertSame( self::BACKSLASH_VALUE, $this->get_key_description( $target['key_id'] ) );
	}

	/**
	 * Item 2: POST /rest-api/add-key accepts a JSON body.
	 */
	public function test_api_keys_add_key_accepts_json_body(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/rest-api/add-key',
			[
				'description' => 'Test API Key',
				'permissions' => 'read',
				'active'      => 1,
			]
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertSame( 1, $data['success'] );
	}

	/**
	 * Item 2: PUT /rest-api/edit-key/{id} accepts a form-encoded body.
	 */
	public function test_api_keys_edit_key_accepts_form_body(): void {
		$this->authenticate_as_admin();
		$target = $this->create_rest_api_key( $this->admin_user_id, 'read' );

		$response = $this->do_authenticated_request(
			'PUT',
			'/mainwp/v2/rest-api/edit-key/' . $target['key_id'],
			[],
			[
				'description' => self::BACKSLASH_VALUE,
				'permissions' => 'read',
			]
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( self::BACKSLASH_VALUE, $this->get_key_description( $target['key_id'] ) );
	}

	/**
	 * A JSON payload sent without a JSON content type is parsed by core as one
	 * URL-encoded key, so edit-key must report empty_body instead of a no-op success.
	 */
	public function test_api_keys_edit_key_rejects_unlabelled_json_body(): void {
		$this->authenticate_as_admin();
		$target = $this->create_rest_api_key( $this->admin_user_id, 'read' );

		$response = $this->do_authenticated_request(
			'PUT',
			'/mainwp/v2/rest-api/edit-key/' . $target['key_id'],
			[],
			[],
			'{"active":false}'
		);

		$this->assertSame( 'empty_body', $response->as_error()->get_error_code(), wp_json_encode( $response->get_data() ) );
	}

	/**
	 * Item 2: a request with neither a JSON nor a form body still reports empty_body.
	 *
	 * The add-key route declares required args, so its own required-param check
	 * fires before the handler runs; the error code contract is pinned on the
	 * helper itself.
	 */
	public function test_get_request_body_data_without_body_returns_empty_body(): void {
		$controller = new \MainWP_Rest_API_Keys_Controller();
		$method     = new \ReflectionMethod( $controller, 'get_request_body_data' );
		$method->setAccessible( true );

		$error = $method->invoke( $controller, new WP_REST_Request( 'POST', '/mainwp/v2/rest-api/add-key' ) );

		$this->assertWPError( $error );
		$this->assertSame( 'empty_body', $error->get_error_code() );
	}

	/**
	 * A JSON payload sent without a JSON content type never reaches the
	 * registered-arg validation, so the helper must not decode it either.
	 */
	public function test_get_request_body_data_ignores_unlabelled_raw_json(): void {
		$controller = new \MainWP_Rest_API_Keys_Controller();
		$method     = new \ReflectionMethod( $controller, 'get_request_body_data' );
		$method->setAccessible( true );

		$request = new WP_REST_Request( 'PUT', '/mainwp/v2/rest-api/edit-key/1' );
		$request->set_header( 'content-type', 'text/plain' );
		$request->set_body( '{"permissions":[["write"]]}' );

		$error = $method->invoke( $controller, $request );

		$this->assertWPError( $error );
		$this->assertSame( 'empty_body', $error->get_error_code() );
	}

	/**
	 * Item 3: POST /sites/add rejects array values for the args it consumes but never declared.
	 */
	public function test_sites_add_rejects_array_typed_params(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/sites/add',
			[
				'url'   => 'https://example.com',
				'name'  => [ 'x' ],
				'admin' => [ 'x' ],
			]
		);

		$this->assertSame( 400, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertSame( 'rest_invalid_param', $data['code'] );

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/sites/add',
			[
				'url'   => 'https://example.com',
				'name'  => 'Example',
				'admin' => [ 'x' ],
			]
		);

		$this->assertSame( 400, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertSame( 'rest_invalid_param', $data['code'] );
	}

	/**
	 * Item 3: sanitize_field() returns an empty string for values trim() cannot take.
	 */
	public function test_base_sanitize_field_returns_empty_for_non_scalars(): void {
		$controller = new \MainWP_Rest_Posts_Controller();

		$this->assertSame( '', $controller->sanitize_field( [ 'x' ] ) );
		$this->assertSame( '', $controller->sanitize_field( new \stdClass() ) );
		$this->assertSame( 'a', $controller->sanitize_field( ' a ' ) );
	}

}
