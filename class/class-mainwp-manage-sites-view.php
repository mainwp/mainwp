<?php
/**
 * MainWP Manage Sites View
 */
class MainWP_Manage_Sites_View {

	public static function initMenu() {
		return add_submenu_page( 'mainwp_tab', __( 'Sites', 'mainwp' ), '<span id="mainwp-Sites">' . __( 'Sites', 'mainwp' ) . '</span>', 'read', 'managesites', array( MainWP_Manage_Sites::getClassName(), 'renderManageSites' ) );
	}

	public static function initMenuSubPages( &$subPages ) {
		?>
		<div id="menu-mainwp-Sites" class="mainwp-submenu-wrapper">
			<div class="wp-submenu sub-open" style="">
				<div class="mainwp_boxout">
					<div class="mainwp_boxoutin"></div>
					<a href="<?php echo admin_url( 'admin.php?page=managesites' ); ?>" class="mainwp-submenu"><?php _e( 'Manage Sites', 'mainwp' ); ?></a>
					<?php if ( mainwp_current_user_can( 'dashboard', 'add_sites' ) ) { ?>
						<?php if ( !MainWP_Menu::is_disable_menu_item( 3, 'managesites_add_new' ) ) { ?>
							<a href="<?php echo admin_url( 'admin.php?page=managesites&do=new' ); ?>" class="mainwp-submenu"><?php _e( 'Add New', 'mainwp' ); ?></a>
						<?php } ?>
						<?php if ( !MainWP_Menu::is_disable_menu_item( 3, 'managesites_import' ) ) { ?>
							<a href="<?php echo admin_url( 'admin.php?page=managesites&do=bulknew' ); ?>" class="mainwp-submenu"><?php _e( 'Import Sites', 'mainwp' ); ?></a>
						<?php } ?>
					<?php } ?>
					<?php if ( !MainWP_Menu::is_disable_menu_item( 3, 'ManageGroups' ) ) { ?>
						<a href="<?php echo admin_url( 'admin.php?page=ManageGroups' ); ?>" class="mainwp-submenu"><?php _e( 'Groups', 'mainwp' ); ?></a>
					<?php } ?>
					<?php
					if ( isset( $subPages ) && is_array( $subPages ) ) {
						foreach ( $subPages as $subPage ) {
							if ( !isset( $subPage[ 'menu_hidden' ] ) || (isset( $subPage[ 'menu_hidden' ] ) && $subPage[ 'menu_hidden' ] != true) ) {
								if ( MainWP_Menu::is_disable_menu_item( 3, 'ManageSites' . $subPage[ 'slug' ] ) ) {
									continue;
								}
								?>
								<a href="<?php echo admin_url( 'admin.php?page=ManageSites' . $subPage[ 'slug' ] ); ?>" class="mainwp-submenu"><?php echo esc_html($subPage[ 'title' ]); ?></a>
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

	static function init_left_menu( $subPages = array() ) {

		MainWP_Menu::add_left_menu( array(
			'title'		 => __( 'Sites', 'mainwp' ),
			'parent_key' => 'mainwp_tab',
			'slug'		 => 'managesites',
			'href'		 => 'admin.php?page=managesites',
			'icon'		 => '<i class="globe icon"></i>'
		), 1 ); // level 1

		$items_menu = array(
			array(
				'title'		 => __( 'Manage Sites', 'mainwp' ),
				'parent_key' => 'managesites',
				'slug'		 => 'managesites',
				'href'		 => 'admin.php?page=managesites',
				'right'		 => ''
			),
			array(
				'title'		 => __( 'Add New', 'mainwp' ),
				'parent_key' => 'managesites',
				'href'		 => 'admin.php?page=managesites&do=new',
				'slug'		 => 'managesites',
				'right'		 => 'add_sites',
				'item_slug'	 => 'managesites_add_new'
			),
			array(
				'title'		 => __( 'Import Sites', 'mainwp' ),
				'parent_key' => 'managesites',
				'href'		 => 'admin.php?page=managesites&do=bulknew',
				'slug'		 => 'managesites',
				'right'		 => 'add_sites',
				'item_slug'	 => 'managesites_import'
			),
			array(
				'title'		 => __( 'Groups', 'mainwp' ),
				'parent_key' => 'managesites',
				'href'		 => 'admin.php?page=ManageGroups',
				'slug'		 => 'ManageGroups',
				'right'		 => ''
			)
		);

		MainWP_Menu::init_subpages_left_menu( $subPages, $items_menu, 'managesites', 'ManageSites' );

		foreach ( $items_menu as $item ) {
			if ( isset( $item[ 'item_slug' ] ) ) {
				if ( MainWP_Menu::is_disable_menu_item( 3, $item[ 'item_slug' ] ) ) {
					continue;
				}
			} else {
				if ( MainWP_Menu::is_disable_menu_item( 3, $item[ 'slug' ] ) ) {
					continue;
				}
			}
			MainWP_Menu::add_left_menu( $item, 2);
		}
	}

	static function getBreadcrumb( $pShowpage, $pSubPages ) {

	}

	public static function renderHeader( $shownPage = '', &$subPages = '' ) {

		if ( $shownPage == '' ) {
			$shownPage = 'ManageSites';
		}

		$site_id = 0;
		if ( isset( $_GET[ 'id' ] ) && !empty( $_GET[ 'id' ] ) ) {
			$site_id = $_GET[ 'id' ];
		} else if ( isset( $_GET[ 'backupid' ] ) && !empty( $_GET[ 'backupid' ] ) ) {
			$site_id = $_GET[ 'backupid' ];
		} else if ( isset( $_GET[ 'updateid' ] ) && !empty( $_GET[ 'updateid' ] ) ) {
			$site_id = $_GET[ 'updateid' ];
		} else if ( isset( $_GET[ 'dashboard' ] ) && !empty( $_GET[ 'dashboard' ] ) ) {
			$site_id = $_GET[ 'dashboard' ];
		} else if ( isset( $_GET[ 'scanid' ] ) && !empty( $_GET[ 'scanid' ] ) ) {
			$site_id = $_GET[ 'scanid' ];
		}

		$managesites_pages = array(
			'ManageSites'	 		=> array( 'href' => 'admin.php?page=managesites', 'title' => __( 'Manage Sites', 'mainwp' ), 'access' => true ),
			'AddNew'		 			=> array( 'href' => 'admin.php?page=managesites&do=new', 'title' => __( 'Add New', 'mainwp' ), 'access' => mainwp_current_user_can( 'dashboard', 'add_sites' ) ),
			'BulkAddNew'	 		=> array( 'href' => 'admin.php?page=managesites&do=bulknew', 'title' => __( 'Import Sites', 'mainwp' ), 'access' => mainwp_current_user_can( 'dashboard', 'add_sites' ) ),
			'ManageGroups'	  => array( 'href' => 'admin.php?page=ManageGroups', 'title' => __( 'Groups', 'mainwp' ), 'access' => true ),
		);

		$site_pages = array(
			'ManageSitesDashboard'	 => array( 'href' => 'admin.php?page=managesites&dashboard=' . $site_id, 'title' => __( 'Overview', 'mainwp' ), 'access' => mainwp_current_user_can( 'dashboard', 'access_individual_dashboard' ) ),
			'ManageSitesEdit'		 		 => array( 'href' => 'admin.php?page=managesites&id=' . $site_id, 'title' => __( 'Edit', 'mainwp' ), 'access' => mainwp_current_user_can( 'dashboard', 'edit_sites' ) ),
			'ManageSitesUpdates'	 	 => array( 'href' => 'admin.php?page=managesites&updateid=' . $site_id, 'title' => __( 'Updates', 'mainwp' ), 'access' => mainwp_current_user_can( 'dashboard', 'access_individual_dashboard' ) ),
			'ManageSitesBackups'	 	 => array( 'href' => 'admin.php?page=managesites&backupid=' . $site_id, 'title' => __( 'Backups', 'mainwp' ), 'access' => mainwp_current_user_can( 'dashboard', 'execute_backups' ) ),
			'SecurityScan'			 	   => array( 'href' => 'admin.php?page=managesites&scanid=' . $site_id, 'title' => __( 'Security Scan', 'mainwp' ), 'access' => true ),
		);


		global $mainwpUseExternalPrimaryBackupsMethod;
		if ( !empty( $mainwpUseExternalPrimaryBackupsMethod ) ) {
			unset( $site_pages[ 'ManageSitesBackups' ] );
		} else if ( !get_option( 'mainwp_enableLegacyBackupFeature' ) ) {
			if ( isset( $site_pages[ 'ManageSitesBackups' ] ) ) {
				unset( $site_pages[ 'ManageSitesBackups' ] );
			}
		}

		$pagetitle = __( 'Sites', 'mainwp' );

		if ( $site_id != 0 ) {
			$website = MainWP_DB::Instance()->getWebsiteById( $site_id );
			$imgfavi = '';
			if ( get_option( 'mainwp_use_favicon', 1 ) == 1 ) {
				$favi_url	 = MainWP_Utility::get_favico_url( $website );
				$imgfavi	 = '<img src="' . $favi_url . '" width="16" height="16" style="vertical-align:middle;"/>&nbsp;';
			}
			$pagetitle = $imgfavi . " " . $website->url;
		}

		$params = array(
			'title' => $pagetitle
		);

		MainWP_UI::render_top_header( $params );

		$renderItems = array();

		if ( isset( $managesites_pages[ $shownPage ] ) ) {
			foreach ( $managesites_pages as $page => $value ) {
				if ( MainWP_Menu::is_disable_menu_item( 3, $page ) )
					continue;

				$item = $value;
				$item['active'] = ( $page == $shownPage ) ? true : false;
				$renderItems[] = $item;
			}
		} else if ( $site_id ) {
			foreach ( $site_pages as $page => $value ) {
				if ( MainWP_Menu::is_disable_menu_item( 3, $page ) )
					continue;
				$item = $value;
				$item['active'] = ( $page == $shownPage ) ? true : false;
				$renderItems[] = $item;
			}
		}

		if ( isset( $subPages ) && is_array( $subPages ) ) {
			foreach ( $subPages as $subPage ) {
				 if ( MainWP_Menu::is_disable_menu_item( 3, 'ManageSites' . $subPage['slug'] ) )
					continue;

				 if ( isset( $subPage[ 'sitetab' ] ) && $subPage[ 'sitetab' ] == true && empty( $site_id ) ) {
					 continue;
				 }
				 $item = array();
				 $item['title'] = $subPage['title'];
				 $item['href'] = 'admin.php?page=ManageSites' . $subPage[ 'slug' ] . ( $site_id ? '&id=' . esc_attr( $site_id ) : '' );
				 $item['active'] = ($subPage[ 'slug' ] == $shownPage) ? true : false;
				 $renderItems[] = $item;
			 }
		}

		MainWP_UI::render_page_navigation( $renderItems );

		$which = '';
		if ( $shownPage == 'ManageSites' ) {
			$which = 'managesites';
			MainWP_UI::render_second_top_header( $which );
		}
	}

	public static function renderFooter( $shownPage, &$subPages ) {
		echo "</div>";
	}

	public static function renderTestConnection() {

	}

	public static function renderTestAdvancedOptions() {

	}

	public static function renderImportSites() {
		?>
		<div id="mainwp-importing-sites" class="ui active inverted dimmer" style="display:none">
			<div class="ui medium text loader"><?php _e( 'Importing', 'mainwp' ); ?></div>
		</div>
		<?php
		$errors = array();
		if ( $_FILES[ 'mainwp_managesites_file_bulkupload' ][ 'error' ] == UPLOAD_ERR_OK ) {
			if ( is_uploaded_file( $_FILES[ 'mainwp_managesites_file_bulkupload' ][ 'tmp_name' ] ) ) {
                $tmp_path = $_FILES[ 'mainwp_managesites_file_bulkupload' ][ 'tmp_name' ];
				$content		 = file_get_contents( $tmp_path );

				$lines = explode( "\r\n", $content ); // PHP_EOL
                $default_values 				  = array( 'name' => '', 'url' => '', 'adminname' => '', 'wpgroups' => '', 'uniqueId' => '', 'http_user' => '', 'http_pass' => '', 'verify_certificate' => 1, 'ssl_version' => 'auto' );

				if ( is_array( $lines ) && (count( $lines ) > 0) ) {
					$row					 = 0;
					$header_line		 = null;

                    foreach ( $lines as $originalLine ) {

						$line = trim( $originalLine );

						if ( MainWP_Utility::startsWith( $line, '#' ) )
							continue;

                        $items = str_getcsv( $line, "," );

						if ( ( $header_line == null ) && $_POST[ 'mainwp_managesites_chk_header_first' ] ) {
							$header_line = $line . "\r\n"; // PHP_EOL
							continue;
						}

                        // to avoid empty rows issue
                        if (count($items) < 3)
                            continue;

                        $x = 0;
                        foreach( $default_values as $field => $val) {
                            $value = isset($items[$x]) ? $items[$x] : $val;

                            if ($field == 'verify_certificate') {
                                if ( $value == 'T' ) {
                                    $value = '1';
                                } else if ( $value == 'Y' ) {
                                    $value = '0';
                                } else {
                                        // keep the value
                                }
                            }

                            $import_data[$field] = $value;
                            $x++;
                        }
                        $encoded = json_encode( $import_data );
						?>
                        <input type="hidden" id="mainwp_managesites_import_csv_line_<?php echo ( $row + 1 ) ?>" value="" encoded-data="<?php echo esc_html( $encoded ); ?>" original="<?php echo esc_attr( $originalLine ); ?>" />
                        <?php
                        $row++;
                    }
                    $header_line == trim( $header_line );

                    ?>
					<input type="hidden" id="mainwp_managesites_do_import" value="1"/>
					<input type="hidden" id="mainwp_managesites_total_import" value="<?php echo $row ?>"/>

					<div class="mainwp_managesites_import_listing" id="mainwp_managesites_import_logging">
                        <pre class="log"><?php echo esc_html($header_line); ?></pre>
					</div>
					<div class="mainwp_managesites_import_listing" id="mainwp_managesites_import_fail_logging" style="display: none;">
					<?php
                        echo esc_html( $header_line );
                    ?>
					</div>

					<?php
				} else {
					$errors[] = __( 'Invalid data. Please, review the import file.', 'mainwp' ) . '<br />';
				}
			} else {
                $errors[] = __( 'Upload failed. Please, try again.', 'mainwp' ) . '<br />';
            }
		} else {
            $errors[] = __( 'Upload failed. Please, try again.', 'mainwp' ) . '<br />';
        }

		if ( count( $errors ) > 0 ) {
			?>
			<div class="error below-h2">
				<?php foreach ( $errors as $error ) {
					?>
					<p><strong>ERROR</strong>: <?php echo esc_html($error) ?></p>
				<?php } ?>
			</div>
			<?php
		}
	}

	public static function renderNewSite() {

	}

	public static function renderSyncExtsSettings() {
		$sync_extensions_options = apply_filters( 'mainwp-sync-extensions-options', array() );
		$working_extensions		 = MainWP_Extensions::getExtensions();
		$available_exts_data	 = MainWP_Extensions_View::getAvailableExtensions();
		if ( count( $working_extensions ) > 0 && count( $sync_extensions_options ) > 0 ) {
			?>

			<h3 class="ui dividing header">
				<?php esc_html_e( ' Extensions Settings Synchronization', 'mainwp' ); ?>
				<div class="sub header"><?php esc_html_e( 'You have Extensions installed that require an additional plugin to be installed on this new Child site for the Extension to work correctly. From the list below select the plugins you want to install and if you want to apply the Extensions default settings to this Child site.', 'mainwp' ); ?></div>
			</h3>

			<?php
			foreach ( $working_extensions as $slug => $data ) {
				$dir_slug	 = dirname( $slug );

				if ( !isset( $sync_extensions_options[ $dir_slug ] ) )
					continue;

				$sync_info	 = isset( $sync_extensions_options[ $dir_slug ] ) ? $sync_extensions_options[ $dir_slug ] : array();
				$ext_name	 = str_replace( "MainWP", "", $data[ 'name' ] );
				$ext_name	 = str_replace( "Extension", "", $ext_name );
                $ext_name = trim($ext_name);
                $ext_name = esc_html($ext_name);

				$ext_data = isset( $available_exts_data[ dirname( $slug ) ] ) ? $available_exts_data[ dirname( $slug ) ] : array();

				if ( isset( $ext_data[ 'img' ] ) ) {
					$img_url = $ext_data[ 'img' ];
				} else {
					$img_url = MAINWP_PLUGIN_URL . 'assets/images/extensions/placeholder.png';
				}


				$html	 = '<div class="ui grid field">';
				$html	 .= '<div class="sync-ext-row" slug="' . $dir_slug . '" ext_name = "' . esc_attr( $ext_name ) . '"status="queue">';
				$html	 .= '<h4>' . $ext_name . '</h4>';

				if ( isset( $sync_info[ 'plugin_slug' ] ) && !empty( $sync_info[ 'plugin_slug' ] ) ) {

					$html .= '<div class="sync-install-plugin" slug="' . esc_attr( dirname( $sync_info[ 'plugin_slug' ] ) ) . '" plugin_name="' . esc_attr( $sync_info[ 'plugin_name' ] ) . '">';
					$html .= '<div class="ui checkbox"><input type="checkbox" class="chk-sync-install-plugin" /> <label>' . esc_html( sprintf( __( 'Install %s plugin', 'mainwp' ), esc_html($sync_info[ 'plugin_name' ]) ) ) . '</label></div> ';
					$html .= '<i class="ui active inline loader tiny" style="display: none"></i> <span class="status"></span>';
					$html .= '</div>';

					if ( !isset( $sync_info[ 'no_setting' ] ) || empty( $sync_info[ 'no_setting' ] ) ) {
						//$html .= '<div class="sync-options options-row"><label><input type="checkbox" /> ' . sprintf( __( 'Apply %s %ssettings%s', 'mainwp' ), esc_html($sync_info[ 'plugin_name' ]), '<a href="admin.php?page=' . $data[ 'page' ] . '">', '</a>' ) . '</label> <i class="ui active inline loader tiny" style="display: none"></i> <span class="status"></span></div>';
						$html .= '<div class="sync-options options-row">';
						$html .= '<div class="ui checkbox"><input type="checkbox" /><label> ';
						$html .= sprintf( __( 'Apply %s %ssettings%s', 'mainwp' ), esc_html($sync_info[ 'plugin_name' ]), '<a href="admin.php?page=' . $data[ 'page' ] . '">', '</a>' );
						$html .= '</label>';
						$html .= '</div> ';
						$html .= '<i class="ui active inline loader tiny" style="display: none"></i> <span class="status"></span>';
						$html .= '</div>';
					}

				} else {

					$html .= '<div class="sync-global-options options-row">';
					$html .= '<div class="ui checkbox"><input type="checkbox" /> <label>' . esc_html( sprintf( __( 'Apply global %s options', 'mainwp' ), trim( $ext_name ) ) ) . '</label></div> ';
					$html .= '<i class="ui active inline loader tiny"  style="display: none"></i> <span class="status"></span>';
					$html .= '</div>';

				}

				$html .= '</div>';
				$html .= '</div>';

				echo $html;
			}
		}
	}

	public static function renderAdvancedOptions() {

	}

	public static function renderBulkUpload() {

	}

	public static function showBackups( &$website, $fullBackups, $dbBackups ) {
		$mwpDir	 = MainWP_Utility::getMainWPDir();
		$mwpDir	 = $mwpDir[ 0 ];

        $output = '';
        foreach ( $fullBackups as $key => $fullBackup ) {
            $downloadLink	 = admin_url( '?sig=' . md5( filesize( $fullBackup ) ) . '&mwpdl=' . rawurlencode( str_replace( $mwpDir, '', $fullBackup ) ) );
            $output			 .= '<div class="ui grid field">';
			$output			 .= '<label class="six wide column middle aligned">' . MainWP_Utility::formatTimestamp( MainWP_Utility::getTimestamp( filemtime( $fullBackup ) ) ) . ' - ' . MainWP_Utility::human_filesize( filesize( $fullBackup ) ) . '</label>';
			$output			 .= '<div class="ten wide column ui toggle checkbox"><a title="' . basename( $fullBackup ) . '" href="' . $downloadLink . '" class="button">Download</a>';
			$output			 .= '<a href="admin.php?page=SiteRestore&websiteid=' . $website->id . '&f=' . base64_encode( $downloadLink ) . '&size=' . filesize( $fullBackup ) . '" class="mainwp-upgrade-button button" target="_blank" title="' . basename( $fullBackup ) . '">Restore</a>';
            $output			 .= '</div>';
            $output			 .= '</div>';
		}

        ?>
        <h3 class="ui dividing header"><?php echo __( 'Backup Details', 'mainwp' ); ?></h3>
        <h3 class="header"><?php echo ($output == '') ? esc_html( 'No full backup has been taken yet', 'mainwp' ) : esc_html( 'Last backups from your files', 'mainwp' ); ?></h3>
        <?php
        echo $output;

        $output = '';
		foreach ( $dbBackups as $key => $dbBackup ) {
			$downloadLink	 = admin_url( '?sig=' . md5( filesize( $dbBackup ) ) . '&mwpdl=' . rawurlencode( str_replace( $mwpDir, '', $dbBackup ) ) );
            $output			 .= '<div class="ui grid field">';
			$output			 .= '<label class="six wide column middle aligned">' . MainWP_Utility::formatTimestamp( MainWP_Utility::getTimestamp( filemtime( $dbBackup ) ) ) . ' - ' . MainWP_Utility::human_filesize( filesize( $dbBackup ) ) . '</label><div class="ten wide column ui toggle checkbox"><a title="' . basename( $dbBackup ) . '" href="' . $downloadLink . '" download class="button">Download</a></div>';
            $output			 .= '</div>';
		}
        ?>
        <h3 class="header"><?php echo ($output == '') ? esc_html( 'No database only backup has been taken yet', 'mainwp' ) : esc_html( 'Last backups from your database', 'mainwp' ); ?></h3>
        <?php
        echo $output;
	}

	public static function renderSettings() {

		$backupsOnServer			 			 = get_option( 'mainwp_backupsOnServer' );
		$backupOnExternalSources	   = get_option( 'mainwp_backupOnExternalSources' );
		$archiveFormat				 		 	 = get_option( 'mainwp_archiveFormat' );
		$maximumFileDescriptors		 	 = get_option( 'mainwp_maximumFileDescriptors' );
		$maximumFileDescriptorsAuto	 = get_option( 'mainwp_maximumFileDescriptorsAuto' );
		$maximumFileDescriptorsAuto	 = ( $maximumFileDescriptorsAuto == 1 || $maximumFileDescriptorsAuto === false );

		$notificationOnBackupFail	 	 = get_option( 'mainwp_notificationOnBackupFail' );
		$notificationOnBackupStart	 = get_option( 'mainwp_notificationOnBackupStart' );
		$chunkedBackupTasks			 		 = get_option( 'mainwp_chunkedBackupTasks' );
		$enableLegacyBackupFeature	 = get_option( 'mainwp_enableLegacyBackupFeature' );

		$loadFilesBeforeZip	 				 = get_option( 'mainwp_options_loadFilesBeforeZip' );
		$loadFilesBeforeZip	 				 = ( $loadFilesBeforeZip == 1 || $loadFilesBeforeZip === false );

		$primaryBackup			 				 = get_option( 'mainwp_primaryBackup' );
		$primaryBackupMethods	 			 = apply_filters( 'mainwp-getprimarybackup-methods', array() );

		if ( !is_array( $primaryBackupMethods ) ) {
			$primaryBackupMethods = array();
		}

		global $mainwpUseExternalPrimaryBackupsMethod;

		$hiddenCls = '';
		if ( !$enableLegacyBackupFeature || (!empty( $primaryBackup ) && $primaryBackup == $mainwpUseExternalPrimaryBackupsMethod ) ) {
			$hiddenCls = 'style="display:none"';
		}
		?>
         <h3 class="ui dividing header">
			<?php esc_html_e( 'Backup Settings', 'mainwp' ); ?>
			<div class="sub header"><?php echo sprintf( __( 'MainWP is actively moving away from further development of the native backups feature. The best long-term solution would be one of the %sBackup Extensions%s.', 'mainwp' ), '<a href="https://mainwp.com/extensions/extension-category/backups/" target="_blank" ?>', '</a>' ); ?></div>
		</h3>
        <div class="ui grid field">
            <label class="six wide column middle aligned"><?php esc_html_e( 'Enable legacy backup feature', 'mainwp' ); ?></label>
            <div class="ten wide column ui toggle checkbox">
                <input type="checkbox" name="mainwp_options_enableLegacyBackupFeature" id="mainwp_options_enableLegacyBackupFeature" <?php echo ($enableLegacyBackupFeature == 0 ? '' : 'checked="true"'); ?>/>
            </div>
        </div>

		<?php if ( count( $primaryBackupMethods ) > 0 ) : ?>
			 <div class="ui grid field">
                <label class="six wide column middle aligned"><?php esc_html_e( 'Select primary backup system', 'mainwp' ); ?></label>
				<div class="ten wide column">
				<select class="ui dropdown" name="mainwp_primaryBackup" id="mainwp_primaryBackup">
					<?php if ( $enableLegacyBackupFeature ) { ?>
						<option value="" ><?php echo __( 'Native backups', 'mainwp' ) ?></option>
					<?php } else { ?>
								<option value="" ><?php echo __( 'N/A', 'mainwp' ) ?></option>
					<?php } ?>
								<?php
							foreach ( $primaryBackupMethods as $method ) {
						echo '<option value="' . $method[ 'value' ] . '" ' . ( ( $primaryBackup == $method[ 'value' ] ) ? 'selected' : '') . '>' . $method[ 'title' ] . '</option>';
							}
							?>
				</select>
				</div>
            </div>
		<?php endif; ?>

        <div class="ui grid field" <?php echo $hiddenCls; ?>>
            <label class="six wide column middle aligned"><?php esc_html_e( 'Backups on server', 'mainwp' ); ?></label>
			<div class="ten wide column">
				<input type="text" name="mainwp_options_backupOnServer" value="<?php echo ( $backupsOnServer === false ? 1 : $backupsOnServer ); ?>"/>
			</div>
		</div>

		<div class="ui grid field" <?php echo $hiddenCls; ?>>
            <label class="six wide column middle aligned"><?php esc_html_e( 'Backups on remote storage', 'mainwp' ); ?></label>
			<div class="ten wide column">
				<span data-tooltip="<?php esc_attr_e( 'The number of backups to keep on your external sources. This does not affect backups on the server. 0 sets unlimited.', 'mainwp' ); ?>" data-inverted=""><input type="text" name="mainwp_options_backupOnExternalSources" value="<?php echo ( $backupOnExternalSources === false ? 1 : $backupOnExternalSources ); ?>"/>
			</div>
		</div>

		<div class="ui grid field" <?php echo $hiddenCls; ?>>
            <label class="six wide column middle aligned"><?php esc_html_e( 'Archive format', 'mainwp' ); ?></label>
			<div class="ten wide column">
				<select class="ui dropdown" name="mainwp_archiveFormat" id="mainwp_archiveFormat">
					<option value="zip" <?php if ( $archiveFormat == 'zip' ) : ?>selected<?php endif; ?>>Zip</option>
					<option value="tar" <?php if ( $archiveFormat == 'tar' ) : ?>selected<?php endif; ?>>Tar</option>
					<option value="tar.gz" <?php if ( ($archiveFormat === false) || ($archiveFormat == 'tar.gz') ) : ?>selected<?php endif; ?>>Tar GZip</option>
					<option value="tar.bz2" <?php if ( $archiveFormat == 'tar.bz2' ) : ?>selected<?php endif; ?>>Tar BZip2</option>
				</select>
			</div>
		</div>

    <div class="ui grid field" <?php echo $hiddenCls; ?> <?php if ( empty( $hiddenCls ) && $archiveFormat != 'zip' ) echo 'style="display: none;"'; ?> >
      <label class="six wide column middle aligned"><?php esc_html_e( 'Auto detect maximum file descriptors on child sites', 'mainwp' ); ?></label>
                            <div class="ten wide column ui toggle checkbox">
        <input type="checkbox" name="mainwp_maximumFileDescriptorsAuto" id="mainwp_maximumFileDescriptorsAuto" value="1" <?php echo ( $maximumFileDescriptorsAuto ? 'checked="checked"' : '' ); ?>/>
                            </div>
			</div>

		<div class="ui grid field" <?php echo $hiddenCls; ?> <?php if ( empty( $hiddenCls ) && $archiveFormat != 'zip' ) echo 'style="display: none;"'; ?> >
      <label class="six wide column middle aligned"><?php esc_html_e( 'Maximum file descriptors fallback value', 'mainwp' ); ?></label>
			<div class="ten wide column">
				<input type="text" name="mainwp_options_maximumFileDescriptors" id="mainwp_options_maximumFileDescriptors" value="<?php echo ( $maximumFileDescriptors === false ? 150 : $maximumFileDescriptors ); ?>"/>
		</div>
		</div>

    <div class="ui grid field" <?php echo $hiddenCls; ?> <?php if (empty($hiddenCls) && $archiveFormat != 'zip') echo 'style="display: none;"'; ?> >
            <label class="six wide column middle aligned"><?php esc_html_e( 'Load files in memory before zipping', 'mainwp' ); ?></label>
            <div class="ten wide column ui toggle checkbox">
        <input type="checkbox" name="mainwp_options_loadFilesBeforeZip" id="mainwp_options_loadFilesBeforeZip" value="1" <?php echo ( $loadFilesBeforeZip ? 'checked="checked"' : '' ); ?>/>
			</div>
		</div>

		<div class="ui grid field" <?php echo $hiddenCls; ?> >
      <label class="six wide column middle aligned"><?php esc_html_e( 'Send email when backup fails', 'mainwp' ); ?></label>
            <div class="ten wide column ui toggle checkbox">
        <input type="checkbox" name="mainwp_options_notificationOnBackupFail" id="mainwp_options_notificationOnBackupFail" value="1" <?php echo ( $notificationOnBackupFail ? 'checked="checked"' : '' ); ?>/>
			</div>
		</div>

		<div class="ui grid field" <?php echo $hiddenCls; ?> >
      <label class="six wide column middle aligned"><?php esc_html_e( 'Send email when backup starts', 'mainwp' ); ?></label>
			<div class="ten wide column ui toggle checkbox">
        <input type="checkbox" name="mainwp_options_notificationOnBackupStart"  id="mainwp_options_notificationOnBackupStart" value="1" <?php echo ( $notificationOnBackupStart ? 'checked="checked"' : '' ); ?>/>
			</div>
		</div>

		<div class="ui grid field" <?php echo $hiddenCls; ?> >
            <label class="six wide column middle aligned"><?php esc_html_e( 'Execute backup tasks in chunks', 'mainwp' ); ?></label>
			<div class="ten wide column ui toggle checkbox">
        <input type="checkbox" name="mainwp_options_chunkedBackupTasks"  id="mainwp_options_chunkedBackupTasks" value="1" <?php echo ( $chunkedBackupTasks ? 'checked="checked"' : '' ); ?>/>
			</div>
		</div>
		<?php
	}

	public static function renderDashboard( &$website, &$page ) {
		if ( !mainwp_current_user_can( 'dashboard', 'access_individual_dashboard' ) ) {
			mainwp_do_not_have_permissions( __( 'individual dashboard', 'mainwp' ) );
			return;
		}
		?>
		<div>
			<?php
			if ( $website->mainwpdir == -1 ) {
				echo '<div class="mainwp-notice mainwp-notice-yellow"><span class="mainwp_conflict" siteid="' . $website->id . '"><strong>Configuration issue detected</strong>: MainWP has no write privileges to the uploads directory. Because of this some of the functionality might not work, please check <a href="http://docs.mainwp.com/install-or-update-of-a-plugin-fails-on-managed-site/" target="_blank">this FAQ for further information</a></span></div>';
			}
			global $screen_layout_columns;
			MainWP_Overview::renderDashboardBody( array( $website ), $page, $screen_layout_columns );
			?>
		</div>
		<?php
	}

	public static function renderUpdates() {
		$website_id = MainWP_Utility::get_current_wpid();
		$total_vulner = 0;
		if ( $website_id ) {
			$total_vulner	 = apply_filters( 'mainwp_vulner_getvulner', 0, $website_id );
		}

		self::renderIndividualUpdates( $website_id );
	}


	public static function renderIndividualUpdates( $id ) {
		global $current_user;
		$userExtension = MainWP_DB::Instance()->getUserExtension();
		//$website = MainWP_DB::Instance()->getWebsiteById( $id );
        $sql = MainWP_DB::Instance()->getSQLWebsiteById( $id, false, array( 'premium_upgrades', 'plugins_outdate_dismissed', 'themes_outdate_dismissed', 'plugins_outdate_info', 'themes_outdate_info', 'favi_icon' ) );
        $websites = MainWP_DB::Instance()->query( $sql );

        @MainWP_DB::data_seek( $websites, 0 );
        if ($websites) {
            $website	= @MainWP_DB::fetch_object( $websites );
        }

		$trustedPlugins = json_decode( $userExtension->trusted_plugins, true );
		if ( !is_array( $trustedPlugins ) ) {
			$trustedPlugins = array();
        }

		$trustedThemes = json_decode( $userExtension->trusted_themes, true );
		if ( !is_array( $trustedThemes ) ) {
			$trustedThemes = array();
		}

		$trusted_label = '<span class="ui tiny green label">Trusted</span>';
		$not_trusted_label = '<span class="ui tiny grey label">Not Trusted</span>';

		$mainwp_show_language_updates = get_option( 'mainwp_show_language_updates', 1 );
		$user_can_update_translation = mainwp_current_user_can( 'dashboard', 'update_translations' );
		$user_can_ignore_unignore_updates = mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' );
		$user_can_update_wordpress = mainwp_current_user_can( 'dashboard', 'update_wordpress' );
		$user_can_update_themes = mainwp_current_user_can( 'dashboard', 'update_themes' );
		$user_can_update_plugins = mainwp_current_user_can( "dashboard", "update_plugins" );

        $active_tab = 'plugins';
        $active_text = esc_html__( 'Plugins Updates', 'mainwp' );
        if (isset($_GET['tab'])) {
            if ( $_GET['tab'] == "wordpress-updates" ) {
                $active_tab = 'wordpress';
                $active_text = esc_html__( 'WordPress Updates', 'mainwp' );
            } else if ( $_GET['tab'] == "themes-updates" ) {
                $active_tab = 'themes';
                $active_text = esc_html__( 'Themes Updates', 'mainwp' );
            } else if ( $_GET['tab'] == "translations-updates" ) {
                $active_tab = 'trans';
                $active_text = esc_html__( 'Translations Updates', 'mainwp' );
            } else if ( $_GET['tab'] == "abandoned-plugins" ) {
                $active_tab = 'abandoned-plugins';
                $active_text = esc_html__( 'Abandoned Plugins', 'mainwp' );
            } else if ( $_GET['tab'] == "abandoned-themes" ) {
                $active_tab = 'abandoned-themes';
                $active_text = esc_html__( 'Abandoned Themes', 'mainwp' );
            }
        }
		?>
		<div class="mainwp-sub-header">
   		<div class="ui grid">
				<div class="equal width row">
			    <div class="middle aligned column">
						<?php echo apply_filters( 'mainwp_widgetupdates_actions_top', '' ); ?>
					</div>
                    <div class="right aligned middle aligned column">
                                <div class="inline field">
                                    <div class="ui selection dropdown">
                                        <div class="text"><?php echo $active_text; ?></div>
                                        <i class="dropdown icon"></i>
                                        <div class="menu">
                                            <div class="<?php echo $active_tab == "wordpress" ? "active" : ""; ?> item" data-tab="wordpress" data-value="wordpress"><?php esc_html_e( 'WordPress Updates', 'mainwp' ); ?></div>
                                            <div class="<?php echo $active_tab == "plugins" ? "active" : ""; ?> item" data-tab="plugins" data-value="plugins"><?php esc_html_e( 'Plugins Updates', 'mainwp' ); ?></div>
                                            <div class="<?php echo $active_tab == "themes" ? "active" : ""; ?> item" data-tab="themes" data-value="themes"><?php esc_html_e( 'Themes Updates', 'mainwp' ); ?></div>
                                            <?php if ( $mainwp_show_language_updates ) : ?>
                                            <div class="<?php echo $active_tab == "trans" ? "active" : ""; ?> item" data-tab="translations" data-value="translations"><?php esc_html_e( 'Translations Updates', 'mainwp' ); ?></div>
                                            <?php endif; ?>
											<div class="<?php echo $active_tab == "abandoned-plugins" ? "active" : ""; ?> item" data-tab="abandoned-plugins" data-value="abandoned-plugins"><?php esc_html_e( 'Abandoned Plugins', 'mainwp' ); ?></div>
											<div class="<?php echo $active_tab == "abandoned-themes" ? "active" : ""; ?> item" data-tab="abandoned-themes" data-value="abandoned-themes"><?php esc_html_e( 'Abandoned Themes', 'mainwp' ); ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                      </div>
			</div>
    </div>
		<div class="ui segment" id="mainwp-manage-<?php echo $id; ?>-updates">
			<div class="ui <?php echo $active_tab == "wordpress" ? "active" : ""; ?> tab" data-tab="wordpress">
				<table class="ui stackable single line table" id="mainwp-wordpress-updates-table"> <!-- Per Site table -->
					<thead>
						<tr>
							<th><?php echo __( 'Version', 'mainwp' ); ?></th>
							<th><?php echo __( 'New Version', 'mainwp' ); ?></th>
							<th class="right aligned"></th>
						</tr>
					</thead>
					<tbody> <!-- individual -->
					<?php if ( ! $website->is_ignoreCoreUpdates ) : ?>
						<?php $wp_upgrades = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'wp_upgrades' ), true ); ?>
						<?php if ( ( count( $wp_upgrades ) !== 0 ) && ! ( $website->sync_errors !== '' ) ) : ?>
						<tr class="mainwp-wordpress-update" site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>" updated="<?php echo ( count( $wp_upgrades ) > 0 ) ? '0' : '1'; ?>">
							<td>
								<?php if ( count( $wp_upgrades ) > 0 ) : ?>
									<?php echo esc_html( $wp_upgrades[ 'current' ] ); ?>
								<?php endif ; ?>
							</td>
							<td>
								<?php if ( count( $wp_upgrades ) > 0 ) : ?>
									<?php echo esc_html( $wp_upgrades[ 'new' ] ); ?>
								<?php endif ; ?>
							</td>
							<td class="right aligned">
								<?php if ( $user_can_update_wordpress ) : ?>
									<?php if ( count( $wp_upgrades ) > 0 ) : ?>
										<a href="#" data-tooltip="<?php echo __( 'Update', 'mainwp' ) . ' ' . $website->name; ?>" data-inverted="" data-position="left center" class="ui green button mini" onClick="return updatesoverview_upgrade(<?php echo esc_attr( $website->id ); ?>, this )"><?php _e( 'Update Now', 'mainwp' ); ?></a>
                                        <input type="hidden" id="wp-updated-<?php echo esc_attr( $website->id ); ?>" value="<?php echo ( count( $wp_upgrades ) > 0 ? '0' : '1' ); ?>" />
									<?php endif ; ?>
								<?php endif ; ?>
							</td>
						</tr>
						<?php endif; ?>
					<?php endif; ?>
					</tbody>
					<thead>
						<tr>
							<th><?php echo __( 'Version', 'mainwp' ); ?></th>
							<th><?php echo __( 'New Version', 'mainwp' ); ?></th>
							<th class="right aligned"></th>
						</tr>
					</thead>
				</table>
			</div>
			<div class="ui <?php echo $active_tab == "plugins" ? "active" : ""; ?> tab" data-tab="plugins">
			<?php if ( ! $website->is_ignorePluginUpdates ) : ?>
				<?php
				$plugin_upgrades = json_decode( $website->plugin_upgrades, true );
				$decodedPremiumUpgrades = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'premium_upgrades' ), true );
				if ( is_array( $decodedPremiumUpgrades ) ) {
					foreach ( $decodedPremiumUpgrades as $crrSlug => $premiumUpgrade ) {
						$premiumUpgrade[ 'premium' ] = true;

						if ( $premiumUpgrade[ 'type' ] == 'plugin' ) {
							if ( !is_array( $plugin_upgrades ) ) {
								$plugin_upgrades = array();
							}

							$premiumUpgrade = array_filter( $premiumUpgrade );

							if ( !isset( $plugin_upgrades[ $crrSlug ] ) ) {
								$plugin_upgrades[ $crrSlug ] = array();
							}
							$plugin_upgrades[ $crrSlug ] = array_merge( $plugin_upgrades[ $crrSlug ], $premiumUpgrade );
						}
					}
				}
				$ignored_plugins = json_decode( $website->ignored_plugins, true );
				if ( is_array( $ignored_plugins ) ) {
					$plugin_upgrades = array_diff_key( $plugin_upgrades, $ignored_plugins );
				}

				$ignored_plugins = json_decode( $userExtension->ignored_plugins, true );
				if ( is_array( $ignored_plugins ) ) {
					$plugin_upgrades = array_diff_key( $plugin_upgrades, $ignored_plugins );
				}
				?>
				<table id="mainwp-updates-plugins-table" class="ui stackable single line table">
					<thead>
						<tr>
							<th><?php echo __( 'Plugin', 'mainwp' ); ?></th>
							<th class="no-sort"><?php echo __( 'Version', 'mainwp' ); ?></th>
							<th class="no-sort"><?php echo __( 'Latest', 'mainwp' ); ?></th>
							<th><?php echo __( 'Trusted', 'mainwp' ) ?></th>
							<th class="no-sort"></th>
						</tr>
					</thead>
					<tbody class="plugins-bulk-updates" site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>">
					<?php foreach ( $plugin_upgrades as $slug => $plugin_upgrade ) : ?>
						<?php $plugin_name = urlencode( $slug ); ?>
						<tr plugin_slug="<?php echo $plugin_name; ?>" premium="<?php echo ( isset( $plugin_upgrade[ 'premium' ] ) ? esc_attr($plugin_upgrade[ 'premium' ]) : 0 ) ? 1 : 0; ?>" updated="0">
							<td>
								<a href="<?php echo admin_url() . 'plugin-install.php?tab=plugin-information&plugin=' . esc_attr( $plugin_upgrade[ 'update' ][ 'slug' ] ) . '&url=' . ( isset( $plugin_upgrade[ 'PluginURI' ] ) ? rawurlencode( $plugin_upgrade[ 'PluginURI' ] ) : '' ) . '&name=' . rawurlencode( $plugin_upgrade[ 'Name' ] ) . '&TB_iframe=true&width=772&height=887'; ?>" target="_blank" class="thickbox open-plugin-details-modal">
									<?php echo esc_html( $plugin_upgrade[ 'Name' ] ); ?>
								</a>
								<input type="hidden" id="wp_upgraded_plugin_<?php echo esc_attr( $website->id ); ?>_<?php echo $plugin_name; ?>" value="0" />
							</td>
							<td><?php echo esc_html( $plugin_upgrade[ 'Version' ] ); ?></td>
							<td>
								<a href="<?php echo admin_url() . 'plugin-install.php?tab=plugin-information&plugin=' . $plugin_upgrade[ 'update' ][ 'slug' ] . '&url=' . ( isset( $plugin_upgrade[ 'PluginURI' ] ) ? rawurlencode( $plugin_upgrade[ 'PluginURI' ] ) : '' ) . '&name=' . rawurlencode( $plugin_upgrade[ 'Name' ] ) . '&section=changelog&TB_iframe=true&width=772&height=887'; ?>" target="_blank" class="thickbox open-plugin-details-modal">
									<?php echo esc_html( $plugin_upgrade[ 'update' ][ 'new_version' ] ); ?>
								</a>
							</td>
							<td><?php echo ( in_array( $slug, $trustedPlugins ) ? $trusted_label : $not_trusted_label ); ?></td>
							<td class="right aligned">
								<?php if ( $user_can_ignore_unignore_updates ) : ?>
									<a href="#" onClick="return updatesoverview_plugins_ignore_detail( '<?php echo $plugin_name; ?>', '<?php echo urlencode( $plugin_upgrade[ 'Name' ] ); ?>', <?php echo esc_attr( $website->id ); ?>, this )" class="ui mini button"><?php _e( 'Ignore Update', 'mainwp' ); ?></a>
								<?php endif; ?>
								<?php if ( $user_can_update_plugins ) : ?>
									<a href="#" class="ui green mini button" onClick="return updatesoverview_upgrade_plugin( <?php echo esc_attr( $website->id ); ?>, '<?php echo $plugin_name; ?>' )"><?php _e( 'Update Now', 'mainwp' ); ?></a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
					<tfoot>
						<tr>
							<th><?php echo __( 'Plugin', 'mainwp' ); ?></th>
							<th class="no-sort"><?php echo __( 'Version', 'mainwp' ); ?></th>
							<th class="no-sort"><?php echo __( 'Latest', 'mainwp' ); ?></th>
							<th><?php echo __( 'Trusted', 'mainwp' ) ?></th>
							<th class="no-sort"></th>
						</tr>
					</tfoot>
				</table>
			<?php endif; ?>
			</div>

			<div class="ui <?php echo $active_tab == "themes" ? "active" : ""; ?> tab" data-tab="themes">
			<?php if ( ! $website->is_ignoreThemeUpdates ) : ?>
				<?php
				$theme_upgrades = json_decode( $website->theme_upgrades, true );
				$decodedPremiumUpgrades = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'premium_upgrades' ), true );
				if ( is_array( $decodedPremiumUpgrades ) ) {
					foreach ( $decodedPremiumUpgrades as $crrSlug => $premiumUpgrade ) {
						$premiumUpgrade[ 'premium' ] = true;

						if ( $premiumUpgrade[ 'type' ] == 'theme' ) {
							if ( !is_array( $theme_upgrades ) ) {
								$theme_upgrades = array();
							}

							$premiumUpgrade = array_filter( $premiumUpgrade );

							if ( !isset( $theme_upgrades[ $crrSlug ] ) ) {
								$theme_upgrades[ $crrSlug ] = array();
							}
							$theme_upgrades[ $crrSlug ] = array_merge( $theme_upgrades[ $crrSlug ], $premiumUpgrade );
						}
					}
				}
				$ignored_themes = json_decode( $website->ignored_themes, true );
				if ( is_array( $ignored_themes ) ) {
					$theme_upgrades = array_diff_key( $theme_upgrades, $ignored_themes );
				}

				$ignored_themes = json_decode( $userExtension->ignored_themes, true );
				if ( is_array( $ignored_themes ) ) {
					$theme_upgrades = array_diff_key( $theme_upgrades, $ignored_themes );
				}
				?>
				<table id="mainwp-updates-themes-table" class="ui stackable single line table">
					<thead>
						<tr>
							<th><?php echo __( 'Theme', 'mainwp' ); ?></th>
							<th class="no-sort"><?php echo __( 'Version', 'mainwp' ); ?></th>
							<th class="no-sort"><?php echo __( 'Latest', 'mainwp' ); ?></th>
							<th><?php echo __( 'Trusted', 'mainwp' ) ?></th>
							<th class="no-sort"></th>
						</tr>
					</thead>
					<tbody class="themes-bulk-updates" site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>">
					<?php // if ( ( count( $theme_upgrades ) > 0 ) && ( $website->sync_errors !== '' ) ) : ?>
						<?php foreach ( $theme_upgrades as $slug => $theme_upgrade ) : ?>
							<?php $theme_name = urlencode( $slug ); ?>
							<tr theme_slug="<?php echo $theme_name; ?>" premium="<?php echo ( isset( $theme_upgrade[ 'premium' ] ) ? esc_attr( $theme_upgrade[ 'premium' ] ) : 0 ) ? 1 : 0; ?>" updated="0">
								<td>
									<?php echo esc_html( $theme_upgrade[ 'Name' ] ); ?>
									<input type="hidden" id="wp_upgraded_theme_<?php echo esc_attr( $website->id ); ?>_<?php echo $theme_name; ?>" value="0" />
								</td>
								<td><?php echo esc_html( $theme_upgrade[ 'Version' ] ); ?></td>
								<td><?php echo esc_html( $theme_upgrade[ 'update' ][ 'new_version' ] ); ?></a></td>
								<td><?php echo ( in_array( $slug, $trustedThemes ) ? $trusted_label : $not_trusted_label ); ?></td>
								<td class="right aligned">
									<?php if ( $user_can_ignore_unignore_updates ) : ?>
										<a href="#" onClick="return updatesoverview_themes_ignore_detail( '<?php echo $theme_name; ?>', '<?php echo urlencode( $theme_upgrade[ 'Name' ] ); ?>', <?php echo esc_attr( $website->id ); ?>, this )" class="ui mini button"><?php _e( 'Ignore Update', 'mainwp' ); ?></a>
									<?php endif; ?>
									<?php if ( $user_can_update_themes ) : ?>
										<a href="#" class="ui green mini button" onClick="return updatesoverview_upgrade_theme( <?php echo esc_attr( $website->id ); ?>, '<?php echo $theme_name; ?>' )"><?php _e( 'Update Now', 'mainwp' ); ?></a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php // endif; ?>
					</tbody>
					<tfoot>
						<tr>
							<th><?php echo __( 'Theme', 'mainwp' ); ?></th>
							<th class="no-sort"><?php echo __( 'Version', 'mainwp' ); ?></th>
							<th class="no-sort"><?php echo __( 'Latest', 'mainwp' ); ?></th>
							<th><?php echo __( 'Trusted', 'mainwp' ) ?></th>
							<th class="no-sort"></th>
						</tr>
					</tfoot>
				</table>
			<?php endif; ?>
			</div>
			<?php if ( $mainwp_show_language_updates ) : ?>
			<div class="ui <?php echo $active_tab == "trans" ? "active" : ""; ?> tab" data-tab="translations">
				<table class="ui stackable single line table" id="mainwp-translations-table">
					<thead>
						<tr>
							<th><?php echo __( 'Translation', 'mainwp' ); ?></th>
							<th><?php echo __( 'Version', 'mainwp' ); ?></th>
							<th class="collapsing no-sort"></th>
						</tr>
					</thead>
					<tbody class="translations-bulk-updates" id="wp_translation_upgrades_<?php echo esc_attr( $website->id ); ?>" site_id="<?php echo esc_attr($website->id); ?>" site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>">
					<?php $translation_upgrades = json_decode( $website->translation_upgrades, true ); ?>
					<?php foreach ( $translation_upgrades as $translation_upgrade ) : ?>
						<?php
						$translation_name	 = isset( $translation_upgrade[ 'name' ] ) ? $translation_upgrade[ 'name' ] : $translation_upgrade[ 'slug' ];
						$translation_slug	 = $translation_upgrade[ 'slug' ];
						?>
						<tr translation_slug="<?php echo $translation_slug; ?>" updated="0">
							<td>
								<?php echo esc_html( $translation_name ); ?>
								<input type="hidden" id="wp_upgraded_translation_<?php echo esc_attr( $website->id ); ?>_<?php echo $translation_slug; ?>" value="0"/>
							</td>
							<td>
								<?php echo esc_html( $translation_upgrade[ 'version' ] ); ?>
							</td>
							<td class="right aligned">
								<?php if ( $user_can_update_translation ) { ?>
									<a href="#" class="ui green mini button" onClick="return updatesoverview_upgrade_translation( <?php echo esc_attr( $website->id ); ?>, '<?php echo $translation_slug; ?>' )"><?php _e( 'Update Now', 'mainwp' ); ?></a>
								<?php } ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
					<tfoot>
						<tr>
							<th><?php echo __( 'Translation', 'mainwp' ); ?></th>
							<th><?php echo __( 'Version', 'mainwp' ); ?></th>
							<th class="collapsing no-sort"></th>
						</tr>
					</tfoot>
				</table>
			</div>
			<?php endif; ?>
			<?php
			$plugins_outdate = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'plugins_outdate_info' ), true );
			if ( !is_array( $plugins_outdate ) ) {
				$plugins_outdate = array();
			}
			$pluginsOutdateDismissed = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'plugins_outdate_dismissed' ), true );
			if ( is_array( $pluginsOutdateDismissed ) ) {
				$plugins_outdate = array_diff_key( $plugins_outdate, $pluginsOutdateDismissed );
			}

			$decodedDismissedPlugins = json_decode( $userExtension->dismissed_plugins, true );
			if ( is_array( $decodedDismissedPlugins ) ) {
				$plugins_outdate = array_diff_key( $plugins_outdate, $decodedDismissedPlugins );
			}
			$str_format = __( 'Updated %s days ago', 'mainwp' );
			?>

			<div class="ui <?php echo $active_tab == "abandoned-plugins" ? "active" : ""; ?> tab" data-tab="abandoned-plugins">
				<table class="ui stackable single line table" id="mainwp-abandoned-plugins-table">
					<thead>
						<tr>
							<tr>
								<th><?php echo __( 'Plugin', 'mainwp' ); ?></th>
								<th><?php echo __( 'Version', 'mainwp' ); ?></th>
								<th><?php echo __( 'Last Update', 'mainwp' ); ?></th>
								<th class="no-sort"></th>
							</tr>
						</tr>
					</thead>
					<tbody id="wp_plugins_outdate_<?php echo esc_attr( $website->id ); ?>" site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>">
						<?php foreach ( $plugins_outdate as $slug => $plugin_outdate ) : ?>
							<?php
							$plugin_name = urlencode( $slug );
							$now = new \DateTime();
							$last_updated = $plugin_outdate[ 'last_updated' ];
							$plugin_last_updated_date = new \DateTime( '@' . $last_updated );
							$diff_in_days = $now->diff( $plugin_last_updated_date )->format( '%a' );
							$outdate_notice = sprintf( $str_format, $diff_in_days );
							?>
							<tr dismissed="0">
								<td>
									<a href="<?php echo admin_url() . 'plugin-install.php?tab=plugin-information&plugin=' . dirname( $slug ) . '&url=' . ( isset( $plugin_outdate[ 'PluginURI' ] ) ? rawurlencode( $plugin_outdate[ 'PluginURI' ] ) : '' ) . '&name=' . rawurlencode( $plugin_outdate[ 'Name' ] ) . '&TB_iframe=true&width=772&height=887'; ?>" target="_blank" class="thickbox open-plugin-details-modal"><?php echo esc_html( $plugin_outdate[ 'Name' ] ); ?></a>
									<input type="hidden" id="wp_dismissed_plugin_<?php echo esc_attr( $website->id ); ?>_<?php echo $plugin_name; ?>" value="0"/>
								</td>
								<td><?php echo esc_html( $plugin_outdate[ 'Version' ] ); ?></td>
								<td><?php echo $outdate_notice; ?></td>
								<td class="right aligned" id="wp_dismissbuttons_plugin_<?php echo esc_attr( $website->id ); ?>_<?php echo $plugin_name; ?>">
									<?php if ( $user_can_ignore_unignore_updates ) { ?>
									<a href="javascript:void(0)" class="ui mini button" onClick="return updatesoverview_plugins_dismiss_outdate_detail( '<?php echo $plugin_name; ?>', '<?php echo urlencode( $plugin_outdate[ 'Name' ] ); ?>', <?php echo esc_attr( $website->id ); ?>, this )"><?php _e( 'Ignore Now', 'mainwp' ); ?></a>
								  <?php } ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
					<tfoot>
						<tr>
							<tr>
								<th><?php echo __( 'Plugin', 'mainwp' ); ?></th>
								<th><?php echo __( 'Version', 'mainwp' ); ?></th>
								<th><?php echo __( 'Last Update', 'mainwp' ); ?></th>
								<th class="no-sort"></th>
							</tr>
						</tr>
					</tfoot>

				</table>
			</div>

			<?php
			$themes_outdate = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'themes_outdate_info' ), true );
			if ( !is_array( $themes_outdate ) ) {
				$themes_outdate = array();
			}

			if ( count( $themes_outdate ) > 0 ) {
				$themesOutdateDismissed = json_decode( MainWP_DB::Instance()->getWebsiteOption( $website, 'themes_outdate_dismissed' ), true );
				if ( is_array( $themesOutdateDismissed ) ) {
					$themes_outdate = array_diff_key( $themes_outdate, $themesOutdateDismissed );
				}

				$decodedDismissedThemes	 = json_decode( $userExtension->dismissed_themes, true );
				if ( is_array( $decodedDismissedThemes ) ) {
					$themes_outdate = array_diff_key( $themes_outdate, $decodedDismissedThemes );
				}
			}

			?>
			<div class="ui <?php echo $active_tab == "abandoned-themes" ? "active" : ""; ?> tab" data-tab="abandoned-themes">
				<table class="ui stackable single line table" id="mainwp-abandoned-themes-table">
					<thead>
						<tr>
							<tr>
								<th><?php echo __( 'Theme', 'mainwp' ); ?></th>
								<th><?php echo __( 'Version', 'mainwp' ); ?></th>
								<th><?php echo __( 'Last Update', 'mainwp' ); ?></th>
								<th class="no-sort"></th>
							</tr>
						</tr>
					</thead>
					<tbody site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo rawurlencode( stripslashes( $website->name ) ); ?>">
						<?php foreach ( $themes_outdate as $slug => $theme_outdate ) : ?>
							<?php
							$theme_name = urlencode( $slug );
							$now = new \DateTime();
							$last_updated = $theme_outdate[ 'last_updated' ];
							$theme_last_updated_date = new \DateTime( '@' . $last_updated );
							$diff_in_days = $now->diff( $theme_last_updated_date )->format( '%a' );
							$outdate_notice = sprintf( $str_format, $diff_in_days );
							?>
							<tr dismissed="0">
								<td>
									<?php echo esc_html( $theme_outdate[ 'Name' ] ); ?>
									<input type="hidden" id="wp_dismissed_theme_<?php echo esc_attr( $website->id ); ?>_<?php echo $theme_name; ?>" value="0"/>
								</td>
								<td><?php echo esc_html( $theme_outdate[ 'Version' ] ); ?></td>
								<td><?php echo $outdate_notice; ?></td>
								<td class="right aligned" id="wp_dismissbuttons_theme_<?php echo esc_attr( $website->id ); ?>_<?php echo $theme_name; ?>">
									<?php if ( $user_can_ignore_unignore_updates ) { ?>
									<a href="javascript:void(0)" class="ui mini button" onClick="return updatesoverview_themes_dismiss_outdate_detail( '<?php echo $theme_name; ?>', '<?php echo urlencode( $theme_outdate[ 'Name' ] ); ?>', <?php echo esc_attr( $website->id ); ?>, this )"><?php _e( 'Ignore Now', 'mainwp' ); ?></a>
								  <?php } ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<script type="text/javascript">
		jQuery( document ).ready( function () {
			jQuery( '.ui.dropdown .item' ).tab();
		} );
		jQuery( 'table.ui.table' ).DataTable( {
			"searching": true,
			"paging" : false,
			"info" : true,
			"columnDefs" : [ { "orderable": false, "targets": "no-sort" } ],
			"language" : { "emptyTable": "No available updates. Please sync your MainWP Dashboard with Child Sites to see if there are any new updates available." }
		} );
		</script>
		<?php
	}


	public static function renderBackupSite( &$website ) {
		if ( !mainwp_current_user_can( 'dashboard', 'execute_backups' ) ) {
			mainwp_do_not_have_permissions( __( 'execute backups', 'mainwp' ) );
			return;
		}

		$primaryBackupMethods = apply_filters( 'mainwp-getprimarybackup-methods', array() );
		if ( !is_array( $primaryBackupMethods ) ) {
			$primaryBackupMethods = array();
		}
		?>
		<div class="error below-h2" style="display: none;" id="ajax-error-zone"></div>
		<div id="ajax-information-zone" class="updated" style="display: none;"></div>

		<?php if ( count( $primaryBackupMethods ) == 0 ) { ?>
			<div class="mainwp-notice mainwp-notice-blue"><?php echo sprintf( __( 'Did you know that MainWP has Extensions for working with popular backup plugins? Visit the %sExtensions Site%s for options.', 'mainwp' ), '<a href="https://mainwp.com/extensions/extension-category/backups/" target="_blank" ?>', '</a>' ); ?></div>
			<?php
		}
        ?>
        <div class="ui alt segment">
            <div class="mainwp-main-content">
            <div class="ui message" id="mainwp-message-zone" style="display:none"></div>
            <?php
            MainWP_Manage_Sites_View::renderBackupDetails($website->id);
            MainWP_Manage_Sites_View::renderBackupOptions($website->id);
            ?>
            </div>
        </div>

		<div class="ui modal" id="managesite-backup-status-box" tabindex="0">
				<div class="header">
					Backup <?php echo stripslashes( $website->name ); ?>
				</div>
				<div class="scrolling content mainwp-modal-content">
				</div>
				<div class="actions mainwp-modal-actions">
					<input id="managesite-backup-status-close" type="button" name="Close" value="Cancel" class="button" />
				</div>
		</div>

		<?php
	}

	public static function renderBackupDetails( $websiteid ) {
		$website	 = MainWP_DB::Instance()->getWebsiteById( $websiteid );
		if ( empty( $website ) )
			return;
		MainWP_Manage_Sites::showBackups( $website );
	}

	public static function renderBackupOptions( $websiteid ) {
		$website	 = MainWP_DB::Instance()->getWebsiteById( $websiteid );

		if ( empty( $website ) )
			return;

		//$remote_destinations	 = apply_filters( 'mainwp_backups_remote_get_destinations', null, array( 'website' => $website->id ) );
		//$hasRemoteDestinations	 = ($remote_destinations == null ? $remote_destinations : count( $remote_destinations ));
		?>
            <h3 class="ui dividing header"><?php echo __( 'Backup Options', 'mainwp' ); ?></h3>
            <form method="POST" action="" class="ui form">
                <input type="hidden" name="site_id" id="backup_site_id" value="<?php echo $website->id; ?>"/>
                <input type="hidden" name="backup_site_full_size" id="backup_site_full_size" value="<?php echo esc_attr($website->totalsize); ?>"/>
                <input type="hidden" name="backup_site_db_size" id="backup_site_db_size" value="<?php echo esc_attr($website->dbsize); ?>"/>

                <div class="ui grid field">
                    <label class="six wide column middle aligned"><?php _e( 'Backup file name', 'mainwp' ); ?></label>
                  <div class="ten wide column">
                        <input type="text" name="backup_filename" id="backup_filename" value="" class="" />
                    </div>
                </div>
                <div class="ui grid field">
                    <label class="six wide column middle aligned"><?php _e( 'Backup type', 'mainwp' ); ?></label>
                  <div class="ten wide column">
                        <select name="mainwp-backup-type" id="mainwp-backup-type" class="ui dropdown">
                            <option value="full" selected><?php _e( 'Full Backup', 'mainwp' ); ?></option>
                            <option value="db"><?php _e( 'Database Backup', 'mainwp' ); ?></option>
                        </select>
                    </div>
                </div>

                <?php do_action( 'mainwp_backups_remote_settings', array( 'website' => $website->id ) ); ?>

                <?php
                $globalArchiveFormat = get_option( 'mainwp_archiveFormat' );
                if ( $globalArchiveFormat == false ) {
                    $globalArchiveFormat = 'tar.gz';
                }
                if ( $globalArchiveFormat == 'zip' ) {
                    $globalArchiveFormatText = 'Zip';
                } else if ( $globalArchiveFormat == 'tar' ) {
                    $globalArchiveFormatText = 'Tar';
                } else if ( $globalArchiveFormat == 'tar.gz' ) {
                    $globalArchiveFormatText = 'Tar GZip';
                } else if ( $globalArchiveFormat == 'tar.bz2' ) {
                    $globalArchiveFormatText = 'Tar BZip2';
                }

                $backupSettings	 = MainWP_DB::Instance()->getWebsiteBackupSettings( $website->id );
                $archiveFormat	 = $backupSettings->archiveFormat;
                $useGlobal		 = ($archiveFormat == 'global');
                ?>

                <div class="ui grid field">
                    <label class="six wide column middle aligned"><?php _e( 'Archive type', 'mainwp' ); ?></label>
                  <div class="ten wide column">
                      <select name="mainwp_archiveFormat" id="mainwp_archiveFormat" class="ui dropdown">
                        <option value="global" <?php if ( $useGlobal ) : ?>selected<?php endif; ?>>Global setting (<?php echo $globalArchiveFormatText; ?>)</option>
                        <option value="zip" <?php if ( $archiveFormat == 'zip' ) : ?>selected<?php endif; ?>>Zip</option>
                        <option value="tar" <?php if ( $archiveFormat == 'tar' ) : ?>selected<?php endif; ?>>Tar</option>
                        <option value="tar.gz" <?php if ( $archiveFormat == 'tar.gz' ) : ?>selected<?php endif; ?>>Tar GZip</option>
                        <option value="tar.bz2" <?php if ( $archiveFormat == 'tar.bz2' ) : ?>selected<?php endif; ?>>Tar BZip2</option>
                    </select>
                    </div>
                </div>
                <div class="mainwp-backup-full-exclude">
                        <h3 class="header"><?php echo __( 'Backup Excludes', 'mainwp' ); ?></h3>
                        <div class="ui grid field">
                            <label class="six wide column middle aligned"><?php _e( 'Known backup locations', 'mainwp' ); ?></label>
                          <div class="ten wide column ui toggle checkbox">
                                <input type="checkbox" id="mainwp-known-backup-locations">
                            </div>
                        </div>
                        <div class="ui grid field">
                            <label class="six wide column middle aligned"></label>
                          <div class="ten wide column ui toggle checkbox">
                                <textarea id="mainwp-kbl-content" disabled></textarea><br />
                                <em><?php echo __( 'This adds known backup locations of popular WordPress backup plugins to the exclude list. Old backups can take up a lot of space and can cause your current MainWP backup to timeout.', 'mainwp' ); ?></em>
                            </div>
                        </div>
                        <div class="ui grid field">
                            <label class="six wide column middle aligned"><?php _e( 'Known cache locations', 'mainwp' ); ?></label>
                          <div class="ten wide column ui toggle checkbox">
                                <input type="checkbox" id="mainwp-known-cache-locations"><br />
                            </div>
                        </div>
                        <div class="ui grid field">
                            <label class="six wide column middle aligned"></label>
                          <div class="ten wide column ui toggle checkbox">
                                <textarea id="mainwp-kcl-content" disabled></textarea><br />
                                <em><?php echo __( 'This adds known cache locations of popular WordPress cache plugins to the exclude list. A cache can be massive with thousands of files and can cause your current MainWP backup to timeout. Your cache will be rebuilt by your caching plugin when the backup is restored.', 'mainwp' ); ?></em>
                            </div>
                        </div>
                        <div class="ui grid field">
                            <label class="six wide column middle aligned"><?php _e( 'Non-WordPress folders', 'mainwp' ); ?></label>
                          <div class="ten wide column ui toggle checkbox">
                                <input type="checkbox" id="mainwp-non-wordpress-folders"><br />
                            </div>
                        </div>
                        <div class="ui grid field">
                            <label class="six wide column middle aligned"></label>
                          <div class="ten wide column ui toggle checkbox">
                                <textarea id="mainwp-nwl-content" disabled></textarea><br />
                                <em><?php echo __( 'This adds folders that are not part of the WordPress core (wp-admin, wp-content and wp-include) to the exclude list. Non-WordPress folders can contain a large amount of data or may be a sub-domain or add-on domain that should be backed up individually and not with this backup.', 'mainwp' ); ?></em>
                            </div>
                        </div>
                        <div class="ui grid field">
                            <label class="six wide column middle aligned"><?php _e( 'ZIP archives', 'mainwp' ); ?></label>
                          <div class="ten wide column ui toggle checkbox">
                                <input type="checkbox" id="mainwp-zip-archives">
                            </div>
                        </div>
                        <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php _e( 'Custom excludes', 'mainwp' ); ?></label>
                          <div class="ten wide column ui toggle checkbox">
                                <textarea id="excluded_folders_list"></textarea>
                            </div>
                        </div>
                </div>
                <input type="button"
                       name="backup_btnSubmit"
                       id="backup_btnSubmit"
                       class="ui button green big floated"
                       value="Backup Now"/>
            </form>
		<?php
	}

	public static function renderScanSite( &$website ) {
		if ( !mainwp_current_user_can( 'dashboard', 'manage_security_issues' ) ) {
			mainwp_do_not_have_permissions( __( 'security scan', 'mainwp' ) );
			return;
		}

		?>

		<div class="ui segment">
			<h3 class="ui dividing header"><?php esc_html_e( 'Basic Security Check', 'mainwp' ); ?></h3>

			<?php
			// Recnder security check issues
			$websiteid	 = isset( $_GET[ 'scanid' ] ) && MainWP_Utility::ctype_digit( $_GET[ 'scanid' ] ) ? $_GET[ 'scanid' ] : null;
			$website	 = MainWP_DB::Instance()->getWebsiteById( $websiteid );
			if ( empty( $website ) )
				return;
			if ( mainwp_current_user_can( 'dashboard', 'manage_security_issues' ) ) {
				do_action( 'mainwp-securityissues-sites', $website );
			}
			?>

			<?php
			// Hook in MainWP Sucuri Extension
			if ( mainwp_current_user_can( 'extension', 'mainwp-sucuri-extension' ) ) {
				if ( MainWP_Extensions::isExtensionAvailable( 'mainwp-sucuri-extension' ) ) {
					do_action( 'mainwp-sucuriscan-sites', $website );
				}
			}
			?>

			<?php
			// Hook in MainWP Wordfence Extension
			if ( mainwp_current_user_can( 'extension', 'mainwp-wordfence-extension' ) ) {
				if ( MainWP_Extensions::isExtensionAvailable( 'mainwp-wordfence-extension' ) ) {
					do_action( 'mainwp-wordfence-sites', $website );
				}
			}
			?>
		</div>

		<?php
	}

	public static function renderEditSite( $websiteid, $updated ) {
		if ( !mainwp_current_user_can( 'dashboard', 'edit_sites' ) ) {
			mainwp_do_not_have_permissions( __( 'edit sites', 'mainwp' ) );
			return;
		}

		$website = MainWP_DB::Instance()->getWebsiteById( $websiteid );
		if ( !MainWP_Utility::can_edit_website( $website ) ) {
			$website = null;
		}

		if ( empty( $website ) )
			return;

		$groups		 = MainWP_DB::Instance()->getGroupsForCurrentUser();

		?>



		<div class="ui segment mainwp-edit-site-<?php echo $website->id; ?>" id="mainwp-edit-site">
				<?php if ( $updated) : ?>
					<div class="ui message green"><i class="close icon"></i> <?php esc_html_e( 'Child site settings saved successfully.', 'mainwp' ); ?></div>
				<?php endif ; ?>
			<form method="POST" action="" id="mainwp-edit-single-site-form" enctype="multipart/form-data" class="ui form">
						<input type="hidden" name="wp_nonce" value="<?php echo wp_create_nonce( 'UpdateWebsite' . $website->id ); ?>" />
						<h3 class="ui dividing header"><?php esc_html_e( 'General Settings', 'mainwp' ); ?></h3>

						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'Site URL', 'mainwp' ); ?></label>
				  <div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Enter your website URL.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
								<div class="ui left action input">
									<select class="ui compact selection dropdown" id="mainwp_managesites_edit_siteurl_protocol" name="mainwp_managesites_edit_siteurl_protocol">
										<option <?php echo ( MainWP_Utility::startsWith( $website->url, 'http:' ) ? 'selected' : '' ); ?> value="http">http://</option>
										<option <?php echo ( MainWP_Utility::startsWith( $website->url, 'https:' ) ? 'selected' : '' ); ?> value="https">https://</option>
									</select>
									<input type="text" id="mainwp_managesites_edit_siteurl" disabled="disabled" name="mainwp_managesites_edit_siteurl" value="<?php echo MainWP_Utility::removeHttpPrefix( $website->url, true ); ?>" />
								</div>
							</div>
						</div>

						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'Administrator username', 'mainwp' ); ?></label>
				  <div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Enter the website Administrator username.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
								<div class="ui left labeled input">
									<input type="text" id="mainwp_managesites_edit_siteadmin" name="mainwp_managesites_edit_siteadmin" value="<?php echo esc_attr( $website->adminname ); ?>" />
								</div>
							</div>
						</div>

						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'Site title', 'mainwp' ); ?></label>
				  <div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Enter the website title.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
								<div class="ui left labeled input">
									<input type="text" id="mainwp_managesites_edit_sitename" name="mainwp_managesites_edit_sitename" value="<?php echo esc_attr( stripslashes( $website->name ) ); ?>" />
								</div>
							</div>
						</div>

						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'Unique security ID', 'mainwp' ); ?></label>
				  <div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'If in use, enter the website Unique ID.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
								<div class="ui left labeled input">
									<input type="text" id="mainwp_managesites_edit_uniqueId" name="mainwp_managesites_edit_uniqueId" value="<?php echo esc_attr( $website->uniqueId ); ?>" />
								</div>
							</div>
						</div>

                        <?php

                        $groupsSite	 = MainWP_DB::Instance()->getGroupsByWebsiteId( $website->id );
                        $init_groups = '';
                        foreach($groups as $group) {
                            $init_groups .= (isset( $groupsSite[ $group->id ] ) && $groupsSite[ $group->id ] ) ? ',' . $group->id : '';
                        }
                        $init_groups = ltrim($init_groups, ',');

                        ?>
						<div class="ui grid field">
						  <label class="six wide column middle aligned"><?php esc_html_e( 'Groups', 'mainwp' ); ?></label>
							<div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Add the website to existing group(s).', 'mainwp' ); ?>" data-inverted="" data-position="top left">
						    <div class="ui multiple selection dropdown" init-value="<?php echo esc_attr($init_groups); ?>">
						      <input name="mainwp_managesites_edit_addgroups" value="" type="hidden">
						      <i class="dropdown icon"></i>
						      <div class="default text"><?php echo ($init_groups == '') ? __( "No groups added yet.", 'mainwp' ) : ''; ?></div>
						      <div class="menu">
						        <?php foreach ( $groups as $group ) { ?>
						          <div class="item" data-value="<?php echo $group->id ?>"><?php echo $group->name; ?></div>
						        <?php } ?>
						      </div>
						    </div>
						  </div>
						</div>


						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'Require backup before update', 'mainwp' ); ?></label>
							<div class="ui six wide column">
								<select class="ui dropdown" id="mainwp_backup_before_upgrade" name="mainwp_backup_before_upgrade">
									<option <?php echo ($website->backup_before_upgrade == 1) ? 'selected' : ''; ?> value="1"><?php _e( 'Yes', 'mainwp' ); ?></option>
									<option <?php echo ($website->backup_before_upgrade == 0) ? 'selected' : ''; ?> value="0"><?php _e( 'No', 'mainwp' ); ?></option>
									<option <?php echo ($website->backup_before_upgrade == 2) ? 'selected' : ''; ?> value="2"><?php _e( 'Use global setting', 'mainwp' ); ?></option>
								</select>
							</div>
						</div>

						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'Auto update core', 'mainwp' ); ?></label>
							<div class="six wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'Enable if you want MainWP to automatically update WP Core on this website.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
								<input type="checkbox" name="mainwp_automaticDailyUpdate" id="mainwp_automaticDailyUpdate" <?php echo ($website->automatic_update == 1 ? 'checked="true"' : ''); ?>><label for="mainwp_automaticDailyUpdate"></label>
							</div>
						</div>

						<?php if ( mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
							<div class="ui grid field">
								<label class="six wide column middle aligned"><?php esc_html_e( 'Ignore core updates', 'mainwp' ); ?></label>
				  <div class="six wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'Enable if you want to ignore WP Core updates on this website.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
									<input type="checkbox" name="mainwp_is_ignoreCoreUpdates" id="mainwp_is_ignoreCoreUpdates" <?php echo ($website->is_ignoreCoreUpdates == 1 ? 'checked="true"' : ''); ?>><label for="mainwp_is_ignoreCoreUpdates"></label>
								</div>
							</div>

							<div class="ui grid field">
								<label class="six wide column middle aligned"><?php esc_html_e( 'Ignore plugin updates', 'mainwp' ); ?></label>
				  <div class="six wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'Enable if you want to ignore plugin updates on this website.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
									<input type="checkbox" name="mainwp_is_ignorePluginUpdates" id="mainwp_is_ignorePluginUpdates" <?php echo ($website->is_ignorePluginUpdates == 1 ? 'checked="true"' : ''); ?>><label for="mainwp_is_ignorePluginUpdates"></label>
								</div>
							</div>

							<div class="ui grid field">
								<label class="six wide column middle aligned"><?php esc_html_e( 'Ignore theme updates', 'mainwp' ); ?></label>
				  <div class="six wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'Enable if you want to ignore theme updates on this website.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
									<input type="checkbox" name="mainwp_is_ignoreThemeUpdates" id="mainwp_is_ignoreThemeUpdates" <?php echo ($website->is_ignoreThemeUpdates == 1 ? 'checked="true"' : ''); ?>><label for="mainwp_is_ignoreThemeUpdates"></label>
								</div>
							</div>
						<?php endif ; ?>

						<h3 class="ui dividing header"><?php esc_html_e( 'Advanced Settings (Optional)', 'mainwp' ); ?></h3>
						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'Verify certificate (optional)', 'mainwp' ); ?></label>
					<div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Do you want to verify SSL certificate.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
								<select class="ui dropdown" id="mainwp_managesites_edit_verifycertificate" name="mainwp_managesites_edit_verifycertificate">
							<option <?php echo ( $website->verify_certificate == 1 ) ? 'selected' : ''; ?> value="1"><?php esc_html_e( 'Yes', 'mainwp' ); ?></option>
							<option <?php echo ( $website->verify_certificate == 0 ) ? 'selected' : ''; ?> value="0"><?php esc_html_e( 'No', 'mainwp' ); ?></option>
							<option <?php echo ( $website->verify_certificate == 2 ) ? 'selected' : ''; ?> value="2"><?php esc_html_e( 'Use global setting', 'mainwp' ); ?></option>
								</select>
							</div>
						</div>

						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'SSL version (optional)', 'mainwp' ); ?></label>
					<div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Select SSL Version. If you are not sure, select "Auto Detect".', 'mainwp' ); ?>" data-inverted="" data-position="top left">
								<select class="ui dropdown" id="mainwp_managesites_edit_ssl_version" name="mainwp_managesites_edit_ssl_version">
									<option <?php echo ($website->ssl_version == '0') ? 'selected' : ''; ?> value="0"><?php _e( 'Auto detect', 'mainwp' ); ?></option>
									<option <?php echo ($website->ssl_version == '6') ? 'selected' : ''; ?> value="6"><?php _e( "Let's encrypt (TLS v1.2)", 'mainwp' ); ?></option>
									<option <?php echo ($website->ssl_version == '1') ? 'selected' : ''; ?> value="1"><?php _e( 'TLS v1.x', 'mainwp' ); ?></option>
									<option <?php echo ($website->ssl_version == '2') ? 'selected' : ''; ?> value="2"><?php _e( 'SSL v2', 'mainwp' ); ?></option>
									<option <?php echo ($website->ssl_version == '3') ? 'selected' : ''; ?> value="3"><?php _e( 'SSL v3', 'mainwp' ); ?></option>
									<option <?php echo ($website->ssl_version == '4') ? 'selected' : ''; ?> value="4"><?php _e( 'TLS v1.0', 'mainwp' ); ?></option>
									<option <?php echo ($website->ssl_version == '5') ? 'selected' : ''; ?> value="5"><?php _e( 'TLS v1.1', 'mainwp' ); ?></option>
								</select>
							</div>
						</div>

						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'Force IPv4 (optional)', 'mainwp' ); ?></label>
					<div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'Do you want to force IPv4 for this child site?', 'mainwp' ); ?>" data-inverted="" data-position="top left">
								<select class="ui dropdown" id="mainwp_managesites_edit_forceuseipv4" name="mainwp_managesites_edit_forceuseipv4">
									<option <?php echo ($website->force_use_ipv4 == 1) ? 'selected' : ''; ?> value="1"><?php _e( 'Yes', 'mainwp' ); ?></option>
									<option <?php echo ($website->force_use_ipv4 == 0) ? 'selected' : ''; ?> value="0"><?php _e( 'No', 'mainwp' ); ?></option>
									<option <?php echo ($website->force_use_ipv4 == 2) ? 'selected' : ''; ?> value="2"><?php _e( 'Use global setting', 'mainwp' ); ?></option>
								</select>
							</div>
						</div>

						<input style="display:none" type="text" name="fakeusernameremembered"/>
						<input style="display:none" type="password" name="fakepasswordremembered"/>

						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'HTTP Username (optional)', 'mainwp' ); ?></label>
                            <div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'If the child site is HTTP Basic Auth protected, enter the HTTP username here.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
								<div class="ui left labeled input">
									<input type="text" id="mainwp_managesites_edit_http_user" name="mainwp_managesites_edit_http_user" value="<?php echo ( empty( $website->http_user ) ? '' : $website->http_user ); ?>" autocomplete="new-http-user" />
								</div>
							</div>
						</div>

						<div class="ui grid field">
							<label class="six wide column middle aligned"><?php esc_html_e( 'HTTP Password (optional)', 'mainwp' ); ?></label>
							<div class="ui six wide column" data-tooltip="<?php esc_attr_e( 'If the child site is HTTP Basic Auth protected, enter the HTTP password here.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
								<div class="ui left labeled input">
									<input type="password" id="mainwp_managesites_edit_http_pass" name="mainwp_managesites_edit_http_pass" value="<?php echo ( empty( $website->http_pass ) ? '' : $website->http_pass ); ?>" autocomplete="new-password" />
								</div>
							</div>
						</div>

						<?php do_action( 'mainwp-manage-sites-edit', $website ); ?>

						<?php do_action( 'mainwp-extension-sites-edit', $website ); // deprecated ?>

            <?php do_action( 'mainwp_extension_sites_edit_tablerow', $website ); // deprecated ?>

						<div class="ui divider"></div>
						<input type="submit" name="submit" id="submit" class="ui button green big right floated" value="<?php _e( 'Save Settings', 'mainwp' ); ?>"/>
					</form>
				</div>
			</div>
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
					$conf		 = array( 'private_key_bits' => 2048 );
					$conf_loc	 = MainWP_System::get_openssl_conf();
					if ( !empty( $conf_loc ) ) {
						$conf[ 'config' ] = $conf_loc;
					}
					$res	 = openssl_pkey_new( $conf );
					@openssl_pkey_export( $res, $privkey, null, $conf );
					$pubkey	 = openssl_pkey_get_details( $res );
					$pubkey	 = $pubkey[ 'key' ];
				} else {
					$privkey = '-1';
					$pubkey	 = '-1';
				}

				$information = MainWP_Utility::fetchUrlNotAuthed( $website->url, $website->adminname, 'register', array( 'pubkey' => $pubkey, 'server' => get_admin_url(), 'uniqueId' => $website->uniqueId ), true, $website->verify_certificate, $website->http_user, $website->http_pass, $website->ssl_version );

				if ( isset( $information[ 'error' ] ) && $information[ 'error' ] != '' ) {
					$err  = rawurlencode( urldecode( $information[ 'error' ] ) );
					$err  = str_replace( '%2F', '/', $err );
					$err  = str_replace( '%20', ' ', $err ); // replaced space encoded
					$err  = str_replace( '%26', '&', $err );
					throw new Exception( $err );
				} else {
					if ( isset( $information[ 'register' ] ) && $information[ 'register' ] == 'OK' ) {
						//Update website
						MainWP_DB::Instance()->updateWebsiteValues( $website->id, array( 'pubkey' => base64_encode( $pubkey ), 'privkey' => base64_encode( $privkey ), 'nossl' => $information[ 'nossl' ], 'nosslkey' => (isset( $information[ 'nosslkey' ] ) ? $information[ 'nosslkey' ] : ''), 'uniqueId' => (isset( $information[ 'uniqueId' ] ) ? $information[ 'uniqueId' ] : '') ) );
						MainWP_Sync::syncInformationArray( $website, $information );
						return true;
					} else {
						throw new Exception( __( 'Undefined error!', 'mainwp' ) );
					}
				}
			} catch ( MainWP_Exception $e ) {
				if ( $e->getMessage() == 'HTTPERROR' ) {
					throw new Exception( 'HTTP error' . ($e->getMessageExtra() != null ? ' - ' . $e->getMessageExtra() : '') );
				} else if ( $e->getMessage() == 'NOMAINWP' ) {
					$error = sprintf( __( 'MainWP Child plugin not detected. First, install and activate the plugin and add your site to your MainWP Dashboard afterward. If you continue experiencing this issue, check the child site for %sknown plugin conflicts%s, or check the %sMainWP Community%s for help.', 'mainwp' ), '<a href="https://meta.mainwp.com/t/known-plugin-conflicts/402">', '</a>', '<a href="https://meta.mainwp.com/c/community-support/5">', '</a>' );
					throw new Exception( $error );
				}
			}
		} else {
			throw new Exception( __( 'This operation is not allowed!', 'mainwp' ) );
		}

		return false;
	}

	public static function addSite( $website ) {

		$params[ 'url' ]				 					= $_POST[ 'managesites_add_wpurl' ];
		$params[ 'name' ]				 					= $_POST[ 'managesites_add_wpname' ];
		$params[ 'wpadmin' ]			 				= $_POST[ 'managesites_add_wpadmin' ];
		$params[ 'unique_id' ]			 			= isset( $_POST[ 'managesites_add_uniqueId' ] ) ? $_POST[ 'managesites_add_uniqueId' ] : '';
		$params[ 'ssl_verify' ]			 			= (!isset( $_POST[ 'verify_certificate' ] ) || ( empty( $_POST[ 'verify_certificate' ] ) && ( $_POST[ 'verify_certificate' ] !== '0' ) ) ? null : $_POST[ 'verify_certificate' ] );
		$params[ 'force_use_ipv4' ]		 		= (!isset( $_POST[ 'force_use_ipv4' ] ) || ( empty( $_POST[ 'force_use_ipv4' ] ) && ( $_POST[ 'force_use_ipv4' ] !== '0' ) ) ? null : $_POST[ 'force_use_ipv4' ] );
		$params[ 'ssl_version' ]		 			= !isset( $_POST[ 'ssl_version' ] ) || empty( $_POST[ 'ssl_version' ] ) ? null : $_POST[ 'ssl_version' ];
		$params[ 'http_user' ]			 			= isset( $_POST[ 'managesites_add_http_user' ] ) ? $_POST[ 'managesites_add_http_user' ] : '';
		$params[ 'http_pass' ]			 			= isset( $_POST[ 'managesites_add_http_pass' ] ) ? $_POST[ 'managesites_add_http_pass' ] : '';
		$params[ 'groupids' ]			 				= isset( $_POST[ 'groupids' ] ) && !empty($_POST[ 'groupids' ]) ? explode(",", $_POST[ 'groupids' ] ) : array();
		$params[ 'groupnames_import' ]	 	= isset( $_POST[ 'groupnames_import' ] ) ? $_POST[ 'groupnames_import' ] : '';

		if ( isset( $_POST[ 'qsw_page' ] ) ) {
			$params['qsw_page'] = $_POST[ 'qsw_page' ];
		}

		return MainWP_Manage_Sites_View::addWPSite( $website, $params );
	}

	public static function addWPSite( $website, $params = array() ) {
		$error	 = '';
		$message = '';
		$id		 = 0;
		if ( $website ) {
			$error = __( 'The site is already connected to your MainWP Dashboard', 'mainwp' );
		} else {
			try {
				//Add
				if ( function_exists( 'openssl_pkey_new' ) ) {
					$conf		 = array( 'private_key_bits' => 2048 );
					$conf_loc	 = MainWP_System::get_openssl_conf();
					if ( !empty( $conf_loc ) ) {
						$conf[ 'config' ] = $conf_loc;
					}
					$res	 = openssl_pkey_new( $conf );
					@openssl_pkey_export( $res, $privkey, null, $conf );
					$pubkey	 = openssl_pkey_get_details( $res );
					$pubkey	 = $pubkey[ 'key' ];
				} else {
					$privkey = '-1';
					$pubkey	 = '-1';
				}

				$url = $params[ 'url' ];

				$verifyCertificate	 = (!isset( $params[ 'ssl_verify' ] ) || ( empty( $params[ 'ssl_verify' ] ) && ( $params[ 'ssl_verify' ] !== '0' ) ) ? null : $params[ 'ssl_verify' ] );
                $sslVersion = !isset( $params['ssl_version'] ) || empty( $params['ssl_version'] ) ? 0 : $params['ssl_version'];
				$addUniqueId		 = isset( $params[ 'unique_id' ] ) ? $params[ 'unique_id' ] : '';
				$http_user			 = isset( $params[ 'http_user' ] ) ? $params[ 'http_user' ] : '';
				$http_pass			 = isset( $params[ 'http_pass' ] ) ? $params[ 'http_pass' ] : '';
				$force_use_ipv4		 = isset( $params[ 'force_use_ipv4' ] ) ? $params[ 'force_use_ipv4' ] : null;
				$information		 = MainWP_Utility::fetchUrlNotAuthed( $url, $params[ 'wpadmin' ], 'register', array(
					'pubkey'	 => $pubkey,
					'server'	 => get_admin_url(),
					'uniqueId'	 => $addUniqueId
				), false, $verifyCertificate, $http_user, $http_pass, $sslVersion, array( 'force_use_ipv4' => $force_use_ipv4 )
				);

				if ( isset( $information[ 'error' ] ) && $information[ 'error' ] != '' ) {
					$error  = rawurlencode( urldecode( $information[ 'error' ] ) );
					$error  = str_replace( '%2F', '/', $error );
					$error  = str_replace( '%20', ' ', $error ); // replaced space encoded
					$err  = str_replace( '%26', '&', $error );
				} else {
					if ( isset( $information[ 'register' ] ) && $information[ 'register' ] == 'OK' ) {
						//Add website to database
						$groupids	 = array();
						$groupnames	 = array();
						$tmpArr		 = array();
						if ( isset( $params[ 'groupids' ] ) && is_array( $params[ 'groupids' ] ) ) {
							foreach ( $params[ 'groupids' ] as $group ) {
								if ( is_numeric( $group ) ) {
									$groupids[] = $group;
								} else {
                                    $group = trim($group);
                                    if (!empty($group))
                                        $tmpArr[] = $group;
								}
							}
							foreach ( $tmpArr as $tmp ) {
								$getgroup = MainWP_DB::Instance()->getGroupByNameForUser( trim( $tmp ) );
								if ( $getgroup ) {
									if ( !in_array( $getgroup->id, $groupids ) ) {
										$groupids[] = $getgroup->id;
									}
								} else {
									$groupnames[] = trim( $tmp );
								}
							}
						}

						if ( (isset( $params[ 'groupnames_import' ] ) && $params[ 'groupnames_import' ] != '' ) ) {
							$tmpArr = preg_split( "/[;,]/", $params[ 'groupnames_import' ] );
							foreach ( $tmpArr as $tmp ) {
								$group = MainWP_DB::Instance()->getGroupByNameForUser( trim( $tmp ) );
								if ( $group ) {
									if ( !in_array( $group->id, $groupids ) ) {
										$groupids[] = $group->id;
									}
								} else {
									$groupnames[] = trim( $tmp );
								}
							}
						}

						if ( !isset( $information[ 'uniqueId' ] ) || empty( $information[ 'uniqueId' ] ) ) {
							$addUniqueId = '';
						}

						$http_user	 = isset( $params[ 'http_user' ] ) ? $params[ 'http_user' ] : '';
						$http_pass	 = isset( $params[ 'http_pass' ] ) ? $params[ 'http_pass' ] : '';
						global $current_user;
						$id = MainWP_DB::Instance()->addWebsite( $current_user->ID, $params[ 'name' ], $params[ 'url' ], $params[ 'wpadmin' ], base64_encode( $pubkey ), base64_encode( $privkey ), $information[ 'nossl' ], (isset( $information[ 'nosslkey' ] ) ? $information[ 'nosslkey' ] : null ), $groupids, $groupnames, $verifyCertificate, $addUniqueId, $http_user, $http_pass, $sslVersion );

						if ( isset( $params['qsw_page'] ) && $params['qsw_page'] ) {
							$message = sprintf( __( '<div class="ui header">Congratulations you have connected %s.</div> You can add new sites at anytime from the Add New Site page.', 'mainwp' ), '<strong>' . $params[ 'name' ] . '</strong>'  );
						} else {
							$message = sprintf( __( 'Site successfully added - Visit the Site\'s %sDashboard%s now.', 'mainwp' ), '<a href="admin.php?page=managesites&dashboard=' . $id . '" style="text-decoration: none;" title="' . __( 'Dashboard', 'mainwp' ) . '">', '</a>' );
						}
						do_action( 'mainwp_added_new_site', $id ); // must before getWebsiteById to update team control permisions
						$website	 = MainWP_DB::Instance()->getWebsiteById( $id );
						MainWP_Sync::syncInformationArray( $website, $information );
					} else {
						$error = __( 'Undefined error occurred. Please try again. For additional help, contact the MainWP Support.', 'mainwp' );
					}
				}
			} catch ( MainWP_Exception $e ) {
				if ( $e->getMessage() == 'HTTPERROR' ) {
					$error = 'HTTP error' . ( $e->getMessageExtra() != null ? ' - ' . $e->getMessageExtra() : '' );
				} else if ( $e->getMessage() == 'NOMAINWP' ) {
					$error = __( 'MainWP Child Plugin not detected! Please make sure that the MainWP Child plugin is installed and activated on the child site. For additional help, contact the MainWP Support.', 'mainwp' );
				} else {
					$error = $e->getMessage();
				}
			}
		}

		return array( $message, $error, $id );
	}

}
