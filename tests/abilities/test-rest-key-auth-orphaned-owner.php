<?php
/**
 * REST API keys require an existing owner and are removed with that owner.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;

class Test_REST_Key_Auth_Orphaned_Owner extends MainWP_Abilities_Test_Case {

    private $auth;
    private $original_instance;
    private $original_auth_state = array();
    private $original_server;
    private $original_get;
    private $original_post;
    private $original_query_vars;
    private $original_rest_server;
    private $cookie_priority;
    private $deleted_user_priority;
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
        $this->deleted_user_priority = has_action( 'deleted_user', array( $this->auth, 'delete_user_api_keys' ) );
    }

    public function tearDown(): void {
        global $wpdb;

        foreach ( $this->created_keys as $credentials ) {
            $hashed = mainwp_api_hash( $credentials['consumer_key'] );
            $wpdb->delete( MainWP_DB::instance()->get_table_name( 'api_keys' ), array( 'consumer_key' => $hashed ), array( '%s' ) );
            wp_cache_delete( 'mainwp_api_key_' . md5( $hashed ) );
        }
        if ( false !== $this->deleted_user_priority ) {
            add_action( 'deleted_user', array( $this->auth, 'delete_user_api_keys' ), $this->deleted_user_priority, 3 );
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

    private function create_key( int $user_id, string $permissions = 'read_write' ): array {
        $credentials         = $this->create_rest_api_key( $user_id, $permissions );
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

    private function orphan_id(): int {
        global $wpdb;
        $user_id = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->users}" ) + 1000;
        $this->assertFalse( get_userdata( $user_id ) );
        return $user_id;
    }

    private function prepare_anonymous_request( array $credentials, string $method = 'basic', string $route = '/mainwp/v2/sites/count', string $http_method = 'GET' ): void {
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
        $_SERVER['REQUEST_URI']                 = '/wp-json' . $route;
        $_SERVER['REQUEST_METHOD']              = $http_method;
        $GLOBALS['wp']->query_vars['rest_route'] = $route;

        if ( empty( $credentials ) ) {
            return;
        }
        if ( 'basic' === $method ) {
            $_SERVER['PHP_AUTH_USER'] = $credentials['consumer_key'];
            $_SERVER['PHP_AUTH_PW']   = $credentials['consumer_secret'];
        } elseif ( 'bearer' === $method ) {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . substr( $credentials['consumer_secret'], 3 ) . '==' . substr( $credentials['consumer_key'], 3 );
        } elseif ( 'query_token' === $method ) {
            $_GET = array(
                'oauth_mainwp_ck' => $credentials['consumer_key'],
                'oauth_mainwp_cs' => $credentials['consumer_secret'],
            );
            $_SERVER['REQUEST_URI'] .= '?' . http_build_query( $_GET, '', '&', PHP_QUERY_RFC3986 );
        } else {
            $_GET = array(
                'oauth_consumer_key'     => $credentials['consumer_key'],
                'oauth_timestamp'        => (string) time(),
                'oauth_nonce'            => wp_generate_password( 32, false ),
                'oauth_signature_method' => 'HMAC-SHA256',
            );
            ksort( $_GET, SORT_STRING );
            $parameters = http_build_query( $_GET, '', '&', PHP_QUERY_RFC3986 );
            $base       = $http_method . '&' . rawurlencode( get_home_url( null, 'wp-json' . $route, 'https' ) ) . '&' . rawurlencode( $parameters );
            $_GET['oauth_signature'] = base64_encode( hash_hmac( 'sha256', $base, $credentials['consumer_secret'] . '&', true ) );
            $_SERVER['REQUEST_URI'] .= '?' . http_build_query( $_GET, '', '&', PHP_QUERY_RFC3986 );
        }
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

    public static function authentication_methods(): array {
        return array( 'basic' => array( 'basic' ), 'oauth' => array( 'oauth' ), 'bearer' => array( 'bearer' ), 'query token' => array( 'query_token' ) );
    }

    /**
     * @dataProvider authentication_methods
     */
    public function test_orphaned_owner_is_indistinguishable_from_an_unknown_key( string $method ): void {
        $credentials = $this->create_key( $this->orphan_id(), 'read' );
        $this->prepare_anonymous_request( $credentials, $method );
        $orphan_error = $this->run_authentication_hooks();
        $this->assert_disabled_key_error( $orphan_error );

        $unknown = array( 'consumer_key' => 'ck_' . bin2hex( random_bytes( 16 ) ), 'consumer_secret' => $credentials['consumer_secret'] );
        $this->prepare_anonymous_request( $unknown, $method );
        $unknown_error = $this->run_authentication_hooks();
        $this->assert_disabled_key_error( $unknown_error );
        $this->assertSame( $unknown_error->get_error_code(), $orphan_error->get_error_code() );
        $this->assertSame( $unknown_error->get_error_data(), $orphan_error->get_error_data() );
        $this->assertSame( $unknown_error->get_error_message(), $orphan_error->get_error_message() );
    }

    /**
     * @dataProvider authentication_methods
     */
    public function test_wrong_secret_is_indistinguishable_from_an_unknown_key( string $method ): void {
        $credentials = $this->create_key( $this->set_current_user_as_admin() );
        $wrong       = array( 'consumer_key' => $credentials['consumer_key'], 'consumer_secret' => 'cs_' . bin2hex( random_bytes( 16 ) ) );
        $this->prepare_anonymous_request( $wrong, $method );
        $wrong_error   = $this->run_authentication_hooks();
        $wrong_headers = $this->auth->send_unauthorized_headers( new \WP_REST_Response() )->get_headers();
        $this->assert_disabled_key_error( $wrong_error );

        $unknown = array( 'consumer_key' => 'ck_' . bin2hex( random_bytes( 16 ) ), 'consumer_secret' => $wrong['consumer_secret'] );
        $this->prepare_anonymous_request( $unknown, $method );
        $unknown_error = $this->run_authentication_hooks();
        $this->assert_disabled_key_error( $unknown_error );
        $this->assertSame( $unknown_error->get_error_data(), $wrong_error->get_error_data() );
        $this->assertSame( $unknown_error->get_error_message(), $wrong_error->get_error_message() );
        $this->assertSame( $this->auth->send_unauthorized_headers( new \WP_REST_Response() )->get_headers(), $wrong_headers );
    }

    public function test_orphaned_owner_cannot_write_tags(): void {
        global $wpdb;
        $credentials = $this->create_key( $this->orphan_id() );
        $this->prepare_anonymous_request( $credentials, 'basic', '/mainwp/v2/tags/add', 'POST' );
        $before  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mainwp_group" );
        $error   = $this->run_authentication_hooks();
        $request = new \WP_REST_Request( 'POST', '/mainwp/v2/tags/add' );
        $request->set_param( 'name', 'Test Tag Orphaned Owner' );

        $GLOBALS['wp_rest_server'] = new \WP_REST_Server();
        do_action( 'rest_api_init' );
        ( new \MainWP_Rest_Tags_Controller() )->register_routes();
        $this->assertArrayHasKey( '/mainwp/v2/tags/add', rest_get_server()->get_routes() );

        // dispatch() omits authentication; its permission callback must also refuse the key.
        $response = rest_do_request( $request );
        $this->assertSame( $before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mainwp_group" ) );
        $this->assert_disabled_key_error( $error );
        $this->assertSame( 401, $response->get_status() );
    }

    public function test_cached_row_is_refused_after_owner_deletion_without_cleanup(): void {
        $owner_id    = $this->set_current_user_as_admin();
        $credentials = $this->create_key( $owner_id );
        $cache_key   = 'mainwp_api_key_' . md5( mainwp_api_hash( $credentials['consumer_key'] ) );
        $this->prepare_anonymous_request( $credentials );
        $this->assertNotWPError( $this->run_authentication_hooks() );
        $this->assertSame( $owner_id, get_current_user_id() );
        $cached = wp_cache_get( $cache_key );
        $this->assertIsObject( $cached );

        remove_action( 'deleted_user', array( $this->auth, 'delete_user_api_keys' ), 10 );
        $this->assertTrue( wp_delete_user( $owner_id ) );
        $this->assertFalse( get_userdata( $owner_id ) );
        $this->assertNotNull( $this->key_row( $credentials ) );
        $this->assertEquals( $cached, wp_cache_get( $cache_key ) );

        $this->prepare_anonymous_request( $credentials );
        $this->assert_disabled_key_error( $this->run_authentication_hooks() );
    }

    public static function deletion_modes(): array {
        return array( 'delete' => array( false ), 'reassign' => array( true ) );
    }

    /**
     * @dataProvider deletion_modes
     */
    public function test_deletion_removes_only_the_owners_keys_and_cache( bool $reassign ): void {
        $owner_a = $this->set_current_user_as_admin();
        $owner_b = $this->set_current_user_as_admin();
        $key_a   = $this->create_key( $owner_a );
        $key_b   = $this->create_key( $owner_b );
        $row_b   = $this->key_row( $key_b );
        foreach ( array( $key_a, $key_b ) as $credentials ) {
            $this->prepare_anonymous_request( $credentials );
            $this->assertNotWPError( $this->run_authentication_hooks() );
        }
        $cache_a = 'mainwp_api_key_' . md5( mainwp_api_hash( $key_a['consumer_key'] ) );
        $cache_b = 'mainwp_api_key_' . md5( mainwp_api_hash( $key_b['consumer_key'] ) );
        $this->assertIsObject( wp_cache_get( $cache_a ) );
        $this->assertIsObject( wp_cache_get( $cache_b ) );
        $this->assertTrue( wp_delete_user( $owner_a, $reassign ? $owner_b : null ) );
        $this->assertNull( $this->key_row( $key_a ) );
        $this->assertFalse( wp_cache_get( $cache_a ) );
        $this->assertEquals( $row_b, $this->key_row( $key_b ) );
        $this->assertIsObject( wp_cache_get( $cache_b ) );
    }

    public function test_blog_removal_handler_leaves_a_different_captured_table_alone(): void {
        global $wpdb;
        $credentials = $this->create_key( $this->set_current_user_as_admin() );
        $row         = $this->key_row( $credentials );
        $base_prefix = $wpdb->base_prefix;
        $blog_id     = get_current_blog_id() + 1000;

        // Single-site wpdb ignores blog IDs; model a different prefix without changing MainWP's captured table.
        $wpdb->base_prefix = $base_prefix . 'unrelated_';
        try {
            $this->assertNotSame( MainWP_DB::instance()->get_table_name( 'api_keys' ), $wpdb->get_blog_prefix( $blog_id ) . 'mainwp_api_keys' );
            $this->auth->remove_user_from_blog_api_keys( (int) $row->user_id, $blog_id, 0 );
        } finally {
            $wpdb->base_prefix = $base_prefix;
        }
        $this->assertEquals( $row, $this->key_row( $credentials ) );
    }

    public function test_multisite_removal_cleans_only_the_dashboard_blog(): void {
        if ( ! is_multisite() ) {
            $this->markTestSkipped( 'The Abilities test configuration is single-site; real multisite membership and removal require multisite.' );
        }
        $owner_id       = $this->set_current_user_as_admin();
        $credentials    = $this->create_key( $owner_id );
        $dashboard_blog = get_current_blog_id();
        $other_blog     = $this->factory->blog->create();
        $this->assertNotWPError( add_user_to_blog( $other_blog, $owner_id, 'administrator' ) );
        $this->assertTrue( remove_user_from_blog( $owner_id, $other_blog ) );
        $this->assertNotNull( $this->key_row( $credentials ) );

        $this->prepare_anonymous_request( $credentials );
        $this->assertNotWPError( $this->run_authentication_hooks() );
        $this->assertSame( $owner_id, get_current_user_id() );
        $cache_key = 'mainwp_api_key_' . md5( mainwp_api_hash( $credentials['consumer_key'] ) );
        $this->assertTrue( remove_user_from_blog( $owner_id, $dashboard_blog ) );
        $this->assertNotFalse( get_userdata( $owner_id ) );
        $this->assertNull( $this->key_row( $credentials ) );
        $this->assertFalse( wp_cache_get( $cache_key ) );

        // Insert a fresh row to verify membership refusal independently of cleanup.
        $credentials = $this->create_key( $owner_id );
        $this->prepare_anonymous_request( $credentials );
        $this->assert_disabled_key_error( $this->run_authentication_hooks() );
    }

    public function test_revoked_super_admin_handler_removes_only_non_member_keys(): void {
        global $wpdb;
        $member_key = $this->create_key( $this->set_current_user_as_admin() );
        $member_row = $this->key_row( $member_key );
        $this->auth->revoked_super_admin_api_keys( (int) $member_row->user_id );
        $this->assertEquals( $member_row, $this->key_row( $member_key ) );

        // Single-site counts every existing user as a member, so a missing user stands in for a non-member.
        $orphan_key  = $this->create_key( $this->orphan_id() );
        $orphan_row  = $this->key_row( $orphan_key );
        $base_prefix = $wpdb->base_prefix;

        // A handler running under another blog's prefix must leave MainWP's captured table alone.
        $wpdb->base_prefix = $base_prefix . 'unrelated_';
        try {
            $this->assertNotSame( MainWP_DB::instance()->get_table_name( 'api_keys' ), $wpdb->get_blog_prefix() . 'mainwp_api_keys' );
            $this->auth->revoked_super_admin_api_keys( (int) $orphan_row->user_id );
        } finally {
            $wpdb->base_prefix = $base_prefix;
        }
        $this->assertEquals( $orphan_row, $this->key_row( $orphan_key ) );

        $this->auth->revoked_super_admin_api_keys( (int) $orphan_row->user_id );
        $this->assertNull( $this->key_row( $orphan_key ) );
        $this->assertEquals( $member_row, $this->key_row( $member_key ) );
    }

    public function test_multisite_super_admin_revocation_removes_keys_of_non_members(): void {
        if ( ! is_multisite() ) {
            $this->markTestSkipped( 'The Abilities test configuration is single-site; super admin revocation and blog membership require multisite.' );
        }
        $outsider_id = $this->factory->user->create();
        $this->assertTrue( remove_user_from_blog( $outsider_id, get_current_blog_id() ) );
        grant_super_admin( $outsider_id );
        $outsider_key = $this->create_key( $outsider_id );
        $member_id    = $this->set_current_user_as_admin();
        grant_super_admin( $member_id );
        $member_key = $this->create_key( $member_id );
        $member_row = $this->key_row( $member_key );

        $this->prepare_anonymous_request( $outsider_key );
        $this->assertNotWPError( $this->run_authentication_hooks() );
        $this->assertSame( $outsider_id, get_current_user_id() );
        $cache_key = 'mainwp_api_key_' . md5( mainwp_api_hash( $outsider_key['consumer_key'] ) );
        $this->assertIsObject( wp_cache_get( $cache_key ) );

        $this->assertTrue( revoke_super_admin( $outsider_id ) );
        $this->assertTrue( revoke_super_admin( $member_id ) );
        $this->assertNull( $this->key_row( $outsider_key ) );
        $this->assertFalse( wp_cache_get( $cache_key ) );
        $this->assertEquals( $member_row, $this->key_row( $member_key ) );
    }

    /**
     * @dataProvider authentication_methods
     */
    public function test_existing_owner_still_authenticates( string $method ): void {
        $owner_id    = $this->set_current_user_as_admin();
        $credentials = $this->create_key( $owner_id );
        $this->prepare_anonymous_request( $credentials, $method );
        $this->assertNotWPError( $this->run_authentication_hooks() );
        $this->assertSame( $owner_id, get_current_user_id() );
    }

    public function test_no_credentials_or_rest_route_leaves_a_machine_call_untouched(): void {
        $this->prepare_anonymous_request( array(), 'basic', '' );
        $this->assertFalse( $this->auth->authenticate( false ) );
        $error_property = new \ReflectionProperty( $this->auth, 'error' );
        $error_property->setAccessible( true );
        $this->assertNull( $error_property->getValue( $this->auth ) );
        $this->assertSame( 0, get_current_user_id() );
        $this->assertNull( apply_filters( 'rest_authentication_errors', null ) );
        $this->assertNull( $this->auth->get_rest_valid_user() );
    }
}
