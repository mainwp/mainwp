<?php

class MainWP_Manage_Sites {
	public static function getClassName() {
		return __CLASS__;
	}

	public static $subPages;
	public static $page;
	/** @var $sitesTable MainWP_Manage_Sites_List_Table */
	public static $sitesTable;

	public static function init() {
		/**
		 * This hook allows you to render the Sites page header via the 'mainwp-pageheader-sites' action.
		 * @link http://codex.mainwp.com/#mainwp-pageheader-sites
		 *
		 * This hook is normally used in the same context of 'mainwp-getsubpages-sites'
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-sites
		 *
		 * @see \MainWP_Manage_Sites::renderHeader
		 */
		add_action( 'mainwp-pageheader-sites', array( MainWP_Manage_Sites::getClassName(), 'renderHeader' ) );

		/**
		 * This hook allows you to render the Sites page footer via the 'mainwp-pagefooter-sites' action.
		 * @link http://codex.mainwp.com/#mainwp-pagefooter-sites
		 *
		 * This hook is normally used in the same context of 'mainwp-getsubpages-sites'
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-sites
		 *
		 * @see \MainWP_Manage_Sites::renderFooter
		 */
		add_action( 'mainwp-pagefooter-sites', array( MainWP_Manage_Sites::getClassName(), 'renderFooter' ) );

		add_filter( 'set-screen-option', array( MainWP_Manage_Sites::getClassName(), 'setScreenOption' ), 10, 3 );
		add_action( 'mainwp-securityissues-sites', array( MainWP_Security_Issues::getClassName(), 'render' ) );
		add_action( 'mainwp-extension-sites-edit', array( MainWP_Manage_Sites::getClassName(), 'on_edit_site' ) );
	}

	static function on_screen_layout_columns( $columns, $screen ) {
		if ( $screen == self::$page ) {
			$columns[ self::$page ] = 3; //Number of supported columns
		}

		return $columns;
	}

	public static function initMenu() {
		self::$page = MainWP_Manage_Sites_View::initMenu();		
		add_action( 'load-' . MainWP_Manage_Sites::$page, array(MainWP_Manage_Sites::getClassName(), 'on_load_page'));		
		
		if ( isset( $_REQUEST['dashboard'] ) ) {
			global $current_user;
			delete_user_option( $current_user->ID, 'screen_layout_toplevel_page_managesites' );
			add_filter( 'screen_layout_columns', array( self::getClassName(), 'on_screen_layout_columns' ), 10, 2 );

			$val = get_user_option( 'screen_layout_' . self::$page );
			if ( ! MainWP_Utility::ctype_digit( $val ) ) {
				global $current_user;
				update_user_option( $current_user->ID, 'screen_layout_' . self::$page, 2, true );
			}
		} 		
		add_submenu_page( 'mainwp_tab', __( 'Sites', 'mainwp' ), '<div class="mainwp-hidden">' . __( 'Sites', 'mainwp' ) . '</div>', 'read', 'SiteOpen', array(
			MainWP_Site_Open::getClassName(),
			'render',
		) );
		add_submenu_page( 'mainwp_tab', __( 'Sites', 'mainwp' ), '<div class="mainwp-hidden">' . __( 'Sites', 'mainwp' ) . '</div>', 'read', 'SiteRestore', array(
			MainWP_Site_Open::getClassName(),
			'renderRestore',
		) );

		/**
		 * This hook allows you to add extra sub pages to the Sites page via the 'mainwp-getsubpages-sites' filter.
		 * @link http://codex.mainwp.com/#mainwp-getsubpages-sites
		 */
		self::$subPages = apply_filters( 'mainwp-getsubpages-sites', array() );
		if ( isset( self::$subPages ) && is_array( self::$subPages ) ) {
			foreach ( self::$subPages as $subPage ) {
				$_page = add_submenu_page( 'mainwp_tab', $subPage['title'], '<div class="mainwp-hidden">' . $subPage['title'] . '</div>', 'read', 'ManageSites' . $subPage['slug'], $subPage['callback'] );
				if ( isset( $subPage['on_load_callback'] ) && !empty($subPage['on_load_callback'])) {
					add_action( 'load-' . $_page, $subPage['on_load_callback']);
				}
			}
		}
        MainWP_Manage_Sites_View::init_sub_sub_left_menu(self::$subPages);
	}

	public static function initMenuSubPages() {
		MainWP_Manage_Sites_View::initMenuSubPages( self::$subPages );
	}
	
	public static function on_load_page() {	
		
		if ( isset( $_REQUEST['dashboard'] ) ) {			
			self::on_load_page_dashboard();
			return;
		} 
		
		MainWP_System::enqueue_postbox_scripts();
		
		$i = 1;
		if ( isset($_REQUEST['do']) ) { 
			if ( 'new' == $_REQUEST['do'] ) {	
					add_meta_box(
						'mwp-newsite-contentbox-' . $i++,
						'<i class="fa fa-cog"></i> ' . __( 'Add a Single Site', 'mainwp' ),
						array( 'MainWP_Manage_Sites_View', 'renderNewSite' ),
						'mainwp_postboxes_managesites_addnew',
						'normal',
						'core'
					);

					$sync_extensions_options = apply_filters( 'mainwp-sync-extensions-options', array() );
					$working_extensions = MainWP_Extensions::getExtensions();		
					if ( count( $working_extensions ) > 0 && count($sync_extensions_options) > 0 ) {
						add_meta_box(
							'mwp-newsite-contentbox-' . $i++,
							'<i class="fa fa-cog"></i> ' . __( 'Extensions Settings Synchronization', 'mainwp' ),
							array( 'MainWP_Manage_Sites_View', 'renderSyncExtsSettings' ),
							'mainwp_postboxes_managesites_addnew',
							'normal',
							'core'
						);
					}
					add_meta_box(
						'mwp-newsite-contentbox-' . $i++,
						'<i class="fa fa-cog"></i> ' . __( 'Advanced Options', 'mainwp' ),
						array( 'MainWP_Manage_Sites_View', 'renderAdvancedOptions' ),
						'mainwp_postboxes_managesites_addnew',
						'normal',
						'core'
					);
					return;
				} else if ( 'bulknew' == $_REQUEST['do'] ) {	
					if ( isset($_FILES['mainwp_managesites_file_bulkupload']) && $_FILES['mainwp_managesites_file_bulkupload']['error'] == UPLOAD_ERR_OK ) {
						add_meta_box(
							'mwp-newsite-contentbox-' . $i++,
							'<i class="fa fa-globe"></i> ' . __( 'Importing New Child Sites', 'mainwp' ),
							array( 'MainWP_Manage_Sites_View', 'renderImportSites' ),
							'mainwp_postboxes_managesites_bulkupload',
							'normal',
							'core'
						);			
					} else {
						add_meta_box(
							'newsite-contentbox-' . $i++,
							'<i class="fa fa-cog"></i> ' . __( 'Import Sites', 'mainwp' ),
							array( 'MainWP_Manage_Sites_View', 'renderBulkUpload' ),
							'mainwp_postboxes_managesites_bulkaddnew',
							'normal',
							'core'
						);
					}
					return;
				} else if ( 'test' == $_REQUEST['do'] ) {
					add_meta_box(
						'mwp-test-contentbox-' . $i++,
						'<i class="fa fa-cog"></i> ' . __( 'Test a Site Connection', 'mainwp' ),
						array( 'MainWP_Manage_Sites_View', 'renderTestConnection' ),
						'mainwp_postboxes_managesites_test',
						'normal',
						'core'
					);
					add_meta_box(
						'mwp-test-contentbox-' . $i++,
						'<i class="fa fa-cog"></i> ' . __( 'Advanced Options', 'mainwp' ),
						array( 'MainWP_Manage_Sites_View', 'renderTestAdvancedOptions' ),
						'mainwp_postboxes_managesites_test',
						'normal',
						'core'
					);	
					return;
				}
		} else if ( isset( $_GET['backupid'] ) && MainWP_Utility::ctype_digit( $_GET['backupid'] ) ) {
				$websiteid = $_GET['backupid'];
				add_meta_box(
					'mwp-backup-contentbox-' . $i++,
					'<i class="fa fa-hdd-o"></i> ' . __( 'Backup Details', 'mainwp' ),
					array( 'MainWP_Manage_Sites_View', 'renderBackupDetails' ),
					'mainwp_postboxes_managesites_backup',
					'normal',
					'core',
					array( 'websiteid' => $websiteid )
				);
				add_meta_box(
					'mwp-backup-contentbox-' . $i++,
					'<i class="fa fa-hdd-o"></i> ' . __( 'Backup Options', 'mainwp' ),
					array( 'MainWP_Manage_Sites_View', 'renderBackupOptions' ),
					'mainwp_postboxes_managesites_backup',
					'normal',
					'core',
					array( 'websiteid' => $websiteid )
				);		
				return;
		} else if ( isset( $_GET['scanid'] ) && MainWP_Utility::ctype_digit( $_GET['scanid'] ) ) {
				$websiteid = $_GET['scanid'];
				$scanwebsite = MainWP_DB::Instance()->getWebsiteById( $websiteid );
				if ( empty( $scanwebsite ) ) {					
					return;
				}
				add_meta_box(
						'mwp-scan-contentbox-' . $i++,
						'<a href="admin.php?page=managesites&dashboard=' . $websiteid . '">' . $scanwebsite->name . '</a> ' . $scanwebsite->url,
						array( 'MainWP_Manage_Sites_View', 'renderScanIssues' ),
						'mainwp_postboxes_managesites_scan',
						'normal',
						'core',
						array( 'websiteid' => $websiteid )
					);
				
				if ( mainwp_current_user_can( 'extension', 'mainwp-sucuri-extension' ) ) {
					if ( ! MainWP_Extensions::isExtensionAvailable( 'mainwp-sucuri-extension' ) ) {			
						add_meta_box(
							'mwp-scan-contentbox-' . $i++,
							__( 'Sucuri Scan', 'mainwp' ),
							array( 'MainWP_Manage_Sites_View', 'renderSucuriScan' ),
							'mainwp_postboxes_managesites_scan',
							'normal',
							'core',
							array( 'websiteid' => $websiteid )
						);
					}
				}	
		
				if ( mainwp_current_user_can( 'extension', 'mainwp-wordfence-extension' ) ) {
					if ( ! MainWP_Extensions::isExtensionAvailable( 'mainwp-wordfence-extension' ) ) {			
						add_meta_box(
							'mwp-scan-contentbox-' . $i++,
							__( 'Wordfence Security Scan', 'mainwp' ),
							array( 'MainWP_Manage_Sites_View', 'renderWordfenceScan' ),
							'mainwp_postboxes_managesites_scan',
							'normal',
							'core',
							array( 'websiteid' => $websiteid )
						);
					}
				}					
				
				return;
		} else if ( isset( $_GET['id'] ) && MainWP_Utility::ctype_digit( $_GET['id'] ) ) {
				$websiteid = $_GET['id'];
				// edit site 
				add_meta_box(
					'mwp-edit-contentbox-' . $i++,
					'<i class="fa fa-cog"></i> ' . __( 'General Options', 'mainwp' ),
					array( 'MainWP_Manage_Sites_View', 'renderSiteGeneralOptions' ),
					'mainwp_postboxes_managesites_edit',
					'normal',
					'core',
					array( 'websiteid' => $websiteid )
				);	
				
				add_meta_box(
					'mwp-edit-contentbox-' . $i++,
					'<i class="fa fa-cog"></i> ' . __( 'Advanced Options', 'mainwp' ),
					array( 'MainWP_Manage_Sites_View', 'renderSiteAdvancedOptions' ),
					'mainwp_postboxes_managesites_edit',
					'normal',
					'core',
					array( 'websiteid' => $websiteid )
				);	
                                
                                $enableLegacyBackupFeature = get_option( 'mainwp_enableLegacyBackupFeature' );
                                if ($enableLegacyBackupFeature) {  
                                    add_meta_box(
                                            'mwp-edit-contentbox-' . $i++,
                                            '<i class="fa fa-cog"></i> ' . __( 'Backup Settings', 'mainwp' ),
                                            array( 'MainWP_Manage_Sites_View', 'renderSiteBackupSettings' ),
                                            'mainwp_postboxes_managesites_edit',
                                            'normal',
                                            'core',
                                            array( 'websiteid' => $websiteid )
                                    );	
                                }
                                
				do_action('mainwp_postboxes_on_load_site_page', $websiteid);
				return;
		} 
		
		self::add_options(); // manage sites screen		
	}
	
