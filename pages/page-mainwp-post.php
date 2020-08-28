<?php
/**
 * MainWP Post Page.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Posts
 *
 * @uses MainWP_Bulk_Add
 */
class MainWP_Post {

	// phpcs:disable Generic.Metrics.CyclomaticComplexity -- Current complexity required to achieve desired results. Pull request solutions appreciated.

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
	 * Public static variable to hold Sub-pages data.
	 *
	 * @static
	 *
	 * @var array Sub-pages.
	 */
	public static $subPages;

	/**
	 * Method init()
	 *
	 * Initiate Page.
	 *
	 * @return void
	 */
	public static function init() {
		/**
		 * This hook allows you to render the Post page header via the 'mainwp-pageheader-post' action.
		 *
		 * @link http://codex.mainwp.com/#mainwp-pageheader-post
		 *
		 * This hook is normally used in the same context of 'mainwp-getsubpages-post'
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-post
		 *
		 * @see \MainWP_Post::render_header
		 */
		add_action( 'mainwp-pageheader-post', array( self::get_class_name(), 'render_header' ) );

		/**
		 * This hook allows you to render the Post page footer via the 'mainwp-pagefooter-post' action.
		 *
		 * @link http://codex.mainwp.com/#mainwp-pagefooter-post
		 *
		 * This hook is normally used in the same context of 'mainwp-getsubpages-post'
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-post
		 *
		 * @see \MainWP_Post::render_footer
		 */
		add_action( 'mainwp-pagefooter-post', array( self::get_class_name(), 'render_footer' ) );

		add_filter( 'admin_post_thumbnail_html', array( self::get_class_name(), 'admin_post_thumbnail_html' ), 10, 3 );

		add_action( 'mainwp_help_sidebar_content', array( self::get_class_name(), 'mainwp_help_content' ) );
	}

