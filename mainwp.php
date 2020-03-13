<?php

/*
  Plugin Name: MainWP Dashboard
  Plugin URI: https://mainwp.com/
  Description: Manage all of your WP sites, even those on different servers, from one central dashboard that runs off of your own self-hosted WordPress install.
  Author: MainWP
  Author URI: https://mainwp.com
  Text Domain: mainwp
  Version:  4.0.7.2
 */

if ( ! defined( 'MAINWP_PLUGIN_FILE' ) ) {
	define( 'MAINWP_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'MAINWP_PLUGIN_DIR' ) ) {
	define( 'MAINWP_PLUGIN_DIR', plugin_dir_path( MAINWP_PLUGIN_FILE ) );
}

if ( ! defined( 'MAINWP_PLUGIN_URL' ) ) {
	define( 'MAINWP_PLUGIN_URL', plugin_dir_url( MAINWP_PLUGIN_FILE ) );
}

// Version information from WordPress.
require_once ABSPATH . 'wp-includes' . DIRECTORY_SEPARATOR . 'version.php'; // Version information from WordPress

if ( ! function_exists( 'mainwp_autoload' ) ) {

	/**
	 * Autoloader for all classes, pages & widgets
	 *
	 * @param string $class_name Folder name class|pages|widgets
	 * @return require_once $autoload_path;
	 */
	function mainwp_autoload( $class_name ) {
		if ( 0 !== strpos( $class_name, 'MainWP_' ) ) {
			return;
		}

		$autoload_types = array(
			'class'   => 'class',
			'pages'   => 'page',
			'widgets' => 'widget',
		);

		foreach ( $autoload_types as $type => $prefix ) {
			$autoload_dir  = \trailingslashit( dirname( __FILE__ ) . DIRECTORY_SEPARATOR . $type );
			$autoload_path = sprintf( '%s%s-%s.php', $autoload_dir, $prefix, strtolower( str_replace( '_', '-', $class_name ) ) );

			if ( file_exists( $autoload_path ) ) {
				require_once $autoload_path;
				break;
			}
		}
	}
}

spl_autoload_register( 'mainwp_autoload' );

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

// If class-mainwp-creport.php exists include it.
if ( file_exists( WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . plugin_basename( __DIR__ ) . DIRECTORY_SEPARATOR . 'class' . DIRECTORY_SEPARATOR . 'class-mainwp-creport.php' ) ) {
	include_once WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . plugin_basename( __DIR__ ) . DIRECTORY_SEPARATOR . 'class' . DIRECTORY_SEPARATOR . 'class-mainwp-creport.php';
}

if ( ! function_exists( 'mainwp_do_not_have_permissions' ) ) {

	/**
	 * Detect permision level & display message to end user.
	 *
	 * @param string  $where User's location
	 * @param boolean $echo Defines weather or not to echo error message
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

// Detect if secupress_scanner is running.
$mainwp_is_secupress_scanning = false;
if ( ! empty( $_GET ) && isset( $_GET['test'] ) && isset( $_GET['action'] ) && 'secupress_scanner' === $_GET['action'] ) {
	$mainwp_is_secupress_scanning = true;
}

// Fix a conflict with SecuPress plugin.
if ( ! $mainwp_is_secupress_scanning ) {
	$mainWP = new MainWP_System( WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . plugin_basename( __FILE__ ) );
	register_activation_hook( __FILE__, array( $mainWP, 'activation' ) );
	register_deactivation_hook( __FILE__, array( $mainWP, 'deactivation' ) );
	add_action( 'plugins_loaded', array( $mainWP, 'update' ) );
}