	/**
	 * @param string $shownPage The page slug shown at this moment
	 */
	public static function renderHeader( $shownPage ) {
		MainWP_Manage_Sites_View::renderHeader( $shownPage, self::$subPages );
	}

	/**
	 * @param string $shownPage The page slug shown at this moment
	 */
	public static function renderFooter( $shownPage ) {
		MainWP_Manage_Sites_View::renderFooter( $shownPage, self::$subPages );
	}

	public static function renderNewSite() {		
		$showpage = 'AddNew';		
		?>		
		<form method="POST" action="" enctype="multipart/form-data" id="mainwp_managesites_add_form">	   
			<?php self::renderHeader( $showpage ); ?>
			<div id="mainwp_managesites_add_errors" style="display: none" class="mainwp-notice mainwp-notice-red"></div>
			<div id="mainwp_managesites_add_message" style="display: none" class="mainwp-notice mainwp-notice-green"></div>
			<?php
				if ( ! mainwp_current_user_can( 'dashboard', 'add_sites' ) ) {
					mainwp_do_not_have_permissions( __( 'add sites', 'mainwp' ) );
					return;
				} else {				
					MainWP_Tours::renderAddNewSiteTours();
					MainWP_System::do_mainwp_meta_boxes('mainwp_postboxes_managesites_addnew');						
					?>
					<p class="submit"><input type="button" name="mainwp_managesites_add"
							id="mainwp_managesites_add"
							class="button-primary button button-hero" value="<?php _e('Add New Site','mainwp'); ?>"/></p>
					<?php						
				} 
				self::renderFooter( $showpage );
				?>
		</form>		
		<?php				
	}
	
	public static function renderBulkNewSite() {	
		$showpage = 'BulkAddNew';	
		?>		
		<form method="POST" action="" enctype="multipart/form-data" id="mainwp_managesites_bulkadd_form">
			<div id="mainwp_managesites_add_errors" style="display: none" class="mainwp-notice mainwp-notice-red"></div>
			<div id="mainwp_managesites_add_message" style="display: none" class="mainwp-notice mainwp-notice-green"></div>	   
			<?php self::renderHeader( $showpage );	
			MainWP_Tours::renderSitesImportTour();	
				if ( ! mainwp_current_user_can( 'dashboard', 'add_sites' ) ) {
					mainwp_do_not_have_permissions( __( 'add sites', 'mainwp' ) );
					return;
				} else {					
					if ( isset($_FILES['mainwp_managesites_file_bulkupload']) && $_FILES['mainwp_managesites_file_bulkupload']['error'] == UPLOAD_ERR_OK ) {
						MainWP_System::do_mainwp_meta_boxes('mainwp_postboxes_managesites_bulkupload');
					} else {												
						MainWP_System::do_mainwp_meta_boxes('mainwp_postboxes_managesites_bulkaddnew');						
						?>
						<p class="submit"><input type="button" name="mainwp_managesites_add"
								id="mainwp_managesites_bulkadd"
								class="button-primary button button-hero" value="<?php _e('Import Sites','mainwp'); ?>"/></p>
						<?php						
					}					
				} 
				self::renderFooter( $showpage );
				?>
		</form>		
		<?php		
	}

	public static function renderTest() {
		self::renderHeader( 'Test' );
		?>
		<div id="mainwp_managesites_test_errors" class="mainwp-notice mainwp-notice-red"></div>
		<div id="mainwp_managesites_test_message" class="mainwp-notice mainwp-notice-green"></div>
		<?php
		if ( ! mainwp_current_user_can( 'dashboard', 'test_connection' ) ) {
			mainwp_do_not_have_permissions( __( 'test connection', 'mainwp' ) );				
		} else { 			
			?>			
			<form method="POST" action="" enctype="multipart/form-data" id="mainwp_testconnection_form">
			<?php			
			MainWP_Tours::renderTestConnectionTour();	
			MainWP_System::do_mainwp_meta_boxes('mainwp_postboxes_managesites_test');
			?>
			<p class="submit"><input type="button" name="mainwp_managesites_test" id="mainwp_managesites_test" class="button-primary button button-hero" value="<?php _e( 'Test Connection','mainwp' ); ?>"/></p>
			</form> 
		<?php
		}
		self::renderFooter( 'Test' );
	}

