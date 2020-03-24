<?php
namespace MainWP\Dashboard;

/**
 * MainWP API Manager
 *
 * This class handles MainWP API.
 *
 * @package MainWP/MainWP API Manager
 * @author Todd Lahman LLC
 * @copyright   Copyright (c) Todd Lahman LLC
 * @since 1.0.0
 */

/**
 * MainWP API Manager
 */
class MainWP_Api_Manager {


	/**
	 * @var string Upgrade Url
	 */
	private $upgrade_url = 'https://mainwp.com/';

	/**
	 * @var string Renew License Url
	 */
	private $renew_license_url = 'https://mainwp.com/my-account';

	/**
	 * @var string MainWP Installation Domain Name.
	 */
	public $domain = '';

	/**
	 * @var The single instance of the class
	 */
	protected static $_instance = null;

	/**
	 * Method instance()
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
	 * Method __clone()
	 *
	 * Cloning is forbidden.
	 *
	 * @since 1.2
	 */
	private function __clone() {
	}

	/**
	 * Method __wakeup()
	 *
	 * Unserializing instances of this class is forbidden.
	 *
	 * @since 1.2
	 */
	private function __wakeup() {
	}

	/**
	 * Method __construct()
	 *
	 * Replace http procol to https
	 */
	public function __construct() {
		$this->domain = str_ireplace( array( 'http://', 'https://' ), '', home_url() );
	}

	/**
	 * Method get_domain()
	 *
	 * Get domain.
	 */
	public function get_domain() {
		return $this->domain;
	}

	/**
	 * Method Get Upgrade Url
	 *
	 * Grabs the correct upgrade url.
	 */
	public function getUpgradeUrl() {
		$url = apply_filters( 'mainwp_api_manager_upgrade_url', $this->upgrade_url );
		return $url;
	}

	/**
	 * Method Get Activation Info
	 *
	 * @param mixed $ext_key
	 *
	 * @return mixed
	 */
	public function get_activation_info( $ext_key ) {
		if ( empty($ext_key) ) {
			return array();
		}

		return get_option( $ext_key . '_APIManAdder' );
	}

	/**
	 * Method set_activation_info()
	 *
	 * Stores activation info.
	 *
	 * @param mixed $ext_key
	 * @param mixed $info
	 *
	 * @return mixed
	 */
	public function set_activation_info( $ext_key, $info ) {

		if ( empty($ext_key) ) {
			return false;
		}

		// Clear cached of all activations to reload for next loading.
		update_option('mainwp_extensions_all_activation_cached', '');

		return MainWP_Utility::update_option( $ext_key . '_APIManAdder', $info );
	}

