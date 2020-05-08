<?php
/**
 * MainWP Utility Helper.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// phpcs:disable WordPress.DB.RestrictedFunctions, WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors -- Using cURL functions.

/**
 * MainWP Utility.
 */
class MainWP_Utility {

	/**
	 * @static
	 * @var (null|true) $enabled_wp_seo If Yoast SEO is enabled return true else return null.
	 */
	public static $enabled_wp_seo = null;

	/**
	 * Method get_class_name()
	 *
	 * Get Class Name.
	 *
	 * @return object __CLASS__
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Method starts_with()
	 *
	 * Start of Stack Trace.
	 *
	 * @param mixed $haystack The full stack.
	 * @param mixed $needle The function that is throwing the error.
	 *
	 * @return mixed Needle in the Haystack.
	 */
	public static function starts_with( $haystack, $needle ) {
		return ! strncmp( $haystack, $needle, strlen( $needle ) );
	}

	/**
	 * Method ends_with()
	 *
	 * End of Stack Trace.
	 *
	 * @param mixed $haystack
	 * @param mixed $needle
	 *
	 * @return mixed True|substr( $haystack, - $length ) === $needle.
	 */
	public static function ends_with( $haystack, $needle ) {
		$length = strlen( $needle );
		if ( 0 === $length ) {
			return true;
		}

		return ( substr( $haystack, - $length ) === $needle );
	}

	/**
	 * Method get_nice_url()
	 *
	 * Grab url.
	 *
	 * @param mixed   $pUrl
	 * @param boolean $showHttp
	 *
	 * @return string $url.
	 */
	public static function get_nice_url( $pUrl, $showHttp = false ) {
		$url = $pUrl;

		if ( self::starts_with( $url, 'http://' ) ) {
			if ( ! $showHttp ) {
				$url = substr( $url, 7 );
			}
		} elseif ( self::starts_with( $pUrl, 'https://' ) ) {
			if ( ! $showHttp ) {
				$url = substr( $url, 8 );
			}
		} else {
			if ( $showHttp ) {
				$url = 'http://' . $url;
			}
		}

		if ( self::ends_with( $url, '/' ) ) {
			if ( ! $showHttp ) {
				$url = substr( $url, 0, strlen( $url ) - 1 );
			}
		} else {
			$url = $url . '/';
		}

		return $url;
	}

	/**
	 * Method is_domain_valid()
	 *
	 * Check $url against FILTER_VALIDATE_URL.
	 *
	 * @param mixed $url Domain to check.
	 * 
	 * @return boolean True|False.
	 */
	public static function is_domain_valid( $url ) {
		return filter_var( $url, FILTER_VALIDATE_URL );
	}

	/**
	 * Method json_convert_string()
	 *
	 * Convert content into utf8 encoding.
	 *
	 * @param mixed $mixed
	 *
	 * @return mixed $mixed
	 */
	public static function json_convert_string( $mixed ) {
		if ( is_array( $mixed ) ) {
			foreach ( $mixed as $key => $value ) {
				$mixed[ $key ] = self::json_convert_string( $value );
			}
		} elseif ( is_string( $mixed ) ) {
			if ( function_exists( 'mb_convert_encoding' ) ) {
				return mb_convert_encoding( $mixed, 'UTF-8', 'UTF-8' );
			}
		}
		return $mixed;
	}

	/**
	 * Method safe_json_encode()
	 *
	 * @param mixed   $value String to encode.
	 * @param integer $options Options for encoding.
	 * @param integer $depth Depth to encode to.
	 *
	 * @return mixed $encoded Encoded String.
	 */
	public static function safe_json_encode( $value, $options = 0, $depth = 512 ) {
		$encoded = wp_json_encode( $value, $options, $depth );
		if ( false === $encoded && $value && json_last_error() == JSON_ERROR_UTF8 ) {
			$encoded = wp_json_encode( self::json_convert_string( $value ), $options, $depth );
		}
		return $encoded;
	}

	/**
	 * Method ctype_digit() 
	 * 
	 * Returns TRUE if every character in the string text is a decimal digit, FALSE otherwise. 
	 * 
	 * @param mixed $str String to check.
	 * 
	 * @return boolean Returns TRUE if every character in the string text is a decimal digit, FALSE otherwise. 
	 */
	public static function ctype_digit( $str ) {
		return ( is_string( $str ) || is_int( $str ) || is_float( $str ) ) && preg_match( '/^\d+\z/', $str );
	}

