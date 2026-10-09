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
     * @deprecated 6.3 Use MainWP_Backup_Executor::backup_site() instead.
     *
     * @param mixed $siteid Child Site ID.
     * @param mixed $pTask Task to perform.
     * @param mixed $subfolder Sub folder to place backup.
     *
     * @return mixed $backup_result
     */
    public static function backup_site( $siteid, $pTask, $subfolder ) {
        _deprecated_function( __METHOD__, '6.3', 'MainWP_Backup_Executor::backup_site' );
        return MainWP_Backup_Executor::backup_site( $siteid, $pTask, $subfolder );
    }

    /**
     * Method backup_download_file()
     *
     * Download backup file.
     *
     * @deprecated 6.3 Use MainWP_Backup_File_Manager::backup_download_file() instead.
     *
     * @param mixed $pSiteId Child Site ID.
     * @param mixed $pType   full|db Type of backup.
     * @param mixed $pUrl    Backup location.
     * @param mixed $pFile   Backup File.
     *
     * @return bool true|false
     */
    public static function backup_download_file( $pSiteId, $pType, $pUrl, $pFile ) {
        _deprecated_function( __METHOD__, '6.3', 'MainWP_Backup_File_Manager::backup_download_file' );
        return MainWP_Backup_File_Manager::backup_download_file( (int) $pSiteId, (string) $pType, (string) $pUrl, (string) $pFile );
    }

    /**
     * Method backup_delete_file().
     *
     * Delete backup file.
     *
     * @deprecated 6.3 Use MainWP_Backup_Executor::backup_delete_file() instead.
     *
     * @param mixed $pSiteId Child Site ID.
     * @param mixed $pFile File to delete.
     *
     * @return bool true
     */
    public static function backup_delete_file( $pSiteId, $pFile ) {
        _deprecated_function( __METHOD__, '6.3', 'MainWP_Backup_Executor::backup_delete_file' );
        return MainWP_Backup_Executor::backup_delete_file( (int) $pSiteId, (string) $pFile );
    }

    /**
     * Method backup_check_pid()
     *
     * Check backup pid.
     *
     * @deprecated 6.3 Use MainWP_Backup_Monitor::backup_check_pid() instead.
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
        _deprecated_function( __METHOD__, '6.3', 'MainWP_Backup_Monitor::backup_check_pid' );
        return MainWP_Backup_Monitor::backup_check_pid( (int) $pSiteId, $pid, (string) $type, (string) $subfolder, null === $pFilename ? null : (string) $pFilename );
    }

    /**
     * Method backup()
     *
     * Backup Child Site.
     *
     * @deprecated 6.3 Use MainWP_Backup_Executor::backup() instead.
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
        _deprecated_function( __METHOD__, '6.3', 'MainWP_Backup_Executor::backup' );
        return MainWP_Backup_Executor::backup( $pSiteId, $pType, $pSubfolder, $pExclude, $excludebackup, $excludecache, $excludenonwp, $excludezip, $pFilename, $pFileNameUID, $pArchiveFormat, $pMaximumFileDescriptorsOverride, $pMaximumFileDescriptorsAuto, $pMaximumFileDescriptors, $pLoadFilesBeforeZip, $pid, $append );
    }

    /**
     * Method normalize_filename()
     *
     * Normalize filename.
     *
     * @deprecated 6.3 Use MainWP_Backup_File_Manager::normalize_filename() instead.
     *
     * @param mixed $s Filename to normalize.
     *
     * @return string $s Normalised filename.
     */
    public static function normalize_filename( $s ) {
        _deprecated_function( __METHOD__, '6.3', 'MainWP_Backup_File_Manager::normalize_filename' );
        return MainWP_Backup_File_Manager::normalize_filename( (string) $s );
    }

    /**
     * Method is_archive()
     *
     * Check if Archive.
     *
     * @deprecated 6.3 Use MainWP_Backup_File_Manager::is_archive() instead.
     *
     * @param mixed  $pFileName File to check.
     * @param string $pPrefix File prefix.
     * @param string $pSuffix File suffix.
     *
     * @return bool Whether the filename is an archive.
     */
    public static function is_archive( $pFileName, $pPrefix = '', $pSuffix = '' ) {
        _deprecated_function( __METHOD__, '6.3', 'MainWP_Backup_File_Manager::is_archive' );
        return MainWP_Backup_File_Manager::is_archive( (string) $pFileName, (string) $pPrefix, (string) $pSuffix );
    }

    /**
     * Method is_sql_file()
     *
     * Check if file is SQL.
     *
     * @deprecated 6.3 Use MainWP_Backup_File_Manager::is_sql_file() instead.
     *
     * @param mixed $pFileName File to check.
     *
     * @return bool Whether the filename is a SQL dump or SQL archive.
     */
    public static function is_sql_file( $pFileName ) {
        _deprecated_function( __METHOD__, '6.3', 'MainWP_Backup_File_Manager::is_sql_file' );
        return MainWP_Backup_File_Manager::is_sql_file( (string) $pFileName );
    }

    /**
     * Method is_sql_archive()
     *
     * Check if is SQL Archive.
     *
     * @deprecated 6.3 Use MainWP_Backup_File_Manager::is_sql_archive() instead.
     *
     * @param mixed $pFileName File to check.
     *
     * @return bool Whether the filename is a SQL archive.
     */
    public static function is_sql_archive( $pFileName ) {
        _deprecated_function( __METHOD__, '6.3', 'MainWP_Backup_File_Manager::is_sql_archive' );
        return MainWP_Backup_File_Manager::is_sql_archive( (string) $pFileName );
    }

    /**
     * Method get_current_archive_extension()
     *
     * Get extension of current Archive.
     *
     * @deprecated 6.3 Use MainWP_Backup_Settings::get_current_archive_extension() instead.
     *
     * @param object|false $website Website object, or false to use the global format.
     * @param object|false $task    Backup task object, or false when there is no task context.
     *
     * @return string Format of Archive.
     */
    public static function get_current_archive_extension( $website = false, $task = false ) {
        _deprecated_function( __METHOD__, '6.3', 'MainWP_Backup_Settings::get_current_archive_extension' );
        return MainWP_Backup_Settings::get_current_archive_extension( $website, $task );
    }

    /**
     * Method get_real_extension()
     *
     * Get full file extension.
     *
     * @deprecated 6.3 Use MainWP_Backup_File_Manager::get_real_extension() instead.
     *
     * @param mixed $path Path to file.
     *
     * @return string Compound extension such as .sql.tar.gz, or the plain extension.
     */
    public static function get_real_extension( $path ) {
        _deprecated_function( __METHOD__, '6.3', 'MainWP_Backup_File_Manager::get_real_extension' );
        return MainWP_Backup_File_Manager::get_real_extension( (string) $path );
    }

    /**
     * Method handle_settings_post()
     *
     * Update Child Site Settings.
     *
     * @deprecated 6.3 Use MainWP_Backup_Settings::handle_settings_post() instead.
     *
     * @return bool true|false
     */
    public static function handle_settings_post() {
        _deprecated_function( __METHOD__, '6.3', 'MainWP_Backup_Settings::handle_settings_post' );
        return MainWP_Backup_Settings::handle_settings_post();
    }

    /**
     * Method backup_get_file_size()
     *
     * Get file size.
     *
     * @deprecated 6.3 Use MainWP_Backup_File_Manager::backup_get_file_size() instead.
     *
     * @param mixed $pFile Backup file.
     *
     * @return false|int
     */
    public static function backup_get_file_size( $pFile ) {
        _deprecated_function( __METHOD__, '6.3', 'MainWP_Backup_File_Manager::backup_get_file_size' );
        return MainWP_Backup_File_Manager::backup_get_file_size( (string) $pFile );
    }
}
