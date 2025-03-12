<?php
/**
 * MainWP System Cron Jobs.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

//phpcs:disable WordPress.WP.AlternativeFunctions -- for custom read/write file.

/**
 * Class MainWP_System_Cron_Jobs
 *
 * @package MainWP\Dashboard
 */
class MainWP_System_Cron_Jobs { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Singleton.
     *
     * @var null $instance
     */
    private static $instance = null;

    /**
     * Last auto logging.
     *
     * @var int Last time auto logs.
     */
    public $last_auto_logging = null;

    /**
     * MainWP Cron Instance.
     *
     * @return self $instance
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }

        return static::$instance;
    }

    /**
     * MainWP_System_Cron_Jobs constructor.
     *
     * Run each time the class is called.
     */
    public function __construct() {
    }

    /**
     * Method init_cron_jobs()
     *
     * Instantiate Cron Jobs.
     */
    public function init_cron_jobs() {

        /**
         * Action: mainwp_cronload_action
         *
         * Hooks MainWP cron jobs actions.
         *
         * @since Unknown
         */
        do_action( 'mainwp_cronload_action' );

        add_action( 'mainwp_cronreconnect_action', array( $this, 'cron_reconnect' ) );
        add_action( 'mainwp_cronbackups_action', array( $this, 'cron_backups' ) );
        add_action( 'mainwp_cronbackups_continue_action', array( $this, 'cron_backups_continue' ) );
        add_action( 'mainwp_cronupdatescheck_action', array( $this, 'cron_updates_check' ) );
        add_action( 'mainwp_cronpingchilds_action', array( $this, 'cron_ping_childs' ) );
        add_action( 'mainwp_cronsitehealthcheck_action', array( $this, 'cron_check_websites_health' ) );
        add_action( 'mainwp_crondeactivatedlicensesalert_action', array( $this, 'cron_deactivated_licenses_alert' ) );
        add_action( 'mainwp_cronuptimemonitoringcheck_action', array( MainWP_Uptime_Monitoring_Schedule::instance(), 'cron_uptime_check' ) );
        add_action( 'mainwp_cron_perform_general_process', array( $this, 'cron_perform_general_process' ) );

        // phpcs:ignore -- required for dashboard's minutely scheduled jobs.
        add_filter( 'cron_schedules', array( $this, 'get_cron_schedules' ), 9 );

        $this->init_cron();
    }

    /**
     * Method init_cron()
     *
     * Build Cron Jobs Array & initiate via init_mainwp_cron()
     */
    public function init_cron() { //phpcs:ignore -- NOSONAR - complex.

        // Check wether or not to use MainWP Cron false|1.
        $useWPCron = ( get_option( 'mainwp_wp_cron' ) === false ) || ( (int) get_option( 'mainwp_wp_cron' ) === 1 );

        // Default Cron Jobs.
        $jobs = array(
            'mainwp_cronreconnect_action'                => 'hourly',
            'mainwp_cronpingchilds_action'               => 'daily',
            'mainwp_cronupdatescheck_action'             => 'minutely',
            'mainwp_crondeactivatedlicensesalert_action' => 'daily',
            'mainwp_cronuptimemonitoringcheck_action'    => 'minutely',
            'mainwp_cron_perform_general_process'        => 'minutely',
        );

        if ( ! $useWPCron && ! get_option( 'mainwp_individual_uptime_monitoring_schedule_enabled' ) ) {
            unset( $jobs['mainwp_cronuptimemonitoringcheck_action'] );
        }

        $disableHealthChecking = get_option( 'mainwp_disableSitesHealthMonitoring', 1 ); // disabled by default.
        if ( ! $disableHealthChecking ) {
            $jobs['mainwp_cronsitehealthcheck_action'] = 'hourly';
        } else {
            // disable check sites health cron.
            $sched = wp_next_scheduled( 'mainwp_cronsitehealthcheck_action' );
            if ( false !== $sched ) {
                wp_unschedule_event( $sched, 'mainwp_cronsitehealthcheck_action' );
            }
        }

        // Legacy Backup Cron jobs.
        if ( get_option( 'mainwp_enableLegacyBackupFeature' ) ) {
            $jobs = array_merge(
                $jobs,
                array(
                    'mainwp_cronbackups_action'          => 'hourly',
                    'mainwp_cronbackups_continue_action' => '5minutely',
                )
            );
        } else {
            // Unset Cron Schedules.
            $sched = wp_next_scheduled( 'mainwp_cronbackups_action' );
            if ( $sched ) {
                wp_unschedule_event( $sched, 'mainwp_cronbackups_action' );
            }
            $sched = wp_next_scheduled( 'mainwp_cronbackups_continue_action' );
            if ( $sched ) {
                wp_unschedule_event( $sched, 'mainwp_cronbackups_continue_action' );
            }
        }

        foreach ( $jobs as $hook => $recur ) {
            $this->init_mainwp_cron( $useWPCron, $hook, $recur );
        }
    }

    /**
     * Method init_mainwp_cron()
     *
     * Schedual Cron Jobs.
     *
     * @param mixed $useWPCron Wether or not to use WP_Cron.
     * @param mixed $cron_hook When cron is going to reoccur.
     * @param mixed $recurrence Cron job hook.
     */
    public function init_mainwp_cron( $useWPCron, $cron_hook, $recurrence ) {
        $sched       = wp_next_scheduled( $cron_hook );
        $enabled_job = apply_filters( 'mainwp_is_enable_schedule_job', $useWPCron, $cron_hook, $recurrence );
        if ( false === $sched ) {
            if ( $useWPCron && $enabled_job ) {
                wp_schedule_event( time(), $recurrence, $cron_hook );
            }
        } elseif ( ! $useWPCron || ! $enabled_job ) {
            wp_unschedule_event( $sched, $cron_hook );
        }
    }

