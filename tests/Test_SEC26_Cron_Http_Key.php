<?php
/**
 * System-cron HTTP authentication.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_Server_Information;
use MainWP\Dashboard\MainWP_Install;
use MainWP\Dashboard\MainWP_Logger;
use MainWP\Dashboard\MainWP_Post_Handler;
use MainWP\Dashboard\MainWP_System_View;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Capture AJAX termination without changing production handlers.
// phpcs:disable WordPress.Security.NonceVerification -- Fixtures exercise the production nonce checks.

/** Keeps AJAX termination outside handlers that catch Exception. */
class SEC26_Response_Stop extends \Error {}

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
        delete_option( 'mainwp_cron_key_state' );
        foreach ( array( 'updatescheck.php', 'stats.php' ) as $script ) {
            delete_transient( 'mainwp_cron_keyless_' . $script );
        }
    }

    /**
     * Do not leave cron settings or an authenticated user for later tests.
     */
    public function tear_down() {
        delete_option( 'mainwp_cron_secret' );
        delete_option( 'mainwp_wp_cron' );
        delete_option( 'mainwp_cron_key_state' );
        foreach ( array( 'updatescheck.php', 'stats.php' ) as $script ) {
            delete_transient( 'mainwp_cron_keyless_' . $script );
        }
        $_POST    = array();
        $_REQUEST = array();
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
     * A corrupted stored key must be replaced, not returned.
     *
     * @dataProvider invalid_stored_secrets
     * @param mixed $stored Value already in the option.
     */
    public function test_invalid_stored_secret_is_replaced( $stored ) {
        update_option( 'mainwp_cron_secret', $stored, false );

        $secret = MainWP_Server_Information::get_cron_secret();

        $this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{32}$/', $secret );
        $this->assertSame( $secret, get_option( 'mainwp_cron_secret' ) );
    }

    /**
     * Shapes that cannot authenticate a caller.
     *
     * @return array Stored values.
     */
    public static function invalid_stored_secrets() {
        return array(
            'empty string' => array( '' ),
            'array'        => array( array( 'sec26' ) ),
        );
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
        $this->assertStringContainsString( 'Command line (no key needed):', $html );
        $this->assertStringContainsString( 'URL for wget or curl (key included):', $html );
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

    /** Fresh installations require a key; existing installations start grace once. */
    public function test_install_initializes_state_and_secret_before_other_migrations() {
        foreach ( array( false, get_site_option( 'mainwp_db_version' ) ) as $version ) {
            delete_option( 'mainwp_cron_key_state' );
            delete_option( 'mainwp_cron_secret' );
            update_site_option( 'mainwp_db_version', $version );
            $stop = static function () {
                throw new \RuntimeException( 'SEC26 state initialized' );
            };
            add_action( 'add_option_mainwp_cron_key_state', $stop );
            $before = time();
            try {
                MainWP_Install::instance()->install();
                $this->fail( 'Installation must initialize cron state.' );
            } catch ( \RuntimeException $error ) {
                $this->assertSame( 'SEC26 state initialized', $error->getMessage() );
            } finally {
                remove_action( 'add_option_mainwp_cron_key_state', $stop );
            }
            $state = get_option( 'mainwp_cron_key_state' );
            $this->assertSame( $version ? 'grace' : 'required', $state['mode'] );
            $this->assertSame( 32, strlen( get_option( 'mainwp_cron_secret' ) ) );
            $this->assertArrayHasKey( 'mainwp_cron_key_state', wp_load_alloptions() );
            if ( $version ) {
                $this->assertGreaterThanOrEqual( $before, $state['grace_start'] );
                $this->assertTrue( $this->http( '' ) );
            } else {
                $this->assertArrayNotHasKey( 'grace_start', $state );
                $this->assertFalse( $this->http( '' ) );
            }
        }
    }

    /** An equal-version install does not renew grace or read the stored secret. */
    public function test_install_preserves_existing_state_without_reading_secret() {
        $state = array(
            'mode'        => 'grace',
            'grace_start' => time() - DAY_IN_SECONDS,
        );
        update_option( 'mainwp_cron_key_state', $state, true );
        $reads = 0;
        $read  = static function ( $pre ) use ( &$reads ) {
            ++$reads;
            return $pre;
        };
        add_filter( 'pre_option_mainwp_cron_secret', $read );
        try {
            MainWP_Install::instance()->install();
        } finally {
            remove_filter( 'pre_option_mainwp_cron_secret', $read );
        }
        $this->assertSame( $state, get_option( 'mainwp_cron_key_state' ) );
        $this->assertSame( 0, $reads );
    }

    /** Grace permits empty keys, rejects wrong keys, and throttles warnings per script. */
    public function test_grace_logs_only_basenames_once_per_script_per_hour() {
        $now = time();
        update_option(
            'mainwp_cron_key_state',
            array(
                'mode'        => 'grace',
                'grace_start' => $now,
            ),
            true
        );
        update_option( 'mainwp_cron_secret', 'never-log-this-secret', false );
        $logger   = MainWP_Logger::instance();
        $priority = new \ReflectionProperty( $logger, 'logPriority' );
        $specific = new \ReflectionProperty( $logger, 'logSpecific' );
        $priority->setAccessible( true );
        $specific->setAccessible( true );
        $previous = array( $priority->getValue( $logger ), $specific->getValue( $logger ) );
        $logger->set_log_priority( MainWP_Logger::DISABLED );
        $logs    = array();
        $capture = static function ( $text, $level ) use ( &$logs ) {
            $logs[] = array( $text, $level );
        };
        add_action( 'mainwp_before_log_data', $capture, 10, 2 );
        try {
            $this->assertTrue( $this->http( '', 'updatescheck.php', $now ) );
            $first = get_option( 'mainwp_cron_key_state' );
            $this->assertTrue( $this->http( null, 'updatescheck.php', $now + 30 ) );
            $this->assertSame( $first, get_option( 'mainwp_cron_key_state' ) );
            $this->assertTrue( $this->http( '', 'stats.php', $now + 40 ) );
            $this->assertSame( 'stats.php', get_option( 'mainwp_cron_key_state' )['keyless_script'] );
            $this->assertTrue( $this->http( '', 'updatescheck.php', $now + 50 ) );
            $this->assertCount( 2, $logs );
            $this->assertTrue( $this->http( '', 'updatescheck.php', $now + HOUR_IN_SECONDS ) );
            $this->assertCount( 3, $logs );
            // The state hour counts from the last write at +50, so this call leaves it alone.
            $this->assertSame( $now + 50, get_option( 'mainwp_cron_key_state' )['keyless_seen'] );
            $this->assertTrue( $this->http( '', 'updatescheck.php', $now + 50 + HOUR_IN_SECONDS ) );
            $this->assertSame( $now + 50 + HOUR_IN_SECONDS, get_option( 'mainwp_cron_key_state' )['keyless_seen'] );
            $this->assertCount( 3, $logs );
            $this->assertFalse( $this->http( 'wrong-key', 'updatescheck.php', $now ) );
            $this->assertFalse( $this->http( array( 'key' ), 'updatescheck.php', $now ) );
            $this->assertCount( 3, $logs );
            foreach ( $logs as list( $text, $level ) ) {
                $this->assertSame( MainWP_Logger::WARNING, $level );
                $this->assertMatchesRegularExpression( '/Cron script (updatescheck|stats)\.php was called by web address without a key\./', $text );
                $this->assertStringNotContainsString( 'never-log-this-secret', $text );
                $this->assertStringNotContainsString( '?', $text );
                $this->assertStringNotContainsString( 'private-query', $text );
                $this->assertStringNotContainsString( 'https://', $text );
            }
        } finally {
            remove_action( 'mainwp_before_log_data', $capture );
            $logger->set_log_priority( $previous[0], $previous[1] );
        }
    }

    /** Expiry is inclusive, and the blocked state is recorded without waiting an hour. */
    public function test_expiry_blocks_keyless_and_keyed_call_clears_notice_state() {
        $now = time();
        $end = $now + 60 * DAY_IN_SECONDS;
        update_option(
            'mainwp_cron_key_state',
            array(
                'mode'        => 'grace',
                'grace_start' => $now,
            ),
            true
        );
        update_option( 'mainwp_cron_secret', 'sec26-key', false );
        $this->assertTrue( $this->http( '', 'updatescheck.php', $end - 1 ) );
        $this->assertFalse( $this->http( '', 'updatescheck.php', $end ) );
        $state = get_option( 'mainwp_cron_key_state' );
        $this->assertTrue( $state['keyless_blocked'] );
        $this->assertSame( $end, $state['keyless_seen'] );
        $this->assertFalse( $this->http( '', 'updatescheck.php', $end + 10 ) );
        $this->assertSame( $state, get_option( 'mainwp_cron_key_state' ) );
        $this->assertTrue( $this->http( 'sec26-key', 'updatescheck.php', $end ) );
        $this->assertSame(
            array(
                'mode'        => 'grace',
                'grace_start' => $now,
            ),
            get_option( 'mainwp_cron_key_state' )
        );
        $writes  = 0;
        $updated = static function () use ( &$writes ) {
            ++$writes;
        };
        add_action( 'update_option_mainwp_cron_key_state', $updated );
        try {
            $this->assertTrue( $this->http( 'sec26-key', 'updatescheck.php', $end ) );
        } finally {
            remove_action( 'update_option_mainwp_cron_key_state', $updated );
        }
        $this->assertSame( 0, $writes );
    }

    /** The normal filter allows a short grace period without a debug switch. */
    public function test_grace_days_filter_and_shell_exemptions() {
        add_filter( 'mainwp_cron_key_grace_days', '__return_zero' );
        try {
            foreach ( array(
                array( 'mode' => 'required' ),
                array(
                    'mode'        => 'grace',
                    'grace_start' => time(),
                ),
            ) as $state ) {
                update_option( 'mainwp_cron_key_state', $state, true );
                $this->assertTrue( MainWP_Server_Information::is_cron_request_allowed( 'cli', array( 'REQUEST_METHOD' => 'GET' ), '' ) );
                $this->assertTrue( MainWP_Server_Information::is_cron_request_allowed( 'cgi-fcgi', array(), '' ) );
                $this->assertSame( $state, get_option( 'mainwp_cron_key_state' ) );
                $this->assertFalse( $this->http( '' ) );
            }
        } finally {
            remove_filter( 'mainwp_cron_key_grace_days', '__return_zero' );
        }
    }

    /**
     * A request that skipped install() must not write a state without a mode, or install() would never seed it.
     */
    public function test_keyless_call_before_install_leaves_state_for_installer() {
        delete_option( 'mainwp_cron_key_state' );

        $this->assertFalse( $this->http( '' ) );
        $this->assertFalse( get_option( 'mainwp_cron_key_state' ) );

        $partial = array( 'keyless_seen' => 1 );
        update_option( 'mainwp_cron_key_state', $partial, true );
        $this->assertFalse( $this->http( '' ) );
        $this->assertSame( $partial, get_option( 'mainwp_cron_key_state' ) );
    }

    /** Notice paths are mutually exclusive and retain exact actionable copy. */
    public function test_notice_renders_once_on_mainwp_and_wordpress_pages_then_clears() {
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        if ( ! function_exists( 'set_current_screen' ) ) {
            require_once ABSPATH . 'wp-admin/includes/screen.php';
        }
        // A fresh test install queues the setup wizard, whose current_screen redirect would exit.
        update_option( 'mainwp_run_quick_setup', 'no' );
        delete_option( 'mainwp_activated' );
        $now = time();
        update_option( 'mainwp_cron_secret', 'sec26<&"key', false );
        update_option(
            'mainwp_cron_key_state',
            array(
                'mode'           => 'grace',
                'grace_start'    => $now,
                'keyless_seen'   => $now,
                'keyless_script' => 'stats.php',
            ),
            true
        );
        foreach ( array( 'mainwp_page_ServerInformation', 'dashboard', 'plugins', 'settings' ) as $screen ) {
            set_current_screen( $screen );
            $html = $this->capture_render(
                static function () use ( $screen ) {
                    MainWP_System_View::wp_admin_notices();
                    if ( 'mainwp_page_ServerInformation' === $screen ) {
                        MainWP_System_View::admin_notices();
                    }
                }
            );
            $this->assertSame( 1, substr_count( $html, 'Update your cron job URL' ) );
            $this->assertStringContainsString( esc_attr( MAINWP_PLUGIN_URL . 'cron/stats.php?key=' . rawurlencode( 'sec26<&"key' ) ), $html );
            $this->assertStringContainsString( esc_html( sprintf( "Your server runs MainWP's cron scripts by web address without a key. From %s these calls will be blocked, and scheduled updates, syncs and backups will stop. In your hosting cron job or cron service, replace the address with this one:", date_i18n( get_option( 'date_format' ), $now + 60 * DAY_IN_SECONDS ) ) ), $html );
            $this->assertStringContainsString( 'data-clipboard-target="#mainwp-cron-key-notice-url"', $html );
            $this->assertStringContainsString( 'data-copy-success="Copied"', $html );
            $this->assertTrue( wp_script_is( 'mainwp-code-copy', 'enqueued' ) );
            $this->assertTrue( wp_script_is( 'clipboard', 'enqueued' ) );
            if ( 'dashboard' === $screen ) {
                $this->assertStringContainsString( 'notice notice-warning', $html );
                $this->assertStringNotContainsString( 'is-dismissible', $html );
            }
        }
        $state                = get_option( 'mainwp_cron_key_state' );
        $state['grace_start'] = $now - 60 * DAY_IN_SECONDS;
        update_option( 'mainwp_cron_key_state', $state, true );
        $this->assertFalse( $this->http( '' ) );
        $html = $this->capture_render( array( MainWP_System_View::class, 'wp_admin_notices' ) );
        $this->assertStringContainsString( esc_html( "Your server is calling MainWP's cron scripts by web address without a key, and these calls are being blocked. Scheduled updates, syncs and backups are not running. In your hosting cron job or cron service, replace the address with this one:" ), $html );
        $this->assertStringContainsString( 'Use the same key for the other scripts in the cron folder. This notice goes away after the first call with the key.', $html );
        $this->assertTrue( $this->http( 'sec26<&"key' ) );
        $this->assertStringNotContainsString( 'Update your cron job URL', $this->capture_render( array( MainWP_System_View::class, 'wp_admin_notices' ) ) );
        set_current_screen( 'front' );
    }

    /** Server-information access alone must never reveal a credential. */
    public function test_settings_right_is_required_for_notice_and_key_visibility() {
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        update_option( 'mainwp_wp_cron', '0' );
        update_option( 'mainwp_cron_secret', 'sec26-private-key', false );
        update_option(
            'mainwp_cron_key_state',
            array(
                'mode'            => 'required',
                'keyless_seen'    => time(),
                'keyless_script'  => 'stats.php',
                'keyless_blocked' => true,
            ),
            true
        );
        $deny = static function ( $allowed, $slug, $right ) {
            return 'manage_dashboard_settings' === $right ? false : $allowed;
        };
        add_filter( 'mainwp_currentusercan', $deny, 999, 3 );
        try {
            $html = $this->capture_render( array( MainWP_Server_Information::class, 'render_cron' ) );
            $this->assertStringContainsString( esc_attr( 'php ' . MAINWP_PLUGIN_DIR . 'cron/updatescheck.php' ), $html );
            $this->assertStringNotContainsString( 'sec26-private-key', $html );
            $this->assertStringNotContainsString( '?key=', $html );
            $this->assertStringNotContainsString( 'Rotate key', $html );
            $this->assertSame( '', $this->capture_render( array( MainWP_System_View::class, 'render_cron_key_notice' ) ) );
        } finally {
            remove_filter( 'mainwp_currentusercan', $deny, 999 );
        }
        // Without Team Control every user passes the rights filter; only administrators may see the key.
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
        $this->assertSame( '', $this->capture_render( array( MainWP_System_View::class, 'render_cron_key_notice' ) ) );
        $this->assertStringNotContainsString( '?key=', $this->capture_render( array( MainWP_Server_Information::class, 'render_cron' ) ) );
        // level_10 makes staff pass the administrator check, so only the staff check hides the notice.
        add_role(
            'mainwp_sec26_staff',
            'SEC26 staff',
            array(
                'read'     => true,
                'level_10' => true,
            )
        );
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'mainwp_sec26_staff' ) ) );
        add_filter( 'mainwp_currentusercan', '__return_true', 999 );
        try {
            $this->assertTrue( \MainWP\Dashboard\MainWP_System_Utility::is_admin() );
            $this->assertSame( '', $this->capture_render( array( MainWP_System_View::class, 'render_cron_key_notice' ) ) );
            $html = $this->capture_render( array( MainWP_Server_Information::class, 'render_cron' ) );
            $this->assertStringNotContainsString( '?key=', $html );
            $this->assertStringNotContainsString( 'Rotate key', $html );
        } finally {
            remove_filter( 'mainwp_currentusercan', '__return_true', 999 );
            remove_role( 'mainwp_sec26_staff' );
        }
    }

    /** Rotation must invalidate the current URL and enforce both authorization checks. */
    public function test_rotate_requires_capability_and_nonce_then_invalidates_old_key() {
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        update_option( 'mainwp_cron_secret', 'sec26-old-key', false );
        update_option( 'mainwp_cron_key_state', array( 'mode' => 'required' ), true );
        update_option( 'mainwp_wp_cron', '0' );
        MainWP_Post_Handler::instance()->init();
        $this->assertNotFalse( has_action( 'wp_ajax_mainwp_cron_secret_rotate' ) );
        $deny = static function ( $allowed, $slug, $right ) {
            return 'manage_dashboard_settings' === $right ? false : $allowed;
        };
        add_filter( 'mainwp_currentusercan', $deny, 999, 3 );
        try {
            $response = json_decode( $this->rotate(), true );
            $this->assertFalse( $response['success'] );
            $this->assertSame( 'sec26-old-key', get_option( 'mainwp_cron_secret' ) );
        } finally {
            remove_filter( 'mainwp_currentusercan', $deny, 999 );
        }
        $response = json_decode( $this->rotate( false ), true );
        $this->assertNotEmpty( $response['error'] );
        $this->assertSame( 'sec26-old-key', get_option( 'mainwp_cron_secret' ) );
        $response = json_decode( $this->rotate(), true );
        $this->assertTrue( $response['success'] );
        $new = get_option( 'mainwp_cron_secret' );
        $this->assertNotSame( 'sec26-old-key', $new );
        $this->assertSame( MAINWP_PLUGIN_URL . 'cron/updatescheck.php?key=' . rawurlencode( $new ), $response['data']['url'] );
        $this->assertFalse( $this->http( 'sec26-old-key' ) );
        $this->assertTrue( $this->http( $new ) );
        $html = $this->capture_render( array( MainWP_Server_Information::class, 'render_cron' ) );
        $this->assertStringContainsString( 'Rotate key', $html );
        $this->assertStringContainsString( 'mainwp_secure_data', $html );
        $this->assertStringContainsString( 'Rotate the cron key? Cron jobs that use the current URLs stop working right away, so update them afterwards.', $html );
        // Team Control staff cannot see the key, so they cannot rotate it either.
        add_role(
            'mainwp_sec26_staff',
            'SEC26 staff',
            array(
                'read'     => true,
                'level_10' => true,
            )
        );
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'mainwp_sec26_staff' ) ) );
        add_filter( 'mainwp_currentusercan', '__return_true', 999 );
        try {
            $response = json_decode( $this->rotate(), true );
            $this->assertFalse( $response['success'] );
            $this->assertArrayNotHasKey( 'url', (array) $response['data'] );
            $this->assertSame( $new, get_option( 'mainwp_cron_secret' ) );
        } finally {
            remove_filter( 'mainwp_currentusercan', '__return_true', 999 );
            remove_role( 'mainwp_sec26_staff' );
        }
    }

    /**
     * The wp-config override still authenticates and cannot be rotated from the UI.
     *
     * The constant cannot be undefined, so it lives in its own process.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_wp_config_secret_override() {
        define( 'MAINWP_CRON_SECRET', 'sec26-config-key' );
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        update_option( 'mainwp_cron_secret', 'sec26-stored-key', false );
        update_option( 'mainwp_cron_key_state', array( 'mode' => 'required' ), true );
        update_option( 'mainwp_wp_cron', '0' );

        $this->assertSame( 'sec26-config-key', MainWP_Server_Information::get_cron_secret() );
        $this->assertTrue( $this->http( 'sec26-config-key' ) );
        $this->assertFalse( $this->http( 'sec26-stored-key' ) );
        $this->assertFalse( $this->http( '' ) );

        $html = $this->capture_render( array( MainWP_Server_Information::class, 'render_cron' ) );
        $this->assertStringContainsString( esc_attr( MAINWP_PLUGIN_URL . 'cron/updatescheck.php?key=sec26-config-key' ), $html );
        $this->assertStringContainsString( 'The key is set by MAINWP_CRON_SECRET in wp-config.php. Change it there.', $html );
        $this->assertStringNotContainsString( 'Rotate key', $html );
        $this->assertStringNotContainsString( 'mainwp_cron_secret_rotate', $html );

        $response = json_decode( $this->rotate(), true );
        $this->assertFalse( $response['success'] );
        $this->assertSame( 'The key is set by MAINWP_CRON_SECRET in wp-config.php. Change it there.', $response['data'] );
        $this->assertSame( 'sec26-stored-key', get_option( 'mainwp_cron_secret' ) );
    }

    /**
     * Keep XML constants from being replayed before the isolated WP bootstrap.
     *
     * @param \PHPUnit\Framework\TestResult|null $result Result collector.
     * @return \PHPUnit\Framework\TestResult
     */
    public function run( ?\PHPUnit\Framework\TestResult $result = null ): \PHPUnit\Framework\TestResult {
        if ( 'test_wp_config_secret_override' !== $this->getName( false ) ) {
            return parent::run( $result );
        }
        $configuration                           = $GLOBALS['__PHPUNIT_CONFIGURATION_FILE'] ?? null;
        $GLOBALS['__PHPUNIT_CONFIGURATION_FILE'] = '';
        try {
            return parent::run( $result );
        } finally {
            $GLOBALS['__PHPUNIT_CONFIGURATION_FILE'] = $configuration;
        }
    }

    /**
     * Invoke the HTTP guard with a URL that must never reach a log.
     *
     * @param mixed  $key Caller key.
     * @param string $script Script basename.
     * @param int    $now Request time.
     * @return bool Authorization result.
     */
    private function http( $key, $script = 'updatescheck.php', $now = null ) {
        return MainWP_Server_Information::is_cron_request_allowed(
            'fpm-fcgi',
            array(
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI'    => '/cron/updatescheck.php?private-query=never-log-this-secret',
            ),
            $key,
            $script,
            $now
        );
    }

    /**
     * Capture markup without sending output to PHPUnit.
     *
     * @param callable $callback Renderer.
     * @return string Markup.
     */
    private function capture_render( callable $callback ) {
        ob_start();
        try {
            $callback();
            return ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Exercise the real nonce and capability code, replacing only process exits.
     *
     * @param bool $valid_nonce Whether the request nonce is valid.
     * @return string AJAX body.
     */
    private function rotate( $valid_nonce = true ) {
        if ( ! class_exists( '\\MainWP\\Dashboard\\SEC26_Post_Handler', false ) ) {
            $names = array(
                'MainWP_Post_Base_Handler' => 'SEC26_Post_Base_Handler',
                'MainWP_Post_Handler'      => 'SEC26_Post_Handler',
            );
            foreach ( array( 'class/class-mainwp-post-base-handler.php', 'class/class-mainwp-post-handler.php' ) as $file ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read the production handler to replace raw exits for this fixture.
                $tokens = token_get_all( file_get_contents( dirname( __DIR__ ) . '/' . $file ) );
                $code   = '';
                foreach ( $tokens as $token ) {
                    if ( is_array( $token ) ) {
                        $code .= T_EXIT === $token[0] ? '\\wp_die' : ( T_STRING === $token[0] && isset( $names[ $token[1] ] ) ? $names[ $token[1] ] : $token[1] );
                    } else {
                        $code .= $token;
                    }
                }
                $code = str_replace( '\\wp_die;', '\\wp_die();', $code );
                eval( '?>' . $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Preserve real nonce checks while capturing termination.
            }
        }
        $_POST    = array(
            'security' => $valid_nonce ? wp_create_nonce( 'mainwp_cron_secret_rotate' ) : 'invalid',
            'dts'      => wp_generate_uuid4(),
        );
        $_REQUEST = $_POST;
        $handler  = static function () {
            return static function ( $message ) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve the AJAX response for assertions.
                throw new SEC26_Response_Stop( (string) $message );
            };
        };
        add_filter( 'wp_doing_ajax', '__return_true' );
        add_filter( 'wp_die_ajax_handler', $handler );
        ob_start();
        try {
            \MainWP\Dashboard\SEC26_Post_Handler::instance()->mainwp_cron_secret_rotate();
        } catch ( SEC26_Response_Stop $error ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Capture exact JSON and nonce failure bodies.
            echo $error->getMessage();
        } finally {
            $body = ob_get_clean();
            remove_filter( 'wp_die_ajax_handler', $handler );
            remove_filter( 'wp_doing_ajax', '__return_true' );
        }
        return $body;
    }
}
