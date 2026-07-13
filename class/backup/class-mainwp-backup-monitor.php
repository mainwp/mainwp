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

        // ZIP archiving restarts from the beginning on each slice timeout, so a
        // large site can burn the resume budget and stall; tar.gz appends and
        // resumes. Over the guardrail threshold, switch ZIP to tar.gz so the
        // backup can actually finish, unless the task explicitly forces ZIP.
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
                        esc_html__( 'ZIP is not recommended for large sites (estimated %s): it restarts from the beginning on every timeout, while tar.gz resumes across timeouts.', 'mainwp' ),
                        $estimate
                    );
                } else {
                    $archive_ext = 'tar.gz';
                    $zip_warning = sprintf(
                        /* translators: %s: estimated backup size */
                        esc_html__( 'ZIP was switched to tar.gz for this large site (estimated %s): ZIP restarts from the beginning on every timeout, while tar.gz resumes across timeouts.', 'mainwp' ),
                        $estimate
                    );
                    // Only a visible scheduled task carries the settings form
                    // with the override checkbox; the hidden on-demand task
                    // must not advertise a control the user cannot reach.
                    if ( is_object( $task ) && empty( $task->template ) ) {
                        $zip_warning .= ' ' . esc_html__( 'Enable "keep ZIP" on the backup task to override.', 'mainwp' );
                    }
                }
                do_action( 'mainwp_log_warning', $zip_warning );
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
                do_action( 'mainwp_log_warning', sprintf( 'Child site reports a large file count (%d); backups may need extra memory.', (int) $preflight['file_count'] ) );
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
            'task_spine_support'         => 'task_spine',
            'download_token_support'     => 'download_token',
            'incremental_support'        => 'incremental',
            'manifest_chunk_support'     => 'manifest_chunk',
            'restore_files_support'      => 'restore_files',
            'cloud_restore_pull_support' => 'cloud_restore_pull',
            'child_is_multisite'         => 'is_multisite',
            'restore_multisite_support'  => 'restore_multisite',
        );

        foreach ( $flags as $option => $reported ) {
            MainWP_DB::instance()->update_website_option( $website, $option, empty( $preflight[ $reported ] ) ? '' : '1' );
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
     * Option-name prefix for the fixed slot rows of the DB semaphore.
     * Slot N lives at "mainwp_backup_slot_N"; the fixed names 1..max are
     * what caps concurrency - a slot is held by owning its row.
     *
     * @var string
     */
    const SLOT_OPTION_PREFIX = 'mainwp_backup_slot_';

    /**
     * Method acquire_backup_slot()
     *
     * Acquire a backup execution slot from the DB semaphore. Each slot is
     * one fixed-name wp_options row; the table's UNIQUE(option_name) key
     * makes the INSERT the atomic acquisition - exactly one contender
     * wins each slot, enforced by the database rather than a best-effort
     * transient read-modify-write. Slots from crashed processes expire
     * after two hours (reclaimed by compare-and-delete on the exact
     * stored value, so racing reclaimers cannot double-free), and a
     * crash never blocks backups permanently.
     *
     * All reads and writes go straight to the table: the options/
     * notoptions caches never see these names, so an external object
     * cache cannot serve a stale slot state.
     *
     * @param int $max_concurrent Maximum concurrent backup tasks (default 2).
     *
     * @return string|false Slot token on success, false when all slots are in use.
     */
    public static function acquire_backup_slot( int $max_concurrent = 2 ): string|false {
        global $wpdb;

        $now   = time();
        $token = wp_generate_password( 12, false );
        $value = (string) wp_json_encode(
            array(
                'token'   => $token,
                'started' => $now,
                'pid'     => getmypid(),
            )
        );

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
                $data  = json_decode( (string) $held, true );
                $stale = ! is_array( $data ) || ! isset( $data['started'] ) || ( $now - (int) $data['started'] ) >= 2 * HOUR_IN_SECONDS;
                if ( ! $stale ) {
                    continue; // Live holder; try the next slot.
                }
                // Reclaim by exact value: a contender that lost this DELETE
                // race deletes nothing and then loses the INSERT race too.
                $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- compare-and-delete reclaim.
                    $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, (string) $held )
                );
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
                 * @param string $event          acquired|refused|released.
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
            $data = json_decode( (string) $value, true );
            if ( is_array( $data ) && isset( $data['started'] ) && ( $now - (int) $data['started'] ) < 2 * HOUR_IN_SECONDS ) {
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
