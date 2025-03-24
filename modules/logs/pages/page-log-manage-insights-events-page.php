<?php
/**
 * MainWP Site Insights Actions Class
 *
 * Displays the Site Insights Actions Info.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard\Module\Log;

use MainWP\Dashboard\MainWP_Menu;
use MainWP\Dashboard\MainWP_UI;
use MainWP\Dashboard\MainWP_Utility;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Client;
use MainWP\Dashboard\MainWP_DB_Common;
use MainWP\Dashboard\MainWP_Post_Handler;
use MainWP\Dashboard\MainWP_Logger;

/**
 * Class Log_Manage_Insights_Events_Page
 *
 * Displays the Site Insights Actions.
 */
class Log_Manage_Insights_Events_Page { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Private static variable to hold the single instance of the class.
     *
     * @static
     *
     * @var mixed Default null
     */
    private static $instance = null;

    /**
     * Private static variable to hold the single instance of the events table.
     *
     * @static
     *
     * @var mixed Default null
     */
    private $list_events_table = null;

    /**
     * Private static variable to hold the table type value.
     *
     * @var mixed Default null
     */
    private $table_id_prefix = 'manage-events';

    /**
     * Private static variable to hold the current page.
     *
     * @var mixed Default null
     */
    public static $page_current = null;

    /**
     * Method instance()
     *
     * Create public static instance.
     *
     * @static
     * @return Log_Manage_Insights_Events_Page
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Constructor.
     *
     * Run each time the class is called.
     */
    public function __construct() {
        add_action( 'mainwp_admin_menu', array( $this, 'init_menu' ), 10, 2 );
        MainWP_Post_Handler::instance()->add_action( 'mainwp_insight_events_dismiss_actions', array( &$this, 'ajax_sites_changes_dismiss_selected' ) );
        MainWP_Post_Handler::instance()->add_action( 'mainwp_insight_events_dismiss_all', array( &$this, 'ajax_sites_changes_dismiss_all' ) );
    }

    /**
     * Method get_class_name()
     *
     * @return string __CLASS__ Class name.
     */
    public static function get_class_name() {
        return __CLASS__;
    }

    /**
     * Method init_menu()
     *
     * Add Insights Overview sub menu "Insights".
     */
    public function init_menu() {
        static::$page_current = add_submenu_page(
            'mainwp_tab',
            esc_html__( 'Sites Changes', 'mainwp' ),
            '<div class="mainwp-hidden" id="mainwp-insights-actions">' . esc_html__( 'Sites Changes', 'mainwp' ) . '</div>',
            'read',
            'InsightsManage',
            array(
                $this,
                'render_insights_actions',
            )
        );
        add_action( 'load-' . static::$page_current, array( &$this, 'on_load_page' ) );
        static::init_left_menu();
    }

    /**
     * Initiates left menu.
     */
    public static function init_left_menu() {
        MainWP_Menu::add_left_menu(
            array(
                'title'                => esc_html__( 'Sites Changes', 'mainwp' ),
                'parent_key'           => 'managesites',
                'slug'                 => 'InsightsManage',
                'href'                 => 'admin.php?page=InsightsManage',
                'icon'                 => '<i class="pie chart icon"></i>',
                'desc'                 => 'Sites Changes',
                'leftsub_order_level2' => 3.4,
            ),
            2
        );
    }

    /**
     * Renders manage insights.
     *
     * @return void
     */
    public function render_insights_actions() {
        if ( ! \mainwp_current_user_can( 'dashboard', 'manage_insights_actions' ) ) {
            \mainwp_do_not_have_permissions( esc_html__( 'manage insights actions', 'mainwp' ) );
            return;
        }
        $this->on_show_page();
    }

    /**
     * Method on_load_page()
     *
     * Run on page load.
     *
     * @uses \MainWP\Dashboard\MainWP_Manage_Sites_List_Table
     * @uses \MainWP\Dashboard\MainWP_System::enqueue_postbox_scripts()
     */
    public function on_load_page() {
        add_filter( 'mainwp_header_actions_right', array( &$this, 'add_screen_options' ), 10, 2 );
    }