	/**
	 * @throws MainWP_Exception
	 */
	public static function backupSite( $siteid, $pTask, $subfolder ) {
		$userid        = $pTask->userid;
		$type          = $pTask->type;
		$exclude       = $pTask->exclude;
		$taskId        = $pTask->id;
		$excludebackup = $pTask->excludebackup;
		$excludecache  = $pTask->excludecache;
		$excludenonwp  = $pTask->excludenonwp;
		$excludezip    = $pTask->excludezip;
		$pFilename     = $pTask->filename;

		if ( trim( $pFilename ) == '' ) {
			$pFilename = null;
		}

		$backup_result = array();

		//Creating a backup
		$website   = MainWP_DB::Instance()->getWebsiteById( $siteid );
		$subfolder = str_replace( '%sitename%', MainWP_Utility::sanitize( $website->name ), $subfolder );
		$subfolder = str_replace( '%url%', MainWP_Utility::sanitize( MainWP_Utility::getNiceURL( $website->url ) ), $subfolder );
		$subfolder = str_replace( '%type%', $type, $subfolder );
		$subfolder = str_replace( '%date%', MainWP_Utility::date( 'Ymd' ), $subfolder );
		$subfolder = str_replace( '%task%', '', $subfolder );
		$subfolder = str_replace( '%', '', $subfolder );
		$subfolder = MainWP_Utility::removePreSlashSpaces( $subfolder );
		$subfolder = MainWP_Utility::normalize_filename( $subfolder );

		if ( ! MainWP_System::Instance()->isSingleUser() && ( $userid != $website->userid ) ) {
			throw new MainWP_Exception( 'Undefined error.' );
		}

		$websiteCleanUrl = $website->url;
		if ( substr( $websiteCleanUrl, - 1 ) == '/' ) {
			$websiteCleanUrl = substr( $websiteCleanUrl, 0, - 1 );
		}
		$websiteCleanUrl = str_replace( array( 'http://', 'https://', '/' ), array( '', '', '-' ), $websiteCleanUrl );

		if ( $type == 'db' ) {
			$ext = '.sql.' . MainWP_Utility::getCurrentArchiveExtension( $website, $pTask );
		} else {
			$ext = '.' . MainWP_Utility::getCurrentArchiveExtension( $website, $pTask );
		}

		$file = str_replace( array(
			'%sitename%',
			'%url%',
			'%date%',
			'%time%',
			'%type%',
		), array(
			MainWP_Utility::sanitize( $website->name ),
			$websiteCleanUrl,
			MainWP_Utility::date( 'm-d-Y' ),
			MainWP_Utility::date( 'G\hi\ms\s' ),
			$type,
		), $pFilename );
		$file = str_replace( '%', '', $file );
		$file = MainWP_Utility::normalize_filename( $file );

		if ( ! empty( $file ) ) {
			$file .= $ext;
		}

		if ( $pTask->archiveFormat == 'zip' ) {
			$loadFilesBeforeZip = $pTask->loadFilesBeforeZip;
		} else if ( $pTask->archiveFormat == '' || $pTask->archiveFormat == 'site' ) {
			$loadFilesBeforeZip = $website->loadFilesBeforeZip;
		} else {
			$loadFilesBeforeZip = 1;
		}

		if ( $loadFilesBeforeZip == 1 ) {
			$loadFilesBeforeZip = get_option( 'mainwp_options_loadFilesBeforeZip' );
			$loadFilesBeforeZip = ( $loadFilesBeforeZip == 1 || $loadFilesBeforeZip === false );
		} else {
			$loadFilesBeforeZip = ( $loadFilesBeforeZip == 2 );
		}

		if ( ( $pTask->archiveFormat == 'zip' ) && ( $pTask->maximumFileDescriptorsOverride == 1 ) ) {
			$maximumFileDescriptorsAuto = ( $pTask->maximumFileDescriptorsAuto == 1 );
			$maximumFileDescriptors     = $pTask->maximumFileDescriptors;
		} else if ( ( $pTask->archiveFormat == '' || $pTask->archiveFormat == 'site' ) && ( $website->maximumFileDescriptorsOverride == 1 ) ) {
			$maximumFileDescriptorsAuto = ( $website->maximumFileDescriptorsAuto == 1 );
			$maximumFileDescriptors     = $website->maximumFileDescriptors;
		} else {
			$maximumFileDescriptorsAuto = get_option( 'mainwp_maximumFileDescriptorsAuto' );
			$maximumFileDescriptors     = get_option( 'mainwp_maximumFileDescriptors' );
			$maximumFileDescriptors     = ( $maximumFileDescriptors === false ? 150 : $maximumFileDescriptors );
		}

		$information        = false;
		$backupTaskProgress = MainWP_DB::Instance()->getBackupTaskProgress( $taskId, $website->id );
		if ( empty( $backupTaskProgress ) || ( $backupTaskProgress->dtsFetched < $pTask->last_run ) ) {
			$start = microtime( true );
			try {
				$pid = time();

				if ( empty( $backupTaskProgress ) ) {
					MainWP_DB::Instance()->addBackupTaskProgress( $taskId, $website->id, array() );
				}

				MainWP_DB::Instance()->updateBackupTaskProgress( $taskId, $website->id, array(
					'dtsFetched'             => time(),
					'fetchResult'            => json_encode( array() ),
					'downloadedDB'           => '',
					'downloadedDBComplete'   => 0,
					'downloadedFULL'         => '',
					'downloadedFULLComplete' => 0,
					'removedFiles'           => 0,
					'attempts'               => 0,
					'last_error'             => '',
					'pid'                    => $pid,
				) );

				$params = array(
					'type'                                       => $type,
					'exclude'                                    => $exclude,
					'excludebackup'                              => $excludebackup,
					'excludecache'                               => $excludecache,
					'excludenonwp'                               => $excludenonwp,
					'excludezip'                                 => $excludezip,
					'ext'                                        => MainWP_Utility::getCurrentArchiveExtension( $website, $pTask ),
					'file_descriptors_auto'                      => $maximumFileDescriptorsAuto,
					'file_descriptors'                           => $maximumFileDescriptors,
					'loadFilesBeforeZip'                         => $loadFilesBeforeZip,
					'pid'                                        => $pid,
					MainWP_Utility::getFileParameter( $website ) => $file,
				);

				MainWP_Logger::Instance()->debugForWebsite( $website, 'backup', 'Requesting backup: ' . print_r( $params, 1 ) );

				$information = MainWP_Utility::fetchUrlAuthed( $website, 'backup', $params, false, false, false );
			} catch ( MainWP_Exception $e ) {
				MainWP_Logger::Instance()->warningForWebsite( $website, 'backup', 'ERROR: ' . $e->getMessage()  . ' (' . $e->getMessageExtra() . ')' );
				$stop = microtime( true );
				//Bigger then 30 seconds means a timeout
				if ( ( $stop - $start ) > 30 ) {
					MainWP_DB::Instance()->updateBackupTaskProgress( $taskId, $website->id,
						array(
							'last_error' => json_encode( array(
								'message' => $e->getMessage(),
								'extra'   => $e->getMessageExtra(),
							) ),
						) );

					return false;
				}

				throw $e;
			}

			if ( isset( $information['error'] ) && stristr( $information['error'], 'Another backup process is running' ) ) {
				return false;
			}

			$backupTaskProgress = MainWP_DB::Instance()->updateBackupTaskProgress( $taskId, $website->id, array( 'fetchResult' => json_encode( $information ) ) );
		} //If not fetchResult, we had a timeout.. Retry this!
		else if ( empty( $backupTaskProgress->fetchResult ) ) {
			try {
				//We had some attempts, check if we have information..
				$temp = MainWP_Utility::fetchUrlAuthed( $website, 'backup_checkpid', array( 'pid' => $backupTaskProgress->pid ) );
			} catch ( Exception $e ) {

			}

			if ( ! empty( $temp ) ) {
				if ( $temp['status'] == 'stalled' ) {
					if ( $backupTaskProgress->attempts < 5 ) {
						$backupTaskProgress = MainWP_DB::Instance()->updateBackupTaskProgress( $taskId, $website->id, array( 'attempts' => $backupTaskProgress->attempts ++ ) );

						try {
							//reinitiate the request!
							$information = MainWP_Utility::fetchUrlAuthed( $website, 'backup', array(
								'type'                                       => $type,
								'exclude'                                    => $exclude,
								'excludebackup'                              => $excludebackup,
								'excludecache'                               => $excludecache,
								'excludenonwp'                               => $excludenonwp,
								'excludezip'                                 => $excludezip,
								'ext'                                        => MainWP_Utility::getCurrentArchiveExtension( $website, $pTask ),
								'file_descriptors_auto'                      => $maximumFileDescriptorsAuto,
								'file_descriptors'                           => $maximumFileDescriptors,
								'loadFilesBeforeZip'                         => $loadFilesBeforeZip,
								'pid'                                        => $backupTaskProgress->pid,
								'append'                                     => '1',
								MainWP_Utility::getFileParameter( $website ) => $temp['file'],
							), false, false, false );

							if ( isset( $information['error'] ) && stristr( $information['error'], 'Another backup process is running' ) ) {
								MainWP_DB::Instance()->updateBackupTaskProgress( $taskId, $website->id, array( 'attempts' => ( $backupTaskProgress->attempts - 1 ) ) );

								return false;
							}
						} catch ( MainWP_Exception $e ) {
							return false;
						}

						$backupTaskProgress = MainWP_DB::Instance()->updateBackupTaskProgress( $taskId, $website->id, array( 'fetchResult' => json_encode( $information ) ) );
					} else {
						throw new MainWP_Exception( 'Backup failed after 5 retries.' );
					}
				} //No retries on invalid status!
				else if ( $temp['status'] == 'invalid' ) {
					$error = json_decode( $backupTaskProgress->last_error );

					if ( ! is_array( $error ) ) {
						throw new MainWP_Exception( 'Backup failed.' );
					} else {
						throw new MainWP_Exception( $error['message'], $error['extra'] );
					}
				} else if ( $temp['status'] == 'busy' ) {
					return false;
				} else if ( $temp['status'] == 'done' ) {
					if ( $type == 'full' ) {
						$information['full'] = $temp['file'];
						$information['db']   = false;
					} else {
						$information['full'] = false;
						$information['db']   = $temp['file'];
					}

					$information['size'] = $temp['size'];

					$backupTaskProgress = MainWP_DB::Instance()->updateBackupTaskProgress( $taskId, $website->id, array( 'fetchResult' => json_encode( $information ) ) );
				}
			} else {
				if ( $backupTaskProgress->attempts < 5 ) {
					$backupTaskProgress = MainWP_DB::Instance()->updateBackupTaskProgress( $taskId, $website->id, array( 'attempts' => $backupTaskProgress->attempts ++ ) );
				} else {
					throw new MainWP_Exception( 'Backup failed after 5 retries.' );
				}
			}
		}

		if ( $information === false ) {
			$information = $backupTaskProgress->fetchResult;
		}

		if ( isset( $information['error'] ) ) {
			throw new MainWP_Exception( $information['error'] );
		} else if ( $type == 'db' && ! $information['db'] ) {
			throw new MainWP_Exception( 'Database backup failed.' );
		} else if ( $type == 'full' && ! $information['full'] ) {
			throw new MainWP_Exception( 'Full backup failed.' );
		} else if ( isset( $information['db'] ) ) {
			$dir = MainWP_Utility::getMainWPSpecificDir( $website->id );

			@mkdir( $dir, 0777, true );

			if ( ! file_exists( $dir . 'index.php' ) ) {
				@touch( $dir . 'index.php' );
			}

			//Clean old backups from our system
			$maxBackups = get_option( 'mainwp_backupsOnServer' );
			if ( $maxBackups === false ) {
				$maxBackups = 1;
			}

			if ( $backupTaskProgress->removedFiles != 1 ) {
				$dbBackups   = array();
				$fullBackups = array();
				if ( file_exists( $dir ) && ( $dh = opendir( $dir ) ) ) {
					while ( ( $file = readdir( $dh ) ) !== false ) {
						if ( $file != '.' && $file != '..' ) {
							$theFile = $dir . $file;
							if ( $information['db'] && MainWP_Utility::isSQLFile( $file ) ) {
								$dbBackups[ filemtime( $theFile ) . $file ] = $theFile;
							}

							if ( $information['full'] && MainWP_Utility::isArchive( $file ) && ! MainWP_Utility::isSQLArchive( $file ) ) {
								$fullBackups[ filemtime( $theFile ) . $file ] = $theFile;
							}
						}
					}
					closedir( $dh );
				}
				krsort( $dbBackups );
				krsort( $fullBackups );

				$cnt = 0;
				foreach ( $dbBackups as $key => $dbBackup ) {
					$cnt ++;
					if ( $cnt >= $maxBackups ) {
						@unlink( $dbBackup );
					}
				}

				$cnt = 0;
				foreach ( $fullBackups as $key => $fullBackup ) {
					$cnt ++;
					if ( $cnt >= $maxBackups ) {
						@unlink( $fullBackup );
					}
				}
				$backupTaskProgress = MainWP_DB::Instance()->updateBackupTaskProgress( $taskId, $website->id, array( 'removedFiles' => 1 ) );
			}

			$localBackupFile = null;

			$fm_date = MainWP_Utility::sanitize_file_name( MainWP_Utility::date( get_option( 'date_format' ) ) );
			$fm_time = MainWP_Utility::sanitize_file_name( MainWP_Utility::date( get_option( 'time_format' ) ) );

			$what            = null;
			$regexBackupFile = null;

			if ( $information['db'] ) {
				$what            = 'db';
				$regexBackupFile = 'db-' . $websiteCleanUrl . '-(.*)-(.*).sql(\.zip|\.tar|\.tar\.gz|\.tar\.bz2)?';
				if ( $backupTaskProgress->downloadedDB == '' ) {
					$localBackupFile = $dir . 'db-' . $websiteCleanUrl . '-' . $fm_date . '-' . $fm_time;

					if ( $pFilename != null ) {
						$filename        = str_replace( array(
							'%sitename%',
							'%url%',
							'%date%',
							'%time%',
							'%type%',
						), array(
							MainWP_Utility::sanitize( $website->name ),
							$websiteCleanUrl,
							$fm_date,
							$fm_time,
							$what,
						), $pFilename );
						$filename        = str_replace( '%', '', $filename );
						$localBackupFile = $dir . $filename;
					}
					$localBackupFile .= MainWP_Utility::getRealExtension( $information['db'] );

					$localBackupFile = MainWP_Utility::normalize_filename( $localBackupFile );

					$backupTaskProgress = MainWP_DB::Instance()->updateBackupTaskProgress( $taskId, $website->id, array( 'downloadedDB' => $localBackupFile ) );
				} else {
					$localBackupFile = $backupTaskProgress->downloadedDB;
				}

				if ( $backupTaskProgress->downloadedDBComplete == 0 ) {
					MainWP_Utility::downloadToFile( MainWP_Utility::getGetDataAuthed( $website, $information['db'], 'fdl' ), $localBackupFile, $information['size'], $website->http_user, $website->http_pass );
					$backupTaskProgress = MainWP_DB::Instance()->updateBackupTaskProgress( $taskId, $website->id, array( 'downloadedDBComplete' => 1 ) );
				}
			}

			if ( $information['full'] ) {
				$realExt         = MainWP_Utility::getRealExtension( $information['full'] );
				$what            = 'full';
				$regexBackupFile = 'full-' . $websiteCleanUrl . '-(.*)-(.*).(zip|tar|tar.gz|tar.bz2)';
				if ( $backupTaskProgress->downloadedFULL == '' ) {
					$localBackupFile = $dir . 'full-' . $websiteCleanUrl . '-' . $fm_date . '-' . $fm_time . $realExt;

					if ( $pFilename != null ) {
						$filename        = str_replace( array(
							'%sitename%',
							'%url%',
							'%date%',
							'%time%',
							'%type%',
						), array(
							MainWP_Utility::sanitize( $website->name ),
							$websiteCleanUrl,
							$fm_date,
							$fm_time,
							$what,
						), $pFilename );
						$filename        = str_replace( '%', '', $filename );
						$localBackupFile = $dir . $filename . $realExt;
					}

					$localBackupFile = MainWP_Utility::normalize_filename( $localBackupFile );

					$backupTaskProgress = MainWP_DB::Instance()->updateBackupTaskProgress( $taskId, $website->id, array( 'downloadedFULL' => $localBackupFile ) );
				} else {
					$localBackupFile = $backupTaskProgress->downloadedFULL;
				}

				if ( $backupTaskProgress->downloadedFULLComplete == 0 ) {
					if ( @file_exists( $localBackupFile ) ) {
						$time = @filemtime( $localBackupFile );

						$minutes = date( 'i', time() );
						$seconds = date( 's', time() );

						$file_minutes = date( 'i', $time );
						$file_seconds = date( 's', $time );

						$minuteDiff = $minutes - $file_minutes;
						if ( $minuteDiff == 59 ) {
							$minuteDiff = 1;
						}
						$secondsdiff = ( $minuteDiff * 60 ) + $seconds - $file_seconds;

						if ( $secondsdiff < 60 ) {
							//still downloading..
							return false;
						}
					}

					MainWP_Utility::downloadToFile( MainWP_Utility::getGetDataAuthed( $website, $information['full'], 'fdl' ), $localBackupFile, $information['size'], $website->http_user, $website->http_pass );
					MainWP_Utility::fetchUrlAuthed( $website, 'delete_backup', array( 'del' => $information['full'] ) );
					$backupTaskProgress = MainWP_DB::Instance()->updateBackupTaskProgress( $taskId, $website->id, array( 'downloadedFULLComplete' => 1 ) );
				}
			}

			$unique = $pTask->last_run;

			do_action( 'mainwp_postprocess_backup_site', $localBackupFile, $what, $subfolder, $regexBackupFile, $website, $taskId, $unique );
			$extra_result = apply_filters( 'mainwp_postprocess_backup_sites_feedback', array(), $unique );
			if ( is_array( $extra_result ) ) {
				foreach ( $extra_result as $key => $value ) {
					$backup_result[ $key ] = $value;
				}
			}
		} else {
			throw new MainWP_Exception( 'Database backup failed due to an undefined error.' );
		}

		return $backup_result;
	}

