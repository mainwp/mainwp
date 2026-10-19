<?php
/**
 * Settings writes require the API key owner's Team Control operation right.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard\Tests;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Utility;

/**
 * Tests authenticated REST writes after the process-wide REST constant is set.
 *
 * Runs in its own "REST Key Authentication" suite after the others (phpunit.xml.dist):
 * the constant cannot be undefined and turns off site scoping for every later test.
 */
class Test_REST_Settings_Write_Rights extends MainWP_Abilities_Test_Case {

    /**
     * Authentication instance hooked by the plugin.
     *
     * @var \MainWP_REST_Authentication
     */
    private $auth;
    /**
     * State restored after each test.
     *
     * @var array
     */
    private $original = array();
    /**
     * API key owner.
     *
     * @var int
     */
    private $owner_id;
    /**
     * Another user's notices and sessions must survive.
     *
     * @var int
     */
    private $other_id;
    /**
     * Read-write and read-only credentials.
     *
     * @var array
     */
    private $keys;
    /**
     * Connected sites.
     *
     * @var array
     */
    private $site_ids;
    /**
     * Filters removed by their exact callbacks.
     *
     * @var array
     */
    private $filters = array();
    /**
     * Withheld operation right.
     *
     * @var string
     */
    private $denied_right = '';
    /**
     * Restrict the owner to one site.
     *
     * @var bool
     */
    private $restricted = false;
    /**
     * Restrict the owner's tag scope.
     *
     * @var bool
     */
    private $restricted_groups = false;
    /**
     * Rights checked and their acting users.
     *
     * @var array
     */
    private $right_calls = array();
    /**
     * Child requests made before any queued worker runs.
     *
     * @var int
     */
    private $child_calls = 0;
    /**
     * HTTP requests intercepted to prevent network access.
     *
     * @var int
     */
    private $http_calls = 0;

    /** Prepare real keys and observable settings, sessions and queue state. */
    public function setUp(): void {
        parent::setUp();
        $this->original = array(
            'server' => $_SERVER,
            'get'    => $_GET, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Preserve request globals for this test fixture.
            'post'   => $_POST, // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Preserve request globals for this test fixture.
            'query'  => $GLOBALS['wp']->query_vars,
            'rest'   => $GLOBALS['wp_rest_server'] ?? null,
            'auth'   => \MainWP_REST_Authentication::$instance,
            'cost'   => Cost_Tracker_Utility::$instance,
        );
        foreach ( $GLOBALS['wp_filter']['determine_current_user']->callbacks[15] as $callback ) {
            $function = $callback['function'];
            if ( is_array( $function ) && $function[0] instanceof \MainWP_REST_Authentication && 'authenticate' === $function[1] ) {
                $this->auth = $function[0];
                break;
            }
        }
        $this->assertInstanceOf( \MainWP_REST_Authentication::class, $this->auth );
        \MainWP_REST_Authentication::$instance = $this->auth;
        foreach ( array( 'user', 'error', 'auth_method' ) as $name ) {
            $property = new \ReflectionProperty( $this->auth, $name );
            $property->setAccessible( true );
            $this->original['auth_state'][ $name ] = $property->getValue( $this->auth );
            $property->setValue( $this->auth, 'auth_method' === $name ? '' : null );
        }

        $this->owner_id = $this->set_current_user_as_admin();
        $this->other_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
        $this->keys     = array(
            'read_write' => $this->create_rest_api_key( $this->owner_id, 'read_write' ),
            'read'       => $this->create_rest_api_key( $this->owner_id, 'read' ),
        );
        $this->site_ids = array( $this->create_test_site(), $this->create_test_site() );
        foreach ( array( $this->owner_id, $this->other_id ) as $user_id ) {
            update_user_option( $user_id, 'mainwp_notice_saved_status', array( 'held' => 1 ) );
            \WP_Session_Tokens::get_instance( $user_id )->create( time() + DAY_IN_SECONDS );
        }
        $this->seed_settings();
        Cost_Tracker_Utility::$instance = new Cost_Tracker_Utility();

        $this->add_test_filter(
            'mainwp_currentusercan',
            function ( $allowed, $type, $right ) {
                if ( 'dashboard' === $type ) {
                    $this->right_calls[] = array( $right, get_current_user_id() );
                    return $right !== $this->denied_right;
                }
                return $allowed;
            },
            3
        );
        $this->add_test_filter(
            'mainwp_currentuserallowedaccesssites',
            function () {
                return $this->restricted ? array( $this->site_ids[0] ) : 'all';
            }
        );
        $this->add_test_filter(
            'mainwp_currentuserallowedaccessgroups',
            function () {
                return $this->restricted_groups ? array() : 'all';
            }
        );
        $this->add_test_filter(
            'mainwp_fetch_url_authed_pre',
            function () {
                ++$this->child_calls;
                return new \WP_Error( 'test_no_child_request', 'Queued jobs must not run during dispatch.' );
            }
        );
        $this->add_test_filter(
            'pre_http_request',
            function () {
                ++$this->http_calls;
                return new \WP_Error( 'test_no_network', 'HTTP is disabled in this fixture.' );
            }
        );
        $GLOBALS['wp_rest_server'] = new \WP_REST_Server();
        do_action( 'rest_api_init' );
        ( new \MainWP_Rest_Settings_Controller() )->register_routes();
    }

