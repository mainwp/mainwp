<?php
/**
 * MainWP Plugins Page Handler.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 *  MainWP Plugins Handler
 *
 * @uses MainWP_Install_Bulk()
 */
class MainWP_Plugins_Handler {

	/**
	 * Get Class Name.
	 *
	 * @return string __CLASS__
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Plugins Search Handler.
	 *
	 * @param mixed  $data Search Data.
	 * @param object $website Child Sites.
	 * @param mixed  $output Output.
	 *
	 * @uses MainWP_Error_Helper::get_error_message()
	 * @uses MainWP_Exception()
	 * @uses MainWP_Error_Helper::get_error_message()
	 *
	 * @return mixed error|array.
	 */
	public static function plugins_search_handler( $data, $website, &$output ) {
		if ( 0 < preg_match( '/<mainwp>(.*)<\/mainwp>/', $data, $results ) ) {
			$result  = $results[1];
			$plugins = MainWP_System_Utility::get_child_response( base64_decode( $result ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
			unset( $results );
			if ( isset( $plugins['error'] ) ) {
				$output->errors[ $website->id ] = MainWP_Error_Helper::get_error_message( new MainWP_Exception( $plugins['error'], $website->url ) );
				return;
			}

			foreach ( $plugins as $plugin ) {
				if ( ! isset( $plugin['name'] ) ) {
					continue;
				}
				$plugin['websiteid']  = $website->id;
				$plugin['websiteurl'] = $website->url;

				$output->plugins[] = $plugin;
			}
			unset( $plugins );
		} else {
			$output->errors[ $website->id ] = MainWP_Error_Helper::get_error_message( new MainWP_Exception( 'NOMAINWP', $website->url ) );
		}
	}

	/** Activate Plugin. */
	public static function activate_plugins() {
		self::action( 'activate' );
	}

	/** Deactivate Plugin */
	public static function deactivate_plugins() {
		self::action( 'deactivate' );
	}

	/** Delete Plugin. */
	public static function delete_plugins() {
		self::action( 'delete' );
	}

	/** Ignore Plugin. */
	public static function ignore_updates() {
		$websiteId = $_POST['websiteId'];

		if ( ! MainWP_Utility::ctype_digit( $websiteId ) ) {
			die( wp_json_encode( array( 'error' => __( 'Invalid request. Please try again.', 'mainwp' ) ) ) );
		}

		$website = MainWP_DB::instance()->get_website_by_id( $websiteId );

		if ( ! MainWP_System_Utility::can_edit_website( $website ) ) {
			die( wp_json_encode( array( 'error' => __( 'You are not allowed to edit this website.', 'mainwp' ) ) ) );
		}

		$plugins = $_POST['plugins'];
		$names   = $_POST['names'];

		$decodedIgnoredPlugins = json_decode( $website->ignored_plugins, true );

		if ( ! is_array( $decodedIgnoredPlugins ) ) {
			$decodedIgnoredPlugins = array();
		}

		if ( is_array( $plugins ) ) {
			$_count = count( $plugins );
			for ( $i = 0; $i < $_count; $i ++ ) {
				$slug = $plugins[ $i ];
				$name = $names[ $i ];
				if ( ! isset( $decodedIgnoredPlugins[ $slug ] ) ) {
					$decodedIgnoredPlugins[ $slug ] = urldecode( $name );
				}
			}

			/**
			* Action: mainwp_before_plugin_ignore
			*
			* Fires before plugin ignore.
			*
			* @since 4.1
			*/
			do_action( 'mainwp_before_plugin_ignore', $decodedIgnoredPlugins, $website );

			MainWP_DB::instance()->update_website_values( $website->id, array( 'ignored_plugins' => wp_json_encode( $decodedIgnoredPlugins ) ) );

			/**
			* Action: mainwp_after_plugin_ignore
			*
			* Fires after plugin ignore.
			*
			* @since 4.1
			*/
			do_action( 'mainwp_after_plugin_ignore', $decodedIgnoredPlugins, $website );

		}

		die( wp_json_encode( array( 'result' => true ) ) );
	}

	/**
	 * Plugin Action handler.
	 *
	 * @param mixed $pAction activate|deactivate|delete.
	 *
	 * @return mixed error|true
	 */
	public static function action( $pAction ) {
		$websiteId = $_POST['websiteId'];

		if ( ! MainWP_Utility::ctype_digit( $websiteId ) ) {
			die( wp_json_encode( array( 'error' => __( 'Invalid request. Please try again.', 'mainwp' ) ) ) );
		}

		$website = MainWP_DB::instance()->get_website_by_id( $websiteId );

		if ( ! MainWP_System_Utility::can_edit_website( $website ) ) {
			die( wp_json_encode( array( 'error' => __( 'You are not allowed to edit this website.', 'mainwp' ) ) ) );
		}

		try {
			$plugin = implode( '||', $_POST['plugins'] );
			$plugin = urldecode( $plugin );

			/**
			* Action: mainwp_before_plugin_action
			*
			* Fires before plugin activate/deactivate/delete actions.
			*
			* @since 4.1
			*/
			do_action( 'mainwp_before_plugin_action', $pAction, $plugin, $website );

			$information = MainWP_Connect::fetch_url_authed(
				$website,
				'plugin_action',
				array(
					'action' => $pAction,
					'plugin' => $plugin,
				)
			);

			/**
			* Action: mainwp_after_plugin_action
			*
			* Fires after plugin activate/deactivate/delete actions.
			*
			* @since 4.1
			*/
			do_action( 'mainwp_after_plugin_action', $information, $pAction, $plugin, $website );

		} catch ( MainWP_Exception $e ) {
			die( wp_json_encode( array( 'error' => MainWP_Error_Helper::get_error_message( $e ) ) ) );
		}

		if ( ! isset( $information['status'] ) || ( 'SUCCESS' !== $information['status'] ) ) {
			die( wp_json_encode( array( 'error' => __( 'Unexpected error. Please try again.', 'mainwp' ) ) ) );
		}

		die( wp_json_encode( array( 'result' => true ) ) );
	}

	/** Trust Plugin $_POST. */
	public static function trust_post() {
		$userExtension  = MainWP_DB_Common::instance()->get_user_extension();
		$trustedPlugins = json_decode( $userExtension->trusted_plugins, true );
		if ( ! is_array( $trustedPlugins ) ) {
			$trustedPlugins = array();
		}
		$action = $_POST['do'];
		$slugs  = $_POST['slugs'];
		if ( ! is_array( $slugs ) ) {
			return;
		}
		if ( 'trust' !== $action && 'untrust' !== $action ) {
			return;
		}
		if ( 'trust' === $action ) {
			foreach ( $slugs as $slug ) {
				$idx = array_search( urldecode( $slug ), $trustedPlugins );
				if ( false === $idx ) {
					$trustedPlugins[] = urldecode( $slug );
				}
			}
		} elseif ( 'untrust' === $action ) {
			foreach ( $slugs as $slug ) {
				if ( in_array( urldecode( $slug ), $trustedPlugins ) ) {
					$trustedPlugins = array_diff( $trustedPlugins, array( urldecode( $slug ) ) );
				}
			}
		}
		$userExtension->trusted_plugins = wp_json_encode( $trustedPlugins );
		MainWP_DB_Common::instance()->update_user_extension( $userExtension );
	}

	/**
	 * Update Trusted Plugin list.
	 *
	 * @param mixed $slug Plugin Slug.
	 */
	public static function trust_plugin( $slug ) {
		$userExtension  = MainWP_DB_Common::instance()->get_user_extension();
		$trustedPlugins = json_decode( $userExtension->trusted_plugins, true );
		if ( ! is_array( $trustedPlugins ) ) {
			$trustedPlugins = array();
		}
		$idx = array_search( urldecode( $slug ), $trustedPlugins );
		if ( false === $idx ) {
			$trustedPlugins[] = urldecode( $slug );
		}
		$userExtension->trusted_plugins = wp_json_encode( $trustedPlugins );
		MainWP_DB_Common::instance()->update_user_extension( $userExtension );
	}

	/**
	 * Check if automatic daily updates is on and
	 * the plugin is on the trusted list.
	 *
	 * @param mixed $slug Plugin Slug.
	 *
	 * @return boolean true|false.
	 */
	public static function check_auto_update_plugin( $slug ) {
		if ( 1 != get_option( 'mainwp_automaticDailyUpdate' ) ) {
			return false;
		}
			$userExtension  = MainWP_DB_Common::instance()->get_user_extension();
			$trustedPlugins = json_decode( $userExtension->trusted_plugins, true );
		if ( is_array( $trustedPlugins ) && in_array( $slug, $trustedPlugins ) ) {
			return true;
		}
			return false;
	}

	/**
	 * Save the trusted plugin note.
	 */
	public static function save_trusted_plugin_note() {
		$slug                = urldecode( $_POST['slug'] );
		$note                = stripslashes( $_POST['note'] );
		$esc_note            = MainWP_Utility::esc_content( $note );
		$userExtension       = MainWP_DB_Common::instance()->get_user_extension();
		$trustedPluginsNotes = json_decode( $userExtension->trusted_plugins_notes, true );
		if ( ! is_array( $trustedPluginsNotes ) ) {
			$trustedPluginsNotes = array();
		}
		$trustedPluginsNotes[ $slug ]         = $esc_note;
		$userExtension->trusted_plugins_notes = wp_json_encode( $trustedPluginsNotes );
		MainWP_DB_Common::instance()->update_user_extension( $userExtension );
	}

}
