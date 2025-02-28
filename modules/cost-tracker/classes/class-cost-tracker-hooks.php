<?php
/**
 * MainWP Module Cost Tracker Hooks class.
 *
 * @package MainWP\Dashboard
 * @version 4.6
 */

namespace MainWP\Dashboard\Module\CostTracker;

use MainWP\Dashboard\MainWP_DB_Client;
/**
 * Class Cost_Tracker_Hooks
 */
class Cost_Tracker_Hooks {

    /**
     * Public static variable to hold the single instance of the class.
     *
     * @static
     *
     * @var mixed Default null
     */
    public static $instance = null;

    /**
     * Public static variable to hold the clients costs.
     *
     * @var mixed Default null
     */
    public $clients_costs = null;

    /**
     * Public static variable to hold the clients costs.
     *
     * @var mixed Default null
     */
    public $clients_sites = null;

        /**
         * Public static variable to hold the clients costs.
         *
         * @var mixed Default null
         */
    public $sites_costs = null;

    /**
     * Get Instance
     *
     * Creates public static instance.
     *
     * @static
     *
     * @return Cost_Tracker_Hooks
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
    }

    /**
     * Initiate Hooks
     *
     * Initiates hooks for the extension.
     */
    public function init() {
        add_filter( 'mainwp_widgets_screen_options', array( &$this, 'hook_widgets_screen_options' ), 10, 1 ); // for both client, site overview widgets.
        add_filter( 'mainwp_header_actions_right', array( $this, 'hook_screen_options' ), 10, 2 );
        add_action( 'mainwp_clientstable_prepared_items', array( &$this, 'hook_clientstable_prepared_items' ), 10, 2 );
        add_filter( 'mainwp_clients_sitestable_getcolumns', array( &$this, 'hook_manage_clients_column' ), 10 );
        add_filter( 'mainwp_clients_sitestable_item', array( &$this, 'hook_manage_clients_display_item' ), 10 );
        add_filter( 'mainwp_sitestable_getcolumns', array( &$this, 'hook_manage_sites_column' ), 10 );
        add_action( 'mainwp_sitestable_prepared_items', array( &$this, 'hook_sitestable_prepared_items' ), 10, 2 );
        add_filter( 'mainwp_sitestable_item', array( &$this, 'hook_manage_sites_display_item' ), 10 );

        add_filter( 'mainwp_clients_getmetaboxes', array( &$this, 'hook_get_client_page_metaboxes' ), 10, 1 );
        add_filter( 'mainwp_clients_widgets_screen_options', array( &$this, 'hook_get_widgets_screen_options' ), 10, 1 );

        add_filter( 'mainwp_getmetaboxes', array( &$this, 'hook_get_site_overview_page_metaboxes' ), 10, 2 );
    }

    /**
     * Widgets screen options.
     *
     * @param array $input Input.
     *
     * @return array $input Input.
     */
    public function hook_widgets_screen_options( $input ) {
        $input['advanced-cost-tracker-widget'] = esc_html__( 'Cost Tracker', 'mainwp' );
        return $input;
    }

    /**
     * Method hook_screen_options()
     *
     * Create Screen Options button.
     *
     * @param mixed $input Screen options button HTML.
     *
     * @return mixed Screen sptions button.
     */
    public function hook_screen_options( $input ) {
        if ( isset( $_GET['page'] ) && 'ManageCostTracker' === $_GET['page'] ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $input .= '<a class="ui button basic icon" onclick="mainwp_module_cost_tracker_sites_screen_options(); return false;" data-inverted="" data-position="bottom right" href="#" target="_blank" data-tooltip="' . esc_html__( 'Page Settings', 'mainwp' ) . '"><i class="cog icon"></i></a>';
        }
        return $input;
    }


    /**
     * Method hook_manage_clients_column().
     *
     * Handle hook to manage sites columns.
     *
     * @param array $columns sites table columns.
     *
     * @return array $columns columns.
     */
    public function hook_manage_clients_column( $columns ) {
        $columns['client-cost-tracker'] = '<span data-tooltip="30-Day cost forecast" data-position="left center" data-inverted="">' . __( 'Costs', 'mainwp' ) . '</span>';
        return $columns;
    }

