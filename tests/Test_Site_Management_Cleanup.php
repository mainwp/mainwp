<?php
/**
 * Site navigation rights and security fix filter defaults.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Manage_Sites_View;
use MainWP\Dashboard\MainWP_Security_Issues;
use MainWP\Dashboard\MainWP_System;

/** Covers operation rights and the security filter's callback contract. */
class Test_Site_Management_Cleanup extends \WP_UnitTestCase {

    use Site_Fixture;

    /** Create an administrator and initialize the permission bridge. */
    public function setUp(): void {
        parent::setUp();
        MainWP_System::instance()->plugins_loaded();
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        if ( ! function_exists( 'set_current_screen' ) ) {
            require_once ABSPATH . 'wp-admin/includes/screen.php';
        }
        set_current_screen( 'dashboard' );
    }

    /** Render both allowed and denied navigation through the permission hook. */
    public function test_hardening_navigation_follows_operation_right(): void {
        $site_id           = $this->create_test_site();
        $_GET['dashboard'] = $site_id;
        $allow             = true;
        $rights            = static function ( $granted, $group, $right ) use ( &$allow ) {
            return 'dashboard' === $group && 'manage_security_issues' === $right ? $allow : $granted;
        };
        add_filter( 'mainwp_currentusercan', $rights, PHP_INT_MAX, 3 );
        try {
            foreach ( array( true, false ) as $allow ) {
                ob_start();
                MainWP_Manage_Sites_View::render_header( 'ManageSitesDashboard' );
                $html = ob_get_clean();
                $href = esc_url( 'admin.php?page=managesites&scanid=' . $site_id );
                $this->assertSame( $allow, str_contains( $html, 'href="' . $href . '"' ), 'Hardening navigation must match the operation right.' );
            }
        } finally {
            remove_filter( 'mainwp_currentusercan', $rights, PHP_INT_MAX );
            unset( $_GET['dashboard'] );
        }
    }

    /** Observe the unchanged default without registering a filter callback. */
    public function test_no_filter_callback_receives_empty_array_default(): void {
        $site_id        = $this->create_test_site();
        $_REQUEST['id'] = $site_id;
        $observed       = null;
        $request        = null;
        $observe        = static function ( $hook, ...$args ) use ( &$observed ) {
            if ( 'mainwp_security_post_data' === $hook ) {
                $observed = $args;
            }
        };
        $transport      = static function ( $result, $website, $action, $params ) use ( &$request ) {
            $request = array( $action, $params );
            return array( 'success' => true );
        };
        add_action( 'all', $observe, 10, 99 );
        add_filter( 'mainwp_fetch_url_authed_pre', $transport, 10, 4 );
        try {
            $this->assertSame( array( 'success' => true ), MainWP_Security_Issues::fix_security_issue() );
            $this->assertSame( array(), $observed[0] );
            $this->assertSame( array(), $observed[1] );
            $this->assertSame( (int) $site_id, (int) $observed[2]->id );
            $this->assertSame( 'securityFix', $request[0] );
            $this->assertSame( array( 'versions' ), $request[1]['skip_features'] );
        } finally {
            remove_action( 'all', $observe, 10 );
            remove_filter( 'mainwp_fetch_url_authed_pre', $transport, 10 );
            unset( $_REQUEST['id'] );
        }
    }

    /** Existing three-argument callbacks can still select checks to skip. */
    public function test_filter_can_supply_skip_features_with_existing_arguments(): void {
        $site_id        = $this->create_test_site();
        $_REQUEST['id'] = $site_id;
        $filter         = function ( $skip, $defaults, $website ) use ( $site_id ) {
            $this->assertSame( array(), $skip );
            $this->assertSame( array(), $defaults );
            $this->assertSame( (int) $site_id, (int) $website->id );
            return array( 'db_reporting' );
        };
        $transport      = static function ( $result, $website, $action, $params ) {
            return $params;
        };
        add_filter( 'mainwp_security_post_data', $filter, 10, 3 );
        add_filter( 'mainwp_fetch_url_authed_pre', $transport, 10, 4 );
        try {
            $result = MainWP_Security_Issues::fix_security_issue();
            $this->assertSame( array( 'db_reporting', 'versions' ), $result['skip_features'] );
        } finally {
            remove_filter( 'mainwp_security_post_data', $filter, 10 );
            remove_filter( 'mainwp_fetch_url_authed_pre', $transport, 10 );
            unset( $_REQUEST['id'] );
        }
    }
}
