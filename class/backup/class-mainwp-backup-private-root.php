<?php
/**
 * Private storage for native backup artifacts.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Keep native backup bytes outside the served WordPress directories.
 */
class MainWP_Backup_Private_Root {

    /**
     * Request-local resolution result.
     *
     * @var string|\WP_Error|null
     */
    private static $root = null;

    /**
     * Request-local stat failure, without a write probe.
     *
     * @var string
     */
    private static $availability_reason = '';

    /**
     * Whether resolution considered the host's sibling directories.
     *
     * @var bool
     */
    private static $used_ladder = false;

    /**
     * Reset request-local state for tests.
     *
     * @return void
     */
    public static function reset(): void {
        self::$root                = null;
        self::$availability_reason = '';
        self::$used_ladder         = false;
    }

    /**
     * Resolve the configured private root without creating it.
     *
     * The default is mainwp-private/<WordPress directory name>/backups beside WordPress, so Dashboards installed side by side never share a root (retention would delete each other's snapshots).
     * For a WordPress directory named mainwp-private, the sibling container is mainwp-private-dashboard to keep the root outside WordPress.
     * Changing MAINWP_BACKUP_PRIVATE_DIR, or renaming the WordPress directory, after backups exist means moving the sites/ folder to the new root, or the older snapshots leave retention and the Backups tab; restore still works from the recorded paths.
     * Hosts that deploy through an atomic symlink (current -> releases/<id>: Capistrano, Deployer, Envoyer) must set MAINWP_BACKUP_PRIVATE_DIR: WordPress resolves ABSPATH through the release directory, so the default folder name changes with every release and the earlier snapshots drop out of retention and the Backups tab.
     * Without a constant or filter override, sibling private_html and private directories provide fallbacks for hosts with an unwritable WordPress parent; existing backup roots take precedence.
     *
     * @return string|\WP_Error
     */
    public static function resolve(): string|\WP_Error {
        if ( null !== self::$root ) {
            return self::$root;
        }
        $folder    = basename( rtrim( ABSPATH, '/\\' ) );
        $parent    = dirname( rtrim( ABSPATH, '/\\' ) );
        $container = 0 === strcasecmp( $folder, 'mainwp-private' ) ? 'mainwp-private-dashboard' : 'mainwp-private';
        $suffix    = '/' . $container . '/' . $folder . '/backups';
        $default   = defined( 'MAINWP_BACKUP_PRIVATE_DIR' ) ? MAINWP_BACKUP_PRIVATE_DIR : $parent . $suffix;
        $candidate = apply_filters( 'mainwp_backup_private_root', $default );
        if ( ! defined( 'MAINWP_BACKUP_PRIVATE_DIR' ) && $candidate === $default ) {
            self::$used_ladder = true;
            $writable          = null;
            $existing          = null;
            foreach ( array( $default, $parent . '/private_html' . $suffix, $parent . '/private' . $suffix ) as $path ) {
                if ( '' !== self::refusal_reason( $path ) ) {
                    continue;
                }
                // Preserve snapshots even when an earlier candidate becomes writable.
                if ( is_dir( $path ) ) {
                    $existing = $path;
                    break;
                }
                if ( null === $writable && '' === self::path_availability_reason( $path ) ) {
                    $writable = $path;
                }
            }
            $candidate = $existing ?? $writable ?? $default;
        }
        $reason                    = self::refusal_reason( $candidate );
        self::$availability_reason = '' === $reason ? self::path_availability_reason( $candidate ) : $reason;
        self::$root                = '' === $reason ? trailingslashit( self::canonical_path( wp_normalize_path( $candidate ) ) ) : self::unavailable( is_string( $candidate ) ? str_replace( "\0", '[NUL]', $candidate ) : '(' . gettype( $candidate ) . ')', $reason );
        return self::$root;
    }

    /**
     * Report whether the resolved root can be reached or created, without writing.
     *
     * @return string Empty when the root exists or its nearest existing ancestor is writable.
     */
    public static function availability_reason(): string {
        self::resolve();
        return self::$availability_reason;
    }

    /**
     * Predict mkdir failure for a path that passed the location checks.
     *
     * @param string $path Accepted path.
     * @return string
     */
    private static function path_availability_reason( string $path ): string {
        if ( is_dir( $path ) ) {
            // An existing root still wins the ladder so snapshots are never abandoned, but the write probe would fail here.
            return is_writable( $path ) ? '' : 'directory is not writable'; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Resolution must use local metadata without connecting or writing.
        }
        while ( ! file_exists( $path ) && ! is_link( $path ) ) {
            $parent = dirname( $path );
            if ( $parent === $path ) {
                return 'could not create directory';
            }
            $path = $parent;
        }
        return is_dir( $path ) && is_writable( $path ) ? '' : 'could not create directory'; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Resolution must use local metadata without connecting or writing.
    }

