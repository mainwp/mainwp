<?php
/**
 * MainWP Backup Executor.
 *
 * Drives backups end to end: the per-site task state machine behind
 * scheduled backup runs, the synchronous one-shot backup path, and the
 * provider contract other systems use to trigger a backup and await its
 * completion.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Backup_Executor
 *
 * @package MainWP\Dashboard
 */
class MainWP_Backup_Executor { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Website option holding the active ad-hoc backup task handle.
     *
     * @var string
     */
    const ADHOC_TASK_OPTION = 'adhoc_backup_task';

    /**
     * Method get_class_name()
     *
     * Get Class Name.
     *
     * @return string __CLASS__
     */
    public static function get_class_name(): string {
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
        if ( ! MainWP_Backup_Settings::is_native_backups_enabled() ) {
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
                        'child_phase'            => '',
                        'bytes_downloaded'       => 0,
                        'bytes_expected'         => 0,
                        'current_file'           => '',
                        'files_processed'        => 0,
                        'files_total'            => 0,
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

                if ( 'incremental' === $type ) {
                    $send           = static::resolve_incremental_send( $website, $pTask, $use_spine, $backupTaskProgress );
                    $params['type'] = $send['type'];
                    foreach ( $send['params'] as $send_key => $send_value ) {
                        $params[ $send_key ] = $send_value;
                    }
                    if ( '' !== $send['notice'] ) {
                        static::record_incremental_notice( $website, $taskId, $send['notice'] );
                    }
                    if ( 'chunk-push' === $send['mode'] ) {
                        // The manifest is too large for the create request:
                        // chunk it over first and reference the staged
                        // session. A transfer that cannot get through falls
                        // back to a manifest-emitting full in this cycle;
                        // the chunk-push marker keeps a later missing-base
                        // report escalating to full instead of retrying.
                        $manifest_path   = (string) $params['__manifest_path'];
                        $manifest_sha256 = (string) $params['__manifest_sha256'];
                        unset( $params['__manifest_path'], $params['__manifest_sha256'] );

                        $push = MainWP_Backup_Manifest_Chunk_Transfer::instance()->push( $website, $manifest_path, $manifest_sha256 );
                        if ( $push['ok'] ) {
                            $params['parent_manifest_ref']    = $push['mtoken'];
                            $params['parent_manifest_sha256'] = $manifest_sha256;
                        } else {
                            static::record_incremental_notice( $website, $taskId, MainWP_Backup_Error_Codes::message( MainWP_Backup_Error_Codes::ERR_MANIFEST_CHUNK_SEND_FAILED ) );
                            $params['type']          = 'full';
                            $params['emit_manifest'] = '1';
                            unset( $params['parent_snapshot_id'] );
                        }
                    }
                    if ( $use_spine ) {
                        // Remember what this run attempted, so a child-reported
                        // missing base escalates plain -> push -> full instead
                        // of retrying the same send forever.
                        MainWP_DB_Backup::instance()->update_backup_task_progress(
                            $taskId,
                            $website->id,
                            array(
                                'last_error' => wp_json_encode(
                                    array(
                                        'incremental_sent' => $send['mode'],
                                        'run'              => (int) $pTask->last_run,
                                    )
                                ),
                            )
                        );
                    }
                }

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

            if ( ! empty( $temp ) && 'incremental' === $type
                && isset( $temp['error_code'] ) && MainWP_Backup_Error_Codes::ERR_INCREMENTAL_BASE_MISSING === $temp['error_code']
                && static::requeue_incremental_after_base_missing( $website, $pTask, $backupTaskProgress ) ) {
                return false; // Requeued: the next cycle re-creates the task with the escalated send.
            }

            if ( ! empty( $temp ) && isset( $temp['error'] ) ) {
                throw new MainWP_Exception( esc_html( MainWP_Backup_Error_Codes::sanitize_message( $temp['error'] ) ), null, isset( $temp['error_code'] ) ? $temp['error_code'] : '' ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }

            if ( ! empty( $temp ) && isset( $temp['phase'] ) ) {
                // Unknown phase strings from newer children stay null and keep polling.
                $phase = MainWP_Backup_Task_Phase::tryFrom( (string) $temp['phase'] );

                static::record_child_progress( $taskId, (int) $website->id, $temp );

                if ( MainWP_Backup_Task_Phase::Failed === $phase ) {
                    // The child recorded why the task died; surface it as-is.
                    $message = isset( $temp['error'] ) && '' !== $temp['error']
                        ? MainWP_Backup_Error_Codes::sanitize_message( $temp['error'] )
                        : esc_html__( 'The backup task failed on the child site. Check the child site error log and try again.', 'mainwp' );

                    /** This action is documented in class/backup/class-mainwp-backup-executor.php */
                    do_action(
                        'mainwp_native_backup_completed',
                        (int) $website->id,
                        $backupTaskProgress->child_backup_task_id,
                        false,
                        array(
                            'error'      => $message,
                            'error_code' => isset( $temp['error_code'] ) ? $temp['error_code'] : '',
                        )
                    );
                    throw new MainWP_Exception( esc_html( $message ), null, isset( $temp['error_code'] ) ? $temp['error_code'] : '' ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                }

                if ( MainWP_Backup_Task_Phase::Completed !== $phase ) {
                    return false; // The task is still working; the next cycle advances it again.
                }

                $information = array();
                if ( 'incremental' === $type ) {
                    // Incremental tasks complete with a role-tagged artifact
                    // list; the legacy single-file keys stay false so the
                    // legacy download section never claims the run.
                    $information['full']      = false;
                    $information['db']        = false;
                    $information['artifacts'] = isset( $temp['artifacts'] ) && is_array( $temp['artifacts'] ) ? $temp['artifacts'] : array();
                } elseif ( 'full' === $type || 'files' === $type ) {
                    // A files-only run produces an archive like a full run,
                    // so it lands in the same download slot.
                    $information['full'] = isset( $temp['file'] ) ? $temp['file'] : false;
                    $information['db']   = false;
                } else {
                    $information['full'] = false;
                    $information['db']   = isset( $temp['file'] ) ? $temp['file'] : false;
                }
                $information['size'] = isset( $temp['size'] ) ? $temp['size'] : 0;

                $backupTaskProgress = MainWP_DB_Backup::instance()->update_backup_task_progress( $taskId, $website->id, array( 'fetchResult' => wp_json_encode( $information ) ) );

                /** This action is documented in class/backup/class-mainwp-backup-executor.php */
                do_action(
                    'mainwp_native_backup_completed',
                    (int) $website->id,
                    $backupTaskProgress->child_backup_task_id,
                    true,
                    array(
                        'type' => $type,
                        'file' => isset( $temp['file'] ) ? $temp['file'] : '',
                        'size' => $information['size'],
                    )
                );
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
                    if ( 'full' === $type || 'files' === $type ) {
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
        } elseif ( 'incremental' === $type && ! empty( $information['artifacts'] ) ) {
            return static::finish_incremental_backup( $website, $pTask, $information['artifacts'], $backupTaskProgress );
        } elseif ( 'incremental' === $type && empty( $information['full'] ) && empty( $information['db'] ) ) {
            // A spine child completing an incremental run without artifacts
            // produced nothing downloadable.
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        } elseif ( 'db' === $type && empty( $information['db'] ) ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_DB_QUERY_FAILED ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        } elseif ( ( 'full' === $type || 'files' === $type ) && empty( $information['full'] ) ) {
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
                        MainWP_Backup_File_Manager::download_backup_artifact( $website, $information['db'], $localBackupFile, $information['size'], $http_user_plain, $http_pass_plain );
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
                        MainWP_Backup_File_Manager::download_backup_artifact( $website, $information['full'], $localBackupFile, $information['size'], $http_user_plain, $http_pass_plain );
                        MainWP_Backup_File_Manager::validate_backup_file( $localBackupFile, $information['size'] );
                    } finally {
                        MainWP_Backup_File_Manager::release_download_lock( $localBackupFile );
                    }
                    MainWP_Connect::fetch_url_authed( $website, 'delete_backup', array( 'del' => $information['full'] ) );
                    $backupTaskProgress = MainWP_DB_Backup::instance()->update_backup_task_progress( $taskId, $website->id, array( 'downloadedFULLComplete' => 1 ) );
                }
            }

            static::record_downloaded_run_snapshot( $website, $pTask, $backupTaskProgress );

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
     * Decide what an incremental task's run actually sends to the child.
     *
     * The happy path references the latest complete snapshot so the child
     * diffs against its own sidecar. A first run, a child without the
     * capability, or an exhausted retry escalation runs a manifest-emitting
     * full instead - a backup that costs more disk beats one that silently
     * never runs. Retries after a child-reported missing base escalate
     * plain -> push (the dashboard re-uploads the parent manifest) -> full.
     *
     * @param object $website   Website object.
     * @param object $pTask     Scheduled task.
     * @param bool   $use_spine Whether the child runs the task machine.
     * @param mixed  $progress  Pre-reset progress row (carries the sent marker).
     *
     * @return array { type: string, mode: string, params: array, notice: string }
     */
    protected static function resolve_incremental_send( $website, $pTask, $use_spine, $progress ) {
        if ( ! $use_spine || ! MainWP_Backup_Monitor::child_supports_incremental( $website ) ) {
            return array(
                'type'   => 'full',
                'mode'   => 'full',
                'params' => $use_spine ? array( 'emit_manifest' => '1' ) : array(),
                'notice' => esc_html__( 'The child site does not support incremental backups, so a full backup was run instead. Update the MainWP Child plugin on the site to enable incremental backups.', 'mainwp' ),
            );
        }

        $marker = static::incremental_sent_marker( $progress, (int) $pTask->last_run );
        $base   = MainWP_Backup_Snapshots::instance()->get_latest_complete( (int) $website->id, (int) $pTask->id );

        if ( null === $base ) {
            // No chain yet - this full run emits the manifest the first
            // incremental diffs against. Expected, not an error.
            return array(
                'type'   => 'full',
                'mode'   => 'full',
                'params' => array( 'emit_manifest' => '1' ),
                'notice' => '',
            );
        }

        $max_chain_length = isset( $pTask->max_chain_length ) ? (int) $pTask->max_chain_length : 0;
        $chain            = MainWP_Backup_Snapshots::instance()->get_chain( (int) $base->id );
        // A chain at its limit restarts with a new full base, turning
        // the old chain into a complete, prunable retention unit. A
        // chain whose walk never reaches a full base, or that crosses
        // a non-complete link (a pruned ancestor's artifacts are gone),
        // is broken and cannot restore completely - restarting is the
        // safe answer, with or without a length limit.
        $has_broken_link = (bool) array_filter( $chain, static fn( $link ) => 'complete' !== $link->status );
        $at_length_limit = $max_chain_length > 0 && count( $chain ) >= $max_chain_length;
        if ( $has_broken_link || 'full' !== $chain[0]->type || $at_length_limit ) {
            return array(
                'type'   => 'full',
                'mode'   => 'full',
                'params' => array( 'emit_manifest' => '1' ),
                'notice' => '',
            );
        }

        $escalate_to_full = in_array( $marker, array( 'push', 'chunk-push', 'full' ), true );
        $manifest_b64     = '';
        if ( ! $escalate_to_full && 'plain' === $marker ) {
            $manifest_b64 = static::pushable_manifest( $base );
            if ( '' === $manifest_b64 ) {
                // Too large for one request: a chunk-capable child stages
                // the manifest in pieces instead of forcing a full backup.
                $chunkable = static::chunkable_manifest( $base, $website );
                if ( '' !== $chunkable['path'] ) {
                    return array(
                        'type'   => 'incremental',
                        'mode'   => 'chunk-push',
                        'params' => array(
                            'parent_snapshot_id' => (string) $base->id,
                            '__manifest_path'    => $chunkable['path'],
                            '__manifest_sha256'  => $chunkable['sha256'],
                        ),
                        'notice' => '',
                    );
                }
                $escalate_to_full = true;
            }
        }

        if ( $escalate_to_full ) {
            return array(
                'type'   => 'full',
                'mode'   => 'full',
                'params' => array( 'emit_manifest' => '1' ),
                'notice' => MainWP_Backup_Error_Codes::message( MainWP_Backup_Error_Codes::ERR_INCREMENTAL_BASE_MISSING ),
            );
        }

        $send_params = array( 'parent_snapshot_id' => (string) $base->id );
        if ( '' !== $manifest_b64 ) {
            $send_params['parent_manifest'] = $manifest_b64;
            return array(
                'type'   => 'incremental',
                'mode'   => 'push',
                'params' => $send_params,
                'notice' => '',
            );
        }

        return array(
            'type'   => 'incremental',
            'mode'   => 'plain',
            'params' => $send_params,
            'notice' => '',
        );
    }

    /**
     * Requeue an incremental run after the child reported a missing or
     * unusable parent manifest. The next create cycle escalates the send;
     * a failure after the full fallback is real and propagates.
     *
     * @param object $website  Website object.
     * @param object $pTask    Scheduled task.
     * @param mixed  $progress Progress row (carries the sent marker).
     *
     * @return bool True when requeued; false when the failure is final.
     */
    protected static function requeue_incremental_after_base_missing( $website, $pTask, $progress ) {
        $marker = static::incremental_sent_marker( $progress, (int) $pTask->last_run );
        if ( 'full' === $marker ) {
            return false; // The full fallback itself failed - a real failure.
        }

        MainWP_DB_Backup::instance()->update_backup_task_progress(
            $pTask->id,
            $website->id,
            array(
                'dtsFetched'           => 0,
                'child_backup_task_id' => '',
                'attempts'             => 0,
            )
        );

        MainWP_Logger::instance()->warning_for_website( $website, 'backup', 'The child site reported the incremental base as missing; the run is requeued with an escalated send (' . $marker . ' attempted).' );

        return true;
    }

    /**
     * What this run already attempted for an incremental task: '', 'plain',
     * 'push', or 'full'. Markers from a previous run (different last_run)
     * are stale and read as ''.
     *
     * @param mixed $progress Progress row.
     * @param int   $run      Current run identity (the task's last_run).
     *
     * @return string Sent marker.
     */
    protected static function incremental_sent_marker( $progress, $run ) {
        if ( empty( $progress ) || empty( $progress->last_error ) || ! is_string( $progress->last_error ) ) {
            return '';
        }
        $decoded = json_decode( $progress->last_error, true );
        if ( ! is_array( $decoded ) || ! isset( $decoded['incremental_sent'], $decoded['run'] ) || (int) $decoded['run'] !== $run ) {
            return '';
        }
        return (string) $decoded['incremental_sent'];
    }

    /**
     * The base snapshot's manifest as a pushable base64 blob, or '' when
     * it is missing, empty, or too large for a single create request.
     *
     * @param object $base Base snapshot row.
     *
     * @return string Base64 manifest or ''.
     */
    protected static function pushable_manifest( $base ) {
        $path = isset( $base->manifest_path ) ? (string) $base->manifest_path : '';
        if ( '' === $path || ! is_readable( $path ) ) {
            return '';
        }
        $size = (int) filesize( $path );
        // The child caps the inline manifest at 6 MiB of base64 (~4.5 MiB raw).
        if ( $size < 1 || $size > 4500000 ) {
            return '';
        }
        $content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- local manifest read.
        return false === $content ? '' : base64_encode( $content ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- wire transport encoding for the pushed manifest, not code.
    }

    /**
     * The base snapshot's manifest as a chunk-push candidate: its local
     * path and hash, or an empty path when the file is unusable or the
     * child cannot stage chunks.
     *
     * @param object $base    Base snapshot row.
     * @param object $website Website object.
     *
     * @return array { path: string, sha256: string }
     */
    protected static function chunkable_manifest( $base, $website ) {
        $none = array(
            'path'   => '',
            'sha256' => '',
        );
        if ( ! MainWP_Backup_Monitor::child_supports_manifest_chunk( $website ) ) {
            return $none;
        }
        $path = isset( $base->manifest_path ) ? (string) $base->manifest_path : '';
        if ( '' === $path || ! is_readable( $path ) ) {
            return $none;
        }
        $size = (int) filesize( $path );
        // The child's staging receiver enforces the same 50 MiB ceiling.
        if ( $size < 1 || $size > 50 * 1024 * 1024 ) {
            return $none;
        }
        $sha256 = hash_file( 'sha256', $path );
        if ( ! is_string( $sha256 ) ) {
            return $none;
        }
        return array(
            'path'   => $path,
            'sha256' => $sha256,
        );
    }

    /**
     * Record a forced-full notice on the task and in the site log, so the
     * user learns why the run cost a full backup.
     *
     * @param object $website Website object.
     * @param int    $taskId  Task id.
     * @param string $message Notice text.
     */
    protected static function record_incremental_notice( $website, $taskId, $message ) {
        MainWP_Logger::instance()->warning_for_website( $website, 'backup', $message );
        if ( MainWP_DB_Backup::instance()->get_backup_task_by_id( $taskId ) ) {
            MainWP_DB_Backup::instance()->update_backup_errors( $taskId, $message . "\n" );
        }
    }

    /**
     * Record a completed full/db download run as a snapshot row, once per
     * run.
     *
     * Both the resumable child-task path and the legacy polling path
     * converge on the same download branch, so this single point covers
     * them. The created-since-last-run guard keeps a postprocess re-entry
     * from recording the same run twice. Ad-hoc runs without a stored
     * task carry no destinations and no chain, so they stay unrecorded.
     *
     * @param object $website  Website object.
     * @param object $pTask    Scheduled task.
     * @param object $progress Progress row carrying the downloaded paths.
     *
     * @return void
     */
    protected static function record_downloaded_run_snapshot( $website, $pTask, $progress ) {
        if ( empty( $pTask->id ) ) {
            return;
        }

        $snapshots = MainWP_Backup_Snapshots::instance();

        $existing = $snapshots->get_latest_complete( (int) $website->id, (int) $pTask->id );
        if ( null !== $existing && (int) $existing->created >= (int) $pTask->last_run ) {
            return;
        }

        $archives = array();
        foreach ( array(
            'db'   => array( $progress->downloadedDBComplete, $progress->downloadedDB ),
            'full' => array( $progress->downloadedFULLComplete, $progress->downloadedFULL ),
        ) as $role => $download ) {
            list( $complete, $path ) = $download;
            if ( empty( $complete ) || empty( $path ) || ! is_file( $path ) ) {
                continue;
            }
            $archives[] = array(
                'file' => basename( $path ),
                'size' => (int) filesize( $path ),
                'role' => $role,
                'path' => $path,
            );
        }

        if ( empty( $archives ) ) {
            return;
        }

        $has_full = in_array( 'full', array_column( $archives, 'role' ), true );

        // A files-only run downloads through the full slot (it is an
        // archive), but its snapshot keeps the files type so retention
        // counts it as its own unit and the restore catalog can refuse
        // a backup that carries no database.
        $snapshot_type = $has_full ? 'full' : 'db';
        if ( $has_full && 'files' === $pTask->type ) {
            $snapshot_type = 'files';
        }

        $snapshots->record_snapshot(
            array(
                'task_id'       => (int) $pTask->id,
                'site_id'       => (int) $website->id,
                'type'          => $snapshot_type,
                'parent_id'     => null,
                'archives'      => $archives,
                'manifest_path' => '',
            )
        );
    }

    /**
     * Download every artifact of a completed incremental-chain run and
     * record the durable snapshot row.
     *
     * Artifacts land in a per-run directory keyed by the child task id, so
     * an interrupted cycle resumes by skipping files that already arrived
     * intact. The snapshot row is written only after every artifact landed;
     * remote cleanup then removes the child-side copies but never the
     * manifest sidecar the next incremental diffs against.
     *
     * The legacy postprocess hooks are not fired: extensions built around
     * a single backup file cannot process a multi-part run.
     *
     * @param object $website   Website object.
     * @param object $pTask     Scheduled task.
     * @param array  $artifacts Role-tagged artifact list from the child.
     * @param object $progress  Progress row (carries the child task id).
     *
     * @throws MainWP_Exception When an artifact cannot be downloaded or fails validation.
     *
     * @return array Backup result with the snapshot id.
     */
    // phpcs:ignore Squiz.Commenting.FunctionCommentThrowTag.Missing -- registry factory throws are MainWP_Exception, documented above.
    protected static function finish_incremental_backup( $website, $pTask, $artifacts, $progress ) { // phpcs:ignore -- NOSONAR - download loop with per-role validation.
        MainWP_System_Utility::get_wp_file_system();

        /**
         * WordPress filesystem instance.
         *
         * @global object
         */
        global $wp_filesystem;

        $dir = MainWP_System_Utility::get_mainwp_specific_dir( $website->id );
        $wp_filesystem->mkdir( $dir, 0750 );
        if ( ! $wp_filesystem->exists( $dir . 'index.php' ) ) {
            $wp_filesystem->touch( $dir . 'index.php' );
        }

        $run_dir = $dir . 'snapshot-' . MainWP_Utility::sanitize_file_name( (string) $progress->child_backup_task_id ) . '/';
        $wp_filesystem->mkdir( $run_dir, 0750 );

        $http_user_plain = MainWP_Credential_Storage::decrypt_credential( $website->http_user );
        $http_pass_plain = MainWP_Credential_Storage::decrypt_credential( $website->http_pass );

        $archives = array();
        $is_base  = false;

        foreach ( $artifacts as $artifact ) {
            $remote = isset( $artifact['file'] ) ? basename( (string) $artifact['file'] ) : '';
            $size   = isset( $artifact['size'] ) ? (int) $artifact['size'] : 0;
            $role   = isset( $artifact['role'] ) ? (string) $artifact['role'] : '';
            if ( '' === $remote || '' === $role ) {
                throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT, 'malformed artifact entry' ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
            }
            if ( 'full' === $role ) {
                $is_base = true;
            }

            $local = MainWP_Backup_File_Manager::normalize_filename( $run_dir . MainWP_Utility::sanitize_file_name( $remote ) );

            $already_complete = file_exists( $local ) && (int) filesize( $local ) === $size && $size > 0;
            if ( ! $already_complete ) {
                if ( ! MainWP_Backup_File_Manager::acquire_download_lock( $local, $website->id, $pTask->id ) ) {
                    throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING, 'download lock held for ' . basename( $local ) ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
                }
                try {
                    MainWP_Backup_File_Manager::download_backup_artifact( $website, $remote, $local, $size, $http_user_plain, $http_pass_plain );
                    if ( in_array( $role, array( 'part', 'db', 'full' ), true ) ) {
                        MainWP_Backup_File_Manager::validate_backup_file( $local, $size );
                    } elseif ( (int) filesize( $local ) !== $size ) {
                        throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT, 'expected ' . $size . ' bytes for ' . $remote ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
                    }
                } finally {
                    MainWP_Backup_File_Manager::release_download_lock( $local );
                }
            }

            $archives[] = array(
                'file' => basename( $local ),
                'size' => $size,
                'role' => $role,
                'path' => $local,
            );
        }

        $snapshots = MainWP_Backup_Snapshots::instance();

        // An interrupted earlier cycle may already have recorded this run.
        $latest        = $snapshots->get_latest_complete( (int) $website->id, (int) $pTask->id );
        $already_saved = null !== $latest && 0 === strpos( (string) $latest->manifest_path, $run_dir );

        if ( ! $already_saved ) {
            $parent_id   = $is_base || null === $latest ? null : (int) $latest->id;
            $snapshot_id = $snapshots->record_snapshot(
                array(
                    'task_id'       => (int) $pTask->id,
                    'site_id'       => (int) $website->id,
                    'type'          => $is_base ? 'full' : 'incremental',
                    'parent_id'     => $parent_id,
                    'archives'      => $archives,
                    'manifest_path' => $run_dir . 'files.list',
                )
            );
        } else {
            $snapshot_id = (int) $latest->id;
        }

        // Remote cleanup: every artifact except the manifest sidecar the
        // next incremental diffs against. Best effort - a leftover file on
        // the child never blocks a recorded backup.
        foreach ( $artifacts as $artifact ) {
            if ( ! isset( $artifact['role'] ) || 'manifest' === $artifact['role'] ) {
                continue;
            }
            try {
                MainWP_Connect::fetch_url_authed( $website, 'delete_backup', array( 'del' => basename( (string) $artifact['file'] ) ) );
            } catch ( \Exception $e ) {
                MainWP_Logger::instance()->warning_for_website( $website, 'backup', 'Could not remove a backup artifact from the child site: ' . $e->getMessage() );
            }
        }

        return array( 'snapshot_id' => $snapshot_id );
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

        if ( ! MainWP_Backup_Settings::is_native_backups_enabled() ) {
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
        } elseif ( 'files' === $pType && empty( $information['full'] ) ) {
            // A child whose legacy backup action predates the files type
            // answers with nothing on either slot; failing loudly beats
            // reporting an empty success.
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

    // phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber -- all throws are MainWP_Exception.
    /**
     * Method trigger_backup()
     *
     * Start a backup for a site and return a handle the caller can await
     * with get_backup_task_status(). Children running the resumable task
     * machine get an ad-hoc task created; the handle is persisted in the
     * adhoc_backup_task website option so a later call resumes the same
     * task instead of colliding with it. Children without the machine run
     * the synchronous legacy path to completion (artifact downloaded, the
     * completion hook fired) and the call returns null.
     *
     * @param int    $site_id Child site ID.
     * @param string $type    full|db Type of backup.
     *
     * @return string|null Task handle for resumable children, null when the synchronous fallback already completed.
     * @throws MainWP_Exception When the backup cannot be started.
     */
    public static function trigger_backup( int $site_id, string $type = 'full' ): ?string {
        // phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber
        if ( ! MainWP_Backup_Settings::is_native_backups_enabled() ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_LEGACY_DISABLED ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }

        if ( 'incremental' === $type ) {
            // Incremental chains hang off a scheduled task's snapshot
            // history; an ad-hoc run has no chain to extend.
            throw new MainWP_Exception( esc_html__( 'Incremental backups run from a scheduled backup task. Create a backup task with the incremental type instead.', 'mainwp' ) );
        }

        MainWP_Backup_Monitor::validate_backup_environment();

        $website = MainWP_DB::instance()->get_website_by_id( $site_id );
        if ( empty( $website ) ) {
            throw new MainWP_Exception( esc_html__( 'Site not found.', 'mainwp' ) );
        }

        $archive_ext = MainWP_Backup_Settings::get_current_archive_extension( $website );
        MainWP_Backup_Monitor::negotiate_backup_preflight( $website, $archive_ext );

        if ( ! MainWP_Backup_Monitor::child_supports_task_spine( $website ) ) {
            // Children without the task machine run the synchronous path to completion.
            $result = static::backup( $site_id, $type, '', '', 0, 0, 0, 0 );
            MainWP_Backup_File_Manager::backup_download_file( $site_id, $type, $result['url'], $result['local'] );

            /** This action is documented in class/backup/class-mainwp-backup-executor.php */
            do_action(
                'mainwp_native_backup_completed',
                $site_id,
                null,
                true,
                array(
                    'type'  => $type,
                    'local' => $result['local'],
                )
            );
            return null;
        }

        $persisted = static::read_adhoc_task( $website );
        if ( ! empty( $persisted['task_id'] ) && ( empty( $persisted['type'] ) || $persisted['type'] === $type ) ) {
            // An ad-hoc task is already in flight; resume it instead of colliding on the child.
            return $persisted['task_id'];
        }

        if ( 1 === (int) $website->maximumFileDescriptorsOverride ) {
            $file_descriptors_auto = ( 1 === (int) $website->maximumFileDescriptorsAuto );
            $file_descriptors      = $website->maximumFileDescriptors;
        } else {
            $file_descriptors_auto = get_option( 'mainwp_maximumFileDescriptorsAuto' );
            $file_descriptors      = get_option( 'mainwp_maximumFileDescriptors' );
            $file_descriptors      = ( false === $file_descriptors ? 150 : $file_descriptors );
        }

        $information = MainWP_Connect::fetch_url_authed(
            $website,
            'backup_task_create',
            array(
                'type'                  => $type,
                'exclude'               => '',
                'excludebackup'         => 0,
                'excludecache'          => 0,
                'excludenonwp'          => 0,
                'excludezip'            => 0,
                'ext'                   => $archive_ext,
                'file_descriptors_auto' => $file_descriptors_auto,
                'file_descriptors'      => $file_descriptors,
                'loadFilesBeforeZip'    => false,
                'pid'                   => time(),
                'f'                     => '',
            ),
            false,
            false,
            false
        );

        if ( isset( $information['error'] ) ) {
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error_Codes::sanitize_message( $information['error'] ) ), null, isset( $information['error_code'] ) ? $information['error_code'] : '' ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        if ( empty( $information['task_id'] ) || ! is_string( $information['task_id'] ) ) {
            throw new MainWP_Exception( esc_html__( 'The child site did not return a backup task id. Sync the child site and try again.', 'mainwp' ) );
        }

        $task_id = sanitize_text_field( $information['task_id'] );
        MainWP_DB::instance()->update_website_option(
            $website,
            static::ADHOC_TASK_OPTION,
            wp_json_encode(
                array(
                    'task_id' => $task_id,
                    'type'    => $type,
                    'started' => time(),
                )
            )
        );

        return $task_id;
    }

    /**
     * Method record_child_progress()
     *
     * Map a child task status payload onto the granular progress fields
     * of the wp_backup_progress row. The machine phase is mirrored
     * verbatim (the human label derives from it at render time), and the
     * optional counters land in the bytes/files columns. Fields absent
     * from the payload keep their stored values, so older children that
     * never report counters degrade to phase-only progress.
     *
     * @param int   $task_id Scheduled backup task id (wp_backup row).
     * @param int   $site_id Child site id.
     * @param array $payload Child status payload.
     *
     * @return void
     */
    public static function record_child_progress( int $task_id, int $site_id, array $payload ): void {
        $fields = array();

        if ( isset( $payload['phase'] ) && is_string( $payload['phase'] ) ) {
            $fields['child_phase'] = sanitize_key( $payload['phase'] );
        }

        $progress = isset( $payload['progress'] ) && is_array( $payload['progress'] ) ? $payload['progress'] : array();
        if ( isset( $progress['bytes_done'] ) ) {
            $fields['bytes_downloaded'] = max( 0, (int) $progress['bytes_done'] );
        }
        if ( isset( $progress['bytes_total'] ) ) {
            $fields['bytes_expected'] = max( 0, (int) $progress['bytes_total'] );
        }
        if ( isset( $progress['current_file'] ) ) {
            $fields['current_file'] = substr( sanitize_text_field( (string) $progress['current_file'] ), 0, 255 );
        }
        if ( isset( $progress['files_processed'] ) ) {
            $fields['files_processed'] = max( 0, (int) $progress['files_processed'] );
        }
        if ( isset( $progress['files_total'] ) ) {
            $fields['files_total'] = max( 0, (int) $progress['files_total'] );
        }

        if ( ! empty( $fields ) ) {
            MainWP_DB_Backup::instance()->update_backup_task_progress( $task_id, $site_id, $fields );
        }
    }

    /**
     * Method get_backup_task_status()
     *
     * Read or drive a resumable backup task. With no budget this is a
     * pure status read. With a budget the task is advanced one bounded
     * slice at a time until it reaches a terminal phase or the budget
     * runs out; on observed completion the artifact is downloaded into
     * the site's backup directory, the completion hook fires, and the
     * persisted ad-hoc handle is cleared.
     *
     * @param int    $site_id        Child site ID.
     * @param string $task_id        Task handle from trigger_backup().
     * @param int    $budget_seconds Wall-clock budget for driving slices; 0 reads without advancing.
     *
     * @return array Status array: status (running|completed|failed) and meta (phase, file, size, local, error, error_code).
     * @throws MainWP_Exception When the site is unknown or the child is unreachable.
     */
    public static function get_backup_task_status( int $site_id, string $task_id, int $budget_seconds = 0 ): array {
        $website = MainWP_DB::instance()->get_website_by_id( $site_id );
        if ( empty( $website ) ) {
            throw new MainWP_Exception( esc_html__( 'Site not found.', 'mainwp' ) );
        }

        if ( $budget_seconds <= 0 ) {
            $payload = MainWP_Connect::fetch_url_authed( $website, 'backup_task_status', array( 'task_id' => $task_id ), false, false, false );
            return static::map_task_status( $payload );
        }

        $deadline = time() + $budget_seconds;
        do {
            $payload = MainWP_Connect::fetch_url_authed( $website, 'backup_task_run', array( 'task_id' => $task_id ), false, false, false );
            $status  = static::map_task_status( $payload );
        } while ( 'running' === $status['status'] && time() < $deadline );

        if ( 'completed' === $status['status'] && ! empty( $status['meta']['file'] ) ) {
            $type  = isset( $payload['type'] ) && MainWP_Backup_Type::Database->value === $payload['type'] ? 'db' : 'full';
            $local = static::build_local_backup_file( $website, $type, $status['meta']['file'] );
            MainWP_Backup_File_Manager::backup_download_file( $site_id, $type, $status['meta']['file'], $local );
            if ( 'full' === $type ) {
                MainWP_Connect::fetch_url_authed( $website, 'delete_backup', array( 'del' => $status['meta']['file'] ) );
            }
            $status['meta']['local'] = $local;
        }

        if ( 'running' !== $status['status'] ) {
            /**
             * Fires when the dashboard observes a backup task reach a terminal phase.
             *
             * @param int         $site_id Child site ID.
             * @param string|null $task_id Backup task handle, null for the synchronous fallback path.
             * @param bool        $success Whether the backup completed.
             * @param array       $meta    Artifact and error details.
             */
            do_action( 'mainwp_native_backup_completed', $site_id, $task_id, 'completed' === $status['status'], $status['meta'] );
            MainWP_DB::instance()->update_website_option( $website, static::ADHOC_TASK_OPTION, '' );
        }

        return $status;
    }

    /**
     * Method has_recent_backup()
     *
     * Whether the site has a full backup archive on the dashboard newer
     * than the given age window.
     *
     * @param int $site_id      Child site ID.
     * @param int $max_age_days Freshness window in days (default 7).
     *
     * @return bool True when a recent full archive exists.
     */
    public static function has_recent_backup( int $site_id, int $max_age_days = 7 ): bool {
        $dir = MainWP_System_Utility::get_mainwp_specific_dir( $site_id );
        if ( empty( $dir ) || ! is_dir( $dir ) ) {
            return false;
        }

        $latest = - 1;
        $dh     = opendir( $dir );
        if ( $dh ) {
            while ( false !== ( $file = readdir( $dh ) ) ) {
                if ( '.' === $file || '..' === $file ) {
                    continue;
                }
                if ( MainWP_Backup_File_Manager::is_archive( $file ) && ! MainWP_Backup_File_Manager::is_sql_archive( $file ) ) {
                    $mtime = (int) filemtime( $dir . $file );
                    if ( $mtime > $latest ) {
                        $latest = $mtime;
                    }
                }
            }
            closedir( $dh );
        }

        return $latest >= ( time() - $max_age_days * DAY_IN_SECONDS );
    }

    /**
     * Method map_task_status()
     *
     * Map a child task payload onto the contract status shape. Unknown
     * phase strings from newer children stay running so callers keep
     * polling instead of failing.
     *
     * @param mixed $payload Child response payload.
     *
     * @return array Status array: status (running|completed|failed) and meta.
     */
    private static function map_task_status( mixed $payload ): array {
        if ( ! is_array( $payload ) ) {
            return array(
                'status' => 'failed',
                'meta'   => array( 'error' => esc_html__( 'The child site returned no task status.', 'mainwp' ) ),
            );
        }

        $meta = array();
        foreach ( array( 'phase', 'file', 'size', 'error', 'error_code' ) as $key ) {
            if ( isset( $payload[ $key ] ) ) {
                $meta[ $key ] = $payload[ $key ];
            }
        }

        if ( ! isset( $payload['phase'] ) ) {
            return array(
                'status' => 'failed',
                'meta'   => $meta,
            );
        }

        $phase  = MainWP_Backup_Task_Phase::tryFrom( (string) $payload['phase'] );
        $status = 'running';
        if ( MainWP_Backup_Task_Phase::Completed === $phase ) {
            $status = 'completed';
        } elseif ( MainWP_Backup_Task_Phase::Failed === $phase ) {
            $status = 'failed';
        }

        return array(
            'status' => $status,
            'meta'   => $meta,
        );
    }

    /**
     * Method build_local_backup_file()
     *
     * Build the dashboard-side path a task artifact downloads to, using
     * the same naming scheme as the scheduled backup flow.
     *
     * @param object $website     Website object.
     * @param string $type        full|db Type of backup.
     * @param string $remote_file Artifact filename reported by the child.
     *
     * @return string Absolute local file path.
     */
    private static function build_local_backup_file( object $website, string $type, string $remote_file ): string {
        $clean_url = $website->url;
        if ( '/' === substr( $clean_url, - 1 ) ) {
            $clean_url = substr( $clean_url, 0, - 1 );
        }
        $clean_url = str_replace( array( 'http://', 'https://', '/' ), array( '', '', '-' ), $clean_url );

        $dir     = MainWP_System_Utility::get_mainwp_specific_dir( $website->id );
        $fm_date = MainWP_Utility::sanitize_file_name( MainWP_Utility::date( get_option( 'date_format' ) ) );
        $fm_time = MainWP_Utility::sanitize_file_name( MainWP_Utility::date( get_option( 'time_format' ) ) );
        $prefix  = MainWP_Backup_Type::Database->value === $type ? 'db-' : 'full-';

        return MainWP_Backup_File_Manager::normalize_filename( $dir . $prefix . $clean_url . '-' . $fm_date . '-' . $fm_time . MainWP_Backup_File_Manager::get_real_extension( $remote_file ) );
    }

    /**
     * Method read_adhoc_task()
     *
     * Read the persisted ad-hoc task handle for a site.
     *
     * @param object $website Website object.
     *
     * @return array Persisted handle data, empty when none.
     */
    private static function read_adhoc_task( object $website ): array {
        $raw  = MainWP_DB::instance()->get_website_option( $website, static::ADHOC_TASK_OPTION );
        $data = json_decode( (string) $raw, true );
        return is_array( $data ) ? $data : array();
    }
}
