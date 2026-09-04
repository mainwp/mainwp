<?php
/**
 * MainWP System Monitor Cron Scanner.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard\SystemMonitor;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * MainWP System Monitor Cron Scanner.
 *
 * Collects raw system information without determining whether it is healthy.
 *
 * Returns raw data for validators.
 */
class MainWP_System_Monitor_Cron_Scanner {

    /**
     * Scan WP-Cron.
     *
     * @return array
     */
    public function scan() {

        return array(
            'current_time'     => time(),
            'last_cron_run'    => MainWP_System_Monitor_Runner::get_last_cron_run(),
            'next_run'         => wp_next_scheduled(
                MainWP_System_Monitor::CRON_HOOK
            ),
            'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
        );
    }
}
