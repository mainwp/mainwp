<?php
/**
 * MainWP System Utility Helper
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Common_Functions
 *
 * @package MainWP\Dashboard
 */
class MainWP_Common_Functions { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Private static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    private static $instance = null;

    /**
     * Protected variable to hold User extension.
     *
     * @var mixed Default null.
     */
    protected $userExtension = null;

    /**
     * Method get_class_name()
     *
     * Get Class Name.
     *
     * @return string
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Method instance()
     *
     * Create public static instance.
     *
     * @static
     * @return MainWP_Common_Functions
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * MainWP_Common_Functions constructor.
     *
     * Runs any time class is called.
     */
    public function __construct() {
        static::$instance = $this;
        if ( null === $this->userExtension ) {
            $this->userExtension = MainWP_DB_Common::instance()->get_user_extension();
        }
    }

    /**
     * Get child site ids that have available updates.
     *
     * @return array $site_ids Array of Child Site ID's that have updates.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::instance()::query()
     * @uses \MainWP\Dashboard\MainWP_DB::instance()::get_sql_websites_for_current_user()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_DB::instance()::get_website_options_array()
     */
    public function get_available_update_siteids() { // phpcs:ignore -- NOSONAR - complex function. Current complexity is the only way to achieve desired results, pull request solutions appreciated.
        $site_ids = array();

        $params = array(
            'view'          => 'updates_view',
            'others_fields' => array( 'premium_upgrades' ),
        );

        $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user_by_params( $params ) );

        $decodedIgnoredCores = ! empty( $this->userExtension->ignored_wp_upgrades ) ? json_decode( $this->userExtension->ignored_wp_upgrades, true ) : array();
        if ( ! is_array( $decodedIgnoredCores ) ) {
            $decodedIgnoredCores = array();
        }

