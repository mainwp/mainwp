<?php
/**
 * MainWP monitor site.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

use stdClass;

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
        add_filter( 'mainwp_register_regular_sequence_process', array( $this, 'hook_regular_sequence_process' ), 10, 1 );
    }

    /**
     * Method hook_regular_sequence_process
     *
     * @param  array $list_values process list.
     */
    public function hook_regular_sequence_process( $list_values ) {
        if ( is_array( $list_values ) ) {
            $list_values['uptime_notification'] = array(
                'priority' => 2,
                'callback' => array( __CLASS__, 'run_schedule_uptime_notification' ), // must be array( class_name, method).
            );
        }
        return $list_values;
    }


    /**
     * Method cron_uptime_check
     *
     * @return void
     */
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

        if ( $uptimecheck_running && empty( $checkuptime_monitors ) ) {
            MainWP_Logger::instance()->log_uptime_check( 'Uptime Monitoring has finished.' );
            MainWP_Utility::update_option( 'mainwp_uptimecheck_last_timestamp_finished', mainwp_get_timestamp() );
            MainWP_Utility::update_option( 'mainwp_uptimecheck_running', 0 );
            $uptimecheck_running = 0;
        }

        if ( ! $uptimecheck_running && empty( $checkuptime_monitors ) ) {
            MainWP_Logger::instance()->log_uptime_check( 'Uptime check waitting interval to run :: [local_time=' . gmdate( 'Y-m-d H:i:s', $local_time ) . ']' );
        }

        // Check the uptime for the detected monitors.
        if ( count( $checkuptime_monitors ) ) {
            MainWP_Uptime_Monitoring_Connect::instance()->check_monitors( $checkuptime_monitors, $global_settings );
        }
    }

    /**
     * Method update_monitoring_time
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
     * Method check_to_disable_schedule_individual_uptime_monitoring
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

        $process_run_status = get_option( 'mainwp_process_uptime_notification_run_status' );

        // if notification process is not 'running'.
        if ( empty( $process_run_status ) || in_array( $process_run_status, array( 'finished', 'init' ) ) ) {

            $local_time = mainwp_get_timestamp();

            $process_init = MainWP_DB_Uptime_Monitoring::instance()->get_uptime_notification_to_start_send( 50 );

            if ( is_array( $process_init ) && ! empty( $process_init ) ) {

                if ( 'init' !== $process_run_status ) {
                    $this->update_uptime_notification_status( 'init' );
                    MainWP_Utility::update_option( 'mainwp_uptime_monitoring_notification_last_time', $local_time );
                    MainWP_Logger::instance()->log_uptime_notice( 'Uptime notice starting.' );
                }

                foreach ( $process_init as $uptime_notice ) {
                    if ( ! empty( $uptime_notice->process_id ) ) {
                        MainWP_DB::instance()->update_regular_process(
                            array(
                                'process_id'        => $uptime_notice->process_id,
                                'dts_process_start' => $local_time, // set start time to current time, to continue processs.
                            )
                        );
                    }
                }
            } elseif ( 'init' === $process_run_status ) {
                $this->update_uptime_notification_status( 'running' );
            }
            return;
        }

        if ( 'running' === $process_run_status ) {
            $limit_send      = apply_filters( 'mainwp_uptime_monitoring_send_notification_limit', 3 );
            $process_notices = MainWP_DB_Uptime_Monitoring::instance()->get_uptime_notification_to_continue_send( array( 'limit' => $limit_send ) );
            if ( is_array( $process_notices ) && ! empty( $process_notices ) ) {
                MainWP_Logger::instance()->log_uptime_notice( 'Uptime notice continue :: [count=' . ( $process_notices ? count( $process_notices ) : 0 ) . '].' );
                $this->send_uptime_notification_importance_status( $process_notices );
            } else {
                MainWP_Logger::instance()->log_uptime_notice( 'Uptime notice completed.' );
                $this->update_uptime_notification_status( 'finished' );
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
    public function send_uptime_notification_importance_status( $process_notices ) {

        $plain_text = get_option( 'mainwp_daily_digest_plain_text', false );

        $local_time = mainwp_get_timestamp();

        $admin_email = MainWP_Notification_Settings::get_general_email();

        // general uptime notification, to administrator.
        $email_settings = MainWP_Notification_Settings::get_general_email_settings( 'uptime' );

        $debug_settings = array(
            'admin_email'            => $admin_email,
            'general_email_settings' => $admin_email,
            'site_email_settings'    => array(),
        );

        if ( ! $email_settings['disable'] ) {
            MainWP_Logger::instance()->log_uptime_notice( 'General uptime notifications are now being sent to the admin.' );
            static::send_uptime_notification_heartbeats_importance_status( $process_notices, $admin_email, $email_settings, $plain_text );
        }

        $individual_admin_uptimeSites = array();
        // individual uptime notification.
        foreach ( $process_notices as $uptime_notice ) {
            $email_settings = MainWP_Notification_Settings::get_site_email_settings( 'uptime', $uptime_notice );
            MainWP_Logger::instance()->log_uptime_notice( 'Uptime site email settings:' . print_r( $email_settings, true ) ); //phpcs:ignore -- NOSONAR -ok.
            $debug_settings['site_email_settings'][ $uptime_notice->id ] = $email_settings;

            if ( ! empty( $uptime_notice->process_id ) ) {
                MainWP_DB::instance()->update_regular_process(
                    array(
                        'process_id'            => $uptime_notice->process_id,
                        'dts_process_stop'      => $local_time + 1, // prevent stop = start.
                        'status'                => 'processed',
                        'dts_process_init_time' => 0, // set time 0 for processed.
                    )
                );
            }

            if ( $email_settings['disable'] ) {
                continue; // disabled send notification for this site.
            }
            $individual_admin_uptimeSites[] = $uptime_notice;
            static::send_uptime_notification_heartbeats_importance_status( array( $uptime_notice ), $admin_email, $email_settings, $plain_text );
        }

        if ( ! empty( $individual_admin_uptimeSites ) ) {
            $admin_email_settings               = MainWP_Notification_Settings::get_default_emails_fields( 'uptime', '', true ); // get default subject and heading only.
            $admin_email_settings['disable']    = 0;
            $admin_email_settings['recipients'] = ''; // sent to admin only.
            // send to admin, all individual sites in one email.
            MainWP_Logger::instance()->log_uptime_notice( 'Send all individual uptime notifications to the admin in a single email. [count=' . count( $individual_admin_uptimeSites ) . ']' );
            static::send_uptime_notification_heartbeats_importance_status( $individual_admin_uptimeSites, $admin_email, $admin_email_settings, $plain_text, true );
        }
        MainWP_Logger::instance()->log_uptime_notice( 'Uptime notifications email settings :: debug :: ' . print_r( $debug_settings, true ) ); //phpcs:ignore -- NOSONAR -ok.
        return true;
    }


    /**
     * Basic site uptime monitoring.
     *
     * @param array  $uptime_notices Array containing the uptime monitor notices.
     * @param string $admin_email    Notification email.
     * @param string $email_settings Email settings.
     * @param bool   $plain_text     Determines if the plain text format should be used.
     * @param bool   $to_admin Send to admin or not.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::update_website_values()
     * @uses \MainWP\Dashboard\MainWP_Notification::send_websites_uptime_monitoring()
     * @uses \MainWP\Dashboard\MainWP_Notification_Template::get_template_html()
     */
    public static function send_uptime_notification_heartbeats_importance_status( $uptime_notices, $admin_email, $email_settings, $plain_text, $to_admin = false ) { //phpcs:ignore -- NOSONAR - complex.
        if ( is_array( $uptime_notices ) ) {
            $heartbeats_notices = array();
            foreach ( $uptime_notices as $notice ) {
                if ( ! empty( $notice->dts_process_init_time ) ) {
                    $notice_heartbeats = MainWP_DB_Uptime_Monitoring::instance()->get_monitor_notification_heartbeats_to_send( $notice->monitor_id, $notice->dts_process_init_time );
                    if ( is_array( $notice_heartbeats ) ) {
                        foreach ( $notice_heartbeats as $hb_notice ) {
                            $new_obj                = clone $notice;  // to fix reference to object.
                            $new_obj->hb_http_code  = $hb_notice->http_code; // to fix for monitor with multi heartbeats down status.
                            $new_obj->status        = $hb_notice->status;
                            $new_obj->hb_time_check = strtotime( $hb_notice->time );
                            $heartbeats_notices[]   = $new_obj;
                        }
                    }
                }
            }
            if ( ! empty( $heartbeats_notices ) ) {
                MainWP_Logger::instance()->log_uptime_notice( 'Uptime notification :: heartbeats :: [count=' . count( $heartbeats_notices ) . ']' );
                MainWP_Monitoring_Handler::notice_sites_uptime_monitoring( $heartbeats_notices, $admin_email, $email_settings, $plain_text, $to_admin );
            }
        }
    }

    /**
     * Update uptime notification status.
     *
     * @param  string $new_status new status.
     *
     * @return void
     */
    public function update_uptime_notification_status( $new_status ) {
        MainWP_Utility::update_option( 'mainwp_process_uptime_notification_run_status', $new_status );
    }
}