    /**
     * Method cron_active()
     *
     * Check if WP_Cron is active.
     *
     * @return void
     *
     * @uses \MainWP\Dashboard\MainWP_System::$version
     */
    public function cron_active() {
        session_write_close();
        if ( ! headers_sent() ) {
            header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ), true );
            header( 'X-Robots-Tag: noindex, nofollow', true );
            header( 'X-MainWP-Version: ' . MainWP_System::$version, true );
            nocache_headers();
        }
        wp_die( 'MainWP Test' );
    }

    /**
     * Method get_cron_schedules()
     *
     * Get current Cron Schedual.
     *
     * @param array $schedules Array of currently set scheduals.
     *
     * @return array $scheduales.
     */
    public function get_cron_schedules( $schedules ) {
        $schedules['5minutely'] = array(
            'interval' => 5 * 60,
            'display'  => esc_html__( 'Once every 5 minutes', 'mainwp' ),
        );
        $schedules['minutely']  = array(
            'interval' => 1 * 60,
            'display'  => esc_html__( 'Once every minute', 'mainwp' ),
        );

        return $schedules;
    }

    /**
     * Method get_timestamp_from_hh_mm()
     *
     * Get Time Stamp from $hh_mm.
     *
     * @param mixed $hh_mm Global time stamp variable.
     * @param int   $time Time of day.
     *
     * @return time Y-m-d 00:00:59.
     */
    public static function get_timestamp_from_hh_mm( $hh_mm, $time = false ) {
        $hh_mm = explode( ':', $hh_mm );
        $_hour = isset( $hh_mm[0] ) ? intval( $hh_mm[0] ) : 0;
        $_mins = isset( $hh_mm[1] ) ? intval( $hh_mm[1] ) : 0;
        if ( $_hour < 0 || $_hour > 23 ) {
            $_hour = 0;
        }
        if ( $_mins < 0 || $_mins > 59 ) {
            $_mins = 0;
        }
        if ( ! empty( $time ) ) {
            $str_date = date( 'Y-m-d', $time ); // phpcs:ignore -- check update at given time.
        } else {
            $lctime   = MainWP_Utility::get_timestamp();
            $str_date = date( 'Y-m-d', $lctime ); // phpcs:ignore -- check update at local server time.
        }
        return strtotime( $str_date . ' ' . $_hour . ':' . $_mins . ':59' ); // phpcs:ignore -- check update at given time.
    }

    /**
     * Method get_next_time_automatic_update_to_show()
     *
     * Get websites automatic update next time.
     *
     * @return mixed array
     */
    public static function get_next_time_automatic_update_to_show() {
        // to calculate next_time.
        static::instance()->check_conds_to_run_auto_update( $next_time, $run_timestamp, $frequence_period_in_seconds );
        return $next_time;
    }

    /**
     * Method check_conds_to_run_auto_update()
     *
     * To check conditionals to run auto update checking.
     *
     * @param int $next_time Next time to run.
     * @param int $run_timestamp Runtime daily.
     * @param int $frequence_in_seconds frequence running in seconds.
     *
     * @return bool Valid or not to run.
     */
    public function check_conds_to_run_auto_update( &$next_time, &$run_timestamp = false, &$frequence_in_seconds = false ) { //phpcs:ignore -- NOSONAR - complexity.
        $local_timestamp = MainWP_Utility::get_timestamp();
        $today_0h = strtotime( date("Y-m-d 00:00:00", $local_timestamp) ); // phpcs:ignore -- to localtime.

        $timeDailyUpdate = get_option( 'mainwp_timeDailyUpdate' );

        if ( ! empty( $timeDailyUpdate ) ) {
            $run_timestamp = static::get_timestamp_from_hh_mm( $timeDailyUpdate );
        } else {
            $run_timestamp = $today_0h; // midnight.
        }

        $lasttimeScheStartAutoUpdate = (int) get_option( 'mainwp_updatescheck_start_last_timestamp', 0 );
        $frequency_daily             = (int) get_option( 'mainwp_frequencyDailyUpdate', 2 );

        if ( $frequency_daily <= 0 ) {
            $frequency_daily = 1;
        }

        if ( $frequency_daily > 1 ) {
            $frequence_in_seconds = intval( DAY_IN_SECONDS / $frequency_daily );
            if ( $frequence_in_seconds < 2 * HOUR_IN_SECONDS ) {
                $frequence_in_seconds = 2 * HOUR_IN_SECONDS;
            }
        } else {
            $frequence_in_seconds = DAY_IN_SECONDS - 1;
        }

        if ( $next_time < $run_timestamp ) {
            $next_time = $run_timestamp;
        }

        // Adjust the runtime according to the runtime settings, in certain sudden cases.
        // Apply at intervals of more than 2 hours.
        if ( $frequence_in_seconds >= 2 * HOUR_IN_SECONDS && $local_timestamp < $next_time && $lasttimeScheStartAutoUpdate + $frequence_in_seconds > $next_time + HOUR_IN_SECONDS ) {
            // Process it within the next scheduled run timeframe.
            // make sure of this.
            // The difference is more than 1 hour.
            MainWP_Utility::update_option( 'mainwp_adjust_auto_sync_run_time', $next_time );
            MainWP_Logger::instance()->log_update_check( 'updates check :: adjust next run time :: next_time :: ' . gmdate( 'Y-m-d H:i:s', $next_time ) . ' :: local_timestamp :: ' . gmdate( 'Y-m-d H:i:s', $local_timestamp ) );
        }

        $adjust_run_time = get_option( 'mainwp_adjust_auto_sync_run_time' );
        if ( ! empty( $adjust_run_time ) ) {
            $next_time = $adjust_run_time;
        } elseif ( $next_time < $lasttimeScheStartAutoUpdate + $frequence_in_seconds ) {
            $next_time = $lasttimeScheStartAutoUpdate + $frequence_in_seconds;
        }

        if ( $next_time < $local_timestamp ) {
            $next_time = $local_timestamp; // will run now.
            if ( ! empty( $adjust_run_time ) ) {
                delete_option( 'mainwp_adjust_auto_sync_run_time' ); // Adjusted the runtime.
            }
            MainWP_Logger::instance()->log_update_check( 'updates check :: time to frequency run today :: next_time :: ' . gmdate( 'Y-m-d H:i:s', $next_time ) . ' :: local_timestamp :: ' . gmdate( 'Y-m-d H:i:s', $local_timestamp ) );
        } elseif ( $this->is_log_time() ) {
            MainWP_Logger::instance()->log_update_check( 'updates check :: wait frequency today :: daily sync time  :: ' . gmdate( 'Y-m-d H:i:s', $run_timestamp ) . ' :: next_time :: ' . gmdate( 'Y-m-d H:i:s', $next_time ) . ' :: local_timestamp :: ' . gmdate( 'Y-m-d H:i:s', $local_timestamp ) );
        }

        $check_to_run = false;

        if ( $local_timestamp >= $next_time ) {
            $check_to_run = true;
            $next_time    = $local_timestamp + $frequence_in_seconds; // to show next time.
        }

        return $check_to_run;
    }

    /**
     * Method is_log_time().
     */
    public function is_log_time() {
        if ( null === $this->last_auto_logging ) {
            $this->last_auto_logging = get_option( 'mainwp_log_wait_lasttime', 0 ); // load last time logging.
        }
        $time = time();
        // do not logging much in short time.
        if ( $this->last_auto_logging + 15 * MINUTE_IN_SECONDS < $time ) {
            MainWP_Utility::update_option( 'mainwp_log_wait_lasttime', $time ); //phpcs:ignore -- local time.
            return true;
        }
        return false;
    }

    /**
     * Method init_environment()
     */
    public function init_environment() {
        ignore_user_abort( true );
        MainWP_System_Utility::set_time_limit( 0 );
        add_filter(
            'admin_memory_limit',
            function () {
                return '512M';
            }
        );
    }

    /**
     * Method update_auto_sync_time
     *
     * @param  int $site_id site id.
     * @return void
     */
    public function update_auto_sync_time( $site_id ) {
        $websiteValues = array(
            'dtsAutomaticSync' => MainWP_Utility::get_timestamp(),
        );
        MainWP_DB::instance()->update_website_sync_values( $site_id, $websiteValues );
    }

    /**
     * Method cron_updates_check()
     *
     * MainWP Cron Check Update
     *
     * This Cron Checks to see if Automatic Daily Updates need to be performed.
     *
     * @uses \MainWP\Dashboard\MainWP_Backup_Handler::is_archive()
     * @uses \MainWP\Dashboard\MainWP_Backup_Handler::backup()
     * @uses \MainWP\Dashboard\MainWP_Backup_Handler::backup_download_file()
     * @uses \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::backup_full_task_running()
     * @uses \MainWP\Dashboard\MainWP_DB_Common::get_user_extension_by_user_id()
     * @uses \MainWP\Dashboard\MainWP_DB::update_website_sync_values()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
     * @uses \MainWP\Dashboard\MainWP_DB::update_website_option()
     * @uses \MainWP\Dashboard\MainWP_Logger::info()
     * @uses \MainWP\Dashboard\MainWP_Logger::debug()
     * @uses \MainWP\Dashboard\MainWP_Notification_Settings::get_general_email_settings()
     * @uses \MainWP\Dashboard\MainWP_Notification_Settings::get_site_email_settings()
     * @uses \MainWP\Dashboard\MainWP_Notification_Settings::get_default_emails_fields()
     * @uses \MainWP\Dashboard\MainWP_Sync::sync_site()
     * @uses \MainWP\Dashboard\MainWP_Sync::get_wp_icon()
     * @uses \MainWP\Dashboard\MainWP_Sync::sync_information_array()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_wp_file_system()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_mainwp_specific_dir()
     * @uses  \MainWP\Dashboard\MainWP_Utility::get_timestamp()
     * @uses  \MainWP\Dashboard\MainWP_Utility::update_option()
     */
    public function cron_updates_check() { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- NOSONAR Current complexity is the only way to achieve desired results, pull request solutions appreciated.

        $this->init_environment();

        $batch_updates_running = MainWP_Cron_Jobs_Batch::instance()->check_to_run_batch_updates();
        if ( $batch_updates_running ) {
            MainWP_Logger::instance()->log_events( 'debug-updates-crons', 'Start run - batch updates' );
            MainWP_Cron_Jobs_Batch::instance()->handle_cron_batch_updates();
            return;
        } else {
            $auto_updates_running = MainWP_Cron_Jobs_Auto_Updates::instance()->check_to_run_auto_updates();
            if ( $auto_updates_running ) {
                MainWP_Logger::instance()->log_events( 'debug-updates-crons', 'Start run - auto updates' );
                MainWP_Cron_Jobs_Auto_Updates::instance()->handle_cron_auto_updates();
                return;
            }
        }

        MainWP_Logger::instance()->log_events( 'debug-updates-crons', 'Start run - updates check' );

        $updatecheck_running = ( 'Y' === get_option( 'mainwp_updatescheck_is_running' ) ? true : false );

        $local_timestamp = MainWP_Utility::get_timestamp();

        $frequencyDailyUpdate = (int) get_option( 'mainwp_frequencyDailyUpdate', 2 );
        if ( $frequencyDailyUpdate <= 0 ) {
            $frequencyDailyUpdate = 1;
        }

        $today_m_y = date( 'd/m/Y', $local_timestamp ); //phpcs:ignore -- local time.

        $lasttimeAutomaticUpdate = get_option( 'mainwp_updatescheck_last_timestamp' );
        $lasttimeDailyDigest     = get_option( 'mainwp_updatescheck_dailydigest_last_timestamp' );
        $mainwpLastDailydigest   = get_option( 'mainwp_dailydigest_last' );

        /**
         * Filter: mainwp_updatescheck_sendmail_at_time
         *
         * Filters the the time when the Daily Digest email will be sent.
         *
         * @since 3.4
         */
        $send_noti_at   = apply_filters( 'mainwp_updatescheck_sendmail_at_time', false );
        $time_to_noti   = false;
        $time_notice_at = false;
        if ( ! empty( $send_noti_at ) ) {
            $send_timestamp = static::get_timestamp_from_hh_mm( $send_noti_at, $lasttimeDailyDigest );
            $time_notice_at = $local_timestamp > $send_timestamp ? true : false;
        } elseif ( 1 < $frequencyDailyUpdate ) {
                $time_to_noti = ( $today_m_y !== $mainwpLastDailydigest ) ? true : false;
        }
        $sendmail_for_each_auto_sync = apply_filters( 'mainwp_updatescheck_sendmail_for_each_auto_sync_finished', false );

        $check_ready_sendmail = false;
        if ( $sendmail_for_each_auto_sync || 1 === (int) $frequencyDailyUpdate || $time_notice_at || $time_to_noti ) {
            $check_ready_sendmail = true;
        }

        if ( $check_ready_sendmail && 'Y' === get_option( 'mainwp_updatescheck_ready_sendmail' ) ) {

            $plain_text = get_option( 'mainwp_daily_digest_plain_text', false );

            MainWP_Utility::update_option( 'mainwp_dailydigest_last', $today_m_y );

            MainWP_Utility::update_option( 'mainwp_updatescheck_dailydigest_last_timestamp', $local_timestamp );

            // send daily digest email one time per day.
            $individual_digestWebsites = get_option( 'mainwp_updatescheck_individual_digest_websites' );

            MainWP_Utility::update_option( 'mainwp_updatescheck_ready_sendmail', '' ); // here to fix.

            MainWP_Logger::instance()->log_update_check( 'got to the daily digest mail part' );

            $gen_email_settings = MainWP_Notification_Settings::get_general_email_settings( 'daily_digest' );
            if ( empty( $gen_email_settings['disable'] ) ) {
                // send general daily digests.
                $this->start_notification_daily_digest( $gen_email_settings, $plain_text ); // general email.
            }

            $to_admin_digestWebsites = array();

            if ( is_array( $individual_digestWebsites ) && ! empty( $individual_digestWebsites ) ) {
                // send individual site daily digests, one email for one site.
                foreach ( $individual_digestWebsites as $siteid ) {
                    $website        = MainWP_DB::instance()->get_website_by_id( $siteid, false, array( 'settings_notification_emails' ) );
                    $email_settings = MainWP_Notification_Settings::get_site_email_settings( 'daily_digest', $website );  // get site email settings.
                    if ( ! $email_settings['disable'] ) {
                        $to_admin_digestWebsites[] = $siteid;
                        $sent                      = $this->start_notification_daily_digest( $email_settings, $plain_text, array( $siteid ), $website );
                        if ( $sent ) {
                            usleep( 100000 );
                        }
                    }
                }
            }

            if ( ! empty( $to_admin_digestWebsites ) ) {
                $admin_email_settings               = MainWP_Notification_Settings::get_default_emails_fields( 'daily_digest', '', true ); // get default subject and heading only.
                $admin_email_settings['disable']    = 0;
                $admin_email_settings['recipients'] = MainWP_Notification_Settings::get_general_email(); // sent to general notification email only.
                // send all individual daily digest to admin in one email.
                $this->start_notification_daily_digest( $admin_email_settings, $plain_text, $to_admin_digestWebsites ); // will send email to general notification email.
            }
            $this->refresh_saved_fields();
        }

        $valid_to_run = $this->check_conds_to_run_auto_update( $next_time, $run_timestamp, $frequence_period_in_seconds );

        /**
         * Filter: mainwp_updatescheck_hours_interval
         *
         * Filters the status check interval.
         *
         * @since 3.4
         */
        $hoursIntervalAutomaticUpdate = apply_filters( 'mainwp_updatescheck_hours_interval', false );

        $manual_trigger_last_timestamp = 0;

        $manual_trigger_start = get_option( 'mainwp_auto_sync_manual_trigger_start_last_timestamp' );

        if ( ! empty( $manual_trigger_start ) ) {
            if ( is_numeric( $manual_trigger_start ) ) {
                $manual_trigger_last_timestamp = intval( $manual_trigger_start ); // timestamp value.
            } elseif ( is_string( $manual_trigger_start ) ) {
                $manual_trigger_last_timestamp = strtotime( $manual_trigger_start ); // Y-m-d H:i:S format.
            }
        }

        if ( ! $updatecheck_running ) {
            $run_auto_checks    = false;
            $run_hours_interval = null;
            if ( $hoursIntervalAutomaticUpdate > 0 ) {
                $run_hours_interval = false;
                if ( $lasttimeAutomaticUpdate && ( $lasttimeAutomaticUpdate + $hoursIntervalAutomaticUpdate * 3600 > $local_timestamp ) ) {
                    MainWP_Logger::instance()->log_update_check( 'updates check :: already updated hours interval' );
                } else {
                    $run_hours_interval = true;
                }
            }

            if ( $run_hours_interval || ( null === $run_hours_interval && $valid_to_run ) ) { // if not set sync time, and run frequency.
                $run_auto_checks = true;
            }

            if ( $manual_trigger_last_timestamp ) {
                $run_auto_checks = true;
            }

            if ( ! $run_auto_checks ) {
                if ( $this->is_log_time() ) {
                    MainWP_Logger::instance()->log_update_check( 'updates check :: wait frequency today :: [frequencyDailyUpdate=' . $frequencyDailyUpdate . '] :: [frequence=' . gmdate( 'H:i:s', $frequence_period_in_seconds ) . ']' );
                }
                return;
            }
        }

        $lasttimeStartAutomaticUpdate = get_option( 'mainwp_updatescheck_start_last_timestamp' );

        // not batch updates run at the moment.
        if ( ! $updatecheck_running ) {

            if ( ! $manual_trigger_last_timestamp ) {
                // do not update if manual trigger.
                MainWP_Utility::update_option( 'mainwp_updatescheck_start_last_timestamp', $local_timestamp ); // start new update checking.
                $lasttimeStartAutomaticUpdate = $local_timestamp;
            } else {
                $lasttimeStartAutomaticUpdate = $manual_trigger_last_timestamp;
            }

            MainWP_Utility::update_option( 'mainwp_updatescheck_is_running', 'Y' );

            // log last run.
            $last_run = get_option( 'mainwp_updatescheck_last_run' );
            if ( $last_run ) {
                $last_run = json_decode( $last_run );
            }
            if ( ! is_array( $last_run ) ) {
                $last_run = array();
            }

            if ( count( $last_run ) > 20 ) {
                array_shift( $last_run );
            }

            $last_run[] = date( 'Y-m-d H:i:s', $local_timestamp );  //phpcs:ignore -- local time.

            MainWP_Utility::update_option( 'mainwp_updatescheck_last_run', wp_json_encode( $last_run ) );

            if ( $local_timestamp > $run_timestamp && $local_timestamp < $run_timestamp + $frequence_period_in_seconds && $frequencyDailyUpdate > 1 ) {
                $last_run_timestamp_today = $run_timestamp + $frequence_period_in_seconds * ( $frequencyDailyUpdate - 1 );
                MainWP_Utility::update_option( 'mainwp_updatescheck_last_run_timestamp_today', $last_run_timestamp_today );
            }
            $this->refresh_saved_fields();

            $log_lastsstart = ! empty( $lasttimeStartAutomaticUpdate ) ? '[lasttimeStartAutomaticUpdate=' . MainWP_Utility::format_timestamp( $lasttimeStartAutomaticUpdate ) . ']' : '';
            MainWP_Logger::instance()->log_update_check( 'Start automatic check updates :: ' . $log_lastsstart );
        }

        $websites             = array();
        $checkupdate_websites = MainWP_Auto_Updates_DB::instance()->get_websites_check_updates( 4, $lasttimeStartAutomaticUpdate ); // to sync sites data.

        foreach ( $checkupdate_websites as $website ) {
            if ( ! MainWP_DB_Backup::instance()->backup_full_task_running( $website->id ) ) {
                $websites[] = $website;
            }
        }
        MainWP_Logger::instance()->info( 'updates check found ' . count( $checkupdate_websites ) . ' websites' );
        MainWP_Logger::instance()->log_update_check( 'updates check found [' . count( $checkupdate_websites ) . ' websites] :: going to check [' . count( $websites ) . ' websites]' );

        $userid = null;
        foreach ( $websites as $website ) {
            $websiteValues = array(
                'dtsAutomaticSyncStart' => $local_timestamp,
            );
            if ( null === $userid ) {
                $userid = $website->userid;
            }

            MainWP_DB::instance()->update_website_sync_values( $website->id, $websiteValues );
        }

        $plain_text = get_option( 'mainwp_daily_digest_plain_text', false );

        /**
         * Filter: mainwp_text_format_email
         *
         * Filters whether the email shuld bein plain text format.
         *
         * @since 3.5
         */
        $filter_plain_text = apply_filters( 'mainwp_text_format_email', $plain_text );

        if ( $plain_text !== $filter_plain_text ) {
            $plain_text = $filter_plain_text;
            MainWP_Utility::update_option( 'mainwp_daily_digest_plain_text', $plain_text );
        }

        if ( empty( $checkupdate_websites ) ) {
            $busyCounter = MainWP_Auto_Updates_DB::instance()->get_websites_count_where_dts_automatic_sync_smaller_then_start( $lasttimeStartAutomaticUpdate );
            if ( ! empty( $busyCounter ) ) {
                MainWP_Logger::instance()->log_update_check( 'busy counter :: found ' . $busyCounter . ' websites' );
                $lastAutomaticUpdate = MainWP_Auto_Updates_DB::instance()->get_websites_last_automatic_sync();
                if ( ( time() - $lastAutomaticUpdate ) < HOUR_IN_SECONDS ) {
                    MainWP_Logger::instance()->log_update_check( 'Automatic check updates last time :: ' . MainWP_Utility::format_timestamp( $lastAutomaticUpdate ) );
                }
            }

            if ( $manual_trigger_last_timestamp ) {
                // finished manual trigger auto sync.
                delete_option( 'mainwp_auto_sync_manual_trigger_start_last_timestamp' );
                MainWP_Logger::instance()->log_update_check( 'Manual trigger for automatic synchronization of all sites completed.' );
            } else {
                MainWP_Logger::instance()->log_update_check( 'Automatic sync of all sites completed.' );
            }

            if ( $updatecheck_running ) {
                MainWP_Utility::update_option( 'mainwp_updatescheck_is_running', '' );
                $updatecheck_running = false;
                do_action( 'mainwp_synced_all_sites' );
            }

            update_option( 'mainwp_last_synced_all_sites', time() );
            MainWP_Utility::update_option( 'mainwp_updatescheck_last_timestamp', $local_timestamp );

            MainWP_Utility::update_option( 'mainwp_updatescheck_last', $today_m_y );

            // send http check notification.
            if ( 1 === (int) get_option( 'mainwp_check_http_response', 0 ) ) {
                $this->start_notification_http_check( $plain_text );
            }

            if ( 'Y' !== get_option( 'mainwp_updatescheck_ready_sendmail' ) ) {
                MainWP_Utility::update_option( 'mainwp_updatescheck_ready_sendmail', 'Y' );
            }
        } else {

            $userExtension = MainWP_DB_Common::instance()->get_user_extension_by_user_id( $userid );

            $decodedIgnoredCores   = ! empty( $userExtension->ignored_wp_upgrades ) ? json_decode( $userExtension->ignored_wp_upgrades, true ) : array();
            $decodedIgnoredPlugins = json_decode( $userExtension->ignored_plugins, true );
            $trustedPlugins        = json_decode( $userExtension->trusted_plugins, true );
            $decodedIgnoredThemes  = json_decode( $userExtension->ignored_themes, true );
            $trustedThemes         = json_decode( $userExtension->trusted_themes, true );

            if ( ! is_array( $decodedIgnoredCores ) ) {
                $decodedIgnoredCores = array();
            }

            if ( ! is_array( $decodedIgnoredPlugins ) ) {
                $decodedIgnoredPlugins = array();
            }

            if ( ! is_array( $trustedPlugins ) ) {
                $trustedPlugins = array();
            }

            if ( ! is_array( $decodedIgnoredThemes ) ) {
                $decodedIgnoredThemes = array();
            }

            if ( ! is_array( $trustedThemes ) ) {
                $trustedThemes = array();
            }

            $coreToUpdate         = array();
            $coreNewUpdate        = array();
            $ignoredCoreToUpdate  = array();
            $ignoredCoreNewUpdate = array();

            $pluginsToUpdate            = array();
            $pluginsNewUpdate           = array();
            $notTrustedPluginsToUpdate  = array();
            $notTrustedPluginsNewUpdate = array();

            $themesToUpdate            = array();
            $themesNewUpdate           = array();
            $notTrustedThemesToUpdate  = array();
            $notTrustedThemesNewUpdate = array();

            $transToUpdate            = array();
            $transNewUpdate           = array();
            $notTrustedTransToUpdate  = array();
            $notTrustedTransNewUpdate = array();

            $individualDailyDigestWebsites = array();

            $updatescheckSitesIcon = get_option( 'mainwp_updatescheck_sites_icon' );
            if ( ! is_array( $updatescheckSitesIcon ) ) {
                $updatescheckSitesIcon = array();
            }

            $delay_autoupdate = get_option( 'mainwp_delay_autoupdate', 1 );

            foreach ( $websites as $website ) {
                $websiteDecodedIgnoredPlugins = json_decode( $website->ignored_plugins, true );
                if ( ! is_array( $websiteDecodedIgnoredPlugins ) ) {
                    $websiteDecodedIgnoredPlugins = array();
                }

                $websiteDecodedIgnoredThemes = json_decode( $website->ignored_themes, true );
                if ( ! is_array( $websiteDecodedIgnoredThemes ) ) {
                    $websiteDecodedIgnoredThemes = array();
                }

                $synced = MainWP_Sync::sync_site( $website, false, true );

                $this->update_auto_sync_time( $website->id );

                if ( ! $synced ) {
                    MainWP_Logger::instance()->log_update_check( 'auto sync failed :: [siteid=' . $website->id . ']' );
                    continue;
                }

                MainWP_Logger::instance()->log_update_check( 'auto sync success :: [siteid=' . $website->id . ']' );

                $website = MainWP_DB::instance()->get_website_by_id( $website->id, false, array( 'wp_upgrades', 'ignored_wp_upgrades', 'last_wp_upgrades' ) );

                $check_individual_digest = false;

                /** Check core updates * */
                $websiteLastCoreUpgrades     = ! empty( $website->last_wp_upgrades ) ? json_decode( $website->last_wp_upgrades, true ) : array();
                $websiteCoreUpgrades         = ! empty( $website->wp_upgrades ) ? json_decode( $website->wp_upgrades, true ) : array();
                $website_decodedIgnoredCores = ! empty( $website->ignored_wp_upgrades ) ? json_decode( $website->ignored_wp_upgrades, true ) : array();

                $websiteCoreUpdateCheck    = 0;
                $websitePluginsUpdateCheck = array();
                $websiteThemesUpdateCheck  = array();
                $websiteTransUpdateCheck   = array();

                if ( ! empty( $delay_autoupdate ) ) {
                    $websiteCoreUpdateCheck    = MainWP_DB::instance()->get_website_option( $website, 'core_update_check' );
                    $websitePluginsUpdateCheck = MainWP_DB::instance()->get_website_option( $website, 'plugins_update_check' );
                    $websiteThemesUpdateCheck  = MainWP_DB::instance()->get_website_option( $website, 'themes_update_check' );
                    $websiteTransUpdateCheck   = MainWP_DB::instance()->get_website_option( $website, 'trans_update_check' );

                    $websiteCoreUpdateCheck    = intval( $websiteCoreUpdateCheck );
                    $websitePluginsUpdateCheck = ! empty( $websitePluginsUpdateCheck ) ? json_decode( $websitePluginsUpdateCheck, true ) : array();
                    $websiteThemesUpdateCheck  = ! empty( $websiteThemesUpdateCheck ) ? json_decode( $websiteThemesUpdateCheck, true ) : array();
                    $websiteTransUpdateCheck   = ! empty( $websiteTransUpdateCheck ) ? json_decode( $websiteTransUpdateCheck, true ) : array();

                    if ( ! is_array( $websitePluginsUpdateCheck ) ) {
                        $websitePluginsUpdateCheck = array();
                    }

                    if ( ! is_array( $websiteThemesUpdateCheck ) ) {
                        $websiteThemesUpdateCheck = array();
                    }

                    if ( ! is_array( $websiteTransUpdateCheck ) ) {
                        $websiteTransUpdateCheck = array();
                    }
                }

                if ( isset( $websiteCoreUpgrades['current'] ) ) {
                    $newUpdate = ! ( isset( $websiteLastCoreUpgrades['current'] ) && ( $websiteLastCoreUpgrades['current'] === $websiteCoreUpgrades['current'] ) && ( $websiteLastCoreUpgrades['new'] === $websiteCoreUpgrades['new'] ) );
                    if ( ! $website->is_ignoreCoreUpdates && ! MainWP_Common_Functions::instance()->is_ignored_updates( $websiteCoreUpgrades, $website_decodedIgnoredCores, 'core' ) && ! MainWP_Common_Functions::instance()->is_ignored_updates( $websiteCoreUpgrades, $decodedIgnoredCores, 'core' ) ) {
                        $check_individual_digest = true;
                        $item                    = array(
                            'id'          => $website->id,
                            'name'        => $website->name,
                            'url'         => $website->url,
                            'current'     => $websiteCoreUpgrades['current'],
                            'new_version' => $websiteCoreUpgrades['new'],
                        );
                        if ( ! empty( $website->automatic_update ) && 1 === (int) $website->automatic_update ) {
                            $item['trusted'] = 1;
                            if ( $newUpdate ) {
                                $item['new']            = 1;
                                $coreNewUpdate[]        = $item;
                                $websiteCoreUpdateCheck = time();
                            } else {
                                $item['new']    = 0;
                                $coreToUpdate[] = $item;
                                if ( empty( $websiteCoreUpdateCheck ) && ! empty( $delay_autoupdate ) ) {
                                    $websiteCoreUpdateCheck = time();
                                }
                            }
                        } else {
                            $item['trusted'] = 0;
                            if ( $newUpdate ) {
                                $item['new']            = 1;
                                $ignoredCoreNewUpdate[] = $item;
                            } else {
                                $item['new']           = 0;
                                $ignoredCoreToUpdate[] = $item;
                            }
                            $websiteCoreUpdateCheck = 0;
                        }
                    }
                }

                /** Check plugins * */
                $websiteLastPlugins = MainWP_DB::instance()->get_website_option( $website, 'last_plugin_upgrades' );
                $websiteLastPlugins = ! empty( $websiteLastPlugins ) ? json_decode( $websiteLastPlugins, true ) : array();

                $websitePlugins = json_decode( $website->plugin_upgrades, true );

                /** Check themes * */
                $websiteLastThemes = MainWP_DB::instance()->get_website_option( $website, 'last_theme_upgrades' );
                $websiteLastThemes = ! empty( $websiteLastThemes ) ? json_decode( $websiteLastThemes, true ) : array();

                $websiteThemes = json_decode( $website->theme_upgrades, true );

                /** Check translations * */
                $websiteLastTrans = MainWP_DB::instance()->get_website_option( $website, 'last_trans_upgrades' );
                $websiteLastTrans = ! empty( $websiteLastTrans ) ? json_decode( $websiteLastTrans, true ) : array();

                $websiteTrans = json_decode( $website->translation_upgrades, true );

                $decodedPremiumUpgrades = MainWP_DB::instance()->get_website_option( $website, 'premium_upgrades' );
                $decodedPremiumUpgrades = ! empty( $decodedPremiumUpgrades ) ? json_decode( $decodedPremiumUpgrades, true ) : array();

                if ( is_array( $decodedPremiumUpgrades ) ) {
                    foreach ( $decodedPremiumUpgrades as $slug => $premiumUpgrade ) {
                        if ( 'plugin' === $premiumUpgrade['type'] ) {
                            if ( ! is_array( $websitePlugins ) ) {
                                $websitePlugins = array();
                            }
                            $websitePlugins[ $slug ] = $premiumUpgrade;
                        } elseif ( 'theme' === $premiumUpgrade['type'] ) {
                            if ( ! is_array( $websiteThemes ) ) {
                                $websiteThemes = array();
                            }
                            $websiteThemes[ $slug ] = $premiumUpgrade;
                        }
                    }
                }

                foreach ( $websitePlugins as $pluginSlug => $pluginInfo ) {
                    if ( $website->is_ignorePluginUpdates ) {
                        continue;
                    }

                    if ( MainWP_Common_Functions::instance()->is_ignored_updates( $pluginInfo, $decodedIgnoredPlugins, 'plugin' ) || MainWP_Common_Functions::instance()->is_ignored_updates( $pluginInfo, $websiteDecodedIgnoredPlugins, 'plugin' ) ) {
                        continue;
                    }

                    $check_individual_digest = true;

                    $change_log = '';
                    if ( isset( $pluginInfo['update']['url'] ) && ( false !== strpos( $pluginInfo['update']['url'], 'wordpress.org/plugins' ) ) ) {
                        $change_log = $pluginInfo['update']['url'];
                        if ( substr( $change_log, - 1 ) !== '/' ) {
                            $change_log .= '/';
                        }
                        $change_log .= '#developers';
                    }

                    $item = array(
                        'id'          => $website->id,
                        'name'        => $website->name,
                        'url'         => $website->url,
                        'plugin'      => $pluginInfo['Name'],
                        'current'     => $pluginInfo['Version'],
                        'new_version' => $pluginInfo['update']['new_version'],
                        'change_log'  => $change_log,
                        'slug'        => $pluginSlug,
                    );

                    $newUpdate = ! ( isset( $websiteLastPlugins[ $pluginSlug ] ) && ( $pluginInfo['Version'] === $websiteLastPlugins[ $pluginSlug ]['Version'] ) && ( $pluginInfo['update']['new_version'] === $websiteLastPlugins[ $pluginSlug ]['update']['new_version'] ) );
                    if ( in_array( $pluginSlug, $trustedPlugins ) ) {
                        $item['trusted'] = 1;
                        if ( $newUpdate ) {
                            $item['new']        = 1;
                            $pluginsNewUpdate[] = $item;
                            if ( ! empty( $delay_autoupdate ) ) {
                                $websitePluginsUpdateCheck[ $pluginSlug ] = time();
                            }
                        } else {
                            $item['new']       = 0;
                            $pluginsToUpdate[] = $item;
                            $check_timestamp   = isset( $websitePluginsUpdateCheck[ $pluginSlug ] ) ? $websitePluginsUpdateCheck[ $pluginSlug ] : 0;
                            if ( ! empty( $delay_autoupdate ) && empty( $check_timestamp ) ) {
                                $websitePluginsUpdateCheck[ $pluginSlug ] = time();
                            }
                        }
                    } else {
                        $item['trusted'] = 0;
                        if ( $newUpdate ) {
                            $item['new']                  = 1;
                            $notTrustedPluginsNewUpdate[] = $item;
                        } else {
                            $item['new']                 = 0;
                            $notTrustedPluginsToUpdate[] = $item;
                        }
                        if ( isset( $websitePluginsUpdateCheck[ $pluginSlug ] ) ) {
                            unset( $websitePluginsUpdateCheck[ $pluginSlug ] );
                        }
                    }
                }

                foreach ( $websiteThemes as $themeSlug => $themeInfo ) {

                    if ( $website->is_ignoreThemeUpdates ) {
                        continue;
                    }

                    if ( MainWP_Common_Functions::instance()->is_ignored_updates( $themeInfo, $decodedIgnoredThemes, 'theme' ) || MainWP_Common_Functions::instance()->is_ignored_updates( $themeInfo, $websiteDecodedIgnoredThemes, 'theme' ) ) {
                        continue;
                    }

                    $check_individual_digest = true;

                    $newUpdate = ! ( isset( $websiteLastThemes[ $themeSlug ] ) && ( $themeInfo['Version'] === $websiteLastThemes[ $themeSlug ]['Version'] ) && ( $themeInfo['update']['new_version'] === $websiteLastThemes[ $themeSlug ]['update']['new_version'] ) );

                    $item = array(
                        'id'          => $website->id,
                        'name'        => $website->name,
                        'url'         => $website->url,
                        'theme'       => $themeInfo['Name'],
                        'current'     => $themeInfo['Version'],
                        'new_version' => $themeInfo['update']['new_version'],
                        'slug'        => $themeSlug,
                    );

                    if ( in_array( $themeSlug, $trustedThemes ) ) {
                        $item['trusted'] = 1;
                        if ( $newUpdate ) {
                            $item['new']       = 1;
                            $themesNewUpdate[] = $item;
                            if ( ! empty( $delay_autoupdate ) ) {
                                $websiteThemesUpdateCheck[ $themeSlug ] = time();
                            }
                        } else {
                            $item['new']     = 0;
                            $check_timestamp = isset( $websiteThemesUpdateCheck[ $themeSlug ] ) ? $websiteThemesUpdateCheck[ $themeSlug ] : 0;
                            if ( empty( $check_timestamp ) && ! empty( $delay_autoupdate ) ) {
                                $websiteThemesUpdateCheck[ $themeSlug ] = time();
                            }
                            $themesToUpdate[] = $item;
                        }
                    } else {
                        $item['trusted'] = 0;
                        if ( $newUpdate ) {
                            $item['new']                 = 1;
                            $notTrustedThemesNewUpdate[] = $item;
                        } else {
                            $item['new']                = 0;
                            $notTrustedThemesToUpdate[] = $item;
                        }
                        if ( isset( $websiteThemesUpdateCheck[ $themeSlug ] ) ) {
                            unset( $websiteThemesUpdateCheck[ $themeSlug ] );
                        }
                    }
                }

                foreach ( $websiteTrans as $transSlug => $transInfo ) {

                    $check_individual_digest = true;

                    $newUpdate = ! isset( $websiteLastTrans[ $transSlug ] );

                    $item = array(
                        'id'          => $website->id,
                        'name'        => $website->name,
                        'url'         => $website->url,
                        'translation' => $transInfo['name'],
                        'version'     => $transInfo['version'],
                        'slug'        => $transInfo['slug'],
                    );

                    if ( MainWP_Manage_Sites_Update_View::is_trans_trusted_update( $transInfo, $trustedPlugins, $trustedThemes ) ) {
                        $item['trusted'] = 1;
                        if ( $newUpdate ) {
                            $item['new']      = 1;
                            $transNewUpdate[] = $item;
                            if ( ! empty( $delay_autoupdate ) ) {
                                $websiteTransUpdateCheck[ $transSlug ] = time();
                            }
                        } else {
                            $item['new']     = 0;
                            $check_timestamp = isset( $websiteTransUpdateCheck[ $transSlug ] ) ? $websiteTransUpdateCheck[ $transSlug ] : 0;
                            if ( empty( $check_timestamp ) && ! empty( $delay_autoupdate ) ) {
                                $websiteTransUpdateCheck[ $transSlug ] = time();
                            }
                            $transToUpdate[] = $item;
                        }
                    } else {
                        $item['trusted'] = 0;
                        if ( $newUpdate ) {
                            $item['new']                = 1;
                            $notTrustedTransNewUpdate[] = $item;
                        } else {
                            $item['new']               = 0;
                            $notTrustedTransToUpdate[] = $item;
                        }
                        if ( isset( $websiteTransUpdateCheck[ $transSlug ] ) ) {
                            unset( $websiteTransUpdateCheck[ $transSlug ] );
                        }
                    }
                }

                /**
                 * Action: mainwp_daily_digest_action
                 *
                 * Hooks the daily digest email notification send process.
                 *
                 * @param object $website    Object conaining child site info.
                 * @param bool   $plain_text Whether plain text email should be sent.
                 *
                 * @since 4.1
                 */
                do_action( 'mainwp_daily_digest_action', $website, $plain_text );
                MainWP_DB::instance()->update_website_sync_values( $website->id, array( 'dtsAutomaticSync' => $local_timestamp ) );
                MainWP_DB::instance()->update_website_option( $website, 'last_wp_upgrades', wp_json_encode( $websiteCoreUpgrades ) );
                MainWP_DB::instance()->update_website_option( $website, 'last_plugin_upgrades', $website->plugin_upgrades );
                MainWP_DB::instance()->update_website_option( $website, 'last_theme_upgrades', $website->theme_upgrades );
                MainWP_DB::instance()->update_website_option( $website, 'last_trans_upgrades', $website->translation_upgrades );

                if ( ! empty( $delay_autoupdate ) ) {
                    foreach ( $websitePluginsUpdateCheck as $slug => $check_time ) {
                        if ( ( time() > $check_time + 30 * DAY_IN_SECONDS ) && is_array( $websitePlugins ) && ! empty( $websitePlugins ) && ! isset( $websitePlugins[ $slug ] ) ) {
                            unset( $websitePluginsUpdateCheck[ $slug ] );
                        }
                    }

                    foreach ( $websiteThemesUpdateCheck as $slug => $check_time ) {
                        if ( ( time() > $check_time + 30 * DAY_IN_SECONDS ) && is_array( $websiteThemes ) && ! empty( $websiteThemes ) && ! isset( $websiteThemes[ $slug ] ) ) {
                            unset( $websiteThemesUpdateCheck[ $slug ] );
                        }
                    }

                    foreach ( $websiteTransUpdateCheck as $slug => $check_time ) {
                        if ( ( time() > $check_time + 30 * DAY_IN_SECONDS ) && is_array( $websiteTrans ) && ! empty( $websiteTrans ) && ! isset( $websiteTrans[ $slug ] ) ) {
                            unset( $websiteTransUpdateCheck[ $slug ] );
                        }
                    }

                    MainWP_DB::instance()->update_website_option( $website, 'core_update_check', $websiteCoreUpdateCheck );
                    MainWP_DB::instance()->update_website_option( $website, 'plugins_update_check', ( ! empty( $websitePluginsUpdateCheck ) ? wp_json_encode( $websitePluginsUpdateCheck ) : '' ) );
                    MainWP_DB::instance()->update_website_option( $website, 'themes_update_check', ( ! empty( $websiteThemesUpdateCheck ) ? wp_json_encode( $websiteThemesUpdateCheck ) : '' ) );
                    MainWP_DB::instance()->update_website_option( $website, 'trans_update_check', ( ! empty( $websiteTransUpdateCheck ) ? wp_json_encode( $websiteTransUpdateCheck ) : '' ) );

                } elseif ( ! empty( $websitePluginsUpdateCheck ) || ! empty( $websiteThemesUpdateCheck ) ) {
                    MainWP_DB::instance()->update_website_option( $website, 'core_update_check', 0 );
                    MainWP_DB::instance()->update_website_option( $website, 'plugins_update_check', '' );
                    MainWP_DB::instance()->update_website_option( $website, 'themes_update_check', '' );
                    MainWP_DB::instance()->update_website_option( $website, 'trans_update_check', '' );
                }

                if ( ! in_array( $website->id, $updatescheckSitesIcon ) ) {
                    MainWP_Sync::get_wp_icon( $website->id );
                    $updatescheckSitesIcon[] = $website->id;
                }

                if ( $check_individual_digest ) {
                    $individualDailyDigestWebsites[] = $website->id;
                }
            }

            MainWP_Utility::update_option( 'mainwp_updatescheck_sites_icon', $updatescheckSitesIcon );

            if ( ! empty( $individualDailyDigestWebsites ) ) {
                $individualDailyDigestWebsitesSaved = get_option( 'mainwp_updatescheck_individual_digest_websites' );
                if ( ! is_array( $individualDailyDigestWebsitesSaved ) ) {
                    $individualDailyDigestWebsitesSaved = array();
                }
                foreach ( $individualDailyDigestWebsites as $sid ) {
                    if ( ! in_array( $sid, $individualDailyDigestWebsitesSaved ) ) {
                        $individualDailyDigestWebsitesSaved[] = $sid;
                    }
                }
                MainWP_Utility::update_option( 'mainwp_updatescheck_individual_digest_websites', $individualDailyDigestWebsitesSaved );
            }

            if ( ! empty( $coreNewUpdate ) ) {
                $coreNewUpdateSaved = get_option( 'mainwp_updatescheck_mail_update_core_new' );
                MainWP_Utility::update_option( 'mainwp_updatescheck_mail_update_core_new', MainWP_Utility::array_merge( $coreNewUpdateSaved, $coreNewUpdate ) );
            }

            if ( ! empty( $pluginsNewUpdate ) ) {
                $pluginsNewUpdateSaved = get_option( 'mainwp_updatescheck_mail_update_plugins_new' );
                MainWP_Utility::update_option( 'mainwp_updatescheck_mail_update_plugins_new', MainWP_Utility::array_merge( $pluginsNewUpdateSaved, $pluginsNewUpdate ) );
            }

            if ( ! empty( $themesNewUpdate ) ) {
                $themesNewUpdateSaved = get_option( 'mainwp_updatescheck_mail_update_themes_new' );
                MainWP_Utility::update_option( 'mainwp_updatescheck_mail_update_themes_new', MainWP_Utility::array_merge( $themesNewUpdateSaved, $themesNewUpdate ) );
            }

            if ( ! empty( $coreToUpdate ) ) {
                $coreToUpdateSaved = get_option( 'mainwp_updatescheck_mail_update_core' );
                MainWP_Utility::update_option( 'mainwp_updatescheck_mail_update_core', MainWP_Utility::array_merge( $coreToUpdateSaved, $coreToUpdate ) );
            }

            if ( ! empty( $pluginsToUpdate ) ) {
                $pluginsToUpdateSaved = get_option( 'mainwp_updatescheck_mail_update_plugins' );
                MainWP_Utility::update_option( 'mainwp_updatescheck_mail_update_plugins', MainWP_Utility::array_merge( $pluginsToUpdateSaved, $pluginsToUpdate ) );
            }

            if ( ! empty( $themesToUpdate ) ) {
                $themesToUpdateSaved = get_option( 'mainwp_updatescheck_mail_update_themes' );
                MainWP_Utility::update_option( 'mainwp_updatescheck_mail_update_themes', MainWP_Utility::array_merge( $themesToUpdateSaved, $themesToUpdate ) );
            }

            if ( ! empty( $ignoredCoreToUpdate ) ) {
                $ignoredCoreToUpdateSaved = get_option( 'mainwp_updatescheck_mail_ignore_core' );
                MainWP_Utility::update_option( 'mainwp_updatescheck_mail_ignore_core', MainWP_Utility::array_merge( $ignoredCoreToUpdateSaved, $ignoredCoreToUpdate ) );
            }

            if ( ! empty( $ignoredCoreNewUpdate ) ) {
                $ignoredCoreNewUpdateSaved = get_option( 'mainwp_updatescheck_mail_ignore_core_new' );
                MainWP_Utility::update_option( 'mainwp_updatescheck_mail_ignore_core_new', MainWP_Utility::array_merge( $ignoredCoreNewUpdateSaved, $ignoredCoreNewUpdate ) );
            }

            if ( ! empty( $notTrustedPluginsToUpdate ) ) {
                $notTrustedPluginsToUpdateSaved = get_option( 'mainwp_updatescheck_mail_ignore_plugins' );
                MainWP_Utility::update_option( 'mainwp_updatescheck_mail_ignore_plugins', MainWP_Utility::array_merge( $notTrustedPluginsToUpdateSaved, $notTrustedPluginsToUpdate ) );
            }

            if ( ! empty( $notTrustedPluginsNewUpdate ) ) {
                $notTrustedPluginsNewUpdateSaved = get_option( 'mainwp_updatescheck_mail_ignore_plugins_new' );
                MainWP_Utility::update_option( 'mainwp_updatescheck_mail_ignore_plugins_new', MainWP_Utility::array_merge( $notTrustedPluginsNewUpdateSaved, $notTrustedPluginsNewUpdate ) );
            }

            if ( ! empty( $notTrustedThemesToUpdate ) ) {
                $notTrustedThemesToUpdateSaved = get_option( 'mainwp_updatescheck_mail_ignore_themes' );
                MainWP_Utility::update_option( 'mainwp_updatescheck_mail_ignore_themes', MainWP_Utility::array_merge( $notTrustedThemesToUpdateSaved, $notTrustedThemesToUpdate ) );
            }

            if ( ! empty( $notTrustedThemesNewUpdate ) ) {
                $notTrustedThemesNewUpdateSaved = get_option( 'mainwp_updatescheck_mail_ignore_themes_new' );
                MainWP_Utility::update_option( 'mainwp_updatescheck_mail_ignore_themes_new', MainWP_Utility::array_merge( $notTrustedThemesNewUpdateSaved, $notTrustedThemesNewUpdate ) );
            }
        }
    }

    /**
     * Method start_notification_daily_digest().
     *
     * Send email notification.
     *
     * @param array  $email_settings Email settings.
     * @param bool   $plain_text Text format value.
     * @param array  $sites_ids Array of websites ids (option).
     * @param object $email_site current report site.
     *
     * @return bool True|False
     *
     * @uses \MainWP\Dashboard\MainWP_Format::get_site_updates_items()
     * @uses \MainWP\Dashboard\MainWP_DB::get_disconnected_websites()
     * @uses \MainWP\Dashboard\MainWP_Logger::debug()
     * @uses \MainWP\Dashboard\MainWP_Notification
     */
    public function start_notification_daily_digest( $email_settings, $plain_text, $sites_ids = false, $email_site = false ) {

        $sendMail       = false;
        $updateAvaiable = false;
        $plugin_updates = array();
        $theme_updates  = array();

        $plugin_updates = MainWP_Format::get_site_updates_items( 'plugin', $sites_ids );
        if ( ! empty( $plugin_updates ) ) {
            $sendMail       = true;
            $updateAvaiable = true;
        }

        $theme_updates = MainWP_Format::get_site_updates_items( 'theme', $sites_ids );
        if ( ! empty( $theme_updates ) ) {
            $sendMail       = true;
            $updateAvaiable = true;
        }

        $wp_updates = MainWP_Format::get_site_updates_items( 'wpcore', $sites_ids );
        if ( ! empty( $wp_updates ) ) {
            $sendMail       = true;
            $updateAvaiable = true;
        }

        $sites_disconnected = MainWP_DB::instance()->get_disconnected_websites( $sites_ids );

        if ( ! empty( $sites_disconnected ) ) {
            $sendMail = true;
        }

        if ( ! $sendMail ) {
            MainWP_Logger::instance()->log_update_check( 'updates check :: sendMail is false' );
            return false;
        }

        $params = array(
            'plain_text' => $plain_text,
            'sites_ids'  => $sites_ids,
            'email_site' => $email_site,
        );

        return MainWP_Notification::send_daily_digest_notification( $email_settings, $updateAvaiable, $wp_updates, $plugin_updates, $theme_updates, $sites_disconnected, $params );
    }

    /**
     * Method refresh_saved_fields().
     *
     * Clear settings field values.
     *
     * @uses  \MainWP\Dashboard\MainWP_Utility::update_option()
     */
    public function refresh_saved_fields() {

        MainWP_Logger::instance()->log_update_check( 'updates check :: clear saved info' );

        $empty_fields = array(
            'mainwp_automaticUpdate_backupChecks',
            'mainwp_updatescheck_mail_update_core_new',
            'mainwp_updatescheck_mail_update_plugins_new',
            'mainwp_updatescheck_mail_update_themes_new',
            'mainwp_updatescheck_mail_update_core',
            'mainwp_updatescheck_mail_update_plugins',
            'mainwp_updatescheck_mail_update_themes',
            'mainwp_updatescheck_mail_ignore_core',
            'mainwp_updatescheck_mail_ignore_plugins',
            'mainwp_updatescheck_mail_ignore_themes',
            'mainwp_updatescheck_mail_ignore_core_new',
            'mainwp_updatescheck_mail_ignore_plugins_new',
            'mainwp_updatescheck_mail_ignore_themes_new',
            'mainwp_updatescheck_individual_digest_websites',
            'mainwp_updatescheck_sites_icon',
        );

        foreach ( $empty_fields as $field ) {
            MainWP_Utility::update_option( $field, '' );
        }
    }

    /**
     * Method start_notification_http_check().
     *
     * Prepare to send http check notification.
     *
     * @param bool $plain_text Text format value.
     *
     * @return bool True|False
     *
     * @uses \MainWP\Dashboard\MainWP_Notification::send_http_check_notification()
     * @uses \MainWP\Dashboard\MainWP_Notification_Settings::get_general_email_settings()
     * @uses \MainWP\Dashboard\MainWP_Notification_Settings::get_site_email_settings()
     * @uses \MainWP\Dashboard\MainWP_Notification_Settings::get_default_emails_fields()
     * @uses  \MainWP\Dashboard\MainWP_Utility::get_http_codes()
     */
    public function start_notification_http_check( $plain_text ) { // phpcs:ignore -- NOSONAR - complex.

        $sitesHttpCheck       = array();
        $email_settings_sites = array();

        $sites_offline = MainWP_DB::instance()->get_websites_http_check_status();
        if ( is_array( $sites_offline ) && ! empty( $sites_offline ) ) {
            foreach ( $sites_offline as $site ) {
                if ( 200 === (int) $site->http_response_code ) { // to fix: ignored 200 http code.
                    continue;
                }
                $email_settings_sites[ $site->id ] = $site->settings_notification_emails; // ok.
                $code                              = $site->http_response_code;
                $code_string                       = MainWP_Utility::get_http_codes( $code );
                if ( ! empty( $code_string ) ) {
                    $code .= ' - ' . $code_string;
                }

                $sitesHttpCheck[] = array(
                    'id'   => $site->id,
                    'name' => $site->name,
                    'url'  => $site->url,
                    'code' => $code,
                );
            }
        }

        if ( empty( $sitesHttpCheck ) ) {
            return false;
        }

        $gen_settings = MainWP_Notification_Settings::get_general_email_settings( 'http_check' );
        // general http check notificaion, to administrator.
        if ( ! $gen_settings['disable'] ) {
            MainWP_Notification::send_http_check_notification( $gen_settings, $sitesHttpCheck, $plain_text );
            usleep( 100000 );
        }

        $to_admin_HttpCheckWebsites = array();
        // individual http check notification.
        foreach ( $sitesHttpCheck as $site ) {

            $website       = new \stdClass();
            $website->id   = $site['id'];
            $website->url  = $site['url'];
            $website->name = $site['name'];

            $website->settings_notification_emails = $email_settings_sites[ $site['id'] ]; // ok.

            $settings = MainWP_Notification_Settings::get_site_email_settings( 'http_check', $website ); // get site email settings.

            if ( ! $settings['disable'] ) {
                $to_admin_HttpCheckWebsites[] = $site;

                $sent = MainWP_Notification::send_http_check_notification( $settings, array( $site ), $plain_text, false );
                if ( $sent ) {
                    usleep( 100000 );
                }
            }
        }

        // send all individual notice to admin in one email.
        if ( ! empty( $to_admin_HttpCheckWebsites ) ) {
            $admin_email_settings               = MainWP_Notification_Settings::get_default_emails_fields( 'http_check', '', true ); // get default subject and heading only.
            $admin_email_settings['disable']    = 0;
            $admin_email_settings['recipients'] = ''; // sent to admin only.
            MainWP_Notification::send_http_check_notification( $admin_email_settings, $sitesHttpCheck, $plain_text );
        }

        return true;
    }

    /**
     * Method cron_deactivated_licenses_alert()
     */
    public function cron_deactivated_licenses_alert() { // phpcs:ignore -- NOSONAR - complex.
        $admin_email_settings = MainWP_Notification_Settings::get_default_emails_fields( 'deactivated_license_alert' );
        if ( empty( $admin_email_settings['disable'] ) && ! empty( $admin_email_settings['recipients'] ) ) {
            $deactivated_licenses = MainWP_Extensions_Handler::get_indexed_extensions_infor( false, true );
            if ( ! empty( $deactivated_licenses ) ) {
                $alerts_now = array();
                foreach ( $deactivated_licenses as $slug => $info ) {
                    if ( ! empty( $info['mainwp_version'] ) ) { // alert for versions 5 only.
                        $alerted = MainWP_Utility::instance()->get_set_deactivated_licenses_alerted( $slug );
                        if ( empty( $alerted ) ) {
                            $alerts_now[ $slug ] = $info;
                            MainWP_Utility::instance()->get_set_deactivated_licenses_alerted( $slug, time(), 'set' );
                        }
                    }
                }

                if ( ! empty( $alerts_now ) ) {
                    $plain_text = get_option( 'mainwp_license_deactivated_alert_plain_text', false );
                    $filtered   = apply_filters( 'mainwp_license_deactivated_alert_plain_text', $plain_text );
                    if ( $plain_text !== $filtered ) {
                        $plain_text = $filtered;
                        MainWP_Utility::update_option( 'mainwp_license_deactivated_alert_plain_text', $plain_text );
                    }
                    MainWP_Notification::send_license_deactivated_alert( $admin_email_settings, $alerts_now, $plain_text );
                    MainWP_Utility::update_option( 'mainwp_cron_license_deactivated_alert_lasttime', time() );
                }
            }
        }
    }

    /**
     * Method cron_ping_childs()
     *
     * Cron job to ping child sites.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::query()
     * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_DB::free_result()
     * @uses \MainWP\Dashboard\MainWP_Logger::info()
     * @uses  \MainWP\Dashboard\MainWP_Utility::update_option()
     * @uses  \MainWP\Dashboard\MainWP_Utility::ends_with()
     */
    public function cron_ping_childs() {
        MainWP_Logger::instance()->info( 'ping childs' );

        $lastPing = get_option( 'mainwp_cron_last_ping' );
        if ( false !== $lastPing && ( time() - $lastPing ) < ( 60 * 60 * 23 ) ) {
            return;
        }
        MainWP_Utility::update_option( 'mainwp_cron_last_ping', time() );

        $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites() );
        while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {
            try {
                $url = $website->siteurl;
                if ( ! MainWP_Utility::ends_with( $url, '/' ) ) {
                    $url .= '/';
                }

                wp_remote_get( $url . 'wp-cron.php' );
            } catch ( \Exception $e ) {
                // ok.
            }
        }
        MainWP_DB::free_result( $websites );
    }

    /**
     * Method cron_backups_continue()
     *
     * Execute remaining backup tasks.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::get_backup_tasks_to_complete()
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::get_backup_task_by_id()
     * @uses \MainWP\Dashboard\MainWP_Logger::info()
     * @uses \MainWP\Dashboard\MainWP_Logger::debug()
     * @uses \MainWP\Dashboard\MainWP_Manage_Backups_Handler::execute_backup_task()
     * @uses  \MainWP\Dashboard\MainWP_Utility::update_option()
     */
    public function cron_backups_continue() {

        if ( ! get_option( 'mainwp_enableLegacyBackupFeature' ) ) {
            return;
        }

        MainWP_Logger::instance()->info( 'backups continue' );

        ignore_user_abort( true );
        MainWP_System_Utility::set_time_limit( 0 );
        add_filter(
            'admin_memory_limit',
            function () {
                return '512M';
            }
        );

        MainWP_Utility::update_option( 'mainwp_cron_last_backups_continue', time() );

        $tasks = MainWP_DB_Backup::instance()->get_backup_tasks_to_complete();

        MainWP_Logger::instance()->debug( 'backups continue :: Found ' . count( $tasks ) . ' to continue.' );

        if ( empty( $tasks ) ) {
            return;
        }

        foreach ( $tasks as $task ) {
            MainWP_Logger::instance()->debug( 'backups continue ::    Task: ' . $task->name );
        }

        foreach ( $tasks as $task ) {
            $task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
            if ( $task->completed < $task->last_run ) {
                MainWP_Manage_Backups_Handler::execute_backup_task( $task, 5, false );
                break;
            }
        }
    }

    /**
     * Method cron_backups()
     *
     * Execute Backup Tasks.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::get_backup_tasks_todo_daily() - NOSONAR todo name.
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::get_backup_tasks_todo_weekly() - NOSONAR todo name.
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::get_backup_tasks_todo_monthly() - NOSONAR todo name.
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::get_backup_task_by_id()
     * @uses \MainWP\Dashboard\MainWP_Logger::info()
     * @uses \MainWP\Dashboard\MainWP_Logger::debug()
     * @uses \MainWP\Dashboard\MainWP_Manage_Backups::validate_backup_tasks()
     * @uses \MainWP\Dashboard\MainWP_Manage_Backups_Handler::execute_backup_task()
     * @uses  \MainWP\Dashboard\MainWP_Utility::update_option()
     */
    public function cron_backups() { // phpcs:ignore -- NOSONAR - complex.
        if ( ! get_option( 'mainwp_enableLegacyBackupFeature' ) ) {
            return;
        }

        MainWP_Logger::instance()->info( 'backups' );

        ignore_user_abort( true );
        MainWP_System_Utility::set_time_limit( 0 );
        add_filter(
            'admin_memory_limit',
            function () {
                return '512M';
            }
        );

        MainWP_Utility::update_option( 'mainwp_cron_last_backups', time() );

        $allTasks   = array();
        $dailyTasks = MainWP_DB_Backup::instance()->get_backup_tasks_todo_daily();
        if ( ! empty( $dailyTasks ) ) {
            $allTasks = $dailyTasks;
        }
        $weeklyTasks = MainWP_DB_Backup::instance()->get_backup_tasks_todo_weekly();
        if ( ! empty( $weeklyTasks ) ) {
            $allTasks = array_merge( $allTasks, $weeklyTasks );
        }
        $monthlyTasks = MainWP_DB_Backup::instance()->get_backup_tasks_todo_monthly();
        if ( ! empty( $monthlyTasks ) ) {
            $allTasks = array_merge( $allTasks, $monthlyTasks );
        }

        MainWP_Logger::instance()->debug( 'backups :: Found ' . count( $allTasks ) . ' to start.' );

        foreach ( $allTasks as $task ) {
            MainWP_Logger::instance()->debug( 'backups ::    Task: ' . $task->name );
        }

        foreach ( $allTasks as $task ) {
            $threshold = 0;
            if ( 'daily' === $task->schedule ) {
                $threshold = ( 60 * 60 * 24 );
            } elseif ( 'weekly' === $task->schedule ) {
                $threshold = ( 60 * 60 * 24 * 7 );
            } elseif ( 'monthly' === $task->schedule ) {
                $threshold = ( 60 * 60 * 24 * 30 );
            }
            $task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
            if ( ( time() - $task->last_run ) < $threshold ) {
                continue;
            }

            if ( ! MainWP_Manage_Backups_Handler::validate_backup_tasks( array( $task ) ) ) {
                $task = MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id );
            }

            $chunkedBackupTasks = get_option( 'mainwp_chunkedBackupTasks' );
            MainWP_Manage_Backups_Handler::execute_backup_task( $task, ( 0 !== $chunkedBackupTasks ? 5 : 0 ) );
        }
    }

    /**
     * Method cron_reconnect()
     *
     * Grab MainWP Cron Job Statistics.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::query()
     * @uses \MainWP\Dashboard\MainWP_DB::get_websites_stats_update_sql()
     * @uses \MainWP\Dashboard\MainWP_DB::update_website_stats()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_DB::free_result()
     * @uses \MainWP\Dashboard\MainWP_Logger::info()
     * @uses \MainWP\Dashboard\MainWP_Logger::info_for_website()
     * @uses \MainWP\Dashboard\MainWP_Logger::warning_for_website()
     * @uses \MainWP\Dashboard\MainWP_Manage_Sites_View::m_reconnect_site()
     * @uses  \MainWP\Dashboard\MainWP_Utility::update_option()
     */
    public function cron_reconnect() {
        MainWP_Logger::instance()->info( 'stats' );

        MainWP_Utility::update_option( 'mainwp_cron_last_stats', time() );

        $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_websites_stats_update_sql() );

        $start = time();
        while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
            if ( ( time() - $start ) > ( 60 * 60 * 2 ) ) {
                break;
            }

            MainWP_DB::instance()->update_website_stats( $website->id, time() );

            if ( property_exists( $website, 'sync_errors' ) && '' !== $website->sync_errors ) {
                MainWP_Logger::instance()->info_for_website( $website, 'reconnect', 'Trying to reconnect' );
                try {
                    if ( MainWP_Manage_Sites_View::m_reconnect_site( $website ) ) {
                        MainWP_Logger::instance()->info_for_website( $website, 'reconnect', 'Reconnected successfully' );
                    }
                } catch ( \Exception $e ) {
                    MainWP_Logger::instance()->warning_for_website( $website, 'reconnect', $e->getMessage() );
                }
            }
            sleep( 3 );
        }
        MainWP_DB::free_result( $websites );
    }

    /**
     * Method cron_check_websites_health()
     *
     * Cron job to check site health.
     *
     * @uses \MainWP\Dashboard\MainWP_Logger::info()
     * @uses  \MainWP\Dashboard\MainWP_Utility::update_option()
     */
    public function cron_check_websites_health() {

        // to disable if run custom cron.
        $disableChecking = get_option( 'mainwp_disableSitesHealthMonitoring', 1 );  // disabled by default.
        if ( $disableChecking ) {
            return;
        }

        MainWP_Logger::instance()->info( 'check sites health :: starting.' );
        $plain_text = get_option( 'mainwp_daily_digest_plain_text', false );
        $this->start_notification_sites_health( $plain_text );
        MainWP_Logger::instance()->info( 'check sites health :: finished.' );
        MainWP_Utility::update_option( 'mainwp_cron_checksiteshealth_last_timestamp', time() );
    }

    /**
     * Method start_notification_sites_health().
     *
     * Prepare sites health notification.
     *
     * @param bool $plain_text Text format value.
     *
     * @return bool True|False
     *
     * @uses \MainWP\Dashboard\MainWP_DB::get_websites_to_notice_health_threshold()
     * @uses \MainWP\Dashboard\MainWP_Logger::info()
     * @uses \MainWP\Dashboard\MainWP_Monitoring_Handler::notice_site_health_threshold()
     * @uses \MainWP\Dashboard\MainWP_Notification_Settings::get_general_email_settings()
     * @uses \MainWP\Dashboard\MainWP_Notification_Settings::get_site_email_settings()
     * @uses \MainWP\Dashboard\MainWP_Notification_Settings::get_default_emails_fields()
     */
    public function start_notification_sites_health( $plain_text ) {

        $globalThreshold = get_option( 'mainwp_sitehealthThreshold', 80 );
        $healthSites     = MainWP_DB::instance()->get_websites_to_notice_health_threshold( $globalThreshold );
        MainWP_Logger::instance()->info( 'check sites health :: notice site health :: found ' . ( $healthSites ? count( $healthSites ) : 0 ) );

        if ( empty( $healthSites ) ) {
            return false;
        }

        $email = MainWP_Notification_Settings::get_general_email();

        // general site health notifcation.
        $email_settings = MainWP_Notification_Settings::get_general_email_settings( 'site_health' );
        if ( ! $email_settings['disable'] ) {
            // send to general email settings, one site in one email.
            MainWP_Monitoring_Handler::notice_site_health_threshold( $email_settings, $healthSites, $email, $plain_text );
        }

        $to_admin_siteHealthWebsites = array();
        // individual uptime notification.
        foreach ( $healthSites as $site ) {
            $email_settings = MainWP_Notification_Settings::get_site_email_settings( 'site_health', $site );
            if ( $email_settings['disable'] ) {
                continue; // disabled notification for this site.
            }
            $to_admin_siteHealthWebsites[] = $site;
            // send to individual email settings, one site in one email.
            MainWP_Monitoring_Handler::notice_site_health_threshold( $email_settings, array( $site ), '', $plain_text, false ); // do not send to admin for individual sending.
        }

        if ( ! empty( $to_admin_siteHealthWebsites ) ) {
            $admin_email_settings               = MainWP_Notification_Settings::get_default_emails_fields( 'site_health', '', true ); // get default subject and heading only.
            $admin_email_settings['disable']    = 0;
            $admin_email_settings['recipients'] = ''; // sent to admin only.
            // send to admin, all individual sites in one email.
            MainWP_Monitoring_Handler::notice_site_health_threshold( $admin_email_settings, $to_admin_siteHealthWebsites, $email, $plain_text, true, true );
        }

        return true;
    }


    /**
     * Method cron_perform_general_process
     *
     * @return void
     */
    public function cron_perform_general_process() {
        $this->perform_sequence_process();
    }

    /**
     * Method perform_sequence_process
     *
     * @return void
     */
    public function perform_sequence_process() { //phpcs:ignore -- NOSONAR - complexity.

        $register_process = apply_filters( 'mainwp_register_regular_sequence_process', array() );

        $list_processes = get_option( 'mainwp_regular_sequence_process_saved', array() );

        if ( ! empty( $list_processes ) ) {
            $list_processes = json_decode( $list_processes, true );
            // fix incorrect data.
            $fix_keys = array_keys( $list_processes );
            if ( isset( $fix_keys[0] ) && 0 === (int) $fix_keys[0] && isset( $fix_keys[1] ) && 1 === (int) $fix_keys[1] ) {
                $list_processes = array();
                MainWP_Utility::update_option( 'mainwp_regular_sequence_process_saved', wp_json_encode( $list_processes ) );
            }
        }

        $register_process = is_array( $register_process ) ? $register_process : array();
        $list_processes   = is_array( $list_processes ) ? $list_processes : array();

        $reg_update = false;

        foreach ( $register_process as $name => $reg_process ) {

            if ( ! is_array( $reg_process ) || empty( $reg_process ) || ! is_string( $name ) || empty( $name ) ) {
                continue;
            }

            $valid_process = false;

            if ( ! isset( $list_processes[ $name ] ) ) {
                $valid_process = $reg_process;
            } elseif ( is_array( $list_processes[ $name ] ) ) {
                if ( $reg_process !== $list_processes[ $name ] ) {
                    $valid_process = $reg_process;
                }
            }

            if ( empty( $valid_process ) || empty( $this->is_process_callable( $valid_process ) ) ) {
                continue;
            }

            if ( is_callable( $valid_process ) ) {
                if ( ! isset( $valid_process['priority'] ) ) {
                    $valid_process['priority'] = max( array_column( $valid_process, 'priority' ) ) + 1;
                }
                $list_processes[ $name ] = $valid_process;
                $reg_update              = true;
            }
        }

        $updated_list = false;
        $valid_list   = array();
        if ( $reg_update && ! empty( $list_processes ) ) {
            foreach ( $list_processes as $name => $pro ) {
                if ( ! empty( $this->is_process_callable( $pro ) ) ) {
                    $valid_list[ $name ] = $pro;
                    $updated_list        = true;
                }
            }
        }

        if ( $updated_list ) {
            MainWP_Logger::instance()->log_events( 'regular-schedule', 'Update scheduled processes.' );
            MainWP_Utility::array_sort( $list_processes, 'priority' );
            MainWP_Utility::update_option( 'mainwp_regular_sequence_process_saved', wp_json_encode( $valid_list ) );
        }

        $managed_processes = array_values( $list_processes );

        $count = count( $managed_processes );

        $current_pid = (int) get_option( 'mainwp_regular_sequence_current_process_pid', 0 );

        if ( $current_pid > $count ) {
            $current_pid = 0;
        }

        $process = isset( $managed_processes[ $current_pid ] ) ? $managed_processes[ $current_pid ] : array();

        MainWP_Logger::instance()->log_events( 'regular-schedule', '[current_pid=' . $current_pid . ' :: [total=' . $count . '] :: [process=' . ( is_array($process) ? print_r($process, true ): '' ) . ']' ); //phpcs:ignore -- ok.

        $performed = false;
        while ( $current_pid <= $count ) {
            $callable = $this->is_process_callable( $process );
            if ( ! empty( $callable ) && is_callable( $callable ) ) {
                call_user_func( $callable );
                $performed = true;
                MainWP_Logger::instance()->log_events( 'regular-schedule', 'Run process: [process=' . ( is_array($process) ? print_r($process, true ): '' ) . ']' ); //phpcs:ignore -- ok.
            }
            if ( $performed ) {
                break;
            } else {
                ++$current_pid;
                $process = isset( $managed_processes[ $current_pid ] ) ? $managed_processes[ $current_pid ] : false;
            }
        }
    }

    /**
     * Method is_process_callable().
     *
     * @param  array $process The process.
     * @return mixed false|string|array callable.
     */
    public function is_process_callable( $process ) { //phpcs:ignore -- NOSONAR - complex.
        if ( is_array( $process ) && ! empty( $process['callback'] ) && is_array( $process['callback'] ) ) {
            $class_name  = ! empty( $process['callback'][0] ) ? $process['callback'][0] : '';
            $method_name = ! empty( $process['callback'][1] ) ? $process['callback'][1] : '';
            if ( ! empty( $class_name ) && ! empty( $method_name ) ) {
                if ( is_callable( $class_name . '::' . $method_name ) ) {
                    return $class_name . '::' . $method_name;
                } elseif ( class_exists( $class_name ) ) {
                    $call_inst = new $class_name();
                    $callback  = array( $call_inst, $method_name );
                    if ( is_callable( $callback ) ) {
                        return $callback;
                    }
                }
            } elseif ( ! empty( $method_name ) && is_callable( $method_name ) ) {
                return $method_name;
            }
        }
        return false;
    }
}
