<?php
/**
 * MainWP Payment Left For This Month Widget
 *
 * Displays the Payment Left For This Month.
 *
 * @package MainWP/Dashboard
 * @version 4.6
 */

namespace MainWP\Dashboard\Module\CostTracker;

use MainWP\Dashboard\MainWP_UI;
use MainWP\Dashboard\MainWP_Utility;

/**
 * Class Cost_Tracker_Payment_Left_This_Month
 *
 * Displays the Payment Left For This Month.
 */
class Cost_Tracker_Payment_Left_This_Month {

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
     * Handle render costs widget.
     */
    public function render() {
        $this->render_widget();
    }

    /**
     * Render widget.
     */
    public function render_widget() {
        ?>
        <div class="mainwp-widget-header">
            <h2 class="ui header handle-drag">
                <?php esc_html_e( 'Payments Left For the Month', 'mainwp' ); ?>
                <div class="sub header">
                <?php esc_html_e( 'See upcoming costs left for this month.', 'mainwp' ); ?>
                </div>
            </h2>
        </div>

        <div class="mainwp-widget-insights-card">
                <?php
                /**
                 * Action: mainwp_module_cost_tracker_widget_top
                 *
                 * Fires at the top of the widget.
                 *
                 * @since 5.0.2
                 */
                do_action( 'mainwp_module_cost_tracker_widget_top', 'payment-left-for-this-month' );
                ?>
                <div id="mainwp-message-zone" style="display:none;" class="ui message"></div>
                <?php
                $this->render_widget_content();
                ?>
                <?php
                /**
                 * Action: mainwp_module_cost_tracker_widget_bottom
                 *
                 * Fires at the bottom of the widget.
                 *
                 * @since 5.0.2
                 */
                do_action( 'mainwp_module_cost_tracker_widget_bottom', 'payment-left-for-this-month' );
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
     * Get widget costs data.
     *
     * @param array $cost_data     Cost data.
     */
    public static function get_costs_widgets_data( $cost_data ) { // phpcs:ignore -- NOSONAR - complex.
        $series_products_price = array();
        $colors                = array();
        $series_data           = array();
        $product_colors        = Cost_Tracker_Admin::get_product_colors();
        $product_types         = Cost_Tracker_Admin::get_product_types();

        if ( is_array( $cost_data ) ) {
            $current_time = time();
            $upcoming1    = strtotime( gmdate( 'Y-m-01 00:00:00', $current_time ) );
            $upcoming2    = strtotime( gmdate( 'Y-m-t 23:59:59', $current_time ) );
            foreach ( $cost_data as $cost ) {

                if ( ! isset( $series_data[ $cost->product_type ] ) ) {
                    $series_data[ $cost->product_type ]           = array(
                        'name' => isset( $product_types[ $cost->product_type ] ) ? esc_js( $product_types[ $cost->product_type ] ) : 'N/A',
                    );
                    $colors[ $cost->product_type ]                = isset( $product_colors[ $cost->product_type ] ) ? $product_colors[ $cost->product_type ] : '';
                    $series_products_price[ $cost->product_type ] = 0;
                }

                $next_rl = $cost->next_renewal;

                if ( $next_rl <= $upcoming1 ) {
                    $next_rl = Cost_Tracker_Admin::get_next_renewal( $upcoming1, $cost->renewal_type, false );
                }

                $next_price = 0;
                while ( $next_rl <= $upcoming2 ) {
                    if ( $next_rl > $upcoming1 && $next_rl <= $upcoming2 ) {
                        $next_price = $cost->price;
                    }
                    $series_products_price[ $cost->product_type ] += $next_price;
                    $next_rl                                       = Cost_Tracker_Admin::get_next_renewal( $next_rl, $cost->renewal_type, false );
                    $next_price                                    = 0;
                }
            }
        }

        $dec = Cost_Tracker_Utility::cost_tracker_format_price( 0, true, array( 'get_decimals' => true ) );
        foreach ( $series_products_price as $product_type => $price ) {
            $series_data[ $product_type ]['data'][] = round( $price, $dec );
        }

        $categories = array( gmdate( 'M' ) );

        $cur_format = Cost_Tracker_Utility::cost_tracker_format_price( 0, true, array( 'get_currency_format' => true ) );
        return array(
            'series'          => array_values( $series_data ),
            'categories'      => array_values( $categories ),
            'colors'          => array_values( $colors ),
            'currency_format' => $cur_format['format'],
            'decimals'        => $cur_format['decimals'],
        );
    }

    /**
     * Method render_widget_content()
     */
    public function render_widget_content() {
        $costs_data = Cost_Tracker_DB::get_instance()->get_summary_data( array( 'sum_data' => 'all' ) );
        $chart_data = static::get_costs_widgets_data( $costs_data );
        ?>
        <div id="mainwp-module-cost-tracker-payment-left-for-this-month-wrapper"></div>
        <script type="text/javascript">
            jQuery( document ).ready( function() {

                let cost_chart_colors = <?php echo wp_json_encode($chart_data['colors'], true ); //phpcs:ignore -- ok. ?>;
                let cost_chart_currency_format = '<?php echo esc_js($chart_data['currency_format']); //phpcs:ignore -- ok. ?>';

                let options = {
                            series: <?php echo wp_json_encode($chart_data['series'], true ); //phpcs:ignore -- ok. ?>,
                            chart: {
                                type: 'bar',
                                height: 350,
                                stacked: true,
                            },
                            plotOptions: {
                                bar: {
                                horizontal: true,
                                dataLabels: {
                                    total: {
                                    enabled: true,
                                    offsetX: 0,
                                    style: {
                                        fontSize: '13px',
                                        fontWeight: 900
                                    }
                                    }
                                }
                                },
                            },
                            xaxis: {
                                categories: <?php echo wp_json_encode($chart_data['categories'], true ); //phpcs:ignore -- ok. ?>,
                                labels: {
                                    formatter: function (value) {
                                        return isNaN(value) ? value : __(cost_chart_currency_format, value ); // to fix month name format.
                                    },
                                    style: {
                                        colors: '#999999',
                                    },
                                },
                            },
                            yaxis:{
                                type: 'string',
                                labels: {
                                    formatter: function (value) {
                                        return isNaN(value) ? value : __(cost_chart_currency_format, value ); // to fix month name format.
                                    },
                                    style: {
                                        colors: '#999999',
                                    },
                                },
                            },
                            fill: {
                                opacity: 1
                            },
                            legend: {
                                show: false
                            },
                            tooltip: {
                                theme: 'dark'
                            },
                            colors: [
                                function ( { value, seriesIndex, dataPointIndex, w } ) {
                                    if (cost_chart_colors[seriesIndex] !== undefined) {
                                        return cost_chart_colors[seriesIndex];
                                    } else {
                                        return "#5ec130";
                                    }
                                }
                            ],
                        };

                let cost_chart = new ApexCharts(document.querySelector("#mainwp-module-cost-tracker-payment-left-for-this-month-wrapper"), options);
                setTimeout(() => {
                    cost_chart.render();
                }, 1000);
            } );
        </script>
        <?php
    }
}
