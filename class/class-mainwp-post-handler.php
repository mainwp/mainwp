<?php
/**
 * Post Handler.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

// phpcs:disable Generic.Metrics.CyclomaticComplexity -- complexity.

/**
 * Class MainWP_Post_Handler
 *
 * @package MainWP\Dashboard
 *
 * @uses \MainWP\Dashboard\MainWP_Post_Base_Handler
 */
class MainWP_Post_Handler extends MainWP_Post_Base_Handler { // phpcs:ignore -- NOSONAR - Complex.

    /**
     * Private static variable to hold the single instance of the class.
     *
     * @static
     *
     * @var mixed Default null
     */
    private static $instance = null;

    /**
     * Method instance()
     *
     * Create a public static instance.
     *
     * @static
     * @return MainWP_Post_Handler
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Initiate all actions.
     *
     * @uses \MainWP\Dashboard\MainWP_Post_Page_Handler::get_class_name()
     */
    public function init() {
        // Page: ManageSites.
        $this->add_action( 'mainwp_notes_save', array( &$this, 'mainwp_notes_save' ) );

        // Page: BulkAddUser.
        $this->add_action( 'mainwp_bulkadduser', array( &$this, 'mainwp_bulkadduser' ) );
        $this->add_action( 'mainwp_importuser', array( &$this, 'mainwp_importuser' ) );

        // Widget: RightNow.
        $this->add_action( 'mainwp_syncerrors_dismiss', array( &$this, 'mainwp_syncerrors_dismiss' ) );

        if ( \mainwp_current_user_can( 'dashboard', 'manage_security_issues' ) ) {
            // Page: SecurityIssues.
            $this->add_action( 'mainwp_security_issues_request', array( &$this, 'mainwp_security_issues_request' ) );
            $this->add_action( 'mainwp_security_issues_fix', array( &$this, 'mainwp_security_issues_fix' ) );
            $this->add_action( 'mainwp_security_issues_unfix', array( &$this, 'mainwp_security_issues_unfix' ) );
        }

        $this->add_action( 'mainwp_notice_status_update', array( &$this, 'mainwp_notice_status_update' ) );
        $this->add_action( 'mainwp_dismiss_activate_notice', array( &$this, 'dismiss_activate_notice' ) );
        $this->add_action( 'mainwp_status_saving', array( &$this, 'mainwp_status_saving' ) );
        $this->add_action( 'mainwp_leftmenu_filter_group', array( &$this, 'mainwp_leftmenu_filter_group' ) );
        $this->add_action( 'mainwp_widgets_order', array( &$this, 'ajax_widgets_order' ) );
        $this->add_action( 'mainwp_save_settings', array( &$this, 'ajax_mainwp_save_settings' ) );
        $this->add_action( 'mainwp_guided_tours_option_update', array( &$this, 'ajax_guided_tours_option_update' ) );
        $this->add_action( 'mainwp_help_modal_content_update', array( &$this, 'ajax_mainwp_help_modal_content_update' ) );

        // Page: Recent Posts.
        if ( \mainwp_current_user_can( 'dashboard', 'manage_posts' ) ) {
            $this->add_action( 'mainwp_post_unpublish', array( &$this, 'mainwp_post_unpublish' ) );
            $this->add_action( 'mainwp_post_publish', array( &$this, 'mainwp_post_publish' ) );
            $this->add_action( 'mainwp_post_trash', array( &$this, 'mainwp_post_trash' ) );
            $this->add_action( 'mainwp_post_delete', array( &$this, 'mainwp_post_delete' ) );
            $this->add_action( 'mainwp_post_restore', array( &$this, 'mainwp_post_restore' ) );
            $this->add_action( 'mainwp_post_approve', array( &$this, 'mainwp_post_approve' ) );
        }
        $this->add_action( 'mainwp_post_addmeta', array( MainWP_Post_Page_Handler::get_class_name(), 'ajax_add_meta' ) );
        // Page: Pages.
        if ( \mainwp_current_user_can( 'dashboard', 'manage_pages' ) ) {
            $this->add_action( 'mainwp_page_unpublish', array( &$this, 'mainwp_page_unpublish' ) );
            $this->add_action( 'mainwp_page_publish', array( &$this, 'mainwp_page_publish' ) );
            $this->add_action( 'mainwp_page_trash', array( &$this, 'mainwp_page_trash' ) );
            $this->add_action( 'mainwp_page_delete', array( &$this, 'mainwp_page_delete' ) );
            $this->add_action( 'mainwp_page_restore', array( &$this, 'mainwp_page_restore' ) );
        }
        // Page: Users.
        $this->add_action( 'mainwp_user_delete', array( &$this, 'mainwp_user_delete' ) );
        $this->add_action( 'mainwp_user_edit', array( &$this, 'mainwp_user_edit' ) );
        $this->add_action( 'mainwp_user_update_password', array( &$this, 'mainwp_user_update_password' ) );
        $this->add_action( 'mainwp_user_update_user', array( &$this, 'mainwp_user_update_user' ) );

        // Page: Posts.
        $this->add_action( 'mainwp_posts_search', array( &$this, 'mainwp_posts_search' ) );
        $this->add_action( 'mainwp_get_categories', array( &$this, 'mainwp_get_categories' ) );
        $this->add_action( 'mainwp_post_get_edit', array( &$this, 'mainwp_post_get_edit' ) );
        $this->add_action( 'mainwp_post_postingbulk', array( MainWP_Post_Page_Handler::get_class_name(), 'ajax_posting_posts' ) );
        $this->add_action( 'mainwp_get_sites_of_groups', array( MainWP_Post_Page_Handler::get_class_name(), 'ajax_get_sites_of_groups' ) );

        // Page: Pages.
        $this->add_action( 'mainwp_pages_search', array( &$this, 'mainwp_pages_search' ) );
        // Page: User.
        $this->add_action( 'mainwp_users_search', array( &$this, 'mainwp_users_search' ) );

        $this->add_action( 'mainwp_events_notice_hide', array( &$this, 'mainwp_events_notice_hide' ) );
        $this->add_action( 'mainwp_showhide_sections', array( &$this, 'mainwp_showhide_sections' ) );
        $this->add_action( 'mainwp_saving_status', array( &$this, 'mainwp_saving_status' ) );
        $this->add_action( 'mainwp_autoupdate_and_trust_child', array( &$this, 'mainwp_autoupdate_and_trust_child' ) );
        $this->add_action( 'mainwp_installation_warning_hide', array( &$this, 'mainwp_installation_warning_hide' ) );
        $this->add_action( 'mainwp_force_destroy_sessions', array( &$this, 'mainwp_force_destroy_sessions' ) );
        $this->add_action( 'mainwp_recheck_http', array( &$this, 'ajax_recheck_http' ) );
        $this->add_action( 'mainwp_ignore_http_response', array( &$this, 'mainwp_ignore_http_response' ) );
        $this->add_action( 'mainwp_disconnect_site', array( &$this, 'ajax_disconnect_site' ) );
        $this->add_action( 'mainwp_manage_sites_display_rows', array( &$this, 'ajax_sites_display_rows' ) );
        $this->add_action( 'mainwp_monitoring_sites_display_rows', array( &$this, 'ajax_monitoring_display_rows' ) );

        $this->add_action_nonce( 'mainwp-common-nonce' );

        // Page: Clients.
        $this->add_action( 'mainwp_clients_add_client', array( &$this, 'mainwp_clients_add_client' ) );
        $this->add_action( 'mainwp_clients_delete_client', array( &$this, 'mainwp_clients_delete_client' ) );

        $this->add_action( 'mainwp_clients_save_field', array( &$this, 'mainwp_clients_save_field' ) );
        $this->add_action( 'mainwp_clients_delete_general_field', array( &$this, 'mainwp_clients_delete_general_field' ) );
        $this->add_action( 'mainwp_clients_delete_field', array( &$this, 'mainwp_clients_delete_field' ) );
        $this->add_action( 'mainwp_clients_notes_save', array( &$this, 'mainwp_clients_notes_save' ) );
        $this->add_action( 'mainwp_clients_suspend_client', array( &$this, 'mainwp_clients_suspend_client' ) );
        $this->add_action( 'mainwp_refresh_icon', array( &$this, 'ajax_refresh_icon' ) );
        $this->add_action( 'mainwp_upload_custom_icon', array( &$this, 'ajax_upload_custom_icon' ) );
        $this->add_action( 'mainwp_select_custom_theme', array( &$this, 'ajax_select_custom_theme' ) );
        $this->add_action( 'mainwp_import_demo_data', array( &$this, 'ajax_import_demo_data' ) );
        $this->add_action( 'mainwp_delete_demo_data', array( &$this, 'ajax_delete_demo_data' ) );
        $this->add_action( 'mainwp_prepare_renew_connections', array( MainWP_Connect_Helper::instance(), 'ajax_prepare_renew_connections' ) );
        $this->add_action( 'mainwp_renew_connections', array( MainWP_Connect_Helper::instance(), 'ajax_renew_connections' ) );

        $this->add_action( 'mainwp_clients_check_client', array( &$this, 'mainwp_clients_check_client' ) );
        $this->add_action( 'mainwp_clients_import_client', array( &$this, 'mainwp_clients_import_client' ) );

        // Page: managesites.
        $this->add_action( 'mainwp_save_temp_import_website', array( &$this, 'ajax_save_temp_import_website' ) );
        $this->add_action( 'mainwp_delete_temp_import_website', array( &$this, 'ajax_delete_temp_import_website' ) );
        $this->add_action( 'mainwp_import_website_add_client', array( &$this, 'ajax_import_website_add_client' ) );
        $this->add_action( 'mainwp_import_website_add_client_no_site', array( &$this, 'ajax_import_website_add_client_no_site' ) );

        // Page:: mainwp-setup.
        $this->add_action( 'mainwp_clients_add_multi_client', array( &$this, 'ajax_clients_add_multi_client' ) );
        $this->add_action( 'mainwp_increase_connection_security', array( &$this, 'ajax_increase_connection_security' ) );
    }

