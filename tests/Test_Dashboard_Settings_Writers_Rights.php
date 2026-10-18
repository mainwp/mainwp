<?php
/**
 * Settings nonces must not bypass Team Control rights.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- A distinct Error keeps captured exits outside legacy Exception handlers.
// phpcs:disable WordPress.Security.NonceVerification.Missing -- Fixtures exercise the production nonce checks rather than handling live requests.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Preserve exact response bodies for assertions without browser output.
// phpcs:disable WordPress.WP.AlternativeFunctions -- Source copies and temporary filesystem fixtures require local file operations.

use MainWP\Dashboard\MainWP_Backup_Handler;
use MainWP\Dashboard\MainWP_DB_Common;
use MainWP\Dashboard\MainWP_DB_Uptime_Monitoring;
use MainWP\Dashboard\MainWP_Monitoring_Handler;
use MainWP\Dashboard\MainWP_Notification_Template;
use MainWP\Dashboard\MainWP_Settings;
use MainWP\Dashboard\Settings_Rights_Post_Handler;
use MainWP\Dashboard\Settings_Rights_Uptime_Edit;

/** AJAX termination must escape handlers that catch Exception. */
class Settings_Rights_Response_Stop extends \Error {}

/** Preserve the state behind each settings writer when its right is refused. */
class Test_Dashboard_Settings_Writers_Rights extends \WP_UnitTestCase {

    use Site_Fixture;

    /**
     * Test-owned callbacks.
     *
     * @var array
     */
    private $filters = array();

    /**
     * Temporary template directory.
     *
     * @var string Temporary template directory.
     */
    private $template_root = '';

    /**
     * Original template paths.
     *
     * @var array Original template paths.
     */
    private $template_paths = array();

    /** Raw exits would prevent assertions against the unchanged writers. */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        $names = array(
            'MainWP_Post_Handler'           => 'Settings_Rights_Post_Handler',
            'MainWP_Uptime_Monitoring_Edit' => 'Settings_Rights_Uptime_Edit',
        );
        foreach ( array( 'class/class-mainwp-post-handler.php', 'class/class-mainwp-uptime-monitoring-edit.php' ) as $file ) {
            $code = '';
            foreach ( token_get_all( file_get_contents( dirname( __DIR__ ) . '/' . $file ) ) as $token ) {
                if ( is_array( $token ) ) {
                    $code .= T_EXIT === $token[0] ? '\\wp_die' : ( T_STRING === $token[0] && isset( $names[ $token[1] ] ) ? $names[ $token[1] ] : $token[1] );
                } else {
                    $code .= $token;
                }
            }
            $code = str_replace( '\\wp_die;', '\\wp_die();', $code );
            eval( '?>' . $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Capture legacy exits without changing production authorization or writes.
        }
    }

    /** WordPress capabilities remain granted while MainWP rights vary. */
    public function setUp(): void {
        parent::setUp();
        wp_create_nonce( 'mainwp-settings-rights-fixture' );
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        $_GET     = array();
        $_POST    = array();
        $_REQUEST = array();
        if ( ! function_exists( 'set_current_screen' ) ) {
            require_once ABSPATH . 'wp-admin/includes/screen.php';
        }
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        set_current_screen( 'dashboard' );
        $this->filter( 'mainwp_currentusercan', '__return_true' );
    }

    /** Template fixtures live outside WordPress transaction cleanup. */
    public function tearDown(): void {
        foreach ( $this->template_paths as $name => $path ) {
            $property = new \ReflectionProperty( MainWP_Notification_Template::class, $name );
            $property->setAccessible( true );
            $property->setValue( MainWP_Notification_Template::instance(), $path );
        }
        if ( $this->template_root ) {
            if ( is_dir( $this->template_root . '/source' ) ) {
                unlink( $this->template_root . '/source/fixture.php' );
                rmdir( $this->template_root . '/source' );
            }
            foreach ( glob( $this->template_root . '/*' ) as $file ) {
                unlink( $file );
            }
            rmdir( $this->template_root );
        }
        foreach ( $this->filters as $hook => $callback ) {
            remove_filter( $hook, $callback, 999 );
        }
        $_GET     = array();
        $_POST    = array();
        $_REQUEST = array();
        wp_set_current_user( 0 );
        parent::tearDown();
    }

