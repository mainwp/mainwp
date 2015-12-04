<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

/**
 * Todd Lahman LLC Updater - Single Updater Class
 *
 * @package Update API Manager/Update Handler
 * @author Todd Lahman LLC
 * @copyright   Copyright (c) Todd Lahman LLC
 * @since 1.0.0
 *
 */
class MainWP_Api_Manager_Plugin_Update {

	/**
	 * @var The single instance of the class
	 */
	protected static $_instance = null;
	private $text_domain = 'mainwp';

	/**
	 *
	 * Ensures only one instance of SimpleComments is loaded or can be loaded.
	 *
	 * @static
	 * @return class instance
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Constructor.
	 *
	 * @access public
	 * @since  1.0.0
	 * @return void
	 */
	public function __construct() {
		// API data
	}

	// Upgrade API URL
	private function create_upgrade_api_url( $args ) {
		$upgrade_url = esc_url_raw( add_query_arg( 'wc-api', 'upgrade-api', MainWP_Api_Manager::instance()->getUpgradeUrl() ) );
		$query_url   = '';
		foreach ( $args as $key => $value ) {
			$query_url .= $key . '=' . urlencode( $value ) . '&';
		}
		$query_url = rtrim( $query_url, '&' );

		return $upgrade_url . '&' . $query_url; //http_build_query( $args );
	}

	public function update_check( $plugin ) {

		$args = array(
			'request'          => 'pluginupdatecheck',
			'plugin_name'      => $plugin['plugin_name'],
			'version'          => $plugin['software_version'],
			'product_id'       => $plugin['product_id'],
			'api_key'          => $plugin['api_key'],
			'activation_email' => $plugin['activation_email'],
			'instance'         => $plugin['instance'],
			'domain'           => $plugin['domain'],
			'software_version' => $plugin['software_version'],
			'extra'            => isset( $plugin['extra'] ) ? $plugin['extra'] : '',
		);

		// Check for a plugin update
		return $this->plugin_information( $args );
	}

	public function request( $args ) {

		$args['request'] = 'plugininformation';

		$response = $this->plugin_information( $args );

		// If everything is okay return the $response
		if ( isset( $response ) && is_object( $response ) && $response !== false ) {
			return $response;
		}
	}

	/**
	 * Sends and receives data to and from the server API
	 *
	 * @access public
	 * @since  1.0.0
	 * @return object $response
	 */
	public function plugin_information( $args ) {

		$target_url = $this->create_upgrade_api_url( $args );

		$apisslverify = ( ( get_option( 'mainwp_api_sslVerifyCertificate' ) === false ) || ( get_option( 'mainwp_api_sslVerifyCertificate' ) == 1 ) ) ? 1 : 0;

		$request = wp_remote_get( $target_url, array( 'timeout' => 50, 'sslverify' => $apisslverify ) );

		//      $request = wp_remote_post( MainWP_Api_Manager::instance()->getUpgradeUrl() . 'wc-api/upgrade-api/', array('body' => $args) );

		if ( is_wp_error( $request ) || wp_remote_retrieve_response_code( $request ) != 200 ) {
			return false;
		}

		$response = unserialize( wp_remote_retrieve_body( $request ) );

		/**
		 * For debugging errors from the API
		 * For errors like: unserialize(): Error at offset 0 of 170 bytes
		 * Comment out $response above first
		 */
		// $response = wp_remote_retrieve_body( $request );
		// print_r($response); exit;

		if ( is_object( $response ) ) {
			if ( isset( $response->package ) ) {
				$response->package = apply_filters( 'mainwp_api_manager_upgrade_url', $response->package );
			}

			return $response;
		} else {
			return false;
		}
	}


