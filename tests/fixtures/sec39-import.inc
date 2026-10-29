<?php
/** Standalone fixture; the .inc suffix excludes it from PHPUnit discovery. */
/** Supply a CSV upload to the real renderer in an isolated subprocess. */
namespace MainWP\Dashboard {
    function is_uploaded_file( $path ) { return 'sec39-fixture.csv' === $path; }
    class MainWP_System_Utility { public static function get_wp_file_system() { return true; } }
    class MainWP_Utility { public static function starts_with( $text, $prefix ) { return str_starts_with( $text, $prefix ); } }
}
namespace {
    define( 'ABSPATH', __DIR__ );
    $input = json_decode( stream_get_contents( STDIN ), true );
    function check_admin_referer( ...$args ) { return true; }
    function wp_unslash( $value ) { return $value; }
    function sanitize_text_field( $value ) { return strip_tags( $value ); }
    function wp_json_encode( $value ) { return json_encode( $value ); }
    function esc_attr( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8', false ); }
    function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8', false ); }
    function esc_html_e( $value, ...$args ) { echo esc_html( $value ); }
    function esc_html__( $value, ...$args ) { return esc_html( $value ); }
    $wp_filesystem = new class {
        public function get_contents( $path ) { return $GLOBALS['input']['csv']; }
    };
    $_FILES = array( 'mainwp_managesites_file_bulkupload' => array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => 'sec39-fixture.csv' ) );
    $_POST = array( 'mainwp_managesites_chk_header_first' => true );
    require ( getenv( 'SEC39_PRODUCTION_ROOT' ) ?: dirname( __DIR__, 2 ) ) . '/class/class-mainwp-manage-sites-view.php';
    ob_start();
    \MainWP\Dashboard\MainWP_Manage_Sites_View::render_import_sites();
    $html = ob_get_clean();
    preg_match_all( '/encoded-data="([^"]+)"/', $html, $matches );
    echo json_encode( array_map( static function ( $row ) { return json_decode( html_entity_decode( $row, ENT_QUOTES ), true ); }, $matches[1] ) );
}
