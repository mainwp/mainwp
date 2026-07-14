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
     * @param array  $results Array of MainWP_System_Monitor_Result.
     *
     * @return int $count Count saved.
     */
    public static function save_results( $monitor, array $results ) {

        // Delete issues from the previous scan.
        self::delete_results( $monitor );

        if ( empty( $results ) ) {
            MainWP_Utility::dismiss_short_term_monitor_notices( $monitor );
            return 0;
        }

        $count = 0;

        foreach ( $results as $result ) {
            if ( ! $result instanceof MainWP_System_Monitor_Result ) {
                continue;
            }
            $insert_id = static::save_result( $result );
            if ( $insert_id ) {
                ++$count;
            }
        }

        MainWP_Utility::dismiss_short_term_monitor_notices( $monitor );
        return $count;
    }

    /**
     * Save a monitor result.
     *
     * @param MainWP_System_Monitor_Result $result Monitor result.
     *
     * @return int Insert ID, or 0 on failure.
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
            $key = static::get_notice_key(
                $result->get_issue_code(),
                $result->get_entity()
            );
            MainWP_Utility::set_short_term_notice( $monitor, $key, $checked_at );
        }
        return $insert_id;
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
     * Get monitor issues.
     *
     * @param string|null $monitor Optional monitor name.
     * @param bool        $not_fallback Whether included fallback monitor issues.
     *
     * @return array
     */
    public static function get_issues( $monitor = null, $not_fallback = true ) {

        $table = static::get_table_name( 'system_monitor' );

        $sql = "SELECT * FROM {$table}";

        if ( ! empty( $monitor ) ) {
            $sql .= static::get_db()->prepare(
                ' WHERE monitor = %s',
                $monitor
            );
        }

        if ( $not_fallback ) {
            $sql .= static::get_db()->prepare(
                ' AND issue_code <> %s ',
                MainWP_System_Monitor_Cron::ISSUE_MONITOR_FALLBACK
            );
        }

        $sql .= ' ORDER BY severity DESC, checked_at DESC';

        $results = static::get_db()->get_results( $sql, ARRAY_A );

        foreach ( $results as &$result ) {
            $result['payload'] = json_decode(
                $result['payload'],
                true
            );

            if ( ! is_array( $result['payload'] ) ) {
                $result['payload'] = array();
            }
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
