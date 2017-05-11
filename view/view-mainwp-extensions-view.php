<?php

class MainWP_Extensions_View {
	public static function initMenu() {
        $page =  add_submenu_page( 'mainwp_tab', __( 'Extensions', 'mainwp' ), ' <span id="mainwp-Extensions">' . __( 'Extensions', 'mainwp' ) . '</span>', 'read', 'Extensions', array(
			MainWP_Extensions::getClassName(),
			'render'
		) );
        MainWP_System::add_sub_left_menu(__('Add Extensions', 'mainwp'), 'Extensions', 'Extensions', 'admin.php?page=Extensions&leftmenu=1', '<i class="fa fa-plug"></i>', '' );
        MainWP_System::add_sub_left_menu(__('Extensions', 'mainwp'), 'mainwp_tab', 'Extensions', 'admin.php?page=Extensions', '<i class="fa fa-plug"></i>', '' );
        return $page;
	}

	public static function renderHeader( $shownPage, &$extensions ) {
        MainWP_UI::render_left_menu();
		?>
		<div class="mainwp-wrap">
		<h1><i class="fa fa-plug"></i> <?php _e( 'Extensions', 'mainwp' ); ?></h1>

		<div class="mainwp-tabs" id="mainwp-tabs">
			<a class="nav-tab pos-nav-tab" href="admin.php?page=Extensions"><?php _e( 'Manage Extensions', 'mainwp' ); ?></a>
			<?php
			if ( isset( $extensions ) && is_array( $extensions ) ) {
				foreach ( $extensions as $extension ) {
					if ( $extension['plugin'] == $shownPage ) {
						?>
						<a class="nav-tab pos-nav-tab echo nav-tab-active" href="admin.php?page=<?php echo $extension['page']; ?>"><?php echo $extension['name']; ?></a>
						<?php
					}
				}
			}
			?>
			<div class="clear"></div>
		</div>
		<div id="mainwp_wrap-inside">
		<?php
	}

	public static function renderFooter( $shownPage ) {

		?>
		</div>
		</div>
		<?php
	}

	public static function render() {
		MainWP_Tours::renderExtensionsTour();
		MainWP_System::do_mainwp_meta_boxes('mainwp_postboxes_manage_extensions');
	}

	public static function renderInstallAndActive() {
		$username     = $password = '';
		$checked_save = false;
		if ( get_option( 'mainwp_extensions_api_save_login' ) == true ) {
			$enscrypt_u   = get_option( 'mainwp_extensions_api_username' );
			$enscrypt_p   = get_option( 'mainwp_extensions_api_password' );
			$username     = ! empty( $enscrypt_u ) ? MainWP_Api_Manager_Password_Management::decrypt_string( $enscrypt_u ) : '';
			$password     = ! empty( $enscrypt_p ) ? MainWP_Api_Manager_Password_Management::decrypt_string( $enscrypt_p ) : '';
			$checked_save = true;
		}

		if ( get_option( 'mainwp_api_sslVerifyCertificate' ) == 1 ) {
			update_option( 'mainwp_api_sslVerifyCertificate', 0 );
		}
		?>
		<div class="mainwp-postbox-actions-top">
			<strong><?php _e( 'Step 1', 'mainwp' ); ?>: </strong><?php _e( 'Enter your MainWP (https://mainwp.com/) login to automatically install and activate purchased extensions.' ); ?>
		</div>
		<div class="inside">
			<div class="api-grabbing-fields">
				<div class="mainwp-padding-top-10 mainwp-padding-bottom-10">
					<?php echo sprintf( __( 'Not registered? %sCreate MainWP account here.%s', 'mainwp'), '<a href="https://mainwp.com/my-account/" target="_blank">', '</a>' ); ?>
				</div>
				<input type="text" id="mainwp_com_username" class="input username" placeholder="<?php esc_attr_e( 'Your MainWP Username', 'mainwp' ); ?>" value="<?php echo $username; ?>"/>
				<input type="password" id="mainwp_com_password" class="input passwd" placeholder="<?php esc_attr_e( 'Your MainWP Password', 'mainwp' ); ?>" value="<?php echo $password; ?>"/>
				<div class="mainwp-padding-top-10 mainwp-padding-bottom-10">
					<?php echo sprintf( __( 'Lost your password? %sReset password here.%s', 'mainwp'), '<a href="https://mainwp.com/my-account/lost-password/" target="_blank">', '</a>' ); ?>
				</div>
				<input type="checkbox" <?php echo $checked_save ? 'checked="checked"' : ''; ?> name="extensions_api_savemylogin_chk" id="extensions_api_savemylogin_chk"><?php _e( 'Remember me', 'mainwp' ); ?>
			</div>
			<p>
				<span class="extension_api_loading">
					<input type="button" class="button-primary button button-hero" id="mainwp-extensions-savelogin" value="<?php _e( 'Verify your MainWP Login', 'mainwp' ); ?>">
					<span class="mainwp-large mainwp-padding-top-20" style="display: block;"><i class="fa fa-spinner fa-pulse" style="display: none;"></i><span class="status hidden"></span></span>
				</span>
			</p>
		</div>
		<div class="mainwp-postbox-actions-mid">
			<strong><?php _e( 'Step 2', 'mainwp' ); ?>: </strong><?php echo sprintf( __( 'The show purchased extensions button will show you all your MainWP Extensions. After the list appears, you can select wanted extensions and install them automatically. You can also install them manually using the directions %shere%s.', 'mainwp' ), '<a href="http://docs.mainwp.com/how-to-install-mainwp-extensions/" >', '</a>' ); ?>
		</div>
		<div class="inside">
			<div id="mainwp-install-purchased-extensions" class="mainwp-padding-top-10 mainwp-padding-bottom-10">
				<span class="extension_api_loading">
					<input type="button" class="mainwp-upgrade-button button-primary button button-hero" id="mainwp-extensions-bulkinstall" value="<?php _e( 'Show purchased extensions', 'mainwp' ); ?>">
					<span class="mainwp-large mainwp-padding-top-20" style="display: block;"><i class="fa fa-spinner fa-pulse" style="display: none;"></i><span class="status hidden"></span></span>
				</span>
			</div>
		</div>
		<div class="mainwp-postbox-actions-mid">
			<strong><?php _e( 'Step 3', 'mainwp' ); ?>: </strong><?php echo sprintf( __( 'The grab API Keys will automatically add your API Keys for extension automatic updates. You can also manually enter your API for each Extension following the steps %shere%s.', 'mainwp' ), '<a href="http://docs.mainwp.com/enter-extensions-api-keys/" >', '</a>' ); ?>
		</div>
		<div class="inside">
			<div class="mainwp-padding-top-10 mainwp-padding-bottom-10">
				<span class="extension_api_loading">
					<input type="button" class="mainwp-upgrade-button button button-hero" id="mainwp-extensions-grabkeys" value="<?php _e( 'Grab Api Keys', 'mainwp' ); ?>">
					<span class="mainwp-large mainwp-padding-top-20" style="display: block;"><i class="fa fa-spinner fa-pulse" style="display: none;"></i><span class="status hidden"></span></span>
				</span>
			</div>
		</div>
		<?php
	}

