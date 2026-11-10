<?php
/**
 * Application password creation and native authentication regression tests.
 *
 * @package MainWP/Dashboard
 */

use MainWP\Dashboard\MainWP_Application_Passwords;
use MainWP\Dashboard\MainWP_Rest_Api_Page;

class Test_Application_Password_Creation extends WP_Ajax_UnitTestCase {
    private $actor;
    private $page;

    public function set_up() {
        parent::set_up();
        $this->actor = self::factory()->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $this->actor );
        $this->page = new MainWP_Rest_Api_Page();
        delete_network_option( get_main_network_id(), 'using_application_passwords' );
        add_filter( 'wp_is_application_passwords_available', '__return_true' );
        add_filter( 'application_password_is_api_request', '__return_true' );
    }

    public function tear_down() {
        remove_filter( 'wp_is_application_passwords_available', '__return_true' );
        remove_filter( 'application_password_is_api_request', '__return_true' );
        $_POST = array();
        $_REQUEST = array();
        parent::tear_down();
    }

    private function create_password( $name = 'Regression client' ) {
        $_POST['name'] = $name;
        $_REQUEST['security'] = wp_create_nonce( 'mainwp_application_password_create' );
        $this->_last_response = '';
        ob_start();
        try {
            $this->page->ajax_application_password_create();
        } catch ( WPAjaxDieContinueException $e ) {
            // The production handler terminates after its JSON response.
        }
        $result = json_decode( $this->_last_response, true );
        $this->assertIsArray( $result, 'Creation must return JSON.' );
        return $result;
    }

    private function authenticate( $password ) {
        $user = get_userdata( $this->actor );
        return wp_authenticate_application_password( null, $user->user_login, $password );
    }

    public function test_first_password_authenticates_and_hook_observes_flag() {
        $observed = false;
        $callback = static function () use ( &$observed ) {
            $observed = (bool) get_network_option( get_main_network_id(), 'using_application_passwords' );
        };
        add_action( 'mainwp_create_application_password', $callback );
        try {
            $result = $this->create_password();
        } finally {
            remove_action( 'mainwp_create_application_password', $callback );
        }
        $this->assertTrue( $result['success'] );
        $this->assertTrue( (bool) get_network_option( get_main_network_id(), 'using_application_passwords' ) );
        $this->assertTrue( $observed );
        $this->assertArrayHasKey( 'uuid', $result['data']['item'] );
        $authenticated = $this->authenticate( $result['data']['password'] );
        $this->assertInstanceOf( WP_User::class, $authenticated );
        $this->assertSame( $this->actor, $authenticated->ID );
    }

    public function test_duplicate_does_not_set_flag() {
        MainWP_Application_Passwords::create_new_application_password( $this->actor, array( 'name' => 'Regression client' ) );
        delete_network_option( get_main_network_id(), 'using_application_passwords' );
        $result = $this->create_password();
        $this->assertFalse( $result['success'] );
        $this->assertFalse( get_network_option( get_main_network_id(), 'using_application_passwords' ) );
        $this->assertCount( 1, MainWP_Application_Passwords::get_user_application_passwords( $this->actor ) );
    }

    public function test_storage_failure_does_not_set_flag() {
        $callback = static function ( $check, $user_id, $key ) {
            return '_application_passwords' === $key ? false : $check;
        };
        add_filter( 'update_user_metadata', $callback, 10, 3 );
        try {
            $result = $this->create_password();
        } finally {
            remove_filter( 'update_user_metadata', $callback, 10 );
        }
        $this->assertFalse( $result['success'] );
        $this->assertFalse( get_network_option( get_main_network_id(), 'using_application_passwords' ) );
        $this->assertSame( array(), MainWP_Application_Passwords::get_user_application_passwords( $this->actor ) );
    }

    public function test_permission_denial_does_not_set_flag() {
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
        add_filter( 'mainwp_currentusercan', '__return_false', 999 );
        try {
            $result = $this->create_password();
        } finally {
            remove_filter( 'mainwp_currentusercan', '__return_false', 999 );
        }
        $this->assertFalse( $result['success'] );
        $this->assertFalse( get_network_option( get_main_network_id(), 'using_application_passwords' ) );
    }

    public function test_existing_flag_is_preserved() {
        update_network_option( get_main_network_id(), 'using_application_passwords', 'already-in-use' );
        $result = $this->create_password();
        $this->assertTrue( $result['success'] );
        $this->assertSame( 'already-in-use', get_network_option( get_main_network_id(), 'using_application_passwords' ) );
        $authenticated = $this->authenticate( $result['data']['password'] );
        $this->assertInstanceOf( WP_User::class, $authenticated );
        $this->assertSame( $this->actor, $authenticated->ID );
    }

    public function test_wrong_and_revoked_passwords_fail() {
        $result = $this->create_password();
        $this->assertTrue( $result['success'] );
        $this->assertWPError( $this->authenticate( 'incorrect-password' ) );
        MainWP_Application_Passwords::delete_application_password( $this->actor, $result['data']['item']['uuid'] );
        $this->assertWPError( $this->authenticate( $result['data']['password'] ) );
    }

    public function test_core_availability_restriction_still_applies() {
        $result = $this->create_password();
        $this->assertTrue( $result['success'] );
        remove_filter( 'wp_is_application_passwords_available', '__return_true' );
        add_filter( 'wp_is_application_passwords_available', '__return_false' );
        try {
            $error = $this->authenticate( $result['data']['password'] );
            $this->assertWPError( $error );
            $this->assertSame( 'application_passwords_disabled', $error->get_error_code() );
        } finally {
            remove_filter( 'wp_is_application_passwords_available', '__return_false' );
        }
    }
}
