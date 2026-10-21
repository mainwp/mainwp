<?php
/**
 * An immediate FTP rejection makes attempted redirects observable without a timeout.
 *
 * @package MainWP\Dashboard\Tests
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security -- isolated CLI fixture; paths come from the test process.
$listener = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $message );
if ( false === $listener ) {
	exit( 1 );
}
file_put_contents( $argv[1], stream_socket_get_name( $listener, false ) );
while ( true ) {
	$connection = stream_socket_accept( $listener, 5 );
	if ( false !== $connection ) {
		file_put_contents( $argv[2], 'contacted' );
		fwrite( $connection, "421 Fixture refuses FTP\r\n" );
		fclose( $connection );
	}
}
