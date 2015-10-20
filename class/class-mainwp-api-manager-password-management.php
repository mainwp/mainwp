<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

/**
 * WooCommerce API Manager Passwords Class
 *
 * @package Update API Manager/Passwords
 * @author Todd Lahman LLC
 * @copyright   Copyright (c) Todd Lahman LLC
 * @since 1.0.0
 *
 */
class MainWP_Api_Manager_Password_Management {
	private static $ENCRYPT = 'AMEncrypt';

	private function rand( $min = 0, $max = 0 ) {
		global $rnd_value;

		// Reset $rnd_value after 14 uses
		// 32(md5) + 40(sha1) + 40(sha1) / 8 = 14 random numbers from $rnd_value
		if ( strlen( $rnd_value ) < 8 ) {
			if ( defined( 'WP_SETUP_CONFIG' ) ) {
				static $seed = '';
			} else {
				$seed = get_transient( 'random_seed' );
			}
			$rnd_value = md5( uniqid( microtime() . mt_rand(), true ) . $seed );
			$rnd_value .= sha1( $rnd_value );
			$rnd_value .= sha1( $rnd_value . $seed );
			$seed = md5( $seed . $rnd_value );
			if ( ! defined( 'WP_SETUP_CONFIG' ) ) {
				set_transient( 'random_seed', $seed );
			}
		}

		// Take the first 8 digits for our value
		$value = substr( $rnd_value, 0, 8 );

		// Strip the first eight, leaving the remainder for the next call to wp_rand().
		$rnd_value = substr( $rnd_value, 8 );

		$value = abs( hexdec( $value ) );

		// Some misconfigured 32bit environments (Entropy PHP, for example) truncate integers larger than PHP_INT_MAX to PHP_INT_MAX rather than overflowing them to floats.
		$max_random_number = 3000000000 === 2147483647 ? (float) '4294967295' : 4294967295; // 4294967295 = 0xffffffff

		// Reduce the value to be within the min - max range
		if ( $max != 0 ) {
			$value = $min + ( $max - $min + 1 ) * $value / ( $max_random_number + 1 );
		}

		return abs( intval( $value ) );
	}

	// Creates a unique instance ID
	public function generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
		$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		if ( $special_chars ) {
			$chars .= '!@#$%^&*()';
		}
		if ( $extra_special_chars ) {
			$chars .= '-_ []{}<>~`+=,.;:/?|';
		}

		$password = '';
		for ( $i = 0; $i < $length; $i ++ ) {
			$password .= substr( $chars, self::rand( 0, strlen( $chars ) - 1 ), 1 );
		}

		// random_password filter was previously in random_password function which was deprecated
		return $password;
	}

	public static function encrypt_string( $str ) {
		return MainWP_Utility::encrypt( $str, self::$ENCRYPT );
	}

	public static function decrypt_string( $encrypted ) {
		return MainWP_Utility::decrypt( $encrypted, self::$ENCRYPT );
	}
}
