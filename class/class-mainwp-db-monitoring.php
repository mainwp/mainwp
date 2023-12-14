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
 *
 * @package MainWP\Dashboard
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
		if ( null === self::$instance ) {
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
	 *
	 * @return int $insert_id False or inserted id.
	 */
	public function insert_website_status( $fields ) {

		if ( empty( $fields ) || ! is_array( $fields ) ) {
			return false;
		}

		if ( ! isset( $fields['event_timestamp'] ) || ! isset( $fields['wpid'] ) || empty( $fields['wpid'] ) ) {
			return false;
		}
		$this->wpdb->insert( $this->table_name( 'wp_status' ), $fields );
		return $this->wpdb->insert_id;
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
		$this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name( 'wp_status' ) . ' WHERE event_timestamp < %d ', time() - $days * DAY_IN_SECONDS ) );
	}
}