    /**
     * Method add_screen_options()
     *
     * Create Page Settings button.
     *
     * @param mixed $input Page Settings button HTML.
     *
     * @return mixed Page Settings button.
     */
    public function add_screen_options( $input ) {
        return $input .
        '<a class="ui button basic icon" onclick="mainwp_manage_events_screen_options(); return false;" data-inverted="" data-position="bottom right" href="#" target="_blank" data-tooltip="' . esc_html__( 'Page Settings', 'mainwp' ) . '">
            <i class="cog icon"></i>
        </a>';
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
        $this->load_events_list_table(); // for events table list.
        /**
         * Action: mainwp_logs_manage_table_top
         *
         * Fires at the top of the widget.
         *
         * @since 5.4
         */
        do_action( 'mainwp_logs_manage_table_top', 'recent_events' );
        ?>
        <div id="mainwp-message-zone" style="display:none;" class="ui message"></div>
        <?php
        wp_nonce_field( 'mainwp-admin-nonce' );
        $this->list_events_table->display();

        /**
         * Action: mainwp_logs_widget_bottom
         *
         * Fires at the bottom of the widget.
         *
         * @since 5.4
         */
        do_action( 'mainwp_logs_manage_table_bottom', 'recent_events' );
        ?>
        </div>
        <?php
        $this->render_screen_options();
    }