    /** Restore authentication, globals and cached module settings. */
    public function tearDown(): void {
        foreach ( $this->filters as $hook => $callback ) {
            remove_filter( $hook, $callback, 99 );
        }
        foreach ( MainWP_DB::instance()->remove_rest_api_keys_by_user( $this->owner_id ) as $row ) {
            \MainWP_REST_Authentication::delete_api_key_cache( $row->consumer_key );
        }
        foreach ( $this->original['auth_state'] as $name => $value ) {
            $property = new \ReflectionProperty( $this->auth, $name );
            $property->setAccessible( true );
            $property->setValue( $this->auth, $value );
        }
        \MainWP_REST_Authentication::$instance = $this->original['auth'];
        Cost_Tracker_Utility::$instance        = $this->original['cost'];
        $_SERVER                               = $this->original['server'];
        $_GET                                  = $this->original['get'];
        $_POST                                 = $this->original['post'];
        $GLOBALS['wp']->query_vars             = $this->original['query'];
        $GLOBALS['wp_rest_server']             = $this->original['rest'];
        wp_set_current_user( 0 );
        parent::tearDown();
    }

    /**
     * Register a fixture filter without replacing other callbacks.
     *
     * @param string   $hook          Filter name.
     * @param callable $callback      Fixture callback.
     * @param int      $accepted_args Argument count.
     */
    private function add_test_filter( $hook, $callback, $accepted_args = 1 ): void {
        $this->filters[ $hook ] = $callback;
        add_filter( $hook, $callback, 99, $accepted_args );
    }

