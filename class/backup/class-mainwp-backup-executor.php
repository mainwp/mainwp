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
     * Unknown identities cannot safely select a Child task owned by this run.
     *
     * @param object $website Child site.
     * @param string $child_task_id Confirmed Child identity.
     * @return array|null Child response, or null when no safe request exists.
     */
    public static function send_backup_task_cancel( object $website, string $child_task_id ): ?array {
        if ( '' === $child_task_id || self::is_creating_marker( $child_task_id ) ) {
            return null;
        }
        $response = MainWP_Connect::fetch_url_authed( $website, 'backup_task_cancel', array( 'task_id' => $child_task_id ), false, false, false );
        return is_array( $response ) ? $response : null;
    }

    /**
     * Keep diagnostic listener failures out of backup retry and cleanup paths.
     *
     * @param \Throwable $e             Original request failure.
     * @param float      $elapsed       Elapsed request time in seconds.
     * @param array      $payload_extra Request identity and dispatch state.
     * @param array      $context       Backup operation and site context.
     *
     * @return void
     */
    private static function record_thrown_request( \Throwable $e, float $elapsed, array $payload_extra, array $context ): void {
        try {
            $payload = array_merge(
                array(
                    'thrown'     => true,
                    'message'    => $e->getMessage(),
                    'code'       => (int) $e->getCode(),
                    'error_code' => $e instanceof MainWP_Exception ? (string) $e->get_message_error_code() : '',
                    'extra'      => $e instanceof MainWP_Exception ? (string) $e->get_message_extra() : '',
                ),
                $payload_extra
            );
            /** This action is documented in class/backup/class-mainwp-backup-executor.php */
            do_action( 'mainwp_backup_diag_poll', $payload, $elapsed, $context );
        } catch ( \Throwable $listener_error ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement -- diagnostics must not change backup control flow.
        }
    }

    /**
     * Backup indicators share a query for each policy during the request.
     *
     * @var array
     */
    private static $recent_native_backup_maps = array();

    /**
     * Website option holding the active ad-hoc backup task handle.
     *
     * @var string
     */
    const ADHOC_TASK_OPTION = 'adhoc_backup_task';

    /**
     * The option has no heartbeat; use the same two-hour window as
     * MainWP_Backup_Monitor gives a running marker without a lease.
     *
     * @var int
     */
    const ADHOC_TASK_ABANDON_SECONDS = 2 * HOUR_IN_SECONDS;

    /**
     * Prefix written into child_backup_task_id while a create request to
     * the child is in flight, so concurrent drivers of the same run see
     * the slot is spoken for before the round trip returns. Real child
     * task ids are child-minted opaque strings, so the prefix can never
     * collide with one.
     *
     * @var string
     */
    const CREATING_MARKER_PREFIX = '__creating_';

    /**
     * Update-gate verdict: fresh evidence satisfies the configured policy;
     * updates may proceed.
     *
     * @var string
     */
    const UPDATE_GATE_PASS = 'pass';

    /**
     * Update-gate verdict: fresh protection exists but has not verifiably
     * reached offsite storage while the policy requires it. Updates defer,
     * and no new fallback backup should be triggered - re-running it could
     * never improve provenance.
     *
     * @var string
     */
    const UPDATE_GATE_PENDING_OFFSITE = 'pending_offsite';

    /**
     * Update-gate verdict: no fresh backup evidence at all; the fallback
     * backup may be triggered.
     *
     * @var string
     */
    const UPDATE_GATE_MISSING = 'missing';

    /**
     * How long a creating marker is honored before a driver treats it as
     * abandoned. Comfortably above observed child create round trips
     * while short enough that a crashed driver clears within one polling
     * cycle instead of wedging the site.
     *
     * @var int
     */
    const CREATE_MARKER_TTL_SECONDS = 60;

    /**
     * Marker attached to an ERR_ALREADY_RUNNING exception when the child's
     * refusal is a genuinely foreign backup that has persisted past the create
     * window, not a same-run driver mid-create. The site loop reads it to
     * surface the collision instead of yielding.
     */
    const EXTRA_FOREIGN_ALREADY_RUNNING = 'foreign_backup';

    /**
     * How long a marker-less child already-running refusal must persist on a
     * run's progress row before it surfaces as a foreign collision. Set above
     * CREATE_MARKER_TTL_SECONDS so a same-run create-window reset race - which
     * re-marks the row within milliseconds - never crosses it.
     */
    const FOREIGN_REFUSAL_SURFACE_SECONDS = 90;

    /**
     * Wall-clock threshold, in seconds, after which a still-running backup
     * is treated as stalled rather than merely slow. Shared with the
     * scheduled backup list view (MainWP_Backup_List_View::column_details())
     * so a crashed scheduled run is flagged stale using the same threshold
     * this class already applies to a per-site "busy" child response,
     * instead of a second, independently-tuned constant.
     *
     * @var int
     */
    const STALL_THRESHOLD_SECONDS = HOUR_IN_SECONDS;

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
     * @param mixed     $siteid Child Site ID.
     * @param mixed     $pTask Task to perform.
     * @param mixed     $subfolder Sub folder to place backup.
     * @param bool|null $advanced Whether this call moved the site forward.
     * @param bool      $start_created_task Start a newly created task before returning to the fleet.
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
    public static function backup_site( $siteid, $pTask, $subfolder, &$advanced = null, bool $start_created_task = false ) {
        $advanced = false;
        // phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber
        // phpcs: ignore -- Current complexity is the only way to achieve desired results, pull request solutions appreciated.
        if ( ! MainWP_Backup_Settings::is_native_backups_enabled() ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_LEGACY_DISABLED ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }

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
        $website = MainWP_DB::instance()->get_website_by_id( $siteid );
        if ( empty( $website ) ) {
            throw new MainWP_Exception( esc_html__( 'Site not found.', 'mainwp' ) );
        }

        self::private_site_dir( (int) $siteid );
        MainWP_Backup_Monitor::validate_backup_environment();

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

                // The marker claims the create window on the shared row before
                // the round trip leaves: dtsFetched in the same write doubles
                // as its age stamp, and the token is unique per driver so a
                // later re-read can tell "my own untouched claim" from
                // "another driver took the slot".
                $creating_marker = self::CREATING_MARKER_PREFIX . wp_generate_password( 12, false );

                if ( empty( $backupTaskProgress ) ) {
                    MainWP_DB_Backup::instance()->add_backup_task_progress( $taskId, $website->id, array() );
                }

                MainWP_DB_Backup::instance()->update_backup_task_progress(
                    $taskId,
                    $website->id,
                    array(
                        // A same-second re-claim can put last_run one second ahead of the clock (GREATEST( ..., last_run + 1 )),
                        // and every current-run predicate compares dtsFetched to last_run.
                        'dtsFetched'             => max( time(), (int) $pTask->last_run ),
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
                        'child_backup_task_id'   => $creating_marker,
                        'child_phase'            => '',
                        'bytes_downloaded'       => 0,
                        'bytes_expected'         => 0,
                        'current_file'           => '',
                        'files_processed'        => 0,
                        'files_total'            => 0,
                    )
                );

                // Negotiate the archive format before building the request so a
                // large-site ZIP auto-switch reaches the child in $params['ext'].
                $archive_ext = MainWP_Backup_Settings::get_current_archive_extension( $website, $pTask );
                MainWP_Backup_Monitor::negotiate_backup_preflight( $website, $archive_ext, $pTask );
                if ( ! MainWP_Backup_Monitor::child_supports_task_spine( $website ) ) {
                    throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_CHILD_UPDATE_REQUIRED ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
                }

                // The requested filename takes its extension from the negotiated
                // format, so an auto-switched backup is not named .zip.
                if ( ! empty( $file ) ) {
                    $file .= ( 'db' === $type ? '.sql.' : '.' ) . $archive_ext;
                }

                $params = array(
                    'type'                  => $type,
                    'exclude'               => $exclude,
                    'excludebackup'         => $excludebackup,
                    'excludecache'          => $excludecache,
                    'excludenonwp'          => $excludenonwp,
                    'excludezip'            => $excludezip,
                    'ext'                   => $archive_ext,
                    'file_descriptors_auto' => $maximumFileDescriptorsAuto,
                    'file_descriptors'      => $maximumFileDescriptors,
                    'loadFilesBeforeZip'    => $loadFilesBeforeZip,
                    'pid'                   => $pid,
                    'f'                     => $file,
                );

                if ( in_array( $type, array( 'full', 'files' ), true ) ) {
                    $params['emit_manifest'] = '1';
                }

                if ( 'incremental' === $type ) {
                    $send           = self::resolve_incremental_send( $website, $pTask, $backupTaskProgress );
                    $params['type'] = $send['type'];
                    foreach ( $send['params'] as $send_key => $send_value ) {
                        $params[ $send_key ] = $send_value;
                    }
                    if ( '' !== $send['notice'] ) {
                        self::record_incremental_notice( $website, $taskId, $send['notice'] );
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
                            self::record_incremental_notice( $website, $taskId, MainWP_Backup_Error_Codes::message( MainWP_Backup_Error_Codes::ERR_MANIFEST_CHUNK_SEND_FAILED ) );
                            $params['type']          = 'full';
                            $params['emit_manifest'] = '1';
                            unset( $params['parent_snapshot_id'] );
                        }
                    }
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

                MainWP_Logger::instance()->debug_for_website( $website, 'backup', 'Requesting backup task: ' . MainWP_Utility::value_to_string( $params ) );

                // A create may replace the shared files.list before its snapshot is recorded.
                if ( 'incremental' === $params['type'] || ( in_array( $params['type'], array( 'full', 'files' ), true ) && ! empty( $params['emit_manifest'] ) ) ) {
                    if ( ! self::claim_manifest_writer( $website, $taskId, $creating_marker ) ) {
                        return false;
                    }
                }

                $diag_start  = microtime( true );
                $information = MainWP_Connect::fetch_url_authed( $website, 'backup_task_create', $params, false, false, false );

                /**
                 * Fires after each backup poll round trip with the full child
                 * response, so the diagnostics recorder can capture what the
                 * progress projection discards (sub_state, timing fields).
                 *
                 * @param array $payload  Full child response payload.
                 * @param float $duration HTTP round-trip duration in seconds.
                 * @param array $context  { op: create|run|status|pre_snapshot, site_id: int, task_id: int, run: int (create only) }.
                 */
                do_action(
                    'mainwp_backup_diag_poll',
                    is_array( $information ) ? $information : array(),
                    microtime( true ) - $diag_start,
                    array(
                        'op'      => 'create',
                        'site_id' => (int) $website->id,
                        'task_id' => (int) $taskId,
                        'run'     => (int) $pTask->last_run,
                    )
                );
            } catch ( MainWP_Exception $e ) {
                MainWP_Logger::instance()->warning_for_website( $website, 'backup', 'ERROR: ' . $e->getMessage() . ' (' . $e->get_message_extra() . ')' );
                self::record_thrown_request(
                    $e,
                    microtime( true ) - ( isset( $diag_start ) ? $diag_start : $start ),
                    array(
                        'sent' => isset( $diag_start ),
                    ),
                    array(
                        'op'      => 'create',
                        'site_id' => (int) $website->id,
                        'task_id' => (int) $taskId,
                        'run'     => (int) $pTask->last_run,
                    )
                );
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

                self::clear_creating_marker_if_mine( $taskId, (int) $website->id, $creating_marker );
                throw $e;
            }

            if ( ! MainWP_Backup_Monitor::confirm_native_lease() ) {
                // The slot went to another process while this round trip was
                // in flight, so a replacement driver may already own the
                // progress row. A task the child did create is real work
                // nobody else can name: hand it to whoever holds the row,
                // and when that is another driver's claim, owe the child a
                // cancel for the exact id instead of leaving it running with
                // no way to stop it. A marker this driver leaves behind is
                // reclaimed by the CREATE_MARKER_TTL_SECONDS window on the
                // next cycle; nothing here is terminal.
                self::hand_off_created_task_after_slot_loss( $website, $taskId, $creating_marker, $information );
                return false;
            }

            // A task-spine child refuses a duplicate start with a coded error;
            // classify only that stable code so wording cannot change behavior.
            $refused_already_running = isset( $information['error'] ) && (
                isset( $information['error_code'] ) && MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING === $information['error_code']
            );

            if ( $refused_already_running ) {
                // The initiate slice, progress poll, and continue cron can all
                // drive this run. A live same-run claim yields immediately. A
                // marker-less refusal is ambiguous with a create-window reset
                // race, so persist its first occurrence on this run's row and
                // surface it only after it outlives that window.
                $current           = MainWP_DB_Backup::instance()->get_backup_task_progress( $taskId, $website->id );
                $claimed_elsewhere = $current && (
                    ( ! empty( $current->child_backup_task_id ) && $current->child_backup_task_id !== $creating_marker )
                    || ! empty( $current->fetchResult )
                );
                if ( $claimed_elsewhere ) {
                    if ( ! empty( $current->foreign_refused_since ) ) {
                        MainWP_DB_Backup::instance()->update_backup_task_progress( $taskId, $website->id, array( 'foreign_refused_since' => 0 ) );
                    }
                    MainWP_Logger::instance()->debug_for_website( $website, 'backup', 'Child refused a duplicate backup start for this run; yielding to the driver that holds the slot.' );
                    /** This action is documented in pages/page-mainwp-manage-backups-handler.php */
                    do_action( 'mainwp_backup_diag_run_reinit', (int) $taskId, 'create_race_yield', (int) $pTask->last_run, isset( $pTask->completed ) ? (int) $pTask->completed : 0 );
                    return false;
                }

                // Tasks sharing a site interleave on the continue lane, so
                // the Child's one-task slot can be held by a sibling run that
                // will finish; that refusal is a delay, never a foreign
                // blocker, so the persistence clock must not run for it.
                if ( 0 < MainWP_DB_Backup::instance()->get_sibling_site_holder( (int) $website->id, (int) $taskId ) ) {
                    if ( $current && ! empty( $current->foreign_refused_since ) ) {
                        MainWP_DB_Backup::instance()->update_backup_task_progress( $taskId, $website->id, array( 'foreign_refused_since' => 0 ) );
                    }
                    self::reset_create_window_if_mine( $taskId, (int) $website->id, $creating_marker );
                    MainWP_Logger::instance()->debug_for_website( $website, 'backup', 'Child refused the backup start because another task of this Dashboard holds the site; waiting for it to finish.' );
                    /** This action is documented in pages/page-mainwp-manage-backups-handler.php */
                    do_action( 'mainwp_backup_diag_run_reinit', (int) $taskId, 'sibling_holds_site_yield', (int) $pTask->last_run, isset( $pTask->completed ) ? (int) $pTask->completed : 0 );
                    return false;
                }

                $refused_since = ( $current && (int) $current->foreign_refused_since >= (int) $pTask->last_run )
                    ? (int) $current->foreign_refused_since
                    : 0;

                if ( 0 === $refused_since ) {
                    MainWP_DB_Backup::instance()->update_backup_task_progress( $taskId, $website->id, array( 'foreign_refused_since' => time() ) );
                    self::reset_create_window_if_mine( $taskId, (int) $website->id, $creating_marker );
                    /** This action is documented in pages/page-mainwp-manage-backups-handler.php */
                    do_action( 'mainwp_backup_diag_run_reinit', (int) $taskId, 'foreign_refusal_pending', (int) $pTask->last_run, isset( $pTask->completed ) ? (int) $pTask->completed : 0 );
                    return false;
                }

                if ( ( time() - $refused_since ) < self::FOREIGN_REFUSAL_SURFACE_SECONDS ) {
                    self::reset_create_window_if_mine( $taskId, (int) $website->id, $creating_marker );
                    return false;
                }

                self::clear_creating_marker_if_mine( $taskId, (int) $website->id, $creating_marker );
                throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING, self::EXTRA_FOREIGN_ALREADY_RUNNING ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
            }

            // A failed mutex write previously surfaced as ERR_ALREADY_RUNNING
            // and received this same-run retry window. Without it, a transient
            // database failure would wait until the next schedule, with no
            // automatic retry for a manual backup.
            $refused_write_failed = isset( $information['error'] )
                && isset( $information['error_code'] )
                && MainWP_Backup_Error_Codes::ERR_MUTEX_WRITE_FAILED === $information['error_code'];

            if ( $refused_write_failed ) {
                $current       = MainWP_DB_Backup::instance()->get_backup_task_progress( $taskId, $website->id );
                $refused_since = ( $current && (int) $current->foreign_refused_since >= (int) $pTask->last_run )
                    ? (int) $current->foreign_refused_since
                    : 0;

                if ( 0 === $refused_since ) {
                    MainWP_DB_Backup::instance()->update_backup_task_progress( $taskId, $website->id, array( 'foreign_refused_since' => time() ) );
                    self::reset_create_window_if_mine( $taskId, (int) $website->id, $creating_marker );
                    /** This action is documented in pages/page-mainwp-manage-backups-handler.php */
                    do_action( 'mainwp_backup_diag_run_reinit', (int) $taskId, 'mutex_write_refusal_pending', (int) $pTask->last_run, isset( $pTask->completed ) ? (int) $pTask->completed : 0 );
                    return false;
                }

                if ( ( time() - $refused_since ) < self::FOREIGN_REFUSAL_SURFACE_SECONDS ) {
                    self::reset_create_window_if_mine( $taskId, (int) $website->id, $creating_marker );
                    return false;
                }

                self::clear_creating_marker_if_mine( $taskId, (int) $website->id, $creating_marker );
                throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_MUTEX_WRITE_FAILED ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
            }

            if ( empty( $information['error'] ) ) {
                if ( empty( $information['task_id'] ) || ! is_string( $information['task_id'] ) ) {
                    self::clear_creating_marker_if_mine( $taskId, (int) $website->id, $creating_marker );
                    throw new MainWP_Exception( esc_html__( 'The child site did not return a backup task id. Sync the child site and try again.', 'mainwp' ) );
                }

                $advanced = true;

                // Persist the task before starting its first slice. Deferring
                // until the next fleet pass can exceed the child's queued limit.
                self::hand_off_created_task_after_slot_loss( $website, $taskId, $creating_marker, $information );
                $backupTaskProgress = MainWP_DB_Backup::instance()->get_backup_task_progress( $taskId, $website->id );
                if ( ! $start_created_task || ! $backupTaskProgress || sanitize_text_field( $information['task_id'] ) !== $backupTaskProgress->child_backup_task_id ) {
                    return false;
                }
            } else {
                $advanced = true;

                // The create round trip resolved into a fetch result, so the
                // marker's window is over: clear it in the same write, or every
                // later cycle would read it as a create still in flight.
                $stored = MainWP_DB_Backup::instance()->update_backup_task_progress_if_marker(
                    $taskId,
                    $website->id,
                    $creating_marker,
                    array(
                        'fetchResult'           => wp_json_encode( $information ),
                        'child_backup_task_id'  => '',
                        'foreign_refused_since' => 0,
                    )
                );
                if ( $stored < 1 ) {
                    return false;
                }
                $backupTaskProgress = MainWP_DB_Backup::instance()->get_backup_task_progress( $taskId, $website->id );
            }
        } elseif ( self::is_creating_marker( $backupTaskProgress->child_backup_task_id ) ) {
            if ( ( time() - (int) $backupTaskProgress->dtsFetched ) < self::CREATE_MARKER_TTL_SECONDS ) {
                // Another driver's create request for this run has not
                // returned yet; give it the round trip it needs instead of
                // racing it with a second request.
                return false;
            }

            // Nobody cleared this marker inside its window, which can only
            // be a driver that died mid-request. Reset the row the same way
            // an escalated incremental retry does, so the next cycle creates
            // the task again from scratch.
            MainWP_DB_Backup::instance()->update_backup_task_progress(
                $taskId,
                $website->id,
                array(
                    'dtsFetched'           => 0,
                    'child_backup_task_id' => '',
                )
            );

            return false;
        }

        if ( empty( $backupTaskProgress->fetchResult ) && ! empty( $backupTaskProgress->child_backup_task_id ) ) {
            if ( ! MainWP_Backup_Monitor::confirm_native_lease() || ! MainWP_Backup_Monitor::confirm_lane_lease() ) {
                return false;
            }
            $temp       = false;
            $diag_start = microtime( true );
            try {
                $temp = MainWP_Connect::fetch_url_authed( $website, 'backup_task_run', array( 'task_id' => $backupTaskProgress->child_backup_task_id ), false, false, false );
            } catch ( \Exception $e ) {
                // The failed run call consumes a retry attempt below instead of being ignored.
                MainWP_Logger::instance()->warning_for_website( $website, 'backup', 'Backup task run failed: ' . $e->getMessage() );
                self::record_thrown_request(
                    $e,
                    microtime( true ) - $diag_start,
                    array(
                        'sent'    => true,
                        'task_id' => ! empty( $backupTaskProgress->child_backup_task_id ) ? (string) $backupTaskProgress->child_backup_task_id : '',
                    ),
                    array(
                        'op'      => 'run',
                        'site_id' => (int) $website->id,
                        'task_id' => (int) $taskId,
                    )
                );
            }

            if ( is_array( $temp ) ) {
                /** This action is documented in class/backup/class-mainwp-backup-executor.php */
                do_action(
                    'mainwp_backup_diag_poll',
                    $temp,
                    microtime( true ) - $diag_start,
                    array(
                        'op'      => 'run',
                        'site_id' => (int) $website->id,
                        'task_id' => (int) $taskId,
                    )
                );
            }

            if ( ! MainWP_Backup_Monitor::confirm_native_lease() || ! MainWP_Backup_Monitor::refresh_lane_lease() ) {
                return false; // Same stop as a slice that is still working: the row is untouched and the next cycle runs it again.
            }

            if ( ! empty( $temp ) && 'incremental' === $type
                && isset( $temp['error_code'] ) && MainWP_Backup_Error_Codes::ERR_INCREMENTAL_BASE_MISSING === $temp['error_code']
                && self::requeue_incremental_after_base_missing( $website, $pTask, $backupTaskProgress ) ) {
                return false; // Requeued: the next cycle re-creates the task with the escalated send.
            }

            if ( ! empty( $temp ) && isset( $temp['error'] )
                && isset( $temp['error_code'] ) && MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING === $temp['error_code'] ) {
                // The slice runner hit the child's other backup lock (a
                // legacy one-shot mid-run, usually short-lived). Failing the
                // job on its first slice would abandon the created task on
                // the child for its whole wall clock, so retry briefly on the
                // create path's persistence clock; only a refusal that
                // outlives the window surfaces as the foreign blocker it is -
                // the FOREIGN extra keeps the site-loop catch from yielding
                // it forever as a same-run collision.
                $run_refused_since = ( (int) $backupTaskProgress->foreign_refused_since >= (int) $pTask->last_run )
                    ? (int) $backupTaskProgress->foreign_refused_since
                    : 0;

                if ( 0 === $run_refused_since ) {
                    MainWP_DB_Backup::instance()->update_backup_task_progress( $taskId, $website->id, array( 'foreign_refused_since' => time() ) );
                    /** This action is documented in pages/page-mainwp-manage-backups-handler.php */
                    do_action( 'mainwp_backup_diag_run_reinit', (int) $taskId, 'run_refusal_pending', (int) $pTask->last_run, isset( $pTask->completed ) ? (int) $pTask->completed : 0 );
                    return false;
                }

                if ( ( time() - $run_refused_since ) < self::FOREIGN_REFUSAL_SURFACE_SECONDS ) {
                    return false;
                }

                throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING, self::EXTRA_FOREIGN_ALREADY_RUNNING ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
            }

            if ( ! empty( $temp ) && isset( $temp['error'] ) ) {
                if ( isset( $temp['phase'] ) && is_string( $temp['phase'] ) && sanitize_key( $temp['phase'] ) !== (string) $backupTaskProgress->child_phase ) {
                    $advanced = true;
                }
                throw new MainWP_Exception( esc_html( MainWP_Backup_Error_Codes::sanitize_message( $temp['error'] ) ), null, isset( $temp['error_code'] ) ? $temp['error_code'] : '' ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }

            if ( ! empty( $temp ) && isset( $temp['phase'] ) ) {
                // Unknown phase strings from newer children stay null and keep polling.
                $phase = MainWP_Backup_Task_Phase::tryFrom( (string) $temp['phase'] );

                // Dashboard sees only the Child's projected progress columns. Internal cursor movement within
                // one table or file still reads as not advanced if phase, counters and current_file stay the same.
                // A stored Child progress token would close this gap.
                $advanced = is_string( $temp['phase'] ) && sanitize_key( $temp['phase'] ) !== (string) $backupTaskProgress->child_phase;
                $progress = isset( $temp['progress'] ) && is_array( $temp['progress'] ) ? $temp['progress'] : array();
                if ( isset( $progress['current_file'] ) && substr( sanitize_text_field( (string) $progress['current_file'] ), 0, 255 ) !== (string) $backupTaskProgress->current_file ) {
                    $advanced = true;
                }
                $counters = array(
                    'bytes_downloaded' => 'bytes_done',
                    'bytes_expected'   => isset( $progress['total_bytes'] ) ? 'total_bytes' : 'bytes_total',
                    'files_processed'  => isset( $progress['files_scanned'] ) ? 'files_scanned' : 'files_processed',
                    'files_total'      => 'files_total',
                );
                foreach ( $counters as $column => $source ) {
                    if ( isset( $progress[ $source ] ) && max( 0, (int) $progress[ $source ] ) !== (int) $backupTaskProgress->$column ) {
                        $advanced = true;
                    }
                }

                self::record_child_progress( $taskId, (int) $website->id, $temp );

                if ( (int) $backupTaskProgress->foreign_refused_since > 0 ) {
                    // The lock holder is gone and the slice ran: stop the
                    // persistence clock so a later, unrelated refusal starts
                    // its own window instead of inheriting this one's age.
                    MainWP_DB_Backup::instance()->update_backup_task_progress( $taskId, $website->id, array( 'foreign_refused_since' => 0 ) );
                }

                if ( MainWP_Backup_Task_Phase::FAILED === $phase ) {
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

                if ( MainWP_Backup_Task_Phase::COMPLETED !== $phase ) {
                    return false; // The task is still working; the next cycle advances it again.
                }

                $information = array();
                if ( 'incremental' === $type ) {
                    // Incremental tasks complete with a role-tagged artifact
                    // list; the shared single-file download block must not
                    // claim the run.
                    $information['full']      = false;
                    $information['db']        = false;
                    $information['artifacts'] = isset( $temp['artifacts'] ) && is_array( $temp['artifacts'] ) ? $temp['artifacts'] : array();
                } elseif ( 'full' === $type || 'files' === $type ) {
                    // A files-only run produces an archive like a full run,
                    // so it lands in the same download slot.
                    $information['full']      = isset( $temp['file'] ) ? $temp['file'] : false;
                    $information['db']        = false;
                    $information['artifacts'] = isset( $temp['artifacts'] ) && is_array( $temp['artifacts'] ) ? $temp['artifacts'] : array();
                } else {
                    $information['full'] = false;
                    $information['db']   = isset( $temp['file'] ) ? $temp['file'] : false;
                }
                $information['size'] = isset( $temp['size'] ) ? $temp['size'] : 0;

                // The child's Woo order-storage facts ride along so the
                // snapshot record step can stamp them without another
                // round trip. Absent on files-only runs and older children.
                if ( isset( $temp['woo'] ) && is_array( $temp['woo'] ) ) {
                    $information['woo'] = $temp['woo'];
                }

                // Concurrent drivers can poll the same completed child task;
                // the conditional claim makes exactly one of them the
                // consumer. A losing driver yields without firing a second
                // completion event and never enters the download phase
                // against an artifact the winner is already consuming.
                if ( ! MainWP_DB_Backup::instance()->claim_backup_fetch_result( $taskId, $website->id, wp_json_encode( $information ) ) ) {
                    MainWP_Logger::instance()->debug_for_website( $website, 'backup', 'Another driver consumed this completed backup task; yielding.' );
                    return false;
                }
                $advanced           = true;
                $backupTaskProgress = MainWP_DB_Backup::instance()->get_backup_task_progress( $taskId, $website->id );

                if ( 'incremental' !== $type ) {
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
                }
            } elseif ( 5 > $backupTaskProgress->attempts ) {
                $backupTaskProgress = MainWP_DB_Backup::instance()->update_backup_task_progress( $taskId, $website->id, array( 'attempts' => $backupTaskProgress->attempts + 1 ) );
                return false;
            } else {
                throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_RETRY_EXHAUSTED ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
            }
        }

        if ( false === $information ) {
            $information = $backupTaskProgress->fetchResult;
        }

        if ( ! isset( $information['error'] ) && empty( $backupTaskProgress->child_backup_task_id ) && ! empty( $pTask->id ) ) {
            self::persist_legacy_snapshot_token( $website, $pTask, $backupTaskProgress );
        }

        if ( isset( $information['error'] ) ) {
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error_Codes::sanitize_message( $information['error'] ) ), null, isset( $information['error_code'] ) ? $information['error_code'] : '' ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        } elseif ( 'incremental' === $type && ! empty( $information['artifacts'] ) ) {
            $snapshot_id = 0;
            try {
                $result = self::finish_incremental_backup( $website, $pTask, $information['artifacts'], $backupTaskProgress, $snapshot_id, $advanced );
            } catch ( \Throwable $e ) {
                /** This action is documented in class/backup/class-mainwp-backup-executor.php */
                do_action(
                    'mainwp_native_backup_completed',
                    (int) $website->id,
                    $backupTaskProgress->child_backup_task_id,
                    false,
                    array(
                        'error'       => $e->getMessage(),
                        'error_code'  => $e instanceof MainWP_Exception ? $e->get_message_error_code() : $e->getCode(),
                        'snapshot_id' => $snapshot_id,
                    )
                );
                throw $e;
            }
            return $result;
        } elseif ( 'incremental' === $type && empty( $information['full'] ) && empty( $information['db'] ) ) {
            // A spine child completing an incremental run without artifacts
            // produced nothing downloadable.
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        } elseif ( 'db' === $type && empty( $information['db'] ) ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_DB_QUERY_FAILED ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        } elseif ( ( 'full' === $type || 'files' === $type ) && empty( $information['full'] ) ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        } elseif ( isset( $information['db'] ) ) {
            MainWP_System_Utility::get_wp_file_system();

            /**
             * WordPress filesystem instance.
             *
             * @global object
             */
            global $wp_filesystem;

            $dir = self::private_site_dir( (int) $website->id );

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

            if ( 1 !== (int) $backupTaskProgress->removedFiles && MainWP_DB_Backup::instance()->claim_backup_file_sweep( $taskId, $website->id ) ) {
                $dbBackups   = array();
                $fullBackups = array();

                // Files recorded by complete backup snapshots belong to
                // snapshot retention; the sweep neither counts nor deletes
                // them.
                $protected = MainWP_Backup_Snapshots::instance()->get_protected_paths_for_site( (int) $website->id );

                // Tasks sharing a site interleave on the continue lane, so another open
                // run's unrecorded downloads must survive this task's sweep too.
                foreach ( MainWP_DB_Backup::instance()->get_open_run_download_paths( (int) $website->id ) as $path ) {
                    $protected[ MainWP_Backup_Snapshots::normalise_path( $path ) ] = true;
                }

                // The current run's own downloaded artifacts are unrecorded
                // until their snapshot lands, so the sweep must read them
                // fresh and protect them: this caller's row view can predate
                // another driver's download, and sweeping that file away is
                // how a validated backup vanished before its record.
                $own_row = MainWP_DB_Backup::instance()->get_backup_task_progress( $taskId, $website->id );
                foreach ( array( $own_row->downloadedDB, $own_row->downloadedFULL ) as $own_path ) {
                    if ( '' !== (string) $own_path ) {
                        $protected[ MainWP_Backup_Snapshots::normalise_path( (string) $own_path ) ] = true;
                    }
                }

                foreach ( array( MainWP_Backup_Private_Root::site_path( (int) $website->id ), MainWP_Backup_Private_Root::legacy_site_dir( (int) $website->id ) ) as $sweep_dir ) {
                    if ( ! is_wp_error( $sweep_dir ) && $wp_filesystem->exists( $sweep_dir ) ) {
                        // The legacy dir gets no cleanup elsewhere, and a stale sidecar
                        // left there would shield its file from retention for good.
                        MainWP_Backup_File_Manager::cleanup_stale_download_locks( $sweep_dir );
                        $dh = opendir( $sweep_dir );
                        if ( $dh ) {
                            while ( false !== ( $file = readdir( $dh ) ) ) {
                                if ( '.' !== $file && '..' !== $file ) {
                                    $theFile = $sweep_dir . $file;
                                    if ( isset( $protected[ MainWP_Backup_Snapshots::normalise_path( $theFile ) ] ) ) {
                                        continue;
                                    }
                                    // Stale locks were just cleared, so a sidecar still here is a live
                                    // download that may have started after the protection snapshot.
                                    if ( file_exists( $theFile . '.downloading' ) ) {
                                        continue;
                                    }
                                    if ( $information['db'] && MainWP_Backup_File_Manager::is_sql_file( $file ) ) {
                                        $dbBackups[ filemtime( $theFile ) . $theFile ] = $theFile;
                                    }

                                    if ( $information['full'] && MainWP_Backup_File_Manager::is_archive( $file ) && ! MainWP_Backup_File_Manager::is_sql_archive( $file ) ) {
                                        $fullBackups[ filemtime( $theFile ) . $theFile ] = $theFile;
                                    }
                                }
                            }
                            closedir( $dh );
                        }
                    }
                }
                krsort( $dbBackups );
                krsort( $fullBackups );

                // Another lane worker can record a download path, or record its snapshot
                // and close its run, while the scan runs, so re-read both right before
                // deleting; only microseconds remain until unlink.
                $protected += MainWP_Backup_Snapshots::instance()->get_protected_paths_for_site( (int) $website->id );
                $own_row    = MainWP_DB_Backup::instance()->get_backup_task_progress( $taskId, $website->id );
                if ( null === $own_row ) {
                    return false; // The task was deleted mid-sweep; its run has nothing left to finish.
                }
                foreach ( array_merge( MainWP_DB_Backup::instance()->get_open_run_download_paths( (int) $website->id ), array( (string) $own_row->downloadedDB, (string) $own_row->downloadedFULL ) ) as $path ) {
                    if ( '' !== $path ) {
                        $protected[ MainWP_Backup_Snapshots::normalise_path( $path ) ] = true;
                    }
                }

                $cnt = 0;
                foreach ( $dbBackups as $key => $dbBackup ) {
                    // A path protected only by the re-read keeps no retention slot, as if
                    // the first snapshot had seen it.
                    if ( isset( $protected[ MainWP_Backup_Snapshots::normalise_path( $dbBackup ) ] ) ) {
                        continue;
                    }
                    ++$cnt;
                    if ( $cnt >= $maxBackups ) {
                        $wp_filesystem->delete( $dbBackup );
                    }
                }

                $cnt = 0;
                foreach ( $fullBackups as $key => $fullBackup ) {
                    // A path protected only by the re-read keeps no retention slot, as if
                    // the first snapshot had seen it.
                    if ( isset( $protected[ MainWP_Backup_Snapshots::normalise_path( $fullBackup ) ] ) ) {
                        continue;
                    }
                    ++$cnt;
                    if ( $cnt >= $maxBackups ) {
                        $wp_filesystem->delete( $fullBackup );
                    }
                }
                // The claim already stamped removedFiles; the fresh row
                // read for the protection set doubles as the view refresh.
                $backupTaskProgress = $own_row;
            }

            $localBackupFile = null;

            $fm_date = MainWP_Utility::sanitize_file_name( MainWP_Utility::date( get_option( 'date_format' ) ) );
            $fm_time = MainWP_Utility::sanitize_file_name( MainWP_Utility::date( get_option( 'time_format' ) ) );

            $what            = null;
            $regexBackupFile = null;

            if ( $information['db'] ) {
                $what            = 'db';
                $regexBackupFile = 'db-' . $websiteCleanUrl . '-(.*)-(.*).sql(\.zip|\.tar|\.tar\.gz|\.tar\.bz2)?';
                if ( '' === self::resumable_download_path( $backupTaskProgress->downloadedDB, (int) $backupTaskProgress->downloadedDBComplete, (int) $website->id ) ) {
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
                        try {
                            $dl_start = microtime( true );
                            // MWP-1548: decrypt at the boundary so download_to_file sees plaintext credentials.
                            $http_user_plain = MainWP_Credential_Storage::decrypt_credential( $website->http_user );
                            $http_pass_plain = MainWP_Credential_Storage::decrypt_credential( $website->http_pass );
                            MainWP_Backup_File_Manager::download_backup_artifact( $website, $information['db'], $localBackupFile, $information['size'], $http_user_plain, $http_pass_plain );
                            MainWP_Backup_File_Manager::validate_backup_file( $localBackupFile, $information['size'] );
                        } catch ( \Exception $e ) {
                            self::record_thrown_request(
                                $e,
                                microtime( true ) - $dl_start,
                                array(
                                    'sent'     => true,
                                    'task_id'  => ! empty( $backupTaskProgress->child_backup_task_id ) ? (string) $backupTaskProgress->child_backup_task_id : '',
                                    'artifact' => 'db',
                                    'attempt'  => (int) $backupTaskProgress->attempts,
                                ),
                                array(
                                    'op'      => 'download',
                                    'site_id' => (int) $website->id,
                                    'task_id' => (int) $taskId,
                                )
                            );
                            // A transient transfer failure leaves a fully
                            // resumable row; it consumes a retry attempt and
                            // yields instead of failing the site terminally.
                            if ( 5 > $backupTaskProgress->attempts ) {
                                MainWP_DB_Backup::instance()->update_backup_task_progress( $taskId, $website->id, array( 'attempts' => $backupTaskProgress->attempts + 1 ) );
                                $extra = $e instanceof MainWP_Exception ? (string) $e->get_message_extra() : '';
                                MainWP_Logger::instance()->warning_for_website( $website, 'backup', 'Backup download failed; the next cycle retries it: ' . $e->getMessage() . ( '' !== $extra ? ' (' . $extra . ')' : '' ) );
                                return false;
                            }
                            throw $e;
                        }
                        // Flag before delete, as for the full archive below.
                        $before             = $backupTaskProgress;
                        $backupTaskProgress = MainWP_DB_Backup::instance()->update_backup_task_progress( $taskId, $website->id, array( 'downloadedDBComplete' => 1 ) );
                        $advanced           = true;

                        if ( self::download_recorded( $backupTaskProgress, $before, 'downloadedDB', $localBackupFile ) ) {
                            try {
                                MainWP_Connect::fetch_url_authed( $website, 'delete_backup', array( 'del' => $information['db'] ) );
                            } catch ( \Exception $e ) {
                                MainWP_Logger::instance()->warning_for_website( $website, 'backup', 'Remote backup cleanup failed after a validated download: ' . $e->getMessage() );
                            }
                        } else {
                            $backupTaskProgress = self::keep_child_copy_of_unrecorded_download( $website, $before, 'downloadedDB', $localBackupFile );
                        }
                    } finally {
                        MainWP_Backup_File_Manager::release_download_lock( $localBackupFile );
                    }
                }
            }

            if ( $information['full'] ) {
                $realExt         = MainWP_Backup_File_Manager::get_real_extension( $information['full'] );
                $what            = 'full';
                $regexBackupFile = 'full-' . $websiteCleanUrl . '-(.*)-(.*).(zip|tar|tar.gz|tar.bz2)';
                if ( '' === self::resumable_download_path( $backupTaskProgress->downloadedFULL, (int) $backupTaskProgress->downloadedFULLComplete, (int) $website->id ) ) {
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
                        try {
                            $dl_start = microtime( true );
                            // MWP-1548: decrypt at the boundary so download_to_file sees plaintext credentials.
                            $http_user_plain = MainWP_Credential_Storage::decrypt_credential( $website->http_user );
                            $http_pass_plain = MainWP_Credential_Storage::decrypt_credential( $website->http_pass );
                            MainWP_Backup_File_Manager::download_backup_artifact( $website, $information['full'], $localBackupFile, $information['size'], $http_user_plain, $http_pass_plain );
                            MainWP_Backup_File_Manager::validate_backup_file( $localBackupFile, $information['size'] );
                        } catch ( \Exception $e ) {
                            self::record_thrown_request(
                                $e,
                                microtime( true ) - $dl_start,
                                array(
                                    'sent'     => true,
                                    'task_id'  => ! empty( $backupTaskProgress->child_backup_task_id ) ? (string) $backupTaskProgress->child_backup_task_id : '',
                                    'artifact' => 'full',
                                    'attempt'  => (int) $backupTaskProgress->attempts,
                                ),
                                array(
                                    'op'      => 'download',
                                    'site_id' => (int) $website->id,
                                    'task_id' => (int) $taskId,
                                )
                            );
                            // A transient transfer failure leaves a fully
                            // resumable row; it consumes a retry attempt and
                            // yields instead of failing the site terminally.
                            if ( 5 > $backupTaskProgress->attempts ) {
                                MainWP_DB_Backup::instance()->update_backup_task_progress( $taskId, $website->id, array( 'attempts' => $backupTaskProgress->attempts + 1 ) );
                                $extra = $e instanceof MainWP_Exception ? (string) $e->get_message_extra() : '';
                                MainWP_Logger::instance()->warning_for_website( $website, 'backup', 'Backup download failed; the next cycle retries it: ' . $e->getMessage() . ( '' !== $extra ? ' (' . $extra . ')' : '' ) );
                                return false;
                            }
                            throw $e;
                        }

                        // The complete flag lands before the remote delete
                        // and inside the lock: a driver dying between the two
                        // leaves a validated resumable download, and no other
                        // driver can slip in after the remote artifact is
                        // gone and destroy the local copy with a re-download.
                        $before             = $backupTaskProgress;
                        $backupTaskProgress = MainWP_DB_Backup::instance()->update_backup_task_progress( $taskId, $website->id, array( 'downloadedFULLComplete' => 1 ) );
                        $advanced           = true;

                        if ( self::download_recorded( $backupTaskProgress, $before, 'downloadedFULL', $localBackupFile ) ) {
                            try {
                                MainWP_Connect::fetch_url_authed( $website, 'delete_backup', array( 'del' => $information['full'] ) );
                            } catch ( \Exception $e ) {
                                // Child-side litter, not a run failure: the
                                // artifact is already validated locally.
                                MainWP_Logger::instance()->warning_for_website( $website, 'backup', 'Remote backup cleanup failed after a validated download: ' . $e->getMessage() );
                            }
                        } else {
                            $backupTaskProgress = self::keep_child_copy_of_unrecorded_download( $website, $before, 'downloadedFULL', $localBackupFile );
                        }
                    } finally {
                        MainWP_Backup_File_Manager::release_download_lock( $localBackupFile );
                    }
                }
            }

            if ( ! MainWP_Backup_Monitor::confirm_native_lease() ) {
                return false; // A snapshot is what retires the site, so it may not be written under a slot this driver no longer owns.
            }

            self::record_run_snapshot_locked( $website, $pTask, $backupTaskProgress, $localBackupFile );

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

    // phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing -- The exception factory returns MainWP_Exception.
    /**
     * Persist legacy identity before downloading so retries cannot mint another run.
     *
     * @param object $website Child site.
     * @param object $task Backup task.
     * @param object $progress Progress row, refreshed after the conditional write.
     * @return void
     * @throws MainWP_Exception When the token cannot be persisted.
     */
    private static function persist_legacy_snapshot_token( $website, $task, &$progress ) {
        // phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.Missing
        global $wpdb;

        if ( ! empty( $progress->fetchResult['snapshot_run_token'] ) ) {
            return;
        }

        $table = MainWP_DB::instance()->get_table_name( 'wp_backup_progress' );
        $raw   = $wpdb->get_var( $wpdb->prepare( "SELECT fetchResult FROM {$table} WHERE task_id = %d AND wp_id = %d AND dtsFetched = %d AND child_backup_task_id = ''", $task->id, $website->id, $progress->dtsFetched ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- Table name from the database helper; conditional persistence needs the raw JSON.
        $fetch = is_string( $raw ) ? json_decode( $raw, true ) : null;
        if ( is_array( $fetch ) ) {
            if ( empty( $fetch['snapshot_run_token'] ) ) {
                $fetch['snapshot_run_token'] = wp_generate_uuid4();
                // Compare the old payload so a racing downloader adopts the first token.
                $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Compare the persisted payload atomically.
                    $table,
                    array( 'fetchResult' => wp_json_encode( $fetch ) ),
                    array(
                        'task_id'              => (int) $task->id,
                        'wp_id'                => (int) $website->id,
                        'dtsFetched'           => (int) $progress->dtsFetched,
                        'child_backup_task_id' => '',
                        'fetchResult'          => $raw,
                    ),
                    array( '%s' ),
                    array( '%d', '%d', '%d', '%s', '%s' )
                );
            }
            $fresh = MainWP_DB_Backup::instance()->get_backup_task_progress( $task->id, $website->id );
            if ( $fresh && (int) $fresh->dtsFetched === (int) $progress->dtsFetched && ! empty( $fresh->fetchResult['snapshot_run_token'] ) && empty( $fresh->child_backup_task_id ) ) {
                $progress = $fresh;
                return;
            }
        }
        throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_RECORD_FAILED, 'could not persist legacy run identity' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
    }

    // phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing -- registry factory throws MainWP_Exception.
    /**
     * Reject a shared manifest replaced between completion and download.
     *
     * @param array  $artifact Child artifact metadata.
     * @param string $path Downloaded manifest.
     * @throws MainWP_Exception When the recorded digest is malformed or the local file cannot be hashed.
     * @return bool Whether the artifact has a verified digest.
     */
    private static function verify_manifest_artifact( $artifact, $path ) {
        // phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.Missing
        if ( ! array_key_exists( 'sha256', $artifact ) || '' === $artifact['sha256'] ) {
            return false;
        }
        if ( ! is_string( $artifact['sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $artifact['sha256'] ) ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT, 'manifest digest mismatch' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry factory.
        }
        $actual = hash_file( 'sha256', $path );
        if ( false === $actual ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT, 'manifest digest unavailable' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry factory.
        }
        // Another task can replace the shared list without invalidating this run's archives.
        return hash_equals( $artifact['sha256'], $actual );
    }

    // phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing -- registry factory throws MainWP_Exception.
    /**
     * Persist this create's token without replacing its incremental retry markers.
     *
     * @param object $website Website row.
     * @param int    $task_id Dashboard task id.
     * @param string $marker Creating marker owned by this driver.
     * @throws MainWP_Exception If either durable write fails.
     * @return bool Whether this driver still owns the creating marker.
     */
    private static function claim_manifest_writer( $website, $task_id, $marker ) {
        // phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.Missing
        global $wpdb;
        $db                        = MainWP_DB::instance();
        $progress                  = MainWP_DB_Backup::instance()->get_backup_task_progress( $task_id, $website->id );
        $state                     = json_decode( (string) ( $progress->last_error ?? '' ), true );
        $state                     = is_array( $state ) ? $state : array();
        $token                     = 'pending:' . (int) $task_id . ':' . wp_generate_uuid4();
        $state['manifest_pending'] = $token;
        $written                   = MainWP_DB_Backup::instance()->update_backup_task_progress_if_marker( $task_id, $website->id, $marker, array( 'last_error' => wp_json_encode( $state ) ) );
        if ( '' !== $wpdb->last_error ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_RECORD_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry factory.
        }
        if ( 1 !== $written ) {
            return false;
        }
        $db->update_website_option( $website, 'native_manifest_snapshot', $token );
        if ( '' !== $wpdb->last_error ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_RECORD_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry factory.
        }
        $recorded_token = $db->get_website_option( (int) $website->id, 'native_manifest_snapshot' );
        if ( '' !== $wpdb->last_error ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_RECORD_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry factory.
        }
        if ( $token !== $recorded_token ) {
            self::reset_create_window_if_mine( $task_id, (int) $website->id, $marker );
            return false;
        }
        return true;
    }

    /**
     * Publish ownership only while this exact create still owns the option.
     *
     * @param object $website Website row.
     * @param object $progress Completed run's progress.
     * @param int    $snapshot_id Recorded snapshot id.
     */
    private static function record_manifest_writer( $website, $progress, $snapshot_id ) {
        global $wpdb;
        $state = json_decode( (string) ( $progress->last_error ?? '' ), true );
        $token = $state['manifest_pending'] ?? '';
        if ( ! is_string( $token ) || 0 !== strpos( $token, 'pending:' ) ) {
            return;
        }
        $table = MainWP_DB::instance()->get_table_name( 'wp_options' );
        $saved = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET value = %s WHERE wpid = %d AND name = %s AND BINARY value = BINARY %s", (string) $snapshot_id, $website->id, 'native_manifest_snapshot', $token ) ); // phpcs:ignore WordPress.DB -- exact conditional ownership write, table name from DB helper.
        if ( false === $saved ) {
            MainWP_Logger::instance()->warning_for_website( $website, 'backup', 'Manifest writer was not recorded; the next incremental will push its manifest.' );
            return;
        }
    }

    /**
     * Keep child identities separate from tokens for older backup protocols.
     *
     * @param object $website Child site.
     * @param object $task Backup task.
     * @param object $progress Persisted progress row.
     * @return string|null Run identity, or null when no durable identity exists.
     */
    private static function snapshot_run_key( $website, $task, $progress ): ?string {
        $prefix = (int) $website->id . ':' . (int) $task->id;
        if ( ! empty( $progress->child_backup_task_id ) && ! self::is_creating_marker( $progress->child_backup_task_id ) ) {
            return $prefix . ':c:' . $progress->child_backup_task_id;
        }
        if ( ! empty( $progress->fetchResult['snapshot_run_token'] ) ) {
            return $prefix . ':l:' . $progress->fetchResult['snapshot_run_token'];
        }
        return null;
    }

    /**
     * Whether a child_backup_task_id value is a creating marker rather than
     * a real child task id.
     *
     * @param mixed $value Stored child_backup_task_id value.
     *
     * @return bool True when the value is a creating marker.
     */
    public static function is_creating_marker( $value ): bool {
        return is_string( $value ) && 0 === strpos( $value, self::CREATING_MARKER_PREFIX );
    }

    /**
     * Clear this driver's own creating marker from the progress row.
     *
     * One conditional write, keyed on the driver's unique token, so a
     * concurrent driver's claim (a real task id or its own marker) is never
     * clobbered by a loser cleaning up after its refused create - and no
     * window opens between reading the row and writing it.
     *
     * @param int    $task_id Task ID.
     * @param int    $site_id Site ID.
     * @param string $marker  This driver's creating marker token.
     *
     * @return void
     */
    private static function clear_creating_marker_if_mine( $task_id, $site_id, $marker ) {
        MainWP_DB_Backup::instance()->update_backup_task_progress_if_marker( $task_id, $site_id, $marker, array( 'child_backup_task_id' => '' ) );
    }

    /**
     * Hand the create window back after a yielded refusal: clear the marker
     * AND zero dtsFetched, the same reset an abandoned stale marker gets, so
     * the next cycle re-attempts the create. Clearing only the marker leaves
     * dtsFetched fresh and the marker write's empty fetchResult in place,
     * which skips the create branch on the next cycle instead of retrying the
     * create until the hold clears or the surface window elapses.
     * foreign_refused_since is deliberately not touched: it is the persistence
     * clock across these resets.
     * Remove send-mode markers because the child never accepted this create;
     * preserve pending manifest ownership and any other state.
     *
     * @param int    $task_id Task id.
     * @param int    $site_id Site id.
     * @param string $marker  This driver's creating marker token.
     *
     * @return void
     */
    private static function reset_create_window_if_mine( $task_id, $site_id, $marker ) {
        $progress = MainWP_DB_Backup::instance()->get_backup_task_progress( $task_id, $site_id );
        $state    = json_decode( (string) ( $progress->last_error ?? '' ), true );
        $values   = array(
            'dtsFetched'           => 0,
            'child_backup_task_id' => '',
        );
        if ( is_array( $state ) ) {
            unset( $state['incremental_sent'], $state['run'] );
            $values['last_error'] = wp_json_encode( $state );
        }
        MainWP_DB_Backup::instance()->update_backup_task_progress_if_marker(
            $task_id,
            $site_id,
            $marker,
            $values
        );
    }

    /**
     * Place the child task a create round trip returned after this driver
     * lost its slot.
     *
     * The child is already building a backup under an id only this driver
     * has seen, so dropping it would leave that task running until its
     * wall clock expires, refusing every backup on the site meanwhile.
     * The id goes onto the row while the row still carries this driver's
     * marker, and the next cycle resumes it. A row a replacement driver
     * already owns is left exactly as it is, and the id is owed to the
     * child as a cancel instead - nothing else can name it.
     *
     * @param object $website     Website object.
     * @param mixed  $task_id     Task id.
     * @param string $marker      This driver's creating marker token.
     * @param mixed  $information Child response to the create round trip.
     *
     * @return void
     */
    protected static function hand_off_created_task_after_slot_loss( $website, $task_id, $marker, $information ) {
        if ( ! is_array( $information ) || ! empty( $information['error'] ) || empty( $information['task_id'] ) || ! is_string( $information['task_id'] ) ) {
            return;
        }

        $child_task_id = sanitize_text_field( $information['task_id'] );

        $adopted = MainWP_DB_Backup::instance()->update_backup_task_progress_if_marker(
            $task_id,
            (int) $website->id,
            $marker,
            array(
                'child_backup_task_id'  => $child_task_id,
                'foreign_refused_since' => 0,
            )
        );

        // (task_id, wp_id) is unique from db 9.0.2.24 on, so the marker
        // match is a single row. Any positive count is this driver's own
        // row adopted; only a zero means a replacement driver took the row
        // over, and then the id is owed to the child as a cancel.
        if ( $adopted < 1 ) {
            MainWP_Manage_Backups_Handler::record_pending_child_cancel( $website, $child_task_id );
        }
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
     * @param mixed  $progress  Pre-reset progress row (carries the sent marker).
     *
     * @return array { type: string, mode: string, params: array, notice: string }
     */
    protected static function resolve_incremental_send( $website, $pTask, $progress ) {
        if ( ! MainWP_Backup_Monitor::child_supports_incremental( $website ) ) {
            return array(
                'type'   => 'full',
                'mode'   => 'full',
                'params' => array( 'emit_manifest' => '1' ),
                'notice' => esc_html__( 'The child site does not support incremental backups, so a full backup was run instead. Update the MainWP Child plugin on the site to enable incremental backups.', 'mainwp' ),
            );
        }

        $marker = self::incremental_sent_marker( $progress, (int) $pTask->last_run );
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
        $run_prefix       = (int) $website->id . ':' . (int) $pTask->id . ':c:';
        $base_run_key     = (string) ( $base->run_key ?? '' );
        $parent_child_id  = 0 === strpos( $base_run_key, $run_prefix ) ? substr( $base_run_key, strlen( $run_prefix ) ) : '';
        $identity_capable = '1' === (string) MainWP_DB::instance()->get_website_option( (int) $website->id, 'manifest_writer_identity_support' );
        $owns_manifest    = $identity_capable && '' !== $parent_child_id && (string) MainWP_DB::instance()->get_website_option( (int) $website->id, 'native_manifest_snapshot' ) === (string) $base->id;
        if ( ! $escalate_to_full && ( 'plain' === $marker || ! $owns_manifest ) ) {
            $manifest_b64 = self::pushable_manifest( $base );
            if ( '' === $manifest_b64 ) {
                // Too large for one request: a chunk-capable child stages
                // the manifest in pieces instead of forcing a full backup.
                $chunkable = self::chunkable_manifest( $base, $website );
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
            'params' => array_merge( $send_params, array( 'parent_child_task_id' => $parent_child_id ) ),
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
        $marker = self::incremental_sent_marker( $progress, (int) $pTask->last_run );
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
     * The note is advisory - the run succeeded as a full backup - so it
     * goes to backup_notices. Anything in backup_errors reads as a failed
     * run: the failure email, the on-demand poll and the scheduler's
     * incomplete-run retry all key off it.
     *
     * @param object $website Website object.
     * @param int    $taskId  Task id.
     * @param string $message Notice text.
     */
    protected static function record_incremental_notice( $website, $taskId, $message ) {
        MainWP_Logger::instance()->warning_for_website( $website, 'backup', $message );
        if ( MainWP_DB_Backup::instance()->get_backup_task_by_id( $taskId ) ) {
            MainWP_DB_Backup::instance()->update_backup_notices( $taskId, $message . "\n" );
        }
    }

    // phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing -- the registry factory throws MainWP_Exception, documented in the docblock.
    /**
     * Record the run's snapshot under the artifact's download lock.
     *
     * The record phase shares the artifact with concurrent drivers the
     * same way the transfer does: without the lock, a second driver's
     * activity between this driver's flag write and the record could
     * destroy the file and the record would silently vanish. A held lock
     * yields to the holder - the handler treats it like any other
     * same-run collision and a later cycle retries the record.
     *
     * @param object      $website   Website object.
     * @param object      $pTask     Scheduled task.
     * @param object      $progress  Progress row after the download phase.
     * @param string|null $lock_file Downloaded artifact path keying the lock.
     *
     * @return void
     * @throws MainWP_Exception When another process holds the artifact lock, or the record itself fails.
     */
    protected static function record_run_snapshot_locked( $website, $pTask, $progress, $lock_file ) {
        // phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.Missing
        if ( empty( $lock_file ) ) {
            self::record_downloaded_run_snapshot( $website, $pTask, $progress );
            return;
        }

        if ( ! MainWP_Backup_File_Manager::acquire_download_lock( (string) $lock_file, (int) $website->id, (int) $pTask->id ) ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING, 'record lock held for ' . basename( (string) $lock_file ) ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }
        try {
            self::record_downloaded_run_snapshot( $website, $pTask, $progress );
        } finally {
            MainWP_Backup_File_Manager::release_download_lock( (string) $lock_file );
        }
    }

    // phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing -- the registry factory throws MainWP_Exception, documented in the docblock.
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
     * @throws MainWP_Exception When every flagged-complete artifact vanished from disk before the record.
     */
    protected static function record_downloaded_run_snapshot( $website, $pTask, $progress ) {
        // phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.Missing
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
            // Every flagged-complete artifact vanished from disk before the
            // record: silently returning here would report a successful run
            // with no snapshot. Surface it as the data loss it is.
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT, 'no downloaded artifact found on disk at snapshot record time' ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
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

        $site_dir      = self::private_site_dir( (int) $website->id );
        $manifest_path = '';
        foreach ( (array) ( $progress->fetchResult['artifacts'] ?? array() ) as $artifact ) {
            if ( ! $has_full || 'manifest' !== ( $artifact['role'] ?? '' ) ) {
                continue;
            }
            try {
                $remote        = basename( (string) ( $artifact['file'] ?? '' ) );
                $size          = (int) ( $artifact['size'] ?? 0 );
                $run_dir       = $site_dir . self::run_dir_name( (string) $progress->child_backup_task_id ) . '/';
                $manifest_path = $run_dir . MainWP_Utility::sanitize_file_name( $remote );
                if ( '' === $remote || $size < 1 ) {
                    throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT, 'invalid manifest artifact' ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
                }
                if ( ! MainWP_Backup_Private_Root::is_within_private_site_dir( $manifest_path, (int) $website->id ) ) {
                    throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT, 'manifest path is outside the private site directory' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Registry messages are pre-escaped.
                }
                wp_mkdir_p( $run_dir );
                if ( ! is_file( $manifest_path ) || (int) filesize( $manifest_path ) !== $size ) {
                    MainWP_Backup_File_Manager::download_backup_artifact(
                        $website,
                        $remote,
                        $manifest_path,
                        $size,
                        MainWP_Credential_Storage::decrypt_credential( $website->http_user ),
                        MainWP_Credential_Storage::decrypt_credential( $website->http_pass )
                    );
                    if ( ! is_file( $manifest_path ) || (int) filesize( $manifest_path ) !== $size ) {
                        throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT, 'manifest size mismatch' ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
                    }
                }
                if ( ! self::verify_manifest_artifact( $artifact, $manifest_path ) ) {
                    wp_delete_file( $manifest_path );
                    $manifest_path = '';
                    break;
                }
                $manifest = array(
                    'file' => basename( $manifest_path ),
                    'size' => $size,
                    'role' => 'manifest',
                    'path' => $manifest_path,
                );
                if ( isset( $artifact['walk'] ) && is_array( $artifact['walk'] ) ) {
                    $manifest['walk'] = $artifact['walk'];
                }
                $archives[] = $manifest;
            } catch ( \Throwable $e ) {
                if ( '' !== $manifest_path && MainWP_Backup_Private_Root::is_within_private_site_dir( $manifest_path, (int) $website->id ) && is_file( $manifest_path ) ) {
                    wp_delete_file( $manifest_path );
                }
                $manifest_path = '';
                MainWP_Logger::instance()->warning_for_website( $website, 'backup', 'Backup recorded without a file manifest: ' . $e->getMessage() );
            }
            break;
        }
        if ( ! MainWP_Backup_Monitor::confirm_native_lease() ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING, 'backup lease lost before snapshot record' ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }

        $snapshot_id = $snapshots->record_snapshot(
            array(
                'task_id'       => (int) $pTask->id,
                'site_id'       => (int) $website->id,
                'type'          => $snapshot_type,
                'parent_id'     => null,
                'archives'      => $archives,
                'manifest_path' => $manifest_path,
                'woo_meta'      => self::woo_meta_from_progress( $progress ),
                'run_key'       => self::snapshot_run_key( $website, $pTask, $progress ),
            )
        );
        if ( $snapshot_id < 1 ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_RECORD_FAILED, 'task id ' . (int) $pTask->id . ', site id ' . (int) $website->id ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }
        if ( '' !== $manifest_path ) {
            self::record_manifest_writer( $website, $progress, $snapshot_id );
        }
    }

    /**
     * The Woo order-storage facts the completed run's fetchResult carries,
     * or an empty array when the run recorded none.
     *
     * @param object $progress Progress row.
     *
     * @return array Woo facts.
     */
    protected static function woo_meta_from_progress( $progress ) {
        $information = isset( $progress->fetchResult ) && is_array( $progress->fetchResult ) ? $progress->fetchResult : array();
        return isset( $information['woo'] ) && is_array( $information['woo'] ) ? $information['woo'] : array();
    }

    /**
     * Download every artifact of a completed native file backup and
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
     * @param int    $snapshot_id Recorded or adopted snapshot id, including on a later failure.
     * @param bool|null $advanced Set true when this call accepted a newly downloaded artifact.
     *
     * @throws MainWP_Exception When an artifact cannot be downloaded or fails validation.
     *
     * @return array|false Backup result with the snapshot id, false when the run stopped without recording one.
     */
    // phpcs:ignore Squiz.Commenting.FunctionCommentThrowTag.Missing -- registry factory throws are MainWP_Exception, documented above.
    protected static function finish_incremental_backup( $website, $pTask, $artifacts, $progress, &$snapshot_id = 0, &$advanced = null ) { // phpcs:ignore -- NOSONAR - download loop with per-role validation.
        MainWP_System_Utility::get_wp_file_system();

        /**
         * WordPress filesystem instance.
         *
         * @global object
         */
        global $wp_filesystem;

        $dir = self::private_site_dir( (int) $website->id );
        $wp_filesystem->mkdir( $dir, 0750 );
        if ( ! $wp_filesystem->exists( $dir . 'index.php' ) ) {
            $wp_filesystem->touch( $dir . 'index.php' );
        }

        $run_dir = $dir . self::run_dir_name( (string) $progress->child_backup_task_id ) . '/';
        $wp_filesystem->mkdir( $run_dir, 0750 );
        // Native backup starts revisit the site directory to recover crashed downloads and sweep orphan mutexes.
        MainWP_Backup_File_Manager::cleanup_stale_download_locks( $dir );

        $http_user_plain = MainWP_Credential_Storage::decrypt_credential( $website->http_user );
        $http_pass_plain = MainWP_Credential_Storage::decrypt_credential( $website->http_pass );

        $archives      = array();
        $is_base       = false;
        $manifest_path = '';

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

            $allow_empty      = 'tombstones' === $role && isset( $artifact['size'] ) && 0 === $size;
            $already_complete = file_exists( $local ) && (int) filesize( $local ) === $size && ( $size > 0 || $allow_empty );
            $invalid_archive  = false;
            if ( $already_complete && in_array( $role, array( 'part', 'db', 'full' ), true ) ) {
                try {
                    MainWP_Backup_File_Manager::validate_backup_file( $local, $size );
                } catch ( MainWP_Exception $e ) {
                    $already_complete = false;
                    $invalid_archive  = true;
                }
            }
            if ( ! $already_complete && ! MainWP_Backup_File_Manager::acquire_download_lock( $local, $website->id, $pTask->id ) ) {
                throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING, 'download lock held for ' . basename( $local ) ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
            }
            $manifest_error = null;
            try {
                if ( $invalid_archive ) {
                    // The downloader skips exact-size files, so discard this one while holding its lock.
                    wp_delete_file( $local );
                }
                if ( ! $already_complete ) {
                    try {
                        MainWP_Backup_File_Manager::download_backup_artifact( $website, $remote, $local, $size, $http_user_plain, $http_pass_plain, $allow_empty );
                    } catch ( MainWP_Exception $e ) {
                        // Transport and ownership failures need the handler's mid_download retry.
                        if ( 'manifest' !== $role || ! in_array( $e->get_message_error_code(), array( MainWP_Backup_Error_Codes::ERR_FILE_TOO_SMALL, MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT ), true ) ) {
                            throw $e;
                        }
                        $manifest_error = $e;
                    }
                }

                if ( null === $manifest_error ) {
                    try {
                        if ( ! $already_complete ) {
                            if ( in_array( $role, array( 'part', 'db', 'full' ), true ) ) {
                                MainWP_Backup_File_Manager::validate_backup_file( $local, $size );
                            } elseif ( (int) filesize( $local ) !== $size ) {
                                throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT, 'expected ' . $size . ' bytes for ' . $remote ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
                            }
                        }
                        if ( 'manifest' === $role && ! self::verify_manifest_artifact( $artifact, $local ) ) {
                            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT, 'manifest digest missing or mismatched' ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
                        }
                    } catch ( \Throwable $e ) {
                        if ( 'manifest' !== $role ) {
                            throw $e;
                        }
                        $manifest_error = $e;
                    }
                }

                if ( null !== $manifest_error ) {
                    // Shared-list replacement does not invalidate the archives; delete while still owning the download lock.
                    if ( is_file( $local ) ) {
                        wp_delete_file( $local );
                    }
                    MainWP_Logger::instance()->warning_for_website( $website, 'backup', 'Backup recorded without a file manifest: ' . $manifest_error->getMessage() );
                    continue;
                }
            } finally {
                if ( ! $already_complete ) {
                    MainWP_Backup_File_Manager::release_download_lock( $local );
                }
            }

            if ( ! $already_complete ) {
                $advanced = true;
            }
            $archive = array(
                'file' => basename( $local ),
                'size' => $size,
                'role' => $role,
                'path' => $local,
            );
            if ( 'manifest' === $role && isset( $artifact['walk'] ) && is_array( $artifact['walk'] ) ) {
                $archive['walk'] = $artifact['walk'];
            }
            $archives[] = $archive;
            if ( 'manifest' === $role ) {
                $manifest_path = $local;
            }
        }

        if ( ! MainWP_Backup_Monitor::confirm_native_lease() ) {
            return false; // A snapshot is what retires the site, so it may not be written under a slot this driver no longer owns.
        }

        $snapshots = MainWP_Backup_Snapshots::instance();

        // Serialize the identity lookup with the insert and completion decision.
        $record_lock = $run_dir . 'snapshot-record';
        if ( ! MainWP_Backup_File_Manager::acquire_download_lock( $record_lock, (int) $website->id, (int) $pTask->id ) ) {
            return false;
        }
        try {
            global $wpdb;
            $run_key  = self::snapshot_run_key( $website, $pTask, $progress );
            $latest   = $snapshots->get_latest_complete( (int) $website->id, (int) $pTask->id );
            $existing = null;
            if ( null !== $run_key && MainWP_Install::backup_snapshot_run_key_ready() ) {
                $table    = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
                $existing = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$table} WHERE run_key = %s AND site_id = %d AND task_id = %d", $run_key, $website->id, $pTask->id ) ); // phpcs:ignore WordPress.DB -- stable run identity lookup under the record lock.
                if ( '' !== $wpdb->last_error ) {
                    throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_RECORD_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry factory.
                }
            }
            if ( null === $existing ) {
                // Runs recorded before schema repair still have no run_key, including manifestless runs.
                $table      = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
                $path_match = '%' . $wpdb->esc_like( substr( wp_json_encode( $run_dir ), 1, -1 ) ) . '%';
                $candidates = $wpdb->get_results( $wpdb->prepare( "SELECT id, archives FROM {$table} WHERE site_id = %d AND task_id = %d AND status = 'complete' AND archives LIKE %s", $website->id, $pTask->id, $path_match ) ); // phpcs:ignore WordPress.DB -- bounded run-directory candidates under the record lock.
                if ( '' !== $wpdb->last_error ) {
                    throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_RECORD_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry factory.
                }
                $run_paths = array_column( array_filter( $archives, static fn( $entry ) => 'manifest' !== $entry['role'] ), 'path' );
                sort( $run_paths );
                foreach ( (array) $candidates as $candidate ) {
                    $entries = json_decode( (string) $candidate->archives, true );
                    $paths   = array();
                    if ( ! is_array( $entries ) || empty( $run_paths ) ) {
                        continue;
                    }
                    foreach ( $entries as $entry ) {
                        if ( ! is_array( $entry ) || ! isset( $entry['path'], $entry['role'] ) || ! is_string( $entry['path'] ) || dirname( $entry['path'] ) . '/' !== $run_dir ) {
                            continue 2;
                        }
                        if ( 'manifest' !== $entry['role'] ) {
                            $paths[] = $entry['path'];
                        }
                    }
                    sort( $paths );
                    if ( $run_paths === $paths ) {
                        $existing = $candidate;
                        break;
                    }
                }
            }
            $already_saved = null !== $existing;

            if ( ! $already_saved ) {
                $parent_id   = $is_base || null === $latest ? null : (int) $latest->id;
                $snapshot_id = $snapshots->record_snapshot(
                    array(
                        'task_id'       => (int) $pTask->id,
                        'site_id'       => (int) $website->id,
                        'type'          => 'files' === $pTask->type ? 'files' : ( $is_base ? 'full' : 'incremental' ),
                        'parent_id'     => $parent_id,
                        'archives'      => $archives,
                        'manifest_path' => $manifest_path,
                        'woo_meta'      => self::woo_meta_from_progress( $progress ),
                        'run_key'       => $run_key,
                    )
                );
            } else {
                $snapshot_id = (int) $existing->id;
            }

            if ( $snapshot_id < 1 ) {
                throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_RECORD_FAILED, 'task id ' . (int) $pTask->id . ', site id ' . (int) $website->id ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
            }

            // Snapshot existence cannot distinguish a failed finish from an announced success.
            // Reread under the record lock so a waiting driver sees the winner's announcement.
            $fresh = MainWP_DB_Backup::instance()->get_backup_task_progress( $pTask->id, $website->id );
            if ( '' !== $wpdb->last_error ) {
                throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_RECORD_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry factory.
            }
            if ( ! $fresh || $fresh->child_backup_task_id !== $progress->child_backup_task_id ) {
                return false;
            }
            $information = $fresh->fetchResult;
            if ( (int) ( $information['announced_snapshot_id'] ?? 0 ) !== $snapshot_id ) {
                // An adopted snapshot may still have a pending writer token.
                if ( '' !== $manifest_path ) {
                    self::record_manifest_writer( $website, $fresh, $snapshot_id );
                }
                $information['announced_snapshot_id'] = $snapshot_id;
                $claimed                              = MainWP_DB_Backup::instance()->update_backup_task_progress_if_marker(
                    $pTask->id,
                    $website->id,
                    $progress->child_backup_task_id,
                    array( 'fetchResult' => wp_json_encode( $information ) )
                );
                if ( '' !== $wpdb->last_error ) {
                    throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_SNAPSHOT_RECORD_FAILED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry factory.
                }
                if ( 1 !== $claimed ) {
                    return false;
                }
                try {
                    /** This action is documented in class/backup/class-mainwp-backup-executor.php */
                    do_action(
                        'mainwp_native_backup_completed',
                        (int) $website->id,
                        $progress->child_backup_task_id,
                        true,
                        array(
                            'type'        => 'incremental',
                            'file'        => '',
                            'size'        => $information['size'] ?? 0,
                            'snapshot_id' => $snapshot_id,
                        )
                    );
                } catch ( \Throwable $e ) {
                    MainWP_Logger::instance()->warning_for_website( $website, 'backup', 'Backup completion listener failed: ' . $e->getMessage() );
                }
            }
        } finally {
            MainWP_Backup_File_Manager::release_download_lock( $record_lock );
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
            } catch ( \Throwable $e ) {
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
     * @param mixed       $pSiteId                         Child Site ID.
     * @param mixed       $pType                           full|db Typ of backup.
     * @param mixed       $pSubfolder                      Sub folder to store backup.
     * @param mixed       $pExclude                        Exclusion list.
     * @param mixed       $excludebackup                   Exclude backup files.
     * @param mixed       $excludecache                    Exclude cache files.
     * @param mixed       $excludenonwp                    Exclude no WP Files.
     * @param mixed       $excludezip                      Exclude Zip files.
     * @param null        $pFilename                       Name of backup file.
     * @param string      $pFileNameUID                    File name unique ID.
     * @param bool        $pArchiveFormat                  Archive format.
     * @param bool        $pMaximumFileDescriptorsOverride Override maximum file descriptors.
     * @param bool        $pMaximumFileDescriptorsAuto     Auto maximum file descriptors.
     * @param bool        $pMaximumFileDescriptors         Maximum file descriptors.
     * @param bool        $pLoadFilesBeforeZip             Load files before zip.
     * @param bool        $pid                             Backup pid.
     * @param bool        $append                          Append to backup.
     * @param object|null $pTask                      Backup task driving this run, when one exists, so
     *                                                preflight negotiation honors its zip_force_override
     *                                                and records the outcome on it.
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
        $append = false,
        $pTask = null
    ) {

        if ( ! MainWP_Backup_Settings::is_native_backups_enabled() ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_LEGACY_DISABLED ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }

        $pExclude = MainWP_Backup_Settings::sanitize_exclude_list( $pExclude );

        if ( null === $pFilename || '' === trim( $pFilename ) ) {
            $pFilename = null;
        }

        $backup_result = array();

        $website = MainWP_DB::instance()->get_website_by_id( $pSiteId );
        if ( empty( $website ) ) {
            throw new MainWP_Exception( esc_html__( 'Site not found.', 'mainwp' ) );
        }

        self::private_site_dir( (int) $pSiteId );
        MainWP_Backup_Monitor::validate_backup_environment();

        $subfolder = str_replace( '%sitename%', MainWP_Utility::sanitize( $website->name ), $pSubfolder );
        $subfolder = str_replace( '%url%', MainWP_Utility::sanitize( MainWP_Utility::get_nice_url( $website->url ) ), $subfolder );
        $subfolder = str_replace( '%type%', $pType, $subfolder );
        $subfolder = str_replace( '%date%', MainWP_Utility::date( 'Ymd' ), $subfolder );
        $subfolder = str_replace( '%task%', '', $subfolder );
        $subfolder = str_replace( '%', '', $subfolder );
        $subfolder = MainWP_Utility::remove_preslash_spaces( $subfolder );
        $subfolder = MainWP_Backup_File_Manager::normalize_filename( $subfolder );

        if ( MainWP_System_Utility::is_suspended_site( $website ) ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_SUSPENDED_SITE ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }

        if ( ! MainWP_System_Utility::can_edit_website( $website ) ) {
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
            $maximumFileDescriptorsAuto = ( 1 === (int) $pMaximumFileDescriptorsAuto );
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
            $loadFilesBeforeZip = (int) $website->loadFilesBeforeZip;
            if ( 1 === $loadFilesBeforeZip ) {
                $loadFilesBeforeZip = get_option( 'mainwp_options_loadFilesBeforeZip' );
                $loadFilesBeforeZip = ( 1 === (int) $loadFilesBeforeZip || false === $loadFilesBeforeZip );
            } else {
                $loadFilesBeforeZip = ( 2 === $loadFilesBeforeZip );
            }
        } elseif ( 'global' === $pArchiveFormat || 1 === (int) $pLoadFilesBeforeZip ) { // Overridden flow: only fallback to global.
            $loadFilesBeforeZip = get_option( 'mainwp_options_loadFilesBeforeZip' );
            $loadFilesBeforeZip = ( 1 === (int) $loadFilesBeforeZip || false === $loadFilesBeforeZip );
        } else {
            $loadFilesBeforeZip = ( 2 === (int) $pLoadFilesBeforeZip );
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

        // Negotiate before building the request so a large-site ZIP auto-switch
        // reaches the child in $params['ext']. The driving task, when one
        // exists, carries the keep-ZIP override and receives the outcome note.
        MainWP_Backup_Monitor::negotiate_backup_preflight( $website, $archiveFormat, $pTask );

        // This engine is the one remaining caller of the child's legacy backup
        // action, and Run Now is what reaches it. A child too old for the task
        // spine is refused here as well, so an un-updated site cannot be backed
        // up from the UI either: gating only the scheduled path would leave a
        // button on every task row that quietly runs the legacy engine.
        //
        // The gate sits AFTER the preflight negotiation above so it reads a
        // freshly negotiated capability, not the stored flag: a site that has
        // never run a native backup has no flag yet, and a missing flag is
        // indistinguishable from an unsupported one, so a healthy child would
        // be refused on a brand-new task. One negotiation serves both the
        // format switch and this gate; a separate probe call would round-trip
        // the child twice per Run Now and double the diagnostics events.
        if ( ! MainWP_Backup_Monitor::child_supports_task_spine( $website ) ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_CHILD_UPDATE_REQUIRED ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }

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

        MainWP_Logger::instance()->debug_for_website( $website, 'backup', 'Requesting backup: ' . MainWP_Utility::value_to_string( $params ) );

        $information = MainWP_Connect::fetch_url_authed( $website, 'backup', $params, false, false, false );
        do_action( 'mainwp_managesite_backup', $website, array( 'type' => $pType ), $information );

        if ( isset( $information['error'] ) ) {
            throw new MainWP_Exception( esc_html( MainWP_Backup_Error_Codes::sanitize_message( $information['error'] ) ), null, isset( $information['error_code'] ) ? $information['error_code'] : '' ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        } elseif ( 'incremental' === $pType ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        } elseif ( 'db' === $pType && empty( $information['db'] ) ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_DB_QUERY_FAILED ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        } elseif ( 'full' === $pType && empty( $information['full'] ) ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        } elseif ( 'files' === $pType && empty( $information['full'] ) ) {
            // A child whose legacy backup action predates the files type
            // answers with nothing on either slot; failing loudly beats
            // reporting an empty success.
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_FILE_CORRUPT ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        } elseif ( ! empty( $information['db'] ) || ! empty( $information['full'] ) ) {
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

            $dir = self::private_site_dir( (int) $pSiteId );

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
     * Whether a Child answer to a one-task backup_task_prune confirms the
     * task is gone.
     *
     * The Child answers counts, not per-task outcomes. Only a clean answer
     * that accounts for exactly the one target as pruned or missing counts;
     * an error envelope, a still-active task or a malformed count is
     * unconfirmed and the caller retries later.
     *
     * @param mixed $answer Child response.
     *
     * @return bool
     */
    public static function child_prune_confirmed( $answer ): bool {
        if ( ! is_array( $answer ) || array_key_exists( 'error', $answer ) || 'ok' !== ( $answer['result'] ?? null ) ) {
            return false;
        }
        $counts = array();
        foreach ( array( 'pruned', 'active', 'missing' ) as $key ) {
            $value = $answer[ $key ] ?? null;
            if ( is_int( $value ) && $value >= 0 ) {
                $counts[ $key ] = $value;
            } elseif ( is_string( $value ) && '' !== $value && ctype_digit( $value ) ) {
                $counts[ $key ] = (int) $value;
            } else {
                return false;
            }
        }
        return 0 === $counts['active'] && 1 === $counts['pruned'] + $counts['missing'];
    }

    // phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber -- all throws are MainWP_Exception.
    /**
     * Method trigger_backup()
     *
     * Start a backup for a site and return a handle the caller can await
     * with get_backup_task_status(). Children running the resumable task
     * machine get an ad-hoc task created; the handle is persisted in the
     * adhoc_backup_task website option so a later call resumes the same
     * task instead of colliding with it. Children without the machine are
     * refused with a coded update-required error.
     *
     * @param int    $site_id Child site ID.
     * @param string $type    full|db Type of backup.
     * @param string $owner   Restore job id, or '' for the auto-update lane.
     *
     * @return string Task handle for resumable children.
     * @throws MainWP_Exception When the backup cannot be started.
     * @throws MainWP_Exception When another lane owns a handle within the abandonment window.
     */
    public static function trigger_backup( int $site_id, string $type = 'full', string $owner = '' ): string {
        // phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber
        if ( ! MainWP_Backup_Settings::is_native_backups_enabled() ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_LEGACY_DISABLED ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }

        if ( 'incremental' === $type ) {
            // Incremental chains hang off a scheduled task's snapshot
            // history; an ad-hoc run has no chain to extend.
            throw new MainWP_Exception( esc_html__( 'Incremental backups run from a scheduled backup task. Create a backup task with the incremental type instead.', 'mainwp' ) );
        }

        $website = MainWP_DB::instance()->get_website_by_id( $site_id );
        if ( empty( $website ) ) {
            throw new MainWP_Exception( esc_html__( 'Site not found.', 'mainwp' ) );
        }

        self::private_site_dir( $site_id );
        MainWP_Backup_Monitor::validate_backup_environment();

        $archive_ext = MainWP_Backup_Settings::get_current_archive_extension( $website );
        MainWP_Backup_Monitor::negotiate_backup_preflight( $website, $archive_ext );

        if ( ! MainWP_Backup_Monitor::child_supports_task_spine( $website ) ) {
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_CHILD_UPDATE_REQUIRED ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }

        $persisted = self::read_adhoc_task( $website );
        if ( ! empty( $persisted['task_id'] ) && ( empty( $persisted['type'] ) || $persisted['type'] === $type ) ) {
            if ( (string) ( $persisted['owner'] ?? '' ) === $owner ) {
                return $persisted['task_id'];
            }
            if ( time() - (int) ( $persisted['started'] ?? 0 ) <= self::ADHOC_TASK_ABANDON_SECONDS ) {
                throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_ALREADY_RUNNING ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
            }
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
        $started = time();
        MainWP_DB::instance()->update_website_option(
            $website,
            self::ADHOC_TASK_OPTION,
            wp_json_encode(
                array(
                    'task_id' => $task_id,
                    'type'    => $type,
                    'started' => $started,
                    'owner'   => $owner,
                )
            )
        );

        self::close_displaced_handle_history( (int) $website->id, $persisted, $task_id );

        MainWP_Backup_Run_History::start( 0, $site_id, $started, '' === $owner ? 'update_gate' : 'restore_safety', 0, '' === $owner ? null : (int) $owner, $task_id );

        return $task_id;
    }

    /**
     * Close the history row of a handle this create displaced.
     *
     * Only once the stored handle holds the new task: a failed create or handle
     * write leaves the old handle for its owner to resume. The numeric id reads
     * the stored option, not a copy carried on the website row, and a failed
     * read counts as not replaced.
     *
     * @param int    $site_id   Child site id.
     * @param array  $persisted Handle read before the create.
     * @param string $task_id   Task the create returned.
     */
    private static function close_displaced_handle_history( int $site_id, array $persisted, string $task_id ): void {
        if ( empty( $persisted['task_id'] ) || (string) $persisted['task_id'] === $task_id ) {
            return;
        }
        $owner = (string) ( $persisted['owner'] ?? '' );
        try {
            $stored = json_decode( (string) MainWP_DB::instance()->get_website_option( $site_id, self::ADHOC_TASK_OPTION ), true );
            if ( ! is_array( $stored ) || (string) ( $stored['task_id'] ?? '' ) !== $task_id ) {
                return;
            }
            if ( '' !== $owner ) {
                $job = MainWP_Backup_Restore_Job_Store::instance()->get_job( (int) $owner );
                // An active restore still polls its own task after the site handle changes.
                if ( null !== $job && in_array( $job->status, MainWP_Backup_Restore_Job_Store::active_statuses(), true ) ) {
                    return;
                }
            }
        } catch ( \Throwable $e ) {
            return;
        }
        MainWP_Backup_Run_History::finalize_ref( $site_id, (string) $persisted['task_id'], '' === $owner ? 'update_gate' : 'restore_safety', 'incomplete' );
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
        if ( isset( $progress['total_bytes'] ) ) {
            // The raw file-size total reported while archiving. It never shares
            // a payload with bytes_total (a different phase), so the same
            // bytes_expected column carries whichever the phase provides.
            $fields['bytes_expected'] = max( 0, (int) $progress['total_bytes'] );
        }
        if ( isset( $progress['current_file'] ) ) {
            $fields['current_file'] = substr( sanitize_text_field( (string) $progress['current_file'] ), 0, 255 );
        }
        if ( isset( $progress['files_processed'] ) ) {
            $fields['files_processed'] = max( 0, (int) $progress['files_processed'] );
        }
        if ( isset( $progress['files_scanned'] ) ) {
            // A live scanned-file count during the archiving walk takes
            // precedence over a processed count from an earlier phase.
            $fields['files_processed'] = max( 0, (int) $progress['files_scanned'] );
        }
        if ( isset( $progress['files_total'] ) ) {
            $fields['files_total'] = max( 0, (int) $progress['files_total'] );
        }

        // While archiving, the processed-file count from the database dump is
        // stale. Unless the child reports a live scanned count, zero it so the
        // poll response falls back to the byte estimate rather than a
        // misleading file ratio.
        if ( isset( $fields['child_phase'] )
            && MainWP_Backup_Task_Phase::ARCHIVING_FILES === $fields['child_phase']
            && ! isset( $progress['files_scanned'] )
            && ! isset( $progress['files_processed'] ) ) {
            $fields['files_processed'] = 0;
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
     * @throws \Exception When a status or run request fails; the original exception propagates.
     */
    public static function get_backup_task_status( int $site_id, string $task_id, int $budget_seconds = 0 ): array {
        $website = MainWP_DB::instance()->get_website_by_id( $site_id );
        if ( empty( $website ) ) {
            throw new MainWP_Exception( esc_html__( 'Site not found.', 'mainwp' ) );
        }

        if ( $budget_seconds <= 0 ) {
            $diag_start = microtime( true );
            try {
                $payload = MainWP_Connect::fetch_url_authed( $website, 'backup_task_status', array( 'task_id' => $task_id ), false, false, false );
            } catch ( \Exception $e ) {
                self::record_thrown_request(
                    $e,
                    microtime( true ) - $diag_start,
                    array(
                        'sent'    => true,
                        'task_id' => $task_id,
                    ),
                    array(
                        'op'      => 'status',
                        'site_id' => (int) $site_id,
                        'task_id' => 0,
                    )
                );
                throw $e;
            }
            /** This action is documented in class/backup/class-mainwp-backup-executor.php */
            do_action(
                'mainwp_backup_diag_poll',
                is_array( $payload ) ? $payload : array(),
                microtime( true ) - $diag_start,
                array(
                    'op'      => 'status',
                    'site_id' => (int) $site_id,
                    'task_id' => 0,
                )
            );
            $status = self::map_task_status( $payload );
            // Only a failure the Child reported ends the task: an unreadable answer
            // keeps the handle, so a later pass can still see the task complete.
            if ( 'failed' === $status['status'] && isset( $status['meta']['phase'] ) ) {
                MainWP_Backup_Run_History::finalize_ref( $site_id, $task_id, 'update_gate', 'failed', is_string( $status['meta']['error_code'] ?? null ) ? $status['meta']['error_code'] : '' );
            }
            return $status;
        }

        $deadline = time() + $budget_seconds;
        do {
            $diag_start = microtime( true );
            try {
                $payload = MainWP_Connect::fetch_url_authed( $website, 'backup_task_run', array( 'task_id' => $task_id ), false, false, false );
            } catch ( \Exception $e ) {
                self::record_thrown_request(
                    $e,
                    microtime( true ) - $diag_start,
                    array(
                        'sent'    => true,
                        'task_id' => $task_id,
                    ),
                    array(
                        'op'      => 'run',
                        'site_id' => (int) $site_id,
                        'task_id' => 0,
                    )
                );
                throw $e;
            }
            /** This action is documented in class/backup/class-mainwp-backup-executor.php */
            do_action(
                'mainwp_backup_diag_poll',
                is_array( $payload ) ? $payload : array(),
                microtime( true ) - $diag_start,
                array(
                    'op'      => 'run',
                    'site_id' => (int) $site_id,
                    'task_id' => 0,
                )
            );
            $status = self::map_task_status( $payload );
        } while ( 'running' === $status['status'] && time() < $deadline );

        $downloaded = '';
        if ( 'completed' === $status['status'] && ! empty( $status['meta']['file'] ) ) {
            $type  = isset( $payload['type'] ) && MainWP_Backup_Type::DATABASE === $payload['type'] ? 'db' : 'full';
            $local = self::build_local_backup_file( $website, $type, $status['meta']['file'] );
            MainWP_Backup_File_Manager::backup_download_file( $site_id, $type, $status['meta']['file'], $local );
            $downloaded              = (string) $status['meta']['file'];
            $status['meta']['local'] = $local;
        }

        if ( 'running' !== $status['status'] ) {
            MainWP_Backup_Run_History::finalize_ref( $site_id, $task_id, 'update_gate', 'completed' === $status['status'] && '' !== $downloaded ? 'completed' : 'failed', 'completed' !== $status['status'] && is_string( $status['meta']['error_code'] ?? null ) ? $status['meta']['error_code'] : '' );
            /**
             * Fires when the dashboard observes a backup task reach a terminal phase.
             *
             * @param int         $site_id Child site ID.
             * @param string|null $task_id Backup task handle, null for the synchronous fallback path.
             * @param bool        $success Whether the backup completed.
             * @param array       $meta    Artifact and error details.
             */
            do_action( 'mainwp_native_backup_completed', $site_id, $task_id, 'completed' === $status['status'], $status['meta'] );

            if ( 'failed' === $status['status'] ) {
                /**
                 * Fires when the dashboard observes a backup task reach a
                 * terminal failed phase.
                 *
                 * The logs connector records the event.
                 *
                 * @param array $event Site, task, and error details.
                 */
                do_action(
                    'mainwp_backup_failed',
                    array(
                        'site_id'    => (int) $site_id,
                        'task_id'    => (string) $task_id,
                        'error'      => isset( $status['meta']['error'] ) ? (string) $status['meta']['error'] : esc_html__( 'The backup task failed.', 'mainwp' ),
                        'error_code' => isset( $status['meta']['error_code'] ) ? (string) $status['meta']['error_code'] : '',
                    )
                );
            }

            MainWP_DB::instance()->update_website_option( $website, self::ADHOC_TASK_OPTION, '' );
        }

        // An ad-hoc run keeps no progress row; the completion hook and the
        // handle clear are what record the download. update_website_option()
        // reports no result, so the handle is read back: while it still names
        // this task, the next call resumes it and downloads the archive again,
        // and the child copy has to be there for that.
        if ( '' !== $downloaded && self::adhoc_handle_released( (int) $website->id, $task_id ) ) {
            try {
                MainWP_Connect::fetch_url_authed( $website, 'delete_backup', array( 'del' => $downloaded ) );
            } catch ( \Exception $e ) {
                MainWP_Logger::instance()->warning_for_website( $website, 'backup', 'Remote backup cleanup failed after a validated download: ' . $e->getMessage() );
            }
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
        return self::latest_backup_archive_mtime( $site_id ) >= ( time() - $max_age_days * DAY_IN_SECONDS );
    }

    /**
     * Freshness thresholds reuse the scan without changing archive selection.
     *
     * @param int $site_id Website identity.
     * @return int Newest non-SQL archive mtime, or -1 when none exists.
     */
    public static function latest_backup_archive_mtime( int $site_id ): int {
        $latest = -1;
        foreach ( array( MainWP_Backup_Private_Root::site_path( $site_id ), MainWP_Backup_Private_Root::legacy_site_dir( $site_id ) ) as $dir ) {
            if ( is_wp_error( $dir ) || ! is_dir( $dir ) ) {
                continue;
            }
            $dh = opendir( $dir );
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
        }

        return $latest;
    }

    /**
     * Method has_recent_native_backup()
     *
     * Whether the site has a recent native snapshot that carries a database
     * - the signal the pre-update gate should trust instead of the legacy
     * archive-directory mtime scan. Reads the snapshot history newest-first
     * and judges the newest complete, database-bearing row: a files-only run
     * protects no data an update could break, and a pruned row is gone.
     *
     * When $require_offsite is set the newest such snapshot must additionally
     * be offsite-verified, so the gate only counts a copy that left the
     * dashboard. Off by default: a site with no cloud destination should not
     * be forced to back up on every update.
     *
     * @param int  $site_id         Child site ID.
     * @param int  $max_age_days    Freshness window in days.
     * @param bool $require_offsite Require offsite_state = 'verified' too.
     *
     * @return bool True when a recent native backup exists.
     */
    public static function has_recent_native_backup( int $site_id, int $max_age_days, bool $require_offsite = false ): bool {
        $snapshots = MainWP_Backup_Snapshots::instance()->get_snapshots_for_site( $site_id );

        foreach ( $snapshots as $snapshot ) {
            if ( 'complete' !== (string) $snapshot->status || 'files' === (string) $snapshot->type ) {
                continue;
            }

            // Newest-first order: the first match is the newest backup that
            // could protect an update, so its freshness decides the gate.
            if ( (int) $snapshot->created < time() - $max_age_days * DAY_IN_SECONDS ) {
                return false;
            }

            if ( $require_offsite && 'verified' !== (string) $snapshot->offsite_state ) {
                return false;
            }

            return true;
        }

        return false;
    }

    /**
     * Avoid one snapshot query per row when rendering backup indicators.
     *
     * @param int[] $site_ids        Site ids, or an empty array for all sites.
     * @param int   $max_age_days    Freshness window.
     * @param bool  $require_offsite Whether the newest eligible snapshot must be verified offsite.
     *
     * @return array<int,bool> Request-scoped backup availability by site.
     */
    public static function get_recent_native_backup_map( array $site_ids, int $max_age_days, bool $require_offsite = false ): array {
        global $wpdb;
        $cache = &self::$recent_native_backup_maps;

        $key = $max_age_days . '|' . (int) $require_offsite;
        if ( ! isset( $cache[ $key ] ) ) {
            $table = MainWP_DB::instance()->get_table_name( 'wp_backup_snapshot' );
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- internal table; no input in SQL; one query per policy per request.
            $rows = $wpdb->get_results(
                "SELECT s.site_id, s.created, s.offsite_state FROM {$table} s INNER JOIN (SELECT MAX(id) AS id FROM {$table} WHERE status = 'complete' AND type <> 'files' GROUP BY site_id) latest ON s.id = latest.id"
            );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
            $cache[ $key ] = array();
            $cutoff        = time() - $max_age_days * DAY_IN_SECONDS;
            foreach ( (array) $rows as $row ) {
                $cache[ $key ][ (int) $row->site_id ] = (int) $row->created >= $cutoff && ( ! $require_offsite || 'verified' === (string) $row->offsite_state );
            }
        }
        if ( empty( $site_ids ) ) {
            return $cache[ $key ];
        }

        $result = array();
        foreach ( $site_ids as $site_id ) {
            $result[ (int) $site_id ] = $cache[ $key ][ (int) $site_id ] ?? false;
        }
        return $result;
    }

    /**
     * Method evaluate_update_backup_gate()
     *
     * The one provenance-aware answer to "may updates proceed on this
     * site's backup evidence?", shared by the automatic-update cron gate
     * and the interactive pre-update check so the two can never disagree.
     *
     * Without the offsite requirement, any fresh evidence satisfies the
     * gate: a native snapshot or a legacy archive in the site's backup
     * directory (which also counts third-party backups). With it, only a
     * fresh native snapshot the upload lane verified offsite passes;
     * other fresh evidence - an unverified snapshot, or a bare local
     * archive with no provenance at all - reads as PENDING_OFFSITE:
     * protection exists but has not verifiably left the dashboard, so
     * updates defer and, critically, no new fallback backup should be
     * triggered - re-running it could never improve provenance. Because
     * the newest db-bearing snapshot alone decides freshness (see
     * has_recent_native_backup()), a fresh unverified snapshot shadows an
     * older verified one; that conservative reading also lands on
     * PENDING_OFFSITE. MISSING - no fresh evidence at all - is the only
     * verdict on which creating a fallback backup makes sense.
     *
     * @param int       $site_id         Child site ID.
     * @param int       $max_age_days    Freshness window in days.
     * @param bool|null $require_offsite Offsite requirement; null reads the
     *                                   mainwp_backup_before_upgrade_require_offsite option.
     *
     * @return string One of the UPDATE_GATE_* verdicts.
     */
    public static function evaluate_update_backup_gate( int $site_id, int $max_age_days, ?bool $require_offsite = null ): string {
        if ( null === $require_offsite ) {
            $require_offsite = (bool) get_option( 'mainwp_backup_before_upgrade_require_offsite', 0 );
        }

        if ( $require_offsite && self::has_recent_native_backup( $site_id, $max_age_days, true ) ) {
            return self::UPDATE_GATE_PASS;
        }

        if ( self::has_recent_native_backup( $site_id, $max_age_days )
            || self::has_recent_backup( $site_id, $max_age_days ) ) {
            return $require_offsite ? self::UPDATE_GATE_PENDING_OFFSITE : self::UPDATE_GATE_PASS;
        }

        return self::UPDATE_GATE_MISSING;
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
        if ( MainWP_Backup_Task_Phase::COMPLETED === $phase ) {
            $status = 'completed';
        } elseif ( MainWP_Backup_Task_Phase::FAILED === $phase ) {
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

        $dir     = self::private_site_dir( (int) $website->id );
        $fm_date = MainWP_Utility::sanitize_file_name( MainWP_Utility::date( get_option( 'date_format' ) ) );
        $fm_time = MainWP_Utility::sanitize_file_name( MainWP_Utility::date( get_option( 'time_format' ) ) );
        $prefix  = MainWP_Backup_Type::DATABASE === $type ? 'db-' : 'full-';

        return MainWP_Backup_File_Manager::normalize_filename( $dir . $prefix . $clean_url . '-' . $fm_date . '-' . $fm_time . MainWP_Backup_File_Manager::get_real_extension( $remote_file ) );
    }

    /**
     * Whether the progress row re-read after the complete-flag write shows
     * this run's download as recorded.
     *
     * The progress helper ignores the write result and returns whatever the
     * re-read finds, so a failed write looks like a row without the flag.
     * A row another run reset, or one naming another local file, does not
     * confirm this download either.
     *
     * @param object|null $row         Row re-read after the write.
     * @param object      $before      This run's row before the write.
     * @param string      $path_column downloadedDB or downloadedFULL.
     * @param string      $local       Validated local file.
     * @return bool
     */
    private static function download_recorded( $row, $before, string $path_column, string $local ): bool {
        $flag = $path_column . 'Complete';
        return ! empty( $row )
            && 1 === (int) $row->$flag
            && (string) $row->$path_column === $local
            && (int) $row->dtsFetched === (int) $before->dtsFetched
            && (string) $row->child_backup_task_id === (string) $before->child_backup_task_id;
    }

    /**
     * Keep the child copy of a download the progress row does not show, and
     * let the run record its snapshot from this run's own view.
     *
     * If the run fails after this point, its retry reads the row without
     * the flag and downloads the archive again, so it needs the child copy.
     * Retention's child prune removes the copy later through the snapshot's
     * run key.
     *
     * @param object $website     Website.
     * @param object $before      This run's row before the write.
     * @param string $path_column downloadedDB or downloadedFULL.
     * @param string $local       Validated local file.
     * @return object
     */
    private static function keep_child_copy_of_unrecorded_download( $website, $before, string $path_column, string $local ) {
        MainWP_Logger::instance()->warning_for_website( $website, 'backup', 'A validated download could not be marked complete, so its copy stays on the child site until retention removes it.' );
        $flag                   = $path_column . 'Complete';
        $progress               = clone $before;
        $progress->$path_column = $local;
        $progress->$flag        = 1;
        return $progress;
    }

    /**
     * Drop an incomplete public checkpoint before selecting a new destination.
     *
     * @param string $path Recorded path.
     * @param int    $complete Completion flag.
     * @param int    $site_id Site id.
     * @return string
     */
    private static function resumable_download_path( string $path, int $complete, int $site_id ): string {
        if ( '' !== $path && 0 === $complete && ! MainWP_Backup_Private_Root::is_within_private_site_dir( $path, $site_id ) ) {
            if ( MainWP_Backup_File_Manager::path_is_confined_to( $path, MainWP_Backup_Private_Root::legacy_site_dir( $site_id ) ) && is_file( $path ) ) {
                wp_delete_file( $path );
            }
            return '';
        }
        return $path;
    }

    /**
     * Fail through the task's existing pre-write error handler.
     *
     * @param int $site_id Site id.
     * @return string
     * @throws MainWP_Exception When private storage is unavailable.
     */
    private static function private_site_dir( int $site_id ): string {
        $dir = MainWP_Backup_Private_Root::site_dir( $site_id );
        if ( is_wp_error( $dir ) ) {
            throw new MainWP_Exception( esc_html( $dir->get_error_message() ), null, MainWP_Backup_Error_Codes::ERR_PRIVATE_ROOT_UNAVAILABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is escaped; the final argument is an error code.
        }
        return $dir;
    }

    /**
     * Name of the per-run directory an incremental or spine run stages into.
     *
     * The retention orphan sweep derives open runs' directories from this
     * too, so both sides must build the name the same way.
     *
     * @param string $child_backup_task_id Child backup task id.
     * @return string
     */
    public static function run_dir_name( string $child_backup_task_id ): string {
        return 'snapshot-' . MainWP_Utility::sanitize_file_name( $child_backup_task_id );
    }

    /**
     * Whether a fresh read shows the ad-hoc handle no longer naming a task.
     *
     * Unlike read_adhoc_task(), a failed read counts as not released. The
     * option helper cannot be used here: its get_var() answers null for a
     * database error, a missing row and an empty value alike. The clear
     * always leaves a row, so a missing row is not a release either.
     *
     * @param int    $site_id Site id.
     * @param string $task_id Task handle the caller downloaded.
     * @return bool
     */
    private static function adhoc_handle_released( int $site_id, string $task_id ): bool {
        global $wpdb;
        $table = MainWP_DB::instance()->get_table_name( 'wp_options' );
        $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT value FROM {$table} WHERE wpid = %d AND name = %s", $site_id, self::ADHOC_TASK_OPTION ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- table name from the database helper; the read must report its own failure.
        if ( '' !== $wpdb->last_error || empty( $rows ) ) {
            return false;
        }
        foreach ( $rows as $row ) {
            $handle = json_decode( (string) $row->value, true );
            if ( is_array( $handle ) && (string) ( $handle['task_id'] ?? '' ) === $task_id ) {
                return false;
            }
        }
        return true;
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
        $raw  = MainWP_DB::instance()->get_website_option( $website, self::ADHOC_TASK_OPTION );
        $data = json_decode( (string) $raw, true );
        return is_array( $data ) ? $data : array();
    }
}
