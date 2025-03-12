<?php
/**
 * MainWP REST API page
 *
 * This Class handles building/Managing the
 * REST API MainWP DashboardPage & all SubPages.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Rest_Api_Page
 *
 * @package MainWP\Dashboard
 */
class MainWP_Rest_Api_Page { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.nore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.nore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.nore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Protected static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    protected static $instance = null;

    /**
     * Public static varable to hold Subpages information.
     *
     * @var array $subPages
     */
    public static $subPages;

    /**
     * Get Class Name
     *
     * @return __CLASS__
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Return the single instance of the class.
     *
     * @return mixed $instance The single instance of the class.
     */
    public static function get_instance() {
        if ( is_null( static::$instance ) ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /** Instantiate Hooks for the REST API Page. */
    public static function init() {
        /**
         * Renders the REST API page header via the 'mainwp_pageheader_restapi' action.
         *
         * This hook is normally used in the same context of 'mainwp_getsubpages_restapi'
         */
        add_action( 'mainwp-pageheader-restapi', array( static::get_class_name(), 'render_header' ) );

        /**
         * Renders the REST API page footer via the 'mainwp-pagefooter-restapi' action.
         *
         * This hook is normally used in the same context of 'mainwp-getsubpages-restapi'
         */
        add_action( 'mainwp-pagefooter-restapi', array( static::get_class_name(), 'render_footer' ) );

        add_action( 'mainwp_help_sidebar_content', array( static::get_class_name(), 'mainwp_help_content' ) );

        add_action( 'admin_init', array( static::get_instance(), 'admin_init' ) );
    }

    /** Run the export_sites method that exports the Child Sites .csv file */
    public function admin_init() {
        MainWP_Post_Handler::instance()->add_action( 'mainwp_rest_api_remove_keys', array( $this, 'ajax_rest_api_remove_keys' ) );
        $this->handle_rest_api_add_new();
        $this->handle_rest_api_edit();
    }


    /**
     * Instantiate the REST API Menu.
     *
     * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
     */
    public static function init_menu() {
        add_submenu_page(
            'mainwp_tab',
            esc_html__( 'REST API', 'mainwp' ),
            ' <span id="mainwp-rest-api">' . esc_html__( 'REST API', 'mainwp' ) . '</span>',
            'read',
            'RESTAPI',
            array(
                static::get_class_name(),
                'render_all_api_keys',
            )
        );

        if ( ! MainWP_Menu::is_disable_menu_item( 3, 'AddApiKeys' ) ) {
            add_submenu_page(
                'mainwp_tab',
                esc_html__( 'Add API Keys', 'mainwp' ),
                ' <div class="mainwp-hidden">' . esc_html__( 'Add API Keys', 'mainwp' ) . '</div>',
                'read',
                'AddApiKeys',
                array(
                    static::get_class_name(),
                    'render_rest_api_setings',
                )
            );
        }

        /**
         * REST API Subpages
         *
         * Filters subpages for the REST API page.
         *
         * @since Unknown
         */
        static::$subPages = apply_filters( 'mainwp_getsubpages_restapi', array() );

        if ( isset( static::$subPages ) && is_array( static::$subPages ) ) {
            foreach ( static::$subPages as $subPage ) {
                if ( MainWP_Menu::is_disable_menu_item( 3, 'RESTAPI' . $subPage['slug'] ) ) {
                    continue;
                }
                add_submenu_page( 'mainwp_tab', $subPage['title'], '<div class="mainwp-hidden">' . $subPage['title'] . '</div>', 'read', 'RESTAPI' . $subPage['slug'], $subPage['callback'] );
            }
        }

        static::init_left_menu( static::$subPages );
    }

    /**
     * Instantiate REST API SubPages Menu.
     *
     * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
     */
    public static function init_subpages_menu() {
        ?>
        <div id="menu-mainwp-RESTAPI" class="mainwp-submenu-wrapper">
            <div class="wp-submenu sub-open" style="">
                <div class="mainwp_boxout">
                    <div class="mainwp_boxoutin"></div>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=RESTAPI' ) ); ?>" class="mainwp-submenu"><?php esc_html_e( 'REST API', 'mainwp' ); ?></a>
                    <?php
                    if ( isset( static::$subPages ) && is_array( static::$subPages ) && ! empty( static::$subPages ) ) {
                        foreach ( static::$subPages as $subPage ) {
                            if ( MainWP_Menu::is_disable_menu_item( 3, 'RESTAPI' . $subPage['slug'] ) ) {
                                continue;
                            }
                            ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=RESTAPI' . $subPage['slug'] ) ); ?>" class="mainwp-submenu"><?php echo esc_html( $subPage['title'] ); ?></a>
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
     * Instantiate left menu
     *
     * REST API Page & SubPage link data.
     *
     * @param array $subPages SubPages Array.
     */
    public static function init_left_menu( $subPages = array() ) {

        MainWP_Menu::add_left_menu(
            array(
                'title'      => esc_html__( 'REST API', 'mainwp' ),
                'parent_key' => 'mainwp_tab',
                'slug'       => 'RESTAPI',
                'href'       => 'admin.php?page=RESTAPI',
                'icon'       => '<div class="mainwp-api-icon">API</div>',
            ),
            0
        );

        $init_sub_subleftmenu = array(
            array(
                'title'      => esc_html__( 'Manage API Keys', 'mainwp' ),
                'parent_key' => 'RESTAPI',
                'href'       => 'admin.php?page=RESTAPI',
                'slug'       => 'RESTAPI',
                'right'      => 'manage_restapi',
            ),

            array(
                'title'      => esc_html__( 'Add API Keys', 'mainwp' ),
                'parent_key' => 'RESTAPI',
                'href'       => 'admin.php?page=AddApiKeys',
                'slug'       => 'AddApiKeys',
                'right'      => '',
            ),
        );

        MainWP_Menu::init_subpages_left_menu( $subPages, $init_sub_subleftmenu, 'RESTAPI', 'RESTAPI' );
        foreach ( $init_sub_subleftmenu as $item ) {
            if ( MainWP_Menu::is_disable_menu_item( 3, $item['slug'] ) ) {
                continue;
            }

            MainWP_Menu::add_left_menu( $item, 2 );
        }
    }

    /**
     * Method handle_rest_api_add_new()
     *
     * Handle rest api settings
     */
    public function handle_rest_api_add_new() { // phpcs:ignore -- NOSONAR - complex.
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $submit = false;

        if ( isset( $_POST['submit'] ) && isset( $_GET['page'] ) && 'AddApiKeys' === $_GET['page'] ) {
            $submit = true;
        }

        if ( $submit && isset( $_POST['wp_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['wp_nonce'] ), 'RESTAPI' ) ) {
            $all_keys = static::check_rest_api_updates();

            if ( ! is_array( $all_keys ) ) {
                $all_keys = array();
            }

            $consumer_key    = isset( $_POST['mainwp_consumer_key'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_consumer_key'] ) ) : '';
            $consumer_secret = isset( $_POST['mainwp_consumer_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_consumer_secret'] ) ) : '';
            $desc            = isset( $_POST['mainwp_rest_add_api_key_desc'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_rest_add_api_key_desc'] ) ) : '';
            $enabled         = ! empty( $_POST['mainwp_enable_rest_api'] ) ? 1 : 0;
            $pers            = ! empty( $_POST['mainwp_rest_api_key_edit_pers'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_rest_api_key_edit_pers'] ) ) : '';

            // hash the password.
            $consumer_key_hashed    = wp_hash_password( $consumer_key );
            $consumer_secret_hashed = wp_hash_password( $consumer_secret );

            if ( ! empty( $_POST['mainwp_rest_api_keys_compatible_v1'] ) ) {
                $all_keys[ $consumer_key ] = array(
                    'ck_hashed' => $consumer_key_hashed,
                    'cs'        => $consumer_secret_hashed,
                    'desc'      => $desc,
                    'enabled'   => $enabled,
                    'perms'     => $pers,
                );
                // store the data.
                MainWP_Utility::update_option( 'mainwp_rest_api_keys', $all_keys );
            }

            // compatible with version 2.
            $scope = 'read';
            if ( ! empty( $pers ) ) {
                $pers_list = explode( ',', $pers );
                if ( in_array( 'w', $pers_list ) && in_array( 'd', $pers_list ) ) {
                    $scope = 'read_write';
                } elseif ( in_array( 'w', $pers_list ) ) {
                    $scope = 'write';
                }
            }
            MainWP_DB::instance()->insert_rest_api_key( $consumer_key, $consumer_secret, $scope, $desc, $enabled );
            // end.
            wp_safe_redirect( admin_url( 'admin.php?page=RESTAPI&message=created' ) ); //phpcs:ignore -- ok.
            exit();
        }
        // phpcs:enable
    }

    /**
     * Method handle_rest_api_edit()
     *
     * Handle rest api settings
     */
    public function handle_rest_api_edit() { // phpcs:ignore -- NOSONAR - complex.

        $edit_id = isset( $_POST['editkey_id'] ) ? sanitize_text_field( wp_unslash( $_POST['editkey_id'] ) ) : false;

        if ( isset( $_POST['submit'] ) && ! empty( $edit_id ) && isset( $_POST['edit_key_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['edit_key_nonce'] ), 'edit-key-nonce-' . $edit_id ) ) {

            $save    = false;
            $updated = false;
            if ( ! empty( $edit_id ) ) {
                if ( ! empty( $_POST['rest_v2_edit'] ) ) {
                    $key_id  = intval( $edit_id );
                    $desc    = isset( $_POST['mainwp_rest_api_key_desc'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_rest_api_key_desc'] ) ) : '';
                    $enabled = ! empty( $_POST['mainwp_enable_rest_api'] ) ? 1 : 0;
                    $pers    = ! empty( $_POST['mainwp_rest_api_key_edit_pers'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_rest_api_key_edit_pers'] ) ) : '';
                    $scope   = 'read';
                    if ( ! empty( $pers ) ) {
                        $pers_list = explode( ',', $pers );
                        if ( in_array( 'w', $pers_list ) && in_array( 'r', $pers_list ) ) {
                            $scope = 'read_write';
                        } elseif ( in_array( 'w', $pers_list ) ) {
                            $scope = 'write';
                        }
                    }

                    MainWP_DB::instance()->update_rest_api_key( $key_id, $scope, $desc, $enabled );

                    $updated = true;
                    $save    = true;
                } else {
                    $edit_id  = sanitize_text_field( $edit_id );
                    $all_keys = get_option( 'mainwp_rest_api_keys', false );
                    if ( is_array( $all_keys ) && isset( $all_keys[ $edit_id ] ) ) {
                        $item = $all_keys[ $edit_id ];
                        if ( is_array( $item ) && isset( $item['cs'] ) ) {
                            $item['desc']    = isset( $_POST['mainwp_rest_api_key_desc'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_rest_api_key_desc'] ) ) : '';
                            $item['enabled'] = ! empty( $_POST['mainwp_enable_rest_api'] ) ? 1 : 0;
                            $item['perms']   = ! empty( $_POST['mainwp_rest_api_key_edit_pers'] ) ? sanitize_text_field( wp_unslash( $_POST['mainwp_rest_api_key_edit_pers'] ) ) : '';

                            $all_keys[ $edit_id ] = $item;
                            $updated              = true;
                            $save                 = true;
                        } else {
                            unset( $all_keys[ $edit_id ] ); // delete incorrect key.
                            $save = true;
                        }

                        if ( $save ) {
                            MainWP_Utility::update_option( 'mainwp_rest_api_keys', $all_keys );
                        }
                    }
                }
            }

            $msg = '';
            if ( $updated ) {
                $msg = '&message=saved';
            }
            wp_safe_redirect( admin_url( 'admin.php?page=RESTAPI' . $msg ) ); //phpcs:ignore -- ok.
            exit();
        }
    }

    /**
     * Method ajax_rest_api_remove_keys()
     *
     * Remove API Key.
     */
    public function ajax_rest_api_remove_keys() { //phpcs:ignore -- NOSONAR - complex.
        MainWP_Post_Handler::instance()->check_security( 'mainwp_rest_api_remove_keys' );
        $ret         = array( 'success' => false );
        $cons_key_id = isset( $_POST['keyId'] ) ? urldecode( wp_unslash( $_POST['keyId'] ) ) : false; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $is_v2       = isset( $_POST['api_ver'] ) && 'v2' === wp_unslash( $_POST['api_ver'] ) ? true : false; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if ( ! empty( $cons_key_id ) ) {
            if ( $is_v2 ) {
                MainWP_DB::instance()->remove_rest_api_key( $cons_key_id );
            } else {
                $save     = false;
                $all_keys = get_option( 'mainwp_rest_api_keys', false );
                if ( is_array( $all_keys ) && isset( $all_keys[ $cons_key_id ] ) ) {
                    $item = $all_keys[ $cons_key_id ];
                    if ( is_array( $item ) && isset( $item['cs'] ) ) {
                        unset( $all_keys[ $cons_key_id ] ); // delete key.
                        $save = true;
                    }
                }
                if ( $save ) {
                    MainWP_Utility::update_option( 'mainwp_rest_api_keys', $all_keys );
                }
            }
            $ret['success'] = 'SUCCESS';
            $ret['result']  = esc_html__( 'REST API Key deleted successfully.', 'mainwp' );
        } else {
            $ret['error'] = esc_html__( 'REST API Key ID empty.', 'mainwp' );
        }
        echo wp_json_encode( $ret );
        exit;
    }


    /**
     * Render Page Header.
     *
     * @param string $shownPage The page slug shown at this moment.
     *
     * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
     * @uses \MainWP\Dashboard\MainWP_UI::render_top_header()
     * @uses \MainWP\Dashboard\MainWP_UI::render_page_navigation()
     */
    public static function render_header( $shownPage = '' ) { // phpcs:ignore -- NOSONAR - complex.

        $params = array(
            'title' => esc_html__( 'REST API', 'mainwp' ),
        );

        MainWP_UI::render_top_header( $params );

        $renderItems = array();

        if ( \mainwp_current_user_can( 'dashboard', 'manage_restapi' ) ) {
            $renderItems[] = array(
                'title'  => esc_html__( 'Manage API Keys', 'mainwp' ),
                'href'   => 'admin.php?page=RESTAPI',
                'active' => ( '' === $shownPage ) ? true : false,
            );
        }

        if ( ! MainWP_Menu::is_disable_menu_item( 3, 'AddApiKeys' ) ) {
            if ( isset( $_GET['editkey'] ) && ! empty( $_GET['editkey'] ) && isset( $_GET['_opennonce'] ) && wp_verify_nonce( sanitize_key( $_GET['_opennonce'] ), 'mainwp-admin-nonce' ) ) {
                // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                $ver           = isset( $_GET['rest_ver'] ) && ! empty( $_GET['rest_ver'] ) ? '&rest_ver=' . intval( $_GET['rest_ver'] ) : '';
                $renderItems[] = array(
                    'title'  => esc_html__( 'Edit API Keys', 'mainwp' ),
                    'href'   => 'admin.php?page=AddApiKeys&editkey=' . esc_url( wp_unslash( $_GET['editkey'] ) ) . $ver . '&_opennonce=' . esc_html( wp_create_nonce( 'mainwp-admin-nonce' ) ), // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                    'active' => ( 'Edit' === $shownPage ) ? true : false,
                );
            }
            $renderItems[] = array(
                'title'  => esc_html__( 'Add API Keys', 'mainwp' ),
                'href'   => 'admin.php?page=AddApiKeys',
                'active' => ( 'Settings' === $shownPage ) ? true : false,
            );
        }

        if ( isset( static::$subPages ) && is_array( static::$subPages ) ) {
            foreach ( static::$subPages as $subPage ) {
                if ( MainWP_Menu::is_disable_menu_item( 3, 'RESTAPI' . $subPage['slug'] ) ) {
                    continue;
                }
                $item           = array();
                $item['title']  = $subPage['title'];
                $item['href']   = 'admin.php?page=RESTAPI' . $subPage['slug'];
                $item['active'] = ( $subPage['slug'] === $shownPage ) ? true : false;
                $renderItems[]  = $item;
            }
        }

        MainWP_UI::render_page_navigation( $renderItems );
    }

    /**
     * Close the HTML container.
     */
    public static function render_footer() {
        echo '</div>';
    }

    /**
     * Method render_api_keys_v1_table.
     *
     * @return void
     */
    public static function render_api_keys_v1_table() { //phpcs:ignore -- NOSONAR - complex.
        $all_keys = static::check_rest_api_updates();
        if ( ! is_array( $all_keys ) ) {
            $all_keys = array();
        }

        if ( ! empty( $all_keys ) ) {
            ?>
        <h2 class="ui dividing header">
            <?php esc_html_e( 'MainWP REST API v1 API Keys (Legacy)', 'mainwp' ); ?>
            <div class="sub header"><?php esc_html_e( 'Legacy API keys for older integrations. We recommend switching to v2 for better security and performance.', 'mainwp' ); ?></div>
        </h2>
        <table id="mainwp-rest-api-keys-table" class="ui unstackable table">
            <thead>
                <tr>
                    <th scope="col" class="no-sort collapsing check-column"><span class="ui checkbox"><input aria-label="<?php esc_attr_e( 'Select all REST API keys', 'mainwp' ); ?>" id="cb-select-all-top" type="checkbox" /></span></th>
                    <th scope="col" class="collapsing"><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
                    <th scope="col" ><?php esc_html_e( 'API Key', 'mainwp' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Permissions', 'mainwp' ); ?></th>
                    <th scope="col" class="no-sort collapsing"><?php esc_html_e( 'Consumer key ending in', 'mainwp' ); ?></th>
                    <th scope="col" class="no-sort collapsing"></th>
                </tr>
            </thead>
            <tbody id="mainwp-rest-api-body-table" class="mainwp-rest-api-body-table-manage">
                <?php
                foreach ( $all_keys as $ck => $item ) {
                    if ( ! is_array( $item ) ) {
                        continue;
                    }
                    $ending      = substr( $ck, -8 );
                    $desc        = isset( $item['desc'] ) && ! empty( $item['desc'] ) ? $item['desc'] : 'N/A';
                    $enabled     = isset( $item['enabled'] ) && ! empty( $item['enabled'] ) ? true : false;
                    $endcoded_ck = rawurlencode( $ck );

                    $pers_codes = '';
                    if ( ! isset( $item['perms'] ) ) {
                        $pers_codes = 'r,w,d'; // to compatible.
                    } elseif ( ! empty( $item['perms'] ) ) {
                        $pers_codes = $item['perms'];
                    }

                    $pers_names = array();
                    if ( ! empty( $pers_codes ) && is_string( $pers_codes ) ) {
                        $pers_codes = explode( ',', $pers_codes );
                        if ( is_array( $pers_codes ) ) {
                            if ( in_array( 'r', $pers_codes ) ) {
                                $pers_names[] = esc_html__( 'Read', 'mainwp' );
                            }
                            if ( in_array( 'w', $pers_codes ) ) {
                                $pers_names[] = esc_html__( 'Write', 'mainwp' );
                            }
                            if ( in_array( 'd', $pers_codes ) ) {
                                $pers_names[] = esc_html__( 'Delete', 'mainwp' );
                            }
                        }
                    }

                    ?>
                    <tr key-ck-id="<?php echo esc_html( $endcoded_ck ); ?>">
                        <td class="check-column">
                            <div class="ui checkbox">
                                <input type="checkbox" aria-label="<?php echo esc_attr__( 'Select API key described as: ', 'mainwp' ) . esc_html( $desc ); ?>" value="<?php echo esc_html( $endcoded_ck ); ?>" name=""/>
                            </div>
                        </td>
                        <td><?php echo $enabled ? '<span class="ui green fluid label">' . esc_html__( 'Enabled', 'mainwp' ) . '</span>' : '<span class="ui gray fluid label">' . esc_html__( 'Disabled', 'mainwp' ) . '</span>'; ?></td>
                        <td><a href="admin.php?page=AddApiKeys&editkey=<?php echo esc_html( $endcoded_ck ); ?>"><?php echo esc_html( $desc ); ?></a></td>
                        <td><?php echo ! empty( $pers_names ) ? implode( ', ', $pers_names ) : 'N/A'; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                        <td><code><?php echo esc_html( '...' . $ending ); // phpcs:ignore WordPress.Security.EscapeOutput ?></code></td>
                        <td class="right aligned">
                            <div class="ui right pointing dropdown" style="z-index:999">
                            <i class="ellipsis vertical icon"></i>
                                <div class="menu">
                                <a class="item" href="admin.php?page=AddApiKeys&editkey=<?php echo esc_html( $endcoded_ck ); ?>&_opennonce=<?php echo esc_html( wp_create_nonce( 'mainwp-admin-nonce' ) ); ?>"><i class="pen icon"></i><?php esc_html_e( 'Edit', 'mainwp' ); ?></a>
                                <a class="item" href="javascript:void(0)" onclick="mainwp_restapi_remove_key_confirm(jQuery(this).closest('tr').find('.check-column INPUT:checkbox'));" ><i class="trash icon"></i><?php esc_html_e( 'Delete', 'mainwp' ); ?></a>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
            <script type="text/javascript">
            var responsive = true;
            if( jQuery( window ).width() > 1140 ) {
                responsive = false;
            }
            jQuery( document ).ready( function( $ ) {
                try {
                    jQuery( '#mainwp-rest-api-keys-table' ).DataTable( {
                        "lengthMenu": [ [5, 10, 25, 50, 100, -1], [5, 10, 25, 50, 100, "All"] ],
                        "stateSave" : true,
                        "order"     : [ [1, 'asc'] ],
                        "columnDefs": [ {
                            "targets": 'no-sort',
                            "orderable": false
                        } ],
                        "responsive": responsive,
                        "preDrawCallback": function() {
                            jQuery( '#mainwp-rest-api-keys-table .ui.dropdown' ).dropdown();
                            jQuery('#mainwp-rest-api-keys-table .ui.checkbox').checkbox();
                            mainwp_datatable_fix_menu_overflow('#mainwp-rest-api-keys-table', -70, 0);
                            mainwp_table_check_columns_init(); // ajax: to fix checkbox all.
                        }
                    } );
                    mainwp_datatable_fix_menu_overflow('#mainwp-rest-api-keys-table', -70, 0);
                } catch(err) {
                    // to fix js error.
                }
            });
            </script>
            <?php
        }
    }

    /** Render REST API SubPage */
    public static function render_api_keys_v2_table() { // phpcs:ignore -- NOSONAR - complex.
        $all_keys_v2 = MainWP_DB::instance()->get_rest_api_keys();
        ?>
        <table id="mainwp-rest-api-keys-v2-table" class="ui unstackable table">
            <thead>
                <tr>
                    <th scope="col" class="no-sort collapsing check-column"><span class="ui checkbox"><input aria-label="<?php esc_attr_e( 'Select all REST API keys', 'mainwp' ); ?>" id="cb-select-all-top" type="checkbox" /></span></th>
                    <th scope="col" class="collapsing"><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
                    <th scope="col" ><?php esc_html_e( 'API Key (v2)', 'mainwp' ); ?></th>
                    <th scope="col" class="collapsing"><?php esc_html_e( 'Permissions', 'mainwp' ); ?></th>
                    <th scope="col" class="no-sort collapsing"><?php esc_html_e( 'API key ending in', 'mainwp' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Last access', 'mainwp' ); ?></th>
                    <th scope="col" class="no-sort collapsing"></th>
                </tr>
                </thead>
                <tbody id="mainwp-rest-api-v2-body-table" class="mainwp-rest-api-body-table-manage">
                    <?php
                    if ( is_array( $all_keys_v2 ) ) {
                        foreach ( $all_keys_v2 as $item ) {
                            $ending  = $item->truncated_key;
                            $desc    = ! empty( $item->description ) ? esc_html( $item->description ) : 'N/A';
                            $enabled = $item->enabled ? true : false;
                            $key_id  = $item->key_id;

                            $pers_title = array();
                            $per        = $item->permissions;
                            if ( 'read' === $per ) {
                                $pers_title[] = esc_html__( 'Read', 'mainwp' );
                            }
                            if ( 'write' === $per ) {
                                $pers_title[] = esc_html__( 'Write', 'mainwp' );
                            }
                            if ( 'read_write' === $per ) {
                                $pers_title[] = esc_html__( 'Read', 'mainwp' );
                                $pers_title[] = esc_html__( 'Write', 'mainwp' );
                            }
                            ?>
                            <tr key-ck-id="<?php echo intval( $key_id ); ?>">
                                <td class="check-column">
                                    <div class="ui checkbox">
                                        <input type="checkbox" aria-label="<?php echo esc_attr__( 'Select API key described as: ', 'mainwp' ) . esc_html( $desc ); ?>" value="<?php echo intval( $key_id ); ?>" name=""/>
                                    </div>
                                </td>
                                <td><?php echo $enabled ? '<span class="ui green fluid label">' . esc_html__( 'Enabled', 'mainwp' ) . '</span>' : '<span class="ui gray fluid label">' . esc_html__( 'Disabled', 'mainwp' ) . '</span>'; ?></td>
                                <td><a href="admin.php?page=AddApiKeys&editkey=<?php echo intval( $key_id ); ?>&rest_ver=2&_opennonce=<?php echo esc_html( wp_create_nonce( 'mainwp-admin-nonce' ) ); ?>"><?php echo esc_html( $desc ); ?></a></td>
                                <td><?php echo ! empty( $pers_title ) ? implode( ', ', $pers_title ) : 'N/A'; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                                <td><code><?php echo esc_html( '...' . $ending ); // phpcs:ignore WordPress.Security.EscapeOutput ?></code></td>
                                <td data-order="<?php echo ! empty( $item->last_access ) ? strtotime( $item->last_access ) : 0; ?>"><?php echo ! empty( $item->last_access ) ? '<span data-tooltip="' . MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( strtotime( $item->last_access ) ) ) . '" data-position="left center" data-inverted="">' . MainWP_Utility::time_elapsed_string( strtotime( $item->last_access ) ) . '</span>' : 'N/A'; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                                <td class="right aligned">
                                    <div class="ui right pointing dropdown" style="z-index:999">
                                    <i class="ellipsis vertical icon"></i>
                                        <div class="menu">
                                            <a class="item" href="admin.php?page=AddApiKeys&editkey=<?php echo intval( $key_id ); ?>&rest_ver=2&_opennonce=<?php echo esc_html( wp_create_nonce( 'mainwp-admin-nonce' ) ); ?>"><?php esc_html_e( 'Edit', 'mainwp' ); ?></a>
                                            <a class="item" href="javascript:void(0)" onclick="mainwp_restapi_remove_key_confirm(jQuery(this).closest('tr').find('.check-column INPUT:checkbox'));" ><?php esc_html_e( 'Delete', 'mainwp' ); ?></a>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php
                        }
                    }
                    ?>
                </tbody>
            </table>
        <script type="text/javascript">
            var responsive = true;
            if( jQuery( window ).width() > 1140 ) {
                responsive = false;
            }
            jQuery( document ).ready( function( $ ) {
                try {
                    jQuery( '#mainwp-rest-api-keys-v2-table' ).DataTable( {
                        "lengthMenu": [ [5, 10, 25, 50, 100, -1], [5, 10, 25, 50, 100, "All"] ],
                        "stateSave" : true,
                        "order"     : [ [1, 'asc'] ],
                        "columnDefs": [ {
                            "targets": 'no-sort',
                            "orderable": false
                        } ],
                        "responsive": responsive,
                        "preDrawCallback": function() {
                            jQuery( '#mainwp-rest-api-keys-v2-table .ui.dropdown' ).dropdown();
                            jQuery('#mainwp-rest-api-keys-v2-table .ui.checkbox').checkbox();
                            mainwp_datatable_fix_menu_overflow('#mainwp-rest-api-keys-v2-table', -70, 0);
                            mainwp_table_check_columns_init(); // ajax: to fix checkbox all.
                        }
                    } );
                    mainwp_datatable_fix_menu_overflow('#mainwp-rest-api-keys-v2-table', -70, 0);
                } catch(err) {
                    // to fix js error.
                }
            });
        </script>

        <?php
    }


    /** Render REST API SubPage */
    public static function render_all_api_keys() { // phpcs:ignore -- NOSONAR - complex.
        if ( ! \mainwp_current_user_can( 'dashboard', 'manage_dashboard_restapi' ) ) {
            \mainwp_do_not_have_permissions( esc_html__( 'manage dashboard REST API', 'mainwp' ) );
            return;
        }
        static::render_header();
        static::render_table_top();
        if ( ! static::check_rest_api_enabled() ) {
            ?>
            <div class="ui message yellow"><?php printf( esc_html__( 'It seems the WordPress REST API is currently disabled on your site. MainWP REST API requires the WordPress REST API to function properly. Please enable it to ensure smooth operation. Need help? %sClick here for a guide%s.', 'mainwp' ), '<a href="https://mainwp.com/kb/wordpress-rest-api-does-not-respond/" target="_blank">', '</a> <i class="external alternate icon"></i>' ); ?></div>
            <?php
        }
        ?>
        <div id="mainwp-rest-api-keys" class="ui segment">
            <div class="ui message" id="mainwp-message-zone-apikeys" style="display:none;"></div>
            <?php static::show_messages(); ?>
            <h2 class="ui dividing header">
                <?php esc_html_e( 'MainWP REST API v2 API Keys', 'mainwp' ); ?>
                <div class="sub header"><?php esc_html_e( 'The latest and most secure version of the MainWP REST API. Use these keys for new integrations.', 'mainwp' ); ?></div>
            </h2>
            <?php static::render_api_keys_v2_table(); ?>

            <?php static::render_api_keys_v1_table(); ?>
        </div>
        <?php
        static::render_footer();
    }


    /**
     * Render table top.
     */
    public static function render_table_top() {
        ?>
        <div class="mainwp-actions-bar">
            <div class="ui grid">
                <div class="equal width row ui mini form">
                    <div class="middle aligned column">
                        <div id="mainwp-rest-api-bulk-actions-menu" class="ui selection dropdown">
                            <div class="default text"><?php esc_html_e( 'Bulk actions', 'mainwp' ); ?></div>
                            <i class="dropdown icon"></i>
                            <div class="menu">
                            <div class="item" data-value="delete"><?php esc_html_e( 'Delete', 'mainwp' ); ?></div>
                            </div>
                        </div>
                        <button class="ui mini basic button" id="mainwp-do-rest-api-bulk-actions"><?php esc_html_e( 'Apply', 'mainwp' ); ?></button>
                    </div>
                    <div class="right aligned middle aligned column">
                        <a href="admin.php?page=AddApiKeys" class="ui mini green button"><?php esc_html_e( 'Create New API Key', 'mainwp' ); ?></a>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }


    /**
     * Method check_rest_api_updates().
     *
     * To Checks for updating REST API keys.
     */
    public static function check_rest_api_updates() {
        $all_keys = get_option( 'mainwp_rest_api_keys', false );
        $cs       = get_option( 'mainwp_rest_api_consumer_key', false );

        // to compatible.
        if ( false === $all_keys || false !== $cs ) {
            if ( ! is_array( $all_keys ) ) {
                $all_keys = array();
            }
            if ( ! empty( $cs ) ) {
                $all_keys[ $cs ] = array(
                    'ck'   => get_option( 'mainwp_rest_api_consumer_key', '' ),
                    'cs'   => get_option( 'mainwp_rest_api_consumer_secret', '' ),
                    'desc' => '',
                );
            }
            MainWP_Utility::update_option( 'mainwp_rest_api_keys', $all_keys );

            if ( false !== $cs ) {
                delete_option( 'mainwp_rest_api_consumer_key' );
                delete_option( 'mainwp_rest_api_consumer_secret' );
                delete_option( 'mainwp_enable_rest_api' );
            }
        }
        // end.
        return $all_keys;
    }


    /**
     * Method show_messages().
     *
     * Show actions messages.
     */
    public static function show_messages() {
        $msg = '';
        if ( isset( $_GET['message'] ) && ( 'saved' === $_GET['message'] || 'created' === $_GET['message'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $msg = esc_html__( 'API Key have been saved successfully!', 'mainwp' );
        }
        if ( ! empty( $msg ) ) {
            ?>
            <div class="ui green message"><i class="close icon"></i><?php echo esc_html( $msg ); ?></div>
            <?php
        }
    }

    /** Render REST API SubPage */
    public static function render_rest_api_setings() { //phpcs:ignore -- NOSONAR - complex.
        if ( ! \mainwp_current_user_can( 'dashboard', 'manage_dashboard_restapi' ) ) {
            \mainwp_do_not_have_permissions( esc_html__( 'manage dashboard REST API', 'mainwp' ) );
            return;
        }

        $edit_key = false;
        if ( isset( $_GET['editkey'] ) && ! empty( $_GET['editkey'] ) && isset( $_GET['_opennonce'] ) && wp_verify_nonce( sanitize_key( $_GET['_opennonce'] ), 'mainwp-admin-nonce' ) ) {
            $edit_key = wp_unslash( $_GET['editkey'] ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        }

        $edit_item = array();
        if ( false !== $edit_key ) {
            if ( empty( $_GET['rest_ver'] ) ) {
                $edit_key = urldecode( $edit_key );
                $all_keys = get_option( 'mainwp_rest_api_keys', false );
                if ( ! is_array( $all_keys ) || ! isset( $all_keys[ $edit_key ] ) ) {
                    $edit_key = false;
                } else {
                    $edit_item = $all_keys[ $edit_key ];
                }

                if ( ! empty( $edit_item ) ) {
                    static::render_rest_api_edit( $edit_key, $edit_item );
                    return;
                }
            } else {
                $key_id    = intval( $edit_key );
                $edit_item = MainWP_DB::instance()->get_rest_api_key_by( $key_id );
                if ( ! empty( $edit_item ) ) {
                    static::render_rest_api_v2_edit( $edit_item );
                    return;
                }
            }
        }

        // we need to generate a consumer key and secret and return the result and save it into the database.

        $_consumer_key    = static::mainwp_generate_rand_hash();
        $_consumer_secret = static::mainwp_generate_rand_hash();

        $consumer_key    = 'ck_' . $_consumer_key;
        $consumer_secret = 'cs_' . $_consumer_secret;

        static::render_header( 'Settings' );

        ?>
        <div id="rest-api-settings" class="ui segment">
            <h2 class="ui dividing header">
                <?php esc_html_e( 'MainWP REST API Credentials', 'mainwp' ); ?>
                <div class="sub header"><?php esc_html_e( 'Generate, manage, and revoke API keys to securely connect with the MainWP REST API. Ensure you\'re using the correct version for your integrations.', 'mainwp' ); ?></div>
            </h2>
            <div id="api-credentials-created" class="ui green message">
                <strong><?php esc_html_e( 'API credentials have been successfully generated.', 'mainwp' ); ?></strong><br/>
                <?php esc_html_e( 'Please copy the consumer key and secret now as after you leave this page the credentials will no longer be accessible. Use the Description field for easier Key management when needed.', 'mainwp' ); ?>
            </div>

            <?php static::show_messages(); ?>
            <?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-rest-api-info-message' ) ) : ?>
                <div class="ui info message">
                    <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-rest-api-info-message"></i>
                    <?php printf( esc_html__( 'Enable the MainWP REST API functionality and generate API credentials. Check this %1$shelp document%2$s to see all available endpoints.', 'mainwp' ), '<a href="https://mainwp.dev/rest-api/" target="_blank">', '</a>' ); ?>
                </div>
            <?php endif; ?>

            <div class="ui info message">
                <?php esc_html_e( 'The API Key (Bearer) authentication is only compatible with the MainWP REST API v2', 'mainwp' ); ?>
                <?php esc_html_e( 'If you are working with the MainWP REST API v1 (Legacy), click on the "Show Legacy API Credentials" button to obtain your Consumer Key and Consumer Secret.', 'mainwp' ); ?>
            </div>

            <div class="ui form">
                <form method="POST" action="">
                    <?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
                    <input type="hidden" name="wp_nonce" value="<?php echo esc_attr( wp_create_nonce( 'RESTAPI' ) ); ?>" />
                    <?php
                    /**
                     * Action: rest_api_form_top
                     *
                     * Fires at the top of REST API form.
                     *
                     * @since 4.1
                     */
                    do_action( 'rest_api_form_top' );
                    ?>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'Enable API key', 'mainwp' ); ?></label>
                        <div class="ten wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'If enabled, the REST API will be activated.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
                            <input type="checkbox" name="mainwp_enable_rest_api" id="mainwp_enable_rest_api" checked="true" aria-label="<?php esc_attr_e( 'Enable REST API key', 'mainwp' ); ?>"/>
                        </div>
                    </div>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'API key name', 'mainwp' ); ?></label>
                        <div class="five wide column" data-tooltip="<?php esc_attr_e( 'Name your API Key.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
                            <input type="text" name="mainwp_rest_add_api_key_desc" id="mainwp_rest_add_api_key_desc" value="" aria-label="<?php esc_attr_e( 'API key name.', 'mainwp' ); ?>"/>
                        </div>
                    </div>

                    <?php
                    $token = $_consumer_secret . '==' . $_consumer_key;
                    ?>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'API key (Bearer token)', 'mainwp' ); ?></label>
                        <div class="five wide column">
                            <input type="text" name="mainwp_rest_token" id="mainwp_rest_token" value="<?php echo esc_html( $token ); ?>" readonly aria-label="<?php esc_attr_e( 'API Token', 'mainwp' ); ?>"/>
                        </div>
                        <div class="five wide column">
                            <input id="mainwp_rest_token_clipboard_button" style="display:nonce;" type="button" name="" class="ui green basic button copy-to-clipboard" value="<?php esc_attr_e( 'Copy to Clipboard', 'mainwp' ); ?>">
                        </div>
                    </div>

                    <?php $init_pers = 'r,w,d'; ?>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'Permissions', 'mainwp' ); ?></label>
                        <div class="five wide column">
                            <div class="ui multiple selection dropdown" init-value="<?php echo esc_attr( $init_pers ); ?>">
                                <input name="mainwp_rest_api_key_edit_pers" value="" type="hidden">
                                <i class="dropdown icon"></i>
                                <div class="default text"><?php echo ( '' === $init_pers ) ? esc_html__( 'No Permissions selected.', 'mainwp' ) : ''; ?></div>
                                <div class="menu">
                                    <div class="item" data-value="r"><?php esc_html_e( 'Read', 'mainwp' ); ?></div>
                                    <div class="item" data-value="w"><?php esc_html_e( 'Write', 'mainwp' ); ?></div>
                                    <div class="item" data-value="d"><?php esc_html_e( 'Delete', 'mainwp' ); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'Enable MainWP REST API v1 Compatibility', 'mainwp' ); ?></label>
                        <div class="ten wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'If enabled, the credentials will be compatible with REST API v1.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
                            <input type="checkbox" name="mainwp_rest_api_keys_compatible_v1" id="mainwp_rest_api_keys_compatible_v1" aria-label="<?php esc_attr_e( 'If enabled, the credentials will be compatible with REST API v1.', 'mainwp' ); ?>"/>
                        </div>
                    </div>

                    <div id="mainwp-legacy-api-credentials" class="ui segment" style="display:none">
                        <h3 class="ui dividing header">
                            <?php esc_html_e( 'Legacy API Credentials', 'mainwp' ); ?>
                            <div class="sub header"><?php esc_html_e( 'Authentication compatible only with the MainWP REST API version 1!', 'mainwp' ); ?></div>
                        </h3>

                        <div class="ui grid field">
                            <label class="six wide column middle aligned"><?php esc_html_e( 'Consumer key', 'mainwp' ); ?></label>
                            <div class="five wide column">
                                <input type="text" name="mainwp_consumer_key" id="mainwp_consumer_key" value="<?php echo esc_html( $consumer_key ); ?>" readonly aria-label="<?php esc_attr_e( 'Consumer key', 'mainwp' ); ?>"/>
                            </div>
                            <div class="five wide column">
                                <input id="mainwp_consumer_key_clipboard_button" style="display:nonce;" type="button" name="" class="ui green basic button copy-to-clipboard" value="<?php esc_attr_e( 'Copy to Clipboard', 'mainwp' ); ?>">
                            </div>
                        </div>

                        <div class="ui grid field">
                            <label class="six wide column middle aligned"><?php esc_html_e( 'Consumer secret', 'mainwp' ); ?></label>
                            <div class="five wide column">
                                <input type="text" name="mainwp_consumer_secret" id="mainwp_consumer_secret" value="<?php echo esc_html( $consumer_secret ); ?>" readonly aria-label="<?php esc_attr_e( 'Consumer secret', 'mainwp' ); ?>"/>
                            </div>
                            <div class="five wide column">
                                <input id="mainwp_consumer_secret_clipboard_button" style="display:nonce;" type="button" name="" class="ui green basic button copy-to-clipboard" value="<?php esc_attr_e( 'Copy to Clipboard', 'mainwp' ); ?>">
                            </div>
                        </div>
                    </div>
                <?php
                /**
                 * Action: rest_api_form_bottom
                 *
                 * Fires at the bottom of REST API form.
                 *
                 * @since 4.1
                 */
                do_action( 'rest_api_form_bottom' );
                ?>
                <div class="ui divider"></div>
                <input type="submit" name="submit" id="submit-save-settings-button" class="ui green big button" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>"/>
                <div style="clear:both"></div>
            </form>
        </div>
    </div>
            <script type="text/javascript">
                jQuery(function($) {
                    //we are going to inject the values into the copy buttons to make things easier for people
                    $('#mainwp_consumer_key_clipboard_button').attr('data-clipboard-text', '<?php echo esc_html( $consumer_key ); ?>');
                    $('#mainwp_consumer_secret_clipboard_button').attr('data-clipboard-text', '<?php echo esc_html( $consumer_secret ); ?>');
                    $('#mainwp_rest_token_clipboard_button').attr('data-clipboard-text', '<?php echo esc_html( $token ); ?>'); //phpcs:ignore -- NOSONAR - token value ok.
                    //initiate clipboard
                    new ClipboardJS('.copy-to-clipboard');
                    //show copy to clipboard buttons
                    $('.copy-to-clipboard').show();

                    $('#mainwp_rest_api_keys_compatible_v1').on('change', function() {
                        $('#mainwp-legacy-api-credentials').toggle();
                    });
                });


            </script>
        <?php

        static::render_footer( 'Settings' );
    }

    /**
     * Method mainwp_generate_rand_hash()
     *
     * Generates a random hash to be used when generating the consumer key and secret.
     *
     * @return string Returns random string.
     */
    public static function mainwp_generate_rand_hash() {
        if ( ! function_exists( 'openssl_random_pseudo_bytes' ) ) {
            return sha1( wp_rand() ); // NOSONAR - safe for keys.
        }

        return bin2hex( openssl_random_pseudo_bytes( 20 ) ); // @codingStandardsIgnoreLine
    }

    /**
     * Method render_rest_api_v2_edit().
     *
     * Render REST API edit screen.
     *
     * @param array $item The Key edit.
     */
    public static function render_rest_api_v2_edit( $item ) { //phpcs:ignore -- NOSONAR - complex.
        $keyid     = $item->key_id;
        $edit_desc = $item->description;
        $enabled   = $item->enabled ? true : false;
        $ending    = $item->truncated_key;
        $perms     = $item->permissions;

        if ( 'read' === $perms ) {
            $init_pers = 'r';
        }
        if ( 'write' === $perms ) {
            $init_pers = 'w';
        }
        if ( 'read_write' === $perms ) {
            $init_pers = 'r,w';
        }
        static::render_header( 'Edit' );
        ?>
        <div id="rest-api-settings" class="ui segment">
            <div class="ui form">
                    <form method="POST" action="">
                        <?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
                        <input type="hidden" name="edit_key_nonce" value="<?php echo esc_attr( wp_create_nonce( 'edit-key-nonce-' . $keyid ) ); ?>" />
                        <input type="hidden" name="editkey_id" value="<?php echo esc_html( $keyid ); ?>" />
                        <input type="hidden" name="rest_v2_edit" value="1" />
                        <div class="ui grid field settings-field-indicator-wrapper <?php echo $item ? 'settings-field-indicator-edit-api-keys' : ''; ?>">
                            <label class="six wide column middle aligned">
                            <?php
                            if ( $item ) {
                                MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $enabled );
                            }
                            ?>
                            <?php esc_html_e( 'Enable REST API Key', 'mainwp' ); ?></label>
                            <div class="ten wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'If enabled, the REST API will be activated.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
                                <input type="checkbox" class="settings-field-value-change-handler" name="mainwp_enable_rest_api" id="mainwp_enable_rest_api" value="1" <?php echo $enabled ? 'checked="true"' : ''; ?> aria-label="<?php esc_attr_e( 'Enable REST API key', 'mainwp' ); ?>" />
                            </div>
                        </div>
                        <div class="ui grid field settings-field-indicator-wrapper <?php echo $item ? 'settings-field-indicator-edit-api-keys' : ''; ?>"">
                            <label class="six wide column middle aligned">
                            <?php
                            if ( $item ) {
                                MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $edit_desc );
                            }
                            ?>
                            <?php esc_html_e( 'Description', 'mainwp' ); ?></label>
                            <div class="five wide column">
                                <input type="text" class="settings-field-value-change-handler" name="mainwp_rest_api_key_desc" id="mainwp_rest_api_key_desc" value="<?php echo esc_html( $edit_desc ); ?>" aria-label="<?php esc_attr_e( 'Key description', 'mainwp' ); ?>"/>
                            </div>
                        </div>
                        <div class="ui grid field settings-field-indicator-wrapper <?php echo $item ? 'settings-field-indicator-edit-api-keys' : ''; ?>"">
                            <label class="six wide column middle aligned">
                            <?php
                            if ( $item ) {
                                MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $ending );
                            }
                            ?>
                            <?php esc_html_e( 'Consumer key ending in', 'mainwp' ); ?></label>
                            <div class="five wide column">
                                <div class="ui disabled input">
                                    <input type="text" class="settings-field-value-change-handler" value="<?php echo esc_attr( '...' . $ending ); ?>" aria-label="<?php esc_attr_e( 'Consumer key ending in...', 'mainwp' ); ?>"/>
                                </div>
                            </div>
                        </div>
                        <div class="ui grid field settings-field-indicator-wrapper <?php echo $item ? 'settings-field-indicator-edit-api-keys' : ''; ?>" default-indi-value="3">
                            <label class="six wide column middle aligned">
                            <?php
                            if ( $item ) {
                                $tmp     = explode( ',', $init_pers );
                                $visible = 2 !== count( $tmp );
                                MainWP_Settings_Indicator::render_indicator( '', '', $visible );
                            }
                            ?>
                            <?php esc_html_e( 'Permissions', 'mainwp' ); ?></label>
                            <div class="five wide column">
                                <div class="ui multiple selection dropdown" init-value="<?php echo esc_attr( $init_pers ); ?>">
                                    <input name="mainwp_rest_api_key_edit_pers" class="settings-field-value-change-handler" value="" type="hidden">
                                    <i class="dropdown icon"></i>
                                    <div class="default text"><?php echo ( '' === $init_pers ) ? esc_html__( 'No Permissions selected.', 'mainwp' ) : ''; ?></div>
                                    <div class="menu">
                                        <div class="item" data-value="r"><?php esc_html_e( 'Read', 'mainwp' ); ?></div>
                                        <div class="item" data-value="w"><?php esc_html_e( 'Write', 'mainwp' ); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="ui divider"></div>
                        <input type="submit" name="submit" id="submit" class="ui green big button" value="<?php esc_attr_e( 'Save API Key', 'mainwp' ); ?>"/>
                        <div style="clear:both"></div>
                    </form>
                </div>

        </div>
        <?php
        static::render_footer( 'Edit' );
    }


    /**
     * Method render_rest_api_edit().
     *
     * Render REST API edit screen.
     *
     * @param string $keyid Key ID edit.
     * @param array  $item The Key edit.
     */
    public static function render_rest_api_edit( $keyid, $item ) { //phpcs:ignore -- NOSONAR - complex.

        $edit_desc = is_array( $item ) && isset( $item['desc'] ) ? $item['desc'] : '';
        $enabled   = is_array( $item ) && isset( $item['enabled'] ) && ! empty( $item['enabled'] ) ? true : false;
        $ending    = substr( $keyid, -8 );

        $init_pers = '';
        if ( isset( $item['perms'] ) ) {
            $init_pers = $item['perms'];
        } else {
            $init_pers = 'r,w,d'; // to compatible.
        }

        static::render_header( 'Edit' );
        ?>
        <div id="rest-api-settings" class="ui segment">
            <div class="ui form">
                    <form method="POST" action="">
                        <?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
                        <input type="hidden" name="edit_key_nonce" value="<?php echo esc_attr( wp_create_nonce( 'edit-key-nonce-' . $keyid ) ); ?>" />
                        <input type="hidden" name="editkey_id" value="<?php echo esc_html( $keyid ); ?>" />
                        <div class="ui grid field settings-field-indicator-wrapper <?php echo $item ? 'settings-field-indicator-edit-api-keys' : ''; ?>">
                            <label class="six wide column middle aligned">
                            <?php
                            if ( $item ) {
                                MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $enabled );
                            }
                            ?>
                            <?php esc_html_e( 'Enable API key', 'mainwp' ); ?></label>
                            <div class="ten wide column ui toggle checkbox" data-tooltip="<?php esc_attr_e( 'If enabled, the REST API will be activated.', 'mainwp' ); ?>" data-inverted="" data-position="bottom left">
                                <input type="checkbox" class="settings-field-value-change-handler" name="mainwp_enable_rest_api" id="mainwp_enable_rest_api" value="1" <?php echo $enabled ? 'checked="true"' : ''; ?> aria-label="<?php esc_attr_e( 'Enable REST API key', 'mainwp' ); ?>" />
                            </div>
                        </div>
                        <div class="ui grid field settings-field-indicator-wrapper <?php echo $item ? 'settings-field-indicator-edit-api-keys' : ''; ?>"">
                            <label class="six wide column middle aligned">
                            <?php
                            if ( $item ) {
                                MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $edit_desc );
                            }
                            ?>
                            <?php esc_html_e( 'Description', 'mainwp' ); ?></label>
                            <div class="five wide column">
                                <input type="text" class="settings-field-value-change-handler" name="mainwp_rest_api_key_desc" id="mainwp_rest_api_key_desc" value="<?php echo esc_html( $edit_desc ); ?>" aria-label="<?php esc_attr_e( 'Key description', 'mainwp' ); ?>"/>
                            </div>
                        </div>
                        <div class="ui grid field settings-field-indicator-wrapper <?php echo $item ? 'settings-field-indicator-edit-api-keys' : ''; ?>"">
                            <label class="six wide column middle aligned">
                            <?php
                            if ( $item ) {
                                MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $ending );
                            }
                            ?>
                            <?php esc_html_e( 'Consumer key ending in', 'mainwp' ); ?></label>
                            <div class="five wide column">
                                <div class="ui disabled input">
                                    <input type="text" class="settings-field-value-change-handler" value="<?php echo esc_attr( '...' . $ending ); ?>" aria-label="<?php esc_attr_e( 'Consumer key ending in...', 'mainwp' ); ?>"/>
                                </div>
                            </div>
                        </div>
                        <div class="ui grid field settings-field-indicator-wrapper <?php echo $item ? 'settings-field-indicator-edit-api-keys' : ''; ?>" default-indi-value="3">
                            <label class="six wide column middle aligned">
                            <?php
                            if ( $item ) {
                                $tmp     = explode( ',', $init_pers );
                                $visible = 3 !== count( $tmp );
                                MainWP_Settings_Indicator::render_indicator( '', '', $visible );
                            }
                            ?>
                            <?php esc_html_e( 'Permissions', 'mainwp' ); ?></label>
                            <div class="five wide column">
                                <div class="ui multiple selection dropdown" init-value="<?php echo esc_attr( $init_pers ); ?>">
                                    <input name="mainwp_rest_api_key_edit_pers" class="settings-field-value-change-handler" value="" type="hidden">
                                    <i class="dropdown icon"></i>
                                    <div class="default text"><?php echo ( '' === $init_pers ) ? esc_html__( 'No Permissions selected.', 'mainwp' ) : ''; ?></div>
                                    <div class="menu">
                                        <div class="item" data-value="r"><?php esc_html_e( 'Read', 'mainwp' ); ?></div>
                                        <div class="item" data-value="w"><?php esc_html_e( 'Write', 'mainwp' ); ?></div>
                                        <div class="item" data-value="d"><?php esc_html_e( 'Delete', 'mainwp' ); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="ui divider"></div>
                        <input type="submit" name="submit" id="submit" class="ui green big button" value="<?php esc_attr_e( 'Save API Key', 'mainwp' ); ?>"/>
                        <div style="clear:both"></div>
                    </form>
                </div>

        </div>
        <?php
        static::render_footer( 'Edit' );
    }

    /**
     * Method check_rest_api_enabled().
     *
     * @param bool $check_logged_in check for logged in user or not.
     *
     * @return bool check result.
     */
    public static function check_rest_api_enabled( $check_logged_in = false ) { // phpcs:ignore -- NOSONAR - complex.
        $cookies = array();
        if ( $check_logged_in && is_user_logged_in() && defined( 'LOGGED_IN_COOKIE' ) ) {
            $cookies      = array();
            $auth_cookies = wp_parse_auth_cookie( $_COOKIE[ LOGGED_IN_COOKIE ], 'logged_in' ); // phpcs:ignore -- ok.
            if ( is_array( $auth_cookies ) ) {
                foreach ( $auth_cookies as $name => $value ) {
                    $cookies[] = new \WP_Http_Cookie(
                        array(
                            'name'  => $name,
                            'value' => $value,
                        )
                    );
                }
            }
        }

        $args = array(
            'method'  => 'GET',
            'timeout' => 45,
            'headers' => array(
                'content-type' => 'application/json',
            ),
        );

        if ( $check_logged_in && ! empty( $cookies ) ) {
            $args['cookies'] = $cookies;
        }

        $site_url = get_option( 'home' );
        $response = wp_remote_post( $site_url . '/wp-json', $args );
        $body     = wp_remote_retrieve_body( $response );
        $data     = is_string( $body ) ? json_decode( $body, true ) : false;

        if ( is_array( $data ) & isset( $data['routes'] ) && ! empty( $data['routes'] ) ) {
            return true;
        } elseif ( ! $check_logged_in ) {
            return static::check_rest_api_enabled( true );
        }
        return false;
    }

    /**
     * Method mainwp_help_content()
     *
     * Creates the MainWP Help Documentation List for the help component in the sidebar.
     */
    public static function mainwp_help_content() {
        $allow_pages = array( 'RESTAPI', 'AddApiKeys' );
        if ( isset( $_GET['page'] ) && in_array( $_GET['page'], $allow_pages, true ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
            ?>
            <p><?php esc_html_e( 'If you need help with the MainWP REST API, please review following help documents', 'mainwp' ); ?></p>
            <div class="ui list">
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/mainwp-rest-api/" target="_blank">REST API</a></div> <?php // NOSONAR -- compatible with help. ?>
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/mainwp-rest-api/#how-to-enable-rest-api" target="_blank">Enable REST API</a></div> <?php // NOSONAR -- compatible with help. ?>
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/mainwp-rest-api/#rest-api-permissions" target="_blank">REST API Permissions</a></div> <?php // NOSONAR -- compatible with help. ?>
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/mainwp-rest-api/#how-to-disable-rest-api" target="_blank">Disable REST API</a></div> <?php // NOSONAR -- compatible with help. ?>
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/mainwp-rest-api/#how-to-delete-rest-api-keys" target="_blank">Delete REST API Key</a></div> <?php // NOSONAR -- compatible with help. ?>
                <?php
                /**
                 * Action: mainwp_rest_api_help_item
                 *
                 * Fires at the bottom of the help articles list in the Help sidebar on the REST API page.
                 *
                 * Suggested HTML markup:
                 *
                 * <div class="item"><a href="Your custom URL">Your custom text</a></div>
                 *
                 * @since 4.0
                 */
                do_action( 'mainwp_rest_api_help_item' );
                ?>
            </div>
            <?php
        }
    }
}
