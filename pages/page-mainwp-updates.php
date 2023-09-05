<?php
/**
 * MainWP Updates Page.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Updates
 *
 * @package MainWP\Dashboard
 */
class MainWP_Updates {

	/**
	 *  User can ignore updates.
	 *
	 * @var bool $user_can_ignore_updates User can ignore updates.
	 */
	public static $user_can_ignore_updates = null;

	/**
	 *  User can update translations.
	 *
	 * @var bool $user_can_update_trans User can update translations.
	 */
	public static $user_can_update_trans = null;

	/**
	 *  User can update WordPress core files.
	 *
	 * @var bool $user_can_update_wp User can update WordPress core files.
	 */
	public static $user_can_update_wp = null;

	/**
	 *  User can update themes.
	 *
	 * @var bool $user_can_update_themes User can update themes.
	 */
	public static $user_can_update_themes = null;

	/**
	 *  User can update plugins.
	 *
	 * @var bool $user_can_update_plugins User can update plugins.
	 */
	public static $user_can_update_plugins = null;

	/**
	 * Placeholder for continue selector.
	 *
	 * @var string $continue_selector Placeholder for continue selector.
	 */
	public static $continue_selector = '';

	/**
	 * Placeholder for continue update.
	 *
	 * @var string $continue_update Placeholder for continue update.
	 */
	public static $continue_update = '';

	/**
	 * Placeholder for continue update slug.
	 *
	 * @var string $continue_update_slug Placeholder for continue update slug.
	 */
	public static $continue_update_slug = '';

	/**
	 * Public static varable to hold Subpages information.
	 *
	 * @var array $subPages
	 */
	public static $subPages;

	/**
	 * Current page.
	 *
	 * @static
	 * @var string $page Current page.
	 */
	public static $page;

	/**
	 * Gets Class Name.
	 *
	 * @return object
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Instantiates MainWP Updates Page.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Post::get_class_name()
	 */
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
	 * Renders init updates menu.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Menu::add_left_menu()
	 */
	public static function init_menu() {
		self::init_left_menu( self::$subPages );
		add_action( 'load-' . self::$page, array( self::get_class_name(), 'on_load_page' ) );
	}

	/**
	 * Initiates Updates menu.
	 *
	 * @param array $subPages Sub pages array.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Menu::add_left_menu()
	 * @uses \MainWP\Dashboard\MainWP_Menu::init_subpages_left_menu()
	 * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
	 */
	public static function init_left_menu( $subPages = array() ) {
		self::$page = add_submenu_page(
			'mainwp_tab',
			__( 'Updates', 'mainwp' ),
			'<span id="mainwp-Updates">' . esc_html__( 'Updates', 'mainwp' ) . '</span>',
			'read',
			'UpdatesManage',
			array(
				self::get_class_name(),
				'render',
			)
		);

		MainWP_Menu::add_left_menu(
			array(
				'title'      => esc_html__( 'Updates', 'mainwp' ),
				'parent_key' => 'managesites',
				'slug'       => 'UpdatesManage',
				'href'       => 'admin.php?page=UpdatesManage',
				'icon'       => '<i class="sync icon"></i>',
				'desc'       => 'Manage updates on your child sites',
			),
			1
		);

		$show_language_updates = get_option( 'mainwp_show_language_updates', 1 );

		if ( $show_language_updates ) {

			$init_sub_subleftmenu = array(
				array(
					'title'      => esc_html__( 'Plugins Updates', 'mainwp' ),
					'parent_key' => 'UpdatesManage',
					'href'       => 'admin.php?page=UpdatesManage&tab=plugins-updates',
					'slug'       => 'UpdatesManage',
					'right'      => '',
				),
				array(
					'title'      => esc_html__( 'Themes Updates', 'mainwp' ),
					'parent_key' => 'UpdatesManage',
					'href'       => 'admin.php?page=UpdatesManage&tab=themes-updates',
					'slug'       => 'UpdatesManage',
					'right'      => '',
				),
				array(
					'title'      => esc_html__( 'WordPress Updates', 'mainwp' ),
					'parent_key' => 'UpdatesManage',
					'href'       => 'admin.php?page=UpdatesManage&tab=wordpress-updates',
					'slug'       => 'UpdatesManage&tab=wordpress-updates',
					'right'      => '',
				),
				array(
					'title'      => esc_html__( 'Translation Updates', 'mainwp' ),
					'parent_key' => 'UpdatesManage',
					'href'       => 'admin.php?page=UpdatesManage&tab=translations-updates',
					'slug'       => 'UpdatesManage&tab=translations-updates',
					'right'      => '',
				),
				array(
					'title'      => esc_html__( 'Abandoned Plugins', 'mainwp' ),
					'parent_key' => 'UpdatesManage',
					'href'       => 'admin.php?page=UpdatesManage&tab=abandoned-plugins',
					'slug'       => 'UpdatesManage&tab=abandoned-plugins',
					'right'      => '',
				),
				array(
					'title'      => esc_html__( 'Abandoned Themes', 'mainwp' ),
					'parent_key' => 'UpdatesManage',
					'href'       => 'admin.php?page=UpdatesManage&tab=abandoned-themes',
					'slug'       => 'UpdatesManage',
					'right'      => '',
				),
			);
		} else {
			$init_sub_subleftmenu = array(
				array(
					'title'      => esc_html__( 'Plugins Updates', 'mainwp' ),
					'parent_key' => 'UpdatesManage',
					'href'       => 'admin.php?page=UpdatesManage&tab=plugins-updates',
					'slug'       => 'UpdatesManage',
					'right'      => '',
				),
				array(
					'title'      => esc_html__( 'Themes Updates', 'mainwp' ),
					'parent_key' => 'UpdatesManage',
					'href'       => 'admin.php?page=UpdatesManage&tab=themes-updates',
					'slug'       => 'UpdatesManage',
					'right'      => '',
				),
				array(
					'title'      => esc_html__( 'WordPress Updates', 'mainwp' ),
					'parent_key' => 'UpdatesManage',
					'href'       => 'admin.php?page=UpdatesManage&tab=wordpress-updates',
					'slug'       => 'UpdatesManage&tab=wordpress-updates',
					'right'      => '',
				),
				array(
					'title'      => esc_html__( 'Abandoned Plugins', 'mainwp' ),
					'parent_key' => 'UpdatesManage',
					'href'       => 'admin.php?page=UpdatesManage&tab=abandoned-plugins',
					'slug'       => 'UpdatesManage&tab=abandoned-plugins',
					'right'      => '',
				),
				array(
					'title'      => esc_html__( 'Abandoned Themes', 'mainwp' ),
					'parent_key' => 'UpdatesManage',
					'href'       => 'admin.php?page=UpdatesManage&tab=abandoned-themes',
					'slug'       => 'UpdatesManage',
					'right'      => '',
				),
			);
		}

		$init_sub_subleftmenu = apply_filters( 'mainwp_sub_leftmenu_updates', $init_sub_subleftmenu );

		MainWP_Menu::init_subpages_left_menu( $subPages, $init_sub_subleftmenu, 'UpdatesManage', 'UpdatesManage' );

		foreach ( $init_sub_subleftmenu as $item ) {
			if ( MainWP_Menu::is_disable_menu_item( 3, $item['slug'] ) ) {
				continue;
			}
			MainWP_Menu::add_left_menu( $item, 2 );
		}
	}

	/**
	 * Method on_load_page()
	 *
	 * Run on page load.
	 */
	public static function on_load_page() {
		add_filter( 'mainwp_header_actions_right', array( self::get_class_name(), 'screen_options' ), 10, 2 );
	}

	/**
	 * Method screen_options()
	 *
	 * Create Page Settings button.
	 *
	 * @param mixed $input Page Settings button HTML.
	 *
	 * @return mixed Page Settings button.
	 */
	public static function screen_options( $input ) {
		return $input .
				'<a class="ui button basic icon" onclick="mainwp_manage_updates_screen_options(); return false;" data-inverted="" data-position="bottom right" href="#" target="_blank" data-tooltip="' . esc_html__( 'Page Settings', 'mainwp' ) . '">
					<i class="cog icon"></i>
				</a>';
	}

	/**
	 * Sets the MainWP Update page page title and pass it off to method MainWP_UI::render_top_header().
	 *
	 * @param string $shownPage The page slug shown at this moment.
	 *
	 * @uses \MainWP\Dashboard\MainWP_UI::render_top_header()
	 */
	public static function render_header( $shownPage = '' ) {

		$params = array(
			'title' => esc_html__( 'Available Updates', 'mainwp' ),
		);

		MainWP_UI::render_top_header( $params );
	}

	/**
	 * Closes the page container.
	 */
	public static function render_footer() {
		echo '</div>';
	}

