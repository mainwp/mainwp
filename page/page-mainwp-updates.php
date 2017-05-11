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

        MainWP_System::add_sub_left_menu(__('Updates', 'mainwp'), 'mainwp_tab', 'UpdatesManage', 'admin.php?page=UpdatesManage', '<i class="fa fa-refresh" aria-hidden="true"></i>', '' );
	}

	/**
	 * @param string $shownPage The page slug shown at this moment
	 */
	public static function renderHeader( $shownPage ) {
        MainWP_UI::render_left_menu();
		?>
		<div class="mainwp-wrap">

		<h1 class="mainwp-margin-top-0"><i class="fa fa-refresh" aria-hidden="true"></i> <?php _e( 'Updates', 'mainwp' ); ?></h1>

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

		if ( MainWP_Twitter::enabledTwitterMessages() ) {
			$filter = array(
				'upgrade_all_plugins',
				'upgrade_all_themes',
				'upgrade_all_wp_core'
			);
			foreach ( $filter as $what ) {
				$twitters = MainWP_Twitter::getTwitterNotice( $what );
				if ( is_array( $twitters ) ) {
					foreach ( $twitters as $timeid => $twit_mess ) {
						if ( !empty( $twit_mess ) ) {
							$sendText = MainWP_Twitter::getTwitToSend( $what, $timeid );
							if ( !empty( $sendText ) ) {
								?>
								<div class="mainwp-tips mainwp-notice mainwp-notice-blue twitter"><span class="mainwp-tip" twit-what="<?php echo $what; ?>" twit-id="<?php echo $timeid; ?>"><?php echo $twit_mess; ?></span>&nbsp;<?php MainWP_Twitter::genTwitterButton( $sendText );?><span><a href="#" class="mainwp-dismiss-twit mainwp-right" ><i class="fa fa-times-circle"></i> <?php _e('Dismiss','mainwp'); ?></a></span></div>
								<?php
							}
						}
					}
				}
			}
		}

        $total_vulner = apply_filters('mainwp_vulner_getvulner', 0, false);
        if ($total_vulner > 0) {
            ?>
    <div class="mainwp_info-box-red"><?php echo sprintf(_n('There is %d vulnerability update. %sClick here to see all vulnerability issues.%s', 'There are %d vulnerability updates. %sClick here to see all vulnerability issues.%s', $total_vulner, 'mainwp'), $total_vulner, '<a href="admin.php?page=Extensions-Mainwp-Vulnerability-Checker-Extension">', '</a>' ); ?></div>
            <?php
        }


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
