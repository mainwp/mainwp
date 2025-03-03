<?php
/**
 * MainWP Yearly Renewwals Widget
 *
 * Displays the Yearly Renewwals.
 *
 * @package MainWP/Dashboard
 * @version 5.0.1
 */

namespace MainWP\Dashboard\Module\CostTracker;

use MainWP\Dashboard\MainWP_UI;
use MainWP\Dashboard\MainWP_Utility;

/**
 * Class Cost_Tracker_Yearly_Renewals
 *
 * Displays the Yearly Renewwals.
 */
class Cost_Tracker_Yearly_Renewals { // phpcs:ignore -- NOSONAR - multi methods.

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
     * Renders the Upocmming renewals widget data
     */
    public static function render_top_grid() {
        ?>
        <div class="ui grid mainwp-widget-header">
            <div class="twelve wide column">
                <h2 class="ui header handle-drag">
                <?php
                /**
                 * Filter: mainwp_module_cost_tracker_yearly_renewals_widget_title
                 *
                 * Filters the widget title text.
                 *
                 * @since 5.0.1
                 */
                echo esc_html( apply_filters( 'mainwp_module_cost_tracker_yearly_renewals_widget_title', esc_html__( 'Upcoming Yearly Renewals', 'mainwp' ) ) );
                ?>
                <div class="sub header"><?php esc_html_e( 'Monitor your expenses - this widget highlights your upcoming yearly renewals.', 'mainwp' ); ?></div>
                </h2>
            </div>

            <div class="four wide column right aligned">
                <div class="ui dropdown right pointing mainwp-dropdown-tab not-auto-init" id="cost-tracker-widget-yearly-renewals-top-select">
                    <input type="hidden" value="yearly-renewals-month">
                        <i class="vertical ellipsis icon"></i>
                        <div class="menu">
                            <a class="item yearly_renewals_today_lnk" data-tab="yearly-renewals-today" data-value="yearly-renewals-today" title="<?php esc_attr_e( 'Today', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'Today', 'mainwp' ); ?></a>
                            <a class="item yearly_renewals_tomorrow_lnk" data-tab="yearly-renewals-tomorrow"  data-value="yearly-renewals-tomorrow" title="<?php esc_attr_e( 'Tomorrow', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'Tomorrow', 'mainwp' ); ?></a>
                            <a class="item yearly_renewals_week_lnk" data-tab="yearly-renewals-week"  data-value="yearly-renewals-week" title="<?php esc_attr_e( 'This Week', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'This Week', 'mainwp' ); ?></a>
                            <a class="item yearly_renewals_next_week_lnk" data-tab="yearly-renewals-next_week" data-value="yearly-renewals-next_week"  title="<?php esc_attr_e( 'Next Week', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'Next Week', 'mainwp' ); ?></a>
                            <a class="item yearly_renewals_month_lnk" data-tab="yearly-renewals-month" data-value="yearly-renewals-month"  title="<?php esc_attr_e( 'This Month', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'This Month', 'mainwp' ); ?></a>
                            <a class="item yearly_renewals_next_month_lnk" data-tab="yearly-renewals-next_month" data-value="yearly-renewals-next_month"  title="<?php esc_attr_e( 'Next Month', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'Next Month', 'mainwp' ); ?></a>
                            <a class="item yearly_renewals_year_lnk" data-tab="yearly-renewals-year" data-value="yearly-renewals-year" title="<?php esc_attr_e( 'This Year', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'This Year', 'mainwp' ); ?></a>
                            <a class="item yearly_renewals_next_year_lnk" data-tab="yearly-renewals-next_year" data-value="yearly-renewals-next_year"  title="<?php esc_attr_e( 'Next Year', 'mainwp' ); ?>" href="#"><?php esc_html_e( 'Next Year', 'mainwp' ); ?></a>
                        </div>
                </div>
            </div>
        </div>

        <script type="text/javascript">
            jQuery( document ).ready( function () {
                var $topSelect = jQuery( '#cost-tracker-widget-yearly-renewals-top-select' ).dropdown( {
                    onChange: function( value ) {
                        console.log('value:' + value);
                        mainwp_ui_state_save('cost-widget-yearly-renewals', value);
                    }
                } );

                var curTab = mainwp_ui_state_load('cost-widget-yearly-renewals');
                console.log('curTab:' + curTab);
                if(  curTab != '' && curTab != null ){
                    $topSelect.dropdown( 'set selected', curTab );
                    jQuery( '.cost_tracker_yearly_renewals').removeClass('active'); //to fix preset.
                    jQuery( '.cost_tracker_yearly_renewals[data-tab="' + curTab + '"]' ).addClass( 'active' );
                }

            } );
        </script>
        <?php
    }

