<?php
/**
 * MainWP Module Cost Tracker Dashboard class.
 *
 * @package MainWP\Dashboard
 * @version 4.6
 */

namespace MainWP\Dashboard\Module\CostTracker;

use MainWP\Dashboard\MainWP_Post_Handler;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Utility;
use MainWP\Dashboard\MainWP_DB_Client;

/**
 * Class Cost_Tracker_Dashboard
 */
class Cost_Tracker_Dashboard { // phpcs:ignore -- NOSONAR - multi methods.
    // phpcs:disable Generic.Metrics.CyclomaticComplexity -- complexity.
    /**
     * Variable to hold the items.
     *
     * @var mixed Default empty.
     */
    private $items = null;

    /**
     * Variable to hold the total items.
     *
     * @var mixed Default empty.
     */
    private $total_items = 0;

    /**
     * Variable to hold the order.
     *
     * @var mixed Default empty.
     */
    private static $order = '';

    /**
     * Variable to hold the order by.
     *
     * @var mixed Default empty.
     */
    private static $orderby = '';

    /**
     * Static variable to hold the single instance of the class.
     *
     * @static
     *
     * @var mixed Default null
     */
    private static $instance = null;

    /**
     * Get Instance
     *
     * Creates public static instance.
     *
     * @static
     *
     * @return Cost_Tracker_Dashboard
     */
    public static function get_instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Constructor
     *
     * Runs each time the class is called.
     */
    public function __construct() {
        add_action( 'admin_init', array( &$this, 'admin_init' ) );
    }

    /**
     * Method admin_init()
     *
     * Admin init.
     */
    public function admin_init() {
        MainWP_Post_Handler::instance()->add_action( 'mainwp_module_cost_tracker_notes_save', array( $this, 'ajax_notes_save' ) );
        MainWP_Post_Handler::instance()->add_action( 'mainwp_module_cost_tracker_delete', array( $this, 'ajax_cost_tracker_delete' ) );
        MainWP_Post_Handler::instance()->add_action( 'mainwp_module_cost_tracker_lists_display_rows', array( $this, 'ajax_display_rows' ) );
        MainWP_Post_Handler::instance()->add_action( 'mainwp_module_cost_tracker_filter_save_segment', array( $this, 'ajax_costs_filter_save_segment' ) );
        MainWP_Post_Handler::instance()->add_action( 'mainwp_module_cost_tracker_filter_load_segments', array( $this, 'ajax_costs_filter_load_segments' ) );
        MainWP_Post_Handler::instance()->add_action( 'mainwp_module_cost_tracker_filter_delete_segment', array( $this, 'ajax_costs_filter_delete_segment' ) );
    }


    /**
     * Get columns.
     *
     * @param bool $filtered With per site price column.
     *
     * @return array Array of column names.
     */
    public static function get_columns( $filtered = false ) {
        $cols = array(
            'cb'             => true,
            'cost_status'    => esc_html__( 'Status', 'mainwp' ),
            'icon'           => esc_html__( 'Icon', 'mainwp' ),
            'name'           => esc_html__( 'Name', 'mainwp' ),
            'per_site_price' => esc_html__( 'Price', 'mainwp' ),
            'price'          => esc_html__( 'Price', 'mainwp' ),
            'license_type'   => esc_html__( 'License', 'mainwp' ),
            'product_type'   => esc_html__( 'Category', 'mainwp' ),
            'type'           => esc_html__( 'Type', 'mainwp' ),
            'last_renewal'   => esc_html__( 'Last Renewal', 'mainwp' ),
            'payment_method' => esc_html__( 'Payment method', 'mainwp' ),
            'next_renewal'   => esc_html__( 'Next Renewal', 'mainwp' ),
            'sites'          => esc_html__( 'Sites', 'mainwp' ),
            'actions'        => esc_html__( 'Action', 'mainwp' ),
        );

        if ( ! $filtered ) {
            unset( $cols['per_site_price'] );
        }
        return $cols;
    }


    /**
     * Get column defines.
     *
     * @return array $defines
     */
    public function get_columns_defines() {
        $defines   = array();
        $defines[] = array(
            'targets'   => 'no-sort',
            'orderable' => false,
        );
        $defines[] = array(
            'targets'   => 'title-column',
            'className' => 'task-row-working',
        );
        $defines[] = array(
            'targets'   => 'date-column',
            'className' => 'mainwp-date-cell',
        );
        $defines[] = array(
            'targets'   => 'state-column',
            'className' => 'mainwp-state-cell',
        );
        $defines[] = array(
            'targets'   => array( 'column-sites', 'column-payment-method', 'column-license-type', 'column-icon' ),
            'className' => 'center aligned',
        );
        $defines[] = array(
            'targets'   => array( 'column-site-price', 'column-price' ),
            'className' => 'right aligned',
        );
        $defines[] = array(
            'targets'   => 'check-column',
            'className' => 'check-column',
        );
        $defines[] = array(
            'targets'   => 'column-actions',
            'className' => 'collapsing not-selectable',
        );

        return $defines;
    }

    /**
     * Instantiate Columns.
     *
     * @param bool $filtered With per site price column.
     *
     * @return array $init_cols
     */
    public function get_columns_init( $filtered ) {
        $cols      = $this->get_columns( $filtered );
        $init_cols = array();
        foreach ( $cols as $key => $val ) {
            $init_cols[] = array( 'data' => esc_html( $key ) );
        }
        return $init_cols;
    }

    /**
     * Renders overview.
     *
     * When the page loads render the body content.
     */
    public function render_overview_page() {
        if ( ! \mainwp_current_user_can( 'dashboard', 'manage_cost_tracker' ) ) {
            \mainwp_do_not_have_permissions( esc_html__( 'manage cost tracker', 'mainwp' ) );
            return;
        }

        $sel_ids = isset( $_GET['selected_ids'] ) ? $_GET['selected_ids'] : ''; //phpcs:ignore -- ok.
        $sel_ids = explode( ',', $sel_ids );

        if ( ! empty( $sel_ids ) && is_array( $sel_ids ) ) {
            global $current_user;
            $sel_ids = MainWP_Utility::array_numeric_filter( $sel_ids );
            if ( ! empty( $sel_ids ) ) {
                update_user_option( $current_user->ID, 'mainwp_module_cost_tracker_onetime_filters_saved', $sel_ids );
            }
        }
        Cost_Tracker_Admin::render_header();
        ?>
        <div id="mainwp-module-cost-tracker-dashboard-tab">
            <?php static::render_manage_tasks_table_top( $sel_ids ); ?>
            <?php $this->render_actions_bar(); ?>
                <div class="ui segment">
                <?php $this->render_dashboard_body(); ?>
                </div>
            </div>
        <?php
    }

