<?php
/**
 * This File handles the Themes SubPage.
 * MainWP Themes Page
 *
 * This page is used to Manage Themes on Child Sites
 *
 * @package MainWP/Themes
 */
namespace MainWP\Dashboard;

/**
 * MainWP Themes Page
 *
 * @uses MainWP_Install_Bulk
 */
class MainWP_Themes {

	/**
	 * Get Class Name
	 *
	 * @return string __CLASS__
	 */
	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * @var $subPages Array of SubPages.
	 */
	public static $subPages;

	/**
	 * Fire on the initialization of WordPress.
	 */
	public static function init() {
		/**
		 * This hook allows you to render the Themes page header via the 'mainwp-pageheader-themes' action.
		 *
		 * @link http://codex.mainwp.com/#mainwp-pageheader-themes
		 *
		 * This hook is normally used in the same context of 'mainwp-getsubpages-themes'
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-themes
		 *
		 * @see \MainWP_Themes::render_header
		 */
		add_action( 'mainwp-pageheader-themes', array( self::get_class_name(), 'render_header' ) );

		/**
		 * This hook allows you to render the Themes page footer via the 'mainwp-pagefooter-themes' action.
		 *
		 * @link http://codex.mainwp.com/#mainwp-pagefooter-themes
		 *
		 * This hook is normally used in the same context of 'mainwp-getsubpages-themes'
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-themes
		 *
		 * @see \MainWP_Themes::render_footer
		 */
		add_action( 'mainwp-pagefooter-themes', array( self::get_class_name(), 'render_footer' ) );

		add_action( 'mainwp_help_sidebar_content', array( self::get_class_name(), 'mainwp_help_content' ) );
	}

