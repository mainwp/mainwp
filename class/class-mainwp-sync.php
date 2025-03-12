<?php
/**
 * MainWP Sync Handler
 *
 * Handle all syncing between MainWP & Child Site Network.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

use MainWP\Dashboard\Module\Log\Log_Manager;

/**
 * Class MainWP_Sync
 *
 * @package MainWP\Dashboard
 */
class MainWP_Sync { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Clone websites setting.
     *
     * @var mixed Clone websites.
     */
    public static $clone_websites = null;

    /**
     * Clone enabled setting.
     *
     * @var mixed Clone enabled.
     */
    public static $clone_enabled = null;


    /**
     * Disallowed Clone sites setting.
     *
     * @var mixed Disallowed clone.
     */
    public static $disallowed_clone_sites = null;


    /**
     * Method sync_website()
     *
     * Sync Child Site.
     *
     * @param object $website object.
     * @param bool   $clear_session to run the ending session or not.
     *
     * @return bool sync result.
     */
    public static function sync_website( $website, $clear_session = true ) {
        if ( ! is_object( $website ) ) {
            return false;
        }
        MainWP_DB::instance()->update_website_sync_values( $website->id, array( 'dtsSyncStart' => time() ) );
        return static::sync_site( $website, false, true, $clear_session );
    }

    /**
     * Method sync_site()
     *
     * @param mixed $pWebsite         Null|userid.
     * @param bool  $pForceFetch      Check if a fourced Sync.
     * @param bool  $pAllowDisconnect Check if allowed to disconect.
     * @param bool  $clear_session to run the ending session or not.
     *
     * @return bool sync_information_array
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Common::get_user_extension_by_user_id()
     * @uses \MainWP\Dashboard\MainWP_DB::query()
     * @uses \MainWP\Dashboard\MainWP_DB::update_website_option()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_DB::free_result()
     * @uses \MainWP\Dashboard\MainWP_Exception
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_primary_backup()
     * @uses  \MainWP\Dashboard\MainWP_Utility::end_session()
     */
    public static function sync_site( &$pWebsite = null, $pForceFetch = false, $pAllowDisconnect = true, $clear_session = true ) { // phpcs:ignore -- NOSONAR - complexity method.

        // to support demo data.
        if ( MainWP_Demo_Handle::get_instance()->is_demo_website( $pWebsite ) ) {
            return MainWP_Demo_Handle::get_instance()->handle_action_demo( $pWebsite, 'sync_site' );
        }

        if ( null === $pWebsite ) {
            return false;
        }
        $userExtension = MainWP_DB_Common::instance()->get_user_extension_by_user_id( $pWebsite->userid );
        if ( null === $userExtension ) {
            return false;
        }

        if ( $clear_session ) {
            MainWP_Utility::end_session();
        }

        try {

            if ( null === static::$clone_enabled ) {

                /**
                 * Filter: mainwp_clone_enabled
                 *
                 * Filters whether the Clone feature is enabled or disabled.
                 *
                 * @since Unknown
                 */
                static::$clone_enabled  = apply_filters( 'mainwp_clone_enabled', false );
                static::$clone_websites = array();

                if ( static::$clone_enabled ) {

                    static::$disallowed_clone_sites = get_option( 'mainwp_clone_disallowedsites' );

                    if ( ! is_array( static::$disallowed_clone_sites ) ) {
                        static::$disallowed_clone_sites = array();
                    }

                    $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );
                    if ( $websites ) {
                        while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
                            if ( in_array( $website->id, static::$disallowed_clone_sites ) ) {
                                continue;
                            }
                            if ( (int) $website->id === (int) $pWebsite->id ) {
                                continue;
                            }

                            static::$clone_websites[ $website->id ] = array(
                                'name'          => $website->name,
                                'url'           => $website->url,
                                'extauth'       => $website->extauth,
                                'size'          => $website->totalsize,
                                'connect_admin' => $website->adminname,
                            );
                        }
                        MainWP_DB::free_result( $websites );
                    }
                    $disallowed_current_site = in_array( $pWebsite->id, static::$disallowed_clone_sites ) ? true : false;
                }
            }