    /** Seed each writer's options with values that the request will change. */
    private function seed_settings(): void {
        $options = array(
            'mainwp_backup_before_upgrade'             => 0,
            'mainwp_maximumRequests'                   => 4,
            'mainwp_global_uptime_monitoring_settings' => \MainWP\Dashboard\MainWP_Uptime_Monitoring_Handle::get_default_monitoring_settings( false ),
            'mainwp_disableSitesHealthMonitoring'      => 0,
            'mainwp_settings_notification_emails'      => array( 'daily_digest' => array( 'subject' => 'Held subject' ) ),
            'mainwp_module_log_settings'               => array(
                'enabled' => 0,
                'held'    => 1,
            ),
            'mainwp_enable_kinsta_api'                 => 0,
            'mainwp_kinsta_company_id'                 => 'held-company',
            'mainwp_enable_guided_tours'               => false,
            'mainwp_selected_theme'                    => 'default',
            'mainwp_extensions_api_username'           => 'fixture-user',
            'mainwp_extensions_api_password'           => 'fixture-password',
            'mainwp_extensions_api_save_login'         => 1,
            'mainwp_extensions_plan_info'              => array( 'held' => 1 ),
            'mainwp_extensions_master_api_key'         => 'fixture-master',
            'mainwp_extensions'                        => array( array( 'held' => 1 ) ),
            'mainwp_extensions_all_activation_cached'  => 'held-cache',
            'mainwp_module_cost_tracker_options'       => array(
                'currency'               => 'USD',
                'currency_format'        => array(
                    'currency_position'  => 'left',
                    'thousand_separator' => ',',
                    'decimal_separator'  => '.',
                    'decimals'           => 2,
                ),
                'custom_product_types'   => wp_json_encode( array( 'held-product' => 'Held product' ) ),
                'product_types_colors'   => wp_json_encode( array( 'held-product' => '#34424D' ) ),
                'product_types_icons'    => wp_json_encode( array( 'held-product' => 'deficon:folder open' ) ),
                'custom_payment_methods' => wp_json_encode( array( 'held-payment' => 'Held payment' ) ),
            ),
        );
        foreach ( $options as $name => $value ) {
            update_option( $name, $value );
        }
    }

    /**
     * Every write route, with a valid request and an observable result.
     *
     * @return array
     */
    public static function write_routes(): array {
        return array(
            'general'          => array( 'PUT', 'general/edit', array( 'backup_before_upgrade' => 1 ), 'manage_dashboard_settings', 'mainwp_backup_before_upgrade', array(), 1 ),
            'advanced'         => array( 'PUT', 'advanced/edit', array( 'mainwp_maximum_requests' => 10 ), 'manage_dashboard_settings', 'mainwp_maximumRequests', array(), 10 ),
            'monitoring'       => array(
                'PUT',
                'monitoring/edit',
                array(
                    'mainwp_uptime_monitoring_active' => 1,
                    'mainwp_disable_sites_health_monitoring' => 1,
                ),
                'manage_dashboard_settings',
                'mainwp_global_uptime_monitoring_settings',
                array( 'active' ),
                1,
            ),
            'emails'           => array( 'PUT', 'emails/daily_digest/edit', array( 'subject' => 'Updated subject' ), 'manage_dashboard_settings', 'mainwp_settings_notification_emails', array( 'daily_digest', 'subject' ), 'Updated subject' ),
            'cost-settings'    => array( 'PUT', 'cost-tracker/edit', array( 'currency' => 'EUR' ), 'manage_dashboard_settings', 'mainwp_module_cost_tracker_options', array( 'currency' ), 'EUR' ),
            'product-add'      => array( 'POST', 'cost-tracker/product-types/add', array( 'title' => 'New product' ), 'manage_dashboard_settings', 'mainwp_module_cost_tracker_options', array( 'custom_product_types', 'new-product' ), 'New product' ),
            'product-edit'     => array( 'PUT', 'cost-tracker/product-types/held-product/edit', array( 'title' => 'Updated product' ), 'manage_dashboard_settings', 'mainwp_module_cost_tracker_options', array( 'custom_product_types', 'held-product' ), 'Updated product' ),
            'product-delete'   => array( 'DELETE', 'cost-tracker/product-types/held-product/delete', array(), 'manage_dashboard_settings', 'mainwp_module_cost_tracker_options', array( 'custom_product_types' ), array() ),
            'payment-add'      => array( 'POST', 'cost-tracker/payment-methods/add', array( 'title' => 'New payment' ), 'manage_dashboard_settings', 'mainwp_module_cost_tracker_options', array( 'custom_payment_methods', 'new-payment' ), 'New payment' ),
            'payment-edit'     => array( 'PUT', 'cost-tracker/payment-methods/held-payment/edit', array( 'title' => 'Updated payment' ), 'manage_dashboard_settings', 'mainwp_module_cost_tracker_options', array( 'custom_payment_methods', 'held-payment' ), 'Updated payment' ),
            'payment-delete'   => array( 'DELETE', 'cost-tracker/payment-methods/held-payment/delete', array(), 'manage_dashboard_settings', 'mainwp_module_cost_tracker_options', array( 'custom_payment_methods' ), array() ),
            'insights'         => array( 'PUT', 'dashboard-insights/edit', array( 'enable_insights_logging' => 1 ), 'manage_dashboard_settings', 'mainwp_module_log_settings', array( 'enabled' ), 1 ),
            'api-backups'      => array(
                'PUT',
                'api-backups/kinsta/edit',
                array(
                    'enabled'    => true,
                    'company_id' => 'new-company',
                ),
                'manage_api_backups',
                'mainwp_kinsta_company_id',
                array(),
                'new-company',
            ),
            'tools-settings'   => array(
                'PUT',
                'tools/edit',
                array(
                    'guided_tours' => true,
                    'mainwp_theme' => 'default',
                ),
                'manage_dashboard_settings',
                'mainwp_enable_guided_tours',
                array(),
                1,
            ),
            'destroy-sessions' => array( 'POST', 'tools/destroy-sessions', array(), 'manage_dashboard_settings', 'job', array( 'destroy_id', 'destroy', 'mainwp_cron_destroy_session_batch' ), null ),
            'renew'            => array( 'POST', 'tools/renew-connections', array(), 'manage_dashboard_settings', 'job', array( 'renew_id', 'renew', 'mainwp_cron_renew_connection_batch' ), null ),
            'disconnect'       => array( 'POST', 'tools/disconnect-all-sites', array(), 'manage_dashboard_settings', 'job', array( 'disconnect_id', 'disconnect', 'mainwp_cron_disconnect_batch' ), null ),
            'clear-activation' => array( 'POST', 'tools/clear-activation-data', array(), 'manage_dashboard_settings', 'mainwp_extensions_api_username', array(), false ),
            'restore-messages' => array( 'POST', 'tools/restore-info-messages', array(), 'manage_dashboard_settings', 'notices', array(), false ),
        );
    }

