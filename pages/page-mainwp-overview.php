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
	 * Enabled widgets
	 *
	 * @var array $enable_widgets
	 */
	private static $enable_widgets = array(
		'overview'          => true,
		'connection_status' => true,
		'recent_posts'      => true,
		'recent_pages'      => true,
		'security_issues'   => true,
		'backup_tasks'      => true,
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
	 */
	public function on_admin_menu() {
		if ( MainWP_System_Utility::is_admin() ) {

			/**
			 * Current user global.
			 *
			 * @global string
			 */
			global $current_user;

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
				MAINWP_PLUGIN_URL . 'assets/images/mainwpicon.png',
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
				'title'      => __( 'Overview', 'mainwp' ),
				'parent_key' => 'mainwp_tab',
				'slug'       => 'mainwp_tab',
				'href'       => 'admin.php?page=mainwp_tab',
				'icon'       => '<i class="tachometer alternate icon"></i>',
			),
			1
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
			MainWP_UI::add_widget_box( 'overview', array( MainWP_Updates_Overview::get_class_name(), 'render' ), $page, 'left', __( 'Updates Overview', 'mainwp' ) );
		}

		// Load the Recent Posts widget.
		if ( mainwp_current_user_have_right( 'dashboard', 'manage_posts' ) ) {
			if ( self::$enable_widgets['recent_posts'] ) {
				MainWP_UI::add_widget_box( 'recent_posts', array( MainWP_Recent_Posts::get_class_name(), 'render' ), $page, 'right', __( 'Recent Posts', 'mainwp' ) );
			}
		}

		// Load the Recent Pages widget.
		if ( mainwp_current_user_have_right( 'dashboard', 'manage_pages' ) ) {
			if ( self::$enable_widgets['recent_pages'] ) {
				MainWP_UI::add_widget_box( 'recent_pages', array( MainWP_Recent_Pages::get_class_name(), 'render' ), $page, 'right', __( 'Recent Pages', 'mainwp' ) );
			}
		}

		// Load the Connection Status widget.
		if ( ! MainWP_System_Utility::get_current_wpid() ) {
			if ( self::$enable_widgets['connection_status'] ) {
				MainWP_UI::add_widget_box( 'connection_status', array( MainWP_Connection_Status::get_class_name(), 'render' ), $page, 'left', __( 'Connection Status', 'mainwp' ) );
			}
		}

		// Load the Security Issues widget.
		if ( mainwp_current_user_have_right( 'dashboard', 'manage_security_issues' ) ) {
			if ( self::$enable_widgets['security_issues'] ) {
				MainWP_UI::add_widget_box( 'security_issues', array( MainWP_Security_Issues_Widget::get_class_name(), 'render_widget' ), $page, 'left', __( 'Security Issues', 'mainwp' ) );
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
				MainWP_UI::add_widget_box( $id, $metaBox['callback'], $page, 'right', $metaBox['metabox_title'] );
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
			mainwp_do_not_have_permissions( __( 'global dashboard', 'mainwp' ) );
			return;
		}

		/**
		 * Screen layout columns array.
		 *
		 * @global object
		 */
		global $screen_layout_columns;

		$params = array(
			'title' => __( 'Overview', 'mainwp' ),
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
     * @uses \MainWP\Dashboard\MainWP_Twitter
     * @uses \MainWP\Dashboard\MainWP_Twitter::get_twitter_notice()
     * @uses \MainWP\Dashboard\MainWP_Twitter::get_twit_to_send()
     * @uses \MainWP\Dashboard\MainWP_UI::do_widget_boxes()
     * @uses \MainWP\Dashboard\MainWP_UI::render_screen_options()
	 */
	public static function render_dashboard_body( $websites, $dashboard, $screen_layout ) {

		$current_wp_id = MainWP_System_Utility::get_current_wpid();
		$website       = null;
		if ( ! empty( $current_wp_id ) ) {
			$website = $websites[0];
		}
		$screen = get_current_screen();
		?>

		<div id="mainwp-dashboard-info-box">
			<?php
			if ( empty( $current_wp_id ) && MainWP_Twitter::enabled_twitter_messages() ) {
				$filter = array(
					'upgrade_everything',
					'upgrade_all_wp_core',
					'upgrade_all_plugins',
					'upgrade_all_themes',
				);
				foreach ( $filter as $what ) {
					$twitters = MainWP_Twitter::get_twitter_notice( $what );

					if ( is_array( $twitters ) ) {
						foreach ( $twitters as $timeid => $twit_mess ) {
							if ( ! empty( $twit_mess ) ) {
								$sendText = MainWP_Twitter::get_twit_to_send( $what, $timeid );
								if ( ! empty( $sendText ) ) {
									?>
									<div class="mainwp-tips ui info message twitter" style="margin:0">
										<i class="ui close icon mainwp-dismiss-twit"></i><span class="mainwp-tip" twit-what="<?php echo esc_attr( $what ); ?>"twit-id="<?php echo esc_attr( $timeid ); ?>"><?php echo $twit_mess; ?></span>&nbsp;<?php MainWP_Twitter::gen_twitter_button( $sendText ); ?>
									</div>
									<?php
								}
							}
						}
					}
				}
				?>
			<?php } ?>
		</div>

	<div id="mainwp-message-zone" class="ui message" style="display:none;"></div>
		<div class="mainwp-primary-content-wrap">
			<?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'widgets' ) ) : ?>
				<div class="ui message">
					<i class="close icon mainwp-notice-dismiss" notice-id="widgets"></i>
					<?php echo sprintf( __( 'To hide or show a widget, click the "Cog" icon or go to the %1$sMainWP Tools%2$s page and select options from "Hide unwanted widgets"', 'mainwp' ), '<a href="admin.php?page=MainWPTools">', '</a>' ); ?>
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

		<?php
		$overviewColumns = get_option( 'mainwp_number_overview_columns', 2 );

		$cls_grid = 'two';
		if ( 3 == $overviewColumns ) {
			$cls_grid = 'three';
		}
		?>
			<div class="ui <?php echo $cls_grid; ?> column stackable grid mainwp-grid-wrapper">
		<div class="column" id="mainwp-grid-left" widget-context="left">
						<?php
						/**
						 * Action: mainwp_overview_before_left_column
						 *
						 * Fires at the top of the left column on the Overview page.
						 *
						 * @since 4.1
						 */
						do_action( 'mainwp_overview_before_left_column' );
						MainWP_UI::do_widget_boxes( $screen->id, 'left' );
						/**
						 * Action: mainwp_overview_after_left_column
						 *
						 * Fires at the bottom of the left column on the Overview page.
						 *
						 * @since 4.1
						 */
						do_action( 'mainwp_overview_after_left_column' );
						?>
		</div>
		<?php if ( 3 == $overviewColumns ) : ?>
		<div class="column" id="mainwp-grid-middle" widget-context="middle">
						<?php
						/**
						 * Action: mainwp_overview_before_middle_column
						 *
						 * Fires at the top of the middle column on the Overview page.
						 *
						 * @since 4.1
						 */
						do_action( 'mainwp_overview_before_middle_column' );
						MainWP_UI::do_widget_boxes( $screen->id, 'middle' );
						/**
						 * Action: mainwp_overview_after_middle_column
						 *
						 * Fires at the bottom of the middle column on the Overview page.
						 *
						 * @since 4.1
						 */
						do_action( 'mainwp_overview_after_middle_column' );
						?>
		</div>
		<?php endif; ?>
		<div class="column" id="mainwp-grid-right" widget-context="right">
						<?php
						/**
						 * Action: mainwp_overview_before_right_column
						 *
						 * Fires at the top of the right column on the Overview page.
						 *
						 * @since 4.1
						 */
						do_action( 'mainwp_overview_before_right_column' );
						MainWP_UI::do_widget_boxes( $screen->id, 'right' );
						/**
						 * Action: mainwp_overview_after_right_column
						 *
						 * Fires at the bottom of the right column on the Overview page.
						 *
						 * @since 4.1
						 */
						do_action( 'mainwp_overview_after_right_column' );
						?>
		</div>
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

		<div class="ui modal" id="mainwp-overview-screen-options-modal">
			<div class="header"><?php esc_html_e( 'Screen Options', 'mainwp' ); ?></div>
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
				<form method="POST" action="">
					<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
					<input type="hidden" name="wp_nonce" value="<?php echo wp_create_nonce( 'MainWPScrOptions' ); ?>" />
					<?php echo MainWP_UI::render_screen_options( false ); ?>
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
				<input type="submit" class="ui green button" name="submit" id="submit" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>" />
				<div class="ui cancel button"><?php esc_html_e( 'Close', 'mainwp' ); ?></div>
			</div>
				</form>
		</div>
	</div>
	<script type="text/javascript">
		var page_sortablewidgets = '<?php echo esc_js( MainWP_System_Utility::get_page_id( $screen->id ) ); ?>';
		jQuery( document ).ready( function( $ ) {

			var $mainwp_drake = dragula( [document.getElementById( 'mainwp-grid-left' ),
			<?php
			if ( 3 == $overviewColumns ) {
				?>
				document.getElementById( 'mainwp-grid-middle' ), <?php }; ?> document.getElementById( 'mainwp-grid-right' )], {
				moves: function ( el, container, handle ) {
					return handle.classList.contains( 'handle-drag' );
				}
			} );

			$mainwp_drake.on( 'drop', function ( el, target, source, sibling ) {
				var conts = $mainwp_drake.containers;
				var order = new Array();
				for ( var i = 0; i < conts.length; i++ ) {
					var context = jQuery( conts[i] ).attr( 'widget-context' );
					if ( undefined === context || '' == context )
						continue;
					var searchEles = conts[i].children;
					for( var idx = 0; idx < searchEles.length; idx++ ) {
						var itemElem = searchEles[idx];
						var wid = $( itemElem ).attr( 'id' );
						wid = wid.replace( "widget-", "" );
						order.push( context + ":" + wid );
					}
				}

				var postVars = {
					action:'mainwp_widgets_order',
					page: page_sortablewidgets
				};
				postVars['order'] = order.join( ',' );
				jQuery.post( ajaxurl, mainwp_secure_data( postVars ), function ( res ) {
				} );

			} );
			jQuery( '.mainwp-widget .mainwp-dropdown-tab .item' ).tab();
		} );
	</script>
		<?php
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
				<div class="item"><a href="https://kb.mainwp.com/docs/understanding-mainwp-dashboard-user-interface/mainwp-navigation/" target="_blank">MainWP Navigation</a></div>
				<div class="item"><a href="https://kb.mainwp.com/docs/understanding-mainwp-dashboard-user-interface/screen-options/" target="_blank">Screen Options</a></div>
				<div class="item"><a href="https://kb.mainwp.com/docs/understanding-mainwp-dashboard-user-interface/mainwp-dashboard/" target="_blank">MainWP Dashboard</a></div>
				<div class="item"><a href="https://kb.mainwp.com/docs/understanding-mainwp-dashboard-user-interface/mainwp-tables/" target="_blank">MainWP Tables</a></div>
				<div class="item"><a href="https://kb.mainwp.com/docs/understanding-mainwp-dashboard-user-interface/individual-child-site-mode/" target="_blank">Individual Child Site Mode</a></div>
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
