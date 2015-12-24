<?php

/**
 * @see MainWP_Install_Bulk
 */
class MainWP_Themes {
	public static function getClassName() {
		return __CLASS__;
	}

	public static $subPages;

	public static function init() {
		add_action( 'mainwp-pageheader-themes', array( MainWP_Themes::getClassName(), 'renderHeader' ) );
		add_action( 'mainwp-pagefooter-themes', array( MainWP_Themes::getClassName(), 'renderFooter' ) );
	}

	public static function initMenu() {

		add_submenu_page( 'mainwp_tab', __( 'Themes', 'mainwp' ), '<span id="mainwp-Themes">' . __( 'Themes', 'mainwp' ) . '</span>', 'read', 'ThemesManage', array(
			MainWP_Themes::getClassName(),
			'render',
		) );
		add_submenu_page( 'mainwp_tab', __( 'Themes', 'mainwp' ), '<div class="mainwp-hidden">' . __( 'Install', 'mainwp' ) . '</div>', 'read', 'ThemesInstall', array(
			MainWP_Themes::getClassName(),
			'renderInstall',
		) );
		add_submenu_page( 'mainwp_tab', __( 'Themes', 'mainwp' ), '<div class="mainwp-hidden">' . __( 'Auto Updates', 'mainwp' ) . '</div>', 'read', 'ThemesAutoUpdate', array(
			MainWP_Themes::getClassName(),
			'renderAutoUpdate',
		) );
		add_submenu_page( 'mainwp_tab', __( 'Themes', 'mainwp' ), '<div class="mainwp-hidden">' . __( 'Ignored Updates', 'mainwp' ) . '</div>', 'read', 'ThemesIgnore', array(
			MainWP_Themes::getClassName(),
			'renderIgnore',
		) );
		add_submenu_page( 'mainwp_tab', __( 'Themes', 'mainwp' ), '<div class="mainwp-hidden">' . __( 'Ignored Conflicts', 'mainwp' ) . '</div>', 'read', 'ThemesIgnoredConflicts', array(
			MainWP_Themes::getClassName(),
			'renderIgnoredConflicts',
		) );
		add_submenu_page( 'mainwp_tab', __( 'Themes', 'mainwp' ), '<div class="mainwp-hidden">' . __( 'Ignored Abandoned', 'mainwp' ) . '</div>', 'read', 'ThemesIgnoredAbandoned', array(
			MainWP_Themes::getClassName(),
			'renderIgnoredAbandoned',
		) );
		add_submenu_page( 'mainwp_tab', __( 'Themes Help', 'mainwp' ), '<div class="mainwp-hidden">' . __( 'Themes Help', 'mainwp' ) . '</div>', 'read', 'ThemesHelp', array(
			MainWP_Themes::getClassName(),
			'QSGManageThemes',
		) );

		self::$subPages = apply_filters( 'mainwp-getsubpages-themes', array() );
		if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
			foreach ( self::$subPages as $subPage ) {
				add_submenu_page( 'mainwp_tab', $subPage['title'], '<div class="mainwp-hidden">' . $subPage['title'] . '</div>', 'read', 'Themes' . $subPage['slug'], $subPage['callback'] );
			}
		}
	}

	public static function initMenuSubPages() {
		?>
		<div id="menu-mainwp-Themes" class="mainwp-submenu-wrapper">
			<div class="wp-submenu sub-open" style="">
				<div class="mainwp_boxout">
					<div class="mainwp_boxoutin"></div>
					<a href="<?php echo admin_url( 'admin.php?page=ThemesManage' ); ?>" class="mainwp-submenu"><?php _e( 'Manage Themes', 'mainwp' ); ?></a>
					<?php if ( mainwp_current_user_can( 'dashboard', 'install_themes' ) ) { ?>
						<a href="<?php echo admin_url( 'admin.php?page=ThemesInstall' ); ?>" class="mainwp-submenu"><?php _e( 'Install', 'mainwp' ); ?></a>
					<?php } ?>
					<a href="<?php echo admin_url( 'admin.php?page=ThemesAutoUpdate' ); ?>" class="mainwp-submenu"><?php _e( 'Auto Updates', 'mainwp' ); ?></a>
					<a href="<?php echo admin_url( 'admin.php?page=ThemesIgnore' ); ?>" class="mainwp-submenu"><?php _e( 'Ignored Updates', 'mainwp' ); ?></a>
					<a href="<?php echo admin_url( 'admin.php?page=ThemesIgnoredConflicts' ); ?>" class="mainwp-submenu"><?php _e( 'Ignored Conflicts', 'mainwp' ); ?></a>
					<a href="<?php echo admin_url( 'admin.php?page=ThemesIgnoredAbandoned' ); ?>" class="mainwp-submenu"><?php _e( 'Ignored Abandoned', 'mainwp' ); ?></a>
					<?php
					if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
						foreach ( self::$subPages as $subPage ) {
							?>
							<a href="<?php echo admin_url( 'admin.php?page=Themes' . $subPage['slug'] ); ?>" class="mainwp-submenu"><?php echo $subPage['title']; ?></a>
							<?php
						}
					}
					?>
				</div>
			</div>
		</div>
		<?php
	}

	public static function renderHeader( $shownPage ) {
		?>
		<div class="wrap">
		<a href="https://mainwp.com" id="mainwplogo" title="MainWP" target="_blank"><img src="<?php echo plugins_url( 'images/logo.png', dirname( __FILE__ ) ); ?>" height="50" alt="MainWP"/></a>
		<h2><i class="fa fa-paint-brush"></i> <?php _e( 'Themes', 'mainwp' ); ?></h2>
		<div style="clear: both;"></div><br/>
		<div id="mainwp-tip-zone">
			<?php if ( $shownPage == 'Manage' ) { ?>
				<?php if ( MainWP_Utility::showUserTip( 'mainwp-managethemes-tips' ) ) { ?>
					<div class="mainwp-tips mainwp_info-box-blue">
						<span class="mainwp-tip" id="mainwp-managethemes-tips"><strong><?php _e( 'MainWP Tip', 'mainwp' ); ?>: </strong><?php _e( 'You can also quickly activate and deactivate installed Themes for a single site from your Individual Site Dashboard Theme widget by visiting Sites &rarr; Manage Sites &rarr; Child Site &rarr; Dashboard.', 'mainwp' ); ?></span><span><a href="#" class="mainwp-dismiss"><i class="fa fa-times-circle"></i> <?php _e( 'Dismiss', 'mainwp' ); ?>
							</a></span></div>
				<?php } ?>
			<?php } ?>
			<?php if ( $shownPage == 'Install' ) { ?>
				<?php if ( MainWP_Utility::showUserTip( 'mainwp-installthemes-tips' ) ) { ?>
					<div class="mainwp-tips mainwp_info-box-blue">
						<span class="mainwp-tip" id="mainwp-installthemes-tips"><strong><?php _e( 'MainWP Tip', 'mainwp' ); ?>: </strong><?php _e( 'If you check the "Overwrite Existing" option while installing a theme you can easily update or rollback the theme on your child sites.', 'mainwp' ); ?></span><span><a href="#" class="mainwp-dismiss"><i class="fa fa-times-circle"></i> <?php _e( 'Dismiss', 'mainwp' ); ?>
							</a></span></div>
				<?php } ?>
			<?php } ?>
		</div>
		<div class="mainwp-tabs" id="mainwp-tabs">
			<a class="nav-tab pos-nav-tab <?php if ( $shownPage == 'Manage' ) {
				echo 'nav-tab-active';
			} ?>" href="admin.php?page=ThemesManage"><?php _e( 'Manage', 'mainwp' ); ?></a>
			<?php if ( mainwp_current_user_can( 'dashboard', 'install_themes' ) ) { ?>
				<a class="nav-tab pos-nav-tab <?php if ( $shownPage == 'Install' ) {
					echo 'nav-tab-active';
				} ?>" href="admin.php?page=ThemesInstall"><?php _e( 'Install', 'mainwp' ); ?></a>
			<?php } ?>
			<a class="nav-tab pos-nav-tab <?php if ( $shownPage == 'AutoUpdate' ) {
				echo 'nav-tab-active';
			} ?>" href="admin.php?page=ThemesAutoUpdate"><?php _e( 'Auto Updates', 'mainwp' ); ?></a>
			<a class="nav-tab pos-nav-tab <?php if ( $shownPage == 'Ignore' ) {
				echo 'nav-tab-active';
			} ?>" href="admin.php?page=ThemesIgnore"><?php _e( 'Ignored Updates', 'mainwp' ); ?></a>
			<a class="nav-tab pos-nav-tab <?php if ( $shownPage == 'IgnoredConflicts' ) {
				echo 'nav-tab-active';
			} ?>" href="admin.php?page=ThemesIgnoredConflicts"><?php _e( 'Ignored Conflicts', 'mainwp' ); ?></a>
			<a class="nav-tab pos-nav-tab <?php if ( $shownPage == 'IgnoreAbandoned' ) {
				echo 'nav-tab-active';
			} ?>" href="admin.php?page=ThemesIgnoredAbandoned"><?php _e( 'Ignored Abandoned', 'mainwp' ); ?></a>
			<a style="float: right" class="mainwp-help-tab nav-tab pos-nav-tab <?php if ( $shownPage === 'ThemesHelp' ) {
				echo 'nav-tab-active';
			} ?>" href="admin.php?page=ThemesHelp"><?php _e( 'Help', 'mainwp' ); ?></a>
			<?php
			if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
				foreach ( self::$subPages as $subPage ) {
					?>
					<a class="nav-tab pos-nav-tab <?php if ( $shownPage === $subPage['slug'] ) {
						echo 'nav-tab-active';
					} ?>" href="admin.php?page=Themes<?php echo $subPage['slug']; ?>"><?php echo $subPage['title']; ?></a>
					<?php
				}
			}
			?>
			<div class="clear"></div>
		</div>
		<div id="mainwp_wrap-inside">
		<?php
	}

	public static function renderFooter( $shownPage ) {
		?>
		</div>
		</div>
		<?php
	}

	public static function render() {
		$cachedSearch = MainWP_Cache::getCachedContext( 'Themes' );
		self::renderHeader( 'Manage' ); ?>
		<div class="mainwp-search-form">
			<div class="postbox mainwp-postbox">
				<h3 class="mainwp_box_title"><i class="fa fa-binoculars"></i> <?php _e( 'Step 1: Search Themes', 'mainwp' ); ?>
				</h3>

				<div class="inside">
					<div class="mainwp_info-box-blue">
						<?php _e( 'To only <strong>View or Ignore</strong> themes select <strong>All Themes</strong>', 'mainwp' ); ?>
						<br/>
						<?php _e( 'To <strong>Activate</strong> or <strong>Delete</strong> a Theme select <strong>Inactive</strong> (A theme needs to be Deactivated in order for it to be Enabled)', 'mainwp' ); ?>
						<br/>
					</div>
					<p>
						<?php _e( 'Status:', 'mainwp' ); ?><br/>
						<select name="mainwp_theme_search_by_status" id="mainwp_theme_search_by_status">
							<option value="active" <?php if ( $cachedSearch != null && $cachedSearch['the_status'] == 'active' ) {
								echo 'selected';
							} ?>><?php _e( 'Active', 'mainwp' ); ?></option>
							<option value="inactive" <?php if ( $cachedSearch != null && $cachedSearch['the_status'] == 'inactive' ) {
								echo 'selected';
							} ?>><?php _e( 'Inactive', 'mainwp' ); ?></option>
							<option value="all" <?php if ( $cachedSearch != null && $cachedSearch['the_status'] == 'all' ) {
								echo 'selected';
							} ?>><?php _e( 'All Themes', 'mainwp' ); ?></option>
						</select>
					</p>
					<p>
						<?php _e( 'Containing Keyword:', 'mainwp' ); ?><br/>
						<input type="text" id="mainwp_theme_search_by_keyword" class="" size="50" value="<?php if ( $cachedSearch != null ) {
							echo $cachedSearch['keyword'];
						} ?>"/>
					</p>
				</div>
			</div>
			<?php MainWP_UI::select_sites_box( __( 'Step 2: Select Sites', 'mainwp' ), 'checkbox', true, true, 'mainwp_select_sites_box_left' ); ?>
			<div style="clear: both;"></div>
			<input type="button" name="mainwp_show_themes" id="mainwp_show_themes" class="button-primary button button-hero button-right" value="<?php _e( 'Show Themes', 'mainwp' ); ?>"/>
			<br /><br />
			<span id="mainwp_themes_loading" class="mainwp-grabbing-info-note"> <i class="fa fa-spinner fa-pulse"></i> <em><?php _e( 'Grabbing information from Child Sites', 'mainwp' ) ?></em></span>
			<span id="mainwp_themes_loading_info" class="mainwp-grabbing-info-note"> - <?php _e( 'Automatically refreshing to get up to date information.', 'mainwp' ); ?></span>
			<br/><br/>
		</div>
		<div class="clear"></div>

		<div id="mainwp_themes_error"></div>
		<div id="mainwp_themes_main" <?php if ( $cachedSearch != null ) {
			echo 'style="display: block;"';
		} ?>>
			<div id="mainwp_themes_content">
				<?php MainWP_Cache::echoBody( 'Themes' ); ?>
			</div>
		</div>
		<?php
		if ( $cachedSearch != null ) {
			echo '<script>mainwp_themes_all_table_reinit();</script>';
		}
		self::renderFooter( 'Manage' );
	}

	public static function renderTable( $keyword, $status, $groups, $sites ) {
		MainWP_Cache::initCache( 'Themes' );

		$output         = new stdClass();
		$output->errors = array();
		$output->themes = array();

		if ( get_option( 'mainwp_optimize' ) == 1 ) {
			//Search in local cache
			if ( $sites != '' ) {
				foreach ( $sites as $k => $v ) {
					if ( MainWP_Utility::ctype_digit( $v ) ) {
						$website   = MainWP_DB::Instance()->getWebsiteById( $v );
						$allThemes = json_decode( $website->themes, true );
						for ( $i = 0; $i < count( $allThemes ); $i ++ ) {
							$theme = $allThemes[ $i ];

							if ( $status == 'active' || $status == 'inactive' ) {
								if ( $theme['active'] == 1 && $status !== 'active' ) {
									continue;
								} else if ( $theme['active'] != 1 && $status !== 'inactive' ) {
									continue;
								}
							}

							if ( $keyword != '' && ! stristr( $theme['title'], $keyword ) ) {
								continue;
							}

							$theme['websiteid']  = $website->id;
							$theme['websiteurl'] = $website->url;
							$output->themes[]    = $theme;
						}
					}
				}
			}

			if ( $groups != '' ) {
				foreach ( $groups as $k => $v ) {
					if ( MainWP_Utility::ctype_digit( $v ) ) {
						$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesByGroupId( $v ) );
						while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
							if ( $website->sync_errors != '' ) {
								continue;
							}
							$allThemes = json_decode( $website->themes, true );
							for ( $i = 0; $i < count( $allThemes ); $i ++ ) {
								$theme = $allThemes[ $i ];
								if ( $status == 'active' || $status == 'inactive' ) {
									if ( $theme['active'] == 1 && $status !== 'active' ) {
										continue;
									} else if ( $theme['active'] != 1 && $status !== 'inactive' ) {
										continue;
									}
								}
								if ( $keyword != '' && ! stristr( $theme['title'], $keyword ) ) {
									continue;
								}

								$theme['websiteid']  = $website->id;
								$theme['websiteurl'] = $website->url;
								$output->themes[]    = $theme;
							}
						}
						@MainWP_DB::free_result( $websites );
					}
				}
			}
		} else {
			//Fetch all!
			//Build websites array
			$dbwebsites = array();

			if ( $sites != '' ) {
				foreach ( $sites as $k => $v ) {
					if ( MainWP_Utility::ctype_digit( $v ) ) {
						$website                    = MainWP_DB::Instance()->getWebsiteById( $v );
						$dbwebsites[ $website->id ] = MainWP_Utility::mapSite( $website, array(
							'id',
							'url',
							'name',
							'adminname',
							'nossl',
							'privkey',
							'nosslkey',
						) );
					}
				}
			}

			if ( $groups != '' ) {
				foreach ( $groups as $k => $v ) {
					if ( MainWP_Utility::ctype_digit( $v ) ) {
						$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesByGroupId( $v ) );
						while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
							if ( $website->sync_errors != '' ) {
								continue;
							}
							$dbwebsites[ $website->id ] = MainWP_Utility::mapSite( $website, array(
								'id',
								'url',
								'name',
								'adminname',
								'nossl',
								'privkey',
								'nosslkey',
							) );
						}
						@MainWP_DB::free_result( $websites );
					}
				}
			}

			$post_data = array(
				'keyword' => $keyword,
			);

			if ( $status == 'active' || $status == 'inactive' ) {
				$post_data['status'] = $status;
				$post_data['filter'] = true;
			} else {
				$post_data['status'] = '';
				$post_data['filter'] = false;
			}

			MainWP_Utility::fetchUrlsAuthed( $dbwebsites, 'get_all_themes', $post_data, array(
				MainWP_Themes::getClassName(),
				'ThemesSearch_handler',
			), $output );

			if ( count( $output->errors ) > 0 ) {
				foreach ( $output->errors as $siteid => $error ) {
					echo '<strong>Error on ' . MainWP_Utility::getNiceURL( $dbwebsites[ $siteid ]->url ) . ': ' . $error . ' <br /></strong>';
				}
				echo '<br />';
			}

			if ( count( $output->errors ) == count( $dbwebsites ) ) {
				return;
			}
		}

		MainWP_Cache::addContext( 'Themes', array( 'keyword' => $keyword, 'the_status' => $status ) );

		ob_start();
		?>

		<div class="alignleft">
			<select name="bulk_action" id="mainwp_bulk_action">
				<option value="none"><?php _e( 'Choose Action', 'mainwp' ); ?></option>
				<?php if ( $status == 'inactive' ) { ?>
					<?php if ( mainwp_current_user_can( 'dashboard', 'activate_themes' ) ) { ?>
						<option value="activate"><?php _e( 'Activate', 'mainwp' ); ?></option>
					<?php } ?>
					<?php if ( mainwp_current_user_can( 'dashboard', 'delete_themes' ) ) { ?>
						<option value="delete"><?php _e( 'Delete', 'mainwp' ); ?></option>
					<?php } ?>
				<?php } ?>
				<?php if ( mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) { ?>
					<option value="ignore_updates"><?php _e( 'Ignore Updates', 'mainwp' ); ?></option>
				<?php } ?>
			</select>
			<input type="button" name="" id="mainwp_bulk_theme_action_apply" class="button" value="<?php _e( 'Confirm', 'mainwp' ); ?>"/>
			<span id="mainwp_bulk_action_loading"><i class="fa fa-spinner fa-pulse"></i></span>
		</div>
		<div class="clear"></div>


		<?php
		if ( count( $output->themes ) == 0 ) {
			?>
			No themes found
			<?php
			$newOutput = ob_get_clean();
			echo $newOutput;
			MainWP_Cache::addBody( 'Themes', $newOutput );

			return;
		}

		//Map per siteId
		$sites             = array(); //id -> url
		$siteThemes        = array(); //site_id -> theme_version_name -> theme obj
		$themes            = array(); //name_version -> name
		$themesVersion     = array(); //name_version -> title_version
		$themesRealVersion = $themesSlug = array(); //name_version -> title_version
		foreach ( $output->themes as $theme ) {
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
		?>
		<div id="mainwp-table-overflow" style="overflow: auto !important ;">
			<table class="wp-list-table widefat fixed pages" id="themes_fixedtable" style="width: auto; word-wrap: normal">
				<thead>
				<tr>
					<th class="headcol" style="text-align: center; border-bottom: 1px Solid #e1e1e1; font-size: 18px; z-index:999; padding: auto; width: 15em !important;"><?php _e( 'Child Site / Theme', 'mainwp' ); ?>
						<p style="font-size: 10px; line-height: 12px;"><?php _e( 'Click on the Theme Name to select the theme on all sites or click the Site URL to select all themes on the site.', 'mainwp' ); ?></p>
					</th>
					<?php
					foreach ( $themesVersion as $theme_name => $theme_title ) {
						?>
						<th height="100" style="padding: 5px;">
							<div style="max-width: 120px; text-align: center;" title="<?php echo $theme_title; ?>" >
								<input type="checkbox" value="<?php echo $themes[$theme_name]; ?>" id="<?php echo $theme_name; ?>" version="<?php echo $themesRealVersion[$theme_name]; ?>" class="mainwp_theme_check_all" style="display: none ;" />
								<label for="<?php echo $theme_name; ?>"><?php echo $theme_title; ?></label>
							</div>
						</th>
						<?php
					}
					?>
				</tr>
				</thead>
				<tbody>
				<?php
				foreach ( $sites as $site_id => $site_url ) {
					?>
					<tr>
						<td class="headcol">
							<input class="websiteId" type="hidden" name="id" value="<?php echo $site_id; ?>"/>
							<label for="<?php echo $site_url; ?>"><?php echo $site_url; ?></label>
							<input type="checkbox" value="" id="<?php echo $site_url; ?>" class="mainwp_site_check_all" style="display: none ;"/>
						</td>
						<?php
						foreach ( $themesVersion as $theme_name => $theme_title ) {
							echo '<td style="text-align: center">';
							if ( isset( $siteThemes[ $site_id ] ) && isset( $siteThemes[ $site_id ][ $theme_name ] ) ) {
								echo '<input type="checkbox" value="' . $themes[ $theme_name ] . '" version="' . $themesRealVersion[ $theme_name ] . '" slug="' . $themesSlug[ $theme_name ] . '" class="selected_theme" />';
							}
							echo '</td>';
						}
						?>
					</tr>
					<?php
				}
				?>
				</tbody>
			</table>
		</div>
		<script type="text/javascript">
			jQuery(document).ready(function() {
				jQuery("#themes_fixedtable").tableHeadFixer({"left" : 1});
			});
		</script>
		<?php
		$newOutput = ob_get_clean();
		echo $newOutput;
		MainWP_Cache::addBody( 'Themes', $newOutput );
	}

	public static function renderAllThemesTable( $output = null ) {
		$keyword       = null;
		$search_status = 'all';
		if ( $output == null ) {
			$keyword             = isset( $_POST['keyword'] ) && ! empty( $_POST['keyword'] ) ? trim( $_POST['keyword'] ) : null;
			$search_status       = isset( $_POST['status'] ) ? $_POST['status'] : 'all';
			$search_theme_status = isset( $_POST['theme_status'] ) ? $_POST['theme_status'] : 'all';

			$output         = new stdClass();
			$output->errors = array();
			$output->themes = array();

			if ( get_option( 'mainwp_optimize' ) == 1 ) {
				//Fetch all!
				//Build websites array
				//Search in local cache
				$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser() );
				while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
					$allThemes = json_decode( $website->themes, true );
					for ( $i = 0; $i < count( $allThemes ); $i ++ ) {
						$theme = $allThemes[ $i ];
						if ( $search_theme_status != 'all' ) {
							if ( $theme['active'] == 1 && $search_theme_status !== 'active' ) {
								continue;
							} else if ( $theme['active'] != 1 && $search_theme_status !== 'inactive' ) {
								continue;
							}
						}

						if ( $keyword != '' && stristr( $theme['name'], $keyword ) === false ) {
							continue;
						}

						$theme['websiteid']  = $website->id;
						$theme['websiteurl'] = $website->url;
						$output->themes[]    = $theme;
					}
				}
				@MainWP_DB::free_result( $websites );
			} else {
				//Fetch all!
				//Build websites array
				$dbwebsites = array();
				$websites   = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser() );
				while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
					$dbwebsites[ $website->id ] = MainWP_Utility::mapSite( $website, array(
						'id',
						'url',
						'name',
						'adminname',
						'nossl',
						'privkey',
						'nosslkey',
					) );
				}
				@MainWP_DB::free_result( $websites );

				$post_data = array(
					'keyword' => $keyword,
				);

				if ( $search_theme_status == 'active' || $search_theme_status == 'inactive' ) {
					$post_data['status'] = $search_theme_status;
					$post_data['filter'] = true;
				} else {
					$post_data['status'] = '';
					$post_data['filter'] = false;
				}

				MainWP_Utility::fetchUrlsAuthed( $dbwebsites, 'get_all_themes', $post_data, array(
					MainWP_Themes::getClassName(),
					'ThemesSearch_handler',
				), $output );

				if ( count( $output->errors ) > 0 ) {
					foreach ( $output->errors as $siteid => $error ) {
						echo '<strong>Error on ' . MainWP_Utility::getNiceURL( $dbwebsites[ $siteid ]->url ) . ': ' . $error . ' <br /></strong>';
					}
					echo '<br />';
				}

				if ( count( $output->errors ) == count( $dbwebsites ) ) {
					session_start();
					$_SESSION['SNThemesAll'] = $output;

					return;
				}
			}

			if ( session_id() == '' ) {
				session_start();
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

		if ( count( $output->themes ) == 0 ) {
			?>
			No themes found
			<?php
			return;
		}

		?>
		<div class="alignleft">
			<select name="bulk_action" id="mainwp_bulk_action">
				<option value="none"><?php _e( 'Choose Action', 'mainwp' ); ?></option>
				<option value="trust"><?php _e( 'Trust', 'mainwp' ); ?></option>
				<option value="untrust"><?php _e( 'Untrust', 'mainwp' ); ?></option>
			</select>
			<input type="button" name="" id="mainwp_bulk_trust_themes_action_apply" class="button" value="<?php _e( 'Confirm', 'mainwp' ); ?>"/>
			<span id="mainwp_bulk_action_loading"><i class="fa fa-spinner fa-pulse"></i></span>
		</div>
		<div class="clear"></div>
		<?php

		//Map per siteId
		$themes = array(); //name_version -> slug
		foreach ( $output->themes as $theme ) {
			$themes[ $theme['slug'] ] = $theme;
		}
		asort( $themes );

		$userExtension        = MainWP_DB::Instance()->getUserExtension();
		$decodedIgnoredThemes = json_decode( $userExtension->ignored_themes, true );
		$trustedThemes        = json_decode( $userExtension->trusted_themes, true );
		if ( ! is_array( $trustedThemes ) ) {
			$trustedThemes = array();
		}
		$trustedThemesNotes = json_decode( $userExtension->trusted_themes_notes, true );
		if ( ! is_array( $trustedThemesNotes ) ) {
			$trustedThemesNotes = array();
		}

		?>
		<table id="mainwp_themes_all_table" class="wp-list-table widefat fixed posts tablesorter" cellspacing="0">
			<thead>
			<tr>
				<th scope="col" id="cb" class="manage-column column-cb check-column" style="">
					<input name="themes" type="checkbox"></th>
				<th scope="col" id="info" class="manage-column column-cb check-column" style=""></th>
				<th scope="col" id="theme" class="manage-column column-title sortable desc" style="">
					<a href="#"><span><?php _e( 'Theme', 'mainwp' ); ?></span><span class="sorting-indicator"></span></a>
				</th>
				<th scope="col" id="thmstatus" class="manage-column column-title sortable desc" style="">
					<a href="#"><span><?php _e( 'Status', 'mainwp' ); ?></span><span class="sorting-indicator"></span></a>
				</th>
				<th scope="col" id="trustlvl" class="manage-column column-title sortable desc" style="">
					<a href="#"><span><?php _e( 'Trust Level', 'mainwp' ); ?></span><span class="sorting-indicator"></span></a>
				</th>
				<th scope="col" id="ignoredstatus" class="manage-column column-title sortable desc" style="">
					<a href="#"><span><?php _e( 'Ignored Status', 'mainwp' ); ?></span><span class="sorting-indicator"></span></a>
				</th>
				<th scope="col" id="notes" class="manage-column column-posts" style=""><?php _e( 'Notes', 'mainwp' ); ?></th>
			</tr>
			</thead>

			<tfoot>
			<tr>
				<th scope="col" class="manage-column column-cb check-column" style="">
					<input name="themes" type="checkbox"></th>
				<th scope="col" id="info_footer" class="manage-column column-cb check-column" style=""></th>
				<th scope="col" id="theme_footer" class="manage-column column-title sortable desc" style="">
					<span><?php _e( 'Theme', 'mainwp' ); ?></span></th>
				<th scope="col" id="thmstatus_footer" class="manage-column column-posts" style=""><?php _e( 'Status', 'mainwp' ); ?></th>
				<th scope="col" id="trustlvl_footer" class="manage-column column-posts" style=""><?php _e( 'Trust Level', 'mainwp' ); ?></th>
				<th scope="col" id="ignoredstatus_footer" class="manage-column column-posts" style=""><?php _e( 'Ignored Status', 'mainwp' ); ?></th>
				<th scope="col" id="notes_footer" class="manage-column column-posts" style=""><?php _e( 'Notes', 'mainwp' ); ?></th>
			</tr>
			</tfoot>

			<tbody id="the-posts-list" class="list:posts">
			<?php
			foreach ( $themes as $slug => $theme ) {
				$name = $theme['name'];
				if ( ! empty( $search_status ) && $search_status != 'all' ) {
					if ( $search_status == 'trust' && ! in_array( $slug, $trustedThemes ) ) {
						continue;
					} else if ( $search_status == 'untrust' && in_array( $slug, $trustedThemes ) ) {
						continue;
					} else if ( $search_status == 'ignored' && ! isset( $decodedIgnoredThemes[ $slug ] ) ) {
						continue;
					}
				}
				?>
				<tr id="post-1" class="post-1 post type-post status-publish format-standard hentry category-uncategorized alternate iedit author-self" valign="top" theme_slug="<?php echo urlencode( $slug ); ?>" theme_name="<?php echo rawurlencode( $name ); ?>">
					<th scope="row" class="check-column">
						<input type="checkbox" name="theme[]" value="<?php echo urlencode( $slug ); ?>"></th>
					<td scope="col" id="info_content" class="manage-column" style=""> <?php if ( isset( $decodedIgnoredThemes[ $slug ] ) ) {
							MainWP_Utility::renderToolTip( 'Ignored themes will NOT be auto-updated.', null, 'images/icons/mainwp-red-info-16.png' );
						} ?></td>
					<td scope="col" id="theme_content" class="manage-column sorted" style="">
						<?php echo $name; ?>
					</td>
					<td scope="col" id="plgstatus_content" class="manage-column" style="">
						<?php echo ( $theme['active'] == 1 ) ? __( 'Active', 'mainwp' ) : __( 'Inactive', 'mainwp' ); ?>
					</td>
					<td scope="col" id="trustlvl_content" class="manage-column" style="">
						<?php
						if ( in_array( $slug, $trustedThemes ) ) {
							echo '<font color="#7fb100">Trusted</font>';
						} else {
							echo '<font color="#c00">Not Trusted</font>';
						}
						?>
					</td>
					<td scope="col" id="ignoredstatus_content" class="manage-column" style="">
						<?php if ( isset( $decodedIgnoredThemes[ $slug ] ) ) {
							echo '<font color="#c00">Ignored</font>';
						} ?>
					</td>
					<td scope="col" id="notes_content" class="manage-column" style="">
						<img src="<?php echo plugins_url( 'images/notes.png', dirname( __FILE__ ) ); ?>" class="mainwp_notes_img" <?php if ( ! isset( $trustedThemesNotes[ $slug ] ) || $trustedThemesNotes[ $slug ] == '' ) {
							echo 'style="display: none;"';
						} ?> />
						<a href="#" class="mainwp_trusted_theme_notes_show"><i class="fa fa-pencil"></i> <?php _e( 'Open', 'mainwp' ); ?>
						</a>

						<div style="display: none" class="note"><?php if ( isset( $trustedThemesNotes[ $slug ] ) ) {
								echo $trustedThemesNotes[ $slug ];
							} ?></div>
					</td>
				</tr>
				<?php
			}
			?>
			</tbody>
		</table>
		<div id="mainwp_notes_overlay" class="mainwp_overlay"></div>
		<div id="mainwp_notes" class="mainwp_popup">
			<a id="mainwp_notes_closeX" class="mainwp_closeX" style="display: inline; "></a>

			<div id="mainwp_notes_title" class="mainwp_popup_title"></span>
			</div>
			<div id="mainwp_notes_content">
                <textarea style="width: 580px !important; height: 300px;"
	                id="mainwp_notes_note"></textarea>
			</div>
			<form>
				<div style="float: right" id="mainwp_notes_status"></div>
				<input type="button" class="button cont button-primary" id="mainwp_trusted_theme_notes_save" value="<?php _e( 'Save Note', 'mainwp' ); ?>"/>
				<input type="button" class="button cont" id="mainwp_notes_cancel" value="<?php _e( 'Close', 'mainwp' ); ?>"/>
				<input type="hidden" id="mainwp_notes_slug" value=""/>
			</form>
		</div>
		<div class="pager" id="pager">
			<form>
				<img src="<?php echo plugins_url( 'images/first.png', dirname( __FILE__ ) ); ?>" class="first">
				<img src="<?php echo plugins_url( 'images/prev.png', dirname( __FILE__ ) ); ?>" class="prev">
				<input type="text" class="pagedisplay">
				<img src="<?php echo plugins_url( 'images/next.png', dirname( __FILE__ ) ); ?>" class="next">
				<img src="<?php echo plugins_url( 'images/last.png', dirname( __FILE__ ) ); ?>" class="last">
				<span>&nbsp;&nbsp;<?php _e( 'Show:', 'mainwp' ); ?> </span><select class="pagesize">
					<option selected="selected" value="10">10</option>
					<option value="20">20</option>
					<option value="30">30</option>
					<option value="40">40</option>
				</select><span> <?php _e( 'Plugins per page', 'mainwp' ); ?></span>
			</form>
		</div>

		<?php
	}

	public static function ThemesSearch_handler( $data, $website, &$output ) {
		if ( preg_match( '/<mainwp>(.*)<\/mainwp>/', $data, $results ) > 0 ) {
			$themes = unserialize( base64_decode( $results[1] ) );
			unset( $results );
			if ( isset( $themes['error'] ) ) {
				$output->errors[ $website->id ] = MainWP_Error_Helper::getErrorMessage( new MainWP_Exception( $themes['error'], $website->url ) );

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
			$output->errors[ $website->id ] = MainWP_Error_Helper::getErrorMessage( new MainWP_Exception( 'NOMAINWP', $website->url ) );
		}
	}

	public static function activateTheme() {
		MainWP_Themes::action( 'activate', $_POST['theme'] );
		die( 'SUCCESS' );
	}

	public static function deleteThemes() {
		MainWP_Themes::action( 'delete', implode( '||', $_POST['themes'] ) );
		die( 'SUCCESS' );
	}

	public static function action( $pAction, $theme ) {
		$websiteIdEnc = $_POST['websiteId'];

		$websiteId = $websiteIdEnc;
		if ( ! MainWP_Utility::ctype_digit( $websiteId ) ) {
			die( 'FAIL' );
		}

		$website = MainWP_DB::Instance()->getWebsiteById( $websiteId );
		if ( ! MainWP_Utility::can_edit_website( $website ) ) {
			die( 'FAIL' );
		}

		try {
			$information = MainWP_Utility::fetchUrlAuthed( $website, 'theme_action', array(
				'action' => $pAction,
				'theme'  => $theme,
			) );
		} catch ( MainWP_Exception $e ) {
			die( 'FAIL' );
		}

		if ( ! isset( $information['out'] ) || ! isset( $information['status'] ) || ( $information['status'] != 'SUCCESS' ) ) {
			die( 'FAIL' );
		}

		die( $information['out'] );
	}

	public static function ignoreUpdates() {
		$websiteIdEnc = $_POST['websiteId'];

		$websiteId = $websiteIdEnc;
		if ( ! MainWP_Utility::ctype_digit( $websiteId ) ) {
			die( 'FAIL' );
		}

		$website = MainWP_DB::Instance()->getWebsiteById( $websiteId );
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
			for ( $i = 0; $i < count( $themes ); $i ++ ) {
				$slug = $themes[ $i ];
				$name = $names[ $i ];
				if ( ! isset( $decodedIgnoredThemes[ $slug ] ) ) {
					$decodedIgnoredThemes[ $slug ] = urldecode( $name );
				}
			}
			MainWP_DB::Instance()->updateWebsiteValues( $website->id, array( 'ignored_themes' => json_encode( $decodedIgnoredThemes ) ) );
		}

		die( json_encode( array( 'result' => true ) ) );
	}

	//@see MainWP_Install_Bulk
	//todo apply coding rules
	public static function renderInstall() {
		$favorites_callback = apply_filters('mainwp_favorites_links_onaction_callback', '');
		wp_enqueue_script('mainwp-theme', MAINWP_PLUGIN_URL . 'js/mainwp-theme.js', array( 'wp-backbone', 'wp-a11y' ), MAINWP_VERSION);
		wp_localize_script( 'mainwp-theme', '_mainwpThemeSettings', array(
			'themes'   => false,
			'settings' => array(
				'isInstall'     => true,
				'canInstall'    => false, //current_user_can( 'install_themes' ),
				'installURI'    => null, //current_user_can( 'install_themes' ) ? self_admin_url( 'admin.php?page=ThemesInstall' ) : null,
				'adminUrl'      => parse_url( self_admin_url(), PHP_URL_PATH )
			),
			'l10n' => array(
				'addNew' => __( 'Add New Theme' ),
				'search' => __( 'Search Themes' ),
				'searchPlaceholder' => __( 'Search themes...' ), // placeholder (no ellipsis)
				'upload' => __( 'Upload Theme' ),
				'back'   => __( 'Back' ),
				'error'  => __( 'An unexpected error occurred. Something may be wrong with WordPress.org or this server&#8217;s configuration. If you continue to have problems, please try the <a href="https://wordpress.org/support/">support forums</a>.' ),
				'themesFound'   => __( 'Number of Themes found: %d' ),
				'noThemesFound' => __( 'No themes found. Try a different search.' ),
				'collapseSidebar'    => __( 'Collapse Sidebar' ),
				'expandSidebar'      => __( 'Expand Sidebar' ),
			),
			'installedThemes' => array(),
			'favoritesOnActionCallback' => $favorites_callback
		) );

		self::renderHeader('Install');
		//MainWPInstallBulk::render('Themes', 'theme');
		self::renderThemesTable($favorites_callback);
		self::renderFooter('Install');
	}

	public static function renderThemesTable($favoritesCallback = '') {
		if (!mainwp_current_user_can("dashboard", "install_themes")) {
			mainwp_do_not_have_permissions( __( 'install themes', 'mainwp' ) );
			return;
		}

		?>
		<a href="#" class="mainwp_action left mainwp_action_down browse-themes" ><?php _e('Search','mainwp'); ?></a><a href="#" class="mainwp_action right upload" ><?php _e('Upload','mainwp'); ?></a>
		<br class="clear" /><br />

		<div class="mainwp_config_box_left" style="width: calc(100% - 290px);">
			<div class="error below-h2" style="display: none;" id="ajax-error-zone"></div>
			<div class="upload-theme">
				<?php MainWP_Install_Bulk::renderUpload('Themes'); ?>
			</div>
			<div class="wp-filter">
				<h3 class="mainwp_box_title"><?php _e( 'Step 1: Select a Theme', 'mainwp' ); ?></h3>
				<div class="filter-count">
					<span class="count theme-count"></span>
				</div>

				<ul class="filter-links">
					<li><a href="#" data-sort="featured"><?php _ex( 'Featured', 'themes' ); ?></a></li>
					<li><a href="#" data-sort="popular"><?php _ex( 'Popular', 'themes' ); ?></a></li>
					<li><a href="#" data-sort="new"><?php _ex( 'Latest', 'themes' ); ?></a></li>
				</ul>
				<a class="drawer-toggle" href="#"><?php _e( 'Feature Filter' ); ?></a>

				<div class="search-form"></div>
				<div class="filter-drawer">
					<div class="buttons">
						<a class="apply-filters button button-secondary" href="#"><?php _e( 'Apply Filters' ); ?><span></span></a>
						<a class="clear-filters button button-secondary" href="#"><?php _e( 'Clear' ); ?></a>
					</div>
					<?php
					$feature_list = get_theme_feature_list();
					foreach ( $feature_list as $feature_name => $features ) {
						echo '<div class="filter-group">';
						$feature_name = esc_html( $feature_name );
						echo '<h4>' . $feature_name . '</h4>';
						echo '<ol class="feature-group">';
						foreach ( $features as $feature => $feature_name ) {
							$feature = esc_attr( $feature );
							echo '<li><input type="checkbox" id="filter-id-' . $feature . '" value="' . $feature . '" /> ';
							echo '<label for="filter-id-' . $feature . '">' . $feature_name . '</label></li>';
						}
						echo '</ol>';
						echo '</div>';
					}
					?>
					<div class="filtered-by">
						<span><?php _e( 'Filtering by:' ); ?></span>
						<div class="tags"></div>
						<a href="#"><?php _e( 'Edit' ); ?></a>
					</div>
				</div>
			</div>
			<div class="theme-browser content-filterable hide-if-upload"></div>
			<div class="theme-install-overlay wp-full-overlay expanded"></div>

			<p class="no-themes"><?php _e( 'No themes found. Try a different search.' ); ?></p>
			<span class="spinner"></span>

			<br class="clear" />
		</div>

		<script id="tmpl-theme" type="text/template">
			<# if ( data.screenshot_url ) { #>
				<div class="theme-screenshot">
					<img src="{{ data.screenshot_url }}" alt="" />
				</div>
				<# } else { #>
					<div class="theme-screenshot blank"></div>
					<# } #>
						<span class="more-details"><?php _ex( 'Details &amp; Preview', 'theme' ); ?></span>
						<div class="theme-author"><?php printf( __( 'By %s', 'mainwp' ), '{{ data.author }}' ); ?></div>
						<h3 class="theme-name">{{ data.name }}</h3>

						<!--<div class="theme-actions">-->
						<!--<a class="button button-secondary preview install-theme-preview" href="#"><?php esc_html_e( 'Preview' ); ?></a>-->
						<!--</div>-->

						<div class="mainwp-theme-lnks" style="">
							<label class="lbl-install-theme" style="font-size: 16px;"><input name="install-theme" type="radio" id="install-theme-{{data.slug}}" title="Install {{data.name}}"><?php esc_html_e( 'Install this Theme', 'mainwp' ); ?></label>
							<?php
							if (!empty($favoritesCallback)) {
								?>
								<div class="favorites-add-link"><a style="font-size: 16px;" class="add-favorites" href="#" id="add-favorite-theme-{{data.slug}}"
																   title="{{data.name}} {{data.version}}"><?php  _e( 'Add To Favorites' ); ?></a></div>
								<?php
							}
							?>
						</div>

						<# if ( data.installed ) { #>
							<div class="theme-installed"><?php _ex( 'Already Installed', 'theme' ); ?></div>
							<# } #>
		</script>

		<script id="tmpl-theme-preview" type="text/template">
			<div class="wp-full-overlay-sidebar">
				<div class="wp-full-overlay-header">
					<a href="#" class="close-full-overlay"><span class="screen-reader-text"><?php _e( 'Close', 'mainwp' ); ?></span></a>
					<a href="#" class="previous-theme"><span class="screen-reader-text"><?php _ex( 'Previous', 'Button label for a theme' ); ?></span></a>
					<a href="#" class="next-theme"><span class="screen-reader-text"><?php _ex( 'Next', 'Button label for a theme' ); ?></span></a>
					<# if ( data.installed ) { #>
						<a href="#" class="button button-primary theme-install disabled"><?php _ex( 'Installed', 'theme' ); ?></a>
						<# } else { #>
							<a href="{{ data.install_url }}" class="button button-primary theme-install"><?php _e( 'Install' ); ?></a>
							<# } #>
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
										<small class="ratings"><?php _e( 'This theme has not been rated yet.' ); ?></small>
									</div>
									<# } #>
										<div class="theme-version"><?php printf( __( 'Version: %s', 'mainwp' ), '{{ data.version }}' ); ?></div>
										<div class="theme-description">{{{ data.description }}}</div>
						</div>
					</div>
				</div>
				<div class="wp-full-overlay-footer">
					<button type="button" class="collapse-sidebar button-secondary" aria-expanded="true" aria-label="<?php esc_attr_e( 'Collapse Sidebar' ); ?>">
						<span class="collapse-sidebar-arrow"></span>
						<span class="collapse-sidebar-label"><?php _e( 'Collapse' ); ?></span>
					</button>
				</div>
			</div>
			<div class="wp-full-overlay-main">
				<iframe src="{{ data.preview_url }}" title="<?php esc_attr_e( 'Preview' ); ?>" />
			</div>
		</script>
		<?php MainWP_UI::select_sites_box( __("Step 2: Select Sites", 'mainwp'), 'checkbox', true, true, 'mainwp_select_sites_box_right' ); ?>
		<div class="mainwp_config_box_right">
			<div class="postbox install-theme-settings hide-if-upload">
				<h3 class="mainwp_box_title"><i class="fa fa-cog"></i> <?php _e( 'Step 3: Installation Options', 'mainwp' ); ?></h3>
				<div class="inside">
					<input type="checkbox" value="2" checked id="chk_overwrite" /> <label for="chk_overwrite"><?php _e('Overwrite Existing theme, if already installed', 'mainwp'); ?></label>
				</div>
			</div>

			<input type="button" value="<?php _e( "Complete Installation", 'mainwp' ); ?>" class="button-primary button button-hero button-right hide-if-upload" id="mainwp_theme_bulk_install_btn" name="bulk-install">
		</div>
		<div style="clear: both;"></div>

		<?php
	}

	//Performs a search
	public static function performSearch() {
		MainWP_Install_Bulk::performSearch( MainWP_Themes::getClassName(), 'Themes' );
	}

	public static function renderAutoUpdate() {
		$cachedThemesSearch = null;
		if ( isset( $_SESSION['SNThemesAllStatus'] ) ) {
			$cachedThemesSearch = $_SESSION['SNThemesAllStatus'];
		}
		self::renderHeader( 'AutoUpdate' );
		if ( ! mainwp_current_user_can( 'dashboard', 'trust_untrust_updates' ) ) {
			mainwp_do_not_have_permissions( __( 'trust/untrust updates', 'mainwp' ) );

			return;
		} else {
			$snAutomaticDailyUpdate = get_option( 'mainwp_automaticDailyUpdate' );
			?>
			<div id="mainwp-au" class=""><strong><?php if ( $snAutomaticDailyUpdate == 1 ) { ?>
						<div class="mainwp-au-on"><?php _e( 'Auto Updates are ON and Trusted Plugins will be Automatically Updated', 'mainwp' ); ?> -
							<a href="<?php echo admin_url(); ?>admin.php?page=Settings"><?php _e( 'Change this in Settings', 'mainwp' ); ?></a>
						</div>
					<?php } elseif ( ( $snAutomaticDailyUpdate === false ) || ( $snAutomaticDailyUpdate == 2 ) ) { ?>
						<div class="mainwp-au-email"><?php _e( 'Auto Updates are OFF - Email Update Notification is ON', 'mainwp' ); ?> -
							<a href="<?php echo admin_url(); ?>admin.php?page=Settings"><?php _e( 'Change this in Settings', 'mainwp' ); ?></a>
						</div>
					<?php } else { ?>
						<div class="mainwp-au-off"><?php _e( 'Auto Updates are OFF - Email Update Notification is OFF', 'mainwp' ); ?> -
							<a href="<?php echo admin_url(); ?>admin.php?page=Settings"><?php _e( 'Change this in Settings', 'mainwp' ); ?></a>
						</div>
					<?php } ?></strong></div>
			<div class="mainwp_info-box"><?php _e( 'Only mark Themes as Trusted if you are absolutely sure they can be updated', 'mainwp' ); ?></div>

			<div class="postbox">
				<h3 class="mainwp_box_title"><i class="fa fa-binoculars"></i> <?php _e( 'Search Themes', 'mainwp' ); ?>
				</h3>

				<div class="inside">
					<span><?php _e( 'Status:', 'mainwp' ); ?> </span>
					<select id="mainwp_au_theme_status">
						<option value="all" <?php if ( $cachedThemesSearch != null && $cachedThemesSearch['theme_status'] == 'all' ) {
							echo 'selected';
						} ?>><?php _e( 'All Themes', 'mainwp' ); ?></option>
						<option value="active" <?php if ( $cachedThemesSearch != null && $cachedThemesSearch['theme_status'] == 'active' ) {
							echo 'selected';
						} ?>><?php _e( 'Active Themes', 'mainwp' ); ?></option>
						<option value="inactive" <?php if ( $cachedThemesSearch != null && $cachedThemesSearch['theme_status'] == 'inactive' ) {
							echo 'selected';
						} ?>><?php _e( 'Inactive Themes', 'mainwp' ); ?></option>
					</select>&nbsp;&nbsp;
					<span><?php _e( 'Trust Status:', 'mainwp' ); ?> </span>
					<select id="mainwp_au_theme_trust_status">
						<option value="all" <?php if ( $cachedThemesSearch != null && $cachedThemesSearch['status'] == 'all' ) {
							echo 'selected';
						} ?>><?php _e( 'All Themes', 'mainwp' ); ?></option>
						<option value="trust" <?php if ( $cachedThemesSearch != null && $cachedThemesSearch['status'] == 'trust' ) {
							echo 'selected';
						} ?>><?php _e( 'Trusted Themes', 'mainwp' ); ?></option>
						<option value="untrust" <?php if ( $cachedThemesSearch != null && $cachedThemesSearch['status'] == 'untrust' ) {
							echo 'selected';
						} ?>><?php _e( 'Not Trusted Themes', 'mainwp' ); ?></option>
						<option value="ignored" <?php if ( $cachedThemesSearch != null && $cachedThemesSearch['status'] == 'ignored' ) {
							echo 'selected';
						} ?>><?php _e( 'Ignored Themes', 'mainwp' ); ?></option>
					</select>&nbsp;&nbsp;
					<span><?php _e( 'Containing Keywords:', 'mainwp' ); ?> </span>
					<input type="text" class="" id="mainwp_au_theme_keyword" style="width: 350px;" value="<?php echo ( $cachedThemesSearch !== null ) ? $cachedThemesSearch['keyword'] : ''; ?>">&nbsp;&nbsp;
					<a href="#" class="button-primary" id="mainwp_show_all_themes"><?php _e( 'Show Themes', 'mainwp' ); ?></a>
					<span id="mainwp_themes_loading"><i class="fa fa-spinner fa-pulse"></i></span>
				</div>
			</div>


			<div id="mainwp_themes_main" style="display: block; margin-top: 1.5em ;">
				<div id="mainwp_themes_content">
					<?php
					if ( session_id() == '' ) {
						session_start();
					}
					if ( isset( $_SESSION['SNThemesAll'] ) ) {
						self::renderAllThemesTable( $_SESSION['SNThemesAll'] );
						echo '<script>mainwp_themes_all_table_reinit();</script>';
					}
					?>
				</div>
			</div>
			<?php
		}
		self::renderFooter( 'AutoUpdate' );
	}

	public static function renderIgnoredConflicts() {
		$websites                     = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser() );
		$userExtension                = MainWP_DB::Instance()->getUserExtension();
		$decodedIgnoredThemeConflicts = json_decode( $userExtension->ignored_themeConflicts, true );
		$ignoredThemeConflicts        = ( is_array( $decodedIgnoredThemeConflicts ) && ( count( $decodedIgnoredThemeConflicts ) > 0 ) );

		$cnt = 0;
		while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
			$tmpDecodedIgnoredThemeConflicts = json_decode( $website->ignored_themeConflicts, true );
			if ( ! is_array( $tmpDecodedIgnoredThemeConflicts ) || count( $tmpDecodedIgnoredThemeConflicts ) == 0 ) {
				continue;
			}
			$cnt ++;
		}

		self::renderHeader( 'IgnoredConflicts' );
		?>
		<table id="mainwp-table" class="wp-list-table widefat" cellspacing="0">
			<caption><?php _e( 'Globally Ignored Theme Conflict List', 'mainwp' ); ?></caption>
			<thead>
			<tr>
				<th scope="col" class="manage-column" style="width: 300px"><?php _e( 'Theme', 'mainwp' ); ?></th>
				<th scope="col" class="manage-column" style="text-align: right; padding-right: 10px"><?php if ( $ignoredThemeConflicts ) { ?>
						<a href="#" class="button-primary mainwp-unignore-globally-all" onClick="return pluginthemeconflict_unignore('theme', undefined, undefined);"><?php _e( 'Allow All', 'mainwp' ); ?></a><?php } ?>
				</th>
			</tr>
			</thead>
			<tbody id="globally-ignored-themeconflict-list" class="list:sites">
			<?php
			if ( $ignoredThemeConflicts ) {
				foreach ( $decodedIgnoredThemeConflicts as $ignoredThemeName ) {
					?>
					<tr theme="<?php echo urlencode( $ignoredThemeName ); ?>">
						<td>
							<strong><?php echo $ignoredThemeName; ?></strong>
						</td>
						<td style="text-align: right; padding-right: 30px">
							<a href="#" onClick="return pluginthemeconflict_unignore('theme', '<?php echo urlencode( $ignoredThemeName ); ?>', undefined);"><i class="fa fa-check"></i> <?php _e( 'Allow', 'mainwp' ); ?>
							</a>
						</td>
					</tr>
					<?php
				}
				?>
				<?php
			} else {
				?>
				<tr>
					<td colspan="2"><?php _e( 'No ignored theme conflicts', 'mainwp' ); ?></td>
				</tr>
				<?php
			}
			?>
			</tbody>
		</table>

		<table id="mainwp-table" class="wp-list-table widefat" cellspacing="0">
			<caption><?php _e( 'Per Site Ignored Theme Conflict List', 'mainwp' ); ?></caption>
			<thead>
			<tr>
				<th scope="col" class="manage-column" style="width: 300px"><?php _e( 'Site', 'mainwp' ); ?></th>
				<th scope="col" class="manage-column" style="width: 650px"><?php _e( 'Themes', 'mainwp' ); ?></th>
				<th scope="col" class="manage-column" style="text-align: right; padding-right: 10px"><?php if ( $cnt > 0 ) { ?>
						<a href="#" class="button-primary mainwp-unignore-detail-all" onClick="return pluginthemeconflict_unignore('theme', undefined, '_ALL_');"><?php _e( 'Allow All', 'mainwp' ); ?></a><?php } ?>
				</th>
			</tr>
			</thead>
			<tbody id="ignored-themeconflict-list" class="list:sites">
			<?php
			if ( $cnt > 0 ) {
				@MainWP_DB::data_seek( $websites, 0 );
				while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
					$decodedIgnoredThemeConflicts = json_decode( $website->ignored_themeConflicts, true );
					if ( ! is_array( $decodedIgnoredThemeConflicts ) || count( $decodedIgnoredThemeConflicts ) == 0 ) {
						continue;
					}
					$first = true;

					foreach ( $decodedIgnoredThemeConflicts as $ignoredThemeConflictName ) {
						?>
						<tr site_id="<?php echo $website->id; ?>" theme="<?php echo urlencode( $ignoredThemeConflictName ); ?>">
							<td>
                            <span class="websitename" <?php if ( ! $first ) {
	                            echo 'style="display: none;"';
                            } else {
	                            $first = false;
                            } ?>>
                                <a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"><?php echo stripslashes( $website->name ); ?></a>
                            </span>
							</td>
							<td>
								<strong><?php echo $ignoredThemeConflictName; ?></strong>
							</td>
							<td style="text-align: right; padding-right: 30px">
								<a href="#" onClick="return pluginthemeconflict_unignore('theme', '<?php echo urlencode( $ignoredThemeConflictName ); ?>', <?php echo $website->id; ?>)"><i class="fa fa-check"></i> <?php _e( 'Allow', 'mainwp' ); ?>
								</a>
							</td>
						</tr>
						<?php
					}
				}
				@MainWP_DB::free_result( $websites );
			} else {
				?>
				<tr>
					<td colspan="3"><?php _e( 'No ignored theme conflicts', 'mainwp' ); ?></td>
				</tr>
				<?php
			}
			?>
			</tbody>
		</table>
		<?php
		self::renderFooter( 'IgnoredConflicts' );
	}

	public static function renderIgnore() {
		$websites             = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser() );
		$userExtension        = MainWP_DB::Instance()->getUserExtension();
		$decodedIgnoredThemes = json_decode( $userExtension->ignored_themes, true );
		$ignoredThemes        = ( is_array( $decodedIgnoredThemes ) && ( count( $decodedIgnoredThemes ) > 0 ) );

		$cnt = 0;
		while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
			if ( $website->is_ignoreThemeUpdates ) {
				continue;
			}
			$tmpDecodedIgnoredThemes = json_decode( $website->ignored_themes, true );
			if ( ! is_array( $tmpDecodedIgnoredThemes ) || count( $tmpDecodedIgnoredThemes ) == 0 ) {
				continue;
			}
			$cnt ++;
		}

		self::renderHeader( 'Ignore' );
		?>
		<table id="mainwp-table" class="wp-list-table widefat" cellspacing="0">
			<caption><?php _e( 'Globally Ignored Themes', 'mainwp' ); ?></caption>
			<thead>
			<tr>
				<th scope="col" class="manage-column" style="width: 300px"><?php _e( 'Theme', 'mainwp' ); ?></th>
				<th scope="col" class="manage-column" style="width: 650px"><?php _e( 'Theme File', 'mainwp' ); ?></th>
				<th scope="col" class="manage-column" style="text-align: right; padding-right: 10px"><?php if ( $ignoredThemes ) { ?>
						<a href="#" class="button-primary mainwp-unignore-globally-all" onClick="return rightnow_themes_unignore_globally_all();"><?php _e( 'Allow All', 'mainwp' ); ?></a><?php } ?>
				</th>
			</tr>
			</thead>
			<tbody id="globally-ignored-themes-list" class="list:sites">
			<?php
			if ( $ignoredThemes ) {
				?>
				<?php
				foreach ( $decodedIgnoredThemes as $ignoredTheme => $ignoredThemeName ) {
					?>
					<tr theme_slug="<?php echo urlencode( $ignoredTheme ); ?>">
						<td>
							<strong><?php echo $ignoredThemeName; ?></strong>
						</td>
						<td>
							<?php echo $ignoredTheme; ?>
						</td>
						<td style="text-align: right; padding-right: 30px">
							<?php if ( mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) { ?>
								<a href="#" onClick="return rightnow_themes_unignore_globally('<?php echo urlencode( $ignoredTheme ); ?>')"><i class="fa fa-check"></i> <?php _e( 'Allow', 'mainwp' ); ?>
								</a>
							<?php } ?>
						</td>
					</tr>
					<?php
				}
				?>
				<?php
			} else {
				?>
				<tr>
					<td colspan="2"><?php _e( 'No ignored themes', 'mainwp' ); ?></td>
				</tr>
				<?php
			}
			?>
			</tbody>
		</table>

		<table id="mainwp-table" class="wp-list-table widefat" cellspacing="0">
			<caption><?php _e( 'Per Site Ignored Themes', 'mainwp' ); ?></caption>
			<thead>
			<tr>
				<th scope="col" class="manage-column" style="width: 300px"><?php _e( 'Site', 'mainwp' ); ?></th>
				<th scope="col" class="manage-column" style="width: 650px"><?php _e( 'Themes', 'mainwp' ); ?></th>
				<th scope="col" class="manage-column" style="text-align: right; padding-right: 10px"><?php if ( mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) {
						if ( $cnt > 0 ) { ?>
							<a href="#" class="button-primary mainwp-unignore-detail-all" onClick="return rightnow_themes_unignore_detail_all();"><?php _e( 'Allow All', 'mainwp' ); ?></a><?php }
					} ?></th>
			</tr>
			</thead>
			<tbody id="ignored-themes-list" class="list:sites">
			<?php
			if ( $cnt > 0 ) {
				@MainWP_DB::data_seek( $websites, 0 );
				while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
					if ( $website->is_ignoreThemeUpdates ) {
						continue;
					}

					$decodedIgnoredThemes = json_decode( $website->ignored_themes, true );
					if ( ! is_array( $decodedIgnoredThemes ) || count( $decodedIgnoredThemes ) == 0 ) {
						continue;
					}
					$first = true;
					foreach ( $decodedIgnoredThemes as $ignoredTheme => $ignoredThemeName ) {
						?>
						<tr site_id="<?php echo $website->id; ?>" theme_slug="<?php echo urlencode( $ignoredTheme ); ?>">
							<td>
                       <span class="websitename" <?php if ( ! $first ) {
	                       echo 'style="display: none;"';
                       } else {
	                       $first = false;
                       } ?>>
                           <a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"><?php echo stripslashes( $website->name ); ?></a>
                       </span>
							</td>
							<td>
								<strong><?php echo $ignoredThemeName; ?></strong> (<?php echo $ignoredTheme; ?>)
							</td>
							<td style="text-align: right; padding-right: 30px">
								<?php if ( mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) { ?>
									<a href="#" onClick="return rightnow_themes_unignore_detail('<?php echo urlencode( $ignoredTheme ); ?>', <?php echo $website->id; ?>)"><i class="fa fa-check"></i> <?php _e( 'Allow', 'mainwp' ); ?>
									</a>
								<?php } ?>
							</td>
						</tr>
						<?php
					}
				}
				@MainWP_DB::free_result( $websites );
			} else {
				?>
				<tr>
					<td colspan="3"><?php _e( 'No ignored themes', 'mainwp' ); ?></td>
				</tr>
				<?php
			}
			?>
			</tbody>
		</table>
		<?php
		self::renderFooter( 'Ignore' );
	}

	public static function renderIgnoredAbandoned() {
		$websites             = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser() );
		$userExtension        = MainWP_DB::Instance()->getUserExtension();
		$decodedIgnoredThemes = json_decode( $userExtension->dismissed_themes, true );
		$ignoredThemes        = ( is_array( $decodedIgnoredThemes ) && ( count( $decodedIgnoredThemes ) > 0 ) );

		$cnt = 0;
		while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
			$tmpDecodedIgnoredThemes = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'themes_outdate_dismissed' ), true );
			if ( ! is_array( $tmpDecodedIgnoredThemes ) || count( $tmpDecodedIgnoredThemes ) == 0 ) {
				continue;
			}
			$cnt ++;
		}

		self::renderHeader( 'IgnoreAbandoned' );
		?>
		<table id="mainwp-table" class="wp-list-table widefat" cellspacing="0">
			<caption><?php _e( 'Globally Ignored Abandoned Themes', 'mainwp' ); ?></caption>
			<thead>
			<tr>
				<th scope="col" class="manage-column" style="width: 300px"><?php _e( 'Theme', 'mainwp' ); ?></th>
				<th scope="col" class="manage-column" style="width: 650px"><?php _e( 'Theme File', 'mainwp' ); ?></th>
				<th scope="col" class="manage-column" style="text-align: right; padding-right: 10px"><?php if ( $ignoredThemes ) { ?>
						<a href="#" class="button-primary mainwp-unignore-globally-all" onClick="return rightnow_themes_abandoned_unignore_globally_all();"><?php _e( 'Allow All', 'mainwp' ); ?></a><?php } ?>
				</th>
			</tr>
			</thead>
			<tbody id="globally-ignored-themes-list" class="list:sites">
			<?php
			if ( $ignoredThemes ) {
				?>
				<?php
				foreach ( $decodedIgnoredThemes as $ignoredTheme => $ignoredThemeName ) {
					?>
					<tr theme_slug="<?php echo urlencode( $ignoredTheme ); ?>">
						<td>
							<strong><?php echo $ignoredThemeName; ?></strong>
						</td>
						<td>
							<?php echo $ignoredTheme; ?>
						</td>
						<td style="text-align: right; padding-right: 30px">
							<?php if ( mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) { ?>
								<a href="#" onClick="return rightnow_themes_abandoned_unignore_globally('<?php echo urlencode( $ignoredTheme ); ?>')"><i class="fa fa-check"></i> <?php _e( 'Allow', 'mainwp' ); ?>
								</a>
							<?php } ?>
						</td>
					</tr>
					<?php
				}
				?>
				<?php
			} else {
				?>
				<tr>
					<td colspan="2"><?php _e( 'No ignored abandoned themes', 'mainwp' ); ?></td>
				</tr>
				<?php
			}
			?>
			</tbody>
		</table>

		<table id="mainwp-table" class="wp-list-table widefat" cellspacing="0">
			<caption><?php _e( 'Per Site Ignored Abandoned Themes', 'mainwp' ); ?></caption>
			<thead>
			<tr>
				<th scope="col" class="manage-column" style="width: 300px"><?php _e( 'Site', 'mainwp' ); ?></th>
				<th scope="col" class="manage-column" style="width: 650px"><?php _e( 'Themes', 'mainwp' ); ?></th>
				<th scope="col" class="manage-column" style="text-align: right; padding-right: 10px"><?php if ( mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) {
						if ( $cnt > 0 ) { ?>
							<a href="#" class="button-primary mainwp-unignore-detail-all" onClick="return rightnow_themes_unignore_abandoned_detail_all();"><?php _e( 'Allow All', 'mainwp' ); ?></a><?php }
					} ?></th>
			</tr>
			</thead>
			<tbody id="ignored-themes-list" class="list:sites">
			<?php
			if ( $cnt > 0 ) {
				@MainWP_DB::data_seek( $websites, 0 );
				while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
					$decodedIgnoredThemes = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'themes_outdate_dismissed' ), true );
					if ( ! is_array( $decodedIgnoredThemes ) || count( $decodedIgnoredThemes ) == 0 ) {
						continue;
					}
					$first = true;
					foreach ( $decodedIgnoredThemes as $ignoredTheme => $ignoredThemeName ) {
						?>
						<tr site_id="<?php echo $website->id; ?>" theme_slug="<?php echo urlencode( $ignoredTheme ); ?>">
							<td>
                       <span class="websitename" <?php if ( ! $first ) {
	                       echo 'style="display: none;"';
                       } else {
	                       $first = false;
                       } ?>>
                           <a href="<?php echo admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ); ?>"><?php echo stripslashes( $website->name ); ?></a>
                       </span>
							</td>
							<td>
								<strong><?php echo $ignoredThemeName; ?></strong> (<?php echo $ignoredTheme; ?>)
							</td>
							<td style="text-align: right; padding-right: 30px">
								<?php if ( mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) { ?>
									<a href="#" onClick="return rightnow_themes_unignore_abandoned_detail('<?php echo urlencode( $ignoredTheme ); ?>', <?php echo $website->id; ?>)"><i class="fa fa-check"></i> <?php _e( 'Allow', 'mainwp' ); ?>
									</a>
								<?php } ?>
							</td>
						</tr>
						<?php
					}
				}
				@MainWP_DB::free_result( $websites );
			} else {
				?>
				<tr>
					<td colspan="3"><?php _e( 'No ignored abandoned themes', 'mainwp' ); ?></td>
				</tr>
				<?php
			}
			?>
			</tbody>
		</table>
		<?php
		self::renderFooter( 'IgnoreAbandoned' );
	}

	public static function trustPost() {
		$userExtension = MainWP_DB::Instance()->getUserExtension();
		$trustedThemes = json_decode( $userExtension->trusted_themes, true );
		if ( ! is_array( $trustedThemes ) ) {
			$trustedThemes = array();
		}

		$action = $_POST['do'];
		$slugs  = $_POST['slugs'];

		if ( ! is_array( $slugs ) ) {
			return;
		}
		if ( $action != 'trust' && $action != 'untrust' ) {
			return;
		}

		if ( $action == 'trust' ) {
			foreach ( $slugs as $slug ) {
				$idx = array_search( urldecode( $slug ), $trustedThemes );
				if ( $idx == false ) {
					$trustedThemes[] = urldecode( $slug );
				}
			}
		} else if ( $action == 'untrust' ) {
			foreach ( $slugs as $slug ) {
				if ( in_array( urldecode( $slug ), $trustedThemes ) ) {
					$trustedThemes = array_diff( $trustedThemes, array( urldecode( $slug ) ) );
				}
			}
		}

		$userExtension->trusted_themes = json_encode( $trustedThemes );
		MainWP_DB::Instance()->updateUserExtension( $userExtension );
	}

	public static function saveTrustedThemeNote() {
		$slug = urldecode( $_POST['slug'] );
		$note = $_POST['note'];

		$userExtension      = MainWP_DB::Instance()->getUserExtension();
		$trustedThemesNotes = json_decode( $userExtension->trusted_themes_notes, true );
		if ( ! is_array( $trustedThemesNotes ) ) {
			$trustedThemesNotes = array();
		}

		$trustedThemesNotes[ $slug ] = $note;

		$userExtension->trusted_themes_notes = json_encode( $trustedThemesNotes );
		MainWP_DB::Instance()->updateUserExtension( $userExtension );
	}

	public static function QSGManageThemes() {
		self::renderHeader( 'ThemesHelp' );
		?>
		<div style="text-align: center">
			<a href="#" class="button button-primary" id="mainwp-quick-start-guide"><?php _e( 'Show Quick Start Guide', 'mainwp' ); ?></a>
		</div>
		<div class="mainwp_info-box-yellow" id="mainwp-qsg-tips">
			<span><a href="#" class="mainwp-show-qsg" number="1"><?php _e( 'Manage Themes', 'mainwp' ) ?></a>&nbsp;&nbsp;&nbsp;&nbsp;<a href="#" class="mainwp-show-qsg" number="4"><?php _e( 'Ignore a theme update', 'mainwp' ) ?></a></span><span><a href="#" id="mainwp-qsg-dismiss" style="float: right;"><i class="fa fa-times-circle"></i> <?php _e( 'Dismiss', 'mainwp' ); ?>
				</a></span>

			<div class="clear"></div>
			<div id="mainwp-qsgs">
				<div class="mainwp-qsg" number="1">
					<h3>Manage Themes</h3>

					<p>
					<ol>
						<li>
							Select do you want to see your Active or Inactive themes.<br/><br/>
							<img src="http://docs.mainwp.com/wp-content/uploads/2013/02/new-themes-active.jpg" style="wight: 100% !important;" alt="screenshot"/>
						</li>
						<li>
							Optionaly, Enter the keyword for the search <br/><br/>
							<img src="http://docs.mainwp.com/wp-content/uploads/2013/02/new-themes-keyword.jpg" style="wight: 100% !important;" alt="screenshot"/>
						</li>
						<li>
							Select the sites from the Select Site Box <br/><br/>
							<img src="http://docs.mainwp.com/wp-content/uploads/2013/02/new-themes-sites.jpg" style="wight: 100% !important;" alt="screenshot"/>
						</li>
						<li>
							Hit the Show Themes button <br/><br/>
							<img src="http://docs.mainwp.com/wp-content/uploads/2013/02/new-themes-show.jpg" style="wight: 100% !important;" alt="screenshot"/>
						</li>
						<li><h4>To Deactivate a Theme: </h4><br/>
							<ol>
								<li>Select Active in Status dropdown list</li>
								<li>Select Site(s)</li>
								<li>Click Show Themes button</li>
								<li>After list generates, select wanted theme(s)</li>
								<li>Choose Deactivate from Bulk Action menu</li>
								<li>Click Confirm</li>
							</ol>
						</li>
						<li><h4>To delete a Theme(s) from a site: </h4><br/>
							<ol>
								<li>Set the Inactive themes in status drop-down list</li>
								<li>Select Site(s)</li>
								<li>Click Show Themes button</li>
								<li>After list generates, select wanted theme(s)</li>
								<li>Choose Delete from Bulk Action menu</li>
								<li>Click Confirm</li>
							</ol>
						</li>
						<li><h4>To activate Theme(s): </h4><br/>
							<ol>
								<li>Set the Inactive theme in status drop-down list.</li>
								<li>Select Site(s)</li>
								<li>Click Show Theme button</li>
								<li>After list generates, select wanted theme(s)</li>
								<li>Choose Activate from Bulk Action menu</li>
								<li>Click Confirm</li>
							</ol>
						</li>
					</ol>
					</p>
				</div>
				<div class="mainwp-qsg" number="2">
					<h3>How to install a Theme</h3>

					<p>You can install new theme by searching WordPress theme repository or by uploading the theme from your computer
					<h4>Search Themes</h4>
					<ol>
						<li>
							Click the Install Tab
						</li>
						<li>
							Select if you want to make the search by Term, Author or Tag
						</li>
						<li>
							Enter a search keyword
						</li>
						<li>
							Click Search Theme button <br/><br/>
							<img src="http://docs.mainwp.com/wp-content/uploads/2013/02/new-themes-install.jpg" style="wight: 100% !important;" alt="screenshot"/>
						</li>
						<li>
							Select the site(s) you want to install the theme, and click Install
						</li>
					</ol>
					<h4>Upload Themes</h4>
					<ol>
						<li>
							Click the Install Tab
						</li>
						<li>
							Click the Upload toggle Button
						</li>
						<li>
							Click 'Upload Now' button<br/><br/>
							<img src="http://docs.mainwp.com/wp-content/uploads/2013/05/new-theme-upload.jpg" style="wight: 100% !important;" alt="screenshot"/>
						</li>
						<li>
							Locate your Theme
						</li>
						<li>
							Select sites you want to install the themes
						</li>
						<li>
							Click 'Install Now' button
						</li>
					</ol>
					</p>
				</div>
				<div class="mainwp-qsg" number="3">
					<h3>How to update Themes</h3>

					<p>
					<ol>
						<li>
							Go to main MainWP Dashboard
						</li>
						<li>
							Locate your 'Right Now' Widget
						</li>
						<li>
							Click 'Show' on 'Theme Upgrades Available' area <br/><br/>
							<img src="http://docs.mainwp.com/wp-content/uploads/2013/05/new-available-themes.jpg" style="wight: 100% !important;" alt="screenshot"/>
						</li>
						<li>
							Click the middle upgrades link to show the drop down of the available upgrades for that site
							<br/><br/>
							<img src="http://docs.mainwp.com/wp-content/uploads/2013/05/new-available-themes-show.jpg" style="wight: 100% !important;" alt="screenshot"/>
						</li>
						<li>
							Select 'Upgrade' next to the name of the theme or 'Upgrade All' to upgrade all themes on the site
							<br/><br/>
							<img src="http://docs.mainwp.com/wp-content/uploads/2013/05/new-available-themes-upgrade.jpg" style="wight: 100% !important;" alt="screenshot"/>
						</li>
					</ol>
					</p>
				</div>
				<div class="mainwp-qsg" number="4">
					<h3>Ignore a Theme update</h3>

					<p>
					<ol>
						<li>
							Go to main MainWP Dashboard
						</li>
						<li>
							Locate your 'Right Now' Widget
						</li>
						<li>
							Click 'Show' on 'Theme Upgrades Available' area <br/><br/>
							<img src="http://docs.mainwp.com/wp-content/uploads/2013/05/new-available-themes.jpg" style="wight: 100% !important;" alt="screenshot"/>
						</li>
						<li>
							Click the middle upgrades link to show the drop down of the available upgrades for that site
						</li>
						<li>
							Click 'Ignore' next to the name of the theme<br/><br/>
							<img src="http://docs.mainwp.com/wp-content/uploads/2013/05/new-plugin-ignore.jpg" style="wight: 100% !important;" alt="screenshot"/>
						</li>
					</ol>
					</p>
				</div>
			</div>
		</div>
		<?php
		self::renderFooter( 'ThemesHelp' );
	}
}
