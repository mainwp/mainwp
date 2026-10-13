<?php
/**
 * Isolated stream transport spies; production reads and writes remain real.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard;

define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__, 3 ) . '/class/backup/class-mainwp-backup-download.php';

$headers = array();
$bytes = '';
$ignore_abort = array();
$aborting = 'abort' === $argv[2];

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

ob_start( static function ( $chunk ) {
	$GLOBALS['bytes'] .= $chunk;
	return '';
} );
$completed = MainWP_Backup_Download::stream_file( $argv[1] );
ob_end_flush();
echo json_encode( array( 'completed' => $completed, 'bytes' => base64_encode( $bytes ), 'headers' => $headers, 'ignore_abort' => $ignore_abort ) );