	/**
	 * Method license_key_activation()
	 *
	 * Checks API Key &  API Email again MainWP Servers.
	 *
	 * @param mixed $api
	 * @param mixed $api_key
	 * @param mixed $api_email
	 */
	public function license_key_activation( $api, $api_key, $api_email ) {

		$options = $this->get_activation_info( $api );

		if ( ! is_array( $options ) ) {
			$options = array();
		}
		$current_api_key          = isset( $options['api_key'] ) ? $options['api_key'] : '';
		$current_activation_email = isset( $options['activation_email'] ) ? $options['activation_email'] : '';
		$activation_status        = isset( $options['activated_key'] ) ? $options['activated_key'] : '';

		if ( $activation_status == 'Deactivated' || $activation_status == '' || $api_key == '' || $api_email == '' || $current_api_key != $api_key ) {
			if ( $current_api_key != $api_key ) {
				$reset = $this->replace_license_key(
					array(
						'email'          => $current_activation_email,
						'licence_key'    => $current_api_key,
						'product_id'     => $options['product_id'],
						'instance'       => $options['instance_id'],
						'platform'       => $this->domain,
					)
				);
				if ( ! $reset ) {
					return array( 'error' => __( 'The license could not be deactivated.', 'mainwp' ) );
				}
			}

			$return = array();

			$args = array(
				'email'              => $api_email,
				'licence_key'        => $api_key,
				'product_id'         => $options['product_id'],
				'instance'           => $options['instance_id'],
				'software_version'   => $options['software_version'],
				'platform'           => $this->domain,
			);

			$activate_results = json_decode( MainWP_Api_Manager_Key::instance()->activate( $args ), true );

			if ( $activate_results['activated'] == true ) {
				$return['result']               = 'SUCCESS';
				$mess                           = isset( $activate_results['message'] ) ? $activate_results['message'] : '';
				$return['message']              = __( 'The extension has been activated. ', 'mainwp' ) . $mess;
				$options['api_key']             = $api_key;
				$options['activation_email']    = $api_email;
				$options['activated_key']       = 'Activated';
				$options['deactivate_checkbox'] = 'off';
			}

			if ( $activate_results == false ) {
				$apisslverify = get_option( 'mainwp_api_sslVerifyCertificate' );
				if ( $apisslverify == 1 ) {
					MainWP_Utility::update_option( 'mainwp_api_sslVerifyCertificate', 0 );
					$return['retry_action'] = 1;
				} else {
					$return['error'] = __( 'Connection failed to the License Key API server. Try again later.', 'mainwp' );
				}
				$options['api_key']          = '';
				$options['activation_email'] = '';
				$options['activated_key']    = 'Deactivated';
			}

			$error = $this->check_response_for_api_errors( $activate_results );
			if ( ! empty( $error ) ) {
				$return['error'] = $error;
			}

			$this->set_activation_info( $api, $options );

			return $return;
		} else {
			return array( 'result' => 'SUCCESS' );
		}
	}

	/**
	 * Method replace_license_key()
	 *
	 * Deactivate the current license key before activating the new license key.
	 *
	 * @param mixed $args
	 *
	 * @return booleen True|False.
	 */
	private function replace_license_key( $args ) {
		$reset = MainWP_Api_Manager_Key::instance()->deactivate( $args ); // reset license key activation

		if ( $reset == true ) {
			return true;
		}
	}

	/**
	 * Method license_key_adectivation()
	 *
	 * Deactivates license Key.
	 *
	 * @param mixed $api
	 */
	public function license_key_deactivation( $api ) {

		$options = $this->get_activation_info( $api );
		if ( ! is_array( $options ) ) {
			$options = array();
		}

		$activation_status        = isset( $options['activated_key'] ) ? $options['activated_key'] : '';
		$current_api_key          = isset( $options['api_key'] ) ? $options['api_key'] : '';
		$current_activation_email = isset( $options['activation_email'] ) ? $options['activation_email'] : '';

		$return = array();

		if ( $activation_status == 'Activated' && $current_api_key != '' && $current_activation_email != '' ) {
			$activate_results = MainWP_Api_Manager_Key::instance()->deactivate(
				array(
					'email'          => $current_activation_email,
					'licence_key'    => $current_api_key,
					'product_id'     => $options['product_id'],
					'instance'       => $options['instance_id'],
					'platform'       => $this->domain,
				)
			); // reset license key activation.

			$activate_results = json_decode( $activate_results, true );
			if ( $activate_results['deactivated'] == true || ( isset( $activate_results['activated'] ) && $activate_results['activated'] == 'inactive' ) ) {
				$options['api_key']              = '';
				$options['activation_email']     = '';
				$options['activated_key']        = 'Deactivated';
				$options['deactivate_checkbox']  = 'on';
				$return['result']                = 'SUCCESS';
				$return['activations_remaining'] = $activate_results['activations_remaining'];
			}

			$error = $this->check_response_for_api_errors( $activate_results );
			if ( ! empty( $error ) ) {
				$return['error'] = $error;
			}

			$this->set_activation_info( $api, $options );

			return $return;
		}
		// to fix: clear cached of all activations to reload for next loading.
		update_option('mainwp_extensions_all_activation_cached', '');
		return array( 'result' => 'SUCCESS' );
	}

	/**
	 * Method test_login_api()
	 *
	 * Test the users MainWP.com Login details against MainWP Server.
	 *
	 * @param mixed $username
	 *
	 * @param mixed $password
	 *
	 * @return booleen True|False.
	 */
	public function test_login_api( $username, $password ) {
		if ( empty( $username ) || empty( $password ) ) {
			return false;
		}

		return MainWP_Api_Manager_Key::instance()->test_login_api(
			array(
				'username'   => $username,
				'password'   => $password,
			)
		);
	}


