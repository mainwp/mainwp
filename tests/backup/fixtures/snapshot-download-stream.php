<?php
/**
 * Isolated stream transport spies; production writes remain real and reads fail only in the read-error modes.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard;

define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__, 3 ) . '/class/backup/class-mainwp-backup-download.php';

$headers = array();
$bytes = '';
$ignore_abort = array();
$time_limits = array();
$reads = 0;
$mode = $argv[2];
$aborting = 'abort' === $mode;

function header( $value ) {
	$GLOBALS['headers'][] = $value;
}

function connection_aborted() {
	return $GLOBALS['aborting'] && '' !== $GLOBALS['bytes'] ? 1 : 0;
}

function ignore_user_abort( $value ) {
	$GLOBALS['ignore_abort'][] = (bool) $value;
	return 0;
}

function fread( $handle, $length ) {
	++$GLOBALS['reads'];
	if ( ( 'first-read-error' === $GLOBALS['mode'] && 1 === $GLOBALS['reads'] ) || ( 'read-error' === $GLOBALS['mode'] && 2 === $GLOBALS['reads'] ) ) {
		return false;
	}
	return \fread( $handle, $length );
}

function __( $text, $domain ) {
	return $text;
}

class MainWP_System_Utility {
	public static function set_time_limit( $timeout = 0 ) {
		$GLOBALS['time_limits'][] = $timeout;
	}
}

class Fixture_Error {
	public function __construct( ...$args ) {
	}
}
class_alias( Fixture_Error::class, 'WP_Error' );

ob_start( static function ( $chunk ) {
	$GLOBALS['bytes'] .= $chunk;
	return '';
} );
$completed = MainWP_Backup_Download::stream_file( $argv[1] );
ob_end_flush();
echo json_encode(
	array(
		'completed'    => $completed instanceof \WP_Error ? 'error' : $completed,
		'bytes'        => base64_encode( $bytes ),
		'headers'      => $headers,
		'ignore_abort' => $ignore_abort,
		'time_limits'  => $time_limits,
	)
);
