<?php
/**
 * MainWP Database Logs.
 *
 * This file handles all interactions with the Client DB.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard\Module\Log;

use MainWP\Dashboard\MainWP_DB;

/**
 * Class Log_DB
 *
 * @package MainWP\Dashboard
 */
class Log_DB extends MainWP_DB {

	/**
	 * Holds the driver instance
	 *
	 * @var Log_DB_Driver
	 */
	public $driver;

	/**
	 * Number of records in last request
	 *
	 * @var int
	 */
	protected $found_records_count = 0;

	/**
	 * Constructor.
	 *
	 * Run each time the class is called.
	 *
	 * @param array $driver  db driver.
	 */
	public function __construct( $driver ) {
		parent::__construct();
		$this->driver = $driver;
	}

	/**
	 * Insert a record
	 *
	 * @param array $record  New record.
	 *
	 * @return int
	 */
	public function insert( $record ) {
		if ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) {
			return false;
		}

		/**
		 * Filter allows modification of record information
		 *
		 * @param array $record
		 *
		 * @return array
		 */
		$record = apply_filters( 'mainwp_module_log_record_array', $record );

		$data = $this->sanitize_record( $record );

		if ( empty( $data ) ) {
			return false;
		}

		$record_id = $this->driver->insert_record( $data );

		if ( ! $record_id ) {
			/**
			 * Fires on a record insertion error
			 *
			 * @param array $record
			 * @param mixed $result
			 */
			do_action( 'mainwp_module_log_record_insert_error', $record, false );

			return false;
		}

		/**
		 * Fires after a record has been inserted
		 *
		 * @param int   $record_id
		 * @param array $record
		 */
		do_action( 'mainwp_module_log_record_inserted', $record_id, $record );

		return absint( $record_id );
	}

	/**
	 * Ensure the record matches our schema.
	 *
	 * @param array $record Record to store.
	 *
	 * @return array
	 */
	protected function sanitize_record( $record ) {
		if ( ! is_array( $record ) ) {
			return array();
		}

		$record_defaults = array(
			'site_id'   => null,
			'user_id'   => null,
			'created'   => null,
			'item'      => null,
			'connector' => null,
			'context'   => null,
			'action'    => null,
			'state'     => null,
			'duration'  => null,
			'meta'      => array(),
		);

		// Records can have only these fields.
		$record = array_intersect_key( $record, $record_defaults );

		// Sanitize all record values.
		return array_map(
			function ( $value ) {
				if ( ! is_array( $value ) ) {
					return wp_strip_all_tags( $value );
				}

				return $value;
			},
			$record
		);
	}

	/**
	 * Get logs records
	 *
	 * @param array $args  Arguments to filter result by.
	 *
	 * @return array Log Records
	 */
	public function get_records( $args ) {
		$defaults = array(
			// Search param.
			'search'           => null,
			'search_field'     => 'item',
			'records_per_page' => get_option( 'posts_per_page', 20 ),
			'paged'            => 1,
			// Order.
			'order'            => 'desc',
			'orderby'          => 'date',
		);

		$args = wp_parse_args( $args, $defaults );

		/**
		 * Filter allows additional arguments to query $args
		 *
		 * @return array  Array of query arguments
		 */
		$args = apply_filters( 'mainwp_module_log_query_args', $args );

		$result                    = (array) $this->driver->get_records( $args );
		$this->found_records_count = isset( $result['count'] ) ? $result['count'] : 0;

		return empty( $result['items'] ) ? array() : $result['items'];
	}

	/**
	 * Helper function, backwards compatibility
	 *
	 * @param array $args  Argument to filter result by.
	 *
	 * @return array Log Records
	 */
	public function query( $args ) {
		return $this->get_records( $args );
	}

	/**
	 * Return the number of records found in last request
	 *
	 * @return int
	 */
	public function get_found_records_count() {
		return $this->found_records_count;
	}

	/**
	 * Public getter to return table names
	 *
	 * @return array
	 */
	public function get_table_names() {
		return $this->driver->get_table_names();
	}




	/**
	 * Create compact logs and erase records from the database.
	 *
	 * @param int $start_time  start time to compact.
	 * @param int $end_time  end time to compact.
	 *
	 * @return mixed results.
	 */
	public function create_compact_and_erase_records( $start_time, $end_time ) {

		global $wpdb;

		$where_compact = $wpdb->prepare( ' AND `logs`.`created` >= %d AND `logs`.`created` <= %d ', $start_time, $end_time );
		$sql           = "SELECT `logs`.* FROM {$wpdb->mainwp_tbl_logs} logs WHERE `logs`.`connector` != 'compact' " . $where_compact;

		$logs       = MainWP_DB::instance()->query( $sql );
		$stats_data = array();

		$done = false;
		while ( $logs && ( $item = MainWP_DB::fetch_object( $logs ) ) ) {
			$conn = $item->connector;
			$cont = $item->context;
			$act  = $item->action;

			if ( empty( $conn ) || empty( $cont ) || empty( $act ) ) {
				continue;
			}

			$year = (int) gmdate( 'Y', $item->created );

			if ( $year < 2020 ) {
				return;
			}

			if ( ! isset( $stats_data[ $year ] ) ) {
				$stats_data[ $year ] = array();
			}

			if ( ! isset( $stats_data[ $year ][ $conn ] ) ) {
				$stats_data[ $year ][ $conn ] = array();
			}
			if ( ! isset( $stats_data[ $year ][ $conn ][ $cont ] ) ) {
				$stats_data[ $year ][ $conn ][ $cont ] = array();
			}

			if ( ! isset( $stats_data[ $year ][ $conn ][ $cont ][ $act ] ) ) {
				$stats_data[ $year ][ $conn ][ $cont ][ $act ] = array(
					'count'    => 0,
					'duration' => 0,
				);
			}
			$stats_data[ $year ][ $conn ][ $cont ][ $act ]['count']    += 1;
			$stats_data[ $year ][ $conn ][ $cont ][ $act ]['duration'] += $item->duration;
			$done = true;
		}

		if ( $done ) {
			foreach ( $stats_data as $year => $data ) {
				$year_data = array(
					'data' => $data,
				);
				do_action( 'mainwp_compact_action', 'saved', $year, $year_data, $start_time, $end_time );
			}

			$this->erase_log_records( $start_time, $end_time );
		}
	}


	/**
	 * Clears logs records from the database.
	 *
	 * @param int $start_time  start time.
	 * @param int $end_time  start end.
	 *
	 * @return int results number.
	 */
	private function erase_log_records( $start_time, $end_time ) {
		global $wpdb;

		$where = $wpdb->prepare( ' AND `logs`.`created` >= %d AND `logs`.`created` <= %d', $start_time, $end_time );

		return $wpdb->query( //phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			"DELETE `logs`, `meta`
			FROM {$wpdb->mainwp_tbl_logs} AS `logs`
			LEFT JOIN {$wpdb->mainwp_tbl_logs_meta} AS `meta`
			ON `meta`.`meta_log_id` = `logs`.`log_id`
			WHERE `logs`.`connector` != 'compact' " . $where // phpcs:ignore -- escaped.
		);
	}
}