	/**
	 * Method sortmulti()
	 * 
	 * Sort the given array, Acending, Decending or by Natural Order.
	 * 
	 * @param mixed $array Array to sort.
	 * @param mixed $index Index of array.
	 * @param mixed $order Acending or Decending order.
	 * @param boolean $natsort Sort an array using a "natural order" algorithm. Default: false.
	 * @param boolean $case_sensitive If case sensitive return true else return false. Default: false.
	 * 
	 * @return array $sorted Return the sorted array.
	 */
	public static function sortmulti( $array, $index, $order, $natsort = false, $case_sensitive = false ) {
		$sorted = array();
		if ( is_array( $array ) && 0 < count( $array ) ) {
			foreach ( array_keys( $array ) as $key ) {
				$temp[ $key ] = $array[ $key ][ $index ];
			}
			if ( ! $natsort ) {
				if ( 'asc' === $order ) {
					asort( $temp );
				} else {
					arsort( $temp );
				}
			} else {
				if ( true === $case_sensitive ) {
					natsort( $temp );
				} else {
					natcasesort( $temp );
				}
				if ( 'asc' !== $order ) {
					$temp = array_reverse( $temp, true );
				}
			}
			foreach ( array_keys( $temp ) as $key ) {
				if ( is_numeric( $key ) ) {
					$sorted[] = $array[ $key ];
				} else {
					$sorted[ $key ] = $array[ $key ];
				}
			}

			return $sorted;
		}

		return $sorted;
	}

	/**
	 * Method get_sub_array_having()
	 * 
	 * Get sub array. 
	 * 
	 * @param mixed $array Array to traverse.
	 * @param mixed $index Index of array.
	 * @param mixed $value Array values.
	 * 
	 * void array $output Sub array.
	 */
	public static function get_sub_array_having( $array, $index, $value ) {
		$output = array();
		if ( is_array( $array ) && 0 < count( $array ) ) {
			foreach ( $array as $arrvalue ) {
				if ( $arrvalue[ $index ] == $value ) {
					$output[] = $arrvalue;
				}
			}
		}

		return $output;
	}

	/**
	 * Method trim_slashes()
	 * 
	 * Trim stashes from element.
	 * 
	 * @param mixed $elem Element to trim.
	 * 
	 * @return string Return string with no slashes.
	 */
	public static function trim_slashes( $elem ) {
		return trim( $elem, '/' );
	}

	/**
	 * Method sanitize()
	 * 
	 * Sanitize given string.
	 * 
	 * @param mixed $str String to sanitize.
	 * 
	 * @return string Sanitized string.
	 */
	public static function sanitize( $str ) {
		return preg_replace( '/[\\\\\/\:"\*\?\<\>\|]+/', '', $str );
	}

	/**
	 * Method end_session()
	 * 
	 * End a session.
	 * 
	 * @return void
	 */
	public static function end_session() {
		session_write_close();
		if ( 0 < ob_get_length() ) {
			ob_end_flush();
		}
	}

	/**
	 * Method get_timestamp()
	 * 
	 * Get time stamp in gmt_offset.
	 * 
	 * @param mixed $timestamp Time stamp to convert.
	 * 
	 * @return string Time stamp in general mountain time offset.
	 */
	public static function get_timestamp( $timestamp ) {
		$gmtOffset = get_option( 'gmt_offset' );

		return ( $gmtOffset ? ( $gmtOffset * HOUR_IN_SECONDS ) + $timestamp : $timestamp );
	}

	/**
	 * Method date()
	 * 
	 * Show date in given format.
	 * 
	 * @param mixed $format Format to display date in.
	 * 
	 * @return string Date.
	 */
	public static function date( $format ) {
		// phpcs:ignore -- use local date function.
		return date( $format, self::get_timestamp( time() ) );
	}

