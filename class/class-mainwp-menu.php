<?php
/**
 * MainWP Main Menu
 *
 * Build & Render MainWP Main Menu.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Menu
 *
 * @package MainWP\Dashboard
 */
class MainWP_Menu { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Method get_class_name()
     *
     * Get Class Name.
     *
     * @return object
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Method get_instance().
     */
    public static function get_instance() {
        return new self();
    }

    /**
     * MainWP_Menu constructor.
     *
     * Run each time the class is called.
     * Define MainWP Main Menu Items.
     */
    public function __construct() {

        /**
         * MainWP Disable Menus items array.
         *
         * @global object
         */
        global $_mainwp_disable_menus_items;

        // Init disable menu items, default is false.
        // Use the MainWP Hook 'mainwp_main_menu_disable_menu_items' to disable menu items.
        if ( null === $_mainwp_disable_menus_items ) {
            $_mainwp_disable_menus_items = array(
                // Compatible with old hooks.
                'level_1' => array(
                    'not_set_this_level' => true,
                ),
                'level_2' => array(
                    // 'mainwp_tab' - Do not hide this menu.
                    'UpdatesManage'     => false,
                    'managesites'       => false,
                    'ManageClients'     => false,
                    'ManageGroups'      => false,
                    'PostBulkManage'    => false,
                    'PageBulkManage'    => false,
                    'ThemesManage'      => false,
                    'PluginsManage'     => false,
                    'UserBulkManage'    => false,
                    'ManageBackups'     => false,
                    'Settings'          => false,
                    'Extensions'        => false,
                    'ServerInformation' => false,
                ),
                // Compatible with old hooks.
                'level_3' => array(),
            );
        }
    }

    /**
     * Method init_mainwp_menus()
     *
     * Init MainWP menus.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Utility::is_admin()
     * @uses \MainWP\Dashboard\MainWP_Updates::init_menu()
     * @uses \MainWP\Dashboard\MainWP_Manage_Sites::init_menu()
     * @uses \MainWP\Dashboard\MainWP_Post::init_menu()
     * @uses \MainWP\Dashboard\MainWP_Page::init_menu()
     * @uses \MainWP\Dashboard\MainWP_Themes::init_menu()
     * @uses \MainWP\Dashboard\MainWP_Plugins::init_menu()
     * @uses \MainWP\Dashboard\MainWP_User::init_menu()
     * @uses \MainWP\Dashboard\MainWP_Manage_Backups::init_menu()
     * @uses \MainWP\Dashboard\MainWP_Manage_Groups::init_menu()
     * @uses \MainWP\Dashboard\MainWP_Monitoring::init_menu()
     * @uses \MainWP\Dashboard\MainWP_Settings::init_menu()
     * @uses \MainWP\Dashboard\MainWP_Extensions::init_menu()
     * @uses \MainWP\Dashboard\MainWP_Bulk_Update_Admin_Passwords::init_menu()
     */
    public static function init_mainwp_menus() { // phpcs:ignore -- NOSONAR - complex method. Current complexity is the only way to achieve desired results, pull request solutions appreciated.
        if ( MainWP_System_Utility::is_admin() ) {

            $menus_items = array();

            // Manage Sites.
            $menus_items[] = array(
                'slug'               => 'managesites',
                'menu_level'         => 2,
                'menu_rights'        => array(
                    'dashboard' => array(
                        'add_sites',
                        'edit_sites',
                        'delete_sites',
                        'access_individual_dashboard',
                        'access_wpadmin_on_child_sites',
                        'manage_security_issues',
                        'test_connection',
                        'manage_groups',
                        'manage_uptime_monitoring',
                    ),
                ),
                'init_menu_callback' => array( MainWP_Manage_Sites::class, 'init_menu' ),
                'leftbar_order'      => 1,
            );

            // Manage Clients.
            $menus_items[] = array(
                'slug'               => 'ManageClients',
                'menu_level'         => 2,
                'menu_rights'        => array(
                    'dashboard' => array(
                        'manage_clients',
                    ),
                ),
                'init_menu_callback' => array( MainWP_Client::class, 'init_menu' ),
                'leftbar_order'      => 2,
            );

            // Manage Tags.
            $menus_items[] = array(
                'slug'               => 'ManageGroups',
                'menu_level'         => 2,
                'menu_rights'        => array(
                    'dashboard' => array(
                        'manage_groups',
                    ),
                ),
                'init_menu_callback' => array( MainWP_Manage_Groups::class, 'init_menu' ),
            );

            // Manage Updates.
            $menus_items[] = array(
                'slug'               => 'UpdatesManage',
                'menu_level'         => 2,
                'menu_rights'        => array(
                    'dashboard' => array(
                        'update_wordpress',
                        'update_plugins',
                        'update_themes',
                        'update_translations',
                        'ignore_unignore_updates',
                        'trust_untrust_updates',
                    ),
                ),
                'init_menu_callback' => array( MainWP_Updates::class, 'init_menu' ),
            );

            // Manage Plugins.
            $menus_items[] = array(
                'slug'               => 'PluginsManage',
                'menu_level'         => 2,
                'menu_rights'        => array(
                    'dashboard' => array(
                        'install_plugins',
                        'delete_plugins',
                        'activate_deactivate_plugins',
                    ),
                ),
                'init_menu_callback' => array( MainWP_Plugins::class, 'init_menu' ),
            );

            // Manage Themes.
            $menus_items[] = array(
                'slug'               => 'ThemesManage',
                'menu_level'         => 2,
                'menu_rights'        => array(
                    'dashboard' => array(
                        'install_themes',
                        'delete_themes',
                        'activate_deactivate_themes',
                    ),
                ),
                'init_menu_callback' => array( MainWP_Themes::class, 'init_menu' ),
            );

            // Manage Users.
            $menus_items[] = array(
                'slug'               => 'UserBulkManage',
                'menu_level'         => 2,
                'menu_rights'        => array(
                    'dashboard' => array(
                        'manage_users',
                    ),
                ),
                'init_menu_callback' => array( MainWP_User::class, 'init_menu' ),
            );

            // Manage Posts.
            $menus_items[] = array(
                'slug'               => 'PostBulkManage',
                'menu_level'         => 2,
                'menu_rights'        => array(
                    'dashboard' => array(
                        'manage_posts',
                    ),
                ),
                'init_menu_callback' => array( MainWP_Post::class, 'init_menu' ),
            );

            // Manage Pages.
            $menus_items[] = array(
                'slug'               => 'PageBulkManage',
                'menu_level'         => 2,
                'menu_rights'        => array(
                    'dashboard' => array(
                        'manage_pages',
                    ),
                ),
                'init_menu_callback' => array( MainWP_Page::class, 'init_menu' ),
            );

            // Manage Backups.
            $menus_items[] = array(
                'slug'               => 'ManageBackups',
                'menu_level'         => 2,
                'menu_rights'        => true,
                'init_menu_callback' => array( MainWP_Manage_Backups::class, 'init_menu' ),
            );

            // Manage Settings.
            $menus_items[] = array(
                'slug'               => 'Settings',
                'menu_level'         => 2,
                'menu_rights'        => array(
                    'dashboard' => array(
                        'manage_dashboard_settings',
                    ),
                ),
                'init_menu_callback' => array( MainWP_Settings::class, 'init_menu' ),
                'leftbar_order'      => 5,
            );

            // Manage RESTAPI.
            $menus_items[] = array(
                'slug'               => 'RESTAPI',
                'menu_level'         => 2,
                'menu_rights'        => array(
                    'dashboard' => array(
                        'manage_dashboard_restapi',
                    ),
                ),
                'init_menu_callback' => array( MainWP_Rest_Api_Page::class, 'init_menu' ),
                'leftbar_order'      => 4,
            );

            // Manage Extensions.
            $menus_items[] = array(
                'slug'               => 'Extensions',
                'menu_level'         => 2,
                'menu_rights'        => array(
                    'dashboard' => array(
                        'manage_extensions',
                    ),
                ),
                'init_menu_callback' => array( MainWP_Extensions::class, 'init_menu' ),
                'leftbar_order'      => 3,
            );

            // Manage Admin Passwords.
            $menus_items[] = array(
                'slug'               => 'UpdateAdminPasswords',
                'menu_level'         => 2,
                'menu_rights'        => array(
                    'dashboard' => array(
                        'manage_users',
                    ),
                ),
                'init_menu_callback' => array( MainWP_Bulk_Update_Admin_Passwords::class, 'init_menu' ),
            );

            // Monitoring Sites.
            $menus_items[] = array(
                'slug'               => 'MonitoringSites',
                'menu_level'         => 3,
                'menu_rights'        => true,
                'init_menu_callback' => array( MainWP_Monitoring::class, 'init_menu' ),
            );

            static::init_mainwp_menu_items( $menus_items, 'first' ); // do NOT change 'first', it related other hooks.

            /**
             * Action: mainwp_admin_menu
             *
             * Hooks main navigation menu items.
             *
             * @since 4.0
             */
            do_action( 'mainwp_admin_menu' ); // to compatible.

            $menus_items_low = array();

            // Manage Admin Passwords.
            $menus_items_low[] = array(
                'slug'               => 'ServerInformation',
                'menu_level'         => 2,
                'menu_rights'        => array(
                    'dashboard' => array(
                        'see_server_information',
                    ),
                ),
                'init_menu_callback' => array( MainWP_Server_Information::class, 'init_menu' ),
                'leftbar_order'      => 6,
            );

            static::init_mainwp_menu_items( $menus_items_low, 'second' );

        }
    }

