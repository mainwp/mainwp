<?php
namespace MainWP\Dashboard;

/**
 * MainWP User Page
 *
 * @uses MainWP_Bulk_Add
 */
class MainWP_User {
	public static function get_class_name() {
		return __CLASS__;
	}

	public static $subPages;

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

	public static function init_menu() {
		add_submenu_page(
			'mainwp_tab', __( 'Users', 'mainwp' ), '<span id="mainwp-Users">' . __( 'Users', 'mainwp' ) . '</span>', 'read', 'UserBulkManage', array(
				self::get_class_name(),
				'render',
			)
		);

		add_submenu_page(
			'mainwp_tab', __( 'Users', 'mainwp' ), '<div class="mainwp-hidden">' . __( 'Add New', 'mainwp' ) . '</div>', 'read', 'UserBulkAdd', array(
				self::get_class_name(),
				'render_bulk_add',
			)
		);

		add_submenu_page(
			'mainwp_tab', __( 'Import Users', 'mainwp' ), '<div class="mainwp-hidden">' . __( 'Import Users', 'mainwp' ) . '</div>', 'read', 'BulkImportUsers', array(
				self::get_class_name(),
				'render_bulk_import_users',
			)
		);

		/**
		 * This hook allows you to add extra sub pages to the User page via the 'mainwp-getsubpages-user' filter.
		 *
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-user
		 */
		self::$subPages = apply_filters( 'mainwp-getsubpages-user', array() );
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

	public static function init_subpages_menu() {
		?>
		<div id="menu-mainwp-Users" class="mainwp-submenu-wrapper">
			<div class="wp-submenu sub-open" style="">
				<div class="mainwp_boxout">
					<div class="mainwp_boxoutin"></div>
					<?php if ( mainwp_current_user_can( 'dashboard', 'manage_users' ) ) { ?>
					<a href="<?php echo admin_url( 'admin.php?page=UserBulkManage' ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Manage Users', 'mainwp' ); ?></a>
					<?php } ?>
					<?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'UserBulkAdd' ) ) { ?>
					<a href="<?php echo admin_url( 'admin.php?page=UserBulkAdd' ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Add New', 'mainwp' ); ?></a>
					<?php } ?>
					<?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'BulkImportUsers' ) ) { ?>
					<a href="<?php echo admin_url( 'admin.php?page=BulkImportUsers' ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Import Users', 'mainwp' ); ?></a>
					<?php } ?>
					<?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'UpdateAdminPasswords' ) ) { ?>
					<a href="<?php echo admin_url( 'admin.php?page=UpdateAdminPasswords' ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Admin Passwords', 'mainwp' ); ?></a>
					<?php } ?>
					<?php
					if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
						foreach ( self::$subPages as $subPage ) {
							if ( MainWP_Menu::is_disable_menu_item( 3, 'UserBulk' . $subPage['slug'] ) ) {
								continue;
							}
							?>
							<a href="<?php echo admin_url( 'admin.php?page=UserBulk' . $subPage['slug'] ); ?>" class="mainwp-submenu"><?php echo esc_html( $subPage['title'] ); ?></a>
							<?php
						}
					}
					?>
				</div>
			</div>
		</div>
		<?php
	}

	public static function init_left_menu( $subPages = array(), $level = 2 ) {
		MainWP_Menu::add_left_menu(
			array(
				'title'      => __( 'Users', 'mainwp' ),
				'parent_key' => 'mainwp_tab',
				'slug'       => 'UserBulkManage',
				'href'       => 'admin.php?page=UserBulkManage',
				'icon'       => '<i class="user icon"></i>',
				'desc'       => 'Manage users on your child sites',
			), 1
		);

		$init_sub_subleftmenu = array(
			array(
				'title'      => __( 'Manage Users', 'mainwp' ),
				'parent_key' => 'UserBulkManage',
				'href'       => 'admin.php?page=UserBulkManage',
				'slug'       => 'UserBulkManage',
				'right'      => 'manage_users',
			),
			array(
				'title'      => __( 'Add New', 'mainwp' ),
				'parent_key' => 'UserBulkManage',
				'href'       => 'admin.php?page=UserBulkAdd',
				'slug'       => 'UserBulkAdd',
				'right'      => '',
			),
			array(
				'title'      => __( 'Import Users', 'mainwp' ),
				'parent_key' => 'UserBulkManage',
				'href'       => 'admin.php?page=BulkImportUsers',
				'slug'       => 'BulkImportUsers',
				'right'      => '',
			),
			array(
				'title'      => __( 'Admin Passwords', 'mainwp' ),
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
	 * @param string $shownPage The page slug shown at this moment
	 */
	public static function render_header( $shownPage = '' ) {
		$params = array(
			'title' => __( 'Users', 'mainwp' ),
		);
		MainWP_UI::render_top_header( $params );

		$renderItems = array();

		if ( mainwp_current_user_can( 'dashboard', 'manage_users' ) ) {
			$renderItems[] = array(
				'title'  => __( 'Manage Users', 'mainwp' ),
				'href'   => 'admin.php?page=UserBulkManage',
				'active' => ( '' === $shownPage ) ? true : false,
			);
		}

		if ( ! MainWP_Menu::is_disable_menu_item( 3, 'UserBulkAdd' ) ) {
			$renderItems[] = array(
				'title'  => __( 'Add New', 'mainwp' ),
				'href'   => 'admin.php?page=UserBulkAdd',
				'active' => ( 'Add' === $shownPage ) ? true : false,
			);
		}

		if ( ! MainWP_Menu::is_disable_menu_item( 3, 'BulkImportUsers' ) ) {
			$renderItems[] = array(
				'title'  => __( 'Import Users', 'mainwp' ),
				'href'   => 'admin.php?page=BulkImportUsers',
				'active' => ( 'Import' === $shownPage ) ? true : false,
			);
		}

		if ( ! MainWP_Menu::is_disable_menu_item( 3, 'UpdateAdminPasswords' ) ) {
			$renderItems[] = array(
				'title'  => __( 'Admin Passwords', 'mainwp' ),
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
				$item['active'] = ( $subPage['slug'] == $shownPage ) ? true : false;
				$renderItems[]  = $item;
			}
		}

		MainWP_UI::render_page_navigation( $renderItems );
	}

	/**
	 * @param string $shownPage The page slug shown at this moment
	 */
	public static function render_footer( $shownPage = '' ) {
		echo '</div>';
	}

	public static function render() {
		if ( ! mainwp_current_user_can( 'dashboard', 'manage_users' ) ) {
			mainwp_do_not_have_permissions( __( 'manage users', 'mainwp' ) );

			return;
		}

		$cachedSearch = MainWP_Cache::get_cached_context( 'Users' );

		$selected_sites = $selected_groups = array();

		if ( null != $cachedSearch ) {
			if ( is_array($cachedSearch['sites'] ) ) {
				$selected_sites = $cachedSearch['sites'];
			} elseif ( is_array($cachedSearch['groups'] ) ) {
				$selected_groups = $cachedSearch['groups'];
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
								<select class="ui dropdown" id="mainwp-bulk-actions">
									<option value="edit"><?php esc_html_e( 'Edit', 'mainwp' ); ?></option>
									<option value="delete"><?php esc_html_e( 'Delete', 'mainwp' ); ?></option>
								</select>
								<button class="ui mini button" id="mainwp-do-users-bulk-actions"><?php esc_html_e( 'Apply', 'mainwp' ); ?></button>
								<?php do_action( 'mainwp_users_actions_bar_left' ); ?>
							</div>
							<div class="right aligned column">
								<?php do_action( 'mainwp_users_actions_bar_right' ); ?>
							</div>
						</div>
					</div>
				</div>
				<div id="mainwp_users_error"></div>
				<div id="mainwp-loading-users-row" style="display: none;">
					<div class="ui active inverted dimmer">
						<div class="ui indeterminate large text loader"><?php esc_html_e( 'Loading Users...', 'mainwp' ); ?>
							<span id="mainwp_users_loading_info" class="mainwp-grabbing-info-note"><br /><?php esc_html_e( 'Automatically refreshing to get up to date information.', 'mainwp' ); ?></span>
						</div>
					</div>
				</div>
				<div class="ui segment" id="mainwp_users_wrap_table">
					<div class="ui message" id="mainwp-message-zone" style="display:none"></div>
					<?php self::render_table( true ); ?>
				</div>
				<div id="mainwp-update-users-box" class="ui segment">
					<?php self::render_update_users(); ?>
				</div>
			</div>
			<div class="mainwp-side-content mainwp-no-padding">
				<div class="mainwp-select-sites">
					<div class="ui header"><?php esc_html_e( 'Select Sites', 'mainwp' ); ?></div>
					<?php MainWP_UI::select_sites_box( 'checkbox', true, true, 'mainwp_select_sites_box_left', '', $selected_sites, $selected_groups ); ?>
				</div>

				<?php
				$user_roles = array(
					'subscriber'    => __( 'Subscriber', 'mainwp' ),
					'administrator' => __( 'Administrator', 'mainwp' ),
					'editor'        => __( 'Editor', 'mainwp' ),
					'author'        => __( 'Author', 'mainwp' ),
					'contributor'   => __( 'Contributor', 'mainwp' ),
				);
				$user_roles = apply_filters( 'mainwp-users-manage-roles', $user_roles );
				?>

				<div class="ui divider"></div>
				<div class="mainwp-search-options">
					<div class="ui mini form">
						<div class="field">
							<select multiple="" class="ui fluid dropdown" id="mainwp_user_roles">
								<option value=""><?php esc_html_e( 'Select role', 'mainwp' ); ?></option>
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
				</div>
				<div class="ui divider"></div>
				<div class="mainwp-search-options">
					<div class="ui header"><?php esc_html_e( 'Search Options', 'mainwp' ); ?></div>
					<?php self::render_search_options(); ?>
				</div>
				<div class="ui divider"></div>
				<div class="mainwp-search-submit">
					<input type="button" name="mainwp_show_users" id="mainwp_show_users" class="ui green big fluid button" value="<?php esc_attr_e( 'Show Users', 'mainwp' ); ?>"/>
				</div>
			</div>
			<div class="ui hidden clearing divider"></div>
		</div>
		<?php
		self::render_footer( '' );
	}

	public static function render_search_options() {
		$cachedSearch = MainWP_Cache::get_cached_context( 'Users' );
		$statuses     = isset( $cachedSearch['status'] ) ? $cachedSearch['status'] : array();
		?>

		<div class="ui mini form">
			<div class="field">
				<div class="ui input fluid">
					<input type="text" placeholder="<?php esc_attr_e( 'Username', 'mainwp' ); ?>" id="mainwp_search_users" class="text" value="
					<?php
					if ( null != $cachedSearch ) {
						echo esc_attr( $cachedSearch['keyword'] );
					}
					?>
					"/>
				</div>
			</div>
		</div>
		<?php
		if ( is_array( $statuses ) && 0 < count( $statuses ) ) {
			$status = implode( "','", $statuses );
			$status = "'" . $status . "'";
			?>
			<script type="text/javascript">
			jQuery( document ).ready( function () {
				jQuery( '#mainwp_user_roles' ).dropdown( 'set selected', [<?php echo esc_html( $status ); ?>] );
			} );
			</script>
			<?php
		}
	}

	public static function render_update_users() {

		$editable_roles = array(
			'donotupdate'   => __( 'Do not update', 'mainwp' ),
			'administrator' => __( 'Administrator', 'mainwp' ),
			'subscriber'    => __( 'Subscriber', 'mainwp' ),
			'contributor'   => __( 'Contributor', 'mainwp' ),
			'author'        => __( 'Author', 'mainwp' ),
			'editor'        => __( 'Editor', 'mainwp' ),
		);

		$editable_roles     = apply_filters( 'mainwp-users-manage-roles', $editable_roles );
		$editable_roles[''] = __( '&mdash; No role for this site &mdash;', 'mainwp' );

		?>
		<div id="mainwp-edit-users-modal" class="ui modal">
			<div class="header"><?php esc_html_e( 'Edit User', 'mainwp' ); ?></div>
			<div class="ui message"><?php esc_html_e( 'Empty fields will not be passed to child sites.', 'mainwp' ); ?></div>
			<form id="update_user_profile">
				<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
				<div class="ui segment">
					<div class="ui form">
						<h3><?php esc_html_e( 'Name', 'mainwp' ); ?></h2>
						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'Role', 'mainwp' ); ?></label>
							<div class="ui six wide column">
								<div class="ui left labeled input">
									<select name="role" id="role">
									<?php
									foreach ( $editable_roles as $role_id => $role_name ) {
										echo '<option value="' . $role_id . '" ' . ( 'donotupdate' === $role_id ? 'selected="selected"' : '' ) . '>' . $role_name . '</option>';
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
			<div class="actions">
				<div id="mainwp_update_password_error" style="display: none"></div>
				<span id="mainwp_users_updating"><i class="ui active inline loader tiny"></i></span>
				<div class="ui cancel button"><?php esc_html_e( 'Cancel', 'mainwp' ); ?></div>
				<input type="button" class="ui green button" id="mainwp_btn_update_user" value="<?php esc_attr_e( 'Update', 'mainwp' ); ?>">
			</div>

		</div>
		<?php
	}

	public static function render_table( $cached = true, $role = '', $groups = '', $sites = '', $search = null ) {
		?>
		<table id="mainwp-users-table" class="ui tablet stackable single line table" style="width:100%">
			<thead>
				<tr>
					<th class="no-sort collapsing check-column"><span class="ui checkbox"><input id="cb-select-all-top" type="checkbox" /></span></th>
					<th><?php esc_html_e( 'Name', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Username', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'E-mail', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Role', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Posts', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Website', 'mainwp' ); ?></th>
					<th id="mainwp-users-actions" class="no-sort collapsing"></th>
				</tr>
			</thead>
			<tbody id="mainwp-users-list">
			<?php
			if ( $cached ) {
				MainWP_Cache::echo_body( 'Users' );
			} else {
				self::render_table_body( $role, $groups, $sites, $search );
			}
			?>
			</tbody>
		</table>
		<script type="text/javascript">
		jQuery( document ).ready( function () {
			jQuery( '#mainwp-users-table' ).DataTable( {
			"colReorder" : true,
			"stateSave":  true,
			"pagingType": "full_numbers",
			"order": [],
			"scrollX" : true,
			"lengthMenu": [ [10, 25, 50, 100, -1], [10, 25, 50, 100, "All"] ],
			"columnDefs": [ {
				"targets": 'no-sort',
				"orderable": false
			} ],
			"preDrawCallback": function( settings ) {
			<?php if ( ! $cached ) { ?>
			jQuery('#mainwp-users-table .ui.dropdown').dropdown();
			jQuery('#mainwp-users-table .ui.checkbox').checkbox();
			<?php } ?>
			}
			} );
		} );
		</script>
		<?php
	}

	public static function render_table_body( $role = '', $groups = '', $sites = '', $search = null ) {
		MainWP_Cache::init_cache( 'Users' );

		$output         = new stdClass();
		$output->errors = array();
		$output->users  = 0;

		if ( 1 == get_option( 'mainwp_optimize' ) ) {

			$check_users_role = false;

			if ( ! empty( $role ) ) {
				$roles = explode( ',', $role );
				if ( is_array( $roles ) ) {
					$check_users_role = true;
				}
			}

			if ( '' !== $sites ) {
				foreach ( $sites as $k => $v ) {
					if ( MainWP_Utility::ctype_digit( $v ) ) {
						$search_user_role = array();
						$website          = MainWP_DB::instance()->get_website_by_id( $v );
						$allUsers         = json_decode( $website->users, true );
						$allUsersCount    = count( $allUsers );

						if ( $check_users_role ) {
							for ( $i = 0; $i < $allUsersCount; $i ++ ) {
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

						for ( $i = 0; $i < $allUsersCount; $i ++ ) {
							$user = $allUsers[ $i ];
							if ( '' !== $search && ! stristr( $user['login'], trim( $search ) ) && ! stristr( $user['display_name'], trim( $search ) ) && ! stristr( $user['email'], trim( $search ) ) ) {
								continue;
							}

							if ( $check_users_role ) {
								if ( ! in_array( $user['id'], $search_user_role) ) {
									continue;
								}
							}

							$tmpUsers       = array( $user );
							$output->users += self::users_search_handler_renderer( $tmpUsers, $website );
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
							$allUsers      = json_decode( $website->users, true );
							$allUsersCount = count( $allUsers );
							if ( $check_users_role ) {
								for ( $i = 0; $i < $allUsersCount; $i ++ ) {
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
							for ( $i = 0; $i < $allUsersCount; $i ++ ) {
								$user = $allUsers[ $i ];
								if ( '' !== $search && ! stristr( $user['login'], trim( $search ) ) && ! stristr( $user['display_name'], trim( $search ) ) && ! stristr( $user['email'], trim( $search ) ) ) {
									continue;
								}

								if ( $check_users_role ) {
									if ( ! in_array($user['id'], $search_user_role) ) {
										continue;
									}
								}

								$tmpUsers       = array( $user );
								$output->users += self::users_search_handler_renderer( $tmpUsers, $website );
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
								)
							);
						}
						MainWP_DB::free_result( $websites );
					}
				}
			}

			$post_data = array(
				'role'           => $role,
				'search'         => '*' . trim( $search ) . '*',
				'search_columns' => 'user_login,display_name,user_email',
			);

			MainWP_Utility::fetch_urls_authed( $dbwebsites, 'search_users', $post_data, array(
				self::get_class_name(),
				'users_search_handler',
			), $output );
		}

		MainWP_Cache::add_context(
			'Users', array(
				'count'   => $output->users,
				'keyword' => $search,
				'status'  => ( isset( $_POST['role'] ) ? $_POST['role'] : 'administrator' ),
				'sites'   => '' !== $sites ? $sites : '',
				'groups'  => '' !== $groups ? $groups : '',
			)
		);

		// Sort if required

		if ( 0 == $output->users ) {
			ob_start();
			?>
				<tr><td colspan="999"><?php esc_html_e( 'Please use the search options to find wanted users.', 'mainwp' ); ?></td></tr>
			<?php
			$newOutput = ob_get_clean();
			echo $newOutput;
			MainWP_Cache::add_body( 'Users', $newOutput );

			return;
		}
	}

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

	protected static function users_search_handler_renderer( $users, $website ) {
		$return = 0;

		foreach ( $users as $user ) {
			ob_start();
			?>
			<tr>
					<td class="check-column"><span class="ui checkbox"><input type="checkbox" name="user[]" value="1"></span></td>
					<td class="name column-name">
					<input class="userId" type="hidden" name="id" value="<?php echo esc_attr( $user['id'] ); ?>" />
					<input class="userName" type="hidden" name="name" value="<?php echo esc_attr( $user['login'] ); ?>" />
					<input class="websiteId" type="hidden" name="id" value="<?php echo intval($website->id); ?>" />
					<?php echo ! empty( $user['display_name'] ) ? esc_html( $user['display_name'] ) : '&nbsp;'; ?>
					<div class="row-actions-working">
						<i class="ui active inline loader tiny"></i> <?php esc_html_e( 'Please wait', 'mainwp' ); ?>
					</div>
					</td>
				<td class="username column-username"><strong><abbr title="<?php echo esc_attr( $user['login'] ); ?>"><?php echo esc_html( $user['login'] ); ?></abbr></strong></td>
				<td class="email column-email"><a href="mailto:<?php echo esc_attr( $user['email'] ); ?>"><?php echo esc_html( $user['email'] ); ?></a></td>
				<td class="role column-role"><?php echo self::get_role( $user['role'] ); ?></td>
				<td class="posts column-posts"><a href="<?php echo admin_url( 'admin.php?page=PostBulkManage&siteid=' . intval( $website->id ) . '&userid=' . $user['id'] ); ?>"><?php echo esc_html( $user['post_count'] ); ?></a></td>
				<td class="website column-website"><a href="<?php echo esc_url( $website->url ); ?>" target="_blank"><?php echo esc_html( $website->url ); ?></a></td>
				<td class="right aligned">
					<div class="ui right pointing dropdown icon mini basic green button" style="z-index: 999">
						<a href="javascript:void(0)"><i class="ellipsis horizontal icon"></i></a>
						<div class="menu">
							<a class="item user_getedit" href="#"><?php esc_html_e( 'Edit', 'mainwp' ); ?></a>
							<?php if ( ( 1 != $user['id'] ) && ( $user['login'] != $website->adminname ) ) { ?>
							<a class="item user_submitdelete" href="#"><?php esc_html_e( 'Delete', 'mainwp' ); ?></a>
							<?php } elseif ( ( 1 == $user['id'] ) || ( $user['login'] == $website->adminname ) ) { ?>
							<a href="javascript:void(0)" class="item" data-tooltip="This user is used for our secure link, it can not be deleted." data-inverted="" data-position="left center"><?php esc_html_e( 'Delete', 'mainwp' ); ?></a>
							<?php } ?>
							<a class="item" href="<?php echo 'admin.php?page=SiteOpen&newWindow=yes&websiteid=' . $website->id; ?>" data-tooltip="<?php esc_attr_e( 'Jump to the site WP Admin', 'mainwp' ); ?>"  data-position="bottom right"  data-inverted="" class="open_newwindow_wpadmin ui green basic icon button" target="_blank"><?php esc_html_e( 'Go to WP Admin', 'mainwp' ); ?></a>
						</div>
					</div>
				</td>
			</tr>
			<?php
			$newOutput = ob_get_clean();
			echo $newOutput;
			MainWP_Cache::add_body( 'Users', $newOutput );
			$return ++;
		}

		return $return;
	}

	public static function users_search_handler( $data, $website, &$output ) {
		if ( 0 < preg_match( '/<mainwp>(.*)<\/mainwp>/', $data, $results ) ) {
			$result = $results[1];
			$users  = MainWP_Utility::get_child_response( base64_decode( $result ) );
			unset( $results );
			$output->users += self::users_search_handler_renderer( $users, $website );
			unset( $users );
		} else {
			$output->errors[ $website->id ] = MainWP_Error_Helper::get_error_message( new MainWP_Exception( 'NOMAINWP', $website->url ) );
		}
	}

	public static function delete() {
		self::action( 'delete' );
		die( wp_json_encode( array( 'result' => __( 'User has been deleted', 'mainwp' ) ) ) );
	}

	public static function edit() {
		$information = self::action( 'edit' );
		wp_send_json( $information );
	}

	public static function update_user() {
		self::action( 'update_user' );
		die( wp_json_encode( array( 'result' => __( 'User has been updated', 'mainwp' ) ) ) );
	}

	public static function update_password() {
		self::action( 'update_password' );
		die( wp_json_encode( array( 'result' => __( 'User password has been updated', 'mainwp' ) ) ) );
	}

	public static function action( $pAction, $extra = '' ) {
		$userId       = $_POST['userId'];
		$userName     = $_POST['userName'];
		$websiteIdEnc = $_POST['websiteId'];
		$pass         = stripslashes( utf8_decode( urldecode( $_POST['update_password'] ) ) );

		if ( ! MainWP_Utility::ctype_digit( $userId ) ) {
			die( wp_json_encode( array( 'error' => __( 'Invalid request!', 'mainwp' ) ) ) );
		}

		$websiteId = $websiteIdEnc;

		if ( ! MainWP_Utility::ctype_digit( $websiteId ) ) {
			die( wp_json_encode( array( 'error' => __( 'Invalid request!', 'mainwp' ) ) ) );
		}

		$website = MainWP_DB::instance()->get_website_by_id( $websiteId );
		if ( ! MainWP_Utility::can_edit_website( $website ) ) {
			die( wp_json_encode( array( 'error' => __( 'You can not edit this website!', 'mainwp' ) ) ) );
		}

		if ( ( 'delete' === $pAction ) && ( $website->adminname == $userName ) ) {
			die( wp_json_encode( array( 'error' => __( 'This user is used for our secure link, it can not be deleted.', 'mainwp' ) ) ) );
		}

		if ( 'update_user' === $pAction ) {
			$user_data = $_POST['user_data'];
			parse_str( $user_data, $extra);
			if ( $website->adminname == $userName ) {

				if ( is_array( $extra ) && isset( $extra['role'] ) ) {
					unset( $extra['role'] );
				}
			}

			if ( ! empty($pass) ) {
				$extra['pass1'] = $extra['pass2'] = $pass;
			}
		}

		$optimize = ( 1 == get_option( 'mainwp_optimize' ) ) ? 1 : 0;

		try {
			$information = MainWP_Utility::fetch_url_authed(
				$website, 'user_action', array(
					'action'    => $pAction,
					'id'        => $userId,
					'extra'     => $extra,
					'user_pass' => $pass,
					'optimize'  => $optimize,
				)
			);
		} catch ( MainWP_Exception $e ) {
			die( wp_json_encode( array( 'error' => MainWP_Error_Helper::get_error_message( $e ) ) ) );
		}

		if ( is_array( $information ) && isset( $information['error'] ) ) {
			wp_send_json( array( 'error' => $information['error'] ) );
		}

		if ( ! isset( $information['status'] ) || ( 'SUCCESS' !== $information['status'] ) ) {
			die( wp_json_encode( array( 'error' => __( 'Unexpected error.', 'mainwp' ) ) ) );
		} elseif ( 'update_user' === $pAction ) {
			if ( $optimize && isset( $information['users'] ) ) {
				$websiteValues['users'] = wp_json_encode( $information['users'] );
				MainWP_DB::instance()->update_website_values( $websiteId, $websiteValues );
			}
		}

		if ( 'edit' === $pAction ) {
			if ( $website->adminname == $userName ) {
				// This user is used for our secure link, you can not change the role.
				if ( is_array( $information ) && isset( $information['user_data'] ) ) {
					$information['is_secure_admin'] = 1;
				}
			}
		}

		return $information;
	}

	public static function render_bulk_add() {
		self::render_header( 'Add' );
		?>

		<div class="ui alt segment" id="mainwp-add-users">
			<form action="" method="post" name="createuser" id="createuser" class="add:users: validate">
				<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
				<div class="mainwp-main-content">
					<div class="ui hidden divider"></div>
					<div class="ui message" id="mainwp-message-zone" style="display:none;"></div>
					<div id="mainwp-add-new-user-form" class="ui segment">
						<div class="ui form">
							<h3 class="ui dividing header"><?php esc_html_e( 'Create a New User', 'mainwp' ); ?></h3>

							<div class="ui grid field">
								<label class="six wide column middle aligned"><?php esc_html_e( 'Username', 'mainwp' ); ?></label>
								<div class="ui six wide column">
									<div class="ui left labeled input">
										<input type="text" id="user_login" name="user_login" value="<?php echo ( isset( $_POST['user_login'] ) ) ? esc_attr( $_POST['user_login'] ) : ''; ?>">
									</div>
								</div>
							</div>

							<div class="ui grid field">
								<label class="six wide column middle aligned"><?php esc_html_e( 'E-mail', 'mainwp' ); ?></label>
								<div class="ui six wide column">
									<div class="ui left labeled input">
										<input type="text" id="email" name="email" value="<?php echo ( isset( $_POST['email'] ) ) ? esc_attr( $_POST['email'] ) : ''; ?>">
									</div>
								</div>
							</div>

							<div class="ui grid field">
								<label class="six wide column middle aligned"><?php esc_html_e( 'First Name', 'mainwp' ); ?></label>
								<div class="ui six wide column">
									<div class="ui left labeled input">
										<input type="text" id="first_name" name="first_name" value="<?php echo ( isset( $_POST['first_name'] ) ) ? esc_attr( $_POST['first_name'] ) : ''; ?>">
									</div>
								</div>
							</div>

							<div class="ui grid field">
								<label class="six wide column middle aligned"><?php esc_html_e( 'Last Name', 'mainwp' ); ?></label>
								<div class="ui six wide column">
									<div class="ui left labeled input">
										<input type="text" id="last_name" name="last_name" value="<?php echo ( isset( $_POST['last_name'] ) ) ? esc_attr( $_POST['last_name'] ) : ''; ?>">
									</div>
								</div>
							</div>

							<div class="ui grid field">
								<label class="six wide column middle aligned"><?php esc_html_e( 'Website', 'mainwp' ); ?></label>
								<div class="ui six wide column">
									<div class="ui left labeled input">
										<input type="text" id="url" name="url" value="<?php echo ( isset( $_POST['url'] ) ) ? esc_attr( $_POST['url'] ) : ''; ?>">
									</div>
								</div>
							</div>

							<div class="ui grid field">
								<label class="six wide column middle aligned"><?php esc_html_e( 'Password', 'mainwp' ); ?></label>
								<div class="ui six wide column">
									<div class="ui left labeled action input">
										<input class="hidden" value=" "/>
										<input type="text" id="password" name="password" autocomplete="off" value="<?php echo esc_attr( wp_generate_password( 24 ) ); ?>">
										<button class="ui green right button wp-generate-pw"><?php esc_html_e( 'Generate Password', 'mainwp' ); ?></button>
									</div>
								</div>
							</div>

							<div class="ui grid field">
								<label class="six wide column middle aligned"><?php esc_html_e( 'Send Password?', 'mainwp' ); ?></label>
								<div class="six wide column ui toggle checkbox">
									<input type="checkbox" name="send_password" id="send_password" <?php echo ( isset( $_POST['send_password'] ) ) ? 'checked' : ''; ?> >
								</div>
							</div>

							<?php
							$user_roles = array(
								'subscriber'    => __( 'Subscriber', 'mainwp' ),
								'administrator' => __( 'Administrator', 'mainwp' ),
								'editor'        => __( 'Editor', 'mainwp' ),
								'author'        => __( 'Author', 'mainwp' ),
								'contributor'   => __( 'Contributor', 'mainwp' ),
							);
							$user_roles = apply_filters( 'mainwp-users-manage-roles', $user_roles );

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
											<option value="<?php echo esc_html( $r ); ?>" <?php echo ( isset( $_POST['role'] ) && $_POST['role'] == $r ) ? 'selected' : ''; ?>><?php echo esc_html( $n ); ?></option>
											<?php
										}
										?>
									</select>
								</div>
							</div>

						</div>
					</div>
				</div>
				<div class="mainwp-side-content mainwp-no-padding">
					<div class="mainwp-select-sites">
						<div class="ui header"><?php esc_html_e( 'Select Sites', 'mainwp' ); ?></div>
						<?php MainWP_UI::select_sites_box(); ?>
					</div>
					<div class="ui divider"></div>
					<div class="mainwp-search-submit">
						<input type="button" name="createuser" id="bulk_add_createuser" class="ui big green fluid button" value="<?php esc_attr_e( 'Add New User', 'mainwp' ); ?> "/>
					</div>
				</div>
				<div style="clear:both"></div>
			</form>
		</div>

		<?php
		self::render_footer( 'Add' );
	}

	public static function render_bulk_import_users() {
		if ( isset( $_FILES['import_user_file_bulkupload'] ) && UPLOAD_ERR_OK == $_FILES['import_user_file_bulkupload']['error'] ) {
			self::render_bulk_upload();
			return;
		}
		?>
		<?php self::render_header( 'Import' ); ?>
		<div id="MainWP_Bulk_AddUser">
			<form action="" method="post" name="createuser" id="createuser" class="add:users: validate" enctype="multipart/form-data">
				<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
				<?php echo self::render_import_users(); ?>
			</form>
		</div>
		<?php
		self::render_footer( 'Import' );
	}

	public static function render_import_users() {
		?>
		<div class="ui segment" id="mainwp-import-sites">
			<div class="ui hidden divider"></div>
			<div id="mainwp-message-zone" class="ui message" style="display:none"></div>
			<h3 class="ui dividing header"><?php esc_html_e( 'Import Users', 'mainwp' ); ?></h3>
				<div id="mainwp-message-zone" class="ui message" style="display:none"></div>
				<div class="ui segment form">
					<form method="POST" action="" enctype="multipart/form-data" id="mainwp_managesites_bulkadd_form">
						<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'Uplod the CSV file', 'mainwp' ); ?></label>
							<div class="ten wide column">
								<input type="file" name="import_user_file_bulkupload" id="import_user_file_bulkupload" accept="text/comma-separated-values" />
							</div>
						</div>
						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'CSV file contains a header', 'mainwp' ); ?></label>
							<div class="ui toggle checkbox">
								<input type="checkbox" name="import_user_chk_header_first" checked="checked" id="import_user_chk_header_first" value="1"/>
							</div>
						</div>
						<div class="ui divider"></div>
						<a href="https://mainwp.com/csv/sample_users.csv" class="ui big green basic button"><?php esc_html_e( 'Download Sample CSV file', 'mainwp' ); ?></a>
						<input type="button" name="createuser" id="bulk_import_createuser" class="ui big green right floated button" value="<?php esc_attr_e( 'Import Users', 'mainwp' ); ?>"/>
					</form>
				</div>
			</div>
		<?php
	}

	public static function do_buk_add() {
		$errors      = array();
		$errorFields = array();

		if ( isset( $_POST['select_by'] ) ) {
			$selected_sites = array();
			if ( isset( $_POST['selected_sites'] ) && is_array( $_POST['selected_sites'] ) ) {
				foreach ( $_POST['selected_sites'] as $selected ) {
					$selected_sites[] = $selected;
				}
			}

			$selected_groups = array();

			if ( isset( $_POST['selected_groups'] ) && is_array( $_POST['selected_groups'] ) ) {
				foreach ( $_POST['selected_groups'] as $selected ) {
					$selected_groups[] = $selected;
				}
			}

			if ( ( 'group' === $_POST['select_by'] && 0 == count( $selected_groups ) ) || ( 'site' === $_POST['select_by'] && 0 == count( $selected_sites ) ) ) {
				$errors[] = __( 'Please select at least one website or group.', 'mainwp' );
			}
		} else {
			$errors[] = __( 'Please select at least one website or group.', 'mainwp' );
		}

		if ( ! isset( $_POST['user_login'] ) || '' === $_POST['user_login'] ) {
			$errorFields[] = 'user_login';
		}

		if ( ! isset( $_POST['email'] ) || '' === $_POST['email'] ) {
			$errorFields[] = 'email';
		}

		$allowed_roles = array( 'subscriber', 'administrator', 'editor', 'author', 'contributor' );
		$cus_roles     = array();
		$cus_roles     = apply_filters( 'mainwp-users-manage-roles', $cus_roles );
		if ( is_array( $cus_roles ) && 0 < count( $cus_roles ) ) {
			$cus_roles     = array_keys( $cus_roles );
			$allowed_roles = array_merge( $allowed_roles, $cus_roles );
		}

		if ( ! isset( $_POST['role'] ) || ! in_array( $_POST['role'], $allowed_roles ) ) {
			$errorFields[] = 'role';
		}

		if ( ( 0 == count( $errors ) ) && ( 0 == count( $errorFields ) ) ) {
			$user_to_add = array(
				'user_pass'  => $_POST['pass1'],
				'user_login' => $_POST['user_login'],
				'user_url'   => $_POST['url'],
				'user_email' => $_POST['email'],
				'first_name' => $_POST['first_name'],
				'last_name'  => $_POST['last_name'],
				'role'       => $_POST['role'],
			);

			$dbwebsites = array();

			if ( 'site' === $_POST['select_by'] ) {
				foreach ( $selected_sites as $k ) {
					if ( MainWP_Utility::ctype_digit( $k ) ) {
						$website                    = MainWP_DB::instance()->get_website_by_id( $k );
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
							)
						);
					}
				}
			} else {
				foreach ( $selected_groups as $k ) {
					if ( MainWP_Utility::ctype_digit( $k ) ) {
						$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_by_group_id( $k ) );
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
								)
							);
						}
						MainWP_DB::free_result( $websites );
					}
				}
			}

			$startTime = time();
			if ( 0 < count( $dbwebsites ) ) {
				$post_data      = array(
					'new_user'      => base64_encode( serialize( $user_to_add ) ),
					'send_password' => ( isset( $_POST['send_password'] ) ? $_POST['send_password'] : '' ),
				);
				$output         = new stdClass();
				$output->ok     = array();
				$output->errors = array();
				MainWP_Utility::fetch_urls_authed( $dbwebsites, 'newuser', $post_data, array(
					MainWP_Bulk_Add::get_class_name(),
					'posting_bulk_handler',
				), $output );
			}

			$countSites = $countRealItems = 0;
			foreach ( $dbwebsites as $website ) {
				if ( isset( $output->ok[ $website->id ] ) && 1 == $output->ok[ $website->id ] ) {
					$countSites ++;
					$countRealItems++;
				}
			}

			if ( ! empty( $countSites ) ) {
				$seconds = ( time() - $startTime );
				MainWP_Twitter::update_twitter_info( 'create_new_user', $countSites, $seconds, $countRealItems, $startTime, 1 );
			}

			if ( MainWP_Twitter::enabled_twitter_messages() ) {
				$twitters = MainWP_Twitter::get_twitter_notice( 'create_new_user' );
				if ( is_array( $twitters ) ) {
					foreach ( $twitters as $timeid => $twit_mess ) {
						if ( ! empty( $twit_mess ) ) {
							$sendText = MainWP_Twitter::get_twit_to_send( 'create_new_user', $timeid );
							?>
							<div class="mainwp-tips ui info message twitter" style="margin:0">
								<i class="ui close icon mainwp-dismiss-twit"></i><span class="mainwp-tip" twit-what="create_new_user" twit-id="<?php echo $timeid; ?>"><?php echo $twit_mess; ?></span>&nbsp;<?php MainWP_Twitter::gen_twitter_button( $sendText ); ?>
							</div>
							<?php
						}
					}
				}
			}

			?>

			<div id="mainwp-creating-new-user-modal" class="ui modal">
				<div class="header"><?php esc_html_e( 'New User', 'mainwp' ); ?></div>
				<div class="content">
					<div class="ui middle aligned divided selection list">
						<?php foreach ( $dbwebsites as $website ) : ?>
						<div class="item ui grid">
							<span class="content"><a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"><?php echo stripslashes( $website->name ); ?></a></span>
							<span class="right floated content"><?php echo( isset( $output->ok[ $website->id ] ) && 1 == $output->ok[ $website->id ] ? '<i class="check green icon"></i> ' : '<i class="times red icon"></i> ' . $output->errors[ $website->id ] ); ?></span>
						</div>
						<?php endforeach; ?>
					</div>
				</div>
				<div class="actions">
					<div class="ui cancel button"><?php esc_html_e( 'Close', 'mainwp' ); ?></div>
				</div>
			</div>
			<?php
		} else {
			echo wp_json_encode( array( $errorFields, $errors ) );
		}
	}

	public static function render_bulk_upload() {
		self::render_header( 'Import' );

		$errors = array();
		if ( UPLOAD_ERR_OK == $_FILES['import_user_file_bulkupload']['error'] ) {
			if ( is_uploaded_file( $_FILES['import_user_file_bulkupload']['tmp_name'] ) ) {
				$tmp_path = $_FILES['import_user_file_bulkupload']['tmp_name'];
				$content  = file_get_contents( $tmp_path );
				$lines    = explode( "\r\n", $content );

				if ( is_array( $lines ) && 0 < count( $lines ) ) {
					$i = 0;
					if ( $_POST['import_user_chk_header_first'] ) {
						$header_line = trim( $lines[0] ) . "\n";
						unset( $lines[0] );
					}

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
						<input type="hidden" id="user_import_csv_line_<?php echo ( $i + 1 ); ?>" original-line="<?php echo esc_html( $line ); ?>" encoded-data="<?php echo esc_html( $encoded ); ?>" />
						<?php
						$i++;
					}
					$header_line = trim( $header_line );
					?>
					<div class="ui modal" id="mainwp-import-users-modal">
						<div class="header"><?php esc_html_e( 'Importing new users and add them to your sites.', 'mainwp' ); ?></div>
						<div class="scrolling header">
							<div id="MainWPBulkUploadUserLoading" style="display: none;"><i class="ui active inline loader tiny"></i> <?php esc_html_e( 'Importing Users', 'mainwp' ); ?></div>
							<input type="hidden" id="import_user_do_import" value="1"/>
							<input type="hidden" id="import_user_total_import" value="<?php echo $i; ?>"/>
							<p>
								<div class="import_user_import_listing" id="import_user_import_logging">
									<pre class="log"><?php echo esc_html( $header_line ) . "\n"; ?></pre>
								</div>
							</p>
							<div id="import_user_import_failed_rows" style="display: none;">
								<span><?php echo esc_html( $header_line ); ?></span>
							</div>
						</div>
						<div class="actions">
							<div class="ui cancel button"><?php esc_html_e( 'Close', 'mainwp' ); ?></div>
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
					$errors[] = __( 'Data is not valid.', 'mainwp' ) . '<br />';
				}
			} else {
				$errors[] = __( 'Upload error.', 'mainwp' ) . '<br />';
			}
		} else {
			$errors[] = __( 'Upload error.', 'mainwp' ) . '<br />';
		}

		if ( 0 < count( $errors ) ) {
			?>
			<div class="error below-h2">
				<?php foreach ( $errors as $error ) { ?>
					<p><strong><?php esc_html_e( 'Error', 'mainwp' ); ?></strong>: <?php echo esc_html( $error ); ?></p>
				<?php } ?>
			</div>
			<br/>
			<a href="<?php echo get_admin_url(); ?>admin.php?page=UserBulkAdd" class="add-new-h2" target="_top"><?php esc_html_e( 'Add New', 'mainwp' ); ?></a>
			<a href="<?php echo get_admin_url(); ?>admin.php?page=mainwp_tab" class="add-new-h2" target="_top"><?php esc_html_e( 'Return to Overview', 'mainwp' ); ?></a>
			<?php
		}

		self::render_footer( 'Import' );
	}

	public static function do_import() {
		if ( isset( $_POST['select_by'] ) ) {
			$selected_sites = array();
			if ( isset( $_POST['selected_sites'] ) && is_array( $_POST['selected_sites'] ) ) {
				foreach ( $_POST['selected_sites'] as $selected ) {
					$selected_sites[] = $selected;
				}
			}

			$selected_groups = array();
			if ( isset( $_POST['selected_groups'] ) && is_array( $_POST['selected_groups'] ) ) {
				foreach ( $_POST['selected_groups'] as $selected ) {
					$selected_groups[] = $selected;
				}
			}
		}
		$user_to_add = array(
			'user_pass'  => $_POST['pass1'],
			'user_login' => $_POST['user_login'],
			'user_url'   => $_POST['url'],
			'user_email' => $_POST['email'],
			'first_name' => $_POST['first_name'],
			'last_name'  => $_POST['last_name'],
			'role'       => $_POST['role'],
		);

		$ret         = array();
		$dbwebsites  = array();
		$not_valid   = array();
		$error_sites = '';
		if ( 'site' === $_POST['select_by'] ) {
			foreach ( $selected_sites as $url ) {
				if ( ! empty( $url ) ) {
					$website = MainWP_DB::instance()->get_websites_by_url( $url );
					if ( $website ) {
						$dbwebsites[ $website[0]->id ] = MainWP_Utility::map_site(
							$website[0], array(
								'id',
								'url',
								'name',
								'adminname',
								'nossl',
								'privkey',
								'nosslkey',
								'http_user',
								'http_pass',
							)
						);
					} else {
						$not_valid[]  = __( 'Unexisting website. Please try again.', 'mainwp' ) . ' ' . $url;
						$error_sites .= $url . ';';
					}
				}
			}
		} else {
			foreach ( $selected_groups as $group ) {
				if ( MainWP_DB::instance()->get_groups_by_name( $group ) ) {
					$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_by_group_name( $group ) );
					if ( $websites ) {
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
								)
							);
						}
						MainWP_DB::free_result( $websites );
					} else {
						$not_valid[]  = __( 'No websites assigned to the selected group.', 'mainwp' ) . ' ' . $group;
						$error_sites .= $group . ';';
					}
				} else {
					$not_valid[]  = __( 'Unexisting group selected. Please try again.', 'mainwp' ) . ' ' . $group;
					$error_sites .= $group . ';';
				}
			}
		}

		if ( 0 < count( $dbwebsites ) ) {
			$post_data      = array(
				'new_user'      => base64_encode( serialize( $user_to_add ) ),
				'send_password' => ( isset( $_POST['send_password'] ) ? $_POST['send_password'] : '' ),
			);
			$output         = new stdClass();
			$output->ok     = array();
			$output->errors = array();
			MainWP_Utility::fetch_urls_authed( $dbwebsites, 'newuser', $post_data, array(
				MainWP_Bulk_Add::get_class_name(),
				'posting_bulk_handler',
			), $output );
		}

		$ret['ok_list'] = $ret['error_list'] = array();
		foreach ( $dbwebsites as $website ) {
			if ( isset( $output->ok[ $website->id ] ) && 1 == $output->ok[ $website->id ] ) {
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
			$error_sites           = rtrim( $error_sites, ';' );
			$ret['failed_logging'] = esc_html( $_POST['user_login'] . ',' . $_POST['email'] . ',' . $_POST['first_name'] . ',' . $_POST['last_name'] . ',' . $_POST['url'] . ',' . $_POST['pass1'] . ',' . intval( $_POST['send_password'] ) . ',' . $_POST['role'] . ',' . $error_sites . ',' );
		}

		$ret['line_number'] = intval( $_POST['line_number'] );
		die( wp_json_encode( $ret ) );
	}

	/*
	 * Hook the section help content to the Help Sidebar element
	 */

	public static function mainwp_help_content() {
		if ( isset( $_GET['page'] ) && ( 'UserBulkManage' === $_GET['page'] || 'UserBulkAdd' === $_GET['page'] || 'UpdateAdminPasswords' === $_GET['page'] ) ) {
			?>
			<p><?php esc_html_e( 'If you need help with managing users, please review following help documents', 'mainwp' ); ?></p>
			<div class="ui relaxed bulleted list">
				<div class="item"><a href="https://mainwp.com/help/docs/manage-users/" target="_blank">Manage Users</a></div>
				<div class="item"><a href="https://mainwp.com/help/docs/manage-users/create-a-new-user/" target="_blank">Create a New User</a></div>
				<div class="item"><a href="https://mainwp.com/help/docs/manage-users/edit-an-existing-user/" target="_blank">Edit an Existing User</a></div>
				<div class="item"><a href="https://mainwp.com/help/docs/manage-users/bulk-update-administrator-passwords/" target="_blank">Bulk Update Administrator Passwords</a></div>
				<div class="item"><a href="https://mainwp.com/help/docs/manage-users/delete-users/" target="_blank">Delete User(s)</a></div>
			</div>
			<?php
		}
	}

}
