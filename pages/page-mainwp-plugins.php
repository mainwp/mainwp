<?php

/**
 *  MainWP Plugins Page
 *
 * @uses MainWP_Install_Bulk
 */
class MainWP_Plugins {

	public static function get_class_name() {
		return __CLASS__;
	}

	public static $subPages;
	public static $pluginsTable;

	public static function init() {
		/**
		 * This hook allows you to render the Plugins page header via the 'mainwp-pageheader-plugins' action.
		 *
		 * @link http://codex.mainwp.com/#mainwp-pageheader-plugins
		 *
		 * This hook is normally used in the same context of 'mainwp-getsubpages-plugins'
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-plugins
		 *
		 * @see \MainWP_Plugins::renderHeader
		 */
		add_action( 'mainwp-pageheader-plugins', array( self::get_class_name(), 'renderHeader' ) );

		/**
		 * This hook allows you to render the Plugins page footer via the 'mainwp-pagefooter-plugins' action.
		 *
		 * @link http://codex.mainwp.com/#mainwp-pagefooter-plugins
		 *
		 * This hook is normally used in the same context of 'mainwp-getsubpages-plugins'
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-plugins
		 *
		 * @see \MainWP_Plugins::renderFooter
		 */
		add_action( 'mainwp-pagefooter-plugins', array( self::get_class_name(), 'renderFooter' ) );

		add_action( 'mainwp_help_sidebar_content', array( self::get_class_name(), 'mainwp_help_content' ) );
	}

