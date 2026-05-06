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

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Api_Manager
 *
 * @package MainWP\Dashboard
 */
class MainWP_Api_Manager { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

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

        if ( is_null( static::$instance ) ) {
            static::$instance = new self();
        }

        return static::$instance;
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
        return apply_filters( 'mainwp_api_manager_upgrade_url', $this->upgrade_url );
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

        $info = get_option( $ext_key . '_APIManAdder' );

        // MWP-1546: per-extension license keys were historically stored as
        // plaintext inside the option array's 'api_key' field. New writes
        // (set_activation_info below) replace that field with a
        // {encrypted_val, file_key} envelope produced by the
        // mainwp_encrypt_key_value filter (MainWP_Keys_Manager). On read,
        // the matching mainwp_decrypt_key_value filter reverses the
        // envelope.
        //
        // Encrypt-on-first-read migration: if the stored row still carries
        // a plaintext api_key string (legacy installs upgraded from before
        // this change), rewrite it as the encrypted envelope right now.
        // Existing dashboards migrate transparently as each extension's
        // option is touched, without waiting for an unrelated activation
        // event. We persist via update_option directly rather than calling
        // set_activation_info to avoid churning the activations_cached
        // option on every legacy read.
        if ( is_array( $info ) && ! empty( $info['api_key'] ) && is_string( $info['api_key'] ) ) {
            $rewritten = static::encrypt_activation_info( $ext_key, $info );
            if ( is_array( $rewritten )
                && isset( $rewritten['api_key'] )
                && is_array( $rewritten['api_key'] )
                && ! empty( $rewritten['api_key']['encrypted_val'] ) ) {
                MainWP_Utility::update_option( $ext_key . '_APIManAdder', $rewritten );
            }
        }