        while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
            $hasSyncErrors = ( '' !== $website->sync_errors );
            $cnt           = 0;
            if ( 1 === (int) $website->offline_check_result && ! $hasSyncErrors ) {
                $total_wp_upgrades     = 0;
                $total_plugin_upgrades = 0;
                $total_theme_upgrades  = 0;

                $wp_upgrades           = isset( $website->wp_upgrades ) && ! empty( $website->wp_upgrades ) ? json_decode( $website->wp_upgrades, true ) : array();
                $ignored_core_upgrades = ! empty( $website->ignored_wp_upgrades ) ? json_decode( $website->ignored_wp_upgrades, true ) : array();

                if ( $website->is_ignoreCoreUpdates || $this->is_ignored_updates( $wp_upgrades, $ignored_core_upgrades, 'core' ) || $this->is_ignored_updates( $wp_upgrades, $decodedIgnoredCores, 'core' ) ) {
                    $wp_upgrades = array();
                }

                if ( is_array( $wp_upgrades ) && ! empty( $wp_upgrades ) ) {
                    ++$total_wp_upgrades;
                }

                $plugin_upgrades = ! empty( $website->plugin_upgrades ) ? json_decode( $website->plugin_upgrades, true ) : array();
                if ( $website->is_ignorePluginUpdates ) {
                    $plugin_upgrades = array();
                }

                $theme_upgrades = ! empty( $website->theme_upgrades ) ? json_decode( $website->theme_upgrades, true ) : array();
                if ( $website->is_ignoreThemeUpdates ) {
                    $theme_upgrades = array();
                }

                $decodedPremiumUpgrades = ! empty( $website->premium_upgrades ) ? json_decode( $website->premium_upgrades, true ) : array();

                if ( is_array( $decodedPremiumUpgrades ) ) {
                    foreach ( $decodedPremiumUpgrades as $crrSlug => $premiumUpgrade ) {
                        $premiumUpgrade['premium'] = true;

                        if ( 'plugin' === $premiumUpgrade['type'] ) {
                            if ( ! is_array( $plugin_upgrades ) ) {
                                $plugin_upgrades = array();
                            }
                            if ( ! $website->is_ignorePluginUpdates ) {
                                $plugin_upgrades[ $crrSlug ] = $premiumUpgrade;
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

                if ( is_array( $plugin_upgrades ) ) {
                    $ignored_plugins = json_decode( $website->ignored_plugins, true );
                    if ( is_array( $ignored_plugins ) ) {
                        $plugin_upgrades = $this->get_not_ignored_updates_themesplugins( $plugin_upgrades, $ignored_plugins );
                    }

                    $ignored_plugins = json_decode( $this->userExtension->ignored_plugins, true );
                    if ( is_array( $ignored_plugins ) ) {
                        $plugin_upgrades = $this->get_not_ignored_updates_themesplugins( $plugin_upgrades, $ignored_plugins );
                    }

                    $total_plugin_upgrades += count( $plugin_upgrades );
                }

                if ( is_array( $theme_upgrades ) ) {
                    $ignored_themes = json_decode( $website->ignored_themes, true );
                    if ( is_array( $ignored_themes ) ) {
                        $theme_upgrades = $this->get_not_ignored_updates_themesplugins( $theme_upgrades, $ignored_themes );
                    }

                    $ignored_themes = json_decode( $this->userExtension->ignored_themes, true );
                    if ( is_array( $ignored_themes ) ) {
                        $theme_upgrades = $this->get_not_ignored_updates_themesplugins( $theme_upgrades, $ignored_themes );
                    }

                    $total_theme_upgrades += count( $theme_upgrades );
                }

                $cnt = $total_wp_upgrades + $total_plugin_upgrades + $total_theme_upgrades;

                if ( 0 < $cnt ) {
                    $site_ids[] = $website->id;
                }
            }
        }

        return $site_ids;
    }

    /**
     * Method get_not_ignored_updates_themesplugins().
     *
     * To compatible with new ignored update info.
     *
     * @since 5.2.
     *
     * @param array $updates Update info.
     * @param array $ignored Ignored update info.
     * @param int   $count_ignored Count ignored updates.
     *
     * @return array $updates Not ignored updates info.
     */
    public function get_not_ignored_updates_themesplugins( $updates, $ignored, &$count_ignored = 0 ) { //phpcs:ignore -- NOSONAR - complexity.
        if ( ! is_array( $updates ) || ! is_array( $ignored ) ) {
            return $updates;
        }
        $new_updates = array();
        foreach ( $updates as $slug => $info ) {
            if ( isset( $ignored[ $slug ] ) ) {
                if ( is_string( $ignored[ $slug ] ) ) {
                    ++$count_ignored;
                    // old ignored info.
                    continue; // ignored update.
                } elseif ( is_array( $ignored[ $slug ] ) && ! empty( $ignored[ $slug ]['ignored_versions'] ) ) {
                    $ignored_vers = is_array( $ignored[ $slug ]['ignored_versions'] ) ? $ignored[ $slug ]['ignored_versions'] : array();
                    $new_version  = is_array( $info ) && isset( $info['update']['new_version'] ) ? $info['update']['new_version'] : '';
                    if ( in_array( 'all_versions', $ignored_vers ) || in_array( $new_version, $ignored_vers ) ) {
                        ++$count_ignored;
                        continue; // ignored update.
                    }
                }
            }
            $new_updates[ $slug ] = $info;
        }
        return $new_updates;
    }


    /**
     * Method is_ignored_updates().
     *
     * To compatible with new ignored update info.
     *
     * @since 5.2.
     *
     * @param array  $item Update info of theme or plugin.
     * @param array  $ignored Ignored update info.
     * @param string $type theme/plugin/core.
     * @param int    $count_ignored Count ignored.

     * @return bool Ignored updates.
     */
    public function is_ignored_updates( $item, $ignored, $type = 'plugin', &$count_ignored = 0 ) { //phpcs:ignore -- NOSONAR complex function.

        if ( ! is_array( $item ) || ! is_array( $ignored ) ) {
            return false;
        }

        if ( in_array( $type, array( 'plugin', 'theme' ) ) ) {
            $item_slug   = isset( $item['update'] ) && is_array( $item['update'] ) && isset( $item['update'][ $type ] ) ? $item['update'][ $type ] : '';
            $new_version = isset( $item['update']['new_version'] ) ? $item['update']['new_version'] : '';

            if ( empty( $item_slug ) && ! empty( $item['slug'] ) ) {
                $item_slug = $item['slug']; // for data of plugin/theme of site.
            }
            if ( empty( $new_version ) && ! empty( $item['new_version'] ) ) {
                $new_version = $item['new_version']; // for data of plugin/theme of site.
            }

            if ( isset( $ignored[ $item_slug ] ) ) {
                if ( is_string( $ignored[ $item_slug ] ) ) { // old ignore info.
                    ++$count_ignored;
                    return true; // ignored update.
                } elseif ( is_array( $ignored[ $item_slug ] ) && ! empty( $ignored[ $item_slug ]['ignored_versions'] ) ) {
                    $ignored_vers = is_array( $ignored[ $item_slug ]['ignored_versions'] ) ? $ignored[ $item_slug ]['ignored_versions'] : array();
                    if ( in_array( 'all_versions', $ignored_vers ) || in_array( $new_version, $ignored_vers ) ) {
                        ++$count_ignored;
                        return true; // ignored update.
                    }
                }
            }
            return false;
        } elseif ( 'core' === $type ) {
            $ignored_vers = isset( $ignored['ignored_versions'] ) && is_array( $ignored['ignored_versions'] ) ? $ignored['ignored_versions'] : array();
            $new_version  = isset( $item['new'] ) ? $item['new'] : '';
            if ( empty( $new_version ) ) {
                $new_version = isset( $item['new_version'] ) ? $item['new_version'] : ''; // to support some info.
            }
            if ( ! empty( $new_version ) && ( in_array( 'all_versions', $ignored_vers ) || in_array( $new_version, $ignored_vers ) ) ) {
                ++$count_ignored;
                return true; // ignored update.
            }
        }
        return false;
    }
}
