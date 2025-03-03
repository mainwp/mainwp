<?php
/**
 * MainWP Updates Handler.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Updates_Handler
 *
 * @package MainWP\Dashboard
 */
class MainWP_Updates_Handler { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Get Class Name
     *
     * @return string __CLASS__
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Method upgrade_site()
     *
     * Check Child Site ID & Update.
     *
     * @param int $id Child site ID.
     *
     * @return string
     * @throws MainWP_Exception Error messages.
     *
     * @uses \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
     * @uses \MainWP\Dashboard\MainWP_Exception
     * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     */
    public static function upgrade_site( $id ) { // phpcs:ignore -- NOSONAR - complex.
        if ( isset( $id ) && MainWP_Utility::ctype_digit( $id ) ) {

            $website = MainWP_DB::instance()->get_website_by_id( $id );

            if ( MainWP_System_Utility::is_suspended_site( $website ) ) {
                throw new MainWP_Exception( 'ERROR', '<i class="pause circular yellow inverted icon"></i> ' . esc_html__( 'Suspended site.', 'mainwp' ) ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }

            $information = static::upgrade_website( $website );

            if ( is_array( $information ) ) {
                if ( isset( $information['upgrade'] ) && 'SUCCESS' === $information['upgrade'] ) {
                    MainWP_DB::instance()->update_website_option( $website, 'wp_upgrades', wp_json_encode( array() ) );
                    do_action( 'mainwp_after_upgrade_wp_success', $website, $information );
                    // Get Icon for the largest change scope of ext regression testing.
                    $html_regression_icon = apply_filters( 'mainwp_html_regression_largest_change_scope', $website->id, true ) ?: '';  // phpcs:ignore -- NOSONAR 

                    return '<i class="green check icon"></i>' . $html_regression_icon;
                } elseif ( isset( $information['upgrade'] ) ) {
                    $errorMsg = '';
                    if ( 'LOCALIZATION' === $information['upgrade'] ) {
                        $errorMsg = '<i class="red times icon"></i> ' . esc_html__( 'No update found for the set locale.', 'mainwp' );
                    } elseif ( 'NORESPONSE' === $information['upgrade'] ) {
                        $errorMsg = '<i class="red times icon"></i> ' . esc_html__( 'No response from the child site server.', 'mainwp' );
                    }
                    throw new MainWP_Exception( 'WPERROR', $errorMsg ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                } elseif ( isset( $information['error'] ) ) {
                    throw new MainWP_Exception( 'WPERROR', esc_html( $information['error'] ) );
                } else {
                    throw new MainWP_Exception( 'ERROR', '<i class="red times icon"></i> ' . esc_html__( 'Invalid response from child site.', 'mainwp' ) );
                }
            }
        }

        throw new MainWP_Exception( 'ERROR', '<i class="red times icon"></i> ' . esc_html__( 'Site ID not found. Please reload the page and try again.', 'mainwp' ) );
    }

    /**
     * Method upgrade_website()
     *
     * Update WP for site.
     *
     * @param object $website Child site object.
     *
     * @return mixed|false update result or false.
     * @throws MainWP_Exception Error message.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
     */
    public static function upgrade_website( $website ) { // phpcs:ignore -- NOSONAR - complex.
        if ( MainWP_System_Utility::can_edit_website( $website ) ) {

            $wpcore_update_disabled_by = MainWP_System_Utility::disabled_wpcore_update_by( $website );
            if ( ! empty( $wpcore_update_disabled_by ) ) {
                throw new MainWP_Exception( 'ERROR', $wpcore_update_disabled_by ); //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            }
            /**
            * Action: mainwp_before_wp_update
            *
            * Fires before WP update.
            *
            * @since 4.1
            */
            do_action( 'mainwp_before_wp_update', $website );

            $information = MainWP_Connect::fetch_url_authed( $website, 'upgrade' );

            // logging feature.
            $error   = '';
            $success = false;

            if ( is_array( $information ) ) {
                if ( isset( $information['upgrade'] ) && ( 'SUCCESS' === $information['upgrade'] ) ) {
                    $success = true;
                } elseif ( isset( $information['upgrade'] ) ) {
                    if ( 'LOCALIZATION' === $information['upgrade'] ) {
                        $error = esc_html__( 'No update found for the set locale.', 'mainwp' );
                    } elseif ( 'NORESPONSE' === $information['upgrade'] ) {
                        $error = esc_html__( 'No response from the child site server.', 'mainwp' );
                    }
                } elseif ( isset( $information['error'] ) ) {
                    $error = esc_html( $information['error'] );
                } else {
                    $error = esc_html__( 'Invalid response from child site.', 'mainwp' );
                }
            }

            $output_array = array(
                'old_version' => isset( $information['old_version'] ) ? $information['old_version'] : '',
                'version'     => isset( $information['version'] ) ? $information['version'] : '',
                'success'     => $success ? 1 : 0,
                'error'       => $error,
            );
            mainwp_get_actions_handler_instance()->do_action_mainwp_install_actions( $website, 'updated', $output_array, 'core' );

            /**
            * Action: mainwp_after_wp_update
            *
            * Fires after WP update.
            *
            * @since 4.1
            */
            do_action( 'mainwp_after_wp_update', $information, $website );

            return $information;
        }
        return false;
    }

    /**
     * Add a plugin or theme to the ignor list.
     *
     * @param mixed  $type plugin|theme.
     * @param mixed  $slug Plugin or Theme Slug.
     * @param mixed  $name Plugin or Theme Name.
     * @param mixed  $id Child Site ID.
     * @param string $ver version ignore.
     *
     * @return string success.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses \MainWP\Dashboard\MainWP_DB::update_website_values()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
     * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     */
    public static function ignore_plugin_theme( $type, $slug, $name, $id, $ver = '' ) { // phpcs:ignore -- NOSONAR - complex.
        if ( isset( $id ) && MainWP_Utility::ctype_digit( $id ) ) {
            $website = MainWP_DB::instance()->get_website_by_id( $id );
            if ( MainWP_System_Utility::can_edit_website( $website ) ) {
                $slug = urldecode( $slug );
                if ( 'plugin' === $type ) {
                    $decodedIgnoredPlugins = json_decode( $website->ignored_plugins, true );
                    /**
                    * Action: mainwp_before_plugin_ignore
                    *
                    * Fires before plugin ignore.
                    *
                    * @since 4.1
                    */
                    do_action( 'mainwp_before_plugin_ignore', $decodedIgnoredPlugins, $website );

                    $ignored_info = is_array( $decodedIgnoredPlugins ) && isset( $decodedIgnoredPlugins[ $slug ] ) ? $decodedIgnoredPlugins[ $slug ] : array();
                    if ( ! is_array( $ignored_info ) ) {
                        $ignored_info = array();
                    }

                    $ignored_info['Name'] = urldecode( $name );

                    $ignored_vers = is_array( $ignored_info ) && isset( $ignored_info['ignored_versions'] ) ? $ignored_info['ignored_versions'] : array();
                    if ( ! is_array( $ignored_vers ) ) {
                        $ignored_vers = array();
                    }
                    if ( empty( $ver ) || 'all_versions' === $ver ) { // ignore all.
                        $ignored_vers = array( 'all_versions' );
                    } else {
                        $ver = urldecode( $ver );
                        if ( ! in_array( $ver, $ignored_vers ) ) {
                            $ignored_vers[] = $ver;
                        }
                    }
                    $ignored_info['ignored_versions'] = $ignored_vers;

                    $decodedIgnoredPlugins[ $slug ] = $ignored_info;

                    MainWP_DB::instance()->update_website_values( $website->id, array( 'ignored_plugins' => wp_json_encode( $decodedIgnoredPlugins ) ) );

                    /**
                    * Action: mainwp_after_plugin_ignore
                    *
                    * Fires after plugin ignore.
                    *
                    * @since 4.1
                    */
                    do_action( 'mainwp_after_plugin_ignore', $decodedIgnoredPlugins, $website );

                } elseif ( 'theme' === $type ) {
                    $decodedIgnoredThemes = json_decode( $website->ignored_themes, true );

                    /**
                    * Action: mainwp_before_theme_ignore
                    *
                    * Fires before theme ignore.
                    *
                    * @since 4.1
                    */
                    do_action( 'mainwp_before_theme_ignore', $decodedIgnoredThemes, $website );

                    $ignored_info = is_array( $decodedIgnoredThemes ) && isset( $decodedIgnoredThemes[ $slug ] ) ? $decodedIgnoredThemes[ $slug ] : array();
                    if ( ! is_array( $ignored_info ) ) {
                        $ignored_info = array();
                    }
                    $ignored_info['Name'] = urldecode( $name );

                    $ignored_vers = is_array( $ignored_info ) && isset( $ignored_info['ignored_versions'] ) ? $ignored_info['ignored_versions'] : array();
                    if ( ! is_array( $ignored_vers ) ) {
                        $ignored_vers = array();
                    }
                    if ( empty( $ver ) || 'all_versions' === $ver ) { // ignore all.
                        $ignored_vers = array( 'all_versions' );
                    } else {
                        $ver = urldecode( $ver );
                        if ( ! in_array( $ver, $ignored_vers ) ) {
                            $ignored_vers[] = $ver;
                        }
                    }
                    $ignored_info['ignored_versions'] = $ignored_vers;

                    $decodedIgnoredThemes[ $slug ] = $ignored_info;
                    MainWP_DB::instance()->update_website_values( $website->id, array( 'ignored_themes' => wp_json_encode( $decodedIgnoredThemes ) ) );
                    /**
                    * Action: mainwp_after_theme_ignore
                    *
                    * Fires after theme ignore.
                    *
                    * @since 4.1
                    */
                    do_action( 'mainwp_after_theme_ignore', $website, $decodedIgnoredThemes );
                }
            }
        }
        return 'success';
    }


    /**
     * Remove a plugin or theme from the ignore list.
     *
     * @param mixed  $type plugin|theme.
     * @param mixed  $slug Plugin or Theme slug.
     * @param mixed  $id Plugin or Theme name.
     * @param string $ver Plugin or Theme version.
     *
     * @return string success.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::query()
     * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_for_current_user()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses \MainWP\Dashboard\MainWP_DB::update_website_values()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_DB::free_result()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
     * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     */
    public static function unignore_plugin_theme( $type, $slug, $id, $ver ) { // phpcs:ignore -- NOSONAR - complex.
        if ( ! empty( $id ) ) {

            /**
            * Action: mainwp_before_plugin_theme_unignore
            *
            * Fires after plugin/theme unignore.
            *
            * @since 4.1
            */
            do_action( 'mainwp_before_plugin_theme_unignore', $type, $slug, $id );

            if ( '_ALL_' === $id ) {
                $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );
                while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {
                    if ( 'plugin' === $type ) {

                        /**
                        * Action: mainwp_before_plugin_unignore
                        *
                        * Fires before plugin unignore.
                        *
                        * @since 4.1
                        */
                        do_action( 'mainwp_before_plugin_unignore', array(), $website );

                        MainWP_DB::instance()->update_website_values( $website->id, array( 'ignored_plugins' => wp_json_encode( array() ) ) );

                        /**
                        * Action: mainwp_after_plugin_unignore
                        *
                        * Fires after plugin unignore.
                        *
                        * @since 4.1
                        */
                        do_action( 'mainwp_after_plugin_unignore', array(), $website );

                    } elseif ( 'theme' === $type ) {
                        /**
                        * Action: mainwp_before_theme_unignore
                        *
                        * Fires before theme unignore.
                        *
                        * @since 4.1
                        */
                        do_action( 'mainwp_before_theme_unignore', array(), $website );
                        MainWP_DB::instance()->update_website_values( $website->id, array( 'ignored_themes' => wp_json_encode( array() ) ) );

                        /**
                        * Action: mainwp_after_theme_unignore
                        *
                        * Fires after theme unignore.
                        *
                        * @since 4.1
                        */
                        do_action( 'mainwp_after_theme_unignore', array(), $website );
                    }
                }
                MainWP_DB::free_result( $websites );
            } elseif ( MainWP_Utility::ctype_digit( $id ) ) {
                $website = MainWP_DB::instance()->get_website_by_id( $id );
                if ( MainWP_System_Utility::can_edit_website( $website ) ) {
                    $slug = urldecode( $slug );
                    if ( 'plugin' === $type ) {
                        $decodedIgnoredPlugins = json_decode( $website->ignored_plugins, true );
                        if ( isset( $decodedIgnoredPlugins[ $slug ] ) ) {
                            /**
                            * Action: mainwp_before_plugin_unignore
                            *
                            * Fires before plugin unignore.
                            *
                            * @since 4.1
                            */
                            do_action( 'mainwp_before_plugin_unignore', $decodedIgnoredPlugins, $website );

                            if ( is_string( $decodedIgnoredPlugins[ $slug ] ) ) { // old ignored info.
                                unset( $decodedIgnoredPlugins[ $slug ] );
                            } elseif ( is_array( $decodedIgnoredPlugins[ $slug ] ) ) {
                                $ignored_info = $decodedIgnoredPlugins[ $slug ];
                                $ignored_vers = is_array( $ignored_info ) && isset( $ignored_info['ignored_versions'] ) ? $ignored_info['ignored_versions'] : array();

                                if ( ! is_array( $ignored_vers ) ) {
                                    $ignored_vers = array();
                                }

                                if ( empty( $ver ) || 'all_versions' === $ver ) { // ignore all.
                                    unset( $decodedIgnoredPlugins[ $slug ] );
                                } else {
                                    $ver = urldecode( $ver );
                                    if ( in_array( $ver, $ignored_vers ) ) {
                                        $ignored_vers = array_diff( $ignored_vers, array( $ver ) );
                                    }
                                    $ignored_info['ignored_versions'] = $ignored_vers;
                                    if ( empty( $ignored_vers ) ) {
                                        unset( $decodedIgnoredPlugins[ $slug ] );
                                    } else {
                                        $decodedIgnoredPlugins[ $slug ] = $ignored_info;
                                    }
                                }
                            }
                            MainWP_DB::instance()->update_website_values( $website->id, array( 'ignored_plugins' => wp_json_encode( $decodedIgnoredPlugins ) ) );
                            /**
                            * Action: mainwp_after_plugin_unignore
                            *
                            * Fires after plugin unignore.
                            *
                            * @since 4.1
                            */
                            do_action( 'mainwp_after_plugin_unignore', $decodedIgnoredPlugins, $website );
                        }
                    } elseif ( 'theme' === $type ) {
                        $decodedIgnoredThemes = json_decode( $website->ignored_themes, true );
                        if ( isset( $decodedIgnoredThemes[ $slug ] ) ) {

                            /**
                            * Action: mainwp_before_theme_unignore
                            *
                            * Fires before theme unignore.
                            *
                            * @since 4.1
                            */
                            do_action( 'mainwp_before_theme_unignore', $decodedIgnoredThemes, $website );

                            if ( is_string( $decodedIgnoredThemes[ $slug ] ) ) { // old ignored info.
                                unset( $decodedIgnoredThemes[ $slug ] );
                            } elseif ( is_array( $decodedIgnoredThemes[ $slug ] ) ) {
                                $ignored_info = $decodedIgnoredThemes[ $slug ];
                                $ignored_vers = is_array( $ignored_info ) && isset( $ignored_info['ignored_versions'] ) ? $ignored_info['ignored_versions'] : array();

                                if ( ! is_array( $ignored_vers ) ) {
                                    $ignored_vers = array();
                                }

                                if ( empty( $ver ) || 'all_versions' === $ver ) { // ignore all.
                                    unset( $decodedIgnoredThemes[ $slug ] );
                                } else {
                                    $ver = urldecode( $ver );
                                    if ( in_array( $ver, $ignored_vers ) ) {
                                        $ignored_vers = array_diff( $ignored_vers, array( $ver ) );
                                    }
                                    $ignored_info['ignored_versions'] = $ignored_vers;
                                    if ( empty( $ignored_vers ) ) {
                                        unset( $decodedIgnoredThemes[ $slug ] );
                                    } else {
                                        $decodedIgnoredThemes[ $slug ] = $ignored_info;
                                    }
                                }
                            }

                            MainWP_DB::instance()->update_website_values( $website->id, array( 'ignored_themes' => wp_json_encode( $decodedIgnoredThemes ) ) );

                            /**
                            * Action: mainwp_after_theme_unignore
                            *
                            * Fires after theme unignore.
                            *
                            * @since 4.1
                            */
                            do_action( 'mainwp_after_theme_unignore', $decodedIgnoredThemes, $website );
                        }
                    }
                }
            }
        }

        return 'success';
    }


    /**
     * Remove WP from the ignore list.
     *
     * @param mixed  $id Plugin or Theme name.
     * @param string $ver Plugin or Theme version.
     *
     * @return string success.
     */
    public static function unignore_core_updates( $id, $ver ) { // phpcs:ignore -- NOSONAR - complex.
        if ( ! empty( $id ) ) {

            if ( '_ALL_' === $id ) {
                $params   = array(
                    'view'          => 'updates_view',
                    'others_fields' => array( 'premium_upgrades' ),
                );
                $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user_by_params( $params ) );
                while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {

                    /**
                    * Action: mainwp_before_core_unignore
                    *
                    * Fires before plugin unignore.
                    *
                    * @since 5.2
                    */
                    do_action( 'mainwp_before_core_unignore', '_ALL_', $website );

                    MainWP_DB::instance()->update_website_option( $website->id, 'ignored_wp_upgrades', wp_json_encode( array() ) );
                    /**
                    * Action: mainwp_after_core_unignore
                    *
                    * Fires after plugin unignore.
                    *
                    * @since 5.2
                    */
                    do_action( 'mainwp_before_core_unignore', '_ALL_', $website );

                }
                MainWP_DB::free_result( $websites );
                return 'success';
            } elseif ( MainWP_Utility::ctype_digit( $id ) ) {
                $website = MainWP_DB::instance()->get_website_by_id( $id, false, array( 'ignored_wp_upgrades' ) );
                if ( MainWP_System_Utility::can_edit_website( $website ) ) {
                    $ignored_info = ! empty( $website->ignored_wp_upgrades ) ? json_decode( $website->ignored_wp_upgrades, true ) : array();

                    if ( ! is_array( $ignored_info ) ) {
                        $ignored_info = array();
                    }
                    /**
                    * Action: mainwp_before_core_unignore
                    *
                    * Fires before plugin unignore.
                    *
                    * @since 5.2
                    */
                    do_action( 'mainwp_before_core_unignore', $ignored_info, $website );

                    $ignored_vers = isset( $ignored_info['ignored_versions'] ) ? $ignored_info['ignored_versions'] : array();

                    if ( ! is_array( $ignored_vers ) ) {
                        $ignored_vers = array();
                    }

                    if ( empty( $ver ) || 'all_versions' === $ver ) { // unignore all.
                        $ignored_vers = array();
                    } elseif ( in_array( $ver, $ignored_vers ) ) {
                            $ignored_vers = array_diff( $ignored_vers, array( $ver ) );
                    }
                    $ignored_info['ignored_versions'] = $ignored_vers;
                    MainWP_DB::instance()->update_website_option( $website->id, 'ignored_wp_upgrades', wp_json_encode( $ignored_info ) );
                    /**
                    * Action: mainwp_after_core_unignore
                    *
                    * Fires after plugin unignore.
                    *
                    * @since 5.2
                    */
                    do_action( 'mainwp_after_core_unignore', $ignored_info, $website );
                    return 'success';
                }
            }
        }
        return 'failed';
    }

    /**
     * Unignore Plugins or Themes.
     *
     * @param string $slug _ALL_|empty.
     * @param string $ver version info.
     *
     * @return string success.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Common::get_user_extension()
     * @uses \MainWP\Dashboard\MainWP_DB_Common::update_user_extension()
     */
    public static function unignore_global_cores( $slug, $ver ) { // phpcs:ignore -- NOSONAR - complex.
        $userExtension = MainWP_DB_Common::instance()->get_user_extension();
        if ( '_ALL_' === $slug ) {
            $decodedIgnoredCores = array();
        } else {
            $decodedIgnoredCores = json_decode( $userExtension->ignored_plugins, true );
            if ( ! is_array( $decodedIgnoredCores ) ) {
                $decodedIgnoredCores = array();
            }
            if ( 'all_versions' === $ver ) {
                $decodedIgnoredCores = array();
            } else {
                $ignored_vers = is_array( $decodedIgnoredCores ) && isset( $decodedIgnoredCores['ignored_versions'] ) ? $decodedIgnoredCores['ignored_versions'] : array();
                if ( is_array( $ignored_vers ) && in_array( $ver, $ignored_vers ) ) {
                    $ignored_vers = array_diff( $ignored_vers, array( $ver ) );
                    if ( empty( $ignored_vers ) ) {
                        $decodedIgnoredCores = array();
                    } else {
                        $decodedIgnoredCores['ignored_versions'] = $ignored_vers;
                    }
                }
            }
        }
        MainWP_DB_Common::instance()->update_user_extension(
            array(
                'userid'              => null,
                'ignored_wp_upgrades' => wp_json_encode( $decodedIgnoredCores ),
            )
        );

        return 'success';
    }

    /**
     * Method ignore_plugins_themes()
     *
     * Ignore Plugins or Themes.
     *
     * @param string $type Plugin or theme.
     * @param string $slug Plugin or tempheme slug.
     * @param string $name Plugin or theme name.
     * @param string $ver Plugin or theme version.
     *
     * @return string 'success'.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Common::get_user_extension()
     * @uses \MainWP\Dashboard\MainWP_DB_Common::update_user_extension()
     */
    public static function ignore_plugins_themes( $type, $slug, $name, $ver = '' ) { //phpcs:ignore -- NOSONAR complex function.
        $slug          = urldecode( $slug );
        $userExtension = MainWP_DB_Common::instance()->get_user_extension();
        if ( 'plugin' === $type ) {
            $decodedIgnoredPlugins = json_decode( $userExtension->ignored_plugins, true );
            if ( ! is_array( $decodedIgnoredPlugins ) ) {
                $decodedIgnoredPlugins = array();
            }

            $ignored_info = is_array( $decodedIgnoredPlugins ) && isset( $decodedIgnoredPlugins[ $slug ] ) ? $decodedIgnoredPlugins[ $slug ] : array();
            if ( ! is_array( $ignored_info ) ) {
                $ignored_info = array();
            }

            $ignored_info['Name'] = urldecode( $name );

            $ignored_vers = is_array( $ignored_info ) && isset( $ignored_info['ignored_versions'] ) ? $ignored_info['ignored_versions'] : array();
            if ( ! is_array( $ignored_vers ) ) {
                $ignored_vers = array();
            }

            if ( empty( $ver ) || 'all_versions' === $ver ) { // ignore all.
                $ignored_vers = array( 'all_versions' );
            } else {
                $ver = urldecode( $ver );
                if ( ! in_array( $ver, $ignored_vers ) ) {
                    $ignored_vers[] = $ver;
                }
            }

            $ignored_info['ignored_versions'] = $ignored_vers;
            $decodedIgnoredPlugins[ $slug ]   = $ignored_info;

            MainWP_DB_Common::instance()->update_user_extension(
                array(
                    'userid'          => null,
                    'ignored_plugins' => wp_json_encode( $decodedIgnoredPlugins ),
                )
            );
        } elseif ( 'theme' === $type ) {
            $decodedIgnoredThemes = json_decode( $userExtension->ignored_themes, true );
            if ( ! is_array( $decodedIgnoredThemes ) ) {
                $decodedIgnoredThemes = array();
            }

            $ignored_info = is_array( $decodedIgnoredThemes ) && isset( $decodedIgnoredThemes[ $slug ] ) ? $decodedIgnoredThemes[ $slug ] : array();
            if ( ! is_array( $ignored_info ) ) {
                $ignored_info = array();
            }

            $ignored_info['Name'] = urldecode( $name );

            $ignored_vers = is_array( $ignored_info ) && isset( $ignored_info['ignored_versions'] ) ? $ignored_info['ignored_versions'] : array();
            if ( ! is_array( $ignored_vers ) ) {
                $ignored_vers = array();
            }
            if ( empty( $ver ) || 'all_versions' === $ver ) { // ignore all.
                $ignored_vers = array( 'all_versions' );
            } else {
                $ver = urldecode( $ver );
                if ( ! in_array( $ver, $ignored_vers ) ) {
                    $ignored_vers[] = $ver;
                }
            }
            $ignored_info['ignored_versions'] = $ignored_vers;
            $decodedIgnoredThemes[ $slug ]    = $ignored_info;

            MainWP_DB_Common::instance()->update_user_extension(
                array(
                    'userid'         => null,
                    'ignored_themes' => wp_json_encode( $decodedIgnoredThemes ),
                )
            );
        }

        return 'success';
    }

    /**
     * Unignore Plugins or Themes.
     *
     * @param mixed  $type plugin|themes.
     * @param mixed  $slug Plugin or Themes slug.
     * @param string $ver Plugin or Themes version.
     *
     * @return string success.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Common::get_user_extension()
     * @uses \MainWP\Dashboard\MainWP_DB_Common::update_user_extension()
     */
    public static function unignore_global_plugins_themes( $type, $slug, $ver ) { // phpcs:ignore -- NOSONAR - complex.
        $slug          = urldecode( $slug );
        $userExtension = MainWP_DB_Common::instance()->get_user_extension();
        if ( 'plugin' === $type ) {
            if ( '_ALL_' === $slug ) {
                $decodedIgnoredPlugins = array();
            } else {
                $decodedIgnoredPlugins = json_decode( $userExtension->ignored_plugins, true );
                if ( ! is_array( $decodedIgnoredPlugins ) ) {
                    $decodedIgnoredPlugins = array();
                }
                if ( isset( $decodedIgnoredPlugins[ $slug ] ) ) {
                    if ( 'all_versions' === $ver ) {
                        unset( $decodedIgnoredPlugins[ $slug ] );
                    } else {
                        $ignored_vers = is_array( $decodedIgnoredPlugins[ $slug ] ) && isset( $decodedIgnoredPlugins[ $slug ]['ignored_versions'] ) ? $decodedIgnoredPlugins[ $slug ]['ignored_versions'] : array();
                        if ( is_array( $ignored_vers ) && in_array( $ver, $ignored_vers ) ) {
                            $ignored_vers = array_diff( $ignored_vers, array( $ver ) );
                            if ( empty( $ignored_vers ) ) {
                                unset( $decodedIgnoredPlugins[ $slug ] );
                            } else {
                                $decodedIgnoredPlugins[ $slug ]['ignored_versions'] = $ignored_vers;
                            }
                        }
                    }
                }
            }
            MainWP_DB_Common::instance()->update_user_extension(
                array(
                    'userid'          => null,
                    'ignored_plugins' => wp_json_encode( $decodedIgnoredPlugins ),
                )
            );
        } elseif ( 'theme' === $type ) {
            if ( '_ALL_' === $slug ) {
                $decodedIgnoredThemes = array();
            } else {
                $decodedIgnoredThemes = json_decode( $userExtension->ignored_plugins, true );
                if ( ! is_array( $decodedIgnoredThemes ) ) {
                    $decodedIgnoredThemes = array();
                }
                if ( isset( $decodedIgnoredThemes[ $slug ] ) ) {
                    if ( 'all_versions' === $ver ) {
                        unset( $decodedIgnoredThemes[ $slug ] );
                    } else {
                        $ignored_vers = is_array( $decodedIgnoredThemes[ $slug ] ) && isset( $decodedIgnoredThemes[ $slug ]['ignored_versions'] ) ? $decodedIgnoredThemes[ $slug ]['ignored_versions'] : array();
                        if ( is_array( $ignored_vers ) && in_array( $ver, $ignored_vers ) ) {
                            $ignored_vers = array_diff( $ignored_vers, array( $ver ) );
                            if ( empty( $ignored_vers ) ) {
                                unset( $decodedIgnoredThemes[ $slug ] );
                            } else {
                                $decodedIgnoredThemes[ $slug ]['ignored_versions'] = $ignored_vers;
                            }
                        }
                    }
                }
            }
            MainWP_DB_Common::instance()->update_user_extension(
                array(
                    'userid'         => null,
                    'ignored_themes' => wp_json_encode( $decodedIgnoredThemes ),
                )
            );
        }

        return 'success';
    }


    /**
     * Unignor abandoned plugins or themes.
     *
     * @param mixed $type plugin|themes.
     * @param mixed $slug Plugin or Themes slug.
     * @param mixed $id Child Site ID.
     *
     * @return string success.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::query()
     * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_for_current_user()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
     * @uses \MainWP\Dashboard\MainWP_DB::update_website_option()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_DB::free_result()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
     * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     */
    public static function unignore_abandoned_plugin_theme( $type, $slug, $id ) { // phpcs:ignore -- NOSONAR - complex.
        if ( isset( $id ) ) {
            if ( '_ALL_' === $id ) {
                $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );
                while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {
                    if ( 'plugin' === $type ) {
                        MainWP_DB::instance()->update_website_option( $website, 'plugins_outdate_dismissed', wp_json_encode( array() ) );
                    } elseif ( 'theme' === $type ) {
                        MainWP_DB::instance()->update_website_option( $website, 'themes_outdate_dismissed', wp_json_encode( array() ) );
                    }
                }
                MainWP_DB::free_result( $websites );
            } elseif ( MainWP_Utility::ctype_digit( $id ) ) {
                $website = MainWP_DB::instance()->get_website_by_id( $id );
                if ( MainWP_System_Utility::can_edit_website( $website ) ) {
                    $slug = urldecode( $slug );
                    if ( 'plugin' === $type ) {
                        $decodedIgnoredPlugins = MainWP_DB::instance()->get_website_option( $website, 'plugins_outdate_dismissed' );
                        $decodedIgnoredPlugins = ! empty( $decodedIgnoredPlugins ) ? json_decode( $decodedIgnoredPlugins, true ) : array();

                        if ( isset( $decodedIgnoredPlugins[ $slug ] ) ) {
                            unset( $decodedIgnoredPlugins[ $slug ] );
                            MainWP_DB::instance()->update_website_option( $website, 'plugins_outdate_dismissed', wp_json_encode( $decodedIgnoredPlugins ) );
                        }
                    } elseif ( 'theme' === $type ) {
                        $decodedIgnoredThemes = MainWP_DB::instance()->get_website_option( $website, 'themes_outdate_dismissed' );
                        $decodedIgnoredThemes = ! empty( $decodedIgnoredThemes ) ? json_decode( $decodedIgnoredThemes, true ) : array();

                        if ( isset( $decodedIgnoredThemes[ $slug ] ) ) {
                            unset( $decodedIgnoredThemes[ $slug ] );
                            MainWP_DB::instance()->update_website_option( $website, 'themes_outdate_dismissed', wp_json_encode( $decodedIgnoredThemes ) );
                        }
                    }
                }
            }
        }

        return 'success';
    }


