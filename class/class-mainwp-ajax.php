<?php


class MainWP_Ajax {
	protected static $instance;

	public function __construct() {
		add_action( 'wp_ajax_mainwp_load', array( $this, 'load_page' ) );
		add_action( 'wp_ajax_mainwp_load_title', array( $this, 'load_page_title' ) );
		add_action( 'wp_ajax_mainwp_load_dashboard', array( $this, 'load_dashboard' ) );
		add_action( 'wp_ajax_mainwp_load_dashboard_title', array( $this, 'load_dashboard_title' ) );
		//add_action('wp_ajax_mainwp_load_profile', array($this, 'load_profile'));
		//add_action('wp_ajax_mainwp_load_profile_title', array($this, 'load_profile_title'));

		add_action( 'admin_init', array( $this, 'force_enqueue' ) );

	}

	/**
	 * Get Instance
	 */
	public static function Instance() {
		if ( self::$instance instanceof MainWP_Ajax ) {
			return self::$instance;
		}
		self::$instance = new MainWP_Ajax();

		return self::$instance;
	}

	public function force_enqueue() {
		// Force enqueue script and style for dashboard, profile and regular admin page
		// Regular Admin
		wp_enqueue_style( 'colors' );
		wp_enqueue_style( 'ie' );
		wp_enqueue_script( 'utils' );
		// Dashboard
		wp_enqueue_script( 'dashboard' );
		wp_enqueue_script( 'plugin-install' );
		wp_enqueue_script( 'media-upload' );
		add_thickbox();
		// Profile
		wp_enqueue_script( 'user-profile' );
	}

	public function init_load() {
		global $plugin_page, $hook_suffix, $current_screen, $title, $menu, $submenu, $pagenow, $typenow;
		if ( ! defined( 'WP_ADMIN' ) ) {
			define( 'WP_ADMIN', true );
		}

		if ( ! defined( 'WP_NETWORK_ADMIN' ) ) {
			define( 'WP_NETWORK_ADMIN', false );
		}

		if ( ! defined( 'WP_USER_ADMIN' ) ) {
			define( 'WP_USER_ADMIN', false );
		}

		if ( ! WP_NETWORK_ADMIN && ! WP_USER_ADMIN ) {
			define( 'WP_BLOG_ADMIN', true );
		}
		error_reporting( 0 ); // make sure to disable any error output

		require_once( ABSPATH . 'wp-admin/includes/admin.php' );
		require( ABSPATH . 'wp-admin/menu.php' );
		do_action( 'admin_init' );
	}

	public function get_title() {
		global $title;
		get_admin_page_title();
		$title = esc_html( strip_tags( $title ) );

		if ( is_network_admin() ) {
			$admin_title = __( 'Network Admin', 'mainwp' );
		} elseif ( is_user_admin() ) {
			$admin_title = __( 'Global Dashboard', 'mainwp' );
		} else {
			$admin_title = get_bloginfo( 'name' );
		}

		if ( $admin_title === $title ) {
			$admin_title = sprintf( __( '%1$s &#8212; WordPress', 'mainwp' ), $title );
		} else {
			$admin_title = sprintf( __( '%1$s &lsaquo; %2$s &#8212; WordPress', 'mainwp' ), $title, $admin_title );
		}

		$admin_title = apply_filters( 'admin_title', $admin_title, $title );

		return html_entity_decode( $admin_title, ENT_COMPAT, 'UTF-8' );
	}

	public function load_page( $load_title = false ) {
		global $plugin_page, $hook_suffix, $current_screen, $title, $menu, $submenu, $pagenow, $typenow;

		$pagenow = 'admin.php';
		$typenow = '';
		$this->init_load();

		$page = isset( $_REQUEST['page'] ) ? $_REQUEST['page'] : '';
		if ( $page && wp_verify_nonce( $_REQUEST['nonce'], 'mainwp_ajax' ) ) {
			$plugin_page = stripslashes( $page );
			$plugin_page = plugin_basename( $plugin_page );
			if ( $plugin_page ) {
				$page_hook   = get_plugin_page_hook( $plugin_page, $pagenow );
				$hook_suffix = $page_hook;
				if ( $load_title ) {
					echo esc_html( $this->get_title() );
					exit;
				}
				set_current_screen();
				do_action( 'load-' . $page_hook );
				screen_meta( $current_screen ); // Back compatibility with 3.2
				//$current_screen->render_screen_meta(); // For 3.3
				do_action( $page_hook );
			}
		}
		exit;
	}

	public function load_page_title() {
		$this->load_page( true );
	}