            $disallowed_current_site = static::$clone_enabled && is_array( static::$disallowed_clone_sites ) && in_array( $pWebsite->id, static::$disallowed_clone_sites ) ? true : false;

            $primaryBackup = MainWP_System_Utility::get_primary_backup();

            $othersData = apply_filters_deprecated( 'mainwp-sync-others-data', array( array(), $pWebsite ), '4.0.7.2', 'mainwp_sync_others_data' );  // @deprecated Use 'mainwp_sync_others_data' instead. NOSONAR - not IP.

            /**
             * Filter: mainwp_sync_others_data
             *
             * Filters additional data in the sync request. Allows extensions or 3rd party plugins to hook data to the sync request.
             *
             * @param object $pWebsite Object contaning child site data.
             *
             * @since Unknown
             *
             * @uses \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
             */
            $othersData = apply_filters( 'mainwp_sync_others_data', $othersData, $pWebsite );

            $saved_days_number = apply_filters( 'mainwp_site_actions_saved_days_number', 30 );

            $backup_method = '';
            if ( property_exists( $pWebsite, 'primary_backup_method' ) ) {
                if ( '' === $pWebsite->primary_backup_method || 'global' === $pWebsite->primary_backup_method ) {
                    $backup_method = $primaryBackup;
                } else {
                    $backup_method = $pWebsite->primary_backup_method;
                }
            }

            $postdata = array(
                'optimize'                        => 1 === (int) get_option( 'mainwp_optimize', 1 ) ? 1 : 0,
                'cloneSites'                      => ( ! static::$clone_enabled || $disallowed_current_site ) ? 0 : rawurlencode( wp_json_encode( static::$clone_websites ) ),
                'othersData'                      => wp_json_encode( $othersData ),
                'server'                          => get_admin_url(),
                'numberdaysOutdatePluginTheme'    => get_option( 'mainwp_numberdays_Outdate_Plugin_Theme', 365 ),
                'primaryBackup'                   => $backup_method, // if empty site backup method will not sync the backup info from child site.
                'siteId'                          => $pWebsite->id,
                'child_actions_saved_days_number' => intval( $saved_days_number ),
                'pingnonce'                       => MainWP_Utility::instance()->create_site_nonce( 'pingnonce', $pWebsite->id ),
            );

            $reg_verify = MainWP_DB::instance()->get_website_option( $pWebsite, 'register_verify_key', '' );
            if ( empty( $reg_verify ) ) {
                $postdata['sync_regverify'] = 1;
            }

            $synclist             = MainWP_Settings::get_instance()->get_data_list_to_sync();
            $postdata['syncdata'] = wp_json_encode( $synclist );

            $information = MainWP_Connect::fetch_url_authed(
                $pWebsite,
                'stats',
                $postdata,
                true,
                $pForceFetch
            );