    /**
     * Unignore abandoned plugins or themes.
     *
     * @param mixed $type plugin|theme.
     * @param mixed $slug Plugin or Themes slug.
     *
     * @return string success.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Common::get_user_extension()
     * @uses \MainWP\Dashboard\MainWP_DB_Common::update_user_extension()
     */
    public static function unignore_abandoned_plugins_themes( $type, $slug ) { // phpcs:ignore -- NOSONAR - complex.
        $slug          = urldecode( $slug );
        $userExtension = MainWP_DB_Common::instance()->get_user_extension();
        if ( 'plugin' === $type ) {
            if ( '_ALL_' === $slug ) {
                $decodedIgnoredPlugins = array();
            } else {
                $decodedIgnoredPlugins = json_decode( $userExtension->dismissed_plugins, true );
                if ( ! is_array( $decodedIgnoredPlugins ) ) {
                    $decodedIgnoredPlugins = array();
                }
                if ( isset( $decodedIgnoredPlugins[ $slug ] ) ) {
                    unset( $decodedIgnoredPlugins[ $slug ] );
                }
            }
            MainWP_DB_Common::instance()->update_user_extension(
                array(
                    'userid'            => null,
                    'dismissed_plugins' => wp_json_encode( $decodedIgnoredPlugins ),
                )
            );
        } elseif ( 'theme' === $type ) {
            if ( '_ALL_' === $slug ) {
                $decodedIgnoredThemes = array();
            } else {
                $decodedIgnoredThemes = json_decode( $userExtension->dismissed_themes, true );
                if ( ! is_array( $decodedIgnoredThemes ) ) {
                    $decodedIgnoredThemes = array();
                }
                if ( isset( $decodedIgnoredThemes[ $slug ] ) ) {
                    unset( $decodedIgnoredThemes[ $slug ] );
                }
            }
            MainWP_DB_Common::instance()->update_user_extension(
                array(
                    'userid'           => null,
                    'dismissed_themes' => wp_json_encode( $decodedIgnoredThemes ),
                )
            );
        }

        return 'success';
    }

