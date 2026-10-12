<?php
/**
 * OAuth errors must not disclose whether a consumer key exists.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;

class Test_REST_Key_Auth_OAuth_Uniform extends MainWP_Abilities_Test_Case {

    private $auth;
    private $original_instance;
    private $original_auth_state = array();
    private $original_server;
    private $original_get;
    private $original_post;
    private $original_query_vars;
    private $owner_id;

    public function setUp(): void {
        parent::setUp();

        $this->original_instance   = \MainWP_REST_Authentication::$instance;
        $this->original_server     = $_SERVER;
        $this->original_get        = $_GET;
        $this->original_post       = $_POST;
        $this->original_query_vars = $GLOBALS['wp']->query_vars;

        // Other harnesses reset the singleton while core restores the hooked instance.
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
        $this->owner_id = $this->set_current_user_as_admin();
    }

    public function tearDown(): void {
        $rows = MainWP_DB::instance()->remove_rest_api_keys_by_user( $this->owner_id );
        foreach ( $rows as $row ) {
            \MainWP_REST_Authentication::delete_api_key_cache( $row->consumer_key );
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
        wp_set_current_user( 0 );

        parent::tearDown();
    }

    private function oauth_error( string $consumer_key, $signature_method = 'HMAC-SHA256' ): array {
        foreach ( array( 'user' => null, 'error' => null, 'auth_method' => '' ) as $name => $value ) {
            $property = new \ReflectionProperty( $this->auth, $name );
            $property->setAccessible( true );
            $property->setValue( $this->auth, $value );
        }
        wp_set_current_user( 0 );
        $_POST = array();
        $_GET  = array(
            'oauth_consumer_key'     => $consumer_key,
            'oauth_timestamp'        => (string) time(),
            'oauth_nonce'            => wp_generate_password( 32, false ),
            'oauth_signature_method' => $signature_method,
            'oauth_signature'        => 'wrong-signature',
        );
        unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
        $_SERVER['HTTPS']                       = 'on';
        $_SERVER['REQUEST_METHOD']              = 'GET';
        $_SERVER['REQUEST_URI']                 = '/wp-json/mainwp/v2/sites/count?' . http_build_query( $_GET, '', '&', PHP_QUERY_RFC3986 );
        $GLOBALS['wp']->query_vars['rest_route'] = '/mainwp/v2/sites/count';

        $method = new \ReflectionMethod( $this->auth, 'perform_oauth_authentication' );
        $method->setAccessible( true );
        $this->assertFalse( $method->invoke( $this->auth ) );
        $error = $this->auth->check_authentication_error( null );
        $this->assertWPError( $error );
        $this->assertSame( 0, get_current_user_id() );
        return array( $error->get_error_code(), $error->get_error_message(), $error->get_error_data() );
    }

    public function test_unknown_disabled_orphaned_hashed_and_wrong_signature_errors_are_identical(): void {
        global $wpdb;
        $table    = MainWP_DB::instance()->get_table_name( 'api_keys' );
        $disabled = $this->create_rest_api_key( $this->owner_id );
        $wpdb->update( $table, array( 'enabled' => 0 ), array( 'consumer_key' => mainwp_api_hash( $disabled['consumer_key'] ) ) );

        $orphan_id = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->users}" ) + 1000;
        $this->assertFalse( get_userdata( $orphan_id ) );
        $orphaned = $this->create_rest_api_key( $orphan_id );
        $hashed   = $this->create_rest_api_key( $this->owner_id );
        $wpdb->update( $table, array( 'consumer_secret' => wp_hash_password( $hashed['consumer_secret'] ) ), array( 'consumer_key' => mainwp_api_hash( $hashed['consumer_key'] ) ) );
        $legacy = $this->create_rest_api_key( $this->owner_id );
        $cases  = array(
            'unknown'         => 'ck_' . bin2hex( random_bytes( 20 ) ),
            'disabled'        => $disabled['consumer_key'],
            'orphaned owner'  => $orphaned['consumer_key'],
            'hashed secret'   => $hashed['consumer_key'],
            'wrong signature' => $legacy['consumer_key'],
        );

        try {
            $expected = array( 'mainwp_rest_authentication_disabled_key', __( 'The REST API Key are disabled.', 'mainwp' ), array( 'status' => 401 ) );
            foreach ( array( 'HMAC-SHA1', 'HMAC-SHA256' ) as $signature_method ) {
                foreach ( $cases as $name => $consumer_key ) {
                    $this->assertSame( $expected, $this->oauth_error( $consumer_key, $signature_method ), $name . ' using ' . $signature_method );
                }
            }
        } finally {
            $wpdb->delete( $table, array( 'consumer_key' => mainwp_api_hash( $orphaned['consumer_key'] ) ), array( '%s' ) );
            \MainWP_REST_Authentication::delete_api_key_cache( mainwp_api_hash( $orphaned['consumer_key'] ) );
        }
    }

    public function test_invalid_signature_method_is_rejected_before_key_lookup(): void {
        $legacy  = $this->create_rest_api_key( $this->owner_id );
        $unknown = 'ck_' . bin2hex( random_bytes( 20 ) );
        foreach ( array( 'RSA-SHA1', 'hmac-sha256', array( 'HMAC-SHA256' ), 256 ) as $signature_method ) {
            $expected = array( 'mainwp_rest_authentication_error', __( 'Invalid signature - signature method is invalid.', 'mainwp' ), array( 'status' => 401 ) );
            $this->assertSame( $expected, $this->oauth_error( $unknown, $signature_method ) );
            $this->assertSame( $expected, $this->oauth_error( $legacy['consumer_key'], $signature_method ) );
        }
    }
}
