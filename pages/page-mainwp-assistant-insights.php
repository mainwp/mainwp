<?php
/**
 * MainWP Assistant Insights Page
 *
 * This Class handles building/Managing the
 * Assistant Insights MainWP Dashboard Page.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

defined( 'ABSPATH' ) || exit;

/**
 * Class MainWP_Assistant_Insights
 *
 * @package MainWP\Dashboard
 */
class MainWP_Assistant_Insights { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace -- NOSONAR.

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
     * Instantiate Hooks for the Assistant Insights AJAX handlers.
     */
    public static function init() {
        add_action( 'mainwp_help_sidebar_content', array( static::get_class_name(), 'mainwp_help_content' ) );

        // Initialize AJAX handlers.
        static::init_ajax_handlers();
    }

    /**
     * Initialize AJAX handlers for insights data.
     */
    public static function init_ajax_handlers() {
        add_action( 'wp_ajax_mainwp_assistant_insights_summary', array( static::get_class_name(), 'ajax_get_summary_metrics' ) );
        add_action( 'wp_ajax_mainwp_assistant_insights_activity', array( static::get_class_name(), 'ajax_get_activity_data' ) );
        add_action( 'wp_ajax_mainwp_assistant_insights_top_abilities', array( static::get_class_name(), 'ajax_get_top_abilities' ) );
        add_action( 'wp_ajax_mainwp_assistant_insights_provider_usage', array( static::get_class_name(), 'ajax_get_provider_usage' ) );
        add_action( 'wp_ajax_mainwp_assistant_insights_export', array( static::get_class_name(), 'ajax_export_activity_csv' ) );
        add_action( 'wp_ajax_mainwp_assistant_insights_conversation', array( static::get_class_name(), 'ajax_get_conversation' ) );
    }

    /**
     * Verify the current user can access insights AJAX endpoints.
     *
     * Sends a JSON error response and exits if unauthorized.
     */
    private static function verify_insights_access() {
        if ( ! is_user_logged_in() || ! \mainwp_current_user_can( 'dashboard', 'access_insights_dashboard' ) ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Permission denied.', 'mainwp' ) ) );
        }

        if ( ! MainWP_Assistant::current_user_can_use_assistant() ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Your account does not have access to the assistant.', 'mainwp' ) ) );
        }

        // DELIBERATE EXCEPTION: the assistant gate above now admits scoped
        // accounts, but Assistant Insights reads OTHER users' transcripts
        // and activity, gated only on manage_dashboard_settings — which
        // Team Control can grant to a site-restricted role. Cross-user
        // reads stay locked to fully-unrestricted admins even though the
        // rest of the assistant is scoped. Do not relax this leg with the
        // main gate. ajax_export_activity_csv() repeats this check inline
        // (it does not route through here) — keep the two in sync.
        $allowed_sites = apply_filters( 'mainwp_currentuserallowedaccesssites', 'all' );
        if ( 'all' !== $allowed_sites ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Assistant Insights is not available to accounts restricted to specific sites.', 'mainwp' ) ) );
        }
    }

    /**
     * Check if current user can view all users' insights.
     *
     * @return bool True if user has permission.
     */
    private static function can_view_all_users() {
        return \mainwp_current_user_can( 'dashboard', 'manage_dashboard_settings' );
    }

    /**
     * Check if current user can access privileged cross-user conversation reads.
     *
     * @return bool True if user has permission.
     */
    private static function can_view_privileged_conversations() {
        return current_user_can( 'manage_options' ) && static::can_view_all_users();
    }

    /**
     * Determine whether the resolved user filter requires an admin read path.
     *
     * @param int|int[]|null $user_id Resolved user filter.
     *
     * @return bool
     */
    private static function is_admin_read_request( $user_id ) {
        if ( is_array( $user_id ) || null === $user_id ) {
            return true;
        }

        return absint( $user_id ) !== get_current_user_id();
    }

