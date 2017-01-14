<?php

define( 'DOING_AJAX', true );
if ( !defined( 'WP_ADMIN' ) ) {
	define( 'WP_ADMIN', true );
}

if ( file_exists( '../../../../wp-load.php' ) )
	require_once( '../../../../wp-load.php' );

function liveReportsResponderClasses() {
	if ( file_exists( '../class/class-mainwp-creport.php' ) ) {
		include_once '../class/class-mainwp-creport.php';
	}
}

function checkLiveReportingAccess($siteurl) {
	$access = get_option( 'live-report-responder-provideaccess' );
	return ( ( 'yes' == $access ) && ( get_option('live-report-responder-siteurl') == $siteurl ) );
}

function LiveReportsResponderSecureConnection( $securitykey = NULL ) {
	if ( get_option( 'live-reports-responder-security-id' ) == 'on' ) {
		if ( isset( $securitykey ) && !empty( $securitykey ) ) {
			return ( get_option( 'live-reports-responder-security-code' ) == base64_decode( $securitykey ) );
		}
		return FALSE;
	}
	return TRUE;
}

function checkifvalidclient( $email, $siteid ) {
	$checkPermission = checkLiveReportingAccess( $_POST[ 'livereportingurl' ] );
	$result = array();
	if ( $checkPermission ) {
		liveReportsResponderClasses();
		global $wpdb;
		$get_site_details = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mainwp_client_report_site_token WHERE token_id=%d AND token_value='%s' AND site_url=(SELECT `url` FROM {$wpdb->prefix}mainwp_wp WHERE id=%d)", 12, $email, $siteid ) );
		if ( $get_site_details ) {
			$result['result'] = 'success';
			$result['data'] = $get_site_details;
		}
	}
	return $result;
}

