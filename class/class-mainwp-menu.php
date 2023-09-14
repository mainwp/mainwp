<?php
/**
 * MainWP Main Menu
 *
 * Build & Render MainWP Main Menu.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Menu
 *
 * @package MainWP\Dashboard
 */
class MainWP_Menu {

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

	/**
	 * MainWP_Menu constructor.
	 *
	 * Run each time the class is called.
	 * Define MainWP Main Menu Items.
	 */
	public function __construct() {

		/**
		 * MainWP Disable Menus items array.
		 *
		 * @global object
		 */
		global $_mainwp_disable_menus_items;

		// Init disable menu items, default is false.
		// Use the MainWP Hook 'mainwp_main_menu_disable_menu_items' to disable menu items.
		if ( null === $_mainwp_disable_menus_items ) {
			$_mainwp_disable_menus_items = array(
				// Compatible with old hooks.
				'level_1' => array(
					'not_set_this_level' => true,
				),
				'level_2' => array(
					// 'mainwp_tab' - Do not hide this menu.
					'UpdatesManage'     => false,
					'managesites'       => false,
					'ManageClients'     => false,
					'ManageGroups'      => false,
					'PostBulkManage'    => false,
					'PageBulkManage'    => false,
					'ThemesManage'      => false,
					'PluginsManage'     => false,
					'UserBulkManage'    => false,
					'ManageBackups'     => false,
					'Settings'          => false,
					'Extensions'        => false,
					'ServerInformation' => false,
				),
				// Compatible with old hooks.
				'level_3' => array(),
			);
		}
	}

	/**
	 * Method init_mainwp_menus()
	 *
	 * Init MainWP menus.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::is_admin()
	 * @uses \MainWP\Dashboard\MainWP_Updates::init_menu()
	 * @uses \MainWP\Dashboard\MainWP_Manage_Sites::init_menu()
	 * @uses \MainWP\Dashboard\MainWP_Post::init_menu()
	 * @uses \MainWP\Dashboard\MainWP_Page::init_menu()
	 * @uses \MainWP\Dashboard\MainWP_Themes::init_menu()
	 * @uses \MainWP\Dashboard\MainWP_Plugins::init_menu()
	 * @uses \MainWP\Dashboard\MainWP_User::init_menu()
	 * @uses \MainWP\Dashboard\MainWP_Manage_Backups::init_menu()
	 * @uses \MainWP\Dashboard\MainWP_Manage_Groups::init_menu()
	 * @uses \MainWP\Dashboard\MainWP_Monitoring::init_menu()
	 * @uses \MainWP\Dashboard\MainWP_Settings::init_menu()
	 * @uses \MainWP\Dashboard\MainWP_Extensions::init_menu()
	 * @uses \MainWP\Dashboard\MainWP_Bulk_Update_Admin_Passwords::init_menu()
	 */
	public static function init_mainwp_menus() { // phpcs:ignore -- complex method. Current complexity is the only way to achieve desired results, pull request solutions appreciated.
		if ( MainWP_System_Utility::is_admin() ) {

			// Manage Sites.
			if ( ! self::is_disable_menu_item( 2, 'managesites' ) ) {
				if ( mainwp_current_user_have_right( 'dashboard', 'add_sites' ) || mainwp_current_user_have_right( 'dashboard', 'edit_sites' ) || mainwp_current_user_have_right( 'dashboard', 'delete_sites' ) || mainwp_current_user_have_right( 'dashboard', 'access_individual_dashboard' ) || mainwp_current_user_have_right( 'dashboard', 'access_wpadmin_on_child_sites' ) || mainwp_current_user_have_right( 'dashboard', 'manage_security_issues' ) && mainwp_current_user_have_right( 'dashboard', 'test_connection' ) || mainwp_current_user_have_right( 'dashboard', 'manage_groups' ) ) {
					MainWP_Manage_Sites::init_menu();
				}
			}

			// Manage Clients.
			if ( ! self::is_disable_menu_item( 2, 'ManageClients' ) ) {
				if ( mainwp_current_user_have_right( 'dashboard', 'manage_clients' ) ) {
					MainWP_Client::init_menu();
				}
			}

			// Manage Tags.
			if ( ! self::is_disable_menu_item( 2, 'ManageGroups' ) ) {
				if ( mainwp_current_user_have_right( 'dashboard', 'manage_groups' ) ) {
					MainWP_Manage_Groups::init_menu();
				}
			}

			// Manage Updates.
			if ( ! self::is_disable_menu_item( 2, 'UpdatesManage' ) ) {
				if ( mainwp_current_user_have_right( 'dashboard', 'update_wordpress' ) || mainwp_current_user_have_right( 'dashboard', 'update_plugins' ) || mainwp_current_user_have_right( 'dashboard', 'update_themes' ) || mainwp_current_user_have_right( 'dashboard', 'update_translations' ) || mainwp_current_user_have_right( 'dashboard', 'ignore_unignore_updates' ) || mainwp_current_user_have_right( 'dashboard', 'trust_untrust_updates' ) ) {
					MainWP_Updates::init_menu();
				}
			}

			// Manage Plugins.
			if ( ! self::is_disable_menu_item( 2, 'PluginsManage' ) ) {
				if ( mainwp_current_user_have_right( 'dashboard', 'install_plugins' ) || mainwp_current_user_have_right( 'dashboard', 'delete_plugins' ) || mainwp_current_user_have_right( 'dashboard', 'activate_deactivate_plugins' ) ) {
					MainWP_Plugins::init_menu();
				}
			}

			// Manage Themes.
			if ( ! self::is_disable_menu_item( 2, 'ThemesManage' ) ) {
				if ( mainwp_current_user_have_right( 'dashboard', 'install_themes' ) || mainwp_current_user_have_right( 'dashboard', 'delete_themes' ) || mainwp_current_user_have_right( 'dashboard', 'activate_deactivate_themes' ) ) {
					MainWP_Themes::init_menu();
				}
			}

			// Manage Users.
			if ( ! self::is_disable_menu_item( 2, 'UserBulkManage' ) ) {
				if ( mainwp_current_user_have_right( 'dashboard', 'manage_users' ) ) {
					MainWP_User::init_menu();
				}
			}

			// Manage Posts.
			if ( ! self::is_disable_menu_item( 2, 'PostBulkManage' ) ) {
				if ( mainwp_current_user_have_right( 'dashboard', 'manage_posts' ) ) {
					MainWP_Post::init_menu();
				}
			}

			// Manage Pages.
			if ( ! self::is_disable_menu_item( 2, 'PageBulkManage' ) ) {
				if ( mainwp_current_user_have_right( 'dashboard', 'manage_pages' ) ) {
					MainWP_Page::init_menu();
				}
			}

			// Manage Backups.
			if ( ! self::is_disable_menu_item( 2, 'ManageBackups' ) ) {
				MainWP_Manage_Backups::init_menu();
			}

			// Manage RESTAPI.
			if ( ! self::is_disable_menu_item( 2, 'RESTAPI' ) ) {
				if ( mainwp_current_user_have_right( 'dashboard', 'manage_dashboard_restapi' ) ) {
					MainWP_Rest_Api_Page::init_menu();
				}
			}

			// Monitoring Sites.
			if ( ! self::is_disable_menu_item( 3, 'Extensions' ) ) {
				if ( mainwp_current_user_have_right( 'dashboard', 'manage_extensions' ) ) {
					MainWP_Extensions::init_menu();
				}
			}

			// Manage Settings.
			if ( ! self::is_disable_menu_item( 2, 'Settings' ) ) {
				if ( mainwp_current_user_have_right( 'dashboard', 'manage_dashboard_settings' ) ) {
					MainWP_Settings::init_menu();
				}
			}

			// Manage Admin Passwords.
			if ( ! self::is_disable_menu_item( 3, 'UpdateAdminPasswords' ) ) {
				if ( mainwp_current_user_have_right( 'dashboard', 'manage_users' ) ) {
					MainWP_Bulk_Update_Admin_Passwords::init_menu();
				}
			}

			// Monitoring Sites.
			if ( ! self::is_disable_menu_item( 3, 'MonitoringSites' ) ) {
				MainWP_Monitoring::init_menu();
			}

			/**
			 * Action: mainwp_admin_menu
			 *
			 * Hooks main navigation menu items.
			 *
			 * @since 4.0
			 */
			do_action( 'mainwp_admin_menu' );

			if ( ! self::is_disable_menu_item( 2, 'ServerInformation' ) ) {
				if ( mainwp_current_user_have_right( 'dashboard', 'see_server_information' ) ) {
					MainWP_Server_Information::init_menu();
				}
			}
		}
	}

