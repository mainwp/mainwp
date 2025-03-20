<?php
/**
 * MainWP Common Handler
 *
 * This class handles the common functions.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

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
        $is_staging = 'no';

        $db_updater_count             = false;
        $total_plugin_db_upgrades     = 0;
        $supported_db_updater_plugins = array();

        $get_fields    = array( 'premium_upgrades', 'favi_icon', 'ignored_wp_upgrades' );
        $custom_fields = apply_filters( 'mainwp_available_updates_count_custom_fields_data', array(), 'updates_count' );

        if ( is_array( $custom_fields ) && 1 === count( $custom_fields ) && in_array( 'plugin_db_upgrades', $custom_fields ) ) {
            $get_fields                   = array_merge( $get_fields, $custom_fields );
            $db_updater_count             = true;
            $supported_db_updater_plugins = apply_filters( 'mainwp_database_updater_supported_plugins', array() );
        }

        $sql = MainWP_DB::instance()->get_sql_websites_for_current_user( false, null, 'wp.url', false, false, null, false, $get_fields, $is_staging );

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
            $wp_upgrades           = ! empty( $website->wp_upgrades ) ? json_decode( $website->wp_upgrades, true ) : array();
            $ignored_core_upgrades = ! empty( $website->ignored_wp_upgrades ) ? json_decode( $website->ignored_wp_upgrades, true ) : array();

            if ( $website->is_ignoreCoreUpdates || MainWP_Common_Functions::instance()->is_ignored_updates( $wp_upgrades, $ignored_core_upgrades, 'core' ) || MainWP_Common_Functions::instance()->is_ignored_updates( $wp_upgrades, $decodedIgnoredCores, 'core' ) ) {
                $wp_upgrades = array();
            }

            if ( is_array( $wp_upgrades ) && ! empty( $wp_upgrades ) ) {
                ++$total_wp_upgrades;
            }

            $translation_upgrades = json_decode( $website->translation_upgrades, true );

            $plugin_upgrades = json_decode( $website->plugin_upgrades, true );
            if ( $website->is_ignorePluginUpdates ) {
                $plugin_upgrades = array();
            }

            $theme_upgrades = json_decode( $website->theme_upgrades, true );
            if ( $website->is_ignoreThemeUpdates ) {
                $theme_upgrades = array();
            }

            $decodedPremiumUpgrades = MainWP_DB::instance()->get_website_option( $website, 'premium_upgrades' );
            $decodedPremiumUpgrades = ! empty( $decodedPremiumUpgrades ) ? json_decode( $decodedPremiumUpgrades, true ) : array();

            if ( is_array( $decodedPremiumUpgrades ) ) {
                foreach ( $decodedPremiumUpgrades as $crrSlug => $premiumUpgrade ) {
                    $premiumUpgrade['premium'] = true;

                    if ( 'plugin' === $premiumUpgrade['type'] ) {
                        if ( ! is_array( $plugin_upgrades ) ) {
                            $plugin_upgrades = array();
                        }
                        if ( ! $website->is_ignorePluginUpdates ) {

                            $premiumUpgrade = array_filter( $premiumUpgrade );
                            if ( ! isset( $plugin_upgrades[ $crrSlug ] ) ) {
                                continue;
                            }

                            $plugin_upgrades[ $crrSlug ] = array_merge( $plugin_upgrades[ $crrSlug ], $premiumUpgrade );
                        }
                    } elseif ( 'theme' === $premiumUpgrade['type'] ) {
                        if ( ! is_array( $theme_upgrades ) ) {
                            $theme_upgrades = array();
                        }
                        if ( ! $website->is_ignoreThemeUpdates ) {
                            $theme_upgrades[ $crrSlug ] = $premiumUpgrade;
                        }
                    }
                }
            }

            if ( is_array( $translation_upgrades ) ) {
                $total_translation_upgrades += count( $translation_upgrades );
            }

            if ( is_array( $plugin_upgrades ) ) {
                $ignored_plugins = json_decode( $website->ignored_plugins, true );
                if ( is_array( $ignored_plugins ) ) {
                    $plugin_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $plugin_upgrades, $ignored_plugins );
                }

                $ignored_plugins = json_decode( $userExtension->ignored_plugins, true );
                if ( is_array( $ignored_plugins ) ) {
                    $plugin_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $plugin_upgrades, $ignored_plugins );
                }

                $total_plugin_upgrades += count( $plugin_upgrades );
            }

            if ( is_array( $theme_upgrades ) ) {
                $ignored_themes = json_decode( $website->ignored_themes, true );
                if ( is_array( $ignored_themes ) ) {
                    $theme_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $theme_upgrades, $ignored_themes );
                }

                $ignored_themes = json_decode( $userExtension->ignored_themes, true );
                if ( is_array( $ignored_themes ) ) {
                    $theme_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $theme_upgrades, $ignored_themes );
                }

                $total_theme_upgrades += count( $theme_upgrades );
            }

            if ( $db_updater_count ) {

                $plugin_db_upgrades = ! empty( $website->plugin_db_upgrades ) ? json_decode( $website->plugin_db_upgrades, true ) : array();
                if ( $website->is_ignorePluginUpdates ) {
                    $plugin_db_upgrades = array();
                }

                if ( is_array( $plugin_db_upgrades ) && ! $website->is_ignorePluginUpdates ) {
                    $_ignored_plugins = ! empty( $website->ignored_plugins ) ? json_decode( $website->ignored_plugins, true ) : array();
                    if ( is_array( $_ignored_plugins ) ) {
                        $plugin_db_upgrades = array_diff_key( $plugin_db_upgrades, $_ignored_plugins );
                    }

                    $_ignored_plugins = ! empty( $userExtension->ignored_plugins ) ? json_decode( $userExtension->ignored_plugins, true ) : array();
                    if ( is_array( $_ignored_plugins ) ) {
                        $plugin_db_upgrades = array_diff_key( $plugin_db_upgrades, $_ignored_plugins );
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

        return $data;
    }
}

// End of class.
