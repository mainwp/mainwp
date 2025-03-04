<?php
/**
 * MainWP Plugins Page.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Plugins
 *
 * @package MainWP\Dashboard\
 *
 * @uses \MainWP\Dashboard\MainWP_Install_Bulk
 */
class MainWP_Plugins { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    // phpcs:disable Generic.Metrics.CyclomaticComplexity -- This is the only way to achieve desired results, pull request solutions appreciated.

    /**
     * Get Class Name.
     *
     * @return string __CLASS__
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * MainWP Plugins sub-pages.
     *
     * @var array $subPages MainWP Plugins Sub Pages.
     */
    public static $subPages;

    /**
     * Plugins table.
     *
     * @var mixed $pluginsTable Plugins table.
     */
    public static $pluginsTable;

    /** Instantiate Hooks. */
    public static function init() {
        /**
         * This hook allows you to render the Plugins page header via the 'mainwp-pageheader-plugins' action.
         *
         * @link http://codex.mainwp.com/#mainwp-pageheader-plugins
         *
         * This hook is normally used in the same context of 'mainwp-getsubpages-plugins'
         * @link http://codex.mainwp.com/#mainwp-getsubpages-plugins
         *
         * @see \MainWP_Plugins::render_header
         */
        add_action( 'mainwp-pageheader-plugins', array( static::get_class_name(), 'render_header' ) );

        /**
         * This hook allows you to render the Plugins page footer via the 'mainwp-pagefooter-plugins' action.
         *
         * @link http://codex.mainwp.com/#mainwp-pagefooter-plugins
         *
         * This hook is normally used in the same context of 'mainwp-getsubpages-plugins'
         * @link http://codex.mainwp.com/#mainwp-getsubpages-plugins
         *
         * @see \MainWP_Plugins::render_footer
         */
        add_action( 'mainwp-pagefooter-plugins', array( static::get_class_name(), 'render_footer' ) );

        add_action( 'mainwp_help_sidebar_content', array( static::get_class_name(), 'mainwp_help_content' ) );
    }

    /**
     * Instantiate Main Plugins Menu.
     *
     * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
     */
    public static function init_menu() {
        add_submenu_page(
            'mainwp_tab',
            __( 'Plugins', 'mainwp' ),
            '<span id="mainwp-Plugins">' . esc_html__( 'Plugins', 'mainwp' ) . '</span>',
            'read',
            'PluginsManage',
            array(
                static::get_class_name(),
                'render',
            )
        );
        if ( \mainwp_current_user_can( 'dashboard', 'install_plugins' ) ) {
            $page = add_submenu_page(
                'mainwp_tab',
                __( 'Plugins', 'mainwp' ),
                '<div class="mainwp-hidden">' . esc_html__( 'Install ', 'mainwp' ) . '</div>',
                'read',
                'PluginsInstall',
                array(
                    static::get_class_name(),
                    'render_install',
                )
            );

            add_action( 'load-' . $page, array( static::get_class_name(), 'load_page' ) );
        }
        add_submenu_page(
            'mainwp_tab',
            __( 'Plugins', 'mainwp' ),
            '<div class="mainwp-hidden">' . esc_html__( 'Advanced Auto Updates', 'mainwp' ) . '</div>',
            'read',
            'PluginsAutoUpdate',
            array(
                static::get_class_name(),
                'render_auto_update',
            )
        );
        add_submenu_page(
            'mainwp_tab',
            __( 'Plugins', 'mainwp' ),
            '<div class="mainwp-hidden">' . esc_html__( 'Ignored Updates', 'mainwp' ) . '</div>',
            'read',
            'PluginsIgnore',
            array(
                static::get_class_name(),
                'render_ignore',
            )
        );
        add_submenu_page(
            'mainwp_tab',
            __( 'Plugins', 'mainwp' ),
            '<div class="mainwp-hidden">' . esc_html__( 'Abandoned Plugins', 'mainwp' ) . '</div>',
            'read',
            'PluginsAbandoned',
            array(
                static::get_class_name(),
                'render_abandoned_plugins',
            )
        );
        add_submenu_page(
            'mainwp_tab',
            __( 'Plugins', 'mainwp' ),
            '<div class="mainwp-hidden">' . esc_html__( 'Ignored Abandoned', 'mainwp' ) . '</div>',
            'read',
            'PluginsIgnoredAbandoned',
            array(
                static::get_class_name(),
                'render_ignored_abandoned',
            )
        );

        /**
         * Plugins Subpages
         *
         * Filters subpages for the Plugins page.
         *
         * @since Unknown
         */
        $sub_pages        = apply_filters_deprecated( 'mainwp-getsubpages-plugins', array( array() ), '4.0.7.2', 'mainwp_getsubpages_plugins' );  // @deprecated Use 'mainwp_getsubpages_plugins' instead. NOSONAR - not IP.
        static::$subPages = apply_filters( 'mainwp_getsubpages_plugins', $sub_pages );

        if ( isset( static::$subPages ) && is_array( static::$subPages ) ) {
            foreach ( static::$subPages as $subPage ) {
                if ( MainWP_Menu::is_disable_menu_item( 3, 'Plugins' . $subPage['slug'] ) ) {
                    continue;
                }
                add_submenu_page( 'mainwp_tab', $subPage['title'], '<div class="mainwp-hidden">' . $subPage['title'] . '</div>', 'read', 'Plugins' . $subPage['slug'], $subPage['callback'] );
            }
        }
        static::init_left_menu( static::$subPages );
    }

    /**
     * Load the Plugins Page.
     *
     * @uses \MainWP\Dashboard\MainWP_Plugins_Install_List_Table
     */
    public static function load_page() {
        static::$pluginsTable = new MainWP_Plugins_Install_List_Table();
        $pagenum              = static::$pluginsTable->get_pagenum();

        static::$pluginsTable->prepare_items();

        $total_pages = static::$pluginsTable->get_pagination_arg( 'total_pages' );

        if ( $pagenum > $total_pages && 0 < $total_pages ) {
            wp_safe_redirect( esc_url_raw( add_query_arg( 'paged', $total_pages ) ) );
            exit;
        }
    }

