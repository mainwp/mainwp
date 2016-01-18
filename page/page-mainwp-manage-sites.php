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
		add_submenu_page( 'mainwp_tab', __( 'Sites Help', 'mainwp' ), '<div class="mainwp-hidden">' . __( 'Sites Help', 'mainwp' ) . '</div>', 'read', 'SitesHelp', array(
			MainWP_Manage_Sites::getClassName(),
			'QSGManageSites',
		) );

		if ( isset( $_REQUEST['dashboard'] ) ) {
			global $current_user;
			delete_user_option( $current_user->ID, 'screen_layout_toplevel_page_managesites' );
			add_filter( 'screen_layout_columns', array( self::getClassName(), 'on_screen_layout_columns' ), 10, 2 );

			$val = get_user_option( 'screen_layout_' . self::$page );
			if ( ! MainWP_Utility::ctype_digit( $val ) ) {
				global $current_user;
				update_user_option( $current_user->ID, 'screen_layout_' . self::$page, 2, true );
			}

			add_action( 'load-' . MainWP_Manage_Sites::$page, array(
				MainWP_Manage_Sites::getClassName(),
				'on_load_page_dashboard',
			) );
		} // Fix bug
		//        else if (isset($_REQUEST['id']))
		//        {
		//
		//        }
		else {
			//            add_action('load-'.MainWP_Manage_Sites::$page, array(MainWP_Manage_Sites::getClassName(), 'on_load_page_manage'));
			add_action( 'load-' . MainWP_Manage_Sites::$page, array( MainWP_Manage_Sites::getClassName(), 'add_options' ) );
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
				add_submenu_page( 'mainwp_tab', $subPage['title'], '<div class="mainwp-hidden">' . $subPage['title'] . '</div>', 'read', 'ManageSites' . $subPage['slug'], $subPage['callback'] );
			}
		}
	}

	public static function initMenuSubPages() {
		MainWP_Manage_Sites_View::initMenuSubPages( self::$subPages );
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
		if ( isset( $_REQUEST['mainwp_managesites_chk_bulkupload'] ) && $_REQUEST['mainwp_managesites_chk_bulkupload'] ) {
			self::renderBulkUpload();
		} else {
			$groups = MainWP_DB::Instance()->getGroupsForCurrentUser();
			self::renderHeader( 'AddNew' );
			MainWP_Manage_Sites_View::_renderNewSite( $groups );
			self::renderFooter( 'AddNew' );
		}
	}

	public static function renderTest() {
		self::renderHeader( 'Test' );

		MainWP_Manage_Sites_View::renderTest();

		self::renderFooter( 'Test' );
	}

	public static function renderBulkUpload() {
		self::renderHeader( 'ManageSitesBulkUpload' );
		MainWP_Manage_Sites_View::renderBulkUpload();
		self::renderFooter( 'ManageSitesBulkUpload' );
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
					'pid'                                        => $pid,
					MainWP_Utility::getFileParameter( $website ) => $file,
				), false, false, false );
			} catch ( MainWP_Exception $e ) {
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
			throw new MainWP_Exception( 'Database backup failed due to an undefined error' );
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
		$information = MainWP_Utility::fetchUrlAuthed( $website, 'backup', array(
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
		), false, false, false );
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

	public static function renderSeoPage( $website ) {
		MainWP_Manage_Sites_View::renderSeoPage( $website );
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
		if ( get_option( 'mainwp_seo' ) == 1 ) {
			add_meta_box( self::$page . '-metaboxes-contentbox-' . $i ++, MainWP_Manage_Sites::getMetaboxName(), array(
				MainWP_Manage_Sites::getClassName(),
				'renderMetabox',
			), self::$page, 'normal', 'core' );
		}

		global $mainwpUseExternalPrimaryBackupsMethod;
		if ( empty( $mainwpUseExternalPrimaryBackupsMethod ) ) {
			add_meta_box( self::$page . '-metaboxes-contentbox-' . $i ++, MainWP_Manage_Backups::getMetaboxName(), array(
				MainWP_Manage_Backups::getClassName(),
				'renderMetabox',
			), self::$page, 'normal', 'core' );
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
			add_meta_box( self::$page . '-contentbox-' . $i ++, $metaBox['metabox_title'], $metaBox['callback'], self::$page, 'normal', 'core' );
		}
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

	public static function _renderAllSites( $showDelete = true, $showAddNew = true ) {
		self::renderHeader( '' );

		$userExtension = MainWP_DB::Instance()->getUserExtension();

		$globalIgnoredPluginConflicts = json_decode( $userExtension->ignored_pluginConflicts, true );
		if ( ! is_array( $globalIgnoredPluginConflicts ) ) {
			$globalIgnoredPluginConflicts = array();
		}

		$globalIgnoredThemeConflicts = json_decode( $userExtension->ignored_themeConflicts, true );
		if ( ! is_array( $globalIgnoredThemeConflicts ) ) {
			$globalIgnoredThemeConflicts = array();
		}

		self::$sitesTable->prepare_items( $globalIgnoredPluginConflicts, $globalIgnoredThemeConflicts );

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
								<div class="mainwp-tips mainwp_info-box-blue twitter"><span class="mainwp-tip" twit-what="<?php echo $what; ?>" twit-id="<?php echo $timeid; ?>"><?php echo $twit_mess; ?></span>&nbsp;<?php MainWP_Twitter::genTwitterButton( $sendText );?><span><a href="#" class="mainwp-dismiss-twit" ><i class="fa fa-times-circle"></i> <?php _e('Dismiss','mainwp'); ?></a></span></div>
								<?php
							}
						}
					}
				}
			}
		}

		?>
		<div id="mainwp_managesites_content">
			<div id="mainwp_managesites_add_errors" class="mainwp_error mainwp_info-box-red"></div>
			<div id="mainwp_managesites_add_message" class="mainwp_updated updated mainwp_info-box"></div>
			<div id="mainwp_managesites_add_other_message" class="mainwp_updated updated mainwp_info-box hidden"></div>
			<?php
			MainWP_Manage_Sites_View::_renderInfo();

			//            self::$sitesTable->display_search();
			?>
			<form method="post" class="mainwp-table-container">
				<input type="hidden" name="page" value="sites_list_table">
				<?php
				MainWP_Manage_Sites_View::_renderNotes();
				self::$sitesTable->display();
				self::$sitesTable->clear_items();
				?>
			</form>
		</div>

		<div id="managesites-backup-box" title="Full backup required" style="display: none; text-align: center">
			<div style="height: 190px; overflow: auto; margin-top: 20px; margin-bottom: 10px; text-align: left" id="managesites-backup-content">
			</div>
			<input id="managesites-backup-all" type="button" name="Backup All" value="<?php esc_attr_e( 'Backup All', 'mainwp' ); ?>" class="button-primary"/>
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

	public static function renderAllSites() {
		global $current_user;

		if ( isset( $_REQUEST['do'] ) ) {
			if ( $_REQUEST['do'] == 'new' ) {
				self::renderNewSite();
			} else if ( $_REQUEST['do'] == 'test' ) {
				self::renderTest();
			}

			return;
		}
		$website = null;
		if ( isset( $_GET['backupid'] ) && MainWP_Utility::ctype_digit( $_GET['backupid'] ) ) {
			$websiteid = $_GET['backupid'];

			$backupwebsite = MainWP_DB::Instance()->getWebsiteById( $websiteid );
			if ( MainWP_Utility::can_edit_website( $backupwebsite ) ) {
				MainWP_Manage_Sites::renderBackupSite( $backupwebsite );

				return;
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

		if ( isset( $_GET['seowebsiteid'] ) && MainWP_Utility::ctype_digit( $_GET['seowebsiteid'] ) ) {
			$websiteid = $_GET['seowebsiteid'];

			$seoWebsite = MainWP_DB::Instance()->getWebsiteById( $websiteid );
			if ( MainWP_Utility::can_edit_website( $seoWebsite ) ) {
				MainWP_Manage_Sites::renderSeoPage( $seoWebsite );

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

		if ( isset( $_GET['id'] ) && MainWP_Utility::ctype_digit( $_GET['id'] ) ) {
			$websiteid = $_GET['id'];

			$website = MainWP_DB::Instance()->getWebsiteById( $websiteid );
			if ( ! MainWP_Utility::can_edit_website( $website ) ) {
				$website = null;
			}
		}

		if ( $website == null ) {
			self::_renderAllSites();
		} else {
			$updated = false;
			//Edit website!
			if ( isset( $_POST['submit'] ) && isset( $_POST['mainwp_managesites_edit_siteadmin'] ) && $_POST['mainwp_managesites_edit_siteadmin'] != '' ) {
				//update site
				$groupids   = array();
				$groupnames = array();
				if ( isset( $_POST['selected_groups'] ) ) {
					foreach ( $_POST['selected_groups'] as $group ) {
						$groupids[] = $group;
					}
				}
				if ( isset( $_POST['mainwp_managesites_edit_addgroups'] ) && $_POST['mainwp_managesites_edit_addgroups'] != '' ) {
					$tmpArr = explode( ',', $_POST['mainwp_managesites_edit_addgroups'] );
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
				$newPluginDir = ( isset( $_POST['mainwp_options_footprint_plugin_folder'] ) ? $_POST['mainwp_options_footprint_plugin_folder'] : '' );

				$maximumFileDescriptorsOverride = isset( $_POST['mainwp_options_maximumFileDescriptorsOverride'] );
				$maximumFileDescriptorsAuto     = isset( $_POST['mainwp_maximumFileDescriptorsAuto'] );
				$maximumFileDescriptors         = isset( $_POST['mainwp_options_maximumFileDescriptors'] ) && MainWP_Utility::ctype_digit( $_POST['mainwp_options_maximumFileDescriptors'] ) ? $_POST['mainwp_options_maximumFileDescriptors'] : 150;

				$archiveFormat = isset( $_POST['mainwp_archiveFormat'] ) ? $_POST['mainwp_archiveFormat'] : 'global';

				$http_user = $_POST['mainwp_managesites_edit_http_user'];
				$http_pass = $_POST['mainwp_managesites_edit_http_pass'];
				$url = $_POST['mainwp_managesites_edit_siteurl_protocol'] . '://' . MainWP_Utility::removeHttpPrefix( $website->url, true);

				MainWP_DB::Instance()->updateWebsite( $website->id, $url, $current_user->ID, $_POST['mainwp_managesites_edit_sitename'], $_POST['mainwp_managesites_edit_siteadmin'], $groupids, $groupnames, $_POST['offline_checks'], $newPluginDir, $maximumFileDescriptorsOverride, $maximumFileDescriptorsAuto, $maximumFileDescriptors, $_POST['mainwp_managesites_edit_verifycertificate'], $archiveFormat, isset( $_POST['mainwp_managesites_edit_uniqueId'] ) ? $_POST['mainwp_managesites_edit_uniqueId'] : '', $http_user, $http_pass );
				do_action( 'mainwp_update_site', $website->id );

				$backup_before_upgrade = isset( $_POST['mainwp_backup_before_upgrade'] ) ? intval( $_POST['mainwp_backup_before_upgrade'] ) : 2;
				if ( $backup_before_upgrade > 2 ) {
					$backup_before_upgrade = 2;
				}

				$newValues = array(
					'automatic_update'      => ( ! isset( $_POST['mainwp_automaticDailyUpdate'] ) ? 0 : 1 ),
					'backup_before_upgrade' => $backup_before_upgrade,
					'loadFilesBeforeZip'    => $_POST['mainwp_options_loadFilesBeforeZip'],
				);

				if ( mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) {
					$newValues['is_ignoreCoreUpdates']   = ( isset( $_POST['mainwp_is_ignoreCoreUpdates'] ) && $_POST['mainwp_is_ignoreCoreUpdates'] ) ? 1 : 0;
					$newValues['is_ignorePluginUpdates'] = ( isset( $_POST['mainwp_is_ignorePluginUpdates'] ) && ( $_POST['mainwp_is_ignorePluginUpdates'] ) ) ? 1 : 0;
					$newValues['is_ignoreThemeUpdates']  = ( isset( $_POST['mainwp_is_ignoreThemeUpdates'] ) && ( $_POST['mainwp_is_ignoreThemeUpdates'] ) ) ? 1 : 0;
				}

				MainWP_DB::Instance()->updateWebsiteValues( $website->id, $newValues );
				$updated = true;
				//Reload the site
				$website = MainWP_DB::Instance()->getWebsiteById( $website->id );
			}

			$groups    = MainWP_DB::Instance()->getGroupsForCurrentUser();
			$statusses = array( 'hourly', '2xday', 'daily', 'weekly' );

			$pluginDir = $website->pluginDir;
			self::renderHeader( 'ManageSitesEdit' );
			MainWP_Manage_Sites_View::renderAllSites( $website, $updated, $groups, $statusses, $pluginDir );
			self::renderFooter( 'ManageSitesEdit' );
		}
	}

	public static function checkSite() {
		$website = MainWP_DB::Instance()->getWebsitesByUrl( $_POST['url'] );
		$ret     = array();
		if ( MainWP_Utility::can_edit_website( $website ) ) { //Already added to the database - so exists.
			$ret['response'] = 'ERROR You already added your site to MainWP';
		} else {
			try {
				$verify_cert = ( !isset( $_POST['verify_certificate'] ) || empty( $_POST['verify_certificate'] ) ? null : $_POST['verify_certificate'] );
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

		die( sprintf( __( 'Site successfully reconnected - Visit the Site\'s %sDashboard%s now.', 'mainwp' ), '<a href="admin.php?page=managesites&dashboard=' . $siteId . '" title="' . __( 'Dashboard', 'mainwp' ) . '">', '</a>' ) );
	}

	public static function _reconnectSite( $website ) {
		return MainWP_Manage_Sites_View::_reconnectSite( $website );
	}

	public static function addSite() {
		$ret     = array();
		$error   = '';
		$message = '';

		if ( isset( $_POST['managesites_add_wpurl'] ) && isset( $_POST['managesites_add_wpadmin'] ) ) {
			//Check if already in DB
			$website = MainWP_DB::Instance()->getWebsitesByUrl( $_POST['managesites_add_wpurl'] );
			list( $message, $error ) = MainWP_Manage_Sites_View::addSite( $website );
		}

		$ret['add_me'] = ( isset( $_POST['add_me'] ) ? $_POST['add_me'] : null );
		if ( $error != '' ) {
			$ret['response'] = 'ERROR ' . $error;
			die( json_encode( $ret ) );
		}
		$ret['response'] = $message;
		if ( MainWP_DB::Instance()->getWebsitesCount() == 1 ) {
			$ret['redirectUrl'] = admin_url( 'admin.php?page=managesites' );
		}

		die( json_encode( $ret ) );
	}

	public static function saveNote() {
		if ( isset( $_POST['websiteid'] ) && MainWP_Utility::ctype_digit( $_POST['websiteid'] ) ) {
			$website = MainWP_DB::Instance()->getWebsiteById( $_POST['websiteid'] );
			if ( MainWP_Utility::can_edit_website( $website ) ) {
				MainWP_DB::Instance()->updateNote( $website->id, $_POST['note'] );
				die( json_encode( array( 'result' => 'SUCCESS' ) ) );
			} else {
				die( json_encode( array( 'error' => 'Not your website' ) ) );
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
			if ( isset( $_POST['submit'] ) ) {
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
				if (isset($_POST['mainwp_primaryBackup']) && !empty($_POST['mainwp_primaryBackup'])) // not default backup method
				{
					MainWP_Utility::update_option( 'mainwp_notificationOnBackupFail', 0 );
					MainWP_Utility::update_option( 'mainwp_notificationOnBackupStart', 0 );
					MainWP_Utility::update_option( 'mainwp_chunkedBackupTasks', 0 );
				} else {
					MainWP_Utility::update_option( 'mainwp_options_loadFilesBeforeZip', (!isset($_POST['mainwp_options_loadFilesBeforeZip']) ? 0 : 1) );
					MainWP_Utility::update_option( 'mainwp_notificationOnBackupFail', (!isset($_POST['mainwp_options_notificationOnBackupFail']) ? 0 : 1) );
					MainWP_Utility::update_option( 'mainwp_notificationOnBackupStart', (!isset($_POST['mainwp_options_notificationOnBackupStart']) ? 0 : 1) );
					MainWP_Utility::update_option( 'mainwp_chunkedBackupTasks', (!isset($_POST['mainwp_options_chunkedBackupTasks']) ? 0 : 1) );
				}

				return true;
			}
		}

		return false;
	}

	public static function renderSettings() {
		if ( MainWP_Utility::isAdmin() ) {
			MainWP_Manage_Sites_View::renderSettings();
		}
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
			update_user_option( $current_user->ID, 'manage' . MainWP_Manage_Sites::$page . 'columnshidden', $hidden, true );
			update_user_option( $current_user->ID, 'mainwp_default_hide_actions_column', 1 );
		}

		self::$sitesTable = new MainWP_Manage_Sites_List_Table();
	}

	public static function on_load_page_manage() {
		$screen = get_current_screen();
		// get out of here if we are not on our settings page
		if ( ! is_object( $screen ) || $screen->id != MainWP_Manage_Sites::$page ) {
			return;
		}

		$args = array(
			'label'   => MainWP_Manage_Sites_View::sitesPerPage(),
			'default' => 20,
			'option'  => 'mainwp_managesites_per_page',
		);
		add_screen_option( 'per_page', $args );
	}


	static function on_edit_site( $website ) {
		if ( isset( $_POST['submit'] ) && isset( $_POST['mainwp_managesites_edit_siteadmin'] ) && $_POST['mainwp_managesites_edit_siteadmin'] != '' ) {
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

	protected static function getPerPage() {
		// get the current user ID
		$user = get_current_user_id();
		// get the current admin screen
		$screen = get_current_screen();
		// retrieve the "per_page" option
		$screen_option = $screen->get_option( 'per_page', 'option' );
		// retrieve the value of the option stored for the current user
		$per_page = get_user_meta( $user, $screen_option, true );
		if ( empty( $per_page ) || $per_page < 1 ) {
			// get the default value if none is set
			$per_page = $screen->get_option( 'per_page', 'default' );
		}

		return $per_page;
	}

	public static function getMetaboxName() {
		return '<i class="fa fa-search"></i> SEO Details';
	}

	public static function renderMetabox() {
		$website = MainWP_Utility::get_current_wpid();
		if ( ! $website ) {
			return;
		}

		$website = MainWP_DB::Instance()->getWebsiteById( $website );

		MainWP_Manage_Sites_View::showSEOWidget( $website );
	}

	public static function QSGManageSites() {
		self::renderHeader( 'SitesHelp' );
		?>
		<div style="text-align: center">
			<a href="#" class="button button-primary" id="mainwp-quick-start-guide"><?php _e( 'Show Quick Start Guide', 'mainwp' ); ?></a>
		</div>
		<div class="mainwp_info-box-yellow" id="mainwp-qsg-tips">
			<span><a href="#" class="mainwp-show-qsg" number="1"><?php _e( 'Adding a Site To MainWP Dashboard', 'mainwp' ) ?></a>&nbsp;&nbsp;&nbsp;&nbsp;<a href="#" class="mainwp-show-qsg" number="2"><?php _e( 'How to use the Child Unique Security ID', 'mainwp' ) ?></a>&nbsp;&nbsp;&nbsp;&nbsp;<a href="#" class="mainwp-show-qsg" number="3"><?php _e( 'Individual Site Dashboard', 'mainwp' ) ?></a>&nbsp;&nbsp;&nbsp;&nbsp;<a href="#" class="mainwp-show-qsg" number="4"><?php _e( 'How to test a Site Connection', 'mainwp' ) ?></a></span><span><a href="#" id="mainwp-qsg-dismiss" style="float: right;"><i class="fa fa-times-circle"></i> <?php _e( 'Dismiss', 'mainwp' ); ?>
				</a></span>

			<div class="clear"></div>
			<div id="mainwp-qsgs">
				<div class="mainwp-qsg" number="1">
					<h3>Adding a Site To MainWP Dashboard</h3>

					<p>
					<ol>
						<li>
							Click the 'Add New Site' button in or go to MainWP > Sites > Add New<br/><br/>
							<img src="http://docs.mainwp.com/wp-content/uploads/2013/02/new-add-new-site-1024x64.jpg" style="wight: 100% !important;" alt="screenshot"/>
						</li>
						<li>
							Enter Site Name, Site URL and Administrator Username <br/><br/>
						</li>
						<li>
							Enter or Select a Group Name (optional) <br/><br/>
						</li>
						<li>
							Click the Add New Site button.
						</li>
					</ol>
					</p>
				</div>
				<div class="mainwp-qsg" number="2">
					<h3>How to use the Child Unique Security ID</h3>

					<p>
					<ol>
						<li>In the Child Site locate MainWP Settings in the Main WordPress Settings tab</li>
						<li>Click 'Require Unique Security ID'</li>
						<li>
							Click Save Changes <br/><br/>
							<img src="http://docs.mainwp.com/wp-content/uploads/2013/04/new-sec-id.jpg" style="wight: 100% !important;" alt="screenshot"/>
						</li>
						<li>In Your Main Network site Click the Add New Site button</li>
						<li>In the Child Unique Security ID field enter the Unique ID you received from your child</li>
						<li>Fill in all other fields as normal</li>
						<li>Click Add New Site</li>
					</ol>
					</p>
				</div>
				<div class="mainwp-qsg" number="3">
					<h3>Individual Site Dashboard</h3>

					<p>
					<ol>
						<li>
							Locate the wanted site in the list and click the 'Dashboard' link under the site name or just click on the site name
							<br/><br/>
							<img src="http://docs.mainwp.com/wp-content/uploads/2013/12/new-dashboard-link-1024x77.png" style="wight: 100% !important;" alt="screenshot"/>
						</li>
					</ol>
					</p>
				</div>
				<div class="mainwp-qsg" number="4">
					<h3>How to test a Site Connection</h3>

					<p>
					<ol>
						<li>
							Click the Test Connection Tab <br/><br/>
						</li>
						<li>Enter your sites URL</li>
						<li>
							Click Test Connection <br/><br/>
							<img src="http://docs.mainwp.com/wp-content/uploads/2013/05/new-test-connection.jpg" style="wight: 100% !important;" alt="screenshot"/>
						</li>
					</ol>
					</p>
				</div>
			</div>
		</div>
		<?php
		self::renderFooter( 'SitesHelp' );
	}
}