if ( isset( $_POST[ 'content' ] ) && isset( $_POST[ 'action' ] ) && ( 'displaycontent' == $_POST[ 'action'] ) ) {
	$secureconnection = LiveReportsResponderSecureConnection( ( isset( $_POST[ 'securitykey' ] ) ) ? $_POST[ 'securitykey' ] : '' );
	if ( $secureconnection ) {
		$checkPermission = checkLiveReportingAccess( $_POST[ 'livereportingurl' ] );
		if ( $checkPermission ) {
			liveReportsResponderClasses();
			$report = new stdClass();
			$report->title = 'Live Reports';
			$report->date_from = strtotime(date("Y-m-01"));
			$report->date_to = strtotime(date('Y-m-d'));
			$report->client = "";
			$report->client_id = 0;
			$report->fname = "";
			$report->fcompany = "";
			$report->femail = "";
			$report->name = "[client.name]";
			$report->company = "";
			$report->email = "";
			$report->subject = "Report for [client.site.name]";
			$report->recurring_schedule = "";
			$report->schedule_bcc_me = 0;
			$report->header = $_POST['content'];
			$report->body = "";
			$report->footer = "";
			$report->type = 0;
                        $sites = base64_encode( serialize( array( $_POST['siteid'] ) ) );
			$report->sites = $sites;
			$report->groups = "";                        
			$report->schedule_nextsend = 0;
			$filtered_reports = MainWP_Live_Reports_Class::filter_report($report, '');
			echo json_encode( array( "result" => "success", "data" => html_entity_decode( stripslashes( $filtered_reports[ $_POST[ 'siteid' ] ]->filtered_header ) ) ) );
			exit;
		} else {
			echo json_encode( array( "result" => "error", "message" => "Permission Denied" ) );
			exit;
		}
	} else {
		echo json_encode( array( "result" => "error", "message" => "Error - Invalid Security ID" ) );
		exit;
	}
}
if ( isset( $_POST[ 'content' ] ) && isset( $_POST[ 'action' ] ) && ( 'livereport' == $_POST[ 'action' ] ) ) {
	$secureconnection = LiveReportsResponderSecureConnection( ( isset( $_POST[ 'securitykey' ] ) ) ? $_POST['securitykey'] : '' );
	if ( $secureconnection ) {
		$checkPermission = checkLiveReportingAccess( $_POST[ 'livereportingurl' ] );
		if ( $checkPermission ) {
			liveReportsResponderClasses();
			$checkifvalidclient = checkifvalidclient( $_POST[ 'email' ], $_POST[ 'siteid' ] );
			if ( 'success' == $checkifvalidclient[ 'result' ] ) {
				$report = new stdClass();
				$report->title = "Live Report";
				$report->date_from = $_POST['date_from'];
				$report->date_to = $_POST['date_to'];
				$report->client = "";
				$report->client_id = 0;
				$report->fname = "";
				$report->fcompany = "";
				$report->femail = "";
				$report->name = "[client.name]";
				$report->company = "";
				$report->email = "";
				$report->subject = "Report for [client.site.name]";
				$report->recurring_schedule = "";
				$report->schedule_bcc_me = 0;
				$report->header = $_POST['content'];
				$report->body = "";
				$report->footer = "";
				$report->type = 0;
				$sites = base64_encode( serialize( array( $_POST['siteid'] ) ) );
                                $report->sites = $sites;
				$report->groups = "";
				$report->schedule_nextsend = 0;
				$filtered_reports = MainWP_Live_Reports_Class::filter_report( $report, $_POST[ 'allowed_tokens' ] );
				echo json_encode( array( "result" => "success", "data" => html_entity_decode( stripslashes( $filtered_reports[ $_POST[ 'siteid' ] ]->filtered_header ) ) ) );
				exit;
			} else {
				echo json_encode( array( "result" => "error", "message" => "No Report Found" ) );
				exit;
			}
		} else {
			echo json_encode( array( "result" => "error", "message" => "Permission Denied" ) );
			exit;
		}
	} else {
		echo json_encode( array( "result" => "error", "message" => "Error - Invalid Security ID" ) );
		exit;
	}
}
if ( isset( $_POST[ 'email' ] ) && isset( $_POST[ 'action' ] ) && ( 'getallsitesbyemail' == $_POST[ 'action' ] ) && !empty( $_POST[ 'email' ] ) ) {
	$secureconnection = LiveReportsResponderSecureConnection( ( isset( $_POST[ 'securitykey' ] ) ) ? $_POST[ 'securitykey' ] : '' );
	if ( $secureconnection ) {
		$checkPermission = checkLiveReportingAccess( $_POST[ 'livereportingurl' ] );
		if ( $checkPermission ) {
			liveReportsResponderClasses();
			global $wpdb;
			$result = array();
			$get_allsites = $wpdb->get_results( $wpdb->prepare( "SELECT `site_url` FROM `{$wpdb->prefix}mainwp_client_report_site_token` WHERE token_id= %d AND token_value=%s ORDER BY `id` DESC", 12, $_POST[ 'email' ] ) );

			if ( $get_allsites ) {
				foreach ( $get_allsites as $site ) {
					$get_site_details = $wpdb->get_row( $wpdb->prepare( "SELECT `id`,`name`,`url` FROM `{$wpdb->prefix}mainwp_wp` WHERE `url`=%s", $site->site_url ) );

					if ( $get_site_details ) {
						$result['result'] = 'success';
						$result['data'][] = $get_site_details;
					}
				}
			} else {
				$result['result'] = 'error';
				$result['message'] = 'No Site Found';
			}
			echo json_encode( $result );
			exit;
		} else {
			echo json_encode( array( "result" => "error", "message" => "Permission Denied" ) );
			exit;
		}
	} else {
		echo json_encode( array( "result" => "error", "message" => "Error - Invalid Security ID" ) );
		exit;
	}
}
if ( isset( $_POST[ 'action' ] ) && ( 'getallsites' == $_POST['action'] ) ) {
	$secureconnection = LiveReportsResponderSecureConnection( ( isset( $_POST[ 'securitykey' ] ) ) ? $_POST[ 'securitykey' ] : '' );
	if ($secureconnection) {
		$checkPermission = checkLiveReportingAccess( $_POST[ 'livereportingurl'  ] );
		if ( $checkPermission ) {
			liveReportsResponderClasses();
			global $wpdb;
			$result = array();
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
				$result['result'] = 'error';
				$result['message'] = 'No Site Found';
			}
			echo json_encode( $result );
			exit;
		} else {
			echo json_encode( array( 'result' => "error", "message" => "Permission Denied" ) );
			exit;
		}
	} else {
		echo json_encode( array( 'result' => "error", "message" => "Error - Invalid Security ID" ) );
		exit;
	}
}
if ( isset( $_POST[ 'action' ] ) && ('checkvalid_live_reports_responder_url' == $_POST[ 'action' ] ) ) {
	if ( is_plugin_active( 'mainwp/mainwp.php' ) ) {
		$secureconnection = LiveReportsResponderSecureConnection( ( isset( $_POST[ 'securitykey' ] ) ) ? $_POST[ 'securitykey' ] : '' );
		if ( $secureconnection ) {
			$checkPermission = checkLiveReportingAccess( $_POST[ 'livereportingurl' ] );
			if ( $checkPermission ) {
				echo json_encode( array( 'result' => "success", "message" => "Access has been granted" ) );
				exit;
			} else {
				echo json_encode( array( 'result' => "error", "message" => "Error - Connection not allowed in the Managed Client Reports for WooCommerce Responder settings" ) );
				exit;
			}
		} else {
			echo json_encode( array( 'result' => "error", "message" => "Error - Invalid Security ID" ) );
			exit;
		}
	} else {
		echo json_encode( array( "result" => "error", "message" => "Error - MainWp Plugin is not Activated" ) );
		exit;
	}
}
?>