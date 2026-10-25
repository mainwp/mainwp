<?php
/**
 * Loopback transport for user-import regression tests.
 *
 * @package MainWP\Dashboard\Tests
 */

if ( 'cli-server' !== PHP_SAPI || ! getenv( 'MAINWP_IMPORT_HTTP_TEST' ) ) {
    if ( 'cli-server' === PHP_SAPI ) {
        http_response_code( 404 );
    }
    return;
}

if ( '/health' === $_SERVER['REQUEST_URI'] ) {
    echo 'ready';
    return;
}

if ( str_starts_with( $_SERVER['REQUEST_URI'], '/child/' ) ) {
    if ( 'newuser' === ( $_POST['function'] ?? '' ) ) {
        file_put_contents( getenv( 'MAINWP_IMPORT_REQUEST_LOG' ), $_SERVER['REQUEST_URI'] . "\n", FILE_APPEND );
    }
    echo '<mainwp>' . base64_encode( json_encode( array( 'added' => 1 ) ) ) . '</mainwp>';
    return;
}

require getenv( 'WP_TESTS_CONFIG_FILE_PATH' );
define( 'WP_USE_THEMES', false );
require ABSPATH . 'wp-settings.php';
require_once dirname( __DIR__, 2 ) . '/mainwp.php';
wp_set_current_user( 1 );

class MainWP_Import_HTTP_Render extends \MainWP\Dashboard\MainWP_User {
    public static function render_header( $shownPage = '' ) {}
    public static function render_footer() {}
}

MainWP_Import_HTTP_Render::render_bulk_import_users();
