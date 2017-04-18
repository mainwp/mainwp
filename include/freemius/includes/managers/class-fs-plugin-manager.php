<?php
	/**
	 * @package     Freemius
	 * @copyright   Copyright (c) 2015, Freemius, Inc.
	 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
	 * @since       1.0.6
	 */

	if ( ! defined( 'ABSPATH' ) ) {
		exit;
	}

	class FS_Plugin_Manager {
		/**
		 * @var string
		 */
		protected $_slug;
		/**
		 * @var FS_Plugin
		 */
		protected $_plugin;

		/**
		 * @var FS_Plugin_Manager[]
		 */
		private static $_instances = array();
		/**
		 * @var FS_Logger
		 */
		protected $_logger;

		/**
		 * @param string $slug
		 *
		 * @return FS_Plugin_Manager
		 */
		static function instance( $slug ) {
			if ( ! isset( self::$_instances[ $slug ] ) ) {
				self::$_instances[ $slug ] = new FS_Plugin_Manager( $slug );
			}

			return self::$_instances[ $slug ];
		}

		protected function __construct( $slug ) {
			$this->_logger = FS_Logger::get_logger( WP_FS__SLUG . '_' . $slug . '_' . 'plugins', WP_FS__DEBUG_SDK, WP_FS__ECHO_DEBUG_SDK );

			$this->_slug = $slug;
			$this->load();
		}

		protected function get_option_manager() {
			return FS_Option_Manager::get_manager( WP_FS__ACCOUNTS_OPTION_NAME, true );
		}

		protected function get_all_plugins() {
			return $this->get_option_manager()->get_option( 'plugins', array() );
		}

		/**
		 * Load plugin data from local DB.
		 *
		 * @author Vova Feldman (@svovaf)
		 * @since  1.0.6
		 */
		function load() {
			$all_plugins   = $this->get_all_plugins();
			$this->_plugin = isset( $all_plugins[ $this->_slug ] ) ?
				$all_plugins[ $this->_slug ] :
				null;
		}

		/**
		 * Store plugin on local DB.
		 *
		 * @author Vova Feldman (@svovaf)
		 * @since  1.0.6
		 *
		 * @param bool|FS_Plugin $plugin
		 * @param bool           $flush
		 *
		 * @return bool|\FS_Plugin
		 */
		function store( $plugin = false, $flush = true ) {
			$all_plugins = $this->get_all_plugins();

			if ( false !== $plugin ) {
				$this->_plugin = $plugin;
			}

			$all_plugins[ $this->_slug ] = $this->_plugin;

			$options_manager = $this->get_option_manager();
			$options_manager->set_option( 'plugins', $all_plugins, $flush );

			return $this->_plugin;
		}

		/**
		 * Update local plugin data if different.
		 *
		 * @author Vova Feldman (@svovaf)
		 * @since  1.0.6
		 *
		 * @param \FS_Plugin $plugin
		 * @param bool       $store
		 *
		 * @return bool True if plugin was updated.
		 */
		function update( FS_Plugin $plugin, $store = true ) {
			if ( ! ( $this->_plugin instanceof FS_Plugin ) ||
			     $this->_plugin->slug != $plugin->slug ||
			     $this->_plugin->public_key != $plugin->public_key ||
			     $this->_plugin->secret_key != $plugin->secret_key ||
			     $this->_plugin->parent_plugin_id != $plugin->parent_plugin_id ||
			     $this->_plugin->title != $plugin->title
			) {
				$this->store( $plugin, $store );

				return true;
			}

			return false;
		}

		/**
		 * @author Vova Feldman (@svovaf)
		 * @since  1.0.6
		 *
		 * @param FS_Plugin $plugin
		 * @param bool      $store
		 */
		function set( FS_Plugin $plugin, $store = false ) {
			$this->_plugin = $plugin;

			if ( $store ) {
				$this->store();
			}
		}

		/**
		 * @author Vova Feldman (@svovaf)
		 * @since  1.0.6
		 *
		 * @return bool|\FS_Plugin
		 */
		function get() {
			return isset( $this->_plugin ) ?
				$this->_plugin :
				false;
		}


	}