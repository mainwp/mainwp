<?php
/**
 * MainWP REST Controller
 *
 * This class handles the REST API
 *
 * @package MainWP\Dashboard
 */

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Uptime_Monitoring;
use MainWP\Dashboard\MainWP_Uptime_Monitoring_Connect;
use MainWP\Dashboard\MainWP_Uptime_Monitoring_Handle;
use MainWP\Dashboard\MainWP_Utility;
use MainWP\Dashboard\MainWP_Monitoring_Handler;
use MainWP\Dashboard\MainWP_Uptime_Monitoring_Edit;

/**
 * Class MainWP_Rest_Monitors_Controller
 *
 * @package MainWP\Dashboard
 */
class MainWP_Rest_Monitors_Controller extends MainWP_REST_Controller { //phpcs:ignore -- NOSONAR - multi methods.

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
    protected $rest_base = 'monitors';

    /**
     * Global settings variable.
     *
     * @var null Instance variable.
     */
    private $global_settings = null;

    /**
     * Database instance.
     *
     * @var MainWP_DB_Uptime_Monitoring
     */
    private $db = null;

    /**
     * Valid statuses map.
     *
     * @var array<string,int>
     */
    private const STATUS_MAP = array(
        'up'      => MainWP_Uptime_Monitoring_Connect::UP,
        'down'    => MainWP_Uptime_Monitoring_Connect::DOWN,
        'pending' => MainWP_Uptime_Monitoring_Connect::PENDING,
        'first'   => MainWP_Uptime_Monitoring_Connect::FIRST,
        'paused'  => -1,
    );

    /**
     * Valid types map.
     *
     * @var array<string,string>
     */
    private const TYPE_MAP = array(
        'useglobal' => 'useglobal',
        'http'      => 'http',
        'ping'      => 'ping',
        'keyword'   => 'keyword',
    );

    /**
     * Valid methods map.
     *
     * @var array<string,string>
     */
    private const METHOD_MAP = array(
        'useglobal' => 'useglobal',
        'head'      => 'head',
        'get'       => 'get',
        'post'      => 'post',
        'push'      => 'push',
        'patch'     => 'patch',
        'delete'    => 'delete',
    );