	public static function backupGetfilesize( $pFile ) {
		$dir = MainWP_Utility::getMainWPSpecificDir();

		if ( stristr( $pFile, $dir ) && file_exists( $pFile ) ) {
			return @filesize( $pFile );
		}

		return 0;
	}

	public static function backupDownloadFile( $pSiteId, $pType, $pUrl, $pFile ) {
		$dir = dirname( $pFile ) . '/';
		@mkdir( $dir, 0777, true );
		if ( ! file_exists( $dir . 'index.php' ) ) {
			@touch( $dir . 'index.php' );
		}
		//Clean old backups from our system
		$maxBackups = get_option( 'mainwp_backupsOnServer' );
		if ( $maxBackups === false ) {
			$maxBackups = 1;
		}

		$dbBackups   = array();
		$fullBackups = array();

		if ( file_exists( $dir ) && ( $dh = opendir( $dir ) ) ) {
			while ( ( $file = readdir( $dh ) ) !== false ) {
				if ( $file != '.' && $file != '..' ) {
					$theFile = $dir . $file;
					if ( $pType == 'db' && MainWP_Utility::isSQLFile( $file ) ) {
						$dbBackups[ filemtime( $theFile ) . $file ] = $theFile;
					}

					if ( $pType == 'full' && MainWP_Utility::isArchive( $file ) && ! MainWP_Utility::isSQLArchive( $file ) ) {
						$fullBackups[ filemtime( $theFile ) . $file ] = $theFile;
					}
				}
			}
			closedir( $dh );
		}
		krsort( $dbBackups );
		krsort( $fullBackups );

		$cnt = 0;
		foreach ( $dbBackups as $key => $dbBackup ) {
			$cnt ++;
			if ( $cnt >= $maxBackups ) {
				@unlink( $dbBackup );
			}
		}

		$cnt = 0;
		foreach ( $fullBackups as $key => $fullBackup ) {
			$cnt ++;
			if ( $cnt >= $maxBackups ) {
				@unlink( $fullBackup );
			}
		}

		$website = MainWP_DB::Instance()->getWebsiteById( $pSiteId );
		MainWP_Utility::endSession();

		$what = null;
		if ( $pType == 'db' ) {
			MainWP_Utility::downloadToFile( MainWP_Utility::getGetDataAuthed( $website, $pUrl, 'fdl' ), $pFile, false, $website->http_user, $website->http_pass );
		}

		if ( $pType == 'full' ) {
			MainWP_Utility::downloadToFile( MainWP_Utility::getGetDataAuthed( $website, $pUrl, 'fdl' ), $pFile, false, $website->http_user, $website->http_pass );
		}

		return true;
	}

	public static function backupDeleteFile( $pSiteId, $pFile ) {
		$website = MainWP_DB::Instance()->getWebsiteById( $pSiteId );
		MainWP_Utility::fetchUrlAuthed( $website, 'delete_backup', array( 'del' => $pFile ) );

		return true;
	}

	public static function backupCheckpid( $pSiteId, $pid, $type, $subfolder, $pFilename ) {
		$website = MainWP_DB::Instance()->getWebsiteById( $pSiteId );

		MainWP_Utility::endSession();
		$information = MainWP_Utility::fetchUrlAuthed( $website, 'backup_checkpid', array( 'pid' => $pid ) );

		//key: status/file
		$status = $information['status'];

		$result = isset( $information['file'] ) ? array( 'file' => $information['file'] ) : array();
		if ( $status == 'done' ) {
			$result['file'] = $information['file'];
			$result['size'] = $information['size'];

			$subfolder = str_replace( '%sitename%', MainWP_Utility::sanitize( $website->name ), $subfolder );
			$subfolder = str_replace( '%url%', MainWP_Utility::sanitize( MainWP_Utility::getNiceURL( $website->url ) ), $subfolder );
			$subfolder = str_replace( '%type%', $type, $subfolder );
			$subfolder = str_replace( '%date%', MainWP_Utility::date( 'Ymd' ), $subfolder );
			$subfolder = str_replace( '%task%', '', $subfolder );
			$subfolder = str_replace( '%', '', $subfolder );
			$subfolder = MainWP_Utility::removePreSlashSpaces( $subfolder );
			$subfolder = MainWP_Utility::normalize_filename( $subfolder );

			$result['subfolder'] = $subfolder;

			$websiteCleanUrl = $website->url;
			if ( substr( $websiteCleanUrl, - 1 ) == '/' ) {
				$websiteCleanUrl = substr( $websiteCleanUrl, 0, - 1 );
			}
			$websiteCleanUrl = str_replace( array( 'http://', 'https://', '/' ), array(
				'',
				'',
				'-',
			), $websiteCleanUrl );

			$dir = MainWP_Utility::getMainWPSpecificDir( $pSiteId );

			$fm_date = MainWP_Utility::sanitize_file_name( MainWP_Utility::date( get_option( 'date_format' ) ) );
			$fm_time = MainWP_Utility::sanitize_file_name( MainWP_Utility::date( get_option( 'time_format' ) ) );

			if ( $type == 'db' ) {
				$localBackupFile = $dir . 'db-' . $websiteCleanUrl . '-' . $fm_date . '-' . $fm_time . MainWP_Utility::getRealExtension( $information['file'] );
				$localRegexFile  = 'db-' . $websiteCleanUrl . '-(.*)-(.*).sql(\.zip|\.tar|\.tar\.gz|\.tar\.bz2)?';
			} else {
				$localBackupFile = $dir . 'full-' . $websiteCleanUrl . '-' . $fm_date . '-' . $fm_time . MainWP_Utility::getRealExtension( $information['file'] );
				$localRegexFile  = 'full-' . $websiteCleanUrl . '-(.*)-(.*).(zip|tar|tar.gz|tar.bz2)';
			}

			if ( $pFilename != null ) {
				$filename        = str_replace( array(
					'%sitename%',
					'%url%',
					'%date%',
					'%time%',
					'%type%',
				), array(
					MainWP_Utility::sanitize( $website->name ),
					$websiteCleanUrl,
					$fm_date,
					$fm_time,
					$type,
				), $pFilename );
				$filename        = str_replace( '%', '', $filename );
				$localBackupFile = $dir . $filename;
				$localBackupFile = MainWP_Utility::normalize_filename( $localBackupFile );

				if ( $type == 'db' ) {
					$localBackupFile .= MainWP_Utility::getRealExtension( $information['file'] );
				} else {
					$localBackupFile .= MainWP_Utility::getRealExtension( $information['file'] );
				}
			}

			$result['local']     = $localBackupFile;
			$result['regexfile'] = $localRegexFile;
		}

		return array( 'status' => $status, 'result' => $result );
	}

