<?php
/**
 * MainWP System Monitor Storage.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard\SystemMonitor;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Utility;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/**
 * MainWP System Monitor Storage.
 */
class MainWP_System_Monitor_Storage {


    /**
     * Get monitor table name.
     *
     * @param string $suffix Table suffix.
     *
     * @return string Table name.
     */
    public static function get_table_name( $suffix ) {
        return MainWP_DB::instance()->get_table_name( $suffix );
    }

    /**
     * Get db method.
     *
     * @return object wpdb.
     */
    public static function get_db() {
        return MainWP_DB::instance()->get_wpdb_instance();
    }

    /**
     * Save monitor results.
     *
     * @param string $monitor Monitor name.
     * @param array  $results Array of MainWP_System_Monitor_Result objects.
     *
     * @return int Number of results saved.
     */
    public static function save_results( $monitor, array $results ) {

        if ( false === $results || ! is_array( $results ) ) {
            return 0;
        }

        static::delete_current_issues( $monitor );

        $count           = 0;
        $new_issues_keys = array();

        foreach ( $results as $result ) {
            if ( ! $result instanceof MainWP_System_Monitor_Result ) {
                continue;
            }

            $insert_data = static::save_result( $result );

            if ( $insert_data ) {
                ++$count;
                $key                     = static::get_notice_key( $insert_data['issue_code'], $insert_data['entity'] );
                $new_issues_keys[ $key ] = $insert_data['checked_at'];
            }
        }

        if ( ! empty( $new_issues_keys ) ) {
            foreach ( $new_issues_keys as $key => $checked_at ) {
                MainWP_Utility::set_short_term_notice( $monitor, $key, $checked_at );
            }
        }

        // Only delete notice options that are no longer applicable when new issues are saved.
        MainWP_Utility::delete_short_term_notice_options( $monitor, array_keys( $new_issues_keys ) );
        return $count;
    }

    /**
     * Method delete_current_issues().
     *
     * @param string $monitor Monitor name.
     *
     * @return int Count number.
     */
    private static function delete_current_issues( $monitor ) {
        $count_current = self::count_current_issues( $monitor );
        if ( ! empty( $count_current ) ) {
            // Delete issues from the previous scan here, so save_results() doesn't need to.
            self::delete_results( $monitor );
            MainWP_Utility::purge_short_term_notices( $monitor, false );
        }
        return $count_current;
    }

    /**
     * Save a monitor result.
     *
     * @param MainWP_System_Monitor_Result $result Monitor result.
     *
     * @return array Insert Data, or false on failure.
     */
    public static function save_result( MainWP_System_Monitor_Result $result ) {

        $checked_at = time();
        $run_id     = $checked_at;
        $monitor    = $result->get_monitor();

        static::get_db()->insert(
            static::get_table_name( 'system_monitor' ),
            array(
                'run_id'     => $run_id,
                'monitor'    => $monitor,
                'check_name' => $result->get_check_name(),
                'entity'     => $result->get_entity(),
                'issue_code' => $result->get_issue_code(),
                'severity'   => $result->get_severity() ? $result->get_severity() : 'info',
                'payload'    => wp_json_encode( $result->get_payload() ),
                'checked_at' => $checked_at,
            ),
            array(
                '%d',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%d',
            )
        );
        $insert_id = (int) static::get_db()->insert_id;

        if ( $insert_id ) {
            return array(
                'monitor'    => $monitor,
                'entity'     => $result->get_entity(),
                'issue_code' => $result->get_issue_code(),
                'checked_at' => $checked_at,
            );
        }
        return false;
    }


    /**
     * Generate a notice key.
     *
     * @param string $code    Issue code.
     * @param string $entity  Issue entity.
     *
     * @return string Notice key.
     */
    public static function get_notice_key( $code, $entity ) {
        return sanitize_key(
            $code . '_' . $entity
        );
    }

    /**
     * Count current monitor issues.
     *
     * @param string|null $monitor     Optional monitor name.
     * @param bool        $not_fallback Whether to exclude fallback monitor issues.
     *
     * @return int Number of current issues.
     */
    public static function count_current_issues( $monitor = null, $not_fallback = false ) {

        $table = static::get_table_name( 'system_monitor' );

        $where = array();
        $args  = array();

        if ( ! empty( $monitor ) ) {
            $where[] = 'monitor = %s';
            $args[]  = $monitor;
        }

        if ( $not_fallback ) {
            $where[] = 'issue_code <> %s';
            $args[]  = MainWP_System_Monitor_Cron::ISSUE_MONITOR_FALLBACK;
        }

        $sql = "SELECT COUNT(*) FROM {$table}";

        if ( ! empty( $where ) ) {
            $sql .= ' WHERE ' . implode( ' AND ', $where );
        }

        if ( ! empty( $args ) ) {
            $sql = static::get_db()->prepare( $sql, ...$args );
        }

        return (int) static::get_db()->get_var( $sql );
    }

    /**
     * Get monitor issues.
     *
     * @param string|null $monitor      Optional monitor name.
     * @param bool        $not_fallback Whether to exclude fallback monitor issues.
     *
     * @return array
     */
    public static function get_issues( $monitor = null, $not_fallback = true ) {

        $table = static::get_table_name( 'system_monitor' );

        $where = array();
        $args  = array();

        if ( ! empty( $monitor ) ) {
            $where[] = 'monitor = %s';
            $args[]  = $monitor;
        }

        if ( $not_fallback ) {
            $where[] = 'issue_code <> %s';
            $args[]  = MainWP_System_Monitor_Cron::ISSUE_MONITOR_FALLBACK;
        }

        $sql = "SELECT * FROM {$table}";

        if ( ! empty( $where ) ) {
            $sql .= ' WHERE ' . implode( ' AND ', $where );
        }

        $sql .= ' ORDER BY severity DESC, checked_at DESC';

        if ( ! empty( $args ) ) {
            $sql = static::get_db()->prepare( $sql, ...$args );
        }

        $results = static::get_db()->get_results( $sql, ARRAY_A );

        foreach ( $results as $i => $result ) {
            $result['payload'] = json_decode( $result['payload'], true );

            if ( ! is_array( $result['payload'] ) ) {
                $result['payload'] = array();
            }

            $results[ $i ] = $result;
        }

        return $results;
    }

    /**
     * Delete monitor results.
     *
     * If `$issue_code` is provided, only matching issues are deleted.
     * Otherwise, all results for the monitor are deleted.
     *
     * @param string      $monitor   Monitor name.
     * @param string|null $issue_code Optional issue code.
     *
     * @return void
     */
    public static function delete_results( $monitor, $issue_code = null ) {

        $table = static::get_table_name( 'system_monitor' );

        $where = array(
            'monitor' => $monitor,
        );

        $where_format = array(
            '%s',
        );

        if ( null !== $issue_code ) {
            $where['issue_code'] = $issue_code;
            $where_format[]      = '%s';
        }

        static::get_db()->delete( // phpcs:ignore -- NOSONAR - custom delete.
            $table,
            $where,
            $where_format
        );
    }
}