    /**
     * Determine whether a conversation link should be shown for a result row.
     *
     * @param int $row_user_id Conversation owner user ID.
     *
     * @return bool
     */
    private static function can_view_activity_conversation_details( $row_user_id ) {
        $row_user_id = absint( $row_user_id );
        if ( ! $row_user_id || get_current_user_id() === $row_user_id ) {
            return true;
        }

        return static::can_view_privileged_conversations();
    }

    /**
     * Resolve date range from POST data using UTC strtotime().
     *
     * WordPress sets the default timezone to UTC in wp-settings.php, so all strtotime()
     * calls operate in UTC — matching the Logs module's date handling contract.
     *
     * @return array Array of ( date_from_timestamp, date_to_timestamp ).
     */
    private static function resolve_date_range() {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by caller.
        $range       = isset( $_POST['range'] ) ? sanitize_text_field( wp_unslash( $_POST['range'] ) ) : 'thismonth';
        $custom_from = isset( $_POST['date_from_str'] ) ? sanitize_text_field( wp_unslash( $_POST['date_from_str'] ) ) : '';
        $custom_to   = isset( $_POST['date_to_str'] ) ? sanitize_text_field( wp_unslash( $_POST['date_to_str'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        // Backwards compat: if old-style numeric timestamps are sent, use them directly.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        if ( empty( $range ) || 'custom' === $range ) {
            if ( isset( $_POST['date_from'] ) && is_numeric( $_POST['date_from'] ) && absint( $_POST['date_from'] ) > 0 ) {
                return array(
                    absint( $_POST['date_from'] ),
                    isset( $_POST['date_to'] ) ? absint( $_POST['date_to'] ) : time(),
                );
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        switch ( $range ) {
            case 'today':
                $from = strtotime( 'today' );
                $to   = strtotime( 'today 23:59:59' );
                break;

            case 'yesterday':
                $from = strtotime( 'yesterday' );
                $to   = strtotime( 'yesterday 23:59:59' );
                break;

            case 'thisweek':
                $now  = time();
                $from = strtotime( 'monday this week', strtotime( 'today' ) );
                $to   = $now;
                break;

            case 'last30':
                $from = strtotime( '-29 days', strtotime( 'today' ) );
                $to   = time();
                break;

            case 'lastmonth':
                $from = strtotime( 'first day of last month' );
                $to   = strtotime( 'first day of this month' ) - 1;
                break;

            case 'thisyear':
                $from = strtotime( 'first day of January ' . gmdate( 'Y' ) );
                $to   = time();
                break;

            case 'lastyear':
                $previous_year = (int) gmdate( 'Y' ) - 1;
                $from          = gmmktime( 0, 0, 0, 1, 1, $previous_year );
                $to            = gmmktime( 23, 59, 59, 12, 31, $previous_year );
                break;

            case 'custom':
                $from = ! empty( $custom_from ) ? strtotime( $custom_from . ' 00:00:00' ) : false;
                $to   = ! empty( $custom_to ) ? strtotime( $custom_to . ' 23:59:59' ) : false;
                if ( false === $from ) {
                    // Invalid or missing start date — fall back to this month.
                    $from = strtotime( gmdate( 'Y-m-01' ) );
                    $to   = time();
                } elseif ( false === $to ) {
                    $to = time();
                }
                break;

            case 'thismonth':
            default:
                $from = strtotime( gmdate( 'Y-m-01' ) );
                $to   = time();
                break;
        }

        return array( $from, $to );
    }

    /**
     * Resolve user filter from POST data.
     *
     * Supports the shared InsightsOverview filter bar (comma-separated user IDs or 'allusers')
     * and falls back to the legacy all_users boolean for backwards compatibility.
     *
     * @return int|int[]|null Single user ID, array of user IDs, or null for all users.
     */
    private static function resolve_user_filter() {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by caller.
        $raw = isset( $_POST['user_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['user_ids'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        // Backwards compat: support old all_users boolean.
        if ( empty( $raw ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller.
            if ( isset( $_POST['all_users'] ) && 'true' === $_POST['all_users'] ) {
                $raw = 'allusers';
            }
        }

        if ( empty( $raw ) ) {
            return get_current_user_id();
        }

        $ids = array_map( 'trim', explode( ',', $raw ) );

        if ( in_array( 'allusers', $ids, true ) ) {
            return static::can_view_all_users() ? null : get_current_user_id();
        }

        $ids = array_filter( array_map( 'absint', $ids ) );

        if ( empty( $ids ) || ! static::can_view_all_users() ) {
            return get_current_user_id();
        }

        return count( $ids ) === 1 ? reset( $ids ) : $ids;
    }

    /**
     * Check if the logs module is enabled and available.
     *
     * @return bool True if logs module is enabled.
     */
    private static function is_logs_module_available() {
        return \mainwp_modules_is_enabled( 'logs' );
    }

    /**
     * Verify nonce, access permission, and logs module for JSON insights AJAX requests.
     *
     * Sends a JSON error response and terminates if any check fails.
     */
    private static function verify_insights_ajax_request() {
        check_ajax_referer( 'mainwp_assistant_insights_nonce', 'security' );

        static::verify_insights_access();

        if ( ! static::is_logs_module_available() ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Logs module is not enabled.', 'mainwp' ) ) );
        }
    }

    /**
     * Whether the current install may expose logged assistant message content.
     *
     * Content-bearing endpoints (conversation reads, CSV export) surface the
     * message bodies MainWP logged while the assistant was in use. Revoking
     * consent retains that history, and the assistant can be re-enabled without
     * re-granting consent, so gate those endpoints on both the enabled flag and
     * an active consent grant. Metadata endpoints stay ungated because metadata
     * is recorded regardless of consent.
     *
     * @return bool True when both the assistant is enabled and consent is granted.
     */
    private static function insights_content_access_allowed() {
        $policies = MainWP_Assistant_Policies::instance();
        return $policies->is_assistant_enabled() && $policies->has_consent();
    }

    /**
     * Resolve the ability label for an activity row.
     *
     * Conversation-start rows fall back to the logged conversation title,
     * which is derived from the first prompt characters — content, not
     * metadata — so that fallback only applies while content access holds.
     *
     * @param object $item Activity log row with action and meta.
     *
     * @return string Ability label.
     */
    private static function activity_ability_label( $item ) {
        $ability = isset( $item->meta['ability'] ) ? $item->meta['ability'] : '';

        if ( 'started' === $item->action && empty( $ability ) ) {
            return static::insights_content_access_allowed() && isset( $item->meta['title'] )
                ? sanitize_text_field( $item->meta['title'] )
                : __( 'Conversation', 'mainwp' );
        }

        if ( 'failed' === $item->action && empty( $ability ) ) {
            return __( 'Chat request', 'mainwp' );
        }

        return MainWP_Assistant_Insights_Helper::instance()->format_ability_name( $ability );
    }

    /**
     * Render disabled state message when assistant is globally disabled.
     *
     * @deprecated Use MainWP_Assistant_Policies::render_disabled_state( 'insights' ) directly.
     */
    public static function render_disabled_state_message() {
        MainWP_Assistant_Policies::render_disabled_state( 'insights' );
    }

    /**
     * Enqueue scripts and styles for the insights widgets.
     */
    public static function enqueue_scripts() {
        // Enqueue insights CSS.
        wp_enqueue_style(
            'mainwp-assistant-insights',
            MAINWP_PLUGIN_URL . 'assets/css/mainwp-assistant-insights.css',
            array(),
            MAINWP_VERSION
        );

        // Enqueue insights JavaScript (ApexCharts is already loaded by Log_Admin).
        wp_enqueue_script(
            'mainwp-assistant-insights',
            MAINWP_PLUGIN_URL . 'assets/js/mainwp-assistant-insights.js',
            array( 'jquery', 'mainwp-apexcharts', 'datatables' ),
            MAINWP_VERSION,
            true
        );

        // Localize script with data.
        wp_localize_script(
            'mainwp-assistant-insights',
            'mainwpAssistantInsights',
            array(
                'ajaxurl'         => admin_url( 'admin-ajax.php' ),
                'nonce'           => wp_create_nonce( 'mainwp_assistant_insights_nonce' ),
                'currentUserId'   => get_current_user_id(),
                'canViewAllUsers' => static::can_view_all_users(),
                'startOfWeek'     => (int) get_option( 'start_of_week', 0 ), // 0=Sunday, 1=Monday, etc.
                'strings'         => array(
                    'started'              => esc_html__( 'New conversation', 'mainwp' ),
                    'executed'             => esc_html__( 'Executed', 'mainwp' ),
                    'failed'               => esc_html__( 'Failed', 'mainwp' ),
                    'confirmed'            => esc_html__( 'Confirmed', 'mainwp' ),
                    'loading'              => esc_html__( 'Loading...', 'mainwp' ),
                    'noData'               => esc_html__( 'No activity found.', 'mainwp' ),
                    'exportSuccess'        => esc_html__( 'Export completed.', 'mainwp' ),
                    'exportError'          => esc_html__( 'Export failed.', 'mainwp' ),
                    'view'                 => esc_html__( 'View', 'mainwp' ),
                    'topAbilities'         => esc_html__( 'Top Abilities', 'mainwp' ),
                    'providerUsage'        => esc_html__( 'Provider Usage', 'mainwp' ),
                    'time'                 => esc_html__( 'Time', 'mainwp' ),
                    'user'                 => esc_html__( 'User', 'mainwp' ),
                    'ability'              => esc_html__( 'Ability', 'mainwp' ),
                    'status'               => esc_html__( 'Status', 'mainwp' ),
                    'duration'             => esc_html__( 'Duration', 'mainwp' ),
                    'details'              => esc_html__( 'Details', 'mainwp' ),
                    'convLoadError'        => esc_html__( 'Error loading conversation.', 'mainwp' ),
                    'convNoMsgs'           => esc_html__( 'No messages in this conversation.', 'mainwp' ),
                    'abilitiesUsed'        => esc_html__( 'Abilities Used', 'mainwp' ),
                    'createdLabel'         => esc_html__( 'Created:', 'mainwp' ),
                    'roleUser'             => esc_html__( 'User', 'mainwp' ),
                    'roleAssistant'        => esc_html__( 'Assistant', 'mainwp' ),
                    /* translators: %d: conversation ID */
                    'conversationFallback' => esc_html__( 'Conversation #%d', 'mainwp' ),
                ),
            )
        );
    }


    /**
     * AJAX handler: Get summary metrics.
     *
     * All logged-in users can view their own metrics.
     * Only users with manage_dashboard_settings can view all users' metrics.
     */
    public static function ajax_get_summary_metrics() {
        static::verify_insights_ajax_request();

        list( $date_from, $date_to ) = static::resolve_date_range();
        $user_id                     = static::resolve_user_filter();

        $metrics = MainWP_Assistant_Insights_Helper::instance()->get_summary_metrics( $user_id, $date_from, $date_to );

        wp_send_json_success( $metrics );
    }

    /**
     * AJAX handler: Get activity data for DataTables.
     *
     * All logged-in users can view their own activity.
     * Only users with manage_dashboard_settings can view all users' activity.
     */
    public static function ajax_get_activity_data() {
        static::verify_insights_ajax_request();

        list( $date_from, $date_to ) = static::resolve_date_range();
        $user_id                     = static::resolve_user_filter();

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified above.
        $start  = isset( $_POST['start'] ) ? absint( $_POST['start'] ) : 0;
        $length = isset( $_POST['length'] ) ? min( absint( $_POST['length'] ), 100 ) : 25; // Cap at 100 for performance.
        $draw   = isset( $_POST['draw'] ) ? absint( $_POST['draw'] ) : 1;

        $search = isset( $_POST['search'] ) ? static::normalize_search_input( $_POST['search'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Unslashed and sanitized in normalize_search_input().

        // Map DataTables column index to database column name.
        // Ability and Details columns are marked orderable:false in JS, using null as placeholder.
        // Column layout varies: User column is only present for manage_dashboard_settings users.
        if ( static::can_view_all_users() ) {
            $column_map = array( 'created', 'display_name', null, 'action', null, null );
        } else {
            $column_map = array( 'created', null, 'action', null, null );
        }

        $orderby = 'created';
        $order   = 'DESC';
        if ( isset( $_POST['order'][0]['column'] ) && isset( $_POST['order'][0]['dir'] ) ) {
            $col_idx = absint( $_POST['order'][0]['column'] );
            if ( isset( $column_map[ $col_idx ] ) && null !== $column_map[ $col_idx ] ) {
                $orderby = $column_map[ $col_idx ];
            }
            $order = 'asc' === strtolower( sanitize_text_field( wp_unslash( $_POST['order'][0]['dir'] ) ) ) ? 'ASC' : 'DESC';
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $result = MainWP_Assistant_Insights_Helper::instance()->query_activity(
            array(
                'user_id'          => $user_id,
                'date_from'        => $date_from,
                'date_to'          => $date_to,
                'search'           => $search,
                'search_content'   => static::insights_content_access_allowed(),
                'start'            => $start,
                'records_per_page' => $length,
                'orderby'          => $orderby,
                'order'            => $order,
            )
        );

        // Format data for response.
        // Duration uses the per-ability value from the log table to preserve row-level semantics.
        $data = array();
        foreach ( $result['items'] as $item ) {
            $ability = isset( $item->meta['ability'] ) ? $item->meta['ability'] : '';
            // Per-ability duration from log table (stored as float seconds, convert to ms).
            $duration = isset( $item->duration ) ? (int) round( $item->duration * 1000 ) : 0;
            $conv_id  = isset( $item->meta['conversation_id'] ) ? absint( $item->meta['conversation_id'] ) : 0;

            // For conversation-start rows, show the conversation title instead
            // of an ability name (content-gated inside the helper).
            $ability_label = static::activity_ability_label( $item );

            $data[] = array(
                'time'                  => $item->created_timestamp,
                'time_formatted'        => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item->created_timestamp ),
                'user_id'               => $item->user_id,
                'user_label'            => isset( $item->display_name ) ? $item->display_name : '',
                'ability'               => $ability,
                'ability_label'         => $ability_label,
                'status'                => $item->action,
                'duration'              => $duration,
                'conversation_id'       => $conv_id,
                'can_view_conversation' => static::can_view_activity_conversation_details( $item->user_id ),
                // Raw message content is intentionally omitted: this metadata
                // endpoint is not gated by insights_content_access_allowed()
                // (unlike the CSV export and conversation-read endpoints), so
                // it must not surface conversation bodies. No column renders it.
            );
        }

        wp_send_json(
            array(
                'draw'            => $draw,
                'recordsTotal'    => $result['total_count'],
                'recordsFiltered' => $result['count'],
                'data'            => $data,
            )
        );
    }

    /**
     * AJAX handler: Get top abilities data.
     *
     * All logged-in users can view their own abilities data.
     * Only users with manage_dashboard_settings can view all users' data.
     */
    public static function ajax_get_top_abilities() {
        static::verify_insights_ajax_request();

        list( $date_from, $date_to ) = static::resolve_date_range();
        $user_id                     = static::resolve_user_filter();
        $abilities                   = MainWP_Assistant_Insights_Helper::instance()->get_top_abilities( $user_id, $date_from, $date_to );

        wp_send_json_success( $abilities );
    }

    /**
     * AJAX handler: Get provider usage data.
     *
     * All logged-in users can view their own provider usage.
     * Only users with manage_dashboard_settings can view all users' data.
     */
    public static function ajax_get_provider_usage() {
        static::verify_insights_ajax_request();

        list( $date_from, $date_to ) = static::resolve_date_range();
        $user_id                     = static::resolve_user_filter();
        $providers                   = MainWP_Assistant_Insights_Helper::instance()->get_provider_usage( $user_id, $date_from, $date_to );

        wp_send_json_success( $providers );
    }

    /**
     * AJAX handler: Export activity to CSV.
     *
     * All logged-in users can export their own activity.
     * Only users with manage_dashboard_settings can export all users' activity.
     */
    public static function ajax_export_activity_csv() {
        check_ajax_referer( 'mainwp_assistant_insights_nonce', 'security' );

        if ( ! is_user_logged_in() || ! \mainwp_current_user_can( 'dashboard', 'access_insights_dashboard' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'mainwp' ) );
        }

        // Restricted accounts stay locked out of Insights entirely: this export
        // reads other users' activity. Mirror verify_insights_access() (kept in
        // sync deliberately) — the assistant gate now admits scoped accounts,
        // but Insights and its export do not.
        if ( ! MainWP_Assistant::current_user_can_use_assistant()
            || 'all' !== apply_filters( 'mainwp_currentuserallowedaccesssites', 'all' ) ) {
            wp_die( esc_html__( 'Assistant Insights is not available to accounts restricted to specific sites.', 'mainwp' ) );
        }

        if ( ! static::is_logs_module_available() ) {
            wp_die( esc_html__( 'Logs module is not enabled.', 'mainwp' ) );
        }

        if ( ! static::insights_content_access_allowed() ) {
            wp_die( esc_html__( 'Assistant consent is required to export activity.', 'mainwp' ) );
        }

        list( $date_from, $date_to ) = static::resolve_date_range();
        $user_id                     = static::resolve_user_filter();
        $search                      = isset( $_POST['search'] ) ? static::normalize_search_input( $_POST['search'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; unslashed and sanitized in normalize_search_input().

        $output = fopen( 'php://output', 'w' );
        if ( false === $output ) {
            wp_die( esc_html__( 'Unable to start the CSV export.', 'mainwp' ) );
        }

        // Set headers for CSV download. Use wp_date() for site timezone consistency.
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="assistant-insights-' . wp_date( 'Y-m-d' ) . '.csv"' );

        // CSV header row - includes both ID and display name columns for data integrity and readability.
        fputcsv( $output, array( 'Time', 'User ID', 'User', 'Ability', 'Status', 'Duration', 'Conversation ID', 'Message' ) );

        // Export all matching records using pagination to manage memory.
        // Page size must match the 100-row cap in query_activity() for correct pagination.
        $page_size = 100;
        $offset    = 0;

        do {
            $result = MainWP_Assistant_Insights_Helper::instance()->query_activity(
                array(
                    'user_id'          => $user_id,
                    'date_from'        => $date_from,
                    'date_to'          => $date_to,
                    'search'           => $search,
                    'start'            => $offset,
                    'records_per_page' => $page_size,
                )
            );

            // Data rows — use per-ability duration from the log table to preserve row-level semantics.
            foreach ( $result['items'] as $item ) {
                $ability_raw = isset( $item->meta['ability'] ) ? $item->meta['ability'] : '';
                // Per-ability duration from log table (stored as float seconds, convert to ms).
                $duration = isset( $item->duration ) ? (int) round( $item->duration * 1000 ) : 0;
                $conv_id  = isset( $item->meta['conversation_id'] ) ? absint( $item->meta['conversation_id'] ) : 0;

                $user_label = isset( $item->display_name ) ? $item->display_name : '';

                // For conversation-start rows, show the conversation title instead
                // of an ability name (content-gated inside the helper).
                $ability_label = static::activity_ability_label( $item );

                // Use wp_date() for site timezone consistency with UI display.
                fputcsv(
                    $output,
                    array(
                        static::sanitize_csv_field( wp_date( 'Y-m-d H:i:s', $item->created_timestamp ) ),
                        $item->user_id,
                        static::sanitize_csv_field( $user_label ),
                        static::sanitize_csv_field( $ability_label ),
                        $item->action,
                        static::format_duration_display( $duration ),
                        $conv_id,
                        static::sanitize_csv_field( $item->message ),
                    )
                );
            }

            $fetched_count = count( $result['items'] );
            $offset       += $page_size;

            // Free memory after each batch.
            unset( $result );

        } while ( $fetched_count === $page_size );

        fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Required for closing php://output stream.
        exit;
    }

    /**
     * Format a duration in milliseconds to a human-readable string.
     *
     * @param int $ms Duration in milliseconds.
     *
     * @return string Formatted duration (e.g., '350ms', '4.23s', '2m 15s', '1h 30m').
     */
    private static function format_duration_display( $ms ) {
        if ( empty( $ms ) ) {
            return '--';
        }
        if ( $ms < 1000 ) {
            return $ms . 'ms';
        }
        $total_seconds = $ms / 1000;
        if ( $total_seconds < 60 ) {
            return number_format( $total_seconds, 2 ) . 's';
        }
        $total_minutes  = (int) floor( $total_seconds / 60 );
        $remain_seconds = (int) floor( fmod( $total_seconds, 60 ) );
        if ( $total_minutes < 60 ) {
            return $total_minutes . 'm ' . $remain_seconds . 's';
        }
        $hours          = (int) floor( $total_minutes / 60 );
        $remain_minutes = $total_minutes % 60;
        return $hours . 'h ' . $remain_minutes . 'm';
    }

    /**
     * Sanitize a field value for CSV export to prevent formula injection.
     *
     * Spreadsheet applications like Excel may interpret cell values starting with
     * =, +, -, @, or tab as formulas, which can be exploited for injection attacks.
     * This method prefixes such values with a single quote to force text treatment.
     *
     * @param string $value The field value to sanitize.
     *
     * @return string The sanitized value safe for CSV export.
     */
    private static function sanitize_csv_field( $value ) {
        if ( empty( $value ) || ! is_string( $value ) ) {
            return $value;
        }

        // Characters that trigger formula interpretation in spreadsheets.
        $formula_triggers = array( '=', '+', '-', '@', "\t", "\r", "\n" );
        $trimmed          = ltrim( $value );

        if ( 0 === strpos( $trimmed, "\xEF\xBB\xBF" ) ) {
            $trimmed = substr( $trimmed, 3 );
        }

        $first_char = substr( $trimmed, 0, 1 );
        if ( in_array( $first_char, $formula_triggers, true ) ) {
            // Prefix with single quote to force text interpretation.
            return "'" . $value;
        }

        return $value;
    }

    /**
     * Normalize DataTables and flat search payloads into a single string.
     *
     * @param mixed $search Search value from the request.
     *
     * @return string Normalized search string.
     */
    private static function normalize_search_input( $search ) {
        if ( is_array( $search ) ) {
            $search = isset( $search['value'] ) ? $search['value'] : '';
        }

        if ( ! is_scalar( $search ) ) {
            return '';
        }

        return sanitize_text_field( wp_unslash( (string) $search ) );
    }

    /**
     * AJAX handler: Get conversation details.
     *
     * All logged-in users can view their own conversations.
     * Only users with manage_dashboard_settings can view all users' conversations.
     */
    public static function ajax_get_conversation() {
        static::verify_insights_ajax_request();

        if ( ! static::insights_content_access_allowed() ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Assistant consent is required to view conversation content.', 'mainwp' ) ), 403 );
        }

        $conversation_id = isset( $_POST['conversation_id'] ) ? absint( $_POST['conversation_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.

        if ( ! $conversation_id ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Invalid conversation ID.', 'mainwp' ) ) );
        }

        // Privileged users viewing all users' conversations must use the explicit admin read path.
        $user_id       = static::resolve_user_filter();
        $is_admin_read = static::is_admin_read_request( $user_id );

        if ( $is_admin_read ) {
            if ( ! static::can_view_privileged_conversations() ) {
                wp_send_json_error( array( 'message' => esc_html__( 'Permission denied.', 'mainwp' ) ), 403 );
            }
            $conversation = MainWP_DB_Assistant::instance()->get_conversation_as_admin( $conversation_id );
        } else {
            $conversation = MainWP_DB_Assistant::instance()->get_conversation( $conversation_id, $user_id );
        }

        if ( is_wp_error( $conversation ) ) {
            wp_send_json_error( array( 'message' => $conversation->get_error_message() ) );
        }

        if ( ! $conversation ) {
            wp_send_json_error( array( 'message' => esc_html__( 'Conversation not found.', 'mainwp' ) ) );
        }

        if ( $is_admin_read ) {
            $messages = MainWP_DB_Assistant::instance()->get_messages_as_admin( $conversation_id, 500 );
        } else {
            $messages = MainWP_DB_Assistant::instance()->get_messages( $conversation_id, $user_id, 500 );
        }

        if ( is_wp_error( $messages ) ) {
            wp_send_json_error( array( 'message' => $messages->get_error_message() ) );
        }

        // Fetch abilities executed in this conversation.
        $abilities = MainWP_Assistant_Insights_Helper::instance()->get_conversation_abilities( $conversation_id );

        // Format timestamps using WordPress site timezone for consistency.
        $datetime_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

        wp_send_json_success(
            array(
                'conversation' => array(
                    'id'                   => $conversation->id,
                    'title'                => $conversation->title,
                    'created_at'           => $conversation->created_at,
                    'created_at_formatted' => wp_date( $datetime_format, $conversation->created_at ),
                    'updated_at'           => $conversation->updated_at,
                ),
                'messages'     => array_map(
                    function ( $msg ) use ( $datetime_format ) {
                        return array(
                            'role'                 => $msg->role,
                            'content'              => $msg->content,
                            'created_at'           => $msg->created_at,
                            'created_at_formatted' => wp_date( $datetime_format, $msg->created_at ),
                        );
                    },
                    $messages
                ),
                'abilities'    => array_map(
                    function ( $ab ) use ( $datetime_format ) {
                        return array(
                            'ability_label'        => $ab->ability_label,
                            'status'               => $ab->action,
                            'duration_formatted'   => static::format_duration_display( $ab->duration_ms ),
                            'created_at_formatted' => wp_date( $datetime_format, $ab->created_timestamp ),
                        );
                    },
                    $abilities
                ),
            )
        );
    }

    /**
     * Method mainwp_help_content()
     *
     * Creates the MainWP Help Documentation List for the help component in the sidebar.
     */
    public static function mainwp_help_content() {
        $allow_pages = array( 'InsightsOverview' );
        if ( isset( $_GET['page'] ) && in_array( $_GET['page'], $allow_pages, true ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            ?>
            <p><?php esc_html_e( 'Help documents for the MainWP Assistant.', 'mainwp' ); ?></p>
            <div class="ui list">
                <div class="item"><i class="external alternate icon"></i> <a href="<?php echo esc_url( 'https://docs.mainwp.com/dashboard/overview/mainwp-ai' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'MainWP Knowledge Base', 'mainwp' ); ?></a></div>
                <?php
                /**
                 * Action: mainwp_assistant_insights_help_item
                 *
                 * Fires at the bottom of the help articles list in the Help sidebar on the Assistant Insights page.
                 *
                 * @since 5.4
                 */
                do_action( 'mainwp_assistant_insights_help_item' );
                ?>
            </div>
            <?php
        }
    }
}
