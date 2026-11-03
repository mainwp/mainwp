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
     * @return int|false Number of archived rows, or false when rows could not be archived and were kept.
     */
    public function archive_sites_changes( $before_timestamp = 0, $by_limit = 0, $dismiss = false ) {
        $before_timestamp = 1000000 * $before_timestamp;
        $where            = '';
        $order_by         = '';
        $batch_limit      = 0;
        if ( ! empty( $before_timestamp ) ) {
            $where .= ' AND created < ' . (int) $before_timestamp;
        }

        if ( ! empty( $by_limit ) ) {
            $order_by   .= ' created ASC ';
            $batch_limit = (int) $by_limit;
        }

        if ( false !== $dismiss ) {
            $where .= ' AND dismiss = ' . intval( $dismiss );
        }

        return $this->bulk_archive_logs( $where, $order_by, $batch_limit );
    }


    /**
     * Bulk archive logs + meta with batching (no transaction).
     *
     * @param string $where      SQL WHERE condition (MUST be prepared).
     * @param string $order_by   Column and direction for ORDER BY (e.g. "created ASC").
     * @param int    $batch_limit Number of rows per batch.
     *
     * @return int|false Total archived rows, or false when a batch could not be archived. Rows that were not copied are kept.
     */
    public function bulk_archive_logs( $where, $order_by, $batch_limit = 1000 ) {
        // Dismiss All and auto-archive pass 0; an unbounded batch would put every id into each query.
        $batch_limit = (int) $batch_limit;
        if ( $batch_limit <= 0 ) {
            $batch_limit = 1000;
        }

        $table_logs = $this->table_name( 'wp_logs' );

        // Errors are logged by log_archive_error(); printing them would break the AJAX JSON responses.
        $suppress = $this->wpdb->suppress_errors( true );

        $columns = $this->get_archive_copy_columns();
        if ( false === $columns ) {
            $this->wpdb->suppress_errors( $suppress );
            return false;
        }

        $total_archived = 0;

        do {
            // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL fragments are built from integer-cast archive inputs; table name is internal.
            $ids = $this->wpdb->get_col(
                "SELECT log_id
                FROM $table_logs
                WHERE 1 $where " . ( ! empty( $order_by ) ? " ORDER BY $order_by " : '' ) . // phpcs:ignore -- NOSONAR -no user input
                ' LIMIT ' . intval( $batch_limit )
            );

            if ( '' !== $this->wpdb->last_error ) {
                $this->log_archive_error( 'select logs to archive' );
                $this->wpdb->suppress_errors( $suppress );
                return false;
            }

            if ( empty( $ids ) ) {
                break;
            }

            // A batch that keeps rows ends the run; the next batch would select the same rows again.
            if ( ! $this->archive_log_ids( array_map( 'intval', $ids ), $columns ) ) {
                $this->wpdb->suppress_errors( $suppress );
                return false;
            }

            $total_archived += count( $ids );

        } while ( count( $ids ) === $batch_limit ); // Continue if we hit the batch limit.

        $this->wpdb->suppress_errors( $suppress );
        return $total_archived;
    }


    /**
     * Method archive_log().
     *
     * @param array $data Log data to archive.
     *
     * @return mixed The archived log row, or false when the log was not found or could not be archived (the log is kept).
     */
    public function archive_log( $data ) {
        if ( empty( $data ) || ! is_array( $data ) || empty( $data['log_id'] ) ) {
            return false;
        }

        $log_id = (int) $data['log_id'];

        $wpdb = $this->wpdb;
        $log  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE log_id = %d', $this->table_name( 'wp_logs' ), $log_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Live read before a write.
        if ( ! $log ) {
            return false;
        }

        $suppress = $this->wpdb->suppress_errors( true );
        $columns  = $this->get_archive_copy_columns();
        $archived = false !== $columns && $this->archive_log_ids( array( $log_id ), $columns );
        $this->wpdb->suppress_errors( $suppress );

        if ( ! $archived ) {
            return false;
        }

        $log['archived_at'] = time();
        return $log;
    }

    /**
     * Copy logs and their meta to the archive, then delete the copied rows.
     *
     * A row is deleted only when its archive copy exists, so a failed or partial
     * copy keeps the original. A log id that is already archived (INSERT IGNORE
     * skipped it) counts as archived only when the archived row has the same
     * created time, so a retry after a partial run still deletes.
     *
     * @param int[] $ids     Log ids.
     * @param array $columns Columns from get_archive_copy_columns().
     *
     * @return bool True when every given log that still exists was archived and deleted.
     */
    private function archive_log_ids( $ids, $columns ) {
        $wpdb = $this->wpdb;

        $logs_table    = $this->table_name( 'wp_logs' );
        $archive_table = $this->table_name( 'wp_logs_archive' );
        $meta_table    = $this->table_name( 'wp_logs_meta' );
        $meta_archive  = $this->table_name( 'wp_logs_meta_archive' );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The interpolated parts are %d/%i placeholder lists from array_fill(); every value goes through prepare(). Archive writes must not be cached.
        $id_placeholders  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $log_placeholders = implode( ',', array_fill( 0, count( $columns['logs'] ), '%i' ) );
        $sel_placeholders = implode( ',', array_fill( 0, count( $columns['logs'] ), 'l.%i' ) );

        // Name the columns: upgraded installs can have archive columns in a different order than wp_logs.
        $result = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO %i ($log_placeholders, %i) SELECT $sel_placeholders, UNIX_TIMESTAMP() FROM %i l WHERE l.log_id IN ($id_placeholders)",
                array_merge( array( $archive_table ), $columns['logs'], array( 'archived_at' ), $columns['logs'], array( $logs_table ), $ids )
            )
        );
        if ( false === $result ) {
            $this->log_archive_error( 'copy logs to archive' );
            return false;
        }

        $meta_placeholders     = implode( ',', array_fill( 0, count( $columns['meta'] ), '%i' ) );
        $meta_sel_placeholders = implode( ',', array_fill( 0, count( $columns['meta'] ), 'm.%i' ) );

        // Only for logs whose archive row is this log: a reused id must not add meta to an older archived log.
        $result = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO %i ($meta_placeholders)
                SELECT $meta_sel_placeholders FROM %i m
                INNER JOIN %i l ON l.log_id = m.meta_log_id
                WHERE m.meta_log_id IN ($id_placeholders)
                AND EXISTS ( SELECT 1 FROM %i a WHERE a.log_id = l.log_id AND a.created = l.created )",
                array_merge( array( $meta_archive ), $columns['meta'], $columns['meta'], array( $meta_table, $logs_table ), $ids, array( $archive_table ) )
            )
        );
        if ( false === $result ) {
            $this->log_archive_error( 'copy log meta to archive' );
            return false;
        }

        // Delete the log first, and only when it and all of its meta are in the archive.
        // created must match too: MySQL before 8.0 resets AUTO_INCREMENT on restart, so after
        // a Dismiss All a new log can reuse an archived id and INSERT IGNORE would skip it.
        // meta_key is compared as bytes: archive tables created at another time can have another collation.
        $result = $wpdb->query(
            $wpdb->prepare(
                "DELETE l FROM %i l
                WHERE l.log_id IN ($id_placeholders)
                AND EXISTS ( SELECT 1 FROM %i a WHERE a.log_id = l.log_id AND a.created = l.created )
                AND NOT EXISTS (
                    SELECT 1 FROM %i m
                    WHERE m.meta_log_id = l.log_id
                    AND NOT EXISTS ( SELECT 1 FROM %i ma WHERE ma.meta_log_id = m.meta_log_id AND CAST( ma.meta_key AS BINARY ) = CAST( m.meta_key AS BINARY ) )
                )",
                array_merge( array( $logs_table ), $ids, array( $archive_table, $meta_table, $meta_archive ) )
            )
        );
        if ( false === $result ) {
            $this->log_archive_error( 'delete archived logs' );
            return false;
        }

        $result = $wpdb->query(
            $wpdb->prepare(
                "DELETE m FROM %i m
                WHERE m.meta_log_id IN ($id_placeholders)
                AND NOT EXISTS ( SELECT 1 FROM %i l WHERE l.log_id = m.meta_log_id )
                AND EXISTS ( SELECT 1 FROM %i ma WHERE ma.meta_log_id = m.meta_log_id AND CAST( ma.meta_key AS BINARY ) = CAST( m.meta_key AS BINARY ) )",
                array_merge( array( $meta_table ), $ids, array( $logs_table, $meta_archive ) )
            )
        );
        if ( false === $result ) {
            $this->log_archive_error( 'delete archived log meta' );
            return false;
        }

        $kept = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT log_id FROM %i WHERE log_id IN ($id_placeholders)",
                array_merge( array( $logs_table ), $ids )
            )
        );
        if ( '' !== $wpdb->last_error ) {
            $this->log_archive_error( 'check archived logs' );
            return false;
        }
        if ( ! empty( $kept ) ) {
            $this->log_archive_error( 'kept ' . count( $kept ) . ' log(s) that were not copied to the archive' );
            return false;
        }
        // phpcs:enable

        return true;
    }

    /**
     * Columns to copy from the live log tables into the archive tables.
     *
     * Creates missing archive tables (and a missing archived_at column) first. Upgrades
     * before log DB 1.0.1.8 could skip creating them, and the stored version then never
     * runs the install step again.
     *
     * @return array|false Array with 'logs' and 'meta' column lists, or false when the archive cannot hold the rows.
     */
    private function get_archive_copy_columns() {
        $archive_columns = $this->get_table_columns( 'wp_logs_archive' );
        $meta_archive    = $this->get_table_columns( 'wp_logs_meta_archive' );

        if ( empty( $archive_columns ) || empty( $meta_archive ) || ! in_array( 'archived_at', $archive_columns, true ) ) {
            Log_Install::instance()->create_archive_tables();
            $archive_columns = $this->get_table_columns( 'wp_logs_archive' );
            $meta_archive    = $this->get_table_columns( 'wp_logs_meta_archive' );
        }

        $log_columns = array_values( array_intersect( $this->get_table_columns( 'wp_logs' ), array_diff( $archive_columns, array( 'archived_at' ) ) ) );
        $meta        = array_values( array_intersect( $this->get_table_columns( 'wp_logs_meta' ), $meta_archive ) );

        if ( ! in_array( 'log_id', $log_columns, true ) || ! in_array( 'created', $log_columns, true ) || ! in_array( 'archived_at', $archive_columns, true ) || ! in_array( 'meta_log_id', $meta, true ) || ! in_array( 'meta_key', $meta, true ) ) {
            $this->log_archive_error( 'archive tables are missing or lack required columns' );
            return false;
        }

        return array(
            'logs' => $log_columns,
            'meta' => $meta,
        );
    }

    /**
     * Column names of a log table.
     *
     * @param string $suffix Table suffix.
     *
     * @return string[] Column names, empty when the table does not exist.
     */
    private function get_table_columns( $suffix ) {
        // SHOW COLUMNS instead of SHOW TABLES: it also sees temporary tables.
        $wpdb    = $this->wpdb;
        $columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $this->table_name( $suffix ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema read, must not be cached.
        return is_array( $columns ) ? $columns : array();
    }

    /**
     * Log why logs were kept instead of archived.
     *
     * @param string $step What failed.
     */
    private function log_archive_error( $step ) {
        if ( function_exists( 'error_log' ) ) {
            error_log( 'MainWP Network Activity: ' . $step . '. Rows were kept. ' . $this->wpdb->last_error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- The Dismiss error message points admins to the server error log.
        }
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
