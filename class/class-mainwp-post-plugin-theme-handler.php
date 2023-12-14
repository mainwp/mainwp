<?php
/**
 * Post Plugin Theme Handler.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Post_Plugin_Theme_Handler
 *
 * @package MainWP\Dashboard
 *
 * @uses \MainWP\Dashboard\MainWP_Post_Base_Handler
 */
class MainWP_Post_Plugin_Theme_Handler extends MainWP_Post_Base_Handler {

	/**
	 * Protected static variable to hold the single instance of the class.
	 *
	 * @var mixed Default null
	 */
	private static $instance = null;

	/**
	 * Method instance()
	 *
	 * Create public static instance.
	 *
	 * @static
	 * @return MainWP_Post_Plugin_Theme_Handler
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Init plugins/themes actions
	 */
	public function init() {
		// Page: ManageSites.
		$this->add_action( 'mainwp_ext_prepareinstallplugintheme', array( &$this, 'mainwp_ext_prepareinstallplugintheme' ) );
		$this->add_action( 'mainwp_ext_performinstallplugintheme', array( &$this, 'mainwp_ext_performinstallplugintheme' ) );

		// Page: InstallPlugins/Themes.
		$this->add_action( 'mainwp_preparebulkinstallplugintheme', array( &$this, 'mainwp_preparebulkinstallplugintheme' ) );
		$this->add_action( 'mainwp_installbulkinstallplugintheme', array( &$this, 'mainwp_installbulkinstallplugintheme' ) );
		$this->add_action( 'mainwp_preparebulkuploadplugintheme', array( &$this, 'mainwp_preparebulkuploadplugintheme' ) );
		$this->add_action( 'mainwp_installbulkuploadplugintheme', array( &$this, 'mainwp_installbulkuploadplugintheme' ) );
		$this->add_action( 'mainwp_cleanbulkuploadplugintheme', array( &$this, 'mainwp_cleanbulkuploadplugintheme' ) );
		$this->add_action( 'mainwp_preparebulkinstallcheckplugin', array( &$this, 'hook_prepare_installcheck_plugin' ) );

		// Widget: RightNow.
		$this->add_action( 'mainwp_upgradewp', array( &$this, 'mainwp_upgradewp' ) );
		$this->add_action( 'mainwp_upgradeplugintheme', array( &$this, 'mainwp_upgrade_plugintheme' ) );
		$this->add_action( 'mainwp_ignoreplugintheme', array( &$this, 'mainwp_ignoreplugintheme' ) );
		$this->add_action( 'mainwp_unignoreplugintheme', array( &$this, 'mainwp_unignoreplugintheme' ) );
		$this->add_action( 'mainwp_ignorepluginsthemes', array( &$this, 'mainwp_ignorepluginsthemes' ) );
		$this->add_action( 'mainwp_unignorepluginsthemes', array( &$this, 'mainwp_unignorepluginsthemes' ) );
		$this->add_action( 'mainwp_unignoreabandonedplugintheme', array( &$this, 'mainwp_unignoreabandonedplugintheme' ) );
		$this->add_action( 'mainwp_unignoreabandonedpluginsthemes', array( &$this, 'mainwp_unignoreabandonedpluginsthemes' ) );
		$this->add_action( 'mainwp_dismissoutdateplugintheme', array( &$this, 'mainwp_dismissoutdateplugintheme' ) );
		$this->add_action( 'mainwp_dismissoutdatepluginsthemes', array( &$this, 'mainwp_dismissoutdatepluginsthemes' ) );
		$this->add_action( 'mainwp_trust_plugin', array( &$this, 'mainwp_trust_plugin' ) );
		$this->add_action( 'mainwp_trust_theme', array( &$this, 'mainwp_trust_theme' ) );

		// Page: Themes.
		$this->add_action( 'mainwp_themes_search', array( &$this, 'mainwp_themes_search' ) );
		$this->add_action( 'mainwp_themes_search_all', array( &$this, 'mainwp_themes_search_all' ) );
		if ( mainwp_current_user_have_right( 'dashboard', 'activate_themes' ) ) {
			$this->add_action( 'mainwp_theme_activate', array( &$this, 'mainwp_theme_activate' ) );
		}
		if ( mainwp_current_user_have_right( 'dashboard', 'delete_themes' ) ) {
			$this->add_action( 'mainwp_theme_delete', array( &$this, 'mainwp_theme_delete' ) );
		}
		$this->add_action( 'mainwp_trusted_theme_notes_save', array( &$this, 'mainwp_trusted_theme_notes_save' ) );
		if ( mainwp_current_user_have_right( 'dashboard', 'ignore_unignore_updates' ) ) {
			$this->add_action( 'mainwp_theme_ignore_updates', array( &$this, 'mainwp_theme_ignore_updates' ) );
		}

		// Page: Plugins.
		$this->add_action( 'mainwp_plugins_search', array( &$this, 'mainwp_plugins_search' ) );
		$this->add_action( 'mainwp_plugins_search_all_active', array( &$this, 'mainwp_plugins_search_all_active' ) );

		if ( mainwp_current_user_have_right( 'dashboard', 'activate_deactivate_plugins' ) ) {
			$this->add_action( 'mainwp_plugin_activate', array( &$this, 'mainwp_plugin_activate' ) );
			$this->add_action( 'mainwp_plugin_deactivate', array( &$this, 'mainwp_plugin_deactivate' ) );
		}
		if ( mainwp_current_user_have_right( 'dashboard', 'delete_plugins' ) ) {
			$this->add_action( 'mainwp_plugin_delete', array( &$this, 'mainwp_plugin_delete' ) );
		}

		if ( mainwp_current_user_have_right( 'dashboard', 'ignore_unignore_updates' ) ) {
			$this->add_action( 'mainwp_plugin_ignore_updates', array( &$this, 'mainwp_plugin_ignore_updates' ) );
		}
		$this->add_action( 'mainwp_trusted_plugin_notes_save', array( &$this, 'mainwp_trusted_plugin_notes_save' ) );

		// Widget: Plugins.
		$this->add_action( 'mainwp_widget_plugin_activate', array( &$this, 'mainwp_widget_plugin_activate' ) );
		$this->add_action( 'mainwp_widget_plugin_deactivate', array( &$this, 'mainwp_widget_plugin_deactivate' ) );
		$this->add_action( 'mainwp_widget_plugin_delete', array( &$this, 'mainwp_widget_plugin_delete' ) );

		// Widget: Themes.
		$this->add_action( 'mainwp_widget_theme_activate', array( &$this, 'mainwp_widget_theme_activate' ) );
		$this->add_action( 'mainwp_widget_theme_delete', array( &$this, 'mainwp_widget_theme_delete' ) );
	}

