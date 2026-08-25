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

		$this->delete_client_field( 'REST V2 Cleanup Field' );
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
