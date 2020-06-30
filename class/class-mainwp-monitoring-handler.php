<?php
/**
 * MainWP Monotoring Sites Handler.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * MainWP Monitoring Sites Handler.
 */
class MainWP_Monitoring_Handler {

	/**
	 * Method handle_settings_post().
	 *
	 * This class handles the $_POST of Settings Options.
	 *
	 * @uses MainWP_Utility::update_option()
	 *
	 * @return boolean True|False Posts On True.
	 */
	public static function handle_settings_post() {
		if ( isset( $_POST['submit'] ) && wp_verify_nonce( $_POST['wp_nonce'], 'Settings' ) ) {
			if ( MainWP_System_Utility::is_admin() ) {
				MainWP_Utility::update_option( 'mainwp_disableSitesChecking', ( ! isset( $_POST['mainwp_disableSitesChecking'] ) ? 0 : 1 ) );
				$val = intval( $_POST['mainwp_frequencySitesChecking'] );
				MainWP_Utility::update_option( 'mainwp_frequencySitesChecking', $val );
				$val = intval( $_POST['mainwp_sitehealthThreshold'] );
				MainWP_Utility::update_option( 'mainwp_sitehealthThreshold', $val );
				return true;
			}
		}
		return false;
	}

	/**
	 * Method handle_check_website()
	 *
	 * Handle check website online status.
	 *
	 * @param object $website The website.
	 *
	 * @return mixed $result Result of checking.
	 */
	public static function handle_check_website( $website ) {

		$result = MainWP_Connect::check_website_status( $website );

		if ( ! is_array( $result ) ) {
			return false;
		}

		$new_code        = ( is_array( $result ) && isset( $result['httpCode'] ) ) ? $result['httpCode'] : 0;
		$online_detected = MainWP_Connect::check_ignored_http_code( $http_code );
		$time            = time();

		// computes duration before update website checking values.
		$duration    = self::get_duration_for_status( $website, $time );
		$new_noticed = self::get_http_noticed_status_value( $website );

		// to save last status.
		MainWP_DB::instance()->update_website_values(
			$website->id,
			array(
				'offline_check_result' => $online_detected ? 1 : -1,
				'offline_checks_last'  => $time,
				'http_response_code'   => $new_code,
				'http_code_noticed'    => $new_noticed,
			)
		);

		// to save status.
		MainWP_DB_Monitoring::instance()->insert_website_status(
			array(
				'wpid'             => $website->id,
				'timestamp_status' => $time,
				'http_code'        => $new_code,
				'status'           => self::get_site_checking_status( $new_code ),
			),
			$duration
		);

		return $result;
	}

	/**
	 * Method get_http_noticed_status()
	 *
	 * Get new noticed value.
	 *
	 * @param object $website The website.
	 *
	 * @return int $noticed_value new noticed value.
	 */
	private static function get_http_noticed_status_value( $website ) {
		$old_code      = $website->http_response_code;
		$noticed_value = $website->http_code_noticed;

		if ( 200 == $old_code && 200 != $new_code ) {
			if ( 1 == $noticed_value ) {
				$noticed_value = 0; // if new offline and noticed then update to notice again.
			}
		} elseif ( 200 != $old_code && 200 == $new_code ) {
			if ( 0 == $noticed_value ) {
				$noticed_value = 1; // if online and not noticed then update to abort notice.
			}
		}
		return $noticed_value;
	}

	/**
	 * Method get_duration_for_status()
	 *
	 * Computes duration status value.
	 *
	 * @param object $website The Website.
	 * @param int    $time The current compution time.
	 *
	 * @return int $duration duration value.
	 */
	private static function get_duration_for_status( $website, $time ) {

		$use_indi_interval = ( 0 < $website->status_check_interval ) ? true : false;

		if ( 0 != $website->offline_checks_last ) {
			$duration_site_last = $time - $website->offline_checks_last; // duration equal now (time()) minus last time checked.
		}

		if ( $use_indi_interval ) { // if use individual interval for this site.
			if ( 0 < $duration_site_last ) {
				$duration = $duration_site_last;
			} else {
				$duration = $website->status_check_interval * 60; // in seconds.
			}
		} else { // use global interval for this site.
			if ( 0 < $duration_site_last ) {
				$duration = $duration_site_last;
			} else {
				$freq_minutes = get_option( 'mainwp_frequencySitesChecking', 60 );
				$duration     = $freq_minutes * 60; // in seconds.
			}
		}

		// to limit duration 24 hours.
		if ( 60 * 60 * 24 < $duration ) {
			$duration = 60 * 60 * 24;
		}

		return $duration;
	}

	/**
	 * Method get_site_checking_status()
	 *
	 * To notice sites offline status.
	 *
	 * @param int $http_code HTTP code.
	 */
	public static function get_site_checking_status( $http_code ) {
		return ( '' == $http_code ) ? 0 : ( 200 == $http_code ? 1 : 0 );
	}


	/**
	 * Method ajax_check_status_site()
	 *
	 * Check status Child Site via ajx.
	 */
	public static function ajax_check_status_site() {
		$website = null;
		if ( isset( $_POST['wp_id'] ) ) {
			$website = MainWP_DB::instance()->get_website_by_id( $_POST['wp_id'] );
		}

		if ( null == $website ) {
			die( wp_json_encode( array( 'error' => __( 'Invalid request. Please, try again.', 'mainwp' ) ) ) );
		}

		MainWP_Utility::end_session();
		$result = self::handle_check_website( $website );
		MainWP_Utility::end_session();

		if ( is_array( $result ) ) {
			die( wp_json_encode( array( 'result' => 'success' ) ) );
		} else {
			die( wp_json_encode( array( 'error' => __( 'Request failed. Please, try again.', 'mainwp' ) ) ) );
		}
	}
}
