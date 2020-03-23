<?php
/**
 * MainWP Client Live Report Responder
 *
 * Legacy Client Reports Extension.
 *
 * @Depriciated moved to external Extension
 * @see MainWP-Client-Reports-Extension
 */

function live_reports_responder_classes() {
	if ( file_exists( '../class/class-mainwp-creport.php' ) ) {
		include_once '../class/class-mainwp-creport.php';
	}
}

function check_live_reporting_access( $siteurl ) {
	$access = get_option( 'live-report-responder-provideaccess' );
	return ( ( 'yes' == $access ) && ( get_option( 'live-report-responder-siteurl' ) == $siteurl ) );
}

function live_reports_responder_secure_connection( $siteurl = null, $securitykey = null, $signature = null, $action = null,
											   $timestamp = null, $pubkey = null ) {
	if ( ( $siteurl == null ) || ( $signature == null ) || ( $action == null ) || ( $timestamp == null ) ) {
		return array( 'error' => 'Invalid request.' );
	}

	$access = get_option( 'live-report-responder-provideaccess' );
	if ( ( 'yes' != $access ) || ( get_option( 'live-report-responder-siteurl' ) != $siteurl ) ) {
		return array( 'error' => 'Error - Connection not allowed in the Managed Client Reports for WooCommerce Responder settings' );
	}

	if ( $timestamp < ( time() - 48 * 60 * 60 ) ) {
		return array( 'error' => 'Outdated request.' );
	}

	$current_key = get_option( 'live-report-responder-pubkey' );
	if ( ( $pubkey !== null ) ) {
		if ( ! empty( $current_key ) ) {
			return array( 'error' => 'The dashboard is already connected, release the connection on the dashboard please.' );
		}

		MainWP_Utility::update_option( 'live-report-responder-pubkey', $pubkey );
		$current_key = $pubkey;
	}

	if ( empty( $current_key ) ) {
		return array( 'error' => 'The dashboard is not connected, please reconnect to establish a secure connection.' );
	}

	$auth = openssl_verify( $action . $securitykey . $timestamp, base64_decode( $signature ), base64_decode( $current_key ) );
	if ( 0 === $auth ) {
		return array( 'error' => 'An error occured while verifying the secure signature.' );
	} elseif ( -1 === $auth ) {
		return array( 'error' => 'Authentication failed, please reconnect the dashboard.' );
	}

	if ( ( get_option( 'live-reports-responder-security-id' ) == 'on' ) && ( get_option( 'live-reports-responder-security-code' ) !== base64_decode( $securitykey ) ) ) {
		return array( 'error' => 'Invalid security ID.' );
	}

	// to fix conflict with divi theme
	define('DOING_CRON', true); // to fix conflict issue with the team control extension

	return true;
}

function check_if_valid_client( $email, $siteid ) {
	$checkPermission = check_live_reporting_access( $_POST['livereportingurl'] );
	$result          = array();
	if ( $checkPermission ) {
		live_reports_responder_classes();
		global $wpdb;

		$get_site_url = $wpdb->get_row( $wpdb->prepare( "SELECT `url` FROM {$wpdb->prefix}mainwp_wp WHERE id=%d", $siteid ) );
		if ( ! empty( $get_site_url ) ) {
			$get_site_details = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mainwp_client_report_site_token WHERE token_id=%d AND token_value=%s AND site_url=%s", 12, $email, $get_site_url->url ) );
			if ( $get_site_details ) {
				$result['result'] = 'success';
				$result['data']   = $get_site_details;
			}
		}
	}

	return $result;
}

