<?php
/**
 * MainWP Base Functions.
 *
 * Grab MainWP Directory and check for permissions.
 *
 * @package     MainWP/Dashboard
 * @author Woocommerce Authors.
 */

/**
 * API - Hash.
 *
 * @since  5.2
 * @param  string $data Message to be hashed.
 * @return string
 */
function mainwp_api_hash( $data ) {
    return hash_hmac( 'sha256', $data, 'mainwp-api' );
}

/**
 * Parses and formats a date for ISO8601/RFC3339.
 *
 * Required WP 4.4 or later.
 * See https://developer.wordpress.org/reference/functions/mysql_to_rfc3339/
 *
 * @since  5.2
 * @param  string|null|DateTime $date Date.
 * @param  bool                 $utc  Send false to get local/offset time.
 * @return string|null ISO8601/RFC3339 formatted datetime.
 */
function mainwp_rest_prepare_date_response( $date, $utc = true ) {
    if ( is_numeric( $date ) ) {
        $date = new MainWP_DateTime( "@$date", new DateTimeZone( 'UTC' ) );
        $date->setTimezone( new DateTimeZone( wp_timezone_string() ) );
    } elseif ( is_string( $date ) ) {
        $date = new MainWP_DateTime( $date, new DateTimeZone( 'UTC' ) );
        $date->setTimezone( new DateTimeZone( wp_timezone_string() ) );
    }

    if ( ! is_a( $date, 'MainWP_DateTime' ) ) {
        return null;
    }

    // Get timestamp before changing timezone to UTC.
    return gmdate( 'Y-m-d\TH:i:s', $utc ? $date->getTimestamp() : $date->getOffsetTimestamp() );
}



/**
 * Converts a string (e.g. 'yes' or 'no') to a bool.
 *
 * @since 5.2
 * @param string|bool $str String to convert. If a bool is passed it will be returned as-is.
 * @return bool
 */
function mainwp_string_to_bool( $str ) {
    $str = $str ?? '';
    return is_bool( $str ) ? $str : ( 'yes' === strtolower( $str ) || 1 === $str || 'true' === strtolower( $str ) || '1' === $str );
}

/**
 * Encodes a value according to RFC 3986.
 * Supports multidimensional arrays.
 *
 * @since 2.6.0
 * @param string|array $value The value to encode.
 * @return string|array       Encoded values.
 */
function mainwp_rest_urlencode_rfc3986( $value ) {
    if ( is_array( $value ) ) {
        return array_map( 'mainwp_rest_urlencode_rfc3986', $value );
    }

    return str_replace( array( '+', '%7E' ), array( ' ', '~' ), rawurlencode( $value ) );
}


/**
 * Method mainwp_search_in_array().
 *
 * @param array  $data The data.
 * @param string $search_str search string.
 * @param array  $params params.
 *
 * @return array Search result.
 */
function mainwp_search_in_array( $data, $search_str, $params = array() ) { //phpcs:ignore -- NOSONAR complex function.

    if ( ! is_array( $params ) ) {
        $params = array();
    }

    $search_in_key    = isset( $params['search_in_key'] ) ? $params['search_in_key'] : true;
    $search_in_value  = isset( $params['search_in_value'] ) ? $params['search_in_value'] : true;
    $in_sub_fields    = isset( $params['in_sub_fields'] ) ? $params['in_sub_fields'] : '';
    $use_index_result = isset( $params['use_index_result'] ) ? $params['use_index_result'] : true;

    $search_str = is_string( $search_str ) ? trim( $search_str ) : '';

    if ( empty( $search_str ) ) {
        return $data;
    }

    if ( ! is_array( $data ) || empty( $data ) ) {
        return $data;
    }

    $results = array();

    foreach ( $data as $key => $value ) {
        if ( $search_in_key && false !== stripos( $key, $search_str ) ) {
            if ( $use_index_result ) {
                $results[ $key ] = $value;
            } else {
                $results[] = $value;
            }

            continue;
        }
        if ( $search_in_value && is_string( $value ) && false !== stripos( $value, $search_str ) ) {
            if ( $use_index_result ) {
                $results[ $key ] = $value;
            } else {
                $results[] = $value;
            }
            continue;
        }

        if ( ! empty( $in_sub_fields ) ) {
            if ( is_string( $in_sub_fields ) ) {
                if ( is_array( $value ) && ! empty( $value[ $in_sub_fields ] ) && is_string( $value[ $in_sub_fields ] ) && false !== stripos( $value[ $in_sub_fields ], $search_str ) ) {
                    if ( $use_index_result ) {
                        $results[ $key ] = $value;
                    } else {
                        $results[] = $value;
                    }
                }
            } elseif ( is_array( $in_sub_fields ) ) {
                foreach ( $in_sub_fields as $in_sub ) {
                    if ( is_array( $value ) && ! empty( $value[ $in_sub ] ) && is_string( $value[ $in_sub ] ) && false !== stripos( $value[ $in_sub ], $search_str ) ) {
                        if ( $use_index_result ) {
                            $results[ $key ] = $value;
                        } else {
                            $results[] = $value;
                        }
                    }
                    break;
                }
            }
        }
    }
    return $results;
}
