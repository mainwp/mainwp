<?php
/**
 * A failed v2 key attempt on a mainwp/v1 route leaves the route's own v1 key check in charge.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\Rest_Api_V1;

class Test_REST_Key_Auth_V1_Routes extends MainWP_Abilities_Test_Case {

    const V1_ROUTE = '/mainwp/v1/sites/all-sites-count';

    private $auth;
    private $original_instance;
    private $original_auth_state = array();
    private $original_server;
    private $original_get;
    private $original_post;
    private $original_query_vars;
    private $original_rest_server;
    private $original_v1_keys;
    private $cookie_priority;
    private $created_keys = array();

    public function setUp(): void {
        parent::setUp();

        $this->original_server      = $_SERVER;
        $this->original_get         = $_GET;
        $this->original_post        = $_POST;
        $this->original_query_vars  = $GLOBALS['wp']->query_vars;
        $this->original_rest_server = $GLOBALS['wp_rest_server'];
        $this->original_instance    = \MainWP_REST_Authentication::$instance;
        $this->original_v1_keys     = get_option( 'mainwp_rest_api_keys', false );

        // Core restores the hooked instance even when another harness resets the singleton.
        foreach ( $GLOBALS['wp_filter']['determine_current_user']->callbacks[15] as $callback ) {
            $function = $callback['function'];
            if ( is_array( $function ) && $function[0] instanceof \MainWP_REST_Authentication && 'authenticate' === $function[1] ) {
                $this->auth = $function[0];
                break;
            }
        }
        $this->assertInstanceOf( \MainWP_REST_Authentication::class, $this->auth );
        \MainWP_REST_Authentication::$instance = $this->auth;

        foreach ( array( 'user', 'error', 'auth_method' ) as $name ) {
            $property = new \ReflectionProperty( $this->auth, $name );
            $property->setAccessible( true );
            $this->original_auth_state[ $name ] = $property->getValue( $this->auth );
        }

        // Cookie authentication returns true for anonymous requests without a nonce.
        $this->cookie_priority = has_filter( 'rest_authentication_errors', 'rest_cookie_check_errors' );
        if ( false !== $this->cookie_priority ) {
            remove_filter( 'rest_authentication_errors', 'rest_cookie_check_errors', $this->cookie_priority );
        }
    }

    public function tearDown(): void {
        global $wpdb;

        foreach ( $this->created_keys as $credentials ) {
            $hashed = mainwp_api_hash( $credentials['consumer_key'] );
            $wpdb->delete( MainWP_DB::instance()->get_table_name( 'api_keys' ), array( 'consumer_key' => $hashed ), array( '%s' ) );
            wp_cache_delete( 'mainwp_api_key_' . md5( $hashed ) );
        }
        if ( false === $this->original_v1_keys ) {
            delete_option( 'mainwp_rest_api_keys' );
        } else {
            update_option( 'mainwp_rest_api_keys', $this->original_v1_keys );
        }
        if ( false !== $this->cookie_priority ) {
            add_filter( 'rest_authentication_errors', 'rest_cookie_check_errors', $this->cookie_priority );
        }
        foreach ( $this->original_auth_state as $name => $value ) {
            $property = new \ReflectionProperty( $this->auth, $name );
            $property->setAccessible( true );
            $property->setValue( $this->auth, $value );
        }
        \MainWP_REST_Authentication::$instance = $this->original_instance;
        $_SERVER                             = $this->original_server;
        $_GET                                = $this->original_get;
        $_POST                               = $this->original_post;
        $GLOBALS['wp']->query_vars            = $this->original_query_vars;
        $GLOBALS['wp_rest_server']            = $this->original_rest_server;
        wp_set_current_user( 0 );

        parent::tearDown();
    }

    /**
     * Store a key only in the v1 option, the shape the API Access page writes.
     */
    private function create_v1_only_key(): array {
        $credentials = array(
            'consumer_key'    => 'ck_' . bin2hex( random_bytes( 20 ) ),
            'consumer_secret' => 'cs_' . bin2hex( random_bytes( 20 ) ),
        );
        update_option(
            'mainwp_rest_api_keys',
            array(
                $credentials['consumer_key'] => array(
                    'ck_hashed' => wp_hash_password( $credentials['consumer_key'] ),
                    'cs'        => wp_hash_password( $credentials['consumer_secret'] ),
                    'desc'      => 'v1 only',
                    'enabled'   => 1,
                    'perms'     => 'r',
                ),
            )
        );
        return $credentials;
    }

    private function create_v2_key( int $user_id ): array {
        $credentials          = $this->create_rest_api_key( $user_id );
        $this->created_keys[] = $credentials;
        return $credentials;
    }

    /**
     * v1 clients send the key as consumer_key and consumer_secret query parameters.
     */
    private function prepare_query_key_request( array $credentials, string $route, bool $https = true ): void {
        foreach ( array( 'user' => null, 'error' => null, 'auth_method' => '' ) as $name => $value ) {
            $property = new \ReflectionProperty( $this->auth, $name );
            $property->setAccessible( true );
            $property->setValue( $this->auth, $value );
        }
        wp_set_current_user( 0 );
        $GLOBALS['current_user'] = null;
        $_POST                   = array();
        $_GET                    = $credentials;
        unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $_SERVER['HTTPS'] );
        if ( $https ) {
            $_SERVER['HTTPS'] = 'on';
        }
        // Not localhost, so plain HTTP is not treated as secure.
        $_SERVER['REMOTE_ADDR']                 = '203.0.113.10';
        $_SERVER['REQUEST_METHOD']              = 'GET';
        $_SERVER['REQUEST_URI']                 = '/wp-json' . $route . '?' . http_build_query( $credentials, '', '&', PHP_QUERY_RFC3986 );
        $GLOBALS['wp']->query_vars['rest_route'] = $route;
    }

    private function run_authentication_hooks() {
        // Core sets the filtered user ID before checking REST authentication errors.
        wp_set_current_user( (int) apply_filters( 'determine_current_user', false ) );
        return apply_filters( 'rest_authentication_errors', null );
    }

    private function dispatch_v1_route( array $credentials ): \WP_REST_Response {
        $GLOBALS['wp_rest_server'] = new \WP_REST_Server();
        do_action( 'rest_api_init' );
        Rest_Api_V1::instance()->mainwp_register_routes();
        $this->assertArrayHasKey( self::V1_ROUTE, rest_get_server()->get_routes() );

        $request = new \WP_REST_Request( 'GET', self::V1_ROUTE );
        $request->set_query_params( $credentials );
        return rest_do_request( $request );
    }

    public static function v1_route_shapes(): array {
        return array(
            'https'                 => array( self::V1_ROUTE, true ),
            'plain http'            => array( self::V1_ROUTE, false ),
            'mixed case'            => array( '/MainWP/V1/sites/all-sites-count', true ),
            'missing leading slash' => array( 'mainwp/v1/sites/all-sites-count', true ),
        );
    }

    /**
     * @dataProvider v1_route_shapes
     */
    public function test_v1_only_key_authenticates_through_the_v1_path( string $route, bool $https ): void {
        $credentials = $this->create_v1_only_key();
        $this->prepare_query_key_request( $credentials, $route, $https );

        $this->assertNull( $this->run_authentication_hooks() );
        $this->assertSame( 0, get_current_user_id() );
        $this->assertNull( $this->auth->get_rest_valid_user() );

        $response = $this->dispatch_v1_route( $credentials );
        $this->assertSame( 200, $response->get_status() );
        $this->assertArrayHasKey( 'count', $response->get_data() );
    }

    public function test_v2_key_on_a_v1_route_still_authenticates_its_owner(): void {
        $owner_id    = $this->set_current_user_as_admin();
        $credentials = $this->create_v2_key( $owner_id );
        $this->prepare_query_key_request( $credentials, self::V1_ROUTE );

        $this->assertNotWPError( $this->run_authentication_hooks() );
        $this->assertSame( $owner_id, get_current_user_id() );
        $this->assertSame( $owner_id, (int) $this->auth->get_rest_valid_user()->user_id );
    }

    public function test_failed_v2_key_on_a_v1_route_is_left_to_the_v1_check(): void {
        $credentials = $this->create_v2_key( $this->set_current_user_as_admin() );
        $wrong       = array(
            'consumer_key'    => $credentials['consumer_key'],
            'consumer_secret' => 'cs_' . bin2hex( random_bytes( 20 ) ),
        );
        $this->prepare_query_key_request( $wrong, self::V1_ROUTE );

        $this->assertNull( $this->run_authentication_hooks() );
        $this->assertSame( 0, get_current_user_id() );
        $this->assertNull( $this->auth->get_rest_valid_user() );
        $this->assertSame( 401, $this->dispatch_v1_route( $wrong )->get_status() );
    }

    public static function non_v1_routes(): array {
        return array(
            'v2'         => array( '/mainwp/v2/sites/count' ),
            'extension'  => array( '/mainwp-backwpup/v1/download/x' ),
            'v1 lookalike' => array( '/mainwp/v10/sites/all-sites-count' ),
        );
    }

    /**
     * @dataProvider non_v1_routes
     */
    public function test_other_mainwp_routes_still_report_v2_failures( string $route ): void {
        $credentials = $this->create_v1_only_key();

        $this->prepare_query_key_request( $credentials, $route );
        $error = $this->run_authentication_hooks();
        $this->assertWPError( $error );
        $this->assertSame( 'mainwp_rest_authentication_disabled_key', $error->get_error_code() );
        $this->assertSame( 0, get_current_user_id() );

        $this->prepare_query_key_request( $credentials, $route, false );
        $error = $this->run_authentication_hooks();
        $this->assertWPError( $error );
        $this->assertSame( 'mainwp_rest_authentication_bad_request', $error->get_error_code() );
        $this->assertSame( 400, $error->get_error_data()['status'] );
    }
}
