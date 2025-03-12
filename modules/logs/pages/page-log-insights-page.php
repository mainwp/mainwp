<?php
/**
 * Processes form input.
 *
 * @package MainWP/Dashboard
 * @version 4.6
 */

namespace MainWP\Dashboard\Module\Log;

use MainWP\Dashboard\MainWP_Menu;
use MainWP\Dashboard\MainWP_UI;
use MainWP\Dashboard\MainWP_Utility;
use MainWP\Dashboard\MainWP_DB_Client;
use MainWP\Dashboard\MainWP_DB_Common;
use MainWP\Dashboard\MainWP_Post_Handler;
use MainWP\Dashboard\MainWP_Logger;
use MainWP\Dashboard\MainWP_Ui_Manage_Widgets_Layout;

/**
 * Class Log_Insights_Page
 *
 * @package MainWP\Dashboard
 */
class Log_Insights_Page { //phpcs:ignore -- NOSONAR - multi methods.


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
     * The single instance of the variable.
     *
     * @var mixed Default null
     */
    protected $list_events_table = null;


    /**
     * Enabled widgets
     *
     * @var array $enable_widgets
     */
    private static $enable_widgets = array(
        'recent_events'     => true,
        'log_plugins'       => true,
        'log_themes'        => true,
        'log_sites'         => true,
        'log_posts'         => true,
        'log_pages'         => true,
        'log_users'         => true,
        'log_clients'       => true,
        'log_graph_status'  => true,
        'log_graph_php'     => true,
        'log_graph_wp'      => true,
        'log_graph_themes'  => true,
        'log_graph_plugins' => true,
        'log_graph_tags'    => true,
        'log_graph_clients' => true,
    );

    /**
     * Current page.
     *
     * @static
     * @var string $page Current page.
     */
    public static $page;

    /**
     * Check if there is a session,
     * if there isn't one create it.
     *
     *  @return static::singlton Overview Page Session.
     *
     * @uses \MainWP\Dashboard\MainWP_Overview
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }

        return static::$instance;
    }

    /**
     * MainWP_Overview constructor.
     *
     * Run each time the class is called.
     */
    public function __construct() {
        add_action( 'admin_init', array( $this, 'admin_init' ) );
    }

    /**
     * Hook admin init.
     */
    public function admin_init() {
        $this->handle_update_screen_options();
    }


