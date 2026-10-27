<?php
/**
 * Browser action writers respect the rights used by their controls.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Widget_Themes;
use MainWP\Dashboard\Rights_Post_Plugin_Theme_Handler;
use MainWP\Dashboard\Rights_REST_API_Page;
use MainWP\Dashboard\Module\CostTracker\Cost_Tracker_DB;
use MainWP\Dashboard\Module\CostTracker\Rights_Cost_Tracker_Dashboard;
use MainWP\Dashboard\Module\Log\Rights_Log_Admin;

/** Stop legacy exits without bypassing request guards. */
class Action_Rights_Response_Stop extends \Error {}

/** Exercise production handlers, SQL and renderers in WordPress. */
class Test_Dashboard_Team_Control_Action_Rights extends \WP_UnitTestCase {

    use Site_Fixture;

    /** @var array Test-owned filters. */
    private $filters = array();

    /** @var array Original action registrations. */
    private $hooks = array();

    /** @var bool Whether this test added the staff role. */
    private $added_role = false;

    /** @var array Observed Child requests. */
    private $requests = array();

    /** Capture only exit tokens; all guards and mutations remain production code. */
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        $names = array(
            'MainWP_Post_Base_Handler'         => 'Rights_Post_Base_Handler',
            'MainWP_Post_Handler'              => 'Rights_Post_Handler',
            'MainWP_Post_Plugin_Theme_Handler' => 'Rights_Post_Plugin_Theme_Handler',
            'MainWP_Widget_Plugins'            => 'Rights_Widget_Plugins',
            'MainWP_Widget_Themes'             => 'Rights_Widget_Themes',
            'MainWP_Rest_Api_Page'             => 'Rights_REST_API_Page',
            'Cost_Tracker_Dashboard'           => 'Rights_Cost_Tracker_Dashboard',
            'Log_Admin'                        => 'Rights_Log_Admin',
        );
        foreach ( array(
            'class/class-mainwp-post-base-handler.php',
            'class/class-mainwp-post-handler.php',
            'widgets/widget-mainwp-widget-plugins.php',
            'widgets/widget-mainwp-widget-themes.php',
            'class/class-mainwp-post-plugin-theme-handler.php',
            'modules/cost-tracker/pages/page-cost-tracker-dashboard.php',
            'modules/logs/classes/class-log-admin.php',
            'pages/page-mainwp-rest-api-page.php',
        ) as $file ) {
            $code = '';
            foreach ( token_get_all( file_get_contents( dirname( __DIR__ ) . '/' . $file ) ) as $token ) {
                if ( is_array( $token ) ) {
                    $code .= T_EXIT === $token[0] ? '\\wp_die' : ( ( T_STRING === $token[0] || ( defined( 'T_NAME_QUALIFIED' ) && T_NAME_QUALIFIED === $token[0] ) ) ? strtr( $token[1], $names ) : $token[1] );
                } else {
                    $code .= $token;
                }
            }
            // nosemgrep: AIK_eval-use -- Only checked-in source is evaluated to capture legacy exits.
            eval( '?>' . str_replace( '\\wp_die;', '\\wp_die();', $code ) ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Capture only legacy exit tokens in renamed production classes.
        }
    }

    /** Set a real administrator and intercept every Child request before transport. */
    public function setUp(): void {
        parent::setUp();
        global $wp_filter;
        $this->hooks = array_map( static function ( $hook ) { return clone $hook; }, $wp_filter );
        wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/screen.php';
        set_current_screen( 'dashboard' );
        $this->filter( 'mainwp_fetch_url_authed_pre', function ( $pre, $website, $what, $params ) {
            $this->requests[] = array( $website->id, $what, $params );
            return array( 'status' => 'SUCCESS', 'success' => 1, 'dbsize_activitylogs' => 17 );
        }, 4 );
        $this->filter( 'wp_redirect', '__return_false' );
        $this->filter( 'mainwp_currentuserallowedaccesssites', static function () { return 'all'; } );
        $_GET = $_POST = $_REQUEST = array();
    }

    /** Restore registrations, filters and request state. */
    public function tearDown(): void {
        global $wp_filter;
        $wp_filter = $this->hooks;
        $_GET = $_POST = $_REQUEST = array();
        wp_set_current_user( 0 );
        if ( $this->added_role ) {
            remove_role( 'mainwp_action_rights_fixture' );
        }
        parent::tearDown();
    }

    /** @return array Real actor and rights combinations. */
    public static function actors(): array {
        return array( 'restricted staff' => array( 'denied' ), 'granted staff' => array( 'staff' ), 'unrestricted admin' => array( 'admin' ), 'single user defaults' => array( 'default' ) );
    }

