<?php
/**
 * Post Plugin Theme Handler.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * MainWP Post Plugin Theme Handler
 */
class MainWP_Post_Plugin_Theme_Handler extends MainWP_Post_Base_Handler {

	// Singleton.
	/** @var $instance MainWP_Post_Plugin_Theme_Handler */
	private static $instance = null;

	/**
	 * @static
	 * @return MainWP_Post_Plugin_Theme_Handler
	 */
	public static function instance() {
		if ( null == self::$instance ) {
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
	 * Page: Themes
	 */
	public function mainwp_themes_search() {
		$this->secure_request( 'mainwp_themes_search' );
		MainWP_Cache::init_session();
		$result = MainWP_Themes::render_table( $_POST['keyword'], $_POST['status'], ( isset( $_POST['groups'] ) ? $_POST['groups'] : '' ), ( isset( $_POST['sites'] ) ? $_POST['sites'] : '' ) );
		wp_send_json( $result );
	}

	public function mainwp_theme_activate() {
		$this->secure_request( 'mainwp_theme_activate' );

		MainWP_Themes_Handler::activate_theme();
		die();
	}

	public function mainwp_theme_delete() {
		$this->secure_request( 'mainwp_theme_delete' );

		MainWP_Themes_Handler::delete_themes();
		die();
	}

	public function mainwp_theme_ignore_updates() {
		$this->secure_request( 'mainwp_theme_ignore_updates' );

		MainWP_Themes_Handler::ignore_updates();
		die();
	}

	public function mainwp_themes_search_all() {
		$this->secure_request( 'mainwp_themes_search_all' );
		MainWP_Cache::init_session();
		MainWP_Themes::render_all_themes_table();
		die();
	}

	public function mainwp_trusted_theme_notes_save() {
		$this->secure_request( 'mainwp_trusted_theme_notes_save' );

		MainWP_Themes_Handler::save_trusted_theme_note();
		die( wp_json_encode( array( 'result' => 'SUCCESS' ) ) );
	}

	/**
	 * Page: Plugins
	 */
	public function mainwp_plugins_search() {
		$this->secure_request( 'mainwp_plugins_search' );
		MainWP_Cache::init_session();
		$result = MainWP_Plugins::render_table( $_POST['keyword'], $_POST['status'], ( isset( $_POST['groups'] ) ? $_POST['groups'] : '' ), ( isset( $_POST['sites'] ) ? $_POST['sites'] : '' ) );
		wp_send_json( $result );
	}

	public function mainwp_plugins_search_all_active() {
		$this->secure_request( 'mainwp_plugins_search_all_active' );
		MainWP_Cache::init_session();
		MainWP_Plugins::render_all_active_table();
		die();
	}

	public function mainwp_plugin_activate() {
		$this->secure_request( 'mainwp_plugin_activate' );

		MainWP_Plugins_Handler::activate_plugins();
		die();
	}

	public function mainwp_plugin_deactivate() {
		$this->secure_request( 'mainwp_plugin_deactivate' );

		MainWP_Plugins_Handler::deactivate_plugins();
		die();
	}

	public function mainwp_plugin_delete() {
		$this->secure_request( 'mainwp_plugin_delete' );

		MainWP_Plugins_Handler::delete_plugins();
		die();
	}

	public function mainwp_plugin_ignore_updates() {
		$this->secure_request( 'mainwp_plugin_ignore_updates' );

		MainWP_Plugins_Handler::ignore_updates();
		die();
	}

	public function mainwp_trusted_plugin_notes_save() {
		$this->secure_request( 'mainwp_trusted_plugin_notes_save' );

		MainWP_Plugins_Handler::save_trusted_plugin_note();
		die( wp_json_encode( array( 'result' => 'SUCCESS' ) ) );
	}

	/**
	 * Widget: Plugins
	 */
	public function mainwp_widget_plugin_activate() {
		$this->secure_request( 'mainwp_widget_plugin_activate' );
		MainWP_Widget_Plugins::activate_plugin();
	}

	public function mainwp_widget_plugin_deactivate() {
		$this->secure_request( 'mainwp_widget_plugin_deactivate' );
		MainWP_Widget_Plugins::deactivate_plugin();
	}

	public function mainwp_widget_plugin_delete() {
		$this->secure_request( 'mainwp_widget_plugin_delete' );
		MainWP_Widget_Plugins::delete_plugin();
	}

	/**
	 * Widget: Themes
	 */
	public function mainwp_widget_theme_activate() {
		$this->secure_request( 'mainwp_widget_theme_activate' );
		MainWP_Widget_Themes::activate_theme();
	}

	public function mainwp_widget_theme_delete() {
		$this->secure_request( 'mainwp_widget_theme_delete' );
		MainWP_Widget_Themes::delete_theme();
	}

	/*
	 * Page: InstallPlugins/Themes
	 */

	public function mainwp_preparebulkinstallplugintheme() {
		$this->secure_request( 'mainwp_preparebulkinstallplugintheme' );

		MainWP_Install_Bulk::prepare_install();
	}

	public function mainwp_installbulkinstallplugintheme() {
		$this->secure_request( 'mainwp_installbulkinstallplugintheme' );

		MainWP_Install_Bulk::perform_install();
	}

	public function mainwp_preparebulkuploadplugintheme() {
		$this->secure_request( 'mainwp_preparebulkuploadplugintheme' );

		MainWP_Install_Bulk::prepare_upload();
	}

	public function mainwp_installbulkuploadplugintheme() {
		$this->secure_request( 'mainwp_installbulkuploadplugintheme' );

		MainWP_Install_Bulk::perform_upload();
	}

	public function mainwp_cleanbulkuploadplugintheme() {
		$this->secure_request( 'mainwp_cleanbulkuploadplugintheme' );

		MainWP_Install_Bulk::clean_upload();
	}

	/*
	 * Page: ManageSites
	 */

	public function mainwp_ext_prepareinstallplugintheme() {

		do_action( 'mainwp_prepareinstallplugintheme' );
	}

	public function mainwp_ext_performinstallplugintheme() {

		do_action( 'mainwp_performinstallplugintheme' );
	}

	/*
	 * Widget: RightNow
	 */

	// Update a specific WP.
	public function mainwp_upgradewp() {
		if ( ! mainwp_current_user_have_right( 'dashboard', 'update_wordpress' ) ) {
			die( wp_json_encode( array( 'error' => mainwp_do_not_have_permissions( __( 'update WordPress', 'mainwp' ), $echo = false ) ) ) );
		}

		$this->secure_request( 'mainwp_upgradewp' );

		try {
			$id = null;
			if ( isset( $_POST['id'] ) ) {
				$id = $_POST['id'];
			}
			die( wp_json_encode( array( 'result' => MainWP_Updates_Handler::upgrade_site( $id ) ) ) ); // ok.
		} catch ( MainWP_Exception $e ) {
			die(
				wp_json_encode(
					array(
						'error' => array(
							'message'    => $e->getMessage(),
							'extra'      => $e->get_message_extra(),
						),
					)
				)
			);
		}
	}


	public function mainwp_upgrade_plugintheme() { // phpcs:ignore -- not quite complex method.

		if ( ! isset( $_POST['type'] ) ) {
			die( wp_json_encode( array( 'error' => '<i class="red times icon"></i> ' . __( 'Invalid request', 'mainwp' ) ) ) );
		}

		if ( 'plugin' === $_POST['type'] && ! mainwp_current_user_have_right( 'dashboard', 'update_plugins' ) ) {
			die( wp_json_encode( array( 'error' => mainwp_do_not_have_permissions( __( 'update plugins', 'mainwp' ), $echo = false ) ) ) );
		}

		if ( 'theme' === $_POST['type'] && ! mainwp_current_user_have_right( 'dashboard', 'update_themes' ) ) {
			die( wp_json_encode( array( 'error' => mainwp_do_not_have_permissions( __( 'update themes', 'mainwp' ), $echo = false ) ) ) );
		}

		if ( 'translation' === $_POST['type'] && ! mainwp_current_user_have_right( 'dashboard', 'update_translations' ) ) {
			die( wp_json_encode( array( 'error' => mainwp_do_not_have_permissions( __( 'update translations', 'mainwp' ), $echo = false ) ) ) );
		}

		$this->secure_request( 'mainwp_upgradeplugintheme' );

		// at the moment: support chunk update for manage sites page only.
		$chunk_support = isset( $_POST['chunk_support'] ) && $_POST['chunk_support'] ? true : false;
		$max_update    = 0;
		$websiteId     = null;
		$slugs         = '';

		if ( isset( $_POST['websiteId'] ) ) {
			$websiteId = $_POST['websiteId'];

			if ( $chunk_support ) {
				$max_update = apply_filters( 'mainwp_update_plugintheme_max', false, $websiteId );
				if ( empty( $max_update ) ) {
					$chunk_support = false; // there is not hook so disable chunk update support.
				}
			}
			if ( $chunk_support ) {
				if ( isset( $_POST['chunk_slugs'] ) ) {
					$slugs = $_POST['chunk_slugs'];  // chunk slugs send so use this.
				} else {
					$slugs = MainWP_Updates_Handler::get_plugin_theme_slugs( $websiteId, $_POST['type'] );
				}
			} elseif ( isset( $_POST['slug'] ) ) {
				$slugs = $_POST['slug'];
			} else {
				$slugs = MainWP_Updates_Handler::get_plugin_theme_slugs( $websiteId, $_POST['type'] );
			}
		}

		if ( MainWP_DB_Backup::instance()->backup_full_task_running( $websiteId ) ) {
			die( wp_json_encode( array( 'error' => __( 'Backup process in progress on the child site. Please, try again later.', 'mainwp' ) ) ) );
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
			die( wp_json_encode( array( 'message' => __( 'Item slug could not be found. Update process could not be executed.', 'mainwp' ) ) ) );
		}
		$website = MainWP_DB::instance()->get_website_by_id( $websiteId );
		try {
			$info = array( 'result' => MainWP_Updates_Handler::upgrade_plugin_theme_translation( $websiteId, $_POST['type'], $slugs ) );

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
							'message' => $e->getMessage(),
							'extra'   => $e->get_message_extra(),
						),
					)
				)
			);
		}
	}

	public function mainwp_ignoreplugintheme() {
		$this->secure_request( 'mainwp_ignoreplugintheme' );

		if ( ! isset( $_POST['id'] ) ) {
			die( wp_json_encode( array( 'error' => __( 'Invalid request!', 'mainwp' ) ) ) );
		}
		wp_send_json( array( 'result' => MainWP_Updates_Handler::ignore_plugin_theme( $_POST['type'], $_POST['slug'], $_POST['name'], $_POST['id'] ) ) );
	}

	public function mainwp_unignoreabandonedplugintheme() {
		$this->secure_request( 'mainwp_unignoreabandonedplugintheme' );

		if ( ! isset( $_POST['id'] ) ) {
			die( wp_json_encode( array( 'error' => __( 'Invalid request!', 'mainwp' ) ) ) );
		}
		die( wp_json_encode( array( 'result' => MainWP_Updates_Handler::unignore_abandoned_plugin_theme( $_POST['type'], $_POST['slug'], $_POST['id'] ) ) ) ); // ok.
	}

	public function mainwp_unignoreabandonedpluginsthemes() {
		$this->secure_request( 'mainwp_unignoreabandonedpluginsthemes' );

		if ( ! isset( $_POST['slug'] ) ) {
			die( wp_json_encode( array( 'error' => __( 'Invalid request!', 'mainwp' ) ) ) );
		}
		die( wp_json_encode( array( 'result' => MainWP_Updates_Handler::unignore_abandoned_plugins_themes( $_POST['type'], $_POST['slug'] ) ) ) );
	}

	public function mainwp_dismissoutdateplugintheme() {
		$this->secure_request( 'mainwp_dismissoutdateplugintheme' );

		if ( ! isset( $_POST['id'] ) ) {
			die( wp_json_encode( array( 'error' => __( 'Invalid request!', 'mainwp' ) ) ) );
		}
		die( wp_json_encode( array( 'result' => MainWP_Updates_Handler::dismiss_plugin_theme( $_POST['type'], $_POST['slug'], $_POST['name'], $_POST['id'] ) ) ) );
	}

	public function mainwp_dismissoutdatepluginsthemes() {
		$this->secure_request( 'mainwp_dismissoutdatepluginsthemes' );

		if ( ! mainwp_current_user_have_right( 'dashboard', 'ignore_unignore_updates' ) ) {
			die( wp_json_encode( array( 'error' => mainwp_do_not_have_permissions( __( 'ignore/unignore updates', 'mainwp' ) ) ) ) );
		}

		if ( ! isset( $_POST['slug'] ) ) {
			die( wp_json_encode( array( 'error' => __( 'Invalid request!', 'mainwp' ) ) ) );
		}
		die( wp_json_encode( array( 'result' => MainWP_Updates_Handler::dismiss_plugins_themes( $_POST['type'], $_POST['slug'], $_POST['name'] ) ) ) );
	}

	public function mainwp_unignoreplugintheme() {
		$this->secure_request( 'mainwp_unignoreplugintheme' );

		if ( ! isset( $_POST['id'] ) ) {
			die( wp_json_encode( array( 'error' => __( 'Invalid request!', 'mainwp' ) ) ) );
		}
		die( wp_json_encode( array( 'result' => MainWP_Updates_Handler::unignore_plugin_theme( $_POST['type'], $_POST['slug'], $_POST['id'] ) ) ) );
	}

	public function mainwp_ignorepluginsthemes() {
		$this->secure_request( 'mainwp_ignorepluginsthemes' );

		if ( ! mainwp_current_user_have_right( 'dashboard', 'ignore_unignore_updates' ) ) {
			die( wp_json_encode( array( 'error' => mainwp_do_not_have_permissions( __( 'ignore/unignore updates', 'mainwp' ) ) ) ) );
		}

		if ( ! isset( $_POST['slug'] ) ) {
			die( wp_json_encode( array( 'error' => __( 'Invalid request!', 'mainwp' ) ) ) );
		}
		die( wp_json_encode( array( 'result' => MainWP_Updates_Handler::ignore_plugins_themes( $_POST['type'], $_POST['slug'], $_POST['name'] ) ) ) );
	}

	public function mainwp_unignorepluginsthemes() {
		$this->secure_request( 'mainwp_unignorepluginsthemes' );

		if ( ! isset( $_POST['slug'] ) ) {
			die( wp_json_encode( array( 'error' => __( 'Invalid request!', 'mainwp' ) ) ) );
		}
		die( wp_json_encode( array( 'result' => MainWP_Updates_Handler::unignore_plugins_themes( $_POST['type'], $_POST['slug'] ) ) ) );
	}

	public function mainwp_trust_plugin() {
		$this->secure_request( 'mainwp_trust_plugin' );

		MainWP_Plugins_Handler::trust_post();
		die( wp_json_encode( array( 'result' => true ) ) );
	}

	public function mainwp_trust_theme() {
		$this->secure_request( 'mainwp_trust_theme' );

		MainWP_Themes_Handler::trust_post();
		die( wp_json_encode( array( 'result' => true ) ) );
	}

}
