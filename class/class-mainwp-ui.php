<?php
/**
 * MainWP UI.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_UI
 *
 * @package MainWP\Dashboard
 */
class MainWP_UI {

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
	 * Method select_sites_box()
	 *
	 * Select sites box.
	 *
	 * @param string  $type Input type, radio.
	 * @param boolean $show_group Whether or not to show group, Default: true.
	 * @param boolean $show_select_all Whether to show select all.
	 * @param string  $class Default = ''.
	 * @param string  $style Default = ''.
	 * @param array   $selected_websites Selected Child Sites.
	 * @param array   $selected_groups Selected Groups.
	 * @param boolean $enableOfflineSites (bool) True, if offline sites is enabled. False if not.
	 * @param integer $postId Post Meta ID.
	 */
	public static function select_sites_box( $type = 'checkbox', $show_group = true, $show_select_all = true, $class = '', $style = '', &$selected_websites = array(), &$selected_groups = array(), $enableOfflineSites = false, $postId = 0 ) {

		if ( $postId ) {

			$sites_val         = get_post_meta( $postId, '_selected_sites', true );
			$selected_websites = MainWP_System_Utility::maybe_unserialyze( $sites_val );

			if ( '' == $selected_websites ) {
				$selected_websites = array();
			}

			$groups_val      = get_post_meta( $postId, '_selected_groups', true );
			$selected_groups = MainWP_System_Utility::maybe_unserialyze( $groups_val );

			if ( '' == $selected_groups ) {
				$selected_groups = array();
			}
		}
		/**
		 * Action: mainwp_before_seclect_sites
		 *
		 * Fires before the Select Sites box.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_before_seclect_sites' );
		?>
		<div id="mainwp-select-sites" class="mainwp_select_sites_wrapper">
		<?php self::select_sites_box_body( $selected_websites, $selected_groups, $type, $show_group, $show_select_all, false, $enableOfflineSites, $postId ); ?>
	</div>
		<?php
		/**
		 * Action: mainwp_after_seclect_sites
		 *
		 * Fires after the Select Sites box.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_after_seclect_sites' );
	}

	/**
	 * Method select_sites_box_body()
	 *
	 * Select sites box Body.
	 *
	 * @param array  $selected_websites Child Site that are selected.
	 * @param array  $selected_groups Group that are selected.
	 * @param string $type Selector type.
	 * @param bool   $show_group         Whether or not to show group, Default: true.
	 * @param bool   $show_select_all    Whether or not to show select all, Default: true.
	 * @param bool   $updateQty          Whether or not to update quantity, Default = false.
	 * @param bool   $enableOfflineSites Whether or not to enable offline sites, Default: true.
	 * @param int    $postId             Post ID.
	 */
	public static function select_sites_box_body( &$selected_websites = array(), &$selected_groups = array(), $type = 'checkbox', $show_group = true, $show_select_all = true, $updateQty = false, $enableOfflineSites = false, $postId = 0 ) {

		if ( 'all' !== $selected_websites && ! is_array( $selected_websites ) ) {
			$selected_websites = array();
		}

		if ( ! is_array( $selected_groups ) ) {
			$selected_groups = array();
		}

		$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );
		$groups   = MainWP_DB_Common::instance()->get_not_empty_groups( null, $enableOfflineSites );

		// support staging extension.
		$staging_enabled = is_plugin_active( 'mainwp-staging-extension/mainwp-staging-extension.php' ) || is_plugin_active( 'mainwp-timecapsule-extension/mainwp-timecapsule-extension.php' );

		$edit_site_id = false;
		if ( $postId ) {
			$edit_site_id = get_post_meta( $postId, '_mainwp_edit_post_site_id', true );
			$edit_site_id = intval( $edit_site_id );
		}

		if ( $edit_site_id ) {
			$show_group = false;
		}
		// to fix layout with multi sites selector.
		$tab_id = wp_rand();

		self::render_select_sites_header( $tab_id, $staging_enabled, $selected_groups );
		self::render_select_sites( $websites, $type, $tab_id, $selected_websites, $enableOfflineSites, $edit_site_id );
		self::render_select_sites_staging( $staging_enabled, $tab_id, $selected_websites, $edit_site_id );
		self::render_select_sites_group( $groups, $tab_id, $selected_groups );
		?>
		<script type="text/javascript">
		jQuery( document ).ready( function () {
			jQuery('#mainwp-select-sites-header .ui.menu .item').tab( {'onVisible': function() { mainwp_sites_selection_onvisible_callback( this ); } } );
		} );
		</script>
		<?php
	}

