<?php
/**
 * Compare detected PHP versions with shared, cached PHP.net release data.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PHP release checks for the existing site hardening row.
 */
class MainWP_PHP_Security {

    /** Shared upstream release metadata. */
    const CACHE_KEY = 'mainwp_php_security_releases';

    /** Explanation for the current scan's PHP status.
     *
     * @var string
     */
    private static $message = '';

    /**
     * Return the explanatory text for the scan response.
     *
     * @return string Row message.
     */
    public static function get_message() {
        return static::$message;
    }

    /**
     * Replace the legacy fixed-version check while preserving flat Y/N statuses.
     *
     * @param mixed $stats   Child security statuses.
     * @param mixed $version PHP version already detected during sync.
     * @return mixed Security statuses.
     */
    public static function apply_to_stats( $stats, $version ) {
        static::$message = '';
        if ( is_array( $stats ) && isset( $stats['phpversion_matched'] ) ) {
            $result                      = static::check( $version );
            $stats['phpversion_matched'] = $result['status'];
            static::$message             = $result['message'];
        }
        return $stats;
    }

    /**
     * Compare a detected version without making an external request.
     *
     * @param mixed $version Detected PHP version, including vendor suffixes.
     * @return array Existing Y/N status and explanatory text.
     */
    public static function check( $version ) {
        $result = array(
            'status'  => 'N',
            'message' => __( 'PHP version could not be verified. Sync the site to update its details.', 'mainwp' ),
        );
        if ( ! is_string( $version ) || strlen( $version ) > 64 ||
            ! preg_match( '/^([1-9]\d?\.(?:0|[1-9]\d?)\.(?:0|[1-9]\d{0,2}))(?:[-+][A-Za-z0-9][A-Za-z0-9.+~_-]*)?$/D', $version, $matches ) ||
            preg_match( '/(?:^|[-+~_.])(?:dev|alpha|beta|rc)\d*(?:$|[-+~_.])/i', $version ) ) {
            return $result;
        }

        $normalized = $matches[1];
        $parts      = explode( '.', $normalized );
        $branch     = $parts[0] . '.' . $parts[1];
        $cache      = static::get_cache();
        if ( ! $cache ) {
            /* translators: %s: Detected PHP version. */
            $result['message'] = sprintf( __( 'PHP %s: security release information is currently unavailable.', 'mainwp' ), $version );
        } elseif ( ! isset( $cache['branches'][ $branch ] ) ) {
            /* translators: %s: Detected PHP version. */
            $result['message'] = sprintf( __( 'PHP %s is not listed as a supported stable branch. Contact your host.', 'mainwp' ), $version );
        } elseif ( version_compare( $normalized, $cache['branches'][ $branch ]['latest'], '>' ) ) {
            /* translators: %s: Detected PHP version. */
            $result['message'] = sprintf( __( 'PHP %s could not be verified against the cached release information.', 'mainwp' ), $version );
        } elseif ( version_compare( $normalized, $cache['branches'][ $branch ]['security'], '<' ) ) {
            /* translators: 1: Detected PHP version, 2: Recommended security release. */
            $result['message'] = sprintf( __( 'PHP %1$s: update to %2$s or newer, or confirm backported security fixes with your host.', 'mainwp' ), $version, $cache['branches'][ $branch ]['security'] );
        } else {
            $result['status'] = 'Y';
            /* translators: %s: Detected PHP version. */
            $result['message'] = sprintf( __( 'PHP %s meets the current security release baseline.', 'mainwp' ), $version );
        }
        return $result;
    }

    /**
     * Read validated data; allow one day of cache use during temporary outages.
     *
     * @return array|false Usable shared cache.
     */
    private static function get_cache() {
        $cache = get_option( static::CACHE_KEY, array() );
        if ( ! is_array( $cache ) || ! isset( $cache['checked_at'] ) || ! is_int( $cache['checked_at'] ) ||
            $cache['checked_at'] > time() || $cache['checked_at'] <= time() - DAY_IN_SECONDS ||
            empty( $cache['branches'] ) || ! is_array( $cache['branches'] ) || count( $cache['branches'] ) > 32 ) {
            return false;
        }
        foreach ( $cache['branches'] as $branch => $release ) {
            if ( ! is_string( $branch ) || ! preg_match( '/^[1-9]\d?\.(?:0|[1-9]\d?)$/D', $branch ) || ! is_array( $release ) ) {
                return false;
            }
            foreach ( array( 'latest', 'security' ) as $key ) {
                if ( ! isset( $release[ $key ] ) || ! is_string( $release[ $key ] ) ||
                    ! preg_match( '/^' . preg_quote( $branch, '/' ) . '\.(?:0|[1-9]\d{0,2})$/D', $release[ $key ] ) ) {
                    return false;
                }
            }
            if ( version_compare( $release['security'], $release['latest'], '>' ) ) {
                return false;
            }
        }
        return $cache;
    }