    /**
     * Method add_post_action()
     *
     * Add ajax action.
     *
     * @param string $action Action to perform.
     * @param string $callback Callback to perform.
     */
    public function add_post_action( $action, $callback ) {
        $this->add_action( $action, $callback );
    }

    /**
     * Method mainwp_installation_warning_hide()
     *
     * Hide the installation warning.
     */
    public function mainwp_installation_warning_hide() {
        $this->secure_request( 'mainwp_installation_warning_hide' );

        update_option( 'mainwp_installation_warning_hide_the_notice', 'yes' );
        die( 'ok' );
    }

    /**
     * Method mainwp_users_search()
     *
     * Search Post handler,
     * Page: User.
     *
     * @uses \MainWP\Dashboard\MainWP_Cache::init_session()
     * @uses \MainWP\Dashboard\MainWP_User::render_table()
     */
    public function mainwp_users_search() {
        $this->secure_request( 'mainwp_users_search' );
        MainWP_Cache::init_session();

        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $role    = isset( $_POST['urole'] ) ? sanitize_text_field( wp_unslash( $_POST['urole'] ) ) : '';
        $groups  = isset( $_POST['groups'] ) && is_array( $_POST['groups'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['groups'] ) ) : '';
        $sites   = isset( $_POST['sites'] ) && is_array( $_POST['sites'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['sites'] ) ) : '';
        $clients = isset( $_POST['clients'] ) && is_array( $_POST['clients'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['clients'] ) ) : '';
        $search  = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        MainWP_User::render_table( false, $role, $groups, $sites, $search, $clients );
        die();
    }