    /**
     * Granted and refused settings rights.
     *
     * @return array Granted and refused settings rights.
     */
    public static function rights(): array {
        return array(
            'refused' => array( false ),
            'allowed' => array( true ),
        );
    }

    /**
     * A valid nonce must not bypass the settings right on arbitrary options.
     *
     * @dataProvider rights
     * @param bool $allowed Settings right.
     */
    public function test_ajax_save_settings_right( bool $allowed ): void {
        update_option( 'mainwp_scope_writer_fixture', 'original' );
        $this->settings_right( $allowed );
        $before   = $this->snapshot();
        $response = $this->ajax(
            'mainwp_save_settings',
            array(
                'name'  => 'scope_writer_fixture',
                'value' => 'replacement',
            )
        );
        if ( $allowed ) {
            $this->assertSame( 'replacement', get_option( 'mainwp_scope_writer_fixture' ) );
            $this->assertSame( 'ok', $response );
        } else {
            $this->assertSame( $before, $this->snapshot() );
            $message = 'Error - insufficient permissions!';
            $this->assertSame(
                array(
                    'success' => false,
                    'result'  => $message,
                    'error'   => $message,
                ),
                json_decode( $response, true )
            );
        }
    }

    /**
     * Refusal must preserve the user extension as well as every option.
     *
     * @dataProvider rights
     * @param bool $allowed Settings right.
     */
    public function test_general_settings_right_precedes_user_extension_write( bool $allowed ): void {
        $extension            = MainWP_DB_Common::instance()->get_user_extension();
        $extension->pluginDir = 'preserve-plugin-directory';
        MainWP_DB_Common::instance()->update_user_extension( $extension );
        update_option( 'mainwp_pluginAutomaticDailyUpdate', 0 );
        $_POST = array(
            'submit'                            => '1',
            'wp_nonce'                          => wp_create_nonce( 'Settings' ),
            'mainwp_pluginAutomaticDailyUpdate' => '1',
        );
        $this->settings_right( $allowed );
        $before = $this->snapshot();
        MainWP_Settings::handle_settings_post();
        if ( $allowed ) {
            $this->assertSame( 1, (int) get_option( 'mainwp_pluginAutomaticDailyUpdate' ) );
            $this->assertSame( '', MainWP_DB_Common::instance()->get_user_extension()->pluginDir );
        } else {
            $this->assertSame( $before, $this->snapshot() );
        }
    }

    /**
     * Backup tuning remains unchanged without the settings right.
     *
     * @dataProvider rights
     * @param bool $allowed Settings right.
     */
    public function test_backup_settings_right( bool $allowed ): void {
        update_option( 'mainwp_backupsOnServer', 2 );
        $_POST = array(
            'submit'                        => '1',
            'wp_nonce'                      => wp_create_nonce( 'Settings' ),
            'mainwp_options_backupOnServer' => '7',
        );
        $this->settings_right( $allowed );
        $before = $this->snapshot();
        // Deprecated shim over MainWP_Backup_Settings::handle_settings_post().
        $this->setExpectedDeprecated( 'MainWP\Dashboard\MainWP_Backup_Handler::handle_settings_post' );
        $saved = MainWP_Backup_Handler::handle_settings_post();
        if ( $allowed ) {
            $this->assertSame( 7, (int) get_option( 'mainwp_backupsOnServer' ) );
            $this->assertTrue( $saved );
        } else {
            $this->assertSame( $before, $this->snapshot() );
            $this->assertFalse( $saved );
        }
    }

    /**
     * Monitoring writes require the settings right even with a valid nonce.
     *
     * @dataProvider rights
     * @param bool $allowed Settings right.
     */
    public function test_monitoring_settings_right( bool $allowed ): void {
        update_option( 'mainwp_sitehealthThreshold', 80 );
        $_POST = array(
            'submit'                      => '1',
            'wp_nonce'                    => wp_create_nonce( 'MonitoringSettings' ),
            'mainwp_site_healthThreshold' => '100',
        );
        $this->settings_right( $allowed );
        $before = $this->snapshot();
        $saved  = MainWP_Monitoring_Handler::handle_settings_post();
        if ( $allowed ) {
            $this->assertSame( 100, (int) get_option( 'mainwp_sitehealthThreshold' ) );
            $this->assertTrue( $saved );
        } else {
            $this->assertSame( $before, $this->snapshot() );
            $this->assertFalse( $saved );
        }
    }