    /**
     * Handle render costs widget.
     */
    public function render() {
        $args       = func_get_args();
        $data       = is_array( $args ) && ! empty( $args[1][0] ) && is_array( $args[1][0] ) ? $args[1][0] : array();
        $costs_data = is_array( $data ) && ! empty( $data['costs_data'] ) ? $data['costs_data'] : array();

        if ( ! is_array( $costs_data ) ) {
            $costs_data = array();
        }
        static::render_top_grid();
        ?>
        <div class="mainwp-scrolly-overflow">
        <?php
            $tabs = array( 'today', 'tomorrow', 'week', 'next_week', 'month', 'next_month', 'year', 'next_year' );
        foreach ( $tabs as $tab ) {
            $this->render_renewals_tab( $tab, $costs_data );
        }
        ?>
        </div>
        <div class="mainwp-widget-footer"></div>
        <?php
    }

    /**
     * Render upcoming renewals tabs.
     *
     * @param string $tab Tab.
     * @param array  $cost_data     Cost data.
     */
    public static function get_costs_widgets_data( $tab, $cost_data ) { //phpcs:ignore -- NOSONAR - complex.
        $list = array();
        if ( is_array( $cost_data ) ) {
            $time      = time();
            $upcoming1 = 0;
            $upcoming2 = 0;
            if ( 'today' === $tab ) {
                $upcoming1 = strtotime( gmdate( 'Y-m-d 00:00:00', $time ) );
                $upcoming2 = strtotime( gmdate( 'Y-m-d 23:59:59', $time ) );
            } elseif ( 'tomorrow' === $tab ) {
                $upcoming1 = strtotime( gmdate( 'Y-m-d 00:00:00', strtotime( '+1 day', $time ) ) );
                $upcoming2 = strtotime( gmdate( 'Y-m-d 23:59:59', strtotime( '+1 day', $time ) ) );
            } elseif ( 'week' === $tab ) {
                $upcoming1 = strtotime( gmdate( 'Y-m-d 00:00:00', strtotime( 'monday this week' ) ) );
                $upcoming2 = strtotime( gmdate( 'Y-m-d 23:59:59', strtotime( 'sunday this week' ) ) );
            } elseif ( 'next_week' === $tab ) {
                $upcoming1 = strtotime( gmdate( 'Y-m-d 00:00:00', strtotime( 'first day of next week' ) ) );
                $upcoming2 = strtotime( gmdate( 'Y-m-d 23:59:59', strtotime( 'last day of next week' ) ) );
            } elseif ( 'month' === $tab ) {
                $upcoming1 = strtotime( gmdate( 'Y-m-01 00:00:00', $time ) );
                $upcoming2 = strtotime( gmdate( 'Y-m-t 23:59:59', $time ) );
            } elseif ( 'next_month' === $tab ) {
                $upcoming1 = strtotime( gmdate( 'Y-m-d 23:59:59', strtotime( 'last day of this month' ) ) );
                $upcoming2 = strtotime( gmdate( 'Y-m-d 23:59:59', strtotime( 'last day of next month' ) ) );
            } elseif ( 'year' === $tab ) {
                $upcoming1 = strtotime( gmdate( 'Y-01-01 00:00:00', $time ) );
                $upcoming2 = strtotime( gmdate( 'Y-12-t 23:59:59', $time ) );
            } elseif ( 'next_year' === $tab ) {
                $upcoming1 = strtotime( gmdate( 'Y-m-d 00:00:00', strtotime( 'first day of January ' . gmdate( 'Y' ) . '+1 year' ) ) );
                $upcoming2 = strtotime( gmdate( 'Y-m-d 23:59:59', strtotime( 'last day of December ' . gmdate( 'Y' ) . '+1 year' ) ) );
            }
            if ( $upcoming1 ) {
                foreach ( $cost_data as $cost ) {
                    if ( 'yearly' !== $cost->renewal_type ) {
                        continue;
                    }
                    $next_renewal = Cost_Tracker_Admin::get_next_renewal( $cost->last_renewal, $cost->renewal_type );
                    if ( $next_renewal > $upcoming1 && $next_renewal <= $upcoming2 ) {
                        $list[] = $cost;
                    }
                }
            }
        }
        return $list;
    }

