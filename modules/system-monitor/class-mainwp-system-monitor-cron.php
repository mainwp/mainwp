<?php
/**
 * MainWP System Monitor Cron.
 *
 *  @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard\SystemMonitor;

use MainWP\Dashboard\MainWP_Utility;


if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * MainWP System Monitor - Cron Monitor.
 *
 * Responsibilities:
 *
 * - Execute the Cron scanner.
 * - Execute the Cron validator.
 * - Convert issues into monitor results.
 *
 * Does NOT:
 *
 * - Store results.
 * - Generate UI.
 * - Access the database.
 */
class MainWP_System_Monitor_Cron implements MainWP_System_Monitor_Interface {

    const ISSUE_MONITOR_STALE        = 'monitor_stale';
    const ISSUE_MONITOR_FALLBACK     = 'monitor_fallback_used';
    const ISSUE_USE_WP_CRON_DISABLED = 'use_wp_cron_disabled'; // MainWP setting.

    /**
     * Constant to support displaying child monitor issues.
     */
    const ISSUE_CHILD_MONITOR_STALE    = 'child_monitor_stale';
    const ISSUE_CHILD_MONITOR_FALLBACK = 'child_monitor_fallback_used';

    /**
     * Monitor name.
     */
    const NAME = 'cron';


    /**
     * Get monitor name.
     *
     * @return string
     */
    public function get_name() {
        return self::NAME;
    }

    /**
     * Get whether cron is enabled.
     */
    public static function is_enabled() {
        return MainWP_Utility::get_use_cron();
    }

    /**
     * Method scan_use_wp_cron_issue().
     *
     * @return MainWP_System_Monitor_Result[]
     */
    public static function scan_use_wp_cron_issue() {
        $use_wpcron = MainWP_Utility::get_use_cron();
        $results    = array();
        $saved      = get_option( 'mainwp_system_monitor_use_wp_cron_saved' );
        if ( $use_wpcron !== (int) $saved || false === $saved ) {
            // if get_use_cron changes state.
            if ( ! $use_wpcron ) {
                $results[] = new MainWP_System_Monitor_Result(
                    self::NAME, // monitor.
                    'use_wp_cron', // check_name.
                    'mainwp_wp_cron', // entity.
                    self::ISSUE_USE_WP_CRON_DISABLED, // issue code.
                    array(
                        'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
                    ),
                    MainWP_System_Monitor_Issues::SEVERITY_INFO
                );

            } else {
                MainWP_System_Monitor_Storage::delete_results( self::NAME, self::ISSUE_USE_WP_CRON_DISABLED ); // So the issue notice won't be displayed.
                MainWP_Utility::dismiss_short_term_monitor_notices( self::NAME, self::ISSUE_USE_WP_CRON_DISABLED );
            }
            MainWP_Utility::update_option( 'mainwp_system_monitor_use_wp_cron_saved', $use_wpcron ); // prevemt insert multi use_wp_cron scan issue.
        }

        return $results;
    }


    /**
     * Execute the monitor.
     *
     * @param array $context Running context data.
     *
     * @return MainWP_System_Monitor_Result[]
     */
    public function run( array $context = array() ) {

        $enabled = self::is_enabled();

        $use_wp_cron_issue = static::scan_use_wp_cron_issue();

        if ( ! $enabled ) {
            return $use_wp_cron_issue; // do not scan other issues.
        }

        $scanner = new MainWP_System_Monitor_Cron_Scanner();

        $scan = $scanner->scan();

        $validator = new MainWP_System_Monitor_Cron_Validator();

        $issues = $validator->validate( $scan, $context );

        return $this->build_results( $issues );
    }

    /**
     * Build monitor results.
     *
     * @param array $issues Validator issues.
     *
     * @return MainWP_System_Monitor_Result[]
     */
    private function build_results( array $issues ) {

        $results = array();

        foreach ( $issues as $issue ) {

            $results[] = new MainWP_System_Monitor_Result(
                self::NAME,
                $issue['check_name'] ?? 'heartbeat',
                $issue['entity'] ?? 'wp_cron',
                $issue['code'],
                $issue['data'] ?? array(),
                $issue['severity'] ?? null
            );
        }

        return $results;
    }

    /**
     * Map a monitor issue code to the corresponding child monitor issue code.
     *
     * Used to display the appropriate issue message for a child site monitor issue.
     *
     * @param string $code Monitor issue code.
     *
     * @return string Corresponding child monitor issue code, or an empty string if no mapping exists.
     */
    public static function map_child_monitor_issue_code( $code ) {

        $mapping = array(
            static::ISSUE_MONITOR_STALE    => static::ISSUE_CHILD_MONITOR_STALE,
            static::ISSUE_MONITOR_FALLBACK => static::ISSUE_CHILD_MONITOR_FALLBACK,
        );

        return isset( $mapping[ $code ] ) ? $mapping[ $code ] : '';
    }
}
