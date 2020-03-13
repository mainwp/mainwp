<?php

/**
 * MainWP Updates Page
 */
class MainWP_Updates {

	public static function getClassName() {
		return __CLASS__;
	}

	public static function init() {
		/**
		 * This hook allows you to render the Post page header via the 'mainwp-pageheader-updates' action.
		 *
		 * This hook is normally used in the same context of 'mainwp-getsubpages-updates'
		 *
		 * @see \MainWP_Updates::renderHeader
		 */
		add_action( 'mainwp-pageheader-updates', array( MainWP_Post::getClassName(), 'renderHeader' ) );

		/**
		 * This hook allows you to render the Updates page footer via the 'mainwp-pagefooter-updates' action.
		 *
		 * This hook is normally used in the same context of 'mainwp-getsubpages-updates'
		 *
		 * @see \MainWP_Updates::renderFooter
		 */
		add_action( 'mainwp-pagefooter-updates', array( MainWP_Post::getClassName(), 'renderFooter' ) );

		add_action( 'mainwp_help_sidebar_content', array( self::getClassName(), 'mainwp_help_content' ) ); // Hook the Help Sidebar content
	}

	public static function initMenu() {
		add_submenu_page( 'mainwp_tab', __( 'Updates', 'mainwp' ), '<span id="mainwp-Updates">' . __( 'Updates', 'mainwp' ) . '</span>', 'read', 'UpdatesManage', array(
			self::getClassName(),
			'render',
		) );

		MainWP_Menu::add_left_menu( array(
			'title'             => __( 'Updates', 'mainwp' ),
			'parent_key'        => 'mainwp_tab',
			'slug'              => 'UpdatesManage',
			'href'              => 'admin.php?page=UpdatesManage',
			'icon'              => '<i class="sync icon"></i>',
		), 1 ); // level 1
	}

	/**
	 * @param string $shownPage The page slug shown at this moment
	 */
	public static function renderHeader( $shownPage = '' ) {

		$params = array(
			'title' => __( 'Update Details', 'mainwp' ),
		);

		MainWP_UI::render_top_header( $params );
	}

	/**
	 * @param string $shownPage The page slug shown at this moment
	 */
	public static function renderFooter() {
		echo '</div>';
	}

	public static function render() {
		global $current_user;
		$current_wpid = MainWP_Utility::get_current_wpid();

		if ( $current_wpid ) {
			$sql = MainWP_DB::Instance()->getSQLWebsiteById( $current_wpid, false, array( 'premium_upgrades', 'plugins_outdate_dismissed', 'themes_outdate_dismissed', 'plugins_outdate_info', 'themes_outdate_info', 'favi_icon' ) );
		} else {
			// Support staging sites
			$staging_enabled = apply_filters('mainwp-extension-available-check', 'mainwp-staging-extension') || apply_filters('mainwp-extension-available-check', 'mainwp-timecapsule-extension');
			$is_staging      = 'no';
			if ( $staging_enabled ) {
				$staging_updates_view = get_user_option( 'mainwp_staging_options_updates_view', $current_user->ID );
				if ( 'staging' === $staging_updates_view ) {
					$is_staging = 'yes';
				}
			}
			// End Support staging sites
			$sql = MainWP_DB::Instance()->getSQLWebsitesForCurrentUser( false, null, 'wp.url', false, false, null, false, array( 'premium_upgrades', 'plugins_outdate_dismissed', 'themes_outdate_dismissed', 'plugins_outdate_info', 'themes_outdate_info', 'favi_icon' ), $is_staging );
		}

		$userExtension = MainWP_DB::Instance()->getUserExtension();
		$websites      = MainWP_DB::Instance()->query( $sql );

		if ( MAINWP_VIEW_PER_GROUP == $userExtension->site_view ) {
			$site_offset = array();
			$all_groups  = array();
			$groups      = MainWP_DB::Instance()->getGroupsForCurrentUser();
			foreach ( $groups as $group ) {
				$all_groups[ $group->id ] = $group->name;
			}

			$sites_in_groups  = array();
			$all_groups_sites = array();
			foreach ( $all_groups as $group_id => $group_name ) {
				$all_groups_sites[ $group_id ] = array();
				$group_sites                   = MainWP_DB::Instance()->getWebsitesByGroupId( $group_id );
				foreach ( $group_sites as $site ) {
					if ( ! isset( $sites_in_groups[ $site->id ] ) ) {
						$sites_in_groups[ $site->id ] = 1;
					}
					$all_groups_sites[ $group_id ][] = $site->id;
				}
				unset( $group_sites );
			}

			$sites_not_in_groups = array();
			$pos                 = 0;
			MainWP_DB::data_seek( $websites, 0 );
			while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
				$site_offset[ $website->id ] = $pos;
				$pos++;
				if ( ! isset( $sites_in_groups[ $website->id ] ) ) {
					$sites_not_in_groups[] = $website->id;
				}
			}

			// sites not in group put at array at index 0
			if ( 0 < count( $sites_not_in_groups ) ) {
				$all_groups_sites[0] = $sites_not_in_groups;
				$all_groups[0]       = __( 'Others', 'mainwp' );
			}
		}

		$total_themesIgnored = 0;
		$total_pluginsIgnored = 0;

		$decodedIgnoredPlugins = json_decode( $userExtension->ignored_plugins, true );
		$decodedIgnoredThemes  = json_decode( $userExtension->ignored_themes, true );
		$total_pluginsIgnored  = is_array( $decodedIgnoredPlugins ) ? count( $decodedIgnoredPlugins ) : 0;
		$total_themesIgnored   = is_array( $decodedIgnoredThemes ) ? count( $decodedIgnoredThemes ) : 0;

		$decodedDismissedPlugins = json_decode( $userExtension->dismissed_plugins, true );
		$decodedDismissedThemes  = json_decode( $userExtension->dismissed_themes, true );

		$total_wp_upgrades          = 0;
		$total_plugin_upgrades      = 0;
		$total_translation_upgrades = 0;
		$total_theme_upgrades       = 0;
		$total_sync_errors          = 0;
		$total_uptodate             = 0;
		$total_offline              = 0;
		$total_plugins_outdate      = 0;
		$total_themes_outdate       = 0;

		$allTranslations  = array();
		$translationsInfo = array();
		$allPlugins       = array();
		$pluginsInfo      = array();
		$allThemes        = array();
		$themesInfo       = array();

		$allPluginsOutdate  = array();
		$pluginsOutdateInfo = array();

		$allThemesOutdate  = array();
		$themesOutdateInfo = array();

		MainWP_DB::data_seek( $websites, 0 );

