<?php
/**
 * MainWP API Manager Key handler.
 *
 * This class handles user authentication with MainWP.com License Servers and provides the ability to grab license keys automatically.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Api_Manager_Key
 *
 * MainWP Api Manager Key handler
 * This class handles user authentication with MainWP.com License Servers
 * and provides the ability to grab License Keys automatically.
 *
 * @package MainWP API Manager/Key Handler
 * @author Todd Lahman LLC
 * @copyright   Copyright (c) Todd Lahman LLC
 * @since 1.3.0
 */
class MainWP_Api_Manager_Key { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Set initial $instance value.
     *
     * @var null
     */
    protected static $instance = null;

    /**
     * Set initial $apisslverify value.
     *
     * @var int
     */
    protected static $apisslverify = 1;

    /**
     * Create a new Self Instance.
     *
     * @return mixed static::$instance
     */
    public static function instance() {

        if ( is_null( static::$instance ) ) {
            static::$instance = new self();
        }

        return static::$instance;
    }

    /**
     * MainWP_Api_Manager_Key constructor.
     *
     * Run any time class is called.
     * Validate SSL certificate.
     */
    public function __construct() {
        static::$apisslverify = ( ( get_option( 'mainwp_api_sslVerifyCertificate' ) === false ) || ( 1 === (int) get_option( 'mainwp_api_sslVerifyCertificate' ) ) ) ? 1 : 0;
    }

    /**
     * Activate extension.
     *
     * This function checks the users login information & grabs the update URL for the specific extension & activates it.
     *
     * @param array $args Extension arguments.
     *
     * @return array Request response.
     *
     * @uses \MainWP\Dashboard\MainWP_Api_Manager::get_upgrade_url()
     */
    public function activate( $args ) {

        $defaults = array(
            'request'           => 'softwareactivation',
            'dashboard_version' => MainWP_System::instance()->get_dashboard_version(),
        );

        $args = wp_parse_args( $defaults, $args );

        $request = wp_remote_post(
            MainWP_Api_Manager::instance()->get_upgrade_url() . '?mainwp-api=am-software-api',
            array(
                'body'      => $args,
                'timeout'   => 50,
                'sslverify' => static::$apisslverify,
            )
        );

        if ( is_wp_error( $request ) || 200 !== wp_remote_retrieve_response_code( $request ) ) {

            return false;
        }

        return wp_remote_retrieve_body( $request );
    }

    /**
     * Deactivate extension .
     *
     * This function checks the users login information & grabs the update URL for the specific extension & deactivates it.
     *
     * @param array $args Extension arguments.
     *
     * @return array Request response.
     *
     * @uses \MainWP\Dashboard\MainWP_Api_Manager::get_upgrade_url()
     */
    public function deactivate( $args ) {

        $defaults = array(
            'request'           => 'deactivate', // old: wc-api/deactivation.
            'dashboard_version' => MainWP_System::instance()->get_dashboard_version(),
        );

        $args = wp_parse_args( $defaults, $args );

        $request = wp_remote_post(
            MainWP_Api_Manager::instance()->get_upgrade_url() . '?mainwp-api=am-software-api',  // old: wc-api/deactivate.
            array(
                'body'      => $args,
                'timeout'   => 50,
                'sslverify' => static::$apisslverify,
            )
        );

        if ( is_wp_error( $request ) || 200 !== wp_remote_retrieve_response_code( $request ) ) {
            // Request failed.
            return false;
        }

        return wp_remote_retrieve_body( $request );
    }

    /**
     * Grab extension API Key.
     *
     * This function checks the users login information & grabs the update URL for the specific extension & returns the API Key.
     *
     * @param array $args Extension arguments.
     *
     * @return array Request response.
     *
     * @uses \MainWP\Dashboard\MainWP_Api_Manager::get_upgrade_url()
     */
    public function grab_api_key( $args ) {

        $defaults = array(
            'request'           => 'grabapikey',
            'dashboard_version' => MainWP_System::instance()->get_dashboard_version(),
        );

        $args = wp_parse_args( $defaults, $args );

        $request = wp_remote_post(
            MainWP_Api_Manager::instance()->get_upgrade_url() . '?mainwp-api=am-software-api',
            array(
                'body'      => $args,
                'timeout'   => 50,
                'sslverify' => static::$apisslverify,
            )
        );

        if ( is_wp_error( $request ) || 200 !== wp_remote_retrieve_response_code( $request ) ) {
            // Request failed.
            return false;
        }

        return wp_remote_retrieve_body( $request );
    }

