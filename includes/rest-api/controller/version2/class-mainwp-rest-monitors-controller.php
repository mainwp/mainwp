<?php
/**
 * MainWP REST Controller
 *
 * This class handles the REST API
 *
 * @package MainWP\Dashboard
 */

use MainWP\Dashboard\MainWP_DB_Uptime_Monitoring;
use MainWP\Dashboard\MainWP_Uptime_Monitoring_Connect;
use MainWP\Dashboard\MainWP_Uptime_Monitoring_Handle;

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
     * Valid statuses map.
     *
     * @var array<string,int>
     */
    private const STATUS_MAP = array(
        'up'      => MainWP_Uptime_Monitoring_Connect::UP,
        'down'    => MainWP_Uptime_Monitoring_Connect::DOWN,
        'pending' => MainWP_Uptime_Monitoring_Connect::PENDING,
        'first'   => MainWP_Uptime_Monitoring_Connect::FIRST,
    );

    /**
     * Constructor.
     *
     * @uses MainWP_Uptime_Monitoring_Handle::get_global_monitoring_settings()
     */
    public function __construct() {
        add_filter( 'mainwp_rest_routes_monitors_controller_filter_allowed_fields_by_context', array( $this, 'hook_filter_allowed_fields_by_context' ), 10, 2 );

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
        $db     = MainWP_DB_Uptime_Monitoring::instance();
        $params = array(
            'exclude'  => ! empty( $args['exclude'] ) ? $args['exclude'] : '',
            'include'  => ! empty( $args['include'] ) ? $args['include'] : '',
            'status'   => ! empty( $args['status'] ) ? $args['status'] : '',
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
        $monitors = $db->get_monitors( $params ); // get monitors.
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
            $status_24h  = $db->get_uptime_monitor_stat_hourly_by( $monitor_id, 'last24', $last24_key );
            $last24_data = $this->get_uptime_24h_data( $status_24h, $last24_key );

            // Get 7d and 30d incidents data.
            $incidents_7d  = (int) $db->count_site_incidents_stats( $monitor_id, $one_week_ago, $today );
            $incidents_30d = (int) $db->count_site_incidents_stats( $monitor_id, $one_month_ago, $today );

            // Get monitor type & check frequency.
            $type         = $this->get_apply_setting( 'type', $monitor->type ?? '', 'useglobal', 'http' );
            $interval_min = (int) $this->get_apply_setting( 'interval', (int) ( $monitor->interval ?? 0 ), -1, 60 );

            $record = array(
                'monitor_id'           => $monitor_id,
                'wpid'                 => $wpid,
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
                'last_check_at'        => ! empty( $monitor->lasttime_check ) ? gmdate( 'Y-m-d H:i:s', (int) $monitor->lasttime_check ) : '',
                'status_code'          => ! empty( $monitor->last_http_code ) ? (int) $monitor->last_http_code : '',
                'status'               => $this->uptime_status( $monitor->last_status ?? null ),
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
    public function get_basic_monitors( $request ) { // phpcs:ignore -- NOSONAR - complex.
        $args   = $this->prepare_objects_query( $request );
        $db     = MainWP_DB_Uptime_Monitoring::instance();
        $params = array(
            'exclude'  => ! empty( $args['exclude'] ) ? $args['exclude'] : '',
            'include'  => ! empty( $args['include'] ) ? $args['include'] : '',
            'status'   => ! empty( $args['status'] ) ? $args['status'] : '',
            'search'   => ! empty( $args['s'] ) ? $args['s'] : '',
            'page'     => ! empty( $args['paged'] ) ? (int) $args['paged'] : 1,
            'per_page' => ! empty( $args['items_per_page'] ) ? (int) $args['items_per_page'] : 20,
        );

        // Get data from uptime monitoring DB.
        $monitors = $db->get_monitors( $params ); // get monitors.
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
                    'id'     => $monitor->monitor_id,
                    'url'    => $monitor->url ?? '',
                    'status' => $this->uptime_status( $monitor->last_status ?? null ),
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
     * Count all Conitors.
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @uses MainWP_DB_Uptime_Monitoring::instance()->get_monitors()
     * @return WP_Error|WP_REST_Response
     */
    public function count_monitors( $request ) {
        $args = $this->prepare_objects_query( $request );

        $params = array(
            'exclude' => isset( $args['exclude'] ) && ! empty( $args['exclude'] ) ? $args['exclude'] : '',
            'include' => isset( $args['include'] ) && ! empty( $args['include'] ) ? $args['include'] : '',
            'status'  => isset( $args['status'] ) && ! empty( $args['status'] ) ? $args['status'] : '',
            'search'  => isset( $args['s'] ) && ! empty( $args['s'] ) ? $args['s'] : '',
        );

        // Get data from uptime monitoring DB.
        $monitors = MainWP_DB_Uptime_Monitoring::instance()->get_monitors( $params );
        $total    = is_array( $monitors ) ? count( $monitors ) : 0;

        return rest_ensure_response(
            array(
                'success' => 1,
                'total'   => $total,
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

        $db = MainWP_DB_Uptime_Monitoring::instance();

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
        $status_24h  = $db->get_uptime_monitor_stat_hourly_by( $monitor_id, 'last24', $last24_key );
        $last24_data = $this->get_uptime_24h_data( $status_24h, $last24_key );

        // Get 7d and 30d incidents data.
        $incidents_7d  = (int) $db->count_site_incidents_stats( $monitor_id, $one_week_ago, $today );
        $incidents_30d = (int) $db->count_site_incidents_stats( $monitor_id, $one_month_ago, $today );

        // Get full heartbeat data.
        $heartbeat_data = $db->get_uptime_monitoring_stats( $monitor_id, $one_month_ago, $today );

        // Get monitor type & check frequency.
        $type         = $this->get_apply_setting( 'type', $monitor->type ?? '', 'useglobal', 'http' );
        $interval_min = (int) $this->get_apply_setting( 'interval', (int) ( $monitor->interval ?? 0 ), -1, 60 );

        $record = array(
            'monitor_id'           => $monitor_id,
            'wpid'                 => $monitor->wpid,
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
            'status_code'          => ! empty( $monitor->last_http_code ) ? (int) $monitor->last_http_code : '',
            'status'               => $this->uptime_status( $monitor->last_status ?? null ),
        );

        return rest_ensure_response(
            array(
                'success' => 1,
                'data'    => $this->filter_response_data_by_allowed_fields( $record, 'view' ),
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
            'monitor_id' => $monitors->monitor_id,
            'url'        => $monitors->url ?? '',
            'status'     => $this->uptime_status( $monitors->last_status ?? null ),
        );

        return rest_ensure_response(
            array(
                'success' => 1,
                'data'    => $this->filter_response_data_by_allowed_fields( $data, 'simple_view' ),
            )
        );
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

        $db = MainWP_DB_Uptime_Monitoring::instance();
        // Get monitor by monitor id.
        if ( ctype_digit( $raw ) ) {
            $monitor_id = (int) $raw;
            return $db->get_monitor_by( false, 'monitor_id', $monitor_id );
        }

        // Get monitor by domain.
        $domain  = strtolower( rtrim( rawurldecode( $raw ), '/' ) );
        $website = $this->get_site_by( 'domain', $domain );
        if ( empty( $website ) ) {
            return false;
        }

        $site_id = (int) $website->id;
        return $db->get_monitor_by( $site_id, 'wpid', $site_id );
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
            'page'     => array(
                'required'          => false,
                'type'              => 'integer',
                'default'           => 1,
                'minimum'           => 1,
                'sanitize_callback' => 'absint',
                'description'       => __( 'Page number.', 'mainwp' ),
            ),
            'per_page' => array(
                'required'          => false,
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
                'minimum'           => 1,
                'default'           => 20,
                'maximum'           => 200,
                'description'       => __( 'Number of monitors per page.', 'mainwp' ),
            ),

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
     * Validate status parameter.
     *
     * @param mixed $param The parameter value.
     * @return bool
     */
    public function validate_status_param( $param ) {
        if ( is_string( $param ) ) {
            return array_key_exists( $param, self::STATUS_MAP );
        }

        if ( is_numeric( $param ) ) {
            $int = (int) $param;
            return in_array( $int, array_values( self::STATUS_MAP ), true );
        }
        return false;
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
     * Get the Tags schema, conforming to JSON Schema.
     *
     * @since  5.2
     * @return array
     */
    public function get_item_schema() {
        return array(
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'monitors',
            'type'       => 'object',
            'properties' => array(
                'id'                   => array(
                    'type'        => 'integer',
                    'description' => __( 'Monitor ID.', 'mainwp' ),
                    'context'     => array( 'view', 'simple_view' ),
                    'readonly'    => true,
                ),
                'url'                  => array(
                    'type'        => 'string',
                    'description' => __( 'Website URL.', 'mainwp' ),
                    'format'      => 'uri',
                    'context'     => array( 'view', 'simple_view' ),
                ),
                'type'                 => array(
                    'type'        => 'string',
                    'description' => __( 'Monitor type.', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'status'               => array(
                    'type'        => 'string',
                    'description' => __( 'Monitor Status.', 'mainwp' ),
                    'enum'        => array( 'up', 'down', 'pending', 'first' ),
                    'context'     => array( 'view', 'simple_view' ),
                ),
                'check_frequency'      => array(
                    'type'        => 'string',
                    'description' => __( 'Check Frequency.', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'last_check_at'        => array(
                    'type'        => array( 'string', 'null' ),
                    'format'      => 'date-time',
                    'description' => __( 'Check interval (e.g. 1m, 5m)', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'status_code'          => array(
                    'description' => 'Last HTTP status code if applicable',
                    'type'        => array( 'integer', 'null' ),
                    'context'     => array( 'view' ),
                ),
                'uptime_24h'           => array(
                    'type'        => 'array',
                    'description' => __( 'Uptime % for last 24h', 'mainwp' ),
                    'context'     => array( 'view' ),
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
                    'context'     => array( 'view' ),
                ),
                'uptime_ratio_30d'     => array(
                    'type'        => 'float',
                    'description' => __( 'Uptime % over last 30 days', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'incidents_count_7d'   => array(
                    'type'        => 'integer',
                    'description' => __( 'Incidents count last 7 days', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'incidents_count_30d'  => array(
                    'type'        => 'integer',
                    'description' => __( 'Incidents count last 30 days', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'response_time_avg_ms' => array(
                    'type'        => 'integer',
                    'description' => __( 'Average response time (ms)', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'response_time_min_ms' => array(
                    'type'        => 'integer',
                    'description' => __( 'Min response time (ms)', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'response_time_max_ms' => array(
                    'type'        => 'integer',
                    'description' => __( 'Max response time (ms)', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
                'heartbeats'           => array(
                    'type'        => 'array',
                    'description' => __( 'Heartbeats data for last 30 days', 'mainwp' ),
                    'context'     => array( 'view' ),
                ),
            ),
        );
    }
}
