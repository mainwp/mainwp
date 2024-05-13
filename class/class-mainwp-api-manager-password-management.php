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
 * Class MainWP_Api_Manager_Password_Management
 *
 * WooCommerce API Password Handler.
 *
 * @package MainWP/MainWP_API_Passwords_Manager
 * @author Todd Lahman LLC
 * @copyright   Copyright (c) Todd Lahman LLC
 * @since 1.0.0
 */
class MainWP_Api_Manager_Password_Management {

	/**
	 * Set encryption type.
	 *
	 * @var string $ENCRYPT
	 */
	private static $ENCRYPT = 'AMEncrypt';

	/**
	 * Generate password. Creates a unique instance ID.
	 *
	 * @param int  $length              Length of ID.
	 * @param bool $special_chars       Valid special characters.
	 * @param bool $extra_special_chars Extra special characters.
	 *
	 * @return string Password.
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
		for ( $i = 0; $i < $length; $i++ ) {
			$password .= substr( $chars, wp_rand( 0, strlen( $chars ) - 1 ), 1 );
		}

		// random_password filter was previously in random_password function which was deprecated.
		return $password;
	}

	/**
	 * Encrypt string.
	 *
	 * @param string $str String to encrypt.
	 *
	 * @return string Encrypted string.
	 */
	public static function encrypt_string( $str ) {
		return self::encrypt( $str, self::$ENCRYPT );
	}

	/**
	 * Decrypts string.
	 *
	 * @param string $encrypted Sting to decrypt.
	 *
	 * @return string Decrypted string.
	 */
	public static function decrypt_string( $encrypted ) {
		return self::decrypt( $encrypted, self::$ENCRYPT );
	}


	/**
	 * Encrypt.
	 *
	 * @param string $str  String to encrypt.
	 * @param string $pass String.
	 *
	 * @return string Encrypted string.
	 */
	public static function encrypt( $str, $pass ) {
		if ( ! is_string( $str ) ) {
			return '';
		}
		$pass = str_split( str_pad( '', strlen( $str ), $pass, STR_PAD_RIGHT ) );
		$stra = str_split( $str );
		foreach ( $stra as $k => $v ) {
			$tmp        = ord( $v ) + ord( $pass[ $k ] );
			$stra[ $k ] = chr( 255 < $tmp ? ( $tmp - 256 ) : $tmp );
		}

		return base64_encode( join( '', $stra ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
	}

	/**
	 * Decrypt.
	 *
	 * @param string $str String to Decrypt.
	 * @param string $pass String.
	 *
	 * @return string Decrypted string.
	 */
	public static function decrypt( $str, $pass ) {
		if ( ! is_string( $str ) ) {
			return '';
		}
		$str  = base64_decode( $str ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
		$pass = str_split( str_pad( '', strlen( $str ), $pass, STR_PAD_RIGHT ) );
		$stra = str_split( $str );
		foreach ( $stra as $k => $v ) {
			$tmp        = ord( $v ) - ord( $pass[ $k ] );
			$stra[ $k ] = chr( 0 > $tmp ? ( $tmp + 256 ) : $tmp );
		}

		return join( '', $stra );
	}
}
