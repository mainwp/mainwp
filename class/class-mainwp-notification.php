<?php
/**
 * MainWP Notification
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Notification
 *
 * @package MainWP\Dashboard
 */
class MainWP_Notification {

	/**
	 * Method get_class_name()
	 *
	 * Get Class Name.
	 *
	 * @return object
	 */
	public static function get_class_name() {
		return __CLASS__;
	}


	/**
	 * Method send_notify_user()
	 *
	 * To send user a notification.
	 *
	 * @param int    $userId User ID.
	 * @param string $subject Email Subject.
	 * @param string $content Email Content.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::get_user_notification_email()
	 */
	public static function send_notify_user( $userId, $subject, $content ) {
		$content_type = 'content-type: text/html';
		self::send_wp_mail(
			MainWP_DB_Common::instance()->get_user_notification_email( $userId ),
			$subject,
			$content,
			$content_type
		);
	}


	/**
	 * Method send_http_check_notification().
	 *
	 * Send HTTP response email notification.
	 *
	 * @param mixed $email_settings Email settings.
	 * @param array $sites_status Websites http status.
	 * @param bool  $plain_text Text format.
	 * @param bool  $general Either general or individual notification.
	 *
	 * @return bool False if failed.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Logger::debug()
	 * @uses \MainWP\Dashboard\MainWP_Notification_Template::get_template_html()
	 */
	public static function send_http_check_notification( $email_settings, $sites_status, $plain_text, $general = true ) {

		if ( $plain_text ) {
			$content_type = "Content-Type: text/plain; charset=\"utf-8\"\r\n";
		} else {
			$content_type = "Content-Type: text/html; charset=\"utf-8\"\r\n";
		}

		$heading = $email_settings['heading'];
		$subject = $email_settings['subject'];

		$formated_content = MainWP_Notification_Template::instance()->get_template_html(
			'emails/mainwp-after-update-http-check-email.php',
			array(
				'sites_statuses' => $sites_status,
				'heading'        => $heading,
			)
		);

		$email = '';

		// do not send individual data to admin.
		if ( $general ) {
			$email = get_option( 'mainwp_updatescheck_mail_email' );
		}

		if ( ! empty( $email_settings['recipients'] ) ) {
			$email .= ',' . $email_settings['recipients']; // send to recipients.
		}

		if ( ! empty( $email ) ) {
			MainWP_Logger::instance()->debug( 'CRON :: http check :: send mail ::' );
			self::send_wp_mail(
				$email,
				$subject,
				$formated_content,
				$content_type
			);
			return true;
		}

		return false;
	}

