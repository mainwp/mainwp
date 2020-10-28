<?php
/**
 * MainWP bootstrap.
 *
 * Set default php.ini variables
 * check if load wp-load & wp-config exist & include them
 * else exit due to "Unsupported WordPress Setup".
 *
 * @package MainWP/Bootstrap
 */

// phpcs:disable -- required to support custom wp-config.php file location

// set php.ini variables.
@ignore_user_abort( true );
if ( false !== strpos( ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
	@set_time_limit( 0 );
}
$mem = '512M';
@ini_set( 'memory_limit', $mem );
@ini_set( 'max_execution_time', 0 );

/**
 * Checks whether cron is in progress.
 *
 * @const ( bool ) Default: true
 * @source https://github.com/mainwp/mainwp/blob/master/cron/bootstrap.php
 */
define( 'DOING_CRON', true );
$included = false;


if ( file_exists( __DIR__ . '/../../../../wp-load.php' ) ) {
	include_once __DIR__ . '/../../../../wp-load.php';
	$included = true;
} elseif ( file_exists( __DIR__ . '/../../../../wp-config.php' ) ) {
	$wp_config = file_get_contents( __DIR__ . '/../../../../wp-config.php' ); // phpcs:ignore -- used before loading WP.
	preg_match_all( '/.*define[^d].*ABSPATH.*/i', $wp_config, $matches );
	if ( count( $matches ) > 0 ) {
		foreach ( $matches as $match ) {
			$execute = str_ireplace( 'ABSPATH', 'TMPABSPATH', $match[0] );
			$execute = str_ireplace( '__FILE__', "'" . __DIR__ . '/../../../../wp-config.php' . "'", $execute );			
			eval( $execute );
			if ( file_exists( TMPABSPATH . 'wp-load.php' ) ) {
				include_once TMPABSPATH . 'wp-load.php';
				$included = true;
				break;
			}
		}
	}
}

// phpcs:enable
if ( ! $included ) {
	exit( 'Unsupported WordPress setup' );
}