    /**
     * Dismis Plugin or Theme.
     *
     * @param mixed $type plugin|theme.
     * @param mixed $slug Plugin or Theme slug.
     * @param mixed $name Plugin or Theme name.
     * @param mixed $id Child Site ID.
     *
     * @return string success.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
     * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     */
    public static function dismiss_plugin_theme( $type, $slug, $name, $id ) { // phpcs:ignore -- NOSONAR - complex.
        if ( isset( $id ) && MainWP_Utility::ctype_digit( $id ) ) {
            $website = MainWP_DB::instance()->get_website_by_id( $id );
            if ( MainWP_System_Utility::can_edit_website( $website ) ) {
                $slug = urldecode( $slug );
                if ( 'plugin' === $type ) {
                    $decodedDismissedPlugins = MainWP_DB::instance()->get_website_option( $website, 'plugins_outdate_dismissed' );
                    $decodedDismissedPlugins = ! empty( $decodedDismissedPlugins ) ? json_decode( $decodedDismissedPlugins, true ) : array();

                    if ( ! isset( $decodedDismissedPlugins[ $slug ] ) ) {
                        $decodedDismissedPlugins[ $slug ] = urldecode( $name );
                        MainWP_DB::instance()->update_website_option( $website, 'plugins_outdate_dismissed', wp_json_encode( $decodedDismissedPlugins ) );
                    }
                } elseif ( 'theme' === $type ) {
                    $decodedDismissedThemes = MainWP_DB::instance()->get_website_option( $website, 'themes_outdate_dismissed' );
                    $decodedDismissedThemes = ! empty( $decodedDismissedThemes ) ? json_decode( $decodedDismissedThemes, true ) : array();

                    if ( ! isset( $decodedDismissedThemes[ $slug ] ) ) {
                        $decodedDismissedThemes[ $slug ] = urldecode( $name );
                        MainWP_DB::instance()->update_website_option( $website, 'themes_outdate_dismissed', wp_json_encode( $decodedDismissedThemes ) );
                    }
                }
            }
        }

        return 'success';
    }