	/**
	 * Method send_daily_digest_notification().
	 *
	 * Sent available updates notification email.
	 *
	 * @param array $email_settings     Email settings.
	 * @param bool  $available_updates  Update avaiable.
	 * @param mixed $wp_updates         WP updates.
	 * @param mixed $plugin_updates     Plugins updates.
	 * @param mixed $theme_updates      Themes updates.
	 * @param mixed $sites_disconnected Sites disconnected.
	 * @param bool  $plain_text         Text format.
	 * @param bool  $sites_ids          Websites ids - default false (option).
	 * @param bool  $to_admin           Send to admin or not - default false (option).
	 * @param bool  $email_site         current report site.
	 *
	 * @return bool
	 *
	 * @uses \MainWP\Dashboard\MainWP_Logger::debug()
	 * @uses \MainWP\Dashboard\MainWP_Notification_Template::get_template_html()
	 */
	public static function send_daily_digest_notification( $email_settings, $available_updates, $wp_updates, $plugin_updates, $theme_updates, $sites_disconnected, $plain_text, $sites_ids = false, $to_admin = false, $email_site = false ) {

		if ( $email_settings['disable'] ) {
			return false; // disabled send daily digest notification.
		}

		$email = '';

		if ( empty( $sites_ids ) || $to_admin ) {
			$email = get_option( 'mainwp_updatescheck_mail_email' ); // general notification, do not send individual notification to admin.
		}

		if ( ! empty( $email_settings['recipients'] ) ) {
			$email .= ',' . $email_settings['recipients']; // send to recipients.
		}

		if ( empty( $email ) ) {
			return false;
		}

		if ( $plain_text ) {
			$content_type = "Content-Type: text/plain; charset=\"utf-8\"\r\n";
		} else {
			$content_type = "Content-Type: text/html; charset=\"utf-8\"\r\n";
		}

		/**
		 * Filter: mainwp_daily_digest_content
		 *
		 * Filters the Daily Digest email content and adds support for enabling text/plain emails.
		 *
		 * @param array $sites_ids Array of sites IDs.
		 * @param bool  $plain_text Wether plain text mode is enabled.
		 *
		 * @since 4.1
		 */
		$other_digest = apply_filters( 'mainwp_daily_digest_content', false, $sites_ids, $plain_text );

		$heading = $email_settings['heading'];

		$formated_content = MainWP_Notification_Template::instance()->get_template_html(
			'emails/mainwp-daily-digest-email.php',
			array(
				'available_updates'  => $available_updates,
				'wp_updates'         => $wp_updates,
				'plugin_updates'     => $plugin_updates,
				'theme_updates'      => $theme_updates,
				'sites_disconnected' => $sites_disconnected,
				'other_digest'       => $other_digest,
				'heading'            => $heading,
				'current_email_site' => $email_site, // support tokens process.
			)
		);

		MainWP_Logger::instance()->debug( 'CRON :: updates check - daily digest :: send mail ::' );
		$subject = $email_settings['subject'];
		self::send_wp_mail(
			$email,
			$subject,
			$formated_content,
			$content_type
		);
		return true;
	}


	/**
	 * Method send_websites_uptime_monitoring().
	 *
	 * Send websites status email notification.
	 *
	 * @param string $emails notification emails.
	 * @param string $subject email subject.
	 * @param string $mail_content email content.
	 * @param bool   $plain_text Text format.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Logger::debug()
	 */
	public static function send_websites_uptime_monitoring( $emails, $subject, $mail_content, $plain_text ) {

		if ( $plain_text ) {
			$content_type = "Content-Type: text/plain; charset=\"utf-8\"\r\n";
		} else {
			$content_type = "Content-Type: text/html; charset=\"utf-8\"\r\n";
		}

		if ( ! empty( $emails ) && '' != $mail_content ) {
			MainWP_Logger::instance()->debug( 'CRON :: sites status :: send mail ::' );
			self::send_wp_mail(
				$emails,
				$subject,
				$mail_content,
				$content_type
			);
		}
	}

	/**
	 * Method send_websites_health_status_notification().
	 *
	 * Send websites status email notification.
	 *
	 * @param string $email notification email.
	 * @param string $subject subject.
	 * @param string $mail_content email content.
	 * @param bool   $plain_text Text format.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Logger::debug()
	 */
	public static function send_websites_health_status_notification( $email, $subject, $mail_content, $plain_text ) {

		if ( $plain_text ) {
			$content_type = "Content-Type: text/plain; charset=\"utf-8\"\r\n";
		} else {
			$content_type = "Content-Type: text/html; charset=\"utf-8\"\r\n";
		}

		if ( ! empty( $email ) && '' != $mail_content ) {
			MainWP_Logger::instance()->debug( 'CRON :: sites health :: send mail ::' );
			self::send_wp_mail(
				$email,
				$subject,
				$mail_content,
				$content_type
			);
		}
	}


	/**
	 * Method send_wp_mail().
	 *
	 * Send email via wp_mail().
	 *
	 * @param string $email send to email.
	 * @param string $subject email content.
	 * @param bool   $mail_content Text format.
	 * @param string $content_type email content.
	 */
	public static function send_wp_mail( $email, $subject, $mail_content, $content_type ) {
		wp_mail(
			$email,
			$subject,
			$mail_content,
			array(
				'From: "' . get_option( 'admin_email' ) . '" <' . get_option( 'admin_email' ) . '>',
				$content_type,
			)
		);
	}

}
