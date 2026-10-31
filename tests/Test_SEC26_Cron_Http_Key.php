<?php
/**
 * System-cron HTTP authentication.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Server_Information;

/**
 * Keeps HTTP cron jobs keyed without changing shell invocations.
 */
class Test_SEC26_Cron_Http_Key extends \WP_UnitTestCase {

    /**
     * Remove options so each case starts without a stored key.
     */
    public function set_up() {
        parent::set_up();
        delete_option( 'mainwp_cron_secret' );
        delete_option( 'mainwp_wp_cron' );
    }

    /**
     * Do not leave cron settings or an authenticated user for later tests.
     */
    public function tear_down() {
        delete_option( 'mainwp_cron_secret' );
        delete_option( 'mainwp_wp_cron' );
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /**
     * Repeated reads must reuse the non-autoloaded option.
     */
    public function test_secret_is_generated_once_without_autoload() {
        global $wpdb;

        $secret = MainWP_Server_Information::get_cron_secret();

        $this->assertSame( 32, strlen( $secret ) );
        $this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{32}$/', $secret );
        $this->assertSame( $secret, get_option( 'mainwp_cron_secret' ) );
        $this->assertSame( $secret, MainWP_Server_Information::get_cron_secret() );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The stored flag, rather than the option cache, establishes autoload behavior.
        $autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM $wpdb->options WHERE option_name = %s", 'mainwp_cron_secret' ) );
        $this->assertContains( $autoload, array( 'no', 'off', 'auto-off' ) );
    }

    /**
     * Shell entry points are exempt; HTTP callers must supply the exact string.
     *
     * @dataProvider cron_requests
     * @param string $sapi    PHP interface.
     * @param array  $server  Request metadata.
     * @param mixed  $key     Caller-supplied key.
     * @param bool   $allowed Expected authorization.
     */
    public function test_cron_request_is_allowed( $sapi, $server, $key, $allowed ) {
        add_option( 'mainwp_cron_secret', 'sec26-test-key', '', false );

        $this->assertSame( $allowed, MainWP_Server_Information::is_cron_request_allowed( $sapi, $server, $key ) );
    }

    /**
     * Includes CGI cron binaries that have no HTTP request metadata.
     *
     * @return array Request cases.
     */
    public static function cron_requests() {
        $http = array( 'REQUEST_METHOD' => 'GET' );

        return array(
            'CLI without key'       => array( 'cli', $http, '', true ),
            'CGI shell without key' => array( 'cgi-fcgi', array(), '', true ),
            'HTTP without key'      => array( 'fpm-fcgi', $http, null, false ),
            'HTTP empty key'        => array( 'fpm-fcgi', $http, '', false ),
            'HTTP wrong key'        => array( 'fpm-fcgi', $http, 'wrong-key', false ),
            'HTTP array key'        => array( 'fpm-fcgi', $http, array( 'sec26-test-key' ), false ),
            'HTTP matching key'     => array( 'fpm-fcgi', $http, 'sec26-test-key', true ),
        );
    }

    /**
     * Every job must pass through the shared guard before invoking MainWP.
     */
    public function test_all_cron_scripts_load_the_guard_before_work() {
        $cron_dir = dirname( __DIR__ ) . '/cron/';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- This check reads local PHP source, not a remote URL.
        $bootstrap = file_get_contents( $cron_dir . 'bootstrap.php' );

        $this->assertStringContainsString( 'is_cron_request_allowed(', $bootstrap );
        $this->assertStringContainsString( 'status_header( 403 )', $bootstrap );
        $this->assertStringContainsString( 'nocache_headers()', $bootstrap );

        $scripts = glob( $cron_dir . '*.php' );
        $this->assertNotEmpty( $scripts );
        foreach ( $scripts as $script ) {
            if ( 'bootstrap.php' === basename( $script ) ) {
                continue;
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- This check reads local PHP source, not a remote URL.
            $source    = file_get_contents( $script );
            $bootstrap = strpos( $source, "require_once 'bootstrap.php'" );
            $work      = strpos( $source, '$mainWP->' );

            $this->assertNotFalse( $bootstrap, basename( $script ) );
            $this->assertNotFalse( $work, basename( $script ) );
            $this->assertLessThan( $work, $bootstrap, basename( $script ) );
        }
    }

    /**
     * Only system-cron users need the keyed URL and shell command.
     *
     * @dataProvider cron_modes
     * @param string $mode MainWP cron setting.
     */
    public function test_render_cron_shows_system_cron_instructions( $mode ) {
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        update_option( 'mainwp_wp_cron', $mode );
        $secret = 'sec26<&"\'key';
        add_option( 'mainwp_cron_secret', $secret, '', false );

        ob_start();
        try {
            MainWP_Server_Information::render_cron();
            $html = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        $command = 'php ' . MAINWP_PLUGIN_DIR . 'cron/updatescheck.php';
        $url     = MAINWP_PLUGIN_URL . 'cron/updatescheck.php?key=' . rawurlencode( $secret );

        if ( '1' === $mode ) {
            $this->assertStringNotContainsString( 'class="ui blue message"', $html );
            $this->assertStringNotContainsString( esc_attr( $command ), $html );
            $this->assertStringNotContainsString( esc_attr( $url ), $html );
            $this->assertStringNotContainsString( 'aria-label="Cron command"', $html );
            $this->assertStringNotContainsString( 'aria-label="Cron URL"', $html );
            return;
        }

        $this->assertStringContainsString( 'class="ui blue message"', $html );
        $this->assertStringContainsString( '<div class="header">System cron</div>', $html );
        $this->assertStringContainsString( 'From the command line no key is needed:', $html );
        $this->assertStringContainsString( 'If your cron calls a URL with wget or curl, include your cron key:', $html );
        $this->assertStringContainsString( 'Use the same pattern for the other scripts in the cron folder. To set your own key, define MAINWP_CRON_SECRET in wp-config.php.', $html );
        $this->assertStringContainsString( 'type="text" readonly aria-label="Cron command" value="' . esc_attr( $command ) . '"', $html );
        $this->assertStringContainsString( 'type="text" readonly aria-label="Cron URL" value="' . esc_attr( $url ) . '"', $html );
    }

    /**
     * Both persisted cron modes must render correctly.
     *
     * @return array Cron settings.
     */
    public static function cron_modes() {
        return array(
            'system cron' => array( '0' ),
            'WP-Cron'     => array( '1' ),
        );
    }
}
