<?php
/**
 * MainWP Clients Page
 *
 * This page is used to Manage Clients.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_client
 *
 * @uses page-mainwp-bulk-add::MainWP_Bulk_Add()
 */
class MainWP_Client { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Get Class Name
     *
     * @return string __CLASS__
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Current page.
     *
     * @static
     * @var string $page Current page.
     */
    public static $page;

    /**
     * Public static varable to hold Subpages information.
     *
     * @var array $subPages
     */
    public static $subPages;


    /**
     * Magage Sites table
     *
     * @var $itemsTable Magage Sites table.
     */
    public static $itemsTable;

    /**
     * Method init()
     *
     * Initiate hooks for the clients page.
     */
    public static function init() {
        /**
         * This hook allows you to render the client page header via the 'mainwp_pageheader_client' action.
         *
         * This hook is normally used in the same context of 'mainwp_pageheader_client'
         *
         * @see \MainWP_client::render_header
         */
        add_action( 'mainwp_pageheader_client', array( static::get_class_name(), 'render_header' ) );

        /**
         * This hook allows you to render the client page footer via the 'mainwp_pagefooter_client' action.
         *
         * This hook is normally used in the same context of 'mainwp_pagefooter_client'
         *
         * @see \MainWP_client::render_footer
         */
        add_action( 'mainwp_pagefooter_client', array( static::get_class_name(), 'render_footer' ) );

        MainWP_Post_Handler::instance()->add_action( 'mainwp_add_edit_client_upload_client_icon', array( static::class, 'ajax_upload_client_icon' ) );
        MainWP_Post_Handler::instance()->add_action( 'mainwp_add_edit_contact_upload_contact_icon', array( static::class, 'ajax_upload_contact_icon' ) );
    }

    /**
     * Method init_menu()
     *
     * Initiate menu.
     *
     * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
     */
    public static function init_menu() {
        static::$page = add_submenu_page(
            'mainwp_tab',
            esc_html__( 'Clients', 'mainwp' ),
            '<span id="mainwp-clients">' . esc_html__( 'Clients', 'mainwp' ) . '</span>',
            'read',
            'ManageClients',
            array(
                static::get_class_name(),
                'render_manage_clients',
            )
        );

        add_submenu_page(
            'mainwp_tab',
            esc_html__( 'Clients', 'mainwp' ),
            '<div class="mainwp-hidden">' . esc_html__( 'Add Client', 'mainwp' ) . '</div>',
            'read',
            'ClientAddNew',
            array(
                static::get_class_name(),
                'render_add_client',
            )
        );

        add_submenu_page(
            'mainwp_tab',
            esc_html__( 'Clients', 'mainwp' ),
            '<div class="mainwp-hidden">' . esc_html__( 'Import Clients', 'mainwp' ) . '</div>',
            'read',
            'ClientImport',
            array(
                static::get_class_name(),
                'render_import_clients',
            )
        );

        add_submenu_page(
            'mainwp_tab',
            esc_html__( 'Clients', 'mainwp' ),
            '<div class="mainwp-hidden">' . esc_html__( 'Client Fields', 'mainwp' ) . '</div>',
            'read',
            'ClientAddField',
            array(
                static::get_class_name(),
                'render_client_fields',
            )
        );

        /**
         * This hook allows you to add extra sub pages to the client page via the 'mainwp-getsubpages-client' filter.
         *
         * @link http://codex.mainwp.com/#mainwp-getsubpages-client
         */
        $sub_pages        = array();
        static::$subPages = apply_filters( 'mainwp_getsubpages_client', $sub_pages );

        if ( isset( static::$subPages ) && is_array( static::$subPages ) ) {
            foreach ( static::$subPages as $subPage ) {
                if ( MainWP_Menu::is_disable_menu_item( 3, 'ManageClients' . $subPage['slug'] ) ) {
                    continue;
                }
                add_submenu_page( 'mainwp_tab', $subPage['title'], '<div class="mainwp-hidden">' . esc_html( $subPage['title'] ) . '</div>', 'read', 'ManageClients' . $subPage['slug'], $subPage['callback'] );
            }
        }

        static::init_left_menu( static::$subPages );

        add_action( 'load-' . static::$page, array( static::get_class_name(), 'on_load_page' ) );
    }