	/**
	 * Method purchase_software()
	 *
	 * Check if the user purchased the software.
	 *
	 * @param mixed $username
	 * @param mixed $password
	 * @param mixed $productId
	 *
	 * @return mixed Array.
	 */
	public function purchase_software( $username, $password, $productId ) {
		if ( empty( $username ) || empty( $password ) ) {
			return false;
		}

		return MainWP_Api_Manager_Key::instance()->purchase_software(
			array(
				'username'   => $username,
				'password'   => $password,
				'product_id' => $productId,
			)
		);
	}

	/**
	 * Method get_purchase_software()
	 *
	 * Get users purchased software.
	 *
	 * @param mixed   $username
	 * @param mixed   $password
	 * @param mixed   $productId
	 * @param booleen $no_register
	 *
	 * @return mixed Array.
	 */
	public function get_purchased_software( $username, $password, $productId = '', $no_register = false ) {
		if ( empty( $username ) || empty( $password ) ) {
			return false;
		}

		return MainWP_Api_Manager_Key::instance()->get_purchased_software(
			array(
				'username'   => $username,
				'password'   => $password,
				'product_id' => $productId,
				'noauth'     => $no_register ? 1 : 0,
			)
		);
	}

	/**
	 * Method grab_license_key()
	 *
	 * Grab users associate MainWP License key for the selected Extension.
	 *
	 * @param mixed $api
	 * @param mixed $username
	 * @param mixed $password
	 *
	 * @return mixed $return
	 */
	public function grab_license_key( $api, $username, $password ) {

		$options = $this->get_activation_info( $api );
		if ( ! is_array( $options ) ) {
			$options = array();
		}
		$activation_status = isset( $options['activated_key'] ) ? $options['activated_key'] : '';

		$api_key   = isset( $options['api_key'] ) ? $options['api_key'] : '';
		$api_email = isset( $options['activation_email'] ) ? $options['activation_email'] : '';

		if ( $activation_status == 'Deactivated' || $activation_status == '' || $api_key == '' || $api_email == '' ) {
			$return = array();
			if ( $username != '' && $password != '' ) {

				$args = array(
					'username'           => $username,
					'password'           => $password,
					'product_id'         => isset( $options['product_id'] ) ? $options['product_id'] : '',
					'instance'           => isset( $options['instance_id'] ) ? $options['instance_id'] : '',
					'software_version'   => isset( $options['software_version'] ) ? $options['software_version'] : '',
					'platform'           => $this->domain,
				);

				$activate_results            = json_decode( MainWP_Api_Manager_Key::instance()->grab_api_key( $args ), true );
				$options['api_key']          = '';
				$options['activation_email'] = '';
				$options['activated_key']    = 'Deactivated';

				if ( is_array( $activate_results ) && isset( $activate_results['activated'] ) && ( $activate_results['activated'] == true ) && ! empty( $activate_results['api_key'] ) ) {
					$return['result']               = 'SUCCESS';
					$mess                           = isset( $activate_results['message'] ) ? $activate_results['message'] : '';
					$return['message']              = __( 'Extension activated. ', 'mainwp' ) . $mess;
					$options['api_key']             = $return['api_key']              = $activate_results['api_key'];
					$options['activation_email']    = $return['activation_email']         = $activate_results['activation_email'];
					$options['activated_key']       = 'Activated';
					$options['deactivate_checkbox'] = 'off';
				} else {

					if ( $activate_results == false ) {
						$return['error'] = __( 'Connection with the API license server could not be established. Please, try again later.', 'mainwp' );
					} elseif ( isset( $activate_results['error'] ) ) {
						$return['error'] = $activate_results['error'];
					} elseif ( empty( $activate_results['api_key'] ) ) {
						$return['error'] = __( 'License key could not be found.', 'mainwp' );
					} else {
						$return['error'] = __( 'An undefined error occurred. Please try again later or contact MainWP Support.', 'mainwp' );
					}
				}

				$error = $this->check_response_for_api_errors( $activate_results );
				if ( ! empty( $error ) ) {
					$return['error'] = $error;
				}

				$this->set_activation_info( $api, $options );

				return $return;
			} else {
				return array( 'error' => __( 'Username and Password are required in order to grab extensions API keys.', 'mainwp' ) );
			}
		}

		return array( 'result' => 'SUCCESS' );
	}