if ( isset( $_POST['content'] ) && isset( $_POST['action'] ) && ( 'displaycontent' == $_POST['action'] ) ) {
	$secureconnection = live_reports_responder_secure_connection( $_POST['livereportingurl'], ( isset( $_POST['securitykey'] ) ) ? $_POST['securitykey'] : '', isset( $_POST['signature'] ) ? $_POST['signature'] : null, isset( $_POST['action'] ) ? $_POST['action'] : null, isset( $_POST['timestamp'] ) ? $_POST['timestamp'] : null );
	if ( $secureconnection === true ) {
		$checkPermission = check_live_reporting_access( $_POST['livereportingurl'] );
		if ( $checkPermission ) {
			live_reports_responder_classes();
			$report                     = new stdClass();
			$report->title              = 'Live Reports';
			$report->date_from          = strtotime( date( 'Y-m-01' ) );
			$report->date_to            = strtotime( date( 'Y-m-d' ) );
			$report->client             = '';
			$report->client_id          = 0;
			$report->fname              = '';
			$report->fcompany           = '';
			$report->femail             = '';
			$report->name               = '[client.name]';
			$report->company            = '';
			$report->email              = '';
			$report->subject            = 'Report for [client.site.name]';
			$report->recurring_schedule = '';
			$report->schedule_bcc_me    = 0;
			$report->header             = $_POST['content'];
			$report->body               = '';
			$report->footer             = '';
			$report->type               = 0;
			$sites                      = base64_encode( serialize( array( $_POST['siteid'] ) ) );
			$report->sites              = $sites;
			$report->groups             = '';
			$report->schedule_nextsend  = 0;
			$filtered_reports           = MainWP_Live_Reports_Class::filter_report( $report, '' );
			echo wp_json_encode(
				array(
					'result' => 'success',
					'data'   => html_entity_decode( stripslashes( $filtered_reports[ $_POST['siteid'] ]->filtered_header ) ),
				)
			);
			exit;
		} else {
			echo wp_json_encode(
				array(
					'result'  => 'error',
					'message' => 'Permission Denied',
				)
			);
			exit;
		}
	} elseif ( isset( $secureconnection['error'] ) ) {
		echo wp_json_encode(
			array(
				'result'  => 'error',
				'message' => $secureconnection['error'],
			)
		);
		exit;
	} else {
		echo wp_json_encode(
			array(
				'result'  => 'error',
				'message' => 'Error - Invalid Request',
			)
		);
		exit;
	}
}
if ( isset( $_POST['content'] ) && isset( $_POST['action'] ) && ( 'livereport' == $_POST['action'] ) ) {
	$secureconnection = live_reports_responder_secure_connection( $_POST['livereportingurl'], ( isset( $_POST['securitykey'] ) ) ? $_POST['securitykey'] : '', isset( $_POST['signature'] ) ? $_POST['signature'] : null, isset( $_POST['action'] ) ? $_POST['action'] : null, isset( $_POST['timestamp'] ) ? $_POST['timestamp'] : null );
	if ( $secureconnection === true ) {
		$checkPermission = check_live_reporting_access( $_POST['livereportingurl'] );
		if ( $checkPermission ) {
			live_reports_responder_classes();
			$checkifvalidclient = check_if_valid_client( $_POST['email'], $_POST['siteid'] );
			$allAccess          = isset( $_POST['allAccess'] ) ? $_POST['allAccess'] : false;
			if ( ( isset( $checkifvalidclient['result'] ) && 'success' == $checkifvalidclient['result'] ) || $allAccess ) {
				$report                     = new stdClass();
				$report->title              = 'Live Report';
				$report->date_from          = $_POST['date_from'];
				$report->date_to            = $_POST['date_to'];
				$report->client             = '';
				$report->client_id          = 0;
				$report->fname              = '';
				$report->fcompany           = '';
				$report->femail             = '';
				$report->name               = '[client.name]';
				$report->company            = '';
				$report->email              = '';
				$report->subject            = 'Report for [client.site.name]';
				$report->recurring_schedule = '';
				$report->schedule_bcc_me    = 0;
				$report->header             = $_POST['content'];
				$report->body               = '';
				$report->footer             = '';
				$report->type               = 0;
				$sites                      = base64_encode( serialize( array( $_POST['siteid'] ) ) );
				$report->sites              = $sites;
				$report->groups             = '';
				$report->schedule_nextsend  = 0;
				$filtered_reports           = MainWP_Live_Reports_Class::filter_report( $report, $_POST['allowed_tokens'] );
				echo wp_json_encode(
					array(
						'result' => 'success',
						'data'   => html_entity_decode( stripslashes( $filtered_reports[ $_POST['siteid'] ]->filtered_header ) ),
					)
				);
				exit;
			} else {
				echo wp_json_encode(
					array(
						'result'  => 'error',
						'message' => 'No Report Found',
					)
				);
				exit;
			}
		} else {
			echo wp_json_encode(
				array(
					'result'  => 'error',
					'message' => 'Permission Denied',
				)
			);
			exit;
		}
	} elseif ( isset( $secureconnection['error'] ) ) {
		echo wp_json_encode(
			array(
				'result'  => 'error',
				'message' => $secureconnection['error'],
			)
		);
		exit;
	} else {
		echo wp_json_encode(
			array(
				'result'  => 'error',
				'message' => 'Error - Invalid Request',
			)
		);
		exit;
	}
}
if ( isset( $_POST['email'] ) && isset( $_POST['action'] ) && ( 'getallsitesbyemail' == $_POST['action'] ) && ! empty( $_POST['email'] ) ) {
	$secureconnection = live_reports_responder_secure_connection( $_POST['livereportingurl'], ( isset( $_POST['securitykey'] ) ) ? $_POST['securitykey'] : '', isset( $_POST['signature'] ) ? $_POST['signature'] : null, isset( $_POST['action'] ) ? $_POST['action'] : null, isset( $_POST['timestamp'] ) ? $_POST['timestamp'] : null );
	if ( $secureconnection ) {
		$checkPermission = check_live_reporting_access( $_POST['livereportingurl'] );
		if ( $checkPermission ) {
			live_reports_responder_classes();
			global $wpdb;
			$result       = array();
			$get_allsites = $wpdb->get_results( $wpdb->prepare( "SELECT `site_url` FROM `{$wpdb->prefix}mainwp_client_report_site_token` WHERE token_id= %d AND token_value=%s ORDER BY `id` DESC", 12, $_POST['email'] ) );

			if ( $get_allsites ) {
				foreach ( $get_allsites as $site ) {
					$get_site_details = $wpdb->get_row( $wpdb->prepare( "SELECT `id`,`name`,`url` FROM `{$wpdb->prefix}mainwp_wp` WHERE `url`=%s", $site->site_url ) );

					if ( $get_site_details ) {
						$result['result'] = 'success';
						$result['data'][] = $get_site_details;
					}
				}
			} else {
				$result['result']  = 'error';
				$result['message'] = 'No Site Found';
			}
			echo wp_json_encode( $result );
			exit;
		} else {
			echo wp_json_encode(
				array(
					'result'  => 'error',
					'message' => 'Permission Denied',
				)
			);
			exit;
		}
	} elseif ( isset( $secureconnection['error'] ) ) {
		echo wp_json_encode(
			array(
				'result'  => 'error',
				'message' => $secureconnection['error'],
			)
		);
		exit;
	} else {
		echo wp_json_encode(
			array(
				'result'  => 'error',
				'message' => 'Error - Invalid Request',
			)
		);
		exit;
	}
}
if ( isset( $_POST['action'] ) && ( 'getallsites' == $_POST['action'] ) ) {
	$secureconnection = live_reports_responder_secure_connection( $_POST['livereportingurl'], ( isset( $_POST['securitykey'] ) ) ? $_POST['securitykey'] : '', isset( $_POST['signature'] ) ? $_POST['signature'] : null, isset( $_POST['action'] ) ? $_POST['action'] : null, isset( $_POST['timestamp'] ) ? $_POST['timestamp'] : null );
	if ( $secureconnection === true ) {
		$checkPermission = check_live_reporting_access( $_POST['livereportingurl'] );
		if ( $checkPermission ) {
			live_reports_responder_classes();
			global $wpdb;
			$result       = array();
			$get_allsites = $wpdb->get_results( $wpdb->prepare( "SELECT `site_url` FROM `{$wpdb->prefix}mainwp_client_report_site_token` WHERE token_id= %d ORDER BY `id` DESC", 12 ) );

			if ( $get_allsites ) {
				foreach ( $get_allsites as $site ) {
					$get_site_details = $wpdb->get_row( $wpdb->prepare( "SELECT `id`,`name`,`url` FROM `{$wpdb->prefix}mainwp_wp` WHERE `url`=%s", $site->site_url ) );

					if ( $get_site_details ) {
						$result['result'] = 'success';
						$result['data'][] = $get_site_details;
					}
				}
			} else {
				$result['result']  = 'error';
				$result['message'] = 'No Site Found';
			}
			echo wp_json_encode( $result );
			exit;
		} else {
			echo wp_json_encode(
				array(
					'result'  => 'error',
					'message' => 'Permission Denied',
				)
			);
			exit;
		}
	} elseif ( isset( $secureconnection['error'] ) ) {
		echo wp_json_encode(
			array(
				'result'  => 'error',
				'message' => $secureconnection['error'],
			)
		);
		exit;
	} else {
		echo wp_json_encode(
			array(
				'result'  => 'error',
				'message' => 'Error - Invalid Request',
			)
		);
		exit;
	}
}
if ( isset( $_POST['action'] ) && ( 'checkvalid_live_reports_responder_url' == $_POST['action'] ) ) {
	$secureconnection = live_reports_responder_secure_connection( $_POST['livereportingurl'], ( isset( $_POST['securitykey'] ) ) ? $_POST['securitykey'] : '', isset( $_POST['signature'] ) ? $_POST['signature'] : null, isset( $_POST['action'] ) ? $_POST['action'] : null, isset( $_POST['timestamp'] ) ? $_POST['timestamp'] : null, isset( $_POST['pubkey'] ) ? $_POST['pubkey'] : null );
	if ( $secureconnection === true ) {
		$checkPermission = check_live_reporting_access( $_POST['livereportingurl'] );
		if ( $checkPermission ) {
			echo wp_json_encode(
				array(
					'result'  => 'success',
					'message' => 'Access has been granted',
				)
			);
			exit;
		} else {
			echo wp_json_encode(
				array(
					'result'  => 'error',
					'message' => 'Error - Connection not allowed in the Managed Client Reports for WooCommerce Responder settings',
				)
			);
			exit;
		}
	} elseif ( isset( $secureconnection['error'] ) ) {
		echo wp_json_encode(
			array(
				'result'  => 'error',
				'message' => $secureconnection['error'],
			)
		);
		exit;
	} else {
		echo wp_json_encode(
			array(
				'result'  => 'error',
				'message' => 'Error - Invalid Request',
			)
		);
		exit;
	}
}

