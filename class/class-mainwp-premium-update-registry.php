<?php
/**
 * MainWP Premium Update Registry.
 *
 * Built-in compatibility data for premium plugin & theme updates (MWP-1660).
 *
 * The registry is the default input of the public premium-update filters
 * (mainwp_detect_premium_plugins_update, mainwp_request_update_premium_plugins,
 * mainwp_detect_premium_themes_update, mainwp_request_update_premium_themes).
 * Filter signatures and semantics are unchanged: filters receive flat arrays of
 * exact identifiers, exactly as they always did. Prefix rules are matched
 * internally (formalizing the historical inline 'yith-' substring check) and are
 * not part of the filtered arrays.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Premium_Update_Registry
 *
 * @package MainWP\Dashboard
 */
class MainWP_Premium_Update_Registry { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Option name for the registry kill switch (default on).
     *
     * @var string
     */
    const OPTION_ENABLED = 'mainwp_premium_updates_registry_enabled';

    /**
     * Option name for user-defined custom identifiers.
     *
     * Stored as an array of items: array( 'type' => 'plugin'|'theme', 'id' => string ).
     * Custom entries always get both detect and request behavior.
     *
     * @var string
     */
    const OPTION_CUSTOM = 'mainwp_premium_updates_custom';

    /**
     * Get Class Name.
     *
     * @return string
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Whether the built-in registry is enabled.
     *
     * When disabled, premium-update checks fall back to the exact pre-registry
     * behavior (legacy hardcoded lists + the inline 'yith-' substring check).
     *
     * @return bool
     */
    public static function is_enabled() {
        return 1 === (int) get_option( static::OPTION_ENABLED, 1 );
    }

