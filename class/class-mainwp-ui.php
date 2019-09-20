<?php

class MainWP_UI {

	public static function select_sites_box( $type = 'checkbox', $show_group = true, $show_select_all = true, $class = '', $style = '', &$selected_websites = array(), &$selected_groups = array(), $enableOfflineSites = false, $postId = 0 ) {

        if ( $postId ) {
            $selected_websites = unserialize( base64_decode( get_post_meta( $postId, '_selected_sites', true ) ) );
            if ( $selected_websites == '' ) {
                $selected_websites = array();
            }

            $selected_groups = unserialize( base64_decode( get_post_meta( $postId, '_selected_groups', true ) ) );
            if ( $selected_groups == '' ) {
                $selected_groups = array();
            }
        }

		?>
		<div id="mainwp-select-sites" class="mainwp_select_sites_wrapper">
      <?php self::select_sites_box_body( $selected_websites, $selected_groups, $type, $show_group, $show_select_all, false, $enableOfflineSites, $postId ); ?>
    </div>
		<?php
	}

	public static function select_sites_box_body( &$selected_websites = array(), &$selected_groups = array(), $type = 'checkbox', $show_group = true, $show_select_all = true, $updateQty = false, $enableOfflineSites = false, $postId = 0 ) {

        if ( $selected_websites != 'all' && !is_array( $selected_websites ) ) {
			$selected_websites = array();
		}

        if ( !is_array( $selected_groups ) ) {
			$selected_groups = array();
		}

		$websites	 = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser() );
		$groups		 = MainWP_DB::Instance()->getNotEmptyGroups( null, $enableOfflineSites );

		// support staging extension
        $staging_enabled = apply_filters( 'mainwp-extension-available-check', 'mainwp-staging-extension' ) || apply_filters( 'mainwp-extension-available-check', 'mainwp-timecapsule-extension' );

		$edit_site_id = false;
		if ( $postId ) {
			$edit_site_id = get_post_meta( $postId, '_mainwp_edit_post_site_id', true );
            $edit_site_id = intval( $edit_site_id );
		}

		if ( $edit_site_id ) {
			$show_group	 = false;
		}
		?>


    <div id="mainwp-select-sites-footer">
			<div class="ui grid">
				<div class="four wide column">
					<div class="ui basic icon mini buttons">
					  <a class="ui button" onClick="return mainwp_ss_select( this, true )" data-tooltip="<?php esc_attr_e( 'Select all websites.', 'mainwp' ); ?>" data-inverted=""><i class="check square outline icon"></i></a>
						<a class="ui button" onClick="return mainwp_ss_select( this, false )" data-tooltip="<?php esc_attr_e( 'Deselect all websites.', 'mainwp' ); ?>" data-inverted=""><i class="square outline icon"></i></a>
					</div>
				</div>
				<div class="twelve wide column">
					<div class="ui mini fluid icon input">
						<input type="text" id="mainwp-select-sites-filter" value="" placeholder="<?php esc_attr_e( 'Type to filter your sites', 'mainwp' ); ?>" <?php echo esc_attr( count( $selected_groups ) > 0 ? 'style="display: none;"' : ''  ); ?> />
						<i class="filter icon"></i>
					</div>
				</div>
			</div>
    </div>

    <input type="hidden" name="select_by" id="select_by" value="<?php echo esc_attr( count( $selected_groups ) > 0 ? 'group' : 'site'  ); ?>"/>
    <input type="hidden" id="select_sites_tab" value="<?php echo esc_attr( count( $selected_groups ) > 0 ? 'group' : 'site'  ); ?>"/>

		<div id="mainwp-select-sites-header">
			<div class="ui pointing green secondary menu">
			  <a class="item active" data-tab="mainwp-select-sites"><?php esc_html_e( 'Sites', 'mainwp' ); ?></a>
			  <a class="item" data-tab="mainwp-select-groups"><?php esc_html_e( 'Groups', 'mainwp' ); ?></a>
        <?php if ( $staging_enabled ) : ?>
        <a class="item" data-tab="mainwp-select-staging-sites"><?php esc_html_e( 'Staging', 'mainwp' ); ?></a>
        <?php endif; ?>
      </div>
    </div>

		<div class="ui divider hidden"></div>

