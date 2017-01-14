<?php

class MainWP_Widget_Plugins {
	public static function getClassName() {
		return __CLASS__;
	}

	public static function test() {

	}

	public static function getName() {
		return '<i class="fa fa-plug"></i> ' . __( 'Plugins', 'mainwp' );
	}

	public static function render() {
		?>
		<div id="recentposts_list"><?php MainWP_Widget_Plugins::renderWidget( false, false ); ?></div>
		<?php
	}

	public function prepare_icons() {
		include( ABSPATH . 'wp-admin/includes/plugin-install.php' );

		$args = array(
			'fields' => array(
				'last_updated' => true,
				'icons' => true,
				'active_installs' => true
			)
		);

		$api = plugins_api( 'query_plugins', $args );

		if ( is_wp_error( $api ) ) {
			$this->error = $api;
			return;
		}

		$this->items = $api->plugins;
	}

	public static function renderWidget( $renew, $pExit = true ) {
		$current_wpid = MainWP_Utility::get_current_wpid();
		if ( empty( $current_wpid ) ) {
			return;
		}

		$sql        = MainWP_DB::Instance()->getSQLWebsiteById( $current_wpid );
		$websites   = MainWP_DB::Instance()->query( $sql );
		$allPlugins = array();
		if ( $websites ) {
			$website = @MainWP_DB::fetch_object( $websites );
			if ( $website && $website->plugins != '' ) {
				$plugins = json_decode( $website->plugins, 1 );
				if ( is_array( $plugins ) && count( $plugins ) != 0 ) {
					foreach ( $plugins as $plugin ) {
						if ( isset( $plugin['mainwp'] ) && ( $plugin['mainwp'] == 'T' ) ) {
							continue;
						}
						$allPlugins[] = $plugin;
					}
				}
			}
			@MainWP_DB::free_result( $websites );
		}

		$actived_plugins = MainWP_Utility::getSubArrayHaving( $allPlugins, 'active', 1 );
		$actived_plugins = MainWP_Utility::sortmulti( $actived_plugins, 'name', 'desc' );

		$inactive_plugins = MainWP_Utility::getSubArrayHaving( $allPlugins, 'active', 0 );
		$inactive_plugins = MainWP_Utility::sortmulti( $inactive_plugins, 'name', 'desc' );

		$plugins_outdate = array();

		if ( ( count( $allPlugins ) > 0 ) && $website ) {

			$plugins_outdate = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'plugins_outdate_info' ), true );
			if ( ! is_array( $plugins_outdate ) ) {
				$plugins_outdate = array();
			}

