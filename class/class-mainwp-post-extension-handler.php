<?php
/**
 * This class extends the MainWP Post Base Handler class
 * to add support for MainWP Extensions.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Post_Extension_Handler
 *
 * @package MainWP\Dashboard
 *
 * @uses \MainWP\Dashboard\MainWP_Post_Base_Handler
 */
class MainWP_Post_Extension_Handler extends MainWP_Post_Base_Handler {

	/**
	 * Public static varibale to hold the instance.
	 *
	 * @var null Default value.
	 */
	private static $instance = null;

	/**
	 * Method instance()
	 *
	 * Create public static instance.
	 *
	 * @return self $instance.
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Init extensions actions
	 *
	 * @uses \MainWP\Dashboard\MainWP_Extensions::get_class_name()
	 */
	public function init() {

		$this->add_action( 'mainwp_extension_add_menu', array( &$this, 'add_extension_menu' ) );
		$this->add_action( 'mainwp_extension_remove_menu', array( &$this, 'remove_extension_menu_from_mainwp_menu' ) );

		$this->add_action( 'mainwp_extension_api_activate', array( &$this, 'activate_api_extension' ) );
		$this->add_action( 'mainwp_extension_deactivate', array( &$this, 'deactivate_extension' ) );
		$this->add_action( 'mainwp_extension_testextensionapilogin', array( &$this, 'test_extensions_api_login' ) );

		$this->add_action( 'mainwp_extension_plugin_action', array( &$this, 'ajax_extension_plugin_action' ) );

		if ( mainwp_current_user_have_right( 'dashboard', 'bulk_install_and_activate_extensions' ) ) {
			$this->add_action( 'mainwp_extension_grabapikey', array( &$this, 'grab_extension_api_key' ) );
			$this->add_action( 'mainwp_extension_saveextensionapilogin', array( &$this, 'save_extensions_api_login' ) );
			$this->add_action( 'mainwp_extension_getpurchased', array( MainWP_Extensions::get_class_name(), 'ajax_get_purchased_extensions' ) );
			$this->add_action( 'mainwp_extension_downloadandinstall', array( &$this, 'download_and_install' ) );
			$this->add_action( 'mainwp_extension_bulk_activate', array( &$this, 'bulk_activate' ) );
			$this->add_action( 'mainwp_extension_apisslverifycertificate', array( &$this, 'save_api_ssl_verify' ) );
		}

		// Page: ManageSites.
		$this->add_action( 'mainwp_ext_applypluginsettings', array( &$this, 'mainwp_ext_applypluginsettings' ) );
	}

	/**
	 * Apply plugin settings.
	 *
	 * @return mixed success|error.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Manage_Sites_Handler::apply_plugin_settings()
	 */
	public function mainwp_ext_applypluginsettings() {
		$this->check_security( 'mainwp_ext_applypluginsettings' );
		MainWP_Manage_Sites_Handler::apply_plugin_settings();
	}

