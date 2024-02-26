<?php
/**
 *  MainWP Post Site Handler.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Post_Site_Handler
 *
 * @package MainWP\Dashboard
 *
 * @uses \MainWP\Dashboard\MainWP_Post_Base_Handler
 */
class MainWP_Post_Site_Handler extends MainWP_Post_Base_Handler {

	/**
	 * Private static variable to hold the single instance of the class.
	 *
	 * @static
	 *
	 * @var mixed Default null
	 */
	private static $instance = null;

	/**
	 * Method instance()
	 *
	 * Create MainWP Post Site Handler instance.
	 *
	 * @static
	 * @return self $instance MainWP_Post_Site_Handler.
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Init site actions
	 */
	public function init() {
		// Page: ManageSites.
		$this->add_action( 'mainwp_checkwp', array( &$this, 'mainwp_checkwp' ) );
		$this->add_action( 'mainwp_addwp', array( &$this, 'mainwp_addwp' ) );
		$this->add_action( 'mainwp_get_site_icon', array( &$this, 'get_site_icon' ) );
		$this->add_action( 'mainwp_check_abandoned', array( &$this, 'check_abandoned' ) );

		if ( mainwp_current_user_have_right( 'dashboard', 'test_connection' ) ) {
			$this->add_action( 'mainwp_testwp', array( &$this, 'mainwp_testwp' ) );
		}

		$this->add_action( 'mainwp_removesite', array( &$this, 'mainwp_removesite' ) );
		$this->add_action( 'mainwp_reconnectwp', array( &$this, 'mainwp_reconnectwp' ) );
		$this->add_action( 'mainwp_updatechildsite_value', array( &$this, 'mainwp_updatechildsite_value' ) );

		// Page: ManageGroups.
		$this->add_action( 'mainwp_group_rename', array( &$this, 'mainwp_group_rename' ) );
		$this->add_action( 'mainwp_group_delete', array( &$this, 'mainwp_group_delete' ) );
		$this->add_action( 'mainwp_group_add', array( &$this, 'mainwp_group_add' ) );

		$this->add_action( 'mainwp_group_getsites', array( &$this, 'mainwp_group_getsites' ) );
		$this->add_action( 'mainwp_group_updategroup', array( &$this, 'mainwp_group_updategroup' ) );

		// Widget: RightNow.
		$this->add_action( 'mainwp_syncsites', array( &$this, 'mainwp_syncsites' ) );

		$this->add_action( 'mainwp_checksites', array( &$this, 'mainwp_checksites' ) );
		$this->add_action( 'mainwp_manage_sites_suspend_site', array( &$this, 'manage_suspend_site' ) );
		$this->add_action( 'mainwp_group_sites_add', array( &$this, 'ajax_group_sites_add' ) );
	}

	/**
	 * Method mainwp_group_rename()
	 *
	 * Rename Group.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Manage_Groups::rename_group()
	 */
	public function mainwp_group_rename() {
		$this->secure_request( 'mainwp_group_rename' );

		MainWP_Manage_Groups::rename_group();
	}

	/**
	 * Method mainwp_group_delete()
	 *
	 * Delete Group.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Manage_Groups::delete_group()
	 */
	public function mainwp_group_delete() {
		$this->secure_request( 'mainwp_group_delete' );

		MainWP_Manage_Groups::delete_group();
	}

	/**
	 * Method mainwp_group_add()
	 *
	 * Add Group.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Manage_Groups::add_group()
	 */
	public function mainwp_group_add() {
		$this->secure_request( 'mainwp_group_add' );
		MainWP_Manage_Groups::add_group();
	}