    /**
     * Built-in registry entries.
     *
     * Fields per entry:
     *  - id      : exact identifier (plugin basename or theme directory slug) or prefix.
     *  - name    : display name used by the settings page when a site-reported name is unavailable.
     *  - type    : 'plugin' | 'theme'.
     *  - match   : 'exact' | 'prefix'.
     *  - detect  : include in update-detection checks.
     *  - request : include in the premium request (wp-admin) update route.
     *
     * Identifiers are stored in canonical case; comparisons are case-insensitive.
     *
     * @return array[] Registry entries.
     */
    public static function get_entries() { // phpcs:ignore -- NOSONAR - long data method.
        static $entries = null;
        if ( null !== $entries ) {
            return $entries;
        }

        $entries = array(
            // Family rule: every YITH premium plugin uses the yith- folder prefix with an init.php
            // main file. Replaces the historical inline strpos( 'yith-' ) detection hack and the
            // single hardcoded YITH request entry.
            array(
                'id'      => 'yith-',
                'name'    => 'YITH WooCommerce plugins',
                'type'    => 'plugin',
                'match'   => 'prefix',
                'detect'  => true,
                'request' => true,
            ),

            // Elegant Themes family.
            array(
                'id'      => 'Divi',
                'name'    => 'Divi',
                'type'    => 'theme',
                'match'   => 'exact',
                'detect'  => true,
                'request' => true,
            ),
            array(
                'id'      => 'Extra',
                'name'    => 'Extra',
                'type'    => 'theme',
                'match'   => 'exact',
                'detect'  => true,
                'request' => true,
            ),
            array(
                'id'      => 'divi-builder/divi-builder.php',
                'name'    => 'Divi Builder',
                'type'    => 'plugin',
                'match'   => 'exact',
                'detect'  => true,
                'request' => true,
            ),
            array(
                'id'      => 'bloom/bloom.php',
                'name'    => 'Bloom',
                'type'    => 'plugin',
                'match'   => 'exact',
                'detect'  => true,
                'request' => true,
            ),

            array(
                'id'      => 'yootheme',
                'name'    => 'YOOtheme Pro',
                'type'    => 'theme',
                'match'   => 'exact',
                'detect'  => true,
                'request' => true,
            ),
            array(
                'id'      => 'bricks',
                'name'    => 'Bricks',
                'type'    => 'theme',
                'match'   => 'exact',
                'detect'  => true,
                'request' => true,
            ),
            array(
                'id'      => 'Avada',
                'name'    => 'Avada',
                'type'    => 'theme',
                'match'   => 'exact',
                'detect'  => true,
                'request' => false,
            ),

            array(
                'id'      => 'js_composer/js_composer.php',
                'name'    => 'WPBakery Page Builder',
                'type'    => 'plugin',
                'match'   => 'exact',
                'detect'  => true,
                'request' => true,
            ),
            array(
                'id'      => 'bb-theme-builder/bb-theme-builder.php',
                'name'    => 'Beaver Themer',
                'type'    => 'plugin',
                'match'   => 'exact',
                'detect'  => true,
                'request' => false,
            ),
            // WooPack (IdeaBox): folder 'woopack' is vendor-confirmed, but the main file name is
            // not independently verified (IdeaBox has folder/file asymmetry precedent), so no
            // entry ships. Add one via the registry intake once a verified identifier surfaces.
            // See MWP-1660.

            // Oxygen. Classic (<= 4.x) and Oxygen 6 share the 'oxygen' folder but use different
            // main files, so a site reports exactly one of the two basenames.
            array(
                'id'      => 'oxygen/functions.php',
                'name'    => 'Oxygen',
                'type'    => 'plugin',
                'match'   => 'exact',
                'detect'  => true,
                'request' => true,
            ),
            // Oxygen 6 main file, verified by probing the vendor's own production site. See MWP-1660.
            array(
                'id'      => 'oxygen/plugin.php',
                'name'    => 'Oxygen 6',
                'type'    => 'plugin',
                'match'   => 'exact',
                'detect'  => true,
                'request' => true,
            ),

            // Carried over from the pre-registry hardcoded detection list (detect-only).
            array(
                'id'      => 'ithemes-security-pro/ithemes-security-pro.php',
                'name'    => 'Solid Security Pro',
                'type'    => 'plugin',
                'match'   => 'exact',
                'detect'  => true,
                'request' => false,
            ),
            array(
                'id'      => 'monarch/monarch.php',
                'name'    => 'Monarch',
                'type'    => 'plugin',
                'match'   => 'exact',
                'detect'  => true,
                'request' => false,
            ),
            array(
                'id'      => 'cornerstone/cornerstone.php',
                'name'    => 'Cornerstone',
                'type'    => 'plugin',
                'match'   => 'exact',
                'detect'  => true,
                'request' => false,
            ),
            // UpdraftPlus Premium ships the identical basename as the free version; the entry is
            // intentionally kept even though it also matches free installs.
            array(
                'id'      => 'updraftplus/updraftplus.php',
                'name'    => 'UpdraftPlus Premium',
                'type'    => 'plugin',
                'match'   => 'exact',
                'detect'  => true,
                'request' => false,
            ),
            array(
                'id'      => 'wp-all-import-pro/wp-all-import-pro.php',
                'name'    => 'WP All Import Pro',
                'type'    => 'plugin',
                'match'   => 'exact',
                'detect'  => true,
                'request' => false,
            ),
            array(
                'id'      => 'bbq-pro/bbq-pro.php',
                'name'    => 'BBQ Pro',
                'type'    => 'plugin',
                'match'   => 'exact',
                'detect'  => true,
                'request' => false,
            ),
            // Audit candidate: SeedProd renamed its pro plugin in later versions. See MWP-1660.
            array(
                'id'      => 'seedprod-coming-soon-pro-5/seedprod-coming-soon-pro-5.php',
                'name'    => 'SeedProd Pro',
                'type'    => 'plugin',
                'match'   => 'exact',
                'detect'  => true,
                'request' => false,
            ),
            array(
                'id'      => 'elementor-pro/elementor-pro.php',
                'name'    => 'Elementor Pro',
                'type'    => 'plugin',
                'match'   => 'exact',
                'detect'  => true,
                'request' => false,
            ),
            array(
                'id'      => 'bbpowerpack/bb-powerpack.php',
                'name'    => 'PowerPack for Beaver Builder',
                'type'    => 'plugin',
                'match'   => 'exact',
                'detect'  => true,
                'request' => false,
            ),
            array(
                'id'      => 'bb-ultimate-addon/bb-ultimate-addon.php',
                'name'    => 'Ultimate Addons for Beaver Builder',
                'type'    => 'plugin',
                'match'   => 'exact',
                'detect'  => true,
                'request' => false,
            ),
            // Audit candidate: WebARX rebranded to Patchstack; the folder likely changed. See MWP-1660.
            array(
                'id'      => 'webarx/webarx.php',
                'name'    => 'WebARX',
                'type'    => 'plugin',
                'match'   => 'exact',
                'detect'  => true,
                'request' => false,
            ),
            // Audit candidate. See MWP-1660.
            array(
                'id'      => 'leco-client-portal/leco-client-portal.php',
                'name'    => 'LECO Client Portal',
                'type'    => 'plugin',
                'match'   => 'exact',
                'detect'  => true,
                'request' => false,
            ),
            // Note: elementor-extras (discontinued 2021) was removed from the carried-over list.
            array(
                'id'      => 'wp-schema-pro/wp-schema-pro.php',
                'name'    => 'Schema Pro',
                'type'    => 'plugin',
                'match'   => 'exact',
                'detect'  => true,
                'request' => false,
            ),
            array(
                'id'      => 'convertpro/convertpro.php',
                'name'    => 'Convert Pro',
                'type'    => 'plugin',
                'match'   => 'exact',
                'detect'  => true,
                'request' => false,
            ),
            array(
                'id'      => 'convertpro-addon/convertpro-addon.php',
                'name'    => 'Convert Pro Addon',
                'type'    => 'plugin',
                'match'   => 'exact',
                'detect'  => true,
                'request' => false,
            ),
            array(
                'id'      => 'astra-addon/astra-addon.php',
                'name'    => 'Astra Pro',
                'type'    => 'plugin',
                'match'   => 'exact',
                'detect'  => true,
                'request' => false,
            ),
            array(
                'id'      => 'astra-portfolio/astra-portfolio.php',
                'name'    => 'Astra Portfolio',
                'type'    => 'plugin',
                'match'   => 'exact',
                'detect'  => true,
                'request' => false,
            ),
            array(
                'id'      => 'astra-pro-sites/astra-pro-sites.php',
                'name'    => 'Astra Premium Starter Templates',
                'type'    => 'plugin',
                'match'   => 'exact',
                'detect'  => true,
                'request' => false,
            ),
            // Audit candidate: Smash Balloon renamed products over time. See MWP-1660.
            array(
                'id'      => 'custom-facebook-feed-pro/custom-facebook-feed.php',
                'name'    => 'Custom Facebook Feed Pro',
                'type'    => 'plugin',
                'match'   => 'exact',
                'detect'  => true,
                'request' => false,
            ),
            array(
                'id'      => 'ultimate-elementor/ultimate-elementor.php',
                'name'    => 'Ultimate Addons for Elementor',
                'type'    => 'plugin',
                'match'   => 'exact',
                'detect'  => true,
                'request' => false,
            ),
            array(
                'id'      => 'gp-premium/gp-premium.php',
                'name'    => 'GP Premium',
                'type'    => 'plugin',
                'match'   => 'exact',
                'detect'  => true,
                'request' => false,
            ),
            array(
                'id'      => 'flying-press/flying-press.php',
                'name'    => 'FlyingPress',
                'type'    => 'plugin',
                'match'   => 'exact',
                'detect'  => true,
                'request' => false,
            ),
            array(
                'id'      => 'wp-rocket/wp-rocket.php',
                'name'    => 'WP Rocket',
                'type'    => 'plugin',
                'match'   => 'exact',
                'detect'  => true,
                'request' => false,
            ),
            array(
                'id'      => 'fluentformpro/fluentformpro.php',
                'name'    => 'Fluent Forms Pro',
                'type'    => 'plugin',
                'match'   => 'exact',
                'detect'  => true,
                'request' => false,
            ),
            array(
                'id'      => 'fluentform-signature/fluentform-signature.php',
                'name'    => 'Fluent Forms Signature',
                'type'    => 'plugin',
                'match'   => 'exact',
                'detect'  => true,
                'request' => false,
            ),
            array(
                'id'      => 'fluentcampaign-pro/fluentcampaign-pro.php',
                'name'    => 'FluentCRM Pro',
                'type'    => 'plugin',
                'match'   => 'exact',
                'detect'  => true,
                'request' => false,
            ),
            array(
                'id'      => 'fluent-support-pro/fluent-support-pro.php',
                'name'    => 'Fluent Support Pro',
                'type'    => 'plugin',
                'match'   => 'exact',
                'detect'  => true,
                'request' => false,
            ),
            array(
                'id'      => 'ninja-tables-pro/ninja-tables-pro.php',
                'name'    => 'Ninja Tables Pro',
                'type'    => 'plugin',
                'match'   => 'exact',
                'detect'  => true,
                'request' => false,
            ),
            array(
                'id'      => 'fluent-booking-pro/fluent-booking-pro.php',
                'name'    => 'FluentBooking Pro',
                'type'    => 'plugin',
                'match'   => 'exact',
                'detect'  => true,
                'request' => false,
            ),
            array(
                'id'      => 'wp-social-ninja-pro/wp-social-ninja-pro.php',
                'name'    => 'WP Social Ninja Pro',
                'type'    => 'plugin',
                'match'   => 'exact',
                'detect'  => true,
                'request' => false,
            ),
            array(
                'id'      => 'wp-payment-form-pro/wp-payment-form-pro.php',
                'name'    => 'Paymattic Pro',
                'type'    => 'plugin',
                'match'   => 'exact',
                'detect'  => true,
                'request' => false,
            ),
        );

        return $entries;
    }