	/**
	 * Method format_timestamp()
	 * 
	 * Format the given timestamp.
	 * 
	 * @param mixed $timestamp Timestamp to format.
	 * 
	 * @return string Formatted timestamp.
	 */
	public static function format_timestamp( $timestamp ) {
		return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * Method human_filesize()
	 * 
	 * Convert to human readable file size format,
	 * (B|kB|MB|GB|TB|PB|EB|ZB|YB).
	 * 
	 * @param mixed $bytes File in bytes.
	 * @param integer $decimals Number of decimals to output.
	 * 
	 * @return string Human readable file size.
	 */
	public static function human_filesize( $bytes, $decimals = 2 ) {
		$size   = array( 'B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB' );
		$factor = floor( ( strlen( $bytes ) - 1 ) / 3 );

		return sprintf( "%.{$decimals}f", $bytes / pow( 1024, $factor ) ) . @$size[ $factor ];
	}

	/**
	 * Method map_site()
	 * 
	 * Map Site.
	 * 
	 * @param mixed $website Website to map.
	 * @param mixed $keys Keys to map.
	 * 
	 * @return object $outputSite Mapped site.
	 */
	public static function map_site( &$website, $keys ) {
		$outputSite = array();
		foreach ( $keys as $key ) {
			$outputSite[ $key ] = $website->$key;
		}

		return (object) $outputSite;
	}

	/**
	 * Method map_site_array()
	 * 
	 * Map Site array.
	 * 
	 * @param mixed $website Website to map.
	 * @param mixed $keys Keys to map.
	 * 
	 * @return object $outputSite Mapped site.
	 */
	public static function map_site_array( &$website, $keys ) {
		$outputSite = array();
		foreach ( $keys as $key ) {
			$outputSite[ $key ] = $website->$key;
		}

		return $outputSite;
	}

	/**
	 * Method sec2hms()
	 * 
	 * Convert seconds to Hours:Minutes.
	 * 
	 * @param mixed $sec Time in seconds.
	 * @param boolean $padHours Hpurs to pad.
	 * 
	 * @return string $hms Time in Hours:Minutes.
	 */
	public static function sec2hms( $sec, $padHours = false ) {

		$hms     = '';
		$hours   = intval( intval( $sec ) / 3600 );
		$hms    .= ( $padHours ) ? str_pad( $hours, 2, '0', STR_PAD_LEFT ) . ':' : $hours . ':';
		$minutes = intval( ( $sec / 60 ) % 60 );
		$hms    .= str_pad( $minutes, 2, '0', STR_PAD_LEFT ) . ':';
		$seconds = intval( $sec % 60 );
		$hms    .= str_pad( $seconds, 2, '0', STR_PAD_LEFT );

		return $hms;
	}

	/**
	 * Method array_merge()
	 * 
	 * Merge two given arrays into one.
	 * 
	 * @param mixed $arr1 First array.
	 * @param mixed $arr2 Second array.
	 * 
	 * @return array Merged Array.
	 */
	public static function array_merge( $arr1, $arr2 ) {
		if ( ! is_array( $arr1 ) && ! is_array( $arr2 ) ) {
			return array();
		}
		if ( ! is_array( $arr1 ) ) {
			return $arr2;
		}
		if ( ! is_array( $arr2 ) ) {
			return $arr1;
		}

		$output = array();
		foreach ( $arr1 as $el ) {
			$output[] = $el;
		}
		foreach ( $arr2 as $el ) {
			$output[] = $el;
		}

		return $output;
	}

	/**
	 * Method update_option()
	 * 
	 * Update option.
	 * 
	 * @param mixed $option_name Option name.
	 * @param mixed $option_value Option value.
	 * 
	 * @return (boolean) False if value was not updated and true if value was updated.
	 */
	public static function update_option( $option_name, $option_value ) {
		$success = add_option( $option_name, $option_value, '', 'no' );

		if ( ! $success ) {
			$success = update_option( $option_name, $option_value );
		}

		return $success;
	}

	/**
	 * Method remove_preslash_spaces()
	 * 
	 * Remove spaces before slashes.
	 * 
	 * @param string $text String to strip.
	 * 
	 * @return string $text Cleaned string.
	 */
	public static function remove_preslash_spaces( $text ) {
		while ( stristr( $text, ' /' ) ) {
			$text = str_replace( ' /', '/', $text );
		}

		return $text;
	}

	/**
	 * Method remove_http_prefix()
	 * 
	 * Remove http prefixes from given url.
	 * 
	 * @param mixed $pUrl Given URL.
	 * @param (boolean) $pTrimSlashes Whether or not to trim slashes. Default is false.
	 * 
	 * @return void
	 */
	public static function remove_http_prefix( $pUrl, $pTrimSlashes = false ) {
		return str_replace( array( 'http:' . ( $pTrimSlashes ? '//' : '' ), 'https:' . ( $pTrimSlashes ? '//' : '' ) ), array( '', '' ), $pUrl );
	}

	/**
	 * Method remove_http_www_prefix()
	 * 
	 * Remove 'www.' from given URL.
	 * 
	 * @param mixed $pUrl Given URL.
	 * 
	 * @return string Cleaned URL.
	 */
	public static function remove_http_www_prefix( $pUrl ) {
		$pUrl = self::remove_http_prefix( $pUrl, true );
		return str_replace( 'www', '', $pUrl );
	}

	/**
	 * Method sanitize_file_name()
	 * 
	 * Sanitize file names.
	 * 
	 * @param mixed $filename File name to sanitize.
	 * 
	 * @return string Sanitized filename.
	 */
	public static function sanitize_file_name( $filename ) {
		$filename = str_replace( array( '|', '/', '\\', ' ', ':' ), array( '-', '-', '-', '-', '-' ), $filename );

		return sanitize_file_name( $filename );
	}

	/**
	 * Method normalize_filename()
	 * 
	 * Normalize filename.
	 * 
	 * @param mixed $s Filename to normalize.
	 * 
	 * @return string $s Normalised filename.
	 */
	public static function normalize_filename( $s ) {
		$s = preg_replace( '@\x{00c4}@u', 'A', $s );
		$s = preg_replace( '@\x{00d6}@u', 'O', $s );
		$s = preg_replace( '@\x{00dc}@u', 'U', $s );
		$s = preg_replace( '@\x{00cb}@u', 'E', $s );
		$s = preg_replace( '@\x{00e4}@u', 'a', $s );
		$s = preg_replace( '@\x{00f6}@u', 'o', $s );
		$s = preg_replace( '@\x{00fc}@u', 'u', $s );
		$s = preg_replace( '@\x{00eb}@u', 'e', $s );
		$s = preg_replace( '@\x{00f1}@u', 'n', $s );
		$s = preg_replace( '@\x{00ff}@u', 'y', $s );
		return $s;
	}


	/**
	 * Method esc_content()
	 * 
	 * Escape content,
	 * allowed content (a,href,title,br,em,strong,p,hr,ul,ol,li,h1,h2).
	 * 
	 * @param mixed $content Content to escape.
	 * @param string $type Type of content. Default = note.
	 * 
	 * @return string Filtered content containing only the allowed HTML.
	 */
	public static function esc_content( $content, $type = 'note' ) {
		if ( 'note' === $type ) {

			$allowed_html = array(
				'a'      => array(
					'href'  => array(),
					'title' => array(),
				),
				'br'     => array(),
				'em'     => array(),
				'strong' => array(),
				'p'      => array(),
				'hr'     => array(),
				'ul'     => array(),
				'ol'     => array(),
				'li'     => array(),
				'h1'     => array(),
				'h2'     => array(),
			);

			$content = wp_kses( $content, $allowed_html );

		} else {
			$content = wp_kses_post( $content );
		}

		return $content;
	}

	/**
	 * Method show_mainwp_message()
	 * 
	 * Check whenther or not to show the MainWP Message.
	 *
	 * @param mixed $type Type of message.
	 * @param mixed $notice_id Notice ID.
	 * 
	 * @return boolean true|false.
	 */
	public static function show_mainwp_message( $type, $notice_id ) {
		$status = get_user_option( 'mainwp_notice_saved_status' );
		if ( ! is_array( $status ) ) {
			$status = array();
		}
		if ( isset( $status[ $notice_id ] ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Method array_sort()
	 * 
	 * Sort given array by given flags.
	 * 
	 * @param mixed $array Array to sort.
	 * @param mixed $key Array key.
	 * @param string $sort_flag Flags to sort by. Default = SORT_STRING.
	 * 
	 * @return array Sorted array.
	 */
	public static function array_sort( &$array, $key, $sort_flag = SORT_STRING ) {
		$sorter = array();
		$ret    = array();
		reset( $array );
		foreach ( $array as $ii => $val ) {
			$sorter[ $ii ] = $val[ $key ];
		}
		asort( $sorter, $sort_flag );
		foreach ( $sorter as $ii => $val ) {
			$ret[ $ii ] = $array[ $ii ];
		}
		$array = $ret;
	}

	/**
	 * Method enabled_wp_seo()
	 * 
	 * Check if Yoast SEO is enabled.
	 * 
	 * @return boolean true|false.
	 */
	public static function enabled_wp_seo() {
		if ( null === self::$enabled_wp_seo ) {
			self::$enabled_wp_seo = is_plugin_active( 'wordpress-seo-extension/wordpress-seo-extension.php' );
		}
		return self::$enabled_wp_seo;
	}

	/**
	 * Method value_to_string()
	 * 
	 * Value to string.
	 * 
	 * @param mixed $var Value to convert to string.
	 * 
	 * @return string Value that has been converted into a string.
	 */
	public static function value_to_string( $var ) {
		if ( is_array( $var ) || is_object( $var ) ) {
			//phpcs:ignore -- for debug only
			return print_r( $var, true );  // @codingStandardsIgnoreLine
		} elseif ( is_string( $var ) ) {
			return $var;
		}
		return '';
	}

	/**
	 * Method render_mainwp_nonce()
	 * 
	 * Render MainWP nonce.
	 * 
	 * @return string Nonce field HTML markup.
	 */
	public static function render_mainwp_nonce() {
		wp_nonce_field( 'mainwp-admin-nonce' );
	}

}
