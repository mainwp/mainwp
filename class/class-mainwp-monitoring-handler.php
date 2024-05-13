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
class MainWP_Monitoring_Handler {

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
	public static function handle_settings_post() {
		if ( isset( $_POST['submit'] ) && isset( $_POST['wp_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'Settings' ) ) {
			if ( MainWP_System_Utility::is_admin() ) {
				MainWP_Utility::update_option( 'mainwp_disableSitesChecking', ( ! isset( $_POST['mainwp_disableSitesChecking'] ) ? 1 : 0 ) );
				$val = isset( $_POST['mainwp_frequency_sitesChecking'] ) ? intval( $_POST['mainwp_frequency_sitesChecking'] ) : 1440;
				MainWP_Utility::update_option( 'mainwp_frequencySitesChecking', $val );
				MainWP_Utility::update_option( 'mainwp_disableSitesHealthMonitoring', ( ! isset( $_POST['mainwp_disable_sitesHealthMonitoring'] ) ? 1 : 0 ) );
				$val = isset( $_POST['mainwp_site_healthThreshold'] ) ? intval( $_POST['mainwp_site_healthThreshold'] ) : 80;
				MainWP_Utility::update_option( 'mainwp_sitehealthThreshold', $val );
				return true;
			}
		}
		return false;
	}

	/**
	 * Check to purge monitoring records.
	 *
	 * @return bool True for cleaning.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB_Monitoring::purge_monitoring_records()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::update_option()
	 */
	public static function check_to_purge_records() {
		$last_purge_records = get_option( 'mainwp_cron_checksites_purge_records_last_timestamp', 0 );
		$twice_a_day        = 12 * HOUR_IN_SECONDS; // twice a day.
		if ( time() > $last_purge_records + $twice_a_day ) {
			MainWP_DB_Monitoring::instance()->purge_monitoring_records();
			MainWP_Utility::update_option( 'mainwp_cron_checksites_purge_records_last_timestamp', time() );
			return true;
		}
		return false;
	}

	/**
	 * Check a website HTTP header status.
	 *
	 * @param object $website Object containing the website info.
	 *
	 * @return array Check result.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Connect::check_website_status()
	 * @uses \MainWP\Dashboard\MainWP_Connect::check_ignored_http_code()
	 * @uses \MainWP\Dashboard\MainWP_DB_Monitoring::insert_website_status()
	 * @uses \MainWP\Dashboard\MainWP_DB::update_website_values()
	 */
	public static function handle_check_website( $website ) {

		$result = MainWP_Connect::check_website_status( $website );

		if ( ! is_array( $result ) ) {
			return false;
		}

		$new_code        = ( is_array( $result ) && isset( $result['httpCode'] ) ) ? (int) $result['httpCode'] : 0;
		$online_detected = MainWP_Connect::check_ignored_http_code( $new_code );
		$time            = time();
		// Computes duration before update website checking values.
		$duration    = self::get_duration_for_status( $website, $time );
		$new_noticed = self::get_http_noticed_status_value( $website, $new_code );

		// Save last status.
		MainWP_DB::instance()->update_website_values(
			$website->id,
			array(
				'offline_check_result' => $online_detected ? 1 : -1,
				'offline_checks_last'  => $time,
				'http_response_code'   => $new_code,
				'http_code_noticed'    => $new_noticed,
			)
		);

		// Save status.
		MainWP_DB_Monitoring::instance()->insert_website_status(
			array(
				'wpid'            => $website->id,
				'event_timestamp' => $time,
				'http_code'       => $new_code,
				'status'          => self::get_site_checking_status( $new_code ),
				'duration'        => $duration,
			)
		);

		return $result;
	}

	/**
	 * Get a new HTTP status notice.
	 *
	 * @param object $website  Object containing the website info.
	 * @param int    $new_code The new HTTP code value.
	 *
	 * @return int $noticed_value New HTTP status.
	 */
	private static function get_http_noticed_status_value( $website, $new_code ) {
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

		if ( 80 <= $old_value && 80 > $new_health ) {
			if ( 1 === (int) $noticed_value ) {
				$noticed_value = 0;
			}
		} elseif ( 80 > $old_value && 80 <= $new_health ) {
			if ( 1 === (int) $noticed_value ) {
				$noticed_value = 0;
			}
		}
		return $noticed_value;
	}

	/**
	 * Computes duration status value.
	 *
	 * @param object $website Object containing the website info.
	 * @param int    $time The current compution time.
	 *
	 * @return int Duration value.
	 */
	private static function get_duration_for_status( $website, $time ) {

		$use_indi_interval  = ( 0 < $website->status_check_interval ) ? true : false;
		$duration_site_last = 0;
		if ( 0 !== $website->offline_checks_last ) {
			$duration_site_last = $time - $website->offline_checks_last; // duration equal now (time()) minus last time checked.
		}

		if ( $use_indi_interval ) { // if use individual interval for this site.
			if ( 0 < $duration_site_last ) {
				$duration = $duration_site_last;
			} else {
				$duration = $website->status_check_interval * 60; // in seconds.
			}
		} elseif ( 0 < $duration_site_last ) { // use global interval for this site.
				$duration = $duration_site_last;
		} else {
			$freq_minutes = get_option( 'mainwp_frequencySitesChecking', 60 );
			$duration     = $freq_minutes * 60; // in seconds.

		}

		// to limit duration 24 hours.
		if ( 60 * 60 * 24 < $duration ) {
			$duration = 60 * 60 * 24;
		}

		return $duration;
	}

	/**
	 * Determin if site is up or down based on HTTP code.
	 *
	 * @param int $http_code HTTP code.
	 */
	public static function get_site_checking_status( $http_code ) {
		return ( empty( $http_code ) ) ? 0 : ( 200 === (int) $http_code ? 1 : 0 );
	}


	/**
	 * Check child site status via AJAX.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::end_session()
	 */
	public static function ajax_check_status_site() {
		$website = null;
		if ( isset( $_POST['wp_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$website = MainWP_DB::instance()->get_website_by_id( intval( $_POST['wp_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		if ( null === $website ) {
			die( wp_json_encode( array( 'error' => esc_html__( 'Site ID not found. Please reload the page and try again.', 'mainwp' ) ) ) );
		}

		MainWP_Utility::end_session();
		$result = self::handle_check_website( $website );
		MainWP_Utility::end_session();

		if ( is_array( $result ) ) {
			die( wp_json_encode( array( 'result' => 'success' ) ) );
		} else {
			die( wp_json_encode( array( 'error' => esc_html__( 'Request failed. Please, try again.', 'mainwp' ) ) ) );
		}
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
				MainWP_Notification::send_websites_uptime_monitoring( $admin_email, $subject, $mail_content, $plain_text );
				usleep( 100000 );
			}
			do_action( 'mainwp_after_notice_sites_uptime_monitoring_admin', $websites );
			return;
		}

		$email = '';
		// for individual notification, one site loop.
		foreach ( $websites as $site ) {
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
	public static function notice_site_health_threshold( $email_settings, $websites, $email, $plain_text, $general = true, $to_admin = false ) {

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

		$email = $general ? $admin_email : '';

		foreach ( $websites as $site ) {

			if ( ! $general ) {
				$addition_emails = $site->monitoring_notification_emails;
				if ( ! empty( $addition_emails ) ) {
					$email .= ',' . $addition_emails; // send to addition emails too.
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
				$email .= ',' . $email_settings['recipients']; // send to recipients.
			}

			$email = trim( $email, ',' );

			if ( ! empty( $email ) && ! empty( $mail_content ) ) {
				MainWP_Notification::send_websites_health_status_notification( $email, $subject, $mail_content, $plain_text );
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
