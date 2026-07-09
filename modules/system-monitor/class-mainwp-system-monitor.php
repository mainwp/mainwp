<?php
/**
 * MainWP System Monitor.
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
 * MainWP System Monitor.
 */
class MainWP_System_Monitor {


    /**
     * DB version option.
     *
     * @var string
     */
    const OPTION_DB_VERSION = 'mainwp_system_monitor_db_version';

    /**
     * DB version handle.
     *
     * @var string
     */
    const DB_VERSION = '1.1.2';


    /**
     * Cron hook.
     *
     * @var string
     */
    const CRON_HOOK = 'mainwp_system_monitor_cron';


    /**
     * Init system.
     */
    public static function init() {
        add_action( 'init', array( self::class, 'maybe_install' ) );
        add_action( 'init', array( self::class, 'init_schedule_cron' ) );
        MainWP_System_Monitor_Runner::init();
        MainWP_System_Monitor_UI::init();

        add_action( 'mainwp_after_save_advanced_settings', array( __CLASS__, 'hook_save_advanced_settings' ) );
    }

    /**
     * DB install / upgrade hook.
     */
    public static function maybe_install() {

        $installed = get_option( self::OPTION_DB_VERSION );

        if ( self::DB_VERSION === $installed ) {
            return;
        }

        self::install_db( $installed );

        update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
    }


    /**
     * Create DB table.
     *
     * @param $installed  Installed db version.
     */
    private static function install_db( $installed ) {

        global $wpdb;

        $table = MainWP_System_Monitor_Storage::get_table_name( 'system_monitor' );

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                run_id BIGINT UNSIGNED NOT NULL,
                monitor varchar(32) NOT NULL,
                check_name varchar(64) NOT NULL,
                entity varchar(128) NOT NULL,
                issue_code varchar(50) NOT NULL DEFAULT '',
                severity varchar(20) NOT NULL DEFAULT 'info',
                payload longtext NULL,
                checked_at bigint(20) unsigned NOT NULL,
                KEY monitor_check_entity (
                    monitor,
                    check_name,
                    entity
                ),
                KEY monitor (monitor),
                KEY severity (severity),
                KEY issue_code (issue_code)";

        if ( empty( $installed ) ) {
            $sql .= ', PRIMARY KEY (id)';
        }
        $sql .= " ) {$charset_collate}; ";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( $sql );
    }


    /**
     * Ensure cron is scheduled.
     */
    public static function init_schedule_cron() {

        $useWPCron  = MainWP_Utility::get_use_cron();
        $recurrence = 'minute';
        $cron_hook  = self::CRON_HOOK;

        $enabled_job = apply_filters( 'mainwp_is_enable_schedule_job', $useWPCron, $cron_hook, $recurrence );

        $sched = wp_next_scheduled( $cron_hook );

        if ( false === $sched ) {
            if ( $useWPCron && $enabled_job ) {
                wp_schedule_event( time(), $recurrence, $cron_hook );
            }
        } elseif ( ! $useWPCron || ! $enabled_job ) {
            wp_unschedule_event( $sched, $cron_hook );
        }
    }


    /**
     * Method create manager.
     */
    public static function hook_save_advanced_settings() {
        $use_wp_cron_results = MainWP_System_Monitor_Cron::scan_use_wp_cron_issue();
        if ( ! empty( $use_wp_cron_results ) ) {
            $monitor = new MainWP_System_Monitor_Cron();
            MainWP_System_Monitor_Storage::save_results(
                $monitor->get_name(),
                $use_wp_cron_results
            );
        }
    }

    /**
     * Method create manager.
     */
    public static function create_manager() {

        $manager = new MainWP_System_Monitor_Manager();

        $manager->register(
            new MainWP_System_Monitor_Cron()
        );

        return $manager;
    }



    /**
     * Activation hook.
     */
    public static function activate() {

        self::maybe_install();
        self::init_schedule_cron();

        // Generate an initial baseline immediately.
        MainWP_System_Monitor_Runner::run_manual();
    }

    /**
     * Deactivation hook.
     */
    public static function deactivate() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }
}
