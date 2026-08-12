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
     * @param array $updates Array of updates.
     * @param mixed $type Type of update.
     *
     * @return boolean true|false.
     */
    public static function check_premium_updates( $updates, $type ) { // phpcs:ignore -- NOSONAR - complex.

        if ( ! is_array( $updates ) || empty( $updates ) ) {
            return false;
        }

        if ( 'plugin' === $type ) {

            $premiums = array(
                'ithemes-security-pro/ithemes-security-pro.php',
                'monarch/monarch.php',
                'cornerstone/cornerstone.php',
                'updraftplus/updraftplus.php',
                'wp-all-import-pro/wp-all-import-pro.php',
                'bbq-pro/bbq-pro.php',
                'seedprod-coming-soon-pro-5/seedprod-coming-soon-pro-5.php',
                'oxygen/functions.php',
                'elementor-pro/elementor-pro.php',
                'bbpowerpack/bb-powerpack.php',
                'bb-ultimate-addon/bb-ultimate-addon.php',
                'webarx/webarx.php',
                'leco-client-portal/leco-client-portal.php',
                'elementor-extras/elementor-extras.php',
                'wp-schema-pro/wp-schema-pro.php',
                'convertpro/convertpro.php',
                'astra-addon/astra-addon.php',
                'astra-portfolio/astra-portfolio.php',
                'astra-pro-sites/astra-pro-sites.php',
                'custom-facebook-feed-pro/custom-facebook-feed.php',
                'convertpro/convertpro.php',
                'convertpro-addon/convertpro-addon.php',
                'wp-schema-pro/wp-schema-pro.php',
                'ultimate-elementor/ultimate-elementor.php',
                'gp-premium/gp-premium.php',
                'flying-press/flying-press.php',
                'wp-rocket/wp-rocket.php',
                'fluentformpro/fluentformpro.php',
                'fluentform-signature/fluentform-signature.php',
                'fluentcampaign-pro/fluentcampaign-pro.php',
                'fluent-support-pro/fluent-support-pro.php',
                'ninja-tables-pro/ninja-tables-pro.php',
                'fluent-booking-pro/fluent-booking-pro.php',
                'wp-social-ninja-pro/wp-social-ninja-pro.php',
                'wp-payment-form-pro/wp-payment-form-pro.php',
            );

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

            if ( is_array( $premiums ) && ! empty( $premiums ) ) {
                foreach ( $updates as $info ) {
                    // Added support for a list of updates.
                    if ( ( is_array( $info ) && isset( $info['slug'] ) && ( in_array( $info['slug'], $premiums ) || false !== strpos( $info['slug'], 'yith-' ) ) ) || ( is_string( $info ) && in_array( $info, $premiums, true ) ) ) {
                        return true;
                    }
                }
            }
        } elseif ( 'theme' === $type ) {

            $premiums = array();

            /**
             * Filter: mainwp_detect_premium_themes_update
             *
             * Filters supported premium themes to fix compatiblity issues with detecting premium theme updates.
             *
             * @since Unknown
             */
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
     * @param mixed $output Output result.
     *
     * @return mixed $request_update
     */
    public static function maybe_request_premium_updates( $website, $what, $params, &$output ) { // phpcs:ignore -- NOSONAR -Current complexity is the only way to achieve desired results, pull request solutions appreciated.
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

            if ( 'upgradeplugintheme' === $what && ( 'plugin' === $update_type || 'theme' === $update_type ) && ( static::check_request_update_premium( $params['list'], $update_type ) || static::check_premium_updates( explode( ',', $params['list'] ), $update_type ) ) ) {
                $output = static::request_premiums_update( $website, $update_type, $params['list'] );
                return true;
            }
        }

        return false;
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

        if ( 3 < count( $updates ) ) {
            return false;
        }

        if ( 'plugin' === $type ) {

            $update_premiums = array(
                'yith-woocommerce-request-a-quote-premium/init.php',
            );

            /**
             * Filter: mainwp_request_update_premium_plugins
             *
             * Filters supported premium plugins to fix compatibility problmes with updating premium plugins.
             *
             * @since Unknown
             */
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

            /**
             * Filter: mainwp_request_update_premium_themes
             *
             * Filters supported premium themes to fix compatibility problmes with updating premium themes.
             *
             * @since Unknown
             */
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
     * Deprecated, see function handle_premium_update_actions().
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

        MainWP_Logger::instance()->debug( ' :: tryRequest :: [website=' . $website->url . ']' );
        return wp_remote_get( $request_url, $args );
    }


    /**
     * Method handle_premium_update_actions()
     *
     * Redirect to requested Site.
     *
     * @since 6.2
     *
     * @param mixed $website Child Site.
     * @param array $params Other params.
     *
     * @return mixed false|array information.
     */
    public static function handle_premium_update_actions( $website, $params = array() ) {
        if ( ! is_array( $params ) ) {
            return false;
        }
        MainWP_Logger::instance()->debug( 'Request premium update :: [siteid=' . $website->id . '] :: [type=' . $params['premium_type'] . '] :: [perform=' . $params['premium_perform'] . ']' );
        try {
            return MainWP_Connect::fetch_url_authed( $website, 'process_premium_updates', $params, false, false, true, null, true );
        } catch ( \Exception $e ) {
            // Just ignore.
            $err = $e->getMessage(); //phpcs:ignore -- NOSONAR -for debug.
        }
        return false;
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
     * @return mixed
     */
    public static function request_premiums_update( $website, $type, $list_items ) {
        if ( ! in_array( $type, array( 'plugin', 'theme' ) ) ) {
            return false;
        }
        $params = array(
            'premium_type'    => $type,
            'premium_perform' => 'premium_update',
            'list'            => $list_items,
        );
        return static::handle_premium_update_actions( $website, $params );
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

        if ( ! in_array( $type, array( 'plugin', 'theme' ), true ) ) {
            return null;
        }
        $params = array(
            'premium_type'    => $type,
            'premium_perform' => 'detect_update',
        );
        static::handle_premium_update_actions( $website, $params );
    }
}
