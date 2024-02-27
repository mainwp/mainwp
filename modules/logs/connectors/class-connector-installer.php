<?php
/**
 * Module Logs Installer connector class.
 *
 * @package MainWP\Dashboard
 * @version 4.5.1
 */

namespace MainWP\Dashboard\Module\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Class Connector_Installer
 *
 * @package MainWP\Dashboard
 */
class Connector_Installer extends Log_Connector {

	/**
	 * Connector name.
	 *
	 * @var string Connector slug.
	 * */
	public $name = 'installer';

	/**
	 * Actions names.
	 *
	 * @var array Actions registered for this connector.
	 * */
	public $actions = array(
		'mainwp_install_update_actions',
		'mainwp_install_plugin_action', // call child function: plugin_action.
		'mainwp_install_theme_action', // call child function: theme_action.
	);


	/**
	 * Return translated connector label.
	 *
	 * @return string Translated connector label.
	 */
	public function get_label() {
		return esc_html__( 'Installer', 'mainwp' );
	}

	/**
	 * Return translated action labels.
	 *
	 * @return array Action label translations.
	 */
	public function get_action_labels() {
		return array(
			'install'    => esc_html__( 'Installed', 'mainwp' ),
			'activate'   => esc_html__( 'Activated', 'mainwp' ),
			'deactivate' => esc_html__( 'Deactivated', 'mainwp' ),
			'delete'     => esc_html__( 'Deleted', 'mainwp' ),
			'updated'    => esc_html__( 'Updated', 'mainwp' ),
		);
	}

	/**
	 * Return translated context labels.
	 *
	 * @return array Context label translations.
	 */
	public function get_context_labels() {
		return array(
			'plugins'     => esc_html__( 'Plugins', 'mainwp' ),
			'themes'      => esc_html__( 'Themes', 'mainwp' ),
			'core'        => esc_html__( 'Core', 'mainwp' ),
			'translation' => esc_html__( 'Translation', 'mainwp' ),
		);
	}

	/**
	 * Register log data.
	 *
	 * @uses \MainWP\Dashboard\Module\Log\Log_Connector::register()
	 */
	public function register() { //phpcs:ignore -- overrided.
		parent::register();
	}


	/**
	 * Log plugin|theme installations.
	 *
	 * @action mainwp_install_update_actions.
	 *
	 * @param array  $website  website.
	 * @param string $pAction install action.
	 * @param array  $data data result.
	 * @param string $type action type.
	 * @param mixed  $post_data post data (option).
	 * @param bool   $upload true|false install by upload.
	 */
	public function callback_mainwp_install_update_actions( $website, $pAction, $data, $type, $post_data = array(), $upload = false ) { //phpcs:ignore -- complex method.

		if ( empty( $website ) || empty( $pAction ) || ! is_string( $pAction ) || ! is_array( $data ) ) {
			return;
		}

		if ( ! in_array( $pAction, array( 'install', 'updated' ), true ) ) {
			return;
		}

		$context = '';

		if ( 'plugin' === $type ) {
			$context = 'plugin';
		} elseif ( 'theme' === $type ) {
			$context = 'theme';
		} elseif ( 'trans' === $type ) {
			$context = 'translation';
		} elseif ( 'core' === $type ) {
			$context = 'core';
		} else {
			return;
		}

		$logs_args = array();

		$action = $pAction;

		$args = array();

		if ( 'install' === $action ) {

			$installed_items = isset( $data['install_items'] ) ? $data['install_items'] : array();

			if ( ! is_array( $installed_items ) ) {
				$installed_items = array();
			}

			if ( empty( $installed_items ) ) {
				return false;
			}

			foreach ( $installed_items as $item ) {
				$item = $this->sanitize_data( $item );
				if ( ! empty( $item ) && isset( $item['name'] ) ) {
					$args        = array(
						'name'       => $item['name'],
						'version'    => $item['version'],
						'upload'     => $upload ? 1 : 0,
						'siteurl'    => $website->url,
						'site_name'  => $website->name,
						'extra_info' => $item,
					);
					$logs_args[] = $args;
				}
			}

			if ( empty( $logs_args ) ) {
				return false;
			}

			$message = esc_html_x(
				'%1$s',
				'Plugin/theme installation. 1: Plugins/themes namess, 2: vesion',
				'mainwp'
			);

		} elseif ( 'updated' === $action && ( in_array( $type, array( 'plugin', 'theme', 'trans' ) ) ) ) {

			$updated_data = isset( $data['updated_data'] ) ? $data['updated_data'] : array();

			if ( ! is_array( $updated_data ) ) {
				$updated_data = array();
			}

			$logs_args = array();

			foreach ( $updated_data as $item ) {
				$item = $this->sanitize_data( $item );
				if ( ! empty( $item ) && isset( $item['name'] ) ) {
					$args = array(
						'name' => $item['name'],
					);
					if ( isset( $item['version'] ) ) {
						$args['version'] = $item['version'];
					}
					$args['siteurl']    = $website->url;
					$args['site_name']  = $website->name;
					$args['extra_info'] = $item;
					$logs_args[]        = $args;
				}
			}

			if ( empty( $logs_args ) ) {
				return false;
			}

			if ( 'plugin' === $type || 'theme' === $type ) {
				$message = esc_html_x(
					'%1$s',
					'Update. 1: name',
					'mainwp'
				);
			} elseif ( 'trans' === $type ) {
				$message = esc_html_x(
					'%1$s',
					'Update. 1: name',
					'mainwp'
				);
			} else {
				return;
			}
		} elseif ( 'updated' === $action && 'core' === $type ) {
			$message     = esc_html__( '%1$s', 'mainwp' );
			$args        = array(
				'name'        => 'WordPress', // label.
				'version'     => isset( $data['version'] ) ? $data['version'] : '',
				'old_version' => isset( $data['old_version'] ) ? $data['old_version'] : '',
				'error'       => isset( $data['error'] ) ? $data['error'] : '',
				'siteurl'     => $website->url,
				'site_name'   => $website->name,
			);
			$logs_args[] = $args;

		} else {
			return false;
		}

		$count_bulk = count( $logs_args );
		foreach ( $logs_args as $args ) {
			$args['duration_bulk'] = $count_bulk;

			$state = 1;
			if ( isset( $args['extra_info'] ) && is_array( $args['extra_info'] ) ) {
				if ( isset( $args['extra_info']['success'] ) ) {
					$state = ! empty( $args['extra_info']['success'] ) ? 1 : 0;
				}
				$args['extra_info'] = wp_json_encode( $args['extra_info'] );
			}
			$this->log(
				$message,
				$args,
				$website->id,
				$context,
				$action,
				$state
			);
		}

		return true;
	}

