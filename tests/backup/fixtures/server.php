<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- standalone test fixture.
/**
 * Test fixture HTTP server for backup download tests.
 *
 * Run with: php -S 127.0.0.1:<port> tests/backup/fixtures/server.php
 *
 * Routes simulate the child site download endpoint in its various
 * behaviors: honoring Range, ignoring Range, 416 responses, server
 * errors, stalls, and truncated transfers.
 *
 * @package MainWP\Dashboard\Tests
 */

// phpcs:disable WordPress.Security, WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions -- standalone fixture, no WordPress loaded.

/**
 * Deterministic body content for a given size.
 *
 * @param int $size Byte length.
 *
 * @return string Content.
 */
function fixture_content( $size ) {
    return substr( str_repeat( '0123456789ABCDEF', (int) ceil( $size / 16 ) ), 0, $size );
}

$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
// Sync requests post to the Child's wp-admin/ path.
$path = preg_replace( '#^/wp-admin/#', '/', $path );
$size = isset( $_GET['size'] ) ? (int) $_GET['size'] : 4096;
$body = fixture_content( $size );

$range_header = isset( $_SERVER['HTTP_RANGE'] ) ? $_SERVER['HTTP_RANGE'] : '';
$range_start  = false;
if ( preg_match( '/bytes=(\d+)-/', $range_header, $m ) ) {
    $range_start = (int) $m[1];
}

