<?php
namespace MainWP\Dashboard;

/**
 * MainWP Updates Page
 */
class MainWP_Updates {

	/**
	 * User can ignore updates
	 *
	 * @var bool
	 */
	public static $user_can_ignore_updates = null;

	/**
	 * User can updates translations
	 *
	 * @var bool
	 */
	public static $user_can_update_trans = null;

	/**
	 * User can updates WP
	 *
	 * @var bool
	 */
	public static $user_can_update_wp = null;

	/**
	 * User can updates themes
	 *
	 * @var bool
	 */
	public static $user_can_update_themes = null;

	/**
	 * User can updates plugins
	 *
	 * @var bool
	 */
	public static $user_can_update_plugins = null;
	public static $trusted_label           = '';
	public static $not_trusted_label       = '';
	public static $continue_selector       = '';
	public static $continue_update         = '';
	public static $continue_update_slug    = '';

	/**
	 * Method get_class_name()
	 *
	 * Get Class Name.
	 *
	 * @return object
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	public static function init() {
		/**
		 * This hook allows you to render the Post page header via the 'mainwp-pageheader-updates' action.
		 *
		 * This hook is normally used in the same context of 'mainwp-getsubpages-updates'
		 *
		 * @see \MainWP_Updates::render_header
		 */
		add_action( 'mainwp-pageheader-updates', array( MainWP_Post::get_class_name(), 'render_header' ) );

		/**
		 * This hook allows you to render the Updates page footer via the 'mainwp-pagefooter-updates' action.
		 *
		 * This hook is normally used in the same context of 'mainwp-getsubpages-updates'
		 *
		 * @see \MainWP_Updates::render_footer
		 */
		add_action( 'mainwp-pagefooter-updates', array( MainWP_Post::get_class_name(), 'render_footer' ) );

