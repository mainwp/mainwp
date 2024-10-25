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
     * Method render_uptime_widget().
     */
    public function render_uptime_widget() {
        if ( ! isset( $_GET['page'] ) || 'managesites' !== $_GET['page'] || !isset($_GET['dashboard'])) { //phpcs:ignore -- ok.
            return;
        }

        $site_id = intval( $_GET['dashboard'] );
        if ( empty( $site_id ) ) {
            return;
        }

        ?>
        <div class="ui grid mainwp-widget-header">
            <div class="twelve wide column">
                <h3 class="ui header handle-drag">
                    <?php esc_html_e( 'Uptime Monitoring', 'mainwp' ); ?>
                    <div class="sub header"><?php esc_html_e( 'Monitor uptime', 'mainwp' ); ?></div>
                </h3>
            </div>
        </div>
        <h4 class="ui header">
        <?php
            $last = MainWP_DB_Uptime_Monitoring::instance()->get_last_site_heartbeat( $site_id );
            esc_html_e( 'Current Status:', 'mainwp' );
        if ( $last ) {
            echo $last->status ? ' <span class="ui green text">UP</span>' : ' <span class="ui red text">DOWN</span>';
        }

        $incidents_count = MainWP_DB_Uptime_Monitoring::instance()->get_last_site_incidents_stats( $site_id );

        if ( ! is_array( $incidents_count ) ) {
            $incidents_count = array();
        }

        $ratios_count = MainWP_DB_Uptime_Monitoring::instance()->get_last_site_uptime_ratios_stats( $site_id );

        if ( ! is_array( $ratios_count ) ) {
            $ratios_count = array();
        }

        ?>
        </h4>
        <div class="mainwp-scrolly-overflow">
            <div id="mainwp-widget-uptime-content">

                <div class="ui dividing header"><?php esc_html_e( 'Number of Incidents', 'mainwp' ); ?></div>
                <div class="ui three columns tablet stackable grid">
                    <div class="center aligned middle aligned column">
                        <div class="mainwp-lighthouse-score ui massive  circular basic label" id="mainwp-widget-uptime-incidents-24hours"><?php echo isset( $incidents_count['total24'] ) ? intval( $incidents_count['total24'] ) : 'N/A'; ?></div>
                        <h4 class="ui header"><?php esc_html_e( 'Last 24 hours', 'mainwp' ); ?></h4>
                    </div>
                    <div class="center aligned middle aligned column">
                        <div class="mainwp-lighthouse-score ui massive  circular basic label" id="mainwp-widget-uptime-incidents-7days"><?php echo isset( $incidents_count['total24'] ) ? intval( $incidents_count['total7'] ) : 'N/A'; ?></div>
                        <h4 class="ui header"><?php esc_html_e( 'Last 7 days', 'mainwp' ); ?></h4>
                    </div>
                    <div class="center aligned middle aligned column">
                        <div class="mainwp-lighthouse-score ui massive  circular basic label" id="mainwp-widget-uptime-incidents-30days"><?php echo isset( $incidents_count['total24'] ) ? intval( $incidents_count['total30'] ) : 'N/A'; ?></div>
                        <h4 class="ui header"><?php esc_html_e( 'Last 30 days', 'mainwp' ); ?></h4>
                    </div>
                </div>
                <div class="ui dividing header"><?php esc_html_e( 'Uptime Ratios', 'mainwp' ); ?></div>
                <div class="ui three columns tablet stackable grid">
                    <div class="center aligned middle aligned column">
                        <div class="mainwp-lighthouse-score ui massive  circular basic label" id="mainwp-widget-uptime-ratios-24hours"><?php echo ! empty( $ratios_count['total24'] ) ? number_format( $ratios_count['up24'] * 100 / $ratios_count['total24'], 2 ) . '%' : 'N/A'; ?></div>
                        <h4 class="ui header"><?php esc_html_e( 'Last 24 hours', 'mainwp' ); ?></h4>
                    </div>
                    <div class="center aligned middle aligned column">
                        <div class="mainwp-lighthouse-score ui massive  circular basic label" id="mainwp-widget-uptime-ratios-7days"><?php echo ! empty( $ratios_count['total7'] ) ? number_format( $ratios_count['up7'] * 100 / $ratios_count['total7'], 2 ) . '%' : 'N/A'; ?></div>
                        <h4 class="ui header"><?php esc_html_e( 'Last 7 days', 'mainwp' ); ?></h4>
                    </div>
                    <div class="center aligned middle aligned column">
                        <div class="mainwp-lighthouse-score ui massive  circular basic label" id="mainwp-widget-uptime-ratios-30days"><?php echo ! empty( $ratios_count['total30'] ) ? number_format( $ratios_count['up30'] * 100 / $ratios_count['total30'], 2 ) . '%' : 'N/A'; ?></div>
                        <h4 class="ui header"><?php esc_html_e( 'Last 30 days', 'mainwp' ); ?></h4>
                    </div>

                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Method render_response_time_widget().
     */
    public function render_response_time_widget() {
        if ( ! isset( $_GET['page'] ) || 'managesites' !== $_GET['page'] || !isset($_GET['dashboard']) ) { //phpcs:ignore -- ok.
            return;
        }

        $site_id = intval( $_GET['dashboard'] );

        if ( empty( $site_id ) ) {
            return;
        }

        $filter_dtsstart = gmdate( 'Y-m-d', time() - 7 * DAY_IN_SECONDS );
        $filter_dtsstop  = gmdate( 'Y-m-d', time() );

        $params = array(
            'start' => $filter_dtsstart,
            'end'   => $filter_dtsstop,
        );

        $results = MainWP_Uptime_Monitoring_Handle::instance()->get_site_response_time_per_days_stats( $site_id, $params );

        if ( ! is_array( $results ) ) {
            $results = array();
        }

        $data       = ! empty( $results['response_time_data_lists'] ) && is_array( $results['response_time_data_lists'] ) ? $results['response_time_data_lists'] : array();
        $resp_stats = ! empty( $results['resp_stats'] ) && is_array( $results['resp_stats'] ) ? $results['resp_stats'] : array();

        ?>
        <div class="ui grid mainwp-widget-header">
            <div class="twelve wide column">
                <h3 class="ui header handle-drag">
                    <?php esc_html_e( 'Uptime Monitoring Response Time', 'mainwp' ); ?>
                    <div class="sub header"><?php esc_html_e( 'Measure response time', 'mainwp' ); ?></div>
                </h3>
            </div>
        </div>
        <input type="hidden" id="mainwp-uptime-response-times-siteid" value="<?php echo intval( $site_id ); ?>">

        <div id="mainwp-response-time-message-zone" class="ui message" style="display:none;"></div>

        <div id="mainwp-widget-uptime-response-time-calendar-wrapper">
            <div class="ui stackable compact grid mini form" id="mainwp-module-cost-tracker-costs-filters-row">
                <div class="thirteen wide column ui compact grid">
                    <div class="three wide middle aligned column">
                        <div class="ui calendar fluid mainwp_datepicker">
                            <div class="ui fluid input left icon">
                                <i class="calendar icon"></i>
                                <input type="text" autocomplete="off" placeholder="<?php esc_attr_e( 'Start date', 'mainwp' ); ?>" id="uptime-response-times-widget-filter-dtsstart" value="<?php echo ! empty( $filter_dtsstart ) ? esc_attr( $filter_dtsstart ) : ''; ?>"/>
                            </div>
                        </div>
                    </div>
                    <div class="three wide middle aligned column">
                        <div class="ui calendar fluid mainwp_datepicker">
                            <div class="ui fluid input left icon">
                                <i class="calendar icon"></i>
                                <input type="text" autocomplete="off" placeholder="<?php esc_attr_e( 'End date', 'mainwp' ); ?>" id="uptime-response-times-widget-filter-dtsstop" value="<?php echo ! empty( $filter_dtsstop ) ? esc_attr( $filter_dtsstop ) : ''; ?>"/>
                            </div>
                        </div>
                    </div>
                    <div class="three wide middle aligned right aligned column">
                        <button onclick="mainwp_uptime_monitoring_response_time_filter()" class="ui mini green button"><?php esc_html_e( 'Filter Response', 'mainwp' ); ?></button>
                    </div>
                </div>
            </div>
        </div>


        <div class="mainwp-scrolly-overflow">
            <div id="mainwp-widget-uptime-response-time-content">

            <div id="mainwp-uptime-monitoring-site-widget-chart-wrapper" ></div>

                <div class="ui three columns tablet stackable grid">
                    <div class="center aligned middle aligned column">
                        <div class="mainwp-lighthouse-score ui massive  circular basic label" id="mainwp-widget-uptime-resp-time-avg"><?php echo ! empty( $resp_stats['avg_resp_time'] ) ? esc_html( $resp_stats['avg_resp_time'] ) : 'N/A'; ?></div>
                        <h4 class="ui header"><?php esc_html_e( 'Avg Response Time (s)', 'mainwp' ); ?></h4>
                    </div>
                    <div class="center aligned middle aligned column">
                        <div class="mainwp-lighthouse-score ui massive  circular basic label" id="mainwp-widget-uptime-resp-time-min"><?php echo ! empty( $resp_stats['min_resp_time'] ) ? esc_html( $resp_stats['min_resp_time'] ) : 'N/A'; ?></div>
                        <h4 class="ui header"><?php esc_html_e( 'Min Response Time (s)', 'mainwp' ); ?></h4>
                    </div>
                    <div class="center aligned middle aligned column">
                        <div class="mainwp-lighthouse-score ui massive  circular basic label" id="mainwp-widget-uptime-resp-time-max"><?php echo ! empty( $resp_stats['max_resp_time'] ) ? esc_html( $resp_stats['max_resp_time'] ) : 'N/A'; ?></div>
                        <h4 class="ui header"><?php esc_html_e( 'Max Response Time (s)', 'mainwp' ); ?></h4>
                    </div>
                </div>

                <script type="text/javascript">

                    jQuery( document ).ready( function() {
                        const data = <?php echo json_encode( $data ); ?>;
                        mainwp_uptime_monitoring_response_time_show_chart(data, 1000 );
                        // to fix issue not loaded calendar js library
                        if (jQuery('.ui.calendar').length > 0) {
                            if (mainwpParams.use_wp_datepicker == 1) {
                                jQuery('#mainwp-widget-uptime-response-time-calendar-wrapper .ui.calendar input[type=text]').datepicker({ dateFormat: "yy-mm-dd" });
                            } else {
                                jQuery('#mainwp-widget-uptime-response-time-calendar-wrapper .ui.calendar').calendar({
                                    type: 'date',
                                    monthFirst: false,
                                    today: true,
                                    touchReadonly: false,
                                    formatter: {
                                        date : 'YYYY-MM-DD'
                                    }
                                });
                            }
                        }
                    });

                    var up_monitoring;

                    let mainwp_uptime_monitoring_response_time_filter = function () {

                        let sdt = jQuery('#uptime-response-times-widget-filter-dtsstart').val();
                        let edt = jQuery('#uptime-response-times-widget-filter-dtsstop').val();

                        if(sdt == '' && edt == '' ){
                            return false;
                        }
                        jQuery['##mainwp-uptime-monitoring-site-widget-chart-wrapper']
                        let data = mainwp_secure_data( {
                            action: 'mainwp_uptime_monitoring_get_response_times',
                            siteid: jQuery( '#mainwp-uptime-response-times-siteid').val(),
                            dtsstart: sdt,
                            dtsstop: edt,
                        } );

                        mainwp_showhide_message('mainwp-response-time-message-zone', '<i class="notched circle loading icon"></i> ' + __('Running...'), 'green');
                        jQuery.post(ajaxurl, data, function (response) {
                            mainwp_showhide_message('mainwp-response-time-message-zone', '' );
                            if (response?.data ) {
                                let resp_stats = response?.data_stats ?? {};
                                mainwp_uptime_monitoring_response_time_show_chart(response?.data);
                                jQuery('#mainwp-widget-uptime-resp-time-avg').html( resp_stats?.avg_resp_time ?? 'N/A');
                                jQuery('#mainwp-widget-uptime-resp-time-min').html( resp_stats?.min_resp_time ?? 'N/A');
                                jQuery('#mainwp-widget-uptime-resp-time-max').html( resp_stats?.max_resp_time ?? 'N/A');
                            } else if (response?.error) {
                                mainwp_showhide_message('mainwp-response-time-message-zone', response.error, 'red');
                            } else {
                                mainwp_showhide_message('mainwp-response-time-message-zone', __('Undefined error. Please try again.'), 'red');
                            }
                        }, 'json');
                        return false;
                    }


                    let mainwp_uptime_monitoring_response_time_show_chart = function (data, delay ) {

                        if(typeof delay === 'undefined' ){
                            delay = 100;
                        }

                        const categories = data.map(item => item.date);
                        const values = data.map(item => item.value);

                        console.log(categories);
                        console.log(values);

                        if( !up_monitoring){
                                // Chart configuration.
                                const options = {
                                    chart: {
                                        type: 'line',
                                        height: 350,
                                        stacked: true,
                                        toolbar: false
                                    },
                                    series: [{
                                        name: 'Resp Time',
                                        data: values
                                    }],
                                    xaxis: {
                                        categories: categories
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
                                                return value + ' s';
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
                                        theme: 'dark'
                                    }
                                };

                            up_monitoring = new ApexCharts(document.querySelector("#mainwp-uptime-monitoring-site-widget-chart-wrapper"), options);
                            setTimeout(() => {
                                up_monitoring.render();
                            }, delay );
                        } else {
                            var newOptions = {
                                series: [{
                                    name: 'Values',
                                    data: values
                                }],
                                xaxis: {
                                    categories: categories
                                },
                            };
                            // Update the chart with new options.
                            up_monitoring.updateOptions(newOptions);
                        }
                    }

                </script>
            </div>
        </div>
        <?php
    }
}