    /** @return array Writer and actor combinations. */
    public static function cost_writers(): array {
        $cases = array();
        foreach ( array( 'notes', 'delete' ) as $writer ) {
            foreach ( self::actors() as $label => $actor ) {
                $cases[ $writer . ' ' . $label ] = array( $actor[0], $writer );
            }
        }
        return $cases;
    }

    /**
     * @dataProvider cost_writers
     * @param string $actor Current actor.
     * @param string $writer Mutation name.
     */
    public function test_cost_writers( string $actor, string $writer ): void {
        global $wpdb;
        $this->actor( $actor, 'manage_cost_tracker' );
        $db = Cost_Tracker_DB::get_instance();
        $table = $db->get_table_name( 'cost_tracker' );
        $lookup = $db->get_table_name( 'lookup_item_objects' );
        $this->assertNotFalse( $wpdb->insert( $table, array( 'name' => 'Rights fixture', 'note' => 'keep' ) ), $wpdb->last_error );
        $id = (int) $wpdb->insert_id;
        $site = $this->create_test_site();
        $db->update_selected_lookup_cost( $id, array( $site ) );
        $relations = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $lookup WHERE item_name='cost' AND item_id=%d", $id ), ARRAY_A );
        $this->assertNotEmpty( $relations );
        $page = ( new \ReflectionClass( Rights_Cost_Tracker_Dashboard::class ) )->newInstanceWithoutConstructor();
        if ( 'notes' === $writer ) {
            $this->post( 'mainwp_module_cost_tracker_notes_save', array( 'subid' => $id, 'note' => 'changed' ) );
            $response = json_decode( $this->capture( array( $page, 'ajax_notes_save' ) ), true );
            $note = $wpdb->get_var( $wpdb->prepare( "SELECT note FROM $table WHERE id=%d", $id ) );
            $this->assertSame( 'denied' === $actor ? 'keep' : 'changed', $note, 'Note writer ignored its Cost Tracker right.' );
            $this->assertArrayHasKey( 'denied' === $actor ? 'error' : 'result', $response );
            return;
        }
        $this->post( 'mainwp_module_cost_tracker_delete', array( 'sub_id' => $id ) );
        $response = json_decode( $this->capture( array( $page, 'ajax_cost_tracker_delete' ) ), true );
        $this->assertSame( 'denied' === $actor ? 1 : 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE id=%d", $id ) ) );
        $after = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $lookup WHERE item_name='cost' AND item_id=%d", $id ), ARRAY_A );
        $this->assertSame( 'denied' === $actor ? $relations : array(), $after );
        $this->assertArrayHasKey( 'denied' === $actor ? 'error' : 'status', $response );
    }

    /**
     * @dataProvider actors
     * @param string $actor Current actor.
     */
    public function test_child_log_cleanup( string $actor ): void {
        $this->actor( $actor, 'manage_insights_actions' );
        $site = $this->create_test_site();
        $page = ( new \ReflectionClass( Rights_Log_Admin::class ) )->newInstanceWithoutConstructor();
        $this->post( 'mainwp_module_log_clean_up_child_logs', array( 'siteid' => $site ) );
        $response = json_decode( $this->capture( array( $page, 'ajax_clean_up_child_logs' ) ), true );
        if ( 'denied' === $actor ) {
            $this->assertSame( array(), $this->requests, 'Denied cleanup reached Child transport.' );
            $this->assertArrayHasKey( 'error', $response );
        } else {
            $this->assertCount( 1, $this->requests );
            $this->assertSame( array( (string) $site, 'clean_up_child_logs', null ), $this->requests[0] );
            $this->assertSame( array( 'success' => 1 ), $response );
            $this->assertSame( '17', MainWP_DB::instance()->get_website_option( $site, 'dbsize_activitylogs' ) );
        }
    }

    /** @return array Each registered action with each actor. */
    public static function widget_actions(): array {
        $cases = array();
        foreach ( array( 'plugin_activate' => 'activate_deactivate_plugins', 'plugin_deactivate' => 'activate_deactivate_plugins', 'plugin_delete' => 'delete_plugins', 'theme_activate' => 'activate_themes', 'theme_delete' => 'delete_themes' ) as $action => $right ) {
            foreach ( self::actors() as $label => $actor ) {
                $cases[ $action . ' ' . $label ] = array( $action, $right, $actor[0] );
            }
        }
        return $cases;
    }

    /**
     * @dataProvider widget_actions
     * @param string $action Widget action.
     * @param string $right Main page right.
     * @param string $actor Current actor.
     */
    public function test_widget_dispatch( string $action, string $right, string $actor ): void {
        $this->actor( $actor, $right );
        foreach ( array( 'plugin_activate', 'plugin_deactivate', 'plugin_delete', 'theme_activate', 'theme_delete' ) as $suffix ) {
            remove_all_actions( 'wp_ajax_mainwp_widget_' . $suffix );
        }
        Rights_Post_Plugin_Theme_Handler::instance()->init();
        $site = $this->create_test_site();
        $hook = 'wp_ajax_mainwp_widget_' . $action;
        $type = explode( '_', $action )[0];
        $this->post( 'mainwp_widget_' . $action, array( 'websiteId' => $site, $type => 'fixture/item' ) );
        $this->capture( static function () use ( $hook ) { do_action( $hook ); } );
        if ( 'denied' === $actor ) {
            $this->assertSame( array(), $this->requests, 'Denied widget request reached Child transport.' );
            $this->assertFalse( has_action( $hook ), 'Denied widget action remains registered.' );
        } else {
            $this->assertCount( 1, $this->requests );
            $this->assertSame( array( (string) $site, $type . '_action', array( 'action' => explode( '_', $action )[1], $type => 'fixture/item' ) ), $this->requests[0] );
        }
    }

    /** @return array REST operation and actor combinations. */
    public static function rest_requests(): array {
        $cases = array();
        foreach ( array( 'create', 'legacy_edit', 'v2_edit', 'ui' ) as $operation ) {
            foreach ( self::actors() as $label => $actor ) {
                $cases[ $operation . ' ' . $label ] = array( $actor[0], $operation );
            }
        }
        return $cases;
    }

    /**
     * Staff can create normal v2 keys but cannot create global legacy keys.
     *
     * @dataProvider rest_requests
     * @param string $actor Current actor.
     * @param string $operation REST operation.
     */
    public function test_rest_key_creation_and_legacy_edit( string $actor, string $operation ): void {
        global $wpdb;
        $this->actor( $actor, 'unused_dashboard_right' );
        $legacy = array( 'fixture-existing' => array( 'cs' => wp_hash_password( 'fixture-secret' ), 'desc' => 'Keep', 'enabled' => 0, 'perms' => 'r' ) );
        update_option( 'mainwp_rest_api_keys', $legacy );
        delete_option( 'mainwp_rest_api_consumer_key' );
        $table = MainWP_DB::instance()->get_table_name( 'api_keys' );
        $before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
        $page = new Rights_REST_API_Page();
        $_GET = array( 'page' => 'AddApiKeys' );
        $_POST = array( 'submit' => '1', 'wp_nonce' => wp_create_nonce( 'RESTAPI' ), 'mainwp_consumer_key' => 'fixture-new', 'mainwp_consumer_secret' => 'fixture-secret', 'mainwp_rest_add_api_key_desc' => 'New key', 'mainwp_enable_rest_api' => '1', 'mainwp_rest_api_key_edit_pers' => 'r,w,d', 'mainwp_rest_api_keys_compatible_v1' => '1' ); // gitleaks:allow -- Static fixture credentials.
        $_REQUEST = array_merge( $_GET, $_POST );
        $this->capture( array( $page, 'handle_rest_api_add_new' ) );
        $this->assertSame( $before + 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ), 'Normal v2 key creation was blocked.' );
        $v2 = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE consumer_key=%s", mainwp_api_hash( 'fixture-new' ) ) );
        $this->assertNotNull( $v2 );
        $this->assertSame( get_current_user_id(), (int) $v2->user_id );
        $this->assertSame( 'read_write', $v2->permissions );
        $this->assertSame( '1', $v2->enabled );
        $stored = get_option( 'mainwp_rest_api_keys' );
        $staff = in_array( $actor, array( 'denied', 'staff' ), true );
        if ( 'create' === $operation ) {
            $this->assertSame( ! $staff, isset( $stored['fixture-new'] ), 'Staff must not create ownerless legacy keys.' );
            $this->assertSame( $legacy['fixture-existing'], $stored['fixture-existing'] );
            return;
        }
        $this->assertSame( $legacy['fixture-existing'], $stored['fixture-existing'] );
        $_POST = array( 'submit' => '1', 'editkey_id' => 'fixture-existing', 'edit_key_nonce' => wp_create_nonce( 'edit-key-nonce-fixture-existing' ), 'mainwp_rest_api_key_desc' => 'Edited', 'mainwp_enable_rest_api' => '1', 'mainwp_rest_api_key_edit_pers' => 'r,w,d' );
        $_REQUEST = $_POST;
        if ( 'legacy_edit' === $operation ) {
            $this->capture( array( $page, 'handle_rest_api_edit' ) );
            $edited = get_option( 'mainwp_rest_api_keys' )['fixture-existing'];
            $this->assertSame( $staff ? $legacy['fixture-existing'] : array_merge( $legacy['fixture-existing'], array( 'desc' => 'Edited', 'enabled' => 1, 'perms' => 'r,w,d' ) ), $edited );
            return;
        }
        $_POST['editkey_id'] = $v2->key_id;
        $_POST['edit_key_nonce'] = wp_create_nonce( 'edit-key-nonce-' . $v2->key_id );
        $_POST['rest_v2_edit'] = '1';
        $_REQUEST = $_POST;
        if ( 'v2_edit' === $operation ) {
            $this->capture( array( $page, 'handle_rest_api_edit' ) );
            $this->assertSame( 'Edited', $wpdb->get_var( $wpdb->prepare( "SELECT description FROM $table WHERE key_id=%d", $v2->key_id ) ) );
            return;
        }
        $_GET = $_POST = $_REQUEST = array();
        $html = $this->capture( array( Rights_REST_API_Page::class, 'render_rest_api_setings' ) );
        $this->assertSame( ! $staff, false !== strpos( $html, 'name="mainwp_rest_api_keys_compatible_v1"' ) );
        $html = $this->capture( array( Rights_REST_API_Page::class, 'render_api_keys_v1_table' ) );
        $this->assertSame( ! $staff, false !== strpos( $html, 'editkey=' ) );
        $html = $this->capture( static function () use ( $legacy ) { Rights_REST_API_Page::render_rest_api_edit( 'fixture-existing', $legacy['fixture-existing'] ); } );
        $this->assertSame( ! $staff, false !== strpos( $html, 'name="editkey_id"' ) );
    }

