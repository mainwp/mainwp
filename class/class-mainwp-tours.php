<?php

class MainWP_Tours {

	public  static function enqueue_tours_scripts() {
		$_ver = '2.1';
		wp_enqueue_script( 'modernizr.mq', MAINWP_PLUGIN_URL . 'js/modernizr.mq.js', array(), $_ver );
		wp_enqueue_script( 'jquery.joyride-2.1', MAINWP_PLUGIN_URL . 'js/jquery.joyride-2.1.js', array(), $_ver );
		wp_enqueue_style( 'joyride-2.1', MAINWP_PLUGIN_URL . 'css/joyride-2.1.css', array(), $_ver );
	}

	public static function gen_tour_message($tour_id) {
		?>
		<div class="mainwp-walkthrough mainwp-notice-wrap"><?php _e('Need help getting started?', 'mainwp' ); ?>&nbsp;&nbsp;&nbsp;<a href="" class="mainwp_starttours"> <i class="fa fa-play" aria-hidden="true"></i> <?php _e( "Start the Tour!", "mainwp" ); ?></a>
                    <span class="mainwp-right"><a class="mainwp-notice-dismiss" notice-id="tour_<?php echo $tour_id; ?>"
                                                  style="text-decoration: none;" href="#"><i class="fa fa-times-circle"></i> <?php esc_html_e( 'Dismiss', 'mainwp' ); ?></a></span>
		</div>
		<?php
	}

