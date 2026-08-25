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
	 * Site id created for the monitor fixture.
	 *
	 * @var int
	 */
	protected $monitor_site_id = 0;

	/**
	 * Monitor id created for the monitor fixture.
	 *
	 * @var int
	 */
	protected $monitor_id = 0;

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

		if ( $this->monitor_id ) {
			$wpdb->delete( $wpdb->prefix . 'mainwp_monitors', [ 'monitor_id' => $this->monitor_id ], [ '%d' ] );
			$this->monitor_id = 0;
		}

		if ( $this->monitor_site_id ) {
			$wpdb->delete( $wpdb->prefix . 'mainwp_wp_sync', [ 'wpid' => $this->monitor_site_id ], [ '%d' ] );
			$wpdb->delete( $wpdb->prefix . 'mainwp_wp', [ 'id' => $this->monitor_site_id ], [ '%d' ] );
			$this->monitor_site_id = 0;
		}

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
	 * @param array  $query        Query parameters, kept out of the body params so the raw body is what the handler reads.
	 * @return \WP_REST_Response Response object.
	 */
	protected function do_authenticated_raw_request( string $method, string $route, string $body = '', string $content_type = 'application/json', array $query = [] ): \WP_REST_Response {
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

		if ( ! empty( $query ) ) {
			$request->set_query_params( $query );
		}

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
	 * parentheses inside message strings from ending a construction early. The
	 * argument split follows a delimiter stack over (), [] and {}, so commas in
	 * a match arm or a closure body are not read as argument separators.
	 *
	 * @param string $file Absolute path to the PHP file.
	 * @return array List of [ 'line' => int, 'source' => string, 'args' => array ] entries.
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

			// PHP 8 tokenizes a fully qualified `new \WP_Error` as a single name token where PHP 7.4
			// emits a separator plus a string, so the comparison is on the name text.
			if ( $j >= $count || ! is_array( $tokens[ $j ] ) || 'WP_Error' !== ltrim( $tokens[ $j ][1], '\\' ) ) {
				continue;
			}

			$line    = $tokens[ $j ][2];
			$source  = '';
			$args    = [];
			$current = '';
			$stack   = [];

			for ( $k = $j + 1; $k < $count; $k++ ) {
				$token   = $tokens[ $k ];
				$text    = is_array( $token ) ? $token[1] : $token;
				$source .= $text;

				$opened = $this->closing_delimiter_for( $token );

				if ( null !== $opened ) {
					$stack[] = $opened;
					// The constructor's own parenthesis is the boundary, not part of an argument.
					if ( 1 === count( $stack ) ) {
						continue;
					}
				} elseif ( ! empty( $stack ) && $token === end( $stack ) ) {
					array_pop( $stack );
					if ( empty( $stack ) ) {
						break;
					}
				} elseif ( ',' === $token && 1 === count( $stack ) ) {
					$args[]  = trim( $current );
					$current = '';
					continue;
				}

				$current .= $text;
			}

			$args[] = trim( $current );

			$found[] = [
				'line'   => $line,
				'source' => $source,
				// A trailing comma leaves an empty tail, which is not an argument.
				'args'   => array_values( array_filter( $args, static fn ( $arg ) => '' !== $arg ) ),
			];
		}

		return $found;
	}

	/**
	 * The delimiter that closes the given token, or null when the token opens nothing.
	 *
	 * @param mixed $token Token from token_get_all(), either a string or a [ id, text, line ] array.
	 * @return string|null Closing delimiter.
	 */
	protected function closing_delimiter_for( $token ): ?string {
		if ( '(' === $token ) {
			return ')';
		}

		if ( '[' === $token ) {
			return ']';
		}

		// An interpolated string opens its brace as an array token but closes it with a plain '}'.
		if ( '{' === $token || ( is_array( $token ) && in_array( $token[0], [ T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ], true ) ) ) {
			return '}';
		}

		return null;
	}

	/**
	 * Item 1: every settings controller error declares the HTTP status it should return.
	 *
	 * Without a status WordPress falls back to 500, so validation and not-found
	 * errors surfaced as server errors. The status travels in the third (data)
	 * argument, so the assertion is that the argument is there at all: a status
	 * built in a variable or a constant passes, a two-argument error does not.
	 */
	public function test_settings_controller_errors_declare_a_status(): void {
		$file = dirname( __DIR__ ) . '/includes/rest-api/controller/version2/class-mainwp-rest-settings-controller.php';
		$this->assertFileExists( $file );

		$constructions = $this->collect_wp_error_constructions( $file );
		$this->assertGreaterThan( 50, count( $constructions ), 'Expected the settings controller to construct many errors.' );

		$missing = [];
		foreach ( $constructions as $construction ) {
			if ( count( $construction['args'] ) < 3 ) {
				$missing[] = $construction['line'];
			}
		}

		$this->assertSame( [], $missing, 'WP_Error constructions without a data argument, at lines: ' . implode( ', ', $missing ) );
	}

	/**
	 * The argument scan reads the constructor's shape, not the words in it.
	 */
	public function test_wp_error_argument_scan_reads_constructor_shape(): void {
		$fixture = tempnam( sys_get_temp_dir(), 'mainwp-wp-error-scan' );

		file_put_contents(
			$fixture,
			'<?php' . "\n"
			. '$a = new WP_Error( \'code_one\', __( \'One, two (three).\' ) );' . "\n"
			. '$b = new WP_Error( \'code_two\', __( \'Two.\' ), array( \'status\' => 400 ) );' . "\n"
			. '$c = new \WP_Error( $code, $message, $data );' . "\n"
			. '$d = new WP_Error( \'code_four\', __( \'Four.\' ), [ \'status\' => 404, \'key\' => 1 ], );' . "\n"
			. '$e = new WP_Error( \'code_five\', match ( $kind ) { 1, 2 => __( \'Low.\' ), default => __( \'High.\' ) } );' . "\n"
			. '$f = new WP_Error( \'code_six\', $message, array( \'status\' => 400, \'map\' => function ( $a, $b ) { return [ $a, $b ]; } ) );' . "\n"
		);

		$constructions = $this->collect_wp_error_constructions( $fixture );
		unlink( $fixture );

		$this->assertCount( 6, $constructions );
		$this->assertCount( 2, $constructions[0]['args'], 'A message containing commas and parentheses is one argument.' );
		$this->assertCount( 3, $constructions[1]['args'] );
		$this->assertCount( 3, $constructions[2]['args'], 'A status passed through a variable still counts as the data argument.' );
		$this->assertCount( 3, $constructions[3]['args'], 'A short-array data argument with a trailing comma is one argument.' );
		$this->assertCount( 2, $constructions[4]['args'], 'The commas in a match arm do not separate arguments.' );
		$this->assertCount( 3, $constructions[5]['args'], 'The commas in a closure body do not separate arguments.' );
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
		$this->assertIsInt( $data['data']['field_id'] );

		$this->delete_client_field( 'REST V2 Cleanup Field' );
	}

	/**
	 * PR review: the fields list answers field_id as the integer its schema declares.
	 *
	 * wpdb hands every column back as a string, so the mapping has to cast or the list disagrees
	 * with the type a client reads off the schema.
	 */
	public function test_client_fields_list_returns_an_integer_field_id(): void {
		$this->authenticate_as_admin();

		$created = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/clients/fields/add',
			[
				'name'        => 'REST V2 Cleanup List Field',
				'description' => 'REST V2 Cleanup List Field description',
			]
		);

		$this->assertSame( 200, $created->get_status() );
		$field_id = (int) $created->get_data()['data']['field_id'];

		$response = $this->do_authenticated_request( 'GET', '/mainwp/v2/clients/fields' );

		$this->assertSame( 200, $response->get_status() );

		$listed = null;
		foreach ( $response->get_data()['data'] as $field ) {
			if ( $field_id === (int) $field['field_id'] ) {
				$listed = $field;
				break;
			}
		}

		$this->assertNotNull( $listed, 'The created field is missing from the fields list.' );
		$this->assertIsInt( $listed['field_id'] );

		$this->delete_client_field_by_id( $field_id );
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
	 * An edit that stores the values already there is a success.
	 *
	 * $wpdb->update() reports no changed rows for it, which the DB layer returns as false, so the
	 * route used to answer a no-op edit with an error.
	 */
	public function test_client_fields_edit_with_unchanged_values_succeeds(): void {
		$this->authenticate_as_admin();

		$field = \MainWP\Dashboard\MainWP_DB_Client::instance()->add_client_field(
			[
				'field_name' => 'REST V2 Cleanup Unchanged Field',
				'field_desc' => 'unchanged',
				'client_id'  => 0,
			]
		);
		$this->assertNotEmpty( $field, 'Could not create the client field the test edits.' );

		$response = $this->do_authenticated_request(
			'PUT',
			'/mainwp/v2/clients/fields/' . $field->field_id . '/edit',
			[
				'name'        => 'REST V2 Cleanup Unchanged Field',
				'description' => 'unchanged',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['success'] );

		$stored = \MainWP\Dashboard\MainWP_DB_Client::instance()->get_client_fields_by( 'field_id', $field->field_id );
		$this->assertSame( 'REST V2 Cleanup Unchanged Field', $stored->field_name );
		$this->assertSame( 'unchanged', $stored->field_desc );

		$this->delete_client_field( 'REST V2 Cleanup Unchanged Field' );
	}

	/**
	 * Renaming a field onto a name another field of the same client owns is the caller's mistake.
	 *
	 * The unique index on (client_id, field_name) refuses the write, so update_client_field() returns
	 * the same false a broken write returns and the status has to be worked out from the name.
	 */
	public function test_client_fields_edit_duplicate_name_returns_400(): void {
		global $wpdb;

		$this->authenticate_as_admin();

		$owner = \MainWP\Dashboard\MainWP_DB_Client::instance()->add_client_field(
			[
				'field_name' => 'REST V2 Cleanup Taken Name',
				'field_desc' => 'owner',
				'client_id'  => 0,
			]
		);
		$this->assertNotEmpty( $owner, 'Could not create the client field that owns the name.' );

		$field = \MainWP\Dashboard\MainWP_DB_Client::instance()->add_client_field(
			[
				'field_name' => 'REST V2 Cleanup Rename Field',
				'field_desc' => 'before',
				'client_id'  => 0,
			]
		);
		$this->assertNotEmpty( $field, 'Could not create the client field the test renames.' );

		// The rejected write is a real duplicate-key error, and wpdb prints those while the test suite
		// has error display on.
		$suppressed = $wpdb->suppress_errors( true );

		$response = $this->do_authenticated_request(
			'PUT',
			'/mainwp/v2/clients/fields/' . $field->field_id . '/edit',
			[ 'name' => 'REST V2 Cleanup Taken Name' ]
		);

		$wpdb->suppress_errors( $suppressed );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'update_field_failed', $response->get_data()['code'] );
		// The 500 branch of this code answers with its own message, so the duplicate-name message
		// belongs to the 400 branch only.
		$this->assertSame( 'Field already exists, try different field name.', $response->get_data()['message'] );

		$stored = \MainWP\Dashboard\MainWP_DB_Client::instance()->get_client_fields_by( 'field_id', $field->field_id );
		$this->assertSame( 'REST V2 Cleanup Rename Field', $stored->field_name );

		$this->delete_client_field( 'REST V2 Cleanup Taken Name' );
		$this->delete_client_field( 'REST V2 Cleanup Rename Field' );
	}

	/**
	 * A JSON body sent without the JSON content type is read raw, so the registered sanitizers
	 * never see it. A nested value would sanitize down to an empty string and wipe the field.
	 */
	public function test_client_fields_edit_rejects_non_scalar_description(): void {
		$this->authenticate_as_admin();

		$field = \MainWP\Dashboard\MainWP_DB_Client::instance()->add_client_field(
			[
				'field_name' => 'REST V2 Cleanup Nested Field',
				'field_desc' => 'keep this',
				'client_id'  => 0,
			]
		);
		$this->assertNotEmpty( $field, 'Could not create the client field the test edits.' );

		$response = $this->do_authenticated_raw_request(
			'PUT',
			'/mainwp/v2/clients/fields/' . $field->field_id . '/edit',
			'{"description":{"nested":"value"}}',
			'text/plain'
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_field_value', $response->get_data()['code'] );

		$stored = \MainWP\Dashboard\MainWP_DB_Client::instance()->get_client_fields_by( 'field_id', $field->field_id );
		$this->assertSame( 'keep this', $stored->field_desc );

		$this->delete_client_field( 'REST V2 Cleanup Nested Field' );
	}

	/**
	 * Anything that is not text is rejected, whatever it casts to.
	 *
	 * The raw path reads json_decode() output, so true, a number and null arrive as themselves:
	 * true would be stored as "1", a number as its digits, and null would clear the field while
	 * looking like the key was never sent.
	 */
	public function test_client_fields_edit_rejects_non_text_values(): void {
		$this->authenticate_as_admin();

		$field = \MainWP\Dashboard\MainWP_DB_Client::instance()->add_client_field(
			[
				'field_name' => 'REST V2 Cleanup Typed Field',
				'field_desc' => 'keep this',
				'client_id'  => 0,
			]
		);
		$this->assertNotEmpty( $field, 'Could not create the client field the test edits.' );

		$bodies = [
			'{"description":true}',
			'{"description":42}',
			'{"description":null}',
			'{"name":true}',
		];

		foreach ( $bodies as $body ) {
			$response = $this->do_authenticated_raw_request(
				'PUT',
				'/mainwp/v2/clients/fields/' . $field->field_id . '/edit',
				$body,
				'text/plain'
			);

			$this->assertSame( 400, $response->get_status(), 'Body should be rejected: ' . $body );
			$this->assertSame( 'invalid_field_value', $response->get_data()['code'], 'Body should be rejected: ' . $body );
		}

		$stored = \MainWP\Dashboard\MainWP_DB_Client::instance()->get_client_fields_by( 'field_id', $field->field_id );
		$this->assertSame( 'REST V2 Cleanup Typed Field', $stored->field_name );
		$this->assertSame( 'keep this', $stored->field_desc );

		$this->delete_client_field( 'REST V2 Cleanup Typed Field' );
	}

	/**
	 * An omitted key is still omitted: the edit keeps the stored value the body says nothing about.
	 */
	public function test_client_fields_edit_leaves_an_omitted_key_alone(): void {
		$this->authenticate_as_admin();

		$field = \MainWP\Dashboard\MainWP_DB_Client::instance()->add_client_field(
			[
				'field_name' => 'REST V2 Cleanup Omitted Field',
				'field_desc' => 'before',
				'client_id'  => 0,
			]
		);
		$this->assertNotEmpty( $field, 'Could not create the client field the test edits.' );

		$response = $this->do_authenticated_raw_request(
			'PUT',
			'/mainwp/v2/clients/fields/' . $field->field_id . '/edit',
			'{"description":"after"}',
			'text/plain'
		);

		$this->assertSame( 200, $response->get_status() );

		$stored = \MainWP\Dashboard\MainWP_DB_Client::instance()->get_client_fields_by( 'field_id', $field->field_id );
		$this->assertSame( 'REST V2 Cleanup Omitted Field', $stored->field_name );
		$this->assertSame( 'after', $stored->field_desc );

		$this->delete_client_field( 'REST V2 Cleanup Omitted Field' );
	}

	/**
	 * The same value check guards the add route.
	 *
	 * Both add params are required, so the query string is what satisfies the required check while
	 * the raw body carries the values the handler actually reads.
	 */
	public function test_client_fields_add_rejects_non_scalar_name(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_raw_request(
			'POST',
			'/mainwp/v2/clients/fields/add',
			'{"name":{"nested":"value"},"description":"REST V2 Cleanup Guard description"}',
			'text/plain',
			[
				'name'        => 'REST V2 Cleanup Guard Field',
				'description' => 'REST V2 Cleanup Guard description',
			]
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_field_value', $response->get_data()['code'] );

		$created = \MainWP\Dashboard\MainWP_DB_Client::instance()->get_client_fields_by( 'field_name', 'REST V2 Cleanup Guard Field' );
		$this->assertEmpty( $created, 'A rejected add must not create the field.' );
	}

	/**
	 * A name the DB layer will not store is still the caller's fault, so it keeps its 400.
	 *
	 * Only a genuine write failure is a 500.
	 */
	public function test_client_fields_add_rejects_name_that_sanitizes_away(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_raw_request(
			'POST',
			'/mainwp/v2/clients/fields/add',
			'{"name":"<b>","description":"REST V2 Cleanup Blank description"}',
			'text/plain',
			[
				'name'        => 'REST V2 Cleanup Blank Field',
				'description' => 'REST V2 Cleanup Blank description',
			]
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'create_field_failed', $response->get_data()['code'] );
	}

	/**
	 * A name with brackets in it is a duplicate like any other.
	 *
	 * The field_name lookup the status used to be worked out from strips [ and ] before it queries,
	 * while the row keeps them, so the second add looked like the write itself had failed.
	 */
	public function test_client_fields_add_duplicate_bracketed_name_returns_400(): void {
		global $wpdb;

		$this->authenticate_as_admin();

		$name = 'REST V2 Cleanup [Bracket] Field';

		$created = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/clients/fields/add',
			[
				'name'        => $name,
				'description' => 'first',
			]
		);

		$this->assertSame( 200, $created->get_status() );
		$this->assertSame( $name, $created->get_data()['data']['name'] );
		$field_id = (int) $created->get_data()['data']['field_id'];

		// The rejected insert is a real duplicate-key error, and wpdb prints those while the test suite
		// has error display on.
		$suppressed = $wpdb->suppress_errors( true );

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/clients/fields/add',
			[
				'name'        => $name,
				'description' => 'second',
			]
		);

		$wpdb->suppress_errors( $suppressed );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'create_field_failed', $response->get_data()['code'] );
		// The 500 branch of this code answers with its own message.
		$this->assertSame( 'Create client field failed.', $response->get_data()['message'] );

		$this->delete_client_field_by_id( $field_id );
	}

	/**
	 * The same for a rename onto a bracketed name another field owns.
	 */
	public function test_client_fields_edit_duplicate_bracketed_name_returns_400(): void {
		global $wpdb;

		$this->authenticate_as_admin();

		$name = 'REST V2 Cleanup [Bracket] Taken';

		$owner = \MainWP\Dashboard\MainWP_DB_Client::instance()->add_client_field(
			[
				'field_name' => $name,
				'field_desc' => 'owner',
				'client_id'  => 0,
			]
		);
		$this->assertNotEmpty( $owner, 'Could not create the client field that owns the name.' );

		$field = \MainWP\Dashboard\MainWP_DB_Client::instance()->add_client_field(
			[
				'field_name' => 'REST V2 Cleanup Bracket Rename Field',
				'field_desc' => 'before',
				'client_id'  => 0,
			]
		);
		$this->assertNotEmpty( $field, 'Could not create the client field the test renames.' );

		$suppressed = $wpdb->suppress_errors( true );

		$response = $this->do_authenticated_request(
			'PUT',
			'/mainwp/v2/clients/fields/' . $field->field_id . '/edit',
			[ 'name' => $name ]
		);

		$wpdb->suppress_errors( $suppressed );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'update_field_failed', $response->get_data()['code'] );

		$stored = \MainWP\Dashboard\MainWP_DB_Client::instance()->get_client_fields_by( 'field_id', $field->field_id );
		$this->assertSame( 'REST V2 Cleanup Bracket Rename Field', $stored->field_name );

		$this->delete_client_field_by_id( (int) $owner->field_id );
		$this->delete_client_field_by_id( (int) $field->field_id );
	}

	/**
	 * A name that differs only in case is a duplicate when the column collation says so.
	 *
	 * field_name is utf8mb4_unicode_520_ci here, so the unique index reads "Example" and "example"
	 * as the same name and the refused add has to answer as a client error rather than as the write
	 * itself failing.
	 */
	public function test_client_fields_add_case_only_duplicate_name_returns_400(): void {
		global $wpdb;

		$this->authenticate_as_admin();

		$name = 'REST V2 Cleanup Case Field';

		$created = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/clients/fields/add',
			[
				'name'        => $name,
				'description' => 'first',
			]
		);

		$this->assertSame( 200, $created->get_status() );
		$field_id = (int) $created->get_data()['data']['field_id'];

		$suppressed = $wpdb->suppress_errors( true );

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/clients/fields/add',
			[
				'name'        => strtolower( $name ),
				'description' => 'second',
			]
		);

		$wpdb->suppress_errors( $suppressed );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'create_field_failed', $response->get_data()['code'] );

		$this->delete_client_field_by_id( $field_id );
	}

	/**
	 * The same for a rename onto a name another field owns in a different case.
	 *
	 * With field_name on utf8mb4_unicode_520_ci the unique index rejects the update, so the answer
	 * is 400 and the stored name is left alone.
	 */
	public function test_client_fields_edit_case_only_duplicate_name_returns_400(): void {
		global $wpdb;

		$this->authenticate_as_admin();

		$name = 'REST V2 Cleanup Case Taken';

		$owner = \MainWP\Dashboard\MainWP_DB_Client::instance()->add_client_field(
			[
				'field_name' => $name,
				'field_desc' => 'owner',
				'client_id'  => 0,
			]
		);
		$this->assertNotEmpty( $owner, 'Could not create the client field that owns the name.' );

		$field = \MainWP\Dashboard\MainWP_DB_Client::instance()->add_client_field(
			[
				'field_name' => 'REST V2 Cleanup Case Rename Field',
				'field_desc' => 'before',
				'client_id'  => 0,
			]
		);
		$this->assertNotEmpty( $field, 'Could not create the client field the test renames.' );

		$suppressed = $wpdb->suppress_errors( true );

		$response = $this->do_authenticated_request(
			'PUT',
			'/mainwp/v2/clients/fields/' . $field->field_id . '/edit',
			[ 'name' => strtolower( $name ) ]
		);

		$wpdb->suppress_errors( $suppressed );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'update_field_failed', $response->get_data()['code'] );

		$stored = \MainWP\Dashboard\MainWP_DB_Client::instance()->get_client_fields_by( 'field_id', $field->field_id );
		$this->assertSame( 'REST V2 Cleanup Case Rename Field', $stored->field_name );

		$this->delete_client_field_by_id( (int) $owner->field_id );
		$this->delete_client_field_by_id( (int) $field->field_id );
	}

	/**
	 * A name longer than the column is a client error, not a write that failed.
	 *
	 * field_name is varchar(191): wpdb truncates a longer name, the unique index refuses it, and the
	 * duplicate lookup queries the untruncated name and finds nothing, so without the length check the
	 * add answers 500.
	 */
	public function test_client_fields_add_name_over_191_characters_returns_400(): void {
		$this->authenticate_as_admin();

		$name = str_pad( 'REST V2 Cleanup Long Name ', 192, 'x' );

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/clients/fields/add',
			[
				'name'        => $name,
				'description' => 'too long',
			]
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_field_value', $response->get_data()['code'] );
	}

	/**
	 * The same for a rename onto a name longer than the column.
	 */
	public function test_client_fields_edit_name_over_191_characters_returns_400(): void {
		$this->authenticate_as_admin();

		$field = \MainWP\Dashboard\MainWP_DB_Client::instance()->add_client_field(
			[
				'field_name' => 'REST V2 Cleanup Long Rename Field',
				'field_desc' => 'before',
				'client_id'  => 0,
			]
		);
		$this->assertNotEmpty( $field, 'Could not create the client field the test renames.' );

		$response = $this->do_authenticated_request(
			'PUT',
			'/mainwp/v2/clients/fields/' . $field->field_id . '/edit',
			[ 'name' => str_pad( 'REST V2 Cleanup Long Rename ', 192, 'x' ) ]
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_field_value', $response->get_data()['code'] );

		$stored = \MainWP\Dashboard\MainWP_DB_Client::instance()->get_client_fields_by( 'field_id', $field->field_id );
		$this->assertSame( 'REST V2 Cleanup Long Rename Field', $stored->field_name );

		$this->delete_client_field_by_id( (int) $field->field_id );
	}

	/**
	 * A name of exactly the column width is still storable.
	 */
	public function test_client_fields_add_name_of_191_characters_succeeds(): void {
		$this->authenticate_as_admin();

		$name = str_pad( 'REST V2 Cleanup Max Name ', 191, 'x' );

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/clients/fields/add',
			[
				'name'        => $name,
				'description' => 'at the limit',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $name, $response->get_data()['data']['name'] );

		$this->delete_client_field_by_id( (int) $response->get_data()['data']['field_id'] );
	}

	/**
	 * A description longer than the column is a client error, not a silently shortened write.
	 *
	 * field_desc is varchar(255): wpdb truncates a longer description, so the add would answer 200
	 * with a stored value the caller never sent.
	 */
	public function test_client_fields_add_description_over_255_characters_returns_400(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/clients/fields/add',
			[
				'name'        => 'REST V2 Cleanup Long Desc Field',
				'description' => str_pad( 'REST V2 Cleanup Long Description ', 256, 'x' ),
			]
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_field_value', $response->get_data()['code'] );
	}

	/**
	 * The same for an edit that sets a description longer than the column.
	 */
	public function test_client_fields_edit_description_over_255_characters_returns_400(): void {
		$this->authenticate_as_admin();

		$field = \MainWP\Dashboard\MainWP_DB_Client::instance()->add_client_field(
			[
				'field_name' => 'REST V2 Cleanup Long Desc Edit Field',
				'field_desc' => 'before',
				'client_id'  => 0,
			]
		);
		$this->assertNotEmpty( $field, 'Could not create the client field the test edits.' );

		$response = $this->do_authenticated_request(
			'PUT',
			'/mainwp/v2/clients/fields/' . $field->field_id . '/edit',
			[ 'description' => str_pad( 'REST V2 Cleanup Long Description ', 256, 'x' ) ]
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_field_value', $response->get_data()['code'] );

		$stored = \MainWP\Dashboard\MainWP_DB_Client::instance()->get_client_fields_by( 'field_id', $field->field_id );
		$this->assertSame( 'before', $stored->field_desc );

		$this->delete_client_field_by_id( (int) $field->field_id );
	}

	/**
	 * A description of exactly the column width is still storable.
	 */
	public function test_client_fields_add_description_of_255_characters_succeeds(): void {
		$this->authenticate_as_admin();

		$desc = str_pad( 'REST V2 Cleanup Max Description ', 255, 'x' );

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/clients/fields/add',
			[
				'name'        => 'REST V2 Cleanup Max Desc Field',
				'description' => $desc,
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $desc, $response->get_data()['data']['description'] );

		$this->delete_client_field_by_id( (int) $response->get_data()['data']['field_id'] );
	}

	/**
	 * PR review: "0" is a value both columns store, so an add carrying it is not an add with values
	 * missing.
	 */
	public function test_client_fields_add_stores_zero_string_values(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/clients/fields/add',
			[
				'name'        => '0',
				'description' => '0',
			]
		);

		$this->assertSame( 200, $response->get_status() );

		$field_id = (int) $response->get_data()['data']['field_id'];
		$this->assertNotSame( 0, $field_id );
		$this->assertSame( '0', $response->get_data()['data']['name'] );
		$this->assertSame( '0', $response->get_data()['data']['description'] );

		$stored = \MainWP\Dashboard\MainWP_DB_Client::instance()->get_client_fields_by( 'field_id', $field_id );
		$this->assertSame( '0', $stored->field_name );
		$this->assertSame( '0', $stored->field_desc );

		$this->delete_client_field_by_id( $field_id );
	}

	/**
	 * The same for an edit: a description sent as "0" is stored, not read as a key left out.
	 */
	public function test_client_fields_edit_stores_a_zero_string_description(): void {
		$this->authenticate_as_admin();

		$field = \MainWP\Dashboard\MainWP_DB_Client::instance()->add_client_field(
			[
				'field_name' => 'REST V2 Cleanup Zero Desc Field',
				'field_desc' => 'before',
				'client_id'  => 0,
			]
		);
		$this->assertNotEmpty( $field, 'Could not create the client field the test edits.' );

		$response = $this->do_authenticated_request(
			'PUT',
			'/mainwp/v2/clients/fields/' . $field->field_id . '/edit',
			[ 'description' => '0' ]
		);

		$this->assertSame( 200, $response->get_status() );

		$stored = \MainWP\Dashboard\MainWP_DB_Client::instance()->get_client_fields_by( 'field_id', $field->field_id );
		$this->assertSame( '0', $stored->field_desc );

		$this->delete_client_field_by_id( (int) $field->field_id );
	}

	/**
	 * PR review: the name lookup has to find a field named "0".
	 *
	 * A falsy test there hid the row from the duplicate check that stands in front of the unique
	 * index, and from the token resolver that maps a name to the field it belongs to.
	 */
	public function test_client_field_name_lookup_finds_a_field_named_zero(): void {
		$db = \MainWP\Dashboard\MainWP_DB_Client::instance();

		$field = $db->add_client_field(
			[
				'field_name' => '0',
				'field_desc' => 'Zero name field',
				'client_id'  => 0,
			]
		);
		$this->assertNotEmpty( $field, 'Could not create the client field the test looks up.' );

		$found = $db->get_client_fields_by( 'field_name', '0', 0 );

		$this->assertNotEmpty( $found, 'A field named "0" must be findable by name.' );
		$this->assertSame( (int) $field->field_id, (int) $found->field_id );

		$this->delete_client_field_by_id( (int) $field->field_id );
	}

	/**
	 * PR review: a client id of 0 is no client, not every general field.
	 *
	 * delete_client() reads the rows it is about to drop through this call, so a falsy id answering
	 * with the whole general set would take every general field down with one client.
	 */
	public function test_client_field_lookup_by_client_id_zero_returns_nothing(): void {
		$db = \MainWP\Dashboard\MainWP_DB_Client::instance();

		$field = $db->add_client_field(
			[
				'field_name' => 'REST V2 Cleanup General Field',
				'field_desc' => 'General field',
				'client_id'  => 0,
			]
		);
		$this->assertNotEmpty( $field, 'Could not create the general client field the test looks up.' );

		$this->assertNull( $db->get_client_fields_by( 'client_id', 0 ) );

		$this->delete_client_field_by_id( (int) $field->field_id );
	}

	/**
	 * PR review: a field named "0" is edited and deleted through the routes by its own id.
	 *
	 * The route segment is read as an id whenever it is all digits, so "0" as a route parameter is
	 * the id 0 and never the name, the same way a field named "42" is only reachable by its own id.
	 */
	public function test_client_fields_named_zero_are_edited_and_deleted_by_id(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/clients/fields/add',
			[
				'name'        => '0',
				'description' => 'Zero name field',
			]
		);

		$this->assertSame( 200, $response->get_status() );

		$field_id = (int) $response->get_data()['data']['field_id'];
		$this->assertNotSame( 0, $field_id );

		$edited = $this->do_authenticated_request(
			'PUT',
			'/mainwp/v2/clients/fields/' . $field_id . '/edit',
			[ 'description' => 'Zero name field edited' ]
		);

		$this->assertSame( 200, $edited->get_status() );

		$stored = \MainWP\Dashboard\MainWP_DB_Client::instance()->get_client_fields_by( 'field_id', $field_id );
		$this->assertSame( '0', $stored->field_name );
		$this->assertSame( 'Zero name field edited', $stored->field_desc );

		$by_name = $this->do_authenticated_request(
			'PUT',
			'/mainwp/v2/clients/fields/0/edit',
			[ 'description' => 'Reached by name' ]
		);

		$this->assertSame( 404, $by_name->get_status() );
		$this->assertSame( 'invalid_field_id', $by_name->get_data()['code'] );

		$deleted = $this->do_authenticated_request( 'DELETE', '/mainwp/v2/clients/fields/' . $field_id . '/delete' );

		$this->assertSame( 200, $deleted->get_status() );
		$this->assertEmpty( \MainWP\Dashboard\MainWP_DB_Client::instance()->get_client_fields_by( 'field_id', $field_id ) );
	}

	/**
	 * Item 6: a group with no items is still a group the endpoint cannot dispatch.
	 */
	public function test_batch_empty_group_value_returns_group_error(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request( 'POST', '/mainwp/v2/batch', [ 'costs' => [] ] );

		$data = $response->get_data();

		$this->assertArrayHasKey( 'costs', $data );
		$this->assertSame( 'rest_batch_group_not_supported', $data['costs']['error']['code'] );
		$this->assertSame( 400, $data['costs']['error']['data']['status'] );
	}

	/**
	 * Item 6: a group whose value is not an array is reported, not dropped.
	 */
	public function test_batch_scalar_group_value_returns_group_error(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request( 'POST', '/mainwp/v2/batch', [ 'unknown_group' => 'x' ] );

		$data = $response->get_data();

		$this->assertArrayHasKey( 'unknown_group', $data );
		$this->assertSame( 'rest_batch_group_not_supported', $data['unknown_group']['error']['code'] );
		$this->assertSame( 400, $data['unknown_group']['error']['data']['status'] );
	}

	/**
	 * Item 6 follow-up: a supported group sent as a scalar is reported, not dropped.
	 *
	 * The dispatch reads an action off the group, which a string does not have, so the request used
	 * to come back as an empty success.
	 */
	public function test_batch_scalar_supported_group_returns_invalid_param(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request( 'POST', '/mainwp/v2/batch', [ 'sites' => 'x' ] );

		$data = $response->get_data();

		$this->assertArrayHasKey( 'sites', $data );
		$this->assertSame( 'rest_invalid_param', $data['sites']['error']['code'] );
		$this->assertSame( 400, $data['sites']['error']['data']['status'] );
	}

	/**
	 * Item 6 follow-up: an action sent as a scalar is reported before the dispatch walks it.
	 */
	public function test_batch_scalar_action_value_returns_invalid_param(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request( 'POST', '/mainwp/v2/batch', [ 'sites' => [ 'create' => 'x' ] ] );

		$data = $response->get_data();

		$this->assertArrayHasKey( 'sites', $data );
		$this->assertSame( 'rest_invalid_param', $data['sites']['error']['code'] );
		$this->assertSame( 400, $data['sites']['error']['data']['status'] );
		$this->assertArrayNotHasKey( 'create', $data['sites'] );
	}

	/**
	 * Item 6 follow-up: a well-formed group is still dispatched and answers in the same shape.
	 *
	 * The site id does not exist, so the dispatch answers with a per-item error and nothing is
	 * written; a group-level error here would mean the shape check swallowed a valid request.
	 */
	public function test_batch_valid_group_is_still_dispatched(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request( 'POST', '/mainwp/v2/batch', [ 'sites' => [ 'sync' => [ 999999 ] ] ] );

		$data = $response->get_data();

		$this->assertArrayHasKey( 'sites', $data );
		$this->assertArrayNotHasKey( 'error', $data['sites'] );
		$this->assertCount( 1, $data['sites']['sync'] );
		$this->assertSame( 999999, $data['sites']['sync'][0]['id'] );
	}

	/**
	 * PR review: a group sent as a JSON list is reported, not run as an empty request.
	 *
	 * The dispatch indexes the group by action name, which a list has none of, so the request used
	 * to come back as an empty success with nothing dispatched and nothing said.
	 */
	public function test_batch_list_shaped_group_returns_invalid_param(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/batch',
			[
				'sites' => [
					[ 'create' => [ [ 'name' => 'REST V2 Cleanup List Group Site' ] ] ],
				],
			]
		);

		$data = $response->get_data();

		$this->assertArrayHasKey( 'sites', $data );
		$this->assertSame( 'rest_invalid_param', $data['sites']['error']['code'] );
		$this->assertSame( 400, $data['sites']['error']['data']['status'] );
		$this->assertArrayNotHasKey( 'create', $data['sites'] );
	}

	/**
	 * PR review: an action the dispatch does not read is reported, not dropped.
	 *
	 * A misspelled action used to answer with an empty success, so a caller could not tell a typo
	 * from a batch that ran.
	 */
	public function test_batch_unknown_action_key_returns_invalid_param(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/batch',
			[
				'sites' => [
					'craete' => [ [ 'name' => 'REST V2 Cleanup Unknown Action Site' ] ],
				],
			]
		);

		$data = $response->get_data();

		$this->assertArrayHasKey( 'sites', $data );
		$this->assertSame( 'rest_invalid_param', $data['sites']['error']['code'] );
		$this->assertSame( 400, $data['sites']['error']['data']['status'] );
		$this->assertArrayNotHasKey( 'craete', $data['sites'] );
	}

	/**
	 * PR review: a create item that is not an object is reported before it reaches the dispatch.
	 *
	 * Every create item is handed to set_body_params(), which takes an array.
	 */
	public function test_batch_null_create_item_returns_invalid_param(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/batch',
			[
				'sites' => [
					'create' => [ null ],
				],
			]
		);

		$data = $response->get_data();

		$this->assertArrayHasKey( 'sites', $data );
		$this->assertSame( 'rest_invalid_param', $data['sites']['error']['code'] );
		$this->assertSame( 400, $data['sites']['error']['data']['status'] );
		$this->assertArrayNotHasKey( 'create', $data['sites'] );
	}

	/**
	 * PR review: ids still dispatch whether they arrive as numbers or as strings.
	 *
	 * A query-sent id is always a string, so the id check cannot demand an int.
	 */
	public function test_batch_id_action_accepts_int_and_numeric_string_ids(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/batch',
			[
				'sites' => [
					'sync' => [ 999999, '999998' ],
				],
			]
		);

		$data = $response->get_data();

		$this->assertArrayHasKey( 'sites', $data );
		$this->assertArrayNotHasKey( 'error', $data['sites'] );
		$this->assertCount( 2, $data['sites']['sync'] );
		$this->assertSame( 999999, $data['sites']['sync'][0]['id'] );
		$this->assertSame( 999998, $data['sites']['sync'][1]['id'] );
	}

	/**
	 * PR review: an id action reads whole numbers, so a fractional string is a group the endpoint
	 * refuses instead of a cast onto whichever site the leading digits name.
	 */
	public function test_batch_id_action_rejects_fractional_string_ids(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/batch',
			[
				'sites' => [
					'sync' => [ '1.9' ],
				],
			]
		);

		$data = $response->get_data();

		$this->assertArrayHasKey( 'sites', $data );
		$this->assertSame( 'rest_invalid_param', $data['sites']['error']['code'] );
		$this->assertArrayNotHasKey( 'sync', $data['sites'] );
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
	 * A malformed group cannot spend the batch limit.
	 *
	 * Its items are never dispatched, so counting them before the shape check let a scalar action
	 * answer with the global 413 instead of the per-group error that says what is wrong.
	 */
	public function test_batch_malformed_group_is_dropped_before_the_limit_check(): void {
		$this->authenticate_as_admin();

		$lower_limit = static function () {
			return 2;
		};
		add_filter( 'mainwp_rest_batch_items_limit', $lower_limit );

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/batch',
			[
				'sites' => [
					'sync'   => [ 1, 2, 3 ],
					'create' => 'x',
				],
			]
		);

		remove_filter( 'mainwp_rest_batch_items_limit', $lower_limit );

		$data = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'sites', $data );
		$this->assertSame( 'rest_invalid_param', $data['sites']['error']['code'] );
		$this->assertSame( 400, $data['sites']['error']['data']['status'] );
		$this->assertArrayNotHasKey( 'sync', $data['sites'] );
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
	 * PR review: a group sent only in the query string is answered, not dropped without a word.
	 *
	 * The limit check reads the merged params, so such a group already spent the caller's budget
	 * while the report, which read the body alone, said nothing about it.
	 */
	public function test_batch_query_only_unsupported_group_is_reported(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_raw_request(
			'POST',
			'/mainwp/v2/batch',
			'',
			'application/json',
			[ 'updates' => [ 'create' => [ '1' ] ] ]
		);

		$data = $response->get_data();

		$this->assertArrayHasKey( 'updates', $data );
		$this->assertSame( 'rest_batch_group_not_supported', $data['updates']['error']['code'] );
		$this->assertSame( 400, $data['updates']['error']['data']['status'] );
	}

	/**
	 * A query-only unsupported group still counts toward the limit, as a body one does.
	 */
	public function test_batch_limit_counts_query_only_updates_items(): void {
		$this->authenticate_as_admin();

		$lower_limit = static function () {
			return 2;
		};
		add_filter( 'mainwp_rest_batch_items_limit', $lower_limit );

		$response = $this->do_authenticated_raw_request(
			'POST',
			'/mainwp/v2/batch',
			'',
			'application/json',
			[ 'updates' => [ 'create' => [ '1', '2', '3' ] ] ]
		);

		remove_filter( 'mainwp_rest_batch_items_limit', $lower_limit );

		$this->assertSame( 413, $response->get_status() );
		$this->assertSame( 'mainwp_rest_request_entity_too_large', $response->get_data()['code'] );
	}

	/**
	 * A group is always an array, so a scalar query parameter names none.
	 */
	public function test_batch_scalar_query_param_is_not_reported_as_a_group(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_raw_request(
			'POST',
			'/mainwp/v2/batch',
			'',
			'application/json',
			[ 'per_page' => '5' ]
		);

		$data = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [], $data );
	}

	/**
	 * PR review: an underscore parameter belongs to the REST server, not to a group name.
	 *
	 * _fields, _embed and _locale can arrive in a JSON body, and reporting them as unsupported
	 * groups answered a request the caller never made.
	 */
	public function test_batch_reserved_underscore_body_param_is_not_a_group(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_request(
			'POST',
			'/mainwp/v2/batch',
			[
				'_fields' => 'id',
				'sites'   => [ 'sync' => [ 999999 ] ],
			]
		);

		$data = $response->get_data();

		$this->assertArrayNotHasKey( '_fields', $data );
		$this->assertArrayHasKey( 'sites', $data );
		$this->assertCount( 1, $data['sites']['sync'] );
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
	 * PR review: a supported group sent only as a query parameter is shape checked too.
	 *
	 * The dispatch reads the merged request params, so checking the body alone let sites[create]=x
	 * reach a foreach over a string.
	 */
	public function test_batch_query_only_group_shape_is_validated(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_raw_request(
			'POST',
			'/mainwp/v2/batch',
			'',
			'application/json',
			[ 'sites' => [ 'create' => 'x' ] ]
		);

		$data = $response->get_data();

		$this->assertArrayHasKey( 'sites', $data );
		$this->assertSame( 'rest_invalid_param', $data['sites']['error']['code'] );
		$this->assertSame( 400, $data['sites']['error']['data']['status'] );
		$this->assertArrayNotHasKey( 'create', $data['sites'] );
	}

	/**
	 * PR review: a misspelled settings key is rejected, not quietly dropped.
	 *
	 * The schema forbids additional properties, so it has to see the body the caller sent rather
	 * than the params filtered down to the keys the route registered.
	 */
	public function test_monitors_individual_settings_rejects_unknown_key(): void {
		$this->authenticate_as_admin();

		$monitor_id = $this->create_monitor_fixture();

		$response = $this->do_authenticated_request(
			'PUT',
			'/mainwp/v2/monitors/' . $monitor_id . '/settings',
			[ 'intervl' => '5m' ]
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_additional_properties_forbidden', $response->get_data()['code'] );
	}

	/**
	 * PR review: a JSON scalar body is no body, on the individual route.
	 *
	 * It used to reach array_intersect_key(), which fatals on anything that is not an array. The
	 * handler is called directly here: both settings routes register args, and WordPress walks the
	 * body params itself while sanitizing those, which fatals on a scalar before the route callback
	 * is reached.
	 */
	public function test_monitors_individual_settings_rejects_scalar_body(): void {
		$this->authenticate_as_admin();

		$monitor_id = $this->create_monitor_fixture();

		$request = new WP_REST_Request( 'PUT', '/mainwp/v2/monitors/' . $monitor_id . '/settings' );
		$request->set_url_params( [ 'id_domain' => (string) $monitor_id ] );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( '5' );

		$result = \MainWP_Rest_Monitors_Controller::instance()->update_individual_monitor_settings( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'empty_body', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * PR review: a JSON list body is no body either, on the individual route.
	 *
	 * Its keys are positions, so it used to intersect down to nothing and answer as a success that
	 * wrote nothing.
	 */
	public function test_monitors_individual_settings_rejects_list_body(): void {
		$this->authenticate_as_admin();

		$monitor_id = $this->create_monitor_fixture();

		$response = $this->do_authenticated_raw_request(
			'PUT',
			'/mainwp/v2/monitors/' . $monitor_id . '/settings',
			'[1,2]'
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'empty_body', $response->get_data()['code'] );
	}

	/**
	 * PR review: the same for a scalar body on the global route, called the same way and for the
	 * same reason.
	 */
	public function test_monitors_global_settings_rejects_scalar_body(): void {
		$this->authenticate_as_admin();

		$request = new WP_REST_Request( 'PUT', '/mainwp/v2/monitors/settings' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( '5' );

		$result = \MainWP_Rest_Monitors_Controller::instance()->update_global_monitoring_settings( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'empty_body', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * PR review: the same for a list body on the global route.
	 */
	public function test_monitors_global_settings_rejects_list_body(): void {
		$this->authenticate_as_admin();

		$response = $this->do_authenticated_raw_request( 'PUT', '/mainwp/v2/monitors/settings', '[1,2]' );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'empty_body', $response->get_data()['code'] );
	}

	/**
	 * Create a site, its sync row and a monitor for it.
	 *
	 * The monitor lookup joins the sites and the sync tables, so a monitor row on its own is
	 * invisible to the route.
	 *
	 * @return int Monitor id.
	 */
	private function create_monitor_fixture(): int {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'mainwp_wp',
			[
				'userid'               => max( 1, get_current_user_id() ),
				'url'                  => 'https://rest-v2-cleanup-monitor.example.com/',
				'name'                 => 'REST V2 Cleanup Monitor Site',
				'adminname'            => 'admin',
				'pubkey'               => 'test-pubkey',
				'privkey'              => 'test-privkey',
				'ssl_version'          => 0,
				'http_user'            => '',
				'http_pass'            => '',
				'suspended'            => 0,
				'offline_check_result' => 1,
				'client_id'            => 0,
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%d' ]
		);

		$this->monitor_site_id = (int) $wpdb->insert_id;

		$wpdb->insert(
			$wpdb->prefix . 'mainwp_wp_sync',
			[
				'wpid'        => $this->monitor_site_id,
				'version'     => '5.0.0',
				'sync_errors' => '',
			],
			[ '%d', '%s', '%s' ]
		);

		$wpdb->insert(
			$wpdb->prefix . 'mainwp_monitors',
			[
				'wpid'            => $this->monitor_site_id,
				'active'          => -1,
				'interval'        => -1,
				'maxretries'      => -1,
				'retry_interval'  => 1,
				'timeout'         => -1,
				'method'          => 'get',
				'type'            => 'useglobal',
				'up_status_codes' => 'useglobal',
				'issub'           => 0,
			]
		);

		$this->monitor_id = (int) $wpdb->insert_id;

		return $this->monitor_id;
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

	/**
	 * Delete a client field by id.
	 *
	 * A name lookup strips [ and ] from what it queries with, so a bracketed field has to be
	 * cleaned up by id.
	 *
	 * @param int $field_id Field id.
	 * @return void
	 */
	private function delete_client_field_by_id( int $field_id ): void {
		if ( $field_id ) {
			\MainWP\Dashboard\MainWP_DB_Client::instance()->delete_client_field_by( 'field_id', $field_id, 0 );
		}
	}
}
