<?php
/**
 * Manage Sites Handler.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Manage_Sites_Handler
 *
 * @package MainWP\Dashboard
 */
class MainWP_Manage_Sites_Handler {

	/**
	 * Get Class Name
	 *
	 * @return string __CLASS__
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Method check_site()
	 *
	 * Check to add site.
	 *
	 * @return mixed send json encode data
	 */
	public static function check_site() {
		$url     = isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( $_POST['url'] ) ) : '';
		$website = MainWP_DB::instance()->get_websites_by_url( $url );
		$ret     = array();

		if ( MainWP_System_Utility::can_edit_website( $website ) ) {
			$ret['response'] = 'ERROR You already added your site to MainWP';
		} else {
			try {
				$verify_cert    = empty( $_POST['verify_certificate'] ) ? null : intval( $_POST['verify_certificate'] );
				$force_use_ipv4 = ( ! isset( $_POST['force_use_ipv4'] ) || ( empty( $_POST['force_use_ipv4'] ) && ( '0' !== $_POST['force_use_ipv4'] ) ) ? null : sanitize_text_field( wp_unslash( $_POST['force_use_ipv4'] ) ) );
				$http_user      = ( isset( $_POST['http_user'] ) ? sanitize_text_field( wp_unslash( $_POST['http_user'] ) ) : '' );
				$http_pass      = ( isset( $_POST['http_pass'] ) ? wp_unslash( $_POST['http_pass'] ) : '' );
				$admin          = ( isset( $_POST['admin'] ) ? sanitize_text_field( wp_unslash( $_POST['admin'] ) ) : '' );

				$information = MainWP_Connect::fetch_url_not_authed( $url, $admin, 'stats', null, false, $verify_cert, $http_user, $http_pass, $sslVersion = 0, $others = array( 'force_use_ipv4' => $force_use_ipv4 ) ); // Fetch the stats with the given admin name.

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

	/**
	 * Method reconnect_site()
	 *
	 * Try to recconnect to Child Site.
	 *
	 * @throws \Exception Error message.
	 */
	public static function reconnect_site() {
		$siteId = isset( $_POST['siteid'] ) ? intval( $_POST['siteid'] ) : false;

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


	/**
	 * Method add_site()
	 *
	 * Add new Child Site.
	 */
	public static function add_site() {
		$ret     = array();
		$error   = '';
		$message = '';
		$site_id = 0;

		if ( isset( $_POST['managesites_add_wpurl'] ) && isset( $_POST['managesites_add_wpadmin'] ) ) {
			// Check if already in DB.
			$website                           = MainWP_DB::instance()->get_websites_by_url( wp_unslash( $_POST['managesites_add_wpurl'] ) );
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

	/**
	 * Method apply_plugin_settings()
	 *
	 * Apply plugin settings.
	 */
	public static function apply_plugin_settings() {
		$site_id      = isset( $_POST['siteId'] ) ? intval( $_POST['siteId'] ) : false;
		$ext_dir_slug = isset( $_POST['ext_dir_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['ext_dir_slug'] ) ) : '';
		if ( empty( $site_id ) ) {
			die( wp_json_encode( array( 'error' => __( 'Invalid site ID. Please try again.', 'mainwp' ) ) ) );
		}

		/**
		 * Apply plugin settings
		 *
		 * Fires to apply certain plugin settigns automatically while adding a new site.
		 *
		 * @param int $site_id Child site ID.
		 *
		 * @since Unknown
		 */
		do_action( 'mainwp_applypluginsettings_' . $ext_dir_slug, $site_id );
		die( wp_json_encode( array( 'error' => __( 'Undefined error occurred. Please try again.', 'mainwp' ) ) ) );
	}

	/**
	 * Method save_note()
	 *
	 * Save Child Site Note.
	 */
	public static function save_note() {
		if ( isset( $_POST['websiteid'] ) ) {
			$website = MainWP_DB::instance()->get_website_by_id( intval( $_POST['websiteid'] ) );
			if ( MainWP_System_Utility::can_edit_website( $website ) ) {
				$note     = isset( $_POST['note'] ) ? wp_unslash( $_POST['note'] ) : ''; // do not sanitize.
				$esc_note = MainWP_Utility::esc_content( $note );
				MainWP_DB_Common::instance()->update_note( $website->id, $esc_note );

				die( wp_json_encode( array( 'result' => 'SUCCESS' ) ) );
			} else {
				die( wp_json_encode( array( 'error' => __( 'Are you sure this is your website?', 'mainwp' ) ) ) );
			}
		}
		die( wp_json_encode( array( 'undefined_error' => true ) ) );
	}

	/**
	 * Method remove_site()
	 *
	 * Try to remove Child Site.
	 */
	public static function remove_site() {
		if ( isset( $_POST['id'] ) ) {
			$website = MainWP_DB::instance()->get_website_by_id( intval( $_POST['id'] ) );
			if ( MainWP_System_Utility::can_edit_website( $website ) ) {
				$error = '';

				/**
				 * Deactive child plugin on live site only,
				 * DO NOT deactive child on staging site, it will deactive child plugin of source site.
				 */
				if ( ! $website->is_staging ) {
					try {
						$information = MainWP_Connect::fetch_url_authed( $website, 'deactivate' );
					} catch ( MainWP_Exception $e ) {
						$error = $e->getMessage();
					}
				} else {
					$information['removed'] = true;
				}

				// Delete icon file.
				$favi = MainWP_DB::instance()->get_website_option( $website, 'favi_icon', '' );
				if ( ! empty( $favi ) && ( false !== strpos( $favi, 'favi-' . $website->id . '-' ) ) ) {

					$hasWPFileSystem = MainWP_System_Utility::get_wp_file_system();

					/**
					 * WordPress files system object.
					 *
					 * @global object
					 */
					global $wp_filesystem;

					$dirs = MainWP_System_Utility::get_icons_dir();
					if ( $wp_filesystem->exists( $dirs[0] . $favi ) ) {
						$wp_filesystem->delete( $dirs[0] . $favi );
					}
				}

				// Remove from DB.
				MainWP_DB::instance()->remove_website( $website->id );

				/**
				 * Delete Child Sites
				 *
				 * Fires after a child site has been removed from MainWP Dashboard
				 *
				 * @param object $website Object containing child site data.
				 *
				 * @since 3.4
				 */
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


	/**
	 * Method update_child_site_value()
	 *
	 * Update Child Site ID.
	 */
	public static function update_child_site_value() {
		if ( isset( $_POST['site_id'] ) ) {
			$website = MainWP_DB::instance()->get_website_by_id( intval( $_POST['site_id'] ) );
			if ( MainWP_System_Utility::can_edit_website( $website ) ) {
				$error    = '';
				$uniqueId = isset( $_POST['unique_id'] ) ? sanitize_text_field( wp_unslash( $_POST['unique_id'] ) ) : '';
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