	/**
	 * Initiate the MainWP Themes SubMenu Page.
	 */
	public static function init_menu() {

		$_page = add_submenu_page(
			'mainwp_tab',
			__( 'Themes', 'mainwp' ),
			'<span id="mainwp-Themes">' . __( 'Themes', 'mainwp' ) . '</span>',
			'read',
			'ThemesManage',
			array(
				self::get_class_name(),
				'render',
			)
		);

		add_submenu_page(
			'mainwp_tab',
			__( 'Themes', 'mainwp' ),
			'<div class="mainwp-hidden">' . __( 'Install', 'mainwp' ) . '</div>',
			'read',
			'ThemesInstall',
			array(
				self::get_class_name(),
				'render_install',
			)
		);
		add_submenu_page(
			'mainwp_tab',
			__( 'Themes', 'mainwp' ),
			'<div class="mainwp-hidden">' . __( 'Auto Updates', 'mainwp' ) . '</div>',
			'read',
			'ThemesAutoUpdate',
			array(
				self::get_class_name(),
				'render_auto_update',
			)
		);
		add_submenu_page(
			'mainwp_tab',
			__( 'Themes', 'mainwp' ),
			'<div class="mainwp-hidden">' . __( 'Ignored Updates', 'mainwp' ) . '</div>',
			'read',
			'ThemesIgnore',
			array(
				self::get_class_name(),
				'render_ignore',
			)
		);
		add_submenu_page(
			'mainwp_tab',
			__( 'Themes', 'mainwp' ),
			'<div class="mainwp-hidden">' . __( 'Ignored Abandoned', 'mainwp' ) . '</div>',
			'read',
			'ThemesIgnoredAbandoned',
			array(
				self::get_class_name(),
				'render_ignored_abandoned',
			)
		);

		/**
		 * This hook allows you to add extra sub pages to the Themes page via the 'mainwp-getsubpages-themes' filter.
		 *
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-themes
		 */
		self::$subPages = apply_filters( 'mainwp-getsubpages-themes', array() );
		if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
			foreach ( self::$subPages as $subPage ) {
				if ( MainWP_Menu::is_disable_menu_item( 3, 'Themes' . $subPage['slug'] ) ) {
					continue;
				}
				add_submenu_page( 'mainwp_tab', $subPage['title'], '<div class="mainwp-hidden">' . $subPage['title'] . '</div>', 'read', 'Themes' . $subPage['slug'], $subPage['callback'] );
			}
		}
		self::init_left_menu( self::$subPages );
	}

	/**
	 * Themes Subpage Menu HTML Content.
	 */
	public static function init_subpages_menu() {
		?>
		<div id="menu-mainwp-Themes" class="mainwp-submenu-wrapper">
			<div class="wp-submenu sub-open">
				<div class="mainwp_boxout">
					<div class="mainwp_boxoutin"></div>
					<a href="<?php echo admin_url( 'admin.php?page=ThemesManage' ); ?>" class="mainwp-submenu">
						<?php esc_html_e( 'Manage Themes', 'mainwp' ); ?>
					</a>
					<?php if ( mainwp_current_user_can( 'dashboard', 'install_themes' ) ) { ?>
						<?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ThemesInstall' ) ) { ?>
						<a href="<?php echo admin_url( 'admin.php?page=ThemesInstall' ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Install', 'mainwp' ); ?></a>
						<?php } ?>
					<?php } ?>
					<?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ThemesAutoUpdate' ) ) { ?>
					<a href="<?php echo admin_url( 'admin.php?page=ThemesAutoUpdate' ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Auto Updates', 'mainwp' ); ?></a>
					<?php } ?>
					<?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ThemesIgnore' ) ) { ?>
					<a href="<?php echo admin_url( 'admin.php?page=ThemesIgnore' ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Ignored Updates', 'mainwp' ); ?></a>
					<?php } ?>
					<?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ThemesIgnoredAbandoned' ) ) { ?>
					<a href="<?php echo admin_url( 'admin.php?page=ThemesIgnoredAbandoned' ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Ignored Abandoned', 'mainwp' ); ?></a>
					<?php } ?>
					<?php
					if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
						foreach ( self::$subPages as $subPage ) {
							if ( MainWP_Menu::is_disable_menu_item( 3, 'Themes' . $subPage['slug'] ) ) {
								continue;
							}
							?>
							<a href="<?php echo admin_url( 'admin.php?page=Themes' . $subPage['slug'] ); ?>" class="mainwp-submenu"><?php echo esc_html( $subPage['title'] ); ?></a>
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
	 * Build arrays for each SubPage Menu Block.
	 *
	 * @param array $subPages Array of SubPages.
	 */
	public static function init_left_menu( $subPages = array() ) {
		MainWP_Menu::add_left_menu(
			array(
				'title'      => __( 'Themes', 'mainwp' ),
				'parent_key' => 'mainwp_tab',
				'slug'       => 'ThemesManage',
				'href'       => 'admin.php?page=ThemesManage',
				'icon'       => '<i class="paint brush icon"></i>',
			),
			1
		);

		$init_sub_subleftmenu = array(
			array(
				'title'                => __( 'Manage Themes', 'mainwp' ),
				'parent_key'           => 'ThemesManage',
				'href'                 => 'admin.php?page=ThemesManage',
				'slug'                 => 'ThemesManage',
				'right'                => '',
			),
			array(
				'title'                => __( 'Install', 'mainwp' ),
				'parent_key'           => 'ThemesManage',
				'href'                 => 'admin.php?page=ThemesInstall',
				'slug'                 => 'ThemesInstall',
				'right'                => 'install_themes',
			),
			array(
				'title'                => __( 'Auto Updates', 'mainwp' ),
				'parent_key'           => 'ThemesManage',
				'href'                 => 'admin.php?page=ThemesAutoUpdate',
				'slug'                 => 'ThemesAutoUpdate',
				'right'                => '',
			),
			array(
				'title'                => __( 'Ignored Updates', 'mainwp' ),
				'parent_key'           => 'ThemesManage',
				'href'                 => 'admin.php?page=ThemesIgnore',
				'slug'                 => 'ThemesIgnore',
				'right'                => '',
			),
			array(
				'title'                => __( 'Ignored Abandoned', 'mainwp' ),
				'parent_key'           => 'ThemesManage',
				'href'                 => 'admin.php?page=ThemesIgnoredAbandoned',
				'slug'                 => 'ThemesIgnoredAbandoned',
				'right'                => '',
			),
		);

		MainWP_Menu::init_subpages_left_menu( $subPages, $init_sub_subleftmenu, 'ThemesManage', 'Themes' );

		foreach ( $init_sub_subleftmenu as $item ) {
			if ( MainWP_Menu::is_disable_menu_item( 3, $item['slug'] ) ) {
				continue;
			}
			MainWP_Menu::add_left_menu( $item, 2 );
		}
	}

	/**
	 * Render Themes SubPage Header.
	 *
	 * @param string $shownPage The page slug shown at this moment
	 */
	public static function render_header( $shownPage = '' ) {
		$params = array( 'title' => __( 'Themes', 'mainwp' ) );

		MainWP_UI::render_top_header( $params );

			$renderItems = array();

			$renderItems[] = array(
				'title'  => __( 'Manage Themes', 'mainwp' ),
				'href'   => 'admin.php?page=ThemesManage',
				'active' => ( 'Manage' === $shownPage ) ? true : false,
			);

			if ( mainwp_current_user_can( 'dashboard', 'install_themes' ) ) {
				$renderItems[] = array(
					'title'  => __( 'Install', 'mainwp' ),
					'href'   => 'admin.php?page=ThemesInstall',
					'active' => ( 'Install' === $shownPage ) ? true : false,
				);
			}

			if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ThemesAutoUpdate' ) ) {
				$renderItems[] = array(
					'title'  => __( 'Auto Updates', 'mainwp' ),
					'href'   => 'admin.php?page=ThemesAutoUpdate',
					'active' => ( 'AutoUpdate' === $shownPage ) ? true : false,
				);
			}

			if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ThemesIgnore' ) ) {
				$renderItems[] = array(
					'title'  => __( 'Ignored Updates', 'mainwp' ),
					'href'   => 'admin.php?page=ThemesIgnore',
					'active' => ( 'Ignore' === $shownPage ) ? true : false,
				);
			}

			if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ThemesIgnoredAbandoned' ) ) {
				$renderItems[] = array(
					'title'  => __( 'Ignored Abandoned', 'mainwp' ),
					'href'   => 'admin.php?page=ThemesIgnoredAbandoned',
					'active' => ( 'IgnoreAbandoned' === $shownPage ) ? true : false,
				);
			}

			if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
				foreach ( self::$subPages as $subPage ) {
					if ( MainWP_Menu::is_disable_menu_item( 3, 'Themes' . $subPage['slug'] ) ) {
						continue;
					}
					$item           = array();
					$item['title']  = $subPage['title'];
					$item['href']   = 'admin.php?page=Themes' . $subPage['slug'];
					$item['active'] = ( $subPage['slug'] == $shownPage ) ? true : false;
					$renderItems[]  = $item;
				}
			}
			MainWP_UI::render_page_navigation( $renderItems );
	}

	/**
	 * Close the page container.
	 *
	 * @param string $shownPage The page slug shown at this moment
	 */
	public static function render_footer( $shownPage ) {
		echo '</div>';
	}

	/**
	 * Render the Theme SubPage content.
	 */
	public static function render() {
		$cachedSearch    = MainWP_Cache::get_cached_context( 'Themes' );
		$selected_sites  = array();
		$selected_groups = array();

		if ( null != $cachedSearch ) {
			if ( is_array( $cachedSearch['sites'] ) ) {
				$selected_sites = $cachedSearch['sites'];
			} elseif ( is_array( $cachedSearch['groups'] ) ) {
				$selected_groups = $cachedSearch['groups'];
			}
		}

		$cachedResult = MainWP_Cache::get_cached_result( 'Themes' );

		self::render_header( 'Manage' );
		?>

		<div id="mainwp-manage-themes" class="ui alt segment">
			<div class="mainwp-main-content">
				<div class="mainwp-actions-bar">
					<div class="ui grid">
						<div class="ui two column row">
							<div class="column">
								<div id="mainwp-themes-bulk-actions-wapper">
								<?php
								if ( is_array( $cachedResult ) && isset( $cachedResult['bulk_actions'] ) ) {
									echo $cachedResult['bulk_actions'];
								} else {
									MainWP_UI::render_empty_bulk_actions();
								}
								?>
								</div>
								<?php do_action( 'mainwp_themes_actions_bar_left' ); ?>
							</div>
							<div class="right aligned column">
								<button id="mainwp-install-themes-to-selected-sites" class="ui green basic button" style="display: none"><?php esc_html_e( 'Install to Selected Site(s)', 'mainwp' ); ?></button>
								<?php do_action( 'mainwp_themes_actions_bar_right' ); ?>
							</div>
						</div>
					</div>
				</div>
				<div class="ui segment" id="mainwp_themes_wrap_table">
					<div id="mainwp-message-zone" class="ui message" style="display:none"></div>
					<div id="mainwp-loading-themes-row" class="ui active inverted dimmer" style="display:none">
						<div class="ui large text loader"><?php esc_html_e( 'Loading Themes...', 'mainwp' ); ?></div>
					</div>
					<div id="mainwp-themes-main-content" <?php echo ( null != $cachedSearch ) ? 'style="display: block;"' : ''; ?> >
						<div id="mainwp-themes-content">
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
						<i class="close icon mainwp-notice-dismiss" notice-id="themes-manage-info"></i>
						<?php esc_html_e( 'A theme needs to be Inactive in order for it to be Activated or Deleted.', 'mainwp' ); ?>
					</div>
					<div class="ui mini form">
						<div class="field">
							<select multiple="" class="ui fluid dropdown" id="mainwp_themes_search_by_status">
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
					<?php self::render_search_options(); ?>
				</div>
				<div class="ui divider"></div>
				<div class="mainwp-search-submit">
					<input type="button" name="mainwp_show_themes" id="mainwp_show_themes" class="ui green big fluid button" value="<?php esc_attr_e( 'Show Themes', 'mainwp' ); ?>"/>
				</div>
			</div>
			<div style="clear:both"></div>
		</div>
		<?php
		self::render_footer( 'Manage' );
	}

	/**
	 * Render the Search Options Meta Box.
	 */
	public static function render_search_options() {
		$cachedSearch = MainWP_Cache::get_cached_context( 'Themes' );
		$statuses     = isset( $cachedSearch['status'] ) ? $cachedSearch['status'] : array();
		?>

		<div class="ui mini form">
			<div class="field">
				<div class="ui input fluid">
					<input type="text" placeholder="<?php esc_attr_e( 'Containing keyword', 'mainwp' ); ?>" id="mainwp_theme_search_by_keyword" size="50" class="text" value="<?php echo ( null != $cachedSearch ) ? esc_attr( $cachedSearch['keyword'] ) : ''; ?>"/>
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
				jQuery( '#mainwp_themes_search_by_status' ).dropdown(  'set selected', [<?php echo esc_html( $status ); ?>] );
			} );
			</script>
			<?php
		}
	}

	/**
	 * Render the Child Sites Bulk action & Sidebar Meta boxes.
	 *
	 * @param mixed $keyword
	 * @param mixed $status
	 * @param mixed $groups
	 * @param mixed $sites
	 *
	 * @return mixed $result Errors|HTML
	 */
	public static function render_table( $keyword, $status, $groups, $sites ) {
		MainWP_Cache::init_cache( 'Themes' );

		$output         = new \stdClass();
		$output->errors = array();
		$output->themes = array();

		if ( 1 == get_option( 'mainwp_optimize' ) ) {
			if ( '' != $sites ) {
				foreach ( $sites as $k => $v ) {
					if ( MainWP_Utility::ctype_digit( $v ) ) {
						$website   = MainWP_DB::instance()->get_website_by_id( $v );
						$allThemes = json_decode( $website->themes, true );
						$_count    = count( $allThemes );
						for ( $i = 0; $i < $_count; $i ++ ) {
							$theme = $allThemes[ $i ];
							if ( 'active' === $status || 'inactive' === $status ) {
								if ( 1 == $theme['active'] && 'active' !== $status ) {
									continue;
								} elseif ( 1 != $theme['active'] && 'inactive' !== $status ) {
									continue;
								}
							}

							if ( '' !== $keyword && ! stristr( $theme['title'], $keyword ) ) {
								continue;
							}

							$theme['websiteid']  = $website->id;
							$theme['websiteurl'] = $website->url;
							$output->themes[]    = $theme;
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
							$allThemes = json_decode( $website->themes, true );
							$_count    = count( $allThemes );
							for ( $i = 0; $i < $_count; $i ++ ) {
								$theme = $allThemes[ $i ];
								if ( 'active' === $status || 'inactive' === $status ) {
									if ( 1 == $theme['active'] && 'active' !== $status ) {
										continue;
									} elseif ( 1 != $theme['active'] && 'inactive' !== $status ) {
										continue;
									}
								}
								if ( '' !== $keyword && ! stristr( $theme['title'], $keyword ) ) {
									continue;
								}

								$theme['websiteid']  = $website->id;
								$theme['websiteurl'] = $website->url;
								$output->themes[]    = $theme;
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
				'keyword' => $keyword,
			);

			if ( 'active' === $status || 'inactive' === $status ) {
				$post_data['status'] = $status;
				$post_data['filter'] = true;
			} else {
				$post_data['status'] = '';
				$post_data['filter'] = false;
			}

			MainWP_Utility::fetch_urls_authed( $dbwebsites, 'get_all_themes', $post_data, array( self::get_class_name(), 'themes_search_handler' ), $output );

			if ( 0 < count( $output->errors ) ) {
				foreach ( $output->errors as $siteid => $error ) {
					echo MainWP_Utility::get_nice_url( $dbwebsites[ $siteid ]->url ) . ' - ' . $error . '<br/>';
				}
				echo '<div class="ui hidden divider"></div>';
			}

			if ( count( $output->errors ) == count( $dbwebsites ) ) {
				return;
			}
		}

		MainWP_Cache::add_context(
			'Themes',
			array(
				'keyword' => $keyword,
				'status'  => $status,
				'sites'   => ( '' !== $sites ) ? $sites : '',
				'groups'  => ( '' !== $groups ) ? $groups : '',
			)
		);

		$bulkActions = self::render_bulk_actions( $status );

		if ( 0 == count( $output->themes ) ) {
			ob_start();
			?>
			<div class="ui message yellow"><?php esc_html_e( 'No themes found.', 'mainwp' ); ?></div>
			<?php
			$newOutput = ob_get_clean();

		} else {
			$sites             = array();
			$siteThemes        = array();
			$themes            = array();
			$themesVersion     = array();
			$themesRealVersion = array();
			$themesSlug        = array();

			foreach ( $output->themes as $theme ) {

				$theme['name']       = esc_html( $theme['name'] );
				$theme['version']    = esc_html( $theme['version'] );
				$theme['title']      = esc_html( $theme['title'] );
				$theme['slug']       = esc_html( $theme['slug'] );
				$theme['websiteurl'] = esc_html( $theme['websiteurl'] );

				$sites[ $theme['websiteid'] ]                                  = $theme['websiteurl'];
				$themes[ $theme['name'] . '_' . $theme['version'] ]            = $theme['name'];
				$themesSlug[ $theme['name'] . '_' . $theme['version'] ]        = $theme['slug'];
				$themesVersion[ $theme['name'] . '_' . $theme['version'] ]     = $theme['title'] . ' ' . $theme['version'];
				$themesRealVersion[ $theme['name'] . '_' . $theme['version'] ] = $theme['version'];
				if ( ! isset( $siteThemes[ $theme['websiteid'] ] ) || ! is_array( $siteThemes[ $theme['websiteid'] ] ) ) {
					$siteThemes[ $theme['websiteid'] ] = array();
				}
				$siteThemes[ $theme['websiteid'] ][ $theme['name'] . '_' . $theme['version'] ] = $theme;
			}
			asort( $themesVersion );

			ob_start();
			self::render_manage_themes_table( $sites, $siteThemes, $themesVersion );
			$newOutput = ob_get_clean();
		}

		$result = array(
			'result'       => $newOutput,
			'bulk_actions' => $bulkActions,
		);

		MainWP_Cache::add_result( 'Themes', $result );
		return $result;
	}

	/**
	 * This method renders the Manage Themes Table
	 *
	 * @param mixed $sites List of sites.
	 * @param mixed $themes List of themes.
	 * @param mixed $siteThemes
	 * @param mixed $themesSlug
	 * @param mixed $themesVersion Installed Theme version.
	 * @param mixed $themesRealVersion Current Theme version.
	 *
	 * @return html Output the table.
	 */
	public static function render_manage_themes_table( $sites, $themes, $siteThemes, $themesSlug, $themesVersion, $themesRealVersion ) {
		?>
	
		<table id="mainwp-manage-themes-table" class="ui celled selectable compact single line definition table">
			<thead>
				<tr>
					<th></th>
					<?php foreach ( $themesVersion as $theme_name => $theme_title ) : ?>
						<?php
						$th_id = strtolower( $theme_name );
						$th_id = preg_replace( '/[[:space:]]+/', '_', $th_id );
						?>
						<th id="<?php echo esc_attr( $th_id ); ?>">
							<div class="ui checkbox">
								<input type="checkbox" value="<?php echo esc_attr( $themes[ $theme_name ] ); ?>" id="<?php echo esc_attr( $themes[ $theme_name ] ); ?>-<?php echo esc_attr( $themesRealVersion[ $theme_name ] ); ?>" version="<?php echo esc_attr( $themesRealVersion[ $theme_name ] ); ?>" class="mainwp_theme_check_all" />
								<label for="<?php echo esc_attr( $themes[ $theme_name ] ); ?>-<?php echo esc_attr( $themesRealVersion[ $theme_name ] ); ?>"><?php echo esc_html( $theme_title ); ?></label>
							</div>
						</th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $sites as $site_id => $site_url ) : ?>
				<tr>
					<td>
						<input class="websiteId" type="hidden" name="id" value="<?php echo esc_attr( $site_id ); ?>"/>
						<div class="ui checkbox">
							<input type="checkbox" value="" id="<?php echo esc_url( $site_url ); ?>" class="mainwp_themes_site_check_all" />
							<label><?php echo esc_html( $site_url ); ?></label>
						</div>
					</td>
					<?php foreach ( $themesVersion as $theme_name => $theme_title ) : ?>
						<td class="center aligned">
							<?php if ( isset( $siteThemes[ $site_id ] ) && isset( $siteThemes[ $site_id ][ $theme_name ] ) ) : ?>
								<div class="ui checkbox">
									<input type="checkbox" value="<?php echo esc_attr( $themes[ $theme_name ] ); ?>" name="<?php echo esc_attr( $themes[ $theme_name ] ); ?>" class="mainwp-selected-theme" version="<?php echo esc_attr( $themesRealVersion[ $theme_name ] ); ?>" slug="<?php echo esc_attr( $themesSlug[ $theme_name ] ); ?>"  />
								</div>
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
				jQuery( '#mainwp-manage-themes-table' ).DataTable( {
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
	}

	/**
	 * Render the bulk actions UI.
	 *
	 * @param mixed $status Theme status.
	 *
	 * @return mixed $bulkActions
	 */
	public static function render_bulk_actions( $status ) {
			ob_start();
		?>
		<?php esc_html_e( 'Bulk Actions: ', 'mainwp' ); ?>
		<div class="ui dropdown" id="mainwp-bulk-actions">
			<div class="text"><?php esc_html_e( 'Bulk Actions', 'mainwp' ); ?></div> <i class="dropdown icon"></i>
			<div class="menu">
				<div class="item" data-value="none"><?php esc_html_e( 'Bulk Actions', 'mainwp' ); ?></div>
				<?php if ( mainwp_current_user_can( 'dashboard', 'activate_themes' ) ) : ?>
					<?php if ( 'inactive' === $status || 'all' === $status ) : ?>
						<div class="item" data-value="activate"><?php esc_html_e( 'Activate', 'mainwp' ); ?></div>
					<?php endif; ?>
				<?php endif; ?>
				<?php if ( 'inactive' === $status || 'all' === $status ) : ?>
					<?php if ( mainwp_current_user_can( 'dashboard', 'delete_themes' ) ) : ?>
						<div class="item" data-value="delete"><?php esc_html_e( 'Delete', 'mainwp' ); ?></div>
					<?php endif; ?>
				<?php endif; ?>
				<?php if ( mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
					<div class="item" data-value="ignore_updates"><?php esc_html_e( 'Ignore updates', 'mainwp' ); ?></div>
				<?php endif; ?>
			</div>
		</div>
		<button class="ui mini basic button" href="javascript:void(0)" id="mainwp-do-themes-bulk-actions"><?php esc_html_e( 'Apply', 'mainwp' ); ?></button>
		<span id="mainwp_bulk_action_loading"><i class="ui active inline loader tiny"></i></span>
		<?php
		$bulkActions = ob_get_clean();
		return $bulkActions;
	}

	/**
	 * Theme Search Handler.
	 *
	 * @param mixed $data
	 * @param mixed $website
	 * @param mixed $output
	 *
	 * @return mixed Exception|Theme
	 */
	public static function themes_search_handler( $data, $website, &$output ) {
		if ( 0 < preg_match( '/<mainwp>(.*)<\/mainwp>/', $data, $results ) ) {
			$result = $results[1];
			$themes = MainWP_Utility::get_child_response( base64_decode( $result ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode function is used for benign reasons.
			unset( $results );
			if ( isset( $themes['error'] ) ) {
				$output->errors[ $website->id ] = MainWP_Error_Helper::get_error_message( new MainWP_Exception( $themes['error'], $website->url ) );

				return;
			}

			foreach ( $themes as $theme ) {
				if ( ! isset( $theme['name'] ) ) {
					continue;
				}
				$theme['websiteid']  = $website->id;
				$theme['websiteurl'] = $website->url;

				$output->themes[] = $theme;
			}
			unset( $themes );
		} else {
			$output->errors[ $website->id ] = MainWP_Error_Helper::get_error_message( new MainWP_Exception( 'NOMAINWP', $website->url ) );
		}
	}

	/**
	 * Activate the selected theme.
	 */
	public static function activate_theme() {
		self::action( 'activate', $_POST['theme'] );
		die( 'SUCCESS' );
	}

	/**
	 * Delete the selected theme.
	 */
	public static function delete_themes() {
		self::action( 'delete', implode( '||', $_POST['themes'] ) );
		die( 'SUCCESS' );
	}

	/**
	 * Checks to see if Theme exists, current user can edit settings, check for any errors.
	 *
	 * @param mixed $pAction Action to perform.
	 * @param mixed $theme Theme to perform action on.
	 *
	 * @return boolean True|Fail This will either return 'True' or 'FAIL'.
	 */
	public static function action( $pAction, $theme ) {
		$websiteIdEnc = $_POST['websiteId'];

		$websiteId = $websiteIdEnc;
		if ( ! MainWP_Utility::ctype_digit( $websiteId ) ) {
			die( 'FAIL' );
		}

		$website = MainWP_DB::instance()->get_website_by_id( $websiteId );
		if ( ! MainWP_Utility::can_edit_website( $website ) ) {
			die( 'FAIL' );
		}

		try {
			$information = MainWP_Utility::fetch_url_authed(
				$website,
				'theme_action',
				array(
					'action' => $pAction,
					'theme'  => $theme,
				)
			);
		} catch ( MainWP_Exception $e ) {
			die( 'FAIL' );
		}

		if ( isset( $information['error'] ) ) {
			wp_send_json( $information );
		}

		if ( ! isset( $information['status'] ) || ( 'SUCCESS' !== $information['status'] ) ) {
			die( 'FAIL' );
		}

		die( wp_json_encode( array( 'result' => true ) ) );
	}

	/**
	 * Check to see if Theme is on the Ignore List.
	 */
	public static function ignore_updates() {
		$websiteIdEnc = $_POST['websiteId'];

		$websiteId = $websiteIdEnc;
		if ( ! MainWP_Utility::ctype_digit( $websiteId ) ) {
			die( 'FAIL' );
		}

		$website = MainWP_DB::instance()->get_website_by_id( $websiteId );
		if ( ! MainWP_Utility::can_edit_website( $website ) ) {
			die( 'FAIL' );
		}

		$themes = $_POST['themes'];
		$names  = $_POST['names'];

		$decodedIgnoredThemes = json_decode( $website->ignored_themes, true );
		if ( ! is_array( $decodedIgnoredThemes ) ) {
			$decodedIgnoredThemes = array();
		}

		if ( is_array( $themes ) ) {
			$_count = count( $themes );
			for ( $i = 0; $i < $_count; $i ++ ) {
				$slug = $themes[ $i ];
				$name = $names[ $i ];
				if ( ! isset( $decodedIgnoredThemes[ $slug ] ) ) {
					$decodedIgnoredThemes[ $slug ] = urldecode( $name );
				}
			}
			MainWP_DB::instance()->update_website_values( $website->id, array( 'ignored_themes' => wp_json_encode( $decodedIgnoredThemes ) ) );
		}

		die( wp_json_encode( array( 'result' => true ) ) );
	}

	/** Render the Install Themes Tab. */
	public static function render_install() {
		wp_enqueue_script( 'mainwp-theme', MAINWP_PLUGIN_URL . 'assets/js/mainwp-theme.js', array( 'wp-backbone', 'wp-a11y' ), MAINWP_VERSION, true );
		wp_localize_script(
			'mainwp-theme',
			'_mainwpThemeSettings',
			array(
				'themes'          => false,
				'settings'        => array(
					'isInstall'     => true,
					'canInstall'    => false,
					'installURI'    => null,
					'adminUrl'      => wp_parse_url( self_admin_url(), PHP_URL_PATH ),
				),
				'l10n'            => array(
					'addNew'            => __( 'Add new theme' ),
					'search'            => __( 'Search themes' ),
					'searchPlaceholder' => __( 'Search themes...' ),
					'upload'            => __( 'Upload theme' ),
					'back'              => __( 'Back' ),
					'error'             => __( 'An unexpected error occurred. Something may be wrong with WordPress.org or this server&#8217;s configuration. If you continue to have problems, please try the <a href="https://wordpress.org/support/">support forums</a>.' ),
					'themesFound'       => __( 'Number of themes found: %d' ),
					'noThemesFound'     => __( 'No themes found. Try a different search.' ),
					'collapseSidebar'   => __( 'Collapse sidebar' ),
					'expandSidebar'     => __( 'Expand sidebar' ),
				),
				'installedThemes' => array(),
			)
		);
		self::render_header( 'Install' );
		self::render_themes_table();
		self::render_footer( 'Install' );
	}

	/** Redner the Themes table for the Install Themes Tab. */
	public static function render_themes_table() {
		if ( ! mainwp_current_user_can( 'dashboard', 'install_themes' ) ) {
			mainwp_do_not_have_permissions( __( 'install themes', 'mainwp' ) );
			return;
		}
		$favorites_enabled = is_plugin_active( 'mainwp-favorites-extension/mainwp-favorites-extension.php' );
		?>
		<div class="ui alt segment" id="mainwp-install-themes">
			<div class="mainwp-main-content">
				<div class="mainwp-actions-bar">
					<div class="ui grid">
						<div class="ui two column row">
							<div class="column">
								<div class="ui fluid search focus">
									<div class="ui icon fluid input hide-if-upload" id="mainwp-search-themes-input-container"></div>
									<div class="results"></div>
								</div>
								<?php do_action( 'mainwp_install_themes_actions_bar_left' ); ?>
							</div>
							<div class="right aligned column">
								<div class="ui buttons">
									<a href="#" class="ui button browse-themes" ><?php esc_html_e( 'Install from WordPress.org', 'mainwp' ); ?></a>
									<div class="or"></div>
									<a href="#" class="ui button upload" ><?php esc_html_e( 'Upload .zip file', 'mainwp' ); ?></a>
								</div>
								<?php do_action( 'mainwp_install_themes_actions_bar_right' ); ?>
							</div>
						</div>
					</div>
				</div>
				<div class="ui segment">
					<div id="mainwp-message-zone" class="ui message" style="display:none;"></div>
					<div class="mainwp-upload-theme">
						<?php MainWP_Install_Bulk::render_upload( 'theme' ); ?>
					</div>
					<div id="themes-loading" class="ui large text loader"><?php esc_html_e( 'Loading Themes...', 'mainwp' ); ?></div>
					<form id="theme-filter" method="post">
						<?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
						<div class="mainwp-browse-themes content-filterable hide-if-upload"></div>
						<div class="theme-install-overlay wp-full-overlay expanded"></div>
					</form>

					<?php MainWP_UI::render_modal_install_plugin_theme( 'theme' ); ?>
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
					?>
					<?php MainWP_UI::select_sites_box( 'checkbox', true, true, 'mainwp_select_sites_box_left', '', $selected_sites, $selected_groups ); ?>
				</div>
				<div class="ui divider"></div>
				<div class="mainwp-search-options">
					<div class="ui header"><?php esc_html_e( 'Installation Options', 'mainwp' ); ?></div>
					<div class="ui form">
						<div class="field">
							<div class="ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'If enabled and the theme already installed on the sites, the already installed version will be overwritten.', 'mainwp' ); ?>" data-position="left center" data-inverted="">
								<input type="checkbox" value="2" checked="checked" id="chk_overwrite" />
								<label for="chk_overwrite"><?php esc_html_e( 'Overwrite existing version', 'mainwp' ); ?></label>
							</div>
						</div>
					</div>
				</div>
				<div class="ui divider"></div>
				<div class="mainwp-search-submit">
					<input type="button" value="<?php esc_attr_e( 'Complete Installation', 'mainwp' ); ?>" class="ui green big fluid button" bulk-action="install" id="mainwp_theme_bulk_install_btn" name="bulk-install">
				</div>
			</div>
			<div class="ui clearing hidden divider"></div>
		</div>

		<script id="tmpl-theme" type="text/template">
				<# if ( data.screenshot_url ) { #>
				<div class="image">
					<img src="{{ data.screenshot_url }}" />
				</div>
				<# } #>
				<div class="content">
					<div class="header">{{ data.name }}</div>
					<div class="meta">
						<a><?php printf( __( 'By %s', 'mainwp' ), '{{ data.author }}' ); ?></a>
					</div>
				</div>
				<div class="extra content">
					<span class="right floated"><?php printf( __( 'Version: %s', 'mainwp' ), '{{ data.version }}' ); ?></span>
					<# if ( data.rating ) { #>
						<div class="star-rating rating-{{ Math.round( data.rating / 10 ) * 10 }}">
							<span class="one"></span><span class="two"></span><span class="three"></span><span class="four"></span><span class="five"></span>
							<small class="ratings">{{ data.num_ratings }}</small>
						</div>
					<# } else { #>
						<div class="star-rating">
							<small class="ratings"><?php esc_html_e( 'This theme has not been rated yet.', 'mainwp' ); ?></small>
						</div>
					<# } #>
				</div>
				<div class="extra content mainwp-theme-lnks">
					<a href="#" id="mainwp-{{data.slug}}-preview" class="ui mini button mainwp-theme-preview"><?php esc_html_e( 'Preview', 'mainwp' ); ?></a>
					<div class="ui radio checkbox right floated">
						<input name="install-theme" type="radio" id="install-theme-{{data.slug}}" title="Install {{data.name}}">
						<label for="install-theme-{{data.slug}}"><?php esc_html_e( 'Install this Theme', 'mainwp' ); ?></label>
					</div>
				</div>
				<?php if ( $favorites_enabled ) { ?>
				<div class="extra content">
					<span><?php esc_html_e( 'Add to Favorites', 'mainwp-favorites-extension' ); ?></span>
					<a class="ui huge star rating right floated" data-max-rating="1" id="add-favorite-theme-{{data.slug}}"></a>
				</div>
				<?php } ?>
		</script>

		<script id="tmpl-theme-preview" type="text/template">
			<div class="wp-full-overlay-sidebar">
				<div class="wp-full-overlay-header">
					<a href="#" class="close-full-overlay"><span class="screen-reader-text"><?php esc_html_e( 'Close', 'mainwp' ); ?></span></a>
					<a href="#" class="previous-theme"><span class="screen-reader-text"><?php esc_html_e( 'Previous', 'mainwp' ); ?></span></a>
					<a href="#" class="next-theme"><span class="screen-reader-text"><?php esc_html_e( 'Next', 'mainwp' ); ?></span></a>
				</div>
				<div class="wp-full-overlay-sidebar-content">
					<div class="install-theme-info">
						<h3 class="theme-name">{{ data.name }}</h3>
						<span class="theme-by"><?php printf( __( 'By %s', 'mainwp' ), '{{ data.author }}' ); ?></span>
						<img class="theme-screenshot" src="{{ data.screenshot_url }}" alt="" />
						<div class="theme-details">
							<# if ( data.rating ) { #>
								<div class="star-rating rating-{{ Math.round( data.rating / 10 ) * 10 }}">
									<span class="one"></span><span class="two"></span><span class="three"></span><span class="four"></span><span class="five"></span>
									<small class="ratings">{{ data.num_ratings }}</small>
								</div>
							<# } else { #>
								<div class="star-rating">
									<small class="ratings"><?php esc_html_e( 'This theme has not been rated yet.', 'mainwp' ); ?></small>
								</div>
							<# } #>
							<div class="theme-version"><?php printf( __( 'Version: %s', 'mainwp' ), '{{ data.version }}' ); ?></div>
							<div class="theme-description">{{{ data.description }}}</div>
						</div>
					</div>
				</div>
				<div class="wp-full-overlay-footer">
					<button type="button" class="collapse-sidebar button-secondary" aria-expanded="true" aria-label="<?php esc_attr_e( 'Collapse Sidebar', 'mainwp' ); ?>">
						<span class="collapse-sidebar-arrow"></span>
						<span class="collapse-sidebar-label"><?php esc_html_e( 'Collapse', 'mainwp' ); ?></span>
					</button>
				</div>
			</div>
			<div class="wp-full-overlay-main">
				<iframe src="{{ data.preview_url }}" title="<?php esc_attr_e( 'Preview', 'mainwp' ); ?>" />
			</div>
		</script>


		<?php
	}

	/** Perform a Theme Search on the WP Theme Repository. */
	public static function perform_search() {
		MainWP_Install_Bulk::perform_search( self::get_class_name(), 'Themes' );
	}

	/** Render the Themes Auto Update Tab. */
	public static function render_auto_update() {

		$cachedThemesSearch = null;
		if ( isset( $_SESSION['SNThemesAllStatus'] ) ) {
			$cachedThemesSearch = $_SESSION['SNThemesAllStatus'];
		}

		self::render_header( 'AutoUpdate' );

		if ( ! mainwp_current_user_can( 'dashboard', 'trust_untrust_updates' ) ) {
			mainwp_do_not_have_permissions( __( 'trust/untrust updates', 'mainwp' ) );
			return;
		} else {
			$snThemeAutomaticDailyUpdate = get_option( 'mainwp_themeAutomaticDailyUpdate' );

			if ( false === $snThemeAutomaticDailyUpdate ) {
				$snThemeAutomaticDailyUpdate = get_option( 'mainwp_automaticDailyUpdate' );
				update_option( 'mainwp_themeAutomaticDailyUpdate', $snThemeAutomaticDailyUpdate );
			}

			$update_time         = MainWP_Utility::get_websites_automatic_update_time();
			$lastAutomaticUpdate = $update_time['last'];
			$nextAutomaticUpdate = $update_time['next'];
			?>
			<div class="ui alt segment" id="mainwp-theme-auto-updates">
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
												<div class="item" data-value="trust"><?php esc_html_e( 'Trust', 'mainwp' ); ?></div>
												<div class="item" data-value="untrust"><?php esc_html_e( 'Untrust', 'mainwp' ); ?></div>
											</div>
										</div>
										<input type="button" name="" id="mainwp-bulk-trust-themes-action-apply" class="ui mini basic button" value="<?php esc_attr_e( 'Apply', 'mainwp' ); ?>"/>
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
					<div id="mainwp-auto-updates-themes-content" class="ui segment">
						<div class="ui inverted dimmer">
							<div class="ui text loader"><?php esc_html_e( 'Loading themes', 'mainwp' ); ?></div>
						</div>
						<div id="mainwp-auto-updates-themes-table-wrapper">
							<?php
							if ( isset( $_SESSION['SNThemesAll'] ) ) {
								self::render_all_themes_table( $_SESSION['SNThemesAll'] );
							}
							?>
						</div>
					</div>
				</div>
				<div class="mainwp-side-content mainwp-no-padding">
					<div class="mainwp-search-options" style="margin-top:1rem">
						<div class="ui info message">
							<i class="close icon mainwp-notice-dismiss" notice-id="themes-auto-updates"></i>
							<p><?php esc_html_e( 'The MainWP Auto Updates feature is a tool for your Dashboard to automatically update themes that you trust to be updated without breaking your Child sites.', 'mainwp' ); ?></p>
							<p><?php esc_html_e( 'Only mark themes as trusted if you are absolutely sure they can be automatically updated by your MainWP Dashboard without causing issues on the Child sites!	', 'mainwp' ); ?></p>
							<p><strong><?php esc_html_e( 'Auto Updates a delayed approximately 24 hours from the update release. Ignored themes can not be automatically updated.', 'mainwp' ); ?></strong></p>
						</div>
						<div class="ui header"><?php esc_html_e( 'Theme Status to Search', 'mainwp' ); ?></div>
						<div class="ui mini form">
							<div class="field">
								<select class="ui fluid dropdown" id="mainwp_au_theme_status">
									<option value="all" <?php echo ( null != $cachedThemesSearch && 'all' === $cachedThemesSearch['theme_status'] ) ? 'selected' : ''; ?>><?php esc_html_e( 'Active and Inactive', 'mainwp' ); ?></option>
									<option value="active" <?php echo ( null != $cachedThemesSearch && 'active' === $cachedThemesSearch['theme_status'] ) ? 'selected' : ''; ?>><?php esc_html_e( 'Active', 'mainwp' ); ?></option>
									<option value="inactive" <?php echo ( null != $cachedThemesSearch && 'inactive' === $cachedThemesSearch['theme_status'] ) ? 'selected' : ''; ?>><?php esc_html_e( 'Inactive', 'mainwp' ); ?></option>
								</select>
							</div>
						</div>
					</div>
					<div class="ui divider"></div>
					<div class="mainwp-search-options">
						<div class="ui header"><?php esc_html_e( 'Search Options', 'mainwp' ); ?></div>
						<div class="ui mini form">
							<div class="field">
								<select class="ui fluid dropdown" id="mainwp_au_theme_trust_status">
									<option value="all" <?php echo ( null != $cachedThemesSearch && 'all' === $cachedThemesSearch['status'] ) ? 'selected' : ''; ?>><?php esc_html_e( 'Trusted, Not trusted and Ignored', 'mainwp' ); ?></option>
									<option value="trust" <?php echo ( null != $cachedThemesSearch && 'trust' === $cachedThemesSearch['status'] ) ? 'selected' : ''; ?>><?php esc_html_e( 'Trusted', 'mainwp' ); ?></option>
									<option value="untrust" <?php echo ( null != $cachedThemesSearch && 'untrust' === $cachedThemesSearch['status'] ) ? 'selected' : ''; ?>><?php esc_html_e( 'Not trusted', 'mainwp' ); ?></option>
									<option value="ignored" <?php echo ( null != $cachedThemesSearch && 'ignored' === $cachedThemesSearch['status'] ) ? 'selected' : ''; ?>><?php esc_html_e( 'Ignored', 'mainwp' ); ?></option>
								</select>
							</div>
							<div class="field">
								<div class="ui input fluid">
									<input type="text" placeholder="<?php esc_attr_e( 'Containing keyword', 'mainwp' ); ?>" id="mainwp_au_theme_keyword" class="text" value="<?php echo ( null !== $cachedThemesSearch ) ? $cachedThemesSearch['keyword'] : ''; ?>">
								</div>
							</div>
						</div>
					</div>
					<div class="ui divider"></div>
					<div class="mainwp-search-submit">
						<a href="#" class="ui green big fluid button" id="mainwp_show_all_active_themes"><?php esc_html_e( 'Show Themes', 'mainwp' ); ?></a>
					</div>
				</div>
			</div>
			<?php
		}
		MainWP_UI::render_modal_edit_notes( 'theme' );
		self::render_footer( 'AutoUpdate' );
	}

	// phpcs:ignore -- not quite complex function
	/**
	 * Render the All Themes Table.
	 *
	 * @param null $output
	 */
	public static function render_all_themes_table( $output = null ) {
		$keyword       = null;
		$search_status = 'all';

		if ( null == $output ) {
			$keyword             = isset( $_POST['keyword'] ) && ! empty( $_POST['keyword'] ) ? trim( $_POST['keyword'] ) : null;
			$search_status       = isset( $_POST['status'] ) ? $_POST['status'] : 'all';
			$search_theme_status = isset( $_POST['theme_status'] ) ? $_POST['theme_status'] : 'all';

			$output         = new \stdClass();
			$output->errors = array();
			$output->themes = array();

			if ( 1 == get_option( 'mainwp_optimize' ) ) {
				$websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );
				while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
					$allThemes = json_decode( $website->themes, true );
					$_count    = count( $allThemes );
					for ( $i = 0; $i < $_count; $i ++ ) {
						$theme = $allThemes[ $i ];
						if ( 'all' !== $search_theme_status ) {
							if ( 1 == $theme['active'] && 'active' !== $search_theme_status ) {
								continue;
							} elseif ( 1 != $theme['active'] && 'inactive' !== $search_theme_status ) {
								continue;
							}
						}
						if ( '' !== $keyword && false === stristr( $theme['name'], $keyword ) ) {
							continue;
						}
						$theme['websiteid']  = $website->id;
						$theme['websiteurl'] = $website->url;
						$output->themes[]    = $theme;
					}
				}
				MainWP_DB::free_result( $websites );
			} else {
				$dbwebsites = array();
				$websites   = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );
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

				$post_data = array( 'keyword' => $keyword );

				if ( 'active' === $search_theme_status || 'inactive' === $search_theme_status ) {
					$post_data['status'] = $search_theme_status;
					$post_data['filter'] = true;
				} else {
					$post_data['status'] = '';
					$post_data['filter'] = false;
				}

				MainWP_Utility::fetch_urls_authed( $dbwebsites, 'get_all_themes', $post_data, array( self::get_class_name(), 'themes_search_handler' ), $output );

				if ( 0 < count( $output->errors ) ) {
					foreach ( $output->errors as $siteid => $error ) {
						echo MainWP_Utility::get_nice_url( $dbwebsites[ $siteid ]->url ) . ' - ' . $error . ' <br/>';
					}
					echo '<div class="ui hidden divider"></div>';
				}

				if ( count( $output->errors ) == count( $dbwebsites ) ) {
					$_SESSION['SNThemesAll'] = $output;
					return;
				}
			}

			$_SESSION['SNThemesAll']       = $output;
			$_SESSION['SNThemesAllStatus'] = array(
				'keyword'      => $keyword,
				'status'       => $search_status,
				'theme_status' => $search_theme_status,
			);
		} else {
			if ( isset( $_SESSION['SNThemesAllStatus'] ) ) {
				$keyword             = $_SESSION['SNThemesAllStatus']['keyword'];
				$search_status       = $_SESSION['SNThemesAllStatus']['status'];
				$search_theme_status = $_SESSION['SNThemesAllStatus']['theme_status'];
			}
		}

		if ( 0 == count( $output->themes ) ) {
			?>
			<div class="ui message yellow"><?php esc_html_e( 'No themes found.', 'mainwp' ); ?></div>
			<?php
			return;
		}

		$themes = array();
		foreach ( $output->themes as $theme ) {
			$themes[ $theme['slug'] ] = $theme;
		}
		asort( $themes );

		$userExtension        = MainWP_DB::instance()->get_user_extension();
		$decodedIgnoredThemes = json_decode( $userExtension->ignored_themes, true );
		$trustedThemes        = json_decode( $userExtension->trusted_themes, true );
		if ( ! is_array( $trustedThemes ) ) {
			$trustedThemes = array();
		}
		$trustedThemesNotes = json_decode( $userExtension->trusted_themes_notes, true );
		if ( ! is_array( $trustedThemesNotes ) ) {
			$trustedThemesNotes = array();
		}
		self::render_all_themes_html( $themes, $search_status, $trustedThemes, $trustedThemesNotes, $decodedIgnoredThemes );
	}

	/**
	 * Render all themes html.
	 *
	 * @param mixed $themes Themes list.
	 * @param mixed $search_status Search status.
	 * @param mixed $trustedThemes Trusted themes.
	 * @param mixed $trustedThemesNotes Trusted themes notes.
	 * @param mixed $decodedIgnoredThemes Decoded ignored themes.
	 */
	public static function render_all_themes_html( $themes, $search_status, $trustedThemes, $trustedThemesNotes, $decodedIgnoredThemes ) {
		?>
		<table class="ui single line table" id="mainwp-all-active-themes-table">
			<thead>
				<tr>
					<th class="no-sort collapsing check-column"><span class="ui checkbox"><input id="cb-select-all-top" type="checkbox" /></span></th>
					<th class="collapsing"></th>
					<th><?php esc_html_e( 'Theme', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
					<th class="collapsing"><?php esc_html_e( 'Trust Status', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Ignored Status', 'mainwp' ); ?></th>
					<th class="collapsing"><?php esc_html_e( 'Notes', 'mainwp' ); ?></th>
				</tr>
			</thead>

			<tbody>
			<?php foreach ( $themes as $slug => $theme ) : ?>
				<?php
				$name = esc_html( $theme['name'] );
				if ( ! empty( $search_status ) && 'all' !== $search_status ) {
					if ( 'trust' === $search_status && ! in_array( $slug, $trustedThemes ) ) {
						continue;
					} elseif ( 'untrust' === $search_status && in_array( $slug, $trustedThemes ) ) {
						continue;
					} elseif ( 'ignored' === $search_status && ! isset( $decodedIgnoredThemes[ $slug ] ) ) {
						continue;
					}
				}

				$esc_note   = '';
				$strip_note = '';
				if ( isset( $trustedThemesNotes[ $slug ] ) ) {
					$esc_note   = MainWP_Utility::esc_content( $trustedThemesNotes[ $slug ] );
					$strip_note = wp_strip_all_tags( $esc_note );
				}

				?>
				<tr theme-slug="<?php echo rawurlencode( $slug ); ?>" theme-name="<?php echo esc_attr( $name ); ?>">
					<td class="check-column"><span class="ui checkbox"><input type="checkbox" name="theme[]" value="<?php echo rawurlencode( $slug ); ?>"></span></td>
					<td><?php echo ( isset( $decodedIgnoredThemes[ $slug ] ) ) ? '<span data-tooltip="Ignored themes will not be automatically updated." data-inverted=""><i class="info red circle icon"></i></span>' : ''; ?></td>
					<td><a href="<?php echo admin_url() . 'plugin-install.php?tab=plugin-information&plugin=' . rawurlencode( dirname( $slug ) ) . '&TB_iframe=true&width=640&height=477'; ?>" target="_blank"><?php echo esc_html( $name ); ?></a></td>
					<td><?php echo ( 1 == $theme['active'] ) ? esc_html__( 'Active', 'mainwp' ) : esc_html__( 'Inactive', 'mainwp' ); ?></td>
					<td><?php echo ( in_array( $slug, $trustedThemes ) ) ? '<span class="ui mini green fluid center aligned label">' . esc_html__( 'Trusted', 'mainwp' ) . '</span>' : '<span class="ui mini red fluid center aligned label">' . esc_html__( 'Not Trusted', 'mainwp' ) . '</span>'; ?></td>
					<td><?php echo ( isset( $decodedIgnoredThemes[ $slug ] ) ) ? '<span class="ui mini label">' . esc_html__( 'Ignored', 'mainwp' ) . '</span>' : ''; ?></td>
					<td class="collapsing center aligned">
					<?php if ( '' === $esc_note ) : ?>
						<a href="javascript:void(0)" class="mainwp-edit-theme-note"><i class="sticky note outline icon"></i></a>
					<?php else : ?>
						<a href="javascript:void(0)" class="mainwp-edit-theme-note" data-tooltip="<?php echo substr( $strip_note, 0, 100 ); ?>" data-position="left center" data-inverted=""><i class="sticky green note icon"></i></a>
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
					<th><?php esc_html_e( 'Theme', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Trust Status', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Ignored Status', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Notes', 'mainwp' ); ?></th>
				</tr>
			</tfoot>
		</table>
		<script type="text/javascript">
			jQuery( document ).ready( function() {
				jQuery( '.mainwp-ui-page .ui.checkbox' ).checkbox();

				jQuery( '#mainwp-all-active-themes-table' ).DataTable( {
					"colReorder" : true,
					"stateSave":  true,
					"paging":   false,
					"ordering": true,
					"columnDefs": [ { "orderable": false, "targets": [ 0, 1, 6 ] } ],
					"order": [ [ 2, "asc" ] ]
				} );
			} );
		</script>
		<?php
	}

	/** Render the Themes Ignored Updates Tab. */
	public static function render_ignore() {
		$websites             = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );
		$userExtension        = MainWP_DB::instance()->get_user_extension();
		$decodedIgnoredThemes = json_decode( $userExtension->ignored_themes, true );
		$ignoredThemes        = ( is_array( $decodedIgnoredThemes ) && ( 0 < count( $decodedIgnoredThemes ) ) );

		$cnt = 0;

		while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
			if ( $website->is_ignoreThemeUpdates ) {
				continue;
			}

			$tmpDecodedIgnoredThemes = json_decode( $website->ignored_themes, true );

			if ( ! is_array( $tmpDecodedIgnoredThemes ) || 0 == count( $tmpDecodedIgnoredThemes ) ) {
				continue;
			}

			$cnt ++;
		}

		self::render_header( 'Ignore' );
		?>
		<div id="mainwp-ignored-plugins" class="ui segment">
			<h3 class="ui header">
				<?php esc_html_e( 'Globally Ignored Themes', 'mainwp' ); ?>
				<div class="sub header"><?php esc_html_e( 'These are themes you have told your MainWP Dashboard to ignore updates on global level and not notify you about pending updates.', 'mainwp' ); ?></div>
			</h3>
			<?php
			self::render_global_ignored( $ignoredThemes, $decodedIgnoredThemes );
			?>
						
		<div class="ui hidden divider"></div>
		<h3 class="ui header">
			<?php esc_html_e( 'Per Site Ignored Themes', 'mainwp' ); ?>
			<div class="sub header"><?php esc_html_e( 'These are themes you have told your MainWP Dashboard to ignore updates per site level and not notify you about pending updates.', 'mainwp' ); ?></div>
		</h3>
		<?php
			self::render_sites_ignored( $cnt, $websites );
		?>
					
		</div>
		<?php
		self::render_footer( 'Ignore' );
	}

	/**
	 * Render globally Ignored themes.
	 *
	 * @param mixed $ignoredThemes Encoded ignored themes.
	 * @param mixed $decodedIgnoredThemes Decoded ignored themes.
	 */
	public static function render_global_ignored( $ignoredThemes, $decodedIgnoredThemes ) {
		?>
		<table id="mainwp-globally-ignored-themes" class="ui compact selectable table stackable">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Theme', 'mainwp' ); ?></th>
						<th><?php esc_html_e( 'Theme slug', 'mainwp' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody id="globally-ignored-themes-list">
					<?php if ( $ignoredThemes ) : ?>
						<?php foreach ( $decodedIgnoredThemes as $ignoredTheme => $ignoredThemeName ) : ?>
						<tr theme-slug="<?php echo rawurlencode( $ignoredTheme ); ?>">
							<td><?php echo esc_html( $ignoredThemeName ); ?></td>
							<td><?php echo esc_html( $ignoredTheme ); ?></td>
							<td class="right aligned">
							<?php if ( mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
								<a href="#" class="ui mini button" onClick="return updatesoverview_themes_unignore_globally( '<?php echo rawurlencode( $ignoredTheme ); ?>' )"><?php esc_html_e( 'Unigore', 'mainwp' ); ?></a>
							<?php endif; ?>
							</td>
						</tr>
						<?php endforeach; ?>
					<?php else : ?>
						<tr><td colspan="999"><?php esc_html_e( 'No ignored themes.', 'mainwp' ); ?></td></tr>
					<?php endif; ?>
				</tbody>
				<?php if ( mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
					<?php if ( $ignoredThemes ) : ?>
					<tfoot class="full-width">
						<tr>
							<th colspan="999">
								<a class="ui right floated small green labeled icon button" onClick="return updatesoverview_themes_unignore_globally_all();" id="mainwp-unignore-globally-all">
									<i class="check icon"></i> <?php esc_html_e( 'Unignore All', 'mainwp' ); ?>
								</a>
							</th>
						</tr>
					</tfoot>
				<?php endif; ?>
			<?php endif; ?>
		</table>	
		<?php
	}

	/**
	 * Render ignored sites.
	 *
	 * @param mixed $cnt
	 * @param mixed $websites Websites list.
	 */
	public static function render_sites_ignored( $cnt, $websites ) {
		?>
		<table id="mainwp-per-site-ignored-themes" class="ui compact selectable table stackable">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Site', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Theme', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Theme slug', 'mainwp' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody id="ignored-themes-list">
			<?php if ( 0 < $cnt ) : ?>
				<?php
				MainWP_DB::data_seek( $websites, 0 );

				while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
					if ( $website->is_ignoreThemeUpdates ) {
						continue;
					}
					$decodedIgnoredThemes = json_decode( $website->ignored_themes, true );
					if ( ! is_array( $decodedIgnoredThemes ) || 0 == count( $decodedIgnoredThemes ) ) {
						continue;
					}
					$first = true;

					foreach ( $decodedIgnoredThemes as $ignoredTheme => $ignoredThemeName ) {
						?>
						<tr site-id="<?php echo esc_attr( $website->id ); ?>" theme-slug="<?php echo rawurlencode( $ignoredTheme ); ?>">
							<?php if ( $first ) : ?>
								<td><div><a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"><?php echo stripslashes( $website->name ); ?></a></div></td>
								<?php $first = false; ?>
							<?php else : ?>
								<td><div style="display:none;"><a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"><?php echo stripslashes( $website->name ); ?></a></div></td>
							<?php endif; ?>
							<td><?php echo esc_html( $ignoredThemeName ); ?></td>
							<td><?php echo esc_html( $ignoredTheme ); ?></td>
							<td class="right aligned">
							<?php if ( mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
								<a href="#" class="ui mini button" onClick="return updatesoverview_themes_unignore_detail( '<?php echo rawurlencode( $ignoredTheme ); ?>', <?php echo esc_attr( $website->id ); ?> )"><?php esc_html_e( 'Unigore', 'mainwp' ); ?></a>
							<?php endif; ?>
							</td>
						</tr>
						<?php
					}
				}
				MainWP_DB::free_result( $websites );
				?>
				<?php else : ?>
					<tr><td colspan="999"><?php esc_html_e( 'No ignored themes', 'mainwp' ); ?></td></tr>
				<?php endif; ?>
			</tbody>
			<?php if ( mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
				<?php if ( 0 < $cnt ) : ?>
				<tfoot class="full-width">
				<tr>
					<th colspan="999">
						<a class="ui right floated small green labeled icon button" onClick="return updatesoverview_themes_unignore_detail_all();" id="mainwp-unignore-detail-all">
							<i class="check icon"></i> <?php esc_html_e( 'Unignore All', 'mainwp' ); ?>
						</a>
					</th>
				</tr>
				</tfoot>
				<?php endif; ?>
			<?php endif; ?>
		</table>
		<?php
	}

	/** Render the Themes Ignored/Abandoned Tab */
	public static function render_ignored_abandoned() {
		$websites             = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );
		$userExtension        = MainWP_DB::instance()->get_user_extension();
		$decodedIgnoredThemes = json_decode( $userExtension->dismissed_themes, true );
		$ignoredThemes        = ( is_array( $decodedIgnoredThemes ) && ( 0 < count( $decodedIgnoredThemes ) ) );
		$cnt                  = 0;
		while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
			$tmpDecodedIgnoredThemes = json_decode( MainWP_DB::instance()->get_website_option( $website, 'themes_outdate_dismissed' ), true );
			if ( ! is_array( $tmpDecodedIgnoredThemes ) || 0 == count( $tmpDecodedIgnoredThemes ) ) {
				continue;
			}
			$cnt ++;
		}

		self::render_header( 'IgnoreAbandoned' );
		?>
		<div id="mainwp-ignored-abandoned-themes" class="ui segment">
			<h3 class="ui header">
				<?php esc_html_e( 'Globally Ignored Abandoned Themes', 'mainwp' ); ?>
				<div class="sub header"><?php esc_html_e( 'These are themes you have told your MainWP Dashboard to ignore on global level even though they have passed your Abandoned Themes Tolerance date', 'mainwp' ); ?></div>
			</h3>
			<?php
			self::render_global_ignored_abandoned( $ignoredThemes, $decodedIgnoredThemes );
			?>
		<div class="ui hidden divider"></div>
		<h3 class="ui header">
			<?php esc_html_e( 'Per Site Ignored Abandoned Themes', 'mainwp' ); ?>
			<div class="sub header"><?php esc_html_e( 'These are themes you have told your MainWP Dashboard to ignore per site level even though they have passed your Abandoned Theme Tolerance date', 'mainwp' ); ?></div>
		</h3>
			<?php
			self::render_sites_ignored_abandoned( $cnt, $websites );
			?>
					
		</div>
		<?php
		self::render_footer( 'IgnoreAbandoned' );
	}

	/**
	 * Render the global ignored themes list.
	 *
	 * @param mixed $ignoredThemes Encoded ignored themes list.
	 * @param mixed $decodedIgnoredThemes Decoded ignored themes list.
	 */
	public static function render_global_ignored_abandoned( $ignoredThemes, $decodedIgnoredThemes ) {
		?>
		<table id="mainwp-globally-ignored-abandoned-themes" class="ui compact selectable table stackable">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Theme', 'mainwp' ); ?></th>
					<th><?php esc_html_e( 'Theme slug', 'mainwp' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody id="globally-ignored-themes-list">
				<?php if ( $ignoredThemes ) : ?>
					<?php foreach ( $decodedIgnoredThemes as $ignoredTheme => $ignoredThemeName ) : ?>
					<tr theme-slug="<?php echo rawurlencode( $ignoredTheme ); ?>">
						<td><?php echo esc_html( $ignoredThemeName ); ?></td>
						<td><?php echo esc_html( $ignoredTheme ); ?></td>
						<td class="right aligned">
						<?php if ( mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
							<a href="#" class="ui mini button" onClick="return updatesoverview_themes_abandoned_unignore_globally( '<?php echo rawurlencode( $ignoredTheme ); ?>' )"><?php esc_html_e( 'Unignore', 'mainwp' ); ?></a>
						<?php endif; ?>
						</td>
					</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr><td colspan="999"><?php esc_html_e( 'No ignored abandoned themes.', 'mainwp' ); ?></td></tr>
				<?php endif; ?>
				</tbody>
				<?php if ( mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
					<?php if ( $ignoredThemes ) : ?>
					<tfoot class="full-width">
						<tr>
							<th colspan="999">
								<a class="ui right floated small green labeled icon button" onClick="return updatesoverview_themes_abandoned_unignore_globally_all();" id="mainwp-unignore-globally-all">
									<i class="check icon"></i> <?php esc_html_e( 'Unignore All', 'mainwp' ); ?>
								</a>
							</th>
						</tr>
					</tfoot>
					<?php endif; ?>
				<?php endif; ?>
		</table>
		<?php
	}

	/**
	 * Render ignored sites list.
	 *
	 * @param mixed $cnt
	 * @param mixed $websites Websites list.
	 */
	public static function render_sites_ignored_abandoned( $cnt, $websites ) {
		?>
	<table id="mainwp-per-site-ignored-abandoned-themes" class="ui compact selectable table stackable">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Site', 'mainwp' ); ?></th>
				<th><?php esc_html_e( 'Theme', 'mainwp' ); ?></th>
				<th><?php esc_html_e( 'Theme slug', 'mainwp' ); ?></th>
				<th></th>
			</tr>
		</thead>
		<tbody id="ignored-abandoned-themes-list">
			<?php if ( 0 < $cnt ) : ?>
				<?php
				MainWP_DB::data_seek( $websites, 0 );
				while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
					$decodedIgnoredThemes = json_decode( MainWP_DB::instance()->get_website_option( $website, 'themes_outdate_dismissed' ), true );
					if ( ! is_array( $decodedIgnoredThemes ) || 0 == count( $decodedIgnoredThemes ) ) {
						continue;
					}

					$first = true;
					foreach ( $decodedIgnoredThemes as $ignoredTheme => $ignoredThemeName ) {
						?>
					<tr site-id="<?php echo esc_attr( $website->id ); ?>" theme-slug="<?php echo rawurlencode( $ignoredTheme ); ?>">
						<?php if ( $first ) : ?>
						<td><a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"><?php echo stripslashes( $website->name ); ?></a></td>
							<?php $first = false; ?>
						<?php else : ?>
						<td><div style="display:none;"><a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"><?php echo stripslashes( $website->name ); ?></a></div></td>
						<?php endif; ?>
						<td><?php echo esc_html( $ignoredThemeName ); ?></td>
						<td><?php echo esc_html( $ignoredTheme ); ?></td>
						<td class="right aligned">
						<?php if ( mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
							<a href="#" class="ui mini button" onClick="return updatesoverview_themes_unignore_abandoned_detail( '<?php echo rawurlencode( $ignoredTheme ); ?>', <?php echo esc_attr( $website->id ); ?> )"><?php esc_html_e( 'Unignore', 'mainwp' ); ?></a>
						<?php endif; ?>
						</td>
					</tr>
						<?php
					}
				}
				MainWP_DB::free_result( $websites );
				?>
			<?php else : ?>
			<tr><td colspan="999"><?php esc_html_e( 'No ignored abandoned themes.', 'mainwp' ); ?></td></tr>
			<?php endif; ?>
			</tbody>
			<?php if ( mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
				<?php if ( 0 < $cnt ) : ?>
				<tfoot class="full-width">
					<tr>
						<th colspan="999">
							<a class="ui right floated small green labeled icon button" onClick="return updatesoverview_themes_unignore_abandoned_detail_all();" id="mainwp-unignore-detail-all">
								<i class="check icon"></i> <?php esc_html_e( 'Unignore All', 'mainwp' ); ?>
							</a>
						</th>
					</tr>
				</tfoot>
				<?php endif; ?>
			<?php endif; ?>
		</table>	
		<?php
	}

	/** This is the Bulk Method to Trust A Theme. */
	public static function trust_post() {
		$userExtension = MainWP_DB::instance()->get_user_extension();
		$trustedThemes = json_decode( $userExtension->trusted_themes, true );
		if ( ! is_array( $trustedThemes ) ) {
			$trustedThemes = array();
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
				$idx = array_search( urldecode( $slug ), $trustedThemes );
				if ( false === $idx ) {
					$trustedThemes[] = urldecode( $slug );
				}
			}
		} elseif ( 'untrust' === $action ) {
			foreach ( $slugs as $slug ) {
				if ( in_array( urldecode( $slug ), $trustedThemes ) ) {
					$trustedThemes = array_diff( $trustedThemes, array( urldecode( $slug ) ) );
				}
			}
		}
		$userExtension->trusted_themes = wp_json_encode( $trustedThemes );
		MainWP_DB::instance()->update_user_extension( $userExtension );
	}

	/** This Method Saves a Trusted theme note. */
	public static function save_trusted_theme_note() {
		$slug               = urldecode( $_POST['slug'] );
		$note               = stripslashes( $_POST['note'] );
		$esc_note           = MainWP_Utility::esc_content( $note );
		$userExtension      = MainWP_DB::instance()->get_user_extension();
		$trustedThemesNotes = json_decode( $userExtension->trusted_themes_notes, true );
		if ( ! is_array( $trustedThemesNotes ) ) {
			$trustedThemesNotes = array();
		}
		$trustedThemesNotes[ $slug ]         = $esc_note;
		$userExtension->trusted_themes_notes = wp_json_encode( $trustedThemesNotes );
		MainWP_DB::instance()->update_user_extension( $userExtension );
	}

	/** Hook the section help content to the Help Sidebar element */
	public static function mainwp_help_content() {
		if ( isset( $_GET['page'] ) && ( 'ThemesManage' === $_GET['page'] || 'ThemesInstall' === $_GET['page'] || 'ThemesAutoUpdate' === $_GET['page'] || 'ThemesIgnore' === $_GET['page'] || 'ThemesIgnoredAbandoned' === $_GET['page'] ) ) {
			?>
			<p><?php esc_html_e( 'If you need help with managing themes, please review following help documents', 'mainwp' ); ?></p>
			<div class="ui relaxed bulleted list">
				<div class="item"><a href="https://mainwp.com/help/docs/managing-themes-with-mainwp/" target="_blank">Managing Themes with MainWP</a></div>
				<div class="item"><a href="https://mainwp.com/help/docs/managing-themes-with-mainwp/install-themes/" target="_blank">Install Themes</a></div>
				<div class="item"><a href="https://mainwp.com/help/docs/managing-themes-with-mainwp/activate-themes/" target="_blank">Activate Themes</a></div>
				<div class="item"><a href="https://mainwp.com/help/docs/managing-themes-with-mainwp/delete-themes/" target="_blank">Delete Themes</a></div>
				<div class="item"><a href="https://mainwp.com/help/docs/managing-themes-with-mainwp/abandoned-themes/" target="_blank">Abandoned Themes</a></div>
				<div class="item"><a href="https://mainwp.com/help/docs/managing-themes-with-mainwp/update-themes/" target="_blank">Update Themes</a></div>
				<div class="item"><a href="https://mainwp.com/help/docs/managing-themes-with-mainwp/themes-auto-updates/" target="_blank">Themes Auto Updates</a></div>
				<div class="item"><a href="https://mainwp.com/help/docs/managing-themes-with-mainwp/ignore-theme-updates/" target="_blank">Ignore Theme Updates</a></div>
			</div>
			<?php
		}
	}

}