	/**
	 * Method init_sub_pages()
	 *
	 * Init subpages MainWP menus.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Extensions::init_subpages_menu()
	 * @uses \MainWP\Dashboard\MainWP_Manage_Backups::init_subpages_menu()
	 * @uses \MainWP\Dashboard\MainWP_Manage_Sites::init_subpages_menu()
	 * @uses \MainWP\Dashboard\MainWP_Page::init_subpages_menu()
	 * @uses \MainWP\Dashboard\MainWP_Post::init_subpages_menu()
	 * @uses \MainWP\Dashboard\MainWP_Settings::init_subpages_menu()
	 * @uses \MainWP\Dashboard\MainWP_Themes::init_subpages_menu()
	 * @uses \MainWP\Dashboard\MainWP_Themes::init_subpages_menu()
	 * @uses \MainWP\Dashboard\MainWP_Plugins::init_subpages_menu()
	 * @uses \MainWP\Dashboard\MainWP_User::init_subpages_menu()
	 * @uses \MainWP\Dashboard\MainWP_Settings::init_subpages_menu()
	 */
	public static function init_sub_pages() {

		if ( ! self::is_disable_menu_item( 2, 'PostBulkManage' ) ) {
			MainWP_Post::init_subpages_menu();
		}
		if ( ! self::is_disable_menu_item( 2, 'managesites' ) ) {
			MainWP_Manage_Sites::init_subpages_menu();
		}

		if ( ! self::is_disable_menu_item( 2, 'RESTAPI' ) ) {
			MainWP_Rest_Api_Page::init_subpages_menu();
		}

		if ( ! self::is_disable_menu_item( 2, 'Settings' ) ) {
			MainWP_Settings::init_subpages_menu();
		}

		if ( ! self::is_disable_menu_item( 2, 'Extensions' ) ) {
			MainWP_Extensions::init_subpages_menu();
		}
		if ( ! self::is_disable_menu_item( 2, 'PageBulkManage' ) ) {
			MainWP_Page::init_subpages_menu();
		}
		if ( ! self::is_disable_menu_item( 2, 'ThemesManage' ) ) {
			MainWP_Themes::init_subpages_menu();
		}
		if ( ! self::is_disable_menu_item( 2, 'PluginsManage' ) ) {
			MainWP_Plugins::init_subpages_menu();
		}
		if ( ! self::is_disable_menu_item( 2, 'UserBulkManage' ) ) {
			MainWP_User::init_subpages_menu();
		}
		if ( ! self::is_disable_menu_item( 2, 'ManageClients' ) ) {
			MainWP_Client::init_subpages_menu();
		}
		if ( get_option( 'mainwp_enableLegacyBackupFeature' ) ) {
			if ( ! self::is_disable_menu_item( 2, 'ManageBackups' ) ) {
				MainWP_Manage_Backups::init_subpages_menu();
			}
		}
		if ( ! self::is_disable_menu_item( 2, 'Settings' ) ) {
			MainWP_Settings::init_subpages_menu();
		}

		/**
		 * Action: mainwp_admin_menu_sub
		 *
		 * Hooks main navigation sub-menu items.
		 *
		 * @since 4.0
		 */
		do_action( 'mainwp_admin_menu_sub' );

		if ( ! self::is_disable_menu_item( 2, 'ServerInformation' ) ) {
			MainWP_Server_Information::init_subpages_menu();
		}
	}


