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

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


require_once __DIR__ . '/class-mainwp-system-monitor-loader.php';

// Register the autoloader immediately so activation hooks and other early
// entry points can load System Monitor classes.
MainWP_System_Monitor_Loader::init();


/**
 * Bootstrap System Monitor module.
 *
 * @return void
 */
function bootstrap() {

    // Initialize the system monitor core.
    MainWP_System_Monitor::init();

    add_filter(
        'cron_schedules',
        function ( $schedules ) {
            if ( ! isset( $schedules['minute'] ) ) {
                $schedules['minute'] = array(
                    'interval' => MINUTE_IN_SECONDS,
                    'display'  => __( 'Every Minute', 'mainwp' ),
                );
            }
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
