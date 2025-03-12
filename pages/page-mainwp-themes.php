<?php
/**
 * MainWP Themes Page
 *
 * This page is used to Manage Themes on Child Sites
 *
 * @package MainWP/Themes
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Themes_Page
 *
 * @uses MainWP_Install_Bulk
 */
class MainWP_Themes { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    //phpcs:disable Generic.Metrics.CyclomaticComplexity -- complexity.

    /**
     * Get Class Name
     *
     * @return string __CLASS__
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Subpages array.
     *
     * @var array $subPages Array of SubPages.
     */
    public static $subPages;

    /**
     * Fire on the initialization of WordPress.
     */
    public static function init() {
        /**
         * This hook allows you to render the Themes page header via the 'mainwp-pageheader-themes' action.
         *
         * @link http://codex.mainwp.com/#mainwp-pageheader-themes
         *
         * This hook is normally used in the same context of 'mainwp-getsubpages-themes'
         * @link http://codex.mainwp.com/#mainwp-getsubpages-themes
         *
         * @see \MainWP_Themes::render_header
         */
        add_action( 'mainwp-pageheader-themes', array( static::get_class_name(), 'render_header' ) );

        /**
         * This hook allows you to render the Themes page footer via the 'mainwp-pagefooter-themes' action.
         *
         * @link http://codex.mainwp.com/#mainwp-pagefooter-themes
         *
         * This hook is normally used in the same context of 'mainwp-getsubpages-themes'
         * @link http://codex.mainwp.com/#mainwp-getsubpages-themes
         *
         * @see \MainWP_Themes::render_footer
         */
        add_action( 'mainwp-pagefooter-themes', array( static::get_class_name(), 'render_footer' ) );

        add_action( 'mainwp_help_sidebar_content', array( static::get_class_name(), 'mainwp_help_content' ) );
    }

    /**
     * Method init_menu()
     *
     * Initiate the MainWP Themes SubMenu page.
     *
     * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
     */
    public static function init_menu() {

        add_submenu_page(
            'mainwp_tab',
            __( 'Themes', 'mainwp' ),
            '<span id="mainwp-Themes">' . esc_html__( 'Themes', 'mainwp' ) . '</span>',
            'read',
            'ThemesManage',
            array(
                static::get_class_name(),
                'render',
            )
        );

        add_submenu_page(
            'mainwp_tab',
            __( 'Themes', 'mainwp' ),
            '<div class="mainwp-hidden">' . esc_html__( 'Install Themes', 'mainwp' ) . '</div>',
            'read',
            'ThemesInstall',
            array(
                static::get_class_name(),
                'render_install',
            )
        );
        add_submenu_page(
            'mainwp_tab',
            __( 'Themes', 'mainwp' ),
            '<div class="mainwp-hidden">' . esc_html__( 'Advanced Auto Updates', 'mainwp' ) . '</div>',
            'read',
            'ThemesAutoUpdate',
            array(
                static::get_class_name(),
                'render_auto_update',
            )
        );
        add_submenu_page(
            'mainwp_tab',
            __( 'Themes', 'mainwp' ),
            '<div class="mainwp-hidden">' . esc_html__( 'Ignored Updates', 'mainwp' ) . '</div>',
            'read',
            'ThemesIgnore',
            array(
                static::get_class_name(),
                'render_ignore',
            )
        );

        add_submenu_page(
            'mainwp_tab',
            __( 'Themes', 'mainwp' ),
            '<div class="mainwp-hidden">' . esc_html__( 'Abandoned Themes', 'mainwp' ) . '</div>',
            'read',
            'ThemesAbandoned',
            array(
                static::get_class_name(),
                'render_abandoned_themes',
            )
        );

        add_submenu_page(
            'mainwp_tab',
            __( 'Themes', 'mainwp' ),
            '<div class="mainwp-hidden">' . esc_html__( 'Ignored Abandoned', 'mainwp' ) . '</div>',
            'read',
            'ThemesIgnoredAbandoned',
            array(
                static::get_class_name(),
                'render_ignored_abandoned',
            )
        );

        /**
         * Themes Subpages
         *
         * Filters subpages for the Themes page.
         *
         * @since Unknown
         */
        $sub_pages        = apply_filters_deprecated( 'mainwp-getsubpages-themes', array( array() ), '4.0.7.2', 'mainwp_getsubpages_themes' );  // @deprecated Use 'mainwp_getsubpages_themes' instead. NOSONAR - not IP.
        static::$subPages = apply_filters( 'mainwp_getsubpages_themes', $sub_pages );
        if ( isset( static::$subPages ) && is_array( static::$subPages ) ) {
            foreach ( static::$subPages as $subPage ) {
                if ( MainWP_Menu::is_disable_menu_item( 3, 'Themes' . $subPage['slug'] ) ) {
                    continue;
                }
                add_submenu_page( 'mainwp_tab', $subPage['title'], '<div class="mainwp-hidden">' . $subPage['title'] . '</div>', 'read', 'Themes' . $subPage['slug'], $subPage['callback'] );
            }
        }
        static::init_left_menu( static::$subPages );
    }

