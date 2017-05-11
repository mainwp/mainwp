<?php
class MainWP_Manage_Sites_View {
	public static function initMenu() {
		return add_submenu_page( 'mainwp_tab', __( 'Sites','mainwp' ), '<span id="mainwp-Sites">'.__( 'Sites','mainwp' ).'</span>', 'read', 'managesites', array( MainWP_Manage_Sites::getClassName(), 'renderManageSites' ) );
	}

	public static function initMenuSubPages( &$subPages ) {

		?>
		<div id="menu-mainwp-Sites" class="mainwp-submenu-wrapper">
			<div class="wp-submenu sub-open" style="">
				<div class="mainwp_boxout">
					<div class="mainwp_boxoutin"></div>
					<a href="<?php echo admin_url( 'admin.php?page=managesites' ); ?>" class="mainwp-submenu"><?php _e( 'Manage Sites','mainwp' ); ?></a>
					<?php if ( mainwp_current_user_can( 'dashboard', 'add_sites' ) ) { ?>
						<a href="<?php echo admin_url( 'admin.php?page=managesites&do=new' ); ?>" class="mainwp-submenu"><?php _e( 'Add New','mainwp' ); ?></a>
						<a href="<?php echo admin_url( 'admin.php?page=managesites&do=bulknew' ); ?>" class="mainwp-submenu"><?php _e( 'Import Sites','mainwp' ); ?></a>
					<?php } ?>
					<a href="<?php echo admin_url( 'admin.php?page=managesites&do=test' ); ?>" class="mainwp-submenu"><?php _e( 'Test Connection','mainwp' ); ?></a>
					<a href="<?php echo admin_url( 'admin.php?page=ManageGroups' ); ?>" class="mainwp-submenu"><?php _e( 'Groups','mainwp' ); ?></a>
					<?php
					if ( isset( $subPages ) && is_array( $subPages ) ) {
						foreach ( $subPages as $subPage ) {
							if ( ! isset( $subPage['menu_hidden'] ) || (isset( $subPage['menu_hidden'] ) && $subPage['menu_hidden'] != true) ) {
							?>
								<a href="<?php echo admin_url( 'admin.php?page=ManageSites' . $subPage['slug'] ); ?>" class="mainwp-submenu"><?php echo $subPage['title']; ?></a>
							<?php
							}
						}
					}
					?>
				</div>
			</div>
		</div>
	<?php
	}

