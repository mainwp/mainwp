<?php
/** Isolated execution of the support-settings AJAX handler and option helper. */
namespace MainWP\Dashboard;

define( 'ABSPATH', __DIR__ . '/' );
$scenario = $argv[1];
$features = array( 'tours', 'video', 'chatbase' );
$options = array();
foreach ( $features as $feature ) {
    $options[ 'mainwp_enable_guided_' . $feature ] = 'unchanged' === $scenario ? '1' : '0';
}
$status = 200;
if ( ! class_exists( '\\WP_Error' ) ) {
    require __DIR__ . '/support-wp-error.inc';
}
$secured = false;

class MainWP_Post_Base_Handler {
    public function secure_request( $action = '', $query_arg = 'security' ) {
        if ( 'mainwp_help_modal_content_update' !== $action ) {
            throw new \RuntimeException( 'Unexpected secured action' );
        }
        $GLOBALS['secured'] = true;
    }
}
class MainWP_Assistant_Policies {
    public static function instance() {
        return new self();
    }
    public function set_assistant_enabled( $enabled ) {
        if ( 'fail_chatbase' === $GLOBALS['scenario'] ) {
            return new \WP_Error( 'mainwp_assistant_toggle_failed', 'save-error' );
        }
        update_option( 'mainwp_enable_guided_chatbase', (int) $enabled );
        return true;
    }
}
class MainWP_System {
    public static function is_assistant_enabled() {
        return (bool) get_option( 'mainwp_enable_guided_chatbase', 0 );
    }
}
function is_wp_error( $thing ) {
    return $thing instanceof \WP_Error;
}
function __( $text, $domain ) {
    return $text;
}
function wp_send_json_success( $data ) {
    echo 'ok';
    exit;
}
function wp_send_json_error( $data, $status ) {
    $GLOBALS['status'] = $status;
    echo 'save-error';
    exit;
}
function add_option( $name, $value, $deprecated = '', $autoload = 'yes' ) {
    return false;
}
function update_option( $name, $value ) {
    if ( 'fail_' . substr( $name, strlen( 'mainwp_enable_guided_' ) ) === $GLOBALS['scenario'] || (string) $GLOBALS['options'][ $name ] === (string) $value ) {
        return false;
    }
    $GLOBALS['options'][ $name ] = $value;
    return true;
}
function get_option( $name, $default = false ) {
    return $GLOBALS['options'][ $name ] ?? $default;
}
function esc_html__( $text, $domain ) {
    return htmlspecialchars( $text, ENT_QUOTES );
}
function wp_unslash( $value ) {
    return is_string( $value ) ? stripslashes( $value ) : $value;
}
function wp_die( $message = '', $title = '', $args = array() ) {
    $GLOBALS['status'] = $args['response'] ?? 500;
    echo 'save-error';
    exit;
}
register_shutdown_function( function () {
    fwrite( STDERR, json_encode( array( 'status' => $GLOBALS['status'], 'secured' => $GLOBALS['secured'], 'options' => $GLOBALS['options'] ) ) );
} );
require dirname( __DIR__, 2 ) . '/class/class-mainwp-utility.php';
require getenv( 'MAINWP_SAVE_HANDLER_SOURCE' ) ?: dirname( __DIR__, 2 ) . '/class/class-mainwp-post-handler.php';
$requested = 'unchanged_off' === $scenario ? 0 : 1;
$_POST = array( 'enable_tour' => $requested, 'enable_video' => $requested, 'enable_chatbase' => $requested );
( new MainWP_Post_Handler() )->ajax_mainwp_help_modal_content_update();