    /**
     * Dismiss plugins or themes.
     *
     * @param mixed $type plugin|theme.
     * @param mixed $slug Plugin or Theme slug.
     * @param mixed $name Plugin or Theme name.
     *
     * @return string success.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Common::get_user_extension()
     * @uses \MainWP\Dashboard\MainWP_DB_Common::update_user_extension()
     */
    public static function dismiss_plugins_themes( $type, $slug, $name ) {
        $slug          = urldecode( $slug );
        $userExtension = MainWP_DB_Common::instance()->get_user_extension();
        if ( 'plugin' === $type ) {
            $decodedDismissedPlugins = json_decode( $userExtension->dismissed_plugins, true );
            if ( ! is_array( $decodedDismissedPlugins ) ) {
                $decodedDismissedPlugins = array();
            }
            $decodedDismissedPlugins[ $slug ] = urldecode( $name );
            MainWP_DB_Common::instance()->update_user_extension(
                array(
                    'userid'            => null,
                    'dismissed_plugins' => wp_json_encode( $decodedDismissedPlugins ),
                )
            );
        } elseif ( 'theme' === $type ) {
            $decodedDismissedThemes = json_decode( $userExtension->dismissed_themes, true );
            if ( ! is_array( $decodedDismissedThemes ) ) {
                $decodedDismissedThemes = array();
            }
            $decodedDismissedThemes[ $slug ] = urldecode( $name );
            MainWP_DB_Common::instance()->update_user_extension(
                array(
                    'userid'           => null,
                    'dismissed_themes' => wp_json_encode( $decodedDismissedThemes ),
                )
            );
        }

        return 'success';
    }

