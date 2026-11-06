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
use MainWP\Dashboard\MainWP_System_Utility;
use MainWP\Dashboard\MainWP_Utility;
use MainWP\Dashboard\MainWP_DB;
use MainWP\Dashboard\MainWP_Connect;

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
        add_action( 'admin_init', array( $this, 'admin_init' ) );
        $log_page = Log_Insights_Page::instance();
        MainWP_Post_Handler::instance()->add_action( 'mainwp_module_log_clean_up_child_logs', array( $this, 'ajax_clean_up_child_logs' ) );
        MainWP_Post_Handler::instance()->add_action( 'mainwp_module_log_manage_events_display_rows', array( Log_Manage_Insights_Events_Page::instance(), 'ajax_manage_events_display_rows' ) );
        MainWP_Post_Handler::instance()->add_action( 'mainwp_module_log_widget_insights_display_rows', array( $log_page, 'ajax_events_display_rows' ) );
        MainWP_Post_Handler::instance()->add_action( 'mainwp_module_log_widget_events_overview_display_rows', array( $log_page, 'ajax_events_overview_display_rows' ) );
        MainWP_Post_Handler::instance()->add_action( 'mainwp_module_log_update_dismissed_db', array( $log_page, 'ajax_archive_dismissed_db' ) );
        MainWP_Post_Handler::instance()->add_action( 'mainwp_module_log_cancel_update_dismissed_db', array( $log_page, 'ajax_cancel_update_dismissed_db' ) );
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
        Log_Events_Filter_Segment::get_instance()->admin_init();
        $this->handle_post_archive_data();
    }

    /**
     * Handle archive data action.
     */
    public function handle_post_archive_data() {
        if ( ! \mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' ) ) {
            return;
        }
        if ( isset( $_GET['clearArchivedSitesChangesData'] ) && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'clear_archived_sites_changes' ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            Log_DB_Archive::instance()->truncate_archive_tables();
            wp_safe_redirect( esc_url( admin_url( 'admin.php?page=MainWPTools' ) ) );
            die();
        }
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
        $script_screens = array( 'mainwp_page_InsightsOverview', 'mainwp_page_SettingsDashboardInsights', 'mainwp_page_SettingsInsights', 'mainwp_page_InsightsManage' );
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
     * Method hook_schedules_cron_listing().
     *
     * @param  array $cron_list Cron info.
     * @return array Cron info.
     */
    public function hook_schedules_cron_listing( $cron_list = array() ) {

        if ( ! $this->manager->is_enabled_auto_archive_logs() ) {
            return $cron_list;
        }

        if ( ! is_array( $cron_list ) ) {
            $cron_list = array();
        }
        $start_lasttime                                       = get_option( 'mainwp_module_log_last_time_auto_archive_logs', 0 );
        $start_nexttime                                       = wp_next_scheduled( 'mainwp_module_log_cron_job_auto_archive' );
        $cron_list['mainwp_module_log_cron_job_auto_archive'] = array(
            'title'     => __( 'Auto archive Network Activity logs', 'mainwp' ),
            'action'    => 'mainwp_module_log_cron_job_auto_archive',
            'frequency' => __( 'Once daily', 'mainwp' ),
            'last_run'  => empty( $start_lasttime ) ? 'N/A' : MainWP_Utility::format_timestamp( $start_lasttime ),
            'next_run'  => empty( $start_nexttime ) ? 'N/A' : MainWP_Utility::format_timestamp( $start_nexttime ),
        );
        return $cron_list;
    }

    /**
     * Handle ajax clean up child logs.
     */
    public function ajax_clean_up_child_logs() {
        MainWP_Post_Handler::instance()->check_security( 'mainwp_module_log_clean_up_child_logs' );
        if ( ! \mainwp_current_user_can( 'dashboard', 'manage_insights_actions' ) ) {
            wp_send_json( array( 'error' => esc_html__( 'Error - insufficient permissions!', 'mainwp' ) ) );
        }

        $siteid  = isset( $_POST['siteid'] ) ? intval( $_POST['siteid'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $website = false;

        if ( $siteid > 0 ) {
            $website = MainWP_DB::instance()->get_website_by_id( $siteid );
        }

        if ( empty( $siteid ) || empty( $website ) ) {
            die( wp_json_encode( array( 'error' => esc_html__( 'Invalid site id or website not found. Please try again.', 'mainwp' ) ) ) );
        }

        $information = MainWP_Connect::fetch_url_authed( $website, 'clean_up_child_logs' );

        if ( ! is_array( $information ) ) {
            die( wp_json_encode( array( 'error' => esc_html__( 'Invalid response from child site. Please try again.', 'mainwp' ) ) ) );
        }

        $rsult = array();
        if ( isset( $information['success'] ) && 1 === intval( $information['success'] ) ) {
            $rsult['success'] = 1;

            if ( isset( $information['dbsize_activitylogs'] ) ) {
                MainWP_DB::instance()->update_website_option( $website, 'dbsize_activitylogs', $information['dbsize_activitylogs'] );
            }
        } elseif ( isset( $information['error'] ) ) {
            $rsult['error'] = $information['error'];
        }

        wp_send_json( $rsult );
    }

    /**
     * Render logs db large size notice.
     *
     * @param int    $limit DB size limit in MByte, default 300 MB.
     * @param string $which The page slug where the notice is displayed.
     */
    public function render_logs_db_notice( $limit = 300, $which = '' ) {
        if ( empty( $limit ) ) {
            $limit = 300; // MB.
        }
        if ( MainWP_Utility::show_mainwp_message( 'notice', 'logs-db-size-large' ) ) {
            $size = Log_DB_Helper::instance()->get_db_size();
            if ( $size >= $limit ) {
                ?>
                <div class="ui yellow message" <?php echo 'overview' === $which || 'client_overview' === $which ? 'style="margin: 1em;"' : ''; ?>>
                    <i class="close icon mainwp-notice-dismiss" notice-id="logs-db-size-large"></i>
                    <?php
                    /* translators: 1: Database size in MB, 2: Opening anchor tag, 3: Closing anchor tag */
                    printf( esc_html__( 'Your Network Activity logs are using a lot of database space (%1$s MB). Go to %2$sMainWP > Settings > Network Activity Settings%3$s and enable Automatically archive logs, then set a Data retention period to keep the table size under control.', 'mainwp' ), esc_html( $size ), '<a href="admin.php?page=SettingsInsights#field-log-enable-auto-archive">', '</a>' ); // NOSONAR - noopener - open safe.
                    ?>
                </div>
                <?php
            }
        }
    }

    /**
     * Render logs db update notice.
     */
    public function render_update_db_notice() {
        $status = '';
        $dbver  = Log_Install::instance()->get_current_logs_db_ver();
        if ( version_compare( $dbver, '1.0.1.26', '=' ) && ! get_option( 'mainwp_module_logs_updates_dismissed_db_cancelled' ) ) { // NOSONAR - no ip,checks ver <= 22 only.
            $status = get_option( 'mainwp_module_logs_updates_dismissed_db_process_status', '' );
        }
        $can_manage_settings = \mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' );
        ?>
        <div class="ui green message" id="module-log-update-dissmised-logs-running" style="display:none;"></div>
        <?php
        if ( $can_manage_settings && 'require_update' === $status && MainWP_Utility::show_mainwp_message( 'notice', 'logs-db-update-required' ) ) {
            delete_option( 'mainwp_module_logs_updates_dismissed_db_cancelled' ); // To ensure it does not retrieve old saved values.
            ?>
            <div class="ui yellow message">
                <i class="close icon mainwp-notice-dismiss" notice-id="logs-db-update-required"></i>
                <?php
                /* translators: 1: Opening anchor tag, 2: Closing anchor tag */
                printf( esc_html__( 'Your \'Network Activity\' database needs to be updated. Click %1$shere%2$s to start the update.', 'mainwp' ), '<a href="javascript:void(0);" id="module-update-logs-db-requirement">', '</a>' );
                ?>
            </div>
            <?php
        } elseif ( $can_manage_settings && 'running' === $status ) {
            ?>
            <script type="text/javascript">
                jQuery(function ($) {
                    mainwp_module_logs_start_update_dismissed_db();
                });
            </script>
            <?php
        }
    }

    /**
     * Get WP users.
     *
     * @param array|null $sites_ids Optional, array websites ids.
     *
     * @return array Array of users.
     */
    public function get_all_users( $sites_ids = null ) {
        $users_sites = Log_DB_Helper::instance()->get_logs_users( $sites_ids );
        return ! empty( $users_sites ) ? $users_sites : array();
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
                <div class="item"><i class="external alternate icon"></i> <a href="https://docs.mainwp.com/add-ons/security/dashboard-insights-extension#dashboard-insights" target="_blank">Dashboard Insights</a></div> <?php // NOSONAR -- compatible with help. ?>
                <div class="item"><i class="external alternate icon"></i> <a href="https://docs.mainwp.com/add-ons/security/dashboard-insights-extension#comprehensive-filtering-options" target="_blank">Filtering Options</a></div> <?php // NOSONAR -- compatible with help. ?>
                <div class="item"><i class="external alternate icon"></i> <a href="https://docs.mainwp.com/add-ons/security/dashboard-insights-extension#export-the-data-and-charts-from-an-individual-widget" target="_blank">Export Insights Data</a></div> <?php // NOSONAR -- compatible with help. ?>
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