	public function check_response_for_errors( $response, $renew_license_url ) {
		$error = '';
		if ( ! empty( $response ) ) {
			$plugins     = get_plugins();
			$plugin_name = isset( $plugins[ $response->slug ] ) ? $plugins[ $response->slug ]['Name'] : $response->slug;
			if ( isset( $response->errors['no_key'] ) && $response->errors['no_key'] == 'no_key' && isset( $response->errors['no_subscription'] ) && $response->errors['no_subscription'] == 'no_subscription' ) {
				$error = sprintf( __( 'A license key for %s could not be found. Maybe you forgot to enter a license key when setting up %s, or the key was deactivated in your account. You can reactivate or purchase a license key from your account <a href="%s" target="_blank">dashboard</a>.', $this->text_domain ), $plugin_name, $plugin_name, $renew_license_url );
				$error .= sprintf( __( 'A subscription for %s could not be found. You can purchase a subscription from your account <a href="%s" target="_blank">dashboard</a>.', $this->text_domain ), $plugin_name, $renew_license_url );
			} else if ( isset( $response->errors['exp_license'] ) && $response->errors['exp_license'] == 'exp_license' ) {
				$error = sprintf( __( 'The license key for %s has expired. You can reactivate or purchase a license key from your account <a href="%s" target="_blank">dashboard</a>.', $this->text_domain ), $plugin_name, $renew_license_url );
			} else if ( isset( $response->errors['hold_subscription'] ) && $response->errors['hold_subscription'] == 'hold_subscription' ) {
				$error = sprintf( __( 'The subscription for %s is on-hold. You can reactivate the subscription from your account <a href="%s" target="_blank">dashboard</a>.', $this->text_domain ), $plugin_name, $renew_license_url );
			} else if ( isset( $response->errors['cancelled_subscription'] ) && $response->errors['cancelled_subscription'] == 'cancelled_subscription' ) {
				$error = sprintf( __( 'The subscription for %s has been cancelled. You can renew the subscription from your account <a href="%s" target="_blank">dashboard</a>. A new license key will be emailed to you after your order has been completed.', $this->text_domain ), $plugin_name, $renew_license_url );
			} else if ( isset( $response->errors['exp_subscription'] ) && $response->errors['exp_subscription'] == 'exp_subscription' ) {
				$error = sprintf( __( 'The subscription for %s has expired. You can reactivate the subscription from your account <a href="%s" target="_blank">dashboard</a>.', $this->text_domain ), $plugin_name, $renew_license_url );
			} else if ( isset( $response->errors['suspended_subscription'] ) && $response->errors['suspended_subscription'] == 'suspended_subscription' ) {
				$error = sprintf( __( 'The subscription for %s has been suspended. You can reactivate the subscription from your account <a href="%s" target="_blank">dashboard</a>.', $this->text_domain ), $plugin_name, $renew_license_url );
			} else if ( isset( $response->errors['pending_subscription'] ) && $response->errors['pending_subscription'] == 'pending_subscription' ) {
				$error = sprintf( __( 'The subscription for %s is still pending. You can check on the status of the subscription from your account <a href="%s" target="_blank">dashboard</a>.', $this->text_domain ), $plugin_name, $renew_license_url );
			} else if ( isset( $response->errors['trash_subscription'] ) && $response->errors['trash_subscription'] == 'trash_subscription' ) {
				$error = sprintf( __( 'The subscription for %s has been placed in the trash and will be deleted soon. You can purchase a new subscription from your account <a href="%s" target="_blank">dashboard</a>.', $this->text_domain ), $plugin_name, $renew_license_url );
			} else if ( isset( $response->errors['no_subscription'] ) && $response->errors['no_subscription'] == 'no_subscription' ) {
				$error = sprintf( __( 'A subscription for %s could not be found. You can purchase a subscription from your account <a href="%s" target="_blank">dashboard</a>.', $this->text_domain ), $plugin_name, $renew_license_url );
			} else if ( isset( $response->errors['no_activation'] ) && $response->errors['no_activation'] == 'no_activation' ) {
				$error = sprintf( __( '%s has not been activated. Go to the settings page and enter the license key and license email to activate %s.', $this->text_domain ), $plugin_name, $plugin_name );
			} else if ( isset( $response->errors['no_key'] ) && $response->errors['no_key'] == 'no_key' ) {
				$error = sprintf( __( 'A license key for %s could not be found. Maybe you forgot to enter a license key when setting up %s, or the key was deactivated in your account. You can reactivate or purchase a license key from your account <a href="%s" target="_blank">dashboard</a>.', $this->text_domain ), $plugin_name, $plugin_name, $renew_license_url );
			} else if ( isset( $response->errors['download_revoked'] ) && $response->errors['download_revoked'] == 'download_revoked' ) {
				$error = sprintf( __( 'Download permission for %s has been revoked possibly due to a license key or subscription expiring. You can reactivate or purchase a license key from your account <a href="%s" target="_blank">dashboard</a>.', $this->text_domain ), $plugin_name, $renew_license_url );
			} else if ( isset( $response->errors['switched_subscription'] ) && $response->errors['switched_subscription'] == 'switched_subscription' ) {
				$error = sprintf( __( 'You changed the subscription for %s, so you will need to enter your new API License Key in the settings page. The License Key should have arrived in your email inbox, if not you can get it by logging into your account <a href="%s" target="_blank">dashboard</a>.', $this->text_domain ), $plugin_name, $renew_license_url );
			}
		}

		return $error;

	}
} // End of class