	/**
	 * Generates individual site overview page link.
	 *
	 * @param object $website The site object.
	 * @param bool   $echo Either echo or not.
	 *
	 * @return string Dashboard link.
	 */
	public static function render_site_link_dashboard( $website, $echo = true ) {
		$lnk = '<a href="' . esc_url( admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ) ) . '"  data-inverted="" data-tooltip="' . esc_html__( 'Visit this dashboard.', 'mainwp' ) . '">' . stripslashes( $website->name ) . '</a>';
		if ( $echo ) {
			echo $lnk; // phpcs:ignore WordPress.Security.EscapeOutput
		} else {
			return $lnk;
		}
	}

	/**
	 * Checks if the current user has permission to uignore updates.
	 *
	 * @return bool Whether user can ignore updates or not.
	 */
	public static function user_can_ignore_updates() {
		if ( null === self::$user_can_ignore_updates ) {
			self::$user_can_ignore_updates = mainwp_current_user_have_right( 'dashboard', 'ignore_unignore_updates' );
		}
		return self::$user_can_ignore_updates;
	}

	/**
	 * Checks if the current user has permission to update translations.
	 *
	 * @return bool Whether user can update translations or not.
	 */
	public static function user_can_update_trans() {
		if ( null === self::$user_can_update_trans ) {
			self::$user_can_update_trans = mainwp_current_user_have_right( 'dashboard', 'update_translations' );
		}
		return self::$user_can_update_trans;
	}

	/**
	 * Checks if the current user has permission to update WordPress core files.
	 *
	 * @return bool Whether user can update WordPress or not.
	 */
	public static function user_can_update_wp() {
		if ( null === self::$user_can_update_wp ) {
			self::$user_can_update_wp = mainwp_current_user_have_right( 'dashboard', 'update_wordpress' );
		}
		return self::$user_can_update_wp;
	}

	/**
	 * Checks if the current user has permission to update themes.
	 *
	 * @return bool Whether user can update themes or not.
	 */
	public static function user_can_update_themes() {
		if ( null === self::$user_can_update_themes ) {
			self::$user_can_update_themes = mainwp_current_user_have_right( 'dashboard', 'update_themes' );
		}
		return self::$user_can_update_themes;
	}

	/**
	 * Checks if the current user has permission to update plugins.
	 *
	 * @return bool Whether user can update plugins or not.
	 */
	public static function user_can_update_plugins() {
		if ( null === self::$user_can_update_plugins ) {
			self::$user_can_update_plugins = mainwp_current_user_have_right( 'dashboard', 'update_plugins' );
		}
		return self::$user_can_update_plugins;
	}

	/**
	 * Renders updates page.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::get_user_extension()
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::get_groups_for_current_user()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_websites_by_group_id()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
	 * @uses \MainWP\Dashboard\MainWP_Utility::array_sort()
	 */
	public static function render() { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- current complexity is the only way to achieve desired results, pull request solutions appreciated.
		$websites      = self::get_sites();
		$userExtension = MainWP_DB_Common::instance()->get_user_extension();
		$site_view     = $userExtension->site_view;

		$site_offset_for_groups = array();
		$all_groups             = array();
		$sites_in_groups        = array();
		$all_groups_sites       = array();

		if ( MAINWP_VIEW_PER_GROUP == $site_view ) {
			$groups = MainWP_DB_Common::instance()->get_groups_for_current_user();
			foreach ( $groups as $group ) {
				$all_groups[ $group->id ] = $group->name;
			}
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
				$site_offset_for_groups[ $website->id ] = $pos;
				$pos++;
				if ( ! isset( $sites_in_groups[ $website->id ] ) ) {
					$sites_not_in_groups[] = $website->id;
				}
			}

			if ( 0 < count( $sites_not_in_groups ) ) {
				$all_groups_sites[0] = $sites_not_in_groups;
				$all_groups[0]       = esc_html__( 'Others', 'mainwp' );
			}
		}

		$decodedDismissedPlugins = json_decode( $userExtension->dismissed_plugins, true );
		$decodedDismissedThemes  = json_decode( $userExtension->dismissed_themes, true );

		$total_wp_upgrades          = 0;
		$total_plugin_upgrades      = 0;
		$total_translation_upgrades = 0;
		$total_theme_upgrades       = 0;
		$total_sync_errors          = 0;
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

			$wp_upgrades = MainWP_DB::instance()->get_website_option( $website, 'wp_upgrades' );
			$wp_upgrades = ( '' != $wp_upgrades ) ? json_decode( $wp_upgrades, true ) : array();

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

			$decodedPremiumUpgrades = MainWP_DB::instance()->get_website_option( $website, 'premium_upgrades' );
			$decodedPremiumUpgrades = ( '' != $decodedPremiumUpgrades ) ? json_decode( $decodedPremiumUpgrades, true ) : array();

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

			if ( is_array( $plugin_upgrades ) && ! $website->is_ignorePluginUpdates ) {
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
			$ignoredAbandoned_themes         = MainWP_DB::instance()->get_website_option( $website, 'themes_outdate_dismissed' );
			$ignoredAbandoned_themes         = ( '' != $ignoredAbandoned_themes ) ? json_decode( $ignoredAbandoned_themes, true ) : array();

			if ( is_array( $ignoredAbandoned_themes ) ) {
				$ignoredAbandoned_themes         = array_filter( $ignoredAbandoned_themes );
				$themesIgnoredAbandoned_perSites = array_merge( $themesIgnoredAbandoned_perSites, $ignoredAbandoned_themes );
			}

			$ignoredAbandoned_plugins = MainWP_DB::instance()->get_website_option( $website, 'plugins_outdate_dismissed' );
			$ignoredAbandoned_plugins = ( '' != $ignoredAbandoned_plugins ) ? json_decode( $ignoredAbandoned_plugins, true ) : array();

			$plugins_outdate = MainWP_DB::instance()->get_website_option( $website, 'plugins_outdate_info' );
			$plugins_outdate = ( '' != $plugins_outdate ) ? json_decode( $plugins_outdate, true ) : array();

			$themes_outdate = MainWP_DB::instance()->get_website_option( $website, 'themes_outdate_info' );
			$themes_outdate = ( '' != $themes_outdate ) ? json_decode( $themes_outdate, true ) : array();

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
							'name'    => isset( $translation_upgrade['name'] ) ? esc_html( $translation_upgrade['name'] ) : $slug,
							'slug'    => $slug,
							'version' => esc_html( $translation_upgrade['version'] ),
						);
					}
				}

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
							'name'      => esc_html( $plugin_upgrade['Name'] ),
							'slug'      => isset( $plugin_upgrade['update']['slug'] ) ? esc_html( $plugin_upgrade['update']['slug'] ) : '',
							'premium'   => ( isset( $plugin_upgrade['premium'] ) ? esc_html( $plugin_upgrade['premium'] ) : 0 ),
							'PluginURI' => esc_html( $plugin_upgrade['PluginURI'] ),
						);
					}
				}

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
							'name'    => esc_html( $theme_upgrade['Name'] ),
							'premium' => ( isset( $theme_upgrade['premium'] ) ? esc_html( $theme_upgrade['premium'] ) : 0 ),
						);
					}
				}

				if ( is_array( $plugins_outdate ) ) {
					foreach ( $plugins_outdate as $slug => $plugin_outdate ) {
						$slug = esc_html( $slug );
						if ( ! isset( $allPluginsOutdate[ $slug ] ) ) {
							$allPluginsOutdate[ $slug ] = array(
								'name' => esc_html( $plugin_outdate['Name'] ),
								'cnt'  => 1,
								'uri'  => esc_html( $plugin_outdate['PluginURI'] ),
							);
						} else {
							$allPluginsOutdate[ $slug ]['cnt'] ++;
						}
					}
				}

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
			}

			if ( '' != $website->sync_errors ) {
				$total_sync_errors ++;
			}
		}

		/**
		 * Filter: mainwp_updates_translation_sort_by
		 *
		 * Filters the default sorting option for Translation updates.
		 *
		 * @since 4.1
		 */
		$allTranslationsSortBy = apply_filters( 'mainwp_updates_translation_sort_by', 'name' );

		/**
		 * Filter: mainwp_updates_plugins_sort_by
		 *
		 * Filters the default sorting option for Plugin updates.
		 *
		 * @since 4.1
		 */
		$allPluginsSortBy = apply_filters( 'mainwp_updates_plugins_sort_by', 'name' );

		/**
		 * Filter: mainwp_updates_themes_sort_by
		 *
		 * Filters the default sorting option for Theme updates.
		 *
		 * @since 4.1
		 */
		$allThemesSortBy = apply_filters( 'mainwp_updates_themes_sort_by', 'name' );

		/**
		 * Filter: mainwp_updates_abandoned_plugins_sort_by
		 *
		 * Filters the default sorting option for Abandoned plugins.
		 *
		 * @since 4.1
		 */
		$allPluginsOutdateSortBy = apply_filters( 'mainwp_updates_abandoned_plugins_sort_by', 'name' );

		/**
		 * Filter: mainwp_updates_abandoned_themes_sort_by
		 *
		 * Filters the default sorting option for Abandoned themes.
		 *
		 * @since 4.1
		 */
		$allThemesOutdateSortBy = apply_filters( 'mainwp_updates_abandoned_themes_sort_by', 'name' );

		MainWP_Utility::array_sort( $allTranslations, $allTranslationsSortBy );
		MainWP_Utility::array_sort( $allPlugins, $allPluginsSortBy );
		MainWP_Utility::array_sort( $allThemes, $allThemesSortBy );
		MainWP_Utility::array_sort( $allPluginsOutdate, $allPluginsOutdateSortBy );
		MainWP_Utility::array_sort( $allThemesOutdate, $allThemesOutdateSortBy );

		$mainwp_show_language_updates = get_option( 'mainwp_show_language_updates', 1 );

		/**
		 * Limits number of updates to process.
		 *
		 * Limits the number of updates that will be processed in a single run on Update Everything action.
		 *
		 * @since 4.0
		 */
		$limit_updates_all = apply_filters( 'mainwp_limit_updates_all', 0 );
		// phpcs:disable WordPress.Security.NonceVerification
		if ( 0 < $limit_updates_all ) {
			if ( isset( $_GET['continue_update'] ) && '' !== $_GET['continue_update'] ) {
				self::$continue_update = sanitize_text_field( wp_unslash( $_GET['continue_update'] ) );
				if ( 'plugins_upgrade_all' === self::$continue_update || 'themes_upgrade_all' === self::$continue_update || 'translations_upgrade_all' === self::$continue_update ) {
					if ( isset( $_GET['slug'] ) && '' !== $_GET['slug'] ) {
						self::$continue_update_slug = wp_unslash( $_GET['slug'] );
					}
				}
			}
		}

		$current_tab = '';

		if ( isset( $_GET['tab'] ) ) {
			$current_tab = sanitize_text_field( wp_unslash( $_GET['tab'] ) );
			if ( ! in_array( $current_tab, array( 'wordpress-updates', 'plugins-updates', 'themes-updates', 'translations-updates', 'abandoned-plugins', 'abandoned-themes', true ) ) ) {
				$current_tab = 'plugins-updates';
			}
		} else {
			$current_tab = 'plugins-updates';
		}
		// phpcs:enable WordPress.Security.NonceVerification
		self::render_header( 'UpdatesManage' );

		self::render_header_tabs( $mainwp_show_language_updates, $current_tab, $total_wp_upgrades, $total_plugin_upgrades, $total_theme_upgrades, $total_translation_upgrades, $total_plugins_outdate, $total_themes_outdate, $site_view );

		?>
		<div class="ui segment" id="mainwp-manage-updates">
			<?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-manage-updates-message' ) ) : ?>
				<div class="ui info message">
					<i class="close icon mainwp-notice-dismiss" notice-id="mainwp-manage-updates-message"></i>
					<div><?php echo sprintf( esc_html__( 'Manage available updates for all your child sites.  From here, you can update update %1$splugins%2$s, %3$sthemes%4$s, and %5$sWordPress core%6$s.', 'mainwp' ), '<a href="https://kb.mainwp.com/docs/update-plugins/" target="_blank">', '</a>', '<a href="https://kb.mainwp.com/docs/update-themes/" target="_blank">', '</a>', '<a href="https://kb.mainwp.com/docs/update-wordpress-core/" target="_blank">', '</a>' ); ?></div>
					<div><?php echo sprintf( esc_html__( 'Also, from here, you can ignore updates for %1$sWordPress core%2$s, %3$splugins%4$s, and %5$sthemes%6$s.', 'mainwp' ), '<a href="https://kb.mainwp.com/docs/ignore-wordpress-core-update/" target="_blank">', '</a>', '<a href="https://kb.mainwp.com/docs/ignore-plugin-updates/" target="_blank">', '</a>', '<a href="https://kb.mainwp.com/docs/ignore-theme-updates/" target="_blank">', '</a>' ); ?></div>
				</div>
			<?php endif; ?>
		<?php

		$hooks_tabs = apply_filters( 'mainwp_pages_updates_render_tabs', false, $current_tab );

		if ( false === $hooks_tabs ) {
			if ( 'wordpress-updates' === $current_tab ) {
				self::render_wp_update_tab( $websites, $total_wp_upgrades, $all_groups_sites, $all_groups, $site_offset_for_groups, $site_view );
			} elseif ( 'plugins-updates' === $current_tab ) {
				self::render_plugins_update_tab( $websites, $total_plugin_upgrades, $userExtension, $all_groups_sites, $all_groups, $allPlugins, $pluginsInfo, $site_offset_for_groups, $site_view );
			} elseif ( 'themes-updates' === $current_tab ) {
				self::render_themes_update_tab( $websites, $total_theme_upgrades, $userExtension, $all_groups_sites, $all_groups, $allThemes, $themesInfo, $site_offset_for_groups, $site_view );
			} elseif ( 'translations-updates' === $current_tab ) {
				self::render_trans_update_tab( $websites, $total_translation_upgrades, $userExtension, $all_groups_sites, $all_groups, $allTranslations, $translationsInfo, $mainwp_show_language_updates, $site_offset_for_groups, $site_view );
			} elseif ( 'abandoned-plugins' === $current_tab ) {
				self::render_abandoned_plugins_tab( $websites, $all_groups_sites, $all_groups, $allPluginsOutdate, $decodedDismissedPlugins, $site_offset_for_groups, $site_view );
			} elseif ( 'abandoned-themes' === $current_tab ) {
				self::render_abandoned_themes_tab( $websites, $all_groups_sites, $all_groups, $allThemesOutdate, $decodedDismissedThemes, $site_offset_for_groups, $site_view );
			}
			?>
			</div>
			<?php
			self::render_js_updates( $site_view );
		}

		self::render_updates_modal();

		self::render_plugin_details_modal();
		MainWP_UI::render_modal_upload_icon();
		self::render_screen_options_modal();
		self::render_footer();
		?>
		<script type="text/javascript">
		mainwp_manage_updates_screen_options = function () {
			jQuery( '#mainwp-manage-updates-screen-options-modal' ).modal( 'show' );
			jQuery( '#manage-updates-screen-options-form' ).submit( function() {
				jQuery( '#mainwp-manage-updates-screen-options-modal' ).modal( 'hide' );
			} );
			return false;
		};
		</script>
		<?php
	}

	/**
	 * Render WP updates tab.
	 *
	 * @param object $websites               Object containing child sites info.
	 * @param int    $total_wp_upgrades      Number of available WP upates.
	 * @param array  $all_groups_sites       Array containing all groups and sites.
	 * @param array  $all_groups             Array containing all groups.
	 * @param int    $site_offset_for_groups Offset value.
	 * @param string $site_view              Current view.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
	 * @uses \MainWP\Dashboard\MainWP_Updates_Per_Group::render_wpcore_updates()
	 * @uses \MainWP\Dashboard\MainWP_Updates_Per_Site::render_wpcore_updates()
	 */
	public static function render_wp_update_tab( $websites, $total_wp_upgrades, $all_groups_sites, $all_groups, $site_offset_for_groups, $site_view ) {
		?>
			<div class="ui active tab" data-tab="wordpress-updates">
				<?php
				/**
				 * Action: mainwp_updates_before_wp_updates
				 *
				 * Fires at the top of the WP updates tab.
				 *
				 * @param object $websites               Object containing child sites info.
				 * @param int    $total_wp_upgrades      Number of available WP upates.
				 * @param array  $all_groups_sites       Array containing all groups and sites.
				 * @param array  $all_groups             Array containing all groups.
				 * @param int    $site_offset_for_groups Offset value.
				 *
				 * @since 4.1
				 */
				do_action( 'mainwp_updates_before_wp_updates', $websites, $total_wp_upgrades, $all_groups_sites, $all_groups, $site_offset_for_groups );
				if ( MAINWP_VIEW_PER_GROUP == $site_view ) {
					/**
					 * Action: mainwp_updates_pergroup_before_wp_updates
					 *
					 * Fires at the top of the WP updates tab, per Group view.
					 *
					 * @param object $websites               Object containing child sites info.
					 * @param int    $total_wp_upgrades      Number of available WP upates.
					 * @param array  $all_groups_sites       Array containing all groups and sites.
					 * @param array  $all_groups             Array containing all groups.
					 * @param int    $site_offset_for_groups Offset value.
					 *
					 * @since 4.1
					 */
					do_action( 'mainwp_updates_pergroup_before_wp_updates', $websites, $total_wp_upgrades, $all_groups_sites, $all_groups, $site_offset_for_groups );
					MainWP_Updates_Per_Group::render_wpcore_updates( $websites, $total_wp_upgrades, $all_groups_sites, $all_groups, $site_offset_for_groups );
					/**
					 * Action: mainwp_updates_pergroup_after_wp_updates
					 *
					 * Fires at the bottom of the WP updates tab, per Group view.
					 *
					 * @param object $websites               Object containing child sites info.
					 * @param int    $total_wp_upgrades      Number of available WP upates.
					 * @param array  $all_groups_sites       Array containing all groups and sites.
					 * @param array  $all_groups             Array containing all groups.
					 * @param int    $site_offset_for_groups Offset value.
					 *
					 * @since 4.1
					 */
					do_action( 'mainwp_updates_pergroup_after_wp_updates', $websites, $total_wp_upgrades, $all_groups_sites, $all_groups, $site_offset_for_groups );
				} else {
					/**
					 * Action: mainwp_updates_persite_before_wp_updates
					 *
					 * Fires at the top of the WP updates tab, per Site view.
					 *
					 * @param object $websites               Object containing child sites info.
					 * @param int    $total_wp_upgrades      Number of available WP upates.
					 * @param array  $all_groups_sites       Array containing all groups and sites.
					 * @param array  $all_groups             Array containing all groups.
					 * @param int    $site_offset_for_groups Offset value.
					 *
					 * @since 4.1
					 */
					do_action( 'mainwp_updates_pergroup_before_wp_updates', $websites, $total_wp_upgrades, $all_groups_sites, $all_groups, $site_offset_for_groups );
					MainWP_DB::data_seek( $websites, 0 );
					MainWP_Updates_Per_Site::render_wpcore_updates( $websites, $total_wp_upgrades );
					/**
					 * Action: mainwp_updates_persite_after_wp_updates
					 *
					 * Fires at the bottom of the WP updates tab, per Site view.
					 *
					 * @param object $websites               Object containing child sites info.
					 * @param int    $total_wp_upgrades      Number of available WP upates.
					 * @param array  $all_groups_sites       Array containing all groups and sites.
					 * @param array  $all_groups             Array containing all groups.
					 * @param int    $site_offset_for_groups Offset value.
					 *
					 * @since 4.1
					 */
					do_action( 'mainwp_updates_persite_after_wp_updates', $websites, $total_wp_upgrades, $all_groups_sites, $all_groups, $site_offset_for_groups );
				};
				/**
				 * Action: mainwp_updates_after_wp_updates
				 *
				 * Fires at the top of the WP updates tab.
				 *
				 * @param object $websites               Object containing child sites info.
				 * @param int    $total_wp_upgrades      Number of available WP upates.
				 * @param array  $all_groups_sites       Array containing all groups and sites.
				 * @param array  $all_groups             Array containing all groups.
				 * @param int    $site_offset_for_groups Offset value.
				 *
				 * @since 4.1
				 */
				do_action( 'mainwp_updates_after_wp_updates', $websites, $total_wp_upgrades, $all_groups_sites, $all_groups, $site_offset_for_groups );
				?>
			</div>
		<?php
	}

	/**
	 * Renders WP updates tab.
	 *
	 * @param object $websites               Object containing child sites info.
	 * @param int    $total_plugin_upgrades  Number of available plugin updates.
	 * @param object $userExtension          User extension.
	 * @param array  $all_groups_sites       Array of all groups and sites.
	 * @param array  $all_groups             Array of all groups.
	 * @param array  $allPlugins             Array of all plugins.
	 * @param array  $pluginsInfo            Array of all plugins info.
	 * @param int    $site_offset_for_groups Offset value.
	 * @param string $site_view              Current view.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Updates_Per_Group::render_plugins_updates()
	 * @uses \MainWP\Dashboard\MainWP_Updates_Per_Item::render_plugins_updates()
	 * @uses \MainWP\Dashboard\MainWP_Updates_Per_Site::render_plugins_updates()
	 */
	public static function render_plugins_update_tab( $websites, $total_plugin_upgrades, $userExtension, $all_groups_sites, $all_groups, $allPlugins, $pluginsInfo, $site_offset_for_groups, $site_view ) {
		$trustedPlugins = json_decode( $userExtension->trusted_plugins, true );
		if ( ! is_array( $trustedPlugins ) ) {
			$trustedPlugins = array();
		}
		?>
		<div class="ui active tab" data-tab="plugins-updates">
		<?php
		/**
		 * Action: mainwp_updates_before_plugin_updates
		 *
		 * Fires at the top of the Plugin updates tab.
		 *
		 * @param object $websites               Object containing child sites info.
		 * @param int    $total_plugin_upgrades  Number of available plugin updates.
		 * @param object $userExtension          User extension.
		 * @param array  $all_groups_sites       Array of all groups and sites.
		 * @param array  $all_groups             Array of all groups.
		 * @param array  $allPlugins             Array of all plugins.
		 * @param array  $pluginsInfo            Array of all plugins info.
		 * @param int    $site_offset_for_groups Offset value.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_updates_before_plugin_updates', $websites, $total_plugin_upgrades, $userExtension, $all_groups_sites, $all_groups, $allPlugins, $pluginsInfo, $site_offset_for_groups );
		if ( MAINWP_VIEW_PER_SITE == $site_view ) {
			/**
			 * Action: mainwp_updates_persite_before_plugin_updates
			 *
			 * Fires at the top of the Plugin updates tab, per Site view.
			 *
			 * @param object $websites               Object containing child sites info.
			 * @param int    $total_plugin_upgrades  Number of available plugin updates.
			 * @param object $userExtension          User extension.
			 * @param array  $all_groups_sites       Array of all groups and sites.
			 * @param array  $all_groups             Array of all groups.
			 * @param array  $allPlugins             Array of all plugins.
			 * @param array  $pluginsInfo            Array of all plugins info.
			 * @param int    $site_offset_for_groups Offset value.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_updates_persite_before_plugin_updates', $websites, $total_plugin_upgrades, $userExtension, $all_groups_sites, $all_groups, $allPlugins, $pluginsInfo, $site_offset_for_groups );
				MainWP_Updates_Per_Site::render_plugins_updates( $websites, $total_plugin_upgrades, $userExtension, $trustedPlugins );
			/**
			 * Action: mainwp_updates_persite_after_plugin_updates
			 *
			 * Fires at the bottom of the Plugin updates tab, per Site view.
			 *
			 * @param object $websites               Object containing child sites info.
			 * @param int    $total_plugin_upgrades  Number of available plugin updates.
			 * @param object $userExtension          User extension.
			 * @param array  $all_groups_sites       Array of all groups and sites.
			 * @param array  $all_groups             Array of all groups.
			 * @param array  $allPlugins             Array of all plugins.
			 * @param array  $pluginsInfo            Array of all plugins info.
			 * @param int    $site_offset_for_groups Offset value.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_updates_persite_after_plugin_updates', $websites, $total_plugin_upgrades, $userExtension, $all_groups_sites, $all_groups, $allPlugins, $pluginsInfo, $site_offset_for_groups );
		} elseif ( MAINWP_VIEW_PER_GROUP == $site_view ) {
			/**
			 * Action: mainwp_updates_pergroup_before_plugin_updates
			 *
			 * Fires at the top of the Plugin updates tab, per Group view.
			 *
			 * @param object $websites               Object containing child sites info.
			 * @param int    $total_plugin_upgrades  Number of available plugin updates.
			 * @param object $userExtension          User extension.
			 * @param array  $all_groups_sites       Array of all groups and sites.
			 * @param array  $all_groups             Array of all groups.
			 * @param array  $allPlugins             Array of all plugins.
			 * @param array  $pluginsInfo            Array of all plugins info.
			 * @param int    $site_offset_for_groups Offset value.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_updates_pergroup_before_plugin_updates', $websites, $total_plugin_upgrades, $userExtension, $all_groups_sites, $all_groups, $allPlugins, $pluginsInfo, $site_offset_for_groups );
				MainWP_Updates_Per_Group::render_plugins_updates( $websites, $total_plugin_upgrades, $userExtension, $all_groups_sites, $all_groups, $site_offset_for_groups, $trustedPlugins );
			/**
			 * Action: mainwp_updates_pergroup_after_plugin_updates
			 *
			 * Fires at the bottom of the Plugin updates tab, per Group view.
			 *
			 * @param object $websites               Object containing child sites info.
			 * @param int    $total_plugin_upgrades  Number of available plugin updates.
			 * @param object $userExtension          User extension.
			 * @param array  $all_groups_sites       Array of all groups and sites.
			 * @param array  $all_groups             Array of all groups.
			 * @param array  $allPlugins             Array of all plugins.
			 * @param array  $pluginsInfo            Array of all plugins info.
			 * @param int    $site_offset_for_groups Offset value.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_updates_pergroup_after_plugin_updates', $websites, $total_plugin_upgrades, $userExtension, $all_groups_sites, $all_groups, $allPlugins, $pluginsInfo, $site_offset_for_groups );
		} else {
			/**
			 * Action: mainwp_updates_perplugin_before_plugin_updates
			 *
			 * Fires at the top of the Plugin updates tab, per Plugin view.
			 *
			 * @param object $websites               Object containing child sites info.
			 * @param int    $total_plugin_upgrades  Number of available plugin updates.
			 * @param object $userExtension          User extension.
			 * @param array  $all_groups_sites       Array of all groups and sites.
			 * @param array  $all_groups             Array of all groups.
			 * @param array  $allPlugins             Array of all plugins.
			 * @param array  $pluginsInfo            Array of all plugins info.
			 * @param int    $site_offset_for_groups Offset value.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_updates_perplugin_before_plugin_updates', $websites, $total_plugin_upgrades, $userExtension, $all_groups_sites, $all_groups, $allPlugins, $pluginsInfo, $site_offset_for_groups );
				MainWP_Updates_Per_Item::render_plugins_updates( $websites, $total_plugin_upgrades, $userExtension, $allPlugins, $pluginsInfo, $trustedPlugins );
			/**
			 * Action: mainwp_updates_perplugin_after_plugin_updates
			 *
			 * Fires at the bottom of the Plugin updates tab, per Plugin view.
			 *
			 * @param object $websites               Object containing child sites info.
			 * @param int    $total_plugin_upgrades  Number of available plugin updates.
			 * @param object $userExtension          User extension.
			 * @param array  $all_groups_sites       Array of all groups and sites.
			 * @param array  $all_groups             Array of all groups.
			 * @param array  $allPlugins             Array of all plugins.
			 * @param array  $pluginsInfo            Array of all plugins info.
			 * @param int    $site_offset_for_groups Offset value.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_updates_perplugin_after_plugin_updates', $websites, $total_plugin_upgrades, $userExtension, $all_groups_sites, $all_groups, $allPlugins, $pluginsInfo, $site_offset_for_groups );
		}
		/**
		 * Action: mainwp_updates_after_plugin_updates
		 *
		 * Fires at the bottom of the Plugin updates tab.
		 *
		 * @param object $websites               Object containing child sites info.
		 * @param int    $total_plugin_upgrades  Number of available plugin updates.
		 * @param object $userExtension          User extension.
		 * @param array  $all_groups_sites       Array of all groups and sites.
		 * @param array  $all_groups             Array of all groups.
		 * @param array  $allPlugins             Array of all plugins.
		 * @param array  $pluginsInfo            Array of all plugins info.
		 * @param int    $site_offset_for_groups Offset value.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_updates_after_plugin_updates', $websites, $total_plugin_upgrades, $userExtension, $all_groups_sites, $all_groups, $allPlugins, $pluginsInfo, $site_offset_for_groups );
		?>
		</div>
		<?php
	}

	/**
	 * Renders theme update tab.
	 *
	 * @param object $websites               Object containing child sites info.
	 * @param int    $total_theme_upgrades   Number of available theme updates.
	 * @param object $userExtension          User extension.
	 * @param array  $all_groups_sites       Array of all groups and sites.
	 * @param array  $all_groups             Array of all groups.
	 * @param array  $allThemes              Array of all themes.
	 * @param array  $themesInfo             Array of all themes info.
	 * @param int    $site_offset_for_groups Offset value.
	 * @param string $site_view              Current view.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Updates_Per_Group::render_themes_updates()
	 * @uses \MainWP\Dashboard\MainWP_Updates_Per_Item::render_themes_updates()
	 * @uses \MainWP\Dashboard\MainWP_Updates_Per_Site::render_themes_updates()
	 */
	public static function render_themes_update_tab( $websites, $total_theme_upgrades, $userExtension, $all_groups_sites, $all_groups, $allThemes, $themesInfo, $site_offset_for_groups, $site_view ) {
		$trustedThemes = json_decode( $userExtension->trusted_themes, true );
		if ( ! is_array( $trustedThemes ) ) {
			$trustedThemes = array();
		}
		?>
		<div class="ui active tab" data-tab="themes-updates">
		<?php
		/**
		 * Action: mainwp_updates_before_theme_updates
		 *
		 * Fires at the top of the Theme updates tab.
		 *
		 * @param object $websites               Object containing child sites info.
		 * @param int    $total_theme_upgrades   Number of available theme updates.
		 * @param object $userExtension          User extension.
		 * @param array  $all_groups_sites       Array of all groups and sites.
		 * @param array  $all_groups             Array of all groups.
		 * @param array  $allThemes              Array of all themes.
		 * @param array  $themesInfo             Array of all themes info.
		 * @param int    $site_offset_for_groups Offset value.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_updates_before_theme_updates', $websites, $total_theme_upgrades, $userExtension, $all_groups_sites, $all_groups, $allThemes, $themesInfo, $site_offset_for_groups );
		if ( MAINWP_VIEW_PER_SITE == $site_view ) {
			/**
			 * Action: mainwp_updates_persite_before_theme_updates
			 *
			 * Fires at the top of the Theme updates tab, per Site view.
			 *
			 * @param object $websites               Object containing child sites info.
			 * @param int    $total_theme_upgrades   Number of available theme updates.
			 * @param object $userExtension          User extension.
			 * @param array  $all_groups_sites       Array of all groups and sites.
			 * @param array  $all_groups             Array of all groups.
			 * @param array  $allThemes              Array of all themes.
			 * @param array  $themesInfo             Array of all themes info.
			 * @param int    $site_offset_for_groups Offset value.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_updates_persite_before_theme_updates', $websites, $total_theme_upgrades, $userExtension, $all_groups_sites, $all_groups, $allThemes, $themesInfo, $site_offset_for_groups );
			MainWP_Updates_Per_Site::render_themes_updates( $websites, $total_theme_upgrades, $userExtension, $trustedThemes );
			/**
			 * Action: mainwp_updates_persite_after_theme_updates
			 *
			 * Fires at the bottom of the Theme updates tab, per Site view.
			 *
			 * @param object $websites               Object containing child sites info.
			 * @param int    $total_theme_upgrades   Number of available theme updates.
			 * @param object $userExtension          User extension.
			 * @param array  $all_groups_sites       Array of all groups and sites.
			 * @param array  $all_groups             Array of all groups.
			 * @param array  $allThemes              Array of all themes.
			 * @param array  $themesInfo             Array of all themes info.
			 * @param int    $site_offset_for_groups Offset value.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_updates_persite_after_theme_updates', $websites, $total_theme_upgrades, $userExtension, $all_groups_sites, $all_groups, $allThemes, $themesInfo, $site_offset_for_groups );
		} elseif ( MAINWP_VIEW_PER_GROUP == $site_view ) {
			/**
			 * Action: mainwp_updates_pergroup_before_theme_updates
			 *
			 * Fires at the top of the Theme updates tab, per Group view.
			 *
			 * @param object $websites               Object containing child sites info.
			 * @param int    $total_theme_upgrades   Number of available theme updates.
			 * @param object $userExtension          User extension.
			 * @param array  $all_groups_sites       Array of all groups and sites.
			 * @param array  $all_groups             Array of all groups.
			 * @param array  $allThemes              Array of all themes.
			 * @param array  $themesInfo             Array of all themes info.
			 * @param int    $site_offset_for_groups Offset value.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_updates_pergroup_before_theme_updates', $websites, $total_theme_upgrades, $userExtension, $all_groups_sites, $all_groups, $allThemes, $themesInfo, $site_offset_for_groups );
			MainWP_Updates_Per_Group::render_themes_updates( $websites, $total_theme_upgrades, $userExtension, $all_groups_sites, $all_groups, $site_offset_for_groups, $trustedThemes );
			/**
			 * Action: mainwp_updates_pergroup_after_theme_updates
			 *
			 * Fires at the bottom of the Theme updates tab, per Group view.
			 *
			 * @param object $websites               Object containing child sites info.
			 * @param int    $total_theme_upgrades   Number of available theme updates.
			 * @param object $userExtension          User extension.
			 * @param array  $all_groups_sites       Array of all groups and sites.
			 * @param array  $all_groups             Array of all groups.
			 * @param array  $allThemes              Array of all themes.
			 * @param array  $themesInfo             Array of all themes info.
			 * @param int    $site_offset_for_groups Offset value.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_updates_pergroup_after_theme_updates', $websites, $total_theme_upgrades, $userExtension, $all_groups_sites, $all_groups, $allThemes, $themesInfo, $site_offset_for_groups );
		} else {
			/**
			 * Action: mainwp_updates_pertheme_before_theme_updates
			 *
			 * Fires at the top of the Theme updates tab, per Theme view.
			 *
			 * @param object $websites               Object containing child sites info.
			 * @param int    $total_theme_upgrades   Number of available theme updates.
			 * @param object $userExtension          User extension.
			 * @param array  $all_groups_sites       Array of all groups and sites.
			 * @param array  $all_groups             Array of all groups.
			 * @param array  $allThemes              Array of all themes.
			 * @param array  $themesInfo             Array of all themes info.
			 * @param int    $site_offset_for_groups Offset value.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_updates_pertheme_before_theme_updates', $websites, $total_theme_upgrades, $userExtension, $all_groups_sites, $all_groups, $allThemes, $themesInfo, $site_offset_for_groups );
			MainWP_Updates_Per_Item::render_themes_updates( $websites, $total_theme_upgrades, $userExtension, $allThemes, $themesInfo, $trustedThemes );
			/**
			 * Action: mainwp_updates_pertheme_after_theme_updates
			 *
			 * Fires at the bottom of the Theme updates tab, per Theme view.
			 *
			 * @param object $websites               Object containing child sites info.
			 * @param int    $total_theme_upgrades   Number of available theme updates.
			 * @param object $userExtension          User extension.
			 * @param array  $all_groups_sites       Array of all groups and sites.
			 * @param array  $all_groups             Array of all groups.
			 * @param array  $allThemes              Array of all themes.
			 * @param array  $themesInfo             Array of all themes info.
			 * @param int    $site_offset_for_groups Offset value.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_updates_pertheme_after_theme_updates', $websites, $total_theme_upgrades, $userExtension, $all_groups_sites, $all_groups, $allThemes, $themesInfo, $site_offset_for_groups );
		}
		/**
		 * Action: mainwp_updates_after_theme_updates
		 *
		 * Fires at the bottom of the Theme updates tab.
		 *
		 * @param object $websites               Object containing child sites info.
		 * @param int    $total_theme_upgrades   Number of available theme updates.
		 * @param object $userExtension          User extension.
		 * @param array  $all_groups_sites       Array of all groups and sites.
		 * @param array  $all_groups             Array of all groups.
		 * @param array  $allThemes              Array of all themes.
		 * @param array  $themesInfo             Array of all themes info.
		 * @param int    $site_offset_for_groups Offset value.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_updates_after_theme_updates', $websites, $total_theme_upgrades, $userExtension, $all_groups_sites, $all_groups, $allThemes, $themesInfo, $site_offset_for_groups );
		?>
		</div>
		<?php
	}

	/**
	 * Renders translations update tab.
	 *
	 * @param object $websites                   Object containing child sites info.
	 * @param int    $total_translation_upgrades Number of available translation updates.
	 * @param object $userExtension              User extension.
	 * @param array  $all_groups_sites           Array of all groups and sites.
	 * @param array  $all_groups                 Array of all groups.
	 * @param array  $allTranslations            Array of all translations.
	 * @param array  $translationsInfo           Array of all translations info.
	 * @param bool   $mainwp_show_language_updates Either or not show language updates.
	 * @param int    $site_offset_for_groups     Offset value.
	 * @param string $site_view                  Current Site view.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Updates_Per_Group::render_trans_update()
	 * @uses \MainWP\Dashboard\MainWP_Updates_Per_Item::render_trans_update()
	 * @uses \MainWP\Dashboard\MainWP_Updates_Per_Site::render_trans_update()
	 */
	public static function render_trans_update_tab( $websites, $total_translation_upgrades, $userExtension, $all_groups_sites, $all_groups, $allTranslations, $translationsInfo, $mainwp_show_language_updates, $site_offset_for_groups, $site_view ) {
		if ( 1 == $mainwp_show_language_updates ) {
			?>
		<div class="ui active tab" data-tab="translations-updates">
			<?php
			/**
			 * Action: mainwp_updates_before_translation_updates
			 *
			 * Fires at the top of the Translation updates tab.
			 *
			 * @param object $websites                   Object containing child sites info.
			 * @param int    $total_translation_upgrades Number of available translation updates.
			 * @param object $userExtension              User extension.
			 * @param array  $all_groups_sites           Array of all groups and sites.
			 * @param array  $all_groups                 Array of all groups.
			 * @param array  $allTranslations            Array of all translations.
			 * @param array  $translationsInfo           Array of all translations info.
			 * @param int    $site_offset_for_groups     Offset value.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_updates_before_translation_updates', $websites, $total_translation_upgrades, $userExtension, $all_groups_sites, $all_groups, $allTranslations, $translationsInfo, $mainwp_show_language_updates, $site_offset_for_groups );
			if ( MAINWP_VIEW_PER_SITE == $site_view ) {
				/**
				 * Action: mainwp_updates_persite_before_translation_updates
				 *
				 * Fires at the top of the Translation updates tab, per Site view.
				 *
				 * @param object $websites                   Object containing child sites info.
				 * @param int    $total_translation_upgrades Number of available translation updates.
				 * @param object $userExtension              User extension.
				 * @param array  $all_groups_sites           Array of all groups and sites.
				 * @param array  $all_groups                 Array of all groups.
				 * @param array  $allTranslations            Array of all translations.
				 * @param array  $translationsInfo           Array of all translations info.
				 * @param int    $site_offset_for_groups     Offset value.
				 *
				 * @since 4.1
				 */
				do_action( 'mainwp_updates_persite_before_translation_updates', $websites, $total_translation_upgrades, $userExtension, $all_groups_sites, $all_groups, $allTranslations, $translationsInfo, $mainwp_show_language_updates, $site_offset_for_groups );
				MainWP_Updates_Per_Site::render_trans_update( $websites, $total_translation_upgrades );
				/**
				 * Action: mainwp_updates_persite_after_translation_updates
				 *
				 * Fires at the bottom of the Translation updates tab, per Site view.
				 *
				 * @param object $websites                   Object containing child sites info.
				 * @param int    $total_translation_upgrades Number of available translation updates.
				 * @param object $userExtension              User extension.
				 * @param array  $all_groups_sites           Array of all groups and sites.
				 * @param array  $all_groups                 Array of all groups.
				 * @param array  $allTranslations            Array of all translations.
				 * @param array  $translationsInfo           Array of all translations info.
				 * @param int    $site_offset_for_groups     Offset value.
				 *
				 * @since 4.1
				 */
				do_action( 'mainwp_updates_persite_after_translation_updates', $websites, $total_translation_upgrades, $userExtension, $all_groups_sites, $all_groups, $allTranslations, $translationsInfo, $mainwp_show_language_updates, $site_offset_for_groups );
			} elseif ( MAINWP_VIEW_PER_GROUP == $site_view ) {
				/**
				 * Action: mainwp_updates_pergroup_before_translation_updates
				 *
				 * Fires at the top of the Translation updates tab, per Group view.
				 *
				 * @param object $websites                   Object containing child sites info.
				 * @param int    $total_translation_upgrades Number of available translation updates.
				 * @param object $userExtension              User extension.
				 * @param array  $all_groups_sites           Array of all groups and sites.
				 * @param array  $all_groups                 Array of all groups.
				 * @param array  $allTranslations            Array of all translations.
				 * @param array  $translationsInfo           Array of all translations info.
				 * @param int    $site_offset_for_groups     Offset value.
				 *
				 * @since 4.1
				 */
				do_action( 'mainwp_updates_pergroup_before_translation_updates', $websites, $total_translation_upgrades, $userExtension, $all_groups_sites, $all_groups, $allTranslations, $translationsInfo, $mainwp_show_language_updates, $site_offset_for_groups );
				MainWP_Updates_Per_Group::render_trans_update( $websites, $total_translation_upgrades, $all_groups_sites, $all_groups, $site_offset_for_groups );
				/**
				 * Action: mainwp_updates_pergroup_after_translation_updates
				 *
				 * Fires at the bottom of the Translation updates tab, per Group view.
				 *
				 * @param object $websites                   Object containing child sites info.
				 * @param int    $total_translation_upgrades Number of available translation updates.
				 * @param object $userExtension              User extension.
				 * @param array  $all_groups_sites           Array of all groups and sites.
				 * @param array  $all_groups                 Array of all groups.
				 * @param array  $allTranslations            Array of all translations.
				 * @param array  $translationsInfo           Array of all translations info.
				 * @param int    $site_offset_for_groups     Offset value.
				 *
				 * @since 4.1
				 */
				do_action( 'mainwp_updates_pergroup_after_translation_updates', $websites, $total_translation_upgrades, $userExtension, $all_groups_sites, $all_groups, $allTranslations, $translationsInfo, $mainwp_show_language_updates, $site_offset_for_groups );
			} else {
				/**
				 * Action: mainwp_updates_pertranslation_before_translation_updates
				 *
				 * Fires at the top of the Translation updates tab, per Translation view.
				 *
				 * @param object $websites                   Object containing child sites info.
				 * @param int    $total_translation_upgrades Number of available translation updates.
				 * @param object $userExtension              User extension.
				 * @param array  $all_groups_sites           Array of all groups and sites.
				 * @param array  $all_groups                 Array of all groups.
				 * @param array  $allTranslations            Array of all translations.
				 * @param array  $translationsInfo           Array of all translations info.
				 * @param int    $site_offset_for_groups     Offset value.
				 *
				 * @since 4.1
				 */
				do_action( 'mainwp_updates_pertranslation_before_translation_updates', $websites, $total_translation_upgrades, $userExtension, $all_groups_sites, $all_groups, $allTranslations, $translationsInfo, $mainwp_show_language_updates, $site_offset_for_groups );
				MainWP_Updates_Per_Item::render_trans_update( $websites, $total_translation_upgrades, $userExtension, $allTranslations, $translationsInfo );
				/**
				 * Action: mainwp_updates_pertranslation_after_translation_updates
				 *
				 * Fires at the bottom of the Translation updates tab, per Translation view.
				 *
				 * @param object $websites                   Object containing child sites info.
				 * @param int    $total_translation_upgrades Number of available translation updates.
				 * @param object $userExtension              User extension.
				 * @param array  $all_groups_sites           Array of all groups and sites.
				 * @param array  $all_groups                 Array of all groups.
				 * @param array  $allTranslations            Array of all translations.
				 * @param array  $translationsInfo           Array of all translations info.
				 * @param int    $site_offset_for_groups     Offset value.
				 *
				 * @since 4.1
				 */
				do_action( 'mainwp_updates_pertranslation_after_translation_updates', $websites, $total_translation_upgrades, $userExtension, $all_groups_sites, $all_groups, $allTranslations, $translationsInfo, $mainwp_show_language_updates, $site_offset_for_groups );
			}
			/**
			 * Action: mainwp_updates_after_translation_updates
			 *
			 * Fires at the bottom of the Translation updates tab.
			 *
			 * @param object $websites                   Object containing child sites info.
			 * @param int    $total_translation_upgrades Number of available translation updates.
			 * @param object $userExtension              User extension.
			 * @param array  $all_groups_sites           Array of all groups and sites.
			 * @param array  $all_groups                 Array of all groups.
			 * @param array  $allTranslations            Array of all translations.
			 * @param array  $translationsInfo           Array of all translations info.
			 * @param int    $site_offset_for_groups     Offset value.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_updates_after_translation_updates', $websites, $total_translation_upgrades, $userExtension, $all_groups_sites, $all_groups, $allTranslations, $translationsInfo, $mainwp_show_language_updates, $site_offset_for_groups );
			?>
		</div>
			<?php
		}
	}

	/**
	 * Renders abandoned plugins tab.
	 *
	 * @param object $websites                Object containing child sites info.
	 * @param array  $all_groups_sites        Array of all groups and sites.
	 * @param array  $all_groups              Array of all groups.
	 * @param array  $allPluginsOutdate       Array of all abandoned plugins.
	 * @param array  $decodedDismissedPlugins Array of dismissed abandoned plugins.
	 * @param int    $site_offset_for_groups  Offset value.
	 * @param string $site_view               Current site view.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Updates_Per_Group::render_abandoned_plugins()
	 * @uses \MainWP\Dashboard\MainWP_Updates_Per_Item::render_abandoned_plugins()
	 * @uses \MainWP\Dashboard\MainWP_Updates_Per_Site::render_abandoned_plugins()
	 */
	public static function render_abandoned_plugins_tab( $websites, $all_groups_sites, $all_groups, $allPluginsOutdate, $decodedDismissedPlugins, $site_offset_for_groups, $site_view ) {
		?>
		<div class="ui active tab" data-tab="abandoned-plugins">
		<?php
		/**
		 * Action: mainwp_updates_before_abandoned_plugins
		 *
		 * Fires at the top of the Abandoned plugins tab.
		 *
		 * @param object $websites                Object containing child sites info.
		 * @param array  $all_groups_sites        Array of all groups and sites.
		 * @param array  $all_groups              Array of all groups.
		 * @param array  $allPluginsOutdate       Array of all abandoned plugins.
		 * @param array  $decodedDismissedPlugins Array of dismissed abandoned plugins.
		 * @param int    $site_offset_for_groups  Offset value.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_updates_before_abandoned_plugins', $websites, $all_groups_sites, $all_groups, $allPluginsOutdate, $decodedDismissedPlugins, $site_offset_for_groups );
		if ( MAINWP_VIEW_PER_SITE == $site_view ) {
			/**
			 * Action: mainwp_updates_persite_before_abandoned_plugins
			 *
			 * Fires at the top of the Abandoned plugins tab, per Site view.
			 *
			 * @param object $websites                Object containing child sites info.
			 * @param array  $all_groups_sites        Array of all groups and sites.
			 * @param array  $all_groups              Array of all groups.
			 * @param array  $allPluginsOutdate       Array of all abandoned plugins.
			 * @param array  $decodedDismissedPlugins Array of dismissed abandoned plugins.
			 * @param int    $site_offset_for_groups  Offset value.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_updates_persite_before_abandoned_plugins', $websites, $all_groups_sites, $all_groups, $allPluginsOutdate, $decodedDismissedPlugins, $site_offset_for_groups );
			MainWP_Updates_Per_Site::render_abandoned_plugins( $websites, $decodedDismissedPlugins );
			/**
			 * Action: mainwp_updates_persite_after_abandoned_plugins
			 *
			 * Fires at the bottom of the Abandoned plugins tab, per Site view.
			 *
			 * @param object $websites                Object containing child sites info.
			 * @param array  $all_groups_sites        Array of all groups and sites.
			 * @param array  $all_groups              Array of all groups.
			 * @param array  $allPluginsOutdate       Array of all abandoned plugins.
			 * @param array  $decodedDismissedPlugins Array of dismissed abandoned plugins.
			 * @param int    $site_offset_for_groups  Offset value.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_updates_persite_after_abandoned_plugins', $websites, $all_groups_sites, $all_groups, $allPluginsOutdate, $decodedDismissedPlugins, $site_offset_for_groups );
		} elseif ( MAINWP_VIEW_PER_GROUP == $site_view ) {
			/**
			 * Action: mainwp_updates_pergroup_before_abandoned_plugins
			 *
			 * Fires at the top of the Abandoned plugins tab, per Group view.
			 *
			 * @param object $websites                Object containing child sites info.
			 * @param array  $all_groups_sites        Array of all groups and sites.
			 * @param array  $all_groups              Array of all groups.
			 * @param array  $allPluginsOutdate       Array of all abandoned plugins.
			 * @param array  $decodedDismissedPlugins Array of dismissed abandoned plugins.
			 * @param int    $site_offset_for_groups  Offset value.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_updates_pergroup_before_abandoned_plugins', $websites, $all_groups_sites, $all_groups, $allPluginsOutdate, $decodedDismissedPlugins, $site_offset_for_groups );
			MainWP_Updates_Per_Group::render_abandoned_plugins( $websites, $all_groups_sites, $all_groups, $site_offset_for_groups, $decodedDismissedPlugins );
			/**
			 * Action: mainwp_updates_pergroup_after_abandoned_plugins
			 *
			 * Fires at the bottom of the Abandoned plugins tab, per Group view.
			 *
			 * @param object $websites                Object containing child sites info.
			 * @param array  $all_groups_sites        Array of all groups and sites.
			 * @param array  $all_groups              Array of all groups.
			 * @param array  $allPluginsOutdate       Array of all abandoned plugins.
			 * @param array  $decodedDismissedPlugins Array of dismissed abandoned plugins.
			 * @param int    $site_offset_for_groups  Offset value.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_updates_pergroup_after_abandoned_plugins', $websites, $all_groups_sites, $all_groups, $allPluginsOutdate, $decodedDismissedPlugins, $site_offset_for_groups );
		} else {
			/**
			 * Action: mainwp_updates_perplugin_before_abandoned_plugins
			 *
			 * Fires at the top of the Abandoned plugins tab, per Plugin view.
			 *
			 * @param object $websites                Object containing child sites info.
			 * @param array  $all_groups_sites        Array of all groups and sites.
			 * @param array  $all_groups              Array of all groups.
			 * @param array  $allPluginsOutdate       Array of all abandoned plugins.
			 * @param array  $decodedDismissedPlugins Array of dismissed abandoned plugins.
			 * @param int    $site_offset_for_groups  Offset value.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_updates_perplugin_before_abandoned_plugins', $websites, $all_groups_sites, $all_groups, $allPluginsOutdate, $decodedDismissedPlugins, $site_offset_for_groups );
			MainWP_Updates_Per_Item::render_abandoned_plugins( $websites, $allPluginsOutdate, $decodedDismissedPlugins );
			/**
			 * Action: mainwp_updates_perplugin_after_abandoned_plugins
			 *
			 * Fires at the bottom of the Abandoned plugins tab, per Plugin view.
			 *
			 * @param object $websites                Object containing child sites info.
			 * @param array  $all_groups_sites        Array of all groups and sites.
			 * @param array  $all_groups              Array of all groups.
			 * @param array  $allPluginsOutdate       Array of all abandoned plugins.
			 * @param array  $decodedDismissedPlugins Array of dismissed abandoned plugins.
			 * @param int    $site_offset_for_groups  Offset value.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_updates_perplugin_after_abandoned_plugins', $websites, $all_groups_sites, $all_groups, $allPluginsOutdate, $decodedDismissedPlugins, $site_offset_for_groups );
		};
		/**
		 * Action: mainwp_updates_after_abandoned_plugins
		 *
		 * Fires at the bottom of the Abandoned plugins tab.
		 *
		 * @param object $websites                Object containing child sites info.
		 * @param array  $all_groups_sites        Array of all groups and sites.
		 * @param array  $all_groups              Array of all groups.
		 * @param array  $allPluginsOutdate       Array of all abandoned plugins.
		 * @param array  $decodedDismissedPlugins Array of dismissed abandoned plugins.
		 * @param int    $site_offset_for_groups  Offset value.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_updates_after_abandoned_plugins', $websites, $all_groups_sites, $all_groups, $allPluginsOutdate, $decodedDismissedPlugins, $site_offset_for_groups );
		?>
		</div>
		<?php
	}

	/**
	 * Renders abandoned themes tab.
	 *
	 * @param object $websites               Object containing child sites info.
	 * @param array  $all_groups_sites       Array of all groups and sites.
	 * @param array  $all_groups             Array of all groups.
	 * @param array  $allThemesOutdate       Array of all abandoned plugins.
	 * @param array  $decodedDismissedThemes Array of dismissed abandoned plugins.
	 * @param int    $site_offset_for_groups Offset value.
	 * @param string $site_view              Current site view.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Updates_Per_Group::render_abandoned_themes()
	 * @uses \MainWP\Dashboard\MainWP_Updates_Per_Item::render_abandoned_themes()
	 * @uses \MainWP\Dashboard\MainWP_Updates_Per_Site::render_abandoned_themes()
	 */
	public static function render_abandoned_themes_tab( $websites, $all_groups_sites, $all_groups, $allThemesOutdate, $decodedDismissedThemes, $site_offset_for_groups, $site_view ) {
		?>
		<div class="ui active tab" data-tab="abandoned-themes">
		<?php
		/**
		 * Action: mainwp_updates_before_abandoned_themes
		 *
		 * Fires at the top of the Abandoned themes tab.
		 *
		 * @param object $websites               Object containing child sites info.
		 * @param array  $all_groups_sites       Array of all groups and sites.
		 * @param array  $all_groups             Array of all groups.
		 * @param array  $allThemesOutdate       Array of all abandoned plugins.
		 * @param array  $decodedDismissedThemes Array of dismissed abandoned plugins.
		 * @param int    $site_offset_for_groups Offset value.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_updates_before_abandoned_themes', $websites, $all_groups_sites, $all_groups, $allThemesOutdate, $decodedDismissedThemes, $site_offset_for_groups );
		if ( MAINWP_VIEW_PER_SITE == $site_view ) {
			/**
			 * Action: mainwp_updates_persite_before_abandoned_themes
			 *
			 * Fires at the top of the Abandoned themes tab, per Site view.
			 *
			 * @param object $websites               Object containing child sites info.
			 * @param array  $all_groups_sites       Array of all groups and sites.
			 * @param array  $all_groups             Array of all groups.
			 * @param array  $allThemesOutdate       Array of all abandoned plugins.
			 * @param array  $decodedDismissedThemes Array of dismissed abandoned plugins.
			 * @param int    $site_offset_for_groups Offset value.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_updates_persite_before_abandoned_themes', $websites, $all_groups_sites, $all_groups, $allThemesOutdate, $decodedDismissedThemes, $site_offset_for_groups );
			MainWP_Updates_Per_Site::render_abandoned_themes( $websites, $decodedDismissedThemes );
			/**
			 * Action: mainwp_updates_persite_after_abandoned_themes
			 *
			 * Fires at the bottom of the Abandoned themes tab, per Site view.
			 *
			 * @param object $websites               Object containing child sites info.
			 * @param array  $all_groups_sites       Array of all groups and sites.
			 * @param array  $all_groups             Array of all groups.
			 * @param array  $allThemesOutdate       Array of all abandoned plugins.
			 * @param array  $decodedDismissedThemes Array of dismissed abandoned plugins.
			 * @param int    $site_offset_for_groups Offset value.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_updates_persite_after_abandoned_themes', $websites, $all_groups_sites, $all_groups, $allThemesOutdate, $decodedDismissedThemes, $site_offset_for_groups );
		} elseif ( MAINWP_VIEW_PER_GROUP == $site_view ) {
			/**
			 * Action: mainwp_updates_pergroup_before_abandoned_themes
			 *
			 * Fires at the top of the Abandoned themes tab, per Group view.
			 *
			 * @param object $websites               Object containing child sites info.
			 * @param array  $all_groups_sites       Array of all groups and sites.
			 * @param array  $all_groups             Array of all groups.
			 * @param array  $allThemesOutdate       Array of all abandoned plugins.
			 * @param array  $decodedDismissedThemes Array of dismissed abandoned plugins.
			 * @param int    $site_offset_for_groups Offset value.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_updates_pergroup_before_abandoned_themes', $websites, $all_groups_sites, $all_groups, $allThemesOutdate, $decodedDismissedThemes, $site_offset_for_groups );
			MainWP_Updates_Per_Group::render_abandoned_themes( $websites, $all_groups_sites, $all_groups, $site_offset_for_groups, $decodedDismissedThemes );
			/**
			 * Action: mainwp_updates_pergroup_after_abandoned_themes
			 *
			 * Fires at the bottom of the Abandoned themes tab, per Group view.
			 *
			 * @param object $websites               Object containing child sites info.
			 * @param array  $all_groups_sites       Array of all groups and sites.
			 * @param array  $all_groups             Array of all groups.
			 * @param array  $allThemesOutdate       Array of all abandoned plugins.
			 * @param array  $decodedDismissedThemes Array of dismissed abandoned plugins.
			 * @param int    $site_offset_for_groups Offset value.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_updates_pergroup_after_abandoned_themes', $websites, $all_groups_sites, $all_groups, $allThemesOutdate, $decodedDismissedThemes, $site_offset_for_groups );
		} else {
			/**
			 * Action: mainwp_updates_pertheme_before_abandoned_themes
			 *
			 * Fires at the top of the Abandoned themes tab, per Theme view.
			 *
			 * @param object $websites               Object containing child sites info.
			 * @param array  $all_groups_sites       Array of all groups and sites.
			 * @param array  $all_groups             Array of all groups.
			 * @param array  $allThemesOutdate       Array of all abandoned plugins.
			 * @param array  $decodedDismissedThemes Array of dismissed abandoned plugins.
			 * @param int    $site_offset_for_groups Offset value.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_updates_pertheme_before_abandoned_themes', $websites, $all_groups_sites, $all_groups, $allThemesOutdate, $decodedDismissedThemes, $site_offset_for_groups );
			MainWP_Updates_Per_Item::render_abandoned_themes( $websites, $allThemesOutdate, $decodedDismissedThemes );
			/**
			 * Action: mainwp_updates_pertheme_after_abandoned_themes
			 *
			 * Fires at the bottom of the Abandoned themes tab, per Theme view.
			 *
			 * @param object $websites               Object containing child sites info.
			 * @param array  $all_groups_sites       Array of all groups and sites.
			 * @param array  $all_groups             Array of all groups.
			 * @param array  $allThemesOutdate       Array of all abandoned plugins.
			 * @param array  $decodedDismissedThemes Array of dismissed abandoned plugins.
			 * @param int    $site_offset_for_groups Offset value.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_updates_pertheme_after_abandoned_themes', $websites, $all_groups_sites, $all_groups, $allThemesOutdate, $decodedDismissedThemes, $site_offset_for_groups );
		};
		/**
		 * Action: mainwp_updates_after_abandoned_themes
		 *
		 * Fires at the bottom of the Abandoned themes tab.
		 *
		 * @param object $websites               Object containing child sites info.
		 * @param array  $all_groups_sites       Array of all groups and sites.
		 * @param array  $all_groups             Array of all groups.
		 * @param array  $allThemesOutdate       Array of all abandoned plugins.
		 * @param array  $decodedDismissedThemes Array of dismissed abandoned plugins.
		 * @param int    $site_offset_for_groups Offset value.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_updates_after_abandoned_themes', $websites, $all_groups_sites, $all_groups, $allThemesOutdate, $decodedDismissedThemes, $site_offset_for_groups );
		?>
		</div>
		<?php
	}

	/**
	 * Renders JavaScript for update page.
	 *
	 * @param string $site_view current site view.
	 */
	public static function render_js_updates( $site_view ) {
		$table_features = array(
			'searching' => 'false',
			'paging'    => 'false',
			'stateSave' => 'true',
			'info'      => 'false',
			'exclusive' => 'false',
			'duration'  => '200',
		);
		/**
		 * Filter: mainwp_updates_table_features
		 *
		 * Filters the Updates table features.
		 *
		 * @since 4.1
		 */
		$table_features = apply_filters( 'mainwp_updates_table_features', $table_features );
		?>
		<script type="text/javascript">
			jQuery( document ).ready( function () {
				jQuery( '#mainwp-manage-updates .ui.accordion' ).accordion( {
					"exclusive": <?php echo esc_html( $table_features['exclusive'] ); ?>,
					"duration": <?php echo esc_html( $table_features['duration'] ); ?>,
					onOpen: function(){
						//mainwp_datatable_init_and_fix_recalc('table.mainwp-manage-updates-item-table');
					}
				} );
			} );

			mainwp_datatable_init_and_fix_recalc = function(selector){ 
				jQuery(selector).each( function(e) {					
					if(jQuery(this).is(":visible")){
						console.log('visible ' + jQuery(this).attr('id'));
						jQuery(this).css( 'display', 'table' );
						jQuery(this).DataTable().destroy();						
						var tb = jQuery(this).DataTable({
							"paging":false,
							"info": false,
							"searching": false,
							"ordering" : false,
							"responsive": true,
						});					
						tb.columns.adjust();
						tb.responsive.recalc();
					}
				});
			}

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
	 * Gets sites for updates
	 *
	 * @return object Object containing websites info.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::query()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_sql_website_by_id()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_for_current_user()
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_current_wpid()
	 */
	public static function get_sites() {

		/**
		 * Current user global.
		 *
		 * @global string
		 */
		global $current_user;

		$current_wpid = MainWP_System_Utility::get_current_wpid();
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
			$params = array(
				'connected' => 'yes',
			);
			$sql    = MainWP_DB::instance()->get_sql_websites_for_current_user( false, null, 'wp.url', false, false, null, false, array( 'premium_upgrades', 'plugins_outdate_dismissed', 'themes_outdate_dismissed', 'plugins_outdate_info', 'themes_outdate_info', 'favi_icon' ), $is_staging, $params );
		}
		return MainWP_DB::instance()->query( $sql );
	}

	/**
	 * Renders header tabs
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
	 */
	public static function render_header_tabs( $show_language_updates, $current_tab, $total_wp_upgrades, $total_plugin_upgrades, $total_theme_upgrades, $total_translation_upgrades, $total_plugins_outdate, $total_themes_outdate, $site_view ) { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		/**
		 * Action: mainwp_updates_before_nav_tabs
		 *
		 * Fires before the navigation tabs on the Updates page.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_updates_before_nav_tabs' );

		$header_tabs = array(
			'plugins-updates'   => array(
				'slug'           => 'plugins-updates',
				'title'          => esc_html__( 'Plugins Updates', 'mainwp' ),
				'total_upgrades' => $total_plugin_upgrades,
			),
			'themes-updates'    => array(
				'slug'           => 'themes-updates',
				'title'          => esc_html__( 'Themes Updates', 'mainwp' ),
				'total_upgrades' => $total_theme_upgrades,
			),
			'wordpress-updates' => array(
				'slug'           => 'wordpress-updates',
				'title'          => esc_html__( 'WordPress Updates', 'mainwp' ),
				'total_upgrades' => $total_wp_upgrades,
			),
		);

		if ( $show_language_updates ) {
			$header_tabs['translations-updates'] = array(
				'slug'           => 'translations-updates',
				'title'          => esc_html__( 'Translations Updates', 'mainwp' ),
				'total_upgrades' => $total_translation_upgrades,
			);
		}

		$header_tabs['abandoned-plugins'] = array(
			'slug'           => 'abandoned-plugins',
			'title'          => esc_html__( 'Abandoned Plugins', 'mainwp' ),
			'total_upgrades' => $total_plugins_outdate,
		);

		$header_tabs['abandoned-themes'] = array(
			'slug'           => 'abandoned-themes',
			'title'          => esc_html__( 'Abandoned Themes', 'mainwp' ),
			'total_upgrades' => $total_themes_outdate,
		);

		$header_tabs = apply_filters( 'mainwp_page_hearder_tabs_updates', $header_tabs );

		?>
		<div id="mainwp-page-navigation-wrapper">
			<div class="ui secondary green pointing menu stackable mainwp-page-navigation">
				<?php
				foreach ( $header_tabs as $slug => $tab ) {
					if ( empty( $tab['title'] ) ) {
						continue;
					}
					?>
					<a class="<?php echo( $slug === $current_tab ? 'active' : '' ); ?> item" data-tab="<?php echo esc_html( $slug ); ?>" href="admin.php?page=UpdatesManage&tab=<?php echo esc_html( $slug ); ?>"><?php echo esc_html( $tab['title'] ); ?><div class="ui small <?php echo empty( $tab['total_upgrades'] ) ? 'green' : 'red'; ?> label" timestamp="<?php echo esc_html( time() ); ?>"><?php echo intval( $tab['total_upgrades'] ); ?></div></a>
					<?php
				}
				?>
			</div>
		</div>
		<?php

		$hide_show_updates_per = apply_filters( 'mainwp_updates_hide_show_updates_per', false, $current_tab );

		/**
		 * Action: mainwp_updates_after_nav_tabs
		 *
		 * Fires after the navigation tabs on the Updates page.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_updates_after_nav_tabs' );

		/**
		 * Action: mainwp_updates_before_actions_bar
		 *
		 * Fires before the actions bar on the Updates page.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_updates_before_actions_bar' );

		if ( ! $hide_show_updates_per ) {
			$is_demo = MainWP_Demo_Handle::is_demo_mode();
			?>
		<div class="mainwp-sub-header">
			<div class="ui grid">
				<div class="equal width row">
				<div class="middle aligned column">
						<form method="post" action="" class="ui mini form">
							<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
							<div class="inline field">
								<select class="ui dropdown" onchange="mainwp_siteview_onchange(this)" id="mainwp_select_options_siteview" name="select_mainwp_options_siteview">
									<option value="1" class="item" <?php echo MAINWP_VIEW_PER_SITE == $site_view ? 'selected' : ''; ?>><?php esc_html_e( 'Show updates per Site', 'mainwp' ); ?></option>
									<option value="0" class="item" <?php echo MAINWP_VIEW_PER_PLUGIN_THEME == $site_view ? 'selected' : ''; ?>><?php esc_html_e( 'Show updates per Item', 'mainwp' ); ?></option>
									<option value="2" class="item" <?php echo MAINWP_VIEW_PER_GROUP == $site_view ? 'selected' : ''; ?>><?php esc_html_e( 'Show updates per Tag', 'mainwp' ); ?></option>
								</select>
							</div>
						</form>
					</div>
					<div class="middle aligned right aligned column">
						<?php
						/**
						 * Filter: mainwp_widgetupdates_actions_top
						 *
						 * Filters the udpates actions top content.
						 *
						 * @since Unknown
						 */
						echo apply_filters( 'mainwp_widgetupdates_actions_top', '' ); // phpcs:ignore WordPress.Security.EscapeOutput
						if ( 'abandoned-plugins' === $current_tab ) {
							if ( $is_demo ) {
								MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<a href="javascript:void(0)" class="ui green mini basic button disabled" disabled="disabled">' . esc_html__( 'Check for Abandoned Plugins', 'mainwp' ) . '</a>' );
							} else {
							?>
							<a href="#" onClick="updatesoverview_bulk_check_abandoned('plugin'); return false;" class="ui green mini basic button" data-tooltip="<?php esc_html_e( 'Check for Abandoned Plugins.', 'mainwp' ); ?>" data-inverted="" data-position="top center"><?php esc_html_e( 'Check for Abandoned Plugins', 'mainwp' ); ?></a>
							<?php
							}
						} elseif ( 'abandoned-themes' === $current_tab ) {
							if ( $is_demo ) {
								MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<a href="javascript:void(0)" class="ui green mini basic button disabled" disabled="disabled">' . esc_html__( 'Check for Abandoned Themes', 'mainwp' ) . '</a>' );
							} else {
							?>
							<a href="#" onClick="updatesoverview_bulk_check_abandoned('theme'); return false;" class="ui green mini basic button" data-tooltip="<?php esc_html_e( 'Check for Abandoned Themes.', 'mainwp' ); ?>" data-inverted="" data-position="top center"><?php esc_html_e( 'Check for Abandoned Themes', 'mainwp' ); ?></a>
							<?php
							}
						}
						?>
					</div>
			</div>
			</div>
		</div>
			<?php
		}

		/**
		 * Action: mainwp_updates_after_actions_bar
		 *
		 * Fires after the actions bar on the Updates page.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_updates_after_actions_bar' );
	}

	/**
	 * Renders the HTTP Check html content.
	 *
	 * @param object $websites Child Sites.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
	 */
	public static function render_http_checks( $websites ) {

		$enable_legacy_backup = get_option( 'mainwp_enableLegacyBackupFeature' );
		$mainwp_primaryBackup = get_option( 'mainwp_primaryBackup' );

		/**
		 * Custom backup pages
		 *
		 * Filters backup options to set correct page for restore options.
		 *
		 * @since 4.0
		 */
		$customPage = apply_filters_deprecated( 'mainwp-getcustompage-backups', array( false ), '4.0.7.2', 'mainwp_getcustompage_backups' ); // @deprecated Use 'mainwp_getcustompage_backups' instead.
		$customPage = apply_filters( 'mainwp_getcustompage_backups', $customPage );

		$restorePageSlug = '';
		if ( empty( $enable_legacy_backup ) && ! empty( $mainwp_primaryBackup ) && is_array( $customPage ) && isset( $customPage['managesites_slug'] ) ) {
			$restorePageSlug = 'admin.php?page=ManageSites' . $customPage['managesites_slug'];
		} elseif ( $enable_legacy_backup ) {
			$restorePageSlug = 'admin.php?page=managesites';
		}
		?>
		<div class="" id="mainwp-http-response-issues">
			<?php
			/**
			 * Action: mainwp_updates_before_http_response_table
			 *
			 * Fires before the HTTP responses table on the Updates pages
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_updates_before_http_response_table' );
			?>
			<table class="ui single line inverted unstackable table" id="mainwp-http-response-issues-table">
				<thead>
					<tr>
						<th class="no-sort" colspan="3"><?php esc_html_e( 'HTTP Response Check Results', 'mainwp' ); ?></th>
					</tr>
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
							$restoreSlug = $restorePageSlug . '&backupid=' . intval( $website->id );
						} elseif ( self::activated_primary_backup_plugin( $mainwp_primaryBackup, $website ) ) {
							$restoreSlug = $restorePageSlug . '&id=' . intval( $website->id );
						}
					}
					?>
					<tr id="child-site-<?php echo intval( $website->id ); ?>" class="child-site mainwp-child-site-<?php echo intval( $website->id ); ?>" siteid="<?php echo intval( $website->id ); ?>" site-url="<?php echo esc_url( $website->url ); ?>">
						<td>
							<?php self::render_site_link_dashboard( $website ); ?>
						</td>
						<td id="wp_http_response_code_<?php echo esc_attr( $website->id ); ?>">
							<label class="ui red label http-code"><?php echo 'HTTP ' . esc_html( $website->http_response_code ); ?></label>
						</td>
						<td class="right aligned">
							<a href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo esc_attr( $website->id ); ?>&_opennonce=<?php echo esc_html( wp_create_nonce( 'mainwp-admin-nonce' ) ); ?>" class="ui mini button" target="_blank"><?php esc_html_e( 'WP Admin', 'mainwp' ); ?></a>
							<a href="javascript:void(0)" onclick="return updatesoverview_recheck_http( this, <?php echo intval( $website->id ); ?> )" class="ui basic mini green button"><?php esc_html_e( 'Recheck', 'mainwp' ); ?></a>
							<?php if ( ! empty( $website->sync_errors ) ) { ?>
							<a href="#" class="mainwp_site_reconnect ui green mini button"><?php esc_html_e( 'Reconnect', 'mainwp' ); ?></a>
							<?php } ?>
							<a href="javascript:void(0)" onClick="return updatesoverview_ignore_http_response( this, <?php echo intval( $website->id ); ?> )" class="ui basic mini button"><?php esc_html_e( 'Ignore', 'mainwp' ); ?></a>
					<?php if ( ! empty( $restoreSlug ) ) { ?>
							<a href="<?php echo esc_url( $restoreSlug ); ?>" class="ui green mini basic button"><?php esc_html_e( 'Restore', 'mainwp' ); ?></a>
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
			<?php
			/**
			 * Action: mainwp_updates_after_http_response_table
			 *
			 * Fires after the HTTP responses table on the Updates pages.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_updates_after_http_response_table' );

			$table_features = array(
				'searching'  => 'false',
				'paging'     => 'false',
				'stateSave'  => 'true',
				'info'       => 'false',
				'responsive' => 'true',
			);

			/**
			 * Filter: mainwp_updates_http_responses_datatable_features
			 *
			 * Filters the DataTable options for the HTTP Responses table on the Updates page.
			 *
			 * @since 4.1
			 */
			$table_features = apply_filters( 'mainwp_updates_http_responses_datatable_features', $table_features );
			?>
			<script>
			var responsive = <?php echo esc_html( $table_features['responsive'] ); ?>;
			if( jQuery( window ).width() > 1140 ) {
				responsive = false;
			}
			jQuery( document ).ready( function() {
				jQuery( '#mainwp-http-response-issues-table' ).DataTable( {
						"responsive": responsive,
						"searching": <?php echo esc_html( $table_features['searching'] ); ?>,
						"paging" : <?php echo esc_html( $table_features['paging'] ); ?>,
						"stateSave": <?php echo esc_html( $table_features['stateSave'] ); ?>,
						"info" : <?php echo esc_html( $table_features['info'] ); ?>,
						"columnDefs" : [ { "orderable": false, "targets": "no-sort" } ],
						"language" : { "emptyTable": "No HTTP issues detected." }
				} );
			} );
			</script>
		</div>
		<div class="ui hidden clearing divider"></div>
		<?php
	}

	/**
	 * Cheks which primary backup plugin is being used.
	 *
	 * @param mixed  $what Which backup plugin is being use.
	 * @param object $website Website array of information.
	 *
	 * @return boolean True|False.
	 */
	public static function activated_primary_backup_plugin( $what, $website ) {
		$plugins = json_decode( $website->plugins, 1 );
		if ( ! is_array( $plugins ) || 0 === count( $plugins ) ) {
			return false;
		}

		$checks = array(
			'backupbuddy' => 'backupbuddy/backupbuddy.php',
			'backupwp'    => array( 'backwpup/backwpup.php', 'backwpup-pro/backwpup.php' ),
			'updraftplus' => 'updraftplus/updraftplus.php',

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
	 * Sets the HTML selector to continue updates.
	 *
	 * @param string $current_update current update string.
	 * @param bool   $slug Whether to update slug.
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
	 * Gets the HTML selector to continue updates.
	 *
	 * @return void.
	 */
	public static function get_continue_update_selector() {
		echo esc_attr( self::$continue_selector );
	}

	/**
	 * Displays the updates modal window during updates.
	 */
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
	 * Displays the plugin details modal window.
	 */
	public static function render_plugin_details_modal() {
		?>
		<div class="ui modal" id="mainwp-plugin-details-modal">
				<div class="header"><?php echo esc_html__( 'Plugin Details', 'mainwp' ); ?></div>
				<div class="content">
					<div class="ui embed"></div>
				</div>
				<div class="actions">
					<div class="ui cancel button"><?php echo esc_html__( 'Close', 'mainwp' ); ?></div>
				</div>
			</div>
		<?php
	}

	/**
	 * Method render_screen_options_modal()
	 *
	 * Renders Page Settings Modal.
	 */
	public static function render_screen_options_modal() { // phpcs:ignore -- complex method.

		$snAutomaticDailyUpdate       = get_option( 'mainwp_automaticDailyUpdate' );
		$snPluginAutomaticDailyUpdate = get_option( 'mainwp_pluginAutomaticDailyUpdate' );
		$snThemeAutomaticDailyUpdate  = get_option( 'mainwp_themeAutomaticDailyUpdate' );
		$mainwp_show_language_updates = get_option( 'mainwp_show_language_updates', 1 );
		$disableUpdateConfirmations   = get_option( 'mainwp_disable_update_confirmations', 0 );
		$delay_autoupdate             = get_option( 'mainwp_delay_autoupdate', 1 );
		?>
		<div class="ui modal" id="mainwp-manage-updates-screen-options-modal">
			<div class="header"><?php esc_html_e( 'Page Settings', 'mainwp' ); ?></div>
			<div class="scrolling content ui form">
				<form method="POST" action="" id="manage-updates-screen-options-form" name="manage-updates-screen-options-form">
					<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
					<input type="hidden" name="wp_nonce" value="<?php echo esc_attr( wp_create_nonce( 'UpdatesScrOptions' ) ); ?>" />
					<div class="ui grid field">
						<label class="six wide column middle aligned"><?php esc_html_e( 'Plugin advanced automatic updates', 'mainwp' ); ?></label>
						<div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Enable or disable automatic plugins updates.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
							<select name="mainwp_pluginAutomaticDailyUpdate" id="mainwp_pluginAutomaticDailyUpdate" class="ui dropdown">
								<option value="1" <?php echo ( 1 == $snPluginAutomaticDailyUpdate ? 'selected' : '' ); ?>><?php esc_html_e( 'Install Trusted Updates', 'mainwp' ); ?></option>
								<option value="0" <?php echo ( ( false !== $snPluginAutomaticDailyUpdate && 0 == $snPluginAutomaticDailyUpdate ) || 2 == $snPluginAutomaticDailyUpdate ? 'selected' : '' ); ?>><?php esc_html_e( 'Disabled', 'mainwp' ); ?></option>
							</select>
						</div>
					</div>
					<div class="ui grid field">
						<label class="six wide column middle aligned"><?php esc_html_e( 'Theme advanced automatic updates', 'mainwp' ); ?></label>
						<div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Enable or disable automatic themes updates.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
							<select name="mainwp_themeAutomaticDailyUpdate" id="mainwp_themeAutomaticDailyUpdate" class="ui dropdown">
								<option value="1" <?php echo ( 1 == $snThemeAutomaticDailyUpdate ? 'selected' : '' ); ?>><?php esc_html_e( 'Install Trusted Updates', 'mainwp' ); ?></option>
								<option value="0" <?php echo ( ( false !== $snThemeAutomaticDailyUpdate && 0 == $snThemeAutomaticDailyUpdate ) || 2 == $snThemeAutomaticDailyUpdate ? 'selected' : '' ); ?>><?php esc_html_e( 'Disabled', 'mainwp' ); ?></option>
							</select>
						</div>
					</div>
					<div class="ui grid field">
						<label class="six wide column middle aligned"><?php esc_html_e( 'WP Core advanced automatic updates', 'mainwp' ); ?></label>
						<div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Enable or disable automatic WordPress core updates.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
							<select name="mainwp_automaticDailyUpdate" id="mainwp_automaticDailyUpdate" class="ui dropdown">
								<option value="1" <?php echo ( 1 == $snAutomaticDailyUpdate ? 'selected' : '' ); ?>><?php esc_html_e( 'Install Trusted Updates', 'mainwp' ); ?></option>
								<option value="0" <?php echo ( ( false !== $snAutomaticDailyUpdate && 0 == $snAutomaticDailyUpdate ) || 2 == $snAutomaticDailyUpdate ? 'selected' : '' ); ?>><?php esc_html_e( 'Disabled', 'mainwp' ); ?></option>
							</select>
						</div>
					</div>
					<div class="ui grid field">
						<label class="six wide column middle aligned"><?php esc_html_e( 'Advanced automatic updates delay', 'mainwp' ); ?></label>
						<div class="ten wide column ui input" data-tooltip="<?php esc_attr_e( 'Set the number of days to delay automatic updates.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
							<select name="mainwp_delay_autoupdate" id="mainwp_delay_autoupdate" class="ui dropdown">
								<option value="0" <?php echo ( 0 == $delay_autoupdate ? 'selected' : '' ); ?>><?php esc_html_e( 'Delay off', 'mainwp' ); ?></option>
								<option value="1" <?php echo ( 1 == $delay_autoupdate ? 'selected' : '' ); ?>><?php esc_html_e( '1 day', 'mainwp' ); ?></option>
								<option value="2" <?php echo ( 2 == $delay_autoupdate ? 'selected' : '' ); ?>><?php esc_html_e( '2 days', 'mainwp' ); ?></option>
								<option value="3" <?php echo ( 3 == $delay_autoupdate ? 'selected' : '' ); ?>><?php esc_html_e( '3 days', 'mainwp' ); ?></option>
								<option value="4" <?php echo ( 4 == $delay_autoupdate ? 'selected' : '' ); ?>><?php esc_html_e( '4 days', 'mainwp' ); ?></option>
								<option value="5" <?php echo ( 5 == $delay_autoupdate ? 'selected' : '' ); ?>><?php esc_html_e( '5 days', 'mainwp' ); ?></option>
								<option value="6" <?php echo ( 6 == $delay_autoupdate ? 'selected' : '' ); ?>><?php esc_html_e( '6 days', 'mainwp' ); ?></option>
								<option value="7" <?php echo ( 7 == $delay_autoupdate ? 'selected' : '' ); ?>><?php esc_html_e( '7 days', 'mainwp' ); ?></option>
								<option value="14" <?php echo ( 14 == $delay_autoupdate ? 'selected' : '' ); ?>><?php esc_html_e( '14 days', 'mainwp' ); ?></option>
								<option value="30" <?php echo ( 30 == $delay_autoupdate ? 'selected' : '' ); ?>><?php esc_html_e( '30 days', 'mainwp' ); ?></option>
							</select>
						</div>
					</div>
					<div class="ui grid field">
						<label class="six wide column middle aligned"><?php esc_html_e( 'Show WordPress language updates', 'mainwp' ); ?></label>
						<div class="ten wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'Enable if you want to manage Translation updates', 'mainwp' ); ?>" data-inverted="" data-position="top left">
							<input type="checkbox" name="mainwp_show_language_updates" id="mainwp_show_language_updates" <?php echo ( 1 == $mainwp_show_language_updates ? 'checked="true"' : '' ); ?>/>
						</div>
					</div>
					<div class="ui grid field">
						<label class="six wide column middle aligned"><?php esc_html_e( 'Update confirmations', 'mainwp' ); ?></label>
						<div class="ten wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'Choose if you want to disable the popup confirmations when performing updates.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
							<select name="mainwp_disable_update_confirmations" id="mainwp_disable_update_confirmations" class="ui dropdown">
								<option value="0" <?php echo ( 0 == $disableUpdateConfirmations ? 'selected' : '' ); ?>><?php esc_html_e( 'Enable', 'mainwp' ); ?></option>
								<option value="2" <?php echo ( 2 == $disableUpdateConfirmations ? 'selected' : '' ); ?>><?php esc_html_e( 'Disable', 'mainwp' ); ?></option>
								<option value="1" <?php echo ( 1 == $disableUpdateConfirmations ? 'selected' : '' ); ?>><?php esc_html_e( 'Disable for single updates', 'mainwp' ); ?></option>
							</select>
						</div>
					</div>
					<div class="ui hidden divider"></div>
					<div class="ui hidden divider"></div>
				</div>
				<div class="actions">
					<div class="ui two columns grid">
						<div class="left aligned column"></div>
						<div class="ui right aligned column">
							<input type="submit" class="ui green button" name="btnSubmit" id="submit-manage-updates-settings" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>" />
							<div class="ui cancel button"><?php esc_html_e( 'Close', 'mainwp' ); ?></div>
						</div>
					</div>
				</div>
			</form>
		</div>
		<?php
	}


	/**
	 * MainWP Help Box content. Hook the section help content to the Help Sidebar element.
	 */
	public static function mainwp_help_content() {
		if ( isset( $_GET['page'] ) && 'UpdatesManage' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			?>
			<p><?php esc_html_e( 'If you need help with managing updates, please review following help documents', 'mainwp' ); ?></p>
			<div class="ui relaxed bulleted list">
				<div class="item"><a href="https://kb.mainwp.com/docs/update-plugins/" target="_blank">Update Plugins</a></div>
				<div class="item"><a href="https://kb.mainwp.com/docs/plugins-auto-updates/" target="_blank">Plugins Auto Updates</a></div>
				<div class="item"><a href="https://kb.mainwp.com/docs/ignore-plugin-updates/" target="_blank">Ignore Plugin Updates</a></div>
				<div class="item"><a href="https://kb.mainwp.com/docs/update-themes/" target="_blank">Update Themes</a></div>
				<div class="item"><a href="https://kb.mainwp.com/docs/themes-auto-updates/" target="_blank">Themes Auto Updates</a></div>
				<div class="item"><a href="https://kb.mainwp.com/docs/ignore-theme-updates/" target="_blank">Ignore Theme Updates</a></div>
				<div class="item"><a href="https://kb.mainwp.com/docs/update-wordpress-core/" target="_blank">Update WordPress Core</a></div>
				<div class="item"><a href="https://kb.mainwp.com/docs/auto-update-wordpress-core/" target="_blank">Auto Update WordPress Core</a></div>
				<div class="item"><a href="https://kb.mainwp.com/docs/ignore-wordpress-core-update/" target="_blank">Ignore WordPress Core Update</a></div>
				<?php
				/**
				 * Action: mainwp_updates_help_item
				 *
				 * Fires at the bottom of the help articles list in the Help sidebar on the Updates page.
				 *
				 * Suggested HTML markup:
				 *
				 * <div class="item"><a href="Your custom URL">Your custom text</a></div>
				 *
				 * @since 4.1
				 */
				do_action( 'mainwp_updates_help_item' );
				?>
			</div>
			<?php
		}
	}

}
