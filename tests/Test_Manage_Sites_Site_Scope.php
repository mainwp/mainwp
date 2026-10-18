<?php
/**
 * Manage Sites authorization and notification template writes.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

// phpcs:disable WordPress.Security.NonceVerification -- Request arrays are fixtures; production handlers verify the supplied nonces.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Fixture rows and snapshots must bypass production caches.

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Uptime_Monitoring;
use MainWP\Dashboard\MainWP_Manage_Sites;
use MainWP\Dashboard\MainWP_Notification_Template;
use MainWP\Dashboard\MainWP_System_Utility;

/** Exercise route and handler checks independently of filtered site lookups. */
class Test_Manage_Sites_Site_Scope extends \WP_UnitTestCase {

    use Site_Fixture;

    /**
     * Test-owned filters.
     *
     * @var array
     */
    private $filters = array();

    /**
     * Mutation hook counts.
     *
     * @var array
     */
    private $hooks = array();

    /**
     * Temporary upload directory within the worktree.
     *
     * @var string
     */
    private $upload_root = '';

    /**
     * Original template singleton.
     *
     * @var MainWP_Notification_Template|null
     */
    private $template_instance;

    /**
     * Original site context.
     *
     * @var int
     */
    private $current_wpid = 0;

    /** Administrators still obey the Team Control rights filter. */
    public function setUp(): void {
        parent::setUp();
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        $_GET     = array();
        $_POST    = array();
        $_REQUEST = array();
        $this->scope( 0 );
        foreach ( array( 'mainwp_update_site', 'mainwp_before_save_email_settings' ) as $hook ) {
            $this->hooks[ $hook ] = 0;
            add_action( $hook, array( $this, 'count_mutation' ) );
        }
        if ( ! function_exists( 'set_current_screen' ) ) {
            require_once ABSPATH . 'wp-admin/includes/screen.php';
        }
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        set_current_screen( 'dashboard' );
        update_user_option( get_current_user_id(), 'mainwp_sitesviewmode', 'table' );
        $this->current_wpid = MainWP_System_Utility::get_current_wpid();
        MainWP_System_Utility::set_current_wpid( 0 );

        $this->upload_root = get_temp_dir() . 'mainwp-site-scope-' . wp_generate_uuid4();
        wp_mkdir_p( $this->upload_root );
        $this->filter(
            'mainwp_get_wp_upload_dir',
            function ( $upload ) {
                $upload['basedir'] = $this->upload_root;
                return $upload;
            }
        );
        $property = new \ReflectionProperty( MainWP_Notification_Template::class, 'instance' );
        $property->setAccessible( true );
        $this->template_instance = $property->getValue();
        $property->setValue( null, new MainWP_Notification_Template() );
    }

    /** Restore shared callbacks, request state and template paths. */
    public function tearDown(): void {
        global $wp_filesystem;

        $property = new \ReflectionProperty( MainWP_Notification_Template::class, 'instance' );
        $property->setAccessible( true );
        $property->setValue( null, $this->template_instance );
        if ( $this->upload_root ) {
            $wp_filesystem->delete( $this->upload_root, true );
        }
        foreach ( $this->filters as $hook => $callback ) {
            remove_filter( $hook, $callback, 999 );
        }
        foreach ( array_keys( $this->hooks ) as $hook ) {
            remove_action( $hook, array( $this, 'count_mutation' ) );
        }
        MainWP_System_Utility::set_current_wpid( $this->current_wpid );
        $_GET     = array();
        $_POST    = array();
        $_REQUEST = array();
        wp_set_current_user( 0 );
        parent::tearDown();
    }

    /** Count mutations after fixture creation. */
    public function count_mutation(): void {
        ++$this->hooks[ current_filter() ];
    }

    /**
     * Out-of-scope requests must retain the listing fallback without writes.
     *
     * @dataProvider routes
     * @param string $route  Site query key.
     * @param array  $extra  Additional renderer inputs.
     * @param string $marker Target content marker.
     */
    public function test_out_of_scope_route_falls_back_to_listing( $route, $extra, $marker ): void {
        $site = $this->route_request( $route, $extra );
        $this->scope( $site );
        $this->assertNotEmpty( MainWP_DB::instance()->get_website_by_id( $site ) );
        $before = $this->snapshot( $site );
        $body   = $this->render();
        $this->assertStringNotContainsString( sprintf( $marker, $site ), $body );
        $this->assertStringContainsString( 'id="mainwp-manage-sites-content"', $body );
        $this->assertSame( $before, $this->snapshot( $site ) );
        $this->assertSame(
            array(
                'mainwp_update_site'                => 0,
                'mainwp_before_save_email_settings' => 0,
            ),
            $this->hooks
        );
    }

