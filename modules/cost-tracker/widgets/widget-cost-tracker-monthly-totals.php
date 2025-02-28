<?php
/**
 * MainWP Upcoming Monthly Totals Widget
 *
 * Displays the Monthly Totals.
 *
 * @package MainWP/Dashboard
 * @version 4.6
 */

namespace MainWP\Dashboard\Module\CostTracker;

use MainWP\Dashboard\MainWP_UI;
use MainWP\Dashboard\MainWP_Utility;

/**
 * Class Cost_Tracker_Monthly_Totals
 *
 * Displays the Monthly Totals.
 */
class Cost_Tracker_Monthly_Totals {

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
                <?php esc_html_e( 'Monthly Totals', 'mainwp' ); ?>
                <div class="sub header">
                <?php esc_html_e( 'Track and forecast your monthly expenses by category for a year.', 'mainwp' ); ?>
                </div>
            </h2>
        </div>

        <div class="mainwp-scrolly-overflow">
                <?php
                /**
                 * Action: mainwp_module_cost_tracker_widget_top
                 *
                 * Fires at the top of the widget.
                 *
                 * @since 5.0.2
                 */
                do_action( 'mainwp_module_cost_tracker_widget_top', 'monthly-totals' );
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
                do_action( 'mainwp_module_cost_tracker_widget_bottom', 'monthly-totals' );
                ?>
            </div>
        <?php
    }

    /**
     * Get widget costs data.
     *
     * @param array $cost_data     Cost data.
     */
    public static function get_costs_widgets_data( $cost_data ) { //phpcs:ignore -- NOSONAR - complex.
        $categories            = array();
        $series_data           = array();
        $series_products_price = array();
        $colors                = array();
        $product_colors        = Cost_Tracker_Admin::get_product_colors();

        $product_types = Cost_Tracker_Admin::get_product_types();

        if ( is_array( $cost_data ) ) {
            $current_time = time();
            $time         = $current_time;
            for ( $i = 0; $i < 12; $i++ ) {
                $upcoming1    = strtotime( gmdate( 'Y-m-d 00:00:00', $time ) );
                $upcoming2    = strtotime( gmdate( 'Y-m-t 23:59:59', $time ) );
                $categories[] = esc_js( gmdate( 'M', $upcoming1 ) );
                $time         = $upcoming2 + 1;
            }

            foreach ( $cost_data as $cost ) {
                if ( ! isset( $series_data[ $cost->product_type ] ) ) {
                    $series_data[ $cost->product_type ]           = array(
                        'name' => isset( $product_types[ $cost->product_type ] ) ? esc_js( $product_types[ $cost->product_type ] ) : 'N/A',
                        'data' => array(),
                    );
                    $colors[ $cost->product_type ]                = isset( $product_colors[ $cost->product_type ] ) ? $product_colors[ $cost->product_type ] : '';
                    $series_products_price[ $cost->product_type ] = array();
                }

                $time       = $current_time;
                $cost_price = $cost->price;
                $next_rl    = $cost->next_renewal;

                for ( $i = 0; $i < 12; $i++ ) {

                    if ( ! isset( $series_products_price[ $cost->product_type ][ $i ] ) ) {
                        $series_products_price[ $cost->product_type ][ $i ] = 0;
                    }

                    $upcoming1 = strtotime( gmdate( 'Y-m-d 00:00:00', $time ) );
                    $upcoming2 = strtotime( gmdate( 'Y-m-t 23:59:59', $time ) );

                    if ( $next_rl <= $upcoming1 ) {
                        $next_rl = Cost_Tracker_Admin::get_next_renewal( $upcoming1, $cost->renewal_type, false );
                    }
                    $next_price = 0;
                    while ( $next_rl <= $upcoming2 ) {
                        if ( $next_rl > $upcoming1 && $next_rl <= $upcoming2 ) {
                            $next_price = $cost_price;
                        }
                        $series_products_price[ $cost->product_type ][ $i ] += $next_price;
                        $next_rl    = Cost_Tracker_Admin::get_next_renewal( $next_rl, $cost->renewal_type, false );
                        $next_price = 0;
                    }

                    $time = $upcoming2 + 1;
                    if ( $next_rl <= $upcoming2 ) {
                        $next_rl = Cost_Tracker_Admin::get_next_renewal( $upcoming2, $cost->renewal_type, false );
                    }
                }
            }
        }

        $dec = Cost_Tracker_Utility::cost_tracker_format_price( 0, true, array( 'get_decimals' => true ) );
        foreach ( $series_products_price as $product_type => $products_price ) {
            foreach ( $products_price as $idx => $price ) {
                if ( ! isset( $series_data[ $product_type ]['data'][ $idx ] ) ) {
                    $series_data[ $product_type ]['data'][ $idx ] = 0;
                }
                $series_data[ $product_type ]['data'][ $idx ] = round( $price, $dec );

            }
        }
        $cur_format = Cost_Tracker_Utility::cost_tracker_format_price( 0, true, array( 'get_currency_format' => true ) );
        return array(
            'series'          => array_values( $series_data ),
            'categories'      => $categories,
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
        <div id="mainwp-module-cost-tracker-monthly-totals-wrapper"></div>
        <script type="text/javascript">
            jQuery( document ).ready( function() {
                let cost_chart_colors = <?php echo wp_json_encode($chart_data['colors'], true ); //phpcs:ignore -- ok. ?>;
                let cost_chart_currency_format = '<?php echo esc_js($chart_data['currency_format']); //phpcs:ignore -- ok. ?>';

                let options = {
                        series: <?php echo wp_json_encode($chart_data['series'], true ); //phpcs:ignore -- ok. ?>,
                        chart: {
                                type: 'bar',
                                height: '95%',
                                stacked: true,
                        },
                        plotOptions: {
                            bar: {
                            horizontal: false,
                            borderRadius: 0,
                            dataLabels: {
                                total: {
                                enabled: true,
                                style: {
                                    fontSize: '14px',
                                    fontWeight: 900
                                }
                                }
                            }
                            },
                        },
                        xaxis: {
                            type: 'string',
                            categories: <?php echo wp_json_encode($chart_data['categories'], true ); //phpcs:ignore -- ok. ?>,
                            labels: {
                                style: {
                                    colors: '#999999',
                                },
                            },
                        },
                        yaxis:{
                            type: 'string',
                            labels: {
                                formatter: function (value) {
                                    return __(cost_chart_currency_format, value);
                                },
                                style: {
                                    colors: '#999999',
                                },
                            },
                        },
                        legend: { show: false },
                        fill: {
                            opacity: 1
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
                        ]
                    };

                let cost_chart = new ApexCharts(document.querySelector("#mainwp-module-cost-tracker-monthly-totals-wrapper"), options);
                setTimeout(() => {
                    cost_chart.render();
                }, 1000);
            } );
        </script>
        <?php
    }
}
