<?php
/**
 * Refusal and forwarding tests for the version-bound update route.
 *
 * @package MainWP\Dashboard\Tests
 */

use MainWP\Dashboard\Tests\MainWP_Abilities_Test_Case;
use MainWP\Dashboard\MainWP_DB_Common;

class Test_REST_Exact_Plugin_Update extends MainWP_Abilities_Test_Case {
    private $site;
    private $calls = array();
    private $response;
    private $controller;
    private $scope;

    public function setUp(): void {
        parent::setUp();
        $this->set_current_user_as_admin();
        $this->site       = (object) array(
            'id'                     => 7,
'suspended'                          => '0',
'sync_errors'                        => '',
            'is_ignorePluginUpdates' => 0,
'ignored_plugins'                    => '',
            'plugin_upgrades'        => wp_json_encode( array( 'fixture/fixture.php' => array( 'update' => array( 'new_version' => '1.1' ) ) ) ),
        );
        $this->response   = array(
            'success' => 1,
'contract'            => 'mainwp.plugin-update-exact.v1',
            'slug'    => 'fixture/fixture.php',
'current_version'     => '1.0',
'target_version'      => '1.1',
        );
        $this->controller = new class( $this->site ) extends MainWP_Rest_Updates_Controller {
            private $site;
            public function __construct( $site ) {
 $this->site = $site; }
            public function get_request_item( $request ) {
 return $this->site; }
        };
        add_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'child' ), 10, 4 );
    }

    public function tearDown(): void {
        remove_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'child' ), 10 );
        if ( $this->scope ) {
            remove_filter( 'mainwp_currentuserallowedaccesssites', $this->scope );
        }
        $property = new ReflectionProperty( MainWP_REST_Authentication::class, 'user' );
        if ( PHP_VERSION_ID < 80100 ) {
            $property->setAccessible( true );
        }
        $property->setValue( MainWP_REST_Authentication::get_instance(), null );
        parent::tearDown();
    }

    public function child( $reply, $site, $command, $params ) {
        $this->calls[] = array(
'site'    => $site->id,
'command' => $command,
'params'  => $params
);
        return $this->response;
    }

    private function request( $changes = array() ) {
        $request = new WP_REST_Request( 'POST', '/mainwp/v2/updates/7/update/plugins/exact' );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( wp_json_encode( array_merge( array(
            'slug' => 'fixture/fixture.php',
'current_version'  => '1.0',
'target_version'   => '1.1',
        ), $changes ) ) );
        $request['id_domain'] = '7';
        return $request;
    }

    /** @dataProvider invalid_bindings */
    public function test_invalid_bindings_never_dispatch( $change ) {
        $this->assertWPError( $this->controller->update_plugin_site_exact( $this->request( $change ) ) );
        $this->assertSame( array(), $this->calls );
    }

    public function invalid_bindings() {
        return array(
            array( array( 'slug' => array( 'fixture/fixture.php' ) ) ),
            array( array( 'slug' => 'fixture/fixture.php,other/other.php' ) ),
            array( array( 'current_version' => null ) ),
            array( array( 'target_version' => '' ) ),
            array( array( 'target_version' => '1.0' ) ),
        );
    }

    public function test_ignored_plugin_never_dispatches() {
        $this->site->ignored_plugins = wp_json_encode( array( 'fixture/fixture.php' => 'Fixture' ) );
        $this->assertWPError( $this->controller->update_plugin_site_exact( $this->request() ) );
        $this->assertSame( array(), $this->calls );
    }

    public function test_site_ignore_never_dispatches() {
        $this->site->is_ignorePluginUpdates = 1;
        $this->assertWPError( $this->controller->update_plugin_site_exact( $this->request() ) );
        $this->assertSame( array(), $this->calls );
    }

    public function test_suspended_and_disconnected_sites_never_dispatch() {
        $this->site->suspended = '1';
        $this->assertWPError( $this->controller->update_plugin_site_exact( $this->request() ) );
        $this->site->suspended   = '0';
        $this->site->sync_errors = 'Disconnected';
        $this->assertWPError( $this->controller->update_plugin_site_exact( $this->request() ) );
        $this->assertSame( array(), $this->calls );
    }

    /** @dataProvider unconfirmed_responses */
    public function test_unconfirmed_child_never_succeeds_or_falls_back( $response ) {
        $this->response = $response;
        $this->assertWPError( $this->controller->update_plugin_site_exact( $this->request() ) );
        $this->assertCount( 1, $this->calls );
        $this->assertSame( 'upgradepluginexact', $this->calls[0]['command'] );
    }

    public function unconfirmed_responses() {
        return array(
            array( array( 'success' => 1 ) ),
            array(
array(
'success' => 0,
'message' => 'Refused'
) ),
            array(
array(
'contract'        => 'mainwp.plugin-update-exact.v1',
'success'         => 1,
'slug'            => 'other/other.php',
'current_version' => '1.0',
'target_version'  => '1.1'
) ),
            array(
array(
'contract'        => 'mainwp.plugin-update-exact.v1',
'success'         => 1,
'slug'            => 'fixture/fixture.php',
'current_version' => '1.0',
'target_version'  => '1.2'
) ),
        );
    }

    public function test_exact_fields_and_one_site_are_forwarded_to_distinct_command() {
        $result = $this->controller->update_plugin_site_exact( $this->request() );
        $this->assertNotWPError( $result );
        $this->assertSame( $this->response, $result->get_data() );
        $this->assertSame( array(
array(
            'site'   => 7,
'command'            => 'upgradepluginexact',
            'params' => array(
'slug'            => 'fixture/fixture.php',
'current_version' => '1.0',
'target_version'  => '1.1'
),
        ) ), $this->calls );
    }

    public function test_route_reuses_api_key_permissions() {
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        add_action( 'rest_api_init', array( new MainWP_Rest_Updates_Controller(), 'register_routes' ) );
        do_action( 'rest_api_init' );
        $routes   = $wp_rest_server->get_routes();
        $route    = $routes['/mainwp/v2/updates/(?P<id_domain>[A-Za-z0-9-\.]+)/update/plugins/exact'];
        $callback = $route[0]['permission_callback'];
        $request  = $this->request();
        $property = new ReflectionProperty( MainWP_REST_Authentication::class, 'user' );
        if ( PHP_VERSION_ID < 80100 ) {
            $property->setAccessible( true );
        }
        $property->setValue( MainWP_REST_Authentication::get_instance(), null );
        $this->assertWPError( call_user_func( $callback, $request ) );
        $property->setValue( MainWP_REST_Authentication::get_instance(), (object) array( 'permissions' => 'read' ) );
        $this->assertWPError( call_user_func( $callback, $request ) );
        $property->setValue( MainWP_REST_Authentication::get_instance(), (object) array( 'permissions' => 'write' ) );
        $this->assertTrue( call_user_func( $callback, $request ) );
        $wp_rest_server = null;
    }

    public function test_real_site_lookup_refuses_foreign_site_scope() {
        $id          = $this->create_test_site();
        $this->scope = static function () {
 return array();
};
        add_filter( 'mainwp_currentuserallowedaccesssites', $this->scope );
        $request              = $this->request();
        $request['id_domain'] = (string) $id;
        $this->assertWPError( ( new MainWP_Rest_Updates_Controller() )->update_plugin_site_exact( $request ) );
        $this->assertSame( array(), $this->calls );
    }
}
