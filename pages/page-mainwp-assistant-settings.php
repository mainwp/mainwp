<?php
/**
 * MainWP Assistant Settings Page
 *
 * This Class handles building/Managing the
 * Assistant Settings MainWP Dashboard Page.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Class MainWP_Assistant_Settings
 *
 * @package MainWP\Dashboard
 */
class MainWP_Assistant_Settings { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

    /**
     * Protected static variable to hold the single instance of the class.
     *
     * @var mixed Default null
     */
    protected static $instance = null;

    /**
     * Get Class Name
     *
     * @return string __CLASS__
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
     * Instantiate Hooks for the Assistant Settings Page.
     */
    public static function init() {
        /**
         * Renders the Assistant Settings page header via the 'mainwp-pageheader-assistant-settings' action.
         */
        add_action( 'mainwp-pageheader-assistant-settings', array( static::get_class_name(), 'render_header' ) );

        /**
         * Renders the Assistant Settings page footer via the 'mainwp-pagefooter-assistant-settings' action.
         */
        add_action( 'mainwp-pagefooter-assistant-settings', array( static::get_class_name(), 'render_footer' ) );

        add_action( 'mainwp_help_sidebar_content', array( static::get_class_name(), 'mainwp_help_content' ) );

        // Initialize AJAX handlers.
        static::init_ajax_handlers();
    }

    /**
     * Initialize AJAX handlers for settings page.
     */
    public static function init_ajax_handlers() {
        add_action( 'wp_ajax_mainwp_assistant_save_model_preference', array( static::get_class_name(), 'ajax_save_model_preference' ) );
        add_action( 'wp_ajax_mainwp_assistant_save_access_mode', array( static::get_class_name(), 'ajax_save_access_mode' ) );
        add_action( 'wp_ajax_mainwp_assistant_save_confirmation_level', array( static::get_class_name(), 'ajax_save_confirmation_level' ) );
        add_action( 'wp_ajax_mainwp_assistant_save_protected_tags', array( static::get_class_name(), 'ajax_save_protected_tags' ) );
        add_action( 'wp_ajax_mainwp_assistant_save_global_toggle', array( static::get_class_name(), 'ajax_save_global_toggle' ) );
        add_action( 'wp_ajax_mainwp_assistant_save_daily_budget', array( static::get_class_name(), 'ajax_save_daily_budget' ) );
        add_action( 'wp_ajax_mainwp_assistant_save_user_daily_budget', array( static::get_class_name(), 'ajax_save_user_daily_budget' ) );
        add_action( 'wp_ajax_mainwp_assistant_save_history_settings', array( static::get_class_name(), 'ajax_save_history_settings' ) );
        add_action( 'wp_ajax_mainwp_assistant_clear_history', array( static::get_class_name(), 'ajax_clear_history' ) );
        add_action( 'wp_ajax_mainwp_assistant_export_history', array( static::get_class_name(), 'ajax_export_history' ) );
        // Consent management.
        add_action( 'wp_ajax_mainwp_assistant_grant_consent', array( static::get_class_name(), 'ajax_grant_consent' ) );
        add_action( 'wp_ajax_mainwp_assistant_revoke_consent', array( static::get_class_name(), 'ajax_revoke_consent' ) );
        // Note: mainwp_assistant_dismiss_disclosure is handled by MainWP_Assistant class
        // to align with the chat JS nonce system (mainwp_secure_data).
    }

    /**
     * Instantiate the Assistant Settings Menu.
     */
    public static function init_menu() {
        $menu_class = MainWP_System::is_assistant_enabled() ? '' : 'assistant-disabled';

        add_submenu_page(
            'mainwp_tab',
            esc_html__( 'Assistant Settings', 'mainwp' ),
            '<span id="mainwp-assistant-settings"' . ( ! empty( $menu_class ) ? ' class="' . esc_attr( $menu_class ) . '"' : '' ) . '>' . esc_html__( 'Assistant Settings', 'mainwp' ) . '</span>',
            'read',
            'AssistantSettings',
            array(
                static::get_class_name(),
                'render',
            )
        );

        // Files the page under the AI nav item, which links to it from its
        // landing cards. Users who cannot see that item still open the page
        // from the Assistant panel.
        MainWP_Menu::add_left_menu(
            array(
                'title'                => esc_html__( 'Assistant Settings', 'mainwp' ),
                'parent_key'           => 'ai',
                'slug'                 => 'AssistantSettings',
                'href'                 => 'admin.php?page=AssistantSettings',
                'leftsub_order_level2' => 0,
            ),
            2
        );
    }