	/**
	 * Method mainwp_themes_search()
	 *
	 * Search handler for Themes.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Cache::init_session()
	 *
	 * @uses \MainWP\Dashboard\MainWP_Themes::render_table()
	 */
	public function mainwp_themes_search() {
		$this->secure_request( 'mainwp_themes_search' );
		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$keyword = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';
		$status  = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
		$groups  = isset( $_POST['groups'] ) && is_array( $_POST['groups'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['groups'] ) ) : array();
		$sites   = isset( $_POST['sites'] ) && is_array( $_POST['sites'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['sites'] ) ) : array();
		$clients = isset( $_POST['clients'] ) && is_array( $_POST['clients'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['clients'] ) ) : array();

		$not_criteria = isset( $_POST['not_criteria'] ) && 'true' === $_POST['not_criteria'] ? true : false;
		// phpcs:enable
		MainWP_Cache::init_session();
		$result = MainWP_Themes::render_table( $keyword, $status, $groups, $sites, $not_criteria, $clients );
		wp_send_json( $result );
	}

	/**
	 * Method mainwp_theme_activate()
	 *
	 * Activate Theme,
	 * Page: Themes.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Themes_Handler::activate_theme()
	 */
	public function mainwp_theme_activate() {
		$this->secure_request( 'mainwp_theme_activate' );
		MainWP_Themes_Handler::activate_theme();
		die();
	}

	/**
	 * Method mainwp_theme_delete()
	 *
	 * Delete Theme,
	 * Page: Themes.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Themes_Handler::delete_themes()
	 */
	public function mainwp_theme_delete() {
		$this->secure_request( 'mainwp_theme_delete' );
		MainWP_Themes_Handler::delete_themes();
		die();
	}