    /**
     * Method hook_clientstable_prepared_items().
     *
     * Handle hook to prepared manage sites items.
     *
     * @param array $clients sites prepared.
     * @param array $clients_ids sites ids prepared.
     */
    public function hook_clientstable_prepared_items( $clients, $clients_ids ) {
        unset( $clients );
        if ( null === $this->clients_costs ) {
            $this->clients_sites = array();
            // get all sites of the $clients_ids.
            $cls_sites = MainWP_DB_Client::instance()->get_websites_by_client_ids( $clients_ids );
            if ( is_array( $cls_sites ) ) {
                foreach ( $cls_sites as $cls_site ) {
                    if ( ! empty( $cls_site->client_id ) && ! isset( $this->clients_sites[ $cls_site->client_id ] ) ) {
                        $this->clients_sites[ $cls_site->client_id ] = array();
                    }
                    $this->clients_sites[ $cls_site->client_id ][] = $cls_site->id;
                }
            }
            $this->clients_costs = Cost_Tracker_DB::get_instance()->get_all_cost_trackers_by_clients( $clients_ids, array( 'get_cost_sites' => true ) );
        }
    }

    /**
     * Manage Sites Item
     *
     * Adds the custom column data in the Manage Sites and Monitoring tables.
     *
     * @param array $item Site comlumn data.
     *
     * @return array $item Site comlumn data.
     */
    public function hook_manage_clients_display_item( $item ) { //phpcs:ignore -- NOSONAR - complex.
        if ( ! is_array( $item ) || ! isset( $item['client_id'] ) ) {
            return $item;
        }
        $client_id = $item['client_id'];
        if ( ! isset( $item['client-cost-tracker'] ) ) {

            $current_time = time();
            $upcoming1    = strtotime( gmdate( 'Y-m-d 00:00:00', $current_time ) );
            $upcoming2    = strtotime( '+1 month', $current_time );

            $series_products_price = array();
            $total_prices          = 0;

            // get all cost trackers of current client.
            $client_costs = is_array( $this->clients_costs ) && isset( $this->clients_costs[ $client_id ] ) ? $this->clients_costs[ $client_id ] : array();
            if ( is_array( $client_costs ) && ! empty( $this->clients_sites[ $client_id ] ) ) {

                foreach ( $client_costs as $cost ) {
                    if ( 'active' !== $cost->cost_status || 'subscription' !== $cost->type ) {
                        continue;
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

                        $cost_val = 0;

                        if ( ! empty( $next_price ) && is_array( $cost->cost_sites_ids ) ) {
                            foreach ( $cost->cost_sites_ids as $ct_siteid ) {
                                // if site of cost tracker in the client's sites then calculate the site's cost/price.
                                if ( is_array( $this->clients_sites[ $client_id ] ) && in_array( $ct_siteid, $this->clients_sites[ $client_id ] ) ) {
                                    if ( 'single_site' === $cost->license_type ) {
                                        $cost_val += $cost->price;
                                    } elseif ( 'multi_site' === $cost->license_type && ! empty( $cost->count_sites ) ) {
                                        $cost_val += $cost->price / $cost->count_sites;
                                    }
                                }
                            }
                        }

                        if ( ! empty( $cost_val ) ) {
                            if ( ! isset( $series_products_price[ $cost->id ] ) ) {
                                $series_products_price[ $cost->id ] = 0;
                            }
                            $series_products_price[ $cost->id ] += $cost_val;
                            $total_prices                       += $cost_val;
                        }

                        $next_rl    = Cost_Tracker_Admin::get_next_renewal( $next_rl, $cost->renewal_type, false );
                        $next_price = 0;
                    }
                }
            }

            $params       = '';
            $selected_ids = array_keys( $series_products_price );
            if ( ! empty( $selected_ids ) ) {
                $params = '&selected_ids=' . implode( ',', $selected_ids ) . '&client_id=' . intval( $client_id );
            }
            $item['client-cost-tracker'] = '<a class="ui mini basic grey button" href="admin.php?page=ManageCostTracker' . $params . '" ><i class="file invoice dollar icon"></i>' . Cost_Tracker_Utility::cost_tracker_format_price( $total_prices, true ) . '</a>';

        }
        return $item;
    }



    /**
     * Method hook_manage_sites_column().
     *
     * Handle hook to manage sites columns.
     *
     * @param array $columns sites table columns.
     *
     * @return array $columns columns.
     */
    public function hook_manage_sites_column( $columns ) {
        $columns['site-cost-tracker'] = '<span data-tooltip="30-Day cost forecast" data-position="left center" data-inverted="">' . __( 'Costs', 'mainwp' ) . '</span>';
        return $columns;
    }