    /**
     * User-defined custom identifiers (settings page).
     *
     * @return array[] Items: array( 'type' => 'plugin'|'theme', 'id' => string ).
     */
    public static function get_custom_entries() {
        $custom = get_option( static::OPTION_CUSTOM, array() );
        if ( ! is_array( $custom ) ) {
            return array();
        }
        $items = array();
        foreach ( $custom as $item ) {
            if ( ! is_array( $item ) || empty( $item['id'] ) || empty( $item['type'] ) ) {
                continue;
            }
            $type = 'theme' === $item['type'] ? 'theme' : 'plugin';
            $id   = sanitize_text_field( $item['id'] );
            if ( '' === $id ) {
                continue;
            }
            $items[] = array(
                'type' => $type,
                'id'   => $id,
            );
        }
        return $items;
    }

    /**
     * Save custom identifiers.
     *
     * @param array $items Items: array( 'type' => 'plugin'|'theme', 'id' => string ).
     */
    public static function save_custom_entries( $items ) {
        $clean = array();
        $seen  = array();
        if ( is_array( $items ) ) {
            foreach ( $items as $item ) {
                if ( ! is_array( $item ) || empty( $item['id'] ) ) {
                    continue;
                }
                $type = isset( $item['type'] ) && 'theme' === $item['type'] ? 'theme' : 'plugin';
                $id   = sanitize_text_field( wp_unslash( $item['id'] ) );
                $id   = trim( $id );
                if ( '' === $id || ! preg_match( '/^[A-Za-z0-9\-_.\/]+$/', $id ) ) {
                    continue;
                }
                $key = $type . '|' . strtolower( $id );
                if ( isset( $seen[ $key ] ) ) {
                    continue;
                }
                $seen[ $key ] = true;
                $clean[]      = array(
                    'type' => $type,
                    'id'   => $id,
                );
            }
        }
        MainWP_Utility::update_option( static::OPTION_CUSTOM, $clean );
    }

