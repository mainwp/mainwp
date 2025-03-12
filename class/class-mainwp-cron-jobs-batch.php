<?php
/**
 * MainWP System Cron Jobs.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Cron_Jobs_Batch
 *
 * @package MainWP\Dashboard
 */
class MainWP_Cron_Jobs_Batch { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Singleton.
     *
     * @var null $instance
     */
    private static $instance = null;


    /**
     * Protected variable to hold User extension.
     *
     * @var mixed Default null.
     */
    protected $userExtension = null;

    /**
     * MainWP Cron Instance.
     *
     * @return self $instance
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }

        return static::$instance;
    }

    /**
     * Constructor.
     *
     * Run each time the class is called.
     */
    public function __construct() {
        if ( null === $this->userExtension ) {
            $this->userExtension = MainWP_DB_Common::instance()->get_user_extension();
        }
    }


    /**
     * Method check_to_run_batch_updates()
     *
     * @return mixed results.
     */
    public function check_to_run_batch_updates() {
        $batch_updates_running            = get_option( 'mainwp_batch_updates_is_running', 0 );
        $batch_individual_updates_running = get_option( 'mainwp_batch_individual_updates_is_running', 0 );

        $local_timestamp = MainWP_Utility::get_timestamp();

        if ( $batch_updates_running ) {
            // check timeout.
            $start_time = get_option( 'mainwp_batch_updates_start_time', 0 );
            if ( ! empty( $start_time ) && $local_timestamp > $start_time + 4 * HOUR_IN_SECONDS ) {
                $batch_updates_running = 0;
                MainWP_Utility::update_option( 'mainwp_batch_updates_is_running', 0 ); // stop.
            }
        }

        if ( $batch_individual_updates_running ) {
            // check timeout.
            $start_time = get_option( 'mainwp_batch_updates_individual_start_time', 0 );
            if ( ! empty( $start_time ) && $local_timestamp > $start_time + 3 * HOUR_IN_SECONDS ) {
                $batch_individual_updates_running = 0;
                MainWP_Utility::update_option( 'mainwp_batch_individual_updates_is_running', 0 ); // stop.
            }
        }

        // a batch updates running so wait to finish to run auto updates check.
        if ( $batch_updates_running || $batch_individual_updates_running ) {

            return true;
        }
        return false;
    }