    /**
     * Create a site's private directory.
     *
     * @param int $site_id Site id.
     * @return string|\WP_Error
     */
    public static function site_dir( int $site_id ): string|\WP_Error {
        if ( $site_id <= 0 ) {
            return self::unavailable( (string) $site_id, 'site id must be positive' );
        }
        return self::child_dir( 'sites', (string) $site_id );
    }

    /**
     * Read a site's private location without creating its directory.
     *
     * @param int $site_id Site id.
     * @return string|\WP_Error
     */
    public static function site_path( int $site_id ): string|\WP_Error {
        if ( $site_id <= 0 ) {
            return self::unavailable( (string) $site_id, 'site id must be positive' );
        }
        $root = self::resolve();
        return is_wp_error( $root ) ? $root : $root . 'sites/' . $site_id . '/';
    }

    /**
     * Create a private staging directory.
     *
     * @param string $kind Staging kind.
     * @param string $name Job name.
     * @return string|\WP_Error
     */
    public static function staging_dir( string $kind, string $name ): string|\WP_Error {
        $path = self::staging_path( $kind, $name );
        if ( is_wp_error( $path ) ) {
            return $path;
        }
        return self::child_dir( $kind, basename( $path ) );
    }

    /**
     * Read a staging location without creating it; cleanup and lookups must not create the root.
     *
     * @param string $kind Staging kind.
     * @param string $name Job name.
     * @return string|\WP_Error
     */
    public static function staging_path( string $kind, string $name ): string|\WP_Error {
        if ( ! in_array( $kind, array( 'restore-staging', 'upload-staging', 'test-restore' ), true )
            || false !== strpos( $name, '..' ) || false !== strpos( $name, '/' ) || false !== strpos( $name, '\\' ) || false !== strpos( $name, "\0" ) ) {
            return self::unavailable( $kind . '/' . $name, 'invalid staging name or kind' );
        }
        $name = sanitize_file_name( $name );
        if ( '' === $name || false !== strpos( $name, '..' ) || false !== strpos( $name, '/' ) || false !== strpos( $name, '\\' ) ) {
            return self::unavailable( $kind . '/' . $name, 'invalid staging name' );
        }
        $root = self::resolve();
        return is_wp_error( $root ) ? $root : $root . $kind . '/' . $name . '/';
    }

    /**
     * Read the historical location without creating it.
     *
     * @param int $site_id Site id.
     * @return string
     */
    public static function legacy_site_dir( int $site_id ): string {
        $upload = apply_filters( 'mainwp_get_wp_upload_dir', wp_upload_dir( null, false ), null, false );
        return trailingslashit( $upload['basedir'] ) . 'mainwp/0/' . $site_id . '/';
    }

    /**
     * Confine recorded paths to either generation of site storage.
     *
     * @param string $path Candidate path.
     * @param int    $site_id Site id.
     * @return bool
     */
    public static function is_within_site_roots( string $path, int $site_id ): bool {
        if ( $site_id <= 0 ) {
            return false;
        }
        return self::is_within_private_site_dir( $path, $site_id )
            || MainWP_Backup_File_Manager::path_is_confined_to( $path, self::legacy_site_dir( $site_id ) );
    }

    /**
     * Confine new writes to private storage, including resumed downloads.
     *
     * @param string $path Candidate path.
     * @param int    $site_id Site id.
     * @return bool
     */
    public static function is_within_private_site_dir( string $path, int $site_id ): bool {
        $private = self::site_path( $site_id );
        return ! is_wp_error( $private ) && ! is_link( untrailingslashit( $private ) ) && ! is_link( dirname( untrailingslashit( $private ) ) ) && MainWP_Backup_File_Manager::path_is_confined_to( $path, $private );
    }

    /**
     * Refuse invalid paths and paths inside WordPress or its content directory.
     *
     * @param mixed $candidate Selected directory.
     * @return string Empty when accepted.
     */
    private static function refusal_reason( $candidate ): string {
        if ( ! is_string( $candidate ) || '' === trim( $candidate ) || false !== strpos( $candidate, "\0" ) ) {
            return 'expected a non-empty path without NUL bytes';
        }
        if ( ! path_is_absolute( $candidate ) ) {
            return 'expected an absolute path';
        }
        $candidate = untrailingslashit( wp_normalize_path( $candidate ) );
        if ( in_array( '..', explode( '/', $candidate ), true ) ) {
            return "expected a path without '..' segments";
        }
        if ( is_link( $candidate ) ) {
            return 'directory is a symlink';
        }
        foreach ( array( ABSPATH, WP_CONTENT_DIR ) as $boundary ) {
            $boundary = untrailingslashit( wp_normalize_path( $boundary ) );
            foreach ( array( $boundary, self::canonical_path( $boundary ) ) as $served ) {
                foreach ( array( $candidate, self::canonical_path( $candidate ) ) as $private ) {
                    if ( $private === $served || str_starts_with( $private, $served . '/' ) ) {
                        return 'directory is inside WordPress or its content directory';
                    }
                }
            }
        }
        return '';
    }