    /**
     * Site restrictions must preserve the granted site renderer.
     *
     * @dataProvider routes
     * @param string $route  Site query key.
     * @param array  $extra  Additional renderer inputs.
     * @param string $marker Target content marker.
     */
    public function test_in_scope_route_renders_target( $route, $extra, $marker ): void {
        $site = $this->route_request( $route, $extra );
        $this->scope( $this->create_test_site() );
        $this->assertStringContainsString( sprintf( $marker, $site ), $this->render() );
    }

    /**
     * Unrestricted administrators must retain every site renderer.
     *
     * @dataProvider routes
     * @param string $route  Site query key.
     * @param array  $extra  Additional renderer inputs.
     * @param string $marker Target content marker.
     */
    public function test_full_administrator_route_renders_target( $route, $extra, $marker ): void {
        $site = $this->route_request( $route, $extra );
        $this->assertStringContainsString( sprintf( $marker, $site ), $this->render() );
    }

    /**
     * Provide each renderer with its complete query and a distinct marker.
     *
     * @return array Complete query inputs and distinct target markers.
     */
    public static function routes(): array {
        return array(
            'backupid'                   => array( 'backupid', array(), 'id="backup_site_id"' ),
            'scanid'                     => array( 'scanid', array(), 'id="securityIssues_refresh"' ),
            'dashboard'                  => array( 'dashboard', array(), 'id="mainwp-grid-wrapper"' ),
            'knowledgeid'                => array( 'knowledgeid', array(), 'data-scope-type="site"' ),
            'updateid'                   => array( 'updateid', array(), 'id="mainwp-manage-%d-updates"' ),
            'monitor_wpid add'           => array(
                'monitor_wpid',
                array(
                    'id'     => 'site',
                    'action' => 'add_submonitor',
                ),
                'id="mainwp-edit-monitor-site-form"',
            ),
            'monitor_wpid edit'          => array(
                'monitor_wpid',
                array(
                    'id'             => 'site',
                    'sub_monitor_id' => 'monitor',
                ),
                'id="mainwp-edit-monitor-site-form"',
            ),
            'emailsettingsid'            => array( 'emailsettingsid', array( 'id' => 'site' ), 'id="mainwp-edit-single-site-form"' ),
            'emailsettingsid edit-email' => array(
                'emailsettingsid',
                array(
                    'id'         => 'site',
                    'edit-email' => 'site_health',
                ),
                'id="mainwp-edit-email-template-modal"',
            ),
            'id'                         => array( 'id', array(), 'id="mainwp-edit-single-site-form"' ),
        );
    }

    /**
     * A valid nonce must not authorize a write to an ungranted site.
     *
     * @dataProvider write_paths
     * @param string $path Route or direct handler invocation.
     */
    public function test_site_write_refuses_out_of_scope_site( $path ): void {
        $site = $this->create_test_site();
        $this->site_post( $site );
        $this->scope( $site );
        $before = $this->snapshot( $site );
        $this->write( 'update_site_handle', $site, $path );
        $this->assertSame( $before, $this->snapshot( $site ) );
        $this->assertSame( 0, $this->hooks['mainwp_update_site'] );
    }

    /**
     * Both entry points must preserve authorized site updates.
     *
     * @dataProvider write_paths
     * @param string $path Route or direct handler invocation.
     */
    public function test_site_write_saves_in_scope_site( $path ): void {
        $site = $this->create_test_site();
        $this->site_post( $site );
        $this->scope( $this->create_test_site() );
        $this->write( 'update_site_handle', $site, $path );
        $this->assertSame( 'Edited scope site', MainWP_DB::instance()->get_website_by_id( $site )->name );
        $this->assertSame( 1, $this->hooks['mainwp_update_site'] );
    }

