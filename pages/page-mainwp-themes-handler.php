<?php
/**
 * This File handles the Themes SubPage.
 * MainWP Themes Handler
 *
 * @package MainWP/Themes
 */

namespace MainWP\Dashboard;

/**
 * MainWP Themes Handler
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
	 */
	public static function themes_search_handler( $data, $website, &$output ) {
		if ( 0 < preg_match( '/<mainwp>(.*)<\/mainwp>/', $data, $results ) ) {
			$result = $results[1];
			$themes = MainWP_System_Utility::get_child_response( base64_decode( $result ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
			unset( $results );
			if ( isset( $themes['error'] ) ) {
				$output->errors[ $website->id ] = MainWP_Error_Helper::get_error_message( new MainWP_Exception( $themes['error'], $website->url ) );

				return;
			}

			foreach ( $themes as $theme ) {
				if ( ! isset( $theme['name'] ) ) {
					continue;
				}
				$theme['websiteid']  = $website->id;
				$theme['websiteurl'] = $website->url;

				$output->themes[] = $theme;
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
		self::action( 'activate', $_POST['theme'] );
		die( 'SUCCESS' );
	}

	/**
	 * Delete the selected theme.
	 */
	public static function delete_themes() {
		self::action( 'delete', implode( '||', $_POST['themes'] ) );
		die( 'SUCCESS' );
	}

	/**
	 * Checks to see if Theme exists, current user can edit settings, check for any errors.
	 *
	 * @param mixed $pAction Action to perform.
	 * @param mixed $theme Theme to perform action on.
	 */
	public static function action( $pAction, $theme ) {
		$websiteIdEnc = $_POST['websiteId'];

		$websiteId = $websiteIdEnc;
		if ( ! MainWP_Utility::ctype_digit( $websiteId ) ) {
			die( 'FAIL' );
		}

		$website = MainWP_DB::instance()->get_website_by_id( $websiteId );
		if ( ! MainWP_System_Utility::can_edit_website( $website ) ) {
			die( 'FAIL' );
		}

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

		if ( isset( $information['error'] ) ) {
			wp_send_json( $information );
		}

		if ( ! isset( $information['status'] ) || ( 'SUCCESS' !== $information['status'] ) ) {
			die( 'FAIL' );
		}

		die( wp_json_encode( array( 'result' => true ) ) );
	}

	/**
	 * Check to see if Theme is on the Ignore List.
	 */
	public static function ignore_updates() {
		$websiteIdEnc = $_POST['websiteId'];

		$websiteId = $websiteIdEnc;
		if ( ! MainWP_Utility::ctype_digit( $websiteId ) ) {
			die( 'FAIL' );
		}

		$website = MainWP_DB::instance()->get_website_by_id( $websiteId );
		if ( ! MainWP_System_Utility::can_edit_website( $website ) ) {
			die( 'FAIL' );
		}

		$themes = $_POST['themes'];
		$names  = $_POST['names'];

		$decodedIgnoredThemes = json_decode( $website->ignored_themes, true );
		if ( ! is_array( $decodedIgnoredThemes ) ) {
			$decodedIgnoredThemes = array();
		}

		if ( is_array( $themes ) ) {
			$_count = count( $themes );
			for ( $i = 0; $i < $_count; $i ++ ) {
				$slug = $themes[ $i ];
				$name = $names[ $i ];
				if ( ! isset( $decodedIgnoredThemes[ $slug ] ) ) {
					$decodedIgnoredThemes[ $slug ] = urldecode( $name );
				}
			}
			MainWP_DB::instance()->update_website_values( $website->id, array( 'ignored_themes' => wp_json_encode( $decodedIgnoredThemes ) ) );
		}

		die( wp_json_encode( array( 'result' => true ) ) );
	}

	/** This is the Bulk Method to Trust A Theme. */
	public static function trust_post() {
		$userExtension = MainWP_DB_Common::instance()->get_user_extension();
		$trustedThemes = json_decode( $userExtension->trusted_themes, true );
		if ( ! is_array( $trustedThemes ) ) {
			$trustedThemes = array();
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
		$slug               = urldecode( $_POST['slug'] );
		$note               = stripslashes( $_POST['note'] );
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