    /**
     * Render Page Header.
     *
     * @param string $shownPage    The page slug shown at this moment.
     * @param string $current_tab  Currently active tab slug. Defaults to reading from $_GET['tab'].
     * @param bool   $history_only Whether to hide site-wide settings tabs.
     */
    public static function render_header( $shownPage = '', $current_tab = '', $history_only = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Standard signature for page headers.
        $params = array(
            'title' => esc_html__( 'Assistant Settings', 'mainwp' ),
        );

        MainWP_UI::render_top_header( $params );

        if ( empty( $current_tab ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab display only.
            $current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'providers';
        }

        if ( $history_only && ! in_array( $current_tab, array( 'providers', 'history' ), true ) ) {
            $current_tab = 'providers';
        }

        $renderItems = array();

        if ( ! $history_only || MainWP_Assistant::current_user_can_use_assistant() ) {
            $renderItems[] = array(
                'title'  => esc_html__( 'AI Connection', 'mainwp' ),
                'href'   => add_query_arg(
                    array(
                        'page' => 'AssistantSettings',
                        'tab'  => 'providers',
                    ),
                    'admin.php'
                ),
                'active' => ( 'providers' === $current_tab ) ? true : false,
            );
        }

        if ( ! $history_only ) {
            $renderItems[] = array(
                'title'  => esc_html__( 'AI Access Policies', 'mainwp' ),
                'href'   => add_query_arg(
                    array(
                        'page' => 'AssistantSettings',
                        'tab'  => 'policies',
                    ),
                    'admin.php'
                ),
                'active' => ( 'policies' === $current_tab ) ? true : false,
            );
        }

        $renderItems[] = array(
            'title'  => esc_html__( 'History & Privacy', 'mainwp' ),
            'href'   => add_query_arg(
                array(
                    'page' => 'AssistantSettings',
                    'tab'  => 'history',
                ),
                'admin.php'
            ),
            'active' => ( 'history' === $current_tab ) ? true : false,
        );

        MainWP_UI::render_page_navigation( $renderItems );
    }

    /**
     * Close the HTML container.
     */
    public static function render_footer() {
        echo '</div>';
    }

    /**
     * Render the Assistant Settings page.
     */
    public static function render() {
        $can_manage_settings = \mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' );

        if ( ! $can_manage_settings && ! \mainwp_current_user_can( 'dashboard', 'access_global_dashboard' ) ) {
            \mainwp_do_not_have_permissions( esc_html__( 'manage assistant settings', 'mainwp' ) );
            return;
        }

        // Personal connections must not grant access to site-wide configuration.
        $personal_only = ! $can_manage_settings;

        // Check if assistant is globally disabled.
        // Allow access to Policies tab for admins so they can re-enable the assistant.
        // Keep History available so users can still manage stored conversation data.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab display only.
        $current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'providers';
        $can_manage  = current_user_can( 'manage_options' ) && $can_manage_settings;

        if ( $personal_only && ! in_array( $current_tab, array( 'providers', 'history' ), true ) ) {
            $current_tab = 'providers';
        }

        if ( $personal_only && ! MainWP_Assistant::current_user_can_use_assistant() ) {
            $current_tab = 'history';
        }

        $allow_when_disabled = 'history' === $current_tab || ( 'policies' === $current_tab && $can_manage );

        if ( ! MainWP_System::is_assistant_enabled() && ! $allow_when_disabled ) {
            static::render_header( '', $current_tab, $personal_only );
            MainWP_Assistant_Policies::render_disabled_state( 'settings' );
            static::render_footer();
            return;
        }

        static::render_header( '', $current_tab, $personal_only );

        // Enqueue assets.
        static::enqueue_assets();

        // Render the appropriate tab content.
        switch ( $current_tab ) {
            case 'policies':
                static::render_policies_tab();
                break;
            case 'history':
                static::render_history_tab();
                break;
            case 'providers':
            default:
                static::render_providers_tab();
                break;
        }

        // Render modals (always include for JS access).
        static::render_modals();

        static::render_footer();
    }

    /**
     * Enqueue JavaScript and CSS assets.
     */
    private static function enqueue_assets() {
        // The panel path registers these on MainWP pages; the settings page
        // enqueues them itself because the assistant can be globally disabled
        // there while this tab still needs the wizard.
        if ( ! wp_script_is( 'mainwp-assistant-chat-logic', 'registered' ) ) {
            wp_register_script(
                'mainwp-assistant-chat-logic',
                MAINWP_PLUGIN_URL . 'assets/js/mainwp-assistant-chat-logic.js',
                array(),
                MAINWP_VERSION,
                true
            );
        }

        wp_enqueue_script(
            'mainwp-assistant-settings',
            MAINWP_PLUGIN_URL . 'assets/js/mainwp-assistant-settings.js',
            array( 'jquery', 'mainwp', 'mainwp-assistant-chat-logic' ),
            MAINWP_VERSION,
            true
        );

        wp_localize_script(
            'mainwp-assistant-settings',
            'mainwpAssistantSettings',
            array(
                'nonce'           => wp_create_nonce( 'mainwp_assistant_settings' ),
                'consentNonce'    => wp_create_nonce( 'mainwp_assistant_consent' ),
                'ajaxurl'         => admin_url( 'admin-ajax.php' ),
                'strings'         => array(
                    'confirmClearHistory'        => __( 'Are you sure you want to clear all conversation history? This action cannot be undone.', 'mainwp' ),
                    'confirmDisableGlobal'       => __( 'This will disable the assistant for all users. Continue?', 'mainwp' ),
                    'saving'                     => __( 'Saving...', 'mainwp' ),
                    'loading'                    => __( 'Loading...', 'mainwp' ),
                    'request_failed'             => __( 'Request failed. Try again.', 'mainwp' ),
                    'request_timeout'            => __( 'Request timed out. Please check your connection and try again.', 'mainwp' ),
                    'network_error'              => __( 'Network error. Please check your internet connection.', 'mainwp' ),
                    'access_mode_saved'          => __( 'Access mode saved.', 'mainwp' ),
                    'access_mode_failed'         => __( 'Failed to save access mode.', 'mainwp' ),
                    'confirmation_level_saved'   => __( 'Confirmation level saved.', 'mainwp' ),
                    'confirmation_level_failed'  => __( 'Failed to save confirmation level.', 'mainwp' ),
                    'protected_tags_saved'       => __( 'Protected tags saved.', 'mainwp' ),
                    'protected_tags_failed'      => __( 'Failed to save protected tags.', 'mainwp' ),
                    'assistant_enabled'          => __( 'Assistant enabled.', 'mainwp' ),
                    'assistant_disabled'         => __( 'Assistant disabled.', 'mainwp' ),
                    'label_enabled_all_users'    => __( 'Assistant is enabled for all users', 'mainwp' ),
                    'label_disabled_all_users'   => __( 'Assistant is disabled for all users', 'mainwp' ),
                    'save_setting_failed'        => __( 'Failed to save setting.', 'mainwp' ),
                    'daily_budget_saved'         => __( 'Daily spend limit saved.', 'mainwp' ),
                    'daily_budget_unlimited'     => __( 'Saved. No daily spend limit is set.', 'mainwp' ),
                    'daily_budget_failed'        => __( 'Failed to save the daily spend limit.', 'mainwp' ),
                    /* translators: 1: estimated spend today in USD, 2: configured daily limit in USD */
                    'used_today_with_limit'      => __( '$%1$s of $%2$s used today. Costs are estimated from token counts and published provider prices.', 'mainwp' ),
                    /* translators: %s: estimated spend today in USD */
                    'used_today_no_limit'        => __( '$%s used today. Costs are estimated from token counts and published provider prices. No limit is set.', 'mainwp' ),
                    'used_today_unreadable'      => __( 'Today\'s spend could not be read. While a limit is set, AI requests stay blocked until the total is readable again.', 'mainwp' ),
                    'user_budget_saved'          => __( 'Per-user spend limit saved.', 'mainwp' ),
                    'user_budget_unlimited'      => __( 'Saved. No per-user spend limit is set.', 'mainwp' ),
                    'user_budget_failed'         => __( 'Failed to save the per-user spend limit.', 'mainwp' ),
                    /* translators: 1: estimated user spend today in USD, 2: configured per-user daily limit in USD */
                    'user_used_today_with_limit' => __( '$%1$s of your $%2$s used today. Costs are estimated from token counts and published provider prices.', 'mainwp' ),
                    /* translators: %s: estimated user spend today in USD */
                    'user_used_today_no_limit'   => __( '$%s of your spend used today. Costs are estimated from token counts and published provider prices. No per-user limit is set.', 'mainwp' ),
                    'user_used_today_unreadable' => __( 'Your spend today could not be read. While a limit is set, your AI requests stay blocked until the total is readable again.', 'mainwp' ),
                    'history_settings_saved'     => __( 'History settings saved.', 'mainwp' ),
                    'history_settings_failed'    => __( 'Failed to save history settings.', 'mainwp' ),
                    'history_exported'           => __( 'History exported.', 'mainwp' ),
                    'export_failed'              => __( 'Failed to export history.', 'mainwp' ),
                    'export_timeout'             => __( 'Export timed out. A large history can take more than one attempt, so try again.', 'mainwp' ),
                    'export_incomplete'          => __( 'Export incomplete. The downloaded file holds every conversation except the ones that could not be read.', 'mainwp' ),
                    'clear_history_failed'       => __( 'Failed to clear history.', 'mainwp' ),
                    'revoke_consent_failed'      => __( 'Failed to revoke consent.', 'mainwp' ),
                    'grant_consent_failed'       => __( 'Failed to enable assistant features.', 'mainwp' ),
                    /* translators: %d: number of days */
                    'retention_adjusted'         => __( 'Saved. Retention adjusted to %d days.', 'mainwp' ),
                    /* translators: %s: model identifier */
                    'model_preference_set'       => __( 'Saved. The assistant will request %s first.', 'mainwp' ),
                    'model_preference_cleared'   => __( 'Saved. WordPress will choose the model.', 'mainwp' ),
                    /* translators: 1: model identifier, 2: comma separated provider names */
                    'model_preference_provider'  => __( 'Saved. Sites mode will request %1$s (%2$s) first.', 'mainwp' ),
                    /* translators: %s: model identifier */
                    'model_preference_unknown'   => __( 'Saved. %s could not be checked against your configured providers.', 'mainwp' ),
                    // The wizard script reads its strings from whichever object
                    // the page registered, so this tab carries the same keys the
                    // chat panel puts in mainwpMintlifyChatL10n.
                ) + MainWP_Assistant_Onboarding::wizard_l10n(),
                'onboardingNonce' => wp_create_nonce( MainWP_Assistant_Onboarding::NONCE_ACTION ),
            ) + MainWP_System::assistant_chatgpt_l10n()
        );

        wp_enqueue_style(
            'mainwp-assistant-settings',
            MAINWP_PLUGIN_URL . 'assets/css/mainwp-assistant-settings.css',
            array(),
            MAINWP_VERSION
        );

        wp_enqueue_script(
            'mainwp-assistant-onboarding',
            MAINWP_PLUGIN_URL . 'assets/js/mainwp-assistant-onboarding.js',
            array( 'jquery', 'mainwp', 'mainwp-assistant-chat-logic' ),
            MAINWP_VERSION,
            true
        );

        wp_enqueue_style(
            'mainwp-assistant-onboarding',
            MAINWP_PLUGIN_URL . 'assets/css/mainwp-assistant-onboarding.css',
            array(),
            MAINWP_VERSION
        );
    }

    /**
     * Render the AI Connection tab content.
     *
     * Sites mode runs on the WordPress core AI Client; API keys are managed on
     * the core Settings > Connectors screen, so this tab shows connection
     * status and the optional site-wide model preference.
     */
    private static function render_providers_tab() { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh -- NOSONAR - one state table, evaluated in order.
        ?>
        <div id="mainwp-assistant-connection-tab" class="ui padded segment">
            <h2 class="ui dividing header"><?php esc_html_e( 'AI Connection', 'mainwp' ); ?></h2>
            <?php if ( MainWP_Assistant::current_user_can_use_assistant() ) : ?>
                <div id="mainwp-assistant-chatgpt-card" aria-live="polite"></div>
            <?php endif; ?>
        <?php
        if ( ! \mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' ) ) {
            echo '</div>';
            return;
        }

        $wp_version       = get_bloginfo( 'version' );
        $has_ai_client    = function_exists( 'wp_supports_ai' );
        $supports_ai      = $has_ai_client && wp_supports_ai();
        $agent_available  = MainWP_Assistant::agent_mode_available();
        $provider_failure = MainWP_Assistant::get_provider_failure();
        $model_preference = get_option( 'mainwp_assistant_model_preference', '' );
        $plan_connected   = $supports_ai && MainWP_Assistant_ChatGPT_Plan::get_status( get_current_user_id() )['connected'];

        // Google connection state, read in the same order the endpoints use:
        // version, AI support, contract, provider, key, agent.
        $contract      = MainWP_Assistant_Onboarding::google_connector_contract();
        $contract_ok   = ! is_wp_error( $contract );
        $google_active = $contract_ok && is_callable( $contract['is_active'] ) && (bool) call_user_func( $contract['is_active'] );
        $key_source    = $contract_ok ? MainWP_Assistant_Onboarding::key_source( $contract ) : 'none';
        $key_masked    = 'database' === $key_source
            ? MainWP_Assistant_Onboarding::mask_key( (string) get_option( $contract['setting_name'], '' ) )
            : '';
        $can_connect   = MainWP_Assistant_Onboarding::user_can_run_wizard();
        $google_failed = $provider_failure && 'google' === $provider_failure['provider'];

        /**
         * Filter the URL of the WordPress Connectors settings screen.
         *
         * @since 6.0.0
         *
         * @param string $url Connectors screen URL.
         */
        $connectors_url = apply_filters( 'mainwp_assistant_connectors_url', admin_url( 'options-connectors.php' ) );
        ?>
        <div class="mainwp-assistant-site-connection" data-plan-connected="<?php echo $plan_connected ? '1' : '0'; ?>">
            <h3><?php esc_html_e( 'Use an API key', 'mainwp' ); ?></h3>
            <p class="mainwp-assistant-connect-note"><?php esc_html_e( 'Connect a free Google Gemini key, or add an OpenAI or Anthropic key on the Connectors screen. Requests run on your own provider account.', 'mainwp' ); ?></p>
            <?php if ( $plan_connected ) : ?>
                <div class="ui visible info blue message">
                    <?php esc_html_e( 'Your Sites mode requests use your ChatGPT plan while it is connected, never this key. People on this Dashboard without a connected plan use the key. Disconnect your plan to use the key yourself.', 'mainwp' ); ?>
                </div>
            <?php endif; ?>

            <div class="ui form">
                <div class="ui grid field">
                    <label class="six wide column middle aligned"><?php esc_html_e( 'WordPress version', 'mainwp' ); ?></label>
                    <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'The WordPress version running this dashboard. Sites mode needs WordPress 7.0 or newer.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                        <?php echo esc_html( $wp_version ); ?>
                    </div>
                </div>
                <div class="ui grid field">
                    <label class="six wide column middle aligned"><?php esc_html_e( 'AI Client', 'mainwp' ); ?></label>
                    <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'The WordPress core AI Client that the assistant sends provider requests through.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                        <?php if ( $supports_ai ) : ?>
                            <i class="green check circle icon"></i><?php esc_html_e( 'Available', 'mainwp' ); ?>
                        <?php elseif ( $has_ai_client ) : ?>
                            <i class="yellow exclamation circle icon"></i><?php esc_html_e( 'Disabled on this site', 'mainwp' ); ?>
                        <?php else : ?>
                            <i class="red times circle icon"></i><?php esc_html_e( 'Requires WordPress 7.0 or newer', 'mainwp' ); ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="ui grid field">
                    <label class="six wide column middle aligned"><?php esc_html_e( 'Sites mode', 'mainwp' ); ?></label>
                    <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Whether the assistant can run actions on your sites. Sites mode needs your ChatGPT plan or an AI provider with a working API key.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                        <?php if ( $plan_connected ) : ?>
                            <i class="green check circle icon"></i><?php esc_html_e( 'Ready. Uses your ChatGPT plan.', 'mainwp' ); ?>
                            <?php if ( $provider_failure && ! $google_failed ) : ?>
                                <div class="ui visible yellow message">
                                    <?php
                                    printf(
                                        /* translators: %s: date and time of the failed request. */
                                        esc_html__( 'The last request that used an API key failed (%s). Your ChatGPT plan is not affected.', 'mainwp' ),
                                        esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $provider_failure['time'] ) )
                                    );
                                    ?>
                                </div>
                            <?php endif; ?>
                        <?php elseif ( $google_failed ) : ?>
                            <i class="yellow exclamation circle icon"></i><?php esc_html_e( 'Not working. See Google Gemini below.', 'mainwp' ); ?>
                        <?php elseif ( $provider_failure ) : ?>
                            <div class="ui visible yellow message"><?php echo esc_html( $provider_failure['message'] ); ?></div>
                        <?php elseif ( $agent_available ) : ?>
                            <i class="green check circle icon"></i><?php esc_html_e( 'Ready. Found a configured AI provider.', 'mainwp' ); ?>
                        <?php else : ?>
                            <div class="ui blue message">
                                <?php esc_html_e( 'No configured AI provider found. Install a provider plugin and add its API key on the Connectors screen.', 'mainwp' ); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="ui grid field">
                    <label class="six wide column middle aligned"><?php esc_html_e( 'Google Gemini', 'mainwp' ); ?></label>
                    <div class="ten wide column">
                        <?php if ( $google_failed ) : ?>
                            <div class="ui visible yellow message"><?php echo esc_html( $provider_failure['message'] ); ?></div>
                        <?php endif; ?>
                        <?php if ( ! $has_ai_client ) : ?>
                            <div class="ui visible info blue message">
                                <?php
                                printf(
                                    /* translators: %s: WordPress version running this dashboard. */
                                    esc_html__( 'Sites mode needs WordPress 7.0 or newer. This dashboard is running %s. Docs mode works on this version.', 'mainwp' ),
                                    esc_html( $wp_version )
                                );
                                ?>
                            </div>
                        <?php elseif ( ! $supports_ai ) : ?>
                            <div class="ui visible info blue message">
                                <?php esc_html_e( 'AI features are turned off on this site, so WordPress registers no AI connectors. Sites mode stays off until they are turned back on.', 'mainwp' ); ?>
                            </div>
                        <?php elseif ( ! $can_connect ) : ?>
                            <div class="ui visible info blue message">
                                <?php esc_html_e( 'Ask an administrator to connect an AI provider.', 'mainwp' ); ?>
                            </div>
                        <?php elseif ( ! $contract_ok ) : ?>
                            <div class="ui visible yellow message">
                                <?php esc_html_e( 'This WordPress version describes AI connectors differently than this dashboard expects, so the wizard cannot store a key for you. Add it on the Connectors screen instead.', 'mainwp' ); ?>
                            </div>
                            <a class="ui basic button" href="<?php echo esc_url( $connectors_url ); ?>">
                                <i class="plug icon"></i> <?php esc_html_e( 'Manage AI Connectors', 'mainwp' ); ?>
                            </a>
                        <?php elseif ( 'env' === $key_source || 'constant' === $key_source ) : ?>
                            <div class="ui visible info blue message">
                                <?php esc_html_e( 'This site supplies a Google API key through its server configuration. That key takes priority over anything stored here, so change it where it is defined.', 'mainwp' ); ?>
                            </div>
                        <?php elseif ( 'database' === $key_source && $google_active && $agent_available ) : ?>
                            <?php if ( ! $google_failed ) : ?>
                            <h4 class="ui header mainwp-assistant-connected-header">
                                <?php if ( $provider_failure ) : ?>
                                <i class="yellow exclamation circle icon"></i>
                                <?php else : ?>
                                <i class="green check circle icon"></i>
                                <?php endif; ?>
                                <div class="content">
                                    <?php
                                    // A failure no provider was named for leaves this key unproven,
                                    // so the row stops claiming it is connected.
                                    if ( $provider_failure ) {
                                        esc_html_e( 'Key saved - Google (Gemini)', 'mainwp' );
                                    } else {
                                        esc_html_e( 'Connected - Google (Gemini)', 'mainwp' );
                                    }
                                    ?>
                                    <div class="sub header"><?php echo esc_html( MainWP_Assistant_Onboarding::free_tier_disclosure() ); ?></div>
                                </div>
                            </h4>
                            <?php endif; ?>
                            <p><?php esc_html_e( 'Stored key', 'mainwp' ); ?>: <span class="mainwp-assistant-key-masked"><?php echo esc_html( $key_masked ); ?></span></p>
                            <button type="button" class="ui basic button mainwp-assistant-connect-cta" data-onboarding-source="replace">
                                <?php esc_html_e( 'Replace key', 'mainwp' ); ?>
                            </button>
                        <?php elseif ( 'database' === $key_source ) : ?>
                            <div class="ui visible yellow message">
                                <?php esc_html_e( 'Key saved, connection not active. WordPress is not serving Sites mode from this key yet.', 'mainwp' ); ?>
                            </div>
                            <button type="button" class="ui basic button mainwp-assistant-connect-cta" data-onboarding-source="replace">
                                <?php esc_html_e( 'Replace key', 'mainwp' ); ?>
                            </button>
                            <a class="ui basic button" href="<?php echo esc_url( $connectors_url ); ?>">
                                <i class="plug icon"></i> <?php esc_html_e( 'Manage AI Connectors', 'mainwp' ); ?>
                            </a>
                        <?php elseif ( ! $agent_available ) : ?>
                            <div class="ui segment mainwp-assistant-connect-segment">
                                <h4 class="ui header"><?php esc_html_e( 'Connect free AI', 'mainwp' ); ?></h4>
                                <p><?php esc_html_e( 'About two minutes, no credit card. MainWP stores a Google AI Studio key and pins a free-tier model.', 'mainwp' ); ?></p>
                                <button type="button" class="ui green button mainwp-assistant-connect-cta" data-onboarding-source="settings">
                                    <?php esc_html_e( 'Connect free AI', 'mainwp' ); ?>
                                </button>
                                <div class="mainwp-assistant-connect-note"><?php echo esc_html( MainWP_Assistant_Onboarding::free_tier_disclosure() ); ?></div>
                                <div class="mainwp-assistant-connect-note">
                                    <?php esc_html_e( 'Prefer OpenAI or Anthropic?', 'mainwp' ); ?>
                                    <a href="<?php echo esc_url( $connectors_url ); ?>"><?php esc_html_e( 'Manage AI Connectors', 'mainwp' ); ?></a>
                                </div>
                            </div>
                        <?php else : ?>
                            <div class="ui visible info blue message">
                                <?php esc_html_e( 'Sites mode is running on another provider. Connect a Google key here if you want to use the Gemini free tier instead.', 'mainwp' ); ?>
                            </div>
                            <button type="button" class="ui basic button mainwp-assistant-connect-cta" data-onboarding-source="settings">
                                <?php esc_html_e( 'Connect free AI', 'mainwp' ); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ( $supports_ai ) : ?>
                    <div class="ui grid field">
                        <label class="six wide column middle aligned"><?php esc_html_e( 'AI provider connections', 'mainwp' ); ?></label>
                        <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Opens the WordPress Connectors screen, where you add and remove the API keys the assistant uses.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                            <a class="ui green basic button" href="<?php echo esc_url( $connectors_url ); ?>">
                                <i class="plug icon"></i> <?php esc_html_e( 'Manage AI Connectors', 'mainwp' ); ?>
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

