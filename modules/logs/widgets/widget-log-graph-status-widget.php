<?php
/**
 * MainWP Logs Widget
 *
 * Displays the Logs Info.
 *
 * @package MainWP/Dashboard
 * @version 4.6
 */

namespace MainWP\Dashboard\Module\Log;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Utility;
use MainWP\Dashboard\MainWP_UI;

/**
 * Class Log_Graph_Status_Widget
 *
 * Displays the Logs info.
 */
class Log_Graph_Status_Widget {

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
     * Method get_class_name()
     *
     * @return string __CLASS__ Class name.
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Method render()
     */
    public function render() {
        $this->render_widget();
    }


    /**
     * Render client overview Info.
     */
    public function render_widget() {
        $sites_count = MainWP_DB::instance()->get_websites_count();
        ?>
        <div class="mainwp-widget-header">
            <h2 class="ui header handle-drag">
                <?php esc_html_e( 'Site Connectivity Status', 'mainwp' ); ?>
                <div class="sub header">
                <?php esc_html_e( 'Number of total, connected, disconnected, and suspended sites across the network for easy status monitoring.', 'mainwp' ); ?>
                </div>
            </h2>
        </div>

        <div class="mainwp-widget-insights-card mainwp-scrolly-overflow">
                <?php
                /**
                 * Action: mainwp_logs_widget_top
                 *
                 * Fires at the top of the widget.
                 *
                 * @since 4.6
                 */
                do_action( 'mainwp_logs_widget_top', 'status' );
                ?>
                <div id="mainwp-message-zone" style="display:none;" class="ui message"></div>
                <?php
                MainWP_UI::generate_wp_nonce( 'mainwp-admin-nonce' );
                if ( 0 < intval( $sites_count ) ) {
                    $this->render_widget_content();
                } else {
                    MainWP_UI::render_empty_element_placeholder( __( 'No Connection Status Data', 'mainwp' ), '<a href="admin.php?page=managesites&do=new">' . __( 'Start connecting your sites now', 'mainwp' ) . '</a>', '<em data-emoji=":bar_chart:" class="medium"></em>' );
                }
                ?>
                <?php
                /**
                 * Action: mainwp_logs_widget_bottom
                 *
                 * Fires at the bottom of the widget.
                 *
                 * @since 4.6
                 */
                do_action( 'mainwp_logs_widget_bottom', 'status' );
                ?>
            </div>
        <div class="mainwp-widget-footer ui four columns stackable grid">
            <div class="column">
            </div>
            <div class="column">
            </div>
        </div>
        <?php
    }


    /**
     * Method render_widget_content()
     */
    public function render_widget_content() {
        $wp_stats = MainWP_DB::instance()->get_websites_stats_count(
            array(
                'count_disconnected' => true,
                'count_suspended'    => true,
            )
        );

        if ( ! is_array( $wp_stats ) ) {
            $wp_stats = array();
        }

        $total        = ! empty( $wp_stats['count_all'] ) ? intval( $wp_stats['count_all'] ) : 0;
        $disconnected = ! empty( $wp_stats['count_disconnected'] ) ? intval( $wp_stats['count_disconnected'] ) : 0;
        $suspended    = ! empty( $wp_stats['count_suspended'] ) ? intval( $wp_stats['count_suspended'] ) : 0;
        $connected    = $total - $disconnected - $suspended;
        ?>
        <div id="mainwp-module-log-chart-status-wrapper" ></div>

        <script type="text/javascript">
            jQuery( document ).ready( function() {
                let options = {
                    chart: {
                        type: 'pie',
                        height: 350,
                    },
                    legend: {
                        labels: {
                            colors: '#999'
                        }
                    },
                    stroke: {
                        width: 0
                    },
                    tooltip: {
                        theme: 'dark'
                    },
                    series: [ {
                        name: 'Sites',
                        data: [
                            { x: 'Total Sites',  y: <?php echo esc_js( $total ); ?>, fillColor: '#18a4e0' },
                            { x: 'Connected',    y: <?php echo esc_js( $connected ); ?>, fillColor: '#7fb100' },
                            { x: 'Disconnected', y: <?php echo esc_js( $disconnected ); ?>, fillColor: '#a61718' },
                            { x: 'Suspended',    y: <?php echo esc_js( $suspended ); ?>, fillColor: '#ffd300' },
                        ]
                    } ],
                }
                let status = new ApexCharts(document.querySelector("#mainwp-module-log-chart-status-wrapper"), options);
                setTimeout(() => {
                    status.render();
                }, 1000);
            } );
        </script>
        <?php
    }
}
