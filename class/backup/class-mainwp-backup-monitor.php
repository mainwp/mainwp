<?php
/**
 * MainWP Backup Monitor.
 *
 * Watchdog and progress surface for the backup system: environment
 * validation, child preflight negotiation, capability detection,
 * concurrency slots, status polling, and error formatting.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Backup_Monitor
 *
 * @package MainWP\Dashboard
 */
class MainWP_Backup_Monitor { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * The error code a child returns for an action its build does not carry.
     *
     * It is the child's own "you are running an older MainWP Child" answer, so
     * it positively identifies a build too old for the callable rather than a
     * transient failure.
     *
     * @var string
     */
    const CHILD_INCOMPATIBLE_CODE = 'child_plugin_incompatible';

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

    /**
     * Method backup_check_pid()
     *
     * Check backup pid.
     *
     * @param int         $pSiteId   Child Site id.
     * @param int|string  $pid       Backup pid.
     * @param string      $type      full|db Type of backup.
     * @param string      $subfolder Sub folder for backup.
     * @param string|null $pFilename Backups filename.
     *
     * @return array $status, $result.
     * @throws MainWP_Exception Error message.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_mainwp_specific_dir()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses \MainWP\Dashboard\MainWP_Utility::end_session()
     */
    public static function backup_check_pid( int $pSiteId, int|string $pid, string $type, string $subfolder, ?string $pFilename ): array {
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
            $subfolder = MainWP_Backup_File_Manager::normalize_filename( $subfolder );

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
                $localBackupFile = $dir . 'db-' . $websiteCleanUrl . '-' . $fm_date . '-' . $fm_time . MainWP_Backup_File_Manager::get_real_extension( $information['file'] );
                $localRegexFile  = 'db-' . $websiteCleanUrl . '-(.*)-(.*).sql(\.zip|\.tar|\.tar\.gz|\.tar\.bz2)?';
            } else {
                $localBackupFile = $dir . 'full-' . $websiteCleanUrl . '-' . $fm_date . '-' . $fm_time . MainWP_Backup_File_Manager::get_real_extension( $information['file'] );
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
                $localBackupFile = MainWP_Backup_File_Manager::normalize_filename( $localBackupFile );

                $localBackupFile .= MainWP_Backup_File_Manager::get_real_extension( $information['file'] );
            }