    /**
     * Method hook_sitestable_prepared_items().
     *
     * Handle hook to prepared manage sites items.
     *
     * @param array $websites sites prepared.
     *  @param array $site_ids sites ids prepared.
     */
    public function hook_sitestable_prepared_items( $websites, $site_ids ) {
        unset( $websites );
        if ( null === $this->sites_costs ) {
            $this->sites_costs = Cost_Tracker_DB::get_instance()->get_all_cost_trackers_by_sites( $site_ids );
        }
    }

    /**
     * Manage Sites Item
     *
     * Adds the custom column data in the Manage Sites and Monitoring tables.
     *
     * @param array $item Site comlumn data.
     *
     * @return array $item Site comlumn data.
     */
    public function hook_manage_sites_display_item( $item ) { //phpcs:ignore -- NOSONAR - complex.
        if ( ! is_array( $item ) || ! isset( $item['id'] ) ) {
            return $item;
        }

        $item_id = $item['id'];

        if ( ! isset( $item['site-cost-tracker'] ) ) {

            $current_time = time();
            $upcoming1    = strtotime( gmdate( 'Y-m-d 00:00:00', $current_time ) );
            $upcoming2    = strtotime( '+1 month', $current_time );

            $series_products_price = array();
            $total_prices          = 0;
            $site_costs            = is_array( $this->sites_costs ) && isset( $this->sites_costs[ $item_id ] ) ? $this->sites_costs[ $item_id ] : array();
            if ( is_array( $site_costs ) ) {
                foreach ( $site_costs as $cost ) {
                    if ( 'active' !== $cost->cost_status || 'subscription' !== $cost->type ) {
                        continue;
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

                        $price = 0;
                        if ( ! empty( $next_price ) ) {
                            if ( 'single_site' === $cost->license_type ) {
                                $price = $cost->price;
                            } elseif ( 'multi_site' === $cost->license_type && ! empty( $cost->count_sites ) ) {
                                $price = $cost->price / $cost->count_sites;
                            }
                        }

                        if ( ! empty( $price ) ) {

                            if ( ! isset( $series_products_price[ $cost->id ] ) ) {
                                $series_products_price[ $cost->id ] = 0;
                            }

                            $series_products_price[ $cost->id ] += $price;
                            $total_prices                       += $price;
                        }

                        $next_rl    = Cost_Tracker_Admin::get_next_renewal( $next_rl, $cost->renewal_type, false );
                        $next_price = 0;
                    }
                }
            }
            $params       = '';
            $selected_ids = array_keys( $series_products_price );
            if ( ! empty( $selected_ids ) ) {
                $params = '&selected_ids=' . implode( ',', $selected_ids ) . '&site_id=' . intval( $item_id );
            }
            $item['site-cost-tracker'] = '<a class="ui mini basic grey button" href="admin.php?page=ManageCostTracker' . $params . '" ><i class="file invoice dollar icon"></i>' . Cost_Tracker_Utility::cost_tracker_format_price( $total_prices, true ) . '</a>';
        }

        return $item;
    }



    /**
     * Widgets screen options.
     *
     * @param array $input Input.
     *
     * @return array $input Input.
     */
    public function hook_get_widgets_screen_options( $input ) {
        $input['advanced-cost-tracker-costs'] = __( 'Cost Tracker', 'mainwp' );
        return $input;
    }


    /**
     * Method hook_get_client_page_metaboxes().
     *
     * Hook Clients get metaboxes.
     *
     * @param array $widgets Client widgets.
     */
    public function hook_get_client_page_metaboxes( $widgets ) {
        $widgets[] = array(
            'id'       => 'cost-tracker-costs',
            'callback' => array( Cost_Tracker_Clients_Widget::instance(), 'callback_render_costs_widget' ),
        );
        return $widgets;
    }

    /**
     * Method hook_get_client_page_metaboxes().
     *
     * Hook Clients get metaboxes.
     *
     * @param array $widgets Client widgets.
     * @param int   $dashboard_siteid Site Id.
     *
     * @return array $widgets Widgets data.
     */
    public function hook_get_site_overview_page_metaboxes( $widgets, $dashboard_siteid = 0 ) {
        if ( ! empty( $dashboard_siteid ) ) {
            $widgets[] = array(
                'id'       => 'cost-tracker-widget',
                'callback' => array( Cost_Tracker_Sites_Widget::instance(), 'callback_render_costs_widget' ),
            );
        }
        return $widgets;
    }
}