switch ( $path ) {
    case '/sync-ftp':
        header( 'Location: ftp://127.0.0.1:' . (int) $_GET['port'] . '/child-response', true, 302 );
        break;

    case '/sync-chain':
        $hops = isset( $_GET['hops'] ) ? (int) $_GET['hops'] : 0;
        if ( $hops > 0 ) {
            header( 'Location: /sync-chain?hops=' . ( $hops - 1 ), true, 302 );
            break;
        }
        // Falls through.
    case '/sync-response':
        echo '<mainwp>' . base64_encode( json_encode( array( 'child_url_fixture' => 'ok' ) ) ) . '</mainwp>';
        break;

    case '/sync-301':
        header( 'Location: /sync-response', true, 301 );
        break;

    case '/child-icon.png':
    case '/favi.php.png':
        header( 'Content-Type: image/png' );
        echo fixture_content( 64 );
        break;

    case '/redirect-other-host':
        header( 'Location: http://localhost:' . (int) $_SERVER['SERVER_PORT'] . '/redirect-target?marker=' . rawurlencode( $_GET['marker'] ?? '' ) );
        break;

    case '/redirect-same-host':
        header( 'Location: /200?size=' . $size, true, 302 );
        break;

    case '/redirect-target.png':
    case '/redirect-target':
        $marker = $_GET['marker'] ?? '';
        if ( preg_match( '/^mainwp-redirect-[a-f0-9]+$/D', $marker ) ) {
            // nosemgrep: AIK_php-file-operations -- test fixture; the anchored pattern above allows only a fixed prefix plus hex, no path separators.
            file_put_contents( sys_get_temp_dir() . '/' . $marker, 'contacted' );
        }
        header( 'Content-Length: ' . $size );
        echo $body;
        break;

    case '/200':
        // Plain full response; Range is never sent to this route by tests.
        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Length: ' . $size );
        echo $body;
        break;

    case '/206':
        // Honors single-range requests like the updated child site.
        if ( false !== $range_start ) {
            if ( $range_start >= $size ) {
                http_response_code( 416 );
                header( 'Content-Range: bytes */' . $size );
                break;
            }
            http_response_code( 206 );
            $end = $size - 1;
            header( 'Content-Type: application/octet-stream' );
            header( 'Content-Range: bytes ' . $range_start . '-' . $end . '/' . $size );
            header( 'Content-Length: ' . ( $size - $range_start ) );
            echo substr( $body, $range_start );
            break;
        }
        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Length: ' . $size );
        echo $body;
        break;

    case '/oversized-stream':
        // Compromised child: ignores Range and streams twice the declared size.
        header( 'Content-Type: application/octet-stream' );
        for ( $sent = 0; $sent < 2 * $size; $sent += 4096 ) {
            echo fixture_content( min( 4096, 2 * $size - $sent ) );
            flush();
        }
        break;

    case '/range-ignored':
        // Old child: ignores Range entirely, always 200 + full body.
        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Length: ' . $size );
        echo $body;
        break;

    case '/416-then-200':
        // 416 for any Range request, full 200 when no Range is sent.
        if ( false !== $range_start ) {
            http_response_code( 416 );
            header( 'Content-Range: bytes */' . $size );
            break;
        }
        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Length: ' . $size );
        echo $body;
        break;

    case '/206-wrong-start':
        // Broken server: confirms 206 but from byte 0 regardless of the request.
        http_response_code( 206 );
        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Range: bytes 0-' . ( $size - 1 ) . '/' . $size );
        header( 'Content-Length: ' . $size );
        echo $body;
        break;

    case '/500':
        http_response_code( 500 );
        header( 'Content-Type: text/html' );
        echo '<html><body><h1>Fatal error</h1><p>Allowed memory size exhausted in /var/www/html/wp-content/plugins/x.php</p></body></html>';
        break;

    case '/slow-drip':
        // Sends a trickle of bytes with pauses to trigger stall detection.
        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Length: ' . $size );
        for ( $i = 0; $i < 5; $i++ ) {
            echo substr( $body, $i * 10, 10 );
            flush();
            sleep( 2 );
        }
        echo substr( $body, 50 );
        break;

    case '/sleep':
        // Never sends a body within the test timeout window.
        sleep( isset( $_GET['s'] ) ? (int) $_GET['s'] : 5 );
        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Length: ' . $size );
        echo $body;
        break;

    case '/partial':
        // Declares the full length but dies after `send` bytes.
        $send = isset( $_GET['send'] ) ? (int) $_GET['send'] : 1024;
        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Length: ' . $size );
        echo substr( $body, 0, $send );
        flush();
        exit;

    case '/bounded':
        // Child 6.3+ resolve_download_range(): one bytes=START-END or START- range, end clamped to EOF.
        // limit=N answers 413 when the declared response length exceeds N, like a server response cap.
        // log=mainwp-ranges-<hex> appends "<m>|<Range>" per request; fault=<kind>&at=<n> breaks request n.
        $request_no = 1;
        $log        = isset( $_GET['log'] ) ? (string) $_GET['log'] : '';
        if ( preg_match( '/^mainwp-ranges-[a-f0-9]+$/D', $log ) ) {
            // nosemgrep: AIK_php-file-operations -- test fixture; the anchored pattern above allows only a fixed prefix plus hex.
            $log_file   = sys_get_temp_dir() . '/' . $log;
            $request_no = ( is_file( $log_file ) ? count( file( $log_file ) ) : 0 ) + 1;
            file_put_contents( $log_file, ( $_GET['m'] ?? '' ) . '|' . $range_header . '|' . ( $_SERVER['HTTP_X_MAINWP_PROBE'] ?? '' ) . "\n", FILE_APPEND );
        }
        $fault_at = array_map( 'intval', explode( ',', (string) ( $_GET['at'] ?? '1' ) ) );
        $fault    = ( isset( $_GET['fault'] ) && in_array( $request_no, $fault_at, true ) ) ? (string) $_GET['fault'] : '';

        if ( '500' === $fault ) {
            http_response_code( 500 );
            header( 'Content-Type: text/html' );
            echo '<html><body><h1>Internal Server Error</h1></body></html>';
            break;
        }
        if ( '416' === $fault ) {
            http_response_code( 416 );
            header( 'Content-Range: bytes */' . ( $size + 7 ) );
            break;
        }

        $status = 200;
        $start  = 0;
        $end    = $size - 1;
        if ( 'ignore' !== $fault && preg_match( '/^bytes=(\d+)-(\d*)$/', $range_header, $m ) ) {
            $start = (int) $m[1];
            $end   = '' !== $m[2] ? min( (int) $m[2], $size - 1 ) : $size - 1;
            if ( $start >= $size || $end < $start ) {
                http_response_code( 416 );
                header( 'Content-Range: bytes */' . $size );
                break;
            }
            $status = 206;
        }
        $length = $end - $start + 1;

        $limit = isset( $_GET['limit'] ) ? (int) $_GET['limit'] : 0;
        if ( $limit > 0 && $length > $limit ) {
            http_response_code( 413 );
            header( 'Content-Type: text/html' );
            echo '<html><body><h1>413 Request Entity Too Large</h1></body></html>';
            break;
        }

        http_response_code( $status );
        header( 'Content-Type: application/octet-stream' );
        $chunk = substr( $body, $start, $length );
        if ( 206 === $status ) {
            $range_end   = 'wrong-end' === $fault ? $end - 1 : $end;
            $range_total = 'wrong-total' === $fault ? $size + 50 : $size;
            header( 'Content-Range: bytes ' . $start . '-' . $range_end . '/' . $range_total );
        }
        if ( 'overflow' === $fault ) {
            // No Content-Length: the body runs past the confirmed range until the connection closes.
            // The in-range bytes go out first so the client has written part of this response
            // before the overflow arrives, which is what the rollback has to undo.
            echo $chunk;
            flush();
            usleep( 300000 );
            echo str_repeat( 'Z', 100 );
            break;
        }
        if ( 'short' === $fault ) {
            // No Content-Length and a clean close before the confirmed range end.
            echo substr( $chunk, 0, $length - 10 );
            break;
        }
        if ( 'wrong-length' === $fault ) {
            header( 'Content-Length: ' . ( $length - 1 ) );
            echo substr( $chunk, 0, $length - 1 );
            break;
        }
        if ( 'no-length' === $fault ) {
            echo $chunk;
            break;
        }
        header( 'Content-Length: ' . $length );
        echo $chunk;
        break;

    default:
        http_response_code( 404 );
        echo 'not found';
}