		while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {

			$pluginsIgnored_perSites          = array();
			$themesIgnored_perSites           = array();
			$pluginsIgnoredAbandoned_perSites = array();
			$themesIgnoredAbandoned_perSites  = array(); 

			$wp_upgrades = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'wp_upgrades' ), true );

			if ( $website->is_ignoreCoreUpdates ) {
				$wp_upgrades = array();
			}

			if ( is_array( $wp_upgrades ) && 0 < count( $wp_upgrades ) ) {
				$total_wp_upgrades ++;
			}

			$translation_upgrades = json_decode( $website->translation_upgrades, true );

			$plugin_upgrades = json_decode( $website->plugin_upgrades, true );
			if ( $website->is_ignorePluginUpdates ) {
				$plugin_upgrades = array();
			}

			$theme_upgrades = json_decode( $website->theme_upgrades, true );
			if ( $website->is_ignoreThemeUpdates ) {
				$theme_upgrades = array();
			}

			$decodedPremiumUpgrades = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'premium_upgrades' ), true );
			if ( is_array( $decodedPremiumUpgrades ) ) {
				foreach ( $decodedPremiumUpgrades as $crrSlug => $premiumUpgrade ) {
					$premiumUpgrade['premium'] = true;

					if ( 'plugin' === $premiumUpgrade['type'] ) {
						if ( ! is_array( $plugin_upgrades ) ) {
							$plugin_upgrades = array();
						}
						if ( ! $website->is_ignorePluginUpdates ) {
							$premiumUpgrade = array_filter( $premiumUpgrade );
							if ( ! isset( $plugin_upgrades[ $crrSlug ] ) ) {
								$plugin_upgrades[ $crrSlug ] = array();
							}
							$plugin_upgrades[ $crrSlug ] = array_merge( $plugin_upgrades[ $crrSlug ], $premiumUpgrade );
						}
					} elseif ( 'theme' === $premiumUpgrade['type'] ) {
						if ( ! is_array( $theme_upgrades ) ) {
							$theme_upgrades = array();
						}
						if ( ! $website->is_ignoreThemeUpdates ) {
							$theme_upgrades[ $crrSlug ] = $premiumUpgrade;
						}
					}
				}
			}

			if ( is_array( $translation_upgrades ) ) {
				$total_translation_upgrades += count( $translation_upgrades );
			}

			if ( is_array( $plugin_upgrades ) ) {
				$ignored_plugins = json_decode( $website->ignored_plugins, true );
				if ( is_array( $ignored_plugins ) ) {
					$plugin_upgrades = array_diff_key( $plugin_upgrades, $ignored_plugins );
				}

				$ignored_plugins = json_decode( $userExtension->ignored_plugins, true );
				if ( is_array( $ignored_plugins ) ) {
					$plugin_upgrades = array_diff_key( $plugin_upgrades, $ignored_plugins );
				}

				$total_plugin_upgrades += count( $plugin_upgrades );
			}

			if ( is_array( $theme_upgrades ) ) {
				$ignored_themes = json_decode( $website->ignored_themes, true );
				if ( is_array( $ignored_themes ) ) {
					$theme_upgrades = array_diff_key( $theme_upgrades, $ignored_themes );
				}

				$ignored_themes = json_decode( $userExtension->ignored_themes, true );
				if ( is_array( $ignored_themes ) ) {
					$theme_upgrades = array_diff_key( $theme_upgrades, $ignored_themes );
				}

				$total_theme_upgrades += count( $theme_upgrades );
			}

			$ignored_plugins = json_decode( $website->ignored_plugins, true );
			$ignored_themes  = json_decode( $website->ignored_themes, true );
			if ( is_array( $ignored_plugins ) ) {
				$ignored_plugins         = array_filter( $ignored_plugins );
				$pluginsIgnored_perSites = array_merge( $pluginsIgnored_perSites, $ignored_plugins );
			}
			if ( is_array( $ignored_themes ) ) {
				$ignored_themes         = array_filter( $ignored_themes );
				$themesIgnored_perSites = array_merge( $themesIgnored_perSites, $ignored_themes );
			}

			$ignoredAbandoned_plugins = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'plugins_outdate_dismissed' ), true );
			if ( is_array( $ignoredAbandoned_plugins ) ) {
				$ignoredAbandoned_plugins         = array_filter( $ignoredAbandoned_plugins );
				$pluginsIgnoredAbandoned_perSites = array_merge( $pluginsIgnoredAbandoned_perSites, $ignoredAbandoned_plugins );
			}
			$ignoredAbandoned_themes = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'themes_outdate_dismissed' ), true );
			if ( is_array( $ignoredAbandoned_themes ) ) {
				$ignoredAbandoned_themes         = array_filter( $ignoredAbandoned_themes );
				$themesIgnoredAbandoned_perSites = array_merge( $themesIgnoredAbandoned_perSites, $ignoredAbandoned_themes );
			}

			$plugins_outdate = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'plugins_outdate_info' ), true );
			$themes_outdate  = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'themes_outdate_info' ), true );

			if ( is_array( $plugins_outdate ) ) {
				if ( is_array( $ignoredAbandoned_plugins ) ) {
					$plugins_outdate = array_diff_key( $plugins_outdate, $ignoredAbandoned_plugins );
				}

				if ( is_array( $decodedDismissedPlugins ) ) {
					$plugins_outdate = array_diff_key( $plugins_outdate, $decodedDismissedPlugins );
				}

				$total_plugins_outdate += count( $plugins_outdate );
			}

			if ( is_array( $themes_outdate ) ) {
				if ( is_array( $themesIgnoredAbandoned_perSites ) ) {
					$themes_outdate = array_diff_key( $themes_outdate, $themesIgnoredAbandoned_perSites );
				}

				if ( is_array( $decodedDismissedThemes ) ) {
					$themes_outdate = array_diff_key( $themes_outdate, $decodedDismissedThemes );
				}

				$total_themes_outdate += count( $themes_outdate );
			}

			if ( MAINWP_VIEW_PER_PLUGIN_THEME == $userExtension->site_view ) {
				if ( is_array( $translation_upgrades ) ) {
					foreach ( $translation_upgrades as $translation_upgrade ) {
						$slug = esc_html( $translation_upgrade['slug'] );
						if ( ! isset( $allTranslations[ $slug ] ) ) {
							$allTranslations[ $slug ] = array(
								'name' => isset( $translation_upgrade['name'] ) ? esc_html( $translation_upgrade['name'] ) : $slug,
								'cnt'  => 1,
							);
						} else {
							$allTranslations[ $slug ]['cnt'] ++;
						}

						$translationsInfo[ $slug ] = array(
							'name'       => isset( $translation_upgrade['name'] ) ? esc_html( $translation_upgrade['name'] ) : $slug,
							'slug'       => $slug,
							'version'    => esc_html( $translation_upgrade['version'] ),
						);
					}
				}

				MainWP_Utility::array_sort( $allTranslations, 'name' );

				// Keep track of all the plugins & themes
				if ( is_array( $plugin_upgrades ) ) {
					foreach ( $plugin_upgrades as $slug => $plugin_upgrade ) {
						if ( ! isset( $allPlugins[ $slug ] ) ) {
							$allPlugins[ $slug ] = array(
								'name' => esc_html( $plugin_upgrade['Name'] ),
								'cnt'  => 1,
							);
						} else {
							$allPlugins[ $slug ]['cnt'] ++;
						}

						$pluginsInfo[ $slug ] = array(
							'name'       => esc_html( $plugin_upgrade['Name'] ),
							'slug'       => esc_html( $plugin_upgrade['update']['slug'] ),
							'premium'    => ( isset( $plugin_upgrade['premium'] ) ? $plugin_upgrade['premium'] : 0 ),
							'PluginURI'  => esc_html( $plugin_upgrade['PluginURI'] ),
						);
					}
				}

				MainWP_Utility::array_sort( $allPlugins, 'name' );

				if ( is_array( $theme_upgrades ) ) {
					foreach ( $theme_upgrades as $slug => $theme_upgrade ) {
						if ( ! isset( $allThemes[ $slug ] ) ) {
							$allThemes[ $slug ] = array(
								'name' => esc_html( $theme_upgrade['Name'] ),
								'cnt'  => 1,
							);
						} else {
							$allThemes[ $slug ]['cnt'] ++;
						}

						$themesInfo[ $slug ] = array(
							'name'       => esc_html( $theme_upgrade['Name'] ),
							'premium'    => ( isset( $theme_upgrade['premium'] ) ? esc_html( $theme_upgrade['premium'] ) : 0 ),
						);
					}
				}

				MainWP_Utility::array_sort( $allThemes, 'name' );

				if ( is_array( $plugins_outdate ) ) {
					foreach ( $plugins_outdate as $slug => $plugin_outdate ) {
						$slug = esc_html( $slug );
						if ( ! isset( $allPluginsOutdate[ $slug ] ) ) {
							$allPluginsOutdate[ $slug ] = array(
								'name' => esc_html( $plugin_outdate['Name'] ),
								'cnt'  => 1,
							);
						} else {
							$allPluginsOutdate[ $slug ]['cnt'] ++;
						}
						$pluginsOutdateInfo[ $slug ] = array(
							'Name'           => esc_html( $plugin_outdate['Name'] ),
							'last_updated'   => ( isset( $plugin_outdate['last_updated'] ) ? esc_html( $plugin_outdate['last_updated'] ) : 0 ),
							'info'           => $plugin_outdate,
							'uri'            => esc_html( $plugin_outdate['PluginURI'] ),
						);
					}
				}

				MainWP_Utility::array_sort( $allPluginsOutdate, 'name' );

				if ( is_array( $themes_outdate ) ) {
					foreach ( $themes_outdate as $slug => $theme_outdate ) {
						$slug = esc_html( $slug );
						if ( ! isset( $allThemesOutdate[ $slug ] ) ) {
							$allThemesOutdate[ $slug ] = array(
								'name' => esc_html( $theme_outdate['Name'] ),
								'cnt'  => 1,
							);
						} else {
							$allThemesOutdate[ $slug ]['cnt'] ++;
						}
						$themesOutdateInfo[ $slug ] = array(
							'name'           => esc_html( $theme_outdate['Name'] ),
							'slug'           => dirname( $slug ),
							'last_updated'   => ( isset( $theme_outdate['last_updated'] ) ? $theme_outdate['last_updated'] : 0 ),
						);
					}
				}

				MainWP_Utility::array_sort( $allThemesOutdate, 'name' );
			}

			if ( '' !== $website->sync_errors ) {
				$total_sync_errors ++;
			}
			if ( 1 === $website->uptodate ) {
				$total_uptodate ++;
			}
			if ( - 1 === $website->offline_check_result ) {
				$total_offline ++;
			}

			$total_pluginsIgnored += count( $pluginsIgnored_perSites );
			$total_themesIgnored  += count( $themesIgnored_perSites );
		}

		$total_upgrades = $total_wp_upgrades + $total_plugin_upgrades + $total_theme_upgrades;

		$mainwp_show_language_updates     = get_option( 'mainwp_show_language_updates', 1 );
		$user_can_update_translation      = mainwp_current_user_can( 'dashboard', 'update_translations' );
		$user_can_ignore_unignore_updates = mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' );
		$user_can_update_wordpress        = mainwp_current_user_can( 'dashboard', 'update_wordpress' );
		$user_can_update_themes           = mainwp_current_user_can( 'dashboard', 'update_themes' );
		$user_can_update_plugins          = mainwp_current_user_can( 'dashboard', 'update_plugins' );

		if ( $mainwp_show_language_updates ) {
			$total_upgrades += $total_translation_upgrades;
		}

		$visit_dashboard_title = __( 'Visit this dashboard', 'mainwp' );
		$visit_group_title     = __( 'Visit this group', 'mainwp' );
		$show_updates_title    = __( 'Click to see available updates', 'mainwp' );

		$trustedPlugins = json_decode( $userExtension->trusted_plugins, true );
		if ( ! is_array( $trustedPlugins ) ) {
			$trustedPlugins = array();
		}

		$trustedThemes = json_decode( $userExtension->trusted_themes, true );
		if ( ! is_array( $trustedThemes ) ) {
			$trustedThemes = array();
		}

		$trusted_label     = '<span class="ui tiny green label">Trusted</span>';
		$not_trusted_label = '<span class="ui tiny grey label">Not Trusted</span>';

		// the hook using to set maximum number of plugins/themes for huge number of updates
		$limit_updates_all = apply_filters( 'mainwp_limit_updates_all', 0 );
		$continue_update   = '';
		$continue_update_slug = '';
		$continue_class = '';

		if ( 0 < $limit_updates_all ) {
			if ( isset( $_GET['continue_update'] ) && '' !== $_GET['continue_update'] ) {
				$continue_update = $_GET['continue_update'];
				if ( 'plugins_upgrade_all' === $continue_update || 'themes_upgrade_all' === $continue_update || 'translations_upgrade_all' === $continue_update ) {
					if ( isset( $_GET['slug'] ) && '' !== $_GET['slug'] ) {
						$continue_update_slug = $_GET['slug'];
					}
				}
			}
		}

		$current_tab = '';

		if ( isset( $_GET['tab'] ) ) {
			if ( 'wordpress-updates' === $_GET['tab'] ) {
				$current_tab = 'wordpress-updates';
			} elseif ( 'plugins-updates' === $_GET['tab'] ) {
				$current_tab = 'plugins-updates';
			} elseif ( 'themes-updates' === $_GET['tab'] ) {
				$current_tab = 'themes-updates';
			} elseif ( 'translations-updates' === $_GET['tab'] ) {
				$current_tab = 'translations-updates';
			} elseif ( 'abandoned-plugins' === $_GET['tab'] ) {
				$current_tab = 'abandoned-plugins';
			} elseif ( 'abandoned-themes' === $_GET['tab'] ) {
				$current_tab = 'abandoned-themes';
			}
		} else {
			$current_tab = 'plugins-updates';
		}

		self::renderHeader( 'UpdatesManage' );

		if ( MainWP_Twitter::enabledTwitterMessages() ) {
			$filter = array(
				'upgrade_all_plugins',
				'upgrade_all_themes',
				'upgrade_all_wp_core',
			);
			foreach ( $filter as $what ) {
				$twitters = MainWP_Twitter::getTwitterNotice( $what );
				if ( is_array( $twitters ) ) {
					foreach ( $twitters as $timeid => $twit_mess ) {
						if ( ! empty( $twit_mess ) ) {
							$sendText = MainWP_Twitter::getTwitToSend( $what, $timeid );
							if ( ! empty( $sendText ) ) {
								?>
								<div class="mainwp-tips ui info message twitter" style="margin:0"><i class="ui close icon mainwp-dismiss-twit"></i><span class="mainwp-tip" twit-what="<?php echo $what; ?>" twit-id="<?php echo $timeid; ?>"><?php echo $twit_mess; ?></span>&nbsp;<?php MainWP_Twitter::genTwitterButton( $sendText ); ?></div>
								<?php
							}
						}
					}
				}
			}
		}

		?>
		<div id="mainwp-page-navigation-wrapper">
			<div class="ui secondary green pointing menu stackable mainwp-page-navigation">
				<a class="<?php echo( 'wordpress-updates' === $current_tab ? 'active' : '' ); ?> item" data-tab="wordpress-updates" href="admin.php?page=UpdatesManage&tab=wordpress-updates"><?php echo __( 'WordPress Updates', 'mainwp' ); ?><div class="ui small <?php echo 0 === $total_wp_upgrades ? 'green' : 'red'; ?> label"><?php echo $total_wp_upgrades; ?></div></a>
				<a class="<?php echo( 'plugins-updates' === $current_tab ? 'active' : '' ); ?> item" data-tab="plugins-updates" href="admin.php?page=UpdatesManage&tab=plugins-updates"><?php echo __( 'Plugins Updates', 'mainwp' ); ?><div class="ui small <?php echo 0 === $total_plugin_upgrades ? 'green' : 'red'; ?> label"><?php echo $total_plugin_upgrades; ?></div></a>
				<a class="<?php echo( 'themes-updates' === $current_tab ? 'active' : '' ); ?> item" data-tab="themes-updates" href="admin.php?page=UpdatesManage&tab=themes-updates"><?php echo __( 'Themes Updates', 'mainwp' ); ?><div class="ui small <?php echo 0 === $total_theme_upgrades ? 'green' : 'red'; ?> label"><?php echo $total_theme_upgrades; ?></div></a>
				<?php if ( $mainwp_show_language_updates ) : ?>
				<a class="<?php echo( 'translations-updates' === $current_tab ? 'active' : '' ); ?> item" data-tab="translations-updates" href="admin.php?page=UpdatesManage&tab=translations-updates"><?php echo __( 'Translations Updates', 'mainwp' ); ?><div class="ui small <?php echo 0 === $total_translation_upgrades ? 'green' : 'red'; ?> label"><?php echo $total_translation_upgrades; ?></div></a>
				<?php endif; ?>
				<a class="<?php echo( 'abandoned-plugins' === $current_tab ? 'active' : '' ); ?> item" data-tab="abandoned-plugins" href="admin.php?page=UpdatesManage&tab=abandoned-plugins"><?php echo __( 'Abandoned Plugins', 'mainwp' ); ?><div class="ui small <?php echo 0 === $total_plugins_outdate ? 'green' : 'red'; ?> label"><?php echo $total_plugins_outdate; ?></div></a>
				<a class="<?php echo( 'abandoned-themes' === $current_tab ? 'active' : '' ); ?> item" data-tab="abandoned-themes" href="admin.php?page=UpdatesManage&tab=abandoned-themes"><?php echo __( 'Abandoned Themes', 'mainwp' ); ?><div class="ui small <?php echo 0 === $total_themes_outdate ? 'green' : 'red'; ?> label"><?php echo $total_themes_outdate; ?></div></a>
			</div>
		</div>
		<div class="mainwp-sub-header">
		   <div class="ui grid">
				<div class="equal width row">
				<div class="middle aligned column">
						<?php echo apply_filters( 'mainwp_widgetupdates_actions_top', '' ); ?>
					</div>
					<div class="right aligned middle aligned column">
						<form method="post" action="" class="ui mini form">
							<div class="inline field">
								<label for="mainwp_select_options_siteview"><?php _e( 'Show updates per ', 'mainwp' ); ?></label>
								<select class="ui dropdown" onchange="mainwp_siteview_onchange(this)"  id="mainwp_select_options_siteview" name="select_mainwp_options_siteview">
									<option value="1" class="item" <?php echo MAINWP_VIEW_PER_SITE == $userExtension->site_view ? 'selected' : ''; ?>><?php esc_html_e( 'Site', 'mainwp' ); ?></option>
									<option value="0" class="item" <?php echo MAINWP_VIEW_PER_PLUGIN_THEME == $userExtension->site_view ? 'selected' : ''; ?>><?php esc_html_e( 'Plugin/Theme', 'mainwp' ); ?></option>
									<option value="2" class="item" <?php echo MAINWP_VIEW_PER_GROUP == $userExtension->site_view ? 'selected' : ''; ?>><?php esc_html_e( 'Group', 'mainwp' ); ?></option>
								</select>
							</div>
						</form>
					</div>
			  </div>
			</div>
	</div>
	<div class="ui segment" id="mainwp-manage-updates">
		<?php
			$enable_http_check = get_option( 'mainwp_check_http_response', 0 );
		if ( $enable_http_check ) {
			$enable_legacy_backup = get_option( 'mainwp_enableLegacyBackupFeature' );
			$mainwp_primaryBackup = get_option( 'mainwp_primaryBackup' );
			$customPage           = apply_filters( 'mainwp-getcustompage-backups', false );
			$restorePageSlug      = '';
			if ( empty( $enable_legacy_backup ) && ! empty( $mainwp_primaryBackup ) && is_array( $customPage ) && isset( $customPage['managesites_slug'] ) ) {
				$restorePageSlug = 'admin.php?page=ManageSites' . $customPage['managesites_slug'];
			} elseif ( $enable_legacy_backup ) {
				$restorePageSlug = 'admin.php?page=managesites';
			}
			?>
			<div class="" id="mainwp-http-response-issues">
				<table class="ui stackable single line red table" id="mainwp-http-response-issues-table">
					<thead>
						<tr>
							<th><?php echo __( 'Website', 'mainwp' ); ?></th>
							<th><?php echo __( 'HTTP Code', 'mainwp' ); ?></th>
							<th class="collapsing no-sort"><?php echo __( '', 'mainwp' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php
					MainWP_DB::data_seek( $websites, 0 );
					while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
						if ( 1 === $website->offline_check_result || '-1' === $website->http_response_code ) {
							continue;
						}

						$restoreSlug = '';

						if ( ! empty( $restorePageSlug ) ) {
							if ( $enable_legacy_backup ) {
								$restoreSlug = $restorePageSlug . '&backupid=' . $website->id;
							} elseif ( MainWP_Utility::activated_primary_backup_plugin( $mainwp_primaryBackup, $website ) ) {
								$restoreSlug = $restorePageSlug . '&id=' . $website->id;
							}
						}
						?>
						<tr>
							<td><a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"  data-inverted="" data-tooltip="<?php echo esc_attr( $visit_dashboard_title ); ?>"><?php echo stripslashes( $website->name ); ?></a></td>
							<td id="wp_http_response_code_<?php echo esc_attr( $website->id ); ?>">
								<label class="ui red label http-code"><?php echo 'HTTP ' . $website->http_response_code; ?></label>
							</td>
							<td class="right aligned">
								<a href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo esc_attr( $website->id ); ?>" class="ui mini button" target="_blank"><?php _e( 'WP Admin', 'mainwp' ); ?></a>
								<a href="javascript:void(0)" onclick="return updatesoverview_recheck_http( this, <?php echo esc_attr( $website->id ); ?> )" class="ui basic mini green button"><?php _e( 'Recheck', 'mainwp' ); ?></a>
								<a href="javascript:void(0)" onClick="return updatesoverview_ignore_http_response( this, <?php echo esc_attr( $website->id ); ?> )" class="ui basic mini button"><?php _e( 'Ignore', 'mainwp' ); ?></a>
						<?php if ( ! empty( $restoreSlug ) ) { ?>
								<a href="<?php echo $restoreSlug; ?>" class="ui green mini basic button"><?php _e( 'Restore', 'mainwp' ); ?></a>
							<?php } ?>
							</td>
						</tr>
						<?php
					}
					?>
					</tbody>
					<tfoot>
						<tr>
							<th><?php echo __( 'Website', 'mainwp' ); ?></th>
							<th><?php echo __( 'HTTP Code', 'mainwp' ); ?></th>
							<th class="collapsing no-sort"><?php echo __( '', 'mainwp' ); ?></th>
						</tr>
					</tfoot>
				</table>
				<script>
				jQuery( '#mainwp-http-response-issues-table' ).DataTable( {
					"searching": false,
					"paging" : false,
					"stateSave": true,
					"info" : false,
					"columnDefs" : [ { "orderable": false, "targets": "no-sort" } ],
					"language" : { "emptyTable": "No HTTP issues detected." }
			  } );
				</script>
			</div>
			<div class="ui hidden clearing divider"></div>
			<?php } ?>


			<!-- WordPress Updates -->

			<?php if ( 'wordpress-updates' === $current_tab ) : ?>
			<div class="ui <?php echo( 'wordpress-updates' === $current_tab ? 'active' : '' ); ?> tab" data-tab="wordpress-updates">
				<?php if ( MAINWP_VIEW_PER_GROUP == $userExtension->site_view ) : ?>
				<table class="ui stackable single line table" id="mainwp-wordpress-updates-groups-table"> <!-- Per Group table -->
					<thead>
						<tr>
							<th class="collapsing no-sort"></th>
							<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo __( 'Group', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
							<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo __( 'Updates', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
							<th class="no-sort right aligned">
								<?php
								if ( $user_can_update_wordpress ) {
									if ( 0 < $total_wp_upgrades ) {
										$continue_class = ( 'wpcore_global_upgrade_all' === $continue_update ) ? 'updatesoverview_continue_update_me' : '';
										?>
										<a class="ui green mini basic button" onclick="return updatesoverview_wordpress_global_upgrade_all();" href="javascript:void(0)" data-position="top right" data-tooltip="<?php esc_attr_e( 'Update WordPress Core files on all child sites.', 'mainwp' ); ?>" data-inverted=""><?php echo __( 'Update All Groups', 'mainwp' ); ?></a>
										<?php
									}
								}
								?>
							</th>
						</tr>
					</thead>
					<tbody class="ui accordion"> <!-- per group -->
					<?php foreach ( $all_groups_sites as $group_id => $site_ids ) : ?>
							<?php
							$total_group_wp_updates = 0;
							$group_name             = $all_groups[ $group_id ];
							?>
							<tr row-uid="uid_wp_upgrades_<?php echo esc_attr( $group_id ); ?>" class="ui title">
								<td  class="accordion-trigger"><i class="icon dropdown"></i></td>
								<td><?php echo stripslashes( $group_name ); ?></td>
								<td sort-value="0"><span total-uid="uid_wp_upgrades_<?php echo esc_attr( $group_id ); ?>" data-inverted="" data-tooltip="<?php echo esc_attr( $show_updates_title ); ?>"></span></td>
								<td class="right aligned">
									<?php if ( $user_can_update_wordpress ) : ?>
									<a href="javascript:void(0)" data-tooltip="<?php echo __( 'Update all sites in the group', 'mainwp' ); ?>" data-inverted="" data-position="left center" btn-all-uid="uid_wp_upgrades_<?php echo esc_attr( $group_id ); ?>" class="ui green button" onClick="return updatesoverview_wordpress_global_upgrade_all( <?php echo esc_attr( $group_id ); ?> )"><?php echo __( 'Update All', 'mainwp' ); ?></a>
									<?php endif; ?>
								</td>
							</tr>
							<tr style="display:none">
								<td colspan="4" class="ui content">
									<table id="mainwp-wordpress-updates-groups-inner-table" class="ui stackable single line table mainwp-per-group-table">
										<thead>
											<tr>
												<th><?php echo __( 'Website', 'mainwp' ); ?></th>
												<th><?php echo __( 'Version', 'mainwp' ); ?></th>
												<th class="no-sort"><?php echo __( 'Latest', 'mainwp' ); ?></th>
												<th class="no-sort"></th>
											</tr>
										</thead>
										<tbody id="update_wrapper_wp_upgrades_group_<?php echo esc_attr( $group_id ); ?>">
											<?php foreach ( $site_ids as $site_id ) : ?>
												<?php
												$seek = $site_offset[ $site_id ];
												MainWP_DB::data_seek( $websites, $seek );
												$website = MainWP_DB::fetch_object( $websites );
												if ( $website->is_ignoreCoreUpdates ) {
													continue;
												}

												$wp_upgrades = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'wp_upgrades' ), true );

												if ( ( 0 == count( $wp_upgrades ) ) && ( '' === $website->sync_errors ) ) {
													continue;
												}

												$total_group_wp_updates += 1;
												?>
												<tr class="mainwp-wordpress-update" site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>" updated="<?php echo ( 0 < count( $wp_upgrades ) ) ? '0' : '1'; ?>">
													<td>
														<a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"  data-inverted="" data-tooltip="<?php esc_attr_e( 'Go to the child site overview.', 'mainwp' ); ?>"><?php echo stripslashes( $website->name ); ?></a>
														<input type="hidden" id="wp-updated-<?php echo esc_attr( $website->id ); ?>" value="<?php echo ( 0 < count( $wp_upgrades ) ? '0' : '1' ); ?>" />
													</td>
													<td>
														<?php if ( 0 < count( $wp_upgrades ) ) : ?>
															<?php echo esc_html( $wp_upgrades['current'] ); ?>
														<?php endif; ?>
													</td>
													<td>
														<?php if ( 0 < count( $wp_upgrades ) ) : ?>
															<?php echo esc_html( $wp_upgrades['new'] ); ?>
														<?php endif; ?>
													</td>
													<td class="right aligned">
														<?php if ( $user_can_update_wordpress ) : ?>
															<?php if ( 0 < count( $wp_upgrades ) ) : ?>
																<a href="javascript:void(0)" data-tooltip="<?php echo __( 'Update', 'mainwp' ) . ' ' . $website->name; ?>" data-inverted="" data-position="left center" class="ui green button mini" onClick="return updatesoverview_upgrade(<?php echo esc_attr( $website->id ); ?>, this )"><?php _e( 'Update Now', 'mainwp' ); ?></a>
															<?php endif; ?>
														<?php endif; ?>
													</td>
												</tr>
											<?php endforeach; ?>
										</tbody>
									</table>
								</td>
							</tr>
							<input type="hidden" class="element_ui_view_values" elem-uid="uid_wp_upgrades_<?php echo esc_attr( $group_id ); ?>" total="<?php echo intval( $total_group_wp_updates ); ?>" can-update="<?php echo $user_can_update_wordpress ? 1 : 0; ?>">
						<?php endforeach; ?>
					</tbody>
					<tfoot>
						<tr>
							<th></th>
							<th><?php echo __( 'Group', 'mainwp' ); ?></th>
							<th><?php echo __( 'Updates', 'mainwp' ); ?></th>
							<th class="right aligned">
							</th>
						</tr>
					</tfoot>
				</table>
				<?php else : // not view per group ?>
					<?php MainWP_DB::data_seek( $websites, 0 ); ?>
				<table class="ui stackable single line table" id="mainwp-wordpress-updates-table"> <!-- Per Site table -->
					<thead>
						<tr>
							<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo __( 'Website', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
							<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo __( 'Version', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
							<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo __( 'Latest', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
							<th class="no-sort right aligned">
								<?php
								if ( $user_can_update_wordpress ) {
									if ( 0 < $total_wp_upgrades ) {
										$continue_class = ( 'wpcore_global_upgrade_all' === $continue_update ) ? 'updatesoverview_continue_update_me' : '';
										?>
										<a class="ui green mini basic button" onclick="return updatesoverview_wordpress_global_upgrade_all();" href="javascript:void(0)" data-position="top right" data-tooltip="<?php esc_attr_e( 'Update WordPress Core files on all child sites.', 'mainwp' ); ?>" data-inverted=""><?php echo __( 'Update All Sites', 'mainwp' ); ?></a>
										<?php
									}
								}
								?>
							</th>
						</tr>
					</thead>
					<tbody> <!-- per site or plugin -->
						<?php
						while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
							if ( $website->is_ignoreCoreUpdates ) {
								continue;
							}

							$wp_upgrades = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'wp_upgrades' ), true );

							if ( ( 0 === count( $wp_upgrades ) ) && ( '' === $website->sync_errors ) ) {
								continue;
							}
							?>
						<tr class="mainwp-wordpress-update" site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>" updated="<?php echo ( 0 < count( $wp_upgrades ) ) ? '0' : '1'; ?>">
							<td>
								<a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"  data-inverted="" data-tooltip="<?php esc_attr_e( 'Go to the child site overview.', 'mainwp' ); ?>"><?php echo stripslashes( $website->name ); ?></a>
								<input type="hidden" id="wp-updated-<?php echo esc_attr( $website->id ); ?>" value="<?php echo ( 0 < count( $wp_upgrades ) ? '0' : '1' ); ?>" />
							</td>
							<td>
								<?php if ( 0 < count( $wp_upgrades ) ) : ?>
									<?php echo esc_html( $wp_upgrades['current'] ); ?>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( 0 < count( $wp_upgrades ) ) : ?>
									<?php echo esc_html( $wp_upgrades['new'] ); ?>
								<?php endif; ?>
							</td>
							<td class="right aligned">
								<?php if ( $user_can_update_wordpress ) : ?>
									<?php if ( 0 < count( $wp_upgrades ) ) : ?>
										<a href="javascript:void(0)" data-tooltip="<?php echo __( 'Update', 'mainwp' ) . ' ' . $website->name; ?>" data-inverted="" data-position="left center" class="ui green button mini" onClick="return updatesoverview_upgrade(<?php echo esc_attr( $website->id ); ?>, this )"><?php _e( 'Update Now', 'mainwp' ); ?></a>
									<?php endif; ?>
								<?php endif; ?>
							</td>
						</tr>
							<?php
						}
						?>
					</tbody>
					<tfoot>
						<tr>
							<th><?php echo __( 'Website', 'mainwp' ); ?></th>
							<th><?php echo __( 'Current Version', 'mainwp' ); ?></th>
							<th><?php echo __( 'New Version', 'mainwp' ); ?></th>
							<th class="right aligned">
							</th>
						</tr>
					</tfoot>
				</table>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<!-- END WordPress Updates -->

			<!-- Plugins Updates -->

			<?php if ( 'plugins-updates' === $current_tab ) : ?>
			<div class="ui <?php echo( 'plugins-updates' === $current_tab ? 'active' : '' ); ?> tab" data-tab="plugins-updates">
				<?php
				if ( MAINWP_VIEW_PER_SITE == $userExtension->site_view ) :
					?>
			  <!-- Per Site -->
				<table class="ui stackable single line table" id="mainwp-plugins-updates-sites-table">
					<thead>
						<tr>
							<th class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
							<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo __( 'Website', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
							<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo $total_plugin_upgrades . ' ' . _n( 'Update', 'Updates', $total_plugin_upgrades, 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
							<th class="no-sort right aligned">
								<?php MainWP_UI::render_show_all_updates_button(); ?>
								<?php
								if ( $user_can_update_plugins ) {
									$continue_class = ( 'plugins_global_upgrade_all' === $continue_update ) ? 'updatesoverview_continue_update_me' : '';
									if ( 0 < $total_plugin_upgrades ) {
										?>
									<a href="javascript:void(0)" onClick="return updatesoverview_plugins_global_upgrade_all();" class="ui basic mini green button" data-tooltip="<?php _e( 'Update all plugins.', 'mainwp' ); ?>" data-inverted="" data-position="top right"><?php echo __( 'Update All Sites' ); ?></a>
										<?php
									}
								}
								?>
							</th>
						</tr>
					</thead>
					<tbody id="plugins-updates-global" class="ui accordion">
						<?php
						MainWP_DB::data_seek( $websites, 0 );
						while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
							if ( $website->is_ignorePluginUpdates ) {
								continue;
							}
							$plugin_upgrades        = json_decode( $website->plugin_upgrades, true );
							$decodedPremiumUpgrades = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'premium_upgrades' ), true );
							if ( is_array( $decodedPremiumUpgrades ) ) {
								foreach ( $decodedPremiumUpgrades as $crrSlug => $premiumUpgrade ) {
									$premiumUpgrade['premium'] = true;

									if ( 'plugin' === $premiumUpgrade['type'] ) {
										if ( ! is_array( $plugin_upgrades ) ) {
											$plugin_upgrades = array();
										}

										$premiumUpgrade = array_filter( $premiumUpgrade );
										if ( ! isset( $plugin_upgrades[ $crrSlug ] ) ) {
											$plugin_upgrades[ $crrSlug ] = array();
										}
										$plugin_upgrades[ $crrSlug ] = array_merge( $plugin_upgrades[ $crrSlug ], $premiumUpgrade );
									}
								}
							}
							$ignored_plugins = json_decode( $website->ignored_plugins, true );
							if ( is_array( $ignored_plugins ) ) {
								$plugin_upgrades = array_diff_key( $plugin_upgrades, $ignored_plugins );
							}

							$ignored_plugins = json_decode( $userExtension->ignored_plugins, true );
							if ( is_array( $ignored_plugins ) ) {
								$plugin_upgrades = array_diff_key( $plugin_upgrades, $ignored_plugins );
							}

							if ( ( 0 === count( $plugin_upgrades ) ) && ( '' === $website->sync_errors ) ) {
								continue;
							}
							?>
							<tr class="ui title">
								<td class="accordion-trigger"><i class="icon dropdown"></i></td>
								<td>
									<a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"  data-inverted="" data-tooltip="<?php echo esc_attr( $visit_dashboard_title ); ?>"><?php echo stripslashes( $website->name ); ?></a>
								</td>
								<td sort-value="<?php echo count( $plugin_upgrades ); ?>"><?php echo count( $plugin_upgrades ); ?> <?php echo _n( 'Update', 'Updates', count( $plugin_upgrades ), 'mainwp' ); ?></td>
								<td class="right aligned">
								<?php if ( $user_can_update_plugins ) : ?>
									<?php if ( 0 < count( $plugin_upgrades ) ) : ?>
										<a href="javascript:void(0)" class="ui mini green button" onClick="return updatesoverview_upgrade_plugin_all( <?php echo esc_attr( $website->id ); ?> )"><?php echo __( 'Update Now', 'mainwp' ); ?></a>
									<?php endif; ?>
								<?php endif; ?>
								</td>
							</tr>

							<tr style="display:none">
								<td colspan="4" class="ui content">
									<table id="mainwp-wordpress-updates-groups-inner-table" class="ui stackable single line table">
										<thead>
											<tr>
												<th><?php echo __( 'Plugin', 'mainwp' ); ?></th>
												<th><?php echo __( 'Version', 'mainwp' ); ?></th>
												<th class="no-sort"><?php echo __( 'Latest', 'mainwp' ); ?></th>
												<th><?php echo __( 'Trusted', 'mainwp' ); ?></th>
												<th class="no-sort"></th>
											</tr>
										</thead>
										<tbody class="plugins-bulk-updates" id="wp_plugin_upgrades_<?php echo intval( $website->id ); ?>" site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>">
											<?php foreach ( $plugin_upgrades as $slug => $plugin_upgrade ) : ?>
												<?php $plugin_name = urlencode( $slug ); ?>
												<tr plugin_slug="<?php echo $plugin_name; ?>" premium="<?php echo ( isset( $plugin_upgrade['premium'] ) ? esc_attr( $plugin_upgrade['premium'] ) : 0 ) ? 1 : 0; ?>" updated="0">
													<td>
														<a href="<?php echo admin_url() . 'plugin-install.php?tab=plugin-information&plugin=' . esc_attr( $plugin_upgrade['update']['slug'] ) . '&url=' . ( isset( $plugin_upgrade['PluginURI'] ) ? rawurlencode( $plugin_upgrade['PluginURI'] ) : '' ) . '&name=' . rawurlencode( $plugin_upgrade['Name'] ) . '&TB_iframe=true&width=772&height=887'; ?>" target="_blank" class="thickbox open-plugin-details-modal">
															<?php echo esc_html( $plugin_upgrade['Name'] ); ?>
														</a>
														<input type="hidden" id="wp_upgraded_plugin_<?php echo esc_attr( $website->id ); ?>_<?php echo $plugin_name; ?>" value="0" />
													</td>
													<td><?php echo esc_html( $plugin_upgrade['Version'] ); ?></td>
													<td>
														<a href="<?php echo admin_url() . 'plugin-install.php?tab=plugin-information&plugin=' . $plugin_upgrade['update']['slug'] . '&url=' . ( isset( $plugin_upgrade['PluginURI'] ) ? rawurlencode( $plugin_upgrade['PluginURI'] ) : '' ) . '&name=' . rawurlencode( $plugin_upgrade['Name'] ) . '&section=changelog&TB_iframe=true&width=772&height=887'; ?>" target="_blank" class="thickbox open-plugin-details-modal">
															<?php echo esc_html( $plugin_upgrade['update']['new_version'] ); ?>
														</a>
													</td>
													<td><?php echo ( in_array( $slug, $trustedPlugins ) ? $trusted_label : $not_trusted_label ); ?></td>
													<td class="right aligned">
														<?php if ( $user_can_ignore_unignore_updates ) : ?>
															<a href="javascript:void(0)" onClick="return updatesoverview_plugins_ignore_detail( '<?php echo $plugin_name; ?>', '<?php echo urlencode( $plugin_upgrade['Name'] ); ?>', <?php echo esc_attr( $website->id ); ?>, this )" class="ui mini button"><?php _e( 'Ignore Update', 'mainwp' ); ?></a>
														<?php endif; ?>
														<?php if ( $user_can_update_plugins ) : ?>
															<a href="javascript:void(0)" class="ui green mini button" onClick="return updatesoverview_upgrade_plugin( <?php echo esc_attr( $website->id ); ?>, '<?php echo $plugin_name; ?>' )"><?php _e( 'Update Now', 'mainwp' ); ?></a>
														<?php endif; ?>
													</td>
												</tr>
											<?php endforeach; ?>
										</tbody>
									</table>
								</td>
							</tr>
							<?php
						}
						?>
					</tbody>
					<tfoot>
						<tr>
							<th class="collapsing no-sort"></th>
							<th><?php echo __( 'Website', 'mainwp' ); ?></th>
							<th><?php echo $total_plugin_upgrades . ' ' . _n( 'Update', 'Updates', $total_plugin_upgrades, 'mainwp' ); ?></th>
							<th class="no-sort right aligned">
							</th>
						</tr>
					</tfoot>
				</table>
					<?php
				elseif ( MAINWP_VIEW_PER_GROUP == $userExtension->site_view ) :
					?>
				<!-- Per Group -->
				<table class="ui stackable single line table" id="mainwp-plugins-updates-groups-table">
					<thead>
						<tr>
							<th class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
							<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo __( 'Group', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
							<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo __( 'Updates', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
							<th class="no-sort right aligned">
								<?php MainWP_UI::render_show_all_updates_button(); ?>
								<?php
								if ( $user_can_update_plugins ) {
									$continue_class = ( 'plugins_global_upgrade_all' === $continue_update ) ? 'updatesoverview_continue_update_me' : '';
									if ( 0 < $total_plugin_upgrades ) {
										?>
									<a href="javascript:void(0)" onClick="return updatesoverview_plugins_global_upgrade_all();" class="ui basic mini green button" data-tooltip="<?php _e( 'Update all sites.', 'mainwp' ); ?>" data-inverted="" data-position="top right"><?php echo __( 'Update All Plugins' ); ?></a>
										<?php
									}
								}
								?>
							</th>
						</tr>
					</thead>
					<tbody id="plugins-updates-global" class="ui accordion">
						<?php foreach ( $all_groups_sites as $group_id => $site_ids ) : ?>
							<?php
							if ( empty( $site_ids ) ) {
								continue;
							}

							$total_group_plugin_updates = 0;
							$group_name                 = $all_groups[ $group_id ];
							?>
							<tr class="title" row-uid="uid_plugin_updates_<?php echo esc_attr( $group_id ); ?>">
								<td class="accordion-trigger"><i class="icon dropdown"></i></td>
								<td><?php echo stripslashes( $group_name ); ?></td>
								<td total-uid="uid_plugin_updates_<?php echo esc_attr( $group_id ); ?>" sort-value="0"></td>
								<td class="right aligned" >
								<?php if ( $user_can_update_plugins ) { ?>
										<a href="javascript:void(0)" btn-all-uid="uid_plugin_updates_<?php echo esc_attr( $group_id ); ?>" class="ui green mini button" onClick="return updatesoverview_plugins_global_upgrade_all( <?php echo esc_attr( $group_id ); ?> )"><?php echo __( 'Update All', 'mainwp' ); ?></a>
								<?php } ?>
								</td>
							</tr>
							<tr row-uid="uid_plugin_updates_<?php echo esc_attr( $group_id ); ?>" style="display:none">
								<td colspan="4" class="content">
									<table id="mainwp-wordpress-updates-sites-inner-table" class="ui stackable single line grey table mainwp-per-group-table">
										<thead>
											<tr>
												<th class="collapsing no-sort"></th>
												<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo __( 'Website', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
												<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo __( 'Updates', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
												<th class="collapsing no-sort"></th>
											</tr>
										</thead>
										<tbody  id="update_wrapper_plugin_upgrades_group_<?php echo esc_attr( $group_id ); ?>" >
											<?php	foreach ( $site_ids as $site_id ) : ?>
												<?php
												$seek = $site_offset[ $site_id ];
												MainWP_DB::data_seek( $websites, $seek );

												$website = MainWP_DB::fetch_object( $websites );
												if ( $website->is_ignorePluginUpdates ) {
													continue;
												}

												$plugin_upgrades        = json_decode( $website->plugin_upgrades, true );
												$decodedPremiumUpgrades = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'premium_upgrades' ), true );
												if ( is_array( $decodedPremiumUpgrades ) ) {
													foreach ( $decodedPremiumUpgrades as $crrSlug => $premiumUpgrade ) {
														$premiumUpgrade['premium'] = true;

														if ( 'plugin' === $premiumUpgrade['type'] ) {
															if ( ! is_array( $plugin_upgrades ) ) {
																$plugin_upgrades = array();
															}
															$premiumUpgrade              = array_filter( $premiumUpgrade );
															$plugin_upgrades[ $crrSlug ] = array_merge( $plugin_upgrades[ $crrSlug ], $premiumUpgrade );
														}
													}
												}

												$ignored_plugins = json_decode( $website->ignored_plugins, true );
												if ( is_array( $ignored_plugins ) ) {
													$plugin_upgrades = array_diff_key( $plugin_upgrades, $ignored_plugins );
												}

												$ignored_plugins = json_decode( $userExtension->ignored_plugins, true );
												if ( is_array( $ignored_plugins ) ) {
													$plugin_upgrades = array_diff_key( $plugin_upgrades, $ignored_plugins );
												}

												$total_group_plugin_updates += count( $plugin_upgrades );

												if ( ( 0 === count( $plugin_upgrades ) ) && ( '' === $website->sync_errors ) ) {
													continue;
												}
												?>
												<tr class="ui title">
													<td class="accordion-trigger"><i class="icon dropdown"></i></td>
													<td>
														<a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>" data-inverted="" data-tooltip="<?php echo esc_attr( $visit_dashboard_title ); ?>"><?php echo stripslashes( $website->name ); ?></a>
													</td>
													<td sort-value="<?php echo count( $plugin_upgrades ); ?>"><?php echo count( $plugin_upgrades ) . ' ' . _n( 'Update', 'Updates', count( $plugin_upgrades ), 'mainwp' ); ?></td>
													<td class="right aligned">
														<?php if ( $user_can_update_plugins ) : ?>
															<?php if ( 0 < count( $plugin_upgrades ) ) : ?>
																<a href="javascript:void(0)" class="ui green mini button" onClick="return updatesoverview_group_upgrade_plugin_all( <?php echo esc_attr( $website->id ); ?>, <?php echo esc_attr( $group_id ); ?>, this )"><?php echo __( 'Update All', 'mainwp' ); ?></a>
															<?php endif; ?>
														<?php endif; ?>
													</td>
												</tr>
												<tr style="display:none">
													<td colspan="4" class="content">
														<table id="mainwp-wordpress-updates-plugins-inner-table" class="ui stackable single line table">
															<thead>
																<tr>
																	<th><?php echo __( 'Plugin', 'mainwp' ); ?></th>
																	<th><?php echo __( 'Version', 'mainwp' ); ?></th>
																	<th class="no-sort"><?php echo __( 'Latest', 'mainwp' ); ?></th>
																	<th><?php echo __( 'Trusted', 'mainwp' ); ?></th>
																	<th class="no-sort"></th>
																</tr>
															</thead>
															<tbody class="plugins-bulk-updates"  id="wp_plugin_upgrades_<?php echo esc_attr( $website->id ); ?>_group_<?php echo esc_attr( $group_id ); ?>" site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>">
															<?php foreach ( $plugin_upgrades as $slug => $plugin_upgrade ) : ?>
																<?php $plugin_name = urlencode( $slug ); ?>
																<tr class="mainwp-plugin-update" plugin_slug="<?php echo $plugin_name; ?>" premium="<?php echo ( isset( $plugin_upgrade['premium'] ) ? $plugin_upgrade['premium'] : 0 ) ? 1 : 0; ?>" updated="0">
																	<td>
																		<a href="<?php echo admin_url() . 'plugin-install.php?tab=plugin-information&plugin=' . esc_attr( $plugin_upgrade['update']['slug'] ) . '&url=' . ( isset( $plugin_upgrade['PluginURI'] ) ? rawurlencode( $plugin_upgrade['PluginURI'] ) : '' ) . '&name=' . rawurlencode( $plugin_upgrade['Name'] ) . '&TB_iframe=true&width=772&height=887'; ?>" target="_blank" class="thickbox open-plugin-details-modal">
																			<?php echo esc_html( $plugin_upgrade['Name'] ); ?>
																		</a>
																		<input type="hidden" id="wp_upgraded_plugin_<?php echo esc_attr($website->id); ?>_group_<?php echo esc_attr( $group_id ); ?>_<?php echo $plugin_name; ?>" value="0"/>
																	</td>
																	<td><?php echo esc_html( $plugin_upgrade['Version'] ); ?></td>
																	<td>
																		<a href="<?php echo admin_url() . 'plugin-install.php?tab=plugin-information&plugin=' . $plugin_upgrade['update']['slug'] . '&url=' . ( isset( $plugin_upgrade['PluginURI'] ) ? rawurlencode( $plugin_upgrade['PluginURI'] ) : '' ) . '&name=' . rawurlencode( $plugin_upgrade['Name'] ) . '&section=changelog&TB_iframe=true&width=772&height=887'; ?>" target="_blank" class="thickbox open-plugin-details-modal">
																			<?php echo esc_html( $plugin_upgrade['update']['new_version'] ); ?>
																		</a>
																	</td>
																	<td><?php echo ( in_array( $slug, $trustedPlugins ) ? $trusted_label : $not_trusted_label ); ?></td>
																	<td class="right aligned">
																	<?php if ( $user_can_ignore_unignore_updates ) : ?>
																		<a href="javascript:void(0)" class="ui mini button" onClick="return updatesoverview_plugins_ignore_detail( '<?php echo $plugin_name; ?>', '<?php echo urlencode( $plugin_upgrade['Name'] ); ?>', <?php echo esc_attr( $website->id ); ?>, this )"><?php _e( 'Ignore Update', 'mainwp' ); ?></a>
																	<?php endif; ?>
																	<?php if ( $user_can_update_plugins ) : ?>
																		<a href="javascript:void(0)" class="ui mini green button" onClick="return updatesoverview_plugins_upgrade( '<?php echo $plugin_name; ?>', <?php echo esc_attr( $website->id ); ?> )"><?php _e( 'Update Now', 'mainwp' ); ?></a>
																	<?php endif; ?>
																	</td>
																</tr>
															<?php endforeach; ?>
															</tbody>
														</table>
													</td>
												</tr>
											<?php	endforeach; ?>
										</tbody>
										<tfoot>
											<tr>
												<th class="collapsing no-sort"></th>
												<th><?php echo __( 'Website', 'mainwp' ); ?></th>
												<th><?php echo __( 'Updates', 'mainwp' ); ?></th>
												<th class="collapsing no-sort"></th>
											</tr>
										</tfoot>
									</table>
								</td>
							</tr>
							<input type="hidden" class="element_ui_view_values" elem-uid="uid_plugin_updates_<?php echo esc_attr( $group_id ); ?>" total="<?php echo intval( $total_group_plugin_updates ); ?>" can-update="<?php echo $user_can_update_plugins ? 1 : 0; ?>">
						<?php endforeach; ?>
					</tbody>
					<tfoot>
						<tr>
							<th class="collapsing no-sort"></th>
							<th><?php echo __( 'Group', 'mainwp' ); ?></th>
							<th><?php echo $total_plugin_upgrades . ' ' . _n( 'Update', 'Updates', $total_plugin_upgrades, 'mainwp' ); ?></th>
							<th class="no-sort right aligned"></th>
						</tr>
					</tfoot>
				</table>
					<?php
				else :
					?>
				<!-- Per Item -->
				<table class="ui stackable single line table" id="mainwp-plugins-updates-table">
					<thead>
						<tr>
							<th class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
							<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo __( 'Plugin', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
							<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo $total_plugin_upgrades . ' ' . _n( 'Update', 'Updates', $total_plugin_upgrades, 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
							<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo __( 'Trusted', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
							<th class="no-sort right aligned">
								<?php MainWP_UI::render_show_all_updates_button(); ?>
								<?php
								if ( $user_can_update_plugins ) {
									$continue_class = ( 'plugins_global_upgrade_all' === $continue_update ) ? 'updatesoverview_continue_update_me' : '';
									if ( 0 < $total_plugin_upgrades ) {
										?>
									<a href="javascript:void(0)" onClick="return updatesoverview_plugins_global_upgrade_all();" class="ui basic mini green button" data-tooltip="<?php _e( 'Update all sites.', 'mainwp' ); ?>" data-inverted="" data-position="top right"><?php echo __( 'Update All Plugins' ); ?></a>
										<?php
									}
								}
								?>
							</th>
						</tr>
					</thead>
					<tbody id="plugins-updates-global" class="ui accordion">
						<?php foreach ( $allPlugins as $slug => $val ) : ?>
							<?php
							$cnt         = intval( $val['cnt'] );
							$plugin_name = urlencode( $slug );
							$trusted     = in_array( $slug, $trustedPlugins ) ? 1 : 0;
							?>
							<tr class="ui title">
								<td class="accordion-trigger"><i class="icon dropdown"></i></td>
								<td>
									<a href="<?php echo admin_url() . 'plugin-install.php?tab=plugin-information&plugin=' . esc_attr( $pluginsInfo[ $slug ]['slug'] ) . '&url=' . ( isset( $pluginsInfo[ $slug ]['PluginURI'] ) ? rawurlencode( $pluginsInfo[ $slug ]['PluginURI'] ) : '' ) . '&name=' . rawurlencode( $pluginsInfo[ $slug ]['name'] ) . '&TB_iframe=true&width=772&height=887'; ?>" target="_blank" class="thickbox open-plugin-details-modal">
										<?php echo esc_html( $pluginsInfo[ $slug ]['name'] ); ?>
									</a>
								</td>
								<td sort-value="<?php echo $cnt; ?>"><?php echo $cnt; ?> <?php echo _n( 'Update', 'Updates', $cnt, 'mainwp' ); ?></td>
								<td sort-value="<?php echo $trusted; ?>"><?php echo ( $trusted ? $trusted_label : $not_trusted_label ); ?></td>
								<td class="right aligned">
									<?php if ( $user_can_ignore_unignore_updates ) : ?>
										<a href="javascript:void(0)" class="ui mini button btn-update-click-accordion" onClick="return updatesoverview_plugins_ignore_all( '<?php echo $plugin_name; ?>', '<?php echo urlencode( $pluginsInfo[ $slug ]['name'] ); ?>', this )"><?php _e( 'Ignore Globally', 'mainwp' ); ?></a>
									<?php endif; ?>
									<?php if ( $user_can_update_plugins ) : ?>
										<?php if ( 0 < $cnt ) : ?>
											<?php $continue_class = ( 'plugins_upgrade_all' === $continue_update && $continue_update_slug == $slug && MAINWP_VIEW_PER_PLUGIN_THEME == $userExtension->site_view ) ? 'updatesoverview_continue_update_me' : ''; ?>
											<a href="javascript:void(0)" class="ui mini button green <?php echo $continue_class; ?>" onClick="return updatesoverview_plugins_upgrade_all( '<?php echo $plugin_name; ?>', '<?php echo urlencode( $pluginsInfo[ $slug ]['name'] ); ?>' )"><?php echo __( 'Update All', 'mainwp' ); ?></a>
										<?php endif; ?>
									<?php endif; ?>
								</td>
							</tr>

							<tr style="display:none" class="plugins-bulk-updates" plugin_slug="<?php echo $plugin_name; ?>" plugin_name="<?php echo urlencode( $pluginsInfo[ $slug ]['name'] ); ?>" premium="<?php echo $pluginsInfo[ $slug ]['premium'] ? 1 : 0; ?>">
								<td colspan="5" class="ui content">
									<table id="mainwp-plugins-updates-sites-inner-table" class="ui stackable single line table">
										<thead>
											<tr>
												<th><?php echo __( 'Website', 'mainwp' ); ?></th>
												<th><?php echo __( 'Version', 'mainwp' ); ?></th>
												<th class="no-sort"><?php echo __( 'Latest', 'mainwp' ); ?></th>
												<th><?php echo __( 'Trusted', 'mainwp' ); ?></th>
												<th class="no-sort"></th>
											</tr>
										</thead>
										<tbody plugin_slug="<?php echo $plugin_name; ?>">
											<?php
											$count_limit_updates = 0;
											MainWP_DB::data_seek( $websites, 0 );
											while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
												if ( $website->is_ignorePluginUpdates ) {
													continue;
												}
												$plugin_upgrades        = json_decode( $website->plugin_upgrades, true );
												$decodedPremiumUpgrades = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'premium_upgrades' ), true );
												if ( is_array( $decodedPremiumUpgrades ) ) {
													foreach ( $decodedPremiumUpgrades as $crrSlug => $premiumUpgrade ) {
														$premiumUpgrade['premium'] = true;
														if ( 'plugin' === $premiumUpgrade['type'] ) {
															if ( ! is_array( $plugin_upgrades ) ) {
																$plugin_upgrades = array();
															}
															$premiumUpgrade              = array_filter( $premiumUpgrade );
															$plugin_upgrades[ $crrSlug ] = array_merge( $plugin_upgrades[ $crrSlug ], $premiumUpgrade );
														}
													}
												}

												$ignored_plugins = json_decode( $website->ignored_plugins, true );
												if ( is_array( $ignored_plugins ) ) {
													$plugin_upgrades = array_diff_key( $plugin_upgrades, $ignored_plugins );
												}

												if ( ! isset( $plugin_upgrades[ $slug ] ) ) {
													continue;
												}
												$plugin_upgrade = $plugin_upgrades[ $slug ];
												?>
												<tr site_id="<?php echo esc_attr($website->id); ?>" site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>" updated="0">
													<td><a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"  data-inverted="" data-tooltip="<?php echo esc_attr( $visit_dashboard_title ); ?>"><?php echo stripslashes( $website->name ); ?></a></td>
													<td><?php echo esc_html( $plugin_upgrade['Version'] ); ?></td>
													<td>
														<a href="<?php echo admin_url() . 'plugin-install.php?tab=plugin-information&plugin=' . $plugin_upgrade['update']['slug'] . '&url=' . ( isset( $plugin_upgrade['PluginURI'] ) ? rawurlencode( $plugin_upgrade['PluginURI'] ) : '' ) . '&name=' . rawurlencode( $plugin_upgrade['Name'] ) . '&section=changelog&TB_iframe=true&width=772&height=887'; ?>" target="_blank" class="thickbox open-plugin-details-modal">
															<?php echo esc_html( $plugin_upgrade['update']['new_version'] ); ?>
														</a>
													</td>
													<td><?php echo ( in_array( $slug, $trustedPlugins ) ? $trusted_label : $not_trusted_label ); ?></td>
													<td class="right aligned">
													<?php if ( $user_can_ignore_unignore_updates ) : ?>
														<a href="javascript:void(0)" class="ui mini button" onClick="return updatesoverview_plugins_ignore_detail( '<?php echo $plugin_name; ?>', '<?php echo urlencode( $plugin_upgrade['Name'] ); ?>', <?php echo esc_attr($website->id); ?>, this )"><?php _e( 'Ignore Update', 'mainwp' ); ?></a>
													<?php endif; ?>
													<?php if ( $user_can_update_plugins ) : ?>
														<a href="javascript:void(0)" class="ui mini green button" onClick="return updatesoverview_plugins_upgrade( '<?php echo $plugin_name; ?>', <?php echo esc_attr( $website->id ); ?> )"><?php _e( 'Update Now', 'mainwp' ); ?></a>
													<?php endif; ?>
													</td>
												</tr>
												<?php
											}
											?>
										</tbody>
									</table>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
					<tfoot>
						<tr>
							<th class="collapsing no-sort"></th>
							<th><?php echo __( 'Plugin', 'mainwp' ); ?></th>
							<th><?php echo $total_plugin_upgrades . ' ' . _n( 'Update', 'Updates', $total_plugin_upgrades, 'mainwp' ); ?></th>
							<th><?php echo __( 'Trusted', 'mainwp' ); ?></th>
							<th class="no-sort right aligned"></th>
						</tr>
					</tfoot>
				</table>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<!-- END Plugins Updates -->

			<!-- Themes Updates -->

			<?php if ( 'themes-updates' === $current_tab ) : ?>
			<div class="ui <?php echo( 'themes-updates' === $current_tab ? 'active' : '' ); ?> tab" data-tab="themes-updates">
				<?php
				if ( MAINWP_VIEW_PER_SITE == $userExtension->site_view ) :
					?>
				<!-- Per Site -->
				<table class="ui stackable single line table" id="mainwp-themes-updates-sites-table">
					<thead>
						<tr>
							<th class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
							<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo __( 'Website', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
							<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo $total_theme_upgrades . ' ' . _n( 'Update', 'Updates', $total_theme_upgrades, 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
							<th class="no-sort right aligned">
								<?php MainWP_UI::render_show_all_updates_button(); ?>
								<?php
								if ( $user_can_update_themes ) {
									$continue_class = ( 'themes_global_upgrade_all' === $continue_update ) ? 'updatesoverview_continue_update_me' : '';
									if ( 0 < $total_theme_upgrades ) {
										?>
									<a href="javascript:void(0)" onClick="return updatesoverview_themes_global_upgrade_all();" class="ui basic mini green button" data-tooltip="<?php _e( 'Update all themes.', 'mainwp' ); ?>" data-inverted="" data-position="top right"><?php echo __( 'Update All Sites' ); ?></a>
										<?php
									}
								}
								?>
							</th>
						</tr>
					</thead>
					<tbody id="themes-updates-global" class="ui accordion">
						<?php
						MainWP_DB::data_seek( $websites, 0 );
						while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
							if ( $website->is_ignoreThemeUpdates ) {
								continue;
							}
							$theme_upgrades         = json_decode( $website->theme_upgrades, true );
							$decodedPremiumUpgrades = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'premium_upgrades' ), true );
							if ( is_array( $decodedPremiumUpgrades ) ) {
								foreach ( $decodedPremiumUpgrades as $crrSlug => $premiumUpgrade ) {
									$premiumUpgrade['premium'] = true;

									if ( 'theme' === $premiumUpgrade['type'] ) {
										if ( ! is_array( $theme_upgrades ) ) {
											$theme_upgrades = array();
										}

										$premiumUpgrade = array_filter( $premiumUpgrade );
										if ( ! isset( $theme_upgrades[ $crrSlug ] ) ) {
											$theme_upgrades[ $crrSlug ] = array();
										}
										$theme_upgrades[ $crrSlug ] = array_merge( $theme_upgrades[ $crrSlug ], $premiumUpgrade );
									}
								}
							}
							$ignored_themes = json_decode( $website->ignored_themes, true );
							if ( is_array( $ignored_themes ) ) {
								$theme_upgrades = array_diff_key( $theme_upgrades, $ignored_themes );
							}

							$ignored_themes = json_decode( $userExtension->ignored_themes, true );
							if ( is_array( $ignored_themes ) ) {
								$theme_upgrades = array_diff_key( $theme_upgrades, $ignored_themes );
							}

							if ( ( 0 === count( $theme_upgrades ) ) && ( '' === $website->sync_errors ) ) {
								continue;
							}
							?>
							<tr class="ui title">
								<td class="accordion-trigger"><i class="icon dropdown"></i></td>
								<td>
									<a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"  data-inverted="" data-tooltip="<?php echo esc_attr( $visit_dashboard_title ); ?>"><?php echo stripslashes( $website->name ); ?></a>
								</td>
								<td sort-value="<?php echo count( $theme_upgrades ); ?>"><?php echo count( $theme_upgrades ); ?> <?php echo _n( 'Update', 'Updates', count( $theme_upgrades ), 'mainwp' ); ?></td>
								<td class="right aligned">
								<?php if ( $user_can_update_themes ) : ?>
									<?php if ( 0 < count( $theme_upgrades ) ) : ?>
										<a href="javascript:void(0)" class="ui mini green button" onClick="return updatesoverview_upgrade_theme_all( <?php echo esc_attr( $website->id ); ?> )"><?php echo __( 'Update Now', 'mainwp' ); ?></a>
									<?php endif; ?>
								<?php endif; ?>
								</td>
							</tr>

							<tr style="display:none">
								<td colspan="4" class="ui content">
									<table id="mainwp-wordpress-updates-groups-inner-table" class="ui stackable single line table">
										<thead>
											<tr>
												<th><?php echo __( 'Theme', 'mainwp' ); ?></th>
												<th><?php echo __( 'Version', 'mainwp' ); ?></th>
												<th class="no-sort"><?php echo __( 'Latest', 'mainwp' ); ?></th>
												<th><?php echo __( 'Trusted', 'mainwp' ); ?></th>
												<th class="no-sort"></th>
											</tr>
										</thead>
										<tbody class="themes-bulk-updates" id="wp_theme_upgrades_<?php echo intval( $website->id ); ?>" site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>">
											<?php foreach ( $theme_upgrades as $slug => $theme_upgrade ) : ?>
												<?php $theme_name = urlencode( $slug ); ?>
												<tr theme_slug="<?php echo $theme_name; ?>" premium="<?php echo ( isset( $theme_upgrade['premium'] ) ? esc_attr( $theme_upgrade['premium'] ) : 0 ) ? 1 : 0; ?>" updated="0">
													<td>
														<?php echo esc_html( $theme_upgrade['Name'] ); ?>
														<input type="hidden" id="wp_upgraded_theme_<?php echo esc_attr( $website->id ); ?>_<?php echo $theme_name; ?>" value="0" />
													</td>
													<td><?php echo esc_html( $theme_upgrade['Version'] ); ?></td>
													<td><?php echo esc_html( $theme_upgrade['update']['new_version'] ); ?></td>
													<td><?php echo ( in_array( $slug, $trustedThemes ) ? $trusted_label : $not_trusted_label ); ?></td>
													<td class="right aligned">
														<?php if ( $user_can_ignore_unignore_updates ) : ?>
															<a href="javascript:void(0)" onClick="return updatesoverview_themes_ignore_detail( '<?php echo $theme_name; ?>', '<?php echo urlencode( $theme_upgrade['Name'] ); ?>', <?php echo esc_attr( $website->id ); ?>, this )" class="ui mini button"><?php _e( 'Ignore Update', 'mainwp' ); ?></a>
														<?php endif; ?>
														<?php if ( $user_can_update_themes ) : ?>
															<a href="javascript:void(0)" class="ui green mini button" onClick="return updatesoverview_upgrade_theme( <?php echo esc_attr( $website->id ); ?>, '<?php echo $theme_name; ?>' )"><?php _e( 'Update Now', 'mainwp' ); ?></a>
														<?php endif; ?>
													</td>
												</tr>
											<?php endforeach; ?>
										</tbody>
									</table>
								</td>
							</tr>
							<?php
						}
						?>
					</tbody>
					<tfoot>
						<tr>
							<th class="collapsing no-sort"></th>
							<th><?php echo __( 'Website', 'mainwp' ); ?></th>
							<th><?php echo $total_theme_upgrades . ' ' . _n( 'Update', 'Updates', $total_theme_upgrades, 'mainwp' ); ?></th>
							<th class="no-sort right aligned"></th>
						</tr>
					</tfoot>
				</table>
					<?php
				elseif ( MAINWP_VIEW_PER_GROUP == $userExtension->site_view ) :
					?>
				<!-- Per Group -->
				<table class="ui stackable single line table" id="mainwp-themes-updates-groups-table">
					<thead>
						<tr>
							<th class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
							<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo __( 'Group', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
							<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo __( 'Updates', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
							<th class="no-sort right aligned">
								<?php MainWP_UI::render_show_all_updates_button(); ?>
								<?php
								if ( $user_can_update_themes ) {
									$continue_class = ( 'themes_global_upgrade_all' === $continue_update ) ? 'updatesoverview_continue_update_me' : '';
									if ( 0 < $total_theme_upgrades ) {
										?>
									<a href="javascript:void(0)" onClick="return updatesoverview_themes_global_upgrade_all();" class="ui basic mini green button" data-tooltip="<?php _e( 'Update all sites.', 'mainwp' ); ?>" data-inverted="" data-position="top right"><?php echo __( 'Update All Themes' ); ?></a>
										<?php
									}
								}
								?>
							</th>
						</tr>
					</thead>
					<tbody id="themes-updates-global" class="ui accordion">
						<?php foreach ( $all_groups_sites as $group_id => $site_ids ) : ?>
							<?php
							if ( empty( $site_ids ) ) {
								continue;
							}

							$total_group_theme_updates = 0;
							$group_name                = $all_groups[ $group_id ];
							?>
							<tr class="title" row-uid="uid_theme_updates_<?php echo esc_attr( $group_id ); ?>">
								<td class="accordion-trigger"><i class="icon dropdown"></i></td>
								<td><?php echo stripslashes( $group_name ); ?></td>
								<td total-uid="uid_theme_updates_<?php echo esc_attr( $group_id ); ?>" sort-value="0"></td>
								<td class="right aligned" >
								<?php if ( $user_can_update_themes ) { ?>
								<a href="javascript:void(0)" btn-all-uid="uid_theme_updates_<?php echo esc_attr( $group_id ); ?>" class="ui green mini button" onClick="return updatesoverview_themes_global_upgrade_all( <?php echo esc_attr( $group_id ); ?> )"><?php echo __( 'Update All', 'mainwp' ); ?></a>
								<?php } ?>
								</td>
							</tr>
							<tr row-uid="uid_theme_updates_<?php echo esc_attr( $group_id ); ?>" style="display:none">
								<td colspan="4" class="content">
									<table id="mainwp-wordpress-updates-sites-inner-table" class="ui stackable single line grey table mainwp-per-group-table">
										<thead>
											<tr>
												<th class="collapsing no-sort"></th>
												<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo __( 'Website', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
												<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo __( 'Updates', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
												<th class="collapsing no-sort"></th>
											</tr>
										</thead>
										<tbody class="accordion" id="update_wrapper_theme_upgrades_group_<?php echo esc_attr( $group_id ); ?>">
											<?php	foreach ( $site_ids as $site_id ) : ?>
												<?php
												$seek = $site_offset[ $site_id ];
												MainWP_DB::data_seek( $websites, $seek );

												$website = MainWP_DB::fetch_object( $websites );
												if ( $website->is_ignoreThemeUpdates ) {
													continue;
												}

												$theme_upgrades         = json_decode( $website->theme_upgrades, true );
												$decodedPremiumUpgrades = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'premium_upgrades' ), true );
												if ( is_array( $decodedPremiumUpgrades ) ) {
													foreach ( $decodedPremiumUpgrades as $crrSlug => $premiumUpgrade ) {
														$premiumUpgrade['premium'] = true;

														if ( 'theme' === $premiumUpgrade['type'] ) {
															if ( ! is_array( $theme_upgrades ) ) {
																$theme_upgrades = array();
															}
															$premiumUpgrade             = array_filter( $premiumUpgrade );
															$theme_upgrades[ $crrSlug ] = array_merge( $theme_upgrades[ $crrSlug ], $premiumUpgrade );
														}
													}
												}

												$ignored_themes = json_decode( $website->ignored_themes, true );
												if ( is_array( $ignored_themes ) ) {
													$theme_upgrades = array_diff_key( $theme_upgrades, $ignored_themes );
												}

												$ignored_themes = json_decode( $userExtension->ignored_themes, true );
												if ( is_array( $ignored_themes ) ) {
													$theme_upgrades = array_diff_key( $theme_upgrades, $ignored_themes );
												}

												$total_group_theme_updates += count( $theme_upgrades );

												if ( ( 0 === count( $theme_upgrades ) ) && ( '' === $website->sync_errors ) ) {
													continue;
												}
												?>
												<tr class="ui title">
													<td class="accordion-trigger"><i class="icon dropdown"></i></td>
													<td>
														<a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>" data-inverted="" data-tooltip="<?php echo esc_attr( $visit_dashboard_title ); ?>"><?php echo stripslashes( $website->name ); ?></a>
													</td>
													<td sort-value="<?php echo count( $theme_upgrades ); ?>"><?php echo count( $theme_upgrades ) . ' ' . _n( 'Update', 'Updates', count( $theme_upgrades ), 'mainwp' ); ?></td>
													<td class="right aligned">
														<?php if ( $user_can_update_themes ) : ?>
															<?php if ( 0 < count( $theme_upgrades ) ) : ?>
																<a href="javascript:void(0)" class="ui green mini button" onClick="return updatesoverview_group_upgrade_theme_all( <?php echo esc_attr( $website->id ); ?>, <?php echo esc_attr( $group_id ); ?>, this )"><?php echo __( 'Update All', 'mainwp' ); ?></a>
															<?php endif; ?>
														<?php endif; ?>
													</td>
												</tr>
												<tr style="display:none">
													<td colspan="4" class="content">
														<table id="mainwp-wordpress-updates-themes-inner-table" class="ui stackable single line table">
															<thead>
																<tr>
																	<th><?php echo __( 'Theme', 'mainwp' ); ?></th>
																	<th><?php echo __( 'Version', 'mainwp' ); ?></th>
																	<th class="no-sort"><?php echo __( 'Latest', 'mainwp' ); ?></th>
																	<th><?php echo __( 'Trusted', 'mainwp' ); ?></th>
																	<th class="no-sort"></th>
																</tr>
															</thead>
															<tbody class="themes-bulk-updates" id="wp_theme_upgrades_<?php echo esc_attr( $website->id ); ?>_group_<?php echo esc_attr( $group_id ); ?>" site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>">
															<?php foreach ( $theme_upgrades as $slug => $theme_upgrade ) : ?>
																<?php $theme_name = urlencode( $slug ); ?>
																<tr class="mainwp-theme-update" theme_slug="<?php echo $theme_name; ?>" premium="<?php echo ( isset( $theme_upgrade['premium'] ) ? $theme_upgrade['premium'] : 0 ) ? 1 : 0; ?>" updated="0">
																	<td>
																		<?php echo esc_html( $theme_upgrade['Name'] ); ?>
																		<input type="hidden" id="wp_upgraded_theme_<?php echo esc_attr( $website->id ); ?>_group_<?php echo esc_attr( $group_id ); ?>_<?php echo $theme_name; ?>" value="0"/>
																	</td>
																	<td><?php echo esc_html( $theme_upgrade['Version'] ); ?></td>
																	<td><?php echo esc_html( $theme_upgrade['update']['new_version'] ); ?></td>
																	<td><?php echo ( in_array( $slug, $trustedThemes ) ? $trusted_label : $not_trusted_label ); ?></td>
																	<td class="right aligned">
																	<?php if ( $user_can_ignore_unignore_updates ) : ?>
																		<a href="javascript:void(0)" class="ui mini button" onClick="return updatesoverview_themes_ignore_detail( '<?php echo $theme_name; ?>', '<?php echo urlencode( $theme_upgrade['Name'] ); ?>', <?php echo esc_attr( $website->id ); ?>, this )"><?php _e( 'Ignore Update', 'mainwp' ); ?></a>
																	<?php endif; ?>
																	<?php if ( $user_can_update_themes ) : ?>
																		<a href="javascript:void(0)" class="ui mini green button" onClick="return updatesoverview_themes_upgrade( '<?php echo $theme_name; ?>', <?php echo esc_attr( $website->id ); ?> )"><?php _e( 'Update Now', 'mainwp' ); ?></a>
																	<?php endif; ?>
																	</td>
																</tr>
															<?php endforeach; ?>
															</tbody>
														</table>
													</td>
												</tr>
											<?php	endforeach; ?>
										</tbody>
										<thead>
											<tr>
												<th class="collapsing no-sort"></th>
												<th><?php echo __( 'Website', 'mainwp' ); ?></th>
												<th><?php echo __( 'Updates', 'mainwp' ); ?></th>
												<th class="collapsing no-sort"></th>
											</tr>
										</thead>
									</table>
								</td>
							</tr>
							<input type="hidden" class="element_ui_view_values" elem-uid="uid_theme_updates_<?php echo esc_attr( $group_id ); ?>" total="<?php echo intval( $total_group_theme_updates ); ?>" can-update="<?php echo $user_can_update_themes ? 1 : 0; ?>">
						<?php endforeach; ?>
					</tbody>
					<tfoot>
						<tr>
							<th class="collapsing no-sort"></th>
							<th><?php echo __( 'Group', 'mainwp' ); ?></th>
							<th><?php echo $total_theme_upgrades . ' ' . _n( 'Update', 'Updates', $total_theme_upgrades, 'mainwp' ); ?></th>
							<th class="no-sort right aligned"></th>
						</tr>
					</tfoot>
				</table>
					<?php
				else :
					?>
				<!-- Per Item -->
				<table class="ui stackable single line table" id="mainwp-themes-updates-table">
					<thead>
						<tr>
							<th class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
							<th class="handle-accordion-sorting indicator-accordion-sorting"><?php echo __( 'Theme', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
							<th class="handle-accordion-sorting indicator-accordion-sorting"><?php echo $total_theme_upgrades . ' ' . _n( 'Update', 'Updates', $total_theme_upgrades, 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
							<th class="handle-accordion-sorting indicator-accordion-sorting"><?php echo __( 'Trusted', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
							<th class="no-sort right aligned">
								<?php MainWP_UI::render_show_all_updates_button(); ?>
								<?php
								if ( $user_can_update_themes ) {
									$continue_class = ( 'themes_global_upgrade_all' === $continue_update ) ? 'updatesoverview_continue_update_me' : '';
									if ( 0 < $total_theme_upgrades ) {
										?>
									<a href="javascript:void(0)" onClick="return updatesoverview_themes_global_upgrade_all();" class="ui basic mini green button" data-tooltip="<?php _e( 'Update all sites.', 'mainwp' ); ?>" data-inverted="" data-position="top right"><?php echo __( 'Update All Themes' ); ?></a>
										<?php
									}
								}
								?>
							</th>
						</tr>
					</thead>
					<tbody id="themes-updates-global" class="ui accordion">
						<?php foreach ( $allThemes as $slug => $val ) : ?>
							<?php
							$cnt        = intval( $val['cnt'] );
							$theme_name = urlencode( $slug );
							$trusted    = in_array( $slug, $trustedThemes ) ? 1 : 0;
							?>
							<tr class="ui title">
								<td class="accordion-trigger"><i class="icon dropdown"></i></td>
								<td><?php echo esc_html( $themesInfo[ $slug ]['name'] ); ?></td>
								<td sort-value="<?php echo $cnt; ?>"><?php echo $cnt; ?> <?php echo _n( 'Update', 'Updates', $cnt, 'mainwp' ); ?></td>
								<td sort-value="<?php echo $trusted; ?>"><?php echo ( $trusted ? $trusted_label : $not_trusted_label ); ?></td>
								<td class="right aligned">
									<?php if ( $user_can_ignore_unignore_updates ) : ?>
										<a href="javascript:void(0)" class="ui mini button btn-update-click-accordion" onClick="return updatesoverview_themes_ignore_all( '<?php echo $theme_name; ?>', '<?php echo urlencode( $themesInfo[ $slug ]['name'] ); ?>', this )"><?php _e( 'Ignore Globally', 'mainwp' ); ?></a>
									<?php endif; ?>
									<?php if ( $user_can_update_themes ) : ?>
										<?php if ( 0 < $cnt ) : ?>
											<?php $continue_class = ( 'themes_upgrade_all' === $continue_update && $continue_update_slug == $slug && MAINWP_VIEW_PER_PLUGIN_THEME == $userExtension->site_view ) ? 'updatesoverview_continue_update_me' : ''; ?>
											<a href="javascript:void(0)" class="ui mini button green <?php echo $continue_class; ?>" onClick="return updatesoverview_themes_upgrade_all( '<?php echo $theme_name; ?>', '<?php echo urlencode( $themesInfo[ $slug ]['name'] ); ?>' )"><?php echo __( 'Update All', 'mainwp' ); ?></a>
										<?php endif; ?>
									<?php endif; ?>
								</td>
							</tr>

							<tr style="display:none" class="themes-bulk-updates" theme_slug="<?php echo $theme_name; ?>" theme_name="<?php echo urlencode( $themesInfo[ $slug ]['name'] ); ?>" premium="<?php echo $themesInfo[ $slug ]['premium'] ? 1 : 0; ?>">
								<td colspan="5" class="ui content">
									<table id="mainwp-themes-updates-sites-inner-table" class="ui stackable single line table">
										<thead>
											<tr>
												<th><?php echo __( 'Website', 'mainwp' ); ?></th>
												<th><?php echo __( 'Version', 'mainwp' ); ?></th>
												<th class="no-sort"><?php echo __( 'Latest', 'mainwp' ); ?></th>
												<th class="no-sort"></th>
											</tr>
										</thead>
										<tbody theme_slug="<?php echo $theme_name; ?>">
											<?php
											$count_limit_updates = 0;
											MainWP_DB::data_seek( $websites, 0 );
											while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
												if ( $website->is_ignoreThemeUpdates ) {
													continue;
												}
												$theme_upgrades         = json_decode( $website->theme_upgrades, true );
												$decodedPremiumUpgrades = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'premium_upgrades' ), true );
												if ( is_array( $decodedPremiumUpgrades ) ) {
													foreach ( $decodedPremiumUpgrades as $crrSlug => $premiumUpgrade ) {
														$premiumUpgrade['premium'] = true;
														if ( 'theme' === $premiumUpgrade['type'] ) {
															if ( ! is_array( $theme_upgrades ) ) {
																$theme_upgrades = array();
															}
															$premiumUpgrade             = array_filter( $premiumUpgrade );
															$theme_upgrades[ $crrSlug ] = array_merge( $theme_upgrades[ $crrSlug ], $premiumUpgrade );
														}
													}
												}

												$ignored_themes = json_decode( $website->ignored_themes, true );
												if ( is_array( $ignored_themes ) ) {
													$theme_upgrades = array_diff_key( $theme_upgrades, $ignored_themes );
												}

												if ( ! isset( $theme_upgrades[ $slug ] ) ) {
													continue;
												}
												$theme_upgrade = $theme_upgrades[ $slug ];
												?>
												<tr site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>" updated="0">
													<td><a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"  data-inverted="" data-tooltip="<?php echo esc_attr( $visit_dashboard_title ); ?>"><?php echo stripslashes( $website->name ); ?></a></td>
													<td><?php echo esc_html( $theme_upgrade['Version'] ); ?></td>
													<td><?php echo esc_html( $theme_upgrade['update']['new_version'] ); ?></td>
													<td class="right aligned">
													<?php if ( $user_can_ignore_unignore_updates ) : ?>
														<a href="javascript:void(0)" class="ui mini button" onClick="return updatesoverview_themes_ignore_detail( '<?php echo $theme_name; ?>', '<?php echo urlencode( $theme_upgrade['Name'] ); ?>', <?php echo esc_attr( $website->id ); ?>, this )"><?php _e( 'Ignore Update', 'mainwp' ); ?></a>
													<?php endif; ?>
													<?php if ( $user_can_update_themes ) : ?>
														<a href="javascript:void(0)" class="ui mini green button" onClick="return updatesoverview_themes_upgrade( '<?php echo $theme_name; ?>', <?php echo esc_attr( $website->id ); ?> )"><?php _e( 'Update Now', 'mainwp' ); ?></a>
													<?php endif; ?>
													</td>
												</tr>
												<?php
											}
											?>
										</tbody>
									</table>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
					<tfoot>
						<tr>
							<th class="collapsing no-sort"></th>
							<th><?php echo __( 'Theme', 'mainwp' ); ?></th>
							<th><?php echo $total_theme_upgrades . ' ' . _n( 'Update', 'Updates', $total_theme_upgrades, 'mainwp' ); ?></th>
							<th><?php echo __( 'Trusted', 'mainwp' ); ?></th>
							<th class="no-sort right aligned"></th>
						</tr>
					</tfoot>
				</table>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<!-- END Themes Updates -->

			<!-- Translatinos Updates -->

			<?php if ( 'translations-updates' === $current_tab ) : ?>
				<?php if ( 1 === $mainwp_show_language_updates ) : ?>
				<div class="ui <?php echo( 'translations-updates' === $current_tab ? 'active' : '' ); ?> tab" data-tab="translations-updates">
					<?php if ( MAINWP_VIEW_PER_SITE == $userExtension->site_view ) : ?>
						<table class="ui stackable single line table" id="mainwp-translations-sites-table">
							<thead>
								<tr>
									<th class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
									<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo __( 'Website', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
									<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo __( 'Updates', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
									<th class="right aligned">
										<?php MainWP_UI::render_show_all_updates_button(); ?>
										<?php if ( $user_can_update_translation ) : ?>
											<?php if ( 0 < $total_translation_upgrades ) : ?>
												<a href="javascript:void(0)" onClick="return updatesoverview_translations_global_upgrade_all();" class="ui button basic mini green" data-tooltip="<?php _e( 'Update all translations', 'mainwp' ); ?>" data-inverted="" data-position="top right"><?php echo __( 'Update All Sites', 'mainwp' ); ?></a>
											<?php endif; ?>
										<?php endif; ?>
									</th>
								</tr>
							</thead>
							<tbody id="translations-updates-global"  class="ui accordion">
								<?php
								MainWP_DB::data_seek( $websites, 0 );
								while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
									$translation_upgrades = json_decode( $website->translation_upgrades, true );
									if ( ( 0 === count( $translation_upgrades ) ) && ( '' === $website->sync_errors ) ) {
										continue;
									}
									?>
									<tr class="title">
										<td class="accordion-trigger"><i class="dropdown icon"></i></td>
										<td>
											<a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>" data-inverted="" data-tooltip="<?php echo esc_attr( $visit_dashboard_title ); ?>"><?php echo stripslashes( $website->name ); ?></a>
										</td>
										<td sort-value="<?php echo count( $translation_upgrades ); ?>">
											<?php echo count( $translation_upgrades ); ?> <?php echo _n( 'Update', 'Updates', count( $translation_upgrades ), 'mainwp' ); ?>
										</td>
										<td class="right aligned">
										<?php if ( $user_can_update_translation ) : ?>
											<?php if ( 0 < count( $translation_upgrades ) ) : ?>
													<a href="javascript:void(0)" class="ui mini green button" onClick="return updatesoverview_upgrade_translation_all( <?php echo esc_attr( $website->id ); ?> )"><?php echo __( 'Update All', 'mainwp' ); ?></a>
											<?php endif; ?>
										<?php endif; ?>
										</td>
									</tr>

									<tr style="display:none">
										<td colspan="4" class="content">
											<table class="ui stackable single line table" id="mainwp-translations-table">
												<thead>
													<tr>
														<th><?php echo __( 'Translation', 'mainwp' ); ?></th>
														<th><?php echo __( 'Version', 'mainwp' ); ?></th>
														<th class="collapsing no-sort"></th>
													</tr>
												</thead>
												<tbody class="translations-bulk-updates" id="wp_translation_upgrades_<?php echo esc_attr( $website->id ); ?>" site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>">
												<?php foreach ( $translation_upgrades as $translation_upgrade ) : ?>
													<?php
													$translation_name = isset( $translation_upgrade['name'] ) ? $translation_upgrade['name'] : $translation_upgrade['slug'];
													$translation_slug = $translation_upgrade['slug'];
													?>
													<tr translation_slug="<?php echo $translation_slug; ?>" updated="0">
														<td>
															<?php echo esc_html( $translation_name ); ?>
															<input type="hidden" id="wp_upgraded_translation_<?php echo esc_attr( $website->id ); ?>_<?php echo $translation_slug; ?>" value="0"/>
														</td>
														<td>
															<?php echo esc_html( $translation_upgrade['version'] ); ?>
														</td>
														<td class="right aligned">
															<?php if ( $user_can_update_translation ) { ?>
																<a href="javascript:void(0)" class="ui green mini button" onClick="return updatesoverview_translations_upgrade('<?php echo $translation_slug; ?>', <?php echo esc_attr( $website->id ); ?> )"><?php _e( 'Update Now', 'mainwp' ); ?></a>
															<?php } ?>
														</td>
													</tr>
												<?php endforeach; ?>
												</tbody>
												<tfoot>
													<tr>
														<th><?php echo __( 'Translation', 'mainwp' ); ?></th>
														<th><?php echo __( 'Version', 'mainwp' ); ?></th>
														<th class="collapsing no-sort"></th>
													</tr>
												</tfoot>
											</table>
										</td>
									</tr>
									<?php
								}
								?>
							</tbody>
							<tfoot>
								<tr>
									<th class="collapsing no-sort"></th>
									<th><?php echo __( 'Website', 'mainwp' ); ?></th>
									<th><?php echo __( 'Updates', 'mainwp' ); ?></th>
									<th class="right aligned"></th>
								</tr>
							</tfoot>
						</table>
					<?php elseif ( MAINWP_VIEW_PER_GROUP == $userExtension->site_view ) : ?>
						<table class="ui stackable single line table" id="mainwp-translations-groups-table">
							<thead>
								<tr>
									<th class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
									<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo __( 'Group', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
									<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo __( 'Updates', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
									<th class="right aligned">
										<?php MainWP_UI::render_show_all_updates_button(); ?>
										<?php if ( $user_can_update_translation ) : ?>
											<?php if ( 0 < $total_translation_upgrades ) : ?>
												<a href="javascript:void(0)" onClick="return updatesoverview_translations_global_upgrade_all();" class="ui button basic mini green" data-tooltip="<?php _e( 'Update all translations', 'mainwp' ); ?>" data-inverted="" data-position="top right"><?php echo __( 'Update All Sites', 'mainwp' ); ?></a>
											<?php endif; ?>
										<?php endif; ?>
									</th>
								</tr>
							</thead>
							<tbody id="translations-updates-global" class="ui accordion">
								<?php foreach ( $all_groups_sites as $group_id => $site_ids ) : ?>
									<?php
									$total_group_translation_updates = 0;
									$group_name                      = $all_groups[ $group_id ];
									?>
									<tr row-uid="uid_translation_updates_<?php echo esc_attr( $group_id ); ?>" class="ui title">
										<td class="accordion-trigger"><i class="dropdown icon"></i></td>
										<td><?php echo stripslashes( $group_name ); ?></td>
										<td total-uid="uid_translation_updates_<?php echo esc_attr( $group_id ); ?>" sort-value="0"></td>
										<td class="right aligned">
										<?php if ( $user_can_update_themes ) { ?>
											<a href="javascript:void(0)" btn-all-uid="uid_translation_updates_<?php echo esc_attr( $group_id ); ?>" class="ui green mini button"  onClick="return updatesoverview_translations_global_upgrade_all( <?php echo esc_attr( $group_id ); ?> )"><?php echo __( 'Update All', 'mainwp' ); ?></a>
										<?php } ?>
										</td>
									</tr>
									<tr style="display:none">
										<td colspan="4" class="ui content">
											<table class="ui stackable single line grey table mainwp-per-group-table" id="mainwp-translations-sites-table">
												<thead>
													<tr>
														<th class="collapsing no-sort"></th>
														<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo __( 'Website', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
														<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo __( 'Updates', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
														<th class="right aligned"></th>
													</tr>
												</thead>
												<tbody class="accordion" id="update_wrapper_translation_upgrades_group_<?php echo esc_attr( $group_id ); ?>" row-uid="uid_translation_updates_<?php echo esc_attr( $group_id ); ?>">
												<?php foreach ( $site_ids as $site_id ) : ?>
													<?php
													$seek = $site_offset[ $site_id ];
													MainWP_DB::data_seek( $websites, $seek );
													$website                          = MainWP_DB::fetch_object( $websites );
													$translation_upgrades             = json_decode( $website->translation_upgrades, true );
													$total_group_translation_updates += count( $translation_upgrades );

													if ( ( 0 === count( $translation_upgrades ) ) && ( '' === $website->sync_errors ) ) {
														continue;
													}
													?>
													<tr class="ui title">
														<td class="accordion-trigger"><i class="dropdown icon"></i></td>
														<td>
															<a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>" data-inverted="" data-tooltip="<?php echo esc_attr( $visit_dashboard_title ); ?>"><?php echo stripslashes( $website->name ); ?></a>
														</td>
														<td sort-value="<?php echo count( $translation_upgrades ); ?>">
															<?php echo _n( 'Update', 'Updates', count( $translation_upgrades ), 'mainwp' ); ?>
														</td>
														<td class="right aligned">
														<?php if ( $user_can_update_translation ) : ?>
															<?php if ( 0 < count( $translation_upgrades ) ) : ?>
																<a href="javascript:void(0)" class="ui green mini button" onClick="return updatesoverview_group_upgrade_translation_all( <?php echo esc_attr( $website->id ); ?>, <?php echo esc_attr( $group_id ); ?>, this )"><?php echo __( 'Update All', 'mainwp' ); ?></a>
															<?php endif; ?>
														<?php endif; ?>
														</td>
													</tr>
													<tr style="display:none">
														<td class="content" colspan="4">
															<table class="ui stackable single line table" id="mainwp-translations-table">
																<thead>
																	<tr>
																		<th><?php echo __( 'translationName', 'mainwp' ); ?></th>
																		<th><?php echo __( 'Version', 'mainwp' ); ?></th>
																		<th class="right aligned"></th>
																	</tr>
																</thead>
																<tbody id="wp_translation_upgrades_<?php echo esc_attr( $website->id ); ?>_group_<?php echo esc_attr( $group_id ); ?>" site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>">
																<?php foreach ( $translation_upgrades as $translation_upgrade ) : ?>
																	<?php
																	$translation_name = isset( $translation_upgrade['name'] ) ? $translation_upgrade['name'] : $translation_upgrade['slug'];
																	$translation_slug = $translation_upgrade['slug'];
																	?>
																	<tr class="mainwp-translation-update" translation_slug="<?php echo $translation_slug; ?>" updated="0">
																		<td>
																			<?php echo $translation_name; ?>
																			<input type="hidden" id="wp_upgraded_translation_<?php echo esc_attr( $website->id ); ?>_group_<?php echo esc_attr( $group_id ); ?>_<?php echo $translation_slug; ?>" value="0"/>
																		</td>
																		<td>
																			<?php echo esc_html( $translation_upgrade['version'] ); ?>
																		</td>
																		<td class="right aligned">
																		<?php if ( $user_can_update_translation ) : ?>
																			<a href="javascript:void(0)" class="ui green mini button" onClick="return updatesoverview_group_upgrade_translation( <?php echo esc_attr( $website->id ); ?>, '<?php echo $translation_slug; ?>', <?php echo esc_attr( $group_id ); ?> )"><?php _e( 'Update Now', 'mainwp' ); ?></a>
																		<?php endif; ?>
																		</td>
																	</tr>
																<?php endforeach; ?>
																</tbody>
															</table>
														</td>
													</tr>
													<?php endforeach; ?>
												</tbody>
											</table>
										</td>
									</tr>
									<input type="hidden" class="element_ui_view_values" elem-uid="uid_translation_updates_<?php echo esc_attr( $group_id ); ?>" total="<?php echo intval( $total_group_translation_updates ); ?>" can-update="<?php echo $user_can_update_translation ? 1 : 0; ?>">
								<?php endforeach; ?>
							</tbody>
							<tfoot>
								<tr>
									<th class="collapsing no-sort"></th>
									<th><?php echo __( 'Group', 'mainwp' ); ?></th>
									<th><?php echo __( 'Updates', 'mainwp' ); ?></th>
									<th class="right aligned">

									</th>
								</tr>
							</tfoot>
						</table>
						<?php
						else :
							?>
						<!-- Per Item -->
						<table class="ui stackable single line table" id="mainwp-translations-sites-table">
							<thead>
								<tr>
									<th class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
									<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo __( 'Translation', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
									<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo __( 'Updates', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
									<th class="right aligned">
										<?php MainWP_UI::render_show_all_updates_button(); ?>
										<?php if ( $user_can_update_translation ) : ?>
											<?php if ( 0 < $total_translation_upgrades ) : ?>
												<a href="javascript:void(0)" onClick="return updatesoverview_translations_global_upgrade_all();" class="ui button basic mini green" data-tooltip="<?php _e( 'Update all sites', 'mainwp' ); ?>" data-inverted="" data-position="top right"><?php echo __( 'Update All Translations', 'mainwp' ); ?></a>
											<?php endif; ?>
										<?php endif; ?>
									</th>
								</tr>
							</thead>
							<tbody id="translations-updates-global" class="ui accordion">
								<?php foreach ( $allTranslations as $slug => $val ) : ?>
									<?php $cnt = intval( $val['cnt'] ); ?>
									<tr class="title">
										<td class="accordion-trigger"><i class="dropdown icon"></i></td>
										<td><?php echo esc_html( $translationsInfo[ $slug ]['name'] ); ?></td>
										<td sort-value="<?php echo $cnt; ?>"><?php echo $cnt; ?> <?php echo _n( 'Update', 'Updates', $cnt, 'mainwp' ); ?></td>
										<td class="right aligned">
										<?php if ( $user_can_update_translation ) : ?>
											<?php if ( 0 < $cnt ) : ?>
												<?php $continue_class = ( 'translations_upgrade_all' === $continue_update && $continue_update_slug == $slug && MAINWP_VIEW_PER_PLUGIN_THEME == $userExtension->site_view ) ? 'updatesoverview_continue_update_me' : ''; ?>
												<a href="javascript:void(0)" class="ui mini button green <?php echo $continue_class; ?>" onClick="return updatesoverview_translations_upgrade_all( '<?php echo $slug; ?>', '<?php echo urlencode( $translationsInfo[ $slug ]['name'] ); ?>' )"><?php echo __( 'Update All', 'mainwp' ); ?></a>
											<?php endif; ?>
										<?php endif; ?>
										</td>
									</tr>
									<tr style="display:none">
										<td colspan="4" class="content">
											<table class="ui stackable single line table" id="mainwp-translations-sites-table">
												<thead>
													<tr>
														<th><?php echo __( 'Website', 'mainwp' ); ?></th>
														<th><?php echo __( 'Version', 'mainwp' ); ?></th>
														<th class="collapsing no-sort"></th>
													</tr>
												</thead>
												<tbody class="translations-bulk-updates" translation_slug="<?php echo $slug; ?>" translation_name="<?php echo urlencode( $translationsInfo[ $slug ]['name'] ); ?>">
													<?php
													MainWP_DB::data_seek( $websites, 0 );
													while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
														$translation_upgrades = json_decode( $website->translation_upgrades, true );
														$translation_upgrade  = null;
														foreach ( $translation_upgrades as $current_translation_upgrade ) {
															if ( $current_translation_upgrade['slug'] == $slug ) {
																$translation_upgrade = $current_translation_upgrade;
																break;
															}
														}
														if ( null === $translation_upgrade ) {
															continue;
														}
														?>
														<tr site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>" updated="0">
															<td><a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"  data-inverted="" data-tooltip="<?php echo esc_attr( $visit_dashboard_title ); ?>"><?php echo stripslashes( $website->name ); ?></a></td>
															<td><?php echo esc_html( $translation_upgrade['version'] ); ?></td>
															<td class="right aligned">
															<?php if ( $user_can_update_translation ) : ?>
																	<a href="javascript:void(0)" class="ui green mini button" onClick="return updatesoverview_upgrade_translation( <?php echo esc_attr( $website->id ); ?>, '<?php echo $slug; ?>' )"><?php _e( 'Update Now', 'mainwp' ); ?></a>
															<?php endif ?>
															</td>
														</tr>
														<?php
													}
													?>
												</tbody>
												<tfoot>
													<tr>
														<th><?php echo __( 'Website', 'mainwp' ); ?></th>
														<th><?php echo __( 'Version', 'mainwp' ); ?></th>
														<th class="collapsing no-sort"></th>
													</tr>
												</tfoot>
											</table>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
							<tfoot>
								<tr>
									<th class="collapsing no-sort"></th>
									<th><?php echo __( 'Translation', 'mainwp' ); ?></th>
									<th><?php echo __( 'Updates', 'mainwp' ); ?></th>
									<th class="right aligned"></th>
								</tr>
							</tfoot>
						</table>
					<?php endif; ?>
				</div>
				<?php endif; ?>
			<?php endif; ?>

			<!-- END Translatinos Updates -->

			<!-- Abandoned Plugins -->

			<?php if ( 'abandoned-plugins' === $current_tab ) : ?>
				<?php $str_format = __( 'Updated %s days ago', 'mainwp' ); ?>
			<div class="ui <?php echo( 'abandoned-plugins' === $current_tab ? 'active' : '' ); ?> tab" data-tab="abandoned-plugins">
				<?php
				if ( MAINWP_VIEW_PER_SITE == $userExtension->site_view ) :
					?>
				<!-- Per Site -->
				<table class="ui stackable single line table" id="mainwp-abandoned-plugins-sites-table">
					<thead>
						<tr>
							<th class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
							<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo __( 'Website', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
							<th class="right aligned indicator-accordion-sorting handle-accordion-sorting"><?php echo __( 'Abandoned', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
						</tr>
					</thead>
					<tbody class="ui accordion">
						<?php MainWP_DB::data_seek( $websites, 0 ); ?>
						<?php
						while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
							$plugins_outdate = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'plugins_outdate_info' ), true );

							if ( ! is_array( $plugins_outdate ) ) {
								$plugins_outdate = array();
							}

							$pluginsOutdateDismissed = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'plugins_outdate_dismissed' ), true );
							if ( is_array( $pluginsOutdateDismissed ) ) {
								$plugins_outdate = array_diff_key( $plugins_outdate, $pluginsOutdateDismissed );
							}

							if ( is_array( $decodedDismissedPlugins ) ) {
								$plugins_outdate = array_diff_key( $plugins_outdate, $decodedDismissedPlugins );
							}

							if ( 0 === count( $plugins_outdate ) ) {
								continue;
							}

							?>

							<tr class="title">
								<td class="accordion-trigger"><i class="icon dropdown"></i></td>
								<td>
									<a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"  data-inverted="" data-tooltip="<?php echo esc_attr( $visit_dashboard_title ); ?>"><?php echo stripslashes( $website->name ); ?></a>
								</td>
								<td class="right aligned" sort-value="<?php echo count( $plugins_outdate ); ?>"><?php echo count( $plugins_outdate ); ?> <?php echo _n( 'Plugin', 'Plugins', count( $plugins_outdate ), 'mainwp' ); ?></td>
							</tr>
							<tr style="display:none">
								<td colspan="3" class="content">
									<table class="ui stackable single line table" id="mainwp-abandoned-plugins-table">
										<thead>
											<tr>
												<th><?php echo __( 'Plugin', 'mainwp' ); ?></th>
												<th><?php echo __( 'Version', 'mainwp' ); ?></th>
												<th><?php echo __( 'Last Update', 'mainwp' ); ?></th>
												<th class="no-sort"></th>
											</tr>
										</thead>
										<tbody id="wp_plugins_outdate_<?php echo esc_attr( $website->id ); ?>" site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>">
											<?php foreach ( $plugins_outdate as $slug => $plugin_outdate ) : ?>
												<?php
												$plugin_name              = urlencode( $slug );
												$now                      = new \DateTime();
												$last_updated             = $plugin_outdate['last_updated'];
												$plugin_last_updated_date = new \DateTime( '@' . $last_updated );
												$diff_in_days             = $now->diff( $plugin_last_updated_date )->format( '%a' );
												$outdate_notice           = sprintf( $str_format, $diff_in_days );
												?>
												<tr dismissed="0">
													<td>
														<a href="<?php echo admin_url() . 'plugin-install.php?tab=plugin-information&plugin=' . dirname( $slug ) . '&url=' . ( isset( $plugin_outdate['PluginURI'] ) ? rawurlencode( $plugin_outdate['PluginURI'] ) : '' ) . '&name=' . rawurlencode( $plugin_outdate['Name'] ) . '&TB_iframe=true&width=772&height=887'; ?>" target="_blank" class="thickbox open-plugin-details-modal"><?php echo esc_html( $plugin_outdate['Name'] ); ?></a>
														<input type="hidden" id="wp_dismissed_plugin_<?php echo esc_attr( $website->id ); ?>_<?php echo $plugin_name; ?>" value="0"/>
													</td>
													<td><?php echo esc_html( $plugin_outdate['Version'] ); ?></td>
													<td><?php echo $outdate_notice; ?></td>
													<td class="right aligned" id="wp_dismissbuttons_plugin_<?php echo esc_attr( $website->id ); ?>_<?php echo $plugin_name; ?>">
														<?php if ( $user_can_ignore_unignore_updates ) { ?>
														<a href="javascript:void(0)" class="ui mini button" onClick="return updatesoverview_plugins_dismiss_outdate_detail( '<?php echo $plugin_name; ?>', '<?php echo urlencode( $plugin_outdate['Name'] ); ?>', <?php echo esc_attr( $website->id ); ?>, this )"><?php _e( 'Ignore Now', 'mainwp' ); ?></a>
													  <?php } ?>
													</td>
												</tr>
											<?php endforeach; ?>
										</tbody>
									</table>
								</td>
							</tr>
							<?php
						}
						?>
					</tbody>
					<tfoot>
						<tr>
							<th class="collapsing no-sort"></th>
							<th><?php echo __( 'Website', 'mainwp' ); ?></th>
							<th class="right aligned"><?php echo __( 'Abandoned', 'mainwp' ); ?></th>
						</tr>
					</tfoot>
				</table>
					<?php
				elseif ( MAINWP_VIEW_PER_GROUP == $userExtension->site_view ) :
					?>
				<!-- Per Group -->
				<table class="ui stackable single line table" id="mainwp-abandoned-plugins-groups-table">
					<thead>
						<tr>
							<th class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
							<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo __( 'Group', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
							<th class="right aligned indicator-accordion-sorting handle-accordion-sorting"><?php echo __( 'Abandoned', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
						</tr>
					</thead>
					<tbody class="ui accordion">
					<?php foreach ( $all_groups_sites as $group_id => $site_ids ) : ?>
						<?php
						$total_group_plugins_outdate = 0;
						$group_name                  = $all_groups[ $group_id ];
						?>
						<tr row-uid="uid_plugins_outdate_<?php echo esc_attr( $group_id ); ?>" class="title">
							<td class="accordion-trigger"><i class="dropdown icon"></i></td>
							<td><?php echo stripslashes( $group_name ); ?></td>
							<td class="right aligned" total-uid="uid_plugins_outdate_<?php echo esc_attr( $group_id ); ?>" sort-value="0"></td>
						</tr>
						<tr style="display:none" row-uid="uid_plugins_outdate_<?php echo esc_attr( $group_id ); ?>">
							<td colspan="3" class="content">
								<table class="ui stackable single line grey table mainwp-per-group-table" id="mainwp-abandoned-plugins-sites-table">
									<thead>
										<tr>
										<th class="collapsing no-sort"></th>
										<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo __( 'Website', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
										<th class="right aligned indicator-accordion-sorting handle-accordion-sorting"><?php echo __( 'Abandoned', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
										</tr>
									</thead>
									<tbody class="accordion">
									<?php foreach ( $site_ids as $site_id ) : ?>
										<?php
										$seek = $site_offset[ $site_id ];
										MainWP_DB::data_seek( $websites, $seek );

										$website = MainWP_DB::fetch_object( $websites );

										$plugins_outdate = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'plugins_outdate_info' ), true );

										if ( ! is_array( $plugins_outdate ) ) {
											$plugins_outdate = array();
										}

										if ( 0 < count( $plugins_outdate ) ) {
											$pluginsOutdateDismissed = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'plugins_outdate_dismissed' ), true );
											if ( is_array( $pluginsOutdateDismissed ) ) {
												$plugins_outdate = array_diff_key( $plugins_outdate, $pluginsOutdateDismissed );
											}

											if ( is_array( $decodedDismissedPlugins ) ) {
												$plugins_outdate = array_diff_key( $plugins_outdate, $decodedDismissedPlugins );
											}
										}

										$total_group_plugins_outdate += count( $plugins_outdate );
										?>
										<?php if ( 0 < count( $plugins_outdate ) ) : ?>
										<tr class="ui title">
											<td class="accordion-trigger"><i class="dropdown icon"></i></td>
											<td>
												<a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"  data-inverted="" data-tooltip="<?php echo esc_attr( $visit_dashboard_title ); ?>"><?php echo stripslashes( $website->name ); ?></a>
											</td>
											<td class="right aligned" sort-value="<?php echo count( $plugins_outdate ); ?>">
												<?php echo count( $plugins_outdate ); ?> <?php echo _n( 'Plugin', 'Plugins', count( $plugins_outdate ), 'mainwp' ); ?>
											</td>
										</tr>
										<tr style="display:none">
											<td colspan="3" class="ui content">
												<table class="ui stackable single line table" id="mainwp-abandoned-plugins-table">
													<thead>
														<tr>
															<tr>
																<th><?php echo __( 'Plugin', 'mainwp' ); ?></th>
																<th><?php echo __( 'Version', 'mainwp' ); ?></th>
																<th><?php echo __( 'Last Update', 'mainwp' ); ?></th>
																<th class="no-sort"></th>
															</tr>
														</tr>
													</thead>
													<tbody site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>">
														<?php foreach ( $plugins_outdate as $slug => $plugin_outdate ) : ?>
															<?php
															$plugin_name              = urlencode( $slug );
															$now                      = new \DateTime();
															$last_updated             = $plugin_outdate['last_updated'];
															$plugin_last_updated_date = new \DateTime( '@' . $last_updated );
															$diff_in_days             = $now->diff( $plugin_last_updated_date )->format( '%a' );
															$outdate_notice           = sprintf( $str_format, $diff_in_days );
															?>
															<tr dismissed="0">
																<td>
																	<a href="<?php echo admin_url() . 'plugin-install.php?tab=plugin-information&plugin=' . dirname( $slug ) . '&url=' . ( isset( $plugin_outdate['PluginURI'] ) ? rawurlencode( $plugin_outdate['PluginURI'] ) : '' ) . '&name=' . rawurlencode( $plugin_outdate['Name'] ) . '&TB_iframe=true&width=772&height=887'; ?>" target="_blank" class="thickbox open-plugin-details-modal"><?php echo esc_html( $plugin_outdate['Name'] ); ?></a>
																	<input type="hidden" id="wp_dismissed_plugin_<?php echo esc_attr( $website->id ); ?>_<?php echo $plugin_name; ?>" value="0"/>
																</td>
																<td><?php echo esc_html( $plugin_outdate['Version'] ); ?></td>
																<td><?php echo $outdate_notice; ?></td>
																<td class="right aligned" id="wp_dismissbuttons_plugin_<?php echo esc_attr( $website->id ); ?>_<?php echo $plugin_name; ?>">
																	<?php if ( $user_can_ignore_unignore_updates ) { ?>
																	<a href="javascript:void(0)" class="ui mini button" onClick="return updatesoverview_plugins_dismiss_outdate_detail( '<?php echo $plugin_name; ?>', '<?php echo urlencode( $plugin_outdate['Name'] ); ?>', <?php echo esc_attr( $website->id ); ?>, this )"><?php _e( 'Ignore Now', 'mainwp' ); ?></a>
																  <?php } ?>
																</td>
															</tr>
														<?php endforeach; ?>
													</tbody>
												</table>
											</td>
										</tr>
										<?php endif; ?>
									<?php endforeach; ?>
									</tbody>
								</table>
							</td>
						</tr>
						<input type="hidden" class="element_ui_view_values" elem-uid="uid_plugins_outdate_<?php echo esc_attr( $group_id ); ?>" total="<?php echo intval( $total_group_plugins_outdate ); ?>" can-update="0">
					<?php endforeach; ?>
					</tbody>
					<tfoot>
						<tr>
							<th class="collapsing no-sort"></th>
							<th><?php echo __( 'Group', 'mainwp' ); ?></th>
							<th class="right aligned"><?php echo __( 'Abandoned', 'mainwp' ); ?></th>
						</tr>
					</tfoot>
				</table>
					<?php
				else :
					?>
				<!-- Per Item -->
				<table class="ui stackable single line table" id="mainwp-abandoned-plugins-items-table">
					<thead>
						<tr>
							<th class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
							<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo __( 'Plugin', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
							<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo __( 'Abandoned', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
							<th class="collapsing no-sort">
								<?php MainWP_UI::render_show_all_updates_button(); ?>
							</th>
						</tr>
					</thead>
					<tbody class="ui accordion">
					<?php foreach ( $allPluginsOutdate as $slug => $val ) : ?>
						<?php
						$cnt         = intval( $val['cnt'] );
						$plugin_name = urlencode( $slug );
						?>
						<tr class="title">
							<td class="accordion-trigger"><i class="dropdown icon"></i></td>
							<td><a href="<?php echo admin_url() . 'plugin-install.php?tab=plugin-information&plugin=' . dirname( $slug ) . '&url=' . ( isset( $pluginsOutdateInfo[ $slug ]['uri'] ) ? rawurlencode( $pluginsOutdateInfo[ $slug ]['uri'] ) : '' ) . '&name=' . rawurlencode( $pluginsOutdateInfo[ $slug ]['Name']  ) . '&TB_iframe=true&width=772&height=887'; ?>" target="_blank" class="thickbox open-plugin-details-modal"><?php echo esc_html( $pluginsOutdateInfo[ $slug ]['Name']  ); ?></a></td>
							<td sort-value="<?php echo $cnt; ?>"><?php echo $cnt; ?> <?php echo _n( 'Website', 'Websites', $cnt, 'mainwp' ); ?></td>
							<td class="right aligned">
								<?php if ( $user_can_ignore_unignore_updates ) { ?>
									<a href="javascript:void(0)" class="ui mini green button" onClick="return updatesoverview_plugins_abandoned_ignore_all( '<?php echo $plugin_name; ?>', '<?php echo urlencode( $pluginsOutdateInfo[ $slug ]['Name'] ); ?>', this )"><?php _e( 'Ignore Globally', 'mainwp' ); ?></a>
								<?php } ?>
							</td>
						</tr>
						<tr style="display:none">
							<td colspan="4" class="content">
								<table class="ui stackable single line table" id="mainwp-abandoned-plugins-sites-table">
									<thead>
										<tr>
											<th><?php echo __( 'Website', 'mainwp' ); ?></th>
											<th><?php echo __( 'Version', 'mainwp' ); ?></th>
											<th><?php echo __( 'Last Update', 'mainwp' ); ?></th>
											<th class="no-sort"></th>
										</tr>
									</thead>
									<tbody class="abandoned-plugins-ignore-global" plugin_slug="<?php echo urlencode( $slug ); ?>" plugin_name="<?php echo urlencode( $pluginsOutdateInfo[ $slug ]['Name'] ); ?>" dismissed="0">
									<?php
									MainWP_DB::data_seek( $websites, 0 );
									while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
										$plugins_outdate = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'plugins_outdate_info' ), true );
										if ( ! is_array( $plugins_outdate ) ) {
											$plugins_outdate = array();
										}

										if ( 0 < count( $plugins_outdate ) ) {
											$pluginsOutdateDismissed = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'plugins_outdate_dismissed' ), true );
											if ( is_array( $pluginsOutdateDismissed ) ) {
												$plugins_outdate = array_diff_key( $plugins_outdate, $pluginsOutdateDismissed );
											}

											if ( is_array( $decodedDismissedPlugins ) ) {
												$plugins_outdate = array_diff_key( $plugins_outdate, $decodedDismissedPlugins );
											}
										}

										if ( ! isset( $plugins_outdate[ $slug ] ) ) {
											continue;
										}

										$plugin_outdate           = $plugins_outdate[ $slug ];
										$now                      = new \DateTime();
										$last_updated             = $plugin_outdate['last_updated'];
										$plugin_last_updated_date = new \DateTime( '@' . $last_updated );
										$diff_in_days             = $now->diff( $plugin_last_updated_date )->format( '%a' );
										$outdate_notice           = sprintf( $str_format, $diff_in_days );
										?>
										<tr site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>" dismissed="0">
											<td><a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"  data-inverted="" data-tooltip="<?php echo esc_attr( $visit_dashboard_title ); ?>"><?php echo stripslashes( $website->name ); ?></a></td>
											<td><?php echo esc_html( $plugin_outdate['Version'] ); ?></td>
											<td><?php echo $outdate_notice; ?></td>
											<td class="right aligned">
												<?php if ( $user_can_ignore_unignore_updates ) : ?>
												<a href="javascript:void(0)" class="ui mini button" onClick="return updatesoverview_plugins_dismiss_outdate_detail( '<?php echo $plugin_name; ?>', '<?php echo urlencode( $plugin_outdate['Name'] ); ?>', <?php echo esc_attr( $website->id ); ?>, this )"><?php _e( 'Ignore Now', 'mainwp' ); ?></a>
											  <?php endif; ?>
											</td>
										</tr>
										<?php
									}
									?>
									</tbody>
								</table>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
					<tfoot>
						<tr>
							<th class="collapsing no-sort"></th>
							<th><?php echo __( 'Plugin', 'mainwp' ); ?></th>
							<th><?php echo __( 'Abandoned', 'mainwp' ); ?></th>
							<th class="collapsing no-sort"></th>
						</tr>
					</tfoot>
				</table>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<!-- END Abandoned Plugins -->

			<!-- Abandoned Themes -->

			<?php if ( 'abandoned-themes' === $current_tab ) : ?>
				<?php $str_format = __( 'Updated %s days ago', 'mainwp' ); ?>
			<div class="ui <?php echo( 'abandoned-themes' === $current_tab ? 'active' : '' ); ?> tab" data-tab="abandoned-themes">
				<?php
				if ( MAINWP_VIEW_PER_SITE == $userExtension->site_view ) :
					?>
				<!-- Per Site -->
				<table class="ui stackable single line table" id="mainwp-abandoned-themes-sites-table">
					<thead>
						<tr>
							<th class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
							<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo __( 'Website', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
							<th class="right aligned indicator-accordion-sorting handle-accordion-sorting"><?php echo __( 'Abandoned', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
						</tr>
					</thead>
					<tbody class="ui accordion">
						<?php MainWP_DB::data_seek( $websites, 0 ); ?>
						<?php
						while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
							$themes_outdate = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'themes_outdate_info' ), true );

							if ( is_array( $themes_outdate ) ) {
								$themesOutdateDismissed = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'themes_outdate_dismissed' ), true );
								if ( is_array( $themesOutdateDismissed ) ) {
									$themes_outdate = array_diff_key( $themes_outdate, $themesOutdateDismissed );
								}
								if ( is_array( $decodedDismissedThemes ) ) {
									$themes_outdate = array_diff_key( $themes_outdate, $decodedDismissedThemes );
								}
							} else {
								$themes_outdate = array();
							}

							if ( 0 === count( $themes_outdate ) ) {
								continue;
							}

							?>
							<tr class="title">
								<td class="accordion-trigger"><i class="icon dropdown"></i></td>
								<td>
									<a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"  data-inverted="" data-tooltip="<?php echo esc_attr( $visit_dashboard_title ); ?>"><?php echo stripslashes( $website->name ); ?></a>
								</td>
								<td class="right aligned" sort-value="<?php echo count( $themes_outdate ); ?>"> <?php echo count( $themes_outdate ); ?> <?php echo _n( 'Theme', 'Themes', count( $themes_outdate ), 'mainwp' ); ?></td>
							</tr>
							<tr style="display:none">
								<td colspan="3" class="content">
									<table class="ui stackable single line table" id="mainwp-abandoned-themes-table">
										<thead>
											<tr>
												<th><?php echo __( 'Theme', 'mainwp' ); ?></th>
												<th><?php echo __( 'Version', 'mainwp' ); ?></th>
												<th><?php echo __( 'Last Update', 'mainwp' ); ?></th>
												<th class="no-sort"></th>
											</tr>
										</thead>
										<tbody id="wp_themes_outdate_<?php echo esc_attr( $website->id ); ?>" site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>">
											<?php foreach ( $themes_outdate as $slug => $theme_outdate ) : ?>
												<?php
												$theme_name              = urlencode( $slug );
												$now                     = new \DateTime();
												$last_updated            = $theme_outdate['last_updated'];
												$theme_last_updated_date = new \DateTime( '@' . $last_updated );
												$diff_in_days            = $now->diff( $theme_last_updated_date )->format( '%a' );
												$outdate_notice          = sprintf( $str_format, $diff_in_days );
												?>
												<tr dismissed="0">
													<td>
														<?php echo esc_html( $theme_outdate['Name'] ); ?>
														<input type="hidden" id="wp_dismissed_theme_<?php echo esc_attr( $website->id ); ?>_<?php echo $theme_name; ?>" value="0"/>
													</td>
													<td><?php echo esc_html( $theme_outdate['Version'] ); ?></td>
													<td><?php echo $outdate_notice; ?></td>
													<td class="right aligned" id="wp_dismissbuttons_theme_<?php echo esc_attr( $website->id ); ?>_<?php echo $theme_name; ?>">
														<?php if ( $user_can_ignore_unignore_updates ) { ?>
															<a href="javascript:void(0)" class="ui mini button" onClick="return updatesoverview_themes_dismiss_outdate_detail( '<?php echo $theme_name; ?>', '<?php echo urlencode( $theme_outdate['Name'] ); ?>', <?php echo esc_attr( $website->id ); ?>, this )"><?php _e( 'Ignore Now', 'mainwp' ); ?></a>
														  <?php } ?>
													</td>
												</tr>
											<?php endforeach; ?>
										</tbody>
									</table>
								</td>
							</tr>
							<?php
						}
						?>
					</tbody>
					<tfoot>
						<tr>
							<th class="collapsing no-sort"></th>
							<th><?php echo __( 'Website', 'mainwp' ); ?></th>
							<th class="right aligned"><?php echo __( 'Abandoned', 'mainwp' ); ?></th>
						</tr>
					</tfoot>
				</table>
					<?php
				elseif ( MAINWP_VIEW_PER_GROUP == $userExtension->site_view ) :
					?>
				<!-- Per Group -->
				<table class="ui stackable single line table" id="mainwp-abandoned-themes-groups-table">
					<thead>
						<tr>
							<th class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
							<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo __( 'Group', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
							<th class="right aligned indicator-accordion-sorting handle-accordion-sorting"><?php echo __( 'Abandoned', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
						</tr>
					</thead>
					<tbody class="ui accordion">
					<?php foreach ( $all_groups_sites as $group_id => $site_ids ) : ?>
						<?php
						$total_group_themes_outdate = 0;
						$group_name                 = $all_groups[ $group_id ];
						?>
						<tr row-uid="uid_themes_outdate_<?php echo esc_attr( $group_id ); ?>" class="title">
							<td class="accordion-trigger"><i class="dropdown icon"></i></td>
							<td><?php echo stripslashes( $group_name ); ?></td>
							<td class="right aligned" total-uid="uid_themes_outdate_<?php echo esc_attr( $group_id ); ?>" sort-value="0"></td>
						</tr>
						<tr style="display:none" row-uid="uid_themes_outdate_<?php echo esc_attr( $group_id ); ?>">
							<td colspan="3" class="content">
								<table class="ui stackable single line grey table mainwp-per-group-table" id="mainwp-abandoned-themes-sites-table">
									<thead>
										<tr>
											<th class="collapsing no-sort"></th>
											<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo __( 'Website', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
											<th class="right aligned indicator-accordion-sorting handle-accordion-sorting"><?php echo __( 'Abandoned', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
										</tr>
									</thead>
									<tbody class="accordion">
									<?php foreach ( $site_ids as $site_id ) : ?>
										<?php
										$seek = $site_offset[ $site_id ];
										MainWP_DB::data_seek( $websites, $seek );

										$website = MainWP_DB::fetch_object( $websites );

										$themes_outdate = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'themes_outdate_info' ), true );

										if ( ! is_array( $themes_outdate ) ) {
											$themes_outdate = array();
										}

										if ( 0 < count( $themes_outdate ) ) {
											$themesOutdateDismissed = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'themes_outdate_dismissed' ), true );
											if ( is_array( $themesOutdateDismissed ) ) {
												$themes_outdate = array_diff_key( $themes_outdate, $themesOutdateDismissed );
											}

											if ( is_array( $decodedDismissedThemes ) ) {
												$themes_outdate = array_diff_key( $themes_outdate, $decodedDismissedThemes );
											}
										}

										$total_group_themes_outdate += count( $themes_outdate );
										?>
										<?php if ( 0 < count( $themes_outdate ) ) : ?>
										<tr class="ui title">
											<td class="accordion-trigger"><i class="dropdown icon"></i></td>
											<td>
												<a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"  data-inverted="" data-tooltip="<?php echo esc_attr( $visit_dashboard_title ); ?>"><?php echo stripslashes( $website->name ); ?></a>
											</td>
											<td class="right aligned" sort-value="<?php echo count( $themes_outdate ); ?>">
												<?php echo count( $themes_outdate ); ?> <?php echo _n( 'Theme', 'Themes', count( $themes_outdate ), 'mainwp' ); ?>
											</td>
										</tr>
										<tr style="display:none">
											<td colspan="3" class="ui content">
												<table class="ui stackable single line table" id="mainwp-abandoned-themes-table">
													<thead>
														<tr>
															<tr>
																<th><?php echo __( 'Theme', 'mainwp' ); ?></th>
																<th><?php echo __( 'Version', 'mainwp' ); ?></th>
																<th><?php echo __( 'Last Update', 'mainwp' ); ?></th>
																<th class="no-sort"></th>
															</tr>
														</tr>
													</thead>
													<tbody site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>">
														<?php foreach ( $themes_outdate as $slug => $theme_outdate ) : ?>
															<?php
															$theme_name              = urlencode( $slug );
															$now                     = new \DateTime();
															$last_updated            = $theme_outdate['last_updated'];
															$theme_last_updated_date = new \DateTime( '@' . $last_updated );
															$diff_in_days            = $now->diff( $theme_last_updated_date )->format( '%a' );
															$outdate_notice          = sprintf( $str_format, $diff_in_days );
															?>
															<tr dismissed="0">
																<td>
																	<?php echo esc_html( $theme_outdate['Name'] ); ?>
																	<input type="hidden" id="wp_dismissed_theme_<?php echo esc_attr( $website->id ); ?>_<?php echo $theme_name; ?>" value="0"/>
																</td>
																<td><?php echo esc_html( $theme_outdate['Version'] ); ?></td>
																<td><?php echo $outdate_notice; ?></td>
																<td class="right aligned" id="wp_dismissbuttons_theme_<?php echo esc_attr( $website->id ); ?>_<?php echo $theme_name; ?>">
																	<?php if ( $user_can_ignore_unignore_updates ) { ?>
																	<a href="javascript:void(0)" class="ui mini button" onClick="return updatesoverview_themes_dismiss_outdate_detail( '<?php echo $theme_name; ?>', '<?php echo urlencode( $theme_outdate['Name'] ); ?>', <?php echo esc_attr( $website->id ); ?>, this )"><?php _e( 'Ignore Now', 'mainwp' ); ?></a>
																  <?php } ?>
																</td>
															</tr>
														<?php endforeach; ?>
													</tbody>
												</table>
											</td>
										</tr>
										<?php endif; ?>
									<?php endforeach; ?>
									</tbody>
								</table>
							</td>
						</tr>
						<input type="hidden" class="element_ui_view_values" elem-uid="uid_themes_outdate_<?php echo esc_attr( $group_id ); ?>" total="<?php echo intval( $total_group_themes_outdate ); ?>" can-update="0">
					<?php endforeach; ?>
					</tbody>
					<tfoot>
						<tr>
							<th class="collapsing no-sort"></th>
							<th><?php echo __( 'Group', 'mainwp' ); ?></th>
							<th class="right aligned"><?php echo __( 'Abandoned', 'mainwp' ); ?></th>
						</tr>
					</tfoot>
				</table>
					<?php
				else :
					?>
				<!-- Per Item -->
				<table class="ui stackable single line table" id="mainwp-themes-updates-table">
					<thead>
						<tr>
							<th class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
							<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo __( 'Theme', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
							<th class="indicator-accordion-sorting handle-accordion-sorting"><?php echo __( 'Abandoned', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
							<th class="collapsing no-sort">
								<?php MainWP_UI::render_show_all_updates_button(); ?>
							</th>
						</tr>
					</thead>
					<tbody class="ui accordion">
					<?php foreach ( $allThemesOutdate as $slug => $val ) : ?>
						<?php
						$cnt        = intval( $val['cnt'] );
						$theme_name = urlencode( $slug );
						?>
						<tr class="title">
							<td class="accordion-trigger"><i class="dropdown icon"></i></td>
							<td><?php echo esc_html( $val['name'] ); ?></td>
							<td sort-value="<?php echo $cnt; ?>"><?php echo $cnt; ?> <?php echo _n( 'Website', 'Websites', $cnt, 'mainwp' ); ?></td>
							<td class="right aligned">
								<?php if ( $user_can_ignore_unignore_updates ) { ?>
									<a href="javascript:void(0)" class="ui mini green button" onClick="return updatesoverview_themes_abandoned_ignore_all( '<?php echo $theme_name; ?>', '<?php echo urlencode( $val['name'] ); ?>', this )"><?php _e( 'Ignore Globally', 'mainwp' ); ?></a>
								<?php } ?>
							</td>
						</tr>
						<tr style="display:none">
							<td colspan="4" class="content">
								<table class="ui stackable single line table" id="mainwp-abandoned-themes-sites-table">
									<thead>
										<tr>
											<th><?php echo __( 'Website', 'mainwp' ); ?></th>
											<th><?php echo __( 'Version', 'mainwp' ); ?></th>
											<th><?php echo __( 'Last Update', 'mainwp' ); ?></th>
											<th class="no-sort"></th>
										</tr>
									</thead>
									<tbody class="abandoned-themes-ignore-global" theme_slug="<?php echo $slug; ?>" theme_name="<?php echo urlencode( $val['name'] ); ?>">
									<?php
									MainWP_DB::data_seek( $websites, 0 );
									while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
										$themes_outdate = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'themes_outdate_info' ), true );
										if ( ! is_array( $themes_outdate ) ) {
											$themes_outdate = array();
										}

										if ( 0 < count( $themes_outdate ) ) {
											$themesOutdateDismissed = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'themes_outdate_dismissed' ), true );
											if ( is_array( $themesOutdateDismissed ) ) {
												$themes_outdate = array_diff_key( $themes_outdate, $themesOutdateDismissed );
											}

											if ( is_array( $decodedDismissedThemes ) ) {
												$themes_outdate = array_diff_key( $themes_outdate, $decodedDismissedThemes );
											}
										}

										if ( ! isset( $themes_outdate[ $slug ] ) ) {
											continue;
										}

										$theme_outdate           = $themes_outdate[ $slug ];
										$now                     = new \DateTime();
										$last_updated            = $theme_outdate['last_updated'];
										$theme_last_updated_date = new \DateTime( '@' . $last_updated );
										$diff_in_days            = $now->diff( $theme_last_updated_date )->format( '%a' );
										$outdate_notice          = sprintf( $str_format, $diff_in_days );
										?>
										<tr site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>" outdate="1" dismissed="0">
											<td><a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"  data-inverted="" data-tooltip="<?php echo esc_attr( $visit_dashboard_title ); ?>"><?php echo stripslashes( $website->name ); ?></a></td>
											<td><?php echo esc_html( $theme_outdate['Version'] ); ?></td>
											<td><?php echo $outdate_notice; ?></td>
											<td class="right aligned">
										<?php if ( $user_can_ignore_unignore_updates ) : ?>
												<a href="javascript:void(0)" class="ui mini button" onClick="return updatesoverview_themes_dismiss_outdate_detail( '<?php echo $theme_name; ?>', '<?php echo urlencode( $theme_outdate['Name'] ); ?>', <?php echo esc_attr( $website->id ); ?>, this )"><?php _e( 'Ignore Now', 'mainwp' ); ?></a>
											  <?php endif; ?>
											</td>
										</tr>
										<?php
									}
									?>
									</tbody>
								</table>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
					<tfoot>
						<tr>
							<th class="collapsing no-sort"></th>
							<th><?php echo __( 'Theme', 'mainwp' ); ?></th>
							<th><?php echo __( 'Abandoned', 'mainwp' ); ?></th>
							<th class="collapsing no-sort"></th>
						</tr>
					</tfoot>
				</table>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<!-- END Abandoned Themes -->

			<script type="text/javascript">
			
			jQuery( document ).ready( function () {
				jQuery( 'table table:not( .mainwp-per-group-table )' ).DataTable( {
					searching: false,
					paging : false,
					stateSave: true,
					info : false,
					columnDefs : [ { "orderable": false, "targets": "no-sort" } ],
					language : { "emptyTable": "No available updates. Please sync your MainWP Dashboard with Child Sites to see if there are any new updates available." }
				} );
				jQuery( '#mainwp-manage-updates .ui.accordion' ).accordion( {
					exclusive: false,
					duration: 200,
				} );
				jQuery( '.handle-accordion-sorting' ).on( 'click', function() {
					mainwp_according_table_sorting( this );
					return false;
				} );
			} );

			jQuery( document ).on( 'click', '.trigger-all-accordion', function() {
				if ( jQuery( this ).hasClass( 'active' ) ) {
					jQuery( this ).removeClass( 'active' );
					jQuery( '#mainwp-manage-updates .ui.accordion tr.title' ).each( function( i ) {
						if ( jQuery( this ).hasClass( 'active' ) ) {
							jQuery( this ).trigger( 'click' );
						}
					} );
				} else {
					jQuery( this ).addClass( 'active' );
					jQuery( '#mainwp-manage-updates .ui.accordion tr.title' ).each( function( i ) {
						if ( !jQuery( this ).hasClass( 'active' ) ) {
							jQuery( this ).trigger( 'click' );
						}
					} );
				}
			} );

			</script>
		</div>

		<div class="ui modal" id="updatesoverview-backup-box">
			<div class="header"><?php _e( 'Backup Check', 'mainwp' ); ?></div>
			<div class="scrolling content mainwp-modal-content"></div>
			<div class="actions mainwp-modal-actions">
				<input id="updatesoverview-backup-all" type="button" name="Backup All" value="<?php _e( 'Backup All', 'mainwp' ); ?>" class="button-primary"/>
				<a id="updatesoverview-backup-now" href="javascript:void(0)" target="_blank" style="display: none"  class="button-primary button"><?php _e( 'Backup Now', 'mainwp' ); ?></a>&nbsp;
				<input id="updatesoverview-backup-ignore" type="button" name="Ignore" value="<?php _e( 'Ignore', 'mainwp' ); ?>" class="button"/>
			</div>
		</div>
		<?php if ( MAINWP_VIEW_PER_GROUP == $userExtension->site_view ) : ?>
		<script type="text/javascript">
			jQuery( document ).ready( function () {
				updatesoverview_updates_init_group_view();
			} );
		</script>
		<?php endif; ?>

		<?php
		self::renderFooter();
	}

	public static function upgradeSite( $id ) {
		if ( isset( $id ) && MainWP_Utility::ctype_digit( $id ) ) {

			$website = MainWP_DB::Instance()->getWebsiteById( $id );

			if ( MainWP_Utility::can_edit_website( $website ) ) {

				$information = MainWP_Utility::fetchUrlAuthed( $website, 'upgrade' );

				if ( isset( $information['upgrade'] ) && ( 'SUCCESS' === $information['upgrade'] ) ) {
					MainWP_DB::Instance()->updateWebsiteOption( $website, 'wp_upgrades', wp_json_encode( array() ) );
					return '<i class="green check icon"></i>';
				} elseif ( isset( $information['upgrade'] ) ) {
					$errorMsg = '';
					if ( 'LOCALIZATION' === $information['upgrade'] ) {
						$errorMsg = '<i class="red times icon"></i> ' . __( 'No update found for the set locale.', 'mainwp' );
					} elseif ( 'NORESPONSE' === $information['upgrade'] ) {
						$errorMsg = '<i class="red times icon"></i> ' . __( 'No response from the child site server.', 'mainwp' );
					}
					throw new MainWP_Exception( 'WPERROR', $errorMsg );
				} elseif ( isset( $information['error'] ) ) {
					throw new MainWP_Exception( 'WPERROR', $information['error'] );
				} else {
					throw new MainWP_Exception( 'ERROR', '<i class="red times icon"></i> ' . __( 'Invalid response from child site.', 'mainwp' ) );
				}
			}
		}

		throw new MainWP_Exception( 'ERROR', '<i class="red times icon"></i> ' . __( 'Invalid request.', 'mainwp' ) );
	}

	public static function ignorePluginTheme( $type, $slug, $name, $id ) {
		if ( isset( $id ) && MainWP_Utility::ctype_digit( $id ) ) {
			$website = MainWP_DB::Instance()->getWebsiteById( $id );
			if ( MainWP_Utility::can_edit_website( $website ) ) {
				$slug = urldecode( $slug );
				if ( 'plugin' === $type ) {
					$decodedIgnoredPlugins = json_decode( $website->ignored_plugins, true );
					if ( ! isset( $decodedIgnoredPlugins[ $slug ] ) ) {
						$decodedIgnoredPlugins[ $slug ] = urldecode( $name );
						MainWP_DB::Instance()->updateWebsiteValues( $website->id, array( 'ignored_plugins' => wp_json_encode( $decodedIgnoredPlugins ) ) );
					}
				} elseif ( 'theme' === $type ) {
					$decodedIgnoredThemes = json_decode( $website->ignored_themes, true );
					if ( ! isset( $decodedIgnoredThemes[ $slug ] ) ) {
						$decodedIgnoredThemes[ $slug ] = urldecode( $name );
						MainWP_DB::Instance()->updateWebsiteValues( $website->id, array( 'ignored_themes' => wp_json_encode( $decodedIgnoredThemes ) ) );
					}
				}
			}
		}

		return 'success';
	}

	public static function unIgnorePluginTheme( $type, $slug, $id ) {
		if ( isset( $id ) ) {
			if ( '_ALL_' === $id ) {
				$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser() );
				while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {
					if ( 'plugin' === $type ) {
						MainWP_DB::Instance()->updateWebsiteValues( $website->id, array( 'ignored_plugins' => wp_json_encode( array() ) ) );
					} elseif ( 'theme' === $type ) {
						MainWP_DB::Instance()->updateWebsiteValues( $website->id, array( 'ignored_themes' => wp_json_encode( array() ) ) );
					}
				}
				MainWP_DB::free_result( $websites );
			} elseif ( MainWP_Utility::ctype_digit( $id ) ) {
				$website = MainWP_DB::Instance()->getWebsiteById( $id );
				if ( MainWP_Utility::can_edit_website( $website ) ) {
					$slug = urldecode( $slug );
					if ( 'plugin' === $type ) {
						$decodedIgnoredPlugins = json_decode( $website->ignored_plugins, true );
						if ( isset( $decodedIgnoredPlugins[ $slug ] ) ) {
							unset( $decodedIgnoredPlugins[ $slug ] );
							MainWP_DB::Instance()->updateWebsiteValues( $website->id, array( 'ignored_plugins' => wp_json_encode( $decodedIgnoredPlugins ) ) );
						}
					} elseif ( 'theme' === $type ) {
						$decodedIgnoredThemes = json_decode( $website->ignored_themes, true );
						if ( isset( $decodedIgnoredThemes[ $slug ] ) ) {
							unset( $decodedIgnoredThemes[ $slug ] );
							MainWP_DB::Instance()->updateWebsiteValues( $website->id, array( 'ignored_themes' => wp_json_encode( $decodedIgnoredThemes ) ) );
						}
					}
				}
			}
		}

		return 'success';
	}

	public static function ignorePluginsThemes( $type, $slug, $name ) {
		$slug          = urldecode( $slug );
		$userExtension = MainWP_DB::Instance()->getUserExtension();
		if ( 'plugin' === $type ) {
			$decodedIgnoredPlugins = json_decode( $userExtension->ignored_plugins, true );
			if ( ! is_array( $decodedIgnoredPlugins ) ) {
				$decodedIgnoredPlugins = array();
			}
			$decodedIgnoredPlugins[ $slug ] = urldecode( $name );
			MainWP_DB::Instance()->updateUserExtension( array(
				'userid'             => null,
				'ignored_plugins'    => wp_json_encode( $decodedIgnoredPlugins ),
			) );
		} elseif ( 'theme' === $type ) {
			$decodedIgnoredThemes = json_decode( $userExtension->ignored_themes, true );
			if ( ! is_array( $decodedIgnoredThemes ) ) {
				$decodedIgnoredThemes = array();
			}
			$decodedIgnoredThemes[ $slug ] = urldecode( $name );
			MainWP_DB::Instance()->updateUserExtension( array(
				'userid'         => null,
				'ignored_themes' => wp_json_encode( $decodedIgnoredThemes ),
			) );
		}

		return 'success';
	}

	public static function unIgnorePluginsThemes( $type, $slug ) {
		$slug          = urldecode( $slug );
		$userExtension = MainWP_DB::Instance()->getUserExtension();
		if ( 'plugin' === $type ) {
			if ( '_ALL_' === $slug ) {
				$decodedIgnoredPlugins = array();
			} else {
				$decodedIgnoredPlugins = json_decode( $userExtension->ignored_plugins, true );
				if ( ! is_array( $decodedIgnoredPlugins ) ) {
					$decodedIgnoredPlugins = array();
				}
				if ( isset( $decodedIgnoredPlugins[ $slug ] ) ) {
					unset( $decodedIgnoredPlugins[ $slug ] );
				}
			}
			MainWP_DB::Instance()->updateUserExtension( array(
				'userid'             => null,
				'ignored_plugins'    => wp_json_encode( $decodedIgnoredPlugins ),
			) );
		} elseif ( 'theme' === $type ) {
			if ( '_ALL_' === $slug ) {
				$decodedIgnoredThemes = array();
			} else {
				$decodedIgnoredThemes = json_decode( $userExtension->ignored_plugins, true );
				if ( ! is_array( $decodedIgnoredThemes ) ) {
					$decodedIgnoredThemes = array();
				}
				if ( isset( $decodedIgnoredThemes[ $slug ] ) ) {
					unset( $decodedIgnoredThemes[ $slug ] );
				}
			}
			MainWP_DB::Instance()->updateUserExtension( array(
				'userid'         => null,
				'ignored_themes' => wp_json_encode( $decodedIgnoredThemes ),
			) );
		}

		return 'success';
	}

	public static function unIgnoreAbandonedPluginTheme( $type, $slug, $id ) {
		if ( isset( $id ) ) {
			if ( '_ALL_' === $id ) {
				$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser() );
				while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {
					if ( 'plugin' === $type ) {
						MainWP_DB::Instance()->updateWebsiteOption( $website, 'plugins_outdate_dismissed', wp_json_encode( array() ) );
					} elseif ( 'theme' === $type ) {
						MainWP_DB::Instance()->updateWebsiteOption( $website, 'themes_outdate_dismissed', wp_json_encode( array() ) );
					}
				}
				MainWP_DB::free_result( $websites );
			} elseif ( MainWP_Utility::ctype_digit( $id ) ) {
				$website = MainWP_DB::Instance()->getWebsiteById( $id );
				if ( MainWP_Utility::can_edit_website( $website ) ) {
					$slug = urldecode( $slug );
					if ( 'plugin' === $type ) {
						$decodedIgnoredPlugins = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'plugins_outdate_dismissed' ), true );
						if ( isset( $decodedIgnoredPlugins[ $slug ] ) ) {
							unset( $decodedIgnoredPlugins[ $slug ] );
							MainWP_DB::Instance()->updateWebsiteOption( $website, 'plugins_outdate_dismissed', wp_json_encode( $decodedIgnoredPlugins ) );
						}
					} elseif ( 'theme' === $type ) {
						$decodedIgnoredThemes = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'themes_outdate_dismissed' ), true );
						if ( isset( $decodedIgnoredThemes[ $slug ] ) ) {
							unset( $decodedIgnoredThemes[ $slug ] );
							MainWP_DB::Instance()->updateWebsiteOption( $website, 'themes_outdate_dismissed', wp_json_encode( $decodedIgnoredThemes ) );
						}
					}
				}
			}
		}

		return 'success';
	}

	public static function unIgnoreAbandonedPluginsThemes( $type, $slug ) {
		$slug          = urldecode( $slug );
		$userExtension = MainWP_DB::Instance()->getUserExtension();
		if ( 'plugin' === $type ) {
			if ( '_ALL_' === $slug ) {
				$decodedIgnoredPlugins = array();
			} else {
				$decodedIgnoredPlugins = json_decode( $userExtension->dismissed_plugins, true );
				if ( ! is_array( $decodedIgnoredPlugins ) ) {
					$decodedIgnoredPlugins = array();
				}
				if ( isset( $decodedIgnoredPlugins[ $slug ] ) ) {
					unset( $decodedIgnoredPlugins[ $slug ] );
				}
			}
			MainWP_DB::Instance()->updateUserExtension( array(
				'userid'             => null,
				'dismissed_plugins'  => wp_json_encode( $decodedIgnoredPlugins ),
			) );
		} elseif ( 'theme' === $type ) {
			if ( '_ALL_' === $slug ) {
				$decodedIgnoredThemes = array();
			} else {
				$decodedIgnoredThemes = json_decode( $userExtension->dismissed_themes, true );
				if ( ! is_array( $decodedIgnoredThemes ) ) {
					$decodedIgnoredThemes = array();
				}
				if ( isset( $decodedIgnoredThemes[ $slug ] ) ) {
					unset( $decodedIgnoredThemes[ $slug ] );
				}
			}
			MainWP_DB::Instance()->updateUserExtension( array(
				'userid'             => null,
				'dismissed_themes'   => wp_json_encode( $decodedIgnoredThemes ),
			) );
		}

		return 'success';
	}

	public static function dismissPluginTheme( $type, $slug, $name, $id ) {
		if ( isset( $id ) && MainWP_Utility::ctype_digit( $id ) ) {
			$website = MainWP_DB::Instance()->getWebsiteById( $id );
			if ( MainWP_Utility::can_edit_website( $website ) ) {
				$slug = urldecode( $slug );
				if ( 'plugin' === $type ) {
					$decodedDismissedPlugins = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'plugins_outdate_dismissed' ), true );
					if ( ! isset( $decodedDismissedPlugins[ $slug ] ) ) {
						$decodedDismissedPlugins[ $slug ] = urldecode( $name );
						MainWP_DB::Instance()->updateWebsiteOption( $website, 'plugins_outdate_dismissed', wp_json_encode( $decodedDismissedPlugins ) );
					}
				} elseif ( 'theme' === $type ) {
					$decodedDismissedThemes = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'themes_outdate_dismissed' ), true );
					if ( ! isset( $decodedDismissedThemes[ $slug ] ) ) {
						$decodedDismissedThemes[ $slug ] = urldecode( $name );
						MainWP_DB::Instance()->updateWebsiteOption( $website, 'themes_outdate_dismissed', wp_json_encode( $decodedDismissedThemes ) );
					}
				}
			}
		}

		return 'success';
	}

	public static function dismissPluginsThemes( $type, $slug, $name ) {
		$slug          = urldecode( $slug );
		$userExtension = MainWP_DB::Instance()->getUserExtension();
		if ( 'plugin' === $type ) {
			$decodedDismissedPlugins = json_decode( $userExtension->dismissed_plugins, true );
			if ( ! is_array( $decodedDismissedPlugins ) ) {
				$decodedDismissedPlugins = array();
			}
			$decodedDismissedPlugins[ $slug ] = urldecode( $name );
			MainWP_DB::Instance()->updateUserExtension( array(
				'userid'             => null,
				'dismissed_plugins'  => wp_json_encode( $decodedDismissedPlugins ),
			) );
		} elseif ( 'theme' === $type ) {
			$decodedDismissedThemes = json_decode( $userExtension->dismissed_themes, true );
			if ( ! is_array( $decodedDismissedThemes ) ) {
				$decodedDismissedThemes = array();
			}
			$decodedDismissedThemes[ $slug ] = urldecode( $name );
			MainWP_DB::Instance()->updateUserExtension( array(
				'userid'             => null,
				'dismissed_themes'   => wp_json_encode( $decodedDismissedThemes ),
			) );
		}

		return 'success';
	}

	/*
	 * $id = site id in db
	 * $type = theme/plugin
	 * $list = name of theme/plugin (seperated by ,)
	 */

	public static function upgradePluginThemeTranslation( $id, $type, $list ) {
		if ( isset( $id ) && MainWP_Utility::ctype_digit( $id ) ) {
			$website = MainWP_DB::Instance()->getWebsiteById( $id );
			if ( MainWP_Utility::can_edit_website( $website ) ) {
				$information = MainWP_Utility::fetchUrlAuthed( $website, ( 'translation' === $type ? 'upgradetranslation' : 'upgradeplugintheme' ), array(
					'type'   => $type,
					'list'   => urldecode( $list ),
				), true );
				if ( isset( $information['upgrades'] ) ) {
					$tmp = array();
					if ( isset( $information['upgrades'] ) ) {
						foreach ( $information['upgrades'] as $k => $v ) {
							$tmp[ urlencode( $k ) ] = $v;
						}
					}
					return $tmp;
				} elseif ( isset( $information['error'] ) ) {
					throw new MainWP_Exception( 'WPERROR', $information['error'] );
				} else {
					throw new MainWP_Exception( 'ERROR', 'Invalid response from site!' );
				}
			}
		}
		throw new MainWP_Exception( 'ERROR', __( 'Invalid request!', 'mainwp' ) );
	}

	/*
	 * $id = site id in db
	 * $type = theme/plugin
	 */

	// todo: rename for Translation
	public static function getPluginThemeSlugs( $id, $type ) {

		$userExtension = MainWP_DB::Instance()->getUserExtension();
		$sql           = MainWP_DB::Instance()->getSQLWebsiteById( $id );
		$websites      = MainWP_DB::Instance()->query( $sql );
		$website       = MainWP_DB::fetch_object( $websites );

		$slugs = array();
		if ( 'plugin' === $type ) {
			if ( $website->is_ignorePluginUpdates ) {
				return '';
			}

			$plugin_upgrades        = json_decode( $website->plugin_upgrades, true );
			$decodedPremiumUpgrades = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'premium_upgrades' ), true );
			if ( is_array( $decodedPremiumUpgrades ) ) {
				foreach ( $decodedPremiumUpgrades as $crrSlug => $premiumUpgrade ) {
					$premiumUpgrade['premium'] = true;

					if ( 'plugin' === $premiumUpgrade['type'] ) {
						if ( ! is_array( $plugin_upgrades ) ) {
							$plugin_upgrades = array();
						}
						$premiumUpgrade              = array_filter( $premiumUpgrade );
						$plugin_upgrades[ $crrSlug ] = array_merge( $plugin_upgrades[ $crrSlug ], $premiumUpgrade );
					}
				}
			}

			$ignored_plugins = json_decode( $website->ignored_plugins, true );
			if ( is_array( $ignored_plugins ) ) {
				$plugin_upgrades = array_diff_key( $plugin_upgrades, $ignored_plugins );
			}

			$ignored_plugins = json_decode( $userExtension->ignored_plugins, true );
			if ( is_array( $ignored_plugins ) ) {
				$plugin_upgrades = array_diff_key( $plugin_upgrades, $ignored_plugins );
			}

			if ( is_array( $plugin_upgrades ) ) {
				foreach ( $plugin_upgrades as $slug => $plugin_upgrade ) {
					$slugs[] = urlencode( $slug );
				}
			}
		} elseif ( 'theme' === $type ) {

			if ( $website->is_ignoreThemeUpdates ) {
				return '';
			}

			$theme_upgrades         = json_decode( $website->theme_upgrades, true );
			$decodedPremiumUpgrades = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'premium_upgrades' ), true );
			if ( is_array( $decodedPremiumUpgrades ) ) {
				foreach ( $decodedPremiumUpgrades as $crrSlug => $premiumUpgrade ) {
					$premiumUpgrade['premium'] = true;

					if ( 'theme' === $premiumUpgrade['type'] ) {
						if ( ! is_array( $theme_upgrades ) ) {
							$theme_upgrades = array();
						}
						$theme_upgrades[ $crrSlug ] = $premiumUpgrade;
					}
				}
			}

			$ignored_themes = json_decode( $website->ignored_themes, true );
			if ( is_array( $ignored_themes ) ) {
				$theme_upgrades = array_diff_key( $theme_upgrades, $ignored_themes );
			}

			$ignored_themes = json_decode( $userExtension->ignored_themes, true );
			if ( is_array( $ignored_themes ) ) {
				$theme_upgrades = array_diff_key( $theme_upgrades, $ignored_themes );
			}

			if ( is_array( $theme_upgrades ) ) {
				foreach ( $theme_upgrades as $slug => $theme_upgrade ) {
					$slugs[] = $slug;
				}
			}
		} elseif ( 'translation' === $type ) {
			$translation_upgrades = json_decode( $website->translation_upgrades, true );
			if ( is_array( $translation_upgrades ) ) {
				foreach ( $translation_upgrades as $translation_upgrade ) {
					$slugs[] = $translation_upgrade['slug'];
				}
			}
		}

		return implode( ',', $slugs );
	}

	// Hook the section help content to the Help Sidebar element
	public static function mainwp_help_content() {
		if ( isset( $_GET['page'] ) && 'UpdatesManage' === $_GET['page'] ) {
			?>
			<p><?php echo __( 'If you need help with managing updates, please review following help documents', 'mainwp' ); ?></p>
			<div class="ui relaxed bulleted list">
				<div class="item"><a href="https://mainwp.com/help/docs/managing-plugins-with-mainwp/update-plugins/" target="_blank">Update Plugins</a></div>
				<div class="item"><a href="https://mainwp.com/help/docs/managing-plugins-with-mainwp/plugins-auto-updates/" target="_blank">Plugins Auto Updates</a></div>
				<div class="item"><a href="https://mainwp.com/help/docs/managing-plugins-with-mainwp/ignore-plugin-updates/" target="_blank">Ignore Plugin Updates</a></div>
				<div class="item"><a href="https://mainwp.com/help/docs/managing-themes-with-mainwp/update-themes/" target="_blank">Update Themes</a></div>
				<div class="item"><a href="https://mainwp.com/help/docs/managing-themes-with-mainwp/themes-auto-updates/" target="_blank">Themes Auto Updates</a></div>
				<div class="item"><a href="https://mainwp.com/help/docs/managing-themes-with-mainwp/ignore-theme-updates/" target="_blank">Ignore Theme Updates</a></div>
				<div class="item"><a href="https://mainwp.com/help/docs/update-wordpress-core/" target="_blank">Update WordPress Core</a></div>
				<div class="item"><a href="https://mainwp.com/help/docs/auto-update-wordpress-core/" target="_blank">Auto Update WordPress Core</a></div>
				<div class="item"><a href="https://mainwp.com/help/docs/ignore-wordpress-core-update/" target="_blank">Ignore WordPress Core Update</a></div>
			</div>
			<?php
		}
	}

}