    /**
     * Method get_insights_filters()
     *
     * Get insights filters.
     *
     * @param bool $save_filter To save filter.
     */
    public function get_insights_filters( $save_filter = false ) { //phpcs:ignore -- NOSONAR - complex method.

        $filters = array( 'client', 'range', 'group', 'user', 'dtsstart', 'dtsstop', 'source', 'sites', 'events' );

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

        $filter_source = '';
        $filter_sites  = '';
        $filter_events = '';

        $array_clients_ids = array();
        $array_groups_ids  = array();
        $array_users_ids   = array();
        $array_sites_ids   = array();

        $array_events_list = array();
        $array_source_list = array();

        $update_filter = false;

        if ( $get_saved ) {
            $filters_saved = get_user_option( 'mainwp_module_logs_manage_filters_saved' );
            if ( ! is_array( $filters_saved ) ) {
                $filters_saved = static::get_default_filters();
            }
            $filter_ranges     = isset( $filters_saved['ranges'] ) && ! empty( $filters_saved['ranges'] ) ? $filters_saved['ranges'] : false;
            $filter_groups_ids = isset( $filters_saved['groups_ids'] ) && ! empty( $filters_saved['groups_ids'] ) ? $filters_saved['groups_ids'] : '';
            $filter_client_ids = isset( $filters_saved['client_ids'] ) && ! empty( $filters_saved['client_ids'] ) ? $filters_saved['client_ids'] : false;
            $filter_user_ids   = isset( $filters_saved['user_ids'] ) && ! empty( $filters_saved['user_ids'] ) ? $filters_saved['user_ids'] : '';
            $filter_dtsstart   = isset( $filters_saved['dtsstart'] ) && ! empty( $filters_saved['dtsstart'] ) ? $filters_saved['dtsstart'] : '';
            $filter_dtsstop    = isset( $filters_saved['dtsstop'] ) && ! empty( $filters_saved['dtsstop'] ) ? $filters_saved['dtsstop'] : '';

            $filter_source = isset( $filters_saved['source'] ) && ! empty( $filters_saved['source'] ) ? $filters_saved['source'] : '';
            $filter_sites  = isset( $filters_saved['sites'] ) && ! empty( $filters_saved['sites'] ) ? $filters_saved['sites'] : '';
            $filter_events = isset( $filters_saved['events'] ) && ! empty( $filters_saved['events'] ) ? $filters_saved['events'] : '';

        } else {
            // phpcs:disable WordPress.Security.NonceVerification
            $filter_ranges     = isset( $_REQUEST['range'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['range'] ) ) : '';
            $filter_groups_ids = isset( $_REQUEST['group'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['group'] ) ) : '';
            $filter_client_ids = isset( $_REQUEST['client'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['client'] ) ) : '';
            $filter_user_ids   = isset( $_REQUEST['user'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['user'] ) ) : '';
            $filter_dtsstart   = isset( $_REQUEST['dtsstart'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['dtsstart'] ) ) : '';
            $filter_dtsstop    = isset( $_REQUEST['dtsstop'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['dtsstop'] ) ) : '';

            $filter_source = isset( $_REQUEST['source'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['source'] ) ) : '';
            $filter_sites  = isset( $_REQUEST['sites'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['sites'] ) ) : '';
            $filter_events = isset( $_REQUEST['events'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['events'] ) ) : '';

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

        if ( ! empty( $filter_sites ) ) {
            $array_sites_ids = explode( ',', $filter_sites ); // convert to array.
            if ( in_array( 'allsites', $array_sites_ids, true ) ) {
                $array_sites_ids = false;
                $filter_sites    = '';
            } else {
                $array_sites_ids = MainWP_Utility::array_numeric_filter( $array_sites_ids );
                $filter_sites    = implode( ',', $array_sites_ids );
            }
        }

        if ( ! empty( $filter_events ) ) {
            $array_events_list = explode( ',', $filter_events ); // convert to array.
            if ( in_array( 'allevents', $array_events_list, true ) ) {
                $filter_events     = '';
                $array_events_list = false;
            }
        }

        if ( $save_filter && $update_filter ) {
            MainWP_Utility::update_user_option(
                'mainwp_module_logs_manage_filters_saved',
                array(
                    'ranges'     => $filter_ranges,
                    'groups_ids' => $filter_groups_ids,
                    'client_ids' => $filter_client_ids,
                    'user_ids'   => $filter_user_ids,
                    'dtsstart'   => $filter_dtsstart,
                    'dtsstop'    => $filter_dtsstop,
                    'source'     => $filter_source,
                    'sites'      => $filter_sites,
                    'events'     => $filter_events,
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
            'filter_source',
            'filter_sites',
            'filter_events',
            'sources_conds',
            'array_sites_ids',
            'array_events_list',
            'array_source_list'
        );
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

        $filter_source = '';
        $filter_sites  = '';
        $filter_events = '';

        $array_source_list = array();
        $array_sites_ids   = array();
        $array_events_list = array();

        extract( $insights_filters ); //phpcs:ignore -- ok.

        $default_filter = false;
        // extracted values.
        if ( ( empty( $filter_ranges ) || 'thismonth' === $filter_ranges ) && empty( $filter_groups_ids ) && empty( $filter_client_ids ) && empty( $filter_user_ids ) && empty( $filter_user_ids ) && empty( $filter_dtsstart ) && empty( $filter_dtsstop ) && empty( $array_clients_ids ) && empty( $array_groups_ids ) && empty( $array_users_ids ) && empty( $array_source_list ) && empty( $array_sites_ids ) && empty( $array_events_list ) ) {
            $default_filter = true;
        }

        $disable_dt = ( '' === $filter_ranges || 'custom' === $filter_ranges ) ? false : true;

        $groups = MainWP_DB_Common::instance()->get_groups_for_current_user();
        if ( ! is_array( $groups ) ) {
            $groups = array();
        }

        ?>
    <div class="mainwp-sub-header" id="mainwp-module-log-overview-sub-header">
        <div class="ui message" style="display: none;" id="mainwp-message-zone-top"></div>
        <div class="ui stackable grid">
            <div class="eight wide middle aligned column">
                <a href="javascript:void(0)" id="mainwp_sites_changes_bulk_dismiss_selected_btn" class="ui button mini basic"><?php esc_html_e( 'Dismiss Selected Changes', 'mainwp' ); ?></a>
                <a href="javascript:void(0)" id="mainwp_sites_changes_bulk_dismiss_all_btn" class="ui mini green button"><?php esc_html_e( 'Dismiss All Changes', 'mainwp' ); ?></a>
            </div>
            <div class="eight wide right aligned middle aligned column">
                <span data-tooltip="<?php esc_html_e( 'Click to filter sites.', 'mainwp' ); ?>" data-position="bottom right" data-inverted="">
                    <a href="#" class="ui mini icon basic button" id="mainwp-sites-changes-filter-toggle-button">
                        <i class="filter icon"></i> <?php esc_html_e( 'Filter Sites Changes', 'mainwp' ); ?>
                    </a>
                </span>
            </div>
        </div>

        <div class="ui stackable grid" id="mainwp-module-log-filters-row" style="display:none">
            <div class="twelve wide column ui">
                <div class="ui stackable compact grid mini form">
                    <div class="two wide middle aligned column">
                        <div id="mainwp-module-log-filter-ranges" class="ui selection fluid dropdown seg_ranges not-auto-init">
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
                        <div class="ui calendar mainwp_datepicker seg_dtsstart" id="mainwp-module-log-filter-dtsstart" >
                            <div class="ui input left fluid icon">
                                <i class="calendar icon"></i>
                                <input type="text" <?php echo $disable_dt ? 'disabled="disabled"' : ''; ?> autocomplete="off" placeholder="<?php esc_attr_e( 'Start date', 'mainwp' ); ?>" value="<?php echo ! empty( $filter_dtsstart ) ? esc_attr( $filter_dtsstart ) : ''; ?>"/>
                            </div>
                        </div>
                    </div>
                    <div class="two wide middle aligned column">
                        <div class="ui calendar mainwp_datepicker seg_dtsstop" id="mainwp-module-log-filter-dtsstop" >
                            <div class="ui input left icon">
                                <i class="calendar icon"></i>
                                <input type="text" <?php echo $disable_dt ? 'disabled="disabled"' : ''; ?> autocomplete="off" placeholder="<?php esc_attr_e( 'End date', 'mainwp' ); ?>" value="<?php echo ! empty( $filter_dtsstop ) ? esc_attr( $filter_dtsstop ) : ''; ?>"/>
                            </div>
                        </div>
                    </div>
                    <div class="two wide middle aligned column">
                        <div id="mainwp-module-log-filter-groups" class="ui selection multiple fluid dropdown seg_groups">
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
                        <div id="mainwp-module-log-filter-clients" class="ui selection multiple fluid dropdown seg_clients">
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
                        <div id="mainwp-module-log-filter-users" class="ui selection multiple fluid dropdown seg_users">
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
                    <?php
                    // add filters: filter_events, filter_source and filter_sites.
                    ?>
                    <div class="two wide middle aligned column">
                        <div id="mainwp-module-log-filter-events" class="ui selection multiple fluid dropdown seg_events">
                                <input type="hidden" value="<?php echo esc_attr( $filter_events ); ?>">
                                <i class="dropdown icon"></i>
                                <div class="default text"><?php esc_html_e( 'All Events', 'mainwp' ); ?></div>
                                <div class="menu">
                                    <?php
                                    $manager = Log_Manager::instance();
                                    $events  = $manager->connectors->term_labels['logs_action'];
                                    if ( ! is_array( $events ) ) {
                                        $events = array();
                                    }

                                    foreach ( $events as $eve_name => $eve_title ) {
                                        ?>
                                        <div class="item" data-value="<?php echo esc_attr( $eve_name ); ?>"><?php echo esc_html( stripslashes( $eve_title ) ); ?></div>
                                        <?php
                                    }
                                    ?>
                                    <div class="item" data-value="allevents"><?php esc_html_e( 'All Events', 'mainwp' ); ?></div>
                                </div>
                        </div>
                    </div>
                    <div class="two wide middle aligned column">
                        <div id="mainwp-module-log-filter-source" class="ui selection multiple fluid dropdown seg_source">
                                <input type="hidden" value="<?php echo esc_attr( $filter_source ); ?>">
                                <i class="dropdown icon"></i>
                                <div class="default text"><?php esc_html_e( 'All Source', 'mainwp' ); ?></div>
                                <div class="menu">
                                    <?php
                                    $seg_source = array(
                                        'dashboard' => esc_html__( 'Dashboard', 'maiwp' ),
                                        'wp-admin'  => esc_html__( 'WP Admin', 'maiwp' ),
                                    );
                                    foreach ( $seg_source as $sou_name => $sou_title ) {
                                        ?>
                                        <div class="item" data-value="<?php echo esc_attr( $sou_name ); ?>"><?php echo esc_html( stripslashes( $sou_title ) ); ?></div>
                                        <?php
                                    }
                                    ?>
                                    <div class="item" data-value="allsource"><?php esc_html_e( 'All Source', 'mainwp' ); ?></div>
                                </div>
                        </div>
                    </div>

                    <div class="two wide middle aligned column">
                        <div id="mainwp-module-log-filter-sites" class="ui selection multiple fluid dropdown seg_sites">
                                <input type="hidden" value="<?php echo esc_attr( $filter_sites ); ?>">
                                <i class="dropdown icon"></i>
                                <div class="default text"><?php esc_html_e( 'All Websites', 'mainwp' ); ?></div>
                                <div class="menu">
                                    <?php
                                    $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user_by_params() );
                                    while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) {
                                        ?>
                                        <div class="item" data-value="<?php echo esc_attr( $website->id ); ?>"><?php echo esc_html( MainWP_Utility::get_nice_url( stripslashes( $website->name ) ) ); ?></div>
                                        <?php
                                    }

                                    ?>
                                    <div class="item" data-value="allsites"><?php esc_html_e( 'All Websites', 'mainwp' ); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="four wide middle aligned left aligned column">
                        <button onclick="mainwp_module_log_manage_events_filter()" class="ui mini green button"><?php esc_html_e( 'Filter Data', 'mainwp' ); ?></button>
                        <button onclick="mainwp_module_log_manage_events_reset_filters(this)" class="ui mini button" <?php echo $default_filter ? 'disabled="disabled"' : ''; ?>><?php esc_html_e( 'Reset Filters', 'mainwp' ); ?></button>
                    </div>
                </div>
            </div>
            <?php Log_Events_Filter_Segment::get_instance()->render_filters_segment( 'module_log_manage' ); ?>
        </div>
    </div>
        <?php
        MainWP_UI::render_modal_save_segment( 'manage-events' );

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

                mainwp_module_log_manage_events_filter = function() {
                    let range = jQuery( '#mainwp-module-log-filter-ranges').dropdown('get value');
                    let group = jQuery( '#mainwp-module-log-filter-groups').dropdown('get value');
                    let client = jQuery( '#mainwp-module-log-filter-clients').dropdown('get value');
                    let user = jQuery( '#mainwp-module-log-filter-users').dropdown('get value');
                    let dtsstart = jQuery('#mainwp-module-log-filter-dtsstart input[type=text]').val();
                    let dtsstop = jQuery('#mainwp-module-log-filter-dtsstop input[type=text]').val();

                    let source = jQuery( '#mainwp-module-log-filter-source').dropdown('get value');
                    let sites = jQuery( '#mainwp-module-log-filter-sites').dropdown('get value');
                    let events = jQuery( '#mainwp-module-log-filter-events').dropdown('get value');

                    let params = '';
                    params += '&range=' + encodeURIComponent( range );
                    params += '&group=' + encodeURIComponent( group );
                    params += '&client=' + encodeURIComponent( client );
                    params += '&user=' + encodeURIComponent( user );
                    params += '&dtsstart=' + encodeURIComponent( dtsstart );
                    params += '&dtsstop=' + encodeURIComponent( dtsstop );

                    params += '&source=' + encodeURIComponent( source );
                    params += '&sites=' + encodeURIComponent( sites );
                    params += '&events=' + encodeURIComponent( events );

                    params += '&_insights_opennonce=' + mainwpParams._wpnonce;
                    window.location = 'admin.php?page=InsightsManage' + params;
                    return false;
                };

                mainwp_module_log_manage_events_reset_filters = function(resetObj) {
                    try {
                        jQuery( '#mainwp-module-log-filter-ranges').dropdown('set selected', 'thismonth');
                        jQuery( '#mainwp-module-log-filter-groups').dropdown('clear');
                        jQuery( '#mainwp-module-log-filter-clients').dropdown('clear');
                        jQuery( '#mainwp-module-log-filter-users').dropdown('clear');
                        jQuery('#mainwp-module-log-filter-dtsstart input[type=text]').val('');
                        jQuery('#mainwp-module-log-filter-dtsstop input[type=text]').val('');

                        jQuery( '#mainwp-module-log-filter-source').dropdown('clear');
                        jQuery( '#mainwp-module-log-filter-sites').dropdown('clear');
                        jQuery( '#mainwp-module-log-filter-events').dropdown('clear');

                        jQuery(resetObj).attr('disabled', 'disabled');
                        mainwp_module_log_manage_events_filter();
                    } catch(err) {
                        // to fix js error.
                        console.log(err);
                    }
                };

            });
        </script>

