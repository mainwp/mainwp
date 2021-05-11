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
 * Version:  4.1.7-beta1
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

if ( ! defined( 'MAINWP_UPDATE_CHECK_LOG_PRIORITY_NUMBER' ) ) {
	/**
	 * Define MainWP daily disgest log priority number.
	 */
	define( 'MAINWP_UPDATE_CHECK_LOG_PRIORITY_NUMBER', 10 );
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

require_once MAINWP_PLUGIN_DIR . 'includes' . DIRECTORY_SEPARATOR . 'functions.php';
require_once MAINWP_PLUGIN_DIR . 'includes' . DIRECTORY_SEPARATOR . 'compatible.php';

// Detect if secupress_scanner is running.
$mainwp_is_secupress_scanning = false;
if ( ! empty( $_GET ) && isset( $_GET['test'] ) && isset( $_GET['action'] ) && 'secupress_scanner' === $_GET['action'] ) {
	$mainwp_is_secupress_scanning = true;
}

// Fix a conflict with SecuPress plugin.
if ( ! $mainwp_is_secupress_scanning ) {
	$mainWP = new MainWP\Dashboard\MainWP_System( WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . plugin_basename( __FILE__ ) );
	register_activation_hook( __FILE__, array( $mainWP, 'activation' ) );
	register_deactivation_hook( __FILE__, array( $mainWP, 'deactivation' ) );
	add_action( 'plugins_loaded', array( $mainWP, 'update' ) );
}