	public static function renderInstalledExtensions($post, $metabox) {
		$extensions = isset($metabox['args']['extensions']) ? $metabox['args']['extensions'] : array();
		if ( !is_array( $extensions ) )
			$extensions = array();

		?>
		<?php if ( count( $extensions ) == 0 ) { ?>
			<div class="mainwp-notice mainwp-notice-blue">
				<h3><?php _e( 'What are extensions?', 'mainwp' ); ?></h3>
				<?php _e( 'Extensions are specific features or tools created for the purpose of expanding the basic functionality of MainWP.', 'mainwp' ); ?>
				<h3><?php _e( 'Why have extensions?', 'mainwp' ); ?></h3>
				<?php _e( 'The core of MainWP has been designed to provide the functions most needed by our users and minimize code bloat. Extensions offer custom functions and features so that each user can tailor their MainWP to their specific needs.', 'mainwp' ); ?>
				<p>
					<a href="https://mainwp.com/mainwp-extensions/"><?php _e( 'Download your first extension now.', 'mainwp' ); ?></a>
				</p>
			</div>
		<?php } else { ?>
			<div class="mainwp-postbox-actions-top">
				<a class="mainwp_action left mainwp_action_down" href="#" id="mainwp-extensions-expand"><?php _e( 'Expand', 'mainwp' ); ?></a><a class="mainwp_action right" href="#" id="mainwp-extensions-collapse"><?php _e( 'Collapse', 'mainwp' ); ?></a>
				<?php if ( mainwp_current_user_can( 'dashboard', 'manage_extensions' ) ) { ?>
					<div style="float: right; margin-top: -3px;">
						<a href="<?php echo admin_url( 'plugin-install.php?tab=upload' ); ?>" class="mainwp-upgrade-button button-primary button"><?php _e( 'Install New Extension', 'mainwp' ); ?></a>
					</div>
					<div class="mainwp-clear"></div>
				<?php } ?>
			</div>
			<div class="inside">
				<div id="mainwp-extensions-list" class="wp-list-table widefat plugin-install">
					<div id="the-list">
						<?php
						$available_exts_data = MainWP_Extensions_View::getAvailableExtensions();
						if ( isset( $extensions ) && is_array( $extensions ) ) {
							foreach ( $extensions as $extension ) {
								if ( ! mainwp_current_user_can( 'extension', dirname( $extension['slug'] ) ) ) {
									continue;
								}
								$active = MainWP_Extensions::isExtensionActivated( $extension['slug'] );
								$added_on_menu = MainWP_Extensions::addedOnMenu( $extension['slug'] );
								$ext_data = isset( $available_exts_data[dirname($extension['slug'])] ) ? $available_exts_data[dirname($extension['slug'])] : array();
								$queue_status = '';

								if ( isset( $extension['apiManager'] ) && $extension['apiManager'] ) {
									$queue_status = 'status="queue"';
								}

								if ( isset($ext_data['img']) ) {
									$img_url = $ext_data['img'];
								} else if ( isset( $extension['iconURI'] ) && $extension['iconURI'] != '' )  {
									$img_url = MainWP_Utility::removeHttpPrefix( $extension['iconURI'] );
								} else {
									$img_url = plugins_url( 'images/extensions/placeholder.png', dirname( __FILE__ ) );
								}

								if ( isset( $extension['direct_page'] ) && ! empty( $extension['direct_page'] ) ) {
									$extension_page_url = admin_url( 'admin.php?page=' . $extension['direct_page'] );
								} else if ( isset( $extension['callback'] ) ) {
									$extension_page_url = admin_url( 'admin.php?page=' . $extension['page'] );
								} else {
									$extension_page_url = admin_url( 'admin.php?page=Extensions' );
								}

								if ( $added_on_menu ) {
									$extensino_to_menu_buton = '<button class="button mainwp-extensions-remove-menu">' . __('Remove from menu','mainwp') . '</button>';
								} else {
									$extensino_to_menu_buton = '<button class="button-primary mainwp-extensions-add-menu" >' . __('Add to menu','mainwp') . '</button>';
								}

								if (isset($extension['apiManager']) && $extension['apiManager']) {
									if ($active) {
										$extension_api_status = '<a href="javascript:void(0)" class="mainwp-extensions-api-activation api-status mainwp-green"><i class="fa fa-unlock"></i> ' . __('Update Notification Enabled','mainwp') . '</a>';
									} else {
										$extension_api_status = '<a href="javascript:void(0)" class="mainwp-extensions-api-activation api-status mainwp-red"><i class="fa fa-lock"></i> ' . __('Add API to Enable Update Notification','mainwp') . '</a>';
									}
								}

								?>

								<div class="plugin-card plugin-card-<?php echo $extension['name']; ?>" extension_slug="<?php echo $extension['slug']; ?>" <?php echo $queue_status; ?> license-status="<?php echo $active ? 'activated' : 'deactivated'; ?>">
									<div class="plugin-card-top">
										<div class="name column-name">
											<h3>
												<a href="<?php echo $extension_page_url; ?>">
													<?php echo $extension['name']; ?>
													<img src="<?php echo $img_url; ?>" title="<?php echo $extension['name']; ?>" alt="<?php echo $extension['name']; ?>" class="plugin-icon mainwp-extension-icon"/>
												</a>
											</h3>
										</div>
										<div class="action-links">
											<ul class="plugin-action-buttons">
												<li><?php echo $extensino_to_menu_buton; ?></li>
												<li>
													<?php if ( isset( $extension['apiManager'] ) && $extension['apiManager'] ) { ?>
														<?php echo '<a href="#" class="mainwp-extensions-api-activation" ><i class="fa fa-key" aria-hidden="true"></i> ' . __( 'Enter API Key', 'mainwp' ) . '</a>'; ?>
													<?php } ?>
												</li>
											</ul>
										</div>
										<div class="desc column-description">
											<p class="extension-description"><?php echo preg_replace( '/\<cite\>.*\<\/cite\>/', '', $extension['description'] ); ?></p>
											<p class="authors">
												<cite>
													<?php printf( __( 'By %s', 'mainwp' ), str_replace( array(
														'http:',
														'https:'
													), '', $extension['author'] ) ); ?>
													<?php echo ( isset( $extension['DocumentationURI'] ) && ! empty( $extension['DocumentationURI'] ) ) ? ' | <a href="' . str_replace( array(
															'http:',
															'https:'
														), '', $extension['DocumentationURI'] ) . '" target="_blank" title="' . __( 'Documentation', 'mainwp' ) . '">' . __( 'Documentation', 'mainwp' ) . '</a>' : ''; ?>
												</cite>
											</p>
										</div>
									</div>
									<?php if ( isset( $extension['apiManager'] ) && $extension['apiManager'] ) { ?>
										<div class="mainwp-extensions-api-row">
											<div class="api-row-div">
												<div class="mainwp-cols-1">
                                        	<span class="mainwp-left" style="width: 63%;">
                                        		<input type="text" class="input api_key" placeholder="<?php esc_attr_e( 'API License Key', 'mainwp' ); ?>" value="<?php echo $extension['api_key']; ?>"/>
	                                            <br/>
	                                            <input type="text" class="input api_email" placeholder="<?php esc_attr_e( 'API License Email', 'mainwp' ); ?>" value="<?php echo $extension['activation_email']; ?>"/>
                                        	</span>
                         					<span class="mainwp-right mainwp-cols-3 mainwp-padding-top-5">
                                            	<input type="button" <?php if ( $active ) { echo 'disabled'; }  ?> class="button-primary button button-hero mainwp-extensions-activate" value="<?php esc_attr_e( 'Activate API', 'mainwp' ); ?>">
                                           	</span>
													<div class="mainwp-clear"></div>
												</div>
												<?php if ( $active ) { ?>
													<div class="mainwp-cols-1 mainwp-padding-10">
                                        		<span class="mainwp-padding-top-20" style="width: 60%;">
		                                            <label for="extension-deactivate-cb"><?php _e( 'Deactivate License Key', 'mainwp' ); ?></label>
		                                            <input type="checkbox" id="extension-deactivate-cb" class="mainwp-extensions-deactivate-chkbox" <?php echo $extension['deactivate_checkbox'] == 'on' ? 'checked' : ''; ?>>
	                                            </span>
	                                            <span class="mainwp-right mainwp-cols-3">
	                                            	<input type="button" class="button mainwp-extensions-deactivate" value="<?php _e( 'Deactivate', 'mainwp' ); ?>">
	                                            </span>
													</div>
												<?php } ?>
												<div class="mainwp-clear"></div>
												<div class="mainwp_loading mainwp-padding-20"><i class="fa fa-spinner fa-pulse"></i> <?php _e( 'Please wait...', 'mainwp' ); ?></div>
											</div>
										</div>
										<div class="activate-api-status hidden mainwp-padding-20"></div>
									<?php } ?>
									<div class="plugin-card-bottom">
										<div class="vers column-rating">Version: <?php echo $extension['version']; ?></div>
										<div class="column-updated"><?php echo $extension_api_status; ?></div>
									</div>
								</div>
							<?php } ?>
						<?php } ?>
					</div>
				</div>
				<div class="mainwp-clear"></div>
			</div>
			<?php
		}
	}

