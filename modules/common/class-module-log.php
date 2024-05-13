<?php
/**
 * Module Logs class loading.
 *
 * @package MainWP\Dashboard\Module\Log
 * @version 4.5.1
 */

namespace MainWP\Dashboard\Module\Log;

use MainWP\Dashboard\MainWP_Includes;

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
		add_filter( 'mainwp_init_load_all_options', array( $this, 'hook_load_options' ) );
		add_action( 'mainwp_system_init', array( $this, 'hook_mainwp_system_init' ) );
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
		$dir = MAINWP_MODULES_DIR;
		// need to load log db install.
		if ( file_exists( $dir . 'logs/classes/class-log-install.php' ) ) {
			require_once $dir . 'logs/classes/class-log-install.php';
		}
		if ( mainwp_modules_is_enabled( 'logs' ) ) {
			if ( file_exists( $dir . 'logs/classes/class-log-manager.php' ) ) {
				require_once $dir . 'logs/classes/class-log-manager.php';
			}
		}
	}

	/**
	 * Handle mainwp system init load options.
	 *
	 * @param array $all_opts All loading mainwp options.
	 * @return array $all_opts All loading mainwp options.
	 */
	public function hook_load_options( $all_opts ) {
		if ( is_array( $all_opts ) ) {
			$all_opts[] = 'mainwp_module_log_last_time_auto_purge_logs';
			$all_opts[] = 'mainwp_module_log_next_time_auto_purge_logs';
		}
		return $all_opts;
	}

	/**
	 * Handle mainwp system init.
	 */
	public function hook_mainwp_system_init() {
		if ( mainwp_modules_is_enabled( 'logs' ) && class_exists( '\MainWP\Dashboard\Module\Log\Log_Manager' ) ) {
			Log_Manager::instance();
		}
	}
}

new MainWP_Module_Log();