	/**
	 * Method render_select_sites_header()
	 *
	 * Render selected sites header.
	 *
	 * @param int   $tab_id          Datatab ID.
	 * @param bool  $staging_enabled True, if in the active plugins list. False, not in the list.
	 * @param array $selected_groups Selected groups.
	 *
	 * @todo Move to view folder.
	 */
	public static function render_select_sites_header( $tab_id, $staging_enabled, $selected_groups ) {

		/**
		 * Action: mainwp_before_select_sites_filters
		 *
		 * Fires before the Select Sites box filters.
	 *
		 * @since 4.1
	 */
		do_action( 'mainwp_before_select_sites_filters' );
		?>
		<div id="mainwp-select-sites-filters">
			<div class="ui grid">
				<div class="four wide column">
					<div class="ui basic icon mini buttons">
						<a class="ui button" onClick="return mainwp_ss_select( this, true )" data-tooltip="<?php esc_attr_e( 'Select all websites.', 'mainwp' ); ?>" data-inverted=""><i class="check square outline icon"></i></a>
						<a class="ui button" onClick="return mainwp_ss_select( this, false )" data-tooltip="<?php esc_attr_e( 'Deselect all websites.', 'mainwp' ); ?>" data-inverted=""><i class="square outline icon"></i></a>
					</div>
				</div>
				<div class="twelve wide column">
					<div class="ui mini fluid icon input">
						<input type="text" id="mainwp-select-sites-filter" value="" placeholder="<?php esc_attr_e( 'Type to filter your sites', 'mainwp' ); ?>" <?php echo esc_attr( count( $selected_groups ) > 0 ? 'style="display: none;"' : '' ); ?> />
						<i class="filter icon"></i>
					</div>
				</div>
			</div>
		</div>
		<?php
		/**
		 * Action: mainwp_after_select_sites_filters
		 *
		 * Fires after the Select Sites box filters.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_after_select_sites_filters' );
		?>
		<input type="hidden" name="select_by" id="select_by" value="<?php echo esc_attr( 0 < count( $selected_groups ) ? 'group' : 'site' ); ?>"/>
		<input type="hidden" id="select_sites_tab" value="<?php echo esc_attr( 0 < count( $selected_groups ) ? 'group' : 'site' ); ?>"/>
		<div id="mainwp-select-sites-header">
			<div class="ui pointing green secondary menu">
				<a class="item active" data-tab="mainwp-select-sites-<?php echo $tab_id; ?>"><?php esc_html_e( 'Sites', 'mainwp' ); ?></a>
				<a class="item" data-tab="mainwp-select-groups-<?php echo $tab_id; ?>"><?php esc_html_e( 'Groups', 'mainwp' ); ?></a>
				<?php if ( $staging_enabled ) : ?>
					<a class="item" data-tab="mainwp-select-staging-sites-<?php echo $tab_id; ?>"><?php esc_html_e( 'Staging', 'mainwp' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
		<div class="ui divider hidden"></div>
		<?php
	}

	/**
	 * Method render_select_sites()
	 *
	 * @param object $websites Object containing child sites info.
	 * @param string $type Selector type.
	 * @param mixed  $tab_id Datatab ID.
	 * @param mixed  $selected_websites Selected Child Sites.
	 * @param bool   $enableOfflineSites (bool) True, if offline sites is enabled. False if not.
	 * @param mixed  $edit_site_id Child Site ID to edit.
	 *
	 * @return void Render Select Sites html.
	 */
	public static function render_select_sites( $websites, $type, $tab_id, $selected_websites, $enableOfflineSites, $edit_site_id ) {
		?>
		<div class="ui tab active" data-tab="mainwp-select-sites-<?php echo $tab_id; ?>" id="mainwp-select-sites" select-by="site">
			<?php
			/**
			 * Action: mainwp_before_select_sites_list
			 *
			 * Fires before the Select Sites list.
			 *
			 * @param object $websites Object containing child sites info.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_before_select_sites_list', $websites );
			?>
			<div id="mainwp-select-sites-body">
				<div class="ui relaxed divided list" id="mainwp-select-sites-list">
					<?php if ( ! $websites ) : ?>
						<h2 class="ui icon header">
							<i class="folder open outline icon"></i>
							<div class="content"><?php esc_html_e( 'No Sites connected!', 'mainwp' ); ?></div>
							<div class="ui divider hidden"></div>
							<a href="admin.php?page=managesites&do=new" class="ui green button basic"><?php esc_html_e( 'Add Site', 'mainwp' ); ?></a>
						</h2>
						<?php
						else :
							while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
								$selected = false;
								if ( '' == $website->sync_errors || $enableOfflineSites ) {
									$selected = ( 'all' === $selected_websites || in_array( $website->id, $selected_websites ) );
									$disabled = '';
									if ( $edit_site_id ) {
										if ( $website->id == $edit_site_id ) {
											$selected = true;
										} else {
											$disabled = 'disabled="disabled"';
										}
									}
									?>
									<div title="<?php echo esc_html( $website->url ); ?>" class="mainwp_selected_sites_item ui checkbox item <?php echo ( $selected ? 'selected_sites_item_checked' : '' ); ?>">
										<input <?php echo $disabled; ?> type="<?php echo $type; ?>" name="<?php echo ( 'radio' === $type ? 'selected_site' : 'selected_sites[]' ); ?>" siteid="<?php echo intval( $website->id ); ?>" value="<?php echo intval( $website->id ); ?>" id="selected_sites_<?php echo intval( $website->id ); ?>" <?php echo ( $selected ? 'checked="true"' : '' ); ?> />
										<label for="selected_sites_<?php echo intval( $website->id ); ?>">
											<?php echo stripslashes( $website->name ); ?>  <span class="url"><?php echo esc_html( $website->url ); ?></span>
										</label>
									</div>
									<?php
								} else {
									?>
								<div title="<?php echo esc_html( $website->url ); ?>" class="mainwp_selected_sites_item item ui checkbox <?php echo ( $selected ? 'selected_sites_item_checked' : '' ); ?>">
									<input type="checkbox" disabled="disabled"/>
									<label for="selected_sites_<?php echo intval( $website->id ); ?>">
										<?php echo stripslashes( $website->name ); ?>  <span class="url"><?php echo esc_html( $website->url ); ?></span>
									</label>
								</div>
									<?php
								}
							}
							MainWP_DB::free_result( $websites );
					endif;
						?>
				</div>
			</div>
			<?php
			/**
			 * Action: mainwp_after_select_sites_list
			 *
			 * Fires after the Select Sites list.
			 *
			 * @param object $websites Object containing child sites info.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_after_select_sites_list', $websites );
			?>
		</div>
		<?php
	}

	/**
	 * Method render_select_sites_staging()
	 *
	 * Render selected staging sites.
	 *
	 * @param boolean $staging_enabled (bool) True, if in the active plugins list. False, not in the list.
	 * @param mixed   $tab_id Datatab ID.
	 * @param mixed   $selected_websites Selected Child Sites.
	 * @param mixed   $edit_site_id Child Site ID to edit.
	 *
	 * @return void Render selected staging sites html.
	 */
	public static function render_select_sites_staging( $staging_enabled, $tab_id, $selected_websites, $edit_site_id ) {
		if ( $staging_enabled ) :
			$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user( false, null, 'wp.url', false, false, null, false, array( 'favi_icon' ), $is_staging = 'yes' ) );
			?>
			<div class="ui tab" data-tab="mainwp-select-staging-sites-<?php echo $tab_id; ?>" select-by="staging">
			<div id="mainwp-select-sites-body">
				<div class="ui relaxed divided list" id="mainwp-select-staging-sites-list">
				<?php if ( ! $websites ) : ?>
						<h2 class="ui icon header">
							<i class="folder open outline icon"></i>
							<div class="content"><?php esc_html_e( 'No staging websites have been found!', 'mainwp' ); ?></div>
						</h2>
						<?php
						else :
							while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
								$selected = false;
								if ( '' == $website->sync_errors || $enableOfflineSites ) {
										$selected = ( 'all' === $selected_websites || in_array( $website->id, $selected_websites ) );
										$disabled = '';
									if ( $edit_site_id ) {
										if ( $website->id != $edit_site_id ) {
											$disabled = 'disabled="disabled"';
										}
									}
									?>
									<div title="<?php echo esc_html( $website->url ); ?>" class="mainwp_selected_sites_item ui checkbox item <?php echo ( $selected ? 'selected_sites_item_checked' : '' ); ?>">
										<input <?php echo $disabled; ?> type="checkbox" name="selected_sites[]" siteid="<?php echo intval( $website->id ); ?>" value="<?php echo intval( $website->id ); ?>" id="selected_sites_<?php echo intval( $website->id ); ?>" <?php echo ( $selected ? 'checked="true"' : '' ); ?> />
										<label for="selected_sites_<?php echo intval( $website->id ); ?>">
											<?php echo stripslashes( $website->name ); ?>  <span class="url"><?php echo esc_html( $website->url ); ?></span>
										</label>
									</div>
									<?php
								} else {
									?>
									<div title="<?php echo esc_html( $website->url ); ?>" class="mainwp_selected_sites_item item ui checkbox <?php echo ( $selected ? 'selected_sites_item_checked' : '' ); ?>">
										<input <?php echo $disabled; ?> type="checkbox" disabled="disabled"/>
										<label for="selected_sites_<?php echo intval( $website->id ); ?>">
											<?php echo stripslashes( $website->name ); ?>  <span class="url"><?php echo esc_html( $website->url ); ?></span>
										</label>
									</div>
									<?php
								}
							}
							MainWP_DB::free_result( $websites );
						endif;
						?>
				</div>
			</div>
		</div>
			<?php
		endif;
	}

