<?php
/**
 * MainWP Monotoring Sites Handler.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Monitoring_Handler
 *
 * @package MainWP\Dashboard
 *
 * This class handles the $_POST of Settings Options.
 */
class MainWP_Monitoring_Handler { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.


    /** Cache for uptime status to avoid multiple queries during sites table rendering.
     *
     * @var array|null
     */
    private static $uptime_cache = null;

    /**
     * Manage the settings post.
     *
     * @uses MainWP_Utility::update_option()
     *
     * @return bool True on success, false on failure.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Utility::is_admin()
     * @uses  \MainWP\Dashboard\MainWP_Utility::update_option()
     */
    public static function handle_settings_post() { // phpcs:ignore -- NOSONAR - complex.
        // Check if form was submitted and nonce is present.
        if ( ! isset( $_POST['submit'] ) || ! isset( $_POST['wp_nonce'] ) ) {
            return false;
        }

        // Verify nonce and admin permissions.
        $nonce          = sanitize_key( $_POST['wp_nonce'] );
        $is_valid_nonce = wp_verify_nonce( $nonce, 'MonitoringSettings' );

        if ( ! $is_valid_nonce || ! MainWP_System_Utility::is_admin() ) {
            return false;
        }

        // Save global uptime monitoring settings.
        MainWP_Uptime_Monitoring_Edit::instance()->handle_save_settings();

        // Save Site Health Monitoring setting.
        $disable_health = isset( $_POST['mainwp_disable_sitesHealthMonitoring'] ) ? 0 : 1;
        MainWP_Utility::update_option( 'mainwp_disableSitesHealthMonitoring', $disable_health );

        // Save Site Health Threshold with validation.
        $threshold = isset( $_POST['mainwp_site_healthThreshold'] ) ? intval( $_POST['mainwp_site_healthThreshold'] ) : 80;

        // Validate threshold value - only allow 80 or 100.
        $allowed_thresholds = array( 80, 100 );
        if ( ! in_array( $threshold, $allowed_thresholds, true ) ) {
            $threshold = 80; // Default to 80 if invalid value.
        }

        MainWP_Utility::update_option( 'mainwp_sitehealthThreshold', $threshold );

        /**
         * Action: mainwp_after_save_monitoring_settings
         *
         * Fires after monitoring settings are saved.
         *
         * @since 4.1
         */
        do_action( 'mainwp_after_save_monitoring_settings', $_POST );

        return true;
    }


    /**
     * Check a website HTTP header status.
     *
     * @param object $website Object containing the website info.
     * @param bool   $chk_http_site Check site http response.
     *
     * @return mixed Check result.
     *
     * @uses \MainWP\Dashboard\MainWP_Connect::check_website_status()
     * @uses \MainWP\Dashboard\MainWP_Connect::check_ignored_http_code()
     * @uses \MainWP\Dashboard\MainWP_DB::update_website_values()
     */
    public static function handle_check_website( $website, $chk_http_site = false ) {

        $result_comp = MainWP_Connect::check_website_status( $website, $chk_http_site );

        if ( ! is_array( $result_comp ) ) {
            return false;
        }

        $new_code = ( is_array( $result_comp ) && isset( $result_comp['httpCode'] ) ) ? (int) $result_comp['httpCode'] : 0;

        if ( isset( $result_comp['new_uptime_status'] ) ) {
            $new_status       = (int) $result_comp['new_uptime_status'];
            $new_check_result = 0; // pending.
            if ( MainWP_Uptime_Monitoring_Connect::UP === $new_status ) {
                $new_check_result = 1; // 1 - online, -1 offline.
            } elseif ( MainWP_Uptime_Monitoring_Connect::DOWN === $new_status ) {
                $new_check_result = -1; // 1 - online, -1 offline.
            }
        } else {
            $new_status       = MainWP_Connect::check_ignored_http_code( $new_code, $website ); // legacy check http code.
            $new_check_result = $new_status ? 1 : -1; // 1 - online, -1 offline.
        }

        $time    = isset( $result_comp['check_offline_time'] ) ? $result_comp['check_offline_time'] : time();
        $noticed = static::check_http_status_notification_threshold( $website, $new_check_result );
        // Save last status.
        MainWP_DB::instance()->update_website_values(
            $website->id,
            array(
                'offline_check_result' => $new_check_result,
                'offline_checks_last'  => $time,
                'http_response_code'   => $new_code,
                'http_code_noticed'    => $noticed,
            )
        );

        MainWP_Logger::instance()->log_uptime_check( 'Check website status :: [website=' . (string) $website->url . '] :: [offline_check_result=' . intval( $new_check_result ) . '] :: [http_response_code=' . esc_html( $new_code ) . '] :: [http_code_noticed=' . esc_html( $noticed ) . ']' );

        return $result_comp; // return results for ajax check requests.
    }


