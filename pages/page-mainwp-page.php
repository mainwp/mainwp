<?php
/**
 * MainWP Page.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Page
 *
 * @package MainWP\Dashboard
 *
 * @uses MainWP_Bulk_Add
 */
class MainWP_Page {

	/**
	 * Method get_class_name()
	 *
	 * Get Class Name.
	 *
	 * @return object __CLASS__
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Subpages of Page.
	 *
	 * @var object $subPages
	 */
	public static $subPages;

	/**
	 * Pages to load.
	 *
	 * @var object $load_page
	 */
	public static $load_page;

	/**
	 * Method init()
	 *
	 * Initiate page.
	 *
	 * @return void
	 */
	public static function init() {
		/**
		 * This hook allows you to render the Page page header via the 'mainwp-pageheader-page' action.
		 *
		 * @link http://codex.mainwp.com/#mainwp-pageheader-page
		 *
		 * This hook is normally used in the same context of 'mainwp-getsubpages-page'
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-page
		 *
		 * @see \MainWP_Page::render_header
		 */
		add_action( 'mainwp-pageheader-page', array( self::get_class_name(), 'render_header' ) ); // @deprecated Use 'mainwp_pageheader_page' instead.
		add_action( 'mainwp_pageheader_page', array( self::get_class_name(), 'render_header' ) );

		/**
		 * This hook allows you to render the Page page footer via the 'mainwp-pagefooter-page' action.
		 *
		 * @link http://codex.mainwp.com/#mainwp-pagefooter-page
		 *
		 * This hook is normally used in the same context of 'mainwp-getsubpages-page'
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-page
		 *
		 * @see \MainWP_Page::render_footer
		 */
		add_action( 'mainwp-pagefooter-page', array( self::get_class_name(), 'render_footer' ) );
		add_action( 'mainwp_pagefooter_page', array( self::get_class_name(), 'render_footer' ) );

		add_action( 'mainwp_help_sidebar_content', array( self::get_class_name(), 'mainwp_help_content' ) ); // Hook the Help Sidebar content.
	}

