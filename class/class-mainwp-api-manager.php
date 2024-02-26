<?php
/**
 * MainWP API Manager
 *
 * This class handles MainWP Extensions API licensing.
 *
 * @package MainWP/MainWP API Manager
 * @author Todd Lahman LLC
 * @copyright Copyright (c) Todd Lahman LLC
 * @since 1.0.0
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Api_Manager
 *
 * @package MainWP\Dashboard
 */
class MainWP_Api_Manager {

	/**
	 * Extensions upgrade URL.
	 *
	 * @var string Default URL 'https://mainwp.com/'
	 */
	private $upgrade_url = 'https://mainwp.com/';

	/**
	 * Extensions license renewal URL.
	 *
	 * @var string Default 'https://mainwp.com/my-account'
	 */
	private $renew_license_url = 'https://mainwp.com/my-account';

	/**
	 * Protected static variable to hold the single instance of the class.
	 *
	 * @var mixed Default null
	 */
	protected static $instance = null;

	/**
	 * MainWP installation domain name.
	 *
	 * @var string $domain Empty by default.
	 */
	public $domain = '';

	/**
	 * Return the single instance of the class.
	 *
	 * @return mixed $instance The single instance of the class.
	 */
	public static function instance() {

		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Cloning is forbidden.
	 *
	 * @since 1.2
	 */
	public function __clone() {
	}

	/**
	 * Un-serializing instances of this class is forbidden.
	 *
	 * @since 1.2
	 */
	public function __wakeup() {
	}

	/**
	 * MainWP_Api_Manager constructor.
	 *
	 * Run each time the class is called.
	 *
	 * Replace HTTP protocol to HTTPS.
	 */
	public function __construct() {
		$this->domain = str_ireplace( array( 'http://', 'https://' ), '', home_url() );
	}

	/**
	 * Get Upgrade URL.
	 *
	 * @return string Activation upgrade URL.
	 */
	public function get_upgrade_url() {
		$url = apply_filters( 'mainwp_api_manager_upgrade_url', $this->upgrade_url );
		return $url;
	}

	/**
	 * Get domain URL.
	 *
	 * @return string domain URL.
	 */
	public function get_domain() {
		return $this->domain;
	}

	/**
	 * Get activation info.
	 *
	 * @param mixed $ext_key extension key.
	 *
	 * @return mixed get_option() get activation information.
	 */
	public function get_activation_info( $ext_key ) {
		if ( empty( $ext_key ) ) {
			return array();
		}

		return get_option( $ext_key . '_APIManAdder' );
	}

	/**
	 * Store activation info.
	 *
	 * @param mixed $ext_key Extension key.
	 * @param mixed $info    Activation information.
	 *
	 * @return mixed Set activation info.
	 * @uses \MainWP\Dashboard\MainWP_Utility::update_option()
	 */
	public function set_activation_info( $ext_key, $info ) {

		if ( empty( $ext_key ) ) {
			return false;
		}

		// Clear cached of all activations to reload for next loading.
		update_option( 'mainwp_extensions_all_activation_cached', '' );

		return MainWP_Utility::update_option( $ext_key . '_APIManAdder', $info );
	}

	/**
	 *  Remove activation info.
	 *
	 * @param mixed $ext_key Extension key.
	 *
	 * @return mixed Remove activation info.
	 */
	public function remove_activation_info( $ext_key ) {
		if ( empty( $ext_key ) ) {
			return;
		}

		$options = $this->get_activation_info( $ext_key );
		if ( ! is_array( $options ) ) {
			$options = array();
		}

		if ( isset( $options['api_key'] ) ) {
			$options['api_key'] = '';
		}
		if ( isset( $options['activated_key'] ) ) {
			$options['activated_key'] = 'Deactivated';
		}
		$this->set_activation_info( $ext_key, $options );
	}

	/**
	 * Check API Key & API Email again MainWP Servers.
	 *
	 * @param array  $api_slug       Extension activation info.
	 * @param string $api_key   API license key.
	 *
	 * @return array Activation info.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Api_Manager_Key::activate()
	 * @uses \MainWP\Dashboard\MainWP_Utility::update_option()
	 */
	public function license_key_activation( $api_slug, $api_key ) {

		$options = $this->get_activation_info( $api_slug );

		if ( ! is_array( $options ) ) {
			$options = array();
		}
		$current_api_key   = isset( $options['api_key'] ) ? $options['api_key'] : '';
		$activation_status = isset( $options['activated_key'] ) ? $options['activated_key'] : '';

		if ( 'Deactivated' === $activation_status || '' === $activation_status || '' === $api_key || $current_api_key !== $api_key ) {
			if ( $current_api_key !== $api_key ) {
				$reset = $this->replace_license_key(
					array(
						'api_key'          => $api_key,
						'product_id'       => $options['product_id'],
						'instance'         => $options['instance_id'],
						'software_version' => $options['software_version'],
						'software_slug'    => $api_slug,
					)
				);
				if ( ! $reset ) {
					return array( 'error' => esc_html__( 'The license could not be deactivated.', 'mainwp' ) );
				}
			}

			$return = array();

			$args = array(
				'api_key'          => $api_key,
				'product_id'       => $options['product_id'],
				'instance'         => $options['instance_id'],
				'software_version' => $options['software_version'],
				'object'           => $this->domain,
				'software_slug'    => $api_slug,
			);

			$activate_results = MainWP_Api_Manager_Key::instance()->activate( $args );
			if ( ! empty( $activate_results ) ) {
				$activate_results = json_decode( $activate_results, true );
			} else {
				$activate_results = array();
			}

			if ( true === $activate_results['activated'] ) {
				$return['result']               = 'SUCCESS';
				$mess                           = isset( $activate_results['message'] ) ? $activate_results['message'] : '';
				$return['message']              = esc_html__( 'The extension has been activated. ', 'mainwp' ) . $mess;
				$options['api_key']             = $api_key;
				$options['activated_key']       = 'Activated';
				$options['deactivate_checkbox'] = 'off';
				$options['mainwp_version']      = MainWP_System::instance()->get_dashboard_version();
				MainWP_Utility::instance()->get_set_deactivated_licenses_alerted( $api_slug, 0, 'set' ); // so next time deactived it will alerted.
			}

			if ( false === $activate_results ) {
				$apisslverify = get_option( 'mainwp_api_sslVerifyCertificate' );
				if ( 1 === $apisslverify ) {
					MainWP_Utility::update_option( 'mainwp_api_sslVerifyCertificate', 0 );
					$return['retry_action'] = 1;
				} else {
					$return['error'] = esc_html__( 'Connection failed to the License Key API server. Try again later.', 'mainwp' );
				}
				$options['api_key']       = '';
				$options['activated_key'] = 'Deactivated';
			}

			$error = $this->check_response_for_api_errors( $activate_results );
			if ( ! empty( $error ) ) {
				$return['error'] = $error;
			}

			$this->set_activation_info( $api_slug, $options );

			return $return;
		} else {
			return array( 'result' => 'SUCCESS' );
		}
	}

	/**
	 * Deactivate the current license key before activating the new license key.
	 *
	 * @param array $args Request arguments.
	 *
	 * @return bool True on success, false on failure.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Api_Manager_Key::deactivate()
	 */
	private function replace_license_key( $args ) {
		$reset = MainWP_Api_Manager_Key::instance()->deactivate( $args ); // reset license key activation.

		if ( true === $reset ) {
			return true;
		}
	}

	/**
	 * Deactivate license Key.
	 *
	 * @param array $api_slug Extension activation info.
	 * @param array $api_key Extension activation api key.
	 *
	 * @return array Deactivation info.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Api_Manager_Key::deactivate()
	 */
	public function license_key_deactivation( $api_slug, $api_key ) {

		$options = $this->get_activation_info( $api_slug );
		if ( ! is_array( $options ) ) {
			$options = array();
		}

		$activation_status = isset( $options['activated_key'] ) ? $options['activated_key'] : '';

		if ( empty( $api_key ) ) {
			$options['api_key'] = '';
			$this->set_activation_info( $api_slug, $options );
		}

		$return = array();

		if ( 'Activated' === $activation_status && '' !== $api_key ) {
			$activate_results = MainWP_Api_Manager_Key::instance()->deactivate(
				array(
					'product_id'       => $options['product_id'],
					'instance'         => $options['instance_id'],
					'api_key'          => $api_key,
					'object'           => $this->domain,
					'software_version' => $options['software_version'],
					'software_slug'    => $api_slug,
				)
			); // reset license key activation.

			$activate_results = json_decode( $activate_results, true );
			if ( isset( $activate_results['deactivated'] ) && true === $activate_results['deactivated'] ) {
				$options['api_key']              = '';
				$options['activated_key']        = 'Deactivated';
				$options['deactivate_checkbox']  = 'on';
				$return['result']                = 'SUCCESS';
				$return['activations_remaining'] = $activate_results['activations_remaining'];
			}

			$error = $this->check_response_for_api_errors( $activate_results );
			if ( ! empty( $error ) ) {
				$return['error']          = $error;
				$options['api_key']       = '';
				$options['activated_key'] = 'Deactivated';
			}

			$this->set_activation_info( $api_slug, $options );

			return $return;
		}
		// to fix: clear cached of all activations to reload for next loading.
		update_option( 'mainwp_extensions_all_activation_cached', '' );
		return array( 'result' => 'SUCCESS' );
	}

	/**
	 * Test the users MainWP.com Login details against MainWP Server.
	 *
	 * @param string $api_key MainWP api key.
	 *
	 * @return mixed Login test result.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Api_Manager_Key::verify_api_key()
	 */
	public function verify_mainwp_api( $api_key ) {
		if ( empty( $api_key ) ) {
			return false;
		}

		return MainWP_Api_Manager_Key::instance()->verify_api_key(
			array(
				'api_key' => $api_key,
			)
		);
	}


	/**
	 * Check if the user purchased the software.
	 *
	 * @param string $productId extension (product) ID.
	 *
	 * @return mixed purchase_software() purchase extensions.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Api_Manager_Key::purchase_software()
	 */
	public function purchase_software( $productId ) {
		return MainWP_Api_Manager_Key::instance()->purchase_software(
			array(
				'product_id' => $productId,
			)
		);
	}

	/**
	 * Get users purchased extensions.
	 *
	 * @param string $api_key api key.
	 * @param string $productId extension (product) ID.
	 * @param bool   $no_register registration request.
	 *
	 * @return array Purchased extensions.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Api_Manager_Key::get_purchased_software()
	 */
	public function get_purchased_extension( $api_key, $productId = '', $no_register = false ) {
		if ( empty( $api_key ) ) {
			return false;
		}

		return MainWP_Api_Manager_Key::instance()->get_purchased_software(
			array(
				'product_id' => $productId,
				'api_key'    => $api_key,
				'noauth'     => $no_register ? 1 : 0,
			)
		);
	}

	/**
	 * Grab users associate MainWP License key for the selected Extension.
	 *
	 * @param array  $api_slug      Extension activation info.
	 * @param string $master_api_key MainWP master api key.
	 *
	 * @return mixed Activation info.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Api_Manager_Key::grab_api_key()
	 */
	public function grab_license_key( $api_slug, $master_api_key ) { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		$options = $this->get_activation_info( $api_slug );
		if ( ! is_array( $options ) ) {
			$options = array();
		}
		$activation_status = isset( $options['activated_key'] ) ? $options['activated_key'] : '';
		$api_key           = isset( $options['api_key'] ) ? $options['api_key'] : '';

		if ( ! isset( $options['product_item_id'] ) ) {
			$options['product_item_id'] = 0; // to compatible.
		}

		if ( 'Deactivated' === $activation_status || '' === $activation_status || '' === $api_key || empty( $options['product_item_id'] ) ) {
			$return = array();
			if ( ! empty( $master_api_key ) ) {
				$args = array(
					'api_key'          => $master_api_key,
					'product_id'       => isset( $options['product_id'] ) ? $options['product_id'] : '',
					'instance'         => isset( $options['instance_id'] ) ? $options['instance_id'] : '',
					'software_version' => isset( $options['software_version'] ) ? $options['software_version'] : '',
					'object'           => $this->domain,
					'software_slug'    => $api_slug,
				);

				$activate_results = MainWP_Api_Manager_Key::instance()->grab_api_key( $args );
				if ( ! empty( $activate_results ) ) {
					$activate_results = json_decode( $activate_results, true );
				} else {
					$activate_results = array();
				}

				$options['api_key']       = '';
				$options['activated_key'] = 'Deactivated';

				if ( ! isset( $options['product_item_id'] ) ) {
					$options['product_item_id'] = 0; // to compatible.
				}

				if ( is_array( $activate_results ) && isset( $activate_results['activated'] ) && ( true === $activate_results['activated'] ) && ! empty( $activate_results['api_key'] ) ) {
					$return['result']               = 'SUCCESS';
					$mess                           = isset( $activate_results['message'] ) ? $activate_results['message'] : '';
					$return['message']              = esc_html__( 'Extension activated. ', 'mainwp' ) . $mess;
					$options['api_key']             = $activate_results['api_key'];
					$return['api_key']              = $activate_results['api_key'];
					$options['activated_key']       = 'Activated';
					$options['product_item_id']     = isset( $activate_results['product_item_id'] ) ? intval( $activate_results['product_item_id'] ) : 0;
					$options['deactivate_checkbox'] = 'off';
				} elseif ( false === $activate_results ) {
					$return['error'] = esc_html__( 'Connection with the API license server could not be established. Please, try again later.', 'mainwp' );
				} elseif ( isset( $activate_results['error'] ) ) {
					$return['error'] = $activate_results['error'];
				} elseif ( empty( $activate_results['api_key'] ) ) {
					$return['error'] = esc_html__( 'License key could not be found.', 'mainwp' );
				} else {
					$return['error'] = esc_html__( 'An undefined error occurred. Please try again later.', 'mainwp' );
				}

				$error = $this->check_response_for_api_errors( $activate_results );
				if ( ! empty( $error ) ) {
					$return['error'] = $error;
				}

				$this->set_activation_info( $api_slug, $options );

				return $return;
			} else {
				return array( 'error' => esc_html__( 'MainWP API key are required in order to grab extensions API keys.', 'mainwp' ) );
			}
		}

		return array( 'result' => 'SUCCESS' );
	}

	/**
	 * Check if $response contains any api errors.
	 *
	 * @param array $response Response array.
	 *
	 * @return string $error Error message.
	 */
	public function check_response_for_api_errors( $response ) {
		if ( ! is_array( $response ) || ! isset( $response['code'] ) ) {
			return false;
		}

		if ( isset( $response['error'] ) ) {
			return $response['error'];
		}

		$error = '';
		switch ( $response['code'] ) {
			case '100':
				$error = esc_html__( 'Activation error! Please try to deactivate and re-activate the extension on the WP > Plugins page and try to activate API key again.', 'mainwp' );
				break;
			case '102':
				$error = esc_html__( 'Activation error! Download permission for this product could not be found.', 'mainwp' );
				break;
			case '101':
				$error = esc_html__( 'Activation error! Matching API key could not be found.', 'mainwp' );
				break;
			case '103':
			case '104':
				$error = esc_html__( 'Invalid Instance ID! Please try to deactivate and re-activate the extension on the WP > Plugins page and try to activate API key again.', 'mainwp' );
				break;
			case '105':
			case '106':
				$error = isset( $response['error'] ) ? $response['error'] : '';
				$info  = isset( $response['additional info'] ) ? ' ' . $response['additional info'] : '';
				$error = $error . $info;
				break;
			case '900':
				$error = esc_html__( 'Your membership is on hold. Reactivate your membership to activate MainWP extensions', 'mainwp' );
				break;
			case '901':
				$error = esc_html__( 'Your membership has been canceled. Reactivate your membership to activate MainWP extensions', 'mainwp' );
				break;
			case '902':
				$error = esc_html__( 'Your membership has expired. Reactivate your membership to activate MainWP extensions', 'mainwp' );
				break;
		}
		return $error;
	}

	/**
	 * Check if $response contains any install errors.
	 *
	 * @param array  $response       Response array.
	 * @param string $software_title Extension title.
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
				$return = esc_html__( 'Your membership is on hold. Reactivate your membership to install MainWP extensions.', 'mainwp' );
				break;
			case 'subscription_cancelled':
				$return = esc_html__( 'Your membership has been canceled. Reactivate your membership to install MainWP extensions.', 'mainwp' );
				break;
			case 'subscription_expired':
				$return = esc_html__( 'Your membership has expired. Reactivate your membership to install MainWP extensions.', 'mainwp' );
				break;
			default: // download_revoked.
				$return = sprintf( esc_html__( 'Download permission for %1$s has been revoked possibly due to a license key or membership expiring. You can reactivate or purchase a license key from your account <a href="%2$s" target="_blank">dashboard</a>.', 'mainwp' ), $software_title, $this->renew_license_url );
				break;
		}
		return $return;
	}

	/**
	 * Request plugin information
	 *
	 * @param array $args Request arguments.
	 *
	 * @return array Plugin info.
	 *
	 * @uses MainWP_Api_Manager_Plugin_Update::request()
	 */
	public function request_extension_information( $args ) {
		return MainWP_Api_Manager_Plugin_Update::instance()->request( $args );
	}
}

// End of class.
