<?php
/**
 * On-demand changelogs for plugins with incomplete update sections.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_System_Handler;

class Test_Premium_Plugin_Changelog extends \WP_UnitTestCase {
    use Site_Fixture;

    private $site_id;
    private $get_backup;
    private $requests;
    private $child_result;
    private $throw_on_request = false;

    public function set_up() {
        parent::set_up();
        $this->get_backup = $_GET;
        $this->requests = array();
        $this->throw_on_request = false;
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        $this->site_id = $this->create_test_site();
        $_GET['wpplugin'] = $this->site_id;
        $this->child_result = array( 'update' => array( 'slug' => 'gravityperks', 'sections' => array( 'changelog' => '<p>Premium changes</p>' ) ) );
        add_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'child_response' ), 10, 4 );
    }

    public function tear_down() {
        $_GET = $this->get_backup;
        remove_filter( 'mainwp_fetch_url_authed_pre', array( $this, 'child_response' ), 10 );
        parent::tear_down();
    }

    public function child_response( $preempt, $website, $action, $params ) {
        $this->requests[] = array( (int) $website->id, $action, $params );
        if ( $this->throw_on_request ) {
            throw new \RuntimeException( 'Child unavailable.' );
        }
        return $this->child_result;
    }

    private function store_sections( $sections ) {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'mainwp_wp',
            array( 'plugin_upgrades' => wp_json_encode( array( 'gravityperks/gravityperks.php' => array( 'update' => array(
                'slug' => 'gravityperks', 'new_version' => '2.0', 'sections' => $sections,
            ) ) ) ) ),
            array( 'id' => $this->site_id )
        );
    }

    private function details( $response = null ) {
        if ( null === $response ) {
            $response = new \WP_Error( 'plugins_api_failed', 'Not on WordPress.org.' );
        }
        return MainWP_System_Handler::instance()->plugins_api_wp_plugins_api_result( $response, 'plugin_information', (object) array( 'slug' => 'gravityperks' ) );
    }

    public function test_existing_changelog_does_not_contact_child() {
        $this->store_sections( array( 'changelog' => '<p>Stored changes</p>', 'description' => 'Stored description' ) );
        $result = $this->details();
        $this->assertSame( '<p>Stored changes</p>', $result->sections['changelog'] );
        $this->assertSame( 'Stored description', $result->sections['description'] );
        $this->assertFalse( $result->external );
        $this->assertSame( array(), $this->requests );
    }

    /** @dataProvider incomplete_sections */
    public function test_missing_changelog_uses_on_demand_child_action( $sections ) {
        $this->store_sections( $sections );
        $result = $this->details();
        $this->assertSame( '<p>Premium changes</p>', $result->sections['changelog'] );
        $this->assertTrue( $result->external );
        $this->assertSame( array( array( $this->site_id, 'plugin_action', array( 'action' => 'changelog_info', 'slug' => 'gravityperks' ) ) ), $this->requests );
    }

    public static function incomplete_sections() {
        return array(
            'missing' => array( null ),
            'empty sections' => array( array() ),
            'description only' => array( array( 'description' => 'Description' ) ),
            'empty changelog' => array( array( 'changelog' => '' ) ),
            'whitespace changelog' => array( array( 'changelog' => " \n\t" ) ),
            'invalid changelog' => array( array( 'changelog' => array( 'invalid' ) ) ),
        );
    }

    public function test_failed_child_lookup_preserves_stored_description() {
        $this->store_sections( array( 'description' => 'Description' ) );
        $this->child_result = array( 'update' => array() );
        $result = $this->details();
        $this->assertSame( 'Description', $result->sections['description'] );
        $this->assertFalse( $result->external );
        $this->assertCount( 1, $this->requests );
    }

    public function test_child_exception_preserves_stored_description() {
        $this->store_sections( array( 'description' => 'Description' ) );
        $this->throw_on_request = true;
        $result = $this->details();
        $this->assertSame( 'Description', $result->sections['description'] );
        $this->assertFalse( $result->external );
        $this->assertCount( 1, $this->requests );
    }

    public function test_missing_sections_and_child_failure_preserve_original_error() {
        $this->store_sections( null );
        $this->child_result = array( 'update' => array() );
        $error = new \WP_Error( 'plugins_api_failed', 'No plugin information.' );
        $this->assertSame( $error, $this->details( $error ) );
        $this->assertCount( 1, $this->requests );
    }

    public function test_wordpress_plugin_information_skips_child() {
        $this->store_sections( array( 'description' => 'Description' ) );
        $response = (object) array( 'slug' => 'gravityperks', 'sections' => array( 'changelog' => '<p>WordPress changes</p>' ) );
        $this->assertSame( $response, $this->details( $response ) );
        $this->assertSame( array(), $this->requests );
    }
}
