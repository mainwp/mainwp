<?php

class MainWP_UI {
	public static function select_sites_box( $title = '', $type = 'checkbox', $show_group = true, $show_select_all = true, $class = '', $style = '', &$selected_websites = array(), &$selected_groups = array(), $enableOfflineSites = false ) {
		?>
		<div class="mainwp_select_sites_box <?php if ( $class ) { echo esc_attr( $class ); } ?> mainwp_select_sites_wrapper" style="<?php if ( $style ) { echo esc_attr( $style ); } ?>">
			<div id="mainwp-select-sites-postbox" class="postbox">
				<h3 class="mainwp_box_title">
					<span>
						<i class="fa fa-globe"></i> <?php echo esc_html( ( $title ) ? $title : translate( 'Select sites', 'mainwp' ) ) ?>
						<div class="mainwp_sites_selectcount mainwp-right"><?php echo esc_html( ! is_array( $selected_websites ) ? '0' : count( $selected_websites ) ); ?></div>
					</span>
				</h3>
				<div class="inside">
					<?php self::select_sites_box_body( $selected_websites, $selected_groups, $type, $show_group, $show_select_all, false, $enableOfflineSites ); ?>
				</div>
			</div>
		</div>
		<?php
	}


	public static function select_sites_box_body( &$selected_websites = array(), &$selected_groups = array(), $type = 'checkbox', $show_group = true, $show_select_all = true, $updateQty = false, $enableOfflineSites = false, $postId = 0 ) {
		$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser() );
		$groups   = MainWP_DB::Instance()->getNotEmptyGroups( null, $enableOfflineSites );

        $edit_site_id = null;
        if ( $postId ) {
            $edit_site_id = get_post_meta( $postId, '_mainwp_edit_post_site_id', true );
            if ( empty( $edit_site_id ) ) {
	            $edit_site_id = null;
            }
        }