    /**
     * Method init_mainwp_menu_items()
     *
     * Init MainWP menus.
     *
     * @param array  $menus_items menus items.
     * @param string $part menus part.
     */
    public static function init_mainwp_menu_items( $menus_items, $part ) { //phpcs:ignore -- NOSONAR - complex method.
        if ( ! is_array( $menus_items ) ) {
            return;
        }

        $menus_items = apply_filters( 'mainwp_init_primary_menu_items', $menus_items, $part );

        MainWP_Utility::array_sort_existed_keys( $menus_items, 'leftbar_order' );

        foreach ( $menus_items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $empty_level = true;

            if ( ! empty( $item['slug'] ) && ! empty( $item['menu_level'] ) && ! empty( $item['init_menu_callback'] ) ) {
                $empty_level = false;
            }

            if ( ! $empty_level && ! static::is_disable_menu_item( intval( $item['menu_level'] ), $item['slug'] ) && is_callable( $item['init_menu_callback'] ) ) {
                $accessable = false;
                if ( isset( $item['menu_rights'] ) ) {
                    $item_rights = $item['menu_rights'];
                    if ( is_array( $item_rights ) && ! empty( $item_rights ) ) {
                        foreach ( $item_rights as $group_right => $rights ) {
                            if ( is_array( $rights ) ) {
                                foreach ( $rights as $func_right ) {
                                    if ( \mainwp_current_user_can( $group_right, $func_right ) ) {
                                        $accessable = true;
                                        break;
                                    }
                                }
                            }
                            if ( $accessable ) {
                                break;
                            }
                        }
                    } elseif ( true === $item['menu_rights'] ) {
                        $accessable = true;
                    }
                }

                if ( $accessable ) {
                    call_user_func( $item['init_menu_callback'] );
                }
            }
        }
    }

    /**
     * Method init_sub_pages()
     *
     * Init subpages MainWP menus.
     *
     * @uses \MainWP\Dashboard\MainWP_Extensions::init_subpages_menu()
     * @uses \MainWP\Dashboard\MainWP_Manage_Backups::init_subpages_menu()
     * @uses \MainWP\Dashboard\MainWP_Manage_Sites::init_subpages_menu()
     * @uses \MainWP\Dashboard\MainWP_Page::init_subpages_menu()
     * @uses \MainWP\Dashboard\MainWP_Post::init_subpages_menu()
     * @uses \MainWP\Dashboard\MainWP_Settings::init_subpages_menu()
     * @uses \MainWP\Dashboard\MainWP_Themes::init_subpages_menu()
     * @uses \MainWP\Dashboard\MainWP_Themes::init_subpages_menu()
     * @uses \MainWP\Dashboard\MainWP_Plugins::init_subpages_menu()
     * @uses \MainWP\Dashboard\MainWP_User::init_subpages_menu()
     * @uses \MainWP\Dashboard\MainWP_Settings::init_subpages_menu()
     */
    public static function init_sub_pages() {

        if ( ! static::is_disable_menu_item( 2, 'PostBulkManage' ) ) {
            MainWP_Post::init_subpages_menu();
        }
        if ( ! static::is_disable_menu_item( 2, 'managesites' ) ) {
            MainWP_Manage_Sites::init_subpages_menu();
        }

        if ( ! static::is_disable_menu_item( 2, 'RESTAPI' ) ) {
            MainWP_Rest_Api_Page::init_subpages_menu();
        }

        if ( ! static::is_disable_menu_item( 2, 'Settings' ) ) {
            MainWP_Settings::init_subpages_menu();
        }

        if ( ! static::is_disable_menu_item( 2, 'Extensions' ) ) {
            MainWP_Extensions::init_subpages_menu();
        }
        if ( ! static::is_disable_menu_item( 2, 'PageBulkManage' ) ) {
            MainWP_Page::init_subpages_menu();
        }
        if ( ! static::is_disable_menu_item( 2, 'ThemesManage' ) ) {
            MainWP_Themes::init_subpages_menu();
        }
        if ( ! static::is_disable_menu_item( 2, 'PluginsManage' ) ) {
            MainWP_Plugins::init_subpages_menu();
        }
        if ( ! static::is_disable_menu_item( 2, 'UserBulkManage' ) ) {
            MainWP_User::init_subpages_menu();
        }
        if ( ! static::is_disable_menu_item( 2, 'ManageClients' ) ) {
            MainWP_Client::init_subpages_menu();
        }
        if ( get_option( 'mainwp_enableLegacyBackupFeature' ) && ! static::is_disable_menu_item( 2, 'ManageBackups' ) ) {
            MainWP_Manage_Backups::init_subpages_menu();
        }

        if ( ! static::is_disable_menu_item( 2, 'Settings' ) ) {
            MainWP_Settings::init_subpages_menu();
        }

        /**
         * Action: mainwp_admin_menu_sub
         *
         * Hooks main navigation sub-menu items.
         *
         * @since 4.0
         */
        do_action( 'mainwp_admin_menu_sub' );

        if ( ! static::is_disable_menu_item( 2, 'ServerInformation' ) ) {
            MainWP_Server_Information::init_subpages_menu();
        }
    }

    /**
     * Method set_menu_active_slugs
     *
     * @param string $item Menu item.
     * @param string $active Menu active slug.
     */
    public static function set_menu_active_slugs( $item, $active ) {
        global $_mainwp_menu_active_slugs;
        if ( ! is_array( $_mainwp_menu_active_slugs ) ) {
            $_mainwp_menu_active_slugs = array();
        }
        $_mainwp_menu_active_slugs[ $item ] = $active;
    }


