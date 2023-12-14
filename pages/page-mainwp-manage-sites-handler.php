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
		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$url = isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( $_POST['url'] ) ) : '';
		$url = urldecode( $url );

		$invalid = false;
		$info    = wp_parse_url( $url );

		if ( is_array( $info ) && ! empty( $info['port'] ) && ( 21 === intval( $info['port'] ) || 22 === intval( $info['port'] ) ) ) { // port 21, 22.
			$invalid = true;
		}

		if ( $invalid || 0 !== strpos( $url, 'http://' ) && 0 !== strpos( $url, 'https://' ) || false !== strpos( $url, '?=' ) ) { // to fix: valid url to check.
			die( wp_json_encode( array( 'error' => esc_html__( 'Invalid URL! Please enter valid URL to the Site URL field.', 'mainwp' ) ) ) );
		}

		$website = MainWP_DB::instance()->get_websites_by_url( $url );
		$ret     = array();

		if ( MainWP_System_Utility::can_edit_website( $website ) ) {
			$ret['response'] = esc_html__( 'ERROR Site is already connected to your MainWP Dashboard.', 'mainwp' );
		} else {
			try {
				$verify_cert    = empty( $_POST['verify_certificate'] ) ? false : intval( $_POST['verify_certificate'] );
				$ssl_version    = empty( $_POST['ssl_version'] ) ? 0 : intval( $_POST['ssl_version'] );
				$force_use_ipv4 = apply_filters( 'mainwp_manage_sites_force_use_ipv4', null, $url );
				$http_user      = ( isset( $_POST['http_user'] ) ? sanitize_text_field( wp_unslash( $_POST['http_user'] ) ) : '' );
				$http_pass      = ( isset( $_POST['http_pass'] ) ? wp_unslash( $_POST['http_pass'] ) : '' );
				$admin          = ( isset( $_POST['admin'] ) ? sanitize_text_field( wp_unslash( $_POST['admin'] ) ) : '' );

				$output = array();

				$information = MainWP_Connect::fetch_url_not_authed( $url, $admin, 'stats', null, false, $verify_cert, $http_user, $http_pass, $ssl_version, $others = array( 'force_use_ipv4' => $force_use_ipv4 ), $output ); // Fetch the stats with the given admin name.

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
		// phpcs:enable
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
		$siteId = isset( $_POST['siteid'] ) ? intval( $_POST['siteid'] ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		try {
			if ( MainWP_Utility::ctype_digit( $siteId ) ) {
				$website = MainWP_DB::instance()->get_website_by_id( $siteId );
				MainWP_Manage_Sites_View::m_reconnect_site( $website );
			} else {
				throw new \Exception( esc_html__( 'Site could not be connected. Please check the Status page and be sure that all system requirments pass.', 'mainwp' ) );
			}
		} catch ( \Exception $e ) {
			die( 'ERROR ' . $e->getMessage() ); // phpcs:ignore WordPress.Security.EscapeOutput
		}

		die( esc_html__( 'Site has been reconnected successfully!', 'mainwp' ) );
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

		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( isset( $_POST['managesites_add_wpurl'] ) && isset( $_POST['managesites_add_wpadmin'] ) ) {
			// Check if already in DB.
			$website                                        = MainWP_DB::instance()->get_websites_by_url( sanitize_text_field( wp_unslash( $_POST['managesites_add_wpurl'] ) ) );
			list( $message, $error, $site_id, $fetch_data ) = MainWP_Manage_Sites_View::add_site( $website, $output );
		}

		$ret['add_me'] = ( isset( $_POST['add_me'] ) ? intval( $_POST['add_me'] ) : null );
		if ( '' !== $error ) {

			if ( ! empty( $fetch_data ) ) {
				$ret['resp_data'] = $fetch_data;
			}

			$ret['response'] = 'ERROR ' . $error;
			die( wp_json_encode( $ret ) );
		}
		$ret['response'] = $message;
		$ret['siteid']   = $site_id;

		if ( isset( $output['fetch_data'] ) ) {
			$ret['resp_data'] = $output['fetch_data'];
		} elseif ( ! empty( $fetch_data ) ) { //phpcs:ignore -- to valid.
			$ret['resp_data'] = $fetch_data;
		}
		// phpcs:enable

		if ( 1 === MainWP_DB::instance()->get_websites_count() ) {
			$ret['redirectUrl'] = esc_url( admin_url( 'admin.php?page=managesites' ) );
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
		$params['url']                     = isset( $data['url'] ) ? sanitize_text_field( wp_unslash( $data['url'] ) ) : '';
		$params['name']                    = isset( $data['name'] ) ? sanitize_text_field( wp_unslash( $data['name'] ) ) : '';
		$params['wpadmin']                 = isset( $data['admin'] ) ? sanitize_text_field( wp_unslash( $data['admin'] ) ) : '';
		$params['unique_id']               = isset( $data['uniqueid'] ) ? sanitize_text_field( wp_unslash( $data['uniqueid'] ) ) : '';
		$params['ssl_verify']              = empty( $data['ssl_verify'] ) ? false : intval( $data['ssl_verify'] );
		$params['force_use_ipv4']          = ( ! isset( $data['force_use_ipv4'] ) || ( empty( $data['force_use_ipv4'] ) && ( '0' !== $data['force_use_ipv4'] ) ) ? null : intval( $data['force_use_ipv4'] ) );
		$params['http_user']               = isset( $data['http_user'] ) ? sanitize_text_field( wp_unslash( $data['http_user'] ) ) : '';
		$params['http_pass']               = isset( $data['http_pass'] ) ? wp_unslash( $data['http_pass'] ) : '';
		$params['groupids']                = isset( $data['groupids'] ) && ! empty( $data['groupids'] ) ? explode( ',', sanitize_text_field( wp_unslash( $data['groupids'] ) ) ) : array();
		$website                           = MainWP_DB::instance()->get_websites_by_url( $params['url'] );
		list( $message, $error, $site_id ) = MainWP_Manage_Sites_View::add_wp_site( $website, $params, $output );

		if ( '' !== $error ) {
			return array( 'error' => $error );
		}
		return array(
			'response' => $message,
			'siteid'   => $site_id,
		);
	}

	/**
	 * Method apply_plugin_settings()
	 *
	 * Apply plugin settings.
	 */
	public static function apply_plugin_settings() {
		$site_id      = isset( $_POST['siteId'] ) ? intval( $_POST['siteId'] ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$ext_dir_slug = isset( $_POST['ext_dir_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['ext_dir_slug'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $site_id ) ) {
			die( wp_json_encode( array( 'error' => esc_html__( 'Invalid site ID. Please try again.', 'mainwp' ) ) ) );
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
		die( wp_json_encode( array( 'error' => esc_html__( 'Undefined error occurred. Please try again.', 'mainwp' ) ) ) );
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
		if ( isset( $_POST['websiteid'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$website = MainWP_DB::instance()->get_website_by_id( intval( $_POST['websiteid'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing 
			if ( MainWP_System_Utility::can_edit_website( $website ) ) {
				$note     = isset( $_POST['note'] ) ? wp_unslash( $_POST['note'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$esc_note = MainWP_Utility::esc_content( $note );
				MainWP_DB_Common::instance()->update_note( $website->id, $esc_note );

				die( wp_json_encode( array( 'result' => 'SUCCESS' ) ) );
			} else {
				die( wp_json_encode( array( 'error' => esc_html__( 'Are you sure this is your website?', 'mainwp' ) ) ) );
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
		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( isset( $_POST['id'] ) ) {

			$result = self::remove_website( intval( $_POST['id'] ) );
			$error  = is_array( $result ) && isset( $result['error'] ) ? $result['error'] : '';

			if ( 'NOMAINWP' === $error ) {
				$error = esc_html__( 'Be sure to deactivate the child plugin on the child site to avoid potential security issues.', 'mainwp' );
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
		// phpcs:enable
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

			$is_demo_wp = MainWP_Demo_Handle::get_instance()->is_demo_website( $website );

			/**
			 * Deactive child plugin on live site only,
			 * DO NOT deactive child on staging site, it will deactive child plugin of source site.
			 */
			if ( ! $website->is_staging && ! $is_demo_wp ) {
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
			! $is_demo_wp && do_action( 'mainwp_delete_site', $website );

			/**
			 * Fires immediately after a new website is deleted.
			 *
			 * @since 4.5.1.1
			 *
			 * @param object   $website  website data.
			 */
			! $is_demo_wp && do_action( 'mainwp_site_deleted', $website );

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
		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
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
		// phpcs:enable
		die( wp_json_encode( array( 'error' => 'NO_SIDE_ID' ) ) );
	}
}
