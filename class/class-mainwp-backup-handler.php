<?php
/**
 * MainWP Backup Handler.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Backup_Handler
 *
 * @package MainWP\Dashboard
 */
class MainWP_Backup_Handler { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Method get_class_name()
     *
     * Get Class Name.
     *
     * @return string __CLASS__
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    // phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber -- all throws are MainWP_Exception.
    /**
     * Method  backup_site()
     *
     * Backup Child Site.
     *
     * @param mixed $siteid Child Site ID.
     * @param mixed $pTask Task to perform.
     * @param mixed $subfolder Sub folder to place backup.
     *
     * @gobal object $wp_filesystem WordPress filesystem instance.
     *
     * @return mixed $backup_result
     * @throws MainWP_Exception Error message, including coded backup errors from the error code registry.
     *
     * @uses \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
     * @uses \MainWP\Dashboard\MainWP_Connect::download_to_file()
     * @uses \MainWP\Dashboard\MainWP_Connect::get_get_data_authed()
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::get_backup_task_progress()
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::add_backup_task_progress()
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::update_backup_task_progress()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses \MainWP\Dashboard\MainWP_Exception
     * @uses \MainWP\Dashboard\MainWP_Logger::debug_for_website()
     * @uses \MainWP\Dashboard\MainWP_Logger::warning_for_website()
     * @uses \MainWP\Dashboard\MainWP_System::is_single_user()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_wp_file_system()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_mainwp_specific_dir()
     * @uses \MainWP\Dashboard\MainWP_Utility::sanitize()
     * @uses \MainWP\Dashboard\MainWP_Utility::date()
     * @uses \MainWP\Dashboard\MainWP_Utility::remove_preslash_spaces()
     * @uses \MainWP\Dashboard\static::normalize_filename()
     * @uses \MainWP\Dashboard\MainWP_Utility::value_to_string()
     * @uses \MainWP\Dashboard\static::get_real_extension()
     */
    public static function backup_site( $siteid, $pTask, $subfolder ) {
        // phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber
        // phpcs: ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.
        if ( ! get_option( 'mainwp_enableLegacyBackupFeature' ) ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_LEGACY_DISABLED ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }

        static::validate_backup_environment();

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
        $subfolder = static::normalize_filename( $subfolder );

        if ( ! MainWP_System::instance()->is_single_user() && ( $userid !== $website->userid ) ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_NO_PERMISSION ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
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
            $ext = '.sql.' . static::get_current_archive_extension( $website, $pTask );
        } else {
            $ext = '.' . static::get_current_archive_extension( $website, $pTask );
        }

        $file = ( null === $pFilename ) ? '' : str_replace(
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
        $file = static::normalize_filename( $file );

        if ( ! empty( $file ) ) {
            $file .= $ext;
        }

        if ( 'zip' === $pTask->archiveFormat ) {
            $loadFilesBeforeZip = (int) $pTask->loadFilesBeforeZip;
        } elseif ( '' === $pTask->archiveFormat || 'site' === $pTask->archiveFormat ) {
            $loadFilesBeforeZip = (int) $website->loadFilesBeforeZip;
        } else {
            $loadFilesBeforeZip = 1;
        }

        if ( 1 === $loadFilesBeforeZip ) {
            $loadFilesBeforeZip = get_option( 'mainwp_options_loadFilesBeforeZip' );
            $loadFilesBeforeZip = ( 1 === (int) $loadFilesBeforeZip || false === $loadFilesBeforeZip );
        } else {
            $loadFilesBeforeZip = ( 2 === $loadFilesBeforeZip );
        }

        if ( ( 'zip' === $pTask->archiveFormat ) && ( 1 === (int) $pTask->maximumFileDescriptorsOverride ) ) {
            $maximumFileDescriptorsAuto = ( 1 === (int) $pTask->maximumFileDescriptorsAuto );
            $maximumFileDescriptors     = $pTask->maximumFileDescriptors;
        } elseif ( ( '' === $pTask->archiveFormat || 'site' === $pTask->archiveFormat ) && ( 1 === (int) $website->maximumFileDescriptorsOverride ) ) {
            $maximumFileDescriptorsAuto = ( 1 === (int) $website->maximumFileDescriptorsAuto );
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
                        'busy_since'             => 0,
                    )
                );

                $params = array(
                    'type'                  => $type,
                    'exclude'               => $exclude,
                    'excludebackup'         => $excludebackup,
                    'excludecache'          => $excludecache,
                    'excludenonwp'          => $excludenonwp,
                    'excludezip'            => $excludezip,
                    'ext'                   => static::get_current_archive_extension( $website, $pTask ),
                    'file_descriptors_auto' => $maximumFileDescriptorsAuto,
                    'file_descriptors'      => $maximumFileDescriptors,
                    'loadFilesBeforeZip'    => $loadFilesBeforeZip,
                    'pid'                   => $pid,
                    'f'                     => $file,
                );

                static::negotiate_backup_preflight( $website, static::get_current_archive_extension( $website, $pTask ) );

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
                                    'message' => $e->getMessage(),
                                    'extra'   => $e->get_message_extra(),
                                )
                            ),
                        )
                    );
                }

                throw $e;
            }

            if ( isset( $information['error'] ) && stristr( $information['error'], 'Another backup process is running' ) ) {
                throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
            }

            $backupTaskProgress = MainWP_DB_Backup::instance()->update_backup_task_progress( $taskId, $website->id, array( 'fetchResult' => wp_json_encode( $information ) ) );
        } elseif ( empty( $backupTaskProgress->fetchResult ) ) {
            $temp = false;
            try {
                $temp = MainWP_Connect::fetch_url_authed( $website, 'backup_checkpid', array( 'pid' => $backupTaskProgress->pid ) );
            } catch ( \Exception $e ) {
                // The failed status check consumes a retry attempt below instead of being ignored.
                MainWP_Logger::instance()->warning_for_website( $website, 'backup', 'Backup status check failed: ' . $e->getMessage() );
            }

            if ( ! empty( $temp ) ) {
                if ( 'stalled' === $temp['status'] ) {
                    if ( 5 > $backupTaskProgress->attempts ) {
                        $backupTaskProgress = MainWP_DB_Backup::instance()->update_backup_task_progress( $taskId, $website->id, array( 'attempts' => $backupTaskProgress->attempts + 1 ) );

                        // A failed retry propagates to the caller instead of being silently dropped.
                        $information = MainWP_Connect::fetch_url_authed(
                            $website,
                            'backup',
                            array(
                                'type'                  => $type,
                                'exclude'               => $exclude,
                                'excludebackup'         => $excludebackup,
                                'excludecache'          => $excludecache,
                                'excludenonwp'          => $excludenonwp,
                                'excludezip'            => $excludezip,
                                'ext'                   => static::get_current_archive_extension( $website, $pTask ),
                                'file_descriptors_auto' => $maximumFileDescriptorsAuto,
                                'file_descriptors'      => $maximumFileDescriptors,
                                'loadFilesBeforeZip'    => $loadFilesBeforeZip,
                                'pid'                   => $backupTaskProgress->pid,
                                'append'                => '1',
                                'f'                     => $temp['file'],
                            ),
                            false,
                            false,
                            false
                        );

                        if ( isset( $information['error'] ) && stristr( $information['error'], 'Another backup process is running' ) ) {
                            MainWP_DB_Backup::instance()->update_backup_task_progress( $taskId, $website->id, array( 'attempts' => ( $backupTaskProgress->attempts - 1 ) ) );

                            return false;
                        }

                        $backupTaskProgress = MainWP_DB_Backup::instance()->update_backup_task_progress( $taskId, $website->id, array( 'fetchResult' => wp_json_encode( $information ) ) );
                    } else {
                        throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_RETRY_EXHAUSTED ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
                    }
                } elseif ( 'invalid' === $temp['status'] ) {
                    $error = json_decode( $backupTaskProgress->last_error );

                    if ( ! is_array( $error ) ) {
                        throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_STALLED ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
                    } else {
                        throw new MainWP_Exception( $error['message'], $error['extra'] ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                    }
                } elseif ( 'busy' === $temp['status'] ) {
                    $busy_since = isset( $backupTaskProgress->busy_since ) ? (int) $backupTaskProgress->busy_since : 0;
                    if ( empty( $busy_since ) ) {
                        MainWP_DB_Backup::instance()->update_backup_task_progress( $taskId, $website->id, array( 'busy_since' => time() ) );
                        return false;
                    }
                    if ( ( time() - $busy_since ) > HOUR_IN_SECONDS ) {
                        // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal diagnostic context.
                        throw new MainWP_Exception(
                            esc_html__( 'The child site has reported the backup as busy for over an hour. The backup process likely stalled or was killed by the host. Check the child site error log and try again.', 'mainwp' ),
                            'busy since ' . gmdate( 'Y-m-d H:i:s', $busy_since ) . ' UTC',
                            MainWP_Backup_Error_Codes::ERR_STALLED
                        );
                        // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
                    }
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
            } elseif ( 5 > $backupTaskProgress->attempts ) {
                    $backupTaskProgress = MainWP_DB_Backup::instance()->update_backup_task_progress( $taskId, $website->id, array( 'attempts' => $backupTaskProgress->attempts + 1 ) );
            } else {
                throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_RETRY_EXHAUSTED ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
            }
        }

        if ( false === $information ) {
            $information = $backupTaskProgress->fetchResult;
        }

        if ( isset( $information['error'] ) ) {
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error_Codes::sanitize_message( $information['error'] ) ), null, isset( $information['error_code'] ) ? $information['error_code'] : '' ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        } elseif ( 'db' === $type && empty( $information['db'] ) ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_DB_QUERY_FAILED ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        } elseif ( 'full' === $type && empty( $information['full'] ) ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        } elseif ( isset( $information['db'] ) ) {
            $hasWPFileSystem = MainWP_System_Utility::get_wp_file_system();

            /**
             * WordPress filesystem instance.
             *
             * @global object
             */
            global $wp_filesystem;

            $dir = MainWP_System_Utility::get_mainwp_specific_dir( $website->id );

            $wp_filesystem->mkdir( $dir, 0750 ); // MWP-1558: tightened from 0777; per-site backup dumps.

            if ( ! $wp_filesystem->exists( $dir . 'index.php' ) ) {
                $wp_filesystem->touch( $dir . 'index.php' );
            }

            static::cleanup_stale_download_locks( $dir );

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
                                if ( $information['db'] && static::is_sql_file( $file ) ) {
                                    $dbBackups[ filemtime( $theFile ) . $file ] = $theFile;
                                }

                                if ( $information['full'] && static::is_archive( $file ) && ! static::is_sql_archive( $file ) ) {
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
                    ++$cnt;
                    if ( $cnt >= $maxBackups ) {
                        $wp_filesystem->delete( $dbBackup );
                    }
                }

                $cnt = 0;
                foreach ( $fullBackups as $key => $fullBackup ) {
                    ++$cnt;
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
                if ( '' === $backupTaskProgress->downloadedDB ) {
                    $localBackupFile = $dir . 'db-' . $websiteCleanUrl . '-' . $fm_date . '-' . $fm_time;

                    if ( null !== $pFilename ) {
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
                    $localBackupFile .= static::get_real_extension( $information['db'] );

                    $localBackupFile = static::normalize_filename( $localBackupFile );

                    $backupTaskProgress = MainWP_DB_Backup::instance()->update_backup_task_progress( $taskId, $website->id, array( 'downloadedDB' => $localBackupFile ) );
                } else {
                    $localBackupFile = $backupTaskProgress->downloadedDB;
                }

                if ( 0 === (int) $backupTaskProgress->downloadedDBComplete ) {
                    if ( ! static::acquire_download_lock( $localBackupFile, $website->id, $taskId ) ) {
                        throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING, 'download lock held for ' . basename( $localBackupFile ) ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
                    }
                    try {
                        // MWP-1548: decrypt at the boundary so download_to_file sees plaintext credentials.
                        $http_user_plain = MainWP_Credential_Storage::decrypt_credential( $website->http_user );
                        $http_pass_plain = MainWP_Credential_Storage::decrypt_credential( $website->http_pass );
                        MainWP_Connect::download_to_file( MainWP_Connect::get_get_data_authed( $website, $information['db'], 'fdl' ), $localBackupFile, $information['size'], $http_user_plain, $http_pass_plain );
                        static::validate_backup_file( $localBackupFile, $information['size'] );
                    } finally {
                        static::release_download_lock( $localBackupFile );
                    }
                    $backupTaskProgress = MainWP_DB_Backup::instance()->update_backup_task_progress( $taskId, $website->id, array( 'downloadedDBComplete' => 1 ) );
                }
            }

            if ( $information['full'] ) {
                $realExt         = static::get_real_extension( $information['full'] );
                $what            = 'full';
                $regexBackupFile = 'full-' . $websiteCleanUrl . '-(.*)-(.*).(zip|tar|tar.gz|tar.bz2)';
                if ( '' === $backupTaskProgress->downloadedFULL ) {
                    $localBackupFile = $dir . 'full-' . $websiteCleanUrl . '-' . $fm_date . '-' . $fm_time . $realExt;

                    if ( null !== $pFilename ) {
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

                    $localBackupFile = static::normalize_filename( $localBackupFile );

                    $backupTaskProgress = MainWP_DB_Backup::instance()->update_backup_task_progress( $taskId, $website->id, array( 'downloadedFULL' => $localBackupFile ) );
                } else {
                    $localBackupFile = $backupTaskProgress->downloadedFULL;
                }

                if ( 0 === (int) $backupTaskProgress->downloadedFULLComplete ) {
                    if ( ! static::acquire_download_lock( $localBackupFile, $website->id, $taskId ) ) {
                        throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING, 'download lock held for ' . basename( $localBackupFile ) ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
                    }
                    try {
                        // MWP-1548: decrypt at the boundary so download_to_file sees plaintext credentials.
                        $http_user_plain = MainWP_Credential_Storage::decrypt_credential( $website->http_user );
                        $http_pass_plain = MainWP_Credential_Storage::decrypt_credential( $website->http_pass );
                        MainWP_Connect::download_to_file( MainWP_Connect::get_get_data_authed( $website, $information['full'], 'fdl' ), $localBackupFile, $information['size'], $http_user_plain, $http_pass_plain );
                        static::validate_backup_file( $localBackupFile, $information['size'] );
                    } finally {
                        static::release_download_lock( $localBackupFile );
                    }
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
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_DB_DUMP_EMPTY ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }

        return $backup_result;
    }

    /**
     * Method backup_download_file()
     *
     * Download backup file.
     *
     * @param mixed $pSiteId Child Site ID.
     * @param mixed $pType   full|db Type of backup.
     * @param mixed $pUrl    Backup location.
     * @param mixed $pFile   Backup File.
     *
     * @return bool true|false
     * @throws MainWP_Exception Error message.
     *
     * @uses \MainWP\Dashboard\MainWP_Connect::get_get_data_authed()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses \MainWP\Dashboard\MainWP_Connect::download_to_file()
     * @uses \MainWP\Dashboard\MainWP_Utility::end_session()
     */
    public static function backup_download_file( $pSiteId, $pType, $pUrl, $pFile ) {
        $hasWPFileSystem = MainWP_System_Utility::get_wp_file_system();

        /**
         * WordPress filesystem instance.
         *
         * @global object
         */
        global $wp_filesystem;

        $dir = dirname( $pFile ) . '/';
        $wp_filesystem->mkdir( $dir, 0750 ); // MWP-1558: tightened from 0777; backup download destination.
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
                        if ( 'db' === $pType && static::is_sql_file( $file ) ) {
                            $dbBackups[ filemtime( $theFile ) . $file ] = $theFile;
                        }

                        if ( 'full' === $pType && static::is_archive( $file ) && ! static::is_sql_archive( $file ) ) {
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
            ++$cnt;
            if ( $cnt >= $maxBackups ) {
                $wp_filesystem->delete( $dbBackup );
            }
        }

        $cnt = 0;
        foreach ( $fullBackups as $key => $fullBackup ) {
            ++$cnt;
            if ( $cnt >= $maxBackups ) {
                $wp_filesystem->delete( $fullBackup );
            }
        }

        $website = MainWP_DB::instance()->get_website_by_id( $pSiteId );
        MainWP_Utility::end_session();

        // MWP-1548: decrypt once at function boundary; both branches use the same credentials.
        $http_user_plain = MainWP_Credential_Storage::decrypt_credential( $website->http_user );
        $http_pass_plain = MainWP_Credential_Storage::decrypt_credential( $website->http_pass );

        if ( 'db' === $pType || 'full' === $pType ) {
            MainWP_Connect::download_to_file( MainWP_Connect::get_get_data_authed( $website, $pUrl, 'fdl' ), $pFile, false, $http_user_plain, $http_pass_plain );
            static::validate_backup_file( $pFile, false );
        }

        return true;
    }

    /**
     * Method backup_delete_file().
     *
     * Delete backup file.
     *
     * @param mixed $pSiteId Child Site ID.
     * @param mixed $pFile File to delete.
     *
     * @return bool true
     *
     * @uses \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     */
    public static function backup_delete_file( $pSiteId, $pFile ) {
        $website = MainWP_DB::instance()->get_website_by_id( $pSiteId );
        MainWP_Connect::fetch_url_authed( $website, 'delete_backup', array( 'del' => $pFile ) );
        return true;
    }

    /**
     * Method backup_check_pid()
     *
     * Check backup pid.
     *
     * @param mixed $pSiteId   Child Site id.
     * @param mixed $pid       Backup pid.
     * @param mixed $type      full|db Type of backup.
     * @param mixed $subfolder Sub folder for backup.
     * @param mixed $pFilename Backups filename.
     *
     * @return array $status, $result.
     * @throws \MainWP_Exception Error message.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_mainwp_specific_dir()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses \MainWP\Dashboard\MainWP_Utility::end_session()
     * @uses \MainWP\Dashboard\MainWP_Utility::sanitize()
     * @uses \MainWP\Dashboard\MainWP_Utility::get_nice_url()
     * @uses \MainWP\Dashboard\MainWP_Utility::date()
     * @uses \MainWP\Dashboard\MainWP_Utility::remove_preslash_spaces()
     * @uses \MainWP\Dashboard\static::normalize_filename()
     * @uses \MainWP\Dashboard\MainWP_Utility::sanitize_file_name()
     * @uses \MainWP\Dashboard\static::get_real_extension()
     */
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
            $subfolder = static::normalize_filename( $subfolder );

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

            $dir = MainWP_System_Utility::get_mainwp_specific_dir( $pSiteId );

            $fm_date = MainWP_Utility::sanitize_file_name( MainWP_Utility::date( get_option( 'date_format' ) ) );
            $fm_time = MainWP_Utility::sanitize_file_name( MainWP_Utility::date( get_option( 'time_format' ) ) );

            if ( 'db' === $type ) {
                $localBackupFile = $dir . 'db-' . $websiteCleanUrl . '-' . $fm_date . '-' . $fm_time . static::get_real_extension( $information['file'] );
                $localRegexFile  = 'db-' . $websiteCleanUrl . '-(.*)-(.*).sql(\.zip|\.tar|\.tar\.gz|\.tar\.bz2)?';
            } else {
                $localBackupFile = $dir . 'full-' . $websiteCleanUrl . '-' . $fm_date . '-' . $fm_time . static::get_real_extension( $information['file'] );
                $localRegexFile  = 'full-' . $websiteCleanUrl . '-(.*)-(.*).(zip|tar|tar.gz|tar.bz2)';
            }

            if ( null !== $pFilename ) {
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
                $localBackupFile = static::normalize_filename( $localBackupFile );

                if ( 'db' === $type ) {
                    $localBackupFile .= static::get_real_extension( $information['file'] );
                } else {
                    $localBackupFile .= static::get_real_extension( $information['file'] );
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

    // phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber -- all throws are MainWP_Exception.
    /**
     * Method backup()
     *
     * Backup Child Site.
     *
     * @param mixed  $pSiteId                         Child Site ID.
     * @param mixed  $pType                           full|db Typ of backup.
     * @param mixed  $pSubfolder                      Sub folder to store backup.
     * @param mixed  $pExclude                        Exclusion list.
     * @param mixed  $excludebackup                   Exclude backup files.
     * @param mixed  $excludecache                    Exclude cache files.
     * @param mixed  $excludenonwp                    Exclude no WP Files.
     * @param mixed  $excludezip                      Exclude Zip files.
     * @param null   $pFilename                       Name of backup file.
     * @param string $pFileNameUID                    File name unique ID.
     * @param bool   $pArchiveFormat                  Archive format.
     * @param bool   $pMaximumFileDescriptorsOverride Override maximum file descriptors.
     * @param bool   $pMaximumFileDescriptorsAuto     Auto maximum file descriptors.
     * @param bool   $pMaximumFileDescriptors         Maximum file descriptors.
     * @param bool   $pLoadFilesBeforeZip             Load files before zip.
     * @param bool   $pid                             Backup pid.
     * @param bool   $append                          Append to backup.
     *
     * @return mixed $backup_result
     * @throws MainWP_Exception Error message, including coded backup errors from the error code registry.
     *
     * @uses  \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
     * @uses  \MainWP\Dashboard\MainWP_Exception
     * @uses  \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses  \MainWP\Dashboard\MainWP_Logger::debug_for_website()
     * @uses  \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
     * @uses  \MainWP\Dashboard\MainWP_System_Utility::get_mainwp_specific_dir()
     * @uses \MainWP\Dashboard\MainWP_Utility::sanitize()
     * @uses \MainWP\Dashboard\MainWP_Utility::date()
     * @uses \MainWP\Dashboard\MainWP_Utility::remove_preslash_spaces()
     * @uses \MainWP\Dashboard\static::normalize_filename()
     * @uses \MainWP\Dashboard\MainWP_Utility::sanitize_file_name()
     * @uses \MainWP\Dashboard\MainWP_Utility::end_session()
     * @uses \MainWP\Dashboard\MainWP_Utility::value_to_string()
     * @uses \MainWP\Dashboard\static::get_real_extension()
     */
    public static function backup( // phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber
        $pSiteId,
        $pType,
        $pSubfolder,
        $pExclude,
        $excludebackup,
        $excludecache,
        $excludenonwp,
        $excludezip,
        $pFilename = null,
        $pFileNameUID = '',
        $pArchiveFormat = false,
        $pMaximumFileDescriptorsOverride = false,
        $pMaximumFileDescriptorsAuto = false,
        $pMaximumFileDescriptors = false,
        $pLoadFilesBeforeZip = false,
        $pid = false,
        $append = false
    ) {

        if ( ! get_option( 'mainwp_enableLegacyBackupFeature' ) ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_LEGACY_DISABLED ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }

        static::validate_backup_environment();

        if ( '' === trim( $pFilename ) ) {
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
        $subfolder = static::normalize_filename( $subfolder );

        if ( MainWP_System_Utility::is_suspended_site( $website ) ) {
            throw new MainWP_Exception( 'Suspended site.', '', 'SUSPENDED_SITE' );
        }

        if ( ! MainWP_System_Utility::can_edit_website( $website ) ) {
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
            if ( 1 === (int) $website->maximumFileDescriptorsOverride ) {
                $maximumFileDescriptorsAuto = ( 1 === (int) $website->maximumFileDescriptorsAuto );
                $maximumFileDescriptors     = $website->maximumFileDescriptors;
            } else {
                $maximumFileDescriptorsAuto = get_option( 'mainwp_maximumFileDescriptorsAuto' );
                $maximumFileDescriptors     = get_option( 'mainwp_maximumFileDescriptors' );
                $maximumFileDescriptors     = ( false === $maximumFileDescriptors ? 150 : $maximumFileDescriptors );
            }
        } elseif ( ( 'global' !== $pArchiveFormat ) && ( 1 === (int) $pMaximumFileDescriptorsOverride ) ) {
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
        $file = static::normalize_filename( $file );

        // Normal flow: check site settings & fallback to global.
        if ( false === $pLoadFilesBeforeZip ) {
            $loadFilesBeforeZip = $website->loadFilesBeforeZip;
            if ( 1 === $loadFilesBeforeZip ) {
                $loadFilesBeforeZip = get_option( 'mainwp_options_loadFilesBeforeZip' );
                $loadFilesBeforeZip = ( 1 === $loadFilesBeforeZip || false === $loadFilesBeforeZip );
            } else {
                $loadFilesBeforeZip = ( 2 === $loadFilesBeforeZip );
            }
        } elseif ( 'global' === $pArchiveFormat || 1 === $pLoadFilesBeforeZip ) { // Overridden flow: only fallback to global.
            $loadFilesBeforeZip = get_option( 'mainwp_options_loadFilesBeforeZip' );
            $loadFilesBeforeZip = ( 1 === $loadFilesBeforeZip || false === $loadFilesBeforeZip );
        } else {
            $loadFilesBeforeZip = ( 2 === $pLoadFilesBeforeZip );
        }

        // Normal flow: check site settings & fallback to global.
        if ( false === $pArchiveFormat ) {
            $archiveFormat = static::get_current_archive_extension( $website );
        } elseif ( 'global' === $pArchiveFormat ) {
            $archiveFormat = static::get_current_archive_extension();
        } else {
            $archiveFormat = $pArchiveFormat;
        }

        MainWP_Utility::end_session();

        $params = array(
            'type'                  => $pType,
            'exclude'               => $pExclude,
            'excludebackup'         => $excludebackup,
            'excludecache'          => $excludecache,
            'excludenonwp'          => $excludenonwp,
            'excludezip'            => $excludezip,
            'ext'                   => $archiveFormat,
            'file_descriptors_auto' => $maximumFileDescriptorsAuto,
            'file_descriptors'      => $maximumFileDescriptors,
            'loadFilesBeforeZip'    => $loadFilesBeforeZip,
            'f'                     => $file,
            'fileUID'               => $pFileNameUID,
            'pid'                   => $pid,
            'append'                => ( $append ? 1 : 0 ),
        );

        static::negotiate_backup_preflight( $website, $archiveFormat );

        MainWP_Logger::instance()->debug_for_website( $website, 'backup', 'Requesting backup: ' . MainWP_Utility::value_to_string( $params, 1 ) );

        $information = MainWP_Connect::fetch_url_authed( $website, 'backup', $params, false, false, false );
        do_action( 'mainwp_managesite_backup', $website, array( 'type' => $pType ), $information );

        if ( isset( $information['error'] ) ) {
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error_Codes::sanitize_message( $information['error'] ) ), null, isset( $information['error_code'] ) ? $information['error_code'] : '' ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        } elseif ( 'db' === $pType && ! $information['db'] ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_DB_QUERY_FAILED ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        } elseif ( 'full' === $pType && ! $information['full'] ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        } elseif ( isset( $information['db'] ) || isset( $information['full'] ) ) {
            if ( ! empty( $information['db'] ) ) {
                $backup_result['url']  = $information['db'];
                $backup_result['type'] = 'db';
            } elseif ( ! empty( $information['full'] ) ) {
                $backup_result['url']  = $information['full'];
                $backup_result['type'] = 'full';
            }

            if ( isset( $information['size'] ) ) {
                $backup_result['size'] = $information['size'];
            }
            $backup_result['subfolder'] = $subfolder;

            $dir = MainWP_System_Utility::get_mainwp_specific_dir( $pSiteId );

            $fm_date = MainWP_Utility::sanitize_file_name( MainWP_Utility::date( get_option( 'date_format' ) ) );
            $fm_time = MainWP_Utility::sanitize_file_name( MainWP_Utility::date( get_option( 'time_format' ) ) );

            if ( 'db' === $pType ) {
                $localBackupFile = $dir . 'db-' . $websiteCleanUrl . '-' . $fm_date . '-' . $fm_time . static::get_real_extension( $information['db'] );
                $localRegexFile  = 'db-' . $websiteCleanUrl . '-(.*)-(.*).sql(\.zip|\.tar|\.tar\.gz|\.tar\.bz2)?';
            } else {
                $localBackupFile = $dir . 'full-' . $websiteCleanUrl . '-' . $fm_date . '-' . $fm_time . static::get_real_extension( $information['full'] );
                $localRegexFile  = 'full-' . $websiteCleanUrl . '-(.*)-(.*).(zip|tar|tar.gz|tar.bz2)';
            }

            if ( null !== $pFilename ) {
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
                $localBackupFile = static::normalize_filename( $localBackupFile );

                if ( 'db' === $pType ) {
                    $localBackupFile .= static::get_real_extension( $information['db'] );
                } else {
                    $localBackupFile .= static::get_real_extension( $information['full'] );
                }
            }

            $backup_result['local']     = $localBackupFile;
            $backup_result['regexfile'] = $localRegexFile;

            return $backup_result;
        } else {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_DB_DUMP_EMPTY ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }
    }

    /**
     * Method normalize_filename()
     *
     * Normalize filename.
     *
     * @param mixed $s Filename to normalize.
     *
     * @return string $s Normalised filename.
     */
    public static function normalize_filename( $s ) {
        $s = preg_replace( '@\x{00c4}@u', 'A', $s );
        $s = preg_replace( '@\x{00d6}@u', 'O', $s );
        $s = preg_replace( '@\x{00dc}@u', 'U', $s );
        $s = preg_replace( '@\x{00cb}@u', 'E', $s );
        $s = preg_replace( '@\x{00e4}@u', 'a', $s );
        $s = preg_replace( '@\x{00f6}@u', 'o', $s );
        $s = preg_replace( '@\x{00fc}@u', 'u', $s );
        $s = preg_replace( '@\x{00eb}@u', 'e', $s );
        $s = preg_replace( '@\x{00f1}@u', 'n', $s );
        $s = preg_replace( '@\x{00ff}@u', 'y', $s );
        return $s;
    }

    /**
     * Method is_archive()
     *
     * Check if Archive.
     *
     * @param mixed  $pFileName File to check.
     * @param string $pPrefix File prefix.
     * @param string $pSuffix File suffix.
     *
     * @return mixed Files that are archives.
     */
    public static function is_archive( $pFileName, $pPrefix = '', $pSuffix = '' ) {
        return preg_match( '/' . $pPrefix . '(.*).(zip|tar|tar.gz|tar.bz2)' . $pSuffix . '$/', $pFileName );
    }

    /**
     * Method is_sql_file()
     *
     * Check if file is SQL.
     *
     * @param mixed $pFileName File to check.
     *
     * @return mixed SQL Files.
     */
    public static function is_sql_file( $pFileName ) {
        return preg_match( '/(.*).sql$/', $pFileName ) || static::is_sql_archive( $pFileName );
    }

    /**
     * Method is_sql_archive()
     *
     * Check if is SQL Archive.
     *
     * @param mixed $pFileName File to check.
     *
     * @return mixed SQL archive.
     */
    public static function is_sql_archive( $pFileName ) {
        return preg_match( '/(.*).sql.(zip|tar|tar.gz|tar.bz2)$/', $pFileName );
    }

    /**
     * Method get_current_archive_extension()
     *
     * Get extension of current Archive.
     *
     * @param bool        $website true|false.
     * @param bool|string $task true|false|global|site.
     *
     * @return mixed $archiveFormat Format of Archive.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Backup::get_website_backup_settings()
     */
    public static function get_current_archive_extension( $website = false, $task = false ) {
        $useSite = true;
        if ( false !== $task ) {
            if ( 'global' === $task->archiveFormat ) {
                $useGlobal = true;
                $useSite   = false;
            } elseif ( '' === $task->archiveFormat || 'site' === $task->archiveFormat ) {
                $useGlobal = false;
                $useSite   = true;
            } else {
                $archiveFormat = $task->archiveFormat;
                $useGlobal     = false;
                $useSite       = false;
            }
        }

        if ( $useSite ) {
            if ( false === $website ) {
                $useGlobal = true;
            } else {
                $backupSettings = MainWP_DB_Backup::instance()->get_website_backup_settings( $website->id );
                $archiveFormat  = $backupSettings->archiveFormat;
                $useGlobal      = ( 'global' === $archiveFormat );
            }
        }

        if ( $useGlobal ) {
            $archiveFormat = get_option( 'mainwp_archiveFormat' );
            if ( false === $archiveFormat ) {
                $archiveFormat = 'tar.gz';
            }
        }

        return $archiveFormat;
    }

    /**
     * Method get_real_extension()
     *
     * Get full file extension.
     *
     * @param mixed $path Path to file.
     *
     * @return mixed $check|pathinfo()
     */
    public static function get_real_extension( $path ) {
        $checks = array( '.sql.zip', '.sql.tar', '.sql.tar.gz', '.sql.tar.bz2', '.tar.gz', '.tar.bz2' );
        foreach ( $checks as $check ) {
            if ( MainWP_Utility::ends_with( $path, $check ) ) {
                return $check;
            }
        }

        return '.' . pathinfo( $path, PATHINFO_EXTENSION );
    }

    /**
     * Method format_backup_errors()
     *
     * Format accumulated backup error entries for storage and email.
     *
     * Sanitizes each entry (absolute paths, SQL fragments, table names),
     * keeps the first errors of the run (these typically reveal the root
     * cause while later errors are cascading failures), and renders them
     * as a numbered list.
     *
     * @param array $entries Error entries, one per failed operation.
     * @param int   $max     Maximum entries to keep (default 10).
     *
     * @return string Formatted HTML, empty when there are no entries.
     */
    public static function format_backup_errors( $entries, $max = 10 ) {
        if ( ! is_array( $entries ) || empty( $entries ) ) {
            return '';
        }

        $total   = count( $entries );
        $entries = array_slice( $entries, 0, $max );

        $output = '<ol>';
        foreach ( $entries as $entry ) {
            $output .= '<li>' . MainWP_Backup_Error_Codes::sanitize_message( (string) $entry ) . '</li>';
        }
        $output .= '</ol>';

        if ( $total > $max ) {
            $output .= sprintf(
                /* translators: %d: number of additional errors not shown */
                esc_html__( '... and %d more errors', 'mainwp' ),
                $total - $max
            );
        }

        return $output;
    }

    /**
     * Method negotiate_backup_preflight()
     *
     * Ask the child site for its backup readiness report before starting
     * a backup. Old child sites without the preflight action are detected
     * by their error response and the backup proceeds without preflight.
     *
     * Stores the child backup directory hash in site metadata for later
     * operations (sensitive value: stored only, never rendered), blocks
     * the backup when the child disk space is critically low, and logs
     * advisory warnings for the rest of the report.
     *
     * @param object $website     Website object.
     * @param string $archive_ext Requested archive extension (zip, tar.gz, tar.bz2, tar).
     *
     * @return void
     * @throws MainWP_Exception When the child reports critically low disk space.
     */
    public static function negotiate_backup_preflight( &$website, $archive_ext = '' ) { // phpcs:ignore -- NOSONAR - complex.
        try {
            $preflight = MainWP_Connect::fetch_with_retry( $website, 'backup_preflight', array() );
        } catch ( \Exception $e ) {
            MainWP_Logger::instance()->warning_for_website( $website, 'backup', 'Backup preflight unavailable: ' . $e->getMessage() );
            return; // Capability unknown - proceed for backwards compatibility.
        }

        if ( ! is_array( $preflight ) || isset( $preflight['error'] ) ) {
            // Old child site without the preflight action - proceed without it.
            MainWP_Logger::instance()->info_for_website( $website, 'backup', 'Backup preflight not supported by the child site; proceeding without it.' );
            return;
        }

        if ( isset( $preflight['disk_free'] ) && false !== $preflight['disk_free'] && is_numeric( $preflight['disk_free'] ) && (int) $preflight['disk_free'] < 50 * MB_IN_BYTES ) {
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message parts are escaped above.
            throw new MainWP_Exception(
                sprintf(
                    /* translators: %s: available disk space */
                    esc_html__( 'The child site has insufficient disk space for a backup: %s available. Free up disk space on the child site and try again.', 'mainwp' ),
                    esc_html( size_format( (int) $preflight['disk_free'] ) )
                ),
                null,
                MainWP_Backup_Error_Codes::ERR_DISK_FULL
            );
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        if ( isset( $preflight['time_limit_ok'] ) && ! $preflight['time_limit_ok'] ) {
            do_action( 'mainwp_log_warning', 'Child site cannot call set_time_limit(). Large backups may time out.' );
        }

        if ( ! empty( $preflight['disk_warning'] ) && is_string( $preflight['disk_warning'] ) ) {
            do_action( 'mainwp_log_warning', 'Child site disk warning: ' . MainWP_Backup_Error_Codes::sanitize_message( $preflight['disk_warning'] ) );
        }

        $format_support = array(
            'zip'     => 'zip_support',
            'tar.gz'  => 'gz_support',
            'tar.bz2' => 'bz2_support',
        );
        if ( isset( $format_support[ $archive_ext ] ) && isset( $preflight[ $format_support[ $archive_ext ] ] ) && ! $preflight[ $format_support[ $archive_ext ] ] ) {
            do_action( 'mainwp_log_warning', sprintf( 'Child site reports no support for the requested %s archive format.', $archive_ext ) );
        }

        if ( ! empty( $preflight['backup_dir_hash'] ) && is_string( $preflight['backup_dir_hash'] ) ) {
            $hash = preg_replace( '/[^a-zA-Z0-9]/', '', $preflight['backup_dir_hash'] );
            if ( '' !== $hash ) {
                MainWP_DB::instance()->update_website_option( $website, 'backup_dir_hash', $hash );
            }
        }
    }

    /**
     * Method get_max_concurrent_backups()
     *
     * Get the configured maximum number of concurrent backup tasks.
     *
     * @return int Maximum concurrent backups (default 2, minimum 1).
     */
    public static function get_max_concurrent_backups() {
        return max( 1, (int) get_option( 'mainwp_backup_max_concurrent', 2 ) );
    }

    /**
     * Method acquire_backup_slot()
     *
     * Acquire a backup execution slot from the transient-based semaphore.
     * Slots from crashed processes expire after two hours so a crash never
     * blocks backups permanently.
     *
     * The transient read-modify-write is best effort: under an external
     * object cache two requests can race within milliseconds of each other
     * and briefly exceed the limit by one. That is acceptable here because
     * the slot cap protects dashboard resources only; each child site
     * enforces its own single-backup rule.
     *
     * @param int $max_concurrent Maximum concurrent backup tasks (default 2).
     *
     * @return string|false Slot token on success, false when all slots are in use.
     */
    public static function acquire_backup_slot( $max_concurrent = 2 ) {
        $lock_key = 'mainwp_backup_slots';
        $slots    = get_transient( $lock_key );

        if ( ! is_array( $slots ) ) {
            $slots = array();
        }

        $now   = time();
        $slots = array_filter(
            $slots,
            function ( $slot ) use ( $now ) {
                return is_array( $slot ) && isset( $slot['started'] ) && ( $now - (int) $slot['started'] ) < 2 * HOUR_IN_SECONDS;
            }
        );

        if ( count( $slots ) >= $max_concurrent ) {
            set_transient( $lock_key, $slots, 2 * HOUR_IN_SECONDS );
            return false;
        }

        $token           = wp_generate_password( 12, false );
        $slots[ $token ] = array(
            'started' => $now,
            'pid'     => getmypid(),
        );

        set_transient( $lock_key, $slots, 2 * HOUR_IN_SECONDS );

        return $token;
    }

    /**
     * Method release_backup_slot()
     *
     * Release a backup execution slot.
     *
     * @param string $token Slot token from acquire_backup_slot().
     *
     * @return void
     */
    public static function release_backup_slot( $token ) {
        $lock_key = 'mainwp_backup_slots';
        $slots    = get_transient( $lock_key );

        if ( is_array( $slots ) && isset( $slots[ $token ] ) ) {
            unset( $slots[ $token ] );
            set_transient( $lock_key, $slots, 2 * HOUR_IN_SECONDS );
        }
    }

    /**
     * Method validate_backup_environment()
     *
     * Validates the dashboard server can receive and store a backup before
     * any child site communication: required PHP extensions and a writable
     * backup directory are hard requirements; low disk space and a low
     * memory limit are logged as warnings because backups may still
     * succeed.
     *
     * @return void
     * @throws MainWP_Exception When environment requirements are not met.
     */
    public static function validate_backup_environment() {
        // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages are pre-escaped.
        $required_extensions = array( 'curl', 'openssl' );
        foreach ( $required_extensions as $ext ) {
            if ( ! extension_loaded( $ext ) ) {
                throw new MainWP_Exception(
                    sprintf(
                        /* translators: %s: PHP extension name */
                        esc_html__( 'The %s PHP extension is required for backups but is not installed. Contact your hosting provider.', 'mainwp' ),
                        esc_html( $ext )
                    ),
                    null,
                    MainWP_Backup_Error_Codes::ERR_MISSING_EXTENSION
                );
            }
        }

        $backup_dir = MainWP_System_Utility::get_mainwp_dir( 'backup' );
        if ( is_array( $backup_dir ) ) {
            $backup_dir = $backup_dir[0];
        }
        if ( ! is_dir( $backup_dir ) && ! wp_mkdir_p( $backup_dir ) ) {
            throw new MainWP_Exception(
                esc_html__( 'Cannot create the dashboard backup directory. Check file permissions on the uploads folder.', 'mainwp' ),
                $backup_dir,
                MainWP_Backup_Error_Codes::ERR_PERMISSIONS
            );
        }
        if ( ! wp_is_writable( $backup_dir ) ) {
            throw new MainWP_Exception(
                esc_html__( 'The dashboard backup directory is not writable. Set permissions to 755 or contact your hosting provider.', 'mainwp' ),
                $backup_dir,
                MainWP_Backup_Error_Codes::ERR_DIR_NOT_WRITABLE
            );
        }
        // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

        // Disk space is advisory: estimates are unreliable, so warn without blocking.
        if ( function_exists( 'disk_free_space' ) ) {
            $free_bytes = @disk_free_space( $backup_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- may be disabled by the host.
            if ( false !== $free_bytes && $free_bytes < 500 * MB_IN_BYTES ) {
                do_action(
                    'mainwp_log_warning',
                    sprintf( 'Low disk space on dashboard server: %s available. Large backups may fail.', size_format( $free_bytes ) )
                );
            }
        }

        // Memory limit is advisory: some hosts cap ini_set, small sites still work.
        $memory_limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
        if ( $memory_limit > 0 && $memory_limit < 128 * MB_IN_BYTES ) {
            do_action(
                'mainwp_log_warning',
                sprintf( 'Low memory limit for backup: %s. Recommended: 256M+.', ini_get( 'memory_limit' ) )
            );
        }
    }

    /**
     * Method acquire_download_lock()
     *
     * Acquire the download lock for a backup file. The lock is a sidecar
     * file with a one hour TTL; a stale or corrupt lock is replaced. TTL
     * expiry alone covers crash recovery on every platform, so no process
     * checks are used.
     *
     * @param string $local_file Absolute path of the download target.
     * @param int    $site_id    Child site ID.
     * @param int    $task_id    Backup task ID.
     *
     * @return bool True when the lock was acquired, false when another process holds a live lock.
     */
    public static function acquire_download_lock( $local_file, $site_id, $task_id ) {
        $lock_file = $local_file . '.downloading';

        if ( file_exists( $lock_file ) ) {
            $data  = json_decode( (string) file_get_contents( $lock_file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local lock file.
            $stale = ! is_array( $data ) || ( time() - (int) ( isset( $data['started'] ) ? $data['started'] : 0 ) ) > HOUR_IN_SECONDS;
            if ( ! $stale ) {
                return false;
            }
            wp_delete_file( $lock_file );
        }

        $payload = wp_json_encode(
            array(
                'started' => time(),
                'site_id' => (int) $site_id,
                'task_id' => (int) $task_id,
            )
        );

        return false !== file_put_contents( $lock_file, $payload ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local lock file.
    }

    /**
     * Method release_download_lock()
     *
     * Release the download lock for a backup file.
     *
     * @param string $local_file Absolute path of the download target.
     *
     * @return void
     */
    public static function release_download_lock( $local_file ) {
        $lock_file = $local_file . '.downloading';
        if ( file_exists( $lock_file ) ) {
            wp_delete_file( $lock_file );
        }
    }

    /**
     * Method cleanup_stale_download_locks()
     *
     * Remove stale download lock files left behind by crashed processes.
     * Runs at backup start so an interrupted run never blocks future runs
     * for longer than the lock TTL.
     *
     * @param string $dir Directory holding backup files.
     *
     * @return void
     */
    public static function cleanup_stale_download_locks( $dir ) {
        $locks = glob( rtrim( $dir, '/' ) . '/*.downloading' );
        if ( ! is_array( $locks ) ) {
            return;
        }
        foreach ( $locks as $lock_file ) {
            $data  = json_decode( (string) file_get_contents( $lock_file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local lock file.
            $stale = ! is_array( $data ) || ( time() - (int) ( isset( $data['started'] ) ? $data['started'] : 0 ) ) > HOUR_IN_SECONDS;
            if ( $stale ) {
                wp_delete_file( $lock_file );
            }
        }
    }

    /**
     * Method validate_backup_file()
     *
     * Validate a downloaded backup file before it is marked complete:
     * the file must exist with content, match the expected size within
     * the tolerance, and start with the archive signature matching its
     * extension. This catches error pages and truncated transfers saved
     * as backup files.
     *
     * @param string    $file          Absolute path of the downloaded file.
     * @param int|false $expected_size Expected size in bytes, false to skip the size check.
     * @param float     $tolerance     Allowed fractional size deviation (default 1%).
     *
     * @return void
     * @throws MainWP_Exception When validation fails.
     */
    public static function validate_backup_file( $file, $expected_size = false, $tolerance = 0.01 ) { // phpcs:ignore -- NOSONAR - complex.
        // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.WP.AlternativeFunctions -- registry messages are pre-escaped; streaming file reads.
        if ( ! file_exists( $file ) || ! filesize( $file ) ) {
            throw new MainWP_Exception(
                MainWP_Backup_Error_Codes::message( MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT ),
                'missing or empty file',
                MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT
            );
        }

        if ( false !== $expected_size && is_numeric( $expected_size ) && (int) $expected_size > 0 ) {
            $actual    = (int) filesize( $file );
            $expected  = (int) $expected_size;
            $allowance = (int) max( 1, floor( $expected * $tolerance ) );
            if ( abs( $actual - $expected ) > $allowance ) {
                $code = $actual < $expected ? MainWP_Backup_Error_Codes::ERR_FILE_TOO_SMALL : MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT;
                throw new MainWP_Exception(
                    MainWP_Backup_Error_Codes::message( $code ),
                    'expected ' . $expected . ' bytes, got ' . $actual,
                    $code
                );
            }
        }

        $fp = fopen( $file, 'rb' );
        if ( false === $fp ) {
            throw new MainWP_Exception(
                MainWP_Backup_Error_Codes::message( MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT ),
                'could not open file for validation',
                MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT
            );
        }
        $head = (string) fread( $fp, 512 );
        fclose( $fp );

        $valid = true;
        if ( MainWP_Utility::ends_with( $file, '.zip' ) ) {
            $valid = "PK\x03\x04" === substr( $head, 0, 4 );
        } elseif ( MainWP_Utility::ends_with( $file, '.gz' ) ) {
            $valid = "\x1f\x8b" === substr( $head, 0, 2 );
        } elseif ( MainWP_Utility::ends_with( $file, '.bz2' ) ) {
            $valid = 'BZh' === substr( $head, 0, 3 );
        } elseif ( MainWP_Utility::ends_with( $file, '.tar' ) ) {
            $valid = strlen( $head ) >= 262 && 'ustar' === substr( $head, 257, 5 );
        } elseif ( MainWP_Utility::ends_with( $file, '.sql' ) ) {
            // SQL dumps must not start with markup - that's an error page.
            $valid = '<' !== substr( ltrim( $head ), 0, 1 );
        }

        if ( ! $valid ) {
            throw new MainWP_Exception(
                MainWP_Backup_Error_Codes::message( MainWP_Backup_Error_Codes::ERR_HEADER_INVALID ),
                'unexpected file signature for ' . basename( $file ),
                MainWP_Backup_Error_Codes::ERR_HEADER_INVALID
            );
        }
        // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.WP.AlternativeFunctions
    }

    /**
     * Method handle_settings_post()
     *
     * Update Child Site Settings.
     *
     * @return bool true|false
     *
     * @uses \MainWP\Dashboard\MainWP_System_Utility::is_admin()
     * @uses \MainWP\Dashboard\MainWP_Utility::update_option()
     */
    public static function handle_settings_post() {
        if ( MainWP_System_Utility::is_admin() ) {
            if ( isset( $_POST['submit'] ) && isset( $_POST['wp_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'Settings' ) ) {
                if ( isset( $_POST['mainwp_options_backupOnServer'] ) && 0 < $_POST['mainwp_options_backupOnServer'] ) {
                    MainWP_Utility::update_option( 'mainwp_backupsOnServer', ( isset( $_POST['mainwp_options_backupOnServer'] ) ? intval( $_POST['mainwp_options_backupOnServer'] ) : 1 ) );
                }
                if ( isset( $_POST['mainwp_options_maximumFileDescriptors'] ) && - 1 < $_POST['mainwp_options_maximumFileDescriptors'] ) {
                    MainWP_Utility::update_option( 'mainwp_maximumFileDescriptors', ( isset( $_POST['mainwp_options_maximumFileDescriptors'] ) ? intval( $_POST['mainwp_options_maximumFileDescriptors'] ) : 1 ) );
                }
                MainWP_Utility::update_option( 'mainwp_maximumFileDescriptorsAuto', ( ! isset( $_POST['mainwp_maximumFileDescriptorsAuto'] ) ? 0 : 1 ) );
                if ( ! empty( $_POST['mainwp_options_backupOnExternalSources'] ) && 0 <= $_POST['mainwp_options_backupOnExternalSources'] ) {
                    MainWP_Utility::update_option( 'mainwp_backupOnExternalSources', ( isset( $_POST['mainwp_options_backupOnExternalSources'] ) ? intval( $_POST['mainwp_options_backupOnExternalSources'] ) : 1 ) );
                }
                MainWP_Utility::update_option( 'mainwp_archiveFormat', ( isset( $_POST['mainwp_archiveFormat'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_archiveFormat'] ) ) : '' ) );

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
                    MainWP_Utility::update_option( 'mainwp_primaryBackup', sanitize_text_field( wp_unslash( $_POST['mainwp_primaryBackup'] ) ) );
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

    /**
     * Method backup_get_file_size()
     *
     * Get file size.
     *
     * @param mixed $pFile Backup file.
     *
     * @return false|int
     *
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_mainwp_specific_dir()
     */
    public static function backup_get_file_size( $pFile ) {
        $dir = MainWP_System_Utility::get_mainwp_specific_dir();

        if ( stristr( $pFile, $dir ) && file_exists( $pFile ) ) {
            return filesize( $pFile );
        }

        return 0;
    }
}
