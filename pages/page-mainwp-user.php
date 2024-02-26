<?php
/**
 * MainWP Users Page
 *
 * This page is used to Manage Users on child sites.
 *
 * @package MainWP/User
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_User
 *
 * @uses page-mainwp-bulk-add::MainWP_Bulk_Add()
 */
class MainWP_User {

	/**
	 * Get Class Name
	 *
	 * @return string __CLASS__
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Public static varable to hold Subpages information.
	 *
	 * @var array $subPages
	 */
	public static $subPages;

	/**
	 * Method init()
	 *
	 * Initiate hooks for the users page.
	 */
	public static function init() {
		/**
		 * This hook allows you to render the User page header via the 'mainwp-pageheader-user' action.
		 *
		 * @link http://codex.mainwp.com/#mainwp-pageheader-user
		 *
		 * This hook is normally used in the same context of 'mainwp-getsubpages-user'
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-user
		 *
		 * @see \MainWP_User::render_header
		 */
		add_action( 'mainwp-pageheader-user', array( self::get_class_name(), 'render_header' ) );

		/**
		 * This hook allows you to render the User page footer via the 'mainwp-pagefooter-user' action.
		 *
		 * @link http://codex.mainwp.com/#mainwp-pagefooter-user
		 *
		 * This hook is normally used in the same context of 'mainwp-getsubpages-user'
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-user
		 *
		 * @see \MainWP_User::render_footer
		 */
		add_action( 'mainwp-pagefooter-user', array( self::get_class_name(), 'render_footer' ) );

		add_action( 'mainwp_help_sidebar_content', array( self::get_class_name(), 'mainwp_help_content' ) );
	}

	/**
	 * Method init_menu()
	 *
	 * Initiate menu.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
	 */
	public static function init_menu() {
		$_page = add_submenu_page(
			'mainwp_tab',
			__( 'Users', 'mainwp' ),
			'<span id="mainwp-Users">' . esc_html__( 'Users', 'mainwp' ) . '</span>',
			'read',
			'UserBulkManage',
			array(
				self::get_class_name(),
				'render',
			)
		);

		add_action( 'load-' . $_page, array( self::get_class_name(), 'on_load_page' ) );

		add_submenu_page(
			'mainwp_tab',
			__( 'Users', 'mainwp' ),
			'<div class="mainwp-hidden">' . esc_html__( 'Add New', 'mainwp' ) . '</div>',
			'read',
			'UserBulkAdd',
			array(
				self::get_class_name(),
				'render_bulk_add',
			)
		);

		add_submenu_page(
			'mainwp_tab',
			__( 'Import Users', 'mainwp' ),
			'<div class="mainwp-hidden">' . esc_html__( 'Import Users', 'mainwp' ) . '</div>',
			'read',
			'BulkImportUsers',
			array(
				self::get_class_name(),
				'render_bulk_import_users',
			)
		);

		/**
		 * This hook allows you to add extra sub pages to the User page via the 'mainwp-getsubpages-user' filter.
		 *
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-user
		 */
		$sub_pages      = apply_filters_deprecated( 'mainwp-getsubpages-user', array( array() ), '4.0.7.2', 'mainwp_getsubpages_user' );  // @deprecated Use 'mainwp_getsubpages_user' instead.
		self::$subPages = apply_filters( 'mainwp_getsubpages_user', $sub_pages );

		if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
			foreach ( self::$subPages as $subPage ) {
				if ( MainWP_Menu::is_disable_menu_item( 3, 'UserBulk' . $subPage['slug'] ) ) {
					continue;
				}
				add_submenu_page( 'mainwp_tab', $subPage['title'], '<div class="mainwp-hidden">' . esc_html( $subPage['title'] ) . '</div>', 'read', 'UserBulk' . $subPage['slug'], $subPage['callback'] );
			}
		}

