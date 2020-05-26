<?php
/**
 * MainWP Manage Sites Page.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * MainWP Manage Sites Page
 */
class MainWP_Manage_Sites {

	/**
	 * Get Class Name
	 *
	 * @return string __CLASS__
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Sub pages
	 *
	 * @static
	 * @var array $subPages Sub pages.
	 */
	public static $subPages;

	/**
	 * Current page.
	 *
	 * @static
	 * @var string $page Current page.
	 */
	public static $page;

	/**
	 * Widgets to enable.
	 *
	 * @static
	 * @var array $enable_widgets Widgets to enable.
	 */
	private static $enable_widgets = array(
		'overview'          => true,
		'connection_status' => true,
		'recent_posts'      => true,
		'recent_pages'      => true,
		'security_issues'   => true,
		'manage_backups'    => true,
		'plugins'           => true,
		'themes'            => true,
		'notes'             => true,
		'site_note'         => true,
	);

	/**
	 * Magage Sites table
	 *
	 * @var $sitesTable Magage Sites table.
	 */
	public static $sitesTable;

	/**
	 * Method init()
	 *
	 * Initiate Manage Sites.
	 */
	public static function init() {
		/**
		 * This hook allows you to render the Sites page header via the 'mainwp-pageheader-sites' action.
		 *
		 * @link http://codex.mainwp.com/#mainwp-pageheader-sites
		 *
		 * This hook is normally used in the same context of 'mainwp-getsubpages-sites'
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-sites
		 *
		 * @see \MainWP_Manage_Sites::render_header
		 */
		add_action( 'mainwp-pageheader-sites', array( self::get_class_name(), 'render_header' ) );

		/**
		 * This hook allows you to render the Sites page footer via the 'mainwp-pagefooter-sites' action.
		 *
		 * @link http://codex.mainwp.com/#mainwp-pagefooter-sites
		 *
		 * This hook is normally used in the same context of 'mainwp-getsubpages-sites'
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-sites
		 *
		 * @see \MainWP_Manage_Sites::render_footer
		 */
		add_action( 'mainwp-pagefooter-sites', array( self::get_class_name(), 'render_footer' ) );

		add_action( 'mainwp-securityissues-sites', array( MainWP_Security_Issues::get_class_name(), 'render' ) );
		add_action( 'mainwp-extension-sites-edit', array( self::get_class_name(), 'on_edit_site' ) ); // @deprecated Use 'mainwp_extension_sites_edit' instead.
		add_action( 'mainwp_extension_sites_edit', array( self::get_class_name(), 'on_edit_site' ) );

		// Hook the Help Sidebar content.
		add_action( 'mainwp_help_sidebar_content', array( self::get_class_name(), 'mainwp_help_content' ) );
	}

	/**
	 * Method on_screen_layout_columns()
	 *
	 * Columns to display.
	 *
	 * @param mixed $columns Columns to display.
	 * @param mixed $screen Current page.
	 *
	 * @return array $columns
	 */
	public static function on_screen_layout_columns( $columns, $screen ) {
		if ( $screen == self::$page ) {
			$columns[ self::$page ] = 3;
		}

		return $columns;
	}

	/** Initiate menu. */
	public static function init_menu() {
		self::$page = MainWP_Manage_Sites_View::init_menu();
		add_action( 'load-' . self::$page, array( self::get_class_name(), 'on_load_page' ) );

		if ( isset( $_REQUEST['dashboard'] ) ) {
			global $current_user;
			delete_user_option( $current_user->ID, 'screen_layout_toplevel_page_managesites' );
			add_filter( 'screen_layout_columns', array( self::get_class_name(), 'on_screen_layout_columns' ), 10, 2 );

			$val = get_user_option( 'screen_layout_' . self::$page );
			if ( ! MainWP_Utility::ctype_digit( $val ) ) {
				global $current_user;
				update_user_option( $current_user->ID, 'screen_layout_' . self::$page, 2, true );
			}
		}
		add_submenu_page(
			'mainwp_tab',
			__( 'Sites', 'mainwp' ),
			'<div class="mainwp-hidden">' . __( 'Sites', 'mainwp' ) . '</div>',
			'read',
			'SiteOpen',
			array(
				MainWP_Site_Open::get_class_name(),
				'render',
			)
		);
		add_submenu_page(
			'mainwp_tab',
			__( 'Sites', 'mainwp' ),
			'<div class="mainwp-hidden">' . __( 'Sites', 'mainwp' ) . '</div>',
			'read',
			'SiteRestore',
			array(
				MainWP_Site_Open::get_class_name(),
				'render_restore',
			)
		);

		/**
		 * This hook allows you to add extra sub pages to the Sites page via the 'mainwp-getsubpages-sites' filter.
		 *
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-sites
		 */
		$sub_pages      = array();
		$sub_pages      = apply_filters_deprecated( 'mainwp-getsubpages-sites', array( $sub_pages ), '4.0.1', 'mainwp_getsubpages_sites' ); // @deprecated Use 'mainwp_getsubpages_sites' instead.
		self::$subPages = apply_filters( 'mainwp_getsubpages_sites', $sub_pages );

		if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
			foreach ( self::$subPages as $subPage ) {
				if ( MainWP_Menu::is_disable_menu_item( 2, 'ManageSites' . $subPage['slug'] ) ) {
					continue;
				}
				$_page = add_submenu_page( 'mainwp_tab', $subPage['title'], '<div class="mainwp-hidden">' . $subPage['title'] . '</div>', 'read', 'ManageSites' . $subPage['slug'], $subPage['callback'] );
				add_action( 'load-' . $_page, array( self::get_class_name(), 'on_load_subpages' ), 9 );
				if ( isset( $subPage['on_load_callback'] ) && ! empty( $subPage['on_load_callback'] ) ) {
					add_action( 'load-' . $_page, $subPage['on_load_callback'] );
				}
			}
		}

