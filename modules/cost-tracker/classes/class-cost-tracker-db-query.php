<?php
/**
 * MainWP Module Cost Tracker DB class.
 *
 * @package MainWP\Dashboard
 * @version 4.6
 */

namespace MainWP\Dashboard\Module\CostTracker;

use MainWP\Dashboard\MainWP_Install;
use MainWP\Dashboard\MainWP_Utility;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Client;

/**
 * Class Cost_Tracker_DB_Query
 */
class Cost_Tracker_DB_Query extends Cost_Tracker_DB {
    //phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery

    /**
     * Private static variable to hold the single instance of the class.
     *
     * @static
     *
     * @var mixed Default null
     */
    private static $instance = null;


    /**
     * Method instance()
     *
     * Return public static instance.
     *
     * @static
     * @return instance of class.
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Query costs records
     *
     * @param array $args Arguments to filter the records by.
     *
     * @return array Records
     */
    public function query_costs( $args ) { //phpcs:ignore -- NOSONAR - complex.
        global $wpdb;

        $all_defaults = Cost_Tracker_Admin::get_default_fields_values();

        $filter_site_ids   = ! empty( $args['filter_sites'] ) ? $args['filter_sites'] : array();
        $filter_client_ids = ! empty( $args['filter_clients'] ) ? $args['filter_clients'] : array();

        $filter_prod_type_slugs = ! empty( $args['filter_prods_types'] ) ? $args['filter_prods_types'] : false;
        $filter_cost_state      = ! empty( $args['filter_states'] ) ? $args['filter_states'] : false;

        $filter_license_type   = ! empty( $args['filter_license_type'] ) ? $args['filter_license_type'] : false;
        $filter_payment_method = ! empty( $args['filter_payment_method'] ) ? $args['filter_payment_method'] : false;
        $filter_renewal_type   = ! empty( $args['filter_renewal_type'] ) ? $args['filter_renewal_type'] : false;

        $filter_dtsstart = ! empty( $args['dtsstart'] ) ? strtotime( $args['dtsstart'] ) : false;
        $filter_dtsstop  = ! empty( $args['dtsstop'] ) ? ( strtotime( $args['dtsstop'] ) + DAY_IN_SECONDS - 1 ) : false;

        if ( ! empty( $filter_client_ids ) ) {
            $filter_client_ids = MainWP_Utility::array_numeric_filter( $filter_client_ids );
        }
        if ( ! empty( $filter_site_ids ) ) {
            $filter_site_ids = MainWP_Utility::array_numeric_filter( $filter_site_ids );
        }

        if ( ! empty( $filter_prod_type_slugs ) ) {
            $product_types          = Cost_Tracker_Admin::get_product_types();
            $product_types          = array_keys( $product_types );
            $filter_prod_type_slugs = array_filter(
                $filter_prod_type_slugs,
                function ( $e ) use ( $product_types ) {
                    return is_string( $e ) && in_array( $e, $product_types, true ) ? true : false; // to valid.
                }
            );
        }

        if ( ! empty( $filter_cost_state ) ) {
            $costs_state       = Cost_Tracker_Admin::get_cost_status();
            $costs_state       = array_keys( $costs_state );
            $filter_cost_state = array_filter(
                $filter_cost_state,
                function ( $e ) use ( $costs_state ) {
                    return is_string( $e ) && in_array( $e, $costs_state, true ) ? true : false; // to valid.
                }
            );
        }

        $license_types     = $all_defaults['license_types'];
        $payment_methods   = $all_defaults['payment_methods'];
        $renewal_frequency = $all_defaults['renewal_frequency'];

        if ( ! empty( $filter_license_type ) ) {
            $license_types       = array_keys( $license_types );
            $filter_license_type = array_filter(
                $filter_license_type,
                function ( $e ) use ( $license_types ) {
                    return is_string( $e ) && in_array( $e, $license_types, true ) ? true : false; // to valid.
                }
            );
        }
        if ( ! empty( $filter_payment_method ) ) {
            $payment_methods       = array_keys( $payment_methods );
            $filter_payment_method = array_filter(
                $filter_payment_method,
                function ( $e ) use ( $payment_methods ) {
                    return is_string( $e ) && in_array( $e, $payment_methods, true ) ? true : false; // to valid.
                }
            );
        }
        if ( ! empty( $filter_renewal_type ) ) {
            $renewal_frequency   = array_keys( $renewal_frequency );
            $filter_renewal_type = array_filter(
                $filter_renewal_type,
                function ( $e ) use ( $renewal_frequency ) {
                    return is_string( $e ) && in_array( $e, $renewal_frequency, true ) ? true : false; // to valid.
                }
            );
        }

        $where = '';

        $allowed_search_fields = array( 'co.name', 'co.product_type', 'co.payment_method', 'co.type' );

        $where_search = array();
        if ( ! empty( $args['search'] ) ) {
            // Sanitize field.
            foreach ( $allowed_search_fields as $field ) {
                $where_search[] = $this->wpdb->prepare( " {$field} LIKE %s ", "%{$args['search']}%" ); //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            }
        }

        if ( ! empty( $where_search ) ) {
            $where .= ' AND (' . implode( ' OR ', $where_search ) . ')';
        }
        $join_lookup   = '';
        $filter_lookup = ! empty( $filter_site_ids ) || ! empty( $filter_client_ids ) ? true : false;

        if ( $filter_lookup ) {
            $join_lookup = ' JOIN ' . $this->table_name( 'lookup_item_objects' ) . ' lco ON co.id = lco.item_id AND lco.item_name = "cost" ';
            $join_and    = array();

            $clients_sites_ids = array();
            if ( $filter_client_ids ) {
                $join_and[] = ' lco.object_name = "client" AND lco.object_id IN (' . implode( ',', $filter_client_ids ) . ') ';

                // if filter by clients need to get sites of the clients to search in lookup table.
                $cli_sites = MainWP_DB_Client::instance()->get_websites_by_client_ids( $filter_client_ids );
                if ( $cli_sites ) {
                    foreach ( $cli_sites as $cli_site ) {
                        $clients_sites_ids[] = $cli_site->id;
                    }
                }
            }

            $filter_site_ids = array_unique( array_merge( $filter_site_ids, $clients_sites_ids ) );

            if ( $filter_site_ids ) {
                $join_and[] = ' lco.object_name = "site" AND lco.object_id IN (' . implode( ',', $filter_site_ids ) . ') ';
            }

            if ( ! empty( $join_and ) ) {
                if ( 1 === count( $join_and ) ) {
                    $join_lookup .= ' AND ' . $join_and[0];
                } elseif ( 2 === count( $join_and ) ) {
                    $join_lookup .= ' AND ( ( ' . $join_and[0] . ' ) OR ( ' . $join_and[1] . ' ) )';
                } else {
                    $join_lookup = '';
                }
            } else {
                $join_lookup = '';
            }
        }

        if ( ! empty( $filter_prod_type_slugs ) && is_array( $filter_prod_type_slugs ) ) {
            $filter_prod_type_slugs = MainWP_DB::instance()->escape_array( $filter_prod_type_slugs );
            if ( ! empty( $filter_prod_type_slugs ) ) {
                $where .= ' AND co.product_type IN ("' . implode( '","', $filter_prod_type_slugs ) . '") ';
            }
        }

        if ( ! empty( $filter_cost_state ) && is_array( $filter_cost_state ) ) {
            $filter_cost_state = MainWP_DB::instance()->escape_array( $filter_cost_state );
            if ( ! empty( $filter_cost_state ) ) {
                $where .= ' AND co.cost_status IN ("' . implode( '","', $filter_cost_state ) . '") ';
            }
        }

        $filter_next_renewal = false;
        if ( ! empty( $filter_dtsstart ) && is_numeric( $filter_dtsstart ) && ! empty( $filter_dtsstop ) && is_numeric( $filter_dtsstop ) && $filter_dtsstart < $filter_dtsstop ) {
            $filter_next_renewal = true;
        }

        if ( $filter_next_renewal ) {
            $stop_start = $filter_dtsstop - $filter_dtsstart;
            $where     .= ' AND cost_status = "active" AND type = "subscription" AND ( CASE ';
            $where     .= ' WHEN renewal_type = "weekly" AND ( ( next_renewal >= ' . intval( $filter_dtsstart ) . ' AND ( next_renewal <= ' . intval( $filter_dtsstop ) . ' ) ) OR ( next_renewal < ' . intval( $filter_dtsstart ) . ' AND ( MOD( ' . intval( $filter_dtsstop ) . ' - next_renewal, ' . intval( WEEK_IN_SECONDS ) . '  ) < ' . $stop_start . ' ) ) ) THEN true ';
            $where     .= ' WHEN renewal_type = "monthly" AND ( ( next_renewal >= ' . intval( $filter_dtsstart ) . ' AND ( next_renewal <= ' . intval( $filter_dtsstop ) . ' ) ) OR ( next_renewal < ' . intval( $filter_dtsstart ) . ' AND ( MOD( ' . intval( $filter_dtsstop ) . ' - next_renewal, ' . intval( MONTH_IN_SECONDS ) . '  ) < ' . $stop_start . ' ) ) ) THEN true ';
            $where     .= ' WHEN renewal_type = "yearly" AND ( ( next_renewal >= ' . intval( $filter_dtsstart ) . ' AND ( next_renewal <= ' . intval( $filter_dtsstop ) . ' ) ) OR ( next_renewal < ' . intval( $filter_dtsstart ) . ' AND ( MOD( ' . intval( $filter_dtsstop ) . ' - next_renewal, ' . intval( YEAR_IN_SECONDS ) . '  ) < ' . $stop_start . ' ) ) ) THEN true ';
            $where     .= ' WHEN renewal_type = "quarterly" AND ( ( next_renewal >= ' . intval( $filter_dtsstart ) . ' AND ( next_renewal <= ' . intval( $filter_dtsstop ) . ' ) ) OR ( next_renewal < ' . intval( $filter_dtsstart ) . ' AND ( MOD( ' . intval( $filter_dtsstop ) . ' - next_renewal, ' . 3 * intval( MONTH_IN_SECONDS ) . '  ) < ' . $stop_start . ' ) ) ) THEN true ';
            $where     .= ' ELSE false END ) ';
        }

        if ( ! empty( $filter_license_type ) && is_array( $filter_license_type ) ) {
            $filter_license_type = MainWP_DB::instance()->escape_array( $filter_license_type );
            if ( ! empty( $filter_license_type ) ) {
                $where .= ' AND co.license_type IN ("' . implode( '","', $filter_license_type ) . '") ';
            }
        }
        if ( ! empty( $filter_payment_method ) && is_array( $filter_payment_method ) ) {
            $filter_payment_method = MainWP_DB::instance()->escape_array( $filter_payment_method );
            if ( ! empty( $filter_payment_method ) ) {
                $where .= ' AND co.payment_method IN ("' . implode( '","', $filter_payment_method ) . '") ';
            }
        }
        if ( ! empty( $filter_renewal_type ) && is_array( $filter_renewal_type ) ) {
            $filter_renewal_type = MainWP_DB::instance()->escape_array( $filter_renewal_type );
            if ( ! empty( $filter_renewal_type ) ) {
                $where .= ' AND co.renewal_type IN ("' . implode( '","', $filter_renewal_type ) . '") ';
            }
        }

        /**
         * PARSE PAGINATION PARAMS
         */
        $limits   = '';
        $start    = isset( $args['start'] ) ? absint( $args['start'] ) : 0;
        $per_page = isset( $args['records_per_page'] ) ? absint( $args['records_per_page'] ) : 99999;

        if ( $per_page >= 0 ) {
            $limits = "LIMIT {$start}, {$per_page}";
        }

        // Default to sorting by record ID.
        $orderby = 'co.id';

        $orderable = array( 'name', 'url', 'type', 'product_type', 'slug', 'license_type', 'cost_status', 'payment_method', 'price', 'renewal_type', 'last_renewal', 'next_renewal' );
        if ( isset( $args['orderby'] ) && in_array( $args['orderby'], $orderable, true ) ) {
            $_orderby = 'next_renewal' === $args['orderby'] ? 'next_renewal_today' : $args['orderby'];
            $prefix   = 'co.';
            $orderby  = sprintf( '%s%s', $prefix, $_orderby );
        }

        // Show the recent records first by default.
        $order = 'DESC';
        if ( isset( $args['order'] ) && 'ASC' === strtoupper( $args['order'] ) ) {
            $order = 'ASC';
        }

        $orderby = sprintf( 'ORDER BY %s %s', $orderby, $order );

        $selects   = array();
        $selects[] = ' DISTINCT(co.id) dist_id '; // need at first.
        $selects[] = ' co.* ';
        $select    = implode( ', ', $selects );

        /**
         * BUILD THE FINAL QUERY
         */
        $query = "SELECT {$select}
        FROM " . $this->table_name( 'cost_tracker' ) . ' as co ' .
        $join_lookup .
        " WHERE 1 {$where}
        {$orderby}
        {$limits}";

        //phpcs:disable Squiz.PHP.CommentedOutCode.Found
        // error_log( print_r( $args, true ) ); //.
        // error_log( $query ); //.
        //phpcs:enable Squiz.PHP.CommentedOutCode.Found

        // Build result count query.
        $count_query = 'SELECT COUNT(DISTINCT(co.id)) as found
        FROM ' . $this->table_name( 'cost_tracker' ) . ' as co ' .
        $join_lookup .
        " WHERE 1 {$where}";

        /**
         * QUERY THE DATABASE FOR RESULTS
         */
        $items = $this->wpdb->get_results( $query );  // phpcs:ignore -- ok.
        $total = absint( $this->wpdb->get_var( $count_query ) );  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        return array(
            'items' => $items,
            'count' => $total,
        );
    }
}
