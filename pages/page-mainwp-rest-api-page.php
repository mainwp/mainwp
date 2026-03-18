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

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

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
     * Private variable to hold the application passwords instance.
     *
     * @var MainWP_Application_Passwords
     */
    protected static $application_passwords;

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

    /**
     * Constructor
     *
     * Sets up the application passwords instance.
     */
    public function __construct() {
        static::$application_passwords = MainWP_Application_Passwords::instance();
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

        // Page: Application Passwords.
        MainWP_Post_Handler::instance()->add_action( 'mainwp_application_password_create', array( &$this, 'ajax_application_password_create' ) );
        MainWP_Post_Handler::instance()->add_action( 'mainwp_application_password_delete', array( &$this, 'ajax_application_password_delete' ) );
        MainWP_Post_Handler::instance()->add_action( 'mainwp_application_password_delete_multiple', array( &$this, 'ajax_application_password_delete_multiple' ) );
        MainWP_Post_Handler::instance()->add_action( 'mainwp_application_password_delete_all', array( &$this, 'ajax_application_password_delete_all' ) );
        // Add update handler for editing application password name.
        MainWP_Post_Handler::instance()->add_action( 'mainwp_application_password_update', array( &$this, 'ajax_application_password_update' ) );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        // Redirect to Application Passwords page if user tries to access REST API page and doesn't have permission.
        if ( isset( $_GET['page'] ) && 'RESTAPI' === $_GET['page'] && ! static::can_access_rest_api() ) {
            // If cannot view REST API, try redirecting to Application Passwords if allowed, otherwise to dashboard.
            $redirect = static::$application_passwords->can_access_application_passwords() ? 'admin.php?page=ApplicationPasswords' : 'admin.php?page=mainwp_tab'; // NOSONAR.
            wp_safe_redirect( admin_url( $redirect ) ); // NOSONAR.
            exit;
        }

        // Redirect to Application Passwords page if user tries to access Add API Keys page and doesn't have permission.
        // Allow access to Add/Edit API Keys if user has manage/create/edit REST API keys.
        if (
            isset( $_GET['page'] ) &&
            'AddApiKeys' === $_GET['page'] &&
            ! ( static::can_create_rest_api_keys() || static::can_edit_rest_api_keys() )
        ) {
            $redirect = static::$application_passwords->can_access_application_passwords() ? 'admin.php?page=ApplicationPasswords' : 'admin.php?page=mainwp_tab';
            wp_safe_redirect( admin_url( $redirect ) );
            exit;
        }
		// phpcs:enable

        $this->handle_rest_api_add_new();
        $this->handle_rest_api_edit();
    }


    /**
     * Instantiate the REST API Menu.
     *
     * @uses \MainWP\Dashboard\MainWP_Menu::is_disable_menu_item()
     */
    public static function init_menu() {
        $add_api_keys_title          = esc_html__( 'Add API Keys', 'mainwp' ); // NOSONAR.
        $api_access_title            = esc_html__( 'API Access', 'mainwp' ); // NOSONAR.
        $application_passwords_title = esc_html__( 'Application Passwords', 'mainwp' ); // NOSONAR.

        add_submenu_page(
            'mainwp_tab',
            $api_access_title,
            ' <span id="mainwp-rest-api">' . $api_access_title . '</span>',
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
                $add_api_keys_title,
                ' <div class="mainwp-hidden">' . $add_api_keys_title . '</div>',
                'read',
                'AddApiKeys',
                array(
                    static::get_class_name(),
                    'render_rest_api_setings',
                )
            );
        }
        if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ApplicationPasswords' ) ) {
            add_submenu_page(
                'mainwp_tab',
                $application_passwords_title,
                ' <div class="mainwp-hidden">' . $application_passwords_title . '</div>',
                'read',
                'ApplicationPasswords',
                array(
                    static::get_class_name(),
                    'render_application_passwords',
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
            <div class="wp-submenu sub-open">
                <div class="mainwp_boxout">
                    <div class="mainwp_boxoutin"></div>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=RESTAPI' ) ); // NOSONAR. ?>" class="mainwp-submenu"><?php esc_html_e( 'API Access', 'mainwp' ); ?></a>
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
        // Determine if the user has permission to view either REST API or Application Passwords.
        $is_can_view_rest_api              = static::can_access_rest_api();
        $is_can_view_application_passwords = static::$application_passwords->can_access_application_passwords();
        if ( ! $is_can_view_rest_api && ! $is_can_view_application_passwords ) {
            return;
        }

        $api_keys_title              = esc_html__( 'API Keys', 'mainwp' ); // NOSONAR.
        $application_passwords_title = esc_html__( 'Application Passwords', 'mainwp' );
        $application_passwords_url   = 'admin.php?page=ApplicationPasswords';

        // Determine the landing href for the top-level API Access item based on permissions.
        $root_href = 'admin.php?page=RESTAPI';
        if ( ! $is_can_view_rest_api && $is_can_view_application_passwords ) {
            $root_href = $application_passwords_url;
        }

        MainWP_Menu::add_left_menu(
            array(
                'title'      => esc_html__( 'Access', 'mainwp' ),
                'parent_key' => 'mainwp_tab',
                'slug'       => 'RESTAPI',
                'href'       => $root_href,
                'id'         => 'mainwp-restapi-active-item',
                'icon'       => '<div class="mainwp-api-icon">API</div>',
            ),
            0
        );

        // API Keys.
        $sub_rest_api = array();
        if ( $is_can_view_rest_api ) {
            // Second-level groups (dropdowns) under API Access.
            MainWP_Menu::add_left_menu(
                array(
                    'title'      => $api_keys_title,
                    'parent_key' => 'RESTAPI',
                    'slug'       => 'RESTAPIKeys',
                    'href'       => 'admin.php?page=RESTAPI',
                ),
                1
            );
            $sub_rest_api[] = array(
                'title'      => $api_keys_title,
                'parent_key' => 'RESTAPIKeys',
                'href'       => 'admin.php?page=RESTAPI',
                'slug'       => 'RESTAPI',
                'right'      => '',
            );

            if ( static::can_create_rest_api_keys() ) {
                $sub_rest_api[] = array(
                    'title'      => esc_html__( 'Add API Keys', 'mainwp' ),
                    'parent_key' => 'RESTAPIKeys',
                    'href'       => 'admin.php?page=AddApiKeys',
                    'slug'       => 'AddApiKeys',
                    'right'      => '',
                );
            }
        }

        // Application Passwords.
        $sub_application_passwords = array();
        if ( $is_can_view_application_passwords ) {
            MainWP_Menu::add_left_menu(
                array(
                    'title'      => $application_passwords_title,
                    'parent_key' => 'RESTAPI',
                    'slug'       => 'RESTAPIAppPasswords',
                    'href'       => $application_passwords_url,
                ),
                1
            );
            $sub_application_passwords[] = array(
                'title'      => $application_passwords_title,
                'parent_key' => 'RESTAPIAppPasswords',
                'href'       => $application_passwords_url,
                'slug'       => 'ApplicationPasswords',
                'right'      => '',
            );
        }

        // Third-level items (actual links).
        $init_sub_subleftmenu = array_merge(
            $sub_rest_api,
            $sub_application_passwords
        );

        // If any dynamic subpages exist, attach them under API Keys group by default.
        MainWP_Menu::init_subpages_left_menu( $subPages, $init_sub_subleftmenu, 'RESTAPIKeys', 'RESTAPI' );
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
            // Only users with explicit create permission can create new API keys.
            if ( ! static::can_create_rest_api_keys() ) {
                wp_safe_redirect( admin_url( 'admin.php?page=RESTAPI' ) );
                exit;
            }
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
                $pers_list  = explode( ',', $pers );
                $has_read   = in_array( 'r', $pers_list );
                $has_write  = in_array( 'w', $pers_list ) || in_array( 'd', $pers_list );

                if ( $has_read && $has_write ) {
                    $scope = 'read_write';
                } elseif ( $has_write ) {
                    $scope = 'write';
                } elseif ( $has_read ) {
                    $scope = 'read';
                }
            }
            $this->invalidate_warm_cache();
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
            // Only users with explicit edit permission can update API keys.
            if ( ! static::can_edit_rest_api_keys() ) {
                wp_safe_redirect( admin_url( 'admin.php?page=RESTAPI' ) );
                exit();
            }

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
                        $pers_list  = explode( ',', $pers );
                        $has_read   = in_array( 'r', $pers_list );
                        $has_write  = in_array( 'w', $pers_list ) || in_array( 'd', $pers_list );

                        if ( $has_read && $has_write ) {
                            $scope = 'read_write';
                        } elseif ( $has_write ) {
                            $scope = 'write';
                        } elseif ( $has_read ) {
                            $scope = 'read';
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

            $this->invalidate_warm_cache();

			wp_safe_redirect( admin_url( 'admin.php?page=RESTAPI' . $msg ) ); //phpcs:ignore -- ok.
            exit();
        }
    }

    /**
     * Method invalidate_warm_cache()
     */
    public function invalidate_warm_cache() {
        MainWP_Cache_Warm_Helper::invalidate_manage_pages( array( 'RESTAPI' ) );
    }

    /**
     * Method ajax_rest_api_remove_keys()
     *
     * Remove API Key.
     */
	public function ajax_rest_api_remove_keys() { //phpcs:ignore -- NOSONAR - complex.
        MainWP_Post_Handler::instance()->check_security( 'mainwp_rest_api_remove_keys' );
        // Require delete permission to remove API keys.
        if ( ! static::can_delete_rest_api_keys() ) {
            echo wp_json_encode(
                array(
                    'success' => false,
                    'error'   => esc_html__( 'You are not allowed to delete REST API keys.', 'mainwp' ),
                )
            );
            exit();
        }
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
            $this->invalidate_warm_cache();
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
            'title' => esc_html__( 'API Access', 'mainwp' ),
        );

        MainWP_UI::render_top_header( $params );

        $renderItems = array();

        // Show API Keys tab if user can view REST API section (any of manage/create/edit/delete).
        if ( static::can_access_rest_api() ) {
            $renderItems[] = array(
                'title'  => esc_html__( 'API Keys', 'mainwp' ),
                'href'   => 'admin.php?page=RESTAPI',
                'active' => ( '' === $shownPage ) ? true : false,
            );
        }

        // Show Add API Keys tab if user can create or edit REST API keys or has manage permission.
        if ( ! MainWP_Menu::is_disable_menu_item( 3, 'AddApiKeys' ) && ( static::can_create_rest_api_keys() || static::can_edit_rest_api_keys() ) ) {
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

        if ( ! MainWP_Menu::is_disable_menu_item( 3, 'ApplicationPasswords' ) && ( static::$application_passwords->can_view_manage_application_passwords() ||
            static::$application_passwords->can_view_all_application_passwords() ||
            static::$application_passwords->can_create_application_passwords() ||
            static::$application_passwords->can_delete_application_passwords() ) ) {

            $renderItems[] = array(
                'title'  => esc_html__( 'Application Passwords', 'mainwp' ),
                'href'   => 'admin.php?page=ApplicationPasswords',
                'active' => ( 'Application Passwords' === $shownPage ) ? true : false,
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
     * Render a friendly permission denied UI inside MainWP layout.
     *
     * @param string $title   Page title to show in header.
     * @param string $message Message to show in the body.
     */
    protected static function render_permission_denied_ui( $title, $message ) {
        $params = array( 'title' => $title );
        MainWP_UI::render_top_header( $params );
        ?>
        <div class="ui segment">
            <div class="ui negative message">
                <i class="ban icon"></i>
                <div class="content">
                    <div class="header"><?php echo esc_html( $title ); ?></div>
                    <p><?php echo esc_html( $message ); ?></p>
                </div>
            </div>
            <div class="ui divider"></div>
            <div class="ui two column stackable grid">
                <div class="column">
                    <?php if ( current_user_can( 'manage_options' ) ) : ?>
                        <a class="ui small basic button" href="<?php echo esc_url( admin_url( 'admin.php?page=RESTAPI' ) ); ?>">
                            <i class="key icon"></i> <?php esc_html_e( 'API Keys', 'mainwp' ); ?>
                        </a>
                    <?php endif; ?>
                </div>
                <div class="column right aligned">
                    <?php if ( static::$application_passwords->can_access_application_passwords() ) : ?>
                        <a class="ui small green button"
                            href="<?php echo esc_url( admin_url( 'admin.php?page=ApplicationPasswords' ) ); ?>">
                            <i class="lock icon"></i> <?php esc_html_e( 'Go to Application Passwords', 'mainwp' ); ?>
                        </a>
                    <?php else : ?>
                        <a class="ui small button" href="<?php echo esc_url( admin_url( 'admin.php?page=mainwp_tab' ) ); ?>">
                            <i class="home icon"></i> <?php esc_html_e( 'Back to Dashboard', 'mainwp' ); ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
        static::render_footer();
    }

    /**
     * Method render_api_keys_v1_table.
     *
     * @return void
     */
	public static function render_api_keys_v1_table() { //phpcs:ignore -- NOSONAR - complex.
        $all_keys        = static::check_rest_api_updates();
        $can_edit_keys   = static::can_edit_rest_api_keys();
        $can_delete_keys = static::can_delete_rest_api_keys();


        if ( ! is_array( $all_keys ) ) {
            $all_keys = array();
        }

        if ( ! empty( $all_keys ) ) {
            $write_delete = esc_html__( 'Write & Delete', 'mainwp' ); // NOSONAR - ok.
            ?>

            <table id="mainwp-rest-api-keys-table" class="ui unstackable single linetable">
                <thead>
                    <tr>
                        <th scope="col" class="no-sort collapsing check-column"><span class="ui checkbox"><input
                                    aria-label="<?php esc_attr_e( 'Select all REST API keys', 'mainwp' ); ?>" id="cb-select-all-top"
                                    type="checkbox" /></span></th>
                        <th scope="col" class="collapsing"><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'API Key', 'mainwp' ); ?></th>
                        <th scope="col" class="collapsing"><?php esc_html_e( 'Permissions', 'mainwp' ); ?></th>
                        <th scope="col" class="no-sort collapsing"><?php esc_html_e( 'Consumer key ending in', 'mainwp' ); ?></th>
                        <?php if ( $can_edit_keys || $can_delete_keys ) : ?>
                            <th scope="col" class="no-sort collapsing"></th>
                        <?php endif; ?>
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
                        <tr key-ck-id="<?php echo esc_attr( $endcoded_ck ); ?>">
                            <td class="check-column">
                                <div class="ui checkbox">
                                    <input type="checkbox"
                                        aria-label="<?php echo esc_attr__( 'Select API key described as: ', 'mainwp' ) . esc_html( $desc ); ?>"
                                        value="<?php echo esc_html( $endcoded_ck ); ?>" name="" />
                                </div>
                            </td>
                            <td><?php echo $enabled ? '<span class="ui green fluid label">' . esc_html__( 'Enabled', 'mainwp' ) . '</span>' : '<span class="ui gray fluid label">' . esc_html__( 'Disabled', 'mainwp' ) . '</span>'; ?>
                            </td>
                            <td>
                                <?php if ( $can_edit_keys ) : ?>
                                    <?php
                                    $url = add_query_arg(
                                        array(
                                            'page'       => 'AddApiKeys',
                                            'editkey'    => $endcoded_ck,
                                            '_opennonce' => wp_create_nonce( 'mainwp-admin-nonce' ),
                                        ),
                                        admin_url( 'admin.php' )
                                    );
                                    ?>
                                    <a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $desc ); ?></a>
                                <?php else : ?>
                                    <?php echo esc_html( $desc ); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo ! empty( $pers_names ) ? implode( ', ', $pers_names ) : 'N/A'; // phpcs:ignore WordPress.Security.EscapeOutput ?>
                            </td>
                            <td><code><?php echo esc_html( '...' . $ending ); // phpcs:ignore WordPress.Security.EscapeOutput ?></code>
                            </td>
                            <?php if ( $can_edit_keys || $can_delete_keys ) : ?>
                                <td class="right aligned">
                                    <div class="ui right pointing dropdown" style="z-index:999">
                                        <i class="ellipsis vertical icon"></i>
                                        <div class="menu">
                                            <?php if ( $can_edit_keys ) : ?>
                                                <?php
                                                $url = add_query_arg(
                                                    array(
                                                        'page' => 'AddApiKeys',
                                                        'editkey' => $endcoded_ck,
                                                        '_opennonce' => wp_create_nonce( 'mainwp-admin-nonce' ),
                                                    ),
                                                    admin_url( 'admin.php' )
                                                );
                                                ?>
                                                <a class="item"
                                                    href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Edit', 'mainwp' ); ?></a>
                                            <?php endif; ?>
                                            <?php if ( $can_delete_keys ) : ?>
                                                <a class="item" href="javascript:void(0)"
                                                    onclick="mainwp_restapi_remove_key_confirm(jQuery(this).closest('tr').find('.check-column INPUT:checkbox'));"><?php esc_html_e( 'Delete', 'mainwp' ); ?></a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
            <script type="text/javascript">
                var responsive = true;
                if (jQuery(window).width() > 1140) {
                    responsive = false;
                }
                jQuery(document).ready(function ($) {
                    try {
                        jQuery('#mainwp-rest-api-keys-table').DataTable({
                            "lengthMenu": [[5, 10, 25, 50, 100, -1], [5, 10, 25, 50, 100, "All"]],
                            "stateSave": true,
                            "order": [[1, 'asc']],
                            "columnDefs": [{
                                "targets": 'no-sort',
                                "orderable": false
                            }],
                            "responsive": responsive,
                            "preDrawCallback": function () {
                                jQuery('#mainwp-rest-api-keys-table .ui.dropdown').dropdown();
                                jQuery('#mainwp-rest-api-keys-table .ui.checkbox').checkbox();
                                mainwp_datatable_fix_menu_overflow('#mainwp-rest-api-keys-table', -70, 0);
                                mainwp_table_check_columns_init(); // ajax: to fix checkbox all.
                            }
                        });
                        mainwp_datatable_fix_menu_overflow('#mainwp-rest-api-keys-table', -70, 0);
                    } catch {
                        // to fix js error.
                    }
                });
            </script>
            <?php
        }
    }

    /** Render REST API SubPage */
	public static function render_api_keys_v2_table() { // phpcs:ignore -- NOSONAR - complex.
        $all_keys_v2     = MainWP_DB::instance()->get_rest_api_keys();
        $can_edit_keys   = static::can_edit_rest_api_keys();
        $can_delete_keys = static::can_delete_rest_api_keys();
        $el_id_cb_1      = 'cb-select-all-top';
        ?>
        <table id="mainwp-rest-api-keys-v2-table" class="ui unstackable single line table">
            <thead>
                <tr>
                    <th scope="col" class="no-sort collapsing check-column">
                        <span class="ui checkbox">
                            <input
                                aria-label="<?php esc_attr_e( 'Select all REST API keys', 'mainwp' ); ?>"
                                id="<?php echo esc_attr( $el_id_cb_1 ); ?>" type="checkbox" />
                        </span>
                    </th>
                    <th scope="col" class="collapsing"><?php esc_html_e( 'Status', 'mainwp' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'API Key (v2)', 'mainwp' ); ?></th>
                    <th scope="col" class="collapsing"><?php esc_html_e( 'Permissions', 'mainwp' ); ?></th>
                    <th scope="col" class="no-sort collapsing"><?php esc_html_e( 'API key ending in', 'mainwp' ); ?></th>
                    <th scope="col" class="collapsing"><?php esc_html_e( 'Last access', 'mainwp' ); ?></th>
                    <?php if ( $can_edit_keys || $can_delete_keys ) : ?>
                        <th scope="col" class="no-sort collapsing"></th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody id="mainwp-rest-api-v2-body-table" class="mainwp-rest-api-body-table-manage">
                <?php
                if ( is_array( $all_keys_v2 ) ) :
                    foreach ( $all_keys_v2 as $item ) :
                        $ending  = $item->truncated_key;
                        $desc    = ! empty( $item->description ) ? esc_html( $item->description ) : 'N/A';
                        $enabled = $item->enabled ? true : false;
                        $key_id  = $item->key_id;

                        $pers_title = array();
                        $per        = $item->permissions;
                        if ( 'read' === $per ) {
                            $pers_title[] = esc_html__( 'Read', 'mainwp' );
                        }
                        if ( 'write' === $per || 'delete' === $per ) {
                            $pers_title[] = esc_html__( 'Write & Delete', 'mainwp' );
                        }
                        if ( 'read_write' === $per ) {
                            $pers_title[] = esc_html__( 'Read', 'mainwp' );
                            $pers_title[] = esc_html__( 'Write & Delete', 'mainwp' );
                        }
                        $url = '';
                        if ( $can_edit_keys ) {
                            $url = add_query_arg(
                                array(
                                    'page'       => 'AddApiKeys',
                                    'editkey'    => intval( $key_id ),
                                    'rest_ver'   => 2,
                                    '_opennonce' => wp_create_nonce( 'mainwp-admin-nonce' ),
                                ),
                                admin_url( 'admin.php' )
                            );
                        }
                        ?>
                        <tr key-ck-id="<?php echo intval( $key_id ); ?>">
                            <td class="check-column">
                                <div class="ui checkbox">
                                    <input type="checkbox"
                                        aria-label="<?php echo esc_attr__( 'Select API key described as: ', 'mainwp' ) . esc_html( $desc ); ?>"
                                        value="<?php echo intval( $key_id ); ?>" name="" />
                                </div>
                            </td>
                            <td><?php echo $enabled ? '<span class="ui green fluid label">' . esc_html__( 'Enabled', 'mainwp' ) . '</span>' : '<span class="ui gray fluid label">' . esc_html__( 'Disabled', 'mainwp' ) . '</span>'; ?>
                            </td>
                            <td>
                                <?php if ( $can_edit_keys ) : ?>
                                    <a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $desc ); ?></a>
                                <?php else : ?>
                                    <?php echo esc_html( $desc ); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo ! empty( $pers_title ) ? implode( ', ', $pers_title ) : 'N/A'; // phpcs:ignore WordPress.Security.EscapeOutput ?>
                            </td>
                            <td><code><?php echo esc_html( '...' . $ending ); // phpcs:ignore WordPress.Security.EscapeOutput ?></code>
                            </td>
                            <td data-order="<?php echo ! empty( $item->last_access ) ? esc_attr( strtotime( $item->last_access ) ) : 0; ?>">
                                <?php echo ! empty( $item->last_access ) ? '<span data-tooltip="' . esc_attr( MainWP_Utility::format_timestamp( MainWP_Utility::get_timestamp( strtotime( $item->last_access ) ) ) ) . '" data-position="left center" data-inverted="">' . esc_html( MainWP_Utility::time_elapsed_string( strtotime( $item->last_access ) ) ) . '</span>' : 'N/A'; // phpcs:ignore WordPress.Security.EscapeOutput ?>
                            </td>
                            <?php if ( $can_edit_keys || $can_delete_keys ) : ?>
                                <td class="right aligned">
                                    <div class="ui right pointing dropdown" style="z-index:999">
                                        <i class="ellipsis vertical icon"></i>
                                        <div class="menu">
                                            <?php if ( $can_edit_keys ) : ?>
                                                <a class="item"
                                                    href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Edit', 'mainwp' ); ?></a>
                                            <?php endif; ?>
                                            <?php if ( $can_delete_keys ) : ?>
                                                <a class="item" href="javascript:void(0)"
                                                    onclick="mainwp_restapi_remove_key_confirm(jQuery(this).closest('tr').find('.check-column INPUT:checkbox'));"><?php esc_html_e( 'Delete', 'mainwp' ); ?></a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                            <?php endif; ?>
                        </tr>
                        <?php
                    endforeach;
                endif;
                ?>
            </tbody>
        </table>
        <script type="text/javascript">
            var responsive = true;
            if (jQuery(window).width() > 1140) {
                responsive = false;
            }
            jQuery(document).ready(function ($) {
                try {
                    jQuery('#mainwp-rest-api-keys-v2-table').DataTable({
                        "lengthMenu": [[5, 10, 25, 50, 100, -1], [5, 10, 25, 50, 100, "All"]],
                        "stateSave": true,
                        "order": [[1, 'asc']],
                        "columnDefs": [{
                            "targets": 'no-sort',
                            "orderable": false
                        }],
                        "responsive": responsive,
                        "preDrawCallback": function () {
                            jQuery('#mainwp-rest-api-keys-v2-table .ui.dropdown').dropdown();
                            jQuery('#mainwp-rest-api-keys-v2-table .ui.checkbox').checkbox();
                            mainwp_datatable_fix_menu_overflow('#mainwp-rest-api-keys-v2-table', -70, 0);
                            mainwp_table_check_columns_init(); // ajax: to fix checkbox all.
                        }
                    });
                    mainwp_datatable_fix_menu_overflow('#mainwp-rest-api-keys-v2-table', -70, 0);
                } catch {
                    // to fix js error.
                }
            });
        </script>

        <?php
    }

    /**
     * Render All API Keys
     */
	public static function render_all_api_keys() { // phpcs:ignore -- NOSONAR - complex.
        // Allow page if user can view REST API (any of manage/create/edit/delete).
        if ( ! static::can_access_rest_api() ) {
            static::render_permission_denied_ui(
                esc_html__( 'API Access', 'mainwp' ),
                esc_html__( 'You do not have permission to view the REST API. If you need access to this page please contact the dashboard administrator.', 'mainwp' )
            );
            return;
        }
        $all_keys = static::check_rest_api_updates();
        if ( ! is_array( $all_keys ) ) {
            $all_keys = array();
        }
        $all_keys_v2        = MainWP_DB::instance()->get_rest_api_keys();
        $has_no_api_keys    = empty( $all_keys ) && empty( $all_keys_v2 );
        static::render_header();
        static::render_table_top( $has_no_api_keys );
        if ( ! static::check_rest_api_enabled() ) {
            ?>
            <div class="ui message yellow">
                <?php
                // translators: 1: Opening anchor tag. 2: Closing anchor tag.
                printf( esc_html__( 'It seems the WordPress REST API is currently disabled on your site. MainWP REST API requires the WordPress REST API to function properly. Please enable it to ensure smooth operation. Need help? %1$sClick here for a guide%2$s.', 'mainwp' ), '<a href="https://docs.mainwp.com/troubleshooting/wordpress-rest-api-does-not-respond" target="_blank">', '</a> <i class="external alternate icon"></i>' );
                ?>
            </div>
            <?php
        }
        ?>
        <div id="mainwp-rest-api-keys" class="ui padded segment">
            <?php if ( $has_no_api_keys ) : ?>
                <?php
                MainWP_UI::render_empty_page_placeholder(
                    esc_html__( 'No API Keys yet', 'mainwp' ),
                    esc_html__( 'Create an API key to integrate MainWP with your custom tools, automations, and external platforms through the REST API.', 'mainwp' ),
                    '<em data-emoji=":key:" class="big"></em>'
                );
                ?>
                <?php if ( static::can_create_rest_api_keys() ) : ?>
                    <div class="ui center aligned basic segment">
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=AddApiKeys' ) ); ?>"
                            class="ui green button"><?php esc_html_e( 'Create New API Key', 'mainwp' ); ?></a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>


            <div class="ui message" id="mainwp-message-zone-apikeys" style="display:none;"></div>
            <?php static::show_messages(); ?>
            <?php if ( ! $has_no_api_keys ) : ?>
                <div class="ui basic accordion mainwp-blank-accordion" id="mainwp-rest-api-keys-accordion">
                    <h2 class="ui dividing header active title">
                        <i class="right dropdown icon"></i>
                        <?php esc_html_e( 'MainWP REST API v2 API Keys', 'mainwp' ); ?>
                        <div class="sub header">
                            <?php esc_html_e( 'Generate secure API Keys to connect your MainWP Dashboard with trusted tools and automations.', 'mainwp' ); ?>
                        </div>
                    </h2>
                    <div class="content active">
                        <?php static::render_api_keys_v2_table(); ?>
                    </div>
                    <?php if ( ! empty( $all_keys ) ) : ?>
                        <h2 class="ui dividing header title">
                            <i class="right dropdown icon"></i>
                            <?php esc_html_e( 'MainWP REST API v1 API Keys (Legacy)', 'mainwp' ); ?>
                            <div class="sub header">
                                <?php esc_html_e( 'Legacy API keys for older integrations. We recommend switching to v2 for better security and performance.', 'mainwp' ); ?>
                            </div>
                        </h2>
                        <div class="content">
                            <?php static::render_api_keys_v1_table(); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <script>
                    jQuery(document).ready(function () {
                        jQuery('#mainwp-rest-api-keys-accordion').accordion({
                            exclusive: false,
                        });
                    });
                </script>
            <?php endif; ?>
        </div>
        <?php
        static::render_footer();
    }

    /**
     * Render table top.
     *
     * @param bool $has_no_api_keys Whether there are no API keys.
     */
    public static function render_table_top( $has_no_api_keys = false ) {
        if ( $has_no_api_keys ) {
            return;
        }
        ?>
        <div class="mainwp-sub-header">
            <div class="ui grid">
                <div class="equal width row ui mini form">
                    <div class="middle aligned column">
                        <?php if ( ! $has_no_api_keys && static::can_delete_rest_api_keys() ) : ?>
                            <div id="mainwp-rest-api-bulk-actions-menu" class="ui selection dropdown disabled">
                                <div class="default text"><?php esc_html_e( 'Bulk actions', 'mainwp' ); ?></div>
                                <i class="dropdown icon"></i>
                                <div class="menu">
                                    <div class="item" data-value="delete"><?php esc_html_e( 'Delete', 'mainwp' ); ?></div>
                                </div>
                            </div>
                            <button class="ui mini basic button disabled"
                                id="mainwp-do-rest-api-bulk-actions"><?php esc_html_e( 'Apply', 'mainwp' ); ?></button>
                        <?php endif; ?>
                    </div>
                    <div class="right aligned middle aligned column">
                        <?php if ( static::can_create_rest_api_keys() ) : ?>
                            <a href="admin.php?page=AddApiKeys"
                                class="ui mini green button"><?php esc_html_e( 'Create New API Key', 'mainwp' ); ?></a>
                        <?php endif; ?>
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

    /**
     * Render REST API SubPage
     */
	public static function render_rest_api_setings() { //phpcs:ignore -- NOSONAR - complex.
        // Allow access if user can create or edit REST API keys (manage alone cannot create/edit).
        if ( ! ( static::can_create_rest_api_keys() || static::can_edit_rest_api_keys() ) ) {
            static::render_permission_denied_ui(
                esc_html__( 'API Access', 'mainwp' ),
                esc_html__( 'You do not have permission to manage REST API keys. If you need access to this page please contact the dashboard administrator.', 'mainwp' )
            );
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
        <div id="rest-api-settings" class="ui padded segment">
            <h2 class="ui dividing header">
                <?php esc_html_e( 'MainWP REST API Credentials', 'mainwp' ); ?>
                <div class="sub header">
                    <?php esc_html_e( 'Generate, manage, and revoke API keys to securely connect with the MainWP REST API. Ensure you\'re using the correct version for your integrations.', 'mainwp' ); ?>
                </div>
            </h2>
            <div id="api-credentials-created" class="ui green message">
                <strong><?php esc_html_e( 'API Key Created Successfully!', 'mainwp' ); ?></strong><br />
                <?php esc_html_e( 'Copy your key and store it securely. It will not be visible again!', 'mainwp' ); ?>
            </div>

            <?php static::show_messages(); ?>

            <div class="ui info message">
                <?php esc_html_e( 'The API Key (Bearer) authentication is only compatible with the MainWP REST API v2', 'mainwp' ); ?>
                <?php esc_html_e( 'If you are working with the MainWP REST API v1 (Legacy), click on the "Show Legacy API Credentials" button to obtain your Consumer Key and Consumer Secret.', 'mainwp' ); ?>
            </div>

            <div class="ui form">
                <form method="POST" action="">
                    <?php MainWP_UI::generate_wp_nonce( 'mainwp-admin-nonce' ); ?>
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
                        <label class="six wide column middle aligned" for="mainwp_enable_rest_api"><?php esc_html_e( 'Enable API key', 'mainwp' ); ?></label>
                        <div class="ten wide column ui toggle checkbox">
                            <input type="checkbox" name="mainwp_enable_rest_api" id="mainwp_enable_rest_api" checked="true"
                                aria-label="<?php esc_attr_e( 'Enable REST API key', 'mainwp' ); // NOSONAR. ?>" />
                        </div>
                    </div>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned" for="mainwp_rest_add_api_key_desc"><?php esc_html_e( 'API key name', 'mainwp' ); ?></label>
                        <div class="five wide column">
                            <input type="text" name="mainwp_rest_add_api_key_desc" id="mainwp_rest_add_api_key_desc" value=""
                                aria-label="<?php esc_attr_e( 'API key name.', 'mainwp' ); ?>" />
                        </div>
                    </div>

                    <div class="ui grid field">
                        <label
                            class="six wide column middle aligned" for="mainwp_rest_api_keys_compatible_v1"><?php esc_html_e( 'Enable MainWP REST API v1 Compatibility', 'mainwp' ); ?></label>
                        <div class="ten wide column ui toggle checkbox"
                            data-tooltip="<?php esc_attr_e( 'If enabled, the credentials will be compatible with REST API v1.', 'mainwp' ); ?>"
                            data-inverted="" data-position="bottom left">
                            <input type="checkbox" name="mainwp_rest_api_keys_compatible_v1"
                                id="mainwp_rest_api_keys_compatible_v1"
                                aria-label="<?php esc_attr_e( 'If enabled, the credentials will be compatible with REST API v1.', 'mainwp' ); ?>" />
                        </div>
                    </div>

                    <?php $init_pers = 'r,w,d'; ?>

                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'Permissions', 'mainwp' ); ?></label>
                        <div class="ten wide column">
                            <div class="mainwp-rest-api-permission-chips" data-init-value="<?php echo esc_attr( $init_pers ); ?>" data-api-version="v2">
                                <input type="hidden" name="mainwp_rest_api_key_edit_pers" value="" />
                                <button type="button" class="ui basic label mainwp-permission-chip" data-permission="r">
                                    <i class="book icon"></i>
                                    <?php esc_html_e( 'Read', 'mainwp' ); ?>
                                </button>
                                <button type="button" class="ui basic label mainwp-permission-chip mainwp-v2-chip" data-permission="w,d">
                                    <i class="pencil icon"></i>
                                    <?php esc_html_e( 'Write & Delete', 'mainwp' ); ?>
                                </button>
                                <button type="button" class="ui basic label mainwp-permission-chip mainwp-v1-chip" data-permission="w" style="display:none;">
                                    <i class="pencil icon"></i>
                                    <?php esc_html_e( 'Write', 'mainwp' ); ?>
                                </button>
                                <button type="button" class="ui basic label mainwp-permission-chip mainwp-v1-chip" data-permission="d" style="display:none;">
                                    <i class="trash icon"></i>
                                    <?php esc_html_e( 'Delete', 'mainwp' ); ?>
                                </button>
                            </div>
                            <div class="mainwp-permission-chips-help">
                                <span class="ui small grey text"><?php esc_html_e( 'Choose what actions this key can perform on your Dashboard data.', 'mainwp' ); ?></span>
                            </div>
                        </div>
                    </div>

                    <?php
                    $token = $_consumer_secret . '==' . $_consumer_key;
                    ?>
                    <div class="ui grid field">
                        <label
                            class="six wide column middle aligned" for="mainwp_rest_token"><?php esc_html_e( 'API key (Bearer token)', 'mainwp' ); ?></label>
                        <div class="five wide column">
                            <input type="text" name="mainwp_rest_token" id="mainwp_rest_token"
                                value="<?php echo esc_html( $token ); ?>" readonly
                                aria-label="<?php esc_attr_e( 'API Token', 'mainwp' ); ?>" />
                        </div>
                        <div class="five wide column">
                            <input id="mainwp_rest_token_clipboard_button" style="display:nonce;" type="button" name=""
                                class="ui green basic button copy-to-clipboard"
                                value="<?php esc_attr_e( 'Copy to Clipboard', 'mainwp' ); // NOSONAR. ?>">
                        </div>
                    </div>

                    <div id="mainwp-legacy-api-credentials" class="ui segment" style="display:none">
                        <h3 class="ui dividing header">
                            <?php esc_html_e( 'Legacy API Credentials', 'mainwp' ); ?>
                            <div class="sub header">
                                <?php esc_html_e( 'Authentication compatible only with the MainWP REST API version 1!', 'mainwp' ); ?>
                            </div>
                        </h3>

                        <div class="ui grid field">
                            <label
                                class="six wide column middle aligned" for="mainwp_consumer_key"><?php esc_html_e( 'Consumer key', 'mainwp' ); ?></label>
                            <div class="five wide column">
                                <input type="text" name="mainwp_consumer_key" id="mainwp_consumer_key"
                                    value="<?php echo esc_html( $consumer_key ); ?>" readonly
                                    aria-label="<?php esc_attr_e( 'Consumer key', 'mainwp' ); ?>" />
                            </div>
                            <div class="five wide column">
                                <input id="mainwp_consumer_key_clipboard_button" style="display:nonce;" type="button" name=""
                                    class="ui green basic button copy-to-clipboard"
                                    value="<?php esc_attr_e( 'Copy to Clipboard', 'mainwp' ); ?>">
                            </div>
                        </div>

                        <div class="ui grid field">
                            <label
                                class="six wide column middle aligned" for="mainwp_consumer_secret"><?php esc_html_e( 'Consumer secret', 'mainwp' ); ?></label>
                            <div class="five wide column">
                                <input type="text" name="mainwp_consumer_secret" id="mainwp_consumer_secret"
                                    value="<?php echo esc_html( $consumer_secret ); ?>" readonly
                                    aria-label="<?php esc_attr_e( 'Consumer secret', 'mainwp' ); ?>" />
                            </div>
                            <div class="five wide column">
                                <input id="mainwp_consumer_secret_clipboard_button" style="display:nonce;" type="button" name=""
                                    class="ui green basic button copy-to-clipboard"
                                    value="<?php esc_attr_e( 'Copy to Clipboard', 'mainwp' ); ?>">
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
                    <input type="submit" name="submit" id="submit-save-settings-button" class="ui green big button"
                        value="<?php esc_attr_e( 'Save Key', 'mainwp' ); ?>" />
                    <div style="clear:both"></div>
                </form>
            </div>
        </div>
        <div id="mainwp-api-key-copied-confirm-modal" class="ui mini modal">
            <i class="close icon"></i>
            <div class="header">
                <?php esc_html_e( 'API Key Copied', 'mainwp' ); ?>
            </div>
            <div class="content">
                <p><?php esc_html_e( 'Your API key has been copied to your clipboard.', 'mainwp' ); ?></p>
            </div>
        </div>
        <script type="text/javascript">
            jQuery(function ($) {
                //we are going to inject the values into the copy buttons to make things easier for people
                $('#mainwp_consumer_key_clipboard_button').attr('data-clipboard-text', '<?php echo esc_html( $consumer_key ); ?>');
                $('#mainwp_consumer_secret_clipboard_button').attr('data-clipboard-text', '<?php echo esc_html( $consumer_secret ); ?>');
                $('#mainwp_rest_token_clipboard_button').attr('data-clipboard-text', '<?php echo esc_html( $token ); ?>'); //phpcs:ignore -- NOSONAR - token value ok.
                //initiate clipboard
                new ClipboardJS('.copy-to-clipboard');
                //show copy to clipboard buttons
                $('.copy-to-clipboard').show();

                $('#mainwp_rest_api_keys_compatible_v1').on('change', function () {
                    $('#mainwp-legacy-api-credentials').toggle();
                });
            });
        </script>
        <?php

        static::render_footer();
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
     * @param mixed $item The Key edit.
     */
	public static function render_rest_api_v2_edit( $item ) { //phpcs:ignore -- NOSONAR - complex.
        $keyid     = $item->key_id;
        $edit_desc = $item->description;
        $enabled   = $item->enabled ? true : false;
        $ending    = $item->truncated_key;
        $perms     = $item->permissions;

        $init_pers = '';
        if ( 'read' === $perms ) {
            $init_pers = 'r';
        } elseif ( 'write' === $perms || 'delete' === $perms ) {
            $init_pers = 'w,d';
        } elseif ( 'read_write' === $perms ) {
            $init_pers = 'r,w,d';
        }
        static::render_header( 'Edit' );
        $el_id_res_api_1 = 'rest-api-settings';
        ?>
        <div id="<?php echo esc_attr( $el_id_res_api_1 ); ?>" class="ui segment">
            <div class="ui form">
                <form method="POST" action="">
                    <?php MainWP_UI::generate_wp_nonce( 'mainwp-admin-nonce' ); ?>
                    <input type="hidden" name="edit_key_nonce"
                        value="<?php echo esc_attr( wp_create_nonce( 'edit-key-nonce-' . $keyid ) ); ?>" />
                    <input type="hidden" name="editkey_id" value="<?php echo esc_html( $keyid ); ?>" />
                    <input type="hidden" name="rest_v2_edit" value="1" />
                    <div
                        class="ui grid field settings-field-indicator-wrapper <?php echo $item ? 'settings-field-indicator-edit-api-keys' : ''; ?>">
                        <label class="six wide column middle aligned" for="mainwp_enable_rest_api">
                            <?php
                            if ( $item ) {
                                MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $enabled );
                            }
                            $el_id_en_res_api_1 = 'mainwp_enable_rest_api';
                            ?>
                            <?php esc_html_e( 'Enable REST API Key', 'mainwp' ); ?></label>
                        <div class="ten wide column ui toggle checkbox"
                            data-tooltip="<?php esc_attr_e( 'If enabled, the REST API will be activated.', 'mainwp' ); ?>"
                            data-inverted="" data-position="bottom left">
                            <input type="checkbox" class="settings-field-value-change-handler" name="mainwp_enable_rest_api"
                                id="<?php echo esc_attr( $el_id_en_res_api_1 ); ?>" value="1" <?php echo $enabled ? 'checked="true"' : ''; ?>
                                aria-label="<?php esc_attr_e( 'Enable REST API key', 'mainwp' ); ?>" />
                        </div>
                    </div>
                    <div
                        class="ui grid field settings-field-indicator-wrapper <?php echo $item ? 'settings-field-indicator-edit-api-keys' : ''; ?>">
                        <label class="six wide column middle aligned" for="mainwp_rest_api_key_desc">
                            <?php
                            if ( $item ) {
                                MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $edit_desc );
                            }
                            ?>
                            <?php esc_html_e( 'Description', 'mainwp' ); ?></label>
                        <div class="five wide column">
                            <input type="text" class="settings-field-value-change-handler" name="mainwp_rest_api_key_desc"
                                id="mainwp_rest_api_key_desc" value="<?php echo esc_html( $edit_desc ); ?>"
                                aria-label="<?php esc_attr_e( 'Key description', 'mainwp' ); ?>" />
                        </div>
                    </div>
                    <div
                        class="ui grid field settings-field-indicator-wrapper <?php echo $item ? 'settings-field-indicator-edit-api-keys' : ''; ?>">
                        <label class="six wide column middle aligned" for="none_preset_value">
                            <?php
                            if ( $item ) {
                                MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $ending );
                            }
                            ?>
                            <?php esc_html_e( 'Consumer key ending in', 'mainwp' ); ?></label>
                        <div class="five wide column">
                            <div class="ui disabled input">
                                <input type="text" class="settings-field-value-change-handler"
                                    value="<?php echo esc_attr( '...' . $ending ); ?>"
                                    aria-label="<?php esc_attr_e( 'Consumer key ending in...', 'mainwp' ); ?>" />
                            </div>
                        </div>
                    </div>
                    <div class="ui grid field settings-field-indicator-wrapper <?php echo $item ? 'settings-field-indicator-edit-api-keys' : ''; ?>"
                        default-indi-value="3">
                        <label class="six wide column middle aligned">
                            <?php
                            if ( $item ) {
                                $tmp     = explode( ',', $init_pers );
                                $visible = 2 !== count( $tmp );
                                MainWP_Settings_Indicator::render_indicator( '', '', $visible );
                            }
                            ?>
                            <?php esc_html_e( 'Permissions', 'mainwp' ); ?></label>
                        <div class="ten wide column">
                            <div class="mainwp-rest-api-permission-chips" data-init-value="<?php echo esc_attr( $init_pers ); ?>" data-api-version="v2">
                                <input type="hidden" name="mainwp_rest_api_key_edit_pers" class="settings-field-value-change-handler" value="" />
                                <button type="button" class="ui button chip mainwp-permission-chip" data-permission="r">
                                    <i class="book icon"></i>
                                    <?php esc_html_e( 'Read', 'mainwp' ); ?>
                                </button>
                                <button type="button" class="ui button chip mainwp-permission-chip" data-permission="w,d">
                                    <i class="pencil icon"></i>
                                    <?php esc_html_e( 'Write & Delete', 'mainwp' ); ?>
                                </button>
                            </div>
                            <div class="mainwp-permission-chips-help" style="margin-top: 8px; font-size: 12px; color: #666;">
                                <?php esc_html_e( 'Choose what actions this key can perform on your Dashboard data.', 'mainwp' ); ?>
                            </div>
                        </div>
                    </div>
                    <div class="ui divider"></div>
                    <input type="submit" name="submit" id="submit" class="ui green big button"
                        value="<?php esc_attr_e( 'Save API Key', 'mainwp' ); ?>" />
                    <div style="clear:both"></div>
                </form>
            </div>

        </div>
        <?php
        static::render_footer();
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
        $el_id_res_api_2 = 'rest-api-settings';
        ?>
        <div id="<?php echo esc_attr( $el_id_res_api_2 ); ?>" class="ui segment">
            <div class="ui form">
                <form method="POST" action="">
                    <?php MainWP_UI::generate_wp_nonce( 'mainwp-admin-nonce' ); ?>
                    <input type="hidden" name="edit_key_nonce"
                        value="<?php echo esc_attr( wp_create_nonce( 'edit-key-nonce-' . $keyid ) ); ?>" />
                    <input type="hidden" name="editkey_id" value="<?php echo esc_html( $keyid ); ?>" />
                    <div
                        class="ui grid field settings-field-indicator-wrapper <?php echo $item ? 'settings-field-indicator-edit-api-keys' : ''; ?>">
                        <label class="six wide column middle aligned" for="mainwp_enable_rest_api">
                            <?php
                            if ( $item ) {
                                MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $enabled );
                            }
                            $el_id_en_res_api_2 = 'mainwp_enable_rest_api';
                            ?>
                            <?php esc_html_e( 'Enable API key', 'mainwp' ); ?></label>
                        <div class="ten wide column ui toggle checkbox"
                            data-tooltip="<?php esc_attr_e( 'If enabled, the REST API will be activated.', 'mainwp' ); ?>"
                            data-inverted="" data-position="bottom left">
                            <input type="checkbox" class="settings-field-value-change-handler" name="mainwp_enable_rest_api"
                                id="<?php echo esc_attr( $el_id_en_res_api_2 ); ?>" value="1" <?php echo $enabled ? 'checked="true"' : ''; ?>
                                aria-label="<?php esc_attr_e( 'Enable REST API key', 'mainwp' ); ?>" />
                        </div>
                    </div>
                    <div
                        class="ui grid field settings-field-indicator-wrapper <?php echo $item ? 'settings-field-indicator-edit-api-keys' : ''; ?>">
                        <label class="six wide column middle aligned" for="none_preset_value">
                            <?php
                            if ( $item ) {
                                MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $edit_desc );
                            }
                            ?>
                            <?php esc_html_e( 'Description', 'mainwp' ); ?></label>
                        <div class="five wide column">
                            <input type="text" class="settings-field-value-change-handler" name="mainwp_rest_api_key_desc"
                                id="mainwp_rest_api_key_desc" value="<?php echo esc_html( $edit_desc ); ?>"
                                aria-label="<?php esc_attr_e( 'Key description', 'mainwp' ); ?>" />
                        </div>
                    </div>
                    <div
                        class="ui grid field settings-field-indicator-wrapper <?php echo $item ? 'settings-field-indicator-edit-api-keys' : ''; ?>">
                        <label class="six wide column middle aligned" for="none_preset_value">
                            <?php
                            if ( $item ) {
                                MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $ending );
                            }
                            ?>
                            <?php esc_html_e( 'Consumer key ending in', 'mainwp' ); ?></label>
                        <div class="five wide column">
                            <div class="ui disabled input">
                                <input type="text" class="settings-field-value-change-handler"
                                    value="<?php echo esc_attr( '...' . $ending ); ?>"
                                    aria-label="<?php esc_attr_e( 'Consumer key ending in...', 'mainwp' ); ?>" />
                            </div>
                        </div>
                    </div>
                    <div class="ui grid field settings-field-indicator-wrapper <?php echo $item ? 'settings-field-indicator-edit-api-keys' : ''; ?>"
                        default-indi-value="3">
                        <label class="six wide column middle aligned">
                            <?php
                            if ( $item ) {
                                $tmp     = explode( ',', $init_pers );
                                $visible = 3 !== count( $tmp );
                                MainWP_Settings_Indicator::render_indicator( '', '', $visible );
                            }
                            ?>
                            <?php esc_html_e( 'Permissions', 'mainwp' ); ?></label>
                        <div class="ten wide column">
                            <div class="mainwp-rest-api-permission-chips" data-init-value="<?php echo esc_attr( $init_pers ); ?>" data-api-version="v1">
                                <input type="hidden" name="mainwp_rest_api_key_edit_pers" class="settings-field-value-change-handler" value="" />
                                <button type="button" class="ui button chip mainwp-permission-chip" data-permission="r">
                                    <i class="book icon"></i>
                                    <?php esc_html_e( 'Read', 'mainwp' ); ?>
                                </button>
                                <button type="button" class="ui button chip mainwp-permission-chip" data-permission="w">
                                    <i class="pencil icon"></i>
                                    <?php esc_html_e( 'Write', 'mainwp' ); ?>
                                </button>
                                <button type="button" class="ui button chip mainwp-permission-chip" data-permission="d">
                                    <i class="trash icon"></i>
                                    <?php esc_html_e( 'Delete', 'mainwp' ); ?>
                                </button>
                            </div>
                            <div class="mainwp-permission-chips-help" style="margin-top: 8px; font-size: 12px; color: #666;">
                                <?php esc_html_e( 'Choose what actions this key can perform on your Dashboard data.', 'mainwp' ); ?>
                            </div>
                        </div>
                    </div>
                    <div class="ui divider"></div>
                    <?php $el_id_sbm_btn_2 = 'submit'; ?>
                    <input type="submit" name="submit" id="<?php echo esc_attr( $el_id_sbm_btn_2 ); ?>"
                        class="ui green big button" value="<?php esc_attr_e( 'Save API Key', 'mainwp' ); ?>" />
                    <div style="clear:both"></div>
                </form>
            </div>

        </div>
        <?php
        static::render_footer();
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
            'method'    => 'GET',
            'timeout'   => 45,
            'headers'   => array(
                'content-type' => 'application/json',
            ),
            'sslverify' => (bool) get_option( 'mainwp_sslVerifyCertificate', true ),
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
            <p><?php esc_html_e( 'If you need help with the MainWP REST API, please review following help documents', 'mainwp' ); ?>
            </p>
            <div class="ui list">
                <div class="item"><i class="external alternate icon"></i> <a
                        href="https://docs.mainwp.com/dashboard/overview/mainwp-rest-api" target="_blank">REST API</a></div>
                <?php // NOSONAR -- compatible with help. ?>
                <div class="item"><i class="external alternate icon"></i> <a
                        href="https://docs.mainwp.com/dashboard/overview/mainwp-rest-api#enable-rest-api" target="_blank">Enable
                        REST API</a></div> <?php // NOSONAR -- compatible with help. ?>
                <div class="item"><i class="external alternate icon"></i> <a
                        href="https://docs.mainwp.com/dashboard/overview/mainwp-rest-api#rest-api-permissions" target="_blank">REST
                        API Permissions</a></div> <?php // NOSONAR -- compatible with help. ?>
                <div class="item"><i class="external alternate icon"></i> <a
                        href="https://docs.mainwp.com/dashboard/overview/mainwp-rest-api#disable-rest-api" target="_blank">Disable
                        REST API</a></div> <?php // NOSONAR -- compatible with help. ?>
                <div class="item"><i class="external alternate icon"></i> <a
                        href="https://docs.mainwp.com/dashboard/overview/mainwp-rest-api#delete-rest-api-keys"
                        target="_blank">Delete REST API Key</a></div> <?php // NOSONAR -- compatible with help. ?>
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

    /**
     * Render application password table top.
     *
     * @param bool $has_rows Whether there are rows to display.
     */
    public static function render_application_passwords_table_top( $has_rows = true ) {
        // Align with Team Control caps: create_application_passwords, delete_application_passwords.
        $can_add    = static::$application_passwords->can_create_application_passwords();
        $can_revoke = static::$application_passwords->can_delete_application_passwords();

        if ( ! $can_add && ! $can_revoke ) {
            return;
        }

        if ( ! $has_rows ) {
            return;
        }
        ?>
        <div class="mainwp-sub-header">
            <div class="ui grid">
                <div class="equal width row ui mini form">
                    <div class="middle aligned column">
                        <?php if ( $can_add ) : ?>
                            <button type="button" class="ui mini green button"
                                id="mainwp-create-application-password-button"><?php esc_html_e( 'Add Application Password', 'mainwp' ); ?></button>
                        <?php endif; ?>
                    </div>
                    <div class="right aligned middle aligned column">
                        <?php if ( $can_revoke ) : ?>
                            <button class="ui mini grey basic button disabled"
                                id="mainwp-do-application-passwords-bulk-actions"><?php esc_html_e( 'Revoke Selected Application Passwords', 'mainwp' ); ?></button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render Application Passwords page.
     */
    public static function render_application_passwords() {
        // Silent check to avoid echoing permission messages on access control.
        $can_access = static::$application_passwords->can_access_application_passwords();

        if ( ! $can_access ) {
            static::render_permission_denied_ui(
                esc_html__( 'Application Passwords', 'mainwp' ),
                esc_html__( 'You do not have permission to view Application Passwords. If you need access to this page please contact the dashboard administrator.', 'mainwp' )
            );
            return;
        }

        $page_title = 'Application Passwords';
        $context    = static::get_app_passwords_context();
        $rows       = static::get_application_password_rows( $context );

        static::render_header( $page_title );
        static::render_application_passwords_table_top( ! empty( $rows ) );

        $current_user_name = ( $context['current_user'] instanceof \WP_User ) ? (string) $context['user_login'] : '';
        $user_id           = (int) $context['user_id'];
        $show_user_col     = ! empty( $context['can_view_all'] );
        ?>
        <div id="rest-application-passwords-settings" class="ui padded segment"
            data-current-user-name="<?php echo esc_attr( $current_user_name ); ?>"
            data-current-user-id="<?php echo esc_attr( $user_id ); ?>">
            <?php static::render_application_passwords_messages( $rows ); ?>
            <?php static::render_application_passwords_table( $rows, (bool) $show_user_col ); ?>
            <?php static::create_application_password_modal(); ?>
            <?php static::success_application_password_modal(); ?>
            <?php static::edit_application_password_modal(); ?>

            <script type="text/javascript">
                jQuery(function ($) {
                    var created_index = <?php echo $show_user_col ? 3 : 2; ?>;

                    window.mainwp_app_passwords_table = $('#mainwp-application-password-table').DataTable({
                        pageLength: 10,
                        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
                        stateSave: true,
                        order: [[created_index, 'desc']],
                        columnDefs: [{
                            targets: 'no-sort',
                            orderable: false
                        }],
                        initComplete: function () {
                            $('#mainwp-application-password-table .ui.checkbox').checkbox();
                            if (typeof mainwp_table_check_columns_init === 'function') {
                                mainwp_table_check_columns_init();
                            }
                        },
                        drawCallback: function () {
                            $('#mainwp-application-password-table .ui.checkbox').checkbox();
                            if (typeof mainwp_table_check_columns_init === 'function') {
                                mainwp_table_check_columns_init();
                            }
                        }
                    });
                });
            </script>
        </div>
        <?php
        static::render_footer();
    }

    /**
     * Render create application password modal.
     */
    public static function create_application_password_modal() {
        ?>
        <div class="ui small modal" id="mainwp-create-application-password-modal">
            <i class="ui close icon"></i>
            <div class="header"><?php esc_html_e( 'Add Application Password', 'mainwp' ); ?></div>
            <div class="content">
                <div class="ui form">
                    <div class="field">
                        <label
                            for="mainwp-app-password-name-input"><?php esc_html_e( 'Application Password Name', 'mainwp' ); ?></label>
                        <input type="text" name="app_password_name" id="mainwp-app-password-name-input"
                            placeholder="<?php esc_attr_e( 'e.g. MCP Client', 'mainwp' ); ?>" />
                        <span
                            class="ui small text"><?php esc_html_e( 'Enter a name to help you identify this application password.', 'mainwp' ); ?></span>
                    </div>
                </div>
            </div>
            <div class="actions">
                <button class="ui green ok button" id="mainwp-create-app-password-submit">
                    <?php esc_html_e( 'Create', 'mainwp' ); ?>
                </button>
                
            </div>
        </div>
        <?php
    }

    /**
     * Render success application password modal.
     */
    public static function success_application_password_modal() {
        ?>
        <div class="ui small modal" id="mainwp-application-password-success-modal">
            <div class="header">
                <strong id="app-pass-success-name"></strong> <?php esc_html_e( 'Application Password Created', 'mainwp' ); ?>
            </div>
            <div class="content">
                <div class="ui message info">
                    <?php esc_html_e( 'Be sure to save this in a safe location. You will not be able to retrieve it.', 'mainwp' ); ?>
                </div>
                <?php esc_html_e( 'Your new password is:', 'mainwp' ); ?>
                <div class="ui grid">
                    <div class="thirteen wide middle aligned column">
                        <div class="ui fluid input">
                            <input type="text" id="app-pass-success-value" readonly="readonly"
                                style="font-family: monospace; font-size: 16px; letter-spacing: 2px;" />
                        </div>
                    </div>
                    <div class="three wide middle aligned column">
                        <button class="ui green basic fluid button copy-app-password"
                            data-tooltip="<?php esc_attr_e( 'Copy to clipboard', 'mainwp' ); ?>" data-position="top center"
                            data-inverted="">
                            <i class="copy icon"></i> <?php esc_html_e( 'Copy', 'mainwp' ); ?>
                        </button>
                    </div>
                </div>
            </div>
            <div class="actions">
                <div class="ui green ok button"><?php esc_html_e( 'Done', 'mainwp' ); ?></div>
            </div>
        </div>
        <?php
    }

    /**
     * Render edit application password modal.
     */
    public static function edit_application_password_modal() {
        ?>
        <div class="ui small modal" id="mainwp-edit-application-password-modal">
            <div class="header"><?php esc_html_e( 'Edit Application Password', 'mainwp' ); ?></div>
            <div class="content">
                <div class="ui form">
                    <div class="field">
                        <label for="mainwp-app-password-edit-name-input"><?php esc_html_e( 'Name', 'mainwp' ); ?></label>
                        <input type="text" name="app_password_name_edit" id="mainwp-app-password-edit-name-input" />
                        <input type="hidden" id="mainwp-app-password-edit-uuid" />
                        <input type="hidden" id="mainwp-app-password-edit-user-id" />
                    </div>
                </div>
            </div>
            <div class="actions">
                <button class="ui green ok button" id="mainwp-edit-app-password-submit">
                    <?php esc_html_e( 'Save', 'mainwp' ); ?>
                </button>
                <button class="ui cancel button"><?php esc_html_e( 'Cancel', 'mainwp' ); ?></button>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX handler for creating application password.
     */
    public function ajax_application_password_create() {
        MainWP_Post_Handler::instance()->check_security( 'mainwp_application_password_create' );

        if ( ! static::$application_passwords->can_create_application_passwords() ) {
            wp_send_json_error( array( 'message' => __( 'You are not allowed to create application passwords.', 'mainwp' ) ) );
        }

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : ''; // phpcs:ignore -- ok.

        if ( '' === $name ) {
            wp_send_json_error( array( 'message' => __( 'Application name is required.', 'mainwp' ) ) );
        }

        $user_id = get_current_user_id();
        $result  = static::$application_passwords->create_new_application_password( $user_id, array( 'name' => $name ) );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        list( $password, $item ) = $result;

        // Format password with spaces for better readability.
        $formatted_password = static::$application_passwords->chunk_password( $password );

        wp_send_json_success(
            array(
                'password' => $formatted_password,
                'item'     => $item,
            )
        );
    }

    /**
     * AJAX handler for deleting application password.
     */
    public function ajax_application_password_delete() {
        MainWP_Post_Handler::instance()->check_security( 'mainwp_application_password_delete' );

        if ( ! static::$application_passwords->can_delete_application_passwords() ) {
            wp_send_json_error( array( 'message' => __( 'You are not allowed to revoke application passwords.', 'mainwp' ) ) ); // NOSONAR.
        }

		$uuid = isset( $_POST['uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['uuid'] ) ) : ''; // phpcs:ignore -- ok.
		$user_id = isset( $_POST['user_id'] ) ? (int) wp_unslash( $_POST['user_id'] ) : 0; // phpcs:ignore -- ok.

        if ( '' === $uuid ) {
            wp_send_json_error( array( 'message' => __( 'UUID is required.', 'mainwp' ) ) );
        }

        $current_id = get_current_user_id();
        // Only allow targeting others if can manage or can view all.
        $can_target_others = static::$application_passwords->can_manage_application_passwords() || static::$application_passwords->can_view_all_application_passwords();
        $target_user       = ( $user_id > 0 && $can_target_others ) ? $user_id : $current_id;

        $result = static::$application_passwords->delete_application_password( $target_user, $uuid );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 'deleted' => true ) );
    }

    /**
     * AJAX handler for deleting multiple application passwords.
     */
	public function ajax_application_password_delete_multiple() { // phpcs:ignore -- NOSONAR.
        MainWP_Post_Handler::instance()->check_security( 'mainwp_application_password_delete_multiple' );
        // Must have delete capability to delete own passwords.
        $can_delete_self = static::$application_passwords->can_delete_application_passwords();
        if ( ! $can_delete_self ) {
            wp_send_json_error( array( 'message' => __( 'You are not allowed to revoke application passwords.', 'mainwp' ) ) );
        }

        // Only allow targeting others if can manage or can view all.
        $can_target_others = static::$application_passwords->can_manage_application_passwords() || static::$application_passwords->can_view_all_application_passwords();

		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $items = isset( $_POST['items'] ) && is_array( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : array();
        $uuids = isset( $_POST['uuids'] ) && is_array( $_POST['uuids'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['uuids'] ) ) : array();
		// phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        $current_id = get_current_user_id();
        $deleted    = 0;
        $errors     = array();

        if ( is_array( $items ) && ! empty( $items ) ) {
            foreach ( (array) $items as $it ) {
                $uuid    = isset( $it['uuid'] ) ? sanitize_text_field( $it['uuid'] ) : '';
                $user_id = isset( $it['user_id'] ) ? (int) $it['user_id'] : 0;

                if ( '' === $uuid ) {
                    continue;
                }

                $target_user = ( $user_id > 0 && $can_target_others ) ? $user_id : $current_id;
                $result      = static::$application_passwords->delete_application_password( $target_user, $uuid );

                if ( is_wp_error( $result ) ) {
                    $errors[] = $result->get_error_message();
                } else {
                    ++$deleted;
                }
            }
        } else {
            $uuids = is_array( $uuids ) ? array_map( 'sanitize_text_field', $uuids ) : array();
            if ( empty( $uuids ) ) {
                wp_send_json_error( array( 'message' => __( 'No passwords selected.', 'mainwp' ) ) );
            }

            foreach ( $uuids as $uuid ) {
                $result = static::$application_passwords->delete_application_password( $current_id, $uuid );

                if ( is_wp_error( $result ) ) {
                    $errors[] = $result->get_error_message();
                } else {
                    ++$deleted;
                }
            }
        }

        if ( ! empty( $errors ) ) {
            wp_send_json_error( array( 'message' => implode( ', ', $errors ) ) );
        }

        wp_send_json_success( array( 'deleted' => $deleted ) );
    }

    /**
     * AJAX handler for deleting all application passwords.
     */
    public function ajax_application_password_delete_all() {
        MainWP_Post_Handler::instance()->check_security( 'mainwp_application_password_delete_all' );

        if ( ! static::$application_passwords->can_delete_application_passwords() ) {
            wp_send_json_error( array( 'message' => __( 'You are not allowed to revoke application passwords.', 'mainwp' ) ) );
        }

        $user_id = get_current_user_id();
        $result  = static::$application_passwords->delete_all_application_passwords( $user_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 'deleted' => $result ) );
    }

    /**
     * AJAX handler for updating (renaming) an application password.
     */
	public function ajax_application_password_update() { // phpcs:ignore -- NOSONAR.
        MainWP_Post_Handler::instance()->check_security( 'mainwp_application_password_update' );
        // Must have edit capability to edit own passwords.
        $can_edit_self = static::$application_passwords->can_edit_application_passwords();
        if ( ! $can_edit_self ) {
            wp_send_json_error( array( 'message' => __( 'You are not allowed to edit application passwords.', 'mainwp' ) ) );
        }

		// phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $uuid    = isset( $_POST['uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['uuid'] ) ) : '';
		$user_id = isset( $_POST['user_id'] ) ? (int) wp_unslash( $_POST['user_id'] ) : 0; // phpcs:ignore -- ok.
        $name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

		// phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if ( '' === $uuid || '' === $name ) {
            wp_send_json_error( array( 'message' => __( 'UUID and Name are required.', 'mainwp' ) ) );
        }

        $current_id       = get_current_user_id();
        // Only allow targeting others if can manage or can view all.
        $can_edit_others  = static::$application_passwords->can_manage_application_passwords() || static::$application_passwords->can_view_all_application_passwords();
        $target_user      = ( $user_id > 0 && $can_edit_others ) ? $user_id : $current_id;
        $result      = static::$application_passwords->update_application_password( $target_user, $uuid, array( 'name' => $name ) );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        $updated_item = static::$application_passwords->get_user_application_password( $target_user, $uuid );
        wp_send_json_success(
            array(
                'updated' => true,
                'item'    => $updated_item,
            )
        );
    }

    /**
     * Get the context for application passwords.
     *
     * @return array The context.
     */
    protected static function get_app_passwords_context() {
        $user_id      = get_current_user_id();
        $current_user = wp_get_current_user();
        $admin_email  = get_option( 'admin_email' );
        $display_name = '';
        $user_login   = '';

        if ( $current_user instanceof \WP_User ) {
            $display_name = ! empty( $current_user->display_name ) ? $current_user->display_name : $current_user->user_login;
            $user_login   = $current_user->user_login;
        }

        $can_view_all = static::$application_passwords->can_view_all_application_passwords();

        return array(
            'user_id'      => (int) $user_id,
            'current_user' => $current_user,
            'display_name' => $display_name,
            'admin_email'  => (string) $admin_email,
            'user_login'   => $user_login,
            'can_view_all' => (bool) $can_view_all,
        );
    }

    /**
     * Get the application password rows.
     *
     * @param array $context The context.
     *
     * @return array The application password rows.
     */
    protected static function get_application_password_rows( $context ) {
        $can_view_all = static::$application_passwords->can_view_all_application_passwords();

        // Only allow viewing all users' passwords when can_view_all is granted.
        if ( $can_view_all ) {
            return static::get_all_users_application_password_rows();
        }

        return static::get_single_user_application_password_rows(
            (int) $context['user_id'],
            $context['user_login'],
            $context['current_user'] instanceof \WP_User ? (string) $context['display_name'] : ''
        );
    }

    /**
     * Get all users application password rows.
     *
     * @return array The application password rows.
     */
    protected static function get_all_users_application_password_rows() {
        $rows  = array();
        $users = get_users(
            array(
                'fields' => array( 'ID', 'display_name', 'user_email', 'user_login' ),
            )
        );

        foreach ( $users as $u ) {
            $pwds = static::$application_passwords->get_user_application_passwords( $u->ID );
            if ( empty( $pwds ) ) {
                continue;
            }

            $user_name = ! empty( $u->display_name ) ? $u->display_name : $u->user_login;

            foreach ( $pwds as $pwd_item ) {
                $rows[] = static::decorate_password_row(
                    $pwd_item,
                    (int) $u->ID,
                    (string) $u->user_login,
                    (string) $user_name,
                    (string) $u->user_email
                );
            }
        }

        return $rows;
    }

    /**
     * Get a single user application password rows.
     *
     * @param int    $user_id      The user ID.
     * @param string $user_login   The user login.
     * @param string $display_name The display name.
     *
     * @return array The application password rows.
     */
    protected static function get_single_user_application_password_rows( $user_id, $user_login, $display_name ) {
        $rows      = array();
        $passwords = static::$application_passwords->get_user_application_passwords( $user_id );

        foreach ( $passwords as $pwd_item ) {
            $rows[] = static::decorate_password_row(
                $pwd_item,
                (int) $user_id,
                (string) $user_login,
                (string) $display_name,
                ''
            );
        }

        return $rows;
    }

    /**
     * Decorate a password row.
     *
     * @param array  $pwd_item     The password item.
     * @param int    $user_id      The user ID.
     * @param string $user_login   The user login.
     * @param string $user_name    The user name.
     * @param string $user_email   The user email.
     *
     * @return array The decorated password row.
     */
    protected static function decorate_password_row( $pwd_item, $user_id, $user_login, $user_name, $user_email ) {
        $pwd_item['user_id']        = (int) $user_id;
        $pwd_item['user_login']     = (string) $user_login;
        $pwd_item['user_full_name'] = (string) $user_name;

        if ( '' !== $user_email ) {
            $pwd_item['user_email'] = (string) $user_email;
        }

        return $pwd_item;
    }

    /**
     * Render application passwords messages.
     *
     * @param array $rows The application password rows.
     */
    protected static function render_application_passwords_messages( $rows ) {
        if ( empty( $rows ) ) {
            if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-app-passwords-welcome-message' ) ) {
                ?>
                <div class="ui icon message mainwp-welcome-message" style="margin-bottom:0;">
                    <em data-emoji=":wave:" class="big"></em>
                    <div class="content">
                        <div class="ui massive header"><?php esc_html_e( 'Get started with MainWP Abilities API / MCP', 'mainwp' ); ?>
                        </div>
                        <p><?php esc_html_e( 'You haven\'t created any Application Passwords. MainWP Abilities API / MCP uses WordPress Application Passwords (not the MainWP API Key).', 'mainwp' ); ?>
                        </p>
                        <p><?php esc_html_e( 'Create a new Application Password (recommended: name it after the client/tool), then use it as the credential when connecting.', 'mainwp' ); ?>
                        </p>
                    </div>
                    <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-app-passwords-welcome-message"></i>
                </div>
                <?php
            }
            return;
        }

        if ( MainWP_Utility::show_mainwp_message( 'notice', 'mainwp-app-passwords-info-message' ) ) {
            ?>
            <div class="ui info message">
                <i class="close icon mainwp-notice-dismiss" notice-id="mainwp-app-passwords-info-message"></i>
                <?php
                /* translators: 1: Opening anchor tag for Abilities API link. 2: Closing anchor tag. */
                printf( esc_html__( '%1$sMainWP Abilities API%2$s / MCP uses WordPress Application Passwords so you can grant tool access without sharing your account password. Create a dedicated Application Password (for example "MCP Client") and revoke it whenever you no longer need it. This does not affect your regular login password.', 'mainwp' ), '<a href="' . esc_url( 'https://docs.mainwp.com/api-reference/abilities-api/overview' ) . '" target="_blank">', '</a>' );
                ?>
            </div>
            <?php
        }
    }

    /**
     * Render the application passwords table.
     *
     * @param array $rows The application password rows.
     * @param bool  $show_user_col Whether to show the username column.
     */
    protected static function render_application_passwords_table( $rows, $show_user_col ) {
        if ( empty( $rows ) ) {
            MainWP_UI::render_empty_page_placeholder(
                esc_html__( 'No Application Passwords yet', 'mainwp' ),
                esc_html__( 'Create your first application password to allow external applications to authenticate with MainWP.', 'mainwp' ),
                '<em data-emoji=":key:" class="big"></em>'
            );
            if ( static::$application_passwords->can_create_application_passwords() ) {
                ?>
                <div class="ui center aligned segment">
                    <button type="button" class="ui green button" id="mainwp-create-application-password-button"><?php esc_html_e( 'Add Application Password', 'mainwp' ); ?></button>
                </div>
                <?php
            }
            return;
        }

        // Align revoke (delete) permission with delete_application_passwords capability.
        $can_revoke = static::$application_passwords->can_delete_application_passwords();
        $can_edit   = static::$application_passwords->can_edit_application_passwords();
        ?>
        <div id="mainwp-message-zone-app-passwords" style="display:none;"></div>
        <div class="content active application-passwords-list-table-wrapper">
            <table id="mainwp-application-password-table" class="ui unstackable single line table"
                data-can-manage="<?php echo esc_attr( $can_revoke ? '1' : '0' ); ?>"
                data-can-edit="<?php echo esc_attr( $can_edit ? '1' : '0' ); ?>">
                <thead>
                    <tr>
                        <th scope="col" class="no-sort collapsing check-column">
                            <span class="ui checkbox">
                                <input aria-label="<?php esc_attr_e( 'Select all Application Passwords', 'mainwp' ); ?>"
                                    id="application-password-select-all-top" type="checkbox" />
                            </span>
                        </th>
                        <th scope="col" class="collapsing"><?php esc_html_e( 'Name', 'mainwp' ); ?></th>

                        <?php if ( $show_user_col ) : ?>
                            <th scope="col" class="collapsing mainwp-col-user"><?php esc_html_e( 'Username', 'mainwp' ); ?></th>
                        <?php endif; ?>

                        <th scope="col"><?php esc_html_e( 'Created', 'mainwp' ); ?></th>
                        <th scope="col" class="collapsing"><?php esc_html_e( 'Last Used', 'mainwp' ); ?></th>
                        <th scope="col" class="collapsing"><?php esc_html_e( 'Last IP', 'mainwp' ); ?></th>
                        <th scope="col" class="no-sort collapsing"></th>
                    </tr>
                </thead>
                <tbody id="mainwp-application-password-table-body" class="mainwp-application-password-body-table-manage">
                    <?php
                    foreach ( $rows as $item ) {
                        static::render_application_password_row( $item, (bool) $show_user_col, (bool) $can_revoke );
                    }
                    ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Render a single application password row.
     *
     * @param array $item         The application password item.
     * @param bool  $show_user_col Whether to show the username column.
     * @param bool  $can_revoke    Whether the current user can revoke application passwords.
     */
    protected static function render_application_password_row( $item, $show_user_col, $can_revoke ) {
        // translators: %s is the application password name.
        $aria_select = esc_attr( sprintf( __( 'Select %s', 'mainwp' ), $item['name'] ) );
        // translators: %s is the application password name.
        $aria_rename = esc_attr( sprintf( __( 'Rename "%s"', 'mainwp' ), $item['name'] ) );
        // translators: %s is the application password name.
        $aria_revoke = esc_attr( sprintf( __( 'Revoke "%s"', 'mainwp' ), $item['name'] ) );
        ?>
        <tr data-uuid="<?php echo esc_attr( $item['uuid'] ); ?>" data-user-id="<?php echo esc_attr( $item['user_id'] ); ?>"
            class="mainwp-application-password-row">
            <td class="check-column">
                <div class="ui checkbox">
                    <input type="checkbox" <?php echo $can_revoke ? '' : 'disabled'; ?>
                        class="mainwp-application-password-checkbox"
                        aria-label="<?php echo $aria_select; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>"
                        value="<?php echo esc_attr( $item['uuid'] ); ?>"
                        data-user-id="<?php echo esc_attr( $item['user_id'] ); ?>" />
                </div>
            </td>

            <td><?php echo esc_html( $item['name'] ); ?></td>

            <?php if ( $show_user_col ) : ?>
                <td><?php echo esc_html( $item['user_login'] ); ?></td>
            <?php endif; ?>

            <td data-order="<?php echo esc_attr( $item['created'] ); ?>">
                <?php echo esc_html( MainWP_Utility::format_timestamp( $item['created'] ) ); ?>
            </td>

            <td data-order="<?php echo ! empty( $item['last_used'] ) ? esc_attr( $item['last_used'] ) : 0; ?>">
                <?php
                if ( ! empty( $item['last_used'] ) ) {
                    echo '<span data-tooltip="' . esc_attr( MainWP_Utility::format_timestamp( $item['last_used'] ) ) . '" data-position="left center" data-inverted="">' .
                        esc_html( MainWP_Utility::time_elapsed_string( $item['last_used'] ) ) .
                        '</span>';
                } else {
                    echo '&mdash;';
                }
                ?>
            </td>

            <td>
                <?php
                if ( ! empty( $item['last_ip'] ) ) {
                    echo esc_html( $item['last_ip'] );
                } else {
                    echo '&mdash;';
                }
                ?>
            </td>

            <td class="right aligned">
                <?php if ( static::$application_passwords->can_edit_application_passwords() ) : ?>
                    <button type="button" class="ui mini basic button mainwp-edit-application-password"
                        data-uuid="<?php echo esc_attr( $item['uuid'] ); ?>"
                        data-user-id="<?php echo esc_attr( $item['user_id'] ); ?>"
                        data-name="<?php echo esc_attr( $item['name'] ); ?>"
                        aria-label="<?php echo $aria_rename; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">
                        <?php esc_html_e( 'Edit', 'mainwp' ); ?>
                    </button>
                <?php endif; ?>
                <?php if ( $can_revoke ) : ?>
                    <button type="button" class="ui mini button mainwp-revoke-application-password"
                        data-uuid="<?php echo esc_attr( $item['uuid'] ); ?>"
                        data-user-id="<?php echo esc_attr( $item['user_id'] ); ?>"
                        aria-label="<?php echo $aria_revoke; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">
                        <?php esc_html_e( 'Revoke', 'mainwp' ); ?>
                    </button>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /**
     * Determine if user can view REST API.
     *
     * @return bool
     */
    public static function can_access_rest_api() {
        if (
            static::can_view_manage_rest_api() ||
            static::can_create_rest_api_keys() ||
            static::can_delete_rest_api_keys() ||
            static::can_edit_rest_api_keys()
        ) {
            return true;
        }
        return false;
    }

    /**
     * Check if the current user can view and manage REST API keys.
     *
     * @return bool
     */
    public static function can_view_manage_rest_api() {
        return \mainwp_current_user_can( 'rest_api', 'manage_rest_api_keys' );
    }

    /**
     * Check if the current user can create REST API keys.
     *
     * @return bool
     */
    public static function can_create_rest_api_keys() {
        return \mainwp_current_user_can( 'rest_api', 'create_rest_api_keys' );
    }

    /**
     * Check if the current user can delete REST API keys.
     *
     * @return bool
     */
    public static function can_delete_rest_api_keys() {
        return \mainwp_current_user_can( 'rest_api', 'delete_rest_api_keys' );
    }

    /**
     * Check if the current user can edit REST API keys.
     *
     * @return bool
     */
    public static function can_edit_rest_api_keys() {
        return \mainwp_current_user_can( 'rest_api', 'edit_rest_api_keys' );
    }
}
