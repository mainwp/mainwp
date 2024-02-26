<?php
/**
 * Module Cost Tracker classes loading.
 *
 * @package MainWP\Dashboard\Module\CostTracker
 * @version 4.6
 */

namespace MainWP\Dashboard\Module\CostTracker;

defined( 'ABSPATH' ) || exit;

/**
 * Logs class.
 */
class MainWP_Module_Cost_Tracker {
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
		if ( class_exists( '\MainWP\Dashboard\Module\CostTracker\Cost_Tracker_DB' ) ) {
			Cost_Tracker_DB::get_instance()->init();
		}
	}

	/**
	 * Load files.
	 */
	private function includes() {
		$dir = MAINWP_MODULES_DIR;
		// need to load log db install.
		if ( file_exists( $dir . 'cost-tracker/classes/class-cost-tracker-db.php' ) ) {
			require_once $dir . 'cost-tracker/classes/class-cost-tracker-db.php';
		}
		if ( mainwp_modules_is_enabled( 'cost-tracker' ) ) {
			if ( file_exists( $dir . 'cost-tracker/classes/class-cost-tracker-manager.php' ) ) {
				require_once $dir . 'cost-tracker/classes/class-cost-tracker-manager.php';
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
		return $all_opts;
	}

	/**
	 * Handle mainwp system init.
	 */
	public function hook_mainwp_system_init() {
		if ( mainwp_modules_is_enabled( 'cost-tracker' ) && class_exists( '\MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Manager' ) ) {
			Cost_Tracker_Manager::get_instance();
		}
	}
}

new MainWP_Module_Cost_Tracker();