    /**
     * Method ajax_display_rows()
     *
     * Handle display table rows.
     */
    public function ajax_display_rows() { //phpcs:ignore -- NOSONAR - complex.

        MainWP_Post_Handler::instance()->check_security( 'mainwp_module_cost_tracker_lists_display_rows' );
        $filtered_one_time_ids = get_user_option( 'mainwp_module_cost_tracker_onetime_filters_saved' );
        if ( ! empty( $filtered_one_time_ids ) && is_array( $filtered_one_time_ids ) ) {
            global $current_user;
            delete_user_option( $current_user->ID, 'mainwp_module_cost_tracker_onetime_filters_saved' );
            $filtered_one_time_ids = MainWP_Utility::array_numeric_filter( $filtered_one_time_ids );
        }
        $req_orderby = '';
        $req_order   = null;
        // phpcs:disable WordPress.Security.NonceVerification
        if ( isset( $_REQUEST['order'] ) ) {
            $order_values = MainWP_Utility::instance()->get_table_orders( $_REQUEST );
            $req_orderby  = $order_values['orderby'];
            $req_order    = $order_values['order'];
        }

        $filters = static::get_cost_filter_params();

        $get_saved = true;
        foreach ( $filters as $filter ) {
            if ( isset( $_REQUEST[ $filter ] ) ) {
                $get_saved = false;
                break;
            }
        }

        $filter_sites_ids       = '';
        $filter_client_ids      = '';
        $filter_prod_type_slugs = '';
        $filter_cost_state      = '';
        $filter_dtsstart        = '';
        $filter_dtsstop         = '';

        $filter_license_type     = '';
        $filter_payment_method   = '';
        $filter_sub_renewal_type = '';

        if ( $get_saved ) {
            $filters_saved = get_user_option( 'mainwp_module_cost_tracker_filters_saved' );
            if ( ! is_array( $filters_saved ) ) {
                $filters_saved = array();
            }

            $filter_sites_ids       = isset( $filters_saved['sites_ids'] ) ? $filters_saved['sites_ids'] : false;
            $filter_client_ids      = isset( $filters_saved['client_ids'] ) ? $filters_saved['client_ids'] : false;
            $filter_prod_type_slugs = isset( $filters_saved['prods_types'] ) ? $filters_saved['prods_types'] : false;
            $filter_cost_state      = isset( $filters_saved['costs_state'] ) ? $filters_saved['costs_state'] : '';
            $filter_dtsstart        = isset( $filters_saved['dtsstart'] ) ? $filters_saved['dtsstart'] : '';
            $filter_dtsstop         = isset( $filters_saved['dtsstop'] ) ? $filters_saved['dtsstop'] : '';

            $filter_license_type     = isset( $filters_saved['license_types'] ) && ! empty( $filters_saved['license_types'] ) ? $filters_saved['license_types'] : '';
            $filter_sub_renewal_type = isset( $filters_saved['renewal_frequency'] ) && ! empty( $filters_saved['renewal_frequency'] ) ? $filters_saved['renewal_frequency'] : '';
            $filter_payment_method   = isset( $filters_saved['payment_methods'] ) && ! empty( $filters_saved['payment_methods'] ) ? $filters_saved['payment_methods'] : '';

        } else {
            // phpcs:disable WordPress.Security.NonceVerification
            $filter_sites_ids       = isset( $_REQUEST['sites'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['sites'] ) ) : '';
            $filter_client_ids      = isset( $_REQUEST['client'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['client'] ) ) : '';
            $filter_prod_type_slugs = isset( $_REQUEST['prods_types'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['prods_types'] ) ) : '';
            $filter_cost_state      = isset( $_REQUEST['costs_state'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['costs_state'] ) ) : '';
            $filter_dtsstart        = isset( $_REQUEST['dtsstart'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['dtsstart'] ) ) : '';
            $filter_dtsstop         = isset( $_REQUEST['dtsstop'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['dtsstop'] ) ) : '';

            $filter_license_type     = isset( $_REQUEST['license_types'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['license_types'] ) ) : '';
            $filter_sub_renewal_type = isset( $_REQUEST['renewal_frequency'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['renewal_frequency'] ) ) : '';
            $filter_payment_method   = isset( $_REQUEST['payment_methods'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['payment_methods'] ) ) : '';

            // phpcs:enable WordPress.Security.NonceVerification
        }

        $array_sites_ids        = array();
        $array_clients_ids      = array();
        $array_prod_types_slugs = array();
        $array_status_slugs     = array();

        $array_license_type     = array();
        $array_payment_method   = array();
        $array_sub_renewal_type = array();

        if ( ! empty( $filter_sites_ids ) ) {
            $array_sites_ids = explode( ',', $filter_sites_ids ); // convert to array.
            if ( in_array( 'nosites', $array_sites_ids, true ) ) {
                $array_sites_ids  = false;
                $filter_sites_ids = '';
            }
        }

        if ( ! empty( $filter_client_ids ) ) {
            $array_clients_ids = explode( ',', $filter_client_ids ); // convert to array.
            if ( in_array( 'noclients', $array_clients_ids, true ) ) {
                $array_clients_ids = false;
                $filter_client_ids = '';
            }
        }

        if ( ! empty( $filter_prod_type_slugs ) ) {
            $array_prod_types_slugs = explode( ',', $filter_prod_type_slugs ); // convert to array.
            if ( in_array( 'nocategories', $array_prod_types_slugs, true ) ) {
                $array_prod_types_slugs = false;
                $filter_prod_type_slugs = '';
            }
        }

        if ( ! empty( $filter_cost_state ) ) {
            $array_status_slugs = explode( ',', $filter_cost_state ); // convert to array.
            if ( in_array( 'nostatus', $array_status_slugs, true ) ) {
                $array_status_slugs = false;
                $filter_cost_state  = '';
            }
        }

        if ( ! empty( $filter_license_type ) ) {
            $array_license_type = explode( ',', $filter_license_type ); // convert to array.
            if ( in_array( 'nolicensetypes', $array_license_type, true ) ) {
                $array_license_type  = false;
                $filter_license_type = '';
            }
        }
        if ( ! empty( $filter_payment_method ) ) {
            $array_payment_method = explode( ',', $filter_payment_method ); // convert to array.
            if ( in_array( 'nopaymentmenthods', $array_payment_method, true ) ) {
                $array_payment_method  = false;
                $filter_payment_method = '';
            }
        }
        if ( ! empty( $filter_sub_renewal_type ) ) {
            $array_sub_renewal_type = explode( ',', $filter_sub_renewal_type ); // convert to array.
            if ( in_array( 'norenewalfrequency', $array_sub_renewal_type, true ) ) {
                $array_sub_renewal_type  = false;
                $filter_sub_renewal_type = '';
            }
        }

        global $current_user;

        if ( empty( $filtered_one_time_ids ) ) {
            update_user_option(
                $current_user->ID,
                'mainwp_module_cost_tracker_filters_saved',
                array(
                    'sites_ids'         => $filter_sites_ids,
                    'client_ids'        => $filter_client_ids,
                    'prods_types'       => $filter_prod_type_slugs,
                    'costs_state'       => $filter_cost_state,
                    'dtsstart'          => $filter_dtsstart,
                    'dtsstop'           => $filter_dtsstop,
                    'license_types'     => $filter_license_type,
                    'payment_methods'   => $filter_payment_method,
                    'renewal_frequency' => $filter_sub_renewal_type,
                )
            );
        }

        // phpcs:enable

         // phpcs:disable WordPress.Security.NonceVerification
        $per_page = isset( $_REQUEST['length'] ) ? intval( $_REQUEST['length'] ) : 25;

        if ( -1 === $per_page ) {
            $per_page = 9999;
        }

        $start  = isset( $_REQUEST['start'] ) ? intval( $_REQUEST['start'] ) : 0;
        $search = isset( $_REQUEST['search']['value'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['search']['value'] ) ) : '';

        // phpcs:enable WordPress.Security.NonceVerification

        $args = array(
            'order'                 => ( 'asc' === $req_order ) ? 'asc' : 'desc',
            'orderby'               => $req_orderby,
            'start'                 => $start,
            'search'                => $search,
            'dtsstart'              => ! empty( $filter_dtsstart ) ? $filter_dtsstart : false,
            'dtsstop'               => ! empty( $filter_dtsstop ) ? $filter_dtsstop : false,
            'filter_clients'        => $array_clients_ids,
            'filter_sites'          => $array_sites_ids,
            'filter_prods_types'    => $array_prod_types_slugs,
            'filter_states'         => $array_status_slugs,
            'filter_license_type'   => $array_license_type,
            'filter_payment_method' => $array_payment_method,
            'filter_renewal_type'   => $array_sub_renewal_type,
        );

        $args['records_per_page'] = $per_page ? $per_page : 20;

        if ( ! empty( $filtered_one_time_ids ) ) {
            $items             = Cost_Tracker_DB::get_instance()->get_cost_tracker_by( 'all', null, array( 'selected_ids' => $filtered_one_time_ids ) );
            $this->items       = ! empty( $items ) ? $items : array();
            $this->total_items = ! empty( $items ) ? count( $items ) : 0;
        } else {
            $results           = Cost_Tracker_DB_Query::instance()->query_costs( $args );
            $this->items       = $results['items'];
            $this->total_items = $results['count'];
        }
        $output = $this->ajax_get_datatable_rows();
        wp_send_json( $output );
    }


