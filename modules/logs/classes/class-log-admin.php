<?php
/**
 * Centralized manager for WordPress backend functionality.
 *
 * @package MainWP\Dashboard
 * @version 4.5.1
 */

namespace MainWP\Dashboard\Module\Log;

use MainWP\Dashboard\MainWP_Logger;
use MainWP\Dashboard\MainWP_Post_Handler;


defined( 'ABSPATH' ) || exit;

use DateTime;
use DateTimeZone;
use DateInterval;
use WP_CLI;

/**
 * Class - Log_Admin
 */
class Log_Admin {

    /**
     * Holds Instance of manager object
     *
     * @var Log_manager
     */
    public $manager;


    /**
     * Menu page screen id
     *
     * @var string
     */
    public $screen_id = array();

    /**
     * List table object
     *
     * @var List_Table
     */
    public $list_table = null;

    /**
     * Parent page of the records and settings pages
     *
     * @var string
     */
    public $admin_parent_page = 'admin.php';

    /**
     * Class constructor.
     *
     * @param Log_Manager $manager Instance of manager object.
     */
    public function __construct( $manager ) {
        $this->manager = $manager;
        add_action( 'init', array( &$this, 'init' ) );
        // Load admin scripts and styles.
        add_action(
            'admin_enqueue_scripts',
            array(
                $this,
                'admin_enqueue_scripts',
            ),
            9
        );

        // Auto purge setup.
        add_action( 'wp_loaded', array( $this, 'hook_purge_scheduled_action' ) );
        add_action( 'admin_init', array( $this, 'admin_init' ) );
    }

    /**
     * Initiate Hooks
     *
     * Initiates hooks for the Dashboard insights module.
     */
    public function init() {
        add_action( 'mainwp_help_sidebar_content', array( $this, 'mainwp_help_content' ) );
    }

    /**
     * Handle admin_init action.
     */
    public function admin_init() {
        MainWP_Post_Handler::instance()->add_action( 'mainwp_module_log_delete_records', array( $this, 'ajax_delete_records' ) );
        MainWP_Post_Handler::instance()->add_action( 'mainwp_module_log_compact_records', array( $this, 'ajax_compact_records' ) );
        MainWP_Post_Handler::instance()->add_action( 'mainwp_module_log_manage_events_display_rows', array( Log_Manage_Insights_Events_Page::instance(), 'ajax_manage_events_display_rows' ) );
        MainWP_Post_Handler::instance()->add_action( 'mainwp_module_log_widget_insights_display_rows', array( Log_Insights_Page::instance(), 'ajax_events_display_rows' ) );
        MainWP_Post_Handler::instance()->add_action( 'mainwp_module_log_widget_events_overview_display_rows', array( Log_Insights_Page::instance(), 'ajax_events_overview_display_rows' ) );
        Log_Events_Filter_Segment::get_instance()->admin_init();
    }

    /**
     * Enqueue scripts/styles for admin screen
     *
     * @action admin_enqueue_scripts
     *
     * @param string $hook  Current hook.
     *
     * @return void
     */
    public function admin_enqueue_scripts( $hook ) {
        $script_screens = array( 'mainwp_page_InsightsOverview', 'mainwp_page_SettingsDashboardInsights', 'mainwp_page_SettingsInsights' );
        wp_enqueue_style( 'mainwp-module-log-admin', $this->manager->locations['url'] . 'ui/css/admin.css', array(), $this->manager->get_version() );

        if ( in_array( $hook, $script_screens, true ) ) {
            wp_enqueue_script(
                'mainwp-module-log-admin',
                $this->manager->locations['url'] . 'ui/js/admin.js',
                array(
                    'jquery',
                    'mainwp',
                ),
                $this->manager->get_version(),
                false
            );

            if ( in_array( $hook, array( 'mainwp_page_InsightsOverview' ), true ) ) {
                add_filter(
                    'mainwp_admin_enqueue_scripts',
                    function ( $scripts ) {
                        if ( is_array( $scripts ) ) {
                            $scripts['apexcharts'] = true;
                        }
                        return $scripts;
                    }
                );
            }

            wp_localize_script(
                'mainwp-module-log-admin',
                'mainwpModuleLog',
                array(
                    'i18n'       => array(),
                    'gmt_offset' => get_option( 'gmt_offset' ),
                )
            );
        }
    }