	/**
	 * Method check_response_for_api_error()
	 *
	 * Check if $response contains any api errors.
	 *
	 * @param mixed $response
	 *
	 * @return string $error Error Message.
	 */
	public function check_response_for_api_errors( $response ) {
		if ( ! is_array( $response ) || ! isset( $response['code'] ) ) {
			return false;
		}

		$error = '';
		switch ( $response['code'] ) {
			case '100':
				$error = __( 'Invalid request! Please try to deactivate and re-activate the extension on the WP > Plugins page and try to activate API key again.', 'mainwp' );
				break;
			case '102':
				$error = __( 'Activation error!  Download permission for this product could not be found.', 'mainwp' );
				break;
			case '101':
				$error = __( 'Activation error! Matching API key could not be found.', 'mainwp' );
				break;
			case '103':
			case '104':
				$error = __( 'Invalid Instance ID! Please try to deactivate and re-activate the extension on the WP > Plugins page and try to activate API key again.', 'mainwp' );
				break;
			case '105':
			case '106':
				$error = isset( $response['error'] ) ? $response['error'] : '';
				$info  = isset( $response['additional info'] ) ? ' ' . $response['additional info'] : '';
				$error = $error . $info;
				break;
			case '900':
				$error = __( 'Your membership is on hold. Reactivate your membership to activate MainWP extensions', 'mainwp' );
				break;
			case '901':
				$error = __( 'Your membership has been canceled. Reactivate your membership to activate MainWP extensions', 'mainwp' );
				break;
			case '902':
				$error = __( 'Your membership has expired. Reactivate your membership to activate MainWP extensions', 'mainwp' );
				break;
		}
		return $error;
	}

	/**
	 * Method check_response_for_intall_errors()
	 *
	 * Check if $response contains any install errors.
	 *
	 * @param mixed  $response
	 * @param string $software_title
	 *
	 * @return string $return Installation Error messages.
	 */
	public function check_response_for_intall_errors( $response, $software_title = '' ) {
		if ( ! is_array( $response ) || ! isset( $response['error'] ) ) {
			return false;
		}

		$return = false;
		switch ( $response['error'] ) {
			case 'subscription_on_hold':
				$return = __( 'Your membership is on hold. Reactivate your membership to install MainWP extensions.', 'mainwp' );
				break;
			case 'subscription_cancelled':
				$return = __( 'Your membership has been canceled. Reactivate your membership to install MainWP extensions.', 'mainwp' );
				break;
			case 'subscription_expired':
				$return = __( 'Your membership has expired. Reactivate your membership to install MainWP extensions.', 'mainwp' );
				break;
			default: // download_revoked
				$return = sprintf( __( 'Download permission for %1$s has been revoked possibly due to a license key or membership expiring. You can reactivate or purchase a license key from your account <a href="%2$s" target="_blank">dashboard</a>.', 'mainwp' ), $software_title, $this->renew_license_url );
				break;
		}
		return $return;
	}

	/**
	 * Method update_check()
	 *
	 * Check if Extensions have an update.
	 *
	 * @param mixed $args
	 *
	 * @return mixed $request
	 */
	public function update_check( $args ) {
		$args['domain'] = $this->domain;

		return MainWP_Api_Manager_Plugin_Update::instance()->update_check( $args );
	}

	/**
	 * Method request_plugin_information()
	 *
	 * Request Plugin Information
	 *
	 * @param mixed $args
	 *
	 * @return mixed
	 */
	public function request_plugin_information( $args ) {
		$args['domain'] = $this->domain;

		return MainWP_Api_Manager_Plugin_Update::instance()->request( $args );
	}

}

// End of class