	/**
	 * Method render_select_sites_group()
	 *
	 * Render selected sites group.
	 *
	 * @param array $groups Array of groups.
	 * @param mixed $tab_id Datatab ID.
	 * @param mixed $selected_groups Selected groups.
	 *
	 * @return void Render selected sites group html.
	 */
	public static function render_select_sites_group( $groups, $tab_id, $selected_groups ) {
		?>
		<div class="ui tab" data-tab="mainwp-select-groups-<?php echo $tab_id; ?>" id="mainwp-select-groups" select-by="group">
			<?php
			/**
			 * Action: mainwp_before_select_groups_list
			 *
			 * Fires before the Select Groups list.
			 *
			 * @param object $groups Object containing groups info.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_before_select_groups_list', $groups );
			?>
			<div id="mainwp-select-sites-body">
				<div class="ui relaxed divided list" id="mainwp-select-groups-list">
					<?php
					if ( 0 === count( $groups ) ) {
						?>
						<h2 class="ui icon header">
							<i class="folder open outline icon"></i>
							<div class="content"><?php esc_html_e( 'No Groups created!', 'mainwp' ); ?></div>
							<div class="ui divider hidden"></div>
							<a href="admin.php?page=ManageGroups" class="ui green button basic"><?php esc_html_e( 'Create Groups', 'mainwp' ); ?></a>
						</h2>
						<?php
					}
					foreach ( $groups as $group ) {
						$selected = in_array( $group->id, $selected_groups );
						?>
						<div class="mainwp_selected_groups_item ui item checkbox <?php echo ( $selected ? 'selected_groups_item_checked' : '' ); ?>">
							<input type="checkbox" name="selected_groups[]" value="<?php echo $group->id; ?>" id="selected_groups_<?php echo $group->id; ?>" <?php echo ( $selected ? 'checked="true"' : '' ); ?> />
							<label for="selected_groups_<?php echo $group->id; ?>">
								<?php echo stripslashes( $group->name ); ?>
							</label>
						</div>
						<?php
					}
					?>
				</div>
			</div>
			<?php
			/**
			 * Action: mainwp_after_select_groups_list
			 *
			 * Fires after the Select Groups list.
			 *
			 * @param object $groups Object containing groups info.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_after_select_groups_list', $groups );
			?>
		</div>
		<?php
	}

	/**
	 * Method render_top_header()
	 *
	 * Render top header.
	 *
	 * @param array $params Page parameters.
	 */
	public static function render_top_header( $params = array() ) {

		$title = isset( $params['title'] ) ? $params['title'] : '';

		/**
		 * Filter: mainwp_header_title
		 *
		 * Filter the MainWP page title in the header element.
		 *
		 * @since 4.0
		 */
		$title = apply_filters( 'mainwp_header_title', $title );

		$show_menu      = true;
		$show_new_items = true;

		if ( isset( $params['show_menu'] ) ) {
			$show_menu = $params['show_menu'];
		}

		/**
		 * Filter: mainwp_header_left
		 *
		 * Filter the MainWP header element left side content.
		 *
		 * @since 4.0
		 */
		$left = apply_filters( 'mainwp_header_left', $title );

		$right = self::render_header_actions();

		/**
		 * Filter: mainwp_header_right
		 *
		 * Filter the MainWP header element right side content.
		 *
		 * @since 4.0
		 */
		$right = apply_filters( 'mainwp_header_right', $right );

		if ( $show_menu ) {
			MainWP_Menu::render_left_menu();
		}

		$sidebarPosition = get_user_option( 'mainwp_sidebarPosition' );
		if ( false === $sidebarPosition ) {
			$sidebarPosition = 1;
		}

		$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );

		?>
		<div class="ui segment right sites sidebar" style="padding:0px" id="mainwp-sites-menu-sidebar">
			<div class="ui segment" style="margin-bottom:0px">
				<div class="ui header"><?php esc_html_e( 'Quick Site Shortcuts', 'mainwp' ); ?></div>
			</div>
			<div class="ui fitted divider"></div>
			<div class="ui segment" style="margin-bottom:0px">
				<div class="ui mini fluid icon input" <?php echo $websites ? '' : 'style="display: none;"'; ?> >
					<input type="text" id="mainwp-sites-menu-filter" value="" placeholder="<?php esc_attr_e( 'Type to filter your sites', 'mainwp' ); ?>" />
					<i class="filter icon"></i>
				</div>
			</div>
			<div class="ui fluid vertical accordion menu" id="mainwp-sites-sidebar-menu" style="margin-top:0px;border-radius:0px;box-shadow:none;">
				<?php foreach ( $websites as $website ) : ?>
					<div class="item mainwp-site-menu-item">
						<a class="title">
							<i class="dropdown icon"></i>
							<label><?php echo esc_html( $website['name'] ); ?></label>
						</a>
						<div class="content">
							<div class="ui link tiny list">
								<a class="item" href="<?php echo 'admin.php?page=managesites&dashboard=' . $website['id']; ?>">
									<i class="grid layout icon"></i>
									<?php esc_html_e( 'Overview', 'mainwp' ); ?>
								</a>
								<a class="item" href="<?php echo 'admin.php?page=managesites&updateid=' . $website['id']; ?>">
									<i class="sync alternate icon"></i>
									<?php esc_html_e( 'Updates', 'mainwp' ); ?>
								</a>
								<a class="item" href="<?php echo 'admin.php?page=managesites&id=' . $website['id']; ?>">
									<i class="edit icon"></i>
									<?php esc_html_e( 'Edit Site', 'mainwp' ); ?>
								</a>
								<a class="item" href="<?php echo 'admin.php?page=managesites&scanid=' . $website['id']; ?>">
									<i class="shield icon"></i>
									<?php esc_html_e( 'Security Scan', 'mainwp' ); ?>
								</a>
								<a class="item" href="<?php echo 'admin.php?page=SiteOpen&newWindow=yes&websiteid=' . $website['id']; ?>">
									<i class="sign-in icon"></i>
									<?php esc_html_e( 'Go to WP Admin', 'mainwp' ); ?>
								</a>
								<a class="item" href="<?php echo esc_url( $website['url'] ); ?>">
									<i class="globe icon"></i>
									<?php esc_html_e( 'Visit Site', 'mainwp' ); ?>
								</a>
								<?php
								/**
								 * Action: mainwp_quick_sites_shortcut
								 *
								 * Adds a new shortcut item in the Quick Sites Shortcuts sidebar menu.
								 *
								 * @param array $website Array containing the child site data.
								 *
								 * Suggested HTML markup:
								 *
								 * <a class="item" href="your custom URL">
								 *   <i class="your custom icon"></i>
								 *   Your custom label  text
								 * </a>
								 *
								 * @since 4.1
								 */
								do_action( 'mainwp_quick_sites_shortcut', $website );
								?>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<div class="ui segment right wide help sidebar" id="mainwp-documentation-sidebar">
			<div class="ui header"><?php esc_html_e( 'MainWP Documenation', 'mainwp' ); ?></div>
			<div class="ui hidden divider"></div>
			<?php
			/**
			 * Action: mainwp_help_sidebar_content
			 *
			 * Fires Help sidebar content
			 *
			 * @since 4.0
			 */
			do_action( 'mainwp_help_sidebar_content' );
			?>
			<div class="ui hidden divider"></div>
			<a href="https://mainwp.com/help/" class="ui big green fluid button"><?php esc_html_e( 'Help Documentation', 'mainwp' ); ?></a>
			<div class="ui hidden divider"></div>
			<div id="mainwp-sticky-help-button" class="" style="position: absolute; bottom: 1em; left: 1em; right: 1em;">
				<a href="https://mainwp.com/my-account/get-support/" target="_blank" class="ui fluid button"><?php esc_html_e( 'Still Need Help?', 'mainwp' ); ?></a>
			</div>
		</div>
		<?php
		/**
		 * Action: mainwp_before_mainwp_content_wrap
		 *
		 * Fires before the #mainwp-content-wrap element.
		 *
		 * @param array $websites Array containing the child site data.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_before_mainwp_content_wrap', $websites );
		?>
		<div class="mainwp-content-wrap <?php echo empty( $sidebarPosition ) ? 'mainwp-sidebar-left' : ''; ?>">
			<?php
			/**
			 * Action: mainwp_before_header
			 *
			 * Fires before the MainWP header element.
			 *
			 * @param array $websites Array containing the child site data.
			 *
			 * @since 4.0
			 */
			do_action( 'mainwp_before_header', $websites );
			?>
			<div id="mainwp-top-header" class="ui sticky">
				<div class="ui stackable grid">
					<div class="two column row">
						<div class="left floated column"><h4 class="mainwp-page-title"><?php echo $left; ?></h4></div>
						<div class="right floated column right aligned"><?php echo $right; ?></div>
					</div>
				</div>
			</div>
			<script type="text/javascript">
			jQuery( document ).ready( function () {
				jQuery( '.ui.sticky' ).sticky();
				jQuery( '#mainwp-help-sidebar' ).on( 'click', function() {
					jQuery( '.ui.help.sidebar' ).sidebar( {
						transition: 'overlay'
					} );
					jQuery( '.ui.help.sidebar' ).sidebar( 'toggle' );
					return false;
				} );
				jQuery( '#mainwp-sites-sidebar' ).on( 'click', function() {
					jQuery( '.ui.sites.sidebar' ).sidebar( {
						transition: 'overlay'
					} );
					jQuery( '.ui.sites.sidebar' ).sidebar( 'toggle' );
					return false;
				} );
				jQuery( '#mainwp-sites-sidebar-menu' ).accordion();
			} );
			</script>
			<?php
			/**
			 * Action: mainwp_after_header
			 *
			 * Fires after the MainWP header element.
			 *
			 * @param array $websites Array containing the child site data.
			 *
			 * @since 4.0
			 */
			do_action( 'mainwp_after_header', $websites );
			?>
		<?php
	}

