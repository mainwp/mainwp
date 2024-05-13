<?php
/**
 * MainWP Database Site Actions
 *
 * This file handles all interactions with the Site Actions DB.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard\Module\Log;

use MainWP\Dashboard\MainWP_Execution_Helper;

/**
 * Class Log_Manager
 *
 * @package MainWP\Dashboard
 */
class Log_Manager {

	/**
	 * Version
	 *
	 * @const string Plugin version number.
	 * */
	const VERSION = '5.0.0';

	/**
	 * Log_Admin
	 *
	 * @var \MainWP\Dashboard\Module\Log\Log_Admin Admin class.
	 * */
	public $admin;

	/**
	 * MainWP_Execution_Helper
	 *
	 * @var \MainWP\Dashboard\MainWP_Execution_Helper class.
	 * */
	public $executor;

	/**
	 * Holds Instance of settings object
	 *
	 * @var Log_Settings
	 */
	public $settings;

	/**
	 * Log_Connectors
	 *
	 * @var \MainWP\Dashboard\Module\Log\Log_Connectors Connectors class.
	 * */
	public $connectors;

	/**
	 * Log_DB
	 *
	 * @var \MainWP\Dashboard\Module\Log\Log_DB DB Class.
	 * */
	public $db;

	/**
	 * Log
	 *
	 * @var \MainWP\Dashboard\Module\Log\Log Log Class.
	 * */
	public $log;

	/**
	 * Log_Install class.
	 *
	 * @var \MainWP\Dashboard\Module\Log\Log_Install Install class.
	 * */
	public $install;

	/**
	 * Locations.
	 *
	 * @var array URLs and Paths used by the plugin.
	 */
	public $locations = array();

	/**
	 * Protected static variable to hold the single instance of the class.
	 *
	 * @var mixed Default null
	 */
	protected static $instance = null;

	/**
	 * Return the single instance of the class.
	 *
	 * @return mixed $instance The single instance of the class.
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Plugin constructor.
	 *
	 * Run each time the class is called.
	 */
	public function __construct() {

		$mod_log_dir     = MAINWP_MODULES_DIR . 'logs/';
		$this->locations = array(
			'dir'       => $mod_log_dir,
			'url'       => MAINWP_MODULES_URL . 'logs/',
			'inc_dir'   => $mod_log_dir . 'includes/',
			'class_dir' => $mod_log_dir . 'classes/',
		);

		spl_autoload_register( array( $this, 'autoload' ) );

		// Load helper functions.
		require_once $this->locations['inc_dir'] . 'functions.php';

		$driver         = new Log_DB_Driver_WPDB();
		$this->db       = new Log_DB( $driver );
		$this->executor = MainWP_Execution_Helper::instance();
		$this->settings = new Log_Settings( $this );

		// Load logger class.
		$this->log = new Log( $this );

		// Load settings and connectors after widgets_init and before the default init priority.
		add_action( 'init', array( $this, 'init' ), 9 );

		// Change DB driver after plugin loaded if any add-ons want to replace.
		add_action( 'plugins_loaded', array( $this, 'plugins_loaded' ), 20 );

		// Load admin area classes.
		if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			$this->admin = new Log_Admin( $this );
		} elseif ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			$this->admin = new Log_Admin( $this, $driver );
		}
	}

	/**
	 * Autoloader for classes.
	 *
	 * @param string $class_name class name.
	 */
	public function autoload( $class_name ) {

		if ( ! preg_match( '/^(?P<namespace>.+)\\\\(?P<autoload>[^\\\\]+)$/', $class_name, $matches ) ) {
			return;
		}

		static $reflection;

		if ( empty( $reflection ) ) {
			$reflection = new \ReflectionObject( $this );
		}

		if ( $reflection->getNamespaceName() !== $matches['namespace'] ) {
			return;
		}

		$autoload_name = $matches['autoload'];
		$autoload_dir  = \trailingslashit( $this->locations['dir'] );
		$load_dirs     = array(
			'classes' => 'class',
			'pages'   => 'page',
			'widgets' => 'widget',
		);
		foreach ( $load_dirs as $dir => $prefix ) {
			$dir           = $dir . '/';
			$autoload_path = sprintf( '%s%s%s-%s.php', $autoload_dir, $dir, $prefix, strtolower( str_replace( '_', '-', $autoload_name ) ) );
			if ( is_readable( $autoload_path ) ) {
				require_once $autoload_path;
				return;
			}
		}
	}


	/**
	 * Get internal connectors.
	 */
	public function get_internal_connectors() {
		return array(
			'compact',
		);
	}

	/**
	 * Load Log_Connectors.
	 *
	 * @action init
	 *
	 * @uses \MainWP\Dashboard\Module\Log\Log_Connectors
	 */
	public function init() {
		$this->connectors = new Log_Connectors( $this );
	}

	/**
	 * Getter for the version number.
	 *
	 * @return string
	 */
	public function get_version() {
		return self::VERSION;
	}

	/**
	 * Change plugin database driver in case driver plugin loaded after logs.
	 *
	 * @uses \MainWP\Dashboard\Module\Log\Log_DB
	 * @uses \MainWP\Dashboard\Module\Log\Log_DB_Driver_WPDB
	 */
	public function plugins_loaded() {
		// Load DB helper interface/class.
		$driver_class = '\MainWP\Dashboard\Module\Log\Log_DB_Driver_WPDB';

		if ( class_exists( $driver_class ) ) {
			$driver   = new $driver_class();
			$this->db = new Log_DB( $driver );
		}
	}
}
