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
     * Fresh download tokens requested after a token rejection before the
     * transfer failure is propagated to the caller.
     *
     * @var int
     */
    const MAX_TOKEN_RETRIES = 2;

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

        // Files recorded by complete backup snapshots belong to snapshot
        // retention; the sweep neither counts nor deletes them.
        $protected = MainWP_Backup_Snapshots::instance()->get_protected_paths_for_site( $pSiteId );

        if ( file_exists( $dir ) ) {
            $dh = opendir( $dir );
            if ( $dh ) {
                while ( false !== ( $file = readdir( $dh ) ) ) {
                    if ( '.' !== $file && '..' !== $file ) {
                        $theFile = $dir . $file;
                        if ( isset( $protected[ MainWP_Backup_Snapshots::normalise_path( $theFile ) ] ) ) {
                            continue;
                        }
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
            static::download_backup_artifact( $website, $pUrl, $pFile, false, $http_user_plain, $http_pass_plain );
            static::validate_backup_file( $pFile, false );
        }

        return true;
    }

    /**
     * Method download_backup_artifact()
     *
     * Download a backup artifact from a child site. When the child
     * advertises one-time download tokens, a token is requested over the
     * authenticated callable surface, sent with the download request, and
     * the sha256 of the received bytes is verified against the hash the
     * child reported at issuance. A token consumed mid-transfer (the
     * transfer layer can retry the same URL internally) surfaces as an
     * HTTP 401/403 response; a fresh token is requested and the download
     * resumes from the partial file. Children without the capability are
     * served by the token-less path with no hash verification.
     *
     * @param object      $website         Child site object.
     * @param string      $remote_filename Remote backup file name.
     * @param string      $local_file      Absolute local target path.
     * @param int|false   $size            Expected size in bytes, false when unknown.
     * @param string|null $http_user       HTTP Basic Auth username (plaintext).
     * @param string|null $http_pass       HTTP Basic Auth password (plaintext).
     *
     * @throws MainWP_Exception On transfer failure, token failure, or hash mismatch.
     *
     * @uses \MainWP\Dashboard\MainWP_Backup_Monitor::child_supports_download_token()
     * @uses \MainWP\Dashboard\MainWP_Connect::download_to_file()
     * @uses \MainWP\Dashboard\MainWP_Connect::get_get_data_authed()
     */
    // phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber -- all throws are MainWP_Exception.
    public static function download_backup_artifact( object $website, string $remote_filename, string $local_file, $size, $http_user, $http_pass ): void {
        // phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber
        $escalated = false;

        if ( ! MainWP_Backup_Monitor::child_supports_download_token( $website ) ) {
            try {
                MainWP_Connect::download_to_file(
                    MainWP_Connect::get_get_data_authed( $website, $remote_filename, 'fdl' ),
                    $local_file,
                    $size,
                    $http_user,
                    $http_pass
                );
                static::fire_downloaded_action( $website, $local_file, false );
                return;
            } catch ( MainWP_Exception $e ) {
                $extra        = (string) $e->get_message_extra( false );
                $auth_refused = false !== strpos( $extra, 'HTTP 401' ) || false !== strpos( $extra, 'HTTP 403' );
                if ( ! $auth_refused ) {
                    throw $e;
                }
                // The child refused a tokenless download: it has ratcheted
                // onto tokens while this dashboard's capability flag is
                // empty (a fresh install reconnected to a known child).
                // Escalate to the token path; a success below stamps the
                // capability so the next download skips this detour.
                $escalated = true;
            }
        }

        // Fail closed: a token failure on a capability-advertising child
        // propagates - downgrading to a token-less download here would
        // defeat the authorization entirely.
        $token_info = static::fetch_download_token( $website, $remote_filename );

        for ( $attempt = 0; ; $attempt++ ) {
            $url = MainWP_Connect::get_get_data_authed( $website, $remote_filename, 'fdl', false, array( 'dltoken' => $token_info['token'] ) );
            try {
                MainWP_Connect::download_to_file( $url, $local_file, $size, $http_user, $http_pass );
                break;
            } catch ( MainWP_Exception $e ) {
                $extra          = (string) $e->get_message_extra( false );
                $token_rejected = false !== strpos( $extra, 'HTTP 401' ) || false !== strpos( $extra, 'HTTP 403' );
                if ( ! $token_rejected || $attempt >= static::MAX_TOKEN_RETRIES ) {
                    throw $e;
                }
                $token_info = static::fetch_download_token( $website, $remote_filename );
            }
        }

        if ( ! empty( $token_info['hash'] ) && file_exists( $local_file ) ) {
            $actual_hash = (string) hash_file( 'sha256', $local_file );
            if ( ! hash_equals( (string) $token_info['hash'], $actual_hash ) ) {
                wp_delete_file( $local_file );
                // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped; the hashes are hex digests.
                throw MainWP_Backup_Error_Codes::exception(
                    MainWP_Backup_Error_Codes::ERR_HASH_MISMATCH,
                    'expected ' . $token_info['hash'] . ', got ' . $actual_hash
                );
                // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
        }

        if ( $escalated ) {
            MainWP_DB::instance()->update_website_option( $website, 'download_token_support', '1' );
        }

        static::fire_downloaded_action( $website, $local_file, true );
    }

    /**
     * Method fetch_download_token()
     *
     * Request a one-time download token from the child site.
     *
     * @param object $website         Child site object.
     * @param string $remote_filename Remote backup file name.
     *
     * @return array Token info: token, hash, size, expires_in.
     * @throws MainWP_Exception When the child reports an error or returns no usable token.
     *
     * @uses \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
     */
    private static function fetch_download_token( object $website, string $remote_filename ): array {
        $info = MainWP_Connect::fetch_url_authed( $website, 'backup_download_token', array( 'file' => $remote_filename ), false, false, false );

        if ( is_array( $info ) && isset( $info['error'] ) ) {
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- message escaped inline, code is a child registry constant.
            throw new MainWP_Exception(
                esc_html( MainWP_Backup_Error_Codes::sanitize_message( $info['error'] ) ),
                null,
                isset( $info['error_code'] ) ? $info['error_code'] : ''
            );
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        if ( ! is_array( $info ) || empty( $info['token'] ) || empty( $info['hash'] ) ) {
            throw new MainWP_Exception( esc_html__( 'The child site did not return a valid download authorization. Verify the child site is running the latest MainWP Child version and try again.', 'mainwp' ) );
        }

        return $info;
    }

    /**
     * Method fire_downloaded_action()
     *
     * Announce a completed artifact download.
     *
     * @param object $website       Child site object.
     * @param string $local_file    Local file the artifact was saved to.
     * @param bool   $hash_verified Whether the sha256 was verified against the child-reported hash.
     */
    private static function fire_downloaded_action( object $website, string $local_file, bool $hash_verified ): void {
        /**
         * Fires after a backup artifact download completes.
         *
         * @param array $event Download details: site_id, file, size, hash_verified.
         */
        do_action(
            'mainwp_backup_downloaded',
            array(
                'site_id'       => (int) $website->id,
                'file'          => basename( $local_file ),
                'size'          => file_exists( $local_file ) ? (int) filesize( $local_file ) : 0,
                'hash_verified' => $hash_verified,
            )
        );
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
