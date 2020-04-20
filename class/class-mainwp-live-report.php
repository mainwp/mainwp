<?php
/**
 * MainWP Client Live Reports
 *
 * Legacy Client Reports Extension.
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Live_Reports
 *
 * @deprecated moved to external Extension.
 *  phpcs:disable PSR1.Classes.ClassDeclaration,Generic.Files.OneObjectStructurePerFile,WordPress.DB.RestrictedFunctions, WordPress.DB.PreparedSQL.NotPrepared -- unprepared SQL ok, accessing the database directly to custom database functions - Deprecated
 */
class MainWP_Live_Reports {

	private static $buffer               = array();
	private static $enabled_piwik        = null;
	private static $enabled_sucuri       = false;
	private static $enabled_ga           = null;
	private static $enabled_aum          = null;
	private static $enabled_woocomstatus = null;
	public static $enabled_pagespeed     = null;
	public static $enabled_brokenlinks   = null;
	private static $count_sec_header     = 0;
	private static $count_sec_body       = 0;
	private static $count_sec_footer     = 0;

	public function __construct() {
	}

	public static function init() {
	}

	public function admin_init() {

		if ( ! in_array( 'mainwp-client-reports-extension/mainwp-client-reports-extension.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
			add_action( 'mainwp_update_site', array( &$this, 'update_site_update_tokens' ), 8, 1 );
			add_action( 'mainwp_delete_site', array( &$this, 'delete_site_delete_tokens' ), 8, 1 );
			add_action( 'mainwp_managesite_backup', array( &$this, 'managesite_backup' ), 10, 3 );
			add_action( 'mainwp_sucuri_scan_done', array( &$this, 'sucuri_scan_done' ), 10, 3 );
			if ( get_option( 'mainwp_enable_managed_cr_for_wc' ) == 1 ) {
				add_action( 'mainwp-extension-sites-edit', array( &$this, 'manage_site_token' ), 9, 1 ); // @deprecated Use 'mainwp_extension_sites_edit' instead.
				add_action( 'mainwp_extension_sites_edit', array( &$this, 'manage_site_token' ), 9, 1 );
			}
		}

		self::$enabled_piwik        = is_plugin_active( 'mainwp-piwik-extension/mainwp-piwik-extension.php' ) ? true : false;
		self::$enabled_sucuri       = is_plugin_active( 'mainwp-sucuri-extension/mainwp-piwik-extension.php' ) ? true : false;
		self::$enabled_ga           = is_plugin_active( 'mainwp-google-analytics-extension/mainwp-google-analytics-extension.php' ) ? true : false;
		self::$enabled_aum          = is_plugin_active( 'advanced-uptime-monitor-extension/advanced-uptime-monitor-extension.php' ) ? true : false;
		self::$enabled_woocomstatus = is_plugin_active( 'mainwp-woocommerce-status-extension/mainwp-woocommerce-status-extension.php' ) ? true : false;
		self::$enabled_pagespeed    = is_plugin_active( 'mainwp-page-speed-extension/mainwp-page-speed-extension.php' ) ? true : false;
		self::$enabled_brokenlinks  = is_plugin_active( 'mainwp-broken-links-checker-extension/mainwp-broken-links-checker-extension.php' ) ? true : false;
	}

	public function managesite_backup( $website, $args, $information ) {
		if ( empty( $website ) ) {
			return;
		}
		$type = isset( $args['type'] ) ? $args['type'] : '';
		if ( empty( $type ) ) {
			return;
		}

		global $mainwpLiveReportResponderActivator;

		$backup_type = ( 'full' === $type ) ? 'Full' : ( 'db' === $type ? 'Database' : '' );

		$message       = '';
		$backup_status = 'success';
		$backup_size   = 0;
		if ( isset( $information['error'] ) ) {
			$message       = $information['error'];
			$backup_status = 'failed';
		} elseif ( 'db' === $type && ! $information['db'] ) {
			$message       = 'Database backup failed.';
			$backup_status = 'failed';
		} elseif ( 'full' === $type && ! $information['full'] ) {
			$message       = 'Full backup failed.';
			$backup_status = 'failed';
		} elseif ( isset( $information['db'] ) ) {
			if ( false !== $information['db'] ) {
				$message = 'Backup database success.';
			} elseif ( false !== $information['full'] ) {
				$message = 'Full backup success.';
			}
			if ( isset( $information['size'] ) ) {
				$backup_size = $information['size'];
			}
		} else {
			$message       = 'Database backup failed due to an undefined error';
			$backup_status = 'failed';
		}

		$post_data = array(
			'mwp_action'     => 'save_backup_stream',
			'size'           => $backup_size,
			'message'        => $message,
			'destination'    => 'Local Server',
			'status'         => $backup_status,
			'type'           => $backup_type,
		);
		apply_filters( 'mainwp_fetchurlauthed', $mainwpLiveReportResponderActivator->get_child_file(), $mainwpLiveReportResponderActivator->get_child_key(), $website->id, 'client_report', $post_data );
	}

	public static function managesite_schedule_backup( $website, $args, $backupResult ) {

		if ( empty( $website ) ) {
			return;
		}

		$type = isset( $args['type'] ) ? $args['type'] : '';
		if ( empty( $type ) ) {
			return;
		}

		$destination = '';
		if ( is_array( $backupResult ) ) {
			$error = false;
			if ( isset( $backupResult['error'] ) ) {
				$destination .= $backupResult['error'] . '<br />';
				$error        = true;
			}

			if ( isset( $backupResult['ftp'] ) ) {
				if ( 'success' != $backupResult['ftp'] ) {
					$destination .= 'FTP: ' . $backupResult['ftp'] . '<br />';
					$error        = true;
				} else {
					$destination .= 'FTP: success<br />';
				}
			}

			if ( isset( $backupResult['dropbox'] ) ) {
				if ( 'success' != $backupResult['dropbox'] ) {
					$destination .= 'Dropbox: ' . $backupResult['dropbox'] . '<br />';
					$error        = true;
				} else {
					$destination .= 'Dropbox: success<br />';
				}
			}
			if ( isset( $backupResult['amazon'] ) ) {
				if ( 'success' != $backupResult['amazon'] ) {
					$destination .= 'Amazon: ' . $backupResult['amazon'] . '<br />';
					$error        = true;
				} else {
					$destination .= 'Amazon: success<br />';
				}
			}

			if ( isset( $backupResult['copy'] ) ) {
				if ( 'success' != $backupResult['copy'] ) {
					$destination .= 'Copy.com: ' . $backupResult['amazon'] . '<br />';
					$error        = true;
				} else {
					$destination .= 'Copy.com: success<br />';
				}
			}

			if ( empty( $destination ) ) {
				$destination = 'Local Server';
			}
		} else {
			$destination = $backupResult;
		}

		if ( 'full' === $type ) {
			$message     = 'Schedule full backup.';
			$backup_type = 'Full';
		} else {
			$message     = 'Schedule database backup.';
			$backup_type = 'Database';
		}

		global $mainwpLiveReportResponderActivator;

		$post_data = array(
			'mwp_action'     => 'save_backup_stream',
			'size'           => 'N/A',
			'message'        => $message,
			'destination'    => $destination,
			'status'         => 'N/A',
			'type'           => $backup_type,
		);
		apply_filters( 'mainwp_fetchurlauthed', $mainwpLiveReportResponderActivator->get_child_file(), $mainwpLiveReportResponderActivator->get_child_key(), $website->id, 'client_report', $post_data );
	}

	public function mainwp_postprocess_backup_sites_feedback( $output, $unique ) {
		if ( is_array( $output ) ) {
			foreach ( $output as $key => $value ) {
				$output[ $key ] = $value;
			}
		}

		return $output;
	}

	// phpcs:ignore -- not quite complex method
	public static function cal_schedule_nextsend( $schedule, $start_recurring_date, $scheduleLastSend = 0 ) {
		if ( empty( $schedule ) || empty( $start_recurring_date ) ) {
			return 0;
		}

		$start_today = strtotime( gmdate( 'Y-m-d' ) . ' 00:00:00' );
		$end_today   = strtotime( gmdate( 'Y-m-d' ) . ' 23:59:59' );

		$next_report_date_to = 0;

		if ( 0 === $scheduleLastSend ) {
			if ( $start_recurring_date > $end_today ) {
				$next_report_date_to = $start_recurring_date;
			} elseif ( $start_recurring_date > $start_today ) {
				$next_report_date_to = $end_today;
			} else {
				$scheduleLastSend = $start_recurring_date;
			}
		}

		if ( 0 === $next_report_date_to ) {
			if ( 'daily' === $schedule ) {
				$next_report_date_to = $scheduleLastSend + 24 * 3600;
				while ( $next_report_date_to < $start_today ) {
					$next_report_date_to += 24 * 3600;
				}
			} elseif ( 'weekly' === $schedule ) {
				$next_report_date_to = $scheduleLastSend + 7 * 24 * 3600;
				while ( $next_report_date_to < $start_today ) {
					$next_report_date_to += 24 * 3600;
				}
			} elseif ( 'biweekly' === $schedule ) {
				$next_report_date_to = $scheduleLastSend + 2 * 7 * 24 * 3600;
				while ( $next_report_date_to < $start_today ) {
					$next_report_date_to += 2 * 7 * 24 * 3600;
				}
			} elseif ( 'monthly' === $schedule ) {
				$next_report_date_to = self::calc_next_schedule_send_date( $start_recurring_date, $scheduleLastSend, 1 );
				while ( $next_report_date_to < $start_today ) {
					$next_report_date_to = self::calc_next_schedule_send_date( $start_recurring_date, $next_report_date_to, 1 );
				}
			} elseif ( 'quarterly' === $schedule ) {
				$next_report_date_to = self::calc_next_schedule_send_date( $start_recurring_date, $scheduleLastSend, 3 );
				while ( $next_report_date_to < $start_today ) {
					$next_report_date_to = self::calc_next_schedule_send_date( $start_recurring_date, $next_report_date_to, 3 );
				}
			} elseif ( 'twice_a_year' === $schedule ) {
				$next_report_date_to = self::calc_next_schedule_send_date( $start_recurring_date, $scheduleLastSend, 6 );
				while ( $next_report_date_to < $start_today ) {
					$next_report_date_to = self::calc_next_schedule_send_date( $start_recurring_date, $next_report_date_to, 6 );
				}
			} elseif ( '' === $schedule ) {
				$next_report_date_to = self::calc_next_schedule_send_date( $start_recurring_date, $scheduleLastSend, 12 );
				while ( $next_report_date_to < $start_today ) {
					$next_report_date_to = self::calc_next_schedule_send_date( $start_recurring_date, $next_report_date_to, 12 );
				}
			}
		}
		return $next_report_date_to;
	}

	public static function calc_next_schedule_send_date( $recurring_date, $lastSend, $monthSteps ) {
		$day_to_send     = gmdate( 'd', $recurring_date );
		$month_last_send = gmdate( 'm', $lastSend );
		$year_last_send  = gmdate( 'Y', $lastSend );

		$day_in_month = gmdate( 't' );
		if ( $day_to_send > $day_in_month ) {
			$day_to_send = $day_in_month;
		}

		$month_to_send = $month_last_send + $monthSteps;
		$year_to_send  = $year_last_send;
		if ( $month_to_send > 12 ) {
			$month_to_send = $month_to_send - 12;
			$year_to_send  = $year_last_send + 1;
		}
		return strtotime( $year_to_send . '-' . $month_to_send . '-' . $day_to_send . ' 23:59:59' );
	}

	// phpcs:ignore -- complex function, deprecated
	public static function save_report() {
		if ( isset( $_REQUEST['action'] ) && 'editreport' == $_REQUEST['action'] && isset( $_REQUEST['nonce'] ) && wp_verify_nonce( $_REQUEST['nonce'], 'mwp_creport_nonce' ) ) {
			$messages             = array();
			$errors               = array();
			$report               = array();
			$current_attach_files = '';
			if ( isset( $_REQUEST['id'] ) && ! empty( $_REQUEST['id'] ) ) {
				$report               = MainWP_Live_Reports_Responder_DB::get_instance()->get_report_by( 'id', $_REQUEST['id'], null, null, ARRAY_A );
				$current_attach_files = $report['attach_files'];
			}
			$title = isset( $_POST['mwp_creport_title'] ) ? trim( $_POST['mwp_creport_title'] ) : '';
			if ( '' != $title ) {
				$report['title'] = $title;
			}

			$start_time = 0;
			$end_time   = 0;

			if ( isset( $_POST['mwp_creport_date_from'] ) ) {
				$start_date = trim( $_POST['mwp_creport_date_from'] );
				if ( '' != $start_date ) {
					$start_time = strtotime( $start_date );
				}
			}

			if ( isset( $_POST['mwp_creport_date_to'] ) ) {
				$end_date = trim( $_POST['mwp_creport_date_to'] );
				if ( '' != $end_date ) {
					$end_time = strtotime( $end_date );
				}
			}

			if ( 0 === $end_time ) {
				$current  = time();
				$end_time = mktime( 0, 0, 0, gmdate( 'm', $current ), gmdate( 'd', $current ), gmdate( 'Y', $current ) );
			}

			if ( ( 0 !== $start_time && 0 !== $end_time ) && ( $start_time > $end_time ) ) {
				$tmp        = $start_time;
				$start_time = $end_time;
				$end_time   = $tmp;
			}

			$report['date_from'] = $start_time;
			$report['date_to']   = $end_time + 24 * 3600 - 1;

			if ( isset( $_POST['mwp_creport_client'] ) ) {
				$report['client'] = trim( $_POST['mwp_creport_client'] );
			}

			if ( isset( $_POST['mwp_creport_client_id'] ) ) {
				$report['client_id'] = intval( $_POST['mwp_creport_client_id'] );
			}

			if ( isset( $_POST['mwp_creport_fname'] ) ) {
				$report['fname'] = trim( $_POST['mwp_creport_fname'] );
			}

			if ( isset( $_POST['mwp_creport_fcompany'] ) ) {
				$report['fcompany'] = trim( $_POST['mwp_creport_fcompany'] );
			}

			$from_email = '';
			if ( ! empty( $_POST['mwp_creport_femail'] ) ) {
				$from_email = trim( $_POST['mwp_creport_femail'] );
				if ( ! preg_match( '/^[a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+$/is', $from_email ) ) {
					$from_email = '';
					$errors[]   = 'Incorrect Email Address in the Send From filed.';
				}
			}
			$report['femail'] = $from_email;

			if ( isset( $_POST['mwp_creport_name'] ) ) {
				$report['name'] = trim( $_POST['mwp_creport_name'] );
			}

			if ( isset( $_POST['mwp_creport_company'] ) ) {
				$report['company'] = trim( $_POST['mwp_creport_company'] );
			}

			$to_email     = '';
			$valid_emails = array();
			if ( ! empty( $_POST['mwp_creport_email'] ) ) {
				$to_emails = explode( ',', trim( $_POST['mwp_creport_email'] ) );
				if ( is_array( $to_emails ) ) {
					foreach ( $to_emails as $_email ) {
						if ( ! preg_match( '/^[a-zA-Z0-9_.+-]+@[a-zA-Z0-9-]+\.[a-zA-Z0-9-.]+$/is', $_email ) && ! preg_match( '/^\[.+\]/is', $_email ) ) {
							$to_email = '';
							$errors[] = 'Incorrect Email Address in the Send To field.';
						} else {
							$valid_emails[] = $_email;
						}
					}
				}
			}

			if ( 0 < count( $valid_emails ) ) {
				$to_email = implode( ',', $valid_emails );
			} else {
				$to_email = '';
				$errors[] = 'Incorrect Email Address in the Send To field.';
			}

			$report['email'] = $to_email;

			if ( isset( $_POST['mwp_creport_email_subject'] ) ) {
				$report['subject'] = trim( $_POST['mwp_creport_email_subject'] );
			}
			if ( isset( $_POST['mainwp_creport_recurring_schedule'] ) ) {
				$report['recurring_schedule'] = trim( $_POST['mainwp_creport_recurring_schedule'] );
			}
			if ( isset( $_POST['mainwp_creport_schedule_date'] ) ) {
				$rec_date                 = trim( $_POST['mainwp_creport_schedule_date'] );
				$report['recurring_date'] = ! empty( $rec_date ) ? strtotime( $rec_date . ' ' . gmdate( 'H:i:s' ) ) : 0;
			}
			if ( isset( $_POST['mainwp_creport_schedule_send_email'] ) ) {
				$report['schedule_send_email'] = trim( $_POST['mainwp_creport_schedule_send_email'] );
			}
			$report['schedule_bcc_me'] = isset( $_POST['mainwp_creport_schedule_bbc_me_email'] ) ? 1 : 0;
			if ( isset( $_POST['mainwp_creport_report_header'] ) ) {
				$report['header'] = trim( $_POST['mainwp_creport_report_header'] );
			}

			if ( isset( $_POST['mainwp_creport_report_body'] ) ) {
				$report['body'] = trim( $_POST['mainwp_creport_report_body'] );
			}

			if ( isset( $_POST['mainwp_creport_report_footer'] ) ) {
				$report['footer'] = trim( $_POST['mainwp_creport_report_footer'] );
			}

			$hasWPFileSystem = MainWP_Utility::get_wp_file_system();
			global $wp_filesystem;

			$creport_dir = apply_filters( 'mainwp_getspecificdir', 'client_report/' );

			if ( $hasWPFileSystem ) {
				if ( ! $wp_filesystem->exists( $creport_dir ) ) {
					$wp_filesystem->mkdir( $creport_dir, 0777, true );
				}
				if ( ! $wp_filesystem->exists( $creport_dir . '/index.php' ) ) {
					$wp_filesystem->touch( $creport_dir . '/index.php' );
				}
			}

			$attach_files = 'NOTCHANGE';
			$delete_files = false;
			if ( isset( $_POST['mainwp_creport_delete_attach_files'] ) && '1' === $_POST['mainwp_creport_delete_attach_files'] ) {
				$attach_files = '';
				if ( ! empty( $current_attach_files ) ) {
					self::delete_attach_files( $current_attach_files, $creport_dir );
				}
			}

			$return = array();
			if ( isset( $_FILES['mainwp_creport_attach_files'] ) && ! empty( $_FILES['mainwp_creport_attach_files']['name'][0] ) ) {
				if ( ! empty( $current_attach_files ) ) {
					self::delete_attach_files( $current_attach_files, $creport_dir );
				}

				$output = self::handle_upload_files( $_FILES['mainwp_creport_attach_files'], $creport_dir );
				if ( isset( $output['error'] ) ) {
					$return['error'] = $output['error'];
				}
				if ( is_array( $output ) && isset( $output['filenames'] ) && ! empty( $output['filenames'] ) ) {
					$attach_files = implode( ', ', $output['filenames'] );
				}
			}

			if ( 'NOTCHANGE' !== $attach_files ) {
				$report['attach_files'] = $attach_files;
			}

			$selected_sites  = array();
			$selected_groups = array();

			if ( isset( $_POST['select_by'] ) ) {
				if ( isset( $_POST['selected_sites'] ) && is_array( $_POST['selected_sites'] ) ) {
					foreach ( $_POST['selected_sites'] as $selected ) {
						$selected_sites[] = intval( $selected );
					}
				}

				if ( isset( $_POST['selected_groups'] ) && is_array( $_POST['selected_groups'] ) ) {
					foreach ( $_POST['selected_groups'] as $selected ) {
						$selected_groups[] = intval( $selected );
					}
				}
			}

			$report['sites']  = base64_encode( serialize( $selected_sites ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for benign reasons.
			$report['groups'] = base64_encode( serialize( $selected_groups ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for benign reasons.

			if ( 'schedule' === $_POST['mwp_creport_report_submit_action'] ) {
				$report['scheduled'] = 1;
			}
			$report['schedule_nextsend'] = self::cal_schedule_nextsend( $report['recurring_schedule'], $report['recurring_date'] );

			if ( 'save' === $_POST['mwp_creport_report_submit_action'] ||
			'send' === $_POST['mwp_creport_report_submit_action'] ||
			'save_pdf' === $_POST['mwp_creport_report_submit_action'] ||
			'schedule' === $_POST['mwp_creport_report_submit_action'] ||
			'archive_report' === $_POST['mwp_creport_report_submit_action'] ) {
				$result = MainWP_Live_Reports_Responder_DB::get_instance()->update_report( $report );
				if ( $result ) {
					$return['id'] = $result->id;
					$messages[]   = 'Report has been saved.';
				} else {
					$messages[] = 'Report has not been changed - Report Saved.';
				}
				$return['saved'] = true;
			} elseif ( 'preview' === (string) $_POST['mwp_creport_report_submit_action'] ||
			'send_test_email' === (string) $_POST['mwp_creport_report_submit_action']
			) {
				$submit_report           = json_decode( wp_json_encode( $report ) );
				$return['submit_report'] = $submit_report;
			}

			if ( ! isset( $return['id'] ) && isset( $report['id'] ) ) {
				$return['id'] = $report['id'];
			}

			if ( 0 < count( $errors ) ) {
				$return['error'] = $errors;
			}

			if ( 0 < count( $messages ) ) {
				$return['message'] = $messages;
			}

			return $return;
		}
		return null;
	}

	public static function delete_attach_files( $files, $dir ) {
		$files = explode( ',', $files );
		if ( is_array( $files ) ) {
			foreach ( $files as $file ) {
				$file      = trim( $file );
				$file_path = $dir . $file;
				if ( file_exists( $file_path ) ) {
					@unlink( $file_path );
				}
			}
		}
	}

	public static function handle_upload_files( $file_input, $dest_dir ) {
		$output        = array();
		$attachFiles   = array();
		$allowed_files = array( 'jpeg', 'jpg', 'gif', 'png', 'rar', 'zip', 'pdf' );

		$tmp_files = $file_input['tmp_name'];
		if ( is_array( $tmp_files ) ) {
			foreach ( $tmp_files as $i => $tmp_file ) {
				if ( ( UPLOAD_ERR_OK == $file_input['error'][ $i ] ) && is_uploaded_file( $tmp_file ) ) {
					$file_size = $file_input['size'][ $i ];
					$file_name = $file_input['name'][ $i ];
					$file_ext  = strtolower( end( explode( '.', $file_name ) ) );
					if ( ( $file_size > 5 * 1024 * 1024 ) ) {
						$output['error'][] = $file_name . ' - ' . __( 'File size too big' );
					} elseif ( ! in_array( $file_ext, $allowed_files ) ) {
						$output['error'][] = $file_name . ' - ' . __( 'File type are not allowed' );
					} else {
						$dest_file = $dest_dir . $file_name;
						$dest_file = dirname( $dest_file ) . '/' . wp_unique_filename( dirname( $dest_file ), basename( $dest_file ) );
						if ( move_uploaded_file( $tmp_file, $dest_file ) ) {
							$attachFiles[] = basename( $dest_file );
						} else {
							$output['error'][] = $file_name . ' - ' . __( 'Can not copy file' );
						};
					}
				}
			}
		}
		$output['filenames'] = $attachFiles;
		return $output;
	}

	public static function gen_report_content( $reports, $combine_report = false ) {
		if ( ! is_array( $reports ) ) {
			$reports = array( $reports );
		}

		$remove_default_html = apply_filters( 'mainwp_client_reports_remove_default_html_tags', false, $reports );

		if ( $combine_report ) {
			ob_start();
		}
		foreach ( $reports as $site_id => $report ) {
			if ( ! $combine_report ) {
				ob_start();
			}

			if ( is_array( $report ) && isset( $report['error'] ) ) {
				?>
				<br>
				<div>
					<br>
					<div style="background:#ffffff;padding:0 1.618em;padding-bottom:50px!important">
						<div style="width:600px;background:#fff;margin-left:auto;margin-right:auto;margin-top:10px;margin-bottom:25px;padding:0!important;border:10px Solid #fff;border-radius:10px;overflow:hidden">
							<div style="display: block; width: 100% ; ">
								<div style="display: block; width: 100% ; padding: .5em 0 ;">
									<?php echo $report['error']; ?>
								</div>
							</div>
						</div>
					</div>
				</div>
				<?php
			} elseif ( is_object( $report ) ) {
				if ( $remove_default_html ) {
					echo stripslashes( nl2br( $report->filtered_header ) );
					echo stripslashes( nl2br( $report->filtered_body ) );
					echo stripslashes( nl2br( $report->filtered_footer ) );
				} else {
					?>
					<br>
					<div>
						<br>
						<div style="background:#ffffff;padding:0 1.618em;padding-bottom:50px!important">
							<div style="width:600px;background:#fff;margin-left:auto;margin-right:auto;margin-top:10px;margin-bottom:25px;padding:0!important;border:10px Solid #fff;border-radius:10px;overflow:hidden">
								<div style="display: block; width: 100% ; ">
									<div style="display: block; width: 100% ; padding: .5em 0 ;">
										<?php
										echo stripslashes( nl2br( $report->filtered_header ) );
										?>
										<div style="clear: both;"></div>
									</div>
								</div>
								<br><br><br>
								<div>
									<?php
									echo stripslashes( nl2br( $report->filtered_body ) );
									?>
								</div>
								<br><br><br>
								<div style="display: block; width: 100% ;">
									<?php
									echo stripslashes( nl2br( $report->filtered_footer ) );
									?>
								</div>

							</div>
						</div>
					</div>
					<?php
				}
			}

			if ( ! $combine_report ) {
				$html               = ob_get_clean();
				$output[ $site_id ] = $html;
			}
		}
		if ( $combine_report ) {
			$html     = ob_get_clean();
			$output[] = $html;
		}
		return $output;
	}

	public static function do_filter_content( $content ) {
		return $content;
	}

	public static function filter_report( $report, $allowed_tokens ) {
		global $mainwpLiveReportResponderActivator;
		$websites = array();

		$sel_sites  = MainWP_Utility::maybe_unserialyze( $report->sites );
		$sel_groups = MainWP_Utility::maybe_unserialyze( $report->groups );

		if ( ! is_array( $sel_sites ) ) {
			$sel_sites = array();
		}
		if ( ! is_array( $sel_groups ) ) {
			$sel_groups = array();
		}
		$dbwebsites = apply_filters( 'mainwp_getdbsites', $mainwpLiveReportResponderActivator->get_child_file(), $mainwpLiveReportResponderActivator->get_child_key(), $sel_sites, $sel_groups );

		if ( is_array( $dbwebsites ) ) {
			foreach ( $dbwebsites as $site ) {
				$websites[] = MainWP_Utility::map_site_array( $site, array( 'id', 'name', 'url' ) );
			}
		}
		$filtered_reports = array();
		if ( 0 === count( $websites ) ) {
			return $filtered_reports;
		}

		foreach ( $websites as $site ) {
			$filtered_reports[ $site['id'] ] = self::filter_report_website( $report, $site );
		}
		return $filtered_reports;
	}

	// phpcs:ignore -- complex function, deprecated
	public static function filter_report_website( $report, $website, $allowed_tokens = array() ) {
		$output                  = new \stdClass();
		$output->filtered_header = $report->header;
		$output->filtered_body   = $report->body;
		$output->filtered_footer = $report->footer;
		$output->id              = isset( $report->id ) ? $report->id : 0;
		$get_ga_tokens           = ( ( false !== strpos( $report->header, '[ga.' ) ) || ( false !== strpos( $report->body, '[ga.' ) ) || ( false !== strpos( $report->footer, '[ga.' ) ) ) ? true : false;
		$get_ga_chart            = ( ( false !== strpos( $report->header, '[ga.visits.chart]' ) ) || ( false !== strpos( $report->body, '[ga.visits.chart]' ) ) || ( false !== strpos( $report->footer, '[ga.visits.chart]' ) ) ) ? true : false;
		$get_ga_chart            = $get_ga_chart || ( ( ( false !== strpos( $report->header, '[ga.visits.maximum]' ) ) || ( false !== strpos( $report->body, '[ga.visits.maximum]' ) ) || ( false !== strpos( $report->footer, '[ga.visits.maximum]' ) ) ) ? true : false );

		$get_piwik_tokens       = ( ( false !== strpos( $report->header, '[piwik.' ) ) || ( false !== strpos( $report->body, '[piwik.' ) ) || ( false !== strpos( $report->footer, '[piwik.' ) ) ) ? true : false;
		$get_aum_tokens         = ( ( false !== strpos( $report->header, '[aum.' ) ) || ( false !== strpos( $report->body, '[aum.' ) ) || ( false !== strpos( $report->footer, '[aum.' ) ) ) ? true : false;
		$get_woocom_tokens      = ( ( false !== strpos( $report->header, '[wcomstatus.' ) ) || ( false !== strpos( $report->body, '[wcomstatus.' ) ) || ( false !== strpos( $report->footer, '[wcomstatus.' ) ) ) ? true : false;
		$get_pagespeed_tokens   = ( ( false !== strpos( $report->header, '[pagespeed.' ) ) || ( false !== strpos( $report->body, '[pagespeed.' ) ) || ( false !== strpos( $report->footer, '[pagespeed.' ) ) ) ? true : false;
		$get_brokenlinks_tokens = ( ( false !== strpos( $report->header, '[brokenlinks.' ) ) || ( false !== strpos( $report->body, '[brokenlinks.' ) ) || ( false !== strpos( $report->footer, '[brokenlinks.' ) ) ) ? true : false;
		if ( null !== $website ) {
			$tokens                = MainWP_Live_Reports_Responder_DB::get_instance()->get_tokens();
			$site_tokens           = MainWP_Live_Reports_Responder_DB::get_instance()->get_site_tokens( $website['url'] );
			$replace_tokens_values = array();
			foreach ( $tokens as $token ) {
				$replace_tokens_values[ '[' . $token->token_name . ']' ] = isset( $site_tokens[ $token->id ] ) ? $site_tokens[ $token->id ]->token_value : '';
			}

			if ( $get_piwik_tokens ) {
				$piwik_tokens = self::piwik_data( $website['id'], $report->date_from, $report->date_to );
				if ( is_array( $piwik_tokens ) ) {
					foreach ( $piwik_tokens as $token => $value ) {
						$replace_tokens_values[ '[' . $token . ']' ] = $value;
					}
				}
			}

			if ( $get_ga_tokens ) {
				$ga_tokens = self::ga_data( $website['id'], $report->date_from, $report->date_to, $get_ga_chart );
				if ( is_array( $ga_tokens ) ) {
					foreach ( $ga_tokens as $token => $value ) {
						$replace_tokens_values[ '[' . $token . ']' ] = $value;
					}
				}
			}

			if ( $get_aum_tokens ) {
				$aum_tokens = self::aum_data( $website['id'], $report->date_from, $report->date_to );
				if ( is_array( $aum_tokens ) ) {
					foreach ( $aum_tokens as $token => $value ) {
						$replace_tokens_values[ '[' . $token . ']' ] = $value;
					}
				}
			}

			if ( $get_woocom_tokens ) {
				$wcomstatus_tokens = self::woocomstatus_data( $website['id'], $report->date_from, $report->date_to );
				if ( is_array( $wcomstatus_tokens ) ) {
					foreach ( $wcomstatus_tokens as $token => $value ) {
						$replace_tokens_values[ '[' . $token . ']' ] = $value;
					}
				}
			}
			if ( $get_pagespeed_tokens ) {
				$pagespeed_tokens = self::pagespeed_tokens( $website['id'], $report->date_from, $report->date_to );
				if ( is_array( $pagespeed_tokens ) ) {
					foreach ( $pagespeed_tokens as $token => $value ) {
						$replace_tokens_values[ '[' . $token . ']' ] = $value;
					}
				}
			}

			if ( $get_brokenlinks_tokens ) {
				$brokenlinks_tokens = self::brokenlinks_tokens( $website['id'], $report->date_from, $report->date_to );
				if ( is_array( $brokenlinks_tokens ) ) {
					foreach ( $brokenlinks_tokens as $token => $value ) {
						$replace_tokens_values[ '[' . $token . ']' ] = $value;
					}
				}
			}

			$replace_tokens_values['[report.daterange]'] = MainWP_Utility::format_timestamp( $report->date_from ) . ' - ' . MainWP_Utility::format_timestamp( $report->date_to );

			$replace_tokens_values = apply_filters( 'mainwp_client_reports_custom_tokens', $replace_tokens_values, $report );

			$report_header = $report->header;
			$report_body   = $report->body;
			$report_footer = $report->footer;

			$result = self::parse_report_content( $report_header, $replace_tokens_values, $allowed_tokens );

			if ( ! empty( $allowed_tokens ) ) {
				$newarrayallowedtokens = array();
				$tokensarray           = MainWP_Utility::maybe_unserialyze( stripslashes( $allowed_tokens ) );
				foreach ( $tokensarray as $key => $t ) {
					$newarrayallowedtokens[ $key ] = '[' . $t . ']';
				}
				$result['other_tokens'] = array_intersect( $newarrayallowedtokens, $result['other_tokens'] );
			}

			$found_tokens                       = $result['sections']['section_token'];
			self::$buffer['sections']['header'] = $result['sections'];
			$sections['header']                 = $result['sections'];
			$other_tokens['header']             = $result['other_tokens'];
			$filtered_header                    = $result['filtered_content'];
			unset( $result );

			$result                           = self::parse_report_content( $report_body, $replace_tokens_values, $allowed_tokens );
			self::$buffer['sections']['body'] = $result['sections'];
			$sections['body']                 = $result['sections'];
			$other_tokens['body']             = $result['other_tokens'];
			$filtered_body                    = $result['filtered_content'];
			unset( $result );

			$result = self::parse_report_content( $report_footer, $replace_tokens_values, $allowed_tokens );

			self::$buffer['sections']['footer'] = $result['sections'];
			$sections['footer']                 = $result['sections'];
			$other_tokens['footer']             = $result['other_tokens'];
			$filtered_footer                    = $result['filtered_content'];
			unset( $result );

			$sections_data     = array();
			$other_tokens_data = array();

			$information = self::fetch_stream_data( $website, $report, $sections, $other_tokens );

			if ( ! empty( $allowed_tokens ) ) {
				$newarrayallowedtokens = array();
				$tokensarray           = MainWP_Utility::maybe_unserialyze( stripslashes( $allowed_tokens ) );
				foreach ( $tokensarray as $key => $t ) {
					$newarrayallowedtokens[ $key ] = '[' . $t . ']';
				}

				$checkdisallowedtokens = array();
				if ( isset( $found_tokens ) && ! empty( $found_tokens ) ) {
					foreach ( $found_tokens as $a ) {
						if ( in_array( $a, $newarrayallowedtokens ) ) {
							$checkdisallowedtokens[] = 'yes';
						} else {
							$checkdisallowedtokens[] = 'no';
						}
					}
				}
				$Newinformation_array = array();
				if ( isset( $information['sections_data']['header'] ) && ! empty( $information['sections_data']['header'] ) ) {
					foreach ( $information['sections_data']['header'] as $key => $value ) {
						if ( 'yes' === $checkdisallowedtokens[ $key ] ) {
							$Newinformation_array['header'][] = $value;
						} else {
							$Newinformation_array['header'][] = array();
						}
					}
					$information['sections_data'] = $Newinformation_array;
				}
			}

			if ( is_array( $information ) && ! isset( $information['error'] ) ) {
				self::$buffer['sections_data'] = isset( $information['sections_data'] ) ? $information['sections_data'] : array();
				$sections_data                 = isset( $information['sections_data'] ) ? $information['sections_data'] : array();
				$other_tokens_data             = isset( $information['other_tokens_data'] ) ? $information['other_tokens_data'] : array();
			} else {
				self::$buffer = array();
				return $information;
			}
			unset( $information );

			self::$count_sec_header = 0;
			self::$count_sec_body   = 0;
			self::$count_sec_footer = 0;

			if ( isset( $sections_data['header'] ) && is_array( $sections_data['header'] ) && 0 < count( $sections_data['header'] ) ) {
				$filtered_header = preg_replace_callback( '/(\[section\.[^\]]+\])(.*?)(\[\/section\.[^\]]+\])/is', array( 'MainWP_Live_Reports', 'section_mark_header' ), $filtered_header );
			}

			if ( isset( $sections_data['body'] ) && is_array( $sections_data['body'] ) && 0 < count( $sections_data['body'] ) ) {
				$filtered_body = preg_replace_callback( '/(\[section\.[^\]]+\])(.*?)(\[\/section\.[^\]]+\])/is', array( 'MainWP_Live_Reports', 'section_mark_body' ), $filtered_body );
			}

			if ( isset( $sections_data['footer'] ) && is_array( $sections_data['footer'] ) && 0 < count( $sections_data['footer'] ) ) {
				$filtered_footer = preg_replace_callback( '/(\[section\.[^\]]+\])(.*?)(\[\/section\.[^\]]+\])/is', array( 'MainWP_Live_Reports', 'section_mark_footer' ), $filtered_footer );
			}

			if ( isset( $other_tokens_data['header'] ) && is_array( $other_tokens_data['header'] ) && 0 < count( $other_tokens_data['header'] ) ) {
				$search  = array();
				$replace = array();
				foreach ( $other_tokens_data['header'] as $token => $value ) {
					if ( in_array( $token, $other_tokens['header'] ) ) {
						$search[]  = $token;
						$replace[] = $value;
					}
				}
				$filtered_header = self::replace_content( $filtered_header, $search, $replace );
			}

			if ( isset( $other_tokens_data['body'] ) && is_array( $other_tokens_data['body'] ) && 0 < count( $other_tokens_data['body'] ) ) {
				$search  = array();
				$replace = array();
				foreach ( $other_tokens_data['body'] as $token => $value ) {
					if ( in_array( $token, $other_tokens['body'] ) ) {
						$search[]  = $token;
						$replace[] = $value;
					}
				}
				$filtered_body = self::replace_content( $filtered_body, $search, $replace );
			}

			if ( isset( $other_tokens_data['footer'] ) && is_array( $other_tokens_data['footer'] ) && 0 < count( $other_tokens_data['footer'] ) ) {
				$search  = array();
				$replace = array();
				foreach ( $other_tokens_data['footer'] as $token => $value ) {
					if ( in_array( $token, $other_tokens['footer'] ) ) {
						$search[]  = $token;
						$replace[] = $value;
					}
				}
				$filtered_footer = self::replace_content( $filtered_footer, $search, $replace );
			}

			$output->filtered_header = $filtered_header;
			$output->filtered_body   = $filtered_body;
			$output->filtered_footer = $filtered_footer;
			self::$buffer            = array();
		}
		return $output;
	}

	public static function section_mark_header( $matches ) {
		$content = $matches[0];
		$sec     = $matches[1];
		$index   = self::$count_sec_header;
		$search  = self::$buffer['sections']['header']['section_content_tokens'][ $index ];
		self::$count_sec_header++;
		$sec_content = trim( $matches[2] );
		if ( isset( self::$buffer['sections_data']['header'][ $index ] ) && ! empty( self::$buffer['sections_data']['header'][ $index ] ) ) {
			$loop             = self::$buffer['sections_data']['header'][ $index ];
			$replaced_content = '';
			if ( is_array( $loop ) ) {
				foreach ( $loop as $replace ) {
					$replaced          = self::replace_section_content( $sec_content, $search, $replace );
					$replaced_content .= $replaced . '<br>';
				}
			}
			return $replaced_content;
		}
		return '';
	}

	public static function section_mark_body( $matches ) {
		$content = $matches[0];
		$index   = self::$count_sec_body;
		$search  = self::$buffer['sections']['body']['section_content_tokens'][ $index ];
		self::$count_sec_body++;
		$sec_content = trim( $matches[2] );
		if ( isset( self::$buffer['sections_data']['body'][ $index ] ) && ! empty( self::$buffer['sections_data']['body'][ $index ] ) ) {
			$loop             = self::$buffer['sections_data']['body'][ $index ];
			$replaced_content = '';
			if ( is_array( $loop ) ) {
				foreach ( $loop as $replace ) {
					$replaced          = self::replace_section_content( $sec_content, $search, $replace );
					$replaced_content .= $replaced . '<br>';
				}
			}
			return $replaced_content;
		}
		return '';
	}

	public static function section_mark_footer( $matches ) {
		$content = $matches[0];
		$sec     = $matches[1];
		$index   = self::$count_sec_footer;
		$search  = self::$buffer['sections']['footer']['section_content_tokens'][ $index ];
		self::$count_sec_footer++;
		$sec_content = trim( $matches[2] );
		if ( isset( self::$buffer['sections_data']['footer'][ $index ] ) && ! empty( self::$buffer['sections_data']['footer'][ $index ] ) ) {
			$loop             = self::$buffer['sections_data']['footer'][ $index ];
			$replaced_content = '';
			if ( is_array( $loop ) ) {
				foreach ( $loop as $replace ) {
					$replaced          = self::replace_section_content( $sec_content, $search, $replace );
					$replaced_content .= $replaced . '<br>';
				}
			}
			return $replaced_content;
		}
		return '';
	}

	public function sucuri_scan_done( $website_id, $scan_status, $data ) {
		$scan_result = array();
		if ( is_array( $data ) ) {
			$blacklisted    = isset( $data['BLACKLIST']['WARN'] ) ? true : false;
			$malware_exists = isset( $data['MALWARE']['WARN'] ) ? true : false;
			$system_error   = isset( $data['SYSTEM']['ERROR'] ) ? true : false;

			$status = array();
			if ( $blacklisted ) {
				$status[] = __( 'Site Blacklisted', 'mainwp-client-reports-extension' );
			}
			if ( $malware_exists ) {
				$status[] = __( 'Site With Warnings', 'mainwp-client-reports-extension' );
			}

			$scan_result['status']   = count( $status ) > 0 ? implode( ', ', $status ) : __( 'Verified Clear', 'mainwp-client-reports-extension' );
			$scan_result['webtrust'] = $blacklisted ? __( 'Site Blacklisted', 'mainwp-client-reports-extension' ) : __( 'Trusted', 'mainwp-client-reports-extension' );
		}
		$post_data = array(
			'mwp_action'     => 'save_sucuri_stream',
			'result'         => base64_encode( serialize( $scan_result ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for benign reasons.
			'scan_status'    => $scan_status,
		);
		global $mainwpLiveReportResponderActivator;
		apply_filters( 'mainwp_fetchurlauthed', $mainwpLiveReportResponderActivator->get_child_file(), $mainwpLiveReportResponderActivator->get_child_key(), $website_id, 'client_report', $post_data );
	}

	public static function replace_content( $content, $tokens, $replace_tokens ) {
		return str_replace( $tokens, $replace_tokens, $content );
	}

	public static function replace_section_content( $content, $tokens, $replace_tokens ) {
		foreach ( $replace_tokens as $token => $value ) {
			$content = str_replace( $token, $value, $content );
		}
		$content = str_replace( $tokens, array(), $content );
		return $content;
	}

	public static function parse_report_content( $content, $replaceTokensValues, $allowed_tokens ) {

		$client_tokens  = array_keys( $replaceTokensValues );
		$replace_values = array_values( $replaceTokensValues );

		$filtered_content = str_replace( $client_tokens, $replace_values, $content );
		$content          = str_replace( $client_tokens, $replace_values, $content );

		$sections = array();
		if ( preg_match_all( '/(\[section\.[^\]]+\])(.*?)(\[\/section\.[^\]]+\])/is', $content, $matches ) ) {
			$_count = count( $matches[1] );
			for ( $i = 0; $i < $_count; $i++ ) {
				$sec         = $matches[1][ $i ];
				$sec_content = $matches[2][ $i ];
				$sec_tokens  = array();
				if ( preg_match_all( '/\[[^\]]+\]/is', $sec_content, $matches2 ) ) {
					$sec_tokens = $matches2[0];
				}
				$sections['section_token'][]          = $sec;
				$sections['section_content_tokens'][] = $sec_tokens;
			}
		}

		$removed_sections = preg_replace_callback( '/(\[section\.[^\]]+\])(.*?)(\[\/section\.[^\]]+\])/is', '__return_empty_string', $content );
		$other_tokens     = array();
		if ( preg_match_all( '/\[[^\]]+\]/is', $removed_sections, $matches ) ) {
			$other_tokens = $matches[0];
		}

		return array(
			'sections'         => $sections,
			'other_tokens'     => $other_tokens,
			'filtered_content' => $filtered_content,
		);
	}

	public static function remove_section_tokens( $content ) {
		$matches        = array();
		$section_tokens = array();
		$section        = '';
		if ( preg_match_all( '/\[\/?section\.[^\]]+\]/is', $content, $matches ) ) {
			$section_tokens                     = $matches[0];
			$str_tmp                            = str_replace( array( '[', ']' ), '', $section_tokens[0] );
			list( $context, $action, $section ) = explode( '.', $str_tmp );
		}
		$content = str_replace( $section_tokens, '', $content );
		return array(
			'content' => $content,
			'section' => $section,
		);
	}

	// phpcs:ignore -- complex function
	public static function ga_data( $site_id, $start_date, $end_date, $chart = false ) {

		if ( null === self::$enabled_ga ) {
			self::$enabled_ga = is_plugin_active( 'mainwp-google-analytics-extension/mainwp-google-analytics-extension.php' ) ? true : false;
		}

		if ( ! self::$enabled_ga ) {
			return false;
		}

		if ( ! $site_id || ! $start_date || ! $end_date ) {
			return false;
		}
		$uniq = 'ga_' . $site_id . '_' . $start_date . '_' . $end_date;
		if ( isset( self::$buffer[ $uniq ] ) ) {
			return self::$buffer[ $uniq ];
		}

		$result = apply_filters( 'mainwp_ga_get_data', $site_id, $start_date, $end_date, $chart );
		$output = array(
			'ga.visits'          => 'N/A',
			'ga.pageviews'       => 'N/A',
			'ga.pages.visit'     => 'N/A',
			'ga.bounce.rate'     => 'N/A',
			'ga.new.visits'      => 'N/A',
			'ga.avg.time'        => 'N/A',
			'ga.visits.chart'    => 'N/A',
			'ga.visits.maximum'  => 'N/A',
		);
		if ( ! empty( $result ) && is_array( $result ) ) {
			if ( isset( $result['stats_int'] ) ) {
				$values                   = $result['stats_int'];
				$output['ga.visits']      = ( isset( $values['aggregates'] ) && isset( $values['aggregates']['ga:sessions'] ) ) ? $values['aggregates']['ga:sessions'] : 'N/A';
				$output['ga.pageviews']   = ( isset( $values['aggregates'] ) && isset( $values['aggregates']['ga:pageviews'] ) ) ? $values['aggregates']['ga:pageviews'] : 'N/A';
				$output['ga.pages.visit'] = ( isset( $values['aggregates'] ) && isset( $values['aggregates']['ga:pageviewsPerSession'] ) ) ? self::format_stats_values( $values['aggregates']['ga:pageviewsPerSession'], true, false ) : 'N/A';
				$output['ga.bounce.rate'] = ( isset( $values['aggregates'] ) && isset( $values['aggregates']['ga:bounceRate'] ) ) ? self::format_stats_values( $values['aggregates']['ga:bounceRate'], true, true ) : 'N/A';
				$output['ga.new.visits']  = ( isset( $values['aggregates'] ) && isset( $values['aggregates']['ga:percentNewSessions'] ) ) ? self::format_stats_values( $values['aggregates']['ga:percentNewSessions'], true, true ) : 'N/A';
				$output['ga.avg.time']    = ( isset( $values['aggregates'] ) && isset( $values['aggregates']['ga:avgSessionDuration'] ) ) ? self::format_stats_values( $values['aggregates']['ga:avgSessionDuration'], false, false, true ) : 'N/A';
			}

			if ( $chart && isset( $result['stats_graphdata'] ) ) {

				$intervalls = '1,1,' . count( $result['stats_graphdata'] );

				foreach ( $result['stats_graphdata'] as $k => $v ) {
					if ( $v['1'] > $maximum_value ) {
						$maximum_value      = $v['1'];
						$maximum_value_date = $v['0'];
					}
				}

				$vertical_max = ceil( $maximum_value * 1.3 );
				$dimensions   = '0,' . $vertical_max;

				$graph_values = '';
				foreach ( $result['stats_graphdata'] as $arr ) {
					$graph_values .= $arr['1'] . ',';
				}
				$graph_values = trim( $graph_values, ',' );

				$graph_dates = '';

				$step = 1;
				if ( 20 < count( $result['stats_graphdata'] ) ) {
					$step = 2;
				}
				$nro = 1;
				foreach ( $result['stats_graphdata'] as $arr ) {
					$nro++;
					if ( 0 === ( $nro % $step ) ) {

						$teile = explode( ' ', $arr['0'] );
						if ( 'Jan' === $teile[0] ) {
							$teile[0] = '1';
						}
						if ( 'Feb' === $teile[0] ) {
							$teile[0] = '2';
						}
						if ( 'Mar' === $teile[0] ) {
							$teile[0] = '3';
						}
						if ( 'Apr' === $teile[0] ) {
							$teile[0] = '4';
						}
						if ( 'May' === $teile[0] ) {
							$teile[0] = '5';
						}
						if ( 'Jun' === $teile[0] ) {
							$teile[0] = '6';
						}
						if ( 'Jul' === $teile[0] ) {
							$teile[0] = '7';
						}
						if ( 'Aug' === $teile[0] ) {
							$teile[0] = '8';
						}
						if ( 'Sep' === $teile[0] ) {
							$teile[0] = '9';
						}
						if ( 'Oct' === $teile[0] ) {
							$teile[0] = '10';
						}
						if ( 'Nov' === $teile[0] ) {
							$teile[0] = '11';
						}
						if ( 'Dec' === $teile[0] ) {
							$teile[0] = '12';
						}
						$graph_dates .= $teile[1] . '.' . $teile[0] . '.|';
					}
				}
				$graph_dates = trim( $graph_dates, '|' );

				$scale = '1,0,' . $vertical_max;

				$wire = '0,10,1,4';

				$barcolor   = '508DDE';
				$fillcolor  = 'EDF5FF';
				$lineformat = '1,0,0';

				$output['ga.visits.chart'] = '<img src="http://chart.apis.google.com/chart?cht=lc&chs=600x250&chd=t:' . $graph_values . '&chds=' . $dimensions . '&chco=' . $barcolor . '&chm=B,' . $fillcolor . ',0,0,0&chls=' . $lineformat . '&chxt=x,y&chxl=0:|' . $graph_dates . '&chxr=' . $scale . '&chg=' . $wire . '">';

				$date1 = explode( ' ', $maximum_value_date );
				if ( 'Jan' === $date1[0] ) {
					$date1[0] = '1';
				}
				if ( 'Feb' === $date1[0] ) {
					$date1[0] = '2';
				}
				if ( 'Mar' === $date1[0] ) {
					$date1[0] = '3';
				}
				if ( 'Apr' === $date1[0] ) {
					$date1[0] = '4';
				}
				if ( 'May' === $date1[0] ) {
					$date1[0] = '5';
				}
				if ( 'Jun' === $date1[0] ) {
					$date1[0] = '6';
				}
				if ( 'Jul' === $date1[0] ) {
					$date1[0] = '7';
				}
				if ( 'Aug' === $date1[0] ) {
					$date1[0] = '8';
				}
				if ( 'Sep' === $date1[0] ) {
					$date1[0] = '9';
				}
				if ( 'Oct' === $date1[0] ) {
					$date1[0] = '10';
				}
				if ( 'Nov' === $date1[0] ) {
					$date1[0] = '11';
				}
				if ( 'Dec' === $date1[0] ) {
					$date1[0] = '12';
				}
				$maximum_value_date          = $date1[1] . '.' . $date1[0] . '.';
				$output['ga.visits.maximum'] = $maximum_value . ' (' . $maximum_value_date . ')';
			}

			$output['ga.startdate'] = gmdate( 'd.m.Y', $start_date );
			$output['ga.enddate']   = gmdate( 'd.m.Y', $end_date );

		}
		self::$buffer[ $uniq ] = $output;
		return $output;
	}

	public static function piwik_data( $site_id, $start_date, $end_date ) {
		if ( null === self::$enabled_piwik ) {
			self::$enabled_piwik = is_plugin_active( 'mainwp-piwik-extension/mainwp-piwik-extension.php' ) ? true : false;
		}

		if ( ! self::$enabled_piwik ) {
			return false;
		}
		if ( ! $site_id || ! $start_date || ! $end_date ) {
			return false;
		}
		$uniq = 'pw_' . $site_id . '_' . $start_date . '_' . $end_date;
		if ( isset( self::$buffer[ $uniq ] ) ) {
			return self::$buffer[ $uniq ];
		}

		$values = apply_filters( 'mainwp_piwik_get_data', $site_id, $start_date, $end_date );

		$output                      = array();
		$output['piwik.visits']      = ( is_array( $values ) && isset( $values['aggregates'] ) && isset( $values['aggregates']['nb_visits'] ) ) ? $values['aggregates']['nb_visits'] : 'N/A';
		$output['piwik.pageviews']   = ( is_array( $values ) && isset( $values['aggregates'] ) && isset( $values['aggregates']['nb_actions'] ) ) ? $values['aggregates']['nb_actions'] : 'N/A';
		$output['piwik.pages.visit'] = ( is_array( $values ) && isset( $values['aggregates'] ) && isset( $values['aggregates']['nb_actions_per_visit'] ) ) ? $values['aggregates']['nb_actions_per_visit'] : 'N/A';
		$output['piwik.bounce.rate'] = ( is_array( $values ) && isset( $values['aggregates'] ) && isset( $values['aggregates']['bounce_rate'] ) ) ? $values['aggregates']['bounce_rate'] : 'N/A';
		$output['piwik.new.visits']  = ( is_array( $values ) && isset( $values['aggregates'] ) && isset( $values['aggregates']['nb_uniq_visitors'] ) ) ? $values['aggregates']['nb_uniq_visitors'] : 'N/A';
		$output['piwik.avg.time']    = ( is_array( $values ) && isset( $values['aggregates'] ) && isset( $values['aggregates']['avg_time_on_site'] ) ) ? self::format_stats_values( $values['aggregates']['avg_time_on_site'], false, false, true ) : 'N/A';
		self::$buffer[ $uniq ]       = $output;

		return $output;
	}

	public static function aum_data( $site_id, $start_date, $end_date ) {

		if ( null === self::$enabled_aum ) {
			self::$enabled_aum = is_plugin_active( 'advanced-uptime-monitor-extension/advanced-uptime-monitor-extension.php' ) ? true : false;
		}

		if ( ! self::$enabled_aum ) {
			return false;
		}

		if ( ! $site_id || ! $start_date || ! $end_date ) {
			return false;
		}
		$uniq = 'aum_' . $site_id . '_' . $start_date . '_' . $end_date;
		if ( isset( self::$buffer[ $uniq ] ) ) {
			return self::$buffer[ $uniq ];
		}

		$values = apply_filters( 'mainwp_aum_get_data', $site_id, $start_date, $end_date );

		$output                           = array();
		$output['aum.alltimeuptimeratio'] = ( is_array( $values ) && isset( $values['aum.alltimeuptimeratio'] ) ) ? $values['aum.alltimeuptimeratio'] . '%' : 'N/A';
		$output['aum.uptime7']            = ( is_array( $values ) && isset( $values['aum.uptime7'] ) ) ? $values['aum.uptime7'] . '%' : 'N/A';
		$output['aum.uptime15']           = ( is_array( $values ) && isset( $values['aum.uptime15'] ) ) ? $values['aum.uptime15'] . '%' : 'N/A';
		$output['aum.uptime30']           = ( is_array( $values ) && isset( $values['aum.uptime30'] ) ) ? $values['aum.uptime30'] . '%' : 'N/A';
		$output['aum.uptime45']           = ( is_array( $values ) && isset( $values['aum.uptime45'] ) ) ? $values['aum.uptime45'] . '%' : 'N/A';
		$output['aum.uptime60']           = ( is_array( $values ) && isset( $values['aum.uptime60'] ) ) ? $values['aum.uptime60'] . '%' : 'N/A';

		self::$buffer[ $uniq ] = $output;

		return $output;
	}

	public static function woocomstatus_data( $site_id, $start_date, $end_date ) {

		if ( null === self::$enabled_woocomstatus ) {
			self::$enabled_woocomstatus = is_plugin_active( 'mainwp-woocommerce-status-extension/mainwp-woocommerce-status-extension.php' ) ? true : false;
		}

		if ( ! self::$enabled_woocomstatus ) {
			return false;
		}

		if ( ! $site_id || ! $start_date || ! $end_date ) {
			return false;
		}
		$uniq = 'wcstatus_' . $site_id . '_' . $start_date . '_' . $end_date;
		if ( isset( self::$buffer[ $uniq ] ) ) {
			return self::$buffer[ $uniq ];
		}

		$values     = apply_filters( 'mainwp_woocomstatus_get_data', $site_id, $start_date, $end_date );
		$top_seller = 'N/A';
		if ( is_array( $values ) && isset( $values['wcomstatus.topseller'] ) ) {
			$top = $values['wcomstatus.topseller'];
			if ( is_object( $top ) && isset( $top->name ) ) {
				$top_seller = $top->name;
			}
		}

		$output                                  = array();
		$output['wcomstatus.sales']              = ( is_array( $values ) && isset( $values['wcomstatus.sales'] ) ) ? $values['wcomstatus.sales'] : 'N/A';
		$output['wcomstatus.topseller']          = $top_seller;
		$output['wcomstatus.awaitingprocessing'] = ( is_array( $values ) && isset( $values['wcomstatus.awaitingprocessing'] ) ) ? $values['wcomstatus.awaitingprocessing'] : 'N/A';
		$output['wcomstatus.onhold']             = ( is_array( $values ) && isset( $values['wcomstatus.onhold'] ) ) ? $values['wcomstatus.onhold'] : 'N/A';
		$output['wcomstatus.lowonstock']         = ( is_array( $values ) && isset( $values['wcomstatus.lowonstock'] ) ) ? $values['wcomstatus.lowonstock'] : 'N/A';
		$output['wcomstatus.outofstock']         = ( is_array( $values ) && isset( $values['wcomstatus.outofstock'] ) ) ? $values['wcomstatus.outofstock'] : 'N/A';
		self::$buffer[ $uniq ]                   = $output;
		return $output;
	}

	public static function pagespeed_tokens( $site_id, $start_date, $end_date ) {

		if ( null === self::$enabled_pagespeed ) {
			self::$enabled_pagespeed = is_plugin_active( 'mainwp-page-speed-extension/mainwp-page-speed-extension.php' ) ? true : false;
		}

		if ( ! self::$enabled_pagespeed ) {
			return false;
		}

		if ( ! $site_id || ! $start_date || ! $end_date ) {
			return false;
		}

		$uniq = 'pagespeed_' . $site_id . '_' . $start_date . '_' . $end_date;
		if ( isset( self::$buffer[ $uniq ] ) ) {
			return self::$buffer[ $uniq ];
		}

		$data                  = apply_filters( 'mainwp_pagespeed_get_data', array(), $site_id, $start_date, $end_date );
		self::$buffer[ $uniq ] = $data;
		return $data;
	}

	public static function brokenlinks_tokens( $site_id, $start_date, $end_date ) {

		if ( null === self::$enabled_brokenlinks ) {
			self::$enabled_brokenlinks = is_plugin_active( 'mainwp-broken-links-checker-extension/mainwp-broken-links-checker-extension.php' ) ? true : false;
		}

		if ( ! self::$enabled_brokenlinks ) {
			return false;
		}

		if ( ! $site_id || ! $start_date || ! $end_date ) {
			return false;
		}

		$uniq = 'brokenlinks_' . $site_id . '_' . $start_date . '_' . $end_date;
		if ( isset( self::$buffer[ $uniq ] ) ) {
			return self::$buffer[ $uniq ];
		}

		$data                  = apply_filters( 'mainwp_brokenlinks_get_data', array(), $site_id, $start_date, $end_date );
		self::$buffer[ $uniq ] = $data;
		return $data;
	}

	private static function format_stats_values( $value, $round = false, $perc = false, $showAsTime = false ) {
		if ( $showAsTime ) {
			$value = MainWP_Utility::sec2hms( $value );
		} else {
			if ( $round ) {
				$value = round( $value, 2 );
			}
			if ( $perc ) {
				$value = $value . '%';
			}
		}
		return $value;
	}

	public static function fetch_stream_data( $website, $report, $sections, $tokens ) {
		global $mainwpLiveReportResponderActivator;
		$post_data = array(
			'mwp_action'     => 'get_stream',
			'sections'       => base64_encode( serialize( $sections ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for benign reasons.
			'other_tokens'   => base64_encode( serialize( $tokens ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for benign reasons.
			'date_from'      => $report->date_from,
			'date_to'        => $report->date_to,
		);

		$information = apply_filters( 'mainwp_fetchurlauthed', $mainwpLiveReportResponderActivator->get_child_file(), $mainwpLiveReportResponderActivator->get_child_key(), $website['id'], 'client_report', $post_data );
		if ( is_array( $information ) && ! isset( $information['error'] ) ) {
			return $information;
		} else {
			if ( isset( $information['error'] ) ) {
				$error = $information['error'];
				if ( 'NO_STREAM' === $error ) {
					$error = __( 'Error: No Stream or MainWP Client Reports plugin installed.' );
				}
			} else {
				$error = is_array( $information ) ? @implode( '<br>', $information ) : $information;
			}
			return array( 'error' => $error );
		}
	}

	public static function manage_site_token( $post ) {

		global $mainwpLiveReportResponderActivator;

		$websiteid = $post->id;
		$website   = apply_filters( 'mainwp_getsites', $mainwpLiveReportResponderActivator->get_child_file(), $mainwpLiveReportResponderActivator->get_child_key(), $websiteid );

		if ( $website && is_array( $website ) ) {
			$website = current( $website );
		}

		if ( empty( $website ) ) {
			return;
		}

		$tokens = MainWP_Live_Reports_Responder_DB::get_instance()->get_tokens();

		$site_tokens = array();
		if ( $website ) {
			$site_tokens = MainWP_Live_Reports_Responder_DB::get_instance()->get_site_tokens( $website['url'] );
		}
		?>

		<h3 class="ui dividing header"><?php esc_html_e( 'Managed Client Reports Settings', 'mainwp' ); ?></h3>

		<?php if ( is_array( $tokens ) && 0 < count( $tokens ) ) : ?>
			<?php
			foreach ( $tokens as $token ) {
				if ( ! $token ) {
					continue;
				}
				$token_value = '';
				if ( isset( $site_tokens[ $token->id ] ) && $site_tokens[ $token->id ] ) {
					$token_value = stripslashes( $site_tokens[ $token->id ]->token_value );
				}

				$input_name = 'creport_token_' . str_replace( array( '.', ' ', '-' ), '_', $token->token_name );
				?>
				<div class="ui grid field">
					<label class="six wide column middle aligned">[<?php echo esc_html( stripslashes( $token->token_name ) ); ?>]</label>
					<div class="ui six wide column">
						<div class="ui left labeled input">
							<input type="text"  name="<?php echo esc_attr( $input_name ); ?>" value="<?php echo esc_attr( $token_value ); ?>" />
						</div>
					</div>
				</div>
				<?php
			}
		else :
			?>
			<div class="ui message info"><?php esc_html_e( 'No tokens created.', 'mainwp' ); ?></div>
			<?php
		endif;
	}

	public function update_site_update_tokens( $websiteId ) {
		global $wpdb, $mainwpLiveReportResponderActivator;
		if ( isset( $_POST['submit'] ) ) {
			$website = apply_filters( 'mainwp_getsites', $mainwpLiveReportResponderActivator->get_child_file(), $mainwpLiveReportResponderActivator->get_child_key(), $websiteId );
			if ( $website && is_array( $website ) ) {
				$website = current( $website );
			}

			if ( ! is_array( $website ) ) {
				return;
			}

			$tokens = MainWP_Live_Reports_Responder_DB::get_instance()->get_tokens();
			foreach ( $tokens as $token ) {
				$input_name = 'creport_token_' . str_replace( array( '.', ' ', '-' ), '_', $token->token_name );
				if ( isset( $_POST[ $input_name ] ) ) {
					$token_value = $_POST[ $input_name ];

					$current = MainWP_Live_Reports_Responder_DB::get_instance()->get_tokens_by( 'id', $token->id, $website['url'] );
					if ( $current ) {
						MainWP_Live_Reports_Responder_DB::get_instance()->update_token_site( $token->id, $token_value, $website['url'] );
					} else {
						MainWP_Live_Reports_Responder_DB::get_instance()->add_token_site( $token->id, $token_value, $website['url'] );
					}
				}
			}
		}
	}

	public function delete_site_delete_tokens( $website ) {
		if ( $website ) {
			MainWP_Live_Reports_Responder_DB::get_instance()->delete_site_tokens( $website->url );
		}
	}

}
// phpcs:enable