	/**
	 * Method ajax_group_sites_add()
	 *
	 * Add Group in modal.
	 */
	public function ajax_group_sites_add() {
		$this->secure_request( 'mainwp_group_sites_add' );
		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$newName  = isset( $_POST['newName'] ) ? sanitize_text_field( wp_unslash( $_POST['newName'] ) ) : '';
		$newColor = isset( $_POST['newColor'] ) ? sanitize_hex_color( wp_unslash( $_POST['newColor'] ) ) : '';
		if ( empty( $newColor ) && ! empty( $tmp_color ) ) {
			$newColor = '#cce2ff'; // default.
		}
		$selected_sites = isset( $_POST['selected_sites'] ) && is_array( $_POST['selected_sites'] ) ? array_map( 'intval', wp_unslash( $_POST['selected_sites'] ) ) : array();
		$selected_sites = array_filter( $selected_sites );
		// phpcs:enable
		$success = false;
		if ( ! empty( $newName ) ) {
			$success = MainWP_Manage_Groups::add_group_sites( $newName, $selected_sites, $newColor );
		}
		if ( ! $success ) {
			wp_die( wp_json_encode( array( 'error' => esc_html__( 'Unexpected error occurred. Please try again.', 'mainwp' ) ) ) );
		} else {
			wp_die( wp_json_encode( array( 'success' => 1 ) ) );
		}
	}


	/**
	 * Method mainwp_group_getsites()
	 *
	 * Get Child Sites in group.
	 */
	public function mainwp_group_getsites() {

		$this->secure_request( 'mainwp_group_getsites' );
		//phpcs:disable WordPress.Security.NonceVerification.Missing
		$groupid = isset( $_POST['groupId'] ) && ! empty( $_POST['groupId'] ) ? intval( $_POST['groupId'] ) : false; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		//phpcs:enable

		if ( $groupid ) {
			$group = MainWP_DB_Common::instance()->get_group_by_id( $groupid );
			if ( ! empty( $group ) ) {
				$websites   = MainWP_DB::instance()->get_websites_by_group_id( $group->id );
				$websiteIds = array();
				if ( ! empty( $websites ) ) {
					foreach ( $websites as $website ) {
						$websiteIds[] = $website->id;
					}
				}

				die( wp_json_encode( $websiteIds ) );  // phpcs:ignore WordPress.Security.EscapeOutput
			}
		}
		die( 'ERROR' );
	}

	/**
	 * Method mainwp_group_updategroup()
	 *
	 * Update Group.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Manage_Groups::update_group()
	 */
	public function mainwp_group_updategroup() {
		$this->secure_request( 'mainwp_group_updategroup' );

		MainWP_Manage_Groups::update_group();
	}

	/**
	 * Method mainwp_checkwp()
	 *
	 * Check if WP can be added.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Manage_Sites_Handler::check_site()
	 */
	public function mainwp_checkwp() {
		$this->check_security( 'mainwp_checkwp', 'security' );
		MainWP_Manage_Sites_Handler::check_site();
	}

	/**
	 * Method mainwp_addwp()
	 *
	 * Add WP to the database.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Manage_Sites_Handler::add_site()
	 */
	public function mainwp_addwp() {
		$this->check_security( 'mainwp_addwp', 'security' );
		MainWP_Manage_Sites_Handler::add_site();
	}