    /**
     * Method handle_cron_batch_updates()
     *
     * MainWP Cron batch Update
     *
     * This Cron Checks to see if Automatic Daily Updates need to be performed.
     *
     * @return mixed result.
     */
    public function handle_cron_batch_updates() { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- NOSONAR Current complexity is the only way to achieve desired results, pull request solutions appreciated.

        $batch_updates_running            = get_option( 'mainwp_batch_updates_is_running', 0 );
        $batch_individual_updates_running = get_option( 'mainwp_batch_individual_updates_is_running', 0 );

        if ( ! $batch_updates_running && ! $batch_individual_updates_running ) {
            return;
        }

        $decodedIgnoredCores   = ! empty( $this->userExtension->ignored_wp_upgrades ) ? json_decode( $this->userExtension->ignored_wp_upgrades, true ) : array();
        $decodedIgnoredPlugins = ! empty( $this->userExtension->ignored_plugins ) ? json_decode( $this->userExtension->ignored_plugins, true ) : array();
        $decodedIgnoredThemes  = ! empty( $this->userExtension->ignored_themes ) ? json_decode( $this->userExtension->ignored_themes, true ) : array();

        if ( ! is_array( $decodedIgnoredCores ) ) {
            $decodedIgnoredCores = array();
        }

        if ( ! is_array( $decodedIgnoredPlugins ) ) {
            $decodedIgnoredPlugins = array();
        }
        if ( ! is_array( $decodedIgnoredThemes ) ) {
            $decodedIgnoredThemes = array();
        }

        $sites_limit = apply_filters( 'mainwp_cron_bulk_update_sites_limit', 3 );
        $items_limit = apply_filters( 'mainwp_cron_bulk_update_items_limit', 3 );

        $is_individual_batch = false;
        $websites            = array();

        if ( $batch_updates_running ) {
            $start_time = get_option( 'mainwp_batch_updates_start_time', 0 );
            $websites   = MainWP_Auto_Updates_DB::instance()->get_websites_to_continue_updates( $sites_limit, $start_time, true, true );
            if ( empty( $websites ) ) {
                $websites = array();
            }
            MainWP_Logger::instance()->log_update_check( 'Batch updates found [websites=' . count( $websites ) . ']' );

            if ( empty( $websites ) ) { // not found general batch updates.
                $this->finished_batch_updates();
            }
        }

        // if empty general batch updates, check individaul batch updates.
        if ( empty( $websites ) && $batch_individual_updates_running ) {
            $start_time = get_option( 'mainwp_batch_updates_individual_start_time', 0 );
            $websites   = MainWP_Auto_Updates_DB::instance()->get_websites_to_continue_individual_updates( $sites_limit, $start_time );
            if ( empty( $websites ) ) {
                $websites = array();
            }
            MainWP_Logger::instance()->log_update_check( 'Batch individual updates found [' . count( $websites ) . ' websites]' );

            if ( empty( $websites ) ) {
                $this->finished_batch_updates( true );
                return;
            }
            $is_individual_batch = true;
        }

        foreach ( $websites as $website ) {
            $updated_status = MainWP_DB::instance()->get_json_website_option( $website, 'bulk_updates_info' );
            if ( ! is_array( $updated_status ) ) {
                $updated_status = array();
            }
            if ( ! isset( $updated_status['updates_processed'] ) ) {
                $updated_status['updates_processed'] = array(
                    'wp'      => array(),
                    'plugins' => array(),
                    'themes'  => array(),
                );
            }
            $pluginsToUpdateNow = array();
            $themesToUpdateNow  = array();

            $coreToUpdate    = MainWP_DB::instance()->get_json_website_option( $website, 'bulk_wp_upgrades' );
            $pluginsToUpdate = MainWP_DB::instance()->get_json_website_option( $website, 'bulk_plugin_upgrades' );
            $themesToUpdate  = MainWP_DB::instance()->get_json_website_option( $website, 'bulk_theme_upgrades' );

            // check ignored settings again.
            if ( $website->is_ignoreCoreUpdates ) {
                $coreToUpdate = array();
                MainWP_DB::instance()->update_website_option( $website, 'bulk_wp_upgrades', wp_json_encode( array() ) );
            }

            if ( $website->is_ignorePluginUpdates ) {
                $pluginsToUpdate = array();
                MainWP_DB::instance()->update_website_option( $website, 'bulk_plugin_upgrades', wp_json_encode( array() ) );
            }

            if ( $website->is_ignoreThemeUpdates ) {
                $themesToUpdate = array();
                MainWP_DB::instance()->update_website_option( $website, 'bulk_theme_upgrades', wp_json_encode( array() ) );
            }

            if ( ! is_array( $coreToUpdate ) ) {
                $coreToUpdate = array();
            }

            if ( ! is_array( $pluginsToUpdate ) ) {
                $pluginsToUpdate = array();
            }

            if ( ! is_array( $themesToUpdate ) ) {
                $themesToUpdate = array();
            }

            $websiteDecodedIgnoredPlugins = json_decode( $website->ignored_plugins, true );
            if ( ! is_array( $websiteDecodedIgnoredPlugins ) ) {
                $websiteDecodedIgnoredPlugins = array();
            }

            $websiteDecodedIgnoredThemes = json_decode( $website->ignored_themes, true );
            if ( ! is_array( $websiteDecodedIgnoredThemes ) ) {
                $websiteDecodedIgnoredThemes = array();
            }

            $websiteDecodedIgnoredCores = ! empty( $website->ignored_wp_upgrades ) ? json_decode( $website->ignored_wp_upgrades, true ) : array();
            if ( ! is_array( $websiteDecodedIgnoredCores ) ) {
                $websiteDecodedIgnoredCores = array();
            }

            $count_processed_now = 0;

            foreach ( $pluginsToUpdate as $slug => $info ) {
                if ( ! is_array( $info ) ) {
                    continue;
                }
                if ( ! isset( $updated_status['updates_processed']['plugins'][ $slug ] ) ) {
                    $ignored = MainWP_Common_Functions::instance()->is_ignored_updates( $info, $decodedIgnoredPlugins, 'plugin' ) || MainWP_Common_Functions::instance()->is_ignored_updates( $info, $websiteDecodedIgnoredPlugins, 'plugin' );
                    if ( $ignored ) {
                        $info['ignored'] = 1;
                    }
                    $updated_status['updates_processed']['plugins'][ $slug ] = $info;

                    if ( ! $ignored ) {
                        ++$count_processed_now;
                        $pluginsToUpdateNow[] = $slug;
                        if ( $count_processed_now >= $items_limit ) {
                            break;
                        }
                    }
                }
            }

            if ( ! empty( $pluginsToUpdateNow ) ) {
                MainWP_DB::instance()->update_website_option( $website, 'bulk_updates_info', wp_json_encode( $updated_status ) );
                try {
                    MainWP_Logger::instance()->log_update_check( 'Batch updates plugins [websiteid=' . $website->id . '] :: [slugs=' . urldecode( implode( ',', $pluginsToUpdateNow ) ) . ']' );
                    /**
                    * Action: mainwp_before_plugin_theme_translation_update
                    *
                    * Fires before plugin/theme/translation update actions.
                    *
                    * @since 4.1
                    */
                    do_action( 'mainwp_before_plugin_theme_translation_update', 'plugin', implode( ',', $pluginsToUpdateNow ), $website );
                    $information = MainWP_Connect::fetch_url_authed(
                        $website,
                        'upgradeplugintheme',
                        array(
                            'type' => 'plugin',
                            'list' => urldecode( implode( ',', $pluginsToUpdateNow ) ),
                        )
                    );
                    $upgrades    = '';
                    if ( is_array( $information ) && isset( $information['upgrades'] ) && is_array( $information['upgrades'] ) ) {
                        $upgrades = wp_json_encode( $information['upgrades'], true ); // phpcs:ignore -- logging.
                    }
                    MainWP_Logger::instance()->log_update_check( 'Batch updates plugins [upgrades result=' . $upgrades . ']' );

                    /**
                    * Action: mainwp_after_plugin_theme_translation_update
                    *
                    * Fires before plugin/theme/translation update actions.
                    *
                    * @since 4.1
                    */
                    do_action( 'mainwp_after_plugin_theme_translation_update', $information, 'plugin', implode( ',', $pluginsToUpdateNow ), $website );

                    if ( isset( $information['sync'] ) && ! empty( $information['sync'] ) ) {
                        MainWP_Sync::sync_information_array( $website, $information['sync'] );
                    }
                } catch ( \Exception $e ) {
                    // error.
                }
            }

            // batch processing limits.
            if ( $count_processed_now < $items_limit ) {
                foreach ( $themesToUpdate as $slug => $info ) {
                    if ( ! is_array( $info ) ) {
                        continue;
                    }
                    if ( ! isset( $updated_status['updates_processed']['themes'][ $slug ] ) ) {
                        $ignored = MainWP_Common_Functions::instance()->is_ignored_updates( $info, $decodedIgnoredThemes, 'theme' ) || MainWP_Common_Functions::instance()->is_ignored_updates( $info, $websiteDecodedIgnoredThemes, 'theme' );
                        if ( $ignored ) {
                            $info['ignored'] = 1;
                        }
                        $updated_status['updates_processed']['themes'][ $slug ] = $info;
                        if ( ! $ignored ) {
                            ++$count_processed_now;
                            $themesToUpdateNow[] = $slug;
                            if ( $count_processed_now >= $items_limit ) {
                                break;
                            }
                        }
                    }
                }

                if ( ! empty( $themesToUpdateNow ) ) {
                    MainWP_DB::instance()->update_website_option( $website, 'bulk_updates_info', wp_json_encode( $updated_status ) );
                    MainWP_Logger::instance()->log_update_check( 'Batch updates themes [websiteid=' . $website->id . '] :: [slugs=' . implode( ',', $themesToUpdateNow ) . ']' );
                    /**
                    * Action: mainwp_before_plugin_theme_translation_update
                    *
                    * Fires before plugin/theme/translation update actions.
                    *
                    * @since 4.1
                    */
                    do_action( 'mainwp_before_plugin_theme_translation_update', 'theme', implode( ',', $themesToUpdateNow ), $website );

                    try {

                        $information = MainWP_Connect::fetch_url_authed(
                            $website,
                            'upgradeplugintheme',
                            array(
                                'type' => 'theme',
                                'list' => urldecode( implode( ',', $themesToUpdateNow ) ),
                            )
                        );

                        if ( isset( $information['sync'] ) && ! empty( $information['sync'] ) ) {
                            MainWP_Sync::sync_information_array( $website, $information['sync'] );
                        }
                    } catch ( \Exception $e ) {
                        // ok.
                    }

                    /**
                    * Action: mainwp_after_plugin_theme_translation_update
                    *
                    * Fires before plugin/theme/translation update actions.
                    *
                    * @since 4.1
                    */
                    do_action( 'mainwp_after_plugin_theme_translation_update', $information, 'theme', implode( ',', $themesToUpdateNow ), $website );
                }
            }

            // batch processing limits.
            if ( $count_processed_now < $items_limit && ! empty( $coreToUpdate ) && empty( $updated_status['wp'] ) ) {

                $info = $coreToUpdate;

                $ignored = MainWP_Common_Functions::instance()->is_ignored_updates( $coreToUpdate, $decodedIgnoredCores, 'core' ) || MainWP_Common_Functions::instance()->is_ignored_updates( $coreToUpdate, $websiteDecodedIgnoredCores, 'core' );

                if ( $ignored ) {
                    $info['ignored'] = 1;
                }

                $updated_status['updates_processed']['wp'] = $info;

                if ( ! $ignored ) {
                    ++$count_processed_now;
                    MainWP_DB::instance()->update_website_option( $website, 'bulk_updates_info', wp_json_encode( $updated_status ) );
                    MainWP_Logger::instance()->log_update_check( 'Batch updates core [websiteid=' . $website->id . ']' );
                    try {
                        MainWP_Connect::fetch_url_authed( $website, 'upgrade' );
                    } catch ( \Exception $e ) {
                        $updated_status['wp']['error'] = $e->getMessage();
                        MainWP_DB::instance()->update_website_option( $website, 'bulk_updates_info', wp_json_encode( $updated_status ) );
                    }
                }
            }
            MainWP_Logger::instance()->log_update_check( 'Batch updates now [websiteid=' . $website->id . '] :: [update now count=' . $count_processed_now . ']' );

            if ( $count_processed_now < $items_limit ) {
                $this->finished_site_batch_updates( $website, $is_individual_batch );
            }
        }
    }