	/**
	 * Method mainwp_theme_ignore_updates()
	 *
	 * Ignore theme updates,
	 * Page: Themes.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Themes_Handler::ignore_updates()
	 */
	public function mainwp_theme_ignore_updates() {
		$this->secure_request( 'mainwp_theme_ignore_updates' );
		MainWP_Themes_Handler::ignore_updates();
		die();
	}

	/**
	 * Method mainwp_themes_search_all()
	 *
	 * Search ALL handler for,
	 * Page: Themes.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Cache::init_session()
	 * @uses \MainWP\Dashboard\MainWP_Themes::render_all_themes_table()
	 */
	public function mainwp_themes_search_all() {
		$this->secure_request( 'mainwp_themes_search_all' );
		MainWP_Cache::init_session();
		MainWP_Themes::render_all_themes_table();
		die();
	}

	/**
	 * Method mainwp_trusted_theme_notes_save()
	 *
	 * Save trusted theme notes,
	 * Page: Themes.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Themes_Handler::save_trusted_theme_note()
	 */
	public function mainwp_trusted_theme_notes_save() {
		$this->secure_request( 'mainwp_trusted_theme_notes_save' );
		MainWP_Themes_Handler::save_trusted_theme_note();
		die( wp_json_encode( array( 'result' => 'SUCCESS' ) ) );
	}

	/**
	 * Method mainwp_plugins_search()
	 *
	 * Search handler for Plugins.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Cache::init_session()
	 * @uses \MainWP\Dashboard\MainWP_Plugins::render_table()
	 */
	public function mainwp_plugins_search() {
		$this->secure_request( 'mainwp_plugins_search' );
		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$keyword      = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';
		$status       = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
		$groups       = isset( $_POST['groups'] ) && is_array( $_POST['groups'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['groups'] ) ) : '';
		$sites        = isset( $_POST['sites'] ) && is_array( $_POST['sites'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['sites'] ) ) : '';
		$clients      = isset( $_POST['clients'] ) && is_array( $_POST['clients'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['clients'] ) ) : '';
		$not_criteria = isset( $_POST['not_criteria'] ) && 'true' === $_POST['not_criteria'] ? true : false;
		// phpcs:enable
		MainWP_Cache::init_session();
		$result = MainWP_Plugins::render_table( $keyword, $status, $groups, $sites, $not_criteria, $clients );
		wp_send_json( $result );
	}

	/**
	 * Method mainwp_plugins_search_all_active()
	 *
	 * Search all Active handler for,
	 * Page: Plugins.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Cache::init_session()
	 * @uses \MainWP\Dashboard\MainWP_Plugins::render_all_active_table()
	 */
	public function mainwp_plugins_search_all_active() {
		$this->secure_request( 'mainwp_plugins_search_all_active' );
		MainWP_Cache::init_session();
		MainWP_Plugins::render_all_active_table();
		die();
	}

	/**
	 * Method mainwp_plugin_activate()
	 *
	 * Activate plugins,
	 * Page: Plugins.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Plugins_Handler::activate_plugins()
	 */
	public function mainwp_plugin_activate() {
		$this->secure_request( 'mainwp_plugin_activate' );
		MainWP_Plugins_Handler::activate_plugins();
		die();
	}

	/**
	 * Method mainwp_plugin_deactivate()
	 *
	 * Deactivate plugins,
	 * Page: Plugins.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Plugins_Handler::deactivate_plugins()
	 */
	public function mainwp_plugin_deactivate() {
		$this->secure_request( 'mainwp_plugin_deactivate' );
		MainWP_Plugins_Handler::deactivate_plugins();
		die();
	}

	/**
	 * Method mainwp_plugin_delete()
	 *
	 * Delete plugins,
	 * Page: Plugins.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Plugins_Handler::delete_plugins()
	 */
	public function mainwp_plugin_delete() {
		$this->secure_request( 'mainwp_plugin_delete' );
		MainWP_Plugins_Handler::delete_plugins();
		die();
	}