    /**
     * Refusal must preserve both auto-update and trusted-plugin state.
     *
     * @dataProvider rights
     * @param bool $allowed Settings right.
     */
    public function test_autoupdate_and_trust_right( bool $allowed ): void {
        update_option( 'mainwp_pluginAutomaticDailyUpdate', 0 );
        $extension                  = MainWP_DB_Common::instance()->get_user_extension();
        $extension->trusted_plugins = wp_json_encode( array( 'fixture/plugin.php' ) );
        MainWP_DB_Common::instance()->update_user_extension( $extension );
        $this->settings_right( $allowed );
        $before   = $this->snapshot();
        $response = $this->ajax( 'mainwp_autoupdate_and_trust_child' );
        if ( $allowed ) {
            $this->assertSame( 1, (int) get_option( 'mainwp_pluginAutomaticDailyUpdate' ) );
            $this->assertSame( array( 'fixture/plugin.php', 'mainwp-child/mainwp-child.php' ), json_decode( MainWP_DB_Common::instance()->get_user_extension()->trusted_plugins, true ) );
            $this->assertSame( 'ok', $response );
        } else {
            $this->assertSame( $before, $this->snapshot() );
            $this->assertSame( array( 'error' => 'Error - insufficient permissions!' ), json_decode( $response, true ) );
        }
    }

    /**
     * Settings and site-edit rights for global saves.
     *
     * @return array Settings and site-edit rights for global saves.
     */
    public static function global_rights(): array {
        return array(
            'refused'       => array( false, true ),
            'allowed'       => array( true, true ),
            'settings only' => array( true, false ),
        );
    }

    /**
     * Global defaults depend on the settings right independently of site-edit rights.
     *
     * @dataProvider global_rights
     * @param bool $settings Settings right.
     * @param bool $edit_sites Site-edit right.
     */
    public function test_uptime_global_right( bool $settings, bool $edit_sites ): void {
        update_option(
            'mainwp_global_uptime_monitoring_settings',
            array(
                'active'           => 0,
                'keyword'          => 'original',
                'retention_limits' => 180,
                'bypass_cache'     => 0,
            )
        );
        $_POST = $this->monitor_post();
        $this->settings_right( $settings, $edit_sites );
        $before = $this->snapshot();
        Settings_Rights_Uptime_Edit::instance()->handle_save_settings();
        if ( $settings ) {
            $this->assertSame( 'replacement', get_option( 'mainwp_global_uptime_monitoring_settings' )['keyword'] );
            $this->assertSame( 0, (int) get_option( 'mainwp_uptime_monitor_cleanup_heartbeat_at' ) );
        } else {
            $this->assertSame( $before, $this->snapshot() );
        }
    }

    /**
     * Both site resolution paths and independent edit rights.
     *
     * @return array Both site resolution paths and independent edit rights.
     */
    public static function site_rights(): array {
        return array(
            'monitor_wpid refused' => array( false, true, 'monitor_wpid' ),
            'id refused'           => array( false, true, 'id' ),
            'submonitor refused'   => array( false, true, 'submonitor' ),
            'monitor_wpid allowed' => array( true, true, 'monitor_wpid' ),
            'id allowed'           => array( true, true, 'id' ),
        );
    }

