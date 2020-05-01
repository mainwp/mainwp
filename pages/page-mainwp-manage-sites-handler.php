<?php
/**
 * Manage Sites Handler.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * MainWP Manage Sites Handler Page
 */
class MainWP_Manage_Sites_Handler {

	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Method check_site()
	 *
	 * Check to add site
	 *
	 * @return mixed send json encode data
	 */
	public static function check_site() {
		$website = MainWP_DB::instance()->get_websites_by_url( $_POST['url'] );
		$ret     = array();
		if ( MainWP_Utility::can_edit_website( $website ) ) {
			$ret['response'] = 'ERROR You already added your site to MainWP';
		} else {
			try {
				$verify_cert    = ( ! isset( $_POST['verify_certificate'] ) || ( empty( $_POST['verify_certificate'] ) && ( '0' !== $_POST['verify_certificate'] ) ) ? null : $_POST['verify_certificate'] );
				$force_use_ipv4 = ( ! isset( $_POST['force_use_ipv4'] ) || ( empty( $_POST['force_use_ipv4'] ) && ( '0' !== $_POST['force_use_ipv4'] ) ) ? null : $_POST['force_use_ipv4'] );
				$http_user      = ( isset( $_POST['http_user'] ) ? $_POST['http_user'] : '' );
				$http_pass      = ( isset( $_POST['http_pass'] ) ? $_POST['http_pass'] : '' );
				$information    = MainWP_Connect::fetch_url_not_authed( $_POST['url'], $_POST['admin'], 'stats', null, false, $verify_cert, $http_user, $http_pass, $sslVersion = 0, $others = array( 'force_use_ipv4' => $force_use_ipv4 ) ); // Fetch the stats with the given admin name.

				if ( isset( $information['wpversion'] ) ) {
					$ret['response'] = 'OK';
				} elseif ( isset( $information['error'] ) ) {
					$ret['response'] = 'ERROR ' . $information['error'];
				} else {
					$ret['response'] = 'ERROR';
				}
			} catch ( MainWP_Exception $e ) {
				$ret['response'] = $e->getMessage();
			}
		}
		$ret['check_me'] = ( isset( $_POST['check_me'] ) ? intval( $_POST['check_me'] ) : null );
		die( wp_json_encode( $ret ) );
	}

	public static function reconnect_site() {
		$siteId = $_POST['siteid'];

		try {
			if ( MainWP_Utility::ctype_digit( $siteId ) ) {
				$website = MainWP_DB::instance()->get_website_by_id( $siteId );
				MainWP_Manage_Sites_View::m_reconnect_site( $website );
			} else {
				throw new \Exception( __( 'Invalid request! Please try again. If the process keeps failing, please contact the MainWP support.', 'mainwp' ) );
			}
		} catch ( \Exception $e ) {
			die( 'ERROR ' . $e->getMessage() );
		}

		die( __( 'Site has been reconnected successfully!', 'mainwp' ) );
	}


	public static function add_site() {
		$ret     = array();
		$error   = '';
		$message = '';
		$site_id = 0;

		if ( isset( $_POST['managesites_add_wpurl'] ) && isset( $_POST['managesites_add_wpadmin'] ) ) {
			// Check if already in DB.
			$website                           = MainWP_DB::instance()->get_websites_by_url( $_POST['managesites_add_wpurl'] );
			list( $message, $error, $site_id ) = MainWP_Manage_Sites_View::add_site( $website );
		}

		$ret['add_me'] = ( isset( $_POST['add_me'] ) ? intval( $_POST['add_me'] ) : null );
		if ( '' !== $error ) {
			$ret['response'] = 'ERROR ' . $error;
			die( wp_json_encode( $ret ) );
		}
		$ret['response'] = $message;
		$ret['siteid']   = $site_id;

		if ( 1 === MainWP_DB::instance()->get_websites_count() ) {
			$ret['redirectUrl'] = admin_url( 'admin.php?page=managesites' );
		}

		die( wp_json_encode( $ret ) );
	}

	public static function apply_plugin_settings() {
		$site_id      = $_POST['siteId'];
		$ext_dir_slug = $_POST['ext_dir_slug'];
		if ( empty( $site_id ) ) {
			die( wp_json_encode( array( 'error' => __( 'Invalid site ID. Please try again.', 'mainwp' ) ) ) );
		}

		do_action( 'mainwp_applypluginsettings_' . $ext_dir_slug, $site_id );
		die( wp_json_encode( array( 'error' => __( 'Undefined error occurred. Please try again.', 'mainwp' ) ) ) );
	}

