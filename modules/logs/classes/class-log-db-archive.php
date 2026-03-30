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

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

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
        $where            = '';
        $order            = '';
        if ( ! empty( $before_timestamp ) ) {
            $where .= ' AND created < ' . (int) $before_timestamp;
        }

        if ( ! empty( $by_limit ) ) {
            $order .= ' ORDER BY created ASC LIMIT ' . (int) $by_limit;
        }

        if ( false !== $dismiss ) {
            $where .= ' AND dismiss = ' . intval( $dismiss );
        }

        $this->bulk_archive_logs( $where, $order );
    }


    /**
     * Bulk archive logs + meta with batching (no transaction).
     *
     * @param string $where      SQL WHERE condition (MUST be prepared).
     * @param string $order      SQL ORDER BY clause (e.g. "ORDER BY created ASC").
     * @param int    $batch_size Number of rows per batch.
     *
     * @return int Total archived rows.
     */
    public function bulk_archive_logs( $where, $order, $batch_size = 1000 ) {
        global $wpdb;

        $table_logs         = $this->table_name( 'wp_logs' );
        $table_logs_archive = $this->table_name( 'wp_logs_archive' );
        $table_meta         = $this->table_name( 'wp_logs_meta' );
        $table_meta_archive = $this->table_name( 'wp_logs_meta_archive' );

        $total_archived = 0;

        do {
            // 1. Get batch IDs.
            $ids = $this->wpdb->get_col(
                "SELECT log_id
                FROM $table_logs
                WHERE 1 $where $order
                LIMIT " . intval( $batch_size )
            );

            if ( empty( $ids ) ) {
                break;
            }

            $ids_in = implode( ',', array_map( 'intval', $ids ) );

            // 2. Archive logs (prevent duplicates).
            $this->wpdb->query(
                "INSERT IGNORE INTO $table_logs_archive
                SELECT l.*, UNIX_TIMESTAMP() AS archived_at
                FROM $table_logs l
                WHERE l.log_id IN ($ids_in)"
            );

            // 3. Archive meta (prevent duplicates).
            $this->wpdb->query(
                "INSERT IGNORE INTO $table_meta_archive
                SELECT m.*
                FROM $table_meta m
                WHERE m.meta_log_id IN ($ids_in)"
            );

            // 4. Delete meta first.
            $this->wpdb->query(
                "DELETE FROM $table_meta
                WHERE meta_log_id IN ($ids_in)"
            );

            // 5. Delete logs
            $this->wpdb->query(
                "DELETE FROM $table_logs
                WHERE log_id IN ($ids_in)"
            );

            $total_archived += count( $ids );

        } while ( count( $ids ) === $batch_size );

        return $total_archived;
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