    /**
     * Handle ajax delete logs records.
     */
    public function ajax_delete_records() {
        MainWP_Post_Handler::instance()->check_security( 'mainwp_module_log_delete_records' );

        $start_date = isset( $_POST['startdate'] ) ? sanitize_text_field( wp_unslash( $_POST['startdate'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $end_date   = isset( $_POST['enddate'] ) ? sanitize_text_field( wp_unslash( $_POST['enddate'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        $start_time = ! empty( $start_date ) ? strtotime( $start_date . ' 00:00:00' ) : '';
        $end_time   = ! empty( $end_date ) ? strtotime( $end_date . ' 23:59:59' ) : '';

        if ( ! is_numeric( $start_time ) || ! is_numeric( $end_time ) || $start_time > $end_time ) {
            die( wp_json_encode( array( 'error' => esc_html__( 'Invalid Start date or end date. Please try again.', 'mainwp' ) ) ) );
        }

        $this->manager->db->create_compact_and_erase_records( $start_time, $end_time );

        wp_send_json( array( 'result' => 'SUCCESS' ) );
    }


    /**
     * Handle ajax compact logs records.
     */
    public function ajax_compact_records() {
        MainWP_Post_Handler::instance()->check_security( 'mainwp_module_log_compact_records' );

        $year = isset( $_POST['year'] ) ? intval( $_POST['year'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if ( $year < 2022 ) {
            die( wp_json_encode( array( 'error' => esc_html__( 'Invalid selected year. Please try again.', 'mainwp' ) ) ) );
        }

        $aday = $year . '-12-15'; // a day in last month.

        $start_time = strtotime( $year . '-1-1 00:00:00' );
        $end_time   = strtotime( gmdate( 'Y-m-t', strtotime( $aday ) ) . ' 23:59:59' );

        $this->manager->db->create_compact_and_erase_records( $start_time, $end_time );

        wp_send_json( array( 'result' => 'SUCCESS' ) );
    }

    /**
     * Schedules a purge of records.
     *
     * @return void
     */
    public function hook_purge_scheduled_action() { //phpcs:ignore -- NOSONAR - complex.
        $enable_schedule = is_array( $this->manager->settings->options ) && ! empty( $this->manager->settings->options['enabled'] ) && ! empty( $this->manager->settings->options['auto_purge'] ) ? true : false;
        if ( $enable_schedule ) {
            $last_purge = get_option( 'mainwp_module_log_last_time_auto_purge_logs' );
            $next_purge = get_option( 'mainwp_module_log_next_time_auto_purge_logs' );
            $days       = 100;
            if ( is_array( $this->manager->settings->options ) && isset( $this->manager->settings->options['records_ttl'] ) ) {
                $days = intval( $this->manager->settings->options['records_ttl'] );
            }

            if ( defined( 'MAINWP_MODULE_LOG_KEEP_RECORDS_TTL' ) && is_numeric( MAINWP_MODULE_LOG_KEEP_RECORDS_TTL ) && MAINWP_MODULE_LOG_KEEP_RECORDS_TTL > 0 ) {
                $days = MAINWP_MODULE_LOG_KEEP_RECORDS_TTL;
            }

            if ( $days ) {
                $time            = time();
                $next_time_purge = false;
                if ( false === $last_purge && false === $next_purge ) {
                    $next_time_purge = $time + $days * DAY_IN_SECONDS;
                } elseif ( ! empty( $next_purge ) && $time > (int) $next_purge ) {
                    do_action( 'mainwp_log_action', 'module log :: purge logs schedule start.', MainWP_Logger::LOGS_AUTO_PURGE_LOG_PRIORITY );
                    $end_time   = $time - $days * DAY_IN_SECONDS;
                    $start_time = ! empty( $last_purge ) ? $last_purge : $end_time - $days * DAY_IN_SECONDS;
                    $this->manager->db->create_compact_and_erase_records( $start_time, $end_time );
                    update_option( 'mainwp_module_log_last_time_auto_purge_logs', $time );
                    $next_time_purge = $time + $days * DAY_IN_SECONDS;
                }
                if ( $next_time_purge ) {
                    update_option( 'mainwp_module_log_next_time_auto_purge_logs', $next_time_purge );
                }
            }
        }
    }


    /**
     * Get db size.
     *
     * @return string Return current db size.
     */
    public function get_db_size() {
        $size = get_transient( 'mainwp_module_log_transient_db_logs_size' );
        if ( false !== $size ) {
            return $size;
        }

        global $wpdb;
        $sql = $wpdb->prepare(
            'SELECT
        ROUND(SUM(DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2)
        FROM INFORMATION_SCHEMA.TABLES
        WHERE
        TABLE_SCHEMA = %s
        AND table_name = %s
        OR table_name = %s',
            $wpdb->dbname,
            $wpdb->mainwp_tbl_logs,
            $wpdb->mainwp_tbl_logs_meta
        );

        $dbsize_mb = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- prepared SQL.

        set_transient( 'mainwp_module_log_transient_db_logs_size', $dbsize_mb, 15 * MINUTE_IN_SECONDS );

        return $dbsize_mb;
    }

    /**
     * Get WP users.
     *
     * @return array Array of users.
     */
    public function get_all_users() {
        $all_users = get_users(
            array(
                'fields' => array( 'ID', 'user_login', 'user_nicename' ),
            )
        );
        if ( empty( $all_users ) || ! is_array( $all_users ) ) {
            return array();
        }
        $all_users = array_map(
            function ( $user ) {
                if ( empty( $user->ID ) ) {
                    return false;
                }
                return array(
                    'id'       => $user->ID,
                    'login'    => $user->user_login,
                    'nicename' => $user->user_nicename,
                );
            },
            $all_users
        );
        return array_filter( $all_users );
    }

    /**
     * Method mainwp_help_content()
     *
     * Creates the MainWP Help Documentation List for the help component in the sidebar.
     */
    public static function mainwp_help_content() {
        $allow_pages = array( 'InsightsOverview' );
        if ( isset( $_GET['page'] ) && in_array( $_GET['page'], $allow_pages, true ) ) { //phpcs:ignore WordPress.Security.NonceVerification.Recommended
            ?>
            <p><?php esc_html_e( 'If you need help with the Dashboard Insights module, please review following help documents', 'mainwp' ); ?></p>
            <div class="ui list">
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/dashboard-insights/" target="_blank">Dashboard Insights</a></div> <?php // NOSONAR -- compatible with help. ?>
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/dashboard-insights/#comprehensive-filtering-options" target="_blank">Filtering Options</a></div> <?php // NOSONAR -- compatible with help. ?>
                <div class="item"><i class="external alternate icon"></i> <a href="https://mainwp.com/kb/dashboard-insights/#export-the-data-and-charts-from-an-individual-widget" target="_blank">Export Insights Data</a></div> <?php // NOSONAR -- compatible with help. ?>
                <?php
                /**
                 * Action: mainwp_module_dashboard_insights_help_item
                 *
                 * Fires at the bottom of the help articles list in the Help sidebar on the Insights page.
                 *
                 * Suggested HTML markup:
                 *
                 * <div class="item"><a href="Your custom URL">Your custom text</a></div>
                 *
                 * @since 5.2
                 */
                do_action( 'mainwp_module_dashboard_insights_help_item' );
                ?>
            </div>
            <?php
        }
    }
}