    /**
     * Handle update screen options.
     */
    public function handle_update_screen_options() { //phpcs:ignore -- NOSONAR - complex.
        $update_opts = false;
        if ( isset( $_POST['module_log_overview_options_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['module_log_overview_options_nonce'] ), 'module_log_overview_options_nonce' ) ) {
            $update_opts = true;
        }
        if ( $update_opts ) {
            $show_wids = array();
            if ( isset( $_POST['mainwp_show_widgets'] ) && is_array( $_POST['mainwp_show_widgets'] ) ) {
                $selected_wids = array_map( 'sanitize_text_field', wp_unslash( $_POST['mainwp_show_widgets'] ) );
                foreach ( $selected_wids as $name ) {
                    $show_wids[ $name ] = 1;
                }
            }

            if ( isset( $_POST['mainwp_widgets_name'] ) && is_array( $_POST['mainwp_widgets_name'] ) ) {
                $name_wids = array_map( 'sanitize_text_field', wp_unslash( $_POST['mainwp_widgets_name'] ) );
                foreach ( $name_wids as $name ) {
                    if ( ! isset( $show_wids[ $name ] ) ) {
                        $show_wids[ $name ] = 0;
                    }
                }
            }

            global $current_user;

            if ( $current_user ) {
                update_user_option( $current_user->ID, 'mainwp_module_log_overview_show_widgets', $show_wids, true );
                if ( isset( $_POST['reset_module_log_overview_widgets_settings'] ) && ! empty( $_POST['reset_module_log_overview_widgets_settings'] ) ) {
                    update_user_option( $current_user->ID, 'mainwp_module_log_overview_show_widgets', false, true );
                    update_user_option( $current_user->ID, 'mainwp_widgets_sorted_' . strtolower( 'mainwp_page_InsightsOverview' ), false, true );
                }
            }
        }
    }


    /**
     * Method on_load_page()
     *
     * Run on page load.
     *
     * @param mixed $page Page name.
     */
    public function on_load_page( $page ) {

        static::$page = $page;

        $val = get_user_option( 'screen_layout_' . $page );
        if ( ! $val ) {
            global $current_user;
            update_user_option( $current_user->ID, 'screen_layout_' . $page, 2, true );
        }

        wp_enqueue_script( 'common' );
        wp_enqueue_script( 'wp-lists' );
        wp_enqueue_script( 'postbox' );
        wp_enqueue_script( 'dashboard' );
        wp_enqueue_script( 'widgets' );

        static::add_meta_boxes( $page );

        add_filter( 'mainwp_header_actions_right', array( $this, 'screen_options' ), 10, 2 );
        add_filter( 'mainwp_widget_boxes_show_widgets', array( $this, 'hook_show_widgets' ), 10, 2 );
        add_filter( 'mainwp_page_admin_body_class', array( $this, 'hook_admin_body_class' ), 10, 1 );
    }

    /**
     * Method screen_options()
     *
     * Create Page Settings button.
     *
     * @param mixed $input Page Settings button HTML.
     *
     * @return mixed Page Settings button.
     */
    public function screen_options( $input ) {
        return $input .
            '<a class="ui button basic icon" onclick="mainwp_module_log_overview_screen_options(); return false;" data-inverted="" data-position="bottom right" href="#" aria-label="' . esc_attr__( 'Page Settings', 'mainwp' ) . '" data-tooltip="' . esc_attr__( 'Page Settings', 'mainwp' ) . '">
                <i class="cog icon"></i>
            </a>';
    }

    /**
     * Method hook_show_widgets()
     *
     * Hook show widgets.
     *
     * @param array  $values Show widgets settings.
     * @param string $page Page slug.
     *
     * @return array $values Show widgets settings.
     */
    public function hook_show_widgets( $values, $page ) {
        if ( strtolower( $page ) === strtolower( static::$page ) ) {
            return get_user_option( 'mainwp_module_log_overview_show_widgets' );
        }
        return $values;
    }

    /**
     * Method hook_admin_body_class()
     *
     * @param string $body_classes Body classes.
     * @return string $body_classes Body classes.
     */
    public function hook_admin_body_class( $body_classes ) {
        return $body_classes . ' mainwp-hidden-second-level-navigation ';
    }


    /**
     * Initiates Insights MainWP menu.
     */
    public static function init_left_menu() {

        MainWP_Menu::add_left_menu(
            array(
                'title'      => esc_html__( 'Insights', 'mainwp' ),
                'parent_key' => 'mainwp_tab',
                'slug'       => 'InsightsOverview',
                'href'       => 'admin.php?page=InsightsOverview',
                'icon'       => '<i class="pie chart icon"></i>',
                'desc'       => 'Dashboard Insights Overview',
                'nosubmenu'  => true,
            ),
            0
        );
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
     */
    public static function add_meta_boxes( $page ) { //phpcs:ignore -- NOSONAR - complex method.

        /**
         * Get getmetaboxes
         *
         * Adds metaboxes (widgets) to the Overview page.
         *
         * @since 4.6
         */
        $extMetaBoxs = array();
        $extMetaBoxs = apply_filters( 'mainwp_insights_getmetaboxes', $extMetaBoxs );

        foreach ( $extMetaBoxs as $box ) {
            if ( isset( $box['plugin'] ) ) {
                $name                            = basename( $box['plugin'], '.php' );
                static::$enable_widgets[ $name ] = true;
            } elseif ( ! empty( $box['widget_id'] ) ) {
                static::$enable_widgets[ $box['widget_id'] ] = true;
            }
        }

        /**
         * Unset unwanted Widgets
         *
         * Contains the list of enabled widgets and allows user to unset unwanted widgets.
         *
         * @param array $enable_widgets           Array containing enabled widgets.
         * @param int   $dashboard_siteid Child site (Overview) ID.
         *
         * @since 4.6
         */
        $values                 = apply_filters( 'mainwp_module_log_overview_enabled_widgets', static::$enable_widgets, null );
        static::$enable_widgets = array_merge( static::$enable_widgets, $values );

        // Load the widget.
        if ( ! empty( static::$enable_widgets['log_sites'] ) ) {
            MainWP_UI::add_widget_box( 'log_sites', array( Log_Sites_Widget::instance(), 'render' ), $page, array( -1, -1, 6, 40 ) );
        }

        // Load the widget.
        if ( ! empty( static::$enable_widgets['log_clients'] ) ) {
            MainWP_UI::add_widget_box( 'log_clients', array( Log_Clients_Widget::instance(), 'render' ), $page, array( -1, -1, 6, 40 ) );
        }

        // Load the widget.
        if ( ! empty( static::$enable_widgets['log_pages'] ) ) {
            MainWP_UI::add_widget_box( 'log_pages', array( Log_Pages_Widget::instance(), 'render' ), $page, array( -1, -1, 6, 40 ) );
        }

        // Load the widget.
        if ( ! empty( static::$enable_widgets['log_posts'] ) ) {
            MainWP_UI::add_widget_box( 'log_posts', array( Log_Posts_Widget::instance(), 'render' ), $page, array( -1, -1, 6, 40 ) );
        }

        // Load the widget.
        if ( ! empty( static::$enable_widgets['log_themes'] ) ) {
            MainWP_UI::add_widget_box( 'log_themes', array( Log_Themes_Widget::instance(), 'render' ), $page, array( -1, -1, 6, 40 ) );
        }

        // Load the widget.
        if ( ! empty( static::$enable_widgets['log_plugins'] ) ) {
            MainWP_UI::add_widget_box( 'log_plugins', array( Log_Plugins_Widget::instance(), 'render' ), $page, array( -1, -1, 6, 40 ) );
        }

        // Load the widget.
        if ( ! empty( static::$enable_widgets['log_graph_clients'] ) ) {
            MainWP_UI::add_widget_box( 'log_graph_clients', array( Log_Graph_Clients_Widget::instance(), 'render' ), $page, array( -1, -1, 6, 40 ) );
        }

        // Load the widget.
        if ( ! empty( static::$enable_widgets['log_graph_tags'] ) ) {
            MainWP_UI::add_widget_box( 'log_graph_tags', array( Log_Graph_Tags_Widget::instance(), 'render' ), $page, array( -1, -1, 6, 40 ) );
        }

        // Load the widget.
        if ( ! empty( static::$enable_widgets['log_graph_status'] ) ) {
            MainWP_UI::add_widget_box( 'log_graph_status', array( Log_Graph_Status_Widget::instance(), 'render' ), $page, array( -1, -1, 6, 40 ) );
        }

        // Load the widget.
        if ( ! empty( static::$enable_widgets['log_graph_themes'] ) ) {
            MainWP_UI::add_widget_box( 'log_graph_themes', array( Log_Graph_Themes_Widget::instance(), 'render' ), $page, array( -1, -1, 6, 40 ) );
        }

        // Load the widget.
        if ( ! empty( static::$enable_widgets['log_graph_wp'] ) ) {
            MainWP_UI::add_widget_box( 'log_graph_wp', array( Log_Graph_WP_Widget::instance(), 'render' ), $page, array( -1, -1, 6, 40 ) );
        }

        // Load the widget.
        if ( ! empty( static::$enable_widgets['log_graph_php'] ) ) {
            MainWP_UI::add_widget_box( 'log_graph_php', array( Log_Graph_Php_Widget::instance(), 'render' ), $page, array( -1, -1, 6, 40 ) );
        }

        // Load the widget.
        if ( ! empty( static::$enable_widgets['log_graph_plugins'] ) ) {
            MainWP_UI::add_widget_box( 'log_graph_plugins', array( Log_Graph_Plugins_Widget::instance(), 'render' ), $page, array( -1, -1, 6, 40 ) );
        }

        // Load the widget.
        if ( ! empty( static::$enable_widgets['log_users'] ) ) {
            MainWP_UI::add_widget_box( 'log_users', array( Log_Users_Widget::instance(), 'render' ), $page, array( -1, -1, 6, 40 ) );
        }

        // Load the widget.
        if ( ! empty( static::$enable_widgets['recent_events'] ) ) {
            MainWP_UI::add_widget_box( 'recent_events', array( Log_Recent_Events_Widget::instance(), 'render' ), $page, array( -1, -1, 6, 40 ) );
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

            $layout = ! empty( $metaBox['layout'] ) && is_array( $metaBox['layout'] ) ? $metaBox['layout'] : array( -1, -1, 6, 40 );

            if ( $enabled ) {
                MainWP_UI::add_widget_box( $id, $metaBox['callback'], $page, $layout );
            }
        }
    }

    /**
     * Method get default logs filters()
     */
    public static function get_default_filters() {
        $format = 'Y-m-d';
        return array(
            'ranges'   => 'thismonth',
            'dtsstart' => gmdate( $format, strtotime( gmdate( 'Y-m-01' ) ) ),
            'dtsstop'  => gmdate( $format, time() ),
        );
    }

    /**
     * Method render_footer()
     *
     * Render Insights page footer. Closes the page container.
     */
    public static function render_footer() {
        echo '</div>';
    }

    /**
     * Renders manage insights dashboard.
     *
     * @return void
     */
    public function render_insights_overview() {
        if ( ! \mainwp_current_user_can( 'dashboard', 'access_insights_dashboard' ) ) {
            \mainwp_do_not_have_permissions( esc_html__( 'insights dashboard', 'mainwp' ) );
            return;
        }
        $this->on_show_page();
    }


    /**
     * Method on_show_page()
     *
     * When the page loads render the body content.
     */
    public function on_show_page() {
        static::render_header( 'overview' );
        $insights_filters = $this->get_insights_filters( true );
        static::render_logs_overview_top( $insights_filters );
        $this->load_events_list_table(); // for events list in overview page.
        $this->list_events_table->prepare_items( true, $insights_filters );
        $items      = $this->list_events_table->items;
        $items_prev = ! empty( $this->list_events_table->items_prev ) ? $this->list_events_table->items_prev : array();

        static::render_dashboard_body( $items, $items_prev, $insights_filters );
    }

    /**
     * Method get_insights_filters()
     *
     * Get insights filters.
     *
     * @param bool $save_filter To save filter.
     */
    public function get_insights_filters( $save_filter = false ) { //phpcs:ignore -- NOSONAR - complex method.

        $filters = array( 'client', 'range', 'group', 'user', 'dtsstart', 'dtsstop' );

        $get_saved = true;
        foreach ( $filters as $filter ) {
            if ( isset( $_REQUEST[ $filter ] ) ) { //phpcs:ignore -- safe.
                $get_saved = false;
                break;
            }
        }

        $filter_ranges     = '';
        $filter_groups_ids = '';
        $filter_client_ids = '';
        $filter_user_ids   = '';
        $filter_dtsstart   = '';
        $filter_dtsstop    = '';
        $array_clients_ids = array();
        $array_groups_ids  = array();
        $array_users_ids   = array();

        $update_filter = false;

        if ( $get_saved ) {
            $filters_saved = get_user_option( 'mainwp_module_logs_overview_filters_saved' );
            if ( ! is_array( $filters_saved ) ) {
                $filters_saved = static::get_default_filters();
            }
            $filter_ranges     = isset( $filters_saved['ranges'] ) && ! empty( $filters_saved['ranges'] ) ? $filters_saved['ranges'] : false;
            $filter_groups_ids = isset( $filters_saved['groups_ids'] ) && ! empty( $filters_saved['groups_ids'] ) ? $filters_saved['groups_ids'] : '';
            $filter_client_ids = isset( $filters_saved['client_ids'] ) && ! empty( $filters_saved['client_ids'] ) ? $filters_saved['client_ids'] : false;
            $filter_user_ids   = isset( $filters_saved['user_ids'] ) && ! empty( $filters_saved['user_ids'] ) ? $filters_saved['user_ids'] : '';
            $filter_dtsstart   = isset( $filters_saved['dtsstart'] ) && ! empty( $filters_saved['dtsstart'] ) ? $filters_saved['dtsstart'] : '';
            $filter_dtsstop    = isset( $filters_saved['dtsstop'] ) && ! empty( $filters_saved['dtsstop'] ) ? $filters_saved['dtsstop'] : '';
        } else {
            // phpcs:disable WordPress.Security.NonceVerification
            $filter_ranges     = isset( $_REQUEST['range'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['range'] ) ) : '';
            $filter_groups_ids = isset( $_REQUEST['group'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['group'] ) ) : '';
            $filter_client_ids = isset( $_REQUEST['client'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['client'] ) ) : '';
            $filter_user_ids   = isset( $_REQUEST['user'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['user'] ) ) : '';
            $filter_dtsstart   = isset( $_REQUEST['dtsstart'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['dtsstart'] ) ) : '';
            $filter_dtsstop    = isset( $_REQUEST['dtsstop'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['dtsstop'] ) ) : '';
            // phpcs:enable WordPress.Security.NonceVerification
            $update_filter = true;
        }

        // validate requests.
        if ( ! empty( $filter_client_ids ) ) {
            $array_clients_ids = explode( ',', $filter_client_ids ); // convert to array.
            if ( in_array( 'allclients', $array_clients_ids, true ) ) {
                $array_clients_ids = false;
                $filter_client_ids = '';
            } else {
                $array_clients_ids = MainWP_Utility::array_numeric_filter( $array_clients_ids );
                $filter_client_ids = implode( ',', $array_clients_ids );
            }
        }

        if ( ! empty( $filter_groups_ids ) ) {
            $array_groups_ids = explode( ',', $filter_groups_ids ); // convert to array.
            if ( in_array( 'alltags', $array_groups_ids, true ) ) {
                $array_groups_ids  = false;
                $filter_groups_ids = '';
            } else {
                $array_groups_ids  = MainWP_Utility::array_numeric_filter( $array_groups_ids );
                $filter_groups_ids = implode( ',', $array_groups_ids );
            }
        }

        if ( ! empty( $filter_user_ids ) ) {
            $array_users_ids = explode( ',', $filter_user_ids ); // convert to array.
            if ( in_array( 'allusers', $array_users_ids, true ) ) {
                $array_users_ids = false;
                $filter_user_ids = '';
            } else {
                $array_users_ids = MainWP_Utility::array_numeric_filter( $array_users_ids );
                $filter_user_ids = implode( ',', $array_users_ids );
            }
        }

        if ( $save_filter && $update_filter ) {
            MainWP_Utility::update_user_option(
                'mainwp_module_logs_overview_filters_saved',
                array(
                    'ranges'     => $filter_ranges,
                    'groups_ids' => $filter_groups_ids,
                    'client_ids' => $filter_client_ids,
                    'user_ids'   => $filter_user_ids,
                    'dtsstart'   => $filter_dtsstart,
                    'dtsstop'    => $filter_dtsstop,
                )
            );
        }

        return compact(
            'filter_ranges',
            'filter_groups_ids',
            'filter_client_ids',
            'filter_user_ids',
            'filter_dtsstart',
            'filter_dtsstop',
            'array_clients_ids',
            'array_groups_ids',
            'array_users_ids',
        );
    }

    /**
     * Render Manage Tasks Table Top.
     *
     * @param array $insights_filters Insights filters.
     */
    public static function render_logs_overview_top( $insights_filters ) { //phpcs:ignore -- NOSONAR - complex.
        $manager = Log_Manager::instance();

        $filter_ranges     = '';
        $filter_groups_ids = '';
        $filter_client_ids = '';
        $filter_user_ids   = '';
        $filter_dtsstart   = '';
        $filter_dtsstop    = '';
        $array_clients_ids = array();
        $array_groups_ids  = array();
        $array_users_ids   = array();

        extract( $insights_filters ); //phpcs:ignore -- ok.

        $default_filter = false;
        // extracted values.
        if ( ( empty( $filter_ranges ) || 'thismonth' === $filter_ranges ) && empty( $filter_groups_ids ) && empty( $filter_client_ids ) && empty( $filter_user_ids ) && empty( $filter_user_ids ) && empty( $filter_dtsstart ) && empty( $filter_dtsstop ) && empty( $array_clients_ids ) && empty( $array_groups_ids ) && empty( $array_users_ids ) ) {
            $default_filter = true;
        }

        $disable_dt = ( '' === $filter_ranges || 'custom' === $filter_ranges ) ? false : true;

        $groups = MainWP_DB_Common::instance()->get_groups_for_current_user();
        if ( ! is_array( $groups ) ) {
            $groups = array();
        }

        static::render_layout_selection();

        ?>
    <div class="mainwp-sub-header" id="mainwp-module-log-overview-sub-header">
        <div class="ui stackable grid" id="mainwp-module-log-filters-row">
            <div class="twelve wide column">
                <div class="ui compact grid">
                    <div class="two wide middle aligned column">
                        <div id="mainwp-module-log-filter-ranges" class="ui selection fluid mini dropdown seg_ranges not-auto-init">
                            <input type="hidden" value="<?php echo esc_html( $filter_ranges ); ?>">
                            <i class="dropdown icon"></i>
                            <div class="default text"><?php esc_html_e( 'Select range', 'mainwp' ); ?></div>
                            <div class="menu">
                                <?php
                                $date_ranges = array(
                                    'today'     => esc_html__( 'Today', 'mainwp' ),
                                    'yesterday' => esc_html__( 'Yesterday', 'mainwp' ),
                                    'thisweek'  => esc_html__( 'This week', 'mainwp' ),
                                    'thismonth' => esc_html__( 'This month', 'mainwp' ),
                                    'lastmonth' => esc_html__( 'Last month', 'mainwp' ),
                                    'thisyear'  => esc_html__( 'This year', 'mainwp' ),
                                    'lastyear'  => esc_html__( 'Last year', 'mainwp' ),
                                );
                                foreach ( $date_ranges as $val => $title ) {
                                    ?>
                                    <div class="item" data-value="<?php echo esc_html( $val ); ?>"><?php echo esc_html( $title ); ?></div>
                                    <?php
                                }
                                ?>
                                <div class="item" data-value="custom"><?php esc_html_e( 'Custom', 'mainwp' ); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="two wide middle aligned column">
                        <div class="ui mini calendar mainwp_datepicker seg_dtsstart" id="mainwp-module-log-filter-dtsstart" >
                            <div class="ui input left fluid icon">
                                <i class="calendar icon"></i>
                                <input type="text" <?php echo $disable_dt ? 'disabled="disabled"' : ''; ?> autocomplete="off" placeholder="<?php esc_attr_e( 'Start date', 'mainwp' ); ?>" value="<?php echo ! empty( $filter_dtsstart ) ? esc_attr( $filter_dtsstart ) : ''; ?>"/>
                            </div>
                        </div>
                    </div>
                    <div class="two wide middle aligned column">
                        <div class="ui mini calendar mainwp_datepicker seg_dtsstop" id="mainwp-module-log-filter-dtsstop" >
                            <div class="ui input left icon">
                                <i class="calendar icon"></i>
                                <input type="text" <?php echo $disable_dt ? 'disabled="disabled"' : ''; ?> autocomplete="off" placeholder="<?php esc_attr_e( 'End date', 'mainwp' ); ?>" value="<?php echo ! empty( $filter_dtsstop ) ? esc_attr( $filter_dtsstop ) : ''; ?>"/>
                            </div>
                        </div>
                    </div>
                    <div class="two wide middle aligned column">
                        <div id="mainwp-module-log-filter-groups" class="ui selection multiple fluid mini dropdown seg_groups">
                            <input type="hidden" value="<?php echo esc_html( $filter_groups_ids ); ?>">
                            <i class="dropdown icon"></i>
                            <div class="default text"><?php esc_html_e( 'All tags', 'mainwp' ); ?></div>
                            <div class="menu">
                                <?php
                                foreach ( $groups as $group ) {
                                    ?>
                                    <div class="item" data-value="<?php echo esc_attr( $group->id ); ?>"><?php echo esc_html( stripslashes( $group->name ) ); ?></div>
                                    <?php
                                }
                                ?>
                                <div class="item" data-value="alltags"><?php esc_html_e( 'All tags', 'mainwp' ); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="two wide middle aligned column">
                        <div id="mainwp-module-log-filter-clients" class="ui selection multiple fluid mini dropdown seg_clients">
                            <input type="hidden" value="<?php echo esc_html( $filter_client_ids ); ?>">
                            <i class="dropdown icon"></i>
                            <div class="default text"><?php esc_html_e( 'All clients', 'mainwp' ); ?></div>
                            <div class="menu">
                                <?php
                                $clients = MainWP_DB_Client::instance()->get_wp_client_by( 'all' );
                                foreach ( $clients as $client ) {
                                    ?>
                                    <div class="item" data-value="<?php echo intval( $client->client_id ); ?>"><?php echo esc_html( stripslashes( $client->name ) ); ?></div>
                                    <?php
                                }
                                ?>
                                <div class="item" data-value="allclients"><?php esc_html_e( 'All Clients', 'mainwp' ); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="two wide middle aligned column">
                        <div id="mainwp-module-log-filter-users" class="ui selection multiple fluid mini dropdown seg_users">
                            <input type="hidden" value="<?php echo esc_html( $filter_user_ids ); ?>">
                            <i class="dropdown icon"></i>
                            <div class="default text"><?php esc_html_e( 'All users', 'mainwp' ); ?></div>
                            <div class="menu">
                                <?php
                                $users = $manager->admin->get_all_users();
                                foreach ( $users as $user ) {
                                    ?>
                                    <div class="item" data-value="<?php echo intval( $user['id'] ); ?>"><?php echo esc_html( $user['login'] ); ?></div>
                                    <?php
                                }
                                ?>
                                <div class="item" data-value="allusers"><?php esc_html_e( 'All users', 'mainwp' ); ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="three wide middle aligned left aligned column">
                        <button onclick="mainwp_module_log_overview_content_filter()" class="ui mini green button"><?php esc_html_e( 'Filter Data', 'mainwp' ); ?></button>
                        <button onclick="mainwp_module_log_overview_content_reset_filters(this)" class="ui mini button" <?php echo $default_filter ? 'disabled="disabled"' : ''; ?>><?php esc_html_e( 'Reset Filters', 'mainwp' ); ?></button>
                    </div>
                </div>
            </div>
            <?php Log_Events_Filter_Segment::get_instance()->render_filters_segment(); ?>
        </div>
    </div>
        <?php
        MainWP_UI::render_modal_save_segment();

        $time          = time();
        $format        = 'Y-m-d';
        $ranges_values = array(
            'today'     => array(
                'start' => gmdate( $format, strtotime( 'today' ) ),
                'end'   => gmdate( $format, strtotime( 'today' ) ),
            ),
            'yesterday' => array(
                'start' => gmdate( $format, strtotime( '-1 day', $time ) ),
                'end'   => gmdate( $format, strtotime( '-1 day', $time ) ),
            ),
            'thisweek'  => array(
                'start' => gmdate( $format, strtotime( 'last monday', $time ) ),
                'end'   => gmdate( $format, $time ),
            ),
            'thismonth' => array(
                'start' => gmdate( $format, strtotime( gmdate( 'Y-m-01' ) ) ),
                'end'   => gmdate( $format, $time ),
            ),
            'lastmonth' => array(
                'start' => gmdate( $format, strtotime( 'first day of last month' ) ),
                'end'   => gmdate( $format, strtotime( 'first day of this month' ) - 1 ),
            ),
            'thisyear'  => array(
                'start' => gmdate( $format, strtotime( 'first day of January ' . gmdate( 'Y' ) ) ),
                'end'   => gmdate( $format, $time ),
            ),
            'lastyear'  => array(
                'start' => gmdate( $format, strtotime( 'first day of January ' . gmdate( 'Y' ) . '-1 year' ) ),
                'end'   => gmdate( $format, strtotime( 'last day of December ' . gmdate( 'Y' ) . '-1 year' ) ),
            ),
            'custom'    => array(
                'start' => gmdate( $format, strtotime( 'last monday', $time ) ),
                'end'   => gmdate( $format, $time ),
            ),
        );
        $ranges_values = wp_json_encode( $ranges_values );
        ?>
        <script type="text/javascript">
            jQuery( document ).ready( function( $ ) {
                var dateRanges = JSON.parse('<?php echo $ranges_values; //phpcs:ignore -- ok ?>');
                $('#mainwp-module-log-filter-ranges').dropdown({
                    onChange: function (value, text, selected) {
                        if(value == 'custom'){
                            $('#mainwp-module-log-filter-dtsstart input[type=text]').attr('disabled', false);
                            $('#mainwp-module-log-filter-dtsstop input[type=text]').attr('disabled', false);
                        } else {
                            $('#mainwp-module-log-filter-dtsstart input[type=text]').attr('disabled', 'disabled');
                            $('#mainwp-module-log-filter-dtsstop input[type=text]').attr('disabled', 'disabled');
                        }
                        $('#mainwp-module-log-filter-dtsstart').calendar('set date', dateRanges[value]['start']);
                        $('#mainwp-module-log-filter-dtsstop').calendar('set date', dateRanges[value]['end']);
                    }
                });
            });
        </script>

        <?php
    }


    /**
     * Method render_header()
     *
     * Render Insights page header.
     */
    public static function render_header() {
        $params = array(
            'title'      => esc_html__( 'Insights', 'mainwp' ),
            'which'      => 'page_insights_overview',
            'wrap_class' => 'mainwp-module-logs-content-wrap',
        );
        MainWP_UI::render_top_header( $params );
    }

    /**
     * Render layout selection.
     */
    public static function render_layout_selection() { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR - complexity.
        $screen = get_current_screen();
        ?>
        <div class="mainwp-sub-header ui right aligned segment" id="module-logs-widgets-layout-row">
            <?php MainWP_Ui_Manage_Widgets_Layout::render_edit_layout( $screen->id ); ?>
        </div>
        <?php
        MainWP_Ui_Manage_Widgets_Layout::render_modal_save_layout();
    }

    /**
     * Method load_sites_table()
     *
     * Load sites table.
     */
    public function load_events_list_table() {
        $manager                 = Log_Manager::instance();
        $this->list_events_table = new Log_Events_List_Table( $manager );
    }

    /**
     * Method ajax_events_display_rows()
     *
     * Display table rows, optimize for shared hosting or big networks.
     */
    public function ajax_events_display_rows() {
        MainWP_Post_Handler::instance()->check_security( 'mainwp_module_log_widget_insights_display_rows' );
        $this->load_events_list_table();
        $insights_filters = $this->get_insights_filters(); // get ajax filters.
        $this->list_events_table->prepare_items( false, $insights_filters );
        $output = $this->list_events_table->ajax_get_datatable_rows();
        MainWP_Logger::instance()->log_execution_time( 'ajax_events_display_rows()' );
        wp_send_json( $output );
    }

    /**
     * Method ajax_events_overview_display_rows()
     *
     * Display table rows, optimize for shared hosting or big networks.
     */
    public function ajax_events_overview_display_rows() { //phpcs:ignore -- NOSONAR -complex.
        MainWP_Post_Handler::instance()->check_security( 'mainwp_module_log_widget_events_overview_display_rows' );
        $this->load_events_list_table();

        $insights_filters = array();
        //phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( ! empty( $_POST['current_site_id'] ) ) {
            $insights_filters['wpid'] = intval( $_POST['current_site_id'] );
        } elseif ( ! empty( $_POST['current_client_id'] ) ) {
            $client_id = intval( $_POST['current_client_id'] );
            $websites  = MainWP_DB_Client::instance()->get_websites_by_client_ids( $client_id );
            $site_ids  = array();
            if ( $websites ) {
                foreach ( $websites as $website ) {
                    $site_ids[] = $website->id;
                }
            }

            if ( empty( $site_ids ) ) {
                $site_ids = -1; // not found websites.
            }

            $insights_filters['wpid'] = $site_ids;
        }
        $filter_source = isset( $_REQUEST['source'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['source'] ) ) : '';
        //phpcs:enable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        $sources_conds = '';
        if ( ! empty( $filter_source ) ) {
            $array_source_list = explode( ',', $filter_source ); // convert to array.
            if ( in_array( 'allsource', $array_source_list, true ) || ( in_array( 'dashboard', $array_source_list ) && in_array( 'wp-admin', $array_source_list ) ) ) {
                $filter_source     = '';
                $array_source_list = false;
            }

            if ( is_array( $array_source_list ) ) {
                $wpadmin_source   = true;
                $dashboard_source = true;

                if ( ! in_array( 'wp-admin', $array_source_list ) ) {
                    $wpadmin_source = false;
                }
                if ( ! in_array( 'dashboard', $array_source_list ) ) {
                    $dashboard_source = false;
                }

                if ( $wpadmin_source && ! $dashboard_source ) {
                    $sources_conds = 'wp-admin-only';
                } elseif ( ! $wpadmin_source && $dashboard_source ) {
                    $sources_conds = 'dashboard-only';
                }
            }
        }
        $insights_filters['sources_conds'] = $sources_conds;
        $this->list_events_table->prepare_items( false, $insights_filters );
        $output = $this->list_events_table->ajax_get_datatable_rows();
        MainWP_Logger::instance()->log_execution_time( 'ajax_events_display_rows()' );
        wp_send_json( $output );
    }


    /**
     * Method render_dashboard_body()
     *
     * Render the logs Dashboard Body content.
     *
     * @param array $items Logs items.
     * @param array $items_prev Logs prev items.
     * @param array $insights_filters Insights filters.
     */
    public static function render_dashboard_body( $items = array(), $items_prev = array(), $insights_filters = array() ) {
        $screen          = get_current_screen();
        $stats_data      = Log_Stats::get_stats_data( $items );
        $stats_prev_data = ! empty( $items_prev ) ? Log_Stats::get_stats_data( $items_prev ) : array();
        ?>
        <div class="mainwp-primary-content-wrap">
            <?php if ( MainWP_Utility::show_mainwp_message( 'notice', 'insights-widgets' ) ) : ?>
            <div class="ui segment">
                <div class="ui info message">
                    <i class="close icon mainwp-notice-dismiss" notice-id="insights-widgets"></i>
                    <?php printf( esc_html__( 'To hide or show a widget, click the Cog (%1$s) icon.', 'mainwp' ), '<i class="cog icon"></i>' ); ?>
                </div>
            </div>
            <?php endif; ?>
            <?php
            /**
             * Action: mainwp_before_overview_widgets
             *
             * Fires at the top of the Overview page (before first widget).
             *
             * @since 4.6
             */
            do_action( 'mainwp_before_overview_widgets', 'insights' );
            ?>
            <div id="mainwp-grid-wrapper" class="gridster">
                <?php
                MainWP_UI::do_widget_boxes(
                    $screen->id,
                    array(
                        'stats_data'       => $stats_data,
                        'stats_prev_data'  => $stats_prev_data,
                        'insights_filters' => $insights_filters,
                    )
                );
                ?>
            </div>
            <?php
            /**
             * Action: 'mainwp_after_overview_widgets'
             *
             * Fires at the bottom of the Overview page (after the last widget).
             *
             * @since 4.6
             */
            do_action( 'mainwp_after_overview_widgets', 'insights' );
            ?>
            <script type="text/javascript">
                jQuery( document ).ready( function( $ ) {

                    jQuery( '.mainwp-widget .mainwp-dropdown-tab .item' ).tab();

                    mainwp_module_log_overview_screen_options = function () {
                        jQuery( '#mainwp-module-log-overview-screen-options-modal' ).modal( {
                            allowMultiple: true,
                            onHide: function () {
                            }
                        } ).modal( 'show' );
                        return false;
                    };
                    jQuery('#reset-log-overview-widgets-settings').on('click', function () {
                        mainwp_confirm(__('Are you sure.'), function(){
                            jQuery('.mainwp_hide_wpmenu_checkboxes input[name="mainwp_show_widgets[]"]').prop('checked', true);
                            jQuery('input[name=reset_module_log_overview_widgets_settings]').attr('value', 1);
                            jQuery('#submit-log-overview-widgets-settings').click();
                        }, false, false, true);
                        return false;
                    });
                } );
            </script>
        <div class="ui modal" id="mainwp-module-log-overview-screen-options-modal">
        <i class="close icon"></i>
                <div class="header"><?php esc_html_e( 'Page Settings', 'mainwp' ); ?></div>
                <div class="content ui form">
                    <?php
                    /**
                     * Action: mainwp_module_log_overview_screen_options_top
                     *
                     * Fires at the top of the Sceen Options modal on the Overview page.
                     *
                     * @since 4.6
                     */
                    do_action( 'mainwp_module_log_overview_screen_options_top' );
                    ?>
                    <form method="POST" action="" name="mainwp_module_log_overview_screen_options_form" id="mainwp-module-log-overview-screen-options-form">
                        <?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
                        <input type="hidden" name="module_log_overview_options_nonce" value="<?php echo esc_attr( wp_create_nonce( 'module_log_overview_options_nonce' ) ); ?>" />
                        <?php static::render_screen_options( false ); ?>
                        <?php
                        /**
                         * Action: mainwp_module_log_overview_screen_options_bottom
                         *
                         * Fires at the bottom of the Sceen Options modal on the Overview page.
                         *
                         * @since 4.6
                         */
                        do_action( 'mainwp_module_log_overview_screen_options_bottom' );
                        ?>
                </div>
                <div class="actions">
                    <div class="ui two columns grid">
                        <div class="left aligned column">
                            <span data-tooltip="<?php esc_attr_e( 'Resets the page to its original layout and reinstates relocated widgets.', 'mainwp' ); ?>" data-inverted="" data-position="top left"><input type="button" class="ui button" name="reset" id="reset-log-overview-widgets-settings" value="<?php esc_attr_e( 'Reset Page', 'mainwp' ); ?>" /></span>
                        </div>
                        <div class="ui right aligned column">
                            <input type="submit" class="ui green button" id="submit-log-overview-widgets-settings" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>" />
                        </div>
                    </div>
                </div>

                <input type="hidden" name="reset_module_log_overview_widgets_settings" value="" />
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Method render_screen_options()
     *
     * Render Page Settings.
     *
     * @return void  Render Page Settings html.
     */
    public static function render_screen_options() {

        $default_widgets = array(
            'recent_events'     => esc_html__( 'Recent Activity Log', 'mainwp' ),
            'log_plugins'       => esc_html__( 'Plugin Management Activity Overview', 'mainwp' ),
            'log_themes'        => esc_html__( 'Themes Management Activity Overview', 'mainwp' ),
            'log_sites'         => esc_html__( 'Site Management Activity Metrics', 'mainwp' ),
            'log_posts'         => esc_html__( 'Posts Management Event Tracker', 'mainwp' ),
            'log_pages'         => esc_html__( 'Page Management Event Tracker', 'mainwp' ),
            'log_users'         => esc_html__( 'User Management Events Summary', 'mainwp' ),
            'log_clients'       => esc_html__( 'Client Management Activity Summary', 'mainwp' ),
            'log_graph_status'  => esc_html__( 'Site Connectivity Status', 'mainwp' ),
            'log_graph_php'     => esc_html__( 'PHP Version Distribution', 'mainwp' ),
            'log_graph_wp'      => esc_html__( 'WordPress Version Distribution', 'mainwp' ),
            'log_graph_themes'  => esc_html__( 'Active Themes Overview', 'mainwp' ),
            'log_graph_plugins' => esc_html__( 'Site Plugin Status Breakdown', 'mainwp' ),
            'log_graph_tags'    => esc_html__( 'Tag Allocation Overview', 'mainwp' ),
            'log_graph_clients' => esc_html__( 'Client Sites Distribution', 'mainwp' ),
        );

        $custom_opts = array();
        /**
         * Filter: mainwp_module_log_widgets_screen_options
         *
         * Filters available widgets on the Overview page allowing users to unsent unwanted widgets.
         *
         * @since 4.6
         */
        $custom_opts = apply_filters( 'mainwp_module_log_widgets_screen_options', $custom_opts );

        if ( is_array( $custom_opts ) && ! empty( $custom_opts ) ) {
            $default_widgets = array_merge( $default_widgets, $custom_opts );
        }

        $show_widgets = get_user_option( 'mainwp_module_log_overview_show_widgets' );
        if ( ! is_array( $show_widgets ) ) {
            $show_widgets = array();
        }

        /**
         * Action: mainwp_screen_options_modal_top
         *
         * Fires at the top of the Page Settings modal element.
         *
         * @since 4.6
         */
        do_action( 'mainwp_screen_options_modal_top' );
        ?>
        <?php if ( isset( $_GET['page'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized ?>
        <div class="ui grid field">
            <label class="six wide column"><?php esc_html_e( 'Show widgets', 'mainwp' ); ?></label>
            <div class="ten wide column" <?php echo 'data-tooltip="' . esc_attr__( 'Select widgets that you want to hide in the MainWP Overview page.', 'mainwp' ); ?> data-inverted="" data-position="top left">
                <ul class="mainwp_hide_wpmenu_checkboxes">
                <?php
                foreach ( $default_widgets as $name => $title ) {
                    $_selected = '';
                    if ( ! isset( $show_widgets[ $name ] ) || 1 === (int) $show_widgets[ $name ] ) {
                        $_selected = 'checked';
                    }
                    ?>
                    <li>
                        <div class="ui checkbox">
                            <input type="checkbox" id="mainwp_show_widget_<?php echo esc_attr( $name ); ?>" name="mainwp_show_widgets[]" <?php echo esc_html( $_selected ); ?> value="<?php echo esc_attr( $name ); ?>">
                            <label for="mainwp_show_widget_<?php echo esc_attr( $name ); ?>" ><?php echo esc_html( $title ); ?></label>
                        </div>
                        <input type="hidden" name="mainwp_widgets_name[]" value="<?php echo esc_attr( $name ); ?>">
                    </li>
                    <?php
                }
                ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>
        <?php
        /**
         * Action: mainwp_screen_options_modal_bottom
         *
         * Fires at the bottom of the Page Settings modal element.
         *
         * @since 4.6
         */
        do_action( 'mainwp_screen_options_modal_bottom' );
    }
}
