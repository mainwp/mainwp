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
     * the backup when the child disk space is critically low, and logs
     * advisory warnings for the rest of the report.
     *
     * @param object $website     Website object.
     * @param string $archive_ext Requested archive extension (zip, tar.gz, tar.bz2, tar).
     *
     * @return void
     * @throws MainWP_Exception When the child reports critically low disk space.
     */
    public static function negotiate_backup_preflight( object &$website, string $archive_ext = '' ): void { // phpcs:ignore -- NOSONAR - complex.
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

        // Remember whether the child runs the resumable task machine; an
        // empty value also clears the flag after a child downgrade.
        MainWP_DB::instance()->update_website_option( $website, 'task_spine_support', empty( $preflight['task_spine'] ) ? '' : '1' );
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
    public static function acquire_backup_slot( int $max_concurrent = 2 ): string|false {
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
    public static function release_backup_slot( string $token ): void {
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
