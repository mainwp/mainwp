<?php
/** Upload boundary doubles for the production Sites import renderer. */
namespace MainWP\Dashboard {
    function is_uploaded_file( $path ) { return 'site-import-fixture.csv' === $path; }
    class MainWP_System_Utility { public static function get_wp_file_system() { return true; } }
    class MainWP_Utility { public static function starts_with( $text, $prefix ) { return 0 === strpos( $text, $prefix ); } }
}
namespace {
    define( 'ABSPATH', __DIR__ );
    $input = json_decode( stream_get_contents( STDIN ), true );
    function check_admin_referer( ...$args ) { return true; }
    function wp_unslash( $value ) { return stripslashes( $value ); }
    function sanitize_text_field( $value ) { return strip_tags( $value ); }
    function wp_json_encode( $value ) { return json_encode( $value ); }
    function esc_attr( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8', false ); }
    function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8', false ); }
    function esc_html_e( $value, ...$args ) { echo esc_html( $value ); }
    function esc_html__( $value, ...$args ) { return esc_html( $value ); }
    $wp_filesystem = new class {
        public function get_contents( $path ) { return $GLOBALS['input']['csv']; }
    };
    $_FILES = array( 'mainwp_managesites_file_bulkupload' => array( 'error' => UPLOAD_ERR_OK, 'tmp_name' => 'site-import-fixture.csv' ) );
    $_POST = array( 'mainwp_managesites_chk_header_first' => false );
    require dirname( __DIR__, 2 ) . '/class/class-mainwp-manage-sites-view.php';
    \MainWP\Dashboard\MainWP_Manage_Sites_View::render_import_sites();
}
