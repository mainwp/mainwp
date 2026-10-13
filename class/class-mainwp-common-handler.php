<?php
/**
 * MainWP Common Handler
 *
 * This class handles the common functions.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Common_Handler
 *
 * @package MainWP\Dashboard
 */
class MainWP_Common_Handler { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Protected static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    private static $instance = null;

    /**
     * Method instance()
     *
     * Create public static instance.
     *
     * @static
     * @return static::$instance
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Method sites_available_updates_count()
     *
     * Returns the number of available udpates for sites.
     *
     * @return mixed $response An object that contains the return data and status of the API request.
     */
    public function sites_available_updates_count() {  // phpcs:ignore -- NOSONAR - complex function.

        $cache_group = MainWP_Cache_Helper::CGR_UPDATES;

        // Suffix changed with the shared ignore tally so a count cached before
        // ignore writes invalidated this group is not served for the rest of the TTL.
        $cache_key = MainWP_Cache_Helper::get_cache_key( 'updates_count_ignored', $cache_group );

        $cache_values = MainWP_Cache_Helper::instance()->get_cache(
            $cache_key,
            $cache_group
        );

        if ( is_array( $cache_values ) ) {
            return $cache_values;
        }

        $is_staging = 'no';

        $db_updater_count             = false;
        $total_plugin_db_upgrades     = 0;
        $supported_db_updater_plugins = array();

        $get_fields    = array( 'premium_upgrades', 'favi_icon', 'ignored_wp_upgrades', 'ignored_trans_updates' );
        $custom_fields = apply_filters( 'mainwp_available_updates_count_custom_fields_data', array(), 'updates_count' );

        if ( is_array( $custom_fields ) && 1 === count( $custom_fields ) && in_array( 'plugin_db_upgrades', $custom_fields ) ) {
            $get_fields                   = array_merge( $get_fields, $custom_fields );
            $db_updater_count             = true;
            $supported_db_updater_plugins = apply_filters( 'mainwp_database_updater_supported_plugins', array() );
        }

        $sql = MainWP_DB::instance()->get_sql_websites_for_current_user( false, null, 'wp.url', false, false, null, false, $get_fields, $is_staging, array( 'connected' => 'yes' ) );

        $userExtension = MainWP_DB_Common::instance()->get_user_extension();

        $decodedIgnoredCores = ! empty( $userExtension->ignored_wp_upgrades ) ? json_decode( $userExtension->ignored_wp_upgrades, true ) : array();
        if ( ! is_array( $decodedIgnoredCores ) ) {
            $decodedIgnoredCores = array();
        }

        $websites = MainWP_DB::instance()->query( $sql );

        $mainwp_show_language_updates = get_option( 'mainwp_show_language_updates', 1 );

        $total_wp_upgrades          = 0;
        $total_plugin_upgrades      = 0;
        $total_translation_upgrades = 0;
        $total_theme_upgrades       = 0;

        MainWP_DB::data_seek( $websites, 0 );

        while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
            $decodedPremiumUpgrades = MainWP_DB::instance()->get_website_option( $website, 'premium_upgrades' );
            if ( empty( $decodedPremiumUpgrades ) ) {
                $decodedPremiumUpgrades = array();
            } else {
                $decodedPremiumUpgrades = json_decode( $decodedPremiumUpgrades, true );
            }

            $tally = self::tally_site_available_updates( $website, $userExtension, $decodedIgnoredCores, $decodedPremiumUpgrades );

            $total_wp_upgrades          += $tally['wp'];
            $total_plugin_upgrades      += $tally['plugins'];
            $total_translation_upgrades += $tally['translations'];
            $total_theme_upgrades       += $tally['themes'];

            if ( $db_updater_count ) {

                $plugin_db_upgrades = ! empty( $website->plugin_db_upgrades ) ? json_decode( $website->plugin_db_upgrades, true ) : array();
                if ( $website->is_ignorePluginUpdates ) {
                    $plugin_db_upgrades = array();
                }

                if ( is_array( $plugin_db_upgrades ) && ! $website->is_ignorePluginUpdates ) {
                    $_ignored_plugins = ! empty( $website->ignored_plugins ) ? json_decode( $website->ignored_plugins, true ) : array();
                    if ( is_array( $_ignored_plugins ) ) {
                        $plugin_db_upgrades = $this->get_not_ignored_updates_plugins_db( $plugin_db_upgrades, $_ignored_plugins );
                    }

                    $_ignored_plugins = ! empty( $userExtension->ignored_plugins ) ? json_decode( $userExtension->ignored_plugins, true ) : array();
                    if ( is_array( $_ignored_plugins ) ) {
                        $plugin_db_upgrades = $this->get_not_ignored_updates_plugins_db( $plugin_db_upgrades, $_ignored_plugins );
                    }

                    // supported the WC plugin.
                    if ( is_array( $plugin_db_upgrades ) ) {
                        foreach ( $supported_db_updater_plugins as $supp_slug ) {
                            if ( isset( $plugin_db_upgrades[ $supp_slug ] ) ) {
                                ++$total_plugin_db_upgrades;
                            }
                        }
                    }
                }
            }
        }

