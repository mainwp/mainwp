<?php
/**
 * Translation holds through registered REST v2 routes.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

class Test_Translation_Hold_REST_V2 extends MainWP_Abilities_Test_Case {

    private $previous_server;
    private $auth;
    private $auth_user;
    private $previous_auth_user;
    private $auth_priority;
    private $site_id;
    private $calls = array();

    public function setUp(): void {
        parent::setUp();
        $this->skip_if_no_abilities_api();
        $user_id                     = $this->set_current_user_as_admin();
        $this->previous_server       = $GLOBALS['wp_rest_server'];
        $this->auth                  = \MainWP_REST_Authentication::get_instance();
        $this->auth_user             = new \ReflectionProperty( $this->auth, 'user' );
        $this->auth_user->setAccessible( true );
        $this->previous_auth_user = $this->auth_user->getValue( $this->auth );
        $this->auth_user->setValue( $this->auth, (object) array( 'user_id' => $user_id, 'permissions' => 'read_write' ) );
        // The route permission callback runs without leaking a permanent REST bypass constant.
        $this->auth_priority = has_filter( 'rest_pre_dispatch', array( $this->auth, 'check_user_permissions' ) );
        if ( false !== $this->auth_priority ) {
            remove_filter( 'rest_pre_dispatch', array( $this->auth, 'check_user_permissions' ), $this->auth_priority );
        }
        $GLOBALS['wp_rest_server'] = new \WP_REST_Server();
        do_action( 'rest_api_init' );
        ( new \MainWP_Rest_Updates_Controller() )->register_routes();
        $this->site_id = $this->create_test_site();
        $this->set_site_translation_upgrades( $this->site_id, array( array( 'slug' => 'akismet', 'language' => 'fr_FR', 'version' => '1.0', 'new_version' => '2.0' ) ) );
        add_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'child_reply' ), 10, 4 );
    }

    public function tearDown(): void {
        remove_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'child_reply' ), 10 );
        if ( $this->auth_user ) {
            $this->auth_user->setValue( $this->auth, $this->previous_auth_user );
            if ( false !== $this->auth_priority ) {
                add_filter( 'rest_pre_dispatch', array( $this->auth, 'check_user_permissions' ), $this->auth_priority, 3 );
            }
        }
        $GLOBALS['wp_rest_server'] = $this->previous_server;
        parent::tearDown();
    }

    public function child_reply( $result, $website, $what, $params ) {
        $this->calls[] = array( 'what' => $what, 'params' => $params );
        return array( 'other_data' => array( 'updated_data' => array( array( 'slug' => 'akismet', 'name' => 'Akismet', 'old_version' => '1.0', 'version' => '2.0', 'success' => 1 ) ) ) );
    }

    private function dispatch( string $method, string $route, array $params = array() ) {
        $request = new \WP_REST_Request( $method, '/mainwp/v2/updates' . $route );
        $request->set_query_params( $params );
        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( 200, $response->get_status() );
        return $response->get_data();
    }

    public function test_site_list_omits_held_translations(): void {
        $this->set_site_option( $this->site_id, 'ignored_trans_updates', '0' );
        $control = $this->dispatch( 'GET', '/' . $this->site_id, array( 'type' => 'translations' ) );
        $this->assertSame( 'akismet', $control['data']['translations'][0]['slug'] );
        $this->set_site_option( $this->site_id, 'ignored_trans_updates', '1' );
        $held = $this->dispatch( 'GET', '/' . $this->site_id, array( 'type' => 'translations' ) );
        $this->assertSame( array(), $held['data']['translations'] );
        $this->assertSame( array(), $this->calls );
    }

    public function test_network_list_omits_held_translations(): void {
        $this->set_site_option( $this->site_id, 'ignored_trans_updates', '0' );
        $control = $this->dispatch( 'GET', '', array( 'type' => 'translations', 'include' => (string) $this->site_id ) );
        $this->assertSame( 'akismet', $control['data'][0]['slug'] );
        $this->assertSame( 'translation', $control['data'][0]['type'] );
        $this->set_site_option( $this->site_id, 'ignored_trans_updates', '1' );
        $held = $this->dispatch( 'GET', '', array( 'type' => 'translations', 'include' => (string) $this->site_id ) );
        $this->assertSame( array(), $held['data'] );
        $this->assertSame( array(), $this->calls );
    }

    public function test_legacy_network_list_omits_held_translations(): void {
        $registry = \WP_Abilities_Registry::get_instance();
        $property = new \ReflectionProperty( $registry, 'registered_abilities' );
        $property->setAccessible( true );
        $abilities = $property->getValue( $registry );
        $remaining = $abilities;
        unset( $remaining['mainwp/list-updates-v1'] );
        $property->setValue( $registry, $remaining );
        $this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::get_registered' );
        try {
            $this->set_site_option( $this->site_id, 'ignored_trans_updates', '0' );
            $control = $this->dispatch( 'GET', '', array( 'type' => 'translations', 'include' => (string) $this->site_id ) );
            $this->assertSame( 'akismet', $control['data'][ $this->site_id ]['translations'][0]['slug'] );
            $this->set_site_option( $this->site_id, 'ignored_trans_updates', '1' );
            $held = $this->dispatch( 'GET', '', array( 'type' => 'translations', 'include' => (string) $this->site_id ) );
            $this->assertSame( array(), $held['data'][ $this->site_id ]['translations'] );
            $this->assertSame( array(), $this->calls );
        } finally {
            $property->setValue( $registry, $abilities );
        }
    }

    public function test_explicit_update_matches_plugin_hold_response_and_preserves_hold_off(): void {
        $this->set_site_option( $this->site_id, 'ignored_trans_updates', '1' );
        $held = $this->dispatch( 'PUT', '/' . $this->site_id . '/update/translations', array( 'slug' => 'akismet' ) );
        $this->assertSame( array( 'success' => 0, 'message' => 'Translations ignored updates per site.' ), $held );
        $this->assertSame( array(), $this->calls, 'Held translations must never reach Child.' );
        $this->set_site_option( $this->site_id, 'ignored_trans_updates', '0' );
        $control = $this->dispatch( 'PUT', '/' . $this->site_id . '/update/translations', array( 'slug' => 'akismet' ) );
        $this->assertSame( 1, $control['success'] );
        $this->assertSame( 1, $control['data']['akismet']['success'] );
        $this->assertSame( array( array( 'what' => 'upgradetranslation', 'params' => array( 'type' => 'translation', 'list' => 'akismet' ) ) ), $this->calls );
    }
}
