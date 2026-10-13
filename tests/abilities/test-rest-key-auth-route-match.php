<?php
/**
 * API keys authenticate only the MainWP route WordPress dispatches.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

class Test_REST_Key_Auth_Route_Match extends MainWP_Abilities_Test_Case {

    private $auth;
    private $original_instance;
    private $original_auth_state = array();
    private $original_server;
    private $original_get;
    private $original_post;
    private $original_cookie;
    private $original_request;
    private $original_query_vars;
    private $original_rest_server;
    private $owner_id;
    private $credentials;

    public function setUp(): void {
        parent::setUp();

        $this->original_server      = $_SERVER;
        $this->original_get         = $_GET;
        $this->original_post        = $_POST;
        $this->original_cookie      = $_COOKIE;
        $this->original_request     = $_REQUEST;
        $this->original_query_vars  = $GLOBALS['wp']->query_vars;
        $this->original_rest_server = $GLOBALS['wp_rest_server'];
        $this->original_instance    = \MainWP_REST_Authentication::$instance;

        // Other harnesses reset the static instance while core restores the original hooks.
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
        $this->owner_id    = $this->set_current_user_as_admin();
        $this->credentials = $this->create_rest_api_key( $this->owner_id );
    }

    public function tearDown(): void {
        remove_filter( 'rest_url_prefix', array( $this, 'custom_rest_prefix' ) );
        remove_filter( 'mainwp_rest_is_request_to_rest_api', '__return_false' );
        foreach ( $this->original_auth_state as $name => $value ) {
            $property = new \ReflectionProperty( $this->auth, $name );
            $property->setAccessible( true );
            $property->setValue( $this->auth, $value );
        }
        \MainWP_REST_Authentication::$instance = $this->original_instance;
        $_SERVER                             = $this->original_server;
        $_GET                                = $this->original_get;
        $_POST                               = $this->original_post;
        $_COOKIE                             = $this->original_cookie;
        $_REQUEST                            = $this->original_request;
        $GLOBALS['wp']->query_vars            = $this->original_query_vars;
        $GLOBALS['wp_rest_server']            = $this->original_rest_server;
        wp_set_current_user( 0 );

        parent::tearDown();
    }

    public function custom_rest_prefix(): string {
        return 'api';
    }

    private function prepare_anonymous_request( string $uri, ?string $route ): void {
        foreach ( array( 'user' => null, 'error' => null, 'auth_method' => '' ) as $name => $value ) {
            $property = new \ReflectionProperty( $this->auth, $name );
            $property->setAccessible( true );
            $property->setValue( $this->auth, $value );
        }
        wp_set_current_user( 0 );
        $GLOBALS['current_user'] = null;
        $_GET                   = array();
        $_POST                  = array();
        parse_str( (string) wp_parse_url( $uri, PHP_URL_QUERY ), $_GET );
        $_SERVER['HTTPS']         = 'on';
        $_SERVER['REQUEST_URI']   = $uri;
        $_SERVER['PHP_AUTH_USER'] = $this->credentials['consumer_key'];
        $_SERVER['PHP_AUTH_PW']   = $this->credentials['consumer_secret'];
        unset( $GLOBALS['wp']->query_vars['rest_route'] );
        if ( null !== $route ) {
            $GLOBALS['wp']->query_vars['rest_route'] = $route;
        }
    }

    public function test_authenticate_uses_the_dispatched_namespace_for_all_request_shapes(): void {
        $shapes = array(
            'pretty permalink' => array( '/wp-json/mainwp/v2/sites', '/mainwp/v2/sites', true ),
            'index permalink' => array( '/index.php/wp-json/mainwp/v2/sites', '/mainwp/v2/sites', true ),
            'subdirectory' => array( '/blog/wp-json/mainwp/v2/sites', '/mainwp/v2/sites', true ),
            'custom prefix' => array( '/api/mainwp/v2/sites', '/mainwp/v2/sites', true ),
            'plain permalink' => array( '/?rest_route=/mainwp/v2/sites', '/mainwp/v2/sites', true ),
            'extension' => array( '/wp-json/mainwp-backwpup/v1/download/x', '/mainwp-backwpup/v1/download/x', true ),
            'mixed case' => array( '/wp-json/MainWP/v2/sites', '/MainWP/v2/sites', true ),
            'missing leading slash' => array( '/wp-json/mainwp/v2/sites', 'mainwp/v2/sites', true ),
            'trimmed route' => array( '/?rest_route=/mainwp/v2/sites', ' /mainwp/v2/sites ', true ),
            'core query' => array( '/wp-json/wp/v2/users?x=/wp-json/mainwp/', '/wp/v2/users', false ),
            'abilities' => array( '/wp-json/wp-abilities/v1/abilities/mainwp/list-sites-v1', '/wp-abilities/v1/abilities/mainwp/list-sites-v1', false ),
            'admin ajax' => array( '/wp-admin/admin-ajax.php?action=x&x=/wp-json/mainwp/', null, false ),
            'admin post' => array( '/wp-admin/admin-post.php?action=x&x=/wp-json/mainwp/', null, false ),
            'site root' => array( '/?x=/wp-json/mainwp/', null, false ),
            'cron' => array( '/wp-cron.php?x=/wp-json/mainwp/', null, false ),
            'nested namespace' => array( '/wp-json/wp/v2/wp-json/mainwp/v2/sites', '/wp/v2/wp-json/mainwp/v2/sites', false ),
            'encoded query' => array( '/wp-json/wp/v2/users?x=%2Fwp-json%2Fmainwp%2F', '/wp/v2/users', false ),
            'query override' => array( '/wp-json/mainwp/v2/sites?rest_route=/wp/v2/users/me', '/wp/v2/users/me', false ),
            'post override' => array( '/wp-json/mainwp/v2/sites', '/wp/v2/users/me', false ),
            'empty route' => array( '/wp-json/mainwp/v2/sites', '', false ),
            'filter opt out' => array( '/wp-json/mainwp/v2/sites', '/mainwp/v2/sites', false ),
        );
        $expected = array();
        $actual   = array();
        foreach ( $shapes as $name => list( $uri, $route, $allowed ) ) {
            $this->prepare_anonymous_request( $uri, $route );
            if ( 'custom prefix' === $name ) {
                add_filter( 'rest_url_prefix', array( $this, 'custom_rest_prefix' ) );
            }
            if ( 'post override' === $name ) {
                $_POST['rest_route'] = '/wp/v2/users/me';
            }
            if ( 'filter opt out' === $name ) {
                add_filter( 'mainwp_rest_is_request_to_rest_api', '__return_false' );
            }
            $expected[ $name ] = $allowed ? $this->owner_id : 0;
            $actual[ $name ]   = (int) $this->auth->authenticate( false );
            remove_filter( 'rest_url_prefix', array( $this, 'custom_rest_prefix' ) );
            remove_filter( 'mainwp_rest_is_request_to_rest_api', '__return_false' );
        }
        $this->assertSame( $expected, $actual, 'Only the dispatched namespace may authenticate the key owner.' );
    }

    public function test_real_hooks_authenticate_mainwp_and_leave_core_anonymous(): void {
        // Core's cookie check returns true even for anonymous requests without a nonce.
        $cookie_priority = has_filter( 'rest_authentication_errors', 'rest_cookie_check_errors' );
        if ( false !== $cookie_priority ) {
            remove_filter( 'rest_authentication_errors', 'rest_cookie_check_errors', $cookie_priority );
        }
        try {
            $this->prepare_anonymous_request( '/wp-json/mainwp/v2/sites', '/mainwp/v2/sites' );
            $this->assertSame( $this->owner_id, (int) apply_filters( 'determine_current_user', false ) );
            $error = apply_filters( 'rest_authentication_errors', null );
            $this->assertSame( $this->owner_id, get_current_user_id() );
            $this->assertNotWPError( $error );

            $this->prepare_anonymous_request( '/wp-json/wp/v2/users?x=/wp-json/mainwp/', '/wp/v2/users' );
            $this->assertSame( 0, (int) apply_filters( 'determine_current_user', false ) );
            $error = apply_filters( 'rest_authentication_errors', null );
            $this->assertSame( 0, get_current_user_id() );
            $this->assertNull( $error );

            // An earlier MainWP error must not leak into a foreign REST request.
            $property = new \ReflectionProperty( $this->auth, 'error' );
            $property->setAccessible( true );
            $property->setValue( $this->auth, new \WP_Error( 'mainwp_rest_authentication_error', 'Earlier authentication failed.', array( 'status' => 401 ) ) );
            $this->assertNull( apply_filters( 'rest_authentication_errors', null ) );
        } finally {
            if ( false !== $cookie_priority ) {
                add_filter( 'rest_authentication_errors', 'rest_cookie_check_errors', $cookie_priority );
            }
        }
    }

    public function test_authentication_waits_for_parse_request_and_retries_inside_rest(): void {
        $this->prepare_anonymous_request( '/wp-json/mainwp/v2/sites', null );
        $_GET['rest_route']  = '/mainwp/v2/sites';
        $_POST['rest_route'] = '/mainwp/v2/sites';
        $this->assertFalse( apply_filters( 'determine_current_user', false ) );
        $this->assertSame( 0, $this->auth->authenticate( 0 ) );
        $this->assertSame( 0, get_current_user_id() );

        $GLOBALS['wp']->query_vars['rest_route'] = '/mainwp/v2/sites';
        // serve_request() clears the cached anonymous user before checking authentication.
        $GLOBALS['current_user'] = null;
        $this->assertNotWPError( apply_filters( 'rest_authentication_errors', null ) );
        $this->assertSame( $this->owner_id, get_current_user_id() );
    }

    public function test_late_bearer_authentication_takes_precedence_over_a_logged_in_cookie(): void {
        $other_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
        $cookie_users  = array(
            'same owner'      => $this->owner_id,
            'different user'  => $other_user_id,
        );

        foreach ( $cookie_users as $scenario => $cookie_user_id ) {
            $this->prepare_anonymous_request( '/wp-json/mainwp/v2/sites/count', null );
            unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $_SERVER['HTTP_X_WP_NONCE'], $_REQUEST['_wpnonce'] );
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . substr( $this->credentials['consumer_secret'], 3 ) . '==' . substr( $this->credentials['consumer_key'], 3 );
            $_COOKIE[ LOGGED_IN_COOKIE ]    = wp_generate_auth_cookie( $cookie_user_id, time() + HOUR_IN_SECONDS, 'logged_in' );

            $this->assertSame( $cookie_user_id, wp_get_current_user()->ID, $scenario );
            $this->assertNull( $this->auth->get_rest_valid_user(), $scenario );

            $GLOBALS['wp']->query_vars['rest_route'] = '/mainwp/v2/sites/count';
            $this->assertTrue( apply_filters( 'rest_authentication_errors', null ), $scenario );
            $this->assertSame( $this->owner_id, get_current_user_id(), $scenario );
            $this->assertSame( $this->owner_id, (int) $this->auth->get_rest_valid_user()->user_id, $scenario );
            $this->assertTrue( $this->auth->is_valid_permissions( new \WP_REST_Request( 'GET', '/mainwp/v2/sites/count' ) ), $scenario );
        }
    }

    public function test_pre_dispatch_refuses_foreign_subrequests_before_access_logging(): void {
        global $wpdb;
        $this->prepare_anonymous_request( '/wp-json/mainwp/v2/sites', '/mainwp/v2/sites' );
        $this->assertSame( $this->owner_id, (int) $this->auth->authenticate( false ) );
        $key = $this->auth->get_rest_valid_user();
        $wpdb->update( $wpdb->prefix . 'mainwp_api_keys', array( 'last_access' => '2000-01-01 00:00:00' ), array( 'key_id' => $key->key_id ) );
        $doing_before = defined( 'MAINWP_REST_API_DOING' );

        $request = new \WP_REST_Request( 'GET', '/wp/v2/users/me' );
        $error   = $this->auth->check_user_permissions( null, rest_get_server(), $request );
        $this->assertWPError( $error );
        $this->assertSame( 'mainwp_rest_authentication_error', $error->get_error_code() );
        $this->assertSame( 401, $error->get_error_data()['status'] );
        $this->assertSame( $doing_before, defined( 'MAINWP_REST_API_DOING' ) );
        $this->assertSame( '2000-01-01 00:00:00', $wpdb->get_var( $wpdb->prepare( "SELECT last_access FROM {$wpdb->prefix}mainwp_api_keys WHERE key_id = %d", $key->key_id ) ) );

        foreach ( array( '/mainwp/v2/sites/count', '/mainwp-backwpup/v1/download/x', '/MainWP/v2/sites/count' ) as $route ) {
            $this->assertNull( $this->auth->check_user_permissions( null, rest_get_server(), new \WP_REST_Request( 'GET', $route ) ), $route );
        }
    }
}
