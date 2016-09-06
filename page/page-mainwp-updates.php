<?php

class MainWP_Updates {
	public static function getClassName() {
		return __CLASS__;
	}

	
	public static function init() {
		/**
		 * This hook allows you to render the Post page header via the 'mainwp-pageheader-updates' action.
		 * 
		 *
		 * This hook is normally used in the same context of 'mainwp-getsubpages-updates'
		 * 
		 *
		 * @see \MainWP_Updates::renderHeader
		 */
		add_action( 'mainwp-pageheader-updates', array( MainWP_Post::getClassName(), 'renderHeader' ) );

		/**
		 * This hook allows you to render the Updates page footer via the 'mainwp-pagefooter-updates' action.
		 * 
		 *
		 * This hook is normally used in the same context of 'mainwp-getsubpages-updates'
		 * 
		 *
		 * @see \MainWP_Updates::renderFooter
		 */
		add_action( 'mainwp-pagefooter-updates', array( MainWP_Post::getClassName(), 'renderFooter' ) );
	}

	public static function initMenu() {
		add_submenu_page( 'mainwp_tab', __( 'Updates', 'mainwp' ), '<span id="mainwp-Updates">' . __( 'Updates', 'mainwp' ) . '</span>', 'read', 'UpdatesManage', array(
			MainWP_Updates::getClassName(),
			'render',
		) );
		
	}

	/**
	 * @param string $shownPage The page slug shown at this moment
	 */
	public static function renderHeader( $shownPage ) {
		?>
		<div class="wrap">
		<a href="https://mainwp.com" id="mainwplogo" title="MainWP" target="_blank"><img src="<?php echo plugins_url( 'images/logo.png', dirname( __FILE__ ) ); ?>" height="50" alt="MainWP"/></a>
		<h2><i class="fa fa-file-text"></i> <?php _e( 'Updates', 'mainwp' ); ?></h2>
		<div style="clear: both;"></div><br/>		
		<div class="mainwp-tabs" id="mainwp-tabs">			
                        <a class="nav-tab pos-nav-tab <?php if ( $shownPage === 'UpdatesManage' ) {
                                echo 'nav-tab-active';
                        } ?>" href="admin.php?page=UpdatesManage"><?php _e( 'Updates', 'mainwp' ); ?></a>				
			<div class="clear"></div>
		</div>
		<div id="mainwp_wrap-inside">
		<?php
	}

	/**
	 * @param string $shownPage The page slug shown at this moment
	 */
	public static function renderFooter() {
		?>
		</div>
		</div>
		<?php
	}
        
        public static function render() {            
            self::renderHeader( 'UpdatesManage' );
            MainWP_Main::renderDashboardBody( array(), null, null, true);
            ?>     
            <div class="postbox" id="mainwp_page_updates_tab-contextbox-1">
                <h3 class="mainwp_box_title">
                        <span><i class="fa fa-refresh" aria-hidden="true"></i> <?php _e( 'Updates', 'mainwp' ); ?></span></h3>
                            <div class="inside">                
                            <div id="rightnow_list" xmlns="http://www.w3.org/1999/html"><?php MainWP_Right_Now::renderSites($updates = true); ?></div>
                    </div>
            </div>
            <?php
            self::renderFooter();
        }
       
}
