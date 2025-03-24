<?php
/**
 * MainWP Updates Per Site
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Updates_Per_Site
 */
class MainWP_Updates_Per_Site { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Method get_class_name()
     *
     * Get Class Name.
     *
     * @return object class name.
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Method render_wpcore_updates()
     *
     * Render WP core updates
     *
     * @param object $websites the websites.
     * @param object $userExtension User Extension.
     * @param int    $total_wp_upgrades number of available WordPress updates.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_UI::render_sorting_icons()
     * @uses \MainWP\Dashboard\MainWP_Updates::user_can_update_wp()
     * @uses \MainWP\Dashboard\MainWP_Updates::set_continue_update_html_selector()
     * @uses \MainWP\Dashboard\MainWP_Updates::render_site_link_dashboard()
     */
    public static function render_wpcore_updates( $websites, $userExtension, $total_wp_upgrades ) { //phpcs:ignore -- NOSONAR - complex method.

        $decodedIgnoredCores = ! empty( $userExtension->ignored_wp_upgrades ) ? json_decode( $userExtension->ignored_wp_upgrades, true ) : array();
        if ( ! is_array( $decodedIgnoredCores ) ) {
            $decodedIgnoredCores = array();
        }

        ?>
        <?php if ( 0 < $total_wp_upgrades ) : ?>
        <table class="ui tablet stackable table mainwp-manage-updates-table main-master-checkbox" id="mainwp-wordpress-updates-table">
            <thead>
                <tr>
                    <th scope="col" class="indicator-accordion-sorting handle-accordion-sorting">
                    <div class="ui main-master checkbox">
                        <input type="checkbox" name=""><label><?php esc_html_e( 'Website', 'mainwp' ); ?></label>
                    </div>
                    <?php MainWP_UI::render_sorting_icons(); ?>
                    </th>
                    <th scope="col" class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Version', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
                    <th scope="col" class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Latest', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
                    <th scope="col" class="indicator-accordion-sorting handle-accordion-sorting"><?php echo esc_html__( 'Client', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
                    <th scope="col" class="no-sort right aligned"></th>
                </tr>
            </thead>
            <tbody class="child-checkbox"> <!-- per site or plugin -->
                <?php
                while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
                    if ( $website->is_ignoreCoreUpdates ) {
                        continue;
                    }

                    $wp_upgrades           = ! empty( $website->wp_upgrades ) ? json_decode( $website->wp_upgrades, true ) : array();
                    $ignored_core_upgrades = ! empty( $website->ignored_wp_upgrades ) ? json_decode( $website->ignored_wp_upgrades, true ) : array();

                    if ( MainWP_Common_Functions::instance()->is_ignored_updates( $wp_upgrades, $ignored_core_upgrades, 'core' ) || MainWP_Common_Functions::instance()->is_ignored_updates( $wp_upgrades, $decodedIgnoredCores, 'core' ) ) {
                        $wp_upgrades = array();
                    }

                    if ( empty( $wp_upgrades ) || ! empty( $website->sync_errors ) ) {
                        continue;
                    }

                    $wpcore_update_disabled_by = MainWP_System_Utility::disabled_wpcore_update_by( $website );

                    // @NO_SONAR_START@ - duplicated issue.
                    $last_version = ! empty( $wp_upgrades ) && ! empty( $wp_upgrades['new'] ) ? $wp_upgrades['new'] : '';

                    ?>
                <tr class="mainwp-wordpress-update" last-version="<?php echo esc_attr( rawurlencode( $last_version ) ); ?>" site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo esc_attr( rawurlencode( stripslashes( $website->name ) ) ); ?>" updated="<?php echo ! empty( $wp_upgrades ) && empty( $wpcore_update_disabled_by ) ? '0' : '1'; ?>">
                    <td>
                        <div class="ui child checkbox">
                            <input type="checkbox" name=""><label><?php MainWP_Updates::render_site_link_dashboard( $website ); ?></label>
                        </div>

                        <input type="hidden" id="wp-updated-<?php echo esc_attr( $website->id ); ?>" value="<?php echo ! empty( $wp_upgrades ) ? '0' : '1'; ?>" />
                    </td>
                    <td>
                        <?php if ( ! empty( $wp_upgrades ) ) : ?>
                            <?php echo esc_html( $wp_upgrades['current'] ); ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php echo esc_html( $last_version ); ?>
                    </td>
                    <td><a href="<?php echo 'admin.php?page=ManageClients&client_id=' . intval( $website->client_id ); ?>" data-tooltip="<?php esc_attr_e( 'Jump to the client', 'mainwp' ); ?>" data-position="right center" data-inverted="" ><?php echo esc_html( $website->client_name ); ?></a></td>
                    <td class="right aligned">
                        <?php if ( MainWP_Updates::user_can_update_wp() ) : ?>
                            <?php
                            if ( ! empty( $wp_upgrades ) ) :
                                if ( ! empty( $wpcore_update_disabled_by ) ) {
                                    ?>
                                    <span data-tooltip="<?php echo esc_html( $wpcore_update_disabled_by ); ?>" data-inverted="" data-position="left center"><a href="javascript:void(0)" class="ui green button mini disabled"><?php esc_html_e( 'Update', 'mainwp' ); ?></a></span>
                                <?php } else { ?>
                                <div class="ui top left pointing dropdown mini button">
                                    <?php esc_html_e( 'Ignore', 'mainwp' ); ?><i class="dropdown icon"></i>
                                    <div class="menu">
                                        <a href="javascript:void(0)" onClick="return updatesoverview_upgrade_ignore( <?php echo intval( $website->id ); ?>, this, '<?php echo esc_js( rawurlencode( $last_version ) ); ?>' )" class="item"><?php esc_html_e( 'Ignore this version', 'mainwp' ); ?></a>
                                        <a href="javascript:void(0)" class="item mainwp-ignore-globally-button" onClick="return updatesoverview_upgrade_ignore_this_version_globally( '<?php echo esc_js( rawurlencode( $last_version ) ); ?>' )"><?php esc_html_e( 'Ignore this version globally', 'mainwp' ); ?></a>
                                        <a href="javascript:void(0)" onClick="return updatesoverview_upgrade_ignore_all_version( <?php echo intval( $website->id ); ?>, this )" class="item"><?php esc_html_e( 'Ignore all versions', 'mainwp' ); ?></a>
                                    </div>
                                </div>
                                <a href="javascript:void(0)" data-tooltip="<?php esc_attr_e( 'Update', 'mainwp' ) . ' ' . $website->name; ?>" data-inverted="" data-position="left center" class="mainwp-update-now-button ui green button mini" onClick="return updatesoverview_upgrade(<?php echo esc_attr( $website->id ); ?>, this )"><?php esc_html_e( 'Update', 'mainwp' ); ?></a>
                                <?php } ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
                    <?php
                    // @NO_SONAR_END@  .
                }
                ?>
            </tbody>
        </table>
        <?php else : ?>
            <?php MainWP_UI::render_empty_page_placeholder( __( 'You\'re All Set!', 'mainwp' ), __( 'No updates available right now.', 'mainwp' ) ); ?>
        <?php endif; ?>
        <?php
    }

