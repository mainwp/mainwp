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
	 * @deprecated 4.3 Use MainWP_UI_Select_Sites::select_sites_box().
	 *
	 * @param string  $type Input type, radio.
	 * @param bool    $show_group Whether or not to show group, Default: true.
	 * @param bool    $show_select_all Whether to show select all.
	 * @param string  $class Default = ''.
	 * @param string  $style Default = ''.
	 * @param array   $selected_websites Selected Child Sites.
	 * @param array   $selected_groups Selected Groups.
	 * @param bool    $enableOfflineSites (bool) True, if offline sites is enabled. False if not.
	 * @param integer $postId Post Meta ID.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::maybe_unserialyze()
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

		if ( empty( $selected_websites ) && isset( $_GET['selected_sites'] ) && ! empty( $_GET['selected_sites'] ) ) {
			$selected_sites    = explode( '-', sanitize_text_field( wp_unslash( $_GET['selected_sites'] ) ) ); // sanitize ok.
			$selected_sites    = array_map( 'intval', $selected_sites );
			$selected_websites = array_filter( $selected_sites );
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
	 * @deprecated 4.3 Use MainWP_UI_Select_Sites::select_sites_box_body().
	 *
	 * @param array  $selected_websites Child Site that are selected.
	 * @param array  $selected_groups Group that are selected.
	 * @param string $type Selector type.
	 * @param bool   $show_group         Whether or not to show group, Default: true.
	 * @param bool   $show_select_all    Whether or not to show select all, Default: true.
	 * @param bool   $updateQty          Whether or not to update quantity, Default = false.
	 * @param bool   $enableOfflineSites Whether or not to enable offline sites, Default: true.
	 * @param int    $postId             Post ID.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::query()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_for_current_user()
	 */
	public static function select_sites_box_body( &$selected_websites = array(), &$selected_groups = array(), $type = 'checkbox', $show_group = true, $show_select_all = true, $updateQty = false, $enableOfflineSites = false, $postId = 0 ) {

		if ( 'all' !== $selected_websites && ! is_array( $selected_websites ) ) {
			$selected_websites = array();
		}

		if ( ! is_array( $selected_groups ) ) {
			$selected_groups = array();
		}

		$selectedby = 'site';
		if ( ! empty( $selected_groups ) ) {
			$selectedby = 'group';
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

		self::render_select_sites_header( $tab_id, $staging_enabled, $selectedby, $show_group );
		?>
		<div class="ui tab <?php echo 'site' == $selectedby ? 'active' : ''; ?>" data-tab="mainwp-select-sites-<?php echo $tab_id; ?>" id="mainwp-select-sites">
		<?php
		self::render_select_sites( $websites, $type, $selected_websites, $enableOfflineSites, $edit_site_id, $show_select_all );
		?>
		</div>
		<?php if ( $staging_enabled ) { ?>
		<div class="ui tab <?php echo 'staging' == $selectedby ? 'active' : ''; ?>" data-tab="mainwp-select-staging-sites-<?php echo $tab_id; ?>">
			<?php
			self::render_select_sites_staging( $selected_websites, $edit_site_id, $type );
			?>
		</div>
			<?php
		}
		if ( $show_group ) {
			?>
			<div class="ui tab <?php echo 'group' == $selectedby ? 'active' : ''; ?>" data-tab="mainwp-select-groups-<?php echo $tab_id; ?>" id="mainwp-select-groups">
			<?php
			self::render_select_sites_group( $groups, $selected_groups, $type );
			?>
			</div>
			<?php
		}
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
	 * @param array $selectedby Selected by.
	 * @param bool  $show_group         Whether or not to show group, Default: true.
	 * @param bool  $show_client         Whether or not to show client, Default: true.
	 *
	 * @todo Move to view folder.
	 */
	public static function render_select_sites_header( $tab_id, $staging_enabled, $selectedby, $show_group = true, $show_client = false ) {

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
					<div class="ui mini fluid icon input">
						<input type="text" id="mainwp-select-sites-filter" value="" placeholder="<?php esc_attr_e( 'Type to filter your sites', 'mainwp' ); ?>" <?php echo 'site' == $selectedby ? '' : 'style="display: none;"'; ?> />
						<i class="filter icon"></i>
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
		<input type="hidden" name="select_by" id="select_by" value="<?php echo esc_attr( $selectedby ); ?>"/>
		<input type="hidden" id="select_sites_tab" value="<?php echo esc_attr( $selectedby ); ?>"/>
		<div id="mainwp-select-sites-header">
			<div class="ui pointing green secondary menu">
				<a class="item ui tab <?php echo ( 'site' == $selectedby ) ? 'active' : ''; ?>" data-tab="mainwp-select-sites-<?php echo $tab_id; ?>" select-by="site"><?php esc_html_e( 'Sites', 'mainwp' ); ?></a>
				<?php if ( $show_group ) : ?>
				<a class="item ui tab <?php echo ( 'group' == $selectedby ) ? 'active' : ''; ?>" data-tab="mainwp-select-groups-<?php echo $tab_id; ?>" select-by="group"><?php esc_html_e( 'Tags', 'mainwp' ); ?></a>
				<?php endif; ?>
				<?php if ( $show_client ) : ?>
				<a class="item ui tab <?php echo ( 'client' == $selectedby ) ? 'active' : ''; ?>" data-tab="mainwp-select-clients-<?php echo $tab_id; ?>" select-by="client"><?php esc_html_e( 'Clients', 'mainwp' ); ?></a>
				<?php endif; ?>
				<?php if ( $staging_enabled ) : ?>
					<a class="item ui tab" data-tab="mainwp-select-staging-sites-<?php echo $tab_id; ?>" select-by="staging"><?php esc_html_e( 'Staging', 'mainwp' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Method render_select_sites()
	 *
	 * @param object $websites Object containing child sites info.
	 * @param string $type Selector type.
	 * @param mixed  $selected_websites Selected Child Sites.
	 * @param bool   $enableOfflineSites (bool) True, if offline sites is enabled. False if not.
	 * @param mixed  $edit_site_id Child Site ID to edit.
	 * @param bool   $show_select_all    Whether or not to show select all, Default: true.
	 * @param mixed  $add_edit_client_id Show enable sites for client. Default: false, 0: add new client, 0 <: edit client.
	 *
	 * @return void Render Select Sites html.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_DB::free_result()
	 */
	public static function render_select_sites( $websites, $type, $selected_websites, $enableOfflineSites, $edit_site_id, $show_select_all, $add_edit_client_id = false ) {
		?>
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
								$enable_site = true;
								if ( false === $add_edit_client_id ) {
									$enable_site = true;
								} elseif ( 0 === intval( $add_edit_client_id ) && false !== $add_edit_client_id ) {
									if ( 0 < intval( $website->client_id ) ) {
										$enable_site = false;
									}
								} elseif ( is_numeric( $add_edit_client_id ) && intval( $add_edit_client_id ) > 0 ) {
									if ( 0 < intval( $website->client_id ) && intval( $website->client_id ) !== intval( $add_edit_client_id ) ) {
										$enable_site = false;
									}
								}

								$selected = false;
								if ( ( '' == $website->sync_errors || $enableOfflineSites ) && ! MainWP_System_Utility::is_suspended_site( $website ) && $enable_site ) {
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
									<div title="<?php echo esc_html( $website->url ); ?>" class="mainwp_selected_sites_item ui <?php echo esc_html( $type ); ?> item <?php echo ( $selected ? 'selected_sites_item_checked' : '' ); ?>">
										<input <?php echo $disabled; ?> type="<?php echo $type; ?>" name="<?php echo ( 'radio' === $type ? 'selected_sites' : 'selected_sites[]' ); ?>" siteid="<?php echo intval( $website->id ); ?>" value="<?php echo intval( $website->id ); ?>" id="selected_sites_<?php echo intval( $website->id ); ?>" <?php echo ( $selected ? 'checked="true"' : '' ); ?> />
										<label for="selected_sites_<?php echo intval( $website->id ); ?>">
											<?php echo stripslashes( $website->name ); ?>  <span class="url"><?php echo esc_html( $website->url ); ?></span>
										</label>
									</div>
									<?php
								} else {
									?>
								<div title="<?php echo esc_html( $website->url ); ?>" class="mainwp_selected_sites_item item ui <?php echo esc_html( $type ); ?> <?php echo ( $selected ? 'selected_sites_item_checked' : '' ); ?>">
									<input type="<?php echo esc_html( $type ); ?>" disabled="disabled"/>
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
		<?php
	}

	/**
	 * Method render_select_sites_staging()
	 *
	 * Render selected staging sites.
	 *
	 * @param mixed  $selected_websites Selected Child Sites.
	 * @param mixed  $edit_site_id Child Site ID to edit.
	 * @param string $type Selector type.
	 *
	 * @return void Render selected staging sites html.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::query()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_for_current_user()
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_DB::free_result()
	 */
	public static function render_select_sites_staging( $selected_websites, $edit_site_id, $type = 'checkbox' ) {
		$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user( false, null, 'wp.url', false, false, null, false, array( 'favi_icon' ), $is_staging = 'yes' ) );
		?>
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
							if ( '' == $website->sync_errors && ! MainWP_System_Utility::is_suspended_site( $website ) ) {
									$selected = ( 'all' === $selected_websites || in_array( $website->id, $selected_websites ) );
									$disabled = '';
								if ( $edit_site_id ) {
									if ( $website->id != $edit_site_id ) {
										$disabled = 'disabled="disabled"';
									}
								}
								?>
								<div title="<?php echo esc_html( $website->url ); ?>" class="mainwp_selected_sites_item ui <?php echo esc_html( $type ); ?> item <?php echo ( $selected ? 'selected_sites_item_checked' : '' ); ?>">
									<input <?php echo $disabled; ?> type="<?php echo esc_html( $type ); ?>" name="<?php echo ( 'radio' === $type ? 'selected_sites' : 'selected_sites[]' ); ?>" siteid="<?php echo intval( $website->id ); ?>" value="<?php echo intval( $website->id ); ?>" id="selected_sites_<?php echo intval( $website->id ); ?>" <?php echo ( $selected ? 'checked="true"' : '' ); ?> />
									<label for="selected_sites_<?php echo intval( $website->id ); ?>">
										<?php echo stripslashes( $website->name ); ?>  <span class="url"><?php echo esc_html( $website->url ); ?></span>
									</label>
								</div>
								<?php
							} else {
								?>
								<div title="<?php echo esc_html( $website->url ); ?>" class="mainwp_selected_sites_item item ui <?php echo esc_html( $type ); ?> <?php echo ( $selected ? 'selected_sites_item_checked' : '' ); ?>">
									<input type="<?php echo esc_html( $type ); ?>" disabled="disabled"/>
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
	}

	/**
	 * Method render_select_sites_group()
	 *
	 * Render selected sites group.
	 *
	 * @param array  $groups Array of groups.
	 * @param mixed  $selected_groups Selected groups.
	 * @param string $type Selector type.
	 *
	 * @return void Render selected sites group html.
	 */
	public static function render_select_sites_group( $groups, $selected_groups, $type = 'checkbox' ) {
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
						<div class="content"><?php esc_html_e( 'No Tags created!', 'mainwp' ); ?></div>
						<div class="ui divider hidden"></div>
						<a href="admin.php?page=ManageGroups" class="ui green button basic"><?php esc_html_e( 'Create Tags', 'mainwp' ); ?></a>
					</h2>
					<?php
				}
				foreach ( $groups as $group ) {
					$selected = in_array( $group->id, $selected_groups );
					?>
					<div class="mainwp_selected_groups_item ui item <?php echo esc_html( $type ); ?> <?php echo ( $selected ? 'selected_groups_item_checked' : '' ); ?>">
						<input type="<?php echo esc_html( $type ); ?>" name="<?php echo ( 'radio' === $type ? 'selected_groups' : 'selected_groups[]' ); ?>" value="<?php echo $group->id; ?>" id="selected_groups_<?php echo $group->id; ?>" <?php echo ( $selected ? 'checked="true"' : '' ); ?> />
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
	}

	/**
	 * Method render_top_header()
	 *
	 * Render top header.
	 *
	 * @param array $params Page parameters.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::query()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_for_current_user()
	 * @uses \MainWP\Dashboard\MainWP_Menu::render_left_menu()
	 */
	public static function render_top_header( $params = array() ) { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.MaxExceeded

		$title = isset( $params['title'] ) ? $params['title'] : '';
		$which = isset( $params['which'] ) ? $params['which'] : '';

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

		$more_tags = array(
			'img' => array(
				'src'    => array(),
				'width'  => array(),
				'height' => array(),
			),
		);
		$title     = MainWP_Utility::esc_content( $title, 'note', $more_tags );

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

		$count_sites = MainWP_DB::instance()->get_websites_count();

		if ( 0 == $count_sites ) {
			if ( ! isset( $_GET['do'] ) ) {
				echo self::render_modal_no_sites_note();
			}
		}

		$siteViewMode = MainWP_Utility::get_siteview_mode();

		$tour_id = '';
		if ( isset( $_GET['page'] ) && 'mainwp_tab' == $_GET['page'] ) {
			$tour_id = '13112';
		} elseif ( isset( $_GET['page'] ) && 'managesites' == $_GET['page'] ) {
			if ( isset( $_GET['do'] ) && 'new' == $_GET['do'] ) {
				$tour_id = '13210';
			} elseif ( isset( $_GET['do'] ) && 'bulknew' == $_GET['do'] ) {
				$tour_id = '27274';
			} elseif ( ! isset( $_GET['dashboard'] ) && ! isset( $_GET['id'] ) && ! isset( $_GET['updateid'] ) && ! isset( $_GET['emailsettingsid'] ) && ! isset( $_GET['scanid'] ) ) {
				if ( 'grid' == $siteViewMode ) {
					$tour_id = '27217';
				} else {
					$tour_id = '29331';
				}
			}
		} elseif ( isset( $_GET['page'] ) && 'MonitoringSites' == $_GET['page'] ) {
			$tour_id = '29003';
		} elseif ( isset( $_GET['page'] ) && 'ManageClients' == $_GET['page'] ) {
			if ( isset( $_GET['client_id'] ) ) {
				$tour_id = '28258';
			} else {
				$tour_id = '28240';
			}
		} elseif ( isset( $_GET['page'] ) && 'ClientAddNew' == $_GET['page'] ) {
			if ( isset( $_GET['client_id'] ) ) {
				$tour_id = '28962';
			} else {
				$tour_id = '28256';
			}
		} elseif ( isset( $_GET['page'] ) && 'ClientAddField' == $_GET['page'] ) {
			$tour_id = '28257';
		} elseif ( isset( $_GET['page'] ) && 'PluginsManage' == $_GET['page'] ) {
			$tour_id = '28510';
		} elseif ( isset( $_GET['page'] ) && 'ManageGroups' == $_GET['page'] ) {
			$tour_id = '27275';
		} elseif ( isset( $_GET['page'] ) && 'UpdatesManage' == $_GET['page'] ) {
			if ( isset( $_GET['tab'] ) && 'plugins-updates' == $_GET['tab'] ) {
				$tour_id = '28259';
			} elseif ( isset( $_GET['tab'] ) && 'themes-updates' == $_GET['tab'] ) {
				$tour_id = '28447';
			} elseif ( isset( $_GET['tab'] ) && 'wordpress-updates' == $_GET['tab'] ) {
				$tour_id = '29005';
			} elseif ( isset( $_GET['tab'] ) && 'translations-updates' == $_GET['tab'] ) {
				$tour_id = '29007';
			} elseif ( isset( $_GET['tab'] ) && 'abandoned-plugins' == $_GET['tab'] ) {
				$tour_id = '29008';
			} elseif ( isset( $_GET['tab'] ) && 'abandoned-themes' == $_GET['tab'] ) {
				$tour_id = '29009';
			} else {
				$tour_id = '28259';
			}
		} elseif ( isset( $_GET['page'] ) && 'PluginsInstall' == $_GET['page'] ) {
			$tour_id = '29011';
		} elseif ( isset( $_GET['page'] ) && 'PluginsAutoUpdate' == $_GET['page'] ) {
			$tour_id = '29015';
		} elseif ( isset( $_GET['page'] ) && 'PluginsIgnore' == $_GET['page'] ) {
			$tour_id = '29018';
		} elseif ( isset( $_GET['page'] ) && 'PluginsIgnoredAbandoned' == $_GET['page'] ) {
			$tour_id = '29329';
		} elseif ( isset( $_GET['page'] ) && 'ThemesManage' == $_GET['page'] ) {
			$tour_id = '28511';
		} elseif ( isset( $_GET['page'] ) && 'ThemesInstall' == $_GET['page'] ) {
			$tour_id = '29010';
		} elseif ( isset( $_GET['page'] ) && 'ThemesAutoUpdate' == $_GET['page'] ) {
			$tour_id = '29016';
		} elseif ( isset( $_GET['page'] ) && 'ThemesIgnore' == $_GET['page'] ) {
			$tour_id = '29019';
		} elseif ( isset( $_GET['page'] ) && 'ThemesIgnoredAbandoned' == $_GET['page'] ) {
			$tour_id = '29330';
		} elseif ( isset( $_GET['page'] ) && 'UserBulkManage' == $_GET['page'] ) {
			$tour_id = '28574';
		} elseif ( isset( $_GET['page'] ) && 'UserBulkAdd' == $_GET['page'] ) {
			$tour_id = '28575';
		} elseif ( isset( $_GET['page'] ) && 'BulkImportUsers' == $_GET['page'] ) {
			$tour_id = '28736';
		} elseif ( isset( $_GET['page'] ) && 'UpdateAdminPasswords' == $_GET['page'] ) {
			$tour_id = '28737';
		} elseif ( isset( $_GET['page'] ) && 'PostBulkManage' == $_GET['page'] ) {
			$tour_id = '28796';
		} elseif ( isset( $_GET['page'] ) && 'PostBulkAdd' == $_GET['page'] ) {
			$tour_id = '28799';
		} elseif ( isset( $_GET['page'] ) && 'PageBulkManage' == $_GET['page'] ) {
			$tour_id = '29045';
		} elseif ( isset( $_GET['page'] ) && 'PageBulkAdd' == $_GET['page'] ) {
			$tour_id = '29048';
		} elseif ( isset( $_GET['page'] ) && 'Extensions' == $_GET['page'] ) {
			$tour_id = '28800';
		} elseif ( isset( $_GET['page'] ) && 'Settings' == $_GET['page'] ) {
			$tour_id = '28883';
		} elseif ( isset( $_GET['page'] ) && 'SettingsAdvanced' == $_GET['page'] ) {
			$tour_id = '28886';
		} elseif ( isset( $_GET['page'] ) && 'SettingsEmail' == $_GET['page'] ) {
			$tour_id = '29054';
		} elseif ( isset( $_GET['page'] ) && 'MainWPTools' == $_GET['page'] ) {
			$tour_id = '29272';
		} elseif ( isset( $_GET['page'] ) && 'RESTAPI' == $_GET['page'] ) {
			$tour_id = '29273';
		} elseif ( isset( $_GET['page'] ) && 'ServerInformation' == $_GET['page'] ) {
			$tour_id = '28873';
		} elseif ( isset( $_GET['page'] ) && 'ServerInformationCron' == $_GET['page'] ) {
			$tour_id = '28874';
		} elseif ( isset( $_GET['page'] ) && 'ErrorLog' == $_GET['page'] ) {
			$tour_id = '28876';
		} elseif ( isset( $_GET['page'] ) && 'ActionLogs' == $_GET['page'] ) {
			$tour_id = '28877';
		}

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
				<?php while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) { ?>
					<div class="item mainwp-site-menu-item">
						<a class="title">
							<i class="dropdown icon"></i>
							<label><?php echo esc_html( $website->name ); ?></label>
						</a>
						<div class="content">
							<div class="ui link tiny list">
								<a class="item" href="<?php echo 'admin.php?page=managesites&dashboard=' . $website->id; ?>">
									<i class="grid layout icon"></i>
									<?php esc_html_e( 'Overview', 'mainwp' ); ?>
								</a>
								<a class="item" href="<?php echo 'admin.php?page=managesites&updateid=' . $website->id; ?>">
									<i class="sync alternate icon"></i>
									<?php esc_html_e( 'Updates', 'mainwp' ); ?>
								</a>
								<a class="item" href="<?php echo 'admin.php?page=managesites&id=' . $website->id; ?>">
									<i class="edit icon"></i>
									<?php esc_html_e( 'Edit Site', 'mainwp' ); ?>
								</a>
								<a class="item" href="<?php echo 'admin.php?page=managesites&scanid=' . $website->id; ?>">
									<i class="shield icon"></i>
									<?php esc_html_e( 'Security Scan', 'mainwp' ); ?>
								</a>
								<a class="item" target="_blank" href="<?php echo 'admin.php?page=SiteOpen&newWindow=yes&websiteid=' . $website->id; ?>&_opennonce=<?php echo wp_create_nonce( 'mainwp-admin-nonce' ); ?>">
									<i class="sign in icon"></i>
									<?php esc_html_e( 'Go to WP Admin', 'mainwp' ); ?>
								</a>
								<a class="item" target="_blank" href="<?php echo esc_url( $website->url ); ?>">
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
				<?php } ?>
			</div>
		</div>
		<div class="ui segment right wide help sidebar" id="mainwp-documentation-sidebar">
			<div class="ui header"><?php esc_html_e( 'MainWP Guided Tours', 'mainwp' ); ?></div>
			<div class="ui hidden divider"></div>
			<?php if ( 1 == get_option( 'mainwp_enable_guided_tours', 0 ) ) : ?>
			<p><?php esc_html_e( 'MainWP guided tours are designed to provide information about all essential features on each MainWP Dashboard page.', 'mainwp' ); ?></p>
			<p><?php esc_html_e( 'Click the Start Page Tour button to start the guided tour for the current page.', 'mainwp' ); ?></p>
			<div class="ui hidden divider"></div>
			<a href="javacscript:void(0);" id="mainwp-start-page-tour-button" class="ui big green fluid basic button" tour-id="<?php esc_attr_e( $tour_id ); ?>"><?php esc_html_e( 'Start Page Tour', 'mainwp' ); ?></a>
				<?php if ( isset( $_GET['page'] ) && 'mainwp_tab' == $_GET['page'] ) : ?>
				<div class="ui hidden divider"></div>
				<a href="javacscript:void(0);" id="mainwp-interface-tour-button" class="ui big green fluid basic button"><?php esc_html_e( 'MainWP Interface Basics Tour', 'mainwp' ); ?></a>
			<?php endif; ?>
			<?php else : ?>
			<div class="ui info message">
				<p><?php esc_html_e( 'MainWP guided tours feature is disabled.', 'mainwp' ); ?></p>
				<p><?php echo sprintf( esc_html__( 'Go to the %1$sMainWP Tools%2$s page to enable it.', 'mainwp' ), '<a href="admin.php?page=MainWPTools">', '</a>' ); ?></p>
			</div>
			<?php endif; ?>
			<div class="ui hidden divider"></div>
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
			<a href="https://kb.mainwp.com/" class="ui big green fluid button"><?php esc_html_e( 'Help Documentation', 'mainwp' ); ?></a>
			<div class="ui hidden divider"></div>
			<div id="mainwp-sticky-help-button" class="" style="position: absolute; bottom: 1em; left: 1em; right: 1em;">
				<a href="https://managers.mainwp.com/" target="_blank" class="ui fluid button"><?php esc_html_e( 'Still Need Help?', 'mainwp' ); ?></a>
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
		global $wp_version;
		$fix_menu_overflow = 1;
		if ( version_compare( $wp_version, '5.5.3', '>' ) ) {
			$fix_menu_overflow = 2;
		}

		if ( 'page_clients_overview' !== $which ) {
			?>

		<div class="ui modal" id="mainwp-overview-screen-options-modal">
			<div class="header"><?php esc_html_e( 'Page Settings', 'mainwp' ); ?></div>
			<div class="content ui form">
				<?php
				/**
				 * Action: mainwp_overview_screen_options_top
				 *
				 * Fires at the top of the Sceen Options modal on the Overview page.
				 *
				 * @since 4.1
				 */
				do_action( 'mainwp_overview_screen_options_top' );
				?>
				<form method="POST" action="" name="mainwp_overview_screen_options_form" id="mainwp-overview-screen-options-form">
					<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
					<input type="hidden" name="wp_nonce" value="<?php echo wp_create_nonce( 'MainWPScrOptions' ); ?>" />
					<?php echo self::render_screen_options( false ); ?>
					<?php
					/**
					 * Action: mainwp_overview_screen_options_bottom
					 *
					 * Fires at the bottom of the Sceen Options modal on the Overview page.
					 *
					 * @since 4.1
					 */
					do_action( 'mainwp_overview_screen_options_bottom' );
					?>
			</div>
			<div class="actions">
				<div class="ui two columns grid">
					<div class="left aligned column">
						<span data-tooltip="<?php esc_attr_e( 'Returns this page to the state it was in when installed. The feature also restores any widgets you have moved through the drag and drop feature on the page.', 'mainwp' ); ?>" data-inverted="" data-position="top left"><input type="button" class="ui button" name="reset" id="reset-overview-settings" value="<?php esc_attr_e( 'Reset Page', 'mainwp' ); ?>" /></span>
					</div>
					<div class="ui right aligned column">
						<input type="submit" class="ui green button" id="submit-overview-settings" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>" />
						<div class="ui cancel button"><?php esc_html_e( 'Close', 'mainwp' ); ?></div>
					</div>
				</div>
			</div>

			<input type="hidden" name="reset_overview_settings" value="" />
			</form>
		</div>
		<?php } ?>

		<div class="mainwp-content-wrap <?php echo empty( $sidebarPosition ) ? 'mainwp-sidebar-left' : ''; ?>" menu-overflow="<?php echo intval( $fix_menu_overflow ); ?>">
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
			<div id="mainwp-top-header" class="">
				<div class="ui grid">

					<div class="five wide column"><h4 class="mainwp-page-title"><?php echo $left; ?></h4></div>

					<div class="two wide column middle aligned right aligned">
						<?php if ( isset( $_GET['dashboard'] ) && ! empty( $_GET['dashboard'] ) ) : ?>
						<select class="ui mini selection fluid dropdown" id="mainwp-jump-to-site-overview-dropdown">
							<?php $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() ); ?>
							<option class="item"><?php esc_html_e( 'Jump to site overview...', 'mainwp' ); ?></option>
							<?php while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) { ?>
								<option class="item" value="<?php echo esc_attr( $website->id ); ?>"><?php echo esc_html( $website->name ); ?></option>
							<?php } ?>	
							<?php MainWP_DB::free_result( $websites ); ?>
							</select>
						<?php endif; ?>
					</div>
					<div class="nine wide right aligned column"><?php echo $right; ?></div>

				</div>
			</div>

			<?php if ( 1 == get_option( 'mainwp_enable_guided_tours', 0 ) ) : ?>
				<script type="text/javascript">
					jQuery( document ).ready( function() {
						jQuery( '#mainwp-start-page-tour-button' ).on( 'click', function() {
							var tourId = jQuery( this ).attr( 'tour-id' );
							jQuery( '#mainwp-documentation-sidebar' ).sidebar( 'toggle' );
							window.USETIFUL.tour.start( parseInt( tourId ) );
						} );
						jQuery( '#mainwp-interface-tour-button' ).on( 'click', function() {
							jQuery( '#mainwp-documentation-sidebar' ).sidebar( 'toggle' );
							window.USETIFUL.tour.start( 13282 );
						} );
					} );
				</script>
			<?php endif; ?>

			<script type="text/javascript">
			jQuery( document ).ready( function () {

				jQuery( '#mainwp-jump-to-site-overview-dropdown' ).on( 'change', function() {
					var site_id = jQuery( this ).val();
					window.location.href = 'admin.php?page=managesites&dashboard=' + site_id;
				} );

				jQuery( '#mainwp-sites-menu-sidebar' ).prependTo( 'body' );
				jQuery( '#mainwp-documentation-sidebar' ).prependTo( 'body' );
				jQuery( 'body > div#wpwrap' ).addClass( 'pusher' );

				jQuery( '.ui.sticky' ).sticky( { pushing: false } ).sticky();

				jQuery( '#mainwp-help-sidebar' ).on( 'click', function() {
					jQuery( '.ui.help.sidebar' ).sidebar( {
						transition: 'overlay'
					} );
					jQuery( '.ui.help.sidebar' ).sidebar( 'toggle' );
					return false;
				} );
				jQuery( '#mainwp-sites-sidebar' ).on( 'click', function() {
					jQuery( '.ui.sites.sidebar' ).sidebar( {
						transition: 'overlay',
						'onShow': function() {
							jQuery( '#mainwp-sites-menu-filter' ).focus();
						}
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
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::get_groups_for_manage_sites()
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::get_not_empty_groups()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_for_current_user()
	 * @uses \MainWP\Dashboard\MainWP_DB::query()
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_DB::free_result()
	 */
	public static function gen_groups_sites_selection() {
		$sql      = MainWP_DB::instance()->get_sql_websites_for_current_user( false, null, 'wp.url', false, false, null, false, array( 'premium_upgrades', 'plugins_outdate_dismissed', 'themes_outdate_dismissed', 'plugins_outdate_info', 'themes_outdate_info', 'favi_icon' ) );
		$websites = MainWP_DB::instance()->query( $sql );
		$g        = isset( $_GET['g'] ) ? intval( $_GET['g'] ) : -1;
		$s        = isset( $_GET['dashboard'] ) ? intval( $_GET['dashboard'] ) : -1;
		?>
		<div class="column full wide">
			<select id="mainwp_top_quick_jump_group" class="ui dropdown">
				<option value="" class="item"><?php esc_html_e( 'All Tags', 'mainwp' ); ?></option>
				<option <?php echo ( -1 === $g ) ? 'selected' : ''; ?> value="-1" class="item"><?php esc_html_e( 'All Tags', 'mainwp' ); ?></option>
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
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::get_websites_count()
	 */
	public static function render_header_actions() {
		$sites_count   = MainWP_DB::instance()->get_websites_count();
		$website_id    = '';
		$sidebar_pages = array( 'ManageGroups', 'PostBulkManage', 'PostBulkAdd', 'PageBulkManage', 'PageBulkAdd', 'ThemesManage', 'ThemesInstall', 'ThemesAutoUpdate', 'PluginsManage', 'PluginsInstall', 'PluginsAutoUpdate', 'UserBulkManage', 'UserBulkAdd', 'UpdateAdminPasswords', 'Extensions' );
		$sidebar_pages = apply_filters( 'mainwp_sidbar_pages', $sidebar_pages );
		ob_start();
		if ( isset( $_GET['dashboard'] ) ) :
			$id      = intval( $_GET['dashboard'] );
			$website = MainWP_DB::instance()->get_website_by_id( $id );

			if ( '' != $website->sync_errors ) :
				?>
				<a href="#" class="mainwp-updates-overview-reconnect-site ui green button" siteid="<?php echo $website->id; ?>" data-position="bottom right" data-tooltip="Reconnect <?php echo stripslashes( $website->name ); ?>" data-inverted=""><?php esc_html_e( 'Reconnect Site', 'mainwp' ); ?></a>
				<?php
			else :
				?>
				<button class="ui button green <?php echo ( 0 < $sites_count ? '' : 'disabled' ); ?>" id="mainwp-sync-sites" data-inverted="" data-position="bottom right" data-tooltip="<?php esc_attr_e( 'Get fresh data from your child sites.', 'mainwp' ); ?>">
					<i class="sync icon mainwp-sync-button-icon"></i>
					<span class="mainwp-sync-button-text">
					<?php
					/**
					 * Filter: mainwp_main_sync_button_text
					 *
					 * Filters the Sync Dashboard with Child Sites button text.
					 *
					 * @since 4.1
					 */
					echo esc_html( apply_filters( 'mainwp_site_sync_button_text', esc_html__( 'Sync Dashboard with Site', 'mainwp' ) ) );
					?>
					</span>
				</button>
				<?php
			endif;
		else :
			?>
			<button class="ui button green <?php echo ( 0 < $sites_count ? '' : 'disabled' ); ?> " id="mainwp-sync-sites" data-inverted="" data-position="bottom right" data-tooltip="<?php esc_attr_e( 'Get fresh data from your child sites.', 'mainwp' ); ?>">
				<i class="sync icon mainwp-sync-button-icon"></i>
				<span class="mainwp-sync-button-text">
			<?php
			/**
			 * Filter: mainwp_main_sync_button_text
			 *
			 * Filters the Sync Dashboard with Child Sites button text.
			 *
			 * @since 4.1
			 */
			echo esc_html( apply_filters( 'mainwp_main_sync_button_text', esc_html__( 'Sync Dashboard with Sites', 'mainwp' ) ) );
			?>
				</span>
		</button>
			<?php
		endif;
		?>

		<div class="ui <?php echo ( 0 == $sites_count ? 'green' : '' ); ?> buttons" id="mainwp-add-new-buttons">
			<a class="ui icon button" data-inverted="" data-position="bottom right" data-tooltip="<?php esc_attr_e( 'Add a new Website to your MainWP Dashboard', 'mainwp' ); ?>" href="<?php echo esc_attr( admin_url( 'admin.php?page=managesites&do=new' ) ); ?>"><i class="plus icon"></i></a>
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
		<?php if ( isset( $_GET['dashboard'] ) || isset( $_GET['id'] ) || isset( $_GET['updateid'] ) || isset( $_GET['emailsettingsid'] ) || isset( $_GET['scanid'] ) || isset( $_GET['cacheControlId'] ) ) : ?>
			<?php if ( isset( $_GET['dashboard'] ) ) : ?>
				<?php $website_id = intval( $_GET['dashboard'] ); ?>
			<?php elseif ( isset( $_GET['updateid'] ) ) : ?>
				<?php $website_id = intval( $_GET['updateid'] ); ?>
			<?php elseif ( isset( $_GET['emailsettingsid'] ) ) : ?>
				<?php $website_id = intval( $_GET['emailsettingsid'] ); ?>
			<?php elseif ( isset( $_GET['scanid'] ) ) : ?>
				<?php $website_id = intval( $_GET['scanid'] ); ?>
			<?php elseif ( isset( $_GET['cacheControlId'] ) ) : ?>
				<?php $website_id = intval( $_GET['cacheControlId'] ); ?>
			<?php else : ?>
				<?php $website_id = intval( $_GET['id'] ); ?>
			<?php endif; ?>
			<a id="mainwp-go-wp-admin-button" href="<?php echo 'admin.php?page=SiteOpen&newWindow=yes&websiteid=' . $website_id; ?>&_opennonce=<?php echo wp_create_nonce( 'mainwp-admin-nonce' ); ?>" data-tooltip="<?php esc_attr_e( 'Jump to the site WP Admin', 'mainwp' ); ?>"  data-position="bottom right"  data-inverted="" class="open_newwindow_wpadmin ui green basic icon button" target="_blank"><i class="sign in icon"></i></a>
			<a id="mainwp-remove-site-button" href="#" site-id="<?php echo $website_id; ?>" data-tooltip="<?php esc_attr_e( 'Remove the site from your MainWP Dashboard.', 'mainwp' ); ?>"  data-position="bottom right"  data-inverted="" class="mainwp-remove-site-button ui red basic icon button" target="_blank"><i class="times icon"></i></a>
		<?php endif; ?>
		<?php if ( ( isset( $_GET['page'] ) && 'mainwp_tab' === $_GET['page'] ) || isset( $_GET['dashboard'] ) || in_array( $_GET['page'], $sidebar_pages ) ) : ?>
		<a id="mainwp-screen-options-button" class="ui button basic icon" onclick="jQuery( '#mainwp-overview-screen-options-modal' ).modal({allowMultiple:true}).modal( 'show' ); return false;" data-inverted="" data-position="bottom right" href="#" target="_blank" data-tooltip="<?php esc_html_e( 'Page Settings', 'mainwp' ); ?>">
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
		<a id="mainwp-community-button" class="ui button basic icon" data-inverted="" data-position="bottom right" href="https://managers.mainwp.com/" target="_blank" data-tooltip="<?php esc_attr_e( 'MainWP Community', 'mainwp' ); ?>">
			<i class="discourse icon"></i>
		</a>
		<a id="mainwp-account-button" class="ui button basic icon" data-inverted="" data-position="bottom right" data-tooltip="<?php esc_attr_e( 'Go to your MainWP Account at MainWP.com', 'mainwp' ); ?>" target="_blank" href="https://mainwp.com/my-account/">
			<i class="user icon"></i>
		</a>
		<?php
		$custom_theme = MainWP_Settings::get_instance()->get_current_user_theme();
		?>
		<div id="mainwp-select-theme-button" class="ui button icon mainwp-selecte-theme-button" custom-theme="default" data-inverted="" data-position="bottom right" data-tooltip="<?php esc_html_e( 'Select MainWP theme', 'mainwp' ); ?>">
			<i class="sun icon"></i>
		</div>
		<?php
		$all_updates = wp_get_update_data();
		if ( is_array( $all_updates ) && isset( $all_updates['counts']['total'] ) && 0 < $all_updates['counts']['total'] ) {
			?>
			<a id="mainwp-available-dashboard-updates-button" class="ui red icon button" data-inverted="" data-position="bottom right" data-tooltip="<?php esc_attr_e( 'Your MainWP Dashboard sites needs your attention. Please check the available updates', 'mainwp' ); ?>" href="update-core.php">
				<i class="exclamation triangle icon"></i>
			</a>
			<?php
		}
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

						if ( ! is_array( $item ) ) {
							continue;
						}

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
						<a class="<?php echo esc_attr( $active ); ?> item" <?php echo esc_attr( $style ); ?> href="<?php echo esc_url( $item['href'] ); ?>">
							<?php echo esc_html( $item['title'] ); ?> <?php echo isset( $item['after_title'] ) ? $item['after_title'] : ''; ?>
						</a>
						<?php
					}
				}
				do_action( 'mainwp_page_navigation_menu' );
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
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_page_id()
	 */
	public static function add_widget_box( $id, $callback, $screen = null, $context = null, $title = null, $priority = 'default' ) { // phpcs:ignore -- complex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated.
		/**
		* MainWP widget boxes array.
		*
		* @global object
		*/
		global $mainwp_widget_boxes;

		$page = MainWP_System_Utility::get_page_id( $screen );

		if ( empty( $page ) ) {
			return;
		}

		if ( 'mainwp_page_manageclients' == $page ) {
			$overviewColumns = get_option( 'mainwp_number_clients_overview_columns', 2 );
		} elseif ( 'toplevel_page_mainwp_tab' === $page || 'mainwp_page_managesites' === $page ) {
			$overviewColumns = get_option( 'mainwp_number_overview_columns', 2 );
		} else {
			$overviewColumns = apply_filters( 'mainwp_widget_boxes_number_columns', 2, $page );
		}

		$contexts = array( 'left', 'right' );
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
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_page_id()
	 */
	public static function do_widget_boxes( $screen, $context = null, $object = '' ) { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.
		global $mainwp_widget_boxes;
		static $already_sorted = false;

		$page = MainWP_System_Utility::get_page_id( $screen );

		if ( empty( $page ) ) {
			return;
		}

		if ( 'mainwp_page_manageclients' == $page ) {
			$overviewColumns = get_option( 'mainwp_number_clients_overview_columns', 2 );
		} elseif ( 'toplevel_page_mainwp_tab' === $page || 'mainwp_page_managesites' === $page ) {
			$overviewColumns = get_option( 'mainwp_number_overview_columns', 2 );
		} else {
			$overviewColumns = apply_filters( 'mainwp_widget_boxes_number_columns', 2, $page );
		}

		$contexts = array( 'left', 'right' );
		if ( 3 == $overviewColumns ) {
			$contexts[] = 'middle';
		}

		if ( null == $context || ! in_array( $context, $contexts ) ) {
			$context = 'right';
		}

		$sorted = get_user_option( 'mainwp_widgets_sorted_' . strtolower( $page ) );

		if ( 'mainwp_page_manageclients' == $page ) {
			$sorted_array = $sorted;
			$sorted       = '';
			$client_id    = isset( $_GET['client_id'] ) ? intval( $_GET['client_id'] ) : 0;
			if ( ! empty( $client_id ) && is_array( $sorted_array ) && isset( $sorted_array[ $client_id ] ) ) {
				$sorted = $sorted_array[ $client_id ];
			}
		}

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

		if ( 'mainwp_page_manageclients' == $page ) {
			$show_widgets = get_user_option( 'mainwp_clients_show_widgets' );
		} elseif ( 'toplevel_page_mainwp_tab' === $page || 'mainwp_page_managesites' === $page ) {
			$show_widgets = get_user_option( 'mainwp_settings_show_widgets' );
		} else {
			$show_widgets = apply_filters( 'mainwp_widget_boxes_show_widgets', array(), $page );
		}

		if ( ! is_array( $show_widgets ) ) {
			$show_widgets = array();
		}

		if ( isset( $mainwp_widget_boxes[ $page ][ $context ] ) ) {
			foreach ( array( 'high', 'sorted', 'core', 'default', 'low' ) as $priority ) {
				if ( isset( $mainwp_widget_boxes[ $page ][ $context ][ $priority ] ) ) {
					foreach ( (array) $mainwp_widget_boxes[ $page ][ $context ][ $priority ] as $box ) {
						if ( false == $box || ! isset( $box['callback'] ) ) {
							continue;
						}

						// to avoid hidden widgets.
						if ( isset( $show_widgets[ $box['id'] ] ) && 0 == $show_widgets[ $box['id'] ] ) {
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
		<select class="ui disabled dropdown" id="mainwp-bulk-actions">
			<option value=""><?php esc_html_e( 'Bulk Actions', 'mainwp' ); ?></option>
		</select>
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
			<div class="ui green progress mainwp-modal-progress">
				<div class="bar"><div class="progress"></div></div>
				<div class="label"></div>
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
				<div id="plugintheme-installation-queue"></div>
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
	 * Method render_modal_upload_icon()
	 *
	 * Render modal window for upload plugins & themes icon.
	 */
	public static function render_modal_upload_icon() {

		?>
		<div id="mainwp-upload-custom-icon-modal" class="ui modal">
			<div class="header">
			<?php
			esc_html_e( 'Update Icon', 'mainwp' );
			?>
			</div>
				<div class="content" id="mainwp-upload-custom-icon-content">
				<form action="" method="post" enctype="multipart/form-data" name="uploadicon_form" id="uploadicon_form" class="">
				<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
				<div class="ui message" id="mainwp-message-zone-upload" style="display:none;"></div>
					<?php
					/**
					 * Action: mainwp_after_upload_custom_icon
					 *
					 * Fires before the modal element.
					 *
					 * @since 4.3
					 */
					do_action( 'mainwp_before_upload_custom_icon' );
					?>
					<div class="ui form">
						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'Custom icon', 'mainwp' ); ?></label>
							<div class="six wide column" data-tooltip="<?php esc_attr_e( 'Upload a custom icon.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
								<input type="file" id="mainwp_upload_icon_uploader" name="mainwp_upload_icon_uploader[]" accept="image/*" data-inverted="" data-tooltip="<?php esc_attr_e( 'Upload a custom icon.', 'mainwp' ); ?>" />
							</div>
						</div>
						<div class="ui grid field" id="mainwp_delete_image_field">
							<label class="six wide column middle aligned"></label>
							<div class="six wide column">
								<img class="ui tiny image" src="" /><br/>
								<div class="ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'If enabled, delete image.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
									<input type="checkbox"id="mainwp_delete_image_chk" />
									<label for="mainwp_delete_image_chk"><?php esc_html_e( 'Delete Image', 'mainwp' ); ?></label>
								</div>
							</div>
						</div>
					</div>
					<?php
					/**
					 * Action: mainwp_after_upload_custom_icon
					 *
					 * Fires after the modal element.
					 *
					 * @since 4.3
					 */
					do_action( 'mainwp_after_upload_custom_icon' );
					?>
					</form>
				</div>
				<div class="actions">
					<div class="ui green button" id="update_custom_icon_btn"><?php esc_html_e( 'Update', 'mainwp' ); ?></div>
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
			echo esc_html( apply_filters( 'mainwp_show_all_updates_button_text', esc_html__( 'Show All Updates', 'mainwp' ) ) );
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
		<i class="sort icon"></i><i class="sort up icon"></i><i class="sort down icon"></i>
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
		<div id="mainwp-notes-modal" class="ui modal">
			<div class="header"><?php esc_html_e( 'Notes', 'mainwp' ); ?></div>
			<div class="content" id="mainwp-notes-content">
				<div id="mainwp-notes-status" class="ui message hidden"></div>
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
						<input type="button" class="ui green button" id="mainwp-notes-save" value="<?php esc_attr_e( 'Save Note', 'mainwp' ); ?>" style="display:none;"/>
						<input type="button" class="ui green button" id="mainwp-notes-edit" value="<?php esc_attr_e( 'Edit Note', 'mainwp' ); ?>"/>
					</div>
					<div class="eight wide column">
						<input type="button" class="ui button" id="mainwp-notes-cancel" value="<?php esc_attr_e( 'Close', 'mainwp' ); ?>"/>
						<input type="hidden" id="mainwp-notes-websiteid" value=""/>
						<input type="hidden" id="mainwp-notes-slug" value=""/>
						<input type="hidden" id="mainwp-which-note" value="<?php echo esc_html( $what ); ?>"/>
						<input type="hidden" id="mainwp-notes-itemid" value=""/>
					</div>
				</div>
			</div>
		</div>

		<?php
	}

	/**
	 * No Sites Modal
	 *
	 * Renders modal window for notification when there are no connected sites.
	 *
	 * @return void
	 */
	public static function render_modal_no_sites_note() {
		if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-no-sites-modal-notice' ) ) :
			?>
		<div id="mainwp-no-sites-modal" class="ui small modal">
			<div class="content" id="mainwp-no-sites-modal-content" style="text-align:center">
				<div class="ui hidden divider"></div>
				<div class="ui hidden divider"></div>
				<h4><?php esc_html_e( 'Hi MainWP Manager, there is not anything to see here before connecting your first site.', 'mainwp' ); ?></h4>
				<div class="ui hidden divider"></div>
				<div class="ui hidden divider"></div>
				<a href="admin.php?page=managesites&do=new" class="ui big green button"><?php esc_html_e( 'Connect Your WordPress Site', 'mainwp' ); ?></a>
				<div class="ui hidden fitted divider"></div>
				<small><?php echo sprintf( esc_html__( 'or you can %1$sbulk import%2$s your sites.', 'mainwp' ), '<a href="admin.php?page=managesites&do=bulknew">', '</a>' ); ?></small>
			</div>
			<div class="actions">
				<div class="ui grid">
					<div class="eight wide left aligned middle aligned column">
						<a href="https://kb.mainwp.com/docs/add-site-to-your-dashboard/" class="ui basic green mini button" target="_blank"><?php esc_html_e( 'See How to Connect Sites', 'mainwp' ); ?></a>
					</div>
					<div class="eight wide column">
						<input type="button" class="ui mini basic cancel button mainwp-notice-dismiss" notice-id="mainwp-no-sites-modal-notice" value="<?php esc_attr_e( 'Let Me Look Around', 'mainwp' ); ?>"/>
					</div>
				</div>
			</div>
		</div>
		<script type="text/javascript">
		jQuery( '#mainwp-no-sites-modal' ).modal( {
		blurring: true,
		inverted: true,
		closable: false
	} ).modal( 'show' );
		</script>
			<?php
		endif;
	}

	/**
	 * Method render_screen_options()
	 *
	 * Render modal window for Page Settings.
	 *
	 * @param bool $setting_page Default: True. Widgets that you want to hide in the MainWP Overview page.
	 *
	 * @return void  Render modal window for Page Settings html.
	 */
	public static function render_screen_options( $setting_page = true ) { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		$default_widgets = array(
			'overview'           => esc_html__( 'Updates Overview', 'mainwp' ),
			'recent_posts'       => esc_html__( 'Recent Posts', 'mainwp' ),
			'recent_pages'       => esc_html__( 'Recent Pages', 'mainwp' ),
			'plugins'            => esc_html__( 'Plugins (Individual Site Overview page)', 'mainwp' ),
			'themes'             => esc_html__( 'Themes (Individual Site Overview page)', 'mainwp' ),
			'connection_status'  => esc_html__( 'Connection Status', 'mainwp' ),
			'security_issues'    => esc_html__( 'Security Issues', 'mainwp' ),
			'notes'              => esc_html__( 'Notes (Individual Site Overview page)', 'mainwp' ),
			'child_site_info'    => esc_html__( 'Child site info (Individual Site Overview page)', 'mainwp' ),
			'client_info'        => esc_html__( 'Client info (Individual Site Overview page)', 'mainwp' ),
			'non_mainwp_changes' => esc_html__( 'Non-MainWP Changes', 'mainwp' ),
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

		$show_widgets = get_user_option( 'mainwp_settings_show_widgets' );

		if ( ! is_array( $show_widgets ) ) {
			$show_widgets = array();
		}

		$sidebar_pages = array( 'ManageGroups', 'PostBulkManage', 'PostBulkAdd', 'PageBulkManage', 'PageBulkAdd', 'ThemesManage', 'ThemesInstall', 'ThemesAutoUpdate', 'PluginsManage', 'PluginsInstall', 'PluginsAutoUpdate', 'UserBulkManage', 'UserBulkAdd', 'UpdateAdminPasswords', 'Extensions' );
		$sidebar_pages = apply_filters( 'mainwp_sidbar_pages', $sidebar_pages );

		/**
		 * Action: mainwp_screen_options_modal_top
		 *
		 * Fires at the top of the Page Settings modal element.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_screen_options_modal_top' );
		$which_settings = 'overview_settings';
		?>
		<?php if ( ! $setting_page ) : ?>
			<?php if ( isset( $_GET['page'] ) && in_array( $_GET['page'], $sidebar_pages ) ) : ?>
				<?php
				$which_settings  = 'sidebar_settings';
				$sidebarPosition = get_user_option( 'mainwp_sidebarPosition' );
				if ( false === $sidebarPosition ) {
					$sidebarPosition = 1;
				}
				$manageGroupsPage = false;
				if ( isset( $_GET['page'] ) && 'ManageGroups' === $_GET['page'] ) {
					$manageGroupsPage = true;
				}
				?>
			<div class="ui grid field">
				<label tabindex="0" class="six wide column middle aligned"><?php echo $manageGroupsPage ? esc_html__( 'Tags menu position', 'mainwp' ) : esc_html__( 'Sidebar position', 'mainwp' ); ?></label>
				<div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Select if you want to show the element on left or right.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
					<select name="mainwp_sidebarPosition" id="mainwp_sidebarPosition" class="ui dropdown">
						<option value="1" <?php echo ( 1 == $sidebarPosition ? 'selected' : '' ); ?>><?php esc_html_e( 'Right', 'mainwp' ); ?></option>
						<option value="0" <?php echo ( 0 == $sidebarPosition ? 'selected' : '' ); ?>><?php esc_html_e( 'Left', 'mainwp' ); ?></option>
					</select>
				</div>
			</div>
			<?php endif; ?>
		<?php endif; ?>
		<?php if ( isset( $_GET['page'] ) && ! in_array( $_GET['page'], $sidebar_pages ) ) : ?>
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
			<label class="six wide column"><?php esc_html_e( 'Show widgets', 'mainwp' ); ?></label>
			<div class="ten wide column" <?php echo $setting_page ? 'data-tooltip="' . esc_attr_e( 'Select widgets that you want to hide in the MainWP Overview page.', 'mainwp' ) . '"' : ''; ?> data-inverted="" data-position="top left">
				<ul class="mainwp_hide_wpmenu_checkboxes">
				<?php
				foreach ( $default_widgets as $name => $title ) {
					$_selected = '';
					if ( ! isset( $show_widgets[ $name ] ) || 1 == $show_widgets[ $name ] ) {
						$_selected = 'checked';
					}
					?>
					<li>
						<div class="ui checkbox">
							<input type="checkbox" id="mainwp_show_widget_<?php echo esc_attr( $name ); ?>" name="mainwp_show_widgets[]" <?php echo $_selected; ?> value="<?php echo esc_attr( $name ); ?>">
							<label for="mainwp_show_widget_<?php echo esc_attr( $name ); ?>" ><?php echo esc_html( $title ); ?></label>
						</div>
						<input type="hidden" name="mainwp_widgets_name[]" value="<?php echo esc_attr( $name ); ?>">
					</li>
					<?php
				}
				?>
				</ul>
			</div>
		</div>
		<?php endif; ?>
		<input type="hidden" name="reset_overview_which_settings" value="<?php echo esc_html( $which_settings ); ?>" />			
		<?php
		/**
		 * Action: mainwp_screen_options_modal_bottom
		 *
		 * Fires at the bottom of the Page Settings modal element.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_screen_options_modal_bottom' );
	}

	/**
	 * Method render_select_mainwp_themes_modal()
	 *
	 * Render modal window for mainwp themes selection.
	 *
	 * @return void  Render modal window for themes selection.
	 */
	public static function render_select_mainwp_themes_modal() {
		?>
		<div class="ui modal" id="mainwp-select-mainwp-themes-modal">
		<div class="header"><?php esc_html_e( 'Select MainWP Theme', 'mainwp' ); ?></div>
		<div class="content ui form">
			<div class="ui blue message">
				<div class=""><?php echo sprintf( esc_html__( 'Did you know you can create your custom theme? %1$sSee here how to do it%2$s!', '' ), '<a href="https://kb.mainwp.com/docs/how-to-change-the-theme-for-mainwp/" target="_blank">', '</a>' ); ?></div>
			</div>
			<form method="POST" action="" name="mainwp_select_mainwp_themes_form" id="mainwp_select_mainwp_themes_form">
				<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
				<input type="hidden" name="wp_scr_options_nonce" value="<?php echo wp_create_nonce( 'MainWPSelectThemes' ); ?>" />
				<?php
				/**
				 * Action: mainwp_select_themes_modal_top
				 *
				 * Fires at the top of the modal.
				 *
				 * @since 4.3
				 */
				do_action( 'mainwp_select_themes_modal_top' );

				MainWP_Settings::get_instance()->render_select_custom_themes();

				/**
				 * Action: mainwp_select_themes_modal_bottom
				 *
				 * Fires at the bottom of the modal.
				 *
				 * @since 4.3
				 */
				do_action( 'mainwp_select_themes_modal_bottom' );
				?>
		</div>
		<div class="actions">
			<div class="ui two columns grid">
				<div class="left aligned column">
					<input type="submit" class="ui green button" id="submit-select-mainwp-themes" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>" />
				</div>
				<div class="ui right aligned column">
					<div class="ui cancel button"><?php esc_html_e( 'Close', 'mainwp' ); ?></div>
				</div>
			</div>
		</div>
		</form>
	</div>
		<?php
	}

}
