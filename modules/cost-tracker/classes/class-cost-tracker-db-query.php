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
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Class constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		parent::__construct();
	}


	/**
	 * Query costs records
	 *
	 * @param array $args Arguments to filter the records by.
	 *
	 * @return array Records
	 */
	public function query_costs( $args ) {
		global $wpdb;

		$count_sites = ! empty( $args['count_sites'] ) ? true : false;

		$filter_dtsstart = ! empty( $args['dtsstart'] ) ? strtotime( $args['dtsstart'] ) : false;
		$filter_dtsstop  = ! empty( $args['dtsstop'] ) ? ( strtotime( $args['dtsstop'] ) + DAY_IN_SECONDS - 1 ) : false;

		$filter_client_ids      = ! empty( $args['filter_clients'] ) ? $args['filter_clients'] : false;
		$filter_prod_type_slugs = ! empty( $args['filter_prods_types'] ) ? $args['filter_prods_types'] : false;
		$filter_cost_state      = ! empty( $args['filter_states'] ) ? $args['filter_states'] : false;

		if ( ! empty( $filter_client_ids ) ) {
			$filter_client_ids = MainWP_Utility::array_numeric_filter( $filter_client_ids );
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
			$costs_state       = Cost_Tracker_Admin::get_cost_status( false, true );
			$costs_state       = array_keys( $costs_state );
			$filter_cost_state = array_filter(
				$filter_cost_state,
				function ( $e ) use ( $costs_state ) {
					return is_string( $e ) && in_array( $e, $costs_state, true ) ? true : false; // to valid.
				}
			);
		}

		$join  = '';
		$where = '';

		$allowed_search_fields = array( 'co.name', 'co.product_type', 'co.payment_method', 'co.price', 'co.type' );

		$where_search = array();
		if ( ! empty( $args['search'] ) ) {
			// Sanitize field.
			foreach ( $allowed_search_fields as $field ) {
				$where_search[] = $this->wpdb->prepare( " {$field} LIKE %s ", "%{$args['search']}%" ); // @codingStandardsIgnoreLine can't prepare column name
			}
		}

		if ( ! empty( $where_search ) ) {
			$where .= ' AND (' . implode( ' OR ', $where_search ) . ')';
		}

		if ( ! empty( $args['client_id'] ) ) {
			$where .= $this->wpdb->prepare( ' AND co.client_id = %d ', $args['client_id'] );
		} elseif ( ! empty( $args['site_id'] ) ) {
			$join  = ' LEFT JOIN ' . $this->table_name( 'cost_tracker' ) . ' wpta ON wpco.task_id = co.task_id '; //phpcs:ignore -- ok.
			$where .= $this->wpdb->prepare( ' AND wpco.site_id = %d AND wpco.wp_task_id IS NOT NULL ', $args['site_id'] );
		}

		if ( ! empty( $filter_client_ids ) && is_array( $filter_client_ids ) ) {
			$where .= ' AND co.client_id IN (' . implode( ',', $filter_client_ids ) . ') ';
		}

		if ( ! empty( $filter_prod_type_slugs ) && is_array( $filter_prod_type_slugs ) ) {
			$where .= ' AND co.product_type IN ("' . implode( '","', $filter_prod_type_slugs ) . '") ';
		}

		if ( ! empty( $filter_cost_state ) && is_array( $filter_cost_state ) ) {
			$where .= ' AND co.cost_status IN ("' . implode( '","', $filter_cost_state ) . '") ';
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
			$prefix  = 'co.';
			$orderby = sprintf( '%s%s', $prefix, $args['orderby'] );
		}

		// Show the recent records first by default.
		$order = 'DESC';
		if ( isset( $args['order'] ) && 'ASC' === strtoupper( $args['order'] ) ) {
			$order = 'ASC';
		}

		$orderby = sprintf( 'ORDER BY %s %s', $orderby, $order );

		$selects   = array();
		$selects[] = 'co.*';
		$select    = implode( ', ', $selects );

		/**
		 * BUILD THE FINAL QUERY
		 */
		$query = "SELECT {$select}
		FROM " . $this->table_name( 'cost_tracker' ) . ' as co ' .
		$join .
		" WHERE 1 {$where}
		{$orderby}
		{$limits}";

		// error_log( print_r( $args, true ) );
		// error_log( $query );

		// Build result count query.
		$count_query = 'SELECT COUNT(*) as found
		FROM ' . $this->table_name( 'cost_tracker' ) . ' as co ' .
		$join .
		" WHERE 1 {$where}";

		/**
		 * QUERY THE DATABASE FOR RESULTS
		 */
		$items = $this->wpdb->get_results( $query );  // phpcs:ignore -- ok.
		$total = absint( $this->wpdb->get_var( $count_query ) );  // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$result = array(
			'items' => $items,
			'count' => $total,
		);

		return $result;
	}
}