    /**
     * Test login API.
     *
     * This function checks the users login information & Tests it against the MainWP.com Login Credentials stored on the license server.
     *
     * @param aray $args Login arguments.
     *
     * @return array Request response.
     *
     * @throws \MainWP_Exception Request error codes.
     *
     * @uses \MainWP\Dashboard\MainWP_Api_Manager::get_upgrade_url()
     * @uses \MainWP\Dashboard\MainWP_Logger::debug()
     * @uses \MainWP\Dashboard\MainWP_Utility::value_to_string()
     * @uses \MainWP\Dashboard\MainWP_Utility::update_option()
     */
    public function verify_api_key( $args ) {

        $defaults = array(
            'request' => 'testloginapi',
        );

        $args = wp_parse_args( $defaults, $args );

        $request = wp_remote_post(
            MainWP_Api_Manager::instance()->get_upgrade_url() . '?mainwp-api=am-software-api',
            array(
                'body'      => $args,
                'timeout'   => 50,
                'sslverify' => static::$apisslverify,
            )
        );

        if ( is_wp_error( $request ) ) {
            if ( 1 === (int) static::$apisslverify ) {
                MainWP_Utility::update_option( 'mainwp_api_sslVerifyCertificate', 0 );

                return array( 'retry_action' => 1 );
            }

            throw new MainWP_Exception( $request->get_error_message() ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $code = wp_remote_retrieve_response_code( $request );
        if ( 200 !== $code ) {
            $error = sprintf( esc_html__( 'Login verification could not be completed. Please contact %1$sMainWP Support%2$s so we can assist.', 'mainwp' ), '<a href="https://community.mainwp.com/" target="_blank">', '</a>' );
            throw new MainWP_Exception( $error ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped.
        }

        return wp_remote_retrieve_body( $request );
    }

    /**
     * Get purchased software.
     *
     * This function grabs a list of purchased MainWP Extensions that are available for download.
     *
     * @param array $args Software Arguments.
     *
     * @return array Request response.
     *
     * @uses \MainWP\Dashboard\MainWP_Api_Manager::get_upgrade_url()
     */
    public function get_purchased_software( $args ) {

        $defaults = array(
            'request' => 'getpurchasedsoftware',
        );

        $args = wp_parse_args( $defaults, $args );

        $request = wp_remote_post(
            MainWP_Api_Manager::instance()->get_upgrade_url() . '?mainwp-api=am-software-api',
            array(
                'body'      => $args,
                'timeout'   => 200,
                'sslverify' => static::$apisslverify,
            )
        );

        MainWP_Logger::instance()->debug( 'Get purchased softwares: ' . MainWP_Utility::value_to_string( $request ) );

        if ( is_wp_error( $request ) || 200 !== wp_remote_retrieve_response_code( $request ) ) {
            // Request failed.
            return false;
        }

        return wp_remote_retrieve_body( $request );
    }

    /**
     * Purchase software.
     *
     * @param array $args Software arguments.
     *
     * @return array Request response.
     *
     * @uses \MainWP\Dashboard\MainWP_Api_Manager::get_upgrade_url()
     */
    public function purchase_software( $args ) {
        $defaults = array(
            'request' => 'purchasesoftware',
        );
        $args     = wp_parse_args( $defaults, $args );

        $request = wp_remote_post(
            MainWP_Api_Manager::instance()->get_upgrade_url() . '?mainwp-api=am-software-api',
            array(
                'body'      => $args,
                'timeout'   => 50,
                'sslverify' => static::$apisslverify,
            )
        );

        if ( is_wp_error( $request ) || 200 !== wp_remote_retrieve_response_code( $request ) ) {
            // Request failed.
            return false;
        }

        return wp_remote_retrieve_body( $request );
    }

    /**
     * Get decrypt master api key.
     *
     * @return array Request response.
     */
    public function get_decrypt_master_api_key() {
        $decryp_api_key = '';

        // to check old value.
        $old_enscrypt = get_option( 'mainwp_extensions_master_api_key', false );
        if ( false !== $old_enscrypt && is_string( $old_enscrypt ) && ! empty( $old_enscrypt ) ) { // to compatible.
            // old encrypt.
            $decryp_api_key = ! empty( $old_enscrypt ) ? MainWP_Api_Manager_Password_Management::decrypt_string( $old_enscrypt ) : '';
            // convert to new encrypt: to compatible.
            if ( ! empty( $decryp_api_key ) && is_string( $decryp_api_key ) ) {
                MainWP_Keys_Manager::instance()->update_key_value( 'mainwp_extensions_master_api_key', $decryp_api_key );
            }
        }
        return MainWP_Keys_Manager::instance()->get_keys_value( 'mainwp_extensions_master_api_key' );
    }
}

// Class is instantiated as an object by other classes on-demand.
