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

    /**
     * Method backup_site()
     *
     * Backup Child Site.
     *
     * @deprecated 6.2 Use MainWP_Backup_Executor::backup_site() instead.
     *
     * @param mixed $siteid Child Site ID.
     * @param mixed $pTask Task to perform.
     * @param mixed $subfolder Sub folder to place backup.
     *
     * @return mixed $backup_result
     */
    public static function backup_site( $siteid, $pTask, $subfolder ) {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_Executor::backup_site' );
        return MainWP_Backup_Executor::backup_site( $siteid, $pTask, $subfolder );
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
     * @deprecated 6.2 Use MainWP_Backup_Executor::backup_delete_file() instead.
     *
     * @param mixed $pSiteId Child Site ID.
     * @param mixed $pFile File to delete.
     *
     * @return bool true
     */
    public static function backup_delete_file( $pSiteId, $pFile ) {
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_Executor::backup_delete_file' );
        return MainWP_Backup_Executor::backup_delete_file( (int) $pSiteId, (string) $pFile );
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

    /**
     * Method backup()
     *
     * Backup Child Site.
     *
     * @deprecated 6.2 Use MainWP_Backup_Executor::backup() instead.
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
     */
    public static function backup(
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
        _deprecated_function( __METHOD__, '6.2', 'MainWP_Backup_Executor::backup' );
        return MainWP_Backup_Executor::backup( $pSiteId, $pType, $pSubfolder, $pExclude, $excludebackup, $excludecache, $excludenonwp, $excludezip, $pFilename, $pFileNameUID, $pArchiveFormat, $pMaximumFileDescriptorsOverride, $pMaximumFileDescriptorsAuto, $pMaximumFileDescriptors, $pLoadFilesBeforeZip, $pid, $append );
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
        // The target parameter is by-reference, so pass a variable, not the
        // inline cast expression. This shim takes the extension by value, so
        // its caller can never receive a negotiated format: negotiate
        // warn-only (allow_switch false) to keep the log truthful.
        $archive_ext = (string) $archive_ext;
        MainWP_Backup_Monitor::negotiate_backup_preflight( $website, $archive_ext, null, false );
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
