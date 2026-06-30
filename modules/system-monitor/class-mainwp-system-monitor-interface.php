<?php
/**
 * MainWP System Monitor Interface.
 *
 *  @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard\SystemMonitor;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * MainWP System Monitor Interface.
 */
interface MainWP_System_Monitor_Interface {

    /**
     * Get monitor name.
     *
     * @return string
     */
    public function get_name();

    /**
     * Execute the monitor.
     *
     * @return MainWP_System_Monitor_Result[]
     */
    public function run();
}
