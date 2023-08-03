<?php
/**
 * MainWP Overview Page.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Overview
 *
 * @package MainWP\Dashboard
 */
class MainWP_Overview {

	/**
	 * Get Class Name
	 *
	 * @return string __CLASS__
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * The single instance of the class
	 *
	 * @var mixed Default null
	 */
	protected static $instance = null;

	/**
	 * Screen dashdoard ID value.
	 *
	 * @var int $dashBoard default 0.
	 */
	public $dashBoard = 0;

	/**
	 * Enabled widgets
	 *
	 * @var array $enable_widgets
	 */
	private static $enable_widgets = array(
		'overview'           => true,
		'connection_status'  => true,
		'recent_posts'       => true,
		'recent_pages'       => true,
		'security_issues'    => true,
		'backup_tasks'       => true,
		'non_mainwp_changes' => true,
		'clients'            => true,
	);

	/**
	 * Check if there is a session,
	 * if there isn't one create it.
	 *
	 *  @return self::singlton Overview Page Session.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Overview
	 */
	public static function get() {
		if ( null == self::$instance ) {
			self::$instance = new MainWP_Overview();
		}

		return self::$instance;
	}

	/**
	 * MainWP_Overview constructor.
	 *
	 * Run each time the class is called.
	 */
	public function __construct() {
		add_filter( 'screen_layout_columns', array( &$this, 'on_screen_layout_columns' ), 10, 2 );
		add_action( 'admin_menu', array( &$this, 'on_admin_menu' ) );
		add_action( 'mainwp_help_sidebar_content', array( &$this, 'mainwp_help_content' ) );
	}

	/**
	 * Set the number of page coumns.
	 *
	 * @param mixed $columns Number of Columns.
	 * @param mixed $screen Screen size.
	 *
	 * @return int $columns Number of desired page columns.
	 */
	public function on_screen_layout_columns( $columns, $screen ) {
		if ( $screen == $this->dashBoard ) {
			$columns[ $this->dashBoard ] = 3;
		}

		return $columns;
	}

	/**
	 * Add MainWP Overview top level menu.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::is_admin()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::ctype_digit()
	 */
	public function on_admin_menu() {
		if ( MainWP_System_Utility::is_admin() ) {

			/**
			 * Current user global.
			 *
			 * @global string
			 */
			global $current_user;

			// The icon in Base64 format.
			$icon_base64 = 'PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0idXRmLTgiPz4NCjwhLS0gR2VuZXJhdG9yOiBBZG9iZSBJbGx1c3RyYXRvciAyNi4zLjEsIFNWRyBFeHBvcnQgUGx1Zy1JbiAuIFNWRyBWZXJzaW9uOiA2LjAwIEJ1aWxkIDApICAtLT4NCjxzdmcgdmVyc2lvbj0iMS4xIiBpZD0iTGF5ZXJfMSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIiB4bWxuczp4bGluaz0iaHR0cDovL3d3dy53My5vcmcvMTk5OS94bGluayIgeD0iMHB4IiB5PSIwcHgiDQoJIHZpZXdCb3g9IjAgMCA1MCA1MCIgc3R5bGU9ImVuYWJsZS1iYWNrZ3JvdW5kOm5ldyAwIDAgNTAgNTA7IiB4bWw6c3BhY2U9InByZXNlcnZlIj4NCjxzdHlsZSB0eXBlPSJ0ZXh0L2NzcyI+DQoJLnN0MHtmaWxsOiM5Q0EyQTc7fQ0KPC9zdHlsZT4NCjxwYXRoIGNsYXNzPSJzdDAiIGQ9Ik0yNSwwLjVDMTEuNDcsMC41LDAuNSwxMS40NywwLjUsMjVjMCwxMy41MywxMC45NywyNC41LDI0LjUsMjQuNVM0OS41LDM4LjUzLDQ5LjUsMjUNCglDNDkuNSwxMS40NywzOC41MywwLjUsMjUsMC41eiBNMjUsNDQuNmwtOC4zMy00LjlsNi4wOS0yNS4wN2MtMS41Ny0wLjgyLTIuNjYtMi40NC0yLjY2LTQuMzNjMC0yLjcxLDIuMTktNC45LDQuOS00LjkNCglzNC45LDIuMTksNC45LDQuOWMwLDEuODktMS4wOSwzLjUyLTIuNjYsNC4zM2w2LjA5LDI1LjA3TDI1LDQ0LjZ6Ii8+DQo8L3N2Zz4NCg==';

			// The icon in the data URI scheme.
			$icon_data_uri = 'data:image/svg+xml;base64,' . $icon_base64;

			delete_user_option( $current_user->ID, 'screen_layout_toplevel_page_mainwp_tab' );
			$this->dashBoard = add_menu_page(
				'MainWP',
				'MainWP',
				'read',
				'mainwp_tab',
				array(
					$this,
					'on_show_page',
				),
				$icon_data_uri,
				'2.00001'
			);

			if ( mainwp_current_user_have_right( 'dashboard', 'access_global_dashboard' ) ) {
				add_submenu_page(
					'mainwp_tab',
					'MainWP',
					__( 'Overview', 'mainwp' ),
					'read',
					'mainwp_tab',
					array(
						$this,
						'on_show_page',
					)
				);
			}

			$val = get_user_option( 'screen_layout_' . $this->dashBoard );
			if ( ! MainWP_Utility::ctype_digit( $val ) ) {
				update_user_option( $current_user->ID, 'screen_layout_' . $this->dashBoard, 2, true );
			}
			add_action( 'load-' . $this->dashBoard, array( &$this, 'on_load_page' ) );
			self::init_left_menu();

		}
	}

