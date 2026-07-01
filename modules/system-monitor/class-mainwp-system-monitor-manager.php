<?php
/**
 * MainWP System Monitor Manager.
 *
 * @package     MainWP/Dashboard
 *
 * Orchestrates all system monitors.
 *
 * Responsibilities:
 *
 * - Execute scanners.
 * - Execute validators.
 * - Build monitor results.
 * - Persist results.
 */

namespace MainWP\Dashboard\SystemMonitor;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Runs all registered monitors.
 */

class MainWP_System_Monitor_Manager {

    /**
     * Registered monitors.
     *
     * @var MainWP_System_Monitor_Interface[]
     */
    private $monitors = array();

    /**
     * Run all monitors.
     *
     * @param array $context Running context data.
     *
     * @return void
     */
    public static function run( array $context = array() ) {

        $manager = new self();

        $manager->register( new MainWP_System_Monitor_Cron() );

        $manager->execute( $context );
    }

    /**
     * Register a monitor.
     *
     * @param MainWP_System_Monitor_Interface $monitor Monitor.
     *
     * @return void
     */
    public function register( MainWP_System_Monitor_Interface $monitor ) {

        $this->monitors[] = $monitor;
    }

    /**
     * Execute all registered monitors.
     *
     * @param array $context Running context data.
     *
     * @return void
     */
    private function execute( array $context = array() ) {
        foreach ( $this->monitors as $monitor ) {
            MainWP_System_Monitor_Storage::save_results(
                $monitor->get_name(),
                $monitor->run( $context )
            );
        }
    }
}
