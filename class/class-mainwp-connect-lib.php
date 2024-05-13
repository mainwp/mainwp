<?php
/**
 * MainWP Connect Lib
 *
 * MainWP Connect Lib functions.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\PublicKeyLoader;

/**
 * Class MainWP_Connect_Lib
 *
 * @package MainWP\Dashboard
 */
class MainWP_Connect_Lib {

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
	 * Create a public static instance.
	 *
	 * @static
	 * @return Instance class.
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Method get_class_name()
	 *
	 * Get Class Name.
	 *
	 * @return object Class name.
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Class constructor.
	 *
	 * Run each time the class is called.
	 */
	public function __construct() {
	}

	/**
	 * Method create_connect_keys()
	 *
	 * Create connect keys.
	 */
	public function create_connect_keys() {
		try {
			//phpcs:ignore -- note.
			// RSA::useInternalEngine(); // to use PHP engine.
			$private     = RSA::createKey();
			$public      = $private->getPublicKey();
			$private_key = $private->toString( 'PKCS1' );
			$public_key  = $public->toString( 'PKCS1' );
			return array(
				'pub'  => $public_key,
				'priv' => $private_key,
			);
		} catch ( \Exception $ex ) {
			// error happen.
		}
		return false;
	}

	/**
	 * Method connect_sign()
	 *
	 * Sign to connect.
	 *
	 * @param mixed $data The data.
	 * @param mixed $signature The signature.
	 * @param mixed $privkey The privkey.
	 */
	public static function connect_sign( $data, &$signature, $privkey ) {
		try {
			$private   = PublicKeyLoader::loadPrivateKey( $privkey );
			$signature = $private->sign( $data );
			if ( ! empty( $signature ) ) {
				return true;
			}
		} catch ( \Exception $ex ) {
			// error happen.
		}
		return false;
	}


	/**
	 * Method is_use_fallback_sec_lib()
	 *
	 * Use php connect lib or not.
	 *
	 * @param mixed $website The website.
	 */
	public static function is_use_fallback_sec_lib( $website ) {
		$use_fallback_lib = false;
		if ( is_object( $website ) && property_exists( $website, 'verify_method' ) ) {
			$val = (int) $website->verify_method;
			if ( 2 === $val ) {
				$use_fallback_lib = true;
			} elseif ( 3 === $val || empty( $val ) ) { // use general settings.
				$use_fallback_lib = ( 2 === (int) get_option( 'mainwp_verify_connection_method' ) ) ? true : false;
			}
		} else {
			$use_fallback_lib = ( 2 === (int) get_option( 'mainwp_verify_connection_method' ) ) ? true : false;
		}
		return $use_fallback_lib;
	}

	/**
	 * Method get_connection_algo_settings_note()
	 *
	 * Get connection settings note.
	 */
	public static function get_connection_algo_settings_note() {
		return esc_html__( 'Due to security reasons, switching back to OPENSSL_ALGO_SHA1 breaks the connection to your child site(s). It is required to deactivate & reactivate the MainWP Child plugin on child sites before you can reconnect them. Use OPENSSL_ALGO_SHA1 only if necessary.', 'mainwp' );
	}
}
