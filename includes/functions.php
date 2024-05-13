<?php
/**
 * MainWP Base Functions.
 *
 * Grab MainWP Directory and check for permissions.
 *
 * @package     MainWP/Dashboard
 */

if ( ! defined( 'FILTER_SANITIZE_STRING_COMPATIBLE' ) ) {  // to compatible.
	define( 'FILTER_SANITIZE_STRING_COMPATIBLE', 513 );
}

if ( ! function_exists( 'mainwpdir' ) ) {

	/**
	 * Grab MainWP Directory
	 *
	 * @return string
	 */
	function mainwpdir() {
		return WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . dirname( plugin_basename( __FILE__ ) ) . DIRECTORY_SEPARATOR . 'libs' . DIRECTORY_SEPARATOR;
	}
}


if ( ! function_exists( 'mainwp_do_not_have_permissions' ) ) {

	/**
	 * Detect permission level & display message to end user.
	 *
	 * @param string $where User's location.
	 * @param bool   $echo_out Defines weather or not to echo error message.
	 * @return string|bool $msg|false
	 */
	function mainwp_do_not_have_permissions( $where = '', $echo_out = true ) {
		$msg = sprintf( esc_html__( 'You do not have sufficient permissions to access this page (%s).', 'mainwp' ), ucwords( $where ) );
		if ( $echo_out ) {
			echo '<div class="mainwp-permission-error"><p>' . esc_html( $msg ) . '</p>If you need access to this page please contact the dashboard administrator.</div>';
		} else {
			return $msg;
		}

		return false;
	}
}

if ( ! function_exists( 'mainwp_current_user_can' ) ) {

	/**
	 * Check permission level.
	 *
	 * To compatible with extensions.
	 *
	 * @param string $cap_type Group or type of capabilities.
	 * @param string $cap Capabilities for current user.
	 *
	 * @return bool true|false
	 */
	function mainwp_current_user_can( $cap_type = '', $cap = '' ) {
		if ( function_exists( 'MainWP\Dashboard\mainwp_current_user_have_right' ) ) {
			return MainWP\Dashboard\mainwp_current_user_have_right( $cap_type, $cap );
		}
		return true;
	}
}

if ( ! function_exists( 'mainwp_get_actions_handler_instance' ) ) {

	/**
	 * Function to get mainwp actions handler instance.
	 *
	 * @return bool true|false
	 */
	function mainwp_get_actions_handler_instance() {
		return \MainWP\Dashboard\MainWP_Actions_Handler::instance();
	}
}

if ( ! function_exists( 'mainwp_send_json_output' ) ) {
	/**
	 * Handle send json output.
	 *
	 * @param array $output Array output.
	 */
	function mainwp_send_json_output( $output ) {
		if ( is_array( $output ) ) {
			$output['execute_time'] = \MainWP\Dashboard\MainWP_Execution_Helper::instance()->get_exec_time();
		}
		wp_send_json( $output );
	}
}


if ( ! function_exists( 'mainwp_modules_is_enabled' ) ) {

	/**
	 * Check if module is enable.
	 *
	 * @param string $module module slug.
	 *
	 * @return bool true|false
	 */
	function mainwp_modules_is_enabled( $module ) {
		$enable_mainwp_modules = array(
			'logs'         => defined( 'MAINWP_MODULE_LOG_ENABLED' ) && MAINWP_MODULE_LOG_ENABLED ? true : false,
			'cost-tracker' => defined( 'MAINWP_MODULE_COST_TRACKER_ENABLED' ) && MAINWP_MODULE_COST_TRACKER_ENABLED ? true : false,
			'api-backups'  => defined( 'MAINWP_MODULE_API_BACKUPS_ENABLED' ) && MAINWP_MODULE_API_BACKUPS_ENABLED ? true : false,
		);
		return ! empty( $enable_mainwp_modules[ $module ] ) ? true : false;
	}
}