	/**
	 * Method render_second_top_header()
	 *
	 * Render second top header.
	 *
	 * @param string $which Current page.
	 *
	 * @return void Render second top header html.
	 */
	public static function render_second_top_header( $which = '' ) {

		/**
		 * Action: mainwp_before_subheader
		 *
		 * Fires before the MainWP sub-header element.
		 *
		 * @since 4.0
		 */
		do_action( 'mainwp_before_subheader' );
		if ( has_action( 'mainwp_subheader_actions' ) || 'overview' === $which || 'managesites' === $which || 'monitoringsites' === $which ) {
			?>
			<div class="mainwp-sub-header">
				<?php if ( 'overview' === $which ) : ?>
				<div class="ui stackable grid">
					<div class="column full">
						<?php self::gen_groups_sites_selection(); ?>
					</div>
				</div>
				<?php endif; ?>
					<?php if ( 'managesites' === $which || 'monitoringsites' === $which ) : ?>
						<?php
						/**
						 * Action: mainwp_managesites_tabletop
						 *
						 * Fires at the table top on the Manage Sites and Monitoring page.
						 *
						 * @since 4.0
						 */
						do_action( 'mainwp_managesites_tabletop' );
						?>
					<?php else : ?>
						<?php
						/**
						 * Action: mainwp_subheader_actions
						 *
						 * Fires at the subheader element to hook available actions.
						 *
						 * @since 4.0
						 */
						do_action( 'mainwp_subheader_actions' );
						?>
				<?php endif; ?>
			</div>
			<?php
		}
		/**
		 * Action: mainwp_after_subheader
		 *
		 * Fires after the MainWP sub-header element.
		 *
		 * @since 4.0
		 */
		do_action( 'mainwp_after_subheader' );
	}