	/**
	 * Method ini_menu()
	 *
	 * Initiate Page menu.
	 *
	 * @return void
	 */
	public static function init_menu() {
		$_page = add_submenu_page( 'mainwp_tab', __( 'Posts', 'mainwp' ), '<span id="mainwp-Posts">' . __( 'Posts', 'mainwp' ) . '</span>', 'read', 'PostBulkManage', array( self::get_class_name(), 'render' ) );
		add_action( 'load-' . $_page, array( self::get_class_name(), 'on_load_page' ) );
		add_filter( 'manage_' . $_page . '_columns', array( self::get_class_name(), 'get_manage_columns' ) );

		if ( ! MainWP_Menu::is_disable_menu_item( 3, 'PostBulkAdd' ) ) {
			$_page = add_submenu_page( 'mainwp_tab', __( 'Posts', 'mainwp' ), '<div class="mainwp-hidden">' . __( 'Add New', 'mainwp' ) . '</div>', 'read', 'PostBulkAdd', array( self::get_class_name(), 'render_bulk_add' ) );
			add_action( 'load-' . $_page, array( self::get_class_name(), 'on_load_add_edit' ) );
		}

		if ( ! MainWP_Menu::is_disable_menu_item( 3, 'PostBulkEdit' ) ) {
			$_page = add_submenu_page( 'mainwp_tab', __( 'Posts', 'mainwp' ), '<div class="mainwp-hidden">' . __( 'Edit Post', 'mainwp' ) . '</div>', 'read', 'PostBulkEdit', array( self::get_class_name(), 'render_bulk_edit' ) );
			add_action( 'load-' . $_page, array( self::get_class_name(), 'on_load_add_edit' ) );
		}

		add_submenu_page(
			'mainwp_tab',
			'Posting new bulkpost',
			'<div class="mainwp-hidden">' . __( 'Posts', 'mainwp' ) . '</div>',
			'read',
			'PostingBulkPost',
			array(
				MainWP_Post_Page_Handler::get_class_name(),
				'posting',
			)
		);

		/**
		 * Posts Subpages
		 *
		 * Filters subpages for the Posts page.
		 *
		 * @since Unknown
		 */
		$sub_pages      = array();
		$sub_pages      = apply_filters_deprecated( 'mainwp-getsubpages-post', array( $sub_pages ), '4.0.7.2', 'mainwp_getsubpages_post' );  // @deprecated Use 'mainwp_getsubpages_post' instead.
		self::$subPages = apply_filters( 'mainwp_getsubpages_post', $sub_pages );

		if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
			foreach ( self::$subPages as $subPage ) {
				if ( MainWP_Menu::is_disable_menu_item( 3, 'Post' . $subPage['slug'] ) ) {
					continue;
				}
				add_submenu_page( 'mainwp_tab', $subPage['title'], '<div class="mainwp-hidden">' . $subPage['title'] . '</div>', 'read', 'Post' . $subPage['slug'], $subPage['callback'] );
			}
		}
		self::init_left_menu( self::$subPages );
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
		add_action( 'admin_head', array( self::get_class_name(), 'admin_head' ) );
		add_filter( 'hidden_columns', array( self::get_class_name(), 'get_hidden_columns' ), 10, 3 );
	}

	/**
	 * Method on_load_add_edit()
	 *
	 * Get the post ID,
	 * pass it to method on_load_bulkpost().
	 *
	 * @return void self::on_load_bulkpost( $post_id ).
	 */
	public static function on_load_add_edit() {
		if ( isset( $_GET['page'] ) && 'PostBulkAdd' === $_GET['page'] ) {

			/**
			 * MainWP default post to edit.
			 *
			 * @global string
			 */
			global $_mainwp_default_post_to_edit;

			$post_type                    = 'bulkpost';
			$_mainwp_default_post_to_edit = get_default_post_to_edit( $post_type, true );
			$post_id                      = $_mainwp_default_post_to_edit ? $_mainwp_default_post_to_edit->ID : 0;
		} else {
			$post_id = isset( $_GET['post_id'] ) ? intval( $_GET['post_id'] ) : 0;
		}

		if ( ! $post_id ) {
			wp_die( __( 'Invalid post.', 'mainwp' ) );
		}

		self::on_load_bulkpost( $post_id );
	}

	/**
	 * Method on_load_bulkpost()
	 *
	 * For the given post ID, this method Enqueues all scripts, styles, settings,
	 * and templates necessary to use all media JS APIs, then retrieves post data.
	 *
	 * @param mixed $post_id Given post ID.
	 *
	 * @return void (WP_Post|array|null) Sets the Global variable $GLOBALS['post'],
	 * Type corresponding to $output on success or null on failure. When $output is OBJECT, a WP_Post instance is returned.
	 */
	public static function on_load_bulkpost( $post_id ) {

		wp_enqueue_media( array( 'post' => $post_id ) );

		wp_enqueue_script(
			'mainwp-post',
			MAINWP_PLUGIN_URL . 'assets/js/mainwp-post-edit.js',
			array(
				'jquery',
				'postbox',
				'word-count',
				'media-views',
				'mainwp',
			),
			MAINWP_VERSION,
			true
		);

		$_post = get_post( $post_id );
		// phpcs:ignore -- required for custom bulk posts/pages and support hooks.
		$GLOBALS['post'] = $_post;
	}

	/**
	 * Method get_manage_columns()
	 *
	 * Get columns to display.
	 *
	 * @return array $colums Array of columns to display on the page.
	 */
	public static function get_manage_columns() {
		$colums = array(
			'title'           => __( 'Title', 'mainwp' ),
			'author'          => __( 'Author', 'mainwp' ),
			'date'            => __( 'Date', 'mainwp' ),
			'categories'      => __( 'Categories', 'mainwp' ),
			'tags'            => __( 'Tags', 'mainwp' ),
			'post-type'       => __( 'Post type', 'mainwp' ),
			'comments'        => __( 'Comments', 'mainwp' ),
			'status'          => __( 'Status', 'mainwp' ),
			'seo-links'       => __( 'Links', 'mainwp' ),
			'seo-linked'      => __( 'Linked', 'mainwp' ),
			'seo-score'       => __( 'SEO Score', 'mainwp' ),
			'seo-readability' => __( 'Readability score', 'mainwp' ),
			'website'         => __( 'Website', 'mainwp' ),
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
	 * Grab the current_screen ID, set the pagenow JS variable,
	 * and render the JS HTML Meta tag.
	 *
	 * @return void Render the Post pagenow header tag.
	 */
	public static function admin_head() {

		/**
		 * Current screen.
		 *
		 * @global string
		 */
		global $current_screen;

		?>
		<script type="text/javascript"> pagenow = '<?php echo esc_html( strtolower( $current_screen->id ) ); ?>';</script>
		<?php
	}

	/**
	 * Method get_hidden_columns()
	 *
	 * Get the currently hidden page columns.
	 *
	 * @param mixed $hidden User option value.
	 * @param mixed $screen Current screen ID.
	 *
	 * @return (mixed) $hidden User option value on success, false on failure.
	 */
	public static function get_hidden_columns( $hidden, $screen ) {
		if ( $screen && 'mainwp_page_PostBulkManage' == $screen->id ) {
			$hidden = get_user_option( 'manage' . strtolower( $screen->id ) . 'columnshidden' );
		}
		if ( ! is_array( $hidden ) ) {
			$hidden = array();
		}
		return $hidden;
	}

	/**
	 * Method init_subpages_menu()
	 *
	 * Initiate subpages menu.
	 *
	 * @return void Render subpages menu.
	 */
	public static function init_subpages_menu() {
		?>
		<div id="menu-mainwp-Posts" class="mainwp-submenu-wrapper">
			<div class="wp-submenu sub-open">
				<div class="mainwp_boxout">
					<div class="mainwp_boxoutin"></div>
					<?php if ( mainwp_current_user_have_right( 'dashboard', 'manage_posts' ) ) { ?>
						<a href="<?php echo admin_url( 'admin.php?page=PostBulkManage' ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Manage Posts', 'mainwp' ); ?></a>
						<?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'PostBulkAdd' ) ) { ?>
							<a href="<?php echo admin_url( 'admin.php?page=PostBulkAdd' ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Add New', 'mainwp' ); ?></a>
						<?php } ?>
					<?php } ?>
					<?php
					if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
						foreach ( self::$subPages as $subPage ) {
							if ( ! isset( $subPage['menu_hidden'] ) || ( isset( $subPage['menu_hidden'] ) && true !== $subPage['menu_hidden'] ) ) {
								if ( MainWP_Menu::is_disable_menu_item( 3, 'Post' . $subPage['slug'] ) ) {
									continue;
								}
								?>
								<a href="<?php echo admin_url( 'admin.php?page=Post' . $subPage['slug'] ); ?>" class="mainwp-submenu"><?php echo esc_html( $subPage['title'] ); ?></a>
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
	 * @param array $subPages Array of subPages to add to the Post left menu.
	 *
	 * @return void Menu arrays are assed on to MainWP_Menu::init_subpages_left_menu() & MainWP_Menu::init_left_menu().
	 */
	public static function init_left_menu( $subPages = array() ) {

		MainWP_Menu::add_left_menu(
			array(
				'title'      => __( 'Posts', 'mainwp' ),
				'parent_key' => 'mainwp_tab',
				'slug'       => 'PostBulkManage',
				'href'       => 'admin.php?page=PostBulkManage',
				'icon'       => '<i class="file alternate icon"></i>',
			),
			1
		);

		$init_sub_subleftmenu = array(
			array(
				'title'      => __( 'Manage Posts', 'mainwp' ),
				'parent_key' => 'PostBulkManage',
				'href'       => 'admin.php?page=PostBulkManage',
				'slug'       => 'PostBulkManage',
				'right'      => 'manage_posts',
			),
			array(
				'title'      => __( 'Add New', 'mainwp' ),
				'parent_key' => 'PostBulkManage',
				'href'       => 'admin.php?page=PostBulkAdd',
				'slug'       => 'PostBulkAdd',
				'right'      => 'manage_posts',
			),
		);
		MainWP_Menu::init_subpages_left_menu( $subPages, $init_sub_subleftmenu, 'PostBulkManage', 'Post' );

		foreach ( $init_sub_subleftmenu as $item ) {
			if ( MainWP_Menu::is_disable_menu_item( 3, $item['slug'] ) ) {
				continue;
			}
			MainWP_Menu::add_left_menu( $item, 2 );
		}
	}

	/**
	 * Method admin_post_thumbnail_html()
	 *
	 * Grab the post thumbnail html.
	 *
	 * @param mixed $content Admin post thumbnail HTML markup.
	 * @param mixed $post_id Post ID.
	 * @param mixed $thumbnail_id Thumbnail ID.
	 *
	 * @return string html
	 */
	public static function admin_post_thumbnail_html( $content, $post_id, $thumbnail_id ) {
		$_post = get_post( $post_id );

		if ( empty( $_post ) ) {
			return $content;
		}

		if ( 'bulkpost' !== $_post->post_type && 'bulkpage' !== $_post->post_type ) {
			return $content;
		}

		return self::wp_post_thumbnail_html( $thumbnail_id, $post_id );
	}

	/**
	 * Method render_header()
	 *
	 * @param string $shownPage Current page slug.
	 * @param null   $post_id BulkEdit Post ID.
	 */
	public static function render_header( $shownPage = '', $post_id = null ) {
		$params = array(
			'title' => __( 'Posts', 'mainwp' ),
		);
		MainWP_UI::render_top_header( $params );

			$renderItems = array();

		if ( mainwp_current_user_have_right( 'dashboard', 'manage_posts' ) ) {
			$renderItems[] = array(
				'title'  => __( 'Manage Posts', 'mainwp' ),
				'href'   => 'admin.php?page=PostBulkManage',
				'active' => ( 'BulkManage' === $shownPage ) ? true : false,
			);
			if ( 'BulkEdit' === $shownPage ) {
				$renderItems[] = array(
					'title'  => __( 'Edit Post', 'mainwp' ),
					'href'   => 'admin.php?page=PostBulkEdit&post_id=' . esc_attr( $post_id ),
					'active' => true,
				);
			}
			if ( ! MainWP_Menu::is_disable_menu_item( 3, 'PostBulkAdd' ) ) {
				$renderItems[] = array(
					'title'  => __( 'Add New', 'mainwp' ),
					'href'   => 'admin.php?page=PostBulkAdd',
					'active' => ( 'BulkAdd' === $shownPage ) ? true : false,
				);
			}
		}

		if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
			foreach ( self::$subPages as $subPage ) {
				if ( MainWP_Menu::is_disable_menu_item( 3, 'Post' . $subPage['slug'] ) ) {
					continue;
				}

				if ( isset( $subPage['tab_link_hidden'] ) && true === $subPage['tab_link_hidden'] ) {
					$tab_link = '#';
				} else {
					$tab_link = 'admin.php?page=Post' . $subPage['slug'];
				}

				$item           = array();
				$item['title']  = $subPage['title'];
				$item['href']   = $tab_link;
				$item['active'] = ( $subPage['slug'] == $shownPage ) ? true : false;
				$renderItems[]  = $item;

			}
		}
		MainWP_UI::render_page_navigation( $renderItems, __CLASS__ );
	}

	/**
	 * Method render_footer()
	 *
	 * Render page footer.
	 *
	 * @param string $shownPage Current page slug.
	 */
	public static function render_footer( $shownPage ) {
		echo '</div>';
	}

	/**
	 * Method render()
	 *
	 * Render the page content.
	 *
	 * @return string Post page html content.
	 */
	public static function render() {
		if ( ! mainwp_current_user_have_right( 'dashboard', 'manage_posts' ) ) {
			mainwp_do_not_have_permissions( __( 'manage posts', 'mainwp' ) );
			return;
		}

		$cachedSearch = MainWP_Cache::get_cached_context( 'Post' );

		$selected_sites  = array();
		$selected_groups = array();
		if ( null != $cachedSearch ) {
			if ( is_array( $cachedSearch['sites'] ) ) {
				$selected_sites = $cachedSearch['sites'];
			} elseif ( is_array( $cachedSearch['groups'] ) ) {
				$selected_groups = $cachedSearch['groups'];
			}
		}

		self::render_header( 'BulkManage' );

		?>
		<div class="ui alt mainwp-posts segment">
			<div class="mainwp-main-content">
				<div class="mainwp-actions-bar ui mini form">
					<div class="ui grid">
						<div class="ui two column row">
							<div class="column">
								<select class="ui dropdown" id="mainwp-bulk-actions">
									<option value="none"><?php esc_html_e( 'Bulk Actions', 'mainwp' ); ?></option>
									<option value="publish"><?php esc_html_e( 'Publish', 'mainwp' ); ?></option>
									<option value="unpublish"><?php esc_html_e( 'Unpublish', 'mainwp' ); ?></option>
									<option value="trash"><?php esc_html_e( 'Trash', 'mainwp' ); ?></option>
									<option value="restore"><?php esc_html_e( 'Restore', 'mainwp' ); ?></option>
									<option value="delete"><?php esc_html_e( 'Delete', 'mainwp' ); ?></option>
									<?php
									/**
									 * Action: mainwp_posts_bulk_action
									 *
									 * Adds new action to the Bulk Actions menu on Manage Posts.
									 *
									 * Suggested HTML Markup:
									 * <option value="Your custom value">Your custom text</option>
									 *
									 * @since 4.1
									 */
									do_action( 'mainwp_posts_bulk_action' );
									?>
								</select>
								<button class="ui mini button" id="mainwp-do-posts-bulk-actions"><?php esc_html_e( 'Apply', 'mainwp' ); ?></button>
								<?php
								/**
								 * Action: mainwp_posts_actions_bar_left
								 *
								 * Fires at the left side of the actions bar on the Posts screen, after the Bulk Actions menu.
								 *
								 * @since 4.0
								 */
								do_action( 'mainwp_posts_actions_bar_left' );
								?>
							</div>
							<div class="right aligned column">
								<?php
								/**
								 * Action: mainwp_posts_actions_bar_right
								 *
								 * Fires at the right side of the actions bar on the Posts screen.
								 *
								 * @since 4.0
								 */
								do_action( 'mainwp_posts_actions_bar_right' );
								?>
							</div>
						</div>
					</div>
				</div>
				<div class="ui segment" id="mainwp-posts-table-wrapper">
					<?php self::render_table( true ); ?>
				</div>
			</div>
			<div class="mainwp-side-content mainwp-no-padding">
				<?php
				/**
				 * Action: mainwp_manage_posts_sidebar_top
				 *
				 * Fires at the top of the sidebar on Manage posts.
				 *
				 * @since 4.1
				 */
				do_action( 'mainwp_manage_posts_sidebar_top' );
				?>
				<div class="mainwp-select-sites">
					<?php
					/**
					 * Action: mainwp_manage_posts_before_select_sites
					 *
					 * Fires before the Select Sites section on Manage posts.
					 *
					 * @since 4.1
					 */
					do_action( 'mainwp_manage_posts_before_select_sites' );
					?>
					<div class="ui header"><?php esc_html_e( 'Select Sites', 'mainwp' ); ?></div>
					<?php MainWP_UI::select_sites_box( 'checkbox', true, true, 'mainwp_select_sites_box_left', '', $selected_sites, $selected_groups ); ?>
					<?php
					/**
					 * Action: mainwp_manage_posts_after_select_sites
					 *
					 * Fires after the Select Sites section on Manage posts.
					 *
					 * @since 4.1
					 */
					do_action( 'mainwp_manage_posts_after_select_sites' );
					?>
				</div>
				<div class="ui divider"></div>
				<div class="mainwp-search-options">
					<?php
					/**
					 * Action: mainwp_manage_posts_before_search_options
					 *
					 * Fires before the Search Options on Manage Posts.
					 *
					 * @since 4.1
					 */
					do_action( 'mainwp_manage_posts_before_search_options' );
					?>
					<div class="ui mini form">
						<div class="field">
							<select multiple="" class="ui fluid dropdown" id="mainwp_post_search_type">
								<option value=""><?php esc_html_e( 'Select status', 'mainwp' ); ?></option>
								<option value="publish"><?php esc_html_e( 'Published', 'mainwp' ); ?></option>
								<option value="pending"><?php esc_html_e( 'Pending', 'mainwp' ); ?></option>
								<option value="private"><?php esc_html_e( 'Private', 'mainwp' ); ?></option>
								<option value="future"><?php esc_html_e( 'Scheduled', 'mainwp' ); ?></option>
								<option value="draft"><?php esc_html_e( 'Draft', 'mainwp' ); ?></option>
								<option value="trash"><?php esc_html_e( 'Trash', 'mainwp' ); ?></option>
							</select>
						</div>
					</div>
				</div>
				<div class="ui divider"></div>
				<div class="mainwp-search-options">
					<div class="ui header"><?php esc_html_e( 'Search Options', 'mainwp' ); ?></div>
					<?php self::render_search_options(); ?>
					<?php
					/**
					 * Action: mainwp_manage_posts_after_search_options
					 *
					 * Fires after the Search Options on Manage Posts.
					 *
					 * @since 4.1
					 */
					do_action( 'mainwp_manage_posts_after_search_options' );
					?>
				</div>
				<div class="ui divider"></div>
				<div class="mainwp-search-submit">
					<?php
					/**
					 * Action: mainwp_manage_posts_before_submit_button
					 *
					 * Fires before the Submit Button on Manage Posts.
					 *
					 * @since 4.1
					 */
					do_action( 'mainwp_manage_posts_before_submit_button' );
					?>
					<input type="button" name="mainwp_show_posts" id="mainwp_show_posts" class="ui green big fluid button" value="<?php esc_attr_e( 'Show Posts', 'mainwp' ); ?>"/>
					<?php
					/**
					 * Action: mainwp_manage_posts_after_submit_button
					 *
					 * Fires after the Submit Button on Manage Posts.
					 *
					 * @since 4.1
					 */
					do_action( 'mainwp_manage_posts_after_submit_button' );
					?>
				</div>
				<?php
				/**
				 * Action: mainwp_manage_posts_sidebar_bottom
				 *
				 * Fires at the bottom of the sidebar on Manage posts.
				 *
				 * @since 4.1
				 */
				do_action( 'mainwp_manage_posts_sidebar_bottom' );
				?>
			</div>
			<div class="ui hidden clearing divider"></div>
		</div>

		<?php

		if ( isset( $_REQUEST['siteid'] ) && isset( $_REQUEST['postid'] ) ) {
			echo '<script>jQuery(document).ready(function() { mainwp_show_post(  ' . intval( $_REQUEST['siteid'] ) . ', ' . intval( $_REQUEST['postid'] ) . ', undefined ) } );</script>';
		} elseif ( isset( $_REQUEST['siteid'] ) && isset( $_REQUEST['userid'] ) ) {
			echo '<script>jQuery(document).ready(function() { mainwp_show_post( ' . intval( $_REQUEST['siteid'] ) . ', undefined, ' . intval( $_REQUEST['userid'] ) . ' ) } );</script>';
		}

		self::render_footer( 'BulkManage' );
	}

	/**
	 * Method render_search_options()
	 *
	 * Search form for Post page.
	 */
	public static function render_search_options() {
		$cachedSearch = MainWP_Cache::get_cached_context( 'Post' );
		$statuses     = isset( $cachedSearch['status'] ) ? $cachedSearch['status'] : array();
		if ( $cachedSearch && isset( $cachedSearch['keyword'] ) ) {
			$cachedSearch['keyword'] = trim( $cachedSearch['keyword'] );
		}

		?>
		<div class="ui mini form">
			<div class="field">
				<div class="ui input fluid">
					<input type="text" placeholder="<?php esc_attr_e( 'Containing keyword', 'mainwp' ); ?>" id="mainwp_post_search_by_keyword" class="text" value="<?php echo ( null != $cachedSearch ) ? esc_attr( $cachedSearch['keyword'] ) : ''; ?>"/>
				</div>
			</div>
			<div class="field">
				<?php
				$searchon = 'title';
				if ( null != $cachedSearch ) {
					$searchon = $cachedSearch['search_on'];
				}
				?>
				<select class="ui dropdown fluid" id="mainwp_post_search_on">
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
								<input type="text" placeholder="<?php esc_attr_e( 'Date', 'mainwp' ); ?>" id="mainwp_post_search_by_dtsstart" value="
								<?php
								if ( null != $cachedSearch ) {
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
								<input type="text" placeholder="<?php esc_attr_e( 'Date', 'mainwp' ); ?>" id="mainwp_post_search_by_dtsstop" value="
								<?php
								if ( null != $cachedSearch ) {
									echo esc_attr( $cachedSearch['dtsstop'] );
								}
								?>
								"/>
							</div>
						</div>
					</div>
				</div>
			</div>
			<?php if ( is_plugin_active( 'mainwp-custom-post-types/mainwp-custom-post-types.php' ) ) : ?>
			<div class="field">
				<label><?php esc_html_e( 'Select post type', 'mainwp' ); ?></label><br/>
				<select class="ui dropdown fluid" id="mainwp_get_custom_post_types_select">
					<option value="any"><?php esc_html_e( 'All post types', 'mainwp' ); ?></option>
					<option value="post"><?php esc_html_e( 'Post', 'mainwp' ); ?></option>
					<?php
					/**
					 * Default post types
					 *
					 * Set default custom post types to exclude from the CPT extension options.
					 *
					 * @since 4.0
					 */
					$default_post_types = apply_filters( 'mainwp_custom_post_types_default', array() );
					foreach ( get_post_types( array( '_builtin' => false ) ) as $key ) {
						if ( ! in_array( $key, $default_post_types ) ) {
							echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $key ) . '</option>';
						}
					}
					?>
				</select>
			</div>
			<?php endif; ?>
			<div class="field">
				<label><?php esc_html_e( 'Max posts to return', 'mainwp' ); ?></label>
				<input type="text" name="mainwp_maximumPosts"  id="mainwp_maximumPosts" value="<?php echo( ( false === get_option( 'mainwp_maximumPosts' ) ) ? 50 : get_option( 'mainwp_maximumPosts' ) ); ?>"/>
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
					jQuery( '#mainwp_post_search_type' ).dropdown( 'set selected', [<?php echo $status; // phpcs:ignore -- safe output. ?>] );
				} )
			</script>
			<?php
		}
	}

	/**
	 * Renders Posts table.
	 *
	 * @param bool   $cached Show cached data or not. Default: true.
	 * @param mixed  $keyword Search keywords.
	 * @param mixed  $dtsstart Date & time of Session start.
	 * @param mixed  $dtsstop Date & time of Session stop.
	 * @param mixed  $status Page statuses.
	 * @param mixed  $groups Groups to display.
	 * @param mixed  $sites Site URLS.
	 * @param int    $postId Post ID.
	 * @param int    $userId Current user ID.
	 * @param string $post_type Post type.
	 * @param string $search_on Site on all sites. Default = all.
	 */
	public static function render_table( $cached = true, $keyword = '', $dtsstart = '', $dtsstop = '', $status = '', $groups = '', $sites = '', $postId = 0, $userId = 0, $post_type = '', $search_on = 'all' ) {
		?>

		<div id="mainwp-message-zone"></div>

		<div id="mainwp-loading-posts-row" style="display: none;">
			<div class="ui active inverted dimmer">
				<div class="ui indeterminate large text loader"><?php esc_html_e( 'Loading Posts...', 'mainwp' ); ?></div>
			</div>
		</div>

		<?php
		/**
		 * Action: mainwp_before_posts_table
		 *
		 * Fires before the Manage Posts table.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_before_posts_table' );
		?>
		<table id="mainwp-posts-table" class="ui selectable single line table" style="width:100%">
			<thead class="full-width">
				<tr>
					<th class="no-sort collapsing check-column"><span class="ui checkbox"><input id="cb-select-all-top" type="checkbox" /></span></th>
					<?php
					/**
					 * Action: mainwp_posts_table_header
					 *
					 * Adds new column header to the Manage posts table.
					 *
					 *  @since 4.1
					 */
					do_action( 'mainwp_posts_table_header' );
					?>
					<th id="mainwp-title"><?php esc_html_e( 'Title', 'mainwp' ); ?></th>
					<th id="mainwp-author"><?php esc_html_e( 'Author', 'mainwp' ); ?></th>
					<th id="mainwp-categories"><?php esc_html_e( 'Categories', 'mainwp' ); ?></th>
					<th id="mainwp-tags"><?php esc_html_e( 'Tags', 'mainwp' ); ?></th>
					<?php if ( is_plugin_active( 'mainwp-custom-post-types/mainwp-custom-post-types.php' ) ) : ?>
						<th id="mainwp-post-type"><?php esc_html_e( 'Post Type', 'mainwp' ); ?></th>
					<?php endif; ?>
					<?php if ( is_plugin_active( 'mainwp-comments-extension/mainwp-comments-extension.php' ) ) : ?>
						<th id="mainwp-comments"><i class="comment icon"></i></th>
					<?php endif; ?>
					<th id="mainwp-date" class=""><?php esc_html_e( 'Date', 'mainwp' ); ?></th>
					<th id="mainwp-status" class=""><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
					<?php if ( MainWP_Utility::enabled_wp_seo() ) : ?>
						<th id="mainwp-seo-links"><?php esc_html_e( 'Links', 'mainwp' ); ?></th>
						<th id="mainwp-seo-linked"><?php esc_html_e( 'Linked', 'mainwp' ); ?></th>
						<th id="mainwp-seo-score"><?php esc_html_e( 'SEO Score', 'mainwp' ); ?></th>
						<th id="mainwp-seo-readability"><?php esc_html_e( 'Readability score', 'mainwp' ); ?></th>
					<?php endif; ?>
					<th id="mainwp-website"><?php esc_html_e( 'Site', 'mainwp' ); ?></th>
					<th id="mainwp-posts-actions" class="no-sort"></th>
				</tr>
			</thead>

			<tbody id="mainwp-posts-list">
		<?php
		if ( $cached ) {
			MainWP_Cache::echo_body( 'Post' );
		} else {
			self::render_table_body( $keyword, $dtsstart, $dtsstop, $status, $groups, $sites, $postId, $userId, $post_type, $search_on );
		}
		?>
			</tbody>
		</table>
		<?php
		/**
		 * Action: mainwp_after_posts_table
		 *
		 * Fires after the Manage Posts table.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_after_posts_table' );

		$table_features = array(
			'searching'  => 'true',
			'paging'     => 'true',
			'info'       => 'true',
			'stateSave'  => 'true',
			'scrollX'    => 'true',
			'colReorder' => '{ fixedColumnsLeft: 1, fixedColumnsRight: 1 }',
			'order'      => '[]',
		);

		/**
		 * Filter: mainwp_posts_table_fatures
		 *
		 * Filters the Manage Posts table features.
		 *
		 * @since 4.1
		 */
		$table_features = apply_filters( 'mainwp_posts_table_fatures', $table_features );
		?>
		<script type="text/javascript">
		jQuery( document ).ready( function () {
			try {
				jQuery( '#mainwp-posts-table' ).DataTable( {
					"searching" : <?php echo $table_features['searching']; ?>,
					"colReorder" : <?php echo $table_features['colReorder']; ?>,
					"stateSave":  <?php echo $table_features['stateSave']; ?>,
					"paging": <?php echo $table_features['paging']; ?>,
					"info": <?php echo $table_features['info']; ?>,
					"order": <?php echo $table_features['order']; ?>,
					"scrollX" : <?php echo $table_features['scrollX']; ?>,
					"lengthMenu": [ [10, 25, 50, 100, -1], [10, 25, 50, 100, "All"] ],
					"columnDefs": [ {
						"targets": 'no-sort',
						"orderable": false
					} ],
					"language" : { "emptyTable": "<?php esc_html_e( 'Please use the search options to find wanted posts.', 'mainwp' ); ?>" },
					"preDrawCallback": function( settings ) {
						jQuery( '#mainwp-posts-table-wrapper table .ui.dropdown' ).dropdown();
						jQuery( '#mainwp-posts-table-wrapper table .ui.checkbox' ).checkbox();
						mainwp_datatable_fix_menu_overflow();
						mainwp_table_check_columns_init(); // ajax: to fix checkbox all.
					}
				} );
			} catch ( err ) {
				// to fix js error.
			}
		} );
		</script>

		<?php
	}

	/**
	 * Method render_table_body()
	 *
	 * Render Posts table body.
	 *
	 * @param mixed   $keyword Search keywords.
	 * @param mixed   $dtsstart Date & time of Session start.
	 * @param mixed   $dtsstop Date & time of Session stop.
	 * @param mixed   $status Page statuses.
	 * @param mixed   $groups Groups to display.
	 * @param mixed   $sites Site URLS.
	 * @param integer $postId Post ID.
	 * @param integer $userId Current user ID.
	 * @param string  $post_type Post type.
	 * @param string  $search_on Site on all sites. Default = all.
	 *
	 * @return string Post table body html.
	 */
	public static function render_table_body( $keyword, $dtsstart, $dtsstop, $status, $groups, $sites, $postId, $userId, $post_type = '', $search_on = 'all' ) { // phpcs:ignore -- complex function.
		MainWP_Cache::init_cache( 'Post' );

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
					while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {
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

		$output         = new \stdClass();
		$output->errors = array();
		$output->posts  = 0;

		if ( 0 < count( $dbwebsites ) ) {
			$post_data = array(
				'keyword'    => $keyword,
				'dtsstart'   => $dtsstart,
				'dtsstop'    => $dtsstop,
				'status'     => $status,
				'search_on'  => $search_on,
				'maxRecords' => ( ( false === get_option( 'mainwp_maximumPosts' ) ) ? 50 : get_option( 'mainwp_maximumPosts' ) ),
			);

			if ( is_plugin_active( 'mainwp-custom-post-types/mainwp-custom-post-types.php' ) ) {
				$post_data['post_type'] = $post_type;
				if ( 'any' === $post_type ) {
					$post_data['exclude_page_type'] = 1;
				}
			}

			if ( MainWP_Utility::enabled_wp_seo() ) {
				$post_data['WPSEOEnabled'] = 1;
			}

			if ( isset( $postId ) && ( '' !== $postId ) ) {
				$post_data['postId'] = $postId;
			} elseif ( isset( $userId ) && ( '' !== $userId ) ) {
				$post_data['userId'] = $userId;
			}

			/**
			 * Get all posts data
			 *
			 * Set search parameters for the fetch process.
			 *
			 * @since 3.4
			 */
			$post_data = apply_filters( 'mainwp_get_all_posts_data', $post_data );
			MainWP_Connect::fetch_urls_authed(
				$dbwebsites,
				'get_all_posts',
				$post_data,
				array(
					self::get_class_name(),
					'posts_search_handler',
				),
				$output
			);
		}

		MainWP_Cache::add_context(
			'Post',
			array(
				'count'     => $output->posts,
				'keyword'   => $keyword,
				'dtsstart'  => $dtsstart,
				'dtsstop'   => $dtsstop,
				'status'    => $status,
				'sites'     => ( '' !== $sites ) ? $sites : '',
				'groups'    => ( '' !== $groups ) ? $groups : '',
				'search_on' => $search_on,
			)
		);

		if ( 0 === $output->posts ) {
			MainWP_Cache::add_body( 'Post', '' );
			return;
		}
	}

	/**
	 * Method get_status()
	 *
	 * Get Post status.
	 *
	 * @param mixed $status Post status.
	 *
	 * @return string $status Post status.
	 */
	private static function get_status( $status ) {
		if ( 'publish' === $status ) {
			return 'Published';
		}

		return ucfirst( $status );
	}

	/**
	 * Method posts_search_handler()
	 *
	 * Post page search handler.
	 *
	 * @param mixed $data Search data.
	 * @param mixed $website Child Sites ID.
	 * @param mixed $output Html to output.
	 *
	 * @return string Returned search results html.
	 */
	public static function posts_search_handler( $data, $website, &$output ) { // phpcs:ignore -- complex method.
		if ( 0 < preg_match( '/<mainwp>(.*)<\/mainwp>/', $data, $results ) ) {
			$result = $results[1];
			$posts  = MainWP_System_Utility::get_child_response( base64_decode( $result ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.

			if ( is_array( $posts ) && isset( $posts['error'] ) ) {
				$output->errors[ $website->id ] = $posts['error'];
				return;
			}

			unset( $results );

			$child_to_dash_array = array();

			if ( is_plugin_active( 'mainwp-custom-post-types/mainwp-custom-post-types.php' ) ) {
				$child_post_ids = array();
				foreach ( $posts as $post ) {
					$child_post_ids[] = $post['id'];
				}
				reset( $posts );

				$connections_ids = apply_filters( 'mainwp_custom_post_types_get_post_connections', false, $website->id, $child_post_ids );
				if ( ! empty( $connections_ids ) ) {
					foreach ( $connections_ids as $key ) {
						$child_to_dash_array[ $key->child_post_id ] = $key->dash_post_id;
					}
				}
			}

			foreach ( $posts as $post ) {
				$raw_dts = '';
				if ( isset( $post['dts'] ) ) {
					$raw_dts = $post['dts'];
					if ( ! stristr( $post['dts'], '-' ) ) {
						$post['dts'] = MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( $post['dts'] ) );
					}
				}

				if ( ! isset( $post['title'] ) || ( '' === $post['title'] ) ) {
					$post['title'] = '(No Title)';
				}

				ob_start();
				?>

				<tr>
					<td class="check-column"><span class="ui checkbox"><input type="checkbox" name="post[]" value="1"></span></td>
					<?php
					/**
					 * Action: mainwp_posts_table_column
					 *
					 * Adds a new column item in the Manage posts table.
					 *
					 * @param array $post    Array containing the post data.
					 * @param array $website Object containing the website data.
					 *
					 * @since 4.1
					 */
					do_action( 'mainwp_posts_table_column', $post, $website );
					?>
					<td class="title column-title">
						<input class="postId" type="hidden" name="id" value="<?php echo esc_attr( $post['id'] ); ?>"/>
						<input class="allowedBulkActions" type="hidden" name="allowedBulkActions" value="|get_edit|trash|delete|<?php echo ( 'publish' === $post['status'] ) ? 'unpublish|' : ''; ?><?php echo ( 'pending' === $post['status'] ) ? 'approve|' : ''; ?><?php echo ( 'trash' === $post['status'] ) ? 'restore|' : ''; ?><?php echo ( 'future' === $post['status'] || 'draft' === $post['status'] ) ? 'publish|' : ''; ?>" />
						<input class="websiteId" type="hidden" name="id" value="<?php echo intval( $website->id ); ?>"/>
						<strong>
							<abbr title="<?php echo esc_attr( $post['title'] ); ?>">
							<?php if ( 'trash' !== $post['status'] ) { ?>
									<a class="row-title" href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo intval( $website->id ); ?>&location=<?php echo base64_encode( 'post.php?post=' . $post['id'] . '&action=edit' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible. ?>" target="_blank">
										<?php echo esc_html( $post['title'] ); ?>
									</a>
								<?php
							} else {
								echo esc_html( $post['title'] );
							}
							?>
							</abbr>
						</strong>
					</td>

					<td class="author column-author"><?php echo esc_html( $post['author'] ); ?></td>

					<td class="categories column-categories"><?php echo esc_attr( $post['categories'] ); ?></td>

					<td class="tags"><?php echo( '' === $post['tags'] ? 'No Tags' : $post['tags'] ); ?></td>

					<?php if ( is_plugin_active( 'mainwp-custom-post-types/mainwp-custom-post-types.php' ) ) : ?>
						<td class="post-type column-post-type"><?php echo esc_html( $post['post_type'] ); ?></td>
					<?php endif; ?>

					<?php if ( is_plugin_active( 'mainwp-comments-extension/mainwp-comments-extension.php' ) ) : ?>
						<td class="comments column-comments">
							<div class="post-com-count-wrapper">
								<a href="<?php echo admin_url( 'admin.php?page=CommentBulkManage&siteid=' . intval( $website->id ) . '&postid=' . $post['id'] ); ?>" title="0 pending" class="post-com-count"><span class="comment-count"><abbr title="<?php echo esc_attr( $post['comment_count'] ); ?>"><?php echo esc_html( $post['comment_count'] ); ?></abbr></span></a>
							</div>
						</td>
					<?php endif; ?>

					<td class="date column-date" data-order="<?php echo esc_attr( $raw_dts ); ?>"><abbr raw_value="<?php echo esc_attr( $raw_dts ); ?>" title="<?php echo esc_attr( $post['dts'] ); ?>"><?php echo esc_html( $post['dts'] ); ?></abbr></td>

					<td class="status column-status"><?php echo self::get_status( $post['status'] ); ?></td>

					<?php
					if ( MainWP_Utility::enabled_wp_seo() ) :
						$count_seo_links   = null;
						$count_seo_linked  = null;
						$seo_score         = '';
						$readability_score = '';
						if ( isset( $post['seo_data'] ) ) {
							$seo_data          = $post['seo_data'];
							$count_seo_links   = esc_html( $seo_data['count_seo_links'] );
							$count_seo_linked  = esc_html( $seo_data['count_seo_linked'] );
							$seo_score         = $seo_data['seo_score'];
							$readability_score = $seo_data['readability_score'];
						}
						?>
						<td class="column-seo-links" ><abbr raw_value="<?php echo null !== $count_seo_links ? $count_seo_links : -1; ?>" title=""><?php echo null !== $count_seo_links ? $count_seo_links : ''; ?></abbr></td>
						<td class="column-seo-linked"><abbr raw_value="<?php echo null !== $count_seo_linked ? $count_seo_linked : -1; ?>" title=""><?php echo null !== $count_seo_linked ? $count_seo_linked : ''; ?></abbr></td>
						<td class="column-seo-score"><abbr raw_value="<?php echo $seo_score ? 1 : 0; ?>" title=""><?php echo $seo_score; ?></abbr></td>
						<td class="column-seo-readability"><abbr raw_value="<?php echo $readability_score ? 1 : 0; ?>" title=""><?php echo $readability_score; ?></abbr></td>
					<?php endif; ?>

					<td class="website column-website"><a href="<?php echo esc_url( $website->url ); ?>" target="_blank"><?php echo esc_html( $website->url ); ?></a></td>
					<td class="right aligned">
						<div class="ui right pointing dropdown icon mini basic green button" style="z-index: 999">
							<a href="javascript:void(0)"><i class="ellipsis horizontal icon"></i></a>
							<div class="menu">
								<?php if ( 'future' === $post['status'] || 'draft' === $post['status'] ) : ?>
									<a class="item post_submitpublish" href="#"><?php esc_html_e( 'Publish', 'mainwp' ); ?></a>
								<?php endif; ?>

								<?php if ( 'pending' === $post['status'] ) : ?>
									<a class="item post_submitapprove" href="#"><?php esc_html_e( 'Approve', 'mainwp' ); ?></a>
								<?php endif; ?>

								<?php if ( 'publish' === $post['status'] ) : ?>
									<a class="item post_submitunpublish" href="#"><?php esc_html_e( 'Unpublish', 'mainwp' ); ?></a>
									<a class="item mainwp-may-hide-referrer" href="<?php echo esc_html( $website->url ) . ( substr( $website->url, - 1 ) != '/' ? '/' : '' ) . '?p=' . esc_attr( $post['id'] ); ?>" target="_blank" ><?php esc_html_e( 'View', 'mainwp' ); ?></a>
								<?php endif; ?>

								<?php if ( 'trash' === $post['status'] ) : ?>
									<a class="item post_submitrestore" href="#"><?php esc_html_e( 'Restore', 'mainwp' ); ?></a>
									<a class="item post_submitdelete_perm" href="#"><?php esc_html_e( 'Delete', 'mainwp' ); ?></a>
								<?php endif; ?>

								<?php if ( 'trash' !== $post['status'] ) : ?>
									<?php if ( isset( $child_to_dash_array[ $post['id'] ] ) ) { ?>
										<a class="item" href="post.php?post=<?php echo (int) $child_to_dash_array[ $post['id'] ]; ?>&action=edit&select=<?php echo (int) $website->id; ?>"><?php esc_html_e( 'Edit', 'mainwp' ); ?></a>
									<?php } else { ?>
										<a class="item post_getedit" href="#"><?php esc_html_e( 'Edit', 'mainwp' ); ?></a>
									<?php } ?>
									<a class="item post_submitdelete" href="#"><?php esc_html_e( 'Trash', 'mainwp' ); ?></a>
								<?php endif; ?>
									<a class="item" href="<?php echo 'admin.php?page=SiteOpen&newWindow=yes&websiteid=' . $website->id; ?>" data-tooltip="<?php esc_attr_e( 'Jump to the site WP Admin', 'mainwp' ); ?>"  data-position="bottom right"  data-inverted="" class="open_newwindow_wpadmin ui green basic icon button" target="_blank"><?php esc_html_e( 'Go to WP Admin', 'mainwp' ); ?></a>
									<?php
									/**
									 * Action: mainwp_posts_table_action
									 *
									 * Adds a new item in the Actions menu in Manage Posts table.
									 *
									 * Suggested HTML markup:
									 * <a class="item" href="Your custom URL">Your custom label</a>
									 *
									 * @param array $post    Array containing the post data.
									 * @param array $website Object containing the website data.
									 *
									 * @since 4.1
									 */
									do_action( 'mainwp_posts_table_action', $post, $website );
									?>
							</div>
						</div>
					</td>
				</tr>
				<?php
				$newOutput = ob_get_clean();
				echo $newOutput;

				MainWP_Cache::add_body( 'Post', $newOutput );
				$output->posts ++;
			}
			unset( $posts );
		} else {
			$output->errors[ $website->id ] = MainWP_Error_Helper::get_error_message( new MainWP_Exception( 'NOMAINWP', $website->url ) );
		}
	}

	/**
	 * Method list_meta_row()
	 *
	 * Outputs a single row of public meta data in the Custom Fields meta box.
	 *
	 * @since 2.5.0
	 *
	 * @param array $entry Post array.
	 * @param int   $count Counter variable.
	 * @return string $r Custom Fields meta box HTML.
	 */
	public static function list_meta_row( $entry, &$count ) {

		/**
		 * Static variable to hold update nonce.
		 *
		 * @static
		 *
		 * @var string Update nonce.
		 */
		static $update_nonce = '';

		if ( is_protected_meta( $entry['meta_key'], 'post' ) ) {
			return '';
		}

		if ( ! $update_nonce ) {
			$update_nonce = wp_create_nonce( 'add-meta' );
		}

		$r = '';
		++ $count;

		if ( is_serialized( $entry['meta_value'] ) ) {
			if ( is_serialized_string( $entry['meta_value'] ) ) {
				$entry['meta_value'] = MainWP_System_Utility::maybe_unserialyze( $entry['meta_value'] );
			} else {
				--$count;
				return '';
			}
		}

		$entry['meta_key']   = esc_attr( $entry['meta_key'] );
		$entry['meta_value'] = esc_textarea( $entry['meta_value'] );
		$entry['meta_id']    = (int) $entry['meta_id'];

		$delete_nonce = wp_create_nonce( 'delete-meta_' . $entry['meta_id'] );

		$r .= "\n\t<div class=\"two column row\" meta-id=\"" . $entry['meta_id'] . '" >';
		$r .= "\n\t\t<div class=\"column\"><label for='meta-{$entry['meta_id']}-key'>" . __( 'Key', 'mainwp' ) . "</label><input name='meta[{$entry['meta_id']}][key]' id='meta-{$entry['meta_id']}-key' type='text' size='20' value='{$entry['meta_key']}' />";
		$r .= "\n\t\t";
		$r .= "<input type=\"button\" onclick=\"mainwp_post_newmeta_submit( 'delete', this )\" class=\"ui mini button\" _ajax_nonce=\"$delete_nonce\" value=\"" . esc_attr__( 'Delete', 'mainwp' ) . '">';
		$r .= "\n\t\t";
		$r .= "<input type=\"button\" onclick=\"mainwp_post_newmeta_submit( 'update', this )\" class=\"ui mini button\" value=\"" . esc_attr__( 'Update', 'mainwp' ) . '">';
		$r .= '</div>';
		$r .= "\n\t\t<div class=\"column\"><label for='meta-{$entry['meta_id']}-value'>" . __( 'Value', 'mainwp' ) . "</label><textarea name='meta[{$entry['meta_id']}][value]' id='meta-{$entry['meta_id']}-value' rows='2' cols='30'>{$entry['meta_value']}</textarea></div>\n\t</div>";
		return $r;
	}

	/**
	 * Method meta_form()
	 *
	 * Prints the form in the Custom Fields meta box.
	 *
	 * @since 1.2.0
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 *
	 * @param WP_Post $pos Optional. The post being edited.
	 */
	public static function meta_form( $pos = null ) {
		global $wpdb;
		$_post = get_post( $pos );

		/**
		 * Filters values for the meta key dropdown in the Custom Fields meta box.
		 *
		 * Returning a non-null value will effectively short-circuit and avoid a
		 * potentially expensive query against postmeta.
		 *
		 * @since 4.4.0
		 *
		 * @param array|null $keys Pre-defined meta keys to be used in place of a postmeta query. Default null.
		 * @param WP_Post    $_post The current post object.
		 */
		$keys = apply_filters( 'postmeta_form_keys', null, $_post );

		if ( null === $keys ) {
			/**
			 * Filters the number of custom fields to retrieve for the drop-down
			 * in the Custom Fields meta box.
			 *
			 * @since 2.1.0
			 *
			 * @param int $limit Number of custom fields to retrieve. Default 30.
			 */
			$limit = apply_filters( 'postmeta_form_limit', 30 );
			$sql   = "SELECT DISTINCT meta_key
			FROM $wpdb->postmeta
			WHERE meta_key NOT BETWEEN '_' AND '_z'
			HAVING meta_key NOT LIKE %s
			ORDER BY meta_key
			LIMIT %d";
			$keys  = $wpdb->get_col( $wpdb->prepare( $sql, $wpdb->esc_like( '_' ) . '%', $limit ) ); // phpcs:ignore -- unprepared SQL ok.
		}

		if ( $keys ) {
			natcasesort( $keys );
			$meta_key_input_id = 'metakeyselect';
		} else {
			$meta_key_input_id = 'metakeyinput';
		}
		?>

		<div class="two column row" id="mainwp-metaform-row">
			<div class="column">
				<label for="<?php echo $meta_key_input_id; ?>"><?php esc_html_e( 'Name', 'mainwp' ); ?></label>
				<?php if ( $keys ) { ?>
					<select id="metakeyselect" name="metakeyselect">
						<option value="#NONE#"><?php esc_html_e( '&mdash; Select &mdash;', 'mainwp' ); ?></option>
						<?php
						foreach ( $keys as $key ) {
							if ( is_protected_meta( $key, 'post' ) || ! current_user_can( 'add_post_meta', $_post->ID, $key ) ) {
								continue;
							}
							echo "\n<option value='" . esc_attr( $key ) . "'>" . esc_html( $key ) . '</option>';
						}
						?>
					</select>
					<input class="hide-if-js" type="text" id="metakeyinput" name="metakeyinput" value="" />
					<a href="#postcustomstuff" class="hide-if-no-js" onclick="jQuery( '#metakeyinput, #metakeyselect, #enternew, #cancelnew' ).toggle();return false;">
						<span id="enternew"><?php esc_html_e( 'Enter new', 'mainwp' ); ?></span>
						<span id="cancelnew" class="hidden"><?php esc_html_e( 'Cancel', 'mainwp' ); ?></span>
					</a>
				<?php } else { ?>
					<input type="text" id="metakeyinput" name="metakeyinput" value="" />
				<?php } ?>
			</div>
			<div class="column">
				<label for="metavalue"><?php esc_html_e( 'Value', 'mainwp' ); ?></label>
				<textarea id="metavalue" name="metavalue" rows="2" cols="25"></textarea>
			</div>
		</div>
		<div class="two column row">
			<div class="column">
				<input type="button" onclick="mainwp_post_newmeta_submit( 'add' )" class="ui mini button" value="<?php esc_attr_e( 'Add Custom Field', 'mainwp' ); ?>">
			</div>
			<div class="column"></div>
		</div>
		<?php
	}

	/**
	 * Method post_custom_meta_box()
	 *
	 * Display custom fields form fields.
	 *
	 * @since 2.6.0
	 *
	 * @param object $post Current post object.
	 */
	public static function post_custom_meta_box( $post ) {
		?>
		<div class="ui secondary segment">
			<div class="ui header"><?php esc_html_e( 'Custom Fields', 'mainwp' ); ?></div>
			<div class="ui grid">
			<?php
			$metadata = has_meta( $post->ID );
			foreach ( $metadata as $key => $value ) {
				if ( is_protected_meta( $metadata[ $key ]['meta_key'], 'post' ) || ! current_user_can( 'edit_post_meta', $post->ID, $metadata[ $key ]['meta_key'] ) ) {
					unset( $metadata[ $key ] );
				}
			}

			$count = 0;
			if ( $metadata ) {
				foreach ( $metadata as $entry ) {
					echo self::list_meta_row( $entry, $count );
				}
			}

			self::meta_form( $post );
			?>
			</div>
		</div>
		<?php
	}

	/**
	 * Output HTML for the post thumbnail meta-box.
	 *
	 * @since 2.9.0
	 *
	 * @param int   $thumbnail_id ID of the attachment used for thumbnail.
	 * @param mixed $pos The post ID or object associated with the thumbnail, defaults to global $post.
	 * @return string html
	 */
	public static function wp_post_thumbnail_html( $thumbnail_id = null, $pos = null ) {

		$_wp_additional_image_sizes = wp_get_additional_image_sizes();

		$_post            = get_post( $pos );
		$post_type_object = get_post_type_object( $_post->post_type );

		$thumb_ok          = false;
		$upload_iframe_src = get_upload_iframe_src( 'image', $_post->ID );

		if ( $thumbnail_id && get_post( $thumbnail_id ) ) {
			$size = isset( $_wp_additional_image_sizes['post-thumbnail'] ) ? 'post-thumbnail' : array( 266, 266 );

			/**
			 * Filters the size used to display the post thumbnail image in the 'Featured Image' meta box.
			 *
			 * Note: When a theme adds 'post-thumbnail' support, a special 'post-thumbnail'
			 * image size is registered, which differs from the 'thumbnail' image size
			 * managed via the Settings > Media screen. See the `$size` parameter description
			 * for more information on default values.
			 *
			 * @since 4.4.0
			 *
			 * @param string|array $size         Post thumbnail image size to display in the meta box. Accepts any valid
			 *                                   image size, or an array of width and height values in pixels (in that order).
			 *                                   If the 'post-thumbnail' size is set, default is 'post-thumbnail'. Otherwise,
			 *                                   default is an array with 266 as both the height and width values.
			 * @param int          $thumbnail_id Post thumbnail attachment ID.
			 * @param WP_Post      $_post         The post object associated with the thumbnail.
			 */
			$size = apply_filters( 'admin_post_thumbnail_size', $size, $thumbnail_id, $_post );

			$thumbnail_html = wp_get_attachment_image( $thumbnail_id, $size );

			if ( ! empty( $thumbnail_html ) ) {
				$thumb_ok = true;

				$set_thumbnail_link = '<p class="hide-if-no-js"><div class="field"><a href="%s" id="set-post-thumbnail"%s class="thickbox">%s</a></div></p>';
				$content            = '<div class="ui setment">';
				$content           .= sprintf(
					$set_thumbnail_link,
					esc_url( $upload_iframe_src ),
					' aria-describedby="set-post-thumbnail-desc"',
					$thumbnail_html
				);
				$content           .= '<p class="hide-if-no-js howto" id="set-post-thumbnail-desc">' . __( 'Click the image to edit or update', 'mainwp' ) . '</p>';
				$content           .= '<p class="hide-if-no-js"><a href="#" id="remove-post-thumbnail">' . esc_html( $post_type_object->labels->remove_featured_image ) . '</a></p>';
				$content           .= '</div>';
			}
		}

		if ( ! $thumb_ok ) {
			$set_thumbnail_link = '<p class="hide-if-no-js"><div class="field"><a href="%s" class="ui button fluid mini" id="set-post-thumbnail"%s class="thickbox">%s</a></div></p>';
			$content            = sprintf(
				$set_thumbnail_link,
				esc_url( $upload_iframe_src ),
				'',
				esc_html( $post_type_object->labels->set_featured_image )
			);
		}

		$content .= '<input type="hidden" id="_thumbnail_id" name="_thumbnail_id" value="' . esc_attr( $thumbnail_id ? $thumbnail_id : '-1' ) . '" />';

		$html  = '<div class="ui header">' . __( 'Featured Image', 'mainwp' ) . '</div>';
		$html .= $content;

		/**
		 * Filters the admin post thumbnail HTML markup to return.
		 *
		 * @since 2.9.0
		 * @since 3.5.0 Added the `$post_id` parameter.
		 * @since 4.6.0 Added the `$thumbnail_id` parameter.
		 *
		 * @param string $content      Admin post thumbnail HTML markup.
		 * @param int    $post_id      Post ID.
		 * @param int    $thumbnail_id Thumbnail ID.
		 */
		return apply_filters( 'mainwp_admin_post_thumbnail_html', $html, $_post->ID, $thumbnail_id );
	}

	/**
	 * Method post_thumbnail_meta_box()
	 *
	 * Renders the post thumbnail meta box.
	 *
	 * @param mixed $pos Post ID.
	 */
	public static function post_thumbnail_meta_box( $pos ) {
		$thumbnail_id = get_post_meta( $pos->ID, '_thumbnail_id', true );
		echo self::wp_post_thumbnail_html( $thumbnail_id, $pos->ID );
	}

	/**
	 * Method touch_time()
	 *
	 * Add time stamps to Post for screen readers.
	 *
	 * @param object  $post Current Post object.
	 * @param integer $edit Whether or not this is an edit or new post.
	 * @param integer $for_post For post.
	 * @param integer $tab_index Tabindex position.
	 * @param integer $multi Multi.
	 *
	 * @return string Hidden time stamps html.
	 */
	public static function touch_time( $post, $edit = 1, $for_post = 1, $tab_index = 0, $multi = 0 ) {

		/**
		 * WordPress Locale.
		 *
		 * @global string
		 */
		global $wp_locale;

		$_post = get_post( $post );

		if ( $for_post ) {
			$edit = ! ( in_array( $_post->post_status, array( 'draft', 'pending' ) ) && ( ! $_post->post_date_gmt || '0000-00-00 00:00:00' == $post->post_date_gmt ) );
		}

		$tab_index_attribute = '';

		if ( 0 < (int) $tab_index ) {
			$tab_index_attribute = " tabindex=\"$tab_index\"";
		}

		$post_date = ( $for_post ) ? $post->post_date : get_comment()->comment_date;
		$jj        = ( $edit ) ? mysql2date( 'd', $post_date, false ) : current_time( 'd' );
		$mm        = ( $edit ) ? mysql2date( 'm', $post_date, false ) : current_time( 'm' );
		$aa        = ( $edit ) ? mysql2date( 'Y', $post_date, false ) : current_time( 'Y' );
		$hh        = ( $edit ) ? mysql2date( 'H', $post_date, false ) : current_time( 'H' );
		$mn        = ( $edit ) ? mysql2date( 'i', $post_date, false ) : current_time( 'i' );
		$ss        = ( $edit ) ? mysql2date( 's', $post_date, false ) : current_time( 's' );

		$cur_jj = current_time( 'd' );
		$cur_mm = current_time( 'm' );
		$cur_aa = current_time( 'Y' );
		$cur_hh = current_time( 'H' );
		$cur_mn = current_time( 'i' );

		$month = '<label><span class="screen-reader-text">' . __( 'Month', 'mainwp' ) . '</span><select ' . ( $multi ? '' : 'id="mm" ' ) . 'name="mm"' . $tab_index_attribute . ">\n";

		for ( $i = 1; $i < 13; ++$i ) {
			$monthnum  = zeroise( $i, 2 );
			$monthtext = $wp_locale->get_month_abbrev( $wp_locale->get_month( $i ) );
			$month    .= "\t\t\t" . '<option value="' . $monthnum . '" data-text="' . $monthtext . '" ' . selected( $monthnum, $mm, false ) . '>';
			$month    .= sprintf( __( '%1$s-%2$s', 'mainwp' ), $monthnum, $monthtext ) . "</option>\n";
		}

		$month .= '</select></label>';

		$day    = '<label><span class="screen-reader-text">' . __( 'Day', 'mainwp' ) . '</span><input type="text" ' . ( $multi ? '' : 'id="jj" ' ) . 'name="jj" value="' . $jj . '" size="2" maxlength="2"' . $tab_index_attribute . ' autocomplete="off" /></label>';
		$year   = '<label><span class="screen-reader-text">' . __( 'Year', 'mainwp' ) . '</span><input type="text" ' . ( $multi ? '' : 'id="aa" ' ) . 'name="aa" value="' . $aa . '" size="4" maxlength="4"' . $tab_index_attribute . ' autocomplete="off" /></label>';
		$hour   = '<label><span class="screen-reader-text">' . __( 'Hour', 'mainwp' ) . '</span><input type="text" ' . ( $multi ? '' : 'id="hh" ' ) . 'name="hh" value="' . $hh . '" size="2" maxlength="2"' . $tab_index_attribute . ' autocomplete="off" /></label>';
		$minute = '<label><span class="screen-reader-text">' . __( 'Minute', 'mainwp' ) . '</span><input type="text" ' . ( $multi ? '' : 'id="mn" ' ) . 'name="mn" value="' . $mn . '" size="2" maxlength="2"' . $tab_index_attribute . ' autocomplete="off" /></label>';

		echo '<div class="timestamp-wrap">';
		printf( __( '%1$s %2$s, %3$s @ %4$s:%5$s', 'mainwp' ), $month, $day, $year, $hour, $minute );

		echo '</div><input type="hidden" id="ss" name="ss" value="' . $ss . '" />';

		if ( $multi ) {
			return;
		}

		echo "\n\n";
		$map = array(
			'mm' => array( $mm, $cur_mm ),
			'jj' => array( $jj, $cur_jj ),
			'aa' => array( $aa, $cur_aa ),
			'hh' => array( $hh, $cur_hh ),
			'mn' => array( $mn, $cur_mn ),
		);

		foreach ( $map as $timeunit => $value ) {
			list( $unit, $curr ) = $value;

			echo '<input type="hidden" id="hidden_' . $timeunit . '" name="hidden_' . $timeunit . '" value="' . $unit . '" />' . "\n";
			$cur_timeunit = 'cur_' . $timeunit;
			echo '<input type="hidden" id="' . $cur_timeunit . '" name="' . $cur_timeunit . '" value="' . $curr . '" />' . "\n";
		}
	}

	/**
	 * Method do_meta_boxes()
	 *
	 * Render meta boxes.
	 *
	 * @param mixed $screen Current page tab.
	 * @param mixed $context Context.
	 * @param mixed $object Object.
	 *
	 * @return string Metabox html
	 */
	public static function do_meta_boxes( $screen, $context, $object ) { // phpcs:ignore -- current complexity required to achieve desired results. Purll Request solutions appreciated.

		/**
		 * WordPress Meta Boxes array.
		 *
		 * @global object
		 */
		global $wp_meta_boxes;
		static $already_sorted = false;

		if ( empty( $screen ) ) {
			$screen = get_current_screen();
		} elseif ( is_string( $screen ) ) {
			$screen = convert_to_screen( $screen );
		}

			$page = $screen->id;

		if ( 'mainwp_page_PostBulkAdd' === $page || 'mainwp_page_PostBulkEdit' === $page ) {
			$page = 'bulkpost';
		} elseif ( 'mainwp_page_PageBulkAdd' === $page || 'mainwp_page_PageBulkEdit' === $page ) {
			$page = 'bulkpage';
		}

		$hidden = get_hidden_meta_boxes( $screen );

		printf( '<div id="%s-sortables" class="meta-box-sortables">', esc_attr( $context ) );

		$sorted = get_user_option( "meta-box-order_$page" );
		if ( ! $already_sorted && $sorted ) {
			foreach ( $sorted as $widget_context => $ids ) {
				foreach ( explode( ',', $ids ) as $id ) {
					if ( $id && 'dashboard_browser_nag' !== $id ) {
						add_meta_box( $id, null, null, $screen, $widget_context, 'sorted' );
					}
				}
			}
		}

		$already_sorted = true;

		$i = 0;

		if ( isset( $wp_meta_boxes[ $page ][ $context ] ) ) {
			foreach ( array( 'high', 'sorted', 'core', 'default', 'low' ) as $priority ) {
				if ( isset( $wp_meta_boxes[ $page ][ $context ][ $priority ] ) ) {
					foreach ( (array) $wp_meta_boxes[ $page ][ $context ][ $priority ] as $box ) {
						if ( false == $box || ! $box['title'] ) {
							continue;
						}

						$block_compatible = true;
						if ( is_array( $box['args'] ) ) {
							if ( $screen->is_block_editor() && isset( $box['args']['__back_compat_meta_box'] ) && $box['args']['__back_compat_meta_box'] ) {
								continue;
							}

							if ( isset( $box['args']['__block_editor_compatible_meta_box'] ) ) {
								$block_compatible = (bool) $box['args']['__block_editor_compatible_meta_box'];
								unset( $box['args']['__block_editor_compatible_meta_box'] );
							}

							if ( ! $block_compatible && $screen->is_block_editor() ) {
								$box['old_callback'] = $box['callback'];
								$box['callback']     = 'do_block_editor_incompatible_meta_box';
							}

							if ( isset( $box['args']['__back_compat_meta_box'] ) ) {
								$block_compatible = $block_compatible || (bool) $box['args']['__back_compat_meta_box'];
								unset( $box['args']['__back_compat_meta_box'] );
							}
						}

						$i++;
						echo '<div id="' . $box['id'] . '" class="postbox" >' . "\n";
						if ( 'dashboard_browser_nag' !== $box['id'] ) {
							$widget_title = $box['title'];

							if ( is_array( $box['args'] ) && isset( $box['args']['__widget_basename'] ) ) {
								$widget_title = $box['args']['__widget_basename'];
								unset( $box['args']['__widget_basename'] );
							}

							echo '<button type="button" class="handlediv" aria-expanded="true">';
							echo '<span class="screen-reader-text">' . sprintf( __( 'Toggle panel: %s', 'mainwp' ), $widget_title ) . '</span>';
							echo '<span class="toggle-indicator" aria-hidden="true"></span>';
							echo '</button>';
						}
						echo "<h2 class='hndle'><span>{$box['title']}</span></h2>\n";
						echo '<div class="inside">' . "\n";

						if ( WP_DEBUG && ! $block_compatible && 'edit' === $screen->parent_base && ! $screen->is_block_editor() && ! isset( $_GET['meta-box-loader'] ) ) {
							$plugin = _get_plugin_from_callback( $box['callback'] );
							if ( $plugin ) {
								?>
									<div class="error inline">
										<p>
										<?php
											printf( __( 'This meta box, from the %s plugin, is not compatible with the block editor.', 'mainwp' ), "<strong>{$plugin['Name']}</strong>" );
										?>
										</p>
									</div>
								<?php
							}
						}

						call_user_func( $box['callback'], $object, $box );
						echo "</div>\n";
						echo "</div>\n";
					}
				}
			}
		}

		echo '</div>';

		return $i;
	}

	/**
	 * Renders bulkpost to edit.
	 *
	 * @param mixed $post_id Post ID.
	 * @param mixed $input_type Post type.
	 *
	 * @return string Edit bulk post html.
	 */
	public static function render_bulkpost( $post_id, $input_type ) {
		$post = get_post( $post_id );

		if ( $post ) {
			$post_type        = $post->post_type;
			$post_type_object = get_post_type_object( $post_type );
		}

		if ( ! $post_type_object || $input_type !== $post_type || ( 'bulkpost' !== $post_type && 'bulkpage' !== $post_type ) ) {
			esc_html_e( 'Invalid post type.', 'mainwp' );
			return;
		}

		$post_ID = $post->ID;

		/**
		 * Current user global.
		 *
		 * @global string
		 */
		global $current_user;

		$user_ID = $current_user->ID;

		$_content_editor_dfw = false;
		$is_IE               = false;
		$_wp_editor_expand   = true;

		$form_action  = 'mainwp_editpost';
		$nonce_action = 'update-post_' . $post_ID;

		$form_extra = "<input type='hidden' id='post_ID' name='post_ID' value='" . esc_attr( $post_ID ) . "' />";

		$referer = wp_get_referer();

		if ( 'auto-draft' === $post->post_status ) {
			$note_title = ( 'bulkpost' === $post_type ) ? __( 'Create New Bulkpost', 'mainwp ' ) : __( 'Create New Bulkpage', 'mainwp' );
		} else {
			$note_title = ( 'bulkpost' === $post_type ) ? __( 'Edit Bulkpost', 'mainwp' ) : __( 'Edit Bulkpage', 'mainwp' );
		}
		$message = '';
		if ( isset( $_GET['message'] ) && 1 == $_GET['message'] ) {
			if ( 'bulkpost' === $post_type ) {
				$message = __( 'Post updated.', 'mainwp' );
			} else {
				$message = __( 'Page updated.', 'mainwp' );
			}
		}

		?>
		<div class="ui alt segment" id="mainwp-add-new-bulkpost">
			<form name="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" method="post" id="post" class="ui form">
				<?php wp_nonce_field( $nonce_action ); ?>
				<input type="hidden" id="user-id" name="user_ID" value="<?php echo (int) $user_ID; ?>" />
				<input type="hidden" id="hiddenaction" name="action" value="<?php echo esc_attr( $form_action ); ?>" />
				<input type="hidden" id="originalaction" name="originalaction" value="<?php echo esc_attr( $form_action ); ?>" />
				<input type="hidden" id="post_author" name="post_author" value="<?php echo esc_attr( $post->post_author ); ?>" />
				<input type="hidden" id="post_type" name="post_type" value="<?php echo esc_attr( $post_type ); ?>" />
				<input type="hidden" id="original_post_status" name="original_post_status" value="<?php echo esc_attr( $post->post_status ); ?>" />
				<input type="hidden" id="referredby" name="referredby" value="<?php echo $referer ? esc_url( $referer ) : ''; ?>" />
				<?php
				if ( 'draft' !== get_post_status( $post ) ) {
					wp_original_referer_field( true, 'previous' );
				}
				echo $form_extra;
				?>
				<div class="mainwp-main-content">
					<div class="ui red message" id="mainwp-message-zone" style="display:none"></div>
					<?php
					if ( $message ) {
						?>
							<div class="ui yellow message"><?php echo esc_html( $message ); ?></div>
						<?php
					}
					?>
					<h3 class="header"><?php echo esc_html( $note_title ); ?></h3>
					<div class="field">
						<label><?php esc_html_e( 'Title', 'mainwp' ); ?></label>
						<input type="text" name="post_title" id="title"  value="<?php echo ( 'Auto Draft' !== $post->post_title ) ? esc_attr( $post->post_title ) : ''; ?>" value="" autocomplete="off" spellcheck="true">
					</div>
					<div class="field">
						<div id="postdivrich" class="postarea
						<?php
						if ( $_wp_editor_expand ) {
							echo ' wp-editor-expand'; }
						?>
						">
							<?php
							remove_editor_styles();
							wp_editor(
								$post->post_content,
								'content',
								array(
									'_content_editor_dfw' => $_content_editor_dfw,
									'drag_drop_upload'    => true,
									'tabfocus_elements'   => 'content-html,save-post',
									'editor_height'       => 300,
									'tinymce'             => array(
										'resize'           => false,
										'wp_autoresize_on' => $_wp_editor_expand,
										'add_unload_trigger' => false,
										'wp_keep_scroll_position' => ! $is_IE,
									),
								)
							);

							?>

							<table id="post-status-info"><tbody><tr>
								<td id="wp-word-count" class="hide-if-no-js"><?php printf( __( 'Word count: %s', 'mainwp' ), '<span class="word-count">0</span>' ); ?></td>
								<td class="autosave-info">
								<span class="autosave-message">&nbsp;</span>
							<?php
							if ( 'auto-draft' !== $post->post_status ) {
								echo '<span id="last-edit">';
								$last_user = get_userdata( get_post_meta( $post_ID, '_edit_last', true ) );
								if ( $last_user ) {
									printf( __( 'Last edited by %1$s on %2$s at %3$s', 'mainwp' ), esc_html( $last_user->display_name ), mysql2date( __( 'F j, Y' ), $post->post_modified ), mysql2date( __( 'g:i a' ), $post->post_modified ) );
								} else {
									printf( __( 'Last edited on %1$s at %2$s', 'mainwp' ), mysql2date( __( 'F j, Y' ), $post->post_modified ), mysql2date( __( 'g:i a' ), $post->post_modified ) );
								}
								echo '</span>';
							}
							?>
								</td>
								<td id="content-resize-handle" class="hide-if-no-js"><br /></td>
							</tr></tbody></table>
						</div>
					</div>
					<div class="field" id="add-slug-div">
						<label><?php esc_html_e( 'Slug', 'mainwp' ); ?></label>
						<?php MainWP_System::instance()->metaboxes->add_slug( $post ); ?>
					</div>
				<?php if ( 'bulkpost' === $post_type ) { ?>
					<div class="field">
						<label><?php esc_html_e( 'Excerpt', 'mainwp' ); ?></label>
							<textarea rows="1" name="excerpt" id="excerpt"></textarea>
						<em><?php esc_html_e( 'Excerpts are optional hand-crafted summaries of your content that can be used in your theme.', 'mainwp' ); ?></em>
					</div>
					<div class="field">
						<label><?php esc_html_e( 'Tags', 'mainwp' ); ?></label>
						<?php MainWP_System::instance()->metaboxes->add_tags( $post ); ?>
						<em><?php esc_html_e( 'Separate tags with commas', 'mainwp' ); ?></em>
					</div>
					<?php } ?>
					<div class="field">
				<?php self::post_custom_meta_box( $post ); ?>
				</div>

				<div class="field postbox-container">
				<?php

				/**
				 * Edit bulkpost
				 *
				 * First on the Edit post screen after default fields.
				 *
				 * @param object $post      Object containing the Post data.
				 * @param string $post_type Post type.
				 */
				do_action( 'mainwp_bulkpost_edit', $post, $post_type );

				self::do_meta_boxes( null, 'normal', $post );

				self::do_meta_boxes( null, 'advanced', $post );

				/**
				 * Edit bulkpost metaboxes
				 *
				 * Fires after all built-in meta boxes have been added.
				 *
				 * @param object $post      Object containing the Post data.
				 * @param string $post_type Post type.
				 *
				 * @see https://developer.wordpress.org/reference/hooks/add_meta_boxes/
				 */
				do_action( 'add_meta_boxes', $post_type, $post );

				self::do_meta_boxes( $post_type, 'normal', $post );

				?>
				</div>
			</div>
				<?php
				$sel_sites  = array();
				$sel_groups = array();
				?>
				<div class="mainwp-side-content mainwp-no-padding">
					<div class="mainwp-select-sites">
						<div class="ui header"><?php esc_html_e( 'Select Sites', 'mainwp' ); ?></div>
						<?php MainWP_UI::select_sites_box( 'checkbox', true, true, '', '', $sel_sites, $sel_groups, false, $post_ID ); ?>
						<input type="hidden" name="select_sites_nonce" id="select_sites_nonce" value="<?php echo wp_create_nonce( 'select_sites_' . $post->ID ); ?>" />
					</div>
					<div class="ui divider"></div>
					<?php
					if ( 'bulkpost' === $post_type ) {
						self::render_categories( $post );
					}
					self::render_post_fields( $post, $post_type );
					?>
					<?php self::do_meta_boxes( $post_type, 'side', $post ); ?>
					<div class="ui divider"></div>
					<?php
					/**
					 * Action: mainwp_edit_posts_before_submit_button
					 *
					 * Fires right before the Submit button.
					 *
					 * @since 4.0
					 */
					do_action( 'mainwp_edit_posts_before_submit_button' );
					?>
					<div class="mainwp-search-submit" id="bulkpost-publishing-action">
						<input type="submit" name="publish" id="publish" class="ui big green fluid button" value="<?php esc_attr_e( 'Publish', 'mainwp' ); ?>">
					</div>
					<?php
					/**
					 * Action: mainwp_edit_posts_after_submit_button
					 *
					 * Fires right after the Submit button.
					 *
					 * @since 4.0
					 */
					do_action( 'mainwp_edit_posts_after_submit_button' );
					?>
				</div>
			</form>
			<script type="text/javascript">
			jQuery( document ).ready( function () {
				jQuery( '#publish' ).on( 'click', function() {
					jQuery( '#mainwp-publish-dimmer' ).show();
				} );
			} );
			</script>
			<div class="ui active inverted dimmer" id="mainwp-publish-dimmer" style="display:none">
				<div class="ui text loader"><?php esc_html_e( 'Publishing...', 'mainwp' ); ?></div>
			</div>
			<div class="ui clearing hidden divider"></div>
		</div>
		<?php
		self::render_footer( 'BulkAdd' );
	}

	/**
	 * Renders Select Categories form
	 *
	 * @param object $post Post object.
	 */
	public static function render_categories( $post ) {
		?>
		<div class="mainwp-search-options">
			<div class="ui header"><?php esc_html_e( 'Select Categories', 'mainwp' ); ?></div>
		<?php
		$categories = array();
		if ( $post ) {
			$categories = base64_decode( get_post_meta( $post->ID, '_categories', true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
			$categories = explode( ',', $categories );
		}
		if ( ! is_array( $categories ) ) {
			$categories = array();
		}

		$uncat     = __( 'Uncategorized', 'mainwp' );
		$post_only = false;
		if ( $post ) {
			$post_only = get_post_meta( $post->ID, '_post_to_only_existing_categories', true );
		}
		?>
			<input type="hidden" name="post_category_nonce" id="post_category_nonce" value="<?php echo esc_attr( wp_create_nonce( 'post_category_' . $post->ID ) ); ?>" />
			<div class="field">
				<div class="ui checkbox">
					<input type="checkbox" name="post_only_existing" id="post_only_existing" value="1" <?php echo $post_only ? 'checked' : ''; ?>>
					<label><?php esc_html_e( 'Post only to existing categories', 'mainwp' ); ?></label>
				</div>
			</div>
			<div class="field">
				<select name="post_category[]" id="categorychecklist" multiple="" class="ui fluid dropdown">
					<option value=""><?php esc_html_e( 'Select categories', 'mainwp' ); ?></option>
					<?php if ( ! in_array( $uncat, $categories ) ) : ?>
					<option value="<?php esc_attr_e( 'Uncategorized', 'mainwp' ); ?>" class="sitecategory"><?php esc_html_e( 'Uncategorized', 'mainwp' ); ?></option>
					<?php endif; ?>
					<?php foreach ( $categories as $cat ) : ?>
						<?php
						if ( empty( $cat ) ) {
							continue;
						}
						$cat_name = rawurldecode( $cat );
						?>
					<option value="<?php echo esc_attr( $cat ); ?>" class="sitecategory"><?php echo esc_html( $cat_name ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php
				$init_cats = '';
				foreach ( $categories as $cat ) {
					$init_cats .= "'" . esc_attr( $cat ) . "',";
				}
				$init_cats = rtrim( $init_cats, ',' );
				?>
				<script type="text/javascript">
					jQuery( document ).ready( function () {
						jQuery( '#categorychecklist' ).dropdown( 'set selected', [<?php echo $init_cats; ?>] );
					} );
				</script>
			</div>
			<div class="field">
				<a href="#" id="category-add-toggle" class="ui button fluid mini"><?php esc_html_e( 'Create New Category', 'mainwp' ); ?></a>
			</div>
			<div class="field" id="newcategory-field" style="display:none">
				<input type="text" name="newcategory" id="newcategory" value="">
			</div>
			<div class="field" id="mainwp-category-add-submit-field" style="display:none">
				<input type="button" id="mainwp-category-add-submit" class="ui fluid basic green mini button" value="<?php esc_attr_e( 'Add New Category', 'mainwp' ); ?>">
			</div>
		</div>
		<div class="ui divider"></div>
		<?php
	}

	/**
	 * Method render_post_fields()
	 *
	 * Render Featured Image & Post Options fields.
	 *
	 * @param object $post Post object.
	 * @param mixed  $post_type Post type.
	 */
	public static function render_post_fields( $post, $post_type ) {
		?>
		<div class="mainwp-search-options mainwp-post-featured-image" id="postimagediv">
				<?php echo '<div class="inside">'; ?>
			<?php self::post_thumbnail_meta_box( $post ); ?>
				<?php echo '</div>'; ?>
			</div>
			<div class="ui divider"></div>
			<div class="mainwp-search-options">
				<div class="ui header"><?php esc_html_e( 'Discussion', 'mainwp' ); ?></div>
				<div class="field">
					<div class="ui checkbox">
						<input type="checkbox" name="comment_status" id="comment_status" value="open" <?php checked( $post->comment_status, 'open' ); ?>>
						<label><?php esc_html_e( 'Allow comments', 'mainwp' ); ?></label>
					</div>
					<div class="ui checkbox">
						<input type="checkbox" name="ping_status" id="ping_status" value="open" <?php checked( $post->ping_status, 'open' ); ?> >
						<label><?php esc_html_e( 'Allow trackbacks and pingbacks', 'mainwp' ); ?></label>
					</div>
				</div>
			</div>
			<div class="ui divider"></div>
			<div class="mainwp-search-options">
				<div class="ui header"><?php esc_html_e( 'Publish Options', 'mainwp' ); ?></div>
				<div class="field">
					<label><?php esc_html_e( 'Status', 'mainwp' ); ?></label>
					<select class="ui dropdown" name="mainwp_edit_post_status" id="post_status">
						<option value="publish" <?php echo ( 'publish' === $post->post_status ) ? 'selected="selected"' : ''; ?>><?php esc_html_e( 'Publish', 'mainwp' ); ?></option>
						<option value="draft" <?php echo ( 'draft' === $post->post_status ) ? 'selected="selected"' : ''; ?>><?php esc_html_e( 'Draft', 'mainwp' ); ?></option>
						<option value="pending" <?php echo ( 'pending' === $post->post_status ) ? 'selected="selected"' : ''; ?>><?php esc_html_e( 'Pending review', 'mainwp' ); ?></option>
					</select>
				</div>

				<?php
				if ( 'private' === $post->post_status ) {
					$post->post_password = '';
					$visibility          = 'private';
					$visibility_trans    = __( 'Private', 'mainwp' );
				} elseif ( ! empty( $post->post_password ) ) {
					$visibility       = 'password';
					$visibility_trans = __( 'Password protected', 'mainwp' );
				} elseif ( 'post' === $post_type && is_sticky( $post->ID ) ) {
					$visibility       = 'public';
					$visibility_trans = __( 'Public, Sticky', 'mainwp' );
				} else {
					$visibility       = 'public';
					$visibility_trans = __( 'Public', 'mainwp' );
				}
				?>

				<div class="grouped fields">
					<label><?php esc_html_e( 'Visibility', 'mainwp' ); ?></label>
					<div class="field">
						<div class="ui radio checkbox">
							<input type="radio" name="visibility" value="public" id="visibility-radio-public" <?php echo ( 'public' === $visibility ) ? 'checked="checked"' : ''; ?>>
							<label><?php esc_html_e( 'Public', 'mainwp' ); ?></label>
						</div>
					</div>
					<div class="field" id="sticky-field">
						<div class="ui checkbox">
							<input type="checkbox" id="sticky" name="sticky" value="sticky"  <?php checked( is_sticky( $post->ID ) ); ?>  />
							<label><?php esc_html_e( 'Stick this post to the front page', 'mainwp' ); ?></label>
						</div>
					</div>
					<div class="field">
						<div class="ui radio checkbox">
							<input type="radio" name="visibility" value="password" id="visibility-radio-password" <?php echo ( 'password' === $visibility ) ? 'checked="checked"' : ''; ?>>
							<label><?php esc_html_e( 'Password protected', 'mainwp' ); ?></label>
						</div>
					</div>
					<div class="field" id="post_password-field" <?php echo ( 'password' === $visibility ) ? '' : 'style="display:none"'; ?>>
						<label><?php esc_html_e( 'Password', 'mainwp' ); ?></label>
						<input type="text" name="post_password" id="post_password" value="<?php echo esc_attr( $post->post_password ); ?>" />
					</div>
					<div class="field">
						<div class="ui radio checkbox">
							<input type="radio" name="visibility" value="private" id="visibility-radio-private" <?php echo ( 'private' === $visibility ) ? 'checked="checked"' : ''; ?>>
							<label><?php esc_html_e( 'Private', 'mainwp' ); ?></label>
						</div>
					</div>
				</div>
				<div class="field">
					<label><?php esc_html_e( 'Publish', 'mainwp' ); ?></label>
					<select class="ui dropdown" name="post_timestamp" id="post_timestamp">
						<option value="immediately" selected="selected"><?php esc_html_e( 'Immediately', 'mainwp' ); ?></option>
						<option value="schedule"><?php esc_html_e( 'Schedule', 'mainwp' ); ?></option>
					</select>
				</div>

				<div class="field" id="post_timestamp_value-field" style="display:none">
					<div class="ui calendar mainwp_datepicker" id="schedule_post_datetime" >
						<div class="ui input left icon">
							<i class="calendar icon"></i>
							<input type="text" placeholder="<?php esc_attr_e( 'Date', 'mainwp' ); ?>" id="post_timestamp_value" value="" />
						</div>
					</div>
				</div>
				<div style="display:none" id="timestampdiv">
					<?php self::touch_time( $post ); ?>
				</div>
			</div>
			<?php
	}

	/**
	 * Method render_bulk_add()
	 *
	 * Check if user has rights,
	 * render the bulk add tab.
	 *
	 * @return string Bulk add tab html.
	 */
	public static function render_bulk_add() {
		if ( ! mainwp_current_user_have_right( 'dashboard', 'manage_posts' ) ) {
			mainwp_do_not_have_permissions( __( 'manage posts', 'mainwp' ) );
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
	 * Check if user has rights,
	 * render the bulk edit tab.
	 *
	 * @return string Bulk edit tab html.
	 */
	public static function render_bulk_edit() {
		if ( ! mainwp_current_user_have_right( 'dashboard', 'manage_posts' ) ) {
			mainwp_do_not_have_permissions( __( 'manage posts', 'mainwp' ) );
			return;
		}
		$post_id = isset( $_GET['post_id'] ) ? intval( $_GET['post_id'] ) : 0;
		self::render_addedit( $post_id, 'BulkEdit' );
	}

	/**
	 * Method render_bulk_addedit()
	 *
	 * Render both the Add & Edit tabs.
	 *
	 * @param mixed $post_id post ID.
	 * @param mixed $what what tab is active.
	 */
	public static function render_addedit( $post_id, $what ) {
		self::render_header( $what, $post_id );
		self::render_bulkpost( $post_id, 'bulkpost' );
		self::render_footer( $what );
	}

	/**
	 * Method hook_posts_search_handler()
	 *
	 * Hook Posts Search handler.
	 *
	 * @param mixed  $data search data.
	 * @param object $website child site object.
	 * @param mixed  $output output.
	 */
	public static function hook_posts_search_handler( $data, $website, &$output ) {
		$posts = array();
		if ( 0 < preg_match( '/<mainwp>(.*)<\/mainwp>/', $data, $results ) ) {
			$result = $results[1];
			$posts  = MainWP_System_Utility::get_child_response( base64_decode( $result ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
			unset( $results );
		}
		$output->results[ $website->id ] = $posts;
	}

	/**
	 * Method mainwp_help_content()
	 *
	 * Attatch MainWP help content.
	 */
	public static function mainwp_help_content() {
		if ( isset( $_GET['page'] ) && ( 'PostBulkManage' === $_GET['page'] || 'PostBulkAdd' === $_GET['page'] ) ) {
			?>
			<p><?php esc_html_e( 'If you need help with managing posts, please review following help documents', 'mainwp' ); ?></p>
			<div class="ui relaxed bulleted list">
				<div class="item"><a href="https://mainwp.com/help/docs/manage-posts/" target="_blank">Manage Posts</a></div>
				<div class="item"><a href="https://mainwp.com/help/docs/manage-posts/create-a-new-post/" target="_blank">Create a New Post</a></div>
				<div class="item"><a href="https://mainwp.com/help/docs/manage-posts/edit-an-existing-post/" target="_blank">Edit an Existing Post</a></div>
				<div class="item"><a href="https://mainwp.com/help/docs/manage-posts/change-status-of-an-existing-post/" target="_blank">Change Status of an Existing Post</a></div>
				<div class="item"><a href="https://mainwp.com/help/docs/manage-posts/view-an-existing-post/" target="_blank">View an Existing Post</a></div>
				<div class="item"><a href="https://mainwp.com/help/docs/manage-posts/delete-posts/" target="_blank">Delete Post(s)</a></div>
				<?php
				/**
				 * Action: mainwp_posts_help_item
				 *
				 * Fires at the bottom of the help articles list in the Help sidebar on the Posts page.
				 *
				 * Suggested HTML markup:
				 *
				 * <div class="item"><a href="Your custom URL">Your custom text</a></div>
				 *
				 * @since 4.1
				 */
				do_action( 'mainwp_posts_help_item' );
				?>
			</div>
			<?php
		}
	}

}
