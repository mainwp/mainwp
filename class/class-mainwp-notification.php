<?php
/**
 * MainWP Notification
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * MainWP Notification
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
	 * Method notify_user()
	 *
	 * To send user a notification.
	 *
	 * @param int    $userId User ID.
	 * @param string $subject Email Subject.
	 * @param string $content Email Content.
	 */
	public static function notify_user( $userId, $subject, $content ) {
		$content_type = 'content-type: text/html';
		self::send_wp_mail(
			MainWP_DB_Common::instance()->get_user_notification_email( $userId ),
			$subject,
			$content,
			$content_type
		);
	}


	/**
	 * Method send_http_response_notification().
	 *
	 * Send HTTP response email notification.
	 *
	 * @param bool $sitesHttpCheckIds Sites IDs.
	 * @param bool $text_format Text format.
	 *
	 * @return bool False if failed.
	 */
	public static function send_http_response_notification( $sitesHttpCheckIds, $text_format ) {

		if ( ! is_array( $sitesHttpCheckIds ) || empty( $sitesHttpCheckIds ) ) {
			return false;
		}

		if ( $text_format ) {
			$content_type = "Content-Type: text/plain; charset=\"utf-8\"\r\n";
		} else {
			$content_type = "Content-Type: text/html; charset=\"utf-8\"\r\n";
		}

		$mail_offline = '';
		$sitesOffline = MainWP_DB::instance()->get_websites_by_ids( $sitesHttpCheckIds );

		if ( is_array( $sitesOffline ) && count( $sitesOffline ) > 0 ) {
			foreach ( $sitesOffline as $site ) {
				if ( -1 == $site->offline_check_result ) {
					$mail_offline .= '<li>' . $site->name . ' - [' . $site->url . '] - [' . $site->http_response_code . ']</li>';
				}
			}
		}

		$email = get_option( 'mainwp_updatescheck_mail_email' );

		if ( ! empty( $email ) && '' != $mail_offline ) {
			MainWP_Logger::instance()->debug( 'CRON :: http check :: send mail to ' . $email );
			$mail_offline   = '<div>After running auto updates, following sites are not returning expected HTTP request response:</div>
							<div></div>
							<ul>
							' . $mail_offline . '
							</ul>
							<div></div>
							<div>Please visit your MainWP Dashboard as soon as possible and make sure that your sites are online. (<a href="' . site_url() . '">' . site_url() . '</a>)</div>';
			self::send_wp_mail(
				$email,
				$mail_title = 'MainWP - HTTP response check',
				MainWP_Format::format_email(
					$email,
					$mail_offline,
					$mail_title
				),
				$content_type
			);
		}
	}

	/**
	 * Method send_updates_notification().
	 *
	 * Sent available updates notification email.
	 *
	 * @param mixed $email Admin email.
	 * @param mixed $content Mail content.
	 * @param bool  $text_format Text format.
	 * @param bool  $updateAvaiable Update avaiable.
	 */
	public static function send_updates_notification( $email, $content, $text_format, $updateAvaiable ) {

		if ( $text_format ) {
			$content_type = "Content-Type: text/plain; charset=\"utf-8\"\r\n";
		} else {
			$content_type = "Content-Type: text/html; charset=\"utf-8\"\r\n";
		}

		if ( $text_format ) {
			$mail_content  = $updateAvaiable ? 'We noticed the following updates are available on your MainWP Dashboard. (' . site_url() . ')' . "\r\n" : '';
			$mail_content .= $content . "\r\n";
			$mail_content .= $updateAvaiable ? 'If your MainWP is configured to use Auto Updates these updates will be installed in the next 24 hours.' . "\r\n" : '';
		} else {
			$mail_content  = $updateAvaiable ? '<div>We noticed the following updates are available on your MainWP Dashboard. (<a href="' . site_url() . '">' . site_url() . '</a>)</div>' : '';
			$mail_content .= '<div></div>';
			$mail_content .= $content;
			$mail_content .= '<div> </div>';
			$mail_content .= $updateAvaiable ? '<div>If your MainWP is configured to use Auto Updates these updates will be installed in the next 24 hours.</div>' : '';
		}

		$mail_title = 'Available Updates';
		if ( ! $updateAvaiable ) {
			$mail_title = '';
		}

		self::send_wp_mail(
			$email,
			'Available Updates',
			MainWP_Format::format_email(
				$email,
				$mail_content,
				$mail_title,
				$text_format,
				$updateAvaiable
			),
			$content_type
		);
	}


	/**
	 * Method send_websites_status_notification().
	 *
	 * Send websites status email notification.
	 *
	 * @param string $email notification email.
	 * @param string $mail_content email content.
	 * @param bool   $text_format Text format.
	 */
	public static function send_websites_status_notification( $email, $mail_content, $text_format ) {

		if ( $text_format ) {
			$content_type = "Content-Type: text/plain; charset=\"utf-8\"\r\n";
		} else {
			$content_type = "Content-Type: text/html; charset=\"utf-8\"\r\n";
		}

		if ( ! empty( $email ) && '' != $mail_content ) {
			MainWP_Logger::instance()->debug( 'CRON :: websites status check :: send mail to ' . $email );
			if ( $text_format ) {
				$mail_content = "Following sites are offline:\r\n" .
							"\r\n" .
							$mail_content .
							"\r\n" .
							'Please visit your MainWP Dashboard as soon as possible and make sure that your sites are online.' . site_url() .
							"\r\n";
			} else {
				$mail_content = '<div>Following sites are offline:</div>
							<div></div>
							<ul>
							' . $mail_content . '
							</ul>
							<div></div>
                            <div>Please visit your MainWP Dashboard as soon as possible and make sure that your sites are online. (<a href="' . site_url() . '">' . site_url() . '</a>)</div>';
			}
			self::send_wp_mail(
				$email,
				$mail_title = 'MainWP - Offline Status Websites',
				MainWP_Format::format_email(
					$email,
					$mail_offline,
					$mail_title
				),
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
	 * @param string $mail_content email content.
	 * @param bool   $text_format Text format.
	 */
	public static function send_websites_health_status_notification( $email, $mail_content, $text_format ) {

		if ( $text_format ) {
			$content_type = "Content-Type: text/plain; charset=\"utf-8\"\r\n";
		} else {
			$content_type = "Content-Type: text/html; charset=\"utf-8\"\r\n";
		}

		if ( ! empty( $email ) && '' != $mail_content ) {
			MainWP_Logger::instance()->debug( 'CRON :: websites health status :: send mail to ' . $email );
			if ( $text_format ) {
				$mail_content = "Following sites are not good site health:\r\n" .
							"\r\n" .
							$mail_content .
							"\r\n" .
							'Please visit your MainWP Dashboard as soon as possible.' . site_url() .
							"\r\n";
			} else {
				$mail_content = '<div>Following sites are not good site health:</div>
							<div></div>
							<ul>
							' . $mail_content . '
							</ul>
							<div></div>
                            <div>Please visit your MainWP Dashboard as soon as possible. (<a href="' . site_url() . '">' . site_url() . '</a>)</div>';
			}
			self::send_wp_mail(
				$email,
				$mail_title = 'MainWP - Websites Health Status',
				MainWP_Format::format_email(
					$email,
					$mail_offline,
					$mail_title
				),
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
	 * @param string $mail_title email content.
	 * @param bool   $mail_content Text format.
	 * @param string $content_type email content.
	 */
	public static function send_wp_mail( $email, $mail_title, $mail_content, $content_type ) {
		wp_mail(
			$email,
			$mail_title,
			$mail_content,
			array(
				'From: "' . get_option( 'admin_email' ) . '" <' . get_option( 'admin_email' ) . '>',
				$content_type,
			)
		);
	}

}