    /**
     * Default exact identifiers for one of the four public filters.
     *
     * Prefix rules are not included: filters keep their historical semantics of
     * flat exact-identifier arrays. Custom entries are included (both flags).
     *
     * @param string $type    'plugin' | 'theme'.
     * @param string $purpose 'detect' | 'request'.
     *
     * @return string[] Exact identifiers.
     */
    public static function get_filter_defaults( $type, $purpose ) {
        $ids = array();
        foreach ( static::get_entries() as $entry ) {
            if ( $entry['type'] !== $type || 'exact' !== $entry['match'] ) {
                continue;
            }
            if ( empty( $entry[ 'request' === $purpose ? 'request' : 'detect' ] ) ) {
                continue;
            }
            $ids[] = $entry['id'];
        }
        foreach ( static::get_custom_entries() as $item ) {
            if ( $item['type'] === $type ) {
                $ids[] = $item['id'];
            }
        }
        return array_values( array_unique( $ids ) );
    }

    /**
     * Prefix rules for internal matching.
     *
     * @param string $type    'plugin' | 'theme'.
     * @param string $purpose 'detect' | 'request'.
     *
     * @return string[] Prefixes.
     */
    public static function get_prefixes( $type, $purpose ) {
        $prefixes = array();
        foreach ( static::get_entries() as $entry ) {
            if ( $entry['type'] !== $type || 'prefix' !== $entry['match'] ) {
                continue;
            }
            if ( empty( $entry[ 'request' === $purpose ? 'request' : 'detect' ] ) ) {
                continue;
            }
            $prefixes[] = $entry['id'];
        }
        return $prefixes;
    }

    /**
     * Case-insensitive identifier matching against exact identifiers and prefixes.
     *
     * Registry identifiers are never sent to child sites; matching only decides
     * whether existing premium-update behavior applies to a child-reported slug.
     *
     * @param string   $slug     Child-reported identifier.
     * @param string[] $ids      Exact identifiers (post-filter).
     * @param string[] $prefixes Prefix rules.
     *
     * @return bool
     */
    public static function slug_matches( $slug, $ids, $prefixes ) {
        if ( ! is_string( $slug ) || '' === $slug ) {
            return false;
        }
        foreach ( (array) $ids as $id ) {
            if ( is_string( $id ) && 0 === strcasecmp( $slug, $id ) ) {
                return true;
            }
        }
        foreach ( (array) $prefixes as $prefix ) {
            if ( is_string( $prefix ) && '' !== $prefix && 0 === strncasecmp( $slug, $prefix, strlen( $prefix ) ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Legacy hardcoded detection list (pre-registry), used verbatim when the
     * registry kill switch is off so behavior reverts to the previous release.
     *
     * @return string[]
     */
    public static function get_legacy_detect_plugins() {
        return array(
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
    }

    /**
     * Legacy hardcoded request list (pre-registry), used when the kill switch is off.
     *
     * @return string[]
     */
    public static function get_legacy_request_plugins() {
        return array(
            'yith-woocommerce-request-a-quote-premium/init.php',
        );
    }
}
