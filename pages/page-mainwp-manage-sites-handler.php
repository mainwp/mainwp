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
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::esc_content()
	 */
	public static function check_site() {
		$url     = isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( $_POST['url'] ) ) : '';
		$website = MainWP_DB::instance()->get_websites_by_url( $url );
		$ret     = array();

		if ( MainWP_System_Utility::can_edit_website( $website ) ) {
			$ret['response'] = 'ERROR You already added your site to MainWP';
		} else {
			try {
				$verify_cert    = empty( $_POST['verify_certificate'] ) ? false : intval( $_POST['verify_certificate'] );
				$force_use_ipv4 = ( ! isset( $_POST['force_use_ipv4'] ) || ( empty( $_POST['force_use_ipv4'] ) && ( '0' !== $_POST['force_use_ipv4'] ) ) ? null : sanitize_text_field( wp_unslash( $_POST['force_use_ipv4'] ) ) );
				$http_user      = ( isset( $_POST['http_user'] ) ? sanitize_text_field( wp_unslash( $_POST['http_user'] ) ) : '' );
				$http_pass      = ( isset( $_POST['http_pass'] ) ? wp_unslash( $_POST['http_pass'] ) : '' );
				$admin          = ( isset( $_POST['admin'] ) ? sanitize_text_field( wp_unslash( $_POST['admin'] ) ) : '' );

				$output = array();

				$information = MainWP_Connect::fetch_url_not_authed( $url, $admin, 'stats', null, false, $verify_cert, $http_user, $http_pass, $sslVersion = 0, $others = array( 'force_use_ipv4' => $force_use_ipv4 ), $output ); // Fetch the stats with the given admin name.

				if ( isset( $information['wpversion'] ) ) {
					$ret['response'] = 'OK';
				} elseif ( isset( $information['error'] ) ) {
					$ret['response'] = 'ERROR ' . MainWP_Utility::esc_content( $information['error'] );
				} else {
					$ret['response']  = 'ERROR';
					$ret['resp_data'] = isset( $output['fetch_data'] ) ? $output['fetch_data'] : '';
				}
			} catch ( MainWP_Exception $e ) {
				$ret['response']  = $e->getMessage();
				$ret['resp_data'] = $e->get_data();
			}
		}
		$ret['check_me'] = ( isset( $_POST['check_me'] ) ? intval( $_POST['check_me'] ) : null );
		die( wp_json_encode( $ret ) );
	}

	/**
	 * Method reconnect_site()
	 *
	 * Try to reconnect to Child Site.
	 *
	 * @throws \Exception Error message.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Manage_DB::get_website_by_id()
	 * @uses \MainWP\Dashboard\MainWP_Manage_Sites_View::m_reconnect_site()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::ctype_digit()
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
	 *
	 * @uses \MainWP\Dashboard\MainWP_Manage_Sites_View::add_site()
	 */
	public static function add_site() {
		$ret        = array();
		$error      = '';
		$message    = '';
		$site_id    = 0;
		$output     = array();
		$fetch_data = null;

		if ( isset( $_POST['managesites_add_wpurl'] ) && isset( $_POST['managesites_add_wpadmin'] ) ) {
			// Check if already in DB.
			$website                                        = MainWP_DB::instance()->get_websites_by_url( sanitize_text_field( wp_unslash( $_POST['managesites_add_wpurl'] ) ) );
			list( $message, $error, $site_id, $fetch_data ) = MainWP_Manage_Sites_View::add_site( $website, $output );
		}

		$ret['add_me'] = ( isset( $_POST['add_me'] ) ? intval( $_POST['add_me'] ) : null );
		if ( '' !== $error ) {

			if ( '' != $fetch_data ) {
				$ret['resp_data'] = $fetch_data;
			}

			$ret['response'] = 'ERROR ' . $error;
			die( wp_json_encode( $ret ) );
		}
		$ret['response'] = $message;
		$ret['siteid']   = $site_id;

		if ( isset( $output['fetch_data'] ) ) {
			$ret['resp_data'] = $output['fetch_data'];
		} elseif ( '' != $fetch_data ) {
			$ret['resp_data'] = $fetch_data;
		}

		if ( 1 === MainWP_DB::instance()->get_websites_count() ) {
			$ret['redirectUrl'] = admin_url( 'admin.php?page=managesites' );
		}

		die( wp_json_encode( $ret ) );
	}

	/**
	 * Method rest_api_add_site().
	 *
	 * Rest API add website.
	 *
	 * @param array $data fields array.
	 * @param array $output Output array.
	 *
	 * $data fields.
	 * 'url'.
	 * 'name'.
	 * 'admin'.
	 * 'uniqueid'.
	 * 'ssl_verify'.
	 * 'force_use_ipv4'.
	 * 'ssl_version'.
	 * 'http_user'.
	 * 'http_pass'.
	 * 'groupids'.
	 *
	 * @return mixed Results.
	 */
	public static function rest_api_add_site( $data, &$output = array() ) {
		$params['url']            = isset( $data['url'] ) ? sanitize_text_field( wp_unslash( $data['url'] ) ) : '';
		$params['name']           = isset( $data['name'] ) ? sanitize_text_field( wp_unslash( $data['name'] ) ) : '';
		$params['wpadmin']        = isset( $data['admin'] ) ? sanitize_text_field( wp_unslash( $data['admin'] ) ) : '';
		$params['unique_id']      = isset( $data['uniqueid'] ) ? sanitize_text_field( wp_unslash( $data['uniqueid'] ) ) : '';
		$params['ssl_verify']     = empty( $data['ssl_verify'] ) ? false : intval( $data['ssl_verify'] );
		$params['force_use_ipv4'] = ( ! isset( $data['force_use_ipv4'] ) || ( empty( $data['force_use_ipv4'] ) && ( '0' !== $data['force_use_ipv4'] ) ) ? null : intval( $data['force_use_ipv4'] ) );
		$params['ssl_version']    = ! isset( $data['ssl_version'] ) || empty( $data['ssl_version'] ) ? null : intval( $data['ssl_version'] );
		$params['http_user']      = isset( $data['http_user'] ) ? sanitize_text_field( wp_unslash( $data['http_user'] ) ) : '';
		$params['http_pass']      = isset( $data['http_pass'] ) ? wp_unslash( $data['http_pass'] ) : '';
		$params['groupids']       = isset( $data['groupids'] ) && ! empty( $data['groupids'] ) ? explode( ',', sanitize_text_field( wp_unslash( $data['groupids'] ) ) ) : array();
		return MainWP_Manage_Sites_View::add_wp_site( false, $params, $output );
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
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::esc_content()
	 */
	public static function save_note() {
		if ( isset( $_POST['websiteid'] ) ) {
			$website = MainWP_DB::instance()->get_website_by_id( intval( $_POST['websiteid'] ) );
			if ( MainWP_System_Utility::can_edit_website( $website ) ) {
				$note     = isset( $_POST['note'] ) ? wp_unslash( $_POST['note'] ) : '';
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

			$result = self::remove_website( $_POST['id'] );
			$error  = is_array( $result ) && isset( $result['error'] ) ? $result['error'] : '';

			if ( 'NOMAINWP' === $error ) {
				$error = __( 'Be sure to deactivate the child plugin on the child site to avoid potential security issues.', 'mainwp' );
			}

			if ( '' !== $error ) {
				die( wp_json_encode( array( 'error' => esc_html( $error ) ) ) );
			} elseif ( isset( $information['deactivated'] ) ) {
				die( wp_json_encode( array( 'result' => 'SUCCESS' ) ) );
			} elseif ( isset( $information['removed'] ) ) {
				die( wp_json_encode( array( 'result' => 'REMOVED' ) ) );
			} else {
				die( wp_json_encode( array( 'undefined_error' => true ) ) );
			}
		}
		die( wp_json_encode( array( 'result' => 'NOSITE' ) ) );
	}

	/**
	 * Method handle remove_site()
	 *
	 * Try to remove Child Site.
	 *
	 * @param object|int $site object or Child site ID.
	 *
	 * @return mixed|false result
	 * @throws \Exception Error message.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_wp_file_system()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_icons_dir()
	 */
	public static function remove_website( $site ) {

		if ( is_numeric( $site ) ) {
			$website = MainWP_DB::instance()->get_website_by_id( intval( $site ) );
		} else {
			$website = $site;
		}

		$information = false;

		if ( MainWP_System_Utility::can_edit_website( $website ) ) {
			/**
			 * Deactive child plugin on live site only,
			 * DO NOT deactive child on staging site, it will deactive child plugin of source site.
			 */
			if ( ! $website->is_staging ) {
				try {
					$information = MainWP_Connect::fetch_url_authed( $website, 'deactivate' );
				} catch ( MainWP_Exception $e ) {
					$information['error'] = $e->getMessage();
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
		}

		return $information;
	}

	/**
	 * Method update_child_site_value()
	 *
	 * Update Child Site ID.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
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