	public static function backup( $pSiteId, $pType, $pSubfolder, $pExclude, $excludebackup, $excludecache, $excludenonwp, $excludezip, $pFilename = null, $pFileNameUID = '', $pArchiveFormat = false, $pMaximumFileDescriptorsOverride = false, $pMaximumFileDescriptorsAuto = false, $pMaximumFileDescriptors = false, $pLoadFilesBeforeZip = false, $pid = false, $append = false ) {
		if ( trim( $pFilename ) == '' ) {
			$pFilename = null;
		}

		$backup_result = array();

		//Creating a backup
		$website   = MainWP_DB::Instance()->getWebsiteById( $pSiteId );
		$subfolder = str_replace( '%sitename%', MainWP_Utility::sanitize( $website->name ), $pSubfolder );
		$subfolder = str_replace( '%url%', MainWP_Utility::sanitize( MainWP_Utility::getNiceURL( $website->url ) ), $subfolder );
		$subfolder = str_replace( '%type%', $pType, $subfolder );
		$subfolder = str_replace( '%date%', MainWP_Utility::date( 'Ymd' ), $subfolder );
		$subfolder = str_replace( '%task%', '', $subfolder );
		$subfolder = str_replace( '%', '', $subfolder );
		$subfolder = MainWP_Utility::removePreSlashSpaces( $subfolder );
		$subfolder = MainWP_Utility::normalize_filename( $subfolder );

		if ( ! MainWP_Utility::can_edit_website( $website ) ) {
			throw new MainWP_Exception( 'You are not allowed to backup this site' );
		}

		$websiteCleanUrl = $website->url;
		if ( substr( $websiteCleanUrl, - 1 ) == '/' ) {
			$websiteCleanUrl = substr( $websiteCleanUrl, 0, - 1 );
		}
		$websiteCleanUrl = str_replace( array( 'http://', 'https://', '/' ), array( '', '', '-' ), $websiteCleanUrl );

		//Normal flow: use website & fallback to global
		if ( $pMaximumFileDescriptorsOverride == false ) {
			if ( $website->maximumFileDescriptorsOverride == 1 ) {
				$maximumFileDescriptorsAuto = ( $website->maximumFileDescriptorsAuto == 1 );
				$maximumFileDescriptors     = $website->maximumFileDescriptors;
			} else {
				$maximumFileDescriptorsAuto = get_option( 'mainwp_maximumFileDescriptorsAuto' );
				$maximumFileDescriptors     = get_option( 'mainwp_maximumFileDescriptors' );
				$maximumFileDescriptors     = ( $maximumFileDescriptors === false ? 150 : $maximumFileDescriptors );
			}
		} //If not set to global & overriden, use these settings
		else if ( ( $pArchiveFormat != 'global' ) && ( $pMaximumFileDescriptorsOverride == 1 ) ) {
			$maximumFileDescriptorsAuto = ( $pMaximumFileDescriptorsAuto == 1 );
			$maximumFileDescriptors     = $pMaximumFileDescriptors;
		} //Set to global or not overriden, use global settings
		else {
			$maximumFileDescriptorsAuto = get_option( 'mainwp_maximumFileDescriptorsAuto' );
			$maximumFileDescriptors     = get_option( 'mainwp_maximumFileDescriptors' );
			$maximumFileDescriptors     = ( $maximumFileDescriptors === false ? 150 : $maximumFileDescriptors );
		}

		$file = str_replace( array(
			'%sitename%',
			'%url%',
			'%date%',
			'%time%',
			'%type%',
		), array(
			MainWP_Utility::sanitize( $website->name ),
			$websiteCleanUrl,
			MainWP_Utility::date( 'm-d-Y' ),
			MainWP_Utility::date( 'G\hi\ms\s' ),
			$pType,
		), $pFilename );
		$file = str_replace( '%', '', $file );
		$file = MainWP_Utility::normalize_filename( $file );

		//Normal flow: check site settings & fallback to global
		if ( $pLoadFilesBeforeZip == false ) {
			$loadFilesBeforeZip = $website->loadFilesBeforeZip;
			if ( $loadFilesBeforeZip == 1 ) {
				$loadFilesBeforeZip = get_option( 'mainwp_options_loadFilesBeforeZip' );
				$loadFilesBeforeZip = ( $loadFilesBeforeZip == 1 || $loadFilesBeforeZip === false );
			} else {
				$loadFilesBeforeZip = ( $loadFilesBeforeZip == 2 );
			}
		} //Overriden flow: only fallback to global
		else if ( $pArchiveFormat == 'global' || $pLoadFilesBeforeZip == 1 ) {
			$loadFilesBeforeZip = get_option( 'mainwp_options_loadFilesBeforeZip' );
			$loadFilesBeforeZip = ( $loadFilesBeforeZip == 1 || $loadFilesBeforeZip === false );
		} else {
			$loadFilesBeforeZip = ( $pLoadFilesBeforeZip == 2 );
		}

		//Nomral flow: check site settings & fallback to global
		if ( $pArchiveFormat == false ) {
			$archiveFormat = MainWP_Utility::getCurrentArchiveExtension( $website );
		} //Overriden flow: only fallback to global
		else if ( $pArchiveFormat == 'global' ) {
			$archiveFormat = MainWP_Utility::getCurrentArchiveExtension();
		} else {
			$archiveFormat = $pArchiveFormat;
		}

		MainWP_Utility::endSession();

		$params = array(
			'type'                                       => $pType,
			'exclude'                                    => $pExclude,
			'excludebackup'                              => $excludebackup,
			'excludecache'                               => $excludecache,
			'excludenonwp'                               => $excludenonwp,
			'excludezip'                                 => $excludezip,
			'ext'                                        => $archiveFormat,
			'file_descriptors_auto'                      => $maximumFileDescriptorsAuto,
			'file_descriptors'                           => $maximumFileDescriptors,
			'loadFilesBeforeZip'                         => $loadFilesBeforeZip,
			MainWP_Utility::getFileParameter( $website ) => $file,
			'fileUID'                                    => $pFileNameUID,
			'pid'                                        => $pid,
			'append'                                     => ( $append ? 1 : 0 ),
		);

		MainWP_Logger::Instance()->debugForWebsite( $website, 'backup', 'Requesting backup: ' . print_r( $params, 1 ) );

		$information = MainWP_Utility::fetchUrlAuthed( $website, 'backup', $params, false, false, false );
		do_action( 'mainwp_managesite_backup', $website, array( 'type' => $pType ), $information );

		if ( isset( $information['error'] ) ) {
			throw new MainWP_Exception( $information['error'] );
		} else if ( $pType == 'db' && ! $information['db'] ) {
			throw new MainWP_Exception( 'Database backup failed.' );
		} else if ( $pType == 'full' && ! $information['full'] ) {
			throw new MainWP_Exception( 'Full backup failed.' );
		} else if ( isset( $information['db'] ) ) {
			if ( $information['db'] != false ) {
				$backup_result['url']  = $information['db'];
				$backup_result['type'] = 'db';
			} else if ( $information['full'] != false ) {
				$backup_result['url']  = $information['full'];
				$backup_result['type'] = 'full';
			}

			if ( isset( $information['size'] ) ) {
				$backup_result['size'] = $information['size'];
			}
			$backup_result['subfolder'] = $subfolder;

			$dir = MainWP_Utility::getMainWPSpecificDir( $pSiteId );

			$fm_date = MainWP_Utility::sanitize_file_name( MainWP_Utility::date( get_option( 'date_format' ) ) );
			$fm_time = MainWP_Utility::sanitize_file_name( MainWP_Utility::date( get_option( 'time_format' ) ) );

			if ( $pType == 'db' ) {
				$localBackupFile = $dir . 'db-' . $websiteCleanUrl . '-' . $fm_date . '-' . $fm_time . MainWP_Utility::getRealExtension( $information['db'] );
				$localRegexFile  = 'db-' . $websiteCleanUrl . '-(.*)-(.*).sql(\.zip|\.tar|\.tar\.gz|\.tar\.bz2)?';
			} else {
				$localBackupFile = $dir . 'full-' . $websiteCleanUrl . '-' . $fm_date . '-' . $fm_time . MainWP_Utility::getRealExtension( $information['full'] );
				$localRegexFile  = 'full-' . $websiteCleanUrl . '-(.*)-(.*).(zip|tar|tar.gz|tar.bz2)';
			}

			if ( $pFilename != null ) {
				$filename        = str_replace( array(
					'%sitename%',
					'%url%',
					'%date%',
					'%time%',
					'%type%',
				), array(
					MainWP_Utility::sanitize( $website->name ),
					$websiteCleanUrl,
					$fm_date,
					$fm_time,
					$pType,
				), $pFilename );
				$filename        = str_replace( '%', '', $filename );
				$localBackupFile = $dir . $filename;
				$localBackupFile = MainWP_Utility::normalize_filename( $localBackupFile );

				if ( $pType == 'db' ) {
					$localBackupFile .= MainWP_Utility::getRealExtension( $information['db'] );
				} else {
					$localBackupFile .= MainWP_Utility::getRealExtension( $information['full'] );
				}
			}

			$backup_result['local']     = $localBackupFile;
			$backup_result['regexfile'] = $localRegexFile;

			return $backup_result;
		} else {
			throw new MainWP_Exception( 'Database backup failed due to an undefined error' );
		}
	}