                <h3 class="ui dividing header">
                    <?php MainWP_Settings_Indicator::render_indicator( 'header', 'settings-field-indicator-model-preference' ); ?>
                    <?php esc_html_e( 'Model Preference', 'mainwp' ); ?>
                </h3>

                <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-model-preference" default-indi-value="">
                    <label class="six wide column middle aligned" for="mainwp-assistant-model-preference">
                        <?php MainWP_Settings_Indicator::render_not_default_indicator( 'none_preset_value', $model_preference ); ?>
                        <?php esc_html_e( 'Preferred model', 'mainwp' ); ?>
                    </label>
                    <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Optional. When set, the assistant asks for this model first and falls back to any available model. Leave empty to let WordPress choose.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                        <input type="text" class="settings-field-value-change-handler" id="mainwp-assistant-model-preference" value="<?php echo esc_attr( $model_preference ); ?>" placeholder="<?php esc_attr_e( 'e.g. claude-sonnet-4-5 or gpt-5-mini', 'mainwp' ); ?>">
                    </div>
                </div>
                <div class="ui divider"></div>
                <button class="ui green big button" id="mainwp-assistant-save-model-preference">
                    <?php esc_html_e( 'Save', 'mainwp' ); ?>
                </button>
                <div id="mainwp-assistant-model-preference-message" class="ui message" style="display:none;"></div>
            </div>
        </div>
        </div>
        <?php
    }

    /**
     * AJAX: Save the site-wide model preference.
     */
    public static function ajax_save_model_preference() {
        check_ajax_referer( 'mainwp_assistant_settings', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) || ! \mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mainwp' ) ) );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by check_ajax_referer above.
        $preference = isset( $_POST['model_preference'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['model_preference'] ) ) ) : '';

        // The value is passed straight to the AI Client as a model id, so it has
        // to clear both the character set model ids use and the list of models
        // the configured providers actually serve. An empty value is valid and
        // means "let WordPress choose"; an allowlist that cannot be built never
        // blocks a save.
        $check = MainWP_Assistant_Onboarding::validate_model_id( $preference );

        if ( ! $check['valid'] ) {
            $message = 'unknown' === $check['reason'] && ! empty( $check['available'] )
                ? sprintf(
                    /* translators: 1: submitted model id, 2: comma separated list of model ids. */
                    __( 'No configured provider offers the model "%1$s". Available models include: %2$s.', 'mainwp' ),
                    $preference,
                    implode( ', ', $check['available'] )
                )
                : __( 'That model id is not valid. Use up to 100 letters, digits, dots, colons, slashes, dashes, underscores, or at signs.', 'mainwp' );

            wp_send_json_error(
                array(
                    'message'   => $message,
                    'available' => $check['available'],
                ),
                400
            );
        }

        update_option( 'mainwp_assistant_model_preference', $preference );
        // A person chose this value; the retired-default migration must not touch it.
        update_option( MainWP_Assistant_Onboarding::MODEL_PIN_SOURCE_OPTION, 'user' );

        wp_send_json_success(
            array(
                'message'   => __( 'Model preference saved.', 'mainwp' ),
                'model'     => $preference,
                'known'     => $check['known'],
                'providers' => $check['providers'],
            )
        );
    }

    /**
     * Render the Policies tab content.
     */
    private static function render_policies_tab() {
        $user_id             = get_current_user_id();
        $policies            = MainWP_Assistant_Policies::instance();
        $current_mode        = $policies->get_access_mode( $user_id );
        $confirmation_level  = $policies->get_confirmation_level( $user_id );
        $protected_tags      = $policies->get_protected_tags( $user_id );
        $all_tags            = $policies->get_all_tags();
        $is_enabled          = $policies->is_assistant_enabled();
        $can_manage          = current_user_can( 'manage_options' );
        $access_modes        = $policies->get_access_modes();
        $confirmation_levels = $policies->get_confirmation_levels();
        ?>
        <div id="mainwp-assistant-policies-tab" class="ui padded segment">
            <h2 class="ui dividing header">
                <?php esc_html_e( 'AI Access Policies', 'mainwp' ); ?>
                <div class="sub header"><?php esc_html_e( 'Control what the AI assistant can do on your dashboard. Changes on this tab save automatically.', 'mainwp' ); ?></div>
            </h2>

            <div class="ui form">
                <!-- Access Mode Section -->
                <h3 class="ui dividing header">
                    <?php MainWP_Settings_Indicator::render_indicator( 'header', 'settings-field-indicator-access-mode' ); ?>
                    <?php esc_html_e( 'Access Mode', 'mainwp' ); ?>
                </h3>

                <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-access-mode" default-indi-value="<?php echo esc_attr( MainWP_Assistant_Policies::MODE_READ_ONLY ); ?>">
                    <label class="six wide column top aligned">
                        <?php MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_assistant_access_mode', $current_mode, true, MainWP_Assistant_Policies::MODE_READ_ONLY ); ?>
                        <?php esc_html_e( 'Access mode', 'mainwp' ); ?>
                    </label>
                    <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Choose the level of access for AI operations.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                        <div class="grouped fields" id="mainwp-assistant-access-mode-group">
                            <?php foreach ( $access_modes as $mode => $label ) : ?>
                                <?php
                                $mode_info = static::get_access_mode_info( $mode );
                                ?>
                                <div class="field">
                                    <div class="ui radio checkbox">
                                        <input type="radio" class="settings-field-value-change-handler" name="access_mode" value="<?php echo esc_attr( $mode ); ?>" <?php checked( $current_mode, $mode ); ?>>
                                        <label>
                                            <strong><?php echo esc_html( $label ); ?></strong>
                                            <p class="ui small grey text"><?php echo esc_html( $mode_info ); ?></p>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div id="mainwp-assistant-access-mode-message" class="ui message" style="display:none;"></div>
                    </div>
                </div>

                <!-- Confirmation Level Section -->
                <div id="mainwp-assistant-confirmation-level-section"<?php echo MainWP_Assistant_Policies::MODE_READ_ONLY === $current_mode ? ' style="display:none"' : ''; ?>>
                    <h3 class="ui dividing header">
                        <?php MainWP_Settings_Indicator::render_indicator( 'header', 'settings-field-indicator-confirmation-level' ); ?>
                        <?php esc_html_e( 'Confirmation Level', 'mainwp' ); ?>
                    </h3>

                    <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-confirmation-level" default-indi-value="<?php echo esc_attr( MainWP_Assistant_Policies::CONFIRM_ALL ); ?>">
                        <label class="six wide column top aligned">
                            <?php MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_assistant_confirmation_level', $confirmation_level, true, MainWP_Assistant_Policies::CONFIRM_ALL ); ?>
                            <?php esc_html_e( 'Ask for confirmation', 'mainwp' ); ?>
                        </label>
                        <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Choose when the assistant asks for confirmation before taking action.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                            <div class="grouped fields" id="mainwp-assistant-confirmation-level-group">
                                <?php foreach ( $confirmation_levels as $level => $label ) : ?>
                                    <?php
                                    $level_info = static::get_confirmation_level_info( $level );
                                    ?>
                                    <div class="field">
                                        <div class="ui radio checkbox">
                                            <input type="radio" class="settings-field-value-change-handler" name="confirmation_level" value="<?php echo esc_attr( $level ); ?>" <?php checked( $confirmation_level, $level ); ?>>
                                            <label>
                                                <strong><?php echo esc_html( $label ); ?></strong>
                                                <p class="ui small grey text"><?php echo esc_html( $level_info ); ?></p>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div id="mainwp-assistant-confirmation-level-message" class="ui message" style="display:none;"></div>
                        </div>
                    </div>
                </div>

                <!-- Protected Tags Section -->
                <h3 class="ui dividing header"><?php esc_html_e( 'Protected Tags', 'mainwp' ); ?></h3>

                <?php if ( empty( $all_tags ) ) : ?>
                    <div class="ui visible info blue message">
                        <?php esc_html_e( 'No tags found. Create tags in the Sites section to use protected tags.', 'mainwp' ); ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ManageGroups' ) ); ?>"><?php esc_html_e( 'Manage tags', 'mainwp' ); ?></a>
                    </div>
                <?php else : ?>
                    <div class="ui grid field">
                        <label class="six wide column top aligned">
                            <?php esc_html_e( 'Protected tags', 'mainwp' ); ?>
                        </label>
                        <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Sites with these tags will require extra confirmation before the AI can modify them.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                            <div class="grouped fields" id="mainwp-assistant-protected-tags-group">
                                <?php foreach ( $all_tags as $tag ) : ?>
                                    <div class="field">
                                        <div class="ui checkbox">
                                            <input type="checkbox" name="protected_tags[]" value="<?php echo esc_attr( $tag['id'] ); ?>" <?php checked( in_array( (int) $tag['id'], $protected_tags, true ) ); ?>>
                                            <label><?php echo esc_html( $tag['name'] ); ?></label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div id="mainwp-assistant-protected-tags-message" class="ui message" style="display:none;"></div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ( $can_manage ) : ?>
                    <!-- Assistant Features Consent -->
                    <?php
                    $has_consent = $policies->has_consent();
                    ?>
                    <h3 class="ui dividing header"><?php esc_html_e( 'Assistant Features Consent', 'mainwp' ); ?></h3>

                    <div class="ui grid field">
                        <label class="six wide column middle aligned">
                            <?php esc_html_e( 'Assistant features consent', 'mainwp' ); ?>
                        </label>
                        <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'The privacy opt-in required before Chat, Tour, and Video features run. The Global Assistant Toggle below controls availability separately.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                            <?php if ( $has_consent ) : ?>
                                <div class="ui green label">
                                    <i class="check icon"></i> <?php esc_html_e( 'Enabled', 'mainwp' ); ?>
                                </div>
                                <button class="ui red button mainwp-assistant-consent-action" id="mainwp-assistant-revoke-consent-btn">
                                    <i class="times icon"></i> <?php esc_html_e( 'Disable Assistant Features', 'mainwp' ); ?>
                                </button>
                                <p class="ui small grey text mainwp-assistant-consent-note">
                                    <?php esc_html_e( 'Disabling turns off the assistant and keeps your conversation history until you remove it from the History & Privacy tab.', 'mainwp' ); ?>
                                </p>
                            <?php else : ?>
                                <div class="ui grey label">
                                    <i class="times icon"></i> <?php esc_html_e( 'Not Enabled', 'mainwp' ); ?>
                                </div>
                                <button class="ui green button mainwp-assistant-consent-action" id="mainwp-assistant-grant-consent-btn">
                                    <i class="check icon"></i> <?php esc_html_e( 'Enable Assistant Features', 'mainwp' ); ?>
                                </button>
                                <p class="ui small grey text mainwp-assistant-consent-note">
                                    <?php esc_html_e( 'Enabling turns on Chat, Tour, and Video features once you accept the privacy notice. You can also turn them on from the assistant panel.', 'mainwp' ); ?>
                                </p>
                            <?php endif; ?>
                            <div id="mainwp-assistant-consent-message" class="ui message" style="display:none;"></div>
                        </div>
                    </div>

                    <!-- Global Assistant Toggle (Admin Only) -->
                    <h3 class="ui dividing header">
                        <?php MainWP_Settings_Indicator::render_indicator( 'header', 'settings-field-indicator-global-toggle' ); ?>
                        <?php esc_html_e( 'Global Assistant Toggle', 'mainwp' ); ?>
                    </h3>

                    <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-global-toggle" default-indi-value="1">
                        <label class="six wide column middle aligned">
                            <?php MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_assistant_enabled', $is_enabled ? '1' : '0', true, '1' ); ?>
                            <?php esc_html_e( 'Assistant availability', 'mainwp' ); ?>
                        </label>
                        <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Turns the assistant on or off for every user on this dashboard. Consent above controls the privacy opt-in separately.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                            <div class="ui toggle checkbox" id="mainwp-assistant-global-toggle">
                                <input type="checkbox" class="settings-field-value-change-handler" name="assistant_enabled" <?php checked( $is_enabled ); ?>>
                                <label>
                                    <?php if ( $is_enabled ) : ?>
                                        <?php esc_html_e( 'Assistant is enabled for all users', 'mainwp' ); ?>
                                    <?php else : ?>
                                        <?php esc_html_e( 'Assistant is disabled for all users', 'mainwp' ); ?>
                                    <?php endif; ?>
                                </label>
                            </div>
                            <div id="mainwp-assistant-global-toggle-message" class="ui message" style="display:none;"></div>
                        </div>
                    </div>

                    <!-- Daily AI Spend Limit -->
                    <?php
                    $daily_budget = $policies->get_daily_budget_usd();
                    $spend_today  = MainWP_DB_Assistant::instance()->get_spend_today();
                    ?>
                    <h3 class="ui dividing header">
                        <?php MainWP_Settings_Indicator::render_indicator( 'header', 'settings-field-indicator-daily-budget' ); ?>
                        <?php esc_html_e( 'Daily AI Spend Limit', 'mainwp' ); ?>
                    </h3>

                    <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-daily-budget" default-indi-value="<?php echo esc_attr( number_format( MainWP_Assistant_Policies::DEFAULT_DAILY_BUDGET_USD, 2, '.', '' ) ); ?>">
                        <label class="six wide column middle aligned">
                            <?php MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_assistant_daily_budget_usd', number_format( $daily_budget, 2, '.', '' ), true, number_format( MainWP_Assistant_Policies::DEFAULT_DAILY_BUDGET_USD, 2, '.', '' ) ); ?>
                            <?php esc_html_e( 'Daily AI spend limit (USD)', 'mainwp' ); ?>
                        </label>
                        <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'A site-wide hard cap. When the day\'s estimated spend reaches this amount, MainWP blocks further AI requests until tomorrow. Set 0 for no limit.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                            <div class="ui labeled input">
                                <div class="ui label">$</div>
                                <input type="number" class="settings-field-value-change-handler" min="0" step="0.01" id="mainwp-assistant-daily-budget" name="daily_budget_usd" value="<?php echo esc_attr( number_format( $daily_budget, 2, '.', '' ) ); ?>">
                            </div>
                            <p class="ui small grey text" id="mainwp-assistant-daily-budget-today">
                                <?php if ( null === $spend_today ) : ?>
                                    <?php esc_html_e( 'Today\'s spend could not be read. While a limit is set, AI requests stay blocked until the total is readable again.', 'mainwp' ); ?>
                                <?php elseif ( $daily_budget > 0 ) : ?>
                                    <?php
                                    printf(
                                        /* translators: 1: estimated spend today in USD, 2: configured daily limit in USD */
                                        esc_html__( '$%1$s of $%2$s used today. Costs are estimated from token counts and published provider prices.', 'mainwp' ),
                                        esc_html( number_format_i18n( (float) $spend_today['cost_usd'], 2 ) ),
                                        esc_html( number_format_i18n( $daily_budget, 2 ) )
                                    );
                                    ?>
                                <?php else : ?>
                                    <?php
                                    printf(
                                        /* translators: %s: estimated spend today in USD */
                                        esc_html__( '$%s used today. Costs are estimated from token counts and published provider prices. No limit is set.', 'mainwp' ),
                                        esc_html( number_format_i18n( (float) $spend_today['cost_usd'], 2 ) )
                                    );
                                    ?>
                                <?php endif; ?>
                            </p>
                            <div id="mainwp-assistant-daily-budget-message" class="ui message" style="display:none;"></div>
                        </div>
                    </div>

                    <!-- Per-User Daily AI Spend Limit -->
                    <?php
                    $user_daily_budget = $policies->get_user_daily_budget_usd();
                    $user_spend_today  = MainWP_DB_Assistant::instance()->get_user_spend_today( get_current_user_id() );
                    ?>
                    <h3 class="ui dividing header">
                        <?php MainWP_Settings_Indicator::render_indicator( 'header', 'settings-field-indicator-user-daily-budget' ); ?>
                        <?php esc_html_e( 'Per-User Daily AI Spend Limit', 'mainwp' ); ?>
                    </h3>

                    <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-user-daily-budget" default-indi-value="0.00">
                        <label class="six wide column middle aligned">
                            <?php MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_assistant_user_daily_budget_usd', number_format( $user_daily_budget, 2, '.', '' ), true, '0.00' ); ?>
                            <?php esc_html_e( 'Per-user daily AI spend limit (USD)', 'mainwp' ); ?>
                        </label>
                        <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'A second cap that applies to each user on top of the site-wide limit, so one user cannot spend the whole budget. Set 0 for no per-user limit.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                            <div class="ui labeled input">
                                <div class="ui label">$</div>
                                <input type="number" class="settings-field-value-change-handler" min="0" step="0.01" id="mainwp-assistant-user-daily-budget" name="user_daily_budget_usd" value="<?php echo esc_attr( number_format( $user_daily_budget, 2, '.', '' ) ); ?>">
                            </div>
                            <p class="ui small grey text" id="mainwp-assistant-user-daily-budget-today">
                                <?php if ( null === $user_spend_today ) : ?>
                                    <?php esc_html_e( 'Your spend today could not be read. While a limit is set, your AI requests stay blocked until the total is readable again.', 'mainwp' ); ?>
                                <?php elseif ( $user_daily_budget > 0 ) : ?>
                                    <?php
                                    printf(
                                        /* translators: 1: estimated user spend today in USD, 2: configured per-user daily limit in USD */
                                        esc_html__( '$%1$s of your $%2$s used today. Costs are estimated from token counts and published provider prices.', 'mainwp' ),
                                        esc_html( number_format_i18n( (float) $user_spend_today['cost_usd'], 2 ) ),
                                        esc_html( number_format_i18n( $user_daily_budget, 2 ) )
                                    );
                                    ?>
                                <?php else : ?>
                                    <?php
                                    printf(
                                        /* translators: %s: estimated user spend today in USD */
                                        esc_html__( '$%s of your spend used today. Costs are estimated from token counts and published provider prices. No per-user limit is set.', 'mainwp' ),
                                        esc_html( number_format_i18n( (float) $user_spend_today['cost_usd'], 2 ) )
                                    );
                                    ?>
                                <?php endif; ?>
                            </p>
                            <div id="mainwp-assistant-user-daily-budget-message" class="ui message" style="display:none;"></div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render the History & Privacy tab content.
     */
    private static function render_history_tab() {
        $user_id         = get_current_user_id();
        $history_opt_out = get_user_meta( $user_id, 'mainwp_assistant_history_opt_out', true );

        // Migrate legacy disclosure key if needed: mainwp_assistant_disclosure_seen -> mainwp_assistant_disclosure_shown.
        $legacy_disclosure = get_user_meta( $user_id, 'mainwp_assistant_disclosure_seen', true );
        $disclosure_shown  = get_user_meta( $user_id, 'mainwp_assistant_disclosure_shown', true );

        if ( $legacy_disclosure && ! $disclosure_shown ) {
            // Copy legacy value to the unified key used by assistant class.
            update_user_meta( $user_id, 'mainwp_assistant_disclosure_shown', true );
            delete_user_meta( $user_id, 'mainwp_assistant_disclosure_seen' );
            $disclosure_shown = true;
        }

        // Migrate legacy key if needed: mainwp_assistant_retention_days -> mainwp_assistant_history_retention.
        $legacy_retention = get_user_meta( $user_id, 'mainwp_assistant_retention_days', true );
        $retention_days   = get_user_meta( $user_id, 'mainwp_assistant_history_retention', true );

        if ( $legacy_retention && ! $retention_days ) {
            // Copy legacy value to the unified key used by assistant and cron.
            $retention_days = absint( $legacy_retention );
            update_user_meta( $user_id, 'mainwp_assistant_history_retention', $retention_days );
            delete_user_meta( $user_id, 'mainwp_assistant_retention_days' );
        }

        // Default retention is 30 days.
        $retention_days = $retention_days ? absint( $retention_days ) : 30;
        ?>
        <div id="mainwp-assistant-history-tab" class="ui padded segment">
            <h2 class="ui dividing header">
                <?php esc_html_e( 'History & Privacy', 'mainwp' ); ?>
                <div class="sub header"><?php esc_html_e( 'Manage your conversation history and privacy settings.', 'mainwp' ); ?></div>
            </h2>

            <?php if ( ! $disclosure_shown ) : ?>
                <!-- First-use Disclosure Banner -->
                <div class="ui info blue message" id="mainwp-assistant-disclosure-banner">
                    <i class="close icon" id="mainwp-assistant-dismiss-disclosure" role="button" tabindex="0" aria-label="<?php esc_attr_e( 'Dismiss', 'mainwp' ); ?>"></i>
                    <div class="header">
                        <i class="info circle icon"></i> <?php esc_html_e( 'About Conversation History', 'mainwp' ); ?>
                    </div>
                    <p><?php esc_html_e( 'Your dashboard stores your conversations with the AI assistant locally for context. External AI providers or bridge services may process that content while they generate a response. You can delete your conversations at any time.', 'mainwp' ); ?></p>
                    <a href="<?php echo esc_url( 'https://mainwp.com/privacy-policy/' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Read our Privacy Policy', 'mainwp' ); ?> <i class="external alternate icon"></i></a>
                </div>
            <?php endif; ?>

            <!-- Retention Settings -->
            <h3 class="ui dividing header">
                <?php MainWP_Settings_Indicator::render_indicator( 'header', 'settings-field-indicator-history-retention' ); ?>
                <?php esc_html_e( 'History Retention', 'mainwp' ); ?>
            </h3>

            <div class="ui form" id="mainwp-assistant-history-form">
                <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-history-retention" default-indi-value="30">
                    <label class="six wide column middle aligned" for="mainwp-assistant-retention-days">
                        <?php MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_assistant_history_retention', (string) $retention_days, true, '30' ); ?>
                        <?php esc_html_e( 'Keep conversations for', 'mainwp' ); ?>
                    </label>
                    <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'How long conversation history is kept before automatic cleanup. Between 1 and 365 days.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                        <div class="ui right labeled input">
                            <input type="number" class="settings-field-value-change-handler" name="retention_days" id="mainwp-assistant-retention-days" value="<?php echo esc_attr( $retention_days ); ?>" min="1" max="365" <?php echo $history_opt_out ? 'disabled' : ''; ?>>
                            <div class="ui basic label"><?php esc_html_e( 'days', 'mainwp' ); ?></div>
                        </div>
                    </div>
                </div>

                <div class="ui grid field settings-field-indicator-wrapper settings-field-indicator-history-retention" default-indi-value="0">
                    <label class="six wide column middle aligned">
                        <?php MainWP_Settings_Indicator::render_not_default_indicator( 'mainwp_assistant_history_opt_out', $history_opt_out ? '1' : '0', true, '0' ); ?>
                        <?php esc_html_e( 'Disable conversation history', 'mainwp' ); ?>
                    </label>
                    <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'When enabled, conversations will not be saved and previous history will be cleared.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                        <div class="ui checkbox" id="mainwp-assistant-opt-out-checkbox">
                            <input type="checkbox" class="settings-field-value-change-handler" name="history_opt_out" <?php checked( $history_opt_out ); ?>>
                            <label><?php esc_html_e( 'Disable conversation history entirely', 'mainwp' ); ?></label>
                        </div>
                    </div>
                </div>

                <div class="ui divider"></div>
                <button class="ui green big button" id="mainwp-assistant-save-history-settings">
                    <?php esc_html_e( 'Save Settings', 'mainwp' ); ?>
                </button>
                <div id="mainwp-assistant-history-message" class="ui message" style="display:none;"></div>
            </div>

            <!-- Export & Clear -->
            <h3 class="ui dividing header"><?php esc_html_e( 'Manage History Data', 'mainwp' ); ?></h3>

            <div class="ui form">
                <div class="ui grid field">
                    <label class="six wide column middle aligned"><?php esc_html_e( 'Export conversation history', 'mainwp' ); ?></label>
                    <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Downloads every conversation of yours, with its messages, as a JSON file.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                        <button class="ui green basic button" id="mainwp-assistant-export-history">
                            <i class="download icon"></i> <?php esc_html_e( 'Export History (JSON)', 'mainwp' ); ?>
                        </button>
                    </div>
                </div>
                <div class="ui grid field">
                    <label class="six wide column middle aligned"><?php esc_html_e( 'Delete conversation history', 'mainwp' ); ?></label>
                    <div class="ten wide column" data-tooltip="<?php esc_attr_e( 'Permanently deletes every conversation of yours. Export first if you want a copy.', 'mainwp' ); ?>" data-inverted="" data-position="top left">
                        <button class="ui red button" id="mainwp-assistant-clear-history">
                            <i class="trash icon"></i> <?php esc_html_e( 'Clear All History', 'mainwp' ); ?>
                        </button>
                    </div>
                </div>
                <div id="mainwp-assistant-history-data-message" class="ui message" style="display:none;"></div>
            </div>

            <!-- Help Links -->
            <h3 class="ui dividing header"><?php esc_html_e( 'Help & Documentation', 'mainwp' ); ?></h3>

            <div class="ui list">
                <a class="item" href="https://mainwp.com/privacy-policy/" target="_blank" rel="noopener">
                    <i class="external alternate icon"></i> <?php esc_html_e( 'Privacy Policy', 'mainwp' ); ?>
                </a>
                <a class="item" href="<?php echo esc_url( 'https://docs.mainwp.com/dashboard/overview/mainwp-ai' ); ?>" target="_blank" rel="noopener">
                    <i class="external alternate icon"></i> <?php esc_html_e( 'Knowledge Base', 'mainwp' ); ?>
                </a>
            </div>
        </div>
        <?php
    }

    /**
     * Render modals for the settings page.
     */
    private static function render_modals() {
        MainWP_Assistant_Onboarding::render_wizard_modal();
        ?>
        <!-- Clear History Confirmation Modal -->
        <div class="ui mini modal" id="mainwp-assistant-clear-history-modal">
            <div class="header"><?php esc_html_e( 'Clear Conversation History', 'mainwp' ); ?></div>
            <div class="content">
                <div class="ui yellow message">
                    <?php esc_html_e( 'This will permanently delete all your conversation history with the AI assistant. This action cannot be undone.', 'mainwp' ); ?>
                </div>
            </div>
            <div class="actions">
                <button class="ui cancel button"><?php esc_html_e( 'Cancel', 'mainwp' ); ?></button>
                <button class="ui red approve button" id="mainwp-assistant-clear-history-confirm">
                    <i class="trash icon"></i> <?php esc_html_e( 'Clear History', 'mainwp' ); ?>
                </button>
            </div>
        </div>

        <!-- Revoke Consent Confirmation Modal -->
        <div class="ui mini modal" id="mainwp-assistant-revoke-consent-modal">
            <div class="header">
                <i class="exclamation triangle icon"></i>
                <?php esc_html_e( 'Disable Assistant Features', 'mainwp' ); ?>
            </div>
            <div class="content">
                <div class="ui yellow message">
                    <p><?php esc_html_e( 'This turns off the assistant for everyone. The consent gate reappears the next time someone opens a feature, and conversation history stays until you clear it on the History & Privacy tab.', 'mainwp' ); ?></p>
                </div>
            </div>
            <div class="actions">
                <button class="ui cancel button"><?php esc_html_e( 'Cancel', 'mainwp' ); ?></button>
                <button class="ui red approve button" id="mainwp-assistant-revoke-consent-confirm">
                    <i class="power off icon"></i> <?php esc_html_e( 'Disable Assistant', 'mainwp' ); ?>
                </button>
            </div>
        </div>

        <!-- Disable Assistant Confirmation Modal -->
        <div class="ui mini modal" id="mainwp-assistant-disable-global-modal">
            <div class="header">
                <i class="power off icon"></i>
                <?php esc_html_e( 'Disable Assistant', 'mainwp' ); ?>
            </div>
            <div class="content">
                <div class="ui yellow message">
                    <p><?php esc_html_e( 'This will disable the assistant for all users on this dashboard.', 'mainwp' ); ?></p>
                </div>
            </div>
            <div class="actions">
                <button class="ui cancel button"><?php esc_html_e( 'Cancel', 'mainwp' ); ?></button>
                <button class="ui red approve button" id="mainwp-assistant-disable-global-confirm">
                    <?php esc_html_e( 'Disable Assistant', 'mainwp' ); ?>
                </button>
            </div>
        </div>
        <?php
    }

    /**
     * Get description for access mode.
     *
     * @param string $mode Access mode constant.
     * @return string Description.
     */
    private static function get_access_mode_info( $mode ) {
        $info = array(
            MainWP_Assistant_Policies::MODE_READ_ONLY   => __( 'View data only. The AI cannot change your sites or settings.', 'mainwp' ),
            MainWP_Assistant_Policies::MODE_READ_WRITE_CONFIRM => __( 'Can make changes but requires your confirmation for write operations.', 'mainwp' ),
            MainWP_Assistant_Policies::MODE_FULL_ACCESS => __( 'Can run every operation, including deletions, which are always previewed and confirmed first.', 'mainwp' ),
        );
        return $info[ $mode ] ?? '';
    }

    /**
     * Get human-readable description for a confirmation level.
     *
     * @param string $level Confirmation level constant.
     * @return string Description text.
     */
    private static function get_confirmation_level_info( $level ) {
        $info = array(
            MainWP_Assistant_Policies::CONFIRM_ALL         => __( 'Every action that modifies data requires your approval before executing.', 'mainwp' ),
            MainWP_Assistant_Policies::CONFIRM_DESTRUCTIVE => __( 'Only actions that delete or remove data require confirmation. Non-destructive changes like syncing or adding sites proceed automatically.', 'mainwp' ),
        );
        return $info[ $level ] ?? '';
    }

    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================

    /**
     * Verify nonce, capability, and assistant-enabled state for standard settings AJAX requests.
     *
     * Sends a JSON error response and terminates if any check fails.
     */
    private static function verify_settings_ajax_request() {
        // Verify nonce.
        if ( ! isset( $_POST['security'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['security'] ) ), 'mainwp_assistant_settings' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'mainwp' ) ), 403 );
        }

        // Check capability.
        if ( ! \mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mainwp' ) ), 403 );
        }

        // Check if assistant is enabled.
        if ( ! MainWP_System::is_assistant_enabled() ) {
            wp_send_json_error(
                array( 'message' => __( 'The AI Assistant is disabled. Turn it on from the AI Access Policies tab.', 'mainwp' ) ),
                403
            );
        }
    }

    /**
     * AJAX handler: Save access mode.
     */
    public static function ajax_save_access_mode() {
        static::verify_settings_ajax_request();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_settings_ajax_request().
        $mode = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : '';

        $result = MainWP_Assistant_Policies::instance()->set_access_mode( $mode );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
        }

        wp_send_json_success( array( 'mode' => $mode ) );
    }

    /**
     * AJAX handler: Save confirmation level.
     */
    public static function ajax_save_confirmation_level() {
        static::verify_settings_ajax_request();

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in verify_settings_ajax_request().
        $level = isset( $_POST['level'] ) ? sanitize_text_field( wp_unslash( $_POST['level'] ) ) : '';

        $result = MainWP_Assistant_Policies::instance()->set_confirmation_level( $level );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
        }

        wp_send_json_success( array( 'level' => $level ) );
    }

    /**
     * AJAX handler: Save protected tags.
     */
    public static function ajax_save_protected_tags() {
        static::verify_settings_ajax_request();

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Sanitized below; nonce verified in verify_settings_ajax_request().
        $raw_tag_ids = isset( $_POST['tag_ids'] ) ? wp_unslash( $_POST['tag_ids'] ) : array();

        // Handle JSON-encoded input.
        if ( is_string( $raw_tag_ids ) ) {
            $decoded     = json_decode( $raw_tag_ids, true );
            $raw_tag_ids = is_array( $decoded ) ? $decoded : array();
        }

        // Sanitize as integers.
        $tag_ids = array_map( 'absint', (array) $raw_tag_ids );

        $result = MainWP_Assistant_Policies::instance()->set_protected_tags( $tag_ids );

        if ( ! $result ) {
            wp_send_json_error( array( 'message' => __( 'Failed to save protected tags.', 'mainwp' ) ), 500 );
        }

        wp_send_json_success( array( 'tag_ids' => $tag_ids ) );
    }

    /**
     * AJAX handler: Save global toggle.
     */
    public static function ajax_save_global_toggle() {
        // Verify nonce.
        if ( ! isset( $_POST['security'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['security'] ) ), 'mainwp_assistant_settings' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'mainwp' ) ), 403 );
        }

        // Check capability - must be administrator with dashboard settings access.
        if ( ! current_user_can( 'manage_options' ) || ! \mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' ) ) {
            wp_send_json_error( array( 'message' => __( 'Only administrators with dashboard settings access can change this setting.', 'mainwp' ) ), 403 );
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Boolean filter.
        $enabled = isset( $_POST['enabled'] ) && filter_var( wp_unslash( $_POST['enabled'] ), FILTER_VALIDATE_BOOLEAN );

        $result = MainWP_Assistant_Policies::instance()->set_assistant_enabled( $enabled );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 403 );
        }

        wp_send_json_success( array( 'enabled' => $enabled ) );
    }

    /**
     * AJAX handler: Save the daily AI spend limit.
     */
    public static function ajax_save_daily_budget() {
        // Verify nonce.
        if ( ! isset( $_POST['security'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['security'] ) ), 'mainwp_assistant_settings' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'mainwp' ) ), 403 );
        }

        // Check capability - must be administrator with dashboard settings access.
        if ( ! current_user_can( 'manage_options' ) || ! \mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' ) ) {
            wp_send_json_error( array( 'message' => __( 'Only administrators with dashboard settings access can change this setting.', 'mainwp' ) ), 403 );
        }

        $budget = isset( $_POST['budget'] ) ? sanitize_text_field( wp_unslash( $_POST['budget'] ) ) : '';

        $result = MainWP_Assistant_Policies::instance()->set_daily_budget_usd( $budget );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
        }

        $limit       = MainWP_Assistant_Policies::instance()->get_daily_budget_usd();
        $spend_today = MainWP_DB_Assistant::instance()->get_spend_today();

        wp_send_json_success(
            array(
                'budget'        => number_format( $limit, 2, '.', '' ),
                // Inputs for the "used today" line's three display states:
                // spent_today null selects the unreadable state, the budget
                // value selects limit-set vs no-limit.
                'spent_today'   => is_array( $spend_today ) ? number_format_i18n( (float) $spend_today['cost_usd'], 2 ) : null,
                'limit_display' => number_format_i18n( $limit, 2 ),
            )
        );
    }

    /**
     * AJAX handler: Save the per-user daily AI spend limit.
     */
    public static function ajax_save_user_daily_budget() {
        // Verify nonce.
        if ( ! isset( $_POST['security'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['security'] ) ), 'mainwp_assistant_settings' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'mainwp' ) ), 403 );
        }

        // Check capability - must be administrator with dashboard settings access.
        if ( ! current_user_can( 'manage_options' ) || ! \mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' ) ) {
            wp_send_json_error( array( 'message' => __( 'Only administrators with dashboard settings access can change this setting.', 'mainwp' ) ), 403 );
        }

        $budget = isset( $_POST['budget'] ) ? sanitize_text_field( wp_unslash( $_POST['budget'] ) ) : '';

        $result = MainWP_Assistant_Policies::instance()->set_user_daily_budget_usd( $budget );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
        }

        $limit       = MainWP_Assistant_Policies::instance()->get_user_daily_budget_usd();
        $spend_today = MainWP_DB_Assistant::instance()->get_user_spend_today( get_current_user_id() );

        wp_send_json_success(
            array(
                'budget'        => number_format( $limit, 2, '.', '' ),
                // Same three display states as the site-wide limit save,
                // scoped to the saving admin's own bucket.
                'spent_today'   => is_array( $spend_today ) ? number_format_i18n( (float) $spend_today['cost_usd'], 2 ) : null,
                'limit_display' => number_format_i18n( $limit, 2 ),
            )
        );
    }

    /**
     * AJAX handler: Save history settings.
     */
    public static function ajax_save_history_settings() {
        // Verify nonce.
        if ( ! isset( $_POST['security'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['security'] ) ), 'mainwp_assistant_settings' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'mainwp' ) ), 403 );
        }

        // Check capability. History settings are per-user data, so chat access suffices.
        if ( ! \mainwp_current_user_can( 'dashboard', 'access_global_dashboard' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mainwp' ) ), 403 );
        }

        $user_id        = get_current_user_id();
        $retention_days = isset( $_POST['retention_days'] ) ? absint( wp_unslash( $_POST['retention_days'] ) ) : 30;
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Boolean filter.
        $opt_out = isset( $_POST['history_opt_out'] ) && filter_var( wp_unslash( $_POST['history_opt_out'] ), FILTER_VALIDATE_BOOLEAN );

        // Validate retention days range.
        $retention_days = max( 1, min( 365, $retention_days ) );

        // When opting out, clear existing history BEFORE flipping the meta:
        // the opt-out flag unlocks the stateless confirm path, so it must
        // never be set while stored confirmations could still survive a
        // failed delete.
        if ( $opt_out ) {
            $deleted = MainWP_DB_Assistant::instance()->delete_user_conversations( $user_id );
            if ( is_wp_error( $deleted ) ) {
                wp_send_json_error( array( 'message' => $deleted->get_error_message() ), 500 );
            }
        }

        // Use the unified key that assistant and cron jobs expect.
        update_user_meta( $user_id, 'mainwp_assistant_history_retention', $retention_days );
        update_user_meta( $user_id, 'mainwp_assistant_history_opt_out', $opt_out ? 1 : 0 );

        // Clean up legacy key if it exists.
        delete_user_meta( $user_id, 'mainwp_assistant_retention_days' );

        wp_send_json_success(
            array(
                'retention_days' => $retention_days,
                'opt_out'        => $opt_out,
            )
        );
    }

    /**
     * AJAX handler: Clear history.
     */
    public static function ajax_clear_history() {
        // Verify nonce.
        if ( ! isset( $_POST['security'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['security'] ) ), 'mainwp_assistant_settings' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'mainwp' ) ), 403 );
        }

        // Check capability. Clearing targets only the current user's own conversations.
        if ( ! \mainwp_current_user_can( 'dashboard', 'access_global_dashboard' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mainwp' ) ), 403 );
        }

        $user_id = get_current_user_id();
        $deleted = MainWP_DB_Assistant::instance()->delete_user_conversations( $user_id );

        if ( is_wp_error( $deleted ) ) {
            wp_send_json_error( array( 'message' => $deleted->get_error_message() ), 500 );
        }

        wp_send_json_success(
            array(
                'message' => sprintf(
                    /* translators: %d: number of conversations deleted */
                    _n( 'Cleared %d conversation.', 'Cleared %d conversations.', (int) $deleted, 'mainwp' ),
                    $deleted
                ),
                'deleted' => $deleted,
            )
        );
    }

    /**
     * AJAX handler: Export history.
     */
    public static function ajax_export_history() {
        // Verify nonce.
        if ( ! isset( $_POST['security'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['security'] ) ), 'mainwp_assistant_settings' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'mainwp' ) ), 403 );
        }

        // Check capability. Export covers only the current user's own conversations.
        if ( ! \mainwp_current_user_can( 'dashboard', 'access_global_dashboard' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mainwp' ) ), 403 );
        }

        $user_id  = get_current_user_id();
        $db       = MainWP_DB_Assistant::instance();
        $page     = 1;
        $per_page = 100; // Maximum allowed by get_user_conversations.
        $result   = $db->get_user_conversations( $user_id, $page, $per_page, true );

        if ( is_wp_error( $result ) || ! is_array( $result ) ) {
            $message = is_wp_error( $result ) ? $result->get_error_message() : __( 'Failed to export history.', 'mainwp' );
            wp_send_json_error( array( 'message' => $message ), 500 );
        }

        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="mainwp-assistant-history-' . gmdate( 'Y-m-d' ) . '.json"' );
        nocache_headers();

        echo '{';
        echo '"exported_at":' . wp_json_encode( gmdate( 'c' ) ) . ',';
        echo '"user_id":' . wp_json_encode( $user_id ) . ',';
        echo '"conversations":[';

        $first_conversation = true;
        $message_limit      = 500; // Maximum allowed by get_messages.
        $complete           = true;

        while ( is_array( $result ) ) {
            $conversations = is_array( $result['conversations'] ?? null ) ? $result['conversations'] : array();

            foreach ( $conversations as $conversation ) {
                if ( ! $first_conversation ) {
                    echo ',';
                }
                $first_conversation = false;

                echo '{';
                echo '"id":' . wp_json_encode( (int) $conversation->id ) . ',';
                echo '"title":' . wp_json_encode( $conversation->title ) . ',';
                echo '"created_at":' . wp_json_encode( gmdate( 'c', (int) $conversation->created_at ) ) . ',';
                echo '"updated_at":' . wp_json_encode( gmdate( 'c', (int) $conversation->updated_at ) ) . ',';
                echo '"messages":[';

                $message_offset = 0;
                $first_message  = true;

                do {
                    $messages = $db->get_messages( $conversation->id, $user_id, $message_limit, $message_offset );
                    if ( is_wp_error( $messages ) || ! is_array( $messages ) ) {
                        MainWP_Logger::instance()->debug(
                            'Assistant export history failed loading messages for conversation ' . (int) $conversation->id . ': ' .
                            ( is_wp_error( $messages ) ? $messages->get_error_message() : 'invalid_messages_response' )
                        );
                        $complete = false;
                        break;
                    }

                    foreach ( $messages as $msg ) {
                        if ( ! $first_message ) {
                            echo ',';
                        }
                        $first_message = false;

                        echo wp_json_encode(
                            array(
                                'role'       => $msg->role,
                                'content'    => $msg->content,
                                'created_at' => gmdate( 'c', (int) $msg->created_at ),
                            )
                        );
                    }

                    $message_count   = count( $messages );
                    $message_offset += $message_limit;

                    if ( function_exists( 'flush' ) ) {
                        flush();
                    }
                } while ( $message_count === $message_limit );

                echo ']}';

                if ( function_exists( 'flush' ) ) {
                    flush();
                }
            }

            $total_pages = max( 1, absint( $result['total_pages'] ?? 1 ) );
            if ( $page >= $total_pages ) {
                break;
            }

            ++$page;
            $result = $db->get_user_conversations( $user_id, $page, $per_page, true );
            if ( is_wp_error( $result ) || ! is_array( $result ) ) {
                MainWP_Logger::instance()->debug(
                    'Assistant export history failed loading conversations page ' . (int) $page . ': ' .
                    ( is_wp_error( $result ) ? $result->get_error_message() : 'invalid_conversation_response' )
                );
                $complete = false;
                break;
            }
        }

        // Streaming already sent headers and body bytes, so a late failure
        // cannot become an HTTP error; the completeness flag is how the
        // client detects a partial export.
        echo '],';
        echo '"complete":' . wp_json_encode( $complete );
        echo '}';

        if ( function_exists( 'flush' ) ) {
            flush();
        }

        wp_die();
    }

    /**
     * AJAX handler: Grant assistant features consent.
     *
     * @since 6.0.0
     */
    public static function ajax_grant_consent() {
        if ( ! isset( $_POST['security'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['security'] ) ), 'mainwp_assistant_consent' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'mainwp' ) ), 403 );
        }

        if ( ! current_user_can( 'manage_options' ) || ! \mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mainwp' ) ), 403 );
        }

        $result = MainWP_Assistant_Policies::instance()->grant_consent();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 403 );
        }

        // Attempt bridge registration after consent succeeds.
        $registered = false;
        $bridge_url = defined( 'MAINWP_ASSISTANT_BRIDGE_URL' ) ? MAINWP_ASSISTANT_BRIDGE_URL : 'https://ai-bridge.fly.dev';

        if ( ! empty( $bridge_url ) ) {
            $reg_result = MainWP_Assistant::instance()->re_register_with_bridge( $bridge_url );
            $registered = ( true === $reg_result );

            if ( is_wp_error( $reg_result ) ) {
                MainWP_Logger::instance()->debug( 'Assistant Bridge registration failed during consent: ' . $reg_result->get_error_message() );
            }
        }

        wp_send_json_success(
            array(
                'consented'  => true,
                'registered' => $registered,
            )
        );
    }

    /**
     * AJAX handler: Revoke assistant features consent.
     *
     * Revokes consent and disables the assistant. Conversation history is
     * left in place; it is purged separately through the Clear History action.
     *
     * @since 6.0.0
     */
    public static function ajax_revoke_consent() {
        if ( ! isset( $_POST['security'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['security'] ) ), 'mainwp_assistant_settings' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'mainwp' ) ), 403 );
        }

        if ( ! current_user_can( 'manage_options' ) || ! \mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mainwp' ) ), 403 );
        }

        $result = MainWP_Assistant_Policies::instance()->revoke_consent();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ), 403 );
        }

        // Clean up bridge credentials.
        MainWP_Keys_Manager::instance()->update_key_value( 'mainwp_assistant_bridge_token', false );
        delete_option( 'mainwp_assistant_bridge_uuid' );
        delete_transient( 'mainwp_bridge_quota_remaining' );
        delete_transient( 'mainwp_bridge_register_backoff' );
        MainWP_Logger::instance()->debug( 'Assistant Bridge credentials cleaned up on consent revocation.' );

        wp_send_json_success( array( 'consented' => false ) );
    }

    /**
     * Method mainwp_help_content()
     *
     * Creates the MainWP Help Documentation List for the help component in the sidebar.
     */
    public static function mainwp_help_content() {
        $allow_pages = array( 'AssistantSettings' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Page display only.
        if ( isset( $_GET['page'] ) && in_array( $_GET['page'], $allow_pages, true ) ) {
            ?>
            <p><?php esc_html_e( 'Help documents for the MainWP Assistant.', 'mainwp' ); ?></p>
            <div class="ui list">
                <div class="item"><i class="external alternate icon"></i> <a href="<?php echo esc_url( 'https://docs.mainwp.com/dashboard/overview/mainwp-ai' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'MainWP Knowledge Base', 'mainwp' ); ?></a></div>
                <div class="item"><i class="external alternate icon"></i> <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener"><?php esc_html_e( 'Get a free Google Gemini API key', 'mainwp' ); ?></a></div>
                <div class="item"><i class="external alternate icon"></i> <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener"><?php esc_html_e( 'Get OpenAI API Key', 'mainwp' ); ?></a></div>
                <div class="item"><i class="external alternate icon"></i> <a href="https://console.anthropic.com/" target="_blank" rel="noopener"><?php esc_html_e( 'Get Anthropic API Key', 'mainwp' ); ?></a></div>
                <?php
                /**
                 * Action: mainwp_assistant_settings_help_item
                 *
                 * Fires at the bottom of the help articles list in the Help sidebar on the Assistant Settings page.
                 *
                 * @since 5.4
                 */
                do_action( 'mainwp_assistant_settings_help_item' );
                ?>
            </div>
            <?php
        }
    }
}