	/**
	 * Method mainwp_plugin_ignore_updates()
	 *
	 * Ignore plugins updates,
	 * Page: Plugins.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Plugins_Handler::ignore_updates()
	 */
	public function mainwp_plugin_ignore_updates() {
		$this->secure_request( 'mainwp_plugin_ignore_updates' );
		MainWP_Plugins_Handler::ignore_updates();
		die();
	}

	/**
	 * Method mainwp_trusted_plugin_notes_save()
	 *
	 * Save trusted plugin notes,
	 * Page: Plugins.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Plugins_Handler::save_trusted_plugin_note()
	 */
	public function mainwp_trusted_plugin_notes_save() {
		$this->secure_request( 'mainwp_trusted_plugin_notes_save' );
		MainWP_Plugins_Handler::save_trusted_plugin_note();
		die( wp_json_encode( array( 'result' => 'SUCCESS' ) ) );
	}

	/**
	 * Method mainwp_widget_plugin_activate()
	 *
	 * Activate plugin,
	 * Widget: Plugins.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Widget_Plugins::activate_plugin()
	 */
	public function mainwp_widget_plugin_activate() {
		$this->secure_request( 'mainwp_widget_plugin_activate' );
		MainWP_Widget_Plugins::activate_plugin();
	}

	/**
	 * Method mainwp_widget_plugin_deactivate()
	 *
	 * Deactivate plugin,
	 * Widget: Plugins.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Widget_Plugins::deactivate_plugin()
	 */
	public function mainwp_widget_plugin_deactivate() {
		$this->secure_request( 'mainwp_widget_plugin_deactivate' );
		MainWP_Widget_Plugins::deactivate_plugin();
	}

	/**
	 * Method mainwp_widget_plugin_delete()
	 *
	 * Delete plugin,
	 * Widget: Plugins.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Widget_Plugins::delete_plugin()
	 */
	public function mainwp_widget_plugin_delete() {
		$this->secure_request( 'mainwp_widget_plugin_delete' );
		MainWP_Widget_Plugins::delete_plugin();
	}

	/**
	 * Method mainwp_widget_theme_activate()
	 *
	 * Activate theme,
	 * Widget: Themes.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Widget_Themes::activate_theme()
	 */
	public function mainwp_widget_theme_activate() {
		$this->secure_request( 'mainwp_widget_theme_activate' );
		MainWP_Widget_Themes::activate_theme();
	}

	/**
	 * Method mainwp_widget_theme_delete()
	 *
	 * Delete theme,
	 * Widget: Themes.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Widget_Themes::delete_theme()
	 */
	public function mainwp_widget_theme_delete() {
		$this->secure_request( 'mainwp_widget_theme_delete' );
		MainWP_Widget_Themes::delete_theme();
	}

	/**
	 * Method mainwp_preparebulkinstallplugintheme()
	 *
	 * Prepair bulk installation of plugins & themes,
	 * Page: InstallPlugins/Themes.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Install_Bulk::prepare_install()
	 */
	public function mainwp_preparebulkinstallplugintheme() {
		$this->secure_request( 'mainwp_preparebulkinstallplugintheme' );
		MainWP_Install_Bulk::prepare_install();
	}

	/**
	 * Method mainwp_installbulkinstallplugintheme()
	 *
	 * Installation of plugins & themes,
	 * Page: InstallPlugins/Themes.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Install_Bulk::perform_install()
	 */
	public function mainwp_installbulkinstallplugintheme() {
		$this->secure_request( 'mainwp_installbulkinstallplugintheme' );
		MainWP_Install_Bulk::perform_install();
	}

	/**
	 * Method mainwp_preparebulkuploadplugintheme()
	 *
	 * Prepair bulk upload of plugins & themes,
	 * Page: InstallPlugins/Themes.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Install_Bulk::prepare_upload()
	 */
	public function mainwp_preparebulkuploadplugintheme() {
		$this->secure_request( 'mainwp_preparebulkuploadplugintheme' );
		MainWP_Install_Bulk::prepare_upload();
	}