	public static function renderAvailableExtensions( $post, $metabox ) {
		$extensions = isset($metabox['args']['extensions']) ? $metabox['args']['extensions'] : array();

		if ( !is_array( $extensions ) )
			$extensions = array();

		$all_extensions = self::getAvailableExtensions();
		$all_groups = self::getExtensionGroups();

		$installed_slugs = array();

		foreach ($extensions as $ext) {
			$installed_slugs[] = dirname($ext['slug']);
		}


		?>
		<div id="mainwp-available-extensions">
			<div id="mainwp-extensions-filter" class="mainwp-postbox-actions-top">
				<a class="mainwp_action left mainwp-show-extensions mainwp_action_down" href="#"
				   group="all"><?php _e('All', 'mainwp'); ?></a><a class="mainwp_action mid mainwp-show-extensions" href="#"
				                                                   group="free"><?php _e("Free", 'mainwp'); ?></a><?php
				$i = 0;
				foreach ($all_groups as $gr_id => $gr_name) {
					$i++;
					?><a class="mainwp_action <?php echo ($i < count($all_groups)) ? 'mid' : 'right'; ?> mainwp-show-extensions" href="#" group="<?php echo $gr_id; ?>"><?php echo esc_html($gr_name); ?></a><?php
				}
				?>
			</div>
			<div class="inside">
				<div id="mainwp-available-extensions-list" class="wp-list-table widefat plugin-install">
					<div id="the-list">
						<?php
						$extension_page_url = admin_url( 'admin.php?page=Extensions' );
						foreach ($all_extensions as $ext) {
							if (in_array($ext['slug'], $installed_slugs))
								continue;
							$is_free = (isset($ext['free']) && $ext['free']) ? true : false;
							$group_class = implode(" group-", $ext['group']);
							$group_class = " group-" . $group_class;
							?>
							<div class="mainwp-availbale-extension-holder plugin-card <?php echo $group_class; ?> <?php echo ($is_free) ? 'mainwp-free group-free' : 'mainwp-paid'; ?>">
								<div class="plugin-card-top">
									<div class="name column-name">
										<h3>
											<a href="<?php echo $extension_page_url; ?>">
												<?php echo $ext['title'] ?>
												<img src="<?php echo $ext['img'] ?>" title="<?php echo $ext['title']; ?>" alt="<?php echo $ext['title']; ?>" class="plugin-icon"/>
											</a>
										</h3>
									</div>
									<div class="action-links">
										<ul class="plugin-action-buttons">
											<li><a target="_blank" href="<?php echo str_replace(array("http:", "https:"), "", $ext['link']) .'?utm_source=dashboard&utm_campaign=extensions-page&utm_medium=plugin'; ?>" class="button"><?php _e( 'Find Out More', 'mainwp' ); ?></a></li>
											<li class="mainwp-padding-top-10"><?php echo $is_free ? '<span class="mainwp-price"></span>' : ''; ?></li>
										</ul>
									</div>
									<div class="desc column-description">
										<p><?php echo $ext['desc'] ?></p>
									</div>
								</div>
							</div>
							<?php
						}
						?>
					</div>
				</div>
				<div class="mainwp-clear"></div>
			</div>
			<div class="inside">
				<div class="installed-group-exts" style="clear: both; display: none">
					<?php echo __('All selected group extensions are installed.', 'mainwp') ?>
				</div>
			</div>
		</div>
		<?php
	}