        return static::decrypt_activation_info( $info );
    }

    /**
     * Reverse the api_key encryption applied by set_activation_info().
     *
     * Returns the input unchanged when api_key is missing, empty, or a
     * plaintext string (legacy format). Falls back to the original value
     * if the mainwp_decrypt_key_value filter cannot recover a string, so
     * a missing keyfile cannot orphan a license activation.
     *
     * @param mixed $info Raw option value as returned by get_option().
     * @return mixed Same shape as $info, with 'api_key' decrypted to plaintext.
     */
    public static function decrypt_activation_info( $info ) {
        if ( ! is_array( $info ) || empty( $info['api_key'] ) ) {
            return $info;
        }
        if ( ! is_array( $info['api_key'] ) ) {
            return $info; // Legacy plaintext string, no envelope to reverse.
        }
        if ( empty( $info['api_key']['encrypted_val'] ) ) {
            // Malformed envelope (array but missing encrypted_val). Drop the
            // unreadable value rather than returning the raw array; callers
            // (api-handler.php readers, the hooks filter) do not is_string-
            // guard the result and would forward the array to the licensing
            // API as http_build_query garbage. Mirrors the decrypt-failure
            // branch below.
            $info['api_key'] = '';
            return $info;
        }
        $decrypted = apply_filters( 'mainwp_decrypt_key_value', false, $info['api_key'], '' );
        if ( is_string( $decrypted ) && '' !== $decrypted ) {
            $info['api_key'] = $decrypted;
        } else {
            // Decrypt failed (e.g. missing keyfile). Drop the unreadable
            // ciphertext so callers see an empty string rather than the
            // raw envelope array, which they would treat as truthy and
            // forward to the licensing API as garbage.
            $info['api_key'] = '';
        }
        return $info;
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

        // MWP-1546: encrypt the 'api_key' field at rest using the same
        // mainwp_encrypt_key_value filter that 3rd-party API keys use. Other
        // array members (activated_key, deactivate_checkbox, product_id,
        // instance_id, software_version, mainwp_version, product_item_id)
        // are not credentials and stay plaintext for backwards compatibility
        // with any reader that consumes them directly.
        $info = static::encrypt_activation_info( $ext_key, $info );

        // Codex follow-up: fail closed when encryption did not produce an
        // envelope. encrypt_activation_info() returns the input unchanged
        // when the mainwp_encrypt_key_value filter fails (missing keyfile,
        // un-writable uploads dir, etc.), which would leave api_key as a
        // plaintext string and silently downgrade this write back to the
        // pre-MWP-1546 leak path. Refuse the write and let the caller
        // surface the error rather than persisting plaintext credentials.
        if ( is_array( $info )
            && isset( $info['api_key'] )
            && is_string( $info['api_key'] )
            && '' !== $info['api_key'] ) {
            return false;
        }

        return MainWP_Utility::update_option( $ext_key . '_APIManAdder', $info );
    }

    /**
     * Encrypt the api_key field of an activation-info array via the
     * mainwp_encrypt_key_value filter (MainWP_Keys_Manager). Replaces the
     * plaintext string with the {encrypted_val, file_key} envelope.
     *
     * @param string $ext_key Extension slug; included in the keyfile prefix.
     * @param mixed  $info    Activation info as supplied by callers.
     * @return mixed Same shape as $info, with 'api_key' replaced by the
     *               encryption envelope (or unchanged if encryption fails).
     */
    public static function encrypt_activation_info( $ext_key, $info ) {
        if ( ! is_array( $info ) ) {
            return $info;
        }
        if ( empty( $info['api_key'] ) || ! is_string( $info['api_key'] ) ) {
            return $info;
        }
        $prefix   = 'extension_' . sanitize_key( $ext_key ) . '_';
        $envelope = apply_filters( 'mainwp_encrypt_key_value', false, $info['api_key'], $prefix, false );
        if ( is_array( $envelope ) && ! empty( $envelope['encrypted_val'] ) ) {
            $info['api_key'] = $envelope;
        }
        return $info;
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
    public function license_key_activation( $api_slug, $api_key ) { // phpcs:ignore -- NOSONAR - complex.

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

            // MWP-1546 follow-up: surface the fail-closed write so the user is not
            // told the activation succeeded when only the upstream half landed.
            // The license slot was already consumed at mainwp.com; without the
            // local persist the dashboard will offer to re-activate and burn a
            // second slot. The encrypt filter only fails on broken installs
            // (missing keyfile, un-writable uploads/mainwp/pk/), but when it
            // does the operator needs to know.
            if ( false === $this->set_activation_info( $api_slug, $options ) ) {
                $return['error'] = esc_html__( 'License activated upstream but local state could not be saved. Check uploads/mainwp/pk/ permissions and try the activation again.', 'mainwp' );
            }

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

            // MWP-1546 follow-up: surface the fail-closed write. The slot was
            // already released at mainwp.com; without the local persist the
            // dashboard would still display the extension as Activated.
            if ( false === $this->set_activation_info( $api_slug, $options ) ) {
                $return['error'] = esc_html__( 'License deactivated upstream but local state could not be cleared. Check uploads/mainwp/pk/ permissions.', 'mainwp' );
            }

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
    public function grab_license_key( $api_slug, $master_api_key ) { // phpcs:ignore -- NOSONAR - Current complexity is the only way to achieve desired results, pull request solutions appreciated.

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

                // MWP-1546 follow-up: surface the fail-closed write. The grab
                // call may have produced a fresh license key upstream; if the
                // local persist fails the dashboard would lose track of it
                // entirely.
                if ( false === $this->set_activation_info( $api_slug, $options ) ) {
                    $return['error'] = esc_html__( 'License retrieved upstream but local state could not be saved. Check uploads/mainwp/pk/ permissions and try again.', 'mainwp' );
                }

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
    public function check_response_for_api_errors( $response ) { //phpcs:ignore -- complex.
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
            default:
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
                /* translators: 1: Extension/software title, 2: URL to account dashboard */
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
