<?php
/**
 * MainWP Plugins Page Handler.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 *  Class MainWP MainWP_Plugins_Handler
 *
 * @uses MainWP_Install_Bulk()
 */
class MainWP_Plugins_Handler {
	// phpcs:disable Generic.Metrics.CyclomaticComplexity -- This is the only way to achieve desired results, pull request solutions appreciated.

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
	 * @return mixed error|array.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Error_Helper::get_error_message()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_child_response()
	 * @uses \MainWP\Dashboard\MainWP_Exception
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_child_response()
	 */
	public static function plugins_search_handler( $data, $website, &$output ) {
		if ( MainWP_Demo_Handle::get_instance()->is_demo_website( $website ) ) {
			return;
		}
		if ( 0 < preg_match( '/<mainwp>(.*)<\/mainwp>/', $data, $results ) ) {
			$result      = $results[1];
			$result_data = MainWP_System_Utility::get_child_response( base64_decode( $result ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
			unset( $results );
			if ( isset( $result_data['error'] ) ) {
				$output->errors[ $website->id ] = MainWP_Error_Helper::get_error_message( new MainWP_Exception( $result_data['error'], $website->url ) ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				return;
			}

			$plugins = isset( $result_data['data'] ) && is_array( $result_data['data'] ) ? $result_data['data'] : array();

			$not_found = true;

			foreach ( $plugins as $plugin ) {
				if ( ! isset( $plugin['name'] ) ) {
					continue;
				}
				$plugin['websiteid']   = $website->id;
				$plugin['websiteurl']  = $website->url;
				$plugin['websitename'] = $website->name;

				$output->plugins[] = $plugin;
				$not_found         = false;
			}

			if ( 'not_installed' === $output->status ) {
				if ( $not_found ) {
					$installed = isset( $result_data['installed_plugins'] ) && is_array( $result_data['installed_plugins'] ) ? $result_data['installed_plugins'] : array();
					foreach ( $installed as $plugin ) {
						if ( ! isset( $plugin['name'] ) ) {
							continue;
						}
						$plugin['websiteid']         = $website->id;
						$plugin['websiteurl']        = $website->url;
						$plugin['websitename']       = $website->name;
						$output->plugins_installed[] = $plugin;
					}
				}
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

	/**
	 * Ignore Plugin.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
	 * @uses \MainWP\Dashboard\MainWP_DB::update_website_values()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
	 */
	public static function ignore_updates() {
		// phpcs:disable WordPress.Security.NonceVerification
		$websiteId = isset( $_POST['websiteId'] ) ? intval( $_POST['websiteId'] ) : false;

		if ( empty( $websiteId ) ) {
			die( wp_json_encode( array( 'error' => esc_html__( 'Site ID not found. Please reload the page and try again.', 'mainwp' ) ) ) );
		}

		$website = MainWP_DB::instance()->get_website_by_id( $websiteId );

		if ( ! MainWP_System_Utility::can_edit_website( $website ) ) {
			die( wp_json_encode( array( 'error' => esc_html__( 'You are not allowed to edit this website.', 'mainwp' ) ) ) );
		}

		$plugins = isset( $_POST['plugins'] ) ? wp_unslash( $_POST['plugins'] ) : false; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$names   = isset( $_POST['names'] ) ? wp_unslash( $_POST['names'] ) : array();  //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable

		$decodedIgnoredPlugins = json_decode( $website->ignored_plugins, true );

		if ( ! is_array( $decodedIgnoredPlugins ) ) {
			$decodedIgnoredPlugins = array();
		}

		if ( is_array( $plugins ) ) {
			$_count = count( $plugins );
			for ( $i = 0; $i < $_count; $i++ ) {
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
	 *
	 * @uses \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
	 * @uses \MainWP\Dashboard\MainWP_Error_Helper::get_error_message()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
	 * @uses \MainWP\Dashboard\MainWP_Exception
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
	 */
	public static function action( $pAction ) {
		// phpcs:disable WordPress.Security.NonceVerification
		$websiteId = isset( $_POST['websiteId'] ) ? intval( $_POST['websiteId'] ) : false;

		if ( empty( $websiteId ) ) {
			die( wp_json_encode( array( 'error' => esc_html__( 'Site ID not found. Please reload the page and try again.', 'mainwp' ) ) ) );
		}

		$website = MainWP_DB::instance()->get_website_by_id( $websiteId );

		if ( ! MainWP_System_Utility::can_edit_website( $website ) ) {
			die( wp_json_encode( array( 'error' => esc_html__( 'You are not allowed to edit this website.', 'mainwp' ) ) ) );
		}

		if ( MainWP_System_Utility::is_suspended_site( $website ) ) {
			die(
				wp_json_encode(
					array(
						'error'     => esc_html__( 'Suspended site.', 'mainwp' ),
						'errorCode' => 'SUSPENDED_SITE',
					)
				)
			);
		}

		try {
			$plugins = isset( $_POST['plugins'] ) ? wp_unslash( $_POST['plugins'] ) : array(); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$plugin  = implode( '||', $plugins );
			$plugin  = urldecode( $plugin );

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

		// phpcs:enable

		if ( ! isset( $information['status'] ) || ( 'SUCCESS' !== $information['status'] ) ) {
			die( wp_json_encode( array( 'error' => esc_html__( 'Unexpected error. Please try again.', 'mainwp' ) ) ) );
		}

		die( wp_json_encode( array( 'result' => true ) ) );
	}

	/**
	 * Trust Plugin $_POST.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::get_user_extension()
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::update_user_extension()
	 */
	public static function trust_post() {
		$userExtension  = MainWP_DB_Common::instance()->get_user_extension();
		$trustedPlugins = json_decode( $userExtension->trusted_plugins, true );
		if ( ! is_array( $trustedPlugins ) ) {
			$trustedPlugins = array();
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$action = isset( $_POST['do'] ) ? sanitize_text_field( wp_unslash( $_POST['do'] ) ) : '';
		$slugs  = isset( $_POST['slugs'] ) && is_array( $_POST['slugs'] ) ? wp_unslash( $_POST['slugs'] ) : ''; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable
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
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::get_user_extension()
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::update_user_extension()
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
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::get_user_extension()
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::update_user_extension()
	 */
	public static function check_auto_update_plugin( $slug ) {
		if ( 1 !== (int) get_option( 'mainwp_pluginAutomaticDailyUpdate' ) ) {
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
	 *
	 * @uses  \MainWP\Dashboard\MainWP_Utility::esc_content()
	 */
	public static function save_trusted_plugin_note() {
		// phpcs:disable WordPress.Security.NonceVerification
		$slug = isset( $_POST['slug'] ) ? urldecode( wp_unslash( $_POST['slug'] ) ) : ''; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$note = isset( $_POST['note'] ) ? wp_unslash( $_POST['note'] ) : ''; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable
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