	/**
	 * Method init_subpages_left_menu
	 *
	 * Build left menu subpages array.
	 *
	 * @param array  $subPages Array of SubPages.
	 * @param array  $initSubpage Initial SubPage Array.
	 * @param string $parentKey Parent Menu Slug.
	 * @param mixed  $slug SubPage Slug.
	 */
	public static function init_subpages_left_menu( $subPages, &$initSubpage, $parentKey, $slug ) {

		if ( ! is_array( $subPages ) ) {
			$subPages = array();
		}

		global $_mainwp_menu_active_slugs;

		$subPages = apply_filters( 'mainwp_subpages_left_menu', $subPages, $initSubpage, $parentKey, $slug );

		foreach ( $subPages as $subPage ) {
			if ( ! isset( $subPage['menu_hidden'] ) || ( isset( $subPage['menu_hidden'] ) && true != $subPage['menu_hidden'] ) ) {
				$_item = array(
					'title'      => $subPage['title'],
					'parent_key' => $parentKey,
					'href'       => 'admin.php?page=' . $slug . $subPage['slug'],
					'slug'       => $slug . $subPage['slug'],
					'right'      => '',
				);

				// To support check right to open menu for sometime.
				if ( isset( $subPage['item_slug'] ) ) {
					$_item['item_slug'] = $subPage['item_slug'];
				}

				if ( isset( $subPage['href'] ) && ! empty( $subPage['href'] ) ) { // override href.
					$_item['href'] = $subPage['href'];
				}

				$initSubpage[] = $_item;
			} elseif ( isset( $subPage['slug'] ) ) {
				$_mainwp_menu_active_slugs[ $slug . $subPage['slug'] ] = $parentKey; // to fix.
			}
		}
	}

	/**
	 * Method is_disable_menu_item
	 *
	 * Check if $_mainwp_disable_menus_items contains any menu items to hide.
	 *
	 * @param string $level The level the menu item is on.
	 * @param array  $item The menu items meta data.
	 *
	 * @return bool True|False, default is False.
	 */
	public static function is_disable_menu_item( $level, $item ) {

		/**
		 * MainWP Disable Menus items array.
		 *
		 * @global object
		 */
		global $_mainwp_disable_menus_items;
		$disabled = false;
		$_level   = 'level_' . $level;
		if ( is_array( $_mainwp_disable_menus_items ) && isset( $_mainwp_disable_menus_items[ $_level ] ) && isset( $_mainwp_disable_menus_items[ $_level ][ $item ] ) ) {
			$disabled = $_mainwp_disable_menus_items[ $_level ][ $item ];
		}
		$disabled                                        = apply_filters( 'mainwp_is_disable_menu_item', $disabled, $level, $item );
		$_mainwp_disable_menus_items[ $_level ][ $item ] = $disabled;
		return $disabled;
	}

	/**
	 * Method add_left_menu
	 *
	 * Build Top Level Menu
	 *
	 * @param array   $params Menu Item parameters.
	 * @param integer $level Menu Item Level 1 or 2.
	 *
	 * @return array $mainwp_leftmenu[] | $mainwp_sub_leftmenu[].
	 */
	public static function add_left_menu( $params = array(), $level = 1 ) {

		if ( empty( $params ) ) {
			return;
		}

		$level = (int) $level;

		if ( 1 != $level && 2 != $level && 0 != $level ) {
			$level = 1;
		}

		$title = $params['title'];

		$slug  = $params['slug'];
		$href  = $params['href'];
		$right = isset( $params['right'] ) ? $params['right'] : '';
		$id    = isset( $params['id'] ) ? $params['id'] : '';

		$icon = isset( $params['icon'] ) ? $params['icon'] : '';

		/**
		 * MainWP Left Menu, Sub Menu & Active menu slugs.
		 *
		 * @global object $mainwp_leftmenu
		 * @global object $mainwp_sub_leftmenu
		 * @global object $_mainwp_menu_active_slugs
		 */
		global $mainwp_leftmenu, $mainwp_sub_leftmenu, $_mainwp_menu_active_slugs;

		if ( ! is_array( $mainwp_leftmenu ) ) {
			$mainwp_leftmenu = array();
		}

		if ( ! isset( $mainwp_leftmenu['mainwp_tab'] ) ) {
			$mainwp_leftmenu['mainwp_tab'] = array(); // to compatible with old hooks.
		}

		$title = esc_html( $title );

		$parent_key = '';

		if ( 0 === $level ) {
			$parent_key                   = 'mainwp_tab'; // forced value.
			$mainwp_leftmenu['leftbar'][] = array( $title, $slug, $href, $id, $icon );
		} elseif ( 1 === $level ) {
			if ( isset( $params['parent_key'] ) && ! empty( $params['parent_key'] ) ) {
				$parent_key = $params['parent_key'];
			} else {
				$parent_key = 'mainwp_tab'; // forced value.
			}

			if ( 'mainwp_tab' === $parent_key ) {
				$mainwp_leftmenu[ $parent_key ][] = array( $title, $slug, $href, $id );
			} else {
				$mainwp_sub_leftmenu['leftbar'][ $parent_key ][] = array( $title, $slug, $href, $id );

				if ( ! empty( $slug ) ) {
					$_mainwp_menu_active_slugs['leftbar'][ $slug ] = $parent_key; // to get active menu.
				}
			}
		} else {
			if ( isset( $params['parent_key'] ) ) {
				$parent_key = $params['parent_key'];
			} else {
				$parent_key = 'mainwp_tab'; // forced value.
			}
			$mainwp_sub_leftmenu[ $parent_key ][] = array( $title, $href, $right, $id, $slug );
		}

		if ( ! empty( $slug ) ) {
			$_mainwp_menu_active_slugs[ $slug ] = $parent_key; // to get active menu.
		}
	}

