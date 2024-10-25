<?php
/**
 * MainWP monitor site.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Uptime_Monitoring_Schedule
 *
 * @package MainWP\Dashboard
 */
class MainWP_Uptime_Monitoring_Schedule { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    const UP   = 1;
    const DOWN = 2;

    /**
     * The single instance of the class
     *
     * @var mixed Default null
     */
    protected static $instance = null;


    /**
     * Get instance.
     *
     *  @return mixed
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * MainWP_Setup_Wizard constructor.
     *
     * Run each time the class is called.
     */
    public function __construct() {
        add_action( 'admin_init', array( $this, 'admin_init' ) );
    }

    /**
     * Admin init.
     */
    public function admin_init() {
    }

    public function cron_uptime_check() { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- NOSONAR Current complexity is the only way to achieve desired results, pull request solutions appreciated.

        $disable_uptime_check = apply_filters( 'mainwp_automatic_disable_uptime_monitoring_check', false );

        if ( $disable_uptime_check ) {
            return;
        }

        MainWP_System_Cron_Jobs::instance()->init_environment();

        $lasttimeAutomaticMainCounterLastTime = get_option( 'mainwp_uptimecheck_auto_main_counter_lasttime_started' );

        $uptimecheck_running = get_option( 'mainwp_uptimecheck_running' );

        $global_settings = get_option( 'mainwp_global_uptime_monitoring_settings', array() );

        if ( empty( $global_settings ) || ! is_array( $global_settings ) ) {
            $global_settings = MainWP_Uptime_Monitoring_Handle::get_default_monitoring_settings( false );
        }

        $limit = (int) get_option( 'mainwp_maximum_uptime_monitoring_requests', 10 );

        $params = array(
            'main_counter_lasttime' => $lasttimeAutomaticMainCounterLastTime,
            'global_settings'       => $global_settings,
            'limit' => $limit,
            //'dev_log_query'       => true,
        );


        $checkuptime_monitors = MainWP_DB_Uptime_Monitoring::instance()->get_monitors_to_check_uptime( $params ); // to sync sites data.
        $local_time           = mainwp_get_timestamp();
        if ( ! $uptimecheck_running && count( $checkuptime_monitors ) > 0 ) {
            MainWP_Logger::instance()->log_uptime_check( 'Uptime Monitoring started :: [local_timestamp=' . gmdate( 'Y-m-d H:i:s', $local_time ) . '] :: [count=' . ( $checkuptime_monitors ? count( $checkuptime_monitors ) : 0 ) . ']' );
            MainWP_Utility::update_option( 'mainwp_uptimecheck_running', 1 );
            MainWP_Utility::update_option( 'mainwp_uptimecheck_auto_main_counter_lasttime_started', $local_time );
            $uptimecheck_running = 1;
        }

        MainWP_Logger::instance()->log_uptime_check( 'Uptime Monitoring :: [count_monitors=' . ( $checkuptime_monitors ? count( $checkuptime_monitors ) : 0 ) . ']' );

        foreach ( $checkuptime_monitors as $monitor ) {
            $update = array(
                'monitor_id'                => $monitor->monitor_id,
                'dts_auto_monitoring_start' => mainwp_get_timestamp(),
            );
            MainWP_DB_Uptime_Monitoring::instance()->update_wp_monitor( $update );
        }

        $busy_counter = MainWP_DB_Uptime_Monitoring::instance()->count_busy_main_round_check( $global_settings, $lasttimeAutomaticMainCounterLastTime );

        if ( $uptimecheck_running && empty( $checkuptime_monitors ) && 0 === (int) $busy_counter ) {
            MainWP_Logger::instance()->log_uptime_check( 'Uptime Monitoring has finished.' );
            MainWP_Utility::update_option( 'mainwp_uptimecheck_last_timestamp_finished', mainwp_get_timestamp() );
            MainWP_Utility::update_option( 'mainwp_uptimecheck_running', 0 );
            $uptimecheck_running = 0;
        }

        if ( ! $uptimecheck_running && empty( $checkuptime_monitors ) ) {
            MainWP_Logger::instance()->log_update_check( 'Uptime check waitting interval to run :: [local_time=' . gmdate( 'Y-m-d H:i:s', $local_time ) . ']' );
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            add_filter( 'mainwp_fetch_uptime_disable_check_multi_exec', '__return_false' );
        }
        // Check the uptime for the detected monitors.
        if ( count( $checkuptime_monitors ) ) {
            MainWP_Uptime_Monitoring_Connect::instance()->check_monitors( $checkuptime_monitors, $global_settings );
        }
    }

    /**
     * update_monitoring_time
     *
     * @param  object $monitor monitor.
     * @param  bool   $set_retry retry time if > 0.
     *
     * @return void
     */
    public function update_monitoring_time( $monitor, $set_retry = false ) {
        $time   = mainwp_get_timestamp();
        $values = array(
            'monitor_id'                     => $monitor->monitor_id,
            'retries'                        => $set_retry ? $monitor->retries++ : 0,
            'dts_auto_monitoring_time'       => $time,
            'dts_auto_monitoring_retry_time' => $set_retry ? $time : 0,
            'dts_interval_lasttime'          => $time,
        );
        MainWP_DB_Uptime_Monitoring::instance()->update_wp_monitor( $values );
    }

    /**
     * check_to_disable_schedule_individual_uptime_monitoring
     *
     * @param  int|null $enabled_use_wp_cron
     * @return void
     */
    public function check_to_disable_schedule_individual_uptime_monitoring( $enabled_use_wp_cron = null ) {

        if ( null === $enabled_use_wp_cron ) {
            $enabled_use_wp_cron = get_option( 'mainwp_wp_cron' );
        }

        if ( $enabled_use_wp_cron ) {
            return;
        }

        $count = MainWP_DB_Uptime_Monitoring::instance()->count_individual_enabled_monitoring();
        if ( 0 === (int) $count ) {
            MainWP_Utility::update_option( 'mainwp_disable_schedule_individual_uptime_monitoring', 1 );
        } else {
            delete_option( 'mainwp_disable_schedule_individual_uptime_monitoring' );
        }
    }
}