    /**
     * Instantiate Subpage "tabs".
     *
     * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
     */
    public static function init_subpages_menu() {
        ?>
        <div id="menu-mainwp-Plugins" class="mainwp-submenu-wrapper" xmlns="http://www.w3.org/1999/html">
            <div class="wp-submenu sub-open" >
                <div class="mainwp_boxout">
                    <div class="mainwp_boxoutin"></div>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=PluginsManage' ) ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Manage Plugins', 'mainwp' ); ?></a>
                    <?php if ( \mainwp_current_user_can( 'dashboard', 'install_plugins' ) ) : ?>
                        <?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'PluginsInstall' ) ) : ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=PluginsInstall' ) ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Install Plugins', 'mainwp' ); ?></a>
                            <?php endif; ?>
                            <?php endif; ?>
                            <?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'PluginsAutoUpdate' ) ) : ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=PluginsAutoUpdate' ) ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Advanced Auto Updates', 'mainwp' ); ?></a>
                            <?php endif; ?>
                            <?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'PluginsIgnore' ) ) : ?>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=PluginsIgnore' ) ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Ignored Updates', 'mainwp' ); ?></a>
                            <?php endif; ?>
                            <?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'PluginsIgnoredAbandoned' ) ) : ?>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=PluginsIgnoredAbandoned' ) ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Ignored Abandoned', 'mainwp' ); ?></a>
                            <?php endif; ?>
                            <?php
                            if ( isset( static::$subPages ) && is_array( static::$subPages ) ) {
                                foreach ( static::$subPages as $subPage ) {
                                    if ( MainWP_Menu::is_disable_menu_item( 3, 'Plugins' . $subPage['slug'] ) ) {
                                        continue;
                                    }
                                    ?>
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=Plugins' . $subPage['slug'] ) ); ?>" class="mainwp-submenu"><?php echo esc_html( $subPage['title'] ); ?></a>
                                    <?php
                                }
                            }
                            ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Instantiate MainWP main menu Subpages menu.
     *
     * @param array $subPages Subpages array.
     *
     * @uses MainWP_Menu::add_left_menu()
     * @uses MainWP_Menu::init_subpages_left_menu()
     * @uses MainWP_Menu::is_disable_menu_item()
     * @uses \MainWP\Dashboard\MainWP_Menu::add_left_menu()
     */
    public static function init_left_menu( $subPages = array() ) {
        MainWP_Menu::add_left_menu(
            array(
                'title'         => esc_html__( 'Plugins', 'mainwp' ),
                'parent_key'    => 'managesites',
                'slug'          => 'PluginsManage',
                'href'          => 'admin.php?page=PluginsManage',
                'icon'          => '<i class="plug icon"></i>',
                'leftsub_order' => 5,
            ),
            1
        );

        $init_sub_subleftmenu = array(
            array(
                'title'                => esc_html__( 'Manage Plugins', 'mainwp' ),
                'parent_key'           => 'PluginsManage',
                'href'                 => 'admin.php?page=PluginsManage',
                'slug'                 => 'PluginsManage',
                'right'                => '',
                'leftsub_order_level2' => 1,
            ),
            array(
                'title'                => esc_html__( 'Install Plugins', 'mainwp' ),
                'parent_key'           => 'PluginsManage',
                'href'                 => 'admin.php?page=PluginsInstall',
                'slug'                 => 'PluginsInstall',
                'right'                => 'install_plugins',
                'leftsub_order_level2' => 2,
            ),
            array(
                'title'                => esc_html__( 'Advanced Auto Updates', 'mainwp' ),
                'parent_key'           => 'PluginsManage',
                'href'                 => 'admin.php?page=PluginsAutoUpdate',
                'slug'                 => 'PluginsAutoUpdate',
                'right'                => '',
                'leftsub_order_level2' => 3,
            ),
            array(
                'title'                => esc_html__( 'Ignored Updates', 'mainwp' ),
                'parent_key'           => 'PluginsManage',
                'href'                 => 'admin.php?page=PluginsIgnore',
                'slug'                 => 'PluginsIgnore',
                'right'                => '',
                'leftsub_order_level2' => 4,
            ),
            array(
                'title'                => esc_html__( 'Abandoned Plugins', 'mainwp' ),
                'parent_key'           => 'PluginsManage',
                'href'                 => 'admin.php?page=PluginsAbandoned',
                'slug'                 => 'PluginsAbandoned',
                'right'                => '',
                'leftsub_order_level2' => 4.1,
            ),
            array(
                'title'                => esc_html__( 'Ignored Abandoned', 'mainwp' ),
                'parent_key'           => 'PluginsManage',
                'href'                 => 'admin.php?page=PluginsIgnoredAbandoned',
                'slug'                 => 'PluginsIgnoredAbandoned',
                'right'                => '',
                'leftsub_order_level2' => 5,
            ),

        );

        MainWP_Menu::init_subpages_left_menu( $subPages, $init_sub_subleftmenu, 'PluginsManage', 'Plugins' );

        foreach ( $init_sub_subleftmenu as $item ) {
            if ( MainWP_Menu::is_disable_menu_item( 3, $item['slug'] ) ) {
                continue;
            }
            MainWP_Menu::add_left_menu( $item, 2 );
        }
    }

    /**
     * Render MainWP Plugins Page Header.
     *
     * @param string $shownPage The page slug shown at this moment.
     *
     * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
     * @uses \MainWP\Dashboard\MainWP_UI::render_top_header()
     * @uses \MainWP\Dashboard\MainWP_UI::render_page_navigation()
     */
    public static function render_header( $shownPage = '' ) { // phpcs:ignore -- NOSONAR - complex.

        $params = array(
            'title' => esc_html__( 'Plugins', 'mainwp' ),
        );

        MainWP_UI::render_top_header( $params );

        $renderItems   = array();
        $renderItems[] = array(
            'title'  => esc_html__( 'Manage Plugins', 'mainwp' ),
            'href'   => 'admin.php?page=PluginsManage',
            'active' => ( 'Manage' === $shownPage ) ? true : false,
        );

        if ( \mainwp_current_user_can( 'dashboard', 'install_plugins' ) && ! MainWP_Menu::is_disable_menu_item( 3, 'PluginsInstall' ) ) {
            $renderItems[] = array(
                'title'  => esc_html__( 'Install', 'mainwp' ),
                'href'   => 'admin.php?page=PluginsInstall',
                'active' => ( 'Install' === $shownPage ) ? true : false,
            );
        }

        if ( ! MainWP_Menu::is_disable_menu_item( 3, 'PluginsAutoUpdate' ) ) {
            $renderItems[] = array(
                'title'  => esc_html__( 'Advanced Auto Updates', 'mainwp' ),
                'href'   => 'admin.php?page=PluginsAutoUpdate',
                'active' => ( 'AutoUpdate' === $shownPage ) ? true : false,
            );
        }

        if ( ! MainWP_Menu::is_disable_menu_item( 3, 'PluginsIgnore' ) ) {
            $renderItems[] = array(
                'title'  => esc_html__( 'Ignored Updates', 'mainwp' ),
                'href'   => 'admin.php?page=PluginsIgnore',
                'active' => ( 'Ignore' === $shownPage ) ? true : false,
            );
        }

        if ( ! MainWP_Menu::is_disable_menu_item( 3, 'PluginsIgnoredAbandoned' ) ) {
            $renderItems[] = array(
                'title'  => esc_html__( 'Ignored Abandoned', 'mainwp' ),
                'href'   => 'admin.php?page=PluginsIgnoredAbandoned',
                'active' => ( 'IgnoreAbandoned' === $shownPage ) ? true : false,
            );
        }

        if ( isset( static::$subPages ) && is_array( static::$subPages ) ) {
            foreach ( static::$subPages as $subPage ) {
                if ( MainWP_Menu::is_disable_menu_item( 3, 'Plugins' . $subPage['slug'] ) ) {
                    continue;
                }

                $item           = array();
                $item['title']  = $subPage['title'];
                $item['href']   = 'admin.php?page=Plugins' . $subPage['slug'];
                $item['active'] = ( $subPage['slug'] === $shownPage ) ? true : false;
                $renderItems[]  = $item;
            }
        }

        MainWP_UI::render_page_navigation( $renderItems );
    }

    /**
     * Method render_footer()
     *
     * Render MainWP Plugins Page Footer.
     */
    public static function render_footer() {
        echo '</div>';
    }

    /**
     * Render MainWP Plugins Page.
     *
     * @uses \MainWP\Dashboard\MainWP_Cache::get_cached_context()
     * @uses \MainWP\Dashboard\MainWP_Cache::get_cached_result()
     * @uses \MainWP\Dashboard\MainWP_UI::render_empty_bulk_actions()
     */
    public static function render() { // phpcs:ignore -- NOSONAR - complex.
        $cachedSearch     = MainWP_Cache::get_cached_context( 'Plugins' );
        $selected_sites   = array();
        $selected_groups  = array();
        $selected_clients = array();

        if ( null !== $cachedSearch ) {
            if ( is_array( $cachedSearch['sites'] ) ) {
                $selected_sites = $cachedSearch['sites'];
            } elseif ( is_array( $cachedSearch['groups'] ) ) {
                $selected_groups = $cachedSearch['groups'];
            } elseif ( is_array( $cachedSearch['clients'] ) ) {
                $selected_clients = $cachedSearch['clients'];
            }
        }
        $cachedResult = MainWP_Cache::get_cached_result( 'Plugins' );

        if ( isset( $_POST['select_mainwp_options_plugintheme_view'] ) && check_admin_referer( 'mainwp-admin-nonce' ) && is_array( $cachedResult ) && isset( $cachedResult['result'] ) ) {
            unset( $cachedResult['result'] ); // clear cached results.
        }

        static::render_header( 'Manage' );
        ?>

        <div id="mainwp-manage-plugins" class="ui alt segment">
            <div class="mainwp-main-content">
                <div class="ui mini form mainwp-actions-bar">
                    <div class="ui stackable grid">
                        <div class="ui two column row">
                            <div class="column" >
                                <span id="mainwp-plugins-bulk-actions-wapper" style="margin-right:1rem">
                                    <?php
                                    if ( is_array( $cachedResult ) && isset( $cachedResult['bulk_actions'] ) ) {
                                        echo $cachedResult['bulk_actions']; // phpcs:ignore WordPress.Security.EscapeOutput
                                    }
                                    ?>
                                </span>
                                <button id="mainwp-install-to-selected-sites" class="ui mini green basic button" style="display: none; "><?php esc_html_e( 'Install to Selected Site(s)', 'mainwp' ); ?></button>
                                <?php
                                /**
                                 * Action: mainwp_plugins_actions_bar_left
                                 *
                                 * Fires at the left side of the actions bar on the Plugins screen, after the Bulk Actions menu.
                                 *
                                 * @since 4.0
                                 */
                                do_action( 'mainwp_plugins_actions_bar_left' );
                                ?>
                            </div>
                            <div class="right aligned column">
                                <?php static::render_select_manage_view(); ?>

                                <?php
                                /**
                                 * Action: mainwp_plugins_actions_bar_right
                                 *
                                 * Fires at the right side of the actions bar on the Plugins screen.
                                 *
                                 * @since 4.0
                                 */
                                do_action( 'mainwp_plugins_actions_bar_right' );
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="ui segment" id="mainwp-plugins-table-wrapper">
                    <?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-manage-plugins-info-message' ) ) : ?>
                        <div class="ui info message">
                            <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-manage-plugins-info-message"></i>
                            <div><?php echo esc_html__( 'Manage installed plugins on your child sites. Here you can activate, deactivate, and delete installed plugins.', 'mainwp' ); ?></div>
                            <p><?php echo esc_html__( 'To Activate or Delete a specific plugin, you must search only for Inactive plugin on your child sites. If you search for Active or both Active and Inactive, the Activate and Delete actions will be disabled.', 'mainwp' ); ?></p>
                            <p><?php echo esc_html__( 'To Deactivate a specific plugin, you must search only for Active plugins on your child sites. If you search for Inactive or both Active and Inactive, the Deactivate action will be disabled.', 'mainwp' ); ?></p>
                            <p><?php printf( esc_html__( 'For additional help, please check this %1$shelp documentation%2$s.', 'mainwp' ), '<a href="https://mainwp.com/kb/managing-plugins-with-mainwp/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); ?></p>
                        </div>
                    <?php endif; ?>
                    <div id="mainwp-message-zone" class="ui message" style="display:none"></div>
                    <div id="mainwp-loading-plugins-row" class="ui active inverted dimmer" style="display:none">
                        <div class="ui large text loader"><?php esc_html_e( 'Loading Plugins...', 'mainwp' ); ?></div>
                    </div>
                    <div id="mainwp-plugins-main-content" <?php echo ( null !== $cachedSearch ) ? 'style="display: block;"' : ''; ?> >
                        <div id="mainwp-plugins-content">
                            <?php if ( is_array( $cachedResult ) && isset( $cachedResult['result'] ) ) : ?>
                                <?php echo $cachedResult['result']; // phpcs:ignore WordPress.Security.EscapeOutput ?>
                            <?php else : ?>
                                <div class="ui hidden divider"></div>
                                <div class="ui hidden divider"></div>
                                <div class="ui hidden divider"></div>
                                <?php MainWP_UI::render_empty_element_placeholder( __( 'Use the search options to find the plugin you want to manage', 'mainwp' ) ); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mainwp-side-content mainwp-no-padding">
                <?php
                /**
                 * Action: mainwp_manage_plugins_sidebar_top
                 *
                 * Fires at the top of the sidebar on Manage themes.
                 *
                 * @since 4.1
                 */
                do_action( 'mainwp_manage_plugins_sidebar_top' );
                ?>

                <div class="mainwp-select-sites ui accordion mainwp-sidebar-accordion">
                    <?php
                    /**
                     * Action: mainwp_manage_plugins_before_select_sites
                     *
                     * Fires before the Select Sites elemnt on Manage plugins.
                     *
                     * @since 4.1
                     */
                    do_action( 'mainwp_manage_plugins_before_select_sites' );
                    ?>
                    <div class="title active"><i class="dropdown icon"></i> <?php esc_html_e( 'Select Sites', 'mainwp' ); ?></div>
                    <div class="content active">
                        <?php
                        $sel_params = array(
                            'class'            => 'mainwp_select_sites_box_left',
                            'selected_sites'   => $selected_sites,
                            'selected_groups'  => $selected_groups,
                            'selected_clients' => $selected_clients,
                            'show_client'      => true,
                        );
                        MainWP_UI_Select_Sites::select_sites_box( $sel_params );
                        ?>
                        </div>
                    <?php
                    /**
                     * Action: mainwp_manage_plugins_after_select_sites
                     *
                     * Fires after the Select Sites elemnt on Manage plugins.
                     *
                     * @since 4.1
                     */
                    do_action( 'mainwp_manage_plugins_after_select_sites' );
                    ?>
                    </div>
                    <div class="ui fitted divider"></div>
                <div class="mainwp-search-options ui accordion mainwp-sidebar-accordion">
                    <div class="title active"><i class="dropdown icon"></i> <?php esc_html_e( 'Select Status', 'mainwp' ); ?></div>
                    <div class="content active">
                    <?php
                    /**
                     * Action: mainwp_manage_plugins_before_search_options
                     *
                     * Fires before the Search Options elemnt on Manage plugins.
                     *
                     * @since 4.1
                     */
                    do_action( 'mainwp_manage_plugins_before_search_options' );
                    ?>
                    <div class="ui info message">
                        <i class="close icon mainwp-notice-dismiss" notice-id="plugins-manage-info"></i>
                        <?php esc_html_e( 'A plugin needs to be Inactive for it to be Activated or Deleted.', 'mainwp' ); ?>
                    </div>
                    <div class="ui mini form">
                        <div class="field">
                            <select class="ui fluid dropdown" id="mainwp_plugins_search_by_status">
                                <option value=""><?php esc_html_e( 'Select status', 'mainwp' ); ?></option>
                                <option value="active" selected><?php esc_html_e( 'Active', 'mainwp' ); ?></option>
                                <option value="inactive"><?php esc_html_e( 'Inactive', 'mainwp' ); ?></option>
                                <option value="installed"><?php esc_html_e( 'Active & Inactive', 'mainwp' ); ?></option>
                                <option value="not_installed"><?php esc_html_e( 'Not installed', 'mainwp' ); ?></option>
                            </select>
                        </div>
                    </div>
                    <?php
                    /**
                     * Action: mainwp_manage_plugins_after_search_options
                     *
                     * Fires after the Search Options elemnt on Manage plugins.
                     *
                     * @since 4.1
                     */
                    do_action( 'mainwp_manage_plugins_after_search_options' );
                    ?>
                </div>
                    </div>
                <div class="ui fitted divider"></div>
                <?php static::render_search_options(); ?>
                <div class="ui fitted divider"></div>
                    <div class="mainwp-search-submit">
                    <?php
                    /**
                     * Action: mainwp_manage_plugins_before_submit_button
                     *
                     * Fires before the Submit Button elemnt on Manage plugins.
                     *
                     * @since 4.1
                     */
                    do_action( 'mainwp_manage_plugins_before_submit_button' );
                    ?>
                    <input type="button" name="mainwp-show-plugins" id="mainwp-show-plugins" class="ui green big fluid button" value="<?php esc_attr_e( 'Show Plugins', 'mainwp' ); ?>"/>
                    <?php
                    /**
                     * Action: mainwp_manage_plugins_after_submit_button
                     *
                     * Fires after the Submit Button elemnt on Manage plugins.
                     *
                     * @since 4.1
                     */
                    do_action( 'mainwp_manage_plugins_after_submit_button' );
                    ?>
                </div>
                <?php
                /**
                 * Action: mainwp_manage_plugins_sidebar_bottom
                 *
                 * Fires at the bottom of the sidebar on Manage themes.
                 *
                 * @since 4.1
                 */
                do_action( 'mainwp_manage_plugins_sidebar_bottom' );
                ?>
            </div>
            <div style="clear:both"></div>
        </div>
        <?php
        static::render_footer( 'Manage' );
    }

    /**
     * Render MainWP plugins page search options.
     *
     * @uses \MainWP\Dashboard\MainWP_Cache::get_cached_context()
     */
    public static function render_search_options() {
        $cachedSearch = MainWP_Cache::get_cached_context( 'Plugins' );
        $statuses     = isset( $cachedSearch['status'] ) ? $cachedSearch['status'] : array();
        if ( $cachedSearch && isset( $cachedSearch['keyword'] ) ) {
            $cachedSearch['keyword'] = trim( $cachedSearch['keyword'] );
        }
        $disabledNegative = ( null !== $cachedSearch ) && ! empty( $cachedSearch['keyword'] ) ? false : true;
        $checkedNegative  = ! $disabledNegative && ( null !== $cachedSearch ) && ! empty( $cachedSearch['not_criteria'] ) ? true : false;
        ?>
        <div class="mainwp-search-options ui accordion mainwp-sidebar-accordion">
            <div class="title active"><i class="dropdown icon"></i> <?php esc_html_e( 'Search Options', 'mainwp' ); ?></div>
            <div class="content active">
                <div class="ui mini form">
                    <div class="field">
                        <div class="ui input fluid">
                            <input type="text" placeholder="<?php esc_attr_e( 'Plugin name', 'mainwp' ); ?>" id="mainwp_plugin_search_by_keyword" class="text" value="<?php echo ( null !== $cachedSearch ) ? esc_attr( $cachedSearch['keyword'] ) : ''; //phpcs:ignore -- escaped. ?>" />
                        </div>
                    </div>
                    <div class="ui hidden fitted divider"></div>
                    <div class="field">
                        <div class="ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'Display sites not meeting the above search criteria.', 'mainwp' ); ?>" data-position="left center" data-inverted="">
                                <input type="checkbox" <?php echo $disabledNegative ? 'disabled' : ''; ?> <?php echo $checkedNegative ? 'checked="true"' : ''; ?> value="1" id="display_sites_not_meeting_criteria" />
                            <label for="display_sites_not_meeting_criteria"><?php esc_html_e( 'Exclude plugin', 'mainwp' ); ?></label>
                            </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
        if ( is_array( $statuses ) && ! empty( $statuses ) ) {
            $status = '';
            foreach ( $statuses as $st ) {
                $status .= "'" . esc_html( $st ) . "',";
            }
            $status = rtrim( $status, ',' );
            ?>
            <script type="text/javascript">
                jQuery( document ).ready( function () {
                    jQuery( '#mainwp_plugins_search_by_status' ).dropdown( 'set selected', [<?php echo $status; // phpcs:ignore -- safe output, to fix incorrect characters. ?>] );
                } );
            </script>
            <?php
        }
        ?>
        <script type="text/javascript">
            jQuery( document ).on( 'keyup', '#mainwp_plugin_search_by_keyword', function () {
                if( jQuery(this).val() != '' ){
                    jQuery( '#display_sites_not_meeting_criteria' ).prop("disabled", false);
                } else {
                    jQuery( '#display_sites_not_meeting_criteria' ).closest('.checkbox').checkbox('set unchecked');
                    jQuery( '#display_sites_not_meeting_criteria' ).attr('disabled', 'true');
                }
            });
        </script>
        <?php
    }

    /**
     * Render Plugins Table.
     *
     * @param mixed $keyword Search Terms.
     * @param mixed $status active|inactive Whether the plugin is active or inactive.
     * @param mixed $groups Selected Child Site Groups.
     * @param mixed $sites Selected individual Child Sites.
     * @param mixed $not_criteria Show not criteria result.
     * @param mixed $clients Selected Clients.
     *
     * @return string Plugin Table.
     *
     * @uses MainWP_Cache::init_cache()
     * @uses MainWP_Utility::ctype_digit()
     * @uses MainWP_DB::instance()
     * @uses MainWP_DB::free_result()
     * @uses MainWP_DB::fetch_object()
     * @uses MainWP_Utility::map_site()
     * @uses MainWP_Utility::fetch_urls_authed()
     * @uses MainWP_Utility::get_nice_url()
     * @uses MainWP_Cache::add_result()
     * @uses \MainWP\Dashboard\MainWP_Cache::init_cache()
     * @uses \MainWP\Dashboard\MainWP_Cache::add_context()
     * @uses \MainWP\Dashboard\MainWP_Cache::add_result()
     * @uses \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
     * @uses \MainWP\Dashboard\MainWP_DB::query()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_by_group_id()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_DB::free_result()
     * @uses \MainWP\Dashboard\MainWP_Plugins_Handler::get_class_name()
     * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     * @uses \MainWP\Dashboard\MainWP_Utility::map_site()
     */
    public static function render_table( $keyword, $status, $groups, $sites, $not_criteria, $clients ) { // phpcs:ignore -- NOSONAR -Current complexity required to achieve desired results. Pull request solutions appreciated.
        $keyword = trim( $keyword );
        MainWP_Cache::init_cache( 'Plugins' );

        $output                    = new \stdClass();
        $output->errors            = array();
        $output->plugins           = array();
        $output->plugins_installed = array();
        $output->status            = $status;
        $output->roll_items        = array();
        $error_results             = '';

        $data_fields   = MainWP_System_Utility::get_default_map_site_fields();
        $data_fields[] = 'plugins';
        $data_fields[] = 'rollback_updates_data';

        if ( 1 === (int) get_option( 'mainwp_optimize', 1 ) || MainWP_Demo_Handle::is_demo_mode() ) {

            $multi_kws = explode( ',', $keyword );
            $multi_kws = array_filter( array_map( 'trim', $multi_kws ) );

            if ( ! empty( $sites ) ) {
                foreach ( $sites as $v ) {
                    if ( MainWP_Utility::ctype_digit( $v ) ) {
                        $website          = MainWP_DB::instance()->get_website_by_id( $v, false, array( 'rollback_updates_data' ) );
                        $allPlugins       = json_decode( $website->plugins, true );
                        $_count           = count( $allPlugins );
                        $_count_installed = 0;
                        for ( $i = 0; $i < $_count; $i++ ) {
                            $plugin    = $allPlugins[ $i ];
                            $is_active = 'active' === $status ? 1 : 0;
                            if ( ( 'active' === $status || 'inactive' === $status ) && $is_active !== (int) $plugin['active'] ) {
                                continue;
                            }

                            if ( ! empty( $keyword ) ) {
                                if ( $not_criteria ) {
                                    if ( MainWP_Utility::multi_find_keywords( $plugin['name'], $multi_kws ) ) {
                                        continue;
                                    }
                                } elseif ( ! MainWP_Utility::multi_find_keywords( $plugin['name'], $multi_kws ) ) {
                                    continue;
                                }
                            }

                            $plugin['websiteid']   = $website->id;
                            $plugin['websiteurl']  = $website->url;
                            $plugin['websitename'] = $website->name;
                            $output->plugins[]     = $plugin;
                            ++$_count_installed;
                        }

                        if ( 0 === $_count_installed && 'not_installed' === $status ) {
                            for ( $i = 0; $i < $_count; $i++ ) {
                                $plugin                      = $allPlugins[ $i ];
                                $plugin['websiteid']         = $website->id;
                                $plugin['websiteurl']        = $website->url;
                                $plugin['websitename']       = $website->name;
                                $output->plugins_installed[] = $plugin;
                            }
                        }
                        $output->roll_items[ $website->id ] = MainWP_Updates_Helper::get_roll_update_plugintheme_items( 'plugin', $website->rollback_updates_data );
                    }
                }
            }

            if ( '' !== $groups ) {
                foreach ( $groups as $v ) {
                    if ( MainWP_Utility::ctype_digit( $v ) ) {
                        $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_by_group_id( $v, false, 'wp.url', false, false, null, null, array( 'extra_view' => array( 'site_info', 'rollback_updates_data' ) ) ) );
                        while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
                            if ( '' !== $website->sync_errors || MainWP_System_Utility::is_suspended_site( $website ) ) {
                                continue;
                            }
                            $allPlugins       = json_decode( $website->plugins, true );
                            $_count           = count( $allPlugins );
                            $_count_installed = 0;
                            for ( $i = 0; $i < $_count; $i++ ) {
                                $plugin    = $allPlugins[ $i ];
                                $is_active = 'active' === $status ? 1 : 0;
                                if ( ( 'active' === $status || 'inactive' === $status ) && $is_active !== (int) $plugin['active'] ) {
                                    continue;
                                }

                                if ( ! empty( $keyword ) ) {
                                    if ( $not_criteria ) {
                                        if ( MainWP_Utility::multi_find_keywords( $plugin['name'], $multi_kws ) ) {
                                            continue;
                                        }
                                    } elseif ( ! MainWP_Utility::multi_find_keywords( $plugin['name'], $multi_kws ) ) {
                                        continue;
                                    }
                                }

                                $plugin['websiteid']   = $website->id;
                                $plugin['websiteurl']  = $website->url;
                                $plugin['websitename'] = $website->name;
                                $output->plugins[]     = $plugin;
                                ++$_count_installed;
                            }

                            if ( 0 === $_count_installed && 'not_installed' === $status ) {
                                for ( $i = 0; $i < $_count; $i++ ) {
                                    $plugin                      = $allPlugins[ $i ];
                                    $plugin['websiteid']         = $website->id;
                                    $plugin['websiteurl']        = $website->url;
                                    $plugin['websitename']       = $website->name;
                                    $output->plugins_installed[] = $plugin;
                                }
                            }
                            $output->roll_items[ $website->id ] = MainWP_Updates_Helper::get_roll_update_plugintheme_items( 'plugin', $website->rollback_updates_data );
                        }
                        MainWP_DB::free_result( $websites );
                    }
                }
            }

            if ( '' !== $clients && is_array( $clients ) ) {
                $websites = MainWP_DB_Client::instance()->get_websites_by_client_ids(
                    $clients,
                    array(
                        'select_data' => $data_fields,
                        'extra_view'  => array( 'rollback_updates_data' ),
                    )
                );
                if ( $websites ) {
                    foreach ( $websites as $website ) {
                        if ( '' !== $website->sync_errors || MainWP_System_Utility::is_suspended_site( $website ) ) {
                            continue;
                        }
                        $allPlugins       = json_decode( $website->plugins, true );
                        $_count           = count( $allPlugins );
                        $_count_installed = 0;
                        for ( $i = 0; $i < $_count; $i++ ) {
                            $plugin = $allPlugins[ $i ];

                            $is_active = ( 'active' === $status ) ? 1 : 0;

                            if ( ( ( 'active' === $status ) || ( 'inactive' === $status ) ) && ( $is_active !== (int) $plugin['active'] ) ) {
                                continue;
                            }

                            if ( ! empty( $keyword ) ) {
                                if ( $not_criteria ) {
                                    if ( MainWP_Utility::multi_find_keywords( $plugin['name'], $multi_kws ) ) {
                                        continue;
                                    }
                                } elseif ( ! MainWP_Utility::multi_find_keywords( $plugin['name'], $multi_kws ) ) {
                                    continue;
                                }
                            }

                            $plugin['websiteid']   = $website->id;
                            $plugin['websiteurl']  = $website->url;
                            $plugin['websitename'] = $website->name;
                            $output->plugins[]     = $plugin;
                            ++$_count_installed;
                        }
                        if ( 0 === $_count_installed && 'not_installed' === $status ) {
                            for ( $i = 0; $i < $_count; $i++ ) {
                                $plugin                      = $allPlugins[ $i ];
                                $plugin['websiteid']         = $website->id;
                                $plugin['websiteurl']        = $website->url;
                                $plugin['websitename']       = $website->name;
                                $output->plugins_installed[] = $plugin;
                            }
                        }
                        $output->roll_items[ $website->id ] = MainWP_Updates_Helper::get_roll_update_plugintheme_items( 'plugin', $website->rollback_updates_data );
                    }
                }
            }
        } else {
            $dbwebsites = array();

            if ( '' !== $sites ) {
                foreach ( $sites as $v ) {
                    if ( MainWP_Utility::ctype_digit( $v ) ) {
                        $website = MainWP_DB::instance()->get_website_by_id( $v, false, array( 'rollback_updates_data' ) );

                        if ( '' !== $website->sync_errors || MainWP_System_Utility::is_suspended_site( $website ) ) {
                            continue;
                        }

                        $dbwebsites[ $website->id ] = MainWP_Utility::map_site(
                            $website,
                            $data_fields
                        );
                    }
                }
            }

            if ( '' !== $groups ) {
                foreach ( $groups as $v ) {
                    if ( MainWP_Utility::ctype_digit( $v ) ) {
                        $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_by_group_id( $v, false, 'wp.url', false, false, null, null, array( 'extra_view' => array( 'site_info', 'rollback_updates_data' ) ) ) );
                        while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
                            if ( '' !== $website->sync_errors || MainWP_System_Utility::is_suspended_site( $website ) ) {
                                continue;
                            }
                            $dbwebsites[ $website->id ] = MainWP_Utility::map_site(
                                $website,
                                $data_fields
                            );
                        }
                        MainWP_DB::free_result( $websites );
                    }
                }
            }

            if ( '' !== $clients && is_array( $clients ) ) {
                $websites = MainWP_DB_Client::instance()->get_websites_by_client_ids(
                    $clients,
                    array(
                        'select_data' => $data_fields,
                        'extra_view'  => array( 'rollback_updates_data' ),
                    )
                );
                if ( $websites ) {
                    foreach ( $websites as $website ) {
                        if ( '' !== $website->sync_errors || MainWP_System_Utility::is_suspended_site( $website ) ) {
                            continue;
                        }
                        $dbwebsites[ $website->id ] = MainWP_Utility::map_site(
                            $website,
                            $data_fields
                        );
                    }
                }
            }

            $post_data = array(
                'keyword' => $keyword,
            );

            if ( 'active' === $status || 'inactive' === $status ) {
                $post_data['status'] = $status;
                $post_data['filter'] = true;
            } else {
                $post_data['status'] = '';
                $post_data['filter'] = false;
            }

            if ( 'not_installed' === $status ) {
                $post_data['not_installed'] = true;
            }

            $post_data['not_criteria'] = $not_criteria ? true : false;
            MainWP_Connect::fetch_urls_authed( $dbwebsites, 'get_all_plugins', $post_data, array( MainWP_Plugins_Handler::get_class_name(), 'plugins_search_handler' ), $output );
            // phpcs:disable WordPress.Security.EscapeOutput
            if ( ! empty( $output->errors ) ) {
                foreach ( $output->errors as $siteid => $error ) {
                    $error_results .= MainWP_Utility::get_nice_url( $dbwebsites[ $siteid ]->url ) . ': ' . $error . ' <br/>';
                }
            }
            // phpcs:enable
        }

        MainWP_Cache::add_context(
            'Plugins',
            array(
                'keyword'      => $keyword,
                'status'       => $status,
                'sites'        => ( '' !== $sites ) ? $sites : '',
                'groups'       => ( '' !== $groups ) ? $groups : '',
                'clients'      => ( '' !== $clients ) ? $clients : '',
                'not_criteria' => $not_criteria ? true : false,
            )
        );

        $view_mode = static::get_manage_view();

        $bulkActions     = static::render_bulk_actions( $status );
        $roll_items_list = array();
        $plugins_list    = array();

        ob_start();

        if ( ! empty( $error_results ) ) {
            // phpcs:disable WordPress.Security.EscapeOutput
            ?>
            <div class="ui message yellow"><?php echo $error_results; ?></div>
            <?php
            // phpcs:enable
        }

        if ( 'not_installed' === $status ) {
            if ( empty( $output->plugins_installed ) ) {
                ?>
                <div class="ui message yellow"><?php esc_html_e( 'No websites found.', 'mainwp' ); ?></div>
                <?php
            } else {
                $plugins_list    = $output->plugins_installed;
                $roll_items_list = ! empty( $output->roll_items ) ? $output->roll_items : array();
                $view_mode       = MAINWP_VIEW_PER_SITE;
            }
        } elseif ( empty( $output->plugins ) ) {
            ?>
            <div class="ui message yellow"><?php esc_html_e( 'No plugins found.', 'mainwp' ); ?></div>
            <?php
        } else {
            $plugins_list    = $output->plugins;
            $roll_items_list = ! empty( $output->roll_items ) ? $output->roll_items : array();
        }

        if ( ! is_array( $roll_items_list ) ) {
            $roll_items_list = array();
        }

        $sites              = array();
        $sitePlugins        = array();
        $pluginsNameSites   = array();
        $muPlugins          = array();
        $pluginsName        = array();
        $pluginsMainWP      = array();
        $pluginsRealVersion = array();
        $pluginsSlug        = array();

        if ( ! empty( $plugins_list ) ) {

            foreach ( $plugins_list as $plugin ) {
                $slug_ver                      = esc_html( $plugin['slug'] . '_' . $plugin['version'] );
                $sites[ $plugin['websiteid'] ] = array(
                    'websiteurl'  => esc_html( $plugin['websiteurl'] ),
                    'websitename' => $plugin['websitename'],
                );

                $pluginsSlug[ $slug_ver ] = isset( $plugin['slug'] ) ? $plugin['slug'] : '';
                $muPlugins[ $slug_ver ]   = isset( $plugin['mu'] ) ? esc_html( $plugin['mu'] ) : 0;
                $pluginsName[ $slug_ver ] = esc_html( $plugin['name'] );

                $pluginsNameSites[ $plugin['name'] ][ $plugin['websiteid'] ][] = $slug_ver;

                $pluginsMainWP[ $slug_ver ]      = isset( $plugin['mainwp'] ) ? esc_html( $plugin['mainwp'] ) : 'F';
                $pluginsRealVersion[ $slug_ver ] = rawurlencode( $plugin['version'] );

                if ( ! isset( $sitePlugins[ $plugin['websiteid'] ] ) || ! is_array( $sitePlugins[ $plugin['websiteid'] ] ) ) {
                    $sitePlugins[ $plugin['websiteid'] ] = array();
                }

                $sitePlugins[ $plugin['websiteid'] ][ $slug_ver ] = $plugin;
            }

            ksort( $pluginsNameSites, SORT_STRING );

            if ( MAINWP_VIEW_PER_PLUGIN_THEME === (int) $view_mode ) {
                static::render_manage_table( $sites, $pluginsSlug, $sitePlugins, $pluginsMainWP, $muPlugins, $pluginsName, $pluginsNameSites, $pluginsRealVersion, $roll_items_list );
            } else {
                static::render_manage_per_site_table( $sites, $pluginsSlug, $sitePlugins, $pluginsMainWP, $muPlugins, $pluginsName, $pluginsNameSites, $pluginsRealVersion, $roll_items_list );
            }
            MainWP_Updates::render_plugin_details_modal();
            MainWP_UI::render_modal_upload_icon();
        }

        $newOutput = ob_get_clean();
        $result    = array(
            'result'       => $newOutput,
            'bulk_actions' => $bulkActions,
        );

        MainWP_Cache::add_result( 'Plugins', $result );
        return $result;
    }


    /**
     * Render Bulk Actions.
     *
     * @param mixed $status active|inactive|all.
     *
     * @return string Plugin Bulk Actions Menu.
     */
    public static function render_bulk_actions( $status ) {
        ob_start();
        ?>
        <select class="ui dropdown" id="mainwp-bulk-actions">
            <option value="none"><?php esc_html_e( 'Bulk Actions', 'mainwp' ); ?></option>
            <?php if ( \mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
                <option value="ignore_updates" data-value="ignore_updates"><?php esc_html_e( 'Ignore updates', 'mainwp' ); ?></option>
            <?php endif; ?>
        <?php if ( \mainwp_current_user_can( 'dashboard', 'activate_deactivate_plugins' ) ) : ?>
                <?php if ( 'active' === $status ) : ?>
                <option value="deactivate" data-value="deactivate"><?php esc_html_e( 'Deactivate', 'mainwp' ); ?></option>
                <?php else : ?>
                    <option value="deactivate" disabled data-value="deactivate"><?php esc_html_e( 'Deactivate', 'mainwp' ); ?></option>
            <?php endif; ?>
        <?php endif; ?>
            <?php if ( 'inactive' === $status ) : ?>
                <?php if ( \mainwp_current_user_can( 'dashboard', 'activate_deactivate_plugins' ) ) : ?>
                <option value="activate" data-value="activate"><?php esc_html_e( 'Activate', 'mainwp' ); ?></option>
            <?php endif; ?>
                <?php if ( \mainwp_current_user_can( 'dashboard', 'delete_plugins' ) ) : ?>
                <option value="delete" data-value="delete"><?php esc_html_e( 'Delete', 'mainwp' ); ?></option>
            <?php endif; ?>
            <?php else : ?>
                <?php if ( \mainwp_current_user_can( 'dashboard', 'activate_deactivate_plugins' ) ) : ?>
                    <option value="activate" disabled data-value="activate"><?php esc_html_e( 'Activate', 'mainwp' ); ?></option>
                <?php endif; ?>
                <?php if ( \mainwp_current_user_can( 'dashboard', 'delete_plugins' ) ) : ?>
                    <option value="delete" disabled data-value="delete"><?php esc_html_e( 'Delete', 'mainwp' ); ?></option>
        <?php endif; ?>
        <?php endif; ?>
        <?php
        /**
         * Action: mainwp_plugins_bulk_action
         *
         * Adds a new action to the Manage Plugins bulk actions menu.
         *
         * @param string $status Status search parameter.
         *
         * @since 4.1
         */
        do_action( 'mainwp_plugins_bulk_action' );
        ?>
        </select>
        <button class="ui mini basic button" href="javascript:void(0)" id="mainwp-do-plugins-bulk-actions"><?php esc_html_e( 'Apply', 'mainwp' ); ?></button>
        <span id="mainwp_bulk_action_loading"><i class="ui active inline loader tiny"></i></span>
        <?php
        return ob_get_clean();
    }

    /**
     * Method get_manage_view().
     *
     * Get view mode.
     *
     * @param string $which plugin|theme.
     *
     * @return int view mode value.
     */
    public static function get_manage_view( $which = 'plugin' ) {
        if ( 'plugin' === $which ) {
            return get_user_option( 'mainwp_manage_plugin_view', MAINWP_VIEW_PER_PLUGIN_THEME );
        } else {
            return get_user_option( 'mainwp_manage_theme_view', MAINWP_VIEW_PER_PLUGIN_THEME );
        }
    }

    /**
     * Method render_select_manage_view().
     *
     * Handle render view mode selection.
     *
     * @param string $which plugin|theme.
     *
     * @return void.
     */
    public static function render_select_manage_view( $which = 'plugin' ) {

        $view_mode = static::get_manage_view( $which );

        $hide_show_updates_per = apply_filters( 'mainwp_manage_plugin_theme_hide_show_updates_per', false, $which );

        if ( ! $hide_show_updates_per ) {
            ?>
            <form method="post" action="" class="ui mini form right aligned">
                <?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
                <input type="hidden" name="whichview" value="<?php echo esc_attr( $which ); ?>" />
                <div class="inline field">
                    <select class="ui dropdown" onchange="mainwp_siteview_onchange(this)"  name="select_mainwp_options_plugintheme_view">
                    <option value="0" class="item" <?php echo MAINWP_VIEW_PER_PLUGIN_THEME === (int) $view_mode ? 'selected' : ''; ?>><?php echo esc_html( 'plugin' === $which ? esc_html__( 'Show Plugins per Item', 'mainwp' ) : esc_html__( 'Show Themes per Item', 'mainwp' ) ); ?></option>
                    <option value="1" class="item" <?php echo MAINWP_VIEW_PER_SITE === (int) $view_mode ? 'selected' : ''; ?>><?php echo esc_html( 'plugin' === $which ? esc_html__( 'Show Plugins per Site', 'mainwp' ) : esc_html__( 'Show Themes per Site', 'mainwp' ) ); ?></option>
                    </select>
                </div>
            </form>
            <?php
        }
    }


    /**
     * Method render_manage_per_site_table()
     *
     * Render Manage Plugins Table.
     *
     * @param array $sites Child Sites array.
     * @param array $pluginsSlug Plugins slug array.
     * @param array $sitePlugins Site plugins array.
     * @param array $pluginsMainWP MainWP plugins array.
     * @param array $muPlugins Must use plugins array.
     * @param array $pluginsName Plugin names array.
     * @param array $pluginsNameSites Plugin names with Sites array.
     * @param array $pluginsRealVersion Latest plugin release version.
     * @param array $roll_list rool items list.
     */
    public static function render_manage_per_site_table( $sites, $pluginsSlug = array(), $sitePlugins = array(), $pluginsMainWP = array(), $muPlugins = array(), $pluginsName = array(), $pluginsNameSites = array(), $pluginsRealVersion = array(), $roll_list = array() ) { //phpcs:ignore -- NOSONAR - complex method.

        $userExtension         = MainWP_DB_Common::instance()->get_user_extension();
        $decodedIgnoredPlugins = json_decode( $userExtension->ignored_plugins, true );
        $trustedPlugins        = json_decode( $userExtension->trusted_plugins, true );
        $is_demo               = MainWP_Demo_Handle::is_demo_mode();

        if ( ! is_array( $trustedPlugins ) ) {
            $trustedPlugins = array();
        }

        $updateWebsites = array();

        foreach ( $sites as $site_id => $info ) {

            $plugin_upgrades = array();
            $website         = MainWP_DB::instance()->get_website_by_id( $site_id, false, array( 'rollback_updates_data' ) );

            if ( $website && ! $website->is_ignorePluginUpdates ) {
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

                if ( is_array( $decodedIgnoredPlugins ) ) {
                    $plugin_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $plugin_upgrades, $decodedIgnoredPlugins );
                }
            }
            $updateWebsites[ $site_id ] = $plugin_upgrades;
        }

        /**
         * Action: mainwp_before_plugins_table
         *
         * Fires before the Plugins table.
         *
         * @since 4.1
         */
        do_action( 'mainwp_before_plugins_table' );
        ?>
        <div class="ui secondary segment main-master-checkbox">
            <div class="ui stackable grid">
                <div class="one wide left aligned middle aligned column">
                    <span class="trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></span>
                </div>
                <div class="one wide center aligned middle aligned column"><div class="ui checkbox main-master  not-auto-init"><input type="checkbox"/><label></label></div></div>
                <div class="six wide middle aligned column"><?php esc_html_e( 'Website', 'mainwp' ); ?></div>
                <div class="two wide center aligned middle aligned column"></div>
                <div class="two wide center aligned middle aligned column"></div>
                <div class="two wide center aligned middle aligned column"></div>
                <div class="two wide right aligned middle aligned column"><?php esc_html_e( 'Plugins', 'mainwp' ); ?></div>
            </div>
        </div>

    <div class="mainwp-manage-plugins-wrapper main-child-checkbox">
        <?php foreach ( $sites as $site_id => $website ) : ?>
            <?php
            $site_name    = $website['websitename'];
            $slugVersions = isset( $sitePlugins[ $site_id ] ) ? $sitePlugins[ $site_id ] : array();

            if ( ! is_array( $slugVersions ) ) {
                $slugVersions = array();
            }

            $item_id       = $site_id;
            $count_plugins = count( $slugVersions );

            // phpcs:disable WordPress.Security.EscapeOutput
            ?>
            <div class="ui accordion mainwp-manage-plugin-accordion mainwp-manage-plugin-item main-child-checkbox"  id="<?php echo esc_html( $item_id ); ?>">
                <div class="title master-checkbox">
                    <div class="ui stackable grid">
                        <div class="one wide left aligned middle aligned column"><i class="dropdown icon dropdown-trigger"></i></div>
                        <div class="one wide center aligned middle aligned column">
                            <div class="ui checkbox master">
                                <input type="checkbox"/>
                                <label></label>
                            </div>
                        </div>
                        <div class="four wide middle aligned column"><a target="_blank" href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo intval( $site_id ); ?>&_opennonce=<?php echo esc_html( wp_create_nonce( 'mainwp-admin-nonce' ) ); ?>"><i class="sign in icon"></i></a> <a href="admin.php?page=managesites&dashboard=<?php echo intval( $site_id ); ?>"><?php echo esc_html( $site_name ); ?></a></div>
                        <div class="two wide center aligned middle aligned column"></div>
                        <div class="two wide center aligned middle aligned column"></div>
                        <div class="two wide center aligned middle aligned column"></div>
                        <div class="two wide center aligned middle aligned column"></div>
                        <div class="two wide right aligned middle aligned column"><div class="ui label"><?php echo intval( $count_plugins ) . ' ' . esc_html( _n( 'Plugin', 'Plugins', intval( $count_plugins ), 'mainwp' ) ); ?></div></div>
                    </div>
                </div>
                <div class="content child-checkbox">
                    <?php
                    // phpcs:enable
                    foreach ( $slugVersions as $slug_ver => $plugin ) :

                        $plugin_title = wp_strip_all_tags( $pluginsName[ $slug_ver ] );

                        $plugin_slug  = $pluginsSlug[ $slug_ver ];
                        $trusted      = in_array( $plugin_slug, $trustedPlugins ) ? true : false;
                        $child_plugin = ( isset( $pluginsMainWP[ $slug_ver ] ) && 'T' === $pluginsMainWP[ $slug_ver ] ) ? true : false;

                        $plugin_mu = false;

                        $plugin_version = $sitePlugins[ $site_id ][ $slug_ver ]['version'];

                        $plugin_upgrades = isset( $updateWebsites[ $site_id ] ) ? $updateWebsites[ $site_id ] : array();
                        if ( ! is_array( $plugin_upgrades ) ) {
                            $plugin_upgrades = array();
                        }

                        $upgradeInfo = isset( $plugin_upgrades[ $plugin_slug ] ) ? $plugin_upgrades[ $plugin_slug ] : false;

                        $new_version = '';
                        if ( ! empty( $upgradeInfo ) && isset( $upgradeInfo['update']['new_version'] ) ) {
                            $new_version = $upgradeInfo['update']['new_version'];
                        }

                        if ( isset( $sitePlugins[ $site_id ][ $slug_ver ] ) && ( empty( $sitePlugins[ $site_id ][ $slug_ver ]['active'] ) || 1 === (int) $sitePlugins[ $site_id ][ $slug_ver ]['active'] ) ) {
                            $actived = true;
                            if ( isset( $sitePlugins[ $site_id ][ $slug_ver ]['active'] ) && 1 === (int) $sitePlugins[ $site_id ][ $slug_ver ]['active'] ) {
                                $plugin_status = '<span class="ui small green basic label">' . esc_html__( 'Active', 'mainwp' ) . '</span>';
                            } elseif ( isset( $sitePlugins[ $site_id ][ $slug_ver ]['active'] ) && 0 === (int) $sitePlugins[ $site_id ][ $slug_ver ]['active'] ) {
                                $plugin_status = '<span class="ui small red basic label">' . esc_html__( 'Inactive', 'mainwp' ) . '</span>';
                                $actived       = false;
                            } else {
                                $plugin_status = '';
                            }

                            if ( isset( $sitePlugins[ $site_id ][ $slug_ver ] ) && ( 1 === (int) $muPlugins[ $slug_ver ] ) ) {
                                $plugin_mu = true;
                            }

                            if ( $plugin_mu ) {
                                $actived = true; // always.
                            }

                            $item_id = $slug_ver . '_' . $site_id;
                            $item_id = strtolower( $item_id );
                            $item_id = preg_replace( '/[[:space:]]+/', '_', $item_id );

                            $plugin_directory = MainWP_Utility::get_dir_slug( $plugin_slug );
                            $details_link     = self_admin_url( 'plugin-install.php?tab=plugin-information&wpplugin=' . intval( $site_id ) . '&plugin=' . rawurlencode( $plugin_directory ) . '&section=changelog' );
                            ?>
                            <div class="ui very compact stackable grid mainwp-manage-plugin-item-website" plugin-slug="<?php echo esc_attr( rawurlencode( $plugin_slug ) ); ?>" plugin-name="<?php echo esc_html( $plugin_title ); ?>" site-id="<?php echo intval( $site_id ); ?>" site-name="<?php echo esc_html( $site_name ); ?>" id="<?php echo esc_html( $item_id ); ?>">
                            <div class="one wide center aligned middle aligned column"></div>
                                <div class="one wide left aligned middle aligned column">
                                    <div class="ui checkbox child <?php echo 'mainwp-child' === $plugin_directory ? 'disabled' : ''; ?>">
                                    <input type="checkbox"
                                    <?php
                                        echo 'mainwp-child' === $plugin_directory ? 'disabled="disabled"' : 'class="mainwp-selected-plugin-site"';
                                    ?>
                                    ><label></label>
                                </div>
                                </div>
                                <?php // phpcs:disable WordPress.Security.EscapeOutput ?>
                                <div class="one wide center aligned middle aligned column"><?php echo MainWP_System_Utility::get_plugin_icon( $plugin_directory ); ?></div>
                                <?php // phpcs:enable ?>
                                <div class="three wide middle aligned column"><a class="open-plugin-details-modal" href="<?php echo esc_url( $details_link ); ?>" target="_blank" ><?php echo esc_html( $plugin_title ); ?></a></div>
                                <div class="one wide center aligned middle aligned column"><?php echo $plugin_status; //phpcs:ignore -- escaped. ?></div>
                                <div class="two wide center aligned middle aligned column"><?php echo $trusted ? '<span class="ui tiny basic green label">' . esc_html__( 'Trusted', 'mainwp' ) . '</span>' : '<span class="ui tiny basic grey label">' . esc_html__( 'Not Trusted', 'mainwp' ) . '</span>'; ?></div>
                                <div class="one wide center aligned middle aligned column"><?php echo $plugin_mu ? '<span class="ui small label"><i class="exclamation orange triangle icon"></i> MU</span>' : ''; ?></div>
                                <div class="two wide right aligned middle aligned column current-version">
                                    <?php echo esc_html( $plugin_version ); ?>
                                    <?php if ( ! empty( $new_version ) ) : ?>
                                    &rarr;
                                        <?php
                                        if ( ! empty( $roll_list[ $site_id ][ $plugin_slug ][ $new_version ] ) ) {
                                            echo MainWP_Updates_Helper::get_roll_msg( $roll_list[ $site_id ][ $plugin_slug ][ $new_version ], true, 'notice' ); //phpcs:ignore -- NOSONAR -- ok.
                                        }
                                        echo esc_html( $new_version );
                                        ?>
                                    <?php endif; ?>
                                </div>
                                <div class="two wide right aligned middle aligned column update-column" updated="0">
                                <?php if ( ! empty( $upgradeInfo ) && MainWP_Updates::user_can_update_plugins() ) : ?>
                                    <span data-position="top right" data-tooltip="<?php echo esc_attr__( 'Update ', 'mainwp' ) . esc_html( $plugin_title ) . ' ' . esc_attr__( 'plugin on this child site.', 'mainwp' ); ?>" data-inverted=""><a href="javascript:void(0)" class="ui mini green basic button <?php echo $is_demo ? 'disabled' : ''; ?>" onClick="return manage_plugins_upgrade( '<?php echo esc_js( rawurlencode( $plugin_slug ) ); ?>', <?php echo esc_attr( $site_id ); ?> )"><?php esc_html_e( 'Update', 'mainwp' ); ?></a></span>
                                <?php endif; ?>
                                </div>
                            <div class="two wide center aligned middle aligned column column-actions">
                                <?php if ( ! $child_plugin ) : ?>
                                    <?php if ( $actived ) { ?>
                                        <?php if ( ! $plugin_mu && \mainwp_current_user_can( 'dashboard', 'activate_deactivate_plugins' ) ) { ?>
                                            <a href="#" class="mainwp-manage-plugin-deactivate ui mini fluid button <?php echo $is_demo ? 'disabled' : ''; ?>" data-position="top right" data-tooltip="<?php echo esc_attr__( 'Deactivate ', 'mainwp' ) . esc_html( $plugin_title ) . ' ' . esc_attr__( 'plugin on this child site.', 'mainwp' ); ?>" data-inverted=""><?php esc_html_e( 'Deactivate', 'mainwp' ); ?></a>
                                        <?php } ?>
                                    <?php } else { ?>
                                        <div class="ui mini fluid buttons">
                                            <?php if ( \mainwp_current_user_can( 'dashboard', 'activate_deactivate_plugins' ) ) { ?>
                                                <a href="#" class="mainwp-manage-plugin-activate ui green button <?php echo $is_demo ? 'disabled' : ''; ?>" data-position="top right" data-tooltip="<?php echo esc_attr__( 'Activate ', 'mainwp' ) . esc_html( wp_strip_all_tags( $plugin_title ) ) . ' ' . esc_attr__( 'plugin on this child site.', 'mainwp' ); ?>" data-inverted=""><?php esc_html_e( 'Activate', 'mainwp' ); ?></a>
                                            <?php } ?>
                                            <?php if ( \mainwp_current_user_can( 'dashboard', 'delete_plugins' ) ) { ?>
                                                <a href="#" class="mainwp-manage-plugin-delete ui button <?php echo $is_demo ? 'disabled' : ''; ?>" data-position="top right" data-tooltip="<?php echo esc_attr__( 'Delete ', 'mainwp' ) . ' ' . esc_html( wp_strip_all_tags( $plugin_title ) ) . ' ' . esc_attr__( 'plugin from this child site.', 'mainwp' ); ?>" data-inverted=""><?php esc_html_e( 'Delete', 'mainwp' ); ?></a>
                                            <?php } ?>
                                        </div>
                                    <?php } ?>
                                <?php endif; ?>
                            </div>
                        </div>
                            <?php

                        }
                    endforeach;
                    ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

        <script type="text/javascript">
            jQuery( '.mainwp-manage-plugin-accordion' ).accordion( {
                "selector": {
                    "trigger"   : '.dropdown-trigger',
                }
            } );

            jQuery( '.trigger-all-accordion' ).on( 'click', function() { // not use document here.
                if ( jQuery( this ).hasClass( 'active' ) ) {
                    jQuery( this ).removeClass( 'active' );
                    jQuery( '.mainwp-manage-plugins-wrapper .ui.accordion div.title' ).each( function( i ) {
                        if ( jQuery( this ).hasClass( 'active' ) ) {
                            jQuery( this ).find('.dropdown-trigger').trigger( 'click' );
                        }
                    } );
                } else {
                    jQuery( this ).addClass( 'active' );
                    jQuery( '.mainwp-manage-plugins-wrapper .ui.accordion div.title' ).each( function( i ) {
                        if ( !jQuery( this ).hasClass( 'active' ) ) {
                            jQuery( this ).find('.dropdown-trigger').trigger( 'click' );
                        }
                    } );
                }
                return false;
            } );

            jQuery(function($) {
                mainwp_master_checkbox_init($);
                mainwp_get_icon_start();
                mainwp_show_hide_install_to_selected_sites( 'plugin' );
            } );

        </script>

            <?php
            /**
             * Action: mainwp_after_plugins_table
             *
             * Fires after the Plugins table.
             *
             * @since 4.1
             */
            do_action( 'mainwp_after_plugins_table' );
    }



    /**
     * Method render_manage_table()
     *
     * Render Manage Plugins Table.
     *
     * @param array $sites Child Sites array.
     * @param array $pluginsSlug Plugins slug array.
     * @param array $sitePlugins Site plugins array.
     * @param array $pluginsMainWP MainWP plugins array.
     * @param array $muPlugins Must use plugins array.
     * @param array $pluginsName Plugin names array.
     * @param array $pluginsNameSites Plugin names with Sites array.
     * @param array $pluginsRealVersion Latest plugin release version.
     * @param array $roll_list rool items list.
     */
    public static function render_manage_table( $sites, $pluginsSlug, $sitePlugins, $pluginsMainWP, $muPlugins, $pluginsName, $pluginsNameSites, $pluginsRealVersion, $roll_list = array() ) { //phpcs:ignore -- NOSONAR - complex method.

        $userExtension         = MainWP_DB_Common::instance()->get_user_extension();
        $decodedIgnoredPlugins = json_decode( $userExtension->ignored_plugins, true );
        $trustedPlugins        = json_decode( $userExtension->trusted_plugins, true );
        $is_demo               = MainWP_Demo_Handle::is_demo_mode();

        if ( ! is_array( $trustedPlugins ) ) {
            $trustedPlugins = array();
        }

        $updateWebsites = array();

        foreach ( $sites as $site_id => $info ) {

            $plugin_upgrades = array();
            $website         = MainWP_DB::instance()->get_website_by_id( $site_id );
            if ( $website && ! $website->is_ignorePluginUpdates ) {
                $plugin_upgrades = json_decode( $website->plugin_upgrades, true );
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

                if ( is_array( $decodedIgnoredPlugins ) ) {
                    $plugin_upgrades = MainWP_Common_Functions::instance()->get_not_ignored_updates_themesplugins( $plugin_upgrades, $decodedIgnoredPlugins );
                }
            }
            $updateWebsites[ $site_id ] = $plugin_upgrades;
        }

        /**
         * Action: mainwp_before_plugins_table
         *
         * Fires before the Plugins table.
         *
         * @since 4.1
         */
        do_action( 'mainwp_before_plugins_table' );
        ?>
        <div class="ui secondary segment main-master-checkbox">
            <div class="ui stackable grid">
                <div class="one wide left aligned middle aligned column">
                    <span class="trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></span>
                </div>
                <div class="one wide center aligned middle aligned column"><div class="ui checkbox main-master  not-auto-init"><input type="checkbox"/><label></label></div></div>
                <div class="one wide center aligned middle aligned column"></div>
                <div class="five wide middle aligned column"><?php esc_html_e( 'Plugin', 'mainwp' ); ?></div>
                <div class="two wide center aligned middle aligned column"></div>
                <div class="two wide right aligned middle aligned column"><?php esc_html_e( 'Latest Version', 'mainwp' ); ?></div>
                <div class="two wide center aligned middle aligned column"></div>
                <div class="two wide right aligned middle aligned column"><?php esc_html_e( 'Websites', 'mainwp' ); ?></div>
            </div>
        </div>

    <div class="mainwp-manage-plugins-wrapper main-child-checkbox">
        <?php foreach ( $pluginsNameSites as $plugin_title => $pluginSites ) : ?>
            <?php
            $slugVersions      = current( $pluginSites );
            $slug_ver_first    = $slugVersions[0]; // get the first one [slug]_[version] to get plugin [slug].
            $plugin_slug_first = $pluginsSlug[ $slug_ver_first ];
            $plugin_directory  = MainWP_Utility::get_dir_slug( $plugin_slug_first );

            $plugin_status = '';

            $item_id = strtolower( $plugin_title );
            $item_id = preg_replace( '/[[:space:]]+/', '_', $item_id );

            $count_sites = count( $pluginSites );

            reset( $pluginSites );
            $first_siteid = key( $pluginSites );

            $details_link    = self_admin_url( 'plugin-install.php?tab=plugin-information&wpplugin=' . intval( $first_siteid ) . '&plugin=' . rawurlencode( $plugin_directory ) . '&section=changelog' );
            $lastest_version = '';
            // phpcs:disable WordPress.Security.EscapeOutput
            ?>
            <div class="ui accordion mainwp-manage-plugin-accordion mainwp-manage-plugin-item main-child-checkbox"  id="<?php echo esc_html( $item_id ); ?>">
                <div class="title master-checkbox">
                    <div class="ui grid">
                        <div class="one wide left aligned middle aligned column"><i class="dropdown icon dropdown-trigger"></i></div>
                        <div class="one wide center aligned middle aligned column">
                            <div class="ui checkbox <?php echo 'mainwp-child' === $plugin_directory ? 'disabled' : ''; ?> master"><input type="checkbox" <?php echo 'mainwp-child' === $plugin_directory ? 'disabled="disabled"' : ''; ?>><label></label></div>
                        </div>
                        <div class="one wide center aligned middle aligned column"><?php echo MainWP_System_Utility::get_plugin_icon( $plugin_directory ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
                        <div class="five wide middle aligned column"><a class="open-plugin-details-modal" href="<?php echo esc_url( $details_link ); ?>" target="_blank" ><?php echo esc_html( $plugin_title ); ?></a></div>
                        <div class="two wide center aligned middle aligned column"></div>
                        <div class="two wide right aligned middle aligned column lastest-version-info"></div>
                        <div class="two wide center aligned middle aligned column"></div>
                        <div class="two wide right aligned middle aligned column"><div class="ui label"><?php echo intval( $count_sites ) . ' ' . esc_html( _n( 'Site', 'Sites', intval( $count_sites ), 'mainwp' ) ); ?></div></div>
                    </div>
                </div>
                <div class="content child-checkbox">
                    <?php
                    // phpcs:enable
                    foreach ( $pluginSites as $site_id => $slugVersions ) :
                        $site_name = $sites[ $site_id ]['websitename'];

                        foreach ( $slugVersions as $slug_ver ) :

                            $plugin_slug  = $pluginsSlug[ $slug_ver ];
                            $trusted      = in_array( $plugin_slug, $trustedPlugins ) ? true : false;
                            $child_plugin = ( isset( $pluginsMainWP[ $slug_ver ] ) && 'T' === $pluginsMainWP[ $slug_ver ] ) ? true : false;

                            $plugin_mu = false;

                            $plugin_version = $sitePlugins[ $site_id ][ $slug_ver ]['version'];

                            $plugin_upgrades = isset( $updateWebsites[ $site_id ] ) ? $updateWebsites[ $site_id ] : array();
                            if ( ! is_array( $plugin_upgrades ) ) {
                                $plugin_upgrades = array();
                            }

                            $upgradeInfo = isset( $plugin_upgrades[ $plugin_slug ] ) ? $plugin_upgrades[ $plugin_slug ] : false;

                            $new_version = '';
                            if ( ! empty( $upgradeInfo ) && isset( $upgradeInfo['update']['new_version'] ) ) {
                                $new_version = $upgradeInfo['update']['new_version'];
                            }

                            if ( '' === $lastest_version || version_compare( $plugin_version, $lastest_version, '>' ) ) {
                                $lastest_version = $plugin_version;
                            }

                            if ( '' !== $new_version && version_compare( $new_version, $lastest_version, '>' ) ) {
                                $lastest_version = $new_version;
                            }

                            if ( isset( $sitePlugins[ $site_id ][ $slug_ver ] ) && ( 0 === (int) $sitePlugins[ $site_id ][ $slug_ver ]['active'] || 1 === (int) $sitePlugins[ $site_id ][ $slug_ver ]['active'] ) ) {
                                $actived = true;
                                if ( isset( $sitePlugins[ $site_id ][ $slug_ver ]['active'] ) && 1 === (int) $sitePlugins[ $site_id ][ $slug_ver ]['active'] ) {
                                    $plugin_status = '<span class="ui small green basic label">' . esc_html__( 'Active', 'mainwp' ) . '</span>';
                                } elseif ( isset( $sitePlugins[ $site_id ][ $slug_ver ]['active'] ) && 0 === (int) $sitePlugins[ $site_id ][ $slug_ver ]['active'] ) {
                                    $plugin_status = '<span class="ui small red basic label">' . esc_html__( 'Inactive', 'mainwp' ) . '</span>';
                                    $actived       = false;
                                } else {
                                    $plugin_status = '';
                                }

                                if ( isset( $sitePlugins[ $site_id ][ $slug_ver ] ) && ( 1 === (int) $muPlugins[ $slug_ver ] ) ) {
                                    $plugin_mu = true;
                                }
                                if ( $plugin_mu ) {
                                    $actived = true; // always.
                                }
                                $item_id = $slug_ver . '_' . $site_id;
                                $item_id = strtolower( $item_id );
                                $item_id = preg_replace( '/[[:space:]]+/', '_', $item_id );

                                ?>
                        <div class="ui stackable grid very compact mainwp-manage-plugin-item-website" plugin-slug="<?php echo esc_attr( rawurlencode( $plugin_slug ) ); ?>" plugin-name="<?php echo esc_html( wp_strip_all_tags( $pluginsName[ $slug_ver ] ) ); ?>" site-id="<?php echo intval( $site_id ); ?>" site-name="<?php echo esc_html( $site_name ); ?>" id="<?php echo esc_html( $item_id ); ?>">
                            <div class="one wide left aligned middle aligned column"></div>
                                <div class="one wide center aligned middle aligned column">
                                        <?php if ( $child_plugin ) { ?>
                                            <div class="ui disabled checkbox"><input type="checkbox" disabled="disabled"><label></label></div>
                                    <?php } else { ?>
                                        <div class="ui checkbox child">
                                            <input type="checkbox" class="mainwp-selected-plugin-site" />
                                            <label></label>
                                        </div>
                                            <?php
                                    }
                                    ?>
                                </div>
                                    <div class="three wide middle aligned column"><a target="_blank" href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo intval( $site_id ); ?>&_opennonce=<?php echo esc_html( wp_create_nonce( 'mainwp-admin-nonce' ) ); ?>"><i class="sign in icon"></i></a> <a href="admin.php?page=managesites&dashboard=<?php echo intval( $site_id ); ?>"><?php echo esc_html( $site_name ); ?></a></div>
                                    <div class="one wide middle aligned column"></div>
                                <div class="one wide center aligned middle aligned column"><?php echo $plugin_status; //phpcs:ignore -- escaped. ?></div>
                                    <div class="two wide center aligned middle aligned column"><?php echo $trusted ? '<span class="ui tiny basic green label">' . esc_html__( 'Trusted', 'mainwp' ) . '</span>' : '<span class="ui tiny basic grey label">' . esc_html__( 'Not Trusted', 'mainwp' ) . '</span>'; ?></div>


                                    <div class="one wide center aligned middle aligned column"><?php echo $plugin_mu ? '<span class="ui small label"><i class="exclamation orange triangle icon"></i> MU</span>' : ''; ?></div>

                                    <div class="two wide center aligned middle aligned column current-version">
                                        <?php echo esc_html( $plugin_version ); ?>
                                        <?php if ( ! empty( $new_version ) ) : ?>
                                        &rarr;
                                            <?php
                                            if ( ! empty( $roll_list[ $site_id ][ $plugin_slug ][ $new_version ] ) ) {
                                                echo MainWP_Updates_Helper::get_roll_msg( $roll_list[ $site_id ][ $plugin_slug ][ $new_version ], true, 'notice' ); //phpcs:ignore -- NOSONAR -- ok.
                                            }
                                            echo esc_html( $new_version );
                                            ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="two wide right aligned middle aligned column update-column" updated="0">
                                    <?php if ( ! empty( $upgradeInfo ) && MainWP_Updates::user_can_update_plugins() ) : ?>
                                        <span data-position="top right" data-tooltip="<?php echo esc_attr__( 'Update ', 'mainwp' ) . esc_html( $plugin_title ) . ' ' . esc_attr__( 'lugin on this child site.', 'mainwp' ); ?>" data-inverted="" ><a href="javascript:void(0)" class="ui mini green basic button <?php echo $is_demo ? 'disabled' : ''; ?>" onClick="return manage_plugins_upgrade( '<?php echo esc_js( rawurlencode( $plugin_slug ) ); ?>', <?php echo esc_attr( $site_id ); ?> )"><?php esc_html_e( 'Update', 'mainwp' ); ?></a></span>
                                    <?php endif; ?>
                                    </div>
                                <div class="two wide center aligned middle aligned column column-actions">
                                    <?php if ( ! $child_plugin ) : ?>
                                        <?php if ( $actived ) { ?>
                                            <?php if ( ! $plugin_mu && \mainwp_current_user_can( 'dashboard', 'activate_deactivate_plugins' ) ) { ?>
                                                <a href="#" class="mainwp-manage-plugin-deactivate ui mini fluid button <?php echo $is_demo ? 'disabled' : ''; ?>"><?php esc_html_e( 'Deactivate', 'mainwp' ); ?></a>
                                        <?php } ?>
                                    <?php } else { ?>
                                            <div class="ui mini fluid buttons">
                                            <?php if ( \mainwp_current_user_can( 'dashboard', 'activate_deactivate_plugins' ) ) { ?>
                                                <a href="#" class="mainwp-manage-plugin-activate ui green button <?php echo $is_demo ? 'disabled' : ''; ?>" ><?php esc_html_e( 'Activate', 'mainwp' ); ?></a>
                                        <?php } ?>
                                            <?php if ( \mainwp_current_user_can( 'dashboard', 'delete_plugins' ) ) { ?>
                                                <a href="#" class="mainwp-manage-plugin-delete ui button <?php echo $is_demo ? 'disabled' : ''; ?>" ><?php esc_html_e( 'Delete', 'mainwp' ); ?></a>
                                        <?php } ?>
                                            </div>
                                    <?php } ?>
                                <?php endif; ?>
                                </div>
                            </div>
                                <?php

                            }
                    endforeach;
                        ?>
                        <span style="display:none" class="lastest-version-hidden" lastest-version="<?php echo esc_html( $lastest_version ); ?>"></span>
                        <?php
                endforeach;
                    ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

        <script type="text/javascript">
            jQuery( '.mainwp-manage-plugin-accordion' ).accordion( {
                "selector": {
                    "trigger"   : '.dropdown-trigger',
                }
            } );

            jQuery( '.trigger-all-accordion' ).on( 'click', function() { // not use document here.
                if ( jQuery( this ).hasClass( 'active' ) ) {
                    jQuery( this ).removeClass( 'active' );
                    jQuery( '.mainwp-manage-plugins-wrapper .ui.accordion div.title' ).each( function( i ) {
                        if ( jQuery( this ).hasClass( 'active' ) ) {
                            jQuery( this ).find('.dropdown-trigger').trigger( 'click' );
                        }
                    } );
                } else {
                    jQuery( this ).addClass( 'active' );
                    jQuery( '.mainwp-manage-plugins-wrapper .ui.accordion div.title' ).each( function( i ) {
                        if ( !jQuery( this ).hasClass( 'active' ) ) {
                            jQuery( this ).find('.dropdown-trigger').trigger( 'click' );
                        }
                    } );
                }
                return false;
            } );



            jQuery(function($) {
                $( '.lastest-version-hidden' ).each(function(){
                    $(this).closest('.mainwp-manage-plugin-item').find('.lastest-version-info').html( $(this).attr('lastest-version') );
                });
                mainwp_master_checkbox_init($);
                mainwp_get_icon_start();
                mainwp_show_hide_install_to_selected_sites( 'plugin' );
            } );

        </script>

            <?php
            /**
             * Action: mainwp_after_plugins_table
             *
             * Fires after the Plugins table.
             *
             * @since 4.1
             */
            do_action( 'mainwp_after_plugins_table' );
    }


    /** Render Install Subpage. */
    public static function render_install() {
        static::render_header( 'Install' );
        static::render_plugins_table();
        static::render_footer( 'Install' );
    }


    /**
     * Render Install plugins Table.
     *
     * @uses \MainWP\Dashboard\MainWP_UI::render_modal_install_plugin_theme()
     *
     * @uses \MainWP\Dashboard\MainWP_Install_Bulk::render_upload()
     */
    public static function render_plugins_table() {

        /**
         * Tab array.
         *
         * @global object
         */
        global $tab;

        if ( ! \mainwp_current_user_can( 'dashboard', 'install_plugins' ) ) {
            \mainwp_do_not_have_permissions( esc_html__( 'install plugins', 'mainwp' ) );
            return;
        }
        ?>

    <div class="ui alt segment" id="mainwp-install-plugins">
        <div class="mainwp-main-content">
            <div class="mainwp-actions-bar">
                <div class="ui stackable two column grid">

                    <div class="middle aligned column">
                        <div class="ui mini stackable buttons">
                            <a href="#" id="MainWPInstallBulkNavSearch" class="ui basic button mainwp-bulk-install-tabs-header-btn" ><?php esc_html_e( 'Install from WordPress.org', 'mainwp' ); ?></a>
                            <a href="#" id="MainWPInstallBulkNavUpload" class="ui basic button mainwp-bulk-install-tabs-header-btn" ><?php esc_html_e( 'Upload .zip file', 'mainwp' ); ?></a>
                            <?php do_action( 'mainwp_install_plugin_theme_tabs_header_top', 'plugin' ); ?>
                        </div>
                        <?php
                        /**
                         * Install Plugins actions bar (right)
                         *
                         * Fires at the left side of the actions bar on the Install Plugins screen, after the Nav buttons.
                         *
                         * @since 4.0
                         */
                        do_action( 'mainwp_install_plugins_actions_bar_right' );
                        ?>
                    </div>

                    <div class="right aligned column">
                        <div id="mainwp-search-plugins-form" class="ui search focus mainwp-bulk-install-showhide-content">
                            <div class="ui icon mini input">
                                <input id="mainwp-search-plugins-form-field" class="fluid prompt" type="text" placeholder="<?php esc_attr_e( 'Search plugins...', 'mainwp' ); ?>" value="<?php echo isset( $_GET['s'] ) ? esc_html( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized ?>">
                                <i class="search icon"></i>
                            </div>
                            <div class="results"></div>
                        </div>
                        <script type="text/javascript">
                            jQuery( document ).ready(function () {
                                jQuery( '#mainwp-search-plugins-form-field' ).on( 'keypress', function(e) {
                                    let search = jQuery( '#mainwp-search-plugins-form-field' ).val();
                                    let sel_ids = jQuery( '#plugin_install_selected_sites' ).val();
                                    if ( '' != sel_ids )
                                        sel_ids = '&selected_sites=' + sel_ids;
                                    let origin   = '<?php echo esc_url( get_admin_url() ); ?>';
                                    if ( 13 === e.which ) {
                                        location.href = origin + 'admin.php?page=PluginsInstall&tab=search&s=' + encodeURIComponent(search) + sel_ids;
                                    }
                                } );
                            } );
                        </script>
                        <?php
                        /**
                         * Install Plugins actions bar (left)
                         *
                         * Fires at the left side of the actions bar on the Install Plugins screen, after the Search bar.
                         *
                         * @since 4.0
                         */
                        do_action( 'mainwp_install_plugins_actions_bar_left' );
                        ?>
                    </div>

                </div>
            </div>


                <div class="ui segment">
            <?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-install-plugins-info-message' ) ) : ?>
                <div class="ui info message">
                    <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-install-plugins-info-message"></i>
                    <?php printf( esc_html__( 'Install plugins on your child sites.  You can install plugins from the WordPress.org repository or by uploading a ZIP file.  For additional help, please check this %1$shelp documentation%2$s.', 'mainwp' ), '<a href="https://mainwp.com/kb/install-plugins/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); ?>
                </div>
            <?php endif; ?>
            <div id="mainwp-message-zone" class="ui message" style="display:none;"></div>
            <div class="mainwp-upload-plugin mainwp-bulk-install-showhide-content" style="display:none;">
                <?php MainWP_Install_Bulk::render_upload( 'plugin' ); ?>
            </div>
            <div class="mainwp-browse-plugins mainwp-bulk-install-showhide-content">
                <form id="plugin-filter" method="post">
                    <?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
                    <?php static::$pluginsTable->display(); ?>
                </form>
            </div>
            <?php
            // @since 5.4.
            do_action( 'mainwp_bulk_install_tabs_content', 'plugin' );

            MainWP_UI::render_modal_install_plugin_theme();
            MainWP_Updates::render_plugin_details_modal();
            ?>
        </div>
    </div>
        <?php
        $selected_sites = array();

        if ( isset( $_GET['selected_sites'] ) && ! empty( $_GET['selected_sites'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $selected_sites = explode( '-', sanitize_text_field( wp_unslash( $_GET['selected_sites'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize ok.
            $selected_sites = array_map( 'intval', $selected_sites );
            $selected_sites = array_filter( $selected_sites );
        }
        ?>
    <div class="mainwp-side-content mainwp-no-padding">
        <?php do_action( 'mainwp_manage_plugins_sidebar_top' ); ?>
        <div class="mainwp-select-sites ui accordion mainwp-sidebar-accordion">
            <?php do_action( 'mainwp_manage_plugins_before_select_sites' ); ?>
            <div class="title active"><i class="dropdown icon"></i> <?php esc_html_e( 'Select Sites', 'mainwp' ); ?></div>
            <div class="content active">
            <?php
            $sel_params = array(
                'class'          => 'mainwp_select_sites_box_left',
                'selected_sites' => $selected_sites,
                'show_client'    => true,
            );
            MainWP_UI_Select_Sites::select_sites_box( $sel_params );
            ?>
            </div>
            <?php do_action( 'mainwp_manage_plugins_after_select_sites' ); ?>
        </div>
        <input type="hidden" id="plugin_install_selected_sites" name="plugin_install_selected_sites" value="<?php echo esc_html( implode( '-', $selected_sites ) ); ?>" />
        <div class="ui fitted divider"></div>
        <div class="mainwp-search-options ui accordion mainwp-sidebar-accordion">
            <?php do_action( 'mainwp_manage_plugins_before_search_options' ); ?>
            <div class="title active"><i class="dropdown icon"></i> <?php esc_html_e( 'Installation Options', 'mainwp' ); ?></div>
            <div class="content active">
            <div class="ui form">
                <div class="field">
                    <div class="ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'If enabled, the plugin will be automatically activated after the installation.', 'mainwp' ); ?>" data-position="left center" data-inverted="">
                        <input type="checkbox" value="1" checked="checked" id="chk_activate_plugin" />
                        <label for="chk_activate_plugin"><?php esc_html_e( 'Activate after installation', 'mainwp' ); ?></label>
                    </div>
                </div>
                <div class="field">
                    <div class="ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'If enabled and the plugin already installed on the sites, the already installed version will be overwritten.', 'mainwp' ); ?>" data-position="left center" data-inverted="">
                        <input type="checkbox" value="2" checked="checked" id="chk_overwrite" />
                        <label for="chk_overwrite"><?php esc_html_e( 'Overwrite existing version', 'mainwp' ); ?></label>
                    </div>
                </div>
            </div>
            </div>
            <?php do_action( 'mainwp_manage_plugins_after_search_options' ); ?>
        </div>
        <div class="ui fitted divider"></div>
        <div class="mainwp-search-submit">
            <?php do_action( 'mainwp_manage_plugins_before_submit_button' ); ?>
        <?php
        /**
         * Disables plugin installation
         *
         * Filters whether file modifications are allowed on the Dashboard site. If not, installation process will be disabled too.
         *
         * @since 4.1
         */
        $allow_install = apply_filters( 'file_mod_allowed', true, 'mainwp_install_plugin' );
        if ( $allow_install ) {
            $is_demo = MainWP_Demo_Handle::is_demo_mode();
            if ( $is_demo ) {
                MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<input type="button" disabled="disabled" class="ui green big fluid button disabled" value="' . esc_html__( 'Complete Installation', 'mainwp' ) . '">' );
            } else {
                ?>
                <input type="button" value="<?php esc_attr_e( 'Complete Installation', 'mainwp' ); ?>" class="ui green big fluid button" id="mainwp_plugin_bulk_install_btn" bulk-action="install" name="bulk-install">
                <?php
            }
        }
        ?>
            <?php do_action( 'mainwp_manage_plugins_before_submit_button' ); ?>
            <?php
            // @since 5.4.
            do_action( 'mainwp_bulk_install_sidebar_submit_bottom', 'plugin' );
            ?>
        </div>
        <?php do_action( 'mainwp_manage_plugins_sidebar_bottom', 'install' ); ?>
    </div>
    <div class="ui clearing hidden divider"></div>
    </div>
        <?php
    }

    /**
     * Render Autoupdate SubPage.
     *
     * @uses \MainWP\Dashboard\MainWP_UI::render_modal_edit_notes()
     */
    public static function render_auto_update() {  // phpcs:ignore -- NOSONAR -Current complexity is the only way to achieve desired results, pull request solutions appreciated.

        $cachedAUSearch = isset( $_SESSION['MainWP_PluginsActiveStatus'] ) ? $_SESSION['MainWP_PluginsActiveStatus'] : null; //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized --- ok.

        static::render_header( 'AutoUpdate' );

        if ( ! \mainwp_current_user_can( 'dashboard', 'trust_untrust_updates' ) ) {
            \mainwp_do_not_have_permissions( esc_html__( 'trust/untrust updates', 'mainwp' ) );
        } else {
            $snPluginAutomaticDailyUpdate = get_option( 'mainwp_pluginAutomaticDailyUpdate' );

            if ( false === $snPluginAutomaticDailyUpdate ) {
                $snPluginAutomaticDailyUpdate = get_option( 'mainwp_automaticDailyUpdate' );
                update_option( 'mainwp_pluginAutomaticDailyUpdate', $snPluginAutomaticDailyUpdate );
            }

            ?>
            <div class="ui alt segment" id="mainwp-plugin-auto-updates">
                <div class="mainwp-main-content">
                    <div class="mainwp-actions-bar">
                        <div class="ui mini form stackable grid">
                            <div class="ui two column row">
                                <div class="left aligned column">
                                    <select id="mainwp-bulk-actions" name="bulk_action" class="ui mini selection dropdown">
                                        <option class="item" value=""><?php esc_html_e( 'Bulk Actions', 'mainwp' ); ?></option>
                                        <option class="item" value="trust"><?php esc_html_e( 'Trust', 'mainwp' ); ?></option>
                                        <option class="item" value="untrust"><?php esc_html_e( 'Untrust', 'mainwp' ); ?></option>
                                                <?php
                                                /**
                                                 * Action: mainwp_plugins_auto_updates_bulk_action
                                                 *
                                                 * Adds new action to the bulk actions menu on Plugins Auto Updates.
                                                 *
                                                 * @since 4.1
                                                 */
                                                do_action( 'mainwp_plugins_auto_updates_bulk_action' );
                                                ?>
                                    </select>
                                        <input type="button" name="" id="mainwp-bulk-trust-plugins-action-apply" class="ui mini basic button" value="<?php esc_attr_e( 'Apply', 'mainwp' ); ?>"/>
                                    </div>
                                <div class="right aligned column"></div>
                            </div>
                        </div>
                    </div>

                    <?php if ( isset( $_GET['message'] ) && 'saved' === $_GET['message'] ) : // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized ?>
                        <div class="ui message green"><?php esc_html_e( 'Settings have been saved.', 'mainwp' ); ?></div>
                    <?php endif; ?>
                    <div id="mainwp-message-zone" class="ui message" style="display:none"></div>
                    <div id="mainwp-auto-updates-plugins-content" class="ui segment">
                        <?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-disable-auto-updates-info-message' ) ) : ?>
                        <div class="ui info message">
                            <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-disable-auto-updates-info-message"></i>
                            <div><?php printf( esc_html__( 'Check out %1$show to disable the WordPress built in auto-updates feature%2$s.', 'mainwp' ), '<a href="https://mainwp.com/how-to-disable-automatic-plugin-and-theme-updates-on-your-child-sites/" target="_blank">', '</a>' ); // NOSONAR - noopener - open safe. ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-plugins-auto-updates-info-message' ) ) : ?>
                        <div class="ui info message">
                            <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-plugins-auto-updates-info-message"></i>
                            <div><?php esc_html_e( 'The MainWP Advanced Auto Updates feature is a tool for your Dashboard to automatically update plugins that you trust to be updated without breaking your Child sites.', 'mainwp' ); ?></div>
                            <div><?php esc_html_e( 'Only mark plugins as trusted if you are absolutely sure they can be automatically updated by your MainWP Dashboard without causing issues on the Child sites!', 'mainwp' ); ?></div>
                            <div><strong><?php esc_html_e( 'Advanced Auto Updates a delayed approximately 24 hours from the update release.  Ignored plugins can not be automatically updated.', 'mainwp' ); ?></strong></div>
                        </div>
                        <?php endif; ?>
                        <div class="ui inverted dimmer">
                            <div class="ui text loader"><?php esc_html_e( 'Loading plugins', 'mainwp' ); ?></div>
                        </div>
                        <div id="mainwp-auto-updates-plugins-table-wrapper">
                        <?php
                        if ( isset( $_SESSION['MainWP_PluginsActive'] ) ) {
                            static::render_all_active_table( $_SESSION['MainWP_PluginsActive'] ); //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                        }
                        ?>
                    </div>
                </div>
            </div>
            <div class="mainwp-side-content mainwp-no-padding">
                <?php do_action( 'mainwp_manage_plugins_sidebar_top' ); ?>
                <div class="mainwp-search-options ui accordion mainwp-sidebar-accordion">
                    <?php do_action( 'mainwp_manage_plugins_before_search_options' ); ?>
                    <div class="title active"><i class="dropdown icon"></i> <?php esc_html_e( 'Plugin Status to Search', 'mainwp' ); ?></div>
                    <div class="content active">
                    <div class="ui mini form">
                        <div class="field">
                            <select class="ui fluid dropdown" id="mainwp_au_plugin_status">
                                <option value="all" <?php echo ( null !== $cachedAUSearch && 'all' === $cachedAUSearch['plugin_status'] ) ? 'selected' : ''; ?>><?php esc_html_e( 'Active and Inactive', 'mainwp' ); ?></option>
                                <option value="active" <?php echo ( null !== $cachedAUSearch && 'active' === $cachedAUSearch['plugin_status'] ) ? 'selected' : ''; ?>><?php esc_html_e( 'Active', 'mainwp' ); ?></option>
                                <option value="inactive" <?php echo ( null !== $cachedAUSearch && 'inactive' === $cachedAUSearch['plugin_status'] ) ? 'selected' : ''; ?>><?php esc_html_e( 'Inactive', 'mainwp' ); ?></option>
                            </select>
                        </div>
                    </div>
                    </div>
                    <?php do_action( 'mainwp_manage_plugins_after_search_options' ); ?>
                </div>
                <div class="ui fitted divider"></div>
                <div class="mainwp-search-options ui accordion mainwp-sidebar-accordion">
                    <div class="title active"><i class="dropdown icon"></i> <?php esc_html_e( 'Search Options', 'mainwp' ); ?></div>
                    <div class="content active">
                    <div class="ui mini form">
                        <div class="field">
                            <select class="ui fluid dropdown" id="mainwp_au_plugin_trust_status">
                                <option value="all" <?php echo ( null !== $cachedAUSearch && 'all' === $cachedAUSearch['status'] ) ? 'selected' : ''; ?>><?php esc_html_e( 'Trusted, Not trusted and Ignored', 'mainwp' ); ?></option>
                                <option value="trust" <?php echo ( null !== $cachedAUSearch && 'trust' === $cachedAUSearch['status'] ) ? 'selected' : ''; ?>><?php esc_html_e( 'Trusted', 'mainwp' ); ?></option>
                                <option value="untrust" <?php echo ( null !== $cachedAUSearch && 'untrust' === $cachedAUSearch['status'] ) ? 'selected' : ''; ?>><?php esc_html_e( 'Not trusted', 'mainwp' ); ?></option>
                                <option value="ignored" <?php echo ( null !== $cachedAUSearch && 'ignored' === $cachedAUSearch['status'] ) ? 'selected' : ''; ?>><?php esc_html_e( 'Ignored', 'mainwp' ); ?></option>
                            </select>
                        </div>
                        <div class="field">
                            <div class="ui input fluid">
                                <input type="text" placeholder="<?php esc_attr_e( 'Plugin name', 'mainwp' ); ?>" id="mainwp_au_plugin_keyword" class="text" value="<?php echo ( null !== $cachedAUSearch ) ? esc_attr( $cachedAUSearch['keyword'] ) : ''; ?>">
                            </div>
                        </div>
                    </div>
                </div>
                </div>
                <div class="ui fitted divider"></div>
                <div class="mainwp-search-submit">
                    <?php do_action( 'mainwp_manage_plugins_before_submit_button' ); ?>
                    <a href="#" class="ui green big fluid button" id="mainwp_show_all_active_plugins"><?php esc_html_e( 'Show Plugins', 'mainwp' ); ?></a>
                    <?php do_action( 'mainwp_manage_plugins_after_submit_button' ); ?>
                </div>
                <?php do_action( 'mainwp_manage_plugins_sidebar_bottom' ); ?>
            </div>
        </div>
            <?php
            MainWP_UI::render_modal_edit_notes( 'plugin' );
        }
        static::render_footer( 'AutoUpdate' );
    }

    /**
     * Method render_all_active_table()
     *
     * Render all active Plugins table.
     *
     * @param null $output function output.
     *
     * @return void
     *
     * @uses \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
     * @uses \MainWP\Dashboard\MainWP_DB_Common::get_user_extension()
     * @uses \MainWP\Dashboard\MainWP_DB::query()
     * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_for_current_user()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_DB::free_result()
     * @uses \MainWP\Dashboard\MainWP_Plugins_Handler::get_class_name()
     * @uses \MainWP\Dashboard\MainWP_Utility::map_site()
     * @uses \MainWP\Dashboard\MainWP_Utility::get_nice_url()
     */
    public static function render_all_active_table( $output = null ) { // phpcs:ignore -- NOSONAR - complex.
        $keyword       = null;
        $search_status = 'all';

        $data_fields = MainWP_System_Utility::get_default_map_site_fields();

        if ( null === $output ) {
            // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $keyword              = isset( $_POST['keyword'] ) && ! empty( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : null;
            $search_status        = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : 'all';
            $search_plugin_status = isset( $_POST['plugin_status'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin_status'] ) ) : 'all';
            // phpcs:enable

            $output                    = new \stdClass();
            $output->errors            = array();
            $output->plugins           = array();
            $output->plugins_installed = array(); // to fix.

            if ( 1 === (int) get_option( 'mainwp_optimize', 1 ) || MainWP_Demo_Handle::is_demo_mode() ) {
                $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );
                while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
                    $allPlugins = json_decode( $website->plugins, true );
                    $_count     = count( $allPlugins );
                    for ( $i = 0; $i < $_count; $i++ ) {
                        $plugin = $allPlugins[ $i ];

                        $all = true;
                        if ( 'all' !== $search_plugin_status ) {
                            $all = false;
                        }

                        if ( ! $all && ( ( 1 === (int) $plugin['active'] && 'active' !== $search_plugin_status ) || ( 1 !== (int) $plugin['active'] && 'inactive' !== $search_plugin_status ) ) ) {
                            continue;
                        }

                        if ( ! empty( $keyword ) ) {
                            $keyword   = trim( $keyword );
                            $multi_kws = explode( ',', $keyword );
                            $multi_kws = array_filter( array_map( 'trim', $multi_kws ) );
                            if ( ! MainWP_Utility::multi_find_keywords( $plugin['name'], $multi_kws ) ) {
                                continue;
                            }
                        }
                        $plugin['websiteid']  = $website->id;
                        $plugin['websiteurl'] = $website->url;
                        $output->plugins[]    = $plugin;
                    }
                }
                MainWP_DB::free_result( $websites );
            } else {
                $dbwebsites = array();
                $websites   = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );
                while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
                    if ( '' !== $website->sync_errors || MainWP_System_Utility::is_suspended_site( $website ) ) {
                        continue;
                    }

                    $dbwebsites[ $website->id ] = MainWP_Utility::map_site(
                        $website,
                        $data_fields
                    );
                }
                MainWP_DB::free_result( $websites );

                $post_data = array( 'keyword' => $keyword );

                if ( 'active' === $search_plugin_status || 'inactive' === $search_plugin_status ) {
                    $post_data['status'] = $search_plugin_status;
                    $post_data['filter'] = true;
                } else {
                    $post_data['status'] = '';
                    $post_data['filter'] = false;
                }
                $output->status = $search_plugin_status;
                MainWP_Connect::fetch_urls_authed( $dbwebsites, 'get_all_plugins', $post_data, array( MainWP_Plugins_Handler::get_class_name(), 'plugins_search_handler' ), $output );
                // phpcs:disable WordPress.Security.EscapeOutput
                if ( ! empty( $output->errors ) ) {
                    foreach ( $output->errors as $siteid => $error ) {
                        echo MainWP_Utility::get_nice_url( $dbwebsites[ $siteid ]->url ) . ' - ' . $error . ' <br/>';

                    }
                    echo '<div class="ui hidden divider"></div>';

                    if ( count( $output->errors ) === count( $dbwebsites ) ) {
                        $_SESSION['MainWP_PluginsActive']       = $output;
                        $_SESSION['MainWP_PluginsActiveStatus'] = array(
                            'keyword'       => $keyword,
                            'status'        => $search_status,
                            'plugin_status' => $search_plugin_status,
                        );
                        return;
                    }
                }
                // phpcs:enable
            }

            $_SESSION['MainWP_PluginsActive']       = $output;
            $_SESSION['MainWP_PluginsActiveStatus'] = array(
                'keyword'       => $keyword,
                'status'        => $search_status,
                'plugin_status' => $search_plugin_status,
            );
        } elseif ( isset( $_SESSION['MainWP_PluginsActiveStatus'] ) ) {
                //phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                $keyword              = isset( $_SESSION['MainWP_PluginsActiveStatus']['keyword'] ) ? $_SESSION['MainWP_PluginsActiveStatus']['keyword'] : null;
                $search_status        = isset( $_SESSION['MainWP_PluginsActiveStatus']['status'] ) ? $_SESSION['MainWP_PluginsActiveStatus']['status'] : null;
                $search_plugin_status = isset( $_SESSION['MainWP_PluginsActiveStatus']['plugin_status'] ) ? $_SESSION['MainWP_PluginsActiveStatus']['plugin_status'] : null;
                //phpcs:enable
        }

        if ( 'inactive' !== $search_plugin_status && ( empty( $keyword ) || ( ! empty( $keyword ) && false !== stristr( 'MainWP Child', $keyword ) ) ) ) {
            $output->plugins[] = array(
                'slug'   => 'mainwp-child/mainwp-child.php',
                'name'   => 'MainWP Child',
                'active' => 1,
            );
        }

        if ( empty( $output->plugins ) ) {
            ?>
            <div class="ui message yellow"><?php esc_html_e( 'No plugins found.', 'mainwp' ); ?></div>
            <?php
            return;
        }

        $plugins = array();
        foreach ( $output->plugins as $plugin ) {
            $plugins[ $plugin['slug'] ] = $plugin;
        }
        asort( $plugins );

        $userExtension         = MainWP_DB_Common::instance()->get_user_extension();
        $decodedIgnoredPlugins = json_decode( $userExtension->ignored_plugins, true );
        $trustedPlugins        = json_decode( $userExtension->trusted_plugins, true );

        if ( ! is_array( $trustedPlugins ) ) {
            $trustedPlugins = array();
        }
        $trustedPluginsNotes = json_decode( $userExtension->trusted_plugins_notes, true );
        if ( ! is_array( $trustedPluginsNotes ) ) {
            $trustedPluginsNotes = array();
        }
        static::render_all_active_html( $plugins, $trustedPlugins, $search_status, $decodedIgnoredPlugins, $trustedPluginsNotes );
        MainWP_UI::render_modal_upload_icon();
    }


    /**
     * Method render_all_active_html()
     *
     * Render all active plugins html.
     *
     * @param array $plugins Plugins array.
     * @param array $trustedPlugins Trusted plugins array.
     * @param mixed $search_status trust|untrust|ignored.
     * @param array $decodedIgnoredPlugins Decoded ignored plugins array.
     * @param array $trustedPluginsNotes Trusted plugins notes.
     *
     * @uses \MainWP\Dashboard\MainWP_Utility::esc_content()
     */
    public static function render_all_active_html( $plugins, $trustedPlugins, $search_status, $decodedIgnoredPlugins, $trustedPluginsNotes ) { // phpcs:ignore -- NOSONAR - complex.

        /**
         * Action: mainwp_plugins_before_auto_updates_table
         *
         * Fires before the Auto Update Plugins table.
         *
         * @since 4.1
         */
        do_action( 'mainwp_plugins_before_auto_updates_table' );
        ?>
        <table class="ui unstackable table" id="mainwp-all-active-plugins-table">
            <thead>
                <tr>
                    <th scope="col" class="no-sort check-column collapsing"><span class="ui checkbox"><input id="cb-select-all-top" type="checkbox" /></span></th>
                    <th scope="col" class="no-sort"></th>
                    <th scope="col" data-priority="1"><?php esc_html_e( 'Plugin', 'mainwp' ); ?></th>
                    <th scope="col" ><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
                    <th scope="col" class="collapsing" data-priority="2"><?php esc_html_e( 'Trust Status', 'mainwp' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Ignored Status', 'mainwp' ); ?></th>
                    <th scope="col" class="collapsing"></th>
                    <th scope="col" class="collapsing"><?php esc_html_e( 'Notes', 'mainwp' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $plugins as $slug => $plugin ) : ?>
                    <?php
                    $name = $plugin['name'];
                    $wpid = isset( $plugin['websiteid'] ) ? intval( $plugin['websiteid'] ) : 0;

                    if ( ! empty( $search_status ) && 'all' !== $search_status ) {
                        if ( 'trust' === $search_status && ! in_array( $slug, $trustedPlugins ) ) {
                            continue;
                        }

                        if ( 'untrust' === $search_status && in_array( $slug, $trustedPlugins ) ) {
                            continue;
                        }

                        if ( 'ignored' === $search_status && ! MainWP_Common_Functions::instance()->is_ignored_updates( $plugin, $decodedIgnoredPlugins, 'plugin' ) ) {
                            continue;
                        }
                    }
                    $esc_note   = '';
                    $strip_note = '';
                    if ( isset( $trustedPluginsNotes[ $slug ] ) ) {
                        $esc_note   = MainWP_Utility::esc_content( $trustedPluginsNotes[ $slug ] );
                        $strip_note = wp_strip_all_tags( $esc_note );
                    }

                    $plugin_directory = dirname( $slug );
                    ?>
                    <?php // phpcs:disable WordPress.Security.EscapeOutput ?>
                    <tr plugin-slug="<?php echo esc_attr( rawurlencode( $slug ) ); ?>" plugin-name="<?php echo esc_html( wp_strip_all_tags( $name ) ); ?>">
                        <td class="check-column"><span class="ui checkbox"><input type="checkbox" name="plugin[]" value="<?php echo esc_attr( rawurlencode( $slug ) ); ?>"></span></td>
                        <td class="collapsing"><?php echo MainWP_System_Utility::get_plugin_icon( $plugin_directory ); ?></td>
                        <td><a href="<?php echo esc_url( admin_url() ) . 'plugin-install.php?tab=plugin-information&wpplugin=' . intval( $wpid ) . '&plugin=' . rawurlencode( dirname( $slug ) ); ?>" target="_blank" class="open-plugin-details-modal"><?php echo esc_html( $name ); ?></a></td>
                        <td><?php echo ( 1 === (int) $plugin['active'] ) ? esc_html__( 'Active', 'mainwp' ) : esc_html__( 'Inactive', 'mainwp' ); //phpcs:ignore -- escaped. ?></td>
                        <td><?php echo ( in_array( $slug, $trustedPlugins ) ) ? '<span class="ui mini green fluid center aligned label">' . esc_html__( 'Trusted', 'mainwp' ) . '</span>' : '<span class="ui mini red fluid center aligned label">' . esc_html__( 'Not Trusted', 'mainwp' ) . '</span>'; ?></td>
                        <td><?php echo MainWP_Common_Functions::instance()->is_ignored_updates( $plugin, $decodedIgnoredPlugins, 'plugin' ) ? '<span class="ui mini label">' . esc_html__( 'Ignored', 'mainwp' ) . '</span>' : ''; ?></td>
                        <td><?php echo MainWP_Common_Functions::instance()->is_ignored_updates( $plugin, $decodedIgnoredPlugins, 'plugin' ) ? '<span data-tooltip="Ignored plugins will not be automatically updated." data-inverted=""><i class="info red circle icon" ></i></span>' : ''; ?></td>
                        <td class="collapsing center aligned">
                        <?php if ( '' === $esc_note ) : ?>
                            <a href="javascript:void(0)" class="mainwp-edit-plugin-note" ><i class="sticky note outline icon"></i></a>
                        <?php else : ?>
                            <a href="javascript:void(0)" class="mainwp-edit-plugin-note" data-tooltip="<?php echo substr( $strip_note, 0, 100 ); //phpcs:ignore -- escaped. ?>" data-position="left center" data-inverted=""><i class="sticky green note icon"></i></a>
                        <?php endif; ?>
                            <span style="display: none" class="esc-content-note"><?php echo $esc_note; //phpcs:ignore -- escaped. ?></span>
                        </td>
                    </tr>
                    <?php // phpcs:enable ?>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th scope="col" class="no-sort check-column"><span class="ui checkbox"><input id="cb-select-all-bottom" type="checkbox" /></span></th>
                    <th scope="col" ></th>
                    <th scope="col" ><?php esc_html_e( 'Plugin', 'mainwp' ); ?></th>
                    <th scope="col" ><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
                    <th scope="col" ><?php esc_html_e( 'Trust Status', 'mainwp' ); ?></th>
                    <th scope="col" ><?php esc_html_e( 'Ignored Status', 'mainwp' ); ?></th>
                    <th scope="col" class="collapsing"></th>
                    <th scope="col" ><?php esc_html_e( 'Notes', 'mainwp' ); ?></th>
                </tr>
            </tfoot>
        </table>
        <?php
        /**
         * Action: mainwp_plugins_after_auto_updates_table
         *
         * Fires after the Auto Update Plugins table.
         *
         * @since 4.1
         */
        do_action( 'mainwp_plugins_after_auto_updates_table' );

        $table_features = array(
            'searching'  => 'true',
            'stateSave'  => 'true',
            'colReorder' => 'true',
            'info'       => 'true',
            'paging'     => 'false',
            'ordering'   => 'true',
            'order'      => '[ [ 2, "asc" ] ]',
            'responsive' => 'true',
        );

        /**
         * Filter: mainwp_plugin_auto_updates_table_fatures
         *
         * Filters the Plugin Auto Updates table features.
         *
         * @since 4.1
         */
        $table_features = apply_filters( 'mainwp_plugin_auto_updates_table_fatures', $table_features );
        ?>
        <script type="text/javascript">
        jQuery( document ).ready( function() {
            let responsive = <?php echo esc_html( $table_features['responsive'] ); ?>;
            if( jQuery( window ).width() > 1140 ) {
                responsive = false;
            }
            jQuery( '#mainwp-all-active-plugins-table' ).DataTable( {
                "searching" : <?php echo esc_html( $table_features['searching'] ); ?>,
                "stateSave" : <?php echo esc_html( $table_features['stateSave'] ); ?>,
                "colReorder" : <?php echo $table_features['colReorder']; // phpcs:ignore -- specical chars. ?>,
                "info" : <?php echo esc_html( $table_features['info'] ); ?>,
                "paging" : <?php echo esc_html( $table_features['paging'] ); ?>,
                "ordering" : <?php echo esc_html( $table_features['ordering'] ); ?>,
                "order" : <?php echo $table_features['order']; // phpcs:ignore -- specical chars. ?>,
                "columnDefs": [ { "orderable": false, "targets": [ 0, 1, 6 ] } ],
                "responsive": responsive,
            } );
        } );
        </script>

        <script type="text/javascript">
            jQuery( document ).ready( function () {
                jQuery( '.mainwp-ui-page .ui.checkbox:not(.not-auto-init)' ).checkbox();
            } );
        </script>
        <?php
    }

    /**
     * Render Ignore Subpage.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Common::get_user_extension()
     * @uses \MainWP\Dashboard\MainWP_DB::query()
     * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_for_current_user()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     */
    public static function render_ignore() {
        $websites              = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );
        $userExtension         = MainWP_DB_Common::instance()->get_user_extension();
        $decodedIgnoredPlugins = json_decode( $userExtension->ignored_plugins, true );
        $ignoredPlugins        = is_array( $decodedIgnoredPlugins ) && ( ! empty( $decodedIgnoredPlugins ) );

        $cnt = 0;

        while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
            if ( $website->is_ignorePluginUpdates ) {
                continue;
            }

            $tmpDecodedIgnoredPlugins = json_decode( $website->ignored_plugins, true );

            if ( ! is_array( $tmpDecodedIgnoredPlugins ) || empty( $tmpDecodedIgnoredPlugins ) ) {
                continue;
            }
            ++$cnt;
        }

        static::render_header( 'Ignore' );
        ?>
        <div id="mainwp-ignored-plugins" class="ui segment">
            <?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-ignored-plugins-info-message' ) ) : ?>
                <div class="ui info message">
                    <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-ignored-plugins-info-message"></i>
                    <?php printf( esc_html__( 'Manage plugins you have told your MainWP Dashboard to ignore updates on global or per site level.  For additional help, please check this %1$shelp documentation%2$s.', 'mainwp' ), '<a href="https://mainwp.com/kb/ignore-plugin-updates/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); ?>
                </div>
            <?php endif; ?>
            <?php
            /**
             * Action: mainwp_plugins_before_ignored_updates
             *
             * Fires on the top of the Ignored Plugin Updates page.
             *
             * @since 4.1
             */
            do_action( 'mainwp_plugins_before_ignored_updates', $ignoredPlugins, $websites );
            ?>
            <h3 class="ui header">
                <?php esc_html_e( 'Globally Ignored Plugins', 'mainwp' ); ?>
                <div class="sub header"><?php esc_html_e( 'These are plugins you have told your MainWP Dashboard to ignore updates on global level and not notify you about pending updates.', 'mainwp' ); ?></div>
            </h3>
            <?php static::render_global_ignored( $ignoredPlugins, $decodedIgnoredPlugins ); ?>
            <div class="ui hidden divider"></div>
            <h3 class="ui header">
                <?php esc_html_e( 'Per Site Ignored Plugins' ); ?>
                <div class="sub header"><?php esc_html_e( 'These are plugins you have told your MainWP Dashboard to ignore updates per site level and not notify you about pending updates.', 'mainwp' ); ?></div>
            </h3>
            <?php static::render_sites_ignored( $cnt, $websites ); ?>
            <?php
            /**
             * Action: mainwp_plugins_after_ignored_updates
             *
             * Fires on the bottom of the Ignored Plugin Updates page.
             *
             * @since 4.1
             */
            do_action( 'mainwp_plugins_after_ignored_updates', $ignoredPlugins, $websites );
            ?>
        </div>
        <?php
        MainWP_Updates::render_plugin_details_modal();
        static::render_footer( 'Ignore' );
    }

    /**
     * Method render_abandoned_plugins()
     *
     * Render abandoned plugins list.
     */
    public static function render_abandoned_plugins() {
        MainWP_Updates::render( 'abandoned_plugins' );
    }

    /**
     * Method render_global_ignored()
     *
     * Render Global Ignored plugins list.
     *
     * @param bool  $ignoredPlugins Ignored plugins array.
     * @param array $decodedIgnoredPlugins Decoded ignored plugins array.
     */
    public static function render_global_ignored( $ignoredPlugins, $decodedIgnoredPlugins ) { //phpcs:ignore -- NOSONAR - complex.
        ?>
        <table id="mainwp-globally-ignored-plugins" class="ui compact selectable table unstackable">
                <thead>
                    <tr>
                        <th scope="col" class="no-sort"></th>
                        <th scope="col" ><?php esc_html_e( 'Plugin', 'mainwp' ); ?></th>
                        <th scope="col" ><?php esc_html_e( 'Plugin slug', 'mainwp' ); ?></th>
                        <th scope="col" ><?php esc_html_e( 'Ignored version', 'mainwp' ); ?></th>
                        <th scope="col" ></th>
                    </tr>
                </thead>
                <tbody id="globally-ignored-plugins-list">
                    <?php if ( $ignoredPlugins ) : ?>
                        <?php // phpcs:disable WordPress.Security.EscapeOutput ?>
                        <?php
                        foreach ( $decodedIgnoredPlugins as $ignoredPlugin => $ignoredPluginName ) :

                            $ignore_name  = 'N/A';
                            $ignored_vers = array( 'all_versions' );
                            if ( is_string( $ignoredPluginName ) ) {
                                $ignore_name = $ignoredPluginName;
                            } elseif ( is_array( $ignoredPluginName ) ) {
                                $ignore_name = ! empty( $ignoredPluginName['Name'] ) ? $ignoredPluginName['Name'] : $ignore_name;
                                $ig_vers     = ! empty( $ignoredPluginName['ignored_versions'] ) ? $ignoredPluginName['ignored_versions'] : '';
                                if ( ! empty( $ig_vers ) && is_array( $ig_vers ) && ! in_array( 'all_versions', $ig_vers ) ) {
                                    $ignored_vers = $ig_vers;
                                }
                            }
                            ?>
                            <?php $plugin_directory = dirname( $ignoredPlugin ); ?>
                            <?php foreach ( $ignored_vers as $ignored_ver ) { ?>
                                <tr plugin-slug="<?php echo esc_attr( rawurlencode( $ignoredPlugin ) ); ?>">
                                    <td class="collapsing"><?php echo MainWP_System_Utility::get_plugin_icon( $plugin_directory ); ?></td>
                                    <td><a href="<?php echo esc_url( admin_url() ) . 'plugin-install.php?tab=plugin-information&plugin=' . esc_html( rawurlencode( dirname( $ignoredPlugin ) ) ); ?>" target="_blank" class="open-plugin-details-modal"><?php echo esc_html( $ignore_name ); ?></a></td>
                                    <td><?php echo esc_html( $ignoredPlugin ); ?></td>
                                    <td><?php echo 'all_versions' === $ignored_ver ? esc_html__( 'All', 'mainwp' ) : esc_html( $ignored_ver ); ?></td>
                                    <td class="right aligned">
                                        <?php if ( \mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
                                            <a href="#" class="ui mini button" onClick="return updatesoverview_plugins_unignore_globally( '<?php echo esc_html( rawurlencode( $ignoredPlugin ) ); ?>', '<?php echo esc_js( rawurlencode( $ignored_ver ) ); ?>' )"><?php esc_html_e( 'Unignore', 'mainwp' ); ?></a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php } ?>
                        <?php endforeach; ?>
                        <?php // phpcs:enable ?>
                    <?php endif; ?>
                </tbody>
                <?php if ( \mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
                    <?php if ( $ignoredPlugins ) : ?>
                        <tfoot class="full-width">
                            <tr>
                                <th scope="col" colspan="5">
                                    <a class="ui right floated small green labeled icon button" onClick="return updatesoverview_plugins_unignore_globally_all();" id="mainwp-unignore-globally-all">
                                        <i class="check icon"></i> <?php esc_html_e( 'Unignore All', 'mainwp' ); ?>
                                    </a>
                                </th>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                <?php endif; ?>
            </table>
            <script type="text/javascript">
            jQuery( document ).ready( function() {
                jQuery( '#mainwp-globally-ignored-plugins' ).DataTable( {
                    "searching": false,
                    "paging": false,
                    "info": false,
                    "responsive": true,
                    "columnDefs": [ {
                        "targets": 'no-sort',
                        "orderable": false
                    } ],
                    "language": {
                        "emptyTable": "<?php esc_html_e( 'No ignored plugins.', 'mainwp' ); ?>"
                    }
                } );
            } );
            </script>
        <?php
    }

    /**
     * Render Per Site Ignored table.
     *
     * @param mixed $cnt Plugin count.
     * @param mixed $websites Child Sites.
     *
     * @return void
     *
     * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_DB::free_result()
     */
    public static function render_sites_ignored( $cnt, $websites ) { // phpcs:ignore -- NOSONAR - complex.
        ?>
        <table id="mainwp-per-site-ignored-plugins" class="ui unstackable compact selectable table ">
            <thead>
                <tr>
                    <th scope="col" ><?php esc_html_e( 'Site', 'mainwp' ); ?></th>
                    <th scope="col" class="no-sort"></th>
                    <th scope="col" ><?php esc_html_e( 'Plugin', 'mainwp' ); ?></th>
                    <th scope="col" ><?php esc_html_e( 'Plugin slug', 'mainwp' ); ?></th>
                    <th scope="col" ><?php esc_html_e( 'Ignored version', 'mainwp' ); ?></th>
                    <th scope="col" ></th>
                </tr>
            </thead>
            <tbody id="ignored-plugins-list">
                <?php if ( 0 < $cnt ) : ?>
                    <?php
                    MainWP_DB::data_seek( $websites, 0 );

                    while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
                        if ( $website->is_ignorePluginUpdates ) {
                            continue;
                        }

                        $decodedIgnoredPlugins = json_decode( $website->ignored_plugins, true );
                        if ( ! is_array( $decodedIgnoredPlugins ) || empty( $decodedIgnoredPlugins ) ) {
                            continue;
                        }
                        $first = true;
                         // phpcs:disable WordPress.Security.EscapeOutput
                        foreach ( $decodedIgnoredPlugins as $ignoredPlugin => $ignoredPluginName ) {

                            $ignore_name  = 'N/A';
                            $ignored_vers = array( 'all_versions' );
                            if ( is_string( $ignoredPluginName ) ) {
                                $ignore_name = $ignoredPluginName;
                            } elseif ( is_array( $ignoredPluginName ) ) {
                                $ignore_name = ! empty( $ignoredPluginName['Name'] ) ? $ignoredPluginName['Name'] : $ignore_name;
                                $ig_vers     = ! empty( $ignoredPluginName['ignored_versions'] ) ? $ignoredPluginName['ignored_versions'] : '';
                                if ( ! empty( $ig_vers ) && is_array( $ig_vers ) && ! in_array( 'all_versions', $ig_vers ) ) {
                                    $ignored_vers = $ig_vers;
                                }
                            }
                            $plugin_directory = MainWP_Utility::get_dir_slug( rawurldecode( $ignoredPlugin ) );
                            foreach ( $ignored_vers as $ignored_ver ) {
                                ?>
                                <tr site-id="<?php echo intval( $website->id ); ?>" plugin-slug="<?php echo esc_attr( rawurlencode( $ignoredPlugin ) ); ?>">
                                <?php if ( $first ) : ?>
                                    <td><div><a href="<?php echo esc_url( admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ) ); ?>"><?php echo esc_html( stripslashes( $website->name ) ); ?></a></div></td>
                                    <?php $first = false; ?>
                                <?php else : ?>
                                    <td><div style="display:none;"><a href="<?php echo esc_url( admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ) ); ?>"><?php echo esc_html( stripslashes( $website->name ) ); ?></a></div></td>
                                <?php endif; ?>
                                    <td class="collapsing"><?php echo MainWP_System_Utility::get_plugin_icon( $plugin_directory ); ?></td>
                                <td><a href="<?php echo esc_url( admin_url() ) . 'plugin-install.php?tab=plugin-information&wpplugin=' . intval( $website->id ) . '&plugin=' . esc_html( rawurlencode( $plugin_directory ) ); ?>" target="_blank" class="open-plugin-details-modal"><?php echo esc_html( $ignore_name ); ?></a></td>
                                <td><?php echo esc_html( rawurldecode( $ignoredPlugin ) ); ?></td>
                                <td><?php echo 'all_versions' === $ignored_ver ? esc_html__( 'All', 'mainwp' ) : esc_html( $ignored_ver ); ?></td>
                                <?php if ( \mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
                                    <td class="right aligned"><a href="#" class="ui mini button" onClick="return updatesoverview_plugins_unignore_detail( '<?php echo esc_js( rawurlencode( $ignoredPlugin ) ); ?>', <?php echo intval( $website->id ); ?>, '<?php echo esc_js( rawurlencode( $ignored_ver ) ); ?>' )"> <?php esc_html_e( 'Unignore', 'mainwp' ); ?></a></td>
                                <?php endif; ?>
                            <?php } ?>
                        </tr>
                            <?php
                        }
                        // phpcs:enable
                    }

                    MainWP_DB::free_result( $websites );
                    ?>
                <?php endif; ?>
            </tbody>
            <?php if ( \mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
                <?php if ( 0 < $cnt ) : ?>
                    <tfoot class="full-width">
                        <tr>
                            <th scope="col" colspan="6">
                                <a class="ui right floated small green labeled icon button" onClick="return updatesoverview_plugins_unignore_detail_all();" id="mainwp-unignore-detail-all">
                                    <i class="check icon"></i> <?php esc_html_e( 'Unignore All', 'mainwp' ); ?>
                                </a>
                            </th>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            <?php endif; ?>
        </table>
        <script type="text/javascript">
        jQuery( document ).ready( function() {
            jQuery( '#mainwp-per-site-ignored-plugins' ).DataTable( {
                "responsive": true,
                "searching": false,
                "paging": false,
                "info": false,
                "columnDefs": [ {
                    "targets": 'no-sort',
                    "orderable": false
                } ],
                "language": {
                    "emptyTable": "<?php esc_html_e( 'No ignored plugins', 'mainwp' ); ?>"
                }
            } );
        } );
        </script>
        <?php
    }

    /**
     * Render Ignored Abandoned Page.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Common::get_user_extension()
     * @uses \MainWP\Dashboard\MainWP_DB::query()
     * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_for_current_user()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     */
    public static function render_ignored_abandoned() {
        $websites              = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );
        $userExtension         = MainWP_DB_Common::instance()->get_user_extension();
        $decodedIgnoredPlugins = json_decode( $userExtension->dismissed_plugins, true );
        $ignoredPlugins        = is_array( $decodedIgnoredPlugins ) && ( ! empty( $decodedIgnoredPlugins ) );
        $cnt                   = 0;
        while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
            $tmpDecodedDismissedPlugins = MainWP_DB::instance()->get_website_option( $website, 'plugins_outdate_dismissed' );
            $tmpDecodedDismissedPlugins = ! empty( $tmpDecodedDismissedPlugins ) ? json_decode( $tmpDecodedDismissedPlugins, true ) : array();

            if ( ! is_array( $tmpDecodedDismissedPlugins ) || empty( $tmpDecodedDismissedPlugins ) ) {
                continue;
            }
            ++$cnt;
        }

        static::render_header( 'IgnoreAbandoned' );
        ?>
        <div id="mainwp-ignored-abandoned-plugins" class="ui segment">
            <?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-ignored-abandoned-plugins-info-message' ) ) : ?>
                <div class="ui info message">
                    <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-ignored-abandoned-plugins-info-message"></i>
                    <?php printf( esc_html__( 'Manage abandoned plugins you have told your MainWP Dashboard to ignore on global or per site level.  For additional help, please check this %1$shelp documentation%2$s.', 'mainwp' ), '<a href="https://mainwp.com/kb/abandoned-plugins/" target="_blank">', '</a> <i class="external alternate icon"></i> ' ); ?>
                </div>
            <?php endif; ?>
            <?php
            /**
             * Action: mainwp_plugins_before_ignored_abandoned
             *
             * Fires on the top of the Ignored Plugins Abandoned page.
             *
             * @since 4.1
             */
            do_action( 'mainwp_plugins_before_ignored_abandoned', $ignoredPlugins, $websites );
            ?>
            <h3 class="ui header">
                <?php esc_html_e( 'Globally Ignored Abandoned Plugins' ); ?>
                <div class="sub header"><?php esc_html_e( 'These are plugins you have told your MainWP Dashboard to ignore on global level even though they have passed your Abandoned Plugin Tolerance date', 'mainwp' ); ?></div>
            </h3>
            <?php static::render_global_ignored_abandoned( $ignoredPlugins, $decodedIgnoredPlugins ); ?>
            <div class="ui hidden divider"></div>
            <h3 class="ui header">
                <?php esc_html_e( 'Per Site Ignored Abandoned Plugins' ); ?>
                <div class="sub header"><?php esc_html_e( 'These are plugins you have told your MainWP Dashboard to ignore per site level even though they have passed your Abandoned Plugin Tolerance date', 'mainwp' ); ?></div>
            </h3>
            <?php static::render_sites_ignored_abandoned( $cnt, $websites ); ?>
            <?php
            /**
             * Action: mainwp_plugins_after_ignored_abandoned
             *
             * Fires on the bottom of the Ignored Plugins Abandoned page.
             *
             * @since 4.1
             */
            do_action( 'mainwp_plugins_after_ignored_abandoned', $ignoredPlugins, $websites );
            ?>
        </div>
        <?php
        static::render_footer( 'IgnoreAbandoned' );
    }

    /**
     * Method render_global_ignored_abandoned()
     *
     * Render Global Ignored Abandoned table.
     *
     * @param array $ignoredPlugins Ignored plugins array.
     * @param array $decodedIgnoredPlugins Decoded dgnored plugins array.
     */
    public static function render_global_ignored_abandoned( $ignoredPlugins, $decodedIgnoredPlugins ) {
        ?>
        <table id="mainwp-globally-ignored-abandoned-plugins" class="ui compact selectable table unstackable">
            <thead>
                <tr>
                    <th scope="col" class="no-sort"><?php esc_html_e( ' ', 'mainwp' ); ?></th>
                    <th scope="col" ><?php esc_html_e( 'Plugin', 'mainwp' ); ?></th>
                    <th scope="col" ><?php esc_html_e( 'Plugin slug', 'mainwp' ); ?></th>
                    <th scope="col" ></th>
                </tr>
            </thead>
            <tbody id="ignored-globally-abandoned-plugins-list">
                <?php if ( $ignoredPlugins ) : ?>
                    <?php
                    // phpcs:disable WordPress.Security.EscapeOutput
                    foreach ( $decodedIgnoredPlugins as $ignoredPlugin => $ignoredPluginName ) :
                        $ignore_name = 'N/A';
                        if ( is_string( $ignoredPluginName ) ) {
                            $ignore_name = $ignoredPluginName;
                        } elseif ( is_array( $ignoredPluginName ) && ! empty( $ignoredPluginName['Name'] ) ) {
                            $ignore_name = $ignoredPluginName['Name'];
                        }

                        $plugin_directory = MainWP_Utility::get_dir_slug( rawurldecode( $ignoredPlugin ) );
                        ?>
                        <tr plugin-slug="<?php echo esc_attr( rawurlencode( $ignoredPlugin ) ); ?>">
                            <td class="collapsing"><?php echo MainWP_System_Utility::get_plugin_icon( $plugin_directory ); ?></td>
                            <td><a href="<?php echo esc_url( admin_url() ) . 'plugin-install.php?tab=plugin-information&plugin=' . esc_html( rawurlencode( $plugin_directory ) ); ?>" target="_blank" class="open-plugin-details-modal"><?php echo esc_html( $ignore_name ); ?></a></td>
                            <td><?php echo esc_html( $ignoredPlugin ); ?></td>
                            <td class="right aligned">
                                <?php if ( \mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
                                    <a href="#" class="ui mini button" onClick="return updatesoverview_plugins_abandoned_unignore_globally( '<?php echo esc_html( rawurlencode( $ignoredPlugin ) ); ?>' )"><?php esc_html_e( 'Unignore', 'mainwp' ); ?></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php // phpcs:enable ?>
                <?php endif; ?>
            </tbody>
            <?php if ( \mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
                <?php if ( $ignoredPlugins ) : ?>
                    <tfoot class="full-width">
                        <tr>
                            <th scope="col" colspan="4">
                                <a class="ui right floated small green labeled icon button" onClick="return updatesoverview_plugins_abandoned_unignore_globally_all();" id="mainwp-unignore-globally-all">
                                    <i class="check icon"></i> <?php esc_html_e( 'Unignore All', 'mainwp' ); ?>
                                </a>
                            </th>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            <?php endif; ?>
        </table>
        <script type="text/javascript">
        jQuery( document ).ready( function() {
            jQuery( '#mainwp-globally-ignored-abandoned-plugins' ).DataTable( {
                "responsive": true,
                "searching": false,
                "paging": false,
                "info": false,
                "columnDefs": [ {
                    "targets": 'no-sort',
                    "orderable": false
                } ],
                "language": {
                    "emptyTable": "<?php esc_html_e( 'No ignored abandoned plugins.', 'mainwp' ); ?>"
                }
            } );
        } );
        </script>
        <?php
    }

    /**
     * Method render_sites_ignored_abandoned()
     *
     * Render Per Site Ignored Abandoned Table.
     *
     * @param int    $cnt Plugins count.
     * @param object $websites The websites object.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_DB::free_result()
     */
    public static function render_sites_ignored_abandoned( $cnt, $websites ) { // phpcs:ignore -- NOSONAR - complex.
        ?>
        <table id="mainwp-per-site-ignored-abandoned-plugins" class="ui compact selectable table unstackable">
            <thead>
                <tr>
                    <th scope="col" ><?php esc_html_e( 'Site', 'mainwp' ); ?></th>
                    <th scope="col" class="no-sort"><?php esc_html_e( ' ', 'mainwp' ); ?></th>
                    <th scope="col" ><?php esc_html_e( 'Plugin', 'mainwp' ); ?></th>
                    <th scope="col" ><?php esc_html_e( 'Plugin slug', 'mainwp' ); ?></th>
                    <th scope="col" ></th>
                </tr>
            </thead>
            <tbody id="ignored-abandoned-plugins-list">
                <?php if ( 0 < $cnt ) : ?>
                    <?php
                    MainWP_DB::data_seek( $websites, 0 );

                    while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
                        $decodedIgnoredPlugins = MainWP_DB::instance()->get_website_option( $website, 'plugins_outdate_dismissed' );
                        $decodedIgnoredPlugins = ! empty( $decodedIgnoredPlugins ) ? json_decode( $decodedIgnoredPlugins, true ) : array();

                        if ( ! is_array( $decodedIgnoredPlugins ) || empty( $decodedIgnoredPlugins ) ) {
                            continue;
                        }
                        $first = true;
                        // phpcs:disable WordPress.Security.EscapeOutput
                        foreach ( $decodedIgnoredPlugins as $ignoredPlugin => $ignoredPluginName ) {
                            $ignore_name = 'N/A';
                            if ( is_string( $ignoredPluginName ) ) {
                                $ignore_name = $ignoredPluginName;
                            } elseif ( is_array( $ignoredPluginName ) && ! empty( $ignoredPluginName['Name'] ) ) {
                                $ignore_name = $ignoredPluginName['Name'];
                            }

                            $plugin_directory = MainWP_Utility::get_dir_slug( rawurldecode( $ignoredPlugin ) );
                            ?>
                            <tr site-id="<?php echo esc_attr( $website->id ); ?>" plugin-slug="<?php echo esc_attr( rawurlencode( $ignoredPlugin ) ); ?>">
                                    <?php if ( $first ) : ?>
                                    <td>
                                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ) ); ?>"><?php echo esc_html( stripslashes( $website->name ) ); ?></a>
                                    </td>
                                        <?php $first = false; ?>
                                <?php else : ?>
                                    <td><div style="display:none;"><a href="<?php echo esc_url( admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ) ); ?>"><?php echo esc_html( stripslashes( $website->name ) ); ?></a></div></td>
                                <?php endif; ?>
                                        <td class="collapsing"><?php echo MainWP_System_Utility::get_plugin_icon( $plugin_directory ); ?></td>
                                <td><a href="<?php echo esc_url( admin_url() ) . 'plugin-install.php?tab=plugin-information&wpplugin=' . intval( $website->id ) . '&plugin=' . esc_html( rawurlencode( $plugin_directory ) ); ?>" target="_blank" class="open-plugin-details-modal"><?php echo esc_html( $ignore_name ); ?></a></td>
                                <td><?php echo esc_html( $ignoredPlugin ); ?></td>
                                <td class="right aligned"><a href="#" class="ui mini button" onClick="return updatesoverview_plugins_unignore_abandoned_detail( '<?php echo esc_html( rawurlencode( $ignoredPlugin ) ); ?>', <?php echo intval( $website->id ); ?> )"> <?php esc_html_e( 'Unignore', 'mainwp' ); ?></a></td>
                            </tr>
                            <?php
                        }
                        // phpcs:enable
                    }
                    MainWP_DB::free_result( $websites );
                    ?>
        <?php endif; ?>
        </tbody>
        <?php if ( \mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
            <?php if ( 0 < $cnt ) : ?>
                <tfoot class="full-width">
                    <tr>
                        <th scope="col" colspan="5">
                            <a class="ui right floated small green labeled icon button" onClick="return updatesoverview_plugins_unignore_abandoned_detail_all();" id="mainwp-unignore-detail-all">
                                <i class="check icon"></i> <?php esc_html_e( 'Unignore All', 'mainwp' ); ?>
                            </a>
                        </th>
                    </tr>
                </tfoot>
            <?php endif; ?>
        <?php endif; ?>
        </table>
        <script type="text/javascript">
        jQuery( document ).ready( function() {
            jQuery( '#mainwp-per-site-ignored-abandoned-plugins' ).DataTable( {
                "responsive": true,
                "searching": false,
                "paging": false,
                "info": false,
                "columnDefs": [ {
                    "targets": 'no-sort',
                    "orderable": false
                } ],
                "language": {
                    "emptyTable": "<?php esc_html_e( 'No ignored abandoned plugins.', 'mainwp' ); ?>"
                }
            } );
        } );
        </script>
        <?php
    }

    /**
     * Method mainwp_help_content()
     *
     * Creates the MainWP Help Documentation List for the help component in the sidebar.
     */
    public static function mainwp_help_content() {
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( isset( $_GET['page'] ) && ( 'PluginsManage' === $_GET['page'] || 'PluginsInstall' === $_GET['page'] || 'PluginsAutoUpdate' === $_GET['page'] || 'PluginsIgnore' === $_GET['page'] || 'PluginsIgnoredAbandoned' === $_GET['page'] ) ) {
            ?>
            <p><?php esc_html_e( 'If you need help with managing plugins, please review following help documents', 'mainwp' ); ?></p>
            <div class="ui list">
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/managing-plugins-with-mainwp/" target="_blank">Managing Plugins with MainWP</a></div>
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/managing-plugins-with-mainwp/#install-plugins" target="_blank">Install Plugins</a></div>
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/managing-plugins-with-mainwp/#activate-plugins" target="_blank">Activate Plugins</a></div>
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/managing-plugins-with-mainwp/#delete-plugins" target="_blank">Delete Plugins</a></div>
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/managing-plugins-with-mainwp/#update-plugins" target="_blank">Update Plugins</a></div>
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/managing-plugins-with-mainwp/#plugins-auto-updates" target="_blank">Plugins Auto Updates</a></div>
                <?php
                /**
                 * Action: mainwp_plugins_help_item
                 *
                 * Fires at the bottom of the help articles list in the Help sidebar on the Plugins page.
                 *
                 * Suggested HTML markup:
                 *
                 * <div class="item"><a href="Your custom URL">Your custom text</a></div>
                 *
                 * @since 4.1
                 */
                do_action( 'mainwp_plugins_help_item' );
                ?>
            </div>
            <?php
        }
        // phpcs:enable
    }
}
