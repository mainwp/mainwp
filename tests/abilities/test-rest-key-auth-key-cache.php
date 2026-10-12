<?php
/**
 * REST API key edits and deletions invalidate cached authentication rows.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;

class Test_REST_Key_Auth_Key_Cache extends MainWP_Abilities_Test_Case {

    private $auth;
    private $original_instance;
    private $original_auth_state = array();
    private $original_server;
    private $original_get;
    private $original_post;
    private $original_query_vars;
    private $original_rest_server;
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
            wp_cache_delete( 'mainwp_api_key_' . md5( $hashed ) ); // NOSONAR - MD5 used for cache key generation only, not cryptographic (security) purposes.
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

    private function create_key( int $user_id ): array {
        $credentials         = $this->create_rest_api_key( $user_id );
        $this->created_keys[] = $credentials;
        $this->assertNotNull( $this->key_row( $credentials ) );
        return $credentials;
    }

    private function key_row( array $credentials ) {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . MainWP_DB::instance()->get_table_name( 'api_keys' ) . ' WHERE consumer_key = %s',
                mainwp_api_hash( $credentials['consumer_key'] )
            )
        );
    }

    private function prepare_anonymous_request( array $credentials ): void {
        foreach ( array( 'user' => null, 'error' => null, 'auth_method' => '' ) as $name => $value ) {
            $property = new \ReflectionProperty( $this->auth, $name );
            $property->setAccessible( true );
            $property->setValue( $this->auth, $value );
        }
        wp_set_current_user( 0 );
        $GLOBALS['current_user'] = null;
        $_GET                   = array();
        $_POST                  = array();
        unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
        $_SERVER['HTTPS']                       = 'on';
        $_SERVER['REQUEST_URI']                 = '/wp-json/mainwp/v2/sites/count';
        $_SERVER['REQUEST_METHOD']              = 'GET';
        $GLOBALS['wp']->query_vars['rest_route'] = '/mainwp/v2/sites/count';
        $_SERVER['PHP_AUTH_USER']               = $credentials['consumer_key'];
        $_SERVER['PHP_AUTH_PW']                 = $credentials['consumer_secret'];
    }

    private function run_authentication_hooks() {
        // Core sets the filtered user ID before checking REST authentication errors.
        wp_set_current_user( (int) apply_filters( 'determine_current_user', false ) );
        return apply_filters( 'rest_authentication_errors', null );
    }

    private function assert_disabled_key_error( $error ): void {
        $this->assertSame( 0, get_current_user_id() );
        $this->assertWPError( $error );
        $this->assertSame( 'mainwp_rest_authentication_disabled_key', $error->get_error_code() );
        $this->assertSame( 401, $error->get_error_data()['status'] );
        $this->assertSame( __( 'The REST API Key are disabled.', 'mainwp' ), $error->get_error_message() );
        $this->assertNull( $this->auth->get_rest_valid_user() );
        $this->assertWPError( $this->auth->is_valid_permissions( new \WP_REST_Request( 'GET', '/mainwp/v2/sites/count' ) ) );
    }

    public function test_disabling_key_clears_cache_and_refuses_authentication(): void {
        $owner_id    = $this->set_current_user_as_admin();
        $credentials = $this->create_key( $owner_id );
        $key_id      = (int) $this->key_row( $credentials )->key_id;
        $cache_key   = 'mainwp_api_key_' . md5( mainwp_api_hash( $credentials['consumer_key'] ) ); // NOSONAR - MD5 used for cache key generation only, not cryptographic (security) purposes.

        $this->prepare_anonymous_request( $credentials );
        $this->assertNotWPError( $this->run_authentication_hooks() );
        $this->assertSame( $owner_id, get_current_user_id() );
        $this->assertIsObject( wp_cache_get( $cache_key ) );

        $this->assertSame( 1, MainWP_DB::instance()->update_rest_api_key( $key_id, 'read_write', 'desc', 0 ) );
        $this->assertFalse( wp_cache_get( $cache_key ) );

        $this->prepare_anonymous_request( $credentials );
        $this->assert_disabled_key_error( $this->run_authentication_hooks() );
    }

    public function test_deleting_key_clears_cache_and_refuses_authentication(): void {
        $owner_id    = $this->set_current_user_as_admin();
        $credentials = $this->create_key( $owner_id );
        $key_id      = (int) $this->key_row( $credentials )->key_id;
        $cache_key   = 'mainwp_api_key_' . md5( mainwp_api_hash( $credentials['consumer_key'] ) ); // NOSONAR - MD5 used for cache key generation only, not cryptographic (security) purposes.

        $this->prepare_anonymous_request( $credentials );
        $this->assertNotWPError( $this->run_authentication_hooks() );
        $this->assertSame( $owner_id, get_current_user_id() );
        $this->assertIsObject( wp_cache_get( $cache_key ) );

        $this->assertSame( 1, MainWP_DB::instance()->remove_rest_api_key( $key_id ) );
        $this->assertNull( $this->key_row( $credentials ) );
        $this->assertFalse( wp_cache_get( $cache_key ) );

        $this->prepare_anonymous_request( $credentials );
        $this->assert_disabled_key_error( $this->run_authentication_hooks() );
    }

    public function test_editing_one_key_preserves_another_keys_cache(): void {
        $owner_id = $this->set_current_user_as_admin();
        $key_a    = $this->create_key( $owner_id );
        $key_b    = $this->create_key( $owner_id );
        $key_id   = (int) $this->key_row( $key_a )->key_id;
        $cache_a  = 'mainwp_api_key_' . md5( mainwp_api_hash( $key_a['consumer_key'] ) ); // NOSONAR - MD5 used for cache key generation only, not cryptographic (security) purposes.
        $cache_b  = 'mainwp_api_key_' . md5( mainwp_api_hash( $key_b['consumer_key'] ) ); // NOSONAR - MD5 used for cache key generation only, not cryptographic (security) purposes.

        foreach ( array( $key_a, $key_b ) as $credentials ) {
            $this->prepare_anonymous_request( $credentials );
            $this->assertNotWPError( $this->run_authentication_hooks() );
            $this->assertSame( $owner_id, get_current_user_id() );
        }
        $this->assertIsObject( wp_cache_get( $cache_a ) );
        $this->assertIsObject( wp_cache_get( $cache_b ) );

        $this->assertSame( 1, MainWP_DB::instance()->update_rest_api_key( $key_id, 'read_write', 'desc', 0 ) );
        $this->assertFalse( wp_cache_get( $cache_a ) );
        $this->assertIsObject( wp_cache_get( $cache_b ) );
    }
}