	/**
	 * Method render_left_menu
	 *
	 * Build Top Level Main Menu HTML & Render.
	 */
	public static function render_left_menu() { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		/**
		 * MainWP Left Menu, Sub Menu & Active menu slugs.
		 *
		 * @global object $mainwp_leftmenu
		 * @global object $mainwp_sub_leftmenu
		 * @global object $_mainwp_menu_active_slugs
		 */
		global $mainwp_leftmenu, $mainwp_sub_leftmenu, $_mainwp_menu_active_slugs, $plugin_page;

		/**
		 * Filter: mainwp_main_menu
		 *
		 * Filters main navigation menu items
		 *
		 * @since 4.0
		 */
		$mainwp_leftmenu = apply_filters( 'mainwp_main_menu', $mainwp_leftmenu );
		$main_leftmenu   = isset( $mainwp_leftmenu['mainwp_tab'] ) ? $mainwp_leftmenu['mainwp_tab'] : array();
		$bar_leftmenu    = isset( $mainwp_leftmenu['leftbar'] ) ? $mainwp_leftmenu['leftbar'] : array();

		/**
		 * Filter: mainwp_main_menu_submenu
		 *
		 * Filters main navigation subt-menu items
		 *
		 * @since 4.0
		 */

		$mainwp_sub_leftmenu = apply_filters( 'mainwp_main_menu_submenu', $mainwp_sub_leftmenu );
		$sub_bar_leftmenu    = isset( $mainwp_sub_leftmenu['leftbar'] ) ? $mainwp_sub_leftmenu['leftbar'] : array();

		$version = get_option( 'mainwp_plugin_version' );

		?>
		<?php
		/**
		 * Action: before_mainwp_menu
		 *
		 * Fires before the main navigation element.
		 *
		 * @since 4.0
		 */
		do_action( 'before_mainwp_menu' );
		?>
		<div id="mainwp-main-navigation-container">
			<div id="mainwp-first-level-navigation">
				<div id="mainwp-first-level-navigation-logo">
				<a href="
				<?php
				/**
				 * Filter: mainwp_menu_logo_href
				 *
				 * Filters the Logo link.
				 *
				 * @since 4.1.4
				 */
				echo esc_url( apply_filters( 'mainwp_menu_logo_href', admin_url( 'admin.php?page=mainwp_tab' ) ) );
				?>
				">
				<img src="
				<?php
				/**
				 * Filter: mainwp_menu_logo_src
				 *
				 * Filters the Logo src attribute.
				 *
				 * @since 4.1
				 */
				echo esc_url( apply_filters( 'mainwp_menu_logo_src', MAINWP_PLUGIN_URL . 'assets/images/mainwp-icon.svg' ) );
				?>
				" alt="
				<?php
				/**
				 * Filter: mainwp_menu_logo_alt
				 *
				 * Filters the Logo alt attribute.
				 *
				 * @since 4.1
				 */
				echo esc_html( apply_filters( 'mainwp_menu_logo_alt', 'MainWP' ) );
				?>
					" id="mainwp-navigation-icon" />
				</a>
				</div>
				<div id="mainwp-first-level-navigation-version-label">
				<span id="mainwp-version-label" class="ui mini green fluid centered label"><?php echo esc_html( $version ); ?></span>
				</div>
				<div id="mainwp-first-level-navigation-menu" class="ui vertical labeled inverted icon tiny menu">
				<?php

					$bar_item_actived_key = '';
				if ( is_array( $_mainwp_menu_active_slugs ) && isset( $_mainwp_menu_active_slugs[ $plugin_page ] ) ) {
					$menu_item_actived_key = $_mainwp_menu_active_slugs[ $plugin_page ];
					if ( isset( $_mainwp_menu_active_slugs['leftbar'] ) && is_array( $_mainwp_menu_active_slugs['leftbar'] ) && isset( $_mainwp_menu_active_slugs['leftbar'][ $menu_item_actived_key ] ) ) {
						$bar_item_actived_key = $_mainwp_menu_active_slugs['leftbar'][ $menu_item_actived_key ];
					}
				}

					$bar_item_active = null;

				if ( is_array( $bar_leftmenu ) && ! empty( $bar_leftmenu ) ) {
					foreach ( $bar_leftmenu as $item ) {
						$title     = wptexturize( $item[0] );
						$item_key  = $item[1];
						$href      = $item[2];
						$item_id   = isset( $item[3] ) ? $item[3] : '';
						$item_icon = isset( $item[4] ) ? $item[4] : '';

						$has_sub = true;
						if ( ! isset( $mainwp_sub_leftmenu[ $item_key ] ) || empty( $mainwp_sub_leftmenu[ $item_key ] ) ) {
							$has_sub = false;
						}
						$active_item = '';

						if ( empty( $bar_item_actived_key ) ) {
							if ( isset( $_mainwp_menu_active_slugs[ $plugin_page ] ) ) {
								if ( $item_key == $_mainwp_menu_active_slugs[ $plugin_page ] ) {
									$bar_item_actived_key = $item_key;
								}
							}
						}

						if ( ! empty( $bar_item_actived_key ) && $item_key == $bar_item_actived_key ) {
							$active_item     = 'active';
							$bar_item_active = $item;
						}

						$id_attr = ! empty( $item_id ) ? 'id="' . esc_html( $item_id ) . '"' : '';

						// phpcs:disable WordPress.Security.EscapeOutput
						if ( $has_sub ) {
							echo '<a ' . $id_attr . ' title="' . esc_html( $title ) . "\" class=\"item $active_item\" href=\"$href\">";
							echo ! empty( $item_icon ) ? $item_icon : '<i class="th large icon"></i>';
							echo '<span class="ui small text">' . esc_html( $title ) . '</span>';
							echo '</a>';
						} else {
							echo '<a ' . $id_attr . ' title="' . esc_html( $title ) . "\" class=\"item $active_item\" href=\"$href\">";
							echo ! empty( $item_icon ) ? $item_icon : '<i class="th large icon"></i>';
							echo '<span class="ui small text">' . esc_html( $title ) . '</span>';
							echo '</a>';
						}
						// phpcs:enable
					}
				}
				?>
				</div>
				<?php
					$go_back_wpadmin_url = admin_url( 'index.php' );

					$link = array(
						'url'  => $go_back_wpadmin_url,
						'text' => esc_html__( 'WP Admin', 'mainwp' ),
						'tip'  => esc_html__( 'Click to go back to the site WP Admin area.', 'mainwp' ),
					);
					/**
					 * Filter: mainwp_go_back_wpadmin_link
					 *
					 * Filters URL for the Go to WP Admin button in Main navigation.
					 *
					 * @since 4.0
					 */
					$go_back_link = apply_filters( 'mainwp_go_back_wpadmin_link', $link );

					if ( false !== $go_back_link ) {
						if ( is_array( $go_back_link ) ) {
							if ( isset( $go_back_link['url'] ) ) {
								$link['url'] = $go_back_link['url'];
							}
							if ( isset( $go_back_link['text'] ) ) {
								$link['text'] = $go_back_link['text'];
							}
							if ( isset( $go_back_link['tip'] ) ) {
								$link['tip'] = $go_back_link['tip'];
							}
						}
					}
					?>
				<div id="mainwp-first-level-wpitems-menu" class="ui vertical labeled inverted icon tiny menu">
					<a class="item" href="#" id="mainwp-collapse-second-level-navigation">
						<i class="angle double left icon"></i>
						<span class="ui small text" style="font-size:8px"><?php esc_html_e( 'Hide Menu', 'mainwp' ); ?></span>
					</a>
					<a class="item" href="<?php echo esc_html( $link['url'] ); ?>">
						<i class="wordpress icon"></i> <?php //phpcs:ignore -- ignore wordpress icon. ?>
						<span class="ui small text"><?php esc_html_e( 'WP Admin', 'mainwp' ); ?></span>
					</a> 
					<a class="item" href="<?php echo wp_logout_url(); // phpcs:ignore WordPress.Security.EscapeOutput ?>">
						<i class="sign out icon"></i>
						<span class="ui small text"><?php esc_html_e( 'Log Out', 'mainwp' ); ?></span>
					</a>
				</div>
			</div>
			<div id="mainwp-second-level-navigation">
				<div id="mainwp-main-menu" class="ui inverted vertical accordion menu">
					<?php
					$bar_active_item_key = '';

					$set_actived = false;

					if ( ! empty( $bar_item_active ) ) {
						$item     = $bar_item_active;
						$title    = wptexturize( $item[0] );
						$item_key = $item[1];
						$href     = $item[2];
						$item_id  = isset( $item[3] ) ? $item[3] : '';

						$bar_active_item_key = $item_key;

						$has_sub = true;
						if ( ! isset( $mainwp_sub_leftmenu[ $item_key ] ) || empty( $mainwp_sub_leftmenu[ $item_key ] ) ) {
							$has_sub = false;
						}
						$active_item = '';
						// to fix active menu.
						if ( ! $set_actived ) {
							if ( isset( $_mainwp_menu_active_slugs[ $plugin_page ] ) ) {
								if ( $item_key == $_mainwp_menu_active_slugs[ $plugin_page ] ) {
									$active_item     = 'active';
									$set_actived     = true;
									$bar_item_active = $item;
								}
							}
						}

						$id_attr = ! empty( $item_id ) ? 'id="' . esc_html( $item_id ) . '"' : '';

						// phpcs:disable WordPress.Security.EscapeOutput
						if ( $has_sub ) {
							echo '<div ' . $id_attr . " class=\"item $active_item\">";
							echo "<a class=\"title with-sub $active_item\" href=\"$href\">$title <i class=\"dropdown icon\"></i></a>";
							echo "<div class=\"content menu $active_item\">";
							self::render_sub_item( $item_key );
							echo '</div>';
							echo '</div>';
						} else {
							echo '<div ' . $id_attr . ' class="item">';
							echo "<a class='title $active_item' href=\"$href\">$title</a>";
							echo '</div>';
						}
						// phpcs:enable

						if ( is_array( $sub_bar_leftmenu ) && ! empty( $bar_active_item_key ) && isset( $sub_bar_leftmenu[ $bar_active_item_key ] ) && is_array( $sub_bar_leftmenu[ $bar_active_item_key ] ) ) {

							$set_actived = false;
							foreach ( $sub_bar_leftmenu[ $bar_active_item_key ] as $item ) {

								if ( empty( $item ) || ! is_array( $item ) ) {
									continue;
								}

								$title    = wptexturize( $item[0] );
								$item_key = $item[1];

								$has_sub = true;
								if ( ! isset( $mainwp_sub_leftmenu[ $item_key ] ) || empty( $mainwp_sub_leftmenu[ $item_key ] ) ) {
									$has_sub = false;
								}

								$href    = $item[2];
								$item_id = isset( $item[3] ) ? $item[3] : '';

								$active_item = '';
								// to fix active menu.
								if ( ! $set_actived ) {
									if ( isset( $_mainwp_menu_active_slugs[ $plugin_page ] ) ) {
										if ( $item_key == $_mainwp_menu_active_slugs[ $plugin_page ] ) {
											$active_item = 'active';
											$set_actived = true;
										}
									}
								}

								$id_attr = ! empty( $item_id ) ? 'id="' . esc_html( $item_id ) . '"' : '';

								// phpcs:disable WordPress.Security.EscapeOutput
								if ( $has_sub ) {
									echo '<div ' . $id_attr . " class=\"item $active_item\">";
									echo "<a class=\"title with-sub $active_item\" href=\"$href\">$title <i class=\"dropdown icon\"></i></a>";
									echo "<div class=\"content menu $active_item\">";
									self::render_sub_item( $item_key );
									echo '</div>';
									echo '</div>';
								} else {
									echo '<div ' . $id_attr . ' class="item">';
									echo "<a class='title $active_item' href=\"$href\">$title</a>";
									echo '</div>';
								}
								// phpcs:enable
							}
						}
					}
					?>
					</div>
					</div>
				</div>
				<?php
				/**
				 * Action: after_mainwp_menu
				 *
				 * Fires after the main navigation element.
				 *
				 * @since 4.0
				 */
				do_action( 'after_mainwp_menu' );
				?>
			<script type="text/javascript">
				jQuery( document ).ready( function () {

					mainwp_left_bar_showhide_init = function(){
						if(jQuery('body').hasClass('toplevel_page_mainwp_tab')){
							return; // hide always.
						}
						var lbar = jQuery( '#mainwp-collapse-second-level-navigation' );
						var show = ( 0 != mainwp_ui_state_load( 'showmenu' ) ) ? true : false;
						mainwp_left_bar_showhide( lbar, show);
					}
					mainwp_left_bar_showhide = function( lbar, show ){
						if ( show ) {
							jQuery( '#mainwp-second-level-navigation' ).show();
							jQuery( '.mainwp-content-wrap' ).css( "margin-left", "272px" );
							jQuery( '#mainwp-main-navigation-container' ).css( "width", "272px" );
							jQuery( lbar ).find( '.icon' ).removeClass( 'right' );
							jQuery( lbar ).find( '.icon' ).addClass( 'left' );
							jQuery( lbar ).find( '.text' ).html( 'Hide Menu' );
							jQuery( lbar ).removeClass( 'collapsed' );
							mainwp_ui_state_save( 'showmenu', 1 );
						} else {
							jQuery( '#mainwp-second-level-navigation' ).hide();
							jQuery( '.mainwp-content-wrap' ).css( "margin-left", "72px" );
							jQuery( '#mainwp-main-navigation-container' ).css( "width", "72px" );
							jQuery( lbar ).find( '.icon' ).removeClass( 'left' );
							jQuery( lbar ).find( '.icon' ).addClass( 'right' );
							jQuery( lbar ).find( '.text' ).html( 'Show Menu' );
							jQuery( lbar ).addClass( 'collapsed' );
							mainwp_ui_state_save( 'showmenu', 0 );
						}
					}

					jQuery( '#mainwp-collapse-second-level-navigation' ).on( 'click', function() {
						var show = jQuery( this ).hasClass( 'collapsed' ) ? true : false;
						mainwp_left_bar_showhide( this, show);
						return false;
					} );
					mainwp_left_bar_showhide_init();

					// click on menu with-sub icon.
					jQuery( '#mainwp-main-navigation-container #mainwp-main-menu a.title.with-sub .icon' ).on( "click", function ( event ) {
						var pr = jQuery( this ).closest( '.item' );
						var title = jQuery( this ).closest( '.title' );
						var active = jQuery( title ).hasClass( 'active' );

						// remove current active.
						mainwp_menu_collapse();

						// if current menu item are not active then set it active.
						if ( !active ) {
							jQuery( title ).addClass( 'active' );
							jQuery( pr ).find('.content.menu').addClass( 'active' );
							pr.addClass('active');
						}
						return false;
					} );

					jQuery( '#mainwp-main-navigation-container #mainwp-main-menu a.title.with-sub' ).on( "click", function ( event ) {
						var pr = jQuery( this ).closest( '.item' );
						var active = jQuery( this ).hasClass( 'active' );

						// remove current active.
						mainwp_menu_collapse();

						// set active before go to the page.
						if ( !active ) {
							jQuery( this ).addClass( 'active' );
							jQuery( pr ).find('.content.menu').addClass( 'active' );
							pr.addClass('active');
						}
					} );

					mainwp_menu_collapse = function() {
						// remove current active.
						jQuery( '#mainwp-main-navigation-container #mainwp-main-menu a.title.active').removeClass('active');
						jQuery( '#mainwp-main-navigation-container #mainwp-main-menu .item').removeClass('active');
						jQuery( '#mainwp-main-navigation-container #mainwp-main-menu .content.menu.active').removeClass('active');
					};

					jQuery('.mainwp-main-mobile-navigation-container #mainwp-main-menu').accordion();

				} );
			</script>
		<?php
	}

