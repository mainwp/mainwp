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
	 * Method __construct()
	 *
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
	 */
	public static function init_mainwp_menus() {
		if ( MainWP_System_Utility::is_admin() ) {
			if ( ! self::is_disable_menu_item( 2, 'UpdatesManage' ) ) {
				MainWP_Updates::init_menu();
			}
			if ( ! self::is_disable_menu_item( 2, 'managesites' ) ) {
				MainWP_Manage_Sites::init_menu();
			}
			if ( ! self::is_disable_menu_item( 2, 'PostBulkManage' ) ) {
				MainWP_Post::init_menu();
			}
			if ( ! self::is_disable_menu_item( 2, 'PageBulkManage' ) ) {
				MainWP_Page::init_menu();
			}
			if ( ! self::is_disable_menu_item( 2, 'ThemesManage' ) ) {
				MainWP_Themes::init_menu();
			}
			if ( ! self::is_disable_menu_item( 2, 'PluginsManage' ) ) {
				MainWP_Plugins::init_menu();
			}
			if ( ! self::is_disable_menu_item( 2, 'UserBulkManage' ) ) {
				MainWP_User::init_menu();
			}
			if ( ! self::is_disable_menu_item( 2, 'ManageBackups' ) ) {
				MainWP_Manage_Backups::init_menu();
			}
			if ( ! self::is_disable_menu_item( 3, 'UpdateAdminPasswords' ) ) {
				MainWP_Bulk_Update_Admin_Passwords::init_menu();
			}
			if ( ! self::is_disable_menu_item( 3, 'ManageGroups' ) ) {
				MainWP_Manage_Groups::init_menu();
			}
			if ( ! self::is_disable_menu_item( 3, 'MonitoringSites' ) ) {
				MainWP_Monitoring::init_menu();
			}
			if ( ! self::is_disable_menu_item( 2, 'Settings' ) ) {
				MainWP_Settings::init_menu();
			}
			MainWP_Extensions::init_menu();

			/**
			 * Action: mainwp_admin_menu
			 *
			 * Hooks main navigation menu items.
			 *
			 * @since 4.0
			 */
			do_action( 'mainwp_admin_menu' );

			if ( ! self::is_disable_menu_item( 2, 'ServerInformation' ) ) {
				MainWP_Server_Information::init_menu();
			}
		}
	}

	/**
	 * Method init_subpages_menu()
	 *
	 * Init subpages MainWP menus.
	 */
	public static function init_subpages_menu() {

		if ( ! self::is_disable_menu_item( 2, 'PostBulkManage' ) ) {
			MainWP_Post::init_subpages_menu();
		}
		if ( ! self::is_disable_menu_item( 2, 'managesites' ) ) {
			MainWP_Manage_Sites::init_subpages_menu();
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
	 *
	 * @return array $initSubpage[] Final SubPages Array.
	 */
	public static function init_subpages_left_menu( $subPages, &$initSubpage, $parentKey, $slug ) {
		if ( ! is_array( $subPages ) ) {
			return;
		}
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

				$initSubpage[] = $_item;
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

		$_level = 'level_' . $level;
		if ( is_array( $_mainwp_disable_menus_items ) && isset( $_mainwp_disable_menus_items[ $_level ] ) && isset( $_mainwp_disable_menus_items[ $_level ][ $item ] ) ) {
			if ( $_mainwp_disable_menus_items[ $_level ][ $item ] ) {
				return true;
			} else {
				return false;
			}
		}
		$_mainwp_disable_menus_items[ $_level ][ $item ] = false;
		return false;
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

		if ( 1 != $level && 2 != $level ) {
			$level = 1;
		}

		$title = $params['title'];

		if ( 1 === $level ) {
			$parent_key = 'mainwp_tab'; // forced value.
		} else {
			if ( isset( $params['parent_key'] ) ) {
				$parent_key = $params['parent_key'];
			} else {
				$parent_key = 'mainwp_tab'; // forced value.
			}
		}

		$slug  = $params['slug'];
		$href  = $params['href'];
		$right = isset( $params['right'] ) ? $params['right'] : '';
		$id    = isset( $params['id'] ) ? $params['id'] : '';

		/**
		 * MainWP Left Menu, Sub Menu & Active menu slugs.
		 *
		 * @global object $mainwp_leftmenu
		 * @global object $mainwp_sub_leftmenu
		 * @global object $_mainwp_menu_active_slugs
		 */
		global $mainwp_leftmenu, $mainwp_sub_leftmenu, $_mainwp_menu_active_slugs;

		$title = esc_html( $title );

		if ( 1 == $level ) {
			$mainwp_leftmenu[ $parent_key ][] = array( $title, $slug, $href, $id );
			if ( ! empty( $slug ) ) {
				$_mainwp_menu_active_slugs[ $slug ] = $slug; // to get active menu.
			}
		} elseif ( 2 == $level ) {
			$mainwp_sub_leftmenu[ $parent_key ][] = array( $title, $href, $right, $id );
			if ( ! empty( $slug ) ) {
				$_mainwp_menu_active_slugs[ $slug ] = $parent_key; // to get active menu.
			}
		}
	}

	/**
	 * Method render_left_menu
	 *
	 * Build Top Level Main Menu HTML & Render.
	 */
	public static function render_left_menu() {

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

		/**
		 * Filter: mainwp_main_menu_submenu
		 *
		 * Filters main navigation subt-menu items
		 *
		 * @since 4.0
		 */
		$mainwp_sub_leftmenu = apply_filters( 'mainwp_main_menu_submenu', $mainwp_sub_leftmenu );

		$mainwp_leftmenu = isset( $mainwp_leftmenu['mainwp_tab'] ) ? $mainwp_leftmenu['mainwp_tab'] : array();

		?>
		<div class="mainwp-nav-wrap">
			<div id="mainwp-logo">
				<img src="
				<?php
				/**
				 * Filter: mainwp_menu_logo_src
				 *
				 * Filters the Logo src attribute.
				 *
				 * @since 4.1
				 */
				echo apply_filters( 'mainwp_menu_logo_src', MAINWP_PLUGIN_URL . 'assets/images/logo.png' );
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
				echo apply_filters( 'mainwp_menu_logo_alt', 'MainWP' );
				?>
				" />
			</div>
			<div class="ui hidden divider"></div>
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
				<div id="mainwp-main-menu"  class="ui inverted vertical accordion menu stackable">
					<?php
					if ( is_array( $mainwp_leftmenu ) && ! empty( $mainwp_leftmenu ) ) {
						foreach ( $mainwp_leftmenu as $item ) {
							$title    = wptexturize( $item[0] );
							$item_key = $item[1];
							$href     = $item[2];
							$item_id  = isset( $item[3] ) ? $item[3] : '';

							$has_sub = true;
							if ( ! isset( $mainwp_sub_leftmenu[ $item_key ] ) || empty( $mainwp_sub_leftmenu[ $item_key ] ) ) {
								$has_sub = false;
							}
							$active_item = '';
							$set_actived = false;
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

							if ( $has_sub ) {
								echo '<div ' . $id_attr . " class=\"item $active_item\">";
								echo "<a class=\"title with-sub $active_item\" href=\"$href\"><b>$title</b> <i class=\"dropdown icon\"></i></a>";
								echo "<div class=\"content menu $active_item\">";
								self::render_sub_item( $item_key );
								echo '</div>';
								echo '</div>';
							} else {
								echo '<div ' . $id_attr . ' class="item">';
								echo "<a class='title $active_item' href=\"$href\"><b>$title</b></a>";
								echo '</div>';
							}
						}
					} else {
						echo "\n\t<div class='item'>";
						echo '</div>';
					}

					$go_back_wpadmin_url = admin_url( 'index.php' );

					$link = array(
						'url'  => $go_back_wpadmin_url,
						'text' => __( 'Go to WP Admin', 'mainwp' ),
						'tip'  => __( 'Click to go back to the site WP Admin area.', 'mainwp' ),
					);

					/**
					 * Filter: mainwp_go_back_wpadmin_link
					 *
					 * Filters URL for the Go to WP Admin button in Main navigation.
					 *
					 * @since 4.0
					 */
					$go_back_link = apply_filters( 'mainwp_go_back_wpadmin_link', $link );

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
					<div class="item item-wp-admin" style="background-color: rgba(255,255,255,.15);">
						<a href="<?php echo esc_html( $link['url'] ); ?>" class="title" style="display:inline" data-position="top left" data-tooltip="<?php echo esc_html( $link['tip'] ); ?>"><b><i class="wordpress icon"></i> <?php echo esc_html( $link['text'] ); ?></b></a> <a class="ui small label" data-position="top right" data-tooltip="<?php esc_html_e( 'Logout', 'mainwp' ); ?>" href="<?php echo wp_logout_url(); ?>"><i class="sign-out icon" style="margin:0"></i></a> <?php //phpcs:ignore -- to avoid auto fix wordpress icon ?>
					</div>
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
			<script type="text/javascript">
				jQuery( document ).ready( function () {
					// click on menu with-sub icon.
					jQuery( '.mainwp-nav-menu a.title.with-sub .icon' ).on( "click", function ( event ) {
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

					jQuery( '.mainwp-nav-menu a.title.with-sub' ).on( "click", function ( event ) {
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
						jQuery( '.mainwp-nav-menu a.title.active').removeClass('active');
						jQuery( '.mainwp-nav-menu .menu .item').removeClass('active');
						jQuery( '.mainwp-nav-menu .content.menu.active').removeClass('active');
					};
				} );
			</script>
		<?php
	}

	/**
	 * Method render_sub_item
	 *
	 * Grab all submenu items and attatch to Main Menu.
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

		foreach ( $submenu_items as $sub_key => $sub_item ) {
			$title       = $sub_item[0];
			$href        = $sub_item[1];
			$right       = $sub_item[2];
			$right_group = 'dashboard';
			if ( ! empty( $right ) ) {
				if ( strpos( $right, 'extension_' ) === 0 ) {
					$right_group = 'extension';
					$right       = str_replace( 'extension_', '', $right );
				}
			}
			if ( empty( $right ) || ( ! empty( $right ) && mainwp_current_user_have_right( $right_group, $right ) ) ) {
				?>
			<a class="item" href="<?php echo esc_url( $href ); ?>"><?php echo esc_html( $title ); ?></a>
				<?php
			}
		}
	}

}