    /**
     * Method render_plugins_updates()
     *
     * Render Plugin updates
     *
     * @param object $websites the websites.
     * @param int    $total_plugin_upgrades number of available plugin updates.
     * @param mixed  $userExtension user extension.
     * @param array  $trustedPlugins trusted plugins.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
     * @uses \MainWP\Dashboard\MainWP_UI::render_sorting_icons()
     * @uses \MainWP\Dashboard\MainWP_UI::render_show_all_updates_button()
     * @uses \MainWP\Dashboard\MainWP_UI
     * @uses \MainWP\Dashboard\MainWP_Updates_Table_Helper
     * @uses \MainWP\Dashboard\MainWP_Updates::user_can_update_plugins()
     * @uses \MainWP\Dashboard\MainWP_Updates::set_continue_update_html_selector()
     * @uses \MainWP\Dashboard\MainWP_Updates::render_site_link_dashboard()
     * @uses \MainWP\Dashboard\MainWP_Updates::user_can_ignore_updates()
     */
    public static function render_plugins_updates( $websites, $total_plugin_upgrades, $userExtension, $trustedPlugins ) { // phpcs:ignore -- NOSONAR - not quite complex method.
        ?>
        <?php if ( 0 < $total_plugin_upgrades ) : ?>
        <table class="ui tablet stackable table mainwp-manage-updates-table main-master-checkbox" id="mainwp-plugins-updates-sites-table">
            <thead>
                <tr>
                    <th scope="col" class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>

                    <th scope="col" class="eight wide indicator-accordion-sorting handle-accordion-sorting">
                        <div class="ui main-master checkbox ">
                            <input type="checkbox" name=""><label><?php esc_html_e( 'Website', 'mainwp' ); ?></label>
                        </div>
                        <?php MainWP_UI::render_sorting_icons(); ?>
                    </th>
                    <th scope="col" class="two wide indicator-accordion-sorting handle-accordion-sorting"><?php echo intval( $total_plugin_upgrades ) . ' ' . esc_html( _n( 'Update', 'Updates', $total_plugin_upgrades, 'mainwp' ) ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
                    <th scope="col" class="two wide collapsing indicator-accordion-sorting handle-accordion-sorting"><?php echo esc_html__( 'Client', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
                    <th scope="col" class="four wide no-sort right aligned">
                        <?php MainWP_UI::render_show_all_updates_button(); ?>
                    </th>
                </tr>
            </thead>
            <tbody id="plugins-updates-global" class="ui accordion">
                <?php
                $updates_table_helper = new MainWP_Updates_Table_Helper( $userExtension->site_view );

                MainWP_DB::data_seek( $websites, 0 );
                while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
                    if ( $website->is_ignorePluginUpdates ) {
                        continue;
                    }
                    $plugin_upgrades = ! empty( $website->plugin_upgrades ) ? json_decode( $website->plugin_upgrades, true ) : array();
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
                                if ( ! is_array( $plugin_upgrades ) ) {
                                    $plugin_upgrades = array();
                                }

                                $premiumUpgrade = array_filter( $premiumUpgrade );
                                if ( ! isset( $plugin_upgrades[ $crrSlug ] ) ) {
                                    continue;
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

                    if ( empty( $plugin_upgrades ) && empty( $website->sync_errors ) ) {
                        continue;
                    }
                    ?>
                    <tr class="ui title master-checkbox">
                        <td class="accordion-trigger"><i class="icon dropdown"></i></td>
                        <td>
                            <div class="ui master checkbox">
                                <input type="checkbox" name=""><label><?php MainWP_Updates::render_site_link_dashboard( $website ); ?></label>
                            </div>
                        </td>
                        <td sort-value="<?php echo count( $plugin_upgrades ); ?>"><?php echo count( $plugin_upgrades ); ?> <?php echo esc_html( _n( 'Update', 'Updates', count( $plugin_upgrades ), 'mainwp' ) ); ?></td>
                        <td><a href="<?php echo 'admin.php?page=ManageClients&client_id=' . intval( $website->client_id ); ?>" data-tooltip="<?php esc_attr_e( 'Jump to the client', 'mainwp' ); ?>" data-position="right center" data-inverted="" ><?php echo esc_html( $website->client_name ); ?></a></td>
                        <td class="right aligned">
                        <?php if ( MainWP_Updates::user_can_update_plugins() ) : ?>
                            <?php if ( ! empty( $plugin_upgrades ) ) : ?>
                                <a href="javascript:void(0)" class="mainwp-update-selected-button ui mini green basic button" onClick="event.stopPropagation(); return updatesoverview_upgrade_plugin_all( <?php echo esc_attr( $website->id ); ?>, true )"><?php esc_html_e( 'Update Selected', 'mainwp' ); ?></a>
                                <a href="javascript:void(0)" class="mainwp-update-now-button ui mini green button" onClick="return updatesoverview_upgrade_plugin_all( <?php echo esc_attr( $website->id ); ?> )"><?php esc_html_e( 'Update', 'mainwp' ); ?></a>
                            <?php endif; ?>
                        <?php endif; ?>
                        </td>
                    </tr>

                    <tr class="child-checkbox content">
                        <td colspan="5">
                            <table id="mainwp-wordpress-updates-groups-inner-table" class="ui table mainwp-manage-updates-item-table">
                                <thead class="mainwp-768-hide">
                                    <tr>
                                    <?php $updates_table_helper->print_column_headers(); ?>
                                    </tr>
                                </thead>
                                <tbody class="plugins-bulk-updates" id="wp_plugin_upgrades_<?php echo intval( $website->id ); ?>" site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo esc_attr( rawurlencode( stripslashes( $website->name ) ) ); ?>">
                                    <?php foreach ( $plugin_upgrades as $slug => $plugin_upgrade ) : ?>
                                        <?php $plugin_name = rawurlencode( $slug ); ?>
                                        <?php
                                        $indent_hidden = '<input type="hidden" id="wp_upgraded_plugin_' . esc_attr( $website->id ) . '_' . $plugin_name . '" value="0" />';
                                        $last_version  = $plugin_upgrade['update']['new_version'];

                                        $row_columns = array(
                                            'title'   => MainWP_System_Utility::get_plugin_icon( dirname( $slug ) ) . '&nbsp;&nbsp;&nbsp;&nbsp;<a href="' . admin_url() . 'plugin-install.php?tab=plugin-information&wpplugin=' . intval( $website->id ) . '&plugin=' . esc_attr( $plugin_upgrade['update']['slug'] ) . '&url=' . ( isset( $plugin_upgrade['PluginURI'] ) ? rawurlencode( $plugin_upgrade['PluginURI'] ) : '' ) . '&name=' . rawurlencode( $plugin_upgrade['Name'] ) . '" target="_blank" class="open-plugin-details-modal">' . esc_html( $plugin_upgrade['Name'] ) . '</a>' . $indent_hidden,
                                            'version' => '<strong class="mainwp-768-show">' . esc_html__( 'Version: ', 'mainwp' ) . '</strong>' . esc_html( $plugin_upgrade['Version'] ),
                                            'latest'  => '<strong class="mainwp-768-show">' . esc_html__( 'Latest: ', 'mainwp' ) . '</strong><a href="' . admin_url() . 'plugin-install.php?tab=plugin-information&wpplugin=' . intval( $website->id ) . '&plugin=' . esc_attr( $plugin_upgrade['update']['slug'] ) . '&url=' . ( isset( $plugin_upgrade['PluginURI'] ) ? rawurlencode( $plugin_upgrade['PluginURI'] ) : '' ) . '&name=' . rawurlencode( $plugin_upgrade['Name'] ) . '&section=changelog" target="_blank" class="open-plugin-details-modal">' . esc_html( $last_version ) . '</a>',
                                            'trusted' => ( in_array( $slug, $trustedPlugins ) ? true : false ),
                                            'status'  => ( isset( $plugin_upgrade['active'] ) && $plugin_upgrade['active'] ) ? true : false,
                                        );

                                        $others = array();
                                        if ( ! empty( $rollPlugins[ $slug ][ $last_version ] ) ) {
                                            $msg                 = MainWP_Updates_Helper::get_roll_msg( $rollPlugins[ $slug ][ $last_version ], true, 'notice' );
                                            $others['roll_info'] = $msg;
                                        }

                                        ?>
                                        <tr plugin_slug="<?php echo esc_attr( $plugin_name ); ?>" last-version="<?php echo esc_attr( rawurlencode( $last_version ) ); ?>" site_name="<?php echo esc_attr( stripslashes( $website->name ) ); ?>" premium="<?php echo isset( $plugin_upgrade['premium'] ) && ! empty( $plugin_upgrade['premium'] ) ? 1 : 0; ?>" updated="0">
                                            <?php
                                            $row_columns     = $updates_table_helper->render_columns( $row_columns, $website, $others );
                                            $action_rendered = isset( $row_columns['action'] ) ? true : false;
                                            if ( ! $action_rendered ) :
                                                ?>
                                            <td class="right aligned">
                                                <?php if ( MainWP_Updates::user_can_ignore_updates() ) : ?>
                                                    <div class="ui top left pointing dropdown mini button">
                                                        <?php esc_html_e( 'Ignore', 'mainwp' ); ?><i class="dropdown icon"></i>
                                                        <div class="menu">
                                                            <a href="javascript:void(0)" onClick="return updatesoverview_plugins_ignore_detail( '<?php echo esc_js( $plugin_name ); ?>', '<?php echo esc_js( rawurlencode( $plugin_upgrade['Name'] ) ); ?>', <?php echo intval( $website->id ); ?>, this, '<?php echo esc_js( rawurlencode( $last_version ) ); ?>' )" class="mainwp-ignore-update-button item"><?php esc_html_e( 'Ignore this version', 'mainwp' ); ?></a>
                                                            <a href="javascript:void(0)" class="item mainwp-ignore-globally-button" onClick="return updatesoverview_plugins_ignore_all( '<?php echo esc_js( $plugin_name ); ?>', '<?php echo esc_attr( rawurlencode( $plugin_upgrade['Name'] ) ); ?>', this, '<?php echo esc_js( rawurlencode( $last_version ) ); ?>' )"><?php esc_html_e( 'Ignore this version globally', 'mainwp' ); ?></a>
                                                            <a href="javascript:void(0)" onClick="return updatesoverview_plugins_ignore_detail( '<?php echo esc_js( $plugin_name ); ?>', '<?php echo esc_js( rawurlencode( $plugin_upgrade['Name'] ) ); ?>', <?php echo intval( $website->id ); ?>, this, 'all_versions' )" class="mainwp-ignore-update-button item"><?php esc_html_e( 'Ignore all versions', 'mainwp' ); ?></a>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ( MainWP_Updates::user_can_update_plugins() ) : ?>
                                                    <a href="javascript:void(0)" class="mainwp-update-now-button ui green mini button" onClick="return updatesoverview_upgrade_plugin( <?php echo esc_attr( $website->id ); ?>, '<?php echo esc_js( $plugin_name ); ?>' )"><?php esc_html_e( 'Update', 'mainwp' ); ?></a>
                                                <?php endif; ?>
                                            </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </td>
                    </tr>
                    <?php
                }
                ?>
            </tbody>
        </table>
        <?php else : ?>
            <?php MainWP_UI::render_empty_page_placeholder( __( 'You\'re All Set!', 'mainwp' ), __( 'No updates available right now.', 'mainwp' ) ); ?>
        <?php endif; ?>
        <?php
    }

    /**
     * Method render_themes_updates()
     *
     * Render Theme updates
     *
     * @param object $websites the websites.
     * @param int    $total_theme_upgrades number of available theme updates.
     * @param mixed  $userExtension user extension.
     * @param array  $trustedThemes trusted themes.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
     * @uses \MainWP\Dashboard\MainWP_UI::render_sorting_icons()
     * @uses \MainWP\Dashboard\MainWP_UI::render_show_all_updates_button()
     * @uses \MainWP\Dashboard\MainWP_UI
     * @uses \MainWP\Dashboard\MainWP_Updates_Table_Helper
     * @uses \MainWP\Dashboard\MainWP_Updates::user_can_update_themes()
     * @uses \MainWP\Dashboard\MainWP_Updates::user_can_ignore_updates()
     * @uses \MainWP\Dashboard\MainWP_Updates::set_continue_update_html_selector()
     * @uses \MainWP\Dashboard\MainWP_Updates::render_site_link_dashboard()
     */
    public static function render_themes_updates( $websites, $total_theme_upgrades, $userExtension, $trustedThemes ) { // phpcs:ignore -- NOSONAR - complex.
        ?>
        <?php if ( 0 < $total_theme_upgrades ) : ?>
        <table class="ui tablet stackable table mainwp-manage-updates-table main-master-checkbox" id="mainwp-themes-updates-sites-table">
            <thead>
                <tr>
                    <th scope="col" class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
                    <th scope="col" class="eight wide indicator-accordion-sorting handle-accordion-sorting">
                        <div class="ui main-master checkbox">
                            <input type="checkbox" name=""><label><?php esc_html_e( 'Website', 'mainwp' ); ?></label>
                        </div>
                    <?php MainWP_UI::render_sorting_icons(); ?>
                    </th>
                    <th scope="col" class="two wide indicator-accordion-sorting handle-accordion-sorting"><?php echo intval( $total_theme_upgrades ) . ' ' . esc_html( _n( 'Update', 'Updates', $total_theme_upgrades, 'mainwp' ) ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
                    <th scope="col" class="two wide indicator-accordion-sorting handle-accordion-sorting"><?php echo esc_html__( 'Client', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
                    <th scope="col" class="four wide no-sort right aligned">
                        <?php MainWP_UI::render_show_all_updates_button(); ?>
                    </th>
                </tr>
            </thead>
            <tbody id="themes-updates-global" class="ui accordion">
                <?php
                $updates_table_helper = new MainWP_Updates_Table_Helper( $userExtension->site_view, 'theme' );
                MainWP_DB::data_seek( $websites, 0 );
                while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
                    if ( $website->is_ignoreThemeUpdates ) {
                        continue;
                    }
                    $theme_upgrades = json_decode( $website->theme_upgrades, true );

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
                                if ( ! is_array( $theme_upgrades ) ) {
                                    $theme_upgrades = array();
                                }

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

                    if ( empty( $theme_upgrades ) && empty( $website->sync_errors ) ) {
                        continue;
                    }
                    ?>
                    <tr class="ui title master-checkbox">
                        <td class="accordion-trigger"><i class="icon dropdown"></i></td>
                        <td>
                            <div class="ui master checkbox">
                                <input type="checkbox" name=""><label><?php MainWP_Updates::render_site_link_dashboard( $website ); ?></label>
                            </div>
                        </td>
                        <td sort-value="<?php echo count( $theme_upgrades ); ?>"><?php echo count( $theme_upgrades ); ?> <?php echo esc_html( _n( 'Update', 'Updates', count( $theme_upgrades ), 'mainwp' ) ); ?></td>
                        <td><a href="<?php echo 'admin.php?page=ManageClients&client_id=' . intval( $website->client_id ); ?>" data-tooltip="<?php esc_attr_e( 'Jump to the client', 'mainwp' ); ?>" data-position="right center" data-inverted="" ><?php echo esc_html( $website->client_name ); ?></a></td>
                        <td class="right aligned">
                        <?php if ( MainWP_Updates::user_can_update_themes() ) : ?>
                            <?php if ( ! empty( $theme_upgrades ) ) : ?>
                                <a href="javascript:void(0)" class="mainwp-update-selected-button ui mini green basic button" onClick="return updatesoverview_upgrade_theme_all( <?php echo esc_attr( $website->id ); ?>, true )"><?php esc_html_e( 'Update Selected', 'mainwp' ); ?></a>
                                <a href="javascript:void(0)" class="mainwp-update-now-button ui mini green button" onClick="return updatesoverview_upgrade_theme_all( <?php echo esc_attr( $website->id ); ?> )"><?php esc_html_e( 'Update', 'mainwp' ); ?></a>
                            <?php endif; ?>
                        <?php endif; ?>
                        </td>
                    </tr>
                    <tr class="child-checkbox content">
                        <td colspan="5">
                            <table id="mainwp-wordpress-updates-groups-inner-table" class="ui table mainwp-manage-updates-item-table mainwp-updates-list">
                                <thead class="mainwp-768-hide">
                                    <tr>
                                    <?php $updates_table_helper->print_column_headers(); ?>
                                    </tr>
                                </thead>
                                <tbody class="themes-bulk-updates" id="wp_theme_upgrades_<?php echo intval( $website->id ); ?>" site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo esc_attr( rawurlencode( stripslashes( $website->name ) ) ); ?>">
                                    <?php foreach ( $theme_upgrades as $slug => $theme_upgrade ) : ?>
                                        <?php $theme_name = rawurlencode( $slug ); ?>
                                        <?php $indent_hidden = '<input type="hidden" id="wp_upgraded_theme_' . esc_attr( $website->id ) . '_' . $theme_name . '" value="0" />'; ?>
                                        <?php

                                        $last_version = $theme_upgrade['update']['new_version'];

                                        $row_columns = array(
                                            'title'   => MainWP_System_Utility::get_theme_icon( $slug ) . '&nbsp;&nbsp;&nbsp;&nbsp;' . esc_html( $theme_upgrade['Name'] ) . $indent_hidden,
                                            'version' => '<strong class="mainwp-768-show">' . esc_html__( 'Version: ', 'mainwp' ) . '</strong>' . esc_html( $theme_upgrade['Version'] ),
                                            'latest'  => '<strong class="mainwp-768-show">' . esc_html__( 'Latest: ', 'mainwp' ) . '</strong>' . esc_html( $last_version ),
                                            'trusted' => ( in_array( $slug, $trustedThemes, true ) ? true : false ),
                                            'status'  => ( isset( $theme_upgrade['active'] ) && $theme_upgrade['active'] ) ? true : false,
                                        );

                                        $others = array();
                                        if ( ! empty( $rollThemes[ $slug ][ $last_version ] ) ) {
                                            $msg                 = MainWP_Updates_Helper::get_roll_msg( $rollThemes[ $slug ][ $last_version ], true, 'notice' );
                                            $others['roll_info'] = $msg;
                                        }
                                        ?>
                                        <tr theme_slug="<?php echo esc_attr( $theme_name ); ?>" last-version="<?php echo esc_attr( rawurlencode( $last_version ) ); ?>" premium="<?php echo isset( $theme_upgrade['premium'] ) && ! empty( $theme_upgrade['premium'] ) ? 1 : 0; ?>" updated="0">
                                            <?php
                                            $row_columns     = $updates_table_helper->render_columns( $row_columns, $website, $others );
                                            $action_rendered = isset( $row_columns['action'] ) ? true : false;
                                            if ( ! $action_rendered ) :
                                                ?>
                                            <td class="right aligned">
                                                <?php if ( MainWP_Updates::user_can_ignore_updates() ) : ?>
                                                    <div class="ui bottom left pointing dropdown mini button">
                                                        <?php esc_html_e( 'Ignore', 'mainwp' ); ?><i class="dropdown icon"></i>
                                                        <div class="menu">
                                                            <a href="javascript:void(0)" onClick="return updatesoverview_themes_ignore_detail( '<?php echo esc_js( $theme_name ); ?>', '<?php echo esc_js( rawurlencode( $theme_upgrade['Name'] ) ); ?>', <?php echo intval( $website->id ); ?>, this, '<?php echo esc_js( rawurlencode( $last_version ) ); ?>' )" class="mainwp-ignore-update-button item"><?php esc_html_e( 'Ignore this version', 'mainwp' ); ?></a>
                                                            <a href="javascript:void(0)" class="item mainwp-ignore-globally-button" onClick="return updatesoverview_themes_ignore_all( '<?php echo esc_js( $theme_name ); ?>', '<?php echo esc_js( rawurlencode( $theme_upgrade['Name'] ) ); ?>', this, '<?php echo esc_js( rawurlencode( $last_version ) ); ?>' )"><?php esc_html_e( 'Ignore this version globally', 'mainwp' ); ?></a>
                                                            <a href="javascript:void(0)" onClick="return updatesoverview_themes_ignore_detail( '<?php echo esc_js( $theme_name ); ?>', '<?php echo esc_js( rawurlencode( $theme_upgrade['Name'] ) ); ?>', <?php echo intval( $website->id ); ?>, this, 'all_versions' )" class="mainwp-ignore-update-button item"><?php esc_html_e( 'Ignore all versions', 'mainwp' ); ?></a>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ( MainWP_Updates::user_can_update_themes() ) : ?>
                                                    <a href="javascript:void(0)" class="mainwp-update-now-button ui green mini button" onClick="return updatesoverview_upgrade_theme( <?php echo esc_attr( $website->id ); ?>, '<?php echo esc_js( $theme_name ); ?>' )"><?php esc_html_e( 'Update', 'mainwp' ); ?></a>
                                                <?php endif; ?>
                                            </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </td>
                    </tr>
                    <?php
                }
                ?>
            </tbody>
        </table>
        <?php else : ?>
            <?php MainWP_UI::render_empty_page_placeholder( __( 'You\'re All Set!', 'mainwp' ), __( 'No updates available right now.', 'mainwp' ) ); ?>
        <?php endif; ?>
        <?php
    }

    /**
     * Method render_trans_update()
     *
     * Render translations updates
     *
     * @param object $websites the websites.
     * @param int    $total_translation_upgrades number of available translation updates.
     * @param object $userExtension User extension data.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
     * @uses \MainWP\Dashboard\MainWP_UI::render_sorting_icons()
     * @uses \MainWP\Dashboard\MainWP_UI::render_show_all_updates_button()
     * @uses \MainWP\Dashboard\MainWP_Updates::user_can_update_trans()
     * @uses \MainWP\Dashboard\MainWP_Updates::render_site_link_dashboard()
     */
    public static function render_trans_update( $websites, $total_translation_upgrades, $userExtension ) {  //phpcs:ignore -- NOSONAR - complex.

        $trustedPlugins = ! empty( $userExtension->trusted_plugins ) ? json_decode( $userExtension->trusted_plugins, true ) : array();
        if ( ! is_array( $trustedPlugins ) ) {
            $trustedPlugins = array();
        }

        $trustedThemes = json_decode( $userExtension->trusted_themes, true );
        if ( ! is_array( $trustedThemes ) ) {
            $trustedThemes = array();
        }

        ?>
        <?php if ( 0 < $total_translation_upgrades ) : ?>
        <table class="ui tablet stackable table mainwp-manage-updates-table main-master-checkbox" id="mainwp-translations-sites-table">
            <thead>
                <tr>
                    <th scope="col" class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
                    <th scope="col" class="eight indicator-accordion-sorting handle-accordion-sorting">
                    <div class="ui main-master checkbox">
                        <input type="checkbox" name=""> <label><?php esc_html_e( 'Website', 'mainwp' ); ?></label>
                    </div>
                    <span class="mainwp-768-hide"><?php MainWP_UI::render_sorting_icons(); ?></span>
                    </th>
                    <th scope="col" class="two wide indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Updates', 'mainwp' ); ?><span class="mainwp-768-hide"><?php MainWP_UI::render_sorting_icons(); ?></span></th>
                    <th scope="col" class="two wide indicator-accordion-sorting handle-accordion-sorting"><?php echo esc_html__( 'Client', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
                    <th scope="col" class="four wide right aligned"><?php MainWP_UI::render_show_all_updates_button(); ?></th>
                </tr>
            </thead>
            <tbody id="translations-updates-global"  class="ui accordion">
                <?php
                MainWP_DB::data_seek( $websites, 0 );
                while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
                    $translation_upgrades = json_decode( $website->translation_upgrades, true );
                    if ( empty( $translation_upgrades ) && empty( $website->sync_errors ) ) {
                        continue;
                    }
                    ?>
                    <tr class="title master-checkbox">
                        <td class="accordion-trigger"><i class="dropdown icon"></i></td>
                        <td>
                        <div class="ui master checkbox">
                            <input type="checkbox" name=""> <label><?php MainWP_Updates::render_site_link_dashboard( $website ); ?></label>
                        </div>
                        </td>
                        <td sort-value="<?php echo count( $translation_upgrades ); ?>">
                            <?php echo count( $translation_upgrades ); ?><?php echo esc_html( _n( 'Update', 'Updates', count( $translation_upgrades ), 'mainwp' ) ); ?>
                        </td>
                        <td><a href="<?php echo 'admin.php?page=ManageClients&client_id=' . intval( $website->client_id ); ?>" data-tooltip="<?php esc_attr_e( 'Jump to the client', 'mainwp' ); ?>" data-position="right center" data-inverted="" ><?php echo esc_html( $website->client_name ); ?></a></td>
                        <td class="right aligned">
                        <?php if ( MainWP_Updates::user_can_update_trans() ) : ?>
                            <?php if ( ! empty( $translation_upgrades ) ) : ?>
                                <a href="javascript:void(0)" class="mainwp-update-selected-button ui mini green basic button" onClick="return updatesoverview_upgrade_translation_all( <?php echo esc_attr( $website->id ); ?>, true )"><?php esc_html_e( 'Update Selected', 'mainwp' ); ?></a>
                                <a href="javascript:void(0)" class="mainwp-update-all-button ui mini green button" onClick="return updatesoverview_upgrade_translation_all( <?php echo esc_attr( $website->id ); ?> )"><?php esc_html_e( 'Update All', 'mainwp' ); ?></a>
                            <?php endif; ?>
                        <?php endif; ?>
                        </td>
                    </tr>
                    <tr class="content child-checkbox">
                        <td colspan="5">
                            <table class="ui table mainwp-manage-updates-item-table" id="mainwp-translations-table">
                                <thead class="mainwp-768-hide">
                                    <tr>
                                        <th scope="col"><?php esc_html_e( 'Translation', 'mainwp' ); ?></th>
                                        <th scope="col"><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
                                        <th scope="col" ><?php esc_html_e( 'Trusted', 'mainwp' ); ?></th>
                                        <th scope="col" class="collapsing no-sort"></th>
                                    </tr>
                                </thead>
                                <tbody class="translations-bulk-updates" id="wp_translation_upgrades_<?php echo esc_attr( $website->id ); ?>" site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo esc_attr( rawurlencode( stripslashes( $website->name ) ) ); ?>">
                                <?php foreach ( $translation_upgrades as $translation_upgrade ) : ?>
                                    <?php
                                    $translation_name = isset( $translation_upgrade['name'] ) ? $translation_upgrade['name'] : $translation_upgrade['slug'];
                                    $translation_slug = $translation_upgrade['slug'];
                                    ?>
                                    <tr translation_slug="<?php echo esc_attr( $translation_slug ); ?>" updated="0">
                                        <td>
                                        <div class="ui child checkbox">
                                                <input type="checkbox" name=""><label><?php echo esc_html( $translation_name ); ?></label>
                                            </div>
                                            <input type="hidden" id="wp_upgraded_translation_<?php echo esc_attr( $website->id ); ?>_<?php echo esc_attr( $translation_slug ); ?>" value="0"/>
                                        </td>
                                        <td>
                                            <strong class="mainwp-768-show"><?php esc_html_e( 'Version:', 'mainwp' ); ?></strong> <?php echo esc_html( $translation_upgrade['version'] ); ?>
                                        </td>
                                        <?php
                                        // trusted column.
                                        $is_trust = MainWP_Manage_Sites_Update_View::is_trans_trusted_update( $translation_upgrade, $trustedPlugins, $trustedThemes );
                                        echo MainWP_Manage_Sites_Update_View::get_column_trusted( $is_trust ); //phpcs:ignore -- NOSONAR - escaped.
                                        ?>
                                        <td class="right aligned">
                                            <?php if ( MainWP_Updates::user_can_update_trans() ) : ?>
                                                <a href="javascript:void(0)" class="mainwp-update-now-button ui green mini button" onClick="return updatesoverview_translations_upgrade( '<?php echo esc_js( $translation_slug ); ?>', <?php echo intval( $website->id ); ?> )"><?php esc_html_e( 'Update', 'mainwp' ); ?></a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </td>
                    </tr>
                    <?php
                }
                ?>
            </tbody>
        </table>
        <?php else : ?>
            <?php MainWP_UI::render_empty_page_placeholder( __( 'You\'re All Set!', 'mainwp' ), __( 'No updates available right now.', 'mainwp' ) ); ?>
        <?php endif; ?>
        <?php
    }

    /**
     * Method render_abandoned_plugins()
     *
     * Render abandoned plugins
     *
     * @param object $websites                the websites.
     * @param array  $allPluginsOutdate       All Plugins Outdate.
     * @param array  $decodedDismissedPlugins all dismissed plugins.
     *
     * @throws \MainWP_Exception Error message.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
     * @uses \MainWP\Dashboard\MainWP_UI::render_sorting_icons()
     * @uses \MainWP\Dashboard\MainWP_Updates::render_site_link_dashboard()
     * @uses \MainWP\Dashboard\MainWP_Updates::user_can_ignore_updates()
     */
    public static function render_abandoned_plugins( $websites, $allPluginsOutdate, $decodedDismissedPlugins ) {  //phpcs:ignore -- NOSONAR - complex.
        $str_format      = esc_html__( 'Updated %s days ago', 'mainwp' );
        $count_abandoned = count( $allPluginsOutdate );
        ?>
        <?php if ( 0 < $count_abandoned ) : ?>
        <table class="ui tablet stackable table mainwp-manage-updates-table" id="mainwp-abandoned-plugins-sites-table">
            <thead>
                <tr>
                    <th scope="col" class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
                    <th scope="col" class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Website', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
                    <th scope="col" class="indicator-accordion-sorting handle-accordion-sorting"><?php echo esc_html__( 'Client', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
                    <th scope="col" class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Abandoned', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
                </tr>
            </thead>
            <tbody class="ui accordion">
                <?php MainWP_DB::data_seek( $websites, 0 ); ?>
                <?php
                while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
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

                    if ( is_array( $decodedDismissedPlugins ) ) {
                        $plugins_outdate = array_diff_key( $plugins_outdate, $decodedDismissedPlugins );
                    }

                    if ( empty( $plugins_outdate ) ) {
                        continue;
                    }

                    ?>

                    <tr class="title">
                        <td class="accordion-trigger"><i class="icon dropdown"></i></td>
                        <td>
                            <?php MainWP_Updates::render_site_link_dashboard( $website ); ?>
                        </td>
                        <td><a href="<?php echo 'admin.php?page=ManageClients&client_id=' . intval( $website->client_id ); ?>" data-tooltip="<?php esc_attr_e( 'Jump to the client', 'mainwp' ); ?>" data-position="right center" data-inverted="" ><?php echo esc_html( $website->client_name ); ?></a></td>
                        <td sort-value="<?php echo count( $plugins_outdate ); ?>"><?php echo count( $plugins_outdate ); ?> <?php echo esc_html( _n( 'Plugin', 'Plugins', count( $plugins_outdate ), 'mainwp' ) ); ?></td>
                    </tr>
                    <tr class="content">
                        <td colspan="4">
                            <table class="ui table mainwp-manage-updates-item-table" id="mainwp-abandoned-plugins-table">
                                <thead class="mainwp-768-hide">
                                    <tr>
                                        <th scope="col" class="no-sort"></th>
                                        <th scope="col"><?php esc_html_e( 'Plugin', 'mainwp' ); ?></th>
                                        <th scope="col"><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
                                        <th scope="col"><?php esc_html_e( 'Last Update', 'mainwp' ); ?></th>
                                        <th scope="col" class="no-sort"></th>
                                    </tr>
                                </thead>
                                <tbody id="wp_plugins_outdate_<?php echo intval( $website->id ); ?>" site_id="<?php echo intval( $website->id ); ?>" site_name="<?php echo esc_attr( rawurlencode( stripslashes( $website->name ) ) ); ?>">
                                    <?php foreach ( $plugins_outdate as $slug => $plugin_outdate ) : ?>
                                        <?php
                                        $plugin_name              = rawurlencode( $slug );
                                        $now                      = new \DateTime();
                                        $last_updated             = $plugin_outdate['last_updated'];
                                        $plugin_last_updated_date = new \DateTime( '@' . $last_updated );
                                        $diff_in_days             = $now->diff( $plugin_last_updated_date )->format( '%a' );
                                        $outdate_notice           = sprintf( $str_format, $diff_in_days );
                                        ?>
                                        <tr dismissed="0">
                                            <td class="collapsing"><?php echo MainWP_System_Utility::get_plugin_icon( dirname( $slug ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                                            <td>
                                                <strong class="mainwp-768-show"><?php esc_html_e( 'Plugin:', 'mainwp' ); ?></strong> <a href="<?php echo esc_url( admin_url() ) . 'plugin-install.php?tab=plugin-information&wpplugin=' . intval( $website->id ) . '&plugin=' . esc_html( dirname( $slug ) ) . '&url=' . ( isset( $plugin_outdate['PluginURI'] ) ? rawurlencode( $plugin_outdate['PluginURI'] ) : '' ) . '&name=' . rawurlencode( $plugin_outdate['Name'] ); ?>" target="_blank" class="open-plugin-details-modal"><?php echo esc_html( $plugin_outdate['Name'] ); ?></a>
                                                <input type="hidden" id="wp_dismissed_plugin_<?php echo esc_attr( $website->id ); ?>_<?php echo esc_attr( $plugin_name ); ?>" value="0"/>
                                            </td>
                                            <td><strong class="mainwp-768-show"><?php esc_html_e( 'Version:', 'mainwp' ); ?></strong> <?php echo esc_html( $plugin_outdate['Version'] ); ?></td>
                                            <td><strong class="mainwp-768-show"><?php esc_html_e( 'Last Update:', 'mainwp' ); ?></strong> <?php echo esc_html( $outdate_notice ); ?></td>
                                            <td class="right aligned" id="wp_dismissbuttons_plugin_<?php echo esc_attr( $website->id ); ?>_<?php echo esc_attr( $plugin_name ); ?>">
                                            <?php if ( MainWP_Updates::user_can_ignore_updates() ) { ?>
                                                <a href="javascript:void(0)" class="mainwp-ignore-now-button ui mini button" onClick="return updatesoverview_plugins_dismiss_outdate_detail( '<?php echo esc_js( $plugin_name ); ?>', '<?php echo esc_js( rawurlencode( $plugin_outdate['Name'] ) ); ?>', <?php echo intval( $website->id ); ?>, this )"><?php esc_html_e( 'Ignore Now', 'mainwp' ); ?></a>
                                            <?php } ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </td>
                    </tr>
                    <?php
                }
                ?>
            </tbody>
        </table>
        <?php else : ?>
            <?php MainWP_UI::render_empty_page_placeholder( __( 'You\'re All Set!', 'mainwp' ), __( 'No abandoned plugins detected.', 'mainwp' ) ); ?>
        <?php endif; ?>
        <?php
    }

    /**
     * Method render_abandoned_themes()
     *
     * Renders abandoned themes table content.
     *
     * @param object $websites the websites.
     * @param array  $allThemesOutdate       All Themes Outdate.
     * @param array  $decodedDismissedThemes all dismissed themes.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
     * @uses \MainWP\Dashboard\MainWP_UI::render_sorting_icons()
     * @uses \MainWP\Dashboard\MainWP_Updates::render_site_link_dashboard()
     * @uses \MainWP\Dashboard\MainWP_Updates::user_can_ignore_updates()
     */
    public static function render_abandoned_themes( $websites, $allThemesOutdate, $decodedDismissedThemes ) { //phpcs:ignore -- NOSONAR - complex.
        $str_format      = esc_html__( 'Updated %s days ago', 'mainwp' );
        $count_abandoned = count( $allThemesOutdate );
        ?>
        <?php if ( 0 < $count_abandoned ) : ?>
        <table class="ui tablet stackable table mainwp-manage-updates-table" id="mainwp-abandoned-themes-sites-table">
            <thead>
                <tr>
                    <th scope="col" class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
                    <th scope="col" class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Website', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
                    <th scope="col" class="indicator-accordion-sorting handle-accordion-sorting"><?php echo esc_html__( 'Client', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
                    <th scope="col" class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Abandoned', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
                </tr>
            </thead>
            <tbody class="ui accordion">
                <?php MainWP_DB::data_seek( $websites, 0 ); ?>
                <?php
                while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
                    $themes_outdate = MainWP_DB::instance()->get_website_option( $website, 'themes_outdate_info' );
                    $themes_outdate = ! empty( $themes_outdate ) ? json_decode( $themes_outdate, true ) : array();

                    if ( is_array( $themes_outdate ) ) {
                        $themesOutdateDismissed = MainWP_DB::instance()->get_website_option( $website, 'themes_outdate_dismissed' );
                        $themesOutdateDismissed = ! empty( $themesOutdateDismissed ) ? json_decode( $themesOutdateDismissed, true ) : array();

                        if ( is_array( $themesOutdateDismissed ) ) {
                            $themes_outdate = array_diff_key( $themes_outdate, $themesOutdateDismissed );
                        }
                        if ( is_array( $decodedDismissedThemes ) ) {
                            $themes_outdate = array_diff_key( $themes_outdate, $decodedDismissedThemes );
                        }
                    } else {
                        $themes_outdate = array();
                    }

                    if ( empty( $themes_outdate ) ) {
                        continue;
                    }

                    ?>
                    <tr class="title">
                        <td class="accordion-trigger"><i class="icon dropdown"></i></td>
                        <td>
                            <div class="ui master checkbox mainwp-768-hide">
                                    <input type="checkbox" name="">
                            </div>
                            <?php MainWP_Updates::render_site_link_dashboard( $website ); ?>
                        </td>
                        <td><a href="<?php echo 'admin.php?page=ManageClients&client_id=' . intval( $website->client_id ); ?>" data-tooltip="<?php esc_attr_e( 'Jump to the client', 'mainwp' ); ?>" data-position="right center" data-inverted="" ><?php echo esc_html( $website->client_name ); ?></a></td>
                        <td sort-value="<?php echo count( $themes_outdate ); ?>"> <?php echo count( $themes_outdate ); ?> <?php echo esc_html( _n( 'Theme', 'Themes', count( $themes_outdate ), 'mainwp' ) ); ?></td>
                    </tr>
                    <tr class="content">
                        <td colspan="4">
                            <table class="ui table mainwp-manage-updates-item-table" id="mainwp-abandoned-themes-table">
                                <thead class="mainwp-768-hide">
                                    <tr>
                                        <th scope="col"><?php esc_html_e( 'Theme', 'mainwp' ); ?></th>
                                        <th scope="col"><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
                                        <th scope="col"><?php esc_html_e( 'Last Update', 'mainwp' ); ?></th>
                                        <th scope="col" class="no-sort"></th>
                                    </tr>
                                </thead>
                                <tbody id="wp_themes_outdate_<?php echo esc_attr( $website->id ); ?>" site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo esc_attr( rawurlencode( stripslashes( $website->name ) ) ); ?>">
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
                                                <strong class="mainwp-768-show"><?php esc_html_e( 'Theme:', 'mainwp' ); ?></strong> <?php echo MainWP_System_Utility::get_theme_icon( $slug ) . '&nbsp;&nbsp;&nbsp;&nbsp;' . esc_html( $theme_outdate['Name'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                                                <input type="hidden" id="wp_dismissed_theme_<?php echo esc_attr( $website->id ); ?>_<?php echo esc_attr( $theme_name ); ?>" value="0"/>
                                            </td>
                                            <td><strong class="mainwp-768-show"><?php esc_html_e( 'Version:', 'mainwp' ); ?></strong> <?php echo esc_html( $theme_outdate['Version'] ); ?></td>
                                            <td><strong class="mainwp-768-show"><?php esc_html_e( 'Last Update:', 'mainwp' ); ?></strong> <?php echo esc_html( $outdate_notice ); ?></td>
                                            <td class="right aligned" id="wp_dismissbuttons_theme_<?php echo esc_attr( $website->id ); ?>_<?php echo esc_attr( $theme_name ); ?>">
                                                <?php if ( MainWP_Updates::user_can_ignore_updates() ) { ?>
                                                    <a href="javascript:void(0)" class="mainwp-ignore-now-button ui mini button" onClick="return updatesoverview_themes_dismiss_outdate_detail( '<?php echo esc_js( $theme_name ); ?>', '<?php echo esc_js( rawurlencode( $theme_outdate['Name'] ) ); ?>', <?php echo intval( $website->id ); ?>, this )"><?php esc_html_e( 'Ignore Now', 'mainwp' ); ?></a>
                                                <?php } ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </td>
                    </tr>
                    <?php
                }
                ?>
            </tbody>
        </table>
        <?php else : ?>
            <?php MainWP_UI::render_empty_page_placeholder( __( 'You\'re All Set!', 'mainwp' ), __( 'No abandoned themes detected.', 'mainwp' ) ); ?>
        <?php endif; ?>
        <?php
    }
}