	public function load_dashboard( $load_title = false ) {
		global $plugin_page, $hook_suffix, $current_screen, $title, $menu, $submenu, $pagenow, $typenow;

		$hook_suffix = 'admin.php';
		$pagenow     = 'admin.php';
		$typenow     = '';
		$title       = __( 'Dashboard', 'mainwp' );
		$this->init_load();

		if ( wp_verify_nonce( $_REQUEST['nonce'], 'mainwp_ajax' ) ) {
			if ( $load_title ) {
				echo esc_html( $this->get_title() );
				exit;
			}
			set_current_screen();
			require_once( ABSPATH . 'wp-admin/includes/dashboard.php' );

			wp_dashboard_setup();

			wp_enqueue_script( 'dashboard' );
			wp_enqueue_script( 'plugin-install' );
			wp_enqueue_script( 'media-upload' );
			add_thickbox();

			$title       = __( 'Dashboard', 'mainwp' );
			$parent_file = 'index.php';

			if ( is_user_admin() ) {
				add_screen_option( 'layout_columns', array( 'max' => 4, 'default' => 2 ) );
			} else {
				add_screen_option( 'layout_columns', array( 'max' => 4, 'default' => 2 ) );
			}

			//$screen_layout_columns = 2;

			$help = '<p>' . __( 'Welcome to your WordPress Dashboard! This is the screen you will see when you log into your site and gives you access to all the site management features of WordPress. You can get help for any screen by clicking the Help tab in the upper corner.', 'mainwp' ) . '</p>';

			get_current_screen()->add_help_tab( array(
				'id'      => 'overview',
				'title'   => __( 'Overview', 'mainwp' ),
				'content' => $help,
			) );

			// Help tabs

			$help = '<p>' . __( 'The left-hand navigation menu provides links to all of the WordPress administration screens, with submenu items displayed on hover. You can minimize this menu to a narrow icon strip by clicking on the Collapse Menu arrow at the bottom.', 'mainwp' ) . '</p>';
			$help .= '<p>' . __( 'Links in the Toolbar at the top of the screen connect your dashboard and the front end of your site and provide access to your profile and helpful WordPress information.', 'mainwp' ) . '</p>';

			get_current_screen()->add_help_tab( array(
				'id'      => 'help-navigation',
				'title'   => __( 'Navigation', 'mainwp' ),
				'content' => $help,
			) );

			$help = '<p>' . __( 'You can use the following controls to arrange your Dashboard screen to suit your workflow. This is true on most other administration screens as well.', 'mainwp' ) . '</p>';
			$help .= '<p>' . __( '<strong>Screen Options</strong> - Use the Screen Options tab to choose which Dashboard boxes to show, and how many columns to display.', 'mainwp' ) . '</p>';
			$help .= '<p>' . __( '<strong>Drag and Drop</strong> - To rearrange the boxes, drag and drop by clicking on the title bar of the selected box and releasing when you see a gray dotted-line rectangle appear in the location you want to place the box.', 'mainwp' ) . '</p>';
			$help .= '<p>' . __( '<strong>Box Controls</strong> - Click the title bar of the box to expand or collapse it. In addition, some box have configurable content, and will show a &#8220;Configure&#8221; link in the title bar if you hover over it.', 'mainwp' ) . '</p>';

			get_current_screen()->add_help_tab( array(
				'id'      => 'help-layout',
				'title'   => __( 'Layout', 'mainwp' ),
				'content' => $help,
			) );

			$help = '<p>' . __( 'The boxes on your Dashboard screen are:', 'mainwp' ) . '</p>';
			$help .= '<p>' . __( '<strong>Update Overview</strong> - Displays a summary of the content on your site and identifies which theme and version of WordPress you are using.', 'mainwp' ) . '</p>';
			$help .= '<p>' . __( '<strong>Recent Comments</strong> - Shows the most recent comments on your posts (configurable, up to 30) and allows you to moderate them.', 'mainwp' ) . '</p>';
			$help .= '<p>' . __( '<strong>Incoming Links</strong> - Shows links to your site found by Google Blog Search.', 'mainwp' ) . '</p>';
			$help .= '<p>' . __( '<strong>QuickPress</strong> - Allows you to create a new post and either publish it or save it as a draft.', 'mainwp' ) . '</p>';
			$help .= '<p>' . __( '<strong>Recent Drafts</strong> - Displays links to the 5 most recent draft posts you&#8217;ve started.', 'mainwp' ) . '</p>';
			$help .= '<p>' . __( '<strong>WordPress Blog</strong> - Latest news from the official WordPress project.', 'mainwp' ) . '</p>';
			$help .= '<p>' . sprintf( __( '<strong>Other WordPress News</strong> - Shows the %sWordPress Planet%s feed. You can configure it to show a different feed of your choosing.', 'mainwp' ), '<a href="http://planet.wordpress.org" target="_blank">', '</a>' ) . '</p>';
			$help .= '<p>' . __( '<strong>Plugins</strong> - Features the most popular, newest, and recently updated plugins from the WordPress.org Plugin Directory.', 'mainwp' ) . '</p>';

			get_current_screen()->add_help_tab( array(
				'id'      => 'help-content',
				'title'   => __( 'Content', 'mainwp' ),
				'content' => $help,
			) );

			unset( $help );

			get_current_screen()->set_help_sidebar(
				'<p><strong>' . __( 'For more information:', 'mainwp' ) . '</strong></p>' .
				'<p>' . sprintf( __( '%sDocumentation on Dashboard%s', 'mainwp' ), '<a href="http://codex.wordpress.org/Dashboard_Screen" target="_blank">', '</a>') . '</p>' .
				'<p>' . sprintf( __( '%sSupport Forums%s', 'mainwp' ), '<a href="http://wordpress.org/support/" target="_blank">', '</a>' ) . '</p>'
			);
			$today = current_time( 'mysql', 1 );
			?>

			<?php
			screen_meta( $current_screen ); // Back compatibility with 3.2
			//$current_screen->render_screen_meta(); // For 3.3
			?>
			<div class="wrap">
				<?php screen_icon( 'index' ); ?>
				<h2><?php echo esc_html( $title ); ?></h2>

				<?php wp_welcome_panel(); ?>

				<div id="dashboard-widgets-wrap">

					<?php wp_dashboard(); ?>

					<div class="clear"></div>
				</div>
				<!-- dashboard-widgets-wrap -->

			</div><!-- wrap -->
			<?php
		}
		exit;
	}

	public function load_dashboard_title() {
		$this->load_dashboard( true );
	}
}





