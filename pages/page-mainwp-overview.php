<?php
/**
 * MainWP Overview Page.
 *
 * @package     MainWP/Dashboard
 */

namespace MainWP\Dashboard;

/**
 * Class MainWP_Overview
 *
 * @package MainWP\Dashboard
 */
class MainWP_Overview { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Get Class Name
     *
     * @return string __CLASS__
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * The single instance of the class
     *
     * @var mixed Default null
     */
    protected static $instance = null;

    /**
     * Screen dashdoard ID value.
     *
     * @var int $dashBoard default 0.
     */
    public $dashBoard = 0;

    /**
     * Enabled widgets
     *
     * @var array $enable_widgets
     */
    private static $enable_widgets = array(
        'overview'                 => true,
        'connection_status'        => true,
        'uptime_monitoring_status' => true,
        'recent_posts'             => true,
        'recent_pages'             => true,
        'security_issues'          => true,
        'backup_tasks'             => true,
        'non_mainwp_changes'       => true,
        'clients'                  => true,
        'get-started'              => true,
    );

    /**
     * Check if there is a session,
     * if there isn't one create it.
     *
     *  @return static::singlton Overview Page Session.
     *
     * @uses \MainWP\Dashboard\MainWP_Overview
     */
    public static function get() {
        if ( null === static::$instance ) {
            static::$instance = new MainWP_Overview();
        }

        return static::$instance;
    }

    /**
     * MainWP_Overview constructor.
     *
     * Run each time the class is called.
     */
    public function __construct() {
        add_filter( 'screen_layout_columns', array( &$this, 'on_screen_layout_columns' ), 10, 2 );
        add_action( 'admin_menu', array( &$this, 'on_admin_menu' ) );
        add_action( 'mainwp_help_sidebar_content', array( &$this, 'mainwp_help_content' ) );
    }

    /**
     * Set the number of page coumns.
     *
     * @param mixed $columns Number of Columns.
     * @param mixed $screen Screen size.
     *
     * @return int $columns Number of desired page columns.
     */
    public function on_screen_layout_columns( $columns, $screen ) {
        if ( $screen === $this->dashBoard ) {
            $columns[ $this->dashBoard ] = 3;
        }

        return $columns;
    }

    /**
     * Add MainWP Overview top level menu.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Utility::is_admin()
     * @uses  \MainWP\Dashboard\MainWP_Utility::ctype_digit()
     */
    public function on_admin_menu() {
        if ( MainWP_System_Utility::is_admin() ) {

            /**
             * Current user global.
             *
             * @global string
             */
            global $current_user;

            // The icon in Base64 format.
            $icon_base64 = 'PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0idXRmLTgiPz4NCjwhLS0gR2VuZXJhdG9yOiBBZG9iZSBJbGx1c3RyYXRvciAyNi4zLjEsIFNWRyBFeHBvcnQgUGx1Zy1JbiAuIFNWRyBWZXJzaW9uOiA2LjAwIEJ1aWxkIDApICAtLT4NCjxzdmcgdmVyc2lvbj0iMS4xIiBpZD0iTGF5ZXJfMSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIiB4bWxuczp4bGluaz0iaHR0cDovL3d3dy53My5vcmcvMTk5OS94bGluayIgeD0iMHB4IiB5PSIwcHgiDQoJIHZpZXdCb3g9IjAgMCA1MCA1MCIgc3R5bGU9ImVuYWJsZS1iYWNrZ3JvdW5kOm5ldyAwIDAgNTAgNTA7IiB4bWw6c3BhY2U9InByZXNlcnZlIj4NCjxzdHlsZSB0eXBlPSJ0ZXh0L2NzcyI+DQoJLnN0MHtmaWxsOiM5Q0EyQTc7fQ0KPC9zdHlsZT4NCjxwYXRoIGNsYXNzPSJzdDAiIGQ9Ik0yNSwwLjVDMTEuNDcsMC41LDAuNSwxMS40NywwLjUsMjVjMCwxMy41MywxMC45NywyNC41LDI0LjUsMjQuNVM0OS41LDM4LjUzLDQ5LjUsMjUNCglDNDkuNSwxMS40NywzOC41MywwLjUsMjUsMC41eiBNMjUsNDQuNmwtOC4zMy00LjlsNi4wOS0yNS4wN2MtMS41Ny0wLjgyLTIuNjYtMi40NC0yLjY2LTQuMzNjMC0yLjcxLDIuMTktNC45LDQuOS00LjkNCglzNC45LDIuMTksNC45LDQuOWMwLDEuODktMS4wOSwzLjUyLTIuNjYsNC4zM2w2LjA5LDI1LjA3TDI1LDQ0LjZ6Ii8+DQo8L3N2Zz4NCg==';

            // The icon in the data URI scheme.
            $icon_data_uri = 'data:image/svg+xml;base64,' . $icon_base64;

            delete_user_option( $current_user->ID, 'screen_layout_toplevel_page_mainwp_tab' );

            $this->dashBoard = add_menu_page(
                'MainWP',
                'MainWP',
                'read',
                'mainwp_tab',
                array(
                    $this,
                    'on_show_page',
                ),
                $icon_data_uri,
                '2.00001'
            );

            add_submenu_page(
                'mainwp_tab',
                'MainWP',
                __( 'Overview', 'mainwp' ),
                'read',
                'mainwp_tab',
                array(
                    $this,
                    'on_show_page',
                )
            );

            $val = get_user_option( 'screen_layout_' . $this->dashBoard );
            if ( ! MainWP_Utility::ctype_digit( $val ) ) {
                update_user_option( $current_user->ID, 'screen_layout_' . $this->dashBoard, 2, true );
            }
            add_action( 'load-' . $this->dashBoard, array( &$this, 'on_load_page' ) );
            static::init_left_menu();

        }
    }

