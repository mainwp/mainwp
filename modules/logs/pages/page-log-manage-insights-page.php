<?php
/**
 * MainWP Site Insights Actions Class
 *
 * Displays the Site Insights Actions Info.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard\Module\Log;

use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_DB_Site_Actions;
use MainWP\Dashboard\MainWP_Menu;
use MainWP\Dashboard\MainWP_UI;
use MainWP\Dashboard\MainWP_Utility;
use MainWP\Dashboard\MainWP_DB_Client;
use MainWP\Dashboard\MainWP_DB_Common;
use MainWP\Dashboard\MainWP_Post_Handler;
use MainWP\Dashboard\MainWP_Logger;

/**
 * Class Log_Manage_Insights_Page
 *
 * Displays the Site Insights Actions.
 */
class Log_Manage_Insights_Page { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

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
    private $table_type = null;


    /**
     * Method instance()
     *
     * Create public static instance.
     *
     * @static
     * @return Log_Manage_Insights_Page
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
    public function __construct( $type = 'widget' ) {
        $this->table_type = 'manage';
        add_action( 'mainwp_admin_menu', array( $this, 'init_menu' ), 10, 2 );
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
        add_submenu_page(
            'mainwp_tab',
            esc_html__( 'Insights Changes', 'mainwp' ),
            '<div class="mainwp-hidden" id="mainwp-insights-actions">' . esc_html__( 'Insights Changes', 'mainwp' ) . '</div>',
            'read',
            'InsightsManage',
            array(
                $this,
                'render_insights_actions',
            )
        );
        static::init_left_menu();
    }

    /**
     * Initiates left menu.
     */
    public static function init_left_menu() {
        MainWP_Menu::add_left_menu(
            array(
                'title'                => esc_html__( 'Insights Changes', 'mainwp' ),
                'parent_key'           => 'managesites',
                'slug'                 => 'InsightsManage',
                'href'                 => 'admin.php?page=InsightsManage',
                'icon'                 => '<i class="pie chart icon"></i>',
                'desc'                 => 'Insights Changes',
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
     * Method on_show_page()
     *
     * When the page loads render the body content.
     */
    public function on_show_page() {

        static::render_header( 'overview' );

        $insights_filters = $this->get_insights_filters( true );
        static::render_logs_overview_top( $insights_filters );
        $this->load_events_list_table();
        $this->list_events_table->prepare_items( true, $insights_filters );
        ?>
        <div class="ui segment" id="mainwp-non-mainwp-mananage-actions-overview">
        <?php
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
                'mainwp_module_logs_manage_filters_saved',
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
    public static function render_logs_overview_top( $insights_filters ) {
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
        ?>
    <div class="mainwp-sub-header" id="mainwp-module-log-overview-sub-header">
        <div class="ui stackable compact grid mini form" id="mainwp-module-log-filters-row">
            <div class="twelve wide column ui compact grid">
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
                <div class="three wide middle aligned left aligned column">
                    <button onclick="mainwp_module_log_manage_content_filter()" class="ui mini green button"><?php esc_html_e( 'Filter Data', 'mainwp' ); ?></button>
                    <button onclick="mainwp_module_log_manage_content_reset_filters(this)" class="ui mini button" <?php echo $default_filter ? 'disabled="disabled"' : ''; ?>><?php esc_html_e( 'Reset Filters', 'mainwp' ); ?></button>
                </div>
            </div>
            <?php Log_Events_Filter_Segment::get_instance()->render_filters_segment( 'module_log_manage' ); ?>
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

                mainwp_module_log_manage_content_filter = function() {
                    let range = jQuery( '#mainwp-module-log-filter-ranges').dropdown('get value');
                    let group = jQuery( '#mainwp-module-log-filter-groups').dropdown('get value');
                    let client = jQuery( '#mainwp-module-log-filter-clients').dropdown('get value');
                    let user = jQuery( '#mainwp-module-log-filter-users').dropdown('get value');
                    let dtsstart = jQuery('#mainwp-module-log-filter-dtsstart input[type=text]').val();
                    let dtsstop = jQuery('#mainwp-module-log-filter-dtsstop input[type=text]').val();
                    let params = '';
                    params += '&range=' + encodeURIComponent( range );
                    params += '&group=' + encodeURIComponent( group );
                    params += '&client=' + encodeURIComponent( client );
                    params += '&user=' + encodeURIComponent( user );
                    params += '&dtsstart=' + encodeURIComponent( dtsstart );
                    params += '&dtsstop=' + encodeURIComponent( dtsstop );
                    params += '&_insights_opennonce=' + mainwpParams._wpnonce;
                    window.location = 'admin.php?page=InsightsManage' + params;
                    return false;
                };

                mainwp_module_log_manage_content_reset_filters = function(resetObj) {
                    try {
                        let range = jQuery( '#mainwp-module-log-filter-ranges').dropdown('set selected', 'thismonth');
                        let group = jQuery( '#mainwp-module-log-filter-groups').dropdown('clear');
                        let client = jQuery( '#mainwp-module-log-filter-clients').dropdown('clear');
                        let user = jQuery( '#mainwp-module-log-filter-users').dropdown('clear');
                        let dtsstart = jQuery('#mainwp-module-log-filter-dtsstart input[type=text]').val('');
                        let dtsstop = jQuery('#mainwp-module-log-filter-dtsstop input[type=text]').val('');
                        jQuery(resetObj).attr('disabled', 'disabled');
                        mainwp_module_log_manage_content_filter();
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
        $this->list_events_table = new Log_Events_List_Table( $manager, $this->table_type );
    }

    /**
     * Method ajax_events_display_rows()
     *
     * Display table rows, optimize for shared hosting or big networks.
     */
    public function ajax_events_display_rows() {
        MainWP_Post_Handler::instance()->check_security( 'mainwp_module_log_manage_display_rows' );
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
            'title'      => esc_html__( 'Insights Changes', 'mainwp' ),
            'which'      => 'page_log_manage_insights',
            'wrap_class' => 'mainwp-log-mananage-insights-wrapper',
        );
        MainWP_UI::render_top_header( $params );
    }


    /**
     * Render Actions Bar
     *
     * Renders the actions bar on the Dashboard tab.
     */
    public function render_actions_bar() {
        $params       = array(
            'total_count' => true,
        );
        $totalRecords = MainWP_DB_Site_Actions::instance()->get_wp_actions( $params );
        ?>
        <div class="mainwp-actions-bar">
            <div class="ui two columns grid">
                <div class="column ui mini form">
                        <select class="ui dropdown" id="non_mainwp_actions_bulk_action">
                            <option value="-1"><?php esc_html_e( 'Bulk actions', 'mainwp' ); ?></option>
                            <option value="dismiss"><?php esc_html_e( 'Dismiss', 'mainwp' ); ?></option>
                        </select>
                        <input type="button" name="mainwp_non_mainwp_actions_action_btn" id="mainwp_non_mainwp_actions_action_btn" class="ui basic mini button" value="<?php esc_html_e( 'Apply', 'mainwp' ); ?>"/>
                        <?php do_action( 'mainwp_non_mainwp_actions_actions_bar_left' ); ?>
                    </div>
                <div class="right aligned middle aligned column">
                    <?php if ( $totalRecords ) : ?>
                        <a href="javascript:void(0)" id="mainwp-delete-all-nonmainwp-actions-button" class="ui button mini green"><?php esc_html_e( 'Clear All Non-MainWP Changes', 'mainwp' ); ?></a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }
}
