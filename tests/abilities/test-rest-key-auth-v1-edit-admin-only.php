<?php
/**
 * Legacy key edits require an unrestricted administrator.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_System_Utility;

/** Tests the registered edit route with authenticated key owners. */
class Test_REST_Key_Auth_V1_Edit_Admin_Only extends MainWP_Abilities_Test_Case {

	/**
	 * Hooked authentication instance.
	 *
	 * @var \MainWP_REST_Authentication
	 */
	private $auth;
	/**
	 * State restored after each request fixture.
	 *
	 * @var array
	 */
	private $original = array();
	/**
	 * Original authentication properties.
	 *
	 * @var array
	 */
	private $auth_state = array();
	/**
	 * Credentials created by this test.
	 *
	 * @var array
	 */
	private $created_keys = array();
	/**
	 * Whether the fixture registered its role.
	 *
	 * @var bool
	 */
	private $added_role = false;

	/** Prepare isolated authentication and REST state. */
	public function setUp(): void {
		parent::setUp();
		global $wp_filter, $wpdb;

		$this->original = array(
			'hooks'       => array_map(
				static function ( $hook ) {
					return clone $hook; },
				$wp_filter
			),
			'instance'    => \MainWP_REST_Authentication::$instance,
			'server'      => $_SERVER,
			'get'         => $_GET,
			'post'        => $_POST,
			'query_vars'  => $GLOBALS['wp']->query_vars,
			'rest_server' => $GLOBALS['wp_rest_server'],
			'user_id'     => get_current_user_id(),
			'legacy_row'  => $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->options} WHERE option_name = %s", 'mainwp_rest_api_keys' ), ARRAY_A ),
			'rest_doing'  => defined( 'MAINWP_REST_API_DOING' ),
		);
		foreach ( $wp_filter['determine_current_user']->callbacks[15] as $callback ) {
			$function = $callback['function'];
			if ( is_array( $function ) && $function[0] instanceof \MainWP_REST_Authentication && 'authenticate' === $function[1] ) {
				$this->auth = $function[0];
				break;
			}
		}
		$this->assertInstanceOf( \MainWP_REST_Authentication::class, $this->auth );
		\MainWP_REST_Authentication::$instance = $this->auth;
		foreach ( array( 'user', 'error', 'auth_method', 'rest_requests' ) as $name ) {
			$property = new \ReflectionProperty( $this->auth, $name );
			$property->setAccessible( true );
			$this->auth_state[ $name ] = $property->getValue( $this->auth );
			$property->setValue( $this->auth, 'rest_requests' === $name ? array() : ( 'auth_method' === $name ? '' : null ) );
		}

