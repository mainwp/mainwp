<?php
/**
 * MainWP REST v2 Cleanup Round 3 Tests
 *
 * Covers backslash preservation on REST body values in the sites, API keys,
 * posts and base controllers, and dbDelta parsing of the install schema.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use WP_REST_Request;
use WP_REST_Server;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- test classes use PSR-style Test_* filenames, not the mainwp-* convention.

/**
 * Class Test_REST_V2_Cleanup_Round_3
 */
class Test_REST_V2_Cleanup_Round_3 extends \WP_Test_REST_TestCase {

	/**
	 * A value with the backslash shapes WordPress REST delivers unslashed.
	 *
	 * @var string
	 */
	const BACKSLASH_VALUE = 'C:\\Temp\\O\'Brien';

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
	 * Mirrors Test_REST_V2_Registration_Fixes: the MainWP REST server singleton
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
	 * @return \WP_REST_Response Response object.
	 */
	protected function do_authenticated_request( string $method, string $route, array $json_body = [], array $body_params = [] ): \WP_REST_Response {
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
	 * Item 1, sites: POST /sites/add keeps backslashes in every text field it maps.
	 */
	public function test_sites_prepare_object_for_database_keeps_backslashes(): void {
		$request = new WP_REST_Request( 'POST', '/mainwp/v2/sites/add' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				[
					'url'           => 'https://example.com/' . self::BACKSLASH_VALUE,
					'name'          => self::BACKSLASH_VALUE,
					'admin'         => self::BACKSLASH_VALUE,
					'adminpassword' => self::BACKSLASH_VALUE,
					'uniqueid'      => self::BACKSLASH_VALUE,
					'http_user'     => self::BACKSLASH_VALUE,
					'http_pass'     => self::BACKSLASH_VALUE,
					'groupids'      => '1,2',
				]
			)
		);

		$controller = new \MainWP_Rest_Sites_Controller();
		$method     = new \ReflectionMethod( $controller, 'prepare_object_for_database' );
		$method->setAccessible( true );
		$fields = $method->invoke( $controller, $request );

		$this->assertSame( 'https://example.com/' . self::BACKSLASH_VALUE, $fields['url'] );
		foreach ( [ 'name', 'wpadmin', 'adminpwd', 'unique_id', 'http_user', 'http_pass' ] as $field ) {
			$this->assertSame( self::BACKSLASH_VALUE, $fields[ $field ], $field . ' lost a backslash' );
		}
		$this->assertSame( [ '1', '2' ], $fields['groupids'] );
	}

	/**
	 * Item 1, API keys: POST /rest-api/add-key stores a description with backslashes.
	 */
	public function test_api_keys_add_key_keeps_backslashes(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/rest-api/add-key',
			[],
			[
				'description' => self::BACKSLASH_VALUE,
				'permissions' => 'read',
				'active'      => 1,
			]
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		global $wpdb;
		$stored = $wpdb->get_var( $wpdb->prepare( "SELECT description FROM {$wpdb->prefix}mainwp_api_keys WHERE description = %s", self::BACKSLASH_VALUE ) );
		$this->assertSame( self::BACKSLASH_VALUE, $stored );
	}

	/**
	 * Item 1, API keys: PUT /rest-api/edit-key/{id} stores a description with backslashes.
	 */
	public function test_api_keys_edit_key_keeps_backslashes(): void {
		$this->authenticate_as_admin();
		$target = $this->create_rest_api_key( $this->admin_user_id, 'read' );

		$response = $this->do_authenticated_request(
			'PUT',
			'/mainwp/v2/rest-api/edit-key/' . $target['key_id'],
			[ 'description' => self::BACKSLASH_VALUE ]
		);

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( self::BACKSLASH_VALUE, $this->get_key_description( $target['key_id'] ) );
	}

	/**
	 * Item 1, posts: the post_type query arg keeps backslashes.
	 */
	public function test_posts_post_type_arg_keeps_backslashes(): void {
		$request = new WP_REST_Request( 'GET', '/mainwp/v2/posts' );
		$request->set_query_params( [ 'post_type' => self::BACKSLASH_VALUE ] );

		$controller = new \MainWP_Rest_Posts_Controller();
		$args       = $controller->posts_fields_custom_query_args( [], $request );

		$this->assertSame( self::BACKSLASH_VALUE, $args['post_type'] );
	}

	/**
	 * Item 1, base: sanitize_field() keeps backslashes and still trims.
	 */
	public function test_base_sanitize_field_keeps_backslashes(): void {
		$controller = new \MainWP_Rest_Posts_Controller();

		$this->assertSame( self::BACKSLASH_VALUE, $controller->sanitize_field( '  ' . self::BACKSLASH_VALUE . '  ' ) );
		$this->assertSame( '', $controller->sanitize_field( null ) );
		$this->assertSame( '', $controller->sanitize_field( '' ) );
	}

	/**
	 * Item 2: dbDelta parses every index line of the backup progress schema.
	 *
	 * The install SQL only exists inside MainWP_Install::install(), so the
	 * mainwp_db_install_tables filter captures it and aborts the install before
	 * it runs dbDelta or the post-update migrations itself.
	 */
	public function test_install_schema_backup_progress_indexes_parse_without_warnings(): void {
		delete_option( 'mainwp_db_version' );

		$captured = [];
		$filter   = function ( $sql ) use ( &$captured ) {
			$captured = $sql;
			throw new \RuntimeException( 'captured' );
		};
		add_filter( 'mainwp_db_install_tables', $filter );

		try {
			\MainWP\Dashboard\MainWP_Install::instance()->install();
			$this->fail( 'mainwp_db_install_tables did not fire' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'captured', $e->getMessage() );
		} finally {
			remove_filter( 'mainwp_db_install_tables', $filter );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'mainwp_wp_backup_progress';
		$query = null;
		foreach ( $captured as $sql ) {
			if ( false !== strpos( $sql, 'CREATE TABLE ' . $table ) ) {
				$query = $sql;
			}
		}
		$this->assertNotNull( $query, 'backup progress schema not found in install SQL' );
		$this->assertStringNotContainsString( 'UNIQUE', $query, 'progress rows are per task and site, so task_id must not be unique' );

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// PHPUnit converts warnings to exceptions, so an unparseable index line fails here.
		$changes = dbDelta( $query, false );

		foreach ( $changes as $change ) {
			$this->assertStringNotContainsString( 'ADD  ()', $change );
		}
	}

}