    /**
     * Render the Dashboard tab
     *
     * Renders the dashboard tab content - Subscription table
     */
    public function render_dashboard_body() {
        $_orderby = 'name';
        $_order   = 'desc';

        static::$order   = $_order;
        static::$orderby = $_orderby;

        if ( gmdate( 'Y-m-d' ) !== get_option( 'module_cost_tracker_calc_today_next_renewal', '' ) ) {
            Cost_Tracker_DB::get_instance()->update_next_renewal_today();
        }

        $filtered = false;
        if ( isset( $_GET['selected_ids'] ) && ! empty( $_GET['selected_ids'] ) ) { //phpcs:ignore -- ok.
            $filtered = true;
        }
        ?>
        <table class="ui single line table" id="mainwp-module-cost-tracker-sites-table" style="width:100%">
            <thead>
                <tr>
                    <th scope="col" class="no-sort collapsing check-column column-check"><span class="ui checkbox"><input id="cb-select-all-top" type="checkbox"></span></th>
                    <th id="cost_status" class="collapsing column-status"><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
                    <th id="icon" class="no-sort column-icon collapsing"></th>
                    <th id="name" class="column-name"><?php esc_html_e( 'Name', 'mainwp' ); ?></th>
                    <?php if ( $filtered ) : ?>
                        <th id="per_site_price" class="no-sort collapsing column-site-price"><?php esc_html_e( 'Per Site Price', 'mainwp' ); ?></th>
                        <th id="price" class="collapsing column-total-price"><?php esc_html_e( 'Total Price', 'mainwp' ); ?></th>
                    <?php else : ?>
                        <th id="price" class="collapsing column-price"><?php esc_html_e( 'Price', 'mainwp' ); ?></th>
                    <?php endif; ?>
                    <th id="license_type" class="collapsing column-license-type"><?php esc_html_e( 'License', 'mainwp' ); ?></th>
                    <th id="product_type" class="collapsing column-product-type"><?php esc_html_e( 'Category', 'mainwp' ); ?></th>
                    <th id="type" class="collapsing column-type"><?php esc_html_e( 'Type', 'mainwp' ); ?></th>
                    <th id="last_renewal" class="collapsing column-last-renewal"><?php esc_html_e( 'Purchased', 'mainwp' ); ?></th>
                    <th id="payment_method" class="collapsing center aligned column-payment-method"><?php esc_html_e( 'Method', 'mainwp' ); ?></th>
                    <th id="next_renewal" class="collapsing column-next-renewal"><?php esc_html_e( 'Renews', 'mainwp' ); ?></th>
                    <th id="sites" class="no-sort collapsing center aligned column-sites"><?php esc_html_e( 'Sites', 'mainwp' ); ?></th>
                    <th id="actions" class="no-sort collapsing right aligned column-actions"></th>
                </tr>
            </thead>
            <tfoot>
                <tr>
                    <th scope="col" class="no-sort collapsing check-column column-check"><span class="ui checkbox"><input id="cb-select-all-bottom" type="checkbox"></span></th>
                    <th id="cost_status-bottom" class="collapsing column-status"><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
                    <th id="icon-bottom" class="column-icon collapsing"></th>
                    <th id="name-bottom" class="column-name" ><?php esc_html_e( 'Name', 'mainwp' ); ?></th>
                    <?php if ( $filtered ) : ?>
                        <th id="per_site_price-bottom" class="no-sort collapsing column-site-price"><?php esc_html_e( 'Per Site Price', 'mainwp' ); ?></th>
                        <th id="price-bottom" class="collapsing column-price"><?php esc_html_e( 'Total Price', 'mainwp' ); ?></th>
                    <?php else : ?>
                        <th id="price-bottom" class="collapsing column-price"><?php esc_html_e( 'Price', 'mainwp' ); ?></th>
                    <?php endif; ?>
                    <th id="license_type-bottom" class="collapsing column-license-type"><?php esc_html_e( 'License', 'mainwp' ); ?></th>
                    <th id="product_type-bottom" class="collapsing column-product-type"><?php esc_html_e( 'Category', 'mainwp' ); ?></th>
                    <th id="type-bottom" class="column-type"><?php esc_html_e( 'Type', 'mainwp' ); ?></th>
                    <th id="last_renewal-bottom" class="collapsing column-last-renewal"><?php esc_html_e( 'Purchased', 'mainwp' ); ?></th>
                    <th id="payment_method-bottom" class="collapsing center aligned column-payment-method"><?php esc_html_e( 'Method', 'mainwp' ); ?></th>
                    <th id="next_renewal-bottom" class="collapsing column-next-renewal"><?php esc_html_e( 'Renews', 'mainwp' ); ?></th>
                    <th id="sites-bottom" class="collapsing column-sites"><?php esc_html_e( 'Sites', 'mainwp' ); ?></th>
                    <th id="actions-bottom" class="no-sort collapsing right aligned column-actions"></th>
                </tr>
            </tfoot>
        </table>
        <div id="mainwp-loading-sites" style="display: none;">
            <div class="ui active inverted dimmer">
                <div class="ui indeterminate large text loader"><?php esc_html_e( 'Loading ...', 'mainwp-time-tracker-extension' ); ?></div>
            </div>
        </div>
        <?php
        static::render_modal_edit_notes();
        static::render_screen_options();
        $sites_per_page = get_option( 'mainwp_default_sites_per_page', 25 );
        $sites_per_page = intval( $sites_per_page );
        $pages_length   = array(
            25  => '25',
            10  => '10',
            50  => '50',
            100 => '100',
            300 => '300',
        );

        $pages_length = $pages_length + array( $sites_per_page => $sites_per_page );

        ksort( $pages_length );

        if ( isset( $pages_length[-1] ) ) {
            unset( $pages_length[-1] );
        }

        $pagelength_val   = implode( ',', array_keys( $pages_length ) );
        $pagelength_title = implode( ',', array_values( $pages_length ) );

        $table_features = array(
            'searching'     => 'true',
            'paging'        => 'true',
            'pagingType'    => 'full_numbers',
            'info'          => 'true',
            'colReorder'    => '{columns:":not(.check-column):not(.column-actions)"}',
            'stateSave'     => 'true',
            'stateDuration' => '0',
            'order'         => '[]',
            'scrollX'       => 'true',
            'responsive'    => 'true',
            'fixedColumns'  => '',
        );

        ?>
        <script type="text/javascript">

            jQuery( document ).ready( function( $ ) {
                    let responsive = <?php echo esc_js( $table_features['responsive'] ); ?>;
                    if( jQuery( window ).width() > 1140 ) {
                        responsive = false;
                    }

                    // to fix issue not loaded calendar js library
                    if (jQuery('.ui.calendar').length > 0) {
                        if (mainwpParams.use_wp_datepicker == 1) {
                            jQuery('#mainwp-module-cost-tracker-list-sub-header .ui.calendar input[type=text]').datepicker({ dateFormat: "yy-mm-dd" });
                        } else {
                            jQuery('#mainwp-module-cost-tracker-list-sub-header .ui.calendar').calendar({
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
                    try {
                        $subscription_sites_table = jQuery( '#mainwp-module-cost-tracker-sites-table' ).on( 'processing.dt', function ( e, settings, processing ) {
                            jQuery( '#mainwp-loading-sites' ).css( 'display', processing ? 'block' : 'none' );
                            if (!processing) {
                                let tb = jQuery( '#mainwp-module-cost-tracker-sites-table' );
                                $( '#mainwp-module-cost-tracker-sites-table .ui.dropdown' ).dropdown();
                                $( '#mainwp-module-cost-tracker-sites-table .ui.checkbox' ).checkbox();
                            }
                        }).DataTable( {
                            "ajax": {
                                "url": ajaxurl,
                                "type": "POST",
                                "data":  function ( d ) {
                                    let data = mainwp_secure_data( {
                                        action: 'mainwp_module_cost_tracker_lists_display_rows',
                                        sites: $( '#mainwp-module-cost-tracker-costs-filter-sites').dropdown('get value'),
                                        prods_types: $( '#mainwp-module-cost-tracker-costs-filter-cats').dropdown('get value'),
                                        costs_state: $( '#mainwp-module-cost-tracker-costs-filter-status').dropdown('get value'),
                                        license_types: $( '#mainwp-module-cost-tracker-costs-filter-license-types').dropdown('get value'),
                                        renewal_frequency: $( '#mainwp-module-cost-tracker-costs-filter-renewal-frequency').dropdown('get value'),
                                        payment_methods: $( '#mainwp-module-cost-tracker-costs-filter-payment-methods').dropdown('get value'),
                                        client: $( '#mainwp-module-cost-tracker-costs-filter-clients').dropdown('get value'),
                                        dtsstart: $('#mainwp-module-cost-tracker-costs-filter-dtsstart').val(),
                                        dtsstop: $('#mainwp-module-cost-tracker-costs-filter-dtsstop').val(),
                                        show_per_site_price: <?php echo $filtered ? 1 : 0; ?>,
                                    } );
                                    return $.extend( {}, d, data );
                                },
                                "dataSrc": function ( json ) {
                                    for ( let i=0, ien=json.data.length ; i < ien ; i++ ) {
                                        json.data[i].rowClass = json.rowsInfo[i].rowClass;
                                        json.data[i].cost_id = json.rowsInfo[i].cost_id;
                                    }
                                    return json.data;
                                }
                            },
                            "responsive": responsive,
                            "searching" : <?php echo esc_js( $table_features['searching'] ); ?>,
                            "paging" : <?php echo esc_js( $table_features['paging'] ); ?>,
                            "pagingType" : "<?php echo esc_js( $table_features['pagingType'] ); ?>",
                            "info" : <?php echo esc_js( $table_features['info'] ); ?>,
                            "colReorder" : <?php echo $table_features['colReorder']; // phpcs:ignore -- specical chars. ?>,
                            "scrollX" : <?php echo esc_js( $table_features['scrollX'] ); ?>,
                            "stateSave" : <?php echo esc_js( $table_features['stateSave'] ); ?>,
                            "stateDuration" : <?php echo esc_js( $table_features['stateDuration'] ); ?>,
                            "order" : <?php echo $table_features['order']; // phpcs:ignore -- specical chars. ?>,
                            "fixedColumns" : <?php echo ! empty( $table_features['fixedColumns'] ) ? esc_js( $table_features['fixedColumns'] ) : '""'; ?>,
                            "lengthMenu" : [ [<?php echo esc_js( $pagelength_val ); ?>, -1 ], [<?php echo esc_js( $pagelength_title ); ?>, "All"] ],
                            "serverSide": true,
                            "pageLength": <?php echo intval( $sites_per_page ); ?>,
                            "columnDefs": <?php echo wp_json_encode( $this->get_columns_defines() ); ?>,
                            "columns": <?php echo wp_json_encode( $this->get_columns_init( $filtered ) ); ?>,
                            "language": {
                                "emptyTable": "<?php esc_html_e( 'No subscriptions found.', 'mainwp' ); ?>"
                            },
                            "drawCallback": function( settings ) {
                                this.api().tables().body().to$().attr( 'id', 'mainwp-module-cost-tracker-body-table' );
                                mainwp_datatable_fix_menu_overflow();
                            },
                            "initComplete": function( settings, json ) {
                            },
                            rowCallback: function (row, data) {
                                jQuery( row ).addClass(data.rowClass);
                                jQuery( row ).attr( 'id', "cost-row-" + data.cost_id );
                                jQuery( row ).attr( 'item-id', data.cost_id );

                            },
                            'select': {
                                items: 'row',
                                style: 'multi+shift',
                                selector: 'tr>td:not(.not-selectable)'
                            }
                        }).on('select', function (e, dt, type, indexes) {
                            if( 'row' == type ){
                                dt.rows(indexes)
                                .nodes()
                                .to$().find('td.check-column .ui.checkbox' ).checkbox('set checked');
                            }
                        }).on('deselect', function (e, dt, type, indexes) {
                            if( 'row' == type ){
                                dt.rows(indexes)
                                .nodes()
                                .to$().find('td.check-column .ui.checkbox' ).checkbox('set unchecked');
                            }
                        }).on( 'columns-reordered', function () {
                            console.log('columns-reordered');
                            setTimeout(() => {
                                $( '#mainwp-module-cost-tracker-sites-table .ui.dropdown' ).dropdown();
                                $( '#mainwp-module-cost-tracker-sites-table .ui.checkbox' ).checkbox();
                                mainwp_datatable_fix_menu_overflow('#mainwp-module-cost-tracker-sites-table');
                            }, 1000 );
                        } );
                    } catch(err) {
                        // to fix js error.
                        console.log(err);
                    }
                    _init_cost_tracker_sites_screen();
            } );

            mainwp_module_cost_tracker_manage_costs_filter = function() {
                try {
                    let emptyFilter =  ( '' == jQuery( '#mainwp-module-cost-tracker-costs-filter-sites').dropdown('get value') ) &&
                    ( '' == jQuery( '#mainwp-module-cost-tracker-costs-filter-cats').dropdown('get value') ) &&
                    ( '' == jQuery( '#mainwp-module-cost-tracker-costs-filter-status').dropdown('get value') ) &&
                    ( '' == jQuery( '#mainwp-module-cost-tracker-costs-filter-license-types').dropdown('get value') ) &&
                    ( '' == jQuery( '#mainwp-module-cost-tracker-costs-filter-renewal-frequency').dropdown('get value') ) &&
                    ( '' == jQuery( '#mainwp-module-cost-tracker-costs-filter-payment-methods').dropdown('get value') ) &&
                    ( '' == jQuery( '#mainwp-module-cost-tracker-costs-filter-clients').dropdown('get value') ) &&
                    ( '' == jQuery( '#mainwp-module-cost-tracker-costs-filter-dtsstart').dropdown('get value') ) &&
                    ( '' == jQuery( '#mainwp-module-cost-tracker-costs-filter-dtsstop').dropdown('get value') );

                    console.log('emptyFilter: ' + ( emptyFilter ? 'yes' : 'no' ) );

                    if(emptyFilter){
                        jQuery( '#mainwp_module_cost_tracker_manage_costs_reset_filters' ).attr('disabled', 'disabled');
                    } else {
                        jQuery( '#mainwp_module_cost_tracker_manage_costs_reset_filters' ).attr('disabled', false);
                    }

                    $subscription_sites_table.ajax.reload();

                } catch(err) {
                    // to fix js error.
                    console.log(err);
                }
            };

            mainwp_module_cost_tracker_manage_costs_reset_filters = function() {
                try {
                    jQuery( '#mainwp-module-cost-tracker-costs-filter-clients').dropdown('clear');
                    jQuery( '#mainwp-module-cost-tracker-costs-filter-sites').dropdown('clear');
                    jQuery( '#mainwp-module-cost-tracker-costs-filter-cats').dropdown('clear');
                    jQuery( '#mainwp-module-cost-tracker-costs-filter-status').dropdown('clear');
                    jQuery( '#mainwp-module-cost-tracker-costs-filter-license-types').dropdown('clear');
                    jQuery( '#mainwp-module-cost-tracker-costs-filter-renewal-frequency').dropdown('clear');
                    jQuery( '#mainwp-module-cost-tracker-costs-filter-payment-methods').dropdown('clear');
                    jQuery('#mainwp-module-cost-tracker-costs-filter-dtsstart').val('');
                    jQuery('#mainwp-module-cost-tracker-costs-filter-dtsstop').val('');
                    $subscription_sites_table.ajax.reload();
                    jQuery( '#mainwp_module_cost_tracker_manage_costs_reset_filters' ).attr('disabled', 'disabled');
                } catch(err) {
                    // to fix js error.
                    console.log(err);
                }
            };

            _init_cost_tracker_sites_screen = function() {
                jQuery( '#mainwp-module-cost-tracker-sites-screen-options-modal input[type=checkbox][id^="mainwp_show_column_"]' ).each( function() {
                    let check_id = jQuery( this ).attr( 'id' );
                    col_id = check_id.replace( "mainwp_show_column_", "" );
                    try {
                        $subscription_sites_table.column( '#' + col_id ).visible( jQuery(this).is( ':checked' ) );
                        if ( check_id.indexOf("mainwp_show_column_desktop") >= 0 ) {
                            col_id = check_id.replace( "mainwp_show_column_desktop", "" );
                            $subscription_sites_table.column( '#mobile' + col_id ).visible( jQuery(this).is( ':checked' ) ); // to set mobile columns.
                        }
                    } catch(err) {
                        // to fix js error.
                    }
                } );
            };

            //@see hook_screen_options().
            mainwp_module_cost_tracker_sites_screen_options = function () {
                jQuery( '#mainwp-module-cost-tracker-sites-screen-options-modal' ).modal( {
                    allowMultiple: true,
                    onHide: function () {
                    }
                } ).modal( 'show' );

                jQuery( '#subscription-sites-screen-options-form' ).submit( function() {
                    if ( jQuery('input[name=reset_subscriptionsites_columns_order]').attr('value') == 1 ) {
                        $subscription_sites_table.colReorder.reset();
                    }
                    jQuery( '#mainwp-module-cost-tracker-sites-screen-options-modal' ).modal( 'hide' );
                } );
                return false;
            };
        </script>
        <?php
    }

    /**
     * Get table rows.
     *
     * Optimize for shared hosting or big networks.
     *
     * @return array Rows html.
     */
    public function ajax_get_datatable_rows() { //phpcs:ignore -- NOSONAR - complex.

        $sel_ids = isset( $_GET['selected_ids'] ) ? $_GET['selected_ids'] : ''; //phpcs:ignore -- ok.
        $sel_ids = explode( ',', $sel_ids );

        $license_types = array(
            'single_site' => '<span data-tooltip="Single-Site License" data-inverted="" data-position="left center"><i class="wordpress large icon"></i></span>',
            'multi_site'  => '<span data-tooltip="Multiple-Site License" data-inverted="" data-position="left center"><i class="icons"><i class="wordpress mini icon"></i><i class="top left corner large wordpress icon"></i><i class="bottom right corner large wordpress icon"></i></i></span>',
        );

        $product_types   = Cost_Tracker_Admin::get_product_types();
        $payment_methods = Cost_Tracker_Admin::get_payment_methods();

        $show_per_site_price = false;
        if ( isset( $_POST['show_per_site_price'] ) && ! empty( $_POST['show_per_site_price'] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Missing
            $show_per_site_price = true;
        }

        $current_time = time();
        $upcoming1    = strtotime( gmdate( 'Y-m-d 00:00:00', $current_time ) );
        $upcoming2    = strtotime( '+1 month', $current_time );

        $all_rows  = array();
        $info_rows = array();

        $columns = $this->get_columns( $show_per_site_price );

        $product_types_icons = Cost_Tracker_Admin::get_product_type_icons();
        $product_colors      = Cost_Tracker_Admin::get_product_colors();

        if ( $this->items ) {
            foreach ( $this->items as $subscription ) {

                $note         = html_entity_decode( $subscription->note );
                $esc_note     = apply_filters( 'mainwp_escape_content', $note );
                $strip_note   = wp_strip_all_tags( $esc_note );
                $last_renewal = $subscription ? $subscription->last_renewal : 0;

                $sel_sites   = ! empty( $subscription->sites ) ? json_decode( $subscription->sites, true ) : array();
                $sel_groups  = ! empty( $subscription->groups ) ? json_decode( $subscription->groups, true ) : array();
                $sel_clients = ! empty( $subscription->clients ) ? json_decode( $subscription->clients, true ) : array();

                if ( ! is_array( $sel_sites ) ) {
                    $sel_sites = array();
                }
                if ( ! is_array( $sel_groups ) ) {
                    $sel_groups = array();
                }
                if ( ! is_array( $sel_clients ) ) {
                    $sel_clients = array();
                }

                $url_manage_sites = '';

                $params = array(
                    'sites'   => $sel_sites,
                    'groups'  => $sel_groups,
                    'clients' => $sel_clients,
                );

                $sub_sites = MainWP_DB::instance()->get_db_sites( $params );
                $num_sites = count( $sub_sites );
                if ( $num_sites > 0 ) {
                    $filter_sites     = ! empty( $sel_sites ) ? '&selected_sites=' . implode( ',', $sel_sites ) : '';
                    $filter_groups    = ! empty( $sel_groups ) ? '&g=' . implode( ',', $sel_groups ) : '';
                    $filter_clients   = ! empty( $sel_clients ) ? '&client=' . implode( ',', $sel_clients ) : '';
                    $url_manage_sites = 'admin.php?page=managesites' . $filter_sites . $filter_groups . $filter_clients;
                }

                $rw_classes = 'cost-item cost-tracker-item-' . intval( $subscription->id ) . ' cost-tracker-type-' . ( isset( $product_types[ $subscription->product_type ] ) ? $product_types[ $subscription->product_type ] : '' );
                $info_item  = array(
                    'rowClass' => esc_html( $rw_classes ),
                    'cost_id'  => intval( $subscription->id ),
                );

                $cols_data = array();

                foreach ( $columns as $column_name => $column_display_name ) {
                    ob_start();
                    switch ( $column_name ) {
                        case 'cb':
                            ?>
                            <span class="ui checkbox" data-tooltip="<?php esc_attr_e( 'Click to select the site.', 'mainwp' ); ?>" data-inverted="" data-position="right center"><input type="checkbox" name="checked[]"></span>
                            <?php
                            break;
                        case 'cost_status':
                            ?>
                            <?php echo Cost_Tracker_Admin::get_cost_status_label( $subscription->cost_status ); //phpcs:ignore -- escaped. ?>
                            <?php
                            break;
                        case 'icon':
                            ?>
                            <?php echo Cost_Tracker_Admin::get_instance()->get_product_icon_display( $subscription ); //phpcs:ignore -- escaped.?>
                            <?php
                            break;
                        case 'name':
                            ?>
                            <a class="item" href="admin.php?page=CostTrackerAdd&id=<?php echo intval( $subscription->id ); ?>"><?php echo esc_html( $subscription->name ); ?></a>
                            <?php
                            break;
                        case 'per_site_price':
                            if ( 'single_site' === $subscription->license_type ) {
                                $per_site_price = $subscription->price;
                            } else {
                                $per_site_price = ( $num_sites > 0 ) ? ( $subscription->price / $num_sites ) : 0;
                            }

                            $next30_price = 0;
                            if ( 'active' === $subscription->cost_status && 'subscription' === $subscription->type ) {
                                $next_rl = $subscription->next_renewal;
                                if ( $next_rl <= $upcoming1 ) {
                                    $next_rl = Cost_Tracker_Admin::get_next_renewal( $upcoming1, $subscription->renewal_type, false );
                                }
                                while ( $next_rl <= $upcoming2 ) {
                                    if ( $next_rl > $upcoming1 && $next_rl <= $upcoming2 ) {
                                        $next30_price += $per_site_price;
                                    }
                                    $next_rl = Cost_Tracker_Admin::get_next_renewal( $next_rl, $subscription->renewal_type, false );
                                }
                            }
                            ?>
                            <strong><?php Cost_Tracker_Utility::cost_tracker_format_price( $next30_price ); ?></strong>
                            <?php
                            break;
                        case 'price':
                            echo ! empty( $url_manage_sites ) ? '<a href="' . esc_url( $url_manage_sites ) . '">' . Cost_Tracker_Utility::cost_tracker_format_price( $subscription->price, true ) . '</a>' : Cost_Tracker_Utility::cost_tracker_format_price( $subscription->price ); //phpcs:ignore -- ok.
                            break;
                        case 'license_type':
                            ?>
                            <?php echo isset( $license_types[ $subscription->license_type ] ) ? $license_types[ $subscription->license_type ] : ''; //phpcs:ignore -- ok. ?>
                            <?php
                            break;
                        case 'type':
                            ?>
                            <?php echo 'lifetime' === $subscription->type ? '<span data-tooltip="Lifetime license" data-inverted="" data-position="left center"><div class="ui grey fluid label"><i class="infinity icon"></i>L</div></span>' : '<span data-tooltip="Recurring subscription" data-inverted="" data-position="left center"><div class="ui black fluid label"><i class="calendar alternate outline icon"></i><strong>' . esc_html( substr( ucfirst( $subscription->renewal_type ), 0, 1 ) ) . '</strong></div></span>'; ?>
                            <?php
                            break;
                        case 'product_type':
                            ?>
                            <?php echo isset( $product_types[ $subscription->product_type ] ) ? '<div class="ui label" style="color:#ffffff;background-color:' . esc_attr( $product_colors[ $subscription->product_type ] ) . '"><i class="' . str_replace( 'deficon:', '', esc_attr( $product_types_icons[ $subscription->product_type ] ) ) . ' icon"></i>' . esc_html( $product_types[ $subscription->product_type ] ) . '</div>' : ''; //phpcs:ignore -- ok. ?>
                            <?php
                            break;
                        case 'last_renewal':
                            ?>
                            <?php echo $last_renewal ? '<em>' . MainWP_Utility::format_date( $last_renewal ) . '</em>': ''; //phpcs:ignore -- escaped. ?>
                            <?php
                            break;
                        case 'payment_method':
                            ?>
                            <?php echo isset( $payment_methods[ $subscription->payment_method ] ) ? Cost_Tracker_Utility::get_payment_method_icon( $payment_methods[ $subscription->payment_method ] ) : ''; // phpcs:ignore -- ok.?>
                            <?php
                            break;
                        case 'next_renewal':
                            $next_renewal = Cost_Tracker_Admin::get_next_renewal( $subscription->last_renewal, $subscription->renewal_type );
                            Cost_Tracker_Admin::generate_next_renewal( $subscription, $next_renewal );
                            break;
                        case 'sites':
                            ?>
                            <?php echo ! empty( $url_manage_sites ) ? '<a href="' . esc_url( $url_manage_sites ) . '">' . '<div class="ui blue small label"><i class="wordpress icon"></i>' . count( $sub_sites ) . '</div>' . '</a>' : '<div class="ui small label"><i class="wordpress icon"></i> 0</div>'; //phpcs:ignore -- WP icon. ?>
                            <?php
                            break;
                        case 'actions':
                            ?>
                            <div class="ui right pointing dropdown">
                                <a href="javascript:void(0)" aria-label="<?php esc_attr_e( 'Actions menu', 'mainwp' ); ?>"><i class="ellipsis vertical icon"></i></a>
                                <div class="menu">
                                    <a class="item" href="admin.php?page=CostTrackerAdd&id=<?php echo intval( $subscription->id ); ?>"><?php esc_html_e( 'Edit', 'mainwp' ); ?></a>
                                    <?php if ( empty( $subscription->note ) ) : ?>
                                        <a href="javascript:void(0)" class="item mainwp-edit-sub-note"><?php esc_html_e( 'Add Notes', 'mainwp' ); ?></a>
                                    <?php else : ?>
                                        <a href="javascript:void(0)" class="item mainwp-edit-sub-note" data-tooltip="<?php echo esc_attr( substr( wp_unslash( $strip_note ), 0, 100 ) ); ?>" data-position="left center" data-inverted=""><?php esc_html_e( 'View Notes', 'mainwp' ); ?></a>
                                    <?php endif; ?>
                                    <span style="display: none" id="sub-notes-<?php echo intval( $subscription->id ); ?>-note"><?php echo wp_unslash( $esc_note ); //phpcs:ignore -- escaped. ?></span>
                                    <a class="item subscription_menu_item_delete" href="javascript:void(0)"><?php esc_html_e( 'Delete', 'mainwp' ); ?></a>
                                </div>
                            </div>
                            <?php
                            break;
                        default:
                            break;
                    }
                    $cols_data[ $column_name ] = ob_get_clean();
                }
                $all_rows[]  = $cols_data;
                $info_rows[] = $info_item;
            }
        }
        return array(
            'data'            => $all_rows,
            'recordsTotal'    => $this->total_items,
            'recordsFiltered' => $this->total_items,
            'rowsInfo'        => $info_rows,
        );
    }

    /**
     * Method ajax_notes_save()
     *
     * Post handler for save notes.
     */
    public function ajax_notes_save() {
        MainWP_Post_Handler::instance()->check_security( 'mainwp_module_cost_tracker_notes_save' );
        //phpcs:disable WordPress.Security.NonceVerification.Missing
        $sub_id = isset( $_POST['subid'] ) ? intval( $_POST['subid'] ) : 0;
        $sub    = Cost_Tracker_DB::get_instance()->get_cost_tracker_by( 'id', $sub_id );
        if ( $sub_id && $sub ) {
            $note     = isset( $_POST['note'] ) ? wp_unslash( $_POST['note'] ) : ''; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- mainwp_escape_content.
            $esc_note = apply_filters( 'mainwp_escape_content', $note );
            $update   = array(
                'id'   => $sub_id,
                'note' => $esc_note,
            );
            Cost_Tracker_DB::get_instance()->update_cost_tracker( $update );
            die( wp_json_encode( array( 'result' => 'SUCCESS' ) ) );
        } else {
            die( wp_json_encode( array( 'error' => esc_html__( 'Invalid cost tracker ID or item not found.', 'mainwp' ) ) ) );
        }
        //phpcs:enable
    }

    /**
     * Method ajax_costs_filter_save_segment()
     *
     * Post handler for save segment.
     */
    public function ajax_costs_filter_save_segment() {
        MainWP_Post_Handler::instance()->check_security( 'mainwp_module_cost_tracker_filter_save_segment' );
        //phpcs:disable WordPress.Security.NonceVerification.Missing

        $not_filters = array(
            'seg_sites'             => 'nosites',
            'seg_clients'           => 'noclients',
            'seg_prods_types'       => 'nocategories',
            'seg_costs_state'       => 'nostatus',
            'seg_license_types'     => 'nolicensetypes',
            'seg_payment_methods'   => 'nopaymentmenthods',
            'seg_renewal_frequency' => 'norenewalfrequency',
        );

        $fields = array(
            'name',
            'seg_sites',
            'seg_clients',
            'seg_prods_types',
            'seg_costs_state',
            'seg_license_types',
            'seg_renewal_frequency',
            'seg_payment_methods',
            'seg_dtsstart',
            'seg_dtsstop',
        );

        $save_fields = array();

        foreach ( $fields as $field ) {
            if ( isset( $_POST[ $field ] ) ) {
                $val_seg = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
                if ( isset( $not_filters[ $field ] ) && false !== strpos( $val_seg, $not_filters[ $field ] ) ) {
                    $val_seg = '';
                }
                $save_fields[ $field ] = $val_seg;
            }
        }

        $seg_id = ! empty( $_POST['seg_id'] ) ? sanitize_text_field( wp_unslash( $_POST['seg_id'] ) ) : time();
        //phpcs:enable WordPress.Security.NonceVerification.Missing

        $saved_segments = $this->set_get_cost_filter_segments();
        if ( ! is_array( $saved_segments ) ) {
            $saved_segments = array();
        }
        $saved_segments[ $seg_id ] = $save_fields;
        $this->set_get_cost_filter_segments( true, $saved_segments );
        die( wp_json_encode( array( 'result' => 'SUCCESS' ) ) );
    }


    /**
     * Method set_get_cost_filter_segments()
     *
     * @param bool  $set_val Get or set value.
     * @param array $saved_segments segments value.
     */
    public function set_get_cost_filter_segments( $set_val = false, $saved_segments = array() ) {
        global $current_user;
        if ( $current_user && ! empty( $current_user->ID ) ) {
            if ( $set_val ) {
                update_user_option( $current_user->ID, 'mainwp_module_cost_tracker_filter_saved_segments', $saved_segments );
            } else {
                $values = get_user_option( 'mainwp_module_cost_tracker_filter_saved_segments', array() );
                if ( ! is_array( $values ) ) {
                    $values = array();
                }
                return $values;
            }
        }
        return array();
    }

    /**
     * Method ajax_costs_filter_load_segments()
     *
     * Post handler for save segment.
     */
    public function ajax_costs_filter_load_segments() {
        MainWP_Post_Handler::instance()->check_security( 'mainwp_module_cost_tracker_filter_load_segments' );
        $saved_segments = $this->set_get_cost_filter_segments();
        $list_segs      = '';
        if ( is_array( $saved_segments ) && ! empty( $saved_segments ) ) {
            $list_segs .= '<select id="mainwp_module_cost_tracker_edit_payment_type" class="ui fluid dropdown">';
            $list_segs .= '<option segment-filters="" value="">' . esc_html__( 'Select a segment', 'mainwp' ) . '</option>';
            foreach ( $saved_segments as $sid => $values ) {
                if ( empty( $values['name'] ) ) {
                    continue;
                }
                $list_segs .= '<option segment-filters="' . esc_attr( wp_json_encode( $values ) ) . '" value="' . esc_attr( $sid ) . '">' . esc_html( $values['name'] ) . '</option>';
            }
            $list_segs .= '</select>';
        }
        die( wp_json_encode( array( 'result' => $list_segs ) ) ); //phpcs:ignore -- ok.
    }

    /**
     * Method ajax_costs_filter_delete_segment()
     *
     * Post handler for save segment.
     */
    public function ajax_costs_filter_delete_segment() {
        MainWP_Post_Handler::instance()->check_security( 'mainwp_module_cost_tracker_filter_delete_segment' );
        $seg_id = ! empty( $_POST['seg_id'] ) ? sanitize_text_field( wp_unslash( $_POST['seg_id'] ) ) : 0; //phpcs:ignore -- ok.

        $saved_segments = $this->set_get_cost_filter_segments();
        if ( ! empty( $seg_id ) && is_array( $saved_segments ) && isset( $saved_segments[ $seg_id ] ) ) {
            unset( $saved_segments[ $seg_id ] );
            $this->set_get_cost_filter_segments( true, $saved_segments );
            die( wp_json_encode( array( 'result' =>'SUCCESS' ) ) ); //phpcs:ignore -- ok.
        }
        die( wp_json_encode( array( 'error' => esc_html__( 'Segment not found. Please try again.', 'mainwp' ) ) ) ); //phpcs:ignore -- ok.
    }



    /**
     * Method ajax_cost_tracker_delete()
     *
     * Post handler for save notes.
     */
    public function ajax_cost_tracker_delete() {
        MainWP_Post_Handler::instance()->check_security( 'mainwp_module_cost_tracker_delete' );
        $sub_id = isset( $_POST['sub_id'] ) ? intval( $_POST['sub_id'] ) : 0; //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if ( Cost_Tracker_DB::get_instance()->delete_cost_tracker( 'id', $sub_id ) ) {
            die( wp_json_encode( array( 'status' => 'success' ) ) );
        } else {
            die( wp_json_encode( array( 'error' => esc_html__( 'Failed.', 'mainwp' ) ) ) );
        }
    }

    /**
     * Render Actions Bar
     *
     * Renders the actions bar on the Dashboard tab.
     */
    public function render_actions_bar() {
        ?>
        <div class="mainwp-actions-bar">
            <div class="ui two columns grid">
                <div class="column ui mini form">
                    <select class="ui dropdown" id="mwp_cost_tracker_bulk_action">
                        <option value="-1"><?php esc_html_e( 'Bulk actions', 'mainwp' ); ?></option>
                        <option value="delete-sub"><?php esc_html_e( 'Delete', 'mainwp' ); ?></option>
                    </select>
                    <input type="button" name="mainwp_module_cost_tracker_action_btn" id="mainwp_module_cost_tracker_action_btn" class="ui basic mini button" value="<?php esc_html_e( 'Apply', 'mainwp' ); ?>"/>
                    <?php do_action( 'mainwp_module_cost_tracker_actions_bar_left' ); ?>
                </div>
                <div class="right aligned middle aligned column">
                    <div class="ui stackable grid">
                        <div class="eight wide right aligned middle aligned column"><?php do_action( 'mainwp_module_cost_tracker_actions_bar_right' ); ?></div>
                        <div class="eight wide right aligned middle aligned column"><a href="#" class="ui mini basic button" id="mainwp-manage-costs-filter-toggle-button" aria-label="<?php esc_attr_e( 'Available filters.', 'mainwp' ); ?>"><i class="filter grey icon"></i> <?php esc_html_e( 'Filter Costs', 'mainwp' ); ?></a></div>
                    </div>
                </div>
            </div>
            <script type="text/javascript">
                jQuery( document ).on( 'click', '#mainwp-manage-costs-filter-toggle-button', function () {
                    jQuery( '#mainwp-module-cost-tracker-list-sub-header' ).toggle( 300 );
                    return false;
                } );
            </script>
        </div>
        <?php
    }


    /**
     * Method get_cost_filter_params().
     *
     * @return array filters params.
     */
    public static function get_cost_filter_params() {
        return array( 'sites', 'client', 'prods_types', 'costs_state', 'dtsstart', 'dtsstop', 'license_types', 'renewal_frequency', 'payment_methods' );
    }

    /**
     * Render Manage Tasks Table Top.
     *
     * @param bool $sel_one_time_ids selected one time ids.
     *
     * @return void
     */
    public static function render_manage_tasks_table_top( $sel_one_time_ids = false ) { //phpcs:ignore -- NOSONAR - complex.

        $filters = static::get_cost_filter_params();

        $get_saved = true;
        foreach ( $filters as $filter ) {
            if ( isset( $_REQUEST[ $filter ] ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended -- ok.
                $get_saved = false;
                break;
            }
        }

        $filter_sites_ids       = '';
        $filter_client_ids      = '';
        $filter_prod_type_slugs = '';
        $filter_cost_state      = '';
        $filter_dtsstart        = '';
        $filter_dtsstop         = '';

        $filter_license_type     = '';
        $filter_payment_method   = '';
        $filter_sub_renewal_type = '';

        $redirect_site_id = isset( $_GET['site_id'] ) ? intval( $_GET['site_id'] ) : 0; //phpcs:ignore -- ok.

        if ( ! $sel_one_time_ids ) {
            if ( $get_saved ) {
                $filters_saved = get_user_option( 'mainwp_module_cost_tracker_filters_saved' );
                if ( ! is_array( $filters_saved ) ) {
                    $filters_saved = array();
                }
                $filter_sites_ids       = isset( $filters_saved['sites_ids'] ) && ! empty( $filters_saved['sites_ids'] ) ? $filters_saved['sites_ids'] : false;
                $filter_client_ids      = isset( $filters_saved['client_ids'] ) && ! empty( $filters_saved['client_ids'] ) ? $filters_saved['client_ids'] : false;
                $filter_prod_type_slugs = isset( $filters_saved['prods_types'] ) && ! empty( $filters_saved['prods_types'] ) ? $filters_saved['prods_types'] : false;
                $filter_cost_state      = isset( $filters_saved['costs_state'] ) && ! empty( $filters_saved['costs_state'] ) ? $filters_saved['costs_state'] : '';
                $filter_dtsstart        = isset( $filters_saved['dtsstart'] ) && ! empty( $filters_saved['dtsstart'] ) ? $filters_saved['dtsstart'] : '';
                $filter_dtsstop         = isset( $filters_saved['dtsstop'] ) && ! empty( $filters_saved['dtsstop'] ) ? $filters_saved['dtsstop'] : '';

                $filter_license_type     = isset( $filters_saved['license_types'] ) && ! empty( $filters_saved['license_types'] ) ? $filters_saved['license_types'] : '';
                $filter_sub_renewal_type = isset( $filters_saved['renewal_frequency'] ) && ! empty( $filters_saved['renewal_frequency'] ) ? $filters_saved['renewal_frequency'] : '';
                $filter_payment_method   = isset( $filters_saved['payment_methods'] ) && ! empty( $filters_saved['payment_methods'] ) ? $filters_saved['payment_methods'] : '';
            } else {
                // phpcs:disable WordPress.Security.NonceVerification
                $filter_sites_ids       = isset( $_REQUEST['sites_ids'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['sites_ids'] ) ) : '';
                $filter_client_ids      = isset( $_REQUEST['client'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['client'] ) ) : '';
                $filter_prod_type_slugs = isset( $_REQUEST['prods_types'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['prods_types'] ) ) : '';
                $filter_cost_state      = isset( $_REQUEST['costs_state'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['costs_state'] ) ) : '';
                $filter_dtsstart        = isset( $_REQUEST['dtsstart'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['dtsstart'] ) ) : '';
                $filter_dtsstop         = isset( $_REQUEST['dtsstop'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['dtsstop'] ) ) : '';

                $filter_license_type     = isset( $_REQUEST['license_types'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['license_types'] ) ) : '';
                $filter_sub_renewal_type = isset( $_REQUEST['renewal_frequency'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['renewal_frequency'] ) ) : '';
                $filter_payment_method   = isset( $_REQUEST['payment_methods'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['payment_methods'] ) ) : '';
                // phpcs:enable WordPress.Security.NonceVerification
            }
        } elseif ( $redirect_site_id ) {
            $filter_sites_ids = $redirect_site_id;
            $filter_dtsstart  = gmdate( 'Y-m-d' );
            $filter_dtsstop   = gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS );
        }

        $empty_filter = true;
        if ( ! $sel_one_time_ids ) {
            $empty_filter = empty( $filter_sites_ids ) && empty( $filter_client_ids ) && empty( $filter_prod_type_slugs ) && empty( $filter_cost_state ) && empty( $filter_dtsstart ) && empty( $filter_dtsstop ) && empty( $filter_license_type ) && empty( $filter_sub_renewal_type ) && empty( $filter_payment_method );
        }

        $all_defaults  = Cost_Tracker_Admin::get_default_fields_values();
        $product_types = $all_defaults['product_types'];
        $cost_status   = $all_defaults['cost_status'];

        $license_types     = $all_defaults['license_types'];
        $renewal_frequency = $all_defaults['renewal_frequency'];
        $payment_methods   = $all_defaults['payment_methods'];

        $saved_segments = static::get_instance()->set_get_cost_filter_segments();

        $filters_row_style = 'display:none';

        if ( ! empty( $filter_sites_ids ) || ! empty( $filter_client_ids ) || ! empty( $filter_prod_type_slugs ) || ! empty( $filter_cost_state ) || ! empty( $filter_license_type ) || ! empty( $filter_sub_renewal_type ) || ! empty( $filter_payment_method ) || ! empty( $filter_dtsstart ) || ! empty( $filter_dtsstop ) ) {
            $filters_row_style = 'display:block';
        }

        ?>
        <div class="mainwp-sub-header" id="mainwp-module-cost-tracker-list-sub-header" style="<?php echo esc_attr( $filters_row_style ); ?>">
            <div class="ui stackable compact grid mini form" id="mainwp-module-cost-tracker-costs-filters-row">
                <div class="thirteen wide column ui compact grid">
                    <div class="three wide middle aligned column">
                        <div id="mainwp-module-cost-tracker-costs-filter-sites" class="ui fluid selection multiple dropdown seg_sites">
                            <input type="hidden" value="<?php echo esc_html( $filter_sites_ids ); ?>">
                            <i class="dropdown icon"></i>
                            <div class="default text"><?php esc_html_e( 'All sites', 'mainwp' ); ?></div>
                            <div class="menu">
                                <?php
                                $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_wp_for_current_user() );
                                foreach ( $websites as $site ) {
                                    ?>
                                    <div class="item" data-value="<?php echo intval( $site['id'] ); ?>"><?php echo esc_html( stripslashes( $site['name'] ) ); ?></div>
                                    <?php
                                }
                                ?>
                                <div class="item" data-value="nosites"><?php esc_html_e( 'All sites', 'mainwp' ); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="three wide middle aligned column">
                        <div id="mainwp-module-cost-tracker-costs-filter-clients" class="ui fluid selection multiple dropdown seg_clients">
                            <input type="hidden" value="<?php echo esc_html( $filter_client_ids ); ?>">
                            <i class="dropdown icon"></i>
                            <div class="default text"><?php esc_html_e( 'All clients', 'mainwp' ); ?></div>
                            <div class="menu">
                                <?php
                                $clients = MainWP_DB_Client::instance()->get_wp_client_by( 'all' );

                                foreach ( $clients as $client ) {
                                    ?>
                                    <div class="item" data-value="<?php echo intval( $client->client_id ); ?>"><?php echo esc_html( stripslashes( $client->name ) ); ?></div>
                                    <?php
                                }
                                ?>
                                <div class="item" data-value="noclients"><?php esc_html_e( 'All clients', 'mainwp' ); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="three wide middle aligned column">
                        <div id="mainwp-module-cost-tracker-costs-filter-cats" class="ui fluid selection multiple dropdown seg_prods_types">
                            <input type="hidden" value="<?php echo esc_html( $filter_prod_type_slugs ); ?>">
                            <i class="dropdown icon"></i>
                            <div class="default text"><?php esc_html_e( 'All categories', 'mainwp' ); ?></div>
                            <div class="menu">
                                <?php
                                foreach ( $product_types as $pro_type => $pro_name ) {
                                    ?>
                                    <div class="item" data-value="<?php echo esc_attr( $pro_type ); ?>"><?php echo esc_html( stripslashes( $pro_name ) ); ?></div>
                                    <?php
                                }
                                ?>
                                <div class="item" data-value="nocategories"><?php esc_html_e( 'All categories', 'mainwp' ); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="three wide middle aligned column">
                        <div id="mainwp-module-cost-tracker-costs-filter-status" class="ui fluid selection multiple dropdown seg_costs_state">
                            <input type="hidden" value="<?php echo esc_html( $filter_cost_state ); ?>">
                            <i class="dropdown icon"></i>
                            <div class="default text"><?php esc_html_e( 'All statuses', 'mainwp' ); ?></div>
                            <div class="menu">
                                <?php
                                foreach ( $cost_status as $status => $status_name ) {
                                    ?>
                                    <div class="item" data-value="<?php echo esc_attr( $status ); ?>"><?php echo esc_html( stripslashes( $status_name ) ); ?></div>
                                    <?php
                                }
                                ?>
                                <div class="item" data-value="nostatus"><?php esc_html_e( 'All statuses', 'mainwp' ); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="three wide middle aligned column">
                        <div id="mainwp-module-cost-tracker-costs-filter-license-types" class="ui fluid selection multiple dropdown seg_license_types">
                            <input type="hidden" value="<?php echo esc_attr( $filter_license_type ); ?>">
                            <i class="dropdown icon"></i>
                            <div class="default text"><?php esc_html_e( 'All license types', 'mainwp' ); ?></div>
                            <div class="menu">
                                <?php
                                foreach ( $license_types as $key => $title ) {
                                    ?>
                                    <div class="item" data-value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $title ); ?></div>
                                    <?php
                                }
                                ?>
                                <div class="item" data-value="nolicensetypes"><?php esc_html_e( 'All license types', 'mainwp' ); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="three wide middle aligned column">
                        <div id="mainwp-module-cost-tracker-costs-filter-renewal-frequency" class="ui fluid selection multiple dropdown seg_renewal_frequency">
                            <input type="hidden" value="<?php echo esc_attr( $filter_sub_renewal_type ); ?>">
                            <i class="dropdown icon"></i>
                            <div class="default text"><?php esc_html_e( 'All Subscription types', 'mainwp' ); ?></div>
                            <div class="menu">
                                <?php
                                foreach ( $renewal_frequency as $key => $title ) {
                                    ?>
                                    <div class="item" data-value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $title ); ?></div>
                                    <?php
                                }
                                ?>
                                <div class="item" data-value="norenewalfrequency"><?php esc_html_e( 'All Subscription types', 'mainwp' ); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="three wide middle aligned column">
                        <div id="mainwp-module-cost-tracker-costs-filter-payment-methods" class="ui fluid selection multiple dropdown seg_payment_methods">
                            <input type="hidden" value="<?php echo esc_attr( $filter_payment_method ); ?>">
                            <i class="dropdown icon"></i>
                            <div class="default text"><?php esc_html_e( 'All methods', 'mainwp' ); ?></div>
                            <div class="menu">
                                <?php
                                foreach ( $payment_methods as $key => $title ) {
                                    ?>
                                    <div class="item" data-value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $title ); ?></div>
                                    <?php
                                }
                                ?>
                                <div class="item" data-value="nopaymentmenthods"><?php esc_html_e( 'All methods', 'mainwp' ); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="three wide middle aligned column">
                        <div class="ui calendar fluid mainwp_datepicker seg_dtsstart">
                            <div class="ui fluid input left icon">
                                <i class="calendar icon"></i>
                                <input type="text" autocomplete="off" placeholder="<?php esc_attr_e( 'Renews period start date', 'mainwp' ); ?>" id="mainwp-module-cost-tracker-costs-filter-dtsstart" value="<?php echo ! empty( $filter_dtsstart ) ? esc_attr( $filter_dtsstart ) : ''; ?>"/>
                            </div>
                        </div>
                    </div>
                    <div class="three wide middle aligned column">
                        <div class="ui calendar fluid mainwp_datepicker seg_dtsstop">
                            <div class="ui fluid input left icon">
                                <i class="calendar icon"></i>
                                <input type="text" autocomplete="off" placeholder="<?php esc_attr_e( 'Renews period end date', 'mainwp' ); ?>" id="mainwp-module-cost-tracker-costs-filter-dtsstop" value="<?php echo ! empty( $filter_dtsstop ) ? esc_attr( $filter_dtsstop ) : ''; ?>"/>
                            </div>
                        </div>
                    </div>
                    <div class="three wide middle aligned right aligned column">
                        <button onclick="mainwp_module_cost_tracker_manage_costs_filter()"  class="ui mini green button"><?php esc_html_e( 'Filter Costs', 'mainwp' ); ?></button>
                        <button onclick="mainwp_module_cost_tracker_manage_costs_reset_filters()" id="mainwp_module_cost_tracker_manage_costs_reset_filters" class="ui mini button" <?php echo $empty_filter ? 'disabled' : ''; ?>><?php esc_html_e( 'Reset Filters', 'mainwp' ); ?></button>
                    </div>
                </div>
                <div class="three wide top aligned right aligned column">
                    <div class="ui compact grid">
                        <div class="eight wide column"></div>
                        <div class="eight wide column">
                            <button class="ui mini green fluid button" id="module-cost-tracker-filter-save-segment-button" selected-segment-id="" selected-segment-name=""><?php esc_html_e( 'Save Segment', 'mainwp' ); ?></button>
                            <br/>
                            <?php if ( ! empty( $saved_segments ) ) : ?>
                                <button class="ui mini fluid button mainwp_module_cost_tracker_filter_choose_segment"><?php esc_html_e( 'Load Segment', 'mainwp' ); ?></button>
                            <?php else : ?>
                                <button class="ui mini fluid disabled button"><?php esc_html_e( 'Load Segment', 'mainwp' ); ?></button>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
            </div>
        </div>
        <style>

        </style>
        <script type="text/javascript">
            jQuery( document ).ready( function( $ ) {

                jQuery('#module-cost-tracker-filter-save-segment-button').on( 'click', function () {
                    jQuery( '#mainwp-cost-tracker-segment-edit-fields' ).show();
                    jQuery( '#mainwp-cost-tracker-edit-segment-save' ).show();
                    jQuery( '#mainwp-cost-tracker-segment-select-fields' ).hide();
                    jQuery( '#mainwp-cost-tracker-select-segment-choose-button' ).hide();
                    jQuery( '#mainwp-cost-tracker-select-segment-delete-button' ).hide();
                    jQuery('#mainwp-cost-tracker-edit-segment-name').val(jQuery(this).attr('selected-segment-name'));
                    mainwp_module_cost_tracker_show_segments_modal();
                } );

                jQuery('.mainwp_module_cost_tracker_filter_choose_segment').on( 'click', function () {
                    jQuery( '#mainwp-cost-tracker-segment-edit-fields' ).hide();
                    jQuery( '#mainwp-cost-tracker-edit-segment-save' ).hide();
                    jQuery( '#mainwp-cost-tracker-segment-select-fields' ).show();
                    jQuery( '#mainwp-cost-tracker-select-segment-choose-button' ).show();
                    jQuery( '#mainwp-cost-tracker-select-segment-delete-button' ).show();
                    mainwp_module_cost_tracker_show_segments_modal(true);
                } );

                mainwp_module_cost_tracker_hide_segments_message = function () {
                    jQuery('#mainwp-cost-tracker-edit-segment-status').removeClass('red green').hide();
                };

                mainwp_module_cost_tracker_show_segments_modal = function (loadSeg) {
                    mainwp_module_cost_tracker_hide_segments_message();
                    jQuery( '#mainwp-module-cost-tracker-segment-modal' ).modal( {
                        allowMultiple: false,
                        onShow: function () {
                            if(typeof loadSeg !== "undefined" && loadSeg){
                                mainwp_module_cost_tracker_load_segments();
                            }
                        }
                    } ).modal( 'show' );
                };

                mainwp_module_cost_tracker_load_segments = function () {
                    jQuery('#mainwp-cost-tracker-segment-select-fields').hide();
                    let data = mainwp_secure_data({
                        action: 'mainwp_module_cost_tracker_filter_load_segments',
                    });
                    jQuery('#mainwp-cost-tracker-edit-segment-status').html('<i class="notched circle loading icon"></i> ' + __('Loading segments. Please wait...')).show();
                    jQuery.post(ajaxurl, data, function (response) {
                        if (response.error != undefined) {
                            jQuery('#mainwp-cost-tracker-edit-segment-status').html(response.error).addClass('red');
                        } else if (response.result) {
                            jQuery('#mainwp-cost-tracker-edit-segment-status').hide();
                            jQuery('#mainwp-cost-tracker-segments-lists-wrapper').html(response.result);
                            jQuery( '#mainwp-cost-tracker-segments-lists-wrapper .ui.dropdown' ).dropdown();
                            jQuery('#mainwp-cost-tracker-segment-select-fields').show();
                        } else {
                            jQuery('#mainwp-cost-tracker-edit-segment-status').html(__('No saved segments.')).addClass('red');
                        }
                    }, 'json');
                };

                jQuery('#mainwp-cost-tracker-edit-segment-save').on( 'click', function () {

                    mainwp_module_cost_tracker_hide_segments_message();

                    let seg_name = jQuery('#mainwp-cost-tracker-edit-segment-name').val().trim();

                    if('' == seg_name){
                        jQuery('#mainwp-cost-tracker-edit-segment-status').html(__('Please enter segment name.')).addClass('red').show();
                        return false;
                    }

                    let data = mainwp_secure_data({
                        action: 'mainwp_module_cost_tracker_filter_save_segment',
                        name: seg_name,
                        seg_sites: $( '#mainwp-module-cost-tracker-costs-filter-sites').dropdown('get value'),
                        seg_clients: $( '#mainwp-module-cost-tracker-costs-filter-clients').dropdown('get value'),
                        seg_prods_types: $( '#mainwp-module-cost-tracker-costs-filter-cats').dropdown('get value'),
                        seg_costs_state: $( '#mainwp-module-cost-tracker-costs-filter-status').dropdown('get value'),
                        seg_license_types: $( '#mainwp-module-cost-tracker-costs-filter-license-types').dropdown('get value'),
                        seg_renewal_frequency: $( '#mainwp-module-cost-tracker-costs-filter-renewal-frequency').dropdown('get value'),
                        seg_payment_methods: $( '#mainwp-module-cost-tracker-costs-filter-payment-methods').dropdown('get value'),
                        seg_dtsstart: $('#mainwp-module-cost-tracker-costs-filter-dtsstart').val(),
                        seg_dtsstop: $('#mainwp-module-cost-tracker-costs-filter-dtsstop').val(),
                        seg_id:$('#module-cost-tracker-filter-save-segment-button').attr('selected-segment-id'),
                    });

                    jQuery('#mainwp-cost-tracker-edit-segment-status').html('<i class="notched circle loading icon"></i> ' + __('Saving segment. Please wait...')).show();

                    jQuery.post(ajaxurl, data, function (response) {
                        if (response.error != undefined) {
                            jQuery('#mainwp-cost-tracker-edit-segment-status').html(response.error).addClass('red');
                        } else if (response.result == 'SUCCESS') {
                            jQuery('#mainwp-cost-tracker-edit-segment-status').html(__('Segment saved successfully.')).addClass('green');
                            setTimeout(function () {
                                jQuery('#mainwp-cost-tracker-edit-segment-status').fadeOut(300);
                                jQuery( '#mainwp-module-cost-tracker-segment-modal' ).modal('hide');
                            }, 2000);
                        } else {
                            jQuery('#mainwp-cost-tracker-edit-segment-status').html(__('Undefined error occured while saving your segment!')).addClass('red');
                        }
                    }, 'json');



                    return false;
                });

                jQuery('#mainwp-cost-tracker-select-segment-choose-button').on( 'click', function () {
                    mainwp_module_cost_tracker_hide_segments_message();
                    let seg_id = jQuery( '#mainwp-cost-tracker-segment-select-fields .ui.dropdown').dropdown('get value');
                    let seg_values = '';
                    if('' != seg_id ) {
                        seg_values = jQuery( '#mainwp-cost-tracker-segment-select-fields select > option[value="' +seg_id+ '"]').attr('segment-filters');
                    }

                    let valErr = true;
                    let arrVal = '';

                    let fieldsAllows = [
                        'seg_sites',
                        'seg_clients',
                        'seg_prods_types',
                        'seg_costs_state',
                        'seg_license_types',
                        'seg_renewal_frequency',
                        'seg_payment_methods',
                        'seg_dtsstart',
                        'seg_dtsstop',
                    ];
                    if('' != seg_values ) {
                        try {
                            seg_values = JSON.parse(seg_values);
                            if('' != seg_values){
                                jQuery( '#module-cost-tracker-filter-save-segment-button' ).attr('selected-segment-id',seg_id);
                                jQuery( '#module-cost-tracker-filter-save-segment-button' ).attr('selected-segment-name',seg_values.name);

                                for (const [key, value] of Object.entries(seg_values)) {
                                    try {
                                        if(fieldsAllows.includes(key)){
                                            if( 'seg_dtsstart' !== key && 'seg_dtsstop' !== key ){
                                                jQuery( '#mainwp-module-cost-tracker-costs-filters-row .ui.dropdown.' + key ).dropdown('clear');
                                                arrVal = value.split(",");
                                                jQuery( '#mainwp-module-cost-tracker-costs-filters-row .ui.dropdown.' + key ).dropdown('set selected', arrVal);
                                            } else {
                                                jQuery( '#mainwp-module-cost-tracker-costs-filters-row .ui.calendar.' + key ).calendar('set date', value );
                                            }
                                        }
                                    } catch (err) {
                                        console.log(err);
                                    }
                                }
                                jQuery( '#mainwp-module-cost-tracker-segment-modal' ).modal('hide');
                                mainwp_module_cost_tracker_manage_costs_filter();
                                valErr = false;
                            }
                        } catch (err) {
                            console.log(err);
                        }
                    }
                    if(valErr){
                        jQuery('#mainwp-cost-tracker-edit-segment-status').html(__('Undefined error segment values! Please try again.')).addClass('red').show();
                    }
                });


                jQuery('#mainwp-cost-tracker-select-segment-delete-button').on( 'click', function () {
                    mainwp_module_cost_tracker_hide_segments_message();
                    let delBtn = this;
                    let seg_id = jQuery( '#mainwp-cost-tracker-segment-select-fields .ui.dropdown').dropdown('get value');
                    if('' == seg_id){
                        return false;
                    }

                    if('yes' === jQuery(delBtn).attr('running')){
                        return false;
                    }

                    jQuery(seg_id).attr('running', 'yes');
                    let data = mainwp_secure_data({
                        action: 'mainwp_module_cost_tracker_filter_delete_segment',
                        seg_id: seg_id,
                    });
                    jQuery('#mainwp-cost-tracker-edit-segment-status').html('<i class="notched circle loading icon"></i> ' + __('Deleting segment. Please wait...')).show();
                    jQuery.post(ajaxurl, data, function (response) {

                        jQuery(delBtn).removeAttr('running');

                        if (response.error != undefined) {
                            jQuery('#mainwp-cost-tracker-edit-segment-status').html(response.error).addClass('red');
                        } else if (response.result == 'SUCCESS') {
                            jQuery('#mainwp-cost-tracker-edit-segment-status').html(__('Segment deleted successfully.')).addClass('green');
                            setTimeout(function () {
                                jQuery('#mainwp-cost-tracker-edit-segment-status').fadeOut(300);
                                jQuery( '#mainwp-module-cost-tracker-segment-modal' ).modal('hide');
                            }, 2000);
                        } else {
                            jQuery('#mainwp-cost-tracker-edit-segment-status').html(__('Undefined error occured while deleting your segment!')).addClass('red');
                        }
                    }, 'json');

                    return false;
                });

            } );
        </script>
        <?php
        static::render_modal_save_segment();
    }