		<div class="ui tab active" data-tab="mainwp-select-sites" id="mainwp-select-sites" select-by="site">
			<div id="mainwp-select-sites-body">
				<div class="ui relaxed divided list" id="mainwp-select-sites-list">
					<?php if ( !$websites ): ?>
						<h2 class="ui icon header">
							<i class="folder open outline icon"></i>
							<div class="content"><?php esc_html_e( 'No Sites connected!', 'mainwp'); ?></div>
							<div class="ui divider hidden"></div>
							<a href="admin.php?page=managesites&do=new" class="ui green button basic"><?php esc_html_e( 'Add Site', 'mainwp'); ?></a>
						</h2>
					<?php else:
						while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
              $selected = false;
							if ( $website->sync_errors == '' || $enableOfflineSites ) {
								$selected	 = ( $selected_websites == 'all' || in_array( $website->id, $selected_websites ) );
                                $disabled	 = '';
								if ( $edit_site_id ) {
									if ( $website->id == $edit_site_id ) {
                    $selected = true;
									} else {
                    $disabled = 'disabled="disabled"';
                  }
								}
            		?>
                <div title="<?php echo $website->url; ?>" class="mainwp_selected_sites_item ui checkbox item <?php echo ( $selected ? 'selected_sites_item_checked' : '' ); ?>">
                  <input onClick="mainwp_site_select(this)" <?php echo $disabled; ?> type="<?php echo $type; ?>" name="<?php echo ( $type == 'radio' ? 'selected_site' : 'selected_sites[]' ); ?>" siteid="<?php echo $website->id; ?>" value="<?php echo $website->id; ?>" id="selected_sites_<?php echo $website->id; ?>" <?php echo ( $selected ? 'checked="true"' : '' ); ?> />
                  <label for="selected_sites_<?php echo $website->id; ?>">
                  <?php echo stripslashes( $website->name ); ?>  <span class="url"><?php echo $website->url; ?></span>
                  </label>
                </div>
                <?php
              } else {
              ?>
              <div title="<?php echo $website->url; ?>" class="mainwp_selected_sites_item item ui checkbox <?php echo ( $selected ? 'selected_sites_item_checked' : '' ); ?>">
	              <input type="checkbox" disabled="disabled"/>
	              <label for="selected_sites_<?php echo $website->id; ?>">
                  <?php echo stripslashes( $website->name ); ?>  <span class="url"><?php echo $website->url; ?></span>
	              </label>
              </div>
							<?php
              }
            }
					@MainWP_DB::free_result( $websites );
		  		endif; ?>
				</div>
			</div>

		</div>

    <?php
		if ( $staging_enabled ) :
			$websites	= MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser( false, null, 'wp.url', false, false, null, false, array( 'favi_icon' ), $is_staging = 'yes' ) );
			?>
            <div class="ui tab" data-tab="mainwp-select-staging-sites" select-by="staging">
			<div id="mainwp-select-sites-body">
				<div class="ui relaxed divided list" id="mainwp-select-staging-sites-list">
				<?php if ( !$websites ): ?>
						<h2 class="ui icon header">
							<i class="folder open outline icon"></i>
							<div class="content"><?php esc_html_e( 'No staging websites have been found!', 'mainwp'); ?></div>
						</h2>
					<?php else:
                            while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
                                $selected = false;
                                if ( $website->sync_errors == '' || $enableOfflineSites ) {
                                        $selected	 = ( $selected_websites == 'all' || in_array( $website->id, $selected_websites ) );
                                        $disabled	 = '';
                                        if ( $edit_site_id ) {
                                            if ( $website->id != $edit_site_id ) {
                                                $disabled = 'disabled="disabled"';
                                            }
                                        }
                                    ?>
                                        <div title="<?php echo $website->url; ?>" class="mainwp_selected_sites_item ui checkbox item <?php echo ( $selected ? 'selected_sites_item_checked' : '' ); ?>">
                                            <input onClick="mainwp_site_select(this)" <?php echo $disabled; ?> type="checkbox" name="selected_sites[]" siteid="<?php echo $website->id; ?>" value="<?php echo $website->id; ?>" id="selected_sites_<?php echo $website->id; ?>" <?php echo ( $selected ? 'checked="true"' : '' ); ?> />
                                            <label for="selected_sites_<?php echo $website->id; ?>">
                                                <?php echo stripslashes( $website->name ); ?>  <span class="url"><?php echo $website->url; ?></span>
                                            </label>
                                        </div>
                                <?php
                                        } else {
                                ?>
                                        <div title="<?php echo $website->url; ?>" class="mainwp_selected_sites_item item ui checkbox <?php echo ( $selected ? 'selected_sites_item_checked' : '' ); ?>">
                                            <input <?php echo $disabled; ?> type="checkbox" disabled="disabled"/>
                                            <label for="selected_sites_<?php echo $website->id; ?>">
                                                <?php echo stripslashes( $website->name ); ?>  <span class="url"><?php echo $website->url; ?></span>
                                            </label>
                                        </div>
                                <?php
                                        }

                            }
                            @MainWP_DB::free_result( $websites );
                endif;

				?>
                </div>
            </div>

        </div>
		<?php endif;
		?>
		<div class="ui tab" data-tab="mainwp-select-groups" id="mainwp-select-groups" select-by="group">
			<div id="mainwp-select-sites-body">
				<div class="ui relaxed divided list" id="mainwp-select-groups-list">
				<?php
				if ( count( $groups ) == 0 ) {
						?>
						<h2 class="ui icon header">
							<i class="folder open outline icon"></i>
							<div class="content"><?php esc_html_e( 'No Groups created!', 'mainwp'); ?></div>
							<div class="ui divider hidden"></div>
							<a href="admin.php?page=ManageGroups" class="ui green button basic"><?php esc_html_e( 'Create Groups', 'mainwp'); ?></a>
						</h2>
						<?php
				}
				foreach ( $groups as $group ) {
					$selected = in_array( $group->id, $selected_groups );
						?>
						<div class="mainwp_selected_groups_item ui item checkbox <?php echo ( $selected ? 'selected_groups_item_checked' : '' ); ?>">
						  <input onClick="mainwp_group_select(this)" type="checkbox" name="selected_groups[]" value="<?php echo $group->id; ?>" id="selected_groups_<?php echo $group->id; ?>" <?php echo ( $selected ? 'checked="true"' : '' ); ?> />
						  <label for="selected_groups_<?php echo $group->id; ?>">
								<?php echo stripslashes( $group->name ); ?>
							</label>
						</div>
						<?php
				}
				?>
			</div>
		</div>

		</div>


		<script type="text/javascript">
            jQuery( document ).ready( function () {
                jQuery('#mainwp-select-sites-header .ui.menu .item').tab({'onVisible': function(){ mainwp_sites_selection_onvisible_callback(this); }});
            });
		</script>


		<?php
		if ( $updateQty ) {
			//echo '<script>jQuery(document).ready(function () {jQuery(".mainwp_sites_selectcount").html(' . (!is_array( $selected_websites ) ? '0' : count( $selected_websites ) ) . ');});</script>';
		}
	}

	public static function render_top_header( $params = array() ) {

		$title = isset($params['title']) ? $params['title'] : '';
		$title = apply_filters( 'mainwp_header_title', $title );

		$show_menu = true;
		$show_new_items = true;

		if ( isset($params['show_menu'] ) )
			$show_menu = $params['show_menu'];

		$left = apply_filters( 'mainwp_header_left', $title );

		$right = self::render_header_actions();
		$right = apply_filters( 'mainwp_header_right', $right );



		if ( $show_menu )
			MainWP_Menu::render_left_menu();


        $sidebarPosition = get_user_option( "mainwp_sidebarPosition" );
        if ( $sidebarPosition === false )
          $sidebarPosition = 1;

		?>
		<div class="ui segment right wide sidebar" id="mainwp-documentation-sidebar">
			<div class="ui header"><?php echo __( 'MainWP Documenation', 'mainwp' ); ?></div>
			<div class="ui hidden divider"></div>
			<?php do_action( 'mainwp_help_sidebar_content' ); ?>
			<div class="ui hidden divider"></div>

			<a href="https://mainwp.com/help/" class="ui big green fluid button"><?php echo __( 'Help Documentation', 'mainwp' ); ?></a>

			<div class="ui hidden divider"></div>

			<div id="mainwp-sticky-help-button" class="" style="position: absolute; bottom: 1em; left: 1em; right: 1em;">
				<a href="https://mainwp.com/my-account/get-support/" target="_blank" class="ui fluid button"><?php echo __( 'Still Need Help?', 'mainwp' ); ?></a>
			</div>
		</div>
		<div class="mainwp-content-wrap <?php echo empty( $sidebarPosition ) ? 'mainwp-sidebar-left' : ''?>">
			<?php do_action( "mainwp_before_header" ); ?>
			<div id="mainwp-top-header" class="ui sticky">
				<div class="ui stackable grid">
					<div class="two column row">
						<div class="left floated column"><h4 class="mainwp-page-title"><?php echo $left; ?></h4></div>
						<div class="right floated column right aligned"><?php echo $right; ?></div>
					</div>
				</div>
				<script type="text/javascript">
                    jQuery( document ).ready( function () {
						jQuery( '.ui.sticky' ).sticky();
						jQuery( '#mainwp-help-sidebar' ).on( 'click', function() {
							jQuery( '.ui.sidebar' ).sidebar( {
								transition: 'overlay'
							} );
							jQuery( '.ui.sidebar' ).sidebar( 'toggle' );
							return false;
						} );
					} );
				</script>
			</div>
			<?php do_action( "mainwp_after_header" ); ?>
		<?php
	}

	public static function render_second_top_header ( $which = '' ) {
		 do_action( 'mainwp_before_subheader' );

         if (has_action('mainwp_subheader_actions') || $which == 'overview' || $which == 'managesites') {
            ?>
               <div class="mainwp-sub-header">
                   <?php if ( $which == 'overview' ) : ?>
                   <div class="ui stackable grid">
                     <div class="column full">
                       <?php self::gen_groups_sites_selection(); ?>
                     </div>
                   </div>
                   <?php endif ; ?>
           <?php if ( $which == 'managesites' ) : ?>
                       <?php do_action( 'mainwp_managesites_tabletop' ); ?>
                   <?php else : ?>
                       <?php do_action( 'mainwp_subheader_actions' ); ?>
                   <?php endif ; ?>
               </div>
           <?php
        }

		do_action( 'mainwp_after_subheader' );
	}

	public static function gen_groups_sites_selection() {

		$sql = MainWP_DB::Instance()->getSQLWebsitesForCurrentUser( false, null, 'wp.url', false, false, null, false, array( 'premium_upgrades', 'plugins_outdate_dismissed', 'themes_outdate_dismissed', 'plugins_outdate_info', 'themes_outdate_info', 'favi_icon' ));
		$websites = MainWP_DB::Instance()->query( $sql );
		$g = isset( $_GET['g'] ) ? intval( $_GET['g'] ) : -1;
		$s = isset( $_GET['dashboard'] ) ? intval( $_GET['dashboard'] ) : -1;
		?>
		<div class="column full wide">
				<select id="mainwp_top_quick_jump_group" class="ui dropdown">
					<option value="" class="item"><?php esc_html_e( 'All Groups', 'mainwp' ); ?></option>
					<option <?php echo ($g == -1) ? 'selected' : ''; ?> value="-1" class="item"><?php esc_html_e( 'All Groups', 'mainwp' ); ?></option>
					<?php
					$groups = MainWP_DB::Instance()->getGroupsForManageSites();
					foreach ( $groups as $group ) {
						?>
						<option class="item" <?php echo ($g == $group->id) ? 'selected' : ''; ?> value="<?php echo $group->id; ?>"><?php echo stripslashes( $group->name ); ?></option>
						<?php
					}
					?>
				</select>
				<select class="ui dropdown" id="mainwp_top_quick_jump_page">
						<option value="" class="item"><?php esc_html_e( 'All Sites', 'mainwp' ); ?></option>
						<option <?php echo ($s == -1) ? 'selected' : ''; ?> value="-1" class="item" ><?php esc_html_e( 'All Sites', 'mainwp' ); ?></option>
						<?php while ( $websites && ( $website	= @MainWP_DB::fetch_object( $websites ) ) ) {
						?>
							<option value="<?php echo $website->id; ?>" <?php echo ($s == $website->id) ? 'selected' : ''; ?> class="item" ><?php echo stripslashes( $website->name ); ?></option>
						<?php
						}
						?>
				</select>
		</div>
			<script type="text/javascript">
				jQuery( document ).on( 'change', '#mainwp_top_quick_jump_group', function ()
				{
					var jumpid = jQuery(this).val();
					window.location = 'admin.php?page=managesites&g='  + jumpid;
				});

				jQuery( document ).on( 'change', '#mainwp_top_quick_jump_page', function ()
				{
					var jumpid = jQuery(this).val();
					if (jumpid == -1)
						window.location = 'admin.php?page=managesites&s='  + jumpid;
					else
						window.location = 'admin.php?page=managesites&dashboard='  + jumpid;
				});
			</script>
		<?php
		@MainWP_DB::free_result( $websites );

	}


	public static function render_header_actions() {
		$sites_count = MainWP_DB::Instance()->getWebsitesCount();
		$website_id = '';
		ob_start();
		?>
			<button class="ui button green <?php echo ( $sites_count > 0 ? '' : 'disabled' ); ?>" id="mainwp-sync-sites" data-inverted="" data-position="bottom right" data-tooltip="<?php esc_attr_e( 'Get fresh data from your child sites.', 'mainwp' ); ?>"><?php esc_html_e( 'Sync Dashboard with Child Sites', 'mainwp' ); ?></button>
			<div class="ui <?php echo ( $sites_count == 0 ? 'green' : '' ); ?> buttons" id="mainwp-add-new-buttons">
				<a class="ui button" data-inverted="" data-position="bottom right" data-tooltip="<?php esc_attr_e( 'Add a new Website to your MainWP Dashboard', 'mainwp' ); ?>" href="<?php echo esc_attr( admin_url( 'admin.php?page=managesites&do=new' ) ); ?>"><?php esc_html_e( 'New Site', 'mainwp' ); ?></a>
        <div class="ui floating dropdown icon button"  style="z-index: 999;" data-inverted="" data-position="bottom right" data-tooltip="<?php esc_attr_e( 'More options', 'mainwp' ); ?>">
          <i class="dropdown icon"></i>
          <div class="menu">
            <a class="item" data-inverted="" data-position="left center" data-tooltip="<?php esc_attr_e( 'Add a new Post to your child sites', 'mainwp' ); ?>" href="<?php echo esc_attr( admin_url( 'admin.php?page=PostBulkAdd' ) ); ?>"><?php esc_html_e( 'Post', 'mainwp' ); ?></a>
            <a class="item" data-inverted="" data-position="left center" data-tooltip="<?php esc_attr_e( 'Add a new Page to your child sites', 'mainwp' ); ?>" href="<?php echo esc_attr( admin_url( 'admin.php?page=PageBulkAdd' ) ); ?>"><?php esc_html_e( 'Page', 'mainwp' ); ?></a>
            <a class="item" data-inverted="" data-position="left center" data-tooltip="<?php esc_attr_e( 'Install a new Plugin to your child sites', 'mainwp' ); ?>" href="<?php echo esc_attr( admin_url( 'admin.php?page=PluginsInstall' ) ); ?>"><?php esc_html_e( 'Plugin', 'mainwp' ); ?></a>
            <a class="item" data-inverted="" data-position="left center" data-tooltip="<?php esc_attr_e( 'Install a new Theme to your child sites', 'mainwp' ); ?>" href="<?php echo esc_attr( admin_url( 'admin.php?page=ThemesInstall' ) ); ?>"><?php esc_html_e( 'Theme', 'mainwp' ); ?></a>
            <a class="item" data-inverted="" data-position="left center" data-tooltip="<?php esc_attr_e( 'Create a new User to your child sites', 'mainwp' ); ?>" href="<?php echo esc_attr( admin_url( 'admin.php?page=UserBulkAdd' ) ); ?>"><?php esc_html_e( 'User', 'mainwp' ); ?></a>
          </div>
        </div>
			</div>
			<?php if ( isset( $_GET['dashboard'] ) ) : ?>
				<?php $website_id = $_GET['dashboard']; ?>
				<a href="<?php echo 'admin.php?page=SiteOpen&newWindow=yes&websiteid=' . $website_id; ?>" data-tooltip="<?php esc_attr_e( 'Jump to the site WP Admin', 'mainwp' ); ?>"  data-position="bottom right"  data-inverted="" class="open_newwindow_wpadmin ui green basic icon button" target="_blank"><i class="sign in icon"></i></a>
			<?php endif; ?>
			<?php if ( ( isset( $_GET['page'] ) && $_GET[ 'page' ] == 'mainwp_tab' ) || isset( $_GET['dashboard'] ) ) : ?>
			<a class="ui button basic icon" onclick="jQuery( '#mainwp-overview-screen-options-modal' ).modal( 'show' ); return false;" data-inverted="" data-position="bottom right" href="#" target="_blank" data-tooltip="<?php esc_html_e( 'Screen Options', 'mainwp' ); ?>">
			  <i class="cog icon"></i>
			</a>
			<?php endif; ?>
        <?php
        $actions = apply_filters('mainwp_header_actions_right', '');
		if ( !empty( $actions ) )
          	echo $actions;
        ?>
        <a class="ui button basic icon" id="mainwp-help-sidebar" data-inverted="" data-position="bottom right" href="#" target="_blank" data-tooltip="<?php esc_html_e( 'Need help?', 'mainwp' ); ?>">
					<i class="life ring icon"></i>
				</a>
			<!--
			<a class="ui button basic icon" data-inverted="" data-position="bottom right" href="https://mainwp.com/mainwp-extensions/" target="_blank" data-tooltip="<?php esc_html_e( 'Get new MainWP Extensions at MainWP.com', 'mainwp' ); ?>">
				<i class="th icon"></i>
			</a>
			-->

			<a class="ui button basic icon" data-inverted="" data-position="bottom right" data-tooltip="<?php esc_html_e( 'Go to your MainWP Account at MainWP.com', 'mainwp' ); ?>" target="_blank" href="https://mainwp.com/my-account/">
				<i class="user icon"></i>
			</a>
			<?php
			$all_updates = wp_get_update_data();
			if ( is_array($all_updates) && isset($all_updates['counts']['total']) && $all_updates['counts']['total'] > 0  ) {
				?>
				<a class="ui red icon button" data-inverted="" data-position="bottom right" data-tooltip="<?php esc_html_e( 'Your MainWP Dashboard sites needs your attention. Please check the available updates', 'mainwp' ); ?>" href="update-core.php">
					<i class="exclamation triangle icon"></i>
				</a>
				<?php
			}
			?>			
      <?php if ( get_option( 'mainwp_show_usersnap', false ) ) : ?>
			<a class="ui button black icon" id="usersnap-bug-report-button" data-position="bottom right" data-inverted="" data-tooltip="<?php esc_html_e( 'Click here (or use Ctrl + U keyboard shortcut) to open the Bug reporting mode.', 'mainwp' ); ?>" target="_blank" href="#">
				<i class="bug icon"></i>
			</a>
			<?php endif; ?>
		<?php
		$output = ob_get_clean();
		return $output;
	}

	public static function render_page_navigation( $subitems = array(), $name_caller = null ) {

            /**
             * This hook allows you to add extra pages navigation via the 'mainwp_page_navigation' filter.
             */
			$subitems = apply_filters( 'mainwp_page_navigation', $subitems , $name_caller );

			?>
			<div id="mainwp-page-navigation-wrapper">
				<div class="ui secondary green pointing menu stackable mainwp-page-navigation">
					<?php

					if ( is_array( $subitems )) {
							foreach ( $subitems as $item ) {

								if ( isset( $item[ 'access' ] ) && ! $item[ 'access' ] ) {
									continue;
								}

								$active = '';
								if (isset( $item['active'] ) && $item['active']) {
									$active = 'active';
								}
								$style = '';
								if (isset( $item['style'])) {
									$style = 'style="' . $item['style'] . '"';
								}

								?>
								<a class="<?php echo esc_attr($active); ?> item" <?php echo esc_attr($style); ?> href="<?php echo esc_url($item[ 'href' ]); ?>"><?php echo esc_html($item[ 'title' ]); ?></a>
								<?php
							}
					}
					?>
				</div>
			</div>
		<?php
	}

	public static function renderHeader( $title = '' ) {
		self::render_top_header( array('title' => $title ) );
		?>
			<div style="clear: both;"></div>
			<div class="wrap">
		<?php
	}

	public static function renderFooter() {
				?>
			</div>
		</div>
		<?php
	}

	public static function render_begin_modal($title = '', $others = array()) {
	?>
	<!-- modal -->
	<div class="ui modal" id="mainwp-modal-default" tabindex="0">
		<?php if ( !empty( $title ) ) { ?>
		<div class="header"></div>
		<?php } ?>
		<div class="ui progress green mainwp-modal-progress">
			<div class="bar"><div class="progress"></div></div>
			<div class="label"></div>
		</div>
		<div class="scrolling content mainwp-modal-content"><!-- content -->
	<?php
	}

	public static function render_end_modal( $actions = '', $others = array()) {
	?>
		</div><!-- end content -->
		<div class="actions mainwp-modal-actions">
		<?php echo $actions; ?>
			<div class="mainwp-modal-close ui cancel button"><?php _e( 'Close' ); ?></div>
		</div>
	</div><!-- end modal -->
	<?php
	}

	public static function renderImage( $img, $alt, $class, $height = null ) {
		?>
		<img src="<?php echo esc_attr( MAINWP_PLUGIN_URL . 'assests/' . $img ); ?>" class="<?php echo esc_attr( $class ); ?>" alt="<?php echo esc_attr( $alt ); ?>" <?php echo esc_attr( $height == null ? '' : 'height="' . $height . '"'  ); ?> />
		<?php
	}

    // customize Wordpress add_meta_box() function
    // param $context: lef, right
	public static function add_widget_box( $id, $callback, $screen = null, $context = null, $title = null, $priority = 'default' ) {
		global $mainwp_widget_boxes;

		$page = MainWP_Utility::get_page_id( $screen );

        if (empty($page))
            return;

        $overviewColumns = get_option('mainwp_number_overview_columns', 2);        
        $contexts = array('left', 'right');
        if ( $overviewColumns == 3){
            $contexts[] = 'middle';
        }
        
        if ( $context == null || !in_array($context, $contexts) ) {
            $context = 'right';
        }

		if ( !isset($mainwp_widget_boxes) )
			$mainwp_widget_boxes = array();
		if ( !isset($mainwp_widget_boxes[$page]) )
			$mainwp_widget_boxes[$page] = array();
		if ( !isset($mainwp_widget_boxes[$page][$context]) )
			$mainwp_widget_boxes[$page][$context] = array();

        foreach ( array_keys($mainwp_widget_boxes[$page]) as $a_context ) {
            foreach ( array('high', 'core', 'default', 'low') as $a_priority ) {
                if ( !isset($mainwp_widget_boxes[$page][$a_context][$a_priority][$id]) )
                    continue;

                // If box previously deleted, don't add
                if ( false === $mainwp_widget_boxes[$page][$a_context][$a_priority][$id] )
                    return;

                // If no priority given and id already present, use existing priority.
                if ( empty($priority) ) {
                    $priority = $a_priority;
                /*
                 * Else, if we're adding to the sorted priority, we don't know the title
                 * or callback. Grab them from the previously added context/priority.
                 */
                } elseif ( 'sorted' == $priority ) {
                    $title = $mainwp_widget_boxes[$page][$a_context][$a_priority][$id]['title'];
                    $callback = $mainwp_widget_boxes[$page][$a_context][$a_priority][$id]['callback'];
                }

                // An id can be in only one context.
                if ( $priority != $a_priority || $context != $a_context )
                    unset($mainwp_widget_boxes[$page][$a_context][$a_priority][$id]);

            }
        }

		if ( !isset($mainwp_widget_boxes[$page][$context]) )
			$mainwp_widget_boxes[$page][$context] = array();

        if ( empty($priority) )
            $priority = 'default';

        if ( empty($title) ) {
            $title = 'No Title';
        }
		$mainwp_widget_boxes[$page][$context][$priority][$id] = array('id' => $id, 'title' => $title, 'callback' => $callback);
	}

	// customize Wordpress do_meta_boxes() function
	public static function do_widget_boxes( $screen, $context = null, $object = '' ) {
		global $mainwp_widget_boxes;
            static $already_sorted = false;

        $page = MainWP_Utility::get_page_id( $screen );

        if (empty($page))
            return;

        $overviewColumns = get_option('mainwp_number_overview_columns', 2);        
        $contexts = array('left', 'right');
        if ( $overviewColumns == 3){
            $contexts[] = 'middle';
        }
        
        if ( $context == null || !in_array($context, $contexts) ) {
            $context = 'right';
        }

//        // Grab the ones the user has manually sorted. Pull them out of their previous context/priority and into the one the user chose
        if ( ! $already_sorted && $sorted = get_user_option( "mainwp_widgets_sorted_" . $page ) ) {
            foreach ( explode( ',', $sorted ) as $val ) {
                list($widget_context, $id) = explode(":", $val);
                if ( !empty( $widget_context ) && !empty($id) ) {
                    self::add_widget_box( $id, null, $screen, $widget_context, null, $priority = 'sorted' );
                    $already_sorted = true;
                }
            }
        }


        $hide_widgets = get_user_option('mainwp_settings_hide_widgets');
        if (!is_array($hide_widgets))
            $hide_widgets = array();

        if ( isset( $mainwp_widget_boxes[ $page ][ $context ] ) ) {
          foreach ( array( 'high', 'sorted', 'core', 'default', 'low' ) as $priority ) {
            if ( isset( $mainwp_widget_boxes[ $page ][ $context ][$priority]) ) {
              foreach ( ( array ) $mainwp_widget_boxes[ $page ][ $context ][$priority] as $box ) {
                if ( false == $box || !isset( $box['callback'] ) )
                  continue;

                // to avoid hidden widgets
                if ( in_array( $box['id'], $hide_widgets ) ) {
                  continue;
                }

                echo '<div class="column grid-item" id="widget-' . esc_html($box['id']) . '">' . "\n";
                echo '<div class="ui segment mainwp-widget" >' . "\n";
                //echo '<div class="ui floating circular large handle-drag label"><i class="clone outline icon handle-drag"></i></div>' . "\n";

                call_user_func($box['callback'], $object, $box);

                echo "</div>\n";
                echo "</div>\n";
              }
          	}
      		}
        }
	}

    static public function get_empty_bulk_actions() {
        ob_start();
        ?>
		<?php esc_html_e( 'Bulk Actions: ', 'mainwp' ); ?>
		<div class="ui disabled dropdown" id="mainwp-bulk-actions">
		  <?php esc_html_e( 'Bulk Actions', 'mainwp' ); ?> <i class="dropdown icon"></i>
		  <div class="menu"></div>
		</div>
		<button class="ui tiny basic disabled button" href="javascript:void(0)" ><?php esc_html_e( 'Apply', 'mainwp' ); ?></button>
        <?php
        $output = ob_get_clean();
        return $output;
    }

    public static function render_modal_install_plugin_theme( $what = 'plugin') {
        ?>
        <div id="plugintheme-installation-progress-modal" class="ui modal">
            <div class="header"><?php
            if ( $what == 'plugin' ) {
                esc_html_e( 'Plugin Installation', 'mainwp' );
            } else if ( $what == 'theme' ) {
                esc_html_e( 'Theme Installation', 'mainwp' );
            }
            ?>
            </div>
            <div id="plugintheme-installation-queue" class="scrolling content"></div>
            <div class="actions">
              <div class="ui cancel button"><?php esc_html_e( 'Close', 'mainwp' ); ?></div>
            </div>
          </div>
        <?php
    }

    public static function render_show_all_updates_button() {
        ?>
        <a href="javascript:void(0)" class="ui mini button trigger-all-accordion"><?php _e('Show All Updates', 'mainwp'); ?></a>
        <?php
    }

    public static function render_sorting_icons() {
        ?>
        <i class="sort icon"></i><i class="sort up icon"></i><i class="sort down icon">
        <?php
    }

    public static function render_modal_edit_notes( $what = 'site') {
	?>
		<div id="mainwp-notes" class="ui modal">
		  <div class="header"><?php esc_html_e( 'Notes', 'mainwp' ); ?></div>
		  <div class="content" id="mainwp-notes-content">
				<div id="mainwp-notes-html"></div>
				<div id="mainwp-notes-editor" class="ui form" style="display:none;">
					<div class="field">
						<label><?php esc_html_e( 'Edit note', 'mainwp' ); ?></label>
						<textarea id="mainwp-notes-note"></textarea>
					</div>
					<div><?php _e( 'Allowed HTML tags:', 'mainwp' ); ?> &lt;p&gt;, &lt;strong&gt;, &lt;em&gt;, &lt;br&gt;, &lt;hr&gt;, &lt;a&gt;, &lt;ul&gt;, &lt;ol&gt;, &lt;li&gt;, &lt;h1&gt;, &lt;h2&gt; </div>
				</div>
		  </div>
			<div class="actions">
				<div class="ui grid">
				  <div class="eight wide left aligned middle aligned column">
						<div id="mainwp-notes-status" class="left aligned"></div>
					</div>
				  <div class="eight wide column">
                        <input type="button" class="ui green button" id="mainwp-notes-save" value="<?php esc_attr_e( 'Save note', 'mainwp' ); ?>" style="display:none;"/>
                        <input type="button" class="ui basic green button" id="mainwp-notes-edit" value="<?php esc_attr_e( 'Edit', 'mainwp' ); ?>"/>
                        <input type="button" class="ui red button" id="mainwp-notes-cancel" value="<?php esc_attr_e( 'Close', 'mainwp' ); ?>"/>
                        <input type="hidden" id="mainwp-notes-websiteid" value=""/>
                        <input type="hidden" id="mainwp-notes-slug" value=""/>
                        <input type="hidden" id="mainwp-which-note" value="<?php echo esc_html( $what ); ?>"/>
					</div>
				</div>
		  </div>
		</div>

	<?php
	}

  public static function usersnap_integration() {

    $showtime = get_option( 'mainwp_show_usersnap', false );

    if ( ! $showtime ) {
      return false;
    }

    if ( time() > ( $showtime + 24 * 60 * 60 ) ) {
      MainWP_Utility::update_option( 'mainwp_show_usersnap', 0 );
      return false;
    }

		echo '<script type="text/javascript">
		window.onUsersnapLoad = function(api) {
			api.init();
			window.Usersnap = api;
		}
		var script = document.createElement(\'script\');
		script.async = 1;
		script.src = \'https://api.usersnap.com/load/0e400c4c-d713-4c62-975a-4eba2a096375.js?onload=onUsersnapLoad\';
		document.getElementsByTagName(\'head\')[0].appendChild(script);

		jQuery(function() {
		  jQuery("#usersnap-bug-report-button").click(function() {
		    Usersnap.open();
		    return false;
		  });
		});
		</script>';
    return true;
	}

    public static function render_screen_options( $setting_page = true ) {

        $default_widgets = array(
			'overview' 					=> __( 'Updates Overview', 'mainwp' ),
			'recent_posts' 			=> __( 'Recent Posts', 'mainwp' ),
			'recent_pages' 			=> __( 'Recent Pages', 'mainwp' ),
			'plugins' 					=> __( 'Plugins (Individual Site Overview page)', 'mainwp' ),
			'themes' 						=> __( 'Themes (Individual Site Overview page)', 'mainwp' ),
			'connection_status' => __( 'Connection Status', 'mainwp' ),
			'security_issues' 	=> __( 'Security Issues', 'mainwp' ),
			'notes' 						=> __( 'Notes (Individual Site Overview page)', 'mainwp' ),
			'child_site_info'	  => __( 'Child site info (Individual Site Overview page)', 'mainwp' ),
        );

		$custom_opts = apply_filters( 'mainwp-widgets-screen-options', array() );		
		if (  is_array( $custom_opts ) && count( $custom_opts ) > 0 ) {
			$default_widgets = array_merge( $default_widgets, $custom_opts );
		}
		
        $hide_widgets = get_user_option( 'mainwp_settings_hide_widgets' );
        if ( !is_array( $hide_widgets ) )
            $hide_widgets = array();

        ?>
    <div class="ui grid field">
      <label class="six wide column middle aligned"><?php esc_html_e( 'Hide the Update Everything button', 'mainwp' ); ?></label>
      <div class="ten wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'If enabled, the "Update Everything" button will be hidden in the Updates Overview widget.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
            <input type="checkbox" name="hide_update_everything" <?php echo ( ( get_option( 'mainwp_hide_update_everything' ) == 1 ) ? 'checked="true"' : ''); ?> />
      </div>
    </div> 
    <?php
    $overviewColumns = get_option('mainwp_number_overview_columns', 2);
    if ( $overviewColumns != 2 && $overviewColumns != 3 ) {
        $overviewColumns = 2;
    }

    ?>
    <div class="ui grid field">
      <label class="six wide column middle aligned"><?php esc_html_e( 'Widgets columns', 'mainwp' ); ?></label>
      <div class="ten wide column">
            <div class="ui radio checkbox">
                <input type="radio" name="number_overview_columns" required="required" <?php echo ( $overviewColumns == 2 ? 'checked="true"' : '' ); ?> value="2">
          <label><?php esc_html_e( 'Show widgets in 2 columns', 'mainwp' ); ?></label>
            </div>          
				<div class="ui fitted hidden divider"></div>
            <div class="ui radio checkbox">
                <input type="radio" name="number_overview_columns" required="required" <?php echo ( $overviewColumns == 3 ? 'checked="true"' : '' ); ?> value="3">
          <label><?php esc_html_e( 'Show widgets in 3 columns', 'mainwp' ); ?></label>
            </div>          
      </div>
    </div>
    
    <div class="ui grid field">
      <label class="six wide column"><?php _e( 'Hide unwanted widgets', 'mainwp' ); ?></label>
      <div class="ten wide column" <?php echo $setting_page ?  'data-tooltip="' . esc_attr_e( 'Select widgets that you want to hide in the MainWP Overview page.', 'mainwp' ) .'"' : ''; ?> data-inverted="" data-position="top left">
        <ul class="mainwp_hide_wpmenu_checkboxes">
        <?php
        foreach ( $default_widgets as $name => $title ) {
          $_selected = '';
          if ( in_array( $name, $hide_widgets ) ) {
            $_selected = 'checked';
          }
          ?>
	          <li>
              <div class="ui checkbox">
                <input type="checkbox" id="mainwp_hide_widget_<?php echo esc_attr( $name ); ?>" name="mainwp_hide_widgets[]" <?php echo $_selected; ?> value="<?php echo esc_attr( $name ); ?>">
                <label for="mainwp_hide_widget_<?php echo esc_attr( $name ); ?>" ><?php echo esc_html( $title ); ?></label>
              </div>
	          </li>
          <?php
          }
          ?>
        </ul>
      </div>
    </div>

	<div class="ui grid field">
      <label class="six wide column middle aligned"></label>
      <div class="ten wide column">
				<div class="ui info message">
					<div class="header"><?php esc_html_e( 'Privacy Notice', 'mainwp' ); ?></div>
        	<p><?php esc_html_e( 'The Bug Recorder uses a program called Usersnap to take a screen capture of your issue. However, the Bug Recorder only records your screen and browser information when press the bug button on the top right of your screen.', 'mainwp' ); ?></p>
					<p><?php esc_html_e( 'Information recorded when you take a screen shot includes:', 'mainwp' ); ?></p>
					<div class="ui bulleted list">
						<div class="item"><?php esc_html_e( 'Screenshot', 'mainwp' ); ?></div>
						<div class="item"><?php esc_html_e( 'Page URL', 'mainwp' ); ?></div>
						<div class="item"><?php esc_html_e( 'Browser', 'mainwp' ); ?></div>
						<div class="item"><?php esc_html_e( 'Screen Size', 'mainwp' ); ?></div>
						<div class="item"><?php esc_html_e( 'Operating System', 'mainwp' ); ?></div>
						<div class="item"><?php esc_html_e( 'Full Console Logs', 'mainwp' ); ?></div>
					</div>
					<p>
						<strong><?php esc_html_e( 'The option gets automatically disabled on your Dashboard after 24 hours or you can turn it off anytime using the Bug Recorder switch.', 'mainwp' ); ?></strong>
					</p>
	      </div>
	    </div>
		</div>

	<div class="ui grid field">
      <label class="six wide column middle aligned"><?php esc_html_e( 'Show Usersnap button', 'mainwp' ); ?></label>
      <div class="ten wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'If enabled, the Usersnap button will show in the MainWP header.', 'mainwp' ); ?>" data-inverted="" data-position="left center">
        <input type="checkbox" name="mainwp_show_usersnap" <?php echo ( ( get_option( 'mainwp_show_usersnap' ) != false ) ? 'checked="true"' : ''); ?> />
      </div>
    </div>
  <?php
  }
}