		add_action( 'mainwp_help_sidebar_content', array( self::get_class_name(), 'mainwp_help_content' ) );
	}

	/**
	 * Method init_menu()
	 *
	 * Render init updates menu
	 *
	 * @return none
	 */
	public static function init_menu() {
		add_submenu_page(
			'mainwp_tab',
			__( 'Updates', 'mainwp' ),
			'<span id="mainwp-Updates">' . __( 'Updates', 'mainwp' ) . '</span>',
			'read',
			'UpdatesManage',
			array(
				self::get_class_name(),
				'render',
			)
		);

		MainWP_Menu::add_left_menu(
			array(
				'title'             => __( 'Updates', 'mainwp' ),
				'parent_key'        => 'mainwp_tab',
				'slug'              => 'UpdatesManage',
				'href'              => 'admin.php?page=UpdatesManage',
				'icon'              => '<i class="sync icon"></i>',
			),
			1
		);
	}

	/**
	 * @param string $shownPage The page slug shown at this moment
	 */
	public static function render_header( $shownPage = '' ) {

		$params = array(
			'title' => __( 'Update Details', 'mainwp' ),
		);

		MainWP_UI::render_top_header( $params );
	}

	/**
	 * @param string $shownPage The page slug shown at this moment
	 */
	public static function render_footer() {
		echo '</div>';
	}

	/**
	 * Method render_site_link_dashboard()
	 *
	 * @param object $website the site
	 * @return html link to dashboard
	 */
	public static function render_site_link_dashboard( $website ) {
		?>
		<a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"  data-inverted="" data-tooltip="<?php echo esc_html__( 'Visit this dashboard', 'mainwp' ); ?>"><?php echo stripslashes( $website->name ); ?></a>
		<?php
	}

	/**
	 * Method user_can_ignore_updates()
	 *
	 * @param empty
	 * @return true|false user can ignore updates or not.
	 */
	public static function user_can_ignore_updates() {
		if ( null === self::$user_can_ignore_updates ) {
			self::$user_can_ignore_updates = mainwp_current_user_have_right( 'dashboard', 'ignore_unignore_updates' );
		}
		return self::$user_can_ignore_updates;
	}

	/**
	 * Method user_can_update_trans()
	 *
	 * @param empty
	 * @return true|false user can update translations.
	 */
	public static function user_can_update_trans() {
		if ( null === self::$user_can_update_trans ) {
			self::$user_can_update_trans = mainwp_current_user_have_right( 'dashboard', 'update_translations' );
		}
		return self::$user_can_update_trans;
	}

	/**
	 * Method user_can_update_wp()
	 *
	 * @param empty
	 * @return true|false user can update WP.
	 */
	public static function user_can_update_wp() {
		if ( null === self::$user_can_update_wp ) {
			self::$user_can_update_wp = mainwp_current_user_have_right( 'dashboard', 'update_wordpress' );
		}
		return self::$user_can_update_wp;
	}

	/**
	 * Method user_can_update_themes()
	 *
	 * @param empty
	 * @return true|false user can update themes.
	 */
	public static function user_can_update_themes() {
		if ( null === self::$user_can_update_themes ) {
			self::$user_can_update_themes = mainwp_current_user_have_right( 'dashboard', 'update_themes' );
		}
		return self::$user_can_update_themes;
	}

	/**
	 * Method user_can_update_plugins()
	 *
	 * @param empty
	 * @return true|false user can update plugins.
	 */
	public static function user_can_update_plugins() {
		if ( null === self::$user_can_update_plugins ) {
			self::$user_can_update_plugins = mainwp_current_user_have_right( 'dashboard', 'update_plugins' );
		}
		return self::$user_can_update_plugins;
	}

	/**
	 * Method render()
	 *
	 * Render updates page
	 *
	 * @return html render
	 */
	public static function render() {

		$websites      = self::get_sites();
		$userExtension = MainWP_DB_Common::instance()->get_user_extension();
		$site_view     = $userExtension->site_view;

		if ( MAINWP_VIEW_PER_GROUP == $site_view ) {
			$site_offset = array();
			$all_groups  = array();
			$groups      = MainWP_DB_Common::instance()->get_groups_for_current_user();
			foreach ( $groups as $group ) {
				$all_groups[ $group->id ] = $group->name;
			}

			$sites_in_groups  = array();
			$all_groups_sites = array();
			foreach ( $all_groups as $group_id => $group_name ) {
				$all_groups_sites[ $group_id ] = array();
				$group_sites                   = MainWP_DB::instance()->get_websites_by_group_id( $group_id );
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

			if ( 0 < count( $sites_not_in_groups ) ) {
				$all_groups_sites[0] = $sites_not_in_groups;
				$all_groups[0]       = __( 'Others', 'mainwp' );
			}
		}

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

		$allPluginsOutdate = array();
		$allThemesOutdate  = array();

		MainWP_DB::data_seek( $websites, 0 );

		while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {

			$wp_upgrades = json_decode( MainWP_DB::instance()->get_website_option( $website, 'wp_upgrades' ), true );

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

			$decodedPremiumUpgrades = json_decode( MainWP_DB::instance()->get_website_option( $website, 'premium_upgrades' ), true );
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
				$_ignored_plugins = json_decode( $website->ignored_plugins, true );
				if ( is_array( $_ignored_plugins ) ) {
					$plugin_upgrades = array_diff_key( $plugin_upgrades, $_ignored_plugins );
				}

				$_ignored_plugins = json_decode( $userExtension->ignored_plugins, true );
				if ( is_array( $_ignored_plugins ) ) {
					$plugin_upgrades = array_diff_key( $plugin_upgrades, $_ignored_plugins );
				}

				$total_plugin_upgrades += count( $plugin_upgrades );
			}

			if ( is_array( $theme_upgrades ) ) {
				$_ignored_themes = json_decode( $website->ignored_themes, true );
				if ( is_array( $_ignored_themes ) ) {
					$theme_upgrades = array_diff_key( $theme_upgrades, $_ignored_themes );
				}

				$_ignored_themes = json_decode( $userExtension->ignored_themes, true );
				if ( is_array( $_ignored_themes ) ) {
					$theme_upgrades = array_diff_key( $theme_upgrades, $_ignored_themes );
				}

				$total_theme_upgrades += count( $theme_upgrades );
			}

			$themesIgnoredAbandoned_perSites = array();
			$ignoredAbandoned_themes         = json_decode( MainWP_DB::instance()->get_website_option( $website, 'themes_outdate_dismissed' ), true );
			if ( is_array( $ignoredAbandoned_themes ) ) {
				$ignoredAbandoned_themes         = array_filter( $ignoredAbandoned_themes );
				$themesIgnoredAbandoned_perSites = array_merge( $themesIgnoredAbandoned_perSites, $ignoredAbandoned_themes );
			}

			$ignoredAbandoned_plugins = json_decode( MainWP_DB::instance()->get_website_option( $website, 'plugins_outdate_dismissed' ), true );
			$plugins_outdate          = json_decode( MainWP_DB::instance()->get_website_option( $website, 'plugins_outdate_info' ), true );
			$themes_outdate           = json_decode( MainWP_DB::instance()->get_website_option( $website, 'themes_outdate_info' ), true );

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

			if ( MAINWP_VIEW_PER_PLUGIN_THEME == $site_view ) {
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
					}
				}

				MainWP_Utility::array_sort( $allThemesOutdate, 'name' );
			}

			if ( '' != $website->sync_errors ) {
				$total_sync_errors ++;
			}
			if ( 1 == $website->uptodate ) {
				$total_uptodate ++;
			}
			if ( - 1 == $website->offline_check_result ) {
				$total_offline ++;
			}
		}

		$mainwp_show_language_updates = get_option( 'mainwp_show_language_updates', 1 );

		$limit_updates_all = apply_filters( 'mainwp_limit_updates_all', 0 );

		if ( 0 < $limit_updates_all ) {
			if ( isset( $_GET['continue_update'] ) && '' !== $_GET['continue_update'] ) {
				self::$continue_update = $_GET['continue_update'];
				if ( 'plugins_upgrade_all' === self::$continue_update || 'themes_upgrade_all' === self::$continue_update || 'translations_upgrade_all' === self::$continue_update ) {
					if ( isset( $_GET['slug'] ) && '' !== $_GET['slug'] ) {
						self::$continue_update_slug = $_GET['slug'];
					}
				}
			}
		}

		$current_tab = '';

		if ( isset( $_GET['tab'] ) ) {
			$current_tab = $_GET['tab'];
			if ( ! in_array( $current_tab, array( 'wordpress-updates', 'plugins-updates', 'themes-updates', 'translations-updates', 'abandoned-plugins', 'abandoned-themes', true ) ) ) {
				$current_tab = 'plugins-updates';
			}
		} else {
			$current_tab = 'plugins-updates';
		}

		self::render_header( 'UpdatesManage' );

		self::render_twitter_notice();

		self::render_header_tabs( $mainwp_show_language_updates, $current_tab, $total_wp_upgrades, $total_plugin_upgrades, $total_theme_upgrades, $total_translation_upgrades, $total_plugins_outdate, $total_themes_outdate, $site_view );
		?>
		<div class="ui segment" id="mainwp-manage-updates">
		<?php
		$enable_http_check = get_option( 'mainwp_check_http_response', 0 );
		if ( $enable_http_check ) {
			self::render_http_checks( $websites );
		}

		self::$trusted_label     = '<span class="ui tiny green label">Trusted</span>';
		self::$not_trusted_label = '<span class="ui tiny grey label">Not Trusted</span>';

		if ( 'wordpress-updates' === $current_tab ) {
			self::render_wp_update_tab( $websites, $total_wp_upgrades, $all_groups_sites, $all_groups, $site_offset, $site_view );
		} elseif ( 'plugins-updates' === $current_tab ) {
			self::render_plugins_update_tab( $websites, $total_plugin_upgrades, $userExtension, $all_groups_sites, $all_groups, $allPlugins, $pluginsInfo, $site_offset, $site_view );
		} elseif ( 'themes-updates' === $current_tab ) {
			self::render_themes_update_tab( $websites, $total_theme_upgrades, $userExtension, $all_groups_sites, $all_groups, $allThemes, $themesInfo, $site_offset, $site_view );
		} elseif ( 'translations-updates' === $current_tab ) {
			self::render_trans_update_tab( $websites, $total_translation_upgrades, $userExtension, $all_groups_sites, $all_groups, $allTranslations, $translationsInfo, $mainwp_show_language_updates, $site_offset, $site_view );
		} elseif ( 'abandoned-plugins' === $current_tab ) {
			self::render_abandoned_plugins_tab( $websites, $all_groups_sites, $all_groups, $allPluginsOutdate, $decodedDismissedPlugins, $site_offset, $site_view );
		} elseif ( 'abandoned-themes' === $current_tab ) {
			self::render_abandoned_themes_tab( $websites, $all_groups_sites, $all_groups, $allThemesOutdate, $decodedDismissedThemes, $site_offset, $site_view );
		}
		?>
		</div>
		<?php

		self::render_js_updates( $site_view );

		self::render_updates_modal();

		self::render_footer();
	}

	/**
	 * Method render_wp_update_tab()
	 *
	 * Render WP updates tab
	 *
	 * @param object $websites the websites
	 * @param int $total_wp_upgrades total update
	 * @param array $all_groups_sites all groups of sites
	 * @param array $all_groups all groups
	 * @param int $site_offset offset value
	 * @param string $site_view current view
	 * 
	 * @return html render
	 */
	public static function render_wp_update_tab( $websites, $total_wp_upgrades, $all_groups_sites, $all_groups, $site_offset, $site_view ) {
		?>
			<!-- WordPress Updates -->
			<div class="ui active tab" data-tab="wordpress-updates">
				<?php
				if ( MAINWP_VIEW_PER_GROUP == $site_view ) {
					MainWP_Updates_Per_Group::render_wpcore_updates( $websites, $total_wp_upgrades, $all_groups_sites, $all_groups, $site_offset );
				} else { // sites/items view.
					MainWP_DB::data_seek( $websites, 0 );
					MainWP_Updates_Per_Site::render_wpcore_updates( $websites, $total_wp_upgrades );
				};
				?>
			</div>
			<!-- END WordPress Updates -->
		<?php
	}

	/**
	 * Method render_plugins_update_tab()
	 *
	 * Render WP updates tab
	 *
	 * @param object $websites the websites
	 * @param int $total_plugin_upgrades total plugins update
	 * @param object $userExtension user extension
	 * @param array $all_groups_sites groups of sites
	 * @param array $all_groups all groups
	 * @param array $allPlugins all plugins
	 * @param array $pluginsInfo all plugins info
	 * @param int $site_offset offset value
	 * @param string $site_view current view
	 * 
	 * @return html render
	 */
	public static function render_plugins_update_tab( $websites, $total_plugin_upgrades, $userExtension, $all_groups_sites, $all_groups, $allPlugins, $pluginsInfo, $site_offset, $site_view ) {

		$trustedPlugins = json_decode( $userExtension->trusted_plugins, true );
		if ( ! is_array( $trustedPlugins ) ) {
			$trustedPlugins = array();
		}

		?>
		<!-- Plugins Updates -->
		<div class="ui active tab" data-tab="plugins-updates">
		<?php
		if ( MAINWP_VIEW_PER_SITE == $site_view ) {
			?>
				<!-- Per Site -->
				<?php
				MainWP_Updates_Per_Site::render_plugins_updates( $websites, $total_plugin_upgrades, $userExtension, $trustedPlugins );
		} elseif ( MAINWP_VIEW_PER_GROUP == $site_view ) {
			?>
				<!-- Per Group -->
				<?php
				MainWP_Updates_Per_Group::render_plugins_updates( $websites, $total_plugin_upgrades, $userExtension, $all_groups_sites, $all_groups, $site_offset, $trustedPlugins );
		} else {
			?>
				<!-- Per Item -->
				<?php
				MainWP_Updates_Per_Item::render_plugins_updates( $websites, $total_plugin_upgrades, $userExtension, $allPlugins, $pluginsInfo, $trustedPlugins );
		}
		?>
		</div>
		<!-- END Plugins Updates -->
		<?php
	}

	/**
	 * Method render_plugins_update_tab()
	 *
	 * Render themes update tab
	 *
	 * @param object $websites the websites
	 * @param int $total_theme_upgrades total updates
	 * @param object $userExtension user extension
	 * @param array $all_groups_sites all groups of sites
	 * @param array $all_groups all groups
	 * @param array $allThemes all themes
	 * @param array $themesInfo all themes info
	 * @param int $site_offset offset value
	 * @param string $site_view current site view
	 * 
	 * @return html render
	 */
	public static function render_themes_update_tab( $websites, $total_theme_upgrades, $userExtension, $all_groups_sites, $all_groups, $allThemes, $themesInfo, $site_offset, $site_view ) {

		$trustedThemes = json_decode( $userExtension->trusted_themes, true );
		if ( ! is_array( $trustedThemes ) ) {
			$trustedThemes = array();
		}

		?>
		<!-- Themes Updates -->
		<div class="ui active tab" data-tab="themes-updates">
		<?php
		if ( MAINWP_VIEW_PER_SITE == $site_view ) {
			?>
			<!-- Per Site -->
			<?php
			MainWP_Updates_Per_Site::render_themes_updates( $websites, $total_theme_upgrades, $userExtension, $trustedThemes );
		} elseif ( MAINWP_VIEW_PER_GROUP == $site_view ) {
			?>
			<!-- Per Group -->
			<?php
			MainWP_Updates_Per_Group::render_themes_updates( $websites, $total_theme_upgrades, $userExtension, $all_groups_sites, $all_groups, $site_offset, $trustedThemes );
		} else {
			?>
			<!-- Per Item -->
			<?php
			MainWP_Updates_Per_Item::render_themes_updates( $websites, $total_theme_upgrades, $userExtension, $allThemes, $themesInfo, $trustedThemes );
		}
		?>
		</div>
		<!-- END Themes Updates -->
		<?php
	}

	/**
	 * Method render_trans_update_tab()
	 *
	 * Render translations update tab
	 *
	 * @param object $websites the websites
	 * @param int $total_translation_upgrades total updates
	 * @param object $userExtension user extension
	 * @param array $all_groups_sites all groups of sites
	 * @param array $all_groups all groups
	 * @param array $allTranslations all translations
	 * @param array $translationsInfo translations info
	 * @param bool $mainwp_show_language_updates show translation update
	 * @param int $site_offset offset value
	 * @param string $site_view current site view
	 * 
	 * @return html render
	 */
	public static function render_trans_update_tab( $websites, $total_translation_upgrades, $userExtension, $all_groups_sites, $all_groups, $allTranslations, $translationsInfo, $mainwp_show_language_updates, $site_offset, $site_view ) {
		?>
		<!-- Translations Updates -->
		<?php if ( 1 === $mainwp_show_language_updates ) { ?>
		<div class="ui active tab" data-tab="translations-updates">
			<?php
			if ( MAINWP_VIEW_PER_SITE == $site_view ) {
				MainWP_Updates_Per_Site::render_trans_update( $websites, $total_translation_upgrades );
			} elseif ( MAINWP_VIEW_PER_GROUP == $site_view ) {
				MainWP_Updates_Per_Group::render_trans_update( $websites, $total_translation_upgrades, $all_groups_sites, $all_groups, $site_offset );
			} else {
				?>
				<!-- Per Item -->
				<?php MainWP_Updates_Per_Item::render_trans_update( $websites, $total_translation_upgrades, $userExtension, $allTranslations, $translationsInfo ); ?>
			<?php } ?>
		</div>
		<?php } ?>
		<!-- END Translations Updates -->
		<?php
	}

	/**
	 * Method render_abandoned_plugins_tab()
	 *
	 * Render abandoned plugins tab
	 *
	 * @param object $websites	the websites 	  
	 * @param array $all_groups_sites all groups of sites
	 * @param array $all_groups all groups
	 * @param array $allPluginsOutdate all outdate plugins
	 * @param array $decodedDismissedPlugins dismissed outdate plugins	  
	 * @param int $site_offset offset value
	 * @param string $site_view current site view
	 * 
	 * @return html render
	 */
	public static function render_abandoned_plugins_tab( $websites, $all_groups_sites, $all_groups, $allPluginsOutdate, $decodedDismissedPlugins, $site_offset, $site_view ) {
		?>
		<!-- Abandoned Plugins -->
		<div class="ui active tab" data-tab="abandoned-plugins">
		<?php
		if ( MAINWP_VIEW_PER_SITE == $site_view ) {
			?>
			<!-- Per Site -->
			<?php
			MainWP_Updates_Per_Site::render_abandoned_plugins( $websites, $decodedDismissedPlugins );
		} elseif ( MAINWP_VIEW_PER_GROUP == $site_view ) {
			?>
			<!-- Per Group -->
			<?php
			MainWP_Updates_Per_Group::render_abandoned_plugins( $websites, $all_groups_sites, $all_groups, $site_offset, $decodedDismissedPlugins );
		} else {
			?>
			<!-- Per Item -->
			<?php
			MainWP_Updates_Per_Item::render_abandoned_plugins( $websites, $allPluginsOutdate, $decodedDismissedPlugins );
		};
		?>
		</div>
		<!-- END Abandoned Plugins -->
		<?php
	}

	/**
	 * Method render_abandoned_themes_tab()
	 *
	 * Render abandoned themes tab
	 *
	 * @param object $websites	the websites 	 
	 * @param array $all_groups_sites all groups sites
	 * @param array $all_groups all groups
	 * @param array $allThemesOutdate all outdate themes
	 * @param array $decodedDismissedThemes	dismissed themes
	 * @param int $site_offset offset value
	 * @param string $site_view current site view
	 * 
	 * @return html render
	 */
	public static function render_abandoned_themes_tab( $websites, $all_groups_sites, $all_groups, $allThemesOutdate, $decodedDismissedThemes, $site_offset, $site_view ) {
		?>
		<!-- Abandoned Themes -->
		<div class="ui active tab" data-tab="abandoned-themes">
		<?php
		if ( MAINWP_VIEW_PER_SITE == $site_view ) {
			?>
			<!-- Per Site -->
			<?php
			MainWP_Updates_Per_Site::render_abandoned_themes( $websites, $decodedDismissedThemes );
		} elseif ( MAINWP_VIEW_PER_GROUP == $site_view ) {
			?>
			<!-- Per Group -->
			<?php
			MainWP_Updates_Per_Group::render_abandoned_themes( $websites, $all_groups_sites, $all_groups, $site_offset, $decodedDismissedThemes );
		} else {
			?>
			<!-- Per Item -->
			<?php
			MainWP_Updates_Per_Item::render_abandoned_themes( $websites, $allThemesOutdate, $decodedDismissedThemes );
			?>
		<?php }; ?>
		</div>
		<!-- END Abandoned Themes -->
		<?php
	}

	/**
	 * Method render_js_updates()
	 *
	 * Render js for update page
	 *
	 * @param string $site_view current site view
	 * @return html render
	 */
	public static function render_js_updates( $site_view ) {		
	?>		
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
		<?php

		if ( MAINWP_VIEW_PER_GROUP == $site_view ) {
			?>
			<script type="text/javascript">
				jQuery( document ).ready( function () {
					updatesoverview_updates_init_group_view();
				} );
			</script>
			<?php
		}
	}


	/**
	 * Method get_sites()
	 *
	 * Get sites for updates
	 *
	 * @return mixed results
	 */
	public static function get_sites() {
		global $current_user;
		$current_wpid = MainWP_Utility::get_current_wpid();
		if ( $current_wpid ) {
			$sql = MainWP_DB::instance()->get_sql_website_by_id( $current_wpid, false, array( 'premium_upgrades', 'plugins_outdate_dismissed', 'themes_outdate_dismissed', 'plugins_outdate_info', 'themes_outdate_info', 'favi_icon' ) );
		} else {
			$staging_enabled = is_plugin_active( 'mainwp-staging-extension/mainwp-staging-extension.php' ) || is_plugin_active( 'mainwp-timecapsule-extension/mainwp-timecapsule-extension.php' );
			$is_staging      = 'no';
			if ( $staging_enabled ) {
				$staging_updates_view = get_user_option( 'mainwp_staging_options_updates_view', $current_user->ID );
				if ( 'staging' === $staging_updates_view ) {
					$is_staging = 'yes';
				}
			}
			$sql = MainWP_DB::instance()->get_sql_websites_for_current_user( false, null, 'wp.url', false, false, null, false, array( 'premium_upgrades', 'plugins_outdate_dismissed', 'themes_outdate_dismissed', 'plugins_outdate_info', 'themes_outdate_info', 'favi_icon' ), $is_staging );
		}
		return MainWP_DB::instance()->query( $sql );
	}

	/**
	 * Method render_header_tabs()
	 *
	 * Render header tabs
	 *
	 * @param bool   $show_language_updates show language update.
	 * @param string $current_tab current tab.
	 * @param int    $total_wp_upgrades total WP update.
	 * @param int    $total_plugin_upgrades total plugins update.
	 * @param int    $total_theme_upgrades total themes update.
	 * @param int    $total_translation_upgrades total translation update.
	 * @param int    $total_plugins_outdate total plugins outdate.
	 * @param int    $total_themes_outdate total theme outdate.
	 * @param string $site_view current site view.
	 *
	 * @return html output
	 */
	public static function render_header_tabs( $show_language_updates, $current_tab, $total_wp_upgrades, $total_plugin_upgrades, $total_theme_upgrades, $total_translation_upgrades, $total_plugins_outdate, $total_themes_outdate, $site_view ) {
		?>
		<div id="mainwp-page-navigation-wrapper">
			<div class="ui secondary green pointing menu stackable mainwp-page-navigation">
				<a class="<?php echo( 'wordpress-updates' === $current_tab ? 'active' : '' ); ?> item" data-tab="wordpress-updates" href="admin.php?page=UpdatesManage&tab=wordpress-updates"><?php esc_html_e( 'WordPress Updates', 'mainwp' ); ?><div class="ui small <?php echo 0 === $total_wp_upgrades ? 'green' : 'red'; ?> label"><?php echo $total_wp_upgrades; ?></div></a>
				<a class="<?php echo( 'plugins-updates' === $current_tab ? 'active' : '' ); ?> item" data-tab="plugins-updates" href="admin.php?page=UpdatesManage&tab=plugins-updates"><?php esc_html_e( 'Plugins Updates', 'mainwp' ); ?><div class="ui small <?php echo 0 === $total_plugin_upgrades ? 'green' : 'red'; ?> label"><?php echo $total_plugin_upgrades; ?></div></a>
				<a class="<?php echo( 'themes-updates' === $current_tab ? 'active' : '' ); ?> item" data-tab="themes-updates" href="admin.php?page=UpdatesManage&tab=themes-updates"><?php esc_html_e( 'Themes Updates', 'mainwp' ); ?><div class="ui small <?php echo 0 === $total_theme_upgrades ? 'green' : 'red'; ?> label"><?php echo $total_theme_upgrades; ?></div></a>
				<?php if ( $show_language_updates ) : ?>
				<a class="<?php echo( 'translations-updates' === $current_tab ? 'active' : '' ); ?> item" data-tab="translations-updates" href="admin.php?page=UpdatesManage&tab=translations-updates"><?php esc_html_e( 'Translations Updates', 'mainwp' ); ?><div class="ui small <?php echo 0 === $total_translation_upgrades ? 'green' : 'red'; ?> label"><?php echo $total_translation_upgrades; ?></div></a>
				<?php endif; ?>
				<a class="<?php echo( 'abandoned-plugins' === $current_tab ? 'active' : '' ); ?> item" data-tab="abandoned-plugins" href="admin.php?page=UpdatesManage&tab=abandoned-plugins"><?php esc_html_e( 'Abandoned Plugins', 'mainwp' ); ?><div class="ui small <?php echo 0 === $total_plugins_outdate ? 'green' : 'red'; ?> label"><?php echo $total_plugins_outdate; ?></div></a>
				<a class="<?php echo( 'abandoned-themes' === $current_tab ? 'active' : '' ); ?> item" data-tab="abandoned-themes" href="admin.php?page=UpdatesManage&tab=abandoned-themes"><?php esc_html_e( 'Abandoned Themes', 'mainwp' ); ?><div class="ui small <?php echo 0 === $total_themes_outdate ? 'green' : 'red'; ?> label"><?php echo $total_themes_outdate; ?></div></a>
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
							<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
							<div class="inline field">
								<label for="mainwp_select_options_siteview"><?php esc_html_e( 'Show updates per ', 'mainwp' ); ?></label>
								<select class="ui dropdown" onchange="mainwp_siteview_onchange(this)"  id="mainwp_select_options_siteview" name="select_mainwp_options_siteview">
									<option value="1" class="item" <?php echo MAINWP_VIEW_PER_SITE == $site_view ? 'selected' : ''; ?>><?php esc_html_e( 'Site', 'mainwp' ); ?></option>
									<option value="0" class="item" <?php echo MAINWP_VIEW_PER_PLUGIN_THEME == $site_view ? 'selected' : ''; ?>><?php esc_html_e( 'Plugin/Theme', 'mainwp' ); ?></option>
									<option value="2" class="item" <?php echo MAINWP_VIEW_PER_GROUP == $site_view ? 'selected' : ''; ?>><?php esc_html_e( 'Group', 'mainwp' ); ?></option>
								</select>
							</div>
						</form>
					</div>
			</div>
			</div>
	</div>
		<?php
	}

	public static function render_twitter_notice() {

		if ( MainWP_Twitter::enabled_twitter_messages() ) {
			$filter = array(
				'upgrade_all_plugins',
				'upgrade_all_themes',
				'upgrade_all_wp_core',
			);
			foreach ( $filter as $what ) {
				$twitters = MainWP_Twitter::get_twitter_notice( $what );
				if ( is_array( $twitters ) ) {
					foreach ( $twitters as $timeid => $twit_mess ) {
						if ( ! empty( $twit_mess ) ) {
							$sendText = MainWP_Twitter::get_twit_to_send( $what, $timeid );
							if ( ! empty( $sendText ) ) {
								?>
								<div class="mainwp-tips ui info message twitter" style="margin:0"><i class="ui close icon mainwp-dismiss-twit"></i><span class="mainwp-tip" twit-what="<?php echo $what; ?>" twit-id="<?php echo $timeid; ?>"><?php echo $twit_mess; ?></span>&nbsp;<?php MainWP_Twitter::gen_twitter_button( $sendText ); ?></div>
								<?php
							}
						}
					}
				}
			}
		}
	}

	public static function render_http_checks( $websites ) {

		$enable_legacy_backup = get_option( 'mainwp_enableLegacyBackupFeature' );
		$mainwp_primaryBackup = get_option( 'mainwp_primaryBackup' );
		$customPage           = apply_filters_deprecated( 'mainwp-getcustompage-backups', array( false ), '4.0.1', 'mainwp_getcustompage_backups' ); // @deprecated Use 'mainwp_getcustompage_backups' instead.
		$customPage           = apply_filters( 'mainwp_getcustompage_backups', $customPage );

		$restorePageSlug = '';
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
						<th><?php esc_html_e( 'Website', 'mainwp' ); ?></th>
						<th><?php esc_html_e( 'HTTP Code', 'mainwp' ); ?></th>
						<th class="collapsing no-sort"></th>
					</tr>
				</thead>
				<tbody>
				<?php
				MainWP_DB::data_seek( $websites, 0 );
				while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
					if ( 1 == $website->offline_check_result || '-1' == $website->http_response_code ) {
						continue;
					}

					$restoreSlug = '';

					if ( ! empty( $restorePageSlug ) ) {
						if ( $enable_legacy_backup ) {
							$restoreSlug = $restorePageSlug . '&backupid=' . $website->id;
						} elseif ( self::activated_primary_backup_plugin( $mainwp_primaryBackup, $website ) ) {
							$restoreSlug = $restorePageSlug . '&id=' . $website->id;
						}
					}
					?>
					<tr>
						<td>
							<?php self::render_site_link_dashboard( $website ); ?>
						</td>
						<td id="wp_http_response_code_<?php echo esc_attr( $website->id ); ?>">
							<label class="ui red label http-code"><?php echo 'HTTP ' . $website->http_response_code; ?></label>
						</td>
						<td class="right aligned">
							<a href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo esc_attr( $website->id ); ?>" class="ui mini button" target="_blank"><?php esc_html_e( 'WP Admin', 'mainwp' ); ?></a>
							<a href="javascript:void(0)" onclick="return updatesoverview_recheck_http( this, <?php echo esc_attr( $website->id ); ?> )" class="ui basic mini green button"><?php esc_html_e( 'Recheck', 'mainwp' ); ?></a>
							<a href="javascript:void(0)" onClick="return updatesoverview_ignore_http_response( this, <?php echo esc_attr( $website->id ); ?> )" class="ui basic mini button"><?php esc_html_e( 'Ignore', 'mainwp' ); ?></a>
					<?php if ( ! empty( $restoreSlug ) ) { ?>
							<a href="<?php echo $restoreSlug; ?>" class="ui green mini basic button"><?php esc_html_e( 'Restore', 'mainwp' ); ?></a>
						<?php } ?>
						</td>
					</tr>
					<?php
				}
				?>
				</tbody>
				<tfoot>
					<tr>
						<th><?php esc_html_e( 'Website', 'mainwp' ); ?></th>
						<th><?php esc_html_e( 'HTTP Code', 'mainwp' ); ?></th>
						<th class="collapsing no-sort"></th>
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
		<?php
	}

	/**
	 * Method activated_primary_backup_plugin()
	 *
	 * Chek which primary backup plugin is being used.
	 *
	 * @param mixed $what Which backup plugin is being use.
	 * @param mixed $website Website array of information.
	 *
	 * @return boolean True|False.
	 */
	public static function activated_primary_backup_plugin( $what, $website ) {
		$plugins = json_decode( $website->plugins, 1 );
		if ( ! is_array( $plugins ) || 0 === count( $plugins ) ) {
			return false;
		}

		$checks = array(
			'backupbuddy'     => 'backupbuddy/backupbuddy.php',
			'backupwordpress' => 'backupwordpress/backupwordpress.php',
			'backupwp'        => array( 'backwpup/backwpup.php', 'backwpup-pro/backwpup.php' ),
			'updraftplus'     => 'updraftplus/updraftplus.php',

		);

		$slug = isset( $checks[ $what ] ) ? $checks[ $what ] : '';

		if ( empty( $slug ) ) {
			return false;
		}

		$installed = false;

		foreach ( $plugins as $plugin ) {
			if ( ( is_string( $slug ) && strtolower( $plugin['slug'] ) == $slug ) || ( is_array( $slug ) && in_array( $plugin['slug'], $slug ) ) ) {
				if ( $plugin['active'] ) {
					$installed = true;
				}
				break;
			}
		}

		return $installed;
	}

	/**
	 * Method set_continue_update_html_selector()
	 *
	 * @param string $current_update current update string
	 * @return none
	 */
	public static function set_continue_update_html_selector( $current_update, $slug = false ) {

		$check_slug = true;
		if ( ! empty( $slug ) ) {
			$check_slug = ( $slug == self::$continue_update_slug ) ? true : false;
		}

		if ( $check_slug && $current_update == self::$continue_update ) {
			self::$continue_selector = 'updatesoverview_continue_update_me';
		} else {
			self::$continue_selector = '';
		}
	}

	/**
	 * Method get_continue_update_selector()
	 *
	 * @param
	 * @return Get continue update html selector
	 */
	public static function get_continue_update_selector() {
		return self::$continue_selector;
	}

	public static function render_updates_modal() {
		?>
		<div class="ui modal" id="updatesoverview-backup-box">
			<div class="header"><?php esc_html_e( 'Backup Check', 'mainwp' ); ?></div>
			<div class="scrolling content mainwp-modal-content"></div>
			<div class="actions mainwp-modal-actions">
				<input id="updatesoverview-backup-all" type="button" name="Backup All" value="<?php esc_html_e( 'Backup All', 'mainwp' ); ?>" class="button-primary"/>
				<a id="updatesoverview-backup-now" href="javascript:void(0)" target="_blank" style="display: none"  class="button-primary button"><?php esc_html_e( 'Backup Now', 'mainwp' ); ?></a>&nbsp;
				<input id="updatesoverview-backup-ignore" type="button" name="Ignore" value="<?php esc_html_e( 'Ignore', 'mainwp' ); ?>" class="button"/>
			</div>
		</div>
		<?php
	}

	/**
	 * Hook the section help content to the Help Sidebar element
	 */

	public static function mainwp_help_content() {
		if ( isset( $_GET['page'] ) && 'UpdatesManage' === $_GET['page'] ) {
			?>
			<p><?php esc_html_e( 'If you need help with managing updates, please review following help documents', 'mainwp' ); ?></p>
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