	/**
	 * Method mainwp_installbulkuploadplugintheme()
	 *
	 * Bulk upload of plugins & themes,
	 * Page: InstallPlugins/Themes.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Install_Bulk::perform_upload()
	 */
	public function mainwp_installbulkuploadplugintheme() {
		$this->secure_request( 'mainwp_installbulkuploadplugintheme' );
		MainWP_Install_Bulk::perform_upload();
	}

	/**
	 * Method mainwp_cleanbulkuploadplugintheme()
	 *
	 * Clean upload of plugins & themes,
	 * Page: InstallPlugins/Themes.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Install_Bulk::clean_upload()
	 */
	public function mainwp_cleanbulkuploadplugintheme() {
		$this->secure_request( 'mainwp_cleanbulkuploadplugintheme' );
		MainWP_Install_Bulk::clean_upload();
	}

	/**
	 * Method mainwp_ext_prepareinstallplugintheme()
	 *
	 * Prepair Installation of plugins & themes,
	 * Page: ManageSites.
	 */
	public function mainwp_ext_prepareinstallplugintheme() {
		do_action( 'mainwp_prepareinstallplugintheme' );
	}

	/**
	 * Method mainwp_ext_performinstallplugintheme()
	 *
	 * Installation of plugins & themes,
	 * Page: ManageSites.
	 */
	public function mainwp_ext_performinstallplugintheme() {
		$this->secure_request( 'mainwp_ext_performinstallplugintheme' );
		do_action( 'mainwp_performinstallplugintheme' );
	}

	/**
	 * Method hook_prepare_installcheck_plugin()
	 *
	 * Prepair bulk installation of plugins,
	 */
	public function hook_prepare_installcheck_plugin() {
		$this->secure_request( 'mainwp_preparebulkinstallcheckplugin' );
		include_once ABSPATH . '/wp-admin/includes/plugin-install.php';
		$api           = MainWP_System_Utility::get_plugin_theme_info(
			'plugin',
			array(
				'slug'   => isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				'fields' => array( 'sections' => false ),
			)
		); // Save on a bit of bandwidth.
		$url           = $api->download_link;
		$output        = array();
		$output['url'] = $url;
		wp_send_json( $output );
	}

	/**
	 * Method mainwp_upgradewp()
	 *
	 * Update a specific WP core.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Exception
	 * @uses \MainWP\Dashboard\MainWP_Updates_Handler::upgrade_site()
	 */
	public function mainwp_upgradewp() {
		if ( ! mainwp_current_user_have_right( 'dashboard', 'update_wordpress' ) ) {
			die( wp_json_encode( array( 'error' => mainwp_do_not_have_permissions( esc_html__( 'update WordPress', 'mainwp' ), $echo = false ) ) ) );
		}

		$this->secure_request( 'mainwp_upgradewp' );

		try {
			$id = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : false; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			die( wp_json_encode( array( 'result' => MainWP_Updates_Handler::upgrade_site( $id ) ) ) ); // ok.
		} catch ( MainWP_Exception $e ) {
			die(
				wp_json_encode(
					array(
						'error' => array(
							'message' => $e->getMessage(),
							'extra'   => $e->get_message_extra(),
						),
					)
				)
			);
		}
	}

	/**
	 * Method mainwp_upgrade_plugintheme()
	 *
	 * Update plugin or theme.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB_Backup::backup_full_task_running()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
	 * @uses \MainWP\Dashboard\MainWP_Exception
	 * @uses \MainWP\Dashboard\MainWP_Updates_Handler::get_plugin_theme_slugs()
	 * @uses \MainWP\Dashboard\MainWP_Updates_Handler::upgrade_plugin_theme_translation()
	 */
	public function mainwp_upgrade_plugintheme() { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! isset( $_POST['type'] ) ) {
			die( wp_json_encode( array( 'error' => '<i class="red times icon"></i> ' . esc_html__( 'Plugin or theme not specified. Please reload the page and try again.', 'mainwp' ) ) ) );
		}

		if ( 'plugin' === $_POST['type'] && ! mainwp_current_user_have_right( 'dashboard', 'update_plugins' ) ) {
			die( wp_json_encode( array( 'error' => mainwp_do_not_have_permissions( esc_html__( 'update plugins', 'mainwp' ), $echo = false ) ) ) );
		}

