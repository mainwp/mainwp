<?php
/**
 * OAuth nonce history regression tests.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;

class Test_REST_OAuth_Nonce_History extends MainWP_Abilities_Test_Case {

    private $auth;
    private $original_instance;
    private $original_auth_state = array();
    private $original_server;
    private $original_get;
    private $original_post;
    private $original_query_vars;
    private $owner_id;
    private $credentials;

    public function setUp(): void {
        parent::setUp();
        $this->original_instance   = \MainWP_REST_Authentication::$instance;
        $this->original_server     = $_SERVER;
        $this->original_get        = $_GET;
        $this->original_post       = $_POST;
        $this->original_query_vars = $GLOBALS['wp']->query_vars;
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
        add_filter( 'pre_http_request', array( $this, 'block_http' ), PHP_INT_MAX );
        add_filter( 'pre_wp_mail', '__return_true' );
    }

    public function tearDown(): void {
        foreach ( MainWP_DB::instance()->remove_rest_api_keys_by_user( $this->owner_id ) as $row ) {
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
        remove_filter( 'pre_http_request', array( $this, 'block_http' ), PHP_INT_MAX );
        remove_filter( 'pre_wp_mail', '__return_true' );
        parent::tearDown();
    }

    public function block_http() {
        return new \WP_Error( 'unexpected_http', 'Outbound HTTP blocked.' );
    }

    private function authenticate( $nonce, int $timestamp, bool $https = true, bool $basic = false ) {
        foreach ( array( 'user' => null, 'error' => null, 'auth_method' => '' ) as $name => $value ) {
            $property = new \ReflectionProperty( $this->auth, $name );
            $property->setAccessible( true );
            $property->setValue( $this->auth, $value );
        }
        wp_set_current_user( 0 );
        $_GET  = array();
        $_POST = array();
        unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
        $_SERVER['HTTPS']                       = $https ? 'on' : 'off';
        $_SERVER['SERVER_PORT']                 = $https ? '443' : '80';
        $_SERVER['REMOTE_ADDR']                 = '192.0.2.10';
        $_SERVER['REQUEST_METHOD']              = 'GET';
        $_SERVER['REQUEST_URI']                 = '/wp-json/mainwp/v2/sites/count';
        $GLOBALS['wp']->query_vars['rest_route'] = '/mainwp/v2/sites/count';
        if ( $basic ) {
            $_SERVER['PHP_AUTH_USER'] = $this->credentials['consumer_key'];
            $_SERVER['PHP_AUTH_PW']   = $this->credentials['consumer_secret'];
        } else {
            $_GET = array(
                'oauth_consumer_key'     => $this->credentials['consumer_key'],
                'oauth_timestamp'        => (string) $timestamp,
                'oauth_nonce'            => $nonce,
                'oauth_signature_method' => 'HMAC-SHA256',
            );
            ksort( $_GET, SORT_STRING );
            $parameters = http_build_query( $_GET, '', '&', PHP_QUERY_RFC3986 );
            $base       = 'GET&' . rawurlencode( get_home_url( null, 'wp-json/mainwp/v2/sites/count', $https ? 'https' : 'http' ) ) . '&' . rawurlencode( $parameters );
            $_GET['oauth_signature'] = base64_encode( hash_hmac( 'sha256', $base, $this->credentials['consumer_secret'] . '&', true ) );
            $_SERVER['REQUEST_URI'] .= '?' . http_build_query( $_GET, '', '&', PHP_QUERY_RFC3986 );
        }
        $user_id = $this->auth->authenticate( false );
        wp_set_current_user( (int) $user_id );
        $error = $this->auth->check_authentication_error( null );
        return is_wp_error( $error ) ? $error : (int) $user_id;
    }

    private function seed_history( array $nonces ): void {
        global $wpdb;
        $wpdb->update(
            MainWP_DB::instance()->get_table_name( 'api_keys' ),
            array( 'nonces' => serialize( $nonces ) ),
            array( 'consumer_key' => mainwp_api_hash( $this->credentials['consumer_key'] ) )
        );
        \MainWP_REST_Authentication::delete_api_key_cache( mainwp_api_hash( $this->credentials['consumer_key'] ) );
    }

    private function assert_nonce_error( $result ): void {
        $this->assertWPError( $result );
        $this->assertSame( 'mainwp_rest_authentication_error', $result->get_error_code() );
        $this->assertSame( 'Invalid nonce - nonce has already been used.', $result->get_error_message() );
        $this->assertSame( array( 'status' => 401 ), $result->get_error_data() );
        $this->assertSame( 0, get_current_user_id() );
    }

    public function test_same_timestamp_retains_both_nonces_and_accepts_a_fresh_nonce(): void {
        $timestamp = time();
        $this->assertSame( $this->owner_id, $this->authenticate( 'nonce-A', $timestamp ) );
        $this->assertSame( $this->owner_id, $this->authenticate( 'nonce-B', $timestamp ) );
        $this->assert_nonce_error( $this->authenticate( 'nonce-A', $timestamp ) );
        $this->assert_nonce_error( $this->authenticate( 'nonce-B', $timestamp ) );
        $this->assertSame( $this->owner_id, $this->authenticate( 'nonce-C', $timestamp ) );
    }

    public function test_live_legacy_nonce_is_honored_after_a_new_request(): void {
        $timestamp = time();
        $this->seed_history( array( $timestamp => 'legacy-nonce' ) );
        $this->assert_nonce_error( $this->authenticate( 'legacy-nonce', $timestamp ) );
        $this->assertSame( $this->owner_id, $this->authenticate( 'new-nonce', $timestamp ) );
        $this->assert_nonce_error( $this->authenticate( 'legacy-nonce', $timestamp ) );
    }

    public function test_mixed_history_preserves_numeric_legacy_nonces_and_numeric_new_keys(): void {
        $timestamp     = time();
        $numeric_nonce = (string) ( $timestamp - 10 );
        $this->seed_history( array( $timestamp => $numeric_nonce, 'new-shape' => $timestamp ) );
        $this->assertSame( $this->owner_id, $this->authenticate( (string) $timestamp, $timestamp ) );
        $this->assert_nonce_error( $this->authenticate( $numeric_nonce, $timestamp ) );
        $this->assert_nonce_error( $this->authenticate( 'new-shape', $timestamp ) );
        $this->assert_nonce_error( $this->authenticate( (string) $timestamp, $timestamp ) );
        $this->assertSame( $this->owner_id, $this->authenticate( '00123', $timestamp ) );
        $this->assert_nonce_error( $this->authenticate( '00123', $timestamp ) );
    }

    public function test_expired_entries_in_both_formats_are_pruned_without_losing_live_entries(): void {
        $timestamp = time();
        $this->seed_history( array( $timestamp - 901 => 'expired-legacy', 'expired-new' => $timestamp - 901, $timestamp => 'live-legacy', 'live-new' => $timestamp ) );
        $this->assertSame( $this->owner_id, $this->authenticate( 'fresh-nonce', $timestamp ) );
        $this->assert_nonce_error( $this->authenticate( 'live-legacy', $timestamp ) );
        $this->assert_nonce_error( $this->authenticate( 'live-new', $timestamp ) );
        $this->assertSame( $this->owner_id, $this->authenticate( 'expired-legacy', $timestamp ) );
        $this->assertSame( $this->owner_id, $this->authenticate( 'expired-new', $timestamp ) );
    }

    public function test_outside_timestamp_window_preserves_the_error_contract(): void {
        foreach ( array( time() - 901, time() + 960 ) as $timestamp ) {
            $result = $this->authenticate( 'outside-window', $timestamp );
            $this->assertWPError( $result );
            $this->assertSame( 'mainwp_rest_authentication_error', $result->get_error_code() );
            $this->assertSame( 'Invalid timestamp.', $result->get_error_message() );
            $this->assertSame( array( 'status' => 401 ), $result->get_error_data() );
        }
    }

    public function test_http_oauth_accepts_fresh_requests_and_keeps_existing_rejection_mask(): void {
        $timestamp = time();
        $this->assertSame( $this->owner_id, $this->authenticate( 'http-nonce', $timestamp, false ) );
        $error = $this->authenticate( 'http-nonce', $timestamp, false );
        $this->assertWPError( $error );
        $this->assertSame( 'mainwp_rest_authentication_bad_request', $error->get_error_code() );
        $this->assertSame( 'Requests to the MainWP REST API must be over HTTPS.', $error->get_error_message() );
        $this->assertSame( array( 'status' => 400 ), $error->get_error_data() );
    }

    public function test_https_basic_authentication_does_not_consume_oauth_nonces(): void {
        $timestamp = time();
        $this->assertSame( $this->owner_id, $this->authenticate( 'basic', $timestamp, true, true ) );
        $this->assertSame( $this->owner_id, $this->authenticate( 'basic', $timestamp, true, true ) );
        $this->assertSame( $this->owner_id, $this->authenticate( 'basic', $timestamp ) );
    }

    public function test_array_nonce_returns_the_existing_authentication_error(): void {
        $result = $this->authenticate( array( 'array-nonce' ), time() );
        $this->assertWPError( $result );
        $this->assertSame( 'mainwp_rest_authentication_disabled_key', $result->get_error_code() );
        $this->assertSame( 'The REST API Key are disabled.', $result->get_error_message() );
        $this->assertSame( array( 'status' => 401 ), $result->get_error_data() );
        $this->assertSame( $this->owner_id, $this->authenticate( 'after-array', time() ) );
    }

    public function test_legacy_array_nonce_does_not_break_a_fresh_string_nonce(): void {
        $timestamp = time();
        $this->seed_history( array( $timestamp => array( 'legacy-array-nonce' ), $timestamp - 1 => 'valid-legacy', 'valid-new' => $timestamp ) );
        $this->assertSame( $this->owner_id, $this->authenticate( 'fresh-string', $timestamp ) );
        $this->assert_nonce_error( $this->authenticate( 'valid-legacy', $timestamp ) );
        $this->assert_nonce_error( $this->authenticate( 'valid-new', $timestamp ) );
    }
}
