<?php

class MainWP_Hooks {
	public function __construct() {
		add_filter( 'mainwp_getspecificdir', array( 'MainWP_Utility', 'getMainWPSpecificDir' ), 10, 1 );
		add_filter( 'mainwp_is_multi_user', array( &$this, 'isMultiUser' ) );
		add_filter( 'mainwp_qq2fileuploader', array( &$this, 'filter_qq2FileUploader' ), 10, 2 );
		add_action( 'mainwp_select_sites_box', array( &$this, 'select_sites_box' ), 10, 8 );
		add_action( 'mainwp_prepareinstallplugintheme', array( 'MainWP_Install_Bulk', 'prepareInstall' ) );
		add_action( 'mainwp_performinstallplugintheme', array( 'MainWP_Install_Bulk', 'performInstall' ) );
		add_filter( 'mainwp_getwpfilesystem', array( 'MainWP_Utility', 'getWPFilesystem' ) );
		add_filter( 'mainwp_getspecificurl', array( 'MainWP_Utility', 'getMainWPSpecificUrl' ), 10, 1 );
		add_filter( 'mainwp_getdownloadurl', array( 'MainWP_Utility', 'getDownloadUrl' ), 10, 2 );
		add_action( 'mainwp_renderToolTip', array( 'MainWP_Utility', 'renderToolTip' ), 10, 4 );
		add_action( 'mainwp_renderHeader', array( 'MainWP_UI', 'renderHeader' ), 10, 2 );
		add_action( 'mainwp_renderFooter', array( 'MainWP_UI', 'renderFooter' ), 10, 0 );
		add_action( 'mainwp_renderImage', array( 'MainWP_UI', 'renderImage' ), 10, 4 );
		add_action( 'mainwp_notify_user', array( &$this, 'notifyUser' ), 10, 3 );
		add_action( 'mainwp_activePlugin', array( &$this, 'activePlugin' ), 10, 0 );
		add_action( 'mainwp_deactivePlugin', array( &$this, 'deactivePlugin' ), 10, 0 );
		add_action( 'mainwp_upgradePluginTheme', array( &$this, 'upgradePluginTheme' ), 10, 0 );

		//Internal hook - deprecated
		add_filter( 'mainwp_getUserExtension', array( &$this, 'getUserExtension' ) );
		add_filter( 'mainwp_getwebsitesbyurl', array( &$this, 'getWebsitesByUrl' ) );
		add_filter( 'mainwp_getWebsitesByUrl', array( &$this, 'getWebsitesByUrl' ) ); //legacy
		add_filter( 'mainwp_getErrorMessage', array( &$this, 'getErrorMessage' ), 10, 2 );
		add_filter( 'mainwp_getwebsitesbygroupids', array( &$this, 'hookGetWebsitesByGroupIds' ), 10, 2 );

		//Cache hooks
		add_filter( 'mainwp_cache_getcontext', array( &$this, 'cache_getcontext' ) );
		add_action( 'mainwp_cache_echo_body', array( &$this, 'cache_echo_body' ) );
		add_action( 'mainwp_cache_init', array( &$this, 'cache_init' ) );
		add_action( 'mainwp_cache_add_context', array( &$this, 'cache_add_context' ), 10, 2 );
		add_action( 'mainwp_cache_add_body', array( &$this, 'cache_add_body' ), 10, 2 );

		add_filter( 'mainwp_getmetaboxes', array( &$this, 'getMetaBoxes' ), 10, 0 );
		add_filter( 'mainwp_getnotificationemail', array( 'MainWP_Utility', 'getNotificationEmail' ), 10, 0 );
		add_filter( 'mainwp-extension-available-check', array(
			MainWP_Extensions::getClassName(),
			'isExtensionAvailable',
		) );
		add_filter( 'mainwp-extension-decrypt-string', array( &$this, 'hookDecryptString' ) );
		add_action( 'mainp_log_debug', array( &$this, 'mainwp_log_debug' ), 10, 1 );
		add_action( 'mainp_log_info', array( &$this, 'mainwp_log_info' ), 10, 1 );
		add_action( 'mainp_log_warning', array( &$this, 'mainwp_log_warning' ), 10, 1 );
		add_filter( 'mainwp_getactivateextensionnotice', array( &$this, 'get_activate_extension_notice' ), 10, 1 );
		add_action( 'mainwp_enqueue_meta_boxes_scripts', array( &$this, 'enqueue_meta_boxes_scripts' ), 10, 1 );
		add_action( 'mainwp_do_meta_boxes', array( &$this, 'mainwp_do_meta_boxes' ), 10, 1 );
	}

	public function mainwp_log_debug( $pText ) {
		MainWP_Logger::Instance()->debug( $pText );
	}
	public function mainwp_log_info( $pText ) {
		MainWP_Logger::Instance()->info( $pText );
	}
	public function mainwp_log_warning( $pText ) {
		MainWP_Logger::Instance()->warning( $pText );
	}

	public function enqueue_meta_boxes_scripts() {		
		MainWP_System::enqueue_postbox_scripts();
	}

	public function mainwp_do_meta_boxes( $postpage ) {		
		MainWP_System::do_mainwp_meta_boxes( $postpage );
	}

