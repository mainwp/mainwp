<?php
/**
 * API keys can manage only permissions within their own capabilities.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;

class Test_REST_Key_Auth_Permission_Limit extends MainWP_Abilities_Test_Case {

    private $auth;
    private $controller;
    private $owner_id;
    private $original_instance;
    private $original_auth_state = array();
    private $original_server;
    private $original_get;
    private $original_post;
    private $original_query_vars;

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
            $property->setValue( $this->auth, 'auth_method' === $name ? '' : null );
        }

        $this->owner_id   = $this->set_current_user_as_admin();
        $this->controller = new \MainWP_Rest_API_Keys_Controller();
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

    private function authenticate_key( string $permissions ): object {
        $credentials = $this->create_rest_api_key( $this->owner_id, $permissions );
        $_GET        = array();
        $_POST       = array();
        unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
        $_SERVER['HTTPS']                       = 'on';
        $_SERVER['REQUEST_METHOD']              = 'POST';
        $_SERVER['REQUEST_URI']                 = '/wp-json/mainwp/v2/rest-api/add-key';
        $_SERVER['PHP_AUTH_USER']               = $credentials['consumer_key'];
        $_SERVER['PHP_AUTH_PW']                 = $credentials['consumer_secret'];
        $GLOBALS['wp']->query_vars['rest_route'] = '/mainwp/v2/rest-api/add-key';
        $this->assertSame( $this->owner_id, (int) $this->auth->authenticate( false ) );
        return $this->auth->get_rest_valid_user();
    }

    private function add_key( string $permissions ) {
        $request = new \WP_REST_Request( 'POST', '/mainwp/v2/rest-api/add-key' );
        $request->set_body_params( array( 'active' => true, 'permissions' => $permissions, 'description' => 'Permission limit fixture' ) );
        return $this->controller->create_new_key( $request );
    }

    private function edit_key( $identifier, array $params ) {
        $request = new \WP_REST_Request( 'PUT', '/mainwp/v2/rest-api/edit-key/' . $identifier );
        $request->set_url_params( array( 'key_identifier' => (string) $identifier ) );
        $request->set_body_params( $params );
        return $this->controller->edit_key( $request );
    }

    private function key_state(): array {
        global $wpdb;
        return array(
            'v2' => $wpdb->get_results( 'SELECT * FROM ' . MainWP_DB::instance()->get_table_name( 'api_keys' ) . ' ORDER BY key_id', ARRAY_A ),
            'v1' => get_option( 'mainwp_rest_api_keys', false ),
        );
    }

    private function assert_permission_refused( $result, array $before ): void {
        $this->assertWPError( $result );
        $this->assertSame( 'mainwp_rest_api_key_permission_exceeded', $result->get_error_code() );
        $this->assertSame( __( 'An API key cannot grant permissions it does not have.', 'mainwp' ), $result->get_error_message() );
        $this->assertSame( array( 'status' => 403 ), $result->get_error_data() );
        $this->assertSame( $before, $this->key_state(), 'Refusal must preserve every key row and the v1 key option.' );
    }

    private function create_v1_key( string $permissions ): string {
        $identifier          = 'ck_' . bin2hex( random_bytes( 20 ) );
        $keys                = get_option( 'mainwp_rest_api_keys', array() );
        $keys[ $identifier ] = array( 'cs' => 'cs_' . bin2hex( random_bytes( 20 ) ), 'perms' => $permissions, 'enabled' => 0, 'desc' => 'V1 fixture' );
        update_option( 'mainwp_rest_api_keys', $keys );
        return $identifier;
    }

    private function assert_key_created( string $permissions, string $scope ): void {
        $before = MainWP_DB::instance()->get_rest_api_keys();
        $result = $this->add_key( $permissions );
        $this->assertNotWPError( $result );
        $this->assertSame( 1, $result->get_data()['success'] );
        $after = MainWP_DB::instance()->get_rest_api_keys();
        $this->assertCount( count( $before ) + 1, $after );
        $this->assertSame( $scope, $after[0]->permissions );
        $this->assertSame( $this->owner_id, (int) $after[0]->user_id );
    }

    public function test_write_key_cannot_add_read_write_or_read(): void {
        $this->authenticate_key( 'write' );
        foreach ( array( 'read,write', 'read' ) as $permissions ) {
            $before = $this->key_state();
            $this->assert_permission_refused( $this->add_key( $permissions ), $before );
        }
    }

    public function test_write_key_cannot_upgrade_itself(): void {
        $caller = $this->authenticate_key( 'write' );
        $before = $this->key_state();
        $this->assert_permission_refused( $this->edit_key( $caller->key_id, array( 'permissions' => 'read,write' ) ), $before );
    }

    public function test_write_key_cannot_edit_or_enable_broader_v2_key(): void {
        global $wpdb;
        $this->create_rest_api_key( $this->owner_id, 'read_write' );
        $target = MainWP_DB::instance()->get_rest_api_keys()[0];
        $this->authenticate_key( 'write' );
        foreach ( array( array( 'active' => true ), array( 'active' => false ), array( 'description' => 'Renamed' ), array( 'permissions' => 'write' ) ) as $params ) {
            $enabled = isset( $params['active'] ) && $params['active'] ? 0 : 1;
            $wpdb->update( MainWP_DB::instance()->get_table_name( 'api_keys' ), array( 'enabled' => $enabled ), array( 'key_id' => $target->key_id ) );
            $before = $this->key_state();
            $this->assert_permission_refused( $this->edit_key( $target->key_id, $params ), $before );
        }
    }

    public function test_write_key_cannot_edit_or_enable_read_v1_key(): void {
        $target = $this->create_v1_key( 'r' );
        $this->authenticate_key( 'write' );
        foreach ( array( array( 'active' => true ), array( 'active' => false ), array( 'description' => 'Renamed' ), array( 'permissions' => 'write' ) ) as $params ) {
            $keys                       = get_option( 'mainwp_rest_api_keys' );
            $keys[ $target ]['enabled'] = isset( $params['active'] ) && $params['active'] ? 0 : 1;
            update_option( 'mainwp_rest_api_keys', $keys );
            $before = $this->key_state();
            $this->assert_permission_refused( $this->edit_key( $target, $params ), $before );
        }
    }

    public function test_write_key_cannot_upgrade_v1_key(): void {
        $target = $this->create_v1_key( 'w,d' );
        $this->authenticate_key( 'write' );
        $before = $this->key_state();
        $this->assert_permission_refused( $this->edit_key( $target, array( 'permissions' => 'read,write' ) ), $before );
    }

    public function test_read_write_key_can_add_write_and_read(): void {
        $this->authenticate_key( 'read_write' );
        $this->assert_key_created( 'write', 'write' );
        $this->assert_key_created( 'read', 'read' );
    }

    public function test_write_key_can_add_write_and_edit_write_description(): void {
        $caller = $this->authenticate_key( 'write' );
        $this->assert_key_created( 'write', 'write' );
        $target = MainWP_DB::instance()->get_rest_api_keys()[0];
        $this->assertNotSame( $caller->key_id, $target->key_id );
        $result = $this->edit_key( $target->key_id, array( 'description' => 'Renamed write key' ) );
        $this->assertNotWPError( $result );
        $updated = MainWP_DB::instance()->get_rest_api_key_by( $target->key_id );
        $this->assertSame( 'Renamed write key', $updated->description );
        $this->assertSame( 'write', $updated->permissions );
    }

    public function test_write_key_can_edit_v1_write_and_delete_keys(): void {
        $this->authenticate_key( 'write' );
        foreach ( array( 'w', 'd', 'w,d' ) as $permissions ) {
            $target = $this->create_v1_key( $permissions );
            $result = $this->edit_key( $target, array( 'description' => 'Renamed v1 key' ) );
            $this->assertNotWPError( $result );
            $key = get_option( 'mainwp_rest_api_keys' )[ $target ];
            $this->assertSame( 'Renamed v1 key', $key['desc'] );
            $this->assertSame( $permissions, $key['perms'] );
        }
    }

    public function test_admin_cookie_can_add_read_write(): void {
        unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $_SERVER['HTTP_AUTHORIZATION'] );
        $this->assertSame( $this->owner_id, $this->auth->authenticate( $this->owner_id ) );
        $this->assertNull( $this->auth->get_rest_valid_user() );
        $this->assert_key_created( 'read,write', 'read_write' );
    }
}
