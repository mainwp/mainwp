<?php
/**
 * Plugin Name: MainWP Dashboard
 *
 * Description: Manage all of your WP sites, even those on different servers, from one central dashboard that runs off of your own self-hosted WordPress install.
 *
 * Author: MainWP
 * Author URI: https://mainwp.com
 * Plugin URI: https://mainwp.com/
 * Text Domain: mainwp
 * Version:  5.0-RC4.0
 *
 * @package MainWP/Dashboard
 *
 * @uses \MainWP\Dashboard\MainWP_System
 */

if ( ! defined( 'MAINWP_PLUGIN_FILE' ) ) {

	/**
	 * Define MainWP Dashboard Plugin absolute full path and filename of this file.
	 *
	 * @const ( string ) Defined MainWP dashboard file path.
	 * @source https://github.com/mainwp/mainwp/blob/master/mainwp.php
	 */
	define( 'MAINWP_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'MAINWP_PLUGIN_DIR' ) ) {
	/**
	 * Define MainWP Dashboard Plugin Directory.
	 *
	 * @const ( string ) Defined MainWP Dashboard Plugin Directory.
	 * @source https://github.com/mainwp/mainwp/blob/master/mainwp.php
	 */
	define( 'MAINWP_PLUGIN_DIR', plugin_dir_path( MAINWP_PLUGIN_FILE ) );
}

if ( ! defined( 'MAINWP_PLUGIN_URL' ) ) {
	/**
	 * Define MainWP Child Dashboard URL.
	 *
	 * @const ( string ) Defined MainWP Dashboard Plugin URL.
	 * @source https://github.com/mainwp/mainwp/blob/master/mainwp.php
	 */
	define( 'MAINWP_PLUGIN_URL', plugin_dir_url( MAINWP_PLUGIN_FILE ) );
}


if ( ! defined( 'MAINWP_MODULES_DIR' ) ) {
	define( 'MAINWP_MODULES_DIR', MAINWP_PLUGIN_DIR . 'modules' . DIRECTORY_SEPARATOR );
}

if ( ! defined( 'MAINWP_MODULES_URL' ) ) {
	define( 'MAINWP_MODULES_URL', MAINWP_PLUGIN_URL . 'modules/' );
}


/**
 * Define enable Log Module.
 */
if ( ! defined( 'MAINWP_MODULE_LOG_ENABLED' ) ) {
	define( 'MAINWP_MODULE_LOG_ENABLED', true );
}

if ( ! defined( 'MAINWP_MODULE_COST_TRACKER_ENABLED' ) ) {
	define( 'MAINWP_MODULE_COST_TRACKER_ENABLED', true );
}

if ( ! defined( 'MAINWP_MODULE_API_BACKUPS_ENABLED' ) ) {
	define( 'MAINWP_MODULE_API_BACKUPS_ENABLED', true );
}


// Version information from WordPress.
require_once ABSPATH . 'wp-includes' . DIRECTORY_SEPARATOR . 'version.php';

if ( ! function_exists( 'mainwp_autoload' ) ) {

	/**
	 * Autoloader for all classes, pages & widgets
	 *
	 * @param string $class_name Folder name class|pages|widgets.
	 * @return require_once $autoload_path;
	 */
	function mainwp_autoload( $class_name ) {

		if ( 0 === strpos( $class_name, 'MainWP\Dashboard' ) ) {
			// trip the namespace prefix: MainWP\Dashboard\ .
			$class_name = substr( $class_name, 17 );
		}

		if ( 0 !== strpos( $class_name, 'MainWP_' ) ) {
			return;
		}

		$autoload_types = array(
			'class'   => 'class',
			'pages'   => 'page',
			'widgets' => 'widget',
		);

		foreach ( $autoload_types as $type => $prefix ) {
			$autoload_dir  = \trailingslashit( __DIR__ . DIRECTORY_SEPARATOR . $type );
			$autoload_path = sprintf( '%s%s-%s.php', $autoload_dir, $prefix, strtolower( str_replace( '_', '-', $class_name ) ) );

			if ( file_exists( $autoload_path ) ) {
				require_once $autoload_path;
				break;
			}
		}
	}
}

spl_autoload_register( 'mainwp_autoload' );

require_once MAINWP_PLUGIN_DIR . 'includes' . DIRECTORY_SEPARATOR . 'functions.php';
require_once MAINWP_PLUGIN_DIR . 'includes' . DIRECTORY_SEPARATOR . 'compatible.php';
require_once MAINWP_PLUGIN_DIR . 'includes' . DIRECTORY_SEPARATOR . 'class-mainwp-includes.php';

// Detect if secupress_scanner is running.
$mainwp_is_secupress_scanning = false;
if ( ! empty( $_GET ) && isset( $_GET['test'] ) && isset( $_GET['action'] ) && 'secupress_scanner' === $_GET['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized.Recommended
	$mainwp_is_secupress_scanning = true;
}

// Fix a conflict with SecuPress plugin.
if ( ! $mainwp_is_secupress_scanning ) {
	$mainWP = new MainWP\Dashboard\MainWP_System( WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . plugin_basename( __FILE__ ) );
	register_activation_hook( __FILE__, array( $mainWP, 'activation' ) );
	register_deactivation_hook( __FILE__, array( $mainWP, 'deactivation' ) );
	add_action( 'plugins_loaded', array( $mainWP, 'update_install' ) );
}