	/**
	 * Method gen_groups_sites_selection()
	 *
	 * Generate group sites selection box.
	 *
	 * @return void Render group sites selection box.
	 */
	public static function gen_groups_sites_selection() {
		$sql      = MainWP_DB::instance()->get_sql_websites_for_current_user( false, null, 'wp.url', false, false, null, false, array( 'premium_upgrades', 'plugins_outdate_dismissed', 'themes_outdate_dismissed', 'plugins_outdate_info', 'themes_outdate_info', 'favi_icon' ) );
		$websites = MainWP_DB::instance()->query( $sql );
		$g        = isset( $_GET['g'] ) ? intval( $_GET['g'] ) : -1;
		$s        = isset( $_GET['dashboard'] ) ? intval( $_GET['dashboard'] ) : -1;
		?>
		<div class="column full wide">
			<select id="mainwp_top_quick_jump_group" class="ui dropdown">
				<option value="" class="item"><?php esc_html_e( 'All Groups', 'mainwp' ); ?></option>
				<option <?php echo ( -1 === $g ) ? 'selected' : ''; ?> value="-1" class="item"><?php esc_html_e( 'All Groups', 'mainwp' ); ?></option>
				<?php
				$groups = MainWP_DB_Common::instance()->get_groups_for_manage_sites();
				foreach ( $groups as $group ) {
					?>
					<option class="item" <?php echo ( $g == $group->id ) ? 'selected' : ''; ?> value="<?php echo $group->id; ?>"><?php echo stripslashes( $group->name ); ?></option>
					<?php
				}
				?>
			</select>
			<select class="ui dropdown" id="mainwp_top_quick_jump_page">
				<option value="" class="item"><?php esc_html_e( 'All Sites', 'mainwp' ); ?></option>
				<option <?php echo ( -1 === $s ) ? 'selected' : ''; ?> value="-1" class="item" ><?php esc_html_e( 'All Sites', 'mainwp' ); ?></option>
				<?php
				while ( $websites && ( $website   = MainWP_DB::fetch_object( $websites ) ) ) {
					?>
					<option value="<?php echo intval( $website->id ); ?>" <?php echo ( $s == $website->id ) ? 'selected' : ''; ?> class="item" ><?php echo stripslashes( $website->name ); ?></option>
					<?php
				}
				?>
			</select>
		</div>
		<script type="text/javascript">
			jQuery( document ).on( 'change', '#mainwp_top_quick_jump_group', function () {
				var jumpid = jQuery( this ).val();
				window.location = 'admin.php?page=managesites&g='  + jumpid;
			} );
			jQuery( document ).on( 'change', '#mainwp_top_quick_jump_page', function () {
				var jumpid = jQuery( this ).val();
				if ( jumpid == -1 )
					window.location = 'admin.php?page=managesites&s='  + jumpid;
				else
					window.location = 'admin.php?page=managesites&dashboard='  + jumpid;
			} );
		</script>
		<?php
		MainWP_DB::free_result( $websites );
	}

	/**
	 * Method render_header_actions()
	 *
	 * Render header action buttons,
	 * (Sync|Add|Options|Community|User|Updates).
	 *
	 * @return mixed $output Render header action buttons html.
	 */
	public static function render_header_actions() {
		$sites_count = MainWP_DB::instance()->get_websites_count();
		$website_id  = '';
		ob_start();
		?>
		<button class="ui button green <?php echo ( 0 < $sites_count ? '' : 'disabled' ); ?>" id="mainwp-sync-sites" data-inverted="" data-position="bottom right" data-tooltip="<?php esc_attr_e( 'Get fresh data from your child sites.', 'mainwp' ); ?>">
			<?php
			/**
			 * Filter: mainwp_main_sync_button_text
			 *
			 * Filters the Sync Dashboard with Child Sites button text.
			 *
			 * @since 4.1
			 */
			echo esc_html( apply_filters( 'mainwp_main_sync_button_text', __( 'Sync Dashboard with Child Sites', 'mainwp' ) ) );
			?>
		</button>
		<div class="ui <?php echo ( 0 == $sites_count ? 'green' : '' ); ?> buttons" id="mainwp-add-new-buttons">
			<a class="ui button" data-inverted="" data-position="bottom right" data-tooltip="<?php esc_attr_e( 'Add a new Website to your MainWP Dashboard', 'mainwp' ); ?>" href="<?php echo esc_attr( admin_url( 'admin.php?page=managesites&do=new' ) ); ?>"><?php esc_html_e( 'Add New', 'mainwp' ); ?></a>
			<div class="ui floating dropdown icon button"  style="z-index: 999;" data-inverted="" data-position="bottom right" data-tooltip="<?php esc_attr_e( 'More options', 'mainwp' ); ?>">
				<i class="dropdown icon"></i>
				<div class="menu">
					<a class="item" data-inverted="" data-position="left center" data-tooltip="<?php esc_attr_e( 'Add a new Website to your MainWP Dashboard', 'mainwp' ); ?>" href="<?php echo esc_attr( admin_url( 'admin.php?page=managesites&do=new' ) ); ?>"><?php esc_html_e( 'Website', 'mainwp' ); ?></a>
					<a class="item" data-inverted="" data-position="left center" data-tooltip="<?php esc_attr_e( 'Add a new Post to your child sites', 'mainwp' ); ?>" href="<?php echo esc_attr( admin_url( 'admin.php?page=PostBulkAdd' ) ); ?>"><?php esc_html_e( 'Post', 'mainwp' ); ?></a>
					<a class="item" data-inverted="" data-position="left center" data-tooltip="<?php esc_attr_e( 'Add a new Page to your child sites', 'mainwp' ); ?>" href="<?php echo esc_attr( admin_url( 'admin.php?page=PageBulkAdd' ) ); ?>"><?php esc_html_e( 'Page', 'mainwp' ); ?></a>
					<a class="item" data-inverted="" data-position="left center" data-tooltip="<?php esc_attr_e( 'Install a new Plugin to your child sites', 'mainwp' ); ?>" href="<?php echo esc_attr( admin_url( 'admin.php?page=PluginsInstall' ) ); ?>"><?php esc_html_e( 'Plugin', 'mainwp' ); ?></a>
					<a class="item" data-inverted="" data-position="left center" data-tooltip="<?php esc_attr_e( 'Install a new Theme to your child sites', 'mainwp' ); ?>" href="<?php echo esc_attr( admin_url( 'admin.php?page=ThemesInstall' ) ); ?>"><?php esc_html_e( 'Theme', 'mainwp' ); ?></a>
					<a class="item" data-inverted="" data-position="left center" data-tooltip="<?php esc_attr_e( 'Create a new User to your child sites', 'mainwp' ); ?>" href="<?php echo esc_attr( admin_url( 'admin.php?page=UserBulkAdd' ) ); ?>"><?php esc_html_e( 'User', 'mainwp' ); ?></a>
					<?php
					/**
					 * Action: mainwp_add_new_menu_option
					 *
					 * Fires at bottom of the Add New menu options list.
					 *
					 * Suggested HTML markup:
					 * <a class="item" href="your custom URL">Your custom label</a>
					 *
					 * @since 4.1
					 */
					do_action( 'mainwp_add_new_menu_option' );
					?>
				</div>
			</div>
		</div>
		<?php if ( isset( $_GET['dashboard'] ) ) : ?>
			<?php $website_id = $_GET['dashboard']; ?>
			<a href="<?php echo 'admin.php?page=SiteOpen&newWindow=yes&websiteid=' . $website_id; ?>" data-tooltip="<?php esc_attr_e( 'Jump to the site WP Admin', 'mainwp' ); ?>"  data-position="bottom right"  data-inverted="" class="open_newwindow_wpadmin ui green basic icon button" target="_blank"><i class="sign in icon"></i></a>
		<?php endif; ?>
		<?php if ( ( isset( $_GET['page'] ) && 'mainwp_tab' === $_GET['page'] ) || isset( $_GET['dashboard'] ) ) : ?>
		<a class="ui button basic icon" onclick="jQuery( '#mainwp-overview-screen-options-modal' ).modal( 'show' ); return false;" data-inverted="" data-position="bottom right" href="#" target="_blank" data-tooltip="<?php esc_html_e( 'Screen Options', 'mainwp' ); ?>">
			<i class="cog icon"></i>
		</a>
		<?php endif; ?>
		<?php
		/**
		 * Filter: mainwp_header_actions_right
		 *
		 * Filters the MainWP header element actions.
		 *
		 * @since 4.0
		 */
		$actions = apply_filters( 'mainwp_header_actions_right', '' );
		if ( ! empty( $actions ) ) {
			echo $actions;
		}
		?>
		<a class="ui button basic icon" id="mainwp-sites-sidebar" data-inverted="" data-position="bottom right" href="#" target="_blank" data-tooltip="<?php esc_attr_e( 'Quick sites shortcuts', 'mainwp' ); ?>">
			<i class="globe icon"></i>
		</a>

		<a class="ui button basic icon" id="mainwp-help-sidebar" data-inverted="" data-position="bottom right" href="#" target="_blank" data-tooltip="<?php esc_attr_e( 'Need help?', 'mainwp' ); ?>">
			<i class="life ring icon"></i>
		</a>

		<a class="ui button basic icon" data-inverted="" data-position="bottom right" href="https://meta.mainwp.com/" target="_blank" data-tooltip="<?php esc_attr_e( 'MainWP Community', 'mainwp' ); ?>">
			<i class="discourse icon"></i>
		</a>

		<a class="ui button basic icon" data-inverted="" data-position="bottom right" data-tooltip="<?php esc_attr_e( 'Go to your MainWP Account at MainWP.com', 'mainwp' ); ?>" target="_blank" href="https://mainwp.com/my-account/">
			<i class="user icon"></i>
		</a>

		<?php
		$all_updates = wp_get_update_data();
		if ( is_array( $all_updates ) && isset( $all_updates['counts']['total'] ) && 0 < $all_updates['counts']['total'] ) {
			?>
			<a class="ui red icon button" data-inverted="" data-position="bottom right" data-tooltip="<?php esc_attr_e( 'Your MainWP Dashboard sites needs your attention. Please check the available updates', 'mainwp' ); ?>" href="update-core.php">
				<i class="exclamation triangle icon"></i>
			</a>
			<?php
		}
		?>
		<?php if ( get_option( 'mainwp_show_usersnap', false ) ) : ?>
			<a class="ui button black icon" id="usersnap-bug-report-button" data-position="bottom right" data-inverted="" data-tooltip="<?php esc_attr_e( 'Click here (or use Ctrl + U keyboard shortcut) to open the Bug reporting mode.', 'mainwp' ); ?>" target="_blank" href="#">
				<i class="bug icon"></i>
			</a>
			<?php endif; ?>
		<?php
		$output = ob_get_clean();
		return $output;
	}

