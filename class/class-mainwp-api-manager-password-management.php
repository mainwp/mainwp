<?php
/**
 * WooCommerce API Password Handler
 *
 * Encrypts & Decrypts API Passwords.
 *
 * @package MainWP/MainWP_API_Passwords_Manager
 */
namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce API Password Handler
 *
 * @package MainWP/MainWP_API_Passwords_Manager
 * @author Todd Lahman LLC
 * @copyright   Copyright (c) Todd Lahman LLC
 * @since 1.0.0
 */
class MainWP_Api_Manager_Password_Management {

	/**
	 * Encryption type
	 *
	 * Sets encryption type.
	 *
	 * @var string $ENCRYPT
	 */
	private static $ENCRYPT = 'AMEncrypt';

	/**
	 * Generate Password
	 *
	 * Creates a unique instance ID.
	 *
	 * @param integer $length Length of ID.
	 * @param boolean $special_chars Valid special characters.
	 * @param boolean $extra_special_chars Extra special characters.
	 *
	 * @return mixed $password
	 */
	public static function generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
		$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		if ( $special_chars ) {
			$chars .= '!@#$%^&*()';
		}
		if ( $extra_special_chars ) {
			$chars .= '-_ []{}<>~`+=,.;:/?|';
		}

		$password = '';
		for ( $i = 0; $i < $length; $i ++ ) {
			$password .= substr( $chars, wp_rand( 0, strlen( $chars ) - 1 ), 1 );
		}

		// random_password filter was previously in random_password function which was deprecated.
		return $password;
	}

	/**
	 * Encrypt String
	 *
	 * Encrypts $str.
	 *
	 * @param mixed $str String to Encrypt.
	 */
	public static function encrypt_string( $str ) {
		return self::encrypt( $str, self::$ENCRYPT );
	}

	/**
	 * Decrypts String
	 *
	 * Decrypts $encrypted
	 *
	 * @param mixed $encrypted Sting to Decrypt.
	 */
	public static function decrypt_string( $encrypted ) {
		return self::decrypt( $encrypted, self::$ENCRYPT );
	}
	
	
	/**
	 * Encrypt String
	 *
	 * Encrypt $encrypted
	 *
	 * @param mixed $str String to Encrypt.
	 * @param mixed $pass String.
	 */
	public static function encrypt( $str, $pass ) {
		$pass = str_split( str_pad( '', strlen( $str ), $pass, STR_PAD_RIGHT ) );
		$stra = str_split( $str );
		foreach ( $stra as $k => $v ) {
			$tmp        = ord( $v ) + ord( $pass[ $k ] );
			$stra[ $k ] = chr( 255 < $tmp ? ( $tmp - 256 ) : $tmp );
		}

		return base64_encode( join( '', $stra ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for benign reasons.
	}

	/**
	 * Decrypts String
	 *
	 * Decrypts $encrypted
	 *
	 * @param mixed $encrypted Sting to Decrypt.
	 * @param mixed $pass Sting.
	 */
	public static function decrypt( $str, $pass ) {
		$str  = base64_decode( $str ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for benign reasons.
		$pass = str_split( str_pad( '', strlen( $str ), $pass, STR_PAD_RIGHT ) );
		$stra = str_split( $str );
		foreach ( $stra as $k => $v ) {
			$tmp        = ord( $v ) - ord( $pass[ $k ] );
			$stra[ $k ] = chr( 0 > $tmp ? ( $tmp + 256 ) : $tmp );
		}

		return join( '', $stra );
	}

}