    /**
     * Get a new HTTP status notice.
     *
     * @param object $website  Object containing the website info.
     * @param int    $check_result The new HTTP code value.
     *
     * @return int $noticed Noticed value.
     */
    public static function check_http_status_notification_threshold( $website, $check_result ) {
        $threshold = HOUR_IN_SECONDS;
        $noticed   = 1; // default is noticed.
        if ( property_exists( $website, 'offline_checks_last' ) ) {
            $last_noticed = MainWP_DB::instance()->get_website_option( $website, 'http_status_notice_check_time', 0 );
            if ( -1 === $check_result ) {
                $noticed = 0;
                if ( $last_noticed > time() - $threshold ) {
                    $noticed = 1;
                }
            }
        }
        return $noticed;
    }


    /**
     * Get a new HTTP status notice.
     *
     * @compatible.
     *
     * @return int $noticed_value New HTTP status.
     */
    public static function get_http_noticed_status_value() {
        return 1;
    }

    /**
     * Get new site health value.
     *
     * @param object $website    Object containing the website info.
     * @param object $new_health New site health value.
     *
     * @return int $noticed_value new site health status.
     */
    public static function get_health_noticed_status_value( $website, $new_health ) {

        // for sure if property does not existed.
        if ( ! property_exists( $website, 'health_value' ) || ! property_exists( $website, 'health_site_noticed' ) ) {
            return null;
        }

        $old_value     = $website->health_value;
        $noticed_value = $website->health_site_noticed;

        if ( ( ( 80 <= $old_value && 80 > $new_health ) || ( 80 > $old_value && 80 <= $new_health ) ) && 1 === (int) $noticed_value ) {
            $noticed_value = 0;
        }
        return $noticed_value;
    }

    /**
     * Determin if site is up or down based on HTTP code.
     *
     * @param int $http_code HTTP code.
     */
    public static function get_site_checking_status( $http_code ) {
        $code200 = 200 === (int) $http_code ? 1 : 0;
        return empty( $http_code ) ? 0 : $code200;
    }

    /**
     * Basic site uptime monitoring.
     *
     * @param array  $websites       Array containing the websites.
     * @param string $admin_email    Notification email.
     * @param string $email_settings Email settings.
     * @param bool   $plain_text     Determines if the plain text format should be used.
     * @param bool   $to_admin Send to admin or not.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::update_website_values()
     * @uses \MainWP\Dashboard\MainWP_Notification::send_websites_uptime_monitoring()
     * @uses \MainWP\Dashboard\MainWP_Notification_Template::get_template_html()
     */
    public static function notice_sites_uptime_monitoring( $websites, $admin_email, $email_settings, $plain_text, $to_admin = false ) {

        $heading = $email_settings['heading'];
        $subject = $email_settings['subject'];

        if ( $to_admin && ! empty( $admin_email ) ) {
            $mail_content = MainWP_Notification_Template::instance()->get_template_html(
                'emails/mainwp-uptime-monitoring-email.php',
                array(
                    'sites'   => $websites,
                    'heading' => $heading,
                )
            );
            if ( ! empty( $mail_content ) ) {
                MainWP_Logger::instance()->log_uptime_notice( 'Uptime notification is being sent for admin.' );
                MainWP_Notification::send_websites_uptime_monitoring( $admin_email, $subject, $mail_content, $plain_text );
                usleep( 100000 );
            }
            do_action( 'mainwp_after_notice_sites_uptime_monitoring_admin', $websites );
            return;
        }

        // Send individual notifications by iterating through each site.
        foreach ( $websites as $site ) {
            $email           = '';
            $addition_emails = $site->monitoring_notification_emails;
            if ( ! empty( $addition_emails ) ) {
                $email .= ',' . $addition_emails; // send to addition emails too.
            }

            $mail_content = MainWP_Notification_Template::instance()->get_template_html(
                'emails/mainwp-uptime-monitoring-email.php',
                array(
                    'current_email_site' => $site, // support tokens process.
                    'heading'            => $heading,
                )
            );

            if ( ! empty( $email_settings['recipients'] ) ) {
                $email .= ',' . $email_settings['recipients']; // send to recipients.
            }

            $email = trim( $email, ',' );

            if ( ! empty( $mail_content ) ) {
                MainWP_Logger::instance()->log_uptime_notice( 'Uptime notification is being sent for individual site.' );
                MainWP_Notification::send_websites_uptime_monitoring( $email, $subject, $mail_content, $plain_text, $site );
                usleep( 100000 );
            }
            do_action( 'mainwp_after_notice_sites_uptime_monitoring_individual', $site );
        }
    }