    /**
     * Method init_subpages_left_menu
     *
     * Build left menu subpages array.
     *
     * @param array  $subPages Array of SubPages.
     * @param array  $initSubpage Initial SubPage Array.
     * @param string $parentKey Parent Menu Slug.
     * @param mixed  $slug SubPage Slug.
     */
    public static function init_subpages_left_menu( $subPages, &$initSubpage, $parentKey, $slug ) { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR - complexity.

        if ( ! is_array( $subPages ) ) {
            $subPages = array();
        }

        global $_mainwp_menu_active_slugs;

        $subPages = apply_filters( 'mainwp_subpages_left_menu', $subPages, $initSubpage, $parentKey, $slug );

        foreach ( $subPages as $subPage ) {
            if ( ! isset( $subPage['menu_hidden'] ) || ( isset( $subPage['menu_hidden'] ) && empty( $subPage['menu_hidden'] ) ) ) {
                $_item = array(
                    'title'      => $subPage['title'],
                    'parent_key' => $parentKey,
                    'href'       => 'admin.php?page=' . $slug . $subPage['slug'],
                    'slug'       => $slug . $subPage['slug'],
                    'right'      => '',
                );

                if ( ! empty( $subPage['before_title'] ) ) {
                    $item['before_title'] = $subPage['before_title'];
                }

                // To support check right to open menu for sometime.
                if ( isset( $subPage['item_slug'] ) ) {
                    $_item['item_slug'] = $subPage['item_slug'];
                }

                if ( isset( $subPage['href'] ) && ! empty( $subPage['href'] ) ) { // override href.
                    $_item['href'] = $subPage['href'];
                }

                $initSubpage[] = $_item;
            } elseif ( isset( $subPage['slug'] ) ) {
                $_mainwp_menu_active_slugs[ $slug . $subPage['slug'] ] = $parentKey; // to fix.
            }
        }
    }

    /**
     * Method is_disable_menu_item
     *
     * Check if $_mainwp_disable_menus_items contains any menu items to hide.
     *
     * @param string       $level The level the menu item is on.
     * @param array|string $item The menu items meta data.
     *
     * @return bool True|False, default is False.
     */
    public static function is_disable_menu_item( $level, $item ) {

        /**
         * MainWP Disable Menus items array.
         *
         * @global object
         */
        global $_mainwp_disable_menus_items;
        $disabled = false;
        $_level   = 'level_' . $level;
        if ( is_array( $_mainwp_disable_menus_items ) && isset( $_mainwp_disable_menus_items[ $_level ] ) && isset( $_mainwp_disable_menus_items[ $_level ][ $item ] ) ) {
            $disabled = $_mainwp_disable_menus_items[ $_level ][ $item ];
        }
        $disabled                                        = apply_filters( 'mainwp_is_disable_menu_item', $disabled, $level, $item );
        $_mainwp_disable_menus_items[ $_level ][ $item ] = $disabled;
        return $disabled;
    }

    /**
     * Method add_left_menu
     *
     * Build Top Level Menu
     *
     * @param array   $params Menu Item parameters.
     * @param integer $level Menu Item Level 1 or 2.
     *
     * @return array $mainwp_leftmenu[] | $mainwp_sub_leftmenu[].
     */
    public static function add_left_menu( $params = array(), $level = 1 ) { //phpcs:ignore -- NOSONAR - complex method.

        if ( empty( $params ) ) {
            return;
        }

        if ( ! empty( $params['menu_hidden'] ) ) {
            return;
        }

        $level = (int) $level;

        if ( 1 !== $level && 2 !== $level && 0 !== $level ) {
            $level = 1;
        }

        $title        = $params['title'];
        $before_title = ! empty( $params['before_title'] ) ? $params['before_title'] : '';

        $slug  = isset( $params['slug'] ) ? $params['slug'] : '';
        $href  = isset( $params['href'] ) ? $params['href'] : '';
        $right = isset( $params['right'] ) ? $params['right'] : '';
        $id    = isset( $params['id'] ) ? $params['id'] : '';

        $icon                 = isset( $params['icon'] ) ? $params['icon'] : '';
        $leftsub_order        = isset( $params['leftsub_order'] ) ? $params['leftsub_order'] : '';
        $leftsub_order_level2 = isset( $params['leftsub_order_level2'] ) ? $params['leftsub_order_level2'] : '';
        $ext_state            = isset( $params['ext_status'] ) && ( 'activated' === $params['ext_status'] || 'inactive' === $params['ext_status'] ) ? $params['ext_status'] : '';
        $parent_key           = isset( $params['parent_key'] ) ? $params['parent_key'] : '';
        $others               = array();
        if ( isset( $params['active_params'] ) ) {
            $others['active_params'] = $params['active_params'];
        }

        /**
         * MainWP Left Menu, Sub Menu & Active menu slugs.
         *
         * @global object $mainwp_leftmenu
         * @global object $mainwp_sub_leftmenu
         * @global object $_mainwp_menu_active_slugs
         */
        global $mainwp_leftmenu, $mainwp_sub_leftmenu, $_mainwp_menu_active_slugs;

        if ( ! is_array( $mainwp_leftmenu ) ) {
            $mainwp_leftmenu = array();
        }

        if ( ! isset( $mainwp_leftmenu['mainwp_tab'] ) ) {
            $mainwp_leftmenu['mainwp_tab'] = array(); // to compatible with old hooks.
        }

        if ( ! is_array( $_mainwp_menu_active_slugs ) ) {
            $_mainwp_menu_active_slugs = array();
        }

        $active_path = false;

        if ( isset( $params['active_path'] ) && is_array( $params['active_path'] ) && ! empty( $params['active_path'] ) ) {
            $active_path = $params['active_path'];
            reset( $active_path );
            $item   = key( $active_path );
            $active = current( $active_path );
            $_mainwp_menu_active_slugs['leftbar'][ $item ]     = $active;
            $_mainwp_menu_active_slugs['parent_slug'][ $item ] = $parent_key;
        }

        if ( 0 === $level ) {
            $parent_key                   = 'mainwp_tab'; // forced value.
            $mainwp_leftmenu['leftbar'][] = array( $title, $slug, $href, $id, $icon );
        } elseif ( 1 === $level ) {

            if ( empty( $parent_key ) ) {
                $parent_key = 'mainwp_tab'; // forced value.
            }

            if ( 'mainwp_tab' === $parent_key ) {
                $mainwp_leftmenu[ $parent_key ][] = array( $title, $slug, $href, $id );
            } else {
                $mainwp_sub_leftmenu['leftbar'][ $parent_key ][] = array( $title, $slug, $href, $id, $leftsub_order, $ext_state, $active_path );

                if ( ! empty( $slug ) ) {
                    $_mainwp_menu_active_slugs['leftbar'][ $slug ] = $parent_key; // to get active menu.
                }
            }
        } else {
            if ( empty( $parent_key ) ) {
                $parent_key = 'mainwp_tab'; // forced value.
            }
            $mainwp_sub_leftmenu[ $parent_key ][] = array( $title, $href, $right, $id, $slug, $leftsub_order_level2, $ext_state, $active_path, $before_title, $others );
        }

        if ( ! empty( $slug ) ) {
            $no_submenu = ! empty( $params['nosubmenu'] ) ? true : false;
            if ( $no_submenu ) {
                $_mainwp_menu_active_slugs[ $slug ] = $slug; // to get active menu.
            } else {
                $_mainwp_menu_active_slugs[ $slug ] = $parent_key; // to get active menu.
            }
        }
    }

