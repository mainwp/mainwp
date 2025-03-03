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
class Log_DB_Helper extends MainWP_DB {

    /**
     * Protected static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    protected static $instance = null;

    /**
     * Return the single instance of the class.
     *
     * @return mixed $instance The single instance of the class.
     */
    public static function instance() {
        if ( is_null( static::$instance ) ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Method get_log_by_id().
     *
     * @param int $log_id Log id.
     *
     * @return object|false log.
     */
    public function get_log_by_id( $log_id ) {
        if ( empty( $log_id ) ) {
            return false;
        }
        return $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name( 'wp_logs' ) . ' WHERE log_id = %d ', $log_id ) ); //phpcs:ignore -- ok.
    }

    /**
     * Method update_log().
     *
     * @param array $data Log data.
     *
     * @return mixed
     */
    public function update_log( $data ) {
        if ( empty( $data ) || ! is_array( $data ) || empty( $data['log_id'] ) ) {
            return false;
        }

        $log_id = $data['log_id'];
        unset( $data['log_id'] );

        return $this->wpdb->update( $this->table_name( 'wp_logs' ), $data, array( 'log_id' => $log_id ) );
    }

    /**
     * Method count_events().
     *
     * @return mixed Count events.
     */
    public function count_events() {
        return $this->wpdb->get_var( 'SELECT count(*) FROM ' . $this->table_name( 'wp_logs' ) . ' WHERE dismiss = 0 ' ); //phpcs:ignore -- ok.
    }
}