		// This bookkeeping hook defines a permanent capability bypass; the real route still checks key permissions.
		remove_filter( 'rest_pre_dispatch', array( $this->auth, 'check_user_permissions' ), 10 );
		add_filter( 'mainwp_currentuserallowedaccesssites', array( $this, 'all_scope' ), 999 );
		add_filter( 'mainwp_currentuserallowedaccessgroups', array( $this, 'all_scope' ), 999 );
		add_filter( 'mainwp_currentusercan', array( $this, 'edit_right' ), 999, 3 );
		$GLOBALS['wp_rest_server'] = new \WP_REST_Server();
		do_action( 'rest_api_init' );
		( new \MainWP_Rest_API_Keys_Controller() )->register_routes();
	}

	/** Restore fixtures without retaining credentials or bypassing later tests. */
	public function tearDown(): void {
		global $wpdb, $wp_filter;

		foreach ( $this->created_keys as $credentials ) {
			$hashed = mainwp_api_hash( $credentials['consumer_key'] );
			$wpdb->delete( MainWP_DB::instance()->get_table_name( 'api_keys' ), array( 'consumer_key' => $hashed ), array( '%s' ) );
			\MainWP_REST_Authentication::delete_api_key_cache( $hashed );
		}
		delete_option( 'mainwp_rest_api_keys' );
		if ( null !== $this->original['legacy_row'] ) {
			$wpdb->insert( $wpdb->options, $this->original['legacy_row'] );
		}
		wp_cache_delete( 'mainwp_rest_api_keys', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		if ( $this->added_role ) {
			remove_role( 'mainwp_legacy_edit_fixture' );
		}
		foreach ( $this->auth_state as $name => $value ) {
			$property = new \ReflectionProperty( $this->auth, $name );
			$property->setAccessible( true );
			$property->setValue( $this->auth, $value );
		}
		\MainWP_REST_Authentication::$instance = $this->original['instance'];
		$_SERVER                               = $this->original['server'];
		$_GET                                  = $this->original['get'];
		$_POST                                 = $this->original['post'];
		$GLOBALS['wp']->query_vars             = $this->original['query_vars'];
		$GLOBALS['wp_rest_server']             = $this->original['rest_server'];
		$wp_filter                             = $this->original['hooks']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the exact hooks used by other tests.
		wp_set_current_user( $this->original['user_id'] );

		parent::tearDown();
	}

	/**
	 * Return unrestricted site and tag access.
	 *
	 * @return string
	 */
	public function all_scope(): string {
		return 'all';
	}

	/**
	 * Grant only the key edit operation.
	 *
	 * @param bool   $allowed Existing permission.
	 * @param string $type Capability group.
	 * @param string $right Requested operation.
	 * @return bool
	 */
	public function edit_right( $allowed, $type, $right ): bool {
		return 'rest_api' === $type && 'edit_rest_api_keys' === $right;
	}

	/**
	 * Authenticate real credentials and select the key owner as WordPress does.
	 *
	 * @param bool   $staff Whether to use a Team Control role.
	 * @param string $route Request route.
	 * @param string $method HTTP method.
	 * @return void
	 */
	private function authenticate_owner( bool $staff, string $route, string $method ): void {
		if ( $staff ) {
			$this->added_role = null !== add_role(
				'mainwp_legacy_edit_fixture',
				'Legacy edit fixture',
				array(
					'read'     => true,
					'level_10' => true,
				)
			);
			$owner_id         = self::factory()->user->create( array( 'role' => 'mainwp_legacy_edit_fixture' ) );
		} else {
			$owner_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		}
		$credentials          = $this->create_rest_api_key( $owner_id, 'read_write' );
		$this->created_keys[] = $credentials;
		wp_set_current_user( 0 );
		$_GET  = array();
		$_POST = array();
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		$_SERVER['HTTPS']                        = 'on';
		$_SERVER['REQUEST_METHOD']               = $method;
		$_SERVER['REQUEST_URI']                  = '/wp-json' . $route;
		$_SERVER['PHP_AUTH_USER']                = $credentials['consumer_key'];
		$_SERVER['PHP_AUTH_PW']                  = $credentials['consumer_secret'];
		$GLOBALS['wp']->query_vars['rest_route'] = $route;
		wp_set_current_user( (int) apply_filters( 'determine_current_user', false ) );
		$this->assertSame( $owner_id, get_current_user_id() );
		$this->assertSame( $owner_id, (int) $this->auth->get_rest_valid_user()->user_id );
		$this->assertSame( $staff, MainWP_System_Utility::is_team_control_staff() );
		$this->assertTrue( MainWP_System_Utility::is_admin() );
		$this->assertSame( ! $staff, current_user_can( 'manage_options' ) );
		$this->assertSame( ! $staff, in_array( 'administrator', wp_get_current_user()->roles, true ) );
	}

	/**
	 * Store a disabled read-only legacy key.
	 *
	 * @return string
	 */
	private function legacy_key(): string {
		$identifier          = 'ck_' . bin2hex( random_bytes( 20 ) );
		$keys                = get_option( 'mainwp_rest_api_keys', array() );
		$keys[ $identifier ] = array(
			'cs'      => 'cs_' . bin2hex( random_bytes( 20 ) ),
			'perms'   => 'r',
			'enabled' => 0,
			'desc'    => 'Legacy fixture',
		);
		update_option( 'mainwp_rest_api_keys', $keys );
		return $identifier;
	}

	/**
	 * Read stored bytes without option decoding or cache access.
	 *
	 * @return string|null
	 */
	private function legacy_option_bytes(): ?string {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'mainwp_rest_api_keys' ) );
	}

	/**
	 * Dispatch the registered route and preserve the process capability state.
	 *
	 * @param string $method HTTP method.
	 * @param string $identifier Target key identifier.
	 * @return \WP_REST_Response
	 */
	private function dispatch_edit( string $method, string $identifier ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, '/mainwp/v2/rest-api/edit-key/' . $identifier );
		$request->set_body_params(
			array(
				'description' => 'Edited fixture',
				'active'      => true,
				'permissions' => 'read,write',
			)
		);
		$response = rest_do_request( $request );
		$this->assertSame( $this->original['rest_doing'], defined( 'MAINWP_REST_API_DOING' ), 'The fixture must not define a permanent capability bypass.' );
		return $response;
	}

	/**
	 * HTTP methods accepted by the edit route.
	 *
	 * @return array
	 */
	public static function edit_methods(): array {
		return array(
			'PUT'   => array( 'PUT' ),
			'PATCH' => array( 'PATCH' ),
		);
	}

	/**
	 * Staff with all sites and the edit right cannot change legacy keys.
	 *
	 * @dataProvider edit_methods
	 * @param string $method HTTP method.
	 */
	public function test_staff_cannot_edit_legacy_key( string $method ): void {
		$identifier = $this->legacy_key();
		$this->authenticate_owner( true, '/mainwp/v2/rest-api/edit-key/' . $identifier, $method );
		$before   = $this->legacy_option_bytes();
		$response = $this->dispatch_edit( $method, $identifier );
		$key      = get_option( 'mainwp_rest_api_keys' )[ $identifier ];
		$this->assertTrue( $before === $this->legacy_option_bytes(), 'Legacy option bytes changed: ' . wp_json_encode( array_intersect_key( $key, array_flip( array( 'desc', 'enabled', 'perms' ) ) ) ) );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'mainwp_restricted_account', $response->get_data()['code'] );
	}

	/** Unrestricted administrators retain legacy key edits. */
	public function test_administrator_can_edit_legacy_key(): void {
		$identifier = $this->legacy_key();
		$this->authenticate_owner( false, '/mainwp/v2/rest-api/edit-key/' . $identifier, 'PUT' );
		$response = $this->dispatch_edit( 'PUT', $identifier );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['success'] );
		$key = get_option( 'mainwp_rest_api_keys' )[ $identifier ];
		$this->assertSame( 'Edited fixture', $key['desc'] );
		$this->assertSame( 1, $key['enabled'] );
		$this->assertSame( 'r,w,d', $key['perms'] );
	}

	/** Staff retain edits of normal v2 keys within their rights. */
	public function test_staff_can_edit_v2_key(): void {
		$this->authenticate_owner( true, '/mainwp/v2/rest-api/edit-key/1', 'PUT' );
		$caller     = $this->auth->get_rest_valid_user();
		$identifier = (string) $caller->key_id;
		$response   = $this->dispatch_edit( 'PUT', $identifier );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['success'] );
		$key = MainWP_DB::instance()->get_rest_api_key_by( $caller->key_id );
		$this->assertSame( 'Edited fixture', $key->description );
		$this->assertSame( 1, (int) $key->enabled );
		$this->assertSame( 'read_write', $key->permissions );
	}
}
