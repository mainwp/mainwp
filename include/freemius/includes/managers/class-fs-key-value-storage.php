<?php
	/**
	 * @package     Freemius
	 * @copyright   Copyright (c) 2015, Freemius, Inc.
	 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
	 * @since       1.0.7
	 */

	if ( ! defined( 'ABSPATH' ) ) {
		exit;
	}

	/**
	 * Class FS_Key_Value_Storage
	 *
	 * @property int           $install_timestamp
	 * @property int           $activation_timestamp
	 * @property int           $sync_timestamp
	 * @property object        $sync_cron
	 * @property int           $install_sync_timestamp
	 * @property array         $connectivity_test
	 * @property array         $is_on
	 * @property object        $trial_plan
	 * @property bool          $has_trial_plan
	 * @property bool          $trial_promotion_shown
	 * @property string        $sdk_version
	 * @property string        $sdk_last_version
	 * @property bool          $sdk_upgrade_mode
	 * @property bool          $sdk_downgrade_mode
	 * @property bool          $plugin_upgrade_mode
	 * @property bool          $plugin_downgrade_mode
	 * @property string        $plugin_version
	 * @property string        $plugin_last_version
	 * @property bool          $is_plugin_new_install
	 * @property bool          $was_plugin_loaded
	 * @property object        $plugin_main_file
	 * @property bool          $prev_is_premium
	 * @property array         $is_anonymous
	 * @property bool          $is_pending_activation
	 * @property bool          $sticky_optin_added
	 * @property object        $uninstall_reason
	 * @property object        $subscription
	 */
	class FS_Key_Value_Storage implements ArrayAccess, Iterator, Countable {
		/**
		 * @var string
		 */
		protected $_id;
		/**
		 * @var string
		 */
		protected $_slug;
		/**
		 * @var array
		 */
		protected $_data;

		/**
		 * @var FS_Plugin_Manager[]
		 */
		private static $_instances = array();
		/**
		 * @var FS_Logger
		 */
		protected $_logger;

		/**
		 * @param string $id
		 * @param string $slug
		 *
		 * @return FS_Key_Value_Storage
		 */
		static function instance( $id, $slug ) {
			$key = $id . ':' . $slug;
			if ( ! isset( self::$_instances[ $key ] ) ) {
				self::$_instances[ $key ] = new FS_Key_Value_Storage( $id, $slug );
			}

			return self::$_instances[ $key ];
		}

		protected function __construct( $id, $slug ) {
			$this->_logger = FS_Logger::get_logger( WP_FS__SLUG . '_' . $slug . '_' . $id, WP_FS__DEBUG_SDK, WP_FS__ECHO_DEBUG_SDK );

			$this->_slug = $slug;
			$this->_id   = $id;
			$this->load();
		}

		protected function get_option_manager() {
			return FS_Option_Manager::get_manager( WP_FS__ACCOUNTS_OPTION_NAME, true );
		}

		protected function get_all_data() {
			return $this->get_option_manager()->get_option( $this->_id, array() );
		}

		/**
		 * Load plugin data from local DB.
		 *
		 * @author Vova Feldman (@svovaf)
		 * @since  1.0.7
		 */
		function load() {
			$all_plugins_data = $this->get_all_data();
			$this->_data      = isset( $all_plugins_data[ $this->_slug ] ) ?
				$all_plugins_data[ $this->_slug ] :
				array();
		}

		/**
		 * @author   Vova Feldman (@svovaf)
		 * @since    1.0.7
		 *
		 * @param string $key
		 * @param mixed  $value
		 * @param bool   $flush
		 */
		function store( $key, $value, $flush = true ) {
			if ( $this->_logger->is_on() ) {
				$this->_logger->entrance( $key . ' = ' . var_export( $value, true ) );
			}

			if ( array_key_exists( $key, $this->_data ) && $value === $this->_data[ $key ] ) {
				// No need to store data if the value wasn't changed.
				return;
			}

			$all_data = $this->get_all_data();

			$this->_data[ $key ] = $value;

			$all_data[ $this->_slug ] = $this->_data;

			$options_manager = $this->get_option_manager();
			$options_manager->set_option( $this->_id, $all_data, $flush );
		}

		/**
		 * @author   Vova Feldman (@svovaf)
		 * @since    1.0.7
		 *
		 * @param bool     $store
		 * @param string[] $exceptions Set of keys to keep and not clear.
		 */
		function clear_all( $store = true, $exceptions = array() ) {
			$new_data = array();
			foreach ( $exceptions as $key ) {
				if ( isset( $this->_data[ $key ] ) ) {
					$new_data[ $key ] = $this->_data[ $key ];
				}
			}

			$this->_data = $new_data;

			if ( $store ) {
				$all_data                 = $this->get_all_data();
				$all_data[ $this->_slug ] = $this->_data;
				$options_manager          = $this->get_option_manager();
				$options_manager->set_option( $this->_id, $all_data, true );
			}
		}

		/**
		 * Delete key-value storage.
		 *
		 * @author   Vova Feldman (@svovaf)
		 * @since    1.0.9
		 */
		function delete() {
			$this->_data = array();

			$all_data = $this->get_all_data();
			unset( $all_data[ $this->_slug ] );
			$options_manager = $this->get_option_manager();
			$options_manager->set_option( $this->_id, $all_data, true );
		}

		/**
		 * @author   Vova Feldman (@svovaf)
		 * @since    1.0.7
		 *
		 * @param string $key
		 * @param bool   $store
		 */
		function remove( $key, $store = true ) {
			if ( ! array_key_exists( $key, $this->_data ) ) {
				return;
			}

			unset( $this->_data[ $key ] );

			if ( $store ) {
				$all_data                 = $this->get_all_data();
				$all_data[ $this->_slug ] = $this->_data;
				$options_manager          = $this->get_option_manager();
				$options_manager->set_option( $this->_id, $all_data, true );
			}
		}

		/**
		 * @author Vova Feldman (@svovaf)
		 * @since  1.0.7
		 *
		 * @param string $key
		 * @param mixed  $default
		 *
		 * @return bool|\FS_Plugin
		 */
		function get( $key, $default = false ) {
			return array_key_exists( $key, $this->_data ) ?
				$this->_data[ $key ] :
				$default;
		}


		/* ArrayAccess + Magic Access (better for refactoring)
        -----------------------------------------------------------------------------------*/
		function __set( $k, $v ) {
			$this->store( $k, $v );
		}

		function __isset( $k ) {
			return array_key_exists( $k, $this->_data );
		}

		function __unset( $k ) {
			$this->remove( $k );
		}

		function __get( $k ) {
			return $this->get( $k, null );
		}

		function offsetSet( $k, $v ) {
			if ( is_null( $k ) ) {
				throw new Exception( 'Can\'t append value to request params.' );
			} else {
				$this->{$k} = $v;
			}
		}

		function offsetExists( $k ) {
			return array_key_exists( $k, $this->_data );
		}

		function offsetUnset( $k ) {
			unset( $this->$k );
		}

		function offsetGet( $k ) {
			return $this->get( $k, null );
		}

		/**
		 * (PHP 5 &gt;= 5.0.0)<br/>
		 * Return the current element
		 *
		 * @link http://php.net/manual/en/iterator.current.php
		 * @return mixed Can return any type.
		 */
		public function current() {
			return current( $this->_data );
		}

		/**
		 * (PHP 5 &gt;= 5.0.0)<br/>
		 * Move forward to next element
		 *
		 * @link http://php.net/manual/en/iterator.next.php
		 * @return void Any returned value is ignored.
		 */
		public function next() {
			next( $this->_data );
		}

		/**
		 * (PHP 5 &gt;= 5.0.0)<br/>
		 * Return the key of the current element
		 *
		 * @link http://php.net/manual/en/iterator.key.php
		 * @return mixed scalar on success, or null on failure.
		 */
		public function key() {
			return key( $this->_data );
		}

		/**
		 * (PHP 5 &gt;= 5.0.0)<br/>
		 * Checks if current position is valid
		 *
		 * @link http://php.net/manual/en/iterator.valid.php
		 * @return boolean The return value will be casted to boolean and then evaluated.
		 *       Returns true on success or false on failure.
		 */
		public function valid() {
			$key = key( $this->_data );

			return ( $key !== null && $key !== false );
		}

		/**
		 * (PHP 5 &gt;= 5.0.0)<br/>
		 * Rewind the Iterator to the first element
		 *
		 * @link http://php.net/manual/en/iterator.rewind.php
		 * @return void Any returned value is ignored.
		 */
		public function rewind() {
			reset( $this->_data );
		}

		/**
		 * (PHP 5 &gt;= 5.1.0)<br/>
		 * Count elements of an object
		 *
		 * @link http://php.net/manual/en/countable.count.php
		 * @return int The custom count as an integer.
		 *       </p>
		 *       <p>
		 *       The return value is cast to an integer.
		 */
		public function count() {
			return count( $this->_data );
		}
	}