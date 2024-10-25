<?php
/**
 * MainWP monitor site.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Uptime_Monitoring_Handle
 *
 * @package MainWP\Dashboard
 */
class MainWP_Uptime_Monitoring_Handle { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * The single instance of the class
     *
     * @var mixed Default null
     */
    protected static $instance = null;

    /**
     * Get instance.
     *
     *  @return static::singlton
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }


    /**
     * MainWP_Setup_Wizard constructor.
     *
     * Run each time the class is called.
     */
    public function __construct() {
        add_action( 'admin_init', array( $this, 'admin_init' ) );
        add_filter( 'mainwp_uptime_monitoring_get_reports_data', array( $this, 'hook_get_reports_data' ), 10, 4 );
    }

    /**
     * Admin init.
     */
    public function admin_init() {
        MainWP_Uptime_Monitoring_Edit::instance()->handle_save_settings();
        MainWP_Post_Handler::instance()->add_action( 'mainwp_uptime_monitoring_remove_monitor', array( &$this, 'ajax_remove_monitor' ) );
        MainWP_Post_Handler::instance()->add_action( 'mainwp_uptime_monitoring_get_response_times', array( &$this, 'ajax_get_response_times' ) );

        $pages = array( 'managesites' );

        if ( isset( $_GET['page'] ) && in_array( wp_unslash( $_GET['page'] ), $pages, true ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
            add_filter(
                'mainwp_admin_enqueue_scripts',
                function ( $scripts ) {
                    if ( is_array( $scripts ) ) {
                        $scripts['apexcharts'] = true;
                    }
                    return $scripts;
                }
            );
        }
    }


    /**
     * get_default_monitoring_settings
     *
     * @param  bool $individual
     * @return array
     */
    public static function get_default_monitoring_settings( $individual ) {
        $default = array(
            'monitor_id'          => 0,
            'wpid'                => 0,
            'type'                => 'useglobal',
            'active'              => 2, // use global setting default.
            'keyword'             => '',
            'interval'            => -1, // use global setting default.
            'maxretries'          => -1, // use global setting default.
            'up_statuscodes_json' => 'useglobal', // default.
            'suburl'              => '',
            'method'              => 'useglobal',
            'timeout'             => -1, // use global setting default.
        );
        if ( ! $individual ) {
            // global defaults.
            $default['up_statuscodes_json'] = '';
            $default['active']              = 1;
            $default['type']                = 'http';
            $default['maxretries']          = 1;
            $default['method']              = 'get';
            $default['timeout']             = 60; // seconds.
            $default['interval']            = 60; // mins.
            unset( $default['suburl'] );
        }
        return $default;
    }


    /**
     * ajax_remove_monitor
     *
     * @return void
     */
    public function ajax_remove_monitor() {

        mainwp_secure_request( 'mainwp_uptime_monitoring_remove_monitor' );

        $monitor_id = isset( $_POST['moid'] ) ? intval( $_POST['moid'] ) : 0;

        if ( ! empty( $monitor_id ) ) {
            $monitor = MainWP_DB_Uptime_Monitoring::instance()->get_monitor_by( false, 'monitor_id', $monitor_id );
        }

        if ( empty( $monitor_id ) || empty( $monitor ) ) {
            die( wp_json_encode( array( 'error' => esc_html__( 'The monitor ID is invalid or could not be found. Please try again.', 'mainwp' ) ) ) );
        }

        $deleted = MainWP_DB_Uptime_Monitoring::instance()->delete_monitor( array( 'monitor_id' => $monitor_id ) );
        if ( $deleted ) {
            die( wp_json_encode( array( 'success' => 1 ) ) );
        }
        die( wp_json_encode( array( 'error' => esc_html__( 'Failed to delete monitor.', 'mainwp' ) ) ) );
    }


    /**
     * ajax_get_response_times
     *
     * @return void
     */
    public function ajax_get_response_times() {

        mainwp_secure_request( 'mainwp_uptime_monitoring_get_response_times' );

        $site_id = isset( $_POST['siteid'] ) ? intval( $_POST['siteid'] ) : 0;

        if ( empty( $site_id ) ) {
            die( wp_json_encode( array( 'error' => esc_html__( 'The Site ID is invalid or not found. Please try again.', 'mainwp' ) ) ) );
        }

        if ( empty( $_POST['dtsstart'] ) || empty( $_POST['dtsstop'] ) ) {
            die( wp_json_encode( array( 'error' => esc_html__( 'Start and end dates cannot be empty. Please try again.', 'mainwp' ) ) ) );
        }

        $params = array(
            'start' => $_POST['dtsstart'],
            'end'   => $_POST['dtsstop'],
        );

        if ( strtotime( $params['start'] ) > strtotime( $params['end'] ) ) {
            die( wp_json_encode( array( 'error' => esc_html__( 'The start date must be earlier than the end date. Please try again.', 'mainwp' ) ) ) );
        }

        $results = $this->get_site_response_time_per_days_stats( $site_id, $params );

        die(
            wp_json_encode(
                array(
                    'data'       => ! empty( $results['response_time_data_lists'] ) ? $results['response_time_data_lists'] : array(),
                    'data_stats' => ! empty( $results['resp_stats'] ) ? $results['resp_stats'] : array(),
                ),
            )
        );
    }


    /**
     * get_site_response_time_per_days_stats
     *
     * @param  int   $site_id
     * @param  array $params
     * @return array
     */
    public function get_site_response_time_per_days_stats( $site_id, $params = array() ) {
        $results = MainWP_DB_Uptime_Monitoring::instance()->get_site_response_time_days_stats( $site_id, $params );

        if ( ! is_array( $results ) ) {
            $results = array();
        }

        $data = array();
        if ( ! empty( $results ) && ! empty( $results['data'] ) ) {

            $start = strtotime( $results['start'] );
            $end   = strtotime( $results['end'] );

            $data_list = array();

            $step = $start;

            while ( $step < $end ) {
                $_dt               = gmdate( 'Y-m-d', $step );
                $data_list[ $_dt ] = array(
                    'date'  => $_dt,
                    'value' => 0,
                );
                $step             += DAY_IN_SECONDS;
            }

            foreach ( $results['data'] as $resp ) {
                if ( ! isset( $resp['resp_date'] ) ) {
                    continue;
                }
                $data_list[ $resp['resp_date'] ] = array(
                    'date'  => $resp['resp_date'],
                    'value' => number_format( $resp['resp_ms'] / 1000, 2 ), // convert milliseconds to seconds.
                );
            }
            $data = array_values( $data_list );
        }
        $resp_times = ! empty( $results['resp_stats_data'] ) && is_array( $results['resp_stats_data'] ) ? $results['resp_stats_data'] : array();
        $times_sec  = array();

        if ( isset( $resp_times['avg_time_ms'] ) ) {
            $times_sec['avg_resp_time'] = number_format( $resp_times['avg_time_ms'] / 1000, 2 );  // convert milliseconds to seconds.
            $times_sec['avg_time_ms']   = $resp_times['avg_time_ms'];
        }

        if ( isset( $resp_times['min_time_ms'] ) ) {
            $times_sec['min_resp_time'] = number_format( $resp_times['min_time_ms'] / 1000, 2 );  // convert milliseconds to seconds.
            $times_sec['min_time_ms']   = $resp_times['min_time_ms'];
        }

        if ( isset( $resp_times['max_time_ms'] ) ) {
            $times_sec['max_resp_time'] = number_format( $resp_times['max_time_ms'] / 1000, 2 );  // convert milliseconds to seconds.
            $times_sec['max_time_ms']   = $resp_times['max_time_ms'];
        }

        return array(
            'response_time_data_lists' => $data,
            'resp_stats'               => $times_sec,
        );
    }


    /**
     * hook_get_reports_data
     *
     * @param  mixed $site_id
     * @param  mixed $start_date Y-m-d.
     * @param  mixed $end_date Y-m-d.
     * @return mixed
     */
    public function hook_get_reports_data( $site_id, $start_date = false, $end_date = false ) {

        if ( ! empty( $site_id ) ) {
            $primary_monitor = MainWP_DB_Uptime_Monitoring::instance()->get_monitor_by( $site_id, 'issub', 0 );
        }

        if ( empty( $site_id ) || empty( $primary_monitor ) ) {
            return array(
                'success' => 0,
                'error'   => 'Error: Invalid Site ID or Site not found.',
            );
        }

        $start_dt = ! empty( $start_date ) ? $start_date : gmdate( 'Y-m-d', time() - 7 * DAY_IN_SECONDS );
        $end_dt   = ! empty( $end_date ) ? $end_date : gmdate( 'Y-m-d', time() );

        $args = array(
            'start' => $start_dt,
            'end'   => $end_dt,
        );

        $results = $this->get_site_response_time_per_days_stats( $site_id, $args );

        if ( ! is_array( $results ) ) {
            $results = array();
        }

        $resp_stats = ! empty( $results['resp_stats'] ) && is_array( $results['resp_stats'] ) ? $results['resp_stats'] : array();

        $period_days = array(
            'uptimeratiosall' => 365, // Last 365 days.
            'uptimeratios7'   => 7,
            'uptimeratios15'  => 15,
            'uptimeratios30'  => 30,
            'uptimeratios45'  => 45,
            'uptimeratios60'  => 60,
        );

        $args['period_days'] = $period_days;

        $uptime_ratios = array();

        $uptime_ratios = MainWP_DB_Uptime_Monitoring::instance()->get_site_uptime_ratios_reports_data( $site_id, $args );

        if ( ! is_array( $uptime_ratios ) ) {
            $uptime_ratios = array();
        }

        $report_ratios = array();

        foreach ( $period_days as $period => $days ) {
            if ( isset( $uptime_ratios[ $period ] ) ) {
                $report_ratios[ $period ] = $uptime_ratios[ $period ];
            } else {
                $report_ratios[ $period ] = 'N/A';
            }
        }

        $current_status = MainWP_DB_Uptime_Monitoring::instance()->get_last_site_heartbeat( $site_id );

        $global_settings = get_option( 'mainwp_global_uptime_monitoring_settings', array() );
        // Data prepare for pro reports.
        $data = array(
            'success'                  => 1,
            'start_date'               => $start_dt,
            'end_date'                 => $end_dt,
            'avg_resp_time'            => isset( $resp_stats['avg_resp_time'] ) ? $resp_stats['avg_resp_time'] : 'N/A',
            'min_resp_time'            => isset( $resp_stats['min_resp_time'] ) ? $resp_stats['min_resp_time'] : 'N/A',
            'max_resp_time'            => isset( $resp_stats['max_resp_time'] ) ? $resp_stats['max_resp_time'] : 'N/A',

            'avg_time_ms'              => isset( $resp_stats['avg_time_ms'] ) ? $resp_stats['avg_time_ms'] : 'N/A',
            'min_time_ms'              => isset( $resp_stats['min_time_ms'] ) ? $resp_stats['min_time_ms'] : 'N/A',
            'max_time_ms'              => isset( $resp_stats['max_time_ms'] ) ? $resp_stats['max_time_ms'] : 'N/A',
            'incidents_data'           => MainWP_DB_Uptime_Monitoring::instance()->get_site_incidents_stats( $site_id, $args ),
            'uptime_ratios_data'       => $report_ratios,
            'uptime_events_data'       => MainWP_DB_Uptime_Monitoring::instance()->get_site_monitoring_events_stats( $site_id, $args ),
            'monitor'                  => array(
                'name'                 => $primary_monitor->name,
                'url'                  => $primary_monitor->url,
                'active'               => ! empty( $primary_monitor->active ) ? $primary_monitor->active : 'N/A',
                'current_status'       => $current_status ? $current_status->status : 'N/A',
                'mapping_status_codes' => MainWP_Uptime_Monitoring_Connect::get_mapping_status_code_names(),
                'type'                 => ! empty( $primary_monitor->type ) ? $primary_monitor->active : 'N/A',
                'interval'             => MainWP_Uptime_Monitoring_Connect::get_apply_setting( 'interval', (int) $primary_monitor->interval, $global_settings, -1, 60 ),
                'keyword'              => ! empty( $primary_monitor->keyword ) ? $primary_monitor->keyword : 'N/A',
                'http_code'            => ! empty( $primary_monitor->http_response_code ) ? $primary_monitor->http_response_code : 'N/A',
            ),
            'response_time_chart_data' => ! empty( $results['response_time_data_lists'] ) && is_array( $results['response_time_data_lists'] ) ? $results['response_time_data_lists'] : array(),
        );
        return $data;
    }
}
