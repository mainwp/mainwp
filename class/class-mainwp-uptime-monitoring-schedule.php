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
        add_filter( 'mainwp_register_regular_sequence_process', array( $this, 'hook_regular_sequence_process' ) );
    }

    /**
     * hook_regular_sequence_process
     *
     * @param  array $process process.
     * @return array $process update.
     */
    public function hook_regular_sequence_process( $list ) {
        if ( is_array( $list ) ) {
            $list['uptime_notification'] = array(
                'priority' => 2,
                'callback' => array( $this, 'run_schedule_uptime_notification' ),
            );
        }
        return $list;
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
            'limit'                 => $limit,
            // 'dev_log_query'       => true,
        );

        $checkuptime_monitors = MainWP_DB_Uptime_Monitoring::instance()->get_monitors_to_check_uptime( $params ); // to sync sites data.
        $local_time           = mainwp_get_timestamp();

        // found monitors to starting check.
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
            'dts_auto_monitoring_time'       => $time + 1, // prevent equal start time.
            'dts_auto_monitoring_retry_time' => $set_retry ? $time : 0,
            'dts_interval_lasttime'          => $time,
        );
        MainWP_DB_Uptime_Monitoring::instance()->update_wp_monitor( $values );
    }

    /**
     * check_to_disable_schedule_individual_uptime_monitoring
     *
     * @return void
     */
    public function check_to_disable_schedule_individual_uptime_monitoring() {
        $count = MainWP_DB_Uptime_Monitoring::instance()->count_monitors_individual_active_enabled();
        if ( $count ) {
            MainWP_Utility::update_option( 'mainwp_individual_uptime_monitoring_schedule_enabled', 1 );
        } else {
            delete_option( 'mainwp_individual_uptime_monitoring_schedule_enabled' );
        }
    }


    /**
     * Run schedule uptime notification.
     *
     * @return void
     */
    public function run_schedule_uptime_notification() { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- NOSONAR Current complexity is the only way to achieve desired results, pull request solutions appreciated.

        $local_time = mainwp_get_timestamp();

        $lasttime_send = get_option( 'mainwp_uptime_monitoring_notification_last_time', $local_time );

        $process_run_status = get_option( 'mainwp_process_uptime_notification_run_status' );

        // if notification process is not 'running'.
        if ( empty( $process_run_status ) || in_array( $process_run_status, array( 'finished', 'init' ) ) ) {

            $process_init = MainWP_DB_Uptime_Monitoring::instance()->get_uptime_notification_to_start_send( $lasttime_send, 50 );

            if ( is_array( $process_init ) && ! empty( $process_init ) ) {

                if ( 'init' !== $process_run_status ) {
                    $this->set_uptime_notification_status( 'init' );
                    MainWP_Utility::update_option( 'mainwp_uptime_monitoring_notification_last_time', $local_time );
                }

                foreach ( $process_init as $uptime_notice ) {

                    if ( ! empty( $uptime_notice->process_id ) ) {
                        MainWP_DB::instance()->update_process(
                            array(
                                'process_id'        => $uptime_notice->process_id,
                                'dts_process_start' => $local_time,
                            )
                        );
                    } else {
                        // insert process.
                        MainWP_DB::instance()->update_process(
                            array(
                                'item_id'           => $uptime_notice->monitor_id,
                                'type'              => 'uptime_notification',
                                'status'            => 'active',
                                'dts_process_start' => $local_time,
                            )
                        );

                    }
                }
            } elseif ( 'init' === $process_run_status ) {
                $this->set_uptime_notification_status( 'running' );
            }
            return;
        }

        if( 'running' === $process_run_status ){
            $process_notices = MainWP_DB_Uptime_Monitoring::instance()->get_uptime_notification_to_continue_send( array( 'limit' => 5 ) );
            if ( is_array( $process_notices ) && ! empty( $process_notices ) ) {
                MainWP_System_Cron_Jobs::instance()->send_uptime_notification_down_status( $process_notices );
            } else {
                $this->set_uptime_notification_status( 'finished' );
            }
        }
    }



    /**
     * Method notification uptime status.
     *
     * Prepare uptime status notification.
     *
     * @param array $process_notices sites.
     *
     * @return bool True|False
     *
     * @uses \MainWP\Dashboard\MainWP_Logger::info()
     * @uses \MainWP\Dashboard\MainWP_Monitoring_Handler::notice_sites_uptime_monitoring()
     * @uses \MainWP\Dashboard\MainWP_Notification_Settings::get_general_email_settings()
     * @uses \MainWP\Dashboard\MainWP_Notification_Settings::get_site_email_settings()
     * @uses \MainWP\Dashboard\MainWP_Notification_Settings::get_default_emails_fields()
     */
    public function send_uptime_notification_down_status( $process_notices ) {

        $plain_text = get_option( 'mainwp_daily_digest_plain_text', false );

        $local_time = mainwp_get_timestamp();

        $admin_email = MainWP_Notification_Settings::get_general_email();

        // general uptime notification, to administrator.
        $email_settings = MainWP_Notification_Settings::get_general_email_settings( 'uptime' );
        if ( ! $email_settings['disable'] ) {
            MainWP_Monitoring_Handler::notice_sites_uptime_monitoring( $process_notices, $admin_email, $email_settings, $plain_text );
        }

        $individual_admin_uptimeSites = array();
        // individual uptime notification.
        foreach ( $process_notices as $uptime_notice ) {
            $email_settings = MainWP_Notification_Settings::get_site_email_settings( 'uptime', $uptime_notice );

            if ( ! empty( $uptime_notice->process_id ) ) {
                MainWP_DB::instance()->update_process(
                    array(
                        'process_id'        => $uptime_notice->process_id,
                        'dts_process_stop' => $local_time,
                    )
                );
            }

            if ( $email_settings['disable'] ) {
                continue; // disabled send notification for this site.
            }
            $individual_admin_uptimeSites[] = $uptime_notice;
            MainWP_Monitoring_Handler::notice_sites_uptime_monitoring( array( $uptime_notice ), $admin_email, $email_settings, $plain_text );
        }

        if ( ! empty( $individual_admin_uptimeSites ) ) {
            $admin_email_settings               = MainWP_Notification_Settings::get_default_emails_fields( 'uptime', '', true ); // get default subject and heading only.
            $admin_email_settings['disable']    = 0;
            $admin_email_settings['recipients'] = ''; // sent to admin only.
            // send to admin, all individual sites in one email.
            MainWP_Monitoring_Handler::notice_sites_uptime_monitoring( $individual_admin_uptimeSites, $admin_email, $admin_email_settings, $plain_text, true );
        }

        return true;
    }

    /**
     * set_uptime_notification_status
     *
     * @param  string $new_status
     * @return void
     */
    public function set_uptime_notification_status( $new_status ) {
        MainWP_Utility::update_option( 'mainwp_process_uptime_notification_run_status', $new_status );
    }
}
