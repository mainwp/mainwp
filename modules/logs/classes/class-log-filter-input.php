<?php
/**
 * Processes form input.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard\Module\Log;

/**
 * Class - Log_Filter_Input
 */
class Log_Filter_Input {
	/**
	 * Callbacks to be used for input validation/sanitation.
	 *
	 * @var array
	 */
	public static $filter_callbacks = array(
		FILTER_DEFAULT                    => null,
		// Validate.
		FILTER_VALIDATE_BOOLEAN           => 'is_bool',
		FILTER_VALIDATE_EMAIL             => 'is_email',
		FILTER_VALIDATE_FLOAT             => 'is_float',
		FILTER_VALIDATE_INT               => 'is_int',
		FILTER_VALIDATE_IP                => array( __CLASS__, 'is_ip_address' ),
		FILTER_VALIDATE_REGEXP            => array( __CLASS__, 'is_regex' ),
		FILTER_VALIDATE_URL               => 'wp_http_validate_url',
		// Sanitize.
		FILTER_SANITIZE_EMAIL             => 'sanitize_email',
		FILTER_SANITIZE_ENCODED           => 'esc_url_raw',
		FILTER_SANITIZE_NUMBER_FLOAT      => 'floatval',
		FILTER_SANITIZE_NUMBER_INT        => 'intval',
		FILTER_SANITIZE_SPECIAL_CHARS     => 'htmlspecialchars',
		FILTER_SANITIZE_STRING_COMPATIBLE => 'sanitize_text_field', // to compatible: FILTER_SANITIZE_STRING.
		FILTER_SANITIZE_URL               => 'esc_url_raw',
		// Other.
		FILTER_UNSAFE_RAW                 => null,
	);

	/**
	 * Returns input variable
	 *
	 * @param int    $type           Input type.
	 * @param string $variable_name  Variable key.
	 * @param int    $filter         Filter callback.
	 * @param array  $options        Filter callback parameters.
	 * @throws \Exception  Invalid input type provided.
	 * @return mixed
	 */
	public static function super( $type, $variable_name, $filter = null, $options = array() ) {
		$super = null;

		// @codingStandardsIgnoreStart
		switch ( $type ) {
			case INPUT_POST :
				$super = $_POST;
				break;
			case INPUT_GET :
				$super = $_GET;
				break;
			case INPUT_COOKIE :
				$super = $_COOKIE;
				break;
			case INPUT_ENV :
				$super = $_ENV;
				break;
			case INPUT_SERVER :
				$super = $_SERVER;
				break;
		}
		// @codingStandardsIgnoreEnd

		if ( is_null( $super ) ) {
			throw new \Exception( esc_html__( 'Invalid use, type must be one of INPUT_* family.', 'mainwp' ) );
		}

		$var = isset( $super[ $variable_name ] ) ? $super[ $variable_name ] : null;
		$var = self::filter( $var, $filter, $options );

		return $var;
	}

	/**
	 * Sanitize or validate input.
	 *
	 * @param mixed $var_value      Raw input.
	 * @param int   $filter   Filter callback.
	 * @param array $options  Filter callback parameters.
	 * @throws \Exception Unsupported filter provided.
	 * @return mixed
	 */
	public static function filter( $var_value, $filter = null, $options = array() ) {
		// Default filter is a sanitizer, not validator.
		$filter_type = 'sanitizer';

		// Only filter value if it is not null.
		if ( isset( $var_value ) && $filter && FILTER_DEFAULT !== $filter ) {
			if ( ! isset( self::$filter_callbacks[ $filter ] ) ) {
				throw new \Exception( esc_html__( 'Filter not supported.', 'mainwp' ) );
			}

			$filter_callback = self::$filter_callbacks[ $filter ];
			$result          = call_user_func( $filter_callback, $var_value );

			/**
			 * "filter_var / filter_input" treats validation/sanitization filters the same
			 * they both return output and change the var value, this shouldn't be the case here.
			 * We'll do a boolean check on validation function, and let sanitizers change the value
			 */
			$filter_type = ( $filter < 500 ) ? 'validator' : 'sanitizer';
			if ( 'validator' === $filter_type ) { // Validation functions.
				if ( ! $result ) {
					$var_value = false;
				}
			} else { // Santization functions.
				$var_value = $result;
			}
		}

		// Detect FILTER_REQUIRE_ARRAY flag.
		if ( isset( $var_value ) && is_int( $options ) && FILTER_REQUIRE_ARRAY === $options ) {
			if ( ! is_array( $var_value ) ) {
				$var_value = ( 'validator' === $filter_type ) ? false : null;
			}
		}

		// Polyfill the `default` attribute only, for now.
		if ( is_array( $options ) && ! empty( $options['options']['default'] ) ) {
			if ( 'validator' === $filter_type && false === $var_value ) {
				$var_value = $options['options']['default'];
			} elseif ( 'sanitizer' === $filter_type && null === $var_value ) {
				$var_value = $options['options']['default'];
			}
		}

		return $var_value;
	}

	/**
	 * Returns whether the variable is a Regular Expression or not?
	 *
	 * @param string $var_value  Raw input.
	 * @return boolean
	 */
	public static function is_regex( $var_value ) {
		// @codingStandardsIgnoreStart
		$test = @preg_match( $var_value, '' );
		// @codingStandardsIgnoreEnd

		return false !== $test;
	}

	/**
	 * Returns whether the variable is an IP address or not?
	 *
	 * @param string $var_value  Raw input.
	 * @return boolean
	 */
	public static function is_ip_address( $var_value ) {
		return false !== \WP_Http::is_ip_address( $var_value );
	}
}