    /**
     * Email writes must respect the site grant at both entry points.
     *
     * @dataProvider write_paths
     * @param string $path Route or direct handler invocation.
     */
    public function test_email_write_refuses_out_of_scope_site( $path ): void {
        $site = $this->email_post();
        $this->scope( $site );
        $this->assert_email_write_refused( $site, $path );
    }

    /**
     * A site grant alone must not authorize email settings writes.
     *
     * @dataProvider write_paths
     * @param string $path Route or direct handler invocation.
     */
    public function test_email_write_requires_edit_sites( $path ): void {
        $site = $this->email_post();
        $this->scope( 0, false );
        $this->assert_email_write_refused( $site, $path );
    }

    /**
     * Authorized email writes must persist recipients and fire the save hook.
     *
     * @dataProvider write_paths
     * @param string $path Route or direct handler invocation.
     */
    public function test_email_write_saves_in_scope_site( $path ): void {
        $site = $this->email_post();
        $this->scope( $this->create_test_site() );
        $this->write( 'update_site_emails_settings_handle', $site, $path );
        $settings = json_decode( $this->email_settings( $site ), true );
        $this->assertSame( 'updated@example.com', $settings['site_health']['recipients'] );
        $this->assertSame( 0, $settings['site_health']['disable'] );
        $this->assertSame( 1, $this->hooks['mainwp_before_save_email_settings'] );
    }

    /**
     * Exercise route dispatch and direct handler calls independently.
     *
     * @return array Both entry points must enforce the same rights.
     */
    public static function write_paths(): array {
        return array(
            'route'   => array( 'route' ),
            'handler' => array( 'handler' ),
        );
    }

    /**
     * Both email query variants must refuse writes without edit_sites.
     *
     * @dataProvider email_queries
     * @param bool $edit Whether to request the template editor.
     */
    public function test_email_route_requires_edit_sites( $edit ): void {
        $site = $this->email_post();
        unset( $_GET['id'] );
        if ( $edit ) {
            $_GET['edit-email'] = 'site_health';
        }
        $_REQUEST = array_merge( $_GET, $_POST );
        $this->scope( 0, false );
        $before = $this->email_settings( $site );
        $body   = $this->render();
        $this->assertSame( $before, $this->email_settings( $site ) );
        $this->assertSame( 0, $this->hooks['mainwp_before_save_email_settings'] );
        $this->assertStringNotContainsString( 'id="mainwp-edit-email-template-modal"', $body );
        $this->assertStringContainsString( 'id="mainwp-manage-sites-content"', $body );
    }

    /**
     * Cover the email route with and without the template editor.
     *
     * @return array Email route query variants.
     */
    public static function email_queries(): array {
        return array(
            'without edit-email' => array( false ),
            'with edit-email'    => array( true ),
        );
    }

    /**
     * The written site must be authorized even when the nonce names a granted site.
     *
     * @dataProvider handlers
     * @param string $handler Private write handler.
     */
    public function test_handler_checks_site_being_written( $handler ): void {
        $request_site = $this->create_test_site();
        $target_site  = $this->create_test_site();
        if ( 'update_site_handle' === $handler ) {
            $this->site_post( $request_site );
        } else {
            $this->email_post( $request_site );
        }
        $this->scope( $target_site );
        $before = $this->snapshot( $target_site );
        $this->write( $handler, $target_site, 'handler' );
        $this->assertSame( $before, $this->snapshot( $target_site ) );
        $this->assertSame(
            array(
                'mainwp_update_site'                => 0,
                'mainwp_before_save_email_settings' => 0,
            ),
            $this->hooks
        );
    }

    /**
     * Cover both private write handlers.
     *
     * @return array Independently callable write handlers.
     */
    public static function handlers(): array {
        return array( array( 'update_site_handle' ), array( 'update_site_emails_settings_handle' ) );
    }

    /**
     * Valid nonces must not authorize template filesystem changes.
     *
     * @dataProvider template_actions
     * @param string $action Delete or copy.
     */
    public function test_template_action_requires_edit_themes( $action ): void {
        global $wp_filesystem;

        $file = $this->template_request( $action );
        wp_get_current_user()->add_cap( 'edit_themes', false );
        $this->assertFalse( current_user_can( 'edit_themes' ) );
        $before = 'delete' === $action ? $wp_filesystem->get_contents( $file ) : false;
        $result = MainWP_Notification_Template::instance()->handle_template_file_action();
        $this->assertSame( 35, $result );
        if ( 'delete' === $action ) {
            $this->assertSame( $before, $wp_filesystem->get_contents( $file ) );
        } else {
            $this->assertFileDoesNotExist( $file );
        }
    }