    /**
     * Snapshot options (including job transients and cron) and user sessions.
     *
     * @return array
     */
    private function stored_state(): array {
        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Compare raw persisted state across refusals.
        return array(
            'options' => $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} ORDER BY option_name", ARRAY_A ),
            'users'   => $wpdb->get_results( "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} ORDER BY umeta_id", ARRAY_A ),
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
    }

    /**
     * Authenticate a real key, then dispatch through the existing permissions.
     *
     * @param string $method      HTTP method.
     * @param string $route       Settings route suffix.
     * @param array  $body        JSON body.
     * @param string $permissions Key permission level.
     * @return \WP_REST_Response
     */
    private function dispatch_write( $method, $route, $body, $permissions = 'read_write' ) {
        $_GET  = array();
        $_POST = array();
        unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
        $_SERVER['HTTPS']                        = 'on';
        $_SERVER['REQUEST_METHOD']               = $method;
        $_SERVER['REQUEST_URI']                  = '/wp-json/mainwp/v2/settings/' . $route;
        $_SERVER['PHP_AUTH_USER']                = $this->keys[ $permissions ]['consumer_key'];
        $_SERVER['PHP_AUTH_PW']                  = $this->keys[ $permissions ]['consumer_secret'];
        $GLOBALS['wp']->query_vars['rest_route'] = '/mainwp/v2/settings/' . $route;
        wp_set_current_user( 0 );
        $owner = $this->auth->authenticate( false );
        $this->assertSame( $this->owner_id, (int) $owner );
        wp_set_current_user( $owner );
        $request = new \WP_REST_Request( $method, '/mainwp/v2/settings/' . $route );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( wp_json_encode( (object) $body ) );
        return rest_get_server()->dispatch( $request );
    }