		if ( 'theme' === $_POST['type'] && ! mainwp_current_user_have_right( 'dashboard', 'update_themes' ) ) {
			die( wp_json_encode( array( 'error' => mainwp_do_not_have_permissions( esc_html__( 'update themes', 'mainwp' ), $echo = false ) ) ) );
		}

		if ( 'translation' === $_POST['type'] && ! mainwp_current_user_have_right( 'dashboard', 'update_translations' ) ) {
			die( wp_json_encode( array( 'error' => mainwp_do_not_have_permissions( esc_html__( 'update translations', 'mainwp' ), $echo = false ) ) ) );
		}

		$this->secure_request( 'mainwp_upgradeplugintheme' );

		// support chunk update for manage sites page only.
		$chunk_support = ! empty( $_POST['chunk_support'] ) ? true : false;
		$max_update    = 0;
		$websiteId     = null;
		$slugs         = '';

		if ( isset( $_POST['websiteId'] ) ) {
			$websiteId = intval( $_POST['websiteId'] );

			if ( $chunk_support ) {
				/**
				 * Filter: mainwp_update_plugintheme_max
				 *
				 * Filters the max number of plugins/themes to be updated in one run in order to improve performance.
				 *
				 * @param int $websiteId Child site ID.
				 *
				 * @since Unknown
				 */
				$max_update = apply_filters( 'mainwp_update_plugintheme_max', false, $websiteId );
				if ( empty( $max_update ) ) {
					$chunk_support = false; // there is no hook so disable chunk update support.
				}
			}
			if ( $chunk_support ) {
				if ( isset( $_POST['chunk_slugs'] ) ) {
					$slugs = wp_unslash( $_POST['chunk_slugs'] );
				} else {
					$slugs = MainWP_Updates_Handler::get_plugin_theme_slugs( $websiteId, sanitize_text_field( wp_unslash( $_POST['type'] ) ) );
				}
			} elseif ( isset( $_POST['slug'] ) ) {
				$slugs = wp_unslash( $_POST['slug'] );
			} else {
				$slugs = MainWP_Updates_Handler::get_plugin_theme_slugs( $websiteId, sanitize_text_field( wp_unslash( $_POST['type'] ) ) );
			}
		}
		// phpcs:enable

		if ( MainWP_DB_Backup::instance()->backup_full_task_running( $websiteId ) ) {
			die( wp_json_encode( array( 'error' => esc_html__( 'Backup process in progress on the child site. Please, try again later.', 'mainwp' ) ) ) );
		}

		$chunk_slugs = array();

		if ( $chunk_support ) {
			// calculate update slugs here.
			if ( $max_update ) {
				$slugs        = explode( ',', $slugs );
				$chunk_slugs  = array_slice( $slugs, $max_update );
				$update_slugs = array_diff( $slugs, $chunk_slugs );
				$slugs        = implode( ',', $update_slugs );
			}
		}

		if ( empty( $slugs ) && ! $chunk_support ) {
			die( wp_json_encode( array( 'message' => esc_html__( 'Item slug could not be found. Update process could not be executed.', 'mainwp' ) ) ) );
		}
		$website = MainWP_DB::instance()->get_website_by_id( $websiteId );
		try {
			$info = array(
				'result'       => array(),
				'result_error' => array(),
			);

			$result = MainWP_Updates_Handler::upgrade_plugin_theme_translation( $websiteId, sanitize_text_field( wp_unslash( $_POST['type'] ) ), $slugs ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			if ( is_array( $result ) ) {
				if ( isset( $result['result'] ) ) {
					$info['result'] = $result['result'];
				}
				if ( isset( $result['result_error'] ) ) {
					$info['result_error'] = $result['result_error'];
				}
			}

			if ( $chunk_support && ( 0 < count( $chunk_slugs ) ) ) {
				$info['chunk_slugs'] = implode( ',', $chunk_slugs );
			}

			if ( ! empty( $website ) ) {
				$info['site_url'] = esc_url( $website->url );
			}
			wp_send_json( $info );
		} catch ( MainWP_Exception $e ) {
			die(
				wp_json_encode(
					array(
						'error' => array(
							'message'   => $e->getMessage(),
							'extra'     => $e->get_message_extra(),
							'errorCode' => $e->get_message_error_code(),
						),
					)
				)
			);
		}
	}

