<?php
/**
 * MainWP System Monitor Storage.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard\SystemMonitor;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


class MainWP_System_Monitor_Storage {

    /**
     * Save monitor results (replace existing).
     *
     * @param string $monitor Monitor name.
     * @param array  $results Array of MainWP_System_Monitor_Result.
     *
     * @return void
     */
    public static function save_results( $monitor, array $results ) {

        global $wpdb;

        self::clear_results( $monitor );

        $table = MainWP_System_Monitor::get_table_name( 'system_monitor' );

        // Remove previous issues for this monitor.
        $wpdb->delete(
            $table,
            array(
                'monitor' => $monitor,
            ),
            array(
                '%s',
            )
        );

        $run_id     = time(); // simple v1 run grouping.
        $checked_at = time();

        foreach ( $results as $result ) {

            if ( ! $result instanceof MainWP_System_Monitor_Result ) {
                continue;
            }

            $wpdb->insert(
                $table,
                array(
                    'run_id'     => $run_id,
                    'monitor'    => $monitor,
                    'check_name' => $result->get_check_name(),
                    'entity'     => $result->get_entity(),
                    'issue_code' => $result->get_issue_code(),
                    'severity'   => $result->get_severity(),
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
        }
    }

    /**
     * Get monitor issues.
     *
     * @param string|null $monitor Optional monitor name.
     *
     * @return array
     */
    public static function get_issues( $monitor = null ) {

        global $wpdb;

        $table = MainWP_System_Monitor::get_table_name( 'system_monitor' );

        $sql = "SELECT * FROM {$table}";

        if ( ! empty( $monitor ) ) {
            $sql .= $wpdb->prepare(
                ' WHERE monitor = %s',
                $monitor
            );
        }

        $sql .= ' ORDER BY severity DESC, checked_at DESC';

        $results = $wpdb->get_results( $sql, ARRAY_A );

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
     * Delete all results for a monitor.
     *
     * @param string $monitor Monitor name.
     *
     * @return void
     */
    public static function clear_results( $monitor ) {

        global $wpdb;

        $table = MainWP_System_Monitor::get_table_name( 'system_monitor' );

        $wpdb->delete(
            $table,
            array(
                'monitor' => $monitor,
            ),
            array(
                '%s',
            )
        );
    }
}
