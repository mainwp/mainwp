<?php

class MainWP_Widget_Plugins {

	public static function getClassName() {
		return __CLASS__;
	}

	public static function render() {
		self::renderWidget( false, false );
	}

	public function prepare_icons() {
		include ABSPATH . 'wp-admin/includes/plugin-install.php';

		$args = array(
			'fields' => array(
				'last_updated'       => true,
				'icons'              => true,
				'active_installs'    => true,
			),
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
			$website = MainWP_DB::fetch_object( $websites );
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
			MainWP_DB::free_result( $websites );
		}

		$actived_plugins = MainWP_Utility::getSubArrayHaving( $allPlugins, 'active', 1 );
		$actived_plugins = MainWP_Utility::sortmulti( $actived_plugins, 'name', 'asc' );

		$inactive_plugins = MainWP_Utility::getSubArrayHaving( $allPlugins, 'active', 0 );
		$inactive_plugins = MainWP_Utility::sortmulti( $inactive_plugins, 'name', 'asc' );

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

		<div class="ui grid">
			<div class="twelve wide column">
				<h3 class="ui header handle-drag">
					<?php _e('Plugins', 'mainwp'); ?>
					<div class="sub header"><?php _e( 'Installed plugins on the child site', 'mainwp' ); ?></div>
				</h3>
			</div>
			<div class="four wide column right aligned">
				<div class="ui dropdown right mainwp-dropdown-tab">
						<div class="text"><?php _e( 'Active', 'mainwp' ); ?></div>
						<i class="dropdown icon"></i>
						<div class="menu">
							<a class="item" data-tab="active_plugins" data-value="active_plugins" title="<?php esc_attr_e( 'Active', 'mainwp' ); ?>" href="#"><?php _e( 'Active', 'mainwp' ); ?></a>
							<a class="item" data-tab="inactive_plugins" data-value="inactive_plugins" title="<?php esc_attr_e( 'Inactive', 'mainwp' ); ?>" href="#"><?php _e( 'Inactive', 'mainwp' ); ?></a>
						</div>
				</div>
			</div>
		</div>

		<div class="ui section hidden divider"></div>

		<!-- Active Plugins List -->
		<div id="mainwp-widget-active-plugins" class="ui tab active" data-tab="active_plugins">
			<div class="ui divided selection list">
				<?php
				$_count = count( $actived_plugins );
				for ( $i = 0; $i < $_count; $i ++ ) {

					$slug = strip_tags( $actived_plugins[ $i ]['slug'] );

					?>
					<div class="item">
						<input class="pluginSlug" type="hidden" name="slug" value="<?php echo esc_attr( strip_tags( $actived_plugins[ $i ]['slug'] ) ); ?>"/>
				<input class="websiteId" type="hidden" name="id" value="<?php echo esc_attr($website->id); ?>"/>
						<div class="right floated pluginsAction">
							<?php if ( mainwp_current_user_can( 'dashboard', 'activate_deactivate_plugins' ) ) { ?>
								 <a href="#" class="mainwp-plugin-deactivate ui mini button green" data-position="top right" data-tooltip="<?php echo __( 'Deactivate the ', 'mainwp') . esc_html( $actived_plugins[ $i ]['name'] ) . __( ' plugin on the child site.', 'mainwp'); ?>" data-inverted=""><?php _e( 'Deactivate', 'mainwp' ); ?></a>
							<?php } ?>
					</div>
						<div class="middle aligned content">
								<a href="<?php echo admin_url() . 'plugin-install.php?tab=plugin-information&plugin=' . dirname( strip_tags( $actived_plugins[ $i ]['slug'] ) ) . '&TB_iframe=true&width=640&height=477'; ?>" target="_blank" class="thickbox open-plugin-details-modal" title="More information about <?php echo esc_html( $actived_plugins[ $i ]['name'] ); ?>">
						<?php echo esc_html( $actived_plugins[ $i ]['name'] . ' ' . $actived_plugins[ $i ]['version'] ); ?>
					  </a>
							</div>
						<div class="mainwp-row-actions-working">
							<i class="notched circle loading icon"></i> <?php _e( 'Please wait...', 'mainwp' ); ?>
						</div>
					</div>
					<?php } ?>
			</div>
		</div>

		<!-- Inactive Plugins List -->
		<div id="mainwp-widget-inactive-plugins" class="ui tab" data-tab="inactive_plugins">
			<div class="ui middle aligned divided selection list">
				<?php
				$_count = count( $actived_plugins );
				for ( $i = 0; $i < $_count; $i ++ ) {

					$slug = $inactive_plugins[ $i ]['slug'];

					?>
					<div class="item">
						<input class="pluginSlug" type="hidden" name="slug" value="<?php echo esc_attr( strip_tags( $inactive_plugins[ $i ]['slug'] ) ); ?>"/>
				<input class="websiteId" type="hidden" name="id" value="<?php echo esc_attr($website->id); ?>"/>
						<div class="right floated content pluginsAction">
							<?php if ( mainwp_current_user_can( 'dashboard', 'activate_deactivate_plugins' ) ) { ?>
						  <a href="#" class="mainwp-plugin-activate ui mini green button" data-position="top right" data-tooltip="<?php echo __( 'Activate the ', 'mainwp') . strip_tags( $inactive_plugins[ $i ]['name'] ) . __( ' plugin on the child site.', 'mainwp'); ?>" data-inverted=""><?php _e( 'Activate', 'mainwp' ); ?></a>
							<?php } ?>
							<?php if ( mainwp_current_user_can( 'dashboard', 'delete_plugins' ) ) { ?>
					<a href="#" class="mainwp-plugin-delete ui mini basic button" data-position="top right" data-tooltip="<?php echo __( 'Delete the ', 'mainwp') . strip_tags( $inactive_plugins[ $i ]['name'] ) . __( ' plugin from the child site.', 'mainwp'); ?>" data-inverted=""><?php _e( 'Delete', 'mainwp' ); ?></a>
				  <?php } ?>
					</div>
						<div class="middle aligned content">
								<a href="<?php echo admin_url() . 'plugin-install.php?tab=plugin-information&plugin=' . dirname( $inactive_plugins[ $i ]['slug'] ) . '&TB_iframe=true&width=640&height=477'; ?>" target="_blank" class="thickbox open-plugin-details-modal" title="More information about <?php echo strip_tags($inactive_plugins[ $i ]['name']); ?>">
									<?php echo esc_html($inactive_plugins[ $i ]['name'] . ' ' . $inactive_plugins[ $i ]['version']); ?>
								  </a>
							</div>
						<div class="mainwp-row-actions-working">
							<i class="ui active inline loader tiny"></i> <?php _e( 'Please wait...', 'mainwp' ); ?>
						</div>
					</div>
				<?php } ?>
			</div>
		</div>

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
			die( json_encode( array( 'error' => MainWP_Error_Helper::getErrorMessage( $e ) ) ) );
		}

		if ( ! isset( $information['status'] ) || ( $information['status'] != 'SUCCESS' ) ) {
			die( json_encode( array( 'error' => 'Unexpected error!' ) ) );
		}
	}

}
