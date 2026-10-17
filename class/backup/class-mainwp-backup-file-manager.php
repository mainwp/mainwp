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
     * Incomplete payloads need time to settle before crash recovery can reclaim them.
     *
     * @var int
     */
    const LOCK_PUBLICATION_GRACE_SECONDS = 60;

    /**
     * One create plus one retry per cause (reclaimed stale lock, vanished lock); further contention refuses.
     *
     * @var int
     */
    const LOCK_ACQUIRE_ATTEMPTS = 3;

    /**
     * Bytes read per hash_update() when verifying a downloaded artifact.
     *
     * @var int
     */
    const HASH_CHUNK_BYTES = 8 * MB_IN_BYTES;

    /**
     * Wall-clock seconds between lease refreshes while hashing.
     *
     * @var int
     */
    const HASH_LEASE_TICK_SECONDS = 60;

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
     * Download a legacy backup into the site's backup directory, removing
     * older unprotected backups of the same type to respect the local limit.
     * Full and database backups are validated after download; other types
     * do not trigger a download.
     *
     * @param int    $pSiteId Child site ID.
     * @param string $pType   'full' or 'db' to download a backup.
     * @param string $pUrl    Remote backup identifier passed to the child.
     * @param string $pFile   Local destination inside this site's backup directory.
     * @param mixed  $pSize   Completed artifact size, or false when unknown.
     *
     * @return bool True on success, including when the type is not 'full' or 'db'.
     * @throws MainWP_Exception If the destination is outside the site's backup directory,
     *                           the site or filesystem is unavailable, or transfer or validation fails.
     *
     * @uses \MainWP\Dashboard\MainWP_Connect::get_get_data_authed()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses \MainWP\Dashboard\MainWP_Connect::download_to_file()
     * @uses \MainWP\Dashboard\MainWP_Utility::end_session()
     */
    public static function backup_download_file( int $pSiteId, string $pType, string $pUrl, string $pFile, $pSize = false ): bool {
        static::sweep_backup_download_files( $pSiteId, $pType, $pFile, $pSize );

        $website = MainWP_DB::instance()->get_website_by_id( $pSiteId );
        if ( empty( $website ) ) {
            throw new MainWP_Exception( esc_html__( 'Site not found.', 'mainwp' ) );
        }
        MainWP_Utility::end_session();

        // MWP-1548: decrypt once at function boundary; both branches use the same credentials.
        $http_user_plain = MainWP_Credential_Storage::decrypt_credential( $website->http_user );
        $http_pass_plain = MainWP_Credential_Storage::decrypt_credential( $website->http_pass );

        if ( 'db' === $pType || 'full' === $pType ) {
            static::download_backup_artifact( $website, $pUrl, $pFile, $pSize, $http_user_plain, $http_pass_plain );
            static::validate_backup_file( $pFile, $pSize );
        }

        return true;
    }

    /**
     * Retention must release old archives before download capacity is measured.
     *
     * @param int    $pSiteId Child site ID.
     * @param string $pType   Backup type.
     * @param string $pFile   Incoming local path.
     * @param mixed  $pSize   Completed size, or false for legacy callers.
     * @return void
     * @throws MainWP_Exception When the path or private storage is unavailable.
     */
    public static function sweep_backup_download_files( int $pSiteId, string $pType, string $pFile, $pSize = false ): void {
        if ( ! MainWP_Backup_Private_Root::is_within_private_site_dir( $pFile, $pSiteId ) ) {
            // The local path round-trips through the browser between the
            // create and download requests, so it is attacker-influenced:
            // confine it to the requesting site's own backup directory so
            // a tampered request can neither write bytes into nor sweep a
            // sibling site's archive set. Internal callers build the path
            // with the private root resolver in the same request, so a
            // legitimate target always passes.
            throw new MainWP_Exception( esc_html__( 'Invalid backup file location.', 'mainwp' ) );
        }
        // The root can become read-only, full or over quota between the create
        // request and this second browser request; the creating path names the
        // cause instead of a generic filesystem or transfer error.
        $dir = MainWP_Backup_Private_Root::site_dir( $pSiteId );
        if ( is_wp_error( $dir ) ) {
            throw new MainWP_Exception( esc_html( $dir->get_error_message() ), null, MainWP_Backup_Error_Codes::ERR_PRIVATE_ROOT_UNAVAILABLE ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is escaped; the final argument is an error code.
        }
        if ( ! MainWP_System_Utility::get_wp_file_system() ) {
            throw new MainWP_Exception( esc_html__( 'Could not initialize the WordPress filesystem to store the backup.', 'mainwp' ) );
        }

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
        $maxBackups = get_option( 'mainwp_backupsOnServer' );
        if ( false === $maxBackups ) {
            $maxBackups = 1;
        }

        $dbBackups   = array();
        $fullBackups = array();

        // Files recorded by complete backup snapshots belong to snapshot
        // retention; the sweep neither counts nor deletes them.
        $protected = MainWP_Backup_Snapshots::instance()->get_protected_paths_for_site( $pSiteId );
        if ( is_numeric( $pSize ) && (float) $pSize > 0 ) {
            // A native update-gate download can resume this incoming file.
            $protected[ MainWP_Backup_Snapshots::normalise_path( $pFile ) ] = true;
        }
        foreach ( MainWP_DB_Backup::instance()->get_open_run_download_paths( $pSiteId ) as $path ) {
            $protected[ MainWP_Backup_Snapshots::normalise_path( $path ) ] = true;
        }

        if ( file_exists( $dir ) ) {
            static::cleanup_stale_download_locks( $dir );
            $dh = opendir( $dir );
            if ( $dh ) {
                while ( false !== ( $file = readdir( $dh ) ) ) {
                    if ( '.' !== $file && '..' !== $file ) {
                        $theFile = $dir . $file;
                        if ( isset( $protected[ MainWP_Backup_Snapshots::normalise_path( $theFile ) ] ) ) {
                            continue;
                        }
                        if ( file_exists( $theFile . '.downloading' ) ) {
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

        // A concurrent run can protect a scanned file before deletion.
        $protected += MainWP_Backup_Snapshots::instance()->get_protected_paths_for_site( $pSiteId );
        foreach ( MainWP_DB_Backup::instance()->get_open_run_download_paths( $pSiteId ) as $path ) {
            $protected[ MainWP_Backup_Snapshots::normalise_path( $path ) ] = true;
        }

        // Both lists are newest-first and the sweep runs BEFORE the incoming
        // artifact is written into this same directory, so the cut is
        // >= $maxBackups on purpose: it keeps $maxBackups - 1 existing files
        // and the download about to land restores the count to exactly
        // $maxBackups. A strict > would leave one file too many.
        $cnt = 0;
        foreach ( $dbBackups as $dbBackup ) {
            if ( isset( $protected[ MainWP_Backup_Snapshots::normalise_path( $dbBackup ) ] ) ) {
                continue;
            }
            ++$cnt;
            if ( $cnt >= $maxBackups ) {
                $wp_filesystem->delete( $dbBackup );
            }
        }

        $cnt = 0;
        foreach ( $fullBackups as $fullBackup ) {
            if ( isset( $protected[ MainWP_Backup_Snapshots::normalise_path( $fullBackup ) ] ) ) {
                continue;
            }
            ++$cnt;
            if ( $cnt >= $maxBackups ) {
                $wp_filesystem->delete( $fullBackup );
            }
        }
    }

    // phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber -- all throws are MainWP_Exception.
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
     * served by the token-less path. A recorded Child digest is checked on either path.
     *
     * @param object      $website         Child site object.
     * @param string      $remote_filename Remote backup file name.
     * @param string      $local_file      Absolute local target path.
     * @param int|false   $size            Expected size in bytes, false when unknown.
     * @param string|null $http_user       HTTP Basic Auth username (plaintext).
     * @param string|null $http_pass       HTTP Basic Auth password (plaintext).
     * @param bool        $allow_empty     Allow an explicitly empty tombstones artifact.
     * @param string|null $expected_sha256 Digest recorded when the Child closed the artifact.
     *
     * @throws MainWP_Exception On transfer failure, token failure, or hash mismatch.
     *
     * @uses \MainWP\Dashboard\MainWP_Backup_Monitor::child_supports_download_token()
     * @uses \MainWP\Dashboard\MainWP_Connect::download_to_file()
     * @uses \MainWP\Dashboard\MainWP_Connect::get_get_data_authed()
     */
    public static function download_backup_artifact( object $website, string $remote_filename, string $local_file, $size, $http_user, $http_pass, bool $allow_empty = false, ?string $expected_sha256 = null ): void {
        // phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.WrongNumber
        if ( ! MainWP_Backup_Private_Root::is_within_private_site_dir( $local_file, (int) $website->id ) ) {
            throw new MainWP_Exception( esc_html__( 'Invalid backup file location.', 'mainwp' ) );
        }
        $progress = static function ( $file ) use ( $local_file ) {
            if ( $file === $local_file ) {
                static::refresh_download_lock( $local_file );
            }
        };
        add_action( 'mainwp_download_to_file_progress', $progress );
        try {
            $escalated = false;

            if ( ! MainWP_Backup_Monitor::child_supports_download_token( $website ) ) {
                try {
                    MainWP_Connect::download_to_file(
                        MainWP_Connect::get_get_data_authed( $website, $remote_filename, 'fdl' ),
                        $local_file,
                        $size,
                        $http_user,
                        $http_pass,
                        $website
                    );
                    // Ordering: this check must run BEFORE the downloaded action
                    // fires - a child can serve an empty body while its artifact
                    // is not yet servable, and announcing that as a download
                    // poisons the resume state with a vacuous success.
                    static::assert_download_landed( $local_file, $allow_empty && 0 === $size );
                    if ( static::is_sha256( $expected_sha256 ) ) {
                        $actual_hash = static::hash_artifact( $local_file );
                        if ( ! hash_equals( $expected_sha256, $actual_hash ) ) {
                            wp_delete_file( $local_file );
                            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_HASH_MISMATCH, 'the downloaded file does not match the hash the child site recorded: expected ' . $expected_sha256 . ', got ' . $actual_hash ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Registry messages are pre-escaped; hashes are hex digests.
                        }
                    }
                    if ( ! MainWP_Backup_Monitor::confirm_native_lease() ) {
                        // The token path's hash makes this same call: the slot
                        // may already be re-owned by a process writing this same
                        // path, so bytes that landed outside the slot cannot be
                        // announced as a download.
                        wp_delete_file( $local_file );
                        throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_SLOT_LOST ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
                    }
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
                    MainWP_Connect::download_to_file( $url, $local_file, $size, $http_user, $http_pass, $website );
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

            // The transfer's own lease is spent by the time it returns, and
            // hashing a multi-gigabyte artifact has no transport filter to renew
            // one. Outside a native lease scope this is a no-op.
            MainWP_Backup_Monitor::touch_native_leases( MainWP_Backup_Monitor::DEFAULT_SLOT_LEASE_SECONDS );

            static::assert_download_landed( $local_file, $allow_empty && 0 === $size );

            $actual_hash = null;
            if ( ! empty( $token_info['hash'] ) && file_exists( $local_file ) ) {
                static::refresh_download_lock( $local_file );
                $actual_hash = static::hash_artifact( $local_file );
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

            if ( static::is_sha256( $expected_sha256 ) ) {
                if ( null === $actual_hash ) {
                    $actual_hash = static::hash_artifact( $local_file );
                }
                if ( ! hash_equals( $expected_sha256, $actual_hash ) ) {
                    wp_delete_file( $local_file );
                    throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_HASH_MISMATCH, 'the downloaded file does not match the hash the child site recorded: expected ' . $expected_sha256 . ', got ' . $actual_hash ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Registry messages are pre-escaped; hashes are hex digests.
                }
            }

            if ( $escalated ) {
                MainWP_DB::instance()->update_website_option( $website, 'download_token_support', '1' );
            }

            static::fire_downloaded_action( $website, $local_file, true );
        } finally {
            remove_action( 'mainwp_download_to_file_progress', $progress );
        }
    }

    /**
     * Reject digests outside the Child hash contract.
     *
     * @param mixed $value Untrusted Child digest.
     * @return bool Whether the digest can support a content comparison.
     */
    public static function is_sha256( $value ): bool {
        return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $value );
    }

    /**
     * Keep skipped hashes distinct from compared content.
     *
     * @param array $artifact Child artifact or completion projection.
     * @return array Validated hash evidence, with an explicit skip taking precedence.
     */
    public static function artifact_hash_evidence( array $artifact ): array {
        if ( isset( $artifact['hash_skipped'] ) && is_string( $artifact['hash_skipped'] ) ) {
            $reason = substr( sanitize_key( $artifact['hash_skipped'] ), 0, 32 );
            if ( '' !== $reason ) {
                return array( 'hash_skipped' => $reason );
            }
        }
        return static::is_sha256( $artifact['sha256'] ?? null ) ? array( 'sha256' => $artifact['sha256'] ) : array();
    }

    /**
     * Cached reuse requires the same lease-aware comparison as a download.
     *
     * @param string $path Cached artifact path.
     * @param string $expected Child digest.
     * @return bool Whether the cached bytes match the recorded digest.
     * @throws MainWP_Exception When hashing loses its lease or cannot open the artifact.
     */
    public static function matches_sha256( string $path, string $expected ): bool {
        return static::is_sha256( $expected ) && hash_equals( $expected, static::hash_artifact( $path ) );
    }

    // phpcs:disable Squiz.Commenting.FunctionCommentThrowTag -- the registry factory throws MainWP_Exception.
    /**
     * Method hash_artifact()
     *
     * Hash a downloaded artifact in 8 MiB chunks, refreshing the native
     * lease as it goes.
     *
     * A multi-gigabyte artifact can take longer to hash than a lease
     * lasts, and there is no transport filter inside hash_file() to renew
     * one, so a holder would go stale reading back its own download. If
     * the lease is lost anyway the artifact cannot be trusted - the slot
     * may already be re-owned by a process writing this same path - so
     * the file is removed, the way a bad download's is.
     *
     * @param string $local_file Absolute local artifact path.
     *
     * @return string Lowercase hex sha256 digest.
     *
     * @throws MainWP_Exception When the artifact cannot be opened, or the lease is lost mid-hash.
     */
    private static function hash_artifact( string $local_file ): string {
        // phpcs:enable Squiz.Commenting.FunctionCommentThrowTag
        // phpcs:disable WordPress.WP.AlternativeFunctions -- streaming a multi-gigabyte artifact.
        $handle = fopen( $local_file, 'rb' );
        if ( false === $handle ) {
            wp_delete_file( $local_file );
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_DL_FILE_NOT_FOUND, 'the downloaded artifact could not be opened for verification' ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }

        $context   = hash_init( 'sha256' );
        $next_tick = time() + static::HASH_LEASE_TICK_SECONDS;
        $lost      = false;

        while ( true ) {
            if ( MainWP_Backup_Monitor::native_lease_lost() ) {
                $lost = true;
                break;
            }
            // A short or failed read ends the loop rather than spinning on
            // feof(); the digest it produces then fails the comparison the
            // caller makes, which is the outcome either way.
            $chunk = fread( $handle, static::HASH_CHUNK_BYTES );
            if ( false === $chunk || '' === $chunk ) {
                break;
            }
            hash_update( $context, $chunk );
            /**
             * Renew request evidence while hashing has no transport timeout.
             *
             * @since 6.3
             */
            do_action( 'mainwp_backup_hash_progress' );
            if ( time() >= $next_tick ) {
                static::refresh_download_lock( $local_file );
                MainWP_Backup_Monitor::touch_native_leases( MainWP_Backup_Monitor::DEFAULT_SLOT_LEASE_SECONDS );
                $next_tick = time() + static::HASH_LEASE_TICK_SECONDS;
            }
        }

        fclose( $handle );
        // phpcs:enable WordPress.WP.AlternativeFunctions

        if ( $lost ) {
            wp_delete_file( $local_file );
            throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_SLOT_LOST ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
        }

        return hash_final( $context );
    }

    // phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing -- the registry factory throws MainWP_Exception.
    /**
     * Method assert_download_landed()
     *
     * Refuse a transfer that returned without landing a real local file.
     * A child in its post-completion window can serve an empty 200 body
     * for an artifact that is not yet servable; treating that as a
     * downloaded backup produces a resume state with nothing behind it.
     * A zero-byte leftover is removed so the retry starts clean.
     *
     * @param string $local_file Absolute local target path.
     * @param bool   $allow_empty Allow an explicitly empty tombstones artifact.
     *
     * @return void
     * @throws MainWP_Exception When no local file landed or it is empty.
     */
    private static function assert_download_landed( string $local_file, bool $allow_empty = false ): void {
        // phpcs:enable Squiz.Commenting.FunctionCommentThrowTag.Missing
        clearstatcache( true, $local_file );
        if ( is_file( $local_file ) && ( filesize( $local_file ) > 0 || $allow_empty ) ) {
            return;
        }
        if ( file_exists( $local_file ) ) {
            wp_delete_file( $local_file );
        }
        throw MainWP_Backup_Error_Codes::exception( MainWP_Backup_Error_Codes::ERR_DL_FILE_NOT_FOUND, 'the transfer returned no local file for ' . basename( $local_file ) ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- registry messages are pre-escaped.
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
     * Reserve a fresh archive name through exclusive sidecar creation.
     *
     * @param string $proposed Proposed absolute path.
     * @param int    $site_id  Owning site.
     * @return string Reserved archive path with a .reserved sidecar.
     * @throws MainWP_Exception When no path can be reserved.
     */
    public static function reserve_local_archive_path( string $proposed, int $site_id ): string {
        if ( ! MainWP_Backup_Private_Root::is_within_private_site_dir( $proposed, $site_id ) ) {
            throw new MainWP_Exception( esc_html__( 'Invalid backup file location.', 'mainwp' ) );
        }
        $protected = MainWP_Backup_Snapshots::instance()->get_protected_paths_for_site( $site_id );
        foreach ( MainWP_DB_Backup::instance()->get_open_run_download_paths( $site_id ) as $path ) {
            $protected[ MainWP_Backup_Snapshots::normalise_path( $path ) ] = true;
        }
        $extension = MainWP_Utility::ends_with( $proposed, '.sql.gz' ) ? '.sql.gz' : static::get_real_extension( $proposed );
        $stem      = substr( $proposed, 0, -strlen( $extension ) );
        for ( $attempt = 1; $attempt <= 110; ++$attempt ) {
            $suffix = 1 === $attempt ? '' : '-' . ( $attempt <= 100 ? $attempt : wp_generate_uuid4() );
            $path   = $stem . $suffix . $extension;
            clearstatcache( true, $path );
            if ( file_exists( $path ) || is_link( $path ) || file_exists( $path . '.reserved' ) || file_exists( $path . '.downloading' ) || file_exists( $path . '.downloading.reclaim' )
                || isset( $protected[ MainWP_Backup_Snapshots::normalise_path( $path ) ] ) ) {
                continue;
            }
            $handle = @fopen( $path . '.reserved', 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors -- exclusive sidecar creation arbitrates concurrent reservations.
            if ( false !== $handle ) {
                fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- close the exclusive reservation sidecar.
                return $path;
            }
        }
        throw new MainWP_Exception( esc_html__( 'Could not create a file for this backup on the Dashboard. Check that the backup file location is writable and try again.', 'mainwp' ) );
    }

    /**
     * Compare local bytes with the snapshot's unmodified recorded size.
     *
     * @param string $path          Local archive path.
     * @param mixed  $recorded_size Size recorded in archives[].
     * @param bool   $allow_unknown Preserve restore plans predating recorded sizes.
     * @return bool Whether the archive has exactly its recorded size.
     */
    public static function local_archive_size_matches( string $path, mixed $recorded_size, bool $allow_unknown = false ): bool {
        clearstatcache( true, $path );
        if ( ! is_int( $recorded_size ) ) {
            return $allow_unknown;
        }
        return $recorded_size >= 0 && is_file( $path ) && filesize( $path ) === $recorded_size;
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
     * Lock tokens this process wrote, by lock path. Release checks the
     * on-disk token against this map so a process whose lock was
     * stale-replaced by another cannot delete the replacement.
     *
     * @var array<string,string>
     */
    private static $owned_download_locks = array();

    /**
     * Method acquire_download_lock()
     *
     * Acquire the download lock for a backup file. The lock is a sidecar
     * file with a one hour TTL; a stale or corrupt lock is replaced. TTL
     * expiry alone covers crash recovery on every platform, so no process
     * checks are used.
     *
     * Publication prefers an exclusive hard link to a complete payload, so
     * processes racing the same missing lock cannot both acquire: one wins and
     * the loser re-reads the winner's live lock. Hosts without hard links use
     * exclusive creation under the reclaim mutex to protect incomplete writes.
     * Stale-lock removal goes through reclaim_stale_lock(), which
     * re-validates staleness under a
     * per-path mutex - a contender that observed the OLD stale lock can
     * never delete the FRESH lock a concurrent winner just created.
     *
     * @param string $local_file Absolute path of the download target.
     * @param int    $site_id    Child site ID.
     * @param int    $task_id    Backup task ID.
     *
     * @return bool True when the lock was acquired, false when another process holds a live lock.
     */
    public static function acquire_download_lock( string $local_file, int $site_id, int $task_id ): bool {
        $lock_file = $local_file . '.downloading';

        for ( $attempt = 0; $attempt < static::LOCK_ACQUIRE_ATTEMPTS; $attempt++ ) {
            $token     = strtolower( wp_generate_password( 12, false ) );
            $temp_file = $lock_file . '.' . wp_generate_uuid4() . '.tmp';
            // phpcs:disable WordPress.WP.AlternativeFunctions -- publish complete local payloads atomically on the same filesystem.
            $handle = @fopen( $temp_file, 'x' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- an unwritable sibling must refuse acquisition.
            if ( false === $handle ) {
                return false;
            }
            try {
                $payload = (string) wp_json_encode(
                    array(
                        'started' => time(),
                        'site_id' => $site_id,
                        'task_id' => $task_id,
                        'token'   => $token,
                    )
                );
                try {
                    $written = fwrite( $handle, $payload );
                } finally {
                    fclose( $handle );
                }
                if ( strlen( $payload ) !== $written ) {
                    return false;
                }
                if ( function_exists( 'link' ) && @link( $temp_file, $lock_file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- an existing target is normal contention.
                    static::$owned_download_locks[ $lock_file ] = $token;
                    return true;
                }
                clearstatcache( true, $lock_file );
                if ( ! file_exists( $lock_file ) ) {
                    // The mutex prevents reclamation while the exclusive pathname has an incomplete payload.
                    $mutex = @fopen( $lock_file . '.reclaim', 'c' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- an unserialized fallback cannot safely publish.
                    if ( false !== $mutex ) {
                        try {
                            if ( flock( $mutex, LOCK_EX | LOCK_NB ) ) {
                                try {
                                    $handle = @fopen( $lock_file, 'x' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- another writer may win after the existence check.
                                    if ( false !== $handle ) {
                                        try {
                                            $written = fwrite( $handle, $payload );
                                        } finally {
                                            fclose( $handle );
                                        }
                                        if ( strlen( $payload ) !== $written ) {
                                            wp_delete_file( $lock_file );
                                            return false;
                                        }
                                        static::$owned_download_locks[ $lock_file ] = $token;
                                        return true;
                                    }
                                } finally {
                                    flock( $mutex, LOCK_UN );
                                }
                            }
                        } finally {
                            fclose( $mutex );
                        }
                    }
                }
            } finally {
                wp_delete_file( $temp_file );
            }
            // phpcs:enable WordPress.WP.AlternativeFunctions

            // The lock exists: a live one refuses this caller; a stale or
            // corrupt one is reclaimed (re-validated and removed under the
            // per-path mutex) and the exclusive create retried, so only
            // one of several racers wins the recreate.
            $data  = json_decode( (string) @file_get_contents( $lock_file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors -- local lock file; a racer may delete it between publication and this read.
            $stale = is_array( $data ) && ( time() - (int) ( isset( $data['started'] ) ? $data['started'] : 0 ) ) > HOUR_IN_SECONDS;
            if ( ! is_array( $data ) ) {
                // An empty lock may be a writer mid-publication until its age no longer explains a live writer.
                clearstatcache( true, $lock_file );
                $mtime = @filemtime( $lock_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- the previous owner may have released the lock.
                if ( false === $mtime ) {
                    // Acquisition can retry a vanished lock; reclamation must refuse an unstatable file to avoid deleting a replacement.
                    continue;
                }
                $stale = time() - $mtime > static::LOCK_PUBLICATION_GRACE_SECONDS;
            }
            if ( ! $stale ) {
                return false;
            }
            if ( ! static::reclaim_stale_lock( $lock_file ) ) {
                return false;
            }
        }

        return false;
    }

    /**
     * Renew only our token, under the reclaim mutex so a stale observation
     * cannot replace the lock between the ownership check and the write.
     *
     * @param string $local_file Download target path.
     * @return bool False when ownership is lost or the refresh cannot be written.
     */
    private static function refresh_download_lock( string $local_file ): bool {
        $lock_file = $local_file . '.downloading';
        $owned     = static::$owned_download_locks[ $lock_file ] ?? '';
        if ( '' === $owned ) {
            return false;
        }
        // phpcs:disable WordPress.WP.AlternativeFunctions -- local sidecar IO shares the reclaim mutex.
        $mutex = fopen( $lock_file . '.reclaim', 'c' );
        if ( false === $mutex ) {
            return false;
        }
        try {
            // Contention means active management; an unopened mutex verifies nothing.
            if ( ! flock( $mutex, LOCK_EX | LOCK_NB ) ) {
                return true;
            }
            try {
                if ( ! file_exists( $lock_file ) ) {
                    return false;
                }
                $data = json_decode( (string) file_get_contents( $lock_file ), true );
                if ( ! is_array( $data ) || ! isset( $data['token'] ) || ! hash_equals( $owned, (string) $data['token'] ) ) {
                    return false;
                }
                $data['started'] = time();
                $payload         = wp_json_encode( $data );
                $temp_file       = $lock_file . '.tmp';
                if ( false === $payload || strlen( $payload ) !== @file_put_contents( $temp_file, $payload ) || ! @rename( $temp_file, $lock_file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a failed refresh must preserve the live sidecar.
                    wp_delete_file( $temp_file );
                    return false;
                }
                return true;
            } finally {
                flock( $mutex, LOCK_UN );
            }
        } finally {
            fclose( $mutex );
        }
        // phpcs:enable WordPress.WP.AlternativeFunctions
    }

    /**
     * Method reclaim_stale_lock()
     *
     * Remove a lock file previously observed stale, safely: the whole
     * re-validate-and-delete runs under an advisory flock on a per-path
     * sidecar mutex, and the lock is re-read INSIDE the mutex. Without
     * this, two contenders that both read the same stale lock race - the
     * loser's pathname delete lands after the winner's fresh exclusive
     * create and silently invalidates it, and both end up believing they
     * own the artifact.
     *
     * This method never deletes the mutex: removing it while another process
     * holds its descriptor would let a third contender open a NEW inode
     * at the same path and bypass the serialization. Release also leaves
     * the mutex in place. Only cleanup_stale_download_locks() sweeps orphan
     * mutexes after the lock TTL, under an exclusive nonblocking flock.
     *
     * @param string      $lock_file Lock file path.
     * @param string|null $mutex_file Shared mutex path for a publication sidecar.
     *
     * @return bool True when the stale lock is gone and the caller may
     *              retry the exclusive create; false when the lock is
     *              live again or another process is reclaiming it.
     */
    private static function reclaim_stale_lock( string $lock_file, ?string $mutex_file = null ): bool {
        $mutex = @fopen( $mutex_file ?? $lock_file . '.reclaim', 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors -- flock target; creation failure degrades to refusing the reclaim.
        if ( false === $mutex ) {
            return false; // Cannot serialize the takeover; treat as contended.
        }

        try {
            if ( ! flock( $mutex, LOCK_EX | LOCK_NB ) ) {
                return false; // Another process is mid-reclaim; let it win.
            }
            try {
                if ( ! file_exists( $lock_file ) ) {
                    return true; // Already reclaimed; the exclusive create decides ownership.
                }
                // Re-read under the mutex: only the exact still-stale state
                // may be deleted, never a fresh lock a winner just wrote.
                $data  = json_decode( (string) @file_get_contents( $lock_file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors -- local lock file.
                $stale = is_array( $data ) && ( time() - (int) ( isset( $data['started'] ) ? $data['started'] : 0 ) ) > HOUR_IN_SECONDS;
                if ( ! is_array( $data ) ) {
                    // An empty lock may be a writer mid-publication until its age no longer explains a live writer.
                    clearstatcache( true, $lock_file );
                    $mtime = @filemtime( $lock_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a vanished file must refuse reclamation.
                    $stale = false !== $mtime && time() - $mtime > static::LOCK_PUBLICATION_GRACE_SECONDS;
                }
                if ( ! $stale ) {
                    return false;
                }
                wp_delete_file( $lock_file );
                return true;
            } finally {
                flock( $mutex, LOCK_UN );
            }
        } finally {
            fclose( $mutex ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- paired with the fopen above.
        }
    }

    /**
     * Method release_download_lock()
     *
     * Release the download lock for a backup file. A lock this process
     * acquired is only deleted while it still carries this process's
     * token: when another process stale-replaced it, deleting the file
     * would release a lock someone else now holds. A caller that never
     * acquired (an administrative clear) keeps the old unconditional
     * delete.
     *
     * @param string $local_file Absolute path of the download target.
     *
     * @return void
     */
    public static function release_download_lock( string $local_file ): void {
        $lock_file = $local_file . '.downloading';

        if ( ! file_exists( $lock_file ) ) {
            unset( static::$owned_download_locks[ $lock_file ] );
            return;
        }

        $owned = isset( static::$owned_download_locks[ $lock_file ] ) ? static::$owned_download_locks[ $lock_file ] : '';
        if ( '' !== $owned ) {
            unset( static::$owned_download_locks[ $lock_file ] );
            // phpcs:disable WordPress.WP.AlternativeFunctions -- ownership checks must serialize with stale replacement.
            $mutex = @fopen( $lock_file . '.reclaim', 'c' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- an unserialized delete could remove a new owner's lock.
            if ( false === $mutex ) {
                return;
            }
            try {
                if ( ! flock( $mutex, LOCK_EX ) ) {
                    return;
                }
                try {
                    if ( ! file_exists( $lock_file ) ) {
                        return;
                    }
                    $data       = json_decode( (string) file_get_contents( $lock_file ), true );
                    $lock_token = is_array( $data ) && isset( $data['token'] ) ? (string) $data['token'] : '';
                    if ( hash_equals( $lock_token, $owned ) ) {
                        wp_delete_file( $lock_file );
                    }
                } finally {
                    flock( $mutex, LOCK_UN );
                }
            } finally {
                fclose( $mutex );
            }
            return;
            // phpcs:enable WordPress.WP.AlternativeFunctions
        }

        wp_delete_file( $lock_file );
    }

    /**
     * Method cleanup_stale_download_locks()
     *
     * Remove stale download lock files left behind by crashed processes in
     * the site directory and its immediate snapshot-* run subdirectories.
     * Runs at backup start so an interrupted run never blocks future runs
     * for longer than the lock TTL.
     * Also reclaims reservation sidecars whose own mtime is older than DAY_IN_SECONDS.
     *
     * Orphan reclaim mutexes are removed only when their own mtime is older
     * than HOUR_IN_SECONDS and the lock is absent before and after clearing
     * the stat cache under an exclusive nonblocking flock. The age excludes
     * live transfers older than the lock TTL; the flock excludes current
     * mutex holders, and the second existence check preserves a new lock.
     * A fallback-host acquirer that opened the mutex microseconds before
     * unlink may retain the old inode. Its exclusive create and
     * LOCK_PUBLICATION_GRACE_SECONDS protect publication, not that mutex.
     *
     * @param string $dir Directory holding backup files.
     *
     * @return void
     */
    public static function cleanup_stale_download_locks( string $dir ): void {
        foreach ( array( '*.downloading', '*.downloading.tmp', '*.downloading.*.tmp' ) as $pattern ) {
            $site_locks = glob( rtrim( $dir, '/' ) . '/' . $pattern );
            $run_locks  = glob( rtrim( $dir, '/' ) . '/snapshot-*/' . $pattern );
            $locks      = array_merge(
                is_array( $site_locks ) ? $site_locks : array(),
                is_array( $run_locks ) ? $run_locks : array()
            );
            foreach ( $locks as $lock_file ) {
                // Refresh sidecars share the owner's mutex so cleanup cannot race a live refresh.
                $owner_file = preg_replace( '/(\.downloading)(?:\.tmp|\.[^.]+\.tmp)$/', '$1', $lock_file );
                static::reclaim_stale_lock( $lock_file, $owner_file . '.reclaim' );
            }
        }

        // Abandoned reservations must not hold archive names indefinitely.
        $site_reservations = glob( rtrim( $dir, '/' ) . '/*.reserved' );
        $run_reservations  = glob( rtrim( $dir, '/' ) . '/snapshot-*/*.reserved' );
        $reservations      = array_merge(
            is_array( $site_reservations ) ? $site_reservations : array(),
            is_array( $run_reservations ) ? $run_reservations : array()
        );
        foreach ( $reservations as $reservation_file ) {
            clearstatcache( true, $reservation_file );
            $mtime = @filemtime( $reservation_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a concurrent sweep may have removed the sidecar.
            if ( false !== $mtime && time() - $mtime > DAY_IN_SECONDS ) {
                wp_delete_file( $reservation_file );
            }
        }

        $site_mutexes = glob( rtrim( $dir, '/' ) . '/*.downloading.reclaim' );
        $run_mutexes  = glob( rtrim( $dir, '/' ) . '/snapshot-*/*.downloading.reclaim' );
        $mutexes      = array_merge(
            is_array( $site_mutexes ) ? $site_mutexes : array(),
            is_array( $run_mutexes ) ? $run_mutexes : array()
        );
        foreach ( $mutexes as $mutex_file ) {
            $lock_file = substr( $mutex_file, 0, -strlen( '.reclaim' ) );
            clearstatcache( true, $lock_file );
            if ( file_exists( $lock_file ) ) {
                continue;
            }
            $mutex = @fopen( $mutex_file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors -- a concurrent sweep may have removed the mutex.
            if ( false === $mutex ) {
                continue;
            }
            try {
                if ( ! flock( $mutex, LOCK_EX | LOCK_NB ) ) {
                    continue;
                }
                try {
                    clearstatcache( true, $mutex_file );
                    $mtime = @filemtime( $mutex_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- an unstatable mutex must be preserved.
                    clearstatcache( true, $lock_file );
                    if ( false !== $mtime && time() - $mtime > HOUR_IN_SECONDS && ! file_exists( $lock_file ) ) {
                        wp_delete_file( $mutex_file );
                    }
                } finally {
                    flock( $mutex, LOCK_UN );
                }
            } finally {
                fclose( $mutex ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- paired with the fopen above.
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
            if ( $actual < $expected || $actual - $expected > $allowance ) {
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
     */
    public static function backup_get_file_size( string $pFile ): int|false {
        $root = MainWP_Backup_Private_Root::resolve();
        if ( ( static::is_within_backup_root( $pFile ) || ( ! is_wp_error( $root ) && static::path_is_confined_to( $pFile, $root ) ) ) && file_exists( $pFile ) ) {
            return filesize( $pFile );
        }

        return 0;
    }

    /**
     * Whether a caller-supplied local path stays inside the dashboard
     * backup tree (uploads/mainwp/).
     *
     * @param string $path Candidate absolute path.
     *
     * @return bool True when the path is confined to the backup root.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_mainwp_dir()
     */
    public static function is_within_backup_root( string $path ): bool {
        $dirs = MainWP_System_Utility::get_mainwp_dir();
        return static::path_is_confined_to( $path, (string) $dirs[0] );
    }

    /**
     * Accept recorded legacy paths while confining downloads to their site.
     *
     * @param string $path    Candidate absolute path.
     * @param int    $site_id Site the path must belong to.
     *
     * @return bool True when the path is confined to that site's backup dir.
     */
    public static function is_within_site_backup_dir( string $path, int $site_id ): bool {
        return MainWP_Backup_Private_Root::is_within_site_roots( $path, $site_id );
    }

    /**
     * Whether a path is confined to a base directory.
     *
     * Resolve existing files and ancestors so symlinks cannot escape the base.
     * Missing download destinations may have uncreated child directories.
     *
     * @param string $path Candidate absolute path.
     * @param string $base Directory the path must sit under.
     *
     * @return bool True when the path is confined to the base.
     */
    public static function path_is_confined_to( string $path, string $base ): bool {
        if ( '' === $path || '' === $base || false !== strpos( $path, "\0" ) ) {
            return false;
        }
        foreach ( explode( '/', wp_normalize_path( $path ) ) as $segment ) {
            if ( '..' === $segment ) {
                return false;
            }
        }
        $root = realpath( $base );
        $real = realpath( $path );
        if ( false === $real && ! is_link( $path ) ) {
            $pending = basename( $path );
            $parent  = dirname( $path );
            while ( false === realpath( $parent ) && dirname( $parent ) !== $parent && ! is_link( $parent ) ) {
                $pending = basename( $parent ) . '/' . $pending;
                $parent  = dirname( $parent );
            }
            $parent = realpath( $parent );
            $real   = false === $parent ? false : trailingslashit( $parent ) . $pending;
        }
        return false !== $root && false !== $real && 0 === strpos( wp_normalize_path( $real ), trailingslashit( wp_normalize_path( $root ) ) );
    }
}