	public static function on_load_page_dashboard() {
		wp_enqueue_script( 'common' );
		wp_enqueue_script( 'wp-lists' );
		wp_enqueue_script( 'postbox' );
		wp_enqueue_script( 'dashboard' );
		wp_enqueue_script( 'widgets' );

		$i = 1;
		add_meta_box( self::$page . '-metaboxes-contentbox-' . $i ++, MainWP_Right_Now::getName(), array(
			MainWP_Right_Now::getClassName(),
			'render',
		), self::$page, 'normal', 'core' );
		add_meta_box( self::$page . '-contentbox-' . $i ++, MainWP_Connection_Status::getName(), array(
			MainWP_Connection_Status::getClassName(),
			'render',
		), self::$page, 'normal', 'core' );
		if ( mainwp_current_user_can( 'dashboard', 'manage_posts' ) ) {
			add_meta_box( self::$page . '-metaboxes-contentbox-' . $i ++, MainWP_Recent_Posts::getName(), array(
				MainWP_Recent_Posts::getClassName(),
				'render',
			), self::$page, 'normal', 'core' );
		}
		if ( mainwp_current_user_can( 'dashboard', 'manage_pages' ) ) {
			add_meta_box( self::$page . '-metaboxes-contentbox-' . $i ++, MainWP_Recent_Pages::getName(), array(
				MainWP_Recent_Pages::getClassName(),
				'render',
			), self::$page, 'normal', 'core' );
		}
		add_meta_box( self::$page . '-metaboxes-contentbox-' . $i ++, MainWP_Shortcuts::getName(), array(
			MainWP_Shortcuts::getClassName(),
			'render',
		), self::$page, 'normal', 'core' );
		if ( mainwp_current_user_can( 'dashboard', 'manage_security_issues' ) ) {
			add_meta_box( self::$page . '-metaboxes-contentbox-' . $i ++, MainWP_Security_Issues::getMetaboxName(), array(
				MainWP_Security_Issues::getClassName(),
				'renderMetabox',
			), self::$page, 'normal', 'core' );
		}

		global $mainwpUseExternalPrimaryBackupsMethod;
		if ( empty( $mainwpUseExternalPrimaryBackupsMethod ) ) {
                    if (get_option('mainwp_enableLegacyBackupFeature')) {
			add_meta_box( self::$page . '-metaboxes-contentbox-' . $i ++, MainWP_Manage_Backups::getMetaboxName(), array(
				MainWP_Manage_Backups::getClassName(),
				'renderMetabox',
			), self::$page, 'normal', 'core' );
                    }
		}

		add_meta_box( self::$page . '-metaboxes-contentbox-' . $i ++, MainWP_Widget_Plugins::getName(), array(
			MainWP_Widget_Plugins::getClassName(),
			'render',
		), self::$page, 'normal', 'core' );
		add_meta_box( self::$page . '-metaboxes-contentbox-' . $i ++, MainWP_Widget_Themes::getName(), array(
			MainWP_Widget_Themes::getClassName(),
			'render',
		), self::$page, 'normal', 'core' );
		add_meta_box( self::$page . '-metaboxes-contentbox-' . $i ++, MainWP_Notes::getName(), array(
			MainWP_Notes::getClassName(),
			'render',
		), self::$page, 'normal', 'core' );

		/**
		 * This hook allows you to add extra metaboxes to the dashboard via the 'mainwp-getmetaboxes' filter.
		 * @link http://codex.mainwp.com/#mainwp-getmetaboxes
		 */
		$extMetaBoxs = MainWP_System::Instance()->apply_filter( 'mainwp-getmetaboxes', array() );
		$extMetaBoxs = apply_filters( 'mainwp-getmetaboxs', $extMetaBoxs );
		foreach ( $extMetaBoxs as $metaBox ) {
			add_meta_box( self::$page . '-contentbox-' . $i++, $metaBox['metabox_title'], $metaBox['callback'], self::$page, 'normal', 'core' );
		}

        add_meta_box( self::$page . '-metaboxes-contentbox-' . $i++, MainWP_Site_Info::getName(), array(
                MainWP_Site_Info::getClassName(),
                'render',
        ), self::$page, 'normal', 'core' );
	}
        
        public static function renderUpdates( $website ) {
		MainWP_Utility::set_current_wpid( $website->id );
		self::renderHeader( 'ManageSitesUpdates' );
		MainWP_Manage_Sites_View::renderUpdates();
		self::renderFooter( 'ManageSitesUpdates' );
	}
        
	public static function renderDashboard( $website ) {
		MainWP_Utility::set_current_wpid( $website->id );
		self::renderHeader( 'ManageSitesDashboard' );
		MainWP_Manage_Sites_View::renderDashboard( $website, self::$page );
		self::renderFooter( 'ManageSitesDashboard' );
	}

	public static function renderBackupSite( $website ) {
		self::renderHeader( 'ManageSitesBackups' );
		MainWP_Manage_Sites_View::renderBackupSite( $website );
		self::renderFooter( 'ManageSitesBackups' );
	}

	public static function renderScanSite( $website ) {
		self::renderHeader( 'SecurityScan' );
		MainWP_Manage_Sites_View::renderScanSite( $website );
		self::renderFooter( 'SecurityScan' );
	}

	public static function showBackups( &$website ) {
		$dir = MainWP_Utility::getMainWPSpecificDir( $website->id );

		if ( ! file_exists( $dir . 'index.php' ) ) {
			@touch( $dir . 'index.php' );
		}
		$dbBackups   = array();
		$fullBackups = array();
		if ( file_exists( $dir ) && ( $dh = opendir( $dir ) ) ) {
			while ( ( $file = readdir( $dh ) ) !== false ) {
				if ( $file != '.' && $file != '..' ) {
					$theFile = $dir . $file;
					if ( MainWP_Utility::isSQLFile( $file ) ) {
						$dbBackups[ filemtime( $theFile ) . $file ] = $theFile;
					} else if ( MainWP_Utility::isArchive( $file ) ) {
						$fullBackups[ filemtime( $theFile ) . $file ] = $theFile;
					}
				}
			}
			closedir( $dh );
		}
		krsort( $dbBackups );
		krsort( $fullBackups );

		MainWP_Manage_Sites_View::showBackups( $website, $fullBackups, $dbBackups );
	}

	protected static function getOppositeOrderBy( $pOrderBy ) {
		return ( $pOrderBy == 'asc' ? 'desc' : 'asc' );
	}

	public static function renderAllSites( $showDelete = true, $showAddNew = true ) {
		self::renderHeader( '' );

		$userExtension = MainWP_DB::Instance()->getUserExtension();

		self::$sitesTable->prepare_items();

		if ( MainWP_Twitter::enabledTwitterMessages() ) {
			$filter = array(    'upgrade_all_plugins',
				'upgrade_all_themes',
				'upgrade_all_wp_core'
			);
			foreach ( $filter as $what ) {
				$twitters = MainWP_Twitter::getTwitterNotice( $what );
				if ( is_array( $twitters ) ) {
					foreach ( $twitters as $timeid => $twit_mess ) {
						if ( !empty( $twit_mess ) ) {
							$sendText = MainWP_Twitter::getTwitToSend( $what, $timeid );
							if ( !empty( $sendText ) ) {
								?>
								<div class="mainwp-tips mainwp-notice mainwp-notice-blue twitter"><span class="mainwp-tip" twit-what="<?php echo $what; ?>" twit-id="<?php echo $timeid; ?>"><?php echo $twit_mess; ?></span>&nbsp;<?php MainWP_Twitter::genTwitterButton( $sendText );?><span><a href="#" class="mainwp-dismiss-twit mainwp-right" ><i class="fa fa-times-circle"></i> <?php _e('Dismiss','mainwp'); ?></a></span></div>
								<?php
							}
						}
					}
				}
			}
		}
                
                $current_options = get_option( 'mainwp_opts_saving_status' );
                $col_orders = "";
                if (is_array($current_options) && isset($current_options['sites_col_order'])) {
                    $col_orders = $current_options['sites_col_order'];
                }
		?>
		<div id="mainwp_managesites_content">
            <?php MainWP_Tours::renderSitesTour(); ?>
            <?php if ( MainWP_Utility::showUserTip( 'mainwp-screenoptions-tips' ) ) { ?>
                <div class="mainwp-tips mainwp-notice mainwp-notice-blue">
                    <span class="mainwp-tip" id="mainwp-screenoptions-tips"><strong><?php _e( 'MainWP Tip', 'mainwp' ); ?>: </strong><?php _e( 'You can manage table columns in the Screen Options tab.', 'mainwp' ); ?></span><span><a href="#" class="mainwp-dismiss"><i class="fa fa-times-circle"></i> <?php _e( 'Dismiss', 'mainwp' ); ?></a></span>
                </div>
            <?php } ?>
			<div id="mainwp_managesites_add_errors" class="mainwp_error mainwp-notice mainwp-notice-red"></div>
			<div id="mainwp_managesites_add_message" class="mainwp-notice mainwp-notice-green mainwp-notice mainwp-notice-green"></div>
			<div id="mainwp_managesites_add_other_message" class="mainwp-notice mainwp-notice-green mainwp-notice mainwp-notice-green hidden"></div>
			<?php
			MainWP_Manage_Sites_View::_renderInfo();
			?>
			<form method="post" class="mainwp-table-container">
				<input type="hidden" name="page" value="sites_list_table">
				<?php
				MainWP_Manage_Sites_View::_renderNotes();
				self::$sitesTable->display();
				self::$sitesTable->clear_items();
				?>
			</form>                                           
                <script type="text/javascript">
                    jQuery( document ).ready( function () {       
                            var sitesColOrder = '<?php echo $col_orders; ?>' ;  
                            mainwp_table_draggable_init('site', 'table.sites', sitesColOrder)
                    })
                </script>
                
		</div>
		<div id="managesites-backup-box" title="Full backup required" style="display: none; text-align: center">
			<div style="height: 190px; overflow: auto; margin-top: 20px; margin-bottom: 10px; text-align: left" id="managesites-backup-content">
			</div>
			<input id="managesites-backup-all" type="button" name="Backup All" value="<?php esc_attr_e( 'Backup all', 'mainwp' ); ?>" class="button-primary"/>
                        <a id="managesites-backup-now" href="#" target="_blank" style="display: none"  class="button-primary button"><?php _e( 'Backup Now', 'mainwp' ); ?></a>
			<input id="managesites-backup-ignore" type="button" name="Ignore" value="<?php esc_attr_e( 'Ignore', 'mainwp' ); ?>" class="button"/>
		</div>

		<div id="managesites-backupnow-box" title="Full backup" style="display: none; text-align: center">
			<div style="height: 190px; overflow: auto; margin-top: 20px; margin-bottom: 10px; text-align: left" id="managesites-backupnow-content">
			</div>
			<input id="managesites-backupnow-close" type="button" name="Ignore" value="<?php esc_attr_e( 'Cancel', 'mainwp' ); ?>" class="button"/>
		</div>

		<?php

		self::renderFooter( '' );
	}

