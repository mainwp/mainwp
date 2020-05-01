<?php
/**
 *  MainWP Post Site Handler.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * MainWP Post Site Handler.
 */
class MainWP_Post_Site_Handler extends MainWP_Post_Base_Handler {

	/** @var $instance Singleton MainWP_Post_Site_Handler */
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
	}

	/**
	 * Method mainwp_group_rename()
	 *
	 * Rename Group.
	 */
	public function mainwp_group_rename() {
		$this->secure_request( 'mainwp_group_rename' );

		MainWP_Manage_Groups::rename_group();
	}

	/**
	 * Method mainwp_group_delete()
	 *
	 * Delete Group.
	 */
	public function mainwp_group_delete() {
		$this->secure_request( 'mainwp_group_delete' );

		MainWP_Manage_Groups::delete_group();
	}

	/**
	 * Method mainwp_group_add()
	 *
	 * Add Group.
	 */
	public function mainwp_group_add() {
		$this->secure_request( 'mainwp_group_add' );

		MainWP_Manage_Groups::add_group();
	}

	/**
	 * Method mainwp_group_getsites()
	 *
	 * Get Child Sites in group.
	 */
	public function mainwp_group_getsites() {
		$this->secure_request( 'mainwp_group_getsites' );

		die( MainWP_Manage_Groups::get_sites() );
	}

	/**
	 * Method mainwp_group_updategroup()
	 *
	 * Update Group.
	 */
	public function mainwp_group_updategroup() {
		$this->secure_request( 'mainwp_group_updategroup' );

		MainWP_Manage_Groups::update_group();
	}

	/**
	 * Method mainwp_checkwp()
	 *
	 * Check if WP can be added.
	 */
	public function mainwp_checkwp() {
		if ( $this->check_security( 'mainwp_checkwp', 'security' ) ) {
			MainWP_Manage_Sites_Handler::check_site();
		} else {
			die( wp_json_encode( array( 'response' => __( 'ERROR: Invalid request!', 'mainwp' ) ) ) );
		}
	}

	/**
	 * Method mainwp_addwp()
	 *
	 * Add WP to the database.
	 */
	public function mainwp_addwp() {
		if ( $this->check_security( 'mainwp_addwp', 'security' ) ) {
			MainWP_Manage_Sites_Handler::add_site();
		} else {
			die( wp_json_encode( array( 'response' => __( 'ERROR: Invalid request!', 'mainwp' ) ) ) );
		}
	}

	/**
	 * Method get_site_icon()
	 *
	 * Get Child Site Favicon.
	 */
	public function get_site_icon() {
		if ( $this->check_security( 'mainwp_get_site_icon', 'security' ) ) {
			$siteId = null;
			if ( isset( $_POST['siteId'] ) ) {
				$siteId = intval( $_POST['siteId'] );
			}
			$result = MainWP_Sync::sync_site_icon( $siteId );
			wp_send_json( $result );
		} else {
			die( wp_json_encode( array( 'error' => __( 'ERROR: Invalid request!', 'mainwp' ) ) ) );
		}
	}

	/**
	 * Method mainwp_testwp()
	 *
	 * Test if Child Site can be reached.
	 *
	 * @return $rslt
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
			$url = $_POST['url'];

			$temp_url = MainWP_Utility::remove_http_prefix( $url, true );

			if ( strpos( $temp_url, ':' ) ) {
				die( wp_json_encode( array( 'error' => __( 'Invalid URL.', 'mainwp' ) ) ) );
			}

			$verifyCertificate = $_POST['test_verify_cert'];
			$forceUseIPv4      = $_POST['test_force_use_ipv4'];
			$sslVersion        = $_POST['test_ssl_version'];
			$http_user         = $_POST['http_user'];
			$http_pass         = $_POST['http_pass'];

		} elseif ( isset( $_POST['siteid'] ) ) {
			$website = MainWP_DB::instance()->get_website_by_id( $_POST['siteid'] );
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
	 */
	public function mainwp_reconnectwp() {
		$this->secure_request( 'mainwp_reconnectwp' );

		MainWP_Manage_Sites_Handler::reconnect_site();
	}

	/**
	 * Method mainwp_updatechildsite_value()
	 *
	 * Update Child Site value.
	 */
	public function mainwp_updatechildsite_value() {
		$this->secure_request( 'mainwp_updatechildsite_value' );

		MainWP_Manage_Sites_Handler::update_child_site_value();
	}

	/**
	 * Method mainwp_syncsites()
	 *
	 * Sync Child Sites.
	 */
	public function mainwp_syncsites() {
		$this->secure_request( 'mainwp_syncsites' );
		MainWP_Updates_Overview::dismiss_sync_errors( false );
		MainWP_Updates_Overview::sync_site();
	}

}