    /**
     * Render upcoming renewals tabs.
     *
     * @param string $tab Tab.
     * @param array  $cost_data     Cost data.
     */
    public static function render_renewals_tab( $tab, $cost_data ) {
        $lists = static::get_costs_widgets_data( $tab, $cost_data );
        ?>
        <div class="cost_tracker_yearly_renewals ui middle aligned tab <?php echo 'month' === $tab ? 'active' : ''; ?>" data-tab="yearly-renewals-<?php echo esc_attr( $tab ); ?>">
            <?php
            /**
             * Action: mainwp_module_yearly_renewals_before_costs_list
             *
             * Fires before the list of costs.
             *
             * @param string $tab Tab.
             * @param array  $cost_data     Cost data.
             *
             * @since 5.0.2
             */
            do_action( 'mainwp_module_yearly_renewals_before_costs_list', $tab, $cost_data );
            if ( empty( $lists ) ) {
                MainWP_UI::render_empty_element_placeholder( __( 'No upcoming renewals for the selected priod.', 'mainwp' ) );
            } else {
                ?>
                <table class="ui stacking table" id="mainwp-yearly-renewals-table-<?php echo esc_attr( $tab ); ?>">
                    <thead>
                        <tr>
                            <th scope="col" ><?php echo esc_html__( 'Name', 'mainwp' ); ?></th>
                            <th scope="col" ><?php echo esc_html__( 'Renews at', 'mainwp' ); ?></th>
                            <th scope="col" class="collapsing right aligned"><?php echo esc_html__( 'Price', 'mainwp' ); ?></th>
                        </tr>
                    </thead>
                <?php
                foreach ( $lists as $item ) {
                    $next_renewal = Cost_Tracker_Admin::get_next_renewal( $item->last_renewal, $item->renewal_type );
                    ?>
                    <tr>
                        <td><a href="admin.php?page=CostTrackerAdd&id=<?php echo intval( $item->id ); ?>"><?php echo esc_html( $item->name ); ?></a></td>
                        <td  data-order="<?php echo esc_attr( $next_renewal ); ?>"><?php echo MainWP_Utility::format_date( $next_renewal ); //phpcs:ignore -- ok. ?></td>
                        <td class="right aligned"><?php Cost_Tracker_Utility::cost_tracker_format_price( $item->price ); ?></td>
                    </tr>
                    <?php
                }
                ?>
                </table>
                <script type="text/javascript">
                jQuery( document ).ready( function() {
                    jQuery( '#mainwp-yearly-renewals-table-today' ).DataTable();
                    jQuery( '#mainwp-yearly-renewals-table-tomorrow' ).DataTable();
                    jQuery( '#mainwp-yearly-renewals-table-week' ).DataTable();
                    jQuery( '#mainwp-yearly-renewals-table-next_week' ).DataTable();
                    jQuery( '#mainwp-yearly-renewals-table-month' ).DataTable();
                    jQuery( '#mainwp-yearly-renewals-table-next_month' ).DataTable();
                    jQuery( '#mainwp-yearly-renewals-table-year' ).DataTable();
                    jQuery( '#mainwp-yearly-renewals-table-next_year' ).DataTable();
                } );
                </script>
                <?php
            }
            /**
             * Action: mainwp_module_yearly_renewals_after_costs_list
             *
             * Fires after the list of costs.
             *
             * @param string $tab Tab.
             * @param array  $cost_data     Cost data.
             *
             * @since 5.0.1
             */
            do_action( 'mainwp_module_yearly_renewals_after_costs_list', $tab, $cost_data );
            ?>
        </div>
        <?php
    }
}