    /**
     * Instantiate the MainWP Overview Menu item.
     *
     * @uses \MainWP\Dashboard\MainWP_Menu::add_left_menu()
     */
    public static function init_left_menu() {
        MainWP_Menu::add_left_menu(
            array(
                'title'      => esc_html__( 'Overview', 'mainwp' ),
                'parent_key' => 'mainwp_tab',
                'slug'       => 'mainwp_tab',
                'href'       => 'admin.php?page=mainwp_tab',
                'icon'       => '<i class="th large icon"></i>',
            ),
            0
        );
    }

    /**
     * Method on_load_page()
     *
     * Run on page load.
     */
    public function on_load_page() {
        wp_enqueue_script( 'common' );
        wp_enqueue_script( 'wp-lists' );
        wp_enqueue_script( 'postbox' );
        wp_enqueue_script( 'dashboard' );
        wp_enqueue_script( 'widgets' );

        static::add_meta_boxes( $this->dashBoard );
    }

    /**
     * Method add_meta_boxes()
     *
     * Add MainWP Overview Page Widgets.
     *
     * @param array $page Current page.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Handler::apply_filters()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_current_wpid()
     * @uses \MainWP\Dashboard\MainWP_UI::add_widget_box()
     * @uses \MainWP\Dashboard\MainWP_Connection_Status::get_class_name()
     * @uses \MainWP\Dashboard\MainWP_Recent_Pages::get_class_name()
     * @uses \MainWP\Dashboard\MainWP_Recent_Posts::get_class_name()
     * @uses \MainWP\Dashboard\MainWP_Security_Issues_Widget::get_class_name()
     * @uses \MainWP\Dashboard\MainWP_Updates_Overview::get_class_name()
     */
    public static function add_meta_boxes( $page ) { // phpcs:ignore -- NOSONAR - complex method. Current complexity is the only way to achieve desired results, pull request solutions appreciated.

        /**
         * Get getmetaboxes
         *
         * Adds metaboxes (widgets) to the Overview page.
         *
         * @since Unknown
         */
        $extMetaBoxs = MainWP_System_Handler::instance()->apply_filters( 'mainwp-getmetaboxes', array() );
        $extMetaBoxs = MainWP_System_Handler::instance()->apply_filters( 'mainwp_getmetaboxes', $extMetaBoxs ); // hooks to load widgets for general overview dashboard page.

        foreach ( $extMetaBoxs as $box ) {
            if ( isset( $box['plugin'] ) ) {
                $name                            = basename( $box['plugin'], '.php' );
                static::$enable_widgets[ $name ] = true;
            }
        }

        $values = static::$enable_widgets;

        /**
         * Unset unwanted Widgets
         *
         * Contains the list of enabled widgets and allows user to unset unwanted widgets.
         *
         * @param array $values           Array containing enabled widgets.
         * @param int   $dashboard_siteid Child site (Overview) ID.
         *
         * @since 4.0
         */
        $values                 = apply_filters( 'mainwp_overview_enabled_widgets', $values, null );
        static::$enable_widgets = array_merge( static::$enable_widgets, $values );

        // Load the Updates Overview widget.
        if ( static::$enable_widgets['overview'] ) {
            MainWP_UI::add_widget_box( 'overview', array( MainWP_Updates_Overview::get_class_name(), 'render' ), $page, array( 0, 0, 12, 19 ) );
        }

        // Load the Clients widget.
        if ( static::$enable_widgets['clients'] ) {
            MainWP_UI::add_widget_box( 'clients', array( MainWP_Clients::get_class_name(), 'render' ), $page, array( -1, -1, 4, 30 ) );
        }

        // Load the Non-MainWP Changes widget.
        if ( static::$enable_widgets['non_mainwp_changes'] ) {
            MainWP_UI::add_widget_box( 'non_mainwp_changes', array( MainWP_Site_Actions::get_class_name(), 'render' ), $page, array( -1, -1, 4, 30 ) );
        }

        // Load the Connection Status widget.
        if ( ! MainWP_System_Utility::get_current_wpid() && static::$enable_widgets['connection_status'] ) {
            MainWP_UI::add_widget_box( 'connection_status', array( MainWP_Connection_Status::get_class_name(), 'render' ), $page, array( -1, -1, 4, 30 ) );
        }

        // Load the Connection Status widget.
        if ( MainWP_Uptime_Monitoring_Edit::is_enable_global_monitoring() && ! MainWP_System_Utility::get_current_wpid() && static::$enable_widgets['uptime_monitoring_status'] ) {
            MainWP_UI::add_widget_box( 'uptime_monitoring_status', array( MainWP_Uptime_Monitoring_Status::get_class_name(), 'render_status' ), $page, array( -1, -1, 4, 30 ) );
        }

        // Load the Security Issues widget.
        if ( \mainwp_current_user_can( 'dashboard', 'manage_security_issues' ) && static::$enable_widgets['security_issues'] ) {
            MainWP_UI::add_widget_box( 'security_issues', array( MainWP_Security_Issues_Widget::get_class_name(), 'render_widget' ), $page, array( -1, -1, 4, 30 ) );
        }

        // Load the Recent Pages widget.
        if ( \mainwp_current_user_can( 'dashboard', 'manage_pages' ) && static::$enable_widgets['recent_pages'] ) {
            MainWP_UI::add_widget_box( 'recent_pages', array( MainWP_Recent_Pages::get_class_name(), 'render' ), $page, array( -1, -1, 4, 30 ) );
        }

        // Load the Recent Posts widget.
        if ( \mainwp_current_user_can( 'dashboard', 'manage_posts' ) && static::$enable_widgets['recent_posts'] ) {
            MainWP_UI::add_widget_box( 'recent_posts', array( MainWP_Recent_Posts::get_class_name(), 'render' ), $page, array( -1, -1, 4, 30 ) );
        }

        $i = 1;
        foreach ( $extMetaBoxs as $metaBox ) {
            $enabled = true;
            if ( isset( $metaBox['plugin'] ) ) {
                $name = basename( $metaBox['plugin'], '.php' );
                if ( isset( static::$enable_widgets[ $name ] ) && ! static::$enable_widgets[ $name ] ) {
                    $enabled = false;
                }
            }

            $id = isset( $metaBox['id'] ) ? $metaBox['id'] : $i++;
            $id = 'advanced-' . $id;

            if ( $enabled ) {
                MainWP_UI::add_widget_box( $id, $metaBox['callback'], $page, array( -1, -1, 6, 30 ) );
            }
        }
    }