    /**
     * Method render_left_menu
     *
     * Build Top Level Main Menu HTML & Render.
     */
    public static function render_left_menu() { // phpcs:ignore -- NOSONAR -Current complexity is the only way to achieve desired results, pull request solutions appreciated.

        /**
         * MainWP Left Menu, Sub Menu & Active menu slugs.
         *
         * @global object $mainwp_leftmenu
         * @global object $mainwp_sub_leftmenu
         * @global object $_mainwp_menu_active_slugs
         */
        global $mainwp_leftmenu, $mainwp_sub_leftmenu, $_mainwp_menu_active_slugs, $plugin_page;

        /**
         * Filter: mainwp_main_menu
         *
         * Filters main navigation menu items
         *
         * @since 4.0
         */
        $mainwp_leftmenu = apply_filters( 'mainwp_main_menu', $mainwp_leftmenu );
        $bar_leftmenu    = isset( $mainwp_leftmenu['leftbar'] ) ? $mainwp_leftmenu['leftbar'] : array();

        /**
         * Filter: mainwp_main_menu_submenu
         *
         * Filters main navigation subt-menu items
         *
         * @since 4.0
         */

        $mainwp_sub_leftmenu = apply_filters( 'mainwp_main_menu_submenu', $mainwp_sub_leftmenu );
        $sub_bar_leftmenu    = isset( $mainwp_sub_leftmenu['leftbar'] ) ? $mainwp_sub_leftmenu['leftbar'] : array();

        $version = get_option( 'mainwp_plugin_version' );

        ?>
        <?php
        /**
         * Action: before_mainwp_menu
         *
         * Fires before the main navigation element.
         *
         * @since 4.0
         */
        do_action( 'before_mainwp_menu' );
        ?>
        <div id="mainwp-main-navigation-container">
            <div id="mainwp-first-level-navigation">
                <div id="mainwp-first-level-navigation-menu" class="ui vertical labeled inverted icon tiny menu">
                <?php

                $bar_item_actived_key = '';
                if ( is_array( $_mainwp_menu_active_slugs ) && isset( $_mainwp_menu_active_slugs[ $plugin_page ] ) ) {
                    $menu_item_actived_key = $_mainwp_menu_active_slugs[ $plugin_page ];
                    if ( isset( $_mainwp_menu_active_slugs['leftbar'] ) && is_array( $_mainwp_menu_active_slugs['leftbar'] ) && isset( $_mainwp_menu_active_slugs['leftbar'][ $menu_item_actived_key ] ) ) {
                        $bar_item_actived_key = $_mainwp_menu_active_slugs['leftbar'][ $menu_item_actived_key ];
                    }
                }

                $bar_item_active = null;

                $sites_count = MainWP_DB::instance()->get_websites_count();
                if ( empty( $sites_count ) ) {
                    ?>
                    <a style="background: #FFD300 !important;" id="leftbar-item-quick-setup" title="<?php esc_html_e( 'Quick Setup', 'mainwp' ); ?>" class="item" href="admin.php?page=mainwp-setup">
                        <i class="magic large icon"></i><span class="ui small text"><?php esc_html_e( 'Quick Setup', 'mainwp' ); ?></span>
                    </a>
                    <?php
                }

                if ( is_array( $bar_leftmenu ) && ! empty( $bar_leftmenu ) ) {
                    foreach ( $bar_leftmenu as $item ) {
                        $title     = wptexturize( $item[0] );
                        $item_key  = $item[1];
                        $href      = $item[2];
                        $item_id   = isset( $item[3] ) ? $item[3] : '';
                        $item_icon = isset( $item[4] ) ? $item[4] : '';

                        $has_sub = true;
                        if ( ! isset( $mainwp_sub_leftmenu[ $item_key ] ) || empty( $mainwp_sub_leftmenu[ $item_key ] ) ) {
                            $has_sub = false;
                        }
                        $active_item = '';

                        if ( empty( $bar_item_actived_key ) ) {
                            if ( isset( $_mainwp_menu_active_slugs['leftbar'][ $plugin_page ] ) ) {
                                if ( $item_key === $_mainwp_menu_active_slugs['leftbar'][ $plugin_page ] ) {
                                    $bar_item_actived_key = $item_key;
                                }
                            } elseif ( isset( $_mainwp_menu_active_slugs[ $plugin_page ] ) ) {
                                if ( $item_key === $_mainwp_menu_active_slugs[ $plugin_page ] ) {
                                    $bar_item_actived_key = $item_key;
                                }
                            }
                        }

                        if ( ! empty( $bar_item_actived_key ) && $item_key === $bar_item_actived_key ) {
                            $active_item     = 'active';
                            $bar_item_active = $item;
                        }

                        $id_attr = ! empty( $item_id ) ? 'id="' . esc_html( $item_id ) . '"' : '';

                        // phpcs:disable WordPress.Security.EscapeOutput
                        echo '<a ' . $id_attr . ' title="' . esc_html( $title ) . "\" class=\"item $active_item\" href=\"$href\">";
                        echo ! empty( $item_icon ) ? $item_icon : '<i class="th large icon"></i>';
                        echo '<span class="ui small text">' . esc_html( $title ) . '</span>';
                        echo '</a>';
                        // phpcs:enable
                    }
                }
                ?>

                <a id="mainwp-help-menu-item" title="<?php esc_attr_e( 'Help', 'mainwp' ); ?>" class="item" href="#" style="opacity:0.3;">
                    <i class="question circle outline icon"></i>
                    <span class="ui small text"><?php esc_html_e( 'Quick Help', 'mainwp' ); ?></span>
                </a>
                </div>
                <?php
                    $all_updates         = wp_get_update_data();
                    $go_back_wpadmin_url = admin_url( 'index.php' );

                    $link = array(
                        'url'  => $go_back_wpadmin_url,
                        'text' => esc_html__( 'WP Admin', 'mainwp' ),
                        'tip'  => esc_html__( 'Click to go back to the site WP Admin area.', 'mainwp' ),
                    );
                    /**
                     * Filter: mainwp_go_back_wpadmin_link
                     *
                     * Filters URL for the Go to WP Admin button in Main navigation.
                     *
                     * @since 4.0
                     */
                    $go_back_link = apply_filters( 'mainwp_go_back_wpadmin_link', $link );

                    if ( false !== $go_back_link && is_array( $go_back_link ) ) {
                        if ( isset( $go_back_link['url'] ) ) {
                            $link['url'] = $go_back_link['url'];
                        }
                        if ( isset( $go_back_link['text'] ) ) {
                            $link['text'] = $go_back_link['text'];
                        }
                        if ( isset( $go_back_link['tip'] ) ) {
                            $link['tip'] = $go_back_link['tip'];
                        }
                    }
                    ?>
                <div id="mainwp-first-level-wpitems-menu" class="ui vertical labeled inverted icon mini menu">
                    <a class="item" href="#" id="mainwp-collapse-second-level-navigation" aria-label="<?php esc_attr_e( 'Collapse menu.', 'mainwp' ); ?>">
                        <i class="double angle left icon"></i>
                    </a>
                </div>
                <div id="mainwp-first-level-navigation-version-label">
                    <?php if ( is_array( $all_updates ) && isset( $all_updates['counts']['total'] ) && 0 < $all_updates['counts']['total'] ) : ?>
                    <a class="ui tiny red fluid centered pulse looping transition label" id="mainwp-dashboard-update-available" data-inverted="" data-position="left center" data-tooltip="<?php esc_attr_e( 'Your MainWP Dashboard sites needs your attention. Please check the available updates', 'mainwp' ); ?>" aria-label="<?php esc_attr_e( 'Your MainWP Dashboard sites needs your attention. Please check the available updates', 'mainwp' ); ?>" href="update-core.php">
                        <i class="exclamation triangle icon" style="color:#fff"></i> <?php esc_html_e( 'Update Dashboard Site', 'mainwp' ); ?>
                    </a>
                    <?php endif; ?>
                    <div id="mainwp-version-label" class="ui tiny green fluid centered label"><?php echo esc_html( $version ); ?></div>
                </div>
            </div>
            <div id="mainwp-second-level-navigation">
                <div id="mainwp-main-menu" class="ui inverted vertical accordion menu">
                    <?php
                    $bar_active_item_key = '';

                    $set_actived = false;

                    if ( ! empty( $bar_item_active ) ) {
                        $item     = $bar_item_active;
                        $title    = wptexturize( $item[0] );
                        $item_key = $item[1];
                        $href     = $item[2];
                        $item_id  = isset( $item[3] ) ? $item[3] : '';

                        $bar_active_item_key = $item_key;

                        $has_sub = true;
                        if ( ! isset( $mainwp_sub_leftmenu[ $item_key ] ) || empty( $mainwp_sub_leftmenu[ $item_key ] ) ) {
                            $has_sub = false;
                        }
                        $active_item = '';

                        if ( ! $set_actived && isset( $_mainwp_menu_active_slugs['parent_slug'][ $plugin_page ] ) && $item_key === $_mainwp_menu_active_slugs['parent_slug'][ $plugin_page ] ) {
                            $active_item = 'active';
                            $set_actived = true;
                        }

                        // to fix active menu.
                        if ( ! $set_actived && isset( $_mainwp_menu_active_slugs[ $plugin_page ] ) && $item_key === $_mainwp_menu_active_slugs[ $plugin_page ] ) {
                            $active_item = 'active';
                            $set_actived = true;
                        }

                        $id_attr = ! empty( $item_id ) ? 'id="' . esc_html( $item_id ) . '"' : '';

                        // phpcs:disable WordPress.Security.EscapeOutput
                        if ( $has_sub ) {
                            echo '<div ' . $id_attr . " class=\"item $active_item\">";
                            echo "<a class=\"title with-sub $active_item\" href=\"$href\">$title <i class=\"dropdown icon\"></i></a>";
                            echo "<div class=\"content menu $active_item\">";
                            static::render_sub_item( $item_key );
                            echo '</div>';
                            echo '</div>';
                        } else {
                            echo '<div ' . $id_attr . ' class="item">';
                            echo "<a class='title $active_item' href=\"$href\">$title</a>";
                            echo '</div>';
                        }
                        // phpcs:enable

                        if ( is_array( $sub_bar_leftmenu ) && ! empty( $bar_active_item_key ) && isset( $sub_bar_leftmenu[ $bar_active_item_key ] ) && is_array( $sub_bar_leftmenu[ $bar_active_item_key ] ) ) {
                            $sub_bar_leftmenu_active_items = $sub_bar_leftmenu[ $bar_active_item_key ];
                            MainWP_Utility::array_sort_existed_keys( $sub_bar_leftmenu_active_items, 4 ); //phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- 4 => 'leftsub_order'.
                            $set_actived = false;
                            foreach ( $sub_bar_leftmenu_active_items as $item ) {

                                if ( empty( $item ) || ! is_array( $item ) ) {
                                    continue;
                                }

                                $title    = wptexturize( $item[0] );
                                $item_key = $item[1];

                                $has_sub = true;
                                if ( ! isset( $mainwp_sub_leftmenu[ $item_key ] ) || empty( $mainwp_sub_leftmenu[ $item_key ] ) ) {
                                    $has_sub = false;
                                }

                                $href      = $item[2];
                                $item_id   = isset( $item[3] ) ? $item[3] : '';
                                $ext_state = isset( $item[5] ) ? $item[5] : '';

                                $item_classes = 'inactive' === $ext_state ? 'extension-inactive' : '';

                                $active_item = '';

                                if ( ! $set_actived && isset( $_mainwp_menu_active_slugs['parent_slug'][ $plugin_page ] ) && $item_key === $_mainwp_menu_active_slugs['parent_slug'][ $plugin_page ] ) {
                                    $active_item = 'active';
                                    $set_actived = true;
                                }

                                // to fix active menu.
                                if ( ! $set_actived && isset( $_mainwp_menu_active_slugs[ $plugin_page ] ) && $item_key === $_mainwp_menu_active_slugs[ $plugin_page ] ) {
                                    $active_item = 'active';
                                    $set_actived = true;
                                }

                                $id_attr = ! empty( $item_id ) ? 'id="' . esc_html( $item_id ) . '"' : '';

                                $hide_item = '';
                                if ( 'admin.php?page=ManageApiBackups' === $href ) {
                                    $hide_item = ' style="display:none"';
                                }

                                // phpcs:disable WordPress.Security.EscapeOutput
                                if ( $has_sub ) {
                                    echo '<div ' . $id_attr . " class=\"item $active_item $item_classes\">";
                                    echo "<a class=\"title with-sub $active_item\" href=\"$href\">$title <i class=\"dropdown icon\"></i></a>";
                                    echo "<div class=\"content menu $active_item\">";
                                    static::render_sub_item( $item_key );
                                    echo '</div>';
                                    echo '</div>';
                                } else {
                                    echo '<div ' . $id_attr . $hide_item . " class=\"item $active_item $item_classes\">";
                                    echo "<a class='title $active_item' href=\"$href\">$title</a>";
                                    echo '</div>';
                                }
                                // phpcs:enable
                            }
                        }
                    }
                    ?>
                    </div>
                    </div>
                </div>

                <?php
                /**
                 * Action: after_mainwp_menu
                 *
                 * Fires after the main navigation element.
                 *
                 * @since 4.0
                 */
                do_action( 'after_mainwp_menu' );
                ?>
            <script type="text/javascript">

                jQuery( document ).ready( function () {

                    setTimeout(() => {
                        jQuery('#mainwp-dashboard-update-available').removeClass('looping');
                    }, 1500);

                    let mainwp_left_bar_showhide_init = function(){
                        if(jQuery('body').hasClass('mainwp-hidden-second-level-navigation')){
                            return; // hide always.
                        }
                        if(jQuery('body').hasClass('toplevel_page_mainwp_tab')){
                            return; // hide always.
                        }
                        let lbar = jQuery( '#mainwp-collapse-second-level-navigation' );
                        let show = ( typeof mainwp_ui_state_load !== 'undefined' ) && 0 != mainwp_ui_state_load( 'showmenu' );
                        mainwp_left_bar_showhide( lbar, show);
                    }
                    mainwp_left_bar_showhide = function( lbar, show ){
                        if ( show ) {
                            jQuery( '#mainwp-second-level-navigation' ).show();
                            jQuery( '.mainwp-content-wrap' ).css( "margin-left", "272px" );
                            //jQuery( '#mainwp-screenshots-sites' ).css( "margin-left", "272px" );
                            jQuery( '#mainwp-main-navigation-container' ).css( "width", "272px" );
                            jQuery( lbar ).find( '.icon' ).removeClass( 'right' );
                            jQuery( lbar ).find( '.icon' ).addClass( 'left' );
                            jQuery( lbar ).css( "left", "272px" );
                            jQuery( lbar ).removeClass( 'collapsed' );
                            if( ( typeof mainwp_ui_state_save !== 'undefined' ) ) {
                                mainwp_ui_state_save( 'showmenu', 1 );
                            }
                        } else {
                            jQuery( '#mainwp-second-level-navigation' ).hide();
                            jQuery( '.mainwp-content-wrap' ).css( "margin-left", "72px" );
                            //jQuery( '#mainwp-screenshots-sites' ).css( "margin-left", "72px" );
                            jQuery( '#mainwp-main-navigation-container' ).css( "width", "72px" );
                            jQuery( lbar ).find( '.icon' ).removeClass( 'left' );
                            jQuery( lbar ).find( '.icon' ).addClass( 'right' );
                            jQuery( lbar ).css( "left", "72px" );
                            jQuery( lbar ).addClass( 'collapsed' );
                            jQuery( '#mainwp-top-header' ).css( "width", "100%" );
                            if( ( typeof mainwp_ui_state_save !== 'undefined' ) ) {
                                mainwp_ui_state_save( 'showmenu', 0 );
                            }
                        }
                    }

                    jQuery( '#mainwp-collapse-second-level-navigation' ).on( 'click', function() {
                        let show = jQuery( this ).hasClass( 'collapsed' ) ? true : false;
                        mainwp_left_bar_showhide( this, show);
                        return false;
                    } );
                    mainwp_left_bar_showhide_init();

                    // click on menu with-sub icon.
                    jQuery( '#mainwp-main-navigation-container #mainwp-main-menu a.title.with-sub .icon' ).on( "click", function ( event ) {
                        let pr = jQuery( this ).closest( '.item' );
                        let title = jQuery( this ).closest( '.title' );
                        let active = jQuery( title ).hasClass( 'active' );

                        // remove current active.
                        mainwp_menu_collapse();

                        // if current menu item are not active then set it active.
                        if ( !active ) {
                            jQuery( title ).addClass( 'active' );
                            jQuery( pr ).find('.content.menu').addClass( 'active' );
                            pr.addClass('active');
                        }
                        return false;
                    } );

                    jQuery( '#mainwp-main-navigation-container #mainwp-main-menu a.title.with-sub' ).on( "click", function ( event ) {
                        let pr = jQuery( this ).closest( '.item' );
                        let active = jQuery( this ).hasClass( 'active' );

                        // remove current active.
                        mainwp_menu_collapse();

                        // set active before go to the page.
                        if ( !active ) {
                            jQuery( this ).addClass( 'active' );
                            jQuery( pr ).find('.content.menu').addClass( 'active' );
                            pr.addClass('active');
                        }
                    } );

                    mainwp_menu_collapse = function() {
                        // remove current active.
                        jQuery( '#mainwp-main-navigation-container #mainwp-main-menu a.title.active').removeClass('active');
                        jQuery( '#mainwp-main-navigation-container #mainwp-main-menu .item').removeClass('active');
                        jQuery( '#mainwp-main-navigation-container #mainwp-main-menu .content.menu.active').removeClass('active');
                    };

                    jQuery('.mainwp-main-mobile-navigation-container #mainwp-main-menu').accordion();

                } );
            </script>
        <?php
    }

