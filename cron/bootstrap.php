<?php
@ignore_user_abort(true);
@set_time_limit(0);
$mem =  '512M';
@ini_set('memory_limit', $mem);
@ini_set('max_execution_time', 0);

define('DOING_CRON', true);
$included = false;

if ( file_exists( __DIR__.'/../../../../wp-load.php' ) ) {
	include_once __DIR__ . '/../../../../wp-load.php';
	$included = true;
} else if ( file_exists( __DIR__.'/../../../../wp-config.php' ) ) {
	$wp_config = file_get_contents( __DIR__.'/../../../../wp-config.php' );
	preg_match_all('/.*define[^d].*ABSPATH.*/i', $wp_config, $matches);
	if ( count( $matches ) > 0 ) {
		foreach ( $matches as $match ) {
			$execute = str_ireplace( 'ABSPATH', 'TMPABSPATH', $match[0] );
			$execute = str_ireplace( '__FILE__', "'" . __DIR__.'/../../../../wp-config.php' . "'", $execute );
			eval( $execute );
			if ( file_exists(TMPABSPATH . 'wp-load.php' ) ) {
				include_once TMPABSPATH . 'wp-load.php';
				$included = true;
				break;
			}
		}
	}
}

if ( !$included ) {
	exit( 'Unsupported wordpress setup' );
}