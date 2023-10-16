<?php
/**
 * Queries the database for logs records.
 *
 * @package MainWP/Dashboard
 */

 namespace MainWP\Dashboard\Module\Log;

 use \MainWP\Dashboard\MainWP_DB;

/**
 * Class - Log_Query
 */
class Log_Query {
	/**
	 * Hold the number of records found
	 *
	 * @var int
	 */
	public $found_records = 0;

	/**
	 * Query records
	 *
	 * @param array $args Arguments to filter the records by.
	 *
	 * @return array Logs Records
	 */
	public function query( $args ) {
		global $wpdb;

		$join  = '';
		$where = " AND lg.connector != 'compact' ";

		/**
		 * PARSE CORE PARAMS
		 */

		if ( ! empty( $args['search'] ) ) {
			$field = ! empty( $args['search_field'] ) ? $args['search_field'] : 'item';

			// Sanitize field.
			$allowed_fields = array( 'log_id', 'site_id', 'user_id', 'created', 'item', 'connector', 'context', 'action' );
			if ( in_array( $field, $allowed_fields, true ) ) {
				$where .= $wpdb->prepare( " AND lg.{$field} LIKE %s", "%{$args['search']}%" ); // @codingStandardsIgnoreLine can't prepare column name
			}
		}

		/**
		 * PARSE PAGINATION PARAMS
		 */
		$limits   = '';
		$start    = absint( $args['start'] );
		$per_page = absint( $args['records_per_page'] );

		if ( $per_page >= 0 ) {
			$limits = "LIMIT {$start}, {$per_page}";
		}

		/**
		 * PARSE ORDER PARAMS
		 */
		$orderable = array( 'site_id', 'name', 'url', 'user_id', 'item', 'created', 'connector', 'context', 'action', 'duration', 'state' );

		// Default to sorting by record ID.
		$orderby = 'lg.log_id';

		if ( in_array( $args['orderby'], $orderable, true ) ) {
			if ( in_array( $args['orderby'], array( 'name', 'url' ) ) ) {
				$orderby = sprintf( '%s.%s', 'meta_view', $args['orderby'] );
			} else {
				$orderby = sprintf( '%s.%s', 'lg', $args['orderby'] );
			}
		}

		// Show the recent records first by default.
		$order = 'DESC';
		if ( 'ASC' === strtoupper( $args['order'] ) ) {
			$order = 'ASC';
		}

		$orderby = sprintf( 'ORDER BY %s %s', $orderby, $order );

		/**
		 * PARSE FIELDS PARAMETER
		 */
		$selects   = array();
		$selects[] = 'lg.*';
		$selects[] = 'meta_view.*';
		$select    = implode( ', ', $selects );

		// $join = " LEFT JOIN $wpdb->mainwp_tbl_logs_meta as lgmeta ON lg.log_id = lgmeta.meta_log_id ";

		$join = ' LEFT JOIN ' . $this->get_log_meta_view() . ' meta_view ON lg.log_id = meta_view.view_log_id ';

		/**
		 * Filters query WHERE statement as an alternative to filtering
		 * the $query using the hook below.
		 *
		 * @param string $where  WHERE statement.
		 *
		 * @return string
		 */
		$where = apply_filters( 'mainwp_module_log_db_query_where', $where );

		/**
		 * BUILD THE FINAL QUERY
		 */
		$query = "SELECT {$select}
		FROM $wpdb->mainwp_tbl_logs as lg
		{$join}
		WHERE `lg`.`connector` != 'compact' {$where}
		{$orderby}
		{$limits}";

		/**
		 * Filter allows the final query to be modified before execution
		 *
		 * @param string $query
		 * @param array  $args
		 *
		 * @return string
		 */
		$query = apply_filters( 'mainwp_module_log_db_query', $query, $args );

		// Build result count query.
		$count_query = "SELECT COUNT(*) as found
		FROM $wpdb->mainwp_tbl_logs as lg
		{$join}
		WHERE `lg`.`connector` != 'compact' {$where}";

		/**
		 * Filter allows the result count query to be modified before execution.
		 *
		 * @param string $query
		 * @param array  $args
		 *
		 * @return string
		 */
		$count_query = apply_filters( 'mainwp_module_log_db_count_query', $count_query, $args );

		/**
		 * QUERY THE DATABASE FOR RESULTS
		 */
		$result = array(
			'items' => $wpdb->get_results( $query ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			'count' => absint( $wpdb->get_var( $count_query ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		return $result;
	}

	/**
	 * Get logs meta database table view.
	 *
	 * @return string logs meta view.
	 */
	public function get_log_meta_view() {
		global $wpdb;
		$view  = '(SELECT intlog.log_id AS view_log_id ';
		$view .= ',(SELECT site_name.meta_value FROM ' . $wpdb->mainwp_tbl_logs_meta . ' site_name WHERE  site_name.meta_log_id = intlog.log_id AND site_name.meta_key = "site_name" LIMIT 1) AS name,
		(SELECT siteurl.meta_value FROM ' . $wpdb->mainwp_tbl_logs_meta . ' siteurl WHERE  siteurl.meta_log_id = intlog.log_id AND siteurl.meta_key = "siteurl" LIMIT 1) AS url ';
		$view .= ' FROM ' . $wpdb->mainwp_tbl_logs . ' intlog)';
		return $view;
	}

}