	/**
	 * Method render_page_navigation()
	 *
	 * Render page navigation.
	 *
	 * @param array $subitems [access, active, style].
	 * @param null  $name_caller Menu Name.
	 */
	public static function render_page_navigation( $subitems = array(), $name_caller = null ) {

		/**
		 * Filter: mainwp_page_navigation
		 *
		 * Filters MainWP page navigation menu items.
		 *
		 * @since 4.0
		 */
		$subitems = apply_filters( 'mainwp_page_navigation', $subitems, $name_caller );
		?>
		<div id="mainwp-page-navigation-wrapper">
			<div class="ui secondary green pointing menu stackable mainwp-page-navigation">
				<?php

				if ( is_array( $subitems ) ) {
					foreach ( $subitems as $item ) {

						if ( isset( $item['access'] ) && ! $item['access'] ) {
							continue;
						}

						$active = '';
						if ( isset( $item['active'] ) && $item['active'] ) {
							$active = 'active';
						}
						$style = '';
						if ( isset( $item['style'] ) ) {
							$style = 'style="' . $item['style'] . '"';
						}

						?>
						<a class="<?php echo esc_attr( $active ); ?> item" <?php echo esc_attr( $style ); ?> href="<?php echo esc_url( $item['href'] ); ?>"><?php echo esc_html( $item['title'] ); ?></a>
						<?php
					}
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Method render_header()
	 *
	 * Render page title.
	 *
	 * @param string $title Page title.
	 *
	 * @return void Render page title and hidden divider html.
	 */
	public static function render_header( $title = '' ) {
		self::render_top_header( array( 'title' => $title ) );
		echo '<div class="ui hidden clearing fitted divider"></div>';
		echo '<div class="wrap">';
	}

	/**
	 * Method render_footer()
	 *
	 * Render page footer.
	 *
	 * @return void Render closing tags for page container.
	 */
	public static function render_footer() {
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Method add_widget_box()
	 *
	 * Customize WordPress add_meta_box() function.
	 *
	 * @param mixed       $id Widget ID parameter.
	 * @param mixed       $callback Callback function.
	 * @param null        $screen Current page.
	 * @param string|null $context right|null. If 3 columns then = 'middle'.
	 * @param null        $title Widget title.
	 * @param string      $priority high|core|default|low, Default: default.
	 *
	 * @return void Sets Global $mainwp_widget_boxes[ $page ][ $context ][ $priority ][ $id ].
	 */
	public static function add_widget_box( $id, $callback, $screen = null, $context = null, $title = null, $priority = 'default' ) { // phpcs:ignore -- complex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated.
		global $mainwp_widget_boxes;

		$page = MainWP_System_Utility::get_page_id( $screen );

		if ( empty( $page ) ) {
			return;
		}

		$overviewColumns = get_option( 'mainwp_number_overview_columns', 2 );
		$contexts        = array( 'left', 'right' );
		if ( 3 == $overviewColumns ) {
			$contexts[] = 'middle';
		}

		if ( null === $context || ! in_array( $context, $contexts ) ) {
			$context = 'right';
		}

		if ( ! isset( $mainwp_widget_boxes ) ) {
			$mainwp_widget_boxes = array();
		}
		if ( ! isset( $mainwp_widget_boxes[ $page ] ) ) {
			$mainwp_widget_boxes[ $page ] = array();
		}
		if ( ! isset( $mainwp_widget_boxes[ $page ][ $context ] ) ) {
			$mainwp_widget_boxes[ $page ][ $context ] = array();
		}

		foreach ( array_keys( $mainwp_widget_boxes[ $page ] ) as $a_context ) {
			foreach ( array( 'high', 'core', 'default', 'low' ) as $a_priority ) {
				if ( ! isset( $mainwp_widget_boxes[ $page ][ $a_context ][ $a_priority ][ $id ] ) ) {
					continue;
				}

				// If box previously deleted, don't add.
				if ( false == $mainwp_widget_boxes[ $page ][ $a_context ][ $a_priority ][ $id ] ) {
					return;
				}

				// If no priority given and id already present, use existing priority.
				if ( empty( $priority ) ) {
					$priority = $a_priority;

					/*
					* Else, if we're adding to the sorted priority, we don't know the title
					* or callback. Grab them from the previously added context/priority.
					*/
				} elseif ( 'sorted' == $priority ) {
					$title    = $mainwp_widget_boxes[ $page ][ $a_context ][ $a_priority ][ $id ]['title'];
					$callback = $mainwp_widget_boxes[ $page ][ $a_context ][ $a_priority ][ $id ]['callback'];
				}

				// An id can be in only one context.
				if ( $priority != $a_priority || $context != $a_context ) {
					unset( $mainwp_widget_boxes[ $page ][ $a_context ][ $a_priority ][ $id ] );
				}
			}
		}

		if ( ! isset( $mainwp_widget_boxes[ $page ][ $context ] ) ) {
			$mainwp_widget_boxes[ $page ][ $context ] = array();
		}

		if ( empty( $priority ) ) {
			$priority = 'default';
		}

		if ( empty( $title ) ) {
			$title = 'No Title';
		}
		$mainwp_widget_boxes[ $page ][ $context ][ $priority ][ $id ] = array(
			'id'       => $id,
			'title'    => $title,
			'callback' => $callback,
		);
	}

	/**
	 * Method do_widget_boxes()
	 *
	 * Customize WordPress do_meta_boxes() function.
	 *
	 * @param mixed       $screen Current page.
	 * @param string|null $context right|null. If 3 columns then = 'middle'.
	 * @param string      $object Empty string.
	 *
	 * @return void Renders widget container box.
	 */
	public static function do_widget_boxes( $screen, $context = null, $object = '' ) {
		global $mainwp_widget_boxes;
		static $already_sorted = false;

		$page = MainWP_System_Utility::get_page_id( $screen );

		if ( empty( $page ) ) {
			return;
		}

		$overviewColumns = get_option( 'mainwp_number_overview_columns', 2 );
		$contexts        = array( 'left', 'right' );
		if ( 3 == $overviewColumns ) {
			$contexts[] = 'middle';
		}

		if ( null == $context || ! in_array( $context, $contexts ) ) {
			$context = 'right';
		}
		$sorted = get_user_option( 'mainwp_widgets_sorted_' . $page );
		// Grab the ones the user has manually sorted. Pull them out of their previous context/priority and into the one the user chose.
		if ( ! $already_sorted && $sorted ) {
			foreach ( explode( ',', $sorted ) as $val ) {
				list( $widget_context, $id ) = explode( ':', $val );
				if ( ! empty( $widget_context ) && ! empty( $id ) ) {
					self::add_widget_box( $id, null, $screen, $widget_context, null, $priority = 'sorted' );
					$already_sorted = true;
				}
			}
		}

		$hide_widgets = get_user_option( 'mainwp_settings_hide_widgets' );
		if ( ! is_array( $hide_widgets ) ) {
			$hide_widgets = array();
		}

		if ( isset( $mainwp_widget_boxes[ $page ][ $context ] ) ) {
			foreach ( array( 'high', 'sorted', 'core', 'default', 'low' ) as $priority ) {
				if ( isset( $mainwp_widget_boxes[ $page ][ $context ][ $priority ] ) ) {
					foreach ( (array) $mainwp_widget_boxes[ $page ][ $context ][ $priority ] as $box ) {
						if ( false == $box || ! isset( $box['callback'] ) ) {
							continue;
						}

						// to avoid hidden widgets.
						if ( in_array( $box['id'], $hide_widgets ) ) {
							continue;
						}

						echo '<div class="column grid-item" id="widget-' . esc_html( $box['id'] ) . '">' . "\n";
						echo '<div class="ui segment mainwp-widget" >' . "\n";

						/**
						* Action: mainwp_widget_content_top
						*
						* Fires at the top of widget content.
						*
						* @since 4.1
						*/
						do_action( 'mainwp_widget_content_top', $box, $page );

						call_user_func( $box['callback'], $object, $box );

						/**
						* Action: mainwp_widget_content_bottom
						*
						* Fires at the bottom of widget content.
						*
						* @since 4.1
						*/
						do_action( 'mainwp_widget_content_bottom', $box, $page );

						echo "</div>\n";
						echo "</div>\n";
					}
				}
			}
		}
	}

	/**
	 * Method render_empty_bulk_actions()
	 *
	 * Render empty bulk actions when drop down is disabled.
	 */
	public static function render_empty_bulk_actions() {
		?>
		<?php esc_html_e( 'Bulk Actions: ', 'mainwp' ); ?>
		<div class="ui disabled dropdown" id="mainwp-bulk-actions">
			<?php esc_html_e( 'Bulk Actions', 'mainwp' ); ?> <i class="dropdown icon"></i>
			<div class="menu"></div>
		</div>
		<button class="ui tiny basic disabled button" href="javascript:void(0)" ><?php esc_html_e( 'Apply', 'mainwp' ); ?></button>
		<?php
	}

	/**
	 * Method render_modal_install_plugin_theme()
	 *
	 * Render modal window for installing plugins & themes.
	 *
	 * @param string $what Which window to render, plugin|theme.
	 */
	public static function render_modal_install_plugin_theme( $what = 'plugin' ) {
		?>
		<div id="plugintheme-installation-progress-modal" class="ui modal">
			<div class="header">
			<?php
			if ( 'plugin' === $what ) {
				esc_html_e( 'Plugin Installation', 'mainwp' );
			} elseif ( 'theme' === $what ) {
				esc_html_e( 'Theme Installation', 'mainwp' );
			}
			?>
			</div>
			<div class="scrolling content">
				<?php
				/**
				 * Action: mainwp_before_plugin_theme_install_progress
				 *
				 * Fires before the progress list in the install modal element.
				 *
				 * @since 4.1
				 */
				do_action( 'mainwp_before_plugin_theme_install_progress' );
				?>
				<div id="plugintheme-installation-queue" ></div>
				<?php
				/**
				 * Action: mainwp_after_plugin_theme_install_progress
				 *
				 * Fires after the progress list in the install modal element.
				 *
				 * @since 4.1
				 */
				do_action( 'mainwp_after_plugin_theme_install_progress' );
				?>
			</div>
			<div class="actions">
				<div class="ui cancel button"><?php esc_html_e( 'Close', 'mainwp' ); ?></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Method render_show_all_updates_button()
	 *
	 * Render show all updates button.
	 *
	 * @return void Render Show All Updates button html.
	 */
	public static function render_show_all_updates_button() {
		?>
		<a href="javascript:void(0)" class="ui mini button trigger-all-accordion">
			<?php
			/**
			 * Filter: mainwp_show_all_updates_button_text
			 *
			 * Filters the Show All Updates button text.
			 *
			 * @since 4.1
			 */
			echo esc_html( apply_filters( 'mainwp_show_all_updates_button_text', __( 'Show All Updates', 'mainwp' ) ) );
			?>
		</a>
		<?php
	}

	/**
	 * Method render_sorting_icons()
	 *
	 * Render sorting up & down icons.
	 *
	 * @return void Render Sort up & down incon html.
	 */
	public static function render_sorting_icons() {
		?>
		<i class="sort icon"></i><i class="sort up icon"></i><i class="sort down icon">
		<?php
	}

	/**
	 * Method render_modal_edit_notes()
	 *
	 * Render modal window for edit notes.
	 *
	 * @param string $what What modal window to render. Default = site.
	 *
	 * @return void
	 */
	public static function render_modal_edit_notes( $what = 'site' ) {
		?>
		<div id="mainwp-notes" class="ui modal">
			<div class="header"><?php esc_html_e( 'Notes', 'mainwp' ); ?></div>
			<div class="content" id="mainwp-notes-content">
				<?php
				/**
				 * Action: mainwp_before_edit_site_note
				 *
				 * Fires before the site note content in the Edit Note modal element.
				 *
				 * @since 4.1
				 */
				do_action( 'mainwp_before_edit_site_note' );
				?>
				<div id="mainwp-notes-html"></div>
				<div id="mainwp-notes-editor" class="ui form" style="display:none;">
					<div class="field">
						<label><?php esc_html_e( 'Edit note', 'mainwp' ); ?></label>
						<textarea id="mainwp-notes-note"></textarea>
					</div>
					<div><?php esc_html_e( 'Allowed HTML tags:', 'mainwp' ); ?> &lt;p&gt;, &lt;strong&gt;, &lt;em&gt;, &lt;br&gt;, &lt;hr&gt;, &lt;a&gt;, &lt;ul&gt;, &lt;ol&gt;, &lt;li&gt;, &lt;h1&gt;, &lt;h2&gt; </div>
				</div>
				<?php
				/**
				 * Action: mainwp_after_edit_site_note
				 *
				 * Fires after the site note content in the Edit Note modal element.
				 *
				 * @since 4.1
				 */
				do_action( 'mainwp_after_edit_site_note' );
				?>
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

	/**
	 * Method usersnap_integration()
	 *
	 * Integrate UserSnap.
	 *
	 * @return bool True, Inject UserSnap meta data. False if $showtime is false.
	 */
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

	/**
	 * Method render_screen_options()
	 *
	 * Render modal window for Screen Options.
	 *
	 * @param boolean $setting_page Default: True. Widgets that you want to hide in the MainWP Overview page.
	 *
	 * @return void  Render modal window for Screen Options html.
	 */
	public static function render_screen_options( $setting_page = true ) {

		$default_widgets = array(
			'overview'          => __( 'Updates Overview', 'mainwp' ),
			'recent_posts'      => __( 'Recent Posts', 'mainwp' ),
			'recent_pages'      => __( 'Recent Pages', 'mainwp' ),
			'plugins'           => __( 'Plugins (Individual Site Overview page)', 'mainwp' ),
			'themes'            => __( 'Themes (Individual Site Overview page)', 'mainwp' ),
			'connection_status' => __( 'Connection Status', 'mainwp' ),
			'security_issues'   => __( 'Security Issues', 'mainwp' ),
			'notes'             => __( 'Notes (Individual Site Overview page)', 'mainwp' ),
			'child_site_info'   => __( 'Child site info (Individual Site Overview page)', 'mainwp' ),
		);

		$custom_opts = apply_filters_deprecated( 'mainwp-widgets-screen-options', array( array() ), '4.0.7.2', 'mainwp_widgets_screen_options' );  // @deprecated Use 'mainwp_widgets_screen_options' instead.

		/**
		 * Filter: mainwp_widgets_screen_options
		 *
		 * Filters available widgets on the Overview page allowing users to unsent unwanted widgets.
		 *
		 * @since 4.0
		 */
		$custom_opts = apply_filters( 'mainwp_widgets_screen_options', $custom_opts );

		if ( is_array( $custom_opts ) && 0 < count( $custom_opts ) ) {
			$default_widgets = array_merge( $default_widgets, $custom_opts );
		}

		$hide_widgets = get_user_option( 'mainwp_settings_hide_widgets' );
		if ( ! is_array( $hide_widgets ) ) {
			$hide_widgets = array();
		}

		/**
		 * Action: mainwp_screen_options_modal_top
		 *
		 * Fires at the top of the Screen Options modal element.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_screen_options_modal_top' );
		?>
		<div class="ui grid field">
			<label class="six wide column middle aligned"><?php esc_html_e( 'Hide the Update Everything button', 'mainwp' ); ?></label>
			<div class="ten wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'If enabled, the "Update Everything" button will be hidden in the Updates Overview widget.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
				<input type="checkbox" name="hide_update_everything" <?php echo ( ( 1 == get_option( 'mainwp_hide_update_everything' ) ) ? 'checked="true"' : '' ); ?> />
			</div>
		</div>
		<?php
		$overviewColumns = get_option( 'mainwp_number_overview_columns', 2 );
		if ( 2 != $overviewColumns && 3 != $overviewColumns ) {
			$overviewColumns = 2;
		}

		?>
		<div class="ui grid field">
			<label class="six wide column middle aligned"><?php esc_html_e( 'Widgets columns', 'mainwp' ); ?></label>
			<div class="ten wide column">
				<div class="ui radio checkbox">
					<input type="radio" name="number_overview_columns" required="required" <?php echo ( 2 == $overviewColumns ? 'checked="true"' : '' ); ?> value="2">
					<label><?php esc_html_e( 'Show widgets in 2 columns', 'mainwp' ); ?></label>
				</div>
					<div class="ui fitted hidden divider"></div>
				<div class="ui radio checkbox">
					<input type="radio" name="number_overview_columns" required="required" <?php echo ( 3 == $overviewColumns ? 'checked="true"' : '' ); ?> value="3">
					<label><?php esc_html_e( 'Show widgets in 3 columns', 'mainwp' ); ?></label>
				</div>
			</div>
		</div>

		<div class="ui grid field">
			<label class="six wide column"><?php esc_html_e( 'Hide unwanted widgets', 'mainwp' ); ?></label>
			<div class="ten wide column" <?php echo $setting_page ? 'data-tooltip="' . esc_attr_e( 'Select widgets that you want to hide in the MainWP Overview page.', 'mainwp' ) . '"' : ''; ?> data-inverted="" data-position="top left">
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
				<input type="checkbox" name="mainwp_show_usersnap" <?php echo ( ( false != get_option( 'mainwp_show_usersnap' ) ) ? 'checked="true"' : '' ); ?> />
			</div>
		</div>
		<?php
		/**
		 * Action: mainwp_screen_options_modal_bottom
		 *
		 * Fires at the bottom of the Screen Options modal element.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_screen_options_modal_bottom' );
	}
}
