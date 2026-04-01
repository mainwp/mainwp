<?php
/**
 * MainWP Counter
 *
 * @package     MainWP/Dashboard
 * @version 4.5.1
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

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
     * Static varibale to hold the instance.
     *
     * @var mixed Default null
     */
    public static $instance = null;

    /**
     * Static varibale to hold the remote call total time.
     *
     * @var mixed Default 0
     */

    public static $exec_stats = array();

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
        MainWP_Logger::instance()->init_execution_time();
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
     * @param  bool $ret_microseconds
     *
     * @since 5.5.
     *
     * @updated 6.0
     *
     * @return int|float execution time.
     */
    public static function get_run_time( $ret_microseconds = false ) {
        if ( empty( static::$exec_start ) ) {
            return 0;
        }
        $rtime = microtime( true ) - static::$exec_start; // seconds.
        if ( $ret_microseconds ) {
            return round( $rtime * 1000000, 4 );
        }
        return round( $rtime, 4 );
    }


    /**
     * Method init_http_call_track.
     *
     * @return void
     */
    public static function init_http_call_track() {
        add_action( 'http_api_debug', array( __CLASS__, 'http_call_track' ), 10, 5 );
    }

    /**
     * Track HTTP API request/response timing.
     *
     * Hooked into `http_api_debug` to measure remote call duration.
     *
     * @param mixed  $response HTTP response or WP_Error.
     * @param string $context  Execution context: 'request', 'response', 'transport', etc.
     * @param string $class    HTTP transport class name.
     * @param array  $args     Request arguments passed to the HTTP API.
     * @param string $url      Request URL.
     *
     * @return void
     */
    public static function http_call_track( $response, $context, $class, $args, $url ) { //phpcs:ignore -- NOSONAR - not use some params.
        $check_context = '';
        if ( 'request' === $context ) {
            $check_context = 'start_point';
        } elseif ( 'response' === $context ) {
            $check_context = 'end_point';
        }
        static::run_call_track( $check_context, $url, $args );
    }


    /**
     * Method execute_call_track.
     *
     * @param  mixed  $context Curl request context.
     * @param  mixed  $website Curl website.
     * @param  array  $args Request args.
     * @param  string $request_id Request id.
     * @param  string $desc Exec msg.
     *
     * @return mixed Track id for 'request' or null.
     */
    public static function execute_call_track( $context, $website = false, $args = array(), $request_id = '', $desc = '' ) {
        $url = '_na_url';
        if ( is_string( $website ) ) {
            $url = $website;
        } elseif ( is_array( $website ) && isset( $website['url'] ) ) {
            $url = $website['url'];
        } elseif ( is_object( $website ) && property_exists( $website, 'url' ) ) {
            $url = $website->url;
        }
        return static::run_call_track( $context, $url, $args, $request_id, $desc );
    }

    /**
     * Track running timing.
     *
     * @param string $check_context  Execution check context.
     * @param string $url      Request URL.
     * @param  array  $args Request args.
     * @param  string $request_id Request ID.
     * @param  string $desc Exec desc.
     *
     * @return mixed void|string
     */
    private static function run_call_track( $check_context, $url, $args, $request_id = '', $desc = '' ) {
        static $start = array();

        if ( ! isset( static::$exec_stats['exec_time'] ) ) {
            static::$exec_stats['exec_time'] = array();
        }

        if ( ! isset( static::$exec_stats['check_count'] ) ) {
            static::$exec_stats['check_count'] = 0;
        }

        if ( ! isset( static::$exec_stats['check_data'] ) ) {
            static::$exec_stats['check_data'] = array();
        }

        // include microtime to avoid overwrite.
        if ( 'start_point' === $check_context ) {
            $key = md5( $url . serialize( $args ) . microtime( true ) ); //phpcs:ignore -- NOSONAR - good for key.
            $start[ $key ] = microtime( true );
            // store request_id (required!).
            return $key;
        }

        if ( empty( $request_id ) ) {
            $request_id = $url;
        }

        if ( 'end_point' === $check_context && ! empty( $request_id ) ) {
            $key = (string) $request_id;

            if ( isset( $start[ $key ] ) ) {
                $time = microtime( true ) - $start[ $key ];
                ++static::$exec_stats['check_count'];
                static::$exec_stats['exec_time'][]  = $time;
                static::$exec_stats['check_desc'][] = $desc;

                $data = array(
                    'url' => $url,
                );
                if ( ! empty( $args ) ) {
                    $data['args'] = $args;
                }

                static::$exec_stats['check_data'][] = $data;
                unset( $start[ $key ] );
            }
        }
    }

    /**
     * Method get_exec_call_stats.
     *
     * @return array Http stats.
     */
    public static function get_exec_call_stats() {
        return self::$exec_stats;
    }

    /**
     * Method get db stats.
     *
     * @return array Array db queries usage stats.
     */
    public static function get_queries_stats() {
        global $wpdb;
        if ( empty( $wpdb->queries ) ) {
            return array();
        }
        $queries = $wpdb->queries;
        return array(
            'total_queries' => is_array( $queries ) ? count( $queries ) : 0,
            'total_runtime' => is_array( $queries ) ? array_sum( array_column( $queries, 1 ) ) : 0,
        );
    }
}
