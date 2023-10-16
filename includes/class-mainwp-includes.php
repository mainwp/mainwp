<?php
/**
 * Includes files.
 *
 * @package MainWP\Dashboard
 * @version 4.5.1
 */
namespace MainWP\Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Logs class.
 */
class MainWP_Includes {

	/**
	 * Public static variable to hold the plugin dir.
	 *
	 * @static
	 *
	 * @var string Default MainWP dashboard plugin dir.
	 */
	public static $plugin_basedir = MAINWP_PLUGIN_DIR;
	/**
	 * Load required files and hooks to make the CLI work.
	 */
	public function __construct() {
		$this->includes();
	}


	/**
	 * Check if enable log module.
	 */
	public static function is_enable_log_module() {
		return defined( 'MAINWP_MODULE_LOG_ENABLED' ) && MAINWP_MODULE_LOG_ENABLED ? true : false;
	}

	/**
	 * Load files.
	 */
	private function includes() {
		if ( file_exists( self::$plugin_basedir . '/modules/class-module-log.php' ) ) {
			require_once self::$plugin_basedir . '/modules/class-module-log.php';
		}
	}
}
