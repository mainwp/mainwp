<?php
/**
 * This File handles the Themes SubPage.
 * MainWP Themes Handler
 *
 * @package MainWP/Themes
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Themes_Handler
 *
 * @uses MainWP_Install_Bulk
 */
class MainWP_Themes_Handler {

	/**
	 * Get Class Name
	 *
	 * @return string __CLASS__
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Method themes_search_handler()
	 *
	 * Theme Search Handler.
	 *
	 * @param mixed  $data Search data.
	 * @param object $website The website object.
	 * @param object $output Search results object.
	 *
	 * @return mixed Exception|Theme
	 *
	 * @uses \MainWP\Dashboard\MainWP_Error_Helper::get_error_message()
	 * @uses \MainWP\Dashboard\MainWP_Exception
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_child_response()
	 */
	public static function themes_search_handler( $data, $website, &$output ) {
		if ( MainWP_Demo_Handle::get_instance()->is_demo_website( $website ) ) {
			return;
		}
		if ( 0 < preg_match( '/<mainwp>(.*)<\/mainwp>/', $data, $results ) ) {
			$result      = $results[1];
			$result_data = MainWP_System_Utility::get_child_response( base64_decode( $result ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
			unset( $results );
			if ( isset( $result_data['error'] ) ) {
				$output->errors[ $website->id ] = MainWP_Error_Helper::get_error_message( new MainWP_Exception( $result_data['error'], $website->url ) );
				return;
			}

			$themes = isset( $result_data['data'] ) && is_array( $result_data['data'] ) ? $result_data['data'] : array();

			$not_found = true;
			foreach ( $themes as $theme ) {
				if ( ! isset( $theme['name'] ) ) {
					continue;
				}
				$theme['websiteid']   = $website->id;
				$theme['websiteurl']  = $website->url;
				$theme['websitename'] = $website->name;

				$output->themes[] = $theme;
				$not_found        = false;
			}

			if ( 'not_installed' === $output->status ) {
				if ( $not_found ) {
					$installed = isset( $result_data['installed_themes'] ) && is_array( $result_data['installed_themes'] ) ? $result_data['installed_themes'] : array();
					foreach ( $installed as $theme ) {
						if ( ! isset( $theme['name'] ) ) {
							continue;
						}
						$theme['websiteid']         = $website->id;
						$theme['websiteurl']        = $website->url;
						$theme['websitename']       = $website->name;
						$output->themes_installed[] = $theme;
					}
				}
			}

			unset( $themes );
		} else {
			$output->errors[ $website->id ] = MainWP_Error_Helper::get_error_message( new MainWP_Exception( 'NOMAINWP', $website->url ) );
		}
	}

	/**
	 * Activate the selected theme.
	 */
	public static function activate_theme() {
		$theme = isset( $_POST['theme'] ) ? sanitize_text_field( wp_unslash( $_POST['theme'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		self::action( 'activate', $theme );
		die( 'SUCCESS' );
	}

	/**
	 * Delete the selected theme.
	 */
	public static function delete_themes() {
		$themes = isset( $_POST['themes'] ) ? wp_unslash( $_POST['themes'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		self::action( 'delete', implode( '||', $themes ) );
		die( 'SUCCESS' );
	}

	/**
	 * Checks to see if Theme exists, current user can edit settings, check for any errors.
	 *
	 * @param mixed $pAction Action to perform.
	 * @param mixed $theme   Theme to perform action on.
	 *
	 * @throws \Exception Error message.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
	 * @uses \MainWP\Dashboard\MainWP_Exception
	 * @uses \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
	 */
	public static function action( $pAction, $theme ) {
		$websiteId = isset( $_POST['websiteId'] ) ? intval( $_POST['websiteId'] ) : false; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( empty( $websiteId ) ) {
			die( 'FAIL' );
		}

		$website = MainWP_DB::instance()->get_website_by_id( $websiteId );
		if ( ! MainWP_System_Utility::can_edit_website( $website ) ) {
			die( 'FAIL' );
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

		/**
		* Action: mainwp_before_theme_action
		*
		* Fires before theme activate/delete actions.
		*
		* @since 4.1
		*/
		do_action( 'mainwp_before_theme_action', $pAction, $theme, $website );

		try {
			$information = MainWP_Connect::fetch_url_authed(
				$website,
				'theme_action',
				array(
					'action' => $pAction,
					'theme'  => $theme,
				)
			);
		} catch ( MainWP_Exception $e ) {
			die( 'FAIL' );
		}

		/**
		* Action: mainwp_after_theme_action
		*
		* Fires after theme activate/delete actions.
		*
		* @since 4.1
		*/
		do_action( 'mainwp_after_theme_action', $information, $pAction, $theme, $website );

		if ( isset( $information['error'] ) ) {
			if ( is_string( $information['error'] ) ) {
				$information['error'] = esc_html( $information['error'] );
			}
			wp_send_json( $information );
		}

		if ( ! isset( $information['status'] ) || ( 'SUCCESS' !== $information['status'] ) ) {
			die( 'FAIL' );
		}

		die( wp_json_encode( array( 'result' => true ) ) );
	}

	/**
	 * Check to see if Theme is on the Ignore List.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
	 * @uses \MainWP\Dashboard\MainWP_DB::update_website_values()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
	 * @uses \MainWP\Dashboard\MainWP_Utility::esc_content()
	 */
	public static function ignore_updates() {
		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$websiteId = isset( $_POST['websiteId'] ) ? intval( $_POST['websiteId'] ) : false;

		if ( empty( $websiteId ) ) {
			die( 'FAIL' );
		}

		$website = MainWP_DB::instance()->get_website_by_id( $websiteId );
		if ( ! MainWP_System_Utility::can_edit_website( $website ) ) {
			die( 'FAIL' );
		}

		$themes = isset( $_POST['themes'] ) ? wp_unslash( $_POST['themes'] ) : array();
		$names  = isset( $_POST['names'] ) ? wp_unslash( $_POST['names'] ) : array();
		// phpcs:enable

		$decodedIgnoredThemes = json_decode( $website->ignored_themes, true );
		if ( ! is_array( $decodedIgnoredThemes ) ) {
			$decodedIgnoredThemes = array();
		}

		if ( is_array( $themes ) ) {
			$_count = count( $themes );
			for ( $i = 0; $i < $_count; $i++ ) {
				$slug = $themes[ $i ];
				$name = $names[ $i ];
				if ( ! isset( $decodedIgnoredThemes[ $slug ] ) ) {
					$decodedIgnoredThemes[ $slug ] = urldecode( $name );
				}
			}

			/**
			* Action: mainwp_before_theme_ignore
			*
			* Fires before theme ignore.
			*
			* @since 4.1
			*/
			do_action( 'mainwp_before_theme_ignore', $website, $decodedIgnoredThemes );
			MainWP_DB::instance()->update_website_values( $website->id, array( 'ignored_themes' => wp_json_encode( $decodedIgnoredThemes ) ) );

			/**
			* Action: mainwp_after_theme_ignore
			*
			* Fires after theme ignore.
			*
			* @since 4.1
			*/
			do_action( 'mainwp_after_theme_ignore', $website, $decodedIgnoredThemes );
		}

		die( wp_json_encode( array( 'result' => true ) ) );
	}

	/**
	 * This is the Bulk Method to Trust A Theme.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::get_user_extension()
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::update_user_extension()
	 */
	public static function trust_post() {
		$userExtension = MainWP_DB_Common::instance()->get_user_extension();
		$trustedThemes = json_decode( $userExtension->trusted_themes, true );
		if ( ! is_array( $trustedThemes ) ) {
			$trustedThemes = array();
		}
		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$action = isset( $_POST['do'] ) ? sanitize_text_field( wp_unslash( $_POST['do'] ) ) : '';
		$slugs  = isset( $_POST['slugs'] ) && is_array( $_POST['slugs'] ) ? wp_unslash( $_POST['slugs'] ) : false;
		// phpcs:enable
		if ( ! is_array( $slugs ) ) {
			return;
		}
		if ( 'trust' !== $action && 'untrust' !== $action ) {
			return;
		}
		if ( 'trust' === $action ) {
			foreach ( $slugs as $slug ) {
				$idx = array_search( urldecode( $slug ), $trustedThemes );
				if ( false === $idx ) {
					$trustedThemes[] = urldecode( $slug );
				}
			}
		} elseif ( 'untrust' === $action ) {
			foreach ( $slugs as $slug ) {
				if ( in_array( urldecode( $slug ), $trustedThemes ) ) {
					$trustedThemes = array_diff( $trustedThemes, array( urldecode( $slug ) ) );
				}
			}
		}
		$userExtension->trusted_themes = wp_json_encode( $trustedThemes );
		MainWP_DB_Common::instance()->update_user_extension( $userExtension );
	}

	/** This Method Saves a Trusted theme note. */
	public static function save_trusted_theme_note() {
		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$slug = isset( $_POST['slug'] ) ? urldecode( sanitize_text_field( wp_unslash( $_POST['slug'] ) ) ) : '';
		$note = isset( $_POST['note'] ) ? wp_unslash( $_POST['note'] ) : '';
		// phpcs:enable
		$esc_note           = MainWP_Utility::esc_content( $note );
		$userExtension      = MainWP_DB_Common::instance()->get_user_extension();
		$trustedThemesNotes = json_decode( $userExtension->trusted_themes_notes, true );
		if ( ! is_array( $trustedThemesNotes ) ) {
			$trustedThemesNotes = array();
		}
		$trustedThemesNotes[ $slug ]         = $esc_note;
		$userExtension->trusted_themes_notes = wp_json_encode( $trustedThemesNotes );
		MainWP_DB_Common::instance()->update_user_extension( $userExtension );
	}
}