    /**
     * Per-site refusal must preserve the monitor and prevent redirects.
     *
     * @dataProvider site_rights
     * @param bool   $scope Site right.
     * @param bool   $edit_sites Site-edit right.
     * @param string $route Site ID source.
     */
    public function test_uptime_site_right( bool $scope, bool $edit_sites, string $route ): void {
        $site   = $this->create_test_site();
        $other  = $this->create_test_site();
        $db     = MainWP_DB_Uptime_Monitoring::instance();
        $output = array();
        $db->update_wp_monitor(
            array(
                'wpid'    => $site,
                'keyword' => 'original',
                'active'  => 0,
            ),
            $output
        );
        $before   = $db->get_monitor_by( $site, 'issub', 0, array(), ARRAY_A );
        $_POST    = array_merge(
            $this->monitor_post(),
            array(
                'mainwp_edit_monitor_individual_settings' => '1',
                'mainwp_edit_monitor_id'                  => $before['monitor_id'],
            )
        );
        $_REQUEST = 'id' !== $route ? array(
            'monitor_wpid' => $site,
            'id'           => $other,
        ) : array(
            'monitor_wpid' => 0,
            'id'           => $site,
        );
        if ( 'submonitor' === $route ) {
            $_POST['update_submonitor'] = '1';
        }
        $this->settings_right( false, $edit_sites );
        $prior = $this->filters['mainwp_currentusercan'];
        $this->filter(
            'mainwp_currentusercan',
            static function ( $can, $type, $cap ) use ( $prior, $scope, $site ) {
                return 'site' === $type && $site === (int) $cap ? $scope : $prior( $can, $type, $cap );
            }
        );
        $redirects = 0;
        $this->filter(
            'wp_redirect',
            static function () use ( &$redirects ) {
                ++$redirects;
                return false;
            }
        );
        $options = $this->snapshot();
        $this->capture(
            static function () {
                Settings_Rights_Uptime_Edit::instance()->handle_save_settings();
            }
        );
        $after = $db->get_monitor_by( $site, 'issub', 0, array(), ARRAY_A );
        if ( $scope && $edit_sites ) {
            $this->assertSame( 'replacement', $after['keyword'] );
        } else {
            $this->assertSame( $before, $after );
            $this->assertSame( $options, $this->snapshot() );
        }
        $this->assertSame( 0, $redirects );
    }

    /**
     * Posted site and monitor ids against the authorized site.
     *
     * @return array Posted site and monitor ids against the authorized site.
     */
    public static function monitor_ownership(): array {
        return array(
            'other primary refused'    => array( 'site', 'other_primary', false, false ),
            'other sub refused'        => array( 'site', 'other_sub', true, false ),
            'missing monitor refused'  => array( 'site', 'missing', false, false ),
            'zero site refused'        => array( 'zero', 'own_primary', false, false ),
            'own primary allowed'      => array( 'site', 'own_primary', false, true ),
            'own sub allowed'          => array( 'site', 'own_sub', true, true ),
            'new sub for site allowed' => array( 'site', 'none', true, true ),
        );
    }

