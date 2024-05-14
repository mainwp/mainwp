<?php
/**
 * Defines common functionality used throughout the plugin.
 *
 * @package MainWP\Dashboard
 */

/**
 * Filters a variable with a specified filter.
 *
 * This is a polyfill function intended to be used in place of PHP's
 * filter_var() function, which can occasionally be unreliable.
 *
 * @param string $var_value      Value to filter.
 * @param int    $filter   The ID of the filter to apply.
 * @param mixed  $options  Associative array of options or bitwise disjunction of flags. If filter accepts options, flags can be provided in "flags" field of array. For the "callback" filter, callable type should be passed. The callback must accept one argument, the value to be filtered, and return the value after filtering/sanitizing it.
 *
 * @return Returns the filtered data, or FALSE if the filter fails.
 */
function mainwp_module_log_filter_var( $var_value, $filter = null, $options = array() ) { //phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	return call_user_func_array( array( '\MainWP\Dashboard\Module\Log\Log_Filter_Input', 'filter' ), func_get_args() );
}


/**
 * Converts a time into an ISO 8601 extended formatted string.
 *
 * @param int|bool $time   Seconds since unix epoch.
 * @param int      $offset Timezone offset.
 *
 * @return string an ISO 8601 extended formatted time
 */
function mainwp_module_log_get_iso_8601_extended_date( $time = false, $offset = 0 ) {
	if ( $time ) {
		$microtime = (float) $time . '.0000';
	} else {
		$microtime = microtime( true );
	}

	$micro_seconds = sprintf( '%06d', ( $microtime - floor( $microtime ) ) * 1000000 );
	$offset_string = sprintf( 'Etc/GMT%s%d', $offset < 0 ? '+' : '-', abs( $offset ) );

	$timezone = new DateTimeZone( $offset_string );
	$date     = new DateTime( gmdate( 'Y-m-d H:i:s.' . $micro_seconds, $microtime ), $timezone );

	return $date->format( 'Y-m-d\TH:i:sO' );
}
