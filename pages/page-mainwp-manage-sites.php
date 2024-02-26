<?php
/**
 * MainWP Manage Sites Page.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Manage_Sites
 *
 * @package MainWP\Dashboard
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
		'overview'           => true,
		'recent_posts'       => true,
		'recent_pages'       => true,
		'security_issues'    => true,
		'manage_backups'     => true,
		'plugins'            => true,
		'themes'             => true,
		'notes'              => true,
		'site_note'          => true,
		'client_info'        => true,
		'non_mainwp_changes' => true,

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
	 *
	 * @uses \MainWP\Dashboard\MainWP_Security_Issues::get_class_name()
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
		add_action( 'mainwp-pageheader-sites', array( self::get_class_name(), 'render_header' ) ); // @deprecated Use 'mainwp_pageheader_sites' instead.
		add_action( 'mainwp_pageheader_sites', array( self::get_class_name(), 'render_header' ) );

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
		add_action( 'mainwp-pagefooter-sites', array( self::get_class_name(), 'render_footer' ) ); // @deprecated Use 'mainwp_pagefooter_sites' instead.
		add_action( 'mainwp_pagefooter_sites', array( self::get_class_name(), 'render_footer' ) );

		add_action( 'mainwp_securityissues_sites', array( MainWP_Security_Issues::get_class_name(), 'render' ) );
		add_action( 'mainwp_manage_sites_edit', array( self::get_class_name(), 'on_edit_site' ) );

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
		if ( $screen === self::$page ) {
			$columns[ self::$page ] = 3;
		}

		return $columns;
	}

	/**
	 * Initiate menu.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Manage_Sites_View::init_menu()
	 * @uses \MainWP\Dashboard\MainWP_Manage_Sites_View::init_left_menu()
	 * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
	 * @uses \MainWP\Dashboard\MainWP_Site_Open::get_class_name()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::ctype_digit()
	 */
	public static function init_menu() {
		self::$page = MainWP_Manage_Sites_View::init_menu();
		add_action( 'load-' . self::$page, array( self::get_class_name(), 'on_load_page' ) );

		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( isset( $_REQUEST['dashboard'] ) ) {
 		// phpcs:enable
			/**
			 * Current user global.
			 *
			 * @global string
			 */
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
			'<div class="mainwp-hidden">' . esc_html__( 'Sites', 'mainwp' ) . '</div>',
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
			'<div class="mainwp-hidden">' . esc_html__( 'Sites', 'mainwp' ) . '</div>',
			'read',
			'SiteRestore',
			array(
				MainWP_Site_Open::get_class_name(),
				'render_restore',
			)
		);

		/**
		 * Sites Subpages
		 *
		 * Filters subpages for the Sites page.
		 *
		 * @since Unknown
		 */
		$sub_pages      = array();
		$sub_pages      = apply_filters_deprecated( 'mainwp-getsubpages-sites', array( $sub_pages ), '4.0.7.2', 'mainwp_getsubpages_sites' ); // @deprecated Use 'mainwp_getsubpages_sites' instead.
		self::$subPages = apply_filters( 'mainwp_getsubpages_sites', $sub_pages );

		if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
			foreach ( self::$subPages as $subPage ) {
				if ( ! empty( $subPage['no_submenu_page'] ) ) {
					continue;
				}
				if ( ! isset( $subPage['slug'] ) && ! isset( $subPage['title'] ) ) {
					continue;
				}
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

	/**
	 * Initiate sub page menu.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Manage_Sites_View::init_subpages_menu()
	 */
	public static function init_subpages_menu() {
		MainWP_Manage_Sites_View::init_subpages_menu( self::$subPages );
	}

	/**
	 * Method on_load_page()
	 *
	 * Run on page load.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Manage_Sites_List_Table
	 * @uses \MainWP\Dashboard\MainWP_System::enqueue_postbox_scripts()
	 */
	public static function on_load_page() {

		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
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
		} elseif ( isset( $_GET['id'] ) || isset( $_GET['scanid'] ) || isset( $_GET['backupid'] ) || isset( $_GET['updateid'] ) || isset( $_GET['emailsettingsid'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,ized
			return;
		}
		// phpcs:enable

		$sitesViewMode = MainWP_Utility::get_siteview_mode();

		add_filter( 'mainwp_header_actions_right', array( self::get_class_name(), 'screen_options' ), 10, 2 );
		if ( 'grid' !== $sitesViewMode ) {
			self::load_sites_table();
		} else {
			new MainWP_Manage_Screenshots(); // to init hooks.
		}
	}


	/**
	 * Method load_sites_table()
	 *
	 * Load sites table.
	 */
	public static function load_sites_table() {
		if ( empty( self::$sitesTable ) ) {
			self::$sitesTable = new MainWP_Manage_Sites_List_Table();
		}
	}


	/**
	 * Method on_load_sunpages()
	 *
	 * Run on subpage load.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::set_current_wpid()
	 */
	public static function on_load_subpages() {
		if ( ! empty( $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,ized
			MainWP_System_Utility::set_current_wpid( intval( $_GET['id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification,ized
		}
	}

	/**
	 * Method render_header()
	 *
	 * Render page header.
	 *
	 * @param string $shownPage Current page slug.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Deprecated_Hooks::maybe_handle_deprecated_hook()
	 * @uses \MainWP\Dashboard\MainWP_Manage_Sites_View::render_header()
	 */
	public static function render_header( $shownPage = '' ) {
		MainWP_Deprecated_Hooks::maybe_handle_deprecated_hook();
		MainWP_Manage_Sites_View::render_header( $shownPage, self::$subPages );
	}

	/**
	 * Method render_footer()
	 *
	 * Render page footer.
	 */
	public static function render_footer() {
		MainWP_Manage_Sites_View::render_footer();
	}

	/**
	 * Method screen_options()
	 *
	 * Create Page Settings button.
	 *
	 * @param mixed $input Page Settings button HTML.
	 *
	 * @return mixed Page Settings button.
	 */
	public static function screen_options( $input ) {
		return $input .
				'<a class="ui button basic icon" onclick="mainwp_manage_sites_screen_options(); return false;" data-inverted="" data-position="bottom right" href="#" target="_blank" data-tooltip="' . esc_html__( 'Page Settings', 'mainwp' ) . '">
					<i class="cog icon"></i>
				</a>';
	}

	/**
	 * Method render_screen_options()
	 *
	 * Render Page Settings Modal.
	 */
	public static function render_screen_options() {  // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		$columns = self::$sitesTable->get_columns();

		if ( isset( $columns['cb'] ) ) {
			unset( $columns['cb'] );
		}

		if ( isset( $columns['status'] ) ) {
			$columns['status'] = esc_html__( 'Status', 'mainwp' );
		}

		if ( isset( $columns['favicon'] ) ) {
			$columns['favicon'] = esc_html__( 'Favicon', 'mainwp' );
		}

		$sites_per_page = get_option( 'mainwp_default_sites_per_page', 25 );

		if ( isset( $columns['site_actions'] ) && empty( $columns['site_actions'] ) ) {
			$columns['site_actions'] = esc_html__( 'Actions', 'mainwp' );
		}

		$show_cols = get_user_option( 'mainwp_settings_show_manage_sites_columns' );
		if ( false === $show_cols ) { // to backwards.

				$default_cols = array(
					'site'         => 1,
					'login'        => 1,
					'url'          => 1,
					'update'       => 1,
					'site_health'  => 1,
					'last_sync'    => 1,
					'site_actions' => 1,
				);

				$show_cols = array();
				foreach ( $columns as $name => $title ) {
					if ( isset( $default_cols[ $name ] ) ) {
						$show_cols[ $name ] = 1;
					} else {
						$show_cols[ $name ] = 1; // show other columns.
					}
				}
				$user = wp_get_current_user();
				if ( $user ) {
					update_user_option( $user->ID, 'mainwp_settings_show_manage_sites_columns', $show_cols, true );
				}
		}

		if ( ! is_array( $show_cols ) ) {
			$show_cols = array();
		}

		$is_demo      = MainWP_Demo_Handle::is_demo_mode();
		$siteViewMode = MainWP_Utility::get_siteview_mode();
		?>
		<div class="ui modal" id="mainwp-manage-sites-screen-options-modal">
			<i class="close icon"></i>
			<div class="header"><?php esc_html_e( 'Page Settings', 'mainwp' ); ?></div>
			<div class="scrolling content ui form">
				<form method="POST" action="" id="manage-sites-screen-options-form" name="manage_sites_screen_options_form">
					<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
					<input type="hidden" name="wp_nonce" value="<?php echo esc_attr( wp_create_nonce( 'ManageSitesScrOptions' ) ); ?>" />
					<div class="ui grid field">
						<label class="top aligned six wide column" tabindex="0"><?php esc_html_e( 'Sites view mode', 'mainwp' ); ?></label>
						<div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Sites view mode.', 'mainwp' ); ?>" data-inverted="" data-position="left center">
							<div class="ui info message">
								<div><strong><?php echo esc_html__( 'Sites view mode is an experimental feature.', 'mainwp' ); ?></strong></div>
								<div><?php echo esc_html__( 'In the Grid mode, sites options are limited in comparison to the Table mode.', 'mainwp' ); ?></div>
								<div><?php echo esc_html__( 'Grid mode queries WordPress.com servers to capture a screenshot of your site the same way comments show you a preview of URLs.', 'mainwp' ); ?></div>
							</div>
							<select name="mainwp_sitesviewmode" id="mainwp_sitesviewmode" class="ui dropdown">
								<option value="table" <?php echo ( 'table' === $siteViewMode ? 'selected' : '' ); ?>><?php esc_html_e( 'Table', 'mainwp' ); ?></option>
								<option value="grid" <?php echo ( 'grid' === $siteViewMode ? 'selected' : '' ); ?>><?php esc_html_e( 'Grid', 'mainwp' ); ?></option>
							</select>
						</div>
					</div>
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
					<div class="ui grid field">
						<label class="six wide column"><?php esc_html_e( 'Show columns', 'mainwp' ); ?></label>
						<div class="ten wide column">
							<ul class="mainwp_hide_wpmenu_checkboxes">
								<?php
								foreach ( $columns as $name => $title ) {
									if ( empty( $title ) ) {
										continue;
									}
									?>
									<li>
										<div class="ui checkbox <?php echo ( 'site_preview' === $name ) ? 'site_preview not-auto-init' : ''; ?>">
											<input type="checkbox"
											<?php
											$show_col = ! isset( $show_cols[ $name ] ) || ( 1 === (int) $show_cols[ $name ] );

											if ( 'added_datetime' === $name && ! isset( $show_cols[ $name ] ) ) {
												$show_col = false; // default is hidden.
											}

											if ( $show_col ) {
												echo 'checked="checked"';
											}

											?>
											id="mainwp_show_column_<?php echo esc_attr( $name ); ?>" name="mainwp_show_column_<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $name ); ?>">
											<label for="mainwp_show_column_<?php echo esc_attr( $name ); ?>" ><?php echo $title; // phpcs:ignore WordPress.Security.EscapeOutput ?></label>
											<input type="hidden" value="<?php echo esc_attr( $name ); ?>" name="show_columns_name[]" />
										</div>
									</li>
									<?php
								}
								?>
							</ul>
						</div>
					</div>
					<div class="ui grid field">
						<label class="six wide column middle aligned"><?php esc_html_e( 'Optimize for shared hosting or big networks', 'mainwp' ); ?></label>
						<div class="ten wide column ui toggle checkbox"  data-tooltip="<?php esc_attr_e( 'If enabled, your MainWP Dashboard will cache updates for faster loading.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
							<input type="checkbox" name="mainwp_optimize" id="mainwp_optimize" <?php echo ( ( 1 === (int) get_option( 'mainwp_optimize', 1 ) ) ? 'checked="true"' : '' ); ?> /><label><?php esc_html_e( 'Default: Off', 'mainwp' ); ?></label>
						</div>
					</div>
					<div class="ui grid field">
						<label class="six wide column middle aligned"><?php esc_html_e( 'Export child sites to CSV file', 'mainwp' ); ?></label>
						<div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Click this button to export all connected sites to a CSV file.', 'mainwp' ); ?>" data-inverted="" data-position="top left"><a href="admin.php?page=MainWPTools&doExportSites=yes&_wpnonce=<?php echo esc_html( wp_create_nonce( 'export_sites' ) ); ?>" class="ui button green basic"><?php esc_html_e( 'Export Child Sites', 'mainwp' ); ?></a></div>
					</div>
					<div class="ui grid field">
						<label class="six wide column middle aligned"><?php esc_html_e( 'Disconnect all child sites', 'mainwp' ); ?></label>
						<div class="ten wide column" id="mainwp-disconnect-sites-tool" data-tooltip="<?php esc_attr_e( 'This will function will break the connection and leave the MainWP Child plugin active.', 'mainwp' ); ?>" data-variation="inverted" data-position="top left">
							<?php
							if ( $is_demo ) {
								MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<a href="#" disabled="disabled" class="ui button green basic disabled">' . esc_html__( 'Disconnect Websites.', 'mainwp' ) . '</a>' );
							} else {
								?>
								<a href="admin.php?page=MainWPTools&disconnectSites=yes&_wpnonce=<?php echo esc_html( wp_create_nonce( 'disconnect_sites' ) ); ?>" onclick="mainwp_tool_disconnect_sites(); return false;" class="ui button green basic"><?php esc_html_e( 'Disconnect Websites.', 'mainwp' ); ?></a>
							<?php } ?>
						</div>
					</div>
					<div class="ui hidden divider"></div>
					<div class="ui hidden divider"></div>
				</div>
				<div class="actions">
					<div class="ui two columns grid">
						<div class="left aligned column">
							<span data-tooltip="<?php esc_attr_e( 'Resets the page to its original layout and reinstates relocated columns.', 'mainwp' ); ?>" data-inverted="" data-position="top center"><input type="button" class="ui button" name="reset" id="reset-managersites-settings" value="<?php esc_attr_e( 'Reset Page', 'mainwp' ); ?>" /></span>
						</div>
						<div class="ui right aligned column">
					<input type="submit" class="ui green button" name="btnSubmit" id="submit-managersites-settings" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>" />
				</div>
					</div>
				</div>
				<input type="hidden" name="reset_managersites_columns_order" value="0">
			</form>
		</div>
		<div class="ui small modal" id="mainwp-manage-sites-site-preview-screen-options-modal">
			<div class="header"><?php esc_html_e( 'Page Settings', 'mainwp' ); ?></div>
			<div class="scrolling content ui form">
				<span><?php esc_html_e( 'Would you like to turn on home screen previews?  This function queries WordPress.com servers to capture a screenshot of your site the same way comments shows you preview of URLs.', 'mainwp' ); ?>
			</div>
			<div class="actions">
				<div class="ui ok button"><?php esc_html_e( 'Yes', 'mainwp' ); ?></div>
				<div class="ui cancel button"><?php esc_html_e( 'No', 'mainwp' ); ?></div>
			</div>
		</div>
		<script type="text/javascript">
			jQuery( document ).ready( function () {
				jQuery( '.ui.checkbox.not-auto-init.site_preview' ).checkbox( {
					onChecked   : function() {
						var $chk = jQuery( this );
						jQuery( '#mainwp-manage-sites-site-preview-screen-options-modal' ).modal( {
							allowMultiple: true, // multiple modals.
							width: 100,
							onDeny: function () {
								$chk.prop('checked', false);
							}
						} ).modal( 'show' );
					}
				} );
				jQuery('#reset-managersites-settings').on( 'click', function () {
					mainwp_confirm(__( 'Are you sure.' ), function(){
						jQuery('#mainwp_sitesviewmode').dropdown( 'set selected', 'grid' );
						jQuery('input[name=mainwp_default_sites_per_page]').val(25);
						jQuery('.mainwp_hide_wpmenu_checkboxes input[id^="mainwp_show_column_"]').prop( 'checked', false );
						//default columns.
						var cols = ['status','site','login','url','update','client_name','site_actions'];
						jQuery.each( cols, function ( index, value ) {
							jQuery('.mainwp_hide_wpmenu_checkboxes input[id="mainwp_show_column_' + value + '"]').prop( 'checked', true );
						} );
						jQuery('input[name=reset_managersites_columns_order]').attr('value',1);
						jQuery('#submit-managersites-settings').click();
					}, false, false, true );
					return false;
				});
			} );
		</script>
		<?php
	}

	/**
	 * Method ajax_optimize_display_rows()
	 *
	 * Display table rows, optimize for shared hosting or big networks.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Manage_Sites_List_Table
	 */
	public static function ajax_optimize_display_rows() {
		self::load_sites_table();
		self::$sitesTable->prepare_items( true );
		$output = self::$sitesTable->ajax_get_datatable_rows();
		self::$sitesTable->clear_items();

		MainWP_Logger::instance()->log_execution_time( 'ajax_optimize_display_rows()' );

		wp_send_json( $output );
	}

	/**
	 * Method render_new_site()
	 *
	 * Render add new site page.
	 *
	 * @return string Add new site html.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB_Common::get_groups_for_current_user()
	 * @uses \MainWP\Dashboard\MainWP_DB::query()
	 * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_for_current_user()
	 * @uses \MainWP\Dashboard\MainWP_Manage_Sites_View::render_sync_exts_settings()
	 */
	public static function render_new_site() {
		$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );

		$showpage = 'AddNew';
		self::render_header( $showpage );

		if ( ! mainwp_current_user_have_right( 'dashboard', 'add_sites' ) ) {
			mainwp_do_not_have_permissions( esc_html__( 'add sites', 'mainwp' ) );
			return;
		} else {
			$groups = MainWP_DB_Common::instance()->get_groups_for_current_user();
			if ( ! is_array( $groups ) ) {
				$groups = array();
			}

			?>
		<div id="mainwp-add-new-site" class="ui segment">

			<?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-add-site-info-message' ) ) : ?>
				<div class="ui info message">
					<i class="close icon mainwp-notice-dismiss" notice-id="mainwp-add-site-info-message"></i>
					<div><?php printf( esc_html__( 'Use the provided form to connect your websites to your MainWP Dashboard.  For additional help, please check this %1$shelp documentation%2$s.', 'mainwp' ), '<a href="https://kb.mainwp.com/docs/add-site-to-your-dashboard/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); ?></div>
					<div><?php printf( esc_html__( 'If you are experiencing issues with adding a website to your MainWP Dashboard, use the %1$sTest Connection%2$s feature to ensure that your MainWP Dashboard can communicate with your website.', 'mainwp' ), '<a href="https://kb.mainwp.com/docs/test-connection-between-your-mainwp-dashboard-and-child-site/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); ?></div>
					<div><?php printf( esc_html__( 'If you still can not connect the site, see the list of %1$spotential issues%2$s.', 'mainwp' ), '<a href="https://kb.mainwp.com/docs/potential-issues/" target="_blank">', '</a>' ); ?></div>
					</div>
			<?php endif; ?>

			<div id="mainwp-message-zone" style="display: none;" class="ui message"></div>

			<div id="mainwp_managesites_add_errors" style="display: none" class="mainwp-notice mainwp-notice-red"></div>
			<div id="mainwp_managesites_add_message" style="display: none" class="mainwp-notice mainwp-notice-green"></div>
			<div class="ui info message" id="mainwp_message_verify_installed_child" style="display:none">
				<?php esc_html_e( 'MainWP requires the MainWP Child plugin to be installed and activated on the WordPress site that you want to connect to your MainWP Dashboard. ', 'mainwp' ); ?>
				<?php esc_html_e( 'To install the MainWP Child plugin, please follow these steps:', 'mainwp' ); ?>
				<ol>
					<li><?php printf( esc_html__( 'Login to the WordPress site you want to connect %1$s(open it in a new browser tab)%2$s', 'mainwp' ), '<em>', '</em>' ); ?></li>
					<li><?php printf( esc_html__( 'Go to the %1$sWP > Plugins%2$s page', 'mainwp' ), '<strong>', '</strong>' ); ?></li>
					<li><?php printf( esc_html__( 'Click %1$sAdd New%2$s to install a new plugin', 'mainwp' ), '<strong>', '</strong>' ); ?></li>
					<li><?php printf( esc_html__( 'In the %1$sSearch Field%2$s, enter "MainWP Child" and once the plugin shows, click the Install button', 'mainwp' ), '<strong>', '</strong>' ); ?></li>
					<li><?php printf( esc_html__( '%1$sActivate%2$s the plugin', 'mainwp' ), '<strong>', '</strong>' ); ?></li>
				</ol>
			</div>

			<form method="POST" class="ui form" action="" enctype="multipart/form-data" id="mainwp_managesites_add_form">
				<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
				<h3 class="ui dividing header">
					<?php esc_html_e( 'Add a New Site', 'mainwp' ); ?>
					<div class="sub header"><?php esc_html_e( 'Add a new site or use the Test Connection feature to check if the site can be connected.', 'mainwp' ); ?></div>
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
					<div class="ui four wide middle aligned column">
						<input type="button" name="mainwp_managesites_edit_test" id="mainwp_managesites_test" class="ui button basic green" value="<?php esc_attr_e( 'Test Connection', 'mainwp' ); ?>"/>
				</div>
				</div>
				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'Verify that the MainWP Child plugin is installed and activated', 'mainwp' ); ?></label>
					<div class="six wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'Verify that MainWP Child is Installed and Activated.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<input type="checkbox" name="mainwp_managesites_verify_installed_child" id="mainwp_managesites_verify_installed_child" />
						<label><?php esc_attr_e( 'Select to confirm that the MainWP Child plugin is active on the child site.', 'mainwp' ); ?></label>
					</div>
				</div>

				<div id="mainwp-add-site-hidden-form" style="display:none">
					<h3 class="ui dividing header">
						<?php esc_html_e( 'Required Settings', 'mainwp' ); ?>
						<div class="sub header"><?php esc_html_e( 'Enter administrator username and site title.', 'mainwp' ); ?></div>
					</h3>

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

					<div class="ui grid field">
						<div class="six wide column middle aligned">
							<a href="#" id="mainwp-add-site-advanced-options-toggle"><i class="eye slash outline icon"></i> <?php esc_html_e( 'Show Optional Settings', 'mainwp' ); ?></a>
						</div>
						<div class="six wide column middle aligned">
						</div>
					</div>

					<div id="mainwp-add-site-advanced-options" class="ui secondary segment" style="display:none" >

				<h3 class="ui dividing header">
					<?php esc_html_e( 'Optional Settings', 'mainwp' ); ?>
							<div class="sub header"><?php esc_html_e( 'Use optional settings when needed. In most cases, you can leave the default values.', 'mainwp' ); ?></div>
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
					<label class="six wide column middle aligned"><?php esc_html_e( 'Tags (optional)', 'mainwp' ); ?></label>
					<div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Add the website to existing tag(s).', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<div class="ui multiple search selection dropdown" init-value="" id="mainwp_managesites_add_addgroups">
							<i class="dropdown icon"></i>
							<div class="default text"></div>
							<div class="menu">
								<?php foreach ( $groups as $group ) { ?>
									<div class="item" data-value="<?php echo intval( $group->id ); ?>"><?php echo esc_html( stripslashes( $group->name ) ); ?></div>
								<?php } ?>
							</div>
						</div>
					</div>
				</div>
				<?php
				$clients = MainWP_DB_Client::instance()->get_wp_client_by( 'all' );
				?>
				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'Client (optional)', 'mainwp' ); ?></label>
					<div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Add a client to the website.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<div class="ui search selection dropdown" init-value="" id="mainwp_managesites_add_client_id">
							<i class="dropdown icon"></i>
							<div class="default text"></div>
							<div class="menu">								
								<div class="item" data-value="0"><?php esc_attr_e( 'Select client', 'mainwp' ); ?></div>
								<?php foreach ( $clients as $client ) { ?>
									<div class="item" data-value="<?php echo intval( $client->client_id ); ?>"><?php echo esc_html( $client->name ); ?></div>
								<?php } ?>
							</div>
						</div>
					</div>
				</div>

				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'Verify SSL certificate (optional)', 'mainwp' ); ?></label>
					<div class="six wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'Do you want to verify SSL certificate.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<input type="checkbox" name="mainwp_managesites_verify_certificate" id="mainwp_managesites_verify_certificate" checked="true" />
					</div>
				</div>

				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php esc_html_e( 'SSL version (optional)', 'mainwp' ); ?></label>
					<div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Select SSL Version. If you are not sure, select "Auto Detect".', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						<select class="ui dropdown" id="mainwp_managesites_add_ssl_version" name="mainwp_managesites_add_ssl_version">
							<option value="0"><?php esc_html_e( 'Auto detect', 'mainwp' ); ?></option>
							<option value="6"><?php esc_html_e( 'TLS v1.2', 'mainwp' ); ?></option>
							<option value="1"><?php esc_html_e( 'TLS v1.x', 'mainwp' ); ?></option>
							<option value="2"><?php esc_html_e( 'SSL v2', 'mainwp' ); ?></option>
							<option value="3"><?php esc_html_e( 'SSL v3', 'mainwp' ); ?></option>
							<option value="4"><?php esc_html_e( 'TLS v1.0', 'mainwp' ); ?></option>
							<option value="5"><?php esc_html_e( 'TLS v1.1', 'mainwp' ); ?></option>
						</select>
					</div>
				</div>

				<!-- fake fields are a workaround for chrome autofill getting the wrong fields. -->
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
						<?php MainWP_Manage_Sites_View::render_sync_exts_settings(); ?>
					</div>


				<?php
				do_action_deprecated( 'mainwp-manage-sites-edit', array( false ), '4.0.7.2', 'mainwp_manage_sites_edit' ); // @deprecated Use 'mainwp_manage_sites_edit' instead.

				/**
				 * Edit site
				 *
				 * Fires on the Edit child site page and allows user to hook in new site options.
				 *
				 * @param bool false
				 */
				do_action( 'mainwp_manage_sites_edit', false );
				?>

				<div class="ui divider"></div>
					<input type="button" name="mainwp_managesites_add" id="mainwp_managesites_add" class="ui button green big" value="<?php esc_attr_e( 'Add Site', 'mainwp' ); ?>" />
					<div class="ui hidden clearing divider"></div>
				</div>
			</form>
		</div>

		<div class="ui modal" id="mainwp-test-connection-modal">
			<i class="close icon"></i>
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
	 *
	 * @uses \MainWP\Dashboard\MainWP_Manage_Sites_View::render_import_sites()
	 */
	public static function render_bulk_new_site() {
		$showpage = 'BulkAddNew';
		self::render_header( $showpage );
		if ( ! mainwp_current_user_have_right( 'dashboard', 'add_sites' ) ) {
			mainwp_do_not_have_permissions( esc_html__( 'add sites', 'mainwp' ) );
			return;
		} elseif ( isset( $_FILES['mainwp_managesites_file_bulkupload'] ) && isset( $_FILES['mainwp_managesites_file_bulkupload']['error'] ) && UPLOAD_ERR_OK === $_FILES['mainwp_managesites_file_bulkupload']['error'] && check_admin_referer( 'mainwp-admin-nonce' ) ) {
			?>
				<div class="ui large modal" id="mainwp-import-sites-modal">
				<i class="close icon"></i>
					<div class="header"><?php esc_html_e( 'Import Sites', 'mainwp' ); ?></div>
					<div class="scrolling header">
					<?php MainWP_Manage_Sites_View::render_import_sites(); ?>
					</div>
					<div class="actions">
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
				<?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-import-sites-info-message' ) ) : ?>
						<div class="ui info message">
							<i class="close icon mainwp-notice-dismiss" notice-id="mainwp-import-sites-info-message"></i>
							<?php printf( esc_html__( 'You can download the sample CSV file to see how to format the import file properly. For additional help, please check this %1$shelp documentation%2$s.', 'mainwp' ), '<a href="https://kb.mainwp.com/docs/import-sites/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); ?>
						</div>
					<?php endif; ?>
					<div id="mainwp-message-zone" class="ui message" style="display:none"></div>
					<h3 class="ui dividing header">
					<?php esc_html_e( 'Import Sites', 'mainwp' ); ?>
						<div class="sub header"><?php esc_html_e( 'Import multiple websites to your MainWP Dashboard.', 'mainwp' ); ?></div>
					</h3>
					<form method="POST" action="" enctype="multipart/form-data" id="mainwp_managesites_bulkadd_form" class="ui form">
					<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'Upload the CSV file', 'mainwp' ); ?></label>
							<div class="ten wide column">
								<div class="ui file input">
								<input type="file" name="mainwp_managesites_file_bulkupload" id="mainwp_managesites_file_bulkupload" accept="text/comma-separated-values"/>
								</div>
							</div>
						</div>
						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'CSV file contains a header', 'mainwp' ); ?></label>
							<div class="ui toggle checkbox">
								<input type="checkbox" name="mainwp_managesites_chk_header_first" checked="checked" id="mainwp_managesites_chk_header_first" value="1"/>
							</div>
						</div>
						<div class="ui divider"></div>
						<input type="button" name="mainwp_managesites_add" id="mainwp_managesites_bulkadd" class="ui big green button" value="<?php esc_attr_e( 'Import Sites', 'mainwp' ); ?>"/>
						<a href="<?php echo esc_url( MAINWP_PLUGIN_URL . 'assets/csv/sample.csv' ); ?>" class="ui big green basic right floated button"><?php esc_html_e( 'Download Sample CSV file', 'mainwp' ); ?></a>
					</form>
				</div>
				<?php

		}
		self::render_footer( $showpage );
	}

	/**
	 * Method on_load_page_dashboard()
	 *
	 * Add individual meta boxes.
	 *
	 * @uses \MainWP\Dashboard\MainWP_System_Handler::apply_filters()
	 * @uses \MainWP\Dashboard\MainWP_UI::add_widget_box()
	 * @uses \MainWP\Dashboard\MainWP_Notes::get_class_name()
	 * @uses \MainWP\Dashboard\MainWP_Recent_Pages::get_class_name()
	 * @uses \MainWP\Dashboard\MainWP_Recent_Posts::get_class_name()
	 * @uses \MainWP\Dashboard\MainWP_Security_Issues_Widget::get_class_name()
	 * @uses \MainWP\Dashboard\MainWP_Site_Info::get_class_name()
	 * @uses \MainWP\Dashboard\MainWP_Updates_Overview::get_class_name()
	 * @uses \MainWP\Dashboard\MainWP_Widget_Plugins::get_class_name()
	 * @uses \MainWP\Dashboard\MainWP_Widget_Themes::get_class_name()
	 */
	public static function on_load_page_dashboard() { // phpcs:ignore -- current complexity is required to achieve desired results. Pull request solutions are welcome.
		wp_enqueue_script( 'common' );
		wp_enqueue_script( 'wp-lists' );
		wp_enqueue_script( 'postbox' );
		wp_enqueue_script( 'dashboard' );
		wp_enqueue_script( 'widgets' );

		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$dashboard_siteid = isset( $_GET['dashboard'] ) ? intval( $_GET['dashboard'] ) : null;
 		// phpcs:enable

		/**
		 * Get getmetaboxes
		 *
		 * Adds metaboxes (widgets) to the Overview page.
		 *
		 * @since Unknown
		 */
		$extMetaBoxs = MainWP_System_Handler::instance()->apply_filters( 'mainwp-getmetaboxes', array() );  // @deprecated Use 'mainwp_getmetaboxes' instead.
		$extMetaBoxs = apply_filters( 'mainwp_getmetaboxes', $extMetaBoxs, $dashboard_siteid ); // hooks load widgets for individual overview page and for manage sites's subpage.

		foreach ( $extMetaBoxs as $box ) {
			// to compatible.
			if ( isset( $box['custom'] ) && $box['custom'] && isset( $box['plugin'] ) ) {
				continue;
			}

			if ( isset( $box['plugin'] ) ) {
				$name                          = basename( $box['plugin'], '.php' );
				self::$enable_widgets[ $name ] = true;
			} elseif ( isset( $box['widget_id'] ) ) {
				self::$enable_widgets[ $box['widget_id'] ] = true;
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
		$values               = apply_filters( 'mainwp_overview_enabled_widgets', $values, $dashboard_siteid );
		self::$enable_widgets = array_merge( self::$enable_widgets, $values );

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
				$layout = array( 1, 1, 4, 11 );
				if ( isset( $metaBox['id'] ) && ( 'google-widget' === $metaBox['id'] || 'matomo' === $metaBox['id'] ) ) {
					$layout = array( 1, 1, 4, 15 );
				}
				$layout = ! empty( $metaBox['layout'] ) && is_array( $metaBox['layout'] ) ? $metaBox['layout'] : $layout;
				MainWP_UI::add_widget_box( $id, $metaBox['callback'], self::$page, $layout );
			}
		}

		// Load the Notes widget.
		if ( self::$enable_widgets['notes'] ) {
			MainWP_UI::add_widget_box( 'notes', array( MainWP_Notes::get_class_name(), 'render' ), self::$page, array( 1, 1, 4, 11 ) );
		}

		// Load the Client widget.
		if ( self::$enable_widgets['client_info'] ) {
			MainWP_UI::add_widget_box( 'client_info', array( MainWP_Client_Info::get_class_name(), 'render' ), self::$page, array( 1, 1, 4, 11 ) );
		}

		// Load the Recent Pages widget.
		if ( mainwp_current_user_have_right( 'dashboard', 'manage_pages' ) ) {
			if ( self::$enable_widgets['recent_pages'] ) {
				MainWP_UI::add_widget_box( 'recent_pages', array( MainWP_Recent_Pages::get_class_name(), 'render' ), self::$page, array( 1, 1, 6, 11 ) );
			}
		}

		// Load the Recent Posts widget.
		if ( mainwp_current_user_have_right( 'dashboard', 'manage_posts' ) ) {
			if ( self::$enable_widgets['recent_posts'] ) {
				MainWP_UI::add_widget_box( 'recent_posts', array( MainWP_Recent_Posts::get_class_name(), 'render' ), self::$page, array( 1, 1, 6, 11 ) );
			}
		}

		// Load the Themes widget.
		if ( self::$enable_widgets['themes'] ) {
			MainWP_UI::add_widget_box( 'themes', array( MainWP_Widget_Themes::get_class_name(), 'render' ), self::$page, array( 1, 1, 6, 11 ) );
		}

		// Load the Pluins widget.
		if ( self::$enable_widgets['plugins'] ) {
			MainWP_UI::add_widget_box( 'plugins', array( MainWP_Widget_Plugins::get_class_name(), 'render' ), self::$page, array( 1, 1, 6, 11 ) );
		}

		// Load the Non-MainWP Changes widget.
		if ( self::$enable_widgets['non_mainwp_changes'] ) {
			MainWP_UI::add_widget_box( 'non_mainwp_changes', array( MainWP_Site_Actions::get_class_name(), 'render' ), self::$page, array( 1, 1, 4, 10 ) );
		}

		// Load the Site Info widget.
		MainWP_UI::add_widget_box( 'child_site_info', array( MainWP_Site_Info::get_class_name(), 'render' ), self::$page, array( 1, 1, 4, 18 ) );

		// Load the Securtiy Issues widget.
		if ( mainwp_current_user_have_right( 'dashboard', 'manage_security_issues' ) ) {
			if ( self::$enable_widgets['security_issues'] ) {
				MainWP_UI::add_widget_box( 'security_issues', array( MainWP_Security_Issues_Widget::get_class_name(), 'render_widget' ), self::$page, array( 1, 1, 4, 8 ) );
			}
		}

		// Load the Updates Overview widget.
		if ( self::$enable_widgets['overview'] ) {
			MainWP_UI::add_widget_box( 'overview', array( MainWP_Updates_Overview::get_class_name(), 'render' ), self::$page, array( 1, 1, 4, 18 ) );
		}
	}

	/**
	 * Method render_updates()
	 *
	 * Render updates.
	 *
	 * @param mixed $website Child Site.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Manage_Sites_Update_View::render_updates()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::set_current_wpid()
	 */
	public static function render_updates( $website ) {
		MainWP_System_Utility::set_current_wpid( $website->id );
		self::render_header( 'ManageSitesUpdates' );
		MainWP_Manage_Sites_Update_View::render_updates( $website );
		self::render_footer( 'ManageSitesUpdates' );
	}

	/**
	 * Method render_email_settings()
	 *
	 * Render email settings.
	 *
	 * @param mixed $website Child Site.
	 * @param bool  $updated updated settings.
	 * @param bool  $updated_templ updated template file.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
	 * @uses \MainWP\Dashboard\MainWP_Manage_Sites_View::render_site_edit_email_settings()
	 * @uses \MainWP\Dashboard\MainWP_Notification_Settings::get_notification_types()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::set_current_wpid()
	 */
	public static function render_email_settings( $website, $updated, $updated_templ ) {
		$website = MainWP_DB::instance()->get_website_by_id( $website->id, false, array( 'settings_notification_emails' ) ); // reload.

		MainWP_System_Utility::set_current_wpid( $website->id );

		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$edit       = false;
		$email_type = isset( $_GET['edit-email'] ) ? sanitize_text_field( wp_unslash( $_GET['edit-email'] ) ) : false;

		if ( ! empty( $email_type ) ) {
			$notification_emails = MainWP_Notification_Settings::get_notification_types();
			if ( isset( $notification_emails[ $email_type ] ) ) {
				$edit = true;
			}
		}
		// phpcs:enable

		self::render_header( 'ManageSitesEmailSettings' );
		if ( $edit ) {
			MainWP_Manage_Sites_View::render_site_edit_email_settings( $website, $email_type, $updated_templ );
		} else {
			MainWP_Manage_Sites_View::render_edit_site_email_settings( $website, $updated );
		}
		self::render_footer( 'ManageSitesEmailSettings' );
	}

	/**
	 * Method render_dashboard()
	 *
	 * Render Manage Sites Dashboard.
	 *
	 * @param mixed $website Child Site.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Manage_Sites_View::render_dashboard()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::set_current_wpid()
	 */
	public static function render_dashboard( $website ) {
		MainWP_System_Utility::set_current_wpid( $website->id );
		self::render_header( 'ManageSitesDashboard' );
		MainWP_Manage_Sites_View::render_dashboard( $website );
		self::render_footer( 'ManageSitesDashboard' );
	}

	/**
	 * Method render_backup_site()
	 *
	 * Render Manage Sites Backups.
	 *
	 * @param mixed $website Child Site.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Manage_Sites_Backup_View::render_backup_site()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::set_current_wpid()
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
	 * Render Site Hardening.
	 *
	 * @param mixed $website Child Site.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Manage_Sites_View::render_scan_site()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::set_current_wpid()
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
	 *
	 * @uses \MainWP\Dashboard\MainWP_Backup_Handler::is_sql_file()
	 * @uses \MainWP\Dashboard\MainWP_Backup_Handler::is_archive()
	 * @uses \MainWP\Dashboard\MainWP_Manage_Sites_Backup_View::show_backups()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::get_mainwp_specific_dir()
	 */
	public static function show_backups( &$website ) {
		$dir = MainWP_System_Utility::get_mainwp_specific_dir( $website->id );

		MainWP_System_Utility::touch( $dir . 'index.php' );

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
	 * @uses \MainWP\Dashboard\MainWP_UI::render_modal_edit_notes()
	 */
	public static function render_all_sites() {

		self::load_sites_table();// to fix loading.

		$optimize_for_sites_table = apply_filters( 'mainwp_manage_sites_optimize_loading', 1 ); // use ajax to load sites table .

		if ( ! $optimize_for_sites_table ) {
			self::$sitesTable->prepare_items( false );
		}

		self::render_header( '' );

		$hide_loading = apply_filters( 'mainwp_manage_sites_hide_loading', false );
		?>
		<div id="mainwp-manage-sites-content" class="ui segment">
			<div id="mainwp-message-zone" class="ui message"></div>
			<div class="ui active inverted dimmer" <?php echo $hide_loading ? 'style="display:none;"' : ''; ?> id="mainwp-sites-table-loader">
				<div class="ui large text loader"><?php esc_html_e( 'Loading sites...', 'mainwp' ); ?></div>
			</div>
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
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
	 * @uses \MainWP\Dashboard\MainWP_Notification_Template::handle_template_file_action()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
	 */
	public static function render_manage_sites() { // phpcs:ignore -- complex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		/**
		 * Current user global.
		 *
		 * @global string
		 */
		global $current_user;

		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( isset( $_REQUEST['do'] ) ) {
			if ( 'new' === $_REQUEST['do'] ) {
				self::render_new_site();
			} elseif ( 'bulknew' === $_REQUEST['do'] ) {
				self::render_bulk_new_site();
			}

			return;
		}

		if ( get_option( 'mainwp_enableLegacyBackupFeature' ) ) {
			if ( ! empty( $_GET['backupid'] ) ) {
				$websiteid = ! empty( $_GET['backupid'] ) ? intval( $_GET['backupid'] ) : false;

				$backupwebsite = MainWP_DB::instance()->get_website_by_id( $websiteid );
				if ( MainWP_System_Utility::can_edit_website( $backupwebsite ) ) {
					self::render_backup_site( $backupwebsite );

					return;
				}
			}
		}

		if ( isset( $_GET['scanid'] ) ) {
			$websiteid = intval( $_GET['scanid'] );

			$scanwebsite = MainWP_DB::instance()->get_website_by_id( $websiteid );
			if ( MainWP_System_Utility::can_edit_website( $scanwebsite ) ) {
				self::render_scan_site( $scanwebsite );

				return;
			}
		}

		if ( isset( $_GET['dashboard'] ) ) {
			$websiteid = intval( $_GET['dashboard'] );

			$dashboardWebsite = MainWP_DB::instance()->get_website_by_id( $websiteid );
			if ( MainWP_System_Utility::can_edit_website( $dashboardWebsite ) ) {
				self::render_dashboard( $dashboardWebsite );

				return;
			}
		}

		if ( isset( $_GET['updateid'] ) ) {
			$websiteid      = intval( $_GET['updateid'] );
			$updatesWebsite = MainWP_DB::instance()->get_website_by_id( $websiteid );
			if ( MainWP_System_Utility::can_edit_website( $updatesWebsite ) ) {
				self::render_updates( $updatesWebsite );
				return;
			}
		}

		if ( ! empty( $_GET['emailsettingsid'] ) ) {
			$websiteid = intval( $_GET['emailsettingsid'] );
			$website   = MainWP_DB::instance()->get_website_by_id( $websiteid, false, array( 'settings_notification_emails' ) );
			if ( MainWP_System_Utility::can_edit_website( $website ) ) {
				// Edit email settings.
				$updated       = self::update_site_emails_settings_handle( $website );
				$updated_templ = MainWP_Notification_Template::instance()->handle_template_file_action();
				self::render_email_settings( $website, $updated, $updated_templ );
				return;
			}
		}

		if ( isset( $_GET['id'] ) ) {
			$websiteid = intval( $_GET['id'] );
			$website   = MainWP_DB::instance()->get_website_by_id( $websiteid );
			if ( MainWP_System_Utility::can_edit_website( $website ) ) {
				// Edit website.
				$updated = self::update_site_handle( $website );
				self::render_edit_site( $websiteid, $updated );
				return;
			}
		}
		// phpcs:enable

		$sitesViewMode = MainWP_Utility::get_siteview_mode();
		if ( 'grid' === $sitesViewMode ) {
			MainWP_Manage_Screenshots::render_all_sites();
		} else {
			self::render_all_sites();
		}
		MainWP_Logger::instance()->log_execution_time( 'render_manage_sites()' );
	}


	/**
	 * Method update_site_emails_settings_handle()
	 *
	 * Handle site email settings update.
	 *
	 * @param mixed $website Child Site object.
	 *
	 * @return bool $updated Updated.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
	 * @uses \MainWP\Dashboard\MainWP_Notification_Settings::get_notification_types()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::valid_input_emails()
	 */
	private static function update_site_emails_settings_handle( $website ) {
		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$updated = false;
		if ( isset( $_POST['submit'] ) && isset( $_GET['emailsettingsid'] ) && isset( $_POST['wp_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'UpdateWebsiteEmailSettings' . sanitize_text_field( wp_unslash( $_GET['emailsettingsid'] ) ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification,ized
			$settings_emails = MainWP_DB::instance()->get_website_option( $website, 'settings_notification_emails', '' );
			$settings_emails = ! empty( $settings_emails ) ? json_decode( $settings_emails, true ) : $settings_emails;
			if ( ! is_array( $settings_emails ) ) {
				$settings_emails = array();
			}

			$notification_emails = MainWP_Notification_Settings::get_notification_types();
			$type                = isset( $_POST['mainwp_managesites_setting_emails_type'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_managesites_setting_emails_type'] ) ) : '';  // phpcs:ignore WordPress.Security.NonceVerification,ized
			$edit_settingEmails  = isset( $_POST['mainwp_managesites_edit_settingEmails'][ $type ] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['mainwp_managesites_edit_settingEmails'][ $type ] ) ) : array();  // phpcs:ignore WordPress.Security.NonceVerification,ized

			if ( isset( $notification_emails[ $type ] ) && ! empty( $edit_settingEmails ) ) {
				$update_settings               = $edit_settingEmails;
				$update_settings['recipients'] = MainWP_Utility::valid_input_emails( $edit_settingEmails['recipients'] );
				$update_settings['disable']    = isset( $edit_settingEmails['disable'] ) ? 0 : 1; // isset 'on' means enable (0), not isset mean disabled (1).

				/**
				* Action: mainwp_before_save_email_settings
				*
				* Fires before save email settings.
				*
				* @since 4.1
				*/
				do_action( 'mainwp_before_save_email_settings', $type, $update_settings, $website );

				$settings_emails[ $type ] = $update_settings;
				MainWP_DB::instance()->update_website_option( $website, 'settings_notification_emails', wp_json_encode( $settings_emails ) );
				$updated = true;

				/**
				* Action: mainwp_after_save_email_settings
				*
				* Fires after save email settings.
				*
				* @since 4.1
				*/
				do_action( 'mainwp_after_save_email_settings', $settings_emails );

			}
		}
		// phpcs:enable
		return $updated;
	}

	/**
	 * Method update_site_handle()
	 *
	 * Handle site update.
	 *
	 * @param mixed $website Child Site object.
	 *
	 * @return bool $updated Updated.
	 *
	 * @uses \MainWP\Dashboard\MainWP_DB::update_website()
	 * @uses \MainWP\Dashboard\MainWP_DB::update_website_values()
	 * @uses \MainWP\Dashboard\MainWP_DB::update_website_option()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::remove_http_prefix()
	 * @uses  \MainWP\Dashboard\MainWP_Utility::valid_input_emails()
	 */
	private static function update_site_handle( $website ) { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		/**
		 * Current user global.
		 *
		 * @global string
		 */
		global $current_user;

		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$updated = false;
		if ( isset( $_POST['submit'] ) && isset( $_POST['wp_nonce'] ) && isset( $_REQUEST['id'] ) && isset( $_POST['mainwp_managesites_edit_siteadmin'] ) && ( '' !== $_POST['mainwp_managesites_edit_siteadmin'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'UpdateWebsite' . sanitize_key( $_REQUEST['id'] ) ) ) {
			if ( mainwp_current_user_have_right( 'dashboard', 'edit_sites' ) ) {
				// update site.
				$groupids   = array();
				$groupnames = array();
				$tmpArr     = array();
				if ( isset( $_POST['mainwp_managesites_edit_addgroups'] ) && ! empty( $_POST['mainwp_managesites_edit_addgroups'] ) ) {
					$groupids = explode( ',', sanitize_text_field( wp_unslash( $_POST['mainwp_managesites_edit_addgroups'] ) ) );
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
				$maximumFileDescriptors         = isset( $_POST['mainwp_options_maximumFileDescriptors'] ) ? intval( $_POST['mainwp_options_maximumFileDescriptors'] ) : 150;

				$archiveFormat = isset( $_POST['mainwp_archiveFormat'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_archiveFormat'] ) ) : 'global';

				$http_user = isset( $_POST['mainwp_managesites_edit_http_user'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_managesites_edit_http_user'] ) ) : '';
				$http_pass = isset( $_POST['mainwp_managesites_edit_http_pass'] ) ? wp_unslash( $_POST['mainwp_managesites_edit_http_pass'] ) : '';
				$url       = ( isset( $_POST['mainwp_managesites_edit_wpurl_with_www'] ) && ( 'www' === sanitize_text_field( wp_unslash( $_POST['mainwp_managesites_edit_wpurl_with_www'] ) ) ) ? 'www.' : '' ) . MainWP_Utility::remove_http_www_prefix( $website->url, true );
				$url       = ( isset( $_POST['mainwp_managesites_edit_siteurl_protocol'] ) && ( 'https' === sanitize_text_field( wp_unslash( $_POST['mainwp_managesites_edit_siteurl_protocol'] ) ) ) ? 'https' : 'http' ) . '://' . MainWP_Utility::remove_http_prefix( $url, true );

				$disableChecking       = isset( $_POST['mainwp_managesites_edit_disableChecking'] ) ? 0 : 1;
				$checkInterval         = isset( $_POST['mainwp_managesites_edit_checkInterval'] ) ? intval( $_POST['mainwp_managesites_edit_checkInterval'] ) : 1440;
				$disableHealthChecking = isset( $_POST['mainwp_managesites_edit_disableSiteHealthMonitoring'] ) ? 0 : 1;
				$healthThreshold       = isset( $_POST['mainwp_managesites_edit_healthThreshold'] ) ? intval( $_POST['mainwp_managesites_edit_healthThreshold'] ) : 80;

				$site_name         = isset( $_POST['mainwp_managesites_edit_sitename'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_managesites_edit_sitename'] ) ) : '';
				$site_admin        = isset( $_POST['mainwp_managesites_edit_siteadmin'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_managesites_edit_siteadmin'] ) ) : '';
				$verifycertificate = isset( $_POST['mainwp_managesites_edit_verifycertificate'] ) ? intval( $_POST['mainwp_managesites_edit_verifycertificate'] ) : '';
				$uniqueId          = isset( $_POST['mainwp_managesites_edit_uniqueId'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_managesites_edit_uniqueId'] ) ) : '';
				$ssl_version       = isset( $_POST['mainwp_managesites_edit_ssl_version'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_managesites_edit_ssl_version'] ) ) : '';

				MainWP_DB::instance()->update_website( $website->id, $url, $current_user->ID, $site_name, $site_admin, $groupids, $groupnames, $newPluginDir, $maximumFileDescriptorsOverride, $maximumFileDescriptorsAuto, $maximumFileDescriptors, $verifycertificate, $archiveFormat, $uniqueId, $http_user, $http_pass, $ssl_version, $disableChecking, $checkInterval, $disableHealthChecking, $healthThreshold );

				$new_client_id = isset( $_POST['mainwp_managesites_edit_client_id'] ) ? intval( $_POST['mainwp_managesites_edit_client_id'] ) : 0;

				if ( $website->client_id !== $new_client_id ) {

					$update = array(
						'client_id' => $new_client_id,
					);
					MainWP_DB::instance()->update_website_values( $website->id, $update );
				}

				/**
				 * Update site
				 *
				 * Fires after updating a website settings.
				 *
				 * @param int $website->id Child site ID.
				 *
				 * @since 3.4
				 */
				do_action( 'mainwp_update_site', $website->id );

				$backup_before_upgrade = isset( $_POST['mainwp_backup_before_upgrade'] ) ? intval( $_POST['mainwp_backup_before_upgrade'] ) : 2;
				if ( 2 < $backup_before_upgrade ) {
					$backup_before_upgrade = 2;
				}

				$forceuseipv4 = isset( $_POST['mainwp_managesites_edit_forceuseipv4'] ) ? intval( $_POST['mainwp_managesites_edit_forceuseipv4'] ) : 0;
				if ( 2 < $forceuseipv4 ) {
					$forceuseipv4 = 0;
				}

				$old_suspended = (int) $website->suspended;
				$suspended     = isset( $_POST['mainwp_suspended_site'] ) ? 1 : 0;
				$newValues     = array(
					'automatic_update'      => ( ! isset( $_POST['mainwp_automaticDailyUpdate'] ) ? 0 : 1 ),
					'backup_before_upgrade' => $backup_before_upgrade,
					'force_use_ipv4'        => $forceuseipv4,
					'loadFilesBeforeZip'    => isset( $_POST['mainwp_options_loadFilesBeforeZip'] ) ? 1 : 0,
					'suspended'             => $suspended,
				);

				if ( mainwp_current_user_have_right( 'dashboard', 'ignore_unignore_updates' ) ) {
					$newValues['is_ignoreCoreUpdates']   = ! empty( $_POST['mainwp_is_ignoreCoreUpdates'] ) ? 1 : 0;
					$newValues['is_ignorePluginUpdates'] = ! empty( $_POST['mainwp_is_ignorePluginUpdates'] ) ? 1 : 0;
					$newValues['is_ignoreThemeUpdates']  = ! empty( $_POST['mainwp_is_ignoreThemeUpdates'] ) ? 1 : 0;
				}

				MainWP_DB::instance()->update_website_values( $website->id, $newValues );

				$monitoring_emails = isset( $_POST['mainwp_managesites_edit_monitoringNotificationEmails'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_managesites_edit_monitoringNotificationEmails'] ) ) : '';
				$monitoring_emails = MainWP_Utility::valid_input_emails( $monitoring_emails );
				MainWP_DB::instance()->update_website_option( $website, 'monitoring_notification_emails', $monitoring_emails );

				$added = isset( $_POST['mainwp_managesites_edit_dt_added'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_managesites_edit_dt_added'] ) ) : '';
				if ( ! empty( $added ) ) {
					$added = strtotime( $added );
				}
				MainWP_DB::instance()->update_website_option( $website, 'added_timestamp', $added );

				$new_alg = isset( $_POST['mainwp_managesites_edit_openssl_alg'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_managesites_edit_openssl_alg'] ) ) : '';
				MainWP_DB::instance()->update_website_option( $website, 'signature_algo', $new_alg );

				$use_lib = isset( $_POST['mainwp_managesites_edit_verify_connection_method'] ) ? intval( $_POST['mainwp_managesites_edit_verify_connection_method'] ) : 0;
				MainWP_DB::instance()->update_website_option( $website, 'verify_method', $use_lib );

				/**
				 * Update site
				 *
				 * Fires after updating a website settings.
				 *
				 * @param int $website Child site Ọbject.
				 *
				 * @since 3.4
				 */
				do_action( 'mainwp_site_updated', $website, $_POST );

				if ( $old_suspended !== $suspended ) { // changed suspended.
					/**
					 * Site suspended changed.
					 *
					 * Fires after suspended a website changed.
					 *
					 * @param int $website->id Child site ID.
					 *
					 * @since 4.5.1.1
					 */
					do_action( 'mainwp_site_suspended', $website, $suspended );
				}
				$updated = true;
			}
		}
		// phpcs:enable
		return $updated;
	}

	/**
	 * Method render_edit_site()
	 *
	 * Render edit site.
	 *
	 * @param mixed $websiteid Child Site ID.
	 * @param mixed $updated Updated.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Manage_Sites_View::render_edit_site()
	 * @uses \MainWP\Dashboard\MainWP_System_Utility::set_current_wpid()
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
		if ( isset( $_POST['submit'] ) && isset( $_REQUEST['id'] ) && isset( $_POST['wp_nonce'] ) && isset( $_POST['mainwp_managesites_edit_siteadmin'] ) && ( '' !== $_POST['mainwp_managesites_edit_siteadmin'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'UpdateWebsite' . sanitize_key( $_REQUEST['id'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification,ized
			if ( isset( $_POST['mainwp_managesites_edit_uniqueId'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,ized
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
		if ( isset( $_GET['page'] ) && 'managesites' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification,ized
			if ( isset( $_GET['do'] ) && 'new' === $_GET['do'] ) { // phpcs:ignore WordPress.Security.NonceVerification,ized
				?>
				<p><?php esc_html_e( 'If you need help connecting your websites, please review following help documents', 'mainwp' ); ?></p>
				<div class="ui relaxed bulleted list">
					<div class="item"><a href="https://kb.mainwp.com/docs/set-up-the-mainwp-plugin/" target="_blank">Set up the MainWP Plugin</a> <i class="external alternate icon"></i></div>
					<div class="item"><a href="https://kb.mainwp.com/docs/install-mainwp-child/" target="_blank">Install MainWP Child</a> <i class="external alternate icon"></i></div>
					<div class="item"><a href="https://kb.mainwp.com/docs/set-unique-security-id/" target="_blank">Set Unique Security ID</a> <i class="external alternate icon"></i></div>
					<div class="item"><a href="https://kb.mainwp.com/docs/add-site-to-your-dashboard/" target="_blank">Add a Site to your Dashboard</a> <i class="external alternate icon"></i></div>
					<div class="item"><a href="https://kb.mainwp.com/docs/import-sites/" target="_blank">Import Sites</a> <i class="external alternate icon"></i></div>
				</div>
				<?php
			} elseif ( isset( $_GET['do'] ) && 'bulknew' === $_GET['do'] ) { // phpcs:ignore WordPress.Security.NonceVerification,ized
				?>
				<p><?php esc_html_e( 'If you need help connecting your websites, please review following help documents', 'mainwp' ); ?></p>
				<div class="ui relaxed bulleted list">
					<div class="item"><a href="https://kb.mainwp.com/docs/set-up-the-mainwp-plugin/" target="_blank">Set up the MainWP Plugin</a> <i class="external alternate icon"></i></div>
					<div class="item"><a href="https://kb.mainwp.com/docs/install-mainwp-child/" target="_blank">Install MainWP Child</a> <i class="external alternate icon"></i></div>
					<div class="item"><a href="https://kb.mainwp.com/docs/set-unique-security-id/" target="_blank">Set Unique Security ID</a> <i class="external alternate icon"></i></div>
					<div class="item"><a href="https://kb.mainwp.com/docs/add-site-to-your-dashboard/" target="_blank">Add a Site to your Dashboard</a> <i class="external alternate icon"></i></div>
					<div class="item"><a href="https://kb.mainwp.com/docs/import-sites/" target="_blank">Import Sites</a> <i class="external alternate icon"></i></div>
				</div>
				<?php
			} else {
				?>
				<p><?php esc_html_e( 'If you need help with managing child sites, please review following help documents', 'mainwp' ); ?></p>
				<div class="ui relaxed bulleted list">
					<div class="item"><a href="https://kb.mainwp.com/docs/manage-child-sites/" target="_blank">Manage Child Sites</a> <i class="external alternate icon"></i></div>
					<div class="item"><a href="https://kb.mainwp.com/docs/access-child-site-wp-admin/" target="_blank">Access Child Site WP Admin</a> <i class="external alternate icon"></i></div>
					<div class="item"><a href="https://kb.mainwp.com/docs/synchronize-a-child-site/" target="_blank">Synchronize a Child Site</a> <i class="external alternate icon"></i></div>
					<div class="item"><a href="https://kb.mainwp.com/docs/edit-a-child-site/" target="_blank">Edit a Child Site</a> <i class="external alternate icon"></i></div>
					<div class="item"><a href="https://kb.mainwp.com/docs/reconnect-a-child-site/" target="_blank">Reconnect a Child Site</a> <i class="external alternate icon"></i></div>
					<div class="item"><a href="https://kb.mainwp.com/docs/delete-a-child-site/" target="_blank">Delete a Child Site</a> <i class="external alternate icon"></i></div>
					<div class="item"><a href="https://kb.mainwp.com/docs/security-issues/" target="_blank">Security Issues</a> <i class="external alternate icon"></i></div>
					<div class="item"><a href="https://kb.mainwp.com/docs/manage-child-site-groups/" target="_blank">Manage Child Site Tags</a> <i class="external alternate icon"></i></div>
					<div class="item"><a href="https://kb.mainwp.com/docs/manage-child-site-notes/" target="_blank">Manage Child Site Notes</a> <i class="external alternate icon"></i></div>
				</div>
				<?php
			}
		}
	}
}
