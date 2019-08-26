<?php

class MainWP_Menu {

	public function __construct() {

		// use the hook mainwp_main_menu_disable_menu_items to disable menu items
		global $_mainwp_disable_menus_items;
		if ( $_mainwp_disable_menus_items === null ) {
			// init some disable menu items, default is false
			$_mainwp_disable_menus_items = array(
				// compatible with old hooks
				'level_1'	 => array(
					'not_set_this_level' => true
					//'mainwp_tab' => false, // not hide this menu
					//'Extensions'		 => false,
					//'childsites_menu'	 => false,
				),
				'level_2'	 => array(
					//'mainwp_tab' => false,  // not hide this menu
					'UpdatesManage'		 => false,
					'managesites'		 => false,
					'PostBulkManage'	 => false,
					'PageBulkManage'	 => false,
					'ThemesManage'		 => false,
					'PluginsManage'		 => false,
					'UserBulkManage'	 => false,
					'ManageBackups'		 => false,
					'Settings'			 => false,
					'Extensions'		 => false,
					'ServerInformation'	 => false,
				),
				// compatible with old hooks
				'level_3'	 => array(
				)
			);
		}
	}

	public static function init_subpages_left_menu( $subPages, &$initSubpage, $parentKey, $slug ) {
		if ( !is_array( $subPages ) ) {
			return;
		}
		foreach ( $subPages as $subPage ) {
			if ( !isset( $subPage[ 'menu_hidden' ] ) || (isset( $subPage[ 'menu_hidden' ] ) && $subPage[ 'menu_hidden' ] != true) ) {
				$_item = array(
					'title'		 => $subPage[ 'title' ],
					'parent_key' => $parentKey,
					'href'		 => 'admin.php?page=' . $slug . $subPage[ 'slug' ],
					'slug'		 => $slug . $subPage[ 'slug' ],
					'right'		 => ''
				);

                // to support check right to open menu for sometime
				if ( isset( $subPage[ 'item_slug' ] ) ) {
					$_item[ 'item_slug' ] = $subPage[ 'item_slug' ];
				}

				$initSubpage[] = $_item;
			}
		}
	}

	public static function is_disable_menu_item( $level, $item ) {

		global $_mainwp_disable_menus_items;
		$_level = 'level_' . $level;
		if ( is_array( $_mainwp_disable_menus_items ) && isset( $_mainwp_disable_menus_items[ $_level ] ) && isset( $_mainwp_disable_menus_items[ $_level ][ $item ] ) ) {
			if ( $_mainwp_disable_menus_items[ $_level ][ $item ] )
				return true;
			else
				return false;
		}
		$_mainwp_disable_menus_items[ $_level ][ $item ] = false;
		return false;
	}

	public static function add_left_menu( $params = array(), $level = 1 ) {

		if ( empty( $params ) )
			return;

		if ($level != 1 && $level != 2)
			$level = 1;

		$title = $params[ 'title' ];

		if ( 1 === $level ) {
			$parent_key = 'mainwp_tab'; // forced value
		} else {
			if ( isset( $params[ 'parent_key' ] ) )
				$parent_key	 = $params[ 'parent_key' ];
			else
				$parent_key	 = 'mainwp_tab'; // forced value
		}

		$slug		 = $params[ 'slug' ];
		$href		 = $params[ 'href' ];
		$right		 = isset( $params[ 'right' ] ) ? $params[ 'right' ] : '';
        $id		 = isset( $params[ 'id' ] ) ? $params[ 'id' ] : '';

		global $mainwp_leftmenu, $mainwp_sub_leftmenu, $_mainwp_menu_active_slugs;

        $title = esc_html($title);

		if ( 1 == $level ) {
			$mainwp_leftmenu[ $parent_key ][]	 = array( $title, $slug, $href, $id );
			if ( !empty( $slug ) )
				$_mainwp_menu_active_slugs[ $slug ]	 = $slug; // to get active menu
		} else if ( 2 == $level ) {
			$mainwp_sub_leftmenu[ $parent_key ][]	 = array( $title, $href, $right, $id );
			if ( !empty( $slug ) )
				$_mainwp_menu_active_slugs[ $slug ]		 = $parent_key; // to get active menu
		}
	}

