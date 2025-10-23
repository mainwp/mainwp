<?php
/**
 * MainWP REST Controller
 *
 * This class handles the REST API
 *
 * @package MainWP\Dashboard
 */

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Notification_Settings;
use MainWP\Dashboard\MainWP_Settings_Indicator;
use MainWP\Dashboard\MainWP_DB_Uptime_Monitoring;
use MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Utility;
use MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Admin;
use MainWP\Dashboard\Module\ApiBackups\Api_Backups_3rd_Party;

/**
 * Class MainWP_Rest_Settings_Controller
 *
 * @package MainWP\Dashboard
 */
class MainWP_Rest_Settings_Controller extends MainWP_REST_Controller { //phpcs:ignore -- NOSONAR - multi methods.

	// phpcs:disable Generic.Metrics.CyclomaticComplexity -- complexity.

    /**
     * Protected static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    private static $instance = null;

    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'settings';

    /**
     * Constructor.
     */
    public function __construct() {
        $this->db = MainWP_DB::instance();
    }

    /**
     * Method instance()
     *
     * Create public static instance.
     *
     * @static
     * @return static::$instance
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Method register_routes()
     *
     * Creates the necessary endpoints for the api.
     * Note, for a request to be successful the URL query parameters consumer_key and consumer_secret need to be set and correct.
     */
	public function register_routes() { // phpcs:ignore -- NOSONAR - complex.
        // Global settings.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/general',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_general_settings' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
                'schema' => array( $this, 'get_public_item_schema' ),
            )
        );

        // Advanced settings.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/advanced',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_advanced_settings' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        // Email settings.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/emails',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_emails_settings' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        // Cost Tracker Settings.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/cost-tracker',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_cost_tracker_settings' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        // Dashboard Insights Settings.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/dashboard-insights',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_dashboard_insights_settings' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        // MainWP Dashboard Tools Settings.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/tools',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_tool_settings' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        // Backup API Providers Settings.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/api-backups',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_api_backup_settings' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        // Individual general settings.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>(\d+|[A-Za-z0-9-\.]+\.[A-Za-z]{2,6}))/general',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_individual_general_settings' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_allowed_id_domain_field(),
                ),
            )
        );
    }

    /**
     * Get all general settings.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @uses MainWP_Settings_Indicator::get_defaults_value()
     *
     * @return WP_Error|WP_REST_Response
     */
	public function get_general_settings( $request ) { // phpcs:ignore -- NOSONAR - complex.
        // Default Setting.
        $default_setting     = MainWP_Settings_Indicator::get_defaults_value();
        $show_widgets        = get_user_option( 'mainwp_settings_show_widgets', array() );
        $monitoring_settings = get_option( 'mainwp_global_uptime_monitoring_settings', array() );

        // Map settings.
        $settings = array(
            // General settings.
            'time_daily_update'               => get_option( 'mainwp_timeDailyUpdate', $default_setting['mainwp_timeDailyUpdate'] ),
            'frequency_daily_update'          => (int) get_option( 'mainwp_frequencyDailyUpdate', $default_setting['mainwp_frequencyDailyUpdate'] ),
            'date_format'                     => get_option( 'date_format', $default_setting['date_format'] ),
            'time_format'                     => get_option( 'time_format', $default_setting['time_format'] ),
            'timezone_string'                 => get_option( 'timezone_string', '' ),
            'gmt_offset'                      => get_option( 'gmt_offset', '' ),
            'sidebar_position'                => get_user_option( 'mainwp_sidebarPosition', $default_setting['sidebar_position'] ),
            'hide_update_everything'          => get_option( 'mainwp_hide_update_everything', $default_setting['mainwp_hide_update_everything'] ),
            'mainwp_widgets'                  => $show_widgets,
            'plugin_automatic_daily_update'   => (int) get_option( 'mainwp_pluginAutomaticDailyUpdate', $default_setting['mainwp_pluginAutomaticDailyUpdate'] ),
            'theme_automatic_daily_update'    => (int) get_option( 'mainwp_themeAutomaticDailyUpdate', $default_setting['mainwp_themeAutomaticDailyUpdate'] ),
            'trans_automatic_daily_update'    => (int) get_option( 'mainwp_transAutomaticDailyUpdate', 0 ),
            'automatic_daily_update'          => (int) get_option( 'mainwp_automaticDailyUpdate', $default_setting['mainwp_automaticDailyUpdate'] ),
            'frequency_auto_update'           => get_option( 'mainwp_frequency_AutoUpdate', $default_setting['mainwp_frequency_AutoUpdate'] ),
            'time_auto_update'                => get_option( 'mainwp_time_AutoUpdate', $default_setting['mainwp_time_AutoUpdate'] ),
            'show_language_updates'           => (int) get_option( 'mainwp_show_language_updates', $default_setting['mainwp_show_language_updates'] ),
            'disable_update_confirmations'    => (int) get_option( 'mainwp_disable_update_confirmations', $default_setting['mainwp_disable_update_confirmations'] ),
            'check_http_response'             => (int) get_option( 'mainwp_check_http_response', $default_setting['mainwp_check_http_response'] ),
            'check_http_response_method'      => get_option( 'mainwp_check_http_response_method', $default_setting['mainwp_check_http_response_method'] ),
            'backup_before_upgrade'           => (int) get_option( 'mainwp_backup_before_upgrade', $default_setting['mainwp_backup_before_upgrade'] ),
            'backup_before_upgrade_days'      => (int) get_option( 'mainwp_backup_before_upgrade_days', $default_setting['mainwp_backup_before_upgrade_days'] ),
            'numberdays_outdate_plugin_theme' => (int) get_option( 'mainwp_numberdays_Outdate_Plugin_Theme', $default_setting['mainwp_numberdays_Outdate_Plugin_Theme'] ),
            'monitoring'                      => $monitoring_settings,
            'dayinweek_auto_update'           => (int) get_option( 'mainwp_dayinweek_AutoUpdate', $default_setting['mainwp_dayinweek_AutoUpdate'] ),
            'dayinmonth_auto_update'          => (int) get_option( 'mainwp_dayinmonth_AutoUpdate', $default_setting['mainwp_dayinmonth_AutoUpdate'] ),
            'delay_autoupdate'                => (int) get_option( 'mainwp_delay_autoupdate', $default_setting['mainwp_dayinmonth_Automainwp_delay_autoupdateUpdate'] ),
        );

        // Response data by allowed fields.
        return rest_ensure_response(
            array(
                'success' => 1,
                'data'    => $this->filter_response_data_by_allowed_fields( $settings, 'view' ),
            )
        );
    }

    /**
     * Get all advanced settings.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @uses MainWP_Settings_Indicator::get_defaults_value()
     *
     * @return WP_Error|WP_REST_Response
     */
    public function get_advanced_settings( $request ) { // phpcs:ignore -- NOSONAR - complex.
        // Default Setting.
        $default_setting = MainWP_Settings_Indicator::get_defaults_value();
        // Sync data.
        $sync_data          = get_option( 'mainwp_settings_sync_data', array() );
        $sync_data_settings = ! empty( $sync_data ) ? json_decode( $sync_data, true ) : array();

        $settings = array(
            'mainwp_maximum_requests'                   => get_option( 'mainwp_maximumRequests', $default_setting['mainwp_maximumRequests'] ),
            'mainwp_minimum_delay'                      => get_option( 'mainwp_minimumDelay', $default_setting['mainwp_minimumDelay'] ),
            'mainwp_maximum_ip_requests'                => get_option( 'mainwp_maximumIPRequests', $default_setting['mainwp_maximumIPRequests'] ),
            'mainwp_minimum_ip_delay'                   => get_option( 'mainwp_minimumIPDelay', $default_setting['mainwp_minimumIPDelay'] ),
            'mainwp_chunksitesnumber'                   => get_option( 'mainwp_chunksitesnumber', 10 ),
            'mainwp_chunksleepinterval'                 => get_option( 'mainwp_chunksleepinterval', 5 ),
            'mainwp_maximum_sync_requests'              => get_option( 'mainwp_maximumSyncRequests', $default_setting['mainwp_maximumSyncRequests'] ),
            'mainwp_maximum_install_update_requests'    => get_option( 'mainwp_maximumInstallUpdateRequests', $default_setting['mainwp_maximum_install_update_requests'] ),
            'mainwp_maximum_uptime_monitoring_requests' => get_option( 'mainwp_maximum_uptime_monitoring_requests', $default_setting['mainwp_maximum_uptime_monitoring_requests'] ),
            'mainwp_optimize'                           => get_option( 'mainwp_optimize', $default_setting['mainwp_optimize'] ),
            'mainwp_wp_cron'                            => get_option( 'mainwp_wp_cron', $default_setting['mainwp_wp_cron'] ),
            'mainwp_ssl_verify_certificate'             => get_option( 'mainwp_sslVerifyCertificate', $default_setting['mainwp_ssl_verify_certificate'] ),
            'mainwp_verify_connection_method'           => get_option( 'mainwp_verify_connection_method', $default_setting['mainwp_verify_connection_method'] ),
            'mainwp_connect_signature_algo'             => get_option( 'mainwp_connect_signature_algo', $default_setting['mainwp_connect_signature_algo'] ),
            'mainwp_force_use_ipv4'                     => get_option( 'mainwp_forceUseIPv4', $default_setting['mainwp_forceUseIPv4'] ),
            'sync_data'                                 => $sync_data_settings,
        );

        return rest_ensure_response(
            array(
                'success' => 1,
                'data'    => $this->filter_response_data_by_allowed_fields( $settings, 'advanced_view' ),
            )
        );
    }

    /**
     * Get all email settings.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @uses MainWP_Notification_Settings::get_notification_types()
     * @uses MainWP_Notification_Settings::get_default_emails_fields()
     * @uses MainWP_Notification_Settings::get_settings_desc()
     *
     * @return WP_Error|WP_REST_Response
     */
    public function get_emails_settings( $request ) { // phpcs:ignore -- NOSONAR - complex.
        // Get notification email types.
        $notification_emails = MainWP_Notification_Settings::get_notification_types();
        // Get email settings.
        $emails_settings = get_option( 'mainwp_settings_notification_emails' );
        if ( ! is_array( $emails_settings ) ) {
            $emails_settings = array();
        }

        $settings = array();
        if ( ! empty( $notification_emails ) ) {
            foreach ( $notification_emails as $type => $name ) {
                $options           = isset( $emails_settings[ $type ] ) ? $emails_settings[ $type ] : array();
                $default           = MainWP_Notification_Settings::get_default_emails_fields( $type, '', true );
                $options           = array_merge( $default, $options );
                $email_description = MainWP_Notification_Settings::get_settings_desc( $type );

                $record     = array(
                    'type'        => $type,
                    'name'        => $name,
                    'description' => $email_description,
                    'recipients'  => $options['recipients'],
                    'disable'     => $options['disable'],
                );
                $settings[] = $this->filter_response_data_by_allowed_fields( $record, 'email_view' );
            }
        }

        return rest_ensure_response(
            array(
                'success' => 1,
                'data'    => $settings,
            )
        );
    }

    /**
     * Get all cost tracker settings.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @uses Cost_Tracker_Utility::get_instance()->get_option()
     * @uses Cost_Tracker_Utility::default_currency_settings()
     * @uses Cost_Tracker_Admin::get_product_colors()
     * @uses Cost_Tracker_Admin::get_product_type_icons()
     *
     * @return WP_Error|WP_REST_Response
     */
    public function get_cost_tracker_settings( $request ) { // phpcs:ignore -- NOSONAR - complex.
        // Get currency settings.
        $selected_currency = Cost_Tracker_Utility::get_instance()->get_option( 'currency', 'USD' );
        $currency_format   = Cost_Tracker_Utility::get_instance()->get_option( 'currency_format', array() );

        // Get default currency format.
        $default         = Cost_Tracker_Utility::default_currency_settings();
        $currency_format = array_merge( $default, $currency_format );

        $currency_position  = $currency_format['currency_position'];
        $thousand_separator = $currency_format['thousand_separator'];
        $decimal_separator  = $currency_format['decimal_separator'];
        $decimals           = $currency_format['decimals'];

        // Get custom  payment methods.
        $custom_payment_methods = Cost_Tracker_Utility::get_instance()->get_option( 'custom_payment_methods', array(), true );

        // Get product colors.
        $product_colors = Cost_Tracker_Admin::get_product_colors();

        // Get product icons.
        $product_types_icons = Cost_Tracker_Admin::get_product_type_icons();

        $product_categories = array();
        if ( ! empty( $product_types_icons ) && is_array( $product_types_icons ) ) {
            foreach ( $product_types_icons as $slug => $icon ) {
                $product_categories[] = array(
                    'slug'  => $slug,
                    'icon'  => $icon,
                    'color' => isset( $product_colors[ $slug ] ) ? $product_colors[ $slug ] : '#34424D',
                );
            }
        }

        $settings = array(
            'currency'           => $selected_currency,
            'currency_position'  => $currency_position,
            'thousand_separator' => $thousand_separator,
            'decimal_separator'  => $decimal_separator,
            'decimals'           => $decimals,
            'product_categories' => $product_categories,
            'payment_methods'    => $custom_payment_methods,
        );

        return rest_ensure_response(
            array(
                'success' => 1,
                'data'    => $this->filter_response_data_by_allowed_fields( $settings, 'cost_tracker_view' ),
            )
        );
    }

    /**
     * Get all dashboard insights settings.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function get_dashboard_insights_settings( $request ) { // phpcs:ignore -- NOSONAR - complex.
        $settings = array(
            'enable_insights_logging' => get_option( 'mainwp_module_log_enabled', 1 ),
            'module_log_settings'     => get_option( 'mainwp_module_log_settings', array() ),
        );

        return rest_ensure_response(
            array(
                'success' => 1,
                'data'    => $this->filter_response_data_by_allowed_fields( $settings, 'dashboard_insights_view' ),
            )
        );
    }

    /**
     * Get all API backup settings.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @uses Api_Backups_3rd_Party::get_cloudways_api_key()
     * @uses Api_Backups_3rd_Party::get_gridpane_api_key()
     * @uses Api_Backups_3rd_Party::get_vultr_api_key()
     * @uses Api_Backups_3rd_Party::get_linode_api_key()
     * @uses Api_Backups_3rd_Party::get_digitalocean_api_key()
     * @uses Api_Backups_3rd_Party::get_plesk_api_key()
     * @uses Api_Backups_3rd_Party::get_kinsta_api_key()
     *
     * @return WP_Error|WP_REST_Response
     */
    public function get_api_backup_settings( $request ) { // phpcs:ignore -- NOSONAR - complex.
        // Masked token.
        $masked = static function ( $token ) {
            $token = (string) $token;
            if ( empty( $token ) ) {
                return '';
            }
            return '****' . substr( $token, -4 );
        };

        $api_backups = array(
            'cloudways'    => array(
                'label'         => 'Cloudways',
                'enabled'       => (int) get_option( 'mainwp_enable_cloudways_api', 0 ),
                'account_email' => get_option( 'mainwp_cloudways_api_account_email', '' ),
                'secrets'       => array(
                    'api_key' => $masked( Api_Backups_3rd_Party::get_cloudways_api_key() ),
                ),
            ),
            'gridpane'     => array(
                'label'   => 'GridPane',
                'enabled' => (int) get_option( 'mainwp_enable_gridpane_api', 0 ),
                'secrets' => array(
                    'api_key' => $masked( Api_Backups_3rd_Party::get_gridpane_api_key() ),
                ),
            ),
            'vultr'        => array(
                'label'   => 'Vultr',
                'enabled' => (int) get_option( 'mainwp_enable_vultr_api', 0 ),
                'secrets' => array(
                    'api_key' => $masked( Api_Backups_3rd_Party::get_vultr_api_key() ),
                ),
            ),
            'linode'       => array(
                'label'   => 'Akamai (Linode)',
                'enabled' => (int) get_option( 'mainwp_enable_linode_api', 0 ),
                'secrets' => array(
                    'api_key' => $masked( Api_Backups_3rd_Party::get_linode_api_key() ),
                ),
            ),
            'digitalocean' => array(
                'label'   => 'DigitalOcean',
                'enabled' => (int) get_option( 'mainwp_enable_digitalocean_api', 0 ),
                'secrets' => array(
                    'api_key' => $masked( Api_Backups_3rd_Party::get_digitalocean_api_key() ),
                ),
            ),
            'cpanel'       => array(
                'label'     => 'cPanel (WP Toolkit)',
                'enabled'   => (int) get_option( 'mainwp_enable_cpanel_api', 0 ),
                'url'       => get_option( 'mainwp_cpanel_url', '' ),
                'site_path' => get_option( 'mainwp_cpanel_site_path', '' ),
                'username'  => get_option( 'mainwp_cpanel_account_username', '' ),
                'secrets'   => array(),
            ),
            'plesk'        => array(
                'label'   => 'Plesk',
                'enabled' => (int) get_option( 'mainwp_enable_plesk_api', 0 ),
                'url'     => get_option( 'mainwp_plesk_api_url', '' ),
                'secrets' => array(
                    'api_key' => $masked( Api_Backups_3rd_Party::get_plesk_api_key() ),
                ),
            ),
            'kinsta'       => array(
                'label'         => 'Kinsta',
                'enabled'       => (int) get_option( 'mainwp_enable_kinsta_api', 0 ),
                'account_email' => get_option( 'mainwp_kinsta_api_account_email', '' ),
                'company_id'    => get_option( 'mainwp_kinsta_company_id', '' ),
                'secrets'       => array(
                    'api_key' => $masked( Api_Backups_3rd_Party::get_kinsta_api_key() ),
                ),
            ),
        );

        return rest_ensure_response(
            array(
                'success' => 1,
                'data'    => $this->filter_response_data_by_allowed_fields( array( 'api_backups' => $api_backups ), 'api_backups_view' ),
            )
        );
    }

    /**
     * Get all tool settings.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function get_tool_settings( $request ) { // phpcs:ignore -- NOSONAR - complex.
        // Get tool settings.
        $settings = array(
            'mainwp_theme' => get_option( 'mainwp_selected_theme', 'default' ),
            'guided_tours' => get_option( 'mainwp_enable_guided_tours', 0 ),
            'chatbase'     => get_option( 'mainwp_enable_guided_chatbase', 0 ),
            'guided_video' => get_option( 'mainwp_enable_guided_video', 0 ),
        );

        return rest_ensure_response(
            array(
                'success' => 1,
                'data'    => $this->filter_response_data_by_allowed_fields( $settings, 'tool_view' ),
            )
        );
    }

    /**
     * Get individual general settings.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @uses MainWP_DB_Uptime_Monitoring::instance()->get_monitor_by()
     *
     * @return WP_Error|WP_REST_Response
     */
	public function get_individual_general_settings( $request ) { // phpcs:ignore -- NOSONAR - complex.
        $website = $this->get_request_item( $request );

        if ( empty( $website ) || is_wp_error( $website ) ) {
            return rest_ensure_response(
                array(
                    'success' => 0,
                    'message' => __( 'Website not found.', 'mainwp' ),
                )
            );
        }

        // Get monitoring settings.
        $monitor = MainWP_DB_Uptime_Monitoring::instance()->get_monitor_by( $website->id, 'suburl', '', array(), OBJECT );
        // Map settings.
        $settings = array(
            'name'                       => isset( $website->name ) ? $website->name : '',
            'admin_name'                 => isset( $website->adminname ) ? $website->adminname : '',
            'unique_id'                  => isset( $website->uniqueId ) ? $website->uniqueId : '',
            'verify_certificate'         => isset( $website->verify_certificate ) ? $website->verify_certificate : 0,
            'ssl_version'                => isset( $website->ssl_version ) ? $website->ssl_version : 0,
            'verify_connection_method'   => isset( $website->verify_method ) ? $website->verify_method : 0,
            'openssl_signature'          => isset( $website->signature_algo ) ? $website->signature_algo : 0,
            'force_use_ipv4'             => isset( $website->force_use_ipv4 ) ? $website->force_use_ipv4 : 0,
            'http_user'                  => isset( $website->http_user ) ? $website->http_user : '',
            'http_pass'                  => isset( $website->http_pass ) ? $website->http_pass : '',
            'suspended'                  => isset( $website->suspended ) ? $website->suspended : 0,
            'backup_before_upgrade'      => isset( $website->backup_before_upgrade ) ? $website->backup_before_upgrade : 0,
            'backup_before_upgrade_days' => isset( $website->backup_before_upgrade_days ) ? $website->backup_before_upgrade_days : 0,
            'automatic_update'           => isset( $website->automatic_update ) ? $website->automatic_update : 0,
            'ignore_core_updates'        => isset( $website->is_ignoreCoreUpdates ) ? $website->is_ignoreCoreUpdates : 0,
            'ignore_plugin_updates'      => isset( $website->is_ignorePluginUpdates ) ? $website->is_ignorePluginUpdates : 0,
            'ignore_theme_updates'       => isset( $website->is_ignoreThemeUpdates ) ? $website->is_ignoreThemeUpdates : 0,
            'connected'                  => isset( $website->added_timestamp ) ? $this->format_date( $website->added_timestamp ) : 0,
            'client_id'                  => isset( $website->client_id ) ? $website->client_id : 0,
            'monitoring'                 => array(
                'active'     => isset( $monitor->active ) ? $monitor->active : 0,
                'type'       => isset( $monitor->type ) ? $monitor->type : 'http',
                'method'     => isset( $monitor->method ) ? $monitor->method : 'head',
                'interval'   => isset( $monitor->interval ) ? $monitor->interval : 5 * 60, // 5 minutes.
                'timeout'    => isset( $monitor->timeout ) ? $monitor->timeout : 30, // 30 seconds.
                'maxretries' => isset( $monitor->max_retries ) ? $monitor->max_retries : 3,
            ),
        );

        return rest_ensure_response(
            array(
                'success' => 1,
                'data'    => $this->filter_response_data_by_allowed_fields( $settings, 'individual_view' ),
            )
        );
    }

    /**
     * Format date.
     *
     * @param int    $date Date.
     * @param string $format Date format.
     *
     * @return string
     */
    protected function format_date( int $date, $format = 'Y-m-d' ) {
        return gmdate( $format, (int) $date );
    }

    /**
     * Get allowed fields for settings.
     *
     * @return array
     */
    public function get_allowed_id_domain_field() {
        return array(
            'id_domain' => array(
                'required'          => true,
                'description'       => __( 'Site ID (number) or domain (string).', 'mainwp' ),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
        );
    }

    /**
     * Get site by id or domain.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_Error|Object Item.
     */
    public function get_request_item( $request ) {
        $value = $request['id_domain'];
        $by    = 'domain';
        if ( is_numeric( $value ) ) {
            $by = 'id';
        } else {
            $value = urldecode( $value );
        }
        return $this->get_site_by( $by, $value );
    }

    /**
     * Get the API keys schema, conforming to JSON Schema.
     *
     * @since  5.2
     * @return array
     */
	public function get_item_schema() {  // phpcs:ignore -- NOSONAR - long schema.
        return array(
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'settings',
            'type'       => 'object',
            'properties' => array(
                'name'                                   => array(
                    'type'        => 'string',
                    'description' => __( 'Website name.', 'mainwp' ),
                    'context'     => array( 'individual_view', 'email_view' ),
                ),
                'admin_name'                             => array(
                    'type'        => 'string',
                    'description' => __( 'Website admin name.', 'mainwp' ),
                    'context'     => array( 'individual_view' ),
                ),
                'unique_id'                              => array(
                    'type'        => 'string',
                    'description' => __( 'Website unique ID.', 'mainwp' ),
                    'context'     => array( 'individual_view' ),
                ),
                'ssl_version'                            => array(
                    'type'        => 'integer',
                    'description' => __( 'SSL version.', 'mainwp' ),
                    'context'     => array( 'individual_view' ),
                ),
                'verify_connection_method'               => array(
                    'type'        => 'integer',
                    'description' => __( 'Verify connection method.', 'mainwp' ),
                    'context'     => array( 'individual_view' ),
                ),
                'openssl_signature'                      => array(
                    'type'        => 'integer',
                    'description' => __( 'OpenSSL signature.', 'mainwp' ),
                    'context'     => array( 'individual_view' ),
                ),
                'force_use_ipv4'                         => array(
                    'type'        => 'integer',
                    'description' => __( 'Force use IPv4.', 'mainwp' ),
                    'context'     => array( 'individual_view' ),
                ),
                'http_user'                              => array(
                    'type'        => 'string',
                    'description' => __( 'HTTP user.', 'mainwp' ),
                    'context'     => array( 'individual_view' ),
                ),
                'http_pass'                              => array(
                    'type'        => 'string',
                    'description' => __( 'HTTP password.', 'mainwp' ),
                    'context'     => array( 'individual_view' ),
                ),
                'suspended'                              => array(
                    'type'        => 'integer',
                    'description' => __( 'Suspended.', 'mainwp' ),
                    'context'     => array( 'individual_view' ),
                ),
                'verify_certificate'                     => array(
                    'type'        => 'integer',
                    'description' => __( 'Verify certificate.', 'mainwp' ),
                    'context'     => array( 'individual_view' ),
                ),
                'automatic_update'                       => array(
                    'type'        => 'integer',
                    'description' => __( 'Automatic update enabled.', 'mainwp' ),
                    'context'     => array( 'individual_view' ),
                ),
                'ignore_core_updates'                    => array(
                    'type'        => 'integer',
                    'description' => __( 'Ignore core updates.', 'mainwp' ),
                    'context'     => array( 'individual_view' ),
                ),
                'ignore_plugin_updates'                  => array(
                    'type'        => 'integer',
                    'description' => __( 'Ignore plugin updates.', 'mainwp' ),
                    'context'     => array( 'individual_view' ),
                ),
                'ignore_theme_updates'                   => array(
                    'type'        => 'integer',
                    'description' => __( 'Ignore theme updates.', 'mainwp' ),
                    'context'     => array( 'individual_view' ),
                ),
                'connected'                              => array(
                    'type'        => 'integer',
                    'format'      => 'date-time',
                    'description' => __( 'Connected.', 'mainwp' ),
                    'context'     => array( 'individual_view' ),
                ),
                'client_id'                              => array(
                    'type'        => 'integer',
                    'description' => __( 'Client ID.', 'mainwp' ),
                    'context'     => array( 'individual_view' ),
                ),
                'time_daily_update'                      => array(
                    'type'        => 'string',
                    'description' => __( 'Time for daily updates.', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'frequency_daily_update'                 => array(
                    'type'        => 'integer',
                    'description' => __( 'Frequency of daily updates.', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'date_format'                            => array(
                    'type'        => 'string',
                    'description' => __( 'Date format.', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'time_format'                            => array(
                    'type'        => 'string',
                    'description' => __( 'Date format.', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'timezone_string'                        => array(
                    'type'        => 'string',
                    'description' => __( 'Date format.', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'gmt_offset'                             => array(
                    'type'        => 'string',
                    'description' => __( 'Date format.', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'sidebar_position'                       => array(
                    'type'        => 'integer',
                    'description' => __( 'Plugin automatic daily update enabled.', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'hide_update_everything'                 => array(
                    'type'        => 'integer',
                    'description' => __( 'Hide update everything.', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'mainwp_widgets'                         => array(
                    'type'        => 'array',
                    'description' => __( 'MainWP widgets.', 'mainwp' ),
                    'context'     => array( 'view' ),
                    'items'       => array(
                        'type' => 'integer',
                    ),
                ),
                'plugin_automatic_daily_update'          => array(
                    'type'        => 'integer',
                    'description' => __( 'Plugin automatic daily update enabled.', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'theme_automatic_daily_update'           => array(
                    'type'        => 'integer',
                    'description' => __( 'Theme automatic daily update enabled.', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'trans_automatic_daily_update'           => array(
                    'type'        => 'integer',
                    'description' => __( 'Translation automatic daily update enabled.', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'automatic_daily_update'                 => array(
                    'type'        => 'integer',
                    'description' => __( 'Automatic daily update enabled.', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'frequency_auto_update'                  => array(
                    'type'        => 'string',
                    'description' => __( 'Frequency of auto updates.', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'time_auto_update'                       => array(
                    'type'        => 'string',
                    'description' => __( 'Time for auto updates.', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'show_language_updates'                  => array(
                    'type'        => 'integer',
                    'description' => __( 'Show language updates.', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'disable_update_confirmations'           => array(
                    'type'        => 'integer',
                    'description' => __( 'Disable update confirmations.', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'check_http_response'                    => array(
                    'type'        => 'integer',
                    'description' => __( 'Check HTTP response after update.', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'check_http_response_method'             => array(
                    'type'        => 'string',
                    'description' => __( 'HTTP method for response check.', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'backup_before_upgrade'                  => array(
                    'type'        => 'integer',
                    'description' => __( 'Backup before upgrade enabled.', 'mainwp' ),
                    'context'     => array( 'view', 'individual_view' ),
                ),
                'backup_before_upgrade_days'             => array(
                    'type'        => 'integer',
                    'description' => __( 'Days to check for backup before upgrade.', 'mainwp' ),
                    'context'     => array( 'view', 'individual_view' ),
                ),
                'numberdays_outdate_plugin_theme'        => array(
                    'type'        => 'integer',
                    'description' => __( 'Number of days to consider plugin/theme as outdated.', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'monitoring'                             => array(
                    'type'        => 'array',
                    'description' => __( 'MainWP widgets.', 'mainwp' ),
                    'context'     => array( 'view' ),
                    'items'       => array(
                        'type' => 'string',
                    ),
                ),
                'dayinweek_auto_update'                  => array(
                    'type'        => 'integer',
                    'description' => __( 'Day in week for auto updates.', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'dayinmonth_auto_update'                 => array(
                    'type'        => 'integer',
                    'description' => __( 'Day in month for auto updates.', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'delay_autoupdate'                       => array(
                    'type'        => 'integer',
                    'description' => __( 'Delay for auto updates.', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'mainwp_maximum_requests'                => array(
                    'type'        => 'integer',
                    'description' => __( 'Maximum requests.', 'mainwp' ),
                    'context'     => array( 'advanced_view' ),
                ),
                'mainwp_minimum_delay'                   => array(
                    'type'        => 'integer',
                    'description' => __( 'Minimum delay.', 'mainwp' ),
                    'context'     => array( 'advanced_view' ),
                ),
                'mainwp_maximum_ip_requests'             => array(
                    'type'        => 'integer',
                    'description' => __( 'Maximum IP requests.', 'mainwp' ),
                    'context'     => array( 'advanced_view' ),
                ),
                'mainwp_minimum_ip_delay'                => array(
                    'type'        => 'integer',
                    'description' => __( 'Minimum IP delay.', 'mainwp' ),
                    'context'     => array( 'advanced_view' ),
                ),
                'mainwp_chunksitesnumber'                => array(
                    'type'        => 'integer',
                    'description' => __( 'Chunk sites number.', 'mainwp' ),
                    'context'     => array( 'advanced_view' ),
                ),
                'mainwp_chunksleepinterval'              => array(
                    'type'        => 'integer',
                    'description' => __( 'Chunk sleep interval.', 'mainwp' ),
                    'context'     => array( 'advanced_view' ),
                ),
                'mainwp_maximum_sync_requests'           => array(
                    'type'        => 'integer',
                    'description' => __( 'Maximum sync requests.', 'mainwp' ),
                    'context'     => array( 'advanced_view' ),
                ),
                'mainwp_maximum_install_update_requests' => array(
                    'type'        => 'integer',
                    'description' => __( 'Maximum install and update requests.', 'mainwp' ),
                    'context'     => array( 'advanced_view' ),
                ),
                'mainwp_maximum_uptime_monitoring_requests' => array(
                    'type'        => 'integer',
                    'description' => __( 'Maximum uptime monitoring requests.', 'mainwp' ),
                    'context'     => array( 'advanced_view' ),
                ),
                'mainwp_optimize'                        => array(
                    'type'        => 'integer',
                    'description' => __( 'Optimize data loading.', 'mainwp' ),
                    'context'     => array( 'advanced_view' ),
                ),
                'mainwp_wp_cron'                         => array(
                    'type'        => 'integer',
                    'description' => __( 'Use WP Cron.', 'mainwp' ),
                    'context'     => array( 'advanced_view' ),
                ),
                'mainwp_ssl_verify_certificate'          => array(
                    'type'        => 'integer',
                    'description' => __( 'Verify SSL certificate.', 'mainwp' ),
                    'context'     => array( 'advanced_view' ),
                ),
                'mainwp_verify_connection_method'        => array(
                    'type'        => 'integer',
                    'description' => __( 'Verify connection method.', 'mainwp' ),
                    'context'     => array( 'advanced_view' ),
                ),
                'mainwp_connect_signature_algo'          => array(
                    'type'        => 'integer',
                    'description' => __( 'OpenSSL signature algorithm.', 'mainwp' ),
                    'context'     => array( 'advanced_view' ),
                ),
                'mainwp_force_use_ipv4'                  => array(
                    'type'        => 'integer',
                    'description' => __( 'Force use IPv4.', 'mainwp' ),
                    'context'     => array( 'advanced_view' ),
                ),
                'sync_data'                              => array(
                    'type'        => 'array',
                    'description' => __( 'Sync data.', 'mainwp' ),
                    'context'     => array( 'advanced_view' ),
                    'items'       => array(
                        'type' => 'string',
                    ),
                ),
                'type'                                   => array(
                    'type'        => 'string',
                    'description' => __( 'Type.', 'mainwp' ),
                    'context'     => array( 'email_view' ),
                ),
                'description'                            => array(
                    'type'        => 'string',
                    'description' => __( 'Description.', 'mainwp' ),
                    'context'     => array( 'email_view' ),
                ),
                'recipients'                             => array(
                    'type'        => 'string',
                    'description' => __( 'Recipients.', 'mainwp' ),
                    'context'     => array( 'email_view' ),
                ),
                'disable'                                => array(
                    'type'        => 'integer',
                    'description' => __( 'Disable.', 'mainwp' ),
                    'context'     => array( 'email_view' ),
                ),
                'currency'                               => array(
                    'type'        => 'string',
                    'description' => __( 'Currency.', 'mainwp' ),
                    'context'     => array( 'cost_tracker_view' ),
                ),
                'currency_position'                      => array(
                    'type'        => 'string',
                    'description' => __( 'Currency position.', 'mainwp' ),
                    'context'     => array( 'cost_tracker_view' ),
                ),
                'thousand_separator'                     => array(
                    'type'        => 'string',
                    'description' => __( 'Thousand separator.', 'mainwp' ),
                    'context'     => array( 'cost_tracker_view' ),
                ),
                'decimal_separator'                      => array(
                    'type'        => 'string',
                    'description' => __( 'Decimal separator.', 'mainwp' ),
                    'context'     => array( 'cost_tracker_view' ),
                ),
                'decimals'                               => array(
                    'type'        => 'integer',
                    'description' => __( 'Decimals.', 'mainwp' ),
                    'context'     => array( 'cost_tracker_view' ),
                ),
                'product_categories'                     => array(
                    'type'        => 'array',
                    'description' => __( 'Product categories.', 'mainwp' ),
                    'context'     => array( 'cost_tracker_view' ),
                    'items'       => array(
                        'type' => 'object',
                    ),
                ),
                'payment_methods'                        => array(
                    'type'        => 'array',
                    'description' => __( 'Payment methods.', 'mainwp' ),
                    'context'     => array( 'cost_tracker_view' ),
                    'items'       => array(
                        'type' => 'string',
                    ),
                ),
                'enable_insights_logging'                => array(
                    'type'        => 'integer',
                    'description' => __( 'Enable insights logging.', 'mainwp' ),
                    'context'     => array( 'dashboard_insights_view' ),
                ),
                'module_log_settings'                    => array(
                    'type'        => 'array',
                    'description' => __( 'Module log settings.', 'mainwp' ),
                    'context'     => array( 'dashboard_insights_view' ),
                    'items'       => array(
                        'type' => 'string',
                    ),
                ),
                'api_backups'                            => array(
                    'type'        => 'array',
                    'description' => __( 'API backups.', 'mainwp' ),
                    'context'     => array( 'api_backups_view' ),
                    'items'       => array(
                        'type' => 'object',
                    ),
                ),
                'mainwp_theme'                           => array(
                    'type'        => 'string',
                    'description' => __( 'MainWP theme.', 'mainwp' ),
                    'context'     => array( 'tool_view' ),
                ),
                'guided_tours'                           => array(
                    'type'        => 'integer',
                    'description' => __( 'Usetiful (Interactiive Guides & Tips).', 'mainwp' ),
                    'context'     => array( 'tool_view' ),
                ),
                'chatbase'                               => array(
                    'type'        => 'integer',
                    'description' => __( 'Chatbase (AI-Powered Chat Support).', 'mainwp' ),
                    'context'     => array( 'tool_view' ),
                ),
                'guided_video'                           => array(
                    'type'        => 'integer',
                    'description' => __( 'Youtube Embeds (Video Tutorials).', 'mainwp' ),
                    'context'     => array( 'tool_view' ),
                ),
            ),
        );
    }
}
