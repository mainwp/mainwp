<?php

class MainWP_UI {
	public static function select_sites_box( $title = '', $type = 'checkbox', $show_group = true, $show_select_all = true, $class = '', $style = '', &$selected_websites = array(), &$selected_groups = array(), $enableOfflineSites = false ) {
		?>
		<div class="mainwp_select_sites_box <?php if ( $class ) { echo esc_attr( $class ); } ?> mainwp_select_sites_wrapper" style="<?php if ( $style ) { echo esc_attr( $style ); } ?>">
			<div class="postbox">
				<h3 class="mainwp_box_title">
					<span>
						<i class="fa fa-globe"></i> <?php echo esc_html( ( $title ) ? $title : translate( 'Select sites', 'mainwp' ) ) ?>
						<div class="mainwp_sites_selectcount"><?php echo esc_html( ! is_array( $selected_websites ) ? '0' : count( $selected_websites ) ); ?></div>
					</span>
				</h3>
				<div class="inside mainwp_inside">
					<?php self::select_sites_box_body( $selected_websites, $selected_groups, $type, $show_group, $show_select_all, false, $enableOfflineSites ); ?>
				</div>
			</div>
		</div>
		<?php
	}


	public static function select_sites_box_body( &$selected_websites = array(), &$selected_groups = array(), $type = 'checkbox', $show_group = true, $show_select_all = true, $updateQty = false, $enableOfflineSites = false ) {
		$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser() );
		$groups   = MainWP_DB::Instance()->getNotEmptyGroups( null, $enableOfflineSites );
		?>
        <input type="hidden" name="select_by" id="select_by" value="<?php echo esc_attr( count( $selected_groups ) > 0 ? 'group' : 'site' ); ?>"/>
		<?php if ( $show_select_all ) :  ?>
			<div style="float:right"><?php esc_html_e( 'Select: ', 'mainwp' ); ?>
				<a href="#" onClick="return mainwp_ss_select(this, true)"><?php esc_html_e( 'All', 'mainwp' ); ?></a>
				| <a href="#" onClick="return mainwp_ss_select(this, false)"><?php esc_html_e( 'None', 'mainwp' ); ?></a>
			</div>
		<?php endif ?>
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
		<?php endif ?>
		<div id="selected_sites" <?php echo esc_html( count( $selected_groups ) > 0 ? 'style="display: none;"' : '' ); ?>>
			<?php
			if ( ! $websites ) {
				echo '<p>' . esc_html( 'No websites have been found.', 'mainwp' ) . '</p>';
			} else {
				while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
					$imgfavi = '';
					if ( $website !== null ) {
						if ( get_option( 'mainwp_use_favicon', 1 ) == 1 ) {
							$favi     = MainWP_DB::Instance()->getWebsiteOption( $website, 'favi_icon', '' );
							$favi_url = MainWP_Utility::get_favico_url( $favi, $website );
							$imgfavi  = '<img src="' . $favi_url . '" width="16" height="16" style="vertical-align:middle;"/>&nbsp;';
						}
					}

					if ( $website->sync_errors == '' || $enableOfflineSites ) {
						$selected = ( $selected_websites == 'all' || in_array( $website->id, $selected_websites ) );

						echo '<div title="'. $website->url .'" class="mainwp_selected_sites_item ' . ( $selected ? 'selected_sites_item_checked' : '' ) . '"><input onClick="mainwp_site_select(this)" type="' . $type . '" name="' . ( $type == 'radio' ? 'selected_site' : 'selected_sites[]' ) . '" siteid="' . $website->id . '" value="' . $website->id . '" id="selected_sites_' . $website->id . '" ' . ( $selected ? 'checked="true"' : '' ) . '/> <label for="selected_sites_' . $website->id . '">' . $imgfavi . stripslashes($website->name) . '<span class="url">' . $website->url . '</span>' . '</label></div>';
					}
					else
					{
						echo '<div title="'. $website->url . '" class="mainwp_selected_sites_item disabled"><input type="' . $type . '" disabled=disabled /> <label for="selected_sites_' . $website->id . '">' . $imgfavi . stripslashes($website->name) . '<span class="url">' . $website->url . '</span>' . '</label></div>';
					}
				}
				@MainWP_DB::free_result( $websites );
			}
			?>
		</div>
		<input id="selected_sites-filter" style="margin-top: .5em" type="text" value="" placeholder="<?php esc_attr_e( 'Type here to filter sites', 'mainwp'); ?>" <?php echo esc_attr( count( $selected_groups ) > 0 ? 'style="display: none;"' : '' ); ?> />
		<?php if ( $show_group ) :  ?>
			<div id="selected_groups" <?php echo esc_html( count( $selected_groups ) > 0 ? 'style="display: block;"' : '' ); ?>>
				<?php
				if ( count( $groups ) == 0 ) {
					echo wp_kses_post( sprintf( '<p>%s</p>', __( 'No groups with entries have been found.', 'mainwp' ) ) );
				}
				foreach ( $groups as $group ) {
					$selected = in_array( $group->id, $selected_groups );

					echo '<div class="mainwp_selected_groups_item ' . ( $selected ? 'selected_groups_item_checked' : '' ) . '"><input onClick="mainwp_group_select(this)" type="' . $type . '" name="' . ( $type == 'radio' ? 'selected_group' : 'selected_groups[]' ) . '" value="' . $group->id . '" id="selected_groups_' . $group->id . '" ' . ( $selected ? 'checked="true"' : '' ) . '/> <label for="selected_groups_' . $group->id . '">' . stripslashes( $group->name ) . '</label></div>';
				}
				?>
            </div>
		<input id="selected_groups-filter" style="margin-top: .5em" type="text" value="" placeholder="<?php esc_attr_e( 'Type here to filter groups', 'mainwp' );?>" <?php echo esc_attr( count( $selected_groups ) > 0 ? 'style="display: block;"' : '' ); ?> />
		<?php endif ?>
		<?php
		if ( $updateQty ) {
			echo '<script>jQuery(document).ready(function () {jQuery(".mainwp_sites_selectcount").html(' . ( ! is_array( $selected_websites ) ? '0' : count( $selected_websites ) ) . ');});</script>';
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
		?>
        <div class="wrap">
			<a href="https://mainwp.com" id="mainwplogo" title="MainWP" target="_blank">
				<img src="<?php echo esc_attr( plugins_url( 'images/logo.png', dirname( __FILE__ ) ) ); ?>" height="50" alt="MainWP"/>
			</a>
			<img src="<?php echo esc_attr( $icon_url ); ?>" style="float: left; margin-right: 8px; margin-top: 7px ;" alt="<?php echo esc_attr( $title ); ?>" height="32"/>
			<h2><?php echo esc_html( $title ); ?></h2>
			<div style="clear: both;"></div><br/>
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
}



