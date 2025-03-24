<?php
/**
 * MainWP Updates Per Item.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Updates_Per_Item.
 */
class MainWP_Updates_Per_Item { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

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
     * Method render_plugins_updates()
     *
     * Render Plugin updates
     *
     * @param object $websites the websites.
     * @param int    $total_plugin_upgrades total plugin updates.
     * @param mixed  $userExtension user extension.
     * @param array  $allPlugins all plugins.
     * @param array  $pluginsInfo pugins information.
     * @param array  $trustedPlugins trusted plugins.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
     * @uses \MainWP\Dashboard\MainWP_UI
     * @uses \MainWP\Dashboard\MainWP_UI::render_sorting_icons()
     * @uses \MainWP\Dashboard\MainWP_UI::render_show_all_updates_button()
     * @uses \MainWP\Dashboard\MainWP_Updates_Table_Helper
     * @uses \MainWP\Dashboard\MainWP_Updates::user_can_ignore_updates()
     * @uses \MainWP\Dashboard\MainWP_Updates::user_can_update_plugins()
     * @uses \MainWP\Dashboard\MainWP_Updates::set_continue_update_html_selector()
     * @uses \MainWP\Dashboard\MainWP_Updates::render_site_link_dashboard()
     * @uses \MainWP\Dashboard\MainWP_Updates::get_continue_update_selector()
     */
    public static function render_plugins_updates( $websites, $total_plugin_upgrades, $userExtension, $allPlugins, $pluginsInfo, $trustedPlugins ) { // phpcs:ignore -- NOSONAR - complex.
        ?>
        <?php if ( 0 < $total_plugin_upgrades ) : ?>
        <table class="ui tablet stackable table mainwp-manage-updates-table main-master-checkbox" id="mainwp-plugins-updates-table">
            <thead>
                <tr>
                    <th scope="col" class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
                    <th scope="col" class="eight wide indicator-accordion-sorting handle-accordion-sorting">
                    <div class="ui main-master checkbox ">
                        <input type="checkbox" name=""><label><?php esc_html_e( 'Plugin', 'mainwp' ); ?></label>
                    </div>
                    <?php MainWP_UI::render_sorting_icons(); ?>
                    </th>
                    <th scope="col" class="two wide indicator-accordion-sorting handle-accordion-sorting"><?php echo intval( $total_plugin_upgrades ) . ' ' . esc_html( _n( 'Update', 'Updates', $total_plugin_upgrades, 'mainwp' ) ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
                    <th scope="col" class="two wide indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Trusted', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
                    <th scope="col" class="four wide no-sort right aligned"><?php MainWP_UI::render_show_all_updates_button(); ?></th>
                </tr>
            </thead>
            <?php
            $updates_table_helper = new MainWP_Updates_Table_Helper( $userExtension->site_view );
            ?>
            <tbody id="plugins-updates-global" class="ui accordion">
                <?php foreach ( $allPlugins as $slug => $val ) { ?>
                    <?php
                    $cnt         = intval( $val['cnt'] );
                    $plugin_name = rawurlencode( $slug );
                    $trusted     = in_array( $slug, $trustedPlugins ) ? 1 : 0;
                    ?>
                    <tr class="ui title master-checkbox">
                        <td class="accordion-trigger"><i class="icon dropdown"></i></td>
                        <td class="middle aligned">
                            <div class="ui master checkbox">
                                <input type="checkbox" name="">
                            </div>
                            &nbsp;&nbsp;<?php echo MainWP_System_Utility::get_plugin_icon( $pluginsInfo[ $slug ]['slug'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>&nbsp;&nbsp;&nbsp;&nbsp;
                            <a href="<?php echo esc_url( admin_url() ) . 'plugin-install.php?tab=plugin-information&plugin=' . esc_attr( $pluginsInfo[ $slug ]['slug'] ) . '&url=' . ( isset( $pluginsInfo[ $slug ]['PluginURI'] ) ? rawurlencode( $pluginsInfo[ $slug ]['PluginURI'] ) : '' ) . '&name=' . rawurlencode( $pluginsInfo[ $slug ]['name'] ); ?>" target="_blank" class="open-plugin-details-modal" open-wpplugin="yes">
                                <?php echo esc_html( $pluginsInfo[ $slug ]['name'] ); ?>
                            </a>
                        </td>
                        <td sort-value="<?php echo intval( $cnt ); ?>"><?php echo intval( $cnt ); ?> <?php echo esc_html( _n( 'Update', 'Updates', $cnt, 'mainwp' ) ); ?></td>
                        <td sort-value="<?php echo esc_attr( $trusted ); ?>"><?php echo $trusted ? '<span class="ui tiny green label">Trusted</span>' : '<span class="ui tiny grey label">Not Trusted</span>'; ?></td>
                        <td class="right aligned">
                            <?php if ( MainWP_Updates::user_can_ignore_updates() ) { ?>
                                <a href="javascript:void(0)" class="ui mini button mainwp-ignore-globally-button" onClick="return updatesoverview_plugins_ignore_all( '<?php echo esc_js( $plugin_name ); ?>', '<?php echo esc_attr( rawurlencode( $pluginsInfo[ $slug ]['name'] ) ); ?>', this )"><?php esc_html_e( 'Ignore Globally', 'mainwp' ); ?></a>
                            <?php } ?>
                            <?php if ( MainWP_Updates::user_can_update_plugins() ) { ?>
                                <?php if ( 0 < $cnt ) { ?>
                                    <?php
                                    if ( MAINWP_VIEW_PER_PLUGIN_THEME === (int) $userExtension->site_view ) {
                                        MainWP_Updates::set_continue_update_html_selector( 'plugins_upgrade_all', $slug );
                                    }
                                    ?>
                                    <a href="javascript:void(0)" class="mainwp-update-selected-button ui mini basic button green <?php MainWP_Updates::get_continue_update_selector(); ?>" onClick="event.stopPropagation(); updatesoverview_plugins_upgrade_all( '<?php echo esc_js( $plugin_name ); ?>', '<?php echo esc_attr( rawurlencode( $pluginsInfo[ $slug ]['name'] ) ); ?>', true ); return false;"><?php esc_html_e( 'Update Selected', 'mainwp' ); ?></a>
                                    <a href="javascript:void(0)" class="mainwp-update-all-button ui mini button green <?php MainWP_Updates::get_continue_update_selector(); ?>" onClick="return updatesoverview_plugins_upgrade_all( '<?php echo esc_js( $plugin_name ); ?>', '<?php echo esc_attr( rawurlencode( $pluginsInfo[ $slug ]['name'] ) ); ?>' )"><?php esc_html_e( 'Update All', 'mainwp' ); ?></a>
                                <?php } ?>
                            <?php } ?>
                        </td>
                    </tr>
                    <tr class="plugins-bulk-updates child-checkbox content" plugin_slug="<?php echo esc_attr( $plugin_name ); ?>" plugin_name="<?php echo esc_attr( rawurlencode( $pluginsInfo[ $slug ]['name'] ) ); ?>" premium="<?php echo $pluginsInfo[ $slug ]['premium'] ? 1 : 0; ?>">
                        <td colspan="7">
                            <table id="mainwp-plugins-updates-sites-inner-table" class="ui table mainwp-manage-updates-table">
                                <thead class="mainwp-768-hide">
                                    <tr>
                                    <?php $updates_table_helper->print_column_headers(); ?>
                                    </tr>
                                </thead>
                                <tbody plugin_slug="<?php echo esc_attr( $plugin_name ); ?>" >
                                    <?php
                                    $first_wpplugin = true;
                                    MainWP_DB::data_seek( $websites, 0 );
                                    while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
                                        if ( $website->is_ignorePluginUpdates ) {
                                            continue;
                                        }
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
                                                    if ( ! isset( $plugin_upgrades[ $crrSlug ] ) ) {
                                                        continue;
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

                                        if ( ! isset( $plugin_upgrades[ $slug ] ) ) {
                                            continue;
                                        }
                                        $plugin_upgrade = $plugin_upgrades[ $slug ];

                                        $last_version = $plugin_upgrade['update']['new_version'];
                                        $row_columns  = array(
                                            'title'   => MainWP_Updates::render_site_link_dashboard( $website, false ),
                                            'login'   => '<a href="admin.php?page=SiteOpen&newWindow=yes&websiteid=' . intval( $website->id ) . '&_opennonce=' . wp_create_nonce( 'mainwp-admin-nonce' ) . '" data-tooltip="' . esc_attr__( 'Jump to the site WP Admin', 'mainwp' ) . '"  data-position="bottom right"  data-inverted="" class="open_newwindow_wpadmin" target="_blank"><i class="sign in icon"></i></a>',
                                            'version' => '<strong class="mainwp-768-show">' . esc_html__( 'Version: ', 'mainwp' ) . '</strong>' . esc_html( $plugin_upgrade['Version'] ),
                                            'latest'  => '<strong class="mainwp-768-show">' . esc_html__( 'Latest: ', 'mainwp' ) . '</strong><a href="' . admin_url() . 'plugin-install.php?tab=plugin-information&wpplugin=' . intval( $website->id ) . '&plugin=' . esc_attr( $plugin_upgrade['update']['slug'] ) . '&url=' . ( isset( $plugin_upgrade['PluginURI'] ) ? rawurlencode( $plugin_upgrade['PluginURI'] ) : '' ) . '&name=' . rawurlencode( $plugin_upgrade['Name'] ) . '&section=changelog" target="_blank" class="open-plugin-details-modal">' . esc_html( $last_version ) . '</a>',
                                            'trusted' => ( in_array( $slug, $trustedPlugins ) ? true : false ),
                                            'status'  => ( isset( $plugin_upgrade['active'] ) && $plugin_upgrade['active'] ) ? true : false,
                                            'client'  => ( isset( $website->client_name ) && '' !== $website->client_name ) ? '<a href="admin.php?page=ManageClients&client_id=' . intval( $website->client_id ) . '" data-tooltip="' . __( 'Jump to the client', 'mainwp' ) . '" data-position="right center" data-inverted="">' . $website->client_name . '</a>' : '',
                                        );

                                        $others = array();
                                        if ( ! empty( $rollPlugins[ $slug ][ $last_version ] ) ) {
                                            $msg                 = MainWP_Updates_Helper::get_roll_msg( $rollPlugins[ $slug ][ $last_version ], true, 'notice' );
                                            $others['roll_info'] = $msg;
                                        }

                                        ?>
                                        <tr site_id="<?php echo esc_attr( $website->id ); ?>" plugin_slug="<?php echo esc_attr( $plugin_name ); ?>" last-version="<?php echo esc_attr( rawurlencode( $last_version ) ); ?>" site_name="<?php echo esc_attr( stripslashes( $website->name ) ); ?>" updated="0" <?php echo $first_wpplugin ? 'open-wpplugin-siteid="' . intval( $website->id ) . '"' : ''; ?>>
                                            <?php
                                            $row_columns     = $updates_table_helper->render_columns( $row_columns, $website, $others );
                                            $action_rendered = isset( $row_columns['action'] ) ? true : false;
                                            if ( ! $action_rendered ) {
                                                ?>
                                                <td class="right aligned">
                                                    <?php if ( MainWP_Updates::user_can_ignore_updates() ) : ?>
                                                        <div class="ui top left pointing dropdown mini button">
                                                            <?php esc_html_e( 'Ignore', 'mainwp' ); ?><i class="dropdown icon"></i>
                                                            <div class="menu">
                                                                <a href="javascript:void(0)" class="mainwp-ignore-update-button item" onClick="return updatesoverview_plugins_ignore_detail( '<?php echo esc_js( $plugin_name ); ?>', '<?php echo esc_js( rawurlencode( $plugin_upgrade['Name'] ) ); ?>', <?php echo intval( $website->id ); ?>, this, '<?php echo esc_js( rawurlencode( $last_version ) ); ?>' )"><?php esc_html_e( 'Ignore this version', 'mainwp' ); ?></a>
                                                                <a href="javascript:void(0)" class="mainwp-ignore-globally-button item" onClick="return updatesoverview_plugins_ignore_all( '<?php echo esc_js( $plugin_name ); ?>', '<?php echo esc_attr( rawurlencode( $pluginsInfo[ $slug ]['name'] ) ); ?>', this, '<?php echo esc_js( rawurlencode( $last_version ) ); ?>' )"><?php esc_html_e( 'Ignore this version globally', 'mainwp' ); ?></a>
                                                                <a href="javascript:void(0)" class="mainwp-ignore-update-button item" onClick="return updatesoverview_plugins_ignore_detail( '<?php echo esc_js( $plugin_name ); ?>', '<?php echo esc_js( rawurlencode( $plugin_upgrade['Name'] ) ); ?>', <?php echo intval( $website->id ); ?>, this, 'all_versions' )"><?php esc_html_e( 'Ignore all versions', 'mainwp' ); ?></a>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ( MainWP_Updates::user_can_update_plugins() ) : ?>
                                                        <a href="javascript:void(0)" class="mainwp-update-now-button ui mini green button" onClick="return updatesoverview_plugins_upgrade( '<?php echo esc_js( $plugin_name ); ?>', <?php echo intval( $website->id ); ?> )"><?php esc_html_e( 'Update', 'mainwp' ); ?></a>
                                                    <?php endif; ?>
                                                </td>
                                            <?php } ?>
                                        </tr>
                                        <?php
                                        $first_wpplugin = false;
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </td>
                    </tr>
                <?php } ?>
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
     * Render theme updates
     *
     * @param object $websites the websites.
     * @param int    $total_theme_upgrades total theme updates.
     * @param mixed  $userExtension user extension.
     * @param array  $allThemes all themes.
     * @param array  $themesInfo themes information.
     * @param array  $trustedThemes trusted themes.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
     * @uses \MainWP\Dashboard\MainWP_UI
     * @uses \MainWP\Dashboard\MainWP_UI::render_sorting_icons()
     * @uses \MainWP\Dashboard\MainWP_UI::render_show_all_updates_button()
     * @uses \MainWP\Dashboard\MainWP_Updates_Table_Helper
     * @uses \MainWP\Dashboard\MainWP_Updates::user_can_update_themes()
     * @uses \MainWP\Dashboard\MainWP_Updates::set_continue_update_html_selector()
     * @uses \MainWP\Dashboard\MainWP_Updates::user_can_ignore_updates()
     * @uses \MainWP\Dashboard\MainWP_Updates::render_site_link_dashboard()
     * @uses \MainWP\Dashboard\MainWP_Updates::get_continue_update_selector()
     */
    public static function render_themes_updates( $websites, $total_theme_upgrades, $userExtension, $allThemes, $themesInfo, $trustedThemes ) { // phpcs:ignore -- NOSONAR - complex.
        $updates_table_helper = new MainWP_Updates_Table_Helper( $userExtension->site_view, 'theme' );
        ?>
        <?php if ( 0 < $total_theme_upgrades ) : ?>
        <table class="ui tablet stackable table mainwp-manage-updates-table main-master-checkbox" id="mainwp-themes-updates-table">
            <thead>
                <tr>
                    <th scope="col" class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
                    <th scope="col" class="eight wide handle-accordion-sorting indicator-accordion-sorting">
                        <div class="ui main-master checkbox ">
                            <input type="checkbox" name=""><label><?php esc_html_e( 'Theme', 'mainwp' ); ?></label>
                        </div>
                        <?php MainWP_UI::render_sorting_icons(); ?>
                    </th>
                    <th scope="col" class="two wide handle-accordion-sorting indicator-accordion-sorting"><?php echo intval( $total_theme_upgrades ) . ' ' . esc_html( _n( 'Update', 'Updates', $total_theme_upgrades, 'mainwp' ) ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
                    <th scope="col" class="two wide handle-accordion-sorting indicator-accordion-sorting"><?php esc_html_e( 'Trusted', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
                    <th scope="col" class="four wide no-sort right aligned"><?php MainWP_UI::render_show_all_updates_button(); ?></th>
                </tr>
            </thead>
            <tbody id="themes-updates-global" class="ui accordion">
                <?php foreach ( $allThemes as $slug => $val ) { ?>
                    <?php
                    $cnt        = intval( $val['cnt'] );
                    $theme_name = rawurlencode( $slug );
                    $trusted    = in_array( $slug, $trustedThemes ) ? 1 : 0;
                    ?>
                    <tr class="ui title master-checkbox">
                        <td class="accordion-trigger"><i class="icon dropdown"></i></td>
                        <td>
                        <div class="ui master checkbox">
                            <input type="checkbox" name=""><label><?php echo MainWP_System_Utility::get_theme_icon( $slug ); // phpcs:ignore WordPress.Security.EscapeOutput ?>&nbsp;&nbsp;&nbsp;&nbsp;<?php echo esc_html( $themesInfo[ $slug ]['name'] ); ?></label>
                        </div>
                        </td>
                        <td sort-value="<?php echo intval( $cnt ); ?>"><?php echo intval( $cnt ); ?> <?php echo esc_html( _n( 'Update', 'Updates', $cnt, 'mainwp' ) ); ?></td>
                        <td sort-value="<?php echo esc_attr( $trusted ); ?>"><?php echo $trusted ? '<span class="ui tiny green label">Trusted</span>' : '<span class="ui tiny grey label">Not Trusted</span>'; ?></td>
                        <td class="right aligned">
                            <?php if ( MainWP_Updates::user_can_ignore_updates() ) { ?>
                                <a href="javascript:void(0)" class="ui mini button mainwp-ignore-globally-button" onClick="return updatesoverview_themes_ignore_all( '<?php echo esc_js( $theme_name ); ?>', '<?php echo esc_js( rawurlencode( $themesInfo[ $slug ]['name'] ) ); ?>', this )"><?php esc_html_e( 'Ignore Globally', 'mainwp' ); ?></a>
                            <?php } ?>
                            <?php if ( MainWP_Updates::user_can_update_themes() ) { ?>
                                <?php
                                if ( 0 < $cnt ) {
                                    if ( MAINWP_VIEW_PER_PLUGIN_THEME === (int) $userExtension->site_view ) {
                                        MainWP_Updates::set_continue_update_html_selector( 'themes_upgrade_all', $slug );
                                    }
                                    ?>
                                    <a href="javascript:void(0)" class="mainwp-update-selected-button ui mini basic button green <?php MainWP_Updates::get_continue_update_selector(); ?>" onClick="updatesoverview_themes_upgrade_all( '<?php echo esc_js( $theme_name ); ?>', '<?php echo esc_js( rawurlencode( $themesInfo[ $slug ]['name'] ) ); ?>', true ); return false;"><?php esc_html_e( 'Update Selected', 'mainwp' ); ?></a>
                                    <a href="javascript:void(0)" class="mainwp-update-all-button ui mini button green <?php MainWP_Updates::get_continue_update_selector(); ?>" onClick="updatesoverview_themes_upgrade_all( '<?php echo esc_js( $theme_name ); ?>', '<?php echo esc_js( rawurlencode( $themesInfo[ $slug ]['name'] ) ); ?>' ); return false;"><?php esc_html_e( 'Update All', 'mainwp' ); ?></a>

                                <?php } ?>
                            <?php } ?>
                        </td>
                    </tr>
                    <tr class="themes-bulk-updates child-checkbox content"  theme_slug="<?php echo esc_attr( $theme_name ); ?>" theme_name="<?php echo esc_attr( rawurlencode( $themesInfo[ $slug ]['name'] ) ); ?>" premium="<?php echo $themesInfo[ $slug ]['premium'] ? 1 : 0; ?>">
                        <td colspan="5">
                            <table id="mainwp-themes-updates-sites-inner-table" class="ui table mainwp-manage-updates-table mainwp-updates-list">
                                <thead class="mainwp-768-hide">
                                    <tr>
                                    <?php $updates_table_helper->print_column_headers(); ?>
                                    </tr>
                                </thead>
                                <tbody theme_slug="<?php echo esc_attr( $theme_name ); ?>">
                                    <?php
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

                                        if ( ! isset( $theme_upgrades[ $slug ] ) ) {
                                            continue;
                                        }
                                        $theme_upgrade = $theme_upgrades[ $slug ];
                                        $last_version  = $theme_upgrade['update']['new_version'];
                                        $row_columns   = array(
                                            'title'   => MainWP_Updates::render_site_link_dashboard( $website, false ),
                                            'login'   => '<a href="admin.php?page=SiteOpen&newWindow=yes&websiteid=' . intval( $website->id ) . '&_opennonce=' . wp_create_nonce( 'mainwp-admin-nonce' ) . '" data-tooltip="' . esc_attr__( 'Jump to the site WP Admin', 'mainwp' ) . '"  data-position="bottom right"  data-inverted="" class="open_newwindow_wpadmin" target="_blank"><i class="sign in icon"></i></a>',
                                            'version' => '<strong class="mainwp-768-show">' . esc_html__( 'Version: ', 'mainwp' ) . '</strong>' . esc_html( $theme_upgrade['Version'] ),
                                            'latest'  => '<strong class="mainwp-768-show">' . esc_html__( 'Latest: ', 'mainwp' ) . '</strong>' . esc_html( $last_version ),
                                            'trusted' => ( in_array( $slug, $trustedThemes ) ? true : false ),
                                            'status'  => ( isset( $theme_upgrade['active'] ) && $theme_upgrade['active'] ) ? true : false,
                                            'client'  => ( isset( $website->client_name ) && '' !== $website->client_name ) ? '<a href="admin.php?page=ManageClients&client_id=' . intval( $website->client_id ) . '" data-tooltip="' . __( 'Jump to the client', 'mainwp' ) . '" data-position="right center" data-inverted="">' . $website->client_name . '</a>' : '',
                                        );

                                        $others = array();
                                        if ( ! empty( $rollThemes[ $slug ][ $last_version ] ) ) {
                                            $msg                 = MainWP_Updates_Helper::get_roll_msg( $rollThemes[ $slug ][ $last_version ], true, 'notice' );
                                            $others['roll_info'] = $msg;
                                        }

                                        ?>
                                        <tr site_id="<?php echo esc_attr( $website->id ); ?>" theme_slug="<?php echo esc_attr( $theme_name ); ?>" last-version="<?php echo esc_attr( rawurlencode( $last_version ) ); ?>" site_name="<?php echo esc_attr( rawurlencode( stripslashes( $website->name ) ) ); ?>" updated="0">
                                            <?php
                                            $row_columns     = $updates_table_helper->render_columns( $row_columns, $website, $others );
                                            $action_rendered = isset( $row_columns['action'] ) ? true : false;
                                            if ( ! $action_rendered ) {
                                                ?>
                                                <td class="right aligned">
                                                    <?php if ( MainWP_Updates::user_can_ignore_updates() ) { ?>
                                                    <div class="ui top left pointing dropdown mini button">
                                                        <?php esc_html_e( 'Ignore', 'mainwp' ); ?><i class="dropdown icon"></i>
                                                        <div class="menu">
                                                            <a href="javascript:void(0)" class="mainwp-ignore-update-button item" onClick="return updatesoverview_themes_ignore_detail( '<?php echo esc_js( $theme_name ); ?>', '<?php echo esc_js( rawurlencode( $theme_upgrade['Name'] ) ); ?>', <?php echo intval( $website->id ); ?>, this, '<?php echo esc_js( rawurlencode( $last_version ) ); ?>' )"><?php esc_html_e( 'Ignore this version', 'mainwp' ); ?></a>
                                                            <a href="javascript:void(0)" class="item mainwp-ignore-globally-button" onClick="return updatesoverview_themes_ignore_all( '<?php echo esc_js( $theme_name ); ?>', '<?php echo esc_js( rawurlencode( $theme_upgrade['Name'] ) ); ?>', this, '<?php echo esc_js( rawurlencode( $last_version ) ); ?>' )"><?php esc_html_e( 'Ignore this version globally', 'mainwp' ); ?></a>
                                                            <a href="javascript:void(0)" class="mainwp-ignore-update-button item" onClick="return updatesoverview_themes_ignore_detail( '<?php echo esc_js( $theme_name ); ?>', '<?php echo esc_js( rawurlencode( $theme_upgrade['Name'] ) ); ?>', <?php echo intval( $website->id ); ?>, this, 'all_versions' )"><?php esc_html_e( 'Ignore all versions', 'mainwp' ); ?></a>
                                                        </div>
                                                    </div>
                                                    <?php } ?>
                                                    <?php if ( MainWP_Updates::user_can_update_themes() ) : ?>
                                                    <a href="javascript:void(0)" class="mainwp-update-now-button ui mini green button" onClick="return updatesoverview_themes_upgrade( '<?php echo esc_js( $theme_name ); ?>', <?php echo intval( $website->id ); ?> )"><?php esc_html_e( 'Update', 'mainwp' ); ?></a>
                                                    <?php endif; ?>
                                                </td>
                                            <?php } ?>
                                        </tr>
                                        <?php
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </td>
                    </tr>
                <?php } ?>
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
     * @param int    $total_translation_upgrades total translation updates.
     * @param mixed  $userExtension user extension.
     * @param array  $allTranslations all translations.
     * @param array  $translationsInfo translations information.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
     * @uses \MainWP\Dashboard\MainWP_UI::render_sorting_icons()
     * @uses \MainWP\Dashboard\MainWP_UI::render_show_all_updates_button()
     * @uses \MainWP\Dashboard\MainWP_Updates::user_can_update_trans()
     * @uses \MainWP\Dashboard\MainWP_Updates::set_continue_update_html_selector()
     * @uses \MainWP\Dashboard\MainWP_Updates::get_continue_update_selector()
     * @uses \MainWP\Dashboard\MainWP_Updates::render_site_link_dashboard()
     */
    public static function render_trans_update( $websites, $total_translation_upgrades, $userExtension, $allTranslations, $translationsInfo ) { // phpcs:ignore -- NOSONAR - complex.

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
                    <th scope="col" class="eight wide indicator-accordion-sorting handle-accordion-sorting">
                        <div class="ui main-master checkbox ">
                            <input type="checkbox" name=""><label><?php esc_html_e( 'Translation', 'mainwp' ); ?></label>
                        </div>
                        <?php MainWP_UI::render_sorting_icons(); ?>
                    </th>
                    <th scope="col" class="two wide indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Updates', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
                    <th scope="col" class="six wide right aligned"><?php MainWP_UI::render_show_all_updates_button(); ?></th>
                </tr>
            </thead>
            <tbody id="translations-updates-global" class="ui accordion">
                <?php foreach ( $allTranslations as $slug => $val ) { ?>
                    <?php $cnt = intval( $val['cnt'] ); ?>
                    <tr class="title master-checkbox">
                        <td class="accordion-trigger"><i class="dropdown icon"></i></td>
                        <td>
                        <div class="ui master checkbox">
                            <input type="checkbox" name=""><label><?php echo esc_html( $translationsInfo[ $slug ]['name'] ); ?></label>
                        </div>
                        </td>
                        <td sort-value="<?php echo intval( $cnt ); ?>"><?php echo intval( $cnt ); ?> <?php echo esc_html( _n( 'Update', 'Updates', $cnt, 'mainwp' ) ); ?></td>
                        <td class="right aligned">
                        <?php if ( MainWP_Updates::user_can_update_trans() ) { ?>
                            <?php
                            if ( 0 < $cnt ) {
                                if ( MAINWP_VIEW_PER_PLUGIN_THEME === (int) $userExtension->site_view ) {
                                    MainWP_Updates::set_continue_update_html_selector( 'translations_upgrade_all', $slug );
                                }
                                ?>
                                <a href="javascript:void(0)" class="mainwp-update-all-button ui mini button green <?php MainWP_Updates::get_continue_update_selector(); ?>" onClick="updatesoverview_translations_upgrade_all( '<?php echo esc_js( $slug ); ?>', '<?php echo esc_js( rawurlencode( $translationsInfo[ $slug ]['name'] ) ); ?>' ); return false;"><?php esc_html_e( 'Update All', 'mainwp' ); ?></a>
                            <?php } ?>
                        <?php } ?>
                        </td>
                    </tr>
                    <tr class="child-checkbox content">
                        <td colspan="4">
                            <table class="ui table mainwp-manage-updates-table" id="mainwp-translations-sites-table">
                                <thead class="mainwp-768-hide">
                                    <tr>
                                        <th scope="col"><?php esc_html_e( 'Website', 'mainwp' ); ?></th>
                                        <th scope="col"><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
                                        <th scope="col" ><?php esc_html_e( 'Trusted', 'mainwp' ); ?></th>
                                        <th scope="col"><?php esc_html_e( 'Client', 'mainwp' ); ?></th>
                                        <th scope="col" class="collapsing no-sort"></th>
                                    </tr>
                                </thead>
                                <tbody class="translations-bulk-updates" translation_slug="<?php echo esc_attr( $slug ); ?>" translation_name="<?php echo esc_attr( rawurlencode( $translationsInfo[ $slug ]['name'] ) ); ?>">
                                    <?php
                                    MainWP_DB::data_seek( $websites, 0 );
                                    while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
                                        $translation_upgrades = json_decode( $website->translation_upgrades, true );
                                        $translation_upgrade  = null;
                                        foreach ( $translation_upgrades as $current_translation_upgrade ) {
                                            if ( $current_translation_upgrade['slug'] === $slug ) {
                                                $translation_upgrade = $current_translation_upgrade;
                                                break;
                                            }
                                        }
                                        if ( null === $translation_upgrade ) {
                                            continue;
                                        }
                                        ?>
                                        <tr site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo esc_attr( rawurlencode( stripslashes( $website->name ) ) ); ?>" updated="0">
                                            <td>
                                            <div class="ui child checkbox">
                                                <input type="checkbox" name="">
                                            </div>
                                            <?php MainWP_Updates::render_site_link_dashboard( $website ); ?>
                                            </td>
                                            <td><strong class="mainwp-768-show"><?php esc_html_e( 'Version:', 'mainwp' ); ?></strong> <?php echo esc_html( $translation_upgrade['version'] ); ?></td>
                                            <?php
                                            // trusted column.
                                            $is_trust = MainWP_Manage_Sites_Update_View::is_trans_trusted_update( $translation_upgrade, $trustedPlugins, $trustedThemes );
                                            echo MainWP_Manage_Sites_Update_View::get_column_trusted($is_trust ); //phpcs:ignore -- NOSONAR - escaped.
                                            ?>
                                            <td><a href="<?php echo 'admin.php?page=ManageClients&client_id=' . intval( $website->client_id ); ?>" data-tooltip="<?php esc_attr_e( 'Jump to the client', 'mainwp' ); ?>" data-position="right center" data-inverted="" ><?php echo esc_html( $website->client_name ); ?></a></td>
                                            <td class="right aligned">
                                                <?php if ( MainWP_Updates::user_can_update_trans() ) : ?>
                                                    <a href="javascript:void(0)" class="mainwp-update-now-button ui green mini button" onClick="return updatesoverview_upgrade_translation( <?php echo esc_attr( $website->id ); ?>, '<?php echo esc_js( $slug ); ?>' )"><?php esc_html_e( 'Update', 'mainwp' ); ?></a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </td>
                    </tr>
                <?php } ?>
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
     * @param array  $allPluginsOutdate       All abandoned plugins.
     * @param array  $decodedDismissedPlugins all dismissed abandoned plugins.
     *
     * @throws \MainWP_Exception Error message.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
     * @uses \MainWP\Dashboard\MainWP_UI::render_sorting_icons()
     * @uses \MainWP\Dashboard\MainWP_UI::render_show_all_updates_button()
     * @uses \MainWP\Dashboard\MainWP_Updates::user_can_ignore_updates()
     * @uses \MainWP\Dashboard\MainWP_Updates::render_site_link_dashboard()
     */
    public static function render_abandoned_plugins( $websites, $allPluginsOutdate, $decodedDismissedPlugins ) { // phpcs:ignore -- NOSONAR - complex.
        $str_format      = esc_html__( 'Updated %s days ago', 'mainwp' );
        $count_abandoned = count( $allPluginsOutdate );
        ?>
        <?php if ( 0 < $count_abandoned ) : ?>
        <table class="ui tablet stackable table mainwp-manage-updates-table" id="mainwp-abandoned-plugins-items-table">
            <thead>
                <tr>
                    <th scope="col" class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
                    <th scope="col" class="no-sort"></th>
                    <th scope="col" class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Plugin', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
                    <th scope="col" class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Abandoned', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
                    <th scope="col" class="collapsing no-sort"><?php MainWP_UI::render_show_all_updates_button(); ?></th>
                </tr>
            </thead>
            <tbody class="ui accordion">
            <?php foreach ( $allPluginsOutdate as $slug => $val ) { ?>
                <?php
                $cnt         = intval( $val['cnt'] );
                $plugin_name = rawurlencode( $slug );
                ?>
                <tr class="title">
                    <td class="accordion-trigger"><i class="dropdown icon"></i></td>
                    <td class="collapsing"><?php echo MainWP_System_Utility::get_plugin_icon( dirname( $slug ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                    <td><a href="<?php echo esc_url( admin_url() ) . 'plugin-install.php?tab=plugin-information&plugin=' . esc_html( dirname( $slug ) ) . '&url=' . ( isset( $val['uri'] ) ? rawurlencode( $val['uri'] ) : '' ) . '&name=' . rawurlencode( $val['name'] ); ?>" target="_blank" class="open-plugin-details-modal" open-wpplugin="yes"><?php echo esc_html( $val['name'] ); ?></a></td>
                    <td sort-value="<?php echo intval( $cnt ); ?>"><?php echo intval( $cnt ); ?> <?php echo esc_html( _n( 'Website', 'Websites', $cnt, 'mainwp' ) ); ?></td>
                    <td class="right aligned">
                        <?php if ( MainWP_Updates::user_can_ignore_updates() ) { ?>
                            <a href="javascript:void(0)" class="ui mini green mainwp-ignore-globally-button button" onClick="return updatesoverview_plugins_abandoned_ignore_all( '<?php echo esc_js( $plugin_name ); ?>', '<?php echo esc_js( rawurlencode( $val['name'] ) ); ?>', this )"><?php esc_html_e( 'Ignore Globally', 'mainwp' ); ?></a>
                        <?php } ?>
                    </td>
                </tr>
                <tr class="content">
                    <td colspan="5">
                        <table class="ui table mainwp-manage-updates-table" id="mainwp-abandoned-plugins-sites-table">
                            <thead class="mainwp-768-hide">
                                <tr>
                                    <th scope="col"><?php esc_html_e( 'Website', 'mainwp' ); ?></th>
                                    <th scope="col"><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
                                    <th scope="col"><?php esc_html_e( 'Last Update', 'mainwp' ); ?></th>
                                    <th scope="col"><?php esc_html_e( 'Client', 'mainwp' ); ?></th>
                                    <th scope="col" class="no-sort"></th>
                                </tr>
                            </thead>
                            <tbody class="abandoned-plugins-ignore-global" plugin_slug="<?php echo esc_attr( rawurlencode( $slug ) ); ?>" plugin_name="<?php echo esc_attr( rawurlencode( $val['name'] ) ); ?>" dismissed="0">
                            <?php
                            $first_wpplugin = true;
                            MainWP_DB::data_seek( $websites, 0 );
                            while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
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

                                    if ( is_array( $decodedDismissedPlugins ) ) {
                                        $plugins_outdate = array_diff_key( $plugins_outdate, $decodedDismissedPlugins );
                                    }
                                }

                                if ( ! isset( $plugins_outdate[ $slug ] ) ) {
                                    continue;
                                }

                                $plugin_outdate           = $plugins_outdate[ $slug ];
                                $now                      = new \DateTime();
                                $last_updated             = $plugin_outdate['last_updated'];
                                $plugin_last_updated_date = new \DateTime( '@' . $last_updated );
                                $diff_in_days             = $now->diff( $plugin_last_updated_date )->format( '%a' );
                                $outdate_notice           = sprintf( $str_format, $diff_in_days );
                                ?>
                                <tr site_name="<?php echo esc_attr( rawurlencode( stripslashes( $website->name ) ) ); ?>" dismissed="0" <?php echo $first_wpplugin ? 'open-wpplugin-siteid="' . intval( $website->id ) . '"' : ''; ?>>
                                    <td><strong class="mainwp-768-show"><?php esc_html_e( 'Website:', 'mainwp' ); ?></strong> <?php MainWP_Updates::render_site_link_dashboard( $website ); ?>
                                    </td>
                                    <td><strong class="mainwp-768-show"><?php esc_html_e( 'Version:', 'mainwp' ); ?></strong> <?php echo esc_html( $plugin_outdate['Version'] ); ?></td>
                                    <td><strong class="mainwp-768-show"><?php esc_html_e( 'Last Update:', 'mainwp' ); ?></strong> <?php echo esc_html( $outdate_notice ); ?></td>
                                    <td><a href="<?php echo 'admin.php?page=ManageClients&client_id=' . intval( $website->client_id ); ?>" data-tooltip="<?php esc_attr_e( 'Jump to the client', 'mainwp' ); ?>" data-position="right center" data-inverted="" ><?php echo esc_html( $website->client_name ); ?></a></td>
                                    <td class="right aligned">
                                    <?php if ( MainWP_Updates::user_can_ignore_updates() ) { ?>
                                        <a href="javascript:void(0)" class="ui mini button" onClick="return updatesoverview_plugins_dismiss_outdate_detail( '<?php echo esc_js( $plugin_name ); ?>', '<?php echo esc_js( rawurlencode( $plugin_outdate['Name'] ) ); ?>', <?php echo intval( $website->id ); ?>, this )"><?php esc_html_e( 'Ignore Now', 'mainwp' ); ?></a>
                                    <?php } ?>
                                    </td>
                                </tr>
                                <?php
                                $first_wpplugin = false;
                            }
                            ?>
                            </tbody>
                        </table>
                    </td>
                </tr>
            <?php } ?>
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
     * Render abandoned themes
     *
     * @param object $websites the websites.
     * @param array  $allThemesOutdate all abandoned themes.
     * @param array  $decodedDismissedThemes all dismissed abandoned themes.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
     * @uses \MainWP\Dashboard\MainWP_UI::render_sorting_icons()
     * @uses \MainWP\Dashboard\MainWP_UI::render_show_all_updates_button()
     * @uses \MainWP\Dashboard\MainWP_Updates::user_can_ignore_updates()
     * @uses \MainWP\Dashboard\MainWP_Updates::render_site_link_dashboard()
     */
    public static function render_abandoned_themes( $websites, $allThemesOutdate, $decodedDismissedThemes ) { // phpcs:ignore -- NOSONAR - complex.
        $str_format      = esc_html__( 'Updated %s days ago', 'mainwp' );
        $count_abandoned = count( $allThemesOutdate );
        ?>
        <?php if ( 0 < $count_abandoned ) : ?>
        <table class="ui tablet stackable table mainwp-manage-updates-table" id="mainwp-themes-updates-table">
            <thead>
                <tr>
                    <th scope="col" class="collapsing no-sort trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></th>
                    <th scope="col" scope="col" class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Theme', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
                    <th scope="col" class="indicator-accordion-sorting handle-accordion-sorting"><?php esc_html_e( 'Abandoned', 'mainwp' ); ?><?php MainWP_UI::render_sorting_icons(); ?></th>
                    <th scope="col" class="collapsing no-sort">
                        <?php MainWP_UI::render_show_all_updates_button(); ?>
                    </th>
                </tr>
            </thead>
            <tbody class="ui accordion">
            <?php foreach ( $allThemesOutdate as $slug => $val ) { ?>
                <?php
                $cnt        = intval( $val['cnt'] );
                $theme_name = rawurlencode( $slug );
                ?>
                <tr class="title">
                    <td class="accordion-trigger"><i class="dropdown icon"></i></td>
                    <td><?php echo MainWP_System_Utility::get_theme_icon( $slug ) . '&nbsp;&nbsp;&nbsp;&nbsp;' . esc_html( $val['name'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                    <td sort-value="<?php echo intval( $cnt ); ?>"><?php echo intval( $cnt ); ?> <?php echo esc_html( _n( 'Website', 'Websites', $cnt, 'mainwp' ) ); ?></td>
                    <td class="right aligned">
                        <?php if ( MainWP_Updates::user_can_ignore_updates() ) { ?>
                            <a href="javascript:void(0)" class="ui mini green mainwp-ignore-globally-button button" onClick="return updatesoverview_themes_abandoned_ignore_all( '<?php echo esc_js( $theme_name ); ?>', '<?php echo esc_js( rawurlencode( $val['name'] ) ); ?>', this )"><?php esc_html_e( 'Ignore Globally', 'mainwp' ); ?></a>
                        <?php } ?>
                    </td>
                </tr>
                <tr class="content">
                    <td colspan="4">
                        <table class="ui table mainwp-manage-updates-item-table" id="mainwp-abandoned-themes-sites-table">
                            <thead class="mainwp-768-hide">
                                <tr>
                                    <th scope="col"><?php esc_html_e( 'Website', 'mainwp' ); ?></th>
                                    <th scope="col"><?php esc_html_e( 'Version', 'mainwp' ); ?></th>
                                    <th scope="col"><?php esc_html_e( 'Last Update', 'mainwp' ); ?></th>
                                    <th scope="col"><?php esc_html_e( 'Client', 'mainwp' ); ?></th>
                                    <th scope="col" class="no-sort"></th>
                                </tr>
                            </thead>
                            <tbody class="abandoned-themes-ignore-global" theme_slug="<?php echo esc_attr( $slug ); ?>" theme_name="<?php echo esc_attr( rawurlencode( $val['name'] ) ); ?>">
                            <?php
                            MainWP_DB::data_seek( $websites, 0 );
                            while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
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

                                    if ( is_array( $decodedDismissedThemes ) ) {
                                        $themes_outdate = array_diff_key( $themes_outdate, $decodedDismissedThemes );
                                    }
                                }

                                if ( ! isset( $themes_outdate[ $slug ] ) ) {
                                    continue;
                                }

                                $theme_outdate           = $themes_outdate[ $slug ];
                                $now                     = new \DateTime();
                                $last_updated            = $theme_outdate['last_updated'];
                                $theme_last_updated_date = new \DateTime( '@' . $last_updated );
                                $diff_in_days            = $now->diff( $theme_last_updated_date )->format( '%a' );
                                $outdate_notice          = sprintf( $str_format, $diff_in_days );
                                ?>
                                <tr site_id="<?php echo esc_attr( $website->id ); ?>" site_name="<?php echo esc_attr( rawurlencode( stripslashes( $website->name ) ) ); ?>" outdate="1" dismissed="0">
                                    <td>
                                    <strong class="mainwp-768-show"><?php esc_html_e( 'Website:', 'mainwp' ); ?></strong> <?php MainWP_Updates::render_site_link_dashboard( $website ); ?>
                                    </td>
                                    <td><strong class="mainwp-768-show"><?php esc_html_e( 'Version:', 'mainwp' ); ?></strong> <?php echo esc_html( $theme_outdate['Version'] ); ?></td>
                                    <td><strong class="mainwp-768-show"><?php esc_html_e( 'Last Update:', 'mainwp' ); ?></strong>  <?php echo esc_html( $outdate_notice ); ?></td>
                                    <td><a href="<?php echo 'admin.php?page=ManageClients&client_id=' . intval( $website->client_id ); ?>" data-tooltip="<?php esc_attr_e( 'Jump to the client', 'mainwp' ); ?>" data-position="right center" data-inverted="" ><?php echo esc_html( $website->client_name ); ?></a></td>
                                    <td class="right aligned">
                                    <?php if ( MainWP_Updates::user_can_ignore_updates() ) { ?>
                                        <a href="javascript:void(0)" class="ui mini button" onClick="return updatesoverview_themes_dismiss_outdate_detail( '<?php echo esc_js( $theme_name ); ?>', '<?php echo esc_js( rawurlencode( $theme_outdate['Name'] ) ); ?>', <?php echo intval( $website->id ); ?>, this )"><?php esc_html_e( 'Ignore Now', 'mainwp' ); ?></a>
                                    <?php } ?>
                                    </td>
                                </tr>
                                <?php
                            }
                            ?>
                            </tbody>
                        </table>
                    </td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
        <?php else : ?>
            <?php MainWP_UI::render_empty_page_placeholder( __( 'You\'re All Set!', 'mainwp' ), __( 'No abandoned plugins detected.', 'mainwp' ) ); ?>
        <?php endif; ?>
        <?php
    }
}
