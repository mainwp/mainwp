<?php
/**
 * Queries the database for logs records.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard\Module\Log;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Client;
use MainWP\Dashboard\MainWP_Utility;

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
		$where = '';

		if ( ! empty( $args['search'] ) ) {
			$field = ! empty( $args['search_field'] ) ? $args['search_field'] : 'item';

			// Sanitize field.
			$allowed_fields = array( 'log_id', 'site_id', 'user_id', 'created', 'item', 'connector', 'context', 'action' );
			if ( in_array( $field, $allowed_fields, true ) ) {
				$where .= $wpdb->prepare( " AND lg.{$field} LIKE %s", "%{$args['search']}%" ); // @codingStandardsIgnoreLine can't prepare column name
			}
		}

		$array_groups_ids  = array();
		$array_clients_ids = array();
		$array_users_ids   = array();

		if ( ! empty( $args['groups_ids'] ) && is_array( $args['groups_ids'] ) ) {
			$array_groups_ids = MainWP_Utility::array_numeric_filter( $args['groups_ids'] );
			if ( ! empty( $array_groups_ids ) ) {
				$groups_sites      = MainWP_DB_Client::instance()->get_websites_by_group_ids( $array_groups_ids );
				$array_website_ids = array();
				if ( $groups_sites ) {
					foreach ( $groups_sites as $website ) {
						$array_website_ids[] = $website->id;
					}
				}
				unset( $groups_sites );
				if ( ! empty( $array_website_ids ) ) {
					$where .= " AND lg.site_id IN ('" . implode( "','", $array_website_ids ) . "') ";
				} else {
					$where .= ' AND false ';
				}
			}
		}

		if ( ! empty( $args['client_ids'] ) && is_array( $args['client_ids'] ) ) {
			$array_clients_ids = MainWP_Utility::array_numeric_filter( $args['client_ids'] );
			if ( ! empty( $array_clients_ids ) ) {
				$client_sites      = MainWP_DB_Client::instance()->get_websites_by_client_ids( $array_clients_ids );
				$array_website_ids = array();
				if ( $client_sites ) {
					foreach ( $client_sites as $website ) {
						$array_website_ids[] = $website->id;
					}
				}
				unset( $client_sites );
				if ( ! empty( $array_website_ids ) ) {
					$where .= " AND lg.site_id IN ('" . implode("','",$array_website_ids) . "') "; // phpcs:ignore -- ok.
				} else {
					$where .= ' AND false ';
				}
			}
		}

		if ( ! empty( $args['user_ids'] ) && is_array( $args['user_ids'] ) ) {
			$array_users_ids = MainWP_Utility::array_numeric_filter( $args['user_ids'] );
			if ( ! empty( $array_users_ids ) ) {
				$where .= " AND lg.user_id IN ('" . implode("','",$array_users_ids) . "') "; // phpcs:ignore -- ok.
			}
		}

		$where_prev = '';

		if ( ! empty( $args['timestart'] ) && ! empty( $args['timestop'] ) ) {
			$where .= $wpdb->prepare( ' AND `lg`.`created` >= %d AND `lg`.`created` <= %d', $args['timestart'], $args['timestop'] );
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

		$limits_recent_count = '';

		// list recent events.
		if ( ! empty( $args['recent_number'] ) ) {
			$limits_recent_count = ' LIMIT ' . intval( $args['recent_number'] );
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

		$join = ' LEFT JOIN ' . $this->get_log_meta_view() . ' meta_view ON lg.log_id = meta_view.view_log_id ';

		/**
		 * BUILD THE FINAL QUERY
		 */
		$query = "SELECT {$select}
		FROM $wpdb->mainwp_tbl_logs as lg
		{$join}
		WHERE `lg`.`connector` != 'compact' {$where}
		{$orderby}
		{$limits}";

		// Build result count query.
		$count_query = "SELECT COUNT(*) as found
		FROM $wpdb->mainwp_tbl_logs as lg
		{$join}
		WHERE `lg`.`connector` != 'compact' {$where}
		{$limits_recent_count}";

		//phpcs:ignore Squiz.PHP.CommentedOutCode.Found
		// error_log( print_r( $args, true ) );//.

		//phpcs:ignore Squiz.PHP.CommentedOutCode.Found
		// error_log( $query );//.

		//phpcs:ignore Squiz.PHP.CommentedOutCode.Found
		// error_log( $count_query );//.

		/**
		 * QUERY THE DATABASE FOR RESULTS
		 */
		$result = array(
			'items' => $wpdb->get_results( $query ), // phpcs:ignore -- ok.
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
		$view .= ',(SELECT site_name.meta_value FROM ' . $wpdb->mainwp_tbl_logs_meta . ' site_name WHERE  site_name.meta_log_id = intlog.log_id AND site_name.meta_key = "site_name" LIMIT 1) AS log_site_name,
		(SELECT siteurl.meta_value FROM ' . $wpdb->mainwp_tbl_logs_meta . ' siteurl WHERE  siteurl.meta_log_id = intlog.log_id AND siteurl.meta_key = "siteurl" LIMIT 1) AS url,';
		$view .= '(SELECT extra_info.meta_value FROM ' . $wpdb->mainwp_tbl_logs_meta . ' extra_info WHERE  extra_info.meta_log_id = intlog.log_id AND extra_info.meta_key = "extra_info" LIMIT 1) AS extra_info ';
		$view .= ' FROM ' . $wpdb->mainwp_tbl_logs . ' intlog)';
		return $view;
	}
}
