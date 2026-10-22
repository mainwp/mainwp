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
    }

    /**
     * Resolve the configured private root without creating it.
     *
     * The default is mainwp-private/<WordPress directory name>/backups beside WordPress, so Dashboards installed side by side never share a root (retention would delete each other's snapshots).
     * For a WordPress directory named mainwp-private, the sibling container is mainwp-private-dashboard to keep the root outside WordPress.
     * Changing MAINWP_BACKUP_PRIVATE_DIR, or renaming the WordPress directory, after backups exist means moving the sites/ folder to the new root, or the older snapshots leave retention and the Backups tab; restore still works from the recorded paths.
     * Hosts that deploy through an atomic symlink (current -> releases/<id>: Capistrano, Deployer, Envoyer) must set MAINWP_BACKUP_PRIVATE_DIR: WordPress resolves ABSPATH through the release directory, so the default folder name changes with every release and the earlier snapshots drop out of retention and the Backups tab.
     * Without a constant or filter override, a refused default stays refused and an existing default always wins. Otherwise, existing sibling backup roots take precedence over creating the default, followed by creating a sibling backup root if the default cannot be created. Siblings are considered in private_html, private order and only within real directories already provided by the host.
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
        if ( ! defined( 'MAINWP_BACKUP_PRIVATE_DIR' )
            && is_string( $candidate ) && is_string( $default )
            && untrailingslashit( wp_normalize_path( $candidate ) ) === untrailingslashit( wp_normalize_path( $default ) )
            && '' === self::refusal_reason( $default )
            && ! is_dir( $default ) ) {
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
                if ( null === $creatable && '' === self::path_availability_reason( $path ) ) {
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
            __( 'Backup directory "%1$s" cannot be used (%2$s). Choose a folder outside the WordPress and wp-content folders that the web server can write to.', 'mainwp' ),
            str_replace( "\0", '[NUL]', $path ),
            $reason
        );
    }

    /**
     * Refuse invalid paths and paths inside WordPress or its content directory.
     *
     * @param mixed $candidate     Selected directory.
     * @param bool  $allow_symlink Accept a symlinked directory that resolves outside both trees.
     * @return string Empty when accepted.
     */
    private static function refusal_reason( $candidate, bool $allow_symlink = false ): string {
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
        if ( ! $allow_symlink && is_link( $candidate ) ) {
            return 'directory is a symlink';
        }
        $canonical = self::canonical_path( $candidate );
        if ( null === $canonical ) {
            return 'directory path is too deep';
        }
        foreach ( array( ABSPATH, WP_CONTENT_DIR ) as $boundary ) {
            $boundary    = untrailingslashit( wp_normalize_path( $boundary ) );
            $served_path = self::canonical_path( $boundary );
            if ( null === $served_path ) {
                return 'served directory path is too deep';
            }
            foreach ( array( $boundary, $served_path ) as $served ) {
                foreach ( array( $candidate, $canonical ) as $private ) {
                    // These platforms can resolve case variants to the same served directory.
                    if ( 'Darwin' === PHP_OS_FAMILY || 'Windows' === PHP_OS_FAMILY ) {
                        $private = strtolower( $private );
                        $served  = strtolower( $served );
                    }
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
        $existed = is_dir( $root );
        $root    = self::ensure_private_dir( $root );
        if ( is_wp_error( $root ) ) {
            return $root;
        }
        if ( 'served' === self::exposure_verdict( $root, ! $existed ) ) {
            return self::unavailable( $root, __( 'the web server serves this directory, so backups already stored in it can be downloaded over the web until they are moved or deleted', 'mainwp' ) );
        }
        $parent = self::ensure_private_dir( $root . $kind, $root );
        if ( is_wp_error( $parent ) ) {
            return $parent;
        }
        return self::ensure_private_dir( $parent . $name, $parent, false );
    }

    /**
     * Cache loopback evidence without keeping a repaired host refused across requests.
     *
     * @param string $root Canonical root.
     * @param bool   $created Whether this writer created the root.
     * @return string
     */
    private static function exposure_verdict( string $root, bool $created ): string {
        // realpath() keeps backslashes on Windows, while every mapping base is normalized.
        $root     = trailingslashit( wp_normalize_path( $root ) );
        $site_url = site_url();
        $key      = hash( 'sha256', untrailingslashit( $root ) . "\n" . $site_url );
        if ( ! $created && isset( self::$probe_verdicts[ $key ] ) ) {
            return self::$probe_verdicts[ $key ];
        }

        $document_root = '';
        if ( ! empty( $_SERVER['DOCUMENT_ROOT'] ) && is_string( $_SERVER['DOCUMENT_ROOT'] ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- This only builds a candidate URL; exposure still requires the random token back.
            $document_root = wp_unslash( $_SERVER['DOCUMENT_ROOT'] );
        }
        $urls = self::probe_urls( array( $root, self::$configured_root ), $site_url, ABSPATH, $document_root );

        $cached = get_option( 'mainwp_backup_private_root_probe' );
        $cached = is_array( $cached ) && ( $cached['key'] ?? '' ) === $key ? $cached : array();
        $probed = isset( $cached['urls'] ) && is_array( $cached['urls'] ) ? array_values( array_filter( $cached['urls'], 'is_string' ) ) : array();
        // A web request can see a document-root URL that the cron request behind the cached allow could not.
        if ( ! $created && time() < (int) ( $cached['expires'] ?? 0 )
            && in_array( $cached['verdict'] ?? '', array( 'not_served', 'unknown' ), true )
            && array() === array_diff( $urls, $probed ) ) {
            self::$probe_verdicts[ $key ] = $cached['verdict'];
            return $cached['verdict'];
        }

        // Cron and CLI requests have no document root, so they also probe what a web request mapped.
        $urls                         = array_slice( array_values( array_unique( array_merge( $urls, $probed ) ) ), 0, 8 );
        $reason                       = '';
        $verdict                      = array() === $urls ? 'unmapped' : self::probe_exposure( $root, $urls, $reason );
        self::$probe_verdicts[ $key ] = $verdict;
        // Mapping costs no request, and a request without a document root must not hide one that has it.
        if ( 'unmapped' !== $verdict ) {
            update_option(
                'mainwp_backup_private_root_probe',
                array(
                    'key'     => $key,
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
     * @return string[] Root URLs with a trailing slash; empty when no base maps the root.
     */
    private static function probe_urls( array $roots, string $site_url, string $abspath, string $document_root ): array {
        $parts = wp_parse_url( $site_url );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return array();
        }
        $origin   = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
        $segments = array_values( array_filter( explode( '/', trim( $parts['path'] ?? '', '/' ) ), 'strlen' ) );
        $bases    = array();
        foreach ( array( array( $abspath, $segments ), array( $document_root, array() ) ) as list( $base, $base_segments ) ) {
            if ( '' === $base || ! path_is_absolute( $base ) ) {
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
                $mapped = null === $base ? null : self::probe_url_segments( $root, $base, $base_segments );
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
     * @return array|null Null when the root is outside the base's URL ancestry.
     */
    private static function probe_url_segments( string $root, string $ancestor, array $segments ): ?array {
        $levels = 0;
        $path   = untrailingslashit( $root );
        // These platforms can resolve case variants to the same served directory, as refusal_reason() assumes.
        $fold = 'Darwin' === PHP_OS_FAMILY || 'Windows' === PHP_OS_FAMILY ? 'strtolower' : static fn( string $value ): string => $value;
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
            return self::unavailable( $dir, 'directory path is too deep' );
        }
        $parent = null === $required_parent ? null : self::canonical_path( $required_parent );
        if ( null !== $required_parent && ( null === $parent || is_link( untrailingslashit( $required_parent ) ) || 0 !== strpos( $candidate . '/', trailingslashit( $parent ) ) ) ) {
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
