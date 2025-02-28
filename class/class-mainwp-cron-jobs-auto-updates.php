<?php
/**
 * MainWP System Cron Jobs.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Cron_Jobs_Auto_Updates
 *
 * @package MainWP\Dashboard
 */
class MainWP_Cron_Jobs_Auto_Updates { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Singleton.
     *
     * @var null $instance
     */
    private static $instance = null;


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
    }


    /**
     * Method check_to_run_auto_updates()
     *
     * @return mixed results.
     */
    public function check_to_run_auto_updates() {

        $enable_automaticCoreUpdates = get_option( 'mainwp_automaticDailyUpdate' );
        $plugin_automaticDailyUpdate = get_option( 'mainwp_pluginAutomaticDailyUpdate' );
        $theme_automaticDailyUpdate  = get_option( 'mainwp_themeAutomaticDailyUpdate' );

        $diabled_auto_updates = 1 !== (int) $enable_automaticCoreUpdates && 1 !== (int) $plugin_automaticDailyUpdate && 1 !== (int) $theme_automaticDailyUpdate;

        $auto_updates_running = get_option( 'mainwp_automatic_updates_is_running', 0 );

        if ( $diabled_auto_updates && $auto_updates_running ) {
            $this->finished_auto_updates();
            MainWP_Utility::update_option( 'mainwp_automatic_update_next_run_timestamp', 0 );
            return false;
        }

        if ( $auto_updates_running ) {
            return true;
        } else {
            $local_timestamp      = MainWP_Utility::get_timestamp();
            $time_to_auto_updates = get_option( 'mainwp_automatic_update_next_run_timestamp', 0 );

            // to fix compatiple.
            $frequency_AutoUpdate = get_option( 'mainwp_frequency_AutoUpdate' );
            if ( false === $frequency_AutoUpdate ) {
                MainWP_Utility::update_option( 'mainwp_frequency_AutoUpdate', 'daily' );
                static::set_next_auto_updates_time();
            }

            if ( ! empty( $time_to_auto_updates ) && $local_timestamp > $time_to_auto_updates ) {
                static::set_next_auto_updates_time();

                $this->log_start_auto_updates();

                MainWP_Utility::update_option( 'mainwp_automatic_updates_start_lasttime', $local_timestamp );
                MainWP_Utility::update_option( 'mainwp_automatic_updates_is_running', 1 );

                $websites = MainWP_Auto_Updates_DB::instance()->get_websites_to_start_updates( false, true ); // included disconnected sites.
                while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {
                    $websiteValues = array(
                        'dtsAutomaticSyncStart' => $local_timestamp,
                    );
                    MainWP_DB::instance()->update_website_sync_values( $website->id, $websiteValues );
                    MainWP_DB::instance()->update_website_option( $website, 'bulk_updates_info', wp_json_encode( array() ) );
                }
                MainWP_DB::free_result( $websites );
                return true;
            }
        }
        return false;
    }

    /**
     * Method handle_cron_auto_updates()
     *
     * MainWP Cron Check Update
     *
     * This Cron Checks to see if Automatic Daily Updates need to be performed.
     *
     * @return mixed results.
     */
    public function handle_cron_auto_updates() { // phpcs:ignore Generic.Metrics.CyclomaticComplexity -- NOSONAR Current complexity is the only way to achieve desired results, pull request solutions appreciated.

        $sites_limit = apply_filters( 'mainwp_cron_bulk_update_sites_limit', 3 );
        $items_limit = apply_filters( 'mainwp_cron_bulk_update_items_limit', 3 );

        $local_timestamp             = MainWP_Utility::get_timestamp();
        $enable_automaticCoreUpdates = get_option( 'mainwp_automaticDailyUpdate' );
        $plugin_automaticDailyUpdate = get_option( 'mainwp_pluginAutomaticDailyUpdate' );
        $theme_automaticDailyUpdate  = get_option( 'mainwp_themeAutomaticDailyUpdate' );
        $tran_automaticDailyUpdate   = get_option( 'mainwp_transAutomaticDailyUpdate' );

        $websites = array();

        $lasttime_start = get_option( 'mainwp_automatic_updates_start_lasttime' );

        $autoupdates_websites = MainWP_Auto_Updates_DB::instance()->get_websites_to_continue_updates( $sites_limit, $lasttime_start, false, true ); // included disconnected sites.

        if ( empty( $autoupdates_websites ) ) { // not found automatic sites to updates.
            $this->finished_auto_updates();
            MainWP_Logger::instance()->info( 'Automatic updates finished' );
            MainWP_Logger::instance()->log_update_check( 'Automatic updates finished' );

            // Legacy logs.
            if ( ! empty( $lasttime_start ) ) {
                $busyCounter = MainWP_Auto_Updates_DB::instance()->get_websites_count_where_dts_automatic_sync_smaller_then_start( $lasttime_start );
                if ( ! empty( $busyCounter ) ) {
                    MainWP_Logger::instance()->log_update_check( 'Automatic updates busy counter :: [found=' . intval( $busyCounter ) . ' websites]' );
                    $lastAutomaticUpdate = MainWP_Auto_Updates_DB::instance()->get_websites_last_automatic_sync();
                    if ( ( $local_timestamp - $lastAutomaticUpdate ) < HOUR_IN_SECONDS ) {
                        MainWP_Logger::instance()->log_update_check( 'Automatic updates last update :: ' . $lastAutomaticUpdate );
                    }
                }
            }
            return;
        } else {

            foreach ( $autoupdates_websites as $website ) {
                if ( ! MainWP_DB_Backup::instance()->backup_full_task_running( $website->id ) ) {
                    if ( ! empty( $website->sync_errors ) && ! MainWP_Sync::sync_site( $website, false, true ) ) {
                        $this->finished_site_auto_updates( $website );  // to skip.
                    } elseif ( ! MainWP_Sync::sync_site( $website, false, true ) ) {
                        $this->finished_site_auto_updates( $website );  // to skip.
                    } else {
                        $websites[] = $website;
                    }
                } else {
                    $this->finished_site_auto_updates( $website );  // to skip.
                }
            }

            $log_lastsstart = ! empty( $lasttime_start ) ? ' :: [lasttime_start=' . MainWP_Utility::format_timestamp( $lasttime_start ) . '] ' : '';
            MainWP_Logger::instance()->info( 'Automatic updates found [count=' . count( $autoupdates_websites ) . ']' . $log_lastsstart );
            MainWP_Logger::instance()->log_update_check( 'Automatic updates found [count=' . count( $autoupdates_websites ) . ']' . $log_lastsstart );
            unset( $autoupdates_websites );
        }

        $count_processed_now = 0;

        $userExtension = MainWP_DB_Common::instance()->get_user_extension_by_user_id();

        $decodedIgnoredCores   = ! empty( $userExtension->ignored_wp_upgrades ) ? json_decode( $userExtension->ignored_wp_upgrades, true ) : array();
        $decodedIgnoredPlugins = ! empty( $userExtension->ignored_plugins ) ? json_decode( $userExtension->ignored_plugins, true ) : array();
        $trustedPlugins        = ! empty( $userExtension->trusted_plugins ) ? json_decode( $userExtension->trusted_plugins, true ) : array();
        $decodedIgnoredThemes  = ! empty( $userExtension->ignored_themes ) ? json_decode( $userExtension->ignored_themes, true ) : array();
        $trustedThemes         = ! empty( $userExtension->trusted_themes ) ? json_decode( $userExtension->trusted_themes, true ) : array();

        if ( ! is_array( $decodedIgnoredCores ) ) {
            $decodedIgnoredCores = array();
        }

        if ( ! is_array( $decodedIgnoredPlugins ) ) {
            $decodedIgnoredPlugins = array();
        }

        if ( ! is_array( $trustedPlugins ) ) {
            $trustedPlugins = array();
        }

        if ( ! is_array( $trustedThemes ) ) {
            $trustedThemes = array();
        }

        if ( ! is_array( $decodedIgnoredThemes ) ) {
            $decodedIgnoredThemes = array();
        }

        $finished_updates_sites = array();

        $coreToUpdateNow    = array();
        $pluginsToUpdateNow = array();
        $themesToUpdateNow  = array();
        $transToUpdateNow   = array();

        $delay_autoupdate = get_option( 'mainwp_delay_autoupdate', 1 );

        foreach ( $websites as $website ) {
            $params = array(
                'view'          => 'updates_view',
                'include'       => array( $website->id ),
                'others_fields' => array(
                    'core_update_check',
                    'plugins_update_check',
                    'themes_update_check',
                    'trans_update_check',
                    'premium_upgrades',
                    'ignored_wp_upgrades',
                    'bulk_wp_upgrades',
                    'bulk_plugin_upgrades',
                    'bulk_theme_upgrades',
                    'bulk_updates_info',
                ),
            );

            $fetch_one = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user_by_params( $params ) );
            if ( $fetch_one ) {
                $website = MainWP_DB::fetch_object( $fetch_one );
                MainWP_DB::free_result( $fetch_one );
            }

            if ( empty( $website ) ) {
                continue;
            }

            $found_updates = 0;

            $websiteDecodedIgnoredPlugins = json_decode( $website->ignored_plugins, true );
            if ( ! is_array( $websiteDecodedIgnoredPlugins ) ) {
                $websiteDecodedIgnoredPlugins = array();
            }

            $websiteDecodedIgnoredThemes = json_decode( $website->ignored_themes, true );
            if ( ! is_array( $websiteDecodedIgnoredThemes ) ) {
                $websiteDecodedIgnoredThemes = array();
            }

            $websiteCoreUpgrades = ! empty( $website->wp_upgrades ) ? json_decode( $website->wp_upgrades, true ) : array();
            $websiteIgnoredCores = ! empty( $website->ignored_wp_upgrades ) ? json_decode( $website->ignored_wp_upgrades, true ) : array();

            if ( ! is_array( $websiteCoreUpgrades ) ) {
                $websiteCoreUpgrades = array();
            }

            if ( ! is_array( $websiteIgnoredCores ) ) {
                $websiteIgnoredCores = array();
            }

            $websiteCoreUpdateCheck    = 0;
            $websitePluginsUpdateCheck = array();
            $websiteThemesUpdateCheck  = array();
            $websiteTransUpdateCheck   = array();

            if ( ! empty( $delay_autoupdate ) ) {
                $websiteCoreUpdateCheck    = ! empty( $website->core_update_check ) ? intval( $website->core_update_check ) : 0;
                $websitePluginsUpdateCheck = ! empty( $website->plugins_update_check ) ? json_decode( $website->plugins_update_check, true ) : array();
                $websiteThemesUpdateCheck  = ! empty( $website->themes_update_check ) ? json_decode( $website->themes_update_check, true ) : array();
                $websiteTransUpdateCheck   = ! empty( $website->trans_update_check ) ? json_decode( $website->trans_update_check, true ) : array();
            }

            if ( ! is_array( $websitePluginsUpdateCheck ) ) {
                $websitePluginsUpdateCheck = array();
            }

            if ( ! is_array( $websiteThemesUpdateCheck ) ) {
                $websiteThemesUpdateCheck = array();
            }

            if ( ! is_array( $websiteTransUpdateCheck ) ) {
                $websiteTransUpdateCheck = array();
            }

            $updated_status = ! empty( $website->bulk_updates_info ) ? json_decode( $website->bulk_updates_info, true ) : array();
            if ( ! is_array( $updated_status ) ) {
                $updated_status = array();
            }

            if ( ! isset( $updated_status['auto_updates_processed'] ) ) {
                $updated_status['auto_updates_processed'] = array(
                    'wp'      => array(),
                    'plugins' => array(),
                    'themes'  => array(),
                    'trans'   => array(),
                );
            }

            $item = array(
                'id'          => $website->id,
                'name'        => $website->name,
                'url'         => $website->url,
                'current'     => isset( $websiteCoreUpgrades['current'] ) ? $websiteCoreUpgrades['current'] : '',
                'new_version' => isset( $websiteCoreUpgrades['new'] ) ? $websiteCoreUpgrades['new'] : '',
            );

            if ( ! empty( $website->automatic_update ) && 1 === (int) $website->automatic_update && 1 === (int) $enable_automaticCoreUpdates && isset( $websiteCoreUpgrades['current'] ) && ! $website->is_ignoreCoreUpdates && ! MainWP_Common_Functions::instance()->is_ignored_updates( $item, $websiteIgnoredCores, 'core' ) && ! MainWP_Common_Functions::instance()->is_ignored_updates( $item, $decodedIgnoredCores, 'core' ) ) {
                $_update_now = false;
                if ( ! empty( $delay_autoupdate ) ) {
                    if ( empty( $websiteCoreUpdateCheck ) ) {
                        $websiteCoreUpdateCheck = time();
                    } elseif ( ! empty( $websiteCoreUpdateCheck ) && time() > $delay_autoupdate * DAY_IN_SECONDS + intval( $websiteCoreUpdateCheck ) ) {
                        $_update_now = true;
                    }
                } else {
                    $_update_now = true;
                }

                if ( $_update_now && empty( $updated_status['wp'] ) ) {
                    $coreToUpdateNow[ $website->id ] = $item;
                    ++$found_updates;
                }
            }

            $websitePlugins         = ! empty( $website->plugin_upgrades ) ? json_decode( $website->plugin_upgrades, true ) : array();
            $websiteThemes          = ! empty( $website->theme_upgrades ) ? json_decode( $website->theme_upgrades, true ) : array();
            $decodedPremiumUpgrades = ! empty( $website->premium_upgrades ) ? json_decode( $website->premium_upgrades, true ) : array();
            $websiteTrans           = ! empty( $website->translation_upgrades ) ? json_decode( $website->translation_upgrades, true ) : array();

            if ( ! is_array( $websitePlugins ) ) {
                $websitePlugins = array();
            }

            if ( ! is_array( $websiteThemes ) ) {
                $websiteThemes = array();
            }

            if ( ! is_array( $websiteTrans ) ) {
                $websiteTrans = array();
            }

            if ( is_array( $decodedPremiumUpgrades ) ) {
                foreach ( $decodedPremiumUpgrades as $slug => $premiumUpgrade ) {
                    if ( 'plugin' === $premiumUpgrade['type'] ) {
                        $websitePlugins[ $slug ] = $premiumUpgrade;
                    } elseif ( 'theme' === $premiumUpgrade['type'] ) {
                        $websiteThemes[ $slug ] = $premiumUpgrade;
                    }
                }
            }

            if ( 1 === (int) $plugin_automaticDailyUpdate ) {
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
                        'id'          => $website->id,
                        'name'        => $website->name,
                        'url'         => $website->url,
                        'plugin'      => $pluginInfo['Name'],
                        'current'     => $pluginInfo['Version'],
                        'new_version' => $pluginInfo['update']['new_version'],
                        'change_log'  => $change_log,
                    );
                    if ( in_array( $pluginSlug, $trustedPlugins ) ) {
                        $_update_now     = false;
                        $check_timestamp = isset( $websitePluginsUpdateCheck[ $pluginSlug ] ) ? $websitePluginsUpdateCheck[ $pluginSlug ] : 0;
                        if ( ! empty( $delay_autoupdate ) ) {
                            if ( ! empty( $check_timestamp ) && ( time() > $delay_autoupdate * DAY_IN_SECONDS + intval( $check_timestamp ) ) ) {
                                $_update_now = true;
                            }
                            if ( empty( $check_timestamp ) ) {
                                $websitePluginsUpdateCheck[ $pluginSlug ] = time();
                            }
                        } else {
                            $_update_now = true;
                        }
                        if ( $_update_now && ( ! isset( $updated_status['auto_updates_processed']['plugins'][ $pluginSlug ] ) || ( $item['new_version'] !== $updated_status['auto_updates_processed']['plugins'][ $pluginSlug ]['new_version'] ) ) ) {
                            $pluginsToUpdateNow[ $website->id ][ $pluginSlug ] = $item;
                            ++$found_updates;
                        }
                    }
                }
            }

            if ( 1 === (int) $theme_automaticDailyUpdate ) {
                foreach ( $websiteThemes as $themeSlug => $themeInfo ) {

                    if ( $website->is_ignoreThemeUpdates ) {
                        continue;
                    }

                    if ( MainWP_Common_Functions::instance()->is_ignored_updates( $themeInfo, $decodedIgnoredThemes, 'theme' ) || MainWP_Common_Functions::instance()->is_ignored_updates( $themeInfo, $websiteDecodedIgnoredThemes, 'theme' ) ) {
                        continue;
                    }
                    $item = array(
                        'id'          => $website->id,
                        'name'        => $website->name,
                        'url'         => $website->url,
                        'theme'       => $themeInfo['Name'],
                        'current'     => $themeInfo['Version'],
                        'new_version' => $themeInfo['update']['new_version'],
                        'slug'        => $themeSlug,
                    );

                    if ( in_array( $themeSlug, $trustedThemes ) ) {
                        $_update_now = false;

                        $check_timestamp = isset( $websiteThemesUpdateCheck[ $themeSlug ] ) ? $websiteThemesUpdateCheck[ $themeSlug ] : 0;
                        if ( ! empty( $delay_autoupdate ) ) {
                            if ( ! empty( $check_timestamp ) && time() > $delay_autoupdate * DAY_IN_SECONDS + intval( $check_timestamp ) ) {
                                $_update_now = true;
                            }
                            if ( empty( $check_timestamp ) ) {
                                $websiteThemesUpdateCheck[ $themeSlug ] = time();
                            }
                        } else {
                            $_update_now = true;
                        }

                        if ( $_update_now && ( ! isset( $updated_status['auto_updates_processed']['themes'][ $themeSlug ] ) || ( $item['new_version'] !== $updated_status['auto_updates_processed']['themes'][ $themeSlug ]['new_version'] ) ) ) {
                            $themesToUpdateNow[ $website->id ][ $themeSlug ] = $item;
                            ++$found_updates;
                        }
                    }
                }
            }

            if ( 1 === (int) $tran_automaticDailyUpdate ) {
                foreach ( $websiteTrans as $transInfo ) {

                    $trans_name = isset( $transInfo['name'] ) ? $transInfo['name'] : $transInfo['slug'];
                    $trans_slug = esc_attr( $transInfo['slug'] );

                    $item = array(
                        'id'          => $website->id,
                        'name'        => $website->name,
                        'url'         => $website->url,
                        'translation' => $trans_name,
                        'version'     => $transInfo['version'],
                        'slug'        => $trans_slug,
                    );

                    if ( MainWP_Manage_Sites_Update_View::is_trans_trusted_update( $transInfo, $trustedPlugins, $trustedThemes ) ) {
                        $_update_now     = false;
                        $check_timestamp = isset( $websiteTransUpdateCheck[ $trans_slug ] ) ? $websiteTransUpdateCheck[ $trans_slug ] : 0;
                        if ( ! empty( $delay_autoupdate ) ) {
                            if ( ! empty( $check_timestamp ) && ( time() > $delay_autoupdate * DAY_IN_SECONDS + intval( $check_timestamp ) ) ) {
                                $_update_now = true;
                            }
                            if ( empty( $check_timestamp ) ) {
                                $websiteTransUpdateCheck[ $trans_slug ] = time();
                            }
                        } else {
                            $_update_now = true;
                        }
                        if ( $_update_now && ( ! isset( $updated_status['auto_updates_processed']['trans'][ $trans_slug ] ) ) ) {
                            $transToUpdateNow[ $website->id ][ $trans_slug ] = $item;
                            ++$found_updates;
                        }
                    }
                }
            }

            MainWP_Logger::instance()->log_update_check( 'Automatic found updates :: [siteid=' . $website->id . '] :: [found_updates=' . $found_updates . ']' );

            if ( ! $found_updates ) {
                $this->finished_site_auto_updates( $website );
                $finished_updates_sites[] = $website->id;
            }

            if ( ! empty( $delay_autoupdate ) ) {
                foreach ( $websitePluginsUpdateCheck as $slug => $check_time ) {
                    if ( ( time() > $check_time + 30 * DAY_IN_SECONDS ) && is_array( $websitePlugins ) && ! empty( $websitePlugins ) && ! isset( $websitePlugins[ $slug ] ) ) {
                        unset( $websitePluginsUpdateCheck[ $slug ] );
                    }
                }

                foreach ( $websiteThemesUpdateCheck as $slug => $check_time ) {
                    if ( ( time() > $check_time + 30 * DAY_IN_SECONDS ) && is_array( $websiteThemes ) && ! empty( $websiteThemes ) && ! isset( $websiteThemes[ $slug ] ) ) {
                        unset( $websiteThemesUpdateCheck[ $slug ] );
                    }
                }
                MainWP_DB::instance()->update_website_option( $website, 'core_update_check', $websiteCoreUpdateCheck );
                MainWP_DB::instance()->update_website_option( $website, 'plugins_update_check', ( ! empty( $websitePluginsUpdateCheck ) ? wp_json_encode( $websitePluginsUpdateCheck ) : '' ) );
                MainWP_DB::instance()->update_website_option( $website, 'themes_update_check', ( ! empty( $websiteThemesUpdateCheck ) ? wp_json_encode( $websiteThemesUpdateCheck ) : '' ) );
                MainWP_DB::instance()->update_website_option( $website, 'trans_update_check', ( ! empty( $websiteTransUpdateCheck ) ? wp_json_encode( $websiteTransUpdateCheck ) : '' ) );
            } elseif ( ! empty( $websitePluginsUpdateCheck ) || ! empty( $websiteThemesUpdateCheck ) ) {
                MainWP_DB::instance()->update_website_option( $website, 'core_update_check', 0 );
                MainWP_DB::instance()->update_website_option( $website, 'plugins_update_check', '' );
                MainWP_DB::instance()->update_website_option( $website, 'themes_update_check', '' );
                MainWP_DB::instance()->update_website_option( $website, 'trans_update_check', '' );
            }
        }

            // going to retired.
        if ( get_option( 'mainwp_enableLegacyBackupFeature' ) && get_option( 'mainwp_backup_before_upgrade' ) === 1 ) {
            $sitesCheckCompleted = get_option( 'mainwp_automaticUpdate_backupChecks' );
            if ( ! is_array( $sitesCheckCompleted ) ) {
                $sitesCheckCompleted = array();
            }

            $websitesToCheck = array();

            if ( 1 === (int) $plugin_automaticDailyUpdate ) {
                foreach ( $pluginsToUpdateNow as $websiteId => $updates_plugins ) {
                    $websitesToCheck[ $websiteId ] = true;
                }
            }

            if ( 1 === (int) $theme_automaticDailyUpdate ) {
                foreach ( $themesToUpdateNow as $websiteId => $slugs ) {
                    $websitesToCheck[ $websiteId ] = true;
                }
            }

            if ( 1 === (int) $enable_automaticCoreUpdates ) {
                foreach ( $coreToUpdateNow as $websiteId => $info ) {
                    $websitesToCheck[ $websiteId ] = true;
                }
            }

            if ( 1 === (int) $tran_automaticDailyUpdate ) {
                foreach ( $transToUpdateNow as $websiteId => $updates_trans ) {
                    $websitesToCheck[ $websiteId ] = true;
                }
            }

            $hasWPFileSystem = MainWP_System_Utility::get_wp_file_system();

            /**
             * WordPress files system object.
             *
             * @global object
             */
            global $wp_filesystem;

            if ( $hasWPFileSystem && ! empty( $wp_filesystem ) ) {
                foreach ( $websitesToCheck as $siteId => $bool ) {
                    if ( empty( $website->backup_before_upgrade ) ) {
                        $sitesCheckCompleted[ $siteId ] = true;
                    }
                    if ( isset( $sitesCheckCompleted[ $siteId ] ) ) {
                        continue;
                    }

                    $dir        = MainWP_System_Utility::get_mainwp_specific_dir( $siteId );
                    $dh         = opendir( $dir );
                    $lastBackup = - 1;
                    if ( $wp_filesystem->exists( $dir ) && $dh ) {
                        while ( ( $file = readdir( $dh ) ) !== false ) {
                            if ( '.' !== $file && '..' !== $file ) {
                                $theFile = $dir . $file;
                                if ( MainWP_Backup_Handler::is_archive( $file ) && ! MainWP_Backup_Handler::is_sql_archive( $file ) && ( $wp_filesystem->mtime( $theFile ) > $lastBackup ) ) {
                                    $lastBackup = $wp_filesystem->mtime( $theFile );
                                }
                            }
                        }
                        closedir( $dh );
                    }

                    $mainwp_backup_before_upgrade_days = get_option( 'mainwp_backup_before_upgrade_days' );
                    if ( empty( $mainwp_backup_before_upgrade_days ) || ! ctype_digit( $mainwp_backup_before_upgrade_days ) ) {
                        $mainwp_backup_before_upgrade_days = 7;
                    }

                    $backupRequired = ( $lastBackup < ( time() - ( $mainwp_backup_before_upgrade_days * 24 * 60 * 60 ) ) ? true : false );

                    if ( ! $backupRequired ) {
                        $sitesCheckCompleted[ $siteId ] = true;
                        MainWP_Utility::update_option( 'mainwp_automaticUpdate_backupChecks', $sitesCheckCompleted );
                        continue;
                    }

                    try {
                        $result = MainWP_Backup_Handler::backup( $siteId, 'full', '', '', 0, 0, 0, 0 );
                        MainWP_Backup_Handler::backup_download_file( $siteId, 'full', $result['url'], $result['local'] );
                        $sitesCheckCompleted[ $siteId ] = true;
                        MainWP_Utility::update_option( 'mainwp_automaticUpdate_backupChecks', $sitesCheckCompleted );
                    } catch ( \Exception $e ) {
                        $sitesCheckCompleted[ $siteId ] = false;
                        MainWP_Utility::update_option( 'mainwp_automaticUpdate_backupChecks', $sitesCheckCompleted );
                    }
                }
            }
        } else {
            $sitesCheckCompleted = null;
        }

        /**  Auto updates part. */
        if ( 1 === (int) $plugin_automaticDailyUpdate ) {
            foreach ( $pluginsToUpdateNow as $websiteId => $updates_plugins ) {
                if ( ( null !== $sitesCheckCompleted ) && ( false === $sitesCheckCompleted[ $websiteId ] ) ) {
                    continue;
                }

                // reload.
                $updated_status = MainWP_DB::instance()->get_json_website_option( $websiteId, 'bulk_updates_info' );
                if ( ! is_array( $updated_status ) ) {
                    $updated_status = array();
                }

                if ( ! isset( $updated_status['auto_updates_processed'] ) ) {
                    $updated_status['auto_updates_processed'] = array(
                        'wp'      => array(),
                        'plugins' => array(),
                        'themes'  => array(),
                        'trans'   => array(),
                    );
                }

                $slugs = array();
                foreach ( $updates_plugins as $slug => $info ) {
                    if ( ! isset( $updated_status['auto_updates_processed']['plugins'][ $slug ] ) || ( $info['new_version'] !== $updated_status['auto_updates_processed']['plugins'][ $slug ]['new_version'] ) ) {
                        ++$count_processed_now;
                        $slugs[] = $slug;
                        $updated_status['auto_updates_processed']['plugins'][ $slug ] = $info;
                        if ( $count_processed_now >= $items_limit ) {
                            break;
                        }
                    }
                }

                MainWP_Logger::instance()->log_update_check( 'Automatic updates plugins now:: [count_slugs=' . count( $slugs ) . ']' );

                if ( ! empty( $slugs ) ) {
                    try {

                        MainWP_DB::instance()->update_website_option( $websiteId, 'bulk_updates_info', wp_json_encode( $updated_status ) );

                        MainWP_Logger::instance()->log_update_check( 'Automatic updates plugins [siteid=' . $websiteId . '] :: [slugs=' . urldecode( implode( ',', $slugs ) ) . ']' );

                        $website = MainWP_DB::instance()->get_website_by_id( $websiteId );

                        /**
                        * Action: mainwp_before_plugin_theme_translation_update
                        *
                        * Fires before plugin/theme/translation update actions.
                        *
                        * @since 4.1
                        */
                        do_action( 'mainwp_before_plugin_theme_translation_update', 'plugin', implode( ',', $slugs ), $website );

                        $information = MainWP_Connect::fetch_url_authed(
                            $website,
                            'upgradeplugintheme',
                            array(
                                'type' => 'plugin',
                                'list' => urldecode( implode( ',', $slugs ) ),
                            )
                        );

                        $upgrades = '';
                        if ( is_array( $information ) && isset( $information['upgrades'] ) && is_array( $information['upgrades'] ) ) {
                            $upgrades = wp_json_encode( $information['upgrades'] ); // phpcs:ignore -- logging.
                        }
                        MainWP_Logger::instance()->log_update_check( 'Automatic updates plugins [siteid=' . $websiteId . '] :: [upgrades results=' . $upgrades . ']' );

                        /**
                        * Action: mainwp_after_plugin_theme_translation_update
                        *
                        * Fires before plugin/theme/translation update actions.
                        *
                        * @since 4.1
                        */
                        do_action( 'mainwp_after_plugin_theme_translation_update', $information, 'plugin', implode( ',', $slugs ), $website );

                        if ( isset( $information['sync'] ) && ! empty( $information['sync'] ) ) {
                            MainWP_Sync::sync_information_array( $website, $information['sync'] );
                        }
                    } catch ( \Exception $e ) {
                        // ok.
                    }
                }
            }
        }

        if ( 1 === (int) $theme_automaticDailyUpdate && $count_processed_now < $items_limit ) {

            foreach ( $themesToUpdateNow as $websiteId => $updates_themes ) {
                if ( ( null !== $sitesCheckCompleted ) && ( false === $sitesCheckCompleted[ $websiteId ] ) ) {
                    continue;
                }

                // reload.
                $updated_status = MainWP_DB::instance()->get_json_website_option( $websiteId, 'bulk_updates_info' );
                if ( ! is_array( $updated_status ) ) {
                    $updated_status = array();
                }

                if ( ! isset( $updated_status['auto_updates_processed'] ) ) {
                    $updated_status['auto_updates_processed'] = array(
                        'wp'      => array(),
                        'plugins' => array(),
                        'themes'  => array(),
                        'trans'   => array(),
                    );
                }

                $slugs = array();
                foreach ( $updates_themes as $slug => $info ) {
                    if ( ! isset( $updated_status['auto_updates_processed']['themes'][ $slug ] ) || ( $info['new_version'] !== $updated_status['auto_updates_processed']['themes'][ $slug ]['new_version'] ) ) {
                        ++$count_processed_now;
                        $slugs[] = $slug;
                        $updated_status['auto_updates_processed']['themes'][ $slug ] = $info;
                        if ( $count_processed_now >= $items_limit ) {
                            break;
                        }
                    }
                }

                MainWP_Logger::instance()->log_update_check( 'Automatic updates themes now:: [count_slugs=' . count( $slugs ) . ']' );

                if ( ! empty( $slugs ) ) {

                    MainWP_DB::instance()->update_website_option( $websiteId, 'bulk_updates_info', wp_json_encode( $updated_status ) );

                    MainWP_Logger::instance()->log_update_check( 'Automatic updates theme [siteid=' . $websiteId . '] :: themes :: ' . implode( ',', $slugs ) );

                    $website = MainWP_DB::instance()->get_website_by_id( $websiteId );

                    /**
                    * Action: mainwp_before_plugin_theme_translation_update
                    *
                    * Fires before plugin/theme/translation update actions.
                    *
                    * @since 4.1
                    */
                    do_action( 'mainwp_before_plugin_theme_translation_update', 'theme', implode( ',', $slugs ), $website );

                    try {
                        $information = MainWP_Connect::fetch_url_authed(
                            $website,
                            'upgradeplugintheme',
                            array(
                                'type' => 'theme',
                                'list' => urldecode( implode( ',', $slugs ) ),
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
                    do_action( 'mainwp_after_plugin_theme_translation_update', $information, 'theme', implode( ',', $slugs ), $website );
                }
            }
        }

        if ( 1 === (int) $tran_automaticDailyUpdate && $count_processed_now < $items_limit ) {

            foreach ( $transToUpdateNow as $websiteId => $updates_trans ) {
                if ( ( null !== $sitesCheckCompleted ) && ( false === $sitesCheckCompleted[ $websiteId ] ) ) {
                    continue;
                }

                // reload.
                $updated_status = MainWP_DB::instance()->get_json_website_option( $websiteId, 'bulk_updates_info' );
                if ( ! is_array( $updated_status ) ) {
                    $updated_status = array();
                }

                if ( ! isset( $updated_status['auto_updates_processed'] ) ) {
                    $updated_status['auto_updates_processed'] = array(
                        'wp'      => array(),
                        'plugins' => array(),
                        'themes'  => array(),
                        'trans'   => array(),
                    );
                }

                $slugs = array();
                foreach ( $updates_trans as $slug => $info ) {
                    if ( ! isset( $updated_status['auto_updates_processed']['trans'][ $slug ] ) ) {
                        ++$count_processed_now;
                        $slugs[] = $slug;
                        $updated_status['auto_updates_processed']['trans'][ $slug ] = $info;
                        if ( $count_processed_now >= $items_limit ) {
                            break;
                        }
                    }
                }

                MainWP_Logger::instance()->log_update_check( 'Automatic updates translation now:: [count_slugs=' . count( $slugs ) . ']' );

                if ( ! empty( $slugs ) ) {

                    MainWP_DB::instance()->update_website_option( $websiteId, 'bulk_updates_info', wp_json_encode( $updated_status ) );

                    MainWP_Logger::instance()->log_update_check( 'Automatic updates translation [siteid=' . $websiteId . '] :: translations :: ' . implode( ',', $slugs ) );

                    $website = MainWP_DB::instance()->get_website_by_id( $websiteId );

                    /**
                    * Action: mainwp_before_plugin_theme_translation_update
                    *
                    * Fires before plugin/theme/translation update actions.
                    *
                    * @since 4.1
                    */
                    do_action( 'mainwp_before_plugin_theme_translation_update', 'translation', implode( ',', $slugs ), $website );

                    try {
                        $information = MainWP_Connect::fetch_url_authed(
                            $website,
                            'upgradetranslation',
                            array(
                                'type' => 'translation',
                                'list' => urldecode( implode( ',', $slugs ) ),
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
                    do_action( 'mainwp_after_plugin_theme_translation_update', $information, 'translation', implode( ',', $slugs ), $website );
                }
            }
        }

        if ( 1 === (int) $enable_automaticCoreUpdates && $count_processed_now < $items_limit ) {
            foreach ( $coreToUpdateNow as $websiteId => $info ) {
                if ( ( null !== $sitesCheckCompleted ) && ( false === $sitesCheckCompleted[ $websiteId ] ) ) {
                    continue;
                }

                // reload.
                $updated_status = MainWP_DB::instance()->get_json_website_option( $websiteId, 'bulk_updates_info' );
                if ( ! is_array( $updated_status ) ) {
                    $updated_status = array();
                }

                if ( ! isset( $updated_status['auto_updates_processed'] ) ) {
                    $updated_status['auto_updates_processed'] = array(
                        'wp'      => array(),
                        'plugins' => array(),
                        'themes'  => array(),
                        'trans'   => array(),
                    );
                }

                if ( ! empty( $updated_status['wp'] ) ) {
                    continue; // updated ?.
                }

                $updated_status['auto_updates_processed']['wp'] = $info;
                MainWP_DB::instance()->update_website_option( $websiteId, 'bulk_updates_info', wp_json_encode( $updated_status ) );

                MainWP_Logger::instance()->log_update_check( 'Automatic updates core [siteid=' . $websiteId . ']' );

                $website = MainWP_DB::instance()->get_website_by_id( $websiteId );

                try {
                    MainWP_Connect::fetch_url_authed( $website, 'upgrade' );
                } catch ( \Exception $e ) {
                    $updated_status['wp']['error'] = $e->getMessage();
                    MainWP_DB::instance()->update_website_option( $website, 'bulk_updates_info', wp_json_encode( $updated_status ) );
                }
            }
        }

        if ( ! empty( $finished_updates_sites ) ) {
            foreach ( $finished_updates_sites as $websiteId ) {
                $updated_status = MainWP_DB::instance()->get_json_website_option( $websiteId, 'bulk_updates_info' );
                if ( ! is_array( $updated_status ) ) {
                    $updated_status = array();
                }
                $updated_status['finished'] = MainWP_Utility::get_timestamp();
                MainWP_DB::instance()->update_website_option( $websiteId, 'bulk_updates_info', wp_json_encode( $updated_status ) );
            }
        }
    }


    /**
     * Method get_next_auto_updates_timestamp()
     */
    public function get_next_auto_updates_timestamp() {

        $time_AutoUpdate       = get_option( 'mainwp_time_AutoUpdate' );
        $dayinweek_AutoUpdate  = (int) get_option( 'mainwp_dayinweek_AutoUpdate', 0 );
        $dayinmonth_AutoUpdate = (int) get_option( 'mainwp_dayinmonth_AutoUpdate', 1 );
        $frequency_AutoUpdate  = get_option( 'mainwp_frequency_AutoUpdate', 'daily' );

        if ( ! in_array( $frequency_AutoUpdate, array( 'daily', 'weekly', 'monthly' ) ) ) {
            $frequency_AutoUpdate = 'daily';
        }

        $local_timestamp = MainWP_Utility::get_timestamp();
        $next_run        = 0;

        if ( 'daily' === $frequency_AutoUpdate ) {
            $next_run = MainWP_System_Cron_Jobs::get_timestamp_from_hh_mm( $time_AutoUpdate );
            if ( $next_run < $local_timestamp ) {
                $next_run += DAY_IN_SECONDS;
            }
        } elseif ( 'weekly' === $frequency_AutoUpdate ) {
            $days = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );
            if ( isset( $days[ $dayinweek_AutoUpdate ] ) ) {
                $day_time = strtotime( 'next ' . $days[ $dayinweek_AutoUpdate ] );
                $next_run = MainWP_System_Cron_Jobs::get_timestamp_from_hh_mm( $time_AutoUpdate, $day_time );
            }
        } elseif ( 'monthly' === $frequency_AutoUpdate ) {

            $today = gmdate( 'd', $local_timestamp );
            if ( $today < $dayinmonth_AutoUpdate ) {
                $next_month = gmdate( 'm', $local_timestamp );
            } else {
                $next_month = gmdate( 'm', $local_timestamp ) + 1;
            }

            $next_year = gmdate( 'Y', $local_timestamp );

            if ( $next_month > 12 ) {
                $next_month = 1;
                ++$next_year;
            }

            $last_day = $this->calculate_days_in_month( $next_month, $next_year );
            if ( $dayinmonth_AutoUpdate > $last_day ) {
                $dayinmonth_AutoUpdate = $last_day;
            }
            $next_time = MainWP_System_Cron_Jobs::get_timestamp_from_hh_mm( $time_AutoUpdate );
            $hm        = explode( ':', gmdate( 'H:i', $next_time ) );
            $next_run  = mktime( intval( $hm[0] ), intval( $hm[1] ), 1, $next_month, $dayinmonth_AutoUpdate, $next_year );
        }

        return $next_run;
    }


    /**
     * Method calculate_days_in_month().
     *
     * @param int $month The month.
     * @param int $year The year.
     */
    public function calculate_days_in_month( $month, $year ) {
        if ( function_exists( 'cal_days_in_month' ) ) {
            $max_d = cal_days_in_month( CAL_GREGORIAN, $month, $year );
        } else {
            $max_d = gmdate( 't', mktime( 0, 0, 0, $month, 1, $year ) );
        }
        return $max_d;
    }

    /**
     * Method set_next_auto_updates_time()
     */
    public static function set_next_auto_updates_time() {
        $next_auto_updates = static::instance()->get_next_auto_updates_timestamp();
        if ( $next_auto_updates ) {
            MainWP_Utility::update_option( 'mainwp_automatic_update_next_run_timestamp', $next_auto_updates );
        }
    }


    /**
     * Method finished_auto_updates()
     */
    public function finished_auto_updates() {
        MainWP_Utility::update_option( 'mainwp_automatic_updates_is_running', 0 );
    }


    /**
     * Method finished_site_auto_updates()
     *
     * @param object $website Is individual batch or not.
     */
    public function finished_site_auto_updates( $website ) {
        $websiteValues = array(
            'dtsAutomaticSync' => MainWP_Utility::get_timestamp(),
        );
        MainWP_DB::instance()->update_website_sync_values( $website->id, $websiteValues );
    }

    /**
     * Method log_start_auto_updates()
     */
    public function log_start_auto_updates() {

        // log last run.
        $last_run = get_option( 'mainwp_automatic_updates_recent_running' );
        if ( $last_run ) {
            $last_run = json_decode( $last_run );
        }
        if ( ! is_array( $last_run ) ) {
            $last_run = array();
        }
        if ( count( $last_run ) > 20 ) {
            array_shift( $last_run );
        }

        $local_timestamp = MainWP_Utility::get_timestamp();
        $last_run[] = gmdate( 'Y-m-d H:i:s', $local_timestamp );  //phpcs:ignore -- local time.
        MainWP_Utility::update_option( 'mainwp_automatic_updates_recent_running', wp_json_encode( $last_run ) );
    }
}
