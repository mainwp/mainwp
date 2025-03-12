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
        MainWP_Post_Handler::instance()->add_action( 'mainwp_uptime_monitoring_get_response_times', array( MainWP_Uptime_Monitoring_Site_Widget::instance(), 'ajax_get_response_times' ) );
        MainWP_Post_Handler::instance()->add_action( 'mainwp_uptime_monitoring_uptime_check', array( &$this, 'ajax_check_uptime' ) );

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
        $this->clear_outdated_hourly_uptime_stats();
    }


    /**
     * Method get_default_monitoring_settings
     *
     * @param  bool $individual individual.
     * @return array
     */
    public static function get_default_monitoring_settings( $individual ) {
        $default = array(
            'monitor_id'      => 0,
            'wpid'            => 0,
            'type'            => 'useglobal',
            'active'          => -1, // use global setting default.
            'keyword'         => '',
            'interval'        => -1, // use global setting default.
            'maxretries'      => -1, // use global setting default.
            'up_status_codes' => 'useglobal', // default.
            'suburl'          => '',
            'method'          => 'useglobal',
            'timeout'         => -1, // use global setting default.
        );
        if ( ! $individual ) {
            // global defaults.
            $default['up_status_codes'] = '';
            $default['active']          = 0;
            $default['type']            = 'http';
            $default['maxretries']      = 1;
            $default['method']          = 'head';
            $default['timeout']         = 60; // seconds.
            $default['interval']        = 60; // mins.
            unset( $default['suburl'] );
        }
        return $default;
    }


    /**
     * Method get_global_monitoring_settings.
     *
     * @return array
     */
    public static function get_global_monitoring_settings() {
        $global_settings = get_option( 'mainwp_global_uptime_monitoring_settings', array() );
        if ( empty( $global_settings ) || ! is_array( $global_settings ) ) {
            $global_settings = static::get_default_monitoring_settings( false );
        }
        return $global_settings;
    }


    /**
     * Method update_uptime_global_settings
     *
     * @param  array $settings settings.
     * @return void
     */
    public static function update_uptime_global_settings( $settings ) {
        // first enabled after new monitoring version update.
        if ( ! empty( $settings['first_enable_update'] ) && ! empty( $settings['active'] ) ) {
            unset( $settings['first_enable_update'] ); // do one time.
            MainWP_DB_Uptime_Monitoring::instance()->update_db_legacy_first_enable_monitoring_create_monitors();
        }
        $settings = apply_filters( 'mainwp_update_uptime_monitor_data', $settings );
        MainWP_Utility::update_option( 'mainwp_global_uptime_monitoring_settings', $settings );
    }


    /**
     * Method ajax_remove_monitor
     *
     * @return void
     */
    public function ajax_remove_monitor() {

        mainwp_secure_request( 'mainwp_uptime_monitoring_remove_monitor' );
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $monitor_id = isset( $_POST['moid'] ) ? intval( $_POST['moid'] ) : 0;

        if ( ! empty( $monitor_id ) ) {
            $monitor = MainWP_DB_Uptime_Monitoring::instance()->get_monitor_by( false, 'monitor_id', $monitor_id );
        }

        if ( empty( $monitor_id ) || empty( $monitor ) ) {
            die( wp_json_encode( array( 'error' => esc_html__( 'The monitor ID is invalid or could not be found. Please try again.', 'mainwp' ) ) ) );
        }
        // phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        $deleted = MainWP_DB_Uptime_Monitoring::instance()->delete_monitor( array( 'monitor_id' => $monitor_id ) );
        if ( $deleted ) {
            die( wp_json_encode( array( 'success' => 1 ) ) );
        }
        die( wp_json_encode( array( 'error' => esc_html__( 'Monitor could not be deleted. Please try again.', 'mainwp' ) ) ) );
    }


    /**
     * Method ajax_check_uptime()
     *
     * Check Child Sites.
     */
    public function ajax_check_uptime() {
        mainwp_secure_request( 'mainwp_uptime_monitoring_uptime_check' );

        $monitor_id = 0;
        $monitor    = false;

        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( isset( $_POST['mo_id'] ) ) {
            $monitor_id = intval( $_POST['mo_id'] );
        }

        if ( ! empty( $monitor_id ) ) {
            $monitor = MainWP_DB_Uptime_Monitoring::instance()->get_monitor_by( false, 'monitor_id', $monitor_id );
        }

        if ( empty( $monitor ) && ! empty( $_POST['wp_id'] ) ) {
            $site_id = intval( $_POST['wp_id'] );
            $monitor = MainWP_DB_Uptime_Monitoring::instance()->get_monitor_by( $site_id, 'issub', 0 ); // get primary monitor.
        }

        if ( empty( $monitor ) ) {
            die( wp_json_encode( array( 'error' => esc_html__( 'Monitor ID invalid or Monitor not found. Please try again.', 'mainwp' ) ) ) );
        }

        // phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        // return compatible uptime status here.
        $result = static::check_website_uptime_monitoring_status( $monitor );

        if ( is_array( $result ) ) {
            die( wp_json_encode( array( 'result' => 'success' ) ) );
        } else {
            die( wp_json_encode( array( 'error' => esc_html__( 'Request failed. Please, try again.', 'mainwp' ) ) ) );
        }
    }

    /**
     * Get site response time chart data
     *
     * @param  int   $site_id site id.
     * @param  array $params params.
     * @return array
     */
    public function get_site_response_time_chart_data( $site_id, $params = array() ) {

        $results_db = MainWP_DB_Uptime_Monitoring::instance()->get_db_site_response_time_stats_data( $site_id, $params );

        if ( ! is_array( $results_db ) ) {
            $results_db = array();
        }

        $data_output = array();
        if ( ! empty( $results_db ) && ! empty( $results_db['resp_time_list'] ) ) {
            $data_list = array();
            foreach ( $results_db['resp_time_list'] as $resp ) {
                if ( ! isset( $resp['resp_time'] ) ) {
                    continue;
                }
                $data_list[] = array(
                    'date'  => $resp['resp_time'], // exactly time.
                    'value' => number_format( $resp['resp_total_ms'] / 1000, 2 ), // convert milliseconds to seconds.
                );
            }

            $data_output = array_values( $data_list );
        }

        $resp_times = ! empty( $results_db['resp_stats_data'] ) && is_array( $results_db['resp_stats_data'] ) ? $results_db['resp_stats_data'] : array();
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
            'response_time_data_lists' => $data_output,
            'resp_stats'               => $times_sec,
            'start'                    => ! empty( $results_db['start'] ) ? $results_db['start'] : '',
            'end'                      => ! empty( $results_db['end'] ) ? $results_db['end'] : '',
        );
    }


    /**
     * Method hook_get_reports_data
     *
     * @param  mixed $site_id site id.
     * @param  mixed $start_date Y-m-d.
     * @param  mixed $end_date Y-m-d.
     * @param  array $params params.
     * @return mixed
     */
    public function hook_get_reports_data( $site_id, $start_date = false, $end_date = false, $params = array() ) {  //phpcs:ignore -- NOSONAR - complexity.

        if ( ! empty( $site_id ) ) {
            $primary_monitor = MainWP_DB_Uptime_Monitoring::instance()->get_monitor_by( $site_id, 'issub', 0 );
        }

        if ( empty( $site_id ) || empty( $primary_monitor ) ) {
            return array(
                'success' => 0,
                'error'   => 'Error: Invalid Site ID or Site not found.',
            );
        }

        if ( ! is_array( $params ) ) {
            $params = array();
        }

        $start_dt = strtotime( $start_date );
        $end_dt   = strtotime( $end_date );

        if ( empty( $start_dt ) || empty( $end_dt ) || ( $start_dt > $end_dt ) ) {
            return array(
                'success' => 0,
                'error'   => 'Error: Invalid start or end date. The start date must be earlier than the end date. Please try again.',
            );
        }

        $params = array_merge(
            $params,
            array(
                'start' => gmdate( 'Y-m-d', $start_dt ),
                'end'   => gmdate( 'Y-m-d', $end_dt ),
            )
        );

        $results_db = $this->get_site_response_time_chart_data( $site_id, $params );

        if ( ! is_array( $results_db ) ) {
            $results_db = array();
        }

        $resp_stats = ! empty( $results_db['resp_stats'] ) && is_array( $results_db['resp_stats'] ) ? $results_db['resp_stats'] : array();

        $period_days = array(
            'uptimeratiosall' => 365, // Last 365 days.
            'uptimeratios7'   => 7,
            'uptimeratios15'  => 15,
            'uptimeratios30'  => 30,
            'uptimeratios45'  => 45,
            'uptimeratios60'  => 60,
        );

        $params['period_days'] = $period_days;

        $uptime_ratios = array();

        $uptime_ratios = MainWP_DB_Uptime_Monitoring::instance()->get_site_uptime_ratios_reports_data( $site_id, $params );

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
        return array(
            'success'                  => 1,
            'avg_resp_time'            => isset( $resp_stats['avg_resp_time'] ) ? $resp_stats['avg_resp_time'] : 'N/A',
            'min_resp_time'            => isset( $resp_stats['min_resp_time'] ) ? $resp_stats['min_resp_time'] : 'N/A',
            'max_resp_time'            => isset( $resp_stats['max_resp_time'] ) ? $resp_stats['max_resp_time'] : 'N/A',
            'avg_time_ms'              => isset( $resp_stats['avg_time_ms'] ) ? $resp_stats['avg_time_ms'] : 'N/A',
            'min_time_ms'              => isset( $resp_stats['min_time_ms'] ) ? $resp_stats['min_time_ms'] : 'N/A',
            'max_time_ms'              => isset( $resp_stats['max_time_ms'] ) ? $resp_stats['max_time_ms'] : 'N/A',
            'incidents_data'           => MainWP_DB_Uptime_Monitoring::instance()->get_site_incidents_stats( $site_id, $params ),
            'uptime_ratios_data'       => $report_ratios,
            'uptime_events_data'       => MainWP_DB_Uptime_Monitoring::instance()->get_site_monitoring_events_stats( $site_id, $params ),
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
            'response_time_chart_data' => ! empty( $results_db['response_time_data_lists'] ) && is_array( $results_db['response_time_data_lists'] ) ? $results_db['response_time_data_lists'] : array(),
            'start_date'               => ! empty( $results_db['start'] ) ? $results_db['start'] : gmdate( 'Y-m-d 00:00:00', $start_dt ),
            'end_date'                 => ! empty( $results_db['end'] ) ? $results_db['end'] : gmdate( 'Y-m-d 23:59:59', $end_dt ),
        );
    }

    /**
     * Handle update website legacy HTTP status.
     *
     * @param object $website website.
     * @param array  $params params.
     *
     * @return mixed Check result.
     *
     * @uses \MainWP\Dashboard\MainWP_Connect::check_ignored_http_code()
     * @uses \MainWP\Dashboard\MainWP_DB::update_website_values()
     */
    public function handle_update_website_legacy_uptime_status( $website, $params ) {

        if ( ! is_array( $params ) || ! isset( $params['httpCode'] ) ) {
            return false;
        }

        $new_code   = isset( $params['httpCode'] ) ? (int) $params['httpCode'] : 0;
        $status     = isset( $params['new_uptime_status'] ) ? (int) $params['new_uptime_status'] : 0;
        $importance = isset( $params['importance'] ) ? $params['importance'] : 0;
        $time       = isset( $params['check_offline_time'] ) ? $params['check_offline_time'] : time();

        $noticed_value = $website->http_code_noticed;

        if ( empty( $noticed_value ) ) {
            $new_noticed = empty( $status ) && $importance ? 1 : 0;
        } else {
            $new_noticed = $noticed_value;
        }

        // Save last status.
        MainWP_DB::instance()->update_website_values(
            $website->id,
            array(
                'offline_check_result' => $status ? 1 : -1, // 1 - online, -1 offline.
                'offline_checks_last'  => $time,
                'http_response_code'   => $new_code,
                'http_code_noticed'    => $new_noticed,
            )
        );

        return true;
    }

    /**
     * Method to compatible with check_website_status()
     *
     * Check if the Website returns and http errors.
     *
     * @param object $monitor Child Site monitor.
     * @param array  $params Params.
     *
     * @return mixed False|try visit result.
     *
     * @uses \MainWP\Dashboard\MainWP_Utility::is_domain_valid()
     */
    public static function check_website_uptime_monitoring_status( $monitor, $params = array() ) {
        $glo_settings = static::get_global_monitoring_settings();
        return MainWP_Uptime_Monitoring_Connect::instance()->fetch_uptime_monitor( $monitor, $glo_settings, false, $params ); // Avoid updating compatible data.
    }


    /**
     * Method calc_and_save_site_uptime_stat_hourly_data
     *
     * @param  mixed $monitor_id monitor id.
     * @param  mixed $ping_data ping data.
     * @return void
     */
    public function calc_and_save_site_uptime_stat_hourly_data( $monitor_id, $ping_data ) {

        if ( ! $monitor_id || ! is_array( $ping_data ) || empty( $ping_data['time'] ) ) {
            return;
        }

        $hourly_key = static::get_hourly_key_by_timestamp( strtotime( $ping_data['time'] ) );

        $existed = MainWP_DB_Uptime_Monitoring::instance()->get_uptime_monitor_stat_hourly_by( $monitor_id, 'timestamp', $hourly_key );

        $update_stat_id = 0;

        if ( ! empty( $existed ) ) {
            $update_stat_id = $existed['stat_hourly_id'];
            $current_stat   = $existed;
        } else {
            $current_stat = array(
                'up'       => 0,
                'down'     => 0,
                'ping_avg' => 0,
                'ping_min' => 0,
                'ping_max' => 0,
            );
        }

        $update_dt = false;

        if ( MainWP_Uptime_Monitoring_Connect::UP === $ping_data['status'] ) {
            $update    = array(
                'monitor_id' => $monitor_id,
                'up'         => ( $current_stat['up'] + 1 ),
                'ping_avg'   => intval( ( $current_stat['ping_avg'] * $current_stat['up'] + $ping_data['ping_ms'] ) / ( $current_stat['up'] + 1 ) ),
                'ping_min'   => min( $current_stat['ping_min'], $ping_data['ping_ms'] ),
                'ping_max'   => max( $current_stat['ping_max'], $ping_data['ping_ms'] ),
                'timestamp'  => $hourly_key,
            );
            $update_dt = true;
        } elseif ( MainWP_Uptime_Monitoring_Connect::DOWN === $ping_data['status'] ) {
            // do not update ping_avg, ping_min, ping_max here.
            $update    = array(
                'monitor_id' => $monitor_id,
                'down'       => ( $current_stat['down'] + 1 ),
                'timestamp'  => $hourly_key,
            );
            $update_dt = true;
        }

        // make sure save UP & DOWN status only.
        if ( $update_dt ) {
            if ( ! empty( $update_stat_id ) ) {
                $update['stat_hourly_id'] = $update_stat_id; // update stat.
            }
            MainWP_DB_Uptime_Monitoring::instance()->update_site_uptime_stat_hourly( $update );
        }
    }


    /**
     * Method get_hourly_key_by_timestamp
     *
     * @param  int $timestamp timestamp.
     * @return int
     */
    public static function get_hourly_key_by_timestamp( $timestamp ) {
        return strtotime( gmdate( 'Y-m-d H:00:00', (int) $timestamp ) );
    }

    /**
     * Method clear_outdated_hourly_uptime_stats
     *
     * @return void
     */
    public function clear_outdated_hourly_uptime_stats() {
        $now      = MainWP_Utility::get_timestamp();
        $midnight = strtotime( gmdate( 'Y-m-d 00:00:01', $now ) );
        if ( ( $now - $midnight < 2 * HOUR_IN_SECONDS ) && ( $now - $midnight > HOUR_IN_SECONDS ) ) {
            $lasttime = (int) get_option( 'mainwp_uptime_monitoring_lasttime_clear_hourly_stats' );
            if ( empty( $lasttime ) || ( $now > $lasttime + 20 * HOUR_IN_SECONDS ) ) {
                MainWP_DB_Uptime_Monitoring::instance()->remove_outdated_hourly_uptime_stats();
                MainWP_Utility::update_option( 'mainwp_uptime_monitoring_lasttime_clear_hourly_stats', $now );
            }
        }
    }

    /**
     * Update monitor notification process.
     *
     * @param  int $monitor_id monitor id.
     * @param  int $check_time monitor time.
     *
     * @return void
     */
    public function update_process_monitor_notification( $monitor_id, $check_time ) {

        $current = MainWP_DB::instance()->get_regular_process_by_item_id_type_slug( $monitor_id, 'monitor', 'uptime_notification' );

        if ( ! empty( $current ) ) {
            $prc_update = array(
                'process_id' => $current->process_id,
                'status'     => 'active',
            );

            if ( empty( $current->dts_process_init_time ) ) { // process init time for this mo notification.
                $prc_update['dts_process_init_time'] = $check_time;
            }

            MainWP_DB::instance()->update_regular_process( $prc_update );
        } else {
            // insert process.
            MainWP_DB::instance()->update_regular_process( // insert.
                array(
                    'item_id'               => $monitor_id,
                    'type'                  => 'monitor',
                    'process_slug'          => 'uptime_notification',
                    'status'                => 'active',
                    'dts_process_init_time' => $check_time, // use UTC time to compatible with monitor heartbeat db time.
                )
            );
        }
    }
}