    /**
     * Method init_subpages_menu()
     *
     * Themes Subpage Menu HTML Content.
     *
     * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
     */
    public static function init_subpages_menu() {
        ?>
        <div id="menu-mainwp-Themes" class="mainwp-submenu-wrapper">
            <div class="wp-submenu sub-open">
                <div class="mainwp_boxout">
                    <div class="mainwp_boxoutin"></div>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=ThemesManage' ) ); ?>" class="mainwp-submenu">
                        <?php esc_html_e( 'Manage Themes', 'mainwp' ); ?>
                    </a>
                    <?php if ( \mainwp_current_user_can( 'dashboard', 'install_themes' ) ) { ?>
                        <?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ThemesInstall' ) ) { ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ThemesInstall' ) ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Install Themes', 'mainwp' ); ?></a>
                        <?php } ?>
                    <?php } ?>
                    <?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ThemesAutoUpdate' ) ) { ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=ThemesAutoUpdate' ) ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Advanced Auto Updates', 'mainwp' ); ?></a>
                    <?php } ?>
                    <?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ThemesIgnore' ) ) { ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=ThemesIgnore' ) ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Ignored Updates', 'mainwp' ); ?></a>
                    <?php } ?>
                    <?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ThemesIgnoredAbandoned' ) ) { ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=ThemesIgnoredAbandoned' ) ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Ignored Abandoned', 'mainwp' ); ?></a>
                    <?php } ?>
                    <?php
                    if ( isset( static::$subPages ) && is_array( static::$subPages ) ) {
                        foreach ( static::$subPages as $subPage ) {
                            if ( MainWP_Menu::is_disable_menu_item( 3, 'Themes' . $subPage['slug'] ) ) {
                                continue;
                            }
                            ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=Themes' . $subPage['slug'] ) ); ?>" class="mainwp-submenu"><?php echo esc_html( $subPage['title'] ); ?></a>
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
     * Method init_left_menu()
     *
     * Build arrays for each SubPage Menu Block.
     *
     * @param array $subPages Array of SubPages.
     *
     * @uses \MainWP\Dashboard\MainWP_Menu::add_left_menu()
     * @uses \MainWP\Dashboard\MainWP_Menu::init_subpages_left_menu()
     * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
     */
    public static function init_left_menu( $subPages = array() ) {
        MainWP_Menu::add_left_menu(
            array(
                'title'         => esc_html__( 'Themes', 'mainwp' ),
                'parent_key'    => 'managesites',
                'slug'          => 'ThemesManage',
                'href'          => 'admin.php?page=ThemesManage',
                'icon'          => '<i class="paint brush icon"></i>',
                'leftsub_order' => 6,
            ),
            1
        );

        $init_sub_subleftmenu = array(
            array(
                'title'                => esc_html__( 'Manage Themes', 'mainwp' ),
                'parent_key'           => 'ThemesManage',
                'href'                 => 'admin.php?page=ThemesManage',
                'slug'                 => 'ThemesManage',
                'right'                => '',
                'leftsub_order_level2' => 1,
            ),
            array(
                'title'                => esc_html__( 'Install Themes', 'mainwp' ),
                'parent_key'           => 'ThemesManage',
                'href'                 => 'admin.php?page=ThemesInstall',
                'slug'                 => 'ThemesInstall',
                'right'                => 'install_themes',
                'leftsub_order_level2' => 2,
            ),
            array(
                'title'                => esc_html__( 'Advanced Auto Updates', 'mainwp' ),
                'parent_key'           => 'ThemesManage',
                'href'                 => 'admin.php?page=ThemesAutoUpdate',
                'slug'                 => 'ThemesAutoUpdate',
                'right'                => '',
                'leftsub_order_level2' => 3,
            ),
            array(
                'title'                => esc_html__( 'Ignored Updates', 'mainwp' ),
                'parent_key'           => 'ThemesManage',
                'href'                 => 'admin.php?page=ThemesIgnore',
                'slug'                 => 'ThemesIgnore',
                'right'                => '',
                'leftsub_order_level2' => 4,
            ),
            array(
                'title'                => esc_html__( 'Abandoned Themes', 'mainwp' ),
                'parent_key'           => 'ThemesManage',
                'href'                 => 'admin.php?page=ThemesAbandoned',
                'slug'                 => 'ThemesAbandoned',
                'right'                => '',
                'leftsub_order_level2' => 4.1,
            ),
            array(
                'title'                => esc_html__( 'Ignored Abandoned', 'mainwp' ),
                'parent_key'           => 'ThemesManage',
                'href'                 => 'admin.php?page=ThemesIgnoredAbandoned',
                'slug'                 => 'ThemesIgnoredAbandoned',
                'right'                => '',
                'leftsub_order_level2' => 5,
            ),
        );

        MainWP_Menu::init_subpages_left_menu( $subPages, $init_sub_subleftmenu, 'ThemesManage', 'Themes' );

        foreach ( $init_sub_subleftmenu as $item ) {
            if ( MainWP_Menu::is_disable_menu_item( 3, $item['slug'] ) ) {
                continue;
            }
            MainWP_Menu::add_left_menu( $item, 2 );
        }
    }

    /**
     * Method render_header()
     *
     * Render Themes SubPage Header.
     *
     * @param string $shownPage The page slug shown at this moment.
     *
     * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
     * @uses \MainWP\Dashboard\MainWP_UI::render_top_header()
     * @uses \MainWP\Dashboard\MainWP_UI::render_page_navigation()
     */
    public static function render_header( $shownPage = '' ) { // phpcs:ignore -- NOSONAR - complex.
        $params = array( 'title' => esc_html__( 'Themes', 'mainwp' ) );

        MainWP_UI::render_top_header( $params );

            $renderItems = array();

            $renderItems[] = array(
                'title'  => esc_html__( 'Manage Themes', 'mainwp' ),
                'href'   => 'admin.php?page=ThemesManage',
                'active' => ( 'Manage' === $shownPage ) ? true : false,
            );

            if ( \mainwp_current_user_can( 'dashboard', 'install_themes' ) ) {
                $renderItems[] = array(
                    'title'  => esc_html__( 'Install', 'mainwp' ),
                    'href'   => 'admin.php?page=ThemesInstall',
                    'active' => ( 'Install' === $shownPage ) ? true : false,
                );
            }

            if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ThemesAutoUpdate' ) ) {
                $renderItems[] = array(
                    'title'  => esc_html__( 'Advanced Auto Updates', 'mainwp' ),
                    'href'   => 'admin.php?page=ThemesAutoUpdate',
                    'active' => ( 'AutoUpdate' === $shownPage ) ? true : false,
                );
            }

            if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ThemesIgnore' ) ) {
                $renderItems[] = array(
                    'title'  => esc_html__( 'Ignored Updates', 'mainwp' ),
                    'href'   => 'admin.php?page=ThemesIgnore',
                    'active' => ( 'Ignore' === $shownPage ) ? true : false,
                );
            }

            if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ThemesAbandoned' ) ) {
                $renderItems[] = array(
                    'title'  => esc_html__( 'Abandoned Themes', 'mainwp' ),
                    'href'   => 'admin.php?page=ThemesAbandoned',
                    'active' => ( 'Abandoned' === $shownPage ) ? true : false,
                );
            }

            if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ThemesIgnoredAbandoned' ) ) {
                $renderItems[] = array(
                    'title'  => esc_html__( 'Ignored Abandoned', 'mainwp' ),
                    'href'   => 'admin.php?page=ThemesIgnoredAbandoned',
                    'active' => ( 'IgnoreAbandoned' === $shownPage ) ? true : false,
                );
            }

            if ( isset( static::$subPages ) && is_array( static::$subPages ) ) {
                foreach ( static::$subPages as $subPage ) {
                    if ( MainWP_Menu::is_disable_menu_item( 3, 'Themes' . $subPage['slug'] ) ) {
                        continue;
                    }
                    $item           = array();
                    $item['title']  = $subPage['title'];
                    $item['href']   = 'admin.php?page=Themes' . $subPage['slug'];
                    $item['active'] = ( $subPage['slug'] === $shownPage ) ? true : false;
                    $renderItems[]  = $item;
                }
            }
            MainWP_UI::render_page_navigation( $renderItems );
    }

    /**
     * Method render_footer()
     *
     * Close the page container.
     */
    public static function render_footer() {
        echo '</div>';
    }

    /**
     * Method render()
     *
     * Render the Theme SubPage content.
     *
     * @uses \MainWP\Dashboard\MainWP_Cache::get_cached_context()
     * @uses \MainWP\Dashboard\MainWP_Cache::get_cached_result()
     * @uses \MainWP\Dashboard\MainWP_UI::render_empty_bulk_actions()
     */
    public static function render() { // phpcs:ignore -- NOSONAR - complex.
        $cachedSearch     = MainWP_Cache::get_cached_context( 'Themes' );
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

        $cachedResult = MainWP_Cache::get_cached_result( 'Themes' );

        if ( isset( $_POST['select_mainwp_options_plugintheme_view'] ) && check_admin_referer( 'mainwp-admin-nonce' ) && is_array( $cachedResult ) && isset( $cachedResult['result'] ) ) {
            unset( $cachedResult['result'] ); // clear cached results.
        }

        static::render_header( 'Manage' );
        ?>

        <div id="mainwp-manage-themes" class="ui alt segment">
            <div class="mainwp-main-content">
                <div class="ui mini form mainwp-actions-bar">
                    <div class="ui stackable grid">
                        <div class="ui two column row">
                            <div class="left aligned column">
                                <span id="mainwp-themes-bulk-actions-wapper" style="margin-right:1em">
                                <?php
                                if ( is_array( $cachedResult ) && isset( $cachedResult['bulk_actions'] ) ) {
                                    echo $cachedResult['bulk_actions']; // phpcs:ignore WordPress.Security.EscapeOutput
                                }
                                ?>
                                </span>
                                <button id="mainwp-install-themes-to-selected-sites" class="ui mini green basic button" style="display: none"><?php esc_html_e( 'Install to Selected Site(s)', 'mainwp' ); ?></button>
                                <?php
                                /**
                                 * Action: mainwp_themes_actions_bar_left
                                 *
                                 * Fires at the left side of the actions bar on the Themes screen, after the Bulk Actions menu.
                                 *
                                 * @since 4.0
                                 */
                                do_action( 'mainwp_themes_actions_bar_left' );
                                ?>
                            </div>
                            <div class="right aligned column">
                                <?php MainWP_Plugins::render_select_manage_view( 'theme' ); ?>

                                <?php
                                /**
                                 * Action: mainwp_themes_actions_bar_right
                                 *
                                 * Fires at the right side of the actions bar on the Themes screen.
                                 *
                                 * @since 4.0
                                 */
                                do_action( 'mainwp_themes_actions_bar_right' );
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="ui segment" id="mainwp_themes_wrap_table">
                    <?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-manage-themes-info-message' ) ) : ?>
                        <div class="ui info message">
                            <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-manage-themes-info-message"></i>
                            <div><?php echo esc_html__( 'Manage installed themes on your child sites. Here you can activate, deactivate, and delete installed themes.', 'mainwp' ); ?></div>
                            <p><?php echo esc_html__( 'To Activate or Delete a specific theme, you must search only for Inactive themes on your child sites. If you search for Active or both Active and Inactive, the Activate and Delete actions will be disabled.', 'mainwp' ); ?></p>
                            <p><?php printf( esc_html__( 'For additional help, please check this %1$shelp documentation%2$s.', 'mainwp' ), '<a href="https://mainwp.com/kb/managing-themes-with-mainwp/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); ?></p>
                        </div>
                    <?php endif; ?>
                    <div id="mainwp-message-zone" class="ui message" style="display:none"></div>
                    <div id="mainwp-loading-themes-row" class="ui active inverted dimmer" style="display:none">
                        <div class="ui large text loader"><?php esc_html_e( 'Loading Themes...', 'mainwp' ); ?></div>
                    </div>
                    <div id="mainwp-themes-main-content" <?php echo ( null !== $cachedSearch ) ? 'style="display: block;"' : ''; ?> >
                        <div id="mainwp-themes-content">
                        <?php if ( is_array( $cachedResult ) && isset( $cachedResult['result'] ) ) : ?>
                                <?php echo $cachedResult['result']; // phpcs:ignore WordPress.Security.EscapeOutput ?>
                        <?php else : ?>
                            <div class="ui hidden divider"></div>
                            <div class="ui hidden divider"></div>
                            <div class="ui hidden divider"></div>
                            <?php MainWP_UI::render_empty_element_placeholder( __( 'Use the search options to find the theme you want to manage', 'mainwp' ) ); ?>
                        <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mainwp-side-content mainwp-no-padding">
                <?php
                /**
                 * Action: mainwp_manage_themes_sidebar_top
                 *
                 * Fires at the top of the sidebar on Manage themes.
                 *
                 * @since 4.1
                 */
                do_action( 'mainwp_manage_themes_sidebar_top' );
                ?>
                <div class="mainwp-select-sites ui accordion mainwp-sidebar-accordion">
                    <?php
                    /**
                     * Action: mainwp_manage_themes_before_select_sites
                     *
                     * Fires before the Select Sites element on Manage themes.
                     *
                     * @since 4.1
                     */
                    do_action( 'mainwp_manage_themes_before_select_sites' );
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
                     * Action: mainwp_manage_themes_after_select_sites
                     *
                     * Fires after the Select Sites element on Manage themes.
                     *
                     * @since 4.1
                     */
                    do_action( 'mainwp_manage_themes_after_select_sites' );
                    ?>
                </div>
                <div class="ui fitted divider"></div>
                <div class="mainwp-search-options ui accordion mainwp-sidebar-accordion">
                    <div class="active title"><i class="dropdown icon"></i> <?php esc_html_e( 'Select Status', 'mainwp' ); ?></div>
                    <div class="content active">
                    <?php
                    /**
                     * Action: mainwp_manage_themes_before_search_options
                     *
                     * Fires before the Search Options element on Manage themes.
                     *
                     * @since 4.1
                     */
                    do_action( 'mainwp_manage_themes_before_search_options' );
                    ?>
                    <div class="ui info message">
                        <i class="close icon mainwp-notice-dismiss" notice-id="themes-manage-info"></i>
                        <?php esc_html_e( 'A theme needs to be Inactive for it to be Activated or Deleted.', 'mainwp' ); ?>
                    </div>
                    <div class="ui mini form">
                        <div class="field">
                            <select class="ui fluid dropdown" id="mainwp_themes_search_by_status">
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
                     * Action: mainwp_manage_themes_after_search_options
                     *
                     * Fires after the Search Options element on Manage themes.
                     *
                     * @since 4.1
                     */
                    do_action( 'mainwp_manage_themes_after_search_options' );
                    ?>
                </div>
                </div>
                <div class="ui fitted divider"></div>
                <?php static::render_search_options(); ?>
                <div class="ui fitted divider"></div>
                <div class="mainwp-search-submit">
                    <?php
                    /**
                     * Action: mainwp_manage_themes_before_submit_button
                     *
                     * Fires before the Submit Button element on Manage themes.
                     *
                     * @since 4.1
                     */
                    do_action( 'mainwp_manage_themes_before_submit_button' );
                    ?>
                    <input type="button" name="mainwp_show_themes" id="mainwp_show_themes" class="ui green big fluid button" value="<?php esc_attr_e( 'Show Themes', 'mainwp' ); ?>"/>
                    <?php
                    /**
                     * Action: mainwp_manage_themes_after_submit_button
                     *
                     * Fires after the Submit Button element on Manage themes.
                     *
                     * @since 4.1
                     */
                    do_action( 'mainwp_manage_themes_after_submit_button' );
                    ?>
                </div>
                <?php
                /**
                 * Action: mainwp_manage_themes_sidebar_bottom
                 *
                 * Fires at the bottom of the sidebar on Manage themes.
                 *
                 * @since 4.1
                 */
                do_action( 'mainwp_manage_themes_sidebar_bottom' );
                ?>
            </div>
            <div style="clear:both"></div>
        </div>
        <?php
        static::render_footer( 'Manage' );
    }

    /**
     * Render the Search Options Meta Box.
     *
     * @uses \MainWP\Dashboard\MainWP_Cache::get_cached_context()
     */
    public static function render_search_options() {
        $cachedSearch = MainWP_Cache::get_cached_context( 'Themes' );
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
                            <input type="text" placeholder="<?php esc_attr_e( 'Theme name', 'mainwp' ); ?>" id="mainwp_theme_search_by_keyword" size="50" class="text" value="<?php echo ( null !== $cachedSearch ) ? esc_attr( $cachedSearch['keyword'] ) : ''; ?>"/>
                        </div>
                    </div>
                    <div class="ui hidden fitted divider"></div>
                        <div class="field">
                            <div class="ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'Display sites not meeting the above search criteria.', 'mainwp' ); ?>" data-position="left center" data-inverted="">
                                <input type="checkbox" <?php echo $disabledNegative ? 'disabled' : ''; ?> <?php echo $checkedNegative ? 'checked="true"' : ''; ?> value="1" id="display_sites_not_meeting_criteria" />
                                <label for="display_sites_not_meeting_criteria"><?php esc_html_e( 'Exclude theme', 'mainwp' ); ?></label>
                                </div>
                        </div>
                </div>
            </div>
        </div>
        <?php
        if ( is_array( $statuses ) && ! empty( $statuses ) ) {
            $status = '';
            foreach ( $statuses as $st ) {
                $status .= "'" . esc_js( $st ) . "',";
            }
            $status = rtrim( $status, ',' );
            ?>
            <script type="text/javascript">
            jQuery( document ).ready( function () {
                jQuery( '#mainwp_themes_search_by_status' ).dropdown(  'set selected', [<?php echo $status; // phpcs:ignore -- safe output, to fix incorrect characters. ?>] );
            } );
            </script>
            <?php
        }
        ?>
        <script type="text/javascript">
            jQuery( document ).on( 'keyup', '#mainwp_theme_search_by_keyword', function () {
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
     * Method render_table()
     *
     * Render the Child Sites Bulk action & Sidebar Meta boxes.
     *
     * @param string $keyword Search keyword parameter.
     * @param string $status Search status parameter.
     * @param array  $groups Selected groups of child sites.
     * @param array  $sites Selected child sites.
     * @param mixed  $not_criteria Show not criteria result.
     * @param mixed  $clients Selected Clients.
     *
     * @return mixed $result Errors|HTML.
     *
     * @uses \MainWP\Dashboard\MainWP_Cache::init_cache()
     * @uses \MainWP\Dashboard\MainWP_Cache::add_context()
     * @uses \MainWP\Dashboard\MainWP_Cache::add_result()
     * @uses \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
     * @uses \MainWP\Dashboard\MainWP_DB::query()
     * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_by_group_id()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_DB::free_result()
     * @uses \MainWP\Dashboard\MainWP_Themes_Handler::get_class_name()
     * @uses \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     * @uses \MainWP\Dashboard\MainWP_Utility::map_site()
     * @uses \MainWP\Dashboard\MainWP_Utility::get_nice_url()
     */
    public static function render_table( $keyword, $status, $groups, $sites, $not_criteria, $clients = '' ) { // phpcs:ignore -- NOSONAR - complex function.
        MainWP_Cache::init_cache( 'Themes' );

        $output                   = new \stdClass();
        $output->errors           = array();
        $output->themes           = array();
        $output->themes_installed = array();
        $output->status           = $status;
        $output->roll_items       = array();
        $error_results            = '';

        $data_fields   = MainWP_System_Utility::get_default_map_site_fields();
        $data_fields[] = 'themes';
        $data_fields[] = 'rollback_updates_data';

        if ( 1 === (int) get_option( 'mainwp_optimize', 1 ) || MainWP_Demo_Handle::is_demo_mode() ) {

            $keyword   = trim( $keyword );
            $multi_kws = explode( ',', $keyword );
            $multi_kws = array_filter( array_map( 'trim', $multi_kws ) );

            if ( '' !== $sites ) {
                foreach ( $sites as $v ) {
                    if ( MainWP_Utility::ctype_digit( $v ) ) {
                        $website          = MainWP_DB::instance()->get_website_by_id( $v, false, array( 'rollback_updates_data' ) );
                        $allThemes        = json_decode( $website->themes, true );
                        $_count           = count( $allThemes );
                        $_count_installed = 0;
                        for ( $i = 0; $i < $_count; $i++ ) {
                            $theme           = $allThemes[ $i ];
                            $active_inactive = 'active' === $status || 'inactive' === $status;
                            if ( $active_inactive && ( ( 1 === (int) $theme['active'] && 'active' !== $status ) || ( 1 !== $theme['active'] && 'inactive' !== $status ) ) ) {
                                continue;
                            }

                            if ( ! empty( $keyword ) ) {
                                if ( $not_criteria ) {
                                    if ( MainWP_Utility::multi_find_keywords( $theme['title'], $multi_kws ) ) {
                                        continue;
                                    }
                                } elseif ( ! MainWP_Utility::multi_find_keywords( $theme['title'], $multi_kws ) ) {
                                    continue;
                                }
                            }

                            $theme['websiteid']   = $website->id;
                            $theme['websiteurl']  = $website->url;
                            $theme['websitename'] = $website->name;
                            $output->themes[]     = $theme;
                            ++$_count_installed;
                        }
                        if ( 0 === $_count_installed && 'not_installed' === $status ) {
                            for ( $i = 0; $i < $_count; $i++ ) {
                                $theme                      = $allThemes[ $i ];
                                $theme['websiteid']         = $website->id;
                                $theme['websiteurl']        = $website->url;
                                $theme['websitename']       = $website->name;
                                $output->themes_installed[] = $theme;
                            }
                        }
                        $output->roll_items[ $website->id ] = MainWP_Updates_Helper::get_roll_update_plugintheme_items( 'theme', $website->rollback_updates_data );
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
                            $allThemes        = json_decode( $website->themes, true );
                            $_count           = count( $allThemes );
                            $_count_installed = 0;
                            for ( $i = 0; $i < $_count; $i++ ) {
                                $theme     = $allThemes[ $i ];
                                $act_inact = 'active' === $status || 'inactive' === $status;
                                if ( $act_inact && ( ( 1 === (int) $theme['active'] && 'active' !== $status ) || ( 1 !== $theme['active'] && 'inactive' !== $status ) ) ) {
                                    continue;
                                }

                                if ( ! empty( $keyword ) ) {
                                    if ( $not_criteria ) {
                                        if ( MainWP_Utility::multi_find_keywords( $theme['title'], $multi_kws ) ) {
                                            continue;
                                        }
                                    } elseif ( ! MainWP_Utility::multi_find_keywords( $theme['title'], $multi_kws ) ) {
                                        continue;
                                    }
                                }

                                $theme['websiteid']   = $website->id;
                                $theme['websiteurl']  = $website->url;
                                $theme['websitename'] = $website->name;
                                $output->themes[]     = $theme;
                                ++$_count_installed;
                            }
                            if ( 0 === $_count_installed && 'not_installed' === $status ) {
                                for ( $i = 0; $i < $_count; $i++ ) {
                                    $theme                      = $allThemes[ $i ];
                                    $theme['websiteid']         = $website->id;
                                    $theme['websiteurl']        = $website->url;
                                    $theme['websitename']       = $website->name;
                                    $output->themes_installed[] = $theme;
                                }
                            }
                            $output->roll_items[ $website->id ] = MainWP_Updates_Helper::get_roll_update_plugintheme_items( 'theme', $website->rollback_updates_data );
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
                        $allThemes        = json_decode( $website->themes, true );
                        $_count           = count( $allThemes );
                        $_count_installed = 0;
                        for ( $i = 0; $i < $_count; $i++ ) {
                            $theme     = $allThemes[ $i ];
                            $act_inacy = 'active' === $status || 'inactive' === $status;
                            if ( $act_inacy && ( ( 1 === (int) $theme['active'] && 'active' !== $status ) || ( 1 !== $theme['active'] && 'inactive' !== $status ) ) ) {
                                continue;
                            }
                            if ( ! empty( $keyword ) ) {
                                if ( $not_criteria ) {
                                    if ( MainWP_Utility::multi_find_keywords( $theme['title'], $multi_kws ) ) {
                                        continue;
                                    }
                                } elseif ( ! MainWP_Utility::multi_find_keywords( $theme['title'], $multi_kws ) ) {
                                    continue;
                                }
                            }

                            $theme['websiteid']   = $website->id;
                            $theme['websiteurl']  = $website->url;
                            $theme['websitename'] = $website->name;
                            $output->themes[]     = $theme;
                            ++$_count_installed;
                        }
                        if ( 0 === $_count_installed && 'not_installed' === $status ) {
                            for ( $i = 0; $i < $_count; $i++ ) {
                                $theme                      = $allThemes[ $i ];
                                $theme['websiteid']         = $website->id;
                                $theme['websiteurl']        = $website->url;
                                $theme['websitename']       = $website->name;
                                $output->themes_installed[] = $theme;
                            }
                        }
                        $output->roll_items[ $website->id ] = MainWP_Updates_Helper::get_roll_update_plugintheme_items( 'theme', $website->rollback_updates_data );
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
            MainWP_Connect::fetch_urls_authed( $dbwebsites, 'get_all_themes', $post_data, array( MainWP_Themes_Handler::get_class_name(), 'themes_search_handler' ), $output );

            if ( ! empty( $output->errors ) ) {
                foreach ( $output->errors as $siteid => $error ) {
                    $error_results .= MainWP_Utility::get_nice_url( $dbwebsites[ $siteid ]->url ) . ' - ' . $error . '<br/>'; // phpcs:ignore WordPress.Security.EscapeOutput
                }
            }
        }

        MainWP_Cache::add_context(
            'Themes',
            array(
                'keyword'      => $keyword,
                'status'       => $status,
                'sites'        => ( '' !== $sites ) ? $sites : '',
                'groups'       => ( '' !== $groups ) ? $groups : '',
                'clients'      => ( '' !== $clients ) ? $clients : '',
                'not_criteria' => $not_criteria ? true : false,
            )
        );

        $view_mode = MainWP_Plugins::get_manage_view( 'theme' );

        $bulkActions = static::render_bulk_actions( $status );
        ob_start();

        if ( ! empty( $error_results ) ) {
            // phpcs:disable WordPress.Security.EscapeOutput
            ?>
            <div class="ui message yellow"><?php echo $error_results; ?></div>
            <?php
            //phpcs:enable
        }
        $roll_items_list = array();
        if ( 'not_installed' === $status ) {
            if ( empty( $output->themes_installed ) ) {
                ?>
                <div class="ui message yellow"><?php esc_html_e( 'No websites found.', 'mainwp' ); ?></div>
                <?php
            } else {
                $themes_list     = $output->themes_installed;
                $roll_items_list = ! empty( $output->roll_items ) ? $output->roll_items : array();
                $view_mode       = MAINWP_VIEW_PER_SITE;
            }
        } elseif ( empty( $output->themes ) ) {
            ?>
            <div class="ui message yellow"><?php esc_html_e( 'No themes found.', 'mainwp' ); ?></div>
            <?php
        } else {
            $themes_list     = $output->themes;
            $roll_items_list = ! empty( $output->roll_items ) ? $output->roll_items : array();
        }

        $sites             = array();
        $siteThemes        = array();
        $themesName        = array();
        $themesNameSites   = array();
        $themesRealVersion = array();
        $themesSlug        = array();

        if ( ! is_array( $roll_items_list ) ) {
            $roll_items_list = array();
        }

        if ( ! empty( $themes_list ) ) {

            foreach ( $themes_list as $theme ) {
                $slug_ver            = esc_html( $theme['name'] . '_' . $theme['version'] );
                $theme['name']       = esc_html( $theme['name'] );
                $theme['version']    = esc_html( $theme['version'] );
                $theme['title']      = esc_html( $theme['title'] );
                $theme['slug']       = esc_html( $theme['slug'] );
                $theme['active']     = ( 1 === (int) $theme['active'] ) ? 1 : 0;
                $theme['websiteurl'] = esc_url_raw( $theme['websiteurl'] );

                $sites[ $theme['websiteid'] ] = array(
                    'websiteurl'  => $theme['websiteurl'],
                    'websitename' => $theme['websitename'],
                );
                $themesName[ $slug_ver ]      = $theme['name'];
                $themesSlug[ $slug_ver ]      = $theme['slug'];

                $themesNameSites[ $theme['name'] ][ $theme['websiteid'] ][] = $slug_ver;

                $themesRealVersion[ $slug_ver ] = $theme['version'];
                if ( ! isset( $siteThemes[ $theme['websiteid'] ] ) || ! is_array( $siteThemes[ $theme['websiteid'] ] ) ) {
                    $siteThemes[ $theme['websiteid'] ] = array();
                }
                $siteThemes[ $theme['websiteid'] ][ $slug_ver ] = $theme;
            }

            ksort( $themesNameSites, SORT_STRING );

            if ( MAINWP_VIEW_PER_PLUGIN_THEME === (int) $view_mode ) {
                static::render_manage_table( $sites, $themesName, $siteThemes, $themesSlug, $themesNameSites, $themesRealVersion, $roll_items_list );
            } else {
                static::render_manage_per_site_table( $sites, $themesName, $siteThemes, $themesSlug, $themesNameSites, $themesRealVersion, $roll_items_list );
            }
            MainWP_UI::render_modal_upload_icon();
        }

        $newOutput = ob_get_clean();
        $result    = array(
            'result'       => $newOutput,
            'bulk_actions' => $bulkActions,
        );

        MainWP_Cache::add_result( 'Themes', $result );
        return $result;
    }

    /**
     * Method render_manage_per_site_table()
     *
     * Render the Manage Themes table
     *
     * @param array  $sites List of sites.
     * @param array  $themesName List of themes.
     * @param array  $siteThemes List of themes for the site.
     * @param array  $themesSlug List of theme slugs.
     * @param array  $themesNameSites Installed theme version.
     * @param string $themesRealVersion Current theme version.
     * @param array  $roll_list roll items list.
     */
    public static function render_manage_per_site_table( $sites, $themesName = array(), $siteThemes = array(), $themesSlug = array(), $themesNameSites = array(), $themesRealVersion = array(), $roll_list = array() ) { //phpcs:ignore -- NOSONAR - complex method.
        $userExtension        = MainWP_DB_Common::instance()->get_user_extension();
        $decodedIgnoredThemes = json_decode( $userExtension->ignored_themes, true );
        $trustedThemes        = json_decode( $userExtension->trusted_themes, true );
        $is_demo              = MainWP_Demo_Handle::is_demo_mode();

        if ( ! is_array( $trustedThemes ) ) {
            $trustedThemes = array();
        }

        $updateWebsites = array();

        foreach ( $sites as $site_id => $info ) {

            $theme_upgrades = array();
            $website        = MainWP_DB::instance()->get_website_by_id( $site_id );
            if ( $website && ! $website->is_ignoreThemeUpdates ) {
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

                if ( is_array( $decodedIgnoredThemes ) ) {
                    $theme_upgrades = array_diff_key( $theme_upgrades, $decodedIgnoredThemes );
                }
            }
            $updateWebsites[ $site_id ] = $theme_upgrades;
        }

        /**
         * Action: mainwp_before_themes_table
         *
         * Fires before the Themes table.
         *
         * @since 4.1
         */
        do_action( 'mainwp_before_themes_table' );
        ?>

        <div class="ui secondary segment main-master-checkbox">
            <div class="ui stackable grid">
                <div class="one wide left aligned middle aligned column">
                    <span class="trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></span>
                </div>
                <div class="one wide center aligned middle aligned column"><div class="ui checkbox main-master  not-auto-init"><input type="checkbox"/><label></label></div></div>
                <div class="six wide middle aligned column"><?php esc_html_e( 'Theme', 'mainwp' ); ?></div>
                <div class="two wide center aligned middle aligned column"></div>
                <div class="two wide center aligned middle aligned column"></div>
                <div class="two wide center aligned middle aligned column"></div>
                <div class="two wide right aligned middle aligned column"><?php esc_html_e( 'Themes', 'mainwp' ); ?></div>
            </div>
        </div>
        <div class="mainwp-manage-themes-wrapper main-child-checkbox">
        <?php foreach ( $sites as $site_id => $website ) : ?>
            <?php
            $site_name    = $website['websitename'];
            $slugVersions = isset( $siteThemes[ $site_id ] ) ? $siteThemes[ $site_id ] : array();
            $item_id      = $site_id;
            $count_themes = count( $slugVersions );
            ?>
            <div class="ui accordion mainwp-manage-theme-accordion mainwp-manage-theme-item main-child-checkbox"  id="<?php echo esc_html( $item_id ); ?>">
                <div class="title master-checkbox">
                    <div class="ui stackable grid">
                        <div class="one wide left aligned middle aligned column"><i class="dropdown icon dropdown-trigger"></i></div>
                        <div class="one wide center aligned middle aligned column"><div class="ui checkbox master"><input type="checkbox"><label></label></div></div>
                        <div class="four wide middle aligned column"><a target="_blank" href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo intval( $site_id ); ?>&_opennonce=<?php echo esc_html( wp_create_nonce( 'mainwp-admin-nonce' ) ); ?>"><i class="sign in icon"></i></a> <a href="admin.php?page=managesites&dashboard=<?php echo intval( $site_id ); ?>"><?php echo esc_html( $site_name ); ?></a></div>
                        <div class="two wide center aligned middle aligned column"></div>
                        <div class="two wide center aligned middle aligned column"></div>
                        <div class="two wide center aligned middle aligned column"></div>
                        <div class="two wide center aligned middle aligned column"></div>
                        <div class="two wide right aligned middle aligned column"><div class="ui label"><?php echo intval( $count_themes ) . ' ' . esc_html( _n( 'Theme', 'Themes', intval( $count_themes ), 'mainwp' ) ); ?></div></div>
                    </div>
                </div>
                <div class="content child-checkbox">
                    <?php
                    foreach ( $slugVersions as $slug_ver => $theme ) :

                        $theme_slug  = $themesSlug[ $slug_ver ];
                        $theme_title = $theme['title'];
                        $trusted     = in_array( $theme_slug, $trustedThemes ) ? true : false;

                        $theme_version = $siteThemes[ $site_id ][ $slug_ver ]['version'];

                        $theme_upgrades = isset( $updateWebsites[ $site_id ] ) ? $updateWebsites[ $site_id ] : array();
                        if ( ! is_array( $theme_upgrades ) ) {
                            $theme_upgrades = array();
                        }

                        $upgradeInfo = isset( $theme_upgrades[ $theme_slug ] ) ? $theme_upgrades[ $theme_slug ] : false;

                        $active_status_class = '';
                        if ( isset( $siteThemes[ $site_id ][ $slug_ver ]['active'] ) && 1 === (int) $siteThemes[ $site_id ][ $slug_ver ]['active'] ) {
                            $active_status_class = 'positive';
                        } elseif ( isset( $siteThemes[ $site_id ][ $slug_ver ]['active'] ) && empty( $siteThemes[ $site_id ][ $slug_ver ]['active'] ) ) {
                            $active_status_class = 'negative';
                        } else {
                            $active_status_class = '';
                        }

                        if ( isset( $siteThemes[ $site_id ][ $slug_ver ]['child_active'] ) && 1 === (int) $siteThemes[ $site_id ][ $slug_ver ]['child_active'] ) {
                            $active_status_class .= ' child-active';
                        }

                        $not_delete = false;
                        $parent_str = '';
                        if ( isset( $siteThemes[ $site_id ][ $slug_ver ]['parent_active'] ) && 1 === (int) $siteThemes[ $site_id ][ $slug_ver ]['parent_active'] ) {
                            $parent_str = '<span data-tooltip="' . sprintf( esc_html__( 'Parent theme of the active theme (%s) on the site can not be deleted.', 'mainwp' ), isset( $siteThemes[ $site_id ][ $slug_ver ]['child_theme'] ) ? $siteThemes[ $site_id ][ $slug_ver ]['child_theme'] : '' ) . '" data-position="right center" data-inverted="" data-variation="mini"><i class="lock icon"></i></span>';
                            $not_delete = true;
                        }

                        $new_version = '';
                        if ( ! empty( $upgradeInfo ) && isset( $upgradeInfo['update']['new_version'] ) ) {
                            $new_version = $upgradeInfo['update']['new_version'];
                        }

                        if ( isset( $siteThemes[ $site_id ][ $slug_ver ] ) && ( empty( $siteThemes[ $site_id ][ $slug_ver ]['active'] ) || 1 === (int) $siteThemes[ $site_id ][ $slug_ver ]['active'] ) ) {
                            $actived = true;

                            if ( isset( $siteThemes[ $site_id ][ $slug_ver ]['active'] ) && 1 === (int) $siteThemes[ $site_id ][ $slug_ver ]['active'] ) {
                                $theme_status = '<span class="ui small green basic label">Active</span>';
                            } elseif ( isset( $siteThemes[ $site_id ][ $slug_ver ]['active'] ) && empty( $siteThemes[ $site_id ][ $slug_ver ]['active'] ) ) {
                                $theme_status = '<span class="ui small red basic label">Inactive</span>';
                                $actived      = false;
                            } else {
                                $theme_status = '';
                            }

                            $item_id = $slug_ver . '_' . $site_id;
                            $item_id = strtolower( $item_id );
                            $item_id = preg_replace( '/[[:space:]]+/', '_', $item_id );
                            ?>
                            <div class="ui very compact stackable grid mainwp-manage-theme-item-website <?php echo esc_html( $active_status_class ); ?>"  updated="0" site-id="<?php echo intval( $site_id ); ?>" theme-slug="<?php echo esc_attr( $theme_slug ); ?>" theme-name="<?php echo esc_html( wp_strip_all_tags( $themesName[ $slug_ver ] ) ); ?>" site-id="<?php echo intval( $site_id ); ?>" site-name="<?php echo esc_html( $site_name ); ?>"  id="<?php echo esc_html( $item_id ); ?>" not-delete="<?php echo $not_delete ? 1 : 0; ?>" is-actived="<?php echo $actived ? 1 : 0; ?>" >
                            <div class="one wide center aligned middle aligned column"></div>
                                <div class="one wide center aligned middle aligned column">

                                <?php if ( '' !== $parent_str ) : ?>
                                    <?php echo $parent_str; //phpcs:ignore -- escaped. ?>
                                <?php else : ?>
                                    <?php if ( $actived ) { ?>
                                    <span data-tooltip="<?php echo esc_html__( 'Active theme on the site can not be deleted.', 'mainwp' ); ?>" data-position="right center" data-inverted="" data-variation="mini"><i class="lock icon"></i></span>
                                    <?php } ?>
                                    <div class="ui checkbox">
                                        <input type="checkbox"  class="mainwp-selected-theme-site" />
                                        <label></label>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div class="one wide center aligned middle aligned column"><?php echo MainWP_System_Utility::get_theme_icon( $theme_slug ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
                                <div class="three wide middle aligned column"><strong><?php echo esc_html( $theme_title ); ?></strong></div>
                                <div class="one wide center aligned middle aligned column"><?php echo $theme_status; // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
                                <div class="two wide center aligned middle aligned column"><?php echo $trusted ? '<span class="ui tiny basic green label">' . esc_html__( 'Trusted', 'mainwp' ) . '</span>' : '<span class="ui tiny basic grey label">' . esc_html__( 'Not Trusted', 'mainwp' ) . '</span>'; ?></div>
                                <div class="one wide center aligned middle aligned column"></div>
                                <div class="two wide center aligned middle aligned column current-version">
                                    <?php echo esc_html( $theme_version ); ?>
                                    <?php if ( ! empty( $new_version ) ) : ?>
                                        &rarr;
                                        <?php
                                        if ( ! empty( $roll_list[ $site_id ][ $theme_slug ][ $new_version ] ) ) {
                                            echo MainWP_Updates_Helper::get_roll_msg( $roll_list[ $site_id ][ $theme_slug ][ $new_version ], true, 'notice' ); //phpcs:ignore -- NOSONAR -- ok.
                                        }
                                        echo esc_html( $new_version );
                                        ?>
                                    <?php endif; ?>
                                </div>
                                <div class="two wide right aligned middle aligned column update-column">
                                    <?php if ( ! empty( $upgradeInfo ) && MainWP_Updates::user_can_update_themes() ) : ?>
                                        <a href="javascript:void(0)" class="ui mini green basic <?php echo $is_demo ? 'disabled' : ''; ?> button" onClick="return manage_themes_upgrade_theme( '<?php echo esc_js( rawurlencode( $theme_slug ) ); ?>', <?php echo esc_attr( $site_id ); ?> )"><?php esc_html_e( 'Update', 'mainwp' ); ?></a>
                                    <?php endif; ?>
                                </div>
                                <div class="two wide center aligned middle aligned column column-actions">
                                    <?php if ( $actived ) : ?>
                                        <?php if ( \mainwp_current_user_can( 'dashboard', 'activate_deactivate_themes' ) ) { ?>
                                            <a href="javascript:void(0)" disabled class="ui mini fluid <?php echo $is_demo ? 'disabled' : ''; ?> button"><?php esc_html_e( 'Deactivate', 'mainwp' ); ?></a>
                                        <?php } ?>
                                    <?php else : ?>
                                        <div class="ui mini fluid buttons">
                                            <?php if ( \mainwp_current_user_can( 'dashboard', 'activate_deactivate_themes' ) ) { ?>
                                            <a href="javascript:void(0)" class="mainwp-manages-theme-activate ui green <?php echo $is_demo ? 'disabled' : ''; ?> button"><?php esc_html_e( 'Activate', 'mainwp' ); ?></a>
                                            <?php } ?>
                                            <?php if ( \mainwp_current_user_can( 'dashboard', 'delete_themes' ) && ! $not_delete ) { ?>
                                            <a href="javascript:void(0)" class="mainwp-manages-theme-delete ui <?php echo $is_demo ? 'disabled' : ''; ?> button"><?php esc_html_e( 'Delete', 'mainwp' ); ?></a>
                                            <?php } ?>
                                        </div>
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
            jQuery( '.mainwp-manage-theme-accordion' ).accordion( {
                "selector": {
                    "trigger"   : '.dropdown-trigger',
                }
            } );

            jQuery( '.trigger-all-accordion' ).on( 'click', function() { // not use document here.
                if ( jQuery( this ).hasClass( 'active' ) ) {
                    jQuery( this ).removeClass( 'active' );
                    jQuery( '.mainwp-manage-themes-wrapper .ui.accordion div.title' ).each( function( i ) {
                        if ( jQuery( this ).hasClass( 'active' ) ) {
                            jQuery( this ).find('.dropdown-trigger').trigger( 'click' );
                        }
                    } );
                } else {
                    jQuery( this ).addClass( 'active' );
                    jQuery( '.mainwp-manage-themes-wrapper .ui.accordion div.title' ).each( function( i ) {
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
                mainwp_show_hide_install_to_selected_sites( 'theme' );
            });

        </script>

        <?php
        /**
         * Action: mainwp_after_themes_table
         *
         * Fires after the Themes table.
         *
         * @since 4.1
         */
        do_action( 'mainwp_after_themes_table' );
    }

    /**
     * Method render_manage_table()
     *
     * Render the Manage Themes table
     *
     * @param array  $sites List of sites.
     * @param array  $themesName List of themes.
     * @param array  $siteThemes List of themes for the site.
     * @param array  $themesSlug List of theme slugs.
     * @param array  $themesNameSites Installed theme version.
     * @param string $themesRealVersion Current theme version.
     * @param array  $roll_list roll items list.
     */
    public static function render_manage_table( $sites, $themesName, $siteThemes, $themesSlug, $themesNameSites, $themesRealVersion, $roll_list = array() ) { //phpcs:ignore -- NOSONAR - complex method.

        $userExtension        = MainWP_DB_Common::instance()->get_user_extension();
        $decodedIgnoredThemes = json_decode( $userExtension->ignored_themes, true );
        $trustedThemes        = json_decode( $userExtension->trusted_themes, true );
        $is_demo              = MainWP_Demo_Handle::is_demo_mode();

        if ( ! is_array( $trustedThemes ) ) {
            $trustedThemes = array();
        }

        $updateWebsites = array();

        foreach ( $sites as $site_id => $info ) {

            $theme_upgrades = array();
            $website        = MainWP_DB::instance()->get_website_by_id( $site_id );
            if ( $website && ! $website->is_ignoreThemeUpdates ) {
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

                if ( is_array( $decodedIgnoredThemes ) ) {
                    $theme_upgrades = array_diff_key( $theme_upgrades, $decodedIgnoredThemes );
                }
            }
            $updateWebsites[ $site_id ] = $theme_upgrades;
        }

        /**
         * Action: mainwp_before_themes_table
         *
         * Fires before the Themes table.
         *
         * @since 4.1
         */
        do_action( 'mainwp_before_themes_table' );
        ?>

        <div class="ui secondary segment main-master-checkbox">
            <div class="ui stackable grid">
                <div class="one wide left aligned middle aligned column">
                    <span class="trigger-all-accordion"><span class="trigger-handle-arrow"><i class="caret right icon"></i><i class="caret down icon"></i></span></span>
                </div>
                <div class="one wide center aligned middle aligned column"><div class="ui checkbox main-master  not-auto-init"><input type="checkbox"/><label></label></div></div>
                <div class="one wide center aligned middle aligned column"></div>
                <div class="five wide middle aligned column"><?php esc_html_e( 'Theme', 'mainwp' ); ?></div>
                <div class="two wide center aligned middle aligned column"></div>
                <div class="two wide right aligned middle aligned column"><?php esc_html_e( 'Latest Version', 'mainwp' ); ?></div>
                <div class="two wide center aligned middle aligned column"></div>
                <div class="two wide right aligned middle aligned column"><?php esc_html_e( 'Websites', 'mainwp' ); ?></div>
            </div>
        </div>
        <div class="mainwp-manage-themes-wrapper main-child-checkbox">
        <?php foreach ( $themesNameSites as $theme_title => $themeSites ) : ?>
            <?php
            $slugVersions     = current( $themeSites );
            $slug_ver_first   = $slugVersions[0]; // get the first one [slug]_[version] to get theme [slug].
            $theme_slug_first = $themesSlug[ $slug_ver_first ];

            $item_id = strtolower( $theme_title );
            $item_id = preg_replace( '/[[:space:]]+/', '_', $item_id );

            $count_sites     = count( $themeSites );
            $lastest_version = '';
            ?>
            <div class="ui accordion mainwp-manage-theme-accordion mainwp-manage-theme-item main-child-checkbox"  id="<?php echo esc_html( $item_id ); ?>">
                <div class="title master-checkbox">
                    <div class="ui stackable grid">
                        <div class="one wide left aligned middle aligned column"><i class="dropdown icon dropdown-trigger"></i></div>
                        <div class="one wide center aligned middle aligned column"><div class="ui checkbox master"><input type="checkbox"><label></label></div></div>
                        <div class="one wide center aligned middle aligned column"><?php echo MainWP_System_Utility::get_theme_icon( $theme_slug_first ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
                        <div class="five wide middle aligned column"><strong><?php echo esc_html( $theme_title ); ?></strong></div>
                        <div class="two wide center aligned middle aligned column"></div>
                        <div class="two wide right aligned middle aligned column lastest-version-info"></div>
                        <div class="two wide center aligned middle aligned column"></div>
                        <div class="two wide right aligned middle aligned column"><div class="ui label"><?php echo intval( $count_sites ) . ' ' . esc_html( _n( 'Site', 'Sites', intval( $count_sites ), 'mainwp' ) ); ?></div></div>
                    </div>
                </div>
            <div class="content child-checkbox">
                    <?php
                    foreach ( $themeSites as $site_id => $slugVersions ) :

                        $site_name = $sites[ $site_id ]['websitename'];

                        foreach ( $slugVersions as $slug_ver ) :

                            $theme_slug = $themesSlug[ $slug_ver ];
                            $trusted    = in_array( $theme_slug, $trustedThemes ) ? true : false;

                            $theme_version = $siteThemes[ $site_id ][ $slug_ver ]['version'];

                            $theme_upgrades = isset( $updateWebsites[ $site_id ] ) ? $updateWebsites[ $site_id ] : array();
                            if ( ! is_array( $theme_upgrades ) ) {
                                $theme_upgrades = array();
                            }

                            $upgradeInfo = isset( $theme_upgrades[ $theme_slug ] ) ? $theme_upgrades[ $theme_slug ] : false;

                            $active_status_class = '';
                            if ( isset( $siteThemes[ $site_id ][ $slug_ver ]['active'] ) && 1 === (int) $siteThemes[ $site_id ][ $slug_ver ]['active'] ) {
                                $active_status_class = 'positive';
                            } elseif ( isset( $siteThemes[ $site_id ][ $slug_ver ]['active'] ) && 0 === (int) $siteThemes[ $site_id ][ $slug_ver ]['active'] ) {
                                $active_status_class = 'negative';
                            } else {
                                $active_status_class = '';
                            }

                            if ( isset( $siteThemes[ $site_id ][ $slug_ver ]['child_active'] ) && 1 === (int) $siteThemes[ $site_id ][ $slug_ver ]['child_active'] ) {
                                $active_status_class .= ' child-active';
                            }

                            $not_delete = false;
                            $parent_str = '';
                            if ( isset( $siteThemes[ $site_id ][ $slug_ver ]['parent_active'] ) && 1 === (int) $siteThemes[ $site_id ][ $slug_ver ]['parent_active'] ) {
                                $parent_str = '<span data-tooltip="' . sprintf( esc_html__( 'Parent theme of the active theme (%s) on the site can not be deleted.', 'mainwp' ), isset( $siteThemes[ $site_id ][ $slug_ver ]['child_theme'] ) ? $siteThemes[ $site_id ][ $slug_ver ]['child_theme'] : '' ) . '" data-position="right center" data-inverted="" data-variation="mini"><i class="lock icon"></i></span>';
                                $not_delete = true;
                            }

                            $new_version = '';
                            if ( ! empty( $upgradeInfo ) && isset( $upgradeInfo['update']['new_version'] ) ) {
                                $new_version = $upgradeInfo['update']['new_version'];
                            }

                            if ( '' === $lastest_version || version_compare( $theme_version, $lastest_version, '>' ) ) {
                                $lastest_version = $theme_version;
                            }

                            if ( '' !== $new_version && version_compare( $new_version, $lastest_version, '>' ) ) {
                                $lastest_version = $new_version;
                            }

                            if ( isset( $siteThemes[ $site_id ][ $slug_ver ] ) && ( 0 === (int) $siteThemes[ $site_id ][ $slug_ver ]['active'] || 1 === (int) $siteThemes[ $site_id ][ $slug_ver ]['active'] ) ) {
                                $actived = true;

                                if ( isset( $siteThemes[ $site_id ][ $slug_ver ]['active'] ) && 1 === (int) $siteThemes[ $site_id ][ $slug_ver ]['active'] ) {
                                    $theme_status = '<span class="ui small green basic label">Active</span>';
                                } elseif ( isset( $siteThemes[ $site_id ][ $slug_ver ]['active'] ) && 0 === (int) $siteThemes[ $site_id ][ $slug_ver ]['active'] ) {
                                    $theme_status = '<span class="ui small red basic label">Inactive</span>';
                                    $actived      = false;
                                } else {
                                    $theme_status = '';
                                }

                                $item_id = $slug_ver . '_' . $site_id;
                                $item_id = strtolower( $item_id );
                                $item_id = preg_replace( '/[[:space:]]+/', '_', $item_id );

                                ?>
                            <div class="ui very compact stackable grid mainwp-manage-theme-item-website <?php echo esc_html( $active_status_class ); ?>"  updated="0" site-id="<?php echo intval( $site_id ); ?>" theme-slug="<?php echo esc_attr( $theme_slug ); ?>" theme-name="<?php echo esc_html( wp_strip_all_tags( $themesName[ $slug_ver ] ) ); ?>" site-id="<?php echo intval( $site_id ); ?>" site-name="<?php echo esc_html( $site_name ); ?>"  id="<?php echo esc_html( $item_id ); ?>" not-delete="<?php echo $not_delete ? 1 : 0; ?>" is-actived="<?php echo $actived ? 1 : 0; ?>" >
                                <div class="one wide center aligned middle aligned column"></div>
                                <div class="one wide left aligned middle aligned column">
                                    <?php if ( '' !== $parent_str ) : ?>
                                        <?php echo $parent_str; //phpcs:ignore -- escaped. ?>
                                    <?php else : ?>
                                        <?php if ( $actived ) { ?>
                                        <span data-tooltip="<?php echo esc_html__( 'Active theme on the site can not be deleted.', 'mainwp' ); ?>" data-position="right center" data-inverted="" data-variation="mini"><i class="lock icon"></i></span>
                                        <?php } ?>
                                        <div class="ui checkbox">
                                            <input type="checkbox"  class="mainwp-selected-theme-site" />
                                            <label></label>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="three wide middle aligned column"><a target="_blank" href="admin.php?page=SiteOpen&newWindow=yes&websiteid=<?php echo intval( $site_id ); ?>&_opennonce=<?php echo esc_html( wp_create_nonce( 'mainwp-admin-nonce' ) ); ?>"><i class="sign in icon"></i></a> <a href="admin.php?page=managesites&dashboard=<?php echo intval( $site_id ); ?>"><?php echo esc_html( $site_name ); ?></a></div>
                                <div class="one wide middle aligned column"></div>
                                <div class="one wide center aligned middle aligned column"><?php echo $theme_status; // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
                                <div class="two wide center aligned middle aligned column"><?php echo $trusted ? '<span class="ui tiny basic green label">' . esc_html__( 'Trusted', 'mainwp' ) . '</span>' : '<span class="ui tiny basic grey label">' . esc_html__( 'Not Trusted', 'mainwp' ) . '</span>'; ?></div>
                                <div class="one wide center aligned middle aligned column"></div>
                                <div class="two wide right aligned middle aligned column current-version">
                                    <?php echo esc_html( $theme_version ); ?>
                                    <?php if ( ! empty( $new_version ) ) : ?>
                                    &rarr;
                                        <?php
                                        if ( ! empty( $roll_list[ $site_id ][ $theme_slug ][ $lastest_version ] ) ) {
                                            echo MainWP_Updates_Helper::get_roll_msg( $roll_list[ $site_id ][ $theme_slug ][ $lastest_version ], true, 'notice' ); //phpcs:ignore -- NOSONAR -- ok.
                                        }
                                        echo esc_html( $new_version );
                                        ?>
                                    <?php endif; ?>
                                </div>
                                <div class="two wide right aligned middle aligned column update-column">
                                <?php if ( ! empty( $upgradeInfo ) && MainWP_Updates::user_can_update_themes() ) : ?>
                                    <a href="javascript:void(0)" class="ui mini green basic <?php echo $is_demo ? 'disabled' : ''; ?> button" onClick="return manage_themes_upgrade_theme( '<?php echo esc_js( rawurlencode( $theme_slug ) ); ?>', <?php echo esc_attr( $site_id ); ?> )"><?php esc_html_e( 'Update', 'mainwp' ); ?></a>
                                <?php endif; ?>
                                </div>
                                <div class="two wide center aligned middle aligned column column-actions">
                                <?php if ( $actived ) : ?>
                                            <?php if ( \mainwp_current_user_can( 'dashboard', 'activate_deactivate_themes' ) ) { ?>
                                                <a href="javascript:void(0)" disabled class="ui mini fluid <?php echo $is_demo ? 'disabled' : ''; ?> button"><?php esc_html_e( 'Deactivate', 'mainwp' ); ?></a>
                                            <?php } ?>
                                    <?php else : ?>
                                        <div class="ui mini fluid buttons">
                                        <?php if ( \mainwp_current_user_can( 'dashboard', 'activate_deactivate_themes' ) ) { ?>
                                            <a href="javascript:void(0)" class="mainwp-manages-theme-activate ui green <?php echo $is_demo ? 'disabled' : ''; ?> button"><?php esc_html_e( 'Activate', 'mainwp' ); ?></a>
                                        <?php } ?>
                                            <?php if ( \mainwp_current_user_can( 'dashboard', 'delete_themes' ) && ! $not_delete ) { ?>
                                            <a href="javascript:void(0)" class="mainwp-manages-theme-delete ui <?php echo $is_demo ? 'disabled' : ''; ?> button"><?php esc_html_e( 'Delete', 'mainwp' ); ?></a>
                                        <?php } ?>
                                        </div>
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
            jQuery( '.mainwp-manage-theme-accordion' ).accordion( {
                "selector": {
                    "trigger"   : '.dropdown-trigger',
                }
            } );

            jQuery( '.trigger-all-accordion' ).on( 'click', function() { // not use document here.
                if ( jQuery( this ).hasClass( 'active' ) ) {
                    jQuery( this ).removeClass( 'active' );
                    jQuery( '.mainwp-manage-themes-wrapper .ui.accordion div.title' ).each( function( i ) {
                        if ( jQuery( this ).hasClass( 'active' ) ) {
                            jQuery( this ).find('.dropdown-trigger').trigger( 'click' );
                        }
                    } );
                } else {
                    jQuery( this ).addClass( 'active' );
                    jQuery( '.mainwp-manage-themes-wrapper .ui.accordion div.title' ).each( function( i ) {
                        if ( !jQuery( this ).hasClass( 'active' ) ) {
                            jQuery( this ).find('.dropdown-trigger').trigger( 'click' );
                        }
                    } );
                }
                return false;
            } );

            jQuery(function($) {
                $('.lastest-version-hidden').each(function(){
                    $(this).closest('.mainwp-manage-theme-item').find('.lastest-version-info').html($(this).attr('lastest-version'));
                });
                mainwp_master_checkbox_init($);
                mainwp_get_icon_start();
                mainwp_show_hide_install_to_selected_sites( 'theme' );
            });

        </script>

        <?php
        /**
         * Action: mainwp_after_themes_table
         *
         * Fires after the Themes table.
         *
         * @since 4.1
         */
        do_action( 'mainwp_after_themes_table' );
    }

    /**
     * Render the bulk actions UI.
     *
     * @param mixed $status Theme status.
     *
     * @return mixed $bulkActions
     */
    public static function render_bulk_actions( $status ) { // phpcs:ignore -- NOSONAR - complex.
        ob_start();
        ?>
        <select class="ui dropdown" id="mainwp-bulk-actions">
            <option value="none"><?php esc_html_e( 'Bulk Actions', 'mainwp' ); ?></option>
                <?php if ( \mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
                    <option data-value="ignore_updates" value="ignore_updates"><?php esc_html_e( 'Ignore updates', 'mainwp' ); ?></option>
                <?php endif; ?>
                <?php if ( \mainwp_current_user_can( 'dashboard', 'activate_themes' ) ) : ?>
                    <?php if ( 'inactive' === $status ) : ?>
                    <option data-value="activate" value="activate"><?php esc_html_e( 'Activate', 'mainwp' ); ?></option>
                    <?php else : ?>
                        <option data-value="activate" disabled value="activate"><?php esc_html_e( 'Activate', 'mainwp' ); ?></option>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ( 'inactive' === $status ) : ?>
                    <?php if ( \mainwp_current_user_can( 'dashboard', 'delete_themes' ) ) : ?>
                    <option data-value="delete" value="delete"><?php esc_html_e( 'Delete', 'mainwp' ); ?></option>
                    <?php endif; ?>
                <?php else : ?>
                    <?php if ( \mainwp_current_user_can( 'dashboard', 'delete_themes' ) ) : ?>
                        <option data-value="delete" disabled value="delete"><?php esc_html_e( 'Delete', 'mainwp' ); ?></option>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ( 'all' === $status ) : ?>
                    <?php if ( \mainwp_current_user_can( 'dashboard', 'activate_themes' ) ) : ?>
                        <option data-value="activate" disabled value="activate"><?php esc_html_e( 'Activate', 'mainwp' ); ?></option>
                    <?php endif; ?>
                    <?php if ( \mainwp_current_user_can( 'dashboard', 'delete_themes' ) ) : ?>
                        <option data-value="delete" disabled value="delete"><?php esc_html_e( 'Delete', 'mainwp' ); ?></option>
                <?php endif; ?>
                <?php endif; ?>

                <?php
                /**
                 * Action: mainwp_themes_bulk_action
                 *
                 * Adds a new action to the Manage Themes bulk actions menu.
                 *
                 * @param string $status Status search parameter.
                 *
                 * @since 4.1
                 */
                do_action( 'mainwp_themes_bulk_action' );
                ?>
        </select>
        <button class="ui mini basic button" href="javascript:void(0)" id="mainwp-do-themes-bulk-actions"><?php esc_html_e( 'Apply', 'mainwp' ); ?></button>
        <span id="mainwp_bulk_action_loading"><i class="ui active inline loader tiny"></i></span>
        <?php
        return ob_get_clean();
    }


    /** Render the Install Themes Tab. */
    public static function render_install() {
        wp_enqueue_script( 'mainwp-theme', MAINWP_PLUGIN_URL . 'assets/js/mainwp-theme.js', array( 'wp-backbone', 'wp-a11y' ), MAINWP_VERSION, true );
        wp_localize_script(
            'mainwp-theme',
            '_mainwpThemeSettings',
            array(
                'themes'          => false,
                'settings'        => array(
                    'isInstall'  => true,
                    'canInstall' => false,
                    'installURI' => null,
                    'adminUrl'   => wp_parse_url( self_admin_url(), PHP_URL_PATH ),
                ),
                'l10n'            => array(
                    'addNew'            => esc_html__( 'Add new theme' ),
                    'search'            => esc_html__( 'Search themes' ),
                    'searchPlaceholder' => esc_html__( 'Search themes...' ),
                    'upload'            => esc_html__( 'Upload theme' ),
                    'back'              => esc_html__( 'Back' ),
                    'error'             => esc_html__( 'An unexpected error occurred. Something may be wrong with WordPress.org or this server&#8217;s configuration. If you continue to have problems, please try the <a href="https://wordpress.org/support/">support forums</a>.' ),
                    'themesFound'       => esc_html__( 'Number of themes found: %d' ),
                    'noThemesFound'     => esc_html__( 'No themes found. Try a different search.' ),
                    'collapseSidebar'   => esc_html__( 'Collapse sidebar' ),
                    'expandSidebar'     => esc_html__( 'Expand sidebar' ),
                ),
                'installedThemes' => array(),
            )
        );
        static::render_header( 'Install' );
        static::render_themes_table();
        static::render_footer( 'Install' );
    }

    /**
     * Render the Themes table for the Install Themes Tab.
     *
     * @uses \MainWP\Dashboard\MainWP_UI::render_modal_install_plugin_theme()
     *
     * @uses \MainWP\Dashboard\MainWP_Install_Bulk::render_upload()
     */
    public static function render_themes_table() {
        if ( ! \mainwp_current_user_can( 'dashboard', 'install_themes' ) ) {
            \mainwp_do_not_have_permissions( esc_html__( 'install themes', 'mainwp' ) );
            return;
        }
        ?>
        <div class="ui alt segment" id="mainwp-install-themes">
            <div class="mainwp-main-content">
                <div class="mainwp-actions-bar">
                    <div class="ui stackable two column grid">

                        <div class="column">
                                    <div class="ui mini buttons">
                                        <a href="#" class="ui basic button browse-themes" ><?php esc_html_e( 'Install from WordPress.org', 'mainwp' ); ?></a>
                                        <a href="#" class="ui basic button upload" ><?php esc_html_e( 'Upload .zip file', 'mainwp' ); ?></a>
                                        <?php do_action( 'mainwp_install_plugin_theme_tabs_header_top', 'theme' ); ?>
                                    </div>
                                <?php
                                /**
                                 * Install Themes actions bar (right)
                                 *
                                 * Fires at the right side of the actions bar on the Install Themes screen.
                                 *
                                 * @since 4.0
                                 */
                                do_action( 'mainwp_install_themes_actions_bar_right' );
                                ?>
                            </div>
                            <div class="right aligned column">
                                <div class="ui search focus">
                                    <div class="ui icon mini input mainwp-bulk-install-showhide-content" id="mainwp-search-themes-input-container" skeyword="<?php echo isset( $_GET['s'] ) ? esc_html( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized ?>"></div>
                                    <div class="results"></div>
                                </div>
                                <?php
                                /**
                                 * Install Themes actions bar (left)
                                 *
                                 * Fires at the left side of the actions bar on the Install Themes screen, after the search form.
                                 *
                                 * @since 4.0
                                 */
                                do_action( 'mainwp_install_themes_actions_bar_left' );
                                ?>
                            </div>


                    </div>
                </div>
                <div class="ui segment">
                    <?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-install-themes-info-message' ) ) : ?>
                        <div class="ui info message">
                            <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-install-themes-info-message"></i>
                            <?php printf( esc_html__( 'Install themes on your child sites.  You can install themes from the WordPress.org repository or by uploading a ZIP file.  For additional help, please check this %1$shelp documentation%2$s.', 'mainwp' ), '<a href="https://kb.mainwp.com/docs/install-themes/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); ?>
                        </div>
                    <?php endif; ?>
                    <div id="mainwp-message-zone" class="ui message" style="display:none;"></div>
                    <div class="mainwp-upload-theme mainwp-bulk-install-showhide-content">
                        <?php MainWP_Install_Bulk::render_upload( 'theme' ); ?>
                    </div>
                    <div id="themes-loading" class="ui large text loader"><?php esc_html_e( 'Loading Themes...', 'mainwp' ); ?></div>
                    <form id="theme-filter" method="post" class="mainwp-bulk-install-showhide-content">
                        <?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
                        <div class="mainwp-browse-themes content-filterable"></div>
                        <div class="theme-install-overlay wp-full-overlay expanded"></div>
                    </form>
                    <?php
                    // @since 5.4.
                    do_action( 'mainwp_bulk_install_tabs_content', 'theme' );
                    ?>
                    <?php MainWP_UI::render_modal_install_plugin_theme( 'theme' ); ?>
                </div>
            </div>
            <div class="mainwp-side-content mainwp-no-padding">
                <?php do_action( 'mainwp_manage_themes_sidebar_top' ); ?>
                <div class="mainwp-select-sites ui accordion mainwp-sidebar-accordion">
                    <?php do_action( 'mainwp_manage_themes_before_select_sites' ); ?>
                    <div class="active title"><i class="dropdown icon"></i> <?php esc_html_e( 'Select Sites', 'mainwp' ); ?></div>
                    <div class="content active">
                    <?php
                    $selected_sites = array();

                    if ( isset( $_GET['selected_sites'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                        $selected_sites = explode( '-', sanitize_text_field( wp_unslash( $_GET['selected_sites'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize ok.
                        $selected_sites = array_map( 'intval', $selected_sites );
                        $selected_sites = array_filter( $selected_sites );
                    }
                    ?>
                    <?php
                    $sel_params = array(
                        'class'          => 'mainwp_select_sites_box_left',
                        'selected_sites' => $selected_sites,
                        'show_client'    => true,
                    );
                    MainWP_UI_Select_Sites::select_sites_box( $sel_params );
                    ?>
                    </div>
                    <?php do_action( 'mainwp_manage_themes_after_select_sites' ); ?>
                </div>
                <div class="ui fitted divider"></div>
                <div class="mainwp-search-options ui accordion mainwp-sidebar-accordion">
                    <?php do_action( 'mainwp_manage_themes_before_search_options' ); ?>
                    <div class="title active"><i class="dropdown icon"></i> <?php esc_html_e( 'Installation Options', 'mainwp' ); ?></div>
                    <div class="content active">
                    <div class="ui form">
                        <div class="field">
                            <div class="ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'If enabled and the theme already installed on the sites, the already installed version will be overwritten.', 'mainwp' ); ?>" data-position="left center" data-inverted="">
                                <input type="checkbox" value="2" checked="checked" id="chk_overwrite" />
                                <label for="chk_overwrite"><?php esc_html_e( 'Overwrite existing version', 'mainwp' ); ?></label>
                            </div>
                        </div>
                    </div>
                    </div>
                    <?php do_action( 'mainwp_manage_themes_after_search_options' ); ?>
                </div>
                <div class="ui fitted divider"></div>
                <div class="mainwp-search-submit">
                    <?php do_action( 'mainwp_manage_themes_before_submit_button' ); ?>
                <?php
                /**
                 * Disables themes installation
                 *
                 * Filters whether file modifications are allowed on the Dashboard site. If not, installation process will be disabled too.
                 *
                 * @since 4.1
                 */
                $allow_install = apply_filters( 'file_mod_allowed', true, 'mainwp_install_theme' );
                if ( $allow_install ) {
                    $is_demo = MainWP_Demo_Handle::is_demo_mode();
                    if ( $is_demo ) {
                        MainWP_Demo_Handle::get_instance()->render_demo_disable_button( '<input type="button" disabled="disabled" class="ui green big fluid button disabled" value="' . esc_attr__( 'Complete Installation', 'mainwp' ) . '" />' );
                    } else {
                        ?>
                        <input type="button" value="<?php esc_attr_e( 'Complete Installation', 'mainwp' ); ?>" class="ui green big fluid button" bulk-action="install" id="mainwp_theme_bulk_install_btn" name="bulk-install">
                        <?php
                    }
                }
                ?>
                <?php do_action( 'mainwp_manage_themes_after_submit_button' ); ?>
                <?php
                // @since 5.4.
                do_action( 'mainwp_bulk_install_sidebar_submit_bottom', 'theme' );
                ?>
                </div>
                <?php do_action( 'mainwp_manage_themes_sidebar_bottom', 'install' ); ?>
            </div>
            <div class="ui clearing hidden divider"></div>
        </div>
        <?php
        $favorites        = '';
        $favorites_themes = apply_filters( 'mainwp_favorites_themes', array() );
        if ( is_array( $favorites_themes ) && ! empty( $favorites_themes ) ) {
            $favorites = wp_json_encode( $favorites_themes );
        }
        ?>
        <div id="mainwp-favorites-themes" favorites-themes="<?php echo esc_attr( $favorites ); ?>"></div>
        <script id="tmpl-theme" type="text/template">
                <# if ( data.screenshot_url ) { #>
                <div class="image">
                    <img src="{{ data.screenshot_url }}" alt="{{ data.name }}" />
                </div>
                <# } #>
                <div class="content">
                    <div class="header">{{ data.name }}</div>
                    <div class="meta">
                        <a><?php printf( esc_html__( 'By %s', 'mainwp' ), '{{ data.author }}' ); ?></a>
                    </div>
                </div>
                <div class="extra content">
                    <span class="right floated"><?php printf( esc_html__( 'Version: %s', 'mainwp' ), '{{ data.version }}' ); ?></span>
                    <# if ( data.rating ) { #>
                        <div class="star-rating rating-{{ Math.round( data.rating / 10 ) * 10 }}">
                            <span class="one"></span><span class="two"></span><span class="three"></span><span class="four"></span><span class="five"></span>
                            <small class="ratings">{{ data.num_ratings }}</small>
                        </div>
                    <# } else { #>
                        <div class="star-rating">
                            <small class="ratings"><?php esc_html_e( 'This theme has not been rated yet.', 'mainwp' ); ?></small>
                        </div>
                    <# } #>
                </div>
                <div class="extra content mainwp-theme-lnks">
                    <a href="#" id="mainwp-{{data.slug}}-preview" class="ui mini button mainwp-theme-preview"><?php esc_html_e( 'Preview', 'mainwp' ); ?></a>
                    <div class="ui radio checkbox right floated">
                        <input name="install-theme" type="radio" id="install-theme-{{data.slug}}" title="Install {{data.name}}" theme-name="{{data.name}}" theme-version="{{data.version}}">
                        <label for="install-theme-{{data.slug}}"><?php esc_html_e( 'Install Theme', 'mainwp' ); ?></label>
                    </div>
                </div>
                <?php do_action( 'mainwp_install_theme_card_template_bottom' ); ?>
        </script>

        <script id="tmpl-theme-preview" type="text/template">
            <div class="wp-full-overlay-sidebar">
                <div class="wp-full-overlay-header">
                    <a href="#" class="close-full-overlay"><span class="screen-reader-text"><?php esc_html_e( 'Close', 'mainwp' ); ?></span></a>
                    <a href="#" class="previous-theme"><span class="screen-reader-text"><?php esc_html_e( 'Previous', 'mainwp' ); ?></span></a>
                    <a href="#" class="next-theme"><span class="screen-reader-text"><?php esc_html_e( 'Next', 'mainwp' ); ?></span></a>
                </div>
                <div class="wp-full-overlay-sidebar-content">
                    <div class="install-theme-info">
                        <h3 class="theme-name">{{ data.name }}</h3>
                        <span class="theme-by"><?php printf( esc_html__( 'By %s', 'mainwp' ), '{{ data.author }}' ); ?></span>
                        <img class="theme-screenshot" src="{{ data.screenshot_url }}" alt="{{ data.name }}" />
                        <div class="theme-details">
                            <# if ( data.rating ) { #>
                                <div class="star-rating rating-{{ Math.round( data.rating / 10 ) * 10 }}">
                                    <span class="one"></span><span class="two"></span><span class="three"></span><span class="four"></span><span class="five"></span>
                                    <small class="ratings">{{ data.num_ratings }}</small>
                                </div>
                            <# } else { #>
                                <div class="star-rating">
                                    <small class="ratings"><?php esc_html_e( 'This theme has not been rated yet.', 'mainwp' ); ?></small>
                                </div>
                            <# } #>
                            <div class="theme-version"><?php printf( esc_html__( 'Version: %s', 'mainwp' ), '{{ data.version }}' ); ?></div>
                            <div class="theme-description">{{{ data.description }}}</div>
                        </div>
                    </div>
                </div>
                <div class="wp-full-overlay-footer">
                    <button type="button" class="collapse-sidebar button-secondary" aria-expanded="true" aria-label="<?php esc_attr_e( 'Collapse Sidebar', 'mainwp' ); ?>">
                        <span class="collapse-sidebar-arrow"></span>
                        <span class="collapse-sidebar-label"><?php esc_html_e( 'Collapse', 'mainwp' ); ?></span>
                    </button>
                </div>
            </div>
            <div class="wp-full-overlay-main">
                <iframe src="{{ data.preview_url }}" title="<?php esc_attr_e( 'Preview', 'mainwp' ); ?>" />
            </div>
        </script>
        <script type="text/javascript">
            jQuery( document ).ready( function() {
                setTimeout( function () {
                    jQuery('#wp-filter-search-input').val( jQuery('#mainwp-search-themes-input-container').attr('skeyword') );
                }, 1000 );
            });
        </script>
        <?php
    }

    /**
     * Render the Themes Auto Update Tab.
     *
     * @uses \MainWP\Dashboard\MainWP_UI::render_modal_edit_notes()
     */
    public static function render_auto_update() { // phpcs:ignore -- NOSONAR -Current complexity is the only way to achieve desired results, pull request solutions appreciated.

        $cachedThemesSearch = null;
        if ( isset( $_SESSION['SNThemesAllStatus'] ) ) {
            $cachedThemesSearch = $_SESSION['SNThemesAllStatus']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        }

        static::render_header( 'AutoUpdate' );

        if ( ! \mainwp_current_user_can( 'dashboard', 'trust_untrust_updates' ) ) {
            \mainwp_do_not_have_permissions( esc_html__( 'trust/untrust updates', 'mainwp' ) );
            return;
        } else {
            $snThemeAutomaticDailyUpdate = get_option( 'mainwp_themeAutomaticDailyUpdate' );

            if ( false === $snThemeAutomaticDailyUpdate ) {
                $snThemeAutomaticDailyUpdate = get_option( 'mainwp_automaticDailyUpdate' );
                update_option( 'mainwp_themeAutomaticDailyUpdate', $snThemeAutomaticDailyUpdate );
            }

            ?>
            <div class="ui alt segment" id="mainwp-theme-auto-updates">
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
                                                 * Action: mainwp_themes_auto_updates_bulk_action
                                                 *
                                                 * Adds new action to the bulk actions menu on Themes Auto Updates.
                                                 *
                                                 * @since 4.1
                                                 */
                                                do_action( 'mainwp_themes_auto_updates_bulk_action' );
                                                ?>
                                        </select>
                                        <input type="button" name="" id="mainwp-bulk-trust-themes-action-apply" class="ui mini basic button" value="<?php esc_attr_e( 'Apply', 'mainwp' ); ?>"/>
                                    </div>
                                <div class="right aligned column"></div>
                            </div>
                        </div>
                    </div>
                    <?php if ( isset( $_GET['message'] ) && 'saved' === $_GET['message'] ) : // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized ?>
                        <div class="ui message green"><?php esc_html_e( 'Settings have been saved.', 'mainwp' ); ?></div>
                    <?php endif; ?>
                    <div id="mainwp-message-zone" class="ui message" style="display:none"></div>
                    <div id="mainwp-auto-updates-themes-content" class="ui segment">
                        <?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-disable-auto-updates-info-message' ) ) : ?>
                        <div class="ui info message">
                            <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-disable-auto-updates-info-message"></i>
                            <div><?php printf( esc_html__( 'Check out %1$show to disable the WordPress built in auto-updates feature%2$s.', 'mainwp' ), '<a href="https://mainwp.com/how-to-disable-automatic-plugin-and-theme-updates-on-your-child-sites/" target="_blank">', '</a>' ); // NOSONAR - noopener - open safe. ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-themes-auto-updates-info-message' ) ) : ?>
                        <div class="ui info message">
                            <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-themes-auto-updates-info-message"></i>
                            <div><?php esc_html_e( 'The MainWP Advanced Auto Updates feature is a tool for your Dashboard to automatically update themes that you trust to be updated without breaking your Child sites.', 'mainwp' ); ?></div>
                            <div><?php esc_html_e( 'Only mark themes as trusted if you are absolutely sure they can be automatically updated by your MainWP Dashboard without causing issues on the Child sites!', 'mainwp' ); ?></div>
                            <div><strong><?php esc_html_e( 'Advanced Auto Updates a delayed approximately 24 hours from the update release. Ignored themes can not be automatically updated.', 'mainwp' ); ?></strong></div>
                        </div>
                        <?php endif; ?>
                        <div class="ui inverted dimmer">
                            <div class="ui text loader"><?php esc_html_e( 'Loading themes', 'mainwp' ); ?></div>
                        </div>
                        <div id="mainwp-auto-updates-themes-table-wrapper">
                            <?php
                            if ( isset( $_SESSION['SNThemesAll'] ) ) {
                                static::render_all_themes_table( $_SESSION['SNThemesAll'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                            }
                            ?>
                        </div>
                    </div>
                </div>
                <div class="mainwp-side-content mainwp-no-padding">
                    <?php do_action( 'mainwp_manage_themes_sidebar_top' ); ?>
                    <div class="mainwp-search-options ui accordion mainwp-sidebar-accordion">
                        <div class="title active"><i class="dropdown icon"></i> <?php esc_html_e( 'Theme Status to Search', 'mainwp' ); ?></div>
                        <div class="content active">
                        <div class="ui mini form">
                            <div class="field">
                                <select class="ui fluid dropdown" id="mainwp_au_theme_status">
                                    <option value="all" <?php echo ( null !== $cachedThemesSearch && 'all' === $cachedThemesSearch['theme_status'] ) ? 'selected' : ''; ?>><?php esc_html_e( 'Active and Inactive', 'mainwp' ); ?></option>
                                    <option value="active" <?php echo ( null !== $cachedThemesSearch && 'active' === $cachedThemesSearch['theme_status'] ) ? 'selected' : ''; ?>><?php esc_html_e( 'Active', 'mainwp' ); ?></option>
                                    <option value="inactive" <?php echo ( null !== $cachedThemesSearch && 'inactive' === $cachedThemesSearch['theme_status'] ) ? 'selected' : ''; ?>><?php esc_html_e( 'Inactive', 'mainwp' ); ?></option>
                                </select>
                            </div>
                        </div>
                    </div>
                    </div>
                    <div class="ui fitted divider"></div>
                    <div class="mainwp-search-options ui accordion mainwp-sidebar-accordion">
                        <?php do_action( 'mainwp_manage_themes_before_search_options' ); ?>
                        <div class="title active"><i class="dropdown icon"></i> <?php esc_html_e( 'Search Options', 'mainwp' ); ?></div>
                        <div class="content active">
                        <div class="ui mini form">
                            <div class="field">
                                <select class="ui fluid dropdown" id="mainwp_au_theme_trust_status">
                                    <option value="all" <?php echo ( null !== $cachedThemesSearch && 'all' === $cachedThemesSearch['status'] ) ? 'selected' : ''; ?>><?php esc_html_e( 'Trusted, Not trusted and Ignored', 'mainwp' ); ?></option>
                                    <option value="trust" <?php echo ( null !== $cachedThemesSearch && 'trust' === $cachedThemesSearch['status'] ) ? 'selected' : ''; ?>><?php esc_html_e( 'Trusted', 'mainwp' ); ?></option>
                                    <option value="untrust" <?php echo ( null !== $cachedThemesSearch && 'untrust' === $cachedThemesSearch['status'] ) ? 'selected' : ''; ?>><?php esc_html_e( 'Not trusted', 'mainwp' ); ?></option>
                                    <option value="ignored" <?php echo ( null !== $cachedThemesSearch && 'ignored' === $cachedThemesSearch['status'] ) ? 'selected' : ''; ?>><?php esc_html_e( 'Ignored', 'mainwp' ); ?></option>
                                </select>
                            </div>
                            <div class="field">
                                <div class="ui input fluid">
                                    <input type="text" placeholder="<?php esc_attr_e( 'Theme name', 'mainwp' ); ?>" id="mainwp_au_theme_keyword" class="text" value="<?php echo ( null !== $cachedThemesSearch ) ? esc_attr( $cachedThemesSearch['keyword'] ) : ''; ?>">
                                </div>
                            </div>
                        </div>
                        </div>
                        <?php do_action( 'mainwp_manage_themes_after_search_options' ); ?>
                    </div>
                    <div class="ui fitted divider"></div>
                    <div class="mainwp-search-submit">
                        <?php do_action( 'mainwp_manage_themes_before_submit_button' ); ?>
                        <a href="#" class="ui green big fluid button" id="mainwp_show_all_active_themes"><?php esc_html_e( 'Show Themes', 'mainwp' ); ?></a>
                        <?php do_action( 'mainwp_manage_themes_after_submit_button' ); ?>
                    </div>
                    <?php do_action( 'mainwp_manage_themes_sidebar_bottom' ); ?>
                </div>
            </div>
            <?php
        }
        MainWP_UI::render_modal_edit_notes( 'theme' );
        static::render_footer( 'AutoUpdate' );
    }

    /**
     * Method render_all_themes_table()
     *
     * Render the All Themes Table.
     *
     * @param null $output Function output.
     *
     * @return void
     *
     * @uses \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
     * @uses \MainWP\Dashboard\MainWP_DB_Common::get_user_extension()
     * @uses \MainWP\Dashboard\MainWP_DB::query()
     * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_for_current_user()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_DB::free_result()
     * @uses \MainWP\Dashboard\MainWP_Themes_Handler::get_class_name()
     * @uses \MainWP\Dashboard\MainWP_Utility::map_site()
     * @uses \MainWP\Dashboard\MainWP_Utility::get_nice_url()
     */
    public static function render_all_themes_table( $output = null ) { // phpcs:ignore -- NOSONAR - complex.
        $keyword       = null;
        $search_status = 'all';

        $data_fields = MainWP_System_Utility::get_default_map_site_fields();

        if ( null === $output ) {
            // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $keyword             = isset( $_POST['keyword'] ) && ! empty( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : null;
            $search_status       = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : 'all';
            $search_theme_status = isset( $_POST['theme_status'] ) ? sanitize_text_field( wp_unslash( $_POST['theme_status'] ) ) : 'all';
            // phpcs:enable

            $output                   = new \stdClass();
            $output->errors           = array();
            $output->themes           = array();
            $output->themes_installed = array();
            $output->status           = $search_theme_status;

            if ( 1 === (int) get_option( 'mainwp_optimize', 1 ) || MainWP_Demo_Handle::is_demo_mode() ) {
                $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );
                while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
                    $allThemes = json_decode( $website->themes, true );
                    $_count    = count( $allThemes );
                    for ( $i = 0; $i < $_count; $i++ ) {
                        $theme = $allThemes[ $i ];
                        if ( 'all' !== $search_theme_status ) {
                            if ( 1 === (int) $theme['active'] && 'active' !== $search_theme_status ) {
                                continue;
                            }
                            if ( 1 !== $theme['active'] && 'inactive' !== $search_theme_status ) {
                                continue;
                            }
                        }
                        if ( ! empty( $keyword ) ) {
                            $keyword   = trim( $keyword );
                            $multi_kws = explode( ',', $keyword );
                            $multi_kws = array_filter( array_map( 'trim', $multi_kws ) );
                            if ( ! MainWP_Utility::multi_find_keywords( $theme['name'], $multi_kws ) ) {
                                continue;
                            }
                        }
                        $theme['websiteid']  = $website->id;
                        $theme['websiteurl'] = $website->url;
                        $output->themes[]    = $theme;
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

                if ( 'active' === $search_theme_status || 'inactive' === $search_theme_status ) {
                    $post_data['status'] = $search_theme_status;
                    $post_data['filter'] = true;
                } else {
                    $post_data['status'] = '';
                    $post_data['filter'] = false;
                }

                MainWP_Connect::fetch_urls_authed( $dbwebsites, 'get_all_themes', $post_data, array( MainWP_Themes_Handler::get_class_name(), 'themes_search_handler' ), $output );

                if ( ! empty( $output->errors ) ) {
                    foreach ( $output->errors as $siteid => $error ) {
                        echo MainWP_Utility::get_nice_url( $dbwebsites[ $siteid ]->url ) . ' - ' . $error . ' <br/>'; // phpcs:ignore WordPress.Security.EscapeOutput
                    }
                    echo '<div class="ui hidden divider"></div>';
                }

                if ( count( $output->errors ) === count( $dbwebsites ) ) {
                    $_SESSION['SNThemesAll'] = $output;
                    return;
                }
            }

            $_SESSION['SNThemesAll']       = $output;
            $_SESSION['SNThemesAllStatus'] = array(
                'keyword'      => $keyword,
                'status'       => $search_status,
                'theme_status' => $search_theme_status,
            );
        } elseif ( isset( $_SESSION['SNThemesAllStatus'] ) ) {
            //phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $search_status = isset( $_SESSION['SNThemesAllStatus']['status'] ) ? $_SESSION['SNThemesAllStatus']['status'] : '';
            //phpcs:enable
        }

        if ( empty( $output->themes ) ) {
            ?>
            <div class="ui message yellow"><?php esc_html_e( 'No themes found.', 'mainwp' ); ?></div>
            <?php
            return;
        }

        $themes = array();
        foreach ( $output->themes as $theme ) {
            $themes[ $theme['slug'] ] = $theme;
        }
        asort( $themes );

        $userExtension        = MainWP_DB_Common::instance()->get_user_extension();
        $decodedIgnoredThemes = json_decode( $userExtension->ignored_themes, true );
        $trustedThemes        = json_decode( $userExtension->trusted_themes, true );
        if ( ! is_array( $trustedThemes ) ) {
            $trustedThemes = array();
        }
        $trustedThemesNotes = json_decode( $userExtension->trusted_themes_notes, true );
        if ( ! is_array( $trustedThemesNotes ) ) {
            $trustedThemesNotes = array();
        }
        static::render_all_themes_html( $themes, $search_status, $trustedThemes, $trustedThemesNotes, $decodedIgnoredThemes );
    }

    /**
     * Render all themes html.
     *
     * @param mixed $themes Themes list.
     * @param mixed $search_status Search status.
     * @param mixed $trustedThemes Trusted themes.
     * @param mixed $trustedThemesNotes Trusted themes notes.
     * @param mixed $decodedIgnoredThemes Decoded ignored themes.
     *
     * @uses \MainWP\Dashboard\MainWP_Utility::esc_content()
     */
    public static function render_all_themes_html( $themes, $search_status, $trustedThemes, $trustedThemesNotes, $decodedIgnoredThemes ) { // phpcs:ignore -- NOSONAR - complex.

        /**
         * Action: mainwp_themes_before_auto_updates_table
         *
         * Fires before the Auto Update Themes table.
         *
         * @since 4.1
         */
        do_action( 'mainwp_themes_before_auto_updates_table' );
        ?>
        <table class="ui unstackable table" id="mainwp-all-active-themes-table">
            <thead>
                <tr>
                    <th scope="col"  class="no-sort collapsing check-column"><span class="ui checkbox"><input id="cb-select-all-top" type="checkbox" /></span></th>
                    <th scope="col" data-priority="1"><?php esc_html_e( 'Theme', 'mainwp' ); ?></th>
                    <th scope="col" ><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
                    <th scope="col"  data-priority="2" class="collapsing"><?php esc_html_e( 'Trust Status', 'mainwp' ); ?></th>
                    <th scope="col" ><?php esc_html_e( 'Ignored Status', 'mainwp' ); ?></th>
                    <th scope="col" class="collapsing"></th>
                    <th scope="col" class="collapsing"><?php esc_html_e( 'Notes', 'mainwp' ); ?></th>
                </tr>
            </thead>

            <tbody>
            <?php foreach ( $themes as $slug => $theme ) : ?>
                <?php
                $name = esc_html( $theme['name'] );
                if ( ! empty( $search_status ) && 'all' !== $search_status ) {
                    if ( 'trust' === $search_status && ! in_array( $slug, $trustedThemes ) ) {
                        continue;
                    }
                    if ( 'untrust' === $search_status && in_array( $slug, $trustedThemes ) ) {
                        continue;
                    }
                    if ( 'ignored' === $search_status && ! isset( $decodedIgnoredThemes[ $slug ] ) ) {
                        continue;
                    }
                }

                $esc_note   = '';
                $strip_note = '';
                if ( isset( $trustedThemesNotes[ $slug ] ) ) {
                    $esc_note   = MainWP_Utility::esc_content( $trustedThemesNotes[ $slug ] );
                    $strip_note = wp_strip_all_tags( $esc_note );
                }

                ?>
                <tr theme-slug="<?php echo esc_attr( rawurlencode( $slug ) ); ?>" theme-name="<?php echo esc_attr( $name ); ?>">
                    <td class="check-column"><span class="ui checkbox"><input type="checkbox" name="theme[]" value="<?php echo esc_attr( rawurlencode( $slug ) ); ?>"></span></td>
                    <td><?php echo MainWP_System_Utility::get_theme_icon( $slug ) . '&nbsp;&nbsp;&nbsp;&nbsp;' . esc_html( $name ); //phpcs:ignore -- escaped. ?></td>
                    <td><?php echo ( 1 === (int) $theme['active'] ) ? esc_html__( 'Active', 'mainwp' ) : esc_html__( 'Inactive', 'mainwp' ); //phpcs:ignore -- escaped. ?></td>
                    <td><?php echo ( in_array( $slug, $trustedThemes ) ) ? '<span class="ui mini green fluid center aligned label">' . esc_html__( 'Trusted', 'mainwp' ) . '</span>' : '<span class="ui mini red fluid center aligned label">' . esc_html__( 'Not Trusted', 'mainwp' ) . '</span>'; ?></td>
                    <td><?php echo ( isset( $decodedIgnoredThemes[ $slug ] ) ) ? '<span class="ui mini label">' . esc_html__( 'Ignored', 'mainwp' ) . '</span>' : ''; ?></td>
                    <td><?php echo ( isset( $decodedIgnoredThemes[ $slug ] ) ) ? '<span data-tooltip="Ignored themes will not be automatically updated." data-inverted=""><i class="info red circle icon"></i></span>' : ''; ?></td>
                    <td class="collapsing center aligned">
                    <?php if ( '' === $esc_note ) : ?>
                        <a href="javascript:void(0)" class="mainwp-edit-theme-note"><i class="sticky note outline icon"></i></a>
                    <?php else : ?>
                        <a href="javascript:void(0)" class="mainwp-edit-theme-note" data-tooltip="<?php echo substr( $strip_note, 0, 100 ); //phpcs:ignore -- escaped. ?>" data-position="left center" data-inverted=""><i class="sticky green note icon"></i></a>
                    <?php endif; ?>
                        <span style="display: none" class="esc-content-note"><?php echo $esc_note; //phpcs:ignore -- escaped. ?></span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>

            <tfoot>
                <tr>
                    <th scope="col" class="no-sort check-column"><span class="ui checkbox"><input id="cb-select-all-bottom" type="checkbox" /></span></th>
                    <th scope="col" ><?php esc_html_e( 'Theme', 'mainwp' ); ?></th>
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
         * Action: mainwp_themes_after_auto_updates_table
         *
         * Fires before the Auto Update Themes table.
         *
         * @since 4.1
         */
        do_action( 'mainwp_themes_after_auto_updates_table' );

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
         * Filter: mainwp_theme_auto_updates_table_fatures
         *
         * Filters the Theme Auto Updates table features.
         *
         * @since 4.1
         */
        $table_features = apply_filters( 'mainwp_theme_auto_updates_table_fatures', $table_features );
        ?>
        <script type="text/javascript">

            jQuery( document ).ready( function() {
                jQuery( '.mainwp-ui-page .ui.checkbox' ).checkbox();

                let responsive = <?php echo esc_html( $table_features['responsive'] ); ?>;
                if( jQuery( window ).width() > 1140 ) {
                    responsive = false;
                }

                jQuery( '#mainwp-all-active-themes-table' ).DataTable( {
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
        <?php
    }

    /**
     * Render the Themes Ignored Updates Tab.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Common::get_user_extension()
     * @uses \MainWP\Dashboard\MainWP_DB::query()
     * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_for_current_user()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     */
    public static function render_ignore() {
        $websites             = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );
        $userExtension        = MainWP_DB_Common::instance()->get_user_extension();
        $decodedIgnoredThemes = json_decode( $userExtension->ignored_themes, true );
        $ignoredThemes        = is_array( $decodedIgnoredThemes ) && ! empty( $decodedIgnoredThemes );

        $cnt = 0;

        while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
            if ( $website->is_ignoreThemeUpdates ) {
                continue;
            }

            $tmpDecodedIgnoredThemes = json_decode( $website->ignored_themes, true );

            if ( ! is_array( $tmpDecodedIgnoredThemes ) || empty( $tmpDecodedIgnoredThemes ) ) {
                continue;
            }

            ++$cnt;
        }

        static::render_header( 'Ignore' );
        ?>
        <div id="mainwp-ignored-plugins" class="ui segment">
            <?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-ignored-themes-info-message' ) ) : ?>
                <div class="ui info message">
                    <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-ignored-themes-info-message"></i>
                    <?php printf( esc_html__( 'Manage themes you have told your MainWP Dashboard to ignore updates on global or per site level.  For additional help, please check this %1$shelp documentation%2$s.', 'mainwp' ), '<a href="https://mainwp.com/kb/ignore-themes-updates/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); ?>
                </div>
            <?php endif; ?>
            <?php
            /**
             * Action: mainwp_themes_before_ignored_updates
             *
             * Fires on the top of the Ignored Theme Updates page.
             *
             * @since 4.1
             */
            do_action( 'mainwp_themes_before_ignored_updates', $ignoredThemes, $websites );
            ?>
            <h3 class="ui header">
                <?php esc_html_e( 'Globally Ignored Themes', 'mainwp' ); ?>
                <div class="sub header"><?php esc_html_e( 'These are themes you have told your MainWP Dashboard to ignore updates on global level and not notify you about pending updates.', 'mainwp' ); ?></div>
            </h3>
            <?php static::render_global_ignored( $ignoredThemes, $decodedIgnoredThemes ); ?>
            <div class="ui hidden divider"></div>
            <h3 class="ui header">
            <?php esc_html_e( 'Per Site Ignored Themes', 'mainwp' ); ?>
            <div class="sub header"><?php esc_html_e( 'These are themes you have told your MainWP Dashboard to ignore updates per site level and not notify you about pending updates.', 'mainwp' ); ?></div>
        </h3>
        <?php static::render_sites_ignored( $cnt, $websites ); ?>
        <?php
        /**
         * Action: mainwp_themes_after_ignored_updates
         *
         * Fires on the bottom of the Ignored Theme Updates page.
         *
         * @since 4.1
         */
        do_action( 'mainwp_themes_after_ignored_updates', $ignoredThemes, $websites );
        ?>
        </div>
        <?php
        MainWP_Updates::render_plugin_details_modal();
        static::render_footer( 'Ignore' );
    }

    /**
     * Render globally Ignored themes.
     *
     * @param mixed $ignoredThemes Encoded ignored themes.
     * @param mixed $decodedIgnoredThemes Decoded ignored themes.
     */
    public static function render_global_ignored( $ignoredThemes, $decodedIgnoredThemes ) { //phpcs:ignore -- NOSONAR - complex.
        ?>
        <table id="mainwp-globally-ignored-themes" class="ui compact selectable table unstackable">
                <thead>
                    <tr>
                        <th scope="col" ><?php esc_html_e( 'Theme', 'mainwp' ); ?></th>
                        <th scope="col" ><?php esc_html_e( 'Theme slug', 'mainwp' ); ?></th>
                        <th scope="col" ><?php esc_html_e( 'Ignored version', 'mainwp' ); ?></th>
                        <th scope="col" ></th>
                    </tr>
                </thead>
                <tbody id="globally-ignored-themes-list">
                    <?php if ( $ignoredThemes ) : ?>
                        <?php
                        foreach ( $decodedIgnoredThemes as $ignoredTheme => $ignoredThemeName ) :

                            $ignore_name  = 'N/A';
                            $ignored_vers = array( 'all_versions' );
                            if ( is_string( $ignoredThemeName ) ) {
                                $ignore_name = $ignoredThemeName;
                            } elseif ( is_array( $ignoredThemeName ) ) {
                                $ignore_name = ! empty( $ignoredThemeName['Name'] ) ? $ignoredThemeName['Name'] : $ignore_name;
                                $ig_vers     = ! empty( $ignoredThemeName['ignored_versions'] ) ? $ignoredThemeName['ignored_versions'] : '';
                                if ( ! empty( $ig_vers ) && is_array( $ig_vers ) && ! in_array( 'all_versions', $ig_vers ) ) {
                                    $ignored_vers = $ig_vers;
                                }
                            }
                            ?>
                            <?php foreach ( $ignored_vers as $ignored_ver ) { ?>
                                <tr theme-slug="<?php echo esc_attr( rawurlencode( $ignoredTheme ) ); ?>">
                                    <td><?php echo MainWP_System_Utility::get_theme_icon( $ignoredTheme ) . '&nbsp;&nbsp;&nbsp;&nbsp;' . esc_html( $ignore_name ); //phpcs:ignore -- escaped. ?></td>
                                    <td><?php echo esc_html( $ignoredTheme ); ?></td>
                                    <td><?php echo 'all_versions' === $ignored_ver ? esc_html__( 'All', 'mainwp' ) : esc_html( $ignored_ver ); ?></td>
                                    <td class="right aligned">
                                    <?php if ( \mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
                                        <a href="#" class="ui mini button" onClick="return updatesoverview_themes_unignore_globally( '<?php echo esc_js( rawurlencode( $ignoredTheme ) ); ?>', '<?php echo esc_js( rawurlencode( $ignored_ver ) ); ?>' )"><?php esc_html_e( 'Unignore', 'mainwp' ); ?></a>
                                    <?php endif; ?>
                                    </td>
                                </tr>
                            <?php } ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if ( \mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
                    <?php if ( $ignoredThemes ) : ?>
                    <tfoot class="full-width">
                        <tr>
                            <th scope="col" colspan="4">
                                <a class="ui right floated small green labeled icon button" onClick="return updatesoverview_themes_unignore_globally_all();" id="mainwp-unignore-globally-all">
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
            jQuery( '#mainwp-globally-ignored-themes' ).DataTable( {
                searching: false,
                paging: false,
                info: false,
                responsive: true,
                "language": {
                    "emptyTable": "<?php esc_html_e( 'No ignored themes.', 'mainwp' ); ?>"
                }
            } );
        } );
        </script>
        <?php
    }

    /**
     * Method render_sites_ignored()
     *
     * Render ignored sites.
     *
     * @param int    $cnt Count of items.
     * @param object $websites The websits object.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_DB::free_result()
     */
    public static function render_sites_ignored( $cnt, $websites ) { // phpcs:ignore -- NOSONAR - complex.
        ?>
        <table id="mainwp-per-site-ignored-themes" class="ui compact selectable table unstackable">
            <thead>
                <tr>
                    <th scope="col" ><?php esc_html_e( 'Site', 'mainwp' ); ?></th>
                    <th scope="col" ><?php esc_html_e( 'Theme', 'mainwp' ); ?></th>
                    <th scope="col" ><?php esc_html_e( 'Theme slug', 'mainwp' ); ?></th>
                    <th scope="col" ><?php esc_html_e( 'Ignored version', 'mainwp' ); ?></th>
                    <th scope="col" ></th>
                </tr>
            </thead>
            <tbody id="ignored-themes-list">
            <?php if ( 0 < $cnt ) : ?>
                <?php
                MainWP_DB::data_seek( $websites, 0 );

                while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
                    if ( $website->is_ignoreThemeUpdates ) {
                        continue;
                    }
                    $decodedIgnoredThemes = json_decode( $website->ignored_themes, true );
                    if ( ! is_array( $decodedIgnoredThemes ) || empty( $decodedIgnoredThemes ) ) {
                        continue;
                    }
                    $first = true;

                    foreach ( $decodedIgnoredThemes as $ignoredTheme => $ignoredThemeName ) {
                        $ignore_name  = 'N/A';
                        $ignored_vers = array( 'all_versions' );
                        if ( is_string( $ignoredThemeName ) ) {
                            $ignore_name = $ignoredThemeName;
                        } elseif ( is_array( $ignoredThemeName ) ) {
                            $ignore_name = ! empty( $ignoredThemeName['Name'] ) ? $ignoredThemeName['Name'] : $ignore_name;
                            $ig_vers     = ! empty( $ignoredThemeName['ignored_versions'] ) ? $ignoredThemeName['ignored_versions'] : '';
                            if ( ! empty( $ig_vers ) && is_array( $ig_vers ) && ! in_array( 'all_versions', $ig_vers ) ) {
                                $ignored_vers = $ig_vers;
                            }
                        }

                        foreach ( $ignored_vers as $ignored_ver ) {
                            ?>
                            <tr site-id="<?php echo esc_attr( $website->id ); ?>" theme-slug="<?php echo esc_attr( rawurlencode( $ignoredTheme ) ); ?>">
                                <?php if ( $first ) : ?>
                                    <td><div><a href="<?php echo esc_url( admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ) ); ?>"><?php echo esc_html( stripslashes( $website->name ) ); ?></a></div></td>
                                    <?php $first = false; ?>
                                <?php else : ?>
                                    <td><div style="display:none;"><a href="<?php echo esc_url( admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ) ); ?>"><?php echo esc_html( stripslashes( $website->name ) ); ?></a></div></td>
                                <?php endif; ?>
                                <td><?php echo MainWP_System_Utility::get_theme_icon( $ignoredTheme ) . '&nbsp;&nbsp;&nbsp;&nbsp;' . esc_html( $ignore_name ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                                <td><?php echo esc_html( $ignoredTheme ); ?></td>
                                <td><?php echo 'all_versions' === $ignored_ver ? esc_html__( 'All', 'mainwp' ) : esc_html( $ignored_ver ); ?></td>
                                <td class="right aligned">
                                <?php if ( \mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
                                    <a href="#" class="ui mini button" onClick="return updatesoverview_themes_unignore_detail( '<?php echo esc_js( rawurlencode( $ignoredTheme ) ); ?>', <?php echo intval( $website->id ); ?>, '<?php echo esc_js( rawurlencode( $ignored_ver ) ); ?>' )"><?php esc_html_e( 'Unignore', 'mainwp' ); ?></a>
                                <?php endif; ?>
                                </td>
                            </tr>
                            <?php
                        }
                    }
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
                        <a class="ui right floated small green labeled icon button" onClick="return updatesoverview_themes_unignore_detail_all();" id="mainwp-unignore-detail-all">
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
            jQuery( '#mainwp-per-site-ignored-themes' ).DataTable( {
                searching: false,
                paging: false,
                info: false,
                responsive: true,
                "language": {
                    "emptyTable": "<?php esc_html_e( 'No ignored themes', 'mainwp' ); ?>"
                }
            } );
        } );
        </script>
        <?php
    }

    /**
     * Method render_abandoned_themes()
     *
     * Render abandoned themes list.
     */
    public static function render_abandoned_themes() {
        MainWP_Updates::render( 'abandoned_themes' );
    }

    /**
     * Render the Themes Ignored/Abandoned Tab.
     *
     * @uses \MainWP\Dashboard\MainWP_DB_Common::get_user_extension()
     * @uses \MainWP\Dashboard\MainWP_DB::query()
     * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_for_current_user()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     */
    public static function render_ignored_abandoned() {
        $websites             = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user() );
        $userExtension        = MainWP_DB_Common::instance()->get_user_extension();
        $decodedIgnoredThemes = json_decode( $userExtension->dismissed_themes, true );
        $ignoredThemes        = is_array( $decodedIgnoredThemes ) && ! empty( $decodedIgnoredThemes );
        $cnt                  = 0;
        while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
            $tmpDecodedIgnoredThemes = MainWP_DB::instance()->get_website_option( $website, 'themes_outdate_dismissed' );
            $tmpDecodedIgnoredThemes = ! empty( $tmpDecodedIgnoredThemes ) ? json_decode( $tmpDecodedIgnoredThemes, true ) : array();

            if ( ! is_array( $tmpDecodedIgnoredThemes ) || empty( $tmpDecodedIgnoredThemes ) ) {
                continue;
            }
            ++$cnt;
        }

        static::render_header( 'IgnoreAbandoned' );
        ?>
        <div id="mainwp-ignored-abandoned-themes" class="ui segment">
            <?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-ignored-abandoned-themes-info-message' ) ) : ?>
                <div class="ui info message">
                    <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-ignored-abandoned-themes-info-message"></i>
                    <?php printf( esc_html__( 'Manage abandoned themes you have told your MainWP Dashboard to ignore on global or per site level.  For additional help, please check this %1$shelp documentation%2$s.', 'mainwp' ), '<a href="https://mainwp.com/kb/abandoned-themes/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); ?>
                </div>
            <?php endif; ?>
            <?php
            /**
             * Action: mainwp_themes_before_ignored_abandoned
             *
             * Fires on the top of the Ignored Themes Abandoned page.
             *
             * @since 4.1
             */
            do_action( 'mainwp_themes_before_ignored_abandoned', $ignoredThemes, $websites );
            ?>
            <h3 class="ui header">
                <?php esc_html_e( 'Globally Ignored Abandoned Themes', 'mainwp' ); ?>
                <div class="sub header"><?php esc_html_e( 'These are themes you have told your MainWP Dashboard to ignore on global level even though they have passed your Abandoned Themes Tolerance date', 'mainwp' ); ?></div>
            </h3>
            <?php static::render_global_ignored_abandoned( $ignoredThemes, $decodedIgnoredThemes ); ?>
        <div class="ui hidden divider"></div>
        <h3 class="ui header">
            <?php esc_html_e( 'Per Site Ignored Abandoned Themes', 'mainwp' ); ?>
            <div class="sub header"><?php esc_html_e( 'These are themes you have told your MainWP Dashboard to ignore per site level even though they have passed your Abandoned Theme Tolerance date', 'mainwp' ); ?></div>
        </h3>
            <?php static::render_sites_ignored_abandoned( $cnt, $websites ); ?>
            <?php
            /**
             * Action: mainwp_themes_after_ignored_abandoned
             *
             * Fires on the bottom of the Ignored Themes Abandoned page.
             *
             * @since 4.1
             */
            do_action( 'mainwp_themes_after_ignored_abandoned', $ignoredThemes, $websites );
            ?>
        </div>
        <?php
        static::render_footer( 'IgnoreAbandoned' );
    }

    /**
     * Render the global ignored themes list.
     *
     * @param mixed $ignoredThemes Encoded ignored themes list.
     * @param mixed $decodedIgnoredThemes Decoded ignored themes list.
     */
    public static function render_global_ignored_abandoned( $ignoredThemes, $decodedIgnoredThemes ) {
        ?>
        <table id="mainwp-globally-ignored-abandoned-themes" class="ui compact selectable table unstackable">
            <thead>
                <tr>
                    <th scope="col" ><?php esc_html_e( 'Theme', 'mainwp' ); ?></th>
                    <th scope="col" ><?php esc_html_e( 'Theme slug', 'mainwp' ); ?></th>
                    <th scope="col" ></th>
                </tr>
            </thead>
            <tbody id="globally-ignored-themes-list">
                <?php if ( $ignoredThemes ) : ?>
                    <?php foreach ( $decodedIgnoredThemes as $ignoredTheme => $ignoredThemeName ) : ?>
                    <tr theme-slug="<?php echo esc_attr( rawurlencode( $ignoredTheme ) ); ?>">
                        <td><?php echo esc_html( $ignoredThemeName ); ?></td>
                        <td><?php echo esc_html( $ignoredTheme ); ?></td>
                        <td class="right aligned">
                        <?php if ( \mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
                            <a href="#" class="ui mini button" onClick="return updatesoverview_themes_abandoned_unignore_globally( '<?php echo esc_js( rawurlencode( $ignoredTheme ) ); ?>' )"><?php esc_html_e( 'Unignore', 'mainwp' ); ?></a>
                        <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
                <?php if ( \mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
                    <?php if ( $ignoredThemes ) : ?>
                    <tfoot class="full-width">
                        <tr>
                            <th scope="col" colspan="3">
                                <a class="ui right floated small green labeled icon button" onClick="return updatesoverview_themes_abandoned_unignore_globally_all();" id="mainwp-unignore-globally-all">
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
            jQuery( '#mainwp-globally-ignored-abandoned-themes' ).DataTable( {
                "responsive": true,
                "searching": false,
                "paging": false,
                "info": false,
                "language": {
                    "emptyTable": "<?php esc_html_e( 'No ignored abandoned themes.', 'mainwp' ); ?>"
                }
            } );
        } );
        </script>
        <?php
    }

    /**
     * Method render_sites_ignored_abandoned()
     *
     * Render ignored items per site list.
     *
     * @param int    $cnt Count of items.
     * @param object $websites The websits object.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::data_seek()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_option()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_DB::free_result()
     */
    public static function render_sites_ignored_abandoned( $cnt, $websites ) { // phpcs:ignore -- NOSONAR - complex.
        ?>
    <table id="mainwp-per-site-ignored-abandoned-themes" class="ui compact selectable table unstackable">
        <thead>
            <tr>
                <th scope="col" ><?php esc_html_e( 'Site', 'mainwp' ); ?></th>
                <th scope="col" ><?php esc_html_e( 'Theme', 'mainwp' ); ?></th>
                <th scope="col" ><?php esc_html_e( 'Theme slug', 'mainwp' ); ?></th>
                <th scope="col" ></th>
            </tr>
        </thead>
        <tbody id="ignored-abandoned-themes-list">
            <?php if ( 0 < $cnt ) : ?>
                <?php
                MainWP_DB::data_seek( $websites, 0 );
                while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
                    $decodedIgnoredThemes = MainWP_DB::instance()->get_website_option( $website, 'themes_outdate_dismissed' );
                    $decodedIgnoredThemes = ! empty( $decodedIgnoredThemes ) ? json_decode( $decodedIgnoredThemes, true ) : array();

                    if ( ! is_array( $decodedIgnoredThemes ) || empty( $decodedIgnoredThemes ) ) {
                        continue;
                    }

                    $first = true;
                    foreach ( $decodedIgnoredThemes as $ignoredTheme => $ignoredThemeName ) {
                        ?>
                    <tr site-id="<?php echo esc_attr( $website->id ); ?>" theme-slug="<?php echo esc_attr( rawurlencode( $ignoredTheme ) ); ?>">
                        <?php if ( $first ) : ?>
                        <td><a href="<?php echo esc_url( admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ) ); ?>"><?php echo esc_html( stripslashes( $website->name ) ); ?></a></td>
                            <?php $first = false; ?>
                        <?php else : ?>
                        <td><div style="display:none;"><a href="<?php echo esc_url( admin_url( 'admin.php?page=managesites&dashboard=' . $website->id ) ); ?>"><?php echo esc_html( stripslashes( $website->name ) ); ?></a></div></td>
                        <?php endif; ?>
                        <td><?php echo esc_html( $ignoredThemeName ); ?></td>
                        <td><?php echo esc_html( $ignoredTheme ); ?></td>
                        <td class="right aligned">
                        <?php if ( \mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
                            <a href="#" class="ui mini button" onClick="return updatesoverview_themes_unignore_abandoned_detail( '<?php echo esc_js( rawurlencode( $ignoredTheme ) ); ?>', <?php echo intval( $website->id ); ?> )"><?php esc_html_e( 'Unignore', 'mainwp' ); ?></a>
                        <?php endif; ?>
                        </td>
                    </tr>
                        <?php
                    }
                }
                MainWP_DB::free_result( $websites );
                ?>
            <?php endif; ?>
            </tbody>
            <?php if ( \mainwp_current_user_can( 'dashboard', 'ignore_unignore_updates' ) ) : ?>
                <?php if ( 0 < $cnt ) : ?>
                <tfoot class="full-width">
                    <tr>
                        <th scope="col" colspan="4">
                            <a class="ui right floated small green labeled icon button" onClick="return updatesoverview_themes_unignore_abandoned_detail_all();" id="mainwp-unignore-detail-all">
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
            jQuery( '#mainwp-per-site-ignored-abandoned-themes' ).DataTable( {
                "responsive": true,
                "searching": false,
                "paging": false,
                "info": false,
                "language": {
                    "emptyTable": "<?php esc_html_e( 'No ignored abandoned themes.', 'mainwp' ); ?>"
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
        if ( isset( $_GET['page'] ) && ( 'ThemesManage' === $_GET['page'] || 'ThemesInstall' === $_GET['page'] || 'ThemesAutoUpdate' === $_GET['page'] || 'ThemesIgnore' === $_GET['page'] || 'ThemesIgnoredAbandoned' === $_GET['page'] ) ) {
            ?>
            <p><?php esc_html_e( 'If you need help with managing themes, please review following help documents', 'mainwp' ); ?></p>
            <div class="ui relaxed bulleted list">
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/managing-themes-with-mainwp/" target="_blank">Managing Themes with MainWP</a></div>
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/managing-themes-with-mainwp/#install-themes" target="_blank">Install Themes</a></div>
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/managing-themes-with-mainwp/#activate-themes" target="_blank">Activate Themes</a></div>
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/managing-themes-with-mainwp/#delete-themes" target="_blank">Delete Themes</a></div>
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/managing-themes-with-mainwp/#update-themes" target="_blank">Update Themes</a></div>
                <?php
                /**
                 * Action: mainwp_themes_help_item
                 *
                 * Fires at the bottom of the help articles list in the Help sidebar on the Themes page.
                 *
                 * Suggested HTML markup:
                 *
                 * <div class="item"><a href="Your custom URL">Your custom text</a></div>
                 *
                 * @since 4.1
                 */
                do_action( 'mainwp_themes_help_item' );
                ?>
            </div>
            <?php
        }
         // phpcs:enable
    }
}