	public static function save_note() {
		if ( isset( $_POST['websiteid'] ) && MainWP_Utility::ctype_digit( $_POST['websiteid'] ) ) {
			$website = MainWP_DB::instance()->get_website_by_id( $_POST['websiteid'] );
			if ( MainWP_Utility::can_edit_website( $website ) ) {
				$note     = stripslashes( $_POST['note'] );
				$esc_note = MainWP_Utility::esc_content( $note );
				MainWP_DB_Common::instance()->update_note( $website->id, $esc_note );

				die( wp_json_encode( array( 'result' => 'SUCCESS' ) ) );
			} else {
				die( wp_json_encode( array( 'error' => __( 'Are you sure this is your website?', 'mainwp' ) ) ) );
			}
		}
		die( wp_json_encode( array( 'undefined_error' => true ) ) );
	}

	public static function remove_site() {
		if ( isset( $_POST['id'] ) && MainWP_Utility::ctype_digit( $_POST['id'] ) ) {
			$website = MainWP_DB::instance()->get_website_by_id( $_POST['id'] );
			if ( MainWP_Utility::can_edit_website( $website ) ) {
				$error = '';

				// deactive child plugin on live site only,
				// do not deactive child on staging site, it will deactive child plugin of source site.
				if ( ! $website->is_staging ) {
					try {
						$information = MainWP_Connect::fetch_url_authed( $website, 'deactivate' );
					} catch ( MainWP_Exception $e ) {
						$error = $e->getMessage();
					}
				} else {
					$information['removed'] = true;
				}

				// delete icon file.
				$favi = MainWP_DB::instance()->get_website_option( $website, 'favi_icon', '' );
				if ( ! empty( $favi ) && ( false !== strpos( $favi, 'favi-' . $website->id . '-' ) ) ) {

					$hasWPFileSystem = MainWP_Utility::get_wp_file_system();

					global $wp_filesystem;

					$dirs = MainWP_Utility::get_icons_dir();
					if ( $wp_filesystem->exists( $dirs[0] . $favi ) ) {
						$wp_filesystem->delete( $dirs[0] . $favi );
					}
				}

				// Remove from DB.
				MainWP_DB::instance()->remove_website( $website->id );
				do_action( 'mainwp_delete_site', $website );

				if ( 'NOMAINWP' === $error ) {
					$error = __( 'Be sure to deactivate the child plugin on the child site to avoid potential security issues.', 'mainwp' );
				}

				if ( '' !== $error ) {
					die( wp_json_encode( array( 'error' => $error ) ) );
				} elseif ( isset( $information['deactivated'] ) ) {
					die( wp_json_encode( array( 'result' => 'SUCCESS' ) ) );
				} elseif ( isset( $information['removed'] ) ) {
					die( wp_json_encode( array( 'result' => 'REMOVED' ) ) );
				} else {
					die( wp_json_encode( array( 'undefined_error' => true ) ) );
				}
			}
		}
		die( wp_json_encode( array( 'result' => 'NOSITE' ) ) );
	}