    /**
     * The capability grant must preserve template deletion and copying.
     *
     * @dataProvider template_actions
     * @param string $action Delete or copy.
     */
    public function test_template_action_works_with_edit_themes( $action ): void {
        global $wp_filesystem;

        $file = $this->template_request( $action );
        $this->assertTrue( current_user_can( 'edit_themes' ) );
        $result = MainWP_Notification_Template::instance()->handle_template_file_action();
        if ( 'delete' === $action ) {
            $this->assertSame( 1, $result );
            $this->assertFileDoesNotExist( $file );
        } else {
            $this->assertSame( 2, $result );
            $source = MainWP_Notification_Template::instance()->get_default_templates_dir() . MainWP_Notification_Template::get_template_name_by_notification_type( 'site_health' );
            $this->assertSame( $wp_filesystem->get_contents( $source ), $wp_filesystem->get_contents( $file ) );
        }
    }

    /**
     * Cover deletion and copying independently.
     *
     * @return array Template filesystem actions.
     */
    public static function template_actions(): array {
        return array(
            'delete' => array( 'delete' ),
            'copy'   => array( 'copy' ),
        );
    }

    /**
     * Supply the site and records required by the real renderer.
     *
     * @param string $route Site query key.
     * @param array  $extra Additional query inputs.
     * @return int Target site ID.
     */
    private function route_request( $route, $extra ): int {
        global $wpdb;

        $site = $this->create_test_site();
        update_option( 'mainwp_enableLegacyBackupFeature', 1 );
        // The backupid route follows the native backups toggle.
        update_option( 'mainwp_enableNativeBackups', 1 );
        if ( 'backupid' === $route ) {
            $wpdb->insert(
                $wpdb->prefix . 'mainwp_wp_settings_backup',
                array(
                    'wpid'          => $site,
                    'archiveFormat' => 'global',
                ),
                array( '%d', '%s' )
            );
        }
        foreach ( $extra as $key => $value ) {
            if ( 'site' === $value ) {
                $extra[ $key ] = $site;
            } elseif ( 'monitor' === $value ) {
                $monitor = MainWP_DB_Uptime_Monitoring::instance()->update_wp_monitor(
                    array(
                        'wpid'   => $site,
                        'issub'  => 1,
                        'suburl' => '/scope-test/',
                        'type'   => 'http',
                        'method' => 'get',
                    )
                );
                $this->assertNotEmpty( $monitor );
                $extra[ $key ] = $monitor;
            }
        }
        $_GET     = array_merge(
            array(
                'page' => 'managesites',
                $route => $site,
            ),
            $extra
        );
        $_REQUEST = $_GET;
        return $site;
    }

    /**
     * Deny only rights so site lookups cannot mask missing route checks.
     *
     * @param int  $denied_site Site denied only by its per-site right.
     * @param bool $edit_sites Dashboard site editing right.
     */
    private function scope( $denied_site, $edit_sites = true ): void {
        $this->filter(
            'mainwp_currentusercan',
            static function ( $allowed, $type, $cap ) use ( $denied_site, $edit_sites ) {
                if ( 'site' === $type && (int) $cap === $denied_site ) {
                    return false;
                }
                return 'dashboard' !== $type || 'edit_sites' !== $cap || $edit_sites;
            }
        );
    }

    /**
     * Replace only this test callback when the grant changes.
     *
     * @param string   $hook Filter name.
     * @param callable $callback Test-owned callback.
     */
    private function filter( $hook, $callback ): void {
        if ( isset( $this->filters[ $hook ] ) ) {
            remove_filter( $hook, $this->filters[ $hook ], 999 );
        }
        $this->filters[ $hook ] = $callback;
        add_filter( $hook, $callback, 999, 3 );
    }

