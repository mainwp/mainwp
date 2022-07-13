<?php
/**
 * MainWP Themes Page
 *
 * This page is used to Manage Themes on Child Sites
 *
 * @package MainWP/Themes
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Themes_Page
 *
 * @uses MainWP_Install_Bulk
 */
class MainWP_Themes {

	// phpcs:disable Generic.Metrics.CyclomaticComplexity -- complexity.

	/**
	 * Get Class Name
	 *
	 * @return string __CLASS__
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Subpages array.
	 *
	 * @var array $subPages Array of SubPages.
	 */
	public static $subPages;

	/**
	 * Fire on the initialization of WordPress.
	 */
	public static function init() {
		/**
		 * This hook allows you to render the Themes page header via the 'mainwp-pageheader-themes' action.
		 *
		 * @link http://codex.mainwp.com/#mainwp-pageheader-themes
		 *
		 * This hook is normally used in the same context of 'mainwp-getsubpages-themes'
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-themes
		 *
		 * @see \MainWP_Themes::render_header
		 */
		add_action( 'mainwp-pageheader-themes', array( self::get_class_name(), 'render_header' ) );

		/**
		 * This hook allows you to render the Themes page footer via the 'mainwp-pagefooter-themes' action.
		 *
		 * @link http://codex.mainwp.com/#mainwp-pagefooter-themes
		 *
		 * This hook is normally used in the same context of 'mainwp-getsubpages-themes'
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-themes
		 *
		 * @see \MainWP_Themes::render_footer
		 */
		add_action( 'mainwp-pagefooter-themes', array( self::get_class_name(), 'render_footer' ) );

		add_action( 'mainwp_help_sidebar_content', array( self::get_class_name(), 'mainwp_help_content' ) );
	}

