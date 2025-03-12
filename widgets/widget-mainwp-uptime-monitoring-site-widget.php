<?php
/**
 * MainWP Uptime monitoring Widget
 *
 * @package MainWP/Dashboard
 * @version 5.3
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Uptime_Monitoring_Site_Widget
 *
 * Displays the Logs info.
 */
class MainWP_Uptime_Monitoring_Site_Widget {

    /**
     * Protected static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    protected static $instance = null;

    /**
     * Return the single instance of the class.
     *
     * @return mixed $instance The single instance of the class.
     */
    public static function instance() {
        if ( is_null( static::$instance ) ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Method ajax_get_response_times
     *
     * @return void
     */
    public function ajax_get_response_times() { //phpcs:ignore -- NOSONAR - complexity.

        mainwp_secure_request( 'mainwp_uptime_monitoring_get_response_times' );
        //phpcs:disable WordPress.Security.NonceVerification
        $site_id        = isset( $_POST['siteid'] ) ? intval( $_POST['siteid'] ) : 0;
        $current_period = isset( $_POST['period'] ) ? sanitize_text_field( wp_unslash( $_POST['period'] ) ) : '';

        if ( empty( $current_period ) ) {
            $current_period = get_user_option( 'mainwp_uptime_monitoring_widget_stat_selected_period' );
        }

        $current_period = static::get_valid_days_periods( $current_period );

        MainWP_Utility::update_user_option( 'mainwp_uptime_monitoring_widget_stat_selected_period', $current_period );

        $days_number = static::get_days_number_by_period( $current_period );

        if ( empty( $site_id ) ) {
            die( wp_json_encode( array( 'error' => esc_html__( 'The Site ID is invalid or not found. Please try again.', 'mainwp' ) ) ) );
        }

        if ( empty( $_POST['dtsstart'] ) || empty( $_POST['dtsstop'] ) ) {
            die( wp_json_encode( array( 'error' => esc_html__( 'Start and end dates cannot be empty. Please try again.', 'mainwp' ) ) ) );
        }

        $params = array(
            'start' => $_POST['dtsstart'] . ' 00:00:00',
            'end'   => $_POST['dtsstop'] . ' 23:59:59',
            'issub' => 0,
        );

        //phpcs:enable WordPress.Security.NonceVerification

        if ( strtotime( $params['start'] ) > strtotime( $params['end'] ) ) {
            die( wp_json_encode( array( 'error' => esc_html__( 'The start date must be earlier than the end date. Please try again.', 'mainwp' ) ) ) );
        }

        $params['group_time_by'] = $this->prepare_group_time_option_for_ui_chart_data_only( $site_id, $params, true );

        $results = MainWP_Uptime_Monitoring_Handle::instance()->get_site_response_time_chart_data( $site_id, $params );

        $prepare_chart_dt = array();

        if ( ! empty( $results['response_time_data_lists'] ) ) {
            $prepare_chart_dt = $this->prepare_response_time_ui_chart_data( $results['response_time_data_lists'], $params );
        }

        $last_incidents_count = MainWP_DB_Uptime_Monitoring::instance()->get_site_count_last_incidents( $site_id, $days_number );

        if ( ! is_array( $last_incidents_count ) ) {
            $last_incidents_count = array();
        }

        $ratios_count = MainWP_DB_Uptime_Monitoring::instance()->get_last_site_uptime_ratios_values( $site_id, $days_number );

        if ( ! is_array( $ratios_count ) ) {
            $ratios_count = array();
        }

        $data_stats = ! empty( $results['resp_stats'] ) && is_array( $results['resp_stats'] ) ? $results['resp_stats'] : array();

        $active_monitor = 0;

        $primary_monitor = MainWP_DB_Uptime_Monitoring::instance()->get_monitor_by( $site_id, 'issub', 0 );
        if ( $primary_monitor ) {
            $global_settings = MainWP_Uptime_Monitoring_Handle::get_global_monitoring_settings();
            $active_monitor  = MainWP_Uptime_Monitoring_Connect::get_apply_setting( 'active', (int) $primary_monitor->active, $global_settings, -1, 60 );
        }
        $data_stats['active_monitor'] = $active_monitor ? 1 : 0;

        $last = MainWP_DB_Uptime_Monitoring::instance()->get_last_site_heartbeat( $site_id, false );

        if ( $last ) {
            $data_stats['current_status'] = $last->status;
            $data_stats['http_code']      = $last->http_code;
        }

        $data_stats['incidents_count'] = isset( $last_incidents_count['count'] ) ? $last_incidents_count['count'] : 'N/A';

        if ( ! empty( $ratios_count['total_value'] ) ) {
            $data_stats['ratios_number'] = number_format( $ratios_count['up_value'] / $ratios_count['total_value'], 10 ); // 10 to fix.
        }

        die(
            wp_json_encode(
                array(
                    'data'       => $prepare_chart_dt,
                    'data_stats' => $data_stats,
                ),
            )
        );
    }


    /**
     * Method get_valid_days_periods
     *
     * @param  string|null $period period.
     * @return mixed
     */
    public static function get_valid_days_periods( $period = null ) {
        $values = array(
            'day'   => 0,
            'week'  => 7,
            'month' => 30,
            'year'  => 365,
        );
        if ( null === $period ) {
            return $values;
        }
        return isset( $values[ $period ] ) ? $period : 'day';
    }

    /**
     * Method get_days_number_by_period
     *
     * @param  string|false $period period.
     * @return mixed
     */
    public static function get_days_number_by_period( $period ) {
        if ( empty( $period ) || ! is_scalar( $period ) ) {
            return 0;
        }
        $values = static::get_valid_days_periods();
        return isset( $values[ $period ] ) ? $values[ $period ] : 0;
    }

    /**
     * Renders the top widget.
     */
    public static function render_top_widget() {

        $current_period = get_user_option( 'mainwp_uptime_monitoring_widget_stat_selected_period' );

        $current_period = static::get_valid_days_periods( $current_period );

        ?>
        <div class="ui grid mainwp-widget-header">
            <div class="twelve wide column">
                <h2 class="ui header handle-drag">
                    <?php
                    /**
                     * Filter: mainwp_uptime_monitoring_response_time_widget_title
                     *
                     * Filters the widget title text.
                     *
                     * @since 5.3
                     */
                    echo esc_html( apply_filters( 'mainwp_uptime_monitoring_response_time_widget_title', esc_html__( 'Uptime Monitoring', 'mainwp' ) ) );
                    ?>
                    <div class="sub header"><?php esc_html_e( 'Monitor site uptime status and response time history.', 'mainwp' ); ?></div>
                </h2>
            </div>

            <div class="four wide column right aligned">
                <div class="ui dropdown right pointing mainwp-dropdown-tab not-auto-init" id="uptime-monitoring-widget-response-times-top-select" tabindex="0">
                    <input type="hidden" value="<?php echo esc_attr( $current_period ); ?>">
                    <i class="vertical ellipsis icon"></i>
                    <div class="menu">
                        <a class="item" data-value="day" title="<?php esc_html_e( 'Last 24 hours' ); ?>" ><?php esc_html_e( 'Last 24 hours' ); ?></a>
                        <a class="item" data-value="week" title="<?php esc_html_e( 'Last 7 days' ); ?>" ><?php esc_html_e( 'Last 7 days' ); ?></a>
                        <a class="item" data-value="month" title="<?php esc_html_e( 'Last 30 days' ); ?>" ><?php esc_html_e( 'Last 30 days' ); ?></a>
                        <a class="item" data-value="year" title="<?php esc_html_e( 'Last 365 days' ); ?>" ><?php esc_html_e( 'Last 365 days' ); ?></a>
                    </div>
                </div>
            </div>
        </div>

        <script type="text/javascript">
            jQuery( document ).ready( function () {

                mainwp_uptime_monitoring_response_time_filter('<?php echo esc_js( $current_period ); ?>');

                jQuery( '#uptime-monitoring-widget-response-times-top-select' ).dropdown( {
                    onChange: function( value ) {
                        console.log( value );
                        mainwp_uptime_monitoring_response_time_filter(value);
                    }
                });

            } );
        </script>
        <?php
    }


    /**
     * Handle render costs widget.
     */
    public function render_response_times_widget() {

        if ( ! isset( $_GET['page'] ) || 'managesites' !== $_GET['page'] || !isset($_GET['dashboard']) ) { //phpcs:ignore -- ok.
            return;
        }

        $site_id = intval( $_GET['dashboard'] ); //phpcs:ignore -- ok.

        if ( empty( $site_id ) ) {
            return;
        }

        static::render_top_widget();
        ?>
        <div id="mainwp-response-time-message-zone" class="ui message" style="display:none;"></div>

        <div class="mainwp-scrolly-overflow">
            <div id="mainwp-monitor-widget-loader">
                <div class="ui active inverted dimmer">
                    <div class="ui text loader"><?php esc_html_e( 'Loading', 'mainwp' ); ?></div>
                </div>
                <p></p>
            </div>
            <?php $this->render_content_response_time_widget( $site_id ); ?>
        </div>
        <div class="mainwp-widget-footer"></div>
        <?php
    }


    /**
     * Method render_content_response_time_widget
     *
     * @param  mixed $site_id site id.
     * @return void
     */
    public function render_content_response_time_widget( $site_id ) {

        $days_periods = static::get_valid_days_periods();
        $end_dts      = time();

        $select_dates = array();

        foreach ( $days_periods as $period => $num ) {
            $select_dates[ $period ] = array(
                'start' => gmdate( 'Y-m-d', $end_dts - $num * DAY_IN_SECONDS ),
                'end'   => gmdate( 'Y-m-d', $end_dts ),
            );
        }

        ?>
        <input type="hidden" id="mainwp-uptime-response-times-siteid" value="<?php echo intval( $site_id ); ?>">

        <div id="mainwp-widget-uptime-response-time-content" style="display:none;">

            <div class="ui three cards">
                <div class="ui small card">
                    <div class="content">
                        <div class="header">
                            <span class="ui large text" id="mainwp-widget-uptime-current-status"></span>
                        </div>
                        <div class="description">
                            <strong><?php esc_html_e( 'Current Status', 'mainwp' ); ?> <span id="mainwp-widget-uptime-http-code"></span></strong>
                        </div>
                    </div>
                </div>

                <div class="ui small card">
                    <div class="content">
                        <div class="header">
                            <span class="ui large text" id="mainwp-widget-uptime-incidents-count"></span>
                        </div>
                        <div class="description">
                            <strong><?php esc_html_e( 'Incidents', 'mainwp' ); ?></strong>
                        </div>
                    </div>
                </div>

                <div class="ui small card">
                    <div class="content">
                        <div class="header">
                            <span class="ui large text" id="mainwp-widget-uptime-ratios-number"></span>
                        </div>
                        <div class="description">
                            <strong><?php esc_html_e( 'Uptime ratio', 'mainwp' ); ?></strong>
                        </div>
                    </div>
                </div>
            </div>

            <div id="mainwp-uptime-monitoring-site-widget-chart-wrapper"></div>

            <div class="ui internally celled three column grid">
                <div class="column">
                    <div class="content">
                        <div class="header">
                            <span class="ui large text" id="mainwp-widget-uptime-resp-time-avg"></span>
                        </div>
                        <div class="description">
                            <strong><?php esc_html_e( 'Average', 'mainwp' ); ?></strong>
                        </div>
                    </div>
                </div>

                <div class="column">
                    <div class="content">
                        <div class="header">
                            <span class="ui large text" id="mainwp-widget-uptime-resp-time-min"></span>
                        </div>
                        <div class="description">
                            <strong><?php esc_html_e( 'Minimum', 'mainwp' ); ?></strong>
                        </div>
                    </div>
                </div>

                <div class="column">
                    <div class="content">
                        <div class="header">
                            <span class="ui large text" id="mainwp-widget-uptime-resp-time-max"></span>
                        </div>
                        <div class="description">
                            <strong><?php esc_html_e( 'Maximum', 'mainwp' ); ?></strong>
                        </div>
                    </div>
                </div>
            </div>

            <script type="text/javascript">

                const select_dates = <?php echo wp_json_encode( $select_dates ); ?>;

                let mainwp_uptime_monitoring_response_time_filter = function (selected_period ) {

                    let start_dt = select_dates[selected_period]?.start ?? select_dates.day.start;
                    let end_dt = select_dates[selected_period]?.end ?? select_dates.day.start;

                    if( start_dt == '' || end_dt == '' ){
                        return false;
                    }

                    jQuery('#mainwp-monitor-widget-loader').hide();

                    let data = mainwp_secure_data( {
                        action: 'mainwp_uptime_monitoring_get_response_times',
                        siteid: jQuery( '#mainwp-uptime-response-times-siteid').val(),
                        dtsstart: start_dt,
                        dtsstop: end_dt,
                        period: selected_period,
                    } );

                    jQuery('#mainwp-monitor-widget-loader').show();

                    jQuery.post(ajaxurl, data, function (response) {

                        jQuery('#mainwp-widget-uptime-response-time-content').show();

                        if (response?.data ) {
                            mainwp_uptime_monitoring_response_time_show_chart(response?.data);
                            let resp_stats = response?.data_stats ?? {};

                            let curr_status = resp_stats?.current_status ??'';
                            let curr_code = resp_stats?.http_code ??'';


                            if( resp_stats?.active_monitor == 0  ){
                                curr_status = '<span class="ui gray text"><i class="stop circle outline icon"></i> ' + __('DISABLED') + '</span>'
                            } else {
                                if( curr_status === '1'){
                                    curr_status = ' <span class="ui big circular icon green looping pulsating transition label"><i class="chevron up icon"></i></span> ' + __('UP') + '</span>';
                                } else if( curr_status === '0'){
                                    curr_status = '<span class="ui big circular icon red looping pulsating transition label"><i class="chevron down icon"></i></span> ' + __('DOWN') + '</span>';
                                } else {
                                    curr_status = '<span class="ui big circular icon grey looping pulsating transition label"><i class="circle outline icon"></i></span> ' + __('PENDING') + '</span>';
                                }
                            }

                            if(curr_code !== '' ){
                                curr_code = ' - ' + curr_code;
                            }

                            let inc_num = resp_stats?.incidents_count && ! isNaN( resp_stats.incidents_count ) ? parseFloat(resp_stats.incidents_count) : 0;

                            jQuery('#mainwp-widget-uptime-current-status').html( curr_status );
                            jQuery('#mainwp-widget-uptime-http-code').html( curr_code );
                            jQuery('#mainwp-widget-uptime-incidents-count').html( resp_stats?.incidents_count ?? 'N/A');

                            if(resp_stats?.ratios_number && '' !== resp_stats?.ratios_number){
                                let rat_num = Number(resp_stats.ratios_number * 100).toFixed(2);
                                if(rat_num >= 100 && inc_num > 0){
                                    rat_num = '~100';
                                }
                                jQuery('#mainwp-widget-uptime-ratios-number').html( rat_num + '%');
                            } else {
                                jQuery('#mainwp-widget-uptime-ratios-number').html( 'N/A');
                            }

                            // jQuery('#mainwp-widget-uptime-ratios-number').html( resp_stats?.ratios_number && '' !== resp_stats?.ratios_number ? Number(resp_stats.ratios_number * 100).toFixed(2) + '%'  : 'N/A');
                            jQuery('#mainwp-widget-uptime-resp-time-avg').html( resp_stats?.avg_resp_time ? resp_stats.avg_resp_time + '<span class="ui tiny text">(seconds)</span>' : 'N/A');
                            jQuery('#mainwp-widget-uptime-resp-time-min').html( resp_stats?.min_resp_time ? resp_stats.min_resp_time + '<span class="ui tiny text">(seconds)</span>' : 'N/A');
                            jQuery('#mainwp-widget-uptime-resp-time-max').html( resp_stats?.max_resp_time ? resp_stats.max_resp_time + '<span class="ui tiny text">(seconds)</span>' : 'N/A');


                            mainwp_showhide_message('mainwp-response-time-message-zone', '' );
                            jQuery('#mainwp-monitor-widget-loader').hide();

                        } else if (response?.error) {
                            mainwp_showhide_message('mainwp-response-time-message-zone', '<i class="close icon"></i>' + response.error, 'red');
                        } else {
                            mainwp_showhide_message('mainwp-response-time-message-zone', '<i class="close icon"></i>' + __('Undefined error. Please try again.'), 'red');
                        }
                    }, 'json');
                    return false;
                }

                var up_monitoring;

                let mainwp_uptime_monitoring_response_time_show_chart = function (data, delay ) {

                    if(typeof delay === 'undefined' ){
                        delay = 100;
                    }

                    const categories = data.map(item => item.date);
                    const values = data.map(item => item.value);
                    let x_counter = -1;
                    let x_div = Math.ceil(categories.length / 12);
                    console.log(categories ? categories.length : 0);
                    if( !up_monitoring){
                            // Chart configuration.
                            const options = {
                                chart: {
                                    type: 'area',
                                    height: '75%',
                                    stacked: true,
                                    toolbar: false,
                                },
                                series: [{
                                    name: '',
                                    data: values
                                }],
                                dataLabels: {
                                    enabled: false
                                },
                                xaxis: {
                                    categories: categories,
                                    show: true,
                                    tooltip: {
                                        enabled: false
                                    },
                                    labels: {
                                        formatter: function (value) {
                                            if(typeof value !== "undefined"){
                                                x_counter++;
                                                return x_counter % x_div === 0 ? value : '';
                                            }
                                            return value;
                                        },
                                        style: {
                                            colors: '#999999',
                                        },
                                    },
                                },
                                title: {
                                    text: '',
                                    align: 'center'
                                },
                                stroke: {
                                    curve: 'smooth'
                                },
                                fill: {
                                    opacity: 1
                                },
                                yaxis:{
                                    type: 'string',
                                    labels: {
                                        formatter: function (value) {
                                            return value;
                                        },
                                        style: {
                                            colors: '#999999',
                                        },
                                    },
                                },
                                legend: {
                                    show: false
                                },
                                tooltip: {
                                    theme: 'dark',
                                },
                                colors: ['#7fb100'],
                                grid: {
                                    borderColor: '#99999910',
                                },
                            };

                        up_monitoring = new ApexCharts(document.querySelector("#mainwp-uptime-monitoring-site-widget-chart-wrapper"), options);
                        setTimeout(() => {
                            up_monitoring.render();
                        }, delay );

                    } else {
                        var newOptions = {
                            series: [{
                                data: values
                            }],
                            xaxis: {
                                categories: categories,
                                labels: {
                                    formatter: function (value) {
                                        if(typeof value !== "undefined"){
                                            x_counter++;
                                            return x_counter % x_div === 0 ? value : '';
                                        }
                                        return value;
                                    },
                                },
                            },
                        };
                        // Update the chart with new options.
                        up_monitoring.updateOptions(newOptions);
                    }
                }

            </script>
    </div>
        <?php
    }



    /**
     * Set 'group_time_by' option for chart data query.
     *
     * Do not use for other query data.
     *
     * @param  int   $site_id site id.
     * @param  array $params params, 'start' and 'end' date must in format: Y-m-d H:i:s.
     * @param  bool  $ajax_working ajax working.
     *
     * @return string
     */
    private function prepare_group_time_option_for_ui_chart_data_only( $site_id, &$params, $ajax_working = false ) { //phpcs:ignore -- NOSONAR - complexity.
        $group_time = 'date';

        if ( ! is_array( $params ) || empty( $params['start'] ) || empty( $params['end'] ) ) {
            return $group_time;
        }

        $start_ts = strtotime( $params['start'] ); // start,end format: Y-m-d H:i:s.
        $end_ts   = strtotime( $params['end'] );

        // check other.

        $apply_interval  = false;
        $primary_monitor = MainWP_DB_Uptime_Monitoring::instance()->get_monitor_by( $site_id, 'issub', 0 );
        if ( $primary_monitor ) {
            $apply_interval = MainWP_Uptime_Monitoring_Connect::get_apply_setting( 'interval', (int) $primary_monitor->interval, false, -1, 60 );
        }

        if ( false !== $apply_interval ) {
            $length_time = $end_ts - $start_ts;

            if ( $ajax_working ) {
                $is_get_all = $length_time <= DAY_IN_SECONDS && $apply_interval <= 60;
            } elseif ( $apply_interval <= 60 ) {
                $is_get_all = true;
            }

            $is_group_hours = $length_time <= 3 * DAY_IN_SECONDS && $length_time > DAY_IN_SECONDS && $apply_interval >= 5 ? true : false;

            if ( ! $is_group_hours && $length_time <= 7 * DAY_IN_SECONDS && $apply_interval >= 60 ) {
                $is_group_hours = 'hour';
            }

            if ( $is_get_all ) {
                $group_time = 'get_all';
            } elseif ( $is_group_hours ) {
                $group_time = 'hour';
            }
        }

        return $group_time;
    }

    /**
     * Prepare response time for ui chart data.
     *
     * @param  array  $chart_data array: date and value.
     * @param  array  $params params.
     * @param  string $slug for date format hook.
     * @return array prepared.
     */
    public function prepare_response_time_ui_chart_data( $chart_data, $params = array(), $slug = 'uptime' ) {

        $format_date = ''; // need to be empty for process.
        if ( ! empty( $params['group_time_by'] ) ) {
            if ( 'hour' === $params['group_time_by'] ) {
                $format_date = 'd H a'; // 09 am.
            } elseif ( 'get_all' === $params['group_time_by'] ) {
                $format_date = 'H:i'; // 10:15.
            }
        }

        $format_date = apply_filters( 'mainwp_widgets_chart_date_format', $format_date, $params, $slug );

        if ( is_array( $chart_data ) ) {
            $chart_data = array_map(
                function ( $value ) use ( $format_date ) {
                    $local_datetime = MainWP_Utility::get_timestamp( strtotime( $value['date'] ) );
                    if ( ! empty( $format_date ) ) {
                        $value['date'] = gmdate( $format_date, $local_datetime );
                    } else {
                        $value['date'] = MainWP_Utility::format_date( $local_datetime );
                    }
                    return $value;
                },
                $chart_data // map data.
            );
        } else {
            $chart_data = array();
        }
        return $chart_data;
    }
}