	/**
	 * Instantiate the MainWP Overview Menu item.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Menu::add_left_menu()
	 */
	public static function init_left_menu() {
		MainWP_Menu::add_left_menu(
			array(
				'title'      => esc_html__( 'Overview', 'mainwp' ),
				'parent_key' => 'mainwp_tab',
				'slug'       => 'mainwp_tab',
				'href'       => 'admin.php?page=mainwp_tab',
				'icon'       => '<i class="th large icon"></i>',
			),
			0
		);
	}

	/**
	 * Method on_load_page()
	 *
	 * Run on page load.
	 */
	public function on_load_page() {
		wp_enqueue_script( 'common' );
		wp_enqueue_script( 'wp-lists' );
		wp_enqueue_script( 'postbox' );
		wp_enqueue_script( 'dashboard' );
		wp_enqueue_script( 'widgets' );

		self::add_meta_boxes( $this->dashBoard );
	}

	/**
	 * Method add_meta_boxes()
	 *
	 * Add MainWP Overview Page Widgets.
	 *
	 * @param array $page Current page.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Handler::apply_filters()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_current_wpid()
	 * @uses \MainWP\Dashboard\MainWP_UI::add_widget_box()
	 * @uses \MainWP\Dashboard\MainWP_Connection_Status::get_class_name()
	 * @uses \MainWP\Dashboard\MainWP_Recent_Pages::get_class_name()
	 * @uses \MainWP\Dashboard\MainWP_Recent_Posts::get_class_name()
	 * @uses \MainWP\Dashboard\MainWP_Security_Issues_Widget::get_class_name()
	 * @uses \MainWP\Dashboard\MainWP_Updates_Overview::get_class_name()
	 */
	public static function add_meta_boxes( $page ) { // phpcs:ignore -- complex method. Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		/**
		 * Get getmetaboxes
		 *
		 * Adds metaboxes (widgets) to the Overview page.
		 *
		 * @since Unknown
		 */
		$extMetaBoxs = MainWP_System_Handler::instance()->apply_filters( 'mainwp-getmetaboxes', array() );
		$extMetaBoxs = MainWP_System_Handler::instance()->apply_filters( 'mainwp_getmetaboxes', $extMetaBoxs );
		foreach ( $extMetaBoxs as $box ) {
			if ( isset( $box['plugin'] ) ) {
				$name                          = basename( $box['plugin'], '.php' );
				self::$enable_widgets[ $name ] = true;
			}
		}

		$values = self::$enable_widgets;

		/**
		 * Unset unwanted Widgets
		 *
		 * Contains the list of enabled widgets and allows user to unset unwanted widgets.
		 *
		 * @param array $values           Array containing enabled widgets.
		 * @param int   $dashboard_siteid Child site (Overview) ID.
		 *
		 * @since 4.0
		 */
		$values               = apply_filters( 'mainwp_overview_enabled_widgets', $values, null );
		self::$enable_widgets = array_merge( self::$enable_widgets, $values );

		// Load the Updates Overview widget.
		if ( self::$enable_widgets['overview'] ) {
			MainWP_UI::add_widget_box( 'overview', array( MainWP_Updates_Overview::get_class_name(), 'render' ), $page, array( 1, 1, 2, 6 ) );
			// MainWP_UI::add_widget_box( 'overview', array( MainWP_Updates_Overview::get_class_name(), 'render' ), $page, 'left', esc_html__( 'Updates Overview', 'mainwp' ) );
		}

		// Load the Security Issues widget.
		if ( mainwp_current_user_have_right( 'dashboard', 'manage_security_issues' ) ) {
			if ( self::$enable_widgets['security_issues'] ) {
				MainWP_UI::add_widget_box( 'security_issues', array( MainWP_Security_Issues_Widget::get_class_name(), 'render_widget' ), $page, array( 1, 1, 2, 2 ) );
				// MainWP_UI::add_widget_box( 'security_issues', array( MainWP_Security_Issues_Widget::get_class_name(), 'render_widget' ), $page, 'left', esc_html__( 'Security Issues', 'mainwp' ) );
			}
		}

		// Load the Clients widget.
		if ( self::$enable_widgets['clients'] ) {
			MainWP_UI::add_widget_box( 'clients', array( MainWP_Clients::get_class_name(), 'render' ), $page, array( 1, 1, 2, 3 ) );
			// MainWP_UI::add_widget_box( 'clients', array( MainWP_Clients::get_class_name(), 'render' ), $page, 'left', esc_html__( 'Clients', 'mainwp' ) );
		}

		// Load the Connection Status widget.
		if ( ! MainWP_System_Utility::get_current_wpid() ) {
			if ( self::$enable_widgets['connection_status'] ) {
				MainWP_UI::add_widget_box( 'connection_status', array( MainWP_Connection_Status::get_class_name(), 'render' ), $page, array( 1, 1, 2, 4 ) );
				// MainWP_UI::add_widget_box( 'connection_status', array( MainWP_Connection_Status::get_class_name(), 'render' ), $page, 'left', esc_html__( 'Connection Status', 'mainwp' ) );
			}
		}

		// Load the Non-MainWP Changes widget.
		if ( self::$enable_widgets['non_mainwp_changes'] ) {
			MainWP_UI::add_widget_box( 'non_mainwp_changes', array( MainWP_Site_Actions::get_class_name(), 'render' ), $page, array( 1, 1, 2, 3 ) );
			// MainWP_UI::add_widget_box( 'non_mainwp_changes', array( MainWP_Site_Actions::get_class_name(), 'render' ), $page, 'left', esc_html__( 'Non-MainWP Changes', 'mainwp' ) );
		}

		// Load the Recent Posts widget.
		if ( mainwp_current_user_have_right( 'dashboard', 'manage_posts' ) ) {
			if ( self::$enable_widgets['recent_posts'] ) {
				MainWP_UI::add_widget_box( 'recent_posts', array( MainWP_Recent_Posts::get_class_name(), 'render' ), $page, array( 1, 1, 3, 3 ) );
				// MainWP_UI::add_widget_box( 'recent_posts', array( MainWP_Recent_Posts::get_class_name(), 'render' ), $page, 'right', esc_html__( 'Recent Posts', 'mainwp' ) );
			}
		}

		// Load the Recent Pages widget.
		if ( mainwp_current_user_have_right( 'dashboard', 'manage_pages' ) ) {
			if ( self::$enable_widgets['recent_pages'] ) {
				MainWP_UI::add_widget_box( 'recent_pages', array( MainWP_Recent_Pages::get_class_name(), 'render' ), $page, array( 1, 1, 3, 3 ) );
				// MainWP_UI::add_widget_box( 'recent_pages', array( MainWP_Recent_Pages::get_class_name(), 'render' ), $page, 'right', esc_html__( 'Recent Pages', 'mainwp' ) );
		}
		}

		$i = 1;
		foreach ( $extMetaBoxs as $metaBox ) {
			$enabled = true;
			if ( isset( $metaBox['plugin'] ) ) {
				$name = basename( $metaBox['plugin'], '.php' );
				if ( isset( self::$enable_widgets[ $name ] ) && ! self::$enable_widgets[ $name ] ) {
					$enabled = false;
				}
			}

			$id = isset( $metaBox['id'] ) ? $metaBox['id'] : $i++;
			$id = 'advanced-' . $id;

			if ( $enabled ) {
				if ( 'google-widget' === $metaBox['id'] || 'matomo' === $metaBox['id'] ) {
					MainWP_UI::add_widget_box( $id, $metaBox['callback'], $page, array( 1, 1, 2, 7 ) );
				} else {
					MainWP_UI::add_widget_box( $id, $metaBox['callback'], $page, array( 1, 1, 2, 3 ) );
				}
			}
		}
	}

