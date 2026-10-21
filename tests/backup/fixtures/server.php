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

    default:
        http_response_code( 404 );
        echo 'not found';
}
