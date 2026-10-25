<?php
/**
 * Translation holds through registered REST v1 routes.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\Rest_Api_V1;

/**
 * V1 authentication defines a process-wide capability bypass constant.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class Test_Translation_Hold_REST_V1 extends MainWP_Abilities_Test_Case {

    private $credentials;
    private $previous_keys;
    private $previous_server;
    private $site_id;
    private $calls = array();
    public function setUp(): void {
        parent::setUp();
        $this->set_current_user_as_admin();
        $this->previous_keys   = get_option( 'mainwp_rest_api_keys', false );
        $this->previous_server = $GLOBALS['wp_rest_server'];
        $this->credentials     = array( 'consumer_key' => 'ck_translation_hold_fixture', 'consumer_secret' => 'cs_translation_hold_fixture' );
        update_option( 'mainwp_rest_api_keys', array(
            $this->credentials['consumer_key'] => array(
                'ck_hashed' => wp_hash_password( $this->credentials['consumer_key'] ),
                'cs'        => wp_hash_password( $this->credentials['consumer_secret'] ),
                'enabled'   => 1,
                'perms'     => 'r,w',
            ),
        ) );
        $GLOBALS['wp_rest_server'] = new \WP_REST_Server();
        do_action( 'rest_api_init' );
        Rest_Api_V1::instance()->mainwp_register_routes();
        $this->site_id = $this->create_test_site();
        $this->set_site_plugin_upgrades( $this->site_id, array() );
        $this->set_site_theme_upgrades( $this->site_id, array() );
        $this->set_site_translation_upgrades( $this->site_id, array( array( 'slug' => 'akismet', 'language' => 'fr_FR', 'version' => '1.0', 'new_version' => '2.0' ) ) );
        $this->set_site_option( $this->site_id, 'wp_upgrades', '[]' );
        add_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'child_reply' ), 10, 4 );
    }

    public function tearDown(): void {
        remove_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'child_reply' ), 10 );
        if ( false === $this->previous_keys ) {
            delete_option( 'mainwp_rest_api_keys' );
        } else {
            update_option( 'mainwp_rest_api_keys', $this->previous_keys );
        }
        $GLOBALS['wp_rest_server'] = $this->previous_server;
        parent::tearDown();
    }

    public function child_reply( $result, $website, $what, $params ) {
        $this->calls[] = array( 'what' => $what, 'params' => $params );
        return array( 'upgrades' => array( 'akismet' => true ) );
    }

    private function dispatch( string $method, string $route ) {
        $request = new \WP_REST_Request( $method, '/mainwp/v1/' . $route );
        $request->set_query_params( $this->credentials + array( 'site_id' => $this->site_id ) );
        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( 200, $response->get_status() );
        return $response->get_data();
    }

    public function test_site_list_omits_held_translations(): void {
        $this->set_site_option( $this->site_id, 'ignored_trans_updates', '0' );
        $control = $this->dispatch( 'GET', 'site/site-available-updates' );
        $this->assertSame( 'akismet', $control['translation'][0]['slug'] );
        $this->set_site_option( $this->site_id, 'ignored_trans_updates', '1' );
        $held = $this->dispatch( 'GET', 'site/site-available-updates' );
        $this->assertSame( array(), $held['translation'] );
        $this->assertSame( array(), $this->calls );
    }

    public function test_site_count_excludes_held_translations(): void {
        $this->set_site_option( $this->site_id, 'ignored_trans_updates', '0' );
        $control = $this->dispatch( 'GET', 'site/site-available-updates-count' );
        $this->assertSame( 1, $control['translations'] );
        $this->assertSame( 1, $control['total'] );
        $this->set_site_option( $this->site_id, 'ignored_trans_updates', '1' );
        $held = $this->dispatch( 'GET', 'site/site-available-updates-count' );
        $this->assertSame( 0, $held['translations'] );
        $this->assertSame( 0, $held['total'] );
        $this->assertSame( array(), $this->calls );
    }

    public function test_network_list_omits_held_translations(): void {
        $this->set_site_option( $this->site_id, 'ignored_trans_updates', '0' );
        $control = $this->dispatch( 'GET', 'updates/available-updates' );
        $this->assertSame( 'akismet', $control[ $this->site_id ]['translation'][0]['slug'] );
        $this->set_site_option( $this->site_id, 'ignored_trans_updates', '1' );
        $held = $this->dispatch( 'GET', 'updates/available-updates' );
        $this->assertSame( array(), $held[ $this->site_id ]['translation'] );
        $this->assertSame( array(), $this->calls );
    }

    public function test_update_preserves_success_response_without_sending_held_translations(): void {
        $this->set_site_option( $this->site_id, 'ignored_trans_updates', '1' );
        $held = $this->dispatch( 'PUT', 'site/site-update-translations' );
        $this->assertSame( array( 'SUCCESS' => 'Process ran.' ), $held );
        $this->assertSame( array(), $this->calls, 'Held translations must never reach Child.' );
        $this->set_site_option( $this->site_id, 'ignored_trans_updates', '0' );
        $control = $this->dispatch( 'PUT', 'site/site-update-translations' );
        $this->assertSame( $held, $control );
        $this->assertSame( array( array( 'what' => 'upgradetranslation', 'params' => array( 'type' => 'translation', 'list' => 'akismet' ) ) ), $this->calls );
    }
}
