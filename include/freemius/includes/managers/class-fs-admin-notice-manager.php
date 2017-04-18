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

	class FS_Admin_Notice_Manager {
		/**
		 * @var string
		 */
		protected $_slug;
		/**
		 * @var string
		 */
		protected $_title;
		/**
		 * @var array[]
		 */
		private $_admin_messages = array();
		/**
		 * @var FS_Key_Value_Storage
		 */
		private $_sticky_storage;
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
		 * @param string $title
		 *
		 * @return FS_Admin_Notice_Manager
		 */
		static function instance( $slug, $title = '' ) {
			if ( ! isset( self::$_instances[ $slug ] ) ) {
				self::$_instances[ $slug ] = new FS_Admin_Notice_Manager( $slug, $title );
			}

			return self::$_instances[ $slug ];
		}

		protected function __construct( $slug, $title = '' ) {
			$this->_logger = FS_Logger::get_logger( WP_FS__SLUG . '_' . $slug . '_data', WP_FS__DEBUG_SDK, WP_FS__ECHO_DEBUG_SDK );

			$this->_slug           = $slug;
			$this->_title          = ! empty( $title ) ? $title : '';
			$this->_sticky_storage = FS_Key_Value_Storage::instance( 'admin_notices', $this->_slug );

			if ( is_admin() ) {
				if ( 0 < count( $this->_sticky_storage ) ) {
					// If there are sticky notices for the current slug, add a callback
					// to the AJAX action that handles message dismiss.
					add_action( "wp_ajax_fs_dismiss_notice_action_{$slug}", array(
						&$this,
						'dismiss_notice_ajax_callback'
					) );

					foreach ( $this->_sticky_storage as $id => $msg ) {
						// Add admin notice.
						$this->add(
							$msg['message'],
							$msg['title'],
							$msg['type'],
							true,
							$msg['all'],
							$msg['id'],
							false
						);
					}
				}
			}
		}

		/**
		 * Remove sticky message by ID.
		 *
		 * @author Vova Feldman (@svovaf)
		 * @since  1.0.7
		 *
		 */
		function dismiss_notice_ajax_callback() {
			$this->_sticky_storage->remove( $_POST['message_id'] );
			wp_die();
		}

		/**
		 * Rendered sticky message dismiss JavaScript.
		 *
		 * @author Vova Feldman (@svovaf)
		 * @since  1.0.7
		 */
		static function _add_sticky_dismiss_javascript() {
			$params = array();
			fs_require_once_template( 'sticky-admin-notice-js.php', $params );
		}

		private static $_added_sticky_javascript = false;

		/**
		 * Hook to the admin_footer to add sticky message dismiss JavaScript handler.
		 *
		 * @author Vova Feldman (@svovaf)
		 * @since  1.0.7
		 */
		private static function has_sticky_messages() {
			if ( ! self::$_added_sticky_javascript ) {
				add_action( 'admin_footer', array( 'FS_Admin_Notice_Manager', '_add_sticky_dismiss_javascript' ) );
			}
		}

		/**
		 * Handle admin_notices by printing the admin messages stacked in the queue.
		 *
		 * @author Vova Feldman (@svovaf)
		 * @since  1.0.4
		 *
		 */
		function _admin_notices_hook() {
			$notice_type = 'admin_notices';

			if ( function_exists( 'current_user_can' ) &&
			     ! current_user_can( 'manage_options' )
			) {
				// Only show messages to admins.
				return;
			}

			if ( ! isset( $this->_admin_messages[ $notice_type ] ) || ! is_array( $this->_admin_messages[ $notice_type ] ) ) {
				return;
			}

			foreach ( $this->_admin_messages[ $notice_type ] as $id => $msg ) {
				fs_require_template( 'admin-notice.php', $msg );

				if ( $msg['sticky'] ) {
					self::has_sticky_messages();
				}
			}
		}

		/**
		 * Handle all_admin_notices by printing the admin messages stacked in the queue.
		 *
		 * @author Vova Feldman (@svovaf)
		 * @since  1.0.4
		 *
		 */
		function _all_admin_notices_hook() {
			$notice_type = 'all_admin_notices';

			if ( ! isset( $this->_admin_messages[ $notice_type ] ) || ! is_array( $this->_admin_messages[ $notice_type ] ) ) {
				return;
			}

			foreach ( $this->_admin_messages[ $notice_type ] as $id => $msg ) {
				fs_require_template( 'all-admin-notice.php', $msg );
			}
		}

		/**
		 * Enqueue common stylesheet to style admin notice.
		 *
		 * @author Vova Feldman (@svovaf)
		 * @since  1.0.7
		 */
		function _enqueue_styles() {
			fs_enqueue_local_style( 'fs_common', '/admin/common.css' );
		}

		/**
		 * Add admin message to admin messages queue, and hook to admin_notices / all_admin_notices if not yet hooked.
		 *
		 * @author Vova Feldman (@svovaf)
		 * @since  1.0.4
		 *
		 * @param string $message
		 * @param string $title
		 * @param string $type
		 * @param bool   $is_sticky
		 * @param bool   $all_admin
		 * @param string $id Message ID
		 * @param bool   $store_if_sticky
		 *
		 * @uses   add_action()
		 */
		function add( $message, $title = '', $type = 'success', $is_sticky = false, $all_admin = false, $id = '', $store_if_sticky = true ) {
			$key = ( $all_admin ? 'all_admin_notices' : 'admin_notices' );

			if ( ! isset( $this->_admin_messages[ $key ] ) ) {
				$this->_admin_messages[ $key ] = array();

				add_action( $key, array( &$this, "_{$key}_hook" ) );
				add_action( 'admin_enqueue_scripts', array( &$this, '_enqueue_styles' ) );

			}

			if ( '' === $id ) {
				$id = md5( $title . ' ' . $message . ' ' . $type );
			}

			$message_object = array(
				'message' => $message,
				'title'   => $title,
				'type'    => $type,
				'sticky'  => $is_sticky,
				'id'      => $id,
				'all'     => $all_admin,
				'slug'    => $this->_slug,
				'plugin'  => $this->_title,
			);

			if ( $is_sticky && $store_if_sticky ) {
				$this->_sticky_storage->{$id} = $message_object;
			}

			$this->_admin_messages[ $key ][ $id ] = $message_object;
		}

		/**
		 * @author Vova Feldman (@svovaf)
		 * @since  1.0.7
		 *
		 * @param string|string[] $ids
		 */
		function remove_sticky( $ids ) {
			if ( ! is_array( $ids ) ) {
				$ids = array( $ids );
			}

			foreach ( $ids as $id ) {
				// Remove from sticky storage.
				$this->_sticky_storage->remove( $id );

				// Remove from current admin messages.
				if ( isset( $this->_admin_messages['all_admin_notices'] ) && isset( $this->_admin_messages['all_admin_notices'][ $id ] ) ) {
					unset( $this->_admin_messages['all_admin_notices'][ $id ] );
				}
				if ( isset( $this->_admin_messages['admin_notices'] ) && isset( $this->_admin_messages['admin_notices'][ $id ] ) ) {
					unset( $this->_admin_messages['admin_notices'][ $id ] );
				}
			}
		}

		/**
		 * Check if sticky message exists by id.
		 *
		 * @author Vova Feldman (@svovaf)
		 * @since  1.0.9
		 *
		 * @param $id
		 *
		 * @return bool
		 */
		function has_sticky( $id ) {
			return isset( $this->_sticky_storage[ $id ] );
		}

		/**
		 * Adds sticky admin notification.
		 *
		 * @author Vova Feldman (@svovaf)
		 * @since  1.0.7
		 *
		 * @param string $message
		 * @param string $id Message ID
		 * @param string $title
		 * @param string $type
		 * @param bool   $all_admin
		 */
		function add_sticky( $message, $id, $title = '', $type = 'success', $all_admin = false ) {
			$message = fs_apply_filter( $this->_slug, "sticky_message_{$id}", $message );
			$title   = fs_apply_filter( $this->_slug, "sticky_title_{$id}", $title );

			$this->add( $message, $title, $type, true, $all_admin, $id );
		}

		/**
		 * Clear all sticky messages.
		 *
		 * @author Vova Feldman (@svovaf)
		 * @since  1.0.8
		 */
		function clear_all_sticky() {
			$this->_sticky_storage->clear_all();
		}

		/**
		 * Add admin message to all admin messages queue, and hook to all_admin_notices if not yet hooked.
		 *
		 * @author Vova Feldman (@svovaf)
		 * @since  1.0.4
		 *
		 * @param string $message
		 * @param string $title
		 * @param string $type
		 * @param bool   $is_sticky
		 * @param string $id Message ID
		 */
		function add_all( $message, $title = '', $type = 'success', $is_sticky = false, $id = '' ) {
			$this->add( $message, $title, $type, $is_sticky, true, $id );
		}
	}