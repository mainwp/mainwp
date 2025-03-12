<?php
/**
 * MainWP Monotoring Sites Handler.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Monitoring_Handler
 *
 * @package MainWP\Dashboard
 *
 * This class handles the $_POST of Settings Options.
 */
class MainWP_Monitoring_Handler { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

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
        if ( isset( $_POST['submit'] ) && isset( $_POST['wp_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'Settings' ) && MainWP_System_Utility::is_admin() ) {
            // global uptime monitoring settings.
            MainWP_Uptime_Monitoring_Edit::instance()->handle_save_settings();
            MainWP_Utility::update_option( 'mainwp_disableSitesHealthMonitoring', ( ! isset( $_POST['mainwp_disable_sitesHealthMonitoring'] ) ? 1 : 0 ) );
            $val = isset( $_POST['mainwp_site_healthThreshold'] ) ? intval( $_POST['mainwp_site_healthThreshold'] ) : 80;
            MainWP_Utility::update_option( 'mainwp_sitehealthThreshold', $val );
            return true;
        }
        return false;
    }


    /**
     * Check a website HTTP header status.
     *
     * @param object $website Object containing the website info.
     *
     * @return mixed Check result.
     *
     * @uses \MainWP\Dashboard\MainWP_Connect::check_website_status()
     * @uses \MainWP\Dashboard\MainWP_Connect::check_ignored_http_code()
     * @uses \MainWP\Dashboard\MainWP_DB::update_website_values()
     */
    public static function handle_check_website( $website ) {

        $result_comp = MainWP_Connect::check_website_status( $website );

        if ( ! is_array( $result_comp ) ) {
            return false;
        }

        $new_code = ( is_array( $result_comp ) && isset( $result_comp['httpCode'] ) ) ? (int) $result_comp['httpCode'] : 0;

        if ( isset( $result_comp['new_uptime_status'] ) ) {
            $is_online = $result_comp['new_uptime_status'];
        } else {
            $is_online = MainWP_Connect::check_ignored_http_code( $new_code ); // legacy check http code.
        }

        $importance = isset( $result_comp['importance'] ) ? $result_comp['importance'] : 0;

        $time = isset( $result_comp['check_offline_time'] ) ? $result_comp['check_offline_time'] : time();

        $noticed_value = $website->http_code_noticed;

        // it is noticed.
        if ( ! empty( $noticed_value ) ) {
            $new_noticed = empty( $is_online ) && $importance ? 0 : 1; // 0 => need to send notification.
        } else {
            $new_noticed = $noticed_value; // no change.
        }

        // Save last status.
        MainWP_DB::instance()->update_website_values(
            $website->id,
            array(
                'offline_check_result' => $is_online ? 1 : -1, // 1 - online, -1 offline.
                'offline_checks_last'  => $time,
                'http_response_code'   => $new_code,
                'http_code_noticed'    => $new_noticed, // http_code_noticed = 0, not noticed yet, ready to notice.
            )
        );

        return $result_comp; // return results for ajax check requests.
    }

    /**
     * Get a new HTTP status notice.
     *
     * @param object $website  Object containing the website info.
     * @param int    $new_code The new HTTP code value.
     *
     * @return int $noticed_value New HTTP status.
     */
    public static function get_http_noticed_status_value( $website, $new_code ) {
        $old_code      = (int) $website->http_response_code;
        $noticed_value = $website->http_code_noticed;
        if ( 200 !== $new_code && (int) $old_code !== $new_code ) {
            $noticed_value = 0;
        } elseif ( 200 !== $old_code && 200 === $new_code ) {
            if ( 0 === $noticed_value ) {
                $noticed_value = 1;
            }
        }
        return $noticed_value;
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
                MainWP_Notification::send_websites_uptime_monitoring( $email, $subject, $mail_content, $plain_text );
                // update noticed value.
                MainWP_DB::instance()->update_website_values(
                    $site->id,
                    array(
                        'http_code_noticed' => 1, // noticed.
                    )
                );
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
}