	public static function getExtensionGroups() {
		$groups = array(
			'backup' => __('Backups', 'mainwp'),
			'content' => __('Content', 'mainwp'),
			'security' => __('Security', 'mainwp'),
			'hosting' => __('Hosting', 'mainwp'),
			'admin' => __('Administrative', 'mainwp'),
			'performance' => __('Performance', 'mainwp'),
			'visitor' => __('Visitor Data', 'mainwp')
		);
		return $groups;
	}

	public static function getAvailableExtensions() {
		return array(
			'advanced-uptime-monitor-extension' =>
				array(
					'free'       => true,
					'slug'       => 'advanced-uptime-monitor-extension',
					'title'      => 'MainWP Advanced Uptime Monitor',
					'desc'       => 'MainWP Extension for real-time up time monitoring.',
					'link'       => 'https://mainwp.com/extension/advanced-uptime-monitor/',
					'img'        => plugins_url( 'images/extensions/advanced-uptime-monitor.png', dirname( __FILE__ ) ),
					'product_id' => 'Advanced Uptime Monitor Extension',
					'catalog_id' => '218',
					'group' => array('admin')
				),
			'mainwp-article-uploader-extension' =>
				array(
					'slug'       => 'mainwp-article-uploader-extension',
					'title'      => 'MainWP Article Uploader Extension',
					'desc'       => 'MainWP Article Uploader Extension allows you to bulk upload articles to your dashboard and publish to child sites.',
					'link'       => 'https://mainwp.com/extension/article-uploader/',
					'img'        => plugins_url( 'images/extensions/article-uploader.png', dirname( __FILE__ ) ),
					'product_id' => 'MainWP Article Uploader Extension',
					'catalog_id' => '15340',
					'group' => array('content')
				),
			'mainwp-backupwordpress-extension' =>
				array(
					'slug'       => 'mainwp-backupwordpress-extension',
					'title'      => 'MainWP BackUpWordPress Extension',
					'desc'       => 'MainWP BackUpWordPress Extension combines the power of your MainWP Dashboard with the popular WordPress BackUpWordPress Plugin. It allows you to schedule backups on your child sites.',
					'link'       => 'https://mainwp.com/extension/backupwordpress/',
					'img'        => plugins_url( 'images/extensions/backupwordpress.png', dirname( __FILE__ ) ),
					'product_id' => 'MainWP BackUpWordPress Extension',
					'catalog_id' => '273535',
					'group' => array('backup')
				),
			'mainwp-backwpup-extension' =>
				array(
					'slug'       => 'mainwp-backwpup-extension',
					'title'      => 'MainWP BackWPup Extension',
					'desc'       => 'MainWP BackWPup Extension combines the power of your MainWP Dashboard with the popular WordPress BackWPup Plugin. It allows you to schedule backups on your child sites.',
					'link'       => 'https://mainwp.com/extension/backwpup/',
					'img'        => plugins_url( 'images/extensions/backwpup.png', dirname( __FILE__ ) ),
					'product_id' => 'MainWP BackWPup Extension',
					'catalog_id' => '995008',
					'group' => array('backup')
				),
			'mainwp-blogvault-backup-extension' =>
				array(
					'free' => true,
					'slug' => 'mainwp-blogvault-backup-extension',
					'title' => 'MainWP BlogVault Backup Extension',
					'desc' => 'MainWP BlogVault Backup Extension allows you to claim your 25% discount for the BlogVault Backup service.',
					'link' => 'https://mainwp.com/extension/blogvault-incremental-backup/',
					'img' => plugins_url( 'images/extensions/blog-vault.png', dirname( __FILE__ ) ),
					'product_id' => 'MainWP BlogVault Backup Extension',
					'catalog_id' => '347111',
					'group' => array('backup')
				),
			'boilerplate-extension' =>
				array(
					'slug'       => 'boilerplate-extension',
					'title'      => 'MainWP Boilerplate Extension',
					'desc'       => 'MainWP Boilerplate extension allows you to create, edit and share repetitive pages across your network of child sites. The available placeholders allow these pages to be customized for each site without needing to be rewritten. The Boilerplate extension is the perfect solution for commonly repeated pages such as your "Privacy Policy", "About Us", "Terms of Use", "Support Policy", or any other page with standard text that needs to be distributed across your network.',
					'link'       => 'https://mainwp.com/extension/boilerplate/',
					'img'        => plugins_url( 'images/extensions/boilerplate.png', dirname( __FILE__ ) ),
					'product_id' => 'Boilerplate Extension',
					'catalog_id' => '1188',
					'group' => array('content')
				),
			'mainwp-branding-extension' =>
				array(
					'slug'       => 'mainwp-branding-extension',
					'title'      => 'MainWP Branding Extension',
					'desc'       => 'The MainWP Branding extension allows you to alter the details of the MianWP Child Plugin to reflect your companies brand or completely hide the plugin from the installed plugins list.',
					'link'       => 'https://mainwp.com/extension/child-plugin-branding/',
					'img'        => plugins_url( 'images/extensions/branding.png', dirname( __FILE__ ) ),
					'product_id' => 'MainWP Branding Extension',
					'catalog_id' => '10679',
					'group' => array('admin')
				),
			'mainwp-broken-links-checker-extension' =>
				array(
					'slug'       => 'mainwp-broken-links-checker-extension',
					'title'      => 'MainWP Broken Links Checker Extension',
					'desc'       => 'MainWP Broken Links Checker Extension allows you to scan and fix broken links on your child sites. Requires the MainWP Dashboard Plugin.',
					'link'       => 'https://mainwp.com/extension/broken-links-checker/',
					'img'        => plugins_url( 'images/extensions/broken-links-checker.png', dirname( __FILE__ ) ),
					'product_id' => 'MainWP Broken Links Checker Extension',
					'catalog_id' => '12737',
					'group' => array('performance')
				),
			'mainwp-bulk-settings-manager' =>
				array(
					'slug' => 'mainwp-bulk-settings-manager',
					'title' => 'MainWP Bulk Settings Manager',
					'desc' => 'The Bulk Settings Manager Extension unlocks the world of WordPress directly from your MainWP Dashboard.  With Bulk Settings Manager you can adjust your Child site settings for the WordPress Core and almost any WordPress Plugin or Theme.',
					'link' => 'https://mainwp.com/extension/bulk-settings-manager/',
					'img' => plugins_url( 'images/extensions/bulk-settings-manager.png', dirname( __FILE__ ) ),
					'product_id' => 'MainWP Bulk Settings Manager',
					'catalog_id' => '347704',
					'group' => array('admin')
				),
			'mainwp-clean-and-lock-extension' =>
				array(
					'free'       => true,
					'slug'       => 'mainwp-clean-and-lock-extension',
					'title'      => 'MainWP Clean and Lock Extension',
					'desc'       => 'MainWP Clean and Lock Extension enables you to remove unwanted WordPress pages from your dashboard site and to control access to your dashboard admin area.',
					'link'       => 'https://mainwp.com/extension/clean-lock/',
					'img'        => plugins_url( 'images/extensions/clean-and-lock.png', dirname( __FILE__ ) ),
					'product_id' => 'MainWP Clean and Lock Extension',
					'catalog_id' => '12907',
					'group' => array('security')
				),
			'mainwp-client-reports-extension' =>
				array(
					'slug'       => 'mainwp-client-reports-extension',
					'title'      => 'MainWP Client Reports Extension',
					'desc'       => 'MainWP Client Reports Extension allows you to generate activity reports for your clients sites. Requires MainWP Dashboard.',
					'link'       => 'https://mainwp.com/extension/client-reports/',
					'img'        => plugins_url( 'images/extensions/client-reports.png', dirname( __FILE__ ) ),
					'product_id' => 'MainWP Client Reports Extension',
					'catalog_id' => '12139',
					'group' => array('admin')
				),
			'mainwp-clone-extension' =>
				array(
					'slug' => 'mainwp-clone-extension',
					'title' => 'MainWP Clone Extension',
					'desc' => 'MainWP Clone Extension is an extension for the MainWP plugin that enables you to clone your child sites with no technical knowledge required.',
					'link' => 'https://mainwp.com/extension/clone/',
					'img' => plugins_url( 'images/extensions/clone.png', dirname( __FILE__ ) ),
					'product_id' => 'MainWP Clone Extension',
					'catalog_id' => '1555',
					'group' => array('admin')
				),
			'mainwp-code-snippets-extension' =>
				array(
					'slug' => 'mainwp-code-snippets-extension',
					'title' => 'MainWP Code Snippets Extension',
					'desc' => 'The MainWP Code Snippets Extension is a powerful PHP platform that enables you to execute php code and scripts on your child sites and view the output on your Dashboard. Requires the MainWP Dashboard plugin.',
					'link' => 'https://mainwp.com/extension/code-snippets/',
					'img' => plugins_url( 'images/extensions/code-snippets.png', dirname( __FILE__ ) ),
					'product_id' => 'MainWP Code Snippets Extension',
					'catalog_id' => '11196',
					'group' => array('admin')
				),
			'mainwp-comments-extension' =>
				array(
					'slug' => 'mainwp-comments-extension',
					'title' => 'MainWP Comments Extension',
					'desc' => 'MainWP Comments Extension is an extension for the MainWP plugin that enables you to manage comments on your child sites.',
					'link' => 'https://mainwp.com/extension/comments/',
					'img' => plugins_url( 'images/extensions/comments.png', dirname( __FILE__ ) ),
					'product_id' => 'MainWP Comments Extension',
					'catalog_id' => '1551',
					'group' => array('admin')
				),
			'mainwp-favorites-extension' =>
				array(
					'slug' => 'mainwp-favorites-extension',
					'title' => 'MainWP Favorites Extension',
					'desc' => 'MainWP Favorites is an extension for the MainWP plugin that allows you to store your favorite plugins and themes, and install them directly to child sites from the dashboard repository.',
					'link' => 'https://mainwp.com/extension/favorites/',
					'img' => plugins_url( 'images/extensions/favorites.png', dirname( __FILE__ ) ),
					'product_id' => 'MainWP Favorites Extension',
					'catalog_id' => '1379',
					'group' => array('admin')
				),
			'mainwp-file-uploader-extension' =>
				array(
					'slug' => 'mainwp-file-uploader-extension',
					'title' => 'MainWP File Uploader Extension',
					'desc' => 'MainWP File Uploader Extension gives you an simple way to upload files to your child sites! Requires the MainWP Dashboard plugin.',
					'link' => 'https://mainwp.com/extension/file-uploader/',
					'img' => plugins_url( 'images/extensions/file-uploader.png', dirname( __FILE__ ) ),
					'product_id' => 'MainWP File Uploader Extension',
					'catalog_id' => '11637',
					'group' => array('content')
				),
			'mainwp-google-analytics-extension' =>
				array(
					'slug' => 'mainwp-google-analytics-extension',
					'title' => 'MainWP Google Analytics Extension',
					'desc' => 'MainWP Google Analytics Extension is an extension for the MainWP plugin that enables you to monitor detailed statistics about your child sites traffic. It integrates seamlessly with your Google Analytics account.',
					'link' => 'https://mainwp.com/extension/google-analytics/',
					'img' => plugins_url( 'images/extensions/google-analytics.png', dirname( __FILE__ ) ),
					'product_id' => 'MainWP Google Analytics Extension',
					'catalog_id' => '1554',
					'group' => array('visitor')
				),
			'mainwp-inmotion-hosting-extension' =>
				array(
					'free' => true,
					'slug' => 'mainwp-inmotion-hosting-extension',
					'title' => 'MainWP InMotion Hosting Extension',
					'desc' => 'MainWP InMotion Hosting Extension allows you to claim your coupon for 1 year of free hosting provided by the InMotion Hosting company.',
					'link' => 'https://mainwp.com/extension/inmotion-hosting/',
					'img' => plugins_url( 'images/extensions/inmotion.png', dirname( __FILE__ ) ),
					'product_id' => 'MainWP InMotion Hosting Extension',
					'catalog_id' => '336219',
					'group' => array('hosting')
				),
			'mainwp-links-manager-extension' =>
				array(
					'slug' => 'mainwp-links-manager-extension',
					'title' => 'MainWP Links Manager',
					'desc' => 'MainWP Links Manager is an Extension that allows you to create, manage and track links in your posts and pages for all your sites right from your MainWP Dashboard.',
					'link' => 'https://mainwp.com/extension/links-manager/',
					'img' => plugins_url( 'images/extensions/links-manager.png', dirname( __FILE__ ) ),
					'product_id' => 'MainWP Links Manager Extension',
					'catalog_id' => '317',
					'group' => array('content')
				),
			'mainwp-maintenance-extension' =>
				array(
					'slug' => 'mainwp-maintenance-extension',
					'title' => 'MainWP Maintenance Extension',
					'desc' => 'MainWP Maintenance Extension is MainWP Dashboard extension that clears unwanted entries from child sites in your network. You can delete post revisions, delete auto draft pots, delete trash posts, delete spam, pending and trash comments, delete unused tags and categories and optimize database tables on selected child sites.',
					'link' => 'https://mainwp.com/extension/maintenance/',
					'img' => plugins_url( 'images/extensions/maintenance.png', dirname( __FILE__ ) ),
					'product_id' => 'MainWP Maintenance Extension',
					'catalog_id' => '1141',
					'group' => array('admin')
				),
			'mainwp-piwik-extension' =>
				array(
					'slug' => 'mainwp-piwik-extension',
					'title' => 'MainWP Piwik Extension',
					'desc' => 'MainWP Piwik Extension is an extension for the MainWP plugin that enables you to monitor detailed statistics about your child sites traffic. It integrates seamlessly with your Piwik account.',
					'link' => 'https://mainwp.com/extension/piwik/',
					'img' => plugins_url( 'images/extensions/piwik.png', dirname( __FILE__ ) ),
					'product_id' => 'MainWP Piwik Extension',
					'catalog_id' => '10523',
					'group' => array('visitor')
				),
			'mainwp-post-dripper-extension' =>
				array(
					'slug' => 'mainwp-post-dripper-extension',
					'title' => 'MainWP Post Dripper Extension',
					'desc' => 'MainWP Post Dripper Extension allows you to deliver posts or pages to your network of sites over a pre-scheduled period of time. Requires MainWP Dashboard plugin.',
					'link' => 'https://mainwp.com/extension/post-dripper/',
					'img' => plugins_url( 'images/extensions/post-dripper.png', dirname( __FILE__ ) ),
					'product_id' => 'MainWP Post Dripper Extension',
					'catalog_id' => '11756',
					'group' => array('content')
				),
			'mainwp-rocket-extension' =>
				array(
					'slug' => 'mainwp-rocket-extension',
					'title' => 'MainWP Rocket Extension',
					'desc' => 'MainWP Rocket Extension combines the power of your MainWP Dashboard with the popular WP Rocket Plugin. It allows you to mange WP Rocket settings and quickly Clear and Preload cache on your child sites.',
					'link' => 'https://mainwp.com/extension/rocket/',
					'img' => plugins_url( 'images/extensions/rocket.png', dirname( __FILE__ ) ),
					'product_id' => 'MainWP Rocket Extension',
					'catalog_id' => '335257',
					'group' => array('performance')
				),
			'mainwp-spinner' =>
				array(
					'slug' => 'mainwp-spinner',
					'title' => 'MainWP Spinner',
					'desc' => 'MainWP Extension Plugin allows words to spun {|} when when adding articles and posts to your blogs. Requires the installation of MainWP Main Plugin.',
					'link' => 'https://mainwp.com/extension/spinner/',
					'img' => plugins_url( 'images/extensions/spinner.png', dirname( __FILE__ ) ),
					'product_id' => 'MainWP Spinner',
					'catalog_id' => '110',
					'group' => array('content')
				),
			'mainwp-sucuri-extension' =>
				array(
					'free' => true,
					'slug' => 'mainwp-sucuri-extension',
					'title' => 'MainWP Sucuri Extension',
					'desc' => 'MainWP Sucuri Extension enables you to scan your child sites for various types of malware, spam injections, website errors, and much more. Requires the MainWP Dashboard.',
					'link' => 'https://mainwp.com/extension/sucuri/',
					'img' => plugins_url( 'images/extensions/sucuri.png', dirname( __FILE__ ) ),
					'product_id' => 'MainWP Sucuri Extension',
					'catalog_id' => '10777',
					'group' => array('security')
				),
			'mainwp-team-control' =>
				array(
					'slug' => 'mainwp-team-control',
					'title' => 'MainWP Team Control',
					'desc' => 'MainWP Team Control extension allows you to create a custom roles for your dashboard site users and limiting their access to MainWP features. Requires MainWP Dashboard plugin.',
					'link' => 'https://mainwp.com/extension/team-control/',
					'img' => plugins_url( 'images/extensions/team-control.png', dirname( __FILE__ ) ),
					'product_id' => 'MainWP Team Control',
					'catalog_id' => '23936',
					'group' => array('admin')
				),
			'mainwp-updraftplus-extension' =>
				array(
					'free' => true,
					'slug' => 'mainwp-updraftplus-extension',
					'title' => 'MainWP UpdraftPlus Extension',
					'desc' => 'MainWP UpdraftPlus Extension combines the power of your MainWP Dashboard with the popular WordPress UpdraftPlus Plugin. It allows you to quickly back up your child sites.',
					'link' => 'https://mainwp.com/extension/updraftplus/',
					'img' => plugins_url( 'images/extensions/updraftplus.png', dirname( __FILE__ ) ),
					'product_id' => 'MainWP UpdraftPlus Extension',
					'catalog_id' => '165843',
					'group' => array('backup')
				),
			'mainwp-url-extractor-extension' =>
				array(
					'slug' => 'mainwp-url-extractor-extension',
					'title' => 'MainWP URL Extractor Extension',
					'desc' => 'MainWP URL Extractor allows you to search your child sites post and pages and export URLs in customized format. Requires MainWP Dashboard plugin.',
					'link' => 'https://mainwp.com/extension/url-extractor/',
					'img' => plugins_url( 'images/extensions/url-extractor.png', dirname( __FILE__ ) ),
					'product_id' => 'MainWP Url Extractor Extension',
					'catalog_id' => '11965',
					'group' => array('admin')
				),
			'mainwp-woocommerce-shortcuts-extension' =>
				array(
					'free' => true,
					'slug' => 'mainwp-woocommerce-shortcuts-extension',
					'title' => 'MainWP WooCommerce Shortcuts Extension',
					'desc' => 'MainWP WooCommerce Shortcuts provides you a quick access WooCommerce pages in your network. Requires MainWP Dashboard plugin.',
					'link' => 'https://mainwp.com/extension/woocommerce-shortcuts/',
					'img' => plugins_url( 'images/extensions/woo-shortcuts.png', dirname( __FILE__ ) ),
					'product_id' => 'MainWP WooCommerce Shortcuts Extension',
					'catalog_id' => '12706',
					'group' => array('admin')
				),
			'mainwp-woocommerce-status-extension' =>
				array(
					'slug' => 'mainwp-woocommerce-status-extension',
					'title' => 'MainWP WooCommerce Status Extension',
					'desc' => 'MainWP WooCommerce Status provides you a quick overview of your WooCommerce stores in your network. Requires MainWP Dashboard plugin.',
					'link' => 'https://mainwp.com/extension/woocommerce-status/',
					'img' => plugins_url( 'images/extensions/woo-status.png', dirname( __FILE__ ) ),
					'product_id' => 'MainWP WooCommerce Status Extension',
					'catalog_id' => '12671',
					'group' => array('admin')
				),
			'mainwp-wordfence-extension' =>
				array(
					'slug' => 'mainwp-wordfence-extension',
					'title' => 'MainWP WordFence Extension',
					'desc' => 'The WordFence Extension combines the power of your MainWP Dashboard with the popular WordPress Wordfence Plugin. It allows you to manage WordFence settings, Monitor Live Traffic and Scan your child sites directly from your dashboard. Requires MainWP Dashboard plugin.',
					'link' => 'https://mainwp.com/extension/wordfence/',
					'img' => plugins_url( 'images/extensions/wordfence.png', dirname( __FILE__ ) ),
					'product_id' => 'MainWP Wordfence Extension',
					'catalog_id' => '19678',
					'group' => array('security')
				),
			'wordpress-seo-extension' =>
				array(
					'slug' => 'wordpress-seo-extension',
					'title' => 'MainWP WordPress SEO Extension',
					'desc' => 'MainWP WordPress SEO extension by MainWP enables you to manage all your WordPress SEO by Yoast plugins across your network. Create and quickly set settings templates from one central dashboard. Requires MainWP Dashboard plugin.',
					'link' => 'https://mainwp.com/extension/wordpress-seo/',
					'img' => plugins_url( 'images/extensions/wordpress-seo.png', dirname( __FILE__ ) ),
					'product_id' => 'MainWP Wordpress SEO Extension',
					'catalog_id' => '12080',
					'group' => array('content')
				),
			'mainwp-page-speed-extension' =>
				array(
					'slug' => 'mainwp-page-speed-extension',
					'title' => 'MainWP Page Speed Extension',
					'desc' => 'MainWP Page Speed Extension enables you to use Google Page Speed insights to monitor website performance across your network. Requires MainWP Dashboard plugin.',
					'link' => 'https://mainwp.com/extension/page-speed/',
					'img' => plugins_url( 'images/extensions/page-speed.png', dirname( __FILE__ ) ),
					'product_id' => 'MainWP Page Speed Extension',
					'catalog_id' => '12581',
					'group' => array('performance')
				),
			'mainwp-ithemes-security-extension' =>
				array(
					'slug' => 'mainwp-ithemes-security-extension',
					'title' => 'MainWP iThemes Security Extension',
					'desc' => 'The iThemes Security Extension combines the power of your MainWP Dashboard with the popular iThemes Security Plugin. It allows you to manage iThemes Security plugin settings directly from your dashboard. Requires MainWP Dashboard plugin.',
					'link' => 'https://mainwp.com/extension/ithemes-security/',
					'img' => plugins_url( 'images/extensions/ithemes.png', dirname( __FILE__ ) ),
					'product_id' => 'MainWP Security Extension',
					'catalog_id' => '113355',
					'group' => array('security')
				),
			'mainwp-post-plus-extension' =>
				array(
					'slug' => 'mainwp-post-plus-extension',
					'title' => 'MainWP Post Plus Extension',
					'desc' => 'Enhance your MainWP publishing experience. The MainWP Post Plus Extension allows you to save work in progress as Post and Page drafts. That is not all, it allows you to use random authors, dates and categories for your posts and pages. Requires the MainWP Dashboard plugin.',
					'link' => 'https://mainwp.com/extension/post-plus/',
					'img' => plugins_url( 'images/extensions/post-plus.png', dirname( __FILE__ ) ),
					'product_id' => 'MainWP Post Plus Extension',
					'catalog_id' => '12458',
					'group' => array('admin')
				),
			'mainwp-custom-post-types' =>
				array(
					'slug' => 'mainwp-custom-post-types',
					'title' => 'MainWP Custom Post Type',
					'desc' => 'Custom Post Types Extension is an extension for the MainWP Plugin that allows you to manage almost any custom post type on your child sites and that includes Publishing, Editing, and Deleting custom post type content.',
					'link' => 'https://mainwp.com/extension/custom-post-types/',
					'img' => plugins_url( 'images/extensions/custom-post.png', dirname( __FILE__ ) ),
					'product_id' => 'MainWP Custom Post Types',
					'catalog_id' => '1002564',
					'group' => array('content')
				),
			'mainwp-buddy-extension' =>
				array(
					'slug' => 'mainwp-buddy-extension',
					'title' => 'MainWP Buddy Extension',
					'desc' => 'With the MainWP Buddy Extension, you can control the BackupBuddy Plugin settings for all your child sites directly from your MainWP Dashboard. This includes giving you the ability to create your child site backups and even set Backup schedules directly from your MainWP Dashboard.',
					'link' => 'https://mainwp.com/extension/mainwpbuddy/',
					'img' => plugins_url( 'images/extensions/mainwp-buddy.png', dirname( __FILE__ ) ),
					'product_id' => 'MainWP Buddy Extension',
					'catalog_id' => '1006044',
					'group' => array('backup')
				),
            'mainwp-vulnerability-checker-extension' =>
				array(
					'slug' => 'mainwp-vulnerability-checker-extension',
					'title' => 'MainWP Vulnerability Checker Extension',
					'desc' => 'MainWP Vulnerability Checker extension uses WPScan Vulnerability Database API to bring you information about vulnerable plugins on your Child Sites so you can act accordingly.',
					'link' => 'https://mainwp.com/extension/vulnerability-checker/',
					'img' => plugins_url( 'images/extensions/vulnerability-checker.png', dirname( __FILE__ ) ),
					'product_id' => 'MainWP Vulnerability Checker Extension',
					'catalog_id' => '12458',
					'group' => array('security')
				),
		);
	}
}