	public static function initMenu() {
		$_page = add_submenu_page(
			'mainwp_tab', __( 'Plugins', 'mainwp' ), '<span id="mainwp-Plugins">' . __( 'Plugins', 'mainwp' ) . '</span>', 'read', 'PluginsManage', array(
				self::get_class_name(),
				'render',
			)
		);
		if ( mainwp_current_user_can( 'dashboard', 'install_plugins' ) ) {
			$page = add_submenu_page(
				'mainwp_tab', __( 'Plugins', 'mainwp' ), '<div class="mainwp-hidden">' . __( 'Install ', 'mainwp' ) . '</div>', 'read', 'PluginsInstall', array(
					self::get_class_name(),
					'renderInstall',
				)
			);

			add_action( 'load-' . $page, array( self::get_class_name(), 'load_page' ) );
		}

		add_submenu_page(
			'mainwp_tab', __( 'Plugins', 'mainwp' ), '<div class="mainwp-hidden">' . __( 'Auto Updates', 'mainwp' ) . '</div>', 'read', 'PluginsAutoUpdate', array(
				self::get_class_name(),
				'renderAutoUpdate',
			)
		);
		add_submenu_page(
			'mainwp_tab', __( 'Plugins', 'mainwp' ), '<div class="mainwp-hidden">' . __( 'Ignored Updates', 'mainwp' ) . '</div>', 'read', 'PluginsIgnore', array(
				self::get_class_name(),
				'renderIgnore',
			)
		);
		add_submenu_page(
			'mainwp_tab', __( 'Plugins', 'mainwp' ), '<div class="mainwp-hidden">' . __( 'Ignored Abandoned', 'mainwp' ) . '</div>', 'read', 'PluginsIgnoredAbandoned', array(
				self::get_class_name(),
				'renderIgnoredAbandoned',
			)
		);

		/**
		 * This hook allows you to add extra sub pages to the Plugins page via the 'mainwp-getsubpages-plugins' filter.
		 *
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-plugins
		 */

		self::$subPages = apply_filters( 'mainwp-getsubpages-plugins', array() );

		if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
			foreach ( self::$subPages as $subPage ) {
				if ( MainWP_Menu::is_disable_menu_item( 3, 'Plugins' . $subPage['slug'] ) ) {
					continue;
				}
				add_submenu_page( 'mainwp_tab', $subPage['title'], '<div class="mainwp-hidden">' . $subPage['title'] . '</div>', 'read', 'Plugins' . $subPage['slug'], $subPage['callback'] );
			}
		}
		self::init_left_menu(self::$subPages);
	}

	public static function load_page() {
		self::$pluginsTable = new MainWP_Plugins_Install_List_Table();
		$pagenum            = self::$pluginsTable->get_pagenum();

		self::$pluginsTable->prepare_items();

		$total_pages = self::$pluginsTable->get_pagination_arg( 'total_pages' );

		if ( $pagenum > $total_pages && 0 < $total_pages ) {
			wp_safe_redirect( esc_url_raw( add_query_arg( 'paged', $total_pages ) ) );
			exit;
		}
	}

	public static function initMenuSubPages() {
		?>
		<div id="menu-mainwp-Plugins" class="mainwp-submenu-wrapper" xmlns="http://www.w3.org/1999/html">
			<div class="wp-submenu sub-open" >
				<div class="mainwp_boxout">
					<div class="mainwp_boxoutin"></div>
					<a href="<?php echo admin_url( 'admin.php?page=PluginsManage' ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Manage Plugins', 'mainwp' ); ?></a>
					<?php if ( mainwp_current_user_can( 'dashboard', 'install_plugins' ) ) : ?>
						<?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'PluginsInstall' ) ) : ?>
							<a href="<?php echo admin_url( 'admin.php?page=PluginsInstall' ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Install Plugins', 'mainwp' ); ?></a>
							<?php endif; ?>
							<?php endif; ?>
							<?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'PluginsAutoUpdate' ) ) : ?>
							<a href="<?php echo admin_url( 'admin.php?page=PluginsAutoUpdate' ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Auto Updates', 'mainwp' ); ?></a>
							<?php endif; ?>
							<?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'PluginsIgnore' ) ) : ?>
								<a href="<?php echo admin_url( 'admin.php?page=PluginsIgnore' ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Ignored Updates', 'mainwp' ); ?></a>
							<?php endif; ?>
							<?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'PluginsIgnoredAbandoned' ) ) : ?>
								<a href="<?php echo admin_url( 'admin.php?page=PluginsIgnoredAbandoned' ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Ignored Abandoned', 'mainwp' ); ?></a>
							<?php endif; ?>
							<?php
							if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
								foreach ( self::$subPages as $subPage ) {
									if ( MainWP_Menu::is_disable_menu_item( 3, 'Plugins' . $subPage['slug'] ) ) {
										continue;
									}
									?>
									<a href="<?php echo admin_url( 'admin.php?page=Plugins' . $subPage['slug'] ); ?>" class="mainwp-submenu"><?php echo esc_html( $subPage['title'] ); ?></a>
									<?php
								}
							}
							?>
				</div>
			</div>
		</div>
		<?php
	}

	public static function init_left_menu( $subPages = array() ) {
		MainWP_Menu::add_left_menu(
			array(
				'title'       => __( 'Plugins', 'mainwp' ),
				'parent_key'  => 'mainwp_tab',
				'slug'        => 'PluginsManage',
				'href'        => 'admin.php?page=PluginsManage',
				'icon'        => '<i class="plug icon"></i>',
			), 1
		);

		$init_sub_subleftmenu = array(
			array(
				'title'              => __( 'Manage Plugins', 'mainwp' ),
				'parent_key'         => 'PluginsManage',
				'href'               => 'admin.php?page=PluginsManage',
				'slug'               => 'PluginsManage',
				'right'              => '',
			),
			array(
				'title'              => __( 'Install Plugins', 'mainwp' ),
				'parent_key'         => 'PluginsManage',
				'href'               => 'admin.php?page=PluginsInstall',
				'slug'               => 'PluginsInstall',
				'right'              => 'install_plugins',
			),
			array(
				'title'              => __( 'Auto Updates', 'mainwp' ),
				'parent_key'         => 'PluginsManage',
				'href'               => 'admin.php?page=PluginsAutoUpdate',
				'slug'               => 'PluginsAutoUpdate',
				'right'              => '',
			),
			array(
				'title'              => __( 'Ignored Updates', 'mainwp' ),
				'parent_key'         => 'PluginsManage',
				'href'               => 'admin.php?page=PluginsIgnore',
				'slug'               => 'PluginsIgnore',
				'right'              => '',
			),
			array(
				'title'              => __( 'Ignored Abandoned', 'mainwp' ),
				'parent_key'         => 'PluginsManage',
				'href'               => 'admin.php?page=PluginsIgnoredAbandoned',
				'slug'               => 'PluginsIgnoredAbandoned',
				'right'              => '',
			),
		);

		MainWP_Menu::init_subpages_left_menu( $subPages, $init_sub_subleftmenu, 'PluginsManage', 'Plugins' );

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
	public static function renderHeader( $shownPage = '' ) {

		$params = array(
			'title' => __( 'Plugins', 'mainwp' ),
		);

		MainWP_UI::render_top_header( $params );

		$renderItems   = array();
		$renderItems[] = array(
			'title'  => __( 'Manage Plugins', 'mainwp' ),
			'href'   => 'admin.php?page=PluginsManage',
			'active' => ( 'Manage' === $shownPage ) ? true : false,
		);

		if ( mainwp_current_user_can( 'dashboard', 'install_plugins' ) ) {
			if ( ! MainWP_Menu::is_disable_menu_item( 3, 'PluginsInstall' ) ) {
				$renderItems[] = array(
					'title'  => __( 'Install', 'mainwp' ),
					'href'   => 'admin.php?page=PluginsInstall',
					'active' => ( 'Install' === $shownPage ) ? true : false,
				);
			}
		}

		if ( ! MainWP_Menu::is_disable_menu_item( 3, 'PluginsAutoUpdate' ) ) {
			$renderItems[] = array(
				'title'  => __( 'Auto Updates', 'mainwp' ),
				'href'   => 'admin.php?page=PluginsAutoUpdate',
				'active' => ( 'AutoUpdate' === $shownPage ) ? true : false,
			);
		}

		if ( ! MainWP_Menu::is_disable_menu_item( 3, 'PluginsIgnore' ) ) {
			$renderItems[] = array(
				'title'  => __( 'Ignored Updates', 'mainwp' ),
				'href'   => 'admin.php?page=PluginsIgnore',
				'active' => ( 'Ignore' === $shownPage ) ? true : false,
			);
		}

		if ( ! MainWP_Menu::is_disable_menu_item( 3, 'PluginsIgnoredAbandoned' ) ) {
			$renderItems[] = array(
				'title'  => __( 'Ignored Abandoned', 'mainwp' ),
				'href'   => 'admin.php?page=PluginsIgnoredAbandoned',
				'active' => ( 'IgnoreAbandoned' === $shownPage ) ? true : false,
			);
		}

		if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
			foreach ( self::$subPages as $subPage ) {
				if ( MainWP_Menu::is_disable_menu_item( 3, 'Plugins' . $subPage['slug'] ) ) {
					continue;
				}

				$item           = array();
				$item['title']  = $subPage['title'];
				$item['href']   = 'admin.php?page=Plugins' . $subPage['slug'];
				$item['active'] = ( $subPage['slug'] == $shownPage ) ? true : false;
				$renderItems[]  = $item;
			}
		}

		MainWP_UI::render_page_navigation( $renderItems );
	}

	/**
	 * @param string $shownPage The page slug shown at this moment
	 */
	public static function renderFooter( $shownPage ) {
		echo '</div>';
	}

	public static function render() {
		$cachedSearch    = MainWP_Cache::get_cached_context( 'Plugins' );
		$selected_sites  = array();
		$selected_groups = array();
		if ( null != $cachedSearch ) {
			if ( is_array( $cachedSearch['sites'] ) ) {
				$selected_sites = $cachedSearch['sites'];
			} elseif ( is_array( $cachedSearch['groups'] ) ) {
				$selected_groups = $cachedSearch['groups'];
			}
		}
		$cachedResult = MainWP_Cache::get_cached_result( 'Plugins' );
		self::renderHeader( 'Manage' );
		?>

		<div id="mainwp-manage-plugins" class="ui alt segment">
			<div class="mainwp-main-content">
				<div class="mainwp-actions-bar">
					<div class="ui grid">
						<div class="ui two column row">
							<div class="column">
								<div id="mainwp-plugins-bulk-actions-wapper">
									<?php
									if ( is_array( $cachedResult ) && isset( $cachedResult['bulk_actions'] ) ) {
										echo $cachedResult['bulk_actions'];
									} else {
										MainWP_UI::render_empty_bulk_actions();
									}
									?>
								</div>
								<?php do_action( 'mainwp_plugins_actions_bar_left' ); ?>
							</div>
							<div class="right aligned column">
								<a href="#" onclick="jQuery( '.mainwp_plugins_site_check_all' ).prop( 'checked', true ).change(); return false;" class="ui small button"><?php esc_html_e( 'Select all', 'mainwp' ); ?></a>
								<a href="#" onclick="jQuery( '.mainwp_plugins_site_check_all' ).prop( 'checked', false ).change(); return false;"   class="ui small button"><?php esc_html_e( 'Select none', 'mainwp' ); ?></a>
								<button id="mainwp-install-to-selected-sites" class="ui olive basic button" style="display: none"><?php esc_html_e( 'Install to Selected Site(s)', 'mainwp' ); ?></button>
								<?php do_action( 'mainwp_plugins_actions_bar_right' ); ?>
							</div>
						</div>
					</div>
				</div>
				<div class="ui segment" id="mainwp-plugins-table-wrapper">
					<div id="mainwp-message-zone" class="ui message" style="display:none"></div>
					<div id="mainwp-loading-plugins-row" class="ui active inverted dimmer" style="display:none">
						<div class="ui large text loader"><?php esc_html_e( 'Loading Plugins...', 'mainwp' ); ?></div>
					</div>
					<div id="mainwp-plugins-main-content" <?php echo ( null != $cachedSearch ) ? 'style="display: block;"' : ''; ?> >
						<div id="mainwp-plugins-content">
							<?php
							if ( is_array( $cachedResult ) && isset( $cachedResult['result'] ) ) {
								echo $cachedResult['result'];
							}
							?>
						</div>
					</div>
				</div>
			</div>

			<div class="mainwp-side-content mainwp-no-padding">
				<div class="mainwp-select-sites">
					<div class="ui header"><?php esc_html_e( 'Select Sites', 'mainwp' ); ?></div>
						<?php MainWP_UI::select_sites_box( 'checkbox', true, true, 'mainwp_select_sites_box_left', '', $selected_sites, $selected_groups ); ?>
					</div>
					<div class="ui divider"></div>
				<div class="mainwp-search-options">
					<div class="ui info message">
						<i class="close icon mainwp-notice-dismiss" notice-id="plugins-manage-info"></i>
						<?php esc_html_e( 'A plugin needs to be Inactive in order for it to be Activated or Deleted.', 'mainwp' ); ?>
					</div>
					<div class="ui mini form">
						<div class="field">
							<select multiple="" class="ui fluid dropdown" id="mainwp_plugins_search_by_status">
								<option value=""><?php esc_html_e( 'Select status', 'mainwp' ); ?></option>
								<option value="active"><?php esc_html_e( 'Active', 'mainwp' ); ?></option>
								<option value="inactive"><?php esc_html_e( 'Inactive', 'mainwp' ); ?></option>
							</select>
						</div>
					</div>
				</div>
				<div class="ui divider"></div>
				<div class="mainwp-search-options">
					<div class="ui header"><?php esc_html_e( 'Search Options', 'mainwp' ); ?></div>
						<?php self::renderSearchOptions(); ?>
					</div>
					<div class="ui divider"></div>
					<div class="mainwp-search-submit">
					<input type="button" name="mainwp-show-plugins" id="mainwp-show-plugins" class="ui green big fluid button" value="<?php esc_attr_e( 'Show Plugins', 'mainwp' ); ?>"/>
				</div>
			</div>
			<div style="clear:both"></div>
		</div>
		<?php
		self::renderFooter( 'Manage' );
	}

	public static function renderSearchOptions() {
		$cachedSearch = MainWP_Cache::get_cached_context( 'Plugins' );
		$statuses     = isset( $cachedSearch['status'] ) ? $cachedSearch['status'] : array();
		?>
		<div class="ui mini form">
			<div class="field">
				<div class="ui input fluid">
					<input type="text" placeholder="<?php esc_attr_e( 'Containing keyword', 'mainwp' ); ?>" id="mainwp_plugin_search_by_keyword" class="text" value="
					<?php
					if ( null != $cachedSearch ) {
						echo esc_attr( $cachedSearch['keyword'] ); }
					?>
					"/>
				</div>
			</div>
		</div>

		<?php
		if ( is_array( $statuses ) && 0 < count( $statuses ) ) {
			$status = '';
			foreach ( $statuses as $st ) {
				$status .= "'" . esc_attr( $st ) . "',";
			}
			$status = rtrim( $status, ',' );
			?>
			<script type="text/javascript">
				jQuery( document ).ready( function () {
					jQuery( '#mainwp_plugins_search_by_status' ).dropdown( 'set selected', [<?php esc_html_e( $status ); ?>] );
				} );
			</script>
			<?php
		}
	}

	public static function renderTable( $keyword, $status, $groups, $sites ) {
		MainWP_Cache::init_cache( 'Plugins' );

			$output          = new stdClass();
			$output->errors  = array();
			$output->plugins = array();

		if ( 1 == get_option( 'mainwp_optimize' ) ) {
			if ( '' !== $sites ) {
				foreach ( $sites as $k => $v ) {
					if ( MainWP_Utility::ctype_digit( $v ) ) {
						$website    = MainWP_DB::Instance()->getWebsiteById( $v );
						$allPlugins = json_decode( $website->plugins, true );
						$_count     = count( $allPlugins );
						for ( $i = 0; $i < $_count; $i ++ ) {
							$plugin = $allPlugins[ $i ];

							if ( ( 'active' === $status ) || ( 'inactive' === $status ) ) {
								if ( $plugin['active'] != ( ( 'active' === $status ) ? 1 : 0 ) ) {
										continue;
								}
							}

							if ( '' !== $keyword && ! stristr( $plugin['name'], $keyword ) ) {
								continue;
							}

							$plugin['websiteid']  = $website->id;
							$plugin['websiteurl'] = $website->url;
							$output->plugins[]    = $plugin;
						}
					}
				}
			}

			if ( '' !== $groups ) {
				foreach ( $groups as $k => $v ) {
					if ( MainWP_Utility::ctype_digit( $v ) ) {
						$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesByGroupId( $v ) );
						while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
							if ( '' !== $website->sync_errors ) {
								continue;
							}
							$allPlugins = json_decode( $website->plugins, true );
							$_count     = count( $allPlugins );
							for ( $i = 0; $i < $_count; $i ++ ) {
								$plugin = $allPlugins[ $i ];

								if ( ( 'active' === $status ) || ( 'inactive' === $status ) ) {
									if ( $plugin['active'] != ( ( 'active' === $status ) ? 1 : 0 ) ) {
										continue;
									}
								}
								if ( '' !== $keyword && ! stristr( $plugin['name'], $keyword ) ) {
									continue;
								}

								$plugin['websiteid']  = $website->id;
								$plugin['websiteurl'] = $website->url;
								$output->plugins[]    = $plugin;
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
						$website                    = MainWP_DB::Instance()->getWebsiteById( $v );
						$dbwebsites[ $website->id ] = MainWP_Utility::mapSite(
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
						$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesByGroupId( $v ) );
						while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
							if ( '' !== $website->sync_errors ) {
								continue;
							}
							$dbwebsites[ $website->id ] = MainWP_Utility::mapSite(
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
				'keyword' => $keyword,
			);

			if ( 'active' === $status || 'inactive' === $status ) {
				$post_data['status'] = $status;
				$post_data['filter'] = true;
			} else {
				$post_data['status'] = '';
				$post_data['filter'] = false;
			}

			MainWP_Utility::fetchUrlsAuthed( $dbwebsites, 'get_all_plugins', $post_data, array( self::get_class_name(), 'PluginsSearch_handler' ), $output );

			if ( 0 < count( $output->errors ) ) {
				foreach ( $output->errors as $siteid => $error ) {
					echo MainWP_Utility::getNiceURL( $dbwebsites[ $siteid ]->url ) . ': ' . $error . ' <br/>';
				}
				echo '<div class="ui hidden divider"></div>';
			}

			if ( count( $output->errors ) == count( $dbwebsites ) ) {
				return;
			}
		}

		MainWP_Cache::add_context(
			'Plugins', array(
				'keyword' => $keyword,
				'status'  => $status,
				'sites'   => ( '' !== $sites ) ? $sites : '',
				'groups'  => ( '' !== $groups ) ? $groups : '',
			)
		);

		ob_start();
		?>
		<?php esc_html_e( 'Bulk Actions: ', 'mainwp' ); ?>
		<div class="ui dropdown" id="mainwp-bulk-actions">
			<div class="text"><?php esc_html_e( 'Bulk Actions', 'mainwp' ); ?></div> <i class="dropdown icon"></i>
			<div class="menu">
		<div class="item" data-value="none"><?php esc_html_e( 'Bulk Actions', 'mainwp' ); ?></div>
		<?php if ( mainwp_current_user_can( 'dashboard', 'activate_deactivate_plugins' ) ) : ?>
			<?php if ( 'active' === $status || 'all' === $status ) : ?>
			<div class="item" data-value="deactivate"><?php esc_html_e( 'Deactivate', 'mainwp' ); ?></div>
			<?php endif; ?>
		<?php endif; ?>
		<?php if ( 'inactive' === $status || 'all' === $status ) : ?>
			<?php if ( mainwp_current_user_can( 'dashboard', 'activate_deactivate_plugins' ) ) : ?>
			<div class="item" data-value="activate"><?php esc_html_e( 'Activate', 'mainwp' ); ?></div>
			<?php endif; ?>
			<?php if ( mainwp_current_user_can( 'dashboard', 'delete_plugins' ) ) : ?>
			<div class="item" data-value="delete"><?php esc_html_e( 'Delete', 'mainwp' ); ?></div>
			<?php endif; ?>
		<?php endif; ?>
		<?php if ( mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
			<div class="item" data-value="ignore_updates"><?php esc_html_e( 'Ignore updates', 'mainwp' ); ?></div>
		<?php endif; ?>
		</div>
	</div>
	<button class="ui mini basic button" href="javascript:void(0)" id="mainwp-do-plugins-bulk-actions"><?php esc_html_e( 'Apply', 'mainwp' ); ?></button>
	<span id="mainwp_bulk_action_loading"><i class="ui active inline loader tiny"></i></span>
		<?php
		$bulkActions = ob_get_clean();
		ob_start();

		if ( 0 == count( $output->plugins ) ) {
			?>
			<div class="ui message yellow"><?php esc_html_e( 'No plugins found.', 'mainwp' ); ?></div>
			<?php
			$newOutput = ob_get_clean();

			$result = array(
				'result'       => $newOutput,
				'bulk_actions' => $bulkActions,
			);
			MainWP_Cache::add_result( 'Plugins', $result );

			return $result;
		}

		$sites              = array();
		$sitePlugins        = array();
		$plugins            = array();
		$muPlugins          = array();
		$pluginsVersion     = array();
		$pluginsName        = array();
		$pluginsMainWP      = array();
		$pluginsRealVersion = array();

		foreach ( $output->plugins as $plugin ) {
			$pn                            = esc_html( $plugin['name'] . '_' . $plugin['version'] );
			$sites[ $plugin['websiteid'] ] = esc_html( $plugin['websiteurl'] );
			$plugins[ $pn ]                = urlencode( $plugin['slug'] );
			$muPlugins[ $pn ]              = isset( $plugin['mu'] ) ? esc_html( $plugin['mu'] ) : '';
			$pluginsName[ $pn ]            = esc_html( $plugin['name'] );
			$pluginsVersion[ $pn ]         = esc_html( $plugin['name'] . ' ' . $plugin['version'] );
			$pluginsMainWP[ $pn ]          = isset( $plugin['mainwp'] ) ? esc_html( $plugin['mainwp'] ) : 'F';
			$pluginsRealVersion[ $pn ]     = urlencode( $plugin['version'] );

			if ( ! isset( $sitePlugins[ $plugin['websiteid'] ] ) || ! is_array( $sitePlugins[ $plugin['websiteid'] ] ) ) {
				$sitePlugins[ $plugin['websiteid'] ] = array();
			}

			$sitePlugins[ $plugin['websiteid'] ][ $pn ] = $plugin;
		}
		asort( $pluginsVersion );
		?>

	<table id="mainwp-manage-plugins-table" class="ui celled selectable compact single line definition table">
		<thead>
			<tr>
				<th></th>
				<?php foreach ( $pluginsVersion as $plugin_name => $plugin_title ) : ?>
					<?php
					$th_id = strtolower( $plugin_name );
					$th_id = preg_replace( '/[[:space:]]+/', '_', $th_id );
					?>
					<th id="<?php echo esc_html( $th_id ); ?>">
						<div class="ui checkbox">
							<input type="checkbox" value="<?php echo wp_strip_all_tags( $plugins[ $plugin_name ] ); ?>" id="<?php echo wp_strip_all_tags( $plugins[ $plugin_name ] . '-' . $pluginsRealVersion[ $plugin_name ] ); ?>" version="<?php echo wp_strip_all_tags( $pluginsRealVersion[ $plugin_name ] ); ?>" class="mainwp_plugin_check_all" />
							<label for="<?php echo wp_strip_all_tags( $plugins[ $plugin_name ] . '-' . $pluginsRealVersion[ $plugin_name ] ); ?>"><?php echo esc_html( $plugin_title ); ?></label>
						</div>
					</th>
				<?php endforeach; ?>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $sites as $site_id => $site_url ) : ?>
		<tr>
			<td>
			<input class="websiteId" type="hidden" name="id" value="<?php echo intval( $site_id ); ?>"/>
			<div class="ui checkbox">
				<input type="checkbox" value="" id="<?php echo esc_url( $site_url ); ?>" class="mainwp_plugins_site_check_all"/>
				<label><?php echo esc_html( $site_url ); ?></label>
			</div>
			</td>
			<?php foreach ( $pluginsVersion as $plugin_name => $plugin_title ) : ?>
			<td class="center aligned">
				<?php if ( isset( $sitePlugins[ $site_id ] ) && isset( $sitePlugins[ $site_id ][ $plugin_name ] ) && ( 0 == $muPlugins[ $plugin_name ] ) ) : ?>
					<?php if ( ! isset( $pluginsMainWP[ $plugin_name ] ) || 'F' === $pluginsMainWP[ $plugin_name ] ) : ?>
				<div class="ui checkbox">
					<input type="checkbox" value="<?php echo wp_strip_all_tags( $plugins[ $plugin_name ] ); ?>" name="<?php echo wp_strip_all_tags( $pluginsName[ $plugin_name ] ); ?>" class="mainwp-selected-plugin" version="<?php echo wp_strip_all_tags( $pluginsRealVersion[ $plugin_name ] ); ?>" />
				</div>
			<?php elseif ( isset( $pluginsMainWP[ $plugin_name ] ) && 'T' === $pluginsMainWP[ $plugin_name ] ) : ?>
				<div class="ui disabled checkbox"><input type="checkbox" disabled="disabled"><label></label></div>
				<?php endif; ?>
			<?php endif; ?>
			</td>
			<?php endforeach; ?>
		</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<style type="text/css">
	.DTFC_LeftBodyLiner { overflow-x: hidden; }
	</style>
	<script type="text/javascript">
	jQuery( document ).ready( function( $ ) {
		jQuery( '#mainwp-manage-plugins-table' ).DataTable( {
			"paging" : false,
			"colReorder" : true,
			"stateSave" :  true,
			"ordering" : true,
			"columnDefs": [ { "orderable": false, "targets": [ 0 ] } ],
			"scrollCollapse" : true,
			"scrollY" : 500,
			"scrollX" : true,
			"scroller" : true,
			"fixedColumns" : true,
		} );
		jQuery( '.mainwp-ui-page .ui.checkbox' ).checkbox();
	} );
	</script>

		<?php
		$newOutput = ob_get_clean();
		$result    = array(
			'result'       => $newOutput,
			'bulk_actions' => $bulkActions,
		);

		MainWP_Cache::add_result( 'Plugins', $result );
		return $result;
	}

	public static function PluginsSearch_handler( $data, $website, &$output ) {
		if ( 0 < preg_match( '/<mainwp>(.*)<\/mainwp>/', $data, $results ) ) {
			$result  = $results[1];
			$plugins = MainWP_Utility::get_child_response( base64_decode( $result ) );
			unset( $results );
			if ( isset( $plugins['error'] ) ) {
				$output->errors[ $website->id ] = MainWP_Error_Helper::get_error_message( new MainWP_Exception( $plugins['error'], $website->url ) );
				return;
			}

			foreach ( $plugins as $plugin ) {
				if ( ! isset( $plugin['name'] ) ) {
					continue;
				}
				$plugin['websiteid']  = $website->id;
				$plugin['websiteurl'] = $website->url;

				$output->plugins[] = $plugin;
			}
			unset( $plugins );
		} else {
			$output->errors[ $website->id ] = MainWP_Error_Helper::get_error_message( new MainWP_Exception( 'NOMAINWP', $website->url ) );
		}
	}

	public static function activatePlugins() {
		self::action( 'activate' );
	}

	public static function deactivatePlugins() {
		self::action( 'deactivate' );
	}

	public static function deletePlugins() {
		self::action( 'delete' );
	}

	public static function ignoreUpdates() {
		$websiteIdEnc = $_POST['websiteId'];
		$websiteId    = $websiteIdEnc;

		if ( ! MainWP_Utility::ctype_digit( $websiteId ) ) {
			die( wp_json_encode( array( 'error' => __( 'Invalid request. Please try again.', 'mainwp' ) ) ) );
		}

		$website = MainWP_DB::Instance()->getWebsiteById( $websiteId );

		if ( ! MainWP_Utility::can_edit_website( $website ) ) {
			die( wp_json_encode( array( 'error' => __( 'You are not allowed to edit this website.', 'mainwp' ) ) ) );
		}

		$plugins = $_POST['plugins'];
		$names   = $_POST['names'];

		$decodedIgnoredPlugins = json_decode( $website->ignored_plugins, true );

		if ( ! is_array( $decodedIgnoredPlugins ) ) {
			$decodedIgnoredPlugins = array();
		}

		if ( is_array( $plugins ) ) {
			$_count = count( $plugins );
			for ( $i = 0; $i < $_count; $i ++ ) {
				$slug = $plugins[ $i ];
				$name = $names[ $i ];
				if ( ! isset( $decodedIgnoredPlugins[ $slug ] ) ) {
					$decodedIgnoredPlugins[ $slug ] = urldecode( $name );
				}
			}
			MainWP_DB::Instance()->updateWebsiteValues( $website->id, array( 'ignored_plugins' => wp_json_encode( $decodedIgnoredPlugins ) ) );
		}

		die( wp_json_encode( array( 'result' => true ) ) );
	}

	public static function action( $pAction ) {
		$websiteIdEnc = $_POST['websiteId'];
		$websiteId    = $websiteIdEnc;

		if ( ! MainWP_Utility::ctype_digit( $websiteId ) ) {
			die( wp_json_encode( array( 'error' => __( 'Invalid request. Please try again.', 'mainwp' ) ) ) );
		}

		$website = MainWP_DB::Instance()->getWebsiteById( $websiteId );

		if ( ! MainWP_Utility::can_edit_website( $website ) ) {
			die( wp_json_encode( array( 'error' => __( 'You are not allowed to edit this website.', 'mainwp' ) ) ) );
		}

		try {
			$plugin      = implode( '||', $_POST['plugins'] );
			$plugin      = urldecode( $plugin );
			$information = MainWP_Utility::fetchUrlAuthed(
				$website, 'plugin_action', array(
					'action' => $pAction,
					'plugin' => $plugin,
				)
			);
		} catch ( MainWP_Exception $e ) {
			die( wp_json_encode( array( 'error' => MainWP_Error_Helper::get_error_message( $e ) ) ) );
		}

		if ( ! isset( $information['status'] ) || ( 'SUCCESS' !== $information['status'] ) ) {
			die( wp_json_encode( array( 'error' => __( 'Unexpected error. Please try again.', 'mainwp' ) ) ) );
		}

		die( wp_json_encode( array( 'result' => true ) ) );
	}

	public static function renderInstall() {
		self::renderHeader( 'Install' );
		self::renderPluginsTable();
		self::renderFooter( 'Install' );
	}

	public static function renderPluginsTable() {
		global $tab;

		if ( ! mainwp_current_user_can( 'dashboard', 'install_plugins' ) ) {
			mainwp_do_not_have_permissions( __( 'install plugins', 'mainwp' ) );
			return;
		}
		?>

	<div class="ui alt segment" id="mainwp-install-plugins">
		<div class="mainwp-main-content">
			<div class="mainwp-actions-bar">
				<div class="ui grid">
					<div class="ui two column row">
						<div class="column">
							<div id="mainwp-search-plugins-form" class="ui fluid search focus">
								<div class="ui icon fluid input">
									<input id="mainwp-search-plugins-form-field" class="fluid prompt" type="text" placeholder="<?php esc_attr_e( 'Search plugins...', 'mainwp' ); ?>" value="<?php echo isset( $_GET['s'] ) ? esc_html( $_GET['s'] ) : ''; ?>">
									<i class="search icon"></i>
								</div>
								<div class="results"></div>
							</div>
							<script type="text/javascript">
								jQuery( document ).ready(function () {
									jQuery( '#mainwp-search-plugins-form-field' ).on( 'keypress', function(e) {
										var search = jQuery( '#mainwp-search-plugins-form-field' ).val();
										var origin   = '<?php echo get_admin_url(); ?>';
										if ( 13 === e.which ) {
											location.href = origin + 'admin.php?page=PluginsInstall&tab=search&s=' + encodeURIComponent(search);
										}
									} );
								} );
							</script>
							<?php do_action( 'mainwp_install_plugins_actions_bar_left' ); ?>
						</div>
					<div class="right aligned column">
						<div class="ui buttons">
							<a href="#" id="MainWPInstallBulkNavSearch" class="ui button" ><?php esc_html_e( 'Install from WordPress.org', 'mainwp' ); ?></a>
							<div class="or"></div>
							<a href="#" id="MainWPInstallBulkNavUpload" class="ui button" ><?php esc_html_e( 'Upload .zip file', 'mainwp' ); ?></a>
						</div>
					<?php do_action( 'mainwp_install_plugins_actions_bar_right' ); ?>
					</div>
				</div>
			</div>
		</div>
		<div class="ui segment">
			<div id="mainwp-message-zone" class="ui message" style="display:none;"></div>
			<div class="mainwp-upload-plugin" style="display:none;">
				<?php MainWP_Install_Bulk::renderUpload( 'plugin' ); ?>
			</div>
			<div class="mainwp-browse-plugins">
				<form id="plugin-filter" method="post">
					<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
					<?php self::$pluginsTable->display(); ?>
				</form>
			</div>
			<?php
			MainWP_UI::render_modal_install_plugin_theme();
			?>
		</div>
	</div>

	<div class="mainwp-side-content mainwp-no-padding">
		<div class="mainwp-select-sites">
			<div class="ui header"><?php esc_html_e( 'Select Sites', 'mainwp' ); ?></div>
			<?php
				$selected_sites  = array();
				$selected_groups = array();

			if ( isset( $_GET['selected_sites'] ) ) {
				$selected_sites = explode( '-', $_GET['selected_sites'] );
				$selected_sites = array_map( 'intval', $selected_sites );
				$selected_sites = array_filter( $selected_sites );
			}

				MainWP_UI::select_sites_box( 'checkbox', true, true, 'mainwp_select_sites_box_left', '', $selected_sites, $selected_groups );
			?>
		</div>
		<div class="ui divider"></div>
		<div class="mainwp-search-options">
			<div class="ui header"><?php esc_html_e( 'Installation Options', 'mainwp' ); ?></div>
			<div class="ui form">
				<div class="field">
					<div class="ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'If enabled, the plugin will be automatically activated after the installation.', 'mainwp' ); ?>" data-position="left center" data-inverted="">
						<input type="checkbox" value="1" checked="checked" id="chk_activate_plugin" />
						<label for="chk_activate_plugin"><?php esc_html_e( 'Activate after installation', 'mainwp' ); ?></label>
					</div>
				</div>
				<div class="field">
					<div class="ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'If enabled and the plugin already installed on the sites, the already installed version will be overwritten.', 'mainwp' ); ?>" data-position="left center" data-inverted="">
						<input type="checkbox" value="2" checked="checked" id="chk_overwrite" />
						<label for="chk_overwrite"><?php esc_html_e( 'Overwrite existing version', 'mainwp' ); ?></label>
					</div>
				</div>
			</div>
		</div>
		<div class="ui divider"></div>
		<div class="mainwp-search-submit">
			<input type="button" value="<?php esc_attr_e( 'Complete Installation', 'mainwp' ); ?>" class="ui green big fluid button" id="mainwp_plugin_bulk_install_btn" bulk-action="install" name="bulk-install">
		</div>
	</div>
	<div class="ui clearing hidden divider"></div>
	</div>
		<?php
	}

	public static function renderAutoUpdate() {
		$cachedAUSearch = null;

		if ( isset( $_SESSION['MainWP_PluginsActiveStatus'] ) ) {
			$cachedAUSearch = $_SESSION['MainWP_PluginsActiveStatus'];
		}

		self::renderHeader( 'AutoUpdate' );

		if ( ! mainwp_current_user_can( 'dashboard', 'trust_untrust_updates' ) ) {
			mainwp_do_not_have_permissions( __( 'trust/untrust updates', 'mainwp' ) );
		} else {
			$snPluginAutomaticDailyUpdate = get_option( 'mainwp_pluginAutomaticDailyUpdate' );

			if ( false === $snPluginAutomaticDailyUpdate ) {
				$snPluginAutomaticDailyUpdate = get_option( 'mainwp_automaticDailyUpdate' );
				update_option( 'mainwp_pluginAutomaticDailyUpdate', $snPluginAutomaticDailyUpdate );
			}

			$update_time         = MainWP_Utility::getWebsitesAutomaticUpdateTime();
			$lastAutomaticUpdate = $update_time['last'];
			$nextAutomaticUpdate = $update_time['next'];
			?>
			<div class="ui alt segment" id="mainwp-plugin-auto-updates">
				<div class="mainwp-main-content">
					<div class="mainwp-actions-bar">
						<div class="ui grid">
							<div class="ui two column row">
								<div class="column">
									<div class="alignleft">
										<?php esc_html_e( 'Bulk Actions', 'mainwp' ); ?>
										<div id="mainwp-bulk-actions" name="bulk_action" class="ui dropdown">
											<div class="text"><?php esc_html_e( 'Select actions', 'mainwp' ); ?></div>
											<i class="dropdown icon"></i>
											<div class="menu">
												<div class="item" value="trust"><?php esc_html_e( 'Trust', 'mainwp' ); ?></div>
												<div class="item" value="untrust"><?php esc_html_e( 'Untrust', 'mainwp' ); ?></div>
											</div>
										</div>
										<input type="button" name="" id="mainwp-bulk-trust-plugins-action-apply" class="ui mini basic button" value="<?php esc_attr_e( 'Apply', 'mainwp' ); ?>"/>
									</div>
								</div>
								<div class="right aligned column"></div>
							</div>
						</div>
					</div>
					<?php if ( isset( $_GET['message'] ) && 'saved' === $_GET['message'] ) : ?>
						<div class="ui message green"><?php esc_html_e( 'Settings have been saved.', 'mainwp' ); ?></div>
					<?php endif; ?>
					<div id="mainwp-message-zone" class="ui message" style="display:none"></div>
					<div id="mainwp-auto-updates-plugins-content" class="ui segment">
						<div class="ui inverted dimmer">
							<div class="ui text loader"><?php esc_html_e( 'Loading plugins', 'mainwp' ); ?></div>
						</div>
						<div id="mainwp-auto-updates-plugins-table-wrapper">
						<?php
						if ( isset( $_SESSION['MainWP_PluginsActive'] ) ) {
							self::renderAllActiveTable( $_SESSION['MainWP_PluginsActive'] );
						}
						?>
					</div>
				</div>
			</div>
			<div class="mainwp-side-content mainwp-no-padding">
				<div class="mainwp-search-options">
					<div class="ui info message">
						<i class="close icon mainwp-notice-dismiss" notice-id="plugins-auto-updates"></i>
						<p><?php esc_html_e( 'The MainWP Auto Updates feature is a tool for your Dashboard to automatically update plugins that you trust to be updated without breaking your Child sites.', 'mainwp' ); ?></p>
						<p><?php esc_html_e( 'Only mark plugins as trusted if you are absolutely sure they can be automatically updated by your MainWP Dashboard without causing issues on the Child sites!', 'mainwp' ); ?></p>
						<p><strong><?php esc_html_e( 'Auto Updates a delayed approximately 24 hours from the update release. Ignored plugins can not be automatically updated.', 'mainwp' ); ?></strong></p>
					</div>
					<div class="ui header" style="margin-top:1rem"><?php esc_html_e( 'Plugin Status to Search', 'mainwp' ); ?></div>
					<div class="ui mini form">
						<div class="field">
							<select class="ui fluid dropdown" id="mainwp_au_plugin_status">
								<option value="all" <?php echo ( null != $cachedAUSearch && 'all' === $cachedAUSearch['plugin_status'] ) ? 'selected' : ''; ?>><?php esc_html_e( 'Active and Inactive', 'mainwp' ); ?></option>
								<option value="active" <?php echo ( null != $cachedAUSearch && 'active' === $cachedAUSearch['plugin_status'] ) ? 'selected' : ''; ?>><?php esc_html_e( 'Active', 'mainwp' ); ?></option>
								<option value="inactive" <?php echo ( null != $cachedAUSearch && 'inactive' === $cachedAUSearch['plugin_status'] ) ? 'selected' : ''; ?>><?php esc_html_e( 'Inactive', 'mainwp' ); ?></option>
							</select>
						</div>
					</div>
				</div>
				<div class="ui divider"></div>
				<div class="mainwp-search-options">
					<div class="ui header"><?php esc_html_e( 'Search Options', 'mainwp' ); ?></div>
					<div class="ui mini form">
						<div class="field">
							<select class="ui fluid dropdown" id="mainwp_au_plugin_trust_status">
								<option value="all" <?php echo ( null != $cachedAUSearch && 'all' === $cachedAUSearch['status'] ) ? 'selected' : ''; ?>><?php esc_html_e( 'Trusted, Not trusted and Ignored', 'mainwp' ); ?></option>
								<option value="trust" <?php echo ( null != $cachedAUSearch && 'trust' === $cachedAUSearch['status'] ) ? 'selected' : ''; ?>><?php esc_html_e( 'Trusted', 'mainwp' ); ?></option>
								<option value="untrust" <?php echo ( null != $cachedAUSearch && 'untrust' === $cachedAUSearch['status'] ) ? 'selected' : ''; ?>><?php esc_html_e( 'Not trusted', 'mainwp' ); ?></option>
								<option value="ignored" <?php echo ( null != $cachedAUSearch && 'ignored' === $cachedAUSearch['status'] ) ? 'selected' : ''; ?>><?php esc_html_e( 'Ignored', 'mainwp' ); ?></option>
							</select>
						</div>
						<div class="field">
							<div class="ui input fluid">
								<input type="text" placeholder="<?php esc_attr_e( 'Containing keyword', 'mainwp' ); ?>" id="mainwp_au_plugin_keyword" class="text" value="<?php echo ( null !== $cachedAUSearch ) ? $cachedAUSearch['keyword'] : ''; ?>">
							</div>
						</div>
					</div>
				</div>
				<div class="ui divider"></div>
				<div class="mainwp-search-submit">
					<a href="#" class="ui green big fluid button" id="mainwp_show_all_active_plugins"><?php esc_html_e( 'Show Plugins', 'mainwp' ); ?></a>
				</div>
			</div>
		</div>
			<?php
			MainWP_UI::render_modal_edit_notes( 'plugin' );
		}
		self::renderFooter( 'AutoUpdate' );
	}

	public static function renderAllActiveTable( $output = null ) {
		$keyword       = null;
		$search_status = 'all';

		if ( null == $output ) {
			$keyword              = isset( $_POST['keyword'] ) && ! empty( $_POST['keyword'] ) ? trim( $_POST['keyword'] ) : null;
			$search_status        = isset( $_POST['status'] ) ? $_POST['status'] : 'all';
			$search_plugin_status = isset( $_POST['plugin_status'] ) ? $_POST['plugin_status'] : 'all';

			$output          = new stdClass();
			$output->errors  = array();
			$output->plugins = array();

			if ( 1 == get_option( 'mainwp_optimize' ) ) {
				$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser() );
				while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
					$allPlugins = json_decode( $website->plugins, true );
					$_count     = count( $allPlugins );
					for ( $i = 0; $i < $_count; $i ++ ) {
						$plugin = $allPlugins[ $i ];
						if ( 'all' !== $search_plugin_status ) {
							if ( 1 == $plugin['active'] && 'active' !== $search_plugin_status ) {
								continue;
							} elseif ( 1 != $plugin['active'] && 'inactive' !== $search_plugin_status ) {
								continue;
							}
						}
						if ( '' !== $keyword && false === stristr( $plugin['name'], $keyword ) ) {
							continue;
						}
						$plugin['websiteid']  = $website->id;
						$plugin['websiteurl'] = $website->url;
						$output->plugins[]    = $plugin;
					}
				}
				MainWP_DB::free_result( $websites );
			} else {
				$dbwebsites = array();
				$websites   = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser() );
				while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
					$dbwebsites[ $website->id ] = MainWP_Utility::mapSite(
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

				$post_data = array( 'keyword' => $keyword );

				if ( 'active' === $search_plugin_status || 'inactive' === $search_plugin_status ) {
					$post_data['status'] = $search_plugin_status;
					$post_data['filter'] = true;
				} else {
					$post_data['status'] = '';
					$post_data['filter'] = false;
				}

				MainWP_Utility::fetchUrlsAuthed( $dbwebsites, 'get_all_plugins', $post_data, array( self::get_class_name(), 'PluginsSearch_handler' ), $output );

				if ( 0 < count( $output->errors ) ) {
					foreach ( $output->errors as $siteid => $error ) {
						echo MainWP_Utility::getNiceURL( $dbwebsites[ $siteid ]->url ) . ' - ' . $error . ' <br/>';

					}
					echo '<div class="ui hidden divider"></div>';

					if ( count( $output->errors ) == count( $dbwebsites ) ) {
						$_SESSION['MainWP_PluginsActive']       = $output;
						$_SESSION['MainWP_PluginsActiveStatus'] = array(
							'keyword'       => $keyword,
							'status'        => $search_status,
							'plugin_status' => $search_plugin_status,
						);
						return;
					}
				}
			}

			$_SESSION['MainWP_PluginsActive']       = $output;
			$_SESSION['MainWP_PluginsActiveStatus'] = array(
				'keyword'       => $keyword,
				'status'        => $search_status,
				'plugin_status' => $search_plugin_status,
			);
		} else {
			if ( isset( $_SESSION['MainWP_PluginsActiveStatus'] ) ) {
				$keyword              = $_SESSION['MainWP_PluginsActiveStatus']['keyword'];
				$search_status        = $_SESSION['MainWP_PluginsActiveStatus']['status'];
				$search_plugin_status = $_SESSION['MainWP_PluginsActiveStatus']['plugin_status'];
			}
		}

		if ( 'inactive' !== $search_plugin_status ) {
			if ( empty( $keyword ) || ( ! empty( $keyword ) && false !== stristr( 'MainWP Child', $keyword ) ) ) {
				$output->plugins[] = array(
					'slug'   => 'mainwp-child/mainwp-child.php',
					'name'   => 'MainWP Child',
					'active' => 1,
				);
			}
		}

		if ( 0 == count( $output->plugins ) ) {
			?>
			<div class="ui message yellow"><?php esc_html_e( 'No plugins found.', 'mainwp' ); ?></div>
			<?php
			return;
		}

		$plugins = array();
		foreach ( $output->plugins as $plugin ) {
			$plugins[ $plugin['slug'] ] = $plugin;
		}
		asort( $plugins );

		$userExtension         = MainWP_DB::Instance()->getUserExtension();
		$decodedIgnoredPlugins = json_decode( $userExtension->ignored_plugins, true );
		$trustedPlugins        = json_decode( $userExtension->trusted_plugins, true );

		if ( ! is_array( $trustedPlugins ) ) {
			$trustedPlugins = array();
		}
		$trustedPluginsNotes = json_decode( $userExtension->trusted_plugins_notes, true );
		if ( ! is_array( $trustedPluginsNotes ) ) {
			$trustedPluginsNotes = array();
		}
		?>
		<table class="ui single line table" id="mainwp-all-active-plugins-table">
			<thead>
				<tr>
					<th class="no-sort check-column collapsing"><span class="ui checkbox"><input id="cb-select-all-top" type="checkbox" /></span></th>
					<th class="collapsing"></th>
					<th><?php esc_html_e( 'Plugin', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
					<th class="collapsing"><?php esc_html_e( 'Trust Status', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Ignored Status', 'mainwp' ); ?></th>
					<th class="collapsing"><?php esc_html_e( 'Notes', 'mainwp' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $plugins as $slug => $plugin ) : ?>
					<?php
					$name = $plugin['name'];
					if ( ! empty( $search_status ) && 'all' !== $search_status ) {
						if ( 'trust' === $search_status && ! in_array( $slug, $trustedPlugins ) ) {
							continue;
						} elseif ( 'untrust' === $search_status && in_array( $slug, $trustedPlugins ) ) {
							continue;
						} elseif ( 'ignored' === $search_status && ! isset( $decodedIgnoredPlugins[ $slug ] ) ) {
							continue;
						}
					}
					$esc_note   = '';
					$strip_note = '';
					if ( isset( $trustedPluginsNotes[ $slug ] ) ) {
						$esc_note   = MainWP_Utility::esc_content( $trustedPluginsNotes[ $slug ] );
						$strip_note = wp_strip_all_tags( $esc_note );
					}
					?>
					<tr plugin-slug="<?php echo urlencode( $slug ); ?>" plugin-name="<?php echo wp_strip_all_tags( $name ); ?>">
						<td class="check-column"><span class="ui checkbox"><input type="checkbox" name="plugin[]" value="<?php echo urlencode( $slug ); ?>"></span></td>
						<td><?php echo ( isset( $decodedIgnoredPlugins[ $slug ] ) ) ? '<span data-tooltip="Ignored plugins will not be automatically updated." data-inverted=""><i class="info red circle icon" ></i></span>' : ''; ?></td>
						<td><a href="<?php echo admin_url() . 'plugin-install.php?tab=plugin-information&plugin=' . urlencode( dirname( $slug ) ) . '&TB_iframe=true&width=640&height=477'; ?>" target="_blank"><?php echo esc_html( $name ); ?></a></td>
						<td><?php echo ( 1 == $plugin['active'] ) ? esc_html_( 'Active', 'mainwp' ) : esc_html_( 'Inactive', 'mainwp' ); ?></td>
						<td><?php echo ( in_array( $slug, $trustedPlugins ) ) ? '<span class="ui mini green fluid center aligned label">' . esc_html_( 'Trusted', 'mainwp' ) . '</span>' : '<span class="ui mini red fluid center aligned label">' . esc_html_( 'Not Trusted', 'mainwp' ) . '</span>'; ?></td>
						<td><?php echo ( isset( $decodedIgnoredPlugins[ $slug ] ) ) ? '<span class="ui mini label">' . esc_html_( 'Ignored', 'mainwp' ) . '</span>' : ''; ?></td>
						<td class="collapsing center aligned">
						<?php if ( '' === $esc_note ) : ?>
							<a href="javascript:void(0)" class="mainwp-edit-plugin-note" ><i class="sticky note outline icon"></i></a>
						<?php else : ?>
							<a href="javascript:void(0)" class="mainwp-edit-plugin-note" data-tooltip="<?php echo substr( $strip_note, 0, 100 ); ?>" data-position="left center" data-inverted=""><i class="sticky green note icon"></i></a>
						<?php endif; ?>
							<span style="display: none" class="esc-content-note"><?php echo $esc_note; ?></span>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr>
					<th class="no-sort check-column"><span class="ui checkbox"><input id="cb-select-all-bottom" type="checkbox" /></span></th>
					<th></th>
					<th><?php esc_html_e( 'Plugin', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Trust Status', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Ignored Status', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Notes', 'mainwp' ); ?></th>
				</tr>
			</tfoot>
		</table>
		<script type="text/javascript">
		jQuery( document ).ready( function() {
			jQuery( '#mainwp-all-active-plugins-table' ).DataTable( {
				"colReorder" : true,
				"stateSave":  true,
				"paging":   false,
				"ordering": true,
				"columnDefs": [ { "orderable": false, "targets": [ 0, 1, 6 ] } ],
				"order": [ [ 2, "asc" ] ]
			} );
		} );
		</script>

		<script type="text/javascript">
			jQuery( document ).ready( function () {
				jQuery( '.mainwp-ui-page .ui.checkbox' ).checkbox();
			} );
		</script>
		<?php
	}

	public static function renderIgnore() {
		$websites              = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser() );
		$userExtension         = MainWP_DB::Instance()->getUserExtension();
		$decodedIgnoredPlugins = json_decode( $userExtension->ignored_plugins, true );
		$ignoredPlugins        = ( is_array( $decodedIgnoredPlugins ) && ( 0 < count( $decodedIgnoredPlugins ) ) );

		$cnt = 0;

		while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
			if ( $website->is_ignorePluginUpdates ) {
				continue;
			}

			$tmpDecodedIgnoredPlugins = json_decode( $website->ignored_plugins, true );

			if ( ! is_array( $tmpDecodedIgnoredPlugins ) || 0 == count( $tmpDecodedIgnoredPlugins ) ) {
				continue;
			}

				$cnt ++;
		}

		self::renderHeader( 'Ignore' );
		?>
		<div id="mainwp-ignored-plugins" class="ui segment">
			<h3 class="ui header">
				<?php esc_html_e( 'Globally Ignored Plugins' ); ?>
				<div class="sub header"><?php esc_html_e( 'These are plugins you have told your MainWP Dashboard to ignore updates on global level and not notify you about pending updates.', 'mainwp' ); ?></div>
			</h3>
			<table id="mainwp-globally-ignored-plugins" class="ui compact selectable table stackable">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Plugin', 'mainwp' ); ?></th>
						<th><?php esc_html_e( 'Plugin slug', 'mainwp' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody id="globally-ignored-plugins-list">
					<?php if ( $ignoredPlugins ) : ?>
						<?php foreach ( $decodedIgnoredPlugins as $ignoredPlugin => $ignoredPluginName ) : ?>
							<tr plugin-slug="<?php echo urlencode( $ignoredPlugin ); ?>">
								<td><a href="<?php echo admin_url() . 'plugin-install.php?tab=plugin-information&plugin=' . urlencode( dirname( $ignoredPlugin ) ) . '&TB_iframe=true&width=640&height=477'; ?>" target="_blank"><?php echo esc_html( $ignoredPluginName ); ?></a></td>
								<td><?php echo esc_html( $ignoredPlugin ); ?></td>
								<td class="right aligned">
									<?php if ( mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
										<a href="#" class="ui mini button" onClick="return updatesoverview_plugins_unignore_globally( '<?php echo urlencode( $ignoredPlugin ); ?>' )"><?php esc_html_e( 'Unignore', 'mainwp' ); ?></a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php else : ?>
						<tr>
							<td colspan="999"><?php esc_html_e( 'No ignored plugins', 'mainwp' ); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
				<?php if ( mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
					<?php if ( $ignoredPlugins ) : ?>
						<tfoot class="full-width">
							<tr>
								<th colspan="999">
									<a class="ui right floated small green labeled icon button" onClick="return updatesoverview_plugins_unignore_globally_all();" id="mainwp-unignore-globally-all">
										<i class="check icon"></i> <?php esc_html_e( 'Unignore All', 'mainwp' ); ?>
									</a>
								</th>
							</tr>
						</tfoot>
					<?php endif; ?>
				<?php endif; ?>
			</table>
			<div class="ui hidden divider"></div>
			<h3 class="ui header">
				<?php esc_html_e( 'Per Site Ignored Plugins' ); ?>
				<div class="sub header"><?php esc_html_e( 'These are plugins you have told your MainWP Dashboard to ignore updates per site level and not notify you about pending updates.', 'mainwp' ); ?></div>
			</h3>
			<table id="mainwp-per-site-ignored-plugins" class="ui compact selectable table stackable">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Site', 'mainwp' ); ?></th>
						<th><?php esc_html_e( 'Plugin', 'mainwp' ); ?></th>
						<th><?php esc_html_e( 'Plugin slug', 'mainwp' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody id="ignored-plugins-list">
					<?php if ( 0 < $cnt ) : ?>
						<?php
						MainWP_DB::data_seek( $websites, 0 );

						while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
							if ( $website->is_ignorePluginUpdates ) {
								continue;
							}

							$decodedIgnoredPlugins = json_decode( $website->ignored_plugins, true );
							if ( ! is_array( $decodedIgnoredPlugins ) || 0 == count( $decodedIgnoredPlugins ) ) {
								continue;
							}
							$first = true;

							foreach ( $decodedIgnoredPlugins as $ignoredPlugin => $ignoredPluginName ) {
								?>
							<tr site-id="<?php echo intval($website->id); ?>" plugin-slug="<?php echo urlencode( $ignoredPlugin ); ?>">
								<?php if ( $first ) : ?>
									<td><div><a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"><?php echo stripslashes( $website->name ); ?></a></div></td>
									<?php $first = false; ?>
								<?php else : ?>
									<td><div style="display:none;"><a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"><?php echo stripslashes( $website->name ); ?></a></div></td>
								<?php endif; ?>
								<td><a href="<?php echo admin_url() . 'plugin-install.php?tab=plugin-information&plugin=' . urlencode( dirname( $ignoredPlugin ) ) . '&TB_iframe=true&width=640&height=477'; ?>" target="_blank"><?php echo esc_html( $ignoredPluginName ); ?></a></td>
								<td><?php echo esc_html( $ignoredPlugin ); ?></td>
								<?php if ( mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
									<td class="right aligned"><a href="#" class="ui mini button" onClick="return updatesoverview_plugins_unignore_detail( '<?php echo urlencode( $ignoredPlugin ); ?>', <?php echo esc_attr( $website->id ); ?> )"> <?php esc_html_e( 'Unignore', 'mainwp' ); ?></a></td>
								<?php endif; ?>
							</tr>
								<?php
							}
						}

						MainWP_DB::free_result( $websites );
						?>
					<?php else : ?>
						<tr><td colspan="999"><?php esc_html_e( 'No ignored plugins', 'mainwp' ); ?></td></tr>
					<?php endif; ?>
				</tbody>
				<?php if ( mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
					<?php if ( 0 < $cnt ) : ?>
						<tfoot class="full-width">
							<tr>
								<th colspan="999">
									<a class="ui right floated small green labeled icon button" onClick="return updatesoverview_plugins_unignore_detail_all();" id="mainwp-unignore-detail-all">
										<i class="check icon"></i> <?php esc_html_e( 'Unignore All', 'mainwp' ); ?>
									</a>
								</th>
							</tr>
						</tfoot>
					<?php endif; ?>
				<?php endif; ?>
			</table>
		</div>
		<?php
		self::renderFooter( 'Ignore' );
	}

	public static function renderIgnoredAbandoned() {
		$websites              = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser() );
		$userExtension         = MainWP_DB::Instance()->getUserExtension();
		$decodedIgnoredPlugins = json_decode( $userExtension->dismissed_plugins, true );
		$ignoredPlugins        = ( is_array( $decodedIgnoredPlugins ) && ( 0 < count( $decodedIgnoredPlugins ) ) );
		$cnt                   = 0;
		while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
			$tmpDecodedDismissedPlugins = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'plugins_outdate_dismissed' ), true );
			if ( ! is_array( $tmpDecodedDismissedPlugins ) || 0 == count( $tmpDecodedDismissedPlugins ) ) {
				continue;
			}
			$cnt ++;
		}

		self::renderHeader( 'IgnoreAbandoned' );
		?>
		<div id="mainwp-ignored-abandoned-plugins" class="ui segment">
			<h3 class="ui header">
				<?php esc_html_e( 'Globally Ignored Abandoned Plugins' ); ?>
				<div class="sub header"><?php esc_html_e( 'These are plugins you have told your MainWP Dashboard to ignore on global level even though they have passed your Abandoned Plugin Tolerance date', 'mainwp' ); ?></div>
			</h3>
			<table id="mainwp-globally-ignored-abandoned-plugins" class="ui compact selectable table stackable">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Plugin', 'mainwp' ); ?></th>
						<th><?php esc_html_e( 'Plugin slug', 'mainwp' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody id="ignored-abandoned-plugins-list">
					<?php if ( $ignoredPlugins ) : ?>
						<?php foreach ( $decodedIgnoredPlugins as $ignoredPlugin => $ignoredPluginName ) : ?>
							<tr plugin-slug="<?php echo urlencode( $ignoredPlugin ); ?>">
								<td><a href="<?php echo admin_url() . 'plugin-install.php?tab=plugin-information&plugin=' . urlencode( dirname( $ignoredPlugin ) ) . '&TB_iframe=true&width=640&height=477'; ?>" target="_blank"><?php echo esc_html( $ignoredPluginName ); ?></a></td>
								<td><?php echo esc_html( $ignoredPlugin ); ?></td>
								<td class="right aligned">
									<?php if ( mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
										<a href="#" class="ui mini button" onClick="return updatesoverview_plugins_abandoned_unignore_globally( '<?php echo urlencode( $ignoredPlugin ); ?>' )"><?php esc_html_e( 'Unignore', 'mainwp' ); ?></a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php else : ?>
						<tr>
							<td colspan="999"><?php esc_html_e( 'No ignored abandoned plugins.', 'mainwp' ); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
				<?php if ( mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
					<?php if ( $ignoredPlugins ) : ?>
						<tfoot class="full-width">
							<tr>
								<th colspan="999">
									<a class="ui right floated small green labeled icon button" onClick="return updatesoverview_plugins_abandoned_unignore_globally_all();" id="mainwp-unignore-globally-all">
										<i class="check icon"></i> <?php esc_html_e( 'Unignore All', 'mainwp' ); ?>
									</a>
								</th>
							</tr>
						</tfoot>
					<?php endif; ?>
				<?php endif; ?>
			</table>
			<div class="ui hidden divider"></div>
			<h3 class="ui header">
				<?php esc_html_e( 'Per Site Ignored Abandoned Plugins' ); ?>
				<div class="sub header"><?php esc_html_e( 'These are plugins you have told your MainWP Dashboard to ignore per site level even though they have passed your Abandoned Plugin Tolerance date', 'mainwp' ); ?></div>
			</h3>
			<table id="mainwp-per-site-ignored-abandoned-plugins" class="ui compact selectable table stackable">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Site', 'mainwp' ); ?></th>
						<th><?php esc_html_e( 'Plugin', 'mainwp' ); ?></th>
						<th><?php esc_html_e( 'Plugin slug', 'mainwp' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody id="ignored-abandoned-plugins-list">
					<?php if ( 0 < $cnt ) : ?>
						<?php
						MainWP_DB::data_seek( $websites, 0 );

						while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
							$decodedIgnoredPlugins = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'plugins_outdate_dismissed' ), true );
							if ( ! is_array( $decodedIgnoredPlugins ) || 0 == count( $decodedIgnoredPlugins ) ) {
								continue;
							}
							$first = true;
							foreach ( $decodedIgnoredPlugins as $ignoredPlugin => $ignoredPluginName ) {
								?>
						<tr site-id="<?php echo esc_attr( $website->id ); ?>" plugin-slug="<?php echo urlencode( $ignoredPlugin ); ?>">
								<?php if ( $first ) : ?>
								<td>
									<a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"><?php echo stripslashes( $website->name ); ?></a>
								</td>
									<?php $first = false; ?>
							<?php else : ?>
								<td><div style="display:none;"><a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"><?php echo stripslashes( $website->name ); ?></a></div></td>
							<?php endif; ?>
							<td><a href="<?php echo admin_url() . 'plugin-install.php?tab=plugin-information&plugin=' . urlencode( dirname( $ignoredPlugin ) ) . '&TB_iframe=true&width=640&height=477'; ?>" target="_blank"><?php echo esc_html( $ignoredPluginName ); ?></a></td>
							<td><?php echo esc_html( $ignoredPlugin ); ?></td>
							<td class="right aligned"><a href="#" class="ui mini button" onClick="return updatesoverview_plugins_unignore_abandoned_detail( '<?php echo urlencode( $ignoredPlugin ); ?>', <?php echo esc_attr( $website->id ); ?> )"> <?php esc_html_e( 'Unignore', 'mainwp' ); ?></a></td>
						</tr>
								<?php
							}
						}

						MainWP_DB::free_result( $websites );

		else :
			?>
			<tr>
				<td colspan="999"><?php esc_html_e( 'No ignored abandoned plugins', 'mainwp' ); ?></td>
			</tr>
		<?php endif; ?>
		</tbody>
		<?php if ( mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
			<?php if ( 0 < $cnt ) : ?>
				<tfoot class="full-width">
					<tr>
						<th colspan="999">
							<a class="ui right floated small green labeled icon button" onClick="return updatesoverview_plugins_unignore_abandoned_detail_all();" id="mainwp-unignore-detail-all">
								<i class="check icon"></i> <?php esc_html_e( 'Unignore All', 'mainwp' ); ?>
							</a>
						</th>
					</tr>
				</tfoot>
			<?php endif; ?>
		<?php endif; ?>
		</table>
	</div>
		<?php
		self::renderFooter( 'IgnoreAbandoned' );
	}

	public static function trustPost() {
		$userExtension  = MainWP_DB::Instance()->getUserExtension();
		$trustedPlugins = json_decode( $userExtension->trusted_plugins, true );
		if ( ! is_array( $trustedPlugins ) ) {
			$trustedPlugins = array();
		}
		$action = $_POST['do'];
		$slugs  = $_POST['slugs'];
		if ( ! is_array( $slugs ) ) {
			return;
		}
		if ( 'trust' !== $action && 'untrust' !== $action ) {
			return;
		}
		if ( 'trust' === $action ) {
			foreach ( $slugs as $slug ) {
				$idx = array_search( urldecode( $slug ), $trustedPlugins );
				if ( false === $idx ) {
					$trustedPlugins[] = urldecode( $slug );
				}
			}
		} elseif ( 'untrust' === $action ) {
			foreach ( $slugs as $slug ) {
				if ( in_array( urldecode( $slug ), $trustedPlugins ) ) {
					$trustedPlugins = array_diff( $trustedPlugins, array( urldecode( $slug ) ) );
				}
			}
		}
		$userExtension->trusted_plugins = wp_json_encode( $trustedPlugins );
		MainWP_DB::Instance()->updateUserExtension( $userExtension );
	}

	public static function trustPlugin( $slug ) {
		$userExtension  = MainWP_DB::Instance()->getUserExtension();
		$trustedPlugins = json_decode( $userExtension->trusted_plugins, true );
		if ( ! is_array( $trustedPlugins ) ) {
			$trustedPlugins = array();
		}
		$idx = array_search( urldecode( $slug ), $trustedPlugins );
		if ( false === $idx ) {
			$trustedPlugins[] = urldecode( $slug );
		}
		$userExtension->trusted_plugins = wp_json_encode( $trustedPlugins );
		MainWP_DB::Instance()->updateUserExtension( $userExtension );
	}

	public static function checkAutoUpdatePlugin( $slug ) {
		if ( 1 != get_option( 'mainwp_automaticDailyUpdate' ) ) {
			return false;
		}
			$userExtension  = MainWP_DB::Instance()->getUserExtension();
			$trustedPlugins = json_decode( $userExtension->trusted_plugins, true );
		if ( is_array( $trustedPlugins ) && in_array( $slug, $trustedPlugins ) ) {
			return true;
		}
			return false;
	}

	public static function saveTrustedPluginNote() {
		$slug                = urldecode( $_POST['slug'] );
		$note                = stripslashes( $_POST['note'] );
		$esc_note            = MainWP_Utility::esc_content( $note );
		$userExtension       = MainWP_DB::Instance()->getUserExtension();
		$trustedPluginsNotes = json_decode( $userExtension->trusted_plugins_notes, true );
		if ( ! is_array( $trustedPluginsNotes ) ) {
			$trustedPluginsNotes = array();
		}
		$trustedPluginsNotes[ $slug ]         = $esc_note;
		$userExtension->trusted_plugins_notes = wp_json_encode( $trustedPluginsNotes );
		MainWP_DB::Instance()->updateUserExtension( $userExtension );
	}

	/*
	 * Hook the section help content to the Help Sidebar element
	 */

	public static function mainwp_help_content() {
		if ( isset( $_GET['page'] ) && ( 'PluginsManage' === $_GET['page'] || 'PluginsInstall' === $_GET['page'] || 'PluginsAutoUpdate' === $_GET['page'] || 'PluginsIgnore' === $_GET['page'] || 'PluginsIgnoredAbandoned' === $_GET['page'] ) ) {
			?>
			<p><?php esc_html_e( 'If you need help with managing plugins, please review following help documents', 'mainwp' ); ?></p>
			<div class="ui relaxed bulleted list">
				<div class="item"><a href="https://mainwp.com/help/docs/managing-plugins-with-mainwp/" target="_blank">Managing Plugins with MainWP</a></div>
				<div class="item"><a href="https://mainwp.com/help/docs/managing-plugins-with-mainwp/install-plugins/" target="_blank">Install Plugins</a></div>
				<div class="item"><a href="https://mainwp.com/help/docs/managing-plugins-with-mainwp/activate-plugins/" target="_blank">Activate Plugins</a></div>
				<div class="item"><a href="https://mainwp.com/help/docs/managing-plugins-with-mainwp/delete-plugins/" target="_blank">Delete Plugins</a></div>
				<div class="item"><a href="https://mainwp.com/help/docs/managing-plugins-with-mainwp/abandoned-plugins/" target="_blank">Abandoned Plugins</a></div>
				<div class="item"><a href="https://mainwp.com/help/docs/managing-plugins-with-mainwp/update-plugins/" target="_blank">Update Plugins</a></div>
				<div class="item"><a href="https://mainwp.com/help/docs/managing-plugins-with-mainwp/plugins-auto-updates/" target="_blank">Plugins Auto Updates</a></div>
				<div class="item"><a href="https://mainwp.com/help/docs/managing-plugins-with-mainwp/ignore-plugin-updates/" target="_blank">Ignore Plugin Updates</a></div>
			</div>
			<?php
		}
	}

}