            $result['local']     = $localBackupFile;
            $result['regexfile'] = $localRegexFile;
        }

        return array(
            'status' => $status,
            'result' => $result,
        );
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
     * @param mixed $entries Error entries, one per failed operation.
     * @param int   $max     Maximum entries to keep (default 10).
     *
     * @return string Formatted HTML, empty when there are no entries.
     */
    public static function format_backup_errors( mixed $entries, int $max = 10 ): string {
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
     * the backup when the child disk space is critically low, switches ZIP to
     * tar.gz on a large site (unless the task forces ZIP), and logs advisory
     * warnings for the rest of the report.
     *
     * @param object      $website      Website object.
     * @param string      $archive_ext  Requested archive extension (zip, tar.gz, tar.bz2, tar).
     *                                   Passed by reference: switched to tar.gz when a large ZIP site
     *                                   is auto-negotiated, so the caller sends the adjusted format.
     * @param object|null $task         Backup task, when known, so the ZIP large-site outcome can be
     *                                   surfaced on it and the zip_force_override flag honored.
     * @param bool        $allow_switch Whether the caller can receive a negotiated format. A caller
     *                                   that ignores the by-reference mutation (the deprecated shim)
     *                                   passes false and gets the warn-only behavior, so the log
     *                                   never claims a switch that did not reach the wire.
     *
     * @return void
     * @throws MainWP_Exception When the child reports critically low disk space.
     */
    public static function negotiate_backup_preflight( object &$website, string &$archive_ext = '', ?object $task = null, bool $allow_switch = true ): void { // phpcs:ignore -- NOSONAR - complex.
        $diag_task_id = is_object( $task ) && ! empty( $task->id ) ? (int) $task->id : 0;

        try {
            $preflight = MainWP_Connect::fetch_with_retry( $website, 'backup_preflight', array() );
        } catch ( \Exception $e ) {
            MainWP_Logger::instance()->warning_for_website( $website, 'backup', 'Backup preflight unavailable: ' . $e->getMessage() );

            /**
             * Fires after a preflight negotiation with the child's readiness
             * report, so the diagnostics recorder can capture it.
             *
             * @param int    $site_id   Child site id.
             * @param int    $task_id   Backup task id, 0 when none drives the run.
             * @param array  $preflight Child readiness report, empty when unavailable.
             * @param string $status    ok|unavailable|unsupported.
             */
            do_action( 'mainwp_backup_diag_preflight', (int) $website->id, $diag_task_id, array(), 'unavailable' );
            return; // Capability unknown - proceed for backwards compatibility.
        }

        if ( ! is_array( $preflight ) || isset( $preflight['error'] ) ) {
            // Old child site without the preflight action - proceed without it.
            //
            // A child that answers child_plugin_incompatible has positively
            // reported that its build does not carry the callable at all. That
            // is not the same as an unreachable child, so it is safe to read as
            // a downgrade and clear the negotiated capabilities: otherwise a
            // site that once ran a task-spine child keeps claiming the spine
            // forever, and the backup gate waves through a child that cannot
            // run the task. Any other error may be transient, so the last known
            // capabilities stand rather than a single bad response stripping a
            // healthy site.
            if ( is_array( $preflight ) && isset( $preflight['error_code'] ) && self::CHILD_INCOMPATIBLE_CODE === $preflight['error_code'] ) {
                static::record_negotiated_capabilities( $website, array() );
            }

            MainWP_Logger::instance()->info_for_website( $website, 'backup', 'Backup preflight not supported by the child site; proceeding without it.' );
            /** This action is documented in class/backup/class-mainwp-backup-monitor.php */
            do_action( 'mainwp_backup_diag_preflight', (int) $website->id, $diag_task_id, is_array( $preflight ) ? $preflight : array(), 'unsupported' );
            return;
        }

        /** This action is documented in class/backup/class-mainwp-backup-monitor.php */
        do_action( 'mainwp_backup_diag_preflight', (int) $website->id, $diag_task_id, $preflight, 'ok' );

        // Byte quantities compare as floats: a 32-bit (int) cast overflows on
        // anything over ~2GB, which here would flip a healthy disk_free
        // negative and falsely abort as disk-full.
        if ( isset( $preflight['disk_free'] ) && false !== $preflight['disk_free'] && is_numeric( $preflight['disk_free'] ) && (float) $preflight['disk_free'] < 50 * MB_IN_BYTES ) {
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
            do_action( 'mainwp_log_warning', 'Child site cannot call set_time_limit(). Large backups may time out.', (int) $website->id, $diag_task_id );
        }

        if ( ! empty( $preflight['disk_warning'] ) && is_string( $preflight['disk_warning'] ) ) {
            do_action( 'mainwp_log_warning', 'Child site disk warning: ' . MainWP_Backup_Error_Codes::sanitize_message( $preflight['disk_warning'] ), (int) $website->id, $diag_task_id );
        }

        if ( $allow_switch && 'zip' === $archive_ext && isset( $preflight['zip_support'] ) && ! $preflight['zip_support'] && ! empty( $preflight['gz_support'] ) ) {
            $archive_ext = 'tar.gz';
            $zip_warning = esc_html__( 'ZIP was switched to tar.gz because this child lacks ext-zip and cannot create a resumable ZIP backup, even with the keep ZIP override enabled.', 'mainwp' );
            do_action( 'mainwp_log_warning', $zip_warning, (int) $website->id, $diag_task_id );
            if ( is_object( $task ) && ! empty( $task->id ) && MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id ) ) {
                MainWP_DB_Backup::instance()->update_backup_notices( $task->id, $zip_warning . "\n" );
            }
        }

        $format_support = array(
            'zip'     => 'zip_support',
            'tar.gz'  => 'gz_support',
            'tar.bz2' => 'bz2_support',
        );
        if ( isset( $format_support[ $archive_ext ] ) && isset( $preflight[ $format_support[ $archive_ext ] ] ) && ! $preflight[ $format_support[ $archive_ext ] ] ) {
            do_action( 'mainwp_log_warning', sprintf( 'Child site reports no support for the requested %s archive format.', $archive_ext ), (int) $website->id, $diag_task_id );
        }

        // ZipArchive resumes after a slice timeout, but it closes the archive at
        // the end of every slice and ZipArchive::close() rewrites the whole file,
        // so on a large site each slice gets slower and the close needs free disk
        // equal to the archive; tar.gz appends. A child without ext-zip falls back
        // to PclZip, which resumes not at all - the copy below therefore claims
        // only the rewrite cost, never that ZIP resumes. Over the guardrail
        // threshold, switch ZIP to tar.gz so the backup can actually finish,
        // unless the task explicitly forces ZIP.
        // Either way, surface the outcome on the task so it is visible before
        // the run.
        if ( 'zip' === $archive_ext && isset( $preflight['estimated_bytes'] ) && is_numeric( $preflight['estimated_bytes'] ) ) {
            // Float casts, not (int): the 2GB default overflows a 32-bit int,
            // which would zero the threshold and silently disable the
            // guardrail. The size_format() argument may stay float too - zero
            // is unreachable inside this block ($zip_threshold > 0 gates it),
            // so size_format()'s strict int-zero case never bites here.
            $zip_threshold = (float) apply_filters( 'mainwp_zip_guardrail_bytes', 2 * GB_IN_BYTES );
            if ( $zip_threshold > 0 && (float) $preflight['estimated_bytes'] > $zip_threshold ) {
                $estimate = esc_html( size_format( (float) $preflight['estimated_bytes'] ) );
                $keep_zip = ! $allow_switch || ( is_object( $task ) && 1 === (int) ( $task->zip_force_override ?? 0 ) );
                if ( $keep_zip ) {
                    $zip_warning = sprintf(
                        /* translators: %s: estimated backup size */
                        esc_html__( 'ZIP is not recommended for sites this large (estimated %s). ZIP rewrites the whole archive after every slice, so slices slow down as the backup grows and the site needs as much free disk as the archive itself. tar.gz appends and does not have that cost.', 'mainwp' ),
                        $estimate
                    );
                } else {
                    $archive_ext = 'tar.gz';
                    $zip_warning = sprintf(
                        /* translators: %s: estimated backup size */
                        esc_html__( 'ZIP was switched to tar.gz for this large site (estimated %s). ZIP rewrites the whole archive after every slice, so slices slow down as the backup grows and the site needs as much free disk as the archive itself. tar.gz appends and does not have that cost.', 'mainwp' ),
                        $estimate
                    );
                    // Only a visible scheduled task carries the settings form
                    // with the override checkbox; the hidden on-demand task
                    // must not advertise a control the user cannot reach.
                    if ( is_object( $task ) && empty( $task->template ) ) {
                        $zip_warning .= ' ' . esc_html__( 'Enable "keep ZIP" on the backup task to override.', 'mainwp' );
                    }
                }
                do_action( 'mainwp_log_warning', $zip_warning, (int) $website->id, $diag_task_id );
                // Advisory outcome, not a failure: record it on the notices
                // channel so it never flips a successful run to "failed" in
                // the poll, the failure email, or the task list.
                if ( is_object( $task ) && ! empty( $task->id ) && MainWP_DB_Backup::instance()->get_backup_task_by_id( $task->id ) ) {
                    MainWP_DB_Backup::instance()->update_backup_notices( $task->id, $zip_warning . "\n" );
                }
            }
        }

        // The file-walker holds the whole entry list in memory while it sorts,
        // so a very large file count is an advisory memory risk for any format.
        if ( isset( $preflight['file_count'] ) && is_numeric( $preflight['file_count'] ) ) {
            $filecount_advisory = (int) apply_filters( 'mainwp_backup_filecount_advisory', 100000 );
            if ( $filecount_advisory > 0 && (int) $preflight['file_count'] > $filecount_advisory ) {
                do_action( 'mainwp_log_warning', sprintf( 'Child site reports a large file count (%d); backups may need extra memory.', (int) $preflight['file_count'] ), (int) $website->id, $diag_task_id );
            }
        }

        if ( ! empty( $preflight['backup_dir_hash'] ) && is_string( $preflight['backup_dir_hash'] ) ) {
            $hash = preg_replace( '/[^a-zA-Z0-9]/', '', $preflight['backup_dir_hash'] );
            if ( '' !== $hash ) {
                MainWP_DB::instance()->update_website_option( $website, 'backup_dir_hash', $hash );
            }
        }

        static::record_negotiated_capabilities( $website, $preflight );
    }

    /**
     * Re-read a child's capability report on demand, with none of the backup
     * side effects.
     *
     * The restore gate needs a current answer, and the stored flags are only
     * ever written by a backup preflight, so they go stale on exactly the child
     * update a refusal asks the user to perform. This is not
     * negotiate_backup_preflight(): that one throws on critically low child disk
     * space and can switch a task's archive format, neither of which belongs on
     * a restore, and it reports to the per-backup diagnostics recorder, which
     * has no task to attribute this call to.
     *
     * @param object $website Website row.
     *
     * @return void
     */
    public static function refresh_negotiated_capabilities( object $website ): void {
        $clamp = static function ( $timeout ) {
            return is_numeric( $timeout ) && $timeout > 0 ? max( 1, min( (int) $timeout, self::CAPABILITY_PROBE_TIMEOUT_SECONDS ) ) : 1;
        };
        add_filter( 'mainwp_fetch_url_site_timeout', $clamp, PHP_INT_MAX );
        try {
            $preflight = MainWP_Connect::fetch_url_authed( $website, 'backup_preflight', array( 'capabilities_only' => 1 ), false, false, true );
        } catch ( \Throwable $e ) {
            MainWP_Logger::instance()->warning_for_website( $website, 'backup', 'Capability probe unavailable: ' . $e->getMessage() );
            // An unreachable child says nothing about its capabilities, so the
            // last negotiated flags stand rather than being cleared by a site
            // that is merely down.
            return;
        } finally {
            remove_filter( 'mainwp_fetch_url_site_timeout', $clamp, PHP_INT_MAX );
        }

        if ( ! is_array( $preflight ) || isset( $preflight['error'] ) ) {
            if ( is_array( $preflight ) && isset( $preflight['error_code'] ) && self::CHILD_INCOMPATIBLE_CODE === $preflight['error_code'] ) {
                static::record_negotiated_capabilities( $website, array() );
            }
            return;
        }

        static::record_negotiated_capabilities( $website, $preflight );
    }

    /**
     * Record the capability flags a child's preflight report advertises.
     *
     * An empty report clears every flag, which is exactly what a child
     * downgrade looks like: the site stops claiming a capability it can no
     * longer serve, so the backup gate refuses it instead of sending a task
     * the child cannot understand.
     *
     * @param object $website   Website row.
     * @param array  $preflight Child readiness report. Empty for a child whose
     *                          build has no preflight action at all.
     *
     * @return void
     */
    protected static function record_negotiated_capabilities( object $website, array $preflight ): void {
        $flags = array(
            'task_spine_support'                 => 'task_spine',
            'download_token_support'             => 'download_token',
            'incremental_support'                => 'incremental',
            'manifest_chunk_support'             => 'manifest_chunk',
            'restore_create_idempotency_support' => 'restore_create_idempotency',
            'restore_files_support'              => 'restore_files',
            'cloud_restore_pull_support'         => 'cloud_restore_pull',
            'child_is_multisite'                 => 'is_multisite',
            'restore_multisite_support'          => 'restore_multisite',
        );

        // An absent capability is stored as '0' rather than ''. WordPress core's
        // wpdb::get_var() ends with "isset( $values[ $x ] ) && '' !== $values[ $x ]",
        // so an empty column reads back as null and '' is indistinguishable from a
        // site that has never been negotiated. The restore gate has to tell those
        // apart: unknown re-probes the child, a recorded '0' refuses. Every reader
        // compares against '1', so the stored '0' behaves exactly as '' did.
        foreach ( $flags as $option => $reported ) {
            MainWP_DB::instance()->update_website_option( $website, $option, empty( $preflight[ $reported ] ) ? '0' : '1' );
        }
    }

    /**
     * Method child_supports_task_spine()
     *
     * Whether the child site advertised the resumable backup task machine
     * in its last preflight report.
     *
     * @param object $website Website object.
     *
     * @return bool True when the child supports resumable backup tasks.
     */
    public static function child_supports_task_spine( object $website ): bool {
        return '1' === (string) MainWP_DB::instance()->get_website_option( $website, 'task_spine_support' );
    }

    /**
     * Method child_supports_download_token()
     *
     * Whether the child site advertised one-time download tokens in its
     * last preflight report.
     *
     * @param object $website Website object.
     *
     * @return bool True when the child issues download tokens.
     */
    public static function child_supports_download_token( object $website ): bool {
        return '1' === (string) MainWP_DB::instance()->get_website_option( $website, 'download_token_support' );
    }

    /**
     * Method child_supports_incremental()
     *
     * Whether the child site advertised incremental backup support in its
     * last preflight report.
     *
     * @param object $website Website object.
     *
     * @return bool True when the child can run incremental backups.
     */
    public static function child_supports_incremental( object $website ): bool {
        return '1' === (string) MainWP_DB::instance()->get_website_option( $website, 'incremental_support' );
    }

    /**
     * Method child_supports_manifest_chunk()
     *
     * Whether the child site advertised chunked parent-manifest staging
     * in its last preflight report.
     *
     * @param object $website Website object.
     *
     * @return bool True when the child can stage a chunked manifest.
     */
    public static function child_supports_manifest_chunk( object $website ): bool {
        return '1' === (string) MainWP_DB::instance()->get_website_option( $website, 'manifest_chunk_support' );
    }

    /**
     * Method child_supports_restore_files()
     *
     * Whether the child site advertised the files-only restore pipeline
     * in its last preflight report.
     *
     * @param object $website Website object.
     *
     * @return bool True when the child can restore a files snapshot.
     */
    public static function child_supports_restore_files( object $website ): bool {
        return '1' === (string) MainWP_DB::instance()->get_website_option( $website, 'restore_files_support' );
    }

    /**
     * Method child_supports_cloud_pull()
     *
     * Whether the child site advertised cloud-pull restore support in
     * its last preflight report.
     *
     * @param object $website Website object.
     *
     * @return bool True when restores can pull artifacts from cloud storage directly.
     */
    public static function child_supports_cloud_pull( object $website ): bool {
        return '1' === (string) MainWP_DB::instance()->get_website_option( $website, 'cloud_restore_pull_support' );
    }

    /**
     * Method child_is_multisite()
     *
     * Whether the child reported running a WordPress multisite network
     * in its last preflight report.
     *
     * @param object $website Website object.
     *
     * @return bool True when the child is a multisite install.
     */
    public static function child_is_multisite( object $website ): bool {
        return '1' === (string) MainWP_DB::instance()->get_website_option( $website, 'child_is_multisite' );
    }

    /**
     * Method child_supports_restore_multisite()
     *
     * Whether the child site advertised whole-network restore support
     * in its last preflight report.
     *
     * @param object $website Website object.
     *
     * @return bool True when the child can restore a multisite network.
     */
    public static function child_supports_restore_multisite( object $website ): bool {
        return '1' === (string) MainWP_DB::instance()->get_website_option( $website, 'restore_multisite_support' );
    }

    /**
     * Method child_supports_restore_create_idempotency()
     *
     * Whether the child site advertised idempotent restore creation
     * in its last preflight report.
     *
     * @param object $website Website object.
     *
     * @return bool True when the child reuses the first task for a restore id.
     */
    public static function child_supports_restore_create_idempotency( object $website ): bool {
        return '1' === (string) MainWP_DB::instance()->get_website_option( $website, 'restore_create_idempotency_support' );
    }

    /**
     * Whether restore-create idempotency has been negotiated for this site.
     *
     * A site that has never reported the capability - every site right after a
     * Dashboard update, and any site whose preflight has not been reachable
     * since - reads as unknown, not as unsupported, so the restore gate leaves
     * it alone instead of taking the Restore button away.
     *
     * @param object $website Website object.
     *
     * @return bool True when the capability option has been recorded.
     */
    public static function child_reported_restore_create_idempotency( object $website ): bool {
        return null !== MainWP_DB::instance()->get_website_option( $website, 'restore_create_idempotency_support' );
    }

    /**
     * Whether the child's reported capabilities require refusing a restore.
     *
     * @param object $website Website object.
     *
     * @return bool True when idempotent restore creation is known to be unsupported.
     */
    public static function child_requires_restore_create_idempotency_update( object $website ): bool {
        return self::child_reported_restore_create_idempotency( $website ) && ! self::child_supports_restore_create_idempotency( $website );
    }

    /**
     * Option-name prefix for the fixed slot rows of the DB semaphore.
     * Slot N lives at "mainwp_backup_slot_N"; the fixed names 1..max are
     * what caps concurrency - a slot is held by owning its row.
     *
     * @var string
     */
    const SLOT_OPTION_PREFIX = 'mainwp_backup_slot_';

    /**
     * Bound applied to a child round trip made under a native lease.
     *
     * Native requests otherwise inherit the transport's 20-hour backstop,
     * which is no bound at all: a child that stops answering holds the
     * slot and a PHP worker for the rest of the day. 900 and not something
     * shorter because backup_task_create can carry an inline incremental
     * manifest of ~6 MiB base64 and has no chunk fallback on timeout, so
     * the bound has to cover that upload on a slow uplink (~7 KiB/s).
     *
     * @var int
     */
    const NATIVE_REQUEST_TIMEOUT_SECONDS = 900;

    /**
     * Bound capability reads made during an admin request.
     *
     * The report is only a few hundred bytes; an admin request cannot wait
     * through a stalled child. A failed probe falls back to the stored answer.
     *
     * @var int
     */
    const CAPABILITY_PROBE_TIMEOUT_SECONDS = 15;

    /**
     * Bound applied to one artifact transfer attempt under a native lease.
     * Today's transport default, so this only clamps a filter user that
     * raised it past what a lease can cover.
     *
     * @var int
     */
    const NATIVE_TRANSFER_TIMEOUT_SECONDS = 1800;

    /**
     * Floor for a native slot lease: it has to outlast the holder's own
     * work between two child requests - site loop bookkeeping, DB writes,
     * the settle grace, hashing a downloaded artifact.
     *
     * @var int
     */
    const DEFAULT_SLOT_LEASE_SECONDS = 10 * MINUTE_IN_SECONDS;

    /**
     * Slack added to a request's bound when it becomes the lease, so the
     * lease outlives the request it covers rather than expiring with it.
     *
     * @var int
     */
    const SLOT_LEASE_MARGIN_SECONDS = 60;

    /**
     * Least coverage a request can still be bounded by. Below this the
     * row is gone rather than merely short, and the request is cut to
     * this floor so it ends quickly and the holder reaches its own stop.
     *
     * @var int
     */
    private const MIN_REQUEST_SECONDS = 30;

    /**
     * Native slot tokens whose holder is executing right now.
     *
     * Both transport filters are process-global and carry no lane, so the
     * clamp and the heartbeat are scoped by this stack instead of by token
     * possession: a legacy holder in the same process is never touched.
     *
     * @var string[]
     */
    private static array $native_lease_tokens = array();

    /**
     * The installed request-timeout callback, kept so remove_filter() gets
     * the same closure back.
     *
     * @var \Closure|null
     */
    private static ?\Closure $request_timeout_filter = null;

    /**
     * The installed transfer-timeout callback, kept for the same reason.
     *
     * @var \Closure|null
     */
    private static ?\Closure $transfer_timeout_filter = null;

    /**
     * Whether the holder in scope has lost the slot row it was working
     * under.
     *
     * Process-level and not per-token: every lane that opens a scope
     * holds one slot for the length of one run, which is the assumption
     * the scope stack itself already makes. The holder's own loop reads
     * this and stops, so the transport and executor layers below it need
     * no new failure to rethrow.
     *
     * @var bool
     */
    private static bool $native_lease_lost = false;

    /**
     * Method acquire_backup_slot()
     *
     * Acquire a backup execution slot from the DB semaphore. Each slot is
     * one fixed-name wp_options row; the table's UNIQUE(option_name) key
     * makes the INSERT the atomic acquisition - exactly one contender
     * wins each slot, enforced by the database rather than a best-effort
     * transient read-modify-write. A stale row is reclaimed by
     * compare-and-delete on the exact stored value, so racing reclaimers
     * cannot double-free.
     *
     * How long a crashed holder blocks the slot depends on the lane. A
     * native holder refreshes a lease on its row before every child
     * request, bounded by that request's own timeout, so its row goes
     * stale minutes after the process dies. A legacy holder makes one
     * round trip in which the child builds the whole archive, with no
     * bound worth leasing against, so its row keeps the two-hour rule -
     * as do rows written before this lane existed.
     *
     * All reads and writes go straight to the table: the options/
     * notoptions caches never see these names, so an external object
     * cache cannot serve a stale slot state.
     *
     * @param int    $max_concurrent Maximum concurrent backup tasks (default 2).
     * @param string $lane           native|legacy. Defaults to legacy so the
     *                               deprecated shim and any outside caller keep
     *                               today's semantics untouched; a native holder
     *                               must also open a lease scope to refresh the
     *                               row it just claimed.
     *
     * @return string|false Slot token on success, false when all slots are in use.
     */
    public static function acquire_backup_slot( int $max_concurrent = 2, string $lane = 'legacy' ): string|false {
        global $wpdb;

        $now   = time();
        $token = wp_generate_password( 12, false );
        $lane  = 'native' === $lane ? 'native' : 'legacy';
        $claim = array(
            'token'   => $token,
            'started' => $now,
            'pid'     => getmypid(),
            'lane'    => $lane,
        );
        if ( 'native' === $lane ) {
            $claim['heartbeat'] = $now;
            $claim['lease']     = self::DEFAULT_SLOT_LEASE_SECONDS;
        }
        $value = (string) wp_json_encode( $claim );

        // A live holder in a slot above a just-lowered maximum still occupies
        // capacity, but the fixed-name loop below only visits slots 1..max and
        // would never see it. Count every live holder first and refuse while
        // the total is already at or over the limit, so reducing the concurrency
        // setting mid-flight cannot admit work past the new cap: the above-limit
        // rows drain on release, then new claims resume.
        $live = self::count_live_slots( $now );
        if ( $live >= $max_concurrent ) {
            /** This action is documented in class/backup/class-mainwp-backup-monitor.php */
            do_action( 'mainwp_backup_diag_slot', 'refused', '', $live, $max_concurrent );
            return false;
        }

        for ( $slot = 1; $slot <= $max_concurrent; $slot++ ) {
            $name = self::SLOT_OPTION_PREFIX . $slot;

            $held = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the semaphore must bypass the options caches.
                $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name )
            );
            if ( null !== $held ) {
                $data = json_decode( (string) $held, true );
                if ( self::is_slot_live( $data, $now ) ) {
                    continue; // Live holder; try the next slot.
                }
                // Reclaim by exact value: a contender that lost this DELETE
                // race deletes nothing and then loses the INSERT race too.
                $reclaimed = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- compare-and-delete reclaim.
                    $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, (string) $held )
                );
                if ( 1 === $reclaimed ) {
                    /** This action is documented in class/backup/class-mainwp-backup-monitor.php */
                    do_action( 'mainwp_backup_diag_slot', 'reclaimed_stale', is_array( $data ) && isset( $data['token'] ) ? (string) $data['token'] : '', self::count_live_slots( $now ), $max_concurrent );
                }
            }

            // UNIQUE(option_name) makes this the atomic claim: 1 row means
            // this contender owns the slot, 0 means another one won it.
            $claimed = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the INSERT is the semaphore's atomic primitive.
                $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $value )
            );
            if ( 1 === $claimed ) {
                /**
                 * Fires on each concurrency slot decision, so slot exhaustion
                 * leaves a diagnostics trace instead of vanishing with the
                 * released row.
                 *
                 * @param string $event          acquired|refused|released|reclaimed_stale|lost.
                 * @param string $token          Slot token, '' on refusal.
                 * @param int    $active         Active slot count after the decision.
                 * @param int    $max_concurrent Configured slot limit.
                 */
                do_action( 'mainwp_backup_diag_slot', 'acquired', $token, self::count_live_slots( $now ), $max_concurrent );
                return $token;
            }
        }

        /** This action is documented in class/backup/class-mainwp-backup-monitor.php */
        do_action( 'mainwp_backup_diag_slot', 'refused', '', self::count_live_slots( $now ), $max_concurrent );
        return false;
    }

    /**
     * Method release_backup_slot()
     *
     * Release a backup execution slot: compare-and-delete the slot row
     * the token owns. A token that no longer owns a row (its stale slot
     * was reclaimed) deletes nothing.
     *
     * @param string $token Slot token from acquire_backup_slot().
     *
     * @return void
     */
    public static function release_backup_slot( string $token ): void {
        global $wpdb;

        if ( '' === $token ) {
            return;
        }

        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the semaphore must bypass the options caches.
            $wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( self::SLOT_OPTION_PREFIX ) . '%'
            )
        );

        foreach ( (array) $rows as $row ) {
            $data = json_decode( (string) $row->option_value, true );
            if ( ! is_array( $data ) || ! isset( $data['token'] ) || $token !== $data['token'] ) {
                continue;
            }
            $released = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- compare-and-delete release.
                $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $row->option_name, (string) $row->option_value )
            );
            if ( 1 === $released ) {
                /** This action is documented in class/backup/class-mainwp-backup-monitor.php */
                do_action( 'mainwp_backup_diag_slot', 'released', $token, self::count_live_slots( time() ), 0 );
            }
            return;
        }
    }

    /**
     * Method begin_native_lease()
     *
     * Open the lease scope for a native holder: from here until the
     * matching end_native_lease(), every child request the process makes
     * is bounded and refreshes this holder's slot row.
     *
     * @param string $token Slot token from acquire_backup_slot().
     *
     * @return void
     */
    public static function begin_native_lease( string $token ): void {
        if ( '' === $token ) {
            return;
        }

        $was_empty                   = empty( self::$native_lease_tokens );
        self::$native_lease_tokens[] = $token;
        if ( ! $was_empty ) {
            return;
        }

        self::$native_lease_lost = false;

        // Last in line, so the clamp lands after every other filter user;
        // a smaller cap (the restore orchestrator's slice bound) still wins
        // through the min() inside the resolver.
        self::$request_timeout_filter  = static function ( $timeout ) {
            return self::resolve_native_bound( $timeout, self::NATIVE_REQUEST_TIMEOUT_SECONDS );
        };
        self::$transfer_timeout_filter = static function ( $timeouts ) {
            if ( ! is_array( $timeouts ) || ! isset( $timeouts['timeout'] ) ) {
                return $timeouts;
            }
            // Only the total is bounded: connect time is inside libcurl's
            // total timeout, so leasing against it as well would let a
            // raised connect_timeout inflate a dead holder's lease past the
            // attempt it covers.
            $timeouts['timeout'] = self::resolve_native_bound( $timeouts['timeout'], self::NATIVE_TRANSFER_TIMEOUT_SECONDS );
            return $timeouts;
        };

        add_filter( 'mainwp_fetch_url_site_timeout', self::$request_timeout_filter, PHP_INT_MAX );
        add_filter( 'mainwp_download_to_file_timeouts', self::$transfer_timeout_filter, PHP_INT_MAX );
    }

    /**
     * Method end_native_lease()
     *
     * Close one holder's lease scope. A token that is not in scope is a
     * no-op, so a finally block can call this unconditionally.
     *
     * @param string $token Slot token from acquire_backup_slot().
     *
     * @return void
     */
    public static function end_native_lease( string $token ): void {
        $at = array_search( $token, self::$native_lease_tokens, true );
        if ( false === $at ) {
            return;
        }

        unset( self::$native_lease_tokens[ $at ] );
        self::$native_lease_tokens = array_values( self::$native_lease_tokens );
        if ( ! empty( self::$native_lease_tokens ) ) {
            return;
        }

        self::$native_lease_lost = false;

        if ( null !== self::$request_timeout_filter ) {
            remove_filter( 'mainwp_fetch_url_site_timeout', self::$request_timeout_filter, PHP_INT_MAX );
            self::$request_timeout_filter = null;
        }
        if ( null !== self::$transfer_timeout_filter ) {
            remove_filter( 'mainwp_download_to_file_timeouts', self::$transfer_timeout_filter, PHP_INT_MAX );
            self::$transfer_timeout_filter = null;
        }
    }

    /**
     * Method native_lease_lost()
     *
     * Whether the holder in scope has lost its slot: a refresh found the
     * row it was working under gone, so another process may already own
     * that slot. The holder's own loop is where the run stops on this.
     *
     * @return bool True once the loss has been seen in this scope.
     */
    public static function native_lease_lost(): bool {
        return self::$native_lease_lost;
    }

    /**
     * Method confirm_native_lease()
     *
     * Ask the slot row, rather than the last touch's memory, whether this
     * holder still owns its slot.
     *
     * A lease can be reclaimed by another reader between two of this
     * holder's touches - a reader whose clock runs behind reads a fresh
     * heartbeat as one from the future and treats the row as dead - and
     * native_lease_lost() only reports what the last touch saw. So a
     * decision that consumes the result of I/O, or that completes work,
     * re-validates here instead: the refresh either renews the row or
     * finds it gone and marks the lease lost.
     *
     * @return bool True while the holder still owns its slot; true as
     *              well outside a lease scope, where there is no slot to
     *              lose.
     */
    public static function confirm_native_lease(): bool {
        if ( empty( self::$native_lease_tokens ) ) {
            return true;
        }

        self::touch_native_leases( self::DEFAULT_SLOT_LEASE_SECONDS );

        return ! self::native_lease_lost();
    }

    /**
     * Flag the holder in scope as having lost its slot, tracing it once.
     *
     * Both doors into this state - a refresh that found no row, and a
     * request the remaining coverage cannot bound - land here, and every
     * later request in the same run would find the same thing, so the
     * trace fires on the transition rather than per call.
     *
     * @return void
     */
    private static function mark_native_lease_lost(): void {
        if ( self::$native_lease_lost ) {
            return;
        }

        self::$native_lease_lost = true;

        /** This action is documented in class/backup/class-mainwp-backup-monitor.php */
        do_action( 'mainwp_backup_diag_slot', 'lost', (string) ( self::$native_lease_tokens[0] ?? '' ), self::count_live_slots( time() ), 0 );
    }

    /**
     * Bound one request under a native lease and refresh the lease that
     * bounds it.
     *
     * A request never outlives the coverage its slot row actually
     * carries, so a heartbeat write that did not land buys nothing.
     * Coverage too short to bound a request by means the row is gone -
     * reclaimed, and by now very likely re-owned - and the request is cut
     * to the floor so it ends quickly. Stopping the run is the holder's
     * decision, not a throw through every catch between here and it.
     *
     * @param mixed $timeout_in Timeout the transport offered. Non-numeric, non-finite and non-positive all mean no bound at all, which is libcurl's unlimited deadline.
     * @param int   $cap        Native bound for this kind of request.
     *
     * @return int Seconds the request may take.
     */
    private static function resolve_native_bound( mixed $timeout_in, int $cap ): int {
        // Compared and rounded as a float before the cast: (int) truncates,
        // so a sub-second offer would become 0 - the unlimited deadline this
        // is here to prevent - and INF or a value past PHP_INT_MAX would cast
        // to a number that is not a timeout at all.
        $n = is_numeric( $timeout_in ) ? (float) $timeout_in : 0.0;
        if ( ! is_finite( $n ) || $n <= 0 ) {
            $bound = $cap;
        } else {
            $bound = (int) min( max( 1.0, ceil( $n ) ), $cap );
        }

        $covered = self::touch_for_request( $bound + self::SLOT_LEASE_MARGIN_SECONDS );

        if ( PHP_INT_MAX === $covered ) {
            return $bound;
        }

        $usable = $covered - self::SLOT_LEASE_MARGIN_SECONDS;
        if ( $usable >= $bound ) {
            return $bound;
        }
        if ( $usable >= self::MIN_REQUEST_SECONDS ) {
            return $usable;
        }

        self::mark_native_lease_lost();
        return self::MIN_REQUEST_SECONDS;
    }

    /**
     * Refresh the leases from inside a timeout filter.
     *
     * A diagnostics or DB hiccup here must never propagate as itself: the
     * filter has to decide on a number, and a request with no timeout is
     * exactly the failure the lease exists to prevent. A throw counts as
     * no coverage, which the caller then treats like a lost slot rather
     * than assuming a lease that may not have been written.
     *
     * @param int $lease_seconds Lease the pending request needs.
     *
     * @return int Seconds of lease coverage the slot rows carry from now.
     */
    private static function touch_for_request( int $lease_seconds ): int {
        try {
            return self::touch_native_leases( $lease_seconds );
        } catch ( \Throwable $e ) {
            unset( $e );
            return 0;
        }
    }

    /**
     * Method touch_native_leases()
     *
     * Refresh every slot row held by a token in the current lease scope.
     * A holder whose row was already reclaimed and re-owned updates zero
     * rows and is left alone: it has lost its slot, and its release will
     * delete nothing, which is the existing contract.
     *
     * The return value is what the caller bounds the pending request by,
     * so a write that did not land cannot leave a request outliving the
     * lease its row actually carries: it reports the coverage the stored
     * rows have, not the coverage that was asked for. A token left with
     * no coverage at all also flags the scope as having lost its slot, so
     * the direct ticks - the site loop, the artifact hash - read
     * native_lease_lost() instead of interpreting this number.
     *
     * @param int $lease_seconds Lease to write, floored at DEFAULT_SLOT_LEASE_SECONDS.
     *
     * @return int Seconds of coverage guaranteed from now: the smallest
     *             heartbeat + lease still stored for a token in scope, 0
     *             for a token whose row is gone, PHP_INT_MAX when nothing
     *             is in scope.
     */
    public static function touch_native_leases( int $lease_seconds ): int {
        global $wpdb;

        if ( empty( self::$native_lease_tokens ) ) {
            return PHP_INT_MAX;
        }

        $now   = time();
        $lease = max( self::DEFAULT_SLOT_LEASE_SECONDS, $lease_seconds );

        $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the semaphore must bypass the options caches.
            $wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( self::SLOT_OPTION_PREFIX ) . '%'
            )
        );

        $covered = array_fill_keys( self::$native_lease_tokens, 0 );

        foreach ( (array) $rows as $row ) {
            $data = json_decode( (string) $row->option_value, true );
            if ( ! is_array( $data ) || ! isset( $data['token'] ) || ! in_array( (string) $data['token'], self::$native_lease_tokens, true ) ) {
                continue;
            }
            $token             = (string) $data['token'];
            $stored            = (int) ( $data['heartbeat'] ?? 0 ) + (int) ( $data['lease'] ?? 0 ) - $now;
            $data['heartbeat'] = $now;
            $data['lease']     = $lease;
            $written           = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- compare-and-update heartbeat.
                $wpdb->prepare(
                    "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                    (string) wp_json_encode( $data ),
                    $row->option_name,
                    (string) $row->option_value
                )
            );

            $covered[ $token ] = 1 === $written ? $lease : $stored;
        }

        $lowest = (int) min( $covered );
        if ( $lowest <= 0 ) {
            self::mark_native_lease_lost();
        }
        return $lowest;
    }

    /**
     * Whether a decoded slot row still belongs to a live holder.
     *
     * The pre-check count and the reclaim loop both ask this, so they
     * cannot disagree about the same row at the same time.
     *
     * @param mixed $data Decoded slot value; anything at all, the row may be malformed.
     * @param int   $now  Current time.
     *
     * @return bool True while the holder still owns the slot.
     */
    private static function is_slot_live( mixed $data, int $now ): bool {
        if ( ! is_array( $data ) || ! isset( $data['started'] ) ) {
            return false;
        }

        // A row with no usable token can never be released: both the
        // release and the reclaim match on it, so treating one as live
        // would hold the slot until it aged out.
        if ( ! isset( $data['token'] ) || ! is_string( $data['token'] ) || '' === $data['token'] ) {
            return false;
        }

        if ( 'native' === ( $data['lane'] ?? '' )
            && isset( $data['heartbeat'], $data['lease'] )
            && is_numeric( $data['heartbeat'] )
            && is_numeric( $data['lease'] )
        ) {
            $beat = (int) $data['heartbeat'];

            // A heartbeat further ahead of this reader than the margin the
            // lease already allows for was written by a clock this reader
            // cannot trust, or by hand: nothing can vouch for it, so the row
            // is reclaimable now rather than once the skew has elapsed.
            // Clamping it to $now instead would slide the expiry forward on
            // every read and still hold the slot for heartbeat + lease.
            if ( $beat > $now + self::SLOT_LEASE_MARGIN_SECONDS ) {
                return false;
            }

            // The stored lease follows whatever timeout a filter user set,
            // so cap it at the age rule the other lanes keep: a raised
            // transport timeout must not buy a dead holder more than two
            // hours of refusals. Inside the margin the heartbeat is taken as
            // written, which is at most a minute of extra coverage.
            return $now < $beat + min( (int) $data['lease'], 2 * HOUR_IN_SECONDS );
        }

        // Legacy, pre-lease, or a native row missing either lease field.
        return ( $now - (int) $data['started'] ) < 2 * HOUR_IN_SECONDS;
    }

    /**
     * Count slot rows whose holder is still live (not expired), for the
     * diagnostics trace.
     *
     * @param int $now Current time.
     *
     * @return int Live slot count.
     */
    private static function count_live_slots( int $now ): int {
        global $wpdb;

        $rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the semaphore must bypass the options caches.
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( self::SLOT_OPTION_PREFIX ) . '%'
            )
        );

        $live = 0;
        foreach ( (array) $rows as $value ) {
            if ( self::is_slot_live( json_decode( (string) $value, true ), $now ) ) {
                ++$live;
            }
        }
        return $live;
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
    public static function validate_backup_environment(): void {
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
}