	public static function render_left_menu() {

		global $mainwp_leftmenu, $mainwp_sub_leftmenu, $_mainwp_menu_active_slugs, $plugin_page;

		$mainwp_leftmenu	 = apply_filters( 'mainwp_main_menu', $mainwp_leftmenu );
		$mainwp_sub_leftmenu = apply_filters( 'mainwp_main_menu_submenu', $mainwp_sub_leftmenu );

		$mainwp_leftmenu = isset( $mainwp_leftmenu['mainwp_tab'] ) ? $mainwp_leftmenu['mainwp_tab'] : array();

		?>
		<div class="mainwp-nav-wrap">


			<div id="mainwp-logo">
				<img src="<?php echo MAINWP_PLUGIN_URL . 'assests/images/logo.png'; ?>" alt="MainWP"/>
			</div>
			<div class="ui hidden divider"></div>
			<div class="mainwp-nav-menu">
				<?php do_action( 'before_mainwp_menu' ); ?>
				<div id="mainwp-main-menu"  class="ui inverted vertical accordion menu stackable">
					<?php
					if ( is_array( $mainwp_leftmenu ) && !empty( $mainwp_leftmenu ) ) {
						foreach ( $mainwp_leftmenu as $item ) {
							$title		 = wptexturize( $item[ 0 ] );
							$item_key	 = $item[ 1 ];
							$href		 = $item[ 2 ];
                            $item_id     = isset($item[ 3 ]) ? $item[ 3 ] : '';

							$has_sub = true;
							if ( !isset( $mainwp_sub_leftmenu[ $item_key ] ) || empty( $mainwp_sub_leftmenu[ $item_key ] ) ) {
								$has_sub = false;
							}
							$active_item = '';
							$set_actived = false;
							// to fix active menu
							if ( !$set_actived ) {
								if ( isset( $_mainwp_menu_active_slugs[ $plugin_page ] ) ) {
									if ( $item_key == $_mainwp_menu_active_slugs[ $plugin_page ] ) {
										$active_item = 'active';
										$set_actived = true;
									}
								}
							}

                            $id_attr = !empty($item_id) ? 'id="' . esc_html($item_id) . '"': '';

							if ( $has_sub ) {
								echo "<div " . $id_attr . " class=\"item $active_item\">";
								echo "<a class=\"title with-sub $active_item\" href=\"$href\"><b>$title</b> <i class=\"dropdown icon\"></i></a>";
								echo "<div class=\"content menu $active_item\">";
								self::render_sub_item( $item_key );
								echo "</div>";
								echo "</div>";
							} else {
								echo '<div ' . $id_attr . ' class="item">';
								echo "<a class='title $active_item' href=\"$href\"><b>$title</b></a>";
								echo "</div>";
							}
						}
					} else {
						echo "\n\t<div class='item'>";
						echo "</div>";
					}

                    $go_back_wpadmin_url = admin_url( 'index.php' );

                    $link = array(
                        'url' => $go_back_wpadmin_url,
                        'text' => __('Go to WP Admin', 'mainwp'),
                        'tip' => __( 'Click to go back to the site WP Admin area.', 'mainwp' )
                    );

                    $go_back_link = apply_filters('mainwp_go_back_wpadmin_link' , $link );
                    if (is_array($go_back_link)) {
                        if (isset($go_back_link['url'])) {
                            $link['url'] = $go_back_link['url'];
                        }
                        if (isset($go_back_link['text'])) {
                            $link['text'] = $go_back_link['text'];
                        }
                        if (isset($go_back_link['tip'])) {
                            $link['tip'] = $go_back_link['tip'];
                        }
                    }

					?>
					 <div class="item item-wp-admin" style="background-color: rgba(255,255,255,.15);">
						 <a href="<?php echo esc_html($link['url']); ?>" class="title" style="display:inline" data-position="top left" data-tooltip="<?php echo esc_html($link['tip']); ?>"><b><i class="wordpress icon"></i> <?php echo esc_html($link['text']); ?></b></a> <a class="ui small label" data-position="top right" data-tooltip="<?php _e( 'Logout', 'mainwp' ); ?>" href="<?php echo site_url(); ?>/wp-login.php?loggedout=true"><i class="sign-out icon" style="margin:0"></i></a>
					 </div>
                    <div class="hamburger">
					    <span class="hamburger-bun"></span>
					    <span class="hamburger-patty"></span>
					    <span class="hamburger-bun"></span>
					 </div>
				 </div>
					<?php do_action( 'after_mainwp_menu' ); ?>
			</div>
		</div>
			<script type="text/javascript">
				jQuery( document ).ready( function () {
                    // click on menu with-sub icon
					jQuery( '.mainwp-nav-menu a.title.with-sub .icon' ).on( "click", function ( event ) {
						var pr = jQuery( this ).closest( '.item' );
						var title = jQuery( this ).closest( '.title' );
						var active = jQuery( title ).hasClass( 'active' );

						// remove current active
						mainwp_menu_collapse();

                        // if current menu item are not active then set it active
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

						// remove current active
						mainwp_menu_collapse();

						// set active before go to the page
						if ( !active ) {
							jQuery( this ).addClass( 'active' );
							jQuery( pr ).find('.content.menu').addClass( 'active' );
							pr.addClass('active');
						}
					} );

					mainwp_menu_collapse = function() {
						// remove current active
						jQuery( '.mainwp-nav-menu a.title.active').removeClass('active');
						jQuery( '.mainwp-nav-menu .menu .item').removeClass('active');
						jQuery( '.mainwp-nav-menu .content.menu.active').removeClass('active');
					};
				} );
			</script>
		<?php
	}

	public static function render_sub_item( $parent_key ) {
		if ( empty( $parent_key ) )
			return;
		global $mainwp_sub_leftmenu;
		$submenu_items = $mainwp_sub_leftmenu[ $parent_key ];

		if ( !is_array( $submenu_items ) || count( $submenu_items ) == 0 )
			return;

		foreach ( $submenu_items as $sub_key => $sub_item ) {
			$title		 = $sub_item[ 0 ];
			$href		 = $sub_item[ 1 ];
			$right		 = $sub_item[ 2 ];
			$right_group = 'dashboard';
			if ( !empty( $right ) ) {
				if ( strpos( $right, 'extension_' ) === 0 ) {
					$right_group = 'extension';
					$right		 = str_replace( 'extension_', '', $right );
				}
			}
			if ( empty( $right ) || (!empty( $right ) && mainwp_current_user_can( $right_group, $right ) ) ) {
				?>
            <a class="item" href="<?php echo esc_url($href); ?>"><?php echo esc_html($title); ?></a>
				<?php
			}
		}

	}

}