    /**
     * Method finished_batch_updates()
     *
     * @param bool $individual_batch Is individual batch or not.
     */
    public function finished_batch_updates( $individual_batch = false ) {
        if ( $individual_batch ) {
            MainWP_Logger::instance()->log_update_check( 'Batch individual updates finished' );
            MainWP_Utility::update_option( 'mainwp_batch_individual_updates_is_running', 0 ); // individual batch updates done.
        } else {
            MainWP_Logger::instance()->log_update_check( 'Batch updates finished' );
            MainWP_Utility::update_option( 'mainwp_batch_updates_is_running', 0 );  // general batch updates done.
        }
    }


    /**
     * Method finished_site_batch_updates()
     *
     * @param object $website Is individual batch or not.
     * @param bool   $individual_batch Is individual batch or not.
     */
    public function finished_site_batch_updates( $website, $individual_batch = false ) {
        if ( $individual_batch ) {
            MainWP_DB::instance()->update_website_option( $website, 'batch_individual_queue_time', 0 ); // individual batch site items done.
        } else {
            $websiteValues = array(
                'dtsAutomaticSync' => MainWP_Utility::get_timestamp(),
            );
            MainWP_DB::instance()->update_website_sync_values( $website->id, $websiteValues );
        }
    }

    /**
     * Method prepare_bulk_updates().
     *
     * @param object $website website.
     */
    public function prepare_bulk_updates( $website ) { //phpcs:ignore -- NOSONAR - complex.

        $decodedIgnoredCores   = ! empty( $this->userExtension->ignored_wp_upgrades ) ? json_decode( $this->userExtension->ignored_wp_upgrades, true ) : array();
        $decodedIgnoredPlugins = ! empty( $this->userExtension->ignored_plugins ) ? json_decode( $this->userExtension->ignored_plugins, true ) : array();
        $decodedIgnoredThemes  = ! empty( $this->userExtension->ignored_themes ) ? json_decode( $this->userExtension->ignored_themes, true ) : array();

        if ( ! is_array( $decodedIgnoredCores ) ) {
            $decodedIgnoredCores = array();
        }

        if ( ! is_array( $decodedIgnoredPlugins ) ) {
            $decodedIgnoredPlugins = array();
        }

        if ( ! is_array( $decodedIgnoredThemes ) ) {
            $decodedIgnoredThemes = array();
        }

        $coreToUpdate    = array();
        $pluginsToUpdate = array();
        $themesToUpdate  = array();

        $websiteDecodedIgnoredCores   = ! empty( $website->ignored_wp_upgrades ) ? json_decode( $website->ignored_wp_upgrades, true ) : array();
        $websiteDecodedIgnoredPlugins = json_decode( $website->ignored_plugins, true );
        $websiteDecodedIgnoredThemes  = json_decode( $website->ignored_themes, true );

        if ( ! is_array( $websiteDecodedIgnoredCores ) ) {
            $websiteDecodedIgnoredCores = array();
        }

        if ( ! is_array( $websiteDecodedIgnoredPlugins ) ) {
            $websiteDecodedIgnoredPlugins = array();
        }

        if ( ! is_array( $websiteDecodedIgnoredThemes ) ) {
            $websiteDecodedIgnoredThemes = array();
        }

        $websiteCoreUpgrades = ! empty( $website->wp_upgrades ) ? json_decode( $website->wp_upgrades, true ) : array();
        if ( ! is_array( $websiteCoreUpgrades ) ) {
            $websiteCoreUpgrades = array();
        }

        if ( isset( $websiteCoreUpgrades['current'] ) && ! $website->is_ignoreCoreUpdates && ! MainWP_Common_Functions::instance()->is_ignored_updates( $websiteCoreUpgrades, $websiteDecodedIgnoredCores, 'core' ) && ! MainWP_Common_Functions::instance()->is_ignored_updates( $websiteCoreUpgrades, $decodedIgnoredCores, 'core' ) ) {
            $item           = array(
                'current'     => $websiteCoreUpgrades['current'],
                'new_version' => $websiteCoreUpgrades['new'],
            );
            $coreToUpdate[] = $item;
        }

        $websitePlugins         = json_decode( $website->plugin_upgrades, true );
        $websiteThemes          = json_decode( $website->theme_upgrades, true );
        $decodedPremiumUpgrades = ! empty( $website->premium_upgrades ) ? json_decode( $website->premium_upgrades, true ) : array();

        if ( is_array( $decodedPremiumUpgrades ) ) {
            foreach ( $decodedPremiumUpgrades as $slug => $premiumUpgrade ) {
                if ( 'plugin' === $premiumUpgrade['type'] ) {
                    if ( ! is_array( $websitePlugins ) ) {
                        $websitePlugins = array();
                    }
                    $websitePlugins[ $slug ] = $premiumUpgrade;
                } elseif ( 'theme' === $premiumUpgrade['type'] ) {
                    if ( ! is_array( $websiteThemes ) ) {
                        $websiteThemes = array();
                    }
                    $websiteThemes[ $slug ] = $premiumUpgrade;
                }
            }
        }

        foreach ( $websitePlugins as $pluginSlug => $pluginInfo ) {
            if ( $website->is_ignorePluginUpdates ) {
                continue;
            }
            if ( MainWP_Common_Functions::instance()->is_ignored_updates( $pluginInfo, $decodedIgnoredPlugins, 'plugin' ) || MainWP_Common_Functions::instance()->is_ignored_updates( $pluginInfo, $websiteDecodedIgnoredPlugins, 'plugin' ) ) {
                continue;
            }

            $change_log = '';
            if ( isset( $pluginInfo['update']['url'] ) && ( false !== strpos( $pluginInfo['update']['url'], 'wordpress.org/plugins' ) ) ) {
                $change_log = $pluginInfo['update']['url'];
                if ( substr( $change_log, - 1 ) !== '/' ) {
                    $change_log .= '/';
                }
                $change_log .= '#developers';
            }

            $item = array(
                'slug'        => $pluginSlug,
                'plugin'      => $pluginInfo['Name'],
                'current'     => $pluginInfo['Version'],
                'new_version' => $pluginInfo['update']['new_version'],
                'change_log'  => $change_log,
            );

            $pluginsToUpdate[ $pluginSlug ] = $item;
        }

        foreach ( $websiteThemes as $themeSlug => $themeInfo ) {
            if ( $website->is_ignoreThemeUpdates ) {
                continue;
            }
            if ( MainWP_Common_Functions::instance()->is_ignored_updates( $themeInfo, $decodedIgnoredThemes, 'theme' ) || MainWP_Common_Functions::instance()->is_ignored_updates( $themeInfo, $websiteDecodedIgnoredThemes, 'theme' ) ) {
                continue;
            }
            $item                         = array(
                'slug'        => $themeSlug,
                'theme'       => $themeInfo['Name'],
                'current'     => $themeInfo['Version'],
                'new_version' => $themeInfo['update']['new_version'],
            );
            $themesToUpdate[ $themeSlug ] = $item;
        }

        MainWP_DB::instance()->update_website_option( $website, 'bulk_wp_upgrades', wp_json_encode( $coreToUpdate ) );
        MainWP_DB::instance()->update_website_option( $website, 'bulk_plugin_upgrades', wp_json_encode( $pluginsToUpdate ) );
        MainWP_DB::instance()->update_website_option( $website, 'bulk_theme_upgrades', wp_json_encode( $themesToUpdate ) );
        $total_updates = count( $coreToUpdate ) + count( $pluginsToUpdate ) + count( $themesToUpdate );
        MainWP_DB::instance()->update_website_option(
            $website,
            'bulk_updates_info',
            wp_json_encode(
                array(
                    'total'      => $total_updates,
                    'start_time' => MainWP_Utility::get_timestamp(),
                )
            )
        );
    }
}
