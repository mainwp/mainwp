<?php
/**
 * Module Logs User connector class.
 *
 * @package MainWP\Dashboard
 * @version 4.5.1
 */

namespace MainWP\Dashboard\Module\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Class Connector_User
 */
class Connector_User extends Log_Connector {
		/**
		 * Connector slug
		 *
		 * @var string
		 */
		public $name = 'users';


		/**
		 * Actions registered for this connector
		 *
		 * @var array
		 */
		public $actions = array(
			'mainwp_user_action',
		);

		/**
		 * Return translated connector label
		 *
		 * @return string Translated connector label
		 */
		public function get_label() {
			return esc_html__( 'Users', 'mainwp' );
		}

		/**
		 * Return translated action term labels
		 *
		 * @return array Action terms label translation
		 */
		public function get_action_labels() {
			return array(
				'created'         => esc_html__( 'Created', 'mainwp' ),
				'update'          => esc_html__( 'Updated', 'mainwp' ),
				'delete'          => esc_html__( 'Deleted', 'mainwp' ),
				'update_password' => esc_html__( 'Updated password', 'mainwp' ),
				'change_role'     => esc_html__( 'Change role', 'mainwp' ),
			);
		}

		/**
		 * Return translated context labels
		 *
		 * @return array Context label translations
		 */
		public function get_context_labels() {
			return array(
				'users' => esc_html__( 'Users', 'mainwp' ),
			);
		}

		/**
		 * Log user actions.
		 *
		 * @action mainwp_user_action
		 *
		 * @param object $website  website data.
		 * @param string $pAction post action.
		 * @param array  $data data array.
		 * @param mixed  $extra extra info.
		 * @param bool   $optimize true|false optimize big site or not.
		 */
		public function callback_mainwp_user_action( $website, $pAction, $data, $extra = array(), $optimize = false ) {

			if ( empty( $website ) || ! is_object( $website ) || empty( $website->id ) || ! is_array( $data ) ) {
				return;
			}

			$action = '';

			$userId = 0;

			if ( isset( $data['user_id'] ) ) {
				$userId = intval( $data['user_id'] );
			}

			if ( empty( $userId ) ) {
				return;
			}

			$display_name = isset( $data['display_name'] ) ? $data['display_name'] : '';

			$args = array(
				'display_name' => $display_name,
				'roles'        => isset( $data['roles'] ) ? $data['roles'] : '',
				'user_id'      => $userId,
			);

			// translators: 1: User display name, 2: Roles.
			$message = esc_html_x(
				'%1$s',
				'1: User display name',
				'mainwp'
			);

			if ( 'created' === $pAction ) {
				$action = 'created';
			} elseif ( 'delete' === $pAction ) {
				$action = 'delete';
			} elseif ( 'changeRole' === $pAction ) {
				$action            = 'change_role';
				$args['old_roles'] = isset( $data['old_roles'] ) ? $data['old_roles'] : '';
			} elseif ( 'newadminpassword' === $pAction ) {
				$action = 'update_password';
			} elseif ( 'update_password' === $pAction ) {
				$action = 'update_password';
			} elseif ( 'update_user' === $pAction ) {
				$action = 'update';
			} else {
				return;
			}

			$args['siteurl']   = $website->url;
			$args['site_name'] = $website->name;
			$state             = 1;

			$this->log(
				$message,
				$args,
				$website->id,
				'users',
				$action,
				$state
			);
		}
}
