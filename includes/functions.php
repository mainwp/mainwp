<?php


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
	 * Detect permision level & display message to end user.
	 *
	 * @param string  $where User's location.
	 * @param boolean $echo Defines weather or not to echo error message.
	 * @return string|bool $msg|false
	 */
	function mainwp_do_not_have_permissions( $where = '', $echo = true ) {
		$msg = sprintf( __( 'You do not have sufficient permissions to access this page (%s).', 'mainwp' ), ucwords( $where ) );
		if ( $echo ) {
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
	 * To compatible with extensions
	 *
	 * @param string $cap_type group or type of capabilities
	 * @param string $cap capabilities for current user
	 * @return bool true|false
	 */
	function mainwp_current_user_can( $cap_type = '', $cap ) {
		if ( function_exists( 'MainWP\Dashboard\mainwp_current_user_can' ) ) {
			return MainWP\Dashboard\mainwp_current_user_can( $cap_type, $cap );
		}
		// to compatible
		if ( has_filter( 'mainwp_currentusercan' ) ) {
			return false;
		}
		return true;
	}
}