    /**
     * A monitor id from another site must not ride on the authorized site's right.
     *
     * @dataProvider monitor_ownership
     * @param string $site_source Resolved site: the authorized site or zero.
     * @param string $monitor Posted monitor id.
     * @param bool   $sub Sub-monitor form.
     * @param bool   $saved Whether the save goes through.
     */
    public function test_uptime_monitor_must_belong_to_site( string $site_source, string $monitor, bool $sub, bool $saved ): void {
        $site   = $this->create_test_site();
        $other  = $this->create_test_site();
        $db     = MainWP_DB_Uptime_Monitoring::instance();
        $output = array();
        foreach ( array( $site, $other ) as $wpid ) {
            $db->update_wp_monitor(
                array(
                    'wpid'    => $wpid,
                    'keyword' => 'original',
                    'active'  => 0,
                ),
                $output
            );
            $db->update_wp_monitor(
                array(
                    'wpid'    => $wpid,
                    'suburl'  => 'original-sub',
                    'issub'   => 1,
                    'keyword' => 'original',
                ),
                $output
            );
        }
        $ids      = array(
            'own_primary'   => (int) $db->get_monitor_by( $site, 'issub', 0, array(), ARRAY_A )['monitor_id'],
            'own_sub'       => (int) $db->get_monitor_by( $site, 'suburl', 'original-sub', array(), ARRAY_A )['monitor_id'],
            'other_primary' => (int) $db->get_monitor_by( $other, 'issub', 0, array(), ARRAY_A )['monitor_id'],
            'other_sub'     => (int) $db->get_monitor_by( $other, 'suburl', 'original-sub', array(), ARRAY_A )['monitor_id'],
            'missing'       => 999999,
            'none'          => 0,
        );
        $monitors = static function () use ( $db, $site, $other ) {
            return array(
                $db->get_monitors( array( 'wpid' => $site ), ARRAY_A ),
                $db->get_monitors( array( 'wpid' => $other ), ARRAY_A ),
            );
        };
        $before   = $monitors();
        $_POST    = array_merge(
            $this->monitor_post(),
            array(
                'mainwp_edit_monitor_individual_settings' => '1',
                'mainwp_edit_monitor_id'                  => $sub ? $ids['own_primary'] : $ids[ $monitor ],
            )
        );
        $_REQUEST = array( 'monitor_wpid' => 'zero' === $site_source ? 0 : $site );
        if ( $sub ) {
            $_POST['update_submonitor']           = '1';
            $_POST['edit_sub_monitor_id']         = $ids[ $monitor ];
            $_POST['mainwp_edit_monitor_sub_url'] = 'replacement-sub';
            $_POST['monitor_edit_is_sub_url']     = '1';
        }
        $this->settings_right( false );
        $prior = $this->filters['mainwp_currentusercan'];
        $this->filter(
            'mainwp_currentusercan',
            static function ( $can, $type, $cap ) use ( $prior, $site, $site_source ) {
                // A filter that grants id 0 must still not reach the monitor writer.
                return 'site' === $type ? ( $site === (int) $cap || ( 'zero' === $site_source && 0 === (int) $cap ) ) : $prior( $can, $type, $cap );
            }
        );
        $redirects = 0;
        $this->filter(
            'wp_redirect',
            static function () use ( &$redirects ) {
                ++$redirects;
                return false;
            }
        );
        $this->capture(
            static function () {
                Settings_Rights_Uptime_Edit::instance()->handle_save_settings();
            }
        );
        if ( ! $saved ) {
            $this->assertSame( $before, $monitors() );
            $this->assertSame( 0, $redirects );
            return;
        }
        $this->assertSame( $before[1], $monitors()[1] );
        if ( $sub ) {
            $this->assertNotEmpty( $db->get_monitor_by( $site, 'suburl', 'replacement-sub' ) );
            $this->assertSame( 1, $redirects );
        } else {
            $this->assertSame( 'replacement', $db->get_monitor_by( $site, 'issub', 0, array(), ARRAY_A )['keyword'] );
        }
    }

    /**
     * Template actions and settings rights.
     *
     * @return array Template actions and settings rights.
     */
    public static function template_actions(): array {
        return array(
            'delete refused' => array( 'delete', false ),
            'copy refused'   => array( 'copy', false ),
            'delete allowed' => array( 'delete', true ),
            'copy allowed'   => array( 'copy', true ),
        );
    }

    /**
     * The email route must authorize before deleting or copying a global template.
     *
     * @dataProvider template_actions
     * @param string $action Template action.
     * @param bool   $allowed Settings right.
     */
    public function test_email_template_route_right( string $action, bool $allowed ): void {
        $template            = MainWP_Notification_Template::instance();
        $this->template_root = sys_get_temp_dir() . '/mainwp-settings-rights-' . wp_generate_uuid4();
        mkdir( $this->template_root );
        foreach ( array( 'template_path', 'template_custom_path' ) as $name ) {
            $property = new \ReflectionProperty( MainWP_Notification_Template::class, $name );
            $property->setAccessible( true );
            $this->template_paths[ $name ] = $property->getValue( $template );
            $property->setValue( $template, $this->template_root . '/' );
        }
        $this->filter(
            'mainwp_get_notification_template_name_by_type',
            static function () {
                return 'fixture.php';
            }
        );
        $file = $this->template_root . '/fixture.php';
        if ( 'delete' === $action ) {
            file_put_contents( $file, 'custom-template-content' );
        } else {
            mkdir( $this->template_root . '/source' );
            file_put_contents( $this->template_root . '/source/fixture.php', 'default-template-content' );
            $this->filter(
                'mainwp_default_template_source_dir',
                function () {
                    return $this->template_root . '/source/';
                }
            );
        }
        $this->assertTrue( current_user_can( 'edit_themes' ) );
        $_GET = array(
            'edit-email' => 'daily_digest',
            '_wpnonce'   => wp_create_nonce( $action . '-email-template' ),
        );
        $this->settings_right( $allowed );
        $this->capture(
            static function () {
                MainWP_Settings::render_email_settings();
            }
        );
        if ( 'delete' === $action ) {
            $this->assertSame( ! $allowed, file_exists( $file ) );
            if ( ! $allowed ) {
                $this->assertSame( 'custom-template-content', file_get_contents( $file ) );
            }
        } else {
            $this->assertSame( $allowed, file_exists( $file ) );
            if ( $allowed ) {
                $this->assertSame( 'default-template-content', file_get_contents( $file ) );
            }
        }
    }

