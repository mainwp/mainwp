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
			<span class="mainwp-no-extensions">
				<div class="mainwp_info-box-yellow"><?php _e( 'You have no installed extensions.', 'mainwp' ); ?></div>
				<br/>
				<div style="text-align: center">
					<a href="https://mainwp.com/extensions/" target="_blank" class="button button-hero button-primary"><?php _e( 'Add an Extension Today', 'mainwp' ); ?></a>
				</div>
				<br/>
				<h3 style="color: #7fb100;"><?php _e( 'What are Extensions?', 'mainwp' ); ?></h3>
				<p><?php _e( 'MainWP Extensions are specific features or tools created for the purpose of expanding the basic functionality of the MainWP plugin.', 'mainwp' ); ?></p>
				<h3 style="color: #7fb100;"><?php _e( 'Why have Extensions?', 'mainwp' ); ?></h3>
				<p><?php _e( 'The core of MainWP has been designed to provide the functions most needed by our users and minimize code bloat. Extensions offer custom functions and features so that each user can tailor their MainWP to their specific needs.', 'mainwp' ); ?></p>
			</span>
			<?php
		} else {
			$showGrid = get_option( 'mainwp_extension_widget_view', 'grid' ) == 'grid';
			$showList = ! $showGrid;
			$available_exts_data = MainWP_Extensions_View::getAvailableExtensions();
			?>
			<br/>
			<div id="mainwp-extensions-widget-grid" <?php echo( ! $showGrid ? "style='display:none;'" : '' ); ?>>
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
					<span class="mainwp-widget-extensions">
						<a href="<?php echo admin_url( 'admin.php?page=' . $ext_page ); ?>">
							<img title="<?php echo $extension['name']; ?>" src="<?php echo $img_url; ?>" class="mainwp-widget-icon"/>
						</a>
						<h4>
							<a href="<?php echo admin_url( 'admin.php?page=' . $ext_page ); ?>"><?php echo $extension['name'] ?></a>
						</h4>
					</span>
					<?php
				}
				?>
			</div>
			<div style="clear: both"></div>

			<table id="mainwp-extensions-widget-list" cellspacing="0" cellpadding="1" <?php echo( ! $showList ? "style='display:none;'" : '' ); ?>>
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
					<tr class="mainwp-widget-extensions-list mainwp-extensions-childHolder" extension_slug="<?php echo $extension['slug']; ?>">
							<td>
								<a href="<?php echo admin_url( 'admin.php?page=' . $ext_page ); ?>"><img title="<?php echo $extension['name']; ?>" src="<?php echo $img_url; ?>" class="mainwp-widget-icon-list"/></a>
							</td>
							<td class="mainwp-extension-widget-title-list">
								<a href="<?php echo admin_url( 'admin.php?page=' . $ext_page ); ?>"><?php echo $extension['name'] ?></a>
							</td>
							<td class="mainwp-extension-widget-version"><?php echo $extension['version']; ?></td>
							<td class="mainwp-api-status-check" align="right" style="padding-right: 10px;">
								<?php
								if ( isset( $extension['apiManager'] ) && $extension['apiManager'] && ! empty( $extension['api_key'] ) ) { ?>
									<span style="color: #7fb100;"><i class="fa fa-unlock"></i> <?php _e( 'Activated', 'mainwp' ); ?></span>
								<?php } else {
									?>
									<span style="color: #a00;"><i class="fa fa-lock"></i> <?php _e( 'Deactivated', 'mainwp' ); ?></span>
								<?php } ?>
							</td>
					</tr>
					<?php
				}
				?>
				</tbody>
			</table>
			<div style="clear: both; text-align: left; font-size: 12px; padding: .5em; border-top: 1px solid #dfdfdf;">
				<a href="https://mainwp.com/extensions/" target="_blank" class="button mainwp-upgrade-button"><?php _e( 'GET MORE EXTENSIONS', 'mainwp' ); ?></a>
				<span style="float: right;">
					<a href="#" class="mainwp-extension-widget-switch-grid" <?php echo( ! $showList ? "style='display:none;'" : '' ); ?>><?php _e( 'Show Grid View', 'mainwp' ); ?></a>
					<a href="#" class="mainwp-extension-widget-switch-list" <?php echo( ! $showGrid ? "style='display:none;'" : '' ); ?>><?php _e( 'Show List View', 'mainwp' ); ?></a>
				</span>
			</div>
			<?php
		}
	}

	public static function changeDefaultView() {
		if ( ! isset( $_POST['view'] ) ) {
			throw new Exception( __( 'Invalid Request', 'mainwp' ) );
		}

		if ( $_POST['view'] == 'list' ) {
			MainWP_Utility::update_option( 'mainwp_extension_widget_view', 'list' );
		} else {
			MainWP_Utility::update_option( 'mainwp_extension_widget_view', 'grid' );
		}

		return array( 'result' => 'SUCCESS' );
	}
}
