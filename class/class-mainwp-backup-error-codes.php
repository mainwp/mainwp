<?php
/**
 * MainWP Backup Error Codes registry.
 *
 * Standardized backup error codes, user-friendly messages, severity levels,
 * and helpers that keep sensitive server details (absolute paths, SQL, table
 * names) out of user-facing output. Full context belongs in server-side logs
 * only. The code set is a superset of the codes the MainWP Child plugin emits
 * so child responses can be classified without translation.
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Backup_Error_Codes
 *
 * @package MainWP\Dashboard
 */
class MainWP_Backup_Error_Codes { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    // Network.
    const ERR_TIMEOUT           = 'mainwp_backup_timeout';
    const ERR_HTTP_ERROR        = 'mainwp_backup_http_error';
    const ERR_SSL_FAILED        = 'mainwp_backup_ssl_failed';
    const ERR_CONNECT_FAILED    = 'mainwp_backup_connect_failed';
    const ERR_CHILD_UNREACHABLE = 'mainwp_backup_child_unreachable';

    // Child environment.
    const ERR_DISK_FULL         = 'mainwp_backup_disk_full';
    const ERR_MEMORY_LIMIT      = 'mainwp_backup_memory_limit';
    const ERR_PERMISSIONS       = 'mainwp_backup_permissions';
    const ERR_DIR_NOT_WRITABLE  = 'mainwp_backup_dir_not_writable';
    const ERR_MISSING_EXTENSION = 'mainwp_backup_missing_extension';

    // Database.
    const ERR_DB_CONNECTION_LOST = 'mainwp_backup_db_connection_lost';
    const ERR_DB_QUERY_FAILED    = 'mainwp_backup_db_query_failed';
    const ERR_DB_DUMP_EMPTY      = 'mainwp_backup_db_dump_empty';

    // Validation.
    const ERR_FILE_CORRUPT   = 'mainwp_backup_file_corrupt';
    const ERR_FILE_TOO_SMALL = 'mainwp_backup_file_too_small';
    const ERR_HEADER_INVALID = 'mainwp_backup_header_invalid';
    const ERR_HASH_MISMATCH  = 'mainwp_backup_hash_mismatch';

    // Download authorization errors reported by the child site.
    const ERR_DL_FILE_NOT_FOUND = 'mainwp_backup_dl_file_not_found';
    const ERR_DL_FILE_CHANGED   = 'mainwp_backup_dl_file_changed';
    const ERR_DL_TOKEN_INVALID  = 'mainwp_backup_dl_token_invalid';

    // Archive.
    const ERR_ZIP_FAILED       = 'mainwp_backup_zip_failed';
    const ERR_TAR_WRITE_FAILED = 'mainwp_backup_tar_write_failed';
    const ERR_FILE_SKIPPED     = 'mainwp_backup_file_skipped';
    const ERR_TOO_MANY_SKIPPED = 'mainwp_backup_too_many_skipped';

    // Process.
    const ERR_STALLED         = 'mainwp_backup_stalled';
    const ERR_RETRY_EXHAUSTED = 'mainwp_backup_retry_exhausted';
    const ERR_ALREADY_RUNNING = 'mainwp_backup_already_running';
    const ERR_WALL_CLOCK      = 'mainwp_backup_wall_clock_exceeded';
    const ERR_UNKNOWN_PHASE   = 'mainwp_backup_unknown_phase';

    // Incremental backups.
    const ERR_INCREMENTAL_BASE_MISSING = 'mainwp_backup_incremental_base_missing';
    const ERR_MANIFEST_TOO_LARGE       = 'mainwp_backup_manifest_too_large';

    const ERR_MANIFEST_CHUNK_WRONG_PHASE = 'mainwp_backup_manifest_chunk_wrong_phase';
    const ERR_MANIFEST_CHUNK_CORRUPT     = 'mainwp_backup_manifest_chunk_corrupt';
    const ERR_MANIFEST_CHUNK_SEND_FAILED = 'mainwp_backup_manifest_chunk_send_failed';

    // Permission / configuration.
    const ERR_SUSPENDED_SITE  = 'mainwp_backup_suspended_site';
    const ERR_NO_PERMISSION   = 'mainwp_backup_no_permission';
    const ERR_LEGACY_DISABLED = 'mainwp_backup_legacy_disabled';

