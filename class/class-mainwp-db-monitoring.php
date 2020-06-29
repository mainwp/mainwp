<?php
/**
 * MainWP Database Monitoring Controller
 *
 * This file handles all interactions with the Monitoring DB.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_DB_Monitoring
 */
class MainWP_DB_Monitoring extends MainWP_DB {

	// phpcs:disable WordPress.DB.RestrictedFunctions, WordPress.DB.PreparedSQL.NotPrepared -- unprepared SQL ok, accessing the database directly to custom database functions.

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
	 * Create public static instance.
	 *
	 * @static
	 * @return MainWP_DB_Common
	 */
	public static function instance() {
		if ( null == self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Method insert_website_status()
	 *
	 * Insert website checking status.
	 *
	 * @param array $fields fields to insert.
	 * @param int   $duration duration.
	 *
	 * @return void
	 */
	public function insert_website_status( $fields, $duration ) {

		if ( empty( $fields ) || ! is_array( $fields ) ) {
			return false;
		}

		if ( ! isset( $fields['timestamp_status'] ) || ! isset( $fields['wpid'] ) || empty( $fields['wpid'] ) ) {
			return false;
		}

		$this->wpdb->insert( $this->table_name( 'wp_status' ), $fields );
		$insert_id = $this->wpdb->insert_id;

		if ( $insert_id ) {
			$this->update_duration( $insert_id, $duration );
		}

		return $insert_id;
	}

	/**
	 * Method update_duration()
	 *
	 * Update duration status.
	 *
	 * @param int $statusid Status id.
	 * @param int $duration duration.
	 *
	 * @return void
	 */
	public function update_duration( $statusid, $duration ) {
		return $this->wpdb->query( $this->wpdb->prepare( 'UPDATE ' . $this->table_name( 'wp_status' ) . ' SET duration = %d WHERE statusid = %d', $duration, $statusid ) );
	}

	/**
	 * Method purge_monitoring_records()
	 *
	 * Purge monitoring records.
	 *
	 * @return void
	 */
	public function purge_monitoring_records() {
		$days = 60; // default 60 days.
		$this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'wp_status' ) . ' WHERE timestamp_status < %d ', time() - $days * 24 * 60 * 60 ) );
	}
}