	/**
	 * Method on_show_page()
	 *
	 * When the page loads render the body content.
	 *
	 * @uses \MainWP\Dashboard\MainWP_UI::render_top_header()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_second_top_header()
	 */
	public function on_show_page() {
		if ( ! mainwp_current_user_have_right( 'dashboard', 'access_global_dashboard' ) ) {
			mainwp_do_not_have_permissions( esc_html__( 'global dashboard', 'mainwp' ) );
			return;
		}

		/**
		 * Screen layout columns array.
		 *
		 * @global object
		 */
		global $screen_layout_columns;

		$params = array(
			'title' => esc_html__( 'Overview', 'mainwp' ),
		);

		MainWP_UI::render_top_header( $params );

		MainWP_UI::render_second_top_header();

		self::render_dashboard_body( array(), $this->dashBoard, $screen_layout_columns );
		?>
		</div>
		<?php
	}


	/**
	 * Method render_dashboard_body()
	 *
	 * Render the Dashboard Body content.
	 *
	 * @param object $websites      Object containing child sites info.
	 * @param mixed  $dashboard     Dashboard.
	 * @param int    $screen_layout Screen Layout.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_current_wpid()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_page_id()
	 * @uses \MainWP\Dashboard\MainWP_UI::do_widget_boxes()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::show_mainwp_message()
	 */
	public static function render_dashboard_body( $websites, $dashboard, $screen_layout ) {

		$current_wp_id = MainWP_System_Utility::get_current_wpid();
		$website       = null;
		if ( ! empty( $current_wp_id ) ) {
			$website = $websites[0];
		}
		$screen = get_current_screen();
		?>



		<div class="mainwp-primary-content-wrap">
			<div id="mainwp-dashboard-info-box"></div>
			<?php
			if ( ! empty( $current_wp_id ) ) {
				if ( ! empty( $website->sync_errors ) ) {
					?>
					<div class="ui red message">
						<p><?php echo '<strong>' . $website->name . '</strong>' . esc_html__( ' is Disconnected. Click the Reconnect button to establish the connection again.', 'mainwp' ); ?></p>
					</div>
					<?php
				}
			}
			?>
	<div id="mainwp-message-zone" class="ui message" style="display:none;"></div>

			<?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'widgets' ) ) : ?>
				<div class="ui info message">
					<i class="close icon mainwp-notice-dismiss" notice-id="widgets"></i>
					<?php echo sprintf( esc_html__( 'To hide or show a widget, click the Cog (%1$s) icon or go to the %2$sMainWP Settings%3$s page and select options from "Show widgets"', 'mainwp' ), '<i class="cog icon"></i>', '<a href="admin.php?page=Settings">', '</a>' ); ?>
				</div>
			<?php endif; ?>

			<?php
			/**
			 * Action: mainwp_before_overview_widgets
			 *
			 * Fires at the top of the Overview page (before first widget).
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_before_overview_widgets' );

						?>
			<div id="mainwp-grid-wrapper" class="gridster">
				<?php MainWP_UI::do_widget_boxes( $screen->id ); ?>
			</div>
			<?php
			/**
			 * Action: 'mainwp_after_overview_widgets'
			 *
			 * Fires at the bottom of the Overview page (after the last widget).
			 *
			 * @since 4.1
			 */
			do_action( 'mainwp_after_overview_widgets' );
			?>
	<script type="text/javascript">
		jQuery( document ).ready( function( $ ) {
			jQuery( '.mainwp-widget .mainwp-dropdown-tab .item' ).tab();
			mainwp_get_icon_start();
		} );
	</script>
		<?php
		MainWP_UI::render_modal_upload_icon();
		MainWP_Updates::render_plugin_details_modal();
	}

	/**
	 * Method mainwp_help_content()
	 *
	 * Hook the section help content to the Help Sidebar element
	 *
	 * @return void
	 */
	public static function mainwp_help_content() {
		if ( isset( $_GET['page'] ) && 'mainwp_tab' === $_GET['page'] ) {
			?>
			<p><?php esc_html_e( 'If you need help with your MainWP Dashboard, please review following help documents', 'mainwp' ); ?></p>
			<div class="ui relaxed bulleted list">
				<div class="item"><a href="https://kb.mainwp.com/docs/understanding-mainwp-dashboard-user-interface/" target="_blank">Understanding MainWP Dashboard UI</a></div>
				<div class="item"><a href="https://kb.mainwp.com/docs/mainwp-navigation/" target="_blank">MainWP Navigation</a></div>
				<div class="item"><a href="https://kb.mainwp.com/docs/screen-options/" target="_blank">Page Settings</a></div>
				<div class="item"><a href="https://kb.mainwp.com/docs/mainwp-dashboard/" target="_blank">MainWP Dashboard</a></div>
				<div class="item"><a href="https://kb.mainwp.com/docs/mainwp-tables/" target="_blank">MainWP Tables</a></div>
				<div class="item"><a href="https://kb.mainwp.com/docs/individual-child-site-mode/" target="_blank">Individual Child Site Mode</a></div>
				<?php
				/**
				 * Action: mainwp_overview_help_item
				 *
				 * Fires at the bottom of the help articles list in the Help sidebar on the Overview page.
				 *
				 * Suggested HTML markup:
				 *
				 * <div class="item"><a href="Your custom URL">Your custom text</a></div>
				 *
				 * @since 4.1
				 */
				do_action( 'mainwp_overview_help_item' );
				?>
			</div>
			<?php
		}
	}

}
