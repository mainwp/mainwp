<?php
/**
 * MainWP System.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

use MainWP\Dashboard\Module\Log\Log_Manage_Insights_Events_Page;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable Generic.Metrics.CyclomaticComplexity -- complexity.
// phpcs:disable plugin_updater_detected -- not a self-updater; injects update data exclusively for licensed MainWP premium extensions hosted on mainwp.com.

const MAINWP_VIEW_PER_SITE         = 1;
const MAINWP_VIEW_PER_PLUGIN_THEME = 0;
const MAINWP_VIEW_PER_GROUP        = 2;

// phpcs:disable WordPress.WP.AlternativeFunctions -- for custom read/write file.

/**
 * Class MainWP_System
 *
 * @package MainWP\Dashboard
 */
class MainWP_System { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Both connection surfaces must offer the same per-user actions.
     *
     * @return array Localized connection options.
     */
    public static function assistant_chatgpt_l10n() {
        return array(
            'chatgpt' => array(
                'can_connect'         => function_exists( 'wp_ai_client_prompt' ) && function_exists( 'wp_supports_ai' ) && wp_supports_ai(),
                'can_manage_settings' => MainWP_Assistant_Onboarding::user_can_run_wizard(),
                'connectors_url'      => esc_url_raw( apply_filters( 'mainwp_assistant_connectors_url', admin_url( 'options-connectors.php' ) ) ),
                'strings'             => array(
                    'choices'       => __( 'AI Connection', 'mainwp' ),
                    'plan_title'    => __( 'Use your ChatGPT plan', 'mainwp' ),
                    'continue'      => __( 'Continue with ChatGPT', 'mainwp' ),
                    'plan_detail'   => __( 'Uses the ChatGPT plan you already pay for. Each person on this Dashboard connects their own.', 'mainwp' ),
                    'api_title'     => __( 'Use an API key', 'mainwp' ),
                    'api_connect'   => __( 'Connect free AI', 'mainwp' ),
                    'connectors'    => __( 'Manage AI Connectors', 'mainwp' ),
                    'api_alt'       => __( 'Use OpenAI or Anthropic instead', 'mainwp' ),
                    'disclosure'    => MainWP_Assistant_Onboarding::free_tier_disclosure(),
                    'step_signin'   => __( 'Sign in to ChatGPT in the new tab and approve MainWP.', 'mainwp' ),
                    'step_error'    => __( "ChatGPT then sends that tab to an error page, such as “This site can't be reached”. That's expected, so keep that tab open.", 'mainwp' ),
                    /* translators: %s: copy keyboard shortcut, such as Ctrl+C. */
                    'step_paste'    => __( "Click that page's address bar, press %s to copy the full address, and paste it back into MainWP.", 'mainwp' ),
                    /* translators: %s: copy keyboard shortcut, such as Ctrl+C. */
                    'paste_detail'  => __( "When you approve, the ChatGPT tab ends on an error page, such as “This site can't be reached”. That's expected: that page's address is what MainWP needs. Click its address bar and press %s to copy the full address.", 'mainwp' ),
                    'callback'      => __( 'Paste the address from the error page', 'mainwp' ),
                    'paste'         => __( 'Paste', 'mainwp' ),
                    'reopen'        => __( 'Closed the ChatGPT tab? Open it again', 'mainwp' ),
                    /* translators: %s: the page's usual browser tab title. */
                    'waiting_title' => __( 'Paste the address · %s', 'mainwp' ),
                    'finish'        => __( 'Finish connecting', 'mainwp' ),
                    'start_over'    => __( 'Start over', 'mainwp' ),
                    'welcome'       => __( "You're using your ChatGPT plan", 'mainwp' ),
                    'welcome_body'  => __( 'The Assistant will use your ChatGPT plan for its answers. You can see and limit this usage in ChatGPT settings.', 'mainwp' ),
                    'got_it'        => __( 'Got it', 'mainwp' ),
                    'using'         => __( 'Using ChatGPT plan', 'mainwp' ),
                    'connected'     => __( 'Connected', 'mainwp' ),
                    'show_account'  => __( 'Show account', 'mainwp' ),
                    'manage'        => __( 'Manage usage', 'mainwp' ),
                    'disconnect'    => __( 'Disconnect', 'mainwp' ),
                    'different'     => __( 'Connect a different ChatGPT account or workspace', 'mainwp' ),
                    'reconnect'     => __( 'Your ChatGPT connection stopped working. Connect again to keep using your plan.', 'mainwp' ),
                    'disabled'      => __( 'ChatGPT plan use is turned off for MainWP.', 'mainwp' ),
                    'turn_on'       => __( 'Turn on ChatGPT plan use', 'mainwp' ),
                    'unavailable'   => __( 'AI features are turned off or unavailable on this Dashboard.', 'mainwp' ),
                    'popup_blocked' => __( 'Your browser blocked the new tab. Open it here:', 'mainwp' ),
                    'model'         => __( 'Model', 'mainwp' ),
                    'close'         => __( 'Close', 'mainwp' ),
                    'loading'       => __( 'Loading...', 'mainwp' ),
                    'revoke'        => __( 'The old connection could not be removed in ChatGPT. Remove it in ChatGPT settings.', 'mainwp' ),
                ),
                'errors'              => array(
                    'empty_callback'         => __( 'Paste the address from the error page first.', 'mainwp' ),
                    'clipboard_no_callback'  => __( "Your clipboard doesn't have the error page's address yet. Copy the full address from that tab's address bar, then click Paste again.", 'mainwp' ),
                    'signin_address'         => __( "That's the address of the ChatGPT sign-in page. Approve MainWP on that page first, then copy the address of the error page that opens next.", 'mainwp' ),
                    'invalid_callback'       => __( 'This address does not match your sign-in attempt, or the attempt expired. Continue with ChatGPT again and paste the full address from the error page.', 'mainwp' ),
                    'access_denied'          => __( 'You declined the connection. Continue with ChatGPT again when you are ready.', 'mainwp' ),
                    'authorization_failed'   => __( 'ChatGPT did not approve the connection. Continue with ChatGPT again.', 'mainwp' ),
                    'invalid_client'         => __( 'This address belongs to a different app registration. Continue with ChatGPT again and copy the full address from the error page.', 'mainwp' ),
                    'invalid_id_token'       => __( 'The ChatGPT sign-in could not be verified. Continue with ChatGPT again.', 'mainwp' ),
                    'unknown_kid'            => __( 'The ChatGPT signing key could not be verified. Try connecting again in a few minutes.', 'mainwp' ),
                    'invalid_jwks'           => __( 'ChatGPT signing keys could not be loaded. Try connecting again in a few minutes.', 'mainwp' ),
                    'invalid_token_response' => __( 'ChatGPT returned an incomplete connection. Continue with ChatGPT again.', 'mainwp' ),
                    'invalid_grant'          => __( 'The sign-in address has expired or was already used. Continue with ChatGPT again.', 'mainwp' ),
                    'network_error'          => __( 'Could not reach ChatGPT. Check your connection and try again.', 'mainwp' ),
                    'request_failed'         => __( 'The Dashboard did not respond. Reload the page and try again.', 'mainwp' ),
                    'connection_busy'        => __( 'Your connection is being updated by another request. Try again in a moment.', 'mainwp' ),
                    'storage_failed'         => __( 'The connection could not be saved on this Dashboard. Try again.', 'mainwp' ),
                    'plan_disabled'          => __( 'ChatGPT plan use is turned off for MainWP.', 'mainwp' ),
                    'needs_reconnect'        => __( 'Your ChatGPT connection stopped working. Connect again to keep using your plan.', 'mainwp' ),
                    'invalid_model'          => __( 'That model is not available to your ChatGPT account. Choose a listed model.', 'mainwp' ),
                    'forbidden'              => __( 'Your Dashboard account cannot use the Assistant.', 'mainwp' ),
                    'generic'                => __( 'ChatGPT could not finish connecting. Continue with ChatGPT again.', 'mainwp' ),
                ),
            ),
        );
    }

    /**
     * Public static variable to hold the current plugin version.
     *
     * @var string Current plugin version.
     */
    public static $version = '6.5.1'; // NOSONAR.

    /**
     * Private static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    private static $instance = null;

    /**
     * Public variable to hold the Metaboxes instance.
     *
     * @var object Metaboxes.
     */
    public $metaboxes;

    /**
     * Private variable to hold the current version.
     *
     * @var string The plugin current version.
     */
    private $current_version = null;


    /**
     * Private variable to hold the check update version number.
     *
     * @var string The plugin current update version.
     */
    private $check_ver_update = '0.0.8';

    /**
     * Private variable to hold the plugin slug (mainwp/mainwp.php)
     *
     * @var string Plugin slug.
     */
    private $plugin_slug;


    /**
     * Private variable to hold the defer js handle.
     *
     * @var array Defer js handle.
     */
    private static $defer_js_handle = array();


    /**
     * Method instance()
     *
     * Create a public static instance.
     *
     * @static
     * @return MainWP_System
     */
    public static function instance() {
        return static::$instance;
    }

    /**
     * MainWP_System constructor.
     *
     * Runs any time class is called.
     *
     * @param string $mainwp_plugin_file Plugn slug.
     *
     * @uses \MainWP\Dashboard\MainWP_Bulk_Post
     * @uses \MainWP\Dashboard\MainWP_Hooks
     * @uses \MainWP\Dashboard\MainWP_Menu::get_class_name()
     * @uses \MainWP\Dashboard\MainWP_Menu
     * @uses \MainWP\Dashboard\MainWP_Meta_Boxes
     * @uses \MainWP\Dashboard\MainWP_System_Cron_Jobs::init_cron_jobs()
     * @uses \MainWP\Dashboard\MainWP_System_Cron_Jobs
     * @uses \MainWP\Dashboard\MainWP_System_Handler
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_class_name()
     * @uses \MainWP\Dashboard\MainWP_System_View::get_class_name()
     * @uses \MainWP\Dashboard\MainWP_UI::get_class_name()
     * @uses \MainWP\Dashboard\MainWP_WP_CLI_Command::init()
     * @uses \MainWP\Dashboard\MainWP_Updates_Overview::init()
     * @uses \MainWP\Dashboard\MainWP_Extensions::init()
     * @uses \MainWP\Dashboard\MainWP_Extensions_Handler::get_class_name()
     * @uses \MainWP\Dashboard\MainWP_Install_Bulk::init()
     * @uses \MainWP\Dashboard\MainWP_Backup_Page::init()
     * @uses \MainWP\Dashboard\MainWP_Manage_Sites::init()
     * @uses \MainWP\Dashboard\MainWP_Overview::get()
     * @uses \MainWP\Dashboard\MainWP_Page::init()
     * @uses \MainWP\Dashboard\MainWP_Plugins::init()
     * @uses \MainWP\Dashboard\MainWP_Post::init()
     * @uses \MainWP\Dashboard\MainWP_Settings::init()
     * @uses \MainWP\Dashboard\MainWP_Themes::init()
     * @uses \MainWP\Dashboard\MainWP_Updates::init()
     * @uses \MainWP\Dashboard\MainWP_User::init()
     * @uses \MainWP\Dashboard\MainWP_Updates::init()
     * @uses  \MainWP\Dashboard\MainWP_Utility::update_option()
     */
    public function __construct( $mainwp_plugin_file ) { //phpcs:ignore -- NOSONAR - complex.
        static::$instance = $this;

        MainWP_Execution_Helper::instance()->init_exec_time();

        $includer = new MainWP_Includes();
        $includer->includes();

        MainWP_Keys_Manager::auto_load_files();
        MainWP_Keys_Manager::register_migration_hooks();

        $this->load_all_options();

        $this->update_install();
        add_action( 'mainwp_install_update_actions', array( MainWP_Get_Started::class, 'record_first_update' ), 10, 4 );
        $this->plugin_slug = plugin_basename( $mainwp_plugin_file );

        add_action( 'shutdown', array( $this, 'hook_wp_shutdown' ) );

        do_action( 'mainwp_system_init' );

        if ( is_admin() ) {
            include_once ABSPATH . 'wp-admin' . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'plugin.php'; // NOSONAR - WP compatible.
            $pluginData            = get_plugin_data( $mainwp_plugin_file, true, false ); // to fix issue of user language setting.
            $this->current_version = $pluginData['Version'];
            $currentVersion        = get_option( 'mainwp_plugin_version' );

            if ( ! empty( $currentVersion ) && version_compare( $currentVersion, '4.0', '<' ) && version_compare( $this->current_version, '4.0', '>=' ) ) {
                add_action( 'mainwp_after_header', array( MainWP_System_View::get_class_name(), 'mainwp_4_update_notice' ) );
            }

            if ( ! empty( $currentVersion ) && version_compare( $currentVersion, '5.0', '<' ) && version_compare( $this->current_version, '5.0', '>=' ) ) {
                add_action( 'mainwp_after_header', array( MainWP_System_View::get_class_name(), 'mainwp_ver5_update_notice' ) );
            }

            if ( ! empty( $currentVersion ) && version_compare( $currentVersion, '5.0.2', '<' ) && version_compare( $this->current_version, '5.0.2', '>=' ) ) {
                add_action( 'mainwp_after_header', array( MainWP_System_View::get_class_name(), 'mainwp_ver502_update_notice' ) );
            }

            // Use 6.0.12 as the lower bound so 6.1-er.x builds trigger this notice.
            if ( ! empty( $currentVersion ) && version_compare( $currentVersion, '6.1', '<' ) && version_compare( $currentVersion, '6.0.12', '>' ) ) {
                add_action( 'mainwp_after_header', array( MainWP_System_View::get_class_name(), 'mainwp_ver61_update_notice' ) );
            }

            MainWP_Utility::update_option( 'mainwp_plugin_version', $this->current_version );
        }

        if ( ! defined( 'MAINWP_VERSION' ) ) {

            /**
             * Defines MainWP Version.
             *
             * @const ( string )
             * @source https://code-reference.mainwp.com/classes/MainWP.Dashboard.MainWP_System.html
             */
            define( 'MAINWP_VERSION', $this->current_version );
        }

        if ( get_option( 'mainwp_setting_demo_mode_enabled' ) && ! defined( 'MAINWP_DEMO_MODE' ) ) {
            define( 'MAINWP_DEMO_MODE', true );
        }

        MainWP_Extensions::init();
        MainWP_Extensions_Groups::init();

        $systemHandler = MainWP_System_Handler::instance();

        add_action( 'init', array( &$this, 'localization' ) );
        add_filter( 'site_transient_update_plugins', array( $systemHandler, 'check_update_custom' ) ); // phpcs:ignore PluginCheck.CodeAnalysis.Sniffs.UpdatingPluginTransientFound -- not a self-updater; injects update data exclusively for licensed MainWP premium extensions (hosted on mainwp.com), not for the Dashboard plugin itself.
        add_filter( 'pre_set_site_transient_update_plugins', array( $systemHandler, 'pre_check_update_custom' ) ); // phpcs:ignore PluginCheck.CodeAnalysis.Sniffs.UpdatingPluginTransientFound -- same as above; pre-populates update info for MainWP premium extensions before WordPress saves the transient.
        add_filter( 'plugins_api', array( $systemHandler, 'plugins_api_extension_info' ), 10, 3 );
        add_filter( 'plugins_api_result', array( $systemHandler, 'plugins_api_wp_plugins_api_result' ), 10, 3 );
        add_filter( 'upgrader_pre_download', array( $systemHandler, 'hook_refresh_trusted_extension_package_url' ), 10, 4 );

        $this->metaboxes = new MainWP_Meta_Boxes();

        MainWP_Overview::get();
        MainWP_Client_Overview::instance();

        MainWP_Manage_Sites::init();
        MainWP_Uptime_Monitoring_Handle::instance();

        MainWP_Hooks::get_instance();
        MainWP_Menu::get_instance();

        add_action( 'plugins_loaded', array( $this, 'plugins_loaded' ), 9 );
        add_action( 'admin_menu', array( MainWP_Menu::get_class_name(), 'init_mainwp_menus' ) );
        add_filter( 'admin_footer', array( &$this, 'admin_footer' ), 15 );
        add_action( 'admin_head', array( MainWP_System_View::get_class_name(), 'admin_head' ) );
        add_action( 'admin_enqueue_scripts', array( &$this, 'admin_enqueue_styles' ) );
        add_action( 'admin_enqueue_scripts', array( &$this, 'admin_enqueue_scripts' ) );
        add_action( 'admin_enqueue_scripts', array( &$this, 'admin_enqueue_scripts_fix_conflict' ), 9 );
        add_action( 'admin_body_class', array( MainWP_System_View::get_class_name(), 'admin_body_class' ) );

        MainWP_Bulk_Post::get_instance();

        add_action( 'admin_init', array( &$this, 'admin_init' ), 20 );
        add_action( 'admin_init', array( $this, 'hook_admin_update_check' ) );
        add_action( 'admin_init', array( $this, 'admin_init_update' ) );
        add_action( 'after_setup_theme', array( &$this, 'after_setup_theme' ) );

        add_action( 'init', array( &$this, 'parse_init' ) );
        add_action( 'init', array( &$this, 'init_jobs' ) );
        add_action( 'init', array( &$this, 'init' ), 9999 );

        add_action( 'admin_init', array( $this, 'admin_redirects' ) );
        add_action( 'current_screen', array( &$this, 'current_screen_redirects' ), 15 );
        add_filter( 'plugin_action_links', array( $this, 'hook_plugin_action_links' ), 10, 4 );
        add_action( 'admin_menu', array( &$this, 'admin_menu' ) );
        add_action( 'admin_print_styles', array( MainWP_System_View::get_class_name(), 'admin_print_styles' ) );

        add_action( 'wp_logout', array( &$this, 'clear_sessions' ) );

        MainWP_Install_Bulk::init();

        add_action( 'mainwp_after_header', array( MainWP_System_View::get_class_name(), 'admin_notices' ) );
        add_action( 'admin_notices', array( MainWP_System_View::get_class_name(), 'wp_admin_notices' ) );
        add_action( 'wp_mail_failed', array( &$this, 'wp_mail_failed' ) );

        add_action( 'after_plugin_row', array( MainWP_System_View::get_class_name(), 'after_extensions_plugin_row' ), 10, 3 );

        add_filter( 'mainwp-activated-check', array( &$this, 'activated_check' ) ); // @deprecated Use 'mainwp_activated_check' instead.
        add_filter( 'mainwp_activated_check', array( &$this, 'activated_check' ) );

        do_action_deprecated( 'mainwp-activated', array(), '4.0.7.2', 'mainwp_activated' ); // @deprecated Use 'mainwp_activated' instead. NOSONAR - not IP.

        /**
         * Action: mainwp_activated
         *
         * Fires upon MainWP plugin activation.
         *
         * @since Unknown
         */
        do_action( 'mainwp_activated' );

        MainWP_Updates::init();
        MainWP_Post::init();
        MainWP_Settings::init();
        if ( MainWP_Backup_Settings::is_native_backups_enabled() ) {
            MainWP_Backup_Page::init();
            MainWP_Backup_Upload_Driver::instance()->init();
            MainWP_Backup_Retention_Scheduler::instance()->init();
            MainWP_Backup_Diagnostics::instance()->init();
            MainWP_Backup_Run_History::init();
        }
        MainWP_Manage_Groups::init();
        MainWP_User::init();
        MainWP_Password_Policy_Settings::init();
        MainWP_Page::init();
        MainWP_Themes::init();
        MainWP_Plugins::init();
        MainWP_Updates_Overview::init();
        MainWP_Client::init();
        MainWP_Knowledge_UI::init();
        MainWP_Rest_Api_Page::init();
        MainWP_Logger::instance();

        if ( class_exists( '\MainWP\Dashboard\Module\Log\Log_Manage_Insights_Events_Page' ) ) {
            Log_Manage_Insights_Events_Page::instance();
        }

        MainWP_Uptime_Monitoring_Schedule::instance();

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            MainWP_WP_CLI_Command::init();
        }
        MainWP_Unhooks_Helper::instance();
        MainWP_Cache_Warm_Helper::instance();
        MainWP_System_Utility::instance();