        <?php
    }

    /**
     * Method load_sites_table()
     *
     * Load sites table.
     */
    public function load_events_list_table() {
        $manager                 = Log_Manager::instance();
        $this->list_events_table = new Log_Events_List_Table( $manager, $this->table_id_prefix );
    }

    /**
     * Method ajax_manage_events_display_rows()
     *
     * Display table rows, optimize for shared hosting or big networks.
     */
    public function ajax_manage_events_display_rows() {
        MainWP_Post_Handler::instance()->check_security( 'mainwp_module_log_manage_events_display_rows' );
        $this->load_events_list_table();
        $insights_filters = $this->get_insights_filters(); // get ajax filters.
        $this->list_events_table->prepare_items( false, $insights_filters );
        $output = $this->list_events_table->ajax_get_datatable_rows();
        MainWP_Logger::instance()->log_execution_time( 'ajax_events_display_rows()' );
        wp_send_json( $output );
    }


    /**
     * Method render_header()
     *
     * Render page header.
     */
    public static function render_header() {
        $params = array(
            'title'      => esc_html__( 'Sites Changes', 'mainwp' ),
            'which'      => 'page_log_manage_insights',
            'wrap_class' => 'mainwp-log-mananage-insights-wrapper',
        );
        MainWP_UI::render_top_header( $params );
    }

    /**
     * Method ajax_sites_changes_dismiss_selected()
     */
    public function ajax_sites_changes_dismiss_selected() {
        MainWP_Post_Handler::instance()->secure_request( 'mainwp_insight_events_dismiss_actions' );
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $log_id = isset( $_POST['log_id'] ) ? intval( $_POST['log_id'] ) : 0;
        $log    = false;
        if ( ! empty( $log_id ) ) {
            $log = Log_DB_Helper::instance()->get_log_by_id( $log_id );
        }
        if ( empty( $log ) ) {
            wp_die( wp_json_encode( array( 'error' => 'Invalid change ID or Change not found.' ) ) );
        }
        $update = array(
            'log_id'  => $log_id,
            'dismiss' => 1,
        );
        Log_DB_Helper::instance()->update_log( $update );
        wp_die( wp_json_encode( array( 'success' => 'yes' ) ) );
    }

    /**
     * Method ajax_sites_changes_dismiss_all()
     */
    public function ajax_sites_changes_dismiss_all() {
        MainWP_Post_Handler::instance()->secure_request( 'mainwp_insight_events_dismiss_all' );
        Log_DB_Helper::instance()->dismiss_all_changes();
        wp_die( wp_json_encode( array( 'success' => 'yes' ) ) );
    }

    /**
     * Method render_screen_options()
     *
     * Render Page Settings Modal.
     */
    public function render_screen_options() {  // phpcs:ignore -- NOSONAR -Current complexity is the only way to achieve desired results, pull request solutions appreciated.

        $columns = $this->list_events_table->get_columns();

        if ( isset( $columns['cb'] ) ) {
            unset( $columns['cb'] );
        }

        if ( isset( $columns['status'] ) ) {
            $columns['status'] = esc_html__( 'Status', 'mainwp' );
        }

        $sites_per_page = get_option( 'mainwp_default_manage_insights_events_per_page', 25 );

        if ( isset( $columns['col_action'] ) && empty( $columns['col_action'] ) ) {
            $columns['col_action'] = esc_html__( 'Actions', 'mainwp' );
        }

        $show_cols = get_user_option( 'mainwp_settings_show_insights_events_columns' );

        if ( false === $show_cols ) { // to backwards.
            $show_cols = array();
            foreach ( $columns as $name => $title ) {
                $show_cols[ $name ] = 1;
            }
            $user = wp_get_current_user();
            if ( $user ) {
                update_user_option( $user->ID, 'mainwp_settings_show_insights_events_columns', $show_cols, true );
            }
        }

        if ( ! is_array( $show_cols ) ) {
            $show_cols = array();
        }

        ?>
        <div class="ui modal" id="mainwp-manage-events-screen-options-modal">
            <i class="close icon"></i>
            <div class="header"><?php esc_html_e( 'Page Settings', 'mainwp' ); ?></div>
            <div class="scrolling content ui form">
                <form method="POST" action="" id="manage-events-screen-options-form" name="manage_sites_screen_options_form">
                    <?php wp_nonce_field( 'mainwp-admin-nonce' ); ?>
                    <input type="hidden" name="wp_nonce" value="<?php echo esc_attr( wp_create_nonce( 'ManageEventsScrOptions' ) ); ?>" />
                    <div class="ui grid field">
                        <label class="six wide column"><?php esc_html_e( 'Default items per page value', 'mainwp' ); ?></label>
                        <div class="ten wide column">
                            <input type="text" name="mainwp_default_sites_per_page" id="mainwp_default_sites_per_page" saved-value="<?php echo intval( $sites_per_page ); ?>" value="<?php echo intval( $sites_per_page ); ?>"/>
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

                                            if ( 'added_datetime' === $name && ! isset( $show_cols[ $name ] ) ) {
                                                $show_col = false; // default is hidden.
                                            }

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
                    <div class="ui hidden divider"></div>
                    <div class="ui hidden divider"></div>
                </div>
                <div class="actions">
                    <div class="ui two columns grid">
                        <div class="left aligned column">
                            <span data-tooltip="<?php esc_attr_e( 'Resets the page to its original layout and reinstates relocated columns.', 'mainwp' ); ?>" data-inverted="" data-position="top center"><input type="button" class="ui button" name="reset" id="reset-manage-events-settings" value="<?php esc_attr_e( 'Reset Page', 'mainwp' ); ?>" /></span>
                        </div>
                        <div class="ui right aligned column">
                    <input type="submit" class="ui green button" name="btnSubmit" id="submit-manage-events-settings" value="<?php esc_attr_e( 'Save Settings', 'mainwp' ); ?>" />
                </div>
                    </div>
                </div>
                <input type="hidden" name="reset_manage_events_columns_order" value="0">
            </form>
        </div>
        <script type="text/javascript">
            jQuery( document ).ready( function () {
                jQuery('#reset-manage-events-settings').on( 'click', function () {
                    mainwp_confirm(__( 'Are you sure.' ), function(){
                        jQuery('input[name=mainwp_default_sites_per_page]').val(25);
                        jQuery('.mainwp_hide_wpmenu_checkboxes input[id^="mainwp_show_column_"]').prop( 'checked', false );
                        //default columns.
                        let cols = ['event', 'log_object', 'created','log_site_name','user_id', 'source', 'col_action'];
                        jQuery.each( cols, function ( index, value ) {
                            jQuery('.mainwp_hide_wpmenu_checkboxes input[id="mainwp_show_column_' + value + '"]').prop( 'checked', true );
                        } );
                        jQuery('input[name=reset_manage_events_columns_order]').attr('value',1);
                        jQuery('#submit-manage-events-settings').click();
                    }, false, false, true );
                    return false;
                });
            } );
        </script>
        <?php
    }
}
