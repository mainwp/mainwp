<?php
/**
 * Module Api Backups classes loading.
 *
 * @package MainWP\Dashboard
 * @version 5.0
 */

namespace MainWP\Dashboard\Module\ApiBackups;

defined( 'ABSPATH' ) || exit;

/**
 * Logs class.
 */
class MainWP_Module_Api_Backups {
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
	}

	/**
	 * Load files.
	 */
	private function includes() {
		$dir = MAINWP_MODULES_DIR;
		if ( mainwp_modules_is_enabled( 'api-backups' ) ) {
			if ( file_exists( $dir . 'api-backups/classes/class-api-backups-manager.php' ) ) {
				require_once $dir . 'api-backups/classes/class-api-backups-manager.php';
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
		if ( mainwp_modules_is_enabled( 'api-backups' ) && class_exists( '\MainWP\Dashboard\Module\ApiBackups\Api_Backups_Manager' ) ) {
			include_once ABSPATH . '/wp-admin/includes/plugin.php';
			if ( function_exists( '\is_plugin_active' ) ) {
				// disable the api backups extension to prevent some conflicted UIs.
				if ( is_plugin_active( 'mainwp-api-backups-extension/mainwp-api-backups-extension.php' ) ) {
					if ( function_exists( '\deactivate_plugins' ) ) {
						deactivate_plugins( 'mainwp-api-backups-extension/mainwp-api-backups-extension.php' );
					}
				}
			}
			Api_Backups_Manager::get_instance();
		}
	}
}

new MainWP_Module_Api_Backups();