    /**
     * Method on_show_page()
     *
     * When the page loads render the body content.
     *
     * @uses \MainWP\Dashboard\MainWP_UI::render_top_header()
     * @uses \MainWP\Dashboard\MainWP_UI::render_second_top_header()
     */
    public function on_show_page() {
        if ( ! \mainwp_current_user_can( 'dashboard', 'access_global_dashboard' ) ) {
            \mainwp_do_not_have_permissions( esc_html__( 'global dashboard', 'mainwp' ) );
            return;
        }

        /**
         * Screen layout columns array.
         *
         * @global object
         */
        global $screen_layout_columns;

        $params = array(
            'title' => esc_html__( 'Overview', 'mainwp' ),
        );

        MainWP_UI::render_top_header( $params );

        MainWP_UI::render_second_top_header();

        static::render_dashboard_body();
        ?>
        </div>
        <?php
    }


    /**
     * Method render_dashboard_body()
     *
     * Render the Dashboard Body content.
     *
     * @param object $websites      Object containing child sites info.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_current_wpid()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_page_id()
     * @uses \MainWP\Dashboard\MainWP_UI::do_widget_boxes()
     * @uses  \MainWP\Dashboard\MainWP_Utility::show_mainwp_message()
     */
    public static function render_dashboard_body( $websites = array() ) {
        $current_wp_id = MainWP_System_Utility::get_current_wpid();
        $website       = null;
        if ( ! empty( $current_wp_id ) ) {
            $website = $websites[0];
        }
        $screen = get_current_screen();

        MainWP_Demo_Handle::get_instance()->init_data_demo();
        static::render_layout_selection();
        ?>
        <div class="mainwp-primary-content-wrap">
            <div class="ui segment" style="padding-top:0px;padding-bottom:0px;margin-bottom:0px;">
            <div id="mainwp-dashboard-info-box"></div>
            <?php
            if ( ! empty( $current_wp_id ) && ! empty( $website->sync_errors ) ) {
                ?>
                <div class="ui red message">
                    <p><?php echo '<strong>' . esc_html( stripslashes( $website->name ) ) . '</strong>' . esc_html__( ' is Disconnected. Click the Reconnect button to establish the connection again.', 'mainwp' ); ?></p>
                </div>
                <?php
            }
            ?>
            <div id="mainwp-message-zone" class="ui message" style="display:none;"></div>
            <?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'widgets' ) ) : ?>
                <div class="ui info message">
                    <i class="close icon mainwp-notice-dismiss" notice-id="widgets"></i>
                        <?php printf( esc_html__( 'To hide or show a widget, click the Cog (%1$s) icon.', 'mainwp' ), '<i class="cog icon"></i>' ); ?>
                </div>
            <?php endif; ?>
            </div>
            <?php
            /**
             * Action: mainwp_before_overview_widgets
             *
             * Fires at the top of the Overview page (before first widget).
             *
             * @since 4.1
             */
            do_action( 'mainwp_before_overview_widgets', 'dashboard' );
            ?>
            <div id="mainwp-grid-wrapper" class="gridster">
                <?php MainWP_UI::do_widget_boxes( $screen->id ); ?>
            </div>
            <?php
            /**
             * Action: 'mainwp_after_overview_widgets'
             *
             * Fires at the bottom of the Overview page (after the last widget).
             *
             * @since 4.1
             */
            do_action( 'mainwp_after_overview_widgets', 'dashboard' );
            ?>
    <script type="text/javascript">
        jQuery( document ).ready( function( $ ) {
            jQuery( '.mainwp-widget .mainwp-dropdown-tab .item' ).tab();
            mainwp_get_icon_start();
        } );
    </script>
        <?php
        MainWP_UI::render_modal_upload_icon();
        MainWP_Updates::render_plugin_details_modal();
    }

    /**
     * Render layout selection.
     */
    public static function render_layout_selection() { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR - complexity.
        $screen = get_current_screen();
        ?>
        <div class="mainwp-sub-header ui right aligned segment" id="mainwp-manage-widgets-layout-row">
            <?php MainWP_Ui_Manage_Widgets_Layout::render_edit_layout( $screen->id ); ?>
        </div>
        <?php
        MainWP_Ui_Manage_Widgets_Layout::render_modal_save_layout();
    }

    /**
     * Method mainwp_help_content()
     *
     * Creates the MainWP Help Documentation List for the help component in the sidebar.
     */
    public static function mainwp_help_content() {
        if ( isset( $_GET['page'] ) && 'mainwp_tab' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            ?>
            <p><?php esc_html_e( 'If you need help with your MainWP Dashboard, please review following help documents', 'mainwp' ); ?></p>
            <div class="ui list">
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/mainwp-user-interface/" target="_blank">Understanding MainWP Dashboard UI</a></div>
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/mainwp-user-interface/#navigation" target="_blank">MainWP Navigation</a></div>
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/mainwp-user-interface/#page-settings" target="_blank">Page Settings</a></div>
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/mainwp-user-interface/#widgetized-page-layout" target="_blank">Widgetized Page Layout</a></div>
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/mainwp-user-interface/#tables" target="_blank">MainWP Tables</a></div>
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/mainwp-user-interface/#individual-site-mode" target="_blank">Individual Child Site Mode</a></div>
                <?php
                /**
                 * Action: mainwp_overview_help_item
                 *
                 * Fires at the bottom of the help articles list in the Help sidebar on the Overview page.
                 *
                 * Suggested HTML markup:
                 *
                 * <div class="item"><a href="Your custom URL">Your custom text</a></div>
                 *
                 * @since 4.1
                 */
                do_action( 'mainwp_overview_help_item' );
                ?>
            </div>
            <?php
        }
    }
}
