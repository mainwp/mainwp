<?php
/**
 * SEC-35: REST v2 routes must not tell a site-scoped API key that a site,
 * client field or tag outside its scope exists.
 *
 * Each case dispatches the real route twice: once with the Team Control
 * stand-in filters narrowing the key to an allowed set, once unrestricted as
 * the control that keeps the full-access output unchanged.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB_Client;
use WP_REST_Request;
use WP_REST_Server;

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- test classes use PSR-style Test_* filenames, not the mainwp-* convention.

/**
 * Class Test_SEC35_REST_Scope_Existence
 */
class Test_SEC35_REST_Scope_Existence extends \WP_Test_REST_TestCase {

	use Site_Fixture;

	/**
	 * Description of the API key rows this class creates.
	 *
	 * @var string
	 */
	const KEY_DESCRIPTION = 'SEC-35 Test API Key';

	/**
	 * Prefix of every tag and client field name this class creates.
	 *
	 * @var string
	 */
	const PREFIX = 'SEC35 ';

	/**
	 * REST server instance.
	 *
	 * @var WP_REST_Server
	 */
	protected $server;

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
	 * Scope filter callbacks added by the current test.
	 *
	 * @var array
	 */
	protected $scope_filters = array();

	/**
	 * Same REST server reset as Test_REST_V2_Cleanup_Round_5.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();

		$reflection           = new \ReflectionClass( \MainWP_Rest_Server::class );
		$controllers_property = $reflection->getProperty( 'controllers' );
		$controllers_property->setAccessible( true );
		$controllers_property->setValue( \MainWP_Rest_Server::instance(), array() );

		\MainWP_REST_Authentication::$instance = null;

		if ( ! has_action( 'rest_api_init', array( \MainWP_Rest_Server::instance(), 'register_rest_routes' ) ) ) {
			add_action( 'rest_api_init', array( \MainWP_Rest_Server::instance(), 'register_rest_routes' ), 10 );
		}

		global $wp_rest_server;
		$this->server = $wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$api_key               = $this->create_rest_api_key( $admin_id );
		$this->consumer_key    = $api_key['consumer_key'];
		$this->consumer_secret = $api_key['consumer_secret'];
	}

	/**
	 * Tear down test environment.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wpdb, $wp_rest_server;
		$wp_rest_server = null;

		foreach ( $this->scope_filters as $hook => $callback ) {
			remove_filter( $hook, $callback, 999 );
		}
		$this->scope_filters = array();

		\MainWP_REST_Authentication::$instance = null;

		$reflection = new \ReflectionClass( \MainWP_Rest_Server::class );
		$property   = $reflection->getProperty( 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );

		$like = $wpdb->esc_like( self::PREFIX ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}mainwp_api_keys WHERE description = %s", self::KEY_DESCRIPTION ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}mainwp_group WHERE name LIKE %s", $like ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}mainwp_wp_clients_fields WHERE field_name LIKE %s", '[' . $like ) );

		parent::tearDown();
	}

	/**
	 * Create a REST API key for testing.
	 *
	 * @param int $user_id User ID to associate with the key.
	 * @return array Array with 'consumer_key' and 'consumer_secret'.
	 */
	protected function create_rest_api_key( int $user_id ): array {
		global $wpdb;

		$consumer_key    = 'ck_' . bin2hex( random_bytes( 16 ) );
		$consumer_secret = 'cs_' . bin2hex( random_bytes( 16 ) );

		$wpdb->insert(
			$wpdb->prefix . 'mainwp_api_keys',
			array(
				'user_id'         => $user_id,
				'description'     => self::KEY_DESCRIPTION,
				'permissions'     => 'read_write',
				'consumer_key'    => mainwp_api_hash( $consumer_key ),
				'consumer_secret' => $consumer_secret,
				'truncated_key'   => substr( $consumer_key, -7 ),
				'enabled'         => 1,
				'last_access'     => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		return array(
			'consumer_key'    => $consumer_key,
			'consumer_secret' => $consumer_secret,
		);
	}

	/**
	 * Narrow the key to these sites and tags, as Team Control does.
	 *
	 * @param int[] $site_ids  Allowed site IDs.
	 * @param int[] $group_ids Allowed tag IDs.
	 * @return void
	 */
	protected function scope( array $site_ids, array $group_ids ): void {
		foreach ( $this->scope_filters as $hook => $callback ) {
			remove_filter( $hook, $callback, 999 );
		}
		$this->scope_filters = array(
			'mainwp_currentuserallowedaccesssites'  => static fn() => $site_ids,
			'mainwp_currentuserallowedaccessgroups' => static fn() => $group_ids,
		);
		foreach ( $this->scope_filters as $hook => $callback ) {
			add_filter( $hook, $callback, 999 );
		}
	}

	/**
	 * Make an authenticated REST request, as Test_REST_V2_Cleanup_Round_5 does.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  REST route.
	 * @param array  $params Request parameters.
	 * @return array Response data.
	 */
	protected function request( string $method, string $route, array $params = array() ): array {
		$original_get        = $_GET;
		$original_server     = $_SERVER;
		$original_query_vars = $GLOBALS['wp']->query_vars;

		$_GET['consumer_key']                    = $this->consumer_key;
		$_GET['consumer_secret']                 = $this->consumer_secret;
		$_SERVER['HTTPS']                        = 'on';
		$_SERVER['REQUEST_URI']                  = '/wp-json' . $route;
		$GLOBALS['wp']->query_vars['rest_route'] = $route;

		\MainWP_REST_Authentication::$instance = null;
		\MainWP_REST_Authentication::get_instance()->authenticate( 0 );

		$request = new WP_REST_Request( $method, $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}
		$response = rest_do_request( $request );

		$_GET                      = $original_get;
		$_SERVER                   = $original_server;
		$GLOBALS['wp']->query_vars = $original_query_vars;

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return (array) $response->get_data();
	}

	/**
	 * Insert a tag row.
	 *
	 * @param string $name Tag name.
	 * @return int Tag ID.
	 */
	protected function create_tag( string $name ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'mainwp_group',
			array(
				'userid' => get_current_user_id(),
				'name'   => $name,
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Names of the tags this class created, sorted.
	 *
	 * @return string[]
	 */
	protected function tag_names(): array {
		global $wpdb;
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}mainwp_group WHERE name LIKE %s", $wpdb->esc_like( self::PREFIX ) . '%' ) );
		sort( $names );
		return $names;
	}

	/**
	 * sites/add answers "already connected" for a hidden site but leaves out
	 * its id; an in-scope site and a full-access key still get found_id.
	 */
	public function test_sites_add_hides_found_id_of_an_out_of_scope_site(): void {
		$hidden_url = 'https://sec35-hidden-' . wp_generate_uuid4() . '.example.com/';
		$shown_url  = 'https://sec35-shown-' . wp_generate_uuid4() . '.example.com/';
		$hidden     = $this->create_test_site( array( 'url' => $hidden_url ) );
		$shown      = $this->create_test_site( array( 'url' => $shown_url ) );
		$params     = array(
			'adminname' => 'admin',
			'name'      => 'SEC35 site',
		);

		$full = $this->request( 'POST', '/mainwp/v2/sites/add', array( 'url' => $hidden_url ) + $params );
		$this->assertSame( $hidden, (int) $full['found_id'] );
		$this->assertSame( 0, $full['success'] );
		$this->assertStringContainsString( 'already connected', $full['error'] );

		$this->scope( array( $shown ), array() );

		$scoped = $this->request( 'POST', '/mainwp/v2/sites/add', array( 'url' => $hidden_url ) + $params );
		$this->assertArrayNotHasKey( 'found_id', $scoped );
		$this->assertSame( array_diff_key( $full, array( 'found_id' => 0 ) ), $scoped );

		$in_scope = $this->request( 'POST', '/mainwp/v2/sites/add', array( 'url' => $shown_url ) + $params );
		$this->assertSame( $shown, (int) $in_scope['found_id'] );
	}

	/**
	 * clients/fields keeps global fields and fields of clients the key reaches
	 * through an allowed site, and drops fields of every other client.
	 */
	public function test_clients_fields_lists_only_global_and_in_scope_client_fields(): void {
		$shown_client  = 900351;
		$hidden_client = 900352;
		$shown         = $this->create_test_site( array( 'client_id' => $shown_client ) );
		$this->create_test_site( array( 'client_id' => $hidden_client ) );

		foreach ( array(
			'global' => 0,
			'shown'  => $shown_client,
			'hidden' => $hidden_client,
		) as $label => $client_id ) {
			$this->assertNotFalse(
				MainWP_DB_Client::instance()->add_client_field(
					array(
						'field_name' => '[' . self::PREFIX . $label . ']',
						'field_desc' => $label,
						'client_id'  => $client_id,
					)
				)
			);
		}

		$names = static function ( array $body ): array {
			$names = wp_list_pluck( $body['data'], 'description' );
			sort( $names );
			return $names;
		};
		$query = array(
			'search'   => self::PREFIX,
			'per_page' => 100,
		);

		$full = $this->request( 'GET', '/mainwp/v2/clients/fields', $query );
		$this->assertSame( array( 'global', 'hidden', 'shown' ), $names( $full ) );
		$this->assertSame( 3, $full['total'] );

		$this->scope( array( $shown ), array() );
		$scoped = $this->request( 'GET', '/mainwp/v2/clients/fields', $query );
		$this->assertSame( array( 'global', 'shown' ), $names( $scoped ) );
		$this->assertSame( 2, $scoped['total'] );

		// A key that reaches no client still sees the global fields.
		$this->scope( array(), array() );
		$none = $this->request( 'GET', '/mainwp/v2/clients/fields', $query );
		$this->assertSame( array( 'global' ), $names( $none ) );
	}

	/**
	 * tags/add numbers a name against the tags the key can see, so a hidden
	 * tag of the same name does not turn "Foo" into "Foo (1)".
	 */
	public function test_tags_add_does_not_reveal_a_hidden_tag_of_the_same_name(): void {
		$hidden_name = self::PREFIX . 'hidden';
		$shown_name  = self::PREFIX . 'shown';
		$this->create_tag( $hidden_name );
		$shown = $this->create_tag( $shown_name );

		$this->scope( array(), array( $shown ) );
		$this->request( 'POST', '/mainwp/v2/tags/add', array( 'name' => $hidden_name ) );
		$this->assertSame( array( $hidden_name, $hidden_name, $shown_name ), $this->tag_names() );

		// A visible duplicate is still numbered, as before.
		$this->request( 'POST', '/mainwp/v2/tags/add', array( 'name' => $shown_name ) );
		$this->assertSame( array( $hidden_name, $hidden_name, $shown_name, $shown_name . ' (1)' ), $this->tag_names() );
	}

	/**
	 * When a hidden and a visible tag share a name, the visible one still
	 * numbers the new tag, whichever row the database returns first.
	 */
	public function test_tags_add_numbers_after_a_visible_tag_that_shares_a_hidden_name(): void {
		$name = self::PREFIX . 'shared';
		$this->create_tag( $name );
		$shown = $this->create_tag( $name );

		$this->scope( array(), array( $shown ) );
		$this->request( 'POST', '/mainwp/v2/tags/add', array( 'name' => $name ) );
		$this->assertSame( array( $name, $name, $name . ' (1)' ), $this->tag_names() );
	}

	/**
	 * The add-tag ability refuses an existing name, so its check must not
	 * count a tag the caller cannot see.
	 */
	public function test_add_tag_ability_refuses_only_a_visible_existing_name(): void {
		// The ability runs inside REST API key requests, which define this for the rest of the process.
		if ( ! defined( 'MAINWP_REST_API_DOING' ) ) {
			define( 'MAINWP_REST_API_DOING', true );
		}
		$hidden_name = self::PREFIX . 'ability hidden';
		$shown_name  = self::PREFIX . 'ability shown';
		$this->create_tag( $hidden_name );
		$shown = $this->create_tag( $shown_name );
		$this->scope( array(), array( $shown ) );

		$created = \MainWP\Dashboard\MainWP_Abilities_Tags::execute_add_tag( array( 'name' => $hidden_name ) );
		$this->assertNotWPError( $created );
		$this->assertSame( array( $hidden_name, $hidden_name, $shown_name ), $this->tag_names() );

		$refused = \MainWP\Dashboard\MainWP_Abilities_Tags::execute_add_tag( array( 'name' => $shown_name ) );
		$this->assertWPError( $refused );
		$this->assertSame( 'mainwp_already_exists', $refused->get_error_code() );
	}

	/**
	 * A full-access key still numbers every duplicate, hidden from no one.
	 */
	public function test_tags_add_numbers_duplicates_for_a_full_access_key(): void {
		$name = self::PREFIX . 'full';
		$this->create_tag( $name );

		$body = $this->request( 'POST', '/mainwp/v2/tags/add', array( 'name' => $name ) );
		$this->assertSame( 1, $body['success'] );
		$this->assertSame( $name . ' (1)', $body['data']['name'] );
		$this->assertSame( array( $name, $name . ' (1)' ), $this->tag_names() );
	}
}
