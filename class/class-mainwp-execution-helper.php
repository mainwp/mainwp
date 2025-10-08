<?php
/**
 * MainWP Counter
 *
 * @package     MainWP/Dashboard
 * @version 4.5.1
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Execution_Helper
 *
 * @package MainWP\Dashboard
 */
class MainWP_Execution_Helper { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.


    /**
     * Private variable to hold time start.
     *
     * @var int
     */
    private static $exec_start = null;

    /**
     * Private static varibale to hold the instance.
     *
     * @var mixed Default null
     */
    public static $instance = null;

    /**
     * Method instance()
     *
     * Returns new MainWP_Logger instance.
     *
     * @return self MainWP_Logger
     *
     * @uses \MainWP\Dashboard\MainWP_Logger
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * MainWP_Logger constructor.
     *
     * Run each time the class is called.
     */
    public function __construct() {
        if ( null === static::$exec_start ) {
            static::$exec_start = microtime( true );
        }
    }

    /**
     * Method init_exec_time().
     *
     * Init execution time start value.
     */
    public function init_exec_time() {
        MainWP_Logger::instance()->init_execution_time(); // compatible.
        return static::$exec_start;
    }

    /**
     * Method get_exec_time().
     *
     * @deprecated Compatible.
     *
     * Get execution time start value.
     *
     * @param bool $log_exec To log execution time or not.
     *
     * @return int $sec Execution time.
     */
    public function get_exec_time( $log_exec = true ) {
        if ( null === static::$exec_start ) {
            static::$exec_start = microtime( true );
        }

        $sec = microtime( true ) - static::$exec_start; // seconds.

        if ( $log_exec ) {
            MainWP_Logger::instance()->log_action( 'execution time :: [value=' . round( $sec, 4 ) . '](seconds)', MainWP_Logger::EXECUTION_TIME_LOG_PRIORITY );
        }

        return $sec;
    }


    /**
     * Method get_run_time().
     *
     * Get the execution time value.
     *
     * @since 5.5.
     *
     * @return float execution time.
     */
    public static function get_run_time() {
        if ( empty( static::$exec_start ) ) {
            return 0;
        }
        $rtime = microtime( true ) - static::$exec_start; // seconds.
        return round( $rtime, 4 );
    }
}
