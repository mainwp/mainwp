<?php
/**
 * MainWP Clients Page
 *
 * This page is used to Manage Clients.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_client
 *
 * @uses page-mainwp-bulk-add::MainWP_Bulk_Add()
 */
class MainWP_Client {

	/**
	 * Get Class Name
	 *
	 * @return string __CLASS__
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * Current page.
	 *
	 * @static
	 * @var string $page Current page.
	 */
	public static $page;

	/**
	 * Public static varable to hold Subpages information.
	 *
	 * @var array $subPages
	 */
	public static $subPages;


	/**
	 * Magage Sites table
	 *
	 * @var $itemsTable Magage Sites table.
	 */
	public static $itemsTable;

	/**
	 * Method init()
	 *
	 * Initiate hooks for the clients page.
	 */
	public static function init() {
		/**
		 * This hook allows you to render the client page header via the 'mainwp_pageheader_client' action.
		 *
		 * This hook is normally used in the same context of 'mainwp_pageheader_client'
		 *
		 * @see \MainWP_client::render_header
		 */
		add_action( 'mainwp_pageheader_client', array( self::get_class_name(), 'render_header' ) );

		/**
		 * This hook allows you to render the client page footer via the 'mainwp_pagefooter_client' action.
		 *
		 * This hook is normally used in the same context of 'mainwp_pagefooter_client'
		 *
		 * @see \MainWP_client::render_footer
		 */
		add_action( 'mainwp_pagefooter_client', array( self::get_class_name(), 'render_footer' ) );

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
		self::$page = add_submenu_page(
			'mainwp_tab',
			esc_html__( 'Clients', 'mainwp' ),
			'<span id="mainwp-clients">' . esc_html__( 'Clients', 'mainwp' ) . '</span>',
			'read',
			'ManageClients',
			array(
				self::get_class_name(),
				'render_manage_clients',
			)
		);

		add_submenu_page(
			'mainwp_tab',
			esc_html__( 'Clients', 'mainwp' ),
			'<div class="mainwp-hidden">' . esc_html__( 'Add Client', 'mainwp' ) . '</div>',
			'read',
			'ClientAddNew',
			array(
				self::get_class_name(),
				'render_add_client',
			)
		);

		add_submenu_page(
			'mainwp_tab',
			esc_html__( 'Clients', 'mainwp' ),
			'<div class="mainwp-hidden">' . esc_html__( 'Client Fields', 'mainwp' ) . '</div>',
			'read',
			'ClientAddField',
			array(
				self::get_class_name(),
				'render_client_fields',
			)
		);

		/**
		 * This hook allows you to add extra sub pages to the client page via the 'mainwp-getsubpages-client' filter.
		 *
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-client
		 */
		$sub_pages      = array();
		self::$subPages = apply_filters( 'mainwp_getsubpages_client', $sub_pages );

		if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
			foreach ( self::$subPages as $subPage ) {
				if ( MainWP_Menu::is_disable_menu_item( 3, 'ManageClients' . $subPage['slug'] ) ) {
					continue;
				}
				add_submenu_page( 'mainwp_tab', $subPage['title'], '<div class="mainwp-hidden">' . esc_html( $subPage['title'] ) . '</div>', 'read', 'ManageClients' . $subPage['slug'], $subPage['callback'] );
			}
		}

		self::init_left_menu( self::$subPages );