	public static function renderMainWPToolsTour() {
		if ( !MainWP_Utility::showMainWPMessage( 'tour', 'tools' ) ) {
			return;
		}
		?>
		<ol id="mainwp-tours-content" style="display: none">
			<li data-id="force-destroy-sessions-button" data-button="Next">
				<p><?php _e( 'Use this button to log out any currently logged in users on your child sites and require them to re-log in. Use only if suggested by MainWP Support team.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_scan_child_sites_fki" data-button="Next">
				<p><?php _e( 'Scans each site individually for known issues.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_start_qsw" data-button="Next">
				<p><?php _e( 'Click this button if you want to initiate the MainWP Quick Start Wizard.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_hide_wpmenu_dashboard" data-button="Next">
				<p><?php _e( 'Select WP menu items that you want to hide from your MainWP Dashboard site.', 'mainwp' ); ?></p>
			</li>
			<li data-id="submit" data-button="Finish">
				<p><?php _e( 'Click the button to save changes.', 'mainwp' ); ?></p>
			</li>
		</ol>
		<?php echo self::gen_tour_message('tools'); ?>
		<script type="text/javascript">
			jQuery(window).load(function() {
				jQuery("#mainwp-tours-content").joyride({
					autoStart : false,
					tipLocation: 'top',
					tipAdjustmentY: -35
				});
				jQuery(document).on( 'click', '.mainwp_starttours', function(e) {
					jQuery('.metabox-holder div.postbox').each(function(){
						if (jQuery(this).hasClass('closed')) {
							jQuery(this).find('.handlediv').trigger("click");
						}
					});
					jQuery("#mainwp-tours-content").joyride();
					return false;
				});
			});
		</script>
		<?php
	}

	public static function renderAdvancedOptionsTour() {
		if ( !MainWP_Utility::showMainWPMessage( 'tour', 'adv_option' ) ) {
			return;
		}
		?>
		<ol id="mainwp-tours-content" style="display: none">
			<li data-id="mainwp_maximumRequests" data-button="Next">
				<p><?php _e( 'If too many requests are sent out, they will begin to time out. This causes your child sites to be shown as offline while they are up and running.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_minimumDelay" data-button="Next">
				<p><?php _e( 'This option allows you to control minimum time delay between two requests.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_maximumIPRequests" data-button="Next">
				<p><?php _e( 'If too many requests are sent out, they will begin to time out. This causes your child sites to be shown as offline while they are up and running.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_minimumIPDelay" data-button="Next">
				<p><?php _e( 'This option allows you to control minimum time delay between two requests.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_maximumSyncRequests" data-button="Next">
				<p><?php _e( 'Maximum simultaneous sync requests. When too many requests are sent to the backend some hosts will block the requests.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_maximumInstallUpdateRequests" data-button="Next">
				<p><?php _e( 'Minimum simultaneous install/update requests. When too many requests are sent to the backend some hosts will block the requests.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_sslVerifyCertificate" data-button="Next">
				<p><?php _e( 'If this option is enabled, MainWP Dashboard will verify the SSL Certificate on your Child Site (if exists) while connecting the Child Site to your MainWP Dashboard. Please note that this is a global setting. The option can be adjusted for individual use for each Child Site addition in the Add New Site form.', 'mainwp' ); ?></p>
				<p><?php _e( 'If you are using an out of date or self-assigned SSL Certificate and you are having trouble with connecting a Child Site, try to disable the SSL Certificate verification and see if that helps.', 'mainwp' ); ?></p>
			</li>
			<li data-id="submit" data-button="Finish">
				<p><?php _e( 'Click the button to save changes.', 'mainwp' ); ?></p>
			</li>
		</ol>
		<?php echo self::gen_tour_message('adv_option'); ?>
		<script type="text/javascript">
			jQuery(window).load(function() {
				jQuery("#mainwp-tours-content").joyride({
					autoStart : false,
					tipLocation: 'top',
					tipAdjustmentY: -35
				});
				jQuery(document).on( 'click', '.mainwp_starttours', function(e) {
					jQuery('.metabox-holder div.postbox').each(function(){
						if (jQuery(this).hasClass('closed')) {
							jQuery(this).find('.handlediv').trigger("click");
						}
					});
					jQuery("#mainwp-tours-content").joyride();
					return false;
				});
			});
		</script>
		<?php
	}

	public static function renderThemesIgnoredUpdatesTour() {
		if ( !MainWP_Utility::showMainWPMessage( 'tour', 'theme_ignore_update' ) ) {
			return;
		}
		?>
		<ol id="mainwp-tours-content" style="display: none">
			<li data-class="mainwp-globally-ignored-themes" data-button="Next">
				<p><?php _e( 'Here you can see globally ignored themes.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_g_theme" data-button="Next">
				<p><?php _e( 'Locate the theme you want to unignore.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_g_theme_allow" data-button="Next" data-options="nubPosition:top-right">
				<p><?php _e( 'Click the Allow button for the theme to unignore it.', 'mainwp' ); ?></p>
			</li>
			<li data-class="mainwp-per-site-ignored-themes" data-button="Next">
				<p><?php _e( 'Here you can see themes ignored per site.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_s_theme" data-button="Next">
				<p><?php _e( 'Locate the theme you want to unignore.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_s_theme_allow" data-button="Finish" data-options="nubPosition:top-right">
				<p><?php _e( 'Click the Allow button for the theme to unignore it.', 'mainwp' ); ?></p>
			</li>
		</ol>
		<?php echo self::gen_tour_message('theme_ignore_update'); ?>
		<script type="text/javascript">
			jQuery(window).load(function() {
				jQuery("#mainwp-tours-content").joyride({
					autoStart : false,
					tipLocation: 'top',
					tipAdjustmentY: -35
				});
				jQuery(document).on( 'click', '.mainwp_starttours', function(e) {
					jQuery('.metabox-holder div.postbox').each(function(){
						if (jQuery(this).hasClass('closed')) {
							jQuery(this).find('.handlediv').trigger("click");
						}
					});
					jQuery("#mainwp-tours-content").joyride();
					return false;
				});
			});
		</script>
		<?php
	}

	public static function renderThemesIgnoredAbandenedTour() {
		if ( !MainWP_Utility::showMainWPMessage( 'tour', 'theme_ignore_abn' ) ) {
			return;
		}
		?>
		<ol id="mainwp-tours-content" style="display: none">
			<li data-class="mainwp-globally-ignored-themes" data-button="Next">
				<p><?php _e( 'Here you can see globally ignored abandoned themes.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_g_theme" data-button="Next">
				<p><?php _e( 'Locate the theme you want to unignore.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_g_theme_allow" data-button="Next" data-options="nubPosition:top-right">
				<p><?php _e( 'Click the Allow button for the theme to unignore it.', 'mainwp' ); ?></p>
			</li>
			<li data-class="mainwp-per-site-ignored-themes" data-button="Next">
				<p><?php _e( 'Here you can see abandoned themes ignored per site.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_s_theme" data-button="Next">
				<p><?php _e( 'Locate the theme you want to unignore.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_s_theme_allow" data-button="Finish" data-options="nubPosition:top-right">
				<p><?php _e( 'Click the Allow button for the theme to unignore it.', 'mainwp' ); ?></p>
			</li>
		</ol>
		<?php echo self::gen_tour_message('theme_ignore_abn'); ?>
		<script type="text/javascript">
			jQuery(window).load(function() {
				jQuery("#mainwp-tours-content").joyride({
					autoStart : false,
					tipLocation: 'top',
					tipAdjustmentY: -35
				});
				jQuery(document).on( 'click', '.mainwp_starttours', function(e) {
					jQuery('.metabox-holder div.postbox').each(function(){
						if (jQuery(this).hasClass('closed')) {
							jQuery(this).find('.handlediv').trigger("click");
						}
					});
					jQuery("#mainwp-tours-content").joyride();
					return false;
				});
			});
		</script>
		<?php
	}

	public static function renderPluginsIgnoredUpdatesTour() {
		if ( !MainWP_Utility::showMainWPMessage( 'tour', 'plugin_ignore_update' ) ) {
			return;
		}
		?>
		<ol id="mainwp-tours-content" style="display: none">
			<li data-class="mainwp-globally-ignored-plugins" data-button="Next">
				<p><?php _e( 'Here you can see globally ignored plugins.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_g_plugin" data-button="Next">
				<p><?php _e( 'Locate the plugin you want to unignore.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_g_plugin_allow" data-button="Next" data-options="nubPosition:top-right">
				<p><?php _e( 'Click the Allow button for the plugin to unignore it.', 'mainwp' ); ?></p>
			</li>
			<li data-class="mainwp-per-site-ignored-plugins" data-button="Next">
				<p><?php _e( 'Here you can see plugins ignored per site.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_s_plugin" data-button="Next">
				<p><?php _e( 'Locate the plugin you want to unignore.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_s_plugin_allow" data-button="Finish" data-options="nubPosition:top-right">
				<p><?php _e( 'Click the Allow button for the plugin to unignore it.', 'mainwp' ); ?></p>
			</li>
		</ol>
		<?php echo self::gen_tour_message('plugin_ignore_update'); ?>
		<script type="text/javascript">
			jQuery(window).load(function() {
				jQuery("#mainwp-tours-content").joyride({
					autoStart : false,
					tipLocation: 'top',
					tipAdjustmentY: -35
				});
				jQuery(document).on( 'click', '.mainwp_starttours', function(e) {
					jQuery('.metabox-holder div.postbox').each(function(){
						if (jQuery(this).hasClass('closed')) {
							jQuery(this).find('.handlediv').trigger("click");
						}
					});
					jQuery("#mainwp-tours-content").joyride();
					return false;
				});
			});
		</script>
		<?php
	}

	public static function renderPluginsIgnoredAbandonedTour() {
		if ( !MainWP_Utility::showMainWPMessage( 'tour', 'plugin_ignore_abn' ) ) {
			return;
		}
		?>
		<ol id="mainwp-tours-content" style="display: none">
			<li data-class="mainwp-globally-ignored-plugins" data-button="Next">
				<p><?php _e( 'Here you can see globally ignored abandoned plugins.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_g_plugin" data-button="Next">
				<p><?php _e( 'Locate the plugin you want to unignore.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_g_plugin_allow" data-button="Next" data-options="nubPosition:top-right">
				<p><?php _e( 'Click the Allow button for the plugin to unignore it.', 'mainwp' ); ?></p>
			</li>
			<li data-class="mainwp-per-site-ignored-plugins" data-button="Next">
				<p><?php _e( 'Here you can see abandoned plugins ignored per site.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_s_plugin" data-button="Next">
				<p><?php _e( 'Locate the plugin you want to unignore.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_s_plugin_allow" data-button="Finish" data-options="nubPosition:top-right">
				<p><?php _e( 'Click the Allow button for the plugin to unignore it.', 'mainwp' ); ?></p>
			</li>
		</ol>
		<?php echo self::gen_tour_message('plugin_ignore_abn'); ?>
		<script type="text/javascript">
			jQuery(window).load(function() {
				jQuery("#mainwp-tours-content").joyride({
					autoStart : false,
					tipLocation: 'top',
					tipAdjustmentY: -35
				});
				jQuery(document).on( 'click', '.mainwp_starttours', function(e) {
					jQuery('.metabox-holder div.postbox').each(function(){
						if (jQuery(this).hasClass('closed')) {
							jQuery(this).find('.handlediv').trigger("click");
						}
					});
					jQuery("#mainwp-tours-content").joyride();
					return false;
				});
			});
		</script>
		<?php
	}

	public static function renderExtensionsTour() {
		if ( !MainWP_Utility::showMainWPMessage( 'tour', 'extensions' ) ) {
			return;
		}
		?>
		<ol id="mainwp-tours-content" style="display: none">
			<li data-id="mainwp_com_username" data-button="Next">
				<p><?php _e( 'Enter your username registered at MainWP.com', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_com_password" data-button="Next">
				<p><?php _e( 'Enter your password registered at MainWP.com', 'mainwp' ); ?></p>
			</li>
			<li data-id="extensions_api_savemylogin_chk" data-button="Next">
				<p><?php _e( 'Select this checkbox to save your login credentials', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp-extensions-savelogin" data-button="Next">
				<p><?php _e( 'Click the button to verify your login details.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp-extensions-bulkinstall" data-button="Next">
				<p><?php _e( 'Click the button to see your extensions', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp-check-all-ext" data-button="Next">
				<p><?php _e( 'Select extensions that you want to install.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp-extensions-installnow" data-button="Next">
				<p><?php _e( 'Click this button to start the installation process.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp-extensions-grabkeys" data-button="Next">
				<p><?php _e( 'Click this button to grab your API keys and activate your extensions.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mwp-extension-contentbox-2" data-button="Finish">
				<p><?php _e( 'Use your extensions.', 'mainwp' ); ?></p>
			</li>
		</ol>
		<?php echo self::gen_tour_message('extensions'); ?>
		<script type="text/javascript">
			jQuery(window).load(function() {
				jQuery("#mainwp-tours-content").joyride({
					autoStart : false,
					tipLocation: 'top',
					tipAdjustmentY: -35
				});
				jQuery(document).on( 'click', '.mainwp_starttours', function(e) {
					jQuery('.metabox-holder div.postbox').each(function(){
						if (jQuery(this).hasClass('closed')) {
							jQuery(this).find('.handlediv').trigger("click");
						}
					});
					jQuery("#mainwp-tours-content").joyride();
					return false;
				});
			});
		</script>
		<?php
	}

	public static function renderDashboardOptionsTour() {
		if ( !MainWP_Utility::showMainWPMessage( 'tour', 'dashboad_opts' ) ) {
			return;
		}
		?>
		<ol id="mainwp-tours-content" style="display: none">
			<li data-id="mainwp_hide_footer" data-button="Next">
				<p><?php _e( 'Set to YES if you want to hide the footer bar.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_use_favicon" data-button="Next">
				<p><?php _e( 'Set to YES if you want to see child site favicons in your MainWP Dashboard.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_hide_tips" data-button="Next">
				<p><?php _e( 'Set to YES if you want to hide MainWP Tips.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_hide_twitters_message" data-button="Next">
				<p><?php _e( 'Set to YES if you want to hide suggestions for Twiiter messages.', 'mainwp' ); ?></p>
			</li>
			<li data-id="submit" data-button="Finish">
				<p><?php _e( 'Click the button to save your changes.', 'mainwp' ); ?></p>
			</li>
		</ol>
		<?php echo self::gen_tour_message('dashboad_opts'); ?>
		<script type="text/javascript">
			jQuery(window).load(function() {
				jQuery("#mainwp-tours-content").joyride({
					autoStart : false,
					tipLocation: 'top',
					tipAdjustmentY: -35
				});
				jQuery(document).on( 'click', '.mainwp_starttours', function(e) {
					jQuery('.metabox-holder div.postbox').each(function(){
						if (jQuery(this).hasClass('closed')) {
							jQuery(this).find('.handlediv').trigger("click");
						}
					});
					jQuery("#mainwp-tours-content").joyride();
					return false;
				});
			});
		</script>
		<?php
	}

	public static function renderGeneralSettingsTour() {
		if ( !MainWP_Utility::showMainWPMessage( 'tour', 'general_opts' ) ) {
			return;
		}
		?>
		<ol id="mainwp-tours-content" style="display: none">
			<li data-id="mainwp_options_footprint_plugin_folder_default" data-button="Next" data-options="tipLocation:bottom;">
				<p><?php _e( 'By enabling this option, you will hide the MainWP Child plugin from search engines and your competitors. This means that anyone who tries to reach ../wp-content/plugins/mainwp-child/ directory on your child sites will encounter the 404 page.', 'mainwp' ); ?></p>
				<p><?php _e( 'This feature does not hide the MainWP Child plugin from the WordPress back-end. The plugin will still be visible in WP Admin on child sites.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_optimize" data-button="Next" data-options="tipLocation:bottom;">
				<p><?php _e( 'If enabled, MainWP Dashboard will cache updates for faster loading. Because of this, it is required to sync your MainWP Dashboard manually before performing updates.', 'mainwp' ); ?></p>
				<p><?php _e( 'Recommended for MainWP Dashboards with 50+ child site and for MainWP Dashboards that are hosted on shared servers.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_options_email" data-button="Next" data-options="tipLocation:bottom;">
				<p><?php _e( 'Add your email address here.', 'mainwp' ); ?></p>
				<p><?php _e( 'All email notification from MainWP Dashboard will be sent to this email address in case the other email address is not set in specific features.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_options_wp_cron" data-button="Next" data-options="tipLocation:bottom;">
				<p><?php _e( 'Setting this option to NO will disable the WP-Cron so all scheduled events will stop working. Disabling the WP-Cron requires setting Cron jobs manually in your hosting control panel.', 'mainwp' ); ?></p>
				<p><?php _e( 'Disable WP Cron only if you are sure you want to do this.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_show_language_updates" data-button="Next" data-options="tipLocation:bottom;">
				<p><?php _e( 'If enabled, this feature checks if there are available language pack updates on your child sites. The available updates will show in the Update Overview widget on the MainWP > Dashboard page.', 'mainwp' ); ?></p>
			</li>
			<li data-id="s2id_mainwp_automaticDailyUpdate" data-button="Next" data-options="tipLocation:bottom;">
				<p><?php _e( 'This option allows you to enable the Automatic Updates feature. You can enable it, disable it or set it to only send email notification about available updates.', 'mainwp' ); ?></p>
				<p><?php _e( 'Enabling this feature is not the only thing that needs to be done in order to set automatic backups to work. After enabling the feature, you need to mark plugins, themes and WP cores as “Trusted”. Only “Trusted” plugins, themes and WP Cores will be updated automatically.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_numberdays_Outdate_Plugin_Theme" data-button="Next" data-options="tipLocation:bottom;">
				<p><?php _e( 'In case a plugin or theme author didn\'t release an update for the set number of days, the plugin/theme will be marked as Possibly Abandoned. The list of possibly abandoned plugins and themes can be found in the Update Overview widget on the MainWP > Overview page.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_options_enableLegacyBackupFeature" data-button="Next" data-options="tipLocation:bottom;">
				<p><?php _e( 'Enables the legacy backup feature.', 'mainwp' ); ?></p>
				<p><strong><?php _e( 'It is highly recommended to use some of our Backup Extensions instead of the Legacy Backups. MainWP is actively moving away from the legacy backups feature.', 'mainwp' ); ?></strong></p>
			</li>
			<li data-id="submit" data-button="Finish">
				<p><?php _e( 'Click the button to save your changes.', 'mainwp' ); ?></p>
			</li>
		</ol>
		<?php echo self::gen_tour_message('general_opts'); ?>
		<script type="text/javascript">
			jQuery(window).load(function() {
				jQuery("#mainwp-tours-content").joyride({
					autoStart : false,
					tipLocation: 'top',
					tipAdjustmentY: -35
				});
				jQuery(document).on( 'click', '.mainwp_starttours', function(e) {
					jQuery('.metabox-holder div.postbox').each(function(){
						if (jQuery(this).hasClass('closed')) {
							jQuery(this).find('.handlediv').trigger("click");
						}
					});
					jQuery("#mainwp-tours-content").joyride();
					return false;
				});
			});
		</script>
		<?php
	}

	public static function renderInstallThemesTour() {
		if ( !MainWP_Utility::showMainWPMessage( 'tour', 'install_theme' ) ) {
			return;
		}
		?>
		<ol id="mainwp-tours-content" style="display: none">
			<li data-id="mainwp-browse-themes" data-button="Next">
				<p><?php _e( 'If you want to install a WordPress theme from the WordPress.org repository, click the Search tab.', 'mainwp' ); ?></p>
			</li>
			<li data-id="wp-filter-search-input" data-button="Next">
				<p><?php _e( 'Enter the Theme name and press the Enter button on your keyboard.', 'mainwp' ); ?></p>
			</li>
			<li data-id="theme-twentysixteen" data-button="Next">
				<p><?php _e( 'Select the theme by clicking the Install this Theme radio button.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp-select-sites-postbox" data-button="Next" data-options="tipLocation:left;">
				<p><?php _e( 'Select your child sites where you want to install the theme.', 'mainwp' ); ?></p>
			</li>
			<li data-id="chk_overwrite" data-button="Next" data-options="tipLocation:left;">
				<p><?php _e( 'If selected, the MainWP plugin will overwrite the theme on your child sites if the theme is already installed.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_theme_bulk_install_btn" data-button="Upload & Install Tour" data-options="tipLocation:left;">
				<p><?php _e( 'Click the button to start the installation process.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp-upload-themes" data-button="Next">
				<p><?php _e( 'If you want to install a WordPress theme by uploading it from your computer, click the Upload tab.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp-file-uploader" data-button="Next">
				<p><?php _e( 'Click the Upload Now button and upload your theme in zip format', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp-select-sites-postbox" data-button="Next" data-options="tipLocation:left;">
				<p><?php _e( 'Select your child sites where you want to install the theme.', 'mainwp' ); ?></p>
			</li>
			<li data-id="chk_overwrite" data-button="Next" data-options="tipLocation:left;">
				<p><?php _e( 'If selected, the MainWP plugin will overwrite the theme on your child sites if the theme is already installed.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_theme_bulk_install_upload_btn" data-button="Finish" data-options="tipLocation:left;">
				<p><?php _e( 'Click the button to start the installation process.', 'mainwp' ); ?></p>
			</li>
		</ol>
		<?php echo self::gen_tour_message('install_theme'); ?>
		<script type="text/javascript">
			jQuery(window).load(function() {
				jQuery("#mainwp-tours-content").joyride({
					autoStart : false,
					tipLocation: 'top',
					tipAdjustmentY: -35
				});
				jQuery(document).on( 'click', '.mainwp_starttours', function(e) {
					jQuery('.metabox-holder div.postbox').each(function(){
						if (jQuery(this).hasClass('closed')) {
							jQuery(this).find('.handlediv').trigger("click");
						}
					});
					jQuery("#mainwp-tours-content").joyride();
					return false;
				});
			});
		</script>
		<?php
	}

	public static function renderThemesAutoUpdatesTours() {
		if ( !MainWP_Utility::showMainWPMessage( 'tour', 'theme_auto_update' ) ) {
			return;
		}
		?>
		<ol id="mainwp-tours-content" style="display: none">
			<li data-id="s2id_mainwp_themeAutomaticDailyUpdate" data-button="Next">
				<p><?php _e( 'Enable the Automatic Updates by selecting the Install trusted updates option', 'mainwp' ); ?></p>
			</li>
			<li data-id="submit" data-button="Next">
				<p><?php _e( 'Click the button to save settings.', 'mainwp' ); ?></p>
			</li>
			<li data-id="s2id_mainwp_au_theme_status" data-button="Next">
				<p><?php _e( 'Select if you are serching for active, inactive or all themes on your child sites.', 'mainwp' ); ?></p>
			</li>
			<li data-id="s2id_mainwp_au_theme_trust_status" data-button="Next">
				<p><?php _e( 'Select if you are searching for trusted, not trusted or both.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_au_theme_keyword" data-button="Next">
				<p><?php _e( 'If you are looking for a specific theme, enter it\'s name here.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_show_all_themes" data-button="Next">
				<p><?php _e( 'Click the button to start the search.', 'mainwp' ); ?></p>
			</li>
			<li data-id="cb" data-button="Next">
				<p><?php _e( 'Select themes that you want to mark as trusted or not trusted.', 'mainwp' ); ?></p>
			</li>
			<li data-id="s2id_mainwp_bulk_action" data-button="Next">
				<p><?php _e( 'Select the action you want to perform.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_bulk_trust_themes_action_apply" data-button="Finish" data-options="nubPosition:top-right;">
				<p><?php _e( 'Click the button to complete the process.', 'mainwp' ); ?></p>
			</li>
		</ol>
		<?php echo self::gen_tour_message('theme_auto_update'); ?>
		<script type="text/javascript">
			jQuery(window).load(function() {
				jQuery("#mainwp-tours-content").joyride({
					autoStart : false,
					tipLocation: 'top',
					tipAdjustmentY: -35
				});
				jQuery(document).on( 'click', '.mainwp_starttours', function(e) {
					jQuery('.metabox-holder div.postbox').each(function(){
						if (jQuery(this).hasClass('closed')) {
							jQuery(this).find('.handlediv').trigger("click");
						}
					});
					jQuery("#mainwp-tours-content").joyride();
					return false;
				});
			});
		</script>
		<?php
	}

	public static function renderInstallPluginsTour() {
		if ( !MainWP_Utility::showMainWPMessage( 'tour', 'install_plugin' ) ) {
			return;
		}
		?>
		<ol id="mainwp-tours-content" style="display: none">
			<li data-id="MainWPInstallBulkNavSearch" data-button="Next">
				<p><?php _e( 'If you want to install a WordPress plugin from the WordPress.org repository, click the Search tab.', 'mainwp' ); ?></p>
			</li>
			<li data-id="wp-filter-search-plugins-input" data-button="Next" data-options="tipLocation:left;">
				<p><?php _e( 'Enter the plugin name and press the Enter button on your keyboard.', 'mainwp' ); ?></p>
			</li>
			<li data-id="plugin-filter" data-button="Next">
				<p><?php _e( 'Select the plugin by clicking the Install this Theme radio button.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp-select-sites-postbox" data-button="Next" data-options="tipLocation:left;">
				<p><?php _e( 'Select your child sites where you want to install the plugin.', 'mainwp' ); ?></p>
			</li>
			<li data-id="chk_activate_plugin" data-button="Next" data-options="tipLocation:left;tipAdjustmentY:-55;">
				<p><?php _e( 'If selected, the MainWP Plugin will automatically activate the installed plugin on your Child Sites.', 'mainwp' ); ?></p>
			</li>
			<li data-id="chk_overwrite" data-button="Next" data-options="tipLocation:left;tipAdjustmentY:-55;">
				<p><?php _e( 'If selected, the MainWP plugin will overwrite the plugin on your child sites if the plugin is already installed.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_plugin_bulk_install_btn" data-button="Upload & Install Tour" data-options="tipLocation:left;">
				<p><?php _e( 'Click the button to start the installation process.', 'mainwp' ); ?></p>
			</li>
			<li data-id="MainWPInstallBulkNavUpload" data-button="Next">
				<p><?php _e( 'If you want to install a WordPress plugin by uploading it from your computer, click the Upload tab.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp-file-uploader" data-button="Next">
				<p><?php _e( 'Click the Upload Now button and upload your plugin in zip format', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp-select-sites-postbox" data-button="Next" data-options="tipLocation:left;">
				<p><?php _e( 'Select your child sites where you want to install the plugin.', 'mainwp' ); ?></p>
			</li>
			<li data-id="chk_activate_plugin" data-button="Next" data-options="tipLocation:left;tipAdjustmentY:-55;">
				<p><?php _e( 'If selected, the MainWP Plugin will automatically activate the installed plugin on your Child Sites.', 'mainwp' ); ?></p>
			</li>
			<li data-id="chk_overwrite" data-button="Next" data-options="tipLocation:left;tipAdjustmentY:-55;">
				<p><?php _e( 'If selected, the MainWP plugin will overwrite the plugin on your child sites if the plugin is already installed.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_plugin_bulk_install_upload_btn" data-button="Finish" data-options="tipLocation:left;">
				<p><?php _e( 'Click the button to start the installation process.', 'mainwp' ); ?></p>
			</li>
		</ol>
		<?php echo self::gen_tour_message('install_plugin'); ?>
		<script type="text/javascript">
			jQuery(window).load(function() {
				jQuery("#mainwp-tours-content").joyride({
					autoStart : false,
					tipLocation: 'top',
					tipAdjustmentY: -35
				});
				jQuery(document).on( 'click', '.mainwp_starttours', function(e) {
					jQuery('.metabox-holder div.postbox').each(function(){
						if (jQuery(this).hasClass('closed')) {
							jQuery(this).find('.handlediv').trigger("click");
						}
					});
					jQuery("#mainwp-tours-content").joyride();
					return false;
				});
			});
		</script>
		<?php
	}

	public static function renderPluginsAutoUpdatesTours() {
		if ( !MainWP_Utility::showMainWPMessage( 'tour', 'plugin_auto_update' ) ) {
			return;
		}
		?>
		<ol id="mainwp-tours-content" style="display: none">
			<li data-id="s2id_mainwp_pluginAutomaticDailyUpdate" data-button="Next">
				<p><?php _e( 'Enable the Automatic Updates by selecting the Install trusted updates option".', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp-save-apu-options" data-button="Next">
				<p><?php _e( 'Click the button to save settings.', 'mainwp' ); ?></p>
			</li>
			<li data-id="s2id_mainwp_au_plugin_status" data-button="Next">
				<p><?php _e( 'Select if you are serching for active, inactive or all plugns on your child sites.', 'mainwp' ); ?></p>
			</li>
			<li data-id="s2id_mainwp_au_plugin_trust_status" data-button="Next">
				<p><?php _e( 'Select if you are searching for trusted, not trusted or both.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_au_plugin_keyword" data-button="Next">
				<p><?php _e( 'If you are looking for a specific plugin, enter it\'s name here.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_show_all_active_plugins" data-button="Next">
				<p><?php _e( 'Click the button to start the search.', 'mainwp' ); ?></p>
			</li>
			<li data-id="cb" data-button="Next">
				<p><?php _e( 'Select plugins that you want to mark as trusted or not trusted.', 'mainwp' ); ?></p>
			</li>
			<li data-id="s2id_mainwp_bulk_action" data-button="Next">
				<p><?php _e( 'Select the action you want to perform.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_bulk_trust_plugins_action_apply" data-button="Finish">
				<p><?php _e( 'Click the button to complete the process.', 'mainwp' ); ?></p>
			</li>
		</ol>
		<?php echo self::gen_tour_message('plugin_auto_update'); ?>
		<script type="text/javascript">
			jQuery(window).load(function() {
				jQuery("#mainwp-tours-content").joyride({
					autoStart : false,
					tipLocation: 'top',
					tipAdjustmentY: -35
				});
				jQuery(document).on( 'click', '.mainwp_starttours', function(e) {
					jQuery('.metabox-holder div.postbox').each(function(){
						if (jQuery(this).hasClass('closed')) {
							jQuery(this).find('.handlediv').trigger("click");
						}
					});
					jQuery("#mainwp-tours-content").joyride();
					return false;
				});
			});
		</script>
		<?php
	}

	public static function renderAddPostTour() {
		if ( !MainWP_Utility::showMainWPMessage( 'tour', 'add_post' ) ) {
			return;
		}
		?>
		<ol id="mainwp-tours-content" style="display: none">
			<li data-id="title" data-button="Next">
				<p><?php _e( 'Set the Post Title', 'mainwp' ); ?></p>
			</li>
			<li data-id="tinymce" data-button="Next">
				<p><?php _e( 'Set the Post Content', 'mainwp' ); ?></p>
			</li>
			<li data-id="excerpt" data-button="Next">
				<p><?php _e( 'Set the Post Excerpt (optional)', 'mainwp' ); ?></p>
			</li>
			<li data-id="postcustom" data-button="Next">
				<p><?php _e( 'Set the Post Custom Fields (optional)', 'mainwp' ); ?></p>
			</li>
			<li data-id="commentstatusdiv" data-button="Next">
				<p><?php _e( 'Set the Post Discussion Settings (optional)', 'mainwp' ); ?></p>
			</li>
			<li data-id="set-post-thumbnail" data-button="Next">
				<p><?php _e( 'Set the Post Featured Image (optional)', 'mainwp' ); ?></p>
			</li>
			<li data-id="add-tags-div" data-button="Next">
				<p><?php _e( 'Set the Post Tags (optional)', 'mainwp' ); ?></p>
			</li>
			<li data-id="add-categories-div" data-button="Next">
				<p><?php _e( 'Select an existing Categiories for the post or create a new one(s) (optional)', 'mainwp' ); ?></p>
			</li>
			<li data-id="select-sites-div" data-button="Next">
				<p><?php _e( 'Select Child Sites where you want to publish this Post', 'mainwp' ); ?></p>
			</li>
			<li data-id="publish" data-button="Finish">
				<p><?php _e( 'Click the Publish button', 'mainwp' ); ?></p>
			</li>
		</ol>
		<?php echo self::gen_tour_message('add_post'); ?>
		<script type="text/javascript">
			jQuery(window).load(function() {
				jQuery("#mainwp-tours-content").joyride({
					autoStart : false,
					tipLocation: 'top',
					tipAdjustmentY: -35
				});
				jQuery(document).on( 'click', '.mainwp_starttours', function(e) {
					jQuery('.metabox-holder div.postbox').each(function(){
						if (jQuery(this).hasClass('closed')) {
							jQuery(this).find('.handlediv').trigger("click");
						}
					});
					jQuery("#mainwp-tours-content").joyride();
					return false;
				});
			});
		</script>
		<?php
	}

	public static function renderAddPageTour() {
		if ( !MainWP_Utility::showMainWPMessage( 'tour', 'add_page' ) ) {
			return;
		}
		?>
		<ol id="mainwp-tours-content" style="display: none">
			<li data-id="title" data-button="Next">
				<p><?php _e( 'Set the Page Title', 'mainwp' ); ?></p>
			</li>
			<li data-id="tinymce" data-button="Next">
				<p><?php _e( 'Set the Page Content', 'mainwp' ); ?></p>
			</li>
			<li data-id="excerpt" data-button="Next">
				<p><?php _e( 'Set the Page Excerpt (optional)', 'mainwp' ); ?></p>
			</li>
			<li data-id="postcustom" data-button="Next">
				<p><?php _e( 'Set the Page Custom Fields (optional)', 'mainwp' ); ?></p>
			</li>
			<li data-id="commentstatusdiv" data-button="Next">
				<p><?php _e( 'Set the Page Discussion Settings (optional)', 'mainwp' ); ?></p>
			</li>
			<li data-id="set-post-thumbnail" data-button="Next">
				<p><?php _e( 'Set the Page Featured Image (optional)', 'mainwp' ); ?></p>
			</li>
			<li data-id="add-tags-div" data-button="Next">
				<p><?php _e( 'Set the Page Tags (optional)', 'mainwp' ); ?></p>
			</li>
			<li data-id="select-sites-div" data-button="Next">
				<p><?php _e( 'Select Child Sites where you want to publish this Page', 'mainwp' ); ?></p>
			</li>
			<li data-id="publish" data-button="Finish">
				<p><?php _e( 'Click the Publish button', 'mainwp' ); ?></p>
			</li>
		</ol>
		<?php echo self::gen_tour_message('add_page'); ?>
		<script type="text/javascript">
			jQuery(window).load(function() {
				jQuery("#mainwp-tours-content").joyride({
					autoStart : false,
					tipLocation: 'top',
					tipAdjustmentY: -35
				});
				jQuery(document).on( 'click', '.mainwp_starttours', function(e) {
					jQuery('.metabox-holder div.postbox').each(function(){
						if (jQuery(this).hasClass('closed')) {
							jQuery(this).find('.handlediv').trigger("click");
						}
					});
					jQuery("#mainwp-tours-content").joyride();
					return false;
				});
			});
		</script>
		<?php
	}

	public static function renderGroupsTour() {
		if ( !MainWP_Utility::showMainWPMessage( 'tour', 'group' ) ) {
			return;
		}
		?>
		<ol id="mainwp-tours-content" style="display: none">
			<li data-id="mainwp_managegroups-addnew-container" data-button="Next">
				<p><?php _e( 'Click here to create a new group', 'mainwp' ); ?></p>
				<p><em><?php _e( 'New field will appear where you can add a name of the group.', 'mainwp' ); ?></em></p>
				<p><?php _e( 'Enter the name and click the Save action.', 'mainwp' ); ?></p>
			</li>
			<li data-id="managegroups-listsites" data-button="Next">
				<p><?php _e( 'Select your websites that you want to add to the group.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp-save-group-selection" data-button="Finish">
				<p><?php _e( 'Click the button to save the group.', 'mainwp' ); ?></p>
			</li>
		</ol>
		<?php echo self::gen_tour_message('group'); ?>
		<script type="text/javascript">
			jQuery(window).load(function() {
				jQuery("#mainwp-tours-content").joyride({
					autoStart : false,
					tipLocation: 'top',
					tipAdjustmentY: -35
				});
				jQuery(document).on( 'click', '.mainwp_starttours', function(e) {
					jQuery('.metabox-holder div.postbox').each(function(){
						if (jQuery(this).hasClass('closed')) {
							jQuery(this).find('.handlediv').trigger("click");
						}
					});
					jQuery("#mainwp-tours-content").joyride();
					return false;
				});
			});
		</script>
		<?php
	}

	public static function renderSitesImportTour() {
		if ( !MainWP_Utility::showMainWPMessage( 'tour', 'site_import' ) ) {
			return;
		}
		?>
		<ol id="mainwp-tours-content" style="display: none">
			<li data-id="mainwp_managesites_file_bulkupload" data-button="Next">
				<p><?php _e( 'Upload the preformated CSV file.', 'mainwp' ); ?></p>
				<p><em><?php _e( 'You can download the sample CSV file and see what is the proper way to format it.', 'mainwp' ); ?></em><p>
			</li>
			<li data-id="mainwp_managesites_chk_header_first" data-button="Next">
				<p><?php _e( 'If you did not remove the header part of from the CSV file, leave this option selected.', 'mainwp' ); ?></p>
				<p><em><?php _e( 'The header part shows you the order of data that you need to use when formating the CSV file, it looks like this: "Site Name, Url, Admin Name, Group,Security ID,HTTP Username,HTTP Password,Verify Certificate,SSL Version"', 'mainwp' ); ?></em></p>
			</li>
			<li data-id="mainwp_managesites_bulkadd" data-button="Finish">
				<p><?php _e( 'Click the button to start the process.', 'mainwp' ); ?></p>
			</li>
		</ol>
		<?php echo self::gen_tour_message('site_import'); ?>
		<script type="text/javascript">
			jQuery(window).load(function() {
				jQuery("#mainwp-tours-content").joyride({
					autoStart : false,
					tipLocation: 'top',
					tipAdjustmentY: -35
				});
				jQuery(document).on( 'click', '.mainwp_starttours', function(e) {
					jQuery('.metabox-holder div.postbox').each(function(){
						if (jQuery(this).hasClass('closed')) {
							jQuery(this).find('.handlediv').trigger("click");
						}
					});
					jQuery("#mainwp-tours-content").joyride();
					return false;
				});
			});
		</script>
		<?php
	}

	public static function renderTestConnectionTour() {
		if ( !MainWP_Utility::showMainWPMessage( 'tour', 'test_connec' ) ) {
			return;
		}
		?>
		<ol id="mainwp-tours-content" style="display: none">
			<li data-id="mainwp_managesites_test_wpurl" data-button="Next">
				<p><?php _e( 'Enter your WordPress site URL', 'mainwp' ); ?></p>
			</li>
			<li data-id="s2id_mainwp_managesites_test_verifycertificate" data-button="Next">
				<p><?php _e( 'If your website doesn\'t use SSL Certificate, skip this field and leave the default value.', 'mainwp' ); ?></p>
				<p><?php _e( 'If your website uses SSL Certificate, select if you wish your MainWP Dashboard to verify the certificate before connecting the website.', 'mainwp' ); ?></p>
				<p><strong><?php _e( 'If you are using an out of date or self-assigned SSL Certificate and you are having trouble with connecting a Child Site, try to disable the SSL Certificate verification and see if that helps.', 'mainwp' ); ?></strong></p>
			</li>
			<li data-id="s2id_mainwp_managesites_test_ssl_version" data-button="Next">
				<p><?php _e( 'Select the SSL Version. If you don\'t know what is the SSL Version on your website or if there is no SSL Certificate on it, skip this field and leave the Auto Detect value.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_managesites_test_http_user" data-button="Next">
				<p><?php _e( 'If you don\'t use HTTP Basic Authentication on your website, skip this field', 'mainwp' ); ?></p>
				<p><?php _e( 'In case your website is protected with the HTTP Basic Authentication, enter your HTTP username', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_managesites_test_http_pass" data-button="Next">
				<p><?php _e( 'If you don\'t use HTTP Basic Authentication on your website, skip this field', 'mainwp' ); ?></p>
				<p><?php _e( 'In case your website is protected with the HTTP Basic Authentication, enter your HTTP password', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_managesites_test" data-button="Finish">
				<p><?php _e( 'Click the button to start the process.', 'mainwp' ); ?></p>
			</li>
		</ol>
		<?php echo self::gen_tour_message('test_connec'); ?>
		<script type="text/javascript">
			jQuery(window).load(function() {
				jQuery("#mainwp-tours-content").joyride({
					autoStart : false,
					tipLocation: 'top',
					tipAdjustmentY: -35
				});
				jQuery(document).on( 'click', '.mainwp_starttours', function(e) {
					jQuery('.metabox-holder div.postbox').each(function(){
						if (jQuery(this).hasClass('closed')) {
							jQuery(this).find('.handlediv').trigger("click");
						}
					});
					jQuery("#mainwp-tours-content").joyride();
					return false;
				});
			});
		</script>
		<?php
	}

	public static function renderSitesTour() {
		if ( !MainWP_Utility::showMainWPMessage( 'tour', 'sites' ) ) {
			return;
		}
		?>
		<ol id="mainwp-tours-content" style="display: none">
			<li data-id="mainwp_managesites_content" data-button="Next">
				<p><?php _e( 'The Manage Sites table allows you to manage your sites from one place.', 'mainwp' ); ?></p>
			</li>
			<li data-id="status" data-button="Next">
				<p><?php _e( 'This column shows you if there are available updates on your child site, if the site is disconnected or it is online.', 'mainwp' ); ?></p>
			</li>
			<li data-id="site" data-button="Next">
				<p><?php _e( 'Here you can see your Friendly site name. Hover over the friendly site name to reveal the Action row of the column. It will allow you to access the individual child site overview page, edit the site or reconnect it if it is disconnected.', 'mainwp' ); ?></p>
			</li>
			<li data-id="url" data-button="Next" data-options="nubPosition:top">
				<p><?php _e( 'Here you can see your child site URL. Hover over the URL to reveal the Action row of the column which enables you to quickly access the child site WP Admin, test connection, see updates and security issues for the child site.', 'mainwp' ); ?></p>
			</li>
			<li data-id="last_sync" data-button="Next">
				<p><?php _e( 'See the last sync time or use the provided action to sync the site.', 'mainwp' ); ?></p>
			</li>
			<li data-id="groups" data-button="Next">
				<p><?php _e( 'See in which group(s) this site is.', 'mainwp' ); ?></p>
			</li>
			<li data-id="last_post" data-button="Next">
				<p><?php _e( 'See time and date of the last post for the child site or use the provided action to quickly post an article to the child site.', 'mainwp' ); ?></p>
			</li>
			<li data-id="notes" data-button="Next" data-options="nubPosition:top-right">
				<p><?php _e( 'Click the Open link to add notes for the child site.', 'mainwp' ); ?></p>
			</li>
			<li data-id="s2id_bulk-action-selector-top" data-button="Next">
				<p><?php _e( 'Bulk actions menu allows you to perform updates, delete sites, reconnect sites or sync sites in bulk.', 'mainwp' ); ?></p>
			</li>
			<li data-id="s2id_autogen1" data-button="Next">
				<p><?php _e( 'Use the provided filter to quickly find sites from a specific group.', 'mainwp' ); ?></p>
			</li>
			<li data-id="s2id_autogen3" data-button="Next">
				<p><?php _e( 'Use the provided filter to quickly find sites with a specific status.', 'mainwp' ); ?></p>
			</li>
			<li data-class="mainwp_autocomplete" data-button="Next">
				<p><?php _e( 'If you are looking for a specific site, enter it\' friendly name or the URL here and click the Search Sites button.', 'mainwp' ); ?></p>
			</li>
			<li data-id="show-settings-link" data-button="Next" data-options="nubPosition:top-right;tipLocation:bottom;">
				<p><?php _e( 'Use the Screen Options menu to hide unwanted table columns or to change number of sites in the Manage Sites table.', 'mainwp' ); ?></p>
			</li>
			<li data-id="contextual-help-link" data-button="Finish" data-options="nubPosition:top-right;tipLocation:bottom;">
				<p><?php _e( 'If you need any additional help, you can always find the MainWP Documentation in the Help menu.', 'mainwp' ); ?></p>
			</li>
		</ol>
		<?php echo self::gen_tour_message('sites'); ?>
		<script type="text/javascript">
			jQuery(window).load(function() {
				jQuery("#mainwp-tours-content").joyride({
					autoStart : false,
					tipLocation: 'top',
					tipAdjustmentY: -35
				});
				jQuery(document).on( 'click', '.mainwp_starttours', function(e) {
					jQuery('.metabox-holder div.postbox').each(function(){
						if (jQuery(this).hasClass('closed')) {
							jQuery(this).find('.handlediv').trigger("click");
						}
					});
					jQuery("#mainwp-tours-content").joyride();
					return false;
				});
			});
		</script>
		<?php
	}

	public static function renderUpdatesTour() {
		if ( !MainWP_Utility::showMainWPMessage( 'tour', 'updates' ) ) {
			return;
		}
		?>
		<ol id="mainwp-tours-content" style="display: none">
			<li data-id="dashboard_refresh" data-button="Next">
				<p><?php _e( 'Triggers the synchronization process which will load fresh data from your Child Sites', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_page_updates_tab-contextbox-1" data-button="Next">
				<p><?php _e( 'The Updtes option box provides you a quick snapshot of your child sites. Here you can see all available updates and update Plugins, Themes and WordPress core files.', 'mainwp' ); ?></p>
			</li>
			<li data-id="s2id_mainwp_select_options_siteview" data-button="Next" data-options="nubPosition:top-right;">
				<p><?php _e( 'Quickly select if you want to see updates per Site, Plugin/Theme or Group.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp-right-now-total-updates" data-button="Next">
				<p><?php _e( 'See all availabe updates. To see details about available updates you can use provided links to drill down through lists and update plugins/thmees individully.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_plugin_upgrades_show" data-button="Finish">
				<p><?php _e( 'Click the link to see the list of all availabe updates.', 'mainwp' ); ?></p>
			</li>
		</ol>
		<?php echo self::gen_tour_message('updates'); ?>
		<script type="text/javascript">
			jQuery(window).load(function() {
				jQuery("#mainwp-tours-content").joyride({
					autoStart : false,
					tipLocation: 'top',
					tipAdjustmentY: -35
				});
				jQuery(document).on( 'click', '.mainwp_starttours', function(e) {
					jQuery('.metabox-holder div.postbox').each(function(){
						if (jQuery(this).hasClass('closed')) {
							jQuery(this).find('.handlediv').trigger("click");
						}
					});
					jQuery("#mainwp-tours-content").joyride();
					return false;
				});
			});
		</script>
		<?php
	}

	public static function renderOverviewTour() {
		if ( !MainWP_Utility::showMainWPMessage( 'tour', 'overview' ) ) {
			return;
		}
		?>
		<ol id="mainwp-tours-content" style="display: none">
			<li data-id="mainwp-welocme-bar" data-button="Next">
				<p><?php _e( 'The Welcome Widget provides you a quick information about sites synchronization.', 'mainwp' ); ?></p>
			</li>
			<li data-id="dashboard_refresh" data-button="Next">
				<p><?php _e( 'Trigger the synchronization process which will load fresh data from your Child Sites.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp-link-showhide-welcome-shortcuts" data-button="Next">
				<p><?php _e( 'Expand the widget to see helpful shortcuts.', 'mainwp' ); ?></p>
			</li>
			<li data-id="toplevel_page_mainwp_tab-contentbox-1" data-button="Next">
				<p><?php _e( 'The Overview widget provides you a quick snapshot of your child sites. Here you can see all available updates and update Plugins, Themes and WordPress core files.', 'mainwp' ); ?></p>
			</li>
			<li data-id="s2id_mainwp_select_options_siteview" data-button="Next" data-options="nubPosition:top-right;tipLocation:bottom;">
				<p><?php _e( 'Quickly select if you want to see updates per Site, Plugin/Theme or Group.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp-right-now-total-updates" data-button="Next">
				<p><?php _e( 'See all available updates. To see details about available updates you can use provided links to drill down through lists and update plugins/themes individually.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_plugin_upgrades_show" data-button="Next">
				<p><?php _e( 'Click the link to see the list of all available updates.', 'mainwp' ); ?></p>
			</li>
			<li data-id="toplevel_page_mainwp_tab-contentbox-2" data-button="Next">
				<p><?php _e( 'This widget allows you to see the current status of your Child Sites and if they have been synced recently.', 'mainwp' ); ?></p>
			</li>
			<li data-id="sync_status_list" data-button="Next">
				<p><?php _e( 'The left column of the widget shows the list of your child sites and by clicking a Child Site friendly name you can access the individual Child Site Overview for the Child Site.', 'mainwp' ); ?></p>
				<p><?php _e( 'The middle column shows the last sync time/date.', 'mainwp' ); ?></p>
				<p><?php _e( 'The Right column shows the current status of Child Sites. If a Child Site is not synced in last 24 hours, the Sync action will be displayed and you can symply sync the Child Site. If a Child Site is disconnected the Reconnect action will appear and you can quickly reconnect the Child Site.', 'mainwp' ); ?></p>
			</li>
			<li data-id="toplevel_page_mainwp_tab-contentbox-3" data-button="Next">
				<p><?php _e( 'This widget allows you to see Posts that have been created recently on your Child Sites. It shows last 5 Posts.', 'mainwp' ); ?></p>
			</li>
			<li data-id="recentposts_list" data-button="Next">
				<p><?php _e( 'The navigation at the top of the widget, allows you to toggle between Post Statuses.', 'mainwp' ); ?></p>
			</li>
			<li data-id="recentposts_list" data-button="Next" data-options="tipLocation:bottom;">
				<p><?php _e( 'The left column shows Post Title and in the action row, you can find actions for managing the post.', 'mainwp' ); ?></p>
				<p><?php _e( 'The middle column shows number of comments for the post.', 'mainwp' ); ?></p>
				<p><?php _e( 'The right column shows a Child Site where the post has been Published or updated along with the publishing date.', 'mainwp' ); ?></p>
			</li>
			<li data-id="toplevel_page_mainwp_tab-contentbox-4" data-button="Next">
				<p><?php _e( 'This widget allows you to see Pages that have been created recently on your Child Sites. It shows last 5 Pages.', 'mainwp' ); ?></p>
			</li>
			<li data-id="recentpages_list" data-button="Next">
				<p><?php _e( 'The navigation at the top of the widget, allows you to toggle between Page Statuses.', 'mainwp' ); ?></p>
			</li>
			<li data-id="recentpages_list" data-button="Next">
				<p><?php _e( 'The left column shows Page Title and in the action row, you can find actions for managing the page.', 'mainwp' ); ?></p>
				<p><?php _e( 'The middle column shows number of comments for the page.', 'mainwp' ); ?></p>
				<p><?php _e( 'The right column shows a Child Site where the page has been Published or updated along with the publishing date.', 'mainwp' ); ?></p>
			</li>
			<li data-id="toplevel_page_mainwp_tab-contentbox-5" data-button="Next">
				<p><?php _e( 'This widget provides you a brief overview of security issues that the MainWP Dashboard detects on your Child Sites.In the initial state of the widget, you can see a number of detected security issues, the Show All link that will expand the widget to show you more details and the Fix All button that will attempt to fix all detected security issues on all your Child Sites.', 'mainwp' ); ?></p>
			</li>
			<li data-id="securityissues_list" data-button="Next">
				<p><?php _e( 'After expanding the list, you will be able to see more details. Clieck the Show All and continue.', 'mainwp' ); ?></p>
			</li>
			<li data-id="wp_securityissues" data-button="Next" data-options="tipLocation:bottom;">
				<p><?php _e( 'The left column displays Child Site friendly name. If you click a Child Site name, the link will lead you to the Security Issues page, where you can see all detected issues and fix them one-by-one.', 'mainwp' ); ?></p>
				<p><?php _e( 'The middle column shows a number of detected issues on the Child Site.', 'mainwp' ); ?></p>
				<p><?php _e( 'The right column provides you a button to Fix All security issues on the Child Site.', 'mainwp' ); ?></p>
			</li>
			<li data-id="toplevel_page_mainwp_tab-contentbox-6" data-button="Next">
				<p><?php _e( 'This widget will show all installed MainWP Extensions.', 'mainwp' ); ?></p>
			</li>
			<li data-id="show-settings-link" data-button="Next" data-options="nubPosition:top-right;tipLocation:bottom;">
				<p><?php _e( 'Use the Screen Options menu to hide unwanted widgets or to change number of columns on the Overview page.', 'mainwp' ); ?></p>
			</li>
			<li data-id="contextual-help-link" data-button="Finish" data-options="nubPosition:top-right;tipLocation:bottom;">
				<p><?php _e( 'If you need any additional help, you can always find the MainWP Documentation in the Help menu.', 'mainwp' ); ?></p>
			</li>
		</ol>
		<?php echo self::gen_tour_message('overview'); ?>
		<script type="text/javascript">
			jQuery(window).load(function() {
				jQuery("#mainwp-tours-content").joyride({
					autoStart : false,
					tipLocation: 'top',
					tipAdjustmentY: -35
				});
				jQuery(document).on( 'click', '.mainwp_starttours', function(e) {
					jQuery('.metabox-holder div.postbox').each(function(){
						if (jQuery(this).hasClass('closed')) {
							jQuery(this).find('.handlediv').trigger("click");
						}
					});
					jQuery("#mainwp-tours-content").joyride();
					return false;
				});
			});
		</script>
		<?php
	}

	public static function renderCreateNewUserTours() {
		if ( !MainWP_Utility::showMainWPMessage( 'tour', 'new_user' ) ) {
			return;
		}
		?>
		<ol id="mainwp-tours-content" style="display: none">
			<li data-id="user_login" data-button="Next">
				<p><?php _e( 'Enter the new username you want to create on your child sites.', 'mainwp' ); ?></p>
			</li>
			<li data-id="email" data-button="Next">
				<p><?php _e( 'Enter the email address of the new user. If the email address already exists on your child site(s), the process will fail.', 'mainwp' ); ?></p>
			</li>
			<li data-id="first_name" data-button="Next">
				<p><?php _e( 'Enter the first name of the user.', 'mainwp' ); ?></p>
			</li>
			<li data-id="last_name" data-button="Next">
				<p><?php _e( 'Enter the last name of the user.', 'mainwp' ); ?></p>
			</li>
			<li data-id="url" data-button="Next">
				<p><?php _e( 'If the user owns a website, you can add the website URL here.', 'mainwp' ); ?></p>
			</li>
			<li data-class="wp-generate-pw" data-button="Next">
				<p><?php _e( 'Set a password for the user.', 'mainwp' ); ?></p>
			</li>
			<li data-id="send_password" data-button="Next">
				<p><?php _e( 'If you want to email the password to the newly created user, select this checkbox.', 'mainwp' ); ?></p>
			</li>
			<li data-id="s2id_role" data-button="Next">
				<p><?php _e( 'Set a role for this user.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp-select-sites-postbox" data-button="Next" data-options="tipLocation:left;">
				<p><?php _e( 'Select child sites where you want to create this user', 'mainwp' ); ?></p>
			</li>
			<li data-id="bulk_add_createuser" data-button="Finish" data-options="tipLocation:left;">
				<p><?php _e( 'Click this button to create the user.', 'mainwp' ); ?></p>
			</li>
		</ol>
		<?php echo self::gen_tour_message('new_user'); ?>
		<script type="text/javascript">
			jQuery(window).load(function() {
				jQuery("#mainwp-tours-content").joyride({
					autoStart : false,
					tipLocation: 'top',
					tipAdjustmentY: -35
				});
				jQuery(document).on( 'click', '.mainwp_starttours', function(e) {
					jQuery('.metabox-holder div.postbox').each(function(){
						if (jQuery(this).hasClass('closed')) {
							jQuery(this).find('.handlediv').trigger("click");
						}
					});
					jQuery("#mainwp-tours-content").joyride();
					return false;
				});
			});
		</script>
		<?php
	}

	public static function renderUsersImportTour() {
		if ( !MainWP_Utility::showMainWPMessage( 'tour', 'user_import' ) ) {
			return;
		}
		?>
		<ol id="mainwp-tours-content" style="display: none">
			<li data-id="mwp-adduser-contentbox-1" data-button="Next">
				<p><?php _e( 'Upload the preformated CSV file.', 'mainwp' ); ?></p>
				<p><em><?php _e( 'You can download the sample CSV file and see what is the proper way to format it.', 'mainwp' ); ?></em><p>
			</li>
			<li data-id="import_user_chk_header_first" data-button="Next">
				<p><?php _e( 'If you did not remove the header part of from the CSV file, leave this option selected.', 'mainwp' ); ?></p>
				<p><em><?php _e( 'The header part shows you the order of data that you need to use when formating the CSV file, it looks like this: "Username,E-mail,First Name,Last Name,Website,Password,Send Password,Role,Select sites, Select groups"', 'mainwp' ); ?></em></p>
			</li>
			<li data-id="bulk_import_createuser" data-button="Finish">
				<p><?php _e( 'Click the button to start the process.', 'mainwp' ); ?></p>
			</li>
		</ol>
		<?php echo self::gen_tour_message('user_import'); ?>
		<script type="text/javascript">
			jQuery(window).load(function() {
				jQuery("#mainwp-tours-content").joyride({
					autoStart : false,
					tipLocation: 'top',
					tipAdjustmentY: -35
				});
				jQuery(document).on( 'click', '.mainwp_starttours', function(e) {
					jQuery('.metabox-holder div.postbox').each(function(){
						if (jQuery(this).hasClass('closed')) {
							jQuery(this).find('.handlediv').trigger("click");
						}
					});
					jQuery("#mainwp-tours-content").joyride();
					return false;
				});
			});
		</script>
		<?php
	}

	public static function renderUpdateAdminPasswordTour() {
		if ( !MainWP_Utility::showMainWPMessage( 'tour', 'admin_pass' ) ) {
			return;
		}
		?>
		<ol id="mainwp-tours-content" style="display: none">
			<li data-id="mwp-password-contentbox-1" data-button="Next" data-options="tipLocation:right">
				<p><?php _e( 'While connecting your WordPress websites to your MainWP Dashboard, an Administrator user has been required to be added in the Add New Site form in order to establish the connection.', 'mainwp' ); ?></p>
				<p><?php _e( 'Here, you can update the password for this user.', 'mainwp' ); ?><p>
			</li>
			<li data-id="pass1-text" data-button="Next">
				<p><?php _e( 'Set a new password.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp-select-sites-postbox" data-button="Next" data-options="tipLocation:left;">
				<p><?php _e( 'Select your child sites.', 'mainwp' ); ?></p>
			</li>
			<li data-id="bulk_updateadminpassword" data-button="Finish" data-options="tipLocation:left;">
				<p><?php _e( 'Click the button to start the process.', 'mainwp' ); ?></p>
			</li>
		</ol>
		<?php echo self::gen_tour_message('admin_pass'); ?>
		<script type="text/javascript">
			jQuery(window).load(function() {
				jQuery("#mainwp-tours-content").joyride({
					autoStart : false,
					tipLocation: 'top',
					tipAdjustmentY: -35
				});
				jQuery(document).on( 'click', '.mainwp_starttours', function(e) {
					jQuery('.metabox-holder div.postbox').each(function(){
						if (jQuery(this).hasClass('closed')) {
							jQuery(this).find('.handlediv').trigger("click");
						}
					});
					jQuery("#mainwp-tours-content").joyride();
					return false;
				});
			});
		</script>
		<?php
	}

	public static function renderSearchUsersTours() {
		if ( !MainWP_Utility::showMainWPMessage( 'tour', 'search_user' ) ) {
			return;
		}
		?>
		<ol id="mainwp-tours-content" style="display: none">
			<li data-id="mainwp_user_role_administrator" data-button="Next">
				<p><?php _e( 'If you want to search users by role, select wanted roles here.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_search_users" data-button="Next">
				<p><?php _e( 'If you want to find a specific user on your child site, enter the user\'s username here', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp-select-sites-postbox" data-button="Next" data-options="tipLocation:left;">
				<p><?php _e( 'Select your child sites here.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_show_users" data-button="Finish" data-options="nubPosition:top-right;">
				<p><?php _e( 'Click the button to start the search process.', 'mainwp' ); ?></p>
			</li>
		</ol>
		<?php echo self::gen_tour_message('search_user'); ?>
		<script type="text/javascript">
			jQuery(window).load(function() {
				jQuery("#mainwp-tours-content").joyride({
					autoStart : false,
					tipLocation: 'top',
					tipAdjustmentY: -35
				});
				jQuery(document).on( 'click', '.mainwp_starttours', function(e) {
					jQuery('.metabox-holder div.postbox').each(function(){
						if (jQuery(this).hasClass('closed')) {
							jQuery(this).find('.handlediv').trigger("click");
						}
					});
					jQuery("#mainwp-tours-content").joyride();
					return false;
				});
			});
		</script>
		<?php
	}

	public static function renderSearchPluginsTours() {
		if ( !MainWP_Utility::showMainWPMessage( 'tour', 'search_plugin' ) ) {
			return;
		}
		?>
		<ol id="mainwp-tours-content" style="display: none">
			<li data-id="s2id_mainwp_plugin_search_by_status" data-button="Next">
				<p><?php _e( 'Select if you are searching for an active, inactive or all plugins.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_plugin_search_by_keyword" data-button="Next">
				<p><?php _e( 'If you are searching for a specific plugin, enter the plugin name here. If not, you can leave it blank and your MainWP Dashboard will display all plugins.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp-select-sites-postbox" data-button="Next" data-options="tipLocation:left;">
				<p><?php _e( 'Select your child sites here.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_show_plugins" data-button="Finish" data-options="tipLocation:left;">
				<p><?php _e( 'Click the button to complete the search process.', 'mainwp' ); ?></p>
			</li>
		</ol>
		<?php echo self::gen_tour_message('search_plugin'); ?>
		<script type="text/javascript">
			jQuery(window).load(function() {
				jQuery("#mainwp-tours-content").joyride({
					autoStart : false,
					tipLocation: 'top',
					tipAdjustmentY: -35
				});
				jQuery(document).on( 'click', '.mainwp_starttours', function(e) {
					jQuery('.metabox-holder div.postbox').each(function(){
						if (jQuery(this).hasClass('closed')) {
							jQuery(this).find('.handlediv').trigger("click");
						}
					});
					jQuery("#mainwp-tours-content").joyride();
					return false;
				});
			});
		</script>
		<?php
	}

	public static function renderSearchThemesTours() {
		if ( !MainWP_Utility::showMainWPMessage( 'tour', 'search_theme' ) ) {
			return;
		}
		?>
		<ol id="mainwp-tours-content" style="display: none">
			<li data-id="s2id_mainwp_theme_search_by_status" data-button="Next">
				<p><?php _e( 'Select if you are searching for an active, inactive or all themes.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_theme_search_by_keyword" data-button="Next">
				<p><?php _e( 'If you are searching for a specific theme, enter the theme name here. If not, you can leave it blank and your MainWP Dashboard will display all themes.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp-select-sites-postbox" data-button="Next" data-options="tipLocation:left;">
				<p><?php _e( 'Select your child sites here.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_show_themes" data-button="Finish" data-options="tipLocation:left;">
				<p><?php _e( 'Click the button to complete the search process.', 'mainwp' ); ?></p>
			</li>
		</ol>
		<?php echo self::gen_tour_message('search_theme'); ?>
		<script type="text/javascript">
			jQuery(window).load(function() {
				jQuery("#mainwp-tours-content").joyride({
					autoStart : false,
					tipLocation: 'top',
					tipAdjustmentY: -35
				});
				jQuery(document).on( 'click', '.mainwp_starttours', function(e) {
					jQuery('.metabox-holder div.postbox').each(function(){
						if (jQuery(this).hasClass('closed')) {
							jQuery(this).find('.handlediv').trigger("click");
						}
					});
					jQuery("#mainwp-tours-content").joyride();
					return false;
				});
			});
		</script>
		<?php
	}

	public static function renderSearchPagesTours() {
		if ( !MainWP_Utility::showMainWPMessage( 'tour', 'search_page' ) ) {
			return;
		}
		?>
		<ol id="mainwp-tours-content" style="display: none">
			<li data-id="mainwp_page_search_type_publish" data-button="Next">
				<p><?php _e( 'Select one or multiple statuses of pages that are you searching for.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_page_search_by_keyword" data-button="Next">
				<p><?php _e( 'If you are looking for pages with a specific word in it, enter the word here.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_page_search_by_dtsstart" data-button="Next">
				<p><?php _e( 'If you are looking for pages that have been published in a specific time, set the date range here.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_maximumPages" data-button="Next">
				<p><?php _e( 'This option controls the number of Pages returned from child sites when performing a search.', 'mainwp' ); ?></p>
				<p><?php _e( 'A large amount will decrease the speed and/or break communication between your MainWP Dashboard and Child Sites.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp-select-sites-postbox" data-button="Next" data-options="tipLocation:left;">
				<p><?php _e( 'Select your child sites here.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_show_pages" data-button="Finish" data-options="tipLocation:left;">
				<p><?php _e( 'Click the button to complete the search.', 'mainwp' ); ?></p>
			</li>
		</ol>
		<?php echo self::gen_tour_message('search_page'); ?>
		<script type="text/javascript">
			jQuery(window).load(function() {
				jQuery("#mainwp-tours-content").joyride({
					autoStart : false,
					tipLocation: 'top',
					tipAdjustmentY: -35
				});
				jQuery(document).on( 'click', '.mainwp_starttours', function(e) {
					jQuery('.metabox-holder div.postbox').each(function(){
						if (jQuery(this).hasClass('closed')) {
							jQuery(this).find('.handlediv').trigger("click");
						}
					});
					jQuery("#mainwp-tours-content").joyride();
					return false;
				});
			});
		</script>
		<?php
	}

	public static function renderSearchPostsTours() {
		if ( !MainWP_Utility::showMainWPMessage( 'tour', 'search_post' ) ) {
			return;
		}
		?>
		<ol id="mainwp-tours-content" style="display: none">
			<li data-id="mainwp_post_search_type_publish" data-button="Next">
				<p><?php _e( 'Select one or multiple statuses of posts that are you searching for.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_post_search_by_keyword" data-button="Next">
				<p><?php _e( 'If you are looking for posts with a specific word in it, enter the word here.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_post_search_by_dtsstart" data-button="Next">
				<p><?php _e( 'If you are looking for posts that have been published in a specific time, set the date range here.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_maximumPosts" data-button="Next">
				<p><?php _e( 'This option controls the number of Posts returned from child sites when performing a search.', 'mainwp' ); ?></p>
				<p><?php _e( 'A large amount will decrease the speed and/or break communication between your MainWP Dashboard and Child Sites.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp-select-sites-postbox" data-button="Next" data-options="tipLocation:left;">
				<p><?php _e( 'Select your child sites here.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_show_posts" data-button="Finish" data-options="tipLocation:left;">
				<p><?php _e( 'Click the button to complete the search.', 'mainwp' ); ?></p>
			</li>
		</ol>
		<?php echo self::gen_tour_message('search_post'); ?>
		<script type="text/javascript">
			jQuery(window).load(function() {
				jQuery("#mainwp-tours-content").joyride({
					autoStart : false,
					tipLocation: 'top',
					tipAdjustmentY: -35
				});
				jQuery(document).on( 'click', '.mainwp_starttours', function(e) {
					jQuery('.metabox-holder div.postbox').each(function(){
						if (jQuery(this).hasClass('closed')) {
							jQuery(this).find('.handlediv').trigger("click");
						}
					});
					jQuery("#mainwp-tours-content").joyride();
					return false;
				});
			});
		</script>
		<?php
	}

	public static function renderAddNewSiteTours() {
		if ( !MainWP_Utility::showMainWPMessage( 'tour', 'add_new_site' ) ) {
			return;
		}
		$url_home = "https://mainwp.com/help/";
		$help_urls = array(
			'add_site' => $url_home . '/docs/set-up-the-mainwp-plugin/add-site-to-your-dashboard/',
			'insatll_child_plugin' => $url_home . '/docs/set-up-the-mainwp-plugin/install-mainwp-child/',
			'add_site_potential_issues' => $url_home . '/docs/potential-issues/'
		);
		?>
		<ol id="mainwp-tours-content"  style="display: none">
			<li data-id="mainwp_managesites_add_new_form" data-button="Continue Tour">
				<h5><?php _e( 'Attention!', 'mainwp' ); ?></h5>
				<p><?php echo sprintf( __( 'Before trying to connect your website to your MainWP Dashboard, make sure that the %sMainWP Child plugin is installed and activated%s on the website.', 'mainwp' ), '<a href="' . $help_urls['insatll_child_plugin'] . '" target="_blank">' ,'</a>' ); ?></p>
			</li>
			<li data-id="mainwp_managesites_add_wpurl_protocol_wrap">
				<p><?php _e( 'Select your website protocol', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_managesites_add_wpurl">
				<p><?php _e( 'Enter your website URL', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_managesites_add_wpadmin">
				<p><?php _e( 'Enter a username of one of the website Administrator users', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_managesites_add_wpname">
				<p><?php _e( 'Enter the Friendly site name. Friendly site name is for internal use only. It is visible only to the MainWP Dashboard administrator.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_managesites_add_addgroups_wrap">
				<p><?php _e( 'Add the Child Site to a Group', 'mainwp' ); ?></p>
				<p><em><?php _e( 'In case you are managing large number of WordPress sites, it would be very useful for you to split them in different groups. Later, you will be able to make site selection by group which will speed up your work and make it much easier.', 'mainwp' ); ?></em></p>
			</li>
			<li data-id="mainwp_managesites_add_uniqueId">
				<p><?php _e( 'If you have set the Unique Security ID on your website that you are adding to your MainWP Dashboard, enter it in the Unique Security ID field. If you have not set the Unique Security ID on your website, skip this field and leave it blank.', 'mainwp' ); ?></p>
				<p><strong><?php _e( 'Not required!', 'mainwp' ); ?></strong></p>
			</li>
			<li data-id="mainwp_managesites_verify_certificate_wrap">
				<p><?php _e( 'If your website doesn\'t use SSL Certificate, skip this field and leave the default value.', 'mainwp' ); ?></p>
				<p><?php _e( 'If your website uses SSL Certificate, select if you wish your MainWP Dashboard to verify the certificate before connecting the website.', 'mainwp' ); ?></p>
				<p><strong><?php _e( 'If you are using an out of date or self-assigned SSL Certificate and you are having trouble with connecting a Child Site, try to disable the SSL Certificate verification and see if that helps.', 'mainwp' ); ?></strong></p>
			</li>
			<li data-id="mainwp_managesites_ssl_version_wrap">
				<p><?php _e( 'Select the SSL Version. If you don\'t know what is the SSL Version on your website or if there is no SSL Certificate on it, skip this field and leave the Auto Detect value.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_managesites_add_http_user">
				<p><?php _e( 'If you don\'t use HTTP Basic Authentication on your website, skip this field', 'mainwp' ); ?></p>
				<p><?php _e( 'In case your website is protected with the HTTP Basic Authentication, enter your HTTP username', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_managesites_add_http_pass">
				<p><?php _e( 'If you don\'t use HTTP Basic Authentication on your website, skip this field', 'mainwp' ); ?></p>
				<p><?php _e( 'In case your website is protected with the HTTP Basic Authentication, enter your HTTP password', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_addnew_sync_exts_settings_notice">
				<p><?php _e( 'Extensions that require a 3rd party plugin to be installed will allow you to automatically Install the plugin and synchronize default extension settings.', 'mainwp' ); ?></p>
				<p><?php _e( 'Extensions that don\'t integrate with any plugin will only allow you to synchronize extension default settings.', 'mainwp' ); ?></p>
			</li>
			<li data-id="mainwp_managesites_add" data-button="Finish">
				<p><?php _e( 'Click the button to complete the process.', 'mainwp' ); ?></p>
			</li>
		</ol>
		<?php echo self::gen_tour_message('add_new_site'); ?>
		<script type="text/javascript">
			<?php  if ( MainWP_DB::Instance()->getWebsitesCount() == 0 ) { ?>
			var autoStartTour = true;
			<?php } else { ?>
			var autoStartTour = false;
			<?php } ?>
			jQuery(window).load(function() {
				jQuery("#mainwp-tours-content").joyride({
					autoStart : autoStartTour,
					tipLocation: 'top',
					tipAdjustmentY: -35
				});
				jQuery(document).on( 'click', '.mainwp_starttours', function(e) {
					jQuery('.metabox-holder div.postbox').each(function(){
						if (jQuery(this).hasClass('closed')) {
							jQuery(this).find('.handlediv').trigger("click");
						}
					});
					jQuery("#mainwp-tours-content").joyride();
					return false;
				});
			});
		</script>
		<?php
	}
}