            $return = static::sync_information_array( $pWebsite, $information, '', false, false, $pAllowDisconnect );
            MainWP_Logger::instance()->log_execution_time( 'sync :: [siteid=' . $pWebsite->id . ']' );
            return $return;
        } catch ( MainWP_Exception $e ) {
            $sync_errors = '';

            if ( $e->getMessage() === 'HTTPERROR' ) {
                $sync_errors = esc_html__( 'HTTP error', 'mainwp' ) . ( ! empty( $e->get_message_extra() ) ? ' - ' . $e->get_message_extra() : '' );
            } elseif ( $e->getMessage() === 'NOMAINWP' ) {
                $sync_errors = MainWP_Error_Helper::get_error_not_detected_connect();
            }

            MainWP_Logger::instance()->log_execution_time( 'sync :: [siteid=' . $pWebsite->id . ']' );
            return static::sync_information_array( $pWebsite, $information, $sync_errors, false, true, $pAllowDisconnect );
        }
    }

    /**
     * Method sync_information_array()
     *
     * Grab all Child Site Information.
     *
     * @param object $pWebsite The website object.
     * @param array  $information Array contaning information returned from child site.
     * @param string $sync_errors Check for Sync Errors.
     * @param int    $check_result Check if offline.
     * @param bool   $error True|False.
     * @param bool   $pAllowDisconnect True|False.
     *
     * @return bool true|false True on success, false on failure.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::update_website_option()
     * @uses \MainWP\Dashboard\MainWP_DB::update_website_sync_values()
     * @uses \MainWP\Dashboard\MainWP_DB::update_website_values()
     * @uses \MainWP\Dashboard\MainWP_Logger::warning_for_website()
     * @uses \MainWP\Dashboard\MainWP_Monitoring_Handler::get_health_noticed_status_value()
     * @uses  \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     * @uses  \MainWP\Dashboard\MainWP_Utility::get_site_health()
     */
    public static function sync_information_array( &$pWebsite, &$information, $sync_errors = '', $check_result = false, $error = false, $pAllowDisconnect = true ) { // phpcs:ignore -- NOSONAR -Current complexity is the only way to achieve desired results, pull request solutions appreciated.
        $emptyArray        = wp_json_encode( array() );
        $websiteValues     = array();
        $websiteSyncValues = array(
            'sync_errors' => $sync_errors,
            'version'     => 0,
        );

        $_error = $sync_errors;

        $done = false;

        $current_siteid = 0;
        if ( is_string( $pWebsite ) || is_int( $pWebsite ) ) {
            $current_siteid = intval( $pWebsite );
        } elseif ( is_object( $pWebsite ) && ( ! property_exists( $pWebsite, 'plugin_updates' ) || ! property_exists( $pWebsite, 'theme_updates' ) ) ) {
            $current_siteid = $pWebsite->id;
        }

        // to get full data.
        if ( $current_siteid ) {
            $pWebsite = MainWP_DB::instance()->get_website_by_id( $current_siteid );
        }

        /**
         * Filter: mainwp_before_save_sync_result
         *
         * Filters data returned from child site before saving to the database.
         *
         * @param object $pWebsite Object containing child site data.
         *
         * @since 3.4
         */
        $information = apply_filters( 'mainwp_before_save_sync_result', $information, $pWebsite );

        if ( ! empty( $information['regverify_info'] ) ) {
            MainWP_DB::instance()->update_website_option( $pWebsite, 'register_verify_key', $information['regverify_info'] );
            $done = true;
        }

        if ( isset( $information['siteurl'] ) ) {
            $websiteValues['siteurl'] = $information['siteurl'];
            $done                     = true;
        }

        if ( isset( $information['version'] ) ) {
            $websiteSyncValues['version'] = $information['version'];
            $done                         = true;
        }

        $phpversion = '';
        if ( isset( $information['site_info'] ) && ! empty( $information['site_info'] ) ) {
            if ( is_array( $information['site_info'] ) && isset( $information['site_info']['phpversion'] ) ) {
                $phpversion = $information['site_info']['phpversion'];
            }
            if ( is_array( $information['site_info'] ) && isset( $information['site_info']['ip'] ) ) {
                $websiteValues['ip'] = sanitize_text_field( wp_unslash( $information['site_info']['ip'] ) );
            }
            MainWP_DB::instance()->update_website_option( $pWebsite, 'site_info', wp_json_encode( $information['site_info'] ) );
            $done = true;
        }

        if ( ! empty( $phpversion ) ) {
            MainWP_DB::instance()->update_website_option( $pWebsite, 'phpversion', $phpversion );
        }
        if ( isset( $information['directories'] ) && is_array( $information['directories'] ) ) {
            $websiteValues['directories'] = wp_json_encode( $information['directories'] );
            $done                         = true;
        } elseif ( isset( $information['directories'] ) ) {
            $websiteValues['directories'] = $information['directories'];
            $done                         = true;
        }

        if ( isset( $information['wp_updates'] ) && ! empty( $information['wp_updates'] ) ) {
            MainWP_DB::instance()->update_website_option(
                $pWebsite,
                'wp_upgrades',
                wp_json_encode(
                    array(
                        'current' => $information['wpversion'],
                        'new'     => $information['wp_updates'],
                    )
                )
            );
            $done = true;
        }

        if ( isset( $information['plugin_updates'] ) ) {
            $update_values = array();
            if ( is_array( $information['plugin_updates'] ) ) {
                foreach ( $information['plugin_updates'] as $file => $update ) {
                    $update_values[ $file ] = $update;
                }
            }
            $websiteValues['plugin_upgrades'] = wp_json_encode( $update_values );
            $done                             = true;
        }

        if ( isset( $information['theme_updates'] ) ) {
            $update_values = array();
            if ( is_array( $information['theme_updates'] ) ) {
                foreach ( $information['theme_updates'] as $file => $update ) {
                    $update_values[ $file ] = $update;
                }
            }
            $websiteValues['theme_upgrades'] = wp_json_encode( $update_values );
            $done                            = true;
        }

        if ( isset( $information['translation_updates'] ) ) {
            $websiteValues['translation_upgrades'] = wp_json_encode( $information['translation_updates'] );
            $done                                  = true;
        }

        if ( isset( $information['premium_updates'] ) ) {
            MainWP_DB::instance()->update_website_option( $pWebsite, 'premium_upgrades', wp_json_encode( $information['premium_updates'] ) );
            $done = true;
        }

        if ( isset( $information['securityStats'] ) ) {
            $total_securityIssues = 0;
            $securityStats        = $information['securityStats'];
            if ( is_array( $securityStats ) ) {
                /** This filter is documented in ../pages/page-mainwp-security-issues.php */
                $filterStats = apply_filters( 'mainwp_security_issues_stats', false, $securityStats, $pWebsite );
                if ( false !== $filterStats && is_array( $filterStats ) ) {
                    $securityStats = array_merge( $securityStats, $filterStats );
                }

                $tmp_issues           = array_filter(
                    $securityStats,
                    function ( $v ) {
                        return 'N' === $v;
                    },
                    ARRAY_FILTER_USE_BOTH
                );
                $total_securityIssues = count( $tmp_issues );
                $securityStats        = wp_json_encode( $securityStats );
            } else {
                $securityStats = $emptyArray;
            }
            $websiteValues['securityIssues'] = $total_securityIssues;
            MainWP_DB::instance()->update_website_option( $pWebsite, 'security_stats', $securityStats );
            $done = true;
        } elseif ( isset( $information['securityIssues'] ) && MainWP_Utility::ctype_digit( $information['securityIssues'] ) && $information['securityIssues'] >= 0 ) {
            $websiteValues['securityIssues'] = $information['securityIssues'];
            $done                            = true;
        }

        if ( isset( $information['recent_comments'] ) ) {
            MainWP_DB::instance()->update_website_option( $pWebsite, 'recent_comments', wp_json_encode( $information['recent_comments'] ) );
            $done = true;
        }

        if ( isset( $information['recent_posts'] ) ) {
            MainWP_DB::instance()->update_website_option( $pWebsite, 'recent_posts', wp_json_encode( $information['recent_posts'] ) );
            $done = true;
        }

        if ( isset( $information['recent_pages'] ) ) {
            MainWP_DB::instance()->update_website_option( $pWebsite, 'recent_pages', wp_json_encode( $information['recent_pages'] ) );
            $done = true;
        }

        if ( isset( $information['themes'] ) ) {
            $websiteValues['themes'] = wp_json_encode( $information['themes'] );
            $done                    = true;
        }

        if ( isset( $information['plugins'] ) ) {
            $websiteValues['plugins'] = wp_json_encode( $information['plugins'] );
            $done                     = true;
        }

        if ( isset( $information['users'] ) ) {
            $websiteValues['users'] = wp_json_encode( $information['users'] );
            $done                   = true;
        }

        if ( isset( $information['categories_list'] ) ) {
            $websiteValues['categories'] = wp_json_encode( $information['categories_list'] );
            $done                        = true;
        } elseif ( isset( $information['categories'] ) ) { // support old child version.
            $websiteValues['categories'] = wp_json_encode( $information['categories'] );
            $done                        = true;
        }

        if ( isset( $information['totalsize'] ) ) {
            $websiteSyncValues['totalsize'] = $information['totalsize'];
            $done                           = true;
        }

        if ( isset( $information['dbsize'] ) ) {
            $websiteSyncValues['dbsize'] = $information['dbsize'];
            $done                        = true;
        }

        if ( isset( $information['extauth'] ) ) {
            $websiteSyncValues['extauth'] = $information['extauth'];
            $done                         = true;
        }

        if ( isset( $information['wpe'] ) ) {
            $websiteValues['wpe'] = $information['wpe'];
            $done                 = true;
        }

        if ( isset( $information['wphost'] ) ) {
            MainWP_DB::instance()->update_website_option( $pWebsite, 'wphost', $information['wphost'] );
        }

        if ( isset( $information['last_post_gmt'] ) ) {
            $websiteSyncValues['last_post_gmt'] = $information['last_post_gmt'];
            $done                               = true;
        }

        if ( isset( $information['health_site_status'] ) ) {
            $health_status                     = $information['health_site_status'];
            $hstatus                           = MainWP_Utility::get_site_health( $health_status );
            $custom_health_value               = $hstatus['val'] - $hstatus['critical'] * 100; // computes custom health value to support sorting by sites health and sites health threshold.
            $websiteSyncValues['health_value'] = $custom_health_value;
            $done                              = true;
            MainWP_DB::instance()->update_website_option( $pWebsite, 'health_site_status', wp_json_encode( $health_status ) );
            $new_noticed = MainWP_Monitoring_Handler::get_health_noticed_status_value( $pWebsite, $custom_health_value );
            if ( null !== $new_noticed ) {
                MainWP_DB::instance()->update_website_sync_values(
                    $pWebsite->id,
                    array(
                        'health_site_noticed' => $new_noticed,
                    )
                );
            }

            $hval          = $hstatus['val'];
            $critical      = $hstatus['critical'];
            $health_status = 0;
            if ( 80 <= $hval && empty( $critical ) ) {
                $health_status = 0; // Good.
            } else {
                $health_status = 1; // Should be improved'.
            }
            $websiteSyncValues['health_status'] = $health_status;
        }

        if ( isset( $information['mainwpdir'] ) ) {
            $websiteValues['mainwpdir'] = $information['mainwpdir'];
            $done                       = true;
        }

        if ( isset( $information['uniqueId'] ) ) {
            $websiteValues['uniqueId'] = $information['uniqueId'];
            $done                      = true;
        }

        if ( isset( $information['clone_adminname'] ) ) {
            $websiteValues['adminname'] = $information['clone_adminname'];
            $done                       = true;
        }

        if ( isset( $information['admin_nicename'] ) ) {
            MainWP_DB::instance()->update_website_option( $pWebsite, 'admin_nicename', trim( $information['admin_nicename'] ) );
            $done = true;
        }

        if ( isset( $information['admin_useremail'] ) ) {
            MainWP_DB::instance()->update_website_option( $pWebsite, 'admin_useremail', trim( $information['admin_useremail'] ) );
            $done = true;
        }

        if ( isset( $information['plugins_outdate_info'] ) ) {
            MainWP_DB::instance()->update_website_option( $pWebsite, 'plugins_outdate_info', wp_json_encode( $information['plugins_outdate_info'] ) );
            $done = true;
        }

        if ( isset( $information['themes_outdate_info'] ) ) {
            MainWP_DB::instance()->update_website_option( $pWebsite, 'themes_outdate_info', wp_json_encode( $information['themes_outdate_info'] ) );
            $done = true;
        }

        if ( isset( $information['primaryLasttimeBackup'] ) ) {
            MainWP_DB::instance()->update_website_option( $pWebsite, 'primary_lasttime_backup', $information['primaryLasttimeBackup'] );
            $done = true;
        }

        if ( isset( $information['child_site_actions_data'] ) ) {
            if ( is_array( $information['child_site_actions_data'] ) && isset( $information['child_site_actions_data']['connected_admin'] ) ) {
                unset( $information['child_site_actions_data']['connected_admin'] );
            }
            Log_Manager::instance()->sync_log_site_actions( $pWebsite->id, $information['child_site_actions_data'], $pWebsite );
            $done = true;
        }

        if ( ! $done ) {
            if ( isset( $information['wpversion'] ) ) {
                $done = true;
            } elseif ( isset( $information['error'] ) ) {
                MainWP_Logger::instance()->warning_for_website( $pWebsite, 'SYNC ERROR', '[' . esc_html( $information['error'] ) . ']' );
                $error                            = true;
                $done                             = true;
                $_error                           = esc_html__( 'ERROR: ', 'mainwp' ) . esc_html( $information['error'] );
                $websiteSyncValues['sync_errors'] = $_error;
            } elseif ( ! empty( $sync_errors ) ) {
                MainWP_Logger::instance()->warning_for_website( $pWebsite, 'SYNC ERROR', '[' . $sync_errors . ']' );
                $_error = $sync_errors;
                $error  = true;
                if ( ! $pAllowDisconnect ) {
                    $sync_errors = '';
                }

                $websiteSyncValues['sync_errors'] = $sync_errors;
            } else {
                MainWP_Logger::instance()->warning_for_website( $pWebsite, 'SYNC ERROR', '[Undefined error]' );
                $error = true;
                if ( $pAllowDisconnect ) {
                    $sync_errors                      = esc_html__( 'Undefined error! Please, reinstall the MainWP Child plugin on the child site.', 'mainwp' );
                    $websiteSyncValues['sync_errors'] = $sync_errors;
                    $_error                           = $sync_errors;
                }
            }
        }

        $act_success = false;
        if ( $done ) {
            $act_success                  = true;
            $websiteSyncValues['dtsSync'] = time();
        }
        MainWP_DB::instance()->update_website_sync_values( $pWebsite->id, $websiteSyncValues );

        if ( ! empty( $websiteValues ) ) {
            MainWP_DB::instance()->update_website_values( $pWebsite->id, $websiteValues );
        }

        $error = apply_filters( 'mainwp_sync_site_after_sync_result', $error, $pWebsite, $information );

        // Sync action.
        if ( ! $error ) {
            do_action_deprecated( 'mainwp-site-synced', array( $pWebsite, $information ), '4.0.7.2', 'mainwp_site_synced' ); // @deprecated Use 'mainwp_site_synced' instead. NOSONAR - not IP.

            /**
             * Action: mainwp_site_synced
             *
             * Fires upon successful site synchronization.
             *
             * @param object $pWebsite    Object containing child site info.
             * @param array  $information Array containing information returned from child site.
             *
             * @since 3.4
             */
            do_action( 'mainwp_site_synced', $pWebsite, $information );
        }

        $post_data = array();

        /**
         * Action: mainwp_site_sync
         *
         * Fires upon successful site synchronization.
         *
         * @param object $pWebsite    Object containing child site info.
         * @param array  $information Array containing information returned from child site.
         * @param bool  $act_success action success or failed.
         *  @param string  $_error Sync error message if existed.
         * @param array  $post_data Addition post data.
         *
         * @since 3.4
         */
        do_action( 'mainwp_site_sync', $pWebsite, $information, $act_success, $_error, $post_data );

        return ! $error;
    }


    /**
     * Method init empty sync values.
     *
     * @param object $pWebsite    Object containing child site info.
     *
     * @return void
     */
    public static function sync_init_empty_values( $pWebsite ) {

        $emptyArray = wp_json_encode( array() );

        $opts = array(
            'site_info',
            'wp_upgrades',
            'premium_upgrades',
            'recent_comments',
            'recent_posts',
            'recent_pages',
            'health_site_status',
            'plugins_outdate_info',
            'themes_outdate_info',
            'directories',
            'plugin_upgrades',
            'theme_upgrades',
            'translation_upgrades',
            'securityIssues',
            'themes',
            'plugins',
            'users',
            'categories',
        );

        foreach ( $opts as $opt ) {
            MainWP_DB::instance()->update_website_option( $pWebsite, $opt, $emptyArray );
        }
    }


    /**
     * Method get_wp_icon()
     *
     * Get site's icon.
     *
     * @param mixed $siteId site's id.
     *
     * @return array result error or success
     * @throws \MainWP_Exception Error message.
     *
     * @uses \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
     * @uses \MainWP\Dashboard\MainWP_Connect::get_file_content()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses \MainWP\Dashboard\MainWP_DB_Common::update_website_option()
     * @uses \MainWP\Dashboard\MainWP_Exception
     * @uses \MainWP\Dashboard\MainWP_Logger::debug()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_wp_file_system()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_mainwp_dir()
     * @uses  \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     */
    public static function get_wp_icon( $siteId = null ) { // phpcs:ignore -- NOSONAR - complex.
        if ( MainWP_Utility::ctype_digit( $siteId ) ) {
            $website = MainWP_DB::instance()->get_website_by_id( $siteId );
            if ( MainWP_System_Utility::can_edit_website( $website ) ) {
                $error = '';
                try {
                    $information = MainWP_Connect::fetch_url_authed( $website, 'get_site_icon' );
                } catch ( MainWP_Exception $e ) {
                    $error = $e->getMessage();
                }

                if ( ! empty( $error ) ) {
                    return array( 'error' => $error );
                } elseif ( isset( $information['faviIconUrl'] ) && ! empty( $information['faviIconUrl'] ) ) {
                    MainWP_Logger::instance()->debug( 'Downloading icon :: ' . esc_html( $information['faviIconUrl'] ) );
                    $content = MainWP_Connect::get_file_content( $information['faviIconUrl'] );
                    if ( ! empty( $content ) ) {

                        MainWP_System_Utility::get_wp_file_system();

                        /**
                         * WordPress files system object.
                         *
                         * @global object
                         */
                        global $wp_filesystem;

                        $dirs     = MainWP_System_Utility::get_mainwp_dir( 'icons', true );
                        $iconsDir = $dirs[0];
                        $filename = basename( $information['faviIconUrl'] );
                        $filename = strtok( $filename, '?' );
                        if ( $filename ) {
                            $filename = 'favi-' . $siteId . '-' . $filename;
                            $size     = false;
                            if ( MainWP_Utility::check_image_file_name( $filename ) ) {
                                $size     = $wp_filesystem->put_contents( $iconsDir . $filename, $content ); // phpcs:ignore --
                            }
                            if ( $size ) {
                                MainWP_Logger::instance()->debug( 'Icon size :: ' . $size );
                                MainWP_DB::instance()->update_website_option( $website, 'favi_icon', $filename );
                                return array( 'result' => 'success' );
                            } else {
                                return array( 'error' => 'Save icon file failed.' );
                            }
                        }
                        return array( 'undefined_error' => true );
                    } else {
                        return array( 'error' => esc_html__( 'Download icon file failed', 'mainwp' ) );
                    }
                } else {
                    return array( 'undefined_error' => true );
                }
            }
        }
        return array( 'result' => 'NOSITE' );
    }
}