    /**
     * Method upgrade_plugin_theme_translation()
     *
     * Upgrade plugin or theme translations.
     *
     * @param int    $id   Child site ID.
     * @param string $type Plugin or theme.
     * @param array  $list_items List of theme or plugin names seperated by comma.
     *
     * @return array
     * @throws MainWP_Exception Error messages.
     *
     * @uses \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses \MainWP\Dashboard\MainWP_Exception
     * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     */
    public static function upgrade_plugin_theme_translation( $id, $type, $list_items ) { // phpcs:ignore -- NOSONAR - complex method.
        if ( isset( $id ) && MainWP_Utility::ctype_digit( $id ) ) {
            $website = MainWP_DB::instance()->get_website_by_id( $id, false, array( 'premium_upgrades', 'rollback_updates_data' ) ); // to fix loading premium_upgrades.
            if ( MainWP_System_Utility::is_suspended_site( $website ) ) {
                throw new MainWP_Exception( 'ERROR', esc_html__( 'Suspended site.', 'mainwp' ), 'SUSPENDED_SITE' );
            }

            $result = static::update_plugin_theme_translation( $website, $type, $list_items );
            if ( is_array( $result ) ) {

                $return_results = array();

                // logging feature.
                $_type = '';
                if ( 'plugin' === $type || 'theme' === $type ) {
                    $_type = $type;
                } elseif ( 'translation' === $type ) {
                    $_type = 'trans';
                }

                $undefined = true;

                if ( isset( $result['upgrades_error'] ) ) {
                    foreach ( $result['upgrades_error'] as $k => $v ) {
                        $return_results['result_error'][ rawurlencode( $k ) ] = esc_html( $v );
                    }
                }

                if ( ! empty( $_type ) && isset( $result['other_data'] ) ) { // ok.
                    $output_array = $result['other_data']; // updated_data: plugins,themes,trans.
                    mainwp_get_actions_handler_instance()->do_action_mainwp_install_actions( $website, 'updated', $output_array, $_type );
                    if ( is_array( $output_array ) ) {
                        $updated_data = isset( $output_array['updated_data'] ) ? $output_array['updated_data'] : array();
                        if ( is_array( $updated_data ) ) {

                            $saved_roll_items = ! empty( $website->rollback_updates_data ) ? json_decode( $website->rollback_updates_data, true ) : array();
                            if ( ! is_array( $saved_roll_items ) ) {
                                $saved_roll_items = array();
                            }

                            $update = false;
                            foreach ( $updated_data as $item ) {
                                $version = isset( $item['version'] ) ? $item['version'] : '';
                                if ( ! empty( $item ) && isset( $item['slug'] ) && ! empty( $item['rollback'] ) ) {
                                    $msg = MainWP_Updates_Helper::get_roll_msg( $item );
                                    $return_results['result_error'][ rawurlencode( $item['slug'] ) ] = '[Roll]' . esc_html( $msg );

                                    $name        = isset( $item['name'] ) ? $item['name'] : '';
                                    $old_version = isset( $item['old_version'] ) ? $item['old_version'] : '';

                                    if ( ! empty( $version ) ) {
                                        if ( ! isset( $saved_roll_items[ $_type ] ) ) {
                                            $saved_roll_items[ $_type ] = array();
                                        }

                                        $saved_item = array(
                                            'name'        => $name,
                                            'old_version' => $old_version,
                                            'version'     => $version,
                                            'created'     => time(),
                                        );

                                        if ( ! empty( $item['error'] ) ) {
                                            $saved_item['error'] = $item['error'];
                                        }

                                        $saved_roll_items[ $_type ][ $item['slug'] ][ $version ] = $saved_item;
                                        $update = true;
                                    }
                                } elseif ( ! empty( $item ) && ! empty( $item['slug'] ) && ! empty( $item['success'] ) ) {
                                    if ( ! empty( $version ) && isset( $saved_roll_items[ $_type ][ $item['slug'] ][ $version ] ) ) {
                                        unset( $saved_roll_items[ $_type ][ $item['slug'] ][ $version ] );
                                        $update = true;
                                    }
                                }
                            }
                            if ( $update ) {
                                MainWP_DB::instance()->update_website_option( $website, 'rollback_updates_data', wp_json_encode( $saved_roll_items ) );
                            }
                        }
                    }
                }

                if ( isset( $result['upgrades'] ) ) {
                    if ( isset( $result['upgrades'] ) ) {
                        foreach ( $result['upgrades'] as $k => $v ) {
                            if ( ! empty( $v ) ) { // updated success.
                                $return_results['result'][ rawurlencode( $k ) ] = $v;
                            }
                        }
                    }

                    if ( 'plugin' === $type ) {
                        $website = MainWP_DB::instance()->get_website_by_id( $id, false ); // Call new data the before a updated.
                        if ( ! empty( $website->plugin_upgrades ) ) {
                            $plugin_upgrades = json_decode( $website->plugin_upgrades, true );
                            if ( is_array( $plugin_upgrades ) ) {
                                $updated = false;
                                if ( isset( $return_results['result'] ) && is_array( $return_results['result'] ) && ! empty( $return_results['result'] ) ) {
                                    foreach ( $return_results['result'] as $k => $v ) {
                                        $k = rawurldecode( $k );
                                        if ( isset( $plugin_upgrades[ $k ] ) ) {
                                            unset( $plugin_upgrades[ $k ] ); // updated.
                                            $updated = true;
                                        }
                                    }
                                }
                                if ( $updated ) {
                                    MainWP_DB::instance()->update_website_values( $id, array( 'plugin_upgrades' => wp_json_encode( $plugin_upgrades ) ) );
                                }
                            }
                        }

                        if ( isset( $return_results['result'] ) && is_array( $return_results['result'] ) && ! empty( $return_results['result'] ) ) {
                            $decodedPremiumUpgrades = MainWP_DB::instance()->get_website_option( $website, 'premium_upgrades' );
                            $decodedPremiumUpgrades = ! empty( $decodedPremiumUpgrades ) ? json_decode( $decodedPremiumUpgrades, true ) : array();
                            if ( is_array( $decodedPremiumUpgrades ) && ! empty( $decodedPremiumUpgrades ) ) {
                                $updated = false;
                                foreach ( $return_results['result'] as $k => $v ) {
                                    $k = rawurldecode( $k );
                                    if ( isset( $decodedPremiumUpgrades[ $k ] ) ) {
                                        unset( $decodedPremiumUpgrades[ $k ] ); // updated.
                                        $updated = true;
                                    }
                                }
                                if ( $updated ) {
                                    MainWP_DB::instance()->update_website_option( $website, 'premium_upgrades', wp_json_encode( $decodedPremiumUpgrades ) );
                                }
                            }
                        }
                    }
                    return $return_results;
                } elseif ( isset( $result['error'] ) ) {
                    throw new MainWP_Exception( 'WPERROR', esc_html( $result['error'] ) );
                } elseif ( isset( $result['notices'] ) ) {
                    $noti = $result['notices'];
                    if ( ! empty( $noti ) && is_string( $noti ) ) {
                        throw new MainWP_Exception( $noti, '', 'MAINWP_NOTICE' );  //phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                    }
                }

                if ( $undefined ) {
                    throw new MainWP_Exception( 'ERROR', esc_html__( 'Invalid response retured from the child site. Please try again.', 'mainwp' ) );
                }
            }
        }
        throw new MainWP_Exception( 'ERROR', esc_html__( 'Site ID not found. Please reload the page and try again.', 'mainwp' ) );
    }

    /**
     * Method update_plugin_theme_translation()
     *
     * Upgrade plugin or theme translations.
     *
     * @param int    $website Child site object.
     * @param string $type    Plugin or theme.
     * @param array  $list_items    List of theme or plugin names seperated by comma.
     *
     * @return array|false update result or false.
     * @throws \MainWP_Exception Error message.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Utility
     * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
     */
    public static function update_plugin_theme_translation( $website, $type, $list_items ) {
        if ( MainWP_System_Utility::can_edit_website( $website ) ) {
            /**
            * Action: mainwp_before_plugin_theme_translation_update
            *
            * Fires before plugin/theme/translation update actions.
            *
            * @since 4.1
            */
            do_action( 'mainwp_before_plugin_theme_translation_update', $type, $list_items, $website );

            $information = MainWP_Connect::fetch_url_authed(
                $website,
                ( 'translation' === $type ? 'upgradetranslation' : 'upgradeplugintheme' ),
                array(
                    'type' => $type,
                    'list' => urldecode( $list_items ),
                ),
                true
            );
            /**
            * Action: mainwp_after_plugin_theme_translation_update
            *
            * Fires before plugin/theme/translation update actions.
            *
            * @since 4.1
            */
            do_action( 'mainwp_after_plugin_theme_translation_update', $information, $type, $list_items, $website );

            return $information;
        }
        return false;
    }

    /**
     * Get plugin or theme slugs.
     *
     * @param int    $id Child Site ID.
     * @param string $type plugin|theme.
     *
     * @return array List of plugins or themes.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Common::get_user_extension()
     * @uses \MainWP\Dashboard\MainWP_DB::query()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     */
    public static function get_plugin_theme_slugs( $id, $type ) { // phpcs:ignore -- NOSONAR - complex method. Current complexity is the only way to achieve desired results, pull request solutions appreciated.

        $userExtension = MainWP_DB_Common::instance()->get_user_extension();
        $sql           = MainWP_DB::instance()->get_sql_website_by_id( $id );
        $websites      = MainWP_DB::instance()->query( $sql );
        $website       = MainWP_DB::fetch_object( $websites );

        $slugs = array();
        if ( 'plugin' === $type ) {
            if ( $website->is_ignorePluginUpdates ) {
                return '';
            }

            $plugin_upgrades        = json_decode( $website->plugin_upgrades, true );
            $decodedPremiumUpgrades = MainWP_DB::instance()->get_website_option( $website, 'premium_upgrades' );
            $decodedPremiumUpgrades = ! empty( $decodedPremiumUpgrades ) ? json_decode( $decodedPremiumUpgrades, true ) : array();

            if ( is_array( $decodedPremiumUpgrades ) ) {
                foreach ( $decodedPremiumUpgrades as $crrSlug => $premiumUpgrade ) {
                    $premiumUpgrade['premium'] = true;

                    if ( 'plugin' === $premiumUpgrade['type'] ) {
                        if ( ! is_array( $plugin_upgrades ) ) {
                            $plugin_upgrades = array();
                        }
                        $premiumUpgrade              = array_filter( $premiumUpgrade );
                        $plugin_upgrades[ $crrSlug ] = array_merge( $plugin_upgrades[ $crrSlug ], $premiumUpgrade );
                    }
                }
            }

            $ignored_plugins = json_decode( $website->ignored_plugins, true );
            if ( is_array( $ignored_plugins ) ) {
                $plugin_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $plugin_upgrades, $ignored_plugins );

            }

            $ignored_plugins = json_decode( $userExtension->ignored_plugins, true );
            if ( is_array( $ignored_plugins ) ) {
                $plugin_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $plugin_upgrades, $ignored_plugins );

            }

            if ( is_array( $plugin_upgrades ) ) {
                foreach ( $plugin_upgrades as $slug => $plugin_upgrade ) {
                    $slugs[] = rawurlencode( $slug );
                }
            }
        } elseif ( 'theme' === $type ) {

            if ( $website->is_ignoreThemeUpdates ) {
                return '';
            }

            $theme_upgrades         = json_decode( $website->theme_upgrades, true );
            $decodedPremiumUpgrades = MainWP_DB::instance()->get_website_option( $website, 'premium_upgrades' );
            $decodedPremiumUpgrades = ! empty( $decodedPremiumUpgrades ) ? json_decode( $decodedPremiumUpgrades, true ) : array();

            if ( is_array( $decodedPremiumUpgrades ) ) {
                foreach ( $decodedPremiumUpgrades as $crrSlug => $premiumUpgrade ) {
                    $premiumUpgrade['premium'] = true;

                    if ( 'theme' === $premiumUpgrade['type'] ) {
                        if ( ! is_array( $theme_upgrades ) ) {
                            $theme_upgrades = array();
                        }
                        $theme_upgrades[ $crrSlug ] = $premiumUpgrade;
                    }
                }
            }

            $ignored_themes = json_decode( $website->ignored_themes, true );
            if ( is_array( $ignored_themes ) ) {
                $theme_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $theme_upgrades, $ignored_themes );
            }

            $ignored_themes = json_decode( $userExtension->ignored_themes, true );
            if ( is_array( $ignored_themes ) ) {
                $theme_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $theme_upgrades, $ignored_themes );
            }

            if ( is_array( $theme_upgrades ) ) {
                foreach ( $theme_upgrades as $slug => $theme_upgrade ) {
                    $slugs[] = $slug;
                }
            }
        } elseif ( 'translation' === $type ) {
            $translation_upgrades = json_decode( $website->translation_upgrades, true );
            if ( is_array( $translation_upgrades ) ) {
                foreach ( $translation_upgrades as $translation_upgrade ) {
                    $slugs[] = $translation_upgrade['slug'];
                }
            }
        }

        return implode( ',', $slugs );
    }
}