        // WP Upgrades part.
        $total_upgrades = $total_wp_upgrades + $total_plugin_upgrades + $total_theme_upgrades;

        // to fix incorrect total updates.
        if ( $mainwp_show_language_updates ) {
            $total_upgrades += $total_translation_upgrades;
            $data            = array(
                'total'        => $total_upgrades,
                'wp'           => $total_wp_upgrades,
                'plugins'      => $total_plugin_upgrades,
                'themes'       => $total_theme_upgrades,
                'translations' => $total_translation_upgrades,
            );
        } else {
            $data = array(
                'total'        => $total_upgrades,
                'wp'           => $total_wp_upgrades,
                'plugins'      => $total_plugin_upgrades,
                'themes'       => $total_theme_upgrades,
                'translations' => 0,
            );
        }

        if ( $db_updater_count ) {
            $data['db_updater_count'] = $total_plugin_db_upgrades;
            $data['total']           += $total_plugin_db_upgrades;
        }
        MainWP_DB::free_result( $websites );

        // set cache.
        MainWP_Cache_Helper::add_cache( $cache_key, $cache_group, $data );

        return $data;
    }


    /**
     * Count one site's available updates the same way the Available Updates tables do.
     *
     * Per-site ignore toggles, per-version core ignores, and per-site or global
     * plugin/theme ignores are applied before the counts are returned. The filtered
     * lists are what the per-item update views aggregate.
     *
     * @param object $website              Child site row.
     * @param object $user_extension       Current user extension row.
     * @param array  $global_ignored_cores Decoded global ignored core versions.
     * @param array  $premium_upgrades     Decoded premium upgrades for the site.
     *
     * @return array {
     *     @type int   $wp                    Core updates, 0 or 1.
     *     @type int   $plugins               Plugin updates after ignore rules.
     *     @type int   $themes                Theme updates after ignore rules.
     *     @type int   $translations          Translation updates after the site toggle.
     *     @type array $plugin_upgrades       Filtered plugin upgrade list.
     *     @type array $theme_upgrades        Filtered theme upgrade list.
     *     @type array $translation_upgrades  Filtered translation upgrade list.
     * }
     */
    public static function tally_site_available_updates( $website, $user_extension, $global_ignored_cores, $premium_upgrades ) { // phpcs:ignore -- NOSONAR - shared with the updates page tally.

        if ( ! is_array( $global_ignored_cores ) ) {
            $global_ignored_cores = array();
        }
        if ( ! is_array( $premium_upgrades ) ) {
            $premium_upgrades = array();
        }

        $wp_upgrades = array();
        if ( ! empty( $website->wp_upgrades ) ) {
            $decoded_wp = json_decode( $website->wp_upgrades, true );
            if ( is_array( $decoded_wp ) ) {
                $wp_upgrades = $decoded_wp;
            }
        }

        $ignored_core_upgrades = array();
        if ( ! empty( $website->ignored_wp_upgrades ) ) {
            $decoded_ignored_cores = json_decode( $website->ignored_wp_upgrades, true );
            if ( is_array( $decoded_ignored_cores ) ) {
                $ignored_core_upgrades = $decoded_ignored_cores;
            }
        }

        $common = MainWP_Common_Functions::instance();
        if ( $website->is_ignoreCoreUpdates || $common->is_ignored_updates( $wp_upgrades, $ignored_core_upgrades, 'core' ) || $common->is_ignored_updates( $wp_upgrades, $global_ignored_cores, 'core' ) ) {
            $wp_upgrades = array();
        }

        $wp_count = 0;
        if ( ! empty( $wp_upgrades ) ) {
            $wp_count = 1;
        }

        $translation_upgrades = json_decode( $website->translation_upgrades, true );
        if ( ! empty( $website->ignored_trans_updates ) ) {
            $translation_upgrades = array();
        }

        $plugin_upgrades = json_decode( $website->plugin_upgrades, true );
        if ( $website->is_ignorePluginUpdates ) {
            $plugin_upgrades = array();
        }

        $theme_upgrades = json_decode( $website->theme_upgrades, true );
        if ( $website->is_ignoreThemeUpdates ) {
            $theme_upgrades = array();
        }

        foreach ( $premium_upgrades as $crr_slug => $premium_upgrade ) {
            $premium_upgrade['premium'] = true;

            if ( 'plugin' === $premium_upgrade['type'] ) {
                if ( ! is_array( $plugin_upgrades ) ) {
                    $plugin_upgrades = array();
                }
                if ( ! $website->is_ignorePluginUpdates ) {
                    $premium_upgrade = array_filter( $premium_upgrade );
                    if ( ! isset( $plugin_upgrades[ $crr_slug ] ) ) {
                        continue;
                    }
                    $plugin_upgrades[ $crr_slug ] = array_merge( $plugin_upgrades[ $crr_slug ], $premium_upgrade );
                }
            } elseif ( 'theme' === $premium_upgrade['type'] ) {
                if ( ! is_array( $theme_upgrades ) ) {
                    $theme_upgrades = array();
                }
                if ( ! $website->is_ignoreThemeUpdates ) {
                    $theme_upgrades[ $crr_slug ] = $premium_upgrade;
                }
            }
        }

        $translation_count = 0;
        if ( is_array( $translation_upgrades ) ) {
            $translation_count = count( $translation_upgrades );
        } else {
            $translation_upgrades = array();
        }

        $plugin_count = 0;
        if ( is_array( $plugin_upgrades ) && ! $website->is_ignorePluginUpdates ) {
            $ignored_plugins = json_decode( $website->ignored_plugins, true );
            if ( is_array( $ignored_plugins ) ) {
                $plugin_upgrades = $common->get_not_ignored_updates_themesplugins( $plugin_upgrades, $ignored_plugins );
            }

            $ignored_plugins = json_decode( $user_extension->ignored_plugins, true );
            if ( is_array( $ignored_plugins ) ) {
                $plugin_upgrades = $common->get_not_ignored_updates_themesplugins( $plugin_upgrades, $ignored_plugins );
            }
            $plugin_count = count( $plugin_upgrades );
        }
        if ( ! is_array( $plugin_upgrades ) ) {
            $plugin_upgrades = array();
        }

        $theme_count = 0;
        if ( is_array( $theme_upgrades ) ) {
            $ignored_themes = json_decode( $website->ignored_themes, true );
            if ( is_array( $ignored_themes ) ) {
                $theme_upgrades = $common->get_not_ignored_updates_themesplugins( $theme_upgrades, $ignored_themes );
            }

            $ignored_themes = json_decode( $user_extension->ignored_themes, true );
            if ( is_array( $ignored_themes ) ) {
                $theme_upgrades = $common->get_not_ignored_updates_themesplugins( $theme_upgrades, $ignored_themes );
            }
            $theme_count = count( $theme_upgrades );
        }
        if ( ! is_array( $theme_upgrades ) ) {
            $theme_upgrades = array();
        }

        return array(
            'wp'                   => $wp_count,
            'plugins'              => $plugin_count,
            'themes'               => $theme_count,
            'translations'         => $translation_count,
            'plugin_upgrades'      => $plugin_upgrades,
            'theme_upgrades'       => $theme_upgrades,
            'translation_upgrades' => $translation_upgrades,
        );
    }

    /**
     * Method get_not_ignored_updates_plugins_db().
     *
     * To compatible with new ignored update info.
     *
     * @param array $updates Update info.
     * @param array $ignored Ignored update info.
     * @param int   $count_ignored Count ignored updates.
     *
     * @return array $updates Not ignored updates info.
     */
    public function get_not_ignored_updates_plugins_db( $updates, $ignored, &$count_ignored = 0 ) { //phpcs:ignore -- NOSONAR - complexity.
        if ( ! is_array( $updates ) || ! is_array( $ignored ) ) {
            return $updates;
        }
        $new_updates = array();
        foreach ( $updates as $slug => $dbup_info ) {
            if ( isset( $ignored[ $slug ] ) ) {
                if ( is_string( $ignored[ $slug ] ) ) {
                    ++$count_ignored;
                    // old ignored info.
                    continue; // ignored update.
                } elseif ( is_array( $ignored[ $slug ] ) && ! empty( $ignored[ $slug ]['ignored_versions'] ) ) {
                    $ignored_vers = is_array( $ignored[ $slug ]['ignored_versions'] ) ? $ignored[ $slug ]['ignored_versions'] : array();
                    $cur_version  = is_array( $dbup_info ) && isset( $dbup_info['version'] ) ? $dbup_info['version'] : '';
                    if ( in_array( 'all_versions', $ignored_vers ) || ( ! empty( $cur_version ) && in_array( $cur_version, $ignored_vers ) ) ) {
                        ++$count_ignored;
                        continue; // ignored update.
                    }
                }
            }
            $new_updates[ $slug ] = $dbup_info;
        }
        return $new_updates;
    }
}

// End of class.