    /**
     * The widget uses the existing activate_themes right in both directions.
     *
     * @dataProvider actors
     * @param string $actor Current actor.
     */
    public function test_theme_control_visibility( string $actor ): void {
        $this->actor( $actor, 'activate_themes' );
        $site = $this->create_test_site();
        $website = MainWP_DB::instance()->get_website_by_id( $site );
        $html = $this->capture( static function () use ( $website ) { MainWP_Widget_Themes::render_html_widget( $website, array( array( 'slug' => 'fixture', 'name' => 'Fixture', 'version' => '1', 'active' => 0 ) ) ); } );
        $this->assertSame( 'denied' !== $actor, false !== strpos( $html, 'mainwp-theme-activate item' ) );
    }

    /**
     * @param string $actor Actor mode.
     * @param string $right Right whose denial is under test.
     */
    private function actor( string $actor, string $right ): void {
        $this->assertFalse( defined( 'MAINWP_REST_API_DOING' ), 'Run before REST tests define the request bypass.' );
        if ( in_array( $actor, array( 'denied', 'staff' ), true ) ) {
            $this->added_role = null !== add_role( 'mainwp_action_rights_fixture', 'Staff fixture', array( 'read' => true, 'level_10' => true ) );
            wp_set_current_user( self::factory()->user->create( array( 'role' => 'mainwp_action_rights_fixture' ) ) );
        }
        if ( 'default' !== $actor ) {
            $this->filter( 'mainwp_currentusercan', static function ( $can, $type, $requested ) use ( $actor, $right ) { return ! ( 'denied' === $actor && 'dashboard' === $type && $requested === $right ); }, 3 );
        }
    }