	/**
	 * Method init_menu()
	 *
	 * Initiate the MainWP Themes SubMenu page.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
	 */
	public static function init_menu() {

		$_page = add_submenu_page(
			'mainwp_tab',
			__( 'Themes', 'mainwp' ),
			'<span id="mainwp-Themes">' . __( 'Themes', 'mainwp' ) . '</span>',
			'read',
			'ThemesManage',
			array(
				self::get_class_name(),
				'render',
			)
		);

		add_submenu_page(
			'mainwp_tab',
			__( 'Themes', 'mainwp' ),
			'<div class="mainwp-hidden">' . __( 'Install', 'mainwp' ) . '</div>',
			'read',
			'ThemesInstall',
			array(
				self::get_class_name(),
				'render_install',
			)
		);
		add_submenu_page(
			'mainwp_tab',
			__( 'Themes', 'mainwp' ),
			'<div class="mainwp-hidden">' . __( 'Advanced Auto Updates', 'mainwp' ) . '</div>',
			'read',
			'ThemesAutoUpdate',
			array(
				self::get_class_name(),
				'render_auto_update',
			)
		);
		add_submenu_page(
			'mainwp_tab',
			__( 'Themes', 'mainwp' ),
			'<div class="mainwp-hidden">' . __( 'Ignored Updates', 'mainwp' ) . '</div>',
			'read',
			'ThemesIgnore',
			array(
				self::get_class_name(),
				'render_ignore',
			)
		);
		add_submenu_page(
			'mainwp_tab',
			__( 'Themes', 'mainwp' ),
			'<div class="mainwp-hidden">' . __( 'Ignored Abandoned', 'mainwp' ) . '</div>',
			'read',
			'ThemesIgnoredAbandoned',
			array(
				self::get_class_name(),
				'render_ignored_abandoned',
			)
		);

		/**
		 * Themes Subpages
		 *
		 * Filters subpages for the Themes page.
		 *
		 * @since Unknown
		 */
		$sub_pages      = apply_filters_deprecated( 'mainwp-getsubpages-themes', array( array() ), '4.0.7.2', 'mainwp_getsubpages_themes' );  // @deprecated Use 'mainwp_getsubpages_themes' instead.
		self::$subPages = apply_filters( 'mainwp_getsubpages_themes', $sub_pages );
		if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
			foreach ( self::$subPages as $subPage ) {
				if ( MainWP_Menu::is_disable_menu_item( 3, 'Themes' . $subPage['slug'] ) ) {
					continue;
				}
				add_submenu_page( 'mainwp_tab', $subPage['title'], '<div class="mainwp-hidden">' . $subPage['title'] . '</div>', 'read', 'Themes' . $subPage['slug'], $subPage['callback'] );
			}
		}
		self::init_left_menu( self::$subPages );
	}

	/**
	 * Method init_subpages_menu()
	 *
	 * Themes Subpage Menu HTML Content.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
	 */
	public static function init_subpages_menu() {
		?>
		<div id="menu-mainwp-Themes" class="mainwp-submenu-wrapper">
			<div class="wp-submenu sub-open">
				<div class="mainwp_boxout">
					<div class="mainwp_boxoutin"></div>
					<a href="<?php echo admin_url( 'admin.php?page=ThemesManage' ); ?>" class="mainwp-submenu">
						<?php esc_html_e( 'Manage Themes', 'mainwp' ); ?>
					</a>
					<?php if ( mainwp_current_user_have_right( 'dashboard', 'install_themes' ) ) { ?>
						<?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ThemesInstall' ) ) { ?>
						<a href="<?php echo admin_url( 'admin.php?page=ThemesInstall' ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Install', 'mainwp' ); ?></a>
						<?php } ?>
					<?php } ?>
					<?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ThemesAutoUpdate' ) ) { ?>
					<a href="<?php echo admin_url( 'admin.php?page=ThemesAutoUpdate' ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Advanced Auto Updates', 'mainwp' ); ?></a>
					<?php } ?>
					<?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ThemesIgnore' ) ) { ?>
					<a href="<?php echo admin_url( 'admin.php?page=ThemesIgnore' ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Ignored Updates', 'mainwp' ); ?></a>
					<?php } ?>
					<?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ThemesIgnoredAbandoned' ) ) { ?>
					<a href="<?php echo admin_url( 'admin.php?page=ThemesIgnoredAbandoned' ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Ignored Abandoned', 'mainwp' ); ?></a>
					<?php } ?>
					<?php
					if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
						foreach ( self::$subPages as $subPage ) {
							if ( MainWP_Menu::is_disable_menu_item( 3, 'Themes' . $subPage['slug'] ) ) {
								continue;
							}
							?>
							<a href="<?php echo admin_url( 'admin.php?page=Themes' . $subPage['slug'] ); ?>" class="mainwp-submenu"><?php echo esc_html( $subPage['title'] ); ?></a>
							<?php
						}
					}
					?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Method init_left_menu()
	 *
	 * Build arrays for each SubPage Menu Block.
	 *
	 * @param array $subPages Array of SubPages.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Menu::add_left_menu()
	 * @uses \MainWP\Dashboard\MainWP_Menu::init_subpages_left_menu()
	 * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
	 */
	public static function init_left_menu( $subPages = array() ) {
		MainWP_Menu::add_left_menu(
			array(
				'title'      => __( 'Themes', 'mainwp' ),
				'parent_key' => 'mainwp_tab',
				'slug'       => 'ThemesManage',
				'href'       => 'admin.php?page=ThemesManage',
				'icon'       => '<i class="paint brush icon"></i>',
			),
			1
		);

		$init_sub_subleftmenu = array(
			array(
				'title'      => __( 'Manage Themes', 'mainwp' ),
				'parent_key' => 'ThemesManage',
				'href'       => 'admin.php?page=ThemesManage',
				'slug'       => 'ThemesManage',
				'right'      => '',
			),
			array(
				'title'      => __( 'Install', 'mainwp' ),
				'parent_key' => 'ThemesManage',
				'href'       => 'admin.php?page=ThemesInstall',
				'slug'       => 'ThemesInstall',
				'right'      => 'install_themes',
			),
			array(
				'title'      => __( 'Advanced Auto Updates', 'mainwp' ),
				'parent_key' => 'ThemesManage',
				'href'       => 'admin.php?page=ThemesAutoUpdate',
				'slug'       => 'ThemesAutoUpdate',
				'right'      => '',
			),
			array(
				'title'      => __( 'Ignored Updates', 'mainwp' ),
				'parent_key' => 'ThemesManage',
				'href'       => 'admin.php?page=ThemesIgnore',
				'slug'       => 'ThemesIgnore',
				'right'      => '',
			),
			array(
				'title'      => __( 'Ignored Abandoned', 'mainwp' ),
				'parent_key' => 'ThemesManage',
				'href'       => 'admin.php?page=ThemesIgnoredAbandoned',
				'slug'       => 'ThemesIgnoredAbandoned',
				'right'      => '',
			),
		);

		MainWP_Menu::init_subpages_left_menu( $subPages, $init_sub_subleftmenu, 'ThemesManage', 'Themes' );

		foreach ( $init_sub_subleftmenu as $item ) {
			if ( MainWP_Menu::is_disable_menu_item( 3, $item['slug'] ) ) {
				continue;
			}
			MainWP_Menu::add_left_menu( $item, 2 );
		}
	}

	/**
	 * Method render_header()
	 *
	 * Render Themes SubPage Header.
	 *
	 * @param string $shownPage The page slug shown at this moment.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_top_header()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_page_navigation()
	 */
	public static function render_header( $shownPage = '' ) {
		$params = array( 'title' => __( 'Themes', 'mainwp' ) );

		MainWP_UI::render_top_header( $params );

			$renderItems = array();

			$renderItems[] = array(
				'title'  => __( 'Manage Themes', 'mainwp' ),
				'href'   => 'admin.php?page=ThemesManage',
				'active' => ( 'Manage' === $shownPage ) ? true : false,
			);

			if ( mainwp_current_user_have_right( 'dashboard', 'install_themes' ) ) {
				$renderItems[] = array(
					'title'  => __( 'Install', 'mainwp' ),
					'href'   => 'admin.php?page=ThemesInstall',
					'active' => ( 'Install' === $shownPage ) ? true : false,
				);
			}

			if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ThemesAutoUpdate' ) ) {
				$renderItems[] = array(
					'title'  => __( 'Advanced Auto Updates', 'mainwp' ),
					'href'   => 'admin.php?page=ThemesAutoUpdate',
					'active' => ( 'AutoUpdate' === $shownPage ) ? true : false,
				);
			}

			if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ThemesIgnore' ) ) {
				$renderItems[] = array(
					'title'  => __( 'Ignored Updates', 'mainwp' ),
					'href'   => 'admin.php?page=ThemesIgnore',
					'active' => ( 'Ignore' === $shownPage ) ? true : false,
				);
			}

			if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ThemesIgnoredAbandoned' ) ) {
				$renderItems[] = array(
					'title'  => __( 'Ignored Abandoned', 'mainwp' ),
					'href'   => 'admin.php?page=ThemesIgnoredAbandoned',
					'active' => ( 'IgnoreAbandoned' === $shownPage ) ? true : false,
				);
			}

			if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
				foreach ( self::$subPages as $subPage ) {
					if ( MainWP_Menu::is_disable_menu_item( 3, 'Themes' . $subPage['slug'] ) ) {
						continue;
					}
					$item           = array();
					$item['title']  = $subPage['title'];
					$item['href']   = 'admin.php?page=Themes' . $subPage['slug'];
					$item['active'] = ( $subPage['slug'] == $shownPage ) ? true : false;
					$renderItems[]  = $item;
				}
			}
			MainWP_UI::render_page_navigation( $renderItems );
	}

	/**
	 * Method render_footer()
	 *
	 * Close the page container.
	 *
	 * @param string $shownPage The page slug shown at this moment.
	 */
	public static function render_footer( $shownPage ) {
		echo '</div>';
	}

	/**
	 * Method render()
	 *
	 * Render the Theme SubPage content.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Cache::get_cached_context()
	 * @uses \MainWP\Dashboard\MainWP_Cache::get_cached_result()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_empty_bulk_actions()
	 * @uses \MainWP\Dashboard\MainWP_UI::select_sites_box()
	 */
	public static function render() {
		$cachedSearch    = MainWP_Cache::get_cached_context( 'Themes' );
		$selected_sites  = array();
		$selected_groups = array();

		if ( null != $cachedSearch ) {
			if ( is_array( $cachedSearch['sites'] ) ) {
				$selected_sites = $cachedSearch['sites'];
			} elseif ( is_array( $cachedSearch['groups'] ) ) {
				$selected_groups = $cachedSearch['groups'];
			}
		}

		$cachedResult = MainWP_Cache::get_cached_result( 'Themes' );

		self::render_header( 'Manage' );
		?>

		<div id="mainwp-manage-themes" class="ui alt segment">
			<div class="mainwp-main-content">
				<div class="ui mini form mainwp-actions-bar">
					<div class="ui stackable grid">
						<div class="ui two column row">
							<div class="column">
								<button id="mainwp-install-themes-to-selected-sites" class="ui mini green basic button" style="display: none"><?php esc_html_e( 'Install to Selected Site(s)', 'mainwp' ); ?></button>
								<?php
								/**
								 * Action: mainwp_themes_actions_bar_left
								 *
								 * Fires at the left side of the actions bar on the Themes screen, after the Bulk Actions menu.
								 *
								 * @since 4.0
								 */
								do_action( 'mainwp_themes_actions_bar_left' );
								?>
							</div>
							<div class="right aligned column">
								<div id="mainwp-themes-bulk-actions-wapper">
								<?php
								if ( is_array( $cachedResult ) && isset( $cachedResult['bulk_actions'] ) ) {
									echo $cachedResult['bulk_actions'];
								} else {
									MainWP_UI::render_empty_bulk_actions();
								}
								?>
								</div>

								<?php
								/**
								 * Action: mainwp_themes_actions_bar_right
								 *
								 * Fires at the right side of the actions bar on the Themes screen.
								 *
								 * @since 4.0
								 */
								do_action( 'mainwp_themes_actions_bar_right' );
								?>
							</div>
						</div>
					</div>
				</div>
				<div class="ui segment" id="mainwp_themes_wrap_table">
					<?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-manage-themes-info-message' ) ) : ?>
						<div class="ui info message">
							<i class="close icon mainwp-notice-dismiss" notice-id="mainwp-manage-themes-info-message"></i>
							<div><?php echo __( 'Manage installed themes on your child sites. Here you can activate, deactive, and delete installed themes.', 'mainwp' ); ?></div>
							<p><?php echo __( 'To Activate or Delete a specific theme, you must search only for Inactive themes on your child sites. If you search for Active or both Active and Inactive, the Activate and Delete actions will be disabled.', 'mainwp' ); ?></p>
							<p><?php echo sprintf( __( 'For additional help, please check this %1$shelp documentation%2$s.', 'mainwp' ), '<a href="https://kb.mainwp.com/docs/managing-themes-with-mainwp/" target="_blank">', '</a>' ); ?></p>
						</div>
					<?php endif; ?>
					<div id="mainwp-message-zone" class="ui message" style="display:none"></div>
					<div id="mainwp-loading-themes-row" class="ui active inverted dimmer" style="display:none">
						<div class="ui large text loader"><?php esc_html_e( 'Loading Themes...', 'mainwp' ); ?></div>
					</div>
					<div id="mainwp-themes-main-content" <?php echo ( null != $cachedSearch ) ? 'style="display: block;"' : ''; ?> >
						<div id="mainwp-themes-content">
						<?php if ( is_array( $cachedResult ) && isset( $cachedResult['result'] ) ) : ?>
								<?php echo $cachedResult['result']; ?>
						<?php else : ?>
							<table id="mainwp-manage-themes-table-placeholder" class="ui table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Sites / Themes', 'mainwp' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<tr>
										<td><?php esc_html_e( 'Please use the search options to find wanted themes.', 'mainwp' ); ?></td>
									</tr>
								</tbody>
							</table>
						<?php endif; ?>
						</div>
					</div>
				</div>
			</div>

			<div class="mainwp-side-content mainwp-no-padding">
				<?php
				/**
				 * Action: mainwp_manage_themes_sidebar_top
				 *
				 * Fires at the top of the sidebar on Manage themes.
				 *
				 * @since 4.1
				 */
				do_action( 'mainwp_manage_themes_sidebar_top' );
				?>
				<div class="mainwp-select-sites ui accordion mainwp-sidebar-accordion">
					<?php
					/**
					 * Action: mainwp_manage_themes_before_select_sites
					 *
					 * Fires before the Select Sites element on Manage themes.
					 *
					 * @since 4.1
					 */
					do_action( 'mainwp_manage_themes_before_select_sites' );
					?>
					<div class="title active"><i class="dropdown icon"></i> <?php esc_html_e( 'Select Sites', 'mainwp' ); ?></div>
					<div class="content active"><?php MainWP_UI::select_sites_box( 'checkbox', true, true, 'mainwp_select_sites_box_left', '', $selected_sites, $selected_groups ); ?></div>
					<?php
					/**
					 * Action: mainwp_manage_themes_after_select_sites
					 *
					 * Fires after the Select Sites element on Manage themes.
					 *
					 * @since 4.1
					 */
					do_action( 'mainwp_manage_themes_after_select_sites' );
					?>
				</div>
				<div class="ui fitted divider"></div>
				<div class="mainwp-search-options ui accordion mainwp-sidebar-accordion">
					<div class="active title"><i class="dropdown icon"></i> <?php esc_html_e( 'Select Status', 'mainwp' ); ?></div>
					<div class="content active">
					<?php
					/**
					 * Action: mainwp_manage_themes_before_search_options
					 *
					 * Fires before the Search Options element on Manage themes.
					 *
					 * @since 4.1
					 */
					do_action( 'mainwp_manage_themes_before_search_options' );
					?>
					<div class="ui info message">
						<i class="close icon mainwp-notice-dismiss" notice-id="themes-manage-info"></i>
						<?php esc_html_e( 'A theme needs to be Inactive in order for it to be Activated or Deleted.', 'mainwp' ); ?>
					</div>
					<div class="ui mini form">
						<div class="field">
							<select multiple="" class="ui fluid dropdown" id="mainwp_themes_search_by_status">
								<option value=""><?php esc_html_e( 'Select status', 'mainwp' ); ?></option>
								<option value="active" selected><?php esc_html_e( 'Active', 'mainwp' ); ?></option>
								<option value="inactive"><?php esc_html_e( 'Inactive', 'mainwp' ); ?></option>
							</select>
						</div>
					</div>
					<?php
					/**
					 * Action: mainwp_manage_themes_after_search_options
					 *
					 * Fires after the Search Options element on Manage themes.
					 *
					 * @since 4.1
					 */
					do_action( 'mainwp_manage_themes_after_search_options' );
					?>
				</div>
				</div>
				<div class="ui fitted divider"></div>
				<?php self::render_search_options(); ?>				
				<div class="ui fitted divider"></div>
				<div class="mainwp-search-submit">
					<?php
					/**
					 * Action: mainwp_manage_themes_before_submit_button
					 *
					 * Fires before the Submit Button element on Manage themes.
					 *
					 * @since 4.1
					 */
					do_action( 'mainwp_manage_themes_before_submit_button' );
					?>
					<input type="button" name="mainwp_show_themes" id="mainwp_show_themes" class="ui green big fluid button" value="<?php esc_attr_e( 'Show Themes', 'mainwp' ); ?>"/>
					<?php
					/**
					 * Action: mainwp_manage_themes_after_submit_button
					 *
					 * Fires after the Submit Button element on Manage themes.
					 *
					 * @since 4.1
					 */
					do_action( 'mainwp_manage_themes_after_submit_button' );
					?>
				</div>
				<?php
				/**
				 * Action: mainwp_manage_themes_sidebar_bottom
				 *
				 * Fires at the bottom of the sidebar on Manage themes.
				 *
				 * @since 4.1
				 */
				do_action( 'mainwp_manage_themes_sidebar_bottom' );
				?>
			</div>
			<div style="clear:both"></div>
		</div>
		<?php
		self::render_footer( 'Manage' );
	}

	/**
	 * Render the Search Options Meta Box.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Cache::get_cached_context()
	 */
	public static function render_search_options() {
		$cachedSearch = MainWP_Cache::get_cached_context( 'Themes' );
		$statuses     = isset( $cachedSearch['status'] ) ? $cachedSearch['status'] : array();
		if ( $cachedSearch && isset( $cachedSearch['keyword'] ) ) {
			$cachedSearch['keyword'] = trim( $cachedSearch['keyword'] );
		}
		$disabledNegative = ( null != $cachedSearch ) && ! empty( $cachedSearch['keyword'] ) ? false : true;
		$checkedNegative  = ! $disabledNegative && ( null != $cachedSearch ) && ! empty( $cachedSearch['not_criteria'] ) ? true : false;
		?>
		<div class="mainwp-search-options ui accordion mainwp-sidebar-accordion">
			<div class="title active"><i class="dropdown icon"></i> <?php esc_html_e( 'Search Options', 'mainwp' ); ?></div>
			<div class="content active">
				<div class="ui mini form">
					<div class="field">
						<div class="ui input fluid">
							<input type="text" placeholder="<?php esc_attr_e( 'Theme name', 'mainwp' ); ?>" id="mainwp_theme_search_by_keyword" size="50" class="text" value="<?php echo ( null != $cachedSearch ) ? esc_attr( $cachedSearch['keyword'] ) : ''; ?>"/>
						</div>
					</div>
					<div class="ui hidden fitted divider"></div>
						<div class="field">
							<div class="ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'Display sites not meeting the above search criteria.', 'mainwp' ); ?>" data-position="left center" data-inverted="">
								<input type="checkbox" <?php echo $disabledNegative ? 'disabled' : ''; ?> <?php echo ( $checkedNegative ? 'checked="true"' : '' ); ?> value="1" id="display_sites_not_meeting_criteria" />
								<label for="display_sites_not_meeting_criteria"><?php esc_html_e( 'Negative search', 'mainwp' ); ?></label>
								</div>
						</div>
				</div>
			</div>
		</div>		
		<?php
		if ( is_array( $statuses ) && 0 < count( $statuses ) ) {
			$status = '';
			foreach ( $statuses as $st ) {
				$status .= "'" . esc_js( $st ) . "',";
			}
			$status = rtrim( $status, ',' );
			?>
			<script type="text/javascript">
			jQuery( document ).ready( function () {
				jQuery( '#mainwp_themes_search_by_status' ).dropdown(  'set selected', [<?php echo $status; //phpcs:ignore -- escaped. ?>] );
			} );
			</script>
			<?php
		}
		?>
		<script type="text/javascript">
			jQuery( document ).on( 'keyup', '#mainwp_theme_search_by_keyword', function () {
				if( jQuery(this).val() != '' ){
					jQuery( '#display_sites_not_meeting_criteria' ).removeAttr('disabled');
				} else {
					jQuery( '#display_sites_not_meeting_criteria' ).closest('.checkbox').checkbox('set unchecked');
					jQuery( '#display_sites_not_meeting_criteria' ).attr('disabled', 'true');
				}
			});
		</script>
		<?php
	}

	/**
	 * Method render_table()
	 *
	 * Render the Child Sites Bulk action & Sidebar Meta boxes.
	 *
	 * @param string $keyword Search keyword parameter.
	 * @param string $status Search status parameter.
	 * @param array  $groups Selected groups of child sites.
	 * @param array  $sites Selected child sites.
	 * @param mixed  $not_criteria Show not criteria result.
	 *
	 * @return mixed $result Errors|HTML.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Cache::init_cache()
	 * @uses \MainWP\Dashboard\MainWP_Cache::add_context()
	 * @uses \MainWP\Dashboard\MainWP_Cache::add_result()
	 * @uses \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
	 * @uses \MainWP\Dashboard\MainWP_DB::query()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_by_group_id()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_DB::free_result()
	 * @uses \MainWP\Dashboard\MainWP_Themes_Handler::get_class_name()
	 * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
	 * @uses \MainWP\Dashboard\MainWP_Utility::map_site()
	 * @uses \MainWP\Dashboard\MainWP_Utility::get_nice_url()
	 */
	public static function render_table( $keyword, $status, $groups, $sites, $not_criteria ) { // phpcs:ignore -- complex function.
		MainWP_Cache::init_cache( 'Themes' );

		$output                      = new \stdClass();
		$output->errors              = array();
		$output->themes              = array();
		$output->not_criteria_themes = array();

		if ( 1 == get_option( 'mainwp_optimize' ) ) {
			if ( '' != $sites ) {
				foreach ( $sites as $k => $v ) {
					if ( MainWP_Utility::ctype_digit( $v ) ) {
						$website   = MainWP_DB::instance()->get_website_by_id( $v );
						$allThemes = json_decode( $website->themes, true );
						$_count    = count( $allThemes );
						$not_found = true;
						for ( $i = 0; $i < $_count; $i ++ ) {
							$theme = $allThemes[ $i ];
							if ( 'active' === $status || 'inactive' === $status ) {
								if ( 1 == $theme['active'] && 'active' !== $status ) {
									continue;
								} elseif ( 1 != $theme['active'] && 'inactive' !== $status ) {
									continue;
								}
							}

							if ( '' != $keyword && ! stristr( $theme['title'], $keyword ) ) {
								continue;
							}

							$theme['websiteid']  = $website->id;
							$theme['websiteurl'] = $website->url;
							$output->themes[]    = $theme;
							$not_found           = false;
						}
						if ( $not_found && $not_criteria ) {
							for ( $i = 0; $i < $_count; $i ++ ) {
								$theme                         = $allThemes[ $i ];
								$theme['websiteid']            = $website->id;
								$theme['websiteurl']           = $website->url;
								$output->not_criteria_themes[] = $theme;
							}
						}
					}
				}
			}

			if ( '' !== $groups ) {
				foreach ( $groups as $k => $v ) {
					if ( MainWP_Utility::ctype_digit( $v ) ) {
						$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_by_group_id( $v ) );
						while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
							if ( '' !== $website->sync_errors ) {
								continue;
							}
							$allThemes = json_decode( $website->themes, true );
							$_count    = count( $allThemes );
							$not_found = true;
							for ( $i = 0; $i < $_count; $i ++ ) {
								$theme = $allThemes[ $i ];
								if ( 'active' === $status || 'inactive' === $status ) {
									if ( 1 == $theme['active'] && 'active' !== $status ) {
										continue;
									} elseif ( 1 != $theme['active'] && 'inactive' !== $status ) {
										continue;
									}
								}
								if ( '' != $keyword && ! stristr( $theme['title'], $keyword ) ) {
									continue;
								}

								$theme['websiteid']  = $website->id;
								$theme['websiteurl'] = $website->url;
								$output->themes[]    = $theme;
								$not_found           = false;
							}

							if ( $not_found && $not_criteria ) {
								for ( $i = 0; $i < $_count; $i ++ ) {
									$theme                         = $allThemes[ $i ];
									$theme['websiteid']            = $website->id;
									$theme['websiteurl']           = $website->url;
									$output->not_criteria_themes[] = $theme;
								}
							}
						}
						MainWP_DB::free_result( $websites );
					}
				}
			}
		} else {
			$dbwebsites = array();

			if ( '' !== $sites ) {
				foreach ( $sites as $k => $v ) {
					if ( MainWP_Utility::ctype_digit( $v ) ) {
						$website                    = MainWP_DB::instance()->get_website_by_id( $v );
						$dbwebsites[ $website->id ] = MainWP_Utility::map_site(
							$website,
							array(
								'id',
								'url',
								'name',
								'adminname',
								'nossl',
								'privkey',
								'nosslkey',
								'http_user',
								'http_pass',
								'ssl_version',
							)
						);
					}
				}
			}

			if ( '' !== $groups ) {
				foreach ( $groups as $k => $v ) {
					if ( MainWP_Utility::ctype_digit( $v ) ) {
						$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_by_group_id( $v ) );
						while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
							if ( '' !== $website->sync_errors ) {
								continue;
							}
							$dbwebsites[ $website->id ] = MainWP_Utility::map_site(
								$website,
								array(
									'id',
									'url',
									'name',
									'adminname',
									'nossl',
									'privkey',
									'nosslkey',
									'http_user',
									'http_pass',
									'ssl_version',
								)
							);
						}
						MainWP_DB::free_result( $websites );
					}
				}
			}

			$post_data = array(
				'keyword' => $keyword,
			);

			if ( 'active' === $status || 'inactive' === $status ) {
				$post_data['status'] = $status;
				$post_data['filter'] = true;
			} else {
				$post_data['status'] = '';
				$post_data['filter'] = false;
			}

			$post_data['not_criteria'] = $not_criteria ? true : false;

			MainWP_Connect::fetch_urls_authed( $dbwebsites, 'get_all_themes', $post_data, array( MainWP_Themes_Handler::get_class_name(), 'themes_search_handler' ), $output );

			if ( 0 < count( $output->errors ) ) {
				foreach ( $output->errors as $siteid => $error ) {
					echo MainWP_Utility::get_nice_url( $dbwebsites[ $siteid ]->url ) . ' - ' . $error . '<br/>';
				}
				echo '<div class="ui hidden divider"></div>';
			}

			if ( count( $output->errors ) == count( $dbwebsites ) ) {
				return;
			}
		}

		MainWP_Cache::add_context(
			'Themes',
			array(
				'keyword'      => $keyword,
				'status'       => $status,
				'sites'        => ( '' !== $sites ) ? $sites : '',
				'groups'       => ( '' !== $groups ) ? $groups : '',
				'not_criteria' => $not_criteria ? true : false,
			)
		);

		$bulkActions = self::render_bulk_actions( $status );

		if ( 0 == count( $output->themes ) && ! $not_criteria ) {
			ob_start();
			?>
			<div class="ui message yellow"><?php esc_html_e( 'No themes found.', 'mainwp' ); ?></div>
			<?php
			$newOutput = ob_get_clean();

		} else {
			$sites             = array();
			$siteThemes        = array();
			$themes            = array();
			$themesVersion     = array();
			$themesRealVersion = array();
			$themesSlug        = array();
			$themes_list       = array();

			if ( $not_criteria ) {
				if ( property_exists( $output, 'not_criteria_themes' ) && ! empty( $output->not_criteria_themes ) ) {
					$themes_list = $output->not_criteria_themes;
				}
			} else {
				$themes_list = $output->themes;
			}

			foreach ( $themes_list as $theme ) {
				$theme['name']       = esc_html( $theme['name'] );
				$theme['version']    = esc_html( $theme['version'] );
				$theme['title']      = esc_html( $theme['title'] );
				$theme['slug']       = esc_html( $theme['slug'] );
				$theme['active']     = ( 1 == $theme['active'] ) ? 1 : 0;
				$theme['websiteurl'] = esc_url_raw( $theme['websiteurl'] );

				$sites[ $theme['websiteid'] ]                                  = $theme['websiteurl'];
				$themes[ $theme['name'] . '_' . $theme['version'] ]            = $theme['name'];
				$themesSlug[ $theme['name'] . '_' . $theme['version'] ]        = $theme['slug'];
				$themesVersion[ $theme['name'] . '_' . $theme['version'] ]     = array(
					'title' => $theme['title'],
					'ver'   => $theme['version'],
				);
				$themesRealVersion[ $theme['name'] . '_' . $theme['version'] ] = $theme['version'];
				if ( ! isset( $siteThemes[ $theme['websiteid'] ] ) || ! is_array( $siteThemes[ $theme['websiteid'] ] ) ) {
					$siteThemes[ $theme['websiteid'] ] = array();
				}
				$siteThemes[ $theme['websiteid'] ][ $theme['name'] . '_' . $theme['version'] ] = $theme;
			}

			uasort(
				$themesVersion,
				function( $a, $b ) {
					$ret = strcasecmp( $a['title'], $b['title'] );
					if ( 0 != $ret ) {
						return $ret;
					}
					return version_compare( $a['ver'], $b['ver'] );
				}
			);

			ob_start();
			self::render_manage_table( $sites, $themes, $siteThemes, $themesSlug, $themesVersion, $themesRealVersion );
			$newOutput = ob_get_clean();
		}

		$result = array(
			'result'       => $newOutput,
			'bulk_actions' => $bulkActions,
		);

		MainWP_Cache::add_result( 'Themes', $result );
		return $result;
	}

	/**
	 * Method render_manage_table()
	 *
	 * Render the Manage Themes table
	 *
	 * @param array  $sites List of sites.
	 * @param array  $themes List of themes.
	 * @param array  $siteThemes List of themes for the site.
	 * @param string $themesSlug Theme slug.
	 * @param string $themesVersion Installed theme version.
	 * @param string $themesRealVersion Current theme version.
	 */
	public static function render_manage_table( $sites, $themes, $siteThemes, $themesSlug, $themesVersion, $themesRealVersion ) {

		/**
		 * Action: mainwp_before_themes_table
		 *
		 * Fires before the Themes table.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_before_themes_table' );
		?>
		<table id="mainwp-manage-themes-table" style="min-width:100%" class="ui celled single line selectable compact unstackable table">
			<thead>
				<tr>
					<th class="mainwp-first-th no-sort"></th>
					<?php
					/**
					 * Action: mainwp_manage_themes_table_header
					 *
					 * @since 4.1
					 */
					do_action( 'mainwp_manage_themes_table_header' );
					?>
					<?php foreach ( $themesVersion as $theme_name => $theme_info ) : ?>
						<?php
						$theme_title = $theme_info['title'] . ' ' . $theme_info['ver'];
						$th_id       = strtolower( $theme_name );
						$th_id       = preg_replace( '/[[:space:]]+/', '_', $th_id );
						?>
						<th id="<?php echo esc_html( $th_id ); ?>" class="center aligned mainwp-manage-themes-theme-name">
							<?php echo esc_html( $theme_title ); ?>
						</th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $sites as $site_id => $site_url ) : ?>
					<?php $website = MainWP_DB::instance()->get_website_by_id( $site_id ); ?>
				<tr>
					<td style="padding-left:40px;padding-right:40px;">
						<input class="websiteId" type="hidden" name="id" value="<?php echo esc_attr( $site_id ); ?>"/>
						<div class="ui slider checkbox mainwp-768-hide">
							<input type="checkbox" value="" id="<?php echo esc_url( $site_url ); ?>" class="mainwp_themes_site_check_all"/><label></label>
						</div>
						<a href="<?php echo 'admin.php?page=SiteOpen&newWindow=yes&websiteid=' . $site_id; ?>&_opennonce=<?php echo wp_create_nonce( 'mainwp-admin-nonce' ); ?>" target="_blank" data-tooltip="<?php esc_html_e( 'Go to the site WP Admin', 'mainwp' ); ?>" data-inverted=""><i class="sign in alternate icon"></i></a>
						<a href="<?php echo esc_attr( $site_url ); ?>"><?php echo esc_html( $website->name ); ?></a>
					</td>
					<?php
					/**
					 * Action: mainwp_manage_themes_table_column
					 *
					 * @param int $site_id Site ID.
					 *
					 * @since 4.1
					 */
					do_action( 'mainwp_manage_themes_table_column', $site_id );
					?>
					<?php foreach ( $themesVersion as $theme_name => $theme_info ) : ?>
						<?php
						$active_status_class = '';
						if ( isset( $siteThemes[ $site_id ][ $theme_name ]['active'] ) && 1 == $siteThemes[ $site_id ][ $theme_name ]['active'] ) {
							$active_status_class = 'positive';
						} elseif ( isset( $siteThemes[ $site_id ][ $theme_name ]['active'] ) && 0 == $siteThemes[ $site_id ][ $theme_name ]['active'] ) {
							$active_status_class = 'negative';
						} else {
							$active_status_class = '';
						}

						if ( isset( $siteThemes[ $site_id ][ $theme_name ]['child_active'] ) && 1 == $siteThemes[ $site_id ][ $theme_name ]['child_active'] ) {
							$active_status_class .= ' child-active';
						}

						$not_delete = false;
						$parent_str = '';
						if ( isset( $siteThemes[ $site_id ][ $theme_name ]['parent_active'] ) && 1 == $siteThemes[ $site_id ][ $theme_name ]['parent_active'] ) {
							$parent_str = '<span data-tooltip="' . sprintf( __( 'Parent theme of the active theme (%s) on the site can not be deleted.', 'mainwp' ), isset( $siteThemes[ $site_id ][ $theme_name ]['child_theme'] ) ? $siteThemes[ $site_id ][ $theme_name ]['child_theme'] : '' ) . '" data-position="right center" data-inverted="" data-variation="mini"><i class="lock icon"></i></span>';
							$not_delete = true;
						}
						?>
						<td class="center aligned <?php echo $active_status_class; ?>">
							<?php if ( isset( $siteThemes[ $site_id ] ) && isset( $siteThemes[ $site_id ][ $theme_name ] ) ) : ?>
								<?php if ( '' != $parent_str ) : ?>
									<?php echo $parent_str; ?>
								<?php else : ?>
								<div class="ui checkbox">
									<input type="checkbox" value="<?php echo esc_attr( $themes[ $theme_name ] ); ?>" name="<?php echo esc_attr( $themes[ $theme_name ] ); ?>" class="mainwp-selected-theme" version="<?php echo esc_attr( $themesRealVersion[ $theme_name ] ); ?>" slug="<?php echo esc_attr( $themesSlug[ $theme_name ] ); ?>"  not-delete="<?php echo $not_delete ? 1 : 0; ?>" />
										<label></label>
								</div>
								<?php endif; ?>
							</div>
							<?php endif; ?>
						</td>
					<?php endforeach; ?>
				</tr>
				<?php endforeach; ?>
			</tbody>
			<tfoot>			
				<tr>
					<th class="mainwp-first-th"></th>
					<?php
					/**
					 * Action: mainwp_manage_themes_table_header
					 *
					 * @since 4.1
					 */
					do_action( 'mainwp_manage_themes_table_header' );
					?>
					<?php foreach ( $themesVersion as $theme_name => $theme_info ) : ?>
						<?php
						$theme_title = $theme_info['title'] . ' ' . $theme_info['ver'];
						$th_id       = strtolower( $theme_name );
						$th_id       = preg_replace( '/[[:space:]]+/', '_', $th_id );
						?>
						<th id="<?php echo esc_attr( $th_id ); ?>" class="center aligned">
							<div class="ui slider checkbox not-auto-init mainwp-768-hide">
								<input type="checkbox" value="<?php echo esc_attr( $themes[ $theme_name ] ); ?>" id="<?php echo esc_attr( $themes[ $theme_name ] ); ?>-<?php echo esc_attr( $themesRealVersion[ $theme_name ] ); ?>" version="<?php echo esc_attr( $themesRealVersion[ $theme_name ] ); ?>" class="mainwp_theme_check_all" />
								<label></label>
							</div>
						</th>
					<?php endforeach; ?>
				</tr>
			</tfoot>
		</table>
		<div class="ui horizontal list" id="mainwp-manage-themes-table-legend">
			<div class="item"><a class="ui empty circular label" style="background:#f7ffe6;border:1px solid #7fb100;"></a> <?php echo esc_html__( 'Installed/Active', 'mainwp' ); ?></div>
			<div class="item"><a class="ui empty circular label" style="background:#e3ffa7;border:1px solid #7fb100;"></a> <?php echo esc_html__( 'Installed/Active/Child theme', 'mainwp' ); ?></div>
			<div class="item"><a class="ui empty circular label" style="background:#ffe7e7;border:1px solid #910000;"></a> <?php echo esc_html__( 'Installed/Inactive', 'mainwp' ); ?></div>
			<div class="item"><i class="ui red lock icon"></i> <?php echo esc_html__( 'Parent of Active Child Theme/Installed/Inactive', 'mainwp' ); ?></div>
			<div class="item"><a class="ui empty circular label" style="background:#fafafa;border:1px solid #f4f4f4;"></a> <?php echo esc_html__( 'Not installed', 'mainwp' ); ?></div>
		</div>

		<?php
		/**
		 * Action: mainwp_after_themes_table
		 *
		 * Fires after the Themes table.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_after_themes_table' );

		$table_features = array(
			'searching'      => 'true',
			'paging'         => 'true', // to fix.
			'info'           => 'false',
			'colReorder'     => 'true',
			'stateSave'      => 'true',
			'ordering'       => 'true',
			'scrollCollapse' => 'true',
			'scrollY'        => '500',
			'scrollX'        => 'true',
			'scroller'       => 'true',
			'fixedColumns'   => 'true',
			'responsive'     => 'true',
		);

		/**
		 * Filter: mainwp_themes_table_features
		 *
		 * Filter the Themes table features.
		 *
		 * @since 4.1
		 */
		$table_features = apply_filters( 'mainwp_themes_table_features', $table_features );
		?>

		<style type="text/css">
			thead th.mainwp-first-th {
				position: sticky !important;
				left: 0  !important;
				top: 0  !important;
				z-index: 9 !important;
			}
			#mainwp-manage-themes-table tbody tr td:first-child {
				position: sticky !important;
				left: 0;
				top: 0;
				background: #f9fafb;
				z-index: 9 !important;
			}
		</style>
		<script type="text/javascript">
			var responsive = <?php echo $table_features['responsive']; ?>;
			if( jQuery( window ).width() > 1140 ) {
				responsive = false;
			}
			jQuery( document ).ready( function( $ ) {
				jQuery( '#mainwp-manage-themes-table' ).DataTable( {
					"paging" : <?php echo $table_features['paging']; ?>,
					"colReorder" : <?php echo $table_features['colReorder']; ?>,
					"stateSave" :  <?php echo $table_features['stateSave']; ?>,
					"ordering" : <?php echo $table_features['ordering']; ?>,
					"searching" : <?php echo $table_features['searching']; ?>,
					"info" : <?php echo $table_features['info']; ?>,
					"scrollCollapse" : <?php echo $table_features['scrollCollapse']; ?>,
					"scrollY" : <?php echo $table_features['scrollY']; ?>,
					"scrollX" : <?php echo $table_features['scrollX']; ?>,
					"scroller" : <?php echo $table_features['scroller']; ?>,
					"fixedColumns" : <?php echo $table_features['fixedColumns']; ?>,
					"columnDefs": [ { "orderable": false, "targets": [ 0 ] } ],
					"responsive": responsive,
				} );
				jQuery( '.mainwp-ui-page .ui.checkbox:not(.not-auto-init)' ).checkbox(); // to fix onclick on plugins checkbox for sorting.
			} );
		</script>
		<?php
	}

	/**
	 * Render the bulk actions UI.
	 *
	 * @param mixed $status Theme status.
	 *
	 * @return mixed $bulkActions
	 */
	public static function render_bulk_actions( $status ) {
			ob_start();
		?>
		<select class="ui dropdown" id="mainwp-bulk-actions">
			<option value="none"><?php esc_html_e( 'Bulk Actions', 'mainwp' ); ?></option>
				<?php if ( mainwp_current_user_have_right( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
					<option data-value="ignore_updates" value="ignore_updates"><?php esc_html_e( 'Ignore updates', 'mainwp' ); ?></option>
				<?php endif; ?>
				<?php if ( mainwp_current_user_have_right( 'dashboard', 'activate_themes' ) ) : ?>
					<?php if ( 'inactive' === $status ) : ?>
					<option data-value="activate" value="activate"><?php esc_html_e( 'Activate', 'mainwp' ); ?></option>
					<?php else : ?>
						<option data-value="activate" disabled value="activate"><?php esc_html_e( 'Activate', 'mainwp' ); ?></option>
					<?php endif; ?>
				<?php endif; ?>
				<?php if ( 'inactive' === $status ) : ?>
					<?php if ( mainwp_current_user_have_right( 'dashboard', 'delete_themes' ) ) : ?>
					<option data-value="delete" value="delete"><?php esc_html_e( 'Delete', 'mainwp' ); ?></option>
					<?php endif; ?>
				<?php else : ?>
					<?php if ( mainwp_current_user_have_right( 'dashboard', 'delete_themes' ) ) : ?>
						<option data-value="delete" disabled value="delete"><?php esc_html_e( 'Delete', 'mainwp' ); ?></option>
					<?php endif; ?>
				<?php endif; ?>
				<?php if ( 'all' === $status ) : ?>
					<?php if ( mainwp_current_user_have_right( 'dashboard', 'activate_themes' ) ) : ?>
						<option data-value="activate" disabled value="activate"><?php esc_html_e( 'Activate', 'mainwp' ); ?></option>
					<?php endif; ?>
					<?php if ( mainwp_current_user_have_right( 'dashboard', 'delete_themes' ) ) : ?>
						<option data-value="delete" disabled value="delete"><?php esc_html_e( 'Delete', 'mainwp' ); ?></option>
				<?php endif; ?>
				<?php endif; ?>

				<?php
				/**
				 * Action: mainwp_themes_bulk_action
				 *
				 * Adds a new action to the Manage Themes bulk actions menu.
				 *
				 * @param string $status Status search parameter.
				 *
				 * @since 4.1
				 */
				do_action( 'mainwp_themes_bulk_action' );
				?>
		</select>
		<button class="ui mini basic button" href="javascript:void(0)" id="mainwp-do-themes-bulk-actions"><?php esc_html_e( 'Apply', 'mainwp' ); ?></button>
		<span id="mainwp_bulk_action_loading"><i class="ui active inline loader tiny"></i></span>
		<?php
		$bulkActions = ob_get_clean();
		return $bulkActions;
	}


	/** Render the Install Themes Tab. */
	public static function render_install() {
		wp_enqueue_script( 'mainwp-theme', MAINWP_PLUGIN_URL . 'assets/js/mainwp-theme.js', array( 'wp-backbone', 'wp-a11y' ), MAINWP_VERSION, true );
		wp_localize_script(
			'mainwp-theme',
			'_mainwpThemeSettings',
			array(
				'themes'          => false,
				'settings'        => array(
					'isInstall'  => true,
					'canInstall' => false,
					'installURI' => null,
					'adminUrl'   => wp_parse_url( self_admin_url(), PHP_URL_PATH ),
				),
				'l10n'            => array(
					'addNew'            => __( 'Add new theme' ),
					'search'            => __( 'Search themes' ),
					'searchPlaceholder' => __( 'Search themes...' ),
					'upload'            => __( 'Upload theme' ),
					'back'              => __( 'Back' ),
					'error'             => __( 'An unexpected error occurred. Something may be wrong with WordPress.org or this server&#8217;s configuration. If you continue to have problems, please try the <a href="https://wordpress.org/support/">support forums</a>.' ),
					'themesFound'       => __( 'Number of themes found: %d' ),
					'noThemesFound'     => __( 'No themes found. Try a different search.' ),
					'collapseSidebar'   => __( 'Collapse sidebar' ),
					'expandSidebar'     => __( 'Expand sidebar' ),
				),
				'installedThemes' => array(),
			)
		);
		self::render_header( 'Install' );
		self::render_themes_table();
		self::render_footer( 'Install' );
	}

	/**
	 * Render the Themes table for the Install Themes Tab.
	 *
	 * @uses \MainWP\Dashboard\MainWP_UI::render_modal_install_plugin_theme()
	 * @uses \MainWP\Dashboard\MainWP_UI::select_sites_box()
	 * @uses \MainWP\Dashboard\MainWP_Install_Bulk::render_upload()
	 */
	public static function render_themes_table() {
		if ( ! mainwp_current_user_have_right( 'dashboard', 'install_themes' ) ) {
			mainwp_do_not_have_permissions( __( 'install themes', 'mainwp' ) );
			return;
		}
		$favorites_enabled = is_plugin_active( 'mainwp-favorites-extension/mainwp-favorites-extension.php' );
		?>
		<div class="ui alt segment" id="mainwp-install-themes">
			<div class="mainwp-main-content">
				<div class="mainwp-actions-bar">
					<div class="ui stackable grid">
						<div class="ui two column row">
							<div class="column">
								<div class="ui fluid search focus">
									<div class="ui icon fluid input hide-if-upload" id="mainwp-search-themes-input-container" skeyword="<?php echo isset( $_GET['s'] ) ? esc_html( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : ''; ?>"></div>
									<div class="results"></div>
								</div>
								<?php
								/**
								 * Install Themes actions bar (left)
								 *
								 * Fires at the left side of the actions bar on the Install Themes screen, after the search form.
								 *
								 * @since 4.0
								 */
								do_action( 'mainwp_install_themes_actions_bar_left' );
								?>
							</div>
							<div class="right aligned column">
								<div class="ui buttons">
									<a href="#" class="ui button browse-themes" ><?php esc_html_e( 'Install from WordPress.org', 'mainwp' ); ?></a>
									<div class="or"></div>
									<a href="#" class="ui button upload" ><?php esc_html_e( 'Upload .zip file', 'mainwp' ); ?></a>
								</div>
								<?php
								/**
								 * Install Themes actions bar (right)
								 *
								 * Fires at the right side of the actions bar on the Install Themes screen.
								 *
								 * @since 4.0
								 */
								do_action( 'mainwp_install_themes_actions_bar_right' );
								?>
							</div>
						</div>
					</div>
				</div>
				<div class="ui segment">
					<?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-install-themes-info-message' ) ) : ?>
						<div class="ui info message">
							<i class="close icon mainwp-notice-dismiss" notice-id="mainwp-install-themes-info-message"></i>
							<?php echo sprintf( __( 'Install themes on your child sites.  You can install themes from the WordPress.org repository or by uploading a ZIP file.  For additional help, please check this %1$shelp documentation%2$s.', 'mainwp' ), '<a href="https://kb.mainwp.com/docs/install-themes/" target="_blank">', '</a>' ); ?>
						</div>
					<?php endif; ?>
					<div id="mainwp-message-zone" class="ui message" style="display:none;"></div>
					<div class="mainwp-upload-theme">
						<?php MainWP_Install_Bulk::render_upload( 'theme' ); ?>
					</div>
					<div id="themes-loading" class="ui large text loader"><?php esc_html_e( 'Loading Themes...', 'mainwp' ); ?></div>
					<form id="theme-filter" method="post">
						<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
						<div class="mainwp-browse-themes content-filterable hide-if-upload"></div>
						<div class="theme-install-overlay wp-full-overlay expanded"></div>
					</form>

					<?php MainWP_UI::render_modal_install_plugin_theme( 'theme' ); ?>
				</div>
			</div>
			<div class="mainwp-side-content mainwp-no-padding">
				<?php do_action( 'mainwp_manage_themes_sidebar_top' ); ?>
				<div class="mainwp-select-sites ui accordion mainwp-sidebar-accordion">
					<?php do_action( 'mainwp_manage_themes_before_select_sites' ); ?>
					<div class="active title"><i class="dropdown icon"></i> <?php esc_html_e( 'Select Sites', 'mainwp' ); ?></div>
					<div class="content active">
					<?php
					$selected_sites  = array();
					$selected_groups = array();

					if ( isset( $_GET['selected_sites'] ) ) {
						$selected_sites = explode( '-', sanitize_text_field( wp_unslash( $_GET['selected_sites'] ) ) ); // sanitize ok.
						$selected_sites = array_map( 'intval', $selected_sites );
						$selected_sites = array_filter( $selected_sites );
					}
					?>
					<?php MainWP_UI::select_sites_box( 'checkbox', true, true, 'mainwp_select_sites_box_left', '', $selected_sites, $selected_groups ); ?>
					</div>
					<?php do_action( 'mainwp_manage_themes_after_select_sites' ); ?>
				</div>
				<div class="ui fitted divider"></div>
				<div class="mainwp-search-options ui accordion mainwp-sidebar-accordion">
					<?php do_action( 'mainwp_manage_themes_before_search_options' ); ?>
					<div class="title active"><i class="dropdown icon"></i> <?php esc_html_e( 'Installation Options', 'mainwp' ); ?></div>
					<div class="content active">
					<div class="ui form">
						<div class="field">
							<div class="ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'If enabled and the theme already installed on the sites, the already installed version will be overwritten.', 'mainwp' ); ?>" data-position="left center" data-inverted="">
								<input type="checkbox" value="2" checked="checked" id="chk_overwrite" />
								<label for="chk_overwrite"><?php esc_html_e( 'Overwrite existing version', 'mainwp' ); ?></label>
							</div>
						</div>
					</div>
					</div>
					<?php do_action( 'mainwp_manage_themes_after_search_options' ); ?>
				</div>
				<div class="ui fitted divider"></div>
				<div class="mainwp-search-submit">
					<?php do_action( 'mainwp_manage_themes_before_submit_button' ); ?>
				<?php
				/**
				 * Disables themes installation
				 *
				 * Filters whether file modifications are allowed on the Dashboard site. If not, installation process will be disabled too.
				 *
				 * @since 4.1
				 */
				$allow_install = apply_filters( 'file_mod_allowed', true, 'mainwp_install_theme' );
				if ( $allow_install ) {
					?>
					<input type="button" value="<?php esc_attr_e( 'Complete Installation', 'mainwp' ); ?>" class="ui green big fluid button" bulk-action="install" id="mainwp_theme_bulk_install_btn" name="bulk-install">
					<?php
				}
				?>
				<?php do_action( 'mainwp_manage_themes_after_submit_button' ); ?>
				</div>
				<?php do_action( 'mainwp_manage_themes_sidebar_bottom' ); ?>
			</div>
			<div class="ui clearing hidden divider"></div>
		</div>

		<script id="tmpl-theme" type="text/template">
				<# if ( data.screenshot_url ) { #>
				<div class="image">
					<img src="{{ data.screenshot_url }}" />
				</div>
				<# } #>
				<div class="content">
					<div class="header">{{ data.name }}</div>
					<div class="meta">
						<a><?php printf( __( 'By %s', 'mainwp' ), '{{ data.author }}' ); ?></a>
					</div>
				</div>
				<div class="extra content">
					<span class="right floated"><?php printf( __( 'Version: %s', 'mainwp' ), '{{ data.version }}' ); ?></span>
					<# if ( data.rating ) { #>
						<div class="star-rating rating-{{ Math.round( data.rating / 10 ) * 10 }}">
							<span class="one"></span><span class="two"></span><span class="three"></span><span class="four"></span><span class="five"></span>
							<small class="ratings">{{ data.num_ratings }}</small>
						</div>
					<# } else { #>
						<div class="star-rating">
							<small class="ratings"><?php esc_html_e( 'This theme has not been rated yet.', 'mainwp' ); ?></small>
						</div>
					<# } #>
				</div>
				<div class="extra content mainwp-theme-lnks">
					<a href="#" id="mainwp-{{data.slug}}-preview" class="ui mini button mainwp-theme-preview"><?php esc_html_e( 'Preview', 'mainwp' ); ?></a>
					<div class="ui radio checkbox right floated">
						<input name="install-theme" type="radio" id="install-theme-{{data.slug}}" title="Install {{data.name}}">
						<label for="install-theme-{{data.slug}}"><?php esc_html_e( 'Install this Theme', 'mainwp' ); ?></label>
					</div>
				</div>
				<?php if ( $favorites_enabled ) { ?>
				<div class="extra content">
					<span><?php esc_html_e( 'Add to Favorites', 'mainwp-favorites-extension' ); ?></span>
					<a class="ui huge star rating right floated" data-max-rating="1" id="add-favorite-theme-{{data.slug}}"></a>
				</div>
				<?php } ?>
		</script>

		<script id="tmpl-theme-preview" type="text/template">
			<div class="wp-full-overlay-sidebar">
				<div class="wp-full-overlay-header">
					<a href="#" class="close-full-overlay"><span class="screen-reader-text"><?php esc_html_e( 'Close', 'mainwp' ); ?></span></a>
					<a href="#" class="previous-theme"><span class="screen-reader-text"><?php esc_html_e( 'Previous', 'mainwp' ); ?></span></a>
					<a href="#" class="next-theme"><span class="screen-reader-text"><?php esc_html_e( 'Next', 'mainwp' ); ?></span></a>
				</div>
				<div class="wp-full-overlay-sidebar-content">
					<div class="install-theme-info">
						<h3 class="theme-name">{{ data.name }}</h3>
						<span class="theme-by"><?php printf( __( 'By %s', 'mainwp' ), '{{ data.author }}' ); ?></span>
						<img class="theme-screenshot" src="{{ data.screenshot_url }}" alt="" />
						<div class="theme-details">
							<# if ( data.rating ) { #>
								<div class="star-rating rating-{{ Math.round( data.rating / 10 ) * 10 }}">
									<span class="one"></span><span class="two"></span><span class="three"></span><span class="four"></span><span class="five"></span>
									<small class="ratings">{{ data.num_ratings }}</small>
								</div>
							<# } else { #>
								<div class="star-rating">
									<small class="ratings"><?php esc_html_e( 'This theme has not been rated yet.', 'mainwp' ); ?></small>
								</div>
							<# } #>
							<div class="theme-version"><?php printf( __( 'Version: %s', 'mainwp' ), '{{ data.version }}' ); ?></div>
							<div class="theme-description">{{{ data.description }}}</div>
						</div>
					</div>
				</div>
				<div class="wp-full-overlay-footer">
					<button type="button" class="collapse-sidebar button-secondary" aria-expanded="true" aria-label="<?php esc_attr_e( 'Collapse Sidebar', 'mainwp' ); ?>">
						<span class="collapse-sidebar-arrow"></span>
						<span class="collapse-sidebar-label"><?php esc_html_e( 'Collapse', 'mainwp' ); ?></span>
					</button>
				</div>
			</div>
			<div class="wp-full-overlay-main">
				<iframe src="{{ data.preview_url }}" title="<?php esc_attr_e( 'Preview', 'mainwp' ); ?>" />
			</div>
		</script>
		<script type="text/javascript">
			jQuery( document ).ready( function() {
				setTimeout( function () {
					jQuery('#wp-filter-search-input').val( jQuery('#mainwp-search-themes-input-container').attr('skeyword') ); 
				}, 1000 );
			});
		</script>
		<?php
	}

	/**
	 * Render the Themes Auto Update Tab.
	 *
	 * @uses \MainWP\Dashboard\MainWP_UI::render_modal_edit_notes()
	 */
	public static function render_auto_update() { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		$cachedThemesSearch = null;
		if ( isset( $_SESSION['SNThemesAllStatus'] ) ) {
			$cachedThemesSearch = $_SESSION['SNThemesAllStatus'];
		}

		self::render_header( 'AutoUpdate' );

		if ( ! mainwp_current_user_have_right( 'dashboard', 'trust_untrust_updates' ) ) {
			mainwp_do_not_have_permissions( __( 'trust/untrust updates', 'mainwp' ) );
			return;
		} else {
			$snThemeAutomaticDailyUpdate = get_option( 'mainwp_themeAutomaticDailyUpdate' );

			if ( false === $snThemeAutomaticDailyUpdate ) {
				$snThemeAutomaticDailyUpdate = get_option( 'mainwp_automaticDailyUpdate' );
				update_option( 'mainwp_themeAutomaticDailyUpdate', $snThemeAutomaticDailyUpdate );
			}

			?>
			<div class="ui alt segment" id="mainwp-theme-auto-updates">
				<div class="mainwp-main-content">
					<div class="mainwp-actions-bar">
						<div class="ui mini form stackable grid">
							<div class="ui two column row">
								<div class="left aligned column">
									<select id="mainwp-bulk-actions" name="bulk_action" class="ui mini selection dropdown">
										<option class="item" value=""><?php esc_html_e( 'Bulk Actions', 'mainwp' ); ?></option>
										<option class="item" value="trust"><?php esc_html_e( 'Trust', 'mainwp' ); ?></option>
										<option class="item" value="untrust"><?php esc_html_e( 'Untrust', 'mainwp' ); ?></option>
												<?php
												/**
												 * Action: mainwp_themes_auto_updates_bulk_action
												 *
												 * Adds new action to the bulk actions menu on Themes Auto Updates.
												 *
												 * @since 4.1
												 */
												do_action( 'mainwp_themes_auto_updates_bulk_action' );
												?>
										</select>
										<input type="button" name="" id="mainwp-bulk-trust-themes-action-apply" class="ui mini basic button" value="<?php esc_attr_e( 'Apply', 'mainwp' ); ?>"/>
									</div>
								<div class="right aligned column"></div>
							</div>
						</div>
					</div>
					<?php if ( isset( $_GET['message'] ) && 'saved' === $_GET['message'] ) : ?>
						<div class="ui message green"><?php esc_html_e( 'Settings have been saved.', 'mainwp' ); ?></div>
					<?php endif; ?>
					<div id="mainwp-message-zone" class="ui message" style="display:none"></div>
					<div id="mainwp-auto-updates-themes-content" class="ui segment">
						<?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-disable-auto-updates-info-message' ) ) : ?>
						<div class="ui info message">
							<i class="close icon mainwp-notice-dismiss" notice-id="mainwp-disable-auto-updates-info-message"></i>
							<div><?php echo sprintf( __( 'Check out %1$show to disable the WordPress built in auto-updates feature%2$s.', 'mainwp' ), '<a href="https://mainwp.com/how-to-disable-automatic-plugin-and-theme-updates-on-your-child-sites/" target="_blank">', '</a>' ); ?></div>
						</div>
						<?php endif; ?>
						<?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-themes-auto-updates-info-message' ) ) : ?>
						<div class="ui info message">
							<i class="close icon mainwp-notice-dismiss" notice-id="mainwp-themes-auto-updates-info-message"></i>
							<div><?php esc_html_e( 'The MainWP Advanced Auto Updates feature is a tool for your Dashboard to automatically update themes that you trust to be updated without breaking your Child sites.', 'mainwp' ); ?></div>
							<div><?php esc_html_e( 'Only mark themes as trusted if you are absolutely sure they can be automatically updated by your MainWP Dashboard without causing issues on the Child sites!	', 'mainwp' ); ?></div>
							<div><strong><?php esc_html_e( 'Advanced Auto Updates a delayed approximately 24 hours from the update release. Ignored themes can not be automatically updated.', 'mainwp' ); ?></strong></div>
						</div>
						<?php endif; ?>
						<div class="ui inverted dimmer">
							<div class="ui text loader"><?php esc_html_e( 'Loading themes', 'mainwp' ); ?></div>
						</div>
						<div id="mainwp-auto-updates-themes-table-wrapper">
							<?php
							if ( isset( $_SESSION['SNThemesAll'] ) ) {
								self::render_all_themes_table( $_SESSION['SNThemesAll'] );
							}
							?>
						</div>
					</div>
				</div>
				<div class="mainwp-side-content mainwp-no-padding">
					<?php do_action( 'mainwp_manage_themes_sidebar_top' ); ?>
					<div class="mainwp-search-options ui accordion mainwp-sidebar-accordion">
						<div class="title active"><i class="dropdown icon"></i> <?php esc_html_e( 'Theme Status to Search', 'mainwp' ); ?></div>
						<div class="content active">
						<div class="ui mini form">
							<div class="field">
								<select class="ui fluid dropdown" id="mainwp_au_theme_status">
									<option value="all" <?php echo ( null != $cachedThemesSearch && 'all' === $cachedThemesSearch['theme_status'] ) ? 'selected' : ''; ?>><?php esc_html_e( 'Active and Inactive', 'mainwp' ); ?></option>
									<option value="active" <?php echo ( null != $cachedThemesSearch && 'active' === $cachedThemesSearch['theme_status'] ) ? 'selected' : ''; ?>><?php esc_html_e( 'Active', 'mainwp' ); ?></option>
									<option value="inactive" <?php echo ( null != $cachedThemesSearch && 'inactive' === $cachedThemesSearch['theme_status'] ) ? 'selected' : ''; ?>><?php esc_html_e( 'Inactive', 'mainwp' ); ?></option>
								</select>
							</div>
						</div>
					</div>
					</div>
					<div class="ui fitted divider"></div>
					<div class="mainwp-search-options ui accordion mainwp-sidebar-accordion">
						<?php do_action( 'mainwp_manage_themes_before_search_options' ); ?>
						<div class="title active"><i class="dropdown icon"></i> <?php esc_html_e( 'Search Options', 'mainwp' ); ?></div>
						<div class="content active">
						<div class="ui mini form">
							<div class="field">
								<select class="ui fluid dropdown" id="mainwp_au_theme_trust_status">
									<option value="all" <?php echo ( null != $cachedThemesSearch && 'all' === $cachedThemesSearch['status'] ) ? 'selected' : ''; ?>><?php esc_html_e( 'Trusted, Not trusted and Ignored', 'mainwp' ); ?></option>
									<option value="trust" <?php echo ( null != $cachedThemesSearch && 'trust' === $cachedThemesSearch['status'] ) ? 'selected' : ''; ?>><?php esc_html_e( 'Trusted', 'mainwp' ); ?></option>
									<option value="untrust" <?php echo ( null != $cachedThemesSearch && 'untrust' === $cachedThemesSearch['status'] ) ? 'selected' : ''; ?>><?php esc_html_e( 'Not trusted', 'mainwp' ); ?></option>
									<option value="ignored" <?php echo ( null != $cachedThemesSearch && 'ignored' === $cachedThemesSearch['status'] ) ? 'selected' : ''; ?>><?php esc_html_e( 'Ignored', 'mainwp' ); ?></option>
								</select>
							</div>
							<div class="field">
								<div class="ui input fluid">
									<input type="text" placeholder="<?php esc_attr_e( 'Theme name', 'mainwp' ); ?>" id="mainwp_au_theme_keyword" class="text" value="<?php echo ( null !== $cachedThemesSearch ) ? $cachedThemesSearch['keyword'] : ''; ?>">
								</div>
							</div>
						</div>
						</div>
						<?php do_action( 'mainwp_manage_themes_after_search_options' ); ?>
					</div>
					<div class="ui fitted divider"></div>
					<div class="mainwp-search-submit">
						<?php do_action( 'mainwp_manage_themes_before_submit_button' ); ?>
						<a href="#" class="ui green big fluid button" id="mainwp_show_all_active_themes"><?php esc_html_e( 'Show Themes', 'mainwp' ); ?></a>
						<?php do_action( 'mainwp_manage_themes_after_submit_button' ); ?>
					</div>
					<?php do_action( 'mainwp_manage_themes_sidebar_bottom' ); ?>
				</div>
			</div>
			<?php
		}
		MainWP_UI::render_modal_edit_notes( 'theme' );
		self::render_footer( 'AutoUpdate' );
	}

	/**
	 * Method render_all_themes_table()
	 *
	 * Render the All Themes Table.
	 *
	 * @param null $output Function output.
	 *
	 * @return void
	 *
	 * @uses \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::get_user_extension()
	 * @uses \MainWP\Dashboard\MainWP_DB::query()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_for_current_user()
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_DB::free_result()
	 * @uses \MainWP\Dashboard\MainWP_Themes_Handler::get_class_name()
	 * @uses \MainWP\Dashboard\MainWP_Utility::map_site()
	 * @uses \MainWP\Dashboard\MainWP_Utility::get_nice_url()
	 */
	public static function render_all_themes_table( $output = null ) { // phpcs:ignore -- not quite complex function.
		$keyword       = null;
		$search_status = 'all';

		if ( null == $output ) {
			$keyword             = isset( $_POST['keyword'] ) && ! empty( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : null;
			$search_status       = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : 'all';
			$search_theme_status = isset( $_POST['theme_status'] ) ? sanitize_text_field( wp_unslash( $_POST['theme_status'] ) ) : 'all';

			$output         = new \stdClass();
			$output->errors = array();
			$output->themes = array();

			if ( 1 == get_option( 'mainwp_optimize' ) ) {
				$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );
				while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
					$allThemes = json_decode( $website->themes, true );
					$_count    = count( $allThemes );
					for ( $i = 0; $i < $_count; $i ++ ) {
						$theme = $allThemes[ $i ];
						if ( 'all' !== $search_theme_status ) {
							if ( 1 == $theme['active'] && 'active' !== $search_theme_status ) {
								continue;
							} elseif ( 1 != $theme['active'] && 'inactive' !== $search_theme_status ) {
								continue;
							}
						}
						if ( '' != $keyword && false === stristr( $theme['name'], $keyword ) ) {
							continue;
						}
						$theme['websiteid']  = $website->id;
						$theme['websiteurl'] = $website->url;
						$output->themes[]    = $theme;
					}
				}
				MainWP_DB::free_result( $websites );
			} else {
				$dbwebsites = array();
				$websites   = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );
				while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
					$dbwebsites[ $website->id ] = MainWP_Utility::map_site(
						$website,
						array(
							'id',
							'url',
							'name',
							'adminname',
							'nossl',
							'privkey',
							'nosslkey',
							'http_user',
							'http_pass',
							'ssl_version',
						)
					);
				}
				MainWP_DB::free_result( $websites );

				$post_data = array( 'keyword' => $keyword );

				if ( 'active' === $search_theme_status || 'inactive' === $search_theme_status ) {
					$post_data['status'] = $search_theme_status;
					$post_data['filter'] = true;
				} else {
					$post_data['status'] = '';
					$post_data['filter'] = false;
				}

				MainWP_Connect::fetch_urls_authed( $dbwebsites, 'get_all_themes', $post_data, array( MainWP_Themes_Handler::get_class_name(), 'themes_search_handler' ), $output );

				if ( 0 < count( $output->errors ) ) {
					foreach ( $output->errors as $siteid => $error ) {
						echo MainWP_Utility::get_nice_url( $dbwebsites[ $siteid ]->url ) . ' - ' . $error . ' <br/>';
					}
					echo '<div class="ui hidden divider"></div>';
				}

				if ( count( $output->errors ) == count( $dbwebsites ) ) {
					$_SESSION['SNThemesAll'] = $output;
					return;
				}
			}

			$_SESSION['SNThemesAll']       = $output;
			$_SESSION['SNThemesAllStatus'] = array(
				'keyword'      => $keyword,
				'status'       => $search_status,
				'theme_status' => $search_theme_status,
			);
		} else {
			if ( isset( $_SESSION['SNThemesAllStatus'] ) ) {
				$keyword             = $_SESSION['SNThemesAllStatus']['keyword'];
				$search_status       = $_SESSION['SNThemesAllStatus']['status'];
				$search_theme_status = $_SESSION['SNThemesAllStatus']['theme_status'];
			}
		}

		if ( 0 == count( $output->themes ) ) {
			?>
			<div class="ui message yellow"><?php esc_html_e( 'No themes found.', 'mainwp' ); ?></div>
			<?php
			return;
		}

		$themes = array();
		foreach ( $output->themes as $theme ) {
			$themes[ $theme['slug'] ] = $theme;
		}
		asort( $themes );

		$userExtension        = MainWP_DB_Common::instance()->get_user_extension();
		$decodedIgnoredThemes = json_decode( $userExtension->ignored_themes, true );
		$trustedThemes        = json_decode( $userExtension->trusted_themes, true );
		if ( ! is_array( $trustedThemes ) ) {
			$trustedThemes = array();
		}
		$trustedThemesNotes = json_decode( $userExtension->trusted_themes_notes, true );
		if ( ! is_array( $trustedThemesNotes ) ) {
			$trustedThemesNotes = array();
		}
		self::render_all_themes_html( $themes, $search_status, $trustedThemes, $trustedThemesNotes, $decodedIgnoredThemes );
	}

	/**
	 * Render all themes html.
	 *
	 * @param mixed $themes Themes list.
	 * @param mixed $search_status Search status.
	 * @param mixed $trustedThemes Trusted themes.
	 * @param mixed $trustedThemesNotes Trusted themes notes.
	 * @param mixed $decodedIgnoredThemes Decoded ignored themes.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Utility::esc_content()
	 */
	public static function render_all_themes_html( $themes, $search_status, $trustedThemes, $trustedThemesNotes, $decodedIgnoredThemes ) {

		/**
		 * Action: mainwp_themes_before_auto_updates_table
		 *
		 * Fires before the Auto Update Themes table.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_themes_before_auto_updates_table' );
		?>
		<table class="ui unstackable table" id="mainwp-all-active-themes-table">
			<thead>
				<tr>
					<th class="no-sort collapsing check-column"><span class="ui checkbox"><input id="cb-select-all-top" type="checkbox" /></span></th>
					<th data-priority="1"><?php esc_html_e( 'Theme', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
					<th  data-priority="2" class="collapsing"><?php esc_html_e( 'Trust Status', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Ignored Status', 'mainwp' ); ?></th>
					<th class="collapsing"></th>
					<th class="collapsing"><?php esc_html_e( 'Notes', 'mainwp' ); ?></th>
				</tr>
			</thead>

			<tbody>
			<?php foreach ( $themes as $slug => $theme ) : ?>
				<?php
				$name = esc_html( $theme['name'] );
				if ( ! empty( $search_status ) && 'all' !== $search_status ) {
					if ( 'trust' === $search_status && ! in_array( $slug, $trustedThemes ) ) {
						continue;
					} elseif ( 'untrust' === $search_status && in_array( $slug, $trustedThemes ) ) {
						continue;
					} elseif ( 'ignored' === $search_status && ! isset( $decodedIgnoredThemes[ $slug ] ) ) {
						continue;
					}
				}

				$esc_note   = '';
				$strip_note = '';
				if ( isset( $trustedThemesNotes[ $slug ] ) ) {
					$esc_note   = MainWP_Utility::esc_content( $trustedThemesNotes[ $slug ] );
					$strip_note = wp_strip_all_tags( $esc_note );
				}

				?>
				<tr theme-slug="<?php echo rawurlencode( $slug ); ?>" theme-name="<?php echo esc_attr( $name ); ?>">
					<td class="check-column"><span class="ui checkbox"><input type="checkbox" name="theme[]" value="<?php echo rawurlencode( $slug ); ?>"></span></td>
					<td><?php echo esc_html( $name ); ?></td>
					<td><?php echo ( 1 == $theme['active'] ) ? esc_html__( 'Active', 'mainwp' ) : esc_html__( 'Inactive', 'mainwp' ); ?></td>
					<td><?php echo ( in_array( $slug, $trustedThemes ) ) ? '<span class="ui mini green fluid center aligned label">' . esc_html__( 'Trusted', 'mainwp' ) . '</span>' : '<span class="ui mini red fluid center aligned label">' . esc_html__( 'Not Trusted', 'mainwp' ) . '</span>'; ?></td>
					<td><?php echo ( isset( $decodedIgnoredThemes[ $slug ] ) ) ? '<span class="ui mini label">' . esc_html__( 'Ignored', 'mainwp' ) . '</span>' : ''; ?></td>
					<td><?php echo ( isset( $decodedIgnoredThemes[ $slug ] ) ) ? '<span data-tooltip="Ignored themes will not be automatically updated." data-inverted=""><i class="info red circle icon"></i></span>' : ''; ?></td>
					<td class="collapsing center aligned">
					<?php if ( '' === $esc_note ) : ?>
						<a href="javascript:void(0)" class="mainwp-edit-theme-note"><i class="sticky note outline icon"></i></a>
					<?php else : ?>
						<a href="javascript:void(0)" class="mainwp-edit-theme-note" data-tooltip="<?php echo substr( $strip_note, 0, 100 ); ?>" data-position="left center" data-inverted=""><i class="sticky green note icon"></i></a>
					<?php endif; ?>
						<span style="display: none" class="esc-content-note"><?php echo $esc_note; ?></span>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>

			<tfoot>
				<tr>
					<th class="no-sort check-column"><span class="ui checkbox"><input id="cb-select-all-bottom" type="checkbox" /></span></th>
					<th></th>
					<th><?php esc_html_e( 'Theme', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Trust Status', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Ignored Status', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Notes', 'mainwp' ); ?></th>
				</tr>
			</tfoot>
		</table>
		<?php
		/**
		 * Action: mainwp_themes_after_auto_updates_table
		 *
		 * Fires before the Auto Update Themes table.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_themes_after_auto_updates_table' );

		$table_features = array(
			'searching'  => 'true',
			'stateSave'  => 'true',
			'colReorder' => 'true',
			'info'       => 'true',
			'paging'     => 'false',
			'ordering'   => 'true',
			'order'      => '[ [ 2, "asc" ] ]',
			'responsive' => 'true',
		);

		/**
		 * Filter: mainwp_theme_auto_updates_table_fatures
		 *
		 * Filters the Theme Auto Updates table features.
		 *
		 * @since 4.1
		 */
		$table_features = apply_filters( 'mainwp_theme_auto_updates_table_fatures', $table_features );
		?>
		<script type="text/javascript">
		var responsive = <?php echo $table_features['responsive']; ?>;
			if( jQuery( window ).width() > 1140 ) {
				responsive = false;
			}
			jQuery( document ).ready( function() {
				jQuery( '.mainwp-ui-page .ui.checkbox' ).checkbox();

				jQuery( '#mainwp-all-active-themes-table' ).DataTable( {
					"searching" : <?php echo $table_features['searching']; ?>,
					"stateSave" : <?php echo $table_features['stateSave']; ?>,
					"colReorder" : <?php echo $table_features['colReorder']; ?>,
					"info" : <?php echo $table_features['info']; ?>,
					"paging" : <?php echo $table_features['paging']; ?>,
					"ordering" : <?php echo $table_features['ordering']; ?>,
					"order" : <?php echo $table_features['order']; ?>,
					"columnDefs": [ { "orderable": false, "targets": [ 0, 1, 6 ] } ],
					"responsive": responsive,
				} );
			} );
		</script>
		<?php
	}

	/**
	 * Render the Themes Ignored Updates Tab.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::get_user_extension()
	 * @uses \MainWP\Dashboard\MainWP_DB::query()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_for_current_user()
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 */
	public static function render_ignore() {
		$websites             = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );
		$userExtension        = MainWP_DB_Common::instance()->get_user_extension();
		$decodedIgnoredThemes = json_decode( $userExtension->ignored_themes, true );
		$ignoredThemes        = ( is_array( $decodedIgnoredThemes ) && ( 0 < count( $decodedIgnoredThemes ) ) );

		$cnt = 0;

		while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
			if ( $website->is_ignoreThemeUpdates ) {
				continue;
			}

			$tmpDecodedIgnoredThemes = json_decode( $website->ignored_themes, true );

			if ( ! is_array( $tmpDecodedIgnoredThemes ) || 0 == count( $tmpDecodedIgnoredThemes ) ) {
				continue;
			}

			$cnt ++;
		}

		self::render_header( 'Ignore' );
		?>
		<div id="mainwp-ignored-plugins" class="ui segment">
			<?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-ignored-themes-info-message' ) ) : ?>
				<div class="ui info message">
					<i class="close icon mainwp-notice-dismiss" notice-id="mainwp-ignored-themes-info-message"></i>
					<?php echo sprintf( __( 'Manage themes you have told your MainWP Dashboard to ignore updates on global or per site level.  For additional help, please check this %1$shelp documentation%2$s.', 'mainwp' ), '<a href="https://kb.mainwp.com/docs/ignore-themes-updates/" target="_blank">', '</a>' ); ?>
				</div>
			<?php endif; ?>
			<?php
			/**
			 * Action: mainwp_themes_before_ignored_updates
			 *
			 * Fires on the top of the Ignored Themes Updates page.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_themes_before_ignored_updates', $ignoredThemes, $websites );
			?>
			<h3 class="ui header">
				<?php esc_html_e( 'Globally Ignored Themes', 'mainwp' ); ?>
				<div class="sub header"><?php esc_html_e( 'These are themes you have told your MainWP Dashboard to ignore updates on global level and not notify you about pending updates.', 'mainwp' ); ?></div>
			</h3>
			<?php self::render_global_ignored( $ignoredThemes, $decodedIgnoredThemes ); ?>
			<div class="ui hidden divider"></div>
			<h3 class="ui header">
			<?php esc_html_e( 'Per Site Ignored Themes', 'mainwp' ); ?>
			<div class="sub header"><?php esc_html_e( 'These are themes you have told your MainWP Dashboard to ignore updates per site level and not notify you about pending updates.', 'mainwp' ); ?></div>
		</h3>
		<?php self::render_sites_ignored( $cnt, $websites ); ?>
		<?php
		/**
		 * Action: mainwp_themes_after_ignored_updates
		 *
		 * Fires on the bottom of the Ignored Themes Updates page.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_themes_after_ignored_updates', $ignoredThemes, $websites );
		?>
		</div>
		<?php
		self::render_footer( 'Ignore' );
	}

	/**
	 * Render globally Ignored themes.
	 *
	 * @param mixed $ignoredThemes Encoded ignored themes.
	 * @param mixed $decodedIgnoredThemes Decoded ignored themes.
	 */
	public static function render_global_ignored( $ignoredThemes, $decodedIgnoredThemes ) {
		?>
		<table id="mainwp-globally-ignored-themes" class="ui compact selectable table unstackable">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Theme', 'mainwp' ); ?></th>
						<th><?php esc_html_e( 'Theme slug', 'mainwp' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody id="globally-ignored-themes-list">
					<?php if ( $ignoredThemes ) : ?>
						<?php foreach ( $decodedIgnoredThemes as $ignoredTheme => $ignoredThemeName ) : ?>
						<tr theme-slug="<?php echo rawurlencode( $ignoredTheme ); ?>">
							<td><?php echo esc_html( $ignoredThemeName ); ?></td>
							<td><?php echo esc_html( $ignoredTheme ); ?></td>
							<td class="right aligned">
							<?php if ( mainwp_current_user_have_right( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
								<a href="#" class="ui mini button" onClick="return updatesoverview_themes_unignore_globally( '<?php echo rawurlencode( $ignoredTheme ); ?>' )"><?php esc_html_e( 'Unigore', 'mainwp' ); ?></a>
							<?php endif; ?>
							</td>
						</tr>
						<?php endforeach; ?>
					<?php else : ?>
						<tr><td colspan="3"><?php esc_html_e( 'No ignored themes.', 'mainwp' ); ?></td></tr>
					<?php endif; ?>
				</tbody>
				<?php if ( mainwp_current_user_have_right( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
					<?php if ( $ignoredThemes ) : ?>
					<tfoot class="full-width">
						<tr>
							<th colspan="3">
								<a class="ui right floated small green labeled icon button" onClick="return updatesoverview_themes_unignore_globally_all();" id="mainwp-unignore-globally-all">
									<i class="check icon"></i> <?php esc_html_e( 'Unignore All', 'mainwp' ); ?>
								</a>
							</th>
						</tr>
					</tfoot>
				<?php endif; ?>
			<?php endif; ?>
		</table>
		<script type="text/javascript">
		jQuery( document ).ready( function() {
			jQuery( '#mainwp-globally-ignored-themes' ).DataTable( {
				searching: false,
				paging: false,
				info: false,
				responsive: true,
			} );
		} );
		</script>
		<?php
	}

	/**
	 * Method render_sites_ignored()
	 *
	 * Render ignored sites.
	 *
	 * @param int    $cnt Count of items.
	 * @param object $websites The websits object.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_DB::free_result()
	 */
	public static function render_sites_ignored( $cnt, $websites ) {
		?>
		<table id="mainwp-per-site-ignored-themes" class="ui compact selectable table unstackable">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Site', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Theme', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Theme slug', 'mainwp' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody id="ignored-themes-list">
			<?php if ( 0 < $cnt ) : ?>
				<?php
				MainWP_DB::data_seek( $websites, 0 );

				while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
					if ( $website->is_ignoreThemeUpdates ) {
						continue;
					}
					$decodedIgnoredThemes = json_decode( $website->ignored_themes, true );
					if ( ! is_array( $decodedIgnoredThemes ) || 0 == count( $decodedIgnoredThemes ) ) {
						continue;
					}
					$first = true;

					foreach ( $decodedIgnoredThemes as $ignoredTheme => $ignoredThemeName ) {
						?>
						<tr site-id="<?php echo esc_attr( $website->id ); ?>" theme-slug="<?php echo rawurlencode( $ignoredTheme ); ?>">
							<?php if ( $first ) : ?>
								<td><div><a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"><?php echo stripslashes( $website->name ); ?></a></div></td>
								<?php $first = false; ?>
							<?php else : ?>
								<td><div style="display:none;"><a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"><?php echo stripslashes( $website->name ); ?></a></div></td>
							<?php endif; ?>
							<td><?php echo esc_html( $ignoredThemeName ); ?></td>
							<td><?php echo esc_html( $ignoredTheme ); ?></td>
							<td class="right aligned">
							<?php if ( mainwp_current_user_have_right( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
								<a href="#" class="ui mini button" onClick="return updatesoverview_themes_unignore_detail( '<?php echo rawurlencode( $ignoredTheme ); ?>', <?php echo esc_attr( $website->id ); ?> )"><?php esc_html_e( 'Unigore', 'mainwp' ); ?></a>
							<?php endif; ?>
							</td>
						</tr>
						<?php
					}
				}
				MainWP_DB::free_result( $websites );
				?>
				<?php else : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No ignored themes', 'mainwp' ); ?></td></tr>
				<?php endif; ?>
			</tbody>
			<?php if ( mainwp_current_user_have_right( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
				<?php if ( 0 < $cnt ) : ?>
				<tfoot class="full-width">
				<tr>
					<th colspan="4">
						<a class="ui right floated small green labeled icon button" onClick="return updatesoverview_themes_unignore_detail_all();" id="mainwp-unignore-detail-all">
							<i class="check icon"></i> <?php esc_html_e( 'Unignore All', 'mainwp' ); ?>
						</a>
					</th>
				</tr>
				</tfoot>
				<?php endif; ?>
			<?php endif; ?>
		</table>
		<script type="text/javascript">
		jQuery( document ).ready( function() {
			jQuery( '#mainwp-per-site-ignored-themes' ).DataTable( {
				searching: false,
				paging: false,
				info: false,
				responsive: true,
			} );
		} );
		</script>
		<?php
	}

	/**
	 * Render the Themes Ignored/Abandoned Tab.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::get_user_extension()
	 * @uses \MainWP\Dashboard\MainWP_DB::query()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_for_current_user()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 */
	public static function render_ignored_abandoned() {
		$websites             = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );
		$userExtension        = MainWP_DB_Common::instance()->get_user_extension();
		$decodedIgnoredThemes = json_decode( $userExtension->dismissed_themes, true );
		$ignoredThemes        = ( is_array( $decodedIgnoredThemes ) && ( 0 < count( $decodedIgnoredThemes ) ) );
		$cnt                  = 0;
		while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
			$tmpDecodedIgnoredThemes = json_decode( MainWP_DB::instance()->get_website_option( $website, 'themes_outdate_dismissed' ), true );
			if ( ! is_array( $tmpDecodedIgnoredThemes ) || 0 == count( $tmpDecodedIgnoredThemes ) ) {
				continue;
			}
			$cnt ++;
		}

		self::render_header( 'IgnoreAbandoned' );
		?>
		<div id="mainwp-ignored-abandoned-themes" class="ui segment">
			<?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-ignored-abandoned-themes-info-message' ) ) : ?>
				<div class="ui info message">
					<i class="close icon mainwp-notice-dismiss" notice-id="mainwp-ignored-abandoned-themes-info-message"></i>
					<?php echo sprintf( __( 'Manage themes you have told your MainWP Dashboard to ignore updates on global or per site level.  For additional help, please check this %1$shelp documentation%2$s.', 'mainwp' ), '<a href="https://kb.mainwp.com/docs/abandoned-themes/" target="_blank">', '</a>' ); ?>
				</div>
			<?php endif; ?>
			<?php
			/**
			 * Action: mainwp_themes_before_ignored_abandoned
			 *
			 * Fires on the top of the Ignored Themes Abandoned page.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_themes_before_ignored_abandoned', $ignoredThemes, $websites );
			?>
			<h3 class="ui header">
				<?php esc_html_e( 'Globally Ignored Abandoned Themes', 'mainwp' ); ?>
				<div class="sub header"><?php esc_html_e( 'These are themes you have told your MainWP Dashboard to ignore on global level even though they have passed your Abandoned Themes Tolerance date', 'mainwp' ); ?></div>
			</h3>
			<?php self::render_global_ignored_abandoned( $ignoredThemes, $decodedIgnoredThemes ); ?>
		<div class="ui hidden divider"></div>
		<h3 class="ui header">
			<?php esc_html_e( 'Per Site Ignored Abandoned Themes', 'mainwp' ); ?>
			<div class="sub header"><?php esc_html_e( 'These are themes you have told your MainWP Dashboard to ignore per site level even though they have passed your Abandoned Theme Tolerance date', 'mainwp' ); ?></div>
		</h3>
			<?php self::render_sites_ignored_abandoned( $cnt, $websites ); ?>
			<?php
			/**
			 * Action: mainwp_themes_after_ignored_abandoned
			 *
			 * Fires on the bottom of the Ignored Themes Abandoned page.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_themes_after_ignored_abandoned', $ignoredThemes, $websites );
			?>
		</div>
		<?php
		self::render_footer( 'IgnoreAbandoned' );
	}

	/**
	 * Render the global ignored themes list.
	 *
	 * @param mixed $ignoredThemes Encoded ignored themes list.
	 * @param mixed $decodedIgnoredThemes Decoded ignored themes list.
	 */
	public static function render_global_ignored_abandoned( $ignoredThemes, $decodedIgnoredThemes ) {
		?>
		<table id="mainwp-globally-ignored-abandoned-themes" class="ui compact selectable table unstackable">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Theme', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Theme slug', 'mainwp' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody id="globally-ignored-themes-list">
				<?php if ( $ignoredThemes ) : ?>
					<?php foreach ( $decodedIgnoredThemes as $ignoredTheme => $ignoredThemeName ) : ?>
					<tr theme-slug="<?php echo rawurlencode( $ignoredTheme ); ?>">
						<td><?php echo esc_html( $ignoredThemeName ); ?></td>
						<td><?php echo esc_html( $ignoredTheme ); ?></td>
						<td class="right aligned">
						<?php if ( mainwp_current_user_have_right( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
							<a href="#" class="ui mini button" onClick="return updatesoverview_themes_abandoned_unignore_globally( '<?php echo rawurlencode( $ignoredTheme ); ?>' )"><?php esc_html_e( 'Unignore', 'mainwp' ); ?></a>
						<?php endif; ?>
						</td>
					</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr><td colspan="3"><?php esc_html_e( 'No ignored abandoned themes.', 'mainwp' ); ?></td></tr>
				<?php endif; ?>
				</tbody>
				<?php if ( mainwp_current_user_have_right( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
					<?php if ( $ignoredThemes ) : ?>
					<tfoot class="full-width">
						<tr>
							<th colspan="3">
								<a class="ui right floated small green labeled icon button" onClick="return updatesoverview_themes_abandoned_unignore_globally_all();" id="mainwp-unignore-globally-all">
									<i class="check icon"></i> <?php esc_html_e( 'Unignore All', 'mainwp' ); ?>
								</a>
							</th>
						</tr>
					</tfoot>
					<?php endif; ?>
				<?php endif; ?>
		</table>
		<script type="text/javascript">
		jQuery( document ).ready( function() {
			jQuery( '#mainwp-globally-ignored-abandoned-themes' ).DataTable( {
				"responsive": true,
				"searching": false,
				"paging": false,
				"info": false,
			} );
		} );
		</script>
		<?php
	}

	/**
	 * Method render_sites_ignored_abandoned()
	 *
	 * Render ignored items per site list.
	 *
	 * @param int    $cnt Count of items.
	 * @param object $websites The websits object.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_DB::free_result()
	 */
	public static function render_sites_ignored_abandoned( $cnt, $websites ) {
		?>
	<table id="mainwp-per-site-ignored-abandoned-themes" class="ui compact selectable table unstackable">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Site', 'mainwp' ); ?></th>
				<th><?php esc_html_e( 'Theme', 'mainwp' ); ?></th>
				<th><?php esc_html_e( 'Theme slug', 'mainwp' ); ?></th>
				<th></th>
			</tr>
		</thead>
		<tbody id="ignored-abandoned-themes-list">
			<?php if ( 0 < $cnt ) : ?>
				<?php
				MainWP_DB::data_seek( $websites, 0 );
				while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
					$decodedIgnoredThemes = json_decode( MainWP_DB::instance()->get_website_option( $website, 'themes_outdate_dismissed' ), true );
					if ( ! is_array( $decodedIgnoredThemes ) || 0 == count( $decodedIgnoredThemes ) ) {
						continue;
					}

					$first = true;
					foreach ( $decodedIgnoredThemes as $ignoredTheme => $ignoredThemeName ) {
						?>
					<tr site-id="<?php echo esc_attr( $website->id ); ?>" theme-slug="<?php echo rawurlencode( $ignoredTheme ); ?>">
						<?php if ( $first ) : ?>
						<td><a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"><?php echo stripslashes( $website->name ); ?></a></td>
							<?php $first = false; ?>
						<?php else : ?>
						<td><div style="display:none;"><a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"><?php echo stripslashes( $website->name ); ?></a></div></td>
						<?php endif; ?>
						<td><?php echo esc_html( $ignoredThemeName ); ?></td>
						<td><?php echo esc_html( $ignoredTheme ); ?></td>
						<td class="right aligned">
						<?php if ( mainwp_current_user_have_right( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
							<a href="#" class="ui mini button" onClick="return updatesoverview_themes_unignore_abandoned_detail( '<?php echo rawurlencode( $ignoredTheme ); ?>', <?php echo esc_attr( $website->id ); ?> )"><?php esc_html_e( 'Unignore', 'mainwp' ); ?></a>
						<?php endif; ?>
						</td>
					</tr>
						<?php
					}
				}
				MainWP_DB::free_result( $websites );
				?>
			<?php else : ?>
			<tr><td colspan="4"><?php esc_html_e( 'No ignored abandoned themes.', 'mainwp' ); ?></td></tr>
			<?php endif; ?>
			</tbody>
			<?php if ( mainwp_current_user_have_right( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
				<?php if ( 0 < $cnt ) : ?>
				<tfoot class="full-width">
					<tr>
						<th colspan="4">
							<a class="ui right floated small green labeled icon button" onClick="return updatesoverview_themes_unignore_abandoned_detail_all();" id="mainwp-unignore-detail-all">
								<i class="check icon"></i> <?php esc_html_e( 'Unignore All', 'mainwp' ); ?>
							</a>
						</th>
					</tr>
				</tfoot>
				<?php endif; ?>
			<?php endif; ?>
		</table>
		<script type="text/javascript">
		jQuery( document ).ready( function() {
			jQuery( '#mainwp-per-site-ignored-abandoned-themes' ).DataTable( {
				"responsive": true,
				"searching": false,
				"paging": false,
				"info": false,
			} );
		} );
		</script>
		<?php
	}


	/**
	 * Hooks the section help content to the Help Sidebar element.
	 */
	public static function mainwp_help_content() {
		if ( isset( $_GET['page'] ) && ( 'ThemesManage' === $_GET['page'] || 'ThemesInstall' === $_GET['page'] || 'ThemesAutoUpdate' === $_GET['page'] || 'ThemesIgnore' === $_GET['page'] || 'ThemesIgnoredAbandoned' === $_GET['page'] ) ) {
			?>
			<p><?php esc_html_e( 'If you need help with managing themes, please review following help documents', 'mainwp' ); ?></p>
			<div class="ui relaxed bulleted list">
				<div class="item"><a href="https://kb.mainwp.com/docs/managing-themes-with-mainwp/" target="_blank">Managing Themes with MainWP</a></div>
				<div class="item"><a href="https://kb.mainwp.com/docs/install-themes/" target="_blank">Install Themes</a></div>
				<div class="item"><a href="https://kb.mainwp.com/docs/activate-themes/" target="_blank">Activate Themes</a></div>
				<div class="item"><a href="https://kb.mainwp.com/docs/delete-themes/" target="_blank">Delete Themes</a></div>
				<div class="item"><a href="https://kb.mainwp.com/docs/abandoned-themes/" target="_blank">Abandoned Themes</a></div>
				<div class="item"><a href="https://kb.mainwp.com/docs/update-themes/" target="_blank">Update Themes</a></div>
				<div class="item"><a href="https://kb.mainwp.com/docs/themes-auto-updates/" target="_blank">Themes Auto Updates</a></div>
				<div class="item"><a href="https://kb.mainwp.com/docs/ignore-theme-updates/" target="_blank">Ignore Theme Updates</a></div>
				<?php
				/**
				 * Action: mainwp_themes_help_item
				 *
				 * Fires at the bottom of the help articles list in the Help sidebar on the Themes page.
				 *
				 * Suggested HTML markup:
				 *
				 * <div class="item"><a href="Your custom URL">Your custom text</a></div>
				 *
				 * @since 4.1
				 */
				do_action( 'mainwp_themes_help_item' );
				?>
			</div>
			<?php
		}
	}

}
