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
		if ( null == self::$instance ) {
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
	 * Method mainwp_group_getsites()
	 *
	 * Get Child Sites in group.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Manage_Groups::get_sites()
	 */
	public function mainwp_group_getsites() {
		$this->secure_request( 'mainwp_group_getsites' );

		die( MainWP_Manage_Groups::get_sites() );
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
	 * @uses \MainWP\Dashboard\MainWP_Sync::sync_site_icon()
	 */
	public function get_site_icon() {
		$this->check_security( 'mainwp_get_site_icon', 'security' );
		$siteId = null;
		if ( isset( $_POST['siteId'] ) ) {
			$siteId = intval( $_POST['siteId'] );
		}
		$result = MainWP_Sync::sync_site_icon( $siteId );
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
	public function mainwp_testwp() {
		$this->secure_request( 'mainwp_testwp' );

		$url               = null;
		$name              = null;
		$http_user         = null;
		$http_pass         = null;
		$verifyCertificate = 1;
		$sslVersion        = 0;

		if ( isset( $_POST['url'] ) ) {
			$url = sanitize_text_field( wp_unslash( $_POST['url'] ) );

			$temp_url = MainWP_Utility::remove_http_prefix( $url, true );

			if ( strpos( $temp_url, ':' ) ) {
				die( wp_json_encode( array( 'error' => __( 'Invalid URL.', 'mainwp' ) ) ) );
			}

			$verifyCertificate = isset( $_POST['test_verify_cert'] ) ? sanitize_text_field( wp_unslash( $_POST['test_verify_cert'] ) ) : false;
			$forceUseIPv4      = isset( $_POST['test_force_use_ipv4'] ) ? sanitize_text_field( wp_unslash( $_POST['test_force_use_ipv4'] ) ) : false;
			$sslVersion        = isset( $_POST['test_ssl_version'] ) ? sanitize_text_field( wp_unslash( $_POST['test_ssl_version'] ) ) : false;
			$http_user         = isset( $_POST['http_user'] ) ? sanitize_text_field( wp_unslash( $_POST['http_user'] ) ) : '';
			$http_pass         = isset( $_POST['http_pass'] ) ? wp_unslash( $_POST['http_pass'] ) : '';

		} elseif ( isset( $_POST['siteid'] ) ) {
			$website = MainWP_DB::instance()->get_website_by_id( intval( $_POST['siteid'] ) );
			if ( $website ) {
				$url               = $website->url;
				$name              = $website->name;
				$verifyCertificate = $website->verify_certificate;
				$forceUseIPv4      = $website->force_use_ipv4;
				$sslVersion        = $website->ssl_version;
				$http_user         = $website->http_user;
				$http_pass         = $website->http_pass;
			}
		}

		$rslt = MainWP_Connect::try_visit( $url, $verifyCertificate, $http_user, $http_pass, $sslVersion, $forceUseIPv4 );

		if ( isset( $rslt['error'] ) && ( '' !== $rslt['error'] ) && ( 'wp-admin/' !== substr( $url, - 9 ) ) ) {
			if ( substr( $url, - 1 ) != '/' ) {
				$url .= '/';
			}
			$url    .= 'wp-admin/';
			$newrslt = MainWP_Connect::try_visit( $url, $verifyCertificate, $http_user, $http_pass, $sslVersion, $forceUseIPv4 );
			if ( isset( $newrslt['error'] ) && ( '' !== $rslt['error'] ) ) {
				$rslt = $newrslt;
			}
		}

		if ( null != $name ) {
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
			die( wp_json_encode( array( 'error' => mainwp_do_not_have_permissions( __( 'delete sites', 'mainwp' ), false ) ) ) );
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
	 * @uses \MainWP\Dashboard\MainWP_Updates_Overview::sync_site()
	 */
	public function mainwp_syncsites() {
		$this->secure_request( 'mainwp_syncsites' );
		MainWP_Updates_Overview::dismiss_sync_errors( false );
		MainWP_Updates_Overview::sync_site();
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

}