    /**
     * Constructor.
     *
     * @uses MainWP_Uptime_Monitoring_Handle::get_global_monitoring_settings()
     */
    public function __construct() {
        add_filter( 'mainwp_rest_routes_monitors_controller_filter_allowed_fields_by_context', array( $this, 'hook_filter_allowed_fields_by_context' ), 10, 2 );
        add_filter( 'mainwp_rest_heartbeat_monitor_object_query', array( $this, 'heartbeat_monitor_custom_query_args' ), 10, 2 );

        $this->db              = MainWP_DB_Uptime_Monitoring::instance();
        $this->global_settings = MainWP_Uptime_Monitoring_Handle::get_global_monitoring_settings();
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
     * Get filter client.
     *
     * @param object $item item data.
     * @param string $context context.
     *
     * @return object item in context.
     */
    public function hook_filter_allowed_fields_by_context( $item, $context = 'view' ) {
        return $this->filter_response_data_by_allowed_fields( $item, $context );
    }

    /**
     * Add custom query args.
     *
     * @param array           $args    Query args.
     * @param WP_REST_Request $request Request object.
     *
     * @return array
     */
    public function heartbeat_monitor_custom_query_args( $args, $request ) {
        // Add custom args for heartbeat endpoint.
        if ( ! empty( $request['period'] ) ) {
            $args['period'] = $request['period'];
        }

        if ( ! empty( $request['since'] ) ) {
            $args['since'] = $request['since'];
        }

        return $args;
    }

    /**
     * Method register_routes()
     *
     * Creates the necessary endpoints for the api.
     * Note, for a request to be successful the URL query parameters consumer_key and consumer_secret need to be set and correct.
     */
	public function register_routes() { // phpcs:ignore -- NOSONAR - complex.
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_monitors' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_monitors_allowed_fields(),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/basic',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_basic_monitors' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_monitors_allowed_fields(),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/count',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'count_monitors' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_count_monitors_allowed_fields(),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>(\d+|[A-Za-z0-9-\.]*[A-Za-z0-9-]{1,63}\.[A-Za-z]{2,6}))',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_monitor' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_monitor_allowed_id_domain_field(),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>(\d+|[A-Za-z0-9-\.]*[A-Za-z0-9-]{1,63}\.[A-Za-z]{2,6}))/basic',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_basic_monitor' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_monitor_allowed_id_domain_field(),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>(\d+|[A-Za-z0-9-\.]*[A-Za-z0-9-]{1,63}\.[A-Za-z]{2,6}))/heartbeat',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_heartbeat_monitor' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_monitor_heartbeat_allowed_fields(),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>(\d+|[A-Za-z0-9-\.]*[A-Za-z0-9-]{1,63}\.[A-Za-z]{2,6}))/incidents',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_monitor_incidents' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_monitor_incidents_allowed_fields(),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>(\d+|[A-Za-z0-9-\.]*[A-Za-z0-9-]{1,63}\.[A-Za-z]{2,6}))/incidents/count',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_monitor_incidents_count' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_monitor_incidents_allowed_fields(),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>(\d+|[A-Za-z0-9-\.]*[A-Za-z0-9-]{1,63}\.[A-Za-z]{2,6}))/settings',
            array(
                array(
                    'methods'             => 'PUT, PATCH',
                    'callback'            => array( $this, 'update_individual_monitor_settings' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_individual_monitor_settings_allowed_fields(),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id_domain>(\d+|[A-Za-z0-9-\.]*[A-Za-z0-9-]{1,63}\.[A-Za-z]{2,6}))/check',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'check_monitor' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/settings',
            array(
                array(
                    'methods'             => 'PUT, PATCH',
                    'callback'            => array( $this, 'update_global_monitoring_settings' ),
                    'permission_callback' => array( $this, 'get_rest_permissions_check' ),
                    'args'                => $this->get_global_monitor_settings_allowed_fields(),
                ),
            )
        );
    }

    /**
     * Get all Monitors.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @uses MainWP_DB_Uptime_Monitoring::instance()->get_monitors()
     * @uses MainWP_DB_Uptime_Monitoring::instance()->get_uptime_monitor_stat_hourly_by()
     * @uses MainWP_Uptime_Monitoring_Handle::get_hourly_key_by_timestamp()
     * @uses MainWP_DB_Uptime_Monitoring::instance()->count_site_incidents_stats()
     *
     * @return WP_Error|WP_REST_Response
     */
	public function get_monitors( $request ) { // phpcs:ignore -- NOSONAR - complex.
        $args   = $this->prepare_objects_query( $request );
        $params = array(
            'exclude'  => ! empty( $args['exclude'] ) ? $args['exclude'] : '',
            'include'  => ! empty( $args['include'] ) ? $args['include'] : '',
            'status'   => isset( $args['status'] ) ? $args['status'] : '',
            'search'   => ! empty( $args['s'] ) ? $args['s'] : '',
            'page'     => ! empty( $args['paged'] ) ? (int) $args['paged'] : 1,
            'per_page' => ! empty( $args['items_per_page'] ) ? (int) $args['items_per_page'] : 20,
        );

        $now           = time();
        $today         = gmdate( 'Y-m-d', $now );
        $one_month_ago = gmdate( 'Y-m-d', $now - 30 * DAY_IN_SECONDS );
        $one_week_ago  = gmdate( 'Y-m-d', $now - 7 * DAY_IN_SECONDS );
        // Get last 24h hourly key.
        $last24_key = MainWP_Uptime_Monitoring_Handle::get_hourly_key_by_timestamp( $now - DAY_IN_SECONDS );

        // Get data from uptime monitoring DB.
        $monitors = $this->db->get_monitors( $params ); // get monitors.
        if ( empty( $monitors ) || ! is_array( $monitors ) ) {
            return rest_ensure_response(
                array(
                    'success' => 1,
                    'total'   => 0,
                    'data'    => array(),
                )
            );
        }

        $data = array();
        foreach ( $monitors as $monitor ) {
            $wpid       = (int) ( $monitor->wpid ?? 0 );
            $monitor_id = (int) ( $monitor->monitor_id ?? 0 );

            // Get reports data.
            $reports_data = apply_filters( 'mainwp_uptime_monitoring_get_reports_data', $wpid, $one_month_ago, $today );

            // Get 24h uptime data as array.
            $status_24h  = $this->db->get_uptime_monitor_stat_hourly_by( $monitor_id, 'last24', $last24_key );
            $last24_data = $this->get_uptime_24h_data( $status_24h, $last24_key );

            // Get 7d and 30d incidents data.
            $incidents_7d  = (int) $this->db->count_site_incidents_stats( $monitor_id, $one_week_ago, $today );
            $incidents_30d = (int) $this->db->count_site_incidents_stats( $monitor_id, $one_month_ago, $today );

            // Get monitor type & check frequency.
            $type         = $this->get_apply_setting( 'type', $monitor->type ?? '', 'useglobal', 'http' );
            $interval_min = (int) $this->get_apply_setting( 'interval', (int) ( $monitor->interval ?? 0 ), -1, 60 );

            $record = array(
                'id'                   => $monitor_id,
                'url'                  => $monitor->url ?? '',
                'uptime_ratio_7d'      => (float) ( $reports_data['uptime_ratios_data']['uptimeratios7'] ?? 0 ),
                'uptime_ratio_30d'     => (float) ( $reports_data['uptime_ratios_data']['uptimeratios30'] ?? 0 ),
                'uptime_24h'           => $last24_data,
                'incidents_count_7d'   => $incidents_7d,
                'incidents_count_30d'  => $incidents_30d,
                'type'                 => $type ? $type : 'http',
                'check_frequency'      => $interval_min > 0 ? ( $interval_min . 'm' ) : '',
                'response_time_avg_ms' => (int) ( $reports_data['avg_time_ms'] ?? 0 ),
                'response_time_min_ms' => (int) ( $reports_data['min_time_ms'] ?? 0 ),
                'response_time_max_ms' => (int) ( $reports_data['max_time_ms'] ?? 0 ),
                'last_check_at'        => ! empty( $monitor->lasttime_check ) ? gmdate( 'Y-m-d H:i:s', (int) $monitor->lasttime_check ) : '',
                'last_status_code'     => ! empty( $monitor->last_http_code ) ? (int) $monitor->last_http_code : '',
                'last_status'          => $this->uptime_status( $monitor->last_status ?? null ),
            );

            // Filter data by allowed fields.
            $data[] = $this->filter_response_data_by_allowed_fields( $record, 'view' );
        }

        return rest_ensure_response(
            array(
                'success' => 1,
                'total'   => count( $data ),
                'data'    => $data,
            )
        );
    }

    /**
     * Get basic all Monitors.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @uses MainWP_DB_Uptime_Monitoring::instance()->get_monitors()
     * @return WP_Error|WP_REST_Response
     */
    public function get_basic_monitors( $request ) {
        $args   = $this->prepare_objects_query( $request );
        $params = array(
            'exclude'  => ! empty( $args['exclude'] ) ? $args['exclude'] : '',
            'include'  => ! empty( $args['include'] ) ? $args['include'] : '',
            'status'   => isset( $args['status'] ) ? $args['status'] : '',
            'search'   => ! empty( $args['s'] ) ? $args['s'] : '',
            'page'     => ! empty( $args['paged'] ) ? (int) $args['paged'] : 1,
            'per_page' => ! empty( $args['items_per_page'] ) ? (int) $args['items_per_page'] : 20,
        );

        // Get data from uptime monitoring DB.
        $monitors = $this->db->get_monitors( $params ); // get monitors.
        if ( empty( $monitors ) || ! is_array( $monitors ) ) {
            return rest_ensure_response(
                array(
                    'success' => 1,
                    'total'   => 0,
                    'data'    => array(),
                )
            );
        }

        // Filter data by allowed fields.
        $data = array_map(
            function ( $monitor ) {
                $record = array(
                    'id'          => $monitor->monitor_id,
                    'url'         => $monitor->url ?? '',
                    'last_status' => $this->uptime_status( $monitor->last_status ?? null ),
                );
                return $this->filter_response_data_by_allowed_fields( $record, 'simple_view' );
            },
            $monitors
        );

        return rest_ensure_response(
            array(
                'success' => 1,
                'total'   => count( $data ),
                'data'    => $data,
            )
        );
    }

    /**
     * Count all Monitors.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @uses MainWP_DB_Uptime_Monitoring::instance()->get_monitors()
     * @return WP_Error|WP_REST_Response
     */
    public function count_monitors( $request ) {
        $args = $this->prepare_objects_query( $request );

        $params = array(
            'exclude' => ! empty( $args['exclude'] ) ? $args['exclude'] : '',
            'include' => ! empty( $args['include'] ) ? $args['include'] : '',
            'status'  => isset( $args['status'] ) ? $args['status'] : '',
            'search'  => ! empty( $args['s'] ) ? $args['s'] : '',
        );

        // Get data from uptime monitoring DB.
        $monitors = $this->db->get_monitors( $params );
        $total    = is_array( $monitors ) ? count( $monitors ) : 0;

        return rest_ensure_response(
            array(
                'success' => 1,
                'count'   => $total,
            )
        );
    }


    /**
     * Get Monitor.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @uses MainWP_Uptime_Monitoring_Handle::get_hourly_key_by_timestamp()
     * @uses MainWP_DB_Uptime_Monitoring::instance()->get_uptime_monitor_stat_hourly_by()
     * @uses MainWP_DB_Uptime_Monitoring::instance()->count_site_incidents_stats()
     * @uses MainWP_DB_Uptime_Monitoring::instance()->get_uptime_monitoring_stats()
     *
     * @return WP_Error|WP_REST_Response
     */
	public function get_monitor( $request ) { // phpcs:ignore -- NOSONAR - complex.
        $monitor = $this->get_request_item( $request );

        if ( empty( $monitor ) ) {
            return rest_ensure_response(
                array(
                    'success' => 0,
                    'message' => __( 'Monitor not found.', 'mainwp' ),
                )
            );
        }

        // Get reports data.
        $now           = time();
        $today         = gmdate( 'Y-m-d', $now );
        $one_month_ago = gmdate( 'Y-m-d', $now - 30 * DAY_IN_SECONDS );
        $one_week_ago  = gmdate( 'Y-m-d', $now - 7 * DAY_IN_SECONDS );
        // Get last 24h hourly key.
        $last24_key   = MainWP_Uptime_Monitoring_Handle::get_hourly_key_by_timestamp( $now - DAY_IN_SECONDS );
        $monitor_id   = (int) ( $monitor->monitor_id ?? 0 );
        $reports_data = apply_filters( 'mainwp_uptime_monitoring_get_reports_data', $monitor_id, $one_month_ago, $today );

        // Get 24h uptime data as array.
        $status_24h  = $this->db->get_uptime_monitor_stat_hourly_by( $monitor_id, 'last24', $last24_key );
        $last24_data = $this->get_uptime_24h_data( $status_24h, $last24_key );

        // Get 7d and 30d incidents data.
        $incidents_7d  = (int) $this->db->count_site_incidents_stats( $monitor_id, $one_week_ago, $today );
        $incidents_30d = (int) $this->db->count_site_incidents_stats( $monitor_id, $one_month_ago, $today );

        // Get full heartbeat data.
        $heartbeat_data = $this->db->get_uptime_monitoring_stats( $monitor_id, $one_month_ago, $today );

        // Get monitor type & check frequency.
        $type         = $this->get_apply_setting( 'type', $monitor->type ?? '', 'useglobal', 'http' );
        $interval_min = (int) $this->get_apply_setting( 'interval', (int) ( $monitor->interval ?? 0 ), -1, 60 );

        $record = array(
            'id'                   => $monitor_id,
            'name'                 => $monitor->name ?? '',
            'url'                  => $monitor->url ?? '',
            'uptime_ratio_7d'      => (float) ( $reports_data['uptime_ratios_data']['uptimeratios7'] ?? 0 ),
            'uptime_ratio_30d'     => (float) ( $reports_data['uptime_ratios_data']['uptimeratios30'] ?? 0 ),
            'uptime_24h'           => $last24_data,
            'incidents_count_7d'   => $incidents_7d,
            'incidents_count_30d'  => $incidents_30d,
            'type'                 => $type ? $type : 'http',
            'check_frequency'      => $interval_min > 0 ? ( $interval_min . 'm' ) : '',
            'response_time_avg_ms' => (int) ( $reports_data['avg_time_ms'] ?? 0 ),
            'response_time_min_ms' => (int) ( $reports_data['min_time_ms'] ?? 0 ),
            'response_time_max_ms' => (int) ( $reports_data['max_time_ms'] ?? 0 ),
            'heartbeats'           => $heartbeat_data ?? array(),
            'last_check_at'        => ! empty( $monitor->lasttime_check ) ? gmdate( 'Y-m-d H:i:s', (int) $monitor->lasttime_check ) : '',
            'last_status_code'     => ! empty( $monitor->last_http_code ) ? (int) $monitor->last_http_code : '',
            'last_status'          => $this->uptime_status( $monitor->last_status ?? null ),
        );

        return rest_ensure_response(
            array(
                'success' => 1,
                'data'    => $this->filter_response_data_by_allowed_fields( $record, 'monitor_view' ),
            )
        );
    }

    /**
     * Get Basic Monitor.
     *
     * @param mixed $request Full details about the request.
     * @return WP_Error|WP_REST_Response
     */
	public function get_basic_monitor( $request ) { // phpcs:ignore -- NOSONAR - complex.
        $monitors = $this->get_request_item( $request );

        if ( empty( $monitors ) ) {
            return rest_ensure_response(
                array(
                    'success' => 0,
                    'message' => __( 'Monitor not found.', 'mainwp' ),
                )
            );
        }

        // Filter data by allowed fields.
        $data = array(
            'id'     => $monitors->monitor_id,
            'url'    => $monitors->url ?? '',
            'status' => $this->uptime_status( $monitors->last_status ?? null ),
        );

        return rest_ensure_response(
            array(
                'success' => 1,
                'data'    => $this->filter_response_data_by_allowed_fields( $data, 'simple_view' ),
            )
        );
    }

    /**
     * Get Monitor Heartbeat.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @uses MainWP_DB_Uptime_Monitoring::instance()->get_heartbeat_count()
     * @uses MainWP_DB_Uptime_Monitoring::instance()->get_heartbeat_data_paginated()
     *
     * @return WP_Error|WP_REST_Response
     */
	public function get_heartbeat_monitor( $request ) { // phpcs:ignore -- NOSONAR - complex.
        $monitor = $this->get_request_item( $request );

        if ( empty( $monitor ) ) {
            return rest_ensure_response(
                array(
                    'success' => 0,
                    'message' => __( 'Monitor not found.', 'mainwp' ),
                )
            );
        }

        // Get params.
        $args   = $this->prepare_objects_query( $request, 'heartbeat_monitor' );
        $params = array(
            'limit'  => ! empty( $args['limit'] ) ? (int) $args['limit'] : 0,
            'period' => ! empty( $args['period'] ) ? $args['period'] : '',
            'status' => isset( $args['status'] ) ? $args['status'] : '',
            'since'  => ! empty( $args['since'] ) ? $args['since'] : '',
            'page'   => ! empty( $args['page'] ) ? (int) $args['page'] : 1,
        );

        // Parse period to date range.
        $date_range = $this->parse_period( $params['period'], $params['since'] );
        if ( is_wp_error( $date_range ) ) {
            return rest_ensure_response(
                array(
                    'success' => 0,
                    'message' => __( 'Invalid period format.', 'mainwp' ),
                )
            );
        }

        $monitor_id = (int) ( $monitor->monitor_id ?? 0 );
        // Get total count for pagination.
        $total_count = $this->db->get_heartbeat_count( $monitor_id, $date_range, $params['status'] );
        if ( empty( $total_count ) ) {
            return rest_ensure_response(
                array(
                    'success' => 0,
                    'message' => __( 'No heartbeats found.', 'mainwp' ),
                )
            );
        }

        // Calculate pagination.
        $per_page    = $params['limit'];
        $page        = $params['page'];
        $total_pages = $per_page > 0 ? ceil( $total_count / $per_page ) : 1;
        $offset      = ( $page - 1 ) * $per_page;

        // Get heartbeats with pagination..
        $heartbeats = $this->db->get_heartbeat_data_paginated(
            $monitor->monitor_id,
            $date_range,
            $params['status'],
            $per_page,
            $offset
        );

        if ( empty( $heartbeats ) || ! is_array( $heartbeats ) ) {
            return rest_ensure_response(
                array(
                    'success' => 0,
                    'message' => __( 'No heartbeats found.', 'mainwp' ),
                )
            );
        }

        $formatted_heartbeats = array();
        foreach ( $heartbeats as $heartbeat ) {
            $record                 = array(
                'heartbeat_id' => (int) $heartbeat->heartbeat_id,
                'monitor_id'   => (int) $heartbeat->monitor_id,
                'msg'          => ! empty( $heartbeat->msg ) ? $heartbeat->msg : '',
                'importance'   => (int) $heartbeat->importance,
                'status'       => (int) $heartbeat->status,
                'time'         => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $heartbeat->time ) ),
                'ping_ms'      => ! empty( $heartbeat->ping_ms ) ? (int) $heartbeat->ping_ms : null,
                'http_code'    => ! empty( $heartbeat->http_code ) ? (int) $heartbeat->http_code : null,
            );
            $formatted_heartbeats[] = $this->filter_response_data_by_allowed_fields( $record, 'heartbeat_view' );
        }

        return rest_ensure_response(
            array(
                'success' => 1,
                'data'    => $formatted_heartbeats,
                'meta'    => array(
                    'page'        => $page,
                    'per_page'    => $per_page,
                    'total'       => $total_count,
                    'total_pages' => $total_pages,
                ),
            )
        );
    }

    /**
     * Get Monitor Incidents.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @uses MainWP_DB_Uptime_Monitoring::instance()->get_heartbeat_data_for_incidents()
     *
     * @return WP_Error|WP_REST_Response
     */
	public function get_monitor_incidents( $request ) { // phpcs:ignore -- NOSONAR - complex.
        $monitor = $this->get_request_item( $request );

        if ( empty( $monitor ) ) {
            return rest_ensure_response(
                array(
                    'success' => 0,
                    'message' => __( 'Monitor not found.', 'mainwp' ),
                )
            );
        }
        // Get Params.
        $args   = $this->prepare_objects_query( $request );
        $page   = ! empty( $args['page'] ) ? (int) $args['page'] : 1;
        $limit  = ! empty( $args['items_per_page'] ) ? (int) $args['items_per_page'] : 20;
        $offset = ( $page > 1 ? ( $page - 1 ) * $limit : 0 );

        $monitor_id = isset( $monitor->monitor_id ) ? (int) $monitor->monitor_id : 0;
        if ( $monitor_id <= 0 ) {
            return rest_ensure_response(
                array(
                    'success' => 0,
                    'message' => __( 'Monitor not found.', 'mainwp' ),
                )
            );
        }

        // Get all heartbeat data for this monitor to process incidents.
        $heartbeats = $this->db->get_heartbeat_data_for_incidents( $monitor_id );

        if ( empty( $heartbeats ) || ! is_array( $heartbeats ) ) {
            return rest_ensure_response(
                array(
                    'success' => 0,
                    'message' => __( 'No heartbeats found.', 'mainwp' ),
                )
            );
        }

        // Process heartbeats to identify incidents.
        $incidents = $this->process_heartbeats_to_incidents( $heartbeats );

        if ( empty( $incidents ) ) {
            return rest_ensure_response(
                array(
                    'success' => 1,
                    'data'    => array(),
                )
            );
        }

        // Paginate incidents.
        $paginated = array_slice( $incidents, $offset, $limit );

        // Format incidents for response.
        $formatted = array();
        foreach ( $paginated as $incident ) {
            $start_ts = isset( $incident['started_at'] ) ? strtotime( $incident['started_at'] ) : null;
            $end_ts   = ! empty( $incident['ended_at'] ) ? strtotime( $incident['ended_at'] ) : null;

            $record = array(
                'started_at'   => $start_ts ? gmdate( 'Y-m-d\TH:i:s\Z', $start_ts ) : null,
                'ended_at'     => $end_ts ? gmdate( 'Y-m-d\TH:i:s\Z', $end_ts ) : null,
                'duration_sec' => isset( $incident['duration_sec'] ) ? (int) $incident['duration_sec'] : 0,
                'resolved'     => ! empty( $incident['resolved'] ),
                'down_count'   => isset( $incident['down_count'] ) ? (int) $incident['down_count'] : 0,
            );

            $formatted[] = $this->filter_response_data_by_allowed_fields( $record, 'incidents_view' );
        }

        return rest_ensure_response(
            array(
                'success' => 1,
                'data'    => $formatted,
            )
        );
    }

    /**
     * Get Monitor Incidents Count.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @uses MainWP_DB_Uptime_Monitoring::instance()->get_heartbeat_data_for_incidents()
     *
     * @return WP_Error|WP_REST_Response
     */
	public function get_monitor_incidents_count( $request ) { // phpcs:ignore -- NOSONAR - complex.
        $monitor = $this->get_request_item( $request );

        if ( empty( $monitor ) ) {
            return rest_ensure_response(
                array(
                    'success' => 0,
                    'message' => __( 'Monitor not found.', 'mainwp' ),
                )
            );
        }
        // Get Params.
        $args   = $this->prepare_objects_query( $request );
        $page   = ! empty( $args['page'] ) ? (int) $args['page'] : 1;
        $limit  = ! empty( $args['items_per_page'] ) ? (int) $args['items_per_page'] : 20;
        $offset = ( $page > 1 ? ( $page - 1 ) * $limit : 0 );

        $monitor_id = isset( $monitor->monitor_id ) ? (int) $monitor->monitor_id : 0;
        if ( $monitor_id <= 0 ) {
            return rest_ensure_response(
                array(
                    'success' => 0,
                    'message' => __( 'Monitor not found.', 'mainwp' ),
                )
            );
        }

        // Get all heartbeat data for this monitor to process incidents.
        $heartbeats = $this->db->get_heartbeat_data_for_incidents( $monitor_id );

        if ( empty( $heartbeats ) || ! is_array( $heartbeats ) ) {
            return rest_ensure_response(
                array(
                    'success' => 0,
                    'message' => __( 'No heartbeats found.', 'mainwp' ),
                )
            );
        }

        // Process heartbeats to identify incidents.
        $incidents = $this->process_heartbeats_to_incidents( $heartbeats );

        if ( empty( $incidents ) ) {
            return rest_ensure_response(
                array(
                    'success' => 1,
                    'count'   => 0,
                )
            );
        }

        // Paginate incidents.
        $paginated = array_slice( $incidents, $offset, $limit );

        // Format incidents for response.
        $down_count = 0;
        foreach ( $paginated as $incident ) {
            $down_count += isset( $incident['down_count'] ) ? (int) $incident['down_count'] : 0;
        }

        return rest_ensure_response(
            array(
                'success' => 1,
                'count'   => $down_count,
            )
        );
    }

    /**
     * Update global monitoring settings.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @uses MainWP_Uptime_Monitoring_Handle::update_uptime_global_settings()
     *
     * @return WP_Error|WP_REST_Response
     */
    public function update_global_monitoring_settings( $request ) {
        // Get request body.
        $body = $request->get_json_params();
        if ( empty( $body ) ) {
            return new WP_Error(
                'empty_body',
                __( 'Request body is empty.', 'mainwp' ),
                array( 'status' => 400 )
            );
        }

        // Check content type.
        $content_type = (string) $request->get_header( 'content-type' );
        if ( 'application/json' !== $content_type ) {
            return new WP_Error(
                'invalid_content_type',
                __( 'Invalid content type. Expected application/json.', 'mainwp' ),
            );
        }

        // Process global monitoring settings update.
        $result = $this->process_global_monitoring_settings_update( $body );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // Get updated settings.
        $current_settings = MainWP_Uptime_Monitoring_Handle::get_global_monitoring_settings();
        return rest_ensure_response(
            array(
                'success' => 1,
                'data'    => $current_settings,
            )
        );
    }

    /**
     * Update individual monitor settings (per-monitor API).
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_Error|WP_REST_Response
     */
    public function update_individual_monitor_settings( $request ) {
        $monitor = $this->get_request_item( $request );

        if ( empty( $monitor ) ) {
            return rest_ensure_response(
                array(
                    'success' => 0,
                    'message' => __( 'Monitor not found.', 'mainwp' ),
                )
            );
        }

        // Get request body.
        $body = $request->get_json_params();

        if ( empty( $body ) ) {
            return new WP_Error(
                'empty_body',
                __( 'Request body is empty.', 'mainwp' ),
            );
        }

        // Check content type.
        $content_type = (string) $request->get_header( 'content-type' );
        if ( 'application/json' !== $content_type ) {
            return new WP_Error(
                'invalid_content_type',
                __( 'Invalid content type. Expected application/json.', 'mainwp' ),
            );
        }

        // Validate request body against schema.
        $schema = $this->get_monitor_settings_schema(); // Get default schema.
        $valid  = rest_validate_value_from_schema( $body, $schema, 'body' );
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }

        // Sanitize request body.
        $data = rest_sanitize_value_from_schema( $body, $schema );

        // Process individual monitor settings update.
        $result = $this->process_individual_monitor_settings_update( $monitor, $data );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response(
            array(
                'success' => 1,
                'data'    => $result,
            )
        );
    }

    /**
     * Check monitor uptime.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @uses MainWP_DB::instance()->get_website_by_id()
     * @uses MainWP_Monitoring_Handler::handle_check_website()
     *
     * @return WP_Error|WP_REST_Response
     */
    public function check_monitor( $request ) {
        $monitor = $this->get_request_item( $request );

        if ( empty( $monitor ) ) {
            return rest_ensure_response(
                array(
                    'success' => 0,
                    'message' => __( 'Monitor not found.', 'mainwp' ),
                )
            );
        }

        // Get website.
        $website_id = isset( $monitor->wpid ) ? (int) $monitor->wpid : 0;
        $website    = MainWP_DB::instance()->get_website_by_id( intval( $website_id ) );
        if ( empty( $website ) ) {
            return rest_ensure_response(
                array(
                    'success' => 0,
                    'message' => __( 'Website not found.', 'mainwp' ),
                )
            );
        }

        // Check website.
        $error_msg = '';
        $result    = '';
        try {
            $result = MainWP_Monitoring_Handler::handle_check_website( $website );
        } catch ( \Exception $e ) {
            $error_msg = $e->getMessage();
        }

        if ( ! empty( $error_msg ) ) {
            return rest_ensure_response(
                array(
                    'success' => 0,
                    'message' => $error_msg,
                )
            );
        }

        $http_code = is_array( $result ) && isset( $result['httpCode'] ) ? $result['httpCode'] : 0;
        if ( is_array( $result ) && isset( $result['new_uptime_status'] ) ) {
            $check_result = $result['new_uptime_status'];
        }

        return rest_ensure_response(
            array(
                'success' => 1,
                'data'    => array(
                    'http_code'     => $http_code,
                    'uptime_status' => $check_result ? 'up' : 'down',
                ),
            )
        );
    }

    /**
     * Process global monitoring settings update with all business logic.
     *
     * @param array $settings Settings to update.
     *
     * @uses MainWP_Uptime_Monitoring_Handle::update_uptime_global_settings()
     * @uses MainWP_Uptime_Monitoring_Handle::get_global_monitoring_settings()
     *
     * @return array|WP_Error Updated global settings or error.
     */
	private function process_global_monitoring_settings_update( $settings ) {  // phpcs:ignore -- NOSONAR - complex.

        // Get current settings.
        $current_settings = MainWP_Uptime_Monitoring_Handle::get_global_monitoring_settings();
        $updated_settings = $current_settings;

        // Process enabled setting.
        if ( isset( $settings['active'] ) ) {
            $updated_settings['active'] = $settings['active'] ? 1 : 0;
        }

        // Process interval setting.
        if ( isset( $settings['interval'] ) ) {
            $updated_settings['interval'] = $settings['interval'];
        }

        // Process timeout setting.
        if ( isset( $settings['timeout'] ) ) {
            $updated_settings['timeout'] = $settings['timeout']; // Convert to seconds.
        }

        // Process retries setting.
        if ( isset( $settings['retries'] ) ) {
            $validation_result = $this->validate_retries( $settings['retries'] );
            if ( is_wp_error( $validation_result ) ) {
                return $validation_result;
            }
            $updated_settings['retries'] = (int) $settings['retries'];
        }

        // Process retries setting.
        if ( isset( $settings['maxretries'] ) ) {
            $validation_result = $this->validate_retries( $settings['maxretries'] );
            if ( is_wp_error( $validation_result ) ) {
                return $validation_result;
            }
            $updated_settings['maxretries'] = (int) $settings['maxretries'];
        }

        // Process type setting.
        if ( isset( $settings['type'] ) ) {
            if ( ! in_array( $settings['type'], array_keys( self::TYPE_MAP ), true ) ) {
                return array();
            }
            $updated_settings['type'] = $settings['type'];
        }

        // Process method setting.
        if ( isset( $settings['method'] ) ) {
            if ( ! in_array( $settings['method'], array_keys( self::METHOD_MAP ), true ) ) {
                return array();
            }
            $updated_settings['method'] = $settings['method'];
        }

        // Process HTTP-specific settings.
        if ( isset( $settings['expected_status'] ) ) {
            $updated_settings['up_status_codes'] = $settings['expected_status'];
        }

        // Process keyword setting.
        if ( isset( $settings['keyword'] ) ) {
            $updated_settings['keyword'] = $settings['keyword'];
        }

        // Apply filters to allow customization.
        $updated_settings = apply_filters( 'mainwp_uptime_monitoring_update_global_settings', $updated_settings );

        // Save the updated settings.
        MainWP_Uptime_Monitoring_Handle::update_uptime_global_settings( $updated_settings );

        return $settings;
    }

    /**
     * Process individual monitor settings update with all business logic.
     *
     * @param object $monitor Monitor object from database.
     * @param array  $settings Settings to update.
     *
     * @uses MainWP_DB_Uptime_Monitoring::update_wp_monitor()
     *
     * @return array|WP_Error Updated monitor settings or error.
     */
	private function process_individual_monitor_settings_update( $monitor, $settings ) { // phpcs:ignore -- NOSONAR - complexity.
        $monitor_id = (int) ( $monitor->monitor_id ?? 0 );

        $update_data = array( 'monitor_id' => $monitor_id );

        // Process active setting.
        if ( isset( $settings['active'] ) ) {
            $update_data['active'] = $settings['active'] ? 1 : 0;
        }

        // Process check_frequency setting.
        if ( isset( $settings['interval'] ) ) {
            $update_data['interval'] = $settings['interval'];
        }

        // Process timeout setting.
        if ( isset( $settings['timeout'] ) ) {
            $update_data['timeout'] = $settings['timeout'];
        }

        // Process retries setting.
        if ( isset( $settings['retries'] ) ) {
            $validation_result = $this->validate_retries( $settings['retries'] );
            if ( is_wp_error( $validation_result ) ) {
                return $validation_result;
            }
            $update_data['retries'] = (int) $settings['retries'];
        }

        // Process maxretries setting.
        if ( isset( $settings['maxretries'] ) ) {
            $validation_result = $this->validate_retries( $settings['maxretries'] );
            if ( is_wp_error( $validation_result ) ) {
                return $validation_result;
            }
            $update_data['maxretries'] = (int) $settings['maxretries'];
        }

        // Process HTTP-specific settings.
        if ( isset( $settings['expected_status'] ) ) {
            $update_data['expected_status'] = $settings['expected_status'];
        }

        // Process port settings.
        if ( isset( $settings['keyword'] ) ) {
            $update_data['keyword'] = $settings['keyword'];
        }

        // Process type setting.
        if ( isset( $settings['type'] ) ) {
            if ( ! in_array( $settings['type'], array_keys( self::TYPE_MAP ), true ) ) {
                return array();
            }
            $update_data['type'] = $settings['type'];
        }

        // Process method setting.
        if ( isset( $settings['method'] ) ) {
            if ( ! in_array( $settings['method'], array_keys( self::METHOD_MAP ), true ) ) {
                return array();
            }
            $update_data['method'] = $settings['method'];
        }

        // Apply filters to allow customization.
        $update_data = apply_filters( 'mainwp_uptime_monitoring_update_monitor_data', $update_data, $monitor_id );

        // Update the monitor.
        $result = $this->db->update_wp_monitor( $update_data );

        if ( false === $result ) {
            return new WP_Error(
                'update_failed',
                __( 'Failed to update monitor settings.', 'mainwp' ),
            );
        }

        return $update_data;
    }

    /**
     * Process heartbeats to identify incidents.
     *
     * An incident is a contiguous sequence of heartbeats where status = 0 (down)
     * bounded by up statuses.
     *
     * @param array $heartbeats Array of heartbeat objects with status and time.
     * @return array Array of incidents with started_at, ended_at, duration_sec, resolved, down_count.
     */
    private function process_heartbeats_to_incidents( $heartbeats ) {
        $incidents        = array();
        $current_incident = null;

        foreach ( $heartbeats as $heartbeat ) {
            $status = (int) $heartbeat->status;
            $time   = $heartbeat->time;

            if ( MainWP_Uptime_Monitoring_Connect::DOWN === $status ) {
                // Start a new incident or continue existing one.
                if ( null === $current_incident ) {
                    $current_incident = array(
                        'started_at'   => $time,
                        'ended_at'     => null,
                        'duration_sec' => null,
                        'resolved'     => false,
                        'down_count'   => 1,
                    );
                } else {
                    // Continue existing incident.
                    ++$current_incident['down_count'];
                }
            } elseif ( MainWP_Uptime_Monitoring_Connect::UP === $status ) {
                // End current incident if one exists.
                if ( null !== $current_incident ) {
                    $current_incident['ended_at'] = $time;
                    $current_incident['resolved'] = true;

                    // Calculate duration in seconds.
                    $start_timestamp                  = strtotime( $current_incident['started_at'] );
                    $end_timestamp                    = strtotime( $current_incident['ended_at'] );
                    $current_incident['duration_sec'] = $end_timestamp - $start_timestamp;

                    $incidents[]      = $current_incident;
                    $current_incident = null;
                }
            }
            // Note: PENDING and FIRST statuses don't affect incident boundaries.
        }

        // If there's an ongoing incident (not resolved), add it to the list.
        if ( null !== $current_incident ) {
            $incidents[] = $current_incident;
        }

        // Sort incidents by started_at descending (most recent first).
        usort(
            $incidents,
            function ( $a, $b ) {
                return strtotime( $b['started_at'] ) - strtotime( $a['started_at'] );
            }
        );

        return $incidents;
    }

    /**
     * Parse period to date range.
     *
     * @param string $period Period.
     * @param string $date Date.
     *
     * @return array|WP_Error Date range or WP_Error.
     */
	private function parse_period( $period, $date ) { // phpcs:ignore -- NOSONAR - long method.
        $now       = time();
        $day_start = '';

        // Format UTC time.
        $format_date = static function ( $day ) {
            return gmdate( 'Y-m-d H:i:s', (int) $day );
        };

        if ( ! empty( $date ) ) {
            $day_start = strtotime( $date );
        }

        switch ( $period ) {
            case '24h':
                $start = ! empty( $day_start ) ? $day_start : $now - DAY_IN_SECONDS;
                return array(
                    'start' => $format_date( $start ),
                    'end'   => $format_date( $now ),
                );
            case '7d':
                $start = ! empty( $day_start ) ? $day_start : $now - 7 * DAY_IN_SECONDS;
                return array(
                    'start' => $format_date( $start ),
                    'end'   => $format_date( $now ),
                );
            case '30d':
                $start = ! empty( $day_start ) ? $day_start : $now - 30 * DAY_IN_SECONDS;
                return array(
                    'start' => $format_date( $start ),
                    'end'   => $format_date( $now ),
                );
            default:
                // Try to parse as ISO8601 range (start/end).
                if ( strpos( $period, '/' ) !== false ) {
                    $parts = explode( '/', $period );
                    if ( count( $parts ) === 2 ) {
                        $start = ! empty( $day_start ) ? $day_start : strtotime( $parts[0] );
                        $end   = strtotime( $parts[1] );
                        if ( $start && $end && $start < $end ) {
                            return array(
                                'start' => $format_date( $start ),
                                'end'   => $format_date( $end ),
                            );
                        }
                    }
                }
                return new WP_Error( 'invalid_period', __( 'Invalid period format.', 'mainwp' ) );
        }
    }

    /**
     * Get uptime status.
     *
     * @param int $status Status.
     *
     * @return string Uptime status.
     */
    private function uptime_status( $status ) {
        switch ( $status ) {
            case 0:
                return 'down';
            case 1:
                return 'up';
            case 2:
                return 'pending';
            case 3:
                return 'first';
            default:
                return 'unknown';
        }
    }

    /**
     * Get monitor by.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @uses MainWP_DB_Uptime_Monitoring::instance()->get_monitor_by()
     *
     * @return WP_Error|Object Item.
     */
    private function get_request_item( $request ) {
        // Get id or domain raw value.
        $raw = (string) $request->get_param( 'id_domain' );
        $raw = trim( $raw );

        if ( empty( $raw ) ) {
            return false;
        }

        // Get monitor by monitor id.
        if ( ctype_digit( $raw ) ) {
            $monitor_id = (int) $raw;
            return $this->db->get_monitor_by( false, 'monitor_id', $monitor_id );
        }

        // Get monitor by domain.
        $domain  = strtolower( rtrim( rawurldecode( $raw ), '/' ) );
        $website = $this->get_site_by( 'domain', $domain );
        if ( empty( $website ) ) {
            return false;
        }

        $site_id = (int) $website->id;
        return $this->db->get_monitor_by( $site_id, 'wpid', $site_id );
    }

    /**
     * Get apply setting.
     *
     * @param string $name Name.
     * @param mixed  $indiv_setting Individual setting.
     * @param mixed  $global_value Global value.
     * @param mixed  $default_value Default value.
     *
     * @uses MainWP_Uptime_Monitoring_Connect::get_apply_setting()
     *
     * @return mixed
     */
    private function get_apply_setting( $name, $indiv_setting, $global_value, $default_value ) {
        return MainWP_Uptime_Monitoring_Connect::get_apply_setting(
            $name,
            $indiv_setting,
            $this->global_settings,
            $global_value,
            $default_value
        );
    }

    /**
     * Get uptime 24h data.
     *
     * @param array  $data data.
     * @param string $last24_starttime last24 start time.
     *
     * @uses MainWP_Uptime_Monitoring_Handle::get_hourly_key_by_timestamp()
     *
     * @return array uptime 24h data.
     */
    private function get_uptime_24h_data( $data, $last24_starttime ) {
        $uptime_24h  = array();
        $uptime_data = array();
        $hourly_key  = MainWP_Uptime_Monitoring_Handle::get_hourly_key_by_timestamp( $last24_starttime );

        foreach ( $data as $value ) {
            $uptime_data[ $value['timestamp'] ] = $value;
        }
        for ( $i = 0; $i < 24; $i++ ) {
            if ( isset( $uptime_data[ $hourly_key ] ) ) {
                $uptime_24h[ $i ] = array(
                    'timestamp' => $hourly_key,
                    'status'    => ! empty( $uptime_data[ $hourly_key ]['up'] ) ? 1 : 0,
                );
            } else {
                $uptime_24h[ $i ] = array(
                    'timestamp' => $hourly_key,
                    'status'    => 0,
                );
            }

            $hourly_key = MainWP_Uptime_Monitoring_Handle::get_hourly_key_by_timestamp( $hourly_key + HOUR_IN_SECONDS );
        }
        return $uptime_24h;
    }

    /**
     * Get monitor incidents allowed fields.
     *
     * @return array Allowed fields.
     */
    public function get_monitor_incidents_allowed_fields() {
        $args = array(
            'page'     => $this->field_page(),
            'per_page' => $this->field_per_page(),
        );
        return array_merge( $args, $this->get_monitor_allowed_id_domain_field() );
    }

    /**
     * Get allowed fields for monitoring settings.
     *
     * @return array Allowed fields.
     */
    private function get_global_monitor_settings_allowed_fields() {
        return array(
            'active'          => array(
                'required'          => false,
                'type'              => 'boolean',
                'description'       => __( 'Active or disable monitoring globally.', 'mainwp' ),
                'sanitize_callback' => 'rest_sanitize_boolean',
            ),
            'interval'        => array(
                'required'          => false,
                'type'              => 'string',
                'description'       => __( 'Default check frequency (e.g., "5m", "1h").', 'mainwp' ),
                'sanitize_callback' => array( $this, 'sanitize_interval_text_field' ),
                'validate_callback' => array( $this, 'settings_validate_interval_param' ),
            ),
            'timeout'         => array(
                'required'          => false,
                'type'              => 'string',
                'description'       => __( 'Request timeout in milliseconds.', 'mainwp' ),
                'sanitize_callback' => array( $this, 'sanitize_timeout_text_field' ),
                'validate_callback' => array( $this, 'settings_validate_timeout_param' ),
            ),
            'retries'         => array(
                'required'          => false,
                'type'              => 'integer',
                'description'       => __( 'Number of retries on failure.', 'mainwp' ),
                'sanitize_callback' => 'sanitize_text_field',
                'minimum'           => -1,
                'maximum'           => 10,
            ),
            'maxretries'      => array(
                'required'          => false,
                'type'              => 'integer',
                'description'       => __( 'Number of retries on failure.', 'mainwp' ),
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => array( $this, 'settings_validate_maxretries_param' ),
            ),
            'type'            => array(
                'required'          => false,
                'type'              => 'string',
                'description'       => __( 'Monitor type.', 'mainwp' ),
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => array( $this, 'settings_validate_type_param' ),
            ),
            'method'          => array(
                'required'          => false,
                'type'              => 'string',
                'description'       => __( 'Monitor method.', 'mainwp' ),
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => array( $this, 'settings_validate_method_param' ),
            ),
            'expected_status' => array(
                'required'          => false,
                'type'              => 'array',
                'description'       => __( 'Expected HTTP status codes.', 'mainwp' ),
                'sanitize_callback' => array( $this, 'sanitize_expected_status_text_field' ),
                'validate_callback' => array( $this, 'settings_validate_expected_status_param' ),
            ),
            'keyword'         => array(
                'required'          => false,
                'type'              => 'string',
                'description'       => __( 'Keyword to match.', 'mainwp' ),
                'sanitize_callback' => 'sanitize_text_field',
            ),
        );
    }

    /**
     * Get allowed fields for monitor settings.
     *
     * @return array Allowed fields.
     */
    private function get_individual_monitor_settings_allowed_fields() {
        return array_merge(
            $this->get_monitor_allowed_id_domain_field(),
            $this->get_global_monitor_settings_allowed_fields()
        );
    }

    /**
     * Get allowed fields for monitor heartbeat.
     *
     * @return array
     */
    private function get_monitor_heartbeat_allowed_fields() {
        $args = array(
            'limit'  => array(
                'required'          => false,
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
                'description'       => __( 'Limit number of heartbeats.', 'mainwp' ),
            ),
            'period' => array(
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => array( $this, 'validate_period_param' ),
                'default'           => '30d',
                'description'       => __( 'Time period (24h, 7d, 30d, or ISO8601 range like 2024-01-01T00:00:00Z/2024-01-02T00:00:00Z).', 'mainwp' ),
            ),
            'since'  => array(
                'required'          => false,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => array( $this, 'validate_datetime_param' ),
                'description'       => __( 'ISO8601 datetime, only return newer entries.', 'mainwp' ),
            ),
            'status' => $this->field_status(),
            'page'   => $this->field_page(),
        );
        return array_merge( $args, $this->get_monitor_allowed_id_domain_field() );
    }

    /**
     * Get allowed fields for monitors.
     *
     * @return array
     */
    private function get_monitor_allowed_id_domain_field() {
        return array(
            'id_domain' => array(
                'description'       => __( 'Site ID (number) or domain (string).', 'mainwp' ),
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
        );
    }

    /**
     * Get allowed fields for monitors.
     *
     * @return array
     */
    private function get_monitors_allowed_fields() {
        return array(
            'status'   => $this->field_status(),
            'search'   => $this->field_search(),
            'include'  => $this->field_include(),
            'exclude'  => $this->field_exclude(),
            'page'     => $this->field_page(),
            'per_page' => $this->field_per_page(),
        );
    }

    /**
     * Get allowed fields for count monitors.
     *
     * @return array
     */
    private function get_count_monitors_allowed_fields() {
        return array(
            'status'  => $this->field_status(),
            'include' => $this->field_include(),
            'exclude' => $this->field_exclude(),
            'search'  => $this->field_search(),
        );
    }

    /**
     * Sanitize timeout text field.
     *
     * @param string          $value Timeout value.
     * @param WP_REST_Request $request Request object.
     *
     * @uses MainWP_Uptime_Monitoring_Edit::get_timeout_values()
     *
     * @return string|WP_Error
     */
    public function sanitize_timeout_text_field( $value, $request ) {
        $value = sanitize_text_field( wp_unslash( $value ) );

        // Get is individual monitor.
        $individual   = $this->is_individual_monitor( $request );
        $value        = $individual ? $this->is_use_global_field( $value ) : $value;
        $all_timeouts = MainWP_Uptime_Monitoring_Edit::get_timeout_values( $individual );

        // Check timeout value exists.
        $key = array_search( $value, $all_timeouts, true );
        if ( false === $key ) {
            return new WP_Error(
                'invalid_timeout',
                __( 'Invalid timeout value.', 'mainwp' ),
            );
        }
        return (string) $key;
    }

    /**
     * Validate timeout parameter.
     *
     * @param string          $value Timeout value.
     * @param WP_REST_Request $request Request object.
     * @param string          $param Parameter name.
     *
     * @uses MainWP_Uptime_Monitoring_Edit::get_timeout_values()
     *
     * @return bool|WP_Error
     */
    public function settings_validate_timeout_param( $value, $request, $param ) {
        if ( empty( $value ) ) {
            return true; // Allow empty, will use default.
        }

        $value = sanitize_text_field( wp_unslash( $value ) );
        // Get is individual monitor.
        $individual   = $this->is_individual_monitor( $request );
        $value        = $individual ? $this->is_use_global_field( $value ) : $value;
        $all_timeouts = MainWP_Uptime_Monitoring_Edit::get_timeout_values( $individual );

        // Check value exists.
        $result = array_search( $value, $all_timeouts ); // check value exists.
        if ( false === $result ) {
            return new WP_Error(
                'invalid_timeout',
                __( 'Invalid timeout value.', 'mainwp' ),
            );
        }

        return true;
    }

    /**
     * Sanitize interval text field.
     *
     * @param string          $value Interval value.
     * @param WP_REST_Request $request Request object.
     *
     * @uses MainWP_Uptime_Monitoring_Edit::get_interval_values()
     *
     * @return string|WP_Error
     */
    public function sanitize_interval_text_field( $value, $request ) {
        $value = sanitize_text_field( wp_unslash( $value ) );

        // Get is individual monitor.
        $individual = $this->is_individual_monitor( $request );
        $value      = $individual ? $this->is_use_global_field( $value ) : $value;

        // Get interval values.
        $interval_values = MainWP_Uptime_Monitoring_Edit::get_interval_values( $individual );

        // Check value exists.
        $key = array_search( $value, $interval_values, true );
        if ( false === $key ) {
            return new WP_Error(
                'invalid_interval',
                __( 'Invalid interval value.', 'mainwp' ),
            );
        }

        return (string) $key;
    }

    /**
     * Validate frequency parameter.
     *
     * @param string          $value Frequency value.
     * @param WP_REST_Request $request Request object.
     * @param string          $param Parameter name.
     *
     * @uses MainWP_Uptime_Monitoring_Edit::get_interval_values()
     *
     * @return bool|WP_Error
     */
    public function settings_validate_interval_param( $value, $request, $param ) {
        if ( empty( $value ) ) {
            return true; // Allow empty, will use default.
        }

        $value = sanitize_text_field( wp_unslash( $value ) );

        // Get is individual monitor.
        $individual = $this->is_individual_monitor( $request );
        $value      = $individual ? $this->is_use_global_field( $value ) : $value;

        // Get interval values.
        $interval_values = MainWP_Uptime_Monitoring_Edit::get_interval_values( $individual );

        // Check value exists.
        $result = array_search( $value, $interval_values ); // check value exists.
        if ( false === $result ) {
            return new WP_Error(
                'invalid_interval',
                __( 'Invalid interval value.', 'mainwp' ),
            );
        }

        // Return the interval value.
        return true;
    }

    /**
     * Sanitize expected status text field.
     *
     * @param string          $value Expected status value.
     * @param WP_REST_Request $request Request object.
     *
     * @uses MainWP_Utility::get_http_codes()
     *
     * @return array
     */
    public function sanitize_expected_status_text_field( $value, $request ) {
        // Get value field.
        $value = sanitize_text_field( wp_unslash( $value ) );
        // Get individual monitor.
        $individual = $this->is_individual_monitor( $request );
        if ( $individual && 'useglobal' === $value ) {
            return $value;
        }

        // Get http error codes.
        $http_error_codes = array_keys( MainWP_Utility::get_http_codes() );
        $status_codes     = ! empty( $value ) ? wp_parse_id_list( $value ) : array();

        foreach ( $status_codes as $code ) {
            if ( ! in_array( $code, $http_error_codes ) ) {
                return new WP_Error(
                    'invalid_status_code',
                    sprintf( __( 'Invalid HTTP status code: %d', 'mainwp' ), $code ),
                    array( 'status' => 400 )
                );
            }
        }
        return $value;
    }

    /**
     * Validate expected status parameter.
     *
     * @param array           $value Expected status value.
     * @param WP_REST_Request $request Request object.
     * @param string          $param Parameter name.
     *
     * @uses MainWP_Utility::get_http_codes()
     *
     * @return bool|WP_Error
     */
    public function settings_validate_expected_status_param( $value, $request, $param ) {
        if ( empty( $value ) ) {
            return true; // Allow empty, will use default.
        }

        $value = sanitize_text_field( wp_unslash( $value ) );
        // Get individual monitor.
        $individual = $this->is_individual_monitor( $request );
        if ( $individual && 'useglobal' === $value ) {
            return true;
        }

        // Get http error codes.
        $http_error_codes = array_keys( MainWP_Utility::get_http_codes() );
        $status_codes     = ! empty( $value ) ? wp_parse_id_list( $value ) : array();
        foreach ( $status_codes as $code ) {
            if ( ! in_array( $code, $http_error_codes ) ) {
                return new WP_Error(
                    'invalid_status_code',
                    sprintf( __( 'Invalid HTTP status code: %d', 'mainwp' ), $code ),
                    array( 'status' => 400 )
                );
            }
        }

        return true;
    }

    /**
     * Validate maxretries parameter.
     *
     * @param int             $value Max retries value.
     * @param WP_REST_Request $request Request object.
     * @param string          $param Parameter name.
     * @return bool
     */
    public function settings_validate_maxretries_param( $value, $request, $param ) {
        if ( empty( $value ) ) {
            return true; // Allow empty, will use default.
        }
        if ( $value < -1 || $value > 1 ) {
            return new WP_Error(
                'invalid_maxretries',
                __( 'The maxretries value must be -1, 0, or 1.', 'mainwp' ),
            );
        }
        return true;
    }

    /**
     * Validate type parameter.
     *
     * @param string          $value Type value.
     * @param WP_REST_Request $request Request object.
     * @param string          $param Parameter name.
     * @return bool|WP_Error
     */
    public function settings_validate_type_param( $value, $request, $param ) {
        if ( empty( $value ) ) {
            return true; // Allow empty, will use default.
        }

        if ( ! in_array( $value, array_keys( self::TYPE_MAP ), true ) ) {
            return new WP_Error(
                'invalid_type',
                __( 'Invalid type value.', 'mainwp' ),
            );
        }
        return true;
    }

    /**
     * Validate method parameter.
     *
     * @param string          $value Method value.
     * @param WP_REST_Request $request Request object.
     * @param string          $param Parameter name.
     * @return bool|WP_Error
     */
    public function settings_validate_method_param( $value, $request, $param ) {
        if ( empty( $value ) ) {
            return true; // Allow empty, will use default.
        }

        if ( ! in_array( $value, array_keys( self::METHOD_MAP ), true ) ) {
            return new WP_Error(
                'invalid_method',
                __( 'Invalid method value.', 'mainwp' ),
            );
        }
        return true;
    }

    /**
     * Validate period parameter.
     *
     * @param string          $value Period value.
     * @param WP_REST_Request $request Request object.
     * @param string          $param Parameter name.
     * @return bool|WP_Error
     */
    public function validate_period_param( $value, $request, $param ) {
        if ( empty( $value ) ) {
            return true; // Allow empty, will use default.
        }

        // Check predefined periods.
        $allowed_periods = array( '24h', '7d', '30d' );
        if ( in_array( $value, $allowed_periods, true ) ) {
            return true;
        }

        // Check ISO8601 range format (start/end).
        if ( strpos( $value, '/' ) !== false ) {
            $parts = explode( '/', $value );
            if ( count( $parts ) !== 2 ) {
                return new WP_Error(
                    'invalid_period_format',
                    __( 'ISO8601 range must be in format: start/end (e.g., 2024-01-01T00:00:00Z/2024-01-02T00:00:00Z)', 'mainwp' )
                );
            }

            $start_time = $this->validate_iso8601_datetime( $parts[0] );
            $end_time   = $this->validate_iso8601_datetime( $parts[1] );

            if ( ! $start_time ) {
                return new WP_Error(
                    'invalid_start_datetime',
                    __( 'Invalid start datetime format. Use ISO8601 format (e.g., 2024-01-01T00:00:00Z)', 'mainwp' )
                );
            }

            if ( ! $end_time ) {
                return new WP_Error(
                    'invalid_end_datetime',
                    __( 'Invalid end datetime format. Use ISO8601 format (e.g., 2024-01-01T00:00:00Z)', 'mainwp' )
                );
            }

            if ( $start_time >= $end_time ) {
                return new WP_Error(
                    'invalid_date_range',
                    __( 'Start datetime must be earlier than end datetime.', 'mainwp' )
                );
            }

            return true;
        }

        return new WP_Error(
            'invalid_period',
            sprintf(
                __( 'Invalid period format. Allowed values: %s or ISO8601 range (start/end)', 'mainwp' ),
                implode( ', ', $allowed_periods )
            )
        );
    }

    /**
     * Validate datetime parameter.
     *
     * @param string          $value Datetime value.
     * @param WP_REST_Request $request Request object.
     * @param string          $param Parameter name.
     * @return bool|WP_Error
     */
    public function validate_datetime_param( $value, $request, $param ) {
        if ( empty( $value ) ) {
            return true; // Allow empty.
        }

        if ( ! $this->validate_iso8601_datetime( $value ) ) {
            return new WP_Error(
                'invalid_datetime',
                __( 'Invalid datetime format. Use ISO8601 format (e.g., 2024-01-01T00:00:00Z)', 'mainwp' )
            );
        }

        return true;
    }

    /**
     * Validate ISO8601 datetime format.
     *
     * @param string $datetime Datetime string.
     * @return int|false Unix timestamp or false if invalid.
     */
    private function validate_iso8601_datetime( $datetime ) {
        // Try to parse various ISO8601 formats.
        $formats = array(
            'Y-m-d\TH:i:s\Z',
            'Y-m-d\TH:i:sP',
            'Y-m-d\TH:i:s',
            'Y-m-d H:i:s',
            'Y-m-d',
        );

        foreach ( $formats as $format ) {
            $date = DateTime::createFromFormat( $format, $datetime );
            if ( $date && $date->format( $format ) === $datetime ) {
                return $date->getTimestamp();
            }
        }

        // Try strtotime as fallback.
        $timestamp = strtotime( $datetime );
        return false !== $timestamp ? $timestamp : false;
    }

    /**
     * Validate status parameter.
     *
     * @param mixed $param The parameter value.
     * @return bool|WP_Error
     */
    public function validate_status_param( $param ) {
        if ( is_string( $param ) ) {
            return array_key_exists( $param, self::STATUS_MAP );
        }

        if ( is_numeric( $param ) ) {
            $int = (int) $param;
            return in_array( $int, array_values( self::STATUS_MAP ), true );
        }

        return new WP_Error(
            'invalid_status',
            __( 'Invalid status value.', 'mainwp' ),
        );
    }

    /**
     * Validate retries value.
     *
     * @param mixed $retries Number of retries.
     * @return bool|WP_Error
     */
    private function validate_retries( $retries ) {
        $retries = (int) $retries;
        if ( $retries < -1 || $retries > 10 ) {
            return new WP_Error(
                'invalid_retries',
                __( 'Retries must be between 0 and 10.', 'mainwp' ),
            );
        }
        return true;
    }

    /**
     * Sanitize status parameter - convert string to numeric.
     *
     * @param mixed $param The parameter value.
     * @return int
     */
    public function sanitize_status_param( $param ) {
        if ( is_string( $param ) && isset( self::STATUS_MAP[ $param ] ) ) {
            return (int) self::STATUS_MAP[ $param ];
        }
        return (int) $param;
    }

    /**
     * Check if monitor is individual.
     *
     * @param WP_REST_Request $request Request object.
     * @return bool
     */
    private function is_individual_monitor( $request ) {
        $is_domain = $request->get_param( 'id_domain' );
        return ! empty( $is_domain ) ? true : false;
    }

    /**
     * Check if value is use global.
     *
     * @param mixed $value Value to check.
     * @return mixed
     */
    private function is_use_global_field( $value ) {
        if ( 'useglobal' === $value ) {
            return 'Use global setting';
        }
        return $value;
    }

    /**
     * Get status field.
     *
     * @return array
     */
    private function field_status() {
        return array(
            'required'          => false,
            'type'              => 'string',
            'description'       => __( 'Monitor status.', 'mainwp' ),
            'validate_callback' => array( $this, 'validate_status_param' ),
            'sanitize_callback' => array( $this, 'sanitize_status_param' ),
        );
    }

    /**
     * Get search field.
     *
     * @return array
     */
    private function field_search() {
        return array(
            'required'          => false,
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'description'       => __( 'Search string.', 'mainwp' ),
        );
    }

    /**
     * Get include field.
     *
     * @return array
     */
    private function field_include() {
        return array(
            'required'          => false,
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'description'       => __( 'Include monitor IDs.', 'mainwp' ),
        );
    }

    /**
     * Get exclude field.
     *
     * @return array
     */
    private function field_exclude() {
        return array(
            'required'          => false,
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'description'       => __( 'Exclude monitor IDs.', 'mainwp' ),
        );
    }

    /**
     * Get page field.
     *
     * @return array
     */
    private function field_page() {
        return array(
            'required'          => false,
            'type'              => 'integer',
            'default'           => 1,
            'minimum'           => 1,
            'sanitize_callback' => 'absint',
            'description'       => __( 'Page number.', 'mainwp' ),
        );
    }

    /**
     * Get per_page field.
     *
     * @return array
     */
    private function field_per_page() {
        return array(
            'required'          => false,
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'minimum'           => 1,
            'default'           => 20,
            'maximum'           => 200,
            'description'       => __( 'Number of monitors per page.', 'mainwp' ),
        );
    }

    /**
     * Get the monitor schema, conforming to JSON Schema.
     *
     * @since  5.2
     * @return array
     */
    private function get_monitor_settings_schema() {
        return array(
            '$schema'              => 'http://json-schema.org/draft-04/schema#',
            'title'                => 'monitor settings',
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => array(
                'active'          => array( 'type' => 'boolean' ),
                'type'            => array(
                    'type' => 'string',
                    'enum' => array_keys( self::TYPE_MAP ),
                ),
                'method'          => array(
                    'type' => 'string',
                    'enum' => array_keys( self::METHOD_MAP ),
                ),
                'retries'         => array( 'type' => 'integer' ),
                'maxretries'      => array( 'type' => 'integer' ),
                'timeout'         => array( 'type' => 'integer' ),
                'interval'        => array( 'type' => 'string' ),
                'expected_status' => array( 'type' => array( 'string' ) ),
                'keyword'         => array( 'type' => 'string' ),
            ),
        );
    }

    /**
     * Get the Tags schema, conforming to JSON Schema.
     *
     * @since  5.2
     * @return array
     */
	public function get_item_schema() {  // phpcs:ignore -- NOSONAR - long schema.
        return array(
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'monitors',
            'type'       => 'object',
            'properties' => array(
                'id'                   => array(
                    'type'        => 'integer',
                    'description' => __( 'Monitor ID.', 'mainwp' ),
                    'context'     => array( 'view', 'monitor_view', 'simple_view' ),
                    'readonly'    => true,
                ),
                'monitor_id'           => array(
                    'type'        => 'integer',
                    'readonly'    => true,
                    'description' => __( 'Monitor ID', 'mainwp' ),
                    'context'     => array( 'heartbeat_view' ),
                ),
                'heartbeat_id'         => array(
                    'type'        => 'integer',
                    'readonly'    => true,
                    'description' => __( 'Heartbeat ID', 'mainwp' ),
                    'context'     => array( 'heartbeat_view' ),
                ),
                'name'                 => array(
                    'type'        => 'string',
                    'description' => __( 'Website name.', 'mainwp' ),
                    'context'     => array( 'monitor_view' ),
                ),
                'url'                  => array(
                    'type'        => 'string',
                    'description' => __( 'Website URL.', 'mainwp' ),
                    'format'      => 'uri',
                    'context'     => array( 'view', 'monitor_view', 'simple_view' ),
                ),
                'type'                 => array(
                    'type'        => 'string',
                    'description' => __( 'Monitor type.', 'mainwp' ),
                    'context'     => array( 'view', 'monitor_view' ),
                ),
                'last_status'          => array(
                    'type'        => 'string',
                    'description' => __( 'Monitor Status.', 'mainwp' ),
                    'enum'        => array( 'up', 'down', 'pending', 'first' ),
                    'context'     => array( 'view', 'simple_view', 'monitor_view' ),
                ),
                'status'               => array(
                    'type'        => 'string',
                    'description' => __( 'Monitor Status.', 'mainwp' ),
                    'enum'        => array( 'up', 'down', 'pending', 'first' ),
                    'context'     => array( 'heartbeat_view' ),
                ),
                'check_frequency'      => array(
                    'type'        => 'string',
                    'description' => __( 'Check Frequency.', 'mainwp' ),
                    'context'     => array( 'view', 'monitor_view' ),
                ),
                'last_check_at'        => array(
                    'type'        => array( 'string', 'null' ),
                    'format'      => 'date-time',
                    'description' => __( 'Check interval (e.g. 1m, 5m)', 'mainwp' ),
                    'context'     => array( 'view', 'monitor_view' ),
                ),
                'last_status_code'     => array(
                    'description' => 'Last HTTP status code if applicable',
                    'type'        => array( 'integer', 'null' ),
                    'context'     => array( 'view', 'monitor_view' ),
                ),
                'uptime_24h'           => array(
                    'type'        => 'array',
                    'description' => __( 'Uptime % for last 24h', 'mainwp' ),
                    'context'     => array( 'view', 'monitor_view' ),
                    'items'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'timestamp' => array(
                                'type'        => 'integer',
                                'description' => __( 'Unix timestamp', 'mainwp' ),
                            ),
                            'status'    => array(
                                'type'        => 'integer',
                                'description' => __( 'Status code (1=up, 0=down)', 'mainwp' ),
                            ),
                        ),
                    ),
                ),
                'uptime_ratio_7d'      => array(
                    'type'        => 'float',
                    'description' => __( 'Uptime % over last 7 days', 'mainwp' ),
                    'context'     => array( 'view', 'monitor_view' ),
                ),
                'uptime_ratio_30d'     => array(
                    'type'        => 'float',
                    'description' => __( 'Uptime % over last 30 days', 'mainwp' ),
                    'context'     => array( 'view', 'monitor_view' ),
                ),
                'incidents_count_7d'   => array(
                    'type'        => 'integer',
                    'description' => __( 'Incidents count last 7 days', 'mainwp' ),
                    'context'     => array( 'view', 'monitor_view' ),
                ),
                'incidents_count_30d'  => array(
                    'type'        => 'integer',
                    'description' => __( 'Incidents count last 30 days', 'mainwp' ),
                    'context'     => array( 'view', 'monitor_view' ),
                ),
                'response_time_avg_ms' => array(
                    'type'        => 'integer',
                    'description' => __( 'Average response time (ms)', 'mainwp' ),
                    'context'     => array( 'view', 'monitor_view' ),
                ),
                'response_time_min_ms' => array(
                    'type'        => 'integer',
                    'description' => __( 'Min response time (ms)', 'mainwp' ),
                    'context'     => array( 'view', 'monitor_view' ),
                ),
                'response_time_max_ms' => array(
                    'type'        => 'integer',
                    'description' => __( 'Max response time (ms)', 'mainwp' ),
                    'context'     => array( 'view', 'monitor_view' ),
                ),
                'heartbeats'           => array(
                    'type'        => 'array',
                    'description' => __( 'Uptime % for last 24h', 'mainwp' ),
                    'context'     => array( 'monitor_view' ),
                    'items'       => array(
                        'type' => 'object',
                    ),
                ),
                'msg'                  => array(
                    'type'        => 'string',
                    'description' => __( 'Heartbeat message', 'mainwp' ),
                    'context'     => array( 'heartbeat_view' ),
                ),
                'importance'           => array(
                    'type'        => 'integer',
                    'description' => __( 'Importance status', 'mainwp' ),
                    'context'     => array( 'heartbeat_view' ),
                ),
                'time'                 => array(
                    'type'        => 'string',
                    'format'      => 'date-time',
                    'description' => __( 'Heartbeat time', 'mainwp' ),
                    'context'     => array( 'heartbeat_view' ),
                ),
                'ping_ms'              => array(
                    'type'        => 'integer',
                    'description' => __( 'Ping time (ms)', 'mainwp' ),
                    'context'     => array( 'heartbeat_view' ),
                ),
                'http_code'            => array(
                    'type'        => 'integer',
                    'description' => __( 'HTTP status code', 'mainwp' ),
                    'context'     => array( 'heartbeat_view' ),
                ),
                'started_at'           => array(
                    'type'        => 'string',
                    'format'      => 'date-time',
                    'description' => __( 'Timestamp of the first down in the run', 'mainwp' ),
                    'context'     => array( 'incidents_view' ),
                ),
                'ended_at'             => array(
                    'type'        => array( 'string', 'null' ),
                    'format'      => 'date-time',
                    'description' => __( 'Timestamp of the first subsequent up; null if ongoing', 'mainwp' ),
                    'context'     => array( 'incidents_view' ),
                ),
                'duration_sec'         => array(
                    'type'        => array( 'integer', 'null' ),
                    'description' => __( 'Duration in seconds; null if ongoing', 'mainwp' ),
                    'context'     => array( 'incidents_view' ),
                ),
                'resolved'             => array(
                    'type'        => 'boolean',
                    'description' => __( 'True if ended_at is present', 'mainwp' ),
                    'context'     => array( 'incidents_view' ),
                ),
                'down_count'           => array(
                    'type'        => 'integer',
                    'description' => __( 'Number of down heartbeats in the run', 'mainwp' ),
                    'context'     => array( 'incidents_view' ),
                ),
            ),
        );
    }
}
