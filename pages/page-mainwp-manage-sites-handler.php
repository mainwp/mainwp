<?php
/**
 * Manage Sites Handler.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * MainWP Manage Sites Handler Page
 */
class MainWP_Manage_Sites_Handler {

	public static function get_class_name() {
		return __CLASS__;
	}

	/**
	 * @throws MainWP_Exception
	 */
	public static function backup_site( $siteid, $pTask, $subfolder ) {
		// phpcs: ignore -- complex function.
		if ( ! get_option( 'mainwp_enableLegacyBackupFeature' ) ) {
			return false;
		}

		$userid        = $pTask->userid;
		$type          = $pTask->type;
		$exclude       = $pTask->exclude;
		$taskId        = $pTask->id;
		$excludebackup = $pTask->excludebackup;
		$excludecache  = $pTask->excludecache;
		$excludenonwp  = $pTask->excludenonwp;
		$excludezip    = $pTask->excludezip;
		$pFilename     = $pTask->filename;

		if ( '' === trim( $pFilename ) ) {
			$pFilename = null;
		}

		$backup_result = array();

		// Creating a backup.
		$website   = MainWP_DB::instance()->get_website_by_id( $siteid );
		$subfolder = str_replace( '%sitename%', MainWP_Utility::sanitize( $website->name ), $subfolder );
		$subfolder = str_replace( '%url%', MainWP_Utility::sanitize( MainWP_Utility::get_nice_url( $website->url ) ), $subfolder );
		$subfolder = str_replace( '%type%', $type, $subfolder );
		$subfolder = str_replace( '%date%', MainWP_Utility::date( 'Ymd' ), $subfolder );
		$subfolder = str_replace( '%task%', '', $subfolder );
		$subfolder = str_replace( '%', '', $subfolder );
		$subfolder = MainWP_Utility::remove_preslash_spaces( $subfolder );
		$subfolder = MainWP_Utility::normalize_filename( $subfolder );

		if ( ! MainWP_System::instance()->is_single_user() && ( $userid != $website->userid ) ) {
			throw new MainWP_Exception( 'Undefined error.' );
		}

		$websiteCleanUrl = $website->url;
		if ( '/' === substr( $websiteCleanUrl, - 1 ) ) {
			$websiteCleanUrl = substr( $websiteCleanUrl, 0, - 1 );
		}
		$websiteCleanUrl = str_replace(
			array( 'http://', 'https://', '/' ),
			array( '', '', '-' ),
			$websiteCleanUrl
		);

		if ( 'db' === $type ) {
			$ext = '.sql.' . MainWP_Utility::get_current_archive_extension( $website, $pTask );
		} else {
			$ext = '.' . MainWP_Utility::get_current_archive_extension( $website, $pTask );
		}

		$file = str_replace(
			array(
				'%sitename%',
				'%url%',
				'%date%',
				'%time%',
				'%type%',
			),
			array(
				MainWP_Utility::sanitize( $website->name ),
				$websiteCleanUrl,
				MainWP_Utility::date( 'm-d-Y' ),
				MainWP_Utility::date( 'G\hi\ms\s' ),
				$type,
			),
			$pFilename
		);
		$file = str_replace( '%', '', $file );
		$file = MainWP_Utility::normalize_filename( $file );

		if ( ! empty( $file ) ) {
			$file .= $ext;
		}

		if ( 'zip' === $pTask->archiveFormat ) {
			$loadFilesBeforeZip = $pTask->loadFilesBeforeZip;
		} elseif ( '' == $pTask->archiveFormat || 'site' == $pTask->archiveFormat ) {
			$loadFilesBeforeZip = $website->loadFilesBeforeZip;
		} else {
			$loadFilesBeforeZip = 1;
		}

		if ( 1 === $loadFilesBeforeZip ) {
			$loadFilesBeforeZip = get_option( 'mainwp_options_loadFilesBeforeZip' );
			$loadFilesBeforeZip = ( 1 === $loadFilesBeforeZip || false === $loadFilesBeforeZip );
		} else {
			$loadFilesBeforeZip = ( 2 === $loadFilesBeforeZip );
		}

		if ( ( 'zip' == $pTask->archiveFormat ) && ( 1 == $pTask->maximumFileDescriptorsOverride ) ) {
			$maximumFileDescriptorsAuto = ( 1 == $pTask->maximumFileDescriptorsAuto );
			$maximumFileDescriptors     = $pTask->maximumFileDescriptors;
		} elseif ( ( '' == $pTask->archiveFormat || 'site' == $pTask->archiveFormat ) && ( 1 == $website->maximumFileDescriptorsOverride ) ) {
			$maximumFileDescriptorsAuto = ( 1 == $website->maximumFileDescriptorsAuto );
			$maximumFileDescriptors     = $website->maximumFileDescriptors;
		} else {
			$maximumFileDescriptorsAuto = get_option( 'mainwp_maximumFileDescriptorsAuto' );
			$maximumFileDescriptors     = get_option( 'mainwp_maximumFileDescriptors' );
			$maximumFileDescriptors     = ( false === $maximumFileDescriptors ? 150 : $maximumFileDescriptors );
		}

		$information        = false;
		$backupTaskProgress = MainWP_DB_Backup::instance()->get_backup_task_progress( $taskId, $website->id );
		if ( empty( $backupTaskProgress ) || ( $backupTaskProgress->dtsFetched < $pTask->last_run ) ) {
			$start = microtime( true );
			try {
				$pid = time();

				if ( empty( $backupTaskProgress ) ) {
					MainWP_DB_Backup::instance()->add_backup_task_progress( $taskId, $website->id, array() );
				}

				MainWP_DB_Backup::instance()->update_backup_task_progress(
					$taskId,
					$website->id,
					array(
						'dtsFetched'             => time(),
						'fetchResult'            => wp_json_encode( array() ),
						'downloadedDB'           => '',
						'downloadedDBComplete'   => 0,
						'downloadedFULL'         => '',
						'downloadedFULLComplete' => 0,
						'removedFiles'           => 0,
						'attempts'               => 0,
						'last_error'             => '',
						'pid'                    => $pid,
					)
				);

				$params = array(
					'type'                  => $type,
					'exclude'               => $exclude,
					'excludebackup'         => $excludebackup,
					'excludecache'          => $excludecache,
					'excludenonwp'          => $excludenonwp,
					'excludezip'            => $excludezip,
					'ext'                   => MainWP_Utility::get_current_archive_extension( $website, $pTask ),
					'file_descriptors_auto' => $maximumFileDescriptorsAuto,
					'file_descriptors'      => $maximumFileDescriptors,
					'loadFilesBeforeZip'    => $loadFilesBeforeZip,
					'pid'                   => $pid,
					'f'						=> $file,
				);

				MainWP_Logger::instance()->debug_for_website( $website, 'backup', 'Requesting backup: ' . MainWP_Utility::value_to_string( $params, 1 ) );

				$information = MainWP_Connect::fetch_url_authed( $website, 'backup', $params, false, false, false );
			} catch ( MainWP_Exception $e ) {
				MainWP_Logger::instance()->warning_for_website( $website, 'backup', 'ERROR: ' . $e->getMessage() . ' (' . $e->get_message_extra() . ')' );
				$stop = microtime( true );
				// Bigger then 30 seconds means a timeout.
				if ( 30 < ( $stop - $start ) ) {
					MainWP_DB_Backup::instance()->update_backup_task_progress(
						$taskId,
						$website->id,
						array(
							'last_error' => wp_json_encode(
								array(
									'message'    => $e->getMessage(),
									'extra'      => $e->get_message_extra(),
								)
							),
						)
					);

					return false;
				}

				throw $e;
			}

			if ( isset( $information['error'] ) && stristr( $information['error'], 'Another backup process is running' ) ) {
				return false;
			}

			$backupTaskProgress = MainWP_DB_Backup::instance()->update_backup_task_progress( $taskId, $website->id, array( 'fetchResult' => wp_json_encode( $information ) ) );
		} elseif ( empty( $backupTaskProgress->fetchResult ) ) {
			try {
				$temp = MainWP_Connect::fetch_url_authed( $website, 'backup_checkpid', array( 'pid' => $backupTaskProgress->pid ) );
			} catch ( \Exception $e ) {
				// ok!
			}

			if ( ! empty( $temp ) ) {
				if ( 'stalled' === $temp['status'] ) {
					if ( 5 > $backupTaskProgress->attempts ) {
						$backupTaskProgress = MainWP_DB_Backup::instance()->update_backup_task_progress( $taskId, $website->id, array( 'attempts' => $backupTaskProgress->attempts ++ ) );

						try {
							$information = MainWP_Connect::fetch_url_authed(
								$website,
								'backup',
								array(
									'type'               => $type,
									'exclude'            => $exclude,
									'excludebackup'      => $excludebackup,
									'excludecache'       => $excludecache,
									'excludenonwp'       => $excludenonwp,
									'excludezip'         => $excludezip,
									'ext'                => MainWP_Utility::get_current_archive_extension( $website, $pTask ),
									'file_descriptors_auto' => $maximumFileDescriptorsAuto,
									'file_descriptors'   => $maximumFileDescriptors,
									'loadFilesBeforeZip' => $loadFilesBeforeZip,
									'pid'                => $backupTaskProgress->pid,
									'append'             => '1',
									'f'					=> $temp['file'],
								),
								false,
								false,
								false
							);

							if ( isset( $information['error'] ) && stristr( $information['error'], 'Another backup process is running' ) ) {
								MainWP_DB_Backup::instance()->update_backup_task_progress( $taskId, $website->id, array( 'attempts' => ( $backupTaskProgress->attempts - 1 ) ) );

								return false;
							}
						} catch ( MainWP_Exception $e ) {
							return false;
						}

						$backupTaskProgress = MainWP_DB_Backup::instance()->update_backup_task_progress( $taskId, $website->id, array( 'fetchResult' => wp_json_encode( $information ) ) );
					} else {
						throw new MainWP_Exception( 'Backup failed after 5 retries.' );
					}
				} elseif ( 'invalid' === $temp['status'] ) {
					$error = json_decode( $backupTaskProgress->last_error );

					if ( ! is_array( $error ) ) {
						throw new MainWP_Exception( 'Backup failed.' );
					} else {
						throw new MainWP_Exception( $error['message'], $error['extra'] );
					}
				} elseif ( 'busy' === $temp['status'] ) {
					return false;
				} elseif ( 'done' === $temp['status'] ) {
					if ( 'full' === $type ) {
						$information['full'] = $temp['file'];
						$information['db']   = false;
					} else {
						$information['full'] = false;
						$information['db']   = $temp['file'];
					}

					$information['size'] = $temp['size'];

					$backupTaskProgress = MainWP_DB_Backup::instance()->update_backup_task_progress( $taskId, $website->id, array( 'fetchResult' => wp_json_encode( $information ) ) );
				}
			} else {
				if ( 5 > $backupTaskProgress->attempts ) {
					$backupTaskProgress = MainWP_DB_Backup::instance()->update_backup_task_progress( $taskId, $website->id, array( 'attempts' => $backupTaskProgress->attempts ++ ) );
				} else {
					throw new MainWP_Exception( 'Backup failed after 5 retries.' );
				}
			}
		}

		if ( false === $information ) {
			$information = $backupTaskProgress->fetchResult;
		}

		if ( isset( $information['error'] ) ) {
			throw new MainWP_Exception( $information['error'] );
		} elseif ( 'db' === $type && ! $information['db'] ) {
			throw new MainWP_Exception( 'Database backup failed.' );
		} elseif ( 'full' === $type && ! $information['full'] ) {
			throw new MainWP_Exception( 'Full backup failed.' );
		} elseif ( isset( $information['db'] ) ) {
			$hasWPFileSystem = MainWP_Utility::get_wp_file_system();
			global $wp_filesystem;

			$dir = MainWP_Utility::get_mainwp_specific_dir( $website->id );

			$wp_filesystem->mkdir( $dir, 0777, true );

			if ( ! $wp_filesystem->exists( $dir . 'index.php' ) ) {
				$wp_filesystem->touch( $dir . 'index.php' );
			}

			// Clean old backups from our system.
			$maxBackups = get_option( 'mainwp_backupsOnServer' );
			if ( false === $maxBackups ) {
				$maxBackups = 1;
			}

			if ( 1 !== $backupTaskProgress->removedFiles ) {
				$dbBackups   = array();
				$fullBackups = array();
				if ( $wp_filesystem->exists( $dir ) ) {
					$dh = opendir( $dir );
					if ( $dh ) {
						while ( false !== ( $file = readdir( $dh ) ) ) {
							if ( '.' !== $file && '..' !== $file ) {
								$theFile = $dir . $file;
								if ( $information['db'] && MainWP_Utility::is_sql_file( $file ) ) {
									$dbBackups[ filemtime( $theFile ) . $file ] = $theFile;
								}

								if ( $information['full'] && MainWP_Utility::is_archive( $file ) && ! MainWP_Utility::is_sql_archive( $file ) ) {
									$fullBackups[ filemtime( $theFile ) . $file ] = $theFile;
								}
							}
						}
						closedir( $dh );
					}
				}
				krsort( $dbBackups );
				krsort( $fullBackups );

				$cnt = 0;
				foreach ( $dbBackups as $key => $dbBackup ) {
					$cnt ++;
					if ( $cnt >= $maxBackups ) {
						$wp_filesystem->delete( $dbBackup );
					}
				}

				$cnt = 0;
				foreach ( $fullBackups as $key => $fullBackup ) {
					$cnt ++;
					if ( $cnt >= $maxBackups ) {
						$wp_filesystem->delete( $fullBackup );
					}
				}
				$backupTaskProgress = MainWP_DB_Backup::instance()->update_backup_task_progress( $taskId, $website->id, array( 'removedFiles' => 1 ) );
			}

			$localBackupFile = null;

			$fm_date = MainWP_Utility::sanitize_file_name( MainWP_Utility::date( get_option( 'date_format' ) ) );
			$fm_time = MainWP_Utility::sanitize_file_name( MainWP_Utility::date( get_option( 'time_format' ) ) );

			$what            = null;
			$regexBackupFile = null;

			if ( $information['db'] ) {
				$what            = 'db';
				$regexBackupFile = 'db-' . $websiteCleanUrl . '-(.*)-(.*).sql(\.zip|\.tar|\.tar\.gz|\.tar\.bz2)?';
				if ( '' == $backupTaskProgress->downloadedDB ) {
					$localBackupFile = $dir . 'db-' . $websiteCleanUrl . '-' . $fm_date . '-' . $fm_time;

					if ( null != $pFilename ) {
						$filename        = str_replace(
							array(
								'%sitename%',
								'%url%',
								'%date%',
								'%time%',
								'%type%',
							),
							array(
								MainWP_Utility::sanitize( $website->name ),
								$websiteCleanUrl,
								$fm_date,
								$fm_time,
								$what,
							),
							$pFilename
						);
						$filename        = str_replace( '%', '', $filename );
						$localBackupFile = $dir . $filename;
					}
					$localBackupFile .= MainWP_Utility::get_real_extension( $information['db'] );

					$localBackupFile = MainWP_Utility::normalize_filename( $localBackupFile );

					$backupTaskProgress = MainWP_DB_Backup::instance()->update_backup_task_progress( $taskId, $website->id, array( 'downloadedDB' => $localBackupFile ) );
				} else {
					$localBackupFile = $backupTaskProgress->downloadedDB;
				}

				if ( 0 == $backupTaskProgress->downloadedDBComplete ) {
					MainWP_Connect::download_to_file( MainWP_Connect::get_get_data_authed( $website, $information['db'], 'fdl' ), $localBackupFile, $information['size'], $website->http_user, $website->http_pass );
					$backupTaskProgress = MainWP_DB_Backup::instance()->update_backup_task_progress( $taskId, $website->id, array( 'downloadedDBComplete' => 1 ) );
				}
			}

			if ( $information['full'] ) {
				$realExt         = MainWP_Utility::get_real_extension( $information['full'] );
				$what            = 'full';
				$regexBackupFile = 'full-' . $websiteCleanUrl . '-(.*)-(.*).(zip|tar|tar.gz|tar.bz2)';
				if ( '' == $backupTaskProgress->downloadedFULL ) {
					$localBackupFile = $dir . 'full-' . $websiteCleanUrl . '-' . $fm_date . '-' . $fm_time . $realExt;

					if ( null != $pFilename ) {
						$filename        = str_replace(
							array(
								'%sitename%',
								'%url%',
								'%date%',
								'%time%',
								'%type%',
							),
							array(
								MainWP_Utility::sanitize( $website->name ),
								$websiteCleanUrl,
								$fm_date,
								$fm_time,
								$what,
							),
							$pFilename
						);
						$filename        = str_replace( '%', '', $filename );
						$localBackupFile = $dir . $filename . $realExt;
					}

					$localBackupFile = MainWP_Utility::normalize_filename( $localBackupFile );

					$backupTaskProgress = MainWP_DB_Backup::instance()->update_backup_task_progress( $taskId, $website->id, array( 'downloadedFULL' => $localBackupFile ) );
				} else {
					$localBackupFile = $backupTaskProgress->downloadedFULL;
				}

				if ( 0 === $backupTaskProgress->downloadedFULLComplete ) {
					if ( file_exists( $localBackupFile ) ) {
						$time = filemtime( $localBackupFile );

						$minutes = gmdate( 'i', time() );
						$seconds = gmdate( 's', time() );

						$file_minutes = gmdate( 'i', $time );
						$file_seconds = gmdate( 's', $time );

						$minuteDiff = $minutes - $file_minutes;
						if ( 59 === $minuteDiff ) {
							$minuteDiff = 1;
						}
						$secondsdiff = ( $minuteDiff * 60 ) + $seconds - $file_seconds;

						if ( 60 > $secondsdiff ) {
							// still downloading.
							return false;
						}
					}

					MainWP_Connect::download_to_file( MainWP_Connect::get_get_data_authed( $website, $information['full'], 'fdl' ), $localBackupFile, $information['size'], $website->http_user, $website->http_pass );
					MainWP_Connect::fetch_url_authed( $website, 'delete_backup', array( 'del' => $information['full'] ) );
					$backupTaskProgress = MainWP_DB_Backup::instance()->update_backup_task_progress( $taskId, $website->id, array( 'downloadedFULLComplete' => 1 ) );
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

	public static function backup_download_file( $pSiteId, $pType, $pUrl, $pFile ) {
		$hasWPFileSystem = MainWP_Utility::get_wp_file_system();
		global $wp_filesystem;

		$dir = dirname( $pFile ) . '/';
		$wp_filesystem->mkdir( $dir, 0777, true );
		if ( ! $wp_filesystem->exists( $dir . 'index.php' ) ) {
			$wp_filesystem->touch( $dir . 'index.php' );
		}
		// Clean old backups from our system.
		$maxBackups = get_option( 'mainwp_backupsOnServer' );
		if ( false === $maxBackups ) {
			$maxBackups = 1;
		}

		$dbBackups   = array();
		$fullBackups = array();

		if ( file_exists( $dir ) ) {
			$dh = opendir( $dir );
			if ( $dh ) {
				while ( false !== ( $file = readdir( $dh ) ) ) {
					if ( '.' !== $file && '..' !== $file ) {
						$theFile = $dir . $file;
						if ( 'db' === $pType && MainWP_Utility::is_sql_file( $file ) ) {
							$dbBackups[ filemtime( $theFile ) . $file ] = $theFile;
						}

						if ( 'full' === $pType && MainWP_Utility::is_archive( $file ) && ! MainWP_Utility::is_sql_archive( $file ) ) {
							$fullBackups[ filemtime( $theFile ) . $file ] = $theFile;
						}
					}
				}
				closedir( $dh );
			}
		}
		krsort( $dbBackups );
		krsort( $fullBackups );

		$cnt = 0;
		foreach ( $dbBackups as $key => $dbBackup ) {
			$cnt ++;
			if ( $cnt >= $maxBackups ) {
				$wp_filesystem->delete( $dbBackup );
			}
		}

		$cnt = 0;
		foreach ( $fullBackups as $key => $fullBackup ) {
			$cnt ++;
			if ( $cnt >= $maxBackups ) {
				$wp_filesystem->delete( $fullBackup );
			}
		}

		$website = MainWP_DB::instance()->get_website_by_id( $pSiteId );
		MainWP_Utility::end_session();

		$what = null;
		if ( 'db' === $pType ) {
			MainWP_Connect::download_to_file( MainWP_Connect::get_get_data_authed( $website, $pUrl, 'fdl' ), $pFile, false, $website->http_user, $website->http_pass );
		}

		if ( 'full' === $pType ) {
			MainWP_Connect::download_to_file( MainWP_Connect::get_get_data_authed( $website, $pUrl, 'fdl' ), $pFile, false, $website->http_user, $website->http_pass );
		}

		return true;
	}

	public static function backup_delete_file( $pSiteId, $pFile ) {
		$website = MainWP_DB::instance()->get_website_by_id( $pSiteId );
		MainWP_Connect::fetch_url_authed( $website, 'delete_backup', array( 'del' => $pFile ) );

		return true;
	}

	public static function backup_check_pid( $pSiteId, $pid, $type, $subfolder, $pFilename ) {
		$website = MainWP_DB::instance()->get_website_by_id( $pSiteId );

		MainWP_Utility::end_session();
		$information = MainWP_Connect::fetch_url_authed( $website, 'backup_checkpid', array( 'pid' => $pid ) );

		$status = $information['status'];

		$result = isset( $information['file'] ) ? array( 'file' => $information['file'] ) : array();
		if ( 'done' === $status ) {
			$result['file'] = $information['file'];
			$result['size'] = $information['size'];

			$subfolder = str_replace( '%sitename%', MainWP_Utility::sanitize( $website->name ), $subfolder );
			$subfolder = str_replace( '%url%', MainWP_Utility::sanitize( MainWP_Utility::get_nice_url( $website->url ) ), $subfolder );
			$subfolder = str_replace( '%type%', $type, $subfolder );
			$subfolder = str_replace( '%date%', MainWP_Utility::date( 'Ymd' ), $subfolder );
			$subfolder = str_replace( '%task%', '', $subfolder );
			$subfolder = str_replace( '%', '', $subfolder );
			$subfolder = MainWP_Utility::remove_preslash_spaces( $subfolder );
			$subfolder = MainWP_Utility::normalize_filename( $subfolder );

			$result['subfolder'] = $subfolder;

			$websiteCleanUrl = $website->url;
			if ( '/' === substr( $websiteCleanUrl, - 1 ) ) {
				$websiteCleanUrl = substr( $websiteCleanUrl, 0, - 1 );
			}
			$websiteCleanUrl = str_replace(
				array( 'http://', 'https://', '/' ),
				array(
					'',
					'',
					'-',
				),
				$websiteCleanUrl
			);

			$dir = MainWP_Utility::get_mainwp_specific_dir( $pSiteId );

			$fm_date = MainWP_Utility::sanitize_file_name( MainWP_Utility::date( get_option( 'date_format' ) ) );
			$fm_time = MainWP_Utility::sanitize_file_name( MainWP_Utility::date( get_option( 'time_format' ) ) );

			if ( 'db' === $type ) {
				$localBackupFile = $dir . 'db-' . $websiteCleanUrl . '-' . $fm_date . '-' . $fm_time . MainWP_Utility::get_real_extension( $information['file'] );
				$localRegexFile  = 'db-' . $websiteCleanUrl . '-(.*)-(.*).sql(\.zip|\.tar|\.tar\.gz|\.tar\.bz2)?';
			} else {
				$localBackupFile = $dir . 'full-' . $websiteCleanUrl . '-' . $fm_date . '-' . $fm_time . MainWP_Utility::get_real_extension( $information['file'] );
				$localRegexFile  = 'full-' . $websiteCleanUrl . '-(.*)-(.*).(zip|tar|tar.gz|tar.bz2)';
			}

			if ( null != $pFilename ) {
				$filename        = str_replace(
					array(
						'%sitename%',
						'%url%',
						'%date%',
						'%time%',
						'%type%',
					),
					array(
						MainWP_Utility::sanitize( $website->name ),
						$websiteCleanUrl,
						$fm_date,
						$fm_time,
						$type,
					),
					$pFilename
				);
				$filename        = str_replace( '%', '', $filename );
				$localBackupFile = $dir . $filename;
				$localBackupFile = MainWP_Utility::normalize_filename( $localBackupFile );

				if ( 'db' === $type ) {
					$localBackupFile .= MainWP_Utility::get_real_extension( $information['file'] );
				} else {
					$localBackupFile .= MainWP_Utility::get_real_extension( $information['file'] );
				}
			}

			$result['local']     = $localBackupFile;
			$result['regexfile'] = $localRegexFile;
		}

		return array(
			'status' => $status,
			'result' => $result,
		);
	}


	public static function backup( $pSiteId, $pType, $pSubfolder, $pExclude, $excludebackup, $excludecache, $excludenonwp, // phpcs:ignore -- complex method.
								$excludezip, $pFilename = null, $pFileNameUID = '', $pArchiveFormat = false,
								$pMaximumFileDescriptorsOverride = false, $pMaximumFileDescriptorsAuto = false,
								$pMaximumFileDescriptors = false, $pLoadFilesBeforeZip = false, $pid = false, $append = false ) {
		if ( ! get_option( 'mainwp_enableLegacyBackupFeature' ) ) {
			return false;
		}

		if ( '' == trim( $pFilename ) ) {
			$pFilename = null;
		}

		$backup_result = array();

		$website   = MainWP_DB::instance()->get_website_by_id( $pSiteId );
		$subfolder = str_replace( '%sitename%', MainWP_Utility::sanitize( $website->name ), $pSubfolder );
		$subfolder = str_replace( '%url%', MainWP_Utility::sanitize( MainWP_Utility::get_nice_url( $website->url ) ), $subfolder );
		$subfolder = str_replace( '%type%', $pType, $subfolder );
		$subfolder = str_replace( '%date%', MainWP_Utility::date( 'Ymd' ), $subfolder );
		$subfolder = str_replace( '%task%', '', $subfolder );
		$subfolder = str_replace( '%', '', $subfolder );
		$subfolder = MainWP_Utility::remove_preslash_spaces( $subfolder );
		$subfolder = MainWP_Utility::normalize_filename( $subfolder );

		if ( ! MainWP_Utility::can_edit_website( $website ) ) {
			throw new MainWP_Exception( 'You are not allowed to backup this site' );
		}

		$websiteCleanUrl = $website->url;
		if ( '/' === substr( $websiteCleanUrl, - 1 ) ) {
			$websiteCleanUrl = substr( $websiteCleanUrl, 0, - 1 );
		}
		$websiteCleanUrl = str_replace(
			array( 'http://', 'https://', '/' ),
			array( '', '', '-' ),
			$websiteCleanUrl
		);

		if ( false === $pMaximumFileDescriptorsOverride ) {
			if ( 1 === $website->maximumFileDescriptorsOverride ) {
				$maximumFileDescriptorsAuto = ( 1 === $website->maximumFileDescriptorsAuto );
				$maximumFileDescriptors     = $website->maximumFileDescriptors;
			} else {
				$maximumFileDescriptorsAuto = get_option( 'mainwp_maximumFileDescriptorsAuto' );
				$maximumFileDescriptors     = get_option( 'mainwp_maximumFileDescriptors' );
				$maximumFileDescriptors     = ( false === $maximumFileDescriptors ? 150 : $maximumFileDescriptors );
			}
		} elseif ( ( 'global' !== $pArchiveFormat ) && ( 1 === $pMaximumFileDescriptorsOverride ) ) {
			$maximumFileDescriptorsAuto = ( 1 === $pMaximumFileDescriptorsAuto );
			$maximumFileDescriptors     = $pMaximumFileDescriptors;
		} else {
			$maximumFileDescriptorsAuto = get_option( 'mainwp_maximumFileDescriptorsAuto' );
			$maximumFileDescriptors     = get_option( 'mainwp_maximumFileDescriptors' );
			$maximumFileDescriptors     = ( false === $maximumFileDescriptors ? 150 : $maximumFileDescriptors );
		}

		$file = str_replace(
			array(
				'%sitename%',
				'%url%',
				'%date%',
				'%time%',
				'%type%',
			),
			array(
				MainWP_Utility::sanitize( $website->name ),
				$websiteCleanUrl,
				MainWP_Utility::date( 'm-d-Y' ),
				MainWP_Utility::date( 'G\hi\ms\s' ),
				$pType,
			),
			$pFilename
		);
		$file = str_replace( '%', '', $file );
		$file = MainWP_Utility::normalize_filename( $file );

		// Normal flow: check site settings & fallback to global.
		if ( false === $pLoadFilesBeforeZip ) {
			$loadFilesBeforeZip = $website->loadFilesBeforeZip;
			if ( 1 === $loadFilesBeforeZip ) {
				$loadFilesBeforeZip = get_option( 'mainwp_options_loadFilesBeforeZip' );
				$loadFilesBeforeZip = ( 1 === $loadFilesBeforeZip || false === $loadFilesBeforeZip );
			} else {
				$loadFilesBeforeZip = ( 2 === $loadFilesBeforeZip );
			}
		} elseif ( 'global' === $pArchiveFormat || 1 === $pLoadFilesBeforeZip ) { // Overriden flow: only fallback to global.
			$loadFilesBeforeZip = get_option( 'mainwp_options_loadFilesBeforeZip' );
			$loadFilesBeforeZip = ( 1 === $loadFilesBeforeZip || false === $loadFilesBeforeZip );
		} else {
			$loadFilesBeforeZip = ( 2 === $pLoadFilesBeforeZip );
		}

		// Nomral flow: check site settings & fallback to global.
		if ( false === $pArchiveFormat ) {
			$archiveFormat = MainWP_Utility::get_current_archive_extension( $website );
		} elseif ( 'global' === $pArchiveFormat ) {
			$archiveFormat = MainWP_Utility::get_current_archive_extension();
		} else {
			$archiveFormat = $pArchiveFormat;
		}

		MainWP_Utility::end_session();

		$params = array(
			'type'                                         => $pType,
			'exclude'                                      => $pExclude,
			'excludebackup'                                => $excludebackup,
			'excludecache'                                 => $excludecache,
			'excludenonwp'                                 => $excludenonwp,
			'excludezip'                                   => $excludezip,
			'ext'                                          => $archiveFormat,
			'file_descriptors_auto'                        => $maximumFileDescriptorsAuto,
			'file_descriptors'                             => $maximumFileDescriptors,
			'loadFilesBeforeZip'                           => $loadFilesBeforeZip,
			'f'												=> $file,
			'fileUID'                                      => $pFileNameUID,
			'pid'                                          => $pid,
			'append'                                       => ( $append ? 1 : 0 ),
		);

		MainWP_Logger::instance()->debug_for_website( $website, 'backup', 'Requesting backup: ' . MainWP_Utility::value_to_string( $params, 1 ) );

		$information = MainWP_Connect::fetch_url_authed( $website, 'backup', $params, false, false, false );
		do_action( 'mainwp_managesite_backup', $website, array( 'type' => $pType ), $information );

		if ( isset( $information['error'] ) ) {
			throw new MainWP_Exception( $information['error'] );
		} elseif ( 'db' === $pType && ! $information['db'] ) {
			throw new MainWP_Exception( 'Database backup failed.' );
		} elseif ( 'full' === $pType && ! $information['full'] ) {
			throw new MainWP_Exception( 'Full backup failed.' );
		} elseif ( isset( $information['db'] ) ) {
			if ( false !== $information['db'] ) {
				$backup_result['url']  = $information['db'];
				$backup_result['type'] = 'db';
			} elseif ( false !== $information['full'] ) {
				$backup_result['url']  = $information['full'];
				$backup_result['type'] = 'full';
			}

			if ( isset( $information['size'] ) ) {
				$backup_result['size'] = $information['size'];
			}
			$backup_result['subfolder'] = $subfolder;

			$dir = MainWP_Utility::get_mainwp_specific_dir( $pSiteId );

			$fm_date = MainWP_Utility::sanitize_file_name( MainWP_Utility::date( get_option( 'date_format' ) ) );
			$fm_time = MainWP_Utility::sanitize_file_name( MainWP_Utility::date( get_option( 'time_format' ) ) );

			if ( 'db' === $pType ) {
				$localBackupFile = $dir . 'db-' . $websiteCleanUrl . '-' . $fm_date . '-' . $fm_time . MainWP_Utility::get_real_extension( $information['db'] );
				$localRegexFile  = 'db-' . $websiteCleanUrl . '-(.*)-(.*).sql(\.zip|\.tar|\.tar\.gz|\.tar\.bz2)?';
			} else {
				$localBackupFile = $dir . 'full-' . $websiteCleanUrl . '-' . $fm_date . '-' . $fm_time . MainWP_Utility::get_real_extension( $information['full'] );
				$localRegexFile  = 'full-' . $websiteCleanUrl . '-(.*)-(.*).(zip|tar|tar.gz|tar.bz2)';
			}

			if ( null != $pFilename ) {
				$filename        = str_replace(
					array(
						'%sitename%',
						'%url%',
						'%date%',
						'%time%',
						'%type%',
					),
					array(
						MainWP_Utility::sanitize( $website->name ),
						$websiteCleanUrl,
						$fm_date,
						$fm_time,
						$pType,
					),
					$pFilename
				);
				$filename        = str_replace( '%', '', $filename );
				$localBackupFile = $dir . $filename;
				$localBackupFile = MainWP_Utility::normalize_filename( $localBackupFile );

				if ( 'db' === $pType ) {
					$localBackupFile .= MainWP_Utility::get_real_extension( $information['db'] );
				} else {
					$localBackupFile .= MainWP_Utility::get_real_extension( $information['full'] );
				}
			}

			$backup_result['local']     = $localBackupFile;
			$backup_result['regexfile'] = $localRegexFile;

			return $backup_result;
		} else {
			throw new MainWP_Exception( 'Database backup failed due to an undefined error' );
		}
	}


	public static function check_site() {
		$website = MainWP_DB::instance()->get_websites_by_url( $_POST['url'] );
		$ret     = array();
		if ( MainWP_Utility::can_edit_website( $website ) ) {
			$ret['response'] = 'ERROR You already added your site to MainWP';
		} else {
			try {
				$verify_cert    = ( ! isset( $_POST['verify_certificate'] ) || ( empty( $_POST['verify_certificate'] ) && ( '0' !== $_POST['verify_certificate'] ) ) ? null : $_POST['verify_certificate'] );
				$force_use_ipv4 = ( ! isset( $_POST['force_use_ipv4'] ) || ( empty( $_POST['force_use_ipv4'] ) && ( '0' !== $_POST['force_use_ipv4'] ) ) ? null : $_POST['force_use_ipv4'] );
				$http_user      = ( isset( $_POST['http_user'] ) ? $_POST['http_user'] : '' );
				$http_pass      = ( isset( $_POST['http_pass'] ) ? $_POST['http_pass'] : '' );
				$information    = MainWP_Connect::fetch_url_not_authed( $_POST['url'], $_POST['admin'], 'stats', null, false, $verify_cert, $http_user, $http_pass, $sslVersion = 0, $others = array( 'force_use_ipv4' => $force_use_ipv4 ) ); // Fetch the stats with the given admin name.

				if ( isset( $information['wpversion'] ) ) {
					$ret['response'] = 'OK';
				} elseif ( isset( $information['error'] ) ) {
					$ret['response'] = 'ERROR ' . $information['error'];
				} else {
					$ret['response'] = 'ERROR';
				}
			} catch ( MainWP_Exception $e ) {
				$ret['response'] = $e->getMessage();
			}
		}
		$ret['check_me'] = ( isset( $_POST['check_me'] ) ? intval( $_POST['check_me'] ) : null );
		die( wp_json_encode( $ret ) );
	}

	public static function reconnect_site() {
		$siteId = $_POST['siteid'];

		try {
			if ( MainWP_Utility::ctype_digit( $siteId ) ) {
				$website = MainWP_DB::instance()->get_website_by_id( $siteId );
				MainWP_Manage_Sites_View::m_reconnect_site( $website );
			} else {
				throw new \Exception( __( 'Invalid request! Please try again. If the process keeps failing, please contact the MainWP support.', 'mainwp' ) );
			}
		} catch ( \Exception $e ) {
			die( 'ERROR ' . $e->getMessage() );
		}

		die( __( 'Site has been reconnected successfully!', 'mainwp' ) );
	}


	public static function add_site() {
		$ret     = array();
		$error   = '';
		$message = '';
		$site_id = 0;

		if ( isset( $_POST['managesites_add_wpurl'] ) && isset( $_POST['managesites_add_wpadmin'] ) ) {
			// Check if already in DB.
			$website                           = MainWP_DB::instance()->get_websites_by_url( $_POST['managesites_add_wpurl'] );
			list( $message, $error, $site_id ) = MainWP_Manage_Sites_View::add_site( $website );
		}

		$ret['add_me'] = ( isset( $_POST['add_me'] ) ? intval( $_POST['add_me'] ) : null );
		if ( '' !== $error ) {
			$ret['response'] = 'ERROR ' . $error;
			die( wp_json_encode( $ret ) );
		}
		$ret['response'] = $message;
		$ret['siteid']   = $site_id;

		if ( 1 === MainWP_DB::instance()->get_websites_count() ) {
			$ret['redirectUrl'] = admin_url( 'admin.php?page=managesites' );
		}

		die( wp_json_encode( $ret ) );
	}

	public static function apply_plugin_settings() {
		$site_id      = $_POST['siteId'];
		$ext_dir_slug = $_POST['ext_dir_slug'];
		if ( empty( $site_id ) ) {
			die( wp_json_encode( array( 'error' => __( 'Invalid site ID. Please try again.', 'mainwp' ) ) ) );
		}

		do_action( 'mainwp_applypluginsettings_' . $ext_dir_slug, $site_id );
		die( wp_json_encode( array( 'error' => __( 'Undefined error occurred. Please try again.', 'mainwp' ) ) ) );
	}

	public static function save_note() {
		if ( isset( $_POST['websiteid'] ) && MainWP_Utility::ctype_digit( $_POST['websiteid'] ) ) {
			$website = MainWP_DB::instance()->get_website_by_id( $_POST['websiteid'] );
			if ( MainWP_Utility::can_edit_website( $website ) ) {
				$note     = stripslashes( $_POST['note'] );
				$esc_note = MainWP_Utility::esc_content( $note );
				MainWP_DB_Common::instance()->update_note( $website->id, $esc_note );

				die( wp_json_encode( array( 'result' => 'SUCCESS' ) ) );
			} else {
				die( wp_json_encode( array( 'error' => __( 'Are you sure this is your website?', 'mainwp' ) ) ) );
			}
		}
		die( wp_json_encode( array( 'undefined_error' => true ) ) );
	}

	public static function remove_site() {
		if ( isset( $_POST['id'] ) && MainWP_Utility::ctype_digit( $_POST['id'] ) ) {
			$website = MainWP_DB::instance()->get_website_by_id( $_POST['id'] );
			if ( MainWP_Utility::can_edit_website( $website ) ) {
				$error = '';

				// deactive child plugin on live site only,
				// do not deactive child on staging site, it will deactive child plugin of source site.
				if ( ! $website->is_staging ) {
					try {
						$information = MainWP_Connect::fetch_url_authed( $website, 'deactivate' );
					} catch ( MainWP_Exception $e ) {
						$error = $e->getMessage();
					}
				} else {
					$information['removed'] = true;
				}

				// delete icon file.
				$favi = MainWP_DB::instance()->get_website_option( $website, 'favi_icon', '' );
				if ( ! empty( $favi ) && ( false !== strpos( $favi, 'favi-' . $website->id . '-' ) ) ) {

					$hasWPFileSystem = MainWP_Utility::get_wp_file_system();

					global $wp_filesystem;

					$dirs = MainWP_Utility::get_icons_dir();
					if ( $wp_filesystem->exists( $dirs[0] . $favi ) ) {
						$wp_filesystem->delete( $dirs[0] . $favi );
					}
				}

				// Remove from DB.
				MainWP_DB::instance()->remove_website( $website->id );
				do_action( 'mainwp_delete_site', $website );

				if ( 'NOMAINWP' === $error ) {
					$error = __( 'Be sure to deactivate the child plugin on the child site to avoid potential security issues.', 'mainwp' );
				}

				if ( '' !== $error ) {
					die( wp_json_encode( array( 'error' => $error ) ) );
				} elseif ( isset( $information['deactivated'] ) ) {
					die( wp_json_encode( array( 'result' => 'SUCCESS' ) ) );
				} elseif ( isset( $information['removed'] ) ) {
					die( wp_json_encode( array( 'result' => 'REMOVED' ) ) );
				} else {
					die( wp_json_encode( array( 'undefined_error' => true ) ) );
				}
			}
		}
		die( wp_json_encode( array( 'result' => 'NOSITE' ) ) );
	}

	public static function handle_settings_post() {
		if ( MainWP_Utility::is_admin() ) {
			if ( isset( $_POST['submit'] ) && wp_verify_nonce( $_POST['wp_nonce'], 'Settings' ) ) {
				if ( MainWP_Utility::ctype_digit( $_POST['mainwp_options_backupOnServer'] ) && 0 < $_POST['mainwp_options_backupOnServer'] ) {
					MainWP_Utility::update_option( 'mainwp_backupsOnServer', $_POST['mainwp_options_backupOnServer'] );
				}
				if ( MainWP_Utility::ctype_digit( $_POST['mainwp_options_maximumFileDescriptors'] ) && - 1 < $_POST['mainwp_options_maximumFileDescriptors'] ) {
					MainWP_Utility::update_option( 'mainwp_maximumFileDescriptors', $_POST['mainwp_options_maximumFileDescriptors'] );
				}
				MainWP_Utility::update_option( 'mainwp_maximumFileDescriptorsAuto', ( ! isset( $_POST['mainwp_maximumFileDescriptorsAuto'] ) ? 0 : 1 ) );
				if ( MainWP_Utility::ctype_digit( $_POST['mainwp_options_backupOnExternalSources'] ) && 0 <= $_POST['mainwp_options_backupOnExternalSources'] ) {
					MainWP_Utility::update_option( 'mainwp_backupOnExternalSources', $_POST['mainwp_options_backupOnExternalSources'] );
				}
				MainWP_Utility::update_option( 'mainwp_archiveFormat', $_POST['mainwp_archiveFormat'] );

				$old_primaryBackup          = get_option( 'mainwp_primaryBackup' );
				$old_enableLegacyBackup     = get_option( 'mainwp_enableLegacyBackupFeature' );
				$updated_enableLegacyBackup = false;

				if ( isset( $_POST['mainwp_primaryBackup'] ) ) {
					if ( ! empty( $_POST['mainwp_primaryBackup'] ) ) { // not default backup method.
						MainWP_Utility::update_option( 'mainwp_notificationOnBackupFail', 0 );
						MainWP_Utility::update_option( 'mainwp_notificationOnBackupStart', 0 );
						MainWP_Utility::update_option( 'mainwp_chunkedBackupTasks', 0 );
						if ( empty( $old_primaryBackup ) ) {
							MainWP_Utility::update_option( 'mainwp_enableLegacyBackupFeature', 0 );
							$updated_enableLegacyBackup = true;
						}
					}
					MainWP_Utility::update_option( 'mainwp_primaryBackup', $_POST['mainwp_primaryBackup'] );
				}

				if ( ! isset( $_POST['mainwp_primaryBackup'] ) || empty( $_POST['mainwp_primaryBackup'] ) ) {
					MainWP_Utility::update_option( 'mainwp_options_loadFilesBeforeZip', ( ! isset( $_POST['mainwp_options_loadFilesBeforeZip'] ) ? 0 : 1 ) );
					MainWP_Utility::update_option( 'mainwp_notificationOnBackupFail', ( ! isset( $_POST['mainwp_options_notificationOnBackupFail'] ) ? 0 : 1 ) );
					MainWP_Utility::update_option( 'mainwp_notificationOnBackupStart', ( ! isset( $_POST['mainwp_options_notificationOnBackupStart'] ) ? 0 : 1 ) );
					MainWP_Utility::update_option( 'mainwp_chunkedBackupTasks', ( ! isset( $_POST['mainwp_options_chunkedBackupTasks'] ) ? 0 : 1 ) );
				}

				$enableLegacyBackup = ( isset( $_POST['mainwp_options_enableLegacyBackupFeature'] ) && ! empty( $_POST['mainwp_options_enableLegacyBackupFeature'] ) ) ? 1 : 0;
				if ( $enableLegacyBackup && empty( $old_enableLegacyBackup ) ) {
					MainWP_Utility::update_option( 'mainwp_primaryBackup', '' );
				}

				if ( ! $updated_enableLegacyBackup ) {
					MainWP_Utility::update_option( 'mainwp_enableLegacyBackupFeature', $enableLegacyBackup );
				}
				return true;
			}
		}
		return false;
	}

	public static function update_child_site_value() {
		if ( isset( $_POST['site_id'] ) && MainWP_Utility::ctype_digit( $_POST['site_id'] ) ) {
			$website = MainWP_DB::instance()->get_website_by_id( $_POST['site_id'] );
			if ( MainWP_Utility::can_edit_website( $website ) ) {
				$error    = '';
				$uniqueId = isset( $_POST['unique_id'] ) ? $_POST['unique_id'] : '';
				try {
					$information = MainWP_Connect::fetch_url_authed( $website, 'update_values', array( 'uniqueId' => $uniqueId ) );
				} catch ( MainWP_Exception $e ) {
					$error = $e->getMessage();
				}

				if ( '' !== $error ) {
					die( wp_json_encode( array( 'error' => $error ) ) );
				} elseif ( isset( $information['result'] ) && ( 'ok' === $information['result'] ) ) {
					die( wp_json_encode( array( 'result' => 'SUCCESS' ) ) );
				} else {
					die( wp_json_encode( array( 'undefined_error' => true ) ) );
				}
			}
		}
		die( wp_json_encode( array( 'error' => 'NO_SIDE_ID' ) ) );
	}

}