    /**
     * Refresh the shared cache from the existing general background job.
     *
     * Normal refreshes run every six hours. A failed lookup leaves the previous
     * cache intact and retries after fifteen minutes. No per-site queue is used.
     *
     * @return void
     */
    public static function refresh_cache() {
        $previous = static::get_cache();
        if ( ( $previous && $previous['checked_at'] > time() - 6 * HOUR_IN_SECONDS ) || get_transient( 'mainwp_php_security_refresh_attempt' ) ) {
            return;
        }
        set_transient( 'mainwp_php_security_refresh_attempt', 1, 15 * MINUTE_IN_SECONDS );
        $catalog = static::request( array( 'json' => '1' ) );
        if ( ! $catalog ) {
            return;
        }
        $branches = array();
        foreach ( $catalog as $major ) {
            if ( ! is_array( $major ) || ! isset( $major['supported_versions'] ) || ! is_array( $major['supported_versions'] ) ) {
                return;
            }
            foreach ( $major['supported_versions'] as $branch ) {
                if ( ! is_string( $branch ) || ! preg_match( '/^[1-9]\d?\.(?:0|[1-9]\d?)$/D', $branch ) ) {
                    return;
                }
                $branches[ $branch ] = array();
            }
        }
        if ( empty( $branches ) || count( $branches ) > 32 ) {
            return;
        }
        foreach ( $branches as $branch => $unused ) {
            $history = static::request(
                array(
                    'json'    => '1',
                    'version' => $branch,
                    'max'     => 100,
                )
            );
            if ( ! $history || count( $history ) > 100 ) {
                return;
            }
            $latest   = '';
            $security = '';
            foreach ( $history as $version => $release ) {
                if ( ! is_string( $version ) || ! preg_match( '/^' . preg_quote( $branch, '/' ) . '\.(?:0|[1-9]\d{0,2})$/D', $version ) ||
                    ! is_array( $release ) || ! isset( $release['tags'] ) || ! is_array( $release['tags'] ) ||
                    count( array_filter( $release['tags'], 'is_string' ) ) !== count( $release['tags'] ) ) {
                    return;
                }
                if ( '' === $latest || version_compare( $version, $latest, '>' ) ) {
                    $latest = $version;
                }
                if ( in_array( 'security', $release['tags'], true ) && ( '' === $security || version_compare( $version, $security, '>' ) ) ) {
                    $security = $version;
                }
            }
            // A newly supported branch may not have a security-tagged release yet.
            $security = '' !== $security ? $security : $latest;
            if ( $previous && isset( $previous['branches'][ $branch ] ) &&
                version_compare( $security, $previous['branches'][ $branch ]['security'], '<' ) ) {
                return;
            }
            $branches[ $branch ] = array(
                'latest'   => $latest,
                'security' => $security,
            );
        }
        $updated = update_option(
            static::CACHE_KEY,
            array(
                'branches'   => $branches,
                'checked_at' => time(),
            ),
            false
        );
        if ( $updated ) {
            static::refresh_site_results();
        }
    }

    /**
     * Recheck saved site results without fetching anything from Child sites.
     *
     * @return void
     */
    private static function refresh_site_results() {
        $db       = MainWP_DB::instance();
        $websites = $db->query(
            $db->get_sql_websites_for_current_user_by_params(
                array(
                    // This background refresh covers all sites, including staging.
                    'for_manager'   => true,
                    'view'          => 'custom_view',
                    'others_fields' => array( 'phpversion', 'security_stats' ),
                )
            )
        );
        while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
            $stats = json_decode( (string) $website->security_stats, true );
            if ( ! is_array( $stats ) || ! isset( $stats['phpversion_matched'] ) ) {
                continue;
            }
            $updated = static::apply_to_stats( $stats, $website->phpversion );
            /** This filter is documented in ../pages/page-mainwp-security-issues.php */
            $filtered = apply_filters( 'mainwp_security_issues_stats', false, $updated, $website );
            if ( false !== $filtered && is_array( $filtered ) ) {
                $updated = array_merge( $updated, $filtered );
            }
            $issues = count(
                array_filter(
                    $updated,
                    static function ( $value ) {
                        return 'N' === $value;
                    }
                )
            );
            if ( $updated !== $stats ) {
                $db->update_website_option( $website, 'security_stats', wp_json_encode( $updated ) );
            }
            if ( (string) $website->securityIssues !== (string) $issues ) {
                $db->update_website_values( $website->id, array( 'securityIssues' => $issues ) );
            }
        }
    }

    /**
     * Fetch bounded public release JSON without sending Dashboard identifiers.
     *
     * @param array $query Public release query.
     * @return array|false Decoded metadata.
     */
    private static function request( $query ) {
        $response = wp_safe_remote_get(
            add_query_arg( $query, 'https://www.php.net/releases/index.php' ),
            array(
                'timeout'             => 3,
                'redirection'         => 0,
                'limit_response_size' => 1048576,
                'user-agent'          => 'MainWP PHP release check',
                'headers'             => array( 'Accept' => 'application/json' ),
                'cookies'             => array(),
                'sslverify'           => true,
            )
        );
        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return false;
        }
        $data = json_decode( wp_remote_retrieve_body( $response ), true, 16 );
        return is_array( $data ) ? $data : false;
    }
}