    /**
     * Assert refusal leaves options, queues and sessions untouched.
     *
     * @param \WP_REST_Response $response REST response.
     * @param array             $before   Persisted state before dispatch.
     * @param int               $status   Expected HTTP status.
     * @param string            $code     Expected error code.
     */
    private function assert_refused( $response, $before, $status, $code ): void {
        $this->assertSame( $before, $this->stored_state(), 'Refusal changed settings, job/cron options or user sessions.' );
        $this->assertSame( 0, $this->child_calls, 'Refusal dispatched a Child request.' );
        $this->assertSame( 0, $this->http_calls, 'Refusal spawned cron or another HTTP request.' );
        $this->assertSame( $status, $response->get_status(), wp_json_encode( $response->get_data() ) );
        $this->assertSame( $code, $response->get_data()['code'] );
    }

    /**
     * Missing rights refuse every writer before its side effects.
     *
     * @dataProvider write_routes
     * @param string $method HTTP method.
     * @param string $route  Settings route.
     * @param array  $body   Request body.
     * @param string $right  Required right.
     */
    public function test_missing_owner_right_refuses_write( $method, $route, $body, $right ): void {
        $this->denied_right = $right;
        $before             = $this->stored_state();
        $response           = $this->dispatch_write( $method, $route, $body );
        $this->assert_refused( $response, $before, 403, 'mainwp_insufficient_right' );
        $this->assertSame(
            array(
                'status' => 403,
                'right'  => $right,
            ),
            $response->get_data()['data']
        );
        $this->assertContains( array( $right, $this->owner_id ), $this->right_calls );
        $this->assertTrue( defined( 'MAINWP_REST_API_DOING' ) && MAINWP_REST_API_DOING );
    }

    /**
     * A read-only key retains the authentication layer's refusal.
     *
     * @dataProvider write_routes
     * @param string $method HTTP method.
     * @param string $route  Settings route.
     * @param array  $body   Request body.
     */
    public function test_read_only_key_still_refused( $method, $route, $body ): void {
        $before   = $this->stored_state();
        $response = $this->dispatch_write( $method, $route, $body, 'read' );
        $this->assert_refused( $response, $before, 401, 'mainwp_rest_authentication_error' );
        $this->assertSame( array(), $this->right_calls );
    }

    /**
     * A granted right preserves the writer's existing result.
     *
     * @dataProvider write_routes
     * @param string $method   HTTP method.
     * @param string $route    Settings route.
     * @param array  $body     Request body.
     * @param string $right    Required right.
     * @param string $option   Option or action to inspect.
     * @param array  $path     Nested option keys or job metadata.
     * @param mixed  $expected Expected saved value.
     */
    public function test_granted_owner_right_preserves_write( $method, $route, $body, $right, $option, $path, $expected ): void {
        // Withhold the other settings right to prove the two gates are independent.
        $this->denied_right = 'manage_api_backups' === $right ? 'manage_dashboard_settings' : 'manage_api_backups';
        $response           = $this->dispatch_write( $method, $route, $body );
        $this->assert_write_result( $response, $option, $path, $expected );
    }