        $fix_style = '';
        if ( null !== $edit_site_id ) {
            $show_group = false;
            $fix_style = '<br/>';
        }
        ?>
		<div class="mainwp-postbox-actions-top">
			<input type="hidden" name="select_by" id="select_by" value="<?php echo esc_attr( count( $selected_groups ) > 0 ? 'group' : 'site' ); ?>"/>
			<?php if ( $show_select_all ) :  ?>
				<div class="mainwp-right"><?php esc_html_e( 'Select: ', 'mainwp' ); ?>
					<a href="#" onClick="return mainwp_ss_select(this, true)"><?php esc_html_e( 'All', 'mainwp' ); ?></a> |
					<a href="#" onClick="return mainwp_ss_select(this, false)"><?php esc_html_e( 'None', 'mainwp' ); ?></a>
				</div>
			<?php endif; ?>
			<?php if ( $show_group ) :  ?>
				<div id="mainwp_ss_site_link" <?php echo esc_html( count( $selected_groups ) > 0 ? 'style="display: inline-block;"' : '' ); ?>>
					<a href="#" onClick="return mainwp_ss_select_by(this, 'site')"><?php esc_html_e( 'By site', 'mainwp' ); ?></a>
				</div>
				<div id="mainwp_ss_site_text" <?php echo esc_html( count( $selected_groups ) > 0 ? 'style="display: none;"' : '' ); ?>>
					<?php esc_html_e( 'By site', 'mainwp' ); ?></div> |
				<div id="mainwp_ss_group_link" <?php echo esc_html( count( $selected_groups ) > 0 ? 'style="display: none;"' : '' ); ?>>
					<a href="#" onClick="return mainwp_ss_select_by(this, 'group')"><?php esc_html_e( 'By group', 'mainwp' ); ?></a>
				</div>
				<div id="mainwp_ss_group_text" <?php echo esc_html( count( $selected_groups ) > 0 ? 'style="display: inline-block;"' : '' ); ?>>
					<?php esc_html_e( 'By group', 'mainwp' ); ?>
				</div>
			<?php endif; ?>
            <?php echo $fix_style; ?>
		</div>
		<div id="selected_sites" <?php echo esc_html( count( $selected_groups ) > 0 ? 'style="display: none;"' : '' ); ?>>
			<?php
			if ( ! $websites ) {
				echo '<p class="mainwp-padding-5">' . esc_html( 'No websites have been found.', 'mainwp' ) . '</p>';
			} else {
				while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
					$imgfavi = '';
					if ( $website !== null ) {
						if ( get_option( 'mainwp_use_favicon', 1 ) == 1 ) {
							$favi_url = MainWP_Utility::get_favico_url( $website );
							$imgfavi  = '<img src="' . $favi_url . '" width="16" height="16" style="vertical-align:middle;"/>&nbsp;';
						}
					}

					if ( $website->sync_errors == '' || $enableOfflineSites ) {
						$selected = ( $selected_websites == 'all' || in_array( $website->id, $selected_websites ) );
                        $disabled = '';
                        if ( null !== $edit_site_id ) {
                            if ( $website->id != $edit_site_id ) {
                                $disabled = 'disabled="disabled"';
                            }
                        }
						echo '<div title="'. $website->url .'" class="mainwp_selected_sites_item mainwp-padding-5 ' . ( $selected ? 'selected_sites_item_checked' : '' ) . '"><input onClick="mainwp_site_select(this)" ' . $disabled .' type="' . $type . '" name="' . ( $type == 'radio' ? 'selected_site' : 'selected_sites[]' ) . '" siteid="' . $website->id . '" value="' . $website->id . '" id="selected_sites_' . $website->id . '" ' . ( $selected ? 'checked="true"' : '' ) . '/> <label for="selected_sites_' . $website->id . '">' . $imgfavi . stripslashes($website->name) . '<span class="url">' . $website->url . '</span>' . '</label></div>';
					}
					else
					{
						echo '<div title="'. $website->url . '" class="mainwp_selected_sites_item mainwp-padding-5 disabled"><input type="' . $type . '" disabled="disabled" /> <label for="selected_sites_' . $website->id . '">' . $imgfavi . stripslashes($website->name) . '<span class="url">' . $website->url . '</span>' . '</label></div>';
					}
				}
				@MainWP_DB::free_result( $websites );
			}
			?>
		</div>

		<?php if ( $show_group ) :  ?>
			<div id="selected_groups" <?php echo esc_html( count( $selected_groups ) > 0 ? 'style="display: block;"' : '' ); ?>>
				<?php
				if ( count( $groups ) == 0 ) {
					echo wp_kses_post( sprintf( '<p class="mainwp-padding-5">%s</p>', __( 'No groups with entries have been found.', 'mainwp' ) ) );
				}
				foreach ( $groups as $group ) {
					$selected = in_array( $group->id, $selected_groups );

					echo '<div class="mainwp_selected_groups_item mainwp-padding-5' . ( $selected ? 'selected_groups_item_checked' : '' ) . '"><input onClick="mainwp_group_select(this)" type="' . $type . '" name="' . ( $type == 'radio' ? 'selected_group' : 'selected_groups[]' ) . '" value="' . $group->id . '" id="selected_groups_' . $group->id . '" ' . ( $selected ? 'checked="true"' : '' ) . '/> <label for="selected_groups_' . $group->id . '">' . stripslashes( $group->name ) . '</label></div>';
				}
				?>
			</div>
		<?php endif; ?>
		<div class="mainwp-postbox-actions-bottom">
			<input id="selected_sites-filter" type="text" value="" placeholder="<?php esc_attr_e( 'Type here to filter sites', 'mainwp'); ?>" <?php echo esc_attr( count( $selected_groups ) > 0 ? 'style="display: none;"' : '' ); ?> />
			<?php if ( $show_group ) :  ?>
				<input id="selected_groups-filter" type="text" value="" placeholder="<?php esc_attr_e( 'Type here to filter groups', 'mainwp' );?>" <?php echo esc_attr( count( $selected_groups ) > 0 ? 'style="display: block;"' : '' ); ?> />
			<?php endif; ?>
		</div>
		<?php
		if ( $updateQty ) {
			echo '<script>jQuery(document).ready(function () {jQuery(".mainwp_sites_selectcount").html(' . ( ! is_array( $selected_websites ) ? '0' : count( $selected_websites ) ) . ');});</script>';
		}
        if ( null !== $edit_site_id ) {
            ?>
                <script>
                    jQuery(document).ready(function () {
                        var edit_site_el = jQuery('#selected_sites_<?php echo $edit_site_id; ?>');
                        mainwp_site_select(edit_site_el);
                    });
                </script>
            <?php
        }
	}

	public static function select_categories_box( $params ) {
		$title         = $params['title'];
		$type          = isset( $params['type'] ) ? $params['type'] : 'checkbox';
		$show_group    = isset( $params['show_group'] ) ? $params['show_group'] : true;
		$selected_by   = ! empty( $params['selected_by'] ) ? $params['selected_by'] : 'site';
		$class         = isset( $params['class'] ) ? $params['class'] : '';
		$style         = isset( $params['style'] ) ? $params['style'] : '';
		$selected_cats = is_array( $params['selected_cats'] ) ? $params['selected_cats'] : array();
		$prefix        = $params['prefix'];
		if ( $type == 'checkbox' ) {
			$cbox_prefix = '[]';
		}

		$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser() );
		$groups   = MainWP_DB::Instance()->getNotEmptyGroups();
		?>
		<div class="mainwp_select_sites_box mainwp_select_categories <?php if ( $class ) { echo esc_attr( $class ); } ?> mainwp_select_sites_wrapper" style="<?php if ( $style ) { echo esc_attr( $style ); } ?>">
			<div class="postbox">
				<h3 class="box_title mainwp_box_title"><?php echo esc_html( ( $title ) ? $title : __( 'Select categories', 'mainwp' ) ) ?></h3>
				<div class="inside mainwp_inside ">
					<input type="hidden" name="select_by_<?php echo esc_attr( $prefix ); ?>" class="select_by" value="<?php echo esc_attr( $selected_by ) ?>"/>
					<?php if ( $show_group ) :  ?>
						<div class="mainwp_ss_site_link" <?php echo esc_html( $selected_by == 'group' ? 'style="display: inline-block;"' : '' ); ?>>
							<a href="#" onClick="return mainwp_ss_cats_select_by(this, 'site')"><?php esc_html_e( 'By site', 'mainwp' ); ?></a>
						</div>
						<div class="mainwp_ss_site_text" <?php echo esc_html( $selected_by == 'group' ? 'style="display: none;"' : '' ); ?>><?php esc_html( 'By site', 'mainwp' ); ?></div> |
						<div class="mainwp_ss_group_link" <?php echo esc_html( $selected_by == 'group' ? 'style="display: none;"' : '' ); ?>>
							<a href="#" onClick="return mainwp_ss_cats_select_by(this, 'group')"><?php esc_html_e( 'By group', 'mainwp' ); ?></a>
						</div>
						<div class="mainwp_ss_group_text" <?php echo esc_html( $selected_by == 'group' ? 'style="display: inline-block;"' : '' ); ?>><?php esc_html_e( 'By group', 'mainwp' ); ?></div>
					<?php endif ?>
					<div class="selected_sites" <?php echo esc_html( $selected_by == 'group' ? 'style = "display: none"' : '' ); ?>>
						<?php
						if ( ! $websites ) {
							echo wp_kses_post( sprintf( '<p>%s</p>', __( 'No websites have been found.', 'mainwp' ) ) );
						} else {
							while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
								$cats = isset( $selected_cats[ $website->id ] ) && is_array( $selected_cats[ $website->id ] ) ? $selected_cats[ $website->id ] : array();
								?>
								<div class="categories_site_<?php echo esc_attr( $website->id ); ?>">
									<div class="categories_list_header">
										<div><?php echo esc_html( stripslashes( $website->name ) ) ?></div>
										<label><span class="url"><?php echo esc_html( $website->url ) ?></span></label>
									</div>
									<div class="categories_list_<?php echo esc_attr( $website->id ); ?>">
										<?php
										if ( count( $cats ) == 0 ) {
											echo wp_kses_post( sprintf( '<p>%s</p>', __( 'No selected categories.', 'mainwp' ) ) );
										} else {
											foreach ( $cats as $cat ) {
												echo wp_kses_post(
													'<div class="mainwp_selected_sites_item  selected_sites_item_checked">
												<input type="' . $type . '" name="sites_selected_cats_' . $prefix . $cbox_prefix . '" value="' . $website->id . ',' . $cat['term_id'] . ',' . $cat['name'] . '" id="sites_selected_cats_' . $prefix . $cat['term_id'] . '" checked="true" />
												<label>' . $cat['name'] . '</label>
										    </div>'
												);
											}
										}
										?>
									</div>
									<div class="mainwp_categories_list_bottom">
										<div style="float:right">
											<a href="#" rel="<?php echo esc_attr( $prefix ) ?>" class="load_more_cats" onClick="return mainwp_ss_cats_more(this, <?php echo esc_attr( $website->id ); ?>, 'site')">
												<?php esc_html_e( 'Reload', 'mainwp' ); ?>
											</a>
										<span class="mainwp_more_loading">
											<i class="fa fa-spinner fa-pulse"></i>
										</span>
										</div>
										<div class="clearfix"></div>
									</div>
								</div>
								<?php
							}
							@MainWP_DB::free_result( $websites );
						}
						?>
					</div>
					<div class="selected_groups" <?php echo esc_attr( $selected_by == 'group' ? 'style = "display: block"' : '' ); ?>>
						<?php
						if ( count( $groups ) == 0 ) {
							echo wp_kses_post( sprintf( '<p>%s</p>', __( 'No groups with entries have been found.', 'mainwp' ) ) );
						}
						foreach ( $groups as $gid => $group ) {
							?>
							<div class="categories_group_<?php echo esc_attr( $gid ); ?>">
								<div class="mainwp_groups_list_header">
									<div><?php echo stripslashes( $group->name ); ?></div>
								</div>
								<?php
								$websites = MainWP_DB::Instance()->getWebsitesByGroupIds( array( $gid ) );
								foreach ( $websites as $website ) {
									$id   = $website->id;
									$cats = ( isset( $selected_cats[ $id ] ) && is_array( $selected_cats[ $id ] ) ) ? $selected_cats[ $id ] : array();
									?>
									<div class="categories_site_<?php echo esc_attr( $id ); ?>">
										<div class="categories_list_header">
											<div><?php echo esc_html( stripslashes( $website->name ) ); ?></div>
											<label><span class="url"><?php echo esc_html( $website->url ) ?></span></label>
										</div>
										<div class="categories_list_<?php echo $id; ?>">
											<?php
											if ( count( $cats ) == 0 ) {
												echo wp_kses_post( sprintf( '<p>%s</p>', __( 'No selected categories.', 'mainwp' ) ) );
											} else {
												foreach ( $cats as $cat ) {
													?>
													<div class="mainwp_selected_sites_item  selected_sites_item_checked">
														<input type="<?php echo esc_attr( $type ) ?>" name="groups_selected_cats_<?php echo esc_attr( $prefix . $cbox_prefix ) ?>" value="<?php echo esc_attr( $id . ',' . $cat['term_id'] . ',' . $cat['name'] ) ?>" id="groups_selected_cats_<?php echo esc_attr( $prefix . $cat['term_id'] ) ?>" checked="true" />
														<label><?php echo esc_html( $cat['name'] ) ?></label>
													</div>
													<?php
												}
											}
											?>
										</div>
										<div class="mainwp_categories_list_bottom">
											<div style="float:right">
												<a href="#" rel="<?php echo esc_attr( $prefix ) ?>" class="load_more_cats" onClick="return mainwp_ss_cats_more(this, <?php echo esc_attr( $id ); ?>, 'group')">Reload</a>
												<span class="mainwp_more_loading"><i class="fa fa-spinner fa-pulse"></i></span>
											</div>
											<div class="clearfix"></div>
										</div>
									</div>
									<?php
								}
								?>
							</div>
							<?php
						}
						?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	public static function submit_box( $title = '', $button = '', $name = '', $id = '', $class = '', $style = '' ) {
		?>
		<div class="mainwp_submit_box <?php if ( $class ) { echo esc_attr( $class ); } ?>" style="<?php if ( $style ) { echo esc_attr( $style ); } ?>">
			<div class="postbox">
				<?php if ( $title ) :  ?>
					<h3 class="box_title mainwp_box_title"><?php echo esc_html( $title ) ?></h3>
				<?php endif ?>
				<div class="inside mainwp_inside">
					<input type="submit" name="<?php echo esc_attr( $name ) ?>" id="<?php echo esc_attr( $id ) ?>" class="button-primary" value="<?php echo esc_attr( $button ) ?>"/>
				</div>
			</div>
		</div>
		<?php
	}

	public static function separator() {
		?>
		<div style="clear: both"></div>
		<?php
	}

	public static function renderHeader( $title, $icon_url ) {
        MainWP_UI::render_left_menu();
		?>
		<div class="mainwp-wrap">
		<h2><?php echo esc_html( $title ); ?></h2>
		<div style="clear: both;"></div>
		<div class="clear"></div>
		<div class="wrap">
		<?php
	}

	public static function renderFooter() {
		?>
		</div>
		</div>
		<?php
	}

	public static function renderImage( $img, $alt, $class, $height = null ) {
		?>
		<img src="<?php echo esc_attr( plugins_url( $img, dirname( __FILE__ ) ) ); ?>" class="<?php echo esc_attr( $class ); ?>" alt="<?php echo esc_attr( $alt ); ?>" <?php echo esc_attr( $height == null ? '' : 'height="' . $height . '"' ); ?> />
		<?php
	}

    public static function render_left_menu( ) {
        if ( !get_option( 'mainwp_disable_wp_main_menu', 1 ) )
            return;
        global $mainwp_leftmenu, $mainwp_sub_leftmenu, $mainwp_sub_subleftmenu, $mainwp_menu_active_slugs, $plugin_page;

        $first = true;
        $values = get_option('mainwp_status_saved_values');
        $menuStatus = isset($values['status_leftmenu']) ? $values['status_leftmenu'] : array();

        ?>
        <div class="mainwp_leftmenu_wrap">
                <a href="admin.php?page=mainwp_tab" id="mainwpmenulogo" title="MainWP"><img src="<?php echo plugins_url( 'images/logo.png', dirname( __FILE__ ) ); ?>" height="50" alt="MainWP"/></a>
                <?php
                if ( MainWP_Utility::showMainWPMessage( 'notice', 'leftmenu_notice' ) ) {
                ?>
                    <div class="mainwp-notice-wrap mainwp-notice mainwp-notice-green" style="margin-top: 1em">
                        <?php echo sprintf( __('Welcome to the new MainWP Dashboard Navigation! If you prefer the old navigation you can turn this off in %sSettings%s.', 'mainwp'), '<a href="admin.php?page=DashboardOptions">', '</a>'); ?><br/><br/>
                        <div style="text-align: center;"><a class="mainwp-notice-dismiss button button-primary" notice-id="leftmenu_notice" style="text-decoration: none;" href="#"><?php esc_html_e( 'Dismiss this notice', 'mainwp' ); ?></a></div>
                    </div>
                    <?php
                }
                ?>
                <div class="mainwp_leftmenu_content">
                <ul>
                    <?php
                    $set_actived = false;
                    foreach($mainwp_leftmenu as $key => $item) {
                        $class = array();
                        $class[] = 'mainwp-menu-item';

                        if ( $first ) {
                                $class[] = 'mainwp-menu-first-item';
                                $first = false;
                        }
                        $title = wptexturize( $item[0] );
                        $item_key = $item[1];
                        $submenu_items = array();
                        if ( ! empty( $mainwp_sub_leftmenu[$item_key] ) ) {
                            $class[] = 'mainwp-menu-has-submenu';
                            $submenu_items = $mainwp_sub_leftmenu[$item_key];
                        }

                        $spinner = '';
                        if ( $item_key == 'childsites_menu' ) {
                            $class[] = 'menu-sites-wrap';
                            $spinner = ' <i class="fa fa-spinner fa-pulse" id="menu-sites-working" style="display:none"></i>';
                        }

                        $arrow = '<span class="handlediv"></span>';

                        if (!isset($menuStatus[$item_key]) || empty($menuStatus[$item_key]))
                            $class[] = 'closed';


                        $class = $class ? ' class="' . join( ' ', $class ) . '"' : '';
                        echo "\n\t<li$class item-key=\"$item_key\">";
                        echo "<div class='handle'><div class='mainwp-menu-name'>$title$spinner</div>$arrow</div>";
                        if ( ! empty( $submenu_items ) ) {
                            echo "\n\t<ul class='mainwp-menu-sub-wrap'>";
                            if ($item_key == 'childsites_menu') {
                                ?>
                                <li class="mainwp-menu-sub-item-filer">
                                    <select id="mainwp-leftmenu-group-filter" class="mainwp-select2-full allowclear" data-placeholder="<?php _e( 'Filter child Sites By Group', 'mainwp' ); ?>">
										<option value=""></option>
										<?php
										$groups = MainWP_DB::Instance()->getGroupsForCurrentUser();
										foreach ( $groups as $group ) {
											echo '<option value="' . $group->id . '">' . stripslashes( $group->name ) . '</option>';
										}
										?>
                                    </select>
                                   <div id="mainwp-sites-menu-filter">
                                           <input id="mainwp-lefmenu-sites-filter" style="width: 100%;" type="text" value="" placeholder="<?php esc_attr_e( 'Search for Child sites', 'mainwp' ); ?>" />
                                   </div>
                                </li>
                               <?php
                            }

                            foreach($submenu_items as $sub_item) {
                                $title = wptexturize($sub_item[0]);
                                $sub_key = $sub_item[1];
                                $href = $sub_item[2];
                                $icon = isset($sub_item[3]) && !empty($sub_item[3]) ? $sub_item[3] . ' ' : '';
                                //$desc = isset($sub_item[4]) && !empty($sub_item[4]) ? '<div class="mainwp-menu-item-desc">' . $sub_item[4] . '</div>' : '';

                                $arrow = '<span class="handlediv"></span>';
                                $site_id = '';
                                $has_sub = true;
                                if(!isset($mainwp_sub_subleftmenu[$sub_key]) || empty($mainwp_sub_subleftmenu[$sub_key])) {
                                    $arrow = '';
                                    $has_sub = false;
                                }
                                if ($item_key == 'childsites_menu') {
                                    $arrow = '';
                                    $site_id =  str_replace('child_site_', '', $sub_key);
                                    $site_id = 'site-id="' . $site_id .'"';
                                }
//                                $sub_closed = '';
//                                if (!isset($menuStatus[$item_key . '-' . $sub_key]) || empty($menuStatus[$item_key . '-' . $sub_key]))
                                    $sub_closed = 'closed';

                                $active_item = '';

                                // to fix active menu
                                if (!$set_actived) {
                                    $active_siteid = 0;
                                    if ($plugin_page == 'managesites') {
                                        if (!isset($_GET['do'])) {
                                            if (isset($_GET['dashboard'])) {
                                                $active_siteid = $_GET['dashboard'];
                                            } else if (isset($_GET['id'])) {
                                                $active_siteid = $_GET['id'];
                                            } else if (isset($_GET['updateid'])) {
                                                $active_siteid = $_GET['updateid'];
                                            } else if (isset($_GET['backupid'])) {
                                                $active_siteid = $_GET['backupid'];
                                            } else if (isset($_GET['scanid'])) {
                                                $active_siteid = $_GET['scanid'];
                                            } else if (isset($_GET['scanid'])) {
                                                $active_siteid = $_GET['scanid'];
                                            }
                                        }
                                    } else if (strpos($plugin_page, 'ManageSites') === 0) {
                                        if (isset($_GET['id'])) {
                                            $active_siteid = $_GET['id'];
                                        }
                                    }

                                    if ($active_siteid) {
                                        if($sub_key == 'child_site_' . $active_siteid) {
                                            $active_item = 'sidemenu-active';
                                            $set_actived = true;
                                        }
                                    } else if ( isset($mainwp_menu_active_slugs[$plugin_page])) {
                                        if ($sub_key == $mainwp_menu_active_slugs[$plugin_page]) {
                                            // to fix Add Extension menu item
                                            if ($plugin_page == 'Extensions') {
                                                if (($item_key == 'mainwp_tab' && !isset($_GET['leftmenu'])) || ($item_key == 'Extensions' && isset($_GET['leftmenu']))) {
                                                    $active_item = 'sidemenu-active';
                                                    $set_actived = true;
                                                }
                                            } else {
                                                $active_item = 'sidemenu-active';
                                                $set_actived = true;
                                            }
                                        }
                                    }
                                }

                                echo "<li class='mainwp-menu-sub-item $active_item $sub_closed " . (empty($icon) ? 'no-icon' : '') . ($has_sub ? ' mainwp-menu-has-submenu' : '') ."' $site_id item-key=\"$item_key-$sub_key\"><div class='mainwp-menu-name'>$icon<a href='{$href}'>$title</a></div>$arrow";
                                if ($has_sub) {
                                    self::render_sub_sub_left_menu($sub_key, $item_key );
                                }
                                echo "</li>";
                            }
                            echo "</ul>";
                        } else {
                            echo "\n\t<ul class='mainwp-menu-sub-wrap'>";
                            echo "<li class='mainwp-menu-sub-item closed'><div class='mainwp-menu-name'>&nbsp;</div></li>";
                            echo "</ul>";
                        }
                        echo "\n\t</li>";
                    }
                    ?>
                </ul>
            </div>
            <div class="mainwp-postbox-actions-bottom" id="mainwp-ui-leftmenu-footer">
            	<span class="mainwp-cols-4 mainwp-left"><a href="https://www.facebook.com/groups/MainWPUsers/" target="_blank" title="MainWP Users Facebook Group"><i class="fa fa-users fa-lg" aria-hidden="true" style="line-height: 1em !important;"></i></a></span>
            	<span class="mainwp-cols-4 mainwp-left"><a href="https://mainwp.com/support/" target="_blank" title="MainWP Support"><i class="fa fa-life-ring fa-lg" aria-hidden="true" style="line-height: 1em !important;"></i></a></span>
            	<span class="mainwp-cols-4 mainwp-left"><a href="https://mainwp.com/help/" target="_blank" title="MainWP Documentation"><i class="fa fa-book fa-lg" aria-hidden="true" style="line-height: 1em !important;"></i></a></span>
            	<span class="mainwp-cols-4 mainwp-left"><a href="https://mainwp.com/my-account/" target="_blank" title="My MainWP Account"><i class="fa fa-user-circle-o fa-lg" aria-hidden="true" style="line-height: 1em !important;"></i></a></span>
            	<div style="clear: both;"></div>
            </div>
        </div>
        <script type="text/javascript">
            jQuery(document).ready(function () {
                var _active_menu_item = jQuery('li.mainwp-menu-sub-item.sidemenu-active');
                var root_active_leftmenu = jQuery(_active_menu_item).closest('li.mainwp-menu-item');
                if (root_active_leftmenu.length > 0) {
                    jQuery( '.mainwp_leftmenu_content li.mainwp-menu-item' ).addClass('closed');
                    root_active_leftmenu.removeClass('closed');
                    //root_active_leftmenu.find('.handle .mainwp-menu-name').addClass('menuitem-active');
                    mainwp_leftmenu_change_status(root_active_leftmenu, true); // save open status
                }
                var sub_active_leftmenu = jQuery(_active_menu_item).closest('li.mainwp-menu-sub-item');
                if (jQuery(sub_active_leftmenu).hasClass('mainwp-menu-has-submenu')) {
                    sub_active_leftmenu.removeClass('closed');
                }
            });
        </script>
        <?php
}

    public static function render_sub_sub_left_menu($parent_key, $item_key = '') {
        if(empty($parent_key))
            return;
        global $mainwp_sub_subleftmenu;
        $submenu_items = $mainwp_sub_subleftmenu[$parent_key];

        if (!is_array($submenu_items) || count($submenu_items) == 0)
            return;

        if ($item_key == 'childsites_menu') {
            echo '<div class="leftmenu_sites_actions">';
             foreach($submenu_items as $sub_key => $sub_items ) {
                $title = $sub_items[0];
                $href = $sub_items[1];
                $right = $sub_items[2];
                if (empty($right) || (!empty($right) && mainwp_current_user_can( $right_group, $right ) )) {
                ?>
                    <a href="<?php echo $href; ?>"><?php echo $title; ?></a>
                <?php
                }
             }
             echo '</div>';
             echo '<div class="clear"></div>';
            return;
        }


        echo '<ul class="mainwp-menu-sub2-wrap">';
        foreach($submenu_items as $sub_key => $sub_items ) {
            $title = $sub_items[0];
            $href = $sub_items[1];
            $right = $sub_items[2];

            $right_group = 'dashboard';

            if (!empty($right)) {
               if (strpos($right, 'extension_') === 0) {
                  $right_group = 'extension';
                  $right = str_replace('extension_', '', $right);
               }
            }
            if (empty($right) || (!empty($right) && mainwp_current_user_can( $right_group, $right ) )) {
                ?>
                    <li class="mainwp-menu-sub2-item"><div class="mainwp-menu-name"><a href="<?php echo $href; ?>" class="mainwp-submenu"><?php echo $title; ?></a></div></li>
                <?php
            }
        }
        echo "</ul>";

    }
}