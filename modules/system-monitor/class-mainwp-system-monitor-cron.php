<?php
/**
 * MainWP System Monitor Cron.
 *
 *  @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard\SystemMonitor;

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

    const ISSUE_MONITOR_STALE    = 'monitor_stale';
    const ISSUE_MONITOR_FALLBACK = 'monitor_fallback_used';

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
     * Execute the monitor.
     *
     * @param array $context Running context data.
     *
     * @return MainWP_System_Monitor_Result[]
     */
    public function run( array $context = array() ) {

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
                self::get_name(),
                $issue['check_name'] ?? 'heartbeat',
                $issue['entity'] ?? 'wp_cron',
                $issue['code'],
                $issue['data'] ?? array(),
                $issue['severity'] ?? null
            );
        }

        return $results;
    }
}
