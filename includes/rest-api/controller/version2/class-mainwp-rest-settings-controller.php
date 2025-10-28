<?php
/**
 * MainWP REST Controller
 *
 * This class handles the REST API
 *
 * @package MainWP\Dashboard
 */

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Utility;
use MainWP\Dashboard\MainWP_Notification_Settings;
use MainWP\Dashboard\MainWP_Settings_Indicator;
use MainWP\Dashboard\MainWP_System_Utility;
use MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Utility;
use MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Admin;
use MainWP\Dashboard\Module\ApiBackups\Api_Backups_3rd_Party;
use MainWP\Dashboard\Module\ApiBackups\Api_Backups_Utility;

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
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/general/edit',
            array(
                'methods'             => 'PUT, PATCH',
                'callback'            => array( $this, 'update_general_settings' ),
                'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                'args'                => $this->get_update_general_allowed_fields(),
            ),
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
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/advanced/edit',
            array(
                array(
                    'methods'             => 'PUT, PATCH',
                    'callback'            => array( $this, 'update_advanced_settings' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_update_advanced_allowed_fields(),
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
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/emails/(?P<mail_type>[a-zA-Z0-9\-\.\_]+)/edit',
            array(
                array(
                    'methods'             => 'PUT, PATCH',
                    'callback'            => array( $this, 'update_emails_settings' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_update_emails_allowed_fields(),
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
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/cost-tracker/edit',
            array(
                array(
                    'methods'             => 'PUT, PATCH',
                    'callback'            => array( $this, 'edit_cost_tracker_settings' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_cost_tracker_allowed_fields(),
                ),
            )
        );
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/cost-tracker/product-types/add',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'add_cost_tracker_product_type' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_add_cost_tracker_product_type_allowed_fields(),
                ),
            )
        );
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/cost-tracker/product-types/(?P<slug>[a-zA-Z0-9\-\.\_]+)/edit',
            array(
                array(
                    'methods'             => 'PUT, PATCH',
                    'callback'            => array( $this, 'update_cost_tracker_product_type' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_edit_cost_tracker_product_type_allowed_fields(),
                ),
            )
        );
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/cost-tracker/product-types/(?P<slug>[a-zA-Z0-9\-\.\_]+)/delete',
            array(
                array(
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => array( $this, 'delete_cost_tracker_product_type' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_allowed_product_type_slug_field(),
                ),
            )
        );
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/cost-tracker/payment-methods/add',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'add_cost_tracker_payment_method' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_add_cost_tracker_payment_method_allowed_fields(),
                ),
            )
        );
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/cost-tracker/payment-methods/(?P<slug>[a-zA-Z0-9\-\.\_]+)/edit',
            array(
                array(
                    'methods'             => 'PUT, PATCH',
                    'callback'            => array( $this, 'update_cost_tracker_payment_method' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_edit_cost_tracker_payment_method_allowed_fields(),
                ),
            )
        );
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/cost-tracker/payment-methods/(?P<slug>[a-zA-Z0-9\-\.\_]+)/delete',
            array(
                array(
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => array( $this, 'delete_cost_tracker_payment_method' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_allowed_payment_method_slug_field(),
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
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/dashboard-insights/edit',
            array(
                array(
                    'methods'             => 'PUT, PATCH',
                    'callback'            => array( $this, 'update_dashboard_insights_settings' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_edit_dashboard_insight_allowed_fields(),
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
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/api-backups/(?P<api_slug>[a-zA-Z0-9\-\.\_]+)/edit',
            array(
                array(
                    'methods'             => 'PUT, PATCH',
                    'callback'            => array( $this, 'update_api_backup_settings' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_edit_api_backup_allowed_fields(),
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
     * @return WP_Error|WP_REST_Response
     */
    public function get_general_settings( $request ) { // phpcs:ignore -- NOSONAR - complex.
        // Get general settings data.
        $settings = $this->get_general_settings_data();

        // Response data by allowed fields.
        return rest_ensure_response(
            array(
                'success' => 1,
                'data'    => $this->filter_response_data_by_allowed_fields( $settings, 'view' ),
            )
        );
    }

    /**
     * Update general settings.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @uses MainWP_Utility::update_option()
     *
     * @return WP_Error|WP_REST_Response
     */
    public function update_general_settings( $request ) { // phpcs:ignore -- NOSONAR - complex.

        // Check content type.
        $content_type = $this->validate_content_type( $request );
        if ( is_wp_error( $content_type ) ) {
            return $content_type;
        }

        // Get request body.
        $body = $this->get_request_body( $request );
        if ( is_wp_error( $body ) ) {
            return $body;
        }

        // Get current user id.
        $user_id = get_current_user_id();

        try {
            // Hepper function to update if present.
            $update_if_present = function ( $key, $option_name, $cast = null ) use ( $body ) {
                return $this->update_option_if_present( $body, $key, $option_name, $cast );
            };

            // Hepper function to update if present.
            $update_use_option = function ( $key, $option_name, $cast = null ) use ( $body, $user_id ) {
                if ( array_key_exists( $key, $body ) ) {
                    $val = $body[ $key ];
                    $val = $this->cast_field_value( $val, $cast );
                    if ( 'mainwp_widgets' === $key ) {
                        $show_widgets = get_user_option( 'mainwp_settings_show_widgets', array() );
                        $val          = array_replace( $show_widgets, (array) $val );
                    }
                    update_user_option( $user_id, $option_name, $val, true );
                }
            };

            // Update option setting.
            $update_if_present( 'time_daily_update', 'mainwp_timeDailyUpdate', 'string' );
            $update_if_present( 'frequency_daily_update', 'mainwp_frequencyDailyUpdate', 'int' );
            $update_if_present( 'date_format', 'date_format', 'string' );
            $update_if_present( 'time_format', 'time_format', 'string' );
            $update_if_present( 'timezone_string', 'gmt_offset', 'string' );
            $update_if_present( 'hide_update_everything', 'mainwp_hide_update_everything', 'int' );
            $update_if_present( 'plugin_automatic_daily_update', 'mainwp_pluginAutomaticDailyUpdate', 'int' );
            $update_if_present( 'theme_automatic_daily_update', 'mainwp_themeAutomaticDailyUpdate', 'int' );
            $update_if_present( 'trans_automatic_daily_update', 'mainwp_transAutomaticDailyUpdate', 'int' );
            $update_if_present( 'automatic_daily_update', 'mainwp_automaticDailyUpdate', 'int' );
            $update_if_present( 'frequency_auto_update', 'mainwp_frequency_AutoUpdate', 'string' );
            $update_if_present( 'time_auto_update', 'mainwp_time_AutoUpdate', 'string' );
            $update_if_present( 'show_language_updates', 'mainwp_show_language_updates', 'int' );
            $update_if_present( 'disable_update_confirmations', 'mainwp_disable_update_confirmations', 'int' );
            $update_if_present( 'check_http_response', 'mainwp_check_http_response', 'int' );
            $update_if_present( 'check_http_response_method', 'mainwp_check_http_response_method', 'string' );
            $update_if_present( 'backup_before_upgrade', 'mainwp_backup_before_upgrade', 'int' );
            $update_if_present( 'backup_before_upgrade_days', 'mainwp_backup_before_upgrade_days', 'int' );
            $update_if_present( 'numberdays_outdate_plugin_theme', 'mainwp_numberdays_Outdate_Plugin_Theme', 'int' );
            $update_if_present( 'dayinweek_auto_update', 'mainwp_dayinweek_AutoUpdate', 'int' );
            $update_if_present( 'dayinmonth_auto_update', 'mainwp_dayinmonth_AutoUpdate', 'int' );
            $update_if_present( 'delay_autoupdate', 'mainwp_delay_autoupdate', 'int' );

            // Update Sidebar.
            $update_use_option( 'sidebar_position', 'mainwp_sidebarPosition', 'int' );
            $update_use_option( 'mainwp_widgets', 'mainwp_settings_show_widgets', 'array' );

            // Get general settings data.
            $settings = $this->get_general_settings_data();
        } catch ( \Exception $e ) {
            do_action( 'mainwp_debug_log', 'Update general settings error: ' . $e->getMessage() );
            return new WP_Error(
                'rest_invalid_param',
                sprintf( __( 'Update general settings error: %s', 'mainwp' ), $e->getMessage() ),
            );
        }

        return rest_ensure_response(
            array(
                'success' => 1,
                'message' => esc_html__( 'General settings updated successfully.', 'mainwp' ),
                'data'    => $this->filter_response_data_by_allowed_fields( $settings, 'edit' ),
            )
        );
    }

    /**
     * Get all advanced settings.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function get_advanced_settings( $request ) { // phpcs:ignore -- NOSONAR - complex.
        // Get advanced settings data.
        $settings = $this->get_advanced_settings_data();

        return rest_ensure_response(
            array(
                'success' => 1,
                'data'    => $this->filter_response_data_by_allowed_fields( $settings, 'advanced_view', 'advanced_edit' ),
            )
        );
    }

    /**
     * Update advanced settings.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @uses MainWP_Utility::update_option()
     * @uses MainWP_System_Utility::get_open_ssl_sign_algos()
     *
     * @return WP_Error|WP_REST_Response
     */
    public function update_advanced_settings( $request ) { // phpcs:ignore -- NOSONAR - long method.
        // Check content type.
        $content_type = $this->validate_content_type( $request );
        if ( is_wp_error( $content_type ) ) {
            return $content_type;
        }

        // Get request body.
        $body = $this->get_request_body( $request );
        if ( is_wp_error( $body ) ) {
            return $body;
        }

        try {
            // Hepper function to update if present.
            $update_if_present = function ( $key, $option_name, $cast = null ) use ( $body ) {
                return $this->update_option_if_present( $body, $key, $option_name, $cast );
            };

            // Update option setting.
            $update_if_present( 'mainwp_maximum_requests', 'mainwp_maximumRequests', 'int' );
            $update_if_present( 'mainwp_minimum_delay', 'mainwp_minimumDelay', 'int' );
            $update_if_present( 'mainwp_maximum_ip_requests', 'mainwp_maximumIPRequests', 'int' );
            $update_if_present( 'mainwp_minimum_ip_delay', 'mainwp_minimumIPDelay', 'int' );
            $update_if_present( 'mainwp_chunksitesnumber', 'mainwp_chunksitesnumber', 'int' );
            $update_if_present( 'mainwp_chunksleepinterval', 'mainwp_chunksleepinterval', 'int' );
            $update_if_present( 'mainwp_maximum_sync_requests', 'mainwp_maximumSyncRequests', 'int' );
            $update_if_present( 'mainwp_maximum_install_update_requests', 'mainwp_maximumInstallUpdateRequests', 'int' );
            $update_if_present( 'mainwp_maximum_uptime_monitoring_requests', 'mainwp_maximum_uptime_monitoring_requests', 'int' );
            $update_if_present( 'mainwp_optimize', 'mainwp_optimize', 'int' );
            $update_if_present( 'mainwp_wp_cron', 'mainwp_wp_cron', 'int' );
            $update_if_present( 'mainwp_ssl_verify_certificate', 'mainwp_sslVerifyCertificate', 'int' );
            $update_if_present( 'mainwp_verify_connection_method', 'mainwp_verify_connection_method', 'int' );
            $update_if_present( 'mainwp_force_use_ipv4', 'mainwp_forceUseIPv4', 'int' );

            // Update sync data.
            if ( isset( $body['sync_data'] ) ) {
                $syncs_data = $this->safe_json_decode( get_option( 'mainwp_settings_sync_data', array() ) ?? '' );
                $val_sync   = array_replace( $syncs_data, (array) $body['sync_data'] );
                MainWP_Utility::update_option( 'mainwp_settings_sync_data', wp_json_encode( $val_sync ) );
            }

            // Update signature algorithm.
            if ( isset( $body['mainwp_connect_signature_algo'] ) ) {
                $sign_algs     = MainWP_System_Utility::get_open_ssl_sign_algos();
                $val_signature = array_search( $body['mainwp_connect_signature_algo'], $sign_algs, true );
                if ( false === $val_signature ) {
                    $val_signature = defined( 'OPENSSL_ALGO_SHA256' ) ? (int) OPENSSL_ALGO_SHA256 : 1;
                }

                MainWP_Utility::update_option( 'mainwp_connect_signature_algo', $val_signature );
            }

            // Get general settings data.
            $settings = $this->get_advanced_settings_data();
        } catch ( \Exception $e ) {
            do_action( 'mainwp_debug_log', 'Update advanced settings error: ' . $e->getMessage() );
            return new WP_Error(
                'rest_invalid_param',
                sprintf( __( 'Update advanced settings error: %s', 'mainwp' ), $e->getMessage() ),
            );
        }

        return rest_ensure_response(
            array(
                'success' => 1,
                'message' => esc_html__( 'General settings updated successfully.', 'mainwp' ),
                'data'    => $this->filter_response_data_by_allowed_fields( $settings, 'advanced_edit' ),
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
                $options    = isset( $emails_settings[ $type ] ) ? $emails_settings[ $type ] : array();
                $default    = MainWP_Notification_Settings::get_default_emails_fields( $type, '', true );
                $options    = array_merge( $default, $options );
                $record     = array(
                    'type'       => $type,
                    'heading'    => $options['heading'],
                    'subject'    => $options['subject'],
                    'recipients' => $options['recipients'],
                    'disable'    => $options['disable'],
                );
                $settings[] = $this->filter_response_data_by_allowed_fields( $record, 'email_view', 'email_edit' );
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
     * Update email settings.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @uses MainWP_Utility::update_option()
     *
     * @return WP_Error|WP_REST_Response
     */
    public function update_emails_settings( $request ) { // phpcs:ignore -- NOSONAR - complex.
        // Check content type.
        $content_type = $this->validate_content_type( $request );
        if ( is_wp_error( $content_type ) ) {
            return $content_type;
        }

        // Get request body.
        $body = $this->get_request_body( $request );
        if ( is_wp_error( $body ) ) {
            return $body;
        }

        // Get mail type.
        $setting = $request->get_param( 'mail_type' );
        if ( empty( $setting ) ) {
            return new WP_Error( 'invalid_mail_type', __( 'Mail type is not exist.', 'mainwp' ) );
        }

        $type = $setting['type'] ?? '';
        if ( empty( $type ) ) {
            return new WP_Error( 'invalid_mail_type', __( 'Invalid email type.', 'mainwp' ) );
        }

        // Get email settings.
        $emails_settings = get_option( 'mainwp_settings_notification_emails' );
        if ( ! is_array( $emails_settings ) ) {
            $emails_settings = array();
        }

        // Update email settings.
        $emails_settings[ $type ]['heading']    = ! empty( $body['heading'] ) ? $body['heading'] : $setting['heading'];
        $emails_settings[ $type ]['recipients'] = ! empty( $body['recipients'] ) ? $body['recipients'] : $setting['recipients'];
        $emails_settings[ $type ]['subject']    = ! empty( $body['subject'] ) ? $body['subject'] : $setting['subject'];
        $emails_settings[ $type ]['disable']    = ! empty( $body['disable'] ) ? $body['disable'] : $setting['disable'];
        MainWP_Utility::update_option( 'mainwp_settings_notification_emails', $emails_settings );

        return rest_ensure_response(
            array(
                'success' => 1,
                'message' => __( 'Email settings updated successfully.', 'mainwp' ),
                'data'    => $this->filter_response_data_by_allowed_fields( $emails_settings[ $type ], 'email_edit' ),
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
     * @uses Cost_Tracker_Admin::get_product_types()
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

        // Get custom product types.
        $custom_product_types = Cost_Tracker_Admin::get_product_types();

        // Get product colors.
        $product_colors = Cost_Tracker_Admin::get_product_colors();

        // Get product icons.
        $product_types_icons = Cost_Tracker_Admin::get_product_type_icons();

        $product_categories = array();
        if ( ! empty( $product_types_icons ) && is_array( $product_types_icons ) ) {
            foreach ( $product_types_icons as $slug => $icon ) {
                $product_categories[] = array(
                    'slug'  => $slug,
                    'title' => isset( $custom_product_types[ $slug ] ) ? $custom_product_types[ $slug ] : '',
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
     * Edit cost tracker settings.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @uses Cost_Tracker_Utility::get_instance()->get_all_options()
     * @uses Cost_Tracker_Utility::get_instance()->save_options()
     *
     * @return WP_Error|WP_REST_Response
     */
    public function edit_cost_tracker_settings( $request ) {
        // Check content type.
        $content_type = $this->validate_content_type( $request );
        if ( is_wp_error( $content_type ) ) {
            return $content_type;
        }

        // Get request body.
        $body = $this->get_request_body( $request );
        if ( is_wp_error( $body ) ) {
            return $body;
        }

        // Get all options.
        $all_opts        = Cost_Tracker_Utility::get_instance()->get_all_options();
        $currency_format = $all_opts['currency_format'] ?? array(); // Set old currency format setting.

        // Map body to variables.
        $all_opts['currency']        = ! empty( $body['currency'] ) ? $body['currency'] : $all_opts['currency'];
        $all_opts['currency_format'] = array(
            'currency_position'  => ! empty( $body['currency_position'] ) ? $body['currency_position'] : $currency_format['currency_position'],
            'thousand_separator' => ! empty( $body['thousand_separator'] ) ? $body['thousand_separator'] : $currency_format['thousand_separator'],
            'decimal_separator'  => ! empty( $body['decimal_separator'] ) ? $body['decimal_separator'] : $currency_format['decimal_separator'],
            'decimals'           => ! empty( $body['decimals'] ) ? $body['decimals'] : $currency_format['decimals'],
        );

        // Save options.
        Cost_Tracker_Utility::get_instance()->save_options( $all_opts );

        return rest_ensure_response(
            array(
                'success' => 1,
                'message' => __( 'Cost tracker settings updated successfully.', 'mainwp' ),
                'data'    => array(
                    'currency'        => $all_opts['currency'],
                    'currency_format' => $all_opts['currency_format'],
                ),
            )
        );
    }

    /**
     * Add cost tracker product type.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @uses Cost_Tracker_Utility::get_instance()->get_all_options()
     * @uses Cost_Tracker_Utility::get_instance()->save_options()
     *
     * @return WP_Error|WP_REST_Response
     */
    public function add_cost_tracker_product_type( $request ) { // phpcs:ignore -- NOSONAR - long method.
        $body = $this->get_request_body( $request );

        if ( is_wp_error( $body ) ) {
            return $body;
        }

        // Map body to variables.
        $title = $body['title'];
        $slug  = sanitize_title( $title );
        $color = ! empty( $body['color'] ) ? $body['color'] : '#34424D';
        $icon  = ! empty( $body['icon'] ) ? $body['icon'] : 'deficon:folder open';

        // Map product type.
        $all_opts             = Cost_Tracker_Utility::get_instance()->get_all_options();
        $custom_product_types = $this->safe_json_decode( $all_opts['custom_product_types'] ?? '' );

        // Check if product type duplicated.
        if ( isset( $custom_product_types[ $slug ] ) ) {
            return new WP_Error( 'duplicate_title', __( 'Duplicate product type. Please choose another one.' ) );
        }
        // Map product type.
        $custom_product_types[ $slug ]    = $title;
        $all_opts['custom_product_types'] = wp_json_encode( $custom_product_types );

        // Map product type color.
        $product_types_colors             = $this->safe_json_decode( $all_opts['product_types_colors'] ?? '' );
        $product_types_colors[ $slug ]    = $color;
        $all_opts['product_types_colors'] = wp_json_encode( $product_types_colors );

        // Map product type icon.
        $product_types_icons             = $this->safe_json_decode( $all_opts['product_types_icons'] ?? '' );
        $product_types_icons[ $slug ]    = $icon;
        $all_opts['product_types_icons'] = wp_json_encode( $product_types_icons );

        // Save options.
        Cost_Tracker_Utility::get_instance()->save_options( $all_opts );
        return rest_ensure_response(
            array(
                'success' => 1,
                'message' => __( 'Product type added successfully.', 'mainwp' ),
                'data'    => array(
                    $slug => array(
                        'title' => $title,
                        'color' => $color,
                        'icon'  => $icon,
                    ),
                ),
            )
        );
    }

    /**
     * Update cost tracker product type.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @uses Cost_Tracker_Utility::get_instance()->get_all_options()
     * @uses Cost_Tracker_Utility::get_instance()->save_options()
     *
     * @return WP_Error|WP_REST_Response
     */
    public function update_cost_tracker_product_type( $request ) { // phpcs:ignore -- NOSONAR - long method.
        // Check content type.
        $content_type = $this->validate_content_type( $request );
        if ( is_wp_error( $content_type ) ) {
            return $content_type;
        }

        // Get request body.
        $body = $this->get_request_body( $request );
        if ( is_wp_error( $body ) ) {
            return $body;
        }

        $slug = $request->get_param( 'slug' );
        if ( empty( $slug ) ) {
            return new WP_Error( 'invalid_slug', __( 'Invalid product type slug.', 'mainwp' ) );
        }

        // Default Product Type.
        $default_product_types = Cost_Tracker_Admin::get_default_product_types();

        // Map body to variables.
        $title = $body['title'];
        $color = ! empty( $body['color'] ) ? $body['color'] : '#34424D';
        $icon  = ! empty( $body['icon'] ) ? $body['icon'] : 'deficon:folder open';

        // Cannot change title if it's a default product type.
        if ( array_key_exists( $slug, $default_product_types ) && $title !== $default_product_types[ $slug ] ) {
            return new WP_Error( 'invalid_title', __( 'Cannot change title of default product type.', 'mainwp' ) );
        }

        // Map product type.
        $all_opts                         = Cost_Tracker_Utility::get_instance()->get_all_options();
        $custom_product_types             = $this->safe_json_decode( $all_opts['custom_product_types'] ?? '' );
        $custom_product_types[ $slug ]    = $title;
        $all_opts['custom_product_types'] = wp_json_encode( $custom_product_types );

        // Map product type color.
        $product_types_colors             = $this->safe_json_decode( $all_opts['product_types_colors'] ?? '' );
        $product_types_colors[ $slug ]    = $color;
        $all_opts['product_types_colors'] = wp_json_encode( $product_types_colors );

        // Map product type icon.
        $product_types_icons             = $this->safe_json_decode( $all_opts['product_types_icons'] ?? '' );
        $product_types_icons[ $slug ]    = $icon;
        $all_opts['product_types_icons'] = wp_json_encode( $product_types_icons );

        // Save options.
        Cost_Tracker_Utility::get_instance()->save_options( $all_opts );
        return rest_ensure_response(
            array(
                'success' => 1,
                'message' => __( 'Product type updated successfully.', 'mainwp' ),
                'data'    => array(
                    $slug => array(
                        'title' => $title,
                        'color' => $color,
                        'icon'  => $icon,
                    ),
                ),
            )
        );
    }

    /**
     * Delete cost tracker product type.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @uses Cost_Tracker_Utility::get_instance()->get_all_options()
     * @uses Cost_Tracker_Utility::get_instance()->save_options()
     *
     * @return WP_Error|WP_REST_Response
     */
    public function delete_cost_tracker_product_type( $request ) { // phpcs:ignore -- NOSONAR - complex.
        $slug = $request->get_param( 'slug' );

        if ( empty( $slug ) ) {
            return new WP_Error( 'invalid_slug', __( 'Invalid product type slug.', 'mainwp' ) );
        }

        // Default Product Type.
        $default_product_types = Cost_Tracker_Admin::get_default_product_types();
        // Cannot delete a default product type.
        if ( array_key_exists( $slug, $default_product_types ) ) {
            return new WP_Error( 'invalid_title', __( 'Cannot delete of default product type.', 'mainwp' ) );
        }

        // Get all options.
        $all_opts             = Cost_Tracker_Utility::get_instance()->get_all_options();
        $custom_product_types = $this->safe_json_decode( $all_opts['custom_product_types'] ?? '' );
        $product_types_colors = $this->safe_json_decode( $all_opts['product_types_colors'] ?? '' );
        $product_types_icons  = $this->safe_json_decode( $all_opts['product_types_icons'] ?? '' );

        // Check if product type exists.
        if ( ! isset( $custom_product_types[ $slug ] ) ) {
            return new WP_Error( 'invalid_slug', __( 'Product type does not exist.', 'mainwp' ) );
        }

        // Delete product type.
        unset( $custom_product_types[ $slug ] );
        unset( $product_types_colors[ $slug ] );
        unset( $product_types_icons[ $slug ] );

        $all_opts['custom_product_types'] = wp_json_encode( $custom_product_types );
        $all_opts['product_types_colors'] = wp_json_encode( $product_types_colors );
        $all_opts['product_types_icons']  = wp_json_encode( $product_types_icons );

        // Save options.
        Cost_Tracker_Utility::get_instance()->save_options( $all_opts );

        return rest_ensure_response(
            array(
                'success' => 1,
                'message' => __( 'Product type deleted successfully.', 'mainwp' ),
            )
        );
    }

    /**
     * Add cost tracker payment method.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @uses Cost_Tracker_Utility::get_instance()->get_option()
     * @uses Cost_Tracker_Utility::get_instance()->update_option()
     *
     * @return WP_Error|WP_REST_Response
     */
    public function add_cost_tracker_payment_method( $request ) { // phpcs:ignore -- NOSONAR - complex.
        // Get request body.
        $body = $this->get_request_body( $request );
        if ( is_wp_error( $body ) ) {
            return $body;
        }

        $title = $body['title'];
        $slug  = sanitize_title( $title );

        // Get all options.
        $all_opts = Cost_Tracker_Utility::get_instance()->get_all_options();

        // Get payment methods.
        $payment_methods = $this->safe_json_decode( $all_opts['custom_payment_methods'] ?? '' );

        if ( isset( $payment_methods[ $slug ] ) ) {
            return new WP_Error( 'duplicate_title', __( 'Duplicate payment method. Please choose another one.' ) );
        }

        // Save payment method.
        $payment_methods[ $slug ]           = $title;
        $all_opts['custom_payment_methods'] = wp_json_encode( $payment_methods );

        // Save options.
        Cost_Tracker_Utility::get_instance()->save_options( $all_opts );

        return rest_ensure_response(
            array(
                'success' => 1,
                'message' => __( 'Payment method added successfully.', 'mainwp' ),
                'data'    => array(
                    $slug => $title,
                ),
            )
        );
    }

    /**
     * Edit cost tracker payment method.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @uses Cost_Tracker_Utility::get_instance()->get_all_options()
     * @uses Cost_Tracker_Utility::get_instance()->update_option()
     *
     * @return WP_Error|WP_REST_Response
     */
    public function update_cost_tracker_payment_method( $request ) { // phpcs:ignore -- NOSONAR - complex.
        // Check content type.
        $content_type = $this->validate_content_type( $request );
        if ( is_wp_error( $content_type ) ) {
            return $content_type;
        }

        // Get request body.
        $body = $this->get_request_body( $request );
        if ( is_wp_error( $body ) ) {
            return $body;
        }

        $slug = $request->get_param( 'slug' );

        // Get all options.
        $all_opts = Cost_Tracker_Utility::get_instance()->get_all_options();
        // Get payment methods.
        $payment_methods = $this->safe_json_decode( $all_opts['custom_payment_methods'] ?? '' );
        if ( ! isset( $payment_methods[ $slug ] ) ) {
            return new WP_Error( 'invalid_slug', __( 'Invalid payment method slug.', 'mainwp' ) );
        }

        $payment_methods[ $slug ]           = $body['title'];
        $all_opts['custom_payment_methods'] = wp_json_encode( $payment_methods );
        Cost_Tracker_Utility::get_instance()->save_options( $all_opts );

        return rest_ensure_response(
            array(
                'success' => 1,
                'message' => __( 'Payment method updated successfully.', 'mainwp' ),
                'data'    => array(
                    $slug => $body['title'],
                ),
            )
        );
    }

    /**
     * Delete cost tracker payment method.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @uses Cost_Tracker_Utility::get_instance()->get_all_options()
     * @uses Cost_Tracker_Utility::get_instance()->update_option()
     *
     * @return WP_Error|WP_REST_Response
     */
    public function delete_cost_tracker_payment_method( $request ) { // phpcs:ignore -- NOSONAR - complex.
        $slug = $request->get_param( 'slug' );

        // Get all options.
        $all_opts = Cost_Tracker_Utility::get_instance()->get_all_options();
        // Get payment methods.
        $payment_methods = $this->safe_json_decode( $all_opts['custom_payment_methods'] ?? '' );
        if ( ! isset( $payment_methods[ $slug ] ) ) {
            return new WP_Error( 'invalid_slug', __( 'Invalid payment method slug.', 'mainwp' ) );
        }

        unset( $payment_methods[ $slug ] );
        $all_opts['custom_payment_methods'] = wp_json_encode( $payment_methods );
        Cost_Tracker_Utility::get_instance()->save_options( $all_opts );

        return rest_ensure_response(
            array(
                'success' => 1,
                'message' => __( 'Payment method deleted successfully.', 'mainwp' ),
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
     * Update dashboard insights settings.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function update_dashboard_insights_settings( $request ) {
        // Check content type.
        $content_type = $this->validate_content_type( $request );
        if ( is_wp_error( $content_type ) ) {
            return $content_type;
        }

        // Get request body.
        $body = $this->get_request_body( $request );
        if ( is_wp_error( $body ) ) {
            return $body;
        }

        // Get Current settings.
        $current_insights_logging = (bool) get_option( 'mainwp_module_log_enabled', 1 );
        $current_log_settings     = get_option( 'mainwp_module_log_settings', array() );

        // Get log settings.
        $incoming_log_settings = array();
        if ( isset( $body['module_log_settings'] ) && is_array( $body['module_log_settings'] ) ) {
            $incoming_log_settings = $body['module_log_settings'];
            $new_log_settings      = array_replace( $current_log_settings, $incoming_log_settings );
        }

        // Get insights logging.
        $new_insights_logging = isset( $body['enable_insights_logging'] ) ? $body['enable_insights_logging'] : $current_insights_logging;

        // Save settings.
        MainWP_Utility::update_option( 'mainwp_module_log_settings', $new_log_settings );
        MainWP_Utility::update_option( 'mainwp_module_log_enabled', $new_insights_logging );

        return rest_ensure_response(
            array(
                'success' => 1,
                'message' => __( 'Dashboard insights settings updated successfully.', 'mainwp' ),
                'data'    => array(
                    'enable_insights_logging' => $new_insights_logging,
                    'module_log_settings'     => $new_log_settings,
                ),
            )
        );
    }

    /**
     * Get all API backup settings.
     *
     * @param WP_REST_Request $request Full details about the request.
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

        // Get all API backup settings.
        $defs             = $this->get_api_backup_definitions();
        $api_backups_data = array();

        foreach ( $defs as $slug => $def ) {
            $provider = array(
                'label'   => $def['label'],
                'enabled' => (int) get_option( $def['enabled_option'], 0 ),
            );

            if ( ! empty( $def['options'] ) ) {
                foreach ( $def['options'] as $field => $option_key ) {
                    $provider[ $field ] = get_option( $option_key, '' );
                }
            }

            // Get secrets key.
            $secrets = array();
            if ( ! empty( $def['secrets'] ) ) {
                foreach ( $def['secrets'] as $secret_key => $getter ) {
                    $value                  = is_callable( $getter ) ? call_user_func( $getter ) : '';
                    $secrets[ $secret_key ] = $masked( $value );
                }
            }
            $provider['secrets'] = $secrets;

            // Add provider to API backups data.
            $api_backups_data[ $slug ] = $provider;
        }

        return rest_ensure_response(
            array(
                'success' => 1,
                'data'    => $this->filter_response_data_by_allowed_fields( array( 'api_backups' => $api_backups_data ), 'api_backups_view' ),
            )
        );
    }

    /**
     * Update API backup settings.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @uses MainWP_Utility::update_option()
     * @uses Api_Backups_Utility::get_instance()->update_api_key()
     *
     * @return WP_Error|WP_REST_Response
     */
    public function update_api_backup_settings( $request ) {
        // Check content type.
        $content_type = $this->validate_content_type( $request );
        if ( is_wp_error( $content_type ) ) {
            return $content_type;
        }

        // Get request body.
        $body = $this->get_request_body( $request );
        if ( is_wp_error( $body ) ) {
            return $body;
        }

        // Get API slug data.
        $api = $request->get_param( 'api_slug' );
        if ( empty( $api ) ) {
            return new WP_Error( 'invalid_api_slug', __( 'API backup not exists.', 'mainwp' ) );
        }

        $updated = array();
        // Update option if present.
        $update_if_present = function ( $key, $option_name ) use ( $body, &$updated ) {
            // Check if key is set and option name is not empty.
            if ( isset( $body[ $key ] ) && ! empty( $option_name ) ) {
                MainWP_Utility::update_option( $option_name, $body[ $key ] );
                $updated[ $key ] = $body[ $key ];
            }
        };

        // Update secrets if present.
        $update_secrets_api = function ( $slug, $name = 'api_key', $value ) use ( &$updated ) {
            if ( ! empty( $slug ) && ! empty( $value ) ) {
                Api_Backups_Utility::get_instance()->update_api_key( $slug, $value );
                $updated[ $name ] = $value;
            }
        };

        // Update api option.
        $update_if_present( 'enabled', $api['enabled_option'] ?? '' );
        $update_if_present( 'account_email', $api['options']['account_email'] ?? '' );
        $update_if_present( 'url', $api['options']['url'] ?? '' );
        $update_if_present( 'site_path', $api['options']['site_path'] ?? '' );
        $update_if_present( 'username', $api['options']['username'] ?? '' );
        $update_if_present( 'company_id', $api['options']['company_id'] ?? '' );

        // Update api key or password.
        $update_secrets_api( $api['slug'] ?? '', 'api_key',$body['secrets']['api_key'] ?? '' );
        $update_secrets_api( $api['slug'] ?? '', 'password',$body['password'] ?? '' );

        return rest_ensure_response(
            array(
                'success' => 1,
                'message' => __( 'API backup settings updated successfully.', 'mainwp' ),
                'data'    => $updated,
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
        );

        return rest_ensure_response(
            array(
                'success' => 1,
                'data'    => $this->filter_response_data_by_allowed_fields( $settings, 'individual_view' ),
            )
        );
    }

    /**
     * Get allowed fields for general settings.
     *
     * @return array
     */
    public function get_update_general_allowed_fields() { // phpcs:ignore -- NOSONAR - long method.
        $val_bool        = array( 0, 1 );
        $disable_update  = array( 0, 1, 2 );
        $days_week       = range( 0, 6 );
        $days_month      = range( 0, 31 );
        $delay           = array( 0, 1, 2, 3, 4, 5, 6, 7, 14, 30 );
        $frequency       = array( 'daily', 'weekly', 'monthly' );
        $frequency_daily = range( 1, 12 );
        $http_methods    = array( 'head', 'get' );

        return array(
            'time_daily_update'               => array(
                'required'          => false,
                'description'       => __( 'Time for daily updates (HH:MM format).', 'mainwp' ),
                'type'              => 'string',
                'validate_callback' => array( $this, 'validate_time_update' ),
                'sanitize_callback' => array( $this, 'sanitize_time_update' ),
            ),
            'frequency_daily_update'          => array(
                'required'          => false,
                'description'       => __( 'Frequency of daily updates (1-12).', 'mainwp' ),
                'type'              => 'integer',
                'sanitize_callback' => $this->make_enum_sanitizer( $frequency_daily ),
                'validate_callback' => $this->make_enum_validator( $frequency_daily ),
            ),
            'date_format'                     => array(
                'required'          => false,
                'description'       => __( 'Date format.', 'mainwp' ),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => array( $this, 'validate_date_format' ),
            ),
            'time_format'                     => array(
                'required'          => false,
                'description'       => __( 'Time format.', 'mainwp' ),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => array( $this, 'validate_time_format' ),
            ),
            'timezone_string'                 => array(
                'required'          => false,
                'description'       => __( 'Timezone.', 'mainwp' ),
                'type'              => 'string',
                'sanitize_callback' => array( $this, 'sanitize_timezone_field' ),
                'validate_callback' => array( $this, 'validate_timezone' ),
            ),
            'sidebar_position'                => array(
                'required'          => false,
                'description'       => __( 'Sidebar position.', 'mainwp' ),
                'type'              => 'boolean',
                'sanitize_callback' => $this->make_enum_sanitizer( $val_bool ),
                'validate_callback' => $this->make_enum_validator( $val_bool ),
            ),
            'hide_update_everything'          => array(
                'required'          => false,
                'description'       => __( 'Hide update everything button.', 'mainwp' ),
                'type'              => 'boolean',
                'sanitize_callback' => $this->make_enum_sanitizer( $val_bool ),
                'validate_callback' => $this->make_enum_validator( $val_bool ),
            ),
            'mainwp_widgets'                  => array(
                'required'          => false,
                'description'       => __( 'MainWP dashboard widgets.', 'mainwp' ),
                'type'              => 'array',
                'sanitize_callback' => array( $this, 'sanitize_mainwp_widgets' ),
                'validate_callback' => array( $this, 'validate_mainwp_widgets' ),
            ),
            'plugin_automatic_daily_update'   => array(
                'required'          => false,
                'description'       => __( 'Plugin automatic daily update.', 'mainwp' ),
                'type'              => 'boolean',
                'sanitize_callback' => $this->make_enum_sanitizer( $val_bool ),
                'validate_callback' => $this->make_enum_validator( $val_bool ),
            ),
            'theme_automatic_daily_update'    => array(
                'required'          => false,
                'description'       => __( 'Theme automatic daily update.', 'mainwp' ),
                'type'              => 'boolean',
                'sanitize_callback' => $this->make_enum_sanitizer( $val_bool ),
                'validate_callback' => $this->make_enum_validator( $val_bool ),
            ),
            'trans_automatic_daily_update'    => array(
                'required'          => false,
                'description'       => __( 'Translation automatic daily update.', 'mainwp' ),
                'type'              => 'array',
                'sanitize_callback' => $this->make_enum_sanitizer( $val_bool ),
                'validate_callback' => $this->make_enum_validator( $val_bool ),
            ),
            'automatic_daily_update'          => array(
                'required'          => false,
                'description'       => __( 'Plugin automatic daily update.', 'mainwp' ),
                'type'              => 'array',
                'sanitize_callback' => $this->make_enum_sanitizer( $val_bool ),
                'validate_callback' => $this->make_enum_validator( $val_bool ),
            ),
            'frequency_auto_update'           => array(
                'required'          => false,
                'description'       => __( 'Frequency of auto updates.', 'mainwp' ),
                'type'              => 'string',
                'sanitize_callback' => $this->make_enum_sanitizer( $frequency, 'string' ),
                'validate_callback' => $this->make_enum_validator( $frequency, 'string' ),
            ),
            'time_auto_update'                => array(
                'required'          => false,
                'description'       => __( 'Auto update time (whole hour, 24h).', 'mainwp' ),
                'type'              => 'string',
                'validate_callback' => array( $this, 'validate_time_update' ),
                'sanitize_callback' => array( $this, 'sanitize_time_update' ),
            ),
            'show_language_updates'           => array(
                'required'          => false,
                'description'       => __( 'Show language updates.', 'mainwp' ),
                'type'              => 'boolean',
                'sanitize_callback' => $this->make_enum_sanitizer( $val_bool ),
                'validate_callback' => $this->make_enum_validator( $val_bool ),
            ),
            'disable_update_confirmations'    => array(
                'required'          => false,
                'description'       => __( 'Disable update confirmations.', 'mainwp' ),
                'type'              => 'integer',
                'sanitize_callback' => $this->make_enum_sanitizer( $disable_update ),
                'validate_callback' => $this->make_enum_validator( $disable_update ),
            ),
            'check_http_response'             => array(
                'required'          => false,
                'description'       => __( 'Check HTTP response after update.', 'mainwp' ),
                'type'              => 'boolean',
                'sanitize_callback' => $this->make_enum_sanitizer( $val_bool ),
                'validate_callback' => $this->make_enum_validator( $val_bool ),
            ),
            'check_http_response_method'      => array(
                'required'          => false,
                'description'       => __( 'HTTP method for response check.', 'mainwp' ),
                'type'              => 'string',
                'sanitize_callback' => $this->make_enum_sanitizer( $http_methods, 'string' ),
                'validate_callback' => $this->make_enum_validator( $http_methods, 'string' ),
            ),
            'backup_before_upgrade'           => array(
                'required'          => false,
                'description'       => __( 'Backup before upgrade.', 'mainwp' ),
                'type'              => 'boolean',
                'sanitize_callback' => $this->make_enum_sanitizer( $val_bool ),
                'validate_callback' => $this->make_enum_validator( $val_bool ),
            ),
            'backup_before_upgrade_days'      => array(
                'required'          => false,
                'description'       => __( 'Days to check for backup before upgrade.', 'mainwp' ),
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
            ),
            'numberdays_outdate_plugin_theme' => array(
                'required'          => false,
                'description'       => __( 'Number of days to consider plugin/theme as outdated.', 'mainwp' ),
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
            ),
            'dayinweek_auto_update'           => array(
                'required'          => false,
                'description'       => __( 'Day in week for auto updates.', 'mainwp' ),
                'type'              => 'integer',
                'sanitize_callback' => $this->make_enum_sanitizer( $days_week ),
                'validate_callback' => $this->make_enum_validator( $days_week ),
            ),
            'dayinmonth_auto_update'          => array(
                'required'          => false,
                'description'       => __( 'Day in month for auto updates.', 'mainwp' ),
                'type'              => 'integer',
                'sanitize_callback' => $this->make_enum_sanitizer( $days_month ),
                'validate_callback' => $this->make_enum_validator( $days_month ),
            ),
            'delay_autoupdate'                => array(
                'required'          => false,
                'description'       => __( 'Delay automatic updates.', 'mainwp' ),
                'type'              => 'integer',
                'sanitize_callback' => $this->make_enum_sanitizer( $delay ),
                'validate_callback' => $this->make_enum_validator( $delay ),
            ),
        );
    }

    /**
     * Get allowed fields for advanced settings.
     *
     * @uses MainWP_System_Utility::get_open_ssl_sign_algos()
     *
     * @return array
     */
    public function get_update_advanced_allowed_fields() { // phpcs:ignore -- NOSONAR - long method.
        $val_bool             = array( 0, 1 );
        $connection_method    = array( 1, 2 );
        $maximum_requests     = range( 1, 20 );
        $minimum_delay        = range( 100, 5000, 100 );
        $maximum_ip_requests  = range( 1, 10 );
        $minimum_ip_delay     = range( 500, 5000, 100 );
        $chunk_sites_number   = range( 1, 30 );
        $chunk_sleep_interval = range( 0, 20 );
        $monitoring_requests  = range( 1, 100 );
        $sign_algs            = MainWP_System_Utility::get_open_ssl_sign_algos();

        return array(
            'mainwp_maximum_requests'                   => array(
                'required'          => false,
                'description'       => __( 'Maximum requests.', 'mainwp' ),
                'type'              => 'integer',
                'sanitize_callback' => $this->make_enum_sanitizer( $maximum_requests ),
                'validate_callback' => $this->make_enum_validator( $maximum_requests ),
            ),
            'mainwp_minimum_delay'                      => array(
                'required'          => false,
                'description'       => __( 'Minimum delay.', 'mainwp' ),
                'type'              => 'integer',
                'sanitize_callback' => $this->make_enum_sanitizer( $minimum_delay ),
                'validate_callback' => $this->make_enum_validator( $minimum_delay ),
            ),
            'mainwp_maximum_ip_requests'                => array(
                'required'          => false,
                'description'       => __( 'Maximum IP requests.', 'mainwp' ),
                'type'              => 'integer',
                'sanitize_callback' => $this->make_enum_sanitizer( $maximum_ip_requests ),
                'validate_callback' => $this->make_enum_validator( $maximum_ip_requests ),
            ),
            'mainwp_minimum_ip_delay'                   => array(
                'required'          => false,
                'description'       => __( 'Minimum IP delay.', 'mainwp' ),
                'type'              => 'integer',
                'sanitize_callback' => $this->make_enum_sanitizer( $minimum_ip_delay ),
                'validate_callback' => $this->make_enum_validator( $minimum_ip_delay ),
            ),
            'mainwp_chunksitesnumber'                   => array(
                'required'          => false,
                'description'       => __( 'Chunk sites number.', 'mainwp' ),
                'type'              => 'integer',
                'sanitize_callback' => $this->make_enum_sanitizer( $chunk_sites_number ),
                'validate_callback' => $this->make_enum_validator( $chunk_sites_number ),
            ),
            'mainwp_chunksleepinterval'                 => array(
                'required'          => false,
                'description'       => __( 'Chunk sleep interval.', 'mainwp' ),
                'type'              => 'integer',
                'sanitize_callback' => $this->make_enum_sanitizer( $chunk_sleep_interval ),
                'validate_callback' => $this->make_enum_validator( $chunk_sleep_interval ),
            ),
            'mainwp_maximum_sync_requests'              => array(
                'required'          => false,
                'description'       => __( 'Maximum sync requests.', 'mainwp' ),
                'type'              => 'integer',
                'sanitize_callback' => $this->make_enum_sanitizer( $maximum_requests ),
                'validate_callback' => $this->make_enum_validator( $maximum_requests ),
            ),
            'mainwp_maximum_install_update_requests'    => array(
                'required'          => false,
                'description'       => __( 'Maximum install and update requests.', 'mainwp' ),
                'type'              => 'integer',
                'sanitize_callback' => $this->make_enum_sanitizer( $maximum_requests ),
                'validate_callback' => $this->make_enum_validator( $maximum_requests ),
            ),
            'mainwp_maximum_uptime_monitoring_requests' => array(
                'required'          => false,
                'description'       => __( 'Maximum uptime monitoring requests.', 'mainwp' ),
                'type'              => 'integer',
                'sanitize_callback' => $this->make_enum_sanitizer( $monitoring_requests ),
                'validate_callback' => $this->make_enum_validator( $monitoring_requests ),
            ),
            'mainwp_optimize'                           => array(
                'required'          => false,
                'description'       => __( 'Optimize data loading.', 'mainwp' ),
                'type'              => 'boolean',
                'sanitize_callback' => $this->make_enum_sanitizer( $val_bool ),
                'validate_callback' => $this->make_enum_validator( $val_bool ),
            ),
            'mainwp_wp_cron'                            => array(
                'required'          => false,
                'description'       => __( 'Use WP Cron.', 'mainwp' ),
                'type'              => 'boolean',
                'sanitize_callback' => $this->make_enum_sanitizer( $val_bool ),
                'validate_callback' => $this->make_enum_validator( $val_bool ),
            ),
            'mainwp_ssl_verify_certificate'             => array(
                'required'          => false,
                'description'       => __( 'Verify SSL certificate.', 'mainwp' ),
                'type'              => 'boolean',
                'sanitize_callback' => $this->make_enum_sanitizer( $val_bool ),
                'validate_callback' => $this->make_enum_validator( $val_bool ),
            ),
            'mainwp_verify_connection_method'           => array(
                'required'          => false,
                'description'       => __( 'Verify connection method.', 'mainwp' ),
                'type'              => 'integer',
                'sanitize_callback' => $this->make_enum_sanitizer( $connection_method ),
                'validate_callback' => $this->make_enum_validator( $connection_method ),
            ),
            'mainwp_force_use_ipv4'                     => array(
                'required'          => false,
                'description'       => __( 'Force use IPv4.', 'mainwp' ),
                'type'              => 'boolean',
                'sanitize_callback' => $this->make_enum_sanitizer( $val_bool ),
                'validate_callback' => $this->make_enum_validator( $val_bool ),
            ),
            'mainwp_connect_signature_algo'             => array(
                'required'          => false,
                'description'       => __( 'OpenSSL signature algorithm.', 'mainwp' ),
                'type'              => 'integer',
                'sanitize_callback' => $this->make_enum_sanitizer( $sign_algs, 'string' ),
                'validate_callback' => $this->make_enum_validator( $sign_algs, 'string' ),
            ),
            'sync_data'                                 => array(
                'required'          => false,
                'description'       => __( 'Sync data.', 'mainwp' ),
                'type'              => 'array',
                'sanitize_callback' => array( $this, 'sanitize_sync_data' ),
                'validate_callback' => array( $this, 'validate_sync_data' ),
            ),
        );
    }

    /**
     * Get update emails allowed fields.
     *
     * @return array
     */
    public function get_update_emails_allowed_fields() { // phpcs:ignore -- NOSONAR - long method.
        $val_bool = array( 0, 1 );
        return array(
            'mail_type'  => array(
                'required'          => true,
                'description'       => __( 'Setting Mail type.', 'mainwp' ),
                'type'              => 'string',
                'sanitize_callback' => array( $this, 'sanitize_email_type' ),
                'validate_callback' => array( $this, 'validate_email_type' ),
            ),
            'disable'    => array(
                'required'          => false,
                'description'       => __( 'Disable mail.', 'mainwp' ),
                'type'              => 'boolean',
                'sanitize_callback' => $this->make_enum_sanitizer( $val_bool ),
                'validate_callback' => $this->make_enum_validator( $val_bool ),
            ),
            'heading'    => array(
                'required'          => false,
                'description'       => __( 'Mail name.', 'mainwp' ),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'subject'    => array(
                'required'          => false,
                'description'       => __( 'Mail description.', 'mainwp' ),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_textarea_field',
            ),
            'recipients' => array(
                'required'          => false,
                'description'       => __( 'Mail recipients.', 'mainwp' ),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => function ( $value ) {
                    if ( ! is_email( $value ) ) {
                        return new WP_Error(
                            'invalid_email',
                            sprintf( __( 'Invalid email address: %s.', 'text-domain' ), $value ),
                        );
                    }
                    return true;
                },
            ),
        );
    }

    /**
     * Get add cost tracker product type allowed fields.
     *
     * @return array
     */
    public function get_add_cost_tracker_product_type_allowed_fields() { // phpcs:ignore -- NOSONAR - long method.
        return array(
            'title' => array(
                'required'          => true,
                'description'       => __( 'Product type title.', 'mainwp' ),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'color' => array(
                'required'          => false,
                'description'       => __( 'Product type color.', 'mainwp' ),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => array( $this, 'validate_product_type_color' ),
            ),
            'icon'  => array(
                'required'          => false,
                'description'       => __( 'Product type icon.', 'mainwp' ),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => array( $this, 'validate_product_type_icon' ),
            ),
        );
    }

    /**
     * Get cost tracker allowed fields.
     *
     * @return array
     */
    public function get_cost_tracker_allowed_fields() { // phpcs:ignore -- NOSONAR - long method.
        $currency_position = array(
            'left',
            'right',
            'left_space',
            'right_space',
        );
        return array(
            'currency'           => array(
                'required'          => false,
                'description'       => __( 'Currency.', 'mainwp' ),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => array( $this, 'validate_cost_tracker_currency' ),
            ),
            'currency_position'  => array(
                'required'          => false,
                'description'       => __( 'Currency position.', 'mainwp' ),
                'type'              => 'string',
                'sanitize_callback' => $this->make_enum_sanitizer( $currency_position, 'string' ),
                'validate_callback' => $this->make_enum_validator( $currency_position, 'string' ),
            ),
            'thousand_separator' => array(
                'required'          => false,
                'description'       => __( 'Thousand separator.', 'mainwp' ),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'decimal_separator'  => array(
                'required'          => false,
                'description'       => __( 'Decimal separator.', 'mainwp' ),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
        );
    }

    /**
     * Get edit cost tracker product type allowed fields.
     *
     * @return array
     */
    public function get_edit_cost_tracker_product_type_allowed_fields() { // phpcs:ignore -- NOSONAR - long method.
        return array_merge(
            $this->get_add_cost_tracker_product_type_allowed_fields(),
            $this->get_allowed_product_type_slug_field()
        );
    }

    /**
     * Get allowed product type slug field.
     *
     * @return array
     */
    public function get_allowed_product_type_slug_field() {
        return array(
            'slug' => array(
                'required'          => true,
                'description'       => __( 'Product type slug.', 'mainwp' ),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => array( $this, 'validate_product_type_slug' ),
            ),
        );
    }

    /**
     * Get add cost tracker payment method allowed fields.
     *
     * @return array
     */
    public function get_add_cost_tracker_payment_method_allowed_fields() { // phpcs:ignore -- NOSONAR - long method.
        return array(
            'title' => array(
                'required'          => true,
                'description'       => __( 'Payment method title.', 'mainwp' ),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
        );
    }

    /**
     * Get edit cost tracker payment method allowed fields.
     *
     * @return array
     */
    public function get_edit_cost_tracker_payment_method_allowed_fields() { // phpcs:ignore -- NOSONAR - long method.
        return array_merge(
            $this->get_allowed_payment_method_slug_field(),
            $this->get_add_cost_tracker_payment_method_allowed_fields()
        );
    }

    /**
     * Get allowed payment method slug field.
     *
     * @return array
     */
    public function get_allowed_payment_method_slug_field() {
        return array(
            'slug' => array(
                'required'          => true,
                'description'       => __( 'Payment method slug.', 'mainwp' ),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => array( $this, 'validate_payment_method_slug' ),
            ),
        );
    }

    /**
     * Get edit dashboard insights allowed fields.
     *
     * @return array
     */
    public function get_edit_dashboard_insight_allowed_fields() { // phpcs:ignore -- NOSONAR - long method.
        $val_bool = array( 0, 1 );
        return array(
            'enable_insights_logging' => array(
                'required'          => false,
                'description'       => __( 'Enable insights logging.', 'mainwp' ),
                'type'              => 'boolean',
                'sanitize_callback' => $this->make_enum_sanitizer( $val_bool ),
                'validate_callback' => $this->make_enum_validator( $val_bool ),
            ),
            'module_log_settings'     => array(
                'type'        => 'object',
                'required'    => false,
                'description' => __( 'Module log settings.', 'mainwp' ),
                'properties'  => array(
                    'enabled'     => array(
                        'type'              => 'integer',
                        'required'          => true,
                        'description'       => __( 'Module log status.', 'mainwp' ),
                        'sanitize_callback' => 'absint',
                        'enum'              => $val_bool,
                    ),
                    'records_ttl' => array(
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                        'required'          => true,
                        'description'       => __( 'Time to live for logs in seconds.', 'mainwp' ),
                    ),
                ),
            ),
        );
    }

    /**
     * Get edit api backup allowed fields.
     *
     * @return array
     */
    public function get_edit_api_backup_allowed_fields() { // phpcs:ignore -- NOSONAR - long method.
        return array_merge(
            $this->get_allowed_api_slug_field(),
            array(
                'username' => array(
                    'required'          => false,
                    'description'       => __( 'API username.', 'mainwp' ),
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'password' => array(
                    'required'          => false,
                    'description'       => __( 'API password.', 'mainwp' ),
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            )
        );
    }

    /**
     * Get allowed API slug field.
     *
     * @return array
     */
    public function get_allowed_api_slug_field() {
        return array(
            'api_slug'      => array(
                'required'          => true,
                'description'       => __( 'API slug.', 'mainwp' ),
                'type'              => 'string',
                'sanitize_callback' => array( $this, 'sanitize_api_slug' ),
                'validate_callback' => array( $this, 'validate_api_slug' ),
            ),
            'enabled'       => array(
                'type'              => 'boolean',
                'required'          => false,
                'description'       => __( 'Enable/Disable the provider.', 'mainwp' ),
                'sanitize_callback' => 'rest_sanitize_boolean',
                'validate_callback' => 'rest_is_boolean',
            ),
            'url'           => array(
                'type'              => 'string',
                'required'          => false,
                'description'       => __( 'Server URL (for cPanel).', 'mainwp' ),
                'sanitize_callback' => 'sanitize_url',
            ),
            'site_path'     => array(
                'type'              => 'string',
                'required'          => false,
                'description'       => __( 'Site path (for cPanel).', 'mainwp' ),
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'username'      => array(
                'type'              => 'string',
                'required'          => false,
                'description'       => __( 'Username (for cPanel).', 'mainwp' ),
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'password'      => array(
                'type'              => 'string',
                'required'          => false,
                'description'       => __( 'Password (for cPanel).', 'mainwp' ),
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'account_email' => array(
                'type'              => 'string',
                'required'          => false,
                'description'       => __( 'Account email (for Cloudways).', 'mainwp' ),
                'sanitize_callback' => 'sanitize_email',
                'validate_callback' => 'is_email',

            ),
            'company_id'    => array(
                'type'              => 'string',
                'required'          => false,
                'description'       => __( 'Company ID (for kinsta).', 'mainwp' ),
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'secrets'       => array(
                'type'        => 'object',
                'required'    => false,
                'description' => __( 'Secrets (Cpanel not used).', 'mainwp' ),
                'properties'  => array(
                    'api_key' => array(
                        'type'              => 'string',
                        'required'          => true,
                        'description'       => __( 'API key (for kinsta).', 'mainwp' ),
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                ),
            ),
        );
    }

    /**
     * Sanitize API slug.
     *
     * @param string $value API slug.
     *
     * @return string
     */
    public function sanitize_api_slug( $value ) {
        if ( empty( $value ) ) {
            return new WP_Error( 'invalid_slug', __( 'Invalid API slug.', 'mainwp' ) );
        }

        // Sanitize field.
        $value       = $this->sanitize_field( $value );
        $api_backups = $this->get_api_backup_definitions(); // Get API backup definitions.
        if ( ! array_key_exists( $value, $api_backups ) ) {
            return new WP_Error( 'invalid_slug', __( 'API slug not exists.', 'mainwp' ) );
        }
        $api_backups[ $value ]['slug'] = $value; // Add slug to API backup definition.
        return $api_backups[ $value ];
    }

    /**
     * Validate API slug.
     *
     * @param string          $value API slug.
     * @param WP_REST_Request $request Request object.
     *
     * @return bool|WP_Error
     */
    public function validate_api_slug( $value, $request ) {
        if ( empty( $value ) ) {
            return new WP_Error( 'invalid_slug', __( 'Invalid API slug.', 'mainwp' ) );
        }

        $value = $this->sanitize_field( $value );

        // Check if API slug exists.
        $api_backups = $this->get_api_backup_definitions();
        if ( ! array_key_exists( $value, $api_backups ) ) {
            return new WP_Error( 'invalid_slug', __( 'API slug not exists.', 'mainwp' ) );
        }

        return true;
    }

    /**
     * Validate currency.
     *
     * @param string          $value Currency.
     * @param WP_REST_Request $request Request object.
     *
     * @uses Cost_Tracker_Utility::get_currency_symbol()
     *
     * @return bool|WP_Error
     */
    public function validate_cost_tracker_currency( $value, $request ) {
        if ( empty( $value ) ) {
            return true;
        }

        $value  = $this->sanitize_field( $value ); // Sanitize field.
        $symbol = Cost_Tracker_Utility::get_currency_symbol( $value ); // Get currency symbol.
        if ( empty( $symbol ) ) {
            return new WP_Error( 'invalid_currency', __( 'Invalid currency.', 'mainwp' ) );
        }
        return true;
    }
    /**
     * Validate product type color.
     *
     * @param string          $value Product type color.
     * @param WP_REST_Request $request Request object.
     *
     * @return bool|WP_Error
     */
    public function validate_product_type_color( $value, $request ) {
        if ( empty( $value ) ) {
            return true;
        }

        $value = $this->sanitize_field( $value );
        if ( ! preg_match( '/^#([A-Fa-f0-9]{6})$/', $value ) ) {
            return new WP_Error( 'invalid_color', __( 'Invalid color. Color must be in the format "#RRGGBB".', 'mainwp' ) );
        }
        return true;
    }

    /**
     * Validate product type icon.
     *
     * @param string          $value Product type icon.
     * @param WP_REST_Request $request Request object.
     *
     * @return bool|WP_Error
     */
    public function validate_product_type_icon( $value, $request ) {
        if ( empty( $value ) ) {
            return true;
        }

        $value = $this->sanitize_field( $value );
        if ( ! preg_match( '/^[a-z0-9_\-]+:[a-z0-9_\-\s]+$/i', $value ) ) {
            return new WP_Error(
                'invalid_icon',
                __( 'Invalid icon. Icon must be in the format "icon_type:icon_name".', 'mainwp' )
            );
        }
        return true;
    }

    /**
     * Validate product type slug.
     *
     * @param string          $value Product type slug.
     * @param WP_REST_Request $request Request object.
     *
     * @uses Cost_Tracker_Utility::get_instance()->get_all_options()
     * @uses Cost_Tracker_Admin::get_default_product_types()
     *
     * @return bool|WP_Error
     */
    public function validate_product_type_slug( $value, $request ) {
        if ( empty( $value ) ) {
            return new WP_Error( 'invalid_slug', __( 'Invalid product type slug.', 'mainwp' ) );
        }

        $value         = $this->sanitize_field( $value );
        $all_opts      = Cost_Tracker_Utility::get_instance()->get_all_options();
        $product_types = $this->safe_json_decode( $all_opts['custom_product_types'] ?? '' );

        // Add default product types if is edit.
        if ( in_array( $request->get_method(), array( 'PUT', 'PATCH' ) ) ) {
            $product_types = array_merge( $product_types, Cost_Tracker_Admin::get_default_product_types() );
        }

        if ( ! isset( $product_types[ $value ] ) ) {
            return new WP_Error( 'invalid_slug', __( 'Product type does not exist.', 'mainwp' ) );
        }

        return true;
    }

    /**
     * Validate payment method slug.
     *
     * @param string          $value Payment method slug.
     * @param WP_REST_Request $request Request object.
     *
     * @uses Cost_Tracker_Utility::get_instance()->get_all_options()
     *
     * @return bool|WP_Error
     */
    public function validate_payment_method_slug( $value, $request ) { // phpcs:ignore -- NOSONAR - long method.
        if ( empty( $value ) ) {
            return new WP_Error( 'invalid_slug', __( 'Invalid payment method slug.', 'mainwp' ) );
        }

        $value           = $this->sanitize_field( $value );
        $all_opts        = Cost_Tracker_Utility::get_instance()->get_all_options();
        $payment_methods = $this->safe_json_decode( $all_opts['custom_payment_methods'] ?? '' );
        if ( ! isset( $payment_methods[ $value ] ) ) {
            return new WP_Error( 'invalid_slug', __( 'Payment method does not exist.', 'mainwp' ) );
        }

        return true;
    }

    /**
     * Sanitize mail type.
     *
     * @param string $value Email type.
     *
     * @uses MainWP_Notification_Settings::get_default_emails_fields()
     *
     * @return string|WP_Error
     */
    public function sanitize_email_type( $value ) { // phpcs:ignore -- NOSONAR - long method.
        if ( empty( $value ) ) {
            return new WP_Error( 'invalid_mail_type', __( 'Invalid email type.', 'mainwp' ) );
        }

        // Sanitize email type.
        $value = $this->sanitize_field( $value );

        // Get default email settings.
        $email_setting = MainWP_Notification_Settings::get_default_emails_fields( $value, '', true );
        if ( empty( $email_setting ) ) {
            return new WP_Error( 'invalid_mail_type', __( 'Invalid email type.', 'mainwp' ) );
        }

        return array_merge( $email_setting, array( 'type' => $value ) );
    }

    /**
     * Validate mail type.
     *
     * @param string          $value Email type.
     * @param WP_REST_Request $request Request object.
     *
     * @uses MainWP_Notification_Settings::get_notification_types()
     *
     * @return bool|WP_Error
     */
    public function validate_email_type( $value, $request ) { // phpcs:ignore -- NOSONAR - long method.
        if ( empty( $value ) ) {
            return new WP_Error( 'invalid_mail_type', __( 'Invalid email type.', 'mainwp' ) );
        }

        $value = $this->sanitize_field( $value );
        // Get notification email types.
        $notification_emails = MainWP_Notification_Settings::get_notification_types();

        if ( ! array_key_exists( $value, $notification_emails ) ) {
            return new WP_Error( 'invalid_mail_type', __( 'Email type does not exist.', 'mainwp' ) );
        }

        return true;
    }

    /**
     * Validate frequency daily update.
     *
     * @param int             $value Frequency daily update.
     * @param WP_REST_Request $request Request object.
     *
     * @return bool|WP_Error
     */
    public function validate_frequency_daily_update( $value, $request ) {
        if ( '' === $value || null === $value ) {
            return true;
        }

        $int_value = (int) $this->sanitize_field( $value );
        if ( $int_value < 1 || $int_value > 12 ) {
            return new WP_Error( 'invalid_frequency_daily_update', __( 'Invalid frequency daily update. Use 1-12.', 'mainwp' ) );
        }
        return true;
    }

    /**
     * Validate date format.
     *
     * @param string          $value Date format.
     * @param WP_REST_Request $request Request object.
     *
     * @return bool|WP_Error
     */
    public function validate_date_format( $value, $request ) {
        if ( empty( $value ) ) {
            return true;
        }

        $value = $this->sanitize_field( $value );
        // No numbers allowed.
        if ( preg_match( '/\d/', $value ) ) {
            return new WP_Error(
                'invalid_date_format_numeric',
                __( 'Invalid date format: should not contain digits. Use format characters like F j, Y.', 'mainwp' ),
            );
        }

        // Allows only valid PHP date() format characters and some basic separators.
        if ( ! preg_match( '/^[dDjlNSwzWFmMntLoYyaABgGhHisueIOPTZcrU\-\.,:\/\s\\\\]+$/', $value ) ) {
            return new WP_Error(
                'invalid_date_format',
                __( 'Invalid date format. Use valid PHP date format characters like F j, Y.', 'mainwp' ),
            );
        }

        // Check rendering works.
        $date = date_i18n( $value, time() );
        if ( false === $date || '' === $date ) {
            return new WP_Error(
                'invalid_date_format_render',
                __( 'Invalid or unsupported date format.', 'mainwp' ),
            );
        }

        return true;
    }

    /**
     * Validate time format.
     *
     * @param string          $value Time format.
     * @param WP_REST_Request $request Request object.
     *
     * @return bool|WP_Error
     */
    public function validate_time_format( $value, $request ) {
        if ( empty( $value ) ) {
            return true;
        }

        $value = $this->sanitize_field( $value );
        // No numbers allowed.
        if ( preg_match( '/\d/', $value ) ) {
            return new WP_Error(
                'invalid_time_format_numeric',
                __( 'Invalid time format: should not contain digits. Use format characters like g:i a.', 'mainwp' ),
            );
        }

        // Allows only valid PHP date() format characters and some basic separators.
        if ( ! preg_match( '/^[gGhHisAa\-\.,:\/\s\\\\]+$/', $value ) ) {
            return new WP_Error(
                'invalid_time_format',
                __( 'Invalid time format. Use valid PHP time format characters like g:i a or H:i.', 'mainwp' ),
            );
        }

        // Check rendering works.
        $time = date_i18n( $value, time() );
        if ( false === $time || '' === $time ) {
            return new WP_Error(
                'invalid_time_format_render',
                __( 'Invalid or unsupported time format.', 'mainwp' ),
            );
        }

        return true;
    }

    /**
     * Sanitize timezone field.
     *
     * @param string $value Timezone.
     *
     * @return string|WP_Error
     */
    public function sanitize_timezone_field( $value ) {
        if ( null === $value || '' === $value ) {
            return '';
        }

        $raw = $this->sanitize_field( $value );

        // Check time UTC eg: "UTC±H", "UTC±H:MM" .
        if ( preg_match( '/^UTC(?:([+-])(0?\d|1[0-4])(?::(00|30))?)?$/i', $raw, $m ) ) {
            $sign    = ( '-' === $m[1] ) ? -1 : 1;
            $hours   = isset( $m[2] ) ? (int) $m[2] : 0;
            $minutes = isset( $m[3] ) ? (int) $m[3] : 0;

            $offset = $sign * ( $hours + ( $minutes / 60.0 ) );
            return round( $offset, 2 );
        }

        $all = timezone_identifiers_list();
        if ( in_array( $raw, $all, true ) ) {
            // Check timezone.
            try {
                $tz = new DateTimeZone( $raw );
            } catch ( Exception $e ) {
                return new WP_Error(
                    'invalid_timezone_string',
                    __( 'Invalid timezone. Use a valid format like "UTC+0" or its equivalent name, e.g. "Europe/London".', 'mainwp' ),
                );
            }

            // Convert to UTC offset.
            $ts = time();
            $dt = new DateTime( '@' . $ts );
            $dt->setTimezone( $tz );
            $offset_seconds = $tz->getOffset( $dt );
            $offset_hours   = $offset_seconds / 3600.0;

            return round( $offset_hours, 2 );
        }

        return new WP_Error(
            'invalid_timezone_string',
            __( 'Invalid timezone. Use a valid format like "UTC+0" or its equivalent name, e.g. "Europe/London".', 'mainwp' ),
        );
    }

    /**
     * Validate timezone.
     *
     * @param string          $value Timezone.
     * @param WP_REST_Request $request Request object.
     *
     * @return bool|WP_Error
     */
    public function validate_timezone( $value, $request ) {
        if ( null === $value || '' === $value ) {
            return true;
        }

        $value = $this->sanitize_field( $value );

        // eg: UTC, UTC+0, UTC+0:00, UTC+0:30 ,UTC-0:30 ...
        if ( preg_match( '/^UTC(?:([+-])(0?\d|1[0-4])(?::(00|30))?)?$/i', $value ) ) {
            return true;
        }

        // Get list timezone.
        $valid_timezones = timezone_identifiers_list();

        // Check timezone is valid.
        if ( in_array( $value, $valid_timezones, true ) ) {
            return true;
        }

        return new WP_Error(
            'invalid_timezone_string',
            __( 'Invalid timezone. Use a valid format like UTC+0 or its equivalent name, e.g. "Europe/London".', 'mainwp' ),
        );
    }

    /**
     * Sanitize mainwp widgets.
     *
     * @param array $value MainWP widgets.
     *
     * @return array|WP_Error
     */
    public function sanitize_mainwp_widgets( $value ) { // phpcs:ignore -- NOSONAR - complex.
        if ( ! is_array( $value ) ) {
            return new WP_Error(
                'invalid_mainwp_widgets_type',
                __( 'mainwp_widgets must be an object.', 'mainwp' )
            );
        }

        $existing = get_user_option( 'mainwp_settings_show_widgets', array() );
        if ( ! is_array( $existing ) ) {
            $existing = array();
        }

        // Sanitize field data.
        $values = array_map( 'sanitize_text_field', wp_unslash( $value ) );

        foreach ( $values as $k => $v ) {
            if ( array_key_exists( $k, $existing ) ) {
                $existing[ $k ] = ( 1 === (int) $v ) ? 1 : 0;
            }
        }

        return $existing;
    }

    /**
     * Validate mainwp widgets.
     *
     * @param array           $value MainWP widgets.
     * @param WP_REST_Request $request Request object.
     *
     * @return bool|WP_Error
     */
    public function validate_mainwp_widgets( $value, $request ) { // phpcs:ignore -- NOSONAR - complex.
        if ( empty( $value ) ) {
            return true;
        }

        if ( ! is_array( $value ) ) {
            return new WP_Error(
                'invalid_mainwp_widgets_type',
                __( 'mainwp_widgets must be an object.', 'mainwp' ),
            );
        }

        // Sanitize field data.
        $values = array_map( 'sanitize_text_field', wp_unslash( $value ) );

        $widgets = get_user_option( 'mainwp_settings_show_widgets', array() );
        $allowed = array_keys( $widgets );
        $keys    = array_keys( $values );

        $invalid_keys = array_values( array_diff( $keys, $allowed ) );

        if ( ! empty( $invalid_keys ) ) {
            return new WP_Error(
                'invalid_mainwp_widgets_keys',
                sprintf(
                    /* translators: 1: invalid keys, 2: allowed keys */
                    __( 'Invalid widget keys: %1$s. Allowed keys: %2$s.', 'mainwp' ),
                    esc_html( implode( ', ', $invalid_keys ) ),
                    esc_html( implode( ', ', $allowed ) )
                ),
            );
        }

        foreach ( $values as $k => $v ) {
            if ( is_array( $v ) || is_object( $v ) ) {
                return new WP_Error(
                    'invalid_mainwp_widgets_value_type',
                    sprintf( __( 'Value for "%s" must be 0 or 1.', 'mainwp' ), esc_html( $k ) ),
                );
            }
            $int = (int) $v;
            if ( 0 !== $int && 1 !== $int ) {
                return new WP_Error(
                    'invalid_mainwp_widgets_value',
                    sprintf( __( 'Invalid value for "%s". Allowed: 0 or 1.', 'mainwp' ), esc_html( $k ) ),
                );
            }
        }

        return true;
    }

    /**
     * Sanitize sync data.
     *
     * @param array $value Sync data.
     *
     * @return array|WP_Error
     */
    public function sanitize_sync_data( $value ) { // phpcs:ignore -- NOSONAR - complex.
        if ( ! is_array( $value ) ) {
            return new WP_Error(
                'invalid_sync_data_type',
                __( 'Sync data must be an object.', 'mainwp' )
            );
        }

        $values = array_map( 'sanitize_text_field', wp_unslash( $value ) );
        // Sync data.
        $sync_data          = get_option( 'mainwp_settings_sync_data', array() );
        $sync_data_settings = $this->safe_json_decode( $sync_data ?? '' );
        if ( ! is_array( $sync_data_settings ) ) {
            $sync_data_settings = array();
        }

        foreach ( $values as $k => $v ) {
            if ( array_key_exists( $k, $sync_data_settings ) ) {
                $sync_data_settings[ $k ] = ( 1 === (int) $v ) ? 1 : 0;
            }
        }

        return $sync_data_settings;
    }

    /**
     * Validate sync data.
     *
     * @param array           $value Sync data.
     * @param WP_REST_Request $request Request object.
     *
     * @return bool|WP_Error
     */
    public function validate_sync_data( $value, $request ) { // phpcs:ignore -- NOSONAR - complex.
        if ( empty( $value ) ) {
            return true;
        }

        if ( ! is_array( $value ) ) {
            return new WP_Error(
                'invalid_sync_data_type',
                __( 'Sync data must be an object.', 'mainwp' )
            );
        }
        // Sanitize field data.
        $values = array_map( 'sanitize_text_field', wp_unslash( $value ) );
        // Sync data.
        $sync_data          = get_option( 'mainwp_settings_sync_data', array() );
        $sync_data_settings = $this->safe_json_decode( $sync_data ?? '' );
        $allowed            = array_keys( $sync_data_settings );
        $keys               = array_keys( $values );
        $invalid_keys       = array_values( array_diff( $keys, $allowed ) );

        if ( ! empty( $invalid_keys ) ) {
            return new WP_Error(
                'invalid_sync_data_type',
                sprintf(
                    /* translators: 1: invalid keys, 2: allowed keys */
                    __( 'Invalid sync data keys: %1$s. Allowed keys: %2$s.', 'mainwp' ),
                    esc_html( implode( ', ', $invalid_keys ) ),
                    esc_html( implode( ', ', $allowed ) )
                ),
            );
        }

        foreach ( $values as $k => $v ) {
            if ( is_array( $v ) || is_object( $v ) ) {
                return new WP_Error(
                    'invalid_sync_data_type',
                    sprintf( __( 'Value for "%s" must be 0 or 1.', 'mainwp' ), esc_html( $k ) ),
                );
            }
            $int = (int) $v;
            if ( 0 !== $int && 1 !== $int ) {
                return new WP_Error(
                    'invalid_sync_data_type',
                    sprintf( __( 'Invalid value for "%s". Allowed: 0 or 1.', 'mainwp' ), esc_html( $k ) ),
                );
            }
        }

        return true;
    }

    /**
     * Sanitize time update.
     *
     * @param string $value Time.
     *
     * @return string|WP_Error
     */
    public function sanitize_time_update( $value ) {
        if ( empty( $value ) ) {
            return empty( $value );
        }

        $value = $this->sanitize_field( trim( (string) $value ) );
        if ( ! preg_match( '/^(?:[01]?\d|2[0-3]):[0-5]\d$/', $value ) ) {
            return new WP_Error(
                'invalid_time_update',
                __( 'Invalid time. Use whole hours in 24h format like 0:00, 9:00, 23:00, etc...', 'mainwp' ),
            );
        }

        return $value;
    }

    /**
     * Validate time update.
     *
     * @param string          $value Time.
     * @param WP_REST_Request $request Request object.
     *
     * @return bool|WP_Error
     */
    public function validate_time_update( $value, $request ) {
        if ( empty( $value ) ) {
            return true;
        }

        $value = $this->sanitize_field( trim( (string) $value ) );
        if ( preg_match( '/^(?:[01]?\d|2[0-3]):[0-5]\d$/', $value ) ) {
            return true;
        }

        return new WP_Error(
            'invalid_time_update',
            __( 'Invalid time. Use whole hours in 24h format like 0:00, 9:00, 23:00, etc...', 'mainwp' ),
        );
    }

    /**
     * Get allowed fields for settings.
     *
     * @return array
     */
    private function get_allowed_id_domain_field() {
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
     * Get request body.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return array|WP_Error
     */
    private function get_request_body( $request ) {
        // Get request body filed.
        $body = $request->get_body_params();
        if ( ! empty( $body ) && is_array( $body ) ) {
            return $body;
        }

        // Get request body from body.
        $body = $request->get_json_params();
        if ( ! empty( $body ) && is_array( $body ) ) {
            return $body;
        }

        // Get request body from raw.
        $body = $request->get_body();
        if ( ! empty( $body ) && is_string( $body ) ) {
            $body = json_decode( $body, true );
            if ( ! is_array( $body ) ) {
                return array();
            }
            return $body;
        }
        // Return error.
        return new WP_Error(
            'empty_body',
            __( 'Request body is empty.', 'mainwp' ),
        );
    }

    /**
     * Validate content type.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return bool|WP_Error
     */
    private function validate_content_type( $request ) {
        $content_type = (string) $request->get_header( 'content-type' );
        if ( 'application/json' !== $content_type ) {
            return new WP_Error(
                'invalid_content_type',
                __( 'Invalid content type. Expected application/json.', 'mainwp' ),
            );
        }
        return true;
    }

    /**
     * Make enum validator.
     *
     * @param array  $allowed Allowed values.
     * @param string $type    Type to coerce to.
     *
     * @return callable
     */
    private function make_enum_validator( array $allowed, string $type = 'int' ) {
        $allowed_norm = array_map( fn( $v ) => $this->coerce_type( $v, $type ), $allowed );

        return function ( $value, $request, $param ) use ( $allowed_norm, $type ) {  // phpcs:ignore -- NOSONAR
            if ( null === $value || '' === $value ) {
                return true;
            }

            $value = $this->sanitize_field( $value );
            $v     = $this->coerce_type( $value, $type );

            if ( in_array( $v, $allowed_norm, true ) ) {
                return true;
            }

            return new WP_Error(
                "invalid_{$param}",
                sprintf(
                    /* translators: 1: field name, 2: allowed list */
                    __( 'Invalid %1$s. Allowed values: %2$s.', 'mainwp' ),
                    esc_html( $param ),
                    esc_html( implode( ', ', $allowed_norm ) )
                ),
            );
        };
    }

    /**
     * Make enum sanitizer.
     *
     * @param array  $allowed Allowed values.
     * @param string $type    Type to coerce to.
     *
     * @return callable
     */
    private function make_enum_sanitizer( array $allowed, string $type = 'int' ) {
        $allowed_norm = array_map( fn( $v ) => $this->coerce_type( $v, $type ), $allowed );

        return function ( $value, $request, $param ) use ( $allowed_norm, $type ) {  // phpcs:ignore -- NOSONAR
            if ( null === $value || '' === $value ) {
                return $value;
            }
            $value = $this->sanitize_field( $value );
            $v     = $this->coerce_type( $value, $type );

            if ( in_array( $v, $allowed_norm, true ) ) {
                return $v;
            }

            return new WP_Error(
                "invalid_{$param}",
                sprintf(
                    __( 'Invalid %1$s. Allowed values: %2$s.', 'mainwp' ),
                    esc_html( $param ),
                    esc_html( implode( ', ', $allowed_norm ) )
                ),
            );
        };
    }

    /**
     * Coerce value to type.
     *
     * @param mixed  $value Value to coerce.
     * @param string $type  Type to coerce to.
     *
     * @return mixed
     */
    private static function coerce_type( $value, string $type ) {
        switch ( $type ) {
            case 'int':
                return (int) $value;
            case 'string':
            default:
                return (string) $value;
        }
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
     * Cast field value to type.
     *
     * @param mixed  $val  Value to cast.
     * @param string $type Type to cast to.
     *
     * @return mixed
     */
    protected function cast_field_value( $val, $type ) {
        switch ( $type ) {
            case 'int':
                $val = (int) $val;
                break;
            case 'bool':
                $val = (int) ( (bool) $val );
                break;
            case 'array':
                $val = is_array( $val ) ? $val : array();
                break;
            case 'string':
                $val = (string) $val;
                break;
            default:
                break;
        }
        return $val;
    }

    /**
     * Update option if present.
     *
     * @param array  $body        Request body.
     * @param string $key         Key to check.
     * @param string $option_name Option name.
     * @param string $cast        Type to cast to.
     *
     * @uses \MainWP\Dashboard\MainWP_Utility::update_option()
     *
     * @return bool|WP_Error
     */
    protected function update_option_if_present( $body, $key, $option_name, $cast = null ) {
        if ( array_key_exists( $key, $body ) ) {
            $val = $body[ $key ];
            $val = $this->cast_field_value( $val, $cast );
            MainWP_Utility::update_option( $option_name, $val );
            return true;
        }
        return new WP_Error(
            'missing_key',
            sprintf(
                __( 'Missing key %s.', 'mainwp' ),
                esc_html( $key )
            ),
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
    protected function format_date( int $date, $format = 'F j, Y' ) {
        return gmdate( $format, (int) $date );
    }

    /**
     * Sanitize field.
     *
     * @param mixed $value Value to sanitize.
     *
     * @return mixed
     */
    protected function sanitize_field( $value ) {
        if ( null === $value || '' === $value ) {
            return '';
        }
        return sanitize_text_field( wp_unslash( trim( $value ) ) );
    }

    /**
     * Safe json decode.
     *
     * @param mixed $json JSON to decode.
     *
     * @return array
     */
    protected function safe_json_decode( $json ) {
        if ( empty( $json ) ) {
            return array();
        }
        $arr = json_decode( (string) $json, true );
        return is_array( $arr ) ? $arr : array();
    }

    /**
     * Build api data.
     *
     * @uses Api_Backups_3rd_Party::get_cloudways_api_key()
     * @uses Api_Backups_3rd_Party::get_gridpane_api_key()
     * @uses Api_Backups_3rd_Party::get_vultr_api_key()
     * @uses Api_Backups_3rd_Party::get_linode_api_key()
     * @uses Api_Backups_3rd_Party::get_digitalocean_api_key()
     * @uses Api_Backups_3rd_Party::get_cpanel_api_key()
     * @uses Api_Backups_3rd_Party::get_plesk_api_key()
     * @uses Api_Backups_3rd_Party::get_kinsta_api_key()
     *
     * @return array<string,array{
     *   label: string,
     *   enabled_option: string,
     *   options?: array<string,string>,
     *   secrets?: array<string, callable>
     * }>
     */
    protected function get_api_backup_definitions() {
        return array(
            'cloudways'    => array(
                'label'          => 'Cloudways',
                'enabled_option' => 'mainwp_enable_cloudways_api',
                'options'        => array(
                    'account_email' => 'mainwp_cloudways_api_account_email',
                ),
                'secrets'        => array(
                    'api_key' => array( Api_Backups_3rd_Party::class, 'get_cloudways_api_key' ),
                ),
            ),
            'gridpane'     => array(
                'label'          => 'GridPane',
                'enabled_option' => 'mainwp_enable_gridpane_api',
                'secrets'        => array(
                    'api_key' => array( Api_Backups_3rd_Party::class, 'get_gridpane_api_key' ),
                ),
            ),
            'vultr'        => array(
                'label'          => 'Vultr',
                'enabled_option' => 'mainwp_enable_vultr_api',
                'secrets'        => array(
                    'api_key' => array( Api_Backups_3rd_Party::class, 'get_vultr_api_key' ),
                ),
            ),
            'linode'       => array(
                'label'          => 'Akamai (Linode)',
                'enabled_option' => 'mainwp_enable_linode_api',
                'secrets'        => array(
                    'api_key' => array( Api_Backups_3rd_Party::class, 'get_linode_api_key' ),
                ),
            ),
            'digitalocean' => array(
                'label'          => 'DigitalOcean',
                'enabled_option' => 'mainwp_enable_digitalocean_api',
                'secrets'        => array(
                    'api_key' => array( Api_Backups_3rd_Party::class, 'get_digitalocean_api_key' ),
                ),
            ),
            'cpanel'       => array(
                'label'          => 'cPanel (WP Toolkit)',
                'enabled_option' => 'mainwp_enable_cpanel_api',
                'options'        => array(
                    'url'       => 'mainwp_cpanel_url',
                    'site_path' => 'mainwp_cpanel_site_path',
                    'username'  => 'mainwp_cpanel_account_username',
                ),
            ),
            'plesk'        => array(
                'label'          => 'Plesk',
                'enabled_option' => 'mainwp_enable_plesk_api',
                'options'        => array(
                    'url' => 'mainwp_plesk_api_url',
                ),
                'secrets'        => array(
                    'api_key' => array( Api_Backups_3rd_Party::class, 'get_plesk_api_key' ),
                ),
            ),
            'kinsta'       => array(
                'label'          => 'Kinsta',
                'enabled_option' => 'mainwp_enable_kinsta_api',
                'options'        => array(
                    'account_email' => 'mainwp_kinsta_api_account_email',
                    'company_id'    => 'mainwp_kinsta_company_id',
                ),
                'secrets'        => array(
                    'api_key' => array( Api_Backups_3rd_Party::class, 'get_kinsta_api_key' ),
                ),
            ),
        );
    }

    /**
     * Get general settings data.
     *
     * @uses MainWP_Settings_Indicator::get_defaults_value()
     *
     * @return array
     */
    protected function get_general_settings_data() {
        // Default Setting.
        $default_setting = MainWP_Settings_Indicator::get_defaults_value();
        $show_widgets    = get_user_option( 'mainwp_settings_show_widgets', array() );

        // Map settings.
        return array(
            // General settings.
            'time_daily_update'               => get_option( 'mainwp_timeDailyUpdate', $default_setting['mainwp_timeDailyUpdate'] ),
            'frequency_daily_update'          => (int) get_option( 'mainwp_frequencyDailyUpdate', $default_setting['mainwp_frequencyDailyUpdate'] ),
            'date_format'                     => get_option( 'date_format', $default_setting['date_format'] ),
            'time_format'                     => get_option( 'time_format', $default_setting['time_format'] ),
            'timezone_string'                 => sprintf( 'UTC%s', get_option( 'gmt_offset', 0 ) ),
            'sidebar_position'                => get_user_option( ' mainwp_sidebarPosition', $default_setting['sidebar_position'] ),
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
            'dayinweek_auto_update'           => (int) get_option( 'mainwp_dayinweek_AutoUpdate', $default_setting['mainwp_dayinweek_AutoUpdate'] ),
            'dayinmonth_auto_update'          => (int) get_option( 'mainwp_dayinmonth_AutoUpdate', $default_setting['mainwp_dayinmonth_AutoUpdate'] ),
            'delay_autoupdate'                => (int) get_option( 'mainwp_delay_autoupdate', $default_setting['mainwp_delay_autoupdate'] ),
        );
    }

    /**
     * Get advanced settings data.
     *
     * @uses MainWP_Settings_Indicator::get_defaults_value()
     * @uses MainWP_System_Utility::get_open_ssl_sign_algos()
     *
     * @return array
     */
    protected function get_advanced_settings_data() {
        // Default Setting.
        $default_setting = MainWP_Settings_Indicator::get_defaults_value();
        // Sync data.
        $sync_data          = get_option( 'mainwp_settings_sync_data', array() );
        $sync_data_settings = $this->safe_json_decode( $sync_data ?? '' );
        // Signature algorithm.
        $sign_algs      = MainWP_System_Utility::get_open_ssl_sign_algos();
        $signature_algo = (int) get_option( 'mainwp_connect_signature_algo', $default_setting['mainwp_connect_signature_algo'] );

        return array(
            'mainwp_maximum_requests'                   => (int) get_option( 'mainwp_maximumRequests', $default_setting['mainwp_maximumRequests'] ),
            'mainwp_minimum_delay'                      => (int) get_option( 'mainwp_minimumDelay', $default_setting['mainwp_minimumDelay'] ),
            'mainwp_maximum_ip_requests'                => (int) get_option( 'mainwp_maximumIPRequests', $default_setting['mainwp_maximumIPRequests'] ),
            'mainwp_minimum_ip_delay'                   => (int) get_option( 'mainwp_minimumIPDelay', $default_setting['mainwp_minimumIPDelay'] ),
            'mainwp_chunksitesnumber'                   => (int) get_option( 'mainwp_chunksitesnumber', 10 ),
            'mainwp_chunksleepinterval'                 => (int) get_option( 'mainwp_chunksleepinterval', 5 ),
            'mainwp_maximum_sync_requests'              => (int) get_option( 'mainwp_maximumSyncRequests', $default_setting['mainwp_maximumSyncRequests'] ),
            'mainwp_maximum_install_update_requests'    => (int) get_option( 'mainwp_maximumInstallUpdateRequests', $default_setting['mainwp_maximum_install_update_requests'] ),
            'mainwp_maximum_uptime_monitoring_requests' => (int) get_option( 'mainwp_maximum_uptime_monitoring_requests', $default_setting['mainwp_maximum_uptime_monitoring_requests'] ),
            'mainwp_optimize'                           => (int) get_option( 'mainwp_optimize', $default_setting['mainwp_optimize'] ),
            'mainwp_wp_cron'                            => (int) get_option( 'mainwp_wp_cron', $default_setting['mainwp_wp_cron'] ),
            'mainwp_ssl_verify_certificate'             => (int) get_option( 'mainwp_sslVerifyCertificate', $default_setting['mainwp_ssl_verify_certificate'] ),
            'mainwp_verify_connection_method'           => (int) get_option( 'mainwp_verify_connection_method', $default_setting['mainwp_verify_connection_method'] ),
            'mainwp_connect_signature_algo'             => isset( $sign_algs[ $signature_algo ] ) ? $sign_algs[ $signature_algo ] : $default_setting['mainwp_connect_signature_algo'],
            'mainwp_force_use_ipv4'                     => (int) get_option( 'mainwp_forceUseIPv4', $default_setting['mainwp_forceUseIPv4'] ),
            'sync_data'                                 => $sync_data_settings,
        );
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
                    'context'     => array( 'individual_view' ),
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
                    'context'     => array( 'view', 'edit' ),
                ),
                'frequency_daily_update'                 => array(
                    'type'        => 'integer',
                    'description' => __( 'Frequency of daily updates.', 'mainwp' ),
                    'context'     => array( 'view', 'edit' ),
                ),
                'date_format'                            => array(
                    'type'        => 'string',
                    'description' => __( 'Date format.', 'mainwp' ),
                    'context'     => array( 'view', 'edit' ),
                ),
                'time_format'                            => array(
                    'type'        => 'string',
                    'description' => __( 'Time format.', 'mainwp' ),
                    'context'     => array( 'view', 'edit' ),
                ),
                'timezone_string'                        => array(
                    'type'        => 'string',
                    'description' => __( 'Timezone string.', 'mainwp' ),
                    'context'     => array( 'view', 'edit' ),
                ),
                'sidebar_position'                       => array(
                    'type'        => 'integer',
                    'description' => __( 'Plugin automatic daily update enabled.', 'mainwp' ),
                    'context'     => array( 'view', 'edit' ),
                ),
                'hide_update_everything'                 => array(
                    'type'        => 'integer',
                    'description' => __( 'Hide update everything.', 'mainwp' ),
                    'context'     => array( 'view', 'edit' ),
                ),
                'mainwp_widgets'                         => array(
                    'type'        => 'array',
                    'description' => __( 'MainWP widgets.', 'mainwp' ),
                    'context'     => array( 'view', 'edit' ),
                    'items'       => array(
                        'type' => 'integer',
                    ),
                ),
                'plugin_automatic_daily_update'          => array(
                    'type'        => 'integer',
                    'description' => __( 'Plugin automatic daily update enabled.', 'mainwp' ),
                    'context'     => array( 'view', 'edit' ),
                ),
                'theme_automatic_daily_update'           => array(
                    'type'        => 'integer',
                    'description' => __( 'Theme automatic daily update enabled.', 'mainwp' ),
                    'context'     => array( 'view', 'edit' ),
                ),
                'trans_automatic_daily_update'           => array(
                    'type'        => 'integer',
                    'description' => __( 'Translation automatic daily update enabled.', 'mainwp' ),
                    'context'     => array( 'view', 'edit' ),
                ),
                'automatic_daily_update'                 => array(
                    'type'        => 'integer',
                    'description' => __( 'Automatic daily update enabled.', 'mainwp' ),
                    'context'     => array( 'view', 'edit' ),
                ),
                'frequency_auto_update'                  => array(
                    'type'        => 'string',
                    'description' => __( 'Frequency of auto updates.', 'mainwp' ),
                    'context'     => array( 'view', 'edit' ),
                ),
                'time_auto_update'                       => array(
                    'type'        => 'string',
                    'description' => __( 'Time for auto updates.', 'mainwp' ),
                    'context'     => array( 'view', 'edit' ),
                ),
                'show_language_updates'                  => array(
                    'type'        => 'integer',
                    'description' => __( 'Show language updates.', 'mainwp' ),
                    'context'     => array( 'view', 'edit' ),
                ),
                'disable_update_confirmations'           => array(
                    'type'        => 'integer',
                    'description' => __( 'Disable update confirmations.', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'check_http_response'                    => array(
                    'type'        => 'integer',
                    'description' => __( 'Check HTTP response after update.', 'mainwp' ),
                    'context'     => array( 'view', 'edit' ),
                ),
                'check_http_response_method'             => array(
                    'type'        => 'string',
                    'description' => __( 'HTTP method for response check.', 'mainwp' ),
                    'context'     => array( 'view', 'edit' ),
                ),
                'backup_before_upgrade'                  => array(
                    'type'        => 'integer',
                    'description' => __( 'Backup before upgrade enabled.', 'mainwp' ),
                    'context'     => array( 'view', 'individual_view' ),
                ),
                'backup_before_upgrade_days'             => array(
                    'type'        => 'integer',
                    'description' => __( 'Days to check for backup before upgrade.', 'mainwp' ),
                    'context'     => array( 'view', 'edit', 'individual_view' ),
                ),
                'numberdays_outdate_plugin_theme'        => array(
                    'type'        => 'integer',
                    'description' => __( 'Number of days to consider plugin/theme as outdated.', 'mainwp' ),
                    'context'     => array( 'view', 'edit' ),
                ),
                'dayinweek_auto_update'                  => array(
                    'type'        => 'integer',
                    'description' => __( 'Day in week for auto updates.', 'mainwp' ),
                    'context'     => array( 'view', 'edit' ),
                ),
                'dayinmonth_auto_update'                 => array(
                    'type'        => 'integer',
                    'description' => __( 'Day in month for auto updates.', 'mainwp' ),
                    'context'     => array( 'view', 'edit' ),
                ),
                'delay_autoupdate'                       => array(
                    'type'        => 'integer',
                    'description' => __( 'Delay for auto updates.', 'mainwp' ),
                    'context'     => array( 'view', 'edit' ),
                ),
                'mainwp_maximum_requests'                => array(
                    'type'        => 'integer',
                    'description' => __( 'Maximum requests.', 'mainwp' ),
                    'context'     => array( 'advanced_view', 'advanced_edit' ),
                ),
                'mainwp_minimum_delay'                   => array(
                    'type'        => 'integer',
                    'description' => __( 'Minimum delay.', 'mainwp' ),
                    'context'     => array( 'advanced_view', 'advanced_edit' ),
                ),
                'mainwp_maximum_ip_requests'             => array(
                    'type'        => 'integer',
                    'description' => __( 'Maximum IP requests.', 'mainwp' ),
                    'context'     => array( 'advanced_view', 'advanced_edit' ),
                ),
                'mainwp_minimum_ip_delay'                => array(
                    'type'        => 'integer',
                    'description' => __( 'Minimum IP delay.', 'mainwp' ),
                    'context'     => array( 'advanced_view', 'advanced_edit' ),
                ),
                'mainwp_chunksitesnumber'                => array(
                    'type'        => 'integer',
                    'description' => __( 'Chunk sites number.', 'mainwp' ),
                    'context'     => array( 'advanced_view', 'advanced_edit' ),
                ),
                'mainwp_chunksleepinterval'              => array(
                    'type'        => 'integer',
                    'description' => __( 'Chunk sleep interval.', 'mainwp' ),
                    'context'     => array( 'advanced_view', 'advanced_edit' ),
                ),
                'mainwp_maximum_sync_requests'           => array(
                    'type'        => 'integer',
                    'description' => __( 'Maximum sync requests.', 'mainwp' ),
                    'context'     => array( 'advanced_view', 'advanced_edit' ),
                ),
                'mainwp_maximum_install_update_requests' => array(
                    'type'        => 'integer',
                    'description' => __( 'Maximum install and update requests.', 'mainwp' ),
                    'context'     => array( 'advanced_view', 'advanced_edit' ),
                ),
                'mainwp_maximum_uptime_monitoring_requests' => array(
                    'type'        => 'integer',
                    'description' => __( 'Maximum uptime monitoring requests.', 'mainwp' ),
                    'context'     => array( 'advanced_view', 'advanced_edit' ),
                ),
                'mainwp_optimize'                        => array(
                    'type'        => 'integer',
                    'description' => __( 'Optimize data loading.', 'mainwp' ),
                    'context'     => array( 'advanced_view', 'advanced_edit' ),
                ),
                'mainwp_wp_cron'                         => array(
                    'type'        => 'integer',
                    'description' => __( 'Use WP Cron.', 'mainwp' ),
                    'context'     => array( 'advanced_view', 'advanced_edit' ),
                ),
                'mainwp_ssl_verify_certificate'          => array(
                    'type'        => 'integer',
                    'description' => __( 'Verify SSL certificate.', 'mainwp' ),
                    'context'     => array( 'advanced_view', 'advanced_edit' ),
                ),
                'mainwp_verify_connection_method'        => array(
                    'type'        => 'integer',
                    'description' => __( 'Verify connection method.', 'mainwp' ),
                    'context'     => array( 'advanced_view', 'advanced_edit' ),
                ),
                'mainwp_connect_signature_algo'          => array(
                    'type'        => 'integer',
                    'description' => __( 'OpenSSL signature algorithm.', 'mainwp' ),
                    'context'     => array( 'advanced_view', 'advanced_edit' ),
                ),
                'mainwp_force_use_ipv4'                  => array(
                    'type'        => 'integer',
                    'description' => __( 'Force use IPv4.', 'mainwp' ),
                    'context'     => array( 'advanced_view', 'advanced_edit' ),
                ),
                'sync_data'                              => array(
                    'type'        => 'array',
                    'description' => __( 'Sync data.', 'mainwp' ),
                    'context'     => array( 'advanced_view', 'advanced_edit' ),
                    'items'       => array(
                        'type' => 'string',
                    ),
                ),
                'heading'                                => array(
                    'type'        => 'string',
                    'description' => __( 'Mail heading.', 'mainwp' ),
                    'context'     => array( 'email_view', 'email_edit' ),
                ),
                'type'                                   => array(
                    'type'        => 'string',
                    'description' => __( 'Type.', 'mainwp' ),
                    'context'     => array( 'email_view' ),
                ),
                'subject'                                => array(
                    'type'        => 'string',
                    'description' => __( 'Mail subject.', 'mainwp' ),
                    'context'     => array( 'email_view', 'email_edit' ),
                ),
                'recipients'                             => array(
                    'type'        => 'string',
                    'description' => __( 'Recipients.', 'mainwp' ),
                    'context'     => array( 'email_view', 'email_edit' ),
                ),
                'disable'                                => array(
                    'type'        => 'integer',
                    'description' => __( 'Disable.', 'mainwp' ),
                    'context'     => array( 'email_view', 'email_edit' ),
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
