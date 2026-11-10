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
     * Dashboard administrator's confirmation of the resolved backup path.
     */
    private const CONFIRMATION_OPTION = 'mainwp_backup_private_root_confirmation';

    /**
     * Error reported when a backup or staging write cannot use private storage.
     */
    private const ERROR_CODE = 'mainwp_backup_private_root_unavailable';

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
     * Whether the host's sibling directories failed to provide a fallback.
     *
     * @var bool
     */
    private static $used_ladder = false;

    /**
     * Request-local exposure verdicts by root and site URL.
     *
     * @var array
     */
    private static $probe_verdicts = array();

    /**
     * The accepted root before symlinks are resolved, for URL mapping.
     *
     * @var string
     */
    private static $configured_root = '';

    /**
     * Whether the root was chosen without a constant or filter override.
     *
     * @var bool
     */
    private static $automatic_root = false;

    /**
     * Reset request-local state for tests.
     *
     * @return void
     */
    public static function reset(): void {
        self::$root                = null;
        self::$availability_reason = '';
        self::$used_ladder         = false;
        self::$probe_verdicts      = array();
        self::$configured_root     = '';
        self::$automatic_root      = false;
    }

    /**
     * Resolve the configured private root without creating it.
     *
     * The default is mainwp-private/<WordPress directory name>/backups beside WordPress, so Dashboards installed side by side never share a root (retention would delete each other's snapshots).
     * For a WordPress directory named mainwp-private, the sibling container is mainwp-private-dashboard to keep the root outside WordPress.
     * Changing MAINWP_BACKUP_PRIVATE_DIR, or renaming the WordPress directory, after backups exist means moving the sites/ folder to the new root, or the older snapshots leave retention and the Backups tab; restore still works from the recorded paths.
     * Hosts that deploy through an atomic symlink (current -> releases/<id>: Capistrano, Deployer, Envoyer) must set MAINWP_BACKUP_PRIVATE_DIR: WordPress resolves ABSPATH through the release directory, so the default folder name changes with every release and the earlier snapshots drop out of retention and the Backups tab.
     * Without an override, preserve existing backup roots for reads. Only the host's real private directory can serve as a new fallback when the default cannot be created.
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

        self::$automatic_root = ! defined( 'MAINWP_BACKUP_PRIVATE_DIR' )
            && is_string( $candidate ) && is_string( $default )
            && untrailingslashit( wp_normalize_path( $candidate ) ) === untrailingslashit( wp_normalize_path( $default ) );
        if ( self::$automatic_root && '' === self::refusal_reason( $default ) && ! is_dir( $default ) ) {
            $existing  = null;
            $creatable = '' === self::path_availability_reason( $default ) ? $default : null;
            foreach ( array( $parent . '/private_html', $parent . '/private' ) as $base ) {
                // The host must provide the sibling; private_html may be a served HTTPS root.
                if ( ! is_dir( $base ) || is_link( $base ) ) {
                    continue;
                }
                $path = $base . $suffix;
                if ( '' !== self::refusal_reason( $path ) ) {
                    continue;
                }
                // Keep using existing snapshots even if the default's parent becomes writable.
                if ( is_dir( $path ) ) {
                    $existing = $path;
                    break;
                }
                if ( $base === $parent . '/private' && null === $creatable && '' === self::path_availability_reason( $path ) ) {
                    $creatable = $path;
                }
            }
            $candidate         = $existing ?? $creatable ?? $default;
            self::$used_ladder = null === $existing && null === $creatable;
        }
        $reason                    = self::refusal_reason( $candidate );
        self::$availability_reason = '' === $reason ? self::path_availability_reason( $candidate ) : $reason;
        self::$configured_root     = '' === $reason ? untrailingslashit( wp_normalize_path( $candidate ) ) : '';
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
            return is_writable( $path ) ? '' : __( 'directory is not writable', 'mainwp' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Resolution must use local metadata without connecting or writing.
        }
        while ( ! file_exists( $path ) && ! is_link( $path ) ) {
            $parent = dirname( $path );
            if ( $parent === $path ) {
                return __( 'could not create directory', 'mainwp' );
            }
            $path = $parent;
        }
        return is_dir( $path ) && is_writable( $path ) ? '' : __( 'could not create directory', 'mainwp' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Resolution must use local metadata without connecting or writing.
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
     * Backup destinations need the same location checks as private storage.
     *
     * @param string $path Candidate directory.
     * @return string Empty when accepted, otherwise an operator-facing refusal.
     */
    public static function served_tree_refusal( string $path ): string {
        // A destination may be a symlink to another disk; only where it resolves matters.
        $reason = self::refusal_reason( $path, true );
        if ( '' === $reason ) {
            return '';
        }
        return sprintf(
            /* translators: 1: Directory path, 2: Failure reason. */
            __( 'Backup directory "%1$s" on the Dashboard server cannot be used (%2$s). Choose a folder outside the WordPress and wp-content folders that the web server can write to.', 'mainwp' ),
            str_replace( "\0", '[NUL]', $path ),
            $reason
        );
    }

    /**
     * Refuse invalid paths and paths inside WordPress or its content directory.
     *
     * @param mixed         $candidate     Selected directory.
     * @param bool          $allow_symlink Accept a symlinked directory that resolves outside both trees.
     * @param string        $os_family     Host OS family.
     * @param string[]|null $boundaries    Served directories; defaults to WordPress and its content directory.
     * @return string Empty when accepted.
     */
    private static function refusal_reason( $candidate, bool $allow_symlink = false, string $os_family = PHP_OS_FAMILY, ?array $boundaries = null ): string {
        if ( ! is_string( $candidate ) || '' === trim( $candidate ) || false !== strpos( $candidate, "\0" ) ) {
            return __( 'expected a non-empty path without NUL bytes', 'mainwp' );
        }
        if ( ! self::is_absolute_path( $candidate, $os_family ) ) {
            return __( 'expected an absolute path', 'mainwp' );
        }
        $candidate = untrailingslashit( wp_normalize_path( $candidate ) );
        if ( in_array( '..', explode( '/', $candidate ), true ) ) {
            return __( "expected a path without '..' segments", 'mainwp' );
        }
        if ( ! $allow_symlink && is_link( $candidate ) ) {
            return __( 'directory is a symlink', 'mainwp' );
        }
        $canonical = self::canonical_path( $candidate );
        if ( null === $canonical ) {
            return __( 'directory path is too deep', 'mainwp' );
        }
        foreach ( $boundaries ?? array( ABSPATH, WP_CONTENT_DIR ) as $boundary ) {
            $boundary    = untrailingslashit( wp_normalize_path( $boundary ) );
            $served_path = self::canonical_path( $boundary );
            if ( null === $served_path ) {
                return __( 'served directory path is too deep', 'mainwp' );
            }
            foreach ( array( $boundary, $served_path ) as $served ) {
                foreach ( array( $candidate, $canonical ) as $private ) {
                    // These platforms can resolve case variants to the same served directory.
                    if ( 'Darwin' === $os_family || 'Windows' === $os_family ) {
                        $private = strtolower( $private );
                        $served  = strtolower( $served );
                    }
                    if ( $private === $served || str_starts_with( $private, $served . '/' ) ) {
                        return __( 'directory is inside WordPress or its content directory', 'mainwp' );
                    }
                }
            }
        }
        return '';
    }

    /**
     * Recognize normalized drive roots on Windows as well as native absolute paths.
     *
     * @param string $path Path.
     * @param string $os_family Host OS family.
     * @return bool
     */
    private static function is_absolute_path( string $path, string $os_family = PHP_OS_FAMILY ): bool {
        return ( 'Windows' === $os_family && 1 === preg_match( '#^[a-zA-Z]:/#', wp_normalize_path( $path ) ) ) || path_is_absolute( $path );
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
        $existed = is_dir( $root );
        if ( ! $existed ) {
            $error = self::verification_error( $root, self::exposure_verdict( $root, true ) );
            if ( is_wp_error( $error ) ) {
                return $error;
            }
        }
        $resolved_root = $root;
        $root          = self::ensure_private_dir( $root );
        if ( is_wp_error( $root ) ) {
            return $root;
        }
        if ( wp_normalize_path( $root ) !== $resolved_root ) {
            return self::unavailable( $resolved_root, __( 'directory changed during creation', 'mainwp' ) );
        }
        $error = self::verification_error( $root, self::exposure_verdict( $root, ! $existed ) );
        if ( is_wp_error( $error ) ) {
            return $error;
        }
        $parent = self::ensure_private_dir( $root . $kind, $root );
        if ( is_wp_error( $parent ) ) {
            return $parent;
        }
        return self::ensure_private_dir( $parent . $name, $parent, false );
    }

    /**
     * Read the storage status for the settings page without creating directories.
     *
     * @param bool $refresh Recheck exposure even if a safe result is cached.
     * @return array{path: string, status: string, key: string}|null Storage status, or null for an invalid or unwritable path.
     */
    public static function storage_status( bool $refresh = false ): ?array {
        $root = self::resolve();
        if ( is_wp_error( $root ) || '' !== self::availability_reason() ) {
            return null;
        }
        return array(
            'path'   => $root,
            'status' => self::verification_status( $root, self::exposure_verdict( $root, $refresh ) ),
            'key'    => self::confirmation_key( $root ),
        );
    }

    /**
     * Save confirmation for the exact path shown on the authorized settings form.
     *
     * @param string $key Submitted path fingerprint.
     * @return bool Whether the confirmation was saved or already exists.
     */
    public static function confirm_storage( string $key ): bool {
        $root = self::resolve();
        if ( is_wp_error( $root ) || self::confirmation_key( $root ) !== $key ) {
            return false;
        }
        $storage = self::storage_status( true );
        if ( null === $storage || ! in_array( $storage['status'], array( 'confirmation_required', 'confirmed' ), true ) ) {
            return false;
        }
        return update_option( self::CONFIRMATION_OPTION, $key, false ) || get_option( self::CONFIRMATION_OPTION ) === $key;
    }

    /**
     * Fingerprint the canonical path without storing a filesystem path in settings.
     *
     * @param string $root Canonical backup root.
     * @return string
     */
    private static function confirmation_key( string $root ): string {
        return hash( 'sha256', untrailingslashit( wp_normalize_path( $root ) ) );
    }

    /**
     * Apply the same verification policy to settings and all backup writers.
     *
     * @param string $root Canonical backup root.
     * @param string $exposure Loopback verdict.
     * @return string
     */
    private static function verification_status( string $root, string $exposure ): string {
        if ( 'served' === $exposure ) {
            return 'public';
        }
        $path = untrailingslashit( wp_normalize_path( $root ) );
        // A guessed URL's response cannot rule out aliases for custom or legacy roots.
        if ( 'unknown' !== $exposure && self::$automatic_root && $path === self::$configured_root && ! preg_match( '~(?:^|/)private_html(?:/|$)~i', $path ) ) {
            return 'automatic';
        }
        if ( get_option( self::CONFIRMATION_OPTION ) === self::confirmation_key( $root ) ) {
            return 'confirmed';
        }

        /**
         * Filter the canonical backup directory verified by the hosting administrator.
         *
         * Return an exact path only after confirming that no web server serves the
         * directory, including through aliases and other virtual hosts.
         *
         * @param string $verified The verified path or an empty string by default.
         */
        $verified = apply_filters( 'mainwp_backup_verified_private_root', defined( 'MAINWP_BACKUP_VERIFIED_PRIVATE_DIR' ) ? MAINWP_BACKUP_VERIFIED_PRIVATE_DIR : '' );
        if ( 'unknown' !== $exposure && '' !== $path && is_string( $verified ) && untrailingslashit( wp_normalize_path( $verified ) ) === $path ) {
            return 'confirmed';
        }
        return 'confirmation_required';
    }

    /**
     * Explain why archive writes cannot proceed.
     *
     * @param string $root Canonical backup root.
     * @param string $exposure Loopback verdict.
     * @return \WP_Error|null
     */
    private static function verification_error( string $root, string $exposure ): ?\WP_Error {
        $status = self::verification_status( $root, $exposure );
        if ( 'public' === $status ) {
            return self::unavailable( $root, __( 'the web server serves this directory, so backups already stored in it can be downloaded over the web until they are moved or deleted', 'mainwp' ) );
        }
        if ( 'confirmation_required' === $status ) {
            return new \WP_Error(
                self::ERROR_CODE,
                sprintf(
                    /* translators: %s: resolved backup directory. */
                    __( 'MainWP could not verify that the backup directory "%s" is private. Ask your host to verify that no domain or alias serves it, then confirm the directory in Built-In Backups settings.', 'mainwp' ),
                    $root
                )
            );
        }
        return null;
    }

    /**
     * Cache loopback evidence without keeping a repaired host refused across requests.
     *
     * @param string $root Canonical root.
     * @param bool   $refresh Whether to bypass a cached allow.
     * @return string
     */
    private static function exposure_verdict( string $root, bool $refresh ): string {
        // realpath() keeps backslashes on Windows, while every mapping base is normalized.
        $root     = trailingslashit( wp_normalize_path( $root ) );
        $site_url = site_url();
        $key      = hash( 'sha256', untrailingslashit( $root ) . "\n" . $site_url );
        $exists   = is_dir( $root );
        if ( $exists && ! $refresh && isset( self::$probe_verdicts[ $key ] ) ) {
            return self::$probe_verdicts[ $key ];
        }

        $document_root = '';
        if ( ! empty( $_SERVER['DOCUMENT_ROOT'] ) && is_string( $_SERVER['DOCUMENT_ROOT'] ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- This only builds a candidate URL; exposure still requires the random token back.
            $document_root = wp_unslash( $_SERVER['DOCUMENT_ROOT'] );
        }
        $urls = self::probe_urls( array( $root, self::$configured_root ), $site_url, ABSPATH, $document_root );

        $cached       = get_option( 'mainwp_backup_private_root_probe' );
        $root_key     = self::confirmation_key( $root );
        $same_mapping = is_array( $cached ) && ( $cached['key'] ?? '' ) === $key;
        $was_public   = is_array( $cached ) && 'served' === ( $cached['verdict'] ?? '' ) && ( $same_mapping || ( $cached['root'] ?? '' ) === $root_key );
        $cached       = $same_mapping ? $cached : array();
        $probed       = isset( $cached['urls'] ) && is_array( $cached['urls'] ) ? array_values( array_filter( $cached['urls'], 'is_string' ) ) : array();
        // A web request can see a document-root URL that the cron request behind the cached allow could not.
        if ( $exists && ! $refresh && time() < (int) ( $cached['expires'] ?? 0 )
            && 'not_served' === ( $cached['verdict'] ?? '' )
            && array() === array_diff( $urls, $probed ) ) {
            self::$probe_verdicts[ $key ] = $cached['verdict'];
            return $cached['verdict'];
        }

        // Cron and CLI requests have no document root, so they also probe what a web request mapped.
        $urls    = array_slice( array_values( array_unique( array_merge( $urls, $probed ) ) ), 0, 8 );
        $reason  = '';
        $verdict = ! $exists || array() === $urls ? 'unmapped' : self::probe_exposure( $root, $urls, $reason );
        // An inconclusive retry or a different origin cannot clear known exposure.
        if ( $was_public && ( ! $same_mapping || in_array( $verdict, array( 'unknown', 'unmapped' ), true ) ) ) {
            self::$probe_verdicts[ $key ] = 'served';
            return 'served';
        }
        self::$probe_verdicts[ $key ] = $verdict;
        if ( 'served' === $verdict && get_option( self::CONFIRMATION_OPTION ) === $root_key ) {
            delete_option( self::CONFIRMATION_OPTION );
        }
        // Mapping costs no request, and a request without a document root must not hide one that has it.
        if ( 'unmapped' !== $verdict ) {
            update_option(
                'mainwp_backup_private_root_probe',
                array(
                    'key'     => $key,
                    'root'    => $root_key,
                    'verdict' => $verdict,
                    'expires' => 'served' === $verdict ? 0 : time() + ( 'unknown' === $verdict ? DAY_IN_SECONDS : 7 * DAY_IN_SECONDS ),
                    'urls'    => $urls,
                ),
                false
            );
        }
        if ( 'unknown' === $verdict && MainWP_Backup_Diagnostics::is_enabled() ) {
            MainWP_Backup_Diagnostics::instance()->record(
                '',
                'private_root_probe',
                0,
                0,
                array(
                    'verdict' => 'unknown',
                    'reason'  => $reason,
                )
            );
        }
        return $verdict;
    }

    /**
     * Every URL the root could be served at on this host.
     *
     * Symlinks and aliases separate filesystem ancestry from URL ancestry, so one
     * base can map the root to a URL nothing serves while another maps it to the
     * URL that does. Each distinct mapping is probed.
     *
     * @param string[] $roots Root as resolved and as configured; empty entries are skipped.
     * @param string   $site_url WordPress URL, which serves ABSPATH.
     * @param string   $abspath WordPress directory as configured.
     * @param string   $document_root Web server document root, served at the host root; empty outside web requests.
     * @param string   $os_family Host OS family.
     * @return string[] Root URLs with a trailing slash; empty when no base maps the root.
     */
    private static function probe_urls( array $roots, string $site_url, string $abspath, string $document_root, string $os_family = PHP_OS_FAMILY ): array {
        $parts = wp_parse_url( $site_url );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return array();
        }
        $origin   = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
        $segments = array_values( array_filter( explode( '/', trim( $parts['path'] ?? '', '/' ) ), 'strlen' ) );
        $bases    = array();
        foreach ( array( array( $abspath, $segments ), array( $document_root, array() ) ) as list( $base, $base_segments ) ) {
            if ( '' === $base || ! self::is_absolute_path( $base, $os_family ) ) {
                continue;
            }
            $bases[] = array( self::canonical_path( $base ), $base_segments );
            $bases[] = array( untrailingslashit( wp_normalize_path( $base ) ), $base_segments );
        }
        $urls = array();
        foreach ( $roots as $root ) {
            $root = untrailingslashit( wp_normalize_path( (string) $root ) );
            if ( '' === $root ) {
                continue;
            }
            foreach ( $bases as list( $base, $base_segments ) ) {
                $mapped = null === $base ? null : self::probe_url_segments( $root, $base, $base_segments, $os_family );
                if ( null !== $mapped ) {
                    $urls[] = $origin . '/' . ( array() === $mapped ? '' : implode( '/', $mapped ) . '/' );
                }
            }
        }
        return array_slice( array_values( array_unique( $urls ) ), 0, 8 );
    }

    /**
     * Map the root through one base served at known URL segments.
     *
     * @param string $root Normalized root.
     * @param string $ancestor Filesystem base served at the URL segments.
     * @param array  $segments URL path segments for the base.
     * @param string $os_family Host OS family.
     * @return array|null Null when the root is outside the base's URL ancestry.
     */
    private static function probe_url_segments( string $root, string $ancestor, array $segments, string $os_family = PHP_OS_FAMILY ): ?array {
        $levels = 0;
        $path   = untrailingslashit( $root );
        // These platforms can resolve case variants to the same served directory, as refusal_reason() assumes.
        $fold = 'Darwin' === $os_family || 'Windows' === $os_family ? 'strtolower' : static fn( string $value ): string => $value;
        while ( $fold( $path ) !== $fold( $ancestor ) && ! str_starts_with( $fold( $path ), $fold( trailingslashit( $ancestor ) ) ) ) {
            $parent = dirname( $ancestor );
            if ( $parent === $ancestor ) {
                return null;
            }
            $ancestor = $parent;
            ++$levels;
        }
        if ( count( $segments ) < $levels ) {
            return null;
        }
        $segments = array_slice( $segments, 0, count( $segments ) - $levels );
        $relative = trim( substr( $path, strlen( $ancestor ) ), '/' );
        if ( '' !== $relative ) {
            $segments = array_merge( $segments, array_map( 'rawurlencode', explode( '/', $relative ) ) );
        }
        return $segments;
    }

    /**
     * Confirm exposure with harmless bytes that follow the archive serving rules.
     *
     * @param string   $root Normalized root.
     * @param string[] $urls Candidate root URLs on one origin.
     * @param string   $reason Short diagnostic code for an unknown result.
     * @return string
     */
    private static function probe_exposure( string $root, array $urls, string &$reason ): string {
        // phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- Local probes must fail without emitting filesystem warnings.
        $name   = 'mainwp-probe-' . wp_generate_password( 24, false ) . '.zip';
        $path   = $root . $name;
        $token  = wp_generate_password( 32, false );
        $handle = false;
        try {
            $reason = 'probe_write_failed';
            $handle = @fopen( $path, 'x' );
            if ( false === $handle ) {
                return 'unknown';
            }
            $written = strlen( $token ) === @fwrite( $handle, $token );
            $written = @fclose( $handle ) && $written;
            if ( ! $written ) {
                return 'unknown';
            }
            $reason   = '';
            $verdict  = 'not_served';
            $deadline = microtime( true ) + 10;
            foreach ( $urls as $url ) {
                $remaining = $deadline - microtime( true );
                if ( $remaining < 1 ) {
                    // A host that drops loopback packets would otherwise cost 5 seconds per candidate.
                    $reason = 'probe_budget';
                    return 'unknown';
                }
                $url     .= $name;
                $response = wp_remote_get(
                    $url,
                    array(
                        'timeout'             => min( 5, $remaining ),
                        'redirection'         => 3,
                        'sslverify'           => apply_filters( 'https_local_ssl_verify', false, $url ),
                        'limit_response_size' => 1024,
                    )
                );
                if ( is_wp_error( $response ) ) {
                    // Redirect loops and timeouts are path-specific, so a later candidate can still prove exposure.
                    $reason  = substr( sanitize_key( (string) $response->get_error_code() ), 0, 64 );
                    $verdict = 'unknown';
                    continue;
                }
                $status = (int) wp_remote_retrieve_response_code( $response );
                if ( 200 === $status && wp_remote_retrieve_body( $response ) === $token ) {
                    return 'served';
                }
                if ( $status >= 500 && $status < 600 ) {
                    $reason  = 'http_' . $status;
                    $verdict = 'unknown';
                }
            }
            return $verdict;
        } finally {
            if ( false !== $handle ) {
                @unlink( $path );
            }
        }
        // phpcs:enable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged
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
        if ( null === $candidate ) {
            return self::unavailable( $dir, __( 'directory path is too deep', 'mainwp' ) );
        }
        $parent = null === $required_parent ? null : self::canonical_path( $required_parent );
        if ( null !== $required_parent && ( null === $parent || is_link( untrailingslashit( $required_parent ) ) || 0 !== strpos( $candidate . '/', trailingslashit( $parent ) ) ) ) {
            return self::unavailable( $dir, __( 'directory escapes its private parent', 'mainwp' ) );
        }
        $existed = is_dir( $dir );
        if ( ! wp_mkdir_p( $dir ) ) {
            return self::unavailable( $dir, __( 'could not create directory', 'mainwp' ) );
        }
        $real = realpath( $dir );
        if ( false === $real || is_link( $dir ) || wp_normalize_path( $real ) !== $candidate ) {
            return self::unavailable( $dir, __( 'directory changed during creation', 'mainwp' ) );
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
            return self::unavailable( $dir, __( 'could not create and remove a write probe', 'mainwp' ) );
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
     * @param string $path  Path.
     * @param int    $depth Missing ancestor count.
     * @return string|null Null when the ancestor limit is exceeded.
     */
    private static function canonical_path( string $path, int $depth = 0 ): ?string {
        if ( $depth >= 64 ) {
            return null;
        }
        $real = realpath( $path );
        if ( false !== $real ) {
            return untrailingslashit( wp_normalize_path( $real ) );
        }
        $parent = dirname( $path );
        if ( $parent === $path ) {
            return untrailingslashit( wp_normalize_path( $path ) );
        }
        $base = self::canonical_path( $parent, $depth + 1 );
        if ( null === $base ) {
            return null;
        }
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
        $searched = self::$used_ladder ? __( ' Sibling directory tried for new backups: private.', 'mainwp' ) : '';
        return new \WP_Error(
            self::ERROR_CODE,
            sprintf(
                /* translators: 1: Directory path, 2: Failure reason, 3: Sibling directories tried, when applicable. */
                __( "Private backup directory \"%1\$s\" on the Dashboard server is unavailable: %2\$s.%3\$s\n\nBackups downloaded from the Child site and restore working files are stored on the Dashboard server. Check write access on the Dashboard server.\n\nConfigure MAINWP_BACKUP_PRIVATE_DIR in the Dashboard wp-config.php outside ABSPATH and WP_CONTENT_DIR. On an addon domain, when WordPress sits in its own subdirectory of the site, or when the Dashboard is served from a folder inside another site, choose a directory no web server serves.", 'mainwp' ),
                $path,
                $reason,
                $searched
            )
        );
    }
}
