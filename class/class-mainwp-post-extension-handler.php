<?php
namespace MainWP\Dashboard;

/**
 * MainWP Post Handler
 */
class MainWP_Post_Extension_Handler extends MainWP_Handler {

	private static $instance = null;

	public static function instance() {
		if ( null == self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Init extensions actions
	 */
	public function init() {

		$this->add_action( 'mainwp_extension_add_menu', array( &$this, 'add_extension_menu' ) );
		$this->add_action( 'mainwp_extension_remove_menu', array( &$this, 'remove_extension_menu_from_mainwp_menu' ) );

		$this->add_action( 'mainwp_extension_activate', array( &$this, 'activate_extension' ) );
		$this->add_action( 'mainwp_extension_deactivate', array( &$this, 'deactivate_extension' ) );
		$this->add_action( 'mainwp_extension_testextensionapilogin', array( &$this, 'test_extensions_api_login' ) );

		if ( mainwp_current_user_can( 'dashboard', 'bulk_install_and_activate_extensions' ) ) {
			$this->add_action( 'mainwp_extension_grabapikey', array( &$this, 'grab_extension_api_key' ) );
			$this->add_action( 'mainwp_extension_saveextensionapilogin', array( &$this, 'save_extensions_api_login' ) );
			$this->add_action( 'mainwp_extension_getpurchased', array( &$this, 'get_purchased_exts' ) );
			$this->add_action( 'mainwp_extension_downloadandinstall', array( &$this, 'download_and_install' ) );
			$this->add_action( 'mainwp_extension_bulk_activate', array( &$this, 'bulk_activate' ) );
			$this->add_action( 'mainwp_extension_apisslverifycertificate', array( &$this, 'save_api_ssl_verify' ) );
		}

		// Page: ManageSites.
		$this->add_action( 'mainwp_ext_applypluginsettings', array( &$this, 'mainwp_ext_applypluginsettings' ) );
	}

	public function mainwp_ext_applypluginsettings() {
		if ( $this->check_security( 'mainwp_ext_applypluginsettings', 'security' ) ) {
			MainWP_Manage_Sites_Handler::apply_plugin_settings();
		} else {
			die( wp_json_encode( array( 'error' => __( 'ERROR: Invalid request!', 'mainwp' ) ) ) );
		}
	}

	/** Ajax add extension menu. */
	public function add_extension_menu() {
		$this->check_security( 'mainwp_extension_add_menu' );
		MainWP_Extensions_Handler::add_extension_menu( $_POST['slug'] );
		die( wp_json_encode( array( 'result' => 'SUCCESS' ) ) );
	}


	/** Activate MainWP Extension. */
	public function activate_extension() {
		$this->check_security( 'mainwp_extension_activate' );
		$api       = dirname( $_POST['slug'] );
		$api_key   = trim( $_POST['key'] );
		$api_email = trim( $_POST['email'] );
		$result    = MainWP_Api_Manager::instance()->license_key_activation( $api, $api_key, $api_email );
		wp_send_json( $result );
	}

	/** Deactivate MainWP Extension. */
	public function deactivate_extension() {
		$this->check_security( 'mainwp_extension_deactivate' );
		$api    = dirname( $_POST['slug'] );
		$result = MainWP_Api_Manager::instance()->license_key_deactivation( $api );
		wp_send_json( $result );
	}

	/** Grab MainWP Extension API Key. */
	public function grab_extension_api_key() {
		$this->check_security( 'mainwp_extension_grabapikey' );
		$username = trim( $_POST['username'] );
		$password = trim( $_POST['password'] );
		$api      = dirname( $_POST['slug'] );
		$result   = MainWP_Api_Manager::instance()->grab_license_key( $api, $username, $password );
		wp_send_json( $result );
	}

	/** Save MainWP Extensions API Login details for future logins. */
	public function save_extensions_api_login() {
		$this->check_security( 'mainwp_extension_saveextensionapilogin' );
		$api_login_history = isset( $_SESSION['api_login_history'] ) ? $_SESSION['api_login_history'] : array();

		$new_api_login_history = array();
		$requests              = 0;

		foreach ( $api_login_history as $api_login ) {
			if ( $api_login['time'] > ( time() - 1 * 60 ) ) {
				$new_api_login_history[] = $api_login;
				$requests++;
			}
		}

		if ( 4 < $requests ) {
			$_SESSION['api_login_history'] = $new_api_login_history;
			die( wp_json_encode( array( 'error' => __( 'Too many requests', 'mainwp' ) ) ) );
		} else {
			$new_api_login_history[]       = array( 'time' => time() );
			$_SESSION['api_login_history'] = $new_api_login_history;
		}

		$username = trim( $_POST['username'] );
		$password = trim( $_POST['password'] );
		if ( ( '' === $username ) && ( '' === $password ) ) {
			MainWP_Utility::update_option( 'mainwp_extensions_api_username', $username );
			MainWP_Utility::update_option( 'mainwp_extensions_api_password', $password );
			die( wp_json_encode( array( 'saved' => 1 ) ) );
		}
		$result = array();
		try {
			$test = MainWP_Api_Manager::instance()->test_login_api( $username, $password );
		} catch ( Exception $e ) {
			$return['error'] = $e->getMessage();
			die( wp_json_encode( $return ) );
		}

		if ( is_array( $test ) && isset( $test['retry_action'] ) ) {
			wp_send_json( $test );
		}

		$result     = json_decode( $test, true );
		$save_login = ( isset( $_POST['saveLogin'] ) && ( '1' === $_POST['saveLogin'] ) ) ? true : false;
		$return     = array();
		if ( is_array( $result ) ) {
			if ( isset( $result['success'] ) && $result['success'] ) {
				if ( $save_login ) {
					$enscrypt_u = MainWP_Api_Manager_Password_Management::encrypt_string( $username );
					$enscrypt_p = MainWP_Api_Manager_Password_Management::encrypt_string( $password );
					MainWP_Utility::update_option( 'mainwp_extensions_api_username', $enscrypt_u );
					MainWP_Utility::update_option( 'mainwp_extensions_api_password', $enscrypt_p );
					MainWP_Utility::update_option( 'mainwp_extensions_api_save_login', true );
				}
				$return['result'] = 'SUCCESS';
			} elseif ( isset( $result['error'] ) ) {
				$return['error'] = $result['error'];
			}
		}

		if ( ! $save_login ) {
			MainWP_Utility::update_option( 'mainwp_extensions_api_username', '' );
			MainWP_Utility::update_option( 'mainwp_extensions_api_password', '' );
			MainWP_Utility::update_option( 'mainwp_extensions_api_save_login', '' );
		}

		die( wp_json_encode( $return ) );
	}

	/** Save whenther or not to verify MainWP API SSL certificate. */
	public function save_api_ssl_verify() {
		$this->check_security( 'mainwp_extension_apisslverifycertificate' );
		MainWP_Utility::update_option( 'mainwp_api_sslVerifyCertificate', intval( $_POST['api_sslverify'] ) );
		die( wp_json_encode( array( 'saved' => 1 ) ) );
	}

	/** Test Extension page MainWP.com login details. */
	public function test_extensions_api_login() {
		$this->check_security( 'mainwp_extension_testextensionapilogin' );
		$enscrypt_u = get_option( 'mainwp_extensions_api_username' );
		$enscrypt_p = get_option( 'mainwp_extensions_api_password' );
		$username   = ! empty( $enscrypt_u ) ? MainWP_Api_Manager_Password_Management::decrypt_string( $enscrypt_u ) : '';
		$password   = ! empty( $enscrypt_p ) ? MainWP_Api_Manager_Password_Management::decrypt_string( $enscrypt_p ) : '';

		if ( ( '' === $username ) || ( '' === $password ) ) {
			die( wp_json_encode( array( 'error' => __( 'Login Invalid.', 'mainwp' ) ) ) );
		}

		$result = array();
		try {
			$test = MainWP_Api_Manager::instance()->test_login_api( $username, $password );
		} catch ( Exception $e ) {
			$return['error'] = $e->getMessage();
			die( wp_json_encode( $return ) );
		}

		if ( is_array( $test ) && isset( $test['retry_action'] ) ) {
			wp_send_json( $test );
		}

		$result = json_decode( $test, true );
		$return = array();
		if ( is_array( $result ) ) {
			if ( isset( $result['success'] ) && $result['success'] ) {
				$return['result'] = 'SUCCESS';
			} elseif ( isset( $result['error'] ) ) {
				$return['error'] = $result['error'];
			}
		} else {
			$apisslverify = get_option( 'mainwp_api_sslVerifyCertificate' );
			if ( 1 == $apisslverify ) {
				MainWP_Utility::update_option( 'mainwp_api_sslVerifyCertificate', 0 );
				$return['retry_action'] = 1;
			}
		}
		wp_send_json( $return );
	}


	/** Download & Install MainWP Extension. */
	public function download_and_install() {
		$this->check_security( 'mainwp_extension_downloadandinstall' );
		// phpcs:ignore -- custom setting to install plugin
		ini_set( 'zlib.output_compression', 'Off' );

		$return = MainWP_Extensions_Handler::install_plugin( $_POST['download_link'] );

		die( '<mainwp>' . wp_json_encode( $return ) . '</mainwp>' );
	}

	/** MainWP Extension Bulck Activation. */
	public function bulk_activate() {
		$this->check_security( 'mainwp_extension_bulk_activate' );
		$plugins = $_POST['plugins'];
		if ( is_array( $plugins ) && 0 < count( $plugins ) ) {
			if ( current_user_can( 'activate_plugins' ) ) {
				activate_plugins( $plugins );
				die( 'SUCCESS' );
			}
		}
		die( 'FAILED' );
	}

	/** Remove Extensions menu from MainWP Menu. */
	public function remove_extension_menu_from_mainwp_menu() {
		$this->check_security( 'mainwp_extension_remove_menu' );
		$snMenuExtensions = get_option( 'mainwp_extmenu' );
		if ( ! is_array( $snMenuExtensions ) ) {
			$snMenuExtensions = array();
		}

		$key = array_search( $_POST['slug'], $snMenuExtensions );

		if ( false !== $key ) {
			unset( $snMenuExtensions[ $key ] );
		}

		MainWP_Utility::update_option( 'mainwp_extmenu', $snMenuExtensions );
		do_action( 'mainwp_removed_extension_menu', $_POST['slug'] );
		die( wp_json_encode( array( 'result' => 'SUCCESS' ) ) );
	}


}