    /**
     * Valid uptime form.
     *
     * @return array Valid uptime form.
     */
    private function monitor_post(): array {
        return array(
            'wp_nonce_uptime_settings'    => wp_create_nonce( 'UpdateMonitorSettings' ),
            'mainwp_edit_monitor_keyword' => 'replacement',
            'mainwp_edit_monitor_active'  => 0,
        );
    }

    /**
     * Vary only the named rights while retaining all other grants.
     *
     * @param bool $settings Settings right.
     * @param bool $edit_sites Site-edit right.
     */
    private function settings_right( bool $settings, bool $edit_sites = true ): void {
        $this->filter(
            'mainwp_currentusercan',
            static function ( $can, $type, $cap ) use ( $settings, $edit_sites ) {
                if ( 'dashboard' === $type && 'manage_dashboard_settings' === $cap ) {
                    return $settings;
                }
                return 'dashboard' === $type && 'edit_sites' === $cap ? $edit_sites : $can;
            }
        );
        // REST tests define MAINWP_REST_API_DOING for the rest of the process, which makes every right pass, so this file must run before them.
        $this->assertFalse( defined( 'MAINWP_REST_API_DOING' ), 'MAINWP_REST_API_DOING is already defined; run this class before the REST tests.' );
        $this->assertSame( $settings, \mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' ) );
    }

    /**
     * Replace only callbacks owned by this test.
     *
     * @param string   $hook Filter.
     * @param callable $callback Callback.
     */
    private function filter( string $hook, callable $callback ): void {
        if ( isset( $this->filters[ $hook ] ) ) {
            remove_filter( $hook, $this->filters[ $hook ], 999 );
        }
        $this->filters[ $hook ] = $callback;
        add_filter( $hook, $callback, 999, 3 );
    }

    /**
     * Options and user-extension state before a refused write.
     *
     * @return array Options and user-extension state before a refused write.
     */
    private function snapshot(): array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Read complete option state to detect any refused write.
        return array( $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} ORDER BY option_name", ARRAY_A ), (array) MainWP_DB_Common::instance()->get_user_extension() );
    }

    /**
     * Exercise the source writer with a valid action nonce.
     *
     * @param string $action AJAX action.
     * @param array  $post Inputs.
     * @return string Response.
     */
    private function ajax( string $action, array $post = array() ): string {
        $_POST    = array_merge(
            $post,
            array(
                'security' => wp_create_nonce( $action ),
                'dts'      => wp_generate_uuid4(),
            )
        );
        $_REQUEST = $_POST;
        return $this->capture(
            static function () use ( $action ) {
                $method = 'mainwp_save_settings' === $action ? 'ajax_mainwp_save_settings' : $action;
                Settings_Rights_Post_Handler::instance()->$method();
            }
        );
    }

    /**
     * Capture termination so state assertions can run after the request.
     *
     * @param callable $callback Request.
     * @return string Captured output.
     */
    private function capture( callable $callback ): string {
        add_filter( 'wp_doing_ajax', '__return_true' );
        add_filter( 'wp_die_ajax_handler', array( $this, 'ajax_die_handler' ) );
        ob_start();
        $body = '';
        try {
            $callback();
        } catch ( Settings_Rights_Response_Stop $exception ) {
            $body = $exception->getMessage();
        } finally {
            $buffer = ob_get_clean();
            remove_filter( 'wp_die_ajax_handler', array( $this, 'ajax_die_handler' ) );
            remove_filter( 'wp_doing_ajax', '__return_true' );
        }
        return $buffer . $body;
    }

    /**
     * AJAX termination callback.
     *
     * @return callable AJAX termination callback.
     */
    public function ajax_die_handler(): callable {
        return static function ( $message ) {
            throw new Settings_Rights_Response_Stop( (string) $message );
        };
    }
}