    /**
     * @param string $action Nonce action.
     * @param array $fields Request fields.
     */
    private function post( string $action, array $fields ): void {
        $_POST = array_merge( $fields, array( 'security' => wp_create_nonce( $action ), 'dts' => wp_generate_uuid4() ) );
        $_REQUEST = $_POST;
    }

    /**
     * @param string $hook Filter name.
     * @param callable $callback Callback.
     * @param int $arguments Argument count.
     */
    private function filter( string $hook, callable $callback, int $arguments = 1 ): void {
        $this->filters[ $hook ] = $callback;
        add_filter( $hook, $callback, 999, $arguments );
    }

    /**
     * @param callable $callback Production writer or renderer.
     * @return string Captured response.
     */
    private function capture( callable $callback ): string {
        add_filter( 'wp_doing_ajax', '__return_true' );
        add_filter( 'wp_die_ajax_handler', array( $this, 'die_handler' ) );
        ob_start();
        $body = '';
        try {
            $callback();
        } catch ( Action_Rights_Response_Stop $exception ) {
            $body = $exception->getMessage();
        } finally {
            $buffer = ob_get_clean();
            remove_filter( 'wp_die_ajax_handler', array( $this, 'die_handler' ) );
            remove_filter( 'wp_doing_ajax', '__return_true' );
        }
        return $buffer . $body;
    }

    /** @return callable WordPress exit capture. */
    public function die_handler(): callable {
        return static function ( $message ) { throw new Action_Rights_Response_Stop( (string) $message ); };
    }
}