	/**
	 * Method render_mobile_menu
	 *
	 * Renders the mobile menu.
	 */
	public static function render_mobile_menu() { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.
		$mainwp_show_language_updates = get_option( 'mainwp_show_language_updates', 1 );
		?>
		<div class="mainwp-main-mobile-navigation-container">
			<div id="mainwp-logo">
				<a href="
				<?php
				/**
				 * Filter: mainwp_menu_logo_href
				 *
				 * Filters the Logo link.
				 *
				 * @since 4.1.4
				 */
				echo esc_url( apply_filters( 'mainwp_menu_logo_href', admin_url( 'admin.php?page=mainwp_tab' ) ) );
				?>
				">
				<img src="
				<?php
				/**
				 * Filter: mainwp_menu_logo_src
				 *
				 * Filters the Logo src attribute.
				 *
				 * @since 4.1
				 */
				echo esc_url( apply_filters( 'mainwp_menu_logo_src', MAINWP_PLUGIN_URL . 'assets/images/logo.png' ) );
				?>
				" alt="
				<?php
				/**
				 * Filter: mainwp_menu_logo_alt
				 *
				 * Filters the Logo alt attribute.
				 *
				 * @since 4.1
				 */
				echo apply_filters( 'mainwp_menu_logo_alt', 'MainWP' ); // phpcs:ignore WordPress.Security.EscapeOutput
				?>
				" id="mainwp-navigation-icon"/>
				</a>
			</div>
			<div class="mainwp-nav-menu">
				<?php
				/**
				 * Action: before_mainwp_menu
				 *
				 * Fires before the main navigation element.
				 *
				 * @since 4.0
				 */
				do_action( 'before_mainwp_menu' );
				?>

				<div id="mainwp-main-menu"  class="test-menu ui inverted vertical accordion menu">
					<div class="item"></div>
					<div class="item"><a href="admin.php?page=mainwp_tab"><?php esc_html_e( 'Overview', 'mainwp' ); ?></a></div>
					<div class="item">
						<div class="title"><a href="admin.php?page=managesites" class=" with-sub"><?php esc_html_e( 'Sites', 'mainwp' ); ?></a><i class="dropdown icon"></i></div>
						<div class="content menu" id="mainwp-sites-mobile-menu-item">
								<div class="accordion item">
									<div class="title"><a href="admin.php?page=managesites"><?php esc_html_e( 'Sites', 'mainwp' ); ?></a><i class="dropdown icon"></i></div>
									<div class="content menu">
										<a class="item" href="admin.php?page=managesites"><?php esc_html_e( 'Manage Sites', 'mainwp' ); ?></a>
										<a class="item" href="admin.php?page=managesites&do=new"><?php esc_html_e( 'Add New Site', 'mainwp' ); ?></a>
										<a class="item" href="admin.php?page=managesites&do=bulknew"><?php esc_html_e( 'Import Sites', 'mainwp' ); ?></a>
										<a class="item" href="admin.php?page=MonitoringSites"><?php esc_html_e( 'Monitoring', 'mainwp' ); ?></a>
									</div>
								</div>
								<div class="item accordion">
									<div class="title"><a class="" href="admin.php?page=ManageGroups"><?php esc_html_e( 'Tags', 'mainwp' ); ?></a><i class="dropdown icon"></i></div>
								<div class="content menu">
									<a class="item" href="admin.php?page=ManageGroups"><?php esc_html_e( 'Manage Tags', 'mainwp' ); ?></a>
								</div>
								</div>
								<div class="item accordion">
									<div class="title"><a href="admin.php?page=UpdatesManage"><?php esc_html_e( 'Updates', 'mainwp' ); ?></a><i class="dropdown icon"></i></div>
									<div class="content menu">
										<a class="item" href="admin.php?page=UpdatesManage&tab=plugins-updates"><?php esc_html_e( 'Plugins Updates', 'mainwp' ); ?></a>
										<a class="item" href="admin.php?page=UpdatesManage&tab=themes-updates"><?php esc_html_e( 'Themes Updates', 'mainwp' ); ?></a>
										<a class="item" href="admin.php?page=UpdatesManage&tab=wordpress-updates"><?php esc_html_e( 'WordPress Updates', 'mainwp' ); ?></a>
									<?php if ( $mainwp_show_language_updates ) : ?>
										<a class="item" href="admin.php?page=UpdatesManage&tab=translations-updates"><?php esc_html_e( 'Translation Plugins', 'mainwp' ); ?></a>
									<?php endif; ?>
										<a class="item" href="admin.php?page=UpdatesManage&tab=abandoned-plugins"><?php esc_html_e( 'Abandoned Plugins', 'mainwp' ); ?></a>
										<a class="item" href="admin.php?page=UpdatesManage&tab=abandoned-themes"><?php esc_html_e( 'Abandoned Themes', 'mainwp' ); ?></a>
									</div>
								</div>
								<div class="item accordion">
									<div class="title"><a href="admin.php?page=PluginsManage"><?php esc_html_e( 'Plugins', 'mainwp' ); ?></a><i class="dropdown icon"></i></div>
									<div class="content menu">
										<a class="item" href="admin.php?page=PluginsManage"><?php esc_html_e( 'Manage Plugins', 'mainwp' ); ?></a>
										<a class="item" href="admin.php?page=PluginsInstall"><?php esc_html_e( 'Install', 'mainwp' ); ?></a>
										<a class="item" href="admin.php?page=PluginsAutoUpdate"><?php esc_html_e( 'Advanced Auto Updates', 'mainwp' ); ?></a>
										<a class="item" href="admin.php?page=PluginsIgnore"><?php esc_html_e( 'Ignored Updates', 'mainwp' ); ?></a>
										<a class="item" href="admin.php?page=PluginsIgnoredAbandoned"><?php esc_html_e( 'Ignored Abandoned', 'mainwp' ); ?></a>
									</div>
								</div>
								<div class="item accordion">
									<div class="title"><a href="admin.php?page=ThemesManage"><?php esc_html_e( 'Themes', 'mainwp' ); ?></a><i class="dropdown icon"></i></div>
									<div class="content menu">
										<a class="item" href="admin.php?page=ThemesManage"><?php esc_html_e( 'Manage Themes', 'mainwp' ); ?></a>
										<a class="item" href="admin.php?page=ThemesInstall"><?php esc_html_e( 'Install', 'mainwp' ); ?></a>
										<a class="item" href="admin.php?page=ThemesAutoUpdate"><?php esc_html_e( 'Advanced Auto Updates', 'mainwp' ); ?></a>
										<a class="item" href="admin.php?page=ThemesIgnore"><?php esc_html_e( 'Ignored Updates', 'mainwp' ); ?></a>
										<a class="item" href="admin.php?page=ThemesIgnoredAbandoned"><?php esc_html_e( 'Ignored Abandoned', 'mainwp' ); ?></a>
									</div>
								</div>
								<div class="item accordion">
									<div class="title"><a href="admin.php?page=UserBulkManage"><?php esc_html_e( 'Users', 'mainwp' ); ?></a><i class="dropdown icon"></i></div>
									<div class="content menu">
										<a class="item" href="admin.php?page=UserBulkManage"><?php esc_html_e( 'Manage Users', 'mainwp' ); ?></a>
										<a class="item" href="admin.php?page=UserBulkAdd"><?php esc_html_e( 'Add New User', 'mainwp' ); ?></a>
										<a class="item" href="admin.php?page=BulkImportUsers"><?php esc_html_e( 'Import Users', 'mainwp' ); ?></a>
										<a class="item" href="admin.php?page=UpdateAdminPasswords"><?php esc_html_e( 'Admin Passwords', 'mainwp' ); ?></a>
									</div>
								</div>
								<div class="item accordion">
									<div class="title"><a href="admin.php?page=PostBulkManage"><?php esc_html_e( 'Posts', 'mainwp' ); ?></a><i class="dropdown icon"></i></div>
									<div class="content menu">
										<a class="item" href="admin.php?page=PostBulkManage"><?php esc_html_e( 'Manage Pages', 'mainwp' ); ?></a>
										<a class="item" href="admin.php?page=PostBulkAdd"><?php esc_html_e( 'Add New Post', 'mainwp' ); ?></a>
									</div>
								</div>
								<div class="item accordion">
									<div class="title"><a href="admin.php?page=PageBulkManage"><?php esc_html_e( 'Pages', 'mainwp' ); ?></a><i class="dropdown icon"></i></div>
									<div class="content menu">
										<a class="item" href="admin.php?page=PageBulkManage"><?php esc_html_e( 'Manage Pages', 'mainwp' ); ?></a>
										<a class="item" href="admin.php?page=PageBulkAdd"><?php esc_html_e( 'Add New Page', 'mainwp' ); ?></a>
									</div>
								</div>
						</div>
					</div>
					<div class="item">
						<div class="title"><a href="admin.php?page=ManageClients" class="with-sub"><?php esc_html_e( 'Clients', 'mainwp' ); ?></a><i class="dropdown icon"></i></div>
						<div class="content menu">
							<a class="item" href="admin.php?page=ManageClients"><?php esc_html_e( 'Clients', 'mainwp' ); ?></a>
							<a class="item" href="admin.php?page=ClientAddNew"><?php esc_html_e( 'Add Client', 'mainwp' ); ?></a>
							<a class="item" href="admin.php?page=ClientAddField"><?php esc_html_e( 'Client Fields', 'mainwp' ); ?></a>
						</div>
					</div>
					<div class="item">
						<div class="title"><a href="admin.php?page=RESTAPI" class="with-sub"><?php esc_html_e( 'REST API', 'mainwp' ); ?></a><i class="dropdown icon"></i></div>
						<div class="content menu">
							<a class="item" href="admin.php?page=RESTAPI"><?php esc_html_e( 'Manage API Keys', 'mainwp' ); ?></a>
							<a class="item" href="admin.php?page=AddApiKeys"><?php esc_html_e( 'Add API Keys', 'mainwp' ); ?></a>
						</div>
					</div>
					<div class="item">
						<div class="title"><a href="admin.php?page=Extensions" class=""><?php esc_html_e( 'Extensions', 'mainwp' ); ?></a><i class="dropdown icon"></i></div>
						<div class="content menu">
							<a class="item" href="admin.php?page=Extensions"><?php esc_html_e( 'Manage Extensions', 'mainwp' ); ?></a>
						</div>
					</div>
					<div class="item">
						<div class="title"><a href="admin.php?page=Settings" class="with-sub"><?php esc_html_e( 'Settings', 'mainwp' ); ?></a><i class="dropdown icon"></i></div>
						<div class="content menu">
							<a class="item" href="admin.php?page=Settings"><?php esc_html_e( 'General Settings', 'mainwp' ); ?></a>
							<a class="item" href="admin.php?page=SettingsAdvanced"><?php esc_html_e( 'Advanced Settings', 'mainwp' ); ?></a>
							<a class="item" href="admin.php?page=SettingsEmail"><?php esc_html_e( 'Email Settings', 'mainwp' ); ?></a>
							<a class="item" href="admin.php?page=MainWPTools"><?php esc_html_e( 'Tools', 'mainwp' ); ?></a>
						</div>
					</div>
					<div class="item">
						<div class="title"><a href="admin.php?page=ServerInformation" class="with-sub"><?php esc_html_e( 'Info', 'mainwp' ); ?></a><i class="dropdown icon"></i></div>
						<div class="content menu">
							<a class="item" href="admin.php?page=ServerInformation"><?php esc_html_e( 'Server', 'mainwp' ); ?></a>
							<a class="item" href="admin.php?page=ServerInformationCron"><?php esc_html_e( 'Cron Schedules', 'mainwp' ); ?></a>
							<a class="item" href="admin.php?page=ErrorLog"><?php esc_html_e( 'Error Log', 'mainwp' ); ?></a>
							<a class="item" href="admin.php?page=ActionLogs"><?php esc_html_e( 'Action Logs', 'mainwp' ); ?></a>
							<a class="item" href="admin.php?page=PluginPrivacy"><?php esc_html_e( 'Plugin Privacy', 'mainwp' ); ?></a>
						</div>
					</div>
					<?php
					$go_back_wpadmin_url = admin_url( 'index.php' );

					$link = array(
						'url'  => $go_back_wpadmin_url,
						'text' => esc_html__( 'WP Admin', 'mainwp' ),
						'tip'  => esc_html__( 'Click to go back to the site WP Admin area.', 'mainwp' ),
					);

					/**
					 * Filter: mainwp_go_back_wpadmin_link
					 *
					 * Filters URL for the Go to WP Admin button in Main navigation.
					 *
					 * @since 4.0
					 */
					$go_back_link = apply_filters( 'mainwp_go_back_wpadmin_link', $link );

					if ( false !== $go_back_link ) {
						if ( is_array( $go_back_link ) ) {
							if ( isset( $go_back_link['url'] ) ) {
								$link['url'] = $go_back_link['url'];
							}
							if ( isset( $go_back_link['text'] ) ) {
								$link['text'] = $go_back_link['text'];
							}
							if ( isset( $go_back_link['tip'] ) ) {
								$link['tip'] = $go_back_link['tip'];
							}
						}
						?>
					<div class="item item-wp-admin">
						<a href="<?php echo esc_html( $link['url'] ); ?>" class="title" style="display:inline" data-position="top left" data-tooltip="<?php echo esc_html( $link['tip'] ); ?>"><b><i class="icon wordpress"></i> <?php echo esc_html( $link['text'] ); ?></b></a> <a class="ui small label" data-position="top right" data-tooltip="<?php esc_html_e( 'Logout', 'mainwp' ); ?>" href="<?php echo wp_logout_url(); ?>"><i class="sign out icon" style="margin:0"></i></a> <?php //phpcs:ignore -- to avoid auto fix icon wordpress ?>
					</div>
					<?php } ?>
					<div class="hamburger">
						<span class="hamburger-bun"></span>
						<span class="hamburger-patty"></span>
						<span class="hamburger-bun"></span>
					</div>
				</div>
				<?php
				/**
				 * Action: after_mainwp_menu
				 *
				 * Fires after the main navigation element.
				 *
				 * @since 4.0
				 */
				do_action( 'after_mainwp_menu' );
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Method render_sub_item
	 *
	 * Grabs all submenu items and attatches to Main Menu.
	 *
	 * @param mixed $parent_key The parent key.
	 */
	public static function render_sub_item( $parent_key ) {
		if ( empty( $parent_key ) ) {
			return;
		}

		/**
		 * MainWP Left Menu.
		 *
		 * @global object $mainwp_sub_leftmenu
		 */
		global $mainwp_sub_leftmenu;

		$submenu_items = $mainwp_sub_leftmenu[ $parent_key ];

		if ( ! is_array( $submenu_items ) || count( $submenu_items ) == 0 ) {
			return;
		}

		global $plugin_page;

		foreach ( $submenu_items as $sub_key => $sub_item ) {
			$title = $sub_item[0];
			$href  = $sub_item[1];
			$right = $sub_item[2];
			$id    = isset( $sub_item[3] ) ? $sub_item[3] : '';
			$slug  = isset( $sub_item[4] ) ? $sub_item[4] : '';

			$_blank = false;
			if ( '_blank' === $id ) {
				$_blank = true;
			}

			$level2_active = self::is_level2_menu_item_active( $href ) ? true : false;

			$right_group = 'dashboard';
			if ( ! empty( $right ) ) {
				if ( strpos( $right, 'extension_' ) === 0 ) {
					$right_group = 'extension';
					$right       = str_replace( 'extension_', '', $right );
				}
			}
			if ( empty( $right ) || ( ! empty( $right ) && mainwp_current_user_have_right( $right_group, $right ) ) ) {
				?>
				<a class="item <?php echo $level2_active ? 'active level-two-active' : ''; ?>" href="<?php echo esc_url( $href ); ?>" id="<?php echo esc_attr( $slug ); ?>" <?php echo $_blank ? 'target="_blank"' : ''; ?>>
					<?php echo esc_html( $title ); ?>
				</a>
				<?php
			}
		}
	}


	/**
	 * Method is_level2_menu_item_active().
	 *
	 * Check if menu item level 2 is active.
	 *
	 * @param mixed $href The href value.
	 */
	public static function is_level2_menu_item_active( $href ) {
		$current_path = $_SERVER['REQUEST_URI'];
		$san_path     = $current_path;

		if ( 0 === stripos( $san_path, '/wp-admin/' ) ) {
			$san_path = str_replace( '/wp-admin/', '', $san_path );
		}

		$orther = '';
		if ( 0 === stripos( $san_path, $href ) ) {
			$orther = str_replace( $href, '', $san_path );
		}

		if ( ! empty( $orther ) && '&' === substr( $orther, 0, 1 ) ) { // cheat: start by &, it is addition params string.
			$san_path = str_replace( $orther, '', $san_path ); // remove other path of uri.
		}

		$san_path = MainWP_Utility::sanitize_attr_slug( $san_path );
		$san_href = MainWP_Utility::sanitize_attr_slug( $href );

		return $san_path === $san_href;
	}
}