    /**
     * Capture renderer output while preserving exceptions.
     *
     * @return string Rendered Manage Sites response.
     */
    private function render(): string {
        ob_start();
        try {
            MainWP_Manage_Sites::render_manage_sites();
            return ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Supply a valid site update that changes persisted state.
     *
     * @param int $site Target site ID.
     */
    private function site_post( $site ): void {
        $_GET     = array(
            'page' => 'managesites',
            'id'   => $site,
        );
        $_POST    = array(
            'submit'                                   => 'Save',
            'wp_nonce'                                 => wp_create_nonce( 'UpdateWebsite' . $site ),
            'mainwp_managesites_edit_siteadmin'        => 'admin',
            'mainwp_managesites_edit_sitename'         => 'Edited scope site',
            'mainwp_managesites_edit_siteurl_protocol' => 'https',
        );
        $_REQUEST = array_merge( $_GET, $_POST );
    }

    /**
     * Seed recipients so refusal can be checked against stored state.
     *
     * @param int $site Optional target site ID.
     * @return int Target site ID.
     */
    private function email_post( $site = 0 ): int {
        $site = $site ? $site : $this->create_test_site();
        MainWP_DB::instance()->update_website_option(
            $site,
            'settings_notification_emails',
            wp_json_encode(
                array(
                    'site_health' => array(
                        'recipients' => 'original@example.com',
                        'disable'    => 1,
                    ),
                )
            )
        );
        $_GET     = array(
            'page'            => 'managesites',
            'id'              => $site,
            'emailsettingsid' => $site,
        );
        $_POST    = array(
            'submit'                                 => 'Save',
            'wp_nonce'                               => wp_create_nonce( 'UpdateWebsiteEmailSettings' . $site ),
            'mainwp_managesites_setting_emails_type' => 'site_health',
            'mainwp_managesites_edit_settingEmails'  => array(
                'site_health' => array(
                    'recipients' => 'updated@example.com',
                    'disable'    => 'on',
                ),
            ),
        );
        $_REQUEST = array_merge( $_GET, $_POST );
        return $site;
    }

    /**
     * Read fresh notification settings from storage.
     *
     * @param int $site Site ID.
     * @return string Stored notification settings.
     */
    private function email_settings( $site ): string {
        return MainWP_DB::instance()->get_website_option( $site, 'settings_notification_emails', '' );
    }

    /**
     * Compare persisted state and hook counts after a refused email write.
     *
     * @param int    $site Site ID.
     * @param string $path Invocation path.
     */
    private function assert_email_write_refused( $site, $path ): void {
        $before = $this->snapshot( $site );
        $this->write( 'update_site_emails_settings_handle', $site, $path );
        $this->assertSame( $before, $this->snapshot( $site ) );
        $this->assertSame( 0, $this->hooks['mainwp_before_save_email_settings'] );
    }

    /**
     * Run the same request through either authorized entry point.
     *
     * @param string $handler Handler name.
     * @param int    $site Site ID.
     * @param string $path Invocation path.
     */
    private function write( $handler, $site, $path ): void {
        if ( 'route' === $path ) {
            $this->render();
            return;
        }
        $method = new \ReflectionMethod( MainWP_Manage_Sites::class, $handler );
        $method->setAccessible( true );
        $method->invoke( null, MainWP_DB::instance()->get_website_by_id( $site ) );
    }

    /**
     * Read stored rows directly so cached objects cannot hide a mutation.
     *
     * @param int $site Site ID.
     * @return array Site row and persisted options.
     */
    private function snapshot( $site ): array {
        global $wpdb;

        return array(
            'site'    => $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mainwp_wp WHERE id = %d", $site ), ARRAY_A ),
            'options' => $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mainwp_wp_options WHERE wpid = %d ORDER BY name", $site ), ARRAY_A ),
        );
    }

    /**
     * Keep template fixtures in the temporary custom templates directory.
     *
     * @param string $action Template action.
     * @return string Custom template filename.
     */
    private function template_request( $action ): string {
        global $wp_filesystem;

        $file = MainWP_Notification_Template::instance()->get_custom_templates_dir() . MainWP_Notification_Template::get_template_name_by_notification_type( 'site_health' );
        $this->assertFileDoesNotExist( $file );
        if ( 'delete' === $action ) {
            $this->assertTrue( $wp_filesystem->put_contents( $file, 'Custom scope test template' ) );
        }
        $_GET     = array(
            'edit-email' => 'site_health',
            '_wpnonce'   => wp_create_nonce( $action . '-email-template' ),
        );
        $_REQUEST = $_GET;
        return $file;
    }
}