	/**
	 * Method get_site_icon()
	 *
	 * Get Child Site Favicon.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Sync::get_wp_icon()
	 */
	public function get_site_icon() {
		$this->check_security( 'mainwp_get_site_icon', 'security' );
		$siteId = isset( $_POST['siteId'] ) ? intval( $_POST['siteId'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$result = MainWP_Sync::get_wp_icon( $siteId );
		wp_send_json( $result );
	}

	/**
	 * Method check_abandoned()
	 *
	 * Check abandoned plugins or themes.
	 */
	public function check_abandoned() {
		$this->check_security( 'mainwp_check_abandoned', 'security' );
		$siteId = isset( $_POST['siteId'] ) ? intval( $_POST['siteId'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$which  = isset( $_POST['which'] ) ? sanitize_text_field( wp_unslash( $_POST['which'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$result = MainWP_Utility::check_abandoned( $siteId, $which );
		wp_send_json( $result );
	}

	/**
	 * Method mainwp_testwp()
	 *
	 * Test if Child Site can be reached.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Connect::try_visit()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::remove_http_prefix()
	 */
	public function mainwp_testwp() { // phpcs:ignore -- complex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated.
		$this->secure_request( 'mainwp_testwp' );

		$url               = null;
		$name              = null;
		$http_user         = null;
		$http_pass         = null;
		$verifyCertificate = 1;
		$sslVersion        = 0;

		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( isset( $_POST['url'] ) ) {
			$url = sanitize_text_field( wp_unslash( $_POST['url'] ) );
			$url = urldecode( $url );

			$invalid = false;
			$info    = wp_parse_url( $url );

			$def_not_allow   = array( 21, 22 ); // not allow ports 21, 22.
			$not_allow_ports = apply_filters( 'mainwp_connect_sites_not_allow_ports', $def_not_allow, $url );

			if ( ! is_array( $not_allow_ports ) ) {
				$not_allow_ports = $def_not_allow;
			}

			if ( is_array( $info ) && ! empty( $info['port'] ) && ( in_array( intval( $info['port'] ), $not_allow_ports, true ) ) ) {
				$invalid = true;
			}

			$temp_url = MainWP_Utility::remove_http_prefix( $url, true );

			if ( $invalid || false !== strpos( $url, '?=' ) ) {
				die( wp_json_encode( array( 'error' => esc_html__( 'Invalid URL.', 'mainwp' ) ) ) );
			}

			if ( strpos( $temp_url, ':' ) ) {
				$invalid     = true;
				$allow_ports = apply_filters( 'mainwp_connect_sites_allow_ports', array(), $url );
				if ( ! empty( $allow_ports ) && is_array( $allow_ports ) ) {
					if ( is_array( $info ) && ! empty( $info['port'] ) && ( in_array( intval( $info['port'] ), $allow_ports, true ) ) ) {
						$invalid = false;
					}
				}
				if ( $invalid ) {
					die( wp_json_encode( array( 'error' => esc_html__( 'Invalid URL.', 'mainwp' ) ) ) );
				}
			}

			$verifyCertificate = isset( $_POST['test_verify_cert'] ) ? intval( $_POST['test_verify_cert'] ) : 1;
			$forceUseIPv4      = apply_filters( 'mainwp_manage_sites_force_use_ipv4', false, $url );
			$sslVersion        = isset( $_POST['ssl_version'] ) ? intval( $_POST['ssl_version'] ) : 0;
			$http_user         = isset( $_POST['http_user'] ) ? sanitize_text_field( wp_unslash( $_POST['http_user'] ) ) : '';
			$http_pass         = isset( $_POST['http_pass'] ) ? wp_unslash( $_POST['http_pass'] ) : '';

		} elseif ( isset( $_POST['siteid'] ) ) {
			$website = MainWP_DB::instance()->get_website_by_id( intval( $_POST['siteid'] ) );
			if ( $website ) {
				$url               = $website->url;
				$name              = $website->name;
				$verifyCertificate = (int) $website->verify_certificate;
				$forceUseIPv4      = $website->force_use_ipv4;
				$sslVersion        = $website->ssl_version;
				$http_user         = $website->http_user;
				$http_pass         = $website->http_pass;
			}
		}
		// phpcs:enable

		$ssl_verifyhost = false;

		if ( 1 === $verifyCertificate ) {
			$ssl_verifyhost = true;
		} elseif ( 2 === $verifyCertificate ) {
			if ( ( ( false === get_option( 'mainwp_sslVerifyCertificate' ) ) || ( 1 === (int) get_option( 'mainwp_sslVerifyCertificate' ) ) ) ) {
				$ssl_verifyhost = true;
			}
		}

		$rslt = MainWP_Connect::try_visit( $url, $ssl_verifyhost, $http_user, $http_pass, $sslVersion, $forceUseIPv4 );

		if ( isset( $rslt['error'] ) && ( '' !== $rslt['error'] ) && ( 'wp-admin/' !== substr( $url, - 9 ) ) ) {
			if ( substr( $url, - 1 ) !== '/' ) {
				$url .= '/';
			}
			$url    .= 'wp-admin/';
			$newrslt = MainWP_Connect::try_visit( $url, $ssl_verifyhost, $http_user, $http_pass, $sslVersion, $forceUseIPv4 );
			if ( isset( $newrslt['error'] ) && ( '' !== $rslt['error'] ) ) {
				$rslt = $newrslt;
			}
		}

		if ( null !== $name ) {
			$rslt['sitename'] = esc_html( $name );
		}

		wp_send_json( $rslt );
	}

	/**
	 * Method mainwp_removesite()
	 *
	 * Remove a website from MainWP.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Manage_Sites_Handler::remove_site()
	 */
	public function mainwp_removesite() {
		if ( ! mainwp_current_user_have_right( 'dashboard', 'delete_sites' ) ) {
			die( wp_json_encode( array( 'error' => mainwp_do_not_have_permissions( esc_html__( 'delete sites', 'mainwp' ), false ) ) ) );
		}

		$this->secure_request( 'mainwp_removesite' );

		MainWP_Manage_Sites_Handler::remove_site();
	}

	/**
	 * Method mainwp_reconnectwp()
	 *
	 * Reconnect to Child Site.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Manage_Sites_Handler::reconnect_site()
	 */
	public function mainwp_reconnectwp() {
		$this->secure_request( 'mainwp_reconnectwp' );

		MainWP_Manage_Sites_Handler::reconnect_site();
	}

	/**
	 * Method mainwp_updatechildsite_value()
	 *
	 * Update Child Site value.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Manage_Sites_Handler::update_child_site_value()
	 */
	public function mainwp_updatechildsite_value() {
		$this->secure_request( 'mainwp_updatechildsite_value' );

		MainWP_Manage_Sites_Handler::update_child_site_value();
	}

	/**
	 * Method mainwp_syncsites()
	 *
	 * Sync Child Sites.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Updates_Overview::dismiss_sync_errors()
	 */
	public function mainwp_syncsites() {
		$this->secure_request( 'mainwp_syncsites' );
		MainWP_Updates_Overview::dismiss_sync_errors( false );

		$website = null;
		$wp_id   = isset( $_POST['wp_id'] ) ? intval( $_POST['wp_id'] ) : false; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( $wp_id ) {
			$website = MainWP_DB::instance()->get_website_by_id( $wp_id );
		}

		if ( null === $website ) {
			die( wp_json_encode( array( 'error' => esc_html__( 'Site ID not found. Please reload the page and try again.', 'mainwp' ) ) ) );
		}

		if ( MainWP_Sync::sync_website( $website ) ) {
			$website = MainWP_DB::instance()->get_website_by_id( $website->id ); // reload.
			/**
			 * Fires immediately after website synced successfully.
			 *
			 * @since 4.6
			 *
			 * @param object $website  website data.
			 */
			do_action( 'mainwp_after_sync_site_success', $website );
			die( wp_json_encode( array( 'result' => 'SUCCESS' ) ) );
		}

		$website = MainWP_DB::instance()->get_website_by_id( $website->id );

		die( wp_json_encode( array( 'error' => esc_html( wp_strip_all_tags( $website->sync_errors ) ) ) ) );
	}

	/**
	 * Method mainwp_checksites()
	 *
	 * Check Child Sites.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Monitoring_Handler::ajax_check_status_site()
	 */
	public function mainwp_checksites() {
		$this->secure_request( 'mainwp_checksites' );
		MainWP_Monitoring_Handler::ajax_check_status_site();
	}

	/**
	 * Method mainwp_manage_sites_suspend_site()
	 *
	 * Check Child Sites.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Sync::get_wp_icon()
	 */
	public function manage_suspend_site() {
		$this->secure_request( 'mainwp_manage_sites_suspend_site' );
		$siteId    = isset( $_POST['siteid'] ) ? intval( $_POST['siteid'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$suspended = isset( $_POST['suspended'] ) && '1' === $_POST['suspended'] ? 1 : 0; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$newValues = array(
			'suspended' => $suspended,
		);

		if ( $siteId ) {
			$website = MainWP_DB::instance()->get_website_by_id( $siteId );
			if ( $website && $website->suspended != $suspended ) { //phpcs:ignore -- to valid.
				MainWP_DB::instance()->update_website_values( $siteId, $newValues );
				/**
				 * Fires immediately after website suspended/unsuspend.
				 *
				 * @since 4.5.1.1
				 *
				 * @param object $website  website data.
				 * @param int $suspended The new suspended value.
				 */
				do_action( 'mainwp_site_suspended', $website, $suspended );
			}
			wp_send_json( array( 'result' => 'success' ) );
		} else {
			wp_send_json( array( 'error' => 'Error: site id empty' ) );
		}
	}
}
