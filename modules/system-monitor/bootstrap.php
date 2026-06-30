<?php
/**
 * System Monitor Bootstrap.
 *
 * Entry point for the System Monitor module.
 *
 * Responsibilities:
 * - Load autoloader / loader
 * - Initialize System Monitor core
 */

namespace MainWP\Dashboard\SystemMonitor;

use MainWP\Dashboard\SystemMonitor\MainWP_System_Monitor_Loader;
use MainWP\Dashboard\SystemMonitor\MainWP_System_Monitor;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


require_once __DIR__ . '/class-mainwp-system-monitor-loader.php';

/**
 * Bootstrap System Monitor module.
 *
 * @return void
 */
function bootstrap() {

    // 1. Ensure loader is available FIRST.
    MainWP_System_Monitor_Loader::init();

    // 2. Initialize the system monitor core.
    MainWP_System_Monitor::init();

    add_filter(
        'cron_schedules',
        function ( $schedules ) {

            $schedules['minute'] = array(
                'interval' => 60,
                'display'  => __( 'Every Minute', 'mainwp' ),
            );

            return $schedules;
        }
    );
}

/**
 * Hook bootstrap into WordPress.
 *
 * Using plugins_loaded ensures:
 * - WordPress is fully loaded
 * - other plugins are available
 * - safe place for module initialization
 */
add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap' );
