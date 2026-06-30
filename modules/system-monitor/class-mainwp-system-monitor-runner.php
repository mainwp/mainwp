<?php
/**
 * MainWP_System_Monitor_Runner.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard\SystemMonitor;

/**
 * Class MainWP_System_Monitor_Runner
 *
 * Executes the System Monitor via WP-Cron or a page-load fallback.
 */
class MainWP_System_Monitor_Runner {

    /**
     * Cron hook.
     *
     * @var string
     */
    const CRON_HOOK = 'mainwp_system_monitor_cron';

    /**
     * Lock transient.
     *
     * @var string
     */
    const LOCK_KEY = 'mainwp_system_monitor_running';

    /**
     * Last monitor run option.
     *
     * @var string
     */
    const OPTION_LAST_RUN = 'mainwp_system_monitor_last_run';

    /**
     * Last cron run option.
     *
     * @var string
     */
    const OPTION_LAST_CRON_RUN = 'mainwp_system_monitor_last_cron_run';

    /**
     * Monitor interval.
     *
     * @var int
     */
    const INTERVAL = MINUTE_IN_SECONDS;

    /**
     * Initialize.
     *
     * @return void
     */
    public static function init() {

        add_action(
            self::CRON_HOOK,
            array( __CLASS__, 'run' )
        );

        // Page-load fallback.
        add_action(
            'admin_init',
            array( __CLASS__, 'maybe_run' )
        );
    }


    /**
     * Execute monitor.
     *
     * @param bool $fallback Fallback run.
     *
     * @return void
     */
    public static function run( $fallback = false ) {

        if ( self::is_locked() ) {
            return;
        }

        self::lock();

        try {

            $manager = MainWP_System_Monitor::create_manager();

            $manager->run(
                array(
                    'fallback' => $fallback ? true : false,
                )
            );

            self::update_last_run();

            if ( ! $fallback ) {
                self::update_last_cron_run();
            }
        } catch ( Exception $e ) {
            // Optional.
        } finally {
            self::unlock();
        }
    }


    /**
     * Run monitor if overdue.
     *
     * This is executed on page load.
     *
     * @return void
     */
    public static function maybe_run() {

        // Another request is already running.
        if ( self::is_locked() ) {
            return;
        }

        $last_run = self::get_last_run();

        if ( 0 !== $last_run &&
            ( time() - $last_run ) < ( self::INTERVAL * 2 ) ) {
            return;
        }

        self::run( true );
    }

    /**
     * Get last successful run.
     *
     * @return int
     */
    public static function get_last_run() {

        return (int) get_option(
            self::OPTION_LAST_RUN,
            0
        );
    }

    /**
     * Get last cron successful run.
     *
     * @return int
     */
    public static function get_last_cron_run() {
        return (int) get_option(
            self::OPTION_LAST_CRON_RUN,
            0
        );
    }

    /**
     * Update last run.
     *
     * @return void
     */
    public static function update_last_run() {
        update_option(
            self::OPTION_LAST_RUN,
            time(),
            false
        );
    }

    /**
     * Update last cron run.
     *
     * @return void
     */
    public static function update_last_cron_run() {
        update_option(
            self::OPTION_LAST_CRON_RUN,
            time(),
            false
        );
    }

    /**
     * Lock monitor.
     *
     * @return void
     */
    private static function lock() {

        set_transient(
            self::LOCK_KEY,
            1,
            5 * MINUTE_IN_SECONDS
        );
    }

    /**
     * Unlock monitor.
     *
     * @return void
     */
    private static function unlock() {

        delete_transient(
            self::LOCK_KEY
        );
    }

    /**
     * Check if monitor is locked.
     *
     * @return bool
     */
    private static function is_locked() {

        return (bool) get_transient(
            self::LOCK_KEY
        );
    }
}
