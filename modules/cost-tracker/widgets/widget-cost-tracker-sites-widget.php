<?php
/**
 * MainWP Logs Widget
 *
 * Displays the Logs Info.
 *
 * @package MainWP/Dashboard
 * @version 4.6
 */

namespace MainWP\Dashboard\Module\CostTracker;

use MainWP\Dashboard\MainWP_Utility;

/**
 * Class Cost_Tracker_Sites_Widget
 *
 * Displays the Logs info.
 */
class Cost_Tracker_Sites_Widget {

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
     * Method callback_render_tasks_client_page_widget().
     *
     * Handle callback render tasks client page widget.
     */
    public function callback_render_costs_widget() {
        if ( ! isset( $_GET['page'] ) || 'managesites' !== $_GET['page'] || empty( $_GET['dashboard'] ) ) { //phpcs:ignore -- ok.
            return;
        }

        ?>
        <div class="ui grid mainwp-widget-header">
            <div class="twelve wide column">
                <h2 class="ui header handle-drag">
                    <?php esc_html_e( 'Cost Tracker', 'mainwp' ); ?>
                    <div class="sub header"><?php esc_html_e( 'Manage and monitor your expenses', 'mainwp' ); ?></div>
                </h2>
            </div>
        </div>
        <div class="mainwp-scrolly-overflow">
        <?php $this->render_costs_tracker_widget_content(); ?>
        </div>
        <div class="ui two columns grid mainwp-widget-footer">
            <div class="left aligned column">
                <a href="admin.php?page=ManageCostTracker" class="ui mini basic button"><?php esc_html_e( 'Cost Tracker Dashboard', 'mainwp' ); ?></a>
            </div>
        </div>
        <?php
    }

    /**
     * Method render_tasks_client_page_widget_content().
     */
    public function render_costs_tracker_widget_content() {
        $site_id = intval( $_GET['dashboard'] ); //phpcs:ignore -- ok.
        $site_costs = Cost_Tracker_DB::get_instance()->get_all_cost_trackers_by_sites( array( $site_id ) );

        if ( is_array( $site_costs ) ) {
            $site_costs = current( $site_costs ); // for current site.
        }

        if ( ! is_array( $site_costs ) ) {
            $site_costs = array();
        }

        ?>
        <table class="ui table" id="mainwp-module-cost-tracker-costs-widget-table">
            <thead>
                <tr>
                    <th scope="col" ><?php esc_html_e( 'Product', 'mainwp' ); ?></th>  <?php //phpcs:ignore -- to fix WordPress word. ?>
                    <th scope="col" class="collapsing right aligned"><?php esc_html_e( 'Site Cost', 'mainwp' ); ?></th>
                    <th scope="col" class="no-sort collapsing"></th>
                </tr>
            </thead>
            <tbody>
            <?php
            if ( ! empty( $site_costs ) ) {
                $columns = array(
                    'name',
                    'price',
                    'actions',
                );
                foreach ( $site_costs as $cost ) {
                    $item = $cost;
                    ?>
                    <tr>
                        <?php
                        foreach ( $columns as $col ) {
                            $row = $this->column_default( $item, $col );
                            echo $row; // phpcs:ignore -- ok.
                        }
                        ?>
                    </tr>
                    <?php
                }
            }
            ?>
            </tbody>
        </table>
        <script type="text/javascript">
            jQuery( document ).ready( function () {

                jQuery( '#mainwp-module-cost-tracker-costs-widget-table' ).DataTable( {
                    "lengthMenu": [ [5, 10, 25, 50, 100, -1], [5, 10, 25, 50, 100, "All"] ],
                    "stateSave" : true,
                    "order"     : [ [1, 'asc'] ],
                    "columnDefs": [ {
                        "targets": 'no-sort',
                        "orderable": false
                    } ],
                    "drawCallback": function () {
                        mainwp_datatable_fix_menu_overflow('#mainwp-module-cost-tracker-costs-widget-table', -60, 5);
                        jQuery( '#mainwp-module-cost-tracker-costs-widget-table .ui.dropdown' ).dropdown();
                    }
                } );
                // to prevent events conflict.
                setTimeout( function () {
                    mainwp_datatable_fix_menu_overflow('#mainwp-module-cost-tracker-costs-widget-table', -60, 5);
                }, 1000 );
            } );
        </script>
        <?php
    }


    /**
     * Returns the column content for the provided item and column.
     *
     * @param array  $item         Record data.
     * @param string $column_name  Column name.
     * @return string $out Output.
     */
    public function column_default( $item, $column_name ) {
        $out       = '';
        $order_val = 0;
        switch ( $column_name ) {
            case 'name':
                $out = esc_html( $item->name );
                break;
            case 'price': // for client widget.
                $array_costs = array(
                    'weekly'    => 0,
                    'monthly'   => 0,
                    'quarterly' => 0,
                    'yearly'    => 0,
                    'lifetime'  => 0,
                );

                if ( 'active' === $item->cost_status ) {
                    $price = 0;
                    if ( 'single_site' === $item->license_type ) {
                        $price = $item->price;
                    } elseif ( 'multi_site' === $item->license_type && ! empty( $item->count_sites ) ) {
                        $price = $item->price / $item->count_sites;
                    }
                    if ( 'lifetime' === $item->type ) {
                        $array_costs['lifetime'] += $price;
                    } elseif ( isset( $array_costs[ $item->renewal_type ] ) ) {
                        $array_costs[ $item->renewal_type ] += $price;
                    }
                    $order_val += $price;
                }
                $out = Cost_Tracker_Utility::get_separated_costs_price( $array_costs );
                break;
            case 'actions':
                ob_start();
                ?>
                    <div class="ui right pointing dropdown" style="z-index: 99;">
                            <i class="ellipsis vertical icon"></i>
                            <div class="menu">
                                <a class="item widget-row-cost-tracker-edit-cost" href="admin.php?page=CostTrackerAdd&id=<?php echo intval( $item->id ); ?>"><?php esc_html_e( 'Edit', 'mainwp' ); ?></a>
                            </div>
                        </div>
                    <?php
                    $out = ob_get_clean();
                break;
            default:
        }

        if ( 'price' === $column_name ) {
            $out = '<td data-order="' . esc_attr( $order_val ) . '">' . $out . '</td>';
        } else {
            $out = '<td>' . $out . '</td>';
        }
        return $out;
    }
}