        add_action(
            'wp_ajax_nopriv_mainwp_self_connect',
            array( MainWP_Post_Handler::instance(), 'ajax_self_connect' )
        );

        // Initialize assistant components on init action with priority 20.
        // This ensures plugins/themes are loaded before assistant initialization.
        add_action( 'init', array( $this, 'init_assistant_components' ), 20 );

        // Wired here rather than with the other assistant components because it
        // hooks the vendored AI provider onto init priority 6, which has to be
        // registered before init runs and before core wires stored connector
        // keys into the AI Client at init priority 20.
        MainWP_Assistant_Onboarding::init();
    }

    /**
     * Method init_assistant_components()
     *
     * Initialize assistant components on init action with priority 20.
     * This ensures plugins and themes are fully loaded before the assistant bootstraps.
     */
    public function init_assistant_components() {
        // Always bootstrap assistant DB hooks so installs/upgrades create tables
        // before any history or cleanup code attempts to use them.
        MainWP_DB_Assistant::instance();

        // Always initialize settings (AJAX handlers needed for enable/disable functionality).
        MainWP_Assistant_Settings::init();
        MainWP_Assistant_ChatGPT_Plan::instance();
        if ( class_exists( '\WordPress\AiClient\AiClient' ) ) {
            $registry = \WordPress\AiClient\AiClient::defaultRegistry();
            if ( ! $registry->hasProvider( MainWP_Assistant_ChatGPT_Provider::ID ) ) {
                $registry->registerProvider( MainWP_Assistant_ChatGPT_Provider::class );
            }
        }

        if ( static::is_assistant_enabled() ) {
            MainWP_Assistant::instance();
            MainWP_Assistant_Policies::instance();
            MainWP_Assistant_Insights::init();
            MainWP_Assistant_Insights_Helper::init();

        }
    }

    /**
     * Method get_dashboard_version()
     *
     * Get dashboard version.
     *
     * @return string $version Version.
     */
    public function get_dashboard_version() {
        return static::$version;
    }

    /**
     * Method get_mainwp_version()
     *
     * Get dashboard version.
     *
     * @return string $version Version.
     */
    public static function get_mainwp_version() { //phpcs:ignore -- NOSONAR same function body.
        return static::$version;
    }

    /**
     * Method load_all_options()
     *
     * Load all wp_options data.
     *
     * @return array $alloptions Array of all options.
     */
    public function load_all_options() { //phpcs:ignore -- NOSONAR - complex.

        /**
         * WordPress Database instance.
         *
         * @global object $wpdb
         */
        global $wpdb;

        if ( ! defined( 'WP_INSTALLING' ) || ! is_multisite() ) {
            $alloptions = wp_cache_get( 'alloptions', 'options' );
        } else {
            $alloptions = false;
        }

        if ( ! defined( 'WP_INSTALLING' ) || ! is_multisite() ) {
            $notoptions = wp_cache_get( 'notoptions', 'options' );
        } else {
            $notoptions = false;
        }

        if ( ! isset( $alloptions['mainwp_db_version'] ) ) {
            $suppress = $wpdb->suppress_errors();
            $options  = array(
                'mainwp_db_version',
                'mainwp_plugin_version',
                'mainwp_upgradeVersionInfo',
                'mainwp_extensions',
                'mainwp_activated',
                'mainwp_automaticDailyUpdate',
                'mainwp_pluginAutomaticDailyUpdate',
                'mainwp_themeAutomaticDailyUpdate',
                'mainwp_backup_before_upgrade',
                'mainwp_backup_before_upgrade_require_offsite',
                'mainwp_enableNativeBackups',
                'mainwp_backup_progress_uniq_key_repair_pending',
                'mainwp_maximumInstallUpdateRequests',
                'mainwp_maximumSyncRequests',
                'mainwp_primaryBackup',
                'mainwp_use_favicon',
                'mainwp_wp_cron',
                'mainwp_timeDailyUpdate',
                'mainwp_frequencyDailyUpdate',
                'mainwp_delay_autoupdate',
                'mainwp_wpcreport_extension',
                'mainwp_daily_digest_plain_text',
                'mainwp_hide_update_everything',
                'mainwp_disable_update_confirmations',
                'mainwp_settings_show_widgets',
                'mainwp_clients_show_widgets',
                'mainwp_settings_show_manage_sites_columns',
                'mainwp_individual_uptime_monitoring_schedule_enabled',
                'mainwp_disableSitesHealthMonitoring',
                'mainwp_sitehealthThreshold',
                'mainwp_check_http_response',
                'mainwp_extmenu',
                'mainwp_opensslLibLocation',
                'mainwp_notice_wp_mail_failed',
                'mainwp_show_language_updates',
                'mainwp_logger_check_daily',
                'mainwp_update_check_version',
                'mainwp_admin_init_update_data',
                'mainwp_setting_demo_mode_enabled',
                'mainwp_log_wait_lasttime',
                'mainwp_cron_license_deactivated_alert_lasttime',
                'mainwp_updatescheck_is_running',
                'mainwp_automatic_updates_is_running',
                'mainwp_frequency_AutoUpdate',
                'mainwp_batch_updates_is_running',
                'mainwp_batch_individual_updates_is_running',
                'mainwp_maximum_uptime_monitoring_requests',
                'mainwp_actionlogs',
                'mainwp_process_uptime_notification_run_status',
                'mainwp_warm_cache_pages_ttl',
                'mainwp_module_log_settings_logs_selection_data',
            );

            $options = apply_filters( 'mainwp_init_load_all_options', $options );

            // A filter callback can return anything. Keep the contract the rest
            // of this method relies on -- a list of option-name strings -- and
            // never hand a non-array to the query builder below.
            if ( ! is_array( $options ) ) {
                $options = array();
            }

            if ( ! empty( $options ) ) {
                $placeholders  = implode( ', ', array_fill( 0, count( $options ), '%s' ) );
                $alloptions_db = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reads MainWP options into the cache this method populates.
                    $wpdb->prepare(
                        "SELECT option_name, option_value FROM $wpdb->options WHERE option_name IN ( $placeholders )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $wpdb->options is trusted; the option names are bound through $placeholders (one %s per option).
                        $options
                    )
                );
            } else {
                $alloptions_db = array();
            }
            $wpdb->suppress_errors( $suppress );
            if ( ! is_array( $alloptions ) ) {
                $alloptions = array();
            }
            if ( is_array( $alloptions_db ) ) {
                foreach ( (array) $alloptions_db as $o ) {
                    $alloptions[ $o->option_name ] = $o->option_value;
                    unset( $options[ array_search( $o->option_name, $options ) ] );
                }
                if ( ! is_array( $notoptions ) ) {
                    $notoptions = array();
                }
                foreach ( $options as $option ) {
                    $notoptions[ $option ] = true;
                }
                if ( ! defined( 'WP_INSTALLING' ) || ! is_multisite() ) {
                    wp_cache_set( 'alloptions', $alloptions, 'options' );
                    wp_cache_set( 'notoptions', $notoptions, 'options' );
                }
            }
        }

        return $alloptions;
    }

    /**
     * Method localization()
     *
     * Loads plugin language files.
     */
    public function localization() {
        $load = apply_filters( 'mainwp_load_text_domain', true );
        if ( $load ) {
            load_plugin_textdomain( 'mainwp', false, dirname( dirname( plugin_basename( __FILE__ ) ) ) . '/languages/' ); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- kept for older WP versions (<4.6) and to support local/custom translation files loaded from the plugin's languages directory.
        }
    }


    /**
     * Method wp_mail_failed()
     *
     * Check if there has been a wp mail failer.
     *
     * @param string $error Array of error messages.
     *
     * @uses \MainWP\Dashboard\MainWP_Logger::debug()
     * @uses  \MainWP\Dashboard\MainWP_Utility::update_option()
     */
    public function wp_mail_failed( $error ) {
        $mail_failed = get_option( 'mainwp_notice_wp_mail_failed' );
        if ( is_object( $error ) && empty( $mail_failed ) ) {
            MainWP_Utility::update_option( 'mainwp_notice_wp_mail_failed', 'yes' );
            $er = $error->get_error_message();
            if ( ! empty( $er ) ) {
                MainWP_Logger::instance()->debug( 'Error :: wp_mail :: [error=' . $er . ']' );
            }
        }
    }

    /**
     * Method get_version()
     *
     * Get current plugin version.
     *
     * @return string Current plugin version.
     */
    public function get_version() {
        return $this->current_version;
    }

    /**
     * Method mainwp_cronpingchilds_action()
     *
     * Run cron ping child's action.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Cron_Jobs::cron_ping_childs()
     */
    public function mainwp_cronpingchilds_action() {
        MainWP_System_Cron_Jobs::instance()->cron_ping_childs();
    }

    /**
     * Method mainwp_cronbackups_continue_action()
     *
     * Run cron backups continue action.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Cron_Jobs::cron_backups_continue()
     */
    public function mainwp_cronbackups_continue_action() {
        MainWP_System_Cron_Jobs::instance()->cron_backups_continue();
    }

    /**
     * Method mainwp_cronbackups_slice_action()
     *
     * Run cron backups slice action.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Cron_Jobs::cron_backups_slice()
     */
    public function mainwp_cronbackups_slice_action() {
        MainWP_System_Cron_Jobs::instance()->cron_backups_slice();
    }

    /**
     * Method mainwp_cronbackups_prune_action()
     *
     * Run the daily backups prune from the system cron script.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Cron_Jobs::cron_backups_prune()
     * @uses \MainWP\Dashboard\MainWP_Backup_Diagnostics::prune()
     */
    public function mainwp_cronbackups_prune_action() {
        MainWP_System_Cron_Jobs::instance()->cron_backups_prune();
        // Under WP-Cron the diagnostics prune rides the same hook; this script calls the lane directly, so prune here too.
        MainWP_Backup_Diagnostics::instance()->prune();
        MainWP_Backup_Run_History::prune();
    }

    /**
     * Method mainwp_cronbackups_action()
     *
     * Run cron backups action.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Cron_Jobs::cron_backups()
     */
    public function mainwp_cronbackups_action() {
        MainWP_System_Cron_Jobs::instance()->cron_backups();
    }

    /**
     * Method mainwp_cronreconnect_action()
     *
     * Run cron stats action.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Cron_Jobs::cron_reconnect()
     */
    public function mainwp_cronreconnect_action() {
        MainWP_System_Cron_Jobs::instance()->cron_reconnect();
    }

    /**
     * Method mainwp_cronupdatescheck_action()
     *
     * Run cron updates check action.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Cron_Jobs::cron_updates_check()
     */
    public function mainwp_cronupdatescheck_action() {
        MainWP_System_Cron_Jobs::instance()->cron_updates_check();
    }

    /**
     * Method mainwp_cron_uptime_monitoring_check_action()
     *
     * Run cron uptime monitoring check action.
     */
    public function mainwp_cron_uptime_monitoring_check_action() {
        MainWP_Uptime_Monitoring_Schedule::instance()->cron_uptime_check();
    }

        /**
         * Method mainwp_cron_perform_general_schedules_action()
         *
         * Run cron uptime monitoring check action.
         */
    public function mainwp_cron_perform_general_schedules_action() {
        MainWP_System_Cron_Jobs::instance()->cron_perform_general_process();
    }


    /**
     * Method mainwp_crondeactivatedlicensesalert_action()
     *
     * Run cron activated licenses alert action.
     */
    public function mainwp_crondeactivatedlicensesalert_action() {
        MainWP_System_Cron_Jobs::instance()->cron_deactivated_licenses_alert();
    }

    /**
     * Method mainwp_cronchecksitehealth_action()
     *
     * Run cron check sites health action.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Cron_Jobs::cron_check_websites_health()
     */
    public function mainwp_cronchecksitehealth_action() {
        MainWP_System_Cron_Jobs::instance()->cron_check_websites_health();
    }

    /**
     * Method is_mainwp_pages()
     *
     * Get the current page and check it for "mainwp_".
     *
     * @return boolean ture|false.
     */
    public static function is_mainwp_pages() {
        $screen = get_current_screen();
        if ( $screen && strpos( $screen->base, 'mainwp_' ) !== false && strpos( $screen->base, 'mainwp_child_tab' ) === false ) {
            return true;
        }

        return false;
    }

    /**
     * Method is_assistant_enabled()
     *
     * Check if the MainWP Assistant is enabled, with fallback to legacy option.
     *
     * Priority order:
     * 1. If mainwp_assistant_enabled is set, use that value
     * 2. If legacy mainwp_enable_guided_chatbase is set, use that value (backward compatibility)
     * 3. If neither exists, default to disabled until the install flow seeds the option
     *
     * @return boolean true if assistant is enabled, false otherwise.
     */
    public static function is_assistant_enabled() {
        // Use a unique sentinel to distinguish "option doesn't exist" from "option is false".
        $sentinel = 'mainwp_option_not_set';
        $enabled  = get_option( 'mainwp_assistant_enabled', $sentinel );

        if ( $sentinel !== $enabled ) {
            // New option exists - return its actual boolean value.
            return (bool) $enabled;
        }

        // New option doesn't exist, check legacy option for backward compatibility.
        $legacy = get_option( 'mainwp_enable_guided_chatbase', $sentinel );

        if ( $sentinel !== $legacy ) {
            // Legacy option exists - respect the user's previous setting.
            return (bool) $legacy;
        }

        // Neither option exists - upgrades should remain disabled until explicitly enabled.
        return false;
    }

    /**
     * Method is_mainwp_site_page()
     *
     * Checks if the current page is under the site mode.
     *
     * @return boolean ture|false.
     */
    public static function is_mainwp_site_page() {
        $is_page = false;
        //phpcs:disable WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['page'] ) && 'CostTrackerAdd' !== $_GET['page'] && ( ( ( isset( $_GET['id'] ) && ! empty( $_GET['id'] ) ) || ( isset( $_GET['dashboard'] ) && ! empty( $_GET['dashboard'] ) ) || ( isset( $_GET['backupid'] ) && ! empty( $_GET['backupid'] ) ) || ( isset( $_GET['knowledgeid'] ) && ! empty( $_GET['knowledgeid'] ) ) || ( isset( $_GET['updateid'] ) && ! empty( $_GET['updateid'] ) ) || ( isset( $_GET['monitor_wpid'] ) && ! empty( $_GET['monitor_wpid'] ) ) || ( isset( $_GET['emailsettingsid'] ) && ! empty( $_GET['emailsettingsid'] ) ) || ( isset( $_GET['scanid'] ) && ! empty( $_GET['scanid'] ) ) ) || ( 'ServerInformation' === $_GET['page'] || 'ServerInformationCron' === $_GET['page'] || 'ErrorLog' === $_GET['page'] || 'ActionLogs' === $_GET['page'] || 'PluginPrivacy' === $_GET['page'] || 'Settings' === $_GET['page'] || 'SettingsAdvanced' === $_GET['page'] || 'SettingsEmail' === $_GET['page'] || 'PremiumUpdates' === $_GET['page'] || 'MainWPTools' === $_GET['page'] || 'SettingsInsights' === $_GET['page'] || 'SettingsApiBackups' === $_GET['page'] || 'BackupSettings' === $_GET['page'] || 'MonitoringSettings' === $_GET['page'] ) ) ) {
            $is_page = true;
        }
        //phpcs:enable

        /**
         * Hook mainwp_is_mainwp_site_page.
         *
         * @since 5.4.1
         */
        return apply_filters( 'mainwp_is_mainwp_site_page', $is_page );
    }


    /**
     * Applies the permission filter and denies access when a callback throws.
     *
     * @param mixed      $initial_grant Default permission value.
     * @param string     $cap_type      Capability group.
     * @param string|int $cap           Capability or site ID.
     * @return mixed Filter result, or false when a callback throws.
     */
    public static function filter_current_user_permission( $initial_grant, $cap_type, $cap ) {
        try {
            return apply_filters( 'mainwp_currentusercan', $initial_grant, $cap_type, $cap );
        } catch ( \Throwable $e ) {
            return false;
        }
    }

    /**
     * Method plugins_loaded()
     *
     * Load plugin text domain.
     */
    public function plugins_loaded() { // phpcs:ignore -- NOSONAR - complex.
        /**
         * Action: mainwp_system_plugins_loaded
         *
         * Fires after all plugins are loaded.
         *
         * @since 6.1.0
         */
        do_action( 'mainwp_system_plugins_loaded' );

        if ( ! function_exists( 'MainWP\Dashboard\mainwp_current_user_have_right' ) ) {

            /**
             * Method \mainwp_current_user_can()
             *
             * Check permission level by hook mainwp_currentusercan of Team Control extension.
             *
             * @param string $cap_type group or type of capabilities.
             * @param string $cap capabilities for current user.
             *
             * @return bool true|false
             *
             * @uses \MainWP\Dashboard\MainWP_System_Handler::handle_settings_post()
             */
            function mainwp_current_user_have_right( $cap_type = '', $cap = '' ) {

                /**
                 * Current user global.
                 *
                 * @global string
                 */
                global $current_user;

                if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
                    return true;
                }

                if ( defined( 'WP_CLI' ) && WP_CLI ) {
                    return true;
                }

                if ( defined( 'MAINWP_REST_API_DOING' ) && MAINWP_REST_API_DOING ) {
                    return true;
                }

                if ( empty( $current_user ) && ! function_exists( 'wp_get_current_user' ) ) {
                    require_once ABSPATH . WPINC . '/pluggable.php'; // NOSONAR - WP compatible.
                }

                return MainWP_System::filter_current_user_permission( true, $cap_type, $cap ); // phpcs:ignore Squiz.Classes.SelfMemberReference.NotUsed -- This named function has no class scope.
            }
        }
    }

    /**
     * Method init()
     *
     * Instantiate Plugin.
     */
    public function init() { //phpcs:ignore -- NOSONAR - complex.

        /**
         * MainWP disabled menu items array.
         *
         * @global object $_mainwp_disable_menus_items
         */
        global $_mainwp_disable_menus_items;

        $_mainwp_disable_menus_items = apply_filters( 'mainwp_disablemenuitems', $_mainwp_disable_menus_items );

        /**
         * Filter: mainwp_main_menu_disable_menu_items
         *
         * Filters disabled MainWP navigation items.
         *
         * @since Unknown
         */
        $_mainwp_disable_menus_items = apply_filters( 'mainwp_main_menu_disable_menu_items', $_mainwp_disable_menus_items );

        MainWP_System_Handler::instance()->handle_settings_post();
    }

    /**
     * Method parse_init()
     *
     * Initiate plugin installation & then run the Quick Setup Wizard.
     *
     * @uses \MainWP\Dashboard\MainWP_System_Handler::upload_file()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_mainwp_dir()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_wp_file_system()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::is_admin()
     * @uses \MainWP\Dashboard\MainWP_Setup_Wizard()
     */
    public function parse_init() {
        // phpcs:disable WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( isset( $_GET['mwpdl'] ) && isset( $_GET['sig'] ) ) {
            if ( ! isset( $_REQUEST['mwpdl'] ) || ! is_string( $_REQUEST['mwpdl'] ) || ! is_string( $_GET['sig'] ) ) {
                return;
            }
            $private_root = isset( $_GET['root'] ) && 'private' === $_GET['root'];
            if ( $private_root ) {
                if ( ! is_user_logged_in() ) {
                    return;
                }
                if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
                    return;
                }
                if ( ! MainWP_System_Utility::is_admin() || ! \mainwp_current_user_can( 'dashboard', 'execute_backups' ) || ! \mainwp_current_user_can( 'dashboard', 'download_backups' ) ) {
                    return;
                }
                $mwpdl    = isset( $_REQUEST['mwpdl'] ) ? wp_unslash( $_REQUEST['mwpdl'] ) : '';
                $decoded  = rawurldecode( $mwpdl );
                $segments = explode( '/', $decoded );
                $site_id  = isset( $segments[1] ) ? (int) $segments[1] : 0;
                if ( 'sites' !== ( $segments[0] ?? '' ) || $site_id <= 0 || ( $segments[1] ?? '' ) !== (string) $site_id || empty( $segments[2] ) ) {
                    return;
                }
                $website = MainWP_DB::instance()->get_website_by_id( $site_id );
                if ( empty( $website ) || ! MainWP_System_Utility::can_edit_website( $website ) || ! \mainwp_current_user_can( 'site', $site_id ) ) {
                    return;
                }
                $mwpDir = MainWP_Backup_Private_Root::resolve();
                if ( is_wp_error( $mwpDir ) || stristr( $decoded, '..' ) ) {
                    return;
                }
                $file = trailingslashit( $mwpDir ) . $decoded;
                if ( ! MainWP_Backup_File_Manager::path_is_confined_to( $file, $mwpDir ) || ! is_file( $file ) ) {
                    return;
                }
            } else {
                $mwpdl    = isset( $_REQUEST['mwpdl'] ) ? wp_unslash( $_REQUEST['mwpdl'] ) : '';
                $segments = explode( '/', ltrim( strtolower( wp_normalize_path( rawurldecode( $mwpdl ) ) ), '/' ) );
                // Diagnostics must pass the user-bound admin-post checks.
                while ( isset( $segments[0] ) && ( '' === $segments[0] || '.' === $segments[0] ) ) {
                    array_shift( $segments );
                }
                if ( strtolower( MainWP_Backup_Diagnostics::SUBDIR ) === ( $segments[0] ?? '' ) ) {
                    return;
                }
                $mwpDir = MainWP_System_Utility::get_mainwp_dir();
                $mwpDir = $mwpDir[0];
            }
            $mwpdl = isset( $_REQUEST['mwpdl'] ) ? wp_unslash( $_REQUEST['mwpdl'] ) : '';
            $file  = trailingslashit( $mwpDir ) . rawurldecode( $mwpdl );

            if ( ! $private_root && is_file( $file ) ) {
                $diagnostics_dir = MainWP_Backup_Diagnostics::instance()->diagnostics_dir();
                if ( MainWP_Backup_File_Manager::path_is_confined_to( $file, $diagnostics_dir ) ) {
                    return;
                }
            }

            $hasWPFileSystem = MainWP_System_Utility::get_wp_file_system();

            /**
             * WordPress files system object.
             *
             * @global object
             */
            global $wp_filesystem;
            $sig_value = wp_unslash( $_GET['sig'] );
            $valid_sig = ! stristr( rawurldecode( $mwpdl ), '..' ) && MainWP_System_Utility::valid_download_sig( $file, $sig_value );

            if ( $hasWPFileSystem && $wp_filesystem->exists( $file ) && $valid_sig ) {
                MainWP_System_Handler::instance()->upload_file( $file );
                exit();
            }

            status_header( 403 );
            header( 'Content-Type: text/plain; charset=utf-8' );
            echo 'Download link is invalid or expired.';
            exit();
        } elseif ( isset( $_GET['page'] ) ) {
            if ( MainWP_System_Utility::is_admin() && 'mainwp-setup' === $_GET['page'] ) {
                MainWP_Setup_Wizard::get_instance();
            }
        }
        // phpcs:enable
    }

    /**
     * Method init_jobs()
     *
     * Initiate mainwp schedule jobs.
     */
    public function init_jobs() {
        MainWP_System_Cron_Jobs::instance()->init_cron_jobs();
    }


    /**
     * Method after_setup_theme()
     *
     * After setup theme hook, to support post thumbnails.
     */
    public function after_setup_theme() {
        add_theme_support( 'post-thumbnails' );
    }


    /**
     * Method hook_admin_update_check()
     */
    public function hook_admin_update_check() {
        $current_ver = $this->check_ver_update;
        $saved_ver   = get_option( 'mainwp_update_check_version', '' );

        if ( version_compare( $saved_ver, $current_ver, '=' ) ) {
            return;
        }

        if ( ! empty( $saved_ver ) && version_compare( $saved_ver, '0.0.4', '<' ) ) {
            $sched = wp_next_scheduled( 'mainwp_cronstats_action' );
            if ( ! empty( $sched ) ) {
                wp_unschedule_event( $sched, 'mainwp_cronstats_action' );
            }
            global $wpdb;
            $wpdb->query( 'DELETE FROM ' . $wpdb->usermeta . ' WHERE meta_key = "mainwp_widgets_sorted_toplevel_page_mainwp_tab" OR meta_key="mainwp_settings_show_widgets"' );//phpcs:ignore -- safe.
        }

        if ( ! empty( $saved_ver ) && version_compare( $saved_ver, '0.0.5', '<' ) ) {
            $all_ext = MainWP_Extensions_View::get_available_extensions();
            foreach ( $all_ext as $slug => $info ) {
                $data = MainWP_Api_Manager::instance()->get_activation_info( $slug );
                if ( is_array( $data ) && isset( $data['api_key'] ) ) {
                    $data['api_key']       = '';
                    $data['activated_key'] = 'Deactivated';
                    MainWP_Api_Manager::instance()->set_activation_info( $slug, $data );
                }
            }
            update_option( 'mainwp_extensions_all_activation_cached', '' );
        }

        if ( version_compare( $saved_ver, '0.0.8', '<' ) ) {
            static::handle_admin_perform_update( 'attempted_repair_primary_monitors' ); // init attempted repair.
        }

        MainWP_Utility::update_option( 'mainwp_update_check_version', $current_ver );
    }


    /**
     * Method admin_init_update()
     */
    public function admin_init_update() {
        static::handle_admin_perform_update();
    }


    /**
     * Method handle_admin_perform_update()
     *
     * @param string $action Init action.
     */
    public function handle_admin_perform_update( $action = '' ) {

        $update_actions = get_option( 'mainwp_admin_init_update_data' );

        /**
         * Filters the update actions.
         *
         * @param array  $update_actions The available update actions.
         * @param string $action         The current update action.
         *
         * @since 6.2.1
         *
         * @return array The filtered update actions.
         */
        $update_actions = apply_filters( 'mainwp_admin_init_update_data', $update_actions, $action );

        if ( ! empty( $action ) ) {
            if ( ! is_array( $update_actions ) ) {
                $update_actions = array();
            }
            if ( 'attempted_repair_primary_monitors' === $action ) {
                $update_actions['attempted_repair_primary_monitors'] = 0;
            }
        }

        if ( is_array( $update_actions ) ) {
            $update = false;
            if ( isset( $update_actions['attempted_repair_primary_monitors'] ) ) {
                $result = MainWP_DB_Uptime_Monitoring::instance()->repair_missing_primary_monitors( $update_actions['attempted_repair_primary_monitors'] );
                if ( is_array( $result ) && ! empty( $result['attempted'] ) ) {
                    $update_actions['attempted_repair_primary_monitors'] = $result['attempted'];
                } else {
                    unset( $update_actions['attempted_repair_primary_monitors'] );
                }
                $update = true;
            }

            if ( $update ) {
                MainWP_Utility::update_option( 'mainwp_admin_init_update_data', $update_actions );
            }
        }
    }

    /**
     * Method admin_init()
     *
     * Do nothing if current user is not an Admin else display the page.
     *
     * @uses \MainWP\Dashboard\MainWP_Post_Backup_Handler::init()
     * @uses \MainWP\Dashboard\MainWP_Post_Extension_Handler::init()
     * @uses \MainWP\Dashboard\MainWP_Post_Handler::init()
     * @uses \MainWP\Dashboard\MainWP_Post_Plugin_Theme_Handler::init()
     * @uses \MainWP\Dashboard\MainWP_Post_Site_Handler::init()
     * @uses \MainWP\Dashboard\MainWP_System_Handler::activate_extension()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::is_admin()
     * @uses \MainWP\Dashboard\MainWP_System_View::get_class_name()
     * @uses \MainWP\Dashboard\MainWP_System_View::get_mainwp_translations()
     */
    public function admin_init() { // phpcs:ignore -- NOSONAR - complex function.

        if ( ! MainWP_System_Utility::is_admin() ) {
            return;
        }

        add_action( 'mainwp_activate_extention', array( MainWP_System_Handler::instance(), 'activate_extension' ), 10, 2 ); // @deprecated Use 'mainwp_activate_extension' instead.
        add_action( 'mainwp_deactivate_extention', array( MainWP_System_Handler::instance(), 'deactivate_extension' ), 10, 3 ); // @deprecated Use 'mainwp_deactivate_extension' instead.

        add_action( 'mainwp_activate_extension', array( MainWP_System_Handler::instance(), 'activate_extension' ), 10, 2 );
        add_action( 'mainwp_deactivate_extension', array( MainWP_System_Handler::instance(), 'deactivate_extension' ), 10, 3 );

        /**
         * MainWP use external primary backup method.
         *
         * @global string
         */
        global $mainwpUseExternalPrimaryBackupsMethod;

        if ( null === $mainwpUseExternalPrimaryBackupsMethod ) {
            $return = '';

            /*
             * @deprecated Use 'mainwp_getprimarybackup_activated' instead.
             *
             */
            $return                                = apply_filters_deprecated( 'mainwp-getprimarybackup-activated', array( $return ), '4.0.7.2', 'mainwp_getprimarybackup_activated' ); // NOSONAR - not IP.
            $mainwpUseExternalPrimaryBackupsMethod = apply_filters( 'mainwp_getprimarybackup_activated', $return );
        }

        add_action( 'mainwp_after_header', array( MainWP_System_View::get_class_name(), 'mainwp_warning_notice' ) );

        MainWP_Post_Handler::instance()->init();
        MainWP_Post_Site_Handler::instance()->init();
        MainWP_Post_Plugin_Theme_Handler::instance()->init();
        MainWP_Post_Extension_Handler::instance()->init();
        MainWP_Post_Backup_Handler::instance()->init();
        MainWP_Assistant_Policies::instance()->init();
        if ( static::is_assistant_enabled() ) {
            MainWP_Assistant::instance()->init();
        }
        MainWP_Backup_Storage_Handler::instance()->init();
        MainWP_Manage_Sites_Filter_Segment::get_instance()->admin_init();
        MainWP_Ui_Manage_Widgets_Layout::get_instance()->admin_init();

        /**
         * Filter: mainwp_ui_use_wp_calendar
         *
         * Filters whether default jQuery datepicker should be used to avoid potential problems with Senatic UI Calendar library.
         *
         * @since 4.0.5
         */
        $use_wp_datepicker = apply_filters( 'mainwp_ui_use_wp_calendar', false );
        if ( $use_wp_datepicker ) {
            wp_enqueue_script( 'jquery-ui-datepicker' );
        }
        wp_enqueue_script( 'jquery-ui-dialog' );
        $en_params = array( 'jquery-ui-dialog' );
        if ( $use_wp_datepicker ) {
            $en_params[] = 'jquery-ui-datepicker';
        }

        static::$defer_js_handle = array_merge( static::$defer_js_handle, array( 'mainwp' ) );

        wp_enqueue_script( 'mainwp', MAINWP_PLUGIN_URL . 'assets/js/mainwp.js', $en_params, $this->current_version, true );
        wp_enqueue_script( 'mainwp-cache-warm', MAINWP_PLUGIN_URL . 'assets/js/mainwp-cache-warm.js', array(), $this->current_version, true );

        $disable_backup_checking = 1 !== (int) get_option( 'mainwp_backup_before_upgrade' );
        $mainwpParams            = array(
            'image_url'                        => MAINWP_PLUGIN_URL . 'assets/images/',
            'backup_before_upgrade'            => 1 === (int) get_option( 'mainwp_backup_before_upgrade' ),
            'disable_checkBackupBeforeUpgrade' => $disable_backup_checking,
            'admin_url'                        => admin_url(),
            'use_wp_datepicker'                => $use_wp_datepicker ? 1 : 0,
            'date_format'                      => get_option( 'date_format' ),
            'time_format'                      => get_option( 'time_format' ),
            'installedBulkSettingsManager'     => is_plugin_active( 'mainwp-bulk-settings-manager/mainwp-bulk-settings-manager.php' ) ? 1 : 0,
            'maximumSyncRequests'              => ( get_option( 'mainwp_maximumSyncRequests' ) === false ) ? 8 : get_option( 'mainwp_maximumSyncRequests' ),
            'maximumInstallUpdateRequests'     => ( get_option( 'mainwp_maximumInstallUpdateRequests' ) === false ) ? 3 : get_option( 'mainwp_maximumInstallUpdateRequests' ),
            'maximumUptimeMonitoringRequests'  => (int) get_option( 'mainwp_maximum_uptime_monitoring_requests', 10 ),
            'siteRemovalTimeout'               => MainWP_Connect::get_site_removal_ajax_timeout_ms(),
            '_wpnonce'                         => wp_create_nonce( 'mainwp-admin-nonce' ),
            'quickThemeChangeNonce'            => wp_create_nonce( 'mainwp_quick_theme_change' ),
            'demoMode'                         => MainWP_Demo_Handle::is_demo_mode() ? 1 : 0,
            'roll_ui_icon'                     => MainWP_Updates_Helper::get_roll_icon( '', true ),
            'admin_url_base'                   => admin_url(),
            'mainwpVersion'                    => static::$version,
        );
        wp_localize_script( 'mainwp', 'mainwpParams', $mainwpParams );

        $mainwpTranslations = MainWP_System_View::get_mainwp_translations();

        wp_localize_script( 'mainwp', 'mainwpTranslations', $mainwpTranslations );

        $security_nonces = MainWP_Post_Handler::instance()->create_security_nonces(); // to fix conflict with Post S M T P plugin.
        if ( static::is_assistant_enabled() ) {
            $assistant_nonces = MainWP_Assistant::instance()->create_security_nonces();
            if ( is_array( $assistant_nonces ) && ! empty( $assistant_nonces ) ) {
                $security_nonces = array_merge( $security_nonces, $assistant_nonces );
            }
        }
        $nonces_filter = apply_filters( 'mainwp_security_nonces', array() );
        if ( is_array( $nonces_filter ) && ! empty( $nonces_filter ) ) {
            $security_nonces = array_merge( $security_nonces, $nonces_filter );
        }
        wp_localize_script( 'mainwp', 'security_nonces', $security_nonces );

        wp_enqueue_script( 'thickbox' );
        wp_enqueue_style( 'thickbox' );

        $load_gridstack = false;

        // phpcs:disable WordPress.Security.NonceVerification
        if ( ( isset( $_GET['page'] ) && ( 'mainwp_tab' === $_GET['page'] || ( 'managesites' === $_GET['page'] && isset( $_GET['dashboard'] ) ) ) ) || ( isset( $_GET['page'] ) && 'ManageClients' === $_GET['page'] && isset( $_GET['client_id'] ) ) || ( isset( $_GET['page'] ) && 0 === strpos( wp_unslash( $_GET['page'] ), 'ManageSites' ) ) ) { //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- individual page.
            $load_gridstack = true;
        }

        // compatible filter.
        $load_gridstack = apply_filters( 'mainwp_enqueue_script_gridster', $load_gridstack );

        if ( $load_gridstack ) {
            static::$defer_js_handle[] = 'mainwp_gridstack';
            wp_enqueue_script( 'mainwp_gridstack', MAINWP_PLUGIN_URL . 'assets/js/gridstack/gridstack-all.js', array( 'jquery' ), '14.1.0', true );
            wp_enqueue_style( 'mainwp_gridstack', MAINWP_PLUGIN_URL . 'assets/js/gridstack/gridstack.min.css', array(), '14.1.0' );
        }

        if ( isset( $_GET['page'] ) && ( 'managesites' === $_GET['page'] || 'MonitoringSites' === $_GET['page'] || 'ManageGroups' === $_GET['page'] ) ) { //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            wp_enqueue_script( 'preview', MAINWP_PLUGIN_URL . 'assets/js/preview.js', array(), $this->current_version, true );
            wp_enqueue_style( 'preview', MAINWP_PLUGIN_URL . 'assets/css/preview.css', array(), $this->current_version );
        }
        // phpcs:enable

        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );

        $this->init_session();

        if ( ! current_user_can( 'update_core' ) ) {
            remove_action( 'admin_notices', 'update_nag', 3 );
        }
    }


    /**
     * Method hook_wp_shutdown()
     */
    public function hook_wp_shutdown() {
        if ( MainWP_Logger::DISABLED !== (int) get_option( 'mainwp_actionlogs' ) ) {
            $error = error_get_last();
            if ( is_array( $error ) && isset( $error['type'] ) && ( E_ERROR === $error['type'] || E_CORE_ERROR === $error['type'] || E_COMPILE_ERROR === $error['type'] || E_PARSE === $error['type'] ) ) {
                MainWP_Logger::instance()->log_action( '[Fatal ERROR detected=' . print_r( $error, true ) . ']', false, MainWP_Logger::WARNING_COLOR, true ); //phpcs:ignore -- NOSONAR.
            }
        }
        MainWP_Cache_Helper::log_metrics();

        /**
         * MainWP shutdown.
         *
         * @since 5.5.
         */
        do_action( 'mainwp_shutdown' );

        $exctime = MainWP_Execution_Helper::get_run_time();
        MainWP_Logger::instance()->log_events( 'execution-time', 'Shutdown :: ' . $exctime );
        MainWP_Logger::instance()->log_execution_sync( 'end' );
    }

    /**
     * Method hook_plugin_action_links()
     *
     *  @param string[] $actions     An array of plugin action links. By default this can include
     *                              'activate', 'deactivate', and 'delete'. With Multisite active
     *                              this can also include 'network_active' and 'network_only' items.
     * @param string   $plugin_file Path to the plugin file relative to the plugins directory.
     * @param array    $plugin_data An array of plugin data. See get_plugin_data()
     *                              and the {@see 'plugin_row_meta'} filter for the list
     *                              of possible values.
     */
    public function hook_plugin_action_links( $actions, $plugin_file, $plugin_data ) {
        unset( $plugin_file ); // not use, compatible.
        if ( is_array( $plugin_data ) && ! empty( $plugin_data['slug'] ) && ! empty( $plugin_data['plugin'] ) && 'mainwp' === $plugin_data['slug'] && 'mainwp/mainwp.php' === $plugin_data['plugin'] ) {
            $tmp = array(
                'mainwp-setup' => '<a href="admin.php?page=mainwp-setup" id="mainwp-setup" aria-label="' . esc_html__( 'MainWP Dashboard Setup Wizard', 'mainwp' ) . '">' . esc_html__( 'Setup Wizard', 'mainwp' ) . '</a>',
            );
            if ( is_array( $actions ) ) {
                foreach ( $actions as $key => $val ) {
                    $tmp[ $key ] = $val;

                }
            }
            return $tmp;
        }
        return $actions;
    }

    /**
     * Method current_screen_redirects()
     *
     * MainWP current screen redirects.
     */
    public function current_screen_redirects() {

        if ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || defined( 'DOING_AJAX' ) ) {
            return;
        }
        if ( static::is_mainwp_pages() ) {
            $quick_setup = get_option( 'mainwp_run_quick_setup', false );
            if ( 'yes' === $quick_setup ) {
                delete_option( 'mainwp_run_quick_setup' );
                wp_safe_redirect( admin_url( 'admin.php?page=mainwp-setup' ) );
                exit;
            }
        }

        if ( ! empty( $_GET['page'] ) && in_array( $_GET['page'], array( 'mainwp-setup' ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            return;
        }

        if ( static::is_mainwp_pages() && 'yes' === get_option( 'mainwp_activated' ) ) {
            delete_option( 'mainwp_activated' );
            wp_cache_delete( 'mainwp_activated', 'options' );
            wp_cache_delete( 'alloptions', 'options' );
            wp_safe_redirect( admin_url( 'admin.php?page=mainwp_tab' ) );
            exit;
        }
    }

    /**
     * Method admin_redirects()
     *
     * MainWP admin redirects.
     */
    public function admin_redirects() {
        if ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || defined( 'DOING_AJAX' ) ) {
            return;
        }

        if ( ! empty( $_GET['page'] ) && in_array( $_GET['page'], array( 'mainwp-setup' ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            return;
        }

        $quick_setup = get_option( 'mainwp_run_quick_setup', false );
        if ( 'yes' === $quick_setup ) {
            wp_safe_redirect( admin_url( 'admin.php?page=mainwp-setup' ) );
            exit;
        }

        MainWP_Logger::instance()->check_log_daily();
    }

    /**
     * Method init_session()
     *
     * Check current page & initiate a session.
     *
     * @uses \MainWP\Dashboard\MainWP_Cache::init_session()
     */
    public function init_session() {
        $page = isset( $_GET['page'] ) ? wp_unslash( $_GET['page'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if ( ! empty( $page ) && in_array(
            $page,
            array(
                'PostBulkManage',
                'PageBulkManage',
                'PluginsManage',
                'PluginsAutoUpdate',
                'ThemesManage',
                'ThemesAutoUpdate',
                'UserBulkManage',
            )
        )
        ) {
            MainWP_Cache::init_session();
        }
    }

    /**
     * Method clear_sessions()
     *
     *  When logout clear the sessions to reset.
     */
    public function clear_sessions() {
        MainWP_Cache::init_session();
        $clear_cached = array(
            'Post',
            'Page',
        );
        foreach ( $clear_cached as $ca ) {
            MainWP_Cache::init_cache( $ca ); // to re-set cache.
        }
    }


    /**
     * Draft support copy shared by the panel markup and JavaScript.
     *
     * @return array
     */
    public static function assistant_support_l10n() {
        return array(
            'menu'          => __( 'Contact support', 'mainwp' ),
            'link'          => __( 'Still stuck? Contact MainWP Support', 'mainwp' ),
            'email'         => __( 'Email', 'mainwp' ),
            'note'          => __( 'Your note', 'mainwp' ),
            'includes'      => __( 'We will send:', 'mainwp' ),
            'transcript'    => __( 'This conversation’s questions and answers', 'mainwp' ),
            'history_off'   => __( 'Conversation history is off. Questions and answers will not be sent.', 'mainwp' ),
            'versions'      => __( 'Dashboard, WordPress and PHP versions', 'mainwp' ),
            'send'          => __( 'Send', 'mainwp' ),
            'cancel'        => __( 'Cancel', 'mainwp' ),
            // translators: %s: reply email address.
            'success'       => __( 'Thanks. MainWP Support will reply by email to %s.', 'mainwp' ),
            'failure'       => __( "We couldn't send this. You can reach MainWP Support at", 'mainwp' ),
            'contact_label' => __( 'mainwp.com/contact', 'mainwp' ),
            'note_required' => __( 'Please add a note so MainWP Support knows how to help.', 'mainwp' ),
        );
    }

    /**
     * Method admin_enqueue_scripts()
     *
     * Enqueue all Mainwp Admin Scripts.
     */
    public function admin_enqueue_scripts() { // phpcs:ignore -- NOSONAR - complex function.

        $load_cust_scripts = false;

        /**
         * Current pagenow.
         *
         * @global string
         */
        global $pagenow;

        if ( is_plugin_active( 'mainwp-custom-post-types/mainwp-custom-post-types.php' ) && ( 'post-new.php' === $pagenow || 'post.php' === $pagenow ) ) {
            $load_cust_scripts = true;
        }

        if ( static::is_mainwp_pages() ) {
            wp_enqueue_script( 'jquery-migrate' );
            wp_enqueue_script( 'mainwp-updates', MAINWP_PLUGIN_URL . 'assets/js/mainwp-updates.js', array(), $this->current_version, true );
            wp_enqueue_script( 'mainwp-managesites-action', MAINWP_PLUGIN_URL . 'assets/js/mainwp-managesites-action.js', array(), $this->current_version, true );
            wp_enqueue_script( 'mainwp-managesites-update', MAINWP_PLUGIN_URL . 'assets/js/mainwp-managesites-update.js', array(), $this->current_version, true );
            wp_enqueue_script( 'mainwp-managesites-import', MAINWP_PLUGIN_URL . 'assets/js/mainwp-managesites-import.js', array(), $this->current_version, true );
            wp_enqueue_script( 'mainwp-plugins-themes', MAINWP_PLUGIN_URL . 'assets/js/mainwp-plugins-themes.js', array(), $this->current_version, true );
            wp_enqueue_script( 'mainwp-backups', MAINWP_PLUGIN_URL . 'assets/js/mainwp-backups.js', array(), $this->current_version, true );
            wp_enqueue_script( 'mainwp-posts', MAINWP_PLUGIN_URL . 'assets/js/mainwp-posts.js', array(), $this->current_version, true );
            wp_enqueue_script( 'mainwp-users', MAINWP_PLUGIN_URL . 'assets/js/mainwp-users.js', array(), $this->current_version, true );
            wp_enqueue_script( 'mainwp-clients', MAINWP_PLUGIN_URL . 'assets/js/mainwp-clients.js', array(), $this->current_version, true );
            wp_enqueue_script( 'mainwp-knowledge', MAINWP_PLUGIN_URL . 'assets/js/mainwp-knowledge.js', array( 'jquery', 'fomantic-ui' ), $this->current_version, true );
            wp_localize_script( 'mainwp-knowledge', 'mainwpKnowledgeStrings', MainWP_Knowledge_UI::js_strings() );
            wp_enqueue_script( 'mainwp-extensions', MAINWP_PLUGIN_URL . 'assets/js/mainwp-extensions.js', array(), $this->current_version, true );
            wp_enqueue_script( 'moment' );
            wp_enqueue_script( 'fomantic-ui', MAINWP_PLUGIN_URL . 'assets/js/fomantic-ui/fomantic-ui.js', array( 'jquery' ), $this->current_version, false );

            wp_enqueue_script( 'datatables', MAINWP_PLUGIN_URL . 'assets/js/datatables/datatables.min.js', array( 'jquery' ), $this->current_version, false );
            wp_enqueue_script( 'datatables-semanticui', MAINWP_PLUGIN_URL . 'assets/js/datatables/dataTables.semanticui.min.js', array( 'datatables' ), $this->current_version, false );
            wp_enqueue_script( 'datatables-select', MAINWP_PLUGIN_URL . 'assets/js/datatables/dataTables.select.min.js', array( 'datatables' ), $this->current_version, false );
            wp_enqueue_script( 'datatables-add-ons', MAINWP_PLUGIN_URL . 'assets/js/datatables/add-ons.datatables.min.js', array( 'datatables' ), $this->current_version, false );

            wp_enqueue_script( 'datatables-natural-sorting', MAINWP_PLUGIN_URL . 'assets/js/sorting/natural.min.js', array( 'jquery', 'datatables' ), $this->current_version, true );

            wp_enqueue_script( 'clipboard' );
            wp_enqueue_script( 'mainwp-ai-client-setup', MAINWP_PLUGIN_URL . 'assets/js/mainwp-ai-client-setup.js', array(), $this->current_version, true );
            wp_enqueue_script( 'mainwp-rest-api', MAINWP_PLUGIN_URL . 'assets/js/mainwp-rest-api.js', array( 'mainwp-ai-client-setup' ), time(), true );

            if ( isset( $_GET['page'] ) && 'ManageGroups' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                wp_enqueue_script( 'mainwp-groups', MAINWP_PLUGIN_URL . 'assets/js/mainwp-groups.js', array(), $this->current_version, true );
            }
            $enqueue_scripts = apply_filters( 'mainwp_admin_enqueue_scripts', array() );
            if ( is_array( $enqueue_scripts ) && ! empty( $enqueue_scripts['apexcharts'] ) ) {
                wp_enqueue_script(
                    'mainwp-apexcharts',
                    MAINWP_PLUGIN_URL . 'assets/js/apexcharts/apexcharts.min.js',
                    array(
                        'jquery',
                        'mainwp',
                    ),
                    $this->current_version,
                    true
                );
                static::$defer_js_handle[] = 'mainwp-apexcharts';
            }
            wp_enqueue_script( 'mainwp-dropzone', MAINWP_PLUGIN_URL . 'assets/js/dropzone/dropzone.min.js', array(), $this->current_version, true );
            wp_enqueue_script( 'mainwp-uptime', MAINWP_PLUGIN_URL . 'assets/js/mainwp-uptime.js', array( 'jquery', 'fomantic-ui' ), $this->current_version, true );
            static::$defer_js_handle = array_merge( static::$defer_js_handle, array( 'mainwp-updates', 'mainwp-managesites-action', 'mainwp-managesites-update', 'mainwp-managesites-import', 'mainwp-plugins-themes', 'mainwp-managesites-import', 'mainwp-plugins-themes', 'mainwp-backups', 'mainwp-posts', 'mainwp-users', 'mainwp-clients', 'fomantic-ui', 'datatables', 'datatables-semanticui', 'datatables-select', 'datatables-add-ons', 'mainwp-dropzone', 'mainwp-uptime' ) );

        }

        if ( $load_cust_scripts ) {
            static::$defer_js_handle[] = 'fomantic-ui';
            wp_enqueue_script( 'fomantic-ui', MAINWP_PLUGIN_URL . 'assets/js/fomantic-ui/fomantic-ui.js', array( 'jquery' ), $this->current_version, true );
        }

        wp_enqueue_script( 'mainwp-ui', MAINWP_PLUGIN_URL . 'assets/js/mainwp-ui.js', array(), $this->current_version, true );
        wp_localize_script(
            'mainwp-ui',
            'mainwpWidgetLayout',
            array(
                'openNonce' => wp_create_nonce( 'mainwp-admin-nonce' ),
            )
        );
        if ( static::is_mainwp_pages() && static::is_assistant_enabled() ) {
            // Shared pure decision logic, also exercised directly by the Node
            // test suite (tests/js/test-*.js); registered as a dependency so
            // it always loads before mainwp-mintlify-chat.js.
            wp_register_script( 'mainwp-assistant-chat-logic', MAINWP_PLUGIN_URL . 'assets/js/mainwp-assistant-chat-logic.js', array(), $this->current_version, true );
            wp_register_script( 'mainwp-mintlify-chat', MAINWP_PLUGIN_URL . 'assets/js/mainwp-mintlify-chat.js', array( 'jquery', 'mainwp', 'mainwp-assistant-chat-logic' ), $this->current_version, true );
            wp_register_style( 'mainwp-mintlify-chat', MAINWP_PLUGIN_URL . 'assets/css/mainwp-mintlify-chat.css', array(), $this->current_version );
            wp_register_script( 'mainwp-assistant-onboarding', MAINWP_PLUGIN_URL . 'assets/js/mainwp-assistant-onboarding.js', array( 'jquery', 'mainwp-assistant-chat-logic' ), $this->current_version, true );
            wp_register_style( 'mainwp-assistant-onboarding', MAINWP_PLUGIN_URL . 'assets/css/mainwp-assistant-onboarding.css', array(), $this->current_version );
            wp_enqueue_script( 'mainwp-assistant-chat-logic' );
            wp_enqueue_script( 'mainwp-mintlify-chat' );
            wp_enqueue_script( 'mainwp-assistant-onboarding' );
            wp_enqueue_style( 'mainwp-mintlify-chat' );
            wp_enqueue_style( 'mainwp-assistant-onboarding' );

            // Localize batch job strings for JavaScript.
            wp_localize_script(
                'mainwp-mintlify-chat',
                'mainwpMintlifyChatL10n',
                array_merge(
                    array(
                        'support'                     => self::assistant_support_l10n(),
                        'support_email'               => wp_get_current_user()->user_email,
                        'support_history_disabled'    => (bool) get_user_meta( get_current_user_id(), 'mainwp_assistant_history_opt_out', true ),
                        // Message rendering strings.
                        'sources_header'              => esc_html__( 'Sources:', 'mainwp' ),
                        'truncated_notice_answer'     => esc_html__( 'This answer was shortened because it was too long.', 'mainwp' ),
                        'truncated_notice_sources'    => esc_html__( 'Some sources were left out of this answer.', 'mainwp' ),
                        'truncated_notice_both'       => esc_html__( 'This answer was shortened and some sources were left out.', 'mainwp' ),
                        // translators: %d: number of processed items.
                        'items_processed'             => esc_html__( '%d items processed.', 'mainwp' ),
                        // translators: %d: number of failed items.
                        'items_failed'                => esc_html__( '%d failed.', 'mainwp' ),
                        // Batch processing strings.
                        'batch_processing'            => esc_html__( 'Processing batch operation...', 'mainwp' ),
                        'batch_queued'                => esc_html__( 'Batch job queued', 'mainwp' ),
                        'batch_processing_sites'      => esc_html__( 'Processing', 'mainwp' ),
                        'batch_complete'              => esc_html__( 'Batch operation completed.', 'mainwp' ),
                        'batch_failed'                => esc_html__( 'Batch operation failed.', 'mainwp' ),
                        'batch_timeout'               => esc_html__( 'Batch operation timed out. The operation may still be running on the server.', 'mainwp' ),
                        'batch_resumed'               => esc_html__( 'Resuming batch operation...', 'mainwp' ),
                        'batch_expired'               => esc_html__( 'Batch job expired (jobs are kept for 24 hours).', 'mainwp' ),
                        'batch_retrying'              => esc_html__( 'Connection lost, retrying...', 'mainwp' ),
                        'batch_connection_lost'       => esc_html__( 'Connection lost. Unable to retrieve batch job status.', 'mainwp' ),
                        'batch_almost_done'           => esc_html__( 'Almost done...', 'mainwp' ),
                        'estimated_time'              => esc_html__( 'Estimating...', 'mainwp' ),
                        'estimated_less_minute'       => esc_html__( 'Less than a minute remaining', 'mainwp' ),

                        // Mode indicator for attribution line.
                        'mode_ask'                    => esc_html__( 'Docs', 'mainwp' ),
                        'mode_agent'                  => esc_html__( 'Sites', 'mainwp' ),
                        // translators: %s: AI provider name.
                        'mode_agent_with_provider'    => esc_html__( 'Sites mode (%s)', 'mainwp' ),
                        'mode_no_provider'            => esc_html__( 'No AI connection is configured.', 'mainwp' ),

                        // Routing receipt.
                        'agent_fallback_notice'       => esc_html__( 'Answered from MainWP docs. Sites mode needs an AI connection with a provider API key under Settings > Connectors.', 'mainwp' ),
                        'connectors_link_text'        => esc_html__( 'Open Connectors settings', 'mainwp' ),
                        'connectors_settings_url'     => esc_url( apply_filters( 'mainwp_assistant_connectors_url', admin_url( 'options-connectors.php' ) ) ),
                        'mode_wp_version'             => esc_html__( 'Sites mode needs WordPress 7.0 or newer. Docs mode works on this version.', 'mainwp' ),

                        // Docs-to-Sites handoff offer.
                        'handoff_explanation'         => esc_html__( 'Review this request in Sites. When you send it, Sites uses your configured AI provider.', 'mainwp' ),
                        'handoff_continue'            => esc_html__( 'Continue in Sites', 'mainwp' ),
                        'handoff_ask_docs'            => esc_html__( 'Ask Docs instead', 'mainwp' ),
                        'handoff_dismiss'             => esc_html__( 'Dismiss', 'mainwp' ),

                        // Connect free AI card and the wizard anchors it shares
                        // with the error bubbles.
                        'connect_card_title'          => esc_html__( 'Connect free AI', 'mainwp' ),
                        'connect_card_lead'           => esc_html__( 'About two minutes. No credit card. Google\'s Gemini free tier.', 'mainwp' ),
                        'connect_card_cta'            => esc_html__( 'Connect free AI', 'mainwp' ),
                        'connect_card_alt'            => esc_html__( 'Use OpenAI or Anthropic instead', 'mainwp' ),
                        'connect_card_disclosure'     => MainWP_Assistant_Onboarding::free_tier_disclosure(),
                        'connect_card_dismiss'        => esc_html__( 'Dismiss', 'mainwp' ),
                        'wizard_link_text'            => esc_html__( 'Connect AI', 'mainwp' ),

                        // Message attribution and per-message controls.
                        'assistant_name'              => esc_html__( 'MainWP Assistant', 'mainwp' ),
                        'feedback_helpful'            => __( 'Helpful answer', 'mainwp' ),
                        'feedback_unhelpful'          => __( 'Unhelpful answer', 'mainwp' ),
                        'feedback_saved'              => __( 'Feedback saved. Thank you.', 'mainwp' ),
                        'feedback_removed'            => __( 'Feedback removed.', 'mainwp' ),
                        'feedback_error'              => __( 'Could not save feedback. Please try again.', 'mainwp' ),
                        'copy_response'               => esc_html__( 'Copy response', 'mainwp' ),
                        'copy_success'                => __( 'Copied!', 'mainwp' ),
                        'copy_failed'                 => __( 'Copy failed. Select the response text and copy it manually.', 'mainwp' ),
                        'regenerate_label'            => esc_html__( 'Regenerate last response', 'mainwp' ),

                        // Control buttons.
                        'send_message'                => esc_html__( 'Send message', 'mainwp' ),
                        'stop_generation'             => esc_html__( 'Stop', 'mainwp' ),
                        'regenerate_response'         => esc_html__( 'Regenerate', 'mainwp' ),
                        'generation_stopped'          => esc_html__( 'Response generation stopped.', 'mainwp' ),
                        'batch_stop_monitoring'       => esc_html__( 'Stopped monitoring here. The batch operation keeps running on the server. Reload the page to resume monitoring it.', 'mainwp' ),
                        'thinking'                    => esc_html__( 'Thinking...', 'mainwp' ),
                        'elapsed_time'                => esc_html__( '%s elapsed', 'mainwp' ),
                        'thinking_preparing'          => esc_html__( 'Preparing request...', 'mainwp' ),
                        'thinking_api'                => esc_html__( 'Waiting for AI response...', 'mainwp' ),
                        'thinking_api_long'           => esc_html__( 'Waiting for AI response... Sites queries typically take 1-3 minutes.', 'mainwp' ),
                        'thinking_tools'              => esc_html__( 'Executing actions...', 'mainwp' ),
                        'thinking_followup'           => esc_html__( 'Generating response...', 'mainwp' ),
                        'thinking_still_processing'   => esc_html__( 'Still processing... Complex queries take longer.', 'mainwp' ),
                        'agent_mode_hint'             => esc_html__( 'Sites mode queries your AI provider directly. Responses typically take 1-3 minutes.', 'mainwp' ),

                        // Token usage line.
                        'token_usage'                 => esc_html__( '%s tokens', 'mainwp' ),
                        /* translators: 1: token count, 2: estimated USD amount */
                        'token_usage_cost'            => esc_html__( '%1$s tokens · est. %2$s', 'mainwp' ),
                        /* translators: %s: number of tokens. */
                        'token_usage_plan'            => esc_html__( '%s tokens · ChatGPT plan', 'mainwp' ),
                        'cost_includes_confirmation'  => esc_html__( '(includes confirmation step)', 'mainwp' ),
                        /* translators: 1: token count, 2: estimated USD amount */
                        'session_cost_label'          => esc_html__( '%1$s tokens · est. %2$s this conversation', 'mainwp' ),
                        /* translators: %s: token count */
                        'session_tokens_label'        => esc_html__( '%s tokens this conversation', 'mainwp' ),
                        // History panel.
                        'history_title'               => esc_html__( 'Conversation History', 'mainwp' ),
                        'history_search'              => esc_html__( 'Search conversations...', 'mainwp' ),
                        'history_no_results'          => esc_html__( 'No conversations found', 'mainwp' ),
                        'history_load_error'          => esc_html__( 'Failed to load history', 'mainwp' ),
                        'conversation_load_error'     => esc_html__( 'Failed to load conversation', 'mainwp' ),
                        'history_delete_confirm'      => esc_html__( 'Delete this conversation?', 'mainwp' ),
                        'history_export_json'         => esc_html__( 'Export as JSON', 'mainwp' ),
                        'history_export_markdown'     => esc_html__( 'Export as Markdown', 'mainwp' ),
                        'history_message_count'       => esc_html__( '%d messages', 'mainwp' ),

                        // Export validation.
                        'export_no_messages'          => esc_html__( 'No messages to export. Start a conversation first.', 'mainwp' ),

                        // First-run mode explainer.
                        'modes_intro_title'           => esc_html__( 'Two ways to ask', 'mainwp' ),
                        'modes_intro_text'            => esc_html__( 'Docs answers questions about MainWP from the official documentation. Sites works with your own sites using your AI provider and can take actions with your confirmation.', 'mainwp' ),
                        'modes_intro_dismiss'         => esc_html__( 'Got it', 'mainwp' ),

                        // Welcome message.
                        'welcome_title'               => esc_html__( 'Welcome to MainWP Assistant', 'mainwp' ),
                        'welcome_subtext'             => esc_html__( 'Ask me anything about MainWP', 'mainwp' ),

                        // Confirmation dialogs.
                        'confirm_destructive'         => esc_html__( 'Confirm Destructive Action', 'mainwp' ),
                        'confirm_destructive_txt'     => esc_html__( 'This action will make permanent changes. Review the preview below:', 'mainwp' ),
                        'confirm_action'              => esc_html__( 'Confirm Action', 'mainwp' ),
                        'confirm_action_txt'          => esc_html__( 'Please confirm you want to proceed with this action:', 'mainwp' ),
                        'confirm_proceed'             => esc_html__( 'Proceed', 'mainwp' ),
                        'confirm_cancel'              => esc_html__( 'Cancel', 'mainwp' ),
                        'confirmation_required'       => esc_html__( 'I need your confirmation to proceed with: %s', 'mainwp' ),
                        'confirm_action_label'        => esc_html__( 'Action:', 'mainwp' ),
                        'confirm_affected_sites'      => esc_html__( 'Affected sites:', 'mainwp' ),
                        // translators: %d: number of additional sites not shown in the list.
                        'confirm_more_sites'          => esc_html__( '+%d more', 'mainwp' ),
                        'confirm_technical_details'   => esc_html__( 'Technical details', 'mainwp' ),
                        'confirm_type_hint'           => esc_html__( 'Type "yes" to proceed, type "cancel" to dismiss, or use the buttons.', 'mainwp' ),
                        'confirm_cancelled'           => esc_html__( 'Cancelled. The action was not executed.', 'mainwp' ),
                        'confirm_cancel_unconfirmed'  => esc_html__( 'Couldn\'t confirm the cancellation with the server. The pending action may remain active until it expires.', 'mainwp' ),
                        'confirm_already_settled'     => esc_html__( 'This action was already confirmed or cancelled elsewhere, so nothing ran here.', 'mainwp' ),
                        'cancel_already_settled'      => esc_html__( 'This action was already confirmed in another tab, so the cancel did not apply.', 'mainwp' ),
                        'cancel_nothing_pending'      => esc_html__( 'This request was already resolved or replaced by a newer one, so there was nothing to cancel.', 'mainwp' ),
                        // Typed-confirmation word lists, shared with the server-side
                        // classifier (same translations and filters).
                        'confirm_affirmative_words'   => implode( ',', MainWP_Assistant::get_affirmative_words() ),
                        'confirm_negative_words'      => implode( ',', MainWP_Assistant::get_negative_words() ),

                        // Action result messages.
                        'action_failed'               => esc_html__( 'Failed to execute action. Please try again.', 'mainwp' ),
                        // action_success is the older key name for action_completed;
                        // both stay registered with the same text so existing JS
                        // call sites keep resolving.
                        'action_completed'            => esc_html__( 'Action completed.', 'mainwp' ),
                        'action_success'              => esc_html__( 'Action completed.', 'mainwp' ),
                        'action_partial'              => esc_html__( 'Action completed with errors. See the details below.', 'mainwp' ),

                        // Batch UI labels.
                        'batch_show_errors'           => esc_html__( 'Show errors (%d)', 'mainwp' ),
                        // translators: %d: number of errors not shown in the list.
                        'batch_errors_more'           => esc_html__( '...and %d more. Export the conversation for the full list.', 'mainwp' ),
                        'batch_sites_label'           => esc_html__( 'sites', 'mainwp' ),
                        'batch_succeeded_label'       => esc_html__( 'succeeded', 'mainwp' ),
                        'batch_failed_label'          => esc_html__( 'failed', 'mainwp' ),
                        'batch_of_total'              => esc_html__( '%1$s of %2$s', 'mainwp' ),
                        'batch_queued_waiting'        => esc_html__( 'Batch job queued, waiting to start...', 'mainwp' ),
                        'estimated_minute_remaining'  => esc_html__( '~%d minute remaining', 'mainwp' ),
                        'estimated_minutes_remaining' => esc_html__( '~%d minutes remaining', 'mainwp' ),
                        'estimated_hours_remaining'   => esc_html__( '~%1$dh %2$dm remaining', 'mainwp' ),

                        // Confirmation execution.
                        'executing_action'            => esc_html__( 'Executing action...', 'mainwp' ),

                        // Policy warnings.
                        'policy_readonly_block'       => esc_html__( 'Action blocked. You are in read-only mode.', 'mainwp' ),
                        'policy_protected_tag'        => esc_html__( 'This affects sites with protected tags. Extra confirmation required.', 'mainwp' ),

                        // Error messages.
                        'error_message_too_long'      => esc_html__( 'Message is too long. Please keep it under 2000 characters.', 'mainwp' ),
                        'error_unknown'               => esc_html__( 'The server did not say what went wrong. Please try again.', 'mainwp' ),
                        'chat_timeout'                => esc_html__( 'The request timed out. The server may still be working on it, so reload the page to check.', 'mainwp' ),
                        'chat_connection_lost'        => esc_html__( 'Lost the connection while waiting for the response. Please try again.', 'mainwp' ),
                        'chat_job_expired'            => esc_html__( 'The request expired before it finished. Please try again.', 'mainwp' ),
                        'chat_fallback_failed'        => esc_html__( 'Unable to process the request. Please try again.', 'mainwp' ),
                        'chat_result_unreadable'      => esc_html__( 'The response arrived in a form the Assistant could not read. Please try again.', 'mainwp' ),
                        'chat_job_failed'             => esc_html__( 'The request could not be completed. Please try again.', 'mainwp' ),
                        'error_generic'               => esc_html__( 'An error occurred. Please try again.', 'mainwp' ),
                        'error_connection'            => esc_html__( 'Unable to connect. Please check your connection and try again.', 'mainwp' ),
                        'try_again'                   => esc_html__( 'Try again', 'mainwp' ),
                        'retry_countdown'             => esc_html__( 'Try again in %ds', 'mainwp' ),
                        'assistant_settings_url'      => esc_url( admin_url( 'admin.php?page=AssistantSettings' ) ),
                        'assistant_settings_link'     => esc_html__( 'Open Assistant settings', 'mainwp' ),

                        // Layered error messages keyed by server error code. Codes
                        // without an entry fall back to the server's own message.
                        // Plain text, not esc_html__(): wp_localize_script() decodes
                        // entities only on top-level values, and appendErrorMessage()
                        // escapes these when it renders them. Escaping here as well
                        // would print entities such as &gt; in the bubble.
                        'error_codes'                 => array(
                            'bridge_not_configured'     => __( 'The MainWP documentation service is not configured for this dashboard.', 'mainwp' ),
                            'bridge_no_consent'         => __( 'The Assistant needs privacy consent before it can answer. An administrator can grant it in Assistant settings.', 'mainwp' ),
                            'bridge_unauthorized'       => __( 'The MainWP documentation service rejected this dashboard\'s credentials. Please try again in a few minutes.', 'mainwp' ),
                            'bridge_quota_exceeded'     => __( 'This dashboard reached its monthly documentation usage limit. Please try again later.', 'mainwp' ),
                            'bridge_rate_limited'       => __( 'Too many documentation requests right now. Please try again later.', 'mainwp' ),
                            'bridge_unavailable'        => __( 'The MainWP documentation service is temporarily unreachable. Please try again in a moment.', 'mainwp' ),
                            'bridge_error'              => __( 'The MainWP documentation service returned an unexpected response. Please try again.', 'mainwp' ),
                            'bridge_invalid_response'   => __( 'The MainWP documentation service returned an unreadable response. Please try again.', 'mainwp' ),
                            'bridge_not_registered'     => __( 'This dashboard is not registered with the MainWP documentation service yet. Please try again in a few minutes.', 'mainwp' ),
                            'no_provider'               => __( 'No AI connection is configured, so Sites mode cannot answer.', 'mainwp' ),
                            'no_usable_provider'        => __( 'Sites mode needs an AI provider with a working API key. Add or check one in Settings > Connectors, then try again.', 'mainwp' ),
                            'ai_result_parse_failed'    => __( 'Your AI provider returned a response the Assistant could not read. Check the provider under Settings > Connectors.', 'mainwp' ),
                            'tool_loop_limit'           => __( 'The Assistant reached its action limit for this request. Try a smaller or more specific request.', 'mainwp' ),
                            // These four stay provider-neutral: this map is built
                            // once for the page, before any turn has resolved which
                            // provider answered. The provider's own message rides
                            // along as a second line when there is one. The daily
                            // quota entry is Gemini-specific; the server maps it
                            // only for Google.
                            'provider_rate_limited'     => __( 'AI provider rate limit reached. Wait a moment and try again.', 'mainwp' ),
                            'provider_daily_quota_exhausted' => __( 'Gemini free-tier quota reached. A per-minute limit clears within a minute; the daily quota resets at midnight Pacific.', 'mainwp' ),
                            'provider_key_rejected'     => __( 'Your AI provider rejected the API key for this dashboard. Check the key under Settings > Connectors.', 'mainwp' ),
                            'provider_unreachable'      => __( 'Could not reach your AI provider. Check the connection and try again.', 'mainwp' ),
                            'provider_unavailable_upstream' => __( 'Your AI provider is having trouble right now. Please try again in a moment.', 'mainwp' ),

                            'chatgpt_plan_limit'        => __( "You've reached your ChatGPT plan limit for MainWP.", 'mainwp' ),
                            'chatgpt_plan_reconnect'    => __( 'Connect your ChatGPT account again to use your plan.', 'mainwp' ),
                            'chatgpt_plan_not_eligible' => __( "This ChatGPT account can't use its plan in other apps.", 'mainwp' ),
                            'chatgpt_plan_unavailable'  => __( 'ChatGPT is not available right now. Try again in a few minutes.', 'mainwp' ),
                            'chatgpt_plan_rejected_request' => __( 'ChatGPT could not accept this request.', 'mainwp' ),
                        ),

                        // Graceful degradation.
                        'provider_unavailable'        => esc_html__( 'AI provider unavailable. Check the provider under Settings > Connectors.', 'mainwp' ),
                        'provider_setup_required'     => esc_html__( 'No AI provider configured. Add a provider API key under Settings > Connectors.', 'mainwp' ),
                        'provider_setup_available'    => esc_html__( 'AI features available', 'mainwp' ),

                        // SSE streaming nonce.
                        'sse_nonce'                   => wp_create_nonce( 'mainwp_assistant_sse_stream' ),
                    ),
                    MainWP_Assistant_Onboarding::wizard_l10n(),
                    static::assistant_chatgpt_l10n()
                )
            );

            // Expose debug flag to JS when WP_DEBUG is active.
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                wp_add_inline_script( 'mainwp-mintlify-chat', 'window.mainwp_assistant_debug=true;', 'before' );
            }

            // Register and enqueue assistant panel assets.
            wp_register_style(
                'mainwp-assistant-panel',
                MAINWP_PLUGIN_URL . 'assets/css/mainwp-assistant-panel.css',
                array( 'mainwp-mintlify-chat' ),
                $this->current_version
            );
            wp_enqueue_style( 'mainwp-assistant-panel' );

            wp_register_script(
                'mainwp-assistant-panel',
                MAINWP_PLUGIN_URL . 'assets/js/mainwp-assistant-panel.js',
                array( 'jquery', 'mainwp-mintlify-chat' ),
                $this->current_version,
                true
            );
            wp_enqueue_script( 'mainwp-assistant-panel' );

            $policies = MainWP_Assistant_Policies::instance();

            wp_localize_script(
                'mainwp-assistant-panel',
                'mainwpAssistantPanelL10n',
                array(
                    'home_title'               => esc_html__( 'MainWP Assistant', 'mainwp' ),
                    'messages_title'           => esc_html__( 'Messages', 'mainwp' ),
                    'chat_title'               => esc_html__( 'MainWP Assistant', 'mainwp' ),
                    'close_panel'              => esc_html__( 'Close', 'mainwp' ),
                    'back'                     => esc_html__( 'Back', 'mainwp' ),
                    'no_conversations'         => esc_html__( 'No conversations yet', 'mainwp' ),
                    'start_new_chat'           => esc_html__( 'Start a new chat', 'mainwp' ),
                    'loading_conversations'    => esc_html__( 'Loading conversations...', 'mainwp' ),
                    'delete_confirm'           => esc_html__( 'Delete this conversation? This cannot be undone.', 'mainwp' ),
                    'delete_error'             => esc_html__( 'Failed to delete conversation. Please try again.', 'mainwp' ),
                    'delete_label'             => esc_html__( 'Delete conversation', 'mainwp' ),
                    'export_json_label'        => esc_html__( 'Export as JSON', 'mainwp' ),
                    'export_markdown_label'    => esc_html__( 'Export as Markdown', 'mainwp' ),
                    'connection_deferred'      => esc_html__( 'Assistant enabled. It connects to the documentation service on first use.', 'mainwp' ),
                    'new_chat'                 => esc_html__( 'New Chat', 'mainwp' ),
                    'message_count'            => esc_html__( 'messages', 'mainwp' ),
                    // messages is the older key name for message_count; both
                    // stay registered with the same text.
                    'messages'                 => esc_html__( 'messages', 'mainwp' ),
                    'untitled'                 => esc_html__( 'Untitled Conversation', 'mainwp' ),
                    'load_error'               => esc_html__( 'Unable to load conversations. Please try again later.', 'mainwp' ),
                    'video_title'              => esc_html__( 'Video Tutorial', 'mainwp' ),
                    'maximize'                 => esc_html__( 'Maximize', 'mainwp' ),
                    'restore'                  => esc_html__( 'Restore', 'mainwp' ),
                    'history_disabled'         => esc_html__( 'Conversation history is disabled for your account.', 'mainwp' ),
                    // Consent gate.
                    'hasConsent'               => $policies->has_consent(),
                    'canGrantConsent'          => (bool) \mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' ),
                    'consentNonce'             => wp_create_nonce( 'mainwp_assistant_consent' ),
                    'consent_title'            => esc_html__( 'Enable Assistant Features', 'mainwp' ),
                    'consent_accept'           => esc_html__( 'Enable & Continue', 'mainwp' ),
                    'consent_cancel'           => esc_html__( 'Cancel', 'mainwp' ),
                    'consent_error'            => esc_html__( 'Failed to enable assistant features. Please try again.', 'mainwp' ),
                    'consent_no_permission'    => esc_html__( 'Assistant features are off. Ask a dashboard administrator to enable them.', 'mainwp' ),
                    'consent_required_history' => esc_html__( 'Enable assistant features to view conversation history.', 'mainwp' ),
                )
            );
        }
        wp_enqueue_script( 'mainwp-js-popup', MAINWP_PLUGIN_URL . 'assets/js/mainwp-popup.js', array(), $this->current_version, true );
        // to support extension uploader.
        wp_enqueue_script( 'mainwp-fileuploader', MAINWP_PLUGIN_URL . 'assets/js/fileuploader.js', array(), $this->current_version ); // phpcs:ignore -- fileuploader scripts need to load at header.
        wp_enqueue_script( 'mainwp-filesaver', MAINWP_PLUGIN_URL . 'assets/js/FileSaver.js', array(), $this->current_version, true );

        static::$defer_js_handle[] = 'mainwp-ui';

        if ( is_array( static::$defer_js_handle ) ) {
            $defer_handle = array_filter( array_unique( static::$defer_js_handle ) );
            foreach ( $defer_handle as $h ) {
                if ( wp_script_is( $h, 'enqueued' ) || wp_script_is( $h, 'registered' ) ) {
                    wp_script_add_data( $h, 'strategy', 'defer' ); // adds defer to <script> tag.
                }
            }
        }

        $this->enqueue_command_palette_script();
    }

    /**
     * Enqueue the MainWP command palette integration.
     */
    private function enqueue_command_palette_script() {

        if ( ! wp_script_is( 'wp-core-commands', 'enqueued' ) ) {
            return;
        }

        $palette_data = $this->get_command_palette_data();

        if ( empty( $palette_data['commands'] ) && empty( $palette_data['unregister'] ) && empty( $palette_data['siteSearch'] ) ) {
            return;
        }

        wp_enqueue_script(
            'mainwp-command-palette',
            MAINWP_PLUGIN_URL . 'assets/js/mainwp-command-palette.js',
            array( 'wp-core-commands', 'wp-data', 'wp-element', 'wp-a11y', 'wp-i18n' ),
            $this->current_version,
            true
        );

        wp_enqueue_style(
            'mainwp-command-palette',
            MAINWP_PLUGIN_URL . 'assets/css/mainwp-command-palette.css',
            array( 'wp-commands' ),
            $this->current_version
        );

        wp_add_inline_script(
            'mainwp-command-palette',
            'window.mainwpCommandPalette = ' . wp_json_encode( $palette_data, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES ) . ';',
            'before'
        );
    }

    /**
     * Build the MainWP command palette configuration.
     *
     * @return array<string, mixed> Command palette configuration.
     */
    private function get_command_palette_data() {
        $registered_pages     = $this->get_command_palette_registered_pages();
        $registered_page_urls = array();

        foreach ( $registered_pages as $slug => $page_data ) {
            $registered_page_urls[ $slug ] = $page_data['url'];
        }

        $commands = $this->get_command_palette_menu_commands( $registered_pages );

        if ( empty( $commands ) ) {
            return array(
                'commands'   => array(),
                'unregister' => array(),
                'siteSearch' => MainWP_Command_Palette::get_search_config(),
            );
        }

        $commands = apply_filters( 'mainwp_command_palette_commands', $commands, $registered_page_urls );

        $unregister = array( 'mainwp_tab' );
        foreach ( array_keys( $registered_pages ) as $slug ) {
            $unregister[] = 'mainwp_tab-' . $slug;
        }

        $unregister = apply_filters( 'mainwp_command_palette_unregister', array_values( array_unique( $unregister ) ), $registered_page_urls );

        return array(
            'commands'   => array_values( array_filter( $commands ) ),
            'unregister' => array_values( array_filter( $unregister ) ),
            'siteSearch' => MainWP_Command_Palette::get_search_config(),
        );
    }

    /**
     * Get registered MainWP submenu pages that can be opened from the palette.
     *
     * @return array<string, array<string, mixed>> Registered page map.
     */
    private function get_command_palette_registered_pages() {
        global $submenu;

        if ( empty( $submenu['mainwp_tab'] ) || ! is_array( $submenu['mainwp_tab'] ) ) {
            return array();
        }

        $registered_pages = array();

        foreach ( $submenu['mainwp_tab'] as $submenu_item ) {
            if ( empty( $submenu_item[2] ) || ( ! empty( $submenu_item[1] ) && ! current_user_can( $submenu_item[1] ) ) ) {
                continue;
            }

            $menu_slug = (string) $submenu_item[2];
            $menu_url  = '';

            if ( preg_match( '/\.php($|\?)/', $menu_slug ) || $this->is_command_palette_absolute_url( $menu_slug ) ) {
                $menu_url = $menu_slug;
            } elseif ( ! empty( menu_page_url( $menu_slug, false ) ) ) {
                $menu_url = wp_specialchars_decode( menu_page_url( $menu_slug, false ), ENT_QUOTES );
            }

            if ( empty( $menu_url ) ) {
                continue;
            }

            $raw_label = isset( $submenu_item[0] ) ? (string) $submenu_item[0] : '';

            $registered_pages[ $menu_slug ] = array(
                'label'  => $this->clean_command_palette_label( $raw_label ),
                'url'    => $menu_url,
                'hidden' => false !== strpos( $raw_label, 'mainwp-hidden' ),
            );
        }

        return $registered_pages;
    }

    /**
     * Build MainWP command-palette commands from runtime menu data.
     *
     * @param array<string, array<string, mixed>> $registered_pages Registered MainWP pages.
     *
     * @return array<int, array<string, mixed>> Command list.
     */
    private function get_command_palette_menu_commands( $registered_pages ) {
        $menu_nodes = $this->get_command_palette_menu_nodes();

        if ( empty( $menu_nodes ) ) {
            $menu_nodes = array();
        }

        $nodes_by_id   = array();
        $nodes_by_slug = array();

        foreach ( $menu_nodes as $node ) {
            $nodes_by_id[ $node['id'] ] = $node;

            if ( ! empty( $node['slug_key'] ) && ! isset( $nodes_by_slug[ $node['slug_key'] ] ) ) {
                $nodes_by_slug[ $node['slug_key'] ] = $node['id'];
            }
        }

        $commands_by_url    = array();
        $command_page_slugs = array();

        foreach ( $menu_nodes as $node ) {
            $path_titles = $this->get_command_palette_menu_path_titles( $node, $nodes_by_id, $nodes_by_slug );

            if ( empty( $path_titles ) ) {
                continue;
            }

            $label = sprintf( __( 'Go to: MainWP > %s', 'mainwp' ), implode( ' > ', $path_titles ) );

            $command_name = ! empty( $node['page_slug'] ) && isset( $registered_pages[ $node['page_slug'] ] )
                ? 'mainwp_tab-' . $node['page_slug']
                : 'mainwp-command-' . sanitize_key( ! empty( $node['page_slug'] ) ? $node['page_slug'] : md5( $node['url'] ) );

            $command = array(
                'name'        => $command_name,
                'label'       => $label,
                'url'         => $node['url'],
                'keywords'    => $this->get_command_palette_keywords( $path_titles, $node['page_slug'] ),
                'searchLabel' => $label,
                '_depth'      => count( $path_titles ),
            );

            if ( ! isset( $commands_by_url[ $node['url'] ] ) || $command['_depth'] > $commands_by_url[ $node['url'] ]['_depth'] ) {
                $commands_by_url[ $node['url'] ] = $command;
            }

            if ( ! empty( $node['page_slug'] ) ) {
                $command_page_slugs[ $node['page_slug'] ] = true;
            }
        }

        foreach ( $this->get_command_palette_utility_commands( $registered_pages ) as $command ) {
            if ( empty( $command['url'] ) || empty( $command['label'] ) ) {
                continue;
            }

            $command['_depth'] = isset( $command['_depth'] ) ? (int) $command['_depth'] : 1;

            if (
                ! isset( $commands_by_url[ $command['url'] ] ) ||
                $command['_depth'] >= $commands_by_url[ $command['url'] ]['_depth']
            ) {
                $commands_by_url[ $command['url'] ] = $command;
            }

            if ( ! empty( $command['_page_slug'] ) ) {
                $command_page_slugs[ $command['_page_slug'] ] = true;
            }
        }

        foreach ( $registered_pages as $page_slug => $page_data ) {
            if (
                isset( $command_page_slugs[ $page_slug ] ) ||
                empty( $page_data['label'] ) ||
                empty( $page_data['url'] ) ||
                ! empty( $page_data['hidden'] )
            ) {
                continue;
            }

            if ( isset( $commands_by_url[ $page_data['url'] ] ) ) {
                continue;
            }

            $label = sprintf( __( 'Go to: MainWP > %s', 'mainwp' ), $page_data['label'] );

            $commands_by_url[ $page_data['url'] ] = array(
                'name'        => 'mainwp_tab-' . $page_slug,
                'label'       => $label,
                'url'         => $page_data['url'],
                'keywords'    => $this->get_command_palette_keywords( array( $page_data['label'] ), $page_slug ),
                'searchLabel' => $label,
                '_depth'      => 1,
            );
        }

        foreach ( $commands_by_url as &$command ) {
            unset( $command['_depth'], $command['_page_slug'] );
        }
        unset( $command );

        return array_values( $commands_by_url );
    }

    /**
     * Build command-palette commands for MainWP pages that do not reliably exist in the global sidebar tree.
     *
     * @param array<string, array<string, mixed>> $registered_pages Registered MainWP pages.
     *
     * @return array<int, array<string, mixed>> Command list.
     */
    private function get_command_palette_utility_commands( $registered_pages ) {
        return array_merge(
            $this->get_command_palette_settings_commands( $registered_pages ),
            $this->get_command_palette_info_commands( $registered_pages )
        );
    }

    /**
     * Build Settings-section command palette commands.
     *
     * @param array<string, array<string, mixed>> $registered_pages Registered MainWP pages.
     *
     * @return array<int, array<string, mixed>> Command list.
     */
    private function get_command_palette_settings_commands( $registered_pages ) {
        $commands = array();

        foreach ( MainWP_Settings::get_command_palette_items() as $item ) {
            if ( empty( $item['slug'] ) || empty( $item['path_titles'] ) ) {
                continue;
            }

            $commands[] = $this->build_command_palette_registered_command(
                $registered_pages,
                $item['slug'],
                $item['path_titles'],
                array( 'mainwp settings' ),
                isset( $item['href'] ) ? $item['href'] : ''
            );
        }

        return array_values( array_filter( $commands ) );
    }

    /**
     * Build System Info and profile utility command palette commands.
     *
     * @param array<string, array<string, mixed>> $registered_pages Registered MainWP pages.
     *
     * @return array<int, array<string, mixed>> Command list.
     */
    private function get_command_palette_info_commands( $registered_pages ) {
        $commands = array();

        foreach ( MainWP_Server_Information::get_command_palette_items() as $item ) {
            if ( empty( $item['slug'] ) || empty( $item['path_titles'] ) ) {
                continue;
            }

            $commands[] = $this->build_command_palette_registered_command(
                $registered_pages,
                $item['slug'],
                $item['path_titles'],
                array( 'system info', 'server info' ),
                isset( $item['href'] ) ? $item['href'] : ''
            );
        }

        return array_values( array_filter( $commands ) );
    }

    /**
     * Build a command palette command for a registered page.
     *
     * @param array<string, array<string, mixed>> $registered_pages Registered MainWP pages.
     * @param string                              $page_slug        Registered page slug.
     * @param array<int, string>                  $path_titles      Breadcrumb path labels.
     * @param array<int, string>                  $extra_keywords   Additional search keywords.
     * @param string                              $href             Optional command href override.
     *
     * @return array<string, mixed>|null Command configuration.
     */
    private function build_command_palette_registered_command( $registered_pages, $page_slug, $path_titles, $extra_keywords = array(), $href = '' ) {
        if ( empty( $registered_pages[ $page_slug ]['url'] ) ) {
            return null;
        }

        $path_titles = array_values(
            array_filter(
                array_map(
                    array( $this, 'clean_command_palette_label' ),
                    is_array( $path_titles ) ? $path_titles : array()
                )
            )
        );

        if ( empty( $path_titles ) ) {
            return null;
        }

        $keywords = $this->get_command_palette_keywords( $path_titles, $page_slug );

        foreach ( $extra_keywords as $keyword ) {
            $keyword = strtolower( $this->clean_command_palette_label( $keyword ) );

            if ( ! empty( $keyword ) ) {
                $keywords[] = $keyword;
            }
        }

        $label = sprintf( __( 'Go to: MainWP > %s', 'mainwp' ), implode( ' > ', $path_titles ) );
        $url   = ! empty( $href ) ? $this->normalize_command_palette_url( $href, $page_slug ) : $registered_pages[ $page_slug ]['url'];

        if ( empty( $url ) ) {
            return null;
        }

        return array(
            'name'        => 'mainwp_tab-' . $page_slug,
            'label'       => $label,
            'url'         => $url,
            'keywords'    => array_values( array_unique( array_filter( $keywords ) ) ),
            'searchLabel' => $label,
            '_depth'      => count( $path_titles ),
            '_page_slug'  => $page_slug,
        );
    }

    /**
     * Collect MainWP menu nodes from the runtime left-menu structures.
     *
     * @return array<int, array<string, string>> Menu nodes.
     */
    private function get_command_palette_menu_nodes() {
        global $mainwp_leftmenu, $mainwp_sub_leftmenu;

        $left_menu     = apply_filters( 'mainwp_main_menu', is_array( $mainwp_leftmenu ) ? $mainwp_leftmenu : array() );
        $sub_left_menu = apply_filters( 'mainwp_main_menu_submenu', is_array( $mainwp_sub_leftmenu ) ? $mainwp_sub_leftmenu : array() );
        $menu_nodes    = array();

        if ( ! empty( $left_menu['leftbar'] ) && is_array( $left_menu['leftbar'] ) ) {
            foreach ( $left_menu['leftbar'] as $item ) {
                $this->add_command_palette_menu_node(
                    $menu_nodes,
                    isset( $item[0] ) ? $item[0] : '',
                    isset( $item[2] ) ? $item[2] : '',
                    isset( $item[1] ) ? $item[1] : '',
                    'mainwp_tab'
                );
            }
        }

        foreach ( $left_menu as $parent_key => $items ) {
            if ( 'leftbar' === $parent_key || ! is_array( $items ) ) {
                continue;
            }

            foreach ( $items as $item ) {
                $this->add_command_palette_menu_node(
                    $menu_nodes,
                    isset( $item[0] ) ? $item[0] : '',
                    isset( $item[2] ) ? $item[2] : '',
                    isset( $item[1] ) ? $item[1] : '',
                    $parent_key
                );
            }
        }

        if ( ! empty( $sub_left_menu['leftbar'] ) && is_array( $sub_left_menu['leftbar'] ) ) {
            foreach ( $sub_left_menu['leftbar'] as $parent_key => $items ) {
                if ( ! is_array( $items ) ) {
                    continue;
                }

                foreach ( $items as $item ) {
                    $this->add_command_palette_menu_node(
                        $menu_nodes,
                        isset( $item[0] ) ? $item[0] : '',
                        isset( $item[2] ) ? $item[2] : '',
                        isset( $item[1] ) ? $item[1] : '',
                        $parent_key
                    );
                }
            }
        }

        foreach ( $sub_left_menu as $parent_key => $items ) {
            if ( 'leftbar' === $parent_key || ! is_array( $items ) ) {
                continue;
            }

            foreach ( $items as $item ) {
                $this->add_command_palette_menu_node(
                    $menu_nodes,
                    isset( $item[0] ) ? $item[0] : '',
                    isset( $item[1] ) ? $item[1] : '',
                    isset( $item[4] ) ? $item[4] : '',
                    $parent_key
                );
            }
        }

        return $menu_nodes;
    }

    /**
     * Add a single node to the command palette source list.
     *
     * @param array<int, array<string, string>> $menu_nodes Menu nodes.
     * @param string                            $title      Menu title.
     * @param string                            $href       Menu href.
     * @param string                            $slug       Menu slug.
     * @param string                            $parent_key Parent menu slug.
     */
    private function add_command_palette_menu_node( &$menu_nodes, $title, $href, $slug, $parent_key ) {
        // The Assistant menu item opens the chat panel and has no page, so a
        // command built from its slug would land on a WordPress error screen.
        // The palette script adds its own Open the MainWP Assistant command.
        if ( 'AssistantChatToggle' === $slug ) {
            return;
        }

        $title = $this->clean_command_palette_label( $title );
        $url   = $this->normalize_command_palette_url( $href, $slug );

        if ( empty( $title ) || empty( $url ) ) {
            return;
        }

        $page_slug = $this->get_command_palette_page_slug_from_url( $url );
        $slug_key  = ! empty( $slug ) ? (string) $slug : $page_slug;

        $menu_nodes[] = array(
            'id'        => md5( $parent_key . '|' . $slug_key . '|' . $url . '|' . $title ),
            'parent'    => (string) $parent_key,
            'page_slug' => $page_slug,
            'slug_key'  => $slug_key,
            'title'     => $title,
            'url'       => $url,
        );
    }

    /**
     * Build a node breadcrumb from the MainWP menu tree.
     *
     * @param array<string, string>                $node          Menu node.
     * @param array<string, array<string, string>> $nodes_by_id   Menu nodes indexed by id.
     * @param array<string, string>                $nodes_by_slug Menu nodes indexed by slug.
     *
     * @return array<int, string> Path titles.
     */
    private function get_command_palette_menu_path_titles( $node, $nodes_by_id, $nodes_by_slug ) {
        $titles     = array();
        $current    = $node;
        $seen_nodes = array();

        while ( is_array( $current ) && ! empty( $current['id'] ) && ! isset( $seen_nodes[ $current['id'] ] ) ) {
            $seen_nodes[ $current['id'] ] = true;

            if ( ! empty( $current['title'] ) ) {
                array_unshift( $titles, $current['title'] );
            }

            if ( empty( $current['parent'] ) || 'mainwp_tab' === $current['parent'] || ! isset( $nodes_by_slug[ $current['parent'] ] ) ) {
                break;
            }

            $parent_id = $nodes_by_slug[ $current['parent'] ];
            $current   = isset( $nodes_by_id[ $parent_id ] ) ? $nodes_by_id[ $parent_id ] : array();
        }

        return $titles;
    }

    /**
     * Build search keywords for a MainWP command.
     *
     * @param array<int, string> $path_titles Path titles.
     * @param string             $page_slug   Page slug.
     *
     * @return array<int, string> Search keywords.
     */
    private function get_command_palette_keywords( $path_titles, $page_slug = '' ) {
        $keywords = array( 'mainwp' );

        foreach ( $path_titles as $title ) {
            $keywords[] = strtolower( $title );
        }

        if ( ! empty( $page_slug ) ) {
            $keywords[] = strtolower( str_replace( array( '-', '_' ), ' ', $page_slug ) );
        }

        return array_values( array_unique( array_filter( $keywords ) ) );
    }

    /**
     * Normalize menu text into readable plain text.
     *
     * @param string $label Raw menu label.
     *
     * @return string Plain-text label.
     */
    private function clean_command_palette_label( $label ) {
        $label = wp_specialchars_decode( wp_strip_all_tags( (string) $label ), ENT_QUOTES );
        $label = preg_replace( '/\s+/', ' ', $label );

        return trim( (string) $label );
    }

    /**
     * Check whether a command-palette URL is already absolute.
     *
     * @param string $url URL to check.
     *
     * @return bool True when the URL is an absolute HTTP(S) URL.
     */
    private function is_command_palette_absolute_url( $url ) {
        return 1 === preg_match( '/^https?:\/\//i', trim( (string) $url ) );
    }

    /**
     * Normalize a MainWP menu href into a usable URL.
     *
     * @param string $href Menu href.
     * @param string $slug Menu slug.
     *
     * @return string Normalized URL.
     */
    private function normalize_command_palette_url( $href, $slug = '' ) {
        $href = trim( wp_specialchars_decode( (string) $href, ENT_QUOTES ) );
        $slug = trim( (string) $slug );

        if ( empty( $href ) || '#' === $href || 0 === strpos( $href, 'javascript:' ) ) {
            if ( empty( $slug ) || preg_match( '/\.php($|\?)/', $slug ) || $this->is_command_palette_absolute_url( $slug ) ) {
                return '';
            }

            return admin_url( 'admin.php?page=' . $slug );
        }

        if ( $this->is_command_palette_absolute_url( $href ) ) {
            return $href;
        }

        return admin_url( ltrim( $href, '/' ) );
    }

    /**
     * Extract a page slug from a menu URL when one exists.
     *
     * @param string $url Menu URL.
     *
     * @return string Page slug.
     */
    private function get_command_palette_page_slug_from_url( $url ) {
        $query = wp_parse_url( $url, PHP_URL_QUERY );

        if ( empty( $query ) ) {
            return '';
        }

        parse_str( $query, $query_args );

        if ( ! isset( $query_args['page'] ) ) {
            return '';
        }

        return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $query_args['page'] );
    }

    /**
     * Method admin_enqueue_scripts_fix_conflict()
     *
     * Enqueue Admin Scripts fix conflict.
     */
    public function admin_enqueue_scripts_fix_conflict() {
        if ( static::is_mainwp_pages() ) {
            // to fix conflict with the SVG Support plugin.
            remove_action( 'admin_enqueue_scripts', 'bodhi_svgs_admin_multiselect' );
        }
    }

    /**
     * Method admin_enqueue_styles()
     *
     * Enqueue all Mainwp Admin Styles.
     */
    public function admin_enqueue_styles() { //phpcs:ignore -- NOSONAR - complex.

        wp_enqueue_style( 'mainwp', MAINWP_PLUGIN_URL . 'assets/css/mainwp.css', array(), $this->current_version );
        wp_enqueue_style( 'mainwp-responsive-layouts', MAINWP_PLUGIN_URL . 'assets/css/mainwp-responsive-layouts.css', array(), $this->current_version );

        if ( isset( $_GET['hideall'] ) && 1 === (int) $_GET['hideall'] ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            remove_action( 'admin_footer', 'wp_admin_bar_render', 1000 );
        }

        /**
         * Current pagenow.
         *
         * @global string
         */
        global $pagenow;

        $load_cust_scripts = false;
        if ( is_plugin_active( 'mainwp-custom-post-types/mainwp-custom-post-types.php' ) && ( 'post-new.php' === $pagenow || 'post.php' === $pagenow ) ) {
            $load_cust_scripts = true;
        }

        if ( static::is_mainwp_pages() ) {
            wp_enqueue_style( 'mainwp-fonts', MAINWP_PLUGIN_URL . 'assets/css/mainwp-fonts.css', array(), $this->current_version );
            wp_enqueue_style( 'fomantic-ui', MAINWP_PLUGIN_URL . 'assets/js/fomantic-ui/fomantic-ui.css', array(), $this->current_version );
            wp_enqueue_style( 'mainwp-fomantic', MAINWP_PLUGIN_URL . 'assets/css/mainwp-fomantic.css', array(), $this->current_version );

            wp_enqueue_style( 'datatables', MAINWP_PLUGIN_URL . 'assets/js/datatables/dataTables.dataTables.min.css', array(), $this->current_version );
            wp_enqueue_style( 'datatables-semanticui', MAINWP_PLUGIN_URL . 'assets/js/datatables/dataTables.semanticui.min.css', array(), $this->current_version );
            wp_enqueue_style( 'datatables-select', MAINWP_PLUGIN_URL . 'assets/js/datatables/select.semanticui.min.css', array(), $this->current_version );
            wp_enqueue_style( 'datatables-add-ons', MAINWP_PLUGIN_URL . 'assets/js/datatables/add-ons.datatables.min.css', array(), $this->current_version );
            // to fix conflict layout.
            wp_enqueue_style( 'jquery-ui-style', MAINWP_PLUGIN_URL . 'assets/css/1.11.1/jquery-ui.min.css', array(), '1.11.1' );

            // load custom MainWP theme.
            $selected_theme = MainWP_Settings::get_instance()->get_selected_theme();
            if ( ! empty( $selected_theme ) ) {
                if ( 'system' === $selected_theme ) {
                    static::enqueue_system_theme();
                } elseif ( 'dark' === $selected_theme ) {
                    wp_enqueue_style( 'mainwp-custom-dashboard-extension-dark-theme', MAINWP_PLUGIN_URL . 'assets/css/themes/mainwp-dark-theme.css', array(), $this->current_version );
                } elseif ( 'wpadmin' === $selected_theme ) {
                    wp_enqueue_style( 'mainwp-custom-dashboard-extension-wp-admin-theme', MAINWP_PLUGIN_URL . 'assets/css/themes/mainwp-wpadmin-theme.css', array(), $this->current_version );
                } elseif ( 'minimalistic' === $selected_theme ) {
                    wp_enqueue_style( 'mainwp-custom-dashboard-extension-minimalistic-theme', MAINWP_PLUGIN_URL . 'assets/css/themes/mainwp-minimalistic-theme.css', array(), $this->current_version );
                } elseif ( 'default-2024' === $selected_theme ) {
                    wp_enqueue_style( 'mainwp-custom-dashboard-extension-default-2024-theme', MAINWP_PLUGIN_URL . 'assets/css/themes/mainwp-default-2024-theme.css', array(), $this->current_version );
                } elseif ( 'default' === $selected_theme ) {
                    wp_enqueue_style( 'mainwp-custom-dashboard-extension-default-theme', MAINWP_PLUGIN_URL . 'assets/css/themes/mainwp-default-theme.css', array(), $this->current_version );
                } elseif ( 'default-dark' === $selected_theme ) {
                    wp_enqueue_style( 'mainwp-custom-dashboard-extension-default-dark-theme', MAINWP_PLUGIN_URL . 'assets/css/themes/mainwp-default-dark-theme.css', array(), $this->current_version );
                } else {
                    $dirs             = MainWP_Settings::get_instance()->get_custom_theme_folder();
                    $custom_theme_url = $dirs[1];
                    wp_enqueue_style( 'mainwp-custom-dashboard-theme', $custom_theme_url . $selected_theme, array(), $this->current_version );
                }
            }
        }

        if ( $load_cust_scripts ) {
            wp_enqueue_style( 'mainwp-fonts', MAINWP_PLUGIN_URL . 'assets/css/mainwp-fonts.css', array(), $this->current_version );
            wp_enqueue_style( 'fomantic-ui', MAINWP_PLUGIN_URL . 'assets/js/fomantic-ui/fomantic-ui.css', array(), $this->current_version );
        }
    }

    /**
     * Load the built-in themes according to the browser's appearance preference.
     */
    public static function enqueue_system_theme() {
        wp_enqueue_style( 'mainwp-custom-dashboard-extension-default-theme', MAINWP_PLUGIN_URL . 'assets/css/themes/mainwp-default-theme.css', array(), MAINWP_VERSION, '(prefers-color-scheme: light)' );
        wp_enqueue_style( 'mainwp-custom-dashboard-extension-default-dark-theme', MAINWP_PLUGIN_URL . 'assets/css/themes/mainwp-default-dark-theme.css', array(), MAINWP_VERSION, '(prefers-color-scheme: dark)' );
    }

    /**
     * Method admin_menu()
     *
     * Add Bulk Post/Pages menue.
     */
    public function admin_menu() {

        /**
         * Admin menu array.
         *
         * @global object
         */
        global $menu;

        foreach ( $menu as $k => $item ) {
            if ( 'edit.php?post_type=bulkpost' === $item[2] || 'edit.php?post_type=bulkpage' === $item[2] ) {
                unset( $menu[ $k ] );
            }
        }
    }

    /**
     * Method enqueue_postbox_scripts()
     *
     * Enqueue postbox scripts.
     */
    public static function enqueue_postbox_scripts() {
        wp_enqueue_script( 'common' );
        wp_enqueue_script( 'wp-lists' );
        wp_enqueue_script( 'postbox' );
    }

    /**
     * Method admin_footer()
     *
     * Create MainWP admin footer.
     *
     * @uses \MainWP\Dashboard\MainWP_DB::get_website_by_id()
     * @uses \MainWP\Dashboard\MainWP_DB::query()
     * @uses \MainWP\Dashboard\MainWP_DB::get_sql_websites_for_current_user()
     * @uses \MainWP\Dashboard\MainWP_DB::free_result()
     * @uses \MainWP\Dashboard\MainWP_Menu::init_subpages_menu()
     * @uses \MainWP\Dashboard\MainWP_System_Utility::get_current_wpid()
     * @uses \MainWP\Dashboard\MainWP_System_View::render_footer_content()
     * @uses \MainWP\Dashboard\MainWP_System_View::admin_footer()
     */
    public function admin_footer() { //phpcs:ignore -- NOSONAR - complex.
        if ( ! static::is_mainwp_pages() ) {
            $sites_count = MainWP_DB::instance()->get_websites_count();
            if ( empty( $sites_count ) ) {
                ?>
                <script type="text/javascript">
                    jQuery( function($){
                        $( 'li#toplevel_page_mainwp_tab .wp-submenu-wrap .wp-submenu-head' ).after('<li style="background: #FFD300 !important;" class="wp-first-item"><a href="admin.php?page=mainwp-setup" style="color: #000 !important;" class="wp-first-item"><?php esc_html_e( 'Quick Setup', 'mainwp' ); ?></a></li>');
                    });
                </script>
                <?php
            }
            return;
        }

        ?>
        <div class="ui large modal" id="mainwp-response-data-modal">
        <i class="close icon"></i>
            <div class="header"><?php esc_html_e( 'Child Site Response', 'mainwp' ); ?></div>
            <div class="content">
                <div class="ui info message"><?php esc_html_e( 'This is the readable child/HTTP response from the failed request (status, headers snippet, and body when it is safe to show). Copy it for support if needed.', 'mainwp' ); ?>
                </div>
            </div>
            <div class="scrolling content content-response" contenteditable="true"></div>
            <div class="actions">
                <button class="ui green button mainwp-response-copy-button"><?php esc_html_e( 'Copy Response', 'mainwp' ); ?></button>
            </div>
        </div>
        <div id="mainwp-response-data-container" resp-data=""></div>
        <?php

        if ( isset( $_GET['hideall'] ) && 1 === (int) $_GET['hideall'] ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            return;
        }
        $current_wpid = MainWP_System_Utility::get_current_wpid();
        if ( $current_wpid ) {
            $website  = MainWP_DB::instance()->get_website_by_id( $current_wpid );
            $websites = array( $website );
        } else {
            $is_staging = 'no';
            if ( isset( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                if ( ( 'managesites' === $_GET['page'] ) && ! isset( $_GET['id'] ) && ! isset( $_GET['do'] ) && ! isset( $_GET['dashboard'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                    $group_ids = get_user_option( 'mainwp_managesites_filter_group' );
                    if ( ! empty( $group_ids ) ) {
                        $group_ids = explode( ',', $group_ids ); // convert to array.
                    }
                } elseif ( 'UpdatesManage' === $_GET['page'] || 'mainwp_tab' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                    $staging_enabled = is_plugin_active( 'mainwp-staging-extension/mainwp-staging-extension.php' ) ? true : false;
                    if ( $staging_enabled ) {
                        $staging_view = MainWP_System_Utility::get_select_staging_view_sites();
                        if ( 'staging' === $staging_view ) {
                            $is_staging = 'yes';
                        }
                    }
                }
            }
            $websites = MainWP_DB::instance()->query( MainWP_DB::instance()->get_sql_websites_for_current_user( false, null, 'wp_sync.dtsSync DESC, wp.url ASC', false, false, null, false, array(), $is_staging ) );
        }

        MainWP_System_View::render_footer_content( $websites, $current_wpid );
        if ( empty( $current_wpid ) ) {
            MainWP_DB::free_result( $websites );
        }

        MainWP_System_View::admin_footer();
        MainWP_System_View::render_plugins_install_check();

        /**
         * MainWP disabled menu items array.
         *
         * @global object
         */
        global $_mainwp_disable_menus_items;

        $_mainwp_disable_menus_items = apply_filters( 'mainwp_all_disablemenuitems', $_mainwp_disable_menus_items );
    }

    /**
     * Method activated_check()
     *
     * Activated check.
     *
     * @uses \MainWP\Dashboard\MainWP_Deprecated_Hooks::maybe_handle_deprecated_hook()
     */
    public function activated_check() {
        MainWP_Deprecated_Hooks::maybe_handle_deprecated_hook();
        return $this->get_version();
    }

    /**
     * Method activation()
     *
     * Activate MainWP.
     *
     * @uses \MainWP\Dashboard\MainWP_Install::install()
     * @uses  \MainWP\Dashboard\MainWP_Utility::update_option()
     */
    public function activation() {
        $this->update_install();
        MainWP_Utility::update_option( 'mainwp_activated', 'yes' );
    }

    /**
     * Method deactivation()
     *
     * Deactivate MainWP.
     */
    public function deactivation() {
        update_option( 'mainwp_extensions_all_activation_cached', '' );
        $useWPCron = ( get_option( 'mainwp_wp_cron' ) === false ) || ( (int) get_option( 'mainwp_wp_cron' ) === 1 );
        if ( $useWPCron ) {
            $jobs = MainWP_System_Cron_Jobs::instance()->get_cron_jobs();
            if ( $jobs ) {
                foreach ( $jobs as $job => $recu ) {
                    wp_clear_scheduled_hook( $job );
                }
            }
        }
    }

    /**
     * Method update_install()
     *
     * Update MainWP.
     *
     * @uses \MainWP\Dashboard\MainWP_Install::install()
     */
    public function update_install() {
        MainWP_DB_Client::instance();
        MainWP_DB_Knowledge::instance();
        MainWP_DB_Site_Actions::instance();
        MainWP_DB_Assistant::instance();
        MainWP_Install::instance()->install();
        $this->check_to_updates();
    }


    /**
     * Method check_to_updates()
     *
     * To check updates options.
     */
    public function check_to_updates() {
        $update_ver  = get_option( 'mainwp_update_check_version', false );
        $current_ver = $this->check_ver_update;

        if ( false === $update_ver && version_compare( $current_ver, '0.0.1', '=' ) ) {
            // to update new saving format.
            MainWP_Api_Manager_Key::instance()->get_decrypt_master_api_key();
            MainWP_Utility::update_option( 'mainwp_update_check_version', $current_ver );
        }
    }

    /**
     * Method is_single_user()
     *
     * Check if single user environment.
     *
     * Single-user has always been the only shipped mode: no released code
     * computes this, and per-user access on multi-user installs is the Team
     * Control extension's capability model (the mainwp_currentusercan and
     * mainwp_currentuserallowedaccesssites filters), which ignores the row
     * userid ownership layer entirely. The filter below arms that legacy
     * ownership layer (can_edit_website(), userid-scoped site and group
     * queries, per-user extension prefs) for installs that opt in; do not
     * flip it on an install using Team Control, or the two models will
     * contradict each other. The private-files root (encryption keys under
     * pk/) is deliberately NOT keyed off this - see
     * MainWP_System_Utility::get_mainwp_specific_dir().
     *
     * @return boolean true|false.
     */
    public function is_single_user() {
        /**
         * Filter whether the Dashboard runs in single-user mode.
         *
         * Default true, matching every shipped release. Returning false
         * arms the legacy per-user ownership gates dashboard-wide.
         *
         * @param bool $single_user Whether the Dashboard is single-user.
         */
        return (bool) apply_filters( 'mainwp_is_single_user', true );
    }

    /**
     * Method is_multi_user()
     *
     * Check if multi user environment.
     *
     * @return boolean true|false.
     */
    public function is_multi_user() {
        return ! $this->is_single_user();
    }

    /**
     * Method get_plugin_slug()
     *
     * Get MainWP Plugin Slug.
     */
    public function get_plugin_slug() {
        return $this->plugin_slug;
    }

    /**
     * Method get_selected_sites_with_allowed_sites.
     *
     * @param  array $selected_ids Selected sites IDs.
     * @param  array $current_sites_ids Current sites IDs.
     * @return array Not selected but in not allowed sites in current sites ids, and selected sites ids.
     */
    public static function get_selected_sites_with_allowed_sites( $selected_ids, $current_sites_ids = array() ) {

        $allowed_ids = MainWP_Abilities_Util::get_allowed_site_ids();
        if ( 'all' === $allowed_ids ) {
            return $selected_ids;
        }

        if ( ! is_array( $selected_ids ) ) {
            $selected_ids = array();
        }

        if ( ! is_array( $current_sites_ids ) ) {
            $current_sites_ids = array();
        }

        $client_site_ids = array();
        foreach ( $selected_ids as $id ) {
            if ( in_array( $id, $allowed_ids ) ) {
                $client_site_ids[] = $id;
            }
        }

        // Team Control cannot remove memberships outside the caller's site grants.
        foreach ( $current_sites_ids as $id ) {
            if ( ! in_array( $id, $selected_ids ) && ! in_array( $id, $allowed_ids ) ) { // If it’s not in the allowed site IDs, the user is not permitted to change it.
                $client_site_ids[] = $id; // keep this site ids.
            }
        }
        return $client_site_ids;
    }
}