    /**
     * Method on_load_page()
     *
     * Run on page load.
     */
    public static function on_load_page() {

        if ( isset( $_GET['client_id'] ) && ! empty( $_GET['client_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            MainWP_Client_Overview::instance()->on_load_page( static::$page );
            return;
        }

        add_filter( 'mainwp_header_actions_right', array( static::get_class_name(), 'screen_options' ), 10, 2 );

        static::$itemsTable = new MainWP_Client_List_Table();
    }

    /**
     * Method screen_options()
     *
     * Create Page Settings button.
     *
     * @param mixed $input Page Settings button HTML.
     *
     * @return mixed Screen sptions button.
     */
    public static function screen_options( $input ) {
        return $input .
                '<a class="ui button basic icon" onclick="mainwp_manage_clients_screen_options(); return false;" data-inverted="" data-position="bottom right" href="#" target="_blank" data-tooltip="' . esc_html__( 'Page Settings', 'mainwp' ) . '" aria-label="' . esc_html__( 'Page Settings', 'mainwp' ) . '">
                    <i class="cog icon"></i>
                </a>';
    }

    /**
     * Initiates sub pages menu.
     *
     * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
     */
    public static function init_subpages_menu() {
        ?>
        <div id="menu-mainwp-Clients" class="mainwp-submenu-wrapper">
            <div class="wp-submenu sub-open" style="">
                <div class="mainwp_boxout">
                    <div class="mainwp_boxoutin"></div>
                    <?php if ( \mainwp_current_user_can( 'dashboard', 'manage_clients' ) ) { ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=ManageClients' ) ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Clients', 'mainwp' ); ?></a>
                    <?php } ?>
                    <?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ClientAddNew' ) ) { ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=ClientAddNew' ) ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Add Client', 'mainwp' ); ?></a>
                    <?php } ?>
                    <?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ClientImport' ) ) { ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=ClientImport' ) ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Import Clients', 'mainwp' ); ?></a>
                    <?php } ?>
                    <?php if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ClientAddField' ) ) { ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=ClientAddField' ) ); ?>" class="mainwp-submenu"><?php esc_html_e( 'Client Properties', 'mainwp' ); ?></a>
                    <?php } ?>
                    <?php
                    if ( isset( static::$subPages ) && is_array( static::$subPages ) ) {
                        foreach ( static::$subPages as $subPage ) {
                            if ( MainWP_Menu::is_disable_menu_item( 3, 'ManageClients' . $subPage['slug'] ) ) {
                                continue;
                            }
                            ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ManageClients' . $subPage['slug'] ) ); ?>" class="mainwp-submenu"><?php echo esc_html( $subPage['title'] ); ?></a>
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
     * Initiates Clients menu.
     *
     * @param array $subPages Sub pages array.
     *
     * @uses \MainWP\Dashboard\MainWP_Menu::add_left_menu()
     * @uses \MainWP\Dashboard\MainWP_Menu::init_subpages_left_menu()
     * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
     */
    public static function init_left_menu( $subPages = array() ) {
        MainWP_Menu::add_left_menu(
            array(
                'title'      => esc_html__( 'Clients', 'mainwp' ),
                'parent_key' => 'mainwp_tab',
                'slug'       => 'ManageClients',
                'href'       => 'admin.php?page=ManageClients',
                'icon'       => '<i class="users icon"></i>',
                'desc'       => 'Manage clients on your child sites',
            ),
            0
        );

        $init_sub_subleftmenu = array(
            array(
                'title'                => esc_html__( 'Clients', 'mainwp' ),
                'parent_key'           => 'ManageClients',
                'href'                 => 'admin.php?page=ManageClients',
                'slug'                 => 'ManageClients',
                'right'                => 'manage_clients',
                'leftsub_order_level2' => 1,
            ),
            array(
                'title'                => esc_html__( 'Add Client', 'mainwp' ),
                'parent_key'           => 'ManageClients',
                'href'                 => 'admin.php?page=ClientAddNew',
                'slug'                 => 'ClientAddNew',
                'right'                => '',
                'leftsub_order_level2' => 2,
            ),
            array(
                'title'                => esc_html__( 'Import Clients', 'mainwp' ),
                'parent_key'           => 'ManageClients',
                'href'                 => 'admin.php?page=ClientImport',
                'slug'                 => 'ClientImport',
                'right'                => '',
                'leftsub_order_level2' => 3,
            ),
            array(
                'title'                => esc_html__( 'Client Fields', 'mainwp' ),
                'parent_key'           => 'ManageClients',
                'href'                 => 'admin.php?page=ClientAddField',
                'slug'                 => 'ClientAddField',
                'right'                => '',
                'leftsub_order_level2' => 4,
            ),
        );

        MainWP_Menu::init_subpages_left_menu( $subPages, $init_sub_subleftmenu, 'ManageClients', 'ManageClients' );

        foreach ( $init_sub_subleftmenu as $item ) {
            if ( MainWP_Menu::is_disable_menu_item( 3, $item['slug'] ) ) {
                continue;
            }
            MainWP_Menu::add_left_menu( $item, 2 );
        }
    }

    /**
     * Method ajax_upload_client_icon()
     */
    public static function ajax_upload_client_icon() { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR - complexity.
        MainWP_Post_Handler::instance()->secure_request( 'mainwp_add_edit_client_upload_client_icon' );

        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $iconfile_slug = isset( $_POST['iconFileSlug'] ) ? sanitize_text_field( wp_unslash( $_POST['iconFileSlug'] ) ) : '';
        $delete        = isset( $_POST['delete'] ) ? intval( $_POST['delete'] ) : 0;
        $client_id     = isset( $_POST['iconItemId'] ) ? intval( $_POST['iconItemId'] ) : 0;
        $delnonce      = isset( $_POST['delnonce'] ) ? sanitize_key( $_POST['delnonce'] ) : '';

        if ( $delete ) {
            if ( ! MainWP_System_Utility::is_valid_custom_nonce( 'client', $iconfile_slug, $delnonce ) ) {
                die( 'Invalid nonce!' );
            }
            if ( $client_id ) {
                $client = MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $client_id );
                if ( $client && ! empty( $client->image ) ) {
                    $update = array(
                        'image'     => '',
                        'client_id' => $client_id,
                    );
                    MainWP_DB_Client::instance()->update_client( $update );
                    MainWP_Utility::instance()->delete_uploaded_icon_file( 'client-images', $client->image );
                }
            } elseif ( ! empty( $iconfile_slug ) ) {
                MainWP_Utility::instance()->delete_uploaded_icon_file( 'client-images', $iconfile_slug );
            }
            wp_die( wp_json_encode( array( 'result' => 'success' ) ) );
        }

        $output = isset( $_FILES['mainwp_upload_icon_uploader'] ) ? MainWP_System_Utility::handle_upload_image( 'client-images', $_FILES['mainwp_upload_icon_uploader'] ) : null;
        // phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        $uploaded_icon = 'NOTCHANGE';
        if ( is_array( $output ) && isset( $output['filename'] ) && ! empty( $output['filename'] ) ) {
            $uploaded_icon = $output['filename'];
        }

        if ( 'NOTCHANGE' !== $uploaded_icon ) {
            $dirs     = MainWP_System_Utility::get_mainwp_dir( 'client-images', true );
            $icon_url = $dirs[1] . $uploaded_icon;
            wp_die(
                wp_json_encode(
                    array(
                        'result'    => 'success',
                        'iconfile'  => esc_html( $uploaded_icon ),
                        'iconsrc'   => esc_html( $icon_url ),
                        'iconimg'   => '<img class="ui circular image" src="' . esc_attr( $icon_url ) . '" style="width:28px;height:auto;display:inline-block;" alt="Client custom icon">',
                        'iconnonce' => MainWP_System_Utility::get_custom_nonce( 'client', esc_html( $uploaded_icon ) ),
                    )
                )
            );
        } else {
            wp_die( wp_json_encode( array( 'result' => 'failed' ) ) );
        }
    }


    /**
     * Method ajax_upload_contact_icon()
     */
    public static function ajax_upload_contact_icon() { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR - complexity.
        MainWP_Post_Handler::instance()->secure_request( 'mainwp_add_edit_contact_upload_contact_icon' );
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $iconfile_slug = isset( $_POST['iconFileSlug'] ) ? sanitize_text_field( wp_unslash( $_POST['iconFileSlug'] ) ) : '';
        $delete        = isset( $_POST['delete'] ) ? intval( $_POST['delete'] ) : 0;
        $contact_id    = isset( $_POST['iconItemId'] ) ? intval( $_POST['iconItemId'] ) : 0;
        $delnonce      = isset( $_POST['delnonce'] ) ? sanitize_key( $_POST['delnonce'] ) : '';

        if ( $delete ) {
            if ( ! MainWP_System_Utility::is_valid_custom_nonce( 'contact', $iconfile_slug, $delnonce ) ) {
                die( 'Invalid nonce!' );
            }
            if ( $contact_id ) {
                $contact_data = MainWP_DB_Client::instance()->get_wp_client_contact_by( 'contact_id', $contact_id );
                if ( $contact_data && ! empty( $contact_data->contact_image ) ) {
                    $update = array(
                        'contact_image' => '',
                        'contact_id'    => $contact_id,
                    );
                    MainWP_DB_Client::instance()->update_client_contact( $update );
                    MainWP_Utility::instance()->delete_uploaded_icon_file( 'client-images', $contact_data->contact_image );
                }
            } elseif ( ! empty( $iconfile_slug ) ) {
                MainWP_Utility::instance()->delete_uploaded_icon_file( 'client-images', $iconfile_slug );
            }
            wp_die( wp_json_encode( array( 'result' => 'success' ) ) );
        }

        $output = isset( $_FILES['mainwp_upload_icon_uploader'] ) ? MainWP_System_Utility::handle_upload_image( 'client-images', $_FILES['mainwp_upload_icon_uploader'] ) : null;
        // phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        $uploaded_icon = 'NOTCHANGE';
        if ( is_array( $output ) && isset( $output['filename'] ) && ! empty( $output['filename'] ) ) {
            $uploaded_icon = $output['filename'];
        }

        if ( 'NOTCHANGE' !== $uploaded_icon ) {
            $dirs     = MainWP_System_Utility::get_mainwp_dir( 'client-images', true );
            $icon_url = $dirs[1] . $uploaded_icon;
            wp_die(
                wp_json_encode(
                    array(
                        'result'    => 'success',
                        'iconfile'  => esc_html( $uploaded_icon ),
                        'iconsrc'   => esc_html( $icon_url ),
                        'iconimg'   => '<img class="ui circular image" src="' . esc_attr( $icon_url ) . '" style="width:32px;height:auto;display:inline-block;" alt="Client custom icon">',
                        'iconnonce' => MainWP_System_Utility::get_custom_nonce( 'contact', esc_html( $uploaded_icon ) ),
                    )
                )
            );
        } else {
            wp_die( wp_json_encode( array( 'result' => 'failed' ) ) );
        }
    }


    /**
     * Method render_header()
     *
     * Render Clients page header.
     *
     * @param string $shownPage The page slug shown at this moment.
     *
     * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
     * @uses \MainWP\Dashboard\MainWP_UI::render_top_header()
     * @uses \MainWP\Dashboard\MainWP_UI::render_page_navigation()
     */
    public static function render_header( $shownPage = '' ) { // phpcs:ignore -- NOSONAR -Current complexity is the only way to achieve desired results, pull request solutions appreciated.
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $client_id = isset( $_GET['client_id'] ) ? intval( $_GET['client_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        $params = array(
            'title' => esc_html__( 'Clients', 'mainwp' ),
            'which' => 'overview' === $shownPage ? 'page_clients_overview' : '',
        );

        $client = false;
        if ( $client_id ) {
            $client = MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $client_id );
            if ( $client ) {
                $arr_client      = MainWP_Utility::map_fields( $client, array( 'image', 'selected_icon_info' ), false ); // array map.
                $client_pic      = MainWP_Client_Handler::get_client_contact_image( $arr_client );
                $params['title'] = $client_pic . '<div class="content">' . $client->name . '<div class="sub header"><a href="mailto:' . $client->client_email . '" target="_blank" style="font-weight:normal!important;">' . $client->client_email . '</a> </div></div>';
            }
        }

        MainWP_UI::render_top_header( $params );

        $renderItems = array();

        if ( \mainwp_current_user_can( 'dashboard', 'manage_clients' ) ) {
            $renderItems[] = array(
                'title'  => esc_html__( 'Clients', 'mainwp' ),
                'href'   => 'admin.php?page=ManageClients',
                'active' => ( '' === $shownPage ) ? true : false,
            );
        }

        if ( $client_id ) {
            $renderItems[] = array(
                'title'  => $client ? $client->name : esc_html__( 'Overview', 'mainwp' ),
                'href'   => 'admin.php?page=ManageClients&client_id=' . $client_id,
                'active' => ( 'overview' === $shownPage ),
            );
            $renderItems[] = array(
                'title'  => $client ? esc_html__( 'Edit', 'mainwp' ) . ' ' . $client->name : esc_html__( 'Edit Client', 'mainwp' ),
                'href'   => 'admin.php?page=ClientAddNew&client_id=' . $client_id,
                'active' => ( 'Edit' === $shownPage ) ? true : false,
            );
        }

        if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ClientAddNew' ) ) {
            $renderItems[] = array(
                'title'  => esc_html__( 'Add Client', 'mainwp' ),
                'href'   => 'admin.php?page=ClientAddNew',
                'active' => ( 'Add' === $shownPage ) ? true : false,
            );
        }

        if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ClientImport' ) ) {
            $renderItems[] = array(
                'title'  => esc_html__( 'Import Clients', 'mainwp' ),
                'href'   => 'admin.php?page=ClientImport',
                'active' => ( 'Add' === $shownPage ) ? true : false,
            );
        }

        if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ClientAddField' ) ) {
            $renderItems[] = array(
                'title'  => esc_html__( 'Client Fields', 'mainwp' ),
                'href'   => 'admin.php?page=ClientAddField',
                'active' => ( 'AddField' === $shownPage ) ? true : false,
            );
        }

        if ( isset( static::$subPages ) && is_array( static::$subPages ) ) {
            foreach ( static::$subPages as $subPage ) {
                if ( MainWP_Menu::is_disable_menu_item( 3, 'ManageClients' . $subPage['slug'] ) ) {
                    continue;
                }

                if ( ! empty( $subPage['individual_settings'] ) && empty( $client_id ) ) {
                    continue;
                }

                $client_param   = $client_id ? '&client_id=' . $client_id : '';
                $item           = array();
                $item['title']  = $subPage['title'];
                $item['href']   = 'admin.php?page=ManageClients' . $subPage['slug'] . $client_param;
                $item['active'] = ( $subPage['slug'] === $shownPage ) ? true : false;
                $renderItems[]  = $item;
            }
        }
        // phpcs:enable
        MainWP_UI::render_page_navigation( $renderItems );
    }

    /**
     * Method render_footer()
     *
     * Render Clients page footer. Closes the page container.
     */
    public static function render_footer() {
        echo '</div>';
    }

    /**
     * Renders manage clients dashboard.
     *
     * @return void
     */
    public static function render_manage_clients() {

        if ( isset( $_GET['client_id'] ) && ! empty( $_GET['client_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            MainWP_Client_Overview::instance()->on_show_page( intval( $_GET['client_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        if ( ! \mainwp_current_user_can( 'dashboard', 'manage_clients' ) ) {
            \mainwp_do_not_have_permissions( esc_html__( 'manage clients', 'mainwp' ) );
            return;
        }

        static::$itemsTable->prepare_items();

        static::render_header( '' );
        static::render_second_top_header();

        ?>
        <div id="mainwp-manage-sites-content" class="ui segment">
            <div id="mainwp-message-zone" style="display:none;" class="ui message"></div>
            <form method="post" class="mainwp-table-container">
                <?php
                wp_nonce_field( 'mainwp-admin-nonce' );
                static::$itemsTable->display();
                static::$itemsTable->clear_items();
                ?>
            </form>
        </div>
        <?php
        static::render_footer( '' );
        static::render_screen_options();
    }

    /**
     * Method render_second_top_header()
     *
     * Render second top header.
     *
     * @return void Render second top header html.
     */
    public static function render_second_top_header() {
        ?>
        <div class="mainwp-sub-header ui mini form">
            <?php
            do_action( 'mainwp_manageclients_tabletop' );
            ?>
        </div>
        <?php
    }


    /**
     * Renders Edit Clients Modal window.
     */
    public static function render_update_clients() {

        ?>
        <div id="mainwp-edit-clients-modal" class="ui modal">
        <i class="close icon"></i>
            <div class="header"><?php esc_html_e( 'Edit client', 'mainwp' ); ?></div>
            <div class="ui message"><?php esc_html_e( 'Empty fields will not be passed to child sites.', 'mainwp' ); ?></div>
            <form id="update_client_profile">
                <?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
                <div class="ui segment">
                    <div class="ui form">
                        <h3><?php esc_html_e( 'Name', 'mainwp' ); ?></h3>
                        <div class="ui grid field">
                            <label class="six wide column middle aligned"><?php esc_html_e( 'First Name', 'mainwp' ); ?></label>
                            <div class="ui six wide column">
                                <div class="ui left labeled input">
                                    <input type="text" name="first_name" id="first_name" value="" class="regular-text" />
                                </div>
                            </div>
                        </div>

                        <div class="ui grid field">
                            <label class="six wide column middle aligned"><?php esc_html_e( 'Last Name', 'mainwp' ); ?></label>
                            <div class="ui six wide column">
                                <div class="ui left labeled input">
                                    <input type="text" name="last_name" id="last_name" value="" class="regular-text" />
                                </div>
                            </div>
                        </div>

                        <div class="ui grid field">
                            <label class="six wide column middle aligned"><?php esc_html_e( 'Nickname', 'mainwp' ); ?></label>
                            <div class="ui six wide column">
                                <div class="ui left labeled input">
                                    <input type="text" name="nickname" id="nickname" value="" class="regular-text" />
                                </div>
                            </div>
                        </div>

                        <div class="ui grid field">
                            <label class="six wide column middle aligned"><?php esc_html_e( 'Display name publicly as', 'mainwp' ); ?></label>
                            <div class="ui six wide column">
                                <div class="ui left labeled input">
                                    <select name="display_name" id="display_name"></select>
                                </div>
                            </div>
                        </div>

                        <h3><?php esc_html_e( 'Contact Info', 'mainwp' ); ?></h3>

                        <div class="ui grid field">
                            <label class="six wide column middle aligned"><?php esc_html_e( 'Email', 'mainwp' ); ?></label>
                            <div class="ui six wide column">
                                <div class="ui left labeled input">
                                    <input type="email" name="email" id="email" value="" class="regular-text ltr" />
                                </div>
                            </div>
                        </div>

                        <div class="ui grid field">
                            <label class="six wide column middle aligned"><?php esc_html_e( 'Website', 'mainwp' ); ?></label>
                            <div class="ui six wide column">
                                <div class="ui left labeled input">
                                    <input type="url" name="url" id="url" value="" class="regular-text code" />
                                </div>
                            </div>
                        </div>

                        <h3><?php esc_html_e( 'About the client', 'mainwp' ); ?></h3>

                        <div class="ui grid field">
                            <label class="six wide column middle aligned"><?php esc_html_e( 'Biographical Info', 'mainwp' ); ?></label>
                            <div class="ui six wide column">
                                <div class="ui left labeled input">
                                    <textarea name="description" id="description" rows="5" cols="30"></textarea>
                                    <p class="description"><?php esc_html_e( 'Share a little biographical information to fill out your profile. This may be shown publicly.', 'mainwp' ); ?></p>
                                </div>
                            </div>
                        </div>

                        <h3><?php esc_html_e( 'Account Management', 'mainwp' ); ?></h3>

                        <div class="ui grid field">
                            <label class="six wide column middle aligned"><?php esc_html_e( 'Password', 'mainwp' ); ?></label>
                            <div class="ui six wide column">
                                <div class="ui left labeled action input">
                                    <input class="hidden" value=" "/>
                                    <input type="text" id="password" name="password" autocomplete="off" value="">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
            <div class="actions">
                <div id="mainwp_update_password_error" style="display: none"></div>
                <span id="mainwp_clients_updating"><i class="ui active inline loader tiny"></i></span>
                <input type="button" class="ui green button" id="mainwp_btn_update_client" value="<?php esc_attr_e( 'Update', 'mainwp' ); ?>">
            </div>
        </div>
        <?php
    }


    /**
     * Method render_screen_options()
     *
     * Render Page Settings Modal.
     */
    public static function render_screen_options() {  // phpcs:ignore -- NOSONAR -Current complexity is the only way to achieve desired results, pull request solutions appreciated.

        $columns = static::$itemsTable->get_columns();

        if ( isset( $columns['cb'] ) ) {
            unset( $columns['cb'] );
        }

        $sites_per_page = get_option( 'mainwp_default_manage_clients_per_page', 25 );

        if ( isset( $columns['site_actions'] ) && empty( $columns['site_actions'] ) ) {
            $columns['site_actions'] = esc_html__( 'Actions', 'mainwp' );
        }

        $show_cols = get_user_option( 'mainwp_settings_show_manage_clients_columns' );

        if ( false === $show_cols ) { // to backwards.
            $show_cols = array();
            foreach ( $columns as $name => $title ) {
                $show_cols[ $name ] = 1;
            }
            $user = wp_get_current_user();
            if ( $user ) {
                update_user_option( $user->ID, 'mainwp_settings_show_manage_clients_columns', $show_cols, true );
            }
        }

        if ( ! is_array( $show_cols ) ) {
            $show_cols = array();
        }

        ?>
        <div class="ui modal" id="mainwp-manage-sites-screen-options-modal">
        <i class="close icon"></i>
            <div class="header"><?php esc_html_e( 'Page Settings', 'mainwp' ); ?></div>
            <div class="scrolling content ui form">
                <form method="POST" action="" id="manage-sites-screen-options-form" name="manage_sites_screen_options_form">
                    <?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
                    <input type="hidden" name="wp_nonce" value="<?php echo esc_attr( wp_create_nonce( 'ManageClientsScrOptions' ) ); ?>" />
                    <div class="ui grid field">
                        <label class="six wide column"><?php esc_html_e( 'Default items per page value', 'mainwp' ); ?></label>
                        <div class="ten wide column">
                            <div class="ui info message">
                                <ul>
                                    <li><?php esc_html_e( 'Based on your Dashboard server default large numbers can severely impact page load times.', 'mainwp' ); ?></li>
                                    <li><?php esc_html_e( 'Do not add commas for thousands (ex 1000).', 'mainwp' ); ?></li>
                                    <li><?php esc_html_e( '-1 to default to All of your Child Sites.', 'mainwp' ); ?></li>
                                </ul>
                            </div>
                            <input type="text" name="mainwp_default_manage_clients_per_page" id="mainwp_default_manage_clients_per_page" saved-value="<?php echo intval( $sites_per_page ); ?>" value="<?php echo intval( $sites_per_page ); ?>"/>
                        </div>
                    </div>
                    <div class="ui grid field">
                        <label class="six wide column"><?php esc_html_e( 'Show columns', 'mainwp' ); ?></label>
                        <div class="ten wide column">
                            <ul class="mainwp_hide_wpmenu_checkboxes">
                                <?php
                                foreach ( $columns as $name => $title ) {
                                    if ( empty( $title ) ) {
                                        continue;
                                    }
                                    ?>
                                    <li>
                                        <div class="ui checkbox <?php echo ( 'site_preview' === $name ) ? 'site_preview not-auto-init' : ''; ?>">
                                            <input type="checkbox"
                                            <?php
                                            $show_col = ! isset( $show_cols[ $name ] ) || ( 1 === (int) $show_cols[ $name ] );
                                            if ( $show_col ) {
                                                echo 'checked="checked"';
                                            }
                                            ?>
                                            id="mainwp_show_column_<?php echo esc_attr( $name ); ?>" name="mainwp_show_column_<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $name ); ?>">
                                            <label for="mainwp_show_column_<?php echo esc_attr( $name ); ?>" ><?php echo $title; // phpcs:ignore WordPress.Security.EscapeOutput ?></label>
                                            <input type="hidden" value="<?php echo esc_attr( $name ); ?>" name="show_columns_name[]" />
                                        </div>
                                    </li>
                                    <?php
                                }
                                ?>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="actions">
                    <div class="ui two columns grid">
                        <div class="left aligned column">
                            <span data-tooltip="<?php esc_attr_e( 'Resets the page to its original layout and reinstates relocated columns.', 'mainwp' ); ?>" data-inverted="" data-position="top center"><input type="button" class="ui button" name="reset" id="reset-manageclients-settings" value="<?php esc_attr_e( 'Reset Page', 'mainwp' ); ?>" /></span>
                        </div>
                        <div class="ui right aligned column">
                    <input type="submit" class="ui green button" name="btnSubmit" id="submit-manageclients-settings" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>" />
                </div>
                    </div>
                </div>
                <input type="hidden" name="reset_manageclients_columns_order" value="0">
            </form>
        </div>
        <div class="ui small modal" id="mainwp-monitoring-sites-site-preview-screen-options-modal">
            <div class="header"><?php esc_html_e( 'Page Settings', 'mainwp' ); ?></div>
            <div class="scrolling content ui form">
                <span><?php esc_html_e( 'Would you like to turn on home screen previews? This function queries WordPress.com servers to capture a screenshot of your site the same way comments shows you preview of URLs.', 'mainwp' ); ?>
            </div>
            <div class="actions">
                <div class="ui ok button"><?php esc_html_e( 'Yes', 'mainwp' ); ?></div>
                <div class="ui cancel button"><?php esc_html_e( 'No', 'mainwp' ); ?></div>
            </div>
        </div>
        <script type="text/javascript">
            jQuery( document ).ready( function () {
                jQuery( '.ui.checkbox.not-auto-init.site_preview' ).checkbox( {
                    onChecked   : function() {
                        let $chk = jQuery( this );
                        jQuery( '#mainwp-monitoring-sites-site-preview-screen-options-modal' ).modal( {
                            allowMultiple: true, // multiple modals.
                            width: 100,
                            onDeny: function () {
                                $chk.prop('checked', false);
                            }
                        } ).modal( 'show' );
                    }
                } );
                jQuery('#reset-manageclients-settings').on( 'click', function () {
                    mainwp_confirm(__( 'Are you sure.' ), function(){
                        jQuery('input[name=mainwp_default_manage_clients_per_page]').val(25);
                        jQuery('.mainwp_hide_wpmenu_checkboxes input[id^="mainwp_show_column_"]').prop( 'checked', false );
                        //default columns.
                        let cols = [ 'suspended','image','client','websites','contact_name','created'];
                        jQuery.each( cols, function ( index, value ) {
                            jQuery('.mainwp_hide_wpmenu_checkboxes input[id="mainwp_show_column_' + value + '"]').prop( 'checked', true );
                        } );
                        jQuery('input[name=reset_manageclients_columns_order]').attr('value',1);
                        jQuery('#submit-manageclients-settings').click();
                    }, false, false, true );
                    return false;
                });
            } );
        </script>
        <?php
    }

    /**
     * Renders the Add New Client form.
     */
    public static function render_add_client() {
        $show           = 'Add';
        $client_id      = 0;
        $selected_sites = array();
        $edit_client    = false;

        if ( isset( $_GET['client_id'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended
            $show         = 'Edit';
            $client_id    = intval( $_GET['client_id'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended
            $edit_client  = $client_id ? MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $client_id ) : false;
            $client_sites = MainWP_DB_Client::instance()->get_websites_by_client_ids( $client_id );

            if ( $client_sites ) {
                foreach ( $client_sites as $site ) {
                    $selected_sites[] = $site->id;
                }
            }
        }

        static::render_header( $show );
        ?>
        <div class="ui alt segment" id="mainwp-add-clients">
            <form action="" method="post" enctype="multipart/form-data" name="createclient_form" id="createclient_form" class="add:clients: validate">
                <?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
                <div class="mainwp-main-content">
                    <div class="ui segment">
                        <?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-add-client-info-message' ) ) : ?>
                            <div class="ui info message">
                                <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-add-client-info-message"></i>
                                <?php printf( esc_html__( 'Use the provided form to create a new client on your child site. For additional help, please check this %1$shelp documentation %2$s.', 'mainwp' ), '<a href="https://mainwp.com/kb/create-a-new-client/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); // NOSONAR - noopener - open safe. ?>
                            </div>
                        <?php endif; ?>
                        <div class="ui message" id="mainwp-message-zone-client" style="display:none;"></div>
                        <div id="mainwp-add-new-client-form" >
                            <?php static::render_add_client_content( $edit_client ); ?>
                        </div>
                    </div>
                </div>
                <div class="mainwp-side-content mainwp-no-padding">
                    <?php if ( $client_id ) : ?>
                    <div class="mainwp-select-sites ui accordion mainwp-sidebar-accordion">
                        <div class="title active"><i class="dropdown icon"></i> <?php esc_html_e( 'Tokens Info', 'mainwp' ); ?></div>
                        <div class="content active">
                            <div class="ui info message">
                                <?php esc_html_e( 'Client info is available as tokens for reports and boilerplate content. Toggle the switch to see available tokens.', 'mainwp' ); ?>
                            </div>
                            <div class="ui toggle checkbox">
                                <input type="checkbox" name="mainwp_toggle_tokens_info" id="mainwp_toggle_tokens_info">
                                <label><?php esc_html_e( 'Toggle available tokens', 'mainwp' ); ?></label>
                            </div>
                        </div>
                    </div>
                    <script type="text/javascript">
                    jQuery( document ).ready( function() {
                    jQuery( '#mainwp_toggle_tokens_info' ).on( 'change', function() {
                            jQuery( '.hidden.token.column' ).toggle();
                        } );
                    } );
                    </script>
                    <div class="ui fitted divider"></div>
                    <?php endif; ?>

                    <div class="mainwp-select-sites ui accordion mainwp-sidebar-accordion">
                        <div class="title active"><i class="dropdown icon"></i>
                        <?php esc_html_e( 'Select Sites', 'mainwp' ); ?></div>
                        <div class="content active">
                            <?php
                            $sel_params = array(
                                'selected_sites'       => $selected_sites,
                                'show_group'           => false,
                                'add_edit_client_id'   => $client_id,
                                'enable_offline_sites' => $client_id ? true : false,
                            );

                            MainWP_UI_Select_Sites::select_sites_box( $sel_params );
                            ?>
                        </div>
                    </div>
                    <div class="ui fitted divider"></div>
                    <div class="mainwp-search-submit">
                        <input type="button" name="createclient" current-page="add-new" id="bulk_add_createclient" class="ui big green fluid button" value="<?php echo $client_id ? esc_attr__( 'Update Client', 'mainwp' ) : esc_attr__( 'Add Client', 'mainwp' ); ?> "/>
                    </div>
                </div>
                <div style="clear:both"></div>
            </form>
        </div>
        <?php
        static::render_footer( $show );
        static::render_add_field_modal( $client_id );
        MainWP_UI::render_modal_upload_icon();
    }

    /**
     * Renders the Import Client form.
     */
    public static function render_import_clients() {  // phpcs:ignore -- NOSONAR - Current complexity is the only way to achieve desired results, pull request solutions appreciated.
        static::render_header( 'ImportClients' );
        $title_page = esc_html__( 'Import Clients', 'mainwp' );
        // phpcs:disable WordPress.Security.NonceVerification.Missing

        $has_import_data = ! empty( $_POST['mainwp_client_import_add'] );
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        if ( $has_import_data && check_admin_referer( 'mainwp-admin-nonce' ) ) {
            static::render_import_client_modal();
        }
        ?>
        <div class=""  id="mainwp-import-clients">
            <div class="ui labeled icon inverted menu mainwp-sub-submenu" id="mainwp-import-client-tabular-menu">
                <a class="item active" data-tab="mainwp-client-import-csv">
                    <i class="file excel icon"></i>
                    <?php esc_html_e( 'CSV Import ', 'mainwp' ); ?>
                </a>
            </div>
            <div class="ui segment">
                <div id="" class="ui tab tab-client-import-csv active" data-tab="mainwp-client-import-csv">
                    <div class="ui info message">
                        <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-import-sites-info-message"></i>
                        <?php printf( esc_html__( 'You can download the sample CSV file to see how to format the import file properly. For additional help, please check this %1$shelp documentation%2$s.', 'mainwp' ), '<a href="https://mainwp.com/kb/import-sites/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); ?>
                    </div>
                    <form method="POST" action="" enctype="multipart/form-data" id="mainwp_client_import_form" class="ui form">
                        <div class="ui bottom attached tab segment active" data-tab="mainwp-import-csv">
                            <div id="mainwp-message-zone" class="ui message" style="display:none"></div>
                            <h3 class="ui dividing header">
                                <?php echo esc_html( $title_page ); ?>
                                <div class="sub header"><?php esc_html_e( 'Import multiple clients to your MainWP Dashboard.', 'mainwp' ); ?></div>
                            </h3>
                            <?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
                            <div class="ui grid field">
                                <label class="three wide column middle aligned" for="mainwp_client_import_file_bulkupload"><?php esc_html_e( 'Upload CSV', 'mainwp' ); ?> (<a href="<?php echo esc_url( MAINWP_PLUGIN_URL . 'assets/csv/sample_clients.csv' ); ?>" target="_blank"><?php esc_html_e( 'Download Sample', 'mainwp' ); ?></a>)</label>
                                <div class="nine wide column">
                                    <div class="ui file input">
                                    <input type="file" name="mainwp_client_import_file_bulkupload" id="mainwp_client_import_file_bulkupload" accept="text/comma-separated-values"/>
                                    </div>
                                </div>
                                <div class="ui toggle checkbox four wide column middle aligned">
                                    <input type="checkbox" name="mainwp_client_import_chk_header_first" checked="checked" id="mainwp_client_import_chk_header_first" value="1"/>
                                    <label for="mainwp_client_import_chk_header_first"><?php esc_html_e( 'CSV file contains a header', 'mainwp' ); ?></label>
                                </div>
                            </div>
                        </div>
                        <div class="ui segment">
                            <div class="ui divider"></div>
                            <input type="submit" name="mainwp_client_import_add" id="mainwp_client_import_bulkadd" class="ui big green button" value="<?php echo esc_attr( $title_page ); ?>"/>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <script type="text/javascript">
            jQuery('#mainwp-import-client-tabular-menu .item').tab();
        </script>
        <?php
        static::render_footer( 'ImportClients' );
    }
    /**
     * Method render_import_client_modal()
     *
     * Render HTML import client modal.
     */
    public static function render_import_client_modal() {
        ?>
        <div class="ui large modal mainwp-qsw-import-client-modal" id="mainwp-import-client-modal" >
            <i class="close icon"></i>
            <div class="header"><?php echo esc_html_e( 'Import Clients' ); ?></div>
            <div class="scrolling content">
                <?php static::render_import_client_row_modal(); ?>
            </div>
            <div class="actions">
                <div class="ui two column grid">
                    <div class="left aligned middle aligned column">
                        <input type="button" name="mainwp_manageclients_btn_import" id="mainwp_manageclients_btn_import" class="ui basic button" value="<?php esc_attr_e( 'Pause', 'mainwp' ); ?>"/>
                    </div>
                </div>
            </div>
        </div>
        <script type="text/javascript">
            jQuery( document ).ready( function () {
                jQuery('#mainwp-import-client-tabular-menu .item').tab();
                jQuery( "#mainwp-import-client-modal" ).modal( {
                    closable: false,
                    onHide: function() {
                        location.reload();
                    }
                } ).modal( 'show' );
            } );
        </script>
        <?php
    }

    /**
     * Method render_import_client_row_modal()
     *
     * Render row HTML import client.
     */
    public static function render_import_client_row_modal() {
        ?>
        <div id="mainwp-importing-clients" class="ui active inverted dimmer">
            <div class="ui medium text loader"><?php esc_html_e( 'Importing', 'mainwp' ); ?></div>
        </div>
        <div class="ui message" id="mainwp-import-clients-status-message">
            <i class="notched circle loading icon"></i> <?php echo esc_html__( 'Importing...', 'mainwp' ); ?>
        </div>
        <?php
        $has_file_upload = isset( $_FILES['mainwp_client_import_file_bulkupload'] ) && isset( $_FILES['mainwp_client_import_file_bulkupload']['error'] ) && UPLOAD_ERR_OK === $_FILES['mainwp_client_import_file_bulkupload']['error'];  // phpcs:ignore -- NOSONAR

        if ( $has_file_upload ) {
            $import_client_data = static::handle_client_import_files();
            if ( ! empty( $import_client_data ) && is_array( $import_client_data ) ) {
                $row         = 0;
                $header_line = trim( $import_client_data['header_line'] );
                foreach ( $import_client_data['data'] as $val_client_data ) {
                    $encoded  = wp_json_encode( $val_client_data );
                    $original = implode(
                        ', ',
                        array_map(
                            function ( $item ) {
                                return is_array( $item ) ? implode( ';', $item ) : $item;
                            },
                            $val_client_data
                        )
                    );
                    ?>
                    <input type="hidden" id="mainwp_manageclients_import_csv_line_<?php echo esc_attr( $row + 1 ); ?>" value="" encoded-data="<?php echo esc_attr( $encoded ); ?>" original="<?php echo esc_attr( $original ); ?>"/>
                    <?php
                    ++$row;
                }
                ?>
                <input type="hidden" id="mainwp_manageclients_do_import" value="1"/>
                <input type="hidden" id="mainwp_manageclients_total_import" value="<?php echo esc_attr( $row ); ?>"/>
                <div class="mainwp_manageclients_import_listing" id="mainwp_manageclients_import_logging">
                    <span class="log ui medium text"><?php echo esc_html( $header_line ) . '<br/>'; ?></span>
                </div>
                <div class="mainwp_manageclients_import_listing" id="mainwp_manageclients_import_fail_logging" style="display: none;"><?php echo esc_html( $header_line ); ?> </div>
                <?php
            }
        }
    }

    /**
     * Method handle_client_import_files()
     *
     * Handle client import files.
     *
     * @uses MainWP_System_Utility::get_wp_file_system()
     * @uses MainWP_DB::instance()->get_websites_by_url()
     *
     * @return array Import data.
     */
    public static function handle_client_import_files() {  // phpcs:ignore -- NOSONAR
        $tmp_path = isset( $_FILES['mainwp_client_import_file_bulkupload']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['mainwp_client_import_file_bulkupload']['tmp_name'] ) ) : '';  // phpcs:ignore WordPress.Security.NonceVerification.Missing -- NOSONAR
        MainWP_System_Utility::get_wp_file_system();
        //phpcs:enable
        /**
         * WordPress files system object.
         *
         * @global object
         */
        global $wp_filesystem;

        $content = $wp_filesystem->get_contents( $tmp_path );

        // to compatible with EOL on OSs.
        $content        = str_replace( "\r\n", "\r", $content );
        $content        = str_replace( "\n", "\r", $content );
        $lines          = explode( "\r", $content );
        $import_data    = array();
        $default_values = array(
            'client.name'              => '',
            'client.email'             => '',
            'client.contact.address.1' => '',
            'client.contact.address.2' => '',
            'client.city'              => '',
            'client.state'             => '',
            'client.zip'               => '',
            'client.country'           => '',
            'client.suspended'         => 0,
            'client.url'               => '',
        );
        if ( is_array( $lines ) && ( ! empty( $lines ) ) ) {
            $header_line = null;
            foreach ( $lines as $original_line ) {
                $line = trim( $original_line );
                if ( MainWP_Utility::starts_with( $line, '#' ) ) {
                    continue;
                }

                $items = str_getcsv( $line, ',' );

                if ( ( null === $header_line ) && ! empty( $_POST['mainwp_client_import_chk_header_first'] ) ) {  // phpcs:ignore WordPress.Security.NonceVerification.Missing -- NOSONAR
                    $header_line = $line . "\r";
                    continue;
                }
                if ( 3 > count( $items ) ) {
                    continue;
                }

                $x             = 0;
                $import_fields = array();
                // Take data from the CSV file into the array.
                foreach ( $default_values as $field => $val ) {
                    $value                   = isset( $items[ $x ] ) ? $items[ $x ] : $val;
                    $import_fields[ $field ] = $value;
                    ++$x;
                }
                $import_data[] = $import_fields;
            }
        }

        if ( ! empty( $import_data ) ) {
            foreach ( $import_data as $k_import => $val_import ) {
                if ( ! empty( $val_import['client.url'] ) ) {
                    $import_data[ $k_import ]['client.url'] = explode( ';', $val_import['client.url'] );
                }
            }
        }
        return array(
            'header_line' => $header_line,
            'data'        => $import_data,
        );
    }

    /**
     * Renders the Add New Client Fields form.
     */
    public static function render_client_fields() {

        static::render_header( 'AddField' );
        ?>
        <div class="mainwp-sub-header">
            <div class="ui one column grid">
                <div class="right aligned column">
                    <a class="ui mini green button" href="javascript:void(0);" id="mainwp-clients-new-custom-field-button"><?php esc_html_e( 'New Field', 'mainwp' ); ?></a>
                </div>
            </div>
        </div>
        <div class="ui segment" id="mainwp-add-client-fields">
            <?php $fields = MainWP_DB_Client::instance()->get_client_fields(); ?>
            <h2 class="ui dividing header">
                <?php esc_html_e( 'Custom Client Fields', 'mainwp' ); ?>
                <div class="sub header"><?php esc_html_e( 'Create and manage custom fields to store additional client details, ensuring you have all the information you need in one place.', 'mainwp' ); ?></div>
            </h2>

            <div class="ui info message" <?php echo ! MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-clients-manage-fields' ) ? 'style="display: none"' : ''; ?>>
            <?php esc_html_e( 'Create and manage custom Client fields.', 'mainwp' ); ?>
                <i class="ui close icon mainwp-notice-dismiss" notice-id="mainwp-clients-manage-fields"></i>
            </div>
            <div class="ui message" id="mainwp-message-zone-client" style="display:none;"></div>
            <table id="mainwp-clients-custom-fields-table" class="ui table" style="width:100%">
                <thead>
                    <tr>
                        <th scope="col" class="collapsing"><?php esc_html_e( 'Field Name', 'mainwp' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Field Description', 'mainwp' ); ?></th>
                        <th scope="col" class="no-sort collapsing"></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( is_array( $fields ) && ! empty( $fields ) ) : ?>
                    <?php foreach ( $fields as $field ) : ?>
                        <?php
                        if ( ! $field ) {
                            continue;
                        }
                        ?>
                            <tr class="mainwp-field none-selected-color" field-id="<?php echo intval( $field->field_id ); ?>">
                                <td class="field-name">[<?php echo esc_html( stripslashes( $field->field_name ) ); ?>]</td>
                                <td class="field-description"><?php echo esc_html( stripslashes( $field->field_desc ) ); ?></td>
                                <td>
                                    <div class="ui right pointing dropdown">
                                        <i class="ellipsis vertical icon"></i>
                                        <div class="menu">
                                            <a class="item" id="mainwp-clients-edit-custom-field" href="#"><?php esc_html_e( 'Edit', 'mainwp' ); ?></a>
                                            <a class="item" id="mainwp-clients-delete-general-field" href="#"><?php esc_html_e( 'Delete', 'mainwp' ); ?></a>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

        <script type="text/javascript">
        // Init datatables
        jQuery( '#mainwp-clients-custom-fields-table' ).DataTable( {
            "stateSave": true,
            "stateDuration": 0,
            "lengthMenu": [ [10, 25, 50, 100, -1], [10, 25, 50, 100, "All"] ],
            "columnDefs": [ { "orderable": false, "targets": "no-sort" } ],
            "order": [ [ 0, "asc" ] ],
            "language": { "emptyTable": "No fields found." },
            "drawCallback" : function( settings ) {
                jQuery( '#mainwp-clients-custom-fields-table .ui.dropdown').dropdown();
            },
        } );
        </script>
        </div>

            <?php
            static::render_add_field_modal();
            static::render_footer( 'AddField' );
    }

        /**
         * Method render_add_field_modal()
         *
         * Render add custom field modal.
         *
         * @param int $client_id The client id.
         */
    public static function render_add_field_modal( $client_id = 0 ) {
        ?>
        <div class="ui modal" id="mainwp-clients-custom-field-modal">
        <i class="close icon"></i>
            <div class="header"><?php esc_html_e( 'Custom Field', 'mainwp' ); ?></div>
            <div class="content ui mini form">
                <div class="ui yellow message" style="display:none"></div>
                <div class="field">
                    <label><?php esc_html_e( 'Field Name', 'mainwp' ); ?></label>
                    <input type="text" value="" class="field-name" name="field-name" placeholder="<?php esc_attr_e( 'Enter field name (without of square brackets)', 'mainwp' ); ?>">
                </div>
                <div class="field">
                    <label><?php esc_html_e( 'Field Description', 'mainwp' ); ?></label>
                    <input type="text" value="" class="field-description" name="field-description" placeholder="<?php esc_attr_e( 'Enter field description', 'mainwp' ); ?>">
                </div>
            </div>
            <div class="actions">
                <input type="button" class="ui green button" client-id="<?php echo intval( $client_id ); ?>" id="mainwp-clients-save-new-custom-field" value="<?php esc_attr_e( 'Save Field', 'mainwp' ); ?>">
            </div>
            <input type="hidden" value="0" name="field-id">
        </div>
            <?php
    }

    /**
     * Method add_client()
     *
     * Bulk client addition $_POST Handler.
     */
    public static function add_client() { // phpcs:ignore -- NOSONAR -Current complexity is required to achieve desired results. Pull request solutions appreciated.

        $selected_sites = ( isset( $_POST['selected_sites'] ) && is_array( $_POST['selected_sites'] ) ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['selected_sites'] ) ) : array(); //phpcs:ignore WordPress.Security.NonceVerification.Missing
        $client_fields  = isset( $_POST['client_fields'] ) ? wp_unslash( $_POST['client_fields'] ) : array(); //phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if ( ! is_array( $client_fields ) ) {
            $client_fields = array();
        }

        $editing_client_id = isset( $client_fields['client_id'] ) ? intval( $client_fields['client_id'] ) : 0;

        if ( ! empty( $editing_client_id ) && ! wp_verify_nonce( $_POST['nonce_client_id'], 'editing-client-' . $editing_client_id ) ) { //phpcs:ignore -- NOSONAR - ok.
            die( 'Invalid nonce!' );
        }

        if ( ! isset( $client_fields['default_field']['client.name'] ) || empty( $client_fields['default_field']['client.name'] ) ) {
            echo wp_json_encode( array( 'error' => esc_html__( 'Client name are empty. Please try again.', 'mainwp' ) ) );
            return;
        }

        $add_new = true;

        $default_client_fields = MainWP_Client_Handler::get_default_client_fields();
        $client_to_add         = array();
        foreach ( $default_client_fields as $field_name => $item ) {
            if ( ! empty( $item['db_field'] ) && isset( $client_fields['default_field'][ $field_name ] ) ) {
                $client_to_add[ $item['db_field'] ] = sanitize_text_field( wp_unslash( $client_fields['default_field'][ $field_name ] ) );
            }
        }

        $client_to_add['primary_contact_id'] = isset( $client_fields['default_field']['primary_contact_id'] ) ? intval( $client_fields['default_field']['primary_contact_id'] ) : 0;

        $client_id = isset( $client_fields['client_id'] ) ? intval( $client_fields['client_id'] ) : 0;

        $new_suspended = isset( $client_to_add['suspended'] ) ? (int) $client_to_add['suspended'] : 0;
        $old_suspended = $new_suspended;

        if ( $client_id ) {
            $current_client = MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $client_id );
            $old_suspended  = $current_client->suspended;

            $client_to_add['client_id'] = $client_id; // update client.
            if ( isset( $client_to_add['created'] ) && ! empty( $client_to_add['created'] ) ) {
                $client_to_add['created'] = strtotime( $client_to_add['created'] );
            }
            $add_new = false;
        } else {
            $client_to_add['created'] = time();
        }

        try {
            $inserted = MainWP_DB_Client::instance()->update_client( $client_to_add, true );
        } catch ( \Exception $e ) {
            echo wp_json_encode( array( 'error' => $e->getMessage() ) );
            return;
        }

        if ( $client_id ) {
            MainWP_DB_Client::instance()->update_selected_sites_for_client( $client_id, $selected_sites );
        } elseif ( is_object( $inserted ) ) {
            MainWP_DB_Client::instance()->update_selected_sites_for_client( $inserted->client_id, $selected_sites );
            $client_id = $inserted->client_id;
        }

        if ( is_object( $inserted ) ) {
            /**
             * Add client
             *
             * Fires after add a client.
             *
             * @param object $inserted client data.
             * @param bool $add_new true add new, false updated.
             *
             * @since 4.5.1.1
             */
            do_action( 'mainwp_client_updated', $inserted, $add_new );

            if ( ! $add_new && $new_suspended != $old_suspended ) { //phpcs:ignore -- to valid.
                /**
                 * Fires immediately after update client suspend/unsuspend.
                 *
                 * @since 4.5.1.1
                 *
                 * @param object $client  client data.
                 * @param bool $new_suspended true|false.
                 */
                do_action( 'mainwp_client_suspend', $inserted, $new_suspended );
            }
        }

        if ( $client_id && isset( $client_fields['custom_fields'] ) && is_array( $client_fields['custom_fields'] ) ) {
            foreach ( $client_fields['custom_fields'] as $field_val ) {
                $field_id = array_key_first( $field_val );
                // update custom field value for client.
                if ( $field_id ) {
                    $val = $field_val[ $field_id ];
                    MainWP_DB_Client::instance()->update_client_field_value( $field_id, $val, $client_id );
                }
            }
        }

        $client_image = '';
        if ( isset( $_POST['mainwp_add_edit_client_uploaded_icon_hidden'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            $client_image = sanitize_text_field( wp_unslash( $_POST['mainwp_add_edit_client_uploaded_icon_hidden'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
        }

        // compatible with quick setup.
        if ( isset( $_FILES['mainwp_client_image_uploader'] ) && isset( $_FILES['mainwp_client_image_uploader']['error']['client_field'] ) && UPLOAD_ERR_OK === $_FILES['mainwp_client_image_uploader']['error']['client_field'] ) { // phpcs:ignore WordPress.Security.NonceVerification
            $output = MainWP_System_Utility::handle_upload_image( 'client-images', $_FILES['mainwp_client_image_uploader'], 'client_field' ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            if ( is_array( $output ) && isset( $output['filename'] ) && ! empty( $output['filename'] ) ) {
                $client_image = $output['filename'];
            }
        }

        $client_data = MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $client_id );
        if ( $client_data && $client_data->image !== $client_image && $client_id ) {
            $old_file = $client_data->image;
            if ( $old_file !== $client_image && ! empty( $old_file ) ) {
                MainWP_Utility::instance()->delete_uploaded_icon_file( 'client-images', $old_file );
            }
            $update = array(
                'client_id' => $client_id,
                'image'     => $client_image,
            );
            MainWP_DB_Client::instance()->update_client( $update );
        }

        if ( $client_id && isset( $client_fields['default_field']['selected_icon'] ) ) {
            $cust_icon  = sanitize_text_field( wp_unslash( $client_fields['default_field']['selected_icon'] ) );
            $cust_color = sanitize_hex_color( wp_unslash( $client_fields['default_field']['selected_color'] ) );
            $update     = array(
                'client_id'          => $client_id,
                'selected_icon_info' => 'selected:' . $cust_icon . ';color:' . $cust_color,
            );
            MainWP_DB_Client::instance()->update_client( $update );
        }

        $is_first_contact       = true;
        $auto_assign_contact_id = 0;

        if ( $client_id && isset( $client_fields['contacts_field'] ) ) {

            foreach ( $client_fields['contacts_field']['client.contact.name'] as $indx => $contact_name ) {
                $contact_to_add = array();
                if ( empty( $contact_name ) ) {
                    continue;
                }
                $contact_to_add['contact_name'] = $contact_name;

                $contact_email = $client_fields['contacts_field']['contact.email'][ $indx ];
                if ( empty( $contact_email ) ) {
                    continue;
                }

                $contact_id = isset( $client_fields['contacts_field']['contact_id'][ $indx ] ) ? intval( $client_fields['contacts_field']['contact_id'][ $indx ] ) : 0;

                if ( empty( $contact_id ) ) {
                    continue;
                }

                $editing_contact_nonce_id = sanitize_key( $client_fields['contacts_field']['nonce_contact_id'][ $indx ] );

                if ( ! wp_verify_nonce( $editing_contact_nonce_id, 'editing-' . $client_id . '-contact-' . $contact_id ) ) {
                    continue;
                }

                $contact_to_add['contact_email'] = $contact_email;

                $contact_to_add['contact_phone'] = $client_fields['contacts_field']['contact.phone'][ $indx ];
                $contact_to_add['contact_role']  = $client_fields['contacts_field']['contact.role'][ $indx ];
                $contact_to_add['facebook']      = $client_fields['contacts_field']['contact.facebook'][ $indx ];
                $contact_to_add['twitter']       = $client_fields['contacts_field']['contact.twitter'][ $indx ];
                $contact_to_add['instagram']     = $client_fields['contacts_field']['contact.instagram'][ $indx ];
                $contact_to_add['linkedin']      = $client_fields['contacts_field']['contact.linkedin'][ $indx ];

                $cust_icon  = sanitize_text_field( wp_unslash( $client_fields['contacts_field']['selected_icon'][ $indx ] ) );
                $cust_color = sanitize_hex_color( wp_unslash( $client_fields['contacts_field']['selected_color'][ $indx ] ) );

                $contact_to_add['contact_icon_info'] = 'selected:' . $cust_icon . ';color:' . $cust_color;

                $contact_to_add['contact_client_id'] = $client_id;
                $contact_to_add['contact_id']        = $contact_id;

                $updated = MainWP_DB_Client::instance()->update_client_contact( $contact_to_add );

                $is_first_contact = false;

                if ( $updated ) {
                    $contact_data  = MainWP_DB_Client::instance()->get_wp_client_contact_by( 'contact_id', $contact_id );
                    $contact_image = '';
                    if ( isset( $_POST['mainwp_add_edit_contact_uploaded_icon_hidden']['contacts_field'][ $indx ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
                        $contact_image = sanitize_text_field( wp_unslash( $_POST['mainwp_add_edit_contact_uploaded_icon_hidden']['contacts_field'][ $indx ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
                    }
                    if ( $contact_data && $contact_data->contact_image !== $contact_image && $contact_id ) {
                        $old_file = $contact_data->contact_image;
                        if ( $old_file !== $contact_image && ! empty( $old_file ) ) {
                            MainWP_Utility::instance()->delete_uploaded_icon_file( 'client-images', $old_file );
                        }
                        $update = array(
                            'contact_id'    => $contact_id,
                            'contact_image' => $contact_image,
                        );
                        MainWP_DB_Client::instance()->update_client_contact( $update );
                    }
                }
            }
        }

        if ( $client_id && isset( $client_fields['new_contacts_field'] ) ) {

            foreach ( $client_fields['new_contacts_field']['client.contact.name'] as $indx => $contact_name ) {
                $contact_to_add = array();
                if ( empty( $contact_name ) ) {
                    continue;
                }
                $contact_to_add['contact_name'] = $contact_name;

                $contact_email = $client_fields['new_contacts_field']['contact.email'][ $indx ];
                if ( empty( $contact_email ) ) {
                    continue;
                }
                $contact_to_add['contact_email'] = $contact_email;

                $contact_to_add['contact_phone'] = isset( $client_fields['new_contacts_field']['contact.phone'][ $indx ] ) ? $client_fields['new_contacts_field']['contact.phone'][ $indx ] : '';
                $contact_to_add['contact_role']  = $client_fields['new_contacts_field']['contact.role'][ $indx ];
                $contact_to_add['facebook']      = isset( $client_fields['new_contacts_field']['contact.facebook'][ $indx ] ) ? $client_fields['new_contacts_field']['contact.facebook'][ $indx ] : '';
                $contact_to_add['twitter']       = isset( $client_fields['new_contacts_field']['contact.twitter'][ $indx ] ) ? $client_fields['new_contacts_field']['contact.twitter'][ $indx ] : '';
                $contact_to_add['instagram']     = isset( $client_fields['new_contacts_field']['contact.instagram'][ $indx ] ) ? $client_fields['new_contacts_field']['contact.instagram'][ $indx ] : '';
                $contact_to_add['linkedin']      = isset( $client_fields['new_contacts_field']['contact.linkedin'][ $indx ] ) ? $client_fields['new_contacts_field']['contact.linkedin'][ $indx ] : '';

                $cust_icon  = isset( $client_fields['new_contacts_field']['selected_icon'][ $indx ] ) ? sanitize_text_field( wp_unslash( $client_fields['new_contacts_field']['selected_icon'][ $indx ] ) ) : '';
                $cust_color = isset( $client_fields['new_contacts_field']['selected_color'][ $indx ] ) ? sanitize_hex_color( wp_unslash( $client_fields['new_contacts_field']['selected_color'][ $indx ] ) ) : '';

                $contact_to_add['contact_icon_info'] = 'selected:' . $cust_icon . ';color:' . $cust_color;

                $contact_to_add['contact_client_id'] = $client_id;

                $inserted = MainWP_DB_Client::instance()->update_client_contact( $contact_to_add );

                if ( $inserted ) {

                    $contact_id    = $inserted->contact_id;
                    $contact_image = '';
                    if ( isset( $_POST['mainwp_add_edit_contact_uploaded_icon_hidden']['new_contacts_field'][ $indx ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
                        $contact_image = sanitize_text_field( wp_unslash( $_POST['mainwp_add_edit_contact_uploaded_icon_hidden']['new_contacts_field'][ $indx ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
                    }

                    if ( '' !== $contact_image && $contact_id ) {
                        $update = array(
                            'contact_id'    => $contact_id,
                            'contact_image' => $contact_image,
                        );
                        MainWP_DB_Client::instance()->update_client_contact( $update );
                    }

                    if ( $is_first_contact && empty( $auto_assign_contact_id ) ) {
                        $auto_assign_contact_id = $contact_id;
                    }
                }
            }
        }

        if ( $client_id && isset( $client_fields['delele_contacts'] ) && is_array( $client_fields['delele_contacts'] ) ) {
            foreach ( $client_fields['delele_contacts'] as $delete_id ) {
                MainWP_DB_Client::instance()->delete_client_contact( $client_id, $delete_id );
                $is_first_contact = false;
            }
        }

        if ( $is_first_contact && $auto_assign_contact_id && $client_id ) {
            // auto assign.
            $update = array(
                'client_id'          => $client_id,
                'primary_contact_id' => $auto_assign_contact_id,
            );
            MainWP_DB_Client::instance()->update_client( $update );
        }

        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( isset( $_POST['is_first_client'] ) && ! empty( $_POST['is_first_client'] ) ) {
            delete_transient( 'mainwp_transient_just_connected_site_id' );
        }
        //phpcs:enable

        echo wp_json_encode(
            array(
                'success'   => 'yes',
                'client_id' => $client_id,
            )
        );
    }

    /**
     * Method render_add_client_modal().
     *
     * Renders add client Modal window.
     */
    public static function render_add_client_modal() {
        ?>
            <div id="mainwp-creating-new-client-modal" class="ui modal">
            <i class="close icon"></i>
                <div class="header"><?php esc_html_e( 'New client', 'mainwp' ); ?></div>
                <div class="ui message" id="mainwp-message-zone-client" style="display:none;"></div>
                <div class="scrolling content mainwp-modal-content">
                    <form action="" method="post" enctype="multipart/form-data" name="createclient_form" id="createclient_form" class="add:clients: validate">
                <?php
                    static::render_add_client_content();
                ?>
                    </form>
                </div>
                <div class="actions">
                    <div class="ui button green" current-page="modal-add" id="bulk_add_createclient"><?php esc_html_e( 'Add Client', 'mainwp' ); ?></div>
                </div>
            </div>

            <script type="text/javascript">
                jQuery(document).on('click', '.edit-site-new-client-button', function () {
                    jQuery('#mainwp-creating-new-client-modal').modal({
                        allowMultiple: true,
                    }).modal('show');
                    return false;
                });
            </script>
            <?php
    }

    /**
     * Method render_add_client_content().
     *
     * Renders add client content window.
     *
     * @param mixed $edit_client The client data.
     */
    public static function render_add_client_content( $edit_client = false ) { // phpcs:ignore -- NOSONAR -Current complexity is required to achieve desired results. Pull request solutions appreciated.
        $client_id             = $edit_client ? $edit_client->client_id : 0;
        $default_client_fields = MainWP_Client_Handler::get_default_client_fields();
        $custom_fields         = MainWP_DB_Client::instance()->get_client_fields( true, $client_id, true );
        $client_image          = $edit_client ? $edit_client->image : '';

        $icon_info_array = array();
        if ( $edit_client ) {
            $arr_fields      = array(
                'image',
                'selected_icon_info',
            );
            $icon_info_array = MainWP_Utility::map_fields( $edit_client, $arr_fields, false );
        }

        $uploaded_icon_src = '';
        if ( ! empty( $client_image ) ) {
            $uploaded_icon_src = MainWP_Client_Handler::get_client_contact_image( $icon_info_array, 'client', 'uploaded_icon' );
        }

        ?>
        <h2 class="ui dividing header">
            <?php if ( $client_id ) : ?>
                <?php MainWP_Settings_Indicator::render_indicator( 'header', 'settings-field-indicator-edit-client' ); ?>
                <?php echo esc_html__( 'Edit Client', 'mainwp' ); ?>
                <div class="sub header"><?php esc_html_e( 'Update client details, manage linked websites, and keep important information organized in one place.', 'mainwp' ); ?></div>
            <?php else : ?>
                <?php esc_html_e( 'Add New Client', 'mainwp' ); ?>
                <div class="sub header"><?php esc_html_e( 'Manage your client relationships by adding a new client and linking their websites for better organization.', 'mainwp' ); ?></div>
            <?php endif; ?>
        </h2>
        <div class="ui form">
            <input type="hidden" name="nonce_client_id" id="nonce_client_id" value="<?php echo esc_attr( wp_create_nonce( 'editing-client-' . $client_id ) ); ?>">
            <?php
            foreach ( $default_client_fields as $field_name => $field ) {
                $db_field = isset( $field['db_field'] ) ? $field['db_field'] : '';
                $val      = $edit_client && '' !== $db_field && property_exists( $edit_client, $db_field ) ? $edit_client->{$db_field} : '';
                $tip      = isset( $field['tooltip'] ) ? $field['tooltip'] : '';
                ?>
                <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-edit-client">
                    <label class="six wide column middle aligned" for="client_fields[default_field][<?php echo esc_attr( $field_name ); ?>]" <?php echo ! empty( $tip ) ? 'data-tooltip="' . esc_attr( $tip ) . '" data-inverted="" data-position="top left"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
                    <?php
                    $indi_val = $val && $edit_client ? 1 : 0;
                    MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $indi_val );
                    echo esc_html( $field['title'] );
                    ?>
                    </label>
                    <div class="ui six wide column">
                        <div class="ui left labeled input">
                    <?php
                    if ( 'client.note' === $field_name ) {
                        ?>
                            <div class="editor">
                                <textarea class="code settings-field-value-change-handler" cols="80" rows="10" id="client_fields[default_field][<?php echo esc_attr( $field_name ); ?>]" name="client_fields[default_field][<?php echo esc_attr( $field_name ); ?>]"><?php echo esc_html( $val ); ?></textarea>
                            </div>
                            <?php
                    } elseif ( 'client.suspended' === $field_name ) {
                        ?>
                            <select class="ui dropdown settings-field-value-change-handler" name="client_fields[default_field][<?php echo esc_attr( $field_name ); ?>]" id="client_fields[default_field][<?php echo esc_attr( $field_name ); ?>]" >
                                <option value="0" <?php echo '0' === $val ? 'selected' : ''; ?>><?php esc_html_e( 'Active', 'mainwp' ); ?></option>
                                <option value="1" <?php echo '1' === $val ? 'selected' : ''; ?>><?php esc_html_e( 'Suspended', 'mainwp' ); ?></option>
                                <option value="2" <?php echo '2' === $val ? 'selected' : ''; ?>><?php esc_html_e( 'Lead', 'mainwp' ); ?></option>
                                <option value="3" <?php echo '3' === $val ? 'selected' : ''; ?>><?php esc_html_e( 'Lost', 'mainwp' ); ?></option>
                            </select>
                        <?php
                    } elseif ( $client_id && 'client.created' === $field_name ) {
                        $created = empty( $val ) ? time() : $val;
                        ?>
                        <div class="ui calendar mainwp_datepicker" >
                            <div class="ui input left icon">
                                <i class="calendar icon"></i>
                                <input type="text" autocomplete="off" name="client_fields[default_field][<?php echo esc_attr( $field_name ); ?>]" placeholder="<?php esc_attr_e( 'Added date', 'mainwp' ); ?>" id="client_fields[default_field][<?php echo esc_attr( $field_name ); ?>]" value="<?php echo esc_attr( date( 'Y-m-d', $created ) ); // phpcs:ignore -- local time. ?>"/>
                            </div>
                        </div>
                        <?php
                    } else {
                        ?>
                            <input type="text" id="client_fields[default_field][<?php echo esc_attr( $field_name ); ?>]" class="regular-text settings-field-value-change-handler" value="<?php echo esc_html( $val ); ?>" name="client_fields[default_field][<?php echo esc_attr( $field_name ); ?>]"/>
                        <?php
                    }
                    ?>
                        </div>
                    </div>
                    <?php if ( $client_id ) : ?>
                    <div class="ui four wide middle aligned hidden token column" style="display:none">
                        <?php if ( 'client.suspended' !== $field_name ) { ?>
                        [<?php echo esc_html( $field_name ); ?>]
                    <?php } ?>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ( 'client.email' === $field_name ) { ?>
                        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-edit-client">
                            <label class="six wide column middle aligned">
                                <?php
                                $indi_val = $client_image && $edit_client ? 1 : 0;
                                MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $indi_val );
                                $delnonce = MainWP_System_Utility::get_custom_nonce( 'client', esc_attr( $client_image ) );
                                ?>
                                <?php esc_html_e( 'Client logo (photo)', 'mainwp' ); ?>
                            </label>
                            <input type="hidden" name="mainwp_add_edit_client_uploaded_icon_hidden" class="settings-field-value-change-handler" id="mainwp_add_edit_client_uploaded_icon_hidden" value="<?php echo esc_attr( $client_image ); ?>">
                            <div class="ui six wide column">
                                <span class="ui circular bordered image">
                                    <?php if ( ! empty( $client_image ) ) { ?>
                                        <?php echo MainWP_Client_Handler::get_client_contact_image( $icon_info_array, 'client', 'display_edit' ); //phpcs:ignore --ok. ?>
                                    <?php } else { ?>
                                        <div style="display:inline-block;" id="mainwp_add_edit_client_upload_custom_icon"></div> <?php // used for icon holder. ?>
                                    <?php } ?>
                                </span>
                                <div class="ui basic button mainwp-add-edit-client-icon-customable"
                                    iconItemId="<?php echo intval( $client_id ); ?>"
                                    iconFileSlug="<?php echo esc_attr( $client_image ); ?>"
                                    del-icon-nonce="<?php echo esc_attr( $delnonce ); ?>"
                                    icon-src="<?php echo esc_attr( $uploaded_icon_src ); ?>">
                                    <i class="image icon"></i> <?php echo ! empty( $client_image ) ? esc_html__( 'Change Image', 'mainwp' ) : esc_html__( 'Upload Image', 'mainwp' ); ?>
                                </div>
                            </div>
                        </div>



                        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-edit-client" default-indi-indi-value="wordpress">
                            <label class="six wide column middle aligned">
                            <?php
                            $default_icons  = MainWP_UI::get_default_icons();
                            $selected_icon  = 'wordpress'; //phpcs:ignore -- WP icon.
                            $selected_color = '#34424D';

                            $icon_info = $edit_client ? $edit_client->selected_icon_info : '';
                            if ( ! empty( $icon_info ) ) {
                                $selected_icon  = static::get_cust_client_icon( $icon_info, 'selected' );
                                $selected_color = static::get_cust_client_icon( $icon_info, 'color' );
                            }

                            $indi_val = 'wordpress' !== $selected_icon ? 1 : 0; //phpcs:ignore -- WP icon.
                            MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $indi_val );
                            esc_html_e( 'Select icon', 'mainwp' );
                            ?>
                            </label>
                            <input type="hidden" name="client_fields[default_field][selected_icon]" class="settings-field-value-change-handler" id="client_fields[default_field][selected_icon]" value="<?php echo esc_attr( $selected_icon ); ?>">
                            <div class="six wide column" data-tooltip="<?php esc_attr_e( 'Select an icon if not using original client icon.', 'mainwp' ); ?>" data-inverted="" data-position="left center">
                                <div class="ui left action input mainwp-dropdown-color-picker-field">
                                    <div class="ui five column selection search dropdown not-auto-init" id="mainwp_edit_clients_icon_select">
                                        <div class="text">
                                            <span style="color:<?php echo esc_attr( $selected_color ); ?>" ><?php echo ! empty( $selected_icon ) ? '<i class="' . esc_attr( $selected_icon ) . ' icon"></i>' : ''; ?></span>
                                        </div>
                                        <i class="dropdown icon"></i>
                                        <div class="menu">
                                            <?php foreach ( $default_icons as $icon ) : ?>
                                                <?php echo '<div class="item" style="color:' . esc_attr( $selected_color ) . '" data-value="' . esc_attr( $icon ) . '"><i class="' . esc_attr( $icon ) . ' icon"></i></div>'; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <input type="color" data-tooltip="Color will update on save" data-position="top center" data-inverted="" name="client_fields[default_field][selected_color]" class="mainwp-color-picker-input" id="client_fields[default_field][selected_color]"  value="<?php echo esc_attr( $selected_color ); ?>" />
                                </div>
                            </div>
                            <div class="one wide column"></div>
                    </div>
                        <?php
                }
            }

            $client_contacts = array();
            if ( $client_id ) {
                $client_contacts = MainWP_DB_Client::instance()->get_wp_client_contact_by( 'client_id', $client_id );
                ?>
                <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-edit-client">
                    <label class="six wide column middle aligned">
                    <?php
                    $indi_val = $edit_client && $client_contacts ? 1 : 0;
                    MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $indi_val );
                    echo esc_html_e( 'Client primary contact', 'mainwp' );
                    ?>
                    </label>
                    <div class="ui six wide column">
                        <div class="ui left labeled">
                            <div class="ui search selection dropdown" init-value="" id="client_fields[default_field][primary_contact_id]">
                            <input type="hidden" name="client_fields[default_field][primary_contact_id]" value="<?php echo $edit_client ? intval( $edit_client->primary_contact_id ) : 0; ?>">
                            <i class="dropdown icon"></i>
                                <div class="default text"><?php esc_attr_e( 'Select primary contact', 'mainwp' ); ?></div>
                                <div class="menu">
                                <div class="item" data-value="0"><?php esc_attr_e( 'Select primary contact', 'mainwp' ); ?></div>
                                <?php if ( $client_contacts ) : ?>
                                    <?php foreach ( $client_contacts as $contact ) { ?>
                                        <div class="item" data-value="<?php echo intval( $contact->contact_id ); ?>"><?php echo esc_html( stripslashes( $contact->contact_name ) ); ?></div>
                                    <?php } ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="ui four wide column">
                    </div>
                </div>
                <div class="ui section hidden divider"></div>
                <?php
            }

            if ( is_array( $custom_fields ) && count( $custom_fields ) > 0 ) {
                $compatible_tokens = MainWP_Client_Handler::get_compatible_tokens();
                foreach ( $custom_fields as $field ) {
                    if ( isset( $default_client_fields[ $field->field_name ] ) ) {
                        continue;
                    }
                    // do not show these tokens.
                    if ( isset( $compatible_tokens[ $field->field_name ] ) ) {
                        continue;
                    }
                    $field_val = ( property_exists( $field, 'field_value' ) && '' !== $field->field_value ) ? esc_html( $field->field_value ) : '';
                    ?>
                    <div class="ui grid field mainwp-field settings-field-indicator-wrapper settings-field-indicator-edit-client"  field-id="<?php echo intval( $field->field_id ); ?>">
                        <label class="six wide column middle aligned field-description">
                        <?php
                        $indi_val = $edit_client && $field_val ? 1 : 0;
                        MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $indi_val );
                        echo esc_html( $field->field_desc );
                        ?>
                        </label>
                        <div class="ui six wide column">
                            <div class="ui left labeled input">
                                <input type="text" value="<?php echo esc_attr( $field_val ); ?>" class="regular-text" name="client_fields[custom_fields][<?php echo esc_html( $field->field_name ); ?>][<?php echo esc_html( $field->field_id ); ?>]"/>
                            </div>
                        </div>
                        <div class="ui four wide column">
                        <?php if ( $client_id > 0 && $field->client_id > 0 ) { // edit client and it is individual field, then show to edit/delete field buttons. ?>
                            <div class="ui right pointing dropdown">
                                <i class="ellipsis vertical icon"></i>
                                <div class="menu">
                                    <a class="item" id="mainwp-clients-edit-custom-field" href="#"><?php esc_html_e( 'Edit', 'mainwp' ); ?></a>
                                    <a class="item" client-id="<?php echo intval( $client_id ); ?>" id="mainwp-clients-delete-individual-field" href="#"><?php esc_html_e( 'Delete', 'mainwp' ); ?></a>
                                </div>
                            </div>
                            <?php } ?>
                            <span class="field-name">[<?php echo esc_html( $field->field_name ); ?>]</span>
                        </div>
                    </div>
                    <?php
                }
            }

            $temp = static::get_add_contact_temp( false, false );

            if ( $client_id && $client_contacts ) {
                foreach ( $client_contacts as $client_contact ) {
                    static::get_add_contact_temp( $client_contact, true, $client_id );
                }
            }
            ?>
        <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-edit-client">
            <label class="six wide column middle aligned"><?php esc_html_e( 'Create a new contact for this client', 'mainwp' ); ?></label>
            <div class="ui six wide column">
                <div class="ui left labeled input">
                    <a href="javascript:void(0);" class="ui basic button mainwp-client-add-contact" add-contact-temp="<?php echo esc_attr( $temp ); ?>"><i class="user icon"></i><?php esc_html_e( 'Add Contact', 'mainwp' ); ?></a>
                </div>
            </div>
        </div>
        <div class="ui section hidden divider after-add-contact-field"></div>
        </div>
        <input type="hidden" name="client_fields[client_id]" value="<?php echo intval( $client_id ); ?>">
        <script type="text/javascript">
                jQuery( document ).ready( function () {
                    // to fix issue not loaded calendar js library
                    if (jQuery('.ui.calendar').length > 0) {
                        if (mainwpParams.use_wp_datepicker == 1) {
                            jQuery('#mainwp-add-new-client-form .ui.calendar input[type=text]').datepicker({ dateFormat: "yy-mm-dd" });
                        } else {
                            mainwp_init_ui_calendar( '#mainwp-add-new-client-form .ui.calendar' );
                        }
                    }

                    let current_iconObj;

                    jQuery(document).on('click', '.mainwp-add-edit-client-icon-customable', function () {
                        current_iconObj = jQuery(this);
                        jQuery('#mainwp_delete_image_field').hide();
                        jQuery('#mainwp-upload-custom-icon-modal').modal({
                            allowMultiple: true, // multiple modals.
                            closable: false,
                        }).modal('show');
                        jQuery('#update_custom_icon_btn').attr('uploading-icon', 'client');
                        jQuery('#update_custom_icon_btn').removeAttr('disabled');
                        jQuery('#mainwp_delete_image_field').find('#mainwp_delete_image_chk').attr('iconItemId', current_iconObj.attr('iconItemId') ); // @see used by mainwp_upload_custom_types_icon().
                        jQuery('#mainwp_delete_image_field').find('#mainwp_delete_image_chk').attr('iconFileSlug', current_iconObj.attr('iconFileSlug') ); // @see used by mainwp_upload_custom_types_icon().

                        if (current_iconObj.attr('icon-src') != '') {
                            jQuery('#mainwp_delete_image_field').find('.ui.image').attr('src', current_iconObj.attr('icon-src'));
                            jQuery('#mainwp_delete_image_field').show();
                        }
                    });

                    jQuery(document).on('click', '.mainwp-add-edit-contact-icon-customable', function () {
                        current_iconObj = jQuery(this);
                        jQuery('#mainwp_delete_image_field').hide();
                        jQuery('#mainwp-upload-custom-icon-modal').modal({
                            allowMultiple: true, // multiple modals.
                            closable: false,
                        }).modal('show');
                        jQuery('#update_custom_icon_btn').attr('uploading-icon', 'contact');
                        jQuery('#update_custom_icon_btn').removeAttr('disabled');
                        jQuery('#mainwp_delete_image_field').find('#mainwp_delete_image_chk').attr('iconItemId', current_iconObj.attr('iconItemId') ); // @see used by mainwp_upload_custom_types_icon().
                        jQuery('#mainwp_delete_image_field').find('#mainwp_delete_image_chk').attr('iconFileSlug', current_iconObj.attr('iconFileSlug') ); // @see used by mainwp_upload_custom_types_icon().

                        if (current_iconObj.attr('icon-src') != '') {
                            jQuery('#mainwp_delete_image_field').find('.ui.image').attr('src', current_iconObj.attr('icon-src'));
                            jQuery('#mainwp_delete_image_field').show();
                        }
                    });

                    // to fix conflict with other update custom icon click event.
                    jQuery(document).on('click', '#update_custom_icon_btn', function () {
                        if( 'client' === jQuery(this).attr( 'uploading-icon' ) ){
                            mainwp_update_custom_icon_client( current_iconObj );
                        } else if( 'contact' === jQuery(this).attr( 'uploading-icon' ) ){
                            mainwp_update_custom_icon_contact( current_iconObj );
                        }
                    });

                    mainwp_update_custom_icon_client = function( iconObj ){
                        let deleteIcon = jQuery('#mainwp_delete_image_chk').is(':checked');
                        let iconItemId = iconObj.attr('iconItemId');
                        let iconFileSlug = iconObj.attr('iconFileSlug'); // to support delete file when iconItemId = 0.

                        // upload/delete lient icon action.
                        mainwp_upload_custom_types_icon(iconObj, 'mainwp_add_edit_client_upload_client_icon', iconItemId, iconFileSlug, deleteIcon, function(response){
                            if (jQuery('#mainwp_add_edit_client_uploaded_icon_hidden').length > 0) {
                                if (typeof response.iconfile !== undefined) {
                                    jQuery('#mainwp_add_edit_client_uploaded_icon_hidden').val(response.iconfile);
                                } else {
                                    jQuery('#mainwp_add_edit_client_uploaded_icon_hidden').val('');
                                }
                                jQuery( '#mainwp_add_edit_client_uploaded_icon_hidden' ).trigger('change');
                            }
                            let deleteIcon = jQuery('#mainwp_delete_image_chk').is(':checked'); // to delete.
                            if(deleteIcon){
                                jQuery('#mainwp_add_edit_client_upload_custom_icon').hide();
                            } else if (jQuery('#mainwp_add_edit_client_upload_custom_icon').length > 0) {
                                if (typeof response.iconfile !== undefined) {
                                    let icon_img = typeof response.iconimg !== undefined ? response.iconimg : '';
                                    let icon_src = typeof response.iconsrc !== undefined ? response.iconsrc : '';
                                    iconObj.attr('icon-src', icon_src);
                                    iconObj.attr('iconFileSlug', response.iconfile); // to support delete file when iconItemId = 0.
                                    iconObj.attr('del-icon-nonce', response.iconnonce);
                                    jQuery('#mainwp_delete_image_field').find('.ui.image').attr('src', icon_src);
                                    jQuery('#mainwp_add_edit_client_upload_custom_icon').html(icon_img);
                                    jQuery('#mainwp_add_edit_client_upload_custom_icon').show();
                                }
                            }
                            setTimeout(function () {
                                //window.location.href = location.href;
                                jQuery('#mainwp-upload-custom-icon-modal').modal('hide')
                            }, 1000);
                        });
                        return false;
                    }

                    mainwp_update_custom_icon_contact = function( iconObj ){
                        let deleteIcon = jQuery('#mainwp_delete_image_chk').is(':checked');
                        let iconItemId = iconObj.attr('iconItemId');
                        let iconFileSlug = iconObj.attr('iconFileSlug'); // to support delete file when iconItemId = 0.

                        // upload/delete lient icon action.
                        mainwp_upload_custom_types_icon(iconObj, 'mainwp_add_edit_contact_upload_contact_icon', iconItemId, iconFileSlug, deleteIcon, function(response){
                            let parent = jQuery(iconObj).closest('.mainwp_edit_clients_contact_uploaded_icon_wrapper');

                            if (jQuery('#mainwp_add_edit_client_uploaded_icon_hidden').length > 0) {
                                if (typeof response.iconfile !== undefined) {
                                    jQuery(parent).find('.mainwp_add_edit_contact_uploaded_icon_hidden').val(response.iconfile);
                                } else {
                                    jQuery(parent).find('.mainwp_add_edit_contact_uploaded_icon_hidden').val('');
                                }
                                jQuery(parent).find( '.mainwp_add_edit_contact_uploaded_icon_hidden' ).trigger('change');
                            }
                            let deleteIcon = jQuery('#mainwp_delete_image_chk').is(':checked'); // to delete.
                            if(deleteIcon){
                                jQuery(parent).find('.mainwp_add_edit_contact_upload_custom_icon').hide();
                            } else if (jQuery(parent).find('.mainwp_add_edit_contact_upload_custom_icon').length > 0) {
                                if (typeof response.iconfile !== undefined) {
                                    let icon_img = typeof response.iconimg !== undefined ? response.iconimg : '';
                                    let icon_src = typeof response.iconsrc !== undefined ? response.iconsrc : '';
                                    iconObj.attr('icon-src', icon_src);
                                    iconObj.attr('iconFileSlug', response.iconfile); // to support delete file when iconItemId = 0.
                                    iconObj.attr('del-icon-nonce', response.iconnonce);
                                    jQuery('#mainwp_delete_image_field').find('.ui.image').attr('src', icon_src);
                                    jQuery(parent).find('.mainwp_add_edit_contact_upload_custom_icon').html(icon_img).show();
                                }
                            }
                            setTimeout(function () {
                                //window.location.href = location.href;
                                jQuery('#mainwp-upload-custom-icon-modal').modal('hide')
                            }, 1000);
                        });
                        return false;
                    }

                } );
            </script>
        <?php
    }

    /**
     * Method get_cust_client_icon()
     *
     * Get site icon.
     *
     * @param string $icon_data icon data.
     * @param string $type Type: selected|color|display.
     * @param string $what Icon display for what.
     */
    public static function get_cust_client_icon( $icon_data, $type = 'display', $what = 'default' ) {
        if ( empty( $icon_data ) ) {
            return '';
        }
        $data          = explode( ';', $icon_data );
        $selected_icon = str_replace( 'selected:', '', $data[0] );
        $color         = str_replace( 'color:', '', $data[1] );

        if ( empty( $color ) ) {
            $color = '#34424D';
        }

        $icon_cls = 'large icon custom-icon';
        if ( 'card' === $what ) {
            $icon_cls = 'icon huge custom-icon';
        }

        $output = '';
        if ( 'selected' === $type ) {
            $output = $selected_icon;
        } elseif ( 'color' === $type ) {
            $output = $color;
        } elseif ( 'display' === $type || 'display_edit' === $what ) {
            $color_style = '';
            if ( ! empty( $color ) ) {
                $color_style = 'color:' . esc_attr( $color ) . ';';
            }
            $icon_wrapper_attr = 'class="mainwp_client_icon_display"';
            if ( 'display_edit' === $what ) {
                $icon_wrapper_attr = ' id="mainwp_add_edit_client_upload_custom_icon" ' . $icon_wrapper_attr;
            }
            $output = '<div style="display:inline-block;' . $color_style . '" ' . $icon_wrapper_attr . ' ><i class="' . esc_attr( $selected_icon ) . ' ' . $icon_cls . '" ></i></div>';
        }
        return $output;
    }

    /**
     * Method get_add_contact_temp().
     *
     * Get add contact template.
     *
     * @param mixed $edit_contact The contact data to edit.
     * @param bool  $echo_out Echo template or not.
     * @param int   $client_id Client id.
     */
    public static function get_add_contact_temp( $edit_contact = false, $echo_out = false, $client_id = 0 ) { //phpcs:ignore -- NOSONAR - complex.

        $input_name    = 'new_contacts_field';
        $contact_id    = 0;
        $contact_image = '';
        if ( $edit_contact ) {
            $input_name    = 'contacts_field';
            $contact_id    = $edit_contact->contact_id;
            $contact_image = $edit_contact->contact_image;
        }

        $uploaded_icon_src = '';
        if ( ! empty( $contact_image ) ) {
            $arr_fields        = array(
                'contact_image',
                'contact_icon_info',
            );
            $icon_info_array   = MainWP_Utility::map_fields( $edit_contact, $arr_fields, false );
            $uploaded_icon_src = MainWP_Client_Handler::get_client_contact_image( $icon_info_array, 'contact', 'uploaded_icon' );
        }

        ob_start();
        ?>
        <h3 class="ui dividing header top-contact-fields"> <?php // must have class: top-contact-fields. ?>
        <?php if ( $edit_contact ) : ?>
                <?php echo esc_html__( 'Edit Contact', 'mainwp' ); ?>
                <div class="sub header"><?php esc_html_e( 'Edit contact person information.', 'mainwp' ); ?></div>
            <?php else : ?>
                <?php esc_html_e( 'Add Contact', 'mainwp' ); ?>
                <div class="sub header"><?php esc_html_e( 'Enter contact person information.', 'mainwp' ); ?></div>
            <?php endif; ?>
        </h3>
            <?php
            $default_icons  = MainWP_UI::get_default_icons();
            $contact_fields = MainWP_Client_Handler::get_default_contact_fields();
            foreach ( $contact_fields as $field_name => $field ) {
                    $db_field   = isset( $field['db_field'] ) ? $field['db_field'] : '';
                    $val        = $edit_contact && '' !== $db_field && property_exists( $edit_contact, $db_field ) ? $edit_contact->{$db_field} : '';
                    $contact_id = $edit_contact && property_exists( $edit_contact, 'contact_id' ) ? $edit_contact->contact_id : '';
                ?>
                <div class="ui grid field settings-field-indicator-wrapper">
                    <label class="six wide column middle aligned">
                    <?php
                    $indi_val = $edit_contact && $val ? 1 : 0;
                    MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $indi_val );
                    echo esc_html( $field['title'] );
                    ?>
                    </label>
                    <div class="ui six wide column">
                        <div class="ui left labeled input">
                            <input type="text" value="<?php echo esc_attr( $val ); ?>" class="regular-text settings-field-value-change-handler" name="client_fields[<?php echo esc_attr( $input_name ); ?>][<?php echo esc_attr( $field_name ); ?>][]"/>
                        </div>
                    </div>
                    <?php if ( $edit_contact ) : ?>
                    <div class="ui four wide middle aligned hidden token column" style="display:none">
                        [<?php echo esc_html( $field_name ); ?>]
                    </div>
                    <?php endif; ?>
                </div>
                <?php
                if ( 'contact.role' === $field_name ) {
                    ?>
                        <div class="ui grid field mainwp_edit_clients_contact_uploaded_icon_wrapper settings-field-indicator-wrapper">
                            <label class="six wide column middle aligned">
                                <?php
                                $indi_val = $edit_contact && $contact_image ? 1 : 0;
                                MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $indi_val );
                                $delnonce = MainWP_System_Utility::get_custom_nonce( 'contact', esc_attr( $contact_image ) );
                                ?>
                                <?php esc_html_e( 'Contact photo', 'mainwp' ); ?>
                            </label>
                            <input type="hidden" name="mainwp_add_edit_contact_uploaded_icon_hidden[<?php echo esc_html( $input_name ); ?>][]" class="settings-field-value-change-handler mainwp_add_edit_contact_uploaded_icon_hidden" value="<?php echo esc_attr( $contact_image ); ?>">
                            <div class="three wide middle aligned column" data-tooltip="<?php esc_attr_e( 'Upload a contact photo.', 'mainwp' ); ?>" data-inverted="" data-position="left center">
                                <div class="ui green button basic mainwp-add-edit-contact-icon-customable" iconItemId="<?php echo intval( $contact_id ); ?>" iconFileSlug="<?php echo esc_attr( $contact_image ); ?>" del-icon-nonce="<?php echo esc_attr( $delnonce ); ?>" icon-src="<?php echo esc_attr( $uploaded_icon_src ); ?>"><?php esc_html_e( 'Upload Icon', 'mainwp' ); ?></div>
                                <?php
                                if ( ! empty( $contact_image ) ) {
                                    ?>
                                    <?php echo MainWP_Client_Handler::get_client_contact_image( $icon_info_array, 'contact', 'display_edit' ); //phpcs:ignore --ok. ?>
                                <?php } else { ?>
                                    <div style="display:inline-block;" class="mainwp_add_edit_contact_upload_custom_icon"></div> <?php // used for icon holder. ?>
                                <?php } ?>
                            </div>
                        </div>
                        <div class="ui grid field mainwp_edit_clients_contact_icon_wrapper settings-field-indicator-wrapper" input-name="<?php echo esc_attr( $input_name ); ?>" default-indi-indi-value="wordpress">
                                <label class="six wide column middle aligned">
                                <?php
                                $selected_icon  = 'wordpress'; //phpcs:ignore -- WP icon.
                                $selected_color = '#34424D';
                                $icon_info      = $edit_contact ? $edit_contact->contact_icon_info : '';

                                if ( ! empty( $icon_info ) ) {
                                    $selected_icon  = static::get_cust_client_icon( $icon_info, 'selected' );
                                    $selected_color = static::get_cust_client_icon( $icon_info, 'color' );
                                }
                                $indi_val = 'wordpress' !== $selected_icon ? 1 : 0; //phpcs:ignore -- WP icon.
                                MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $indi_val );
                                esc_html_e( 'Select icon', 'mainwp' );
                                ?>
                                </label>
                                <div class="six wide column" data-tooltip="<?php esc_attr_e( 'Select an icon if not using original contact icon.', 'mainwp' ); ?>" data-inverted="" data-position="left center">
                                    <input type="hidden" class="settings-field-value-change-handler" name="client_fields[<?php echo esc_html( $input_name ); ?>][selected_icon][]" id="client_fields[<?php echo esc_attr( $input_name ); ?>][selected_icon][]" value="<?php echo esc_attr( $selected_icon ); ?>">
                                    <div class="ui left action input mainwp-dropdown-color-picker-field">
                                        <div class="ui five column selection search dropdown not-auto-init mainwp-edit-clients-select-contact-icon" style="min-width:21em">
                                            <div class="text">
                                                <span style="color:<?php echo esc_attr( $selected_color ); ?>" ><?php echo ! empty( $selected_icon ) ? '<i class="' . esc_attr( $selected_icon ) . ' icon"></i>' : ''; ?></span>
                                            </div>
                                            <i class="dropdown icon"></i>
                                            <div class="menu">
                                                <?php foreach ( $default_icons as $icon ) : ?>
                                                    <?php echo '<div class="item" style="color:' . esc_attr( $selected_color ) . '" data-value="' . esc_attr( $icon ) . '"><i class="' . esc_attr( $icon ) . ' icon"></i></div>'; ?>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                        <input type="color" data-tooltip="Color will update on save" data-position="top center" data-inverted="" name="client_fields[<?php echo esc_html( $input_name ); ?>][selected_color][]" class="mainwp-color-picker-input" id="client_fields[<?php echo esc_html( $input_name ); ?>][selected_color][]"  value="<?php echo esc_attr( $selected_color ); ?>" />
                                    </div>
                                </div>
                                <div class="one wide column"></div>
                        </div>
                    <?php
                }
            }

            ?>
            <div class="ui grid field remove-contact-field-parent">
                <label class="six wide column middle aligned"><?php esc_html_e( 'Remove contact', 'mainwp' ); ?></label>
                <div class="ui six wide column">
                    <div class="ui left labeled input">
                    <a href="javascript:void(0);" contact-id="<?php echo intval( $contact_id ); ?>" class="ui basic button mainwp-client-remove-contact"><?php esc_html_e( 'Remove contact', 'mainwp' ); ?></a>
                    </div>
                        <?php
                        if ( $edit_contact ) {
                            ?>
                            <input type="hidden" value="<?php echo intval( $edit_contact->contact_id ); ?>" name="client_fields[contacts_field][contact_id][]"/>
                            <input type="hidden" value="<?php echo esc_attr( wp_create_nonce( 'editing-' . $client_id . '-contact-' . $edit_contact->contact_id ) ); ?>" name="client_fields[contacts_field][nonce_contact_id][]"/>
                            <?php
                        }
                        ?>
                </div>
            </div>
            <div class="ui section hidden divider bottom-contact-fields"></div>
            <?php
            $html = ob_get_clean();

            if ( $echo_out ) {
                echo $html; //phpcs:ignore -- validated content.
            }

            return $html;
    }

    /**
     * Method save_client_field().
     *
     * Save custom fields.
     */
    public static function save_client_field() { //phpcs:ignore -- NOSONAR - complex.

        $return = array(
            'success' => false,
            'error'   => '',
            'message' => '',
        );

        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $client_id  = isset( $_POST['client_id'] ) ? intval( $_POST['client_id'] ) : 0; // 0 is global client's field.
        $field_id   = isset( $_POST['field_id'] ) ? intval( $_POST['field_id'] ) : 0;
        $field_desc = isset( $_POST['field_desc'] ) ? sanitize_text_field( wp_unslash( $_POST['field_desc'] ) ) : '';
        $field_name = isset( $_POST['field_name'] ) ? sanitize_text_field( wp_unslash( $_POST['field_name'] ) ) : '';
        $field_name = trim( $field_name, '[]' );
        // phpcs:enable

        // update general or individual client field.
        if ( $field_id ) {
            $current = MainWP_DB_Client::instance()->get_client_fields_by( 'field_id', $field_id );
            if ( $current && $current->field_name === $field_name && $current->field_desc === $field_desc ) {
                $return['success'] = true;
                $return['message'] = esc_html__( 'Field has been saved without changes.', 'mainwp' );
            } else {
                $current = MainWP_DB_Client::instance()->get_client_fields_by( 'field_name', $field_name, $client_id ); // check if other field with the same name existed.
                if ( $current && (int) $current->field_id !== $field_id ) {
                    $return['error'] = esc_html__( 'Field already exists, try different field name.', 'mainwp' );
                } else {
                    // update general or individual field name.
                    $field = MainWP_DB_Client::instance()->update_client_field(
                        $field_id,
                        array(
                            'field_name' => $field_name,
                            'field_desc' => $field_desc,
                            'client_id'  => $client_id,
                        )
                    );
                    if ( $field ) {
                        $return['success'] = true;
                    }
                }
            }
        } else { // add new.
            $current = MainWP_DB_Client::instance()->get_client_fields_by( 'field_name', $field_name, $client_id );
            if ( $current ) { // checking general or individual field name.
                $return['error'] = esc_html__( 'Field already exists, try different field name.', 'mainwp' );
            } else {
                // insert general or individual field name.
                $field = MainWP_DB_Client::instance()->add_client_field(
                    array(
                        'field_name' => $field_name,
                        'field_desc' => $field_desc,
                        'client_id'  => $client_id,
                    )
                );

                if ( $field ) {
                    $return['success'] = true;
                } else {
                    $return['error'] = esc_html__( 'Undefined error occurred. Please try again.', 'mainwp' );
                }
            }
        }
        echo wp_json_encode( $return );
        exit;
    }

        /**
         * Method save_note()
         *
         * Save Client Note.
         */
    public static function save_note() {
        if ( isset( $_POST['clientid'] ) && ! empty( $_POST['clientid'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $note     = isset( $_POST['note'] ) ? wp_unslash( $_POST['note'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $esc_note = MainWP_Utility::esc_content( $note );
            $update   = array(
                'client_id' => intval( $_POST['clientid'] ), // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                'note'      => $esc_note,
            );
            MainWP_DB_Client::instance()->update_client( $update );
            die( wp_json_encode( array( 'result' => 'SUCCESS' ) ) );
        }
        die( wp_json_encode( array( 'undefined_error' => true ) ) );
    }
}
