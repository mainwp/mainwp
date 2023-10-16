<?php
/**
 * Module Logs class loading.
 *
 * @package MainWP\Dashboard\Module\Log
 * @version 4.5.1
 */
namespace MainWP\Dashboard\Module\Log;

use \MainWP\Dashboard\MainWP_Includes;

defined( 'ABSPATH' ) || exit;

/**
 * Logs class.
 */
class MainWP_Module_Log {
	/**
	 * Load required files and hooks to make the CLI work.
	 */
	public function __construct() {
		$this->includes();
		add_action( 'plugins_loaded', array( $this, 'plugins_loaded' ) );
	}

	/**
	 * Init method.
	 */
	public function plugins_loaded() {
		if ( class_exists( '\MainWP\Dashboard\Module\Log\Log_Install' ) ) {
			Log_Install::instance()->install();
		}
	}

	/**
	 * Load files.
	 */
	private function includes() {
		$dir = MAINWP_PLUGIN_DIR;
		// need to load log db install.
		if ( file_exists( $dir . '/modules/logs/classes/class-log-install.php' ) ) {
			require_once $dir . '/modules/logs/classes/class-log-install.php';
		}
		if ( MainWP_Includes::is_enable_log_module() ) {
			if ( file_exists( $dir . 'modules/logs/classes/class-log-manager.php' ) ) {
				require_once $dir . 'modules/logs/classes/class-log-manager.php';
			}
		}
	}
}

new MainWP_Module_Log();
