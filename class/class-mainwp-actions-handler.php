<?php
/**
 * Handle Dashboard Hooks Actions.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Actions_Handler
 */
class MainWP_Actions_Handler {

	/**
	 * Private static variable to hold the single instance of the class.
	 *
	 * @static
	 *
	 * @var mixed Default null
	 */
	private static $instance = null;

	/**
	 * Method instance()
	 *
	 * Create a public static instance.
	 *
	 * @static
	 * @return MainWP_Actions_Handler
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Action mainwp_post_action.
	 *
	 * @param object    $website  website data.
	 * @param string    $pAction post action.
	 * @param array     $information post action information.
	 * @param int|false $postId post id.
	 * @param string    $type post|page.
	 */
	public function do_action_mainwp_post_action( $website, $pAction, $information, $postId, $type = '' ) {
		if ( is_array( $information ) && isset( $information['status'] ) && ( 'SUCCESS' === $information['status'] ) ) {
			$data = isset( $information['other_data']['post_action_data'] ) ? $information['other_data']['post_action_data'] : array();
			/**
			 * Fires immediately after post action.
			 *
			 * @since 4.5.1.1
			 */
			do_action( 'mainwp_post_action', $website, $pAction, $data, $postId, $type );
		}
	}

	/**
	 * Action mainwp_install_actions.
	 *
	 * @param array  $websites  websites.
	 * @param string $pAction install action.
	 * @param mixed  $output result.
	 * @param string $type action type.
	 * @param mixed  $post_data post data (option).
	 * @param bool   $upload true|false: install by upload (option).
	 */
	public function do_action_mainwp_install_actions( $websites, $pAction, $output, $type, $post_data = array(), $upload = false ) {

		if ( ! in_array( $pAction, array( 'install', 'updated' ), true ) ) {
			return false;
		}

		$website = is_array( $websites ) ? current( $websites ) : $websites;
		if ( empty( $website ) ) {
			return;
		}

		if ( ! is_array( $post_data ) ) {
			$post_data = array();
		}

		$data = array();
		if ( is_array( $output ) ) {
			$data = $output;
		} elseif ( is_object( $output ) ) {
			if ( ! empty( $output->other_data ) && is_array( $output->other_data ) ) {
				if ( isset( $output->other_data[ $website->id ]['install_items'] ) ) {
					$data['install_items'] = $output->other_data[ $website->id ]['install_items'];
				}
			}
		}

		/**
		 * Fires immediately after install action.
		 *
		 * @since 4.5.1.1
		 */
		do_action( 'mainwp_install_update_actions', $website, $pAction, $data, $type, $post_data, $upload );
	}

	/**
	 *
	 * Handle @action mainwp_fetch_url_authed.
	 *
	 * @param object $website  website.
	 * @param array  $information information result data.
	 * @param string $action action.
	 * @param array  $params params input array.
	 * @param array  $others others input array.
	 *
	 * @since 4.5.1.1
	 */
	public function hook_mainwp_fetch_url_authed( $website, $information, $action, $params, $others ) {
		if ( 'plugin_action' === $action ) {
			$plugin_act = isset( $params['action'] ) ? $params['action'] : '';
			if ( in_array( $plugin_act, array( 'activate', 'deactivate', 'delete' ) ) && isset( $information['other_data']['plugin_action_data'] ) ) {
				do_action( 'mainwp_install_plugin_action', $website, $plugin_act, $params, $information['other_data']['plugin_action_data'], $others );
			}
		} elseif ( 'theme_action' === $action ) {
			$theme_act = isset( $params['action'] ) ? $params['action'] : '';
			if ( 'activate' === $theme_act && isset( $information['other_data']['theme_deactivate_data'] ) ) {
				do_action( 'mainwp_install_theme_action', $website, 'deactivate', $params, $information['other_data']['theme_deactivate_data'], $others );
			}
			if ( in_array( $theme_act, array( 'activate', 'delete' ) ) && isset( $information['other_data']['theme_action_data'] ) ) {
				do_action( 'mainwp_install_theme_action', $website, $theme_act, $params, $information['other_data']['theme_action_data'], $others );
			}
		}
	}
}