    /**
     * Check the saved value or the scoped background job and scheduled event.
     *
     * @param \WP_REST_Response $response REST response.
     * @param string            $option   Option or action.
     * @param array             $path     Nested keys or job metadata.
     * @param mixed             $expected Saved value.
     */
    private function assert_write_result( $response, $option, $path, $expected ): void {
        if ( 'mainwp_maximumRequests' === $option ) {
            // The existing response builder reads an undefined defaults key after saving.
            $this->assertSame( 500, $response->get_status() );
            $this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
            $this->assertStringContainsString( 'mainwp_maximum_install_update_requests', $response->get_data()['message'] );
        } else {
            $this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
            $this->assertSame( 1, $response->get_data()['success'] );
        }
        $this->assertSame( 0, $this->child_calls );
        if ( 'job' === $option ) {
            $id     = $response->get_data()[ $path[0] ];
            $job    = get_transient( 'mainwp_tool_' . $path[1] . '_job_' . $id );
            $ids    = $this->restricted ? array( $this->site_ids[0] ) : $this->site_ids;
            $queued = array_map( 'intval', $job['websites'] );
            sort( $ids );
            sort( $queued );
            $this->assertSame( $ids, $queued );
            $this->assertSame( 'pending', $job['status'] );
            $this->assertSame( count( $ids ), $job['total'] );
            $this->assertSame( 0, $job['processed'] );
            $this->assertNotFalse( wp_next_scheduled( $path[2], array( $id ) ) );
        } else {
            $value = 'notices' === $option ? get_user_option( 'mainwp_notice_saved_status', $this->owner_id ) : get_option( $option );
            foreach ( $path as $key ) {
                $value = is_string( $value ) ? json_decode( $value, true ) : $value;
                $value = $value[ $key ];
            }
            $value = ! empty( $path ) && is_string( $value ) && in_array( end( $path ), array( 'custom_product_types', 'custom_payment_methods' ), true ) ? json_decode( $value, true ) : $value;
            $this->assertSame( $expected, $value );
        }
        $this->assertSame( array( 'held' => 1 ), get_user_option( 'mainwp_notice_saved_status', $this->other_id ) );
        $this->assertCount( 1, \WP_Session_Tokens::get_instance( $this->owner_id )->get_all() );
        $this->assertCount( 1, \WP_Session_Tokens::get_instance( $this->other_id )->get_all() );
    }

    /**
     * Keep the existing global-scope refusals and scoped tools behavior.
     *
     * @dataProvider write_routes
     * @param string $method   HTTP method.
     * @param string $route    Settings route.
     * @param array  $body     Request body.
     * @param string $right    Required right.
     * @param string $option   Option or action.
     * @param array  $path     Nested keys or job metadata.
     * @param mixed  $expected Saved value.
     */
    public function test_restricted_owner_retains_existing_scope_guard( $method, $route, $body, $right, $option, $path, $expected ): void {
        $this->restricted = true;
        $before           = $this->stored_state();
        $response         = $this->dispatch_write( $method, $route, $body );
        if ( 'job' === $option || 'notices' === $option ) {
            $this->assert_write_result( $response, $option, $path, $expected );
        } else {
            $this->assert_refused( $response, $before, 403, 'mainwp_restricted_account' );
            $this->assertSame( array(), $this->right_calls );
        }
    }

    /**
     * The tools routes with limited site scope still require the operation right.
     *
     * @return array
     */
    public static function scoped_tools(): array {
        return array_intersect_key( self::write_routes(), array_flip( array( 'destroy-sessions', 'renew', 'disconnect', 'restore-messages' ) ) );
    }

    /**
     * Close the live gap without queuing work for a scoped owner.
     *
     * @dataProvider scoped_tools
     * @param string $method HTTP method.
     * @param string $route  Settings route.
     * @param array  $body   Request body.
     * @param string $right  Required right.
     */
    public function test_scoped_tools_without_right_refused( $method, $route, $body, $right ): void {
        $this->restricted = true;
        $this->test_missing_owner_right_refuses_write( $method, $route, $body, $right );
    }

    /**
     * Tag restrictions alone still refuse every global settings write.
     *
     * @dataProvider write_routes
     * @param string $method HTTP method.
     * @param string $route  Settings route.
     * @param array  $body   Request body.
     * @param string $right  Required right.
     * @param string $option Option or action.
     */
    public function test_tag_restriction_preserves_global_refusal( $method, $route, $body, $right, $option ): void {
        if ( 'job' === $option || 'notices' === $option ) {
            // These four routes deliberately keep their scoped permission callback.
            $this->denied_right = $right;
        }
        $this->restricted_groups = true;
        $before                  = $this->stored_state();
        $response                = $this->dispatch_write( $method, $route, $body );
        $code                    = 'job' === $option || 'notices' === $option ? 'mainwp_insufficient_right' : 'mainwp_restricted_account';
        $this->assert_refused( $response, $before, 403, $code );
    }
}