    static function init_sub_sub_left_menu( $subPages = array() ) {
        MainWP_System::add_sub_left_menu(__('Sites', 'mainwp'), 'mainwp_tab', 'managesites', 'admin.php?page=managesites', '<i class="fa fa-globe"></i>', '');

        $init_sub_subleftmenu = array(
                array(  'title' => __('Manage Sites', 'mainwp'),
                            'parent_key' => 'managesites',
                            'slug' => 'managesites',
                            'href' => 'admin.php?page=managesites',
                            'right' => ''
                    ),
                array(  'title' => __('Add New', 'mainwp'),
                            'parent_key' => 'managesites',
                            'href' => 'admin.php?page=managesites&do=new',
                            'slug' => 'managesites',
                            'right' => 'add_sites'
                        ),
                array(  'title' => __('Import Sites', 'mainwp'),
                            'parent_key' => 'managesites',
                            'href' => 'admin.php?page=managesites&do=bulknew',
                            'slug' => 'managesites',
                            'right' => 'add_sites'
                        ),
                array(  'title' => __('Test Connection', 'mainwp'),
                            'parent_key' => 'managesites',
                            'href' => 'admin.php?page=managesites&do=test',
                            'slug' => 'managesites',
                            'right' => ''
                        ),
                array(  'title' => __('Groups', 'mainwp'),
                            'parent_key' => 'managesites',
                            'href' => 'admin.php?page=ManageGroups',
                            'slug' => 'ManageGroups',
                            'right' => ''
                        )
        );

        MainWP_System::init_subpages_left_menu($subPages, $init_sub_subleftmenu, 'managesites', 'ManageSites');

        foreach($init_sub_subleftmenu as $item) {
            MainWP_System::add_sub_sub_left_menu($item['title'], $item['parent_key'], $item['slug'], $item['href'], $item['right']);
        }

        // init sites left menu
        if (get_option('mainwp_disable_wp_main_menu', 1)) { // to reduce db query
            $websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser() );
            while ( $websites && ( $website = @MainWP_DB::fetch_object( $websites ) ) ) {
                MainWP_System::add_sub_left_menu($website->name, 'childsites_menu', 'child_site_' . $website->id, 'admin.php?page=managesites&dashboard=' . $website->id, '', $website->url );

                $init_sub_subleftmenu = array(
                        array(  'title' => '<i class="fa fa-pencil-square-o" title="' . __('Edit', 'mainwp'). '"></i>',
                                'parent_key' => 'child_site_' . $website->id,
                                'href' => 'admin.php?page=managesites&id=' . $website->id,
                                'slug' => 'site_edit_' . $website->id ,
                                'right' => ''
                            ),
                        array(  'title' => '<i class="fa fa-refresh" aria-hidden="true" title="' . __('Updates', 'mainwp'). '"></i>',
                                'parent_key' => 'child_site_' . $website->id,
                                'href' => 'admin.php?page=managesites&updateid=' . $website->id,
                                'slug' => 'site_update_' . $website->id ,
                                'right' => ''
                            ),
                );
                foreach($init_sub_subleftmenu as $item ) {
                    MainWP_System::add_sub_sub_left_menu($item['title'], $item['parent_key'], $item['slug'], $item['href'], $item['right']);
                }
            }
        }
    }

	static function getBreadcrumb( $pShowpage, $pSubPages ) {
		$extra = array();
		if ( isset( $pSubPages ) && is_array( $pSubPages ) ) {
			foreach ( $pSubPages as $sub ) {
				if ( $pShowpage === $sub['slug'] ) {
					$extra['text'] = $sub['title'];
					break;
				}
			}
		}
                                
		$site_id = null;
		$page = '';
		switch ( $pShowpage ) {
			case 'ManageSites':
				$page = 'manage';
				break;
			case 'ManageSitesDashboard':
				$site_id = $_GET['dashboard'];
				$page = 'dashboard';
				break;
			case 'SecurityScan':
				$site_id = $_GET['scanid'];
				$page = 'scan';
				break;
			case 'ManageSitesEdit':
				$site_id = $_GET['id'];
				$page = 'edit';
				break;
			case 'ManageSitesBackups':
				$site_id = $_GET['backupid'];
				$page = 'backup';
				break;                            
                        case 'ManageSitesUpdates':
                            $site_id = $_GET['updateid'];
                            $page = 'update';
                            break;
			case 'Test':
				$page = 'test';
				break;
			case 'SitesHelp':
				$page = 'help';
				break;
			default:
				$site_id = isset( $_GET['id'] ) ? $_GET['id'] : 0;
				$page = 'others';
				break;
		}
		$current_site = '';
		$separator = '<span class="separator">&nbsp;&rsaquo;&nbsp;</span>';
		if ( $site_id ) {
			$website = MainWP_DB::Instance()->getWebsiteById( $site_id );
			if ( $website ) {
				$current_site  = '<a href="admin.php?page=managesites&dashboard=' . $site_id . '">' . stripslashes( $website->name ) . '</a>' . $separator;
			}
		}

		$page_links = array(
			'mainwp' => array(
                                                        'href' => 'admin.php?page=mainwp_tab',
							'text' => __( 'MainWP', 'mainwp' ),
							'alt' => '',
							'parent' => '',
							),
			'site' => array(
                                                        'href' => 'admin.php?page=managesites',
							'text' => __( 'Sites', 'mainwp' ),
							'alt' => '',
							'parent' => 'mainwp',
							),
			'dashboard' => array(
                                                        'href' => '',
							'text' => $current_site . __( 'Overview', 'mainwp' ),
							'alt' => '',
							'parent' => 'site',
							),
                        'updates' => array(
                                                        'href' => '',
							'text' => $current_site . __( 'Updates', 'mainwp' ),
							'alt' => '',
							'parent' => 'site',
							),
			'bulkupload' => array(
			'href' => '',
							'text' => __( 'Bulk Upload', 'mainwp' ),
							'alt' => '',
							'parent' => 'site',
							),			
			'edit' => array(
			'href' => '',
							'text' => $current_site . __( 'Edit', 'mainwp' ),
							'alt' => '',
							'parent' => 'site',
							),
			'backup' => array(
			'href' => '',
							'text' => $current_site . __( 'Backups', 'mainwp' ),
							'alt' => '',
							'parent' => 'site',
							),     
                        'update' => array(
			'href' => '',
							'text' => $current_site . __( 'Updates', 'mainwp' ),
							'alt' => '',
							'parent' => 'site',
							),     
			'scan' => array(
			'href' => '',
							'text' => $current_site . __( 'Security Scan', 'mainwp' ),
							'alt' => '',
							'parent' => 'site',
							),
			'others' => array(
			'href' => '',
							'text' => ( ! empty( $current_site ) ? $current_site : '')  . (isset( $extra['text'] ) ? $extra['text'] : ''),
							'alt' => (isset( $extra['alt'] ) ? $extra['alt'] : ''),
							'parent' => 'site',
						),
		);

		$str_breadcrumb = '';
		$first = true;
		while ( isset( $page_links[ $page ] ) ) {
			if ( $first ) {
				$str_breadcrumb = $page_links[ $page ]['text'] . $str_breadcrumb ;
				$first = false;
			} else {
				$str_breadcrumb = $separator . $str_breadcrumb;
				if ( ! empty( $page_links[ $page ]['href'] ) ) {
					$str_breadcrumb  = '<a href="' . $page_links[ $page ]['href'] . '" alt="' . $page_links[ $page ]['alt'] . '">' . $page_links[ $page ]['text'] . '</a>' . $str_breadcrumb ;
				} else { $str_breadcrumb = $page_links[ $page ]['text'] . $str_breadcrumb ;}
			}
			$page = $page_links[ $page ]['parent'];
		}

		$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser() );
		$html = '';
		if ( ! empty( $str_breadcrumb ) ) {
			$html = '<div class="postbox"><div class="inside"><span class="mainwp-left mainwp-cols-2 mainwp-padding-top-15"><i class="fa fa-map-signs" aria-hidden="true"></i> ' . __( 'You are here: ','mainwp' ) . '&nbsp;&nbsp;' .  $str_breadcrumb . '
                    </span><span class="mainwp-right mainwp-padding-top-10 mainwp-cols-2 mainwp-t-align-right">' .  __( 'Jump to ','mainwp' ) . '
                        <select id="mainwp-quick-jump-child" name="" class="mainwp-select2">
                            <option value="" selected="selected">' . __( 'Select Site ','mainwp' ) . '</option>';
			while ( $websites && ($website = @MainWP_DB::fetch_object( $websites )) ) {
				$html .= '<option value="'.$website->id.'">' . stripslashes( $website->name ) . '</option>';
			}
				@MainWP_DB::free_result( $websites );

                
		
                
			$html .= '
					</select>
					<select id="mainwp-quick-jump-page" name="" class="mainwp-select2">
						<option value="" selected="selected">' . __( 'Select page ','mainwp' ) . '</option>
						<option value="dashboard">' . __( 'Overview ','mainwp' ) . '</option>
						<option value="id">' . __( 'Edit ','mainwp' ) . '</option>
                                                <option value="updateid">' . __( 'Updates','mainwp' ) . '</option>';
                        
                        $enableLegacyBackupFeature = get_option( 'mainwp_enableLegacyBackupFeature' );
                        if ($enableLegacyBackupFeature) {
                            $html .= '<option value="backupid">' . __( 'Backup ','mainwp' ) . '</option>';
                        } else {
                            $primaryBackup = get_option( 'mainwp_primaryBackup' );
                            if (!empty($primaryBackup)) {
                                $customPage = apply_filters( 'mainwp-getcustompage-backups', false );
                                if ( is_array( $customPage ) && isset( $customPage['slug'] )) {
                                    $html .= '<option value="' . 'ManageBackups' . $customPage['slug'] . '">' . $customPage['title'] . '</option>';
                                }

                            }
                        }   
                        $html .= '<option value="scanid">' . __( 'Security Scan ','mainwp' ) . '</option>
					</select>
				</span>
				<div style="clear: both;"></div>
				</div>
			</div>';
		}

		return $html;
	}

	public static function renderHeader( $shownPage, &$subPages ) {

		if ( $shownPage == '' ) {
			$shownPage = 'ManageSites';}

		$site_id = 0;
		if ( isset( $_GET['id'] ) && ! empty( $_GET['id'] ) ) {
			$site_id = $_GET['id'];                        
                } else if ( isset( $_GET['backupid'] ) && ! empty( $_GET['backupid'] ) ) {
                        $site_id = $_GET['backupid'];                         
                } else if ( isset( $_GET['updateid'] ) && ! empty( $_GET['updateid'] ) ) {
                        $site_id = $_GET['updateid'];                         
                } else if ( isset( $_GET['dashboard'] ) && ! empty( $_GET['dashboard'] ) ) {
                        $site_id = $_GET['dashboard'];                                
                } else if ( isset( $_GET['scanid'] ) && ! empty( $_GET['scanid'] ) ) {
                        $site_id = $_GET['scanid'];                                        
                }
                
                $managesites_pages = array(
                        'ManageSites' => array( 'href' => 'admin.php?page=managesites', 'title' => __( 'Manage Sites','mainwp' ), 'access' => true ),
                        'AddNew' => array( 'href' => 'admin.php?page=managesites&do=new', 'title' => __( 'Add New','mainwp' ), 'access' => mainwp_current_user_can( 'dashboard', 'add_sites' ) ),
                        'BulkAddNew' => array( 'href' => 'admin.php?page=managesites&do=bulknew', 'title' => __( 'Import Sites','mainwp' ), 'access' => mainwp_current_user_can( 'dashboard', 'add_sites' ) ),
                        'Test' => array( 'href' => 'admin.php?page=managesites&do=test', 'title' => __( 'Test Connection','mainwp' ), 'access' => mainwp_current_user_can( 'dashboard', 'test_connection' ) ),
                        'ManageGroups' => array( 'href' => 'admin.php?page=ManageGroups', 'title' => __( 'Groups','mainwp' ), 'access' => true ),
                );

		$site_pages = array(
                        'ManageSitesDashboard' => array( 'href' => 'admin.php?page=managesites&dashboard=' . $site_id, 'title' => __( 'Overview','mainwp' ), 'access' => mainwp_current_user_can( 'dashboard', 'access_individual_dashboard' ) ),
                        'ManageSitesEdit' => array( 'href' => 'admin.php?page=managesites&id=' . $site_id, 'title' => __( 'Edit','mainwp' ), 'access' => mainwp_current_user_can( 'dashboard', 'edit_sites' ) ),
                        'ManageSitesUpdates' => array( 'href' => 'admin.php?page=managesites&updateid=' . $site_id, 'title' => __( 'Updates','mainwp' ), 'access' => mainwp_current_user_can( 'dashboard', 'access_individual_dashboard' ) ),
                        'ManageSitesBackups' => array( 'href' => 'admin.php?page=managesites&backupid=' . $site_id, 'title' => __( 'Backups','mainwp' ), 'access' => mainwp_current_user_can( 'dashboard', 'execute_backups' ) ),
                        'SecurityScan' => array( 'href' => 'admin.php?page=managesites&scanid=' . $site_id, 'title' => __( 'Security Scan','mainwp' ), 'access' => true ),
                );
                
                
		global $mainwpUseExternalPrimaryBackupsMethod;
		if ( ! empty( $mainwpUseExternalPrimaryBackupsMethod ) ) {
			unset( $site_pages['ManageSitesBackups'] );
		} else if (!get_option('mainwp_enableLegacyBackupFeature')) {
                    if (isset($site_pages['ManageSitesBackups'])) {
                        unset($site_pages['ManageSitesBackups']);
                    }
                }
                               
		$breadcrumd = '';
		if ( ! isset( $managesites_pages[ $shownPage ] ) ) {
			$breadcrumd = self::getBreadcrumb( $shownPage, $subPages );
		}

        MainWP_UI::render_left_menu();

		?>
	<div class="mainwp-wrap">

		<h1 class="mainwp-margin-top-0"><i class="fa fa-globe"></i> <?php _e( 'Sites','mainwp' ); ?></h1>

		<div id="mainwp-tip-zone">
			<?php if ( $shownPage == '' ) { ?>
				<?php if ( MainWP_Utility::showUserTip( 'mainwp-managesites-tips' ) ) { ?>
					<div class="mainwp-tips mainwp-notice mainwp-notice-blue">
						<span class="mainwp-tip" id="mainwp-managesites-tips"><strong><?php _e( 'MainWP tip','mainwp' ); ?>: </strong><?php _e( 'You can show more or less information per row by selecting "Screen Options" on the top right.','mainwp' ); ?></span>
						<span><a href="#" class="mainwp-dismiss" ><i class="fa fa-times-circle"></i> <?php _e( 'Dismiss','mainwp' ); ?></a></span>
					</div>
				<?php } ?>
			<?php } ?>
			<?php if ( $shownPage == 'ManageSitesDashboard' ) { ?>
				<?php if ( MainWP_Utility::showUserTip( 'mainwp-managesitesdashboard-tips' ) ) { ?>
					<div class="mainwp-tips mainwp-notice mainwp-notice-blue">
						<span class="mainwp-tip" id="mainwp-managesitesdashboard-tips"><strong><?php _e( 'MainWP tip','mainwp' ); ?>: </strong><?php _e( 'You can move widgets around to fit your needs and even adjust the number of columns by selecting "Screen Options" on the top right.','mainwp' ); ?></span>
						<span><a href="#" class="mainwp-dismiss" ><i class="fa fa-times-circle"></i> <?php _e( 'Dismiss','mainwp' ); ?></a></span>
					</div>
				<?php } ?>
			<?php } ?>
		</div>

		<div class="mainwp-tabs" id="mainwp-tabs">
            <?php echo ! empty( $breadcrumd ) ? $breadcrumd . '<br />' : ''; ?>
			<?php
			if ( isset( $managesites_pages[ $shownPage ] ) ) {
				foreach ( $managesites_pages as $page => $value ) {
					if ( ! $value['access'] ) {
						continue;
					}
					?>
					<a class="nav-tab pos-nav-tab <?php echo $shownPage == $page ? 'nav-tab-active' : '' ?>" href="<?php echo $value['href']; ?>"><?php echo $value['title']; ?></a>
					<?php
				}
			} else if ( $site_id ) {
				foreach ( $site_pages as $page => $value ) {
					if ( ! $value['access'] ) {
						continue;
					}
					?>
					<a class="nav-tab pos-nav-tab <?php echo $shownPage == $page ? 'nav-tab-active' : '' ?>" href="<?php echo $value['href']; ?>"><?php echo $value['title']; ?></a>
					<?php
				}
			}

			if ( isset( $subPages ) && is_array( $subPages ) ) {
				foreach ( $subPages as $subPage ) {
					if ( isset( $subPage['sitetab'] ) && $subPage['sitetab'] == true && empty( $site_id ) ) {
						continue;
					}
					?>
					<a class="nav-tab pos-nav-tab <?php if ( $shownPage === $subPage['slug'] ) { echo 'nav-tab-active'; } ?>" href="admin.php?page=ManageSites<?php echo $subPage['slug'] . ($site_id ? '&id=' . esc_attr( $site_id ) : ''); ?>"><?php echo $subPage['title']; ?></a>
					<?php
				}
			}
			?>			
			<div class="clear"></div>
		</div>

		<div id="mainwp_wrap-inside">
		<?php
	}

	public static function renderFooter( $shownPage, &$subPages ) {
		?>
			</div>
		</div>
		<?php
	}
	
	public static function renderTestConnection() {
	?>
		<div class="mainwp-postbox-actions-top">
			<?php _e( 'The Test Connection feature is specifically testing what your Dashboard can "see" and what your Dashboard "sees" and what my Dashboard "sees" or what your browser "sees" can be completely different things.','mainwp' ); ?>
		</div>
		<div class="inside">
			<table class="form-table">
				<tr class="form-field form-required">
					<th scope="row"><?php _e( 'Site URL:','mainwp' ); ?></th>
					<td>
						<input type="text" id="mainwp_managesites_test_wpurl"
							   name="mainwp_managesites_add_wpurl"
							   value="<?php if ( isset( $_REQUEST['site'] ) ) {echo esc_attr( $_REQUEST['site'] );} ?>" autocompletelist="mainwp-test-sites" class="mainwp_autocomplete" />
						<datalist id="mainwp-test-sites">
							<?php
							$websites = MainWP_DB::Instance()->query( MainWP_DB::Instance()->getSQLWebsitesForCurrentUser() );
							while ( $websites && ($website = @MainWP_DB::fetch_object( $websites )) ) {
								echo '<option>'.$website->url.'</option>';
							}
							@MainWP_DB::free_result( $websites );
							?>
						</datalist>
						<br/><em><?php _e( 'Please only use the domain URL, do not add /wp-admin.','mainwp' ); ?></em>
					</td>
				</tr>
			</table>
		</div>
	<?php
	}
	
	public static function renderTestAdvancedOptions() {
	?>
		<table class="form-table">
			<tr class="form-field form-required">
			   <th scope="row"><?php _e( 'Verify certificate','mainwp' ); ?>&nbsp;<?php MainWP_Utility::renderToolTip( __( 'Verify the childs SSL certificate. This should be disabled if you are using out of date or self signed certificates.','mainwp' ) ); ?></th>
				<td>
					<select class="mainwp-select2" id="mainwp_managesites_test_verifycertificate" name="mainwp_managesites_test_verifycertificate">
						 <option selected value="1"><?php _e( 'Yes','mainwp' ); ?></option>
						 <option value="0"><?php _e( 'No','mainwp' ); ?></option>
						 <option value="2"><?php _e( 'Use Global Setting','mainwp' ); ?></option>
					 </select> <em>(<?php _e( 'Default: Yes','mainwp' ); ?>)</em>
				</td>
			</tr>
			<tr class="form-field form-required">
			   <th scope="row"><?php _e( 'SSL version','mainwp' ); ?>&nbsp;<?php MainWP_Utility::renderToolTip( __( 'Prefered SSL Version to connect to your site.','mainwp' ) ); ?></th>
				<td>
					<select class="mainwp-select2" id="mainwp_managesites_test_ssl_version" name="mainwp_managesites_test_ssl_version">
						 <option selected value="auto"><?php _e( 'Auto detect','mainwp' ); ?></option>
                                                 <option value="1.2"><?php _e( "Let's encrypt (TLS v1.2)",'mainwp' ); ?></option>
						 <option value="1.x"><?php _e( 'TLS v1.x','mainwp' ); ?></option>
						 <option value="2"><?php _e( 'SSL v2','mainwp' ); ?></option>
						 <option value="3"><?php _e( 'SSL v3','mainwp' ); ?></option>
						 <option value="1.0"><?php _e( 'TLS v1.0','mainwp' ); ?></option>
						 <option value="1.1"><?php _e( 'TLS v1.1','mainwp' ); ?></option>						 
					 </select> <em>(<?php _e( 'Default: Auto detect','mainwp' ); ?>)</em>
				</td>
			</tr>

			<!-- fake fields are a workaround for chrome autofill getting the wrong fields -->
			<input style="display:none" type="text" name="fakeusernameremembered"/>
			<input style="display:none" type="password" name="fakepasswordremembered"/>

			<tr class="form-field form-required">
				 <th scope="row"><?php _e( 'HTTP username: ','mainwp' ); ?></th>
				 <td><input type="text" id="mainwp_managesites_test_http_user" name="mainwp_managesites_test_http_user" value="" class=""/><br/><em><?php _e( 'If your Child Site is protected with HTTP basic authentication, please set the username for authentication here.','mainwp' ); ?></em></td>
			</tr>
			<tr class="form-field form-required">
				 <th scope="row"><?php _e( 'HTTP password: ','mainwp' ); ?></th>
				 <td><input type="password" id="mainwp_managesites_test_http_pass" name="mainwp_managesites_test_http_pass" value="" class=""/><br/><em><?php _e( 'If your Child Site is protected with HTTP basic authentication, please set the password for authentication here.','mainwp' ); ?></em></td>
			</tr>
		</table>
	<?php
	}
	
	public static function renderImportSites() {
		?>
            <div id="MainWPBulkUploadSitesLoading" class="updated" style="display: none;">
                <div><i class="fa fa-spinner fa-pulse"></i> <?php _e( 'Importing sites','mainwp' ); ?></div>
            </div>
            <?php
			$errors = array();
			if ( $_FILES['mainwp_managesites_file_bulkupload']['error'] == UPLOAD_ERR_OK ) {
				if ( is_uploaded_file( $_FILES['mainwp_managesites_file_bulkupload']['tmp_name'] ) ) {
					$content = file_get_contents( $_FILES['mainwp_managesites_file_bulkupload']['tmp_name'] );
					$lines = explode( "\r", $content );
					$allowedHeaders = array('site name', 'url', 'admin name', 'group', 'security id', 'http username', 'http password', 'verify certificate', 'ssl version');
					$default = array('', '', '', '', '', '', '', '1', 'auto');

					if ( is_array( $lines ) && (count( $lines ) > 0) ) {
						$i = 0;
						$header_line = null;
						$header_line_split = null;

						foreach ( $lines as $originalLine ) {
							$line = trim( $originalLine );
							if (MainWP_Utility::startsWith($line, '#')) continue;

							if ( ( $header_line == null ) && $_POST['mainwp_managesites_chk_header_first'] ) {
								$header_line = $line . "\n";
								$header_line_split_tmp = explode( ',', $header_line );
								$header_line_split = array();
								for ($x = 0; $x < count($header_line_split_tmp); $x++)
								{
									$header_line_split[$x] = strtolower( trim( trim( $header_line_split_tmp[$x] ), '"' ) );
								}

								continue;
							}

							$items = explode( ',', $line );
							$line = '';
							for ($x = 0; $x < count($allowedHeaders); $x++)
							{
								if ($line != '') { $line .= ','; }
								$idx = $x;
								if (!empty($header_line_split)) {
									$idx = array_search($allowedHeaders[$x], $header_line_split);
								}

								$val = null;
								if ( $idx > -1 ) {
									$val = trim( trim( $items[$idx] ), '"' );
									if ( $allowedHeaders[$x] == 'verify certificate' ) {
										if ( $val == 'T' ) {
											$val = '1';
										} else {
											$val = '0';
										}
									}
								}
								if ( empty( $val ) ) {
									$val = $default[$x];
								}
								$line .= $val;
							}
							?>
                            <input type="hidden"
                                   id="mainwp_managesites_import_csv_line_<?php echo ($i + 1) // start from 1 ?>"
                                   value="<?php echo esc_attr( $line ); ?>"
                                   original="<?php echo esc_attr( $originalLine ); ?>" />
                            <?php
							$i++;
						}

						?>                        
                        <input type="hidden" id="mainwp_managesites_do_import" value="1"/>
                        <input type="hidden" id="mainwp_managesites_total_import" value="<?php echo $i ?>"/>

                        <p>
                        <div class="mainwp_managesites_import_listing" id="mainwp_managesites_import_logging">
                            <pre class="log"><?php echo esc_attr($header_line); ?></pre>
                        </div></p>

                        <p class="submit" style="float:left;"><input type="button" name="mainwp_managesites_btn_import"
                                                 id="mainwp_managesites_btn_import"
                                                 class="button-primary button button-hero" value="<?php _e('Pause','mainwp'); ?>"/>
                            <input type="button" name="mainwp_managesites_btn_save_csv"
                                   id="mainwp_managesites_btn_save_csv" disabled="disabled"
                                   class="button-hero button" value="<?php _e('Save failed','mainwp'); ?>"/>
                        </p>

                        <p>
                        <div class="mainwp_managesites_import_listing"
                             id="mainwp_managesites_import_fail_logging" style="display: none;">
                            <pre class="log"><?php echo esc_attr($header_line); ?></pre>
                        </div></p>                        
						<br style="clear:both" />
                        <?php
					} else {
						$errors[] = __( 'ERROR: Data is not valid!', 'mainwp' ) . '<br />';
					}
				} else {
					$errors[] = __( 'ERROR: Upload error!', 'mainwp' ) . '<br />';
				}
			} else {
				$errors[] = __( 'ERROR: Upload error!', 'mainwp' ) . '<br />';
			}

			if ( count( $errors ) > 0 ) {
			?>
                <div class="error below-h2">
                    <?php foreach ( $errors as $error ) {
					?>
                    <p><strong>ERROR</strong>: <?php echo $error ?></p>
                    <?php } ?>
                </div>
                <br/>
                <a href="<?php echo get_admin_url() ?>admin.php?page=managesites" class="add-new-h2" target="_top"><?php _e('Add
                    new','mainwp'); ?></a>
                <a href="<?php echo get_admin_url() ?>admin.php?page=mainwp_tab" class="add-new-h2" target="_top"><?php _e('Return
                    to dashboard','mainwp'); ?></a>
                <?php
			}
	}


	public static function renderNewSite() {				
		$groups = MainWP_DB::Instance()->getGroupsForCurrentUser();
		if (!is_array($groups))
			$groups = array();
		?>
		<div class="mainwp-postbox-actions-top">
	        <?php echo sprintf( __( 'If you are having trouble adding your site please see the %s%s List of Most Common Reasons%s.','mainwp' ), '<a href="#" id="mainwp-most-common-reasons">', '<i class="fa fa-eye-slash" aria-hidden="true"></i>', '</a>' ); ?>
	        <div id="mainwp-most-common-reasons-content" style="display: none;">
	        	<h3><?php _e( 'MainWP Child plugin missing', 'mainwp' ); ?></h3>
	        	<p><?php _e( 'To be able to connect your website you need to make sure that the MainWP Child plugin is installed on your website that you are trying to connect.', 'mainwp' ); ?></p>
	        	<h3><?php _e( 'MainWP Child plugin installed but not activated', 'mainwp' ); ?></h3>
	        	<p><?php _e( 'If you are not able to connect your website to your MainWP Dashboard, and you are sure that you have installed the MainWP Child plugin on your website, make sure that the plugin is activated.', 'mainwp' ); ?></p>
	        	<h3><?php _e( 'Plugin conflict', 'mainwp' ); ?></h3>
	        	<p><?php _e( 'If after verifying that the MainWP Child plugin is installed and activated on your website, the website still can\'t be connected to your MainWP Dashboard, try to disable all plugins except for MainWP Child and connect your site after that.', 'mainwp' ); ?></p>
	        	<em><?php _e( 'If you are not able to disable all your plugins, be sure to at least disable all security and caching plugins.', 'mainwp' ); ?></em>
	        	<h3><?php _e( 'Dashboard Site Server Misconfiguration', 'mainwp' ); ?></h3>
	        	<p><?php _e( 'To be sure that your Dashboard Site server is configured properly and can be used for hosting MainWP Dashboard plugin:', 'mainwp' ); ?></p>
	        	<ol>
	        		<li><?php _e( 'Log in to your Dashboard Site', 'mainwp' ); ?></li>
	        		<li><?php _e( 'Go to the MainWP > Server Information page', 'mainwp' ); ?></li>
	        		<li><?php _e( 'Locate following checks and make sure that all of them display the Pass response:', 'mainwp' ); ?></li>
	        		<li>
	        			<ul>
	        				<li><?php _e( 'SSL Extension Enabled', 'mainwp' ); ?></li>
	        				<li><?php _e( 'SSL Warnings', 'mainwp' ); ?></li>
	        				<li><?php _e( 'cURL Version', 'mainwp' ); ?></li>
	        				<li><?php _e( 'cURL OpenSSL Version', 'mainwp' ); ?></li>
	        			</ul>
	        		</li>
	        	</ol>
	        	<h3><?php _e( 'Child Site Server Misconfiguration', 'mainwp' ); ?></h3>
	        	<p><?php _e( 'To be sure that your Child Site server is configured properly and can be connected to your MainWP Dashboard:', 'mainwp' ); ?></p>
	        	<ol>
	        		<li><?php _e( 'Log in to your Child Site', 'mainwp' ); ?></li>
	        		<li><?php _e( 'Go to the WP > Settings > MainWP Child > Server Information page', 'mainwp' ); ?></li>
	        		<li><?php _e( 'Locate following checks and make sure that all of them display the Pass response:', 'mainwp' ); ?></li>
	        		<li>
	        			<ul>
	        				<li><?php _e( 'MainWP Upload Directory', 'mainwp' ); ?></li>
	        				<li><?php _e( 'SSL Extension Enabled', 'mainwp' ); ?></li>
	        				<li><?php _e( 'SSL Warnings', 'mainwp' ); ?></li>
	        				<li><?php _e( 'cURL Version', 'mainwp' ); ?></li>
	        				<li><?php _e( 'cURL OpenSSL Version', 'mainwp' ); ?></li>
	        			</ul>
	        		</li>
	        	</ol>
	        	<h3><?php _e( 'Dashboard and Child site on the same server with disabled loopback connections', 'mainwp' ); ?></h3>
	        	<p><?php _e( 'In case your Dashboard Site is on the same server as your website that you are trying to connect to it, you need to make sure that the loopback connections are allowed and enabled on your server. To do that;', 'mainwp' ); ?></p>
	        	<ol>
	        		<li><?php _e( 'Log in to your Dashboard Site', 'mainwp' ); ?></li>
	        		<li><?php _e( 'Go to the MainWP > Server Information page', 'mainwp' ); ?></li>
	        		<li><?php _e( 'Locate the Server self-connect check', 'mainwp' ); ?></li>
	        	</ol>
	        	<p><?php _e( 'If you see anything different then Response Test O.K. it means that the loopback connections are disabled. In that case, you will need to contact your host support and request enabling this feature. If, by any chance, that is not possible, you should consider moving your Dashboard Site to another Webserver or Localhost.', 'mainwp' ); ?></p>
	        	<h3><?php _e( 'Website has been migrated recently', 'mainwp' ); ?></h3>
	        	<p><?php _e( 'You may have recently moved the website to another server and your Dashboard\'s Server may not have an updated DNS or your server may be experiencing DNS issues. To check this use the Test Connection tab and verify the IP that shows up with the IP that shows on your website WP > Settings > MainWP Child > Server Information page.', 'mainwp' ); ?></p>
	        	<p><?php _e( 'In case there is an IP address mismatch, you will need to contact your hosting provider and request 2 things:', 'mainwp' ); ?></p>
	        	<ol>
	        		<li><?php _e( 'Dashboard Site host: request DNS Cache flush', 'mainwp' ); ?></li>
	        		<li><?php _e( 'Child Site host: request DNS Settings verification', 'mainwp' ); ?></li>
	        	</ol>
	        	<em><?php _e( 'In most cases, this issue resolves itself in up to 48 hours, however, some host companies do not flush DNS cache that often and more time is needed.', 'mainwp' ); ?></em>
	        	<h3><?php _e( 'Requests being blocked by Child Site server', 'mainwp' ); ?></h3>
	        	<p><?php _e( 'n some cases, the Child Site server blocks requests sent from the Dashboard site, and your website may return message that the MainWP Child plugin can\'t be found. In this case, you need to contact your Child Site host support department and have them check if the server Firewall or Mod_Security is blocking access by reviewing server logs.', 'mainwp' ); ?></p>
	        	<h3><?php _e( 'Connection being Blocked by CloudFlare', 'mainwp' ); ?></h3>
	        	<p><?php _e( 'Some users with CloudFlare have reported trouble connecting their website to their MainWP Dashboard. If you are experiencing this issue please try the two resolution steps.', 'mainwp' ); ?></p>
	        	<ol>
	        		<li><?php _e( 'Add your Dashboard IP to your CloudFlare Trusted IP list', 'mainwp' ); ?></li>
	        		<li><?php _e( 'Add your WP-Admin to CloudFlare Bypass Catch as site.com/wp-admin*', 'mainwp' ); ?></li>
	        	</ol>
	        </div>
       </div>
       <div class="inside">
           <table class="form-table" id="mainwp_managesites_add_new_form">
               <tr class="form-field form-required">
                   <th scope="row"><?php _e('Site URL','mainwp'); ?></th>
                   <td><span id="mainwp_managesites_add_wpurl_protocol_wrap"><select class="mainwp-select2-wpurl-protocol" id="mainwp_managesites_add_wpurl_protocol" name="mainwp_managesites_add_wpurl_protocol"><option value="http">http://</option><option value="https">https://</option></select></span> <input type="text"
                               id="mainwp_managesites_add_wpurl"
                               name="mainwp_managesites_add_wpurl"
                               value=""
							   style="width: 262px !important"
                               class="" />
                    </td>
               </tr>
               <tr class="form-field form-required">
                   <th scope="row"><?php _e('Administrator username','mainwp'); ?></th>
                   <td>
                        <input type="text"
                               id="mainwp_managesites_add_wpadmin"
                               name="mainwp_managesites_add_wpadmin"
                               value=""
                               class="" />
                    </td>
               </tr>
               <tr class="form-field form-required">
                   <th scope="row"><?php _e('Friendly site name','mainwp'); ?></th>
                   <td>
                            <input type="text"
                                   id="mainwp_managesites_add_wpname"
                                   name="mainwp_managesites_add_wpname"
                                   value=""
                                   class=""/>
                    </td>
               </tr> 
               <tr>
                   <th scope="row"><?php _e('Groups','mainwp'); ?></th>
                   <td><span id="mainwp_managesites_add_addgroups_wrap">
                        <select 
                               name="selected_groups[]"
                               id="mainwp_managesites_add_addgroups" style="width: 350px"
							   multiple="multiple" /><?php 
                           foreach ($groups as $group)
                           {
								echo '<option value="' . $group->id . '">' . stripslashes($group->name)  . '</option>';
                           }
							?></select></span>
                   </td>
               </tr>
               </table>   
           
               
               <?php
                    $current_options = get_option( 'mainwp_opts_saving_status' );
                    $disabled_pop_notice = (is_array($current_options) && isset($current_options['disable_newsite_notice'])) ? true : false;                                                            
                    
                    if (!$disabled_pop_notice) {
                        $value = MainWP_DB::Instance()->getWebsitesCount();
                        if ($value > 0) {
                            $disabled_pop_notice = true;
                            $current_options = get_option( 'mainwp_opts_saving_status' );
                            $current_options['disable_newsite_notice'] = 1;
                            update_option( 'mainwp_opts_saving_status', $current_options );
                        }
                        if (!$disabled_pop_notice) {
                            ?>
                            <div id="newsite-pop-box" title="<?php _e('Not sure what to add here?'); ?>" style="display: none;">
                                <?php _e('Please check this page:', 'mainwp');?> <a href="" id="connection_detail_lnk" target="_blank"></a>                            
                                <br/><br/>
                                <p style="text-align: center">
                                    <input id="newsite-pop-box-close" type="button" name="close" value="Close" class="button"/>
                                    <input id="newsite-pop-box-disable" type="button" name="donotshow" value="<?php echo esc_attr('Don\'t show this again', 'mainwp'); ?>" class="button"/>                                
                                </p>
                            </div>
                            <?php
                        }
                    }
                    
               ?>
                    <script type="text/javascript">
                            jQuery( document ).ready( function () {			
                                    <?php if (count($groups) == 0) { ?>
                                    jQuery('#mainwp_managesites_add_addgroups').select2({minimumResultsForSearch: 10, allowClear: true, tags: true, placeholder: "<?php _e("No groups added yet.", 'mainwp'); ?>"});
                                    //jQuery('#mainwp_managesites_add_addgroups').prop("disabled", true);
                                    <?php } else { ?>
                                    jQuery('#mainwp_managesites_add_addgroups').select2({minimumResultsForSearch: 10, allowClear: true, tags: true, placeholder: " "});
                                    <?php } ?>                                         
                                    <?php if (!$disabled_pop_notice) { ?>                                            
                                            var pop_showed = false;
                                            jQuery("#mainwp_managesites_add_wpurl").blur(function() {                                                
                                                var detail_url = jQuery('#mainwp_managesites_add_wpurl_protocol option:selected').text() + jQuery('#mainwp_managesites_add_wpurl').val().trim() + '/wp-admin/options-general.php?page=mainwp_child_tab&tab=connection-detail';
                                                jQuery('#connection_detail_lnk').attr('href', detail_url).text(__('Connection Details'));
                                                if (!pop_showed) {
                                                    pop_showed = true;
                                                    jQuery('#newsite-pop-box').dialog({
                                                        resizable: false,
                                                        height: 150,
                                                        width: 500,
                                                        modal: true,
                                                        close: function(event, ui) {jQuery('#newsite-pop-box').dialog('destroy');}});                                                
                                                } 
                                            });
                                            jQuery('#newsite-pop-box-close').live('click', function(event)
                                            {                                                
                                                jQuery('#newsite-pop-box').dialog('destroy');
                                            });
                                            
                                            jQuery('#newsite-pop-box-disable').live('click', function(event)
                                            {       
                                                var data = {
                                                    action:'mainwp_saving_status',
                                                    saving_status: 'disable_newsite_notice',
                                                    value: 1,
                                                    nonce: mainwp_ajax_nonce
                                                };
                                                jQuery.post(ajaxurl, data, function (res) {
                                                });
                                                jQuery('#newsite-pop-box').dialog('destroy');
                                            });
                                            
                                    <?php } ?> 
                            });
                    </script>
               </div>

<?php
		return;

	}

	public static function renderSyncExtsSettings() {	
	$sync_extensions_options = apply_filters( 'mainwp-sync-extensions-options', array() );
	$working_extensions = MainWP_Extensions::getExtensions();
	$available_exts_data = MainWP_Extensions_View::getAvailableExtensions();
	if ( count( $working_extensions ) > 0 && count($sync_extensions_options) > 0 ) {?>
            <div class="mainwp-notice mainwp-notice-blue" id="mainwp_addnew_sync_exts_settings_notice"><?php _e( 'You have Extensions installed that require an additional plugin to be installed on this new Child site for the Extension to work correctly. From the list below select the plugins you want to install and if you want to apply the Extensions default settings to this Child site.', 'mainwp' ); ?></div>
            <div><a id="mainwp-check-all-sync-ext" href="javascript:void(0);"><i class="fa fa-check-square-o"></i> <?php echo __('Select All', 'mainwp'); ?></a> | <a id="mainwp-uncheck-all-sync-ext" href="javascript:void(0);"><i class="fa fa-square-o"></i> <?php echo __('Select None', 'mainwp'); ?></a></div>
                    <?php
                        foreach ( $working_extensions as $slug => $data ) {
                                $dir_slug = dirname($slug);
                                if (!isset($sync_extensions_options[$dir_slug]))
                                        continue;
                                $sync_info = isset( $sync_extensions_options[$dir_slug] ) ? $sync_extensions_options[$dir_slug] : array();
                                $ext_name = str_replace("MainWP", "", $data['name']);
                                $ext_name = str_replace("Extension", "", $ext_name);

                                $ext_data = isset( $available_exts_data[dirname($slug)] ) ? $available_exts_data[dirname($slug)] : array();
                                if ( isset($ext_data['img']) ) {
                                        $img_url = $ext_data['img'];
                                } else {
                                        $img_url = plugins_url( 'images/extensions/placeholder.png', dirname( __FILE__ ) );
                                }
                                $html = '<div class="sync-ext-row" slug="' . $dir_slug. '" ext_name = "' . esc_attr($ext_name) . '"status="queue">';
                                $html .= '<br/><img src="' . $img_url .'" height="24" style="margin-bottom: -5px;">' . '<h3 style="display: inline;">' . $ext_name . '</h3><br/><br/>';
                                if (isset($sync_info['plugin_slug']) && !empty($sync_info['plugin_slug'])) {
                                        $html .= '<div class="sync-install-plugin" slug="' . esc_attr(dirname($sync_info['plugin_slug']) ) .'" plugin_name="' . esc_attr($sync_info['plugin_name']) . '"><label><input type="checkbox" class="chk-sync-install-plugin" /> ' . esc_html( sprintf( __('Install %s plugin', 'mainwp'), $sync_info['plugin_name']) ) . '</label> <i class="fa fa-spinner fa-pulse" style="display: none"></i> <span class="status"></span></div>';
                                        if (!isset($sync_info['no_setting']) || empty($sync_info['no_setting'])) {
                                                $html .= '<div class="sync-options options-row"><label><input type="checkbox" /> ' . sprintf( __('Apply %s %ssettings%s', 'mainwp'), $sync_info['plugin_name'], '<a href="admin.php?page=' . $data['page'] . '">', '</a>' ) . '</label> <i class="fa fa-spinner fa-pulse" style="display: none"></i> <span class="status"></span></div>';
                                        }
                                } else {
                                        $html .= '<div class="sync-global-options options-row"><label><input type="checkbox" /> ' . esc_html( sprintf( __('Apply global %s options', 'mainwp'), trim($ext_name)) ) . '</label> <i class="fa fa-spinner fa-pulse"  style="display: none"></i> <span class="status"></span></div>';
                                }
                                $html .= '</div>';
                                echo $html;
                        }
                ?>
	<?php }
	}

	public static function renderAdvancedOptions() {
	?>	
                    <table class="form-table">
                        <tr class="form-field form-required">
				 <th scope="row"><?php _e('Child Unique Security
				   ID ','mainwp'); ?>&nbsp;&nbsp;<?php MainWP_Utility::renderToolTip('The Unique Security ID adds additional protection between the Child plugin and your Main Dashboard. The Unique Security ID will need to match when being added to the Main Dashboard. This is additional security and should not be needed in most situations.'); ?></th>
                             <td>
                             <input type="text"
                                    id="mainwp_managesites_add_uniqueId"
                                    name="mainwp_managesites_add_uniqueId"
                                    value=""
                                    class=""/>
				</td>
                        </tr>
                        <tr class="form-field form-required">
                            <th scope="row"><?php _e('Verify certificate','mainwp'); ?>&nbsp;<?php MainWP_Utility::renderToolTip(__('Verify the childs SSL certificate. This should be disabled if you are using out of date or self signed certificates.','mainwp')); ?></th>
                            <td>
					<span id="mainwp_managesites_verify_certificate_wrap">
						<select  id="mainwp_managesites_verify_certificate" name="mainwp_managesites_verify_certificate" class="form-control mainwp-select2">
                                         <option selected value="1"><?php _e('Yes','mainwp'); ?></option>
                                         <option value="0"><?php _e('No','mainwp'); ?></option>
                                         <option value="2"><?php _e('Use global setting','mainwp'); ?></option>
                                    </select> <em><?php _e( 'Default: Yes', 'mainwp' ); ?></em>
					</span>
                            </td>
                        </tr>
	                    <tr class="form-field form-required">
	                       <th scope="row"><?php _e( 'SSL version','mainwp' ); ?>&nbsp;<?php MainWP_Utility::renderToolTip( __( 'Prefered SSL version to connect to your site.','mainwp' ) ); ?></th>
	                        <td>
					<span id="mainwp_managesites_ssl_version_wrap">
						<select class="mainwp-select2" id="mainwp_managesites_ssl_version" name="mainwp_managesites_ssl_version">
	                                 <option selected value="auto"><?php _e( 'Auto detect','mainwp' ); ?></option>
                                         <option value="1.2"><?php _e( "Let's encrypt (TLS v1.2)",'mainwp' ); ?></option>                                         
	                                 <option value="1.x"><?php _e( 'TLS v1.x','mainwp' ); ?></option>
	                                 <option value="2"><?php _e( 'SSL v2','mainwp' ); ?></option>
	                                 <option value="3"><?php _e( 'SSL v3','mainwp' ); ?></option>
	                                 <option value="1.0"><?php _e( 'TLS v1.0','mainwp' ); ?></option>
	                                 <option value="1.1"><?php _e( 'TLS v1.1','mainwp' ); ?></option>	                                 
	                             </select> <em>(<?php _e( 'Default: Auto detect','mainwp' ); ?>)</em>
					 </span>
	                        </td>
	                    </tr>

                        <!-- fake fields are a workaround for chrome autofill getting the wrong fields -->
                        <input style="display:none" type="text" name="fakeusernameremembered"/>
                        <input style="display:none" type="password" name="fakepasswordremembered"/>


                        <tr class="form-field form-required">
                             <th scope="row"><?php _e('HTTP username ','mainwp'); ?></th>
                             <td>
                                     <input type="text"
                                            id="mainwp_managesites_add_http_user"
                                            name="mainwp_managesites_add_http_user"
                                            value=""
											autocomplete="new-http-user"
								class=""/><br/><em><?php _e( 'If your child site is protected with HTTP basic authentication, please set the username and password for authentication here.','mainwp' ); ?></em>
                            </td>
                        </tr>
                        <tr class="form-field form-required">
                             <th scope="row"><?php _e('HTTP password ','mainwp'); ?></th>
                             <td>
                                    <input type="password"
                                           id="mainwp_managesites_add_http_pass"
                                           name="mainwp_managesites_add_http_pass"
                                           value=""
										   autocomplete="new-http-password"
							   class=""/><br/><em><?php _e( 'If your child site is protected with HTTP basic authentication, please set the username and password for authentication here.','mainwp' ); ?></em>
                            </td>
                        </tr>
                    </table>
<?php
	}
        
	public static function renderBulkUpload() {
		?>	
			<div class="mainwp-postbox-actions-top">
           		<?php _e('Import sites allows you to connect a large number of child sites at once by uploading a CSV file. The MainWP Child plugin needs to be installed and activated before using the Import Sites option.','mainwp'); ?>
           </div>
			<div class="inside">
				<table>
					<th scope="row"></th>
					<td>
						<input type="file" name="mainwp_managesites_file_bulkupload"
							   id="mainwp_managesites_file_bulkupload"
							   accept="text/comma-separated-values"
							   class="regular-text"/>
					   

						<div>						
							<p>
								<input type="checkbox" name="mainwp_managesites_chk_header_first"
									   checked="checked"
									   id="mainwp_managesites_chk_header_first" value="1"/>
								<label for="mainwp_managesites_chk_header_first"><span class="description"><?php _e('CSV file contains a header.','mainwp'); ?></span></label>
							</p>
						</div>
					</td>
	           </table>
           </div>
           <div class="mainwp-postbox-actions-bottom">
           		<?php _e('File must be in CSV format.','mainwp'); ?> <a href="<?php echo plugins_url('csv/sample.csv', dirname(__FILE__)); ?>"><?php _e('Click here to download sample CSV file.','mainwp'); ?></a>
           </div>
		<?php
	}
	
	public static function showBackups( &$website, $fullBackups, $dbBackups ) {
		$output = '';
		echo '<table>';

		$mwpDir = MainWP_Utility::getMainWPDir();
		$mwpDir = $mwpDir[0];
		foreach ( $fullBackups as $key => $fullBackup ) {
			$downloadLink = admin_url( '?sig=' . md5( filesize( $fullBackup ) ) . '&mwpdl=' . rawurlencode( str_replace( $mwpDir, '', $fullBackup ) ) );
			$output .= '<tr><td style="width: 400px;">' . MainWP_Utility::formatTimestamp( MainWP_Utility::getTimestamp( filemtime( $fullBackup ) ) ) . ' - ' . MainWP_Utility::human_filesize( filesize( $fullBackup ) );
			$output .= '</td><td><a title="'.basename( $fullBackup ).'" href="' . $downloadLink . '" class="button">Download</a></td>';
			$output .= '<td><a href="admin.php?page=SiteRestore&websiteid=' . $website->id . '&f=' . base64_encode( $downloadLink ) . '&size='.filesize( $fullBackup ).'" class="mainwp-upgrade-button button" target="_blank" title="' . basename( $fullBackup ) . '">Restore</a></td></tr>';
		}
		if ( $output == '' ) {echo '<br />' . __( 'No full backup has been taken yet','mainwp' ) . '<br />';
		} else { echo '<strong style="font-size: 14px">'. __( 'Last backups from your files:','mainwp' ) . '</strong>' . $output;}

		echo '</table><br/><table>';

		$output = '';
		foreach ( $dbBackups as $key => $dbBackup ) {
			$downloadLink = admin_url( '?sig=' . md5( filesize( $dbBackup ) ) . '&mwpdl=' . rawurlencode( str_replace( $mwpDir, '', $dbBackup ) ) );
			$output .= '<tr><td style="width: 400px;">' . MainWP_Utility::formatTimestamp( MainWP_Utility::getTimestamp( filemtime( $dbBackup ) ) ) . ' - ' . MainWP_Utility::human_filesize( filesize( $dbBackup ) ) . '</td><td><a title="'.basename( $dbBackup ).'" href="' . $downloadLink . '" download class="button">Download</a></td></tr>';
		}
		if ( $output == '' ) {echo '<br />'. __( 'No database only backup has been taken yet','mainwp' ) . '<br /><br />';
		} else { echo '<strong style="font-size: 14px">'. __( 'Last backups from your database:','mainwp' ) . '</strong>' . $output;}
		echo '</table>';
	}


	public static function renderSettings() {

		$backupsOnServer = get_option( 'mainwp_backupsOnServer' );
		$backupOnExternalSources = get_option( 'mainwp_backupOnExternalSources' );
		$archiveFormat = get_option( 'mainwp_archiveFormat' );
		$maximumFileDescriptors = get_option( 'mainwp_maximumFileDescriptors' );
		$maximumFileDescriptorsAuto = get_option( 'mainwp_maximumFileDescriptorsAuto' );
		$maximumFileDescriptorsAuto = ($maximumFileDescriptorsAuto == 1 || $maximumFileDescriptorsAuto === false);

		$notificationOnBackupFail = get_option( 'mainwp_notificationOnBackupFail' );
		$notificationOnBackupStart = get_option( 'mainwp_notificationOnBackupStart' );
		$chunkedBackupTasks = get_option( 'mainwp_chunkedBackupTasks' );
        $enableLegacyBackupFeature = get_option( 'mainwp_enableLegacyBackupFeature' );
                
		$loadFilesBeforeZip = get_option( 'mainwp_options_loadFilesBeforeZip' );
		$loadFilesBeforeZip = ($loadFilesBeforeZip == 1 || $loadFilesBeforeZip === false);

		$primaryBackup = get_option( 'mainwp_primaryBackup' );
		$primaryBackupMethods = apply_filters( 'mainwp-getprimarybackup-methods', array() );
		if ( ! is_array( $primaryBackupMethods ) ) {
			$primaryBackupMethods = array();
		}

		global $mainwpUseExternalPrimaryBackupsMethod;
		$hiddenCls = '';
		if ( !$enableLegacyBackupFeature || (! empty( $primaryBackup ) && $primaryBackup == $mainwpUseExternalPrimaryBackupsMethod )) {
			$hiddenCls = 'class="hidden"';
		}

		?>
    <table class="form-table">
        <tbody>
            <?php if ( count( $primaryBackupMethods ) == 0 ) { ?>
                <tr>
		<div class="mainwp-notice mainwp-notice-blue"><?php echo sprintf( __('Did you know that MainWP has extensions for working with popular backup plugins? Visit the %sextensions site%s for options.', 'mainwp' ), '<a href="https://mainwp.com/extensions/extension-category/backups/" target="_blank" ?>', '</a>' ); ?></div>
                </tr>
            <?php } ?>
                <tr>
            <th scope="row"><?php _e( 'Enable legacy backup feature','mainwp' ); ?></th>
               <td>
                 <div class="mainwp-checkbox">
                   <input type="checkbox" id="mainwp_options_enableLegacyBackupFeature" name="mainwp_options_enableLegacyBackupFeature"  <?php echo ($enableLegacyBackupFeature == 0 ? '' : 'checked="checked"'); ?> />
                   <label for="mainwp_options_enableLegacyBackupFeature"></label>
                </div>
                <em><?php echo sprintf(__('It is highly recommended to use some of our %sBackup Extensions%s instead of the Legacy Backups. MainWP is actively moving away from the legacy backups feature.', 'mainwp'), '<a href="https://mainwp.com/mainwp-extensions/extension-category/backup/" target="_blank">', '</a>'); ?></em>
            </td>
        </tr>
        <?php
		if ( count( $primaryBackupMethods ) > 0 ) {
		?>
        <tr>
            <th scope="row"><?php _e( 'Select primary backup system','mainwp' ); ?></th>
               <td>
                <span><select class="mainwp-select2-super" name="mainwp_primaryBackup" id="mainwp_primaryBackup">
                        <?php 
                        if ($enableLegacyBackupFeature) { 
                        ?>
                        <option value="" ><?php echo __('Default MainWP Backups', 'mainwp')?></option>
                        <?php
                        } else {
                        ?>
                        <option value="" ><?php echo __('N/A', 'mainwp')?></option>
                        <?php
                        }
                        ?>
                        <?php
						foreach ( $primaryBackupMethods as $method ) {
							echo '<option value="' . $method['value'] . '" ' . (($primaryBackup == $method['value']) ? 'selected' : '') . '>' . $method['title'] . '</option>';
						}
						?>
                </select><label></label></span>
            </td>
        </tr>
        <?php } ?>
        <tr <?php echo $hiddenCls; ?> >
            <th scope="row"><?php _e( 'Backups on server', 'mainwp' ); ?>&nbsp;<?php MainWP_Utility::renderToolTip( 'The number of backups to keep on your server. This does not affect external sources. 0 is not allowed, the backups always require one local backup to upload to external sources.', 'http://docs.mainwp.com/recurring-backups-with-mainwp/' ); ?></th>
            <td>
                <input type="text" name="mainwp_options_backupOnServer"  class=""
                       value="<?php echo ($backupsOnServer === false ? 1 : $backupsOnServer); ?>"/>
            </td>
        </tr>
        <tr <?php echo $hiddenCls; ?>>
            <th scope="row"><?php _e( 'Backups on remote storage','mainwp' ); ?>&nbsp;<?php MainWP_Utility::renderToolTip( 'The number of backups to keep on your external sources. This does not affect backups on the server. 0 sets unlimited.', 'http://docs.mainwp.com/recurring-backups-with-mainwp/' ); ?></th>
            <td>
                <input type="text" name="mainwp_options_backupOnExternalSources"  class=""
                       value="<?php echo ($backupOnExternalSources === false ? 1 : $backupOnExternalSources); ?>"/><span class="mainwp-form_hint"><?php _e( 'The number of backups to keep on your external sources.  This does not affect backups on the server.  0 sets unlimited.','mainwp' ); ?></span>
            </td>
        </tr>
        <tr <?php echo $hiddenCls; ?>>
            <th scope="row"><?php _e( 'Archive format','mainwp' ); ?>&nbsp;</th>
            <td>
                <table class="mainwp-nomarkup">
                    <tr>
                        <td valign="top">
                            <span class="mainwp-select-bg"><select class="mainwp-select2" name="mainwp_archiveFormat" id="mainwp_archiveFormat">
                                <option value="zip" <?php if ( $archiveFormat == 'zip' ) :  ?>selected<?php endif; ?>>Zip</option>
                                <option value="tar" <?php if ( $archiveFormat == 'tar' ) :  ?>selected<?php endif; ?>>Tar</option>
                                <option value="tar.gz" <?php if ( ($archiveFormat === false) || ($archiveFormat == 'tar.gz') ) :  ?>selected<?php endif; ?>>Tar GZip</option>
                                <option value="tar.bz2" <?php if ( $archiveFormat == 'tar.bz2' ) :  ?>selected<?php endif; ?>>Tar BZip2</option>
                            </select><label></label></span>
                        </td>
                        <td>
                            <i>
                            <span id="info_zip" class="archive_info" <?php if ( $archiveFormat != 'zip' ) :  ?>style="display: none;"<?php endif; ?>><?php _e( 'Uses PHP native Zip-library, when missing, the PCLZip library included in WordPress will be used. (Good compression, fast with native zip-library)','mainwp' ); ?></span>
                            <span id="info_tar" class="archive_info" <?php if ( $archiveFormat != 'tar' ) :  ?>style="display: none;"<?php endif; ?>><?php _e( 'Creates an uncompressed tar-archive. (No compression, fast, low memory usage)','mainwp' ); ?></span>
                            <span id="info_tar.gz" class="archive_info" <?php if ( $archiveFormat != 'tar.gz' && $archiveFormat !== false ) :  ?>style="display: none;"<?php endif; ?>><?php _e( 'Creates a GZipped tar-archive. (Good compression, fast, low memory usage)','mainwp' ); ?></span>
                            <span id="info_tar.bz2" class="archive_info" <?php if ( $archiveFormat != 'tar.bz2' ) :  ?>style="display: none;"<?php endif; ?>><?php _e( 'Creates a BZipped tar-archive. (Best compression, fast, low memory usage)','mainwp' ); ?></span>
                            </i>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
        <tr class="archive_method archive_zip <?php echo ! empty( $hiddenCls ) ? 'hidden' : ''; ?>" <?php if ( $archiveFormat != 'zip' ) :  ?>style="display: none;"<?php endif; ?>>
            <th scope="row"><?php _e( 'Maximum file descriptors on child','mainwp' ); ?>&nbsp;<?php MainWP_Utility::renderToolTip( 'The maximum number of open file descriptors on the child hosting.', 'http://docs.mainwp.com/maximum-number-of-file-descriptors/' ); ?></th>
            <td>
            <table>
            	<tr>
            		<td><?php _e( 'Auto detect:', 'mainwp'); ?></td>
            		<td><div class="mainwp-checkbox"><input type="checkbox" id="mainwp_maximumFileDescriptorsAuto" name="mainwp_maximumFileDescriptorsAuto" <?php echo ($maximumFileDescriptorsAuto ? 'checked="checked"' : ''); ?> /> <label for="mainwp_maximumFileDescriptorsAuto"></label></div></td>
            	</tr>
            </table>
                <input type="text" name="mainwp_options_maximumFileDescriptors" id="mainwp_options_maximumFileDescriptors" value="<?php echo ($maximumFileDescriptors === false ? 150 : $maximumFileDescriptors); ?>"/>
                <br/>
                <em>(<?php _e( 'Enter a fallback value because not all hosts support this function.','mainwp' ); ?>)</em>
            </td>
        </tr>
        <tr class="archive_method archive_zip <?php echo ! empty( $hiddenCls ) ? 'hidden' : ''; ?>" <?php if ( $archiveFormat != 'zip' ) :  ?>style="display: none;"<?php endif; ?>>
            <th scope="row"><?php _e( 'Load files in memory before zipping','mainwp' );?>&nbsp;<?php MainWP_Utility::renderToolTip( 'This causes the files to be opened and closed immediately, using less simultaneous I/O operations on the disk. For huge sites with a lot of files we advice to disable this, memory usage will drop but we will use more file handlers when backing up.', 'http://docs.mainwp.com/maximum-number-of-file-descriptors/' ); ?></th>
            <td>
                <div class="mainwp-checkbox">
                <input type="checkbox" id="mainwp_options_loadFilesBeforeZip" name="mainwp_options_loadFilesBeforeZip" <?php echo ($loadFilesBeforeZip ? 'checked="checked"' : ''); ?> />
                <label for="mainwp_options_loadFilesBeforeZip"></label>
                </div>
            </td>
        </tr>
        <tr <?php echo $hiddenCls; ?>>
            <th scope="row">
                <?php _e( 'Send email if a backup fails','mainwp' ); ?></th>
                <td>
                  <div class="mainwp-checkbox">
                    <input type="checkbox" id="mainwp_options_notificationOnBackupFail" name="mainwp_options_notificationOnBackupFail"  <?php echo ($notificationOnBackupFail == 0 ? '' : 'checked="checked"'); ?> />
                    <label for="mainwp_options_notificationOnBackupFail"></label>
                  </div>
               </td>
        </tr>
        <tr <?php echo $hiddenCls; ?>>
            <th scope="row"><?php _e( 'Send email when a backup starts','mainwp' ); ?></th>
               <td>
                 <div class="mainwp-checkbox">
                   <input type="checkbox" id="mainwp_options_notificationOnBackupStart" name="mainwp_options_notificationOnBackupStart"  <?php echo ($notificationOnBackupStart == 0 ? '' : 'checked="checked"'); ?> />
                   <label for="mainwp_options_notificationOnBackupStart"></label>
                </div>
            </td>
        </tr>
        <tr <?php echo $hiddenCls; ?>>
            <th scope="row"><?php _e( 'Execute backup tasks in chunks','mainwp' ); ?></th>
               <td>
                 <div class="mainwp-checkbox">
                   <input type="checkbox" id="mainwp_options_chunkedBackupTasks" name="mainwp_options_chunkedBackupTasks"  <?php echo ($chunkedBackupTasks == 0 ? '' : 'checked="checked"'); ?> />
                   <label for="mainwp_options_chunkedBackupTasks"></label>
                </div>
            </td>
        </tr>        
        </tbody>
    </table>
    <?php
	}


	public static function renderDashboard( &$website, &$page ) {
		if ( ! mainwp_current_user_can( 'dashboard', 'access_individual_dashboard' ) ) {
			mainwp_do_not_have_permissions( __( 'individual dashboard', 'mainwp' ) );
			return;
		}

		?>
            <div id="howto-metaboxes-general" class="wrap">
                <?php
				if ( $website->mainwpdir == -1 ) {
					echo '<div class="mainwp-notice mainwp-notice-yellow"><span class="mainwp_conflict" siteid="' . $website->id . '"><strong>Configuration issue detected</strong>: MainWP has no write privileges to the uploads directory. Because of this some of the functionality might not work, please check <a href="http://docs.mainwp.com/install-or-update-of-a-plugin-fails-on-managed-site/" target="_blank">this FAQ for further information</a></span></div>';
				}
				global $screen_layout_columns;
				MainWP_Main::renderDashboardBody( array( $website ), $page, $screen_layout_columns );
				?>
            </div>
    <?php
	}
        
         public static function renderUpdates() { 
            $website_id = MainWP_Utility::get_current_wpid();
            $total_vulner = 0;
            if ( $website_id ) {
                $website = MainWP_DB::Instance()->getWebsiteById( $website_id );
                MainWP_Main::renderDashboardBody( array($website), null, null, true);
                $total_vulner = apply_filters('mainwp_vulner_getvulner', 0, $website_id);
            }

            if ( $total_vulner > 0 ) {
                ?>
        <div class="mainwp_info-box-red"><?php echo sprintf(_n('There is %d vulnerability update. %sClick here to see all vulnerability issues.%s', 'There are %d vulnerability updates. %sClick here to see all vulnerability issues.%s', $total_vulner, 'mainwp'), $total_vulner, '<a href="admin.php?page=Extensions-Mainwp-Vulnerability-Checker-Extension">', '</a>' ); ?></div>
                <?php
            }
            ?>
            <div class="postbox" id="mainwp_page_updates_tab-contextbox-1">
                <h3 class="mainwp_box_title">
                        <span><i class="fa fa-refresh" aria-hidden="true"></i> <?php _e( 'Updates', 'mainwp' ); ?></span></h3>
                            <div class="inside">                
                            <div id="rightnow_list" xmlns="http://www.w3.org/1999/html"><?php MainWP_Right_Now::renderSites($updates = true); ?></div>
                    </div>
            </div>
            <?php
        }
        
	public static function renderBackupSite( &$website ) {
		if ( ! mainwp_current_user_can( 'dashboard', 'execute_backups' ) ) {
			mainwp_do_not_have_permissions( __( 'execute backups', 'mainwp' ) );
			return;
		}

		$primaryBackupMethods = apply_filters( 'mainwp-getprimarybackup-methods', array() );
		if ( ! is_array( $primaryBackupMethods ) ) {
			$primaryBackupMethods = array();
		}
		?>
        <div class="error below-h2" style="display: none;" id="ajax-error-zone"></div>
        <div id="ajax-information-zone" class="updated" style="display: none;"></div>
        
        <?php if ( count( $primaryBackupMethods ) == 0 ) { ?>
			<div class="mainwp-notice mainwp-notice-blue"><?php echo sprintf( __('Did you know that MainWP has Extensions for working with popular backup plugins? Visit the %sExtensions Site%s for options.', 'mainwp' ), '<a href="https://mainwp.com/extensions/extension-category/backups/" target="_blank" ?>', '</a>' ); ?></div>           
        <?php } 
			MainWP_System::do_mainwp_meta_boxes('mainwp_postboxes_managesites_backup'); 		
			?>
			<div id="managesite-backup-status-box" title="Backup <?php echo stripslashes( $website->name ); ?>" style="display: none; text-align: center">
				<div style="height: 190px; overflow: auto; margin-top: 20px; margin-bottom: 10px; text-align: left" id="managesite-backup-status-text">
				</div>
				<input id="managesite-backup-status-close" type="button" name="Close" value="Cancel" class="button" />
			</div>
                <?php
				}

	public static function renderBackupDetails($post, $metabox) {	
		$websiteid = isset($metabox['args']['websiteid']) ? $metabox['args']['websiteid'] : null;
		$website = MainWP_DB::Instance()->getWebsiteById( $websiteid );		
		if ( empty( $website ) )
			return;			
				MainWP_Manage_Sites::showBackups( $website );
	}
			
	public static function renderBackupOptions($post, $metabox) {	
		$websiteid = isset($metabox['args']['websiteid']) ? $metabox['args']['websiteid'] : null;
		$website = MainWP_DB::Instance()->getWebsiteById( $websiteid );		
		if ( empty( $website ) )
			return;	

		$remote_destinations = apply_filters( 'mainwp_backups_remote_get_destinations', null, array( 'website' => $website->id ) );
		$hasRemoteDestinations = ($remote_destinations == null ? $remote_destinations : count( $remote_destinations ));
				?>
            <form method="POST" action="" id="mainwp_backup_sites_page">
			<input type="hidden" name="site_id" id="backup_site_id" value="<?php echo $website->id; ?>"/>
			<input type="hidden" name="backup_site_full_size" id="backup_site_full_size" value="<?php echo $website->totalsize; ?>"/>
			<input type="hidden" name="backup_site_db_size" id="backup_site_db_size" value="<?php echo $website->dbsize; ?>"/>							
            <table class="form-table">
                <tbody>
                <tr>
					 <th scope="row"><?php _e( 'Backup file name:','mainwp' ); ?></th>
					 <td><input type="text" name="backup_filename" id="backup_filename" value="" class="" />
                    </td>
                </tr>
                <tr><td colspan="2"><hr /></td></tr>
                <tr>
                    <th scope="row"><?php _e( 'Backup type:','mainwp' ); ?></th>
                    <td>
                        <a class="mainwp_action left mainwp_action_down" href="#" id="backup_type_full"><?php _e( 'FULL BACKUP','mainwp' ); ?></a><a class="mainwp_action right" href="#" id="backup_type_db"><?php _e( 'DATABASE BACKUP','mainwp' ); ?></a>
                    </td>
                </tr>
                <tr class="mainwp_backup_exclude_files_content"><td colspan="2"><hr /></td></tr>
                <tr class="mainwp-exclude-suggested">
					 <th scope="row" style="vertical-align: top"><?php _e( 'Suggested exclude', 'mainwp' ); ?>:</th>
                    <td><p style="background: #7fb100; color: #ffffff; padding: .5em;"><?php _e( 'Every WordPress website is different but the sections below generally do not need to be backed up and since many of them are large in size they can even cause issues with your backup including server timeouts.', 'mainwp' ); ?></p></td>
                </tr>
                <tr class="mainwp-exclude-backup-locations">
					 <td colspan="2"><h4><i class="fa fa-cloud-upload"></i> <?php _e( 'Known backup locations', 'mainwp' ); ?></h4></td>
                </tr>
                <tr class="mainwp-exclude-backup-locations">
                    <td><label for="mainwp-known-backup-locations"><?php _e( 'Exclude', 'mainwp' ); ?></label><input type="checkbox" id="mainwp-known-backup-locations" checked></td>
					 <td class="mainwp-td-des"><a href="#" id="mainwp-show-kbl-folders"><?php _e( '+ Show excluded folders', 'mainwp' ); ?></a><a href="#" id="mainwp-hide-kbl-folders"><?php _e( '- Hide excluded folders', 'mainwp' ); ?></a><br/>
                        <textarea id="mainwp-kbl-content" disabled></textarea>
                        <br/><?php _e( 'This adds known backup locations of popular WordPress backup plugins to the exclude list. Old backups can take up a lot of space and can cause your current MainWP backup to timeout.', 'mainwp' ); ?></td>
                </tr>
                <tr class="mainwp-exclude-separator"><td colspan="2" style="padding: 0 !important;"><hr /></td></tr>
                <tr class="mainwp-exclude-cache-locations">
					 <td colspan="2"><h4><i class="fa fa-cubes"></i> <?php _e( 'Known cache locations', 'mainwp' ); ?></h4></td>
                </tr>
                <tr class="mainwp-exclude-cache-locations">
                    <td><label for="mainwp-known-cache-locations"><?php _e( 'Exclude', 'mainwp' ); ?></label><input type="checkbox" id="mainwp-known-cache-locations" checked></td>
					 <td class="mainwp-td-des"><a href="#" id="mainwp-show-kcl-folders"><?php _e( '+ Show excluded folders', 'mainwp' ); ?></a><a href="#" id="mainwp-hide-kcl-folders"><?php _e( '- Hide excluded folders', 'mainwp' ); ?></a><br/>
                        <textarea id="mainwp-kcl-content" disabled></textarea>
                        <br/><?php _e( 'This adds known cache locations of popular WordPress cache plugins to the exclude list. A cache can be massive with thousands of files and can cause your current MainWP backup to timeout.  Your cache will be rebuilt by your caching plugin when the backup is restored.', 'mainwp' ); ?></td>
                </tr>
                <tr class="mainwp-exclude-separator"><td colspan="2" style="padding: 0 !important;"><hr /></td></tr>
                <tr class="mainwp-exclude-nonwp-folders">
					 <td colspan="2"><h4><i class="fa fa-folder"></i> <?php _e( 'Non-WordPress folders', 'mainwp' ); ?></h4></td>
                </tr>
                <tr class="mainwp-exclude-nonwp-folders">
                    <td><label for="mainwp-non-wordpress-folders"><?php _e( 'Exclude', 'mainwp' ); ?></label><input type="checkbox" id="mainwp-non-wordpress-folders" checked></td>
					 <td class="mainwp-td-des"><a href="#" id="mainwp-show-nwl-folders"><?php _e( '+ Show excluded folders', 'mainwp' ); ?></a><a href="#" id="mainwp-hide-nwl-folders"><?php _e( '- Hide excluded folders', 'mainwp' ); ?></a><br/>
                        <textarea id="mainwp-nwl-content" disabled></textarea>
                        <br/><?php _e( 'This adds folders that are not part of the WordPress core (wp-admin, wp-content and wp-include) to the exclude list. Non-WordPress folders can contain a large amount of data or may be a sub-domain or add-on domain that should be backed up individually and not with this backup.', 'mainwp' ); ?></td>
                </tr>
                <tr class="mainwp-exclude-separator"><td colspan="2" style="padding: 0 !important;"><hr /></td></tr>
                <tr class="mainwp-exclude-zips">
                    <td colspan="2"><h4><i class="fa fa-file-archive-o"></i> <?php _e( 'ZIP archives', 'mainwp' ); ?></h4></td>
                </tr>
                <tr class="mainwp-exclude-zips">
                    <td><label for="mainwp-zip-archives"><?php _e( 'Exclude', 'mainwp' ); ?></label><input type="checkbox" id="mainwp-zip-archives" checked></td>
                    <td class="mainwp-td-des"><?php _e( 'Zip files can be large and are often not needed for a WordPress backup. Be sure to deselect this option if you do have zip files you need backed up.', 'mainwp' ); ?></td>
                </tr>
                <tr class="mainwp-exclude-separator"><td colspan="2" style="padding: 0 !important;"><hr /></td></tr>
                <tr class="mainwp_backup_exclude_files_content">
                    <th scope="row" style="vertical-align: top"><h4 class="mainwp-custom-excludes"><i class="fa fa-minus-circle"></i> <?php _e( 'Custom excludes', 'mainwp' ); ?></h4></th>
                    <td>
                        <p style="background: #7fb100; color: #ffffff; padding: .5em;"><?php _e( 'Exclude any additional files that you do not need backed up for this site. Click a folder name to drill down into the directory.', 'mainwp' ); ?></p>
                        <br />
                        <?php printf( __( 'Click directories to navigate. Click the red sign ( %s ) to exclude a folder.','mainwp' ), '<img style="margin-bottom: -3px;" src="' . plugins_url( 'images/exclude.png', dirname( __FILE__ ) ) . '">' ); ?><br /><br />
                        <table class="mainwp_excluded_folders_cont">
                            <tr>
                                <td style="width: 280px;">
                                    <div id="backup_exclude_folders"
                                         siteid="<?php echo $website->id; ?>"
                                         class="mainwp_excluded_folders"></div>
                                </td>
                                <td>
                                    <?php _e( 'Excluded files & directories:','mainwp' ); ?><br/>
                                    <textarea id="excluded_folders_list"></textarea>
                                </td>
                            </tr>
                        </table>
                        <span class="description"><strong><?php _e( 'ATTENTION:','mainwp' ); ?></strong> <?php _e( 'Do not exclude any folders if you are using this backup to clone or migrate the wordpress installation.','mainwp' ); ?></span>
                    </td>
                </tr>
                <?php
				if ( $hasRemoteDestinations !== null ) {
				?>
                <tr><td colspan="2"><hr /></td></tr>
                <tr>
                    <th scope="row"><?php _e( 'Store Backup In:','mainwp' ); ?></th>
                    <td>
                        <a class="mainwp_action left <?php echo ( ! $hasRemoteDestinations ? 'mainwp_action_down' : ''); ?>" href="#" id="backup_location_local"><?php _e( 'LOCAL SERVER ONLY','mainwp' ); ?></a><a class="mainwp_action right <?php echo ($hasRemoteDestinations ? 'mainwp_action_down' : ''); ?>" href="#" id="backup_location_remote"><?php _e( 'REMOTE DESTINATION','mainwp' ); ?></a>
                    </td>
                </tr>
                <tr class="mainwp_backup_destinations" <?php echo ( ! $hasRemoteDestinations ? 'style="display: none;"' : ''); ?>>
                    <th scope="row"><?php _e( 'Backup Subfolder:','mainwp' ); ?></th>
                    <td><input type="text" id="backup_subfolder" name="backup_subfolder"
															value="MainWP Backups/%url%/%type%/%date%"/></td>
                </tr>
                <?php
				}
				?>
                    <?php do_action( 'mainwp_backups_remote_settings', array( 'website' => $website->id ) ); ?>

                <?php
				$globalArchiveFormat = get_option( 'mainwp_archiveFormat' );
				if ( $globalArchiveFormat == false ) {$globalArchiveFormat = 'tar.gz';}
				if ( $globalArchiveFormat == 'zip' ) {
					$globalArchiveFormatText = 'Zip';
				} else if ( $globalArchiveFormat == 'tar' ) {
					$globalArchiveFormatText = 'Tar';
				} else if ( $globalArchiveFormat == 'tar.gz' ) {
					$globalArchiveFormatText = 'Tar GZip';
				} else if ( $globalArchiveFormat == 'tar.bz2' ) {
					$globalArchiveFormatText = 'Tar BZip2';
				}

				$backupSettings = MainWP_DB::Instance()->getWebsiteBackupSettings( $website->id );
				$archiveFormat = $backupSettings->archiveFormat;
				$useGlobal = ($archiveFormat == 'global');
				?>
                <tr><td colspan="2"><hr /></td></tr>
                <tr>
                    <th scope="row"><?php _e( 'Archive format','mainwp' ); ?></th>
                    <td>
                        <table class="mainwp-nomarkup">
                            <tr>
                                <td valign="top">
									 <span class="mainwp-select-bg"><select class="mainwp-select2" name="mainwp_archiveFormat" id="mainwp_archiveFormat">
                                        <option value="global" <?php if ( $useGlobal ) :  ?>selected<?php endif; ?>>Global setting (<?php echo $globalArchiveFormatText; ?>)</option>
                                        <option value="zip" <?php if ( $archiveFormat == 'zip' ) :  ?>selected<?php endif; ?>>Zip</option>
                                        <option value="tar" <?php if ( $archiveFormat == 'tar' ) :  ?>selected<?php endif; ?>>Tar</option>
                                        <option value="tar.gz" <?php if ( $archiveFormat == 'tar.gz' ) :  ?>selected<?php endif; ?>>Tar GZip</option>
                                        <option value="tar.bz2" <?php if ( $archiveFormat == 'tar.bz2' ) :  ?>selected<?php endif; ?>>Tar BZip2</option>
                                    </select><label></label></span>
                                </td>
                                <td>
                                    <i>
                                    <span id="info_global" class="archive_info" <?php if ( ! $useGlobal ) :  ?>style="display: none;"<?php endif; ?>><?php
									if ( $globalArchiveFormat == 'zip' ) :  ?>Uses PHP native Zip-library, when missing, the PCLZip library included in WordPress will be used. (Good compression, fast with native zip-library)<?php
										elseif ( $globalArchiveFormat == 'tar' ) :  ?>Uses PHP native Zip-library, when missing, the PCLZip library included in WordPress will be used. (Good compression, fast with native zip-library)<?php
										elseif ( $globalArchiveFormat == 'tar.gz' ) :  ?>Creates a GZipped tar-archive. (Good compression, fast, low memory usage)<?php
										elseif ( $globalArchiveFormat == 'tar.bz2' ) :  ?>Creates a BZipped tar-archive. (Best compression, fast, low memory usage)<?php endif; ?></span>
                                    <span id="info_zip" class="archive_info" <?php if ( $archiveFormat != 'zip' ) :  ?>style="display: none;"<?php endif; ?>>Uses PHP native Zip-library, when missing, the PCLZip library included in WordPress will be used. (Good compression, fast with native zip-library)</span>
                                    <span id="info_tar" class="archive_info" <?php if ( $archiveFormat != 'tar' ) :  ?>style="display: none;"<?php endif; ?>>Creates an uncompressed tar-archive. (No compression, fast, low memory usage)</span>
                                    <span id="info_tar.gz" class="archive_info" <?php if ( $archiveFormat != 'tar.gz' ) :  ?>style="display: none;"<?php endif; ?>>Creates a GZipped tar-archive. (Good compression, fast, low memory usage)</span>
                                    <span id="info_tar.bz2" class="archive_info" <?php if ( $archiveFormat != 'tar.bz2' ) :  ?>style="display: none;"<?php endif; ?>>Creates a BZipped tar-archive. (Best compression, fast, low memory usage)</span>
                                    </i>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <?php
				$maximumFileDescriptorsOverride = ($website->maximumFileDescriptorsOverride == 1);
				$maximumFileDescriptorsAuto = ($website->maximumFileDescriptorsAuto == 1);
				$maximumFileDescriptors = $website->maximumFileDescriptors;
				?>
                <tr class="archive_method archive_zip" <?php if ( $archiveFormat != 'zip' ) :  ?>style="display: none;"<?php endif; ?>>
                    <th scope="row"><?php _e( 'Maximum File Descriptors on Child','mainwp' ); ?>&nbsp;<?php MainWP_Utility::renderToolTip( 'The maximum number of open file descriptors on the child hosting.', 'http://docs.mainwp.com/maximum-number-of-file-descriptors/' ); ?></th>
                    <td>
						<input type="radio" value="" name="mainwp_options_maximumFileDescriptorsOverride" id="mainwp_options_maximumFileDescriptorsOverride_global" <?php echo ( ! $maximumFileDescriptorsOverride ? 'checked="true"' : ''); ?>/>
						<label for="mainwp_options_maximumFileDescriptorsOverride_global"><?php _e( 'Global Setting', 'mainwp' ); ?> (<a href="<?php echo admin_url( 'admin.php?page=Settings' ); ?>"><?php _e( 'Change Here', 'mainwp' ); ?></a>)</label>
						<br/>
						<input type="radio" value="override" name="mainwp_options_maximumFileDescriptorsOverride" id="mainwp_options_maximumFileDescriptorsOverride_override" <?php echo ($maximumFileDescriptorsOverride ? 'checked="true"' : ''); ?> />
						<label for="mainwp_options_maximumFileDescriptorsOverride_override"><?php _e( 'Override','mainwp' ); ?></label>
						<table>
							<tr>
								<td><?php _e( 'Auto detect:','mainwp' ); ?></td>
								<td><div class="mainwp-checkbox"><input type="checkbox" id="mainwp_maximumFileDescriptorsAuto" name="mainwp_maximumFileDescriptorsAuto" <?php echo ($maximumFileDescriptorsAuto ? 'checked="checked"' : ''); ?> /> <label for="mainwp_maximumFileDescriptorsAuto"></label></div></td>
							</tr>
						</table>
						 <input type="text" 
						 		name="mainwp_options_maximumFileDescriptors" 
						 		id="mainwp_options_maximumFileDescriptors"
								value="<?php echo $maximumFileDescriptors; ?>"/><br/>
						<em>(<?php _e( 'Enter a fallback value because not all hosts support this function.','mainwp' ); ?>)</em>
                    </td>
                </tr>
                <tr class="archive_method archive_zip" <?php if ( $archiveFormat != 'zip' ) :  ?>style="display: none;"<?php endif; ?>>
                    <th scope="row"><?php _e( 'Load files in memory before zipping','mainwp' ); ?>&nbsp;<?php MainWP_Utility::renderToolTip( 'This causes the files to be opened and closed immediately, using less simultaneous I/O operations on the disk. For huge sites with a lot of files we advise to disable this, memory usage will drop but we will use more file handlers when backing up.', 'http://docs.mainwp.com/load-files-memory/' ); ?></th>
                    <td>
                        <input type="radio" name="mainwp_options_loadFilesBeforeZip" id="mainwp_options_loadFilesBeforeZip_global" value="1" <?php if ( $website->loadFilesBeforeZip == false || $website->loadFilesBeforeZip == 1 ) :  ?>checked="true"<?php endif; ?>/> Global setting (<a href="<?php echo admin_url( 'admin.php?page=Settings' ); ?>">Change Here</a>)<br />
                        <input type="radio" name="mainwp_options_loadFilesBeforeZip" id="mainwp_options_loadFilesBeforeZip_yes" value="2" <?php if ( $website->loadFilesBeforeZip == 2 ) :  ?>checked="true"<?php endif; ?>/> Yes<br />
                        <input type="radio" name="mainwp_options_loadFilesBeforeZip" id="mainwp_options_loadFilesBeforeZip_no" value="0" <?php if ( $website->loadFilesBeforeZip == 0 ) :  ?>checked="true"<?php endif; ?>/> No<br />
                    </td>
                </tr>
            </table>
			<input type="button" 
					name="backup_btnSubmit" 
					id="backup_btnSubmit"
                                         class="button-primary button button-hero"
                    value="Backup Now"/>
            </form>
    <?php
	}

	public static function renderScanSite( &$website ) {
		if ( ! mainwp_current_user_can( 'dashboard', 'manage_security_issues' ) ) {
			mainwp_do_not_have_permissions( __( 'security scan', 'mainwp' ) );
			return;
		}		
		wp_nonce_field( 'meta-box-order', 'meta-box-order-nonce', false );
		wp_nonce_field( 'closedpostboxes', 'closedpostboxesnonce', false );
		?>
		<div class="metabox-holder columns-1">
		<?php	
		$_postpage = 'mainwp_postboxes_managesites_scan';
		do_meta_boxes($_postpage, 'normal', null );	

		if ( mainwp_current_user_can( 'extension', 'mainwp-sucuri-extension' ) ) {
			if ( MainWP_Extensions::isExtensionAvailable( 'mainwp-sucuri-extension' ) ) {			
				do_action( 'mainwp-sucuriscan-sites', $website );
			}
		}

		if ( mainwp_current_user_can( 'extension', 'mainwp-wordfence-extension' ) ) {
			if ( MainWP_Extensions::isExtensionAvailable( 'mainwp-wordfence-extension' ) ) {			
				do_action( 'mainwp-wordfence-sites', $website );
			} 
		}
		
		?>
                    </div>
		<script type="text/javascript"> var mainwp_postbox_page = '<?php echo $_postpage; ?>';</script>			
		<?php	
		}

	public static function renderScanIssues( $post, $metabox ) {
		$websiteid = isset($metabox['args']['websiteid']) ? $metabox['args']['websiteid'] : null;
		$website = MainWP_DB::Instance()->getWebsiteById( $websiteid );		
		if ( empty( $website ) )
			return;
		if ( mainwp_current_user_can( 'dashboard', 'manage_security_issues' ) ) {			
			do_action( 'mainwp-securityissues-sites', $website );			
	}
	}

	public static function renderSucuriScan( $post, $metabox ) {	
		//metabox show message only
		echo sprintf( __('The Sucuri Scan requires the free Sucuri Extension, please download from %shere%s', 'mainwp' ), '<a href="https://mainwp.com/extension/sucuri/" title="Sucuri">', '</a>' ); 		
	}
	
	public static function renderWordfenceScan( $post, $metabox ) {	
		//metabox show message only
		echo sprintf( __('Wordfence status requires the Wordfence Extension, please order from %shere%s.', 'mainwp' ), '<a href="https://mainwp.com/extension/wordfence/" title="Wordfence">', '</a>' );		
	}
	
	public static function _renderInfo() {

		//todo: RS: Remove method
	}

	public static function _renderNotes() {

		?>
        <div id="mainwp_notes_overlay" class="mainwp_overlay"></div>
        <div id="mainwp_notes" class="mainwp_popup">
            <a id="mainwp_notes_closeX" class="mainwp_closeX" style="display: inline; "></a>

            <div id="mainwp_notes_title" class="mainwp_popup_title"></span>
            </div>
            <div id="mainwp_notes_content">
                <div id="mainwp_notes_html" style="width: 580px !important; height: 300px;"></div>
                <textarea style="width: 580px !important; height: 300px;"
                          id="mainwp_notes_note"></textarea>
            </div>
            <div><em><?php _e( 'Allowed HTML Tags:','mainwp' ); ?> &lt;p&gt;, &lt;strong&gt;, &lt;em&gt;, &lt;br&gt;, &lt;hr&gt;, &lt;a&gt;, &lt;ul&gt;, &lt;ol&gt;, &lt;li&gt;, &lt;h1&gt;, &lt;h2&gt; </em></div><br/>
            <form>
                <div style="float: right" id="mainwp_notes_status"></div>
                <input type="button" class="button cont button-primary" id="mainwp_notes_save" value="<?php esc_attr_e( 'Save note','mainwp' ); ?>"/>
                <input type="button" class="button cont" id="mainwp_notes_edit" value="<?php esc_attr_e( 'Edit','mainwp' ); ?>"/>                
                <input type="button" class="button cont" id="mainwp_notes_view" value="<?php esc_attr_e( 'View','mainwp' ); ?>"/>                                
                <input type="button" class="button cont" id="mainwp_notes_cancel" value="<?php esc_attr_e( 'Close','mainwp' ); ?>"/>
                <input type="hidden" id="mainwp_notes_websiteid" value=""/>
            </form>
        </div>
        <?php
	}

        public static function renderEditSite( $websiteid, $updated ) {
		if ( ! mainwp_current_user_can( 'dashboard', 'edit_sites' ) ) {
			mainwp_do_not_have_permissions( __( 'edit sites', 'mainwp' ) );
			return;
		}

		$website = MainWP_DB::Instance()->getWebsiteById( $websiteid );
		if ( ! MainWP_Utility::can_edit_website( $website ) ) {
			$website = null;
		}
		
		if (empty($website))
			return;
		
		?>
                <div class="error below-h2" style="display: none;" id="ajax-error-zone"></div>
                <div id="ajax-information-zone" class="updated" style="display: none;"></div>
                <?php
                        if ( $updated ) {
                        ?>
                    <div id="mainwp_managesites_edit_message" class="updated"><p><?php _e( 'Website updated.','mainwp' ); ?></p></div>
                    <?php
                        }
                        ?>
                <form method="POST" action="" id="mainwp-edit-single-site-form" enctype="multipart/form-data">
                                <input type="hidden" name="wp_nonce" value="<?php echo wp_create_nonce( 'UpdateWebsite' . $website->id ); ?>" />			
                    <?php 
                                wp_nonce_field( 'meta-box-order', 'meta-box-order-nonce', false );
                                wp_nonce_field( 'closedpostboxes', 'closedpostboxesnonce', false );
                                ?>
                                <div class="metabox-holder columns-1">
                                <?php	
                                $_postpage = 'mainwp_postboxes_managesites_edit';
                                do_meta_boxes($_postpage, 'normal', null );	
                                do_action( 'mainwp-extension-sites-edit', $website );
                                ?>
                                </div>
                                <script type="text/javascript"> var mainwp_postbox_page = '<?php echo $_postpage; ?>';</script>			
                                <p class="submit"><input type="submit" name="submit" id="submit" class="button-primary button button-hero"
                                                value="<?php _e( 'Update Site','mainwp' ); ?>"/></p>
                </form>       
                <?php
	}

        

	public static function renderAllSites( &$website, $updated, $groups, $statusses, $pluginDir ) {
		if ( ! mainwp_current_user_can( 'dashboard', 'edit_sites' ) ) {
			mainwp_do_not_have_permissions( __( 'edit sites', 'mainwp' ) );
			return;
		}

		$website = MainWP_DB::Instance()->getWebsiteById( $websiteid );
		if ( ! MainWP_Utility::can_edit_website( $website ) ) {
			$website = null;
		}
		
		if (empty($website))
			return;
		
		?>
        <div class="error below-h2" style="display: none;" id="ajax-error-zone"></div>
        <div id="ajax-information-zone" class="updated" style="display: none;"></div>
        <?php
		if ( $updated ) {
		?>
            <div id="mainwp_managesites_edit_message" class="updated"><p><?php _e( 'Website updated.','mainwp' ); ?></p></div>
            <?php
		}
		?>
        <form method="POST" action="" id="mainwp-edit-single-site-form" enctype="multipart/form-data">
			<input type="hidden" name="wp_nonce" value="<?php echo wp_create_nonce( 'UpdateWebsite' . $website->id ); ?>" />
            <?php 
			wp_nonce_field( 'meta-box-order', 'meta-box-order-nonce', false );
			wp_nonce_field( 'closedpostboxes', 'closedpostboxesnonce', false );
			?>
			<div class="metabox-holder columns-1">
			<?php	
			$_postpage = 'mainwp_postboxes_managesites_edit';
			do_meta_boxes($_postpage, 'normal', null );	
			do_action( 'mainwp-extension-sites-edit', $website );
			?>
			</div>
			<script type="text/javascript"> var mainwp_postbox_page = '<?php echo $_postpage; ?>';</script>			
			<p class="submit"><input type="submit" name="submit" id="submit" class="button-primary button button-hero"
					value="<?php _e( 'Update Site','mainwp' ); ?>"/></p>
        </form>       
        <?php
	}

	public static function renderSiteGeneralOptions( $post, $metabox ) {
		$websiteid = isset($metabox['args']['websiteid']) ? $metabox['args']['websiteid'] : null;
		$website = MainWP_DB::Instance()->getWebsiteById( $websiteid );		
		if ( empty( $website ) )
			return;
		
		$groups    = MainWP_DB::Instance()->getGroupsForCurrentUser();
		$pluginDir = $website->pluginDir;
		
		?>
            <table class="form-table">
                <tbody>
                <tr>
                    <th scope="row"><?php _e( 'Site URL','mainwp' ); ?></th>
                    <td><select class="mainwp-select2-wpurl-protocol" id="mainwp_managesites_edit_siteurl_protocol" name="mainwp_managesites_edit_siteurl_protocol"><option <?php echo (MainWP_Utility::startsWith($website->url, 'http:') ? 'selected' : ''); ?> value="http">http://</option><option <?php echo (MainWP_Utility::startsWith($website->url, 'https:') ? 'selected' : ''); ?> value="https">https://</option></select> <input type="text" id="mainwp_managesites_edit_siteurl" style="width: 262px !important" disabled="disabled"
                               value="<?php echo MainWP_Utility::removeHttpPrefix($website->url, true); ?>" class="regular-text" /></td>
                </tr>
                <tr>
                    <th scope="row"><?php _e( 'Administrator username','mainwp' ); ?></th>
                    <td><input type="text" name="mainwp_managesites_edit_siteadmin"
                               id="mainwp_managesites_edit_siteadmin"
                               value="<?php echo $website->adminname; ?>"
                               class="regular-text"/></td>
                </tr>
                <tr>
                    <th scope="row"><?php _e( 'Friendly site name','mainwp' ); ?></th>
                    <td><input type="text" name="mainwp_managesites_edit_sitename"
                               value="<?php echo stripslashes( $website->name ); ?>" class="regular-text"/></td>
                </tr>
                <tr>
                    <th scope="row"><?php _e( 'Groups','mainwp' ); ?></th>
                    <td>
						<select 
                               name="selected_groups[]"
                               id="mainwp_managesites_edit_addgroups"  style="width: 350px"
							   multiple="multiple" /><?php 						
							$groupsSite = MainWP_DB::Instance()->getGroupsByWebsiteId( $website->id );
							foreach ($groups as $group)
							{
								echo '<option value="' . $group->id . '" ' . (isset( $groupsSite[ $group->id ] ) && $groupsSite[ $group->id ] ? 'selected="selected"' : '') . ' >' . stripslashes($group->name)  . '</option>';
							}
						?></select>						                                                
                    </td>
                </tr>
				<script type="text/javascript">
					jQuery( document ).ready( function () {			
						<?php if (count($groups) == 0) { ?>
						jQuery('#mainwp_managesites_edit_addgroups').select2({minimumResultsForSearch: 10, allowClear: true, placeholder: "<?php _e("No groups added yet.", 'mainwp'); ?>"});
						//jQuery('#mainwp_managesites_edit_addgroups').prop("disabled", true);
						<?php } else { ?>
						jQuery('#mainwp_managesites_edit_addgroups').select2({minimumResultsForSearch: 10, allowClear: true, tags: true});
						<?php } ?>
					});
				</script>
                <tr>
                    <th scope="row"><?php _e( 'Client plugin folder option','mainwp' ); ?>&nbsp;<?php MainWP_Utility::renderToolTip( 'Default, files/folders on the child site are viewable.<br />Hidden, when attempting to view files a 404 file will be returned, however a footprint does still exist.<br /><strong>Hiding the child plugin does require the plugin to make changes to your .htaccess file that in rare instances or server configurations could cause problems.</strong>' ); ?></th>
                    <td>
						<input type="radio" value="" name="mainwp_options_footprint_plugin_folder" id="mainwp_options_footprint_plugin_folder_global" <?php echo ($pluginDir == '' ? 'checked="true"' : ''); ?> />
                        <label for="mainwp_options_footprint_plugin_folder_global"><?php _e( 'Global Setting', 'mainwp' ); ?> (<a href="<?php echo admin_url( 'admin.php?page=Settings#network-footprint' ); ?>" ><?php _e( 'Change Here', 'mainwp' ); ?></a>)</label>
                        <br/>
                        <input type="radio" value="default" name="mainwp_options_footprint_plugin_folder" id="mainwp_options_footprint_plugin_folder_default" <?php echo ($pluginDir == 'default' ? 'checked="true"' : ''); ?> />
                        <label for="mainwp_options_footprint_plugin_folder_default"><?php _e( 'Default', 'mainwp' ); ?></label>
                        <br/>
                          <input type="radio" value="hidden" name="mainwp_options_footprint_plugin_folder" id="mainwp_options_footprint_plugin_folder_hidden" <?php echo ($pluginDir == 'hidden' ? 'checked="true"' : ''); ?>/>
                        <label for="mainwp_options_footprint_plugin_folder_hidden"><?php _e( 'Hidden (<strong>Note: </strong><em>If the heatmap is turned on, the heatmap javascript will still be visible.</em>)', 'mainwp' ); ?></label>
                    </td>
                </tr>               
                <tr>
                    <th scope="row"><?php _e( 'Require backup before update','mainwp' ); ?>&nbsp;<?php MainWP_Utility::renderToolTip( __( 'Backup only works when enabled in the global settings as well.','mainwp' ), admin_url( 'admin.php?page=Settings' ) ); ?></th>
                    <td>
                         <select class="mainwp-select2" id="mainwp_backup_before_upgrade" name="mainwp_backup_before_upgrade">
                             <option <?php echo ($website->backup_before_upgrade == 1) ? 'selected' : ''; ?> value="1"><?php _e( 'Yes','mainwp' ); ?></option>
                             <option <?php echo ($website->backup_before_upgrade == 0) ? 'selected' : ''; ?> value="0"><?php _e( 'No','mainwp' ); ?></option>
                             <option <?php echo ($website->backup_before_upgrade == 2) ? 'selected' : ''; ?> value="2"><?php _e( 'Use global setting','mainwp' ); ?></option>
                         </select> <i>(<?php _e( 'Default','mainwp' ); ?>: <?php _e( 'Use Global Setting','mainwp' ); ?>)</i>
                         
                    </td>
                </tr>
                 <tr>
                    <th scope="row"><?php _e( 'Auto update core','mainwp' ); ?>&nbsp;<?php MainWP_Utility::renderToolTip( 'Auto update only works when enabled in the global settings as well.', admin_url( 'admin.php?page=Settings' ) ); ?></th>
                    <td>
                        <div class="mainwp-checkbox">
                        <input type="checkbox" name="mainwp_automaticDailyUpdate"
                               id="mainwp_automaticDailyUpdate" <?php echo ($website->automatic_update == 1 ? 'checked="true"' : ''); ?> />
                        <label for="mainwp_automaticDailyUpdate"></label>
                        </div>
                    </td>
                </tr>
                <?php if ( mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) { ?>
                <tr>
                    <th scope="row"><?php _e( 'Ignore core updates','mainwp' ); ?>&nbsp;<?php MainWP_Utility::renderToolTip( 'Set to YES if you want to ignore core updates.' ); ?></th>
                    <td>
                        <div class="mainwp-checkbox">
                        <input type="checkbox" name="mainwp_is_ignoreCoreUpdates"
                               id="mainwp_is_ignoreCoreUpdates" <?php echo ($website->is_ignoreCoreUpdates == 1 ? 'checked="true"' : ''); ?> />
                        <label for="mainwp_is_ignoreCoreUpdates"></label>
                        </div>
                    </td>
                </tr>  
                <tr>
                    <th scope="row"><?php _e( 'Ignore all plugin updates','mainwp' ); ?>&nbsp;<?php MainWP_Utility::renderToolTip( 'Set to YES if you want to ignore all plugin updates.' ); ?></th>
                    <td>
                        <div class="mainwp-checkbox">
                        <input type="checkbox" name="mainwp_is_ignorePluginUpdates"
                               id="mainwp_is_ignorePluginUpdates" <?php echo ($website->is_ignorePluginUpdates == 1 ? 'checked="true"' : ''); ?> />
                        <label for="mainwp_is_ignorePluginUpdates"></label>
                        </div>
                    </td>
                </tr>  
                <tr>
                    <th scope="row"><?php _e( 'Ignore all theme updates','mainwp' ); ?>&nbsp;<?php MainWP_Utility::renderToolTip( 'Set to YES if you want to ignore all theme updates.' ); ?></th>
                    <td>
                        <div class="mainwp-checkbox">
                        <input type="checkbox" name="mainwp_is_ignoreThemeUpdates"
                               id="mainwp_is_ignoreThemeUpdates" <?php echo ($website->is_ignoreThemeUpdates == 1 ? 'checked="true"' : ''); ?> />
                        <label for="mainwp_is_ignoreThemeUpdates"></label>
                        </div>
                    </td>
                </tr>
                <?php } ?>
                <?php do_action( 'mainwp_extension_sites_edit_tablerow', $website ); ?>
                </tbody>
            </table>
		<?php
	}
	
	public static function renderSiteAdvancedOptions( $post, $metabox ) {
		$websiteid = isset($metabox['args']['websiteid']) ? $metabox['args']['websiteid'] : null;
		$website = MainWP_DB::Instance()->getWebsiteById( $websiteid );		
		if ( empty( $website ) )
			return;	
			
		?>
            <table class="form-table" style="width: 100%">
                <tr class="form-field form-required">
                    <th scope="row"><?php _e('Child unique security ID ','mainwp'); ?>&nbsp;<?php MainWP_Utility::renderToolTip( 'The unique security ID adds additional protection between the child plugin and your MainWP Dashboard. The unique security ID will need to match when being added to the MainWP Dashboard. This is additional security and should not be needed in most situations.' ); ?></th>
                    <td><input type="text" id="mainwp_managesites_edit_uniqueId"
						 name="mainwp_managesites_edit_uniqueId" value="<?php echo $website->uniqueId; ?>" class=""/></td>
                </tr>                
                 <tr class="form-field form-required">
                    <th scope="row"><?php _e( 'Verify certificate','mainwp' ); ?>&nbsp;<?php MainWP_Utility::renderToolTip( __( 'Verify the childs SSL certificate. This should be disabled if you are using out of date or self signed certificates.','mainwp' ) ); ?></th>
                    <td>
					<select class="mainwp-select2" id="mainwp_managesites_edit_verifycertificate" name="mainwp_managesites_edit_verifycertificate">
                             <option <?php echo ($website->verify_certificate == 1) ? 'selected' : ''; ?> value="1"><?php _e( 'Yes','mainwp' ); ?></option>
                             <option <?php echo ($website->verify_certificate == 0) ? 'selected' : ''; ?> value="0"><?php _e( 'No','mainwp' ); ?></option>
                             <option <?php echo ($website->verify_certificate == 2) ? 'selected' : ''; ?> value="2"><?php _e( 'Use global setting','mainwp' ); ?></option>
                         </select> <i>(Default: Yes)</i>
                    </td>
                </tr>
                <tr class="form-field form-required">
                   <th scope="row"><?php _e( 'SSL version','mainwp' ); ?>&nbsp;<?php MainWP_Utility::renderToolTip( __( 'Prefered SSL version to connect to your site.','mainwp' ) ); ?></th>
                    <td>
					<select class="mainwp-select2" id="mainwp_managesites_edit_ssl_version" name="mainwp_managesites_edit_ssl_version">
                             <option <?php echo ($website->ssl_version == 'auto') ? 'selected' : ''; ?> value="auto"><?php _e( 'Auto detect','mainwp' ); ?></option>
                             <option <?php echo ($website->ssl_version == '1.2') ? 'selected' : ''; ?> value="1.2"><?php _e( "Let's encrypt (TLS v1.2)",'mainwp' ); ?></option>
                             <option <?php echo ($website->ssl_version == '1.x') ? 'selected' : ''; ?> value="1.x"><?php _e( 'TLS v1.x','mainwp' ); ?></option>
                             <option <?php echo ($website->ssl_version == '2') ? 'selected' : ''; ?> value="2"><?php _e( 'SSL v2','mainwp' ); ?></option>
                             <option <?php echo ($website->ssl_version == '3') ? 'selected' : ''; ?> value="3"><?php _e( 'SSL v3','mainwp' ); ?></option>
                             <option <?php echo ($website->ssl_version == '1.0') ? 'selected' : ''; ?> value="1.0"><?php _e( 'TLS v1.0','mainwp' ); ?></option>
                             <option <?php echo ($website->ssl_version == '1.1') ? 'selected' : ''; ?> value="1.1"><?php _e( 'TLS v1.1','mainwp' ); ?></option>                             
                         </select> <em>(<?php _e( 'Default: Auto detect','mainwp' ); ?>)</em>
                    </td>
                </tr>

                <!-- fake fields are a workaround for chrome autofill getting the wrong fields -->
                <input style="display:none" type="text" name="fakeusernameremembered"/>
                <input style="display:none" type="password" name="fakepasswordremembered"/>

                <tr class="form-field form-required">
                     <th scope="row"><?php _e( 'HTTP username ','mainwp' ); ?></th>
				 <td><input type="text" id="mainwp_managesites_edit_http_user" name="mainwp_managesites_edit_http_user" value="<?php echo (empty( $website->http_user ) ? '' : $website->http_user); ?>" autocomplete="new-http-user" class=""/><br/><em><?php _e( 'If your Child Site is protected with HTTP basic authentication, please set the username for authentication here.','mainwp' ); ?></em></td>
                </tr>
                <tr class="form-field form-required">
                     <th scope="row"><?php _e( 'HTTP password ','mainwp' ); ?></th>
				 <td><input type="password" id="mainwp_managesites_edit_http_pass" name="mainwp_managesites_edit_http_pass" value="<?php echo (empty( $website->http_pass ) ? '' : $website->http_pass); ?>" autocomplete="new-http-password" class=""/><br/><em><?php _e( 'If your Child Site is protected with HTTP basic authentication, please set the password for authentication here.','mainwp' ); ?></em></td>
                </tr>
            </table>
	<?php
	}	
            
	public static function renderSiteBackupSettings( $post, $metabox ) {
		$websiteid = isset($metabox['args']['websiteid']) ? $metabox['args']['websiteid'] : null;
		$website = MainWP_DB::Instance()->getWebsiteById( $websiteid );		
		if ( empty( $website ) )
			return;
		
		$remote_destinations = apply_filters( 'mainwp_backups_remote_get_destinations', null, array( 'website' => $website->id ) );
		$hasRemoteDestinations = ($remote_destinations == null ? $remote_destinations : count( $remote_destinations ));
		
	?>
            <table class="form-table" style="width: 100%">
                <?php
				$globalArchiveFormat = get_option( 'mainwp_archiveFormat' );
				if ( $globalArchiveFormat == false ) {$globalArchiveFormat = 'tar.gz';}
				if ( $globalArchiveFormat == 'zip' ) {
					$globalArchiveFormatText = 'Zip';
				} else if ( $globalArchiveFormat == 'tar' ) {
					$globalArchiveFormatText = 'Tar';
				} else if ( $globalArchiveFormat == 'tar.gz' ) {
					$globalArchiveFormatText = 'Tar GZip';
				} else if ( $globalArchiveFormat == 'tar.bz2' ) {
					$globalArchiveFormatText = 'Tar BZip2';
				}

				$backupSettings = MainWP_DB::Instance()->getWebsiteBackupSettings( $website->id );
				$archiveFormat = $backupSettings->archiveFormat;
				$useGlobal = ($archiveFormat == 'global');
				?>
                <tr>
                    <th scope="row"><?php _e( 'Archive format','mainwp' ); ?>&nbsp;</th>
                    <td>
                        <table class="mainwp-nomarkup">
                            <tr>
                                <td valign="top">
								<span class="mainwp-select-bg"><select class="mainwp-select2" name="mainwp_archiveFormat" id="mainwp_archiveFormat">
                                        <option value="global" <?php if ( $useGlobal ) :  ?>selected<?php endif; ?>>Global setting (<?php echo $globalArchiveFormatText; ?>)</option>
                                        <option value="zip" <?php if ( $archiveFormat == 'zip' ) :  ?>selected<?php endif; ?>>Zip</option>
                                        <option value="tar" <?php if ( $archiveFormat == 'tar' ) :  ?>selected<?php endif; ?>>Tar</option>
                                        <option value="tar.gz" <?php if ( $archiveFormat == 'tar.gz' ) :  ?>selected<?php endif; ?>>Tar GZip</option>
                                        <option value="tar.bz2" <?php if ( $archiveFormat == 'tar.bz2' ) :  ?>selected<?php endif; ?>>Tar BZip2</option>
                                    </select><label></label></span>
                                </td>
                                <td>
                                    <i>
                                    <span id="info_global" class="archive_info" <?php if ( ! $useGlobal ) :  ?>style="display: none;"<?php endif; ?>><?php
									if ( $globalArchiveFormat == 'zip' ) :  ?>Uses PHP native Zip-library, when missing, the PCLZip library included in WordPress will be used. (Good compression, fast with native zip-library)<?php
										elseif ( $globalArchiveFormat == 'tar' ) :  ?>Uses PHP native Zip-library, when missing, the PCLZip library included in WordPress will be used. (Good compression, fast with native zip-library)<?php
										elseif ( $globalArchiveFormat == 'tar.gz' ) :  ?>Creates a GZipped tar-archive. (Good compression, fast, low memory usage)<?php
										elseif ( $globalArchiveFormat == 'tar.bz2' ) :  ?>Creates a BZipped tar-archive. (Best compression, fast, low memory usage)<?php endif; ?></span>
                                    <span id="info_zip" class="archive_info" <?php if ( $archiveFormat != 'zip' ) :  ?>style="display: none;"<?php endif; ?>>Uses PHP native Zip-library, when missing, the PCLZip library included in WordPress will be used. (Good compression, fast with native zip-library)</span>
                                    <span id="info_tar" class="archive_info" <?php if ( $archiveFormat != 'tar' ) :  ?>style="display: none;"<?php endif; ?>>Creates an uncompressed tar-archive. (No compression, fast, low memory usage)</span>
                                    <span id="info_tar.gz" class="archive_info" <?php if ( $archiveFormat != 'tar.gz' ) :  ?>style="display: none;"<?php endif; ?>>Creates a GZipped tar-archive. (Good compression, fast, low memory usage)</span>
                                    <span id="info_tar.bz2" class="archive_info" <?php if ( $archiveFormat != 'tar.bz2' ) :  ?>style="display: none;"<?php endif; ?>>Creates a BZipped tar-archive. (Best compression, fast, low memory usage)</span>
                                    </i>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <?php
				$maximumFileDescriptorsOverride = ($website->maximumFileDescriptorsOverride == 1);
				$maximumFileDescriptorsAuto = ($website->maximumFileDescriptorsAuto == 1);
				$maximumFileDescriptors = $website->maximumFileDescriptors;
				?>
                <tr class="archive_method archive_zip" <?php if ( $archiveFormat != 'zip' ) :  ?>style="display: none;"<?php endif; ?>>
                    <th scope="row"><?php _e( 'Maximum file descriptors on child','mainwp' ); ?>&nbsp;<?php MainWP_Utility::renderToolTip( 'The maximum number of open file descriptors on the child hosting.', 'http://docs.mainwp.com/maximum-number-of-file-descriptors/' ); ?></th>
                    <td>
					<input type="radio" value="" name="mainwp_options_maximumFileDescriptorsOverride" id="mainwp_options_maximumFileDescriptorsOverride_global" <?php echo ( ! $maximumFileDescriptorsOverride ? 'checked="true"' : ''); ?> />
					<label for="mainwp_options_maximumFileDescriptorsOverride_global">Global Setting (<a href="<?php echo admin_url( 'admin.php?page=Settings' ); ?>">Change Here</a>)</label>
					<br/>
					<input type="radio" value="override" name="mainwp_options_maximumFileDescriptorsOverride" id="mainwp_options_maximumFileDescriptorsOverride_override" <?php echo ($maximumFileDescriptorsOverride ? 'checked="true"' : ''); ?> />
					<label for="mainwp_options_maximumFileDescriptorsOverride_override">Override</label>
					<table>
						<tr>
							<td>Auto Detect:</td>
							<td><div class="mainwp-checkbox"><input type="checkbox" id="mainwp_maximumFileDescriptorsAuto" name="mainwp_maximumFileDescriptorsAuto" <?php echo ($maximumFileDescriptorsAuto ? 'checked="checked"' : ''); ?> /> <label for="mainwp_maximumFileDescriptorsAuto"></label></div></td>
						</tr>
					</table>
					<input type="text" name="mainwp_options_maximumFileDescriptors" id="mainwp_options_maximumFileDescriptors" value="<?php echo $maximumFileDescriptors; ?>"/>
					<br/>
					<em>(<?php _e( 'Enter a fallback value because not all hosts support this function.','mainwp' ); ?>)</em>
                    </td>
                </tr>
                <tr class="archive_method archive_zip" <?php if ( $archiveFormat != 'zip' ) :  ?>style="display: none;"<?php endif; ?>>
                    <th scope="row"><?php _e( 'Load files in memory before zipping','mainwp' ); ?>&nbsp;<?php MainWP_Utility::renderToolTip( 'This causes the files to be opened and closed immediately, using less simultaneous I/O operations on the disk. For huge sites with a lot of files we advise to disable this, memory usage will drop but we will use more file handlers when backing up.', 'http://docs.mainwp.com/load-files-memory/' ); ?></th>
                    <td>
                        <input type="radio" name="mainwp_options_loadFilesBeforeZip" id="mainwp_options_loadFilesBeforeZip_global" value="1" <?php if ( $website->loadFilesBeforeZip == false || $website->loadFilesBeforeZip == 1 ) :  ?>checked="true"<?php endif; ?>/> Global setting (<a href="<?php echo admin_url( 'admin.php?page=Settings' ); ?>">Change Here</a>)<br />
                        <input type="radio" name="mainwp_options_loadFilesBeforeZip" id="mainwp_options_loadFilesBeforeZip_yes" value="2" <?php if ( $website->loadFilesBeforeZip == 2 ) :  ?>checked="true"<?php endif; ?>/> Yes<br />
                        <input type="radio" name="mainwp_options_loadFilesBeforeZip" id="mainwp_options_loadFilesBeforeZip_no" value="0" <?php if ( $website->loadFilesBeforeZip == 0 ) :  ?>checked="true"<?php endif; ?>/> No<br />
                    </td>
                </tr>
                <?php if ( $hasRemoteDestinations !== null ) { do_action( 'mainwp_backups_remote_settings', array( 'website' => $website->id, 'hide' => 'no' ) ); } ?>
            </table>
            <?php
	}

	public static function _reconnectSite( $website ) {
		if ( MainWP_Utility::can_edit_website( $website ) ) {
			try {
				//Try to refresh stats first;
				if ( MainWP_Sync::syncSite( $website, true ) ) {
					return true;
				}

				//Add
				if ( function_exists( 'openssl_pkey_new' ) ) {
					$conf = array( 'private_key_bits' => 384 );
                    $conf_loc = MainWP_System::get_openssl_conf();
                    if ( !empty( $conf_loc ) ) {
                        $conf['config'] = $conf_loc;
					}
					$res = openssl_pkey_new( $conf );
					@openssl_pkey_export( $res, $privkey, null, $conf );
					$pubkey = openssl_pkey_get_details( $res );
					$pubkey = $pubkey['key'];
				} else {
					$privkey = '-1';
					$pubkey = '-1';
				}

				$information = MainWP_Utility::fetchUrlNotAuthed( $website->url, $website->adminname, 'register', array( 'pubkey' => $pubkey, 'server' => get_admin_url(), 'uniqueId' => $website->uniqueId ), true, $website->verify_certificate, $website->http_user, $website->http_pass, $website->ssl_version );

				if ( isset( $information['error'] ) && $information['error'] != '' ) {
					throw new Exception( $information['error'] );
				} else {
					if ( isset( $information['register'] ) && $information['register'] == 'OK' ) {
						//Update website
						MainWP_DB::Instance()->updateWebsiteValues( $website->id, array( 'pubkey' => base64_encode( $pubkey ), 'privkey' => base64_encode( $privkey ), 'nossl' => $information['nossl'], 'nosslkey' => (isset( $information['nosslkey'] ) ? $information['nosslkey'] : ''), 'uniqueId' => (isset( $information['uniqueId'] ) ? $information['uniqueId'] : '') ) );
						MainWP_Sync::syncInformationArray( $website, $information );
						return true;
					} else {
						throw new Exception( __( 'Undefined error!','mainwp' ) );
					}
				}
			} catch (MainWP_Exception $e) {
				if ( $e->getMessage() == 'HTTPERROR' ) {
					throw new Exception( 'HTTP error' . ($e->getMessageExtra() != null ? ' - ' . $e->getMessageExtra() : '') );
				} else if ( $e->getMessage() == 'NOMAINWP' ) {
					$error = __( 'No MainWP Child plugin detected, first install and activate the plugin and add your site to MainWP afterwards. If you continue experiencing this issue please ','mainwp' );
					if ( $e->getMessageExtra() != null ) {$error .= sprintf( __( 'test your connection %shere%s or ', 'mainwp' ), '<a href="' . admin_url( 'admin.php?page=managesites&do=test&site=' . urlencode( $e->getMessageExtra() ) ) . '">', '</a>' );}					
					$error .= sprintf( __( 'post as much information as possible on the error in the %ssupport forum%s.','mainwp' ), '<a href="https://mainwp.com/forum/">', '</a>' );

					throw new Exception( $error );
				}
			}
		} else {
			throw new Exception( __( 'This operation is not allowed!','mainwp' ) );
		}

		return false;
	}

	public static function addSite( $website ) {
                $params['url'] = $_POST['managesites_add_wpurl'];
                $params['name'] = $_POST['managesites_add_wpname'];                
                $params['wpadmin'] = $_POST['managesites_add_wpadmin'];
                $params['unique_id'] = isset( $_POST['managesites_add_uniqueId'] ) ? $_POST['managesites_add_uniqueId'] : '';
                $params['ssl_verify'] = ( !isset( $_POST['verify_certificate'] ) || ( empty( $_POST['verify_certificate'] ) && ( $_POST['verify_certificate'] !== '0' ) ) ? null : $_POST['verify_certificate'] );                
                $params['ssl_version'] = !isset( $_POST['ssl_version'] ) || empty( $_POST['ssl_version'] ) ? null : $_POST['ssl_version'];                
                $params['http_user'] = isset( $_POST['managesites_add_http_user'] ) ? $_POST['managesites_add_http_user'] : '';
                $params['http_pass'] = isset( $_POST['managesites_add_http_pass'] ) ? $_POST['managesites_add_http_pass'] : '';                
                $params['groupids'] = isset( $_POST['groupids'] ) ? $_POST['groupids'] : array();                
                $params['groupnames_import'] = isset( $_POST['groupnames_import'] ) ? $_POST['groupnames_import'] : '';                
                return MainWP_Manage_Sites_View::addWPSite($website, $params);                		
	}

        public static function addWPSite( $website, $params = array()  ) {
		$error = '';
		$message = '';
		$id = 0;
		if ( $website ) {
			$error = __( 'Your site is already added to MainWP Dashboard','mainwp' );
		} else {
			try {
				//Add
				if ( function_exists( 'openssl_pkey_new' ) ) {
					$conf = array( 'private_key_bits' => 384 );
                    $conf_loc = MainWP_System::get_openssl_conf();
                    if ( !empty( $conf_loc ) ) {
                        $conf['config'] = $conf_loc;
					}
					$res = openssl_pkey_new( $conf );
					@openssl_pkey_export( $res, $privkey, null, $conf );
					$pubkey = openssl_pkey_get_details( $res );
					$pubkey = $pubkey['key'];
				} else {
					$privkey = '-1';
					$pubkey = '-1';
				}

				$url = $params['url'];

				$verifyCertificate = ( !isset( $params['ssl_verify'] ) || ( empty( $params['ssl_verify'] ) && ( $params['ssl_verify'] !== '0' ) ) ? null : $params['ssl_verify'] );
				$sslVersion = MainWP_Utility::getCURLSSLVersion( !isset( $params['ssl_version'] ) || empty( $params['ssl_version'] ) ? null : $params['ssl_version'] );
				$addUniqueId = isset( $params['unique_id'] ) ? $params['unique_id'] : '';
				$http_user = isset( $params['http_user'] ) ? $params['http_user'] : '';
				$http_pass = isset( $params['http_pass'] ) ? $params['http_pass'] : '';
				$information = MainWP_Utility::fetchUrlNotAuthed($url, $params['wpadmin'], 'register',
					array(
					'pubkey' => $pubkey,
						'server' => get_admin_url(),
						'uniqueId' => $addUniqueId				
					),
					false,
					$verifyCertificate, $http_user, $http_pass, $sslVersion
				);

				if ( isset( $information['error'] ) && $information['error'] != '' ) {
					$error = $information['error'];
				} else {
					if ( isset( $information['register'] ) && $information['register'] == 'OK' ) {
						//Add website to database
						$groupids = array();
						$groupnames = array();
                        $tmpArr = array();
						if ( isset( $params['groupids'] ) && is_array( $params['groupids']) ) {
							foreach ( $params['groupids'] as $group ) {
								if (is_numeric($group)) {
                                    $groupids[] = $group;
                                } else {
                                    $tmpArr[] = $group;
                                }
							}
	                        foreach ( $tmpArr as $tmp ) {
	                            $getgroup = MainWP_DB::Instance()->getGroupByNameForUser( trim( $tmp ) );
	                            if ( $getgroup ) {
	                                if ( ! in_array( $getgroup->id, $groupids ) ) {
	                                    $groupids[] = $getgroup->id;
	                                }
	                            } else {
	                                $groupnames[] = trim( $tmp );
	                            }
	                        }
						}

						if ( (isset( $params['groupnames_import'] ) && $params['groupnames_import'] != '') ) {								
								$tmpArr = explode( ';', $params['groupnames_import'] );						
								foreach ( $tmpArr as $tmp ) {
									$group = MainWP_DB::Instance()->getGroupByNameForUser( trim( $tmp ) );
									if ( $group ) {
										if ( ! in_array( $group->id, $groupids ) ) {
											$groupids[] = $group->id;
										}
									} else {
										$groupnames[] = trim( $tmp );
									}
								}
						}

						if ( ! isset( $information['uniqueId'] ) || empty( $information['uniqueId'] ) ) {
							$addUniqueId = '';}

						$http_user = isset( $params['http_user'] ) ? $params['http_user'] : '';
						$http_pass = isset( $params['http_pass'] ) ? $params['http_pass'] : '';
						global $current_user;
						$id = MainWP_DB::Instance()->addWebsite($current_user->ID, htmlentities( $params['name'] ), $params['url'], $params['wpadmin'], base64_encode( $pubkey ), base64_encode( $privkey ), $information['nossl'], (isset( $information['nosslkey'] )
								? $information['nosslkey'] : null), $groupids, $groupnames, $verifyCertificate, $addUniqueId, $http_user, $http_pass, $sslVersion);
						$message = sprintf( __( 'Site successfully added - Visit the Site\'s %sDashboard%s now.', 'mainwp' ), '<a href="admin.php?page=managesites&dashboard=' . $id . '" style="text-decoration: none;" title="' . __( 'Dashboard', 'mainwp' ) . '">', '</a>' );
						do_action('mainwp_added_new_site', $id); // must before getWebsiteById to update team control permisions
						$website = MainWP_DB::Instance()->getWebsiteById( $id );						
						MainWP_Sync::syncInformationArray( $website, $information );
					} else {
						$error = __('Undefined error.', 'mainwp' );
					}
				}
			} catch (MainWP_Exception $e) {
				if ( $e->getMessage() == 'HTTPERROR' ) {
					$error = 'HTTP error' . ($e->getMessageExtra() != null ? ' - ' . $e->getMessageExtra() : '');
				} else if ( $e->getMessage() == 'NOMAINWP' ) {
					$error = __( 'No MainWP Child plugin detected, first install and activate the plugin and add your site to MainWP afterwards. If you continue experiencing this issue please ','mainwp' );
					if ( $e->getMessageExtra() != null ) {$error .=sprintf( __( 'test your connection %shere%s or ', 'mainwp' ), '<a href="' . admin_url( 'admin.php?page=managesites&do=test&site=' . urlencode( $e->getMessageExtra() ) ) . '">', '</a>' );}
					$error .= sprintf( __( 'post as much information as possible on the error in the %ssupport forum%s.','mainwp' ), '<a href="https://mainwp.com/forum/">', '</a>' );
				} else {
					$error = $e->getMessage();
				}
			}
		}

		return array( $message, $error, $id );
	}
        
	public static function sitesPerPage() {
		return __( 'Sites per page', 'mainwp' );
	}
}
