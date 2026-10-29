<?php
/**
 * Exercise wizard entry and real redirect listeners without a WordPress database.
 *
 * @package MainWP/Dashboard/Tests
 */

$input = json_decode( stream_get_contents( STDIN ), true );
define( 'ABSPATH', __DIR__ );
define( 'MAINWP_PLUGIN_URL', 'https://dashboard.example.org/wp-content/plugins/mainwp/' );
define( 'MAINWP_VERSION', 'fixture' );

class Onboarding_Assets_Requested extends RuntimeException {}
class Onboarding_Unexpected_Redirect extends RuntimeException {}

$options = array( 'mainwp_activated' => 'yes' );
if ( ! empty( $input['quick_setup'] ) ) {
	$options['mainwp_run_quick_setup'] = 'yes';
}
$redirects = array();
$enqueued  = false;
function get_option( $name, $default = false ) {
	return $GLOBALS['options'][ $name ] ?? $default;
}
function delete_option( $name ) {
	unset( $GLOBALS['options'][ $name ] );
}
function current_user_can( $capability ) {
	return 'manage_options' === $capability;
}
function esc_html__( $text, $domain ) {
	return $text;
}
function sanitize_key( $value ) {
	return $value;
}
function wp_enqueue_script( ...$args ) {
	$GLOBALS['enqueued'] = true;
	throw new Onboarding_Assets_Requested();
}
function get_current_screen() {
	return (object) array( 'base' => 'mainwp_page_managesites' );
}
function wp_cache_delete( ...$args ) {}
function admin_url( $path ) {
	return 'https://dashboard.example.org/wp-admin/' . $path;
}
function wp_safe_redirect( $url ) {
	$GLOBALS['redirects'][] = $url;
	throw new Onboarding_Unexpected_Redirect();
}
function wp_unslash( $value ) {
	return $value;
}
function sanitize_text_field( $value ) {
	return $value;
}

class Onboarding_Logger {
	public static function instance() { return new self(); }
	public function check_log_daily() {}
}
class_alias( Onboarding_Logger::class, 'MainWP\\Dashboard\\MainWP_Logger' );

require $input['root'] . '/pages/page-mainwp-setup-wizard.php';
require $input['root'] . '/class/class-mainwp-system.php';
$wizard = ( new ReflectionClass( MainWP\Dashboard\MainWP_Setup_Wizard::class ) )->newInstanceWithoutConstructor();
$_GET   = array( 'page' => $input['entry_page'] ?? 'mainwp-setup', 'step' => $input['step'] );
try {
	$wizard->admin_init();
} catch ( Onboarding_Assets_Requested $error ) {
	// Stop before enqueueing assets; entry state must already be consumed.
}
if ( ! empty( $input['destination'] ) ) {
	$_GET                   = $input['destination'];
	$_SERVER['REQUEST_URI']  = '/wp-admin/admin.php';
	$system                 = ( new ReflectionClass( MainWP\Dashboard\MainWP_System::class ) )->newInstanceWithoutConstructor();
	try {
		$system->admin_redirects();
		$system->current_screen_redirects();
	} catch ( Onboarding_Unexpected_Redirect $error ) {
		// Return the attempted destination instead of terminating this fixture.
	}
}
echo json_encode( array( 'options' => $options, 'redirects' => $redirects, 'enqueued' => $enqueued ) );