	/**
	 * Ajax add extension menu.
	 *
	 * @return void
	 *
	 * @uses \MainWP\Dashboard\MainWP_Extensions_Handler::add_extension_menu()
	 */
	public function add_extension_menu() {
		$this->check_security( 'mainwp_extension_add_menu' );
		$slug = isset( $_POST['slug'] ) ? wp_unslash( $_POST['slug'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		MainWP_Extensions_Handler::add_extension_menu( $slug );
		die( wp_json_encode( array( 'result' => 'SUCCESS' ) ) );
	}

	/**
	 * Activate MainWP Extension.
	 *
	 * @return void
	 *
	 * @uses \MainWP\Dashboard\MainWP_Api_Manager::license_key_activation()
	 * @uses \MainWP\Dashboard\MainWP_Deprecated_Hooks::maybe_handle_deprecated_hook()
	 */
	public function activate_api_extension() {
		$this->check_security( 'mainwp_extension_api_activate' );
		MainWP_Deprecated_Hooks::maybe_handle_deprecated_hook();
		$api_slug = isset( $_POST['slug'] ) ? dirname( wp_unslash( $_POST['slug'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$api_key  = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$result   = MainWP_Api_Manager::instance()->license_key_activation( $api_slug, $api_key );
		wp_send_json( $result );
	}


	/**
	 * Handle MainWP Extension plugin actions.
	 *
	 * @return void
	 */
	public function ajax_extension_plugin_action() {
		$this->check_security( 'mainwp_extension_plugin_action' );
		$plugin_slug = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$action      = isset( $_POST['what'] ) ? sanitize_text_field( wp_unslash( $_POST['what'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! empty( $plugin_slug ) && in_array( $action, array( 'active', 'disable', 'remove' ) ) ) {
			if ( 'disable' === $action ) {
				if ( is_plugin_active( $plugin_slug ) ) {
					deactivate_plugins( $plugin_slug, false );
				}
				wp_send_json( array( 'result' => 'SUCCESS' ) );
			} elseif ( 'active' === $action ) {
				if ( ! is_plugin_active( $plugin_slug ) ) {
					activate_plugin( $plugin_slug, '', false, false );
				}
				wp_send_json( array( 'result' => 'SUCCESS' ) );

			} elseif ( 'remove' === $action ) {
				$status = $this->delete_extension_plugin( $plugin_slug );
				wp_send_json( $status );
			}
		}
		wp_send_json( array( 'error' => esc_html__( 'Invalid data provided.', 'mainwp' ) ) );
	}

	/**
	 * Delete MainWP Extension plugin.
	 *
	 * @param string $plugin_slug plugin slug.
	 *
	 * @return array $status Status result.
	 */
	public function delete_extension_plugin( $plugin_slug ) {

		$status = array();
		if ( ! empty( $plugin_slug ) ) {
			// Check filesystem credentials.
			$url = wp_nonce_url( 'plugins.php?action=delete-selected&verify-delete=1&checked[]=' . $plugin_slug, 'bulk-plugins' );
			ob_start();
			$credentials = request_filesystem_credentials( $url );
			ob_end_clean();

			if ( false === $credentials || ! WP_Filesystem( $credentials ) ) {
				global $wp_filesystem;
				$status['error'] = esc_html__( 'Unable to connect to the filesystem. Please confirm your credentials.', 'mainwp' );
				// Pass through the error from WP_Filesystem if one was raised.
				if ( $wp_filesystem instanceof WP_Filesystem_Base && is_wp_error( $wp_filesystem->errors ) && $wp_filesystem->errors->has_errors() ) {
					$status['error'] = esc_html( $wp_filesystem->errors->get_error_message() );
				}
				return $status;
			}

			$result = delete_plugins( array( $plugin_slug ) );

			if ( is_wp_error( $result ) ) {
				$status['error'] = $result->get_error_message();
				return $status;
			} elseif ( false === $result ) {
				$status['error'] = esc_html__( 'Plugin could not be deleted.', 'mainwp' );
				return $status;
			}
			$status['result'] = 'SUCCESS';

			return $status;
		}
		return $status;
	}


	/**
	 * Deactivate MainWP Extension.
	 *
	 * @return void
	 *
	 * @uses \MainWP\Dashboard\MainWP_Api_Manager::license_key_deactivation()
	 * @uses \MainWP\Dashboard\MainWP_Deprecated_Hooks::maybe_handle_deprecated_hook()
	 */
	public function deactivate_extension() {
		$this->check_security( 'mainwp_extension_deactivate' );
		$api_slug = isset( $_POST['slug'] ) ? dirname( wp_unslash( $_POST['slug'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$api_key  = isset( $_POST['api_key'] ) ? wp_unslash( $_POST['api_key'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$result   = MainWP_Api_Manager::instance()->license_key_deactivation( $api_slug, $api_key );
		wp_send_json( $result );
	}

	/**
	 * Grab MainWP Extension API Key.
	 *
	 * @return void
	 *
	 * @uses \MainWP\Dashboard\MainWP_Api_Manager::grab_license_key()
	 */
	public function grab_extension_api_key() {
		$this->check_security( 'mainwp_extension_grabapikey' );
		$api_slug       = isset( $_POST['slug'] ) ? dirname( wp_unslash( $_POST['slug'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$master_api_key = isset( $_POST['master_api_key'] ) ? wp_unslash( $_POST['master_api_key'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$result         = MainWP_Api_Manager::instance()->grab_license_key( $api_slug, $master_api_key );
		wp_send_json( $result );
	}

	/**
	 * Save MainWP Extensions API Login details for future logins.
	 *
	 * @return void
	 *
	 * @uses \MainWP\Dashboard\MainWP_Api_Manager::verify_mainwp_api()
	 * @uses \MainWP\Dashboard\MainWP_Api_Manager_Password_Management::encrypt_string()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::update_option()
	 */
	public function save_extensions_api_login() { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.
		$this->check_security( 'mainwp_extension_saveextensionapilogin' );
		$api_login_history = isset( $_SESSION['api_login_history'] ) ? $_SESSION['api_login_history'] : array(); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- ok.

		$new_api_login_history = array();
		$requests              = 0;

		foreach ( $api_login_history as $api_login ) {
			if ( $api_login['time'] > ( time() - 1 * 60 ) ) {
				$new_api_login_history[] = $api_login;
				++$requests;
			}
		}

		if ( 4 < $requests ) {
			$_SESSION['api_login_history'] = $new_api_login_history;
			die( wp_json_encode( array( 'error' => esc_html__( 'Too many requests', 'mainwp' ) ) ) );
		} else {
			$new_api_login_history[]       = array( 'time' => time() );
			$_SESSION['api_login_history'] = $new_api_login_history;
		}

		$api_key = isset( $_POST['api_key'] ) ? trim( wp_unslash( $_POST['api_key'] ) ) : false; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( '' === $api_key && false !== $api_key ) {
			MainWP_Keys_Manager::instance()->update_key_value( 'mainwp_extensions_master_api_key', false );
		}

		if ( empty( $api_key ) ) {
			die( wp_json_encode( array( 'saved' => 1 ) ) );
		}

		$result = array();
		try {
			$test = MainWP_Api_Manager::instance()->verify_mainwp_api( $api_key );
		} catch ( \Exception $e ) {
			$return['error'] = $e->getMessage();
			die( wp_json_encode( $return ) );
		}

		if ( is_array( $test ) && isset( $test['retry_action'] ) ) {
			wp_send_json( $test );
		}

		$result     = json_decode( $test, true );
		$save_login = ( isset( $_POST['saveLogin'] ) && ( 1 === (int) $_POST['saveLogin'] ) ) ? true : false; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$return     = array();
		if ( is_array( $result ) ) {
			if ( isset( $result['success'] ) && $result['success'] ) {
				if ( $save_login ) {
					if ( empty( $api_key ) && isset( $result['master_api_key'] ) ) {
						$api_key = $result['master_api_key'];
					}
					MainWP_Keys_Manager::instance()->update_key_value( 'mainwp_extensions_master_api_key', $api_key );
					MainWP_Utility::update_option( 'mainwp_extensions_api_save_login', true );
					$plan_info = isset( $result['plan_info'] ) ? wp_json_encode( $result['plan_info'] ) : '';
					MainWP_Utility::update_option( 'mainwp_extensions_plan_info', $plan_info );
				}
				$return['result'] = 'SUCCESS';
			} elseif ( isset( $result['error'] ) ) {
				$return['error'] = $result['error'];
			}
		}

		if ( ! $save_login ) {
			MainWP_Utility::update_option( 'mainwp_extensions_api_save_login', '' );
			MainWP_Utility::update_option( 'mainwp_extensions_plan_info', '' );
		}

		die( wp_json_encode( $return ) );
	}

	/**
	 * Save whenther or not to verify MainWP API SSL certificate.
	 *
	 * @return void
	 *
	 * @uses  \MainWP\Dashboard\MainWP_Utility::update_option()
	 */
	public function save_api_ssl_verify() {
		$this->check_security( 'mainwp_extension_apisslverifycertificate' );
		MainWP_Utility::update_option( 'mainwp_api_sslVerifyCertificate', isset( $_POST['api_sslverify'] ) ? intval( $_POST['api_sslverify'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		die( wp_json_encode( array( 'saved' => 1 ) ) );
	}

	/**
	 * Test Extension page MainWP.com login details.
	 *
	 * @return void
	 *
	 * @uses \MainWP\Dashboard\MainWP_Api_Manager::verify_mainwp_api()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::update_option()
	 */
	public function test_extensions_api_login() {
		$this->check_security( 'mainwp_extension_testextensionapilogin' );
		$api_key = MainWP_Api_Manager_Key::instance()->get_decrypt_master_api_key();
		$result  = array();
		try {
			$test = MainWP_Api_Manager::instance()->verify_mainwp_api( $api_key );
		} catch ( \Exception $e ) {
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
			if ( 1 === (int) $apisslverify ) {
				MainWP_Utility::update_option( 'mainwp_api_sslVerifyCertificate', 0 );
				$return['retry_action'] = 1;
			}
		}
		wp_send_json( $return );
	}

	/**
	 * Download & Install MainWP Extension.
	 *
	 * @return void
	 *
	 * @uses \MainWP\Dashboard\MainWP_Extensions_Handler::install_plugin()
	 */
	public function download_and_install() {
		$this->check_security( 'mainwp_extension_downloadandinstall' );
		// phpcs:ignore -- custom setting to install plugin.
		ini_set( 'zlib.output_compression', 'Off' );
		$download_link = isset( $_POST['download_link'] ) ? wp_unslash( $_POST['download_link'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$plugin_slug   = isset( $_POST['plugin_slug'] ) ? wp_unslash( $_POST['plugin_slug'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$return = array( 'error' => esc_html__( 'Empty or Invalid request data, please try again.', 'mainwp' ) );

		if ( ! empty( $download_link ) ) {
			$return = MainWP_Extensions_Handler::install_plugin( $download_link );
		} elseif ( ! empty( $plugin_slug ) ) {
			include_once ABSPATH . '/wp-admin/includes/plugin-install.php';
			$api = MainWP_System_Utility::get_plugin_theme_info(
				'plugin',
				array(
					'slug'    => dirname( $plugin_slug ),
					'fields'  => array( 'sections' => false ),
					'timeout' => 60,
				)
			);

			if ( is_object( $api ) && property_exists( $api, 'download_link' ) ) {
				$download_link = $api->download_link;
				$return        = MainWP_Extensions_Handler::install_plugin( $download_link );
			} else {
				$return = array( 'error' => esc_html__( 'No response from the WordPress update server.', 'mainwp' ) );
			}
		}

		die( '<mainwp>' . wp_json_encode( $return ) . '</mainwp>' );
	}

	/**
	 * MainWP Extension Bulck Activation.
	 *
	 * @return void
	 */
	public function bulk_activate() {
		$this->check_security( 'mainwp_extension_bulk_activate' );
		$plugins = isset( $_POST['plugins'] ) ? wp_unslash( $_POST['plugins'] ) : false; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( is_array( $plugins ) && 0 < count( $plugins ) ) {
			if ( current_user_can( 'activate_plugins' ) ) {
				activate_plugins( $plugins );
				die( 'SUCCESS' );
			}
		}
		die( 'FAILED' );
	}

	/**
	 * Remove Extensions menu from MainWP Menu.
	 *
	 * @return void
	 *
	 * @uses  \MainWP\Dashboard\MainWP_Utility::update_option()
	 */
	public function remove_extension_menu_from_mainwp_menu() {
		$this->check_security( 'mainwp_extension_remove_menu' );
		$snMenuExtensions = get_option( 'mainwp_extmenu' );
		if ( ! is_array( $snMenuExtensions ) ) {
			$snMenuExtensions = array();
		}

		$key = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( ! empty( $key ) && isset( $snMenuExtensions[ $key ] ) ) {
			unset( $snMenuExtensions[ $key ] );
			MainWP_Utility::update_option( 'mainwp_extmenu', $snMenuExtensions );
			do_action( 'mainwp_removed_extension_menu', $key );
			die( wp_json_encode( array( 'result' => 'SUCCESS' ) ) );
		}

		die( - 1 );
	}
}