			$pluginsOutdateDismissed = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'plugins_outdate_dismissed' ), true );
			if ( is_array( $pluginsOutdateDismissed ) ) {
				$plugins_outdate = array_diff_key( $plugins_outdate, $pluginsOutdateDismissed );
			}

			$userExtension           = MainWP_DB::Instance()->getUserExtension();
			$decodedDismissedPlugins = json_decode( $userExtension->dismissed_plugins, true );

			if ( is_array( $decodedDismissedPlugins ) ) {
				$plugins_outdate = array_diff_key( $plugins_outdate, $decodedDismissedPlugins );
			}
		}

		?>
		<div class="clear mwp_plugintheme_widget">
			<div class="mainwp-postbox-actions-top">
				<a class="mainwp_action left mainwp_action_down plugins_actived_lnk" href="#"><?php _e( 'Active', 'mainwp' ); ?> (<?php echo count( $actived_plugins ); ?>)</a><a class="mainwp_action mid plugins_inactive_lnk right" href="#"><?php _e( 'Inactive', 'mainwp' ); ?> (<?php echo count( $inactive_plugins ); ?>)</a>
			</div>
			<div class="mainwp_plugins_active">
				<?php

				$str_format = __( 'Last Updated %s Days Ago', 'mainwp' );
				for ( $i = 0; $i < count( $actived_plugins ); $i ++ ) {
					$outdate_notice    = '';
					$slug              = $actived_plugins[ $i ]['slug'];

					if ( isset( $plugins_outdate[ $slug ] ) ) {
						$plugin_outdate = $plugins_outdate[ $slug ];

						$now                      = new \DateTime();
						$last_updated             = $plugin_outdate['last_updated'];
						$plugin_last_updated_date = new \DateTime( '@' . $last_updated );
						$diff_in_days             = $now->diff( $plugin_last_updated_date )->format( '%a' );
						$outdate_notice           = sprintf( $str_format, $diff_in_days );
					}

					?>
					<div class="mainwp-row mainwp-active">
						<input class="pluginSlug" type="hidden" name="slug" value="<?php echo $actived_plugins[ $i ]['slug']; ?>"/>
						<input class="websiteId" type="hidden" name="id" value="<?php echo $website->id; ?>"/>
						<span class="mainwp-left mainwp-cols-2">
							<a href="<?php echo admin_url() . 'plugin-install.php?tab=plugin-information&plugin=' . dirname( $actived_plugins[ $i ]['slug'] ) . '&TB_iframe=true&width=640&height=477'; ?>" target="_blank" class="thickbox" title="More information about <?php echo $actived_plugins[ $i ]['name']; ?>">
								<?php echo $actived_plugins[ $i ]['name']; ?>
							</a>
							<a href="<?php echo admin_url() . 'plugin-install.php?tab=plugin-information&plugin=' . dirname( $actived_plugins[ $i ]['slug'] ) . '&TB_iframe=true&width=640&height=477&section=changelog'; ?>" target="_blank" class="thickbox" title="Changelog <?php echo $actived_plugins[ $i ]['name']; ?>">
								<?php echo ' ' . $actived_plugins[ $i ]['version']; ?>
							</a><br /><span class="mainwp-small"><?php echo $outdate_notice; ?></span>
						</span>
						<div class="mainwp-right mainwp-cols-2 mainwp-t-align-right pluginsAction">
							<?php if ( mainwp_current_user_can( 'dashboard', 'activate_deactivate_plugins' ) ) { ?>
								<a href="#" class="mainwp-plugin-deactivate button button-primary"><?php _e( 'Deactivate', 'mainwp' ); ?>
								</a>
							<?php } ?>
						</div>
						<div class="mainwp-clear"></div>
						<div class="mainwp-row-actions-working">
							<i class="fa fa-spinner fa-pulse"></i> <?php _e( 'Please wait...', 'mainwp' ); ?></div>
						<div>&nbsp;</div>
					</div>
				<?php } ?>
			</div>

			<div class="mainwp_plugins_inactive" style="display: none">
				<?php
				for ( $i = 0; $i < count( $inactive_plugins ); $i ++ ) {
					$outdate_notice = '';
					$slug           = $inactive_plugins[ $i ]['slug'];
					$plugin_i_icon_url= '//ps.w.org/' . $slug . '/assets/icon-128x128.png';
					if ( isset( $plugins_outdate[ $slug ] ) ) {
						$plugin_outdate = $plugins_outdate[ $slug ];

						$now                      = new \DateTime();
						$last_updated             = $plugin_outdate['last_updated'];
						$plugin_last_updated_date = new \DateTime( '@' . $last_updated );
						$diff_in_days             = $now->diff( $plugin_last_updated_date )->format( '%a' );
						$outdate_notice           = sprintf( $str_format, $diff_in_days );
					}
					?>
					<div class="mainwp-row mainwp-inactive">
						<input class="pluginSlug" type="hidden" name="slug" value="<?php echo $inactive_plugins[ $i ]['slug']; ?>"/>
						<input class="websiteId" type="hidden" name="id" value="<?php echo $website->id; ?>"/>
						<span class="mainwp-left mainwp-cols-2">
						<a href="<?php echo admin_url() . 'plugin-install.php?tab=plugin-information&plugin=' . dirname( $inactive_plugins[ $i ]['slug'] ) . '&TB_iframe=true&width=640&height=477'; ?>" target="_blank" class="thickbox" title="More information about <?php echo $inactive_plugins[ $i ]['name']; ?>">
							<?php echo $inactive_plugins[ $i ]['name']; ?>
						</a>
						<a href="<?php echo admin_url() . 'plugin-install.php?tab=plugin-information&plugin=' . dirname( $inactive_plugins[ $i ]['slug'] ) . '&TB_iframe=true&width=640&height=477&section=changelog'; ?>" target="_blank" class="thickbox" title="Changelog <?php echo $inactive_plugins[ $i ]['name']; ?>">
							<?php echo ' ' . $inactive_plugins[ $i ]['version']; ?>
						</a><br /><span class="mainwp-small"><?php echo $outdate_notice; ?></span>
						</span>
						<div class="mainwp-right mainwp-cols-2 mainwp-t-align-right pluginsAction">
							<?php if ( mainwp_current_user_can( 'dashboard', 'activate_deactivate_plugins' ) ) { ?>
								<a href="#" class="mainwp-plugin-activate button button-primary"><?php _e( 'Activate', 'mainwp' ); ?>
								</a>
							<?php } ?>
							<?php if ( mainwp_current_user_can( 'dashboard', 'delete_plugins' ) ) { ?>
								<a href="#" class="mainwp-plugin-delete mainwp-red button"><?php _e( 'Delete', 'mainwp' ); ?>
								</a>
							<?php } ?>
						</div>
						<div class="mainwp-clear"></div>
						<div class="mainwp-row-actions-working">
							<i class="fa fa-spinner fa-pulse"></i> <?php _e( 'Please wait...', 'mainwp' ); ?></div>
						<div>&nbsp;</div>
					</div>
				<?php } ?>
			</div>
		</div>
		<div class="mainwp-clear"></div>
		<?php
		if ( $pExit == true ) {
			exit();
		}
	}

	public static function activatePlugin() {
		self::action( 'activate' );
		die( json_encode( array( 'result' => __( 'Plugin has been activated!', 'mainwp' ) ) ) );
	}

	public static function deactivatePlugin() {
		self::action( 'deactivate' );
		die( json_encode( array( 'result' => __( 'Plugin has been deactivated!', 'mainwp' ) ) ) );
	}

	public static function deletePlugin() {
		self::action( 'delete' );
		die( json_encode( array( 'result' => __( 'Plugin has been permanently deleted!', 'mainwp' ) ) ) );
	}

	public static function action( $pAction ) {
		$plugin       = $_POST['plugin'];
		$websiteIdEnc = $_POST['websiteId'];

		if ( empty( $plugin ) ) {
			die( json_encode( array( 'error' => 'Invalid Request!' ) ) );
		}
		$websiteId = $websiteIdEnc;
		if ( ! MainWP_Utility::ctype_digit( $websiteId ) ) {
			die( json_encode( array( 'error' => 'Invalid Request!' ) ) );
		}

		$website = MainWP_DB::Instance()->getWebsiteById( $websiteId );
		if ( ! MainWP_Utility::can_edit_website( $website ) ) {
			die( json_encode( array( 'error' => 'You can not edit this website!' ) ) );
		}

		try {
			$information = MainWP_Utility::fetchUrlAuthed( $website, 'plugin_action', array(
				'action' => $pAction,
				'plugin' => $plugin,
			) );
		} catch ( MainWP_Exception $e ) {
			die( json_encode( array( 'error' => MainWP_Error_Helper::getErrorMessage($e) ) ) );
		}

		if ( ! isset( $information['status'] ) || ( $information['status'] != 'SUCCESS' ) ) {
			die( json_encode( array( 'error' => 'Unexpected error!' ) ) );
		}
	}
}
