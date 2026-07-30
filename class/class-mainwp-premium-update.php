<?php
/**
 * MainWP Premium Update
 *
 * MainWP Premium Update functions.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.DB.RestrictedFunctions, WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors -- Using cURL functions.

/**
 * Class MainWP_Premium_Update
 *
 * @package MainWP\Dashboard
 *
 * Check for premium plugin updates.
 */
class MainWP_Premium_Update { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Raw response of the most recent premium request-update GET, if any.
     *
     * Captured so fetch_url_authed() can report the child's real result instead
     * of fabricating success (MWP-1660). Reset per maybe_request_premium_updates() call.
     *
     * @var array|\WP_Error|null
     */
    private static $last_request_response = null;

    /**
     * Method get_class_name()
     *
     * Get Class Name.
     *
     * @return object
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Method check_premium_updates()
     *
     * Check for Premium Plugin updates.
     *
     * @param array $updates Array of installed items reported by the child site.
     * @param mixed $type Type of update.
     *
     * @return boolean true|false.
     */
    public static function check_premium_updates( $updates, $type ) { // phpcs:ignore -- NOSONAR - complex.

        if ( ! is_array( $updates ) || empty( $updates ) ) {
            return false;
        }

        if ( ! MainWP_Premium_Update_Registry::is_enabled() ) {
            return static::legacy_check_premium_updates( $updates, $type );
        }

        if ( 'plugin' === $type ) {

            $premiums = MainWP_Premium_Update_Registry::get_filter_defaults( 'plugin', 'detect' );

            /**
             * Filter: mainwp_detect_premiums_updates
             *
             * Use mainwp_detect_premium_plugins_update instead.
             *
             * @deprecated
             */
            $premiums = apply_filters( 'mainwp_detect_premiums_updates', $premiums );

            /**
             * Filter: mainwp_detect_premium_plugins_update
             *
             * Filters supported premium plugins to fix compatiblity issues with detecting premium plugin updates.
             *
             * @since Unknown
             */
            $premiums = apply_filters( 'mainwp_detect_premium_plugins_update', $premiums );

            $prefixes = MainWP_Premium_Update_Registry::get_prefixes( 'plugin', 'detect' );

            if ( ( is_array( $premiums ) && ! empty( $premiums ) ) || ! empty( $prefixes ) ) {
                foreach ( $updates as $info ) {
                    if ( isset( $info['slug'] ) && MainWP_Premium_Update_Registry::slug_matches( $info['slug'], is_array( $premiums ) ? $premiums : array(), $prefixes ) ) {
                        return true;
                    }
                }
            }
        } elseif ( 'theme' === $type ) {

            $premiums = MainWP_Premium_Update_Registry::get_filter_defaults( 'theme', 'detect' );

            /**
             * Filter: mainwp_detect_premium_themes_update
             *
             * Filters supported premium themes to fix compatiblity issues with detecting premium theme updates.
             *
             * @since Unknown
             */
            $premiums = apply_filters( 'mainwp_detect_premium_themes_update', $premiums );

            $prefixes = MainWP_Premium_Update_Registry::get_prefixes( 'theme', 'detect' );

            if ( ( is_array( $premiums ) && ! empty( $premiums ) ) || ! empty( $prefixes ) ) {
                foreach ( $updates as $info ) {
                    if ( ! isset( $info['slug'] ) ) {
                        continue;
                    }
                    // A theme updater only runs while its theme (or a child of it) is active,
                    // so the extra checks are limited to the active theme and its parent.
                    // Older child plugins that do not report activity flags keep the
                    // previous match-any-installed behavior.
                    if ( ( isset( $info['active'] ) || isset( $info['parent_active'] ) )
                        && empty( $info['active'] ) && empty( $info['parent_active'] ) ) {
                        continue;
                    }
                    if ( MainWP_Premium_Update_Registry::slug_matches( $info['slug'], is_array( $premiums ) ? $premiums : array(), $prefixes ) ) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Pre-registry detection behavior, used verbatim when the registry kill switch is off.
     *
     * @param array $updates Array of installed items reported by the child site.
     * @param mixed $type Type of update.
     *
     * @return boolean true|false.
     */
    private static function legacy_check_premium_updates( $updates, $type ) { // phpcs:ignore -- NOSONAR - complex.
        if ( 'plugin' === $type ) {

            $premiums = MainWP_Premium_Update_Registry::get_legacy_detect_plugins();

            /** This filter is documented in class/class-mainwp-premium-update.php */
            $premiums = apply_filters( 'mainwp_detect_premiums_updates', $premiums );

            /** This filter is documented in class/class-mainwp-premium-update.php */
            $premiums = apply_filters( 'mainwp_detect_premium_plugins_update', $premiums );

            if ( is_array( $premiums ) && ! empty( $premiums ) ) {
                foreach ( $updates as $info ) {
                    if ( isset( $info['slug'] ) && ( in_array( $info['slug'], $premiums ) || false !== strpos( $info['slug'], 'yith-' ) ) ) {
                        return true;
                    }
                }
            }
        } elseif ( 'theme' === $type ) {

            $premiums = array();

            /** This filter is documented in class/class-mainwp-premium-update.php */
            $premiums = apply_filters( 'mainwp_detect_premium_themes_update', $premiums );

            if ( is_array( $premiums ) && ! empty( $premiums ) ) {
                foreach ( $updates as $info ) {
                    if ( isset( $info['slug'] ) && in_array( $info['slug'], $premiums ) ) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Method maybe_request_premium_updates()
     *
     * @param mixed $website Child Site info.
     * @param mixed $what stats|upgradeplugintheme What function to perform.
     * @param mixed $params plugin|theme Update Type.
     *
     * @return mixed $request_update
     */
    public static function maybe_request_premium_updates( $website, $what, $params ) { // phpcs:ignore -- NOSONAR -Current complexity is the only way to achieve desired results, pull request solutions appreciated.
        self::$last_request_response = null;

        $request_update = false;
        if ( 'stats' === $what || ( 'upgradeplugintheme' === $what && isset( $params['type'] ) ) ) {

            $update_type = '';

            $check_premi_plugins = array();
            $check_premi_themes  = array();

            if ( 'stats' === $what ) {
                if ( '' !== $website->plugins ) {
                    $check_premi_plugins = json_decode( $website->plugins, 1 );
                }
                if ( '' !== $website->themes ) {
                    $check_premi_themes = json_decode( $website->themes, 1 );
                }
            } elseif ( 'upgradeplugintheme' === $what ) {
                $update_type = ( isset( $params['type'] ) ) ? $params['type'] : '';
                if ( 'plugin' === $update_type ) {
                    if ( '' !== $website->plugins ) {
                        $check_premi_plugins = json_decode( $website->plugins, 1 );
                    }
                } elseif ( 'theme' === $update_type ) {
                    if ( '' !== $website->themes ) {
                        $check_premi_themes = json_decode( $website->themes, 1 );
                    }
                }
            }

            if ( static::check_premium_updates( $check_premi_plugins, 'plugin' ) ) {
                static::try_to_detect_premiums_update( $website, 'plugin' );
            }

            if ( static::check_premium_updates( $check_premi_themes, 'theme' ) ) {
                static::try_to_detect_premiums_update( $website, 'theme' );
            }

            if ( 'upgradeplugintheme' === $what && ( 'plugin' === $update_type || 'theme' === $update_type ) && static::check_request_update_premium( $params['list'], $update_type ) ) {
                static::request_premiums_update( $website, $update_type, $params['list'] );
                $request_update = true;
            }
        }

        return $request_update;
    }

    /**
     * Method check_request_update_premium()
     *
     * Check if any updates are on the premiums list.
     *
     * @param array  $list_items List of updates.
     * @param string $type Type of update. plugin|theme.
     *
     * @return bool true|false.
     */
    public static function check_request_update_premium( $list_items, $type ) { // phpcs:ignore -- NOSONAR - complex.

        $updates = explode( ',', $list_items );

        if ( ! is_array( $updates ) || empty( $updates ) ) {
            return false;
        }

        if ( 1 < count( $updates ) ) {
            return false;
        }

        if ( ! MainWP_Premium_Update_Registry::is_enabled() ) {
            return static::legacy_check_request_update_premium( $updates, $type );
        }

        if ( 'plugin' === $type ) {

            $update_premiums = MainWP_Premium_Update_Registry::get_filter_defaults( 'plugin', 'request' );

            /**
             * Filter: mainwp_request_update_premium_plugins
             *
             * Filters supported premium plugins to fix compatibility problmes with updating premium plugins.
             *
             * @since Unknown
             */
            $update_premiums = apply_filters( 'mainwp_request_update_premium_plugins', $update_premiums );

            $prefixes = MainWP_Premium_Update_Registry::get_prefixes( 'plugin', 'request' );

            foreach ( $updates as $slug ) {
                if ( ! empty( $slug ) && MainWP_Premium_Update_Registry::slug_matches( $slug, is_array( $update_premiums ) ? $update_premiums : array(), $prefixes ) ) {
                    return true;
                }
            }
        } elseif ( 'theme' === $type ) {

            $update_premiums = MainWP_Premium_Update_Registry::get_filter_defaults( 'theme', 'request' );

            /**
             * Filter: mainwp_request_update_premium_themes
             *
             * Filters supported premium themes to fix compatibility problmes with updating premium themes.
             *
             * @since Unknown
             */
            $update_premiums = apply_filters( 'mainwp_request_update_premium_themes', $update_premiums );

            $prefixes = MainWP_Premium_Update_Registry::get_prefixes( 'theme', 'request' );

            foreach ( $updates as $slug ) {
                if ( ! empty( $slug ) && MainWP_Premium_Update_Registry::slug_matches( $slug, is_array( $update_premiums ) ? $update_premiums : array(), $prefixes ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Pre-registry request-route behavior, used verbatim when the registry kill switch is off.
     *
     * @param array  $updates Exploded list of updates.
     * @param string $type Type of update. plugin|theme.
     *
     * @return bool true|false.
     */
    private static function legacy_check_request_update_premium( $updates, $type ) { // phpcs:ignore -- NOSONAR - complex.
        if ( 'plugin' === $type ) {

            $update_premiums = MainWP_Premium_Update_Registry::get_legacy_request_plugins();

            /** This filter is documented in class/class-mainwp-premium-update.php */
            $update_premiums = apply_filters( 'mainwp_request_update_premium_plugins', $update_premiums );

            if ( is_array( $update_premiums ) && ! empty( $update_premiums ) ) {
                foreach ( $updates as $slug ) {
                    if ( ! empty( $slug ) && in_array( $slug, $update_premiums ) ) {
                        return true;
                    }
                }
            }
        } elseif ( 'theme' === $type ) {

            $update_premiums = array();

            /** This filter is documented in class/class-mainwp-premium-update.php */
            $update_premiums = apply_filters( 'mainwp_request_update_premium_themes', $update_premiums );
            if ( is_array( $update_premiums ) && ! empty( $update_premiums ) ) {
                foreach ( $updates as $slug ) {
                    if ( ! empty( $slug ) && in_array( $slug, $update_premiums ) ) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Method redirect_request_site()
     *
     * Redirect to requested Site.
     *
     * @param mixed $website Child Site.
     * @param mixed $where_url page to redirerct to.
     *
     * @uses \MainWP\Dashboard\MainWP_Connect::get_get_data_authed()
     * @uses \MainWP\Dashboard\MainWP_Logger::debug()
     * @uses \MainWP\Dashboard\MainWP_System::$version
     */
    public static function redirect_request_site( $website, $where_url ) {

        $request_url = MainWP_Connect::get_get_data_authed( $website, $where_url );

        $agent = 'Mozilla/5.0 (compatible; MainWP/' . MainWP_System::$version . '; +http://mainwp.com)';
        $args  = array(
            'timeout'     => 25,
            'httpversion' => '1.1',
            'User-Agent'  => $agent,
            'sslverify'   => static::get_ssl_verify( $website ),
        );

        if ( ! empty( $website->http_user ) && ! empty( $website->http_pass ) ) {
            // MWP-1548: decrypt before constructing the Basic Auth header.
            $http_user_plain = MainWP_Credential_Storage::decrypt_credential( $website->http_user );
            $http_pass_plain = MainWP_Credential_Storage::decrypt_credential( $website->http_pass );
            if ( ! empty( $http_user_plain ) && ! empty( $http_pass_plain ) ) {
                $args['headers'] = array(
                    'Authorization' => 'Basic ' . base64_encode( $http_user_plain . ':' . stripslashes( $http_pass_plain ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for http encoding compatible.
                );
            }
        }

        $ssl_version    = isset( $website->ssl_version ) ? (int) $website->ssl_version : 0;
        $force_use_ipv4 = static::get_force_use_ipv4( $website );

        // MWP-1660: honor the site's connection profile, mirroring fetch_url().
        $curl_extra = function ( $handle ) use ( $ssl_version, $force_use_ipv4 ) {
            if ( ! empty( $ssl_version ) ) {
                curl_setopt( $handle, CURLOPT_SSLVERSION, $ssl_version );
            }
            if ( $force_use_ipv4 && defined( 'CURLOPT_IPRESOLVE' ) && defined( 'CURL_IPRESOLVE_V4' ) ) {
                curl_setopt( $handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 );
            }
        };

        add_action( 'http_api_curl', $curl_extra );
        try {
            MainWP_Logger::instance()->debug( ' :: tryRequest :: [website=' . $website->url . ']' );
            $response = wp_remote_get( $request_url, $args );
        } finally {
            remove_action( 'http_api_curl', $curl_extra );
        }

        static::log_request_outcome( $website, $where_url, $response );

        return $response;
    }

    /**
     * Resolve the sslverify argument for a website, mirroring fetch_url() semantics.
     *
     * @param mixed $website Child Site.
     *
     * @return bool
     */
    private static function get_ssl_verify( $website ) {
        $verify = isset( $website->verify_certificate ) ? (int) $website->verify_certificate : 2;
        if ( 1 === $verify ) {
            return true;
        }
        if ( 0 === $verify ) {
            return false;
        }
        return ( false === get_option( 'mainwp_sslVerifyCertificate' ) ) || ( 1 === (int) get_option( 'mainwp_sslVerifyCertificate' ) );
    }

    /**
     * Resolve the force-IPv4 flag for a website, mirroring fetch_url() semantics.
     *
     * @param mixed $website Child Site.
     *
     * @return bool
     */
    private static function get_force_use_ipv4( $website ) {
        $force = isset( $website->force_use_ipv4 ) && null !== $website->force_use_ipv4 ? (int) $website->force_use_ipv4 : null;
        if ( 1 === $force ) {
            return true;
        }
        if ( null === $force || 2 === $force ) {
            return 1 === (int) get_option( 'mainwp_forceUseIPv4' );
        }
        return false;
    }

    /**
     * Log the outcome of a premium detect/request GET and record failures per site.
     *
     * These requests previously discarded their responses entirely, leaving
     * support blind to sites where the premium checks silently fail (MWP-1660).
     *
     * @param mixed                 $website Child Site.
     * @param string                $where_url Requested wp-admin location.
     * @param array|\WP_Error|mixed $response HTTP response.
     */
    private static function log_request_outcome( $website, $where_url, $response ) {
        $error = '';
        if ( is_wp_error( $response ) ) {
            $error = $response->get_error_message();
        } else {
            $code = (int) wp_remote_retrieve_response_code( $response );
            if ( $code < 200 || $code >= 400 ) {
                $error = 'HTTP ' . $code;
            }
        }

        if ( '' === $error ) {
            MainWP_Logger::instance()->debug_for_website( $website, 'premium_update', '[where=' . $where_url . '] :: ok' );
            return;
        }

        MainWP_Logger::instance()->warning_for_website( $website, 'premium_update', '[where=' . $where_url . '] :: ' . $error, false );

        $count = (int) MainWP_DB::instance()->get_website_option( $website, 'premium_updates_error_count' );
        MainWP_DB::instance()->update_website_option( $website, 'premium_updates_error_count', (string) ( $count + 1 ) );
        MainWP_DB::instance()->update_website_option(
            $website,
            'premium_updates_last_error',
            wp_json_encode(
                array(
                    'time'  => time(),
                    'where' => $where_url,
                    'error' => $error,
                )
            )
        );
    }

    /**
     * Method request_premiums_update()
     *
     * Request to update plugin or theme.
     *
     * @param mixed $website Child Site to update.
     * @param mixed $type Type of update, plugin|theme.
     * @param mixed $list_items list of plugins & themes installed.
     *
     * @return mixed null|true.
     */
    public static function request_premiums_update( $website, $type, $list_items ) {
        if ( 'plugin' === $type ) {
            $where_url = 'plugins.php?_request_update_premiums_type=plugin&list=' . $list_items;
        } elseif ( 'theme' === $type ) {
            $where_url = 'update-core.php?_request_update_premiums_type=theme&list=' . $list_items;
        } else {
            return null;
        }
        self::$last_request_response = static::redirect_request_site( $website, $where_url );
        return true;
    }

    /**
     * Parse the child's `<mainwp>` result envelope out of an HTML response body.
     *
     * The premium request route runs the regular upgradeplugintheme callable
     * mid-render of a wp-admin page; the callable terminates via
     * MainWP_Helper::write(), so its result envelope is embedded in the page
     * output. Returns null when no valid envelope is present (e.g. timeout).
     *
     * @param string $body Response body.
     *
     * @return array|null Decoded information array or null.
     */
    public static function parse_mainwp_envelope( $body ) {
        if ( ! is_string( $body ) || '' === $body ) {
            return null;
        }
        if ( ! preg_match( '/<mainwp>(.*)<\/mainwp>/', $body, $results ) ) {
            return null;
        }
        $information = MainWP_System_Utility::get_child_response( base64_decode( $results[1] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- base64_encode used for backwards compatibility.
        return is_array( $information ) ? $information : null;
    }

    /**
     * Get the parsed result of the most recent premium request-update GET.
     *
     * Used by fetch_url_authed() to report the child's real result. Returns null
     * when the request timed out or produced no parsable envelope; callers keep
     * the previous optimistic behavior in that case (MWP-1660).
     *
     * @return array|null
     */
    public static function get_last_parsed_response() {
        if ( null === self::$last_request_response || is_wp_error( self::$last_request_response ) ) {
            return null;
        }
        return static::parse_mainwp_envelope( wp_remote_retrieve_body( self::$last_request_response ) );
    }

    /**
     * Method try_to_detect_premiums_update()
     *
     * Try to detect if pugin and themes are premium.
     *
     * @param mixed $website Child Site.
     * @param mixed $type Type of update, plugin|theme.
     *
     * @return mixed false|static::redirect_request_site()
     */
    public static function try_to_detect_premiums_update( $website, $type ) {
        if ( 'plugin' === $type ) {
            $where_url = 'plugins.php?_detect_plugins_updates=yes';
        } elseif ( 'theme' === $type ) {
            $where_url = 'update-core.php?_detect_themes_updates=yes';
        } else {
            return false;
        }
        static::redirect_request_site( $website, $where_url );
    }
}
