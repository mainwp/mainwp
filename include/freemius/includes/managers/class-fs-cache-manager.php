<?php
	/**
	 * @package     Freemius
	 * @copyright   Copyright (c) 2015, Freemius, Inc.
	 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
	 * @since       1.1.6
	 */

	if ( ! defined( 'ABSPATH' ) ) {
		exit;
	}

	class FS_Cache_Manager {
		/**
		 * @var FS_Option_Manager
		 */
		private $_options;
		/**
		 * @var FS_Logger
		 */
		private $_logger;

		/**
		 * @var FS_Cache_Manager[]
		 */
		private static $_MANAGERS = array();

		/**
		 * @author Vova Feldman (@svovaf)
		 * @since  1.1.3
		 *
		 * @param string $id
		 */
		private function __construct( $id ) {
			$this->_logger = FS_Logger::get_logger( WP_FS__SLUG . '_cach_mngr_' . $id, WP_FS__DEBUG_SDK, WP_FS__ECHO_DEBUG_SDK );

			$this->_logger->entrance();
			$this->_logger->log( 'id = ' . $id );

			$this->_options = FS_Option_Manager::get_manager( $id, true );
		}

		/**
		 * @author Vova Feldman (@svovaf)
		 * @since  1.1.6
		 *
		 * @param $id
		 *
		 * @return FS_Cache_Manager
		 */
		static function get_manager( $id ) {
			$id = strtolower( $id );

			if ( ! isset( self::$_MANAGERS[ $id ] ) ) {
				self::$_MANAGERS[ $id ] = new FS_Cache_Manager( $id );
			}

			return self::$_MANAGERS[ $id ];
		}

		/**
		 * @author Vova Feldman (@svovaf)
		 * @since  1.1.6
		 *
		 * @return bool
		 */
		function is_empty() {
			$this->_logger->entrance();

			return $this->_options->is_empty();
		}

		/**
		 * @author Vova Feldman (@svovaf)
		 * @since  1.1.6
		 */
		function clear() {
			$this->_logger->entrance();

			$this->_options->clear( true );
		}

		/**
		 * Delete cache manager from DB.
		 *
		 * @author Vova Feldman (@svovaf)
		 * @since  1.0.9
		 */
		function delete() {
			$this->_options->delete();
		}

		/**
		 * Check if there's a cached item.
		 *
		 * @author Vova Feldman (@svovaf)
		 * @since  1.1.6
		 *
		 * @param string $key
		 *
		 * @return bool
		 */
		function has( $key ) {
			$cache_entry = $this->_options->get_option( $key, false );

			return ( is_object( $cache_entry ) &&
			         isset( $cache_entry->timestamp ) &&
			         is_numeric( $cache_entry->timestamp )
			);
		}

		/**
		 * Check if there's a valid cached item.
		 *
		 * @author Vova Feldman (@svovaf)
		 * @since  1.1.6
		 *
		 * @param string $key
		 *
		 * @return bool
		 */
		function has_valid( $key ) {
			$cache_entry = $this->_options->get_option( $key, false );

			return ( is_object( $cache_entry ) &&
			         isset( $cache_entry->timestamp ) &&
			         is_numeric( $cache_entry->timestamp ) &&
			         $cache_entry->timestamp > WP_FS__SCRIPT_START_TIME
			);
		}

		/**
		 * @author Vova Feldman (@svovaf)
		 * @since  1.1.6
		 *
		 * @param string $key
		 * @param mixed  $default
		 *
		 * @return mixed
		 */
		function get( $key, $default = null ) {
			$this->_logger->entrance( 'key = ' . $key );

			$cache_entry = $this->_options->get_option( $key, false );

			if ( is_object( $cache_entry ) &&
			     isset( $cache_entry->timestamp ) &&
			     is_numeric( $cache_entry->timestamp )
			) {
				return $cache_entry->result;
			}

			return $default;
		}

		/**
		 * @author Vova Feldman (@svovaf)
		 * @since  1.1.6
		 *
		 * @param string $key
		 * @param mixed  $default
		 *
		 * @return mixed
		 */
		function get_valid( $key, $default = null ) {
			$this->_logger->entrance( 'key = ' . $key );

			$cache_entry = $this->_options->get_option( $key, false );

			if ( is_object( $cache_entry ) &&
			     isset( $cache_entry->timestamp ) &&
			     is_numeric( $cache_entry->timestamp ) &&
			     $cache_entry->timestamp > WP_FS__SCRIPT_START_TIME
			) {
				return $cache_entry->result;
			}

			return $default;
		}

		/**
		 * @author Vova Feldman (@svovaf)
		 * @since  1.1.6
		 *
		 * @param string $key
		 * @param mixed  $value
		 * @param int    $expiration
		 */
		function set( $key, $value, $expiration = WP_FS__TIME_24_HOURS_IN_SEC ) {
			$this->_logger->entrance( 'key = ' . $key );

			$cache_entry            = new stdClass();
			$cache_entry->result    = $value;
			$cache_entry->timestamp = WP_FS__SCRIPT_START_TIME + $expiration;
			$this->_options->set_option( $key, $cache_entry, true );
		}

		/**
		 * Get cached record expiration, or false if not cached or expired.
		 *
		 * @author Vova Feldman (@svovaf)
		 * @since  1.1.7.3
		 *
		 * @param string $key
		 *
		 * @return bool|int
		 */
		function get_record_expiration( $key ) {
			$this->_logger->entrance( 'key = ' . $key );

			$cache_entry = $this->_options->get_option( $key, false );

			if ( is_object( $cache_entry ) &&
			     isset( $cache_entry->timestamp ) &&
			     is_numeric( $cache_entry->timestamp ) &&
			     $cache_entry->timestamp > WP_FS__SCRIPT_START_TIME
			) {
				return $cache_entry->timestamp;
			}

			return false;
		}

		/**
		 * Purge cached item.
		 *
		 * @author Vova Feldman (@svovaf)
		 * @since  1.1.6
		 *
		 * @param string $key
		 */
		function purge( $key ) {
			$this->_logger->entrance( 'key = ' . $key );

			$this->_options->unset_option( $key, true );
		}
	}