	/**
	 * Log plugin actions.
	 *
	 * @action mainwp_install_plugin_action.
	 *
	 * @param array  $website  website.
	 * @param string $plugin_act plugin action: activate, deactivate, delete.
	 * @param array  $params params array.
	 * @param array  $action_data response data.
	 * @param array  $others others data.
	 */
	public function callback_mainwp_install_plugin_action( $website, $plugin_act, $params, $action_data, $others ) {

		if ( empty( $website ) || ! is_object( $website ) || empty( $website->id ) || empty( $plugin_act ) || ! is_string( $plugin_act ) || ! is_array( $params ) ) {
			return;
		}

		if ( ! in_array( $plugin_act, array( 'activate', 'delete', 'deactivate' ), true ) ) {
			return;
		}

		if ( ! is_array( $action_data ) ) {
			return;
		}

		$logs_args = array();

		foreach ( $action_data as $item ) {
			$item = $this->sanitize_data( $item );
			if ( ! empty( $item ) && isset( $item['name'] ) ) {
				$logs_args[] = array(
					'name'      => $item['name'],
					'version'   => $item['version'],
					'slug'      => $item['slug'],
					'siteurl'   => $website->url,
					'site_name' => $website->name,
				);
			}
		}

		if ( empty( $logs_args ) ) {
			return false;
		}

		$action  = $plugin_act;
		$context = 'plugin';

		$message = esc_html_x(
			'%1$s',
			'1: Plugin name, 2: plugin version',
			'mainwp'
		);

		$state = 1;

		$count_bulk = count( $logs_args );
		foreach ( $logs_args as $args ) {
			$args['duration_bulk'] = $count_bulk;
			$this->log(
				$message,
				$args,
				$website->id,
				$context,
				$action,
				$state
			);
		}
	}

	/**
	 * Log themes actions.
	 *
	 * @action mainwp_install_theme_action.
	 *
	 * @param array  $website  website.
	 * @param string $theme_act theme action: activate, delete.
	 * @param array  $params params array.
	 * @param array  $action_data response data.
	 * @param array  $others others data.
	 */
	public function callback_mainwp_install_theme_action( $website, $theme_act, $params, $action_data, $others ) {

		if ( empty( $website ) || ! is_object( $website ) || empty( $website->id ) || empty( $theme_act ) || ! is_string( $theme_act ) || ! is_array( $params ) ) {
			return;
		}

		if ( ! in_array( $theme_act, array( 'activate', 'delete', 'deactivate' ), true ) ) {
			return;
		}

		if ( ! is_array( $action_data ) ) {
			return;
		}

		$logs_args = array();

		foreach ( $action_data as $item ) {
			$item = $this->sanitize_data( $item );
			if ( ! empty( $item ) && isset( $item['name'] ) ) {
				$logs_args[] = array(
					'name'      => $item['name'],
					'version'   => $item['version'],
					'slug'      => $item['slug'],
					'siteurl'   => $website->url,
					'site_name' => $website->name,
				);
			}
		}

		if ( empty( $logs_args ) ) {
			return false;
		}

		$action  = $theme_act;
		$context = 'theme';

		$message = esc_html_x(
			'%1$s',
			'1: Theme name, 2: Theme version',
			'mainwp'
		);

		$state = 1;

		$count_bulk = count( $logs_args );
		foreach ( $logs_args as $args ) {
			$args['duration_bulk'] = $count_bulk;
			$this->log(
				$message,
				$args,
				$website->id,
				$context,
				$action,
				$state
			);
		}
	}
}
