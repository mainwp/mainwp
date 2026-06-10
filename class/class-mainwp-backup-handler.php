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
     * @uses \MainWP\Dashboard\MainWP_Backup_File_Manager::normalize_filename()
     * @uses \MainWP\Dashboard\MainWP_Utility::value_to_string()
     * @uses \MainWP\Dashboard\MainWP_Backup_File_Manager::get_real_extension()
     */
    public static function backup_site( $siteid, $pTask, $subfolder ) {
        // phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber
        // phpcs: ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.
        if ( ! get_option( 'mainwp_enableLegacyBackupFeature' ) ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_LEGACY_DISABLED ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }

        MainWP_Backup_Monitor::validate_backup_environment();

        $userid        = $pTask->userid;
        $type          = $pTask->type;
        $exclude       = MainWP_Backup_Settings::sanitize_exclude_list( $pTask->exclude );
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
        $subfolder = MainWP_Backup_File_Manager::normalize_filename( $subfolder );

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
            $ext = '.sql.' . MainWP_Backup_Settings::get_current_archive_extension( $website, $pTask );
        } else {
            $ext = '.' . MainWP_Backup_Settings::get_current_archive_extension( $website, $pTask );
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
        $file = MainWP_Backup_File_Manager::normalize_filename( $file );

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
                        'child_backup_task_id'   => '',
                    )
                );

                $params = array(
                    'type'                  => $type,
                    'exclude'               => $exclude,
                    'excludebackup'         => $excludebackup,
                    'excludecache'          => $excludecache,
                    'excludenonwp'          => $excludenonwp,
                    'excludezip'            => $excludezip,
                    'ext'                   => MainWP_Backup_Settings::get_current_archive_extension( $website, $pTask ),
                    'file_descriptors_auto' => $maximumFileDescriptorsAuto,
                    'file_descriptors'      => $maximumFileDescriptors,
                    'loadFilesBeforeZip'    => $loadFilesBeforeZip,
                    'pid'                   => $pid,
                    'f'                     => $file,
                );

                MainWP_Backup_Monitor::negotiate_backup_preflight( $website, MainWP_Backup_Settings::get_current_archive_extension( $website, $pTask ) );

                $use_spine = MainWP_Backup_Monitor::child_supports_task_spine( $website );

                MainWP_Logger::instance()->debug_for_website( $website, 'backup', 'Requesting backup' . ( $use_spine ? ' task' : '' ) . ': ' . MainWP_Utility::value_to_string( $params, 1 ) );

                $information = MainWP_Connect::fetch_url_authed( $website, $use_spine ? 'backup_task_create' : 'backup', $params, false, false, false );
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

            if ( $use_spine && empty( $information['error'] ) ) {
                if ( empty( $information['task_id'] ) || ! is_string( $information['task_id'] ) ) {
                    throw new MainWP_Exception( esc_html__( 'The child site did not return a backup task id. Sync the child site and try again.', 'mainwp' ) );
                }

                // The task is queued on the child; each following cycle
                // advances it one bounded slice.
                MainWP_DB_Backup::instance()->update_backup_task_progress( $taskId, $website->id, array( 'child_backup_task_id' => sanitize_text_field( $information['task_id'] ) ) );

                return false;
            }

            $backupTaskProgress = MainWP_DB_Backup::instance()->update_backup_task_progress( $taskId, $website->id, array( 'fetchResult' => wp_json_encode( $information ) ) );
        } elseif ( empty( $backupTaskProgress->fetchResult ) && ! empty( $backupTaskProgress->child_backup_task_id ) ) {
            $temp = false;
            try {
                $temp = MainWP_Connect::fetch_url_authed( $website, 'backup_task_run', array( 'task_id' => $backupTaskProgress->child_backup_task_id ), false, false, false );
            } catch ( \Exception $e ) {
                // The failed run call consumes a retry attempt below instead of being ignored.
                MainWP_Logger::instance()->warning_for_website( $website, 'backup', 'Backup task run failed: ' . $e->getMessage() );
            }

            if ( ! empty( $temp ) && isset( $temp['error'] ) ) {
                throw new MainWP_Exception( esc_html( MainWP_Backup_Error_Codes::sanitize_message( $temp['error'] ) ), null, isset( $temp['error_code'] ) ? $temp['error_code'] : '' ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }

            if ( ! empty( $temp ) && isset( $temp['phase'] ) ) {
                if ( 'failed' === $temp['phase'] ) {
                    // The child recorded why the task died; surface it as-is.
                    $message = isset( $temp['error'] ) && '' !== $temp['error']
                        ? MainWP_Backup_Error_Codes::sanitize_message( $temp['error'] )
                        : esc_html__( 'The backup task failed on the child site. Check the child site error log and try again.', 'mainwp' );
                    throw new MainWP_Exception( esc_html( $message ), null, isset( $temp['error_code'] ) ? $temp['error_code'] : '' ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                }

                if ( 'completed' !== $temp['phase'] ) {
                    return false; // The task is still working; the next cycle advances it again.
                }

                $information = array();
                if ( 'full' === $type ) {
                    $information['full'] = isset( $temp['file'] ) ? $temp['file'] : false;
                    $information['db']   = false;
                } else {
                    $information['full'] = false;
                    $information['db']   = isset( $temp['file'] ) ? $temp['file'] : false;
                }
                $information['size'] = isset( $temp['size'] ) ? $temp['size'] : 0;

                $backupTaskProgress = MainWP_DB_Backup::instance()->update_backup_task_progress( $taskId, $website->id, array( 'fetchResult' => wp_json_encode( $information ) ) );
            } elseif ( 5 > $backupTaskProgress->attempts ) {
                $backupTaskProgress = MainWP_DB_Backup::instance()->update_backup_task_progress( $taskId, $website->id, array( 'attempts' => $backupTaskProgress->attempts + 1 ) );
                return false;
            } else {
                throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_RETRY_EXHAUSTED ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
            }
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
                                'ext'                   => MainWP_Backup_Settings::get_current_archive_extension( $website, $pTask ),
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

            MainWP_Backup_File_Manager::cleanup_stale_download_locks( $dir );

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
                                if ( $information['db'] && MainWP_Backup_File_Manager::is_sql_file( $file ) ) {
                                    $dbBackups[ filemtime( $theFile ) . $file ] = $theFile;
                                }

                                if ( $information['full'] && MainWP_Backup_File_Manager::is_archive( $file ) && ! MainWP_Backup_File_Manager::is_sql_archive( $file ) ) {
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
                    $localBackupFile .= MainWP_Backup_File_Manager::get_real_extension( $information['db'] );

                    $localBackupFile = MainWP_Backup_File_Manager::normalize_filename( $localBackupFile );

                    $backupTaskProgress = MainWP_DB_Backup::instance()->update_backup_task_progress( $taskId, $website->id, array( 'downloadedDB' => $localBackupFile ) );
                } else {
                    $localBackupFile = $backupTaskProgress->downloadedDB;
                }

                if ( 0 === (int) $backupTaskProgress->downloadedDBComplete ) {
                    if ( ! MainWP_Backup_File_Manager::acquire_download_lock( $localBackupFile, $website->id, $taskId ) ) {
                        throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING, 'download lock held for ' . basename( $localBackupFile ) ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
                    }
                    try {
                        // MWP-1548: decrypt at the boundary so download_to_file sees plaintext credentials.
                        $http_user_plain = MainWP_Credential_Storage::decrypt_credential( $website->http_user );
                        $http_pass_plain = MainWP_Credential_Storage::decrypt_credential( $website->http_pass );
                        MainWP_Connect::download_to_file( MainWP_Connect::get_get_data_authed( $website, $information['db'], 'fdl' ), $localBackupFile, $information['size'], $http_user_plain, $http_pass_plain );
                        MainWP_Backup_File_Manager::validate_backup_file( $localBackupFile, $information['size'] );
                    } finally {
                        MainWP_Backup_File_Manager::release_download_lock( $localBackupFile );
                    }
                    $backupTaskProgress = MainWP_DB_Backup::instance()->update_backup_task_progress( $taskId, $website->id, array( 'downloadedDBComplete' => 1 ) );
                }
            }

            if ( $information['full'] ) {
                $realExt         = MainWP_Backup_File_Manager::get_real_extension( $information['full'] );
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

                    $localBackupFile = MainWP_Backup_File_Manager::normalize_filename( $localBackupFile );

                    $backupTaskProgress = MainWP_DB_Backup::instance()->update_backup_task_progress( $taskId, $website->id, array( 'downloadedFULL' => $localBackupFile ) );
                } else {
                    $localBackupFile = $backupTaskProgress->downloadedFULL;
                }

                if ( 0 === (int) $backupTaskProgress->downloadedFULLComplete ) {
                    if ( ! MainWP_Backup_File_Manager::acquire_download_lock( $localBackupFile, $website->id, $taskId ) ) {
                        throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING, 'download lock held for ' . basename( $localBackupFile ) ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
                    }
                    try {
                        // MWP-1548: decrypt at the boundary so download_to_file sees plaintext credentials.
                        $http_user_plain = MainWP_Credential_Storage::decrypt_credential( $website->http_user );
                        $http_pass_plain = MainWP_Credential_Storage::decrypt_credential( $website->http_pass );
                        MainWP_Connect::download_to_file( MainWP_Connect::get_get_data_authed( $website, $information['full'], 'fdl' ), $localBackupFile, $information['size'], $http_user_plain, $http_pass_plain );
                        MainWP_Backup_File_Manager::validate_backup_file( $localBackupFile, $information['size'] );
                    } finally {
                        MainWP_Backup_File_Manager::release_download_lock( $localBackupFile );
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
     * @deprecated 6.2 Use MainWP_Backup_File_Manager::backup_download_file() instead.
     *
     * @param mixed $pSiteId Child Site ID.
     * @param mixed $pType   full|db Type of backup.
     * @param mixed $pUrl    Backup location.
     * @param mixed $pFile   Backup File.
     *
     * @return bool true|false
     */
    public static function backup_download_file( $pSiteId, $pType, $pUrl, $pFile ) {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_File_Manager::backup_download_file' );
        return MainWP_Backup_File_Manager::backup_download_file( (int) $pSiteId, (string) $pType, (string) $pUrl, (string) $pFile );
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
     * @deprecated 6.2 Use MainWP_Backup_Monitor::backup_check_pid() instead.
     *
     * @param mixed $pSiteId   Child Site id.
     * @param mixed $pid       Backup pid.
     * @param mixed $type      full|db Type of backup.
     * @param mixed $subfolder Sub folder for backup.
     * @param mixed $pFilename Backups filename.
     *
     * @return array $status, $result.
     */
    public static function backup_check_pid( $pSiteId, $pid, $type, $subfolder, $pFilename ) {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_Monitor::backup_check_pid' );
        return MainWP_Backup_Monitor::backup_check_pid( (int) $pSiteId, $pid, (string) $type, (string) $subfolder, null === $pFilename ? null : (string) $pFilename );
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
     * @uses \MainWP\Dashboard\MainWP_Backup_File_Manager::normalize_filename()
     * @uses \MainWP\Dashboard\MainWP_Utility::sanitize_file_name()
     * @uses \MainWP\Dashboard\MainWP_Utility::end_session()
     * @uses \MainWP\Dashboard\MainWP_Utility::value_to_string()
     * @uses \MainWP\Dashboard\MainWP_Backup_File_Manager::get_real_extension()
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

        MainWP_Backup_Monitor::validate_backup_environment();

        $pExclude = MainWP_Backup_Settings::sanitize_exclude_list( $pExclude );

        if ( null === $pFilename || '' === trim( $pFilename ) ) {
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
        $subfolder = MainWP_Backup_File_Manager::normalize_filename( $subfolder );

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
                $pType,
            ),
            $pFilename
        );
        $file = str_replace( '%', '', $file );
        $file = MainWP_Backup_File_Manager::normalize_filename( $file );

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
            $archiveFormat = MainWP_Backup_Settings::get_current_archive_extension( $website );
        } elseif ( 'global' === $pArchiveFormat ) {
            $archiveFormat = MainWP_Backup_Settings::get_current_archive_extension();
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

        MainWP_Backup_Monitor::negotiate_backup_preflight( $website, $archiveFormat );

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
                $localBackupFile = $dir . 'db-' . $websiteCleanUrl . '-' . $fm_date . '-' . $fm_time . MainWP_Backup_File_Manager::get_real_extension( $information['db'] );
                $localRegexFile  = 'db-' . $websiteCleanUrl . '-(.*)-(.*).sql(\.zip|\.tar|\.tar\.gz|\.tar\.bz2)?';
            } else {
                $localBackupFile = $dir . 'full-' . $websiteCleanUrl . '-' . $fm_date . '-' . $fm_time . MainWP_Backup_File_Manager::get_real_extension( $information['full'] );
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
                $localBackupFile = MainWP_Backup_File_Manager::normalize_filename( $localBackupFile );

                if ( 'db' === $pType ) {
                    $localBackupFile .= MainWP_Backup_File_Manager::get_real_extension( $information['db'] );
                } else {
                    $localBackupFile .= MainWP_Backup_File_Manager::get_real_extension( $information['full'] );
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
     * @deprecated 6.2 Use MainWP_Backup_File_Manager::normalize_filename() instead.
     *
     * @param mixed $s Filename to normalize.
     *
     * @return string $s Normalised filename.
     */
    public static function normalize_filename( $s ) {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_File_Manager::normalize_filename' );
        return MainWP_Backup_File_Manager::normalize_filename( (string) $s );
    }

    /**
     * Method is_archive()
     *
     * Check if Archive.
     *
     * @deprecated 6.2 Use MainWP_Backup_File_Manager::is_archive() instead.
     *
     * @param mixed  $pFileName File to check.
     * @param string $pPrefix File prefix.
     * @param string $pSuffix File suffix.
     *
     * @return bool Whether the filename is an archive.
     */
    public static function is_archive( $pFileName, $pPrefix = '', $pSuffix = '' ) {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_File_Manager::is_archive' );
        return MainWP_Backup_File_Manager::is_archive( (string) $pFileName, (string) $pPrefix, (string) $pSuffix );
    }

    /**
     * Method is_sql_file()
     *
     * Check if file is SQL.
     *
     * @deprecated 6.2 Use MainWP_Backup_File_Manager::is_sql_file() instead.
     *
     * @param mixed $pFileName File to check.
     *
     * @return bool Whether the filename is a SQL dump or SQL archive.
     */
    public static function is_sql_file( $pFileName ) {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_File_Manager::is_sql_file' );
        return MainWP_Backup_File_Manager::is_sql_file( (string) $pFileName );
    }

    /**
     * Method is_sql_archive()
     *
     * Check if is SQL Archive.
     *
     * @deprecated 6.2 Use MainWP_Backup_File_Manager::is_sql_archive() instead.
     *
     * @param mixed $pFileName File to check.
     *
     * @return bool Whether the filename is a SQL archive.
     */
    public static function is_sql_archive( $pFileName ) {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_File_Manager::is_sql_archive' );
        return MainWP_Backup_File_Manager::is_sql_archive( (string) $pFileName );
    }

    /**
     * Method sanitize_exclude_list()
     *
     * Sanitize a backup exclude list before it is sent to a child site.
     *
     * @deprecated 6.2 Use MainWP_Backup_Settings::sanitize_exclude_list() instead.
     *
     * @param string|array $exclude Exclude list as a newline- or comma-separated string, or an array of entries.
     *
     * @return string Comma-separated list of sanitized child-relative folder paths.
     */
    public static function sanitize_exclude_list( $exclude ) {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_Settings::sanitize_exclude_list' );
        return MainWP_Backup_Settings::sanitize_exclude_list( $exclude );
    }

    /**
     * Method get_current_archive_extension()
     *
     * Get extension of current Archive.
     *
     * @deprecated 6.2 Use MainWP_Backup_Settings::get_current_archive_extension() instead.
     *
     * @param object|false $website Website object, or false to use the global format.
     * @param object|false $task    Backup task object, or false when there is no task context.
     *
     * @return string Format of Archive.
     */
    public static function get_current_archive_extension( $website = false, $task = false ) {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_Settings::get_current_archive_extension' );
        return MainWP_Backup_Settings::get_current_archive_extension( $website, $task );
    }

    /**
     * Method get_real_extension()
     *
     * Get full file extension.
     *
     * @deprecated 6.2 Use MainWP_Backup_File_Manager::get_real_extension() instead.
     *
     * @param mixed $path Path to file.
     *
     * @return string Compound extension such as .sql.tar.gz, or the plain extension.
     */
    public static function get_real_extension( $path ) {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_File_Manager::get_real_extension' );
        return MainWP_Backup_File_Manager::get_real_extension( (string) $path );
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
     * @deprecated 6.2 Use MainWP_Backup_Monitor::format_backup_errors() instead.
     *
     * @param array $entries Error entries, one per failed operation.
     * @param int   $max     Maximum entries to keep (default 10).
     *
     * @return string Formatted HTML, empty when there are no entries.
     */
    public static function format_backup_errors( $entries, $max = 10 ) {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_Monitor::format_backup_errors' );
        return MainWP_Backup_Monitor::format_backup_errors( $entries, (int) $max );
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
     * @deprecated 6.2 Use MainWP_Backup_Monitor::negotiate_backup_preflight() instead.
     *
     * @param object $website     Website object.
     * @param string $archive_ext Requested archive extension (zip, tar.gz, tar.bz2, tar).
     *
     * @return void
     */
    public static function negotiate_backup_preflight( &$website, $archive_ext = '' ) {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_Monitor::negotiate_backup_preflight' );
        MainWP_Backup_Monitor::negotiate_backup_preflight( $website, (string) $archive_ext );
    }

    /**
     * Method child_supports_task_spine()
     *
     * Whether the child site advertised the resumable backup task machine
     * in its last preflight report.
     *
     * @deprecated 6.2 Use MainWP_Backup_Monitor::child_supports_task_spine() instead.
     *
     * @param object $website Website object.
     *
     * @return bool True when the child supports resumable backup tasks.
     */
    public static function child_supports_task_spine( $website ) {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_Monitor::child_supports_task_spine' );
        return MainWP_Backup_Monitor::child_supports_task_spine( $website );
    }

    /**
     * Method get_max_concurrent_backups()
     *
     * Get the configured maximum number of concurrent backup tasks.
     *
     * @deprecated 6.2 Use MainWP_Backup_Settings::get_max_concurrent_backups() instead.
     *
     * @return int Maximum concurrent backups (default 2, minimum 1).
     */
    public static function get_max_concurrent_backups() {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_Settings::get_max_concurrent_backups' );
        return MainWP_Backup_Settings::get_max_concurrent_backups();
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
     * @deprecated 6.2 Use MainWP_Backup_Monitor::acquire_backup_slot() instead.
     *
     * @param int $max_concurrent Maximum concurrent backup tasks (default 2).
     *
     * @return string|false Slot token on success, false when all slots are in use.
     */
    public static function acquire_backup_slot( $max_concurrent = 2 ) {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_Monitor::acquire_backup_slot' );
        return MainWP_Backup_Monitor::acquire_backup_slot( (int) $max_concurrent );
    }

    /**
     * Method release_backup_slot()
     *
     * Release a backup execution slot.
     *
     * @deprecated 6.2 Use MainWP_Backup_Monitor::release_backup_slot() instead.
     *
     * @param string $token Slot token from acquire_backup_slot().
     *
     * @return void
     */
    public static function release_backup_slot( $token ) {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_Monitor::release_backup_slot' );
        MainWP_Backup_Monitor::release_backup_slot( (string) $token );
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
     * @deprecated 6.2 Use MainWP_Backup_Monitor::validate_backup_environment() instead.
     *
     * @return void
     */
    public static function validate_backup_environment() {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_Monitor::validate_backup_environment' );
        MainWP_Backup_Monitor::validate_backup_environment();
    }

    /**
     * Method acquire_download_lock()
     *
     * Acquire the download lock for a backup file. The lock is a sidecar
     * file with a one hour TTL; a stale or corrupt lock is replaced. TTL
     * expiry alone covers crash recovery on every platform, so no process
     * checks are used.
     *
     * @deprecated 6.2 Use MainWP_Backup_File_Manager::acquire_download_lock() instead.
     *
     * @param string $local_file Absolute path of the download target.
     * @param int    $site_id    Child site ID.
     * @param int    $task_id    Backup task ID.
     *
     * @return bool True when the lock was acquired, false when another process holds a live lock.
     */
    public static function acquire_download_lock( $local_file, $site_id, $task_id ) {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_File_Manager::acquire_download_lock' );
        return MainWP_Backup_File_Manager::acquire_download_lock( (string) $local_file, (int) $site_id, (int) $task_id );
    }

    /**
     * Method release_download_lock()
     *
     * Release the download lock for a backup file.
     *
     * @deprecated 6.2 Use MainWP_Backup_File_Manager::release_download_lock() instead.
     *
     * @param string $local_file Absolute path of the download target.
     *
     * @return void
     */
    public static function release_download_lock( $local_file ) {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_File_Manager::release_download_lock' );
        MainWP_Backup_File_Manager::release_download_lock( (string) $local_file );
    }

    /**
     * Method cleanup_stale_download_locks()
     *
     * Remove stale download lock files left behind by crashed processes.
     * Runs at backup start so an interrupted run never blocks future runs
     * for longer than the lock TTL.
     *
     * @deprecated 6.2 Use MainWP_Backup_File_Manager::cleanup_stale_download_locks() instead.
     *
     * @param string $dir Directory holding backup files.
     *
     * @return void
     */
    public static function cleanup_stale_download_locks( $dir ) {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_File_Manager::cleanup_stale_download_locks' );
        MainWP_Backup_File_Manager::cleanup_stale_download_locks( (string) $dir );
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
     * @deprecated 6.2 Use MainWP_Backup_File_Manager::validate_backup_file() instead.
     *
     * @param string    $file          Absolute path of the downloaded file.
     * @param int|false $expected_size Expected size in bytes, false to skip the size check.
     * @param float     $tolerance     Allowed fractional size deviation (default 1%).
     *
     * @return void
     */
    public static function validate_backup_file( $file, $expected_size = false, $tolerance = 0.01 ) {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_File_Manager::validate_backup_file' );
        MainWP_Backup_File_Manager::validate_backup_file( (string) $file, $expected_size, (float) $tolerance );
    }

    /**
     * Method handle_settings_post()
     *
     * Update Child Site Settings.
     *
     * @deprecated 6.2 Use MainWP_Backup_Settings::handle_settings_post() instead.
     *
     * @return bool true|false
     */
    public static function handle_settings_post() {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_Settings::handle_settings_post' );
        return MainWP_Backup_Settings::handle_settings_post();
    }

    /**
     * Method backup_get_file_size()
     *
     * Get file size.
     *
     * @deprecated 6.2 Use MainWP_Backup_File_Manager::backup_get_file_size() instead.
     *
     * @param mixed $pFile Backup file.
     *
     * @return false|int
     */
    public static function backup_get_file_size( $pFile ) {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_File_Manager::backup_get_file_size' );
        return MainWP_Backup_File_Manager::backup_get_file_size( (string) $pFile );
    }
}