    // Upload.
    const ERR_FTP_FAILED     = 'mainwp_backup_ftp_failed';
    const ERR_DROPBOX_FAILED = 'mainwp_backup_dropbox_failed';
    const ERR_S3_FAILED      = 'mainwp_backup_s3_failed';
    const ERR_GCS_FAILED     = 'mainwp_backup_gcs_failed';

    /**
     * Get all registered error codes.
     *
     * @return string[] Registered error codes.
     */
    public static function get_registered_codes() {
        return array_keys( static::get_messages() );
    }

    /**
     * Get the user-friendly message for an error code.
     *
     * Messages follow the structure: what failed, why it failed, how to fix it.
     *
     * @param string $code Error code.
     *
     * @return string User-friendly message.
     */
    public static function message( $code ) {
        $messages = static::get_messages();
        if ( isset( $messages[ $code ] ) ) {
            return $messages[ $code ];
        }
        return esc_html__( 'The backup encountered an unexpected problem and did not complete. Check the dashboard and child site error logs for details and try again.', 'mainwp' );
    }

    /**
     * Build a MainWP_Exception for an error code.
     *
     * Uses the registry message for the code and attaches the code so AJAX
     * handlers and the UI can classify the failure.
     *
     * @param string      $code  Error code.
     * @param string|null $extra Optional extra context for the exception.
     *
     * @return MainWP_Exception Coded exception, ready to throw.
     */
    public static function exception( $code, $extra = null ) {
        return new MainWP_Exception( static::message( $code ), $extra, $code ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
    }

    /**
     * Get the severity for an error code.
     *
     * @param string $code Error code.
     *
     * @return string Either 'error' or 'warning'. Unknown codes default to 'error'.
     */
    public static function severity( $code ) {
        $warnings = array(
            self::ERR_MEMORY_LIMIT,
            self::ERR_FILE_SKIPPED,
            self::ERR_ALREADY_RUNNING,
            self::ERR_INCREMENTAL_BASE_MISSING,
            self::ERR_MANIFEST_CHUNK_SEND_FAILED,
        );
        return in_array( $code, $warnings, true ) ? 'warning' : 'error';
    }

    /**
     * Strip sensitive server details from a user-facing message.
     *
     * Removes the absolute site path, SQL statement fragments, and table name
     * references. Full context should be logged server-side before sanitizing.
     *
     * @param mixed $message Raw message.
     *
     * @return string Sanitized message.
     */
    public static function sanitize_message( $message ) {
        if ( ! is_string( $message ) || '' === $message ) {
            return '';
        }

        // Strip the absolute site root - relative paths stay readable.
        $message = str_replace( ABSPATH, '', $message );

        // Strip SQL statement fragments. Compound keyword forms only, so plain
        // English like "check for updates" is never mangled.
        $sql_patterns = array(
            '/SELECT\s+.{0,500}?\s+FROM\s+\S+(\s+WHERE\s+[^.;]*)?/is',
            '/INSERT\s+INTO\s+[^.;]*/is',
            '/UPDATE\s+\S+\s+SET\s+[^.;]*/is',
            '/DELETE\s+FROM\s+[^.;]*/is',
            '/DROP\s+TABLE(\s+IF\s+EXISTS)?\s+[^.;]*/is',
            '/CREATE\s+TABLE(\s+IF\s+NOT\s+EXISTS)?\s+[^.;]*/is',
            '/ALTER\s+TABLE\s+[^.;]*/is',
            '/TRUNCATE\s+TABLE\s+[^.;]*/is',
        );
        $message      = preg_replace( $sql_patterns, '[SQL removed]', $message );

        // Redact backticked identifiers and MySQL "Table '...'" references.
        $message = preg_replace( '/`[^`]+`/', '[redacted]', $message );
        $message = preg_replace( "/(Table\s+')[^']+(')/i", '$1[redacted]$2', $message );

        return $message;
    }

    /**
     * The error code => user-friendly message map.
     *
     * @return array<string,string> Messages keyed by error code.
     */
    private static function get_messages() {
        return array(
            self::ERR_TIMEOUT                    => esc_html__( 'The connection to the child site timed out during the backup. The child site may be overloaded or behind a server that limits long requests. Try again during low-traffic hours.', 'mainwp' ),
            self::ERR_HTTP_ERROR                 => esc_html__( 'The child site returned an unexpected response during the backup transfer. Check that the child site is reachable and the MainWP Child plugin is active, then try again.', 'mainwp' ),
            self::ERR_SSL_FAILED                 => esc_html__( 'The secure connection to the child site could not be established. The SSL certificate may be invalid or expired. Check the child site SSL configuration.', 'mainwp' ),
            self::ERR_CONNECT_FAILED             => esc_html__( 'Could not connect to the child site. The site may be down or a firewall may be blocking the dashboard. Check the child site status and try again.', 'mainwp' ),
            self::ERR_CHILD_UNREACHABLE          => esc_html__( 'The child site did not respond. The site may be down, suspended by the host, or blocking dashboard requests. Verify the site loads in a browser and try again.', 'mainwp' ),
            self::ERR_DISK_FULL                  => esc_html__( 'Could not write backup data. The server may be out of disk space. Free up disk space and try again.', 'mainwp' ),
            self::ERR_MEMORY_LIMIT               => esc_html__( 'The backup is approaching the PHP memory limit. Increase the PHP memory limit or exclude large folders from the backup.', 'mainwp' ),
            self::ERR_PERMISSIONS                => esc_html__( 'Could not create a file or directory for the backup. Check that the web server has write permission on the backup directory.', 'mainwp' ),
            self::ERR_DIR_NOT_WRITABLE           => esc_html__( 'The backup directory is not writable. Set the directory permissions to allow the web server to write, or contact your hosting provider.', 'mainwp' ),
            self::ERR_MISSING_EXTENSION          => esc_html__( 'A required PHP extension is not available. Ask your host to enable the missing extension, such as cURL, OpenSSL, or ZipArchive.', 'mainwp' ),
            self::ERR_DB_CONNECTION_LOST         => esc_html__( 'The database connection was lost during the backup. The database server may be overloaded. Try running the backup again during low-traffic hours.', 'mainwp' ),
            self::ERR_DB_QUERY_FAILED            => esc_html__( 'Database backup did not complete. The child site may have run out of memory or the database connection was interrupted. Check the child site PHP memory limit and error log.', 'mainwp' ),
            self::ERR_DB_DUMP_EMPTY              => esc_html__( 'Backup response from the child site did not include the expected files. The child site may need a PHP or MainWP Child plugin update. Verify the child site is running the latest MainWP Child version.', 'mainwp' ),
            self::ERR_FILE_CORRUPT               => esc_html__( 'Full site backup did not complete. The child site may have run out of disk space or encountered a file permission issue. Check the child site disk space and error log.', 'mainwp' ),
            self::ERR_FILE_TOO_SMALL             => esc_html__( 'The downloaded backup file is smaller than expected and is likely incomplete. The transfer may have been interrupted. Try running the backup again.', 'mainwp' ),
            self::ERR_HEADER_INVALID             => esc_html__( 'The downloaded backup file does not look like a valid archive. The child site may have returned an error page instead of the backup. Check the child site error log and try again.', 'mainwp' ),
            self::ERR_ZIP_FAILED                 => esc_html__( 'Could not create the ZIP archive. The ZipArchive PHP extension may not be available or the backup directory is not writable. Check the PHP configuration and directory permissions.', 'mainwp' ),
            self::ERR_TAR_WRITE_FAILED           => esc_html__( 'Could not write to the backup archive. The server may be out of disk space or the backup directory permissions may have changed. Free up disk space or check directory permissions.', 'mainwp' ),
            self::ERR_FILE_SKIPPED               => esc_html__( 'A file could not be added to the backup archive. Check the file permissions; the file was skipped and the backup continued.', 'mainwp' ),
            self::ERR_TOO_MANY_SKIPPED           => esc_html__( 'Too many files could not be added to the backup. Check disk space and file permissions on the child site, then try again.', 'mainwp' ),
            self::ERR_STALLED                    => esc_html__( 'Backup stopped responding after receiving an invalid status from the child site. Check the child site error log for details.', 'mainwp' ),
            self::ERR_RETRY_EXHAUSTED            => esc_html__( 'Backup did not complete after 5 attempts. The child site may be overloaded, have insufficient resources, or be behind a firewall that blocks long-running requests. Check the child site status and try again during low-traffic hours.', 'mainwp' ),
            self::ERR_ALREADY_RUNNING            => esc_html__( 'Another backup is in progress for this site. Wait for it to finish or for the stale process to be detected, then try again.', 'mainwp' ),
            self::ERR_WALL_CLOCK                 => esc_html__( 'The backup ran past the maximum time allowed on the child site and was stopped. The site may be very large or the host may limit how long a process can run. Try running the backup during off-peak hours, or split large folders into separate schedules.', 'mainwp' ),
            self::ERR_UNKNOWN_PHASE              => esc_html__( 'The backup reached a step the child site does not recognize. This usually means the MainWP Child plugin is older than the dashboard. Update the MainWP Child plugin on the site to the latest version, then run the backup again.', 'mainwp' ),
            self::ERR_SUSPENDED_SITE             => esc_html__( 'This site is suspended in the dashboard, so backups are not run for it. Unsuspend the site to run backups.', 'mainwp' ),
            self::ERR_NO_PERMISSION              => esc_html__( 'You do not have permission to run backups. Ask the dashboard administrator to grant backup access to your user role.', 'mainwp' ),
            self::ERR_LEGACY_DISABLED            => esc_html__( 'Native backups are not enabled for this site. Go to Settings > Backups to enable the legacy backup feature.', 'mainwp' ),
            self::ERR_FTP_FAILED                 => esc_html__( 'Could not upload the backup to the FTP server. Check the FTP credentials and that the server is reachable, then try again.', 'mainwp' ),
            self::ERR_DROPBOX_FAILED             => esc_html__( 'Could not upload the backup to Dropbox. Check the Dropbox connection settings and available storage space, then try again.', 'mainwp' ),
            self::ERR_S3_FAILED                  => esc_html__( 'Could not upload the backup to Amazon S3. Check the S3 credentials, bucket name, and permissions, then try again.', 'mainwp' ),
            self::ERR_GCS_FAILED                 => esc_html__( 'Could not upload the backup to Google Cloud Storage. Check the HMAC key, bucket name, and permissions, then try again.', 'mainwp' ),
            self::ERR_HASH_MISMATCH              => esc_html__( 'The downloaded backup file does not match the integrity hash reported by the child site. The file may have been modified or corrupted during the transfer. The local file was removed; try the download again.', 'mainwp' ),
            self::ERR_DL_FILE_NOT_FOUND          => esc_html__( 'The backup file was not found on the child site. It may have been removed by a cleanup. Run a new backup and try again.', 'mainwp' ),
            self::ERR_DL_FILE_CHANGED            => esc_html__( 'The backup file changed on the child site after the download was authorized. Try the download again to get a fresh authorization for the current file.', 'mainwp' ),
            self::ERR_DL_TOKEN_INVALID           => esc_html__( 'The download authorization was rejected by the child site as invalid, expired, or already used. Try the download again to get a fresh authorization.', 'mainwp' ),

            self::ERR_INCREMENTAL_BASE_MISSING   => esc_html__( 'The previous backup this incremental run depends on is no longer usable on the child site, so a full backup was run instead. The next incremental run will use the new full backup as its base.', 'mainwp' ),
            self::ERR_MANIFEST_TOO_LARGE         => esc_html__( 'The file list from the previous backup is too large to send to the child site, so a full backup was run instead. Sites with very large file counts may need full backups only.', 'mainwp' ),

            self::ERR_MANIFEST_CHUNK_WRONG_PHASE => esc_html__( 'The file-list piece arrived outside of an active staging session on the child site. The transfer restarts from the beginning on the next attempt.', 'mainwp' ),
            self::ERR_MANIFEST_CHUNK_CORRUPT     => esc_html__( 'The transferred file list does not match its expected checksum on the child site and was discarded. The transfer runs again or the run falls back to a full backup.', 'mainwp' ),
            self::ERR_MANIFEST_CHUNK_SEND_FAILED => esc_html__( 'The file list from the previous backup could not be delivered to the child site in pieces, so a full backup was run instead. The site may enforce a small request size limit.', 'mainwp' ),
        );
    }
}