		add_action( 'load-' . self::$page, array( self::get_class_name(), 'on_load_page' ) );
	}

	/**
	 * Method on_load_page()
	 *
	 * Run on page load.
	 */
	public static function on_load_page() {

		if ( isset( $_GET['client_id'] ) && ! empty( $_GET['client_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			MainWP_Client_Overview::instance()->on_load_page( self::$page );
			return;
		}

		add_filter( 'mainwp_header_actions_right', array( self::get_class_name(), 'screen_options' ), 10, 2 );

		self::$itemsTable = new MainWP_Client_List_Table();
	}

	/**
	 * Method screen_options()
	 *
	 * Create Page Settings button.
	 *
	 * @param mixed $input Page Settings button HTML.
	 *
	 * @return mixed Screen sptions button.
	 */
	public static function screen_options( $input ) {
		return $input .
				'<a class="ui button basic icon" onclick="mainwp_manage_clients_screen_options(); return false;" data-inverted="" data-position="bottom right" href="#" target="_blank" data-tooltip="' . esc_html__( 'Page Settings', 'mainwp' ) . '">
					<i class="cog icon"></i>
				</a>';
	}

	/**
	 * Initiates sub pages menu.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
	 */
	public static function init_subpages_menu() {
		?>
		<div id="menu-mainwp-Clients" class="mainwp-submenu-wrapper">
			<div class="wp-submenu sub-open" style="">
				<div class="mainwp_boxout">
					<div class="mainwp_boxoutin"></div>
					<?php if ( mainwp_current_user_have_right( 'dashboard', 'manage_clients' ) ) { ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=ManageClients' ) ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Clients', 'mainwp' ); ?></a>
					<?php } ?>
					<?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ClientAddNew' ) ) { ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=ClientAddNew' ) ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Add Client', 'mainwp' ); ?></a>
					<?php } ?>
					<?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ClientAddField' ) ) { ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=ClientAddField' ) ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Client Properties', 'mainwp' ); ?></a>
					<?php } ?>
					<?php
					if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
						foreach ( self::$subPages as $subPage ) {
							if ( MainWP_Menu::is_disable_menu_item( 3, 'ManageClients' . $subPage['slug'] ) ) {
								continue;
							}
							?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=ManageClients' . $subPage['slug'] ) ); ?>" class="mainwp-submenu"><?php echo esc_html( $subPage['title'] ); ?></a>
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
	 * Initiates Clients menu.
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
				'title'      => esc_html__( 'Clients', 'mainwp' ),
				'parent_key' => 'mainwp_tab',
				'slug'       => 'ManageClients',
				'href'       => 'admin.php?page=ManageClients',
				'icon'       => '<i class="users icon"></i>',
				'desc'       => 'Manage clients on your child sites',
			),
			0
		);

		$init_sub_subleftmenu = array(
			array(
				'title'                => esc_html__( 'Clients', 'mainwp' ),
				'parent_key'           => 'ManageClients',
				'href'                 => 'admin.php?page=ManageClients',
				'slug'                 => 'ManageClients',
				'right'                => 'manage_clients',
				'leftsub_order_level2' => 1,
			),
			array(
				'title'                => esc_html__( 'Add Client', 'mainwp' ),
				'parent_key'           => 'ManageClients',
				'href'                 => 'admin.php?page=ClientAddNew',
				'slug'                 => 'ClientAddNew',
				'right'                => '',
				'leftsub_order_level2' => 2,
			),
			array(
				'title'                => esc_html__( 'Client Fields', 'mainwp' ),
				'parent_key'           => 'ManageClients',
				'href'                 => 'admin.php?page=ClientAddField',
				'slug'                 => 'ClientAddField',
				'right'                => '',
				'leftsub_order_level2' => 3,
			),
		);

		MainWP_Menu::init_subpages_left_menu( $subPages, $init_sub_subleftmenu, 'ManageClients', 'ManageClients' );

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
	 * Render Clients page header.
	 *
	 * @param string $shownPage The page slug shown at this moment.
	 *
	 * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_top_header()
	 * @uses \MainWP\Dashboard\MainWP_UI::render_page_navigation()
	 */
	public static function render_header( $shownPage = '' ) { // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.
		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$client_id = isset( $_GET['client_id'] ) ? intval( $_GET['client_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$params = array(
			'title' => esc_html__( 'Clients', 'mainwp' ),
			'which' => 'overview' === $shownPage ? 'page_clients_overview' : '',
		);

		$client = false;
		if ( $client_id ) {
			$client = MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $client_id );
			if ( $client ) {
				$client_pic = isset( $client->image ) ? MainWP_Client_Handler::get_client_image_url( $client->image ) : '';
				if ( '' !== $client_pic ) {
					$client_pic = '<img src="' . esc_attr( $client_pic ) . '" class="ui circurlar avatar image" />';
				} else {
					$client_pic  = '<i class="user circle icon"></i>'; // phpcs:ignore -- Prevent modify WP icon.
				}
				$params['title'] = $client_pic . '<div class="content">' . $client->name . '<div class="sub header"><a href="mailto:' . $client->client_email . '" target="_blank" style="color:#666!important;font-weight:normal!important;">' . $client->client_email . '</a> </div></div>';
			}
		}

		MainWP_UI::render_top_header( $params );

		$renderItems = array();

		if ( mainwp_current_user_have_right( 'dashboard', 'manage_clients' ) ) {
			$renderItems[] = array(
				'title'  => esc_html__( 'Clients', 'mainwp' ),
				'href'   => 'admin.php?page=ManageClients',
				'active' => ( '' === $shownPage ) ? true : false,
			);
		}

		if ( $client_id ) {
			$renderItems[] = array(
				'title'  => $client ? $client->name : esc_html__( 'Overview', 'mainwp' ),
				'href'   => 'admin.php?page=ManageClients&client_id=' . $client_id,
				'active' => ( 'overview' === $shownPage ),
			);
			$renderItems[] = array(
				'title'  => $client ? esc_html__( 'Edit', 'mainwp' ) . ' ' . $client->name : esc_html__( 'Edit Client', 'mainwp' ),
				'href'   => 'admin.php?page=ClientAddNew&client_id=' . $client_id,
				'active' => ( 'Edit' === $shownPage ) ? true : false,
			);
		}

		if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ClientAddNew' ) ) {
			$renderItems[] = array(
				'title'  => esc_html__( 'Add Client', 'mainwp' ),
				'href'   => 'admin.php?page=ClientAddNew',
				'active' => ( 'Add' === $shownPage ) ? true : false,
			);
		}

		if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ClientAddField' ) ) {
			$renderItems[] = array(
				'title'  => esc_html__( 'Client Fields', 'mainwp' ),
				'href'   => 'admin.php?page=ClientAddField',
				'active' => ( 'AddField' === $shownPage ) ? true : false,
			);
		}

		if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
			foreach ( self::$subPages as $subPage ) {
				if ( MainWP_Menu::is_disable_menu_item( 3, 'ManageClients' . $subPage['slug'] ) ) {
					continue;
				}

				if ( ! empty( $subPage['individual_settings'] ) && empty( $client_id ) ) {
					continue;
				}

				$client_param   = $client_id ? '&client_id=' . $client_id : '';
				$item           = array();
				$item['title']  = $subPage['title'];
				$item['href']   = 'admin.php?page=ManageClients' . $subPage['slug'] . $client_param;
				$item['active'] = ( $subPage['slug'] === $shownPage ) ? true : false;
				$renderItems[]  = $item;
			}
		}
		// phpcs:enable
		MainWP_UI::render_page_navigation( $renderItems );
	}

	/**
	 * Method render_footer()
	 *
	 * Render Clients page footer. Closes the page container.
	 */
	public static function render_footer() {
		echo '</div>';
	}

	/**
	 * Renders manage clients dashboard.
	 *
	 * @return void
	 */
	public static function render_manage_clients() {

		if ( isset( $_GET['client_id'] ) && ! empty( $_GET['client_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			MainWP_Client_Overview::instance()->on_show_page( intval( $_GET['client_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( ! mainwp_current_user_have_right( 'dashboard', 'manage_clients' ) ) {
			mainwp_do_not_have_permissions( esc_html__( 'manage clients', 'mainwp' ) );
			return;
		}

		self::$itemsTable->prepare_items();

		self::render_header( '' );
		self::render_second_top_header();

		?>
		<div id="mainwp-manage-sites-content" class="ui segment">
			<div id="mainwp-message-zone" style="display:none;" class="ui message"></div>
			<form method="post" class="mainwp-table-container">
				<?php
				wp_nonce_field( 'mainwp-admin-nonce' );
				self::$itemsTable->display();
				self::$itemsTable->clear_items();
				?>
			</form>
		</div>		
		<?php
		self::render_footer( '' );
		self::render_screen_options();
	}

	/**
	 * Method render_second_top_header()
	 *
	 * Render second top header.
	 *
	 * @return void Render second top header html.
	 */
	public static function render_second_top_header() {
		?>
		<div class="mainwp-sub-header ui mini form">
			<?php
			do_action( 'mainwp_manageclients_tabletop' );
			?>
		</div>
		<?php
	}


	/**
	 * Renders Edit Clients Modal window.
	 */
	public static function render_update_clients() {

		?>
		<div id="mainwp-edit-clients-modal" class="ui modal">
		<i class="close icon"></i>
			<div class="header"><?php esc_html_e( 'Edit client', 'mainwp' ); ?></div>
			<div class="ui message"><?php esc_html_e( 'Empty fields will not be passed to child sites.', 'mainwp' ); ?></div>
			<form id="update_client_profile">
				<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
				<div class="ui segment">
					<div class="ui form">
						<h3><?php esc_html_e( 'Name', 'mainwp' ); ?></h3>
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

						<h3><?php esc_html_e( 'About the client', 'mainwp' ); ?></h3>

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
			<div class="actions">
				<div id="mainwp_update_password_error" style="display: none"></div>
				<span id="mainwp_clients_updating"><i class="ui active inline loader tiny"></i></span>
				<input type="button" class="ui green button" id="mainwp_btn_update_client" value="<?php esc_attr_e( 'Update', 'mainwp' ); ?>">
			</div>
		</div>
		<?php
	}


	/**
	 * Method render_screen_options()
	 *
	 * Render Page Settings Modal.
	 */
	public static function render_screen_options() {  // phpcs:ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.

		$columns = self::$itemsTable->get_columns();

		if ( isset( $columns['cb'] ) ) {
			unset( $columns['cb'] );
		}

		$sites_per_page = get_option( 'mainwp_default_manage_clients_per_page', 25 );

		if ( isset( $columns['site_actions'] ) && empty( $columns['site_actions'] ) ) {
			$columns['site_actions'] = esc_html__( 'Actions', 'mainwp' );
		}

		$show_cols = get_user_option( 'mainwp_settings_show_manage_clients_columns' );

		if ( false === $show_cols ) { // to backwards.
			$default_cols = array(
				'name'     => 1,
				'email'    => 1,
				'phone'    => 1,
				'websites' => 1,
			);

			$show_cols = array();
			foreach ( $columns as $name => $title ) {
				if ( isset( $default_cols[ $name ] ) ) {
					$show_cols[ $name ] = 1;
				} {
					$show_cols[ $name ] = 1; // show other columns.
				}
			}
			$user = wp_get_current_user();
			if ( $user ) {
				update_user_option( $user->ID, 'mainwp_settings_show_manage_clients_columns', $show_cols, true );
			}
		}

		if ( ! is_array( $show_cols ) ) {
			$show_cols = array();
		}

		?>
		<div class="ui modal" id="mainwp-manage-sites-screen-options-modal">
		<i class="close icon"></i>
			<div class="header"><?php esc_html_e( 'Page Settings', 'mainwp' ); ?></div>
			<div class="scrolling content ui form">
				<form method="POST" action="" id="manage-sites-screen-options-form" name="manage_sites_screen_options_form">
					<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
					<input type="hidden" name="wp_nonce" value="<?php echo esc_attr( wp_create_nonce( 'ManageClientsScrOptions' ) ); ?>" />
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
							<input type="text" name="mainwp_default_manage_clients_per_page" id="mainwp_default_manage_clients_per_page" saved-value="<?php echo intval( $sites_per_page ); ?>" value="<?php echo intval( $sites_per_page ); ?>"/>
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
				</div>
				<div class="actions">
					<div class="ui two columns grid">
						<div class="left aligned column">
							<span data-tooltip="<?php esc_attr_e( 'Resets the page to its original layout and reinstates relocated columns.', 'mainwp' ); ?>" data-inverted="" data-position="top center"><input type="button" class="ui button" name="reset" id="reset-manageclients-settings" value="<?php esc_attr_e( 'Reset Page', 'mainwp' ); ?>" /></span>
						</div>
						<div class="ui right aligned column">
					<input type="submit" class="ui green button" name="btnSubmit" id="submit-manageclients-settings" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>" />
				</div>
					</div>
				</div>
				<input type="hidden" name="reset_manageclients_columns_order" value="0">
			</form>
		</div>
		<div class="ui small modal" id="mainwp-monitoring-sites-site-preview-screen-options-modal">
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
						jQuery( '#mainwp-monitoring-sites-site-preview-screen-options-modal' ).modal( {
							allowMultiple: true, // multiple modals.
							width: 100,
							onDeny: function () {
								$chk.prop('checked', false);
							}
						} ).modal( 'show' );
					}
				} );
				jQuery('#reset-manageclients-settings').on( 'click', function () {
					mainwp_confirm(__( 'Are you sure.' ), function(){
						jQuery('input[name=mainwp_default_manage_clients_per_page]').val(25);
						jQuery('.mainwp_hide_wpmenu_checkboxes input[id^="mainwp_show_column_"]').prop( 'checked', false );
						//default columns.
						var cols = ['name','email','phone','websites'];
						jQuery.each( cols, function ( index, value ) {
							jQuery('.mainwp_hide_wpmenu_checkboxes input[id="mainwp_show_column_' + value + '"]').prop( 'checked', true );
						} );
						jQuery('input[name=reset_manageclients_columns_order]').attr('value',1);
						jQuery('#submit-manageclients-settings').click();						
					}, false, false, true );
					return false;
				});
			} );
		</script>
		<?php
	}

	/**
	 * Renders Clients Table.
	 *
	 * @param string $role Current client role.
	 * @param string $groups Current client groups.
	 * @param string $sites Current Child Sites the client is on.
	 * @param null   $search Search field.
	 */
	public static function render_table( $role = '', $groups = '', $sites = '', $search = null ) {

		/**
		 * Action: mainwp_before_clients_table
		 *
		 * Fires before the client table.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_before_clients_table' );
		?>
		<table id="mainwp-clients-table" class="ui unstackable single line table" style="width:100%">
			<thead>
				<tr>
					<th  class="no-sort collapsing check-column"><span class="ui checkbox"><input id="cb-select-all-top" type="checkbox" /></span></th>
					<?php do_action( 'mainwp_clients_table_header' ); ?>
					<th><?php esc_html_e( 'Client name', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Company', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Email', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Phone', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Websites', 'mainwp' ); ?></th>
					<th id="mainwp-clients-actions" class="no-sort collapsing"></th>
				</tr>
			</thead>
			<tbody id="mainwp-clients-list">
			<?php
			self::render_table_body( $role, $groups, $sites, $search );
			?>
			</tbody>
			<tfoot>
				<tr>
					<th  class="no-sort collapsing check-column"><span class="ui checkbox"><input id="cb-select-all-top" type="checkbox" /></span></th>
					<?php do_action( 'mainwp_clients_table_header' ); ?>
					<th><?php esc_html_e( 'Client name', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Company', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Email', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Phone', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Websites', 'mainwp' ); ?></th>
					<th id="mainwp-clients-actions" class="no-sort collapsing"></th>
				</tr>
			</tfoot>
		</table>
		<?php
		/**
		 * Action: mainwp_after_clients_table
		 *
		 * Fires after the client table.
		 *
		 * @since 4.1
		 */
		do_action( 'mainwp_after_clients_table' );

		/**
		 * Filter: mainwp_clients_table_fatures
		 *
		 * Filters the Manage Clients table features.
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
		$table_features = apply_filters( 'mainwp_clients_table_fatures', $table_features );
		?>
		<script type="text/javascript">
		var responsive = <?php echo esc_html( $table_features['responsive'] ); ?>;
		if( jQuery( window ).width() > 1140 ) {
			responsive = false;
		}
		jQuery( document ).ready( function () {
			try {
				jQuery( "#mainwp-clients-table" ).DataTable().destroy(); // to fix re-init database issue.
				jQuery( "#mainwp-clients-table" ).DataTable( {
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
					"preDrawCallback": function() {					
						jQuery('#mainwp-clients-table .ui.dropdown').dropdown();
						jQuery('#mainwp-clients-table .ui.checkbox').checkbox();
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
	 * Renders the table body.
	 *
	 * @param string $role   client Role.
	 * @param string $groups Usr Group.
	 * @param string $sites  Clients Sites.
	 * @param string $search Search field.
	 */
	public static function render_table_body( $role = '', $groups = '', $sites = '', $search = '' ) { // phpcs:ignore -- current complexity required to achieve desired results. Pull request solutions appreciated.

		if ( empty( $output->clients ) ) {
			self::render_not_found();
			return;
		} else {
			self::clients_search_handler_renderer( $clients, $website );
		}
	}

	/**
	 * Renders when cache is not found.
	 */
	public static function render_not_found() {
		ob_start();
		?>
			<tr><td colspan="999"><?php esc_html_e( 'Please use the search options to find wanted clients.', 'mainwp' ); ?></td></tr>
		<?php
		$newOutput = ob_get_clean();
		echo $newOutput; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Renders Search results.
	 *
	 * @param array  $clients Clients array.
	 * @param object $website Object containing the child site info.
	 *
	 * @return mixed Search results table.
	 */
	protected static function clients_search_handler_renderer( $clients, $website ) {
		$return = 0;

		foreach ( $clients as $client ) {
			if ( ! is_array( $client ) ) {
				continue;
			}
			ob_start();
			?>
			<tr>
				<td class="check-column"><span class="ui checkbox"><input type="checkbox" name="client[]" value="1"></span></td>
					<?php do_action( 'mainwp_clients_table_column', $client, $website ); ?>
					<td class="name column-name">
					<?php echo ! empty( $client['display_name'] ) ? esc_html( $client['display_name'] ) : '&nbsp;'; ?>
					<div class="row-actions-working">
						<i class="ui active inline loader tiny"></i> <?php esc_html_e( 'Please wait', 'mainwp' ); ?>
					</div>
				</td>
				<td class="clientname column-clientname"><strong><abbr title="<?php echo esc_attr( $client['login'] ); ?>"><?php echo esc_html( $client['login'] ); ?></abbr></strong></td>
				<td class="email column-email"><a href="mailto:<?php echo esc_attr( $client['email'] ); ?>"><?php echo esc_html( $client['email'] ); ?></a></td>
				<td class="posts column-posts"><a href="<?php echo esc_url( admin_url( 'admin.php?page=PostBulkManage&siteid=' . intval( $website->id ) ) . '&clientid=' . $client['id'] ); ?>"><?php echo esc_html( $client['post_count'] ); ?></a></td>
				<td class="website column-website"><a href="<?php echo esc_url( $website->url ); ?>" target="_blank"><?php echo esc_html( $website->url ); ?></a></td>
				<td class="right aligned">
					<input class="clientId" type="hidden" name="id" value="<?php echo esc_attr( $client['id'] ); ?>" />
					<input class="clientName" type="hidden" name="name" value="<?php echo esc_attr( $client['login'] ); ?>" />
					<input class="websiteId" type="hidden" name="id" value="<?php echo intval( $website->id ); ?>" />
					<div class="ui left pointing dropdown icon mini basic green button" style="z-index: 999">
						<a href="javascript:void(0)"><i class="ellipsis horizontal icon"></i></a>
						<div class="menu">
							<a class="item client_getedit" href="#"><?php esc_html_e( 'Edit', 'mainwp' ); ?></a>
							<?php if ( ( 1 !== (int) $client['id'] ) && ( $client['login'] !== $website->adminname ) ) { ?>
							<a class="item client_submitdelete" href="#"><?php esc_html_e( 'Delete', 'mainwp' ); ?></a>
							<?php } elseif ( ( 1 === (int) $client['id'] ) || ( $client['login'] === $website->adminname ) ) { ?>
							<a href="javascript:void(0)" class="item" data-tooltip="This client is used for our secure link, it can not be deleted." data-inverted="" data-position="left center"><?php esc_html_e( 'Delete', 'mainwp' ); ?></a>
							<?php } ?>
							<a class="item" href="<?php echo 'admin.php?page=SiteOpen&newWindow=yes&websiteid=' . intval( $website->id ); ?>&_opennonce=<?php echo esc_html( wp_create_nonce( 'mainwp-admin-nonce' ) ); ?>" data-tooltip="<?php esc_attr_e( 'Jump to the site WP Admin', 'mainwp' ); ?>"  data-position="bottom right"  data-inverted="" class="open_newwindow_wpadmin ui green basic icon button" target="_blank"><?php esc_html_e( 'Go to WP Admin', 'mainwp' ); ?></a>
							<?php
							/**
							 * Action: mainwp_clients_table_action
							 *
							 * Adds a new item in the Actions menu in Manage Clients table.
							 *
							 * Suggested HTML markup:
							 * <a class="item" href="Your custom URL">Your custom label</a>
							 *
							 * @param array $client    Array containing the client data.
							 * @param array $website Object containing the website data.
							 *
							 * @since 4.1
							 */
							do_action( 'mainwp_clients_table_action', $client, $website );
							?>
						</div>
					</div>
				</td>
			</tr>
			<?php
			$newOutput = ob_get_clean();
			echo $newOutput; // phpcs:ignore WordPress.Security.EscapeOutput
			++$return;
		}

		return $return;
	}

	/**
	 * Renders the Add New Client form.
	 */
	public static function render_add_client() {
		$show           = 'Add';
		$client_id      = 0;
		$selected_sites = array();
		$edit_client    = false;

		if ( isset( $_GET['client_id'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended
			$show         = 'Edit';
			$client_id    = intval( $_GET['client_id'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended
			$edit_client  = $client_id ? MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $client_id ) : false;
			$client_sites = MainWP_DB_Client::instance()->get_websites_by_client_ids( $client_id );

			if ( $client_sites ) {
				foreach ( $client_sites as $site ) {
					$selected_sites[] = $site->id;
				}
			}
		}

		self::render_header( $show );
		?>
		<div class="ui alt segment" id="mainwp-add-clients">
			<form action="" method="post" enctype="multipart/form-data" name="createclient_form" id="createclient_form" class="add:clients: validate">
				<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
				<div class="mainwp-main-content">
					<div class="ui segment">
					<?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-add-client-info-message' ) ) : ?>
					<div class="ui info message">
						<i class="close icon mainwp-notice-dismiss" notice-id="mainwp-add-client-info-message"></i>
						<?php printf( esc_html__( 'use the provided form to create a new client on your child site(). for additional help, please check this %1$shelp documentation %2$s.', 'mainwp' ), '<a href="https://kb.mainwp.com/docs/create-a-new-client/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); ?>
					</div>
				<?php endif; ?>
					<div class="ui message" id="mainwp-message-zone-client" style="display:none;"></div>
					<div id="mainwp-add-new-client-form" >						
						<?php
						self::render_add_client_content( $edit_client );
						?>
					</div>
				</div>
				</div>
				<div class="mainwp-side-content mainwp-no-padding">

					<?php if ( $client_id ) : ?>
					<div class="mainwp-select-sites ui accordion mainwp-sidebar-accordion">
						<div class="title active"><i class="dropdown icon"></i> <?php esc_html_e( 'Tokens Info', 'mainwp' ); ?></div>
						<div class="content active">
							<div class="ui info message">
								<?php esc_html_e( 'Client info is available as tokens for reports and boilerplate content. Toggle the switch to see available tokens.', 'mainwp' ); ?>
							</div>
							<div class="ui toggle checkbox">
								<input type="checkbox" name="mainwp_toggle_tokens_info" id="mainwp_toggle_tokens_info">
								<label><?php esc_html_e( 'Toggle available tokens', 'mainwp' ); ?></label>
							</div>
						</div>
					</div>
					<script type="text/javascript">
					jQuery( document ).ready( function() {
					jQuery( '#mainwp_toggle_tokens_info' ).on( 'change', function() {
							jQuery( '.hidden.token.column' ).toggle();
						} );
					} );
					</script>
					<div class="ui fitted divider"></div>
					<?php endif; ?>

					<div class="mainwp-select-sites ui accordion mainwp-sidebar-accordion">
						<div class="title active"><i class="dropdown icon"></i> <?php esc_html_e( 'Select Sites', 'mainwp' ); ?></div>
						<div class="content active">
							<?php
							$sel_params = array(
								'selected_sites'       => $selected_sites,
								'show_group'           => false,
								'add_edit_client_id'   => $client_id,
								'enable_offline_sites' => $client_id ? true : false,
							);

							MainWP_UI_Select_Sites::select_sites_box( $sel_params );
							?>
						</div>
					</div>
					<div class="ui fitted divider"></div>
					<div class="mainwp-search-submit">
						<input type="button" name="createclient" current-page="add-new" id="bulk_add_createclient" class="ui big green fluid button" value="<?php echo $client_id ? esc_attr__( 'Update Client', 'mainwp' ) : esc_attr__( 'Add Client', 'mainwp' ); ?> "/>
					</div>
				</div>
				<div style="clear:both"></div>
			</form>


		</div>
		<?php
		self::render_footer( $show );
		self::render_add_field_modal( $client_id );
	}


	/**
	 * Renders the Add New Client Fields form.
	 */
	public static function render_client_fields() {

		self::render_header( 'AddField' );
		?>
		<div class="ui segment" id="mainwp-add-client-fields">
		<?php
		$fields = MainWP_DB_Client::instance()->get_client_fields();
		?>
		<div class="ui info message" <?php echo ! MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-clients-manage-fields' ) ? 'style="display: none"' : ''; ?>>
		<?php esc_html_e( 'Create and manage custom Client fields.', 'mainwp' ); ?>
			<i class="ui close icon mainwp-notice-dismiss" notice-id="mainwp-clients-manage-fields"></i>
		</div>
		<div class="ui message" id="mainwp-message-zone-client" style="display:none;"></div>
		<table id="mainwp-clients-custom-fields-table" class="ui selectable compact table" style="width:100%">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Field Name', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Field Description', 'mainwp' ); ?></th>
					<th class="no-sort collapsing"><?php esc_html_e( '', 'mainwp' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( is_array( $fields ) && count( $fields ) > 0 ) : ?>
				<?php foreach ( $fields as $field ) : ?>
					<?php
					if ( ! $field ) {
						continue;}
					?>
						<tr class="mainwp-field" field-id="<?php echo intval( $field->field_id ); ?>">
							<td class="field-name">[<?php echo esc_html( stripslashes( $field->field_name ) ); ?>]</td>
							<td class="field-description"><?php echo esc_html( stripslashes( $field->field_desc ) ); ?></td>
							<td>
								<div class="ui left pointing dropdown icon mini basic green button">
									<i class="ellipsis horizontal icon"></i>
									<div class="menu">
										<a class="item" id="mainwp-clients-edit-custom-field" href="#"><?php esc_html_e( 'Edit', 'mainwp' ); ?></a>
										<a class="item" id="mainwp-clients-delete-general-field" href="#"><?php esc_html_e( 'Delete', 'mainwp' ); ?></a>
									</div>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
			<tfoot>
				<tr>
					<th><a class="ui mini green button" href="javascript:void(0);" id="mainwp-clients-new-custom-field-button"><?php esc_html_e( 'New Field', 'mainwp' ); ?></a></th>
					<th><?php esc_html_e( '', 'mainwp' ); ?></th>
					<th><?php esc_html_e( '', 'mainwp' ); ?></th>
				</tr>
			</tfoot>
		</table>

		<script type="text/javascript">
		// Init datatables
		jQuery( '#mainwp-clients-custom-fields-table' ).DataTable( {
			"stateSave": true,
			"stateDuration": 0,
			"lengthMenu": [ [10, 25, 50, 100, -1], [10, 25, 50, 100, "All"] ],
			"columnDefs": [ { "orderable": false, "targets": "no-sort" } ],
			"order": [ [ 0, "asc" ] ],
			"language": { "emptyTable": "No fields found." },
			"drawCallback" : function( settings ) {
				jQuery( '#mainwp-clients-custom-fields-table .ui.dropdown').dropdown();
			},
		} );
		</script>
		</div>

			<?php
			self::render_add_field_modal();
			self::render_footer( 'AddField' );
	}

		/**
		 * Method render_add_field_modal()
		 *
		 * Render add custom field modal.
		 *
		 * @param int $client_id The client id.
		 */
	public static function render_add_field_modal( $client_id = 0 ) {
		?>
		<div class="ui modal" id="mainwp-clients-custom-field-modal">
		<i class="close icon"></i>
			<div class="header"><?php esc_html_e( 'Custom Field', 'mainwp' ); ?></div>
			<div class="content ui mini form">
				<div class="ui yellow message" style="display:none"></div>
				<div class="field">
					<label><?php esc_html_e( 'Field Name', 'mainwp' ); ?></label>
					<input type="text" value="" class="field-name" name="field-name" placeholder="<?php esc_attr_e( 'Enter field name (without of square brackets)', 'mainwp' ); ?>">
				</div>
				<div class="field">
					<label><?php esc_html_e( 'Field Description', 'mainwp' ); ?></label>
					<input type="text" value="" class="field-description" name="field-description" placeholder="<?php esc_attr_e( 'Enter field description', 'mainwp' ); ?>">
				</div>
			</div>
			<div class="actions">
				<input type="button" class="ui green button" client-id="<?php echo intval( $client_id ); ?>" id="mainwp-clients-save-new-custom-field" value="<?php esc_attr_e( 'Save Field', 'mainwp' ); ?>">
			</div>
			<input type="hidden" value="0" name="field-id">
		</div>
			<?php
	}

	/**
	 * Method add_client()
	 *
	 * Bulk client addition $_POST Handler.
	 */
	public static function add_client() { // phpcs:ignore -- Current complexity is required to achieve desired results. Pull request solutions appreciated.

		$selected_sites = ( isset( $_POST['selected_sites'] ) && is_array( $_POST['selected_sites'] ) ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['selected_sites'] ) ) : array(); //phpcs:ignore WordPress.Security.NonceVerification.Missing
		$client_fields  = isset( $_POST['client_fields'] ) ? wp_unslash( $_POST['client_fields'] ) : array(); //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( ! is_array( $client_fields ) ) {
			$client_fields = array();
		}

		if ( ! isset( $client_fields['default_field']['client.name'] ) || empty( $client_fields['default_field']['client.name'] ) ) {
			echo wp_json_encode( array( 'error' => esc_html__( 'Client name are empty. Please try again.', 'mainwp' ) ) );
			return;
		}

		$add_new = true;

		$dirs     = MainWP_System_Utility::get_mainwp_dir( 'client-images', true );
		$base_dir = $dirs[0];

		$default_client_fields = MainWP_Client_Handler::get_default_client_fields();
		$client_to_add         = array();
		foreach ( $default_client_fields as $field_name => $item ) {
			if ( ! empty( $item['db_field'] ) ) {
				if ( isset( $client_fields['default_field'][ $field_name ] ) ) {
					$client_to_add[ $item['db_field'] ] = sanitize_text_field( wp_unslash( $client_fields['default_field'][ $field_name ] ) );
				}
			}
		}

		$client_to_add['primary_contact_id'] = isset( $client_fields['default_field']['primary_contact_id'] ) ? intval( $client_fields['default_field']['primary_contact_id'] ) : 0;

		$client_id = isset( $client_fields['client_id'] ) ? intval( $client_fields['client_id'] ) : 0;

		$new_suspended = $client_to_add['suspended'];
		$old_suspended = $new_suspended;

		if ( $client_id ) {
			$current_client = MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $client_id );
			$old_suspended  = $current_client->suspended;

			$client_to_add['client_id'] = $client_id; // update client.
			if ( isset( $client_to_add['created'] ) && ! empty( $client_to_add['created'] ) ) {
				$client_to_add['created'] = strtotime( $client_to_add['created'] );
			}
			$add_new = false;
		} else {
			$client_to_add['created'] = time();
		}

		try {
			$inserted = MainWP_DB_Client::instance()->update_client( $client_to_add, true );
		} catch ( \Exception $e ) {
			echo wp_json_encode( array( 'error' => $e->getMessage() ) );
			return;
		}

		if ( $client_id ) {
			MainWP_DB_Client::instance()->update_selected_sites_for_client( $client_id, $selected_sites );
		} elseif ( is_object( $inserted ) ) {
			MainWP_DB_Client::instance()->update_selected_sites_for_client( $inserted->client_id, $selected_sites );
			$client_id = $inserted->client_id;
		}

		if ( is_object( $inserted ) ) {
			/**
			 * Add client
			 *
			 * Fires after add a client.
			 *
			 * @param object $inserted client data.
			 * @param bool $add_new true add new, false updated.
			 *
			 * @since 4.5.1.1
			 */
			do_action( 'mainwp_client_updated', $inserted, $add_new );

			if ( ! $add_new && $new_suspended != $old_suspended ) { //phpcs:ignore -- to valid.
				/**
				 * Fires immediately after update client suspend/unsuspend.
				 *
				 * @since 4.5.1.1
				 *
				 * @param object $client  client data.
				 * @param bool $new_suspended true|false.
				 */
				do_action( 'mainwp_client_suspend', $inserted, $new_suspended );
			}
		}

		if ( $client_id && isset( $client_fields['custom_fields'] ) && is_array( $client_fields['custom_fields'] ) ) {
			foreach ( $client_fields['custom_fields'] as $input_name => $field_val ) {
				$field_id = array_key_first( $field_val );
				// update custom field value for client.
				if ( $field_id ) {
					$val = $field_val[ $field_id ];
					MainWP_DB_Client::instance()->update_client_field_value( $field_id, $val, $client_id );
				}
			}
		}

		$client_image = 'NOTCHANGE';
		if ( isset( $_POST['mainwp_client_delete_image']['client_field'] ) && $client_id === (int) $_POST['mainwp_client_delete_image']['client_field'] ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$client_image = '';
		}

		if ( isset( $_FILES['mainwp_client_image_uploader']['error']['client_field'] ) && UPLOAD_ERR_OK === $_FILES['mainwp_client_image_uploader']['error']['client_field'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			$output = MainWP_System_Utility::handle_upload_image( 'client-images', $_FILES['mainwp_client_image_uploader'], 'client_field' ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( is_array( $output ) && isset( $output['filename'] ) && ! empty( $output['filename'] ) ) {
				$client_image = $output['filename'];
			}
		}

		if ( 'NOTCHANGE' !== $client_image && $client_id ) {
			$client_data = MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $client_id );

			$old_file = $client_data->image;
			if ( $old_file !== $client_image && ! empty( $old_file ) ) {
				$delete_old_file = $base_dir . '/' . $old_file;
				if ( file_exists( $delete_old_file ) ) {
					wp_delete_file( $delete_old_file );
				}
			}
			$update = array(
				'client_id' => $client_id,
				'image'     => $client_image,
			);
			MainWP_DB_Client::instance()->update_client( $update );
		}

			$is_first_contact       = true;
			$auto_assign_contact_id = 0;

		if ( $client_id && isset( $client_fields['contacts_field'] ) ) {

			foreach ( $client_fields['contacts_field']['client.contact.name'] as $indx => $contact_name ) {
				$contact_to_add = array();
				if ( empty( $contact_name ) ) {
					continue;
				}
				$contact_to_add['contact_name'] = $contact_name;

				$contact_email = $client_fields['contacts_field']['contact.email'][ $indx ];
				if ( empty( $contact_email ) ) {
					continue;
				}

				$contact_id = isset( $client_fields['contacts_field']['contact_id'][ $indx ] ) ? intval( $client_fields['contacts_field']['contact_id'][ $indx ] ) : 0;

				if ( empty( $contact_id ) ) {
					continue;
				}

				$contact_to_add['contact_email'] = $contact_email;

				$contact_to_add['contact_phone'] = $client_fields['contacts_field']['contact.phone'][ $indx ];
				$contact_to_add['contact_role']  = $client_fields['contacts_field']['contact.role'][ $indx ];
				$contact_to_add['facebook']      = $client_fields['contacts_field']['contact.facebook'][ $indx ];
				$contact_to_add['twitter']       = $client_fields['contacts_field']['contact.twitter'][ $indx ];
				$contact_to_add['instagram']     = $client_fields['contacts_field']['contact.instagram'][ $indx ];
				$contact_to_add['linkedin']      = $client_fields['contacts_field']['contact.linkedin'][ $indx ];

				$contact_to_add['contact_client_id'] = $client_id;
				$contact_to_add['contact_id']        = $contact_id;

				$updated = MainWP_DB_Client::instance()->update_client_contact( $contact_to_add );

				$is_first_contact = false;

				if ( $updated ) {
					$contact_image = 'NOTCHANGE';
					if ( isset( $_POST['mainwp_client_delete_image']['contacts_field'][ $contact_id ] ) && $contact_id === (int) $_POST['mainwp_client_delete_image']['contacts_field'][ $contact_id ] ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
						$contact_image = '';
					}

					if ( isset( $_FILES['mainwp_client_image_uploader']['error']['contacts_field'][ $indx ] ) && UPLOAD_ERR_OK === $_FILES['mainwp_client_image_uploader']['error']['contacts_field'][ $indx ] ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
						$output = MainWP_System_Utility::handle_upload_image( 'client-images', $_FILES['mainwp_client_image_uploader'], 'contacts_field', $indx ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
						if ( is_array( $output ) && isset( $output['filename'] ) && ! empty( $output['filename'] ) ) {
							$contact_image = $output['filename'];
						}
					}

					if ( 'NOTCHANGE' !== $contact_image && $client_id ) {
						$contact_data = MainWP_DB_Client::instance()->get_wp_client_contact_by( 'contact_id', $contact_id );

						$old_file = $contact_data->contact_image;
						if ( $old_file !== $contact_image && ! empty( $old_file ) ) {
							$delete_old_file = $base_dir . '/' . $old_file;
							if ( file_exists( $delete_old_file ) ) {
								wp_delete_file( $delete_old_file );
							}
						}
						$update = array(
							'contact_id'    => $contact_id,
							'contact_image' => $contact_image,
						);
						MainWP_DB_Client::instance()->update_client_contact( $update );
					}
				}
			}
		}

		if ( $client_id && isset( $client_fields['new_contacts_field'] ) ) {

			$first_contact_id = 0;
			foreach ( $client_fields['new_contacts_field']['client.contact.name'] as $indx => $contact_name ) {
				$contact_to_add = array();
				if ( empty( $contact_name ) ) {
					continue;
				}
				$contact_to_add['contact_name'] = $contact_name;

				$contact_email = $client_fields['new_contacts_field']['contact.email'][ $indx ];
				if ( empty( $contact_email ) ) {
					continue;
				}
				$contact_to_add['contact_email'] = $contact_email;

				$contact_to_add['contact_phone'] = $client_fields['new_contacts_field']['contact.phone'][ $indx ];
				$contact_to_add['contact_role']  = $client_fields['new_contacts_field']['contact.role'][ $indx ];
				$contact_to_add['facebook']      = $client_fields['new_contacts_field']['contact.facebook'][ $indx ];
				$contact_to_add['twitter']       = $client_fields['new_contacts_field']['contact.twitter'][ $indx ];
				$contact_to_add['instagram']     = $client_fields['new_contacts_field']['contact.instagram'][ $indx ];
				$contact_to_add['linkedin']      = $client_fields['new_contacts_field']['contact.linkedin'][ $indx ];

				$contact_to_add['contact_client_id'] = $client_id;

				$inserted = MainWP_DB_Client::instance()->update_client_contact( $contact_to_add );

				if ( $inserted ) {

					$contact_id    = $inserted->contact_id;
					$contact_image = '';

					if ( isset( $_FILES['mainwp_client_image_uploader']['error']['new_contacts_field'][ $indx ] ) && UPLOAD_ERR_OK === $_FILES['mainwp_client_image_uploader']['error']['new_contacts_field'][ $indx ] ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
						$output = MainWP_System_Utility::handle_upload_image( 'client-images', $_FILES['mainwp_client_image_uploader'], 'new_contacts_field', $indx ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
						if ( is_array( $output ) && isset( $output['filename'] ) && ! empty( $output['filename'] ) ) {
							$contact_image = $output['filename'];
						}
					}

					if ( '' !== $contact_image && $contact_id ) {
						$update = array(
							'contact_id'    => $contact_id,
							'contact_image' => $contact_image,
						);
						MainWP_DB_Client::instance()->update_client_contact( $update );
					}

					if ( $is_first_contact && empty( $auto_assign_contact_id ) ) {
						$auto_assign_contact_id = $contact_id;
					}
				}
			}
		}

		if ( $client_id && isset( $client_fields['delele_contacts'] ) && is_array( $client_fields['delele_contacts'] ) ) {
			foreach ( $client_fields['delele_contacts'] as $delete_id ) {
				MainWP_DB_Client::instance()->delete_client_contact( $client_id, $delete_id );
				$is_first_contact = false;
			}
		}

		if ( $is_first_contact && $auto_assign_contact_id && $client_id ) {
			// auto assign.
			$update = array(
				'client_id'          => $client_id,
				'primary_contact_id' => $auto_assign_contact_id,
			);
			MainWP_DB_Client::instance()->update_client( $update );
		}

		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( isset( $_POST['is_first_client'] ) && ! empty( $_POST['is_first_client'] ) ) {
			delete_transient( 'mainwp_transient_just_connected_site_id' );
		}
		//phpcs:enable

		echo wp_json_encode(
			array(
				'success'   => 'yes',
				'client_id' => $client_id,
			)
		);
	}

	/**
	 * Method render_add_client_modal().
	 *
	 * Renders add client Modal window.
	 */
	public static function render_add_client_modal() {
		?>
			<div id="mainwp-creating-new-client-modal" class="ui modal">
			<i class="close icon"></i>
				<div class="header"><?php esc_html_e( 'New client', 'mainwp' ); ?></div>
				<div class="ui message" id="mainwp-message-zone-client" style="display:none;"></div>
				<div class="scrolling content mainwp-modal-content">				
					<form action="" method="post" enctype="multipart/form-data" name="createclient_form" id="createclient_form" class="add:clients: validate">
				<?php
					self::render_add_client_content();
				?>
					</form>
				</div>
				<div class="actions">
					<div class="ui button green" current-page="modal-add" id="bulk_add_createclient"><?php esc_html_e( 'Add Client', 'mainwp' ); ?></div>	
				</div>
			</div>

			<script type="text/javascript">
				jQuery(document).on('click', '.edit-site-new-client-button', function () {
					jQuery('#mainwp-creating-new-client-modal').modal({
						allowMultiple: false,
					}).modal('show');
					return false;
				});
			</script>
			<?php
	}

	/**
	 * Method render_add_client_content().
	 *
	 * Renders add client content window.
	 *
	 * @param mixed $edit_client The client data.
	 */
	public static function render_add_client_content( $edit_client = false ) { // phpcs:ignore -- Current complexity is required to achieve desired results. Pull request solutions appreciated.
		$client_id             = $edit_client ? $edit_client->client_id : 0;
		$default_client_fields = MainWP_Client_Handler::get_default_client_fields();
		$custom_fields         = MainWP_DB_Client::instance()->get_client_fields( true, $client_id, true );
		$client_image          = $edit_client ? $edit_client->image : '';

		?>
		<h3 class="ui dividing header">
			<?php if ( $client_id ) : ?>
				<?php echo esc_html__( 'Edit Client', 'mainwp' ); ?>
				<div class="sub header"><?php esc_html_e( 'Edit client information.', 'mainwp' ); ?></div>
			<?php else : ?>
				<?php esc_html_e( 'Add New Client', 'mainwp' ); ?>
				<div class="sub header"><?php esc_html_e( 'Enter the client information.', 'mainwp' ); ?></div>
			<?php endif; ?>
		</h3>
		<div class="ui form">
			<?php

			foreach ( $default_client_fields as $field_name => $field ) {
				$db_field = isset( $field['db_field'] ) ? $field['db_field'] : '';
				$val      = $edit_client && '' !== $db_field && property_exists( $edit_client, $db_field ) ? $edit_client->{$db_field} : '';
				$tip      = isset( $field['tooltip'] ) ? $field['tooltip'] : '';
				?>
				<div class="ui grid field">
					<label class="six wide column middle aligned" <?php echo ! empty( $tip ) ? 'data-tooltip="' . esc_attr( $tip ) . '" data-inverted="" data-position="top left"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>><?php echo esc_html( $field['title'] ); ?></label>
					<div class="ui six wide column">
						<div class="ui left labeled input">
					<?php
					if ( 'client.note' === $field_name ) {
						?>
							<div class="editor">
								<textarea class="code" cols="80" rows="10" name="client_fields[default_field][<?php echo esc_attr( $field_name ); ?>]"><?php echo esc_html( $val ); ?></textarea>
							</div>
							<?php
					} elseif ( 'client.suspended' === $field_name ) {
						?>
							<select name="client_fields[default_field][<?php echo esc_attr( $field_name ); ?>]" id="client_fields[default_field][<?php echo esc_attr( $field_name ); ?>]" class="ui dropdown">
								<option value="0" <?php echo ( '0' === $val ? 'selected' : '' ); ?>><?php esc_html_e( 'Active', 'mainwp' ); ?></option>
								<option value="1" <?php echo ( '1' === $val ? 'selected' : '' ); ?>><?php esc_html_e( 'Suspended', 'mainwp' ); ?></option>
								<option value="2" <?php echo ( '2' === $val ? 'selected' : '' ); ?>><?php esc_html_e( 'Lead', 'mainwp' ); ?></option>
								<option value="3" <?php echo ( '3' === $val ? 'selected' : '' ); ?>><?php esc_html_e( 'Lost', 'mainwp' ); ?></option>
							</select>
						<?php
					} elseif ( $client_id && 'client.created' === $field_name ) {
						$created = empty( $val ) ? time() : $val;
						?>
						<div class="ui calendar mainwp_datepicker" >
								<div class="ui input left icon">
									<i class="calendar icon"></i>
									<input type="text" autocomplete="off" name="client_fields[default_field][<?php echo esc_attr( $field_name ); ?>]" placeholder="<?php esc_attr_e( 'Added date', 'mainwp' ); ?>" id="client_fields[default_field][<?php echo esc_attr( $field_name ); ?>]" value="<?php echo esc_attr( date( 'Y-m-d', $created ) ); // phpcs:ignore -- local time. ?>"/>
								</div>
						</div>
						<?php
					} else {
						?>
							<input type="text" value="<?php echo esc_html( $val ); ?>" class="regular-text" name="client_fields[default_field][<?php echo esc_attr( $field_name ); ?>]"/>
						<?php
					}
					?>
						</div>											
					</div>
					<?php if ( $client_id ) : ?>
					<div class="ui four wide middle aligned hidden token column" style="display:none">
						<?php if ( 'client.suspended' !== $field_name ) { ?>
						[<?php echo esc_html( $field_name ); ?>]
					<?php } ?>
					</div>	
					<?php endif; ?>
				</div>
					<?php

					if ( 'client.name' === $field_name ) {
						?>
						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'Client photo', 'mainwp' ); ?></label>
							<div class="six wide column" data-tooltip="<?php esc_attr_e( 'Upload a client photo.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
								<div class="ui file fluid input">
								<input type="file" name="mainwp_client_image_uploader[client_field]" accept="image/*" data-inverted="" data-tooltip="<?php esc_attr_e( "Image must be 500KB maximum. It will be cropped to 310px wide and 70px tall. For best results  us an image of this site. Allowed formats: jpeg, gif and png. Note that animated gifs aren't going to be preserved.", 'mainwp' ); ?>" />
							</div>
						</div>
						</div>
						<?php if ( ! empty( $client_image ) ) : ?>
						<div class="ui grid field">
							<label class="six wide column middle aligned"></label>
							<div class="six wide column">
								<img class="ui tiny circular image" src="<?php echo esc_url( MainWP_Client_Handler::get_client_image_url( $client_image ) ); ?>" /><br/>
								<div class="ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'If enabled, delete client image.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
									<input type="checkbox" value="<?php echo intval( $client_id ); ?>" id="mainwp_client_delete_image[client_field]" name="mainwp_client_delete_image[client_field]" />
									<label for="mainwp_client_delete_image[client_field][]"><?php esc_html_e( 'Delete photo', 'mainwp' ); ?></label>
								</div>
							</div>
						</div>
					<?php endif; ?>
						<?php
					}
			}

			$client_contacts = array();
			if ( $client_id ) {
				$client_contacts = MainWP_DB_Client::instance()->get_wp_client_contact_by( 'client_id', $client_id );
				?>
			<div class="ui grid field">
					<label class="six wide column middle aligned"><?php echo esc_html_e( 'Client primary contact', 'mainwp' ); ?></label>
					<div class="ui six wide column">
						<div class="ui left labeled">
							<div class="ui search selection dropdown" init-value="" id="client_fields[default_field][primary_contact_id]">
							<input type="hidden" name="client_fields[default_field][primary_contact_id]" value="<?php echo $edit_client ? intval( $edit_client->primary_contact_id ) : 0; ?>">
							<i class="dropdown icon"></i>
								<div class="default text"><?php esc_attr_e( 'Select primary contact', 'mainwp' ); ?></div>
								<div class="menu">
								<div class="item" data-value="0"><?php esc_attr_e( 'Select primary contact', 'mainwp' ); ?></div>
								<?php if ( $client_contacts ) : ?>
									<?php foreach ( $client_contacts as $contact ) { ?>
										<div class="item" data-value="<?php echo intval( $contact->contact_id ); ?>"><?php echo esc_html( stripslashes( $contact->contact_name ) ); ?></div>
									<?php } ?>
									<?php endif; ?>
								</div>
							</div>
						</div>											
					</div>
					<div class="ui four wide column">
					</div>	
				</div>
				<div class="ui section hidden divider"></div>
				<?php
			}

			$primary_contact_id = 0;

			if ( $edit_client && $edit_client->primary_contact_id ) {
				$primary_contact_id = $edit_client->primary_contact_id;
			}

			if ( is_array( $custom_fields ) && count( $custom_fields ) > 0 ) {
				$compatible_tokens = MainWP_Client_Handler::get_compatible_tokens();
				foreach ( $custom_fields as $field ) {
					if ( isset( $default_client_fields[ $field->field_name ] ) ) {
						continue;
					}
					// do not show these tokens.
					if ( isset( $compatible_tokens[ $field->field_name ] ) ) {
						continue;
					}
					?>
					<div class="ui grid field mainwp-field"  field-id="<?php echo intval( $field->field_id ); ?>">
						<label class="six wide column middle aligned field-description"><?php echo esc_html( $field->field_desc ); ?></label>
						<div class="ui six wide column">
							<div class="ui left labeled input">
								<input type="text" value="<?php echo ( property_exists( $field, 'field_value' ) && '' !== $field->field_value ) ? esc_html( $field->field_value ) : ''; ?>" class="regular-text" name="client_fields[custom_fields][<?php echo esc_html( $field->field_name ); ?>][<?php echo esc_html( $field->field_id ); ?>]"/>
							</div>
						</div>
						<div class="ui four wide column">
						<?php if ( $client_id > 0 && $field->client_id > 0 ) { // edit client and it is individual field, then show to edit/delete field buttons. ?>
							<div class="ui left pointing dropdown icon mini basic green button">
								<i class="ellipsis horizontal icon"></i>
								<div class="menu">
									<a class="item" id="mainwp-clients-edit-custom-field" href="#"><?php esc_html_e( 'Edit', 'mainwp' ); ?></a>
									<a class="item" client-id="<?php echo intval( $client_id ); ?>" id="mainwp-clients-delete-individual-field" href="#"><?php esc_html_e( 'Delete', 'mainwp' ); ?></a>
								</div>
							</div>
							<?php } ?>
							<span class="field-name">[<?php echo esc_html( $field->field_name ); ?>]</span>
						</div>	
					</div>
					<?php
				}
			}

			$temp = self::get_add_contact_temp( false, false );

			if ( $client_id ) {
				if ( $client_contacts ) {
					foreach ( $client_contacts as $client_contact ) {
						self::get_add_contact_temp( $client_contact, true );
					}
				}
			}
			?>
		<div class="ui grid field">
			<label class="six wide column middle aligned"><?php esc_html_e( 'Create a new contact for this client', 'mainwp' ); ?></label>
			<div class="ui six wide column">
				<div class="ui left labeled input">
				<a href="javascript:void(0);" class="ui green button mainwp-client-add-contact" add-contact-temp="<?php echo esc_attr( $temp ); ?>"><?php esc_html_e( 'Add Additional Contact', 'mainwp' ); ?></a>
				</div>
			</div>
		</div>
		<div class="ui section hidden divider after-add-contact-field"></div>
		</div>
		<input type="hidden" name="client_fields[client_id]" value="<?php echo intval( $client_id ); ?>">
		<script type="text/javascript">
				jQuery( document ).ready( function () {
					// to fix issue not loaded calendar js library
					if (jQuery('.ui.calendar').length > 0) {
						if (mainwpParams.use_wp_datepicker == 1) {
							jQuery('#mainwp-add-new-client-form .ui.calendar input[type=text]').datepicker({ dateFormat: "yy-mm-dd" });
						} else {
							jQuery('#mainwp-add-new-client-form .ui.calendar').calendar({
								type: 'date',
								monthFirst: false,
								today: true,
								touchReadonly: false,
								formatter: {
									date: function (date) {
										if (!date) return '';
										var day = date.getDate();
										var month = date.getMonth() + 1;
										var year = date.getFullYear();

										if (month < 10) {
											month = '0' + month;
										}
										if (day < 10) {
											day = '0' + day;
										}
										return year + '-' + month + '-' + day;
									}
								}
							});
						}
					}
				} );
			</script>
		<?php
	}

	/**
	 * Method get_add_contact_temp().
	 *
	 * Get add contact template.
	 *
	 * @param mixed $edit_contact The contact data to edit.
	 * @param bool  $echo_out Echo template or not.
	 */
	public static function get_add_contact_temp( $edit_contact = false, $echo_out = false ) {

		$input_name    = 'new_contacts_field';
		$contact_id    = 0;
		$contact_image = '';
		if ( $edit_contact ) {
			$input_name    = 'contacts_field';
			$contact_id    = $edit_contact->contact_id;
			$contact_image = $edit_contact->contact_image;
		}

		ob_start();
		?>
		<h3 class="ui dividing header top-contact-fields"> <?php // must have class: top-contact-fields. ?>
		<?php if ( $edit_contact ) : ?>
				<?php echo esc_html__( 'Edit Contact', 'mainwp' ); ?>
				<div class="sub header"><?php esc_html_e( 'Edit contact person information.', 'mainwp' ); ?></div>
			<?php else : ?>
				<?php esc_html_e( 'Add Contact', 'mainwp' ); ?>
				<div class="sub header"><?php esc_html_e( 'Enter contact person information.', 'mainwp' ); ?></div>
			<?php endif; ?>
		</h3>
			<?php
			$contact_fields = MainWP_Client_Handler::get_default_contact_fields();
			foreach ( $contact_fields as $field_name => $field ) {
					$db_field   = isset( $field['db_field'] ) ? $field['db_field'] : '';
					$val        = $edit_contact && '' !== $db_field && property_exists( $edit_contact, $db_field ) ? $edit_contact->{$db_field} : '';
					$contact_id = $edit_contact && property_exists( $edit_contact, 'contact_id' ) ? $edit_contact->contact_id : '';
				?>
				<div class="ui grid field">
					<label class="six wide column middle aligned"><?php echo esc_html( $field['title'] ); ?></label>
					<div class="ui six wide column">
						<div class="ui left labeled input">
							<input type="text" value="<?php echo esc_html( $val ); ?>" class="regular-text" name="client_fields[<?php echo esc_html( $input_name ); ?>][<?php echo esc_attr( $field_name ); ?>][]"/>
						</div>											
					</div>
							<?php if ( $edit_contact ) : ?>
					<div class="ui four wide middle aligned hidden token column" style="display:none">
						[<?php echo esc_html( $field_name ); ?>]
					</div>	
					<?php endif; ?>
				</div>
							<?php

							if ( 'contact.role' === $field_name ) {
								?>
					<div class="ui grid field">
						<label class="six wide column middle aligned"><?php esc_html_e( 'Contact photo', 'mainwp' ); ?></label>
						<div class="six wide column" data-tooltip="<?php esc_attr_e( 'Upload a client photo.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
							<div class="ui file fluid  input">
							<input type="file" name="mainwp_client_image_uploader[<?php echo esc_html( $input_name ); ?>][]" accept="image/*" data-inverted="" data-tooltip="<?php esc_attr_e( "Image must be 500KB maximum. It will be cropped to 310px wide and 70px tall. For best results  us an image of this site. Allowed formats: jpeg, gif and png. Note that animated gifs aren't going to be preserved.", 'mainwp' ); ?>" />
							</div>
						</div>
					</div>

								<?php if ( ! empty( $contact_image ) ) : ?>
						<div class="ui grid field">
							<label class="six wide column middle aligned"></label>
							<div class="six wide column">
								<img class="ui tiny circular image" src="<?php echo esc_url( MainWP_Client_Handler::get_client_image_url( $contact_image ) ); ?>" /><br/>
								<div class="ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'If enabled, delete contact image.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
									<input type="checkbox" value="<?php echo intval( $contact_id ); ?>" id="mainwp_client_delete_image[contacts_field][<?php echo intval( $contact_id ); ?>]" name="mainwp_client_delete_image[contacts_field][<?php echo intval( $contact_id ); ?>]" />
									<label for="mainwp_client_delete_image[contacts_field][<?php echo intval( $contact_id ); ?>]"><?php esc_html_e( 'Delete photo', 'mainwp' ); ?></label>
								</div>

							</div>
						</div>
									<?php
			endif;
							}
			}

			?>
			<div class="ui grid field remove-contact-field-parent">
				<label class="six wide column middle aligned"><?php esc_html_e( 'Remove contact', 'mainwp' ); ?></label>
				<div class="ui six wide column">
					<div class="ui left labeled input">
					<a href="javascript:void(0);" contact-id="<?php echo intval( $contact_id ); ?>" class="ui basic button mainwp-client-remove-contact"><?php esc_html_e( 'Remove contact', 'mainwp' ); ?></a>
					</div>
						<?php
						if ( $edit_contact ) {
							?>
						<input type="hidden" value="<?php echo intval( $edit_contact->contact_id ); ?>" name="client_fields[contacts_field][contact_id][]"/>
								<?php
						}
						?>
				</div>
			</div>
			<div class="ui section hidden divider bottom-contact-fields"></div>
			<?php
			$html = ob_get_clean();

			if ( $echo_out ) {
				echo $html; //phpcs:ignore -- validated content.
			}

			return $html;
	}

	/**
	 * Method save_client_field().
	 *
	 * Save custom fields.
	 */
	public static function save_client_field() {

		$return = array(
			'success' => false,
			'error'   => '',
			'message' => '',
		);

		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$client_id  = isset( $_POST['client_id'] ) ? intval( $_POST['client_id'] ) : 0; // 0 is global client's field.
		$field_id   = isset( $_POST['field_id'] ) ? intval( $_POST['field_id'] ) : 0;
		$field_desc = isset( $_POST['field_desc'] ) ? sanitize_text_field( wp_unslash( $_POST['field_desc'] ) ) : '';
		$field_name = isset( $_POST['field_name'] ) ? sanitize_text_field( wp_unslash( $_POST['field_name'] ) ) : '';
		$field_name = trim( $field_name, '[]' );
		// phpcs:enable

		// update general or individual client field.
		if ( $field_id ) {
			$current = MainWP_DB_Client::instance()->get_client_fields_by( 'field_id', $field_id );
			if ( $current && $current->field_name === $field_name && $current->field_desc === $field_desc ) {
				$return['success'] = true;
				$return['message'] = esc_html__( 'Field has been saved without changes.', 'mainwp' );
			} else {
				$current = MainWP_DB_Client::instance()->get_client_fields_by( 'field_name', $field_name, $client_id ); // check if other field with the same name existed.
				if ( $current && (int) $current->field_id !== $field_id ) {
					$return['error'] = esc_html__( 'Field already exists, try different field name.', 'mainwp' );
				} else {
					// update general or individual field name.
					$field = MainWP_DB_Client::instance()->update_client_field(
						$field_id,
						array(
							'field_name' => $field_name,
							'field_desc' => $field_desc,
							'client_id'  => $client_id,
						)
					);
					if ( $field ) {
						$return['success'] = true;
					}
				}
			}
		} else { // add new.
			$current = MainWP_DB_Client::instance()->get_client_fields_by( 'field_name', $field_name, $client_id );
			if ( $current ) { // checking general or individual field name.
				$return['error'] = esc_html__( 'Field already exists, try different field name.', 'mainwp' );
			} else {
				// insert general or individual field name.
				$field = MainWP_DB_Client::instance()->add_client_field(
					array(
						'field_name' => $field_name,
						'field_desc' => $field_desc,
						'client_id'  => $client_id,
					)
				);

				if ( $field ) {
					$return['success'] = true;
				} else {
					$return['error'] = esc_html__( 'Undefined error occurred. Please try again.', 'mainwp' ); }
			}
		}
		echo wp_json_encode( $return );
		exit;
	}

		/**
		 * Method save_note()
		 *
		 * Save Client Note.
		 */
	public static function save_note() {
		if ( isset( $_POST['clientid'] ) && ! empty( $_POST['clientid'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$note     = isset( $_POST['note'] ) ? wp_unslash( $_POST['note'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$esc_note = MainWP_Utility::esc_content( $note );
			$update   = array(
				'client_id' => intval( $_POST['clientid'] ), // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				'note'      => $esc_note,
			);
			MainWP_DB_Client::instance()->update_client( $update );
			die( wp_json_encode( array( 'result' => 'SUCCESS' ) ) );
		}
		die( wp_json_encode( array( 'undefined_error' => true ) ) );
	}

		/**
		 * Hooks the section help content to the Help Sidebar element.
		 */
	public static function mainwp_help_content() {
		if ( isset( $_GET['page'] ) && ( 'ManageClients' === $_GET['page'] || 'ClientAddNew' === $_GET['page'] || 'UpdateAdminPasswords' === $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			?>
			<p><?php esc_html_e( 'If you need help with managing clients, please review following help documents', 'mainwp' ); ?></p>
			<div class="ui relaxed bulleted list">
				<div class="item"><a href="https://kb.mainwp.com/docs/manage-clients/" target="_blank">Manage Clients</a> <i class="external alternate icon"></i></div>
				<?php
				/**
				 * Action: mainwp_clients_help_item
				 *
				 * Fires at the bottom of the help articles list in the Help sidebar on the Clients page.
				 *
				 * Suggested HTML markup:
				 *
				 * <div class="item"><a href="Your custom URL">Your custom text</a></div>
				 *
				 * @since 4.1
				 */
				do_action( 'mainwp_clients_help_item' );
				?>
			</div>
				<?php
		}
	}
}
