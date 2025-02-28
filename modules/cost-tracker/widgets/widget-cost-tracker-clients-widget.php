<?php
/**
 * MainWP Cost Tracker Clients Widget
 *
 * Displays the Clients's Cost Tracker Info.
 *
 * @package MainWP/Dashboard
 * @version 4.6
 */

namespace MainWP\Dashboard\Module\CostTracker;

use MainWP\Dashboard\MainWP_DB_Client;
use MainWP\Dashboard\MainWP_Utility;

/**
 * Class Cost_Tracker_Clients_Widget
 */
class Cost_Tracker_Clients_Widget {

    /**
     * Public static variable to hold the single instance of class.
     *
     * @var mixed Default null
     */
    public static $instance = null;

    /**
     * Public static variable to hold the clients costs.
     *
     * @var mixed Default array().
     */
    public $clients_sites = array();

    /**
     * Method instance().
     *
     * Get instance class.
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Constructor class.
     *
     * @return void
     */
    public function __construct() {
        // construct.
    }


    /**
     * Method callback_render_tasks_client_page_widget().
     *
     * Handle callback render tasks client page widget.
     */
    public function callback_render_costs_widget() {
        if ( ! isset( $_GET['page'] ) || 'ManageClients' !== $_GET['page'] || empty( $_GET['client_id'] ) ) { //phpcs:ignore -- ok.
            return;
        }

        ?>
        <div class="ui grid mainwp-widget-header">
            <div class="twelve wide column">
                <h2 class="ui header handle-drag">
                    <?php esc_html_e( 'Cost Tracker', 'mainwp' ); ?>
                    <div class="sub header"><?php esc_html_e( 'Manage and monitor your expenses.', 'mainwp' ); ?></div>
                </h2>
            </div>
        </div>
        <div class="mainwp-scrolly-overflow">
        <?php $this->render_costs_tracker_widget_content(); ?>
        </div>
        <div class="ui two columns grid mainwp-widget-footer">
            <div class="left aligned column">
                <a href="admin.php?page=ManageCostTracker" class="ui basic mini button"><?php esc_html_e( 'Cost Tracker Dashboard', 'mainwp' ); ?></a>
            </div>
        </div>
        <?php
    }


    /**
     * Method render_tasks_client_page_widget_content().
     */
    public function render_costs_tracker_widget_content() {
        $client_id = intval( $_GET['client_id'] ); //phpcs:ignore -- ok.

        $this->clients_sites = array();
        // get all sites of the $client_id.
        $cls_sites = MainWP_DB_Client::instance()->get_websites_by_client_ids( array( $client_id ) );
        if ( is_array( $cls_sites ) ) {
            foreach ( $cls_sites as $cls_site ) {
                if ( ! empty( $cls_site->client_id ) && ! isset( $this->clients_sites[ $cls_site->client_id ] ) ) {
                    $this->clients_sites[ $cls_site->client_id ] = array();
                }
                $this->clients_sites[ $cls_site->client_id ][] = $cls_site->id;
            }
        }

        $client_costs = Cost_Tracker_DB::get_instance()->get_all_cost_trackers_by_clients( array( $client_id ), array( 'get_cost_sites' => true ) );

        if ( is_array( $client_costs ) ) {
            $client_costs = current( $client_costs ); // for current client.
        }

        if ( ! is_array( $client_costs ) ) {
            $client_costs = array();
        }

        ?>
        <table class="ui table" id="mainwp-module-cost-tracker-costs-widget-table">
            <thead>
                <tr>
                    <th scope="col" ><?php esc_html_e( 'Product', 'mainwp' ); ?></th>  <?php //phpcs:ignore -- to fix WordPress word. ?>
                    <th scope="col" class="collapsing center aligned"><?php esc_html_e( 'Client Sites', 'mainwp' ); ?></th>
                    <th scope="col" class="collapsing right aligned"><?php esc_html_e( 'Cost', 'mainwp' ); ?></th>
                    <th scope="col" class="no-sort collapsing"></th>
                </tr>
            </thead>
            <tbody>
            <?php
            if ( ! empty( $client_costs ) ) {
                $columns = array(
                    'name',
                    'number_sites',
                    'price',
                    'actions',
                );
                foreach ( $client_costs as $cost ) {
                    $item = $cost;
                    ?>
                    <tr>
                        <?php
                        foreach ( $columns as $col ) {
                            $row = $this->column_default( $item, $col, $client_id );
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
                    "info": false,
                    "layout": {
                        "bottom": 'paging',
                        "bottomStart": null,
                        "bottomEnd": null
                    },
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
     * @param int    $client_id  Client id.
     *
     * @return string $out Output.
     */
    public function column_default( $item, $column_name, $client_id ) { //phpcs:ignore -- NOSONAR - complex.
        $out = '';

        $order_val = 0;
        switch ( $column_name ) {
            case 'name':
                $out = esc_html( $item->name );
                break;
            case 'number_sites': // for client widget.
                $out = property_exists( $item, 'number_client_costs_sites' ) && is_array( $item->number_client_costs_sites ) ? count( $item->number_client_costs_sites ) : 0;
                break;
            case 'price': // for client widget.
                $array_costs = array(
                    'weekly'    => 0,
                    'monthly'   => 0,
                    'quarterly' => 0,
                    'yearly'    => 0,
                    'lifetime'  => 0,
                );

                if ( 'active' === $item->cost_status && is_array( $item->cost_sites_ids ) ) {
                    foreach ( $item->cost_sites_ids as $ct_siteid ) {
                        // if site of cost tracker in the client's sites then calculate the site's cost/price.
                        if ( is_array( $this->clients_sites[ $client_id ] ) && in_array( $ct_siteid, $this->clients_sites[ $client_id ] ) ) {
                            $cost_val = 0;
                            if ( 'single_site' === $item->license_type ) {
                                $cost_val = $item->price;
                            } elseif ( 'multi_site' === $item->license_type && ! empty( $item->count_sites ) ) {
                                $cost_val = $item->price / $item->count_sites;
                            }
                            if ( 'lifetime' === $item->type ) {
                                $array_costs['lifetime'] += $cost_val;
                            } elseif ( isset( $array_costs[ $item->renewal_type ] ) ) {
                                $array_costs[ $item->renewal_type ] += $cost_val;
                            }
                            $order_val += $cost_val;
                        }
                    }
                }
                $out = Cost_Tracker_Utility::get_separated_costs_price( $array_costs );
                break;
            case 'actions':
                ob_start();
                ?>
                <div class="ui right pointing dropdown not-auto-init" style="z-index: 99;">
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
