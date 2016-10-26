<?php

class MainWP_Extensions_Widget {

	public static function getClassName() {
		return __CLASS__;
	}

	public static $extensions;
	public static $extensionsLoaded = false;

	public static function getName() {
		return '<i class="fa fa-plug"></i> ' . __( 'Extensions', 'mainwp' );
	}

	public static function render() {

		$currentExtensions = ( self::$extensionsLoaded ? self::$extensions : get_option( 'mainwp_extensions' ) );
		if ( count( $currentExtensions ) == 0 ) {
			?>
			<div class="mainwp-postbox-actions-top">
				<p><?php _e( 'MainWP extensions are specific features or tools created for the purpose of expanding the basic functionality of the MainWP plugin.', 'mainwp' ); ?></p>
				<p><?php _e( 'The core of MainWP has been designed to provide the functions most needed by our users and minimize code bloat. Extensions offer custom functions and features so that each user can tailor their MainWP Dashboard to their specific needs.', 'mainwp' ); ?></p>
			</div>
			<div class="inside" style="line-height: 1em;">
				<div class="mainwp-row">
					<div class="mainwp-t-align-left mainwp-cols-10 mainwp-left"><img style="width: 45px; height: 45px;" src="<?php echo plugins_url( 'images/extensions/advanced-uptime-monitor.png', dirname( __FILE__ ) ); ?>" /></div>
					<div class="mainwp-padding-top-15 mainwp-t-align-left mainwp-cols-2 mainwp-left"><span class="mainwp-large mainwp-padding-top-10">Advanced Uptime Monitor</span></div>
					<div class="mainwp-padding-top-10 mainwp-t-align-right mainwp-cols-5 mainwp-right"><a target="_blank" href="https://mainwp.com/extension/advanced-uptime-monitor/?utm_source=dashboard&utm_medium=plugin&utm_campaign=overviewwidget" class="button"><?php _e( 'Add for free', 'mainwp' ); ?></a></div>
					<div class="mainwp-clear"></div>
				</div>
				<div class="mainwp-row">
					<div class="mainwp-t-align-left mainwp-cols-10 mainwp-left"><img style="width: 45px; height: 45px;" src="<?php echo plugins_url( 'images/extensions/backupwordpress.png', dirname( __FILE__ ) ); ?>" /></div>
					<div class="mainwp-padding-top-15 mainwp-t-align-left mainwp-cols-2 mainwp-left"><span class="mainwp-large mainwp-padding-top-10">BackUpWordPress</span></div>
					<div class="mainwp-padding-top-10 mainwp-t-align-right mainwp-cols-5 mainwp-right"><a target="_blank" href="https://mainwp.com/extension/backupwordpress/?utm_source=dashboard&utm_medium=plugin&utm_campaign=overviewwidget" class="button"><?php _e( 'Add for free', 'mainwp' ); ?></a></div>
					<div class="mainwp-clear"></div>
				</div>
				<div class="mainwp-row">
					<div class="mainwp-t-align-left mainwp-cols-10 mainwp-left"><img style="width: 45px; height: 45px;" src="<?php echo plugins_url( 'images/extensions/backwpup.png', dirname( __FILE__ ) ); ?>" /></div>
					<div class="mainwp-padding-top-15 mainwp-t-align-left mainwp-cols-2 mainwp-left"><span class="mainwp-large mainwp-padding-top-10">BackWPup</span></div>
					<div class="mainwp-padding-top-10 mainwp-t-align-right mainwp-cols-5 mainwp-right"><a target="_blank" href="https://mainwp.com/extension/backwpup/?utm_source=dashboard&utm_medium=plugin&utm_campaign=overviewwidget" class="button"><?php _e( 'Add for free', 'mainwp' ); ?></a></div>
					<div class="mainwp-clear"></div>
				</div>
				<div class="mainwp-row">
					<div class="mainwp-t-align-left mainwp-cols-10 mainwp-left"><img style="width: 45px; height: 45px;" src="<?php echo plugins_url( 'images/extensions/blog-vault.png', dirname( __FILE__ ) ); ?>" /></div>
					<div class="mainwp-padding-top-15 mainwp-t-align-left mainwp-cols-2 mainwp-left"><span class="mainwp-large mainwp-padding-top-10">BlogVault</span></div>
					<div class="mainwp-padding-top-10 mainwp-t-align-right mainwp-cols-5 mainwp-right"><a target="_blank" href="https://mainwp.com/extension/blogvault/?utm_source=dashboard&utm_medium=plugin&utm_campaign=overviewwidget" class="button"><?php _e( 'Add for free', 'mainwp' ); ?></a></div>
					<div class="mainwp-clear"></div>
				</div>
				<div class="mainwp-row">
					<div class="mainwp-t-align-left mainwp-cols-10 mainwp-left"><img style="width: 45px; height: 45px;" src="<?php echo plugins_url( 'images/extensions/clean-and-lock.png', dirname( __FILE__ ) ); ?>" /></div>
					<div class="mainwp-padding-top-15 mainwp-t-align-left mainwp-cols-2 mainwp-left"><span class="mainwp-large mainwp-padding-top-10">Clean and Lock</span></div>
					<div class="mainwp-padding-top-10 mainwp-t-align-right mainwp-cols-5 mainwp-right"><a target="_blank" href="https://mainwp.com/extension/clean-and-lock/?utm_source=dashboard&utm_medium=plugin&utm_campaign=overviewwidget" class="button"><?php _e( 'Add for free', 'mainwp' ); ?></a></div>
					<div class="mainwp-clear"></div>
				</div>
				<div class="mainwp-row">
					<div class="mainwp-t-align-left mainwp-cols-10 mainwp-left"><img style="width: 45px; height: 45px;" src="<?php echo plugins_url( 'images/extensions/inmotion.png', dirname( __FILE__ ) ); ?>" /></div>
					<div class="mainwp-padding-top-15 mainwp-t-align-left mainwp-cols-2 mainwp-left"><span class="mainwp-large mainwp-padding-top-10">InMotion Hosting</span></div>
					<div class="mainwp-padding-top-10 mainwp-t-align-right mainwp-cols-5 mainwp-right"><a target="_blank" href="https://mainwp.com/extension/inmotion-hosting/?utm_source=dashboard&utm_medium=plugin&utm_campaign=overviewwidget" class="button"><?php _e( 'Add for free', 'mainwp' ); ?></a></div>
					<div class="mainwp-clear"></div>
				</div>
				<div class="mainwp-row">
					<div class="mainwp-t-align-left mainwp-cols-10 mainwp-left"><img style="width: 45px; height: 45px;" src="<?php echo plugins_url( 'images/extensions/sucuri.png', dirname( __FILE__ ) ); ?>" /></div>
					<div class="mainwp-padding-top-15 mainwp-t-align-left mainwp-cols-2 mainwp-left"><span class="mainwp-large mainwp-padding-top-10">Sucuri</span></div>
					<div class="mainwp-padding-top-10 mainwp-t-align-right mainwp-cols-5 mainwp-right"><a target="_blank" href="https://mainwp.com/extension/sucuri/?utm_source=dashboard&utm_medium=plugin&utm_campaign=overviewwidget" class="button"><?php _e( 'Add for free', 'mainwp' ); ?></a></div>
					<div class="mainwp-clear"></div>
				</div>
				<div class="mainwp-row">
					<div class="mainwp-t-align-left mainwp-cols-10 mainwp-left"><img style="width: 45px; height: 45px;" src="<?php echo plugins_url( 'images/extensions/updraftplus.png', dirname( __FILE__ ) ); ?>" /></div>
					<div class="mainwp-padding-top-15 mainwp-t-align-left mainwp-cols-2 mainwp-left"><span class="mainwp-large mainwp-padding-top-10">UpdraftPlus Backups</span></div>
					<div class="mainwp-padding-top-10 mainwp-t-align-right mainwp-cols-5 mainwp-right"><a target="_blank" href="https://mainwp.com/extension/updraftplus/?utm_source=dashboard&utm_medium=plugin&utm_campaign=overviewwidget" class="button"><?php _e( 'Add for free', 'mainwp' ); ?></a></div>
					<div class="mainwp-clear"></div>
				</div>
				<div class="mainwp-row">
					<div class="mainwp-t-align-left mainwp-cols-10 mainwp-left"><img style="width: 45px; height: 45px;" src="<?php echo plugins_url( 'images/extensions/woo-shortcuts.png', dirname( __FILE__ ) ); ?>" /></div>
					<div class="mainwp-padding-top-15 mainwp-t-align-left mainwp-cols-2 mainwp-left"><span class="mainwp-large mainwp-padding-top-10">WooCommerece Shortcuts</span></div>
					<div class="mainwp-padding-top-10 mainwp-t-align-right mainwp-cols-5 mainwp-right"><a target="_blank" href="https://mainwp.com/extension/woocommerce-shortcuts/?utm_source=dashboard&utm_medium=plugin&utm_campaign=overviewwidget" class="button"><?php _e( 'Add for free', 'mainwp' ); ?></a></div>
					<div class="mainwp-clear"></div>
				</div>
			</div>
			<div class="mainwp-postbox-actions-bottom mainwp-t-align-center">
				<a href="https://mainwp.com/mainwp-extensions/?utm_source=dashboard&utm_medium=plugin&utm_campaign=widget" target="_blank" class="button mainwp-upgrade-button button-hero"><?php _e( 'Explore more MainWP Extensions', 'mainwp' ); ?></a>
			</div>
			<?php
		} else {
			$available_exts_data = MainWP_Extensions_View::getAvailableExtensions();
			?>
			<div class="inside">
				<table id="mainwp-extensions-widget-list" cellspacing="0" cellpadding="1">
					<tbody>
					<?php
					foreach ( $currentExtensions as $extension ) {
						if ( ! mainwp_current_user_can( 'extension', dirname( $extension['slug'] ) ) ) {
							continue;
						}

						$ext_data = isset( $available_exts_data[dirname($extension['slug'])] ) ? $available_exts_data[dirname($extension['slug'])] : array();
						if ( isset($ext_data['img']) ) {
							$img_url = $ext_data['img'];
						} else if ( isset( $extension['iconURI'] ) && $extension['iconURI'] != '' )  {
							$img_url = MainWP_Utility::removeHttpPrefix( $extension['iconURI'] );
						} else {
							$img_url = plugins_url( 'images/extensions/placeholder.png', dirname( __FILE__ ) );
						}

						if ( isset( $extension['direct_page'] ) && ! empty( $extension['direct_page'] ) ) {
							$ext_page = $extension['direct_page'];
						} else {
							$ext_page = $extension['page'];
						}

						?>
						<div id="mainwp-extensions-list-widget">
							<div class="mainwp-padding-5 mainwp-t-align-left mainwp-cols-10 mainwp-left"><a href="<?php echo admin_url( 'admin.php?page=' . $ext_page ); ?>"><img title="<?php echo $extension['name']; ?>" src="<?php echo $img_url; ?>" /></a></div>
							<div class="mainwp-padding-15 mainwp-t-align-left mainwp-cols-2 mainwp-left"><a href="<?php echo admin_url( 'admin.php?page=' . $ext_page ); ?>"><?php echo $extension['name'] ?></a> - <em><?php echo $extension['version']; ?></em></div>
							<div class="mainwp-padding-15 mainwp-t-align-right mainwp-cols-5 mainwp-right">
								<?php
								if ( isset( $extension['apiManager'] ) && $extension['apiManager'] && ! empty( $extension['api_key'] ) ) { ?>
									<span class="mainwp-green"><i class="fa fa-unlock"></i> <?php _e( 'Activated', 'mainwp' ); ?></span>
								<?php } else {
									?>
									<span class="mainwp-red"><i class="fa fa-lock"></i> <?php _e( 'Deactivated', 'mainwp' ); ?></span>
								<?php } ?>
							</div>
							<div class="mainwp-clear"></div>
						</div>
						<?php
					}
					?>
					</tbody>
				</table>
			</div>
			<div class="mainwp-postbox-actions-bottom mainwp-t-align-center">
				<a href="https://mainwp.com/mainwp-extensions/?utm_source=dashboard&utm_medium=plugin&utm_campaign=widget" target="_blank" class="button mainwp-upgrade-button button-hero"><?php _e( 'Explore more MainWP Extensions', 'mainwp' ); ?></a>
			</div>
			<?php
		}
	}
}