	public static function handle_settings_post() {
		if ( MainWP_Utility::is_admin() ) {
			if ( isset( $_POST['submit'] ) && wp_verify_nonce( $_POST['wp_nonce'], 'Settings' ) ) {
				if ( MainWP_Utility::ctype_digit( $_POST['mainwp_options_backupOnServer'] ) && 0 < $_POST['mainwp_options_backupOnServer'] ) {
					MainWP_Utility::update_option( 'mainwp_backupsOnServer', $_POST['mainwp_options_backupOnServer'] );
				}
				if ( MainWP_Utility::ctype_digit( $_POST['mainwp_options_maximumFileDescriptors'] ) && - 1 < $_POST['mainwp_options_maximumFileDescriptors'] ) {
					MainWP_Utility::update_option( 'mainwp_maximumFileDescriptors', $_POST['mainwp_options_maximumFileDescriptors'] );
				}
				MainWP_Utility::update_option( 'mainwp_maximumFileDescriptorsAuto', ( ! isset( $_POST['mainwp_maximumFileDescriptorsAuto'] ) ? 0 : 1 ) );
				if ( MainWP_Utility::ctype_digit( $_POST['mainwp_options_backupOnExternalSources'] ) && 0 <= $_POST['mainwp_options_backupOnExternalSources'] ) {
					MainWP_Utility::update_option( 'mainwp_backupOnExternalSources', $_POST['mainwp_options_backupOnExternalSources'] );
				}
				MainWP_Utility::update_option( 'mainwp_archiveFormat', $_POST['mainwp_archiveFormat'] );

				$old_primaryBackup          = get_option( 'mainwp_primaryBackup' );
				$old_enableLegacyBackup     = get_option( 'mainwp_enableLegacyBackupFeature' );
				$updated_enableLegacyBackup = false;

				if ( isset( $_POST['mainwp_primaryBackup'] ) ) {
					if ( ! empty( $_POST['mainwp_primaryBackup'] ) ) { // not default backup method.
						MainWP_Utility::update_option( 'mainwp_notificationOnBackupFail', 0 );
						MainWP_Utility::update_option( 'mainwp_notificationOnBackupStart', 0 );
						MainWP_Utility::update_option( 'mainwp_chunkedBackupTasks', 0 );
						if ( empty( $old_primaryBackup ) ) {
							MainWP_Utility::update_option( 'mainwp_enableLegacyBackupFeature', 0 );
							$updated_enableLegacyBackup = true;
						}
					}
					MainWP_Utility::update_option( 'mainwp_primaryBackup', $_POST['mainwp_primaryBackup'] );
				}

				if ( ! isset( $_POST['mainwp_primaryBackup'] ) || empty( $_POST['mainwp_primaryBackup'] ) ) {
					MainWP_Utility::update_option( 'mainwp_options_loadFilesBeforeZip', ( ! isset( $_POST['mainwp_options_loadFilesBeforeZip'] ) ? 0 : 1 ) );
					MainWP_Utility::update_option( 'mainwp_notificationOnBackupFail', ( ! isset( $_POST['mainwp_options_notificationOnBackupFail'] ) ? 0 : 1 ) );
					MainWP_Utility::update_option( 'mainwp_notificationOnBackupStart', ( ! isset( $_POST['mainwp_options_notificationOnBackupStart'] ) ? 0 : 1 ) );
					MainWP_Utility::update_option( 'mainwp_chunkedBackupTasks', ( ! isset( $_POST['mainwp_options_chunkedBackupTasks'] ) ? 0 : 1 ) );
				}

				$enableLegacyBackup = ( isset( $_POST['mainwp_options_enableLegacyBackupFeature'] ) && ! empty( $_POST['mainwp_options_enableLegacyBackupFeature'] ) ) ? 1 : 0;
				if ( $enableLegacyBackup && empty( $old_enableLegacyBackup ) ) {
					MainWP_Utility::update_option( 'mainwp_primaryBackup', '' );
				}

				if ( ! $updated_enableLegacyBackup ) {
					MainWP_Utility::update_option( 'mainwp_enableLegacyBackupFeature', $enableLegacyBackup );
				}
				return true;
			}
		}
		return false;
	}

	public static function update_child_site_value() {
		if ( isset( $_POST['site_id'] ) && MainWP_Utility::ctype_digit( $_POST['site_id'] ) ) {
			$website = MainWP_DB::instance()->get_website_by_id( $_POST['site_id'] );
			if ( MainWP_Utility::can_edit_website( $website ) ) {
				$error    = '';
				$uniqueId = isset( $_POST['unique_id'] ) ? $_POST['unique_id'] : '';
				try {
					$information = MainWP_Connect::fetch_url_authed( $website, 'update_values', array( 'uniqueId' => $uniqueId ) );
				} catch ( MainWP_Exception $e ) {
					$error = $e->getMessage();
				}

				if ( '' !== $error ) {
					die( wp_json_encode( array( 'error' => $error ) ) );
				} elseif ( isset( $information['result'] ) && ( 'ok' === $information['result'] ) ) {
					die( wp_json_encode( array( 'result' => 'SUCCESS' ) ) );
				} else {
					die( wp_json_encode( array( 'undefined_error' => true ) ) );
				}
			}
		}
		die( wp_json_encode( array( 'error' => 'NO_SIDE_ID' ) ) );
	}

}
