<?php
/**
 * Uptime edit persistence and down confirmation controls.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB_Uptime_Monitoring;
use MainWP\Dashboard\MainWP_Uptime_Monitoring_Edit;

class Test_MWP_1886_Uptime_Edit extends \WP_UnitTestCase {
    use Site_Fixture;

    public function setUp(): void {
        parent::setUp();
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        $_GET = $_POST = $_REQUEST = array();
    }

    public function tearDown(): void {
        remove_filter( 'mainwp_update_wp_monitor', '__return_empty_array' );
        $_GET = $_POST = $_REQUEST = array();
        parent::tearDown();
    }

    public function test_primary_monitor_save_clears_repair_attempts(): void {
        $site = $this->create_test_site();
        $monitor_id = $this->monitor( $site );
        $option = 'mainwp_primary_monitor_repair_attempts_for_wp_' . $site;
        update_option( $option, 3 );
        $this->save_monitor( $site, $monitor_id, 0 );
        $this->assertFalse( get_option( $option, false ) );
        $this->assertSame( 0, (int) MainWP_DB_Uptime_Monitoring::instance()->get_monitor_by( $site, 'monitor_id', $monitor_id )->maxretries );
    }

    public function test_refused_monitor_save_keeps_repair_attempts(): void {
        $site = $this->create_test_site();
        $monitor_id = $this->monitor( $site );
        $option = 'mainwp_primary_monitor_repair_attempts_for_wp_' . $site;
        update_option( $option, 3 );
        add_filter( 'mainwp_update_wp_monitor', '__return_empty_array' );
        try {
            $this->save_monitor( $site, $monitor_id, 0 );
        } finally {
            remove_filter( 'mainwp_update_wp_monitor', '__return_empty_array' );
        }
        $this->assertSame( 3, (int) get_option( $option ) );
        $this->assertSame( 1, (int) MainWP_DB_Uptime_Monitoring::instance()->get_monitor_by( $site, 'monitor_id', $monitor_id )->maxretries );
    }

    public function test_failed_monitor_update_keeps_repair_attempts(): void {
        global $wpdb;
        $site = $this->create_test_site();
        $monitor_id = $this->monitor( $site );
        $option = 'mainwp_primary_monitor_repair_attempts_for_wp_' . $site;
        update_option( $option, 3 );
        $attempts = 0;
        $fail_update = static function ( $query ) use ( $wpdb, &$attempts ) {
            if ( preg_match( '/^\s*UPDATE\s+`?' . preg_quote( $wpdb->prefix . 'mainwp_monitors', '/' ) . '`?\s/i', $query ) ) {
                ++$attempts;
                return "UPDATE {$wpdb->prefix}mainwp_monitors SET d57_missing_column = 1";
            }
            return $query;
        };
        $previous_errors = $wpdb->suppress_errors( true );
        add_filter( 'query', $fail_update );
        try {
            $this->save_monitor( $site, $monitor_id, 0 );
        } finally {
            remove_filter( 'query', $fail_update );
            $wpdb->suppress_errors( $previous_errors );
        }
        $this->assertSame( 1, $attempts, 'Saving must attempt a real monitor UPDATE.' );
        $this->assertSame( 3, (int) get_option( $option ) );
        $this->assertSame( 1, (int) MainWP_DB_Uptime_Monitoring::instance()->get_monitor_by( $site, 'monitor_id', $monitor_id )->maxretries );
    }

    /** @dataProvider confirmation_settings */
    public function test_down_confirmation_selection_survives_save_and_reopen( $individual, $retries ): void {
        $site = $this->create_test_site();
        $monitor_id = $this->monitor( $site );
        for ( $pass = 0; $pass < 2; ++$pass ) {
            if ( $individual ) {
                $this->save_monitor( $site, $monitor_id, $retries );
                $this->assertSame( $retries, (int) MainWP_DB_Uptime_Monitoring::instance()->get_monitor_by( $site, 'monitor_id', $monitor_id )->maxretries );
            } else {
                $this->save_monitor( 0, 0, $retries );
                $this->assertSame( $retries, (int) get_option( 'mainwp_global_uptime_monitoring_settings' )['maxretries'] );
            }
            ob_start();
            try {
                MainWP_Uptime_Monitoring_Edit::instance()->render_monitor_settings( $individual ? $site : false, $individual );
            } finally {
                $html = ob_get_clean();
            }
            $dom = new \DOMDocument();
            $previous = libxml_use_internal_errors( true );
            try {
                $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors( $previous );
            }
            $xpath = new \DOMXPath( $dom );
            $selected = $xpath->query( '//select[@id="mainwp_edit_monitor_maxretries"]/option[@selected]' );
            $this->assertSame( 1, $selected->length, 'Exactly one down confirmation choice must be selected.' );
            $this->assertSame( (string) $retries, $selected->item( 0 )->getAttribute( 'value' ) );
            $this->assertSame( 1, $xpath->query( '//select[@id="mainwp_edit_monitor_maxretries"]/option[text()="Enable" and @value="' . max( 1, $retries ) . '"]' )->length );
            $retries = (int) $selected->item( 0 )->getAttribute( 'value' );
        }
    }

    public static function confirmation_settings(): array {
        return array(
            'individual disabled' => array( true, 0 ),
            'global disabled' => array( false, 0 ),
            'individual inherited' => array( true, -1 ),
            'individual enabled' => array( true, 3 ),
            'global enabled' => array( false, 3 ),
        );
    }

    private function monitor( $site ): int {
        $id = MainWP_DB_Uptime_Monitoring::instance()->update_wp_monitor( array( 'wpid' => $site, 'suburl' => '', 'maxretries' => 1 ) );
        $this->assertGreaterThan( 0, $id );
        return (int) $id;
    }

    private function save_monitor( $site, $monitor_id, $retries ): void {
        $_REQUEST = array( 'monitor_wpid' => $site );
        $_POST = array(
            'wp_nonce_uptime_settings' => wp_create_nonce( 'UpdateMonitorSettings' ),
            'mainwp_edit_monitor_individual_settings' => $site ? 1 : 0,
            'mainwp_edit_monitor_id' => $monitor_id,
            'mainwp_edit_monitor_maxretries' => $retries,
        );
        MainWP_Uptime_Monitoring_Edit::instance()->handle_save_settings();
    }
}