	public function get_activate_extension_notice( $pluginFile ) {
		$active = MainWP_Extensions::isExtensionActivated( $pluginFile );
		if ($active)
			return false;

		$now = time();
		$register_time = get_option( 'mainwp_setup_register_later_time', 0 );
		if ($register_time > 0) {
			if ($now - $register_time > 24 * 60 * 60){
				delete_option('mainwp_setup_register_later_time');
			} else {
				return false;
			}
		}

		$activate_notices = get_user_option( 'mainwp_hide_activate_notices' );
		if ( is_array( $activate_notices ) ) {
			$slug = basename($pluginFile, ".php");
			if (isset($activate_notices[$slug])) {
				return false; // hide it
			}
		}

		return sprintf(__("You have a MainWP extension that does not have an active API entered. This means you will not receive updates or support.  Please visit the %sExtensions%s page and enter your API key.", 'mainwp'), '<a href="admin.php?page=Extensions">', '</a>');
	}

	public function cache_getcontext( $page ) {
		return MainWP_Cache::getCachedContext( $page );
	}

	public function cache_echo_body( $page ) {
		MainWP_Cache::echoBody( $page );
	}

	public function cache_init( $page ) {
		MainWP_Cache::initCache( $page );
	}

	public function cache_add_context( $page, $context ) {
		MainWP_Cache::addContext( $page, $context );
	}

	public function cache_add_body( $page, $body ) {
		MainWP_Cache::addBody( $page, $body );
	}


	public function select_sites_box( $title = '', $type = 'checkbox', $show_group = true, $show_select_all = true, $class = '', $style = '', $selected_websites = array(), $selected_groups = array() ) {
		MainWP_UI::select_sites_box( $title, $type, $show_group, $show_select_all, $class, $style, $selected_websites, $selected_groups );
	}

	public function notifyUser( $userId, $subject, $content ) {
		wp_mail( MainWP_DB::Instance()->getUserNotificationEmail( $userId ), $subject, $content, array(
			'From: "' . get_option( 'admin_email' ) . '" <' . get_option( 'admin_email' ) . '>',
			'content-type: text/html',
		) );
	}

	public function getErrorMessage( $msg, $extra ) {
		return MainWP_Error_Helper::getErrorMessage( new MainWP_Exception( $msg, $extra ) );
	}

	public function getUserExtension() {
		return MainWP_DB::Instance()->getUserExtension();
	}

	public function getWebsitesByUrl( $url ) {
		return MainWP_DB::Instance()->getWebsitesByUrl( $url );
	}

	public function isMultiUser() {
		return MainWP_System::Instance()->isMultiUser();
	}

	function filter_qq2FileUploader( $allowedExtensions, $sizeLimit ) {
		return new qq2FileUploader( $allowedExtensions, $sizeLimit );
	}

	function getMetaBoxes() {
		return MainWP_System::Instance()->metaboxes;
	}

	function activePlugin() {
		MainWP_Plugins::activatePlugins();
		die();
	}

	function deactivePlugin() {
		MainWP_Plugins::deactivatePlugins();
		die();
	}

	function upgradePluginTheme() {
		try {
			$websiteId = $type = null;
			$slugs     = array();
			if ( isset( $_POST['websiteId'] ) ) {
				$websiteId = $_POST['websiteId'];
			}
			if ( isset( $_POST['slugs'] ) ) {
				$slugs = $_POST['slugs'];
			}

			if ( isset( $_POST['type'] ) ) {
				$type = $_POST['type'];
			}

			$error = '';
			if ( $type == 'plugin' && ! mainwp_current_user_can( 'dashboard', 'update_plugins' ) ) {
				$error = mainwp_do_not_have_permissions( __( 'update plugins', 'mainwp' ), false );
			} else if ( $type == 'theme' && ! mainwp_current_user_can( 'dashboard', 'update_themes' ) ) {
				$error = mainwp_do_not_have_permissions( __( 'update themes', 'mainwp' ), false );
			}

			if ( ! empty( $error ) ) {
				die( json_encode( array( 'error' => $error ) ) );
			}

			if ( MainWP_Utility::ctype_digit( $websiteId ) ) {
				$website = MainWP_DB::Instance()->getWebsiteById( $websiteId );
				if ( MainWP_Utility::can_edit_website( $website ) ) {
					$information = MainWP_Utility::fetchUrlAuthed( $website, 'upgradeplugintheme', array(
						'type' => $type,
						'list' => urldecode( implode( ',', $slugs ) ),
					) );
					die( json_encode( $information ) );
				}
			}
		} catch ( MainWP_Exception $e ) {
			die( json_encode( array( 'error' => MainWP_Error_Helper::getErrorMessage($e) ) ) );
		}

		die();
	}

	public function hookGetWebsitesByGroupIds( $ids, $userId = null ) {
		return MainWP_DB::Instance()->getWebsitesByGroupIds( $ids, $userId );
	}

	public function hookDecryptString( $enscrypt ) {
		return MainWP_Api_Manager_Password_Management::decrypt_string( $enscrypt );
	}
}