	/**
	 * Method mainwp_ignoreplugintheme()
	 *
	 * Ignores a plugin or a theme.
	 */
	public function mainwp_ignoreplugintheme() {
		$this->secure_request( 'mainwp_ignoreplugintheme' );

		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! isset( $_POST['id'] ) ) {
			die( wp_json_encode( array( 'error' => esc_html__( 'Item ID not found. Please reload the page and try again.', 'mainwp' ) ) ) );
		}
		$type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
		$slug = isset( $_POST['slug'] ) ? esc_html( wp_unslash( $_POST['slug'] ) ) : '';
		$id   = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		// phpcs:enable
		wp_send_json( array( 'result' => MainWP_Updates_Handler::ignore_plugin_theme( $type, $slug, $name, $id ) ) );
	}

	/**
	 * Method mainwp_unignoreabandonedplugintheme()
	 *
	 * Unignore abandoned plugin or theme.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Updates_Handler::ignore_plugin_theme()
	 * @uses \MainWP\Dashboard\MainWP_Updates_Handler::unignore_abandoned_plugin_theme()
	 */
	public function mainwp_unignoreabandonedplugintheme() {
		$this->secure_request( 'mainwp_unignoreabandonedplugintheme' );

		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! isset( $_POST['id'] ) ) {
			die( wp_json_encode( array( 'error' => esc_html__( 'Item ID not found. Please reload the page and try again.', 'mainwp' ) ) ) );
		}

		$type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
		$slug = isset( $_POST['slug'] ) ? esc_html( wp_unslash( $_POST['slug'] ) ) : '';
		$id   = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : 0; // string|int.
		// phpcs:enable
		die( wp_json_encode( array( 'result' => MainWP_Updates_Handler::unignore_abandoned_plugin_theme( $type, $slug, $id ) ) ) ); // ok.
	}

	/**
	 * Method mainwp_unignoreabandonedpluginthemes()
	 *
	 * Unignore abandoned plugins or themes.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Updates_Handler::unignore_abandoned_plugins_themes()
	 */
	public function mainwp_unignoreabandonedpluginsthemes() {
		$this->secure_request( 'mainwp_unignoreabandonedpluginsthemes' );

		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! isset( $_POST['slug'] ) ) {
			die( wp_json_encode( array( 'error' => esc_html__( 'Item slug not found. Please reload the page and try again.', 'mainwp' ) ) ) );
		}

		$type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
		$slug = isset( $_POST['slug'] ) ? esc_html( wp_unslash( $_POST['slug'] ) ) : '';
		// phpcs:enable
		die( wp_json_encode( array( 'result' => MainWP_Updates_Handler::unignore_abandoned_plugins_themes( $type, $slug ) ) ) );
	}

	/**
	 * Method mainwp_dismissoutdateplugintheme()
	 *
	 * Dismiss outdated plugin or theme.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Updates_Handler::dismiss_plugin_theme()
	 */
	public function mainwp_dismissoutdateplugintheme() {
		$this->secure_request( 'mainwp_dismissoutdateplugintheme' );

		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! isset( $_POST['id'] ) ) {
			die( wp_json_encode( array( 'error' => esc_html__( 'Item ID not found. Please reload the page and try again.', 'mainwp' ) ) ) );
		}
		$type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
		$slug = isset( $_POST['slug'] ) ? esc_html( wp_unslash( $_POST['slug'] ) ) : '';
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$id   = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
		// phpcs:enable
		die( wp_json_encode( array( 'result' => MainWP_Updates_Handler::dismiss_plugin_theme( $type, $slug, $name, $id ) ) ) );
	}

	/**
	 * Method mainwp_dismissoutdatepluginthemes()
	 *
	 * Dismiss outdated plugins or themes.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Updates_Handler::dismiss_plugins_themes()
	 */
	public function mainwp_dismissoutdatepluginsthemes() {
		$this->secure_request( 'mainwp_dismissoutdatepluginsthemes' );

		if ( ! mainwp_current_user_have_right( 'dashboard', 'ignore_unignore_updates' ) ) {
			die( wp_json_encode( array( 'error' => mainwp_do_not_have_permissions( esc_html__( 'ignore/unignore updates', 'mainwp' ) ) ) ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! isset( $_POST['slug'] ) ) {
			die( wp_json_encode( array( 'error' => esc_html__( 'Item slug not found. Please reload the page and try again.', 'mainwp' ) ) ) );
		}

		$type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
		$slug = isset( $_POST['slug'] ) ? esc_html( wp_unslash( $_POST['slug'] ) ) : '';
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		// phpcs:enable

		die( wp_json_encode( array( 'result' => MainWP_Updates_Handler::dismiss_plugins_themes( $type, $slug, $name ) ) ) );
	}

	/**
	 * Method mainwp_unignoreplugintheme()
	 *
	 * Unignore plugin or theme.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Updates_Handler::unignore_plugin_theme()
	 */
	public function mainwp_unignoreplugintheme() {
		$this->secure_request( 'mainwp_unignoreplugintheme' );

		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! isset( $_POST['id'] ) ) {
			die( wp_json_encode( array( 'error' => esc_html__( 'Item ID not found. Please reload the page and try again.', 'mainwp' ) ) ) );
		}
		$type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
		$slug = isset( $_POST['slug'] ) ? wp_unslash( $_POST['slug'] ) : '';
		$id   = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		// phpcs:enable
		die( wp_json_encode( array( 'result' => MainWP_Updates_Handler::unignore_plugin_theme( $type, $slug, $id ) ) ) );
	}

	/**
	 * Method mainwp_ignorepluginthemes()
	 *
	 * Ignore plugins or themes.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Updates_Handler::ignore_plugins_themes()
	 */
	public function mainwp_ignorepluginsthemes() {
		$this->secure_request( 'mainwp_ignorepluginsthemes' );

		if ( ! mainwp_current_user_have_right( 'dashboard', 'ignore_unignore_updates' ) ) {
			die( wp_json_encode( array( 'error' => mainwp_do_not_have_permissions( esc_html__( 'ignore/unignore updates', 'mainwp' ) ) ) ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! isset( $_POST['slug'] ) ) {
			die( wp_json_encode( array( 'error' => esc_html__( 'Item slug not found. Please reload the page and try again.', 'mainwp' ) ) ) );
		}

		$type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
		$slug = isset( $_POST['slug'] ) ? esc_html( wp_unslash( $_POST['slug'] ) ) : '';
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		// phpcs:enable
		die( wp_json_encode( array( 'result' => MainWP_Updates_Handler::ignore_plugins_themes( $type, $slug, $name ) ) ) );
	}

	/**
	 * Method mainwp_unignorepluginthemes()
	 *
	 * Unignore plugins or themes.
	 */
	public function mainwp_unignorepluginsthemes() {
		$this->secure_request( 'mainwp_unignorepluginsthemes' );

		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! isset( $_POST['slug'] ) ) {
			die( wp_json_encode( array( 'error' => esc_html__( 'Item slug not found. Please reload the page and try again.', 'mainwp' ) ) ) );
		}
		$type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
		$slug = isset( $_POST['slug'] ) ? wp_unslash( $_POST['slug'] ) : '';
		// phpcs:enable
		die( wp_json_encode( array( 'result' => MainWP_Updates_Handler::unignore_plugins_themes( $type, $slug ) ) ) );
	}

	/**
	 * Method mainwp_trust_plugin()
	 *
	 * Trust plugin.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Plugins_Handler::trust_post()
	 * @uses \MainWP\Dashboard\MainWP_Themes_Handler::trust_post()
	 */
	public function mainwp_trust_plugin() {
		$this->secure_request( 'mainwp_trust_plugin' );

		MainWP_Plugins_Handler::trust_post();
		die( wp_json_encode( array( 'result' => true ) ) );
	}

	/**
	 * Method mainwp_trust_theme()
	 *
	 * Trust theme.
	 */
	public function mainwp_trust_theme() {
		$this->secure_request( 'mainwp_trust_theme' );

		MainWP_Themes_Handler::trust_post();
		die( wp_json_encode( array( 'result' => true ) ) );
	}
}