    /**
     * Site health monitoring.
     *
     * @param string $email_settings Email settings.
     * @param array  $websites       Array containing the websites.
     * @param string $email          Notification email.
     * @param bool   $plain_text     Determines if the plain text format should be used.
     * @param bool   $general        Determines if it's a general notification.
     * @param bool   $to_admin Send to admin or not.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Common::get_user_notification_email()
     * @uses \MainWP\Dashboard\MainWP_DB::update_website_sync_values()
     * @uses \MainWP\Dashboard\MainWP_Notification::send_websites_health_status_notification()
     * @uses \MainWP\Dashboard\MainWP_Notification_Template::get_template_html()
     */
    public static function notice_site_health_threshold( $email_settings, $websites, $email, $plain_text, $general = true, $to_admin = false ) { // phpcs:ignore -- NOSONAR - complex.

        $to_email = $email;

        $admin_email = MainWP_Notification_Settings::get_general_email();

        $heading = $email_settings['heading'];
        $subject = $email_settings['subject'];

        if ( $to_admin && ! empty( $admin_email ) ) {
            $mail_content = MainWP_Notification_Template::instance()->get_template_html(
                'emails/mainwp-site-health-monitoring-email.php',
                array(
                    'sites'   => $websites,
                    'heading' => $heading,
                )
            );
            if ( ! empty( $mail_content ) ) {
                $subject = $email_settings['subject'];
                MainWP_Notification::send_websites_health_status_notification( $admin_email, $subject, $mail_content, $plain_text );
                usleep( 100000 );
            }
            return;
        }

        $to_email = $general ? $admin_email : $to_email;

        foreach ( $websites as $site ) {
            $email_site_settings = $email_settings;
            MainWP_Notification_Settings::prepare_general_email_settings_for_site( $email_site_settings, $site );
            $heading = $email_site_settings['heading'];
            $subject = $email_site_settings['subject'];

            if ( ! $general ) {
                $addition_emails = $site->monitoring_notification_emails;
                if ( ! empty( $addition_emails ) ) {
                    $to_email .= ',' . $addition_emails; // send to addition emails too.
                }
            }

            $mail_content = MainWP_Notification_Template::instance()->get_template_html(
                'emails/mainwp-site-health-monitoring-email.php',
                array(
                    'current_email_site' => $site,  // support tokens process.
                    'heading'            => $heading,
                )
            );

            if ( ! empty( $email_settings['recipients'] ) ) {
                $to_email .= ',' . $email_settings['recipients']; // send to recipients.
            }

            $to_email = trim( $to_email, ',' );

            if ( ! empty( $to_email ) && ! empty( $mail_content ) ) {
                MainWP_Notification::send_websites_health_status_notification( $to_email, $subject, $mail_content, $plain_text );
                // update noticed value.
                MainWP_DB::instance()->update_website_sync_values(
                    $site->id,
                    array(
                        'health_site_noticed' => 1, // as noticed.
                    )
                );
                usleep( 100000 );
            }
        }
    }

    /**
     * Preload uptime data for all websites.
     * Instead of querying monitor_stat_hourly per site (19 queries), do one batch query.
     *
     * @param array $websites Array of websites to preload data for.
     *
     * @return void
     */
    public static function preload_uptime_data( $websites ) { //phpcs:ignore -- NOSONAR -- complex func.

        if ( null !== self::$uptime_cache ) {
            return;
        }

        self::$uptime_cache = array();

        // Collect monitor_ids from websites.
        $monitor_ids = array();

        if ( MainWP_DB::is_result( $websites ) ) {
            MainWP_DB::data_seek( $websites, 0 );
            while ( $websites && ( $site = MainWP_DB::fetch_array( $websites ) ) ) {
                $mid = isset( $site['monitor_id'] ) ? (int) $site['monitor_id'] : 0;
                if ( $mid > 0 ) {
                    $monitor_ids[ $mid ] = true;
                }
            }
            MainWP_DB::data_seek( $websites, 0 );
        }

        $int_monitor_ids = array_map( 'intval', array_keys( $monitor_ids ) );

        if ( empty( $int_monitor_ids ) ) {
            return;
        }

        $last24_time = MainWP_Uptime_Monitoring_Handle::get_hourly_key_by_timestamp( time() - DAY_IN_SECONDS );

        // Single batch query for all monitors.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
        $results = MainWP_DB_Uptime_Monitoring::instance()->get_batch_stat_hourly( $int_monitor_ids, $last24_time );

        if ( is_array( $results ) ) {
            // Group by monitor_id and compute percentage.
            $grouped = array();
            foreach ( $results as $r ) {
                $mid = (int) $r['monitor_id'];
                if ( ! isset( $grouped[ $mid ] ) ) {
                    $grouped[ $mid ] = array();
                }
                $grouped[ $mid ][] = $r;
            }

            foreach ( $grouped as $mid => $uptime_status ) {
                ob_start();
                MainWP_Monitoring_Sites_List_Table::instance()->render_last24_uptime_status( $uptime_status, $last24_time );
                $html                       = ob_get_clean();
                self::$uptime_cache[ $mid ] = $html;
            }
        }
    }

    /**
     * Method intercept_column()
     *
     * Intercept column display in the sites table.
     *
     * @param string $content Column content.
     * @param string $column_name Column name.
     * @param array  $website Website data.
     *
     * @return string $content Modified column content.
     */
    public static function intercept_column( $content, $column_name, $website ) {
        // Uptime: use preloaded batch data instead of per-row query.
        if ( 'uptime' === $column_name ) {
            $mid = isset( $website['monitor_id'] ) ? (int) $website['monitor_id'] : 0;
            if ( $mid > 0 && isset( self::$uptime_cache[ $mid ] ) ) {
                return self::$uptime_cache[ $mid ];
            }
            if ( $mid <= 0 ) {
                return '<span class="ui small text">N/A</span>';
            }
        }
        return $content;
    }
}