	/**
	 * Method init_menu()
	 *
	 * Initiate Menu.
	 *
	 * @return void Initiated menus.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
	 */
	public static function init_menu() {
		$_page = add_submenu_page( 'mainwp_tab', esc_html__( 'Pages', 'mainwp' ), '<span id="mainwp-Pages">' . esc_html__( 'Pages', 'mainwp' ) . '</span>', 'read', 'PageBulkManage', array( self::get_class_name(), 'render' ) );
		add_action( 'load-' . $_page, array( self::get_class_name(), 'on_load_page' ) );
		add_filter( 'manage_' . $_page . '_columns', array( self::get_class_name(), 'get_manage_columns' ) );

		$_page = add_submenu_page( 'mainwp_tab', esc_html__( 'Pages', 'mainwp' ), '<div class="mainwp-hidden">' . esc_html__( 'Add New', 'mainwp' ) . '</div>', 'read', 'PageBulkAdd', array( self::get_class_name(), 'render_bulk_add' ) );
		add_action( 'load-' . $_page, array( self::get_class_name(), 'on_load_add_edit' ) );

		$_page = add_submenu_page( 'mainwp_tab', esc_html__( 'Pages', 'mainwp' ), '<div class="mainwp-hidden">' . esc_html__( 'Edit Page', 'mainwp' ) . '</div>', 'read', 'PageBulkEdit', array( self::get_class_name(), 'render_bulk_edit' ) );
		add_action( 'load-' . $_page, array( self::get_class_name(), 'on_load_add_edit' ) );

		add_submenu_page( 'mainwp_tab', esc_html__( 'Posting new bulkpage', 'mainwp' ), '<div class="mainwp-hidden">' . esc_html__( 'Add New Page', 'mainwp' ) . '</div>', 'read', 'PostingBulkPage', array( self::get_class_name(), 'posting' ) ); // removed from menu afterwards.

		/**
		 * Pages Subpages
		 *
		 * Filters subpages for the Pages page.
		 *
		 * @since Unknown
		 */
		$sub_pages      = array();
		$sub_pages      = apply_filters_deprecated( 'mainwp-getsubpages-page', array( $sub_pages ), '4.0.7.2', 'mainwp_getsubpages_page' );  // @deprecated Use 'mainwp_getsubpages_page' instead.
		self::$subPages = apply_filters( 'mainwp_getsubpages_page', $sub_pages );

		if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
			foreach ( self::$subPages as $subPage ) {
				if ( isset( $subPage['no_page'] ) && $subPage['no_page'] ) {
					continue;
				}
				if ( MainWP_Menu::is_disable_menu_item( 3, 'Page' . $subPage['slug'] ) ) {
					continue;
				}
				add_submenu_page( 'mainwp_tab', $subPage['title'], '<div class="mainwp-hidden">' . $subPage['title'] . '</div>', 'read', 'Page' . $subPage['slug'], $subPage['callback'] );
			}
		}
		self::init_left_menu( self::$subPages );
	}

	/**
	 * Method on_load_add_edit()
	 *
	 * Add edit on load bulk posts.
	 *
	 * @return void Returns post to edit.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Post::on_load_bulkpost()
	 */
	public static function on_load_add_edit() {

		global $_mainwp_menu_active_slugs;

		if ( isset( $_GET['page'] ) && 'PageBulkAdd' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			/**
			 * MainWP default post to edit.
			 *
			 * @global string
			 */
			global $_mainwp_default_post_to_edit;

			$post_type                    = 'bulkpage';
			$_mainwp_default_post_to_edit = get_default_post_to_edit( $post_type, true );
			$post_id                      = $_mainwp_default_post_to_edit ? $_mainwp_default_post_to_edit->ID : 0;
		} else {
			$post_id                                   = isset( $_GET['post_id'] ) ? intval( $_GET['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$_mainwp_menu_active_slugs['PageBulkEdit'] = 'PageBulkManage'; // to fix hidden second menu level.
		}

		if ( ! $post_id ) {
			wp_die( esc_html__( 'Invalid post.' ) );
		}

		MainWP_Post::on_load_bulkpost( $post_id );
	}

	/**
	 * Method init_subpages_menu()
	 *
	 * Initiate subpages menu.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
	 */
	public static function init_subpages_menu() {
		?>
		<div id="menu-mainwp-Pages" class="mainwp-submenu-wrapper">
			<div class="wp-submenu sub-open" style="">
				<div class="mainwp_boxout">
					<div class="mainwp_boxoutin"></div>
					<?php if ( mainwp_current_user_have_right( 'dashboard', 'manage_pages' ) ) { ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=PageBulkManage' ) ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Manage Pages', 'mainwp' ); ?></a>
						<?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'PageBulkAdd' ) ) { ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=PageBulkAdd' ) ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Add New', 'mainwp' ); ?></a>
						<?php } ?>
					<?php } ?>
					<?php
					if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
						foreach ( self::$subPages as $subPage ) {
							if ( ! isset( $subPage['menu_hidden'] ) || ( isset( $subPage['menu_hidden'] ) && true !== $subPage['menu_hidden'] ) ) {
								if ( MainWP_Menu::is_disable_menu_item( 3, 'Page' . $subPage['slug'] ) ) {
									continue;
								}
								?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=Page' . $subPage['slug'] ) ); ?>" class="mainwp-submenu"><?php echo esc_html( $subPage['title'] ); ?></a>
								<?php
							}
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
	 * Initiate left menu.
	 *
	 * @param array $subPages Left menu sub pages.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Menu::add_left_menu()
	 * @uses \MainWP\Dashboard\MainWP_Menu::init_subpages_left_menu()
	 * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
	 */
	public static function init_left_menu( $subPages = array() ) {

		MainWP_Menu::add_left_menu(
			array(
				'title'         => esc_html__( 'Pages', 'mainwp' ),
				'parent_key'    => 'managesites',
				'slug'          => 'PageBulkManage',
				'href'          => 'admin.php?page=PageBulkManage',
				'icon'          => '<i class="file icon"></i>',
				'leftsub_order' => 8,
			),
			1
		);
		$init_sub_subleftmenu = array(
			array(
				'title'                => esc_html__( 'Manage Pages', 'mainwp' ),
				'parent_key'           => 'PageBulkManage',
				'href'                 => 'admin.php?page=PageBulkManage',
				'slug'                 => 'PageBulkManage',
				'right'                => 'manage_pages',
				'leftsub_order_level2' => 1,
			),
			array(
				'title'                => esc_html__( 'Add New', 'mainwp' ),
				'parent_key'           => 'PageBulkManage',
				'href'                 => 'admin.php?page=PageBulkAdd',
				'slug'                 => 'PageBulkAdd',
				'right'                => 'manage_pages',
				'leftsub_order_level2' => 2,
			),
		);
		MainWP_Menu::init_subpages_left_menu( $subPages, $init_sub_subleftmenu, 'PageBulkManage', 'Page' );

		foreach ( $init_sub_subleftmenu as $item ) {
			if ( MainWP_Menu::is_disable_menu_item( 3, $item['slug'] ) ) {
				continue;
			}
			MainWP_Menu::add_left_menu( $item, 2 );
		}
	}

	/**
	 * Method on_load_page()
	 *
	 * On page load.
	 */
	public static function on_load_page() {
		add_action( 'admin_head', array( self::get_class_name(), 'admin_head' ) );
		add_filter( 'hidden_columns', array( self::get_class_name(), 'get_hidden_columns' ), 10, 3 );
		add_action( 'mainwp_screen_options_modal_bottom', array( self::get_class_name(), 'hook_screen_options_modal_bottom' ), 10, 2 );
	}

	/**
	 * Method get_manage_columns()
	 *
	 * Get columns to display.
	 *
	 * @return array $colums Columns to display.
	 *
	 * @uses  \MainWP\Dashboard\MainWP_Utility::enabled_wp_seo()
	 */
	public static function get_manage_columns() {
		$colums = array(
			'title'           => 'Title',
			'author'          => 'Author',
			'comments'        => 'Comments',
			'date'            => 'Date',
			'status'          => 'Status',
			'seo-links'       => 'Links',
			'seo-linked'      => 'Linked',
			'seo-score'       => 'SEO Score',
			'seo-readability' => 'Readability score',
			'website'         => 'Website',
		);

		if ( ! MainWP_Utility::enabled_wp_seo() ) {
			unset( $colums['seo-links'] );
			unset( $colums['seo-linked'] );
			unset( $colums['seo-score'] );
			unset( $colums['seo-readability'] );
		}
		return $colums;
	}

	/**
	 * Method admin_head()
	 *
	 * Add current screen ID to html header.
	 */
	public static function admin_head() {

		/**
		 * Current screen.
		 *
		 * @global string
		 */
		global $current_screen;

		?>
		<script type="text/javascript"> pagenow = '<?php echo esc_js( strtolower( $current_screen->id ) ); ?>';</script>
		<?php
	}

	/**
	 * Method get_hidden_columns()
	 *
	 * Get hidden columns.
	 *
	 * @param mixed $hidden Columns that are hidden.
	 * @param mixed $screen Current page.
	 *
	 * @return string $hidden Hidden columns.
	 */
	public static function get_hidden_columns( $hidden, $screen ) {
		if ( $screen && 'mainwp_page_PageBulkManage' === $screen->id ) {
			$hidden = get_user_option( 'manage' . strtolower( $screen->id ) . 'columnshidden' );
		}
		if ( ! is_array( $hidden ) ) {
			$hidden = array();
		}
		return $hidden;
	}

	/**
	 * Method hook_screen_options_modal_bottom()
	 *
	 * Render screen options modal bottom.
	 */
	public static function hook_screen_options_modal_bottom() {
		$page = isset( $_GET['page'] ) ? wp_unslash( $_GET['page'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( 'PageBulkManage' === $page ) {

			$show_columns = get_user_option( 'mainwp_managepages_show_columns' );

			if ( ! is_array( $show_columns ) ) {
				$show_columns = array();
			}

			$cols = self::get_manage_columns();

			MainWP_UI::render_showhide_columns_settings( $cols, $show_columns, 'page' );
		}
	}

	/**
	 * Method render_header()
	 *
	 * Render page header.
	 *
	 * @param string $shownPage Current page.
	 * @param int    $post_id Post ID.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_top_header()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_page_navigation()
	 */
	public static function render_header( $shownPage = '', $post_id = null ) {

		$params = array(
			'title' => esc_html__( 'Pages', 'mainwp' ),
		);
		MainWP_UI::render_top_header( $params );

		$renderItems = array();

		if ( mainwp_current_user_have_right( 'dashboard', 'manage_pages' ) ) {
			$renderItems[] = array(
				'title'  => esc_html__( 'Manage Pages', 'mainwp' ),
				'href'   => 'admin.php?page=PageBulkManage',
				'active' => ( 'BulkManage' === $shownPage ) ? true : false,
			);
			if ( 'BulkEdit' === $shownPage ) {
				$renderItems[] = array(
					'title'  => esc_html__( 'Edit Page', 'mainwp' ),
					'href'   => 'admin.php?page=PageBulkEdit&post_id=' . esc_attr( $post_id ),
					'active' => true,
				);
			}

			if ( ! MainWP_Menu::is_disable_menu_item( 3, 'PageBulkAdd' ) ) {
				$renderItems[] = array(
					'title'  => esc_html__( 'Add New', 'mainwp' ),
					'href'   => 'admin.php?page=PageBulkAdd',
					'active' => ( 'BulkAdd' === $shownPage ) ? true : false,
				);
			}
		}

		if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
			foreach ( self::$subPages as $subPage ) {
				if ( MainWP_Menu::is_disable_menu_item( 3, 'Page' . $subPage['slug'] ) ) {
					continue;
				}

				if ( isset( $subPage['tab_link_hidden'] ) && true === $subPage['tab_link_hidden'] ) {
					$tab_link = '#';
				} else {
					$tab_link = 'admin.php?page=Page' . $subPage['slug'];
				}
				$item           = array();
				$item['title']  = $subPage['title'];
				$item['href']   = $tab_link;
				$item['active'] = ( $subPage['slug'] === $shownPage ) ? true : false;
				$renderItems[]  = $item;
			}
		}

		MainWP_UI::render_page_navigation( $renderItems, __CLASS__ );
	}

	/**
	 * Method render_footer()
	 *
	 * Render page footer.
	 */
	public static function render_footer() {
		echo '</div>';
	}

	/**
	 * Renders Bulk Page Manager.
	 *
	 * @return void
	 *
	 * @uses \MainWP\Dashboard\MainWP_Cache::get_cached_context()
	 */
	public static function render() {
		if ( ! mainwp_current_user_have_right( 'dashboard', 'manage_pages' ) ) {
			mainwp_do_not_have_permissions( esc_html__( 'manage pages', 'mainwp' ) );
			return;
		}

		$cachedSearch = MainWP_Cache::get_cached_context( 'Page' );

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

		?>
		<?php self::render_header( 'BulkManage' ); ?>

		<div id="mainwp-manage-pages"  class="ui alt segment">
			<div class="mainwp-main-content">
				<div class="mainwp-actions-bar ui mini form">
					<div class="ui grid">
						<div class="ui two column row">
							<div class="column">
								<select class="ui dropdown" id="mainwp-bulk-actions">
									<option value="none"><?php esc_html_e( 'Bulk Actions', 'mainwp' ); ?></option>
									<?php do_action( 'mainwp_manage_pages_bulk_action' ); ?>	
									<option value="trash"><?php esc_html_e( 'Move to trash', 'mainwp' ); ?></option>
									<option value="restore"><?php esc_html_e( 'Restore', 'mainwp' ); ?></option>
									<option value="delete"><?php esc_html_e( 'Delete permanently', 'mainwp' ); ?></option>
									<?php
									/**
									 * Action: mainwp_pages_bulk_action
									 *
									 * Adds new action to the Bulk Actions menu on Manage Pages.
									 *
									 * Suggested HTML Markup:
									 * <option value="Your custom value">Your custom text</option>
									 *
									 * @since 4.1
									 */
									do_action( 'mainwp_pages_bulk_action' );
									?>
								</select>
								<button class="ui mini button" id="mainwp-do-pages-bulk-actions"><?php esc_html_e( 'Apply', 'mainwp' ); ?></button>
								<?php
								/**
								 * Action: mainwp_pages_actions_bar_left
								 *
								 * Fires at the left side of the actions bar on the Pages screen, after the Bulk Actions menu.
								 *
								 * @since 4.0
								 */
								do_action( 'mainwp_pages_actions_bar_left' );
								?>
							</div>
							<div class="right aligned column">
								<?php
								/**
								 * Action: mainwp_pages_actions_bar_right
								 *
								 * Fires at the right side of the actions bar on the Pages screen.
								 *
								 * @since 4.0
								 */
								do_action( 'mainwp_pages_actions_bar_right' );
								?>
							</div>
						</div>
					</div>
				</div>
				<div class="ui segment" id="mainwp_pages_wrap_table">
					<?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-manage-pages-info-message' ) ) : ?>
						<div class="ui info message">
							<i class="close icon mainwp-notice-dismiss" notice-id="mainwp-manage-pages-info-message"></i>
							<?php printf( esc_html__( 'Manage existing pages on your child sites.  Here you can edit, view and delete pages.  For additional help, please check this %1$shelp documentation%2$s.', 'mainwp' ), '<a href="https://kb.mainwp.com/docs/manage-pages/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); ?>
						</div>
					<?php endif; ?>
					<?php self::render_table( true ); ?>
				</div>
			</div>
			<div class="mainwp-side-content mainwp-no-padding">
				<?php
				/**
				 * Action: mainwp_manage_pages_sidebar_top
				 *
				 * Fires at the top of the sidebar on Manage pages.
				 *
				 * @since 4.1
				 */
				do_action( 'mainwp_manage_pages_sidebar_top' );
				?>
				<div class="mainwp-select-sites ui accordion mainwp-sidebar-accordion">
					<?php
					/**
					 * Action: mainwp_manage_pages_before_select_sites
					 *
					 * Fires before the Select Sites section on Manage pages.
					 *
					 * @since 4.1
					 */
					do_action( 'mainwp_manage_pages_before_select_sites' );
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
					 * Action: mainwp_manage_pages_after_select_sites
					 *
					 * Fires after the Select Sites section on Manage pages.
					 *
					 * @since 4.1
					 */
					do_action( 'mainwp_manage_pages_after_select_sites' );
					?>
				</div>
				<div class="ui fitted divider"></div>
				<div class="mainwp-search-options ui accordion mainwp-sidebar-accordion">
					<div class="title active"><i class="dropdown icon"></i> <?php esc_html_e( 'Select Status', 'mainwp' ); ?></div>
					<div class="content active">
					<?php
					/**
					 * Action: mainwp_manage_pages_before_search_options
					 *
					 * Fires before the Search Options on Manage Pages.
					 *
					 * @since 4.1
					 */
					do_action( 'mainwp_manage_pages_before_search_options' );
					?>
					<div class="ui mini form">
						<div class="field">
							<select multiple="" class="ui fluid dropdown" id="mainwp_page_search_type">
								<option value=""><?php esc_html_e( 'Select status', 'mainwp' ); ?></option>
								<option value="publish" selected><?php esc_html_e( 'Published', 'mainwp' ); ?></option>
								<option value="pending"><?php esc_html_e( 'Pending', 'mainwp' ); ?></option>
								<option value="private"><?php esc_html_e( 'Private', 'mainwp' ); ?></option>
								<option value="future"><?php esc_html_e( 'Scheduled', 'mainwp' ); ?></option>
								<option value="draft"><?php esc_html_e( 'Draft', 'mainwp' ); ?></option>
								<option value="trash"><?php esc_html_e( 'Trash', 'mainwp' ); ?></option>
							</select>
						</div>
					</div>
				</div>
				</div>
				<div class="ui fitted divider"></div>
				<div class="mainwp-search-options ui accordion mainwp-sidebar-accordion">
					<div class="title active"><i class="dropdown icon"></i> <?php esc_html_e( 'Search Options', 'mainwp' ); ?></div>
					<div class="content active">
					<?php self::render_search_options(); ?>
					<?php
					/**
					 * Action: mainwp_manage_pages_after_search_options
					 *
					 * Fires after the Search Options on Manage Pages.
					 *
					 * @since 4.1
					 */
					do_action( 'mainwp_manage_pages_after_search_options' );
					?>
				</div>
				</div>
				<div class="ui fitted divider"></div>
				<div class="mainwp-search-submit">
					<?php
					/**
					 * Action: mainwp_manage_pages_before_submit_button
					 *
					 * Fires before the Submit Button on Manage Pages.
					 *
					 * @since 4.1
					 */
					do_action( 'mainwp_manage_pages_before_submit_button' );
					$is_demo = MainWP_Demo_Handle::is_demo_mode();
					if ( $is_demo ) {
						MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<input type="button" class="ui green big fluid button disabled" disabled="disabled" value="' . esc_attr__( 'Show Pages', 'mainwp' ) . '"/>' );
					} else {
						?>
						<input type="button" name="mainwp_show_pages" id="mainwp_show_pages" class="ui green big fluid button" value="<?php esc_attr_e( 'Show Pages', 'mainwp' ); ?>"/>
						<?php
					}
					/**
					 * Action: mainwp_manage_pages_after_submit_button
					 *
					 * Fires after the Submit Button on Manage Pages.
					 *
					 * @since 4.1
					 */
					do_action( 'mainwp_manage_pages_after_submit_button' );
					?>
				</div>
				<?php
				/**
				 * Action: mainwp_manage_pages_sidebar_bottom
				 *
				 * Fires at the bottom of the sidebar on Manage pages.
				 *
				 * @since 4.1
				 */
				do_action( 'mainwp_manage_pages_sidebar_bottom' );
				?>
			</div>
			<div class="ui hidden clearing divider"></div>
		</div>
		<?php
	}

	/**
	 * Method render_search_options()
	 *
	 * Render search options box.
	 *
	 * @return void Output the page search options box
	 *
	 * @uses \MainWP\Dashboard\MainWP_Cache::get_cached_context()
	 */
	public static function render_search_options() {
		$cachedSearch = MainWP_Cache::get_cached_context( 'Page' );
		$statuses     = isset( $cachedSearch['status'] ) ? $cachedSearch['status'] : array();
		if ( $cachedSearch && isset( $cachedSearch['keyword'] ) ) {
			$cachedSearch['keyword'] = trim( $cachedSearch['keyword'] );
		}
		?>
		<div class="ui mini form">
			<div class="field">
				<div class="ui input fluid">
					<input type="text" placeholder="<?php esc_attr_e( 'Containing keyword', 'mainwp' ); ?>" id="mainwp_page_search_by_keyword" class="text" value="<?php echo ( null !== $cachedSearch ) ? esc_attr( $cachedSearch['keyword'] ) : ''; ?>" />
				</div>
			</div>
			<div class="field">
				<?php
				$searchon = 'all';
				if ( null !== $cachedSearch ) {
					$searchon = $cachedSearch['search_on'];
				}
				?>
				<select class="ui dropdown fluid" id="mainwp_page_search_on">
					<option value=""><?php esc_html_e( 'Search in...', 'mainwp' ); ?></option>
					<option value="title" <?php echo 'title' === $searchon ? 'selected' : ''; ?>><?php esc_html_e( 'Title', 'mainwp' ); ?></option>
					<option value="content" <?php echo 'content' === $searchon ? 'selected' : ''; ?>><?php esc_html_e( 'Body', 'mainwp' ); ?></option>
					<option value="all" <?php echo 'all' === $searchon ? 'selected' : ''; ?>><?php esc_html_e( 'Title and Body', 'mainwp' ); ?></option>
				</select>
			</div>
			<div class="field">
				<label><?php esc_html_e( 'Date range', 'mainwp' ); ?></label>
				<div class="two fields">
					<div class="field">
						<div class="ui calendar mainwp_datepicker" >
							<div class="ui input left icon">
								<i class="calendar icon"></i>
								<input type="text" placeholder="Date" autocomplete="off" id="mainwp_page_search_by_dtsstart" value="
									<?php
									if ( null !== $cachedSearch ) {
										echo esc_attr( $cachedSearch['dtsstart'] );
									}
									?>
								"/>
							</div>
						</div>
					</div>
					<div class="field">
						<div class="ui calendar mainwp_datepicker" >
							<div class="ui input left icon">
								<i class="calendar icon"></i>
								<input type="text" placeholder="Date" autocomplete="off" id="mainwp_page_search_by_dtsstop" value="
								<?php
								if ( null !== $cachedSearch ) {
									echo esc_attr( $cachedSearch['dtsstop'] );
								}
								?>
								"/>
							</div>
						</div>
					</div>
				</div>
			</div>
			<div class="field">
				<label><?php esc_html_e( 'Max pages to return', 'mainwp' ); ?></label>
				<input type="text" name="mainwp_maximumPages"  id="mainwp_maximumPages" value="<?php echo( ( false === get_option( 'mainwp_maximumPages' ) ) ? 50 : esc_attr( get_option( 'mainwp_maximumPages' ) ) ); ?>"/>
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
					jQuery( '#mainwp_page_search_type' ).dropdown( 'set selected',[<?php echo $status; //phpcs:ignore -- safe output. ?>] );
				} );
			</script>
			<?php
		}
	}

	/**
	 * Method render_table()
	 *
	 * Render Manage page table.
	 *
	 * @param mixed  $cached Cached search body.
	 * @param string $keyword Search keywords.
	 * @param string $dtsstart Date & time of Session start time.
	 * @param string $dtsstop Date & time of Session stop time.
	 * @param string $status Page status.
	 * @param string $groups Groups to display.
	 * @param string $sites Site URLS.
	 * @param string $search_on Site on all sites. Default = all.
	 * @param mixed  $clients Selected Clients.
	 *
	 * @return void Page table html.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Cache::echo_body()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::enabled_wp_seo()
	 */
	public static function render_table( $cached, $keyword = '', $dtsstart = '', $dtsstop = '', $status = '', $groups = '', $sites = '', $search_on = 'all', $clients = '' ) {
		?>
		<div id="mainwp_pages_error"></div>
		<div id="mainwp-loading-pages-row" style="display: none;">
			<div class="ui active inverted dimmer">
				<div class="ui indeterminate large text loader"><?php esc_html_e( 'Loading Pages...', 'mainwp' ); ?></div>
			</div>
		</div>
		<?php
		/**
		 * Action: mainwp_before_pages_table
		 *
		 * Fires before the Manage Pages table.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_before_pages_table' );
		?>
		<table id="mainwp-pages-table" class="ui unstackable single line table" style="width:100%">
			<thead>
				<tr>
					<th  class="no-sort check-column collapsing"><span class="ui checkbox"><input id="cb-select-all-top" type="checkbox" /></span></th>
					<?php
					/**
					 * Action: mainwp_pages_table_header
					 *
					 * Adds new column header to the Manage pages table.
					 *
					 *  @since 4.1
					 */
					do_action( 'mainwp_pages_table_header' );
					?>
					<th id="title"><?php esc_html_e( 'Title', 'mainwp' ); ?></th>
					<th id="author" class="min-tablet"><?php esc_html_e( 'Author', 'mainwp' ); ?></th>
					<th id="comments"><i class="comment icon"></i></th>
					<th id="date" class="min-tablet"><?php esc_html_e( 'Last Modified', 'mainwp' ); ?></th>
					<th id="status"><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
					<?php if ( MainWP_Utility::enabled_wp_seo() ) : ?>
					<th id="seo-links"><span title="<?php echo esc_attr__( 'Number of internal links in this page', 'mainwp' ); ?>"><?php esc_html_e( 'Links', 'mainwp' ); ?></span></th>
					<th id="seo-linked"><span title="<?php echo esc_attr__( 'Number of internal links linking to this page', 'mainwp' ); ?>"><?php esc_html_e( 'Linked', 'mainwp' ); ?></span></th>
					<th id="seo-score"><span title="<?php echo esc_attr__( 'SEO score', 'mainwp' ); ?>"><?php esc_html_e( 'SEO score', 'mainwp' ); ?></span></th>
					<th id="seo-readability"><span title="<?php echo esc_attr__( 'Readability score', 'mainwp' ); ?>"><?php esc_html_e( 'Readability score', 'mainwp' ); ?></span></th>
					<?php endif; ?>
					<th id="website" class="min-tablet"><?php esc_html_e( 'Website', 'mainwp' ); ?></th>
					<th id="pages-actions" class="no-sort min-tablet"></th>
				</tr>
			</thead>
			<tbody id="mainwp-posts-list">
				<?php
				if ( $cached ) {
					MainWP_Cache::echo_body( 'Page' );
				} else {
					self::render_table_body( $keyword, $dtsstart, $dtsstop, $status, $groups, $sites, $search_on, $clients );
				}
				?>
			</tbody>
		</table>
		<?php
		/**
		 * Action: mainwp_after_pages_table
		 *
		 * Fires after the Manage Pages table.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_after_pages_table' );

		$table_features = array(
			'searching'  => 'true',
			'paging'     => 'true',
			'info'       => 'true',
			'stateSave'  => 'true',
			'scrollX'    => 'true',
			'colReorder' => '{ fixedColumnsLeft: 1, fixedColumnsRight: 1 }',
			'order'      => '[]',
			'responsive' => 'true',
		);

		/**
		 * Filter: mainwp_pages_table_fatures
		 *
		 * Filters the Manage Pages table features.
		 *
		 * @since 4.1
		 */
		$table_features = apply_filters( 'mainwp_pages_table_fatures', $table_features );
		?>
		<script type="text/javascript">
		var responsive = <?php echo esc_html( $table_features['responsive'] ); ?>;
		if( jQuery( window ).width() > 1140 ) {
			responsive = false;
		}
		jQuery( document ).ready( function () {
			try {
				jQuery("#mainwp-pages-table").DataTable().destroy(); // fixed re-initialize datatable issue.
				$manage_pages_table = jQuery( '#mainwp-pages-table' ).DataTable( {
					"responsive" : responsive,
					"searching" : <?php echo esc_html( $table_features['searching'] ); ?>,
					"colReorder" : <?php echo esc_html( $table_features['colReorder'] ); ?>,
					"stateSave":  <?php echo esc_html( $table_features['stateSave'] ); ?>,
					"paging": <?php echo esc_html( $table_features['paging'] ); ?>,
					"info": <?php echo esc_html( $table_features['info'] ); ?>,
					"order": <?php echo esc_html( $table_features['order'] ); ?>,
					"scrollX" : <?php echo esc_html( $table_features['scrollX'] ); ?>,
					"lengthMenu": [ [10, 25, 50, 100, -1], [10, 25, 50, 100, "All"] ],
					"columnDefs": [ {
						"targets": 'no-sort',
						"orderable": false
					} ],
					"language" : { "emptyTable": "<?php esc_html_e( 'Use the search options to find the page you want to manage.', 'mainwp' ); ?>" },
					"preDrawCallback": function( settings ) {
						jQuery( '#mainwp_pages_wrap_table table .ui.dropdown' ).dropdown();
						jQuery( '#mainwp_pages_wrap_table table .ui.checkbox' ).checkbox();
						mainwp_datatable_fix_menu_overflow();
						mainwp_table_check_columns_init(); // ajax: to fix checkbox all.
					}
				} );
			} catch( err ) {
				// to fix js error.
			}

			_init_manage_sites_screen = function() {
				jQuery( '#mainwp-overview-screen-options-modal input[type=checkbox][id^="mainwp_show_column_"]' ).each( function() {
					var col_id = jQuery( this ).attr( 'id' );
					col_id = col_id.replace( "mainwp_show_column_", "" );
					try {	
						$manage_pages_table.column( '#' + col_id ).visible( jQuery(this).is( ':checked' ) );
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
	 * Method render_table_body()
	 *
	 * Render table body.
	 *
	 * @param mixed  $keyword Search keywords.
	 * @param mixed  $dtsstart Date & time of Session start.
	 * @param mixed  $dtsstop Date & time of Session stop.
	 * @param mixed  $status Page statuses.
	 * @param mixed  $groups Groups to display.
	 * @param mixed  $sites Site URLS.
	 * @param string $search_on Site on all sites. Default = all.
	 * @param mixed  $clients Selected Clients.
	 *
	 * @return void Output table body.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Cache::init_cache()
	 * @uses \MainWP\Dashboard\MainWP_Cache::add_context()
	 * @uses \MainWP\Dashboard\MainWP_Cache::add_body()
	 * @uses \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
	 * @uses \MainWP\Dashboard\MainWP_DB::query()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_by_group_id()
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_DB::free_result()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::ctype_digit()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::map_site()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::enabled_wp_seo()
	 */
	public static function render_table_body( $keyword, $dtsstart, $dtsstop, $status, $groups, $sites, $search_on = 'all', $clients = '' ) { // phpcs:ignore -- complex function.

		MainWP_Cache::init_cache( 'Page' );

		$data_fields = MainWP_System_Utility::get_default_map_site_fields();

		$dbwebsites = array();
		if ( ! empty( $sites ) ) {
			foreach ( $sites as $k => $v ) {
				if ( MainWP_Utility::ctype_digit( $v ) ) {
					$website = MainWP_DB::instance()->get_website_by_id( $v );
					if ( empty( $website->sync_errors ) && ! MainWP_System_Utility::is_suspended_site( $website ) ) {
						$dbwebsites[ $website->id ] = MainWP_Utility::map_site( $website, $data_fields );
					}
				}
			}
		}
		if ( ! empty( $groups ) ) {
			foreach ( $groups as $k => $v ) {
				if ( MainWP_Utility::ctype_digit( $v ) ) {
					$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_by_group_id( $v ) );
					while ( $websites && ( $website   = MainWP_DB::fetch_object( $websites ) ) ) {
						if ( '' !== $website->sync_errors || MainWP_System_Utility::is_suspended_site( $website ) ) {
							continue;
						}
						$dbwebsites[ $website->id ] = MainWP_Utility::map_site( $website, $data_fields );
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

		$output         = new \stdClass();
		$output->errors = array();
		$output->pages  = 0;

		if ( 0 < count( $dbwebsites ) ) {
			$post_data = array(
				'keyword'    => $keyword,
				'dtsstart'   => $dtsstart,
				'dtsstop'    => $dtsstop,
				'status'     => $status,
				'maxRecords' => ( ( false === get_option( 'mainwp_maximumPages' ) ) ? 50 : get_option( 'mainwp_maximumPages' ) ),
				'search_on'  => $search_on,
			);

			if ( MainWP_Utility::enabled_wp_seo() ) {
				$post_data['WPSEOEnabled'] = 1;
			}

			/**
			 * Get all pages data
			 *
			 * Set search parameters for the fetch process.
			 *
			 * @since 3.4
			 */
			$post_data = apply_filters( 'mainwp_get_all_pages_data', $post_data );
			MainWP_Connect::fetch_urls_authed( $dbwebsites, 'get_all_pages', $post_data, array( self::get_class_name(), 'pages_search_handler' ), $output );
		}

		MainWP_Cache::add_context(
			'Page',
			array(
				'count'     => $output->pages,
				'keyword'   => $keyword,
				'dtsstart'  => $dtsstart,
				'dtsstop'   => $dtsstop,
				'status'    => $status,
				'sites'     => ( ! empty( $sites ) ) ? $sites : '',
				'groups'    => ( ! empty( $groups ) ) ? $groups : '',
				'search_on' => $search_on,
			)
		);

		if ( empty( $output->pages ) ) {
			MainWP_Cache::add_body( 'Page', '' );
			return;
		}
	}

	/**
	 * Method get_status()
	 *
	 * Get page status. If the page status is
	 * 'publish' change it to 'Published'.
	 *
	 * @param mixed $status Page Status.
	 *
	 * @return string Page status.
	 */
	private static function get_status( $status ) {
		if ( 'publish' === $status ) {
			return 'Published';
		}
		return esc_html( ucfirst( $status ) );
	}


	/**
	 * Method pages_search_handler()
	 *
	 * Pages Search handler.
	 *
	 * @param mixed $data Search data.
	 * @param mixed $website Child Site ID to search on.
	 * @param mixed $output Search output.
	 *
	 * @return void Search box html.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Cache::add_body()
	 * @uses \MainWP\Dashboard\MainWP_Error_Helper::get_error_message()
	 * @uses \MainWP\Dashboard\MainWP_Exception
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_child_response()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::format_timestamp()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::get_timestamp()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::enabled_wp_seo()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::esc_content()
	 */
	public static function pages_search_handler( $data, $website, &$output ) { // phpcs:ignore -- complex function.
		if ( MainWP_Demo_Handle::get_instance()->is_demo_website( $website ) ) {
			return;
		}
		if ( preg_match( '/<mainwp>(.*)<\/mainwp>/', $data, $results ) > 0 ) {
			$result = $results[1];
			$pages  = MainWP_System_Utility::get_child_response( base64_decode( $result ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.

			if ( is_array( $pages ) && isset( $pages['error'] ) ) {
				$output->errors[ $website->id ] = esc_html( $pages['error'] );
				return;
			}

			unset( $results );
			foreach ( $pages as $page ) {
				$raw_dts = '';
				if ( isset( $page['dts'] ) ) {
					$raw_dts = $page['dts'];
					if ( ! stristr( $page['dts'], '-' ) ) {
						$page['dts'] = MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $page['dts'] ) );
					}
				}

				if ( ! isset( $page['title'] ) || ( '' === $page['title'] ) ) {
					$page['title'] = '(No Title)';
				}
				ob_start();
				?>
				<tr>
					<td  class="check-column"><span class="ui checkbox"><input type="checkbox" name="page[]" value="1"></span></td>
					<?php
					/**
					 * Action: mainwp_pages_table_column
					 *
					 * Adds a new column item in the Manage pages table.
					 *
					 * @param array $page    Array containing the page data.
					 * @param array $website Object containing the website data.
					 *
					 * @since 4.1
					 */
					do_action( 'mainwp_pages_table_column', $page, $website );
					?>
					<td class="page-title  column-title">
						<strong>
							<abbr title="<?php echo esc_html( $page['title'] ); ?>">
								<?php if ( 'trash' !== $page['status'] ) { ?>
									<a class="row-title" href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo esc_attr( $website->id ); ?>&location=<?php echo esc_attr( base64_encode( 'post.php?post=' . $page['id'] . '&action=edit' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible. ?>&_opennonce=<?php echo esc_html( wp_create_nonce( 'mainwp-admin-nonce' ) ); ?>" target="_blank" title="Edit '<?php echo esc_html( $page['title'] ); ?>'?"><?php echo esc_html( $page['title'] ); ?></a>
								<?php } else { ?>
									<?php echo esc_html( $page['title'] ); ?>
								<?php } ?>
							</abbr>
						</strong>

					</td>
					<td class="author column-author">
						<?php echo esc_html( $page['author'] ); ?>
					</td>
					<td class="comments">
						<div class="page-com-count-wrapper">
							<a href="#" title="0 pending" class="post-com-count">
								<span class="comment-count"><abbr title="<?php echo esc_attr( $page['comment_count'] ); ?>"><?php echo esc_html( $page['comment_count'] ); ?></abbr></span>
							</a>
						</div>
					</td>
					<td class="date" data-order="<?php echo esc_attr( $raw_dts ); ?>">
						<abbr raw_value="<?php echo esc_attr( $raw_dts ); ?>" title="<?php echo esc_attr( $page['dts'] ); ?>"><?php echo esc_html( $page['dts'] ); ?></abbr>
					</td>
					<td class="status column-status <?php echo 'trash' === $page['status'] ? 'post-trash' : ''; ?>"><?php echo esc_html( self::get_status( $page['status'] ) ); ?>
					</td>
					<?php
					if ( MainWP_Utility::enabled_wp_seo() ) {
						$count_seo_links   = null;
						$count_seo_linked  = null;
						$seo_score         = '';
						$readability_score = '';
						if ( isset( $page['seo_data'] ) ) {
							$seo_data          = $page['seo_data'];
							$count_seo_links   = esc_html( $seo_data['count_seo_links'] );
							$count_seo_linked  = esc_html( $seo_data['count_seo_linked'] );
							$seo_score         = MainWP_Utility::esc_content( $seo_data['seo_score'], 'mixed' );
							$readability_score = MainWP_Utility::esc_content( $seo_data['readability_score'], 'mixed' );
						}
						?>
						<td class="column-seo-links"><abbr raw_value="<?php echo null !== $count_seo_links ? $count_seo_links : -1; ?>" title=""><?php echo null !== $count_seo_links ? $count_seo_links : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?></abbr></td>
						<td class="column-seo-linked"><abbr raw_value="<?php echo null !== $count_seo_linked ? $count_seo_linked : -1; ?>" title=""><?php echo null !== $count_seo_linked ? $count_seo_linked : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?></abbr></td>
						<td class="column-seo-score"><abbr raw_value="<?php echo $seo_score ? 1 : 0; ?>" title=""><?php echo $seo_score; // phpcs:ignore WordPress.Security.EscapeOutput ?></abbr></td>
						<td class="column-seo-readability"><abbr raw_value="<?php echo $readability_score ? 1 : 0; ?>" title=""><?php echo $readability_score; // phpcs:ignore WordPress.Security.EscapeOutput ?></abbr></td>
						<?php
					}
					?>
					<td class="website">
						<a href="<?php echo esc_html( $website->url ); ?>" class="mainwp-may-hide-referrer" target="_blank"><?php echo esc_html( $website->url ); ?></a>
					</td>
					<td class="right aligned">
						<input class="pageId" type="hidden" name="id" value="<?php echo intval( $page['id'] ); ?>"/>
						<input class="allowedBulkActions" type="hidden" name="allowedBulkActions" value="|get_edit|trash|delete|<?php echo ( 'trash' === $page['status'] ) ? 'restore|' : ''; ?><?php echo ( 'future' === $page['status'] || 'draft' === $page['status'] ) ? 'publish|' : ''; ?>" />
						<input class="websiteId" type="hidden" name="id" value="<?php echo esc_attr( $website->id ); ?>"/>

						<div class="ui left pointing dropdown icon mini basic green button" style="z-index: 999">
							<a href="javascript:void(0)"><i class="ellipsis horizontal icon"></i></a>
							<div class="menu">
								<?php if ( 'future' === $page['status'] || 'draft' === $page['status'] ) : ?>
									<a class="item page_submitpublish" href="#"><?php esc_html_e( 'Publish', 'mainwp' ); ?></a>
								<?php endif; ?>
								<?php if ( 'trash' !== $page['status'] ) : ?>
									<a class="item page_getedit" href="#"><?php esc_html_e( 'Edit', 'mainwp' ); ?></a>
									<?php do_action( 'mainwp_manage_pages_action_item', $page ); ?>
									<a class="item page_submitdelete" href="#"><?php esc_html_e( 'Trash', 'mainwp' ); ?></a>
									<?php endif; ?>
								<?php if ( 'publish' === $page['status'] ) { ?>
									<a class="item" href="<?php echo esc_html( $website->url ) . ( '/' !== substr( $website->url, -1 ) ? '/' : '' ) . '?p=' . intval( $page['id'] ); ?>" target="_blank"><?php esc_html_e( 'View', 'mainwp' ); ?></a>
								<?php } ?>								
								<?php if ( 'trash' === $page['status'] ) { ?>
									<a class="item page_submitrestore" href="#"><?php esc_html_e( 'Restore', 'mainwp' ); ?></a>
									<a class="item page_submitdelete_perm" href="#"><?php esc_html_e( 'Delete permanently', 'mainwp' ); ?></a>
								<?php } ?>
								<a class="item" href="<?php echo 'admin.php?page=SiteOpen&newWindow=yes&websiteid=' . intval( $website->id ); ?>&_opennonce=<?php echo esc_html( wp_create_nonce( 'mainwp-admin-nonce' ) ); ?>" data-tooltip="<?php esc_attr_e( 'Jump to the site WP Admin', 'mainwp' ); ?>"  data-position="bottom right"  data-inverted="" class="open_newwindow_wpadmin ui green basic icon button" target="_blank"><?php esc_html_e( 'Go to WP Admin', 'mainwp' ); ?></a>
								<?php
								/**
								 * Action: mainwp_pages_table_action
								 *
								 * Adds a new item in the Actions menu in Manage Pages table.
								 *
								 * Suggested HTML markup:
								 * <a class="item" href="Your custom URL">Your custom label</a>
								 *
								 * @param array $post    Array containing the page data.
								 * @param array $website Object containing the website data.
								 *
								 * @since 4.1
								 */
								do_action( 'mainwp_pages_table_action', $page, $website );
								?>
							</div>
						</div>
					</td>
				</tr>
				<?php
				$newOutput = ob_get_clean();
				echo $newOutput; // phpcs:ignore WordPress.Security.EscapeOutput
				MainWP_Cache::add_body( 'Page', $newOutput );
				++$output->pages;
			}
			unset( $pages );
		} else {
			$output->errors[ $website->id ] = MainWP_Error_Helper::get_error_message( new MainWP_Exception( 'NOMAINWP', $website->url ) ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	/**
	 * Method publish()
	 *
	 * Publish page.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Recent_Posts::action()
	 */
	public static function publish() {
		MainWP_Recent_Posts::action( 'publish', 'page' );
		die( wp_json_encode( array( 'result' => 'Page has been published!' ) ) );
	}

	/**
	 * Method unpublish()
	 *
	 * Unpublish page.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Recent_Posts::action()
	 */
	public static function unpublish() {
		MainWP_Recent_Posts::action( 'unpublish', 'page' );
		die( wp_json_encode( array( 'result' => 'Page has been unpublished!' ) ) );
	}

	/**
	 * Method trash()
	 *
	 * Trash page.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Recent_Posts::action()
	 */
	public static function trash() {
		MainWP_Recent_Posts::action( 'trash', 'page' );
		die( wp_json_encode( array( 'result' => 'Page has been moved to trash!' ) ) );
	}

	/**
	 * Method delete()
	 *
	 * Delete page.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Recent_Posts::action()
	 */
	public static function delete() {
		MainWP_Recent_Posts::action( 'delete', 'page' );
		die( wp_json_encode( array( 'result' => 'Page has been permanently deleted!' ) ) );
	}

	/**
	 * Method restore()
	 *
	 * Restore page.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Recent_Posts::action()
	 */
	public static function restore() {
		MainWP_Recent_Posts::action( 'restore', 'page' );
		die( wp_json_encode( array( 'result' => 'Page has been restored!' ) ) );
	}

	/**
	 * Method render_bulk_add()
	 *
	 * Check if user has the rights to manage pages,
	 * grab the post id and pass onto render_addedit() method.
	 *
	 * @return void
	 */
	public static function render_bulk_add() {
		if ( ! mainwp_current_user_have_right( 'dashboard', 'manage_pages' ) ) {
			mainwp_do_not_have_permissions( esc_html__( 'manage pages', 'mainwp' ) );
			return;
		}

		/**
		 * MainWP default post to edit.
		 *
		 * @global string
		 */
		global $_mainwp_default_post_to_edit;

		$post_id = $_mainwp_default_post_to_edit ? $_mainwp_default_post_to_edit->ID : 0;
		self::render_addedit( $post_id, 'BulkAdd' );
	}

	/**
	 * Method render_bulk_edit()
	 *
	 * Check if user has the rights to manage pages,
	 * grab the post id and pass onto render_addedit() method.
	 *
	 * @return void
	 */
	public static function render_bulk_edit() {
		if ( ! mainwp_current_user_have_right( 'dashboard', 'manage_pages' ) ) {
			mainwp_do_not_have_permissions( esc_html__( 'manage pages', 'mainwp' ) );
			return;
		}

		$post_id = isset( $_GET['post_id'] ) ? intval( $_GET['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		self::render_addedit( $post_id, 'BulkEdit' );
	}

	/**
	 * Method render_addedit()
	 *
	 * Render bulk posts page.
	 *
	 * @param mixed $post_id Post ID.
	 * @param mixed $what Current page.
	 *
	 * @return void Display page header, bulkpost body & footer.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Post::render_bulkpost()
	 */
	public static function render_addedit( $post_id, $what ) {
		self::render_header( $what, $post_id );
		MainWP_Post::render_bulkpost( $post_id, 'bulkpage' );
		self::render_footer( $what );
	}

	/**
	 * Method posting()
	 *
	 * Render Posting page modal window.
	 *
	 * @return void Posting page modal window html.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
	 * @uses \MainWP\Dashboard\MainWP_DB::query()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
	 * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
	 * @uses \MainWP\Dashboard\MainWP_DB::free_result()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::maybe_unserialyze()
	 * @uses \MainWP\Dashboard\MainWP_Bulk_Add::get_class_name()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::ctype_digit()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::map_site()
	 */
	public static function posting() { // phpcs:ignore -- current complexity required to achieve desired results. Pull request solutions appreciated.
		$succes_message = '';
		$post_id        = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$edit_id        = 0;
		if ( $post_id ) {
			$edit_id = get_post_meta( $post_id, '_mainwp_edit_post_id', true );
			$edit_id = intval( $edit_id );
			if ( $edit_id ) {
				$succes_message = esc_html__( 'Page has been updated successfully', 'mainwp' );
			} else {
				$succes_message = esc_html__( 'New page created', 'mainwp' );
			}
		}
		$data_fields = MainWP_System_Utility::get_default_map_site_fields();
		?>
		<div class="ui modal" id="mainwp-posting-page-modal">
			<i class="close icon"></i>
			<div class="header"><?php $edit_id ? esc_html_e( 'Edit Page', 'mainwp' ) : esc_html_e( 'New Page', 'mainwp' ); ?></div>
			<div class="scrolling content">
			<?php
			/**
			 * Before Page post action
			 *
			 * Fires right before posting the 'bulkpage' to child sites.
			 *
			 * @param int $_GET['id'] Page ID.
			 *
			 * @since Unknown
			 */
			do_action( 'mainwp_bulkpage_before_post', $post_id );

			$skip_post = false;
			if ( $post_id ) {
				if ( 'yes' === get_post_meta( $post_id, '_mainwp_skip_posting', true ) ) {
					$skip_post = true;
					wp_delete_post( $post_id, true );
				}
			}

			if ( ! $skip_post ) {
				if ( $post_id ) {
					$id    = $post_id;
					$_post = get_post( $id );
					if ( $_post ) {
						$selected_by      = get_post_meta( $id, '_selected_by', true );
						$val              = get_post_meta( $id, '_selected_sites', true );
						$selected_sites   = MainWP_System_Utility::maybe_unserialyze( $val );
						$val              = get_post_meta( $id, '_selected_groups', true );
						$selected_groups  = MainWP_System_Utility::maybe_unserialyze( $val );
						$selected_clients = get_post_meta( $id, '_selected_clients', true );
						$post_slug        = base64_decode( get_post_meta( $id, '_slug', true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
						$post_custom      = get_post_custom( $id );
						include_once ABSPATH . 'wp-includes' . DIRECTORY_SEPARATOR . 'post-thumbnail-template.php';
						$featured_image_id   = get_post_thumbnail_id( $id );
						$post_featured_image = null;
						$featured_image_data = null;
						$mainwp_upload_dir   = wp_upload_dir();

						// to fix.
						$post_status = $_post->post_status;
						if ( 'publish' === $post_status ) {
							$post_status = get_post_meta( $id, '_edit_post_status', true );
						}

						/**
						 * Page status
						 *
						 * Sets page status when posting 'bulkpage' to child sites.
						 *
						 * @param int $id Page ID.
						 *
						 * @since Unknown
						 */
						$post_status = apply_filters( 'mainwp_posting_bulkpost_post_status', $post_status, $id );

						$new_post = array(
							'post_title'     => $_post->post_title,
							'post_content'   => $_post->post_content,
							'post_status'    => $post_status,
							'post_date'      => $_post->post_date,
							'post_date_gmt'  => $_post->post_date_gmt,
							'post_type'      => 'page',
							'post_name'      => $post_slug,
							'post_excerpt'   => MainWP_Utility::esc_content( $_post->post_excerpt, 'mixed' ),
							'post_password'  => $_post->post_password,
							'comment_status' => $_post->comment_status,
							'ping_status'    => $_post->ping_status,
							'mainwp_post_id' => $_post->ID,
						);

						if ( ! empty( $featured_image_id ) ) {
							$img                 = wp_get_attachment_image_src( $featured_image_id, 'full' );
							$post_featured_image = $img[0];
							$attachment          = get_post( $featured_image_id );
							$featured_image_data = array(
								'alt'         => get_post_meta( $featured_image_id, '_wp_attachment_image_alt', true ),
								'caption'     => MainWP_Utility::esc_content( $attachment->post_excerpt, 'mixed' ),
								'description' => $attachment->post_content,
								'title'       => htmlspecialchars( $attachment->post_title ),
							);
						}

						$galleries           = get_post_gallery( $id, false );
						$post_gallery_images = array();

						if ( is_array( $galleries ) && isset( $galleries['ids'] ) ) {
							$attached_images = explode( ',', $galleries['ids'] );
							foreach ( $attached_images as $attachment_id ) {
								$attachment = get_post( $attachment_id );
								if ( $attachment ) {
									$post_gallery_images[] = array(
										'id'          => $attachment_id,
										'alt'         => get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
										'caption'     => MainWP_Utility::esc_content( $attachment->post_excerpt, 'mixed' ),
										'description' => $attachment->post_content,
										'src'         => $attachment->guid,
										'title'       => htmlspecialchars( $attachment->post_title ),
									);
								}
							}
						}

						$dbwebsites = array();
						if ( 'site' === $selected_by ) {
							foreach ( $selected_sites as $k ) {
								if ( MainWP_Utility::ctype_digit( $k ) ) {
									$website = MainWP_DB::instance()->get_website_by_id( $k );
									if ( '' === $website->sync_errors && ! MainWP_System_Utility::is_suspended_site( $website ) ) {
										$dbwebsites[ $website->id ] = MainWP_Utility::map_site( $website, $data_fields );
									}
								}
							}
						} elseif ( 'client' === $selected_by ) {
							if ( is_array( $selected_clients ) ) {
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
										$dbwebsites[ $website->id ] = MainWP_Utility::map_site( $website, $data_fields );
									}
								}
							}
						} elseif ( 'group' === $selected_by ) {
							foreach ( $selected_groups as $k ) {
								if ( MainWP_Utility::ctype_digit( $k ) ) {
									$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_by_group_id( $k ) );
									while ( $websites && ( $website   = MainWP_DB::fetch_object( $websites ) ) ) {
										if ( '' !== $website->sync_errors || MainWP_System_Utility::is_suspended_site( $website ) ) {
											continue;
										}
										$dbwebsites[ $website->id ] = MainWP_Utility::map_site( $website, $data_fields );
									}
									MainWP_DB::free_result( $websites );
								}
							}
						}

						$output         = new \stdClass();
						$output->ok     = array();
						$output->errors = array();
						$startTime      = time();

						if ( 0 < count( $dbwebsites ) ) {

							// prepare $post_custom values.
							$new_post_custom = array();
							foreach ( $post_custom as $meta_key => $meta_values ) {
								$new_meta_values = array();
								foreach ( $meta_values as $key_value => $meta_value ) {
									if ( is_serialized( $meta_value ) ) {
										$meta_value = unserialize( $meta_value ); // phpcs:ignore -- internal value safe.
									}
									$new_meta_values[ $key_value ] = $meta_value;
								}
								$new_post_custom[ $meta_key ] = $new_meta_values;
							}

							$post_data = array(
								'new_post'            => base64_encode( wp_json_encode( $new_post ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
								'post_custom'         => base64_encode( wp_json_encode( $new_post_custom ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
								'post_featured_image' => ( null !== $post_featured_image ) ? base64_encode( $post_featured_image ) : null, // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
								'post_gallery_images' => base64_encode( wp_json_encode( $post_gallery_images ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
								'mainwp_upload_dir'   => base64_encode( wp_json_encode( $mainwp_upload_dir ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
								'featured_image_data' => ( null !== $featured_image_data ) ? base64_encode( wp_json_encode( $featured_image_data ) ) : null, // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
							);

							/**
							 * Posting new page
							 *
							 * Sets Page data to post to child sites.
							 *
							 * @param int $id Page ID.
							 *
							 * @since Unknown
							 */
							$post_data = apply_filters( 'mainwp_bulkpage_posting', $post_data, $id );
							MainWP_Connect::fetch_urls_authed( $dbwebsites, 'newpost', $post_data, array( MainWP_Bulk_Add::get_class_name(), 'posting_bulk_handler' ), $output );
						}

						foreach ( $dbwebsites as $website ) {
							if ( isset( $output->ok[ $website->id ] ) && ( 1 === (int) $output->ok[ $website->id ] ) && ( isset( $output->added_id[ $website->id ] ) ) ) {
								$links = isset( $output->link[ $website->id ] ) ? $output->link[ $website->id ] : null;

								do_action_deprecated( 'mainwp-post-posting-page', array( $website, $output->added_id[ $website->id ], $links ), '4.0.7.2', 'mainwp_post_posting_page' ); // @deprecated Use 'mainwp_post_posting_page' instead.
								do_action_deprecated( 'mainwp-bulkposting-done', array( $_post, $website, $output ), '4.0.7.2', 'mainwp_bulkposting_done' ); // @deprecated Use 'mainwp_bulkposting_done' instead.

								/**
								 * Posting page
								 *
								 * Fires while posting page.
								 *
								 * @param object $website                          Object containing child site data.
								 * @param int    $output->added_id[ $website->id ] Child site ID.
								 * @param array  $links                            Links.
								 *
								 * @since Unknown
								 */
								do_action( 'mainwp_post_posting_page', $website, $output->added_id[ $website->id ], $links );

								/**
								 * Posting page completed
								 *
								 * Fires after the page posting process is completed.
								 *
								 * @param array  $_post   Array containing the post data.
								 * @param object $website Object containing child site data.
								 * @param array  $output  Output data.
								 *
								 * @since Unknown
								 */
								do_action( 'mainwp_bulkposting_done', $_post, $website, $output );
							}
						}

						/**
						 * After posting a new page
						 *
						 * Sets data after the posting process to show the process feedback.
						 *
						 * @param array $_post      Array containing the post data.
						 * @param array $dbwebsites Array containing processed sites.
						 * @param array $output     Output data.
						 *
						 * @since Unknown
						 */
						$after_posting = apply_filters_deprecated( 'mainwp-after-posting-bulkpage-result', array( false, $_post, $dbwebsites, $output ), '4.0.7.2', 'mainwp_after_posting_bulkpage_result' );  // @deprecated Use 'mainwp_after_posting_bulkpage_result' instead.
						$after_posting = apply_filters( 'mainwp_after_posting_bulkpage_result', $after_posting, $_post, $dbwebsites, $output );

						if ( false === $after_posting ) {
							?>
							<div class="ui relaxed list">
								<?php foreach ( $dbwebsites as $website ) { ?>
									<div class="item"><a href="<?php echo esc_url( admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ) ); ?>"><?php echo esc_html( stripslashes( $website->name ) ); ?></a>
										: <?php echo ( isset( $output->ok[ $website->id ] ) && 1 === (int) $output->ok[ $website->id ] ? esc_html( $succes_message ) . ' <a href="' . esc_html( $output->link[ $website->id ] ) . '"  class="mainwp-may-hide-referrer" target="_blank">View Page</a>' : 'ERROR: ' . $output->errors[ $website->id ] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									</div>
								<?php } ?>
							</div>

							<?php
						}

						$do_not_del = get_post_meta( $id, '_bulkpost_do_not_del', true );

						if ( 'yes' !== $do_not_del ) {
							wp_delete_post( $id, true );
						}

						$countSites     = 0;
						$countRealItems = 0;
						foreach ( $dbwebsites as $website ) {
							if ( isset( $output->ok[ $website->id ] ) && 1 === (int) $output->ok[ $website->id ] ) {
								++$countSites;
								++$countRealItems;
							}
						}
					}
				} else {
					?>
					<div class="error">
						<p><strong>ERROR</strong>: <?php esc_html_e( 'An undefined error occured.', 'mainwp' ); ?></p>
					</div>
					<?php
				}
			}
			?>
			</div>
			<div class="actions">
				<a href="admin.php?page=PageBulkAdd" class="ui green button"><?php esc_html_e( 'New Page', 'mainwp' ); ?></a>
			</div>
		</div>
		<div class="ui active inverted dimmer" id="mainwp-posting-running">
			<div class="ui indeterminate large text loader"><?php esc_html_e( 'Running ...', 'mainwp' ); ?></div>
		</div>
		<script type="text/javascript">
			jQuery( document ).ready( function () {
				jQuery( "#mainwp-posting-running" ).hide();
				jQuery( "#mainwp-posting-page-modal" ).modal( {
					closable: true,
					onHide: function() {
						location.href = 'admin.php?page=PageBulkManage';
					}
				} ).modal( 'show' );
			} );
		</script>
		<?php
	}

	/**
	 * Method mainwp_help_content()
	 *
	 * Hook the section help content to the Help Sidebar element
	 *
	 * @return void Help section html.
	 */
	public static function mainwp_help_content() {
		if ( isset( $_GET['page'] ) && ( 'PageBulkManage' === $_GET['page'] || 'PageBulkAdd' === $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			?>
			<p><?php esc_html_e( 'If you need help with managing pages, please review following help documents', 'mainwp' ); ?></p>
			<div class="ui relaxed bulleted list">
				<div class="item"><a href="https://kb.mainwp.com/docs/manage-pages/" target="_blank">Manage Pages</a> <i class="external alternate icon"></i></div>
				<div class="item"><a href="https://kb.mainwp.com/docs/create-a-new-page/" target="_blank">Create a New Page</a> <i class="external alternate icon"></i></div>
				<div class="item"><a href="https://kb.mainwp.com/docs/edit-an-existing-page/" target="_blank">Edit an Existing Page</a> <i class="external alternate icon"></i></div>
				<div class="item"><a href="https://kb.mainwp.com/docs/change-status-of-an-existing-page/" target="_blank">Change Status of an Existing Page</a> <i class="external alternate icon"></i></div>
				<div class="item"><a href="https://kb.mainwp.com/docs/view-an-existing-page/" target="_blank">View an Existing Page</a> <i class="external alternate icon"></i></div>
				<div class="item"><a href="https://kb.mainwp.com/docs/delete-pages/" target="_blank">Delete Page(s)</a> <i class="external alternate icon"></i></div>
				<?php
				/**
				 * Action: mainwp_pages_help_item
				 *
				 * Fires at the bottom of the help articles list in the Help sidebar on the Pages page.
				 *
				 * Suggested HTML markup:
				 *
				 * <div class="item"><a href="Your custom URL">Your custom text</a></div>
				 *
				 * @since 4.1
				 */
				do_action( 'mainwp_pages_help_item' );
				?>
			</div>
			<?php
		}
	}
}