    /**
     * Method render_modal_save_segment()
     *
     * Render modal window.
     *
     * @return void
     */
    public static function render_modal_save_segment() {
        ?>
        <div id="mainwp-module-cost-tracker-segment-modal" class="ui tiny modal">
            <i class="close icon" id="mainwp-notes-subs-cancel"></i>
            <div class="header"><?php esc_html_e( 'Save Segment', 'mainwp' ); ?></div>
            <div class="content" id="mainwp-cost-tracker-segment-content">
                <div id="mainwp-cost-tracker-edit-segment-status" class="ui message hidden"></div>
                <div id="mainwp-cost-tracker-segment-edit-fields" class="ui form">
                    <div class="field">
                        <label><?php esc_html_e( 'Enter the segment name', 'mainwp' ); ?></label>
                    </div>
                    <div class="field">
                        <input type="text" id="mainwp-cost-tracker-edit-segment-name" value=""/>
                    </div>
                </div>
                <div id="mainwp-cost-tracker-segment-select-fields" style="display:none;">
                    <div class="field">
                        <label><?php esc_html_e( 'Select a segment', 'mainwp' ); ?></label>
                    </div>
                    <div class="field">
                        <div id="mainwp-cost-tracker-segments-lists-wrapper"></div>
                    </div>
                </div>
            </div>
            <div class="actions">
                <div class="ui grid">
                    <div class="eight wide left aligned middle aligned column">
                        <input type="button" class="ui green button" id="mainwp-cost-tracker-edit-segment-save" value="<?php esc_attr_e( 'Save', 'mainwp' ); ?>"/>
                        <input type="button" class="ui green button" id="mainwp-cost-tracker-select-segment-choose-button" value="<?php esc_attr_e( 'Choose', 'mainwp' ); ?>" style="display:none;"/>
                        <input type="button" class="ui basic button" id="mainwp-cost-tracker-select-segment-delete-button" value="<?php esc_attr_e( 'Delete', 'mainwp' ); ?>" style="display:none;"/>
                    </div>
                    <div class="eight wide column">

                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Method render_modal_edit_notes()
     *
     * Render modal window for edit notes.
     *
     * @param string $what What modal window to render. Default = site.
     *
     * @return void
     */
    public static function render_modal_edit_notes( $what = 'site' ) {
        ?>
        <div id="mainwp-notes-subs-modal" class="ui modal">
            <i class="close icon" id="mainwp-notes-subs-cancel"></i>
            <div class="header"><?php esc_html_e( 'Notes', 'mainwp' ); ?></div>
            <div class="content" id="mainwp-notes-subs-content">
                <div id="mainwp-notes-subs-status" class="ui message hidden"></div>
                <div id="mainwp-notes-subs-html"></div>
                <div id="mainwp-notes-subs-editor" class="ui form" style="display:none;">
                    <div class="field">
                        <label><?php esc_html_e( 'Edit note', 'mainwp' ); ?></label>
                        <textarea id="mainwp-notes-subs-note"></textarea>
                    </div>
                    <div><?php esc_html_e( 'Allowed HTML tags:', 'mainwp' ); ?> &lt;p&gt;, &lt;strong&gt;, &lt;em&gt;, &lt;br&gt;, &lt;hr&gt;, &lt;a&gt;, &lt;ul&gt;, &lt;ol&gt;, &lt;li&gt;, &lt;h1&gt;, &lt;h2&gt; </div>
                </div>
            </div>
            <div class="actions">
                <div class="ui grid">
                    <div class="eight wide left aligned middle aligned column">
                        <input type="button" class="ui green button" id="mainwp-notes-subs-save" value="<?php esc_attr_e( 'Save Note', 'mainwp' ); ?>" style="display:none;"/>
                        <input type="button" class="ui green button" id="mainwp-notes-subs-edit" value="<?php esc_attr_e( 'Edit Note', 'mainwp' ); ?>"/>
                    </div>
                    <div class="eight wide column">
                        <input type="hidden" id="mainwp-notes-subs-subid" value=""/>
                        <input type="hidden" id="mainwp-notes-subs-slug" value=""/>
                        <input type="hidden" id="mainwp-which-note" value="<?php echo esc_html( $what ); ?>"/>
                    </div>
                </div>
            </div>
        </div>

        <?php
    }

    /**
     * Render Page Settings.
     */
    public static function render_screen_options() {

        $columns = static::get_columns();

        $show_cols = get_user_option( 'mainwp_module_costs_tracker_manage_showhide_columns' );

        if ( ! is_array( $show_cols ) ) {
            $show_cols = array();
        }

        ?>
        <div class="ui modal" id="mainwp-module-cost-tracker-sites-screen-options-modal">
            <div class="header"><?php esc_html_e( 'Page Settings', 'mainwp' ); ?></div>
            <div class="scrolling content ui form">
                <form method="POST" action="" id="subscription-sites-screen-options-form" name="subscription_sites_screen_options_form">
                <?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
                    <input type="hidden" name="wp_nonce" value="<?php echo esc_attr( wp_create_nonce( 'CostTrackerSitesScrOptions' ) ); ?>" />
                        <div class="ui grid field">
                            <label class="six wide column"><?php esc_html_e( 'Show columns', 'mainwp' ); ?></label>
                            <div class="ten wide column">
                                <ul class="mainwp_hide_wpmenu_checkboxes">
                                <?php
                                foreach ( $columns as $name => $title ) {
                                    if ( 'cb' === $name ) {
                                        continue;
                                    }
                                    ?>
                                        <li>
                                            <div class="ui checkbox">
                                                <input type="checkbox"
                                            <?php
                                            $show_col = ! isset( $show_cols[ $name ] ) || ( 1 === (int) $show_cols[ $name ] );
                                            if ( $show_col ) {
                                                echo 'checked="checked"';
                                            }
                                            ?>
                                                id="mainwp_show_column_<?php echo esc_attr( $name ); ?>" name="mainwp_show_column_<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $name ); ?>">
                                                <label for="mainwp_show_column_<?php echo esc_attr( $name ); ?>" ><?php echo $title; //phpcs:ignore ?></label>
                                                <input type="hidden" value="<?php echo esc_attr( $name ); ?>" name="show_columns_name[]" />
                                            </div>
                                        </li>
                                        <?php
                                }
                                ?>
                                </ul>
                            </div>
                    </div>
                </div>
            <div class="actions">
                    <div class="ui two columns grid">
                        <div class="left aligned column">
                            <span data-tooltip="<?php esc_attr_e( 'Returns this page to the state it was in when installed. The feature also restores any column you have moved through the drag and drop feature on the page.', 'mainwp' ); ?>" data-inverted="" data-position="top center"><input type="button" class="ui button" name="reset" id="reset-subscriptionsites-settings" value="<?php esc_attr_e( 'Reset Page', 'mainwp' ); ?>" /></span>
                        </div>
                        <div class="ui right aligned column">
                    <input type="submit" class="ui green button" name="btnSubmit" id="submit-subscriptionsites-settings" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>" />
                    <div class="ui cancel button"><?php esc_html_e( 'Close', 'mainwp' ); ?></div>
                </div>
                    </div>
                </div>
                <input type="hidden" name="reset_subscriptionsites_columns_order" value="0">
            </form>
        </div>
        <div class="ui small modal" id="mainwp-module-cost-tracker-sites-site-preview-screen-options-modal">
            <div class="header"><?php esc_html_e( 'Screen Options', 'mainwp' ); ?></div>
            <div class="scrolling content ui form">
                <span><?php esc_html_e( 'Would you like to turn on home screen previews? This function queries WordPress.com servers to capture a screenshot of your site the same way comments shows you preview of URLs.', 'mainwp' ); ?>
            </div>
            <div class="actions">
                <div class="ui ok button"><?php esc_html_e( 'Yes', 'mainwp' ); ?></div>
                <div class="ui cancel button"><?php esc_html_e( 'No', 'mainwp' ); ?></div>
            </div>
        </div>
        <script type="text/javascript">
            jQuery( document ).ready( function () {
                jQuery('#reset-subscriptionsites-settings').on( 'click', function () {
                    mainwp_confirm(__( 'Are you sure.' ), function(){
                        jQuery('.mainwp_hide_wpmenu_checkboxes input[id^="mainwp_show_column_"]').prop( 'checked', false );
                        //default columns
                        let cols = ['name','type','product_type','price','cost_status','license_type','last_renewal','next_renewal','payment_method','sites','actions'];
                        jQuery.each( cols, function ( index, value ) {
                            jQuery('.mainwp_hide_wpmenu_checkboxes input[id="mainwp_show_column_' + value + '"]').prop( 'checked', true );
                        } );
                        jQuery('input[name=reset_subscriptionsites_columns_order]').attr('value',1);
                        jQuery('#submit-subscriptionsites-settings').click();
                    }, false, false, true );
                    return false;
                });
            } );
        </script>
            <?php
    }
}
