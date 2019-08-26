<?php

class MainWP_Api_Manager {

	private $api_url = 'https://mainwp.com/edd-mainwp-api/';
	private $license_url = 'https://mainwp.com/';

	public $domain = '';

	/**
	 * @var The single instance of the class
	 */
	protected static $_instance = null;

	public static function instance() {

		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Cloning is forbidden.
	 *
	 * @since 1.2
	 */
	private function __clone() {

	}

	/**
	 * Unserializing instances of this class is forbidden.
	 *
	 * @since 1.2
	 */
	private function __wakeup() {

	}

	public function __construct() {
		$this->domain = str_ireplace( array( 'http://', 'https://' ), '', home_url() );
	}

	public function get_domain() {
		return $this->domain;
	}

	public function get_license_url() {
		return apply_filters( 'mainwp_api_manager_upgrade_url', $this->license_url );
	}

    public function get_api_url() {
		return $this->api_url;
	}

    public function get_activation_info( $ext_key ) {
        if (empty($ext_key))
            return array();

        return get_option( $ext_key . '_APIManAdder' );
	}

    public function set_activation_info( $ext_key, $info ) {

        if (empty($ext_key))
            return false;

        update_option('mainwp_extensions_all_activation_cached', ''); // clear cached of all activations to reload for next loading

        return MainWP_Utility::update_option( $ext_key . '_APIManAdder', $info );
	}

	public function license_key_activation( $api, $api_key ) {

		$options = $this->get_activation_info( $api );

		if ( !is_array( $options ) ) {
			$options = array();
		}

		$current_api_key			 = isset( $options[ 'api_key' ] ) ? $options[ 'api_key' ] : '';
		$activation_status			 = isset( $options[ 'activated_key' ] ) ? $options[ 'activated_key' ] : '';

		if ( $activation_status == 'Deactivated' || $activation_status == '' || $api_key == '' || $current_api_key != $api_key ) {
			if ( $current_api_key !== '' && $current_api_key != $api_key ) {

                $reset = false;

                $params = array(
                    'license'	 => $current_api_key,
                    'item_id'	 => $options[ 'product_id' ],
                    'url'		 => $this->domain,
                );

                $response = MainWP_Api_Manager_Key::instance()->deactivate( $params ); // Deactivate the current license key before activating the new license key

                $license_data = json_decode( wp_remote_retrieve_body( $response ) );

                // $license_data->license will be either "deactivated" or "failed"
                if( is_object( $license_data ) && $license_data->license == 'deactivated' ) {
                    $reset = true;
                }

				if ( !$reset ) {
					return array( 'error' => __( 'The license could not be deactivated.', 'mainwp' ) );
				}
			}

			$return = array();

            // data to send in our API request
            $api_params = array(
                'license'    => $api_key,
                'item_id'  => $options[ 'product_id' ], // the id of our product in EDD
                'url'        => $this->domain
            );

			$response = MainWP_Api_Manager_Key::instance()->activate( $api_params );

            $activate_results = json_decode( wp_remote_retrieve_body( $response ) );

			if ( is_object($activate_results) && $activate_results->license == 'valid' && $activate_results->item_id == $options[ 'product_id' ] ) {
				$return[ 'result' ]					 = 'SUCCESS';
				$return[ 'message' ]				 = __( 'The extension has been activated. ', 'mainwp' );
				$options[ 'api_key' ]				 = $api_key;
				$options[ 'activated_key' ]			 = 'Activated';
			}

			if ( $activate_results == false ) {
				$apisslverify = get_option( 'mainwp_api_sslVerifyCertificate' );
				if ( $apisslverify == 1 ) {
					MainWP_Utility::update_option( 'mainwp_api_sslVerifyCertificate', 0 );
					$return[ 'retry_action' ] = 1;
				} else {
					$return[ 'error' ] = __( 'Connection failed to the License Key API server. Try again later.', 'mainwp' );
				}
				$options[ 'api_key' ]			 = '';
				$options[ 'activated_key' ]		 = 'Deactivated';
			}

			$error				 = $this->check_response_for_api_errors( $response );

			if ( !empty( $error ) )
				$return[ 'error' ]	 = $error;

            $this->set_activation_info( $api, $options );

			return $return;
		} else {
			return array( 'result' => 'SUCCESS' );
		}
	}

	public function license_key_deactivation( $api ) {

		$options = $this->get_activation_info( $api );
		if ( !is_array( $options ) ) {
			$options = array();
		}

		$activation_status			 = isset( $options[ 'activated_key' ] ) ? $options[ 'activated_key' ] : '';
		$current_api_key			 = isset( $options[ 'api_key' ] ) ? $options[ 'api_key' ] : '';

        $return = array();

		if ( $activation_status == 'Activated' && $current_api_key != '' ) {

			$response = MainWP_Api_Manager_Key::instance()->deactivate( array(
				'license'	 => $current_api_key,
				'item_id'	 => $options[ 'product_id' ],
				'url'		 => $this->domain,
			) ); // reset license key activation

            $activate_results = json_decode( wp_remote_retrieve_body( $response ) );

            if ( is_object($activate_results) &&  $activate_results->license == 'deactivated' ) {
				$options[ 'api_key' ]				 = '';
				$options[ 'activated_key' ]			 = 'Deactivated';
//				$options[ 'deactivate_checkbox' ]	 = 'on';
				$return[ 'result' ]					 = 'SUCCESS';
				$return[ 'activations_remaining' ]	 = '';
			}

            $error = '';

            // make sure the response came back okay
            if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
                if ( is_wp_error( $response ) ) {
                    $error = $response->get_error_message();
                } else {
                    $error = __( 'An error occurred, please try again.' );
                }
            }

			if ( !empty( $error ) )
				$return[ 'error' ]	 = $error;

			$this->set_activation_info( $api, $options );

			return $return;
		}
        update_option('mainwp_extensions_all_activation_cached', ''); // to fix: clear cached of all activations to reload for next loading
		return array( 'result' => 'SUCCESS' );
	}