		self::init_left_menu( self::$subPages );
	}

	/**
	 * Initiates sub pages menu.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
	 */
	public static function init_subpages_menu() {
		?>
		<div id="menu-mainwp-Users" class="mainwp-submenu-wrapper">
			<div class="wp-submenu sub-open" style="">
				<div class="mainwp_boxout">
					<div class="mainwp_boxoutin"></div>
					<?php if ( mainwp_current_user_have_right( 'dashboard', 'manage_users' ) ) { ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=UserBulkManage' ) ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Manage Users', 'mainwp' ); ?></a>
					<?php } ?>
					<?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'UserBulkAdd' ) ) { ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=UserBulkAdd' ) ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Add New', 'mainwp' ); ?></a>
					<?php } ?>
					<?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'BulkImportUsers' ) ) { ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=BulkImportUsers' ) ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Import Users', 'mainwp' ); ?></a>
					<?php } ?>
					<?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'UpdateAdminPasswords' ) ) { ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=UpdateAdminPasswords' ) ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Admin Passwords', 'mainwp' ); ?></a>
					<?php } ?>
					<?php
					if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
						foreach ( self::$subPages as $subPage ) {
							if ( MainWP_Menu::is_disable_menu_item( 3, 'UserBulk' . $subPage['slug'] ) ) {
								continue;
							}
							?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=UserBulk' . $subPage['slug'] ) ); ?>" class="mainwp-submenu"><?php echo esc_html( $subPage['title'] ); ?></a>
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
	 * Method get_manage_columns()
	 *
	 * Get columns to display.
	 *
	 * @return array $colums Array of columns to display on the page.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Utility::enabled_wp_seo()
	 */
	public static function get_manage_columns() {
		$colums = array(
			'name'     => esc_html__( 'Name', 'mainwp' ),
			'username' => esc_html__( 'Username', 'mainwp' ),
			'email'    => esc_html__( 'E-mail', 'mainwp' ),
			'role'     => esc_html__( 'Role', 'mainwp' ),
			'posts'    => esc_html__( 'Posts', 'mainwp' ),
			'website'  => esc_html__( 'Website', 'mainwp' ),
		);
		return $colums;
	}

	/**
	 * Method on_load_page()
	 *
	 * Used during init_menu() to get the class names of,
	 * admin_head and get_hidden_columns.
	 *
	 * @return void
	 */
	public static function on_load_page() {
		add_action( 'mainwp_screen_options_modal_bottom', array( self::get_class_name(), 'hook_screen_options_modal_bottom' ), 10, 2 );
	}

	/**
	 * Method hook_screen_options_modal_bottom()
	 *
	 * Render screen options modal bottom.
	 */
	public static function hook_screen_options_modal_bottom() {
		$page = isset( $_GET['page'] ) ? wp_unslash( $_GET['page'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( 'UserBulkManage' === $page ) {

			$show_columns = get_user_option( 'mainwp_manageusers_show_columns' );

			if ( ! is_array( $show_columns ) ) {
				$show_columns = array();
			}

			$cols = self::get_manage_columns();
			MainWP_UI::render_showhide_columns_settings( $cols, $show_columns, 'user' );
		}
	}

	/**
	 * Initiates Users menu.
	 *
	 * @param array $subPages Sub pages array.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Menu::add_left_menu()
	 * @uses \MainWP\Dashboard\MainWP_Menu::init_subpages_left_menu()
	 * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
	 */
	public static function init_left_menu( $subPages = array() ) {
		MainWP_Menu::add_left_menu(
			array(
				'title'         => esc_html__( 'Users', 'mainwp' ),
				'parent_key'    => 'managesites',
				'slug'          => 'UserBulkManage',
				'href'          => 'admin.php?page=UserBulkManage',
				'icon'          => '<i class="user icon"></i>',
				'desc'          => 'Manage users on your child sites',
				'leftsub_order' => 7,
			),
			1
		);

		$init_sub_subleftmenu = array(
			array(
				'title'      => esc_html__( 'Manage Users', 'mainwp' ),
				'parent_key' => 'UserBulkManage',
				'href'       => 'admin.php?page=UserBulkManage',
				'slug'       => 'UserBulkManage',
				'right'      => 'manage_users',
			),
			array(
				'title'      => esc_html__( 'Add New', 'mainwp' ),
				'parent_key' => 'UserBulkManage',
				'href'       => 'admin.php?page=UserBulkAdd',
				'slug'       => 'UserBulkAdd',
				'right'      => '',
			),
			array(
				'title'      => esc_html__( 'Import Users', 'mainwp' ),
				'parent_key' => 'UserBulkManage',
				'href'       => 'admin.php?page=BulkImportUsers',
				'slug'       => 'BulkImportUsers',
				'right'      => '',
			),
			array(
				'title'      => esc_html__( 'Admin Passwords', 'mainwp' ),
				'parent_key' => 'UserBulkManage',
				'href'       => 'admin.php?page=UpdateAdminPasswords',
				'slug'       => 'UpdateAdminPasswords',
				'right'      => '',
			),
		);

		MainWP_Menu::init_subpages_left_menu( $subPages, $init_sub_subleftmenu, 'UserBulkManage', 'UserBulk' );

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
	 * Render Users page header.
	 *
	 * @param string $shownPage The page slug shown at this moment.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_top_header()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_page_navigation()
	 */
	public static function render_header( $shownPage = '' ) {
		$params = array(
			'title' => esc_html__( 'Users', 'mainwp' ),
		);
		MainWP_UI::render_top_header( $params );

		$renderItems = array();

		if ( mainwp_current_user_have_right( 'dashboard', 'manage_users' ) ) {
			$renderItems[] = array(
				'title'  => esc_html__( 'Manage Users', 'mainwp' ),
				'href'   => 'admin.php?page=UserBulkManage',
				'active' => ( '' === $shownPage ) ? true : false,
			);
		}

		if ( ! MainWP_Menu::is_disable_menu_item( 3, 'UserBulkAdd' ) ) {
			$renderItems[] = array(
				'title'  => esc_html__( 'Add New', 'mainwp' ),
				'href'   => 'admin.php?page=UserBulkAdd',
				'active' => ( 'Add' === $shownPage ) ? true : false,
			);
		}

		if ( ! MainWP_Menu::is_disable_menu_item( 3, 'BulkImportUsers' ) ) {
			$renderItems[] = array(
				'title'  => esc_html__( 'Import Users', 'mainwp' ),
				'href'   => 'admin.php?page=BulkImportUsers',
				'active' => ( 'Import' === $shownPage ) ? true : false,
			);
		}

		if ( ! MainWP_Menu::is_disable_menu_item( 3, 'UpdateAdminPasswords' ) ) {
			$renderItems[] = array(
				'title'  => esc_html__( 'Admin Passwords', 'mainwp' ),
				'href'   => 'admin.php?page=UpdateAdminPasswords',
				'active' => ( 'UpdateAdminPasswords' === $shownPage ) ? true : false,
			);
		}

		if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
			foreach ( self::$subPages as $subPage ) {
				if ( MainWP_Menu::is_disable_menu_item( 3, 'UserBulk' . $subPage['slug'] ) ) {
					continue;
				}

				$item           = array();
				$item['title']  = $subPage['title'];
				$item['href']   = 'admin.php?page=UserBulk' . $subPage['slug'];
				$item['active'] = ( $subPage['slug'] === $shownPage ) ? true : false;
				$renderItems[]  = $item;
			}
		}

		MainWP_UI::render_page_navigation( $renderItems );
	}

	/**
	 * Method render_footer()
	 *
	 * Render Users page footer. Closes the page container.
	 */
	public static function render_footer() {
		echo '</div>';
	}

	/**
	 * Renders manage users dashboard.
	 *
	 * @return void
	 *
	 * @uses \MainWP\Dashboard\MainWP_Cache::get_cached_context()
	 */
	public static function render() {
		if ( ! mainwp_current_user_have_right( 'dashboard', 'manage_users' ) ) {
			mainwp_do_not_have_permissions( esc_html__( 'manage users', 'mainwp' ) );

			return;
		}

		$cachedSearch = MainWP_Cache::get_cached_context( 'Users' );

		$selected_sites   = array();
		$selected_groups  = array();
		$selected_clients = array();

		if ( null !== $cachedSearch ) {
			if ( is_array( $cachedSearch['sites'] ) ) {
				$selected_sites = $cachedSearch['sites'];
			} elseif ( is_array( $cachedSearch['groups'] ) ) {
				$selected_groups = $cachedSearch['groups'];
			} elseif ( is_array( $cachedSearch['clients'] ) ) {
				$selected_clients = $cachedSearch['clients'];
			}
		}

		self::render_header( '' );
		?>
		<div id="mainwp-manage-users" class="ui alt segment">
			<div class="mainwp-main-content">
				<div class="mainwp-actions-bar ui mini form">
					<div class="ui grid">
						<div class="ui two column row">
							<div class="column">
								<select class="ui mini dropdown" id="mainwp-bulk-actions">
									<option value=""><?php esc_html_e( 'Bulk Actions', 'mainwp' ); ?></option>
									<option value="edit"><?php esc_html_e( 'Edit', 'mainwp' ); ?></option>
									<option value="delete"><?php esc_html_e( 'Delete', 'mainwp' ); ?></option>
									<?php
									/**
									 * Action: mainwp_users_bulk_action
									 *
									 * Adds new Bulk Actions option under on Manage Users.
									 *
									 * Suggested HTML Markup:
									 * <option value="Your custom value">Your custom label</option>
									 *
									 * @since 4.1
									 */
									do_action( 'mainwp_users_bulk_action' );
									?>
								</select>
								<button class="ui mini button" id="mainwp-do-users-bulk-actions"><?php esc_html_e( 'Apply', 'mainwp' ); ?></button>
								<?php
								/**
								 * Users actions bar (left)
								 *
								 * Fires at the left side of the actions bar on the Users screen, after the Bulk Actions menu.
								 *
								 * @since 4.0
								 */
								do_action( 'mainwp_users_actions_bar_left' );
								?>
							</div>
							<div class="right aligned column">
								<?php
								/**
								 * Users actions bar (right)
								 *
								 * Fires at the right side of the actions bar on the Users screen.
								 *
								 * @since 4.0
								 */
								do_action( 'mainwp_users_actions_bar_right' );
								?>
							</div>
						</div>
					</div>
				</div>
				<div id="mainwp-loading-users-row" style="display: none;">
					<div class="ui active inverted dimmer">
						<div class="ui indeterminate large text loader"><?php esc_html_e( 'Loading Users...', 'mainwp' ); ?>
							<span id="mainwp_users_loading_info" class="mainwp-grabbing-info-note"><br /><?php esc_html_e( 'Automatically refreshing to get up to date information.', 'mainwp' ); ?></span>
						</div>
					</div>
				</div>
				<div class="ui segment" id="mainwp_users_wrap_table">
					<?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-manage-users-info-message' ) ) : ?>
						<div class="ui info message">
							<i class="close icon mainwp-notice-dismiss" notice-id="mainwp-manage-users-info-message"></i>
							<?php printf( esc_html__( 'Manage existing users on your child sites.  Here you can Delete, Edit or Change Role for existing users.  For additional help, please check this %1$shelp documentation%2$s.', 'mainwp' ), '<a href="https://kb.mainwp.com/docs/manage-users/" target="_blank">', '</a>' ); ?>
						</div>
					<?php endif; ?>
					<div class="ui message" id="mainwp-message-zone" style="display:none"></div>
					<?php self::render_table( true ); ?>
				</div>
				<div id="mainwp-update-users-box" class="ui segment">
					<?php self::render_update_users(); ?>
				</div>
			</div>
			<div class="mainwp-side-content mainwp-no-padding">
				<?php
				/**
				 * Action: mainwp_manage_users_sidebar_top
				 *
				 * Fires on top of the sidebar on Manage Users page.
				 *
				 * @since 4.1
				 */
				do_action( 'mainwp_manage_users_sidebar_top' );
				?>
				<div class="mainwp-select-sites ui accordion mainwp-sidebar-accordion">
					<?php
					/**
					 * Action: mainwp_manage_users_before_select_sites
					 *
					 * Fires before the Select Sites section on Manage Users page.
					 *
					 * @since 4.1
					 */
					do_action( 'mainwp_manage_users_before_select_sites' );
					?>
					<div class="title active"><i class="dropdown icon"></i> <?php esc_html_e( 'Select Sites', 'mainwp' ); ?></div>
					<div class="content active">
						<?php
						$sel_params = array(
							'selected_sites'   => $selected_sites,
							'selected_groups'  => $selected_groups,
							'selected_clients' => $selected_clients,
							'class'            => 'mainwp_select_sites_box_left',
							'show_client'      => true,
						);
						MainWP_UI_Select_Sites::select_sites_box( $sel_params );
						?>
						</div>
					<?php
					/**
					 * Action: mainwp_manage_users_after_select_sites
					 *
					 * Fires after the Select Sites section on Manage Users page.
					 *
					 * @since 4.1
					 */
					do_action( 'mainwp_manage_users_after_select_sites' );
					?>
				</div>

				<?php
				$user_roles = array(
					'subscriber'    => esc_html__( 'Subscriber', 'mainwp' ),
					'administrator' => esc_html__( 'Administrator', 'mainwp' ),
					'editor'        => esc_html__( 'Editor', 'mainwp' ),
					'author'        => esc_html__( 'Author', 'mainwp' ),
					'contributor'   => esc_html__( 'Contributor', 'mainwp' ),
				);

				$user_roles = apply_filters_deprecated( 'mainwp-users-manage-roles', array( $user_roles ), '4.0.7.2', 'mainwp_users_manage_roles' );  // @deprecated Use 'mainwp_users_manage_roles' instead.
				$user_roles = apply_filters( 'mainwp_users_manage_roles', $user_roles );

				?>

				<div class="ui fitted divider"></div>
				<div class="mainwp-search-options ui accordion mainwp-sidebar-accordion">
					<div class="title active"><i class="dropdown icon"></i> <?php esc_html_e( 'Select Roles', 'mainwp' ); ?></div>
					<div class="content active">
					<?php
					/**
					 * Action: mainwp_manage_users_before_search_options
					 *
					 * Fires before the Search Options section on Manage Users page.
					 *
					 * @since 4.1
					 */
					do_action( 'mainwp_manage_users_before_search_options' );
					?>
					<div class="ui mini form">
						<div class="field" data-tooltip="<?php esc_attr_e( 'Select specific roles that you want to search for.', 'mainwp' ); ?>" data-inverted="" data-position="top right">
							<select multiple="" class="ui fluid mini dropdown" id="mainwp_user_roles">
								<option value=""><?php esc_html_e( 'Select wanted role(s)', 'mainwp' ); ?></option>
								<?php
								foreach ( $user_roles as $r => $n ) {
									if ( empty( $r ) ) {
										continue;
									}
									?>
									<option value="<?php echo esc_html( $r ); ?>"><?php echo esc_html( $n ); ?></option>
									<?php
								}
								?>
							</select>
						</div>
					</div>
					<?php
					/**
					 * Action: mainwp_manage_users_after_search_options
					 *
					 * Fires after the Search Options section on Manage Users page.
					 *
					 * @since 4.1
					 */
					do_action( 'mainwp_manage_users_after_search_options' );
					?>
				</div>
				</div>
				<div class="ui fitted divider"></div>
				<div class="mainwp-search-options ui accordion mainwp-sidebar-accordion">
					<div class="title active"><i class="dropdown icon"></i> <?php esc_html_e( 'Search Options', 'mainwp' ); ?></div>
					<div class="content active"><?php self::render_search_options(); ?></div>
				</div>
				<div class="ui fitted divider"></div>
				<div class="mainwp-search-submit">
					<?php
					/**
					 * Action: mainwp_manage_users_before_submit_button
					 *
					 * Fires before the Submit Button on Manage Users page.
					 *
					 * @since 4.1
					 */
					do_action( 'mainwp_manage_users_before_submit_button' );
					?>
					<input type="button" name="mainwp_show_users" id="mainwp_show_users" class="ui green big fluid button" value="<?php esc_attr_e( 'Show Users', 'mainwp' ); ?>"/>
					<?php
					/**
					 * Action: mainwp_manage_users_after_submit_button
					 *
					 * Fires after the Submit Button on Manage Users page.
					 *
					 * @since 4.1
					 */
					do_action( 'mainwp_manage_users_after_submit_button' );
					?>
				</div>
				<?php
				/**
				 * Action: mainwp_manage_users_sidebar_bottom
				 *
				 * Fires at the bottom of the sidebar on Manage Users page.
				 *
				 * @since 4.1
				 */
				do_action( 'mainwp_manage_users_sidebar_bottom' );
				?>
			</div>
			<div class="ui hidden clearing divider"></div>
		</div>
		<?php
		self::render_footer( '' );
	}

	/**
	 * Method render_search_options()
	 *
	 * Render User page search.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Cache::get_cached_context()
	 */
	public static function render_search_options() {
		$cachedSearch = MainWP_Cache::get_cached_context( 'Users' );
		$statuses     = isset( $cachedSearch['status'] ) ? $cachedSearch['status'] : array();
		if ( $cachedSearch && isset( $cachedSearch['keyword'] ) ) {
			$cachedSearch['keyword'] = trim( $cachedSearch['keyword'] );
		}
		?>

		<div class="ui mini form">
			<div class="field" data-tooltip="<?php esc_attr_e( 'Enter specific username that you want to search for.', 'mainwp' ); ?>" data-inverted="" data-position="top right">
				<div class="ui input fluid">
					<input type="text" placeholder="<?php esc_attr_e( 'Username', 'mainwp' ); ?>" id="mainwp_search_users" class="text" value="<?php echo ( null !== $cachedSearch ) ? esc_attr( $cachedSearch['keyword'] ) : ''; ?>" />
				</div>
			</div>
		</div>
		<?php
		if ( is_array( $statuses ) && 0 < count( $statuses ) ) {
			$status = '';
			foreach ( $statuses as $st ) {
				$status .= "'" . esc_html( $st ) . "',";
			}
			$status = rtrim( $status, ',' );
			?>
			<script type="text/javascript">
			jQuery( document ).ready( function () {
				jQuery( '#mainwp_user_roles' ).dropdown( 'set selected', [<?php echo $status; //phpcs:ignore -- safe output. ?>] );
			} );
			</script>
			<?php
		}
	}

	/**
	 * Renders Edit Users Modal window.
	 */
	public static function render_update_users() {

		$editable_roles = array(
			'donotupdate'   => esc_html__( 'Do not update', 'mainwp' ),
			'administrator' => esc_html__( 'Administrator', 'mainwp' ),
			'subscriber'    => esc_html__( 'Subscriber', 'mainwp' ),
			'contributor'   => esc_html__( 'Contributor', 'mainwp' ),
			'author'        => esc_html__( 'Author', 'mainwp' ),
			'editor'        => esc_html__( 'Editor', 'mainwp' ),
		);

		$editable_roles = apply_filters_deprecated( 'mainwp-users-manage-roles', array( $editable_roles ), '4.0.7.2', 'mainwp_users_manage_roles' );  // @deprecated Use 'mainwp_users_manage_roles' instead.
		$editable_roles = apply_filters( 'mainwp_users_manage_roles', $editable_roles );

		$editable_roles[''] = esc_html__( '&mdash; No role for this site &mdash;', 'mainwp' );

		?>
		<div id="mainwp-edit-users-modal" class="ui modal">
			<i class="close icon"></i>
			<div class="header"><?php esc_html_e( 'Edit User', 'mainwp' ); ?></div>
			<div class="ui message"><?php esc_html_e( 'Empty fields will not be passed to child sites.', 'mainwp' ); ?></div>
			<form id="update_user_profile">
				<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
				<div class="ui segment">
					<div class="ui form">
						<h3><?php esc_html_e( 'Name', 'mainwp' ); ?></h3>
						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'Role', 'mainwp' ); ?></label>
							<div class="ui six wide column">
								<div class="ui left labeled input">
									<select name="role" id="role">
									<?php
									foreach ( $editable_roles as $role_id => $role_name ) {
										echo '<option value="' . esc_attr( $role_id ) . '" ' . ( 'donotupdate' === $role_id ? 'selected="selected"' : '' ) . '>' . esc_html( $role_name ) . '</option>';
									}
									?>
									</select>
								</div>
							</div>
						</div>

						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'First Name', 'mainwp' ); ?></label>
							<div class="ui six wide column">
								<div class="ui left labeled input">
									<input type="text" name="first_name" id="first_name" value="" class="regular-text" />
								</div>
							</div>
						</div>

						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'Last Name', 'mainwp' ); ?></label>
							<div class="ui six wide column">
								<div class="ui left labeled input">
									<input type="text" name="last_name" id="last_name" value="" class="regular-text" />
								</div>
							</div>
						</div>

						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'Nickname', 'mainwp' ); ?></label>
							<div class="ui six wide column">
								<div class="ui left labeled input">
									<input type="text" name="nickname" id="nickname" value="" class="regular-text" />
								</div>
							</div>
						</div>

						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'Display name publicly as', 'mainwp' ); ?></label>
							<div class="ui six wide column">
								<div class="ui left labeled input">
									<select name="display_name" id="display_name"></select>
								</div>
							</div>
						</div>

						<h3><?php esc_html_e( 'Contact Info', 'mainwp' ); ?></h3>

						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'Email', 'mainwp' ); ?></label>
							<div class="ui six wide column">
								<div class="ui left labeled input">
									<input type="email" name="email" id="email" value="" class="regular-text ltr" />
								</div>
							</div>
						</div>

						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'Website', 'mainwp' ); ?></label>
							<div class="ui six wide column">
								<div class="ui left labeled input">
									<input type="url" name="url" id="url" value="" class="regular-text code" />
								</div>
							</div>
						</div>

						<h3><?php esc_html_e( 'About the user', 'mainwp' ); ?></h3>

						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'Biographical Info', 'mainwp' ); ?></label>
							<div class="ui six wide column">
								<div class="ui left labeled input">
									<textarea name="description" id="description" rows="5" cols="30"></textarea>
									<p class="description"><?php esc_html_e( 'Share a little biographical information to fill out your profile. This may be shown publicly.', 'mainwp' ); ?></p>
								</div>
							</div>
						</div>

						<h3><?php esc_html_e( 'Account Management', 'mainwp' ); ?></h3>

						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'Password', 'mainwp' ); ?></label>
							<div class="ui six wide column">
								<div class="ui left labeled action input">
									<input class="hidden" value=" "/>
									<input type="text" id="password" name="password" autocomplete="off" value="">
								</div>
							</div>
						</div>
					</div>
				</div>
			</form>
			<?php
			$is_demo = MainWP_Demo_Handle::is_demo_mode();
			?>
			<div class="actions">
				<div id="mainwp_update_password_error" style="display: none"></div>
				<span id="mainwp_users_updating"><i class="ui active inline loader tiny"></i></span>
				<?php
				if ( $is_demo ) {
					MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<input type="button" class="ui green button disabled" disabled="disabled" value="' . esc_attr__( 'Update', 'mainwp' ) . '">' );
				} else {
					?>
					<input type="button" class="ui green button" id="mainwp_btn_update_user" value="<?php esc_attr_e( 'Update', 'mainwp' ); ?>">
				<?php } ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders Users Table.
	 *
	 * @param bool   $cached true|false Cached or not.
	 * @param string $role Current user role.
	 * @param string $groups Current user groups.
	 * @param string $sites Current Child Sites the user is on.
	 * @param null   $search Search field.
	 * @param mixed  $clients Current Clients's Sites the user is on.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Cache::echo_body()
	 */
	public static function render_table( $cached = true, $role = '', $groups = '', $sites = '', $search = null, $clients = '' ) {

		/**
		 * Action: mainwp_before_users_table
		 *
		 * Fires before the User table.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_before_users_table' );
		?>
		<table id="mainwp-users-table" class="ui unstackable single line table" style="width:100%">
			<thead>
				<tr>
					<th  class="no-sort collapsing check-column"><span class="ui checkbox"><input id="cb-select-all-top" type="checkbox" /></span></th>
					<?php do_action( 'mainwp_users_table_header' ); ?>
					<th id="name"><?php esc_html_e( 'Name', 'mainwp' ); ?></th>
					<th id="username"><?php esc_html_e( 'Username', 'mainwp' ); ?></th>
					<th id="email"><?php esc_html_e( 'E-mail', 'mainwp' ); ?></th>
					<th id="role"><?php esc_html_e( 'Role', 'mainwp' ); ?></th>
					<th id="posts"><?php esc_html_e( 'Posts', 'mainwp' ); ?></th>
					<th id="website"><?php esc_html_e( 'Website', 'mainwp' ); ?></th>
					<th id="mainwp-users-actions" class="no-sort collapsing"></th>
				</tr>
			</thead>
			<tbody id="mainwp-users-list">
			<?php
			if ( $cached ) {
				MainWP_Cache::echo_body( 'Users' );
			} else {
				self::render_table_body( $role, $groups, $sites, $search, $clients );
			}
			?>
			</tbody>
		</table>
		<?php
		/**
		 * Action: mainwp_after_users_table
		 *
		 * Fires after the User table.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_after_users_table' );

		/**
		 * Filter: mainwp_users_table_fatures
		 *
		 * Filters the Manage Users table features.
		 *
		 * @since 4.1
		 */
		$table_features = array(
			'searching'  => 'true',
			'paging'     => 'true',
			'info'       => 'true',
			'stateSave'  => 'true',
			'scrollX'    => 'true',
			'responsive' => 'true',
			'colReorder' => '{ fixedColumnsLeft: 1, fixedColumnsRight: 1 }',
			'order'      => '[]',
		);
		$table_features = apply_filters( 'mainwp_users_table_fatures', $table_features );
		?>
		<script type="text/javascript">
		var responsive = <?php echo esc_html( $table_features['responsive'] ); ?>;
		if( jQuery( window ).width() > 1140 ) {
			responsive = false;
		}
		jQuery( document ).ready( function () {
			try {
				jQuery( "#mainwp-users-table" ).DataTable().destroy(); // to fix re-init database issue.
				$manage_users_table = jQuery( "#mainwp-users-table" ).DataTable( {
					"responsive" : responsive,
					"searching" : <?php echo esc_html( $table_features['searching'] ); ?>,
					"colReorder" : <?php echo esc_html( $table_features['colReorder'] ); ?>,
					"stateSave":  <?php echo esc_html( $table_features['stateSave'] ); ?>,
					"paging": <?php echo esc_html( $table_features['paging'] ); ?>,
					"info": <?php echo esc_html( $table_features['info'] ); ?>,
					"order": <?php echo esc_html( $table_features['order'] ); ?>,
					"scrollX" : <?php echo esc_html( $table_features['scrollX'] ); ?>,
					"lengthMenu": [ [10, 25, 50, 100, -1], [10, 25, 50, 100, "All"] ],
					"language" : { "emptyTable": "<?php esc_html_e( 'Please use the search options to find wanted users.', 'mainwp' ); ?>" },
					"columnDefs": [ {
						"targets": 'no-sort',
						"orderable": false
					} ],
					"preDrawCallback": function() {					
						jQuery('#mainwp-users-table .ui.dropdown').dropdown();
						jQuery('#mainwp-users-table .ui.checkbox').checkbox();
						mainwp_datatable_fix_menu_overflow();
						mainwp_table_check_columns_init(); // ajax: to fix checkbox all.
					}
				} );
			} catch ( err ) {
				// to fix js error.
			}

			_init_manage_sites_screen = function() {
				jQuery( '#mainwp-overview-screen-options-modal input[type=checkbox][id^="mainwp_show_column_"]' ).each( function() {
					var col_id = jQuery( this ).attr( 'id' );
					col_id = col_id.replace( "mainwp_show_column_", "" );
					try {	
						$manage_users_table.column( '#' + col_id ).visible( jQuery(this).is( ':checked' ) );
					} catch(err) {
						// to fix js error.
					}
				} );
			};
			_init_manage_sites_screen();

		} );
		</script>
		<?php
	}

	/**
	 * Renders the table body.
	 *
	 * @param string $role   User Role.
	 * @param string $groups Usr Group.
	 * @param string $sites  Users Sites.
	 * @param string $search Search field.
	 * @param mixed  $clients Current Clients's Sites the user is on.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Cache::init_cache()
	 * @uses \MainWP\Dashboard\MainWP_Cache::add_context()
	 * @uses \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
	 * @uses \MainWP\Dashboard\MainWP_DB::query()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_by_group_id()
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_DB::free_result()
	 * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
	 * @uses \MainWP\Dashboard\MainWP_Utility::map_site()
	 */
	public static function render_table_body( $role = '', $groups = '', $sites = '', $search = '', $clients = '' ) { // phpcs:ignore -- current complexity required to achieve desired results. Pull request solutions appreciated.
		MainWP_Cache::init_cache( 'Users' );

		$output         = new \stdClass();
		$output->errors = array();
		$output->users  = 0;

		$data_fields   = MainWP_System_Utility::get_default_map_site_fields();
		$data_fields[] = 'users';

		if ( 1 === (int) get_option( 'mainwp_optimize', 1 ) || MainWP_Demo_Handle::is_demo_mode() ) {

			$check_users_role = false;

			if ( ! empty( $role ) ) {
				$roles = explode( ',', $role );
				if ( is_array( $roles ) ) {
					$check_users_role = true;
				}
			}

			$dbwebsites = array();
			if ( ! empty( $sites ) ) {
				foreach ( $sites as $k => $v ) {
					if ( MainWP_Utility::ctype_digit( $v ) ) {
						$website = MainWP_DB::instance()->get_website_by_id( $v );
						if ( empty( $website->sync_errors ) && ! MainWP_System_Utility::is_suspended_site( $website ) ) {
							$dbwebsites[ $website->id ] = $website;
						}
					}
				}
			}
			if ( ! empty( $groups ) ) {
				foreach ( $groups as $k => $v ) {
					if ( MainWP_Utility::ctype_digit( $v ) ) {
						$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_by_group_id( $v ) );
						while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
							if ( ! empty( $website->sync_errors ) || MainWP_System_Utility::is_suspended_site( $website ) ) {
								continue;
							}
							$dbwebsites[ $website->id ] = $website;
						}
						MainWP_DB::free_result( $websites );
					}
				}
			}

			if ( ! empty( $clients ) && is_array( $clients ) ) {
				$websites = MainWP_DB_Client::instance()->get_websites_by_client_ids(
					$clients,
					array(
						'select_data' => $data_fields,
					)
				);

				foreach ( $websites as $website ) {
					if ( ! empty( $website->sync_errors ) || MainWP_System_Utility::is_suspended_site( $website ) ) {
						continue;
					}
					$dbwebsites[ $website->id ] = $website;
				}
			}

			if ( $dbwebsites ) {
				foreach ( $dbwebsites as $website ) {
					$allUsers         = json_decode( $website->users, true );
					$allUsersCount    = count( $allUsers );
					$search_user_role = array();
					if ( $check_users_role ) {
						for ( $i = 0; $i < $allUsersCount; $i++ ) {
							$user = $allUsers[ $i ];
							foreach ( $roles as $_role ) {
								if ( stristr( $user['role'], $_role ) ) {
									if ( ! in_array( $user['id'], $search_user_role ) ) {
										$search_user_role[] = $user['id'];
									}
									break;
								}
							}
						}
					}
					for ( $i = 0; $i < $allUsersCount; $i++ ) {
						$user = $allUsers[ $i ];
						if ( ! empty( $search ) && ! stristr( $user['login'], trim( $search ) ) && ! stristr( $user['display_name'], trim( $search ) ) && ! stristr( $user['email'], trim( $search ) ) ) {
							continue;
						}

						if ( $check_users_role ) {
							if ( ! in_array( $user['id'], $search_user_role ) ) {
								continue;
							}
						}

						$tmpUsers       = array( $user );
						$output->users += self::users_search_handler_renderer( $tmpUsers, $website );
					}
				}
			}
		} else {
			$dbwebsites = array();
			if ( '' !== $sites ) {
				foreach ( $sites as $k => $v ) {
					if ( MainWP_Utility::ctype_digit( $v ) ) {
						$website = MainWP_DB::instance()->get_website_by_id( $v );
						if ( empty( $website->sync_errors ) && ! MainWP_System_Utility::is_suspended_site( $website ) ) {
							$dbwebsites[ $website->id ] = MainWP_Utility::map_site(
								$website,
								$data_fields
							);
						}
					}
				}
			}
			if ( '' !== $groups ) {
				foreach ( $groups as $k => $v ) {
					if ( MainWP_Utility::ctype_digit( $v ) ) {
						$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_by_group_id( $v ) );
						while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
							if ( '' !== $website->sync_errors || MainWP_System_Utility::is_suspended_site( $website ) ) {
								continue;
							}
							$dbwebsites[ $website->id ] = MainWP_Utility::map_site(
								$website,
								$data_fields
							);
						}
						MainWP_DB::free_result( $websites );
					}
				}
			}

			if ( '' !== $clients && is_array( $clients ) ) {
				$websites = MainWP_DB_Client::instance()->get_websites_by_client_ids(
					$clients,
					array(
						'select_data' => $data_fields,
					)
				);
				if ( $websites ) {
					foreach ( $websites as $website ) {
						if ( '' !== $website->sync_errors || MainWP_System_Utility::is_suspended_site( $website ) ) {
							continue;
						}
						$dbwebsites[ $website->id ] = MainWP_Utility::map_site(
							$website,
							$data_fields
						);
					}
				}
			}

			$post_data = array(
				'role'           => $role,
				'search'         => '*' . trim( $search ) . '*',
				'search_columns' => 'user_login,display_name,user_email',
			);

			MainWP_Connect::fetch_urls_authed(
				$dbwebsites,
				'search_users',
				$post_data,
				array(
					self::get_class_name(),
					'users_search_handler',
				),
				$output
			);
		}

		MainWP_Cache::add_context(
			'Users',
			array(
				'count'   => $output->users,
				'keyword' => $search,
				'status'  => ( isset( $_POST['role'] ) ? sanitize_text_field( wp_unslash( $_POST['role'] ) ) : 'administrator' ), // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				'sites'   => '' !== $sites ? $sites : '',
				'groups'  => '' !== $groups ? $groups : '',
				'clients' => ( '' !== $clients ) ? $clients : '',
			)
		);

		// Sort if required.

		if ( empty( $output->users ) ) {
			self::render_cache_not_found();
			return;
		}
	}

	/**
	 * Renders when cache is not found.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Cache::add_body()
	 */
	public static function render_cache_not_found() {
		ob_start();
		$newOutput = ob_get_clean();
		echo $newOutput; // phpcs:ignore WordPress.Security.EscapeOutput
		MainWP_Cache::add_body( 'Users', $newOutput );
	}

	/**
	 * Gets the selected users current role.
	 *
	 * @param string $role Selected Users Role.
	 */
	private static function get_role( $role ) {
		if ( is_array( $role ) ) {
			$allowed_roles = array( 'subscriber', 'administrator', 'editor', 'author', 'contributor' );
			$ret           = '';
			foreach ( $role as $ro ) {
				if ( in_array( $ro, $allowed_roles ) ) {
					$ret .= ucfirst( $ro ) . ', ';
				}
			}
			$ret = rtrim( $ret, ', ' );
			if ( '' === $ret ) {
				$ret = 'None';
			}

			return $ret;
		}

		return ucfirst( $role );
	}

	/**
	 * Renders Search results.
	 *
	 * @param array  $users Users array.
	 * @param object $website Object containing the child site info.
	 *
	 * @return mixed Search results table.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Cache::add_body()
	 */
	protected static function users_search_handler_renderer( $users, $website ) {
		$return  = 0;
		$is_demo = MainWP_Demo_Handle::is_demo_mode();

		foreach ( $users as $user ) {
			if ( ! is_array( $user ) ) {
				continue;
			}
			ob_start();
			?>
			<tr>
					<td class="check-column"><span class="ui checkbox"><input type="checkbox" name="user[]" value="1"></span></td>
					<?php do_action( 'mainwp_users_table_column', $user, $website ); ?>
					<td class="name column-name">
					<?php echo ! empty( $user['display_name'] ) ? esc_html( $user['display_name'] ) : '&nbsp;'; ?>
					<div class="row-actions-working">
						<i class="ui active inline loader tiny"></i> <?php esc_html_e( 'Please wait', 'mainwp' ); ?>
					</div>
					</td>
				<td class="username column-username"><strong><abbr title="<?php echo esc_attr( $user['login'] ); ?>"><?php echo esc_html( $user['login'] ); ?></abbr></strong></td>
				<td class="email column-email"><a href="mailto:<?php echo esc_attr( $user['email'] ); ?>"><?php echo esc_html( $user['email'] ); ?></a></td>
				<td class="role column-role"><?php echo self::get_role( $user['role'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
				<td class="posts column-posts"><a href="<?php echo esc_url( admin_url( 'admin.php?page=PostBulkManage&siteid=' . intval( $website->id ) ) . '&userid=' . $user['id'] ); ?>"><?php echo esc_html( $user['post_count'] ); ?></a></td>
				<td class="website column-website"><a href="<?php echo esc_url( $website->url ); ?>" target="_blank"><?php echo esc_html( $website->url ); ?></a></td>
				<td class="right aligned">
					<input class="userId" type="hidden" name="id" value="<?php echo esc_attr( $user['id'] ); ?>" />
					<input class="userName" type="hidden" name="name" value="<?php echo esc_attr( $user['login'] ); ?>" />
					<input class="websiteId" type="hidden" name="id" value="<?php echo intval( $website->id ); ?>" />
					<div class="ui left pointing dropdown icon mini basic green button" style="z-index: 999">
						<a href="javascript:void(0)"><i class="ellipsis horizontal icon"></i></a>
						<div class="menu">
							<a class="item user_getedit" href="#"><?php esc_html_e( 'Edit', 'mainwp' ); ?></a>
							<?php if ( ( 1 !== (int) $user['id'] ) && ( $user['login'] !== $website->adminname ) ) { ?>
							<a class="item user_submitdelete" href="#"><?php esc_html_e( 'Delete', 'mainwp' ); ?></a>
							<?php } elseif ( ( 1 === (int) $user['id'] ) || ( $user['login'] === $website->adminname ) ) { ?>
							<a href="javascript:void(0)" class="item" data-tooltip="This user is used for our secure link, it can not be deleted." data-inverted="" data-position="left center"><?php esc_html_e( 'Delete', 'mainwp' ); ?></a>
							<?php } ?>
							<?php if ( ! $is_demo ) : ?>
							<a class="item" href="<?php echo 'admin.php?page=SiteOpen&newWindow=yes&websiteid=' . intval( $website->id ); ?>&_opennonce=<?php echo esc_html( wp_create_nonce( 'mainwp-admin-nonce' ) ); ?>" data-tooltip="<?php esc_attr_e( 'Jump to the site WP Admin', 'mainwp' ); ?>"  data-position="bottom right"  data-inverted="" class="open_newwindow_wpadmin ui green basic icon button" target="_blank"><?php esc_html_e( 'Go to WP Admin', 'mainwp' ); ?></a>
							<?php else : ?>
								<a class="item" href="<?php echo esc_url( $website->url ) . 'wp-admin.html'; ?>" data-tooltip="<?php esc_attr_e( 'Jump to the site WP Admin', 'mainwp' ); ?>"  data-position="bottom right"  data-inverted="" class="open_newwindow_wpadmin ui green basic icon button" target="_blank"><?php esc_html_e( 'Go to WP Admin', 'mainwp' ); ?></a>
							<?php endif; ?>
							<?php
							/**
							 * Action: mainwp_users_table_action
							 *
							 * Adds a new item in the Actions menu in Manage Users table.
							 *
							 * Suggested HTML markup:
							 * <a class="item" href="Your custom URL">Your custom label</a>
							 *
							 * @param array $user    Array containing the user data.
							 * @param array $website Object containing the website data.
							 *
							 * @since 4.1
							 */
							do_action( 'mainwp_users_table_action', $user, $website );
							?>
						</div>
					</div>
				</td>
			</tr>
			<?php
			$newOutput = ob_get_clean();
			echo $newOutput; // phpcs:ignore WordPress.Security.EscapeOutput
			MainWP_Cache::add_body( 'Users', $newOutput );
			++$return;
		}

		return $return;
	}

	/**
	 * Handles user search.
	 *
	 * @param mixed $data Search data.
	 * @param mixed $website Child Site.
	 * @param mixed $output Output to pass to self::users_search_handler_renderer().
	 *
	 * @uses \MainWP\Dashboard\MainWP_Error_Helper::get_error_message()
	 * @uses \MainWP\Dashboard\MainWP_Exception
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_child_response()
	 */
	public static function users_search_handler( $data, $website, &$output ) {
		if ( MainWP_Demo_Handle::get_instance()->is_demo_website( $website ) ) {
			return;
		}
		if ( 0 < preg_match( '/<mainwp>(.*)<\/mainwp>/', $data, $results ) ) {
			$result = $results[1];
			$users  = MainWP_System_Utility::get_child_response( base64_decode( $result ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
			unset( $results );
			$output->users += self::users_search_handler_renderer( $users, $website );
			unset( $users );
		} else {
			$output->errors[ $website->id ] = MainWP_Error_Helper::get_error_message( new MainWP_Exception( 'NOMAINWP', $website->url ) );
		}
	}

	/**
	 * Deletes user.
	 */
	public static function delete() {
		self::action( 'delete' );
		die( wp_json_encode( array( 'result' => esc_html__( 'User has been deleted', 'mainwp' ) ) ) );
	}

	/**
	 * Edits user.
	 */
	public static function edit() {
		$information = self::action( 'edit' );
		wp_send_json( $information );
	}

	/**
	 * Updates user.
	 */
	public static function update_user() {
		self::action( 'update_user' );
		die( wp_json_encode( array( 'result' => esc_html__( 'User has been updated', 'mainwp' ) ) ) );
	}

	/**
	 * Updates users password.
	 */
	public static function update_password() {
		self::action( 'update_password' );
		die( wp_json_encode( array( 'result' => esc_html__( 'User password has been updated', 'mainwp' ) ) ) );
	}

	/**
	 * Users actions.
	 *
	 * @param mixed  $pAction Action to perform delete|update_user|update_password.
	 * @param string $extra   Additional Roles to add if any.
	 *
	 * @return mixed $information User update info that is returned.
	 * @throws \Exception Error message.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
	 * @uses \MainWP\Dashboard\MainWP_DB::update_website_values()
	 * @uses \MainWP\Dashboard\MainWP_Error_Helper::get_error_message()
	 * @uses \MainWP\Dashboard\MainWP_Exception
	 * @uses \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
	 */
	public static function action( $pAction, $extra = '' ) { // phpcs:ignore -- current complexity required to achieve desired results. Pull request solutions appreciated.

		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$userId    = isset( $_POST['userId'] ) ? sanitize_text_field( wp_unslash( $_POST['userId'] ) ) : false;
		$userName  = isset( $_POST['userName'] ) ? sanitize_text_field( wp_unslash( $_POST['userName'] ) ) : '';
		$websiteId = isset( $_POST['websiteId'] ) ? sanitize_text_field( wp_unslash( $_POST['websiteId'] ) ) : false;
		$pass      = isset( $_POST['update_password'] ) ? rawurldecode( wp_unslash( $_POST['update_password'] ) ) : '';

		if ( function_exists( '\mb_convert_encoding' ) ) {
			$pass = \mb_convert_encoding( $pass, 'ISO-8859-1', 'UTF-8' );
		} else {
			$pass = utf8_decode( $pass ); // to compatible.
		}

		if ( empty( $userId ) || empty( $websiteId ) ) {
			die( wp_json_encode( array( 'error' => esc_html__( 'Site ID or user ID not found. Please reload the page and try again.', 'mainwp' ) ) ) );
		}

		$website = MainWP_DB::instance()->get_website_by_id( $websiteId );

		if ( MainWP_System_Utility::is_suspended_site( $website ) ) {
			die(
				wp_json_encode(
					array(
						'error'     => esc_html__( 'Suspended site.', 'mainwp' ),
						'errorCode' => 'SUSPENDED_SITE',
					)
				)
			);
		}

		if ( ! MainWP_System_Utility::can_edit_website( $website ) ) {
			die( wp_json_encode( array( 'error' => esc_html__( 'You can not edit this website!', 'mainwp' ) ) ) );
		}

		if ( ( 'delete' === $pAction ) && ( $website->adminname === $userName ) ) {
			die( wp_json_encode( array( 'error' => esc_html__( 'This user is used for our secure link, it can not be deleted.', 'mainwp' ) ) ) );
		}

		if ( 'update_user' === $pAction ) {
			$user_data = isset( $_POST['user_data'] ) ? wp_unslash( $_POST['user_data'] ) : '';
			parse_str( $user_data, $extra );
			if ( $website->adminname === $userName ) {

				if ( is_array( $extra ) && isset( $extra['role'] ) ) {
					unset( $extra['role'] );
				}
			}

			if ( ! empty( $pass ) ) {
				$extra['pass1'] = $pass;
				$extra['pass2'] = $pass;
			}
		}
		// phpcs:enable

		$optimize = ( 1 === (int) get_option( 'mainwp_optimize', 1 ) ) ? 1 : 0;

		/**
		* Action: mainwp_before_user_action
		*
		* Fires before user edit/delete/update_user/update_password actions.
		*
		* @since 4.1
		*/
		do_action( 'mainwp_before_user_action', $pAction, $userId, $extra, $pass, $optimize, $website );

		try {
			$information = MainWP_Connect::fetch_url_authed(
				$website,
				'user_action',
				array(
					'action'    => $pAction,
					'id'        => $userId,
					'extra'     => $extra,
					'user_pass' => $pass,
					'optimize'  => $optimize,
				)
			);

			if ( is_array( $information ) && isset( $information['status'] ) && ( 'SUCCESS' === $information['status'] ) ) {
				$data = isset( $information['other_data']['users_data'] ) ? $information['other_data']['users_data'] : array();  // user actions data.

				/**
				 * Fires immediately after user action.
				 *
				 * @since 4.5.1.1
				 */
				do_action( 'mainwp_user_action', $website, $pAction, $data, $extra, $optimize );
			}
		} catch ( MainWP_Exception $e ) {
			die( wp_json_encode( array( 'error' => MainWP_Error_Helper::get_error_message( $e ) ) ) );
		}

		/**
		* Action: mainwp_after_user_action
		*
		* Fires after user edit/delete/update_user/update_password actions.
		*
		* @since 4.1
		*/
		do_action( 'mainwp_after_user_action', $information, $pAction, $userId, $extra, $pass, $optimize, $website );

		if ( is_array( $information ) && isset( $information['error'] ) ) {
			wp_send_json( array( 'error' => esc_html( $information['error'] ) ) );
		}

		if ( ! isset( $information['status'] ) || ( 'SUCCESS' !== $information['status'] ) ) {
			die( wp_json_encode( array( 'error' => esc_html__( 'Unexpected error.', 'mainwp' ) ) ) );
		} elseif ( 'update_user' === $pAction ) {
			if ( $optimize && isset( $information['users'] ) ) {
				$websiteValues['users'] = wp_json_encode( $information['users'] );
				MainWP_DB::instance()->update_website_values( $websiteId, $websiteValues );
			}
		}

		if ( 'edit' === $pAction ) {
			if ( $website->adminname === $userName ) {
				// This user is used for our secure link, you can not change the role.
				if ( is_array( $information ) && isset( $information['user_data'] ) ) {
					$information['is_secure_admin'] = 1;
				}
			}
		}

		return $information;
	}

	/**
	 * Renders the Add New user form.
	 */
	public static function render_bulk_add() {

		/**
		 * Filter: mainwp_new_user_password_complexity
		 *
		 * Filters the Password lenght for the Add New user, Password field.
		 *
		 * Since 4.1
		 */
		$pass_complexity = apply_filters( 'mainwp_new_user_password_complexity', '24' );
		self::render_header( 'Add' );
		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		?>
		<div class="ui alt segment" id="mainwp-add-users">
			<?php
			/**
			 * Action: mainwp_before_new_user_form
			 *
			 * Fires before the Add New user form.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_before_new_user_form' );
			?>
			<form action="" method="post" name="createuser" id="createuser" class="add:users: validate">
				<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
				<div class="mainwp-main-content">
					<div class="ui hidden divider"></div>
					<?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-add-user-info-message' ) ) : ?>
					<div class="ui info message">
						<i class="close icon mainwp-notice-dismiss" notice-id="mainwp-add-user-info-message"></i>
						<?php printf( esc_html__( 'Use the provided form to create a new user on your child site.  For additional help, please check this %1$shelp documentation%2$s.', 'mainwp' ), '<a href="https://kb.mainwp.com/docs/create-a-new-user/" target="_blank">', '</a>' ); ?>
					</div>
				<?php endif; ?>
					<div class="ui message" id="mainwp-message-zone" style="display:none;"></div>
					<div id="mainwp-add-new-user-form" >
						<?php
						/**
						 * Action: mainwp_before_new_user_form_fields
						 *
						 * Fires before the Add New user form fields.
						 *
						 * @since 4.1
						 */
						do_action( 'mainwp_before_new_user_form_fields' );
						?>
						<div class="ui form">
							<div class="ui grid field">
								<label class="six wide column middle aligned"><?php esc_html_e( 'Username', 'mainwp' ); ?></label>
								<div class="ui six wide column">
									<div class="ui left labeled input">
										<input type="text" id="user_login" name="user_login" value="<?php echo ( isset( $_POST['user_login'] ) ) ? esc_html( sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) ) : ''; ?>">
									</div>
								</div>
							</div>
							<div class="ui grid field">
								<label class="six wide column middle aligned"><?php esc_html_e( 'E-mail', 'mainwp' ); ?></label>
								<div class="ui six wide column">
									<div class="ui left labeled input">
										<input type="text" id="email" name="email" value="<?php echo ( isset( $_POST['email'] ) ) ? esc_html( sanitize_text_field( wp_unslash( $_POST['email'] ) ) ) : ''; ?>">
									</div>
								</div>
							</div>
							<div class="ui grid field">
								<label class="six wide column middle aligned"><?php esc_html_e( 'First Name', 'mainwp' ); ?></label>
								<div class="ui six wide column">
									<div class="ui left labeled input">
										<input type="text" id="first_name" name="first_name" value="<?php echo ( isset( $_POST['first_name'] ) ) ? esc_html( sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) ) : ''; ?>">
									</div>
								</div>
							</div>
							<div class="ui grid field">
								<label class="six wide column middle aligned"><?php esc_html_e( 'Last Name', 'mainwp' ); ?></label>
								<div class="ui six wide column">
									<div class="ui left labeled input">
										<input type="text" id="last_name" name="last_name" value="<?php echo ( isset( $_POST['last_name'] ) ) ? esc_html( sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) ) : ''; ?>">
									</div>
								</div>
							</div>
							<div class="ui grid field">
								<label class="six wide column middle aligned"><?php esc_html_e( 'Website', 'mainwp' ); ?></label>
								<div class="ui six wide column">
									<div class="ui left labeled input">
										<input type="text" id="url" name="url" value="<?php echo ( isset( $_POST['url'] ) ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : ''; ?>">
									</div>
								</div>
							</div>
							<div class="ui grid field">
								<label class="six wide column middle aligned"><?php esc_html_e( 'Password', 'mainwp' ); ?></label>
								<div class="ui six wide column">
									<div class="ui left labeled action input">
										<input class="hidden" value=" "/>
										<input type="text" id="password" name="password" autocomplete="off" value="<?php echo esc_attr( wp_generate_password( $pass_complexity ) ); ?>">
										<button class="ui green right button wp-generate-pw"><?php esc_html_e( 'Generate Password', 'mainwp' ); ?></button>
									</div>
								</div>
							</div>

							<div class="ui grid field">
								<label class="six wide column middle aligned"><?php esc_html_e( 'Send Password?', 'mainwp' ); ?></label>
								<div class="six wide column ui toggle checkbox">
									<input type="checkbox" name="send_password" id="send_password" <?php echo ( isset( $_POST['send_password'] ) ) ? esc_html( 'checked' ) : ''; ?> >
								</div>
							</div>

							<?php
							$user_roles = array(
								'subscriber'    => esc_html__( 'Subscriber', 'mainwp' ),
								'administrator' => esc_html__( 'Administrator', 'mainwp' ),
								'editor'        => esc_html__( 'Editor', 'mainwp' ),
								'author'        => esc_html__( 'Author', 'mainwp' ),
								'contributor'   => esc_html__( 'Contributor', 'mainwp' ),
							);
							$user_roles = apply_filters_deprecated( 'mainwp-users-manage-roles', array( $user_roles ), '4.0.7.2', 'mainwp_users_manage_roles' );  // @deprecated Use 'mainwp_users_manage_roles' instead.
							$user_roles = apply_filters( 'mainwp_users_manage_roles', $user_roles );
							?>

							<div class="ui grid field">
								<label class="six wide column middle aligned"><?php esc_html_e( 'Role', 'mainwp' ); ?></label>
								<div class="six wide column">
									<select class="ui dropdown" name="role" id="role">
										<?php
										foreach ( $user_roles as $r => $n ) {
											if ( empty( $r ) ) {
												continue;
											}
											?>
											<option value="<?php echo esc_html( $r ); ?>" <?php echo ( isset( $_POST['role'] ) && $_POST['role'] === $r ) ? esc_html( 'selected' ) : ''; ?>><?php echo esc_html( $n ); ?></option>
											<?php
										}
										?>
									</select>
								</div>
							</div>

						</div>
						<?php
						/**
						 * Action: mainwp_after_new_user_form_fields
						 *
						 * Fires after the Add New user form fields.
						 *
						 * @since 4.1
						 */
						do_action( 'mainwp_after_new_user_form_fields' );
						?>
					</div>
				</div>
				<div class="mainwp-side-content mainwp-no-padding">
					<?php
					/**
					 * Action: mainwp_add_new_user_sidebar_top
					 *
					 * Fires at the top of the sidebar on Add New user page.
					 *
					 * @since 4.1
					 */
					do_action( 'mainwp_add_new_user_sidebar_top' );
					?>
					<div class="mainwp-select-sites ui accordion mainwp-sidebar-accordion">
						<?php
						/**
						 * Action: mainwp_add_new_user_before_select_sites
						 *
						 * Fires before the Select Sites section on the Add New user page.
						 *
						 * @since 4.1
						 */
						do_action( 'mainwp_add_new_user_before_select_sites' );
						?>
						<div class="title active"><i class="dropdown icon"></i> <?php esc_html_e( 'Select Sites', 'mainwp' ); ?></div>
						<div class="content active">
							<?php
							$sel_params = array(
								'show_client' => true,
							);
							MainWP_UI_Select_Sites::select_sites_box( $sel_params );
							?>
							</div>
						<?php
						/**
						 * Action: mainwp_add_new_user_after_select_sites
						 *
						 * Fires after the Select Sites section on the Add New user page.
						 *
						 * @since 4.1
						 */
						do_action( 'mainwp_add_new_user_after_select_sites' );
						?>
					</div>
					<div class="ui fitted divider"></div>
					<div class="mainwp-search-submit">
						<?php
						/**
						 * Action: mainwp_add_new_user_before_submit_button
						 *
						 * Fires before the Submit button on the Add New user page.
						 *
						 * @since 4.1
						 */
						do_action( 'mainwp_add_new_user_before_submit_button' );

						$is_demo = MainWP_Demo_Handle::is_demo_mode();
						if ( $is_demo ) {
							MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<input type="button" class="ui green big button disabled" disabled="disabled" value="' . esc_attr__( 'Add New User', 'mainwp' ) . '"/>' );
						} else {
							?>
							<input type="button" name="createuser" id="bulk_add_createuser" class="ui big green fluid button" value="<?php esc_attr_e( 'Add New User', 'mainwp' ); ?> "/>
							<?php
						}
						/**
						 * Action: mainwp_add_new_user_after_submit_button
						 *
						 * Fires after the Submit button on the Add New user page.
						 *
						 * @since 4.1
						 */
						do_action( 'mainwp_add_new_user_after_submit_button' );
						?>
					</div>
					<?php
					/**
					 * Action: mainwp_add_new_user_sidebar_bottom
					 *
					 * Fires at the bottom of the sidebar on Add New user page.
					 *
					 * @since 4.1
					 */
					do_action( 'mainwp_add_new_user_sidebar_bottom' );
					?>
				</div>
				<div style="clear:both"></div>
			</form>
			<?php
			/**
			 * Action: mainwp_after_new_user_form
			 *
			 * Fires after the Add New user form.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_after_new_user_form' );
			?>
		</div>
		<?php
		// phpcs:enable

		self::render_footer( 'Add' );
	}

	/**
	 * Renders bulk Import Users form.
	 *
	 * @return void
	 */
	public static function render_bulk_import_users() {
		if ( isset( $_FILES['import_user_file_bulkupload'] ) && isset( $_FILES['import_user_file_bulkupload']['error'] ) && UPLOAD_ERR_OK === $_FILES['import_user_file_bulkupload']['error'] ) { //phpcs:ignore WordPress.Security.NonceVerification.Missing
			self::render_bulk_upload();
			return;
		}
		?>
		<?php self::render_header( 'Import' ); ?>
		<div id="MainWP_Bulk_AddUser">
			<form action="" method="post" name="createuser" id="createuser" class="add:users: validate" enctype="multipart/form-data">
				<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
				<?php self::render_import_users(); ?>
			</form>
		</div>
		<?php
		self::render_footer( 'Import' );
	}

	/**
	 * Method render_import_users()
	 *
	 * Render Import Users page.
	 */
	public static function render_import_users() {
		?>
		<div class="ui segment" id="mainwp-import-sites">
			<?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-import-users-info-message' ) ) : ?>
				<div class="ui info message">
					<i class="close icon mainwp-notice-dismiss" notice-id="mainwp-import-users-info-message"></i>
					<?php printf( esc_html__( 'Use the form to bulk import users.  You can download the sample CSV file to see how to fomat the import file properly.  For additional help, please check this %1$shelp documentation%2$s.', 'mainwp' ), '<a href="https://kb.mainwp.com/docs/import-users/" target="_blank">', '</a>' ); ?>
				</div>
			<?php endif; ?>

			<div id="mainwp-message-zone" class="ui message" style="display:none"></div>
			<?php
			/**
			 * Action: mainwp_before_import_users
			 *
			 * Fires above the Import Users section.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_before_import_users' );
			$is_demo = MainWP_Demo_Handle::is_demo_mode();
			?>
			<div id="mainwp-message-zone" class="ui message" style="display:none"></div>
			<div class="ui form">
					<form method="POST" action="" enctype="multipart/form-data" id="mainwp_managesites_bulkadd_form">
						<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'Upload the CSV file', 'mainwp' ); ?></label>
						<div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Click to upload the import file.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
								<div class="ui file input">
								<input type="file" name="import_user_file_bulkupload" id="import_user_file_bulkupload" accept="text/comma-separated-values" />
								</div>
							</div>
						</div>
						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'CSV file contains a header', 'mainwp' ); ?></label>
						<div class="ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'Enable if the import file contains a header.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
							<input type="checkbox" name="import_user_chk_header_first" checked="checked" id="import_user_chk_header_first" value="1" />
							</div>
						</div>
						<div class="ui divider"></div>
						<?php
						if ( $is_demo ) {
								MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<input type="button" class="ui big green button disabled" disabled="disabled" value="' . esc_attr__( 'Import Users', 'mainwp' ) . '"/>' );
						} else {
							?>
								<input type="button" name="createuser" id="bulk_import_createuser" class="ui big green button" value="<?php esc_attr_e( 'Import Users', 'mainwp' ); ?>" />
							<?php } ?>	
							<a href="<?php echo esc_url( MAINWP_PLUGIN_URL . 'assets/csv/sample_users.csv' ); ?>" class="ui big green basic right floated button"><?php esc_html_e( 'Download Sample CSV file', 'mainwp' ); ?></a>
					</form>
				</div>
			<?php
			/**
			 * Action: mainwp_after_import_users
			 *
			 * Fires under the Import Users section.
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_after_import_users' );
			?>
			</div>
		<?php
	}

	/**
	 * Method do_bulk_add()
	 *
	 * Bulk User addition $_POST Handler.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
	 * @uses \MainWP\Dashboard\MainWP_DB::query()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_DB::free_result()
	 * @uses \MainWP\Dashboard\MainWP_Bulk_Add::get_class_name()
	 * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
	 * @uses \MainWP\Dashboard\MainWP_Utility::map_site()
	 */
	public static function do_bulk_add() { // phpcs:ignore -- Current complexity is required to achieve desired results. Pull request solutions appreciated.
		$errors      = array();
		$errorFields = array();

		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( isset( $_POST['select_by'] ) ) {
			$selected_sites   = ( isset( $_POST['selected_sites'] ) && is_array( $_POST['selected_sites'] ) ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['selected_sites'] ) ) : array();
			$selected_groups  = ( isset( $_POST['selected_groups'] ) && is_array( $_POST['selected_groups'] ) ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['selected_groups'] ) ) : array();
			$selected_clients = ( isset( $_POST['selected_clients'] ) && is_array( $_POST['selected_clients'] ) ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['selected_clients'] ) ) : array();

			if ( ( 'group' === $_POST['select_by'] && 0 === count( $selected_groups ) ) || ( 'site' === $_POST['select_by'] && 0 === count( $selected_sites ) ) || ( 'client' === $_POST['select_by'] && 0 === count( $selected_clients ) ) ) {
				$errors[] = esc_html__( 'Please select at least one website or group or client.', 'mainwp' );
			}
		} else {
			$errors[] = esc_html__( 'Please select at least one website or group or client.', 'mainwp' );
		}

		if ( ! isset( $_POST['user_login'] ) || '' === $_POST['user_login'] ) {
			$errorFields[] = 'user_login';
		}

		if ( ! isset( $_POST['email'] ) || '' === $_POST['email'] ) {
			$errorFields[] = 'email';
		}

		$allowed_roles = array( 'subscriber', 'administrator', 'editor', 'author', 'contributor' );
		$cus_roles     = array();
		$cus_roles     = apply_filters_deprecated( 'mainwp-users-manage-roles', array( $cus_roles ), '4.0.7.2', 'mainwp_users_manage_roles' );  // @deprecated Use 'mainwp_users_manage_roles' instead.
		$cus_roles     = apply_filters( 'mainwp_users_manage_roles', $cus_roles );

		if ( is_array( $cus_roles ) && 0 < count( $cus_roles ) ) {
			$cus_roles     = array_keys( $cus_roles );
			$allowed_roles = array_merge( $allowed_roles, $cus_roles );
		}

		if ( ! isset( $_POST['role'] ) || ! in_array( $_POST['role'], $allowed_roles ) ) {
			$errorFields[] = 'role';
		}

		$data_fields = MainWP_System_Utility::get_default_map_site_fields();

		if ( ( 0 === count( $errors ) ) && ( 0 === count( $errorFields ) ) ) {
			$user_to_add = array(
				'user_pass'  => isset( $_POST['pass1'] ) ? wp_unslash( $_POST['pass1'] ) : '',
				'user_login' => isset( $_POST['user_login'] ) ? sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) : '',
				'user_url'   => isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '',
				'user_email' => isset( $_POST['email'] ) ? sanitize_text_field( wp_unslash( $_POST['email'] ) ) : '',
				'first_name' => isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '',
				'last_name'  => isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '',
				'role'       => isset( $_POST['role'] ) ? sanitize_text_field( wp_unslash( $_POST['role'] ) ) : '',
			);

			$dbwebsites = array();

			if ( isset( $_POST['select_by'] ) && 'site' === $_POST['select_by'] ) {
				foreach ( $selected_sites as $k ) {
					if ( MainWP_Utility::ctype_digit( $k ) ) {
						$website = MainWP_DB::instance()->get_website_by_id( $k );
						if ( empty( $website->sync_errors ) && ! MainWP_System_Utility::is_suspended_site( $website ) ) {
							$dbwebsites[ $website->id ] = MainWP_Utility::map_site(
								$website,
								$data_fields
							);
						}
					}
				}
			} elseif ( isset( $_POST['select_by'] ) && 'client' === $_POST['select_by'] ) {
				$websites = MainWP_DB_Client::instance()->get_websites_by_client_ids(
					$selected_clients,
					array(
						'select_data' => $data_fields,
					)
				);

				if ( $websites ) {
					foreach ( $websites as $website ) {
						if ( '' !== $website->sync_errors || MainWP_System_Utility::is_suspended_site( $website ) ) {
							continue;
						}
						$dbwebsites[ $website->id ] = MainWP_Utility::map_site(
							$website,
							$data_fields
						);
					}
				}
			} else {
				foreach ( $selected_groups as $k ) {
					if ( MainWP_Utility::ctype_digit( $k ) ) {
						$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_by_group_id( $k ) );
						while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
							if ( '' !== $website->sync_errors || MainWP_System_Utility::is_suspended_site( $website ) ) {
								continue;
							}
							$dbwebsites[ $website->id ] = MainWP_Utility::map_site(
								$website,
								$data_fields
							);
						}
						MainWP_DB::free_result( $websites );
					}
				}
			}

			$startTime = time();
			if ( 0 < count( $dbwebsites ) ) {
				$post_data      = array(
					'new_user'      => base64_encode( wp_json_encode( $user_to_add ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
					'send_password' => ( isset( $_POST['send_password'] ) ? intval( $_POST['send_password'] ) : '' ),
				);
				$output         = new \stdClass();
				$output->ok     = array();
				$output->errors = array();

				/**
				* Action: mainwp_before_user_create
				*
				* Fires before user create.
				*
				* @since 4.1
				*/
				do_action( 'mainwp_before_user_create', $post_data, $dbwebsites );

				MainWP_Connect::fetch_urls_authed(
					$dbwebsites,
					'newuser',
					$post_data,
					array(
						MainWP_Bulk_Add::get_class_name(),
						'posting_bulk_handler',
					),
					$output
				);

				/**
				* Action: mainwp_after_user_create
				*
				* Fires after user create.
				*
				* @since 4.1
				*/
				do_action( 'mainwp_after_user_create', $output, $post_data, $dbwebsites );
			}

			$countSites     = 0;
			$countRealItems = 0;
			foreach ( $dbwebsites as $website ) {
				if ( isset( $output->ok[ $website->id ] ) && 1 === (int) $output->ok[ $website->id ] ) {
					++$countSites;
					++$countRealItems;
				}
			}
			self::render_bulk_add_modal( $dbwebsites, $output );
		} else {
			echo wp_json_encode( array( $errorFields, $errors ) );
		}
		// phpcs:enable
	}

	/**
	 * Renders Bulk User addition Modal window.
	 *
	 * @param mixed $dbwebsites Child sites list.
	 * @param mixed $output Modal window content.
	 */
	public static function render_bulk_add_modal( $dbwebsites, $output ) {
		// phpcs:disable WordPress.Security.EscapeOutput
		?>
		<div id="mainwp-creating-new-user-modal" class="ui modal">
			<i class="close icon"></i>
				<div class="header"><?php esc_html_e( 'New User', 'mainwp' ); ?></div>
				<div class="content">
					<div class="ui middle aligned divided selection list">
						<?php foreach ( $dbwebsites as $website ) : ?>
						<div class="item ui grid">
							<span class="content"><a href="<?php echo esc_url( admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ) ); ?>"><?php echo esc_html( stripslashes( $website->name ) ); ?></a></span>
							<span class="right floated content"><?php echo( isset( $output->ok[ $website->id ] ) && 1 === (int) $output->ok[ $website->id ] ? '<i class="check green icon"></i> ' : '<i class="times red icon"></i> ' . $output->errors[ $website->id ] ); ?></span>
						</div>
						<?php endforeach; ?>
					</div>
				</div>
				<div class="actions">
				</div>
			</div>
		<?php
		// phpcs:enable
	}

	/**
	 * Renders Import Users Modal window.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_wp_file_system()
	 * @uses \MainWP\Dashboard\MainWP_Utility::starts_with()
	 */
	public static function render_bulk_upload() {
		self::render_header( 'Import' );
		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$errors = array();
		if ( isset( $_FILES['import_user_file_bulkupload']['error'] ) && UPLOAD_ERR_OK === $_FILES['import_user_file_bulkupload']['error'] ) {
			if ( isset( $_FILES['import_user_file_bulkupload']['tmp_name'] ) && is_uploaded_file( $_FILES['import_user_file_bulkupload']['tmp_name'] ) ) {
				$tmp_path     = isset( $_FILES['import_user_file_bulkupload']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['import_user_file_bulkupload']['tmp_name'] ) ) : '';
				$wpFileSystem = MainWP_System_Utility::get_wp_file_system();
				// phpcs:enable
				/**
				 * WordPress files system object.
				 *
				 * @global object
				 */
				global $wp_filesystem;

				$content = $wp_filesystem->get_contents( $tmp_path );
				$lines   = explode( "\r\n", $content );

				if ( is_array( $lines ) && 0 < count( $lines ) ) {
					$i = 0;
					// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					if ( ! empty( $_POST['import_user_chk_header_first'] ) ) {
						$header_line = trim( $lines[0] ) . "\n";
						unset( $lines[0] );
					}
					// phpcs:enable

					foreach ( $lines as $originalLine ) {

						$line = trim( $originalLine );

						if ( MainWP_Utility::starts_with( $line, '#' ) ) {
							continue;
						}

						$items = str_getcsv( $line, ',' );

						if ( 3 > count( $items ) ) {
							continue;
						}

						$import_data = array(
							'user_login'    => trim( $items[0] ),
							'email'         => trim( $items[1] ),
							'first_name'    => trim( $items[2] ),
							'last_name'     => trim( $items[3] ),
							'url'           => trim( $items[4] ),
							'pass1'         => trim( $items[5] ),
							'send_password' => intval( $items[6] ),
							'role'          => trim( strtolower( $items[7] ) ),
							'select_sites'  => trim( $items[8] ),
							'select_groups' => trim( $items[9] ),
						);
						$encoded     = wp_json_encode( $import_data );
						?>
						<input type="hidden" id="user_import_csv_line_<?php echo intval( $i + 1 ); ?>" original-line="<?php echo esc_html( $line ); ?>" encoded-data="<?php echo esc_html( $encoded ); ?>" />
						<?php
						++$i;
					}
					$header_line = trim( $header_line );
					?>
					<div class="ui modal" id="mainwp-import-users-modal">
					<i class="close icon"></i>
						<div class="header"><?php esc_html_e( 'Importing new users and add them to your sites.', 'mainwp' ); ?></div>
						<div class="scrolling content">
							<?php
							/**
							 * Action: mainwp_import_users_modal_top
							 *
							 * Fires on the top of the Import Users modal.
							 *
							 * @since 4.1
							 */
							do_action( 'mainwp_import_users_modal_top' );
							?>
							<div id="MainWPBulkUploadUserLoading" style="display: none;"><i class="ui active inline loader tiny"></i> <?php esc_html_e( 'Importing Users', 'mainwp' ); ?></div>
							<input type="hidden" id="import_user_do_import" value="1"/>
							<input type="hidden" id="import_user_total_import" value="<?php echo esc_attr( $i ); ?>"/>
							<p>
								<div class="import_user_import_listing" id="import_user_import_logging">
									<pre class="log"><?php echo esc_html( $header_line ) . "\n"; ?></pre>
								</div>
							</p>
							<div id="import_user_import_failed_rows" style="display: none;">
								<span><?php echo esc_html( $header_line ); ?></span>
							</div>
							<?php
							/**
							 * Action: mainwp_import_users_modal_bottom
							 *
							 * Fires on the bottom of the Import Users modal.
							 *
							 * @since 4.1
							 */
							do_action( 'mainwp_import_users_modal_bottom' );
							?>
						</div>
						<div class="actions">
							<input type="button" name="import_user_btn_import" id="import_user_btn_import" class="ui basic button" value="<?php esc_attr_e( 'Pause', 'mainwp' ); ?>"/>
							<input type="button" name="import_user_btn_save_csv" id="import_user_btn_save_csv" disabled="disabled" class="ui basic green button" value="<?php esc_attr_e( 'Save failed', 'mainwp' ); ?>"/>
						</div>
					</div>
						<script type="text/javascript">
							jQuery( document ).ready( function () {
								jQuery( "#mainwp-import-users-modal" ).modal( {
									closable: false,
									onHide: function() {
										location.href = 'admin.php?page=BulkImportUsers';
									}
								} ).modal( 'show' );
							} );
						</script>

					<?php

				} else {
					$errors[] = esc_html__( 'Invalid data. Please make sure that the Import file has been formated properly.', 'mainwp' );
				}
			} else {
				$errors[] = esc_html__( 'File could not be uploaded. Temporary file cold not be created. Please make sure that the tmpfile() PHP function is enabled on your server.', 'mainwp' );
			}
		} else {
			$errors[] = esc_html__( 'File could not be uploaded. Please try again. If process keeps failing, please review MainWP Knowledgebase, and if you still have issues, please let us know in the MainWP Community.', 'mainwp' );
		}

		if ( 0 < count( $errors ) ) {
			?>
				<?php foreach ( $errors as $error ) { ?>
				<div class="ui error message"><?php echo esc_html( $error ); ?></div>
				<?php } ?>

			<a href="<?php echo esc_url( get_admin_url() ); ?>admin.php?page=UserBulkAdd" class="ui button"><?php esc_html_e( 'Add New', 'mainwp' ); ?></a>
			<a href="<?php echo esc_url( get_admin_url() ); ?>admin.php?page=mainwp_tab" class="ui button"><?php esc_html_e( 'Return to Overview', 'mainwp' ); ?></a>
			<?php
		}

		self::render_footer( 'Import' );
	}

	/**
	 * User Import $_POST handler.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::get_group_by_name()
	 * @uses \MainWP\Dashboard\MainWP_DB::query()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_websites_by_url()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_by_group_id()
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_DB::free_result()
	 * @uses \MainWP\Dashboard\MainWP_Bulk_Add::get_class_name()
	 * @uses \MainWP\Dashboard\MainWP_Utility::map_site()
	 */
	public static function do_import() { // phpcs:ignore -- Current complexity is required to achieve desired results. Pull request solutions appreciated.

		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$selected_sites  = ( isset( $_POST['select_sites'] ) && is_array( $_POST['select_sites'] ) ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['select_sites'] ) ) : array();
		$selected_groups = ( isset( $_POST['select_groups'] ) && is_array( $_POST['select_groups'] ) ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['select_groups'] ) ) : array();

		$data_fields   = MainWP_System_Utility::get_default_map_site_fields();
		$data_fields[] = 'users';

		$user_to_add = array(
			'user_pass'  => isset( $_POST['pass1'] ) ? wp_unslash( $_POST['pass1'] ) : '',
			'user_login' => isset( $_POST['user_login'] ) ? sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) : '',
			'user_url'   => isset( $_POST['url'] ) ? wp_unslash( $_POST['url'] ) : '',
			'user_email' => isset( $_POST['email'] ) ? sanitize_text_field( wp_unslash( $_POST['email'] ) ) : '',
			'first_name' => isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '',
			'last_name'  => isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '',
			'role'       => isset( $_POST['role'] ) ? sanitize_text_field( wp_unslash( $_POST['role'] ) ) : '',
		);

		$ret         = array();
		$dbwebsites  = array();
		$not_valid   = array();
		$error_sites = '';
		if ( isset( $_POST['select_by'] ) && 'site' === $_POST['select_by'] ) {
			foreach ( $selected_sites as $url ) {
				if ( ! empty( $url ) ) {
					$website = MainWP_DB::instance()->get_websites_by_url( $url );
					if ( $website ) {
						if ( '' !== $website->sync_errors || MainWP_System_Utility::is_suspended_site( $website ) ) {
							continue;
						}
						$dbwebsites[ $website[0]->id ] = MainWP_Utility::map_site(
							$website[0],
							$data_fields
						);
					} else {
						$not_valid[]  = esc_html__( 'Unexisting website. Please try again.', 'mainwp' ) . ' ' . $url;
						$error_sites .= $url . ';';
					}
				}
			}
		} else {
			foreach ( $selected_groups as $group ) {
				if ( MainWP_DB_Common::instance()->get_group_by_name( $group ) ) {
					$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_by_group_name( $group ) );
					if ( $websites ) {
						while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
							if ( '' !== $website->sync_errors || MainWP_System_Utility::is_suspended_site( $website ) ) {
								continue;
							}
							$dbwebsites[ $website->id ] = MainWP_Utility::map_site(
								$website,
								$data_fields
							);
						}
						MainWP_DB::free_result( $websites );
					} else {
						$not_valid[]  = esc_html__( 'No websites assigned to the selected group.', 'mainwp' ) . ' ' . $group;
						$error_sites .= $group . ';';
					}
				} else {
					$not_valid[]  = esc_html__( 'Unexisting group selected. Please try again.', 'mainwp' ) . ' ' . $group;
					$error_sites .= $group . ';';
				}
			}
		}

		if ( 0 < count( $dbwebsites ) ) {
			$post_data      = array(
				'new_user'      => base64_encode( wp_json_encode( $user_to_add ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
				'send_password' => ( isset( $_POST['send_password'] ) ? intval( $_POST['send_password'] ) : '' ),
			);
			$output         = new \stdClass();
			$output->ok     = array();
			$output->errors = array();

			/**
			* Action: mainwp_before_user_create
			*
			* Fires before user create.
			*
			* @since 4.1
			*/
			do_action( 'mainwp_before_user_create', $post_data, $dbwebsites );

			MainWP_Connect::fetch_urls_authed(
				$dbwebsites,
				'newuser',
				$post_data,
				array(
					MainWP_Bulk_Add::get_class_name(),
					'posting_bulk_handler',
				),
				$output
			);

			/**
			* Action: mainwp_after_user_create
			*
			* Fires after user create.
			*
			* @since 4.1
			*/
			do_action( 'mainwp_after_user_create', $output, $post_data, $dbwebsites );
		}

		$ret['ok_list']    = array();
		$ret['error_list'] = array();
		foreach ( $dbwebsites as $website ) {
			if ( isset( $output->ok[ $website->id ] ) && 1 === (int) $output->ok[ $website->id ] ) {
				$ret['ok_list'][] = 'New user(s) created: ' . esc_html( stripslashes( $website->name ) );
			} else {
				$ret['error_list'][] = esc_html( $output->errors[ $website->id ] . ' ' . stripslashes( $website->name ) );
				$error_sites        .= $website->url . ';';
			}
		}

		foreach ( $not_valid as $val ) {
			$ret['error_list'][] = $val;
		}

		$ret['failed_logging'] = '';
		if ( ! empty( $error_sites ) ) {
			$error_sites = rtrim( $error_sites, ';' );

			$user_login    = isset( $_POST['user_login'] ) ? sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) : '';
			$email         = isset( $_POST['email'] ) ? sanitize_text_field( wp_unslash( $_POST['email'] ) ) : '';
			$first_name    = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
			$last_name     = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
			$url           = isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( $_POST['url'] ) ) : '';
			$pass1         = isset( $_POST['pass1'] ) ? wp_unslash( $_POST['pass1'] ) : '';
			$send_password = isset( $_POST['send_password'] ) ? intval( $_POST['send_password'] ) : 0;
			$role          = isset( $_POST['role'] ) ? sanitize_text_field( wp_unslash( $_POST['role'] ) ) : '';

			$ret['failed_logging'] = esc_html( $user_login . ',' . $email . ',' . $first_name . ',' . $last_name . ',' . $url . ',' . $pass1 . ',' . $send_password . ',' . $role . ',' . $error_sites . ',' );
		}
		$ret['line_number'] = isset( $_POST['line_number'] ) ? intval( $_POST['line_number'] ) : 0;
		// phpcs:enable
		die( wp_json_encode( $ret ) );
	}

	/**
	 * Hooks the section help content to the Help Sidebar element.
	 */
	public static function mainwp_help_content() {
		if ( isset( $_GET['page'] ) && ( 'UserBulkManage' === $_GET['page'] || 'UserBulkAdd' === $_GET['page'] || 'UpdateAdminPasswords' === $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			?>
			<p><?php esc_html_e( 'If you need help with managing users, please review following help documents', 'mainwp' ); ?></p>
			<div class="ui relaxed bulleted list">
				<div class="item"><a href="https://kb.mainwp.com/docs/manage-users/" target="_blank">Manage Users</a> <i class="external alternate icon"></i></div>
				<div class="item"><a href="https://kb.mainwp.com/docs/create-a-new-user/" target="_blank">Create a New User</a> <i class="external alternate icon"></i></div>
				<div class="item"><a href="https://kb.mainwp.com/docs/edit-an-existing-user/" target="_blank">Edit an Existing User</a> <i class="external alternate icon"></i></div>
				<div class="item"><a href="https://kb.mainwp.com/docs/bulk-update-administrator-passwords/" target="_blank">Bulk Update Administrator Passwords</a> <i class="external alternate icon"></i></div>
				<div class="item"><a href="https://kb.mainwp.com/docs/delete-users/" target="_blank">Delete User(s)</a> <i class="external alternate icon"></i></div>
				<?php
				/**
				 * Action: mainwp_users_help_item
				 *
				 * Fires at the bottom of the help articles list in the Help sidebar on the Users page.
				 *
				 * Suggested HTML markup:
				 *
				 * <div class="item"><a href="Your custom URL">Your custom text</a></div>
				 *
				 * @since 4.1
				 */
				do_action( 'mainwp_users_help_item' );
				?>
			</div>
			<?php
		}
	}
}
