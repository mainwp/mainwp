<?php
/**
 * Cost Tracker settings probes, loaded by rest-operation-rights.inc.
 *
 * Runs real settings render/save handlers, REST callbacks and option helpers.
 * WordPress services and persistence are doubles; there is no network or DB.
 * Browser probes omit MAINWP_REST_API_DOING. Redirects and the rendering entry
 * point stop execution before exits or full page markup, after authorization.
 *
 * @package MainWP\Dashboard\Tests
 */

namespace MainWP\Dashboard {
	class MainWP_Settings {
		public static function render_header( $page ) { throw new \Rest_Rights_Boundary( 'render settings' ); }
		public static function get_all_settings_values() { return $GLOBALS['cost_options']; }
	}
}

namespace {
	function mainwp_current_user_can( $type, $right ) { return apply_filters( 'mainwp_currentusercan', true, $type, $right ); }
	function mainwp_do_not_have_permissions( $message ) { $GLOBALS['cost_denied'] = true; }
	function get_option( $key, $default = false ) { return 'mainwp_module_cost_tracker_options' === $key ? $GLOBALS['cost_options'] : $default; }
	function update_option( $key, $value ) {
		if ( 'mainwp_module_cost_tracker_options' !== $key ) { throw new Error( 'Unexpected option write' ); }
		$GLOBALS['cost_options'] = $value;
		++$GLOBALS['cost_writes'];
		return true;
	}
	function wp_unslash( $value ) { return $value; }
	function sanitize_text_field( $value ) { return is_scalar( $value ) ? trim( strip_tags( (string) $value ) ) : ''; }
	function sanitize_title( $value ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $value ) ), '-' ); }
	function wp_verify_nonce( $nonce, $action ) { return 'valid' === $nonce && 'module_cost_tracker_settings_nonce' === $action; }
	function admin_url( $path ) { return 'https://example.test/wp-admin/' . $path; }
	function wp_safe_redirect( $url ) { throw new Rest_Rights_Boundary( 'redirect' ); }

	require $root . '/modules/cost-tracker/classes/class-cost-tracker-utility.php';
	require $root . '/modules/cost-tracker/classes/class-cost-tracker-admin.php';
	require $root . '/modules/cost-tracker/pages/page-cost-tracker-settings.php';
	$GLOBALS['cost_options'] = array(
		'currency' => 'USD',
		'currency_format' => MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Utility::default_currency_settings(),
		'custom_product_types' => '{"held-product":"Held product"}',
		'product_types_colors' => '{}',
		'product_types_icons' => '{}',
		'custom_payment_methods' => '{"held-payment":"Held payment"}',
	);
	$GLOBALS['cost_writes'] = 0;
	$GLOBALS['cost_denied'] = false;
	$before = $GLOBALS['cost_options'];
	add_filter( 'mainwp_currentuserallowedaccessgroups', static function ( $value ) use ( $input ) { return $input['groups'] ?? $value; } );

	$result = null;
	$boundary = null;
	try {
		if ( 'screen' === $input['cost_settings_mode'] ) {
			MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Settings::get_instance()->render_settings_page();
		} elseif ( 'post' === $input['cost_settings_mode'] ) {
			$_POST = array(
				'mwp_cost_tracker_settings_submit' => 1,
				'nonce' => $input['nonce'] ?? 'valid',
				'mainwp_module_cost_tracker_settings_currency' => 'EUR',
			);
			if ( ! empty( $input['missing_nonce'] ) ) { unset( $_POST['nonce'] ); }
			MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Admin::handle_settings_post();
		} else {
			$auth = MainWP_REST_Authentication::get_instance();
			( new ReflectionProperty( $auth, 'user' ) )->setValue(
				$auth,
				! empty( $input['unauthenticated'] ) ? null : (object) array( 'user_id' => 42, 'permissions' => $input['scope'] ?? 'read_write' )
			);
			$request = new WP_REST_Request( $input['http_method'] ?? 'PUT', $input['route'] ?? '/mainwp/v2/settings/cost-tracker/edit' );
			$request->set_body_params( $input['params'] ?? array() );
			$controller = new MainWP_Rest_Settings_Controller();
			$method = $input['method'];
			$result = 'get_cost_tracker_settings' === $method ? $controller->get_rest_permissions_check( $request ) : $controller->get_unrestricted_permissions_check( $request );
			if ( ! is_wp_error( $result ) ) { $result = $controller->$method( $request ); }
		}
	} catch ( Rest_Rights_Boundary $stop ) {
		$boundary = $stop->getMessage();
	}
	echo json_encode( array(
		'error' => is_wp_error( $result ) ? array( 'code' => $result->get_error_code(), 'data' => $result->get_error_data() ) : null,
		'result' => is_wp_error( $result ) ? null : $result,
		'boundary' => $boundary,
		'denied' => $GLOBALS['cost_denied'],
		'before' => $before,
		'options' => $GLOBALS['cost_options'],
		'writes' => $GLOBALS['cost_writes'],
		'checks' => $GLOBALS['fixture']['checks'],
	), JSON_THROW_ON_ERROR );
}