    public function test_verify_api( $public_key, $token ) {
		if ( empty( $public_key ) || empty( $token ) ) {
			return false;
		}
		return MainWP_Api_Manager_Key::instance()->testverifyapi( $public_key, $token );
	}

	public function purchase_software( $productId, $public_key, $token ) {
		return MainWP_Api_Manager_Key::instance()->purchasesoftware( $productId, $public_key, $token );
	}

    public function grab_license_key_by_id( $item_id ) {
        $public_key = get_option( 'mainwp_extensions_api_public_key' );
		$token = get_option( 'mainwp_extensions_api_token' );
        return json_decode( MainWP_Api_Manager_Key::instance()->grabapikey( $item_id, $public_key, $token  ), true );
    }

	public function grab_license_key( $api ) {

        $public_key = get_option( 'mainwp_extensions_api_public_key' );
		$token = get_option( 'mainwp_extensions_api_token' );

		$options = $this->get_activation_info( $api );

		if ( !is_array( $options ) ) {
			$options = array();
		}

		$activation_status = isset( $options[ 'activated_key' ] ) ? $options[ 'activated_key' ] : '';
		$api_key	 = isset( $options[ 'api_key' ] ) ? $options[ 'api_key' ] : '';

        if ( $activation_status == 'Deactivated' || $activation_status == '' || $api_key == '' ) {
			$return = array();
			if ( $public_key != '' && $token != '' ) {
                $item_id = isset( $options[ 'product_id' ] ) ? intval($options[ 'product_id' ]) : 0;

				$activate_results				 = json_decode( MainWP_Api_Manager_Key::instance()->grabapikey( $item_id, $public_key, $token  ), true );
                $options[ 'api_key' ]			 = '';
				$options[ 'activated_key' ]		 = 'Deactivated';

				if ( is_array( $activate_results ) && isset( $activate_results[ 'license' ] ) && ( $activate_results[ 'license' ] == 'valid' ) && !empty( $activate_results[ 'license_key' ] ) ) {
					$return[ 'result' ]					 = 'SUCCESS';
					$return[ 'message' ]				 = __( 'Extension activated. ', 'mainwp' );
					$options[ 'api_key' ]				 = $return[ 'api_key' ]				 = $activate_results[ 'license_key' ];
					$options[ 'activated_key' ]			 = 'Activated';
				} else {

					if ( $activate_results == false ) {
						$return[ 'error' ] = __( 'Connection with the API license server could not be established. Please, try again later.', "mainwp" );
					} else if ( isset( $activate_results[ 'error' ] ) ) {
						$return[ 'error' ] = $activate_results[ 'error' ];
					} else if ( empty( $activate_results[ 'license_key' ] ) ) {
						$return[ 'error' ] = __( 'License key could not be found.', 'mainwp' );
					} else {
						$return[ 'error' ] = __( 'An undefined error occurred. Please try again later or contact MainWP Support.', 'mainwp' );
					}
				}

//				$error				 = $this->check_response_for_api_errors( $activate_results );
//				if ( !empty( $error ) )
//					$return[ 'error' ]	 = $error;

				$this->set_activation_info( $api, $options );

				return $return;
			} else {
				return array( 'error' => __( 'API Keys are required in order to grab extensions license.', 'mainwp' ) );
			}
		}

		return array( 'result' => 'SUCCESS' );
	}