	public static function renderManageSites() {
		global $current_user;

		if ( isset( $_REQUEST['do'] ) ) {
			if ( $_REQUEST['do'] == 'new' ) {
				self::renderNewSite();
			} else if ( $_REQUEST['do'] == 'bulknew' ) {
				self::renderBulkNewSite();
			} else if ( $_REQUEST['do'] == 'test' ) {
				self::renderTest();
			}

			return;
		}
                
                if (get_option('mainwp_enableLegacyBackupFeature')) {
                    if ( isset( $_GET['backupid'] ) && MainWP_Utility::ctype_digit( $_GET['backupid'] ) ) {
                            $websiteid = $_GET['backupid'];

                            $backupwebsite = MainWP_DB::Instance()->getWebsiteById( $websiteid );
                            if ( MainWP_Utility::can_edit_website( $backupwebsite ) ) {
                                    MainWP_Manage_Sites::renderBackupSite( $backupwebsite );

                                    return;
                            }
                    }
                }

		if ( isset( $_GET['scanid'] ) && MainWP_Utility::ctype_digit( $_GET['scanid'] ) ) {
			$websiteid = $_GET['scanid'];

			$scanwebsite = MainWP_DB::Instance()->getWebsiteById( $websiteid );
			if ( MainWP_Utility::can_edit_website( $scanwebsite ) ) {
				MainWP_Manage_Sites::renderScanSite( $scanwebsite );

				return;
			}
		}
		
		if ( isset( $_GET['dashboard'] ) && MainWP_Utility::ctype_digit( $_GET['dashboard'] ) ) {
			$websiteid = $_GET['dashboard'];

			$dashboardWebsite = MainWP_DB::Instance()->getWebsiteById( $websiteid );
			if ( MainWP_Utility::can_edit_website( $dashboardWebsite ) ) {
				MainWP_Manage_Sites::renderDashboard( $dashboardWebsite );

				return;
			}
		}

		if ( isset( $_GET['updateid'] ) && MainWP_Utility::ctype_digit( $_GET['updateid'] ) ) {
			$websiteid = $_GET['updateid'];
			$updatesWebsite = MainWP_DB::Instance()->getWebsiteById( $websiteid );
			if ( MainWP_Utility::can_edit_website( $updatesWebsite ) ) {
				MainWP_Manage_Sites::renderUpdates( $updatesWebsite );
				return;
			}
		}

		if ( isset( $_GET['id'] ) && MainWP_Utility::ctype_digit( $_GET['id'] ) ) {
			$websiteid = $_GET['id'];

			$website = MainWP_DB::Instance()->getWebsiteById( $websiteid );
			if ( MainWP_Utility::can_edit_website( $website ) ) {
				
				global $current_user;					
				$updated = false;		
				//Edit website!
				if ( isset( $_POST['submit'] ) && isset( $_POST['mainwp_managesites_edit_siteadmin'] ) && ( $_POST['mainwp_managesites_edit_siteadmin'] != '' ) && wp_verify_nonce( $_POST['wp_nonce'], 'UpdateWebsite' . $_GET['id'] ) ) {			
					if ( mainwp_current_user_can( 'dashboard', 'edit_sites' ) ) {				
						//update site
						$groupids   = array();
						$groupnames = array();
                        $tmpArr = array();
						if ( isset( $_POST['selected_groups'] ) ) {
							foreach ( $_POST['selected_groups'] as $group ) {
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
				
				$newPluginDir = ( isset( $_POST['mainwp_options_footprint_plugin_folder'] ) ? $_POST['mainwp_options_footprint_plugin_folder'] : '' );

                                                $maximumFileDescriptorsOverride = isset( $_POST['mainwp_options_maximumFileDescriptorsOverride'] );
                                                $maximumFileDescriptorsAuto     = isset( $_POST['mainwp_maximumFileDescriptorsAuto'] );
                                                $maximumFileDescriptors         = isset( $_POST['mainwp_options_maximumFileDescriptors'] ) && MainWP_Utility::ctype_digit( $_POST['mainwp_options_maximumFileDescriptors'] ) ? $_POST['mainwp_options_maximumFileDescriptors'] : 150;

                                                $archiveFormat = isset( $_POST['mainwp_archiveFormat'] ) ? $_POST['mainwp_archiveFormat'] : 'global';

                                                $http_user = $_POST['mainwp_managesites_edit_http_user'];
                                                $http_pass = $_POST['mainwp_managesites_edit_http_pass'];
                                                $url = $_POST['mainwp_managesites_edit_siteurl_protocol'] . '://' . MainWP_Utility::removeHttpPrefix( $website->url, true);

                                                MainWP_DB::Instance()->updateWebsite( $websiteid, $url, $current_user->ID, htmlentities( $_POST['mainwp_managesites_edit_sitename'] ), $_POST['mainwp_managesites_edit_siteadmin'], $groupids, $groupnames, '', $newPluginDir, $maximumFileDescriptorsOverride, $maximumFileDescriptorsAuto, $maximumFileDescriptors, $_POST['mainwp_managesites_edit_verifycertificate'], $archiveFormat, isset( $_POST['mainwp_managesites_edit_uniqueId'] ) ? $_POST['mainwp_managesites_edit_uniqueId'] : '', $http_user, $http_pass );
                                                do_action( 'mainwp_update_site', $websiteid );

                                                $backup_before_upgrade = isset( $_POST['mainwp_backup_before_upgrade'] ) ? intval( $_POST['mainwp_backup_before_upgrade'] ) : 2;
                                                if ( $backup_before_upgrade > 2 ) {
                                                        $backup_before_upgrade = 2;
                                                }

                                                $newValues = array(
                                                        'automatic_update'      => ( ! isset( $_POST['mainwp_automaticDailyUpdate'] ) ? 0 : 1 ),
                                                        'backup_before_upgrade' => $backup_before_upgrade,
                                                        'loadFilesBeforeZip'    => isset( $_POST['mainwp_options_loadFilesBeforeZip'] ) ? 1 : 0,
                                                );

                                                if ( mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) {
                                                        $newValues['is_ignoreCoreUpdates']   = ( isset( $_POST['mainwp_is_ignoreCoreUpdates'] ) && $_POST['mainwp_is_ignoreCoreUpdates'] ) ? 1 : 0;
                                                        $newValues['is_ignorePluginUpdates'] = ( isset( $_POST['mainwp_is_ignorePluginUpdates'] ) && ( $_POST['mainwp_is_ignorePluginUpdates'] ) ) ? 1 : 0;
                                                        $newValues['is_ignoreThemeUpdates']  = ( isset( $_POST['mainwp_is_ignoreThemeUpdates'] ) && ( $_POST['mainwp_is_ignoreThemeUpdates'] ) ) ? 1 : 0;
                                                }

                                                MainWP_DB::Instance()->updateWebsiteValues( $websiteid, $newValues );
                                                $updated = true;
                                            }
                                        }
                                        MainWP_Manage_Sites::renderEditSite($websiteid, $updated);
                                        return;        
                        }			

		} 		
		MainWP_Manage_Sites::renderAllSites();
	}

	public static function renderEditSite($websiteid, $updated) {		
        self::renderHeader( 'ManageSitesEdit' );
        MainWP_Manage_Sites_View::renderEditSite( $websiteid, $updated);
        self::renderFooter( 'ManageSitesEdit' );
    }

	public static function checkSite() {
		$website = MainWP_DB::Instance()->getWebsitesByUrl( $_POST['url'] );
		$ret     = array();
		if ( MainWP_Utility::can_edit_website( $website ) ) { //Already added to the database - so exists.
			$ret['response'] = 'ERROR You already added your site to MainWP';
		} else {
			try {
				$verify_cert = ( !isset( $_POST['verify_certificate'] ) || ( empty( $_POST['verify_certificate'] ) && ( $_POST['verify_certificate'] !== '0' ) ) ? null : $_POST['verify_certificate'] );
				$http_user   = ( isset( $_POST['http_user'] ) ? $_POST['http_user'] : '' );
				$http_pass   = ( isset( $_POST['http_pass'] ) ? $_POST['http_pass'] : '' );
				$information = MainWP_Utility::fetchUrlNotAuthed( $_POST['url'], $_POST['admin'], 'stats', null, false, $verify_cert, $http_user, $http_pass ); //Fetch the stats with the given admin name

				if ( isset( $information['wpversion'] ) ) { //Version found - able to add
					$ret['response'] = 'OK';
				} else if ( isset( $information['error'] ) ) { //Error
					$ret['response'] = 'ERROR ' . $information['error'];
				} else { //Should not occur?
					$ret['response'] = 'ERROR';
				}
			} catch ( MainWP_Exception $e ) {
				//Exception - error
				$ret['response'] = $e->getMessage();
			}
		}
		$ret['check_me'] = ( isset( $_POST['check_me'] ) ? $_POST['check_me'] : null );
		die( json_encode( $ret ) );
	}

	public static function reconnectSite() {
		$siteId = $_POST['siteid'];

		try {
			if ( MainWP_Utility::ctype_digit( $siteId ) ) {
				$website = MainWP_DB::Instance()->getWebsiteById( $siteId );
				self::_reconnectSite( $website );
			} else {
				throw new Exception( 'Invalid request' );
			}
		} catch ( Exception $e ) {
			die( 'ERROR ' . $e->getMessage() );
		}

		die( sprintf( __( 'Site successfully reconnected - Visit the site\'s %sOverview%s now.', 'mainwp' ), '<a href="admin.php?page=managesites&dashboard=' . $siteId . '" title="' . __( 'Overview', 'mainwp' ) . '">', '</a>' ) );
	}

	public static function _reconnectSite( $website ) {
		return MainWP_Manage_Sites_View::_reconnectSite( $website );
	}

	public static function addSite() {
		$ret     = array();
		$error   = '';
		$message = '';
		$site_id = 0;

		if ( isset( $_POST['managesites_add_wpurl'] ) && isset( $_POST['managesites_add_wpadmin'] ) ) {
			//Check if already in DB
			$website = MainWP_DB::Instance()->getWebsitesByUrl( $_POST['managesites_add_wpurl'] );
			list( $message, $error, $site_id ) = MainWP_Manage_Sites_View::addSite( $website );
		}

		$ret['add_me'] = ( isset( $_POST['add_me'] ) ? $_POST['add_me'] : null );
		if ( $error != '' ) {
			$ret['response'] = 'ERROR ' . $error;
			die( json_encode( $ret ) );
		}
		$ret['response'] = $message;
		$ret['siteid'] = $site_id;

		if ( MainWP_DB::Instance()->getWebsitesCount() == 1 ) {
			$ret['redirectUrl'] = admin_url( 'admin.php?page=managesites' );
		}

		die( json_encode( $ret ) );
	}

	public static function apply_plugin_settings() {
		$site_id = $_POST['siteId'];
		$ext_dir_slug = $_POST['ext_dir_slug'];
		if ( empty( $site_id ) ) {
			die( json_encode( array( 'error' => 'ERROR: empty site id' ) ) );
		}

		do_action('mainwp_applypluginsettings_' . $ext_dir_slug, $site_id);
		die( json_encode( array( 'error' => __('Undefined error!', 'mainwp' ) ) ) );
	}


	public static function saveNote() {
		if ( isset( $_POST['websiteid'] ) && MainWP_Utility::ctype_digit( $_POST['websiteid'] ) ) {
			$website = MainWP_DB::Instance()->getWebsiteById( $_POST['websiteid'] );
			if ( MainWP_Utility::can_edit_website( $website ) ) {
				//MainWP_DB::Instance()->updateNote( $website->id, esc_html( stripslashes( $_POST['note'] ) ) );
                MainWP_DB::Instance()->updateNote( $website->id, htmlentities(stripslashes($_POST['note']))); // to fix
				die( json_encode( array( 'result' => 'SUCCESS' ) ) );
			} else {
				die( json_encode( array( 'error' => 'Not your website!' ) ) );
			}
		}
		die( json_encode( array( 'undefined_error' => true ) ) );
	}


	public static function removeSite() {
		if ( isset( $_POST['id'] ) && MainWP_Utility::ctype_digit( $_POST['id'] ) ) {
			$website = MainWP_DB::Instance()->getWebsiteById( $_POST['id'] );
			if ( MainWP_Utility::can_edit_website( $website ) ) {
				$error = '';

				try {
					$information = MainWP_Utility::fetchUrlAuthed( $website, 'deactivate' );
				} catch ( MainWP_Exception $e ) {
					$error = $e->getMessage();
				}

                // delete icon file
                $favi     = MainWP_DB::Instance()->getWebsiteOption( $website, 'favi_icon', '' );
                if ( !empty( $favi ) && ( false !== strpos( $favi, 'favi-' . $website->id . '-' ) ) ) {
                    $dirs      = MainWP_Utility::getIconsDir();
                    if ( file_exists( $dirs[0] . $favi ) ) {
                        unlink( $dirs[0] . $favi );
                    }
                }

				//Remove from DB
				MainWP_DB::Instance()->removeWebsite( $website->id );
				do_action( 'mainwp_delete_site', $website );

				if ( $error === 'NOMAINWP' ) {
					$error = __( 'Be sure to deactivate the child plugin from the site to avoid potential security issues.', 'mainwp' );
				}

				if ( $error != '' ) {
					die( json_encode( array( 'error' => $error ) ) );
				} else if ( isset( $information['deactivated'] ) ) {
					die( json_encode( array( 'result' => 'SUCCESS' ) ) );
				} else {
					die( json_encode( array( 'undefined_error' => true ) ) );
				}
			}
		}
		die( json_encode( array( 'result' => 'NOSITE' ) ) );
	}

	public static function handleSettingsPost() {
		if ( MainWP_Utility::isAdmin() ) {
			if ( isset( $_POST['submit'] ) && wp_verify_nonce( $_POST['wp_nonce'], 'Settings' ) ) {
				if ( MainWP_Utility::ctype_digit( $_POST['mainwp_options_backupOnServer'] ) && $_POST['mainwp_options_backupOnServer'] > 0 ) {
					MainWP_Utility::update_option( 'mainwp_backupsOnServer', $_POST['mainwp_options_backupOnServer'] );
				}
				if ( MainWP_Utility::ctype_digit( $_POST['mainwp_options_maximumFileDescriptors'] ) && $_POST['mainwp_options_maximumFileDescriptors'] > - 1 ) {
					MainWP_Utility::update_option( 'mainwp_maximumFileDescriptors', $_POST['mainwp_options_maximumFileDescriptors'] );
				}
				MainWP_Utility::update_option( 'mainwp_maximumFileDescriptorsAuto', ( !isset( $_POST['mainwp_maximumFileDescriptorsAuto'] ) ? 0 : 1 ) );
				if ( MainWP_Utility::ctype_digit( $_POST['mainwp_options_backupOnExternalSources'] ) && $_POST['mainwp_options_backupOnExternalSources'] >= 0 ) {
					MainWP_Utility::update_option( 'mainwp_backupOnExternalSources', $_POST['mainwp_options_backupOnExternalSources'] );
				}
				MainWP_Utility::update_option( 'mainwp_archiveFormat', $_POST['mainwp_archiveFormat'] );
                                
                                $old_primaryBackup = get_option('mainwp_primaryBackup');
                                $old_enableLegacyBackup = get_option('mainwp_enableLegacyBackupFeature');
                                $updated_enableLegacyBackup = false;
                                
				if ( isset( $_POST['mainwp_primaryBackup'] ) ) {
                                    if (!empty($_POST['mainwp_primaryBackup'])) // not default backup method
                                    {
                                            MainWP_Utility::update_option( 'mainwp_notificationOnBackupFail', 0 );
                                            MainWP_Utility::update_option( 'mainwp_notificationOnBackupStart', 0 );
                                            MainWP_Utility::update_option( 'mainwp_chunkedBackupTasks', 0 );
                                            if (empty($old_primaryBackup)) {
                                                MainWP_Utility::update_option( 'mainwp_enableLegacyBackupFeature', 0 );                                    
                                                $updated_enableLegacyBackup = true;
                                            }
                                    } 
                                    MainWP_Utility::update_option( 'mainwp_primaryBackup', $_POST['mainwp_primaryBackup'] );                                                                            
                                }
                                
                                if (!isset($_POST['mainwp_primaryBackup']) || empty($_POST['mainwp_primaryBackup'])) {
					MainWP_Utility::update_option( 'mainwp_options_loadFilesBeforeZip', (!isset($_POST['mainwp_options_loadFilesBeforeZip']) ? 0 : 1) );
					MainWP_Utility::update_option( 'mainwp_notificationOnBackupFail', (!isset($_POST['mainwp_options_notificationOnBackupFail']) ? 0 : 1) );
					MainWP_Utility::update_option( 'mainwp_notificationOnBackupStart', (!isset($_POST['mainwp_options_notificationOnBackupStart']) ? 0 : 1) );
					MainWP_Utility::update_option( 'mainwp_chunkedBackupTasks', (!isset($_POST['mainwp_options_chunkedBackupTasks']) ? 0 : 1) );
				}
                                                                
                                $enableLegacyBackup = (isset($_POST['mainwp_options_enableLegacyBackupFeature']) && !empty($_POST['mainwp_options_enableLegacyBackupFeature'])) ? 1 : 0;                                
                                if ($enableLegacyBackup && empty($old_enableLegacyBackup)) {
                                    MainWP_Utility::update_option( 'mainwp_primaryBackup', '' );
                                }   
                                
                                if (!$updated_enableLegacyBackup)
                                    MainWP_Utility::update_option( 'mainwp_enableLegacyBackupFeature', $enableLegacyBackup );
				return true;
			}
		}
		return false;
	}

	public static function add_options() {
		$option = 'per_page';
		$args   = array(
			'label'   => MainWP_Manage_Sites_View::sitesPerPage(),
			'default' => 10,
			'option'  => 'mainwp_managesites_per_page',
		);
		add_screen_option( $option, $args );

		if ( false === get_user_option( 'mainwp_default_hide_actions_column' ) ) {
			global $current_user;
			$hidden = get_user_option( 'manage' . MainWP_Manage_Sites::$page . 'columnshidden' );
			if ( ! is_array( $hidden ) ) {
				$hidden = array();
			}
			$hidden[] = 'site_actions';
            $hidden[] = 'wpcore_update';
            $hidden[] = 'plugin_update';
            $hidden[] = 'theme_update';
            $hidden[] = 'groups';
            $hidden[] = 'last_post';
            $hidden[] = 'phpversion';

			update_user_option( $current_user->ID, 'manage' . MainWP_Manage_Sites::$page . 'columnshidden', $hidden, true );
			update_user_option( $current_user->ID, 'mainwp_default_hide_actions_column', 1 );
		}

		self::$sitesTable = new MainWP_Manage_Sites_List_Table();		
	}


	static function on_edit_site( $website ) {
		if ( isset( $_POST['submit'] ) && isset( $_POST['mainwp_managesites_edit_siteadmin'] ) && ( $_POST['mainwp_managesites_edit_siteadmin'] != '' ) && wp_verify_nonce( $_POST['wp_nonce'], 'UpdateWebsite' . $_GET['id'] ) ) {
			if ( isset( $_POST['mainwp_managesites_edit_uniqueId'] ) ) {
				?>
				<script type="text/javascript">
					jQuery( document ).ready( function () {
						mainwp_managesites_update_childsite_value( <?php echo $website->id; ?>, '<?php echo $website->uniqueId; ?>' );
					} );
				</script>
				<?php
			}
		}
	}

	public static function updateChildsiteValue() {
		if ( isset( $_POST['site_id'] ) && MainWP_Utility::ctype_digit( $_POST['site_id'] ) ) {
			$website = MainWP_DB::Instance()->getWebsiteById( $_POST['site_id'] );
			if ( MainWP_Utility::can_edit_website( $website ) ) {
				$error    = '';
				$uniqueId = isset( $_POST['unique_id'] ) ? $_POST['unique_id'] : '';
				try {
					$information = MainWP_Utility::fetchUrlAuthed( $website, 'update_values', array( 'uniqueId' => $uniqueId ) );
				} catch ( MainWP_Exception $e ) {
					$error = $e->getMessage();
				}

				if ( $error != '' ) {
					die( json_encode( array( 'error' => $error ) ) );
				} else if ( isset( $information['result'] ) && ( $information['result'] == 'ok' ) ) {
					die( json_encode( array( 'result' => 'SUCCESS' ) ) );
				} else {
					die( json_encode( array( 'undefined_error' => true ) ) );
				}
			}
		}
		die( json_encode( array( 'error' => 'NO_SIDE_ID' ) ) );
	}

	public static function setScreenOption( $status, $option, $value ) {
		if ( 'mainwp_managesites_per_page' == $option ) {
			return $value;
		}

		return null;
	}
}
