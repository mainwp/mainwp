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
     * Reset request-local state for tests.
     *
     * @return void
     */
    public static function reset(): void {
        self::$root = null;
    }

    /**
     * Resolve and verify the private root without an uploads fallback.
     *
     * @return string|\WP_Error
     */
    public static function resolve(): string|\WP_Error {
        if ( null !== self::$root ) {
            return self::$root;
        }
        self::served_roots();
        if ( defined( 'MAINWP_BACKUP_PRIVATE_DIR' ) ) {
            $default = MAINWP_BACKUP_PRIVATE_DIR;
        } else {
            $suffix = get_option( 'mainwp_backup_private_suffix', '' );
            if ( '' === $suffix ) {
                add_option( 'mainwp_backup_private_suffix', strtolower( wp_generate_password( 16, false ) ), '', 'yes' );
                $suffix = get_option( 'mainwp_backup_private_suffix' );
            }
            $default = dirname( rtrim( ABSPATH, '/\\' ) ) . '/mainwp-private-' . $suffix . '/backups';
        }
        $root = apply_filters( 'mainwp_backup_private_root', $default );
        if ( ! is_string( $root ) || '' === trim( $root ) || false !== strpos( $root, "\0" ) ) {
            self::$root = self::unavailable( is_string( $root ) ? str_replace( "\0", '[NUL]', $root ) : '(' . gettype( $root ) . ')', 'expected a non-empty path without NUL bytes' );
        } elseif ( ! path_is_absolute( $root ) ) {
            self::$root = self::unavailable( $root, 'expected an absolute path' );
        } else {
            self::$root = self::ensure_private_dir( $root );
        }
        return self::$root;
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
        if ( ! in_array( $kind, array( 'restore-staging', 'upload-staging', 'test-restore' ), true )
            || false !== strpos( $name, '..' ) || false !== strpos( $name, '/' ) || false !== strpos( $name, '\\' ) || false !== strpos( $name, "\0" ) ) {
            return self::unavailable( $kind . '/' . $name, 'invalid staging name or kind' );
        }
        $name = sanitize_file_name( $name );
        if ( '' === $name || false !== strpos( $name, '..' ) || false !== strpos( $name, '/' ) || false !== strpos( $name, '\\' ) ) {
            return self::unavailable( $kind . '/' . $name, 'invalid staging name' );
        }
        return self::child_dir( $kind, $name );
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
     * Remember web roots so cron and CLI enforce the same boundary.
     *
     * @return array
     */
    private static function served_roots(): array {
        $roots = get_option( 'mainwp_backup_served_roots', array() );
        $roots = is_array( $roots ) ? array_filter( $roots, 'is_string' ) : array();
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- A filesystem boundary, never rendered or used as a write destination.
        $current = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if ( is_string( $current ) && '' !== trim( $current ) ) {
            $real    = realpath( $current );
            $current = wp_normalize_path( false === $real ? $current : $real );
            $current = '/' === $current ? $current : untrailingslashit( $current );
            if ( ! in_array( $current, $roots, true ) ) {
                $roots[] = $current;
                $roots   = array_slice( $roots, -10 );
                if ( ! add_option( 'mainwp_backup_served_roots', $roots, '', 'no' ) ) {
                    update_option( 'mainwp_backup_served_roots', $roots, false );
                }
            }
        }
        return array_filter( $roots, 'strlen' );
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
        $parent = self::ensure_private_dir( $root . $kind, $root );
        if ( is_wp_error( $parent ) ) {
            return $parent;
        }
        return self::ensure_private_dir( $parent . $name, $parent, false );
    }

    /**
     * Verify location and actual writes before accepting a directory.
     *
     * @param string      $dir Directory.
     * @param string|null $required_parent Required parent.
     * @param bool        $protect Add defense-in-depth files to shared parents.
     * @return string|\WP_Error
     */
    private static function ensure_private_dir( string $dir, ?string $required_parent = null, bool $protect = true ): string|\WP_Error {
        // phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- Local filesystem probes must report failure without emitting warnings.
        $dir = untrailingslashit( wp_normalize_path( $dir ) );
        if ( is_link( $dir ) ) {
            return self::unavailable( $dir, 'directory is a symlink' );
        }
        $candidate = self::canonical_path( $dir );
        foreach ( array_merge( array( ABSPATH, WP_CONTENT_DIR ), self::served_roots() ) as $public ) {
            foreach ( array( self::canonical_path( $public ), wp_normalize_path( $public ) ) as $served ) {
                $served = untrailingslashit( $served );
                if ( $candidate === $served || 0 === strpos( $candidate, $served . '/' ) || $dir === $served || 0 === strpos( $dir, $served . '/' ) ) {
                    return self::unavailable( $dir, 'directory is inside a served WordPress directory' );
                }
            }
        }
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
        return new \WP_Error(
            'mainwp_backup_private_root_unavailable',
            sprintf(
                /* translators: 1: Directory path, 2: Failure reason. */
                __( 'Private backup directory "%1$s" is unavailable: %2$s. Configure MAINWP_BACKUP_PRIVATE_DIR outside ABSPATH and WP_CONTENT_DIR with write access.', 'mainwp' ),
                $path,
                $reason
            )
        );
    }
}