	public function check_response_for_api_errors( $response ) {

        $message = '';

        // make sure the response came back okay
        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {

            if ( is_wp_error( $response ) ) {
                $message = $response->get_error_message();
            } else {
                $message = __( 'An error occurred, please try again.', 'mainwp' );
            }

        } else {

            $license_data = json_decode( wp_remote_retrieve_body( $response ) );

            if ( false === $license_data->success ) {

                switch( $license_data->error ) {

                    case 'expired' :

                        $message = sprintf(
                            __( 'Your license key expired on %s.', 'mainwp' ),
                            date_i18n( get_option( 'date_format' ), strtotime( $license_data->expires, current_time( 'timestamp' ) ) )
                        );
                        break;

                    case 'disabled' :
                    case 'revoked' :

                        $message = __( 'Your license key has been disabled.', 'mainwp' );
                        break;

                    case 'missing' :

                        $message = __( 'Invalid license.', 'mainwp' );
                        break;

                    case 'invalid' :
                    case 'site_inactive' :

                        $message = __( 'Your license is not active for this URL.', 'mainwp'  );
                        break;

                    case 'item_name_mismatch' :

                        $message = __( 'This appears to be an invalid license key.', 'mainwp' );
                        break;

                    case 'no_activations_left':

                        $message = __( 'Your license key has reached its activation limit.', 'mainwp' );
                        break;

                    default :

                        $message = __( 'An error occurred, please try again.', 'mainwp' );
                        break;
                }

            }

        }

        return $message;


//		if ( !is_array( $response ) || !isset( $response[ 'code' ] ) )
//			return false;
//
//		$error = '';
//		switch ( $response[ 'code' ] ) {
//			case '100':
//				$error	 = __( 'Invalid request! Please try to deactivate and re-activate the extension on the WP > Plugins page and try to activate API key again.', 'mainwp' );
//				break;
//			case '102':
//				$error	 = __( 'Activation error!  Download permission for this product could not be found.', 'mainwp' );
//				break;
//			case '101':
//				$error	 = __( 'Activation error! Matching API key could not be found.', 'mainwp' );
//				break;
//			case '103':
//			case '104':
//				$error	 = __( 'Invalid Instance ID! Please try to deactivate and re-activate the extension on the WP > Plugins page and try to activate API key again.', 'mainwp' );
//				break;
//			case '105':
//			case '106':
//				$error	 = isset( $response[ 'error' ] ) ? $response[ 'error' ] : '';
//				$info	 = isset( $response[ 'additional info' ] ) ? ' ' . $response[ 'additional info' ] : '';
//				$error	 = $error . $info;
//				break;
//			case '900' :
//				$error	 = __( 'Your membership is on hold. Reactivate your membership to activate MainWP extensions', 'mainwp' );
//				break;
//			case '901' :
//				$error	 = __( 'Your membership has been canceled. Reactivate your membership to activate MainWP extensions', 'mainwp' );
//				break;
//			case '902' :
//				$error	 = __( 'Your membership has expired. Reactivate your membership to activate MainWP extensions', 'mainwp' );
//				break;
//		}
//		return $error;
	}

	public function check_response_for_intall_errors( $response, $software_title = "" ) {
		if ( !is_array( $response ) || !isset( $response[ 'error' ] ) )
			return false;

		switch ( $response[ 'error' ] ) {
			case 'subscription_on_hold':
				return __( 'Your membership is on hold. Reactivate your membership to install MainWP extensions.', 'mainwp' );
				break;
			case 'subscription_cancelled':
				return __( 'Your membership has been canceled. Reactivate your membership to install MainWP extensions.', 'mainwp' );
				break;
			case 'subscription_expired':
				return __( 'Your membership has expired. Reactivate your membership to install MainWP extensions.', 'mainwp' );
				break;
			default : //download_revoked
				return sprintf( __( 'Download permission for %s has been revoked possibly due to a license key or membership expiring. You can reactivate or purchase a license key from your account <a href="https://mainwp.com/my-account" target="_blank">dashboard</a>.', 'mainwp' ), $software_title );
				break;
		}
		return false;
	}
}

// End of class
