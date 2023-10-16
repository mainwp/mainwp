<?php
/**
 * MainWP Database Site Actions
 *
 * This file handles all interactions with the Site Actions DB.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard\Module\Log;

use MainWP\Dashboard\MainWP_DB;

/**
 * Class Log_DB_Driver_WPDB
 */
class Log_DB_Driver_WPDB implements Log_DB_Driver {
	/**
	 * Holds Query class
	 *
	 * @var Query
	 */
	protected $query;

	/**
	 * Hold records table name
	 *
	 * @var string
	 */
	public $table;

	/**
	 * Hold meta table name
	 *
	 * @var string
	 */
	public $table_meta;

	/**
	 * Class constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->query      = new Log_Query( $this );
		$this->table      = MainWP_DB::instance()->get_table_name( 'wp_logs' );
		$this->table_meta = MainWP_DB::instance()->get_table_name( 'wp_logs_meta' );

		$wpdb->mainwp_tbl_logs      = $this->table;
		$wpdb->mainwp_tbl_logs_meta = $this->table_meta;
	}

	/**
	 * Insert a record.
	 *
	 * @param array $data Data to insert.
	 *
	 * @return int
	 */
	public function insert_record( $data ) {
		global $wpdb;

		if ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) {
			return false;
		}

		$meta = array();
		if ( array_key_exists( 'meta', $data ) ) {
			$meta = $data['meta'];
			unset( $data['meta'] );
		}

		$result = $wpdb->insert( $this->table, $data ); //phpcs:ignore -- ok.
		if ( ! $result ) {
			return false;
		}

		$record_id = $wpdb->insert_id;

		// Insert record meta.
		foreach ( (array) $meta as $key => $vals ) {
			foreach ( (array) $vals as $val ) {
				if ( is_scalar( $val ) && '' !== $val ) {
					$this->insert_meta( $record_id, $key, $val );
				}
			}
		}

		return $record_id;
	}

	/**
	 * Insert record meta
	 *
	 * @param int    $record_id Record ID.
	 * @param string $key       Meta Key.
	 * @param string $val       Meta Data.
	 *
	 * @return array
	 */
	public function insert_meta( $record_id, $key, $val ) {
		global $wpdb;

		$result = $wpdb->insert( //phpcs:ignore -- ok.
			$this->table_meta,
			array(
				'meta_log_id' => $record_id,
				'meta_key'    => $key, //phpcs:ignore -- ok.
				'meta_value'  => $val, //phpcs:ignore -- ok.
			)
		);

		return $result;
	}

	/**
	 * Retrieve records
	 *
	 * @param array $args Query arguments.
	 *
	 * @return array
	 */
	public function get_records( $args ) {
		return $this->query->query( $args );
	}

	/**
	 * Returns array of existing values for requested column.
	 * Used to fill search filters with only used items, instead of all items.
	 *
	 * GROUP BY allows query to find just the first occurrence of each value in the column,
	 * increasing the efficiency of the query.
	 *
	 * @param string $column Column being filtered.
	 *
	 * @return array
	 */
	public function get_column_values( $column ) {
		global $wpdb;
		return (array) $wpdb->get_results( //phpcs:ignore -- ok.
			"SELECT DISTINCT $column FROM $wpdb->mainwp_tbl_logs", // @codingStandardsIgnoreLine can't prepare column name
			'ARRAY_A'
		);
	}

	/**
	 * Public getter to return table names
	 *
	 * @return array
	 */
	public function get_table_names() {
		return array(
			$this->table,
			$this->table_meta,
		);
	}

	/**
	 * Purge storage.
	 *
	 * @param \MainWP\Dashboard\Module\Log\Log_Manager $manager Instance of the Log_Manager.
	 */
	public function purge_storage( $manager ) {
		// @TODO: Not doing anything here until the deactivation/uninstall flow has been rethought.
	}
}
