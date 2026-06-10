<?php
/**
 * MainWP Backup File Manager.
 *
 * File-handling surface for the backup system: archive name helpers,
 * backup downloads, download locks, and downloaded-file validation.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Backup_File_Manager
 *
 * @package MainWP\Dashboard
 */
class MainWP_Backup_File_Manager { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

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
     * Method backup_download_file()
     *
     * Download backup file.
     *
     * @param int    $pSiteId Child Site ID.
     * @param string $pType   full|db Type of backup.
     * @param string $pUrl    Backup location.
     * @param string $pFile   Backup File.
     *
     * @return bool true|false
     * @throws MainWP_Exception Error message.
     *
     * @uses \MainWP\Dashboard\MainWP_Connect::get_get_data_authed()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses \MainWP\Dashboard\MainWP_Connect::download_to_file()
     * @uses \MainWP\Dashboard\MainWP_Utility::end_session()
     */
    public static function backup_download_file( int $pSiteId, string $pType, string $pUrl, string $pFile ): bool {
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
     * Method normalize_filename()
     *
     * Normalize filename.
     *
     * @param string $s Filename to normalize.
     *
     * @return string $s Normalised filename.
     */
    public static function normalize_filename( string $s ): string {
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
     * @param string $pFileName File to check.
     * @param string $pPrefix File prefix.
     * @param string $pSuffix File suffix.
     *
     * @return bool Whether the filename is an archive.
     */
    public static function is_archive( string $pFileName, string $pPrefix = '', string $pSuffix = '' ): bool {
        return (bool) preg_match( '/' . $pPrefix . '(.*).(zip|tar|tar.gz|tar.bz2)' . $pSuffix . '$/', $pFileName );
    }

    /**
     * Method is_sql_file()
     *
     * Check if file is SQL.
     *
     * @param string $pFileName File to check.
     *
     * @return bool Whether the filename is a SQL dump or SQL archive.
     */
    public static function is_sql_file( string $pFileName ): bool {
        return (bool) preg_match( '/(.*).sql$/', $pFileName ) || static::is_sql_archive( $pFileName );
    }

    /**
     * Method is_sql_archive()
     *
     * Check if is SQL Archive.
     *
     * @param string $pFileName File to check.
     *
     * @return bool Whether the filename is a SQL archive.
     */
    public static function is_sql_archive( string $pFileName ): bool {
        return (bool) preg_match( '/(.*).sql.(zip|tar|tar.gz|tar.bz2)$/', $pFileName );
    }

    /**
     * Method get_real_extension()
     *
     * Get full file extension.
     *
     * @param string $path Path to file.
     *
     * @return string Compound extension such as .sql.tar.gz, or the plain extension.
     */
    public static function get_real_extension( string $path ): string {
        $checks = array( '.sql.zip', '.sql.tar', '.sql.tar.gz', '.sql.tar.bz2', '.tar.gz', '.tar.bz2' );
        foreach ( $checks as $check ) {
            if ( MainWP_Utility::ends_with( $path, $check ) ) {
                return $check;
            }
        }

        return '.' . pathinfo( $path, PATHINFO_EXTENSION );
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
    public static function acquire_download_lock( string $local_file, int $site_id, int $task_id ): bool {
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
                'site_id' => $site_id,
                'task_id' => $task_id,
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
    public static function release_download_lock( string $local_file ): void {
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
    public static function cleanup_stale_download_locks( string $dir ): void {
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
     * @param string           $file          Absolute path of the downloaded file.
     * @param int|string|false $expected_size Expected size in bytes (the child reports it over the wire, so numeric strings are accepted), false to skip the size check.
     * @param float            $tolerance     Allowed fractional size deviation (default 1%).
     *
     * @return void
     * @throws MainWP_Exception When validation fails.
     */
    public static function validate_backup_file( string $file, int|string|false $expected_size = false, float $tolerance = 0.01 ): void { // phpcs:ignore -- NOSONAR - complex.
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
     * Method backup_get_file_size()
     *
     * Get file size.
     *
     * @param string $pFile Backup file.
     *
     * @return int|false File size in bytes, or 0 outside the backup directory.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_mainwp_specific_dir()
     */
    public static function backup_get_file_size( string $pFile ): int|false {
        $dir = MainWP_System_Utility::get_mainwp_specific_dir();

        if ( stristr( $pFile, $dir ) && file_exists( $pFile ) ) {
            return filesize( $pFile );
        }

        return 0;
    }
}