		MainWP_Manage_Sites_View::init_left_menu( self::$subPages );
	}

	/** Initiate sub page menu. */
	public static function init_subpages_menu() {
		MainWP_Manage_Sites_View::init_subpages_menu( self::$subPages );
	}

	/**
	 * Method on_load_page()
	 *
	 * Run on page load.
	 */
	public static function on_load_page() {

		if ( isset( $_REQUEST['dashboard'] ) ) {
			self::on_load_page_dashboard();
			return;
		}

		MainWP_System::enqueue_postbox_scripts();

		$i = 1;
		if ( isset( $_REQUEST['do'] ) ) {
			if ( 'new' === $_REQUEST['do'] ) {
				return;
			}
		} elseif ( isset( $_GET['id'] ) || isset( $_GET['scanid'] ) || isset( $_GET['backupid'] ) || isset( $_GET['updateid'] ) ) {
			return;
		}

		add_filter( 'mainwp_header_actions_right', array( self::get_class_name(), 'screen_options' ), 10, 2 );
		self::$sitesTable = new MainWP_Manage_Sites_List_Table();
	}

	/**
	 * Method on_load_sunpages()
	 *
	 * Run on subpage load.
	 */
	public static function on_load_subpages() {
		if ( isset( $_GET['id'] ) && $_GET['id'] ) {
			MainWP_System_Utility::set_current_wpid( $_GET['id'] );
		}
	}

	/**
	 * Method render_header()
	 *
	 * Render page header.
	 *
	 * @param string $shownPage Current page slug.
	 */
	public static function render_header( $shownPage = '' ) {
		MainWP_Manage_Sites_View::render_header( $shownPage, self::$subPages );
	}

	/**
	 * Method render_footer()
	 *
	 * Render page footer.
	 *
	 * @param string $shownPage The page slug shown at this moment.
	 */
	public static function render_footer( $shownPage ) {
		MainWP_Manage_Sites_View::render_footer( $shownPage, self::$subPages );
	}

	/**
	 * Method screen_options()
	 *
	 * Create Screen Options button.
	 *
	 * @param mixed $input Screen options button HTML.
	 *
	 * @return mixed Screen sptions button.
	 */
	public static function screen_options( $input ) {
		return $input .
				'<a class="ui button basic icon" onclick="mainwp_manage_sites_screen_options(); return false;" data-inverted="" data-position="bottom right" href="#" target="_blank" data-tooltip="' . esc_html__( 'Screen Options', 'mainwp' ) . '">
					<i class="cog icon"></i>
				</a>';
	}

	/**
	 * Method render_screen_options()
	 *
	 * Render Screen Options Modal.
	 */
	public static function render_screen_options() {

		$columns = self::$sitesTable->get_columns();

		if ( isset( $columns['cb'] ) ) {
			unset( $columns['cb'] );
		}

		if ( isset( $columns['status'] ) ) {
			$columns['status'] = __( 'Status', 'mainwp' );
		}

		$sites_per_page = get_option( 'mainwp_default_sites_per_page', 25 );

		if ( isset( $columns['site_actions'] ) && empty( $columns['site_actions'] ) ) {
			$columns['site_actions'] = __( 'Actions', 'mainwp' );
		}

		?>
		<div class="ui modal" id="mainwp-manage-sites-screen-options-modal">
			<div class="header"><?php esc_html_e( 'Screen Options', 'mainwp' ); ?></div>
			<div class="scrolling content ui form">
				<form method="POST" action="" id="manage-sites-screen-options-form">
					<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
					<input type="hidden" name="wp_nonce" value="<?php echo wp_create_nonce( 'ManageSitesScrOptions' ); ?>" />
					<div class="ui grid field">
						<label class="six wide column"><?php esc_html_e( 'Default items per page value', 'mainwp' ); ?></label>
						<div class="ten wide column">
							<div class="ui info message">
								<ul>
									<li><?php esc_html_e( 'Based on your Dashboard server default large numbers can severely impact page load times.', 'mainwp' ); ?></li>
									<li><?php esc_html_e( 'Do not add commas for thousands (ex 1000).', 'mainwp' ); ?></li>
									<li><?php esc_html_e( '-1 to default to All of your Child Sites.', 'mainwp' ); ?></li>
								</ul>
							</div>
							<input type="text" name="mainwp_default_sites_per_page" id="mainwp_default_sites_per_page" saved-value="<?php echo intval( $sites_per_page ); ?>" value="<?php echo intval( $sites_per_page ); ?>"/>
						</div>
					</div>
					<?php
					$hide_cols = get_user_option( 'mainwp_settings_hide_manage_sites_columns' );
					if ( ! is_array( $hide_cols ) ) {
						$hide_cols = array();
					}
					?>
					<div class="ui grid field">
						<label class="six wide column"><?php esc_html_e( 'Hide unwanted columns', 'mainwp' ); ?></label>
						<div class="ten wide column">
							<ul class="mainwp_hide_wpmenu_checkboxes">
								<?php
								foreach ( $columns as $name => $title ) {
									if ( empty( $title ) ) {
										continue;
									}
									?>
									<li>
										<div class="ui checkbox">
											<input type="checkbox"
											<?php
											if ( in_array( $name, $hide_cols, true ) ) {
												echo 'checked="checked"';
											}
											?>
											id="mainwp_hide_column_<?php echo esc_attr( $name ); ?>" name="mainwp_hide_column_<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $name ); ?>">
											<label for="mainwp_hide_column_<?php echo esc_attr( $name ); ?>" ><?php echo $title; ?></label>
										</div>
									</li>
									<?php
								}
								?>
							</ul>
						</div>
					</div>
				</div>
				<div class="actions">
					<input type="submit" class="ui green button" name="submit" id="submit" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>" />
					<div class="ui cancel button"><?php esc_html_e( 'Close', 'mainwp' ); ?></div>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Method display_rows()
	 *
	 * Display table rows.
	 */
	public static function display_rows() {
		self::$sitesTable = new MainWP_Manage_Sites_List_Table();
		self::$sitesTable->prepare_items( true );
		$output = self::$sitesTable->get_datatable_rows();
		self::$sitesTable->clear_items();
		wp_send_json( $output );
	}

	/**
	 * Method render_new_site()
	 *
	 * Render add new site page.
	 *
	 * @return html Add new site html.
	 */
	public static function render_new_site() {
		$websites            = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );
		$referrer_page_check = false;
		$referrer_page       = wp_get_referer();
		if ( admin_url( 'admin.php?page=mainwp-setup' ) === $referrer_page ) {
			$referrer_page_check = true;
		}

		$showpage = 'AddNew';
		self::render_header( $showpage );

		if ( ! mainwp_current_user_have_right( 'dashboard', 'add_sites' ) ) {
			mainwp_do_not_have_permissions( __( 'add sites', 'mainwp' ) );
			return;
		} else {
			$groups = MainWP_DB_Common::instance()->get_groups_for_current_user();
			if ( ! is_array( $groups ) ) {
				$groups = array();
			}

			?>
		<div id="mainwp-add-new-site" class="ui segment">

			<?php if ( ! $websites && $referrer_page_check ) : ?>
			<div id="mainwp-add-site-welcome-message" class="ui inverted dimmer">
				<div class="ui segment" style="width: 60%;">
					<div class="ui huge header">
						<?php esc_html_e( 'Let\'s start connecting your WordPress Sites.', 'mainwp' ); ?>
						<div class="sub header"><?php esc_html_e( 'What is MainWP and How Works', 'mainwp' ); ?></div>
					</div>
					<p><?php esc_html_e( 'The MainWP Dashboard is a WordPress plugin that utilizes a control dashboard for your managed sites. The Dashboard plugin allows you to connect and control completely independent WordPress sites even those on different hosts and servers.', 'mainwp' ); ?></p>
					<div class="ui hidden divider"></div>
					<img class="ui centered image" src="/wp-content/plugins/mainwp/assets/images/mainwp-demo-infographic.png" alt="<?php esc_attr_e( 'How MainWP works', 'mainwp' ); ?>">
					<div class="ui hidden divider"></div>
					<div class="ui message">
						<div class="header"><?php esc_html_e( 'MainWP Dashboard requires the MainWP Child plugin to be installed and activated on the WordPress site that you want to connect.', 'mainwp' ); ?></div>
						<?php esc_html_e( 'The MainWP Child plugin is used to securely manage multiple WordPress websites from your MainWP Dashboard. This plugin is to be installed on every WordPress site you want to control from your Dashboard. It allows your Dashboard plugin to safely connect to your website and communicate with it while performing requested actions.', 'mainwp' ); ?>
						<p><a href="https://mainwp.com/help/docs/install-mainwp-child/" target="_blank" class="ui mini button"><?php esc_html_e( 'How to Install MainWP Child Plugin', 'mainwp' ); ?></a> <a href="https://mainwp.com/help/docs/add-site-to-your-dashboard/" target="_blank" class="ui mini button"><?php esc_html_e( 'How to Connect Child Sites', 'mainwp' ); ?></a></p>
					</div>
					<div class="ui big cancel green button"><?php esc_html_e( 'OK, Let\'s Start!', 'mainwp' ); ?></div>
				</div>

			</div>

			<script type="text/javascript">
			jQuery( '#mainwp-add-site-welcome-message' ).dimmer( 'show' );
			jQuery( '.ui.cancel.button' ).on( 'click', function () {
				jQuery( '#mainwp-add-site-welcome-message' ).dimmer( 'hide' );
			} );
			</script>
			<?php endif; ?>

			<div class="ui hidden divider"></div>
			<div id="mainwp-message-zone" style="display: none;" class="ui message"></div>

			<div id="mainwp_managesites_add_errors" style="display: none" class="mainwp-notice mainwp-notice-red"></div>
			<div id="mainwp_managesites_add_message" style="display: none" class="mainwp-notice mainwp-notice-green"></div>

			<form method="POST" class="ui form" action="" enctype="multipart/form-data" id="mainwp_managesites_add_form">
				<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
				<h3 class="ui dividing header">
					<?php esc_html_e( 'Add a Single Site', 'mainwp' ); ?>
					<div class="sub header"><?php esc_html_e( 'Required fields.', 'mainwp' ); ?></div>
				</h3>

				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'Site URL', 'mainwp' ); ?></label>
					<div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Enter your website URL.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<div class="ui left action input">
							<select class="ui compact selection dropdown" id="mainwp_managesites_add_wpurl_protocol" name="mainwp_managesites_add_wpurl_protocol">
								<option value="http">http://</option>
								<option selected="" value="https">https://</option>
							</select>
							<input type="text" id="mainwp_managesites_add_wpurl" name="mainwp_managesites_add_wpurl" value="" />
						</div>
					</div>
				</div>

				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'Administrator username', 'mainwp' ); ?></label>
					<div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Enter the website Administrator username.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<div class="ui left labeled input">
							<input type="text" id="mainwp_managesites_add_wpadmin" name="mainwp_managesites_add_wpadmin" value="" />
						</div>
					</div>
				</div>

				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'Site title', 'mainwp' ); ?></label>
					<div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Enter the website title.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<div class="ui left labeled input">
							<input type="text" id="mainwp_managesites_add_wpname" name="mainwp_managesites_add_wpname" value="" />
						</div>
					</div>
				</div>

				<h3 class="ui dividing header">
					<?php esc_html_e( 'Optional Settings', 'mainwp' ); ?>
					<div class="sub header"><?php esc_html_e( 'Use these fields as per your perferrence.', 'mainwp' ); ?></div>
				</h3>

				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'Unique security ID (optional)', 'mainwp' ); ?></label>
					<div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'If in use, enter the website Unique ID.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<div class="ui left labeled input">
							<input type="text" id="mainwp_managesites_add_uniqueId" name="mainwp_managesites_add_uniqueId" value="" />
						</div>
					</div>
				</div>

				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'Groups (optional)', 'mainwp' ); ?></label>
					<div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Add the website to existing group(s).', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<div class="ui multiple search selection dropdown" init-value="" id="mainwp_managesites_add_addgroups">
							<i class="dropdown icon"></i>
							<div class="default text"></div>
							<div class="menu">
								<?php foreach ( $groups as $group ) { ?>
									<div class="item" data-value="<?php echo $group->id; ?>"><?php echo $group->name; ?></div>
								<?php } ?>
							</div>
						</div>
					</div>
				</div>

				<?php MainWP_Manage_Sites_View::render_sync_exts_settings(); ?>

				<h3 class="ui dividing header">
					<?php esc_html_e( 'Advanced Options', 'mainwp' ); ?>
					<div class="sub header"><?php esc_html_e( 'Use advanced options when needed. In most cases, you can leave the default values.', 'mainwp' ); ?></div>
				</h3>

				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'Verify SSL certificate (optional)', 'mainwp' ); ?></label>
					<div class="six wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'Do you want to verify SSL certificate.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<input type="checkbox" name="mainwp_managesites_verify_certificate" id="mainwp_managesites_verify_certificate" checked="true" />
					</div>
				</div>

				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'SSL version (optional)', 'mainwp' ); ?></label>
					<div class="six wide column" data-tooltip="<?php esc_attr_e( 'Select SSL version. If you are not sure, select "Auto Detect".', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<select name="mainwp_managesites_ssl_version" id="mainwp_managesites_ssl_version" class="ui dropdown">
							<option selected value="auto"><?php esc_html_e( 'Auto detect', 'mainwp' ); ?></option>
							<option value="1.2"><?php esc_html_e( "Let's encrypt (TLS v1.2)", 'mainwp' ); ?></option>
							<option value="1.x"><?php esc_html_e( 'TLS v1.x', 'mainwp' ); ?></option>
							<option value="2"><?php esc_html_e( 'SSL v2', 'mainwp' ); ?></option>
							<option value="3"><?php esc_html_e( 'SSL v3', 'mainwp' ); ?></option>
							<option value="1.0"><?php esc_html_e( 'TLS v1.0', 'mainwp' ); ?></option>
							<option value="1.1"><?php esc_html_e( 'TLS v1.1', 'mainwp' ); ?></option>
						</select>
					</div>
				</div>

				<!-- fake fields are a workaround for chrome autofill getting the wrong fields -->
				<input style="display:none" type="text" name="fakeusernameremembered"/>
				<input style="display:none" type="password" name="fakepasswordremembered"/>

				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'HTTP username (optional)', 'mainwp' ); ?></label>
					<div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'If the child site is HTTP Basic Auth protected, enter the HTTP username here.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<div class="ui left labeled input">
							<input type="text" id="mainwp_managesites_add_http_user" name="mainwp_managesites_add_http_user" value="" autocomplete="new-http-user" />
						</div>
					</div>
				</div>

				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'HTTP password (optional)', 'mainwp' ); ?></label>
					<div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'If the child site is HTTP Basic Auth protected, enter the HTTP password here.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<div class="ui left labeled input">
							<input type="password" id="mainwp_managesites_add_http_pass" name="mainwp_managesites_add_http_pass" value="" autocomplete="new-password" />
						</div>
					</div>
				</div>

				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'Force IPv4 (optional)', 'mainwp' ); ?></label>
					<div class="six wide column ui toggle checkbox"  data-tooltip="<?php esc_attr_e( 'Do you want to force IPv4 for this child site?', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<input type="checkbox" name="mainwp_managesites_force_use_ipv4" id="mainwp_managesites_force_use_ipv4" />
					</div>
				</div>

				<?php
				do_action_deprecated( 'mainwp-manage-sites-edit', array( false ), '4.0.1', 'mainwp_manage_sites_edit' ); // @deprecated Use 'mainwp_manage_sites_edit' instead.
				do_action( 'mainwp_manage_sites_edit', false );
				?>

				<div class="ui divider"></div>
				<input type="button" name="mainwp_managesites_test" id="mainwp_managesites_test" class="ui button basic green big" value="<?php esc_attr_e( 'Test Connection', 'mainwp' ); ?>"/>
				<input type="button" name="mainwp_managesites_add" id="mainwp_managesites_add" class="ui button green big right floated" value="<?php esc_attr_e( 'Add Site', 'mainwp' ); ?>" />
			</form>
		</div>

		<div class="ui modal" id="mainwp-test-connection-modal">
			<div class="header"><?php esc_html_e( 'Connection Test', 'mainwp' ); ?></div>
			<div class="content">
				<div class="ui active inverted dimmer">
					<div class="ui text loader"><?php esc_html_e( 'Testing connection...', 'mainwp' ); ?></div>
				</div>
				<div id="mainwp-test-connection-result" class="ui segment" style="display:none">
					<h2 class="ui center aligned icon header">
						<i class=" icon"></i>
						<div class="content">
							<span></span>
							<div class="sub header"></div>
						</div>
					</h2>
				</div>
			</div>
			<div class="actions">
				<div class="ui cancel button"><?php esc_html_e( 'Close', 'mainwp' ); ?></div>
			</div>
		</div>
		<script type="text/javascript">
			jQuery( document ).ready( function () {
				jQuery( '#mainwp_managesites_add_addgroups' ).dropdown( {
					allowAdditions: true
				} );
			} );
		</script>
			<?php
		}
		self::render_footer( $showpage );
	}

	/**
	 * Method render_bulk_new_site()
	 *
	 * Render Import Sites - bulk new site modal.
	 */
	public static function render_bulk_new_site() {
		$showpage = 'BulkAddNew';
		self::render_header( $showpage );
		if ( ! mainwp_current_user_have_right( 'dashboard', 'add_sites' ) ) {
			mainwp_do_not_have_permissions( __( 'add sites', 'mainwp' ) );
			return;
		} else {
			if ( isset( $_FILES['mainwp_managesites_file_bulkupload'] ) && UPLOAD_ERR_OK == $_FILES['mainwp_managesites_file_bulkupload']['error'] && check_admin_referer( 'mainwp-admin-nonce' ) ) {
				?>
				<div class="ui modal" id="mainwp-import-sites-modal">
					<div class="header"><?php esc_html_e( 'Import Sites', 'mainwp' ); ?></div>
					<div class="scrolling header">
					<?php MainWP_Manage_Sites_View::render_import_sites(); ?>
					</div>
					<div class="actions">
						<div class="ui cancel button"><?php esc_html_e( 'Close', 'mainwp' ); ?></div>
						<input type="button" name="mainwp_managesites_btn_import" id="mainwp_managesites_btn_import" class="ui basic button" value="<?php esc_attr_e( 'Pause', 'mainwp' ); ?>"/>
						<input type="button" name="mainwp_managesites_btn_save_csv" id="mainwp_managesites_btn_save_csv" disabled="disabled" class="ui basic green button" value="<?php esc_attr_e( 'Save failed', 'mainwp' ); ?>"/>
					</div>
				</div>
				<script type="text/javascript">
					jQuery( document ).ready( function () {
						jQuery( "#mainwp-import-sites-modal" ).modal( {
							closable: false,
							onHide: function() {
								location.href = 'admin.php?page=managesites&do=bulknew';
							}
						} ).modal( 'show' );
					} );
				</script>
				<?php
			} else {
				?>
				<div class="ui segment" id="mainwp-import-sites">
					<div id="mainwp-message-zone" class="ui message" style="display:none"></div>
					<form method="POST" action="" enctype="multipart/form-data" id="mainwp_managesites_bulkadd_form" class="ui form">
						<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'Uplod the CSV file', 'mainwp' ); ?></label>
							<div class="ten wide column">
								<input type="file" name="mainwp_managesites_file_bulkupload" id="mainwp_managesites_file_bulkupload" accept="text/comma-separated-values"/>
							</div>
						</div>
						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'CSV file contains a header', 'mainwp' ); ?></label>
							<div class="ui toggle checkbox">
								<input type="checkbox" name="mainwp_managesites_chk_header_first" checked="checked" id="mainwp_managesites_chk_header_first" value="1"/>
							</div>
						</div>
						<div class="ui divider"></div>
						<a href="<?php echo MAINWP_PLUGIN_URL . 'assets/csv/sample.csv'; ?>" class="ui big green basic button"><?php esc_html_e( 'Download Sample CSV file', 'mainwp' ); ?></a>
						<input type="button" name="mainwp_managesites_add" id="mainwp_managesites_bulkadd" class="ui big green right floated button" value="<?php esc_attr_e( 'Import Sites', 'mainwp' ); ?>"/>
					</form>
				</div>
				<?php
			}
		}
		self::render_footer( $showpage );
	}

	/**
	 * Method on_load_page_dashboard()
	 *
	 * Add individual meta boxes.
	 */
	public static function on_load_page_dashboard() { // phpcs:ignore -- not quite complex method.
		wp_enqueue_script( 'common' );
		wp_enqueue_script( 'wp-lists' );
		wp_enqueue_script( 'postbox' );
		wp_enqueue_script( 'dashboard' );
		wp_enqueue_script( 'widgets' );

		$dashboard_siteid = isset( $_GET['dashboard'] ) ? $_GET['dashboard'] : null;

		/**
		 * This hook allows you to add extra metaboxes to the dashboard via the 'mainwp-getmetaboxes' filter.
		 *
		 * @link http://codex.mainwp.com/#mainwp-getmetaboxes
		 */

		$extMetaBoxs = MainWP_System_Handler::instance()->apply_filters( 'mainwp-getmetaboxes', array() );  // @deprecated Use 'mainwp_getmetaboxes' instead.
		$extMetaBoxs = MainWP_System_Handler::instance()->apply_filters( 'mainwp_getmetaboxes', $extMetaBoxs );

		foreach ( $extMetaBoxs as $box ) {
			if ( isset( $box['plugin'] ) ) {
				$name                          = basename( $box['plugin'], '.php' );
				self::$enable_widgets[ $name ] = true;
			}
		}

		$values = self::$enable_widgets;
		// hook to support enable/disable overview widgets.
		$values               = apply_filters( 'mainwp_overview_enabled_widgets', $values, $dashboard_siteid );
		self::$enable_widgets = array_merge( self::$enable_widgets, $values );

		// Load the Updates Overview widget.
		if ( self::$enable_widgets['overview'] ) {
			MainWP_UI::add_widget_box( 'overview', array( MainWP_Updates_Overview::get_class_name(), 'render' ), self::$page, 'left', __( 'Updates Overview', 'mainwp' ) );
		}

		// Load the Recent Posts widget.
		if ( mainwp_current_user_have_right( 'dashboard', 'manage_posts' ) ) {
			if ( self::$enable_widgets['recent_posts'] ) {
				MainWP_UI::add_widget_box( 'recent_posts', array( MainWP_Recent_Posts::get_class_name(), 'render' ), self::$page, 'right', __( 'Recent Posts', 'mainwp' ) );
			}
		}

		// Load the Recent Pages widget.
		if ( mainwp_current_user_have_right( 'dashboard', 'manage_pages' ) ) {
			if ( self::$enable_widgets['recent_pages'] ) {
				MainWP_UI::add_widget_box( 'recent_pages', array( MainWP_Recent_Pages::get_class_name(), 'render' ), self::$page, 'right', __( 'Recent Pages', 'mainwp' ) );
			}
		}

		// Load the Pluins widget.
		if ( self::$enable_widgets['plugins'] ) {
			MainWP_UI::add_widget_box( 'plugins', array( MainWP_Widget_Plugins::get_class_name(), 'render' ), self::$page, 'left', __( 'Plugins', 'mainwp' ) );
		}

		// Load the Themes widget.
		if ( self::$enable_widgets['themes'] ) {
			MainWP_UI::add_widget_box( 'themes', array( MainWP_Widget_Themes::get_class_name(), 'render' ), self::$page, 'left', __( 'Themes', 'mainwp' ) );
		}

		// Load the Connection Status widget.
		if ( self::$enable_widgets['connection_status'] ) {
			MainWP_UI::add_widget_box( 'connection_status', array( MainWP_Connection_Status::get_class_name(), 'render' ), self::$page, 'left', __( 'Connection Status', 'mainwp' ) );
		}

		// Load the Securtiy Issues widget.
		if ( mainwp_current_user_have_right( 'dashboard', 'manage_security_issues' ) ) {
			if ( self::$enable_widgets['security_issues'] ) {
				MainWP_UI::add_widget_box( 'security_issues', array( MainWP_Security_Issues_Widget::get_class_name(), 'render_widget' ), self::$page, 'right', __( 'Security Issues', 'mainwp' ) );
			}
		}

		// Load the Notes widget.
		if ( self::$enable_widgets['notes'] ) {
			MainWP_UI::add_widget_box( 'notes', array( MainWP_Notes::get_class_name(), 'render' ), self::$page, 'left', __( 'Notes', 'mainwp' ) );
		}

		// Load the Site Info widget.
		MainWP_UI::add_widget_box( 'child_site_info', array( MainWP_Site_Info::get_class_name(), 'render' ), self::$page, 'right', __( 'Child site info', 'mainwp' ) );

		$i = 0;
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
				MainWP_UI::add_widget_box( $id, $metaBox['callback'], self::$page, 'right', $metaBox['metabox_title'] );
			}
		}
	}

	/**
	 * Method render_updates()
	 *
	 * Render updates.
	 *
	 * @param mixed $website Child Site.
	 */
	public static function render_updates( $website ) {
		MainWP_System_Utility::set_current_wpid( $website->id );
		self::render_header( 'ManageSitesUpdates' );
		MainWP_Manage_Sites_Update_View::render_updates( $website );
		self::render_footer( 'ManageSitesUpdates' );
	}

	/**
	 * Method render_dashboard()
	 *
	 * Render Manage Sites Dashboard.
	 *
	 * @param mixed $website Child Site.
	 */
	public static function render_dashboard( $website ) {
		MainWP_System_Utility::set_current_wpid( $website->id );
		self::render_header( 'ManageSitesDashboard' );
		MainWP_Manage_Sites_View::render_dashboard( $website, self::$page );
		self::render_footer( 'ManageSitesDashboard' );
	}

	/**
	 * Method render_backup_site()
	 *
	 * Render Manage Sites Backups.
	 *
	 * @param mixed $website Child Site.
	 */
	public static function render_backup_site( $website ) {
		MainWP_System_Utility::set_current_wpid( $website->id );
		self::render_header( 'ManageSitesBackups' );
		MainWP_Manage_Sites_Backup_View::render_backup_site( $website );
		self::render_footer( 'ManageSitesBackups' );
	}

	/**
	 * Method render_scan_site()
	 *
	 * Render Security Scan.
	 *
	 * @param mixed $website Child Site.
	 */
	public static function render_scan_site( $website ) {
		MainWP_System_Utility::set_current_wpid( $website->id );
		self::render_header( 'SecurityScan' );
		MainWP_Manage_Sites_View::render_scan_site( $website );
		self::render_footer( 'SecurityScan' );
	}

	/**
	 * Method show_backups()
	 *
	 * Render Backups.
	 *
	 * @param mixed $website Child Site.
	 */
	public static function show_backups( &$website ) {
		$dir = MainWP_System_Utility::get_mainwp_specific_dir( $website->id );

		if ( ! file_exists( $dir . 'index.php' ) ) {
			touch( $dir . 'index.php' );
		}
		$dbBackups   = array();
		$fullBackups = array();
		if ( file_exists( $dir ) ) {
			$dh = opendir( $dir );
			if ( $dh ) {
				while ( false !== ( $file = readdir( $dh ) ) ) {
					if ( '.' !== $file && '..' !== $file ) {
						$theFile = $dir . $file;
						if ( MainWP_Backup_Handler::is_sql_file( $file ) ) {
							$dbBackups[ filemtime( $theFile ) . $file ] = $theFile;
						} elseif ( MainWP_Backup_Handler::is_archive( $file ) ) {
							$fullBackups[ filemtime( $theFile ) . $file ] = $theFile;
						}
					}
				}
				closedir( $dh );
			}
		}
		krsort( $dbBackups );
		krsort( $fullBackups );

		MainWP_Manage_Sites_Backup_View::show_backups( $website, $fullBackups, $dbBackups );
	}

	/**
	 * Method render_all_sites()
	 *
	 * Render manage sites content.
	 *
	 * @param boolean $showDelete true|false Show delete option.
	 * @param boolean $showAddNew true|false Show add new option.
	 */
	public static function render_all_sites( $showDelete = true, $showAddNew = true ) {

		$optimize_for_sites_table = ( 1 === get_option( 'mainwp_optimize' ) );

		if ( ! $optimize_for_sites_table ) {
			self::$sitesTable->prepare_items( false );
		}

		self::render_header( '' );

		if ( MainWP_Twitter::enabled_twitter_messages() ) {
			$filter = array(
				'upgrade_all_plugins',
				'upgrade_all_themes',
				'upgrade_all_wp_core',
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
									<i class="ui close icon mainwp-dismiss-twit"></i><span class="mainwp-tip" twit-what="<?php echo esc_attr( $what ); ?>" twit-id="<?php echo esc_attr( $timeid ); ?>"><?php echo $twit_mess; ?></span>&nbsp;<?php MainWP_Twitter::gen_twitter_button( $sendText ); ?>
								</div>
								<?php
							}
						}
					}
				}
			}
		}

		?>
		<div id="mainwp-manage-sites-content" class="ui segment">
			<div id="mainwp-message-zone" style="display:none;" class="ui message"></div>
			<form method="post" class="mainwp-table-container">
				<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
				<?php
				self::$sitesTable->display( $optimize_for_sites_table );
				self::$sitesTable->clear_items();
				?>
			</form>
		</div>
		<?php MainWP_UI::render_modal_edit_notes(); ?>
		<div class="ui modal" id="managesites-backup-box" tabindex="0">
			<div class="header"><?php esc_html_e( 'Full backup required', 'mainwp' ); ?></div>
			<div class="content mainwp-modal-content"></div>
			<div class="actions mainwp-modal-actions">
				<input id="managesites-backup-all" type="button" name="Backup All" value="<?php esc_attr_e( 'Backup all', 'mainwp' ); ?>" class="button-primary"/>
				<a id="managesites-backup-now" href="#" target="_blank" style="display: none"  class="button-primary button"><?php esc_html_e( 'Backup Now', 'mainwp' ); ?></a>&nbsp;
				<input id="managesites-backup-ignore" type="button" name="Ignore" value="<?php esc_attr_e( 'Ignore', 'mainwp' ); ?>" class="button"/>
			</div>
		</div>
		<?php
		self::render_screen_options();
		self::render_footer( '' );
	}

	/**
	 * Method render_manage_sites()
	 *
	 * Render Manage Sites Page.
	 */
	public static function render_manage_sites() { // phpcs:ignore -- complex method.
		global $current_user;

		if ( isset( $_REQUEST['do'] ) ) {
			if ( 'new' === $_REQUEST['do'] ) {
				self::render_new_site();
			} elseif ( 'bulknew' === $_REQUEST['do'] ) {
				self::render_bulk_new_site();
			}

			return;
		}

		if ( get_option( 'mainwp_enableLegacyBackupFeature' ) ) {
			if ( isset( $_GET['backupid'] ) && MainWP_Utility::ctype_digit( $_GET['backupid'] ) ) {
				$websiteid = $_GET['backupid'];

				$backupwebsite = MainWP_DB::instance()->get_website_by_id( $websiteid );
				if ( MainWP_System_Utility::can_edit_website( $backupwebsite ) ) {
					self::render_backup_site( $backupwebsite );

					return;
				}
			}
		}

		if ( isset( $_GET['scanid'] ) && MainWP_Utility::ctype_digit( $_GET['scanid'] ) ) {
			$websiteid = $_GET['scanid'];

			$scanwebsite = MainWP_DB::instance()->get_website_by_id( $websiteid );
			if ( MainWP_System_Utility::can_edit_website( $scanwebsite ) ) {
				self::render_scan_site( $scanwebsite );

				return;
			}
		}

		if ( isset( $_GET['dashboard'] ) && MainWP_Utility::ctype_digit( $_GET['dashboard'] ) ) {
			$websiteid = $_GET['dashboard'];

			$dashboardWebsite = MainWP_DB::instance()->get_website_by_id( $websiteid );
			if ( MainWP_System_Utility::can_edit_website( $dashboardWebsite ) ) {
				self::render_dashboard( $dashboardWebsite );

				return;
			}
		}

		if ( isset( $_GET['updateid'] ) && MainWP_Utility::ctype_digit( $_GET['updateid'] ) ) {
			$websiteid      = $_GET['updateid'];
			$updatesWebsite = MainWP_DB::instance()->get_website_by_id( $websiteid );
			if ( MainWP_System_Utility::can_edit_website( $updatesWebsite ) ) {
				self::render_updates( $updatesWebsite );
				return;
			}
		}

		if ( isset( $_GET['id'] ) && MainWP_Utility::ctype_digit( $_GET['id'] ) ) {
			$websiteid = $_GET['id'];

			$website = MainWP_DB::instance()->get_website_by_id( $websiteid );
			if ( MainWP_System_Utility::can_edit_website( $website ) ) {

				global $current_user;
				$updated = false;
				// Edit website!
				if ( isset( $_POST['submit'] ) && isset( $_POST['mainwp_managesites_edit_siteadmin'] ) && ( '' !== $_POST['mainwp_managesites_edit_siteadmin'] ) && wp_verify_nonce( $_POST['wp_nonce'], 'UpdateWebsite' . $_GET['id'] ) ) {
					if ( mainwp_current_user_have_right( 'dashboard', 'edit_sites' ) ) {
						// update site.
						$groupids   = array();
						$groupnames = array();
						$tmpArr     = array();
						if ( isset( $_POST['mainwp_managesites_edit_addgroups'] ) && ! empty( $_POST['mainwp_managesites_edit_addgroups'] ) ) {
							$groupids = explode( ',', $_POST['mainwp_managesites_edit_addgroups'] );
						}

						// to fix update staging site.
						if ( $website->is_staging ) {
							$stag_gid = get_option( 'mainwp_stagingsites_group_id' );
							if ( $stag_gid ) {
								if ( ! in_array( $stag_gid, $groupids, true ) ) {
									$groupids[] = $stag_gid;
								}
							}
						}

						$newPluginDir = '';

						$maximumFileDescriptorsOverride = isset( $_POST['mainwp_options_maximumFileDescriptorsOverride'] );
						$maximumFileDescriptorsAuto     = isset( $_POST['mainwp_maximumFileDescriptorsAuto'] );
						$maximumFileDescriptors         = isset( $_POST['mainwp_options_maximumFileDescriptors'] ) && MainWP_Utility::ctype_digit( $_POST['mainwp_options_maximumFileDescriptors'] ) ? $_POST['mainwp_options_maximumFileDescriptors'] : 150;

						$archiveFormat = isset( $_POST['mainwp_archiveFormat'] ) ? $_POST['mainwp_archiveFormat'] : 'global';

						$http_user = $_POST['mainwp_managesites_edit_http_user'];
						$http_pass = $_POST['mainwp_managesites_edit_http_pass'];
						$url       = $_POST['mainwp_managesites_edit_siteurl_protocol'] . '://' . MainWP_Utility::remove_http_prefix( $website->url, true );

						MainWP_DB::instance()->update_website( $websiteid, $url, $current_user->ID, $_POST['mainwp_managesites_edit_sitename'], $_POST['mainwp_managesites_edit_siteadmin'], $groupids, $groupnames, '', $newPluginDir, $maximumFileDescriptorsOverride, $maximumFileDescriptorsAuto, $maximumFileDescriptors, $_POST['mainwp_managesites_edit_verifycertificate'], $archiveFormat, isset( $_POST['mainwp_managesites_edit_uniqueId'] ) ? $_POST['mainwp_managesites_edit_uniqueId'] : '', $http_user, $http_pass, $_POST['mainwp_managesites_edit_ssl_version'] );
						do_action( 'mainwp_update_site', $websiteid );

						$backup_before_upgrade = isset( $_POST['mainwp_backup_before_upgrade'] ) ? intval( $_POST['mainwp_backup_before_upgrade'] ) : 2;
						if ( 2 < $backup_before_upgrade ) {
							$backup_before_upgrade = 2;
						}

						$forceuseipv4 = isset( $_POST['mainwp_managesites_edit_forceuseipv4'] ) ? intval( $_POST['mainwp_managesites_edit_forceuseipv4'] ) : 0;
						if ( 2 < $forceuseipv4 ) {
							$forceuseipv4 = 0;
						}

						$newValues = array(
							'automatic_update'       => ( ! isset( $_POST['mainwp_automaticDailyUpdate'] ) ? 0 : 1 ),
							'backup_before_upgrade'  => $backup_before_upgrade,
							'force_use_ipv4'         => $forceuseipv4,
							'loadFilesBeforeZip'     => isset( $_POST['mainwp_options_loadFilesBeforeZip'] ) ? 1 : 0,
						);

						if ( mainwp_current_user_have_right( 'dashboard', 'ignore_unignore_updates' ) ) {
							$newValues['is_ignoreCoreUpdates']   = ( isset( $_POST['mainwp_is_ignoreCoreUpdates'] ) && $_POST['mainwp_is_ignoreCoreUpdates'] ) ? 1 : 0;
							$newValues['is_ignorePluginUpdates'] = ( isset( $_POST['mainwp_is_ignorePluginUpdates'] ) && ( $_POST['mainwp_is_ignorePluginUpdates'] ) ) ? 1 : 0;
							$newValues['is_ignoreThemeUpdates']  = ( isset( $_POST['mainwp_is_ignoreThemeUpdates'] ) && ( $_POST['mainwp_is_ignoreThemeUpdates'] ) ) ? 1 : 0;
						}

						MainWP_DB::instance()->update_website_values( $websiteid, $newValues );
						$updated = true;
					}
				}
				self::render_edit_site( $websiteid, $updated );
				return;
			}
		}
		self::render_all_sites();
	}

	/**
	 * Method render_edit_site()
	 *
	 * Render edit site.
	 *
	 * @param mixed $websiteid Child Site ID.
	 * @param mixed $updated Updated.
	 */
	public static function render_edit_site( $websiteid, $updated ) {
		if ( $websiteid ) {
			MainWP_System_Utility::set_current_wpid( $websiteid );
		}
		self::render_header( 'ManageSitesEdit' );
		MainWP_Manage_Sites_View::render_edit_site( $websiteid, $updated );
		self::render_footer( 'ManageSitesEdit' );
	}

	/**
	 * Method on_edit_site()
	 *
	 * Render on edit.
	 *
	 * @param object $website The website object.
	 */
	public static function on_edit_site( $website ) {
		if ( isset( $_POST['submit'] ) && isset( $_POST['mainwp_managesites_edit_siteadmin'] ) && ( '' !== $_POST['mainwp_managesites_edit_siteadmin'] ) && wp_verify_nonce( $_POST['wp_nonce'], 'UpdateWebsite' . $_GET['id'] ) ) {
			if ( isset( $_POST['mainwp_managesites_edit_uniqueId'] ) ) {
				?>
				<script type="text/javascript">
					jQuery( document ).ready( function () {
						mainwp_managesites_update_childsite_value( <?php echo esc_attr( $website->id ); ?>, '<?php echo esc_js( $website->uniqueId ); ?>' );
					} );
				</script>
				<?php
			}
		}
	}

	/** Hook the section help content to the Help Sidebar element. */
	public static function mainwp_help_content() {
		if ( isset( $_GET['page'] ) && 'managesites' === $_GET['page'] ) {
			if ( isset( $_GET['do'] ) && 'new' === $_GET['do'] ) {
				?>
				<p><?php esc_html_e( 'If you need help connecting your websites, please review following help documents', 'mainwp' ); ?></p>
				<div class="ui relaxed bulleted list">
					<div class="item"><a href="https://mainwp.com/help/docs/set-up-the-mainwp-plugin/" target="_blank">Set up the MainWP Plugin</a></div>
					<div class="item"><a href="https://mainwp.com/help/docs/set-up-the-mainwp-plugin/install-mainwp-child/" target="_blank">Install MainWP Child</a></div>
					<div class="item"><a href="https://mainwp.com/help/docs/set-up-the-mainwp-plugin/set-unique-security-id/" target="_blank">Set Unique Security ID</a></div>
					<div class="item"><a href="https://mainwp.com/help/docs/set-up-the-mainwp-plugin/add-site-to-your-dashboard/" target="_blank">Add a Site to your Dashboard</a></div>
					<div class="item"><a href="https://mainwp.com/help/docs/set-up-the-mainwp-plugin/add-site-to-your-dashboard/import-sites/" target="_blank">Import Sites</a></div>
				</div>
				<?php
			} elseif ( isset( $_GET['do'] ) && 'bulknew' === $_GET['do'] ) {
				?>
				<p><?php esc_html_e( 'If you need help connecting your websites, please review following help documents', 'mainwp' ); ?></p>
				<div class="ui relaxed bulleted list">
					<div class="item"><a href="https://mainwp.com/help/docs/set-up-the-mainwp-plugin/" target="_blank">Set up the MainWP Plugin</a></div>
					<div class="item"><a href="https://mainwp.com/help/docs/set-up-the-mainwp-plugin/install-mainwp-child/" target="_blank">Install MainWP Child</a></div>
					<div class="item"><a href="https://mainwp.com/help/docs/set-up-the-mainwp-plugin/set-unique-security-id/" target="_blank">Set Unique Security ID</a></div>
					<div class="item"><a href="https://mainwp.com/help/docs/set-up-the-mainwp-plugin/add-site-to-your-dashboard/" target="_blank">Add a Site to your Dashboard</a></div>
					<div class="item"><a href="https://mainwp.com/help/docs/set-up-the-mainwp-plugin/add-site-to-your-dashboard/import-sites/" target="_blank">Import Sites</a></div>
				</div>
				<?php
			} else {
				?>
				<p><?php esc_html_e( 'If you need help with managing child sites, please review following help documents', 'mainwp' ); ?></p>
				<div class="ui relaxed bulleted list">
					<div class="item"><a href="https://mainwp.com/help/docs/manage-child-sites/" target="_blank">Manage Child Sites</a></div>
					<div class="item"><a href="https://mainwp.com/help/docs/manage-child-sites/access-child-site-wp-admin/" target="_blank">Access Child Site WP Admin</a></div>
					<div class="item"><a href="https://mainwp.com/help/docs/manage-child-sites/synchronize-a-child-site/" target="_blank">Synchronize a Child Site</a></div>
					<div class="item"><a href="https://mainwp.com/help/docs/manage-child-sites/edit-a-child-site/" target="_blank">Edit a Child Site</a></div>
					<div class="item"><a href="https://mainwp.com/help/docs/manage-child-sites/reconnect-a-child-site/" target="_blank">Reconnect a Child Site</a></div>
					<div class="item"><a href="https://mainwp.com/help/docs/manage-child-sites/delete-a-child-site/" target="_blank">Delete a Child Site</a></div>
					<div class="item"><a href="https://mainwp.com/help/docs/manage-child-sites/security-issues/" target="_blank">Security Issues</a></div>
					<div class="item"><a href="https://mainwp.com/help/docs/manage-child-sites/manage-child-site-groups/" target="_blank">Manage Child Site Groups</a></div>
					<div class="item"><a href="https://mainwp.com/help/docs/manage-child-sites/manage-child-site-notes/" target="_blank">Manage Child Site Notes</a></div>
				</div>
				<?php
			}
		}
	}

}
