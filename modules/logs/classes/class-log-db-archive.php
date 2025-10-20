<?php
/**
 * MainWP Database Logs Archive.
 *
 * This file handles all interactions with the Client DB.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard\Module\Log;

use MainWP\Dashboard\MainWP_DB;

/**
 * Class Log_DB_Archive
 *
 * @package MainWP\Dashboard
 */
class Log_DB_Archive extends MainWP_DB {

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
     * Method archive_sites_changes().
     *
     * @param int   $before_timestamp Archive Network Activity data created before time.
     * @param int   $by_limit By limit.
     * @param mixed $dismiss Dismiss: false|0|1.
     *
     * @return mixed
     */
    public function archive_sites_changes( $before_timestamp = 0, $by_limit = 0, $dismiss = false ) {
        $before_timestamp = 1000000 * $before_timestamp;
        $where = '';
        $order = '';
        if ( ! empty( $before_timestamp ) ) {
            $where .= ' AND created < ' . (int) $before_timestamp;
        }

        if ( ! empty( $by_limit ) ) {
            $order .= ' ORDER BY created ASC LIMIT ' . (int) $by_limit;
        }

        if ( false !== $dismiss ) {
            $where .= ' AND dismiss = ' . intval( $dismiss );
        }

        $logs = $this->wpdb->get_results(  'SELECT * FROM ' . $this->table_name('wp_logs') . ' WHERE 1 ' . $where . $order , ARRAY_A ); //phpcs:ignore -- NOSONAR -ok.
        if ( $logs ) {
            foreach ( $logs as $log ) {
                $this->archive_log( $log );
            }
        }
    }

    /**
     * Method archive_log().
     *
     * @param array $data Log data to archive.
     *
     * @return mixed
     */
    public function archive_log( $data ) {
        if ( empty( $data ) || ! is_array( $data ) || empty( $data['log_id'] ) ) {
            return false;
        }

        $log_id = $data['log_id'];

        $log = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name('wp_logs') . ' WHERE log_id = %d', $log_id ), ARRAY_A ); //phpcs:ignore -- NOSONAR -ok.
        if ( $log ) {
            $log['archived_at'] = time();
            $this->wpdb->insert( $this->table_name( 'wp_logs_archive' ), $log );
            $log_mt = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name('wp_logs_meta') . ' WHERE meta_log_id = %d', $log_id ), ARRAY_A ); //phpcs:ignore -- NOSONAR -ok.

            if ( $log_mt ) {
                foreach ( $log_mt as $mt ) {
                    $this->wpdb->insert( $this->table_name( 'wp_logs_meta_archive' ), $mt );
                }
            }

            $this->wpdb->delete(
                $this->table_name( 'wp_logs' ),
                array(
                    'log_id' => $log_id,
                )
            );

            $this->wpdb->delete(
                $this->table_name( 'wp_logs_meta' ),
                array(
                    'meta_log_id' => $log_id,
                )
            );

            return $log;
        }
        return false;
    }

    /**
     * Method truncate_archive_tables().
     */
    public function truncate_archive_tables() {
        $this->wpdb->query( 'TRUNCATE TABLE ' . $this->table_name( 'wp_logs_archive' ) ); //phpcs:ignore -- ok.
        $this->wpdb->query( 'TRUNCATE TABLE ' . $this->table_name( 'wp_logs_meta_archive' ) ); //phpcs:ignore -- ok.
        delete_transient( 'mainwp_module_log_transient_db_logs_size' );
    }
}