    /**
     * Method render_mobile_menu
     *
     * Renders the mobile menu.
     */
    public static function render_mobile_menu() { // phpcs:ignore -- NOSONAR -Current complexity is the only way to achieve desired results, pull request solutions appreciated.
        $mainwp_show_language_updates = get_option( 'mainwp_show_language_updates', 1 );
        ?>
        <div class="mainwp-main-mobile-navigation-container">
            <div class="mainwp-nav-menu">
                <?php
                /**
                 * Action: before_mainwp_menu
                 *
                 * Fires before the main navigation element.
                 *
                 * @since 4.0
                 */
                do_action( 'before_mainwp_menu' );
                ?>

                <div id="mainwp-main-menu"  class="test-menu ui inverted vertical accordion menu">
                    <div class="hamburger">
                        <span class="hamburger-bun"></span>
                        <span class="hamburger-patty"></span>
                        <span class="hamburger-bun"></span>
                    </div>

                    <div class="item"><a href="admin.php?page=mainwp_tab"><?php esc_html_e( 'Overview', 'mainwp' ); ?></a></div>
                    <div class="item">
                        <div class="title"><a href="admin.php?page=managesites" class=" with-sub"><?php esc_html_e( 'Sites', 'mainwp' ); ?></a><i class="dropdown icon"></i></div>
                        <div class="content menu" id="mainwp-sites-mobile-menu-item">
                                <div class="accordion item">
                                    <div class="title"><a href="admin.php?page=managesites"><?php esc_html_e( 'Sites', 'mainwp' ); ?></a><i class="dropdown icon"></i></div>
                                    <div class="content menu">
                                        <a class="item" href="admin.php?page=managesites"><?php esc_html_e( 'Manage Sites', 'mainwp' ); ?></a>
                                        <a class="item" href="admin.php?page=managesites&do=new"><?php esc_html_e( 'Add New Site', 'mainwp' ); ?></a>
                                        <a class="item" href="admin.php?page=managesites&do=bulknew"><?php esc_html_e( 'Import Sites', 'mainwp' ); ?></a>
                                        <a class="item" href="admin.php?page=MonitoringSites"><?php esc_html_e( 'Monitoring', 'mainwp' ); ?></a>
                                    </div>
                                </div>
                                <div class="item accordion">
                                    <div class="title"><a class="" href="admin.php?page=ManageGroups"><?php esc_html_e( 'Tags', 'mainwp' ); ?></a><i class="dropdown icon"></i></div>
                                <div class="content menu">
                                    <a class="item" href="admin.php?page=ManageGroups"><?php esc_html_e( 'Manage Tags', 'mainwp' ); ?></a>
                                </div>
                                </div>
                                <div class="item accordion">
                                    <div class="title"><a href="admin.php?page=UpdatesManage"><?php esc_html_e( 'Updates', 'mainwp' ); ?></a><i class="dropdown icon"></i></div>
                                    <div class="content menu">
                                        <a class="item" href="admin.php?page=UpdatesManage&tab=plugins-updates"><?php esc_html_e( 'Plugin Updates', 'mainwp' ); ?></a>
                                        <a class="item" href="admin.php?page=UpdatesManage&tab=themes-updates"><?php esc_html_e( 'Theme Updates', 'mainwp' ); ?></a>
                                        <a class="item" href="admin.php?page=UpdatesManage&tab=wordpress-updates"><?php esc_html_e( 'WordPress Updates', 'mainwp' ); ?></a>
                                    <?php if ( $mainwp_show_language_updates ) : ?>
                                        <a class="item" href="admin.php?page=UpdatesManage&tab=translations-updates"><?php esc_html_e( 'Translation Plugins', 'mainwp' ); ?></a>
                                    <?php endif; ?>
                                        <a class="item" href="admin.php?page=PluginsAbandoned"><?php esc_html_e( 'Abandoned Plugins', 'mainwp' ); ?></a>
                                        <a class="item" href="admin.php?page=ThemesAbandoned"><?php esc_html_e( 'Abandoned Themes', 'mainwp' ); ?></a>
                                    </div>
                                </div>
                                <div class="item accordion">
                                    <div class="title"><a href="admin.php?page=PluginsManage"><?php esc_html_e( 'Plugins', 'mainwp' ); ?></a><i class="dropdown icon"></i></div>
                                    <div class="content menu">
                                        <a class="item" href="admin.php?page=PluginsManage"><?php esc_html_e( 'Manage Plugins', 'mainwp' ); ?></a>
                                        <a class="item" href="admin.php?page=PluginsInstall"><?php esc_html_e( 'Install', 'mainwp' ); ?></a>
                                        <a class="item" href="admin.php?page=PluginsAutoUpdate"><?php esc_html_e( 'Advanced Auto Updates', 'mainwp' ); ?></a>
                                        <a class="item" href="admin.php?page=PluginsIgnore"><?php esc_html_e( 'Ignored Updates', 'mainwp' ); ?></a>
                                        <a class="item" href="admin.php?page=PluginsIgnoredAbandoned"><?php esc_html_e( 'Ignored Abandoned', 'mainwp' ); ?></a>
                                    </div>
                                </div>
                                <div class="item accordion">
                                    <div class="title"><a href="admin.php?page=ThemesManage"><?php esc_html_e( 'Themes', 'mainwp' ); ?></a><i class="dropdown icon"></i></div>
                                    <div class="content menu">
                                        <a class="item" href="admin.php?page=ThemesManage"><?php esc_html_e( 'Manage Themes', 'mainwp' ); ?></a>
                                        <a class="item" href="admin.php?page=ThemesInstall"><?php esc_html_e( 'Install', 'mainwp' ); ?></a>
                                        <a class="item" href="admin.php?page=ThemesAutoUpdate"><?php esc_html_e( 'Advanced Auto Updates', 'mainwp' ); ?></a>
                                        <a class="item" href="admin.php?page=ThemesIgnore"><?php esc_html_e( 'Ignored Updates', 'mainwp' ); ?></a>
                                        <a class="item" href="admin.php?page=ThemesIgnoredAbandoned"><?php esc_html_e( 'Ignored Abandoned', 'mainwp' ); ?></a>
                                    </div>
                                </div>
                                <div class="item accordion">
                                    <div class="title"><a href="admin.php?page=UserBulkManage"><?php esc_html_e( 'Users', 'mainwp' ); ?></a><i class="dropdown icon"></i></div>
                                    <div class="content menu">
                                        <a class="item" href="admin.php?page=UserBulkManage"><?php esc_html_e( 'Manage Users', 'mainwp' ); ?></a>
                                        <a class="item" href="admin.php?page=UserBulkAdd"><?php esc_html_e( 'Add New User', 'mainwp' ); ?></a>
                                        <a class="item" href="admin.php?page=BulkImportUsers"><?php esc_html_e( 'Import Users', 'mainwp' ); ?></a>
                                        <a class="item" href="admin.php?page=UpdateAdminPasswords"><?php esc_html_e( 'Admin Passwords', 'mainwp' ); ?></a>
                                    </div>
                                </div>
                                <div class="item accordion">
                                    <div class="title"><a href="admin.php?page=PostBulkManage"><?php esc_html_e( 'Posts', 'mainwp' ); ?></a><i class="dropdown icon"></i></div>
                                    <div class="content menu">
                                        <a class="item" href="admin.php?page=PostBulkManage"><?php esc_html_e( 'Manage Pages', 'mainwp' ); ?></a>
                                        <a class="item" href="admin.php?page=PostBulkAdd"><?php esc_html_e( 'Add New Post', 'mainwp' ); ?></a>
                                    </div>
                                </div>
                                <div class="item accordion">
                                    <div class="title"><a href="admin.php?page=PageBulkManage"><?php esc_html_e( 'Pages', 'mainwp' ); ?></a><i class="dropdown icon"></i></div>
                                    <div class="content menu">
                                        <a class="item" href="admin.php?page=PageBulkManage"><?php esc_html_e( 'Manage Pages', 'mainwp' ); ?></a>
                                        <a class="item" href="admin.php?page=PageBulkAdd"><?php esc_html_e( 'Add New Page', 'mainwp' ); ?></a>
                                    </div>
                                </div>
                                <div class="item accordion">
                                    <div class="title"><a href="admin.php?page=ManageApiBackups"><?php esc_html_e( 'Backups', 'mainwp' ); ?></a><i class="dropdown icon"></i></div>
                                    <div class="content menu">
                                        <a class="item" href="admin.php?page=ManageApiBackups"><?php esc_html_e( 'Backups', 'mainwp' ); ?></a>
                                    </div>
                                </div>
                        </div>
                    </div>
                    <div class="item">
                        <div class="title"><a href="admin.php?page=ManageClients" class="with-sub"><?php esc_html_e( 'Clients', 'mainwp' ); ?></a><i class="dropdown icon"></i></div>
                        <div class="content menu">
                            <a class="item" href="admin.php?page=ManageClients"><?php esc_html_e( 'Clients', 'mainwp' ); ?></a>
                            <a class="item" href="admin.php?page=ClientAddNew"><?php esc_html_e( 'Add Client', 'mainwp' ); ?></a>
                            <a class="item" href="admin.php?page=ClientAddField"><?php esc_html_e( 'Client Fields', 'mainwp' ); ?></a>
                        </div>
                    </div>

                    <div class="item">
                        <div class="title"><a href="admin.php?page=ManageCostTracker" class="with-sub"><?php esc_html_e( 'Cost Tracker', 'mainwp' ); ?></a><i class="dropdown icon"></i></div>
                        <div class="content menu">
                            <a class="item" href="admin.php?page=ManageCostTracker"><?php esc_html_e( 'Cost Tracker', 'mainwp' ); ?></a>
                            <a class="item" href="admin.php?page=CostTrackerAdd"><?php esc_html_e( 'Add Cost', 'mainwp' ); ?></a>
                            <a class="item" href="admin.php?page=CostTrackerSettings"><?php esc_html_e( 'Cost Tracker Settings', 'mainwp' ); ?></a>
                        </div>
                    </div>

                    <div class="item">
                        <div class="title"><a href="admin.php?page=InsightsOverview" class="with-sub"><?php esc_html_e( 'Insights', 'mainwp' ); ?></a><i class="dropdown icon"></i></div>
                        <div class="content menu">
                            <a class="item" href="admin.php?page=InsightsOverview"><?php esc_html_e( 'Insights', 'mainwp' ); ?></a>
                        </div>
                    </div>

                    <div class="item">
                        <div class="title"><a href="admin.php?page=RESTAPI" class="with-sub"><?php esc_html_e( 'REST API', 'mainwp' ); ?></a><i class="dropdown icon"></i></div>
                        <div class="content menu">
                            <a class="item" href="admin.php?page=RESTAPI"><?php esc_html_e( 'Manage API Keys', 'mainwp' ); ?></a>
                            <a class="item" href="admin.php?page=AddApiKeys"><?php esc_html_e( 'Add API Keys', 'mainwp' ); ?></a>
                        </div>
                    </div>
                    <div class="item">
                        <div class="title"><a href="admin.php?page=Extensions" class=""><?php esc_html_e( 'Extensions', 'mainwp' ); ?></a><i class="dropdown icon"></i></div>
                        <div class="content menu">
                            <a class="item" href="admin.php?page=Extensions"><?php esc_html_e( 'Manage Extensions', 'mainwp' ); ?></a>
                        </div>
                    </div>
                    <div class="item">
                        <div class="title"><a href="admin.php?page=Settings" class="with-sub"><?php esc_html_e( 'Settings', 'mainwp' ); ?></a><i class="dropdown icon"></i></div>
                        <div class="content menu">
                            <a class="item" href="admin.php?page=Settings"><?php esc_html_e( 'General Settings', 'mainwp' ); ?></a>
                            <a class="item" href="admin.php?page=SettingsAdvanced"><?php esc_html_e( 'Advanced Settings', 'mainwp' ); ?></a>
                            <a class="item" href="admin.php?page=SettingsEmail"><?php esc_html_e( 'Email Settings', 'mainwp' ); ?></a>
                            <a class="item" href="admin.php?page=MainWPTools"><?php esc_html_e( 'Tools', 'mainwp' ); ?></a>
                        </div>
                    </div>
                    <div class="item">
                        <div class="title"><a href="admin.php?page=ServerInformation" class="with-sub"><?php esc_html_e( 'Info', 'mainwp' ); ?></a><i class="dropdown icon"></i></div>
                        <div class="content menu">
                            <a class="item" href="admin.php?page=ServerInformation"><?php esc_html_e( 'Server', 'mainwp' ); ?></a>
                            <a class="item" href="admin.php?page=ServerInformationCron"><?php esc_html_e( 'Cron Schedules', 'mainwp' ); ?></a>
                            <a class="item" href="admin.php?page=ErrorLog"><?php esc_html_e( 'Error Log', 'mainwp' ); ?></a>
                            <a class="item" href="admin.php?page=ActionLogs"><?php esc_html_e( 'Custom Event Monitor', 'mainwp' ); ?></a>
                            <a class="item" href="admin.php?page=PluginPrivacy"><?php esc_html_e( 'Plugin Privacy', 'mainwp' ); ?></a>
                        </div>
                    </div>
                    <div class="item">
                        <a id="mainwp-help-menu-item" title="<?php esc_attr_e( 'Help', 'mainwp' ); ?>" class="item" href="#" style="opacity:0.3;"><?php esc_html_e( 'Quick Help', 'mainwp' ); ?></a>
                    </div>
                    <?php
                    $go_back_wpadmin_url = admin_url( 'index.php' );

                    $link = array(
                        'url'  => $go_back_wpadmin_url,
                        'text' => esc_html__( 'WP Admin', 'mainwp' ),
                        'tip'  => esc_html__( 'Click to go back to the site WP Admin area.', 'mainwp' ),
                    );

                    /**
                     * Filter: mainwp_go_back_wpadmin_link
                     *
                     * Filters URL for the Go to WP Admin button in Main navigation.
                     *
                     * @since 4.0
                     */
                    $go_back_link = apply_filters( 'mainwp_go_back_wpadmin_link', $link );

                    if ( false !== $go_back_link ) {
                        if ( is_array( $go_back_link ) ) {
                            if ( isset( $go_back_link['url'] ) ) {
                                $link['url'] = $go_back_link['url'];
                            }
                            if ( isset( $go_back_link['text'] ) ) {
                                $link['text'] = $go_back_link['text'];
                            }
                            if ( isset( $go_back_link['tip'] ) ) {
                                $link['tip'] = $go_back_link['tip'];
                            }
                        }
                        ?>
                    <div class="item item-wp-admin">
                        <a href="<?php echo esc_html( $link['url'] ); ?>" class="title" style="display:inline" data-position="top left" data-tooltip="<?php echo esc_html( $link['tip'] ); ?>"><b><i class="icon wordpress"></i> <?php echo esc_html( $link['text'] ); ?></b></a> <a class="ui small label" data-position="top right" data-tooltip="<?php esc_html_e( 'Logout', 'mainwp' ); ?>" href="<?php echo wp_logout_url(); ?>"><i class="sign out icon" style="margin:0"></i></a> <?php //phpcs:ignore -- to avoid auto fix icon wordpress ?>
                    </div>
                    <?php } ?>

                </div>
                <?php
                /**
                 * Action: after_mainwp_menu
                 *
                 * Fires after the main navigation element.
                 *
                 * @since 4.0
                 */
                do_action( 'after_mainwp_menu' );
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Method render_sub_item
     *
     * Grabs all submenu items and attatches to Main Menu.
     *
     * @param mixed $parent_key The parent key.
     */
    public static function render_sub_item( $parent_key ) { //phpcs:ignore -- NOSONAR - complex method.
        if ( empty( $parent_key ) ) {
            return;
        }

        /**
         * MainWP Left Menu.
         *
         * @global object $mainwp_sub_leftmenu
         */
        global $mainwp_sub_leftmenu, $_mainwp_menu_active_slugs;

        $submenu_items = $mainwp_sub_leftmenu[ $parent_key ];

        if ( ! is_array( $submenu_items ) || empty( $submenu_items ) ) {
            return;
        }

        global $plugin_page;

        $set_actived = false;

        MainWP_Utility::array_sort_existed_keys( $submenu_items, 5 ); //phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- 5 => 'leftsub_order_level2'.

        foreach ( $submenu_items as $sub_item ) {
            $title        = $sub_item[0];
            $href         = $sub_item[1];
            $right        = $sub_item[2];
            $id           = isset( $sub_item[3] ) ? $sub_item[3] : '';
            $slug         = isset( $sub_item[4] ) ? $sub_item[4] : '';
            $ext_state    = isset( $sub_item[6] ) ? $sub_item[6] : '';
            $active_path  = isset( $sub_item[7] ) ? $sub_item[7] : '';
            $before_title = isset( $sub_item[8] ) ? $sub_item[8] : '';
            $others       = isset( $sub_item[9] ) ? $sub_item[9] : array();

            if ( ! is_array( $others ) ) {
                $others = array();
            }

            $item_classes = 'inactive' === $ext_state ? 'extension-inactive' : '';

            $_blank = false;
            if ( '_blank' === $id ) {
                $_blank = true;
            }

            $level2_active = false;

            if ( ! $set_actived ) {
                $level2_active = static::is_level2_menu_item_active( $href ) ? true : false;
                if ( is_array( $active_path ) && ! empty( $active_path ) ) {
                    reset( $active_path );
                    $item = key( $active_path );
                    if ( $item === $plugin_page ) {
                        $level2_active = true;
                    }
                }
                // hard fix managesite menu items active status.
                //phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                if ( false !== strpos( $href, 'admin.php?page=managesites' ) ) {
                    $page_name     = isset( $_GET['page'] ) ? wp_unslash( $_GET['page'] ) : '';
                    $level2_active = false;

                    if ( isset( $_GET['do'] ) && 'new' === $_GET['do'] && false !== strpos( $href, 'admin.php?page=managesites&do=new' ) ) {
                        $level2_active = true;
                    }

                    if ( ! $level2_active && isset( $_GET['do'] ) && 'bulknew' === $_GET['do'] && false !== strpos( $href, 'admin.php?page=managesites&do=bulknew' ) ) {
                        $level2_active = true;
                    }

                    if ( ! $level2_active && ! isset( $_GET['do'] ) && 'InsightsManage' !== $page_name && 'admin.php?page=managesites' === $href ) {
                        $level2_active = true;
                    }
                }

                if ( ! $level2_active && ! empty( $others['active_params'] ) && is_array( $others['active_params'] ) ) {
                    foreach ( $others['active_params'] as $name => $value ) {
                        if ( isset( $_GET[ $name ] ) && wp_unslash( $_GET[ $name ] ) === $value ) {
                            $level2_active = true;
                            break;
                        }
                    }
                }

                //phpcs:enable WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                if ( $level2_active ) {
                    $set_actived = true;
                }
            }

            $right_group = 'dashboard';
            if ( ! empty( $right ) && strpos( $right, 'extension_' ) === 0 ) {
                $right_group = 'extension';
                $right       = str_replace( 'extension_', '', $right );
            }
            if ( empty( $right ) || ( ! empty( $right ) && \mainwp_current_user_can( $right_group, $right ) ) ) {
                ?>
                <a class="item <?php echo $level2_active ? 'active level-two-active' : ''; ?> <?php echo esc_attr( $item_classes ); ?>" href="<?php echo esc_url( $href ); ?>" id="<?php echo esc_attr( $slug ); ?>" <?php echo $_blank ? 'target="_blank"' : ''; ?>>
                    <?php echo $before_title . $title; //phpcs:ignore -- requires escaped. ?>
                </a>
                <?php
            }
        }
    }


    /**
     * Method is_level2_menu_item_active().
     *
     * Check if menu item level 2 is active.
     *
     * @param mixed $href The href value.
     */
    public static function is_level2_menu_item_active( $href ) {
        $current_path = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        $san_path     = $current_path;

        if ( 0 === stripos( $san_path, '/wp-admin/' ) ) {
            $san_path = str_replace( '/wp-admin/', '', $san_path );
        }

        $orther = '';
        if ( 0 === stripos( $san_path, $href ) ) {
            $orther = str_replace( $href, '', $san_path );
        }

        if ( ! empty( $orther ) && '&' === substr( $orther, 0, 1 ) ) { // cheat: start by &, it is addition params string.
            $san_path = str_replace( $orther, '', $san_path ); // remove other path of uri.
        }

        $san_path = MainWP_Utility::sanitize_attr_slug( $san_path );
        $san_href = MainWP_Utility::sanitize_attr_slug( $href );

        return $san_path === $san_href;
    }
}