    /**
     * Recheck parents when a resumed writer creates staging.
     *
     * @param string $kind Parent name.
     * @param string $name Child name.
     * @return string|\WP_Error
     */
    private static function child_dir( string $kind, string $name ): string|\WP_Error {
        $root = self::resolve();
        if ( is_wp_error( $root ) ) {
            return $root;
        }
        $root = self::ensure_private_dir( $root );
        if ( is_wp_error( $root ) ) {
            return $root;
        }
        $parent = self::ensure_private_dir( $root . $kind, $root );
        if ( is_wp_error( $parent ) ) {
            return $parent;
        }
        return self::ensure_private_dir( $parent . $name, $parent, false );
    }

    /**
     * Verify location and actual writes before accepting a directory.
     *
     * @param mixed       $dir Directory.
     * @param string|null $required_parent Required parent.
     * @param bool        $protect Add defense-in-depth files to shared parents.
     * @return string|\WP_Error
     */
    private static function ensure_private_dir( $dir, ?string $required_parent = null, bool $protect = true ): string|\WP_Error {
        // phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- Local filesystem probes must report failure without emitting warnings.
        $reason = self::refusal_reason( $dir );
        if ( '' !== $reason ) {
            return self::unavailable( is_string( $dir ) ? str_replace( "\0", '[NUL]', $dir ) : '(' . gettype( $dir ) . ')', $reason );
        }
        $dir       = untrailingslashit( wp_normalize_path( $dir ) );
        $candidate = self::canonical_path( $dir );
        if ( null !== $required_parent && ( is_link( untrailingslashit( $required_parent ) ) || 0 !== strpos( $candidate . '/', trailingslashit( self::canonical_path( $required_parent ) ) ) ) ) {
            return self::unavailable( $dir, 'directory escapes its private parent' );
        }
        $existed = is_dir( $dir );
        if ( ! wp_mkdir_p( $dir ) ) {
            return self::unavailable( $dir, 'could not create directory' );
        }
        $real = realpath( $dir );
        if ( false === $real || is_link( $dir ) || wp_normalize_path( $real ) !== $candidate ) {
            return self::unavailable( $dir, 'directory changed during creation' );
        }
        $probe   = $real . '/.write-' . wp_generate_password( 24, false );
        $handle  = @fopen( $probe, 'x' );
        $written = false;
        if ( false !== $handle ) {
            $written = 1 === fwrite( $handle, '1' );
            $written = fclose( $handle ) && $written;
            $written = @unlink( $probe ) && $written;
        }
        if ( ! $written ) {
            return self::unavailable( $dir, 'could not create and remove a write probe' );
        }
        if ( ! $existed ) {
            @chmod( $real, 0700 );
        }
        if ( ! $existed && $protect ) {
            foreach ( array(
                'index.php' => '',
                '.htaccess' => 'deny from all',
            ) as $file => $contents ) {
                $handle = @fopen( $real . '/' . $file, 'x' );
                if ( false !== $handle ) {
                    fwrite( $handle, $contents );
                    fclose( $handle );
                }
            }
        }
        return trailingslashit( $real );
        // phpcs:enable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged
    }

    /**
     * Resolve existing ancestors and normalize missing path segments.
     *
     * @param string $path Path.
     * @return string
     */
    private static function canonical_path( string $path ): string {
        $real = realpath( $path );
        if ( false !== $real ) {
            return untrailingslashit( wp_normalize_path( $real ) );
        }
        $parent = dirname( $path );
        if ( $parent === $path ) {
            return untrailingslashit( wp_normalize_path( $path ) );
        }
        $base = self::canonical_path( $parent );
        $name = basename( $path );
        if ( '..' === $name ) {
            return dirname( $base );
        }
        return '.' === $name ? $base : $base . '/' . $name;
    }

    /**
     * Explain the path failure to the operator.
     *
     * @param string $path Path.
     * @param string $reason Refusal reason.
     * @return \WP_Error
     */
    private static function unavailable( string $path, string $reason ): \WP_Error {
        $searched = self::$used_ladder ? __( ' Sibling directories tried: private_html, private.', 'mainwp' ) : '';
        return new \WP_Error(
            'mainwp_backup_private_root_unavailable',
            sprintf(
                /* translators: 1: Directory path, 2: Failure reason, 3: Sibling directories tried, when applicable. */
                __( 'Private backup directory "%1$s" is unavailable: %2$s.%3$s Configure MAINWP_BACKUP_PRIVATE_DIR outside ABSPATH and WP_CONTENT_DIR with write access. On an addon domain, when WordPress sits in its own subdirectory of the site, or when the Dashboard is served from a folder inside another site, set MAINWP_BACKUP_PRIVATE_DIR to a directory no web server serves.', 'mainwp' ),
                $path,
                $reason,
                $searched
            )
        );
    }
}