    /**
     * Method mainwp_posts_search()
     *
     * Search Post handler,
     * Page: Posts.
     *
     * @uses \MainWP\Dashboard\MainWP_Cache::init_session()
     * @uses \MainWP\Dashboard\MainWP_Post::render_table()
     * @uses  \MainWP\Dashboard\MainWP_Utility::update_option()
     */
    public function mainwp_posts_search() { // phpcs:ignore -- NOSONAR - complex.
        $this->secure_request( 'mainwp_posts_search' );
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $post_type = ( isset( $_POST['post_type'] ) && 0 < strlen( sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) ) ? sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) : 'post' );
        if ( isset( $_POST['maximum'] ) ) {
            MainWP_Utility::update_option( 'mainwp_maximumPosts', isset( $_POST['maximum'] ) ? intval( $_POST['maximum'] ) : 50 );
        }
        $keyword            = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';
        $dtsstart           = isset( $_POST['dtsstart'] ) ? sanitize_text_field( trim( wp_unslash( $_POST['dtsstart'] ) ) ) : '';
        $dtsstop            = isset( $_POST['dtsstop'] ) ? sanitize_text_field( trim( wp_unslash( $_POST['dtsstop'] ) ) ) : '';
        $status             = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
        $groups             = isset( $_POST['groups'] ) && is_array( $_POST['groups'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['groups'] ) ) : '';
        $sites              = isset( $_POST['sites'] ) && is_array( $_POST['sites'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['sites'] ) ) : '';
        $clients            = isset( $_POST['clients'] ) && is_array( $_POST['clients'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['clients'] ) ) : '';
        $postId             = isset( $_POST['postId'] ) ? sanitize_text_field( wp_unslash( $_POST['postId'] ) ) : '';
        $userId             = isset( $_POST['userId'] ) ? sanitize_text_field( wp_unslash( $_POST['userId'] ) ) : '';
        $search_on          = isset( $_POST['search_on'] ) ? sanitize_text_field( wp_unslash( $_POST['search_on'] ) ) : '';
        $table_content_only = isset( $_POST['table_content'] ) && wp_unslash( $_POST['table_content'] ) ? true : false;
        // phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        MainWP_Cache::init_session();
        if ( $table_content_only ) {
            MainWP_Post::render_table_body( $keyword, $dtsstart, $dtsstop, $status, $groups, $sites, $postId, $userId, $post_type, $search_on, true, $clients );
        } else {
            $params = array(
                'groups'    => $groups,
                'sites'     => $sites,
                'postId'    => $postId,
                'userId'    => $userId,
                'post_type' => $post_type,
                'search_on' => $search_on,
                'clients'   => $clients,
            );
            MainWP_Post::render_table( false, $keyword, $dtsstart, $dtsstop, $status, $params );
        }
        die();
    }
    /**
     * Method mainwp_pages_search()
     *
     * Search Post handler,
     * Page: Pages.
     *
     * @uses \MainWP\Dashboard\MainWP_Cache::init_session()
     * @uses \MainWP\Dashboard\MainWP_Page::render_table()
     *
     * @uses  \MainWP\Dashboard\MainWP_Utility::update_option()
     */
    public function mainwp_pages_search() {
        $this->secure_request( 'mainwp_pages_search' );
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( isset( $_POST['maximum'] ) ) {
            MainWP_Utility::update_option( 'mainwp_maximumPages', intval( $_POST['maximum'] ) ? intval( $_POST['maximum'] ) : 50 );
        }
        $keyword   = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';
        $dtsstart  = isset( $_POST['dtsstart'] ) ? sanitize_text_field( wp_unslash( $_POST['dtsstart'] ) ) : '';
        $dtsstop   = isset( $_POST['dtsstop'] ) ? sanitize_text_field( wp_unslash( $_POST['dtsstop'] ) ) : '';
        $status    = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
        $groups    = isset( $_POST['groups'] ) && is_array( $_POST['groups'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['groups'] ) ) : '';
        $sites     = isset( $_POST['sites'] ) && is_array( $_POST['sites'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['sites'] ) ) : '';
        $clients   = isset( $_POST['clients'] ) && is_array( $_POST['clients'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['clients'] ) ) : '';
        $search_on = isset( $_POST['search_on'] ) ? sanitize_text_field( wp_unslash( $_POST['search_on'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        MainWP_Cache::init_session();

        $params = array(
            'groups'    => $groups,
            'sites'     => $sites,
            'search_on' => $search_on,
            'clients'   => $clients,
        );

        MainWP_Page::render_table( false, $keyword, $dtsstart, $dtsstop, $status, $params );
        die();
    }

    /**
     * Method mainwp_get_categories()
     *
     * Get post/page categories.
     *
     * @uses \MainWP\Dashboard\MainWP_Post_Page_Handler::get_categories()
     */
    public function mainwp_get_categories() {
        $this->secure_request( 'mainwp_get_categories' );
        MainWP_Post_Page_Handler::ajax_handle_get_categories();
        die();
    }

    /**
     * Method mainwp_post_get_edit()
     *
     * Get post to edit.
     *
     * @uses \MainWP\Dashboard\MainWP_Post_Page_Handler::get_post()
     */
    public function mainwp_post_get_edit() {
        $this->secure_request( 'mainwp_post_get_edit' );
        MainWP_Post_Page_Handler::get_post();
        die();
    }

    /**
     * Method mainwp_user_delete()
     *
     * Delete User from Child Site,
     * Page: Users.
     *
     * @uses \MainWP\Dashboard\MainWP_User::delete()
     */
    public function mainwp_user_delete() {
        $this->secure_request( 'mainwp_user_delete' );
        MainWP_User::delete();
    }

    /**
     * Method mainwp_user_edit()
     *
     * Edit User from Child Site,
     * Page: Users.
     *
     * @uses \MainWP\Dashboard\MainWP_User::edit()
     */
    public function mainwp_user_edit() {
        $this->secure_request( 'mainwp_user_edit' );
        MainWP_User::edit();
    }

    /**
     * Method mainwp_user_update_password(
     *
     * Update User password from Child Site,
     * Page: Users.
     *
     * @uses \MainWP\Dashboard\MainWP_User::update_password()
     */
    public function mainwp_user_update_password() {
        $this->secure_request( 'mainwp_user_update_password' );
        MainWP_User::update_password();
    }

    /**
     * Method mainwp_user_update_user()
     *
     * Update User from Child Site,
     * Page: Users.
     *
     * @uses \MainWP\Dashboard\MainWP_User::update_user()
     */
    public function mainwp_user_update_user() {
        $this->secure_request( 'mainwp_user_update_user' );
        MainWP_User::update_user();
    }

    /**
     * Method mainwp_post_unpublish()
     *
     * Unpublish post from Child Site,
     * Page: Recent Posts.
     *
     * @uses \MainWP\Dashboard\MainWP_Recent_Posts::unpublish()
     */
    public function mainwp_post_unpublish() {
        $this->secure_request( 'mainwp_post_unpublish' );
        MainWP_Recent_Posts::unpublish();
    }

    /**
     * Method mainwp_post_publish()
     *
     * Publish post on Child Site,
     * Page: Recent Posts.
     *
     * @uses \MainWP\Dashboard\MainWP_Recent_Posts::publish()
     */
    public function mainwp_post_publish() {
        $this->secure_request( 'mainwp_post_publish' );
        MainWP_Recent_Posts::publish();
    }

    /**
     * Method mainwp_post_approve()
     *
     * Approve post on Child Site,
     * Page: Recent Posts.
     *
     * @uses \MainWP\Dashboard\MainWP_Recent_Posts::approve()
     */
    public function mainwp_post_approve() {
        $this->secure_request( 'mainwp_post_approve' );
        MainWP_Recent_Posts::approve();
    }

    /**
     * Method mainwp_post_trash()
     *
     * Trash post on Child Site,
     * Page: Recent Posts.
     *
     * @uses \MainWP\Dashboard\MainWP_Recent_Posts::trash()
     */
    public function mainwp_post_trash() {
        $this->secure_request( 'mainwp_post_trash' );

        MainWP_Recent_Posts::trash();
    }

    /**
     * Method mainwp_post_delete()
     *
     * Delete post on Child Site,
     * Page: Recent Posts.
     *
     * @uses \MainWP\Dashboard\MainWP_Recent_Posts::delete()
     */
    public function mainwp_post_delete() {
        $this->secure_request( 'mainwp_post_delete' );

        MainWP_Recent_Posts::delete();
    }

    /**
     * Method mainwp_post_restore()
     *
     * Restore post,
     * Page: Recent Posts.
     *
     * @uses \MainWP\Dashboard\MainWP_Recent_Posts::restore()
     */
    public function mainwp_post_restore() {
        $this->secure_request( 'mainwp_post_restore' );

        MainWP_Recent_Posts::restore();
    }

    /**
     * Method mainwp_page_unpublish()
     *
     * Unpublish page,
     * Page: Recent Pages.
     *
     * @uses \MainWP\Dashboard\MainWP_Page::unpublish()
     */
    public function mainwp_page_unpublish() {
        $this->secure_request( 'mainwp_page_unpublish' );
        MainWP_Page::unpublish();
    }

    /**
     * Method mainwp_page_publish()
     *
     * Publish page,
     * Page: Recent Pages.
     *
     * @uses \MainWP\Dashboard\MainWP_Page::publish()
     */
    public function mainwp_page_publish() {
        $this->secure_request( 'mainwp_page_publish' );
        MainWP_Page::publish();
    }

    /**
     * Method mainwp_page_trash()
     *
     * Trash page,
     * Page: Recent Pages.
     *
     * @uses \MainWP\Dashboard\MainWP_Page::trash()
     */
    public function mainwp_page_trash() {
        $this->secure_request( 'mainwp_page_trash' );
        MainWP_Page::trash();
    }

    /**
     * Method mainwp_page_delete()
     *
     * Delete page,
     * Page: Recent Pages.
     *
     * @uses \MainWP\Dashboard\MainWP_Page::delete()
     */
    public function mainwp_page_delete() {
        $this->secure_request( 'mainwp_page_delete' );
        MainWP_Page::delete();
    }

    /**
     * Method mainwp_page_restor()
     *
     * Restore page,
     * Page: Recent Pages.
     *
     * @uses \MainWP\Dashboard\MainWP_Page::restore()
     */
    public function mainwp_page_restore() {
        $this->secure_request( 'mainwp_page_restore' );
        MainWP_Page::restore();
    }

    /**
     * Method mainwp_notice_status_update()
     *
     * Hide after installation notices,
     * (PHP version, Trust MainWP Child, Multisite Warning and OpenSSL warning).
     *
     * @uses  \MainWP\Dashboard\MainWP_Utility::update_option()
     */
    public function mainwp_notice_status_update() {
        $this->secure_request( 'mainwp_notice_status_update' );

        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $no_id = isset( $_POST['notice_id'] ) ? sanitize_text_field( wp_unslash( $_POST['notice_id'] ) ) : false;
        if ( 'mail_failed' === $no_id ) {
            MainWP_Utility::update_option( 'mainwp_notice_wp_mail_failed', 'hide' );
            die( 'ok' );
        }

        if ( 'phpver_8_0' === $no_id ) {
            MainWP_Utility::update_user_option( 'lasttime_hidden_phpver_8_0', time() );
        }

        $time_set = isset( $_POST['time_set'] ) && 1 === intval( $_POST['time_set'] ) ? true : false;
        $this->hide_dashboard_notice_status( $no_id, $time_set );
        // phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        die( 1 );
    }

    /**
     * Method hide dashboard notice status.
     *
     * @param  mixed $no_id notice id.
     * @param  mixed $time_set time.
     * @param  bool  $hide_noti hide notice.
     * @return void
     */
    public function hide_dashboard_notice_status( $no_id, $time_set = false, $hide_noti = true ) { //phpcs:ignore -- NOSONAR - complexity.
        /**
         * Current user global.
         *
         * @global string
         */
        global $current_user;
        $user_id = $current_user->ID;
        if ( $user_id ) {
            $status = get_user_option( 'mainwp_notice_saved_status' );
            if ( ! is_array( $status ) ) {
                $status = array();
            }
            if ( ! empty( $no_id ) ) {
                if ( 'mainwp_secure_priv_key_notice' === $no_id ) {
                    MainWP_Utility::update_option( 'mainwp_not_start_encrypt_keys', 1 ); // to show the button.
                }
                if ( $hide_noti ) {
                    if ( $time_set ) {
                        $status[ $no_id ] = time();
                    } else {
                        $status[ $no_id ] = 1;
                    }
                } elseif ( ! empty( $status[ $no_id ] ) ) {
                    unset( $status[ $no_id ] );
                }
                update_user_option( $user_id, 'mainwp_notice_saved_status', $status );
            }
        }
    }

    /**
     * Method mainwp_status_saving()
     *
     * Save last_sync_sites time().
     */
    public function mainwp_status_saving() { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR - complexity.
        $this->secure_request( 'mainwp_status_saving' );
        $values = get_option( 'mainwp_status_saved_values' );

        if ( ! is_array( $values ) ) {
            $values = array();
        }

        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( ! isset( $_POST['status'] ) ) {
            die( -1 );
        }

        if ( 'last_sync_sites' === $_POST['status'] ) {
            if ( isset( $_POST['isGlobalSync'] ) && ! empty( $_POST['isGlobalSync'] ) ) {
                update_option( 'mainwp_last_synced_all_sites', time() );

                /**
                 * Action: mainwp_synced_all_sites
                 *
                 * Fires upon successfull synchronization process.
                 *
                 * @since 3.5.1
                 */
                do_action( 'mainwp_synced_all_sites' );
            }
            die( 'ok' );
        }

        $status = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
        $value  = isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if ( ! empty( $status ) ) {
            if ( empty( $value ) ) {
                if ( isset( $values[ $status ] ) ) {
                    unset( $values[ $status ] );
                }
            } else {
                    $values[ $status ] = $value;
            }
            update_option( 'mainwp_status_saved_values', $values );
        }

        die( 'ok' );
    }

    /**
     * Method ajax_widget_order()
     *
     * Update saved widget order.
     */
    public function ajax_widgets_order() { //phpcs:ignore -- NOSONAR - complex.

        $this->secure_request( 'mainwp_widgets_order' );
        $user = wp_get_current_user();
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( $user && ! empty( $_POST['page'] ) ) {
            $page  = isset( $_POST['page'] ) ? sanitize_text_field( wp_unslash( $_POST['page'] ) ) : '';
            $order = isset( $_POST['order'] ) ? sanitize_text_field( wp_unslash( $_POST['order'] ) ) : '';
            $wgids = isset( $_POST['wgids'] ) ? sanitize_text_field( wp_unslash( $_POST['wgids'] ) ) : '';

            $wgs_orders = array();

            if ( ! empty( $wgids ) ) {
                $wgids = json_decode( $wgids, true );
                $order = json_decode( $order, true );
                if ( is_array( $wgids ) && is_array( $order ) ) {
                    foreach ( $wgids as $idx => $wgid ) {
                        if ( isset( $order[ $idx ] ) ) {
                            $pre = 'widget-'; // #compatible-widgetid.
                            if ( 0 === strpos( $wgid, $pre ) ) {
                                $wgid = substr( $wgid, strlen( $pre ) );
                            }
                            $wgs_orders[ $wgid ] = $order[ $idx ];
                        }
                    }
                }
            }

            if ( 'mainwp_page_manageclients' === $page ) {
                $item_id      = isset( $_POST['item_id'] ) ? intval( $_POST['item_id'] ) : 0;
                $sorted_array = get_user_option( 'mainwp_widgets_sorted_' . strtolower( $page ) );
                if ( ! empty( $sorted_array ) ) {
                    $sorted_array = json_decode( $sorted_array, true );
                }
                if ( ! is_array( $sorted_array ) ) {
                    $sorted_array = array();
                }
                $sorted_array[ $item_id ] = $wgs_orders;
                update_user_option( $user->ID, 'mainwp_widgets_sorted_' . $page, wp_json_encode( $sorted_array ), true );
            } else {
                update_user_option( $user->ID, 'mainwp_widgets_sorted_' . $page, wp_json_encode( $wgs_orders ), true );
            }
            die( 'ok' );
        }
        // phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        die( -1 );
    }

    /**
     * Method ajax_mainwp_save_settings()
     *
     * Update saved MainWP Settings.
     *
     * @uses  \MainWP\Dashboard\MainWP_Utility::update_option()
     */
    public function ajax_mainwp_save_settings() {
        $this->secure_request( 'mainwp_save_settings' );
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        if ( ! empty( $name ) ) {
            $option_name = 'mainwp_' . $name;
            $val         = isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : '';
            MainWP_Utility::update_option( $option_name, $val );
        }
        // phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        die( 'ok' );
    }

    /**
     * Method ajax_guided_tours_option_update()
     *
     * Update saved MainWP Settings.
     *
     * @uses  \MainWP\Dashboard\MainWP_Utility::update_option()
     */
    public function ajax_guided_tours_option_update() {
        $this->secure_request( 'mainwp_guided_tours_option_update' );
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $enable = isset( $_POST['enable'] ) ? intval( $_POST['enable'] ) : 0;
        // phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        MainWP_Utility::update_option( 'mainwp_enable_guided_tours', $enable );
        die( 'ok ' . intval( $enable ) );
    }

    /**
     * Method mainwp_help_modal_content_update()
     *
     * Update saved MainWP Settings.
     *
     * @uses  \MainWP\Dashboard\MainWP_Utility::update_option()
     */
    public function ajax_mainwp_help_modal_content_update() {
        $this->secure_request( 'mainwp_help_modal_content_update' );
        MainWP_Utility::update_option( 'mainwp_enable_guided_tours', ( int)$_POST['enable_tour'] ); //phpcs:ignore -- NOSONAR verify.
        MainWP_Utility::update_option( 'mainwp_enable_guided_video', (int)$_POST['enable_video'] ); //phpcs:ignore -- NOSONAR verify.
        MainWP_Utility::update_option( 'mainwp_enable_guided_chatbase', (int)$_POST['enable_chatbase'] ); //phpcs:ignore -- NOSONAR verify.
        die( 'ok' );
    }

    /**
     * Method mainwp_leftmenu_filter_group()
     *
     * MainWP left menu filter by group.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::query()
     * @uses \MainWP\Dashboard\MainWP_DB::get_websites_by_group_id()
     * @uses \MainWP\Dashboard\MainWP_DB::fetch_object()
     * @uses \MainWP\Dashboard\MainWP_DB::free_result()
     */
    public function mainwp_leftmenu_filter_group() {
        $this->secure_request( 'mainwp_leftmenu_filter_group' );

        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $gid = isset( $_POST['group_id'] ) ? intval( $_POST['group_id'] ) : false;
        // phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if ( ! empty( $gid ) ) {
            $ids      = '';
            $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_by_group_id( $gid, true ) );
            while ( $websites && ( $website  = MainWP_DB::fetch_object( $websites ) ) ) {
                $ids .= $website->id . ',';
            }
            MainWP_DB::free_result( $websites );
            $ids = rtrim( $ids, ',' );
            die( $ids ); // phpcs:ignore WordPress.Security.EscapeOutput
        }
        die( '' );
    }

    /**
     * Method dismiss_activate_notice()
     *
     * Dismiss activate notice.
     */
    public function dismiss_activate_notice() {
        $this->secure_request( 'mainwp_dismiss_activate_notice' );

        /**
         * Current user global.
         *
         * @global string
         */
        global $current_user;
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $user_id = $current_user->ID;
        $slug    = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
        if ( $user_id && ! empty( $slug ) ) {
            $activate_notices = get_user_option( 'mainwp_hide_activate_notices' );
            if ( ! is_array( $activate_notices ) ) {
                $activate_notices = array();
            }

            $activate_notices[ $slug ] = time();
            update_user_option( $user_id, 'mainwp_hide_activate_notices', $activate_notices );
        }
         // phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        die( 1 );
    }



    /**
     * Method mainwp_security_issues_request()
     *
     * Post handler for,
     * Page: SecurityIssues.
     *
     * @uses \MainWP\Dashboard\MainWP_Exception
     * @uses \MainWP\Dashboard\MainWP_Security_Issues::fetch_security_issues()
     */
    public function mainwp_security_issues_request() {
        $this->secure_request( 'mainwp_security_issues_request' );

        try {
            wp_send_json( array( 'result' => MainWP_Security_Issues::fetch_security_issues() ) );
        } catch ( MainWP_Exception $e ) {
            die(
                wp_json_encode(
                    array(
                        'error' => array(
                            'message' => $e->getMessage(),
                            'extra'   => $e->get_message_extra(),
                        ),
                    )
                )
            );
        }
    }

    /**
     * Method  mainwp_security_issues_fix()
     *
     * Post handler for 'fix issues',
     * Page: SecurityIssues.
     *
     * @uses \MainWP\Dashboard\MainWP_Exception
     * @uses \MainWP\Dashboard\MainWP_Security_Issues::fix_security_issue()
     */
    public function mainwp_security_issues_fix() {
        $this->secure_request( 'mainwp_security_issues_fix' );

        try {
            wp_send_json( array( 'result' => MainWP_Security_Issues::fix_security_issue() ) );
        } catch ( MainWP_Exception $e ) {
            die(
                wp_json_encode(
                    array(
                        'error' => array(
                            'message' => $e->getMessage(),
                            'extra'   => $e->get_message_extra(),
                        ),
                    )
                )
            );
        }
    }

    /**
     * Method  mainwp_security_issues_unfix()
     *
     * Post handler for 'unfix issues',
     * Page: SecurityIssues.
     *
     * @uses \MainWP\Dashboard\MainWP_Exception
     * @uses \MainWP\Dashboard\MainWP_Security_Issues::unfix_security_issue()
     */
    public function mainwp_security_issues_unfix() {
        $this->secure_request( 'mainwp_security_issues_unfix' );

        try {
            wp_send_json( array( 'result' => MainWP_Security_Issues::unfix_security_issue() ) );
        } catch ( MainWP_Exception $e ) {
            die(
                wp_json_encode(
                    array(
                        'error' => array(
                            'message' => $e->getMessage(),
                            'extra'   => $e->get_message_extra(),
                        ),
                    )
                )
            );
        }
    }

    /**
     * Method ajax_disconnect_site()
     *
     * Disconnect Child Site.
     *
     * @uses \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     */
    public function ajax_disconnect_site() {
        $this->secure_request( 'mainwp_disconnect_site' );
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $siteid = isset( $_POST['wp_id'] ) ? intval( $_POST['wp_id'] ) : 0;
         // phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if ( empty( $siteid ) ) {
            die( wp_json_encode( array( 'error' => 'Error: site id empty' ) ) );
        }

        $website = MainWP_DB::instance()->get_website_by_id( $siteid );

        if ( ! $website ) {
            die( wp_json_encode( array( 'error' => 'Not found site' ) ) );
        }

        try {
            $information = MainWP_Connect::fetch_url_authed( $website, 'disconnect' );
        } catch ( \Exception $e ) {
            $information = array( 'error' => esc_html__( 'fetch_url_authed exception', 'mainwp' ) );
        }

        wp_send_json( $information );
    }

    /**
     * Method ajax_sites_display_rows()
     *
     * Display rows via ajax,
     * Page: Manage Sites.
     *
     * @uses \MainWP\Dashboard\MainWP_Manage_Sites::ajax_optimize_display_rows()
     */
    public function ajax_sites_display_rows() {
        $this->secure_request( 'mainwp_manage_sites_display_rows' );
        MainWP_Manage_Sites::ajax_optimize_display_rows();
    }

    /**
     * Method ajax_monitoring_display_rows()
     *
     * Display rows via ajax,
     * Page: Monitoring Sites.
     *
     * @uses \MainWP\Dashboard\MainWP_Monitoring::ajax_optimize_display_rows()
     */
    public function ajax_monitoring_display_rows() {
        $this->secure_request( 'mainwp_monitoring_sites_display_rows' );
        MainWP_Monitoring::ajax_optimize_display_rows();
    }


    /**
     * Method mainwp_bulkadduser()
     *
     * Bulk Add User for,
     * Page: BulkAddUser.
     *
     * @uses \MainWP\Dashboard\MainWP_User::do_bulk_add()
     */
    public function mainwp_bulkadduser() {
        $this->check_security( 'mainwp_bulkadduser' );
        MainWP_User::do_bulk_add();
        die();
    }

    /**
     * Method mainwp_importuser()
     *
     * Import user.
     *
     * @uses \MainWP\Dashboard\MainWP_User::do_import()
     */
    public function mainwp_importuser() {
        $this->secure_request( 'mainwp_importuser' );
        MainWP_User::do_import();
    }

    /**
     * Method mainwp_clients_add_client()
     *
     * Add Client for,
     * Page: BulkAddClient.
     */
    public function mainwp_clients_add_client() {
        $this->check_security( 'mainwp_clients_add_client' );
        MainWP_Client::add_client();
        die();
    }

    /**
     * Method mainwp_clients_delete_client()
     *
     * Add Client for,
     * Page: BulkAddClient.
     */
    public function mainwp_clients_delete_client() {
        $this->check_security( 'mainwp_clients_delete_client' );
        $ret = array( 'success' => false );
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $client_id = isset( $_POST['clientid'] ) ? intval( $_POST['clientid'] ) : 0;
        // phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( $client_id ) {
            MainWP_DB_Client::instance()->delete_client( $client_id );
            $ret['success'] = 'SUCCESS';
            $ret['result']  = esc_html__( 'Client removed successfully.', 'mainwp' );
        } else {
            $ret['result'] = esc_html__( 'Client ID empty.', 'mainwp' );
        }

        echo wp_json_encode( $ret );
        exit;
    }

    /**
     * Method mainwp_clients_save_field()
     *
     * Save client custom fields.
     */
    public function mainwp_clients_save_field() {
        $this->check_security( 'mainwp_clients_save_field' );
        MainWP_Client::save_client_field();
        die();
    }


    /**
     * Method mainwp_clients_delete_general_field()
     *
     * Delete client general fields.
     */
    public function mainwp_clients_delete_general_field() {
        $this->check_security( 'mainwp_clients_delete_general_field' );
        $ret = array( 'success' => false );
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $field_id  = isset( $_POST['field_id'] ) ? intval( $_POST['field_id'] ) : 0;
        $client_id = 0; // 0 general fields.
        if ( MainWP_DB_Client::instance()->delete_client_field_by( 'field_id', $field_id, $client_id ) ) {
            $ret['success'] = true;
        }
        // phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        echo wp_json_encode( $ret );
        exit;
    }

    /**
     * Method mainwp_clients_delete_field()
     *
     * Delete client custom fields.
     */
    public function mainwp_clients_delete_field() {
        $this->check_security( 'mainwp_clients_delete_field' );
        $ret = array( 'success' => false );
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $field_id  = isset( $_POST['field_id'] ) ? intval( $_POST['field_id'] ) : 0;
        $client_id = isset( $_POST['client_id'] ) ? intval( $_POST['client_id'] ) : 0;  // $client_id > 0, individual token.
        // phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( $client_id && MainWP_DB_Client::instance()->delete_client_field_by( 'field_id', $field_id, $client_id ) ) {
            $ret['success'] = true;
        }
        echo wp_json_encode( $ret );
        exit;
    }

    /**
     * Method mainwp_notes_save()
     *
     * Post handler for save notes on,
     * Page: Manage Sites.
     *
     * @uses \MainWP\Dashboard\MainWP_Manage_Sites_Handler::save_note()
     */
    public function mainwp_notes_save() {
        $this->secure_request( 'mainwp_notes_save' );
        MainWP_Manage_Sites_Handler::save_note();
    }

    /**
     * Method mainwp_clients_notes_save()
     *
     * Post handler for save notes on,
     * Page: Manage Sites.
     *
     * @uses \MainWP\Dashboard\MainWP_Manage_Sites_Handler::save_note()
     */
    public function mainwp_clients_notes_save() {
        $this->secure_request( 'mainwp_clients_notes_save' );
        MainWP_Client::save_note();
    }

    /**
     * Method mainwp_clients_suspend_client()
     *
     * Post handler for suspend client,
     * Page: Manage Sites.
     *
     * @uses \MainWP\Dashboard\MainWP_Manage_Sites_Handler::save_note()
     */
    public function mainwp_clients_suspend_client() {
        $this->secure_request( 'mainwp_clients_suspend_client' );
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $clientid  = isset( $_POST['clientid'] ) ? intval( $_POST['clientid'] ) : 0;
        $suspended = isset( $_POST['suspend_status'] ) && ! empty( $_POST['suspend_status'] ) ? 1 : 0;
        // phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if ( empty( $clientid ) ) {
            wp_die( 'Error - empty client id!' );
        }

        $params = array(
            'client_id' => $clientid,
            'suspended' => $suspended,
        );

        MainWP_DB_Client::instance()->update_client( $params );

        $client = MainWP_DB_Client::instance()->get_wp_client_by( 'client_id', $clientid );

        /**
         * Fires immediately after update client suspend/unsuspend.
         *
         * @since 4.5.1.1
         *
         * @param object $client  client data.
         * @param bool $suspended true|false.
         */
        do_action( 'mainwp_client_suspend', $client, $suspended );

        MainWP_DB_Client::instance()->suspend_unsuspend_websites_by_client_id( $clientid, $suspended );

        wp_die( 'success' );
    }

    /**
     * Method mainwp_clients_check_client()
     *
     * The client check has been created in the system yet.
     *
     * @uses \MainWP_DB_Client::instance()->get_wp_client_by()
     */
    public function mainwp_clients_check_client() {
        $this->secure_request( 'mainwp_clients_check_client' );
        // phpcs:disable WordPress.Security.NonceVerification
        $client_email = isset( $_POST['email'] ) ? sanitize_text_field( wp_unslash( $_POST['email'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification
        $client_existed = MainWP_DB_Client::instance()->get_wp_client_by( 'client_email', $client_email, ARRAY_A );
        if ( is_array( $client_existed ) && isset( $client_existed['client_id'] ) ) {
            return wp_send_json_error( array( 'error' => esc_html__( 'Client email exists. Please try again.', 'mainwp' ) ) );
        }
        return wp_send_json_success( array( 'result' => esc_html__( 'Client email not exists.', 'mainwp' ) ) );
    }

    /**
     * Method mainwp_clients_import_client()
     *
     * Import new client
     *
     * @uses \MainWP_DB::instance()->get_websites_by_url()
     * @uses \MainWP_Client_Handler::get_default_client_fields()
     * @uses \MainWP_DB_Client::instance()->update_client()
     * @uses \MainWP_DB_Client::instance()->update_selected_sites_for_client()
     */
    public function mainwp_clients_import_client() {  // phpcs:ignore -- NOSONAR
        $this->secure_request( 'mainwp_clients_import_client' );
        // phpcs:disable
        // Set value from POST data.
        $default_values = array(
            'client.name'              => ! empty( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
            'client.email'             => ! empty( $_POST['email'] ) ? sanitize_text_field( wp_unslash( $_POST['email'] ) ) : '',
            'client.contact.address.1' => ! empty( $_POST['address_1'] ) ? sanitize_text_field( wp_unslash( $_POST['address_1'] ) ) : '',
            'client.contact.address.2' => ! empty( $_POST['address_2'] ) ? sanitize_text_field( wp_unslash( $_POST['address_2'] ) ) : '',
            'client.city'              => ! empty( $_POST['city'] ) ? sanitize_text_field( wp_unslash( $_POST['city'] ) ) : '',
            'client.state'             => ! empty( $_POST['state'] ) ? sanitize_text_field( wp_unslash( $_POST['state'] ) ) : '',
            'client.zip'               => ! empty( $_POST['zip'] ) ? sanitize_text_field( wp_unslash( $_POST['zip'] ) ) : '',
            'client.country'           => ! empty( $_POST['country'] ) ? sanitize_text_field( wp_unslash( $_POST['country'] ) ) : '',
            'client.suspended'         => ! empty( $_POST['suspended'] ) ? sanitize_text_field( wp_unslash( $_POST['suspended'] ) ) : 0,
        );
        // Get Site id.
        $selected_sites = array();
        if ( ! empty( $_POST['urls'] ) ) {
            $urls           = array_map( 'sanitize_text_field', wp_unslash( $_POST['urls'] ) );
            $selected_sites = array_filter(
                array_map(
                    function ( $v_url ) {
                            $url     = esc_url( $v_url );
                            $website = MainWP_DB::instance()->get_websites_by_url( $url );
                            return ! empty( $website ) ? current( $website )->id : null;
                    },
                    $urls
                )
            );
        }
        // phpcs:enable
        $client_to_add         = array();
        $default_client_fields = MainWP_Client_Handler::get_default_client_fields(); // Get client field database.
        foreach ( $default_values as $key_client => $val_client ) {
            if ( isset( $default_client_fields[ $key_client ] ) && ! empty( $default_client_fields[ $key_client ]['db_field'] ) && ! empty( $val_client ) ) {
                $client_to_add[ $default_client_fields[ $key_client ]['db_field'] ] = $val_client; // Assign data to customers according to DB Field.
            }
        }

        if ( ! empty( $client_to_add ) ) {
            // Set default values.
            $client_to_add['created']            = time();
            $client_to_add['primary_contact_id'] = 0;
            // Inset client.
            $inserted = MainWP_DB_Client::instance()->update_client( $client_to_add, true );

            if ( ! empty( $inserted ) && is_object( $inserted ) ) {
                if ( ! empty( $selected_sites ) ) {
                    MainWP_DB_Client::instance()->update_selected_sites_for_client( $inserted->client_id, $selected_sites ); // Set client to selected sites.
                }
                // Set default color and icon .
                $update = array(
                    'client_id'          => $inserted->client_id,
                    'selected_icon_info' => 'selected:wordpress;color:#34424d',
                );
                MainWP_DB_Client::instance()->update_client( $update ); // Update Client.

                return wp_send_json_success(
                    array(
                        'message' => esc_html__( 'successful client.', 'mainwp' ),
                        'client'  => $inserted,
                    )
                );
            }
            return wp_send_json_error(
                array(
                    'message' => esc_html__( 'unsuccessful client.', 'mainwp' ),
                )
            );
        }
        return wp_send_json_error(
            array(
                'message' => esc_html__( 'Error, please try again later.', 'mainwp' ),
            )
        );
    }

    /**
     * Method mainwp_syncerrors_dismiss()
     *
     * Dismiss Sync errors for,
     * Widget: RightNow.
     *
     * @uses \MainWP\Dashboard\MainWP_Updates_Overview::dismiss_sync_errors()
     */
    public function mainwp_syncerrors_dismiss() {

        $this->secure_request( 'mainwp_syncerrors_dismiss' );

        try {
            die( wp_json_encode( array( 'result' => MainWP_Updates_Overview::dismiss_sync_errors() ) ) );
        } catch ( \Exception $e ) {
            die( wp_json_encode( array( 'error' => sanitize_text_field( $e->getMessage() ) ) ) );
        }
    }

    /**
     * Method mainwp_events_notice_hide()
     *
     * Hide events notice.
     */
    public function mainwp_events_notice_hide() {
        $this->secure_request( 'mainwp_events_notice_hide' );
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( isset( $_POST['notice'] ) ) {
            $current_options = get_option( 'mainwp_showhide_events_notice' );
            if ( ! is_array( $current_options ) ) {
                $current_options = array();
            }
            if ( 'first_site' === $_POST['notice'] ) {
                update_option( 'mainwp_first_site_events_notice', '' );
            } elseif ( 'request_reviews1' === $_POST['notice'] ) {
                $current_options['request_reviews1']           = 15;
                $current_options['request_reviews1_starttime'] = time();
            } elseif ( 'request_reviews1_forever' === $_POST['notice'] || 'request_reviews2_forever' === $_POST['notice'] ) {
                $current_options['request_reviews1'] = 'forever';
                $current_options['request_reviews2'] = 'forever';
            } elseif ( 'request_reviews2' === $_POST['notice'] ) {
                $current_options['request_reviews2']           = 15;
                $current_options['request_reviews2_starttime'] = time();
            } elseif ( 'trust_child' === $_POST['notice'] ) {
                $current_options['trust_child'] = 1;
            } elseif ( 'multi_site' === $_POST['notice'] ) {
                $current_options['hide_multi_site_notice'] = 1;
            }
            update_option( 'mainwp_showhide_events_notice', $current_options );
        }
        // phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        die( 'ok' );
    }

    /**
     * Method mainwp_showhide_sections()
     *
     * Show/Hide sections.
     */
    public function mainwp_showhide_sections() {
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( isset( $_POST['sec'] ) && isset( $_POST['status'] ) ) {
            $opts = get_option( 'mainwp_opts_showhide_sections' );
            if ( ! is_array( $opts ) ) {
                $opts = array();
            }
            $opts[ sanitize_text_field( wp_unslash( $_POST['sec'] ) ) ] = sanitize_text_field( wp_unslash( $_POST['status'] ) );
            update_option( 'mainwp_opts_showhide_sections', $opts );
            die( 'ok' );
        }
        // phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        die( 'failed' );
    }

    /**
     * Method mainwp_saving_status()
     *
     * MainWP Saving Status.
     */
    public function mainwp_saving_status() {
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( ! isset( $_REQUEST['nonce'] ) || ! wp_verify_nonce( sanitize_key( $_REQUEST['nonce'] ), 'mainwp_ajax' ) ) {
            die( esc_html__( 'WP nonce could not be verified. Please reload the page and try again.', 'mainwp' ) );
        }
        $saving_status = isset( $_POST['saving_status'] ) ? sanitize_text_field( wp_unslash( $_POST['saving_status'] ) ) : false;
        if ( ! empty( $saving_status ) ) {
            $current_options = get_option( 'mainwp_opts_saving_status' );
            if ( ! is_array( $current_options ) ) {
                $current_options = array();
            }
            if ( isset( $_POST['value'] ) ) {
                $current_options[ $saving_status ] = sanitize_text_field( wp_unslash( $_POST['value'] ) );
            }
            update_option( 'mainwp_opts_saving_status', $current_options );
        }
        // phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        die( 'ok' );
    }

    /**
     * Method ajax_recheck_http()
     *
     * Recheck Child Site http status code & message.
     *
     * @uses \MainWP\Dashboard\MainWP_Connect::check_ignored_http_code()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses \MainWP\Dashboard\MainWP_Monitoring_Handler::handle_check_website()
     */
    public function ajax_recheck_http() {
        $this->check_security( 'mainwp_recheck_http' );
         // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( ! isset( $_POST['websiteid'] ) || empty( $_POST['websiteid'] ) ) {
            die( -1 );
        }

        $website = MainWP_DB::instance()->get_website_by_id( intval( $_POST['websiteid'] ) );
        if ( empty( $website ) ) {
            die( -1 );
        }
         // phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        $result       = MainWP_Monitoring_Handler::handle_check_website( $website );
        $http_code    = ( is_array( $result ) && isset( $result['httpCode'] ) ) ? $result['httpCode'] : 0;
        $check_result = MainWP_Connect::check_ignored_http_code( $http_code, $website );

        if ( is_array( $result ) && isset( $result['new_uptime_status'] ) ) {
            $check_result = $result['new_uptime_status'];
        }

        die(
            wp_json_encode(
                array(
                    'httpcode' => esc_html( $http_code ),
                    'status'   => $check_result ? 1 : 0,
                )
            )
        );
    }

    /**
     * Method mainwp_ignore_http_response()
     *
     * Ignore Child Site https response.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses \MainWP\Dashboard\MainWP_DB::update_website_values()
     */
    public function mainwp_ignore_http_response() {
        $this->check_security( 'mainwp_ignore_http_response' );
         // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $siteid = isset( $_POST['websiteid'] ) ? intval( $_POST['websiteid'] ) : false;
         // phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( empty( $siteid ) ) {
            die( -1 );
        }

        $website = MainWP_DB::instance()->get_website_by_id( $siteid );
        if ( empty( $website ) ) {
            die( -1 );
        }

        MainWP_DB::instance()->update_website_values( $website->id, array( 'http_response_code' => '-1' ) );
        die( wp_json_encode( array( 'ok' => 1 ) ) );
    }

    /**
     * Method mainwp_autoupdate_and_trust_child()
     *
     * Set MainWP Child Plugin to Trusted & AutoUpdate.
     *
     * @uses \MainWP\Dashboard\MainWP_Plugins_Handler::trust_plugin()
     */
    public function mainwp_autoupdate_and_trust_child() {
        $this->secure_request( 'mainwp_autoupdate_and_trust_child' );
        if ( 1 !== (int) get_option( 'mainwp_pluginAutomaticDailyUpdate' ) ) {
            update_option( 'mainwp_pluginAutomaticDailyUpdate', 1 );
        }
        MainWP_Plugins_Handler::trust_plugin( 'mainwp-child/mainwp-child.php' );
        die( 'ok' );
    }

    /**
     * Method mainwp_force_destroy_sessions()
     *
     * Force destroy sessions.
     *
     * @uses \MainWP\Dashboard\MainWP_Connect::fetch_url_authed()
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     *
     * @uses \MainWP\Dashboard\MainWP_System_Utility::can_edit_website()
     */
    public function mainwp_force_destroy_sessions() {
        $this->secure_request( 'mainwp_force_destroy_sessions' );

         // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $website_id = ( isset( $_POST['website_id'] ) ? (int) $_POST['website_id'] : 0 );
         // phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( ! MainWP_DB::instance()->get_website_by_id( $website_id ) ) {
            die( wp_json_encode( array( 'error' => array( 'message' => esc_html__( 'This website does not exist.', 'mainwp' ) ) ) ) );
        }

        $website = MainWP_DB::instance()->get_website_by_id( $website_id );
        if ( ! MainWP_System_Utility::can_edit_website( $website ) ) {
            die( wp_json_encode( array( 'error' => array( 'message' => esc_html__( 'You cannot edit this website.', 'mainwp' ) ) ) ) );
        }

        try {
            $information = MainWP_Connect::fetch_url_authed(
                $website,
                'settings_tools',
                array(
                    'action' => 'force_destroy_sessions',
                )
            );
        } catch ( \Exception $e ) {
            $information = array( 'error' => esc_html__( 'fetch_url_authed exception', 'mainwp' ) );
        }

        wp_send_json( $information );
    }

    /**
     * Method ajax_refresh_icon()
     */
    public function ajax_refresh_icon() {
        $this->secure_request( 'mainwp_refresh_icon' );
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $slug = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
        $type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
         // phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if ( empty( $slug ) ) {
            wp_die( 'failed' );
        }

        $fet_icon = MainWP_System_Utility::handle_get_icon( $slug, $type );

        if ( ! empty( $fet_icon ) ) {
            wp_die( 'success' );
        } else {
            wp_die( 'failed' );
        }
    }

    /**
     * Method ajax_upload_custom_icon()
     */
    public function ajax_upload_custom_icon() { //phpcs:ignore -- NOSONAR -complex.
        $this->secure_request( 'mainwp_upload_custom_icon' );
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $slug   = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
        $type   = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
        $delete = isset( $_POST['delete'] ) ? intval( $_POST['delete'] ) : 0;

        if ( empty( $slug ) || ( 'plugin' !== $type && 'theme' !== $type ) ) {
            wp_die( 'Invalid data! Type or empty slug' );
        }

        $sub_folder = '';

        if ( 'plugin' === $type ) {
            $sub_folder = 'plugin-icons';
        } elseif ( 'theme' === $type ) {
            $sub_folder = 'theme-icons';
        } else {
            wp_die( wp_json_encode( array( 'result' => 'invalid' ) ) );
        }

        if ( $delete ) {
            MainWP_System_Utility::update_cached_icons( '', $slug, $type, true );
            wp_die( wp_json_encode( array( 'result' => 'success' ) ) );
        }

        $output = isset( $_FILES['mainwp_upload_icon_uploader'] ) ? MainWP_System_Utility::handle_upload_image( $sub_folder, $_FILES['mainwp_upload_icon_uploader'], 0 ) : null;
        // phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        $uploaded_icon = 'NOTCHANGE';
        if ( is_array( $output ) && isset( $output['filename'] ) && ! empty( $output['filename'] ) ) {
            $uploaded_icon = $output['filename'];
        }

        if ( 'NOTCHANGE' !== $uploaded_icon ) {
            MainWP_System_Utility::update_cached_icons( $uploaded_icon, $slug, $type, true );
            wp_die( wp_json_encode( array( 'result' => 'success' ) ) );
        } else {
            $result = array(
                'result' => 'failed',
            );
            $error  = static::get_upload_icon_error( $output );
            if ( ! empty( $error ) ) {
                $result['error'] = esc_html( $error );
            }
            wp_die( wp_json_encode( $result ) );
        }
    }

    /**
     * Method get_upload_icon_error().
     *
     * @param mixed $results Upload results.
     *
     * @return string
     */
    public static function get_upload_icon_error( $results ) {
        $error = '';
        if ( is_array( $results ) && ! empty( $results['error'] ) && is_array( $results['error'] ) ) {
            $error_code = $results['error'][0];
            switch ( $error_code ) {
                case 3:
                    $error = __( 'File size is too big. Please try a smaller file.', 'mainwp' );
                    break;
                case 4:
                    $error = __( 'File type is not allowed. Please use a different file type.', 'mainwp' );
                    break;
                case 5:
                    $error = __( 'File extension is not allowed. Please use a different file type.', 'mainwp' );
                    break;
                case 6:
                    $error = __( 'Cannot copy file. Please try again.', 'mainwp' );
                    break;
                case 9:
                    $error = __( 'Failed to crop file. Please try again.', 'mainwp' );
                    break;
                default:
                    $error = __( 'Undefined error. Please try again.', 'mainwp' );
                    break;
            }
        }
        return $error;
    }

    /**
     * Method ajax_select_custom_theme()
     */
    public function ajax_select_custom_theme() {
        $this->secure_request( 'mainwp_select_custom_theme' );
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $theme = isset( $_POST['theme'] ) ? sanitize_text_field( wp_unslash( $_POST['theme'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( empty( $theme ) ) {
            wp_die( 'failed' );
        }

        $user = wp_get_current_user();
        if ( empty( $user ) || empty( $user->ID ) ) {
            wp_die( 'failed' );
        }

        update_user_option( $user->ID, 'mainwp_selected_theme', $theme );
        wp_die( 'success' );
    }

    /**
     * Method ajax_import_demo_data().
     */
    public function ajax_import_demo_data() {
        $this->secure_request( 'mainwp_import_demo_data' );
        $data = MainWP_Demo_Handle::get_instance()->import_data_demo();
        MainWP_Utility::update_option( 'mainwp_enable_guided_tours', 1 );
        wp_die( wp_json_encode( $data ) );
    }

    /**
     * Method ajax_delete_demo_data().
     */
    public function ajax_delete_demo_data() {
        $this->secure_request( 'mainwp_delete_demo_data' );
        $data = MainWP_Demo_Handle::get_instance()->delete_data_demo();
        MainWP_Utility::update_option( 'mainwp_enable_guided_tours', 0 );
        wp_die( wp_json_encode( $data ) );
    }

    /**
     *  Method ajax_save_temp_import_website()
     *
     *  Save temp import website.
     *
     * @uses MainWP_DB::instance()->get_general_option()
     * @uses MainWP_DB::instance()->update_general_option(()
     */
    public function ajax_save_temp_import_website() {
        $this->secure_request( 'mainwp_save_temp_import_website' ); // Check secure.
        $error_msg = esc_html__( 'Undefined error. Please try again.', 'mainwp' );
        // Get previously saved temporary data (if any).
        $values = MainWP_DB::instance()->get_general_option( 'temp_import_sites', 'array' );
        $status = $this->mainwp_get_sanitized_post( 'status' );
        $index  = $this->mainwp_get_sanitized_post( 'row_index', 'intval' ) ?? 1;
        if ( empty( $status ) || '' === $index ) {
            wp_die( wp_send_json_error( $error_msg ) ); //phpcs:ignore WordPress.Security.EscapeOutput
        }

        $is_update = false;
        if ( 'delete_temp' === $status ) { // Handling remove temp data.
            $is_update = $this->mainwp_handle_delete_temp_import_website( $values, $index );
        } elseif ( 'save_temp' === $status ) { // Handling save row data.
            // Update row data into array.
            $row_item = array(
                'site_url'           => $this->mainwp_get_sanitized_post( 'site_url' ),
                'admin_name'         => $this->mainwp_get_sanitized_post( 'admin_name' ),
                'admin_password'     => $this->mainwp_get_sanitized_post( 'admin_password' ),
                'site_name'          => $this->mainwp_get_sanitized_post( 'site_name' ),
                'tag'                => $this->mainwp_get_sanitized_post( 'tag' ),
                'security_id'        => $this->mainwp_get_sanitized_post( 'security_id' ),
                'http_username'      => $this->mainwp_get_sanitized_post( 'http_username' ),
                'http_password'      => $this->mainwp_get_sanitized_post( 'http_password' ),
                'verify_certificate' => $this->mainwp_get_sanitized_post( 'verify_certificate', 'intval' ) ?? 1,
                'ssl_version'        => $this->mainwp_get_sanitized_post( 'ssl_version' ) ?? 'auto',
            );

            $is_update = $this->mainwp_handle_save_temp_import_website( $values, $index, $row_item );
        }
        // Save data to options table.
        if ( $is_update ) {
            MainWP_DB::instance()->update_general_option( 'temp_import_sites', $values, 'array' );
            wp_die( wp_send_json_success( esc_html( __( 'Row data saved successfully.', 'mainwp' ) ) ) ); //phpcs:ignore WordPress.Security.EscapeOutput
        }

        // In case of no updates.
        wp_die( wp_send_json_error( esc_html( $error_msg ) ) ); //phpcs:ignore WordPress.Security.EscapeOutput
    }

    /**
     * Method mainwp_handle_delete_temp_import_website()
     *
     * Handling remove temp data.
     *
     * @param mixed $values temp import sites.
     * @param int   $index row key.
     *
     * @return bool true|false.
     */
    private function mainwp_handle_delete_temp_import_website( &$values, $index ) {
        // Check if row data stream exists.
        if ( ! empty( $values ) && is_array( $values ) && array_key_exists( $index, $values ) ) {
            unset( $values[ $index ] ); // Remove row data.
            return true;
        }
        return false;
    }

    /**
     *  Method mainwp_handle_save_temp_import_website()
     *
     * Create value if empty or array key exists.
     *
     * @param mixed $values temp import sites.
     * @param int   $index row key.
     * @param array $row_item new row.
     *
     * @return bool true.
     */
    private function mainwp_handle_save_temp_import_website( &$values, $index, $row_item ) {
        if ( empty( $values ) || ! array_key_exists( $index, $values ) ) {
            $index            = 0 !== $index ? $index : 0;
            $values[ $index ] = $row_item;
        } else {
            $values[ $index ] = $row_item;
        }
        return true;
    }

    /**
     * Method ajax_delete_temp_import_website().
     *
     * Delete data temp if has been added website.
     *
     * @uses MainWP_DB::instance()->get_general_option().
     * @uses MainWP_DB::instance()->update_general_option().
     */
    public function ajax_delete_temp_import_website() {
        $this->secure_request( 'mainwp_delete_temp_import_website' ); // Check secure.
        $error_msg = esc_html__( 'Undefined error. Please try again.', 'mainwp' );
        // Get option temp import sites data.
        $temp_sites = MainWP_DB::instance()->get_general_option( 'temp_import_sites', 'array' );
        if ( empty( $temp_sites ) || ! is_array( $temp_sites ) ) { // Check if temp_sites data exists and is an array.
            wp_die( wp_send_json_error( esc_html( $error_msg ) ) ); //phpcs:ignore WordPress.Security.EscapeOutput
        }

        // Get wpurl, wpadmin from POST.
        $wp_url   = $this->mainwp_get_sanitized_post( 'managesites_add_wpurl' );
        $wp_admin = $this->mainwp_get_sanitized_post( 'managesites_add_wpadmin' );
        if ( empty( $wp_url ) || empty( $wp_admin ) ) { // Check wpurl, wpadmin has data or empty.
            wp_die( wp_send_json_error( esc_html( $error_msg ) ) ); //phpcs:ignore WordPress.Security.EscapeOutput
        }
        $is_delete = false;

        if ( ! empty( $temp_sites ) && is_array( $temp_sites ) ) {
            foreach ( $temp_sites as $k_site => $val_site ) { // Loop through data to remove added website.
                // Check if row data stream exists.
                if ( rtrim( $wp_url, '/' ) === $val_site['site_url'] && $wp_admin === $val_site['admin_name'] ) {
                    // Remove row data.
                    unset( $temp_sites[ $k_site ] );
                    $is_delete = true;
                    break;
                }
            }
        }

        // Save data to options table.
        if ( $is_delete ) {
            MainWP_DB::instance()->update_general_option( 'temp_import_sites', $temp_sites, 'array' );
            wp_die( wp_send_json_success( esc_html__( 'Delete import row successfully.', 'mainwp' ) ) ); //phpcs:ignore WordPress.Security.EscapeOutput
        }

        // In case of no delete.
        wp_die( wp_send_json_error( $error_msg ) ); //phpcs:ignore WordPress.Security.EscapeOutput
    }

    /**
     * Method ajax_import_website_add_client()
     *
     * Create client.
     */
    public function ajax_import_website_add_client() {
        $this->secure_request( 'mainwp_import_website_add_client' ); // Check secure.
        $error_msg = esc_html__( 'Undefined error. Please try again.', 'mainwp' );
        try {
            // Retrieve client data.
            $data    = $this->mainwp_get_sanitized_post( 'client' );
            $site_id = $this->mainwp_get_sanitized_post( 'site_id' );

            if ( empty( $data ) ) {
                wp_die( wp_send_json_error( esc_html( $error_msg ) ) ); //phpcs:ignore WordPress.Security.EscapeOutput
            }

            $client_data = json_decode( $data, true );
            // Create client.
            $client = $this->mainwp_handle_create_client( $client_data );
            if ( $client ) {
                // Add groups website and client.
                if ( ! empty( $site_id ) ) {
                    $this->mainwp_handle_selected_sites_for_client( $client, $site_id );
                }

                wp_die( wp_send_json_success( esc_html__( 'Created client successfully.', 'mainwp' ) ) ); //phpcs:ignore WordPress.Security.EscapeOutput
            }
        } catch ( \Exception $e ) {
            wp_die( wp_send_json_error( sanitize_text_field( $e->getMessage() ) ) ); //phpcs:ignore WordPress.Security.EscapeOutput
        }
        // In case of no created.
        wp_die( wp_send_json_error( esc_html( $error_msg ) ) ); //phpcs:ignore WordPress.Security.EscapeOutput
    }

    /**
     * Method ajax_import_website_add_client_no_site()
     *
     * Create client dont has a website.
     */
    public function ajax_import_website_add_client_no_site() {
        $this->secure_request( 'mainwp_import_website_add_client_no_site' ); // Check secure.
        $error_msg = esc_html__( 'Undefined error. Please try again.', 'mainwp' );
        try {
            $data = ! empty( $_POST['client'] ) ? rest_sanitize_array( $_POST['client'] ) : array(); //phpcs:ignore -- NonceVerification.

            if ( empty( $data ) || ! is_array( $data ) ) {
                wp_die( wp_send_json_error( esc_html( $error_msg ) ) ); //phpcs:ignore WordPress.Security.EscapeOutput
            }

            foreach ( $data as $val_data ) {
                $client_data = json_decode( stripslashes( $val_data ), true ); // Decode client data.
                $this->mainwp_handle_create_client( $client_data ); // Update or instert new client.
            }
        } catch ( \Exception $e ) {
            wp_die( wp_send_json_error( sanitize_text_field( $e->getMessage() ) ) ); //phpcs:ignore WordPress.Security.EscapeOutput
        }
        // In case of no created.
        wp_die( wp_send_json_error( esc_html( $error_msg ) ) ); //phpcs:ignore WordPress.Security.EscapeOutput
    }

    /**
     * Method ajax_clients_add_multi_client
     *
     * Create Multi Client form QSW.
     */
    public function ajax_clients_add_multi_client() {
        $error_msg = esc_html__( 'Undefined error. Please try again.', 'mainwp' );
        $this->check_security( 'mainwp_clients_add_multi_client' );
        $dataes = isset( $_POST['data'] ) ? $_POST['data'] : array(); //phpcs:ignore -- NonceVerification.
        try {
            if ( ! empty( $dataes ) ) {
                foreach ( $dataes as $val_data ) {
                    $client_data = array(
                        'image'              => '',
                        'name'               => $val_data['client_name'] ?? '',
                        'address_1'          => '',
                        'address_2'          => '',
                        'city'               => '',
                        'zip'                => '',
                        'state'              => '',
                        'country'            => '',
                        'note'               => '',
                        'selected_icon_info' => 'selected:wordpress;color:#34424d',
                        'client_email'       => $val_data['client_email'] ?? '',
                        'client_phone'       => '',
                        'client_facebook'    => '',
                        'client_twitter'     => '',
                        'client_instagram'   => '',
                        'client_linkedin'    => '',
                        'suspended'          => 0,
                        'primary_contact_id' => 0,
                    );

                    // Create client.
                    $client = $this->mainwp_handle_create_client( $client_data );

                    // Create client successfy.
                    if ( ! empty( $client ) && ! empty( $val_data['website_id'] ) ) {
                        // Add groups website and client.
                        $this->mainwp_handle_selected_sites_for_client( $client, $val_data['website_id'] );
                    }
                    // Create client contact.
                    if ( ! empty( $val_data['contacts_field'] ) ) {
                        $this->mainwp_handle_create_contact_for_client( $client, $val_data['contacts_field'] );
                    }
                }
                wp_die( wp_send_json_success( esc_html__( 'Created client successfully.', 'mainwp' ) ) ); //phpcs:ignore WordPress.Security.EscapeOutput
            }
        } catch ( \Exception $e ) {
            wp_die( wp_send_json_error( sanitize_text_field( $e->getMessage() ) ) ); //phpcs:ignore WordPress.Security.EscapeOutput
        }

        wp_die( wp_send_json_error( esc_html( $error_msg ) ) ); //phpcs:ignore WordPress.Security.EscapeOutput
    }

    /**
     * Method ajax_increase_connection_security
     */
    public function ajax_increase_connection_security() {
        $this->check_security( 'mainwp_increase_connection_security' );
        $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user( false, null, 'wp.url', false, false, null, true ) );
        while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
            // try to decrypt priv key.
            $de_privkey = MainWP_Encrypt_Data_Lib::instance()->decrypt_privkey( base64_decode( $website->privkey ), $website->id );  // phpcs:ignore -- NOSONAR - base64_encode trust.
            if ( empty( $de_privkey ) ) { // key is not encrypted.
                $en_privkey = MainWP_Encrypt_Data_Lib::instance()->encrypt_privkey( base64_decode( $website->privkey ), $website->id, true ); //phpcs:ignore -- NOSONAR - trust - create encrypt priv key for the site.
                if ( ! empty( $en_privkey['en_data'] ) ) {
                    MainWP_DB::instance()->update_website_values(
                        $website->id,
                        array(
                            'privkey' => base64_encode( $en_privkey['en_data'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- NOSONAR - base64_encode trust.
                        )
                    );
                }
            }
        }
        MainWP_DB::free_result( $websites );
        $this->hide_dashboard_notice_status( 'mainwp_secure_priv_key_notice' );
        delete_option( 'mainwp_not_start_encrypt_keys' );
        wp_die( wp_json_encode( array( 'success' => 1 ) ) );
    }



    /**
     * Method mainwp_get_sanitized_post()
     *
     * Sanitized post field.
     *
     * @param string $key key to get from POST.
     * @param string $callback cleaning method.
     *
     * @return mixed data value.
     */
    public function mainwp_get_sanitized_post( $key, $callback = 'sanitize_text_field' ) {
        return isset( $_POST[ $key ] ) ? $callback( wp_unslash( $_POST[ $key ] ) ) : ''; //phpcs:ignore -- NonceVerification.
    }

    /**
     * Method mainwp_handle_create_client()
     *
     * Create new client or return client data.
     *
     * @uses MainWP_DB_Client::instance()->get_wp_client_by()
     * @uses MainWP_DB_Client::instance()->update_client()
     *
     * @param array $client_data new client creation data.
     *
     * @return object client data.
     */
    public function mainwp_handle_create_client( $client_data ) {
        // Check if client does not exist then create new one.
        $client = MainWP_DB_Client::instance()->get_wp_client_by( 'client_email', $client_data['client_email'], OBJECT );
        if ( empty( $client ) ) {
            $client = MainWP_DB_Client::instance()->update_client( $client_data ); // Create client.
        }

        return $client;
    }

    /**
     * Method mainwp_handle_selected_sites_for_client()
     *
     * Update selected sites for client.
     *
     * @uses MainWP_DB_Client::instance()->get_websites_by_client_ids()
     * @uses MainWP_DB_Client::instance()->update_selected_sites_for_client()
     *
     * @param object $client client data.
     * @param int    $website_id id.
     */
    public function mainwp_handle_selected_sites_for_client( $client, $website_id ) {
        $sites_ids = MainWP_DB_Client::instance()->get_websites_by_client_ids( $client->client_id );
        $ids       = isset( $sites_ids ) && ! empty( $sites_ids ) ? array_column( $sites_ids, 'id' ) : array();
        $ids[]     = $website_id;
        MainWP_DB_Client::instance()->update_selected_sites_for_client( $client->client_id, $ids );
    }

    /**
     * Method mainwp_handle_create_contact_for_client()
     *
     * Handle create contact for client.
     *
     * @uses MainWP_DB_Client::instance()->update_client_contact()
     * @uses MMainWP_DB_Client::instance()->update_client()
     *
     * @param object $client client data.
     * @param array  $contacts_data contacts Data.
     */
    public function mainwp_handle_create_contact_for_client( $client, $contacts_data ) {
        $contacts = array_filter( $contacts_data );
        // Check required fields.
        if ( ( isset( $contacts['contact_name'] ) && '' !== $contacts['contact_name'] ) && ( isset( $contacts['contact_email'] ) && $contacts['contact_email'] ) ) {
            $contact_data = array(
                'contact_name'      => $contacts['contact_name'] ?? '',
                'contact_email'     => $contacts['contact_email'] ?? '',
                'contact_role'      => $contacts['contact_role'] ?? '',
                'contact_client_id' => $client->client_id,
                'contact_phone'     => '',
                'facebook'          => '',
                'twitter'           => '',
                'instagram'         => '',
                'linkedin'          => '',
                'contact_icon_info' => '',
            );
            $inserted     = MainWP_DB_Client::instance()->update_client_contact( $contact_data ); // Create or update contact of client.
            // If the update is successful, the client will be updated again.
            if ( $inserted ) {
                $update = array(
                    'client_id'          => $client->client_id,
                    'primary_contact_id' => $inserted->contact_id,
                );
                MainWP_DB_Client::instance()->update_client( $update );
            }
        }
    }
}
