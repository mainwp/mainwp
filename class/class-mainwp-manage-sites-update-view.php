<?php
/**
 * MainWP Manage Sites Update View.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Manage_Sites_Update_View
 *
 * @package MainWP\Dashboard
 */
class MainWP_Manage_Sites_Update_View { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Method render_updates()
     *
     * If empty do nothing else grab the Child Sites ID and pass it to
     * method render_individual_updates().
     *
     * @param mixed $website Child Site Info.
     *
     * @return mixed
     */
    public static function render_updates( $website ) {
        if ( empty( $website ) ) {
            return;
        }

        $website_id = $website->id;
        static::render_individual_updates( $website_id );
    }

    /**
     * Method render_individual_updates()
     *
     * Render Plugin updates Tab.
     *
     * @param mixed $id Child Site ID.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Common::get_user_extension()
     * @uses \MainWP\Dashboard\MainWP_DB::get_sql_website_by_id()
     * @uses \MainWP\Dashboard\MainWP_DB::query()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_Manage_Sites_View::render_header_tabs()
     */
    public static function render_individual_updates( $id ) {

        /**
         * Current user global.
         *
         * @global string
         */
        global $current_user;
        $userExtension = MainWP_DB_Common::instance()->get_user_extension();
        $sql           = MainWP_DB::instance()->get_sql_website_by_id( $id, false, array( 'wp_upgrades', 'ignored_wp_upgrades', 'premium_upgrades', 'plugins_outdate_dismissed', 'themes_outdate_dismissed', 'plugins_outdate_info', 'themes_outdate_info', 'favi_icon' ) );
        $websites      = MainWP_DB::instance()->query( $sql );

        MainWP_DB::data_seek( $websites, 0 );
        if ( $websites ) {
            $website = MainWP_DB::fetch_object( $websites );
        }

        $mainwp_show_language_updates = get_option( 'mainwp_show_language_updates', 1 );

        $active_tab  = 'plugins';
        $active_text = esc_html__( 'Plugin Updates', 'mainwp' );
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( isset( $_GET['tab'] ) ) {
            if ( 'wordpress-updates' === $_GET['tab'] ) {
                $active_tab  = 'wordpress';
                $active_text = esc_html__( 'WordPress Updates', 'mainwp' );
            } elseif ( 'themes-updates' === $_GET['tab'] ) {
                $active_tab  = 'themes';
                $active_text = esc_html__( 'Theme Updates', 'mainwp' );
            } elseif ( 'translations-updates' === $_GET['tab'] ) {
                $active_tab  = 'trans';
                $active_text = esc_html__( 'Translation Updates', 'mainwp' );
            } elseif ( 'abandoned-plugins' === $_GET['tab'] ) {
                $active_tab  = 'abandoned-plugins';
                $active_text = esc_html__( 'Abandoned Plugins', 'mainwp' );
            } elseif ( 'abandoned-themes' === $_GET['tab'] ) {
                $active_tab  = 'abandoned-themes';
                $active_text = esc_html__( 'Abandoned Themes', 'mainwp' );
            }
        }
        // phpcs:enable
        MainWP_Manage_Sites_View::render_header_tabs( $active_tab, $active_text, $mainwp_show_language_updates )
        ?>
        <div class="ui segment" id="mainwp-manage-<?php echo intval( $id ); ?>-updates">
            <?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-manage-updates-site-message' ) ) : ?>
                <div class="ui info message">
                    <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-manage-updates-site-message"></i>
                    <div><?php printf( esc_html__( 'Manage available updates for the child site. From here, you can update update %1$splugins%2$s, %3$sthemes%4$s, and %5$sWordPress core%6$s.', 'mainwp' ), '<a href="https://mainwp.com/kb/update-plugins/" target="_blank">', '</a> <i class="external alternate icon"></i>', '<a href="https://mainwp.com/kb/update-themes/" target="_blank">', '</a> <i class="external alternate icon"></i>', '<a href="https://mainwp.com/kb/update-wordpress-core/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); // NOSONAR - noopener - open safe. ?></div>
                    <div><?php printf( esc_html__( 'Also, from here, you can ignore updates for %1$sWordPress core%2$s, %3$splugins%4$s, and %5$sthemes%6$s.', 'mainwp' ), '<a href="https://mainwp.com/kb/ignore-wordpress-core-update/" target="_blank">', '</a> <i class="external alternate icon"></i>', '<a href="https://mainwp.com/kb/ignore-plugin-updates/" target="_blank">', '</a> <i class="external alternate icon"></i>', '<a href="https://mainwp.com/kb/ignore-theme-updates/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); // NOSONAR - noopener - open safe. ?></div>
                </div>
            <?php endif; ?>
            <?php
            static::render_wpcore_updates( $website, $active_tab, $userExtension );
            static::render_plugins_updates( $website, $active_tab, $userExtension );
            static::render_themes_updates( $website, $active_tab, $userExtension );

            if ( $mainwp_show_language_updates ) :
                static::render_language_updates( $website, $active_tab, $userExtension );
            endif;

            static::render_abandoned_plugins( $website, $active_tab, $userExtension );
            static::render_abandoned_themes( $website, $active_tab, $userExtension );
            ?>
        </div>
        <script type="text/javascript">
            jQuery(function ($) {
                jQuery( '.ui.dropdown .item' ).tab();
                jQuery( 'table.ui.table' ).DataTable( {
                    "searching": true,
                    "paging" : false,
                    "info" : true,
                    "columnDefs" : [ { "orderable": false, "targets": "no-sort" } ],
                    "language" : { "emptyTable": "No available updates. Please sync your MainWP Dashboard with Child Sites to see if there are any new updates available." }
                } );
                mainwp_get_icon_start();
            });
        </script>
        <?php
        MainWP_UI::render_modal_upload_icon();
    }

    /**
     * Method get_total_info()
     *
     * Get total Updates information.
     *
     * @param mixed $site_id Child Site id.
     */
    public static function get_total_info( $site_id ) { // phpcs:ignore -- NOSONAR -Current complexity is the only way to achieve desired results, pull request solutions appreciated.

        $userExtension = MainWP_DB_Common::instance()->get_user_extension();

        $decodedIgnoredCores = ! empty( $userExtension->ignored_wp_upgrades ) ? json_decode( $userExtension->ignored_wp_upgrades, true ) : array();
        if ( ! is_array( $decodedIgnoredCores ) ) {
            $decodedIgnoredCores = array();
        }

        $sql      = MainWP_DB::instance()->get_sql_website_by_id( $site_id, false, array( 'wp_upgrades', 'ignored_wp_upgrades', 'premium_upgrades', 'plugins_outdate_dismissed', 'themes_outdate_dismissed', 'plugins_outdate_info', 'themes_outdate_info', 'favi_icon' ) );
        $websites = MainWP_DB::instance()->query( $sql );

        MainWP_DB::data_seek( $websites, 0 );
        if ( $websites ) {
            $website = MainWP_DB::fetch_object( $websites );
        }

        $return = array(
            'total_wp'            => 0,
            'total_plugins'       => 0,
            'total_themes'        => 0,
            'total_trans'         => 0,
            'total_aband_plugins' => 0,
            'total_aband_themes'  => 0,
            'total_upgrades'      => 0,
        );

        if ( empty( $website ) ) {
            return $return;
        }

        $wp_upgrades           = ! empty( $website->wp_upgrades ) ? json_decode( $website->wp_upgrades, true ) : array();
        $ignored_core_upgrades = ! empty( $website->ignored_wp_upgrades ) ? json_decode( $website->ignored_wp_upgrades, true ) : array();

        if ( $website->is_ignoreCoreUpdates || MainWP_Common_Functions::instance()->is_ignored_updates( $wp_upgrades, $ignored_core_upgrades, 'core' ) || MainWP_Common_Functions::instance()->is_ignored_updates( $wp_upgrades, $decodedIgnoredCores, 'core' ) ) {
            $wp_upgrades = array();
        }

        $return['total_wp'] = ! empty( $wp_upgrades ) ? 1 : 0;

        $plugin_upgrades = json_decode( $website->plugin_upgrades, true );

        if ( $website->is_ignorePluginUpdates ) {
            $plugin_upgrades = array();
        }

        if ( ! is_array( $plugin_upgrades ) ) {
            $plugin_upgrades = array();
        }

        $decodedPremiumUpgrades = MainWP_DB::instance()->get_website_option( $website, 'premium_upgrades' );
        $decodedPremiumUpgrades = ! empty( $decodedPremiumUpgrades ) ? json_decode( $decodedPremiumUpgrades, true ) : array();

        if ( is_array( $decodedPremiumUpgrades ) ) {
            foreach ( $decodedPremiumUpgrades as $crrSlug => $premiumUpgrade ) {
                $premiumUpgrade['premium'] = true;
                if ( 'plugin' === $premiumUpgrade['type'] ) {
                    $premiumUpgrade = array_filter( $premiumUpgrade );

                    if ( ! isset( $plugin_upgrades[ $crrSlug ] ) ) {
                        $plugin_upgrades[ $crrSlug ] = array();
                    }
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

        $return['total_plugins'] = count( $plugin_upgrades );

        $theme_upgrades = json_decode( $website->theme_upgrades, true );

        if ( $website->is_ignoreThemeUpdates ) {
            $theme_upgrades = array();
        }

        if ( ! is_array( $theme_upgrades ) ) {
            $theme_upgrades = array();
        }
        $decodedPremiumUpgrades = MainWP_DB::instance()->get_website_option( $website, 'premium_upgrades' );
        $decodedPremiumUpgrades = ! empty( $decodedPremiumUpgrades ) ? json_decode( $decodedPremiumUpgrades, true ) : array();

        if ( is_array( $decodedPremiumUpgrades ) ) {
            foreach ( $decodedPremiumUpgrades as $crrSlug => $premiumUpgrade ) {
                $premiumUpgrade['premium'] = true;

                if ( 'theme' === $premiumUpgrade['type'] ) {
                    $premiumUpgrade = array_filter( $premiumUpgrade );

                    if ( ! isset( $theme_upgrades[ $crrSlug ] ) ) {
                        $theme_upgrades[ $crrSlug ] = array();
                    }
                    $theme_upgrades[ $crrSlug ] = array_merge( $theme_upgrades[ $crrSlug ], $premiumUpgrade );
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

        $return['total_themes'] = count( $theme_upgrades );

        $translation_upgrades = json_decode( $website->translation_upgrades, true );
        if ( ! is_array( $translation_upgrades ) ) {
            $translation_upgrades = array();
        }
        $return['total_trans'] = count( $translation_upgrades );

        $plugins_outdate = MainWP_DB::instance()->get_website_option( $website, 'plugins_outdate_info' );
        $plugins_outdate = ! empty( $plugins_outdate ) ? json_decode( $plugins_outdate, true ) : array();

        if ( ! is_array( $plugins_outdate ) ) {
            $plugins_outdate = array();
        }
        if ( ! empty( $plugins_outdate ) ) {
            $pluginsOutdateDismissed = MainWP_DB::instance()->get_website_option( $website, 'plugins_outdate_dismissed' );
            $pluginsOutdateDismissed = ! empty( $pluginsOutdateDismissed ) ? json_decode( $pluginsOutdateDismissed, true ) : array();

            if ( is_array( $pluginsOutdateDismissed ) ) {
                $plugins_outdate = array_diff_key( $plugins_outdate, $pluginsOutdateDismissed );
            }

            $decodedDismissedPlugins = json_decode( $userExtension->dismissed_plugins, true );
            if ( is_array( $decodedDismissedPlugins ) ) {
                $plugins_outdate = array_diff_key( $plugins_outdate, $decodedDismissedPlugins );
            }
        }
        $return['total_aband_plugins'] = count( $plugins_outdate );

        $themes_outdate = MainWP_DB::instance()->get_website_option( $website, 'themes_outdate_info' );
        $themes_outdate = ! empty( $themes_outdate ) ? json_decode( $themes_outdate, true ) : array();

        if ( ! is_array( $themes_outdate ) ) {
            $themes_outdate = array();
        }

        if ( ! empty( $themes_outdate ) ) {
            $themesOutdateDismissed = MainWP_DB::instance()->get_website_option( $website, 'themes_outdate_dismissed' );
            $themesOutdateDismissed = ! empty( $themesOutdateDismissed ) ? json_decode( $themesOutdateDismissed, true ) : array();

            if ( is_array( $themesOutdateDismissed ) ) {
                $themes_outdate = array_diff_key( $themes_outdate, $themesOutdateDismissed );
            }

            $decodedDismissedThemes = json_decode( $userExtension->dismissed_themes, true );
            if ( is_array( $decodedDismissedThemes ) ) {
                $themes_outdate = array_diff_key( $themes_outdate, $decodedDismissedThemes );
            }
        }

        $return['total_aband_themes'] = count( $themes_outdate );

        $return['total_upgrades'] = $return['total_wp'] + $return['total_plugins'] + $return['total_themes'] + $return['total_trans'];

        return $return;
    }


    /**
     * Method render_wpcore_updates()
     *
     * Render the WordPress Updates Tab.
     *
     * @param mixed  $website Child Site info.
     * @param mixed  $active_tab Current active tab.
     * @param object $userExtension User Extension.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
     */
    public static function render_wpcore_updates( $website, $active_tab, $userExtension ) { // phpcs:ignore -- NOSONAR - complex.
        $user_can_update_wp = \mainwp_current_user_can( 'dashboard', 'update_wordpress' );
        ?>
        <div class="ui <?php echo 'WordPress' === $active_tab ? 'active' : ''; ?> tab" data-tab="wordpress">
            <table style="width:100% !important;" class="ui tablet stackable table" id="mainwp-wordpress-updates-table mainwp-manage-updates-table">
                    <thead>
                        <tr>
                            <th scope="col" ><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
                            <th scope="col" ><?php esc_html_e( 'New Version', 'mainwp' ); ?></th>
                            <th scope="col" ></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php

                    $decodedIgnoredCores = ! empty( $userExtension->ignored_wp_upgrades ) ? json_decode( $userExtension->ignored_wp_upgrades, true ) : array();
                    if ( ! is_array( $decodedIgnoredCores ) ) {
                        $decodedIgnoredCores = array();
                    }

                    $wp_upgrades           = ! empty( $website->wp_upgrades ) ? json_decode( $website->wp_upgrades, true ) : array();
                    $ignored_core_upgrades = ! empty( $website->ignored_wp_upgrades ) ? json_decode( $website->ignored_wp_upgrades, true ) : array();
                    ?>
                    <?php if ( ! $website->is_ignoreCoreUpdates && ! MainWP_Common_Functions::instance()->is_ignored_updates( $wp_upgrades, $ignored_core_upgrades, 'core' ) && ! MainWP_Common_Functions::instance()->is_ignored_updates( $wp_upgrades, $decodedIgnoredCores, 'core' ) ) : ?>
                        <?php
                        $wpcore_update_disabled_by = '';
                        if ( ! empty( $wp_upgrades ) ) {
                            $wpcore_update_disabled_by = MainWP_System_Utility::disabled_wpcore_update_by( $website );
                        }
                        $last_version = ! empty( $wp_upgrades ) ? $wp_upgrades['new'] : '';
                        ?>
                        <?php if ( ( ! empty( $wp_upgrades ) ) && '' === $website->sync_errors ) : ?>
                        <tr class="mainwp-wordpress-update" site_id="<?php echo intval( $website->id ); ?>" last-version="<?php echo esc_attr( rawurlencode( $last_version ) ); ?>" site_name="<?php echo esc_attr( rawurlencode( stripslashes( $website->name ) ) ); ?>" updated="<?php echo ! empty( $wp_upgrades ) && empty( $wpcore_update_disabled_by ) ? '0' : '1'; ?>">
                            <td>
                                <?php if ( ! empty( $wp_upgrades ) ) : ?>
                                    <?php echo esc_html( $wp_upgrades['current'] ); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo esc_html( $last_version ); ?>
                            </td>
                            <td>
                                <?php if ( $user_can_update_wp ) : ?>
                                    <?php
                                    if ( ! empty( $wp_upgrades ) ) :
                                        if ( '' !== $wpcore_update_disabled_by ) {
                                            ?>
                                            <span data-tooltip="<?php echo esc_html( $wpcore_update_disabled_by ); ?>" data-inverted="" data-position="left center"><a href="javascript:void(0)" class="ui green button mini disabled"><?php esc_html_e( 'Update', 'mainwp' ); ?></a></span>
                                            <?php } else { ?>

                                                <div class="ui bottom left pointing dropdown mini button"><?php esc_html_e( 'Ignore', 'mainwp' ); ?>
                                                    <i class="dropdown icon"></i>
                                                    <div class="menu">
                                                        <a href="javascript:void(0)" onClick="return updatesoverview_upgrade_ignore( <?php echo intval( $website->id ); ?>, this, '<?php echo esc_js( rawurlencode( $last_version ) ); ?>' )" class="item"><?php esc_html_e( 'Ignore this version', 'mainwp' ); ?></a>
                                                        <a href="javascript:void(0)" class="item mainwp-ignore-globally-button" onClick="return updatesoverview_upgrade_ignore_this_version_globally( '<?php echo esc_js( rawurlencode( $last_version ) ); ?>' )"><?php esc_html_e( 'Ignore this version globally', 'mainwp' ); ?></a>
                                                        <a href="javascript:void(0)" onClick="return updatesoverview_upgrade_ignore_all_version( <?php echo intval( $website->id ); ?>, this )" class="item"><?php esc_html_e( 'Ignore all versions', 'mainwp' ); ?></a>
                                                    </div>
                                                </div>
                                                <a href="javascript:void(0)" data-tooltip="<?php esc_attr_e( 'Update', 'mainwp' ) . ' ' . esc_attr( $website->name ); ?>" data-inverted="" data-position="left center" class="ui green button mini" onClick="return updatesoverview_upgrade(<?php echo intval( $website->id ); ?>, this )"><?php esc_html_e( 'Update', 'mainwp' ); ?></a>

                                            <input type="hidden" id="wp-updated-<?php echo intval( $website->id ); ?>" value="<?php echo ! empty( $wp_upgrades ) ? '0' : '1'; ?>" />
                                            <?php
                                            }
                                        endif;
                                    ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                    <?php endif; ?>
                    </tbody>
                    <thead>
                        <tr>
                            <th scope="col" ><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
                            <th scope="col" ><?php esc_html_e( 'New Version', 'mainwp' ); ?></th>
                            <th scope="col" ></th>
                        </tr>
                    </thead>
                </table>
            </div>
        <?php
    }

    /**
     * Method render_plugins_updates()
     *
     * Render the Plugin Updates Tab.
     *
     * @param mixed $website Child Site info.
     * @param mixed $active_tab Current active tab.
     * @param mixed $userExtension MainWP trusted plugin data.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
     * @uses \MainWP\Dashboard\MainWP_Updates_Table_Helper
     */
    public static function render_plugins_updates( $website, $active_tab, $userExtension ) { // phpcs:ignore -- NOSONAR -Current complexity is the only way to achieve desired results, pull request solutions appreciated.

        $trustedPlugins = json_decode( $userExtension->trusted_plugins, true );
        if ( ! is_array( $trustedPlugins ) ) {
            $trustedPlugins = array();
        }

        $user_can_update_plugins  = \mainwp_current_user_can( 'dashboard', 'update_plugins' );
        $user_can_ignore_unignore = \mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' );
        ?>
        <div id="plugins-updates-global" class="ui <?php echo 'plugins' === $active_tab ? 'active' : ''; ?> tab" data-tab="plugins">
            <?php if ( ! $website->is_ignorePluginUpdates ) : ?>
                <?php
                $plugin_upgrades = json_decode( $website->plugin_upgrades, true );
                if ( ! is_array( $plugin_upgrades ) ) {
                    $plugin_upgrades = array();
                }

                $site_opts = MainWP_DB::instance()->get_website_options_array( $website, array( 'premium_upgrades', 'rollback_updates_data' ) );
                if ( ! is_array( $site_opts ) ) {
                    $site_opts = array();
                }
                $decodedPremiumUpgrades = ! empty( $site_opts['premium_upgrades'] ) ? json_decode( $site_opts['premium_upgrades'], true ) : array();

                $rollItems = ! empty( $site_opts['rollback_updates_data'] ) ? json_decode( $site_opts['rollback_updates_data'], true ) : array();

                $rollPlugins = array();
                if ( is_array( $rollItems ) && ! empty( $rollItems['plugin'] ) && is_array( $rollItems['plugin'] ) ) {
                    $rollPlugins = $rollItems['plugin'];
                }

                if ( is_array( $decodedPremiumUpgrades ) ) {
                    foreach ( $decodedPremiumUpgrades as $crrSlug => $premiumUpgrade ) {
                        $premiumUpgrade['premium'] = true;
                        if ( 'plugin' === $premiumUpgrade['type'] ) {
                            $premiumUpgrade = array_filter( $premiumUpgrade );
                            if ( ! isset( $plugin_upgrades[ $crrSlug ] ) ) {
                                $plugin_upgrades[ $crrSlug ] = array();
                            }
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

                $updates_table_helper = new MainWP_Updates_Table_Helper( MAINWP_VIEW_PER_SITE, 'plugin', array( 'show_select' => true ) );

                add_filter( 'mainwp_updates_table_header_content', array( static::class, 'hook_table_update_plugins_header_content' ), 10, 3 );

                ?>
                <table id="mainwp-updates-plugins-table" style="width:100% !important;" class="  ui tablet stackable table mainwp-updates-list mainwp-manage-updates-table">
                    <thead class="master-checkbox">
                        <tr>
                        <?php $updates_table_helper->print_column_headers(); ?>
                        </tr>
                    </thead>
                    <tbody class="plugins-bulk-updates child-checkbox" site_id="<?php echo intval( $website->id ); ?>" site_name="<?php echo esc_attr( rawurlencode( stripslashes( $website->name ) ) ); ?>">
                    <?php foreach ( $plugin_upgrades as $slug => $plugin_upgrade ) : ?>
                        <?php $plugin_name = rawurlencode( $slug ); ?>
                        <?php
                        $item_slug     = MainWP_Utility::get_dir_slug( rawurldecode( $slug ) );
                        $indent_hidden = '<input type="hidden" id="wp_upgraded_plugin_' . intval( $website->id ) . '_' . esc_html( $plugin_name ) . '" value="0" />';
                        $last_version  = $plugin_upgrade['update']['new_version'];
                        $row_columns   = array(
                            'title'   => MainWP_System_Utility::get_plugin_icon( $item_slug ) . '&nbsp;<a href="' . admin_url() . 'plugin-install.php?tab=plugin-information&wpplugin=' . intval( $website->id ) . '&plugin=' . esc_attr( $plugin_upgrade['update']['slug'] ) . '&url=' . ( isset( $plugin_upgrade['PluginURI'] ) ? rawurlencode( $plugin_upgrade['PluginURI'] ) : '' ) . '&name=' . rawurlencode( $plugin_upgrade['Name'] ) . '" target="_blank" class="open-plugin-details-modal">' . esc_html( $plugin_upgrade['Name'] ) . '</a>' . $indent_hidden,
                            'version' => esc_html( $plugin_upgrade['Version'] ),
                            'latest'  => '<a href="' . admin_url() . 'plugin-install.php?tab=plugin-information&wpplugin=' . intval( $website->id ) . '&plugin=' . esc_attr( $plugin_upgrade['update']['slug'] ) . '&url=' . ( isset( $plugin_upgrade['PluginURI'] ) ? rawurlencode( $plugin_upgrade['PluginURI'] ) : '' ) . '&name=' . rawurlencode( $plugin_upgrade['Name'] ) . '&section=changelog" target="_blank" class="open-plugin-details-modal">' . esc_html( $last_version ) . '</a>',
                            'trusted' => ( in_array( $slug, $trustedPlugins ) ? true : false ),
                            'status'  => ( isset( $plugin_upgrade['active'] ) && $plugin_upgrade['active'] ) ? true : false,
                        );

                        $others = array();
                        if ( ! empty( $rollPlugins[ $slug ][ $last_version ] ) ) {
                            $msg                 = MainWP_Updates_Helper::get_roll_msg( $rollPlugins[ $slug ][ $last_version ], true, 'notice' );
                            $others['roll_info'] = $msg;
                        }

                        ?>
                        <tr plugin_slug="<?php echo esc_attr( $plugin_name ); ?>" last-version="<?php echo esc_js( rawurlencode( $last_version ) ); ?>" site_name="<?php echo esc_attr( stripslashes( $website->name ) ); ?>" premium="<?php echo isset( $plugin_upgrade['premium'] ) && ! empty( $plugin_upgrade['premium'] ) ? 1 : 0; ?>" updated="0">
                            <?php
                            $row_columns     = $updates_table_helper->render_columns( $row_columns, $website, $others );
                            $action_rendered = isset( $row_columns['action'] ) ? true : false;
                            if ( ! $action_rendered ) :
                                ?>
                            <td>
                                <?php if ( $user_can_ignore_unignore ) : ?>
                                <div class="ui bottom left pointing dropdown mini button"><?php esc_html_e( 'Ignore', 'mainwp' ); ?>
                                    <i class="dropdown icon"></i>
                                    <div class="menu">
                                        <a href="javascript:void(0)" onClick="return updatesoverview_plugins_ignore_detail( '<?php echo esc_js( $plugin_name ); ?>', '<?php echo esc_js( rawurlencode( $plugin_upgrade['Name'] ) ); ?>', <?php echo intval( $website->id ); ?>, this, '<?php echo esc_js( rawurlencode( $last_version ) ); ?>' )" class="item"><?php esc_html_e( 'Ignore this version', 'mainwp' ); ?></a>
                                        <a href="javascript:void(0)" class="item mainwp-ignore-globally-button" onClick="return updatesoverview_plugins_ignore_all( '<?php echo esc_js( $plugin_name ); ?>', '<?php echo esc_attr( rawurlencode( $plugin_upgrade['Name'] ) ); ?>', this, '<?php echo esc_js( rawurlencode( $last_version ) ); ?>' )"><?php esc_html_e( 'Ignore this version globally', 'mainwp' ); ?></a>
                                        <a href="javascript:void(0)" onClick="return updatesoverview_plugins_ignore_detail( '<?php echo esc_js( $plugin_name ); ?>', '<?php echo esc_js( rawurlencode( $plugin_upgrade['Name'] ) ); ?>', <?php echo intval( $website->id ); ?>, this, 'all_versions' )" class="item"><?php esc_html_e( 'Ignore all versions', 'mainwp' ); ?></a>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <?php if ( $user_can_update_plugins ) : ?>
                                    <a href="javascript:void(0)" class="ui green mini button" onClick="return updatesoverview_upgrade_plugin( <?php echo intval( $website->id ); ?>, '<?php echo esc_js( $plugin_name ); ?>' )"><?php esc_html_e( 'Update', 'mainwp' ); ?></a>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                        <?php $updates_table_helper->print_column_headers( false ); ?>
                        </tr>
                    </tfoot>
                </table>
            <?php endif; ?>
            </div>
        <?php
        remove_filter( 'mainwp_updates_table_header_content', array( static::class, 'hook_table_update_plugins_header_content' ), 10, 3 );

        MainWP_Updates::render_updates_modal();
        MainWP_Updates::render_plugin_details_modal();
    }

    /**
     * Method hook_table_update_plugins_header_content()
     *
     * Hook render the column header updates table.
     *
     * @param string $column_display_name column display name.
     * @param mixed  $column_key column key.
     * @param bool   $top Top or bottom header.
     */
    public static function hook_table_update_plugins_header_content( $column_display_name, $column_key, $top ) {
        if ( $top && 'title' === $column_key ) {
            $column_display_name = '<div class="ui master checkbox plugins-checkbox"><input type="checkbox" name=""><label>' . $column_display_name . '</label></div>';
        }
        return $column_display_name;
    }

    /**
     * Method hook_table_update_themes_header_content()
     *
     * Hook render the column header updates table.
     *
     * @param string $column_display_name column display name.
     * @param mixed  $column_key column key.
     * @param bool   $top Top or bottom header.
     */
    public static function hook_table_update_themes_header_content( $column_display_name, $column_key, $top ) {
        if ( $top && 'title' === $column_key ) {
            $column_display_name = '<div class="ui master checkbox themes-checkbox"><input type="checkbox" name=""><label>' . $column_display_name . '</label></div>';
        }
        return $column_display_name;
    }

    /**
     * Method render_themes_updates()
     *
     * Render the Themes Update Tab.
     *
     * @param mixed $website Child Site info.
     * @param mixed $active_tab Current active tab.
     * @param mixed $userExtension MainWP trusted themes data.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
     * @uses \MainWP\Dashboard\MainWP_Updates_Table_Helper
     */
    public static function render_themes_updates( $website, $active_tab, $userExtension ) { // phpcs:ignore -- NOSONAR -Current complexity is the only way to achieve desired results, pull request solutions appreciated.

        $trustedThemes = json_decode( $userExtension->trusted_themes, true );
        if ( ! is_array( $trustedThemes ) ) {
            $trustedThemes = array();
        }

        $user_can_update_themes   = \mainwp_current_user_can( 'dashboard', 'update_themes' );
        $user_can_ignore_unignore = \mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' );

        ?>
        <div id="themes-updates-global" class="ui <?php echo 'themes' === $active_tab ? 'active' : ''; ?> tab" data-tab="themes">
            <?php if ( ! $website->is_ignoreThemeUpdates ) : ?>
                <?php
                $theme_upgrades = json_decode( $website->theme_upgrades, true );
                if ( ! is_array( $theme_upgrades ) ) {
                    $theme_upgrades = array();
                }

                $site_opts = MainWP_DB::instance()->get_website_options_array( $website, array( 'premium_upgrades', 'rollback_updates_data' ) );
                if ( ! is_array( $site_opts ) ) {
                    $site_opts = array();
                }
                $decodedPremiumUpgrades = ! empty( $site_opts['premium_upgrades'] ) ? json_decode( $site_opts['premium_upgrades'], true ) : array();

                $rollItems = ! empty( $site_opts['rollback_updates_data'] ) ? json_decode( $site_opts['rollback_updates_data'], true ) : array();

                $rollThemes = array();
                if ( is_array( $rollItems ) && ! empty( $rollItems['theme'] ) && is_array( $rollItems['theme'] ) ) {
                    $rollThemes = $rollItems['theme'];
                }

                if ( is_array( $decodedPremiumUpgrades ) ) {
                    foreach ( $decodedPremiumUpgrades as $crrSlug => $premiumUpgrade ) {
                        $premiumUpgrade['premium'] = true;
                        if ( 'theme' === $premiumUpgrade['type'] ) {
                            $premiumUpgrade = array_filter( $premiumUpgrade );
                            if ( ! isset( $theme_upgrades[ $crrSlug ] ) ) {
                                $theme_upgrades[ $crrSlug ] = array();
                            }
                            $theme_upgrades[ $crrSlug ] = array_merge( $theme_upgrades[ $crrSlug ], $premiumUpgrade );
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

                $updates_table_helper = new MainWP_Updates_Table_Helper( MAINWP_VIEW_PER_SITE, 'theme', array( 'show_select' => true ) );

                add_filter( 'mainwp_updates_table_header_content', array( static::class, 'hook_table_update_themes_header_content' ), 10, 3 );

                ?>
                <table id="mainwp-updates-themes-table" style="width:100% !important;" class="  ui tablet stackable table mainwp-updates-list mainwp-manage-updates-table">
                    <thead class="master-checkbox full-width" >
                        <tr>
                        <?php $updates_table_helper->print_column_headers(); ?>
                        </tr>
                    </thead>
                    <tbody class="themes-bulk-updates child-checkbox" site_id="<?php echo intval( $website->id ); ?>" site_name="<?php echo esc_attr( rawurlencode( stripslashes( $website->name ) ) ); ?>">
                        <?php foreach ( $theme_upgrades as $slug => $theme_upgrade ) : ?>
                            <?php $theme_name = rawurlencode( $slug ); ?>
                            <?php $indent_hidden = '<input type="hidden" id="wp_upgraded_theme_' . intval( $website->id ) . '_' . esc_attr( $theme_name ) . '" value="0" />'; ?>
                            <?php
                            $last_version = $theme_upgrade['update']['new_version'];
                            $row_columns  = array(
                                'title'   => MainWP_System_Utility::get_theme_icon( $slug ) . '&nbsp;' . esc_html( $theme_upgrade['Name'] ) . $indent_hidden,
                                'version' => esc_html( $theme_upgrade['Version'] ),
                                'latest'  => esc_html( $last_version ),
                                'trusted' => ( in_array( $slug, $trustedThemes, true ) ? true : false ),
                                'status'  => ( isset( $theme_upgrade['active'] ) && $theme_upgrade['active'] ) ? true : false,
                            );
                            $others       = array();
                            if ( ! empty( $rollThemes[ $slug ][ $last_version ] ) ) {
                                $msg                 = MainWP_Updates_Helper::get_roll_msg( $rollThemes[ $slug ][ $last_version ], true, 'notice' );
                                $others['roll_info'] = $msg;
                            }
                            ?>
                            <tr theme_slug="<?php echo esc_attr( $theme_name ); ?>" last-version="<?php echo esc_js( rawurlencode( $last_version ) ); ?>" premium="<?php echo isset( $theme_upgrade['premium'] ) && ! empty( $theme_upgrade['premium'] ) ? 1 : 0; ?>" updated="0">
                                <?php
                                $row_columns     = $updates_table_helper->render_columns( $row_columns, $website, $others );
                                $action_rendered = isset( $row_columns['action'] ) ? true : false;
                                if ( ! $action_rendered ) :
                                    ?>
                                <td>
                                    <?php if ( $user_can_ignore_unignore ) : ?>
                                    <div class="ui bottom left pointing dropdown mini button"><?php esc_html_e( 'Ignore', 'mainwp' ); ?>
                                        <i class="dropdown icon"></i>
                                        <div class="menu">
                                            <a href="javascript:void(0)" onClick="return updatesoverview_themes_ignore_detail( '<?php echo esc_js( $theme_name ); ?>', '<?php echo esc_js( rawurlencode( $theme_upgrade['Name'] ) ); ?>', <?php echo intval( $website->id ); ?>, this, '<?php echo esc_js( rawurlencode( $last_version ) ); ?>' )" class="item"><?php esc_html_e( 'Ignore this version', 'mainwp' ); ?></a>
                                            <a href="javascript:void(0)" class="item mainwp-ignore-globally-button" onClick="return updatesoverview_themes_ignore_all( '<?php echo esc_js( $theme_name ); ?>', '<?php echo esc_js( rawurlencode( $theme_upgrade['Name'] ) ); ?>', this, '<?php echo esc_js( rawurlencode( $last_version ) ); ?>' )"><?php esc_html_e( 'Ignore this version globally', 'mainwp' ); ?></a>
                                            <a href="javascript:void(0)" onClick="return updatesoverview_themes_ignore_detail( '<?php echo esc_js( $theme_name ); ?>', '<?php echo esc_js( rawurlencode( $theme_upgrade['Name'] ) ); ?>', <?php echo intval( $website->id ); ?>, this, 'all_versions' )" class="item"><?php esc_html_e( 'Ignore all versions', 'mainwp' ); ?></a>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ( $user_can_update_themes ) : ?>
                                        <a href="javascript:void(0)" class="ui green mini button" onClick="return updatesoverview_upgrade_theme( <?php echo intval( $website->id ); ?>, '<?php echo esc_js( $theme_name ); ?>' )"><?php esc_html_e( 'Update', 'mainwp' ); ?></a>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="full-width">
                        <tr>
                        <?php $updates_table_helper->print_column_headers( false ); ?>
                        </tr>
                    </tfoot>
                </table>
            <?php endif; ?>
            </div>
        <?php
        remove_filter( 'mainwp_updates_table_header_content', array( static::class, 'hook_table_update_themes_header_content' ), 10, 3 );
    }

    /**
     * Method render_language_updates()
     *
     * Render the Language Updates Tab.
     *
     * @param mixed  $website Child Site info.
     * @param mixed  $active_tab Current active tab.
     * @param object $userExtension User extension data.
     */
    public static function render_language_updates( $website, $active_tab, $userExtension ) {
        $trustedPlugins = ! empty( $userExtension->trusted_plugins ) ? json_decode( $userExtension->trusted_plugins, true ) : array();
        if ( ! is_array( $trustedPlugins ) ) {
            $trustedPlugins = array();
        }

        $trustedThemes = json_decode( $userExtension->trusted_themes, true );
        if ( ! is_array( $trustedThemes ) ) {
            $trustedThemes = array();
        }

        $user_can_update_translation = \mainwp_current_user_can( 'dashboard', 'update_translations' );
        $is_demo                     = MainWP_Demo_Handle::is_demo_mode();
        ?>
        <div class="ui <?php echo 'trans' === $active_tab ? 'active' : ''; ?> tab" data-tab="translations">
            <table style="width:100% !important;" class=" ui tablet stackable table mainwp-manage-updates-table" id="mainwp-translations-table">
                <thead>
                    <tr>
                        <th scope="col" ><?php esc_html_e( 'Translation', 'mainwp' ); ?></th>
                        <th scope="col" ><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
                        <th scope="col" ><?php esc_html_e( 'Trusted', 'mainwp' ); ?></th>
                        <th scope="col" class="collapsing no-sort"></th>
                    </tr>
                </thead>
                <tbody class="translations-bulk-updates" id="wp_translation_upgrades_<?php echo intval( $website->id ); ?>" site_id="<?php echo intval( $website->id ); ?>" site_name="<?php echo esc_attr( rawurlencode( stripslashes( $website->name ) ) ); ?>">
                <?php $translation_upgrades = json_decode( $website->translation_upgrades, true ); ?>
                <?php foreach ( $translation_upgrades as $translation_upgrade ) : ?>
                    <?php
                    $translation_name = isset( $translation_upgrade['name'] ) ? $translation_upgrade['name'] : $translation_upgrade['slug'];
                    $translation_slug = esc_attr( $translation_upgrade['slug'] );
                    ?>
                    <tr translation_slug="<?php echo esc_attr( $translation_slug ); ?>" updated="0">
                        <td>
                            <?php echo esc_html( $translation_name ); ?>
                            <input type="hidden" id="wp_upgraded_translation_<?php echo intval( $website->id ); ?>_<?php echo esc_attr( $translation_slug ); ?>" value="0"/>
                        </td>
                        <td>
                            <?php echo esc_html( $translation_upgrade['version'] ); ?>
                        </td>
                        <?php
                        $is_trust = static::is_trans_trusted_update( $translation_upgrade, $trustedPlugins, $trustedThemes );
                        echo static::get_column_trusted($is_trust ); //phpcs:ignore -- NOSONAR - escaped.
                        ?>
                        <td>
                            <?php
                            if ( $user_can_update_translation ) {
                                if ( $is_demo ) {
                                    MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<a href="javascript:void(0)" class="ui green mini button disabled" disabled="disabled">' . esc_html__( 'Update', 'mainwp' ) . '</a>' );
                                } else {
                                    ?>
                                <a href="javascript:void(0)" class="ui green mini button" onClick="return updatesoverview_upgrade_translation( <?php echo intval( $website->id ); ?>, '<?php echo esc_js( $translation_slug ); ?>' )"><?php esc_html_e( 'Update', 'mainwp' ); ?></a>
                                <?php } ?>
                            <?php } ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th scope="col" ><?php esc_html_e( 'Translation', 'mainwp' ); ?></th>
                        <th scope="col" ><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
                        <th scope="col" ><?php esc_html_e( 'Trusted', 'mainwp' ); ?></th>
                        <th scope="col" class="collapsing no-sort"></th>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php
    }


    /**
     * Get trusted column.
     *
     * @param mixed $value Value of column.
     */
    public static function get_column_trusted( $value ) {
        if ( $value ) {
            $label = '<span class="ui tiny basic green label mainwp-768-fluid">Trusted</span>';
        } else {
            $label = '<span class="ui tiny basic grey label mainwp-768-fluid">Not Trusted</span>';
        }
        return '<td class="mainwp-768-half-width-cell">' . $label . '</td>';
    }

    /**
     * Check if trans is trusted update.
     *
     * @param string $tran_info Translation slug.
     * @param array  $trusted_plugins Trusted update plugins.
     * @param array  $trusted_themes Trusted update themes.
     */
    public static function is_trans_trusted_update( $tran_info, $trusted_plugins, $trusted_themes ) {
        if ( is_array( $tran_info ) && isset( $tran_info['type'] ) && isset( $tran_info['slug'] ) ) {
            $tran_slug = $tran_info['slug'];
            if ( 'plugin' === $tran_info['type'] && is_array( $trusted_plugins ) ) {
                foreach ( $trusted_plugins as $slug ) {
                    $_slug = explode( '/', $slug )[0];
                    if ( $tran_slug === $_slug ) {
                        return true;
                    }
                }
            } elseif ( 'theme' === $tran_info['type'] && is_array( $trusted_themes ) && in_array( $tran_slug, $trusted_themes ) ) {
                return true;
            }
        }
        return false;
    }


    /**
     * Method render_abandoned_plugins()
     *
     * Render the Abandoned Plugin Tab.
     *
     * @param mixed $website Child Site info.
     * @param mixed $active_tab Current active tab.
     * @param mixed $userExtension MainWP trusted plugin data.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
     */
    public static function render_abandoned_plugins( $website, $active_tab, $userExtension ) {

        $user_can_ignore_unignore = \mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' );

        $plugins_outdate = MainWP_DB::instance()->get_website_option( $website, 'plugins_outdate_info' );
        $plugins_outdate = ! empty( $plugins_outdate ) ? json_decode( $plugins_outdate, true ) : array();

        if ( ! is_array( $plugins_outdate ) ) {
            $plugins_outdate = array();
        }
        $pluginsOutdateDismissed = MainWP_DB::instance()->get_website_option( $website, 'plugins_outdate_dismissed' );
        $pluginsOutdateDismissed = ! empty( $pluginsOutdateDismissed ) ? json_decode( $pluginsOutdateDismissed, true ) : array();

        if ( is_array( $pluginsOutdateDismissed ) ) {
            $plugins_outdate = array_diff_key( $plugins_outdate, $pluginsOutdateDismissed );
        }

        $decodedDismissedPlugins = json_decode( $userExtension->dismissed_plugins, true );
        if ( is_array( $decodedDismissedPlugins ) ) {
            $plugins_outdate = array_diff_key( $plugins_outdate, $decodedDismissedPlugins );
        }
        $str_format = esc_html__( 'Updated %s days ago', 'mainwp' );
        ?>

        <div class="ui <?php echo 'abandoned-plugins' === $active_tab ? 'active' : ''; ?> tab" data-tab="abandoned-plugins">
            <table style="width:100% !important;"  class="  ui tablet stackable table mainwp-manage-updates-table" id="mainwp-abandoned-plugins-table">
                <thead>
                    <tr>
                        <th scope="col" ><?php esc_html_e( 'Plugin', 'mainwp' ); ?></th>
                        <th scope="col" ><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
                        <th scope="col" ><?php esc_html_e( 'Last Update', 'mainwp' ); ?></th>
                        <th scope="col" class="no-sort"></th>
                    </tr>
                </thead>
                <tbody id="wp_plugins_outdate_<?php echo intval( $website->id ); ?>" site_id="<?php echo intval( $website->id ); ?>" site_name="<?php echo esc_attr( rawurlencode( stripslashes( $website->name ) ) ); ?>">
                    <?php foreach ( $plugins_outdate as $slug => $plugin_outdate ) : ?>
                        <?php
                        $plugin_name              = rawurlencode( $slug );
                        $item_slug                = MainWP_Utility::get_dir_slug( rawurldecode( $slug ) );
                        $now                      = new \DateTime();
                        $last_updated             = $plugin_outdate['last_updated'];
                        $plugin_last_updated_date = new \DateTime( '@' . $last_updated );
                        $diff_in_days             = $now->diff( $plugin_last_updated_date )->format( '%a' );
                        $outdate_notice           = sprintf( $str_format, $diff_in_days );

                        ?>
                        <tr dismissed="0">
                            <td> <?php echo MainWP_System_Utility::get_plugin_icon( $item_slug ) . '&nbsp;'; // phpcs:ignore WordPress.Security.EscapeOutput ?>
                                <a href="<?php echo esc_url( admin_url() ) . 'plugin-install.php?tab=plugin-information&wpplugin=' . intval( $website->id ) . '&plugin=' . esc_html( dirname( $slug ) ) . '&url=' . ( isset( $plugin_outdate['PluginURI'] ) ? esc_url( rawurlencode( $plugin_outdate['PluginURI'] ) ) : '' ) . '&name=' . esc_url( rawurlencode( $plugin_outdate['Name'] ) ); ?>" target="_blank" class="open-plugin-details-modal"><?php echo esc_html( $plugin_outdate['Name'] ); ?></a>
                                <input type="hidden" id="wp_dismissed_plugin_<?php echo intval( $website->id ); ?>_<?php echo esc_attr( $plugin_name ); ?>" value="0"/>
                            </td>
                            <td><?php echo esc_html( $plugin_outdate['Version'] ); ?></td>
                            <td><?php echo esc_html( $outdate_notice ); ?></td>
                            <td id="wp_dismissbuttons_plugin_<?php echo intval( $website->id ); ?>_<?php echo esc_attr( $plugin_name ); ?>">
                                <?php if ( $user_can_ignore_unignore ) { ?>
                                <a href="javascript:void(0)" class="ui mini button" onClick="return updatesoverview_plugins_dismiss_outdate_detail( '<?php echo esc_js( $plugin_name ); ?>', '<?php echo esc_js( rawurlencode( $plugin_outdate['Name'] ) ); ?>', <?php echo esc_js( $website->id ); ?>, this )"><?php esc_html_e( 'Ignore Now', 'mainwp' ); ?></a>
                            <?php } ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th scope="col" ><?php esc_html_e( 'Plugin', 'mainwp' ); ?></th>
                        <th scope="col" ><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
                        <th scope="col" ><?php esc_html_e( 'Last Update', 'mainwp' ); ?></th>
                        <th scope="col" class="no-sort"></th>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php
    }

    /**
     * Method render_abandoned_themes()
     *
     * Render the Abandoned Themes tab.
     *
     * @param mixed $website Child Site info.
     * @param mixed $active_tab Current active tab.
     * @param mixed $userExtension MainWP trusted themes data.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
     */
    public static function render_abandoned_themes( $website, $active_tab, $userExtension ) {

        $user_can_ignore_unignore = \mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' );

        $themes_outdate = MainWP_DB::instance()->get_website_option( $website, 'themes_outdate_info' );
        $themes_outdate = ! empty( $themes_outdate ) ? json_decode( $themes_outdate, true ) : array();

        if ( ! is_array( $themes_outdate ) ) {
            $themes_outdate = array();
        }

        if ( ! empty( $themes_outdate ) ) {
            $themesOutdateDismissed = MainWP_DB::instance()->get_website_option( $website, 'themes_outdate_dismissed' );
            $themesOutdateDismissed = ! empty( $themesOutdateDismissed ) ? json_decode( $themesOutdateDismissed, true ) : array();

            if ( is_array( $themesOutdateDismissed ) ) {
                $themes_outdate = array_diff_key( $themes_outdate, $themesOutdateDismissed );
            }

            $decodedDismissedThemes = json_decode( $userExtension->dismissed_themes, true );
            if ( is_array( $decodedDismissedThemes ) ) {
                $themes_outdate = array_diff_key( $themes_outdate, $decodedDismissedThemes );
            }
        }

        $str_format = esc_html__( 'Updated %s days ago', 'mainwp' );

        ?>
        <div class="ui <?php echo 'abandoned-themes' === $active_tab ? 'active' : ''; ?> tab" data-tab="abandoned-themes">
            <table style="width:100% !important;"  class="  ui tablet stackable table mainwp-manage-updates-table" id="mainwp-abandoned-themes-table">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e( 'Theme', 'mainwp' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Last Update', 'mainwp' ); ?></th>
                        <th scope="col" class="no-sort"></th>
                    </tr>
                </thead>
                <tbody site_id="<?php echo intval( $website->id ); ?>" site_name="<?php echo esc_attr( rawurlencode( stripslashes( $website->name ) ) ); ?>">
                    <?php foreach ( $themes_outdate as $slug => $theme_outdate ) : ?>
                        <?php
                        $theme_name              = rawurlencode( $slug );
                        $now                     = new \DateTime();
                        $last_updated            = $theme_outdate['last_updated'];
                        $theme_last_updated_date = new \DateTime( '@' . $last_updated );
                        $diff_in_days            = $now->diff( $theme_last_updated_date )->format( '%a' );
                        $outdate_notice          = sprintf( $str_format, $diff_in_days );
                        ?>
                        <tr dismissed="0">
                            <td>
                                <?php echo MainWP_System_Utility::get_theme_icon( $slug ) . '&nbsp;' . esc_html( $theme_outdate['Name'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                                <input type="hidden" id="wp_dismissed_theme_<?php echo intval( $website->id ); ?>_<?php echo esc_attr( $theme_name ); ?>" value="0"/>
                            </td>
                            <td><?php echo esc_html( $theme_outdate['Version'] ); ?></td>
                            <td><?php echo esc_html( $outdate_notice ); ?></td>
                            <td id="wp_dismissbuttons_theme_<?php echo intval( $website->id ); ?>_<?php echo esc_attr( $theme_name ); ?>">
                                <?php if ( $user_can_ignore_unignore ) { ?>
                                <a href="javascript:void(0)" class="ui mini button" onClick="return updatesoverview_themes_dismiss_outdate_detail( '<?php echo esc_js( $theme_name ); ?>', '<?php echo esc_js( rawurlencode( $theme_outdate['Name'] ) ); ?>', <?php echo intval( $website->id ); ?>, this )"><?php esc_html_e( 'Ignore Now', 'mainwp' ); ?></a>
                                <?php } ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
