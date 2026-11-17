<?php
/**
 * MainWP Insights Abilities
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Abilities_Insights
 *
 * Read-only analytical abilities over dashboard data:
 * - mainwp/query-activity-v1: Filterable, paginated activity log events
 * - mainwp/get-activity-summary-v1: Grouped activity counts over a date range
 * - mainwp/get-network-snapshot-v1: One-call network health overview
 * - mainwp/list-upcoming-renewals-v1: Cost Tracker renewals in a window
 */
class MainWP_Abilities_Insights { //phpcs:ignore -- NOSONAR - multi methods.

    /**
     * Register all insights abilities.
     *
     * @return void
     */
    public static function register(): void {
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return;
        }
        self::register_query_activity();
        self::register_activity_summary();
        self::register_network_snapshot();
        self::register_upcoming_renewals();
    }

    /**
     * Register mainwp/query-activity-v1 ability.
     *
     * @return void
     */
    private static function register_query_activity(): void {
        wp_register_ability(
            'mainwp/query-activity-v1',
            array(
                'label'               => __( 'Query Activity Log', 'mainwp' ),
                'description'         => __( 'Query the MainWP activity log (Dashboard Insights events) with site, client, context, search, and date-range filters. Answers questions like "what happened on my sites last week". Requires the Logs module.', 'mainwp' ),
                'category'            => 'mainwp-insights',
                'input_schema'        => self::get_query_activity_input_schema(),
                'output_schema'       => self::get_query_activity_output_schema(),
                'execute_callback'    => array( self::class, 'execute_query_activity' ),
                'permission_callback' => array( MainWP_Abilities_Util::class, 'check_rest_api_permission' ),
                'meta'                => array(
                    'mainwp_team_control' => true,
                    'show_in_rest'        => true,
                    'annotations'         => array(
                        'instructions' => 'Omit or pass 0 / empty string for any filter to mean "no filter". Date params are unix seconds; the range defaults to the last 30 days and is capped at 90.',
                        'readonly'     => true,
                        'destructive'  => false,
                        'idempotent'   => true,
                    ),
                ),
            )
        );
    }

    /**
     * Get input schema for query-activity-v1.
     *
     * @return array
     */
    private static function get_query_activity_input_schema(): array {
        return array(
            'type'                 => array( 'object', 'null' ),
            'properties'           => array(
                'site_id'   => array(
                    'type'        => 'integer',
                    'description' => __( 'Filter to one site by ID. 0 or omitted = all sites.', 'mainwp' ),
                    'default'     => 0,
                    'minimum'     => 0,
                ),
                'client_id' => array(
                    'type'        => 'integer',
                    'description' => __( 'Filter to one client by ID. 0 or omitted = all clients.', 'mainwp' ),
                    'default'     => 0,
                    'minimum'     => 0,
                ),
                'context'   => array(
                    'type'        => 'string',
                    'description' => __( 'Filter by event context (e.g. plugins, themes, posts, users, sync). Empty = all contexts.', 'mainwp' ),
                    'default'     => '',
                ),
                'search'    => array(
                    'type'        => 'string',
                    'description' => __( 'Free-text search over event items. Empty = no search filter.', 'mainwp' ),
                    'default'     => '',
                ),
                'date_from' => array(
                    'type'        => 'integer',
                    'description' => __( 'Range start, unix seconds. 0 = default window start (30 days before date_to).', 'mainwp' ),
                    'default'     => 0,
                    'minimum'     => 0,
                ),
                'date_to'   => array(
                    'type'        => 'integer',
                    'description' => __( 'Range end, unix seconds. 0 = now.', 'mainwp' ),
                    'default'     => 0,
                    'minimum'     => 0,
                ),
                'page'      => array(
                    'type'        => 'integer',
                    'description' => __( 'Page number (1-based).', 'mainwp' ),
                    'default'     => 1,
                    'minimum'     => 1,
                ),
                'per_page'  => array(
                    'type'        => 'integer',
                    'description' => __( 'Items per page.', 'mainwp' ),
                    'default'     => 20,
                    'minimum'     => 1,
                    'maximum'     => 100,
                ),
            ),
            'additionalProperties' => false,
        );
    }

    /**
     * Get output schema for query-activity-v1.
     *
     * @return array
     */
    private static function get_query_activity_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'items'     => array(
                    'type'        => 'array',
                    'description' => __( 'Activity events, newest first.', 'mainwp' ),
                    'items'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'id'        => array( 'type' => 'integer' ),
                            'site_id'   => array( 'type' => 'integer' ),
                            'site_name' => array( 'type' => 'string' ),
                            'connector' => array( 'type' => 'string' ),
                            'context'   => array( 'type' => 'string' ),
                            'action'    => array( 'type' => 'string' ),
                            'item'      => array( 'type' => 'string' ),
                            'user_id'   => array( 'type' => 'integer' ),
                            'user'      => array( 'type' => 'string' ),
                            'timestamp' => array( 'type' => 'string' ),
                        ),
                    ),
                ),
                'total'     => array( 'type' => 'integer' ),
                'page'      => array( 'type' => 'integer' ),
                'per_page'  => array( 'type' => 'integer' ),
                'date_from' => array( 'type' => 'integer' ),
                'date_to'   => array( 'type' => 'integer' ),
                'filters'   => array(
                    'type'        => 'object',
                    'description' => __( 'Echo of the filters that were applied.', 'mainwp' ),
                ),
                'note'      => array( 'type' => 'string' ),
            ),
        );
    }

    /**
     * Execute mainwp/query-activity-v1.
     *
     * @param array $input Validated input.
     * @return array|\WP_Error
     */
    public static function execute_query_activity( $input ) {
        if ( ! class_exists( 'MainWP\Dashboard\Module\Log\Log_Manager' ) ) {
            return new \WP_Error(
                'mainwp_module_not_available',
                __( 'Logs module is not active.', 'mainwp' ),
                array( 'status' => 501 )
            );
        }

        $input    = MainWP_Abilities_Util::normalize_input( $input );
        $range    = MainWP_Abilities_Util::resolve_date_range( $input );
        $page     = max( 1, (int) ( $input['page'] ?? 1 ) );
        $per_page = max( 1, min( 100, (int) ( $input['per_page'] ?? 20 ) ) );

        $query_args = array(
            'timestart'        => $range['from'],
            'timestop'         => $range['to'],
            'start'            => ( $page - 1 ) * $per_page,
            'records_per_page' => $per_page,
            'order'            => 'DESC',
            'orderby'          => 'created',
            'optimize'         => true,
        );

        $allowed_sites = MainWP_Abilities_Util::get_allowed_site_ids();

        $site_id = (int) ( $input['site_id'] ?? 0 );
        if ( $site_id > 0 ) {
            $site = MainWP_Abilities_Util::resolve_site( $site_id );
            if ( is_wp_error( $site ) ) {
                return $site;
            }
            $access_check = MainWP_Abilities_Util::check_site_access( $site );
            if ( is_wp_error( $access_check ) ) {
                return $access_check;
            }
            $query_args['wpid']         = (int) $site->id;
            $query_args['check_access'] = false;
        }

        $client_id = (int) ( $input['client_id'] ?? 0 );
        if ( $client_id > 0 ) {
            $client = MainWP_Abilities_Util::resolve_client( $client_id );
            if ( is_wp_error( $client ) ) {
                return $client;
            }
            $client_access = MainWP_Abilities_Util::check_client_access( $client, $input );
            if ( is_wp_error( $client_access ) ) {
                return $client_access;
            }
            $query_args['client_ids'] = array( $client_id );
        }

        // Restricted callers never query outside their allowed sites: with
        // no site filter the query would otherwise default to network-wide,
        // and a client filter alone would cover the client's whole site set
        // rather than the caller's slice. The wpid arg accepts an ID list
        // (Log_Query builds an IN clause), ANDed with any client filter.
        if ( 'all' !== $allowed_sites && $site_id <= 0 ) {
            if ( empty( $allowed_sites ) ) {
                return array(
                    'items'     => array(),
                    'total'     => 0,
                    'page'      => $page,
                    'per_page'  => $per_page,
                    'date_from' => $range['from'],
                    'date_to'   => $range['to'],
                    'filters'   => array(
                        'site_id'   => 0,
                        'client_id' => $client_id,
                        'context'   => (string) ( $input['context'] ?? '' ),
                        'search'    => (string) ( $input['search'] ?? '' ),
                    ),
                    'note'      => __( 'No activity events matched the applied filters and date range.', 'mainwp' ),
                );
            }
            $query_args['wpid']         = $allowed_sites;
            $query_args['check_access'] = false;
        }

        if ( ! empty( $input['context'] ) ) {
            $query_args['contexts'] = sanitize_text_field( $input['context'] );
        }
        if ( ! empty( $input['search'] ) ) {
            $query_args['search'] = sanitize_text_field( $input['search'] );
        }

        $log_db  = \MainWP\Dashboard\Module\Log\Log_Manager::instance()->db;
        $records = $log_db->query( $query_args );
        $total   = $log_db->get_found_records_count();

        $items = array();
        if ( is_array( $records ) ) {
            foreach ( $records as $record ) {
                $items[] = array(
                    'id'        => isset( $record->log_id ) ? (int) $record->log_id : 0,
                    'site_id'   => isset( $record->site_id ) ? (int) $record->site_id : 0,
                    'site_name' => isset( $record->log_site_name ) ? (string) $record->log_site_name : '',
                    'connector' => isset( $record->connector ) ? (string) $record->connector : '',
                    'context'   => isset( $record->context ) ? (string) $record->context : '',
                    'action'    => isset( $record->action ) ? (string) $record->action : '',
                    'item'      => isset( $record->item ) ? (string) $record->item : '',
                    'user_id'   => isset( $record->user_id ) ? (int) $record->user_id : 0,
                    'user'      => isset( $record->user_login ) ? (string) $record->user_login : '',
                    'timestamp' => isset( $record->created ) ? gmdate( 'c', (int) ( $record->created / 1000000 ) ) : '',
                );
            }
        }

        $filters = array(
            'site_id'   => $site_id,
            'client_id' => $client_id,
            'context'   => (string) ( $input['context'] ?? '' ),
            'search'    => (string) ( $input['search'] ?? '' ),
        );

        return array(
            'items'     => $items,
            'total'     => (int) $total,
            'page'      => $page,
            'per_page'  => $per_page,
            'date_from' => $range['from'],
            'date_to'   => $range['to'],
            'filters'   => $filters,
            'note'      => empty( $items ) ? __( 'No activity events matched the applied filters and date range.', 'mainwp' ) : '',
        );
    }

    /**
     * Register mainwp/get-activity-summary-v1 ability.
     *
     * @return void
     */
    private static function register_activity_summary(): void {
        wp_register_ability(
            'mainwp/get-activity-summary-v1',
            array(
                'label'               => __( 'Get Activity Summary', 'mainwp' ),
                'description'         => __( 'Aggregate activity log counts grouped by action, connector, context, site, or user over a date range. Answers questions like "how many updates ran last month". Requires the Logs module.', 'mainwp' ),
                'category'            => 'mainwp-insights',
                'input_schema'        => self::get_activity_summary_input_schema(),
                'output_schema'       => self::get_activity_summary_output_schema(),
                'execute_callback'    => array( self::class, 'execute_activity_summary' ),
                'permission_callback' => array( MainWP_Abilities_Util::class, 'check_rest_api_permission' ),
                'meta'                => array(
                    'mainwp_team_control' => true,
                    'show_in_rest'        => true,
                    'annotations'         => array(
                        'instructions' => 'Omit or pass 0 for site_id/client_id to mean "no filter". Date params are unix seconds; default window 30 days, capped at 90.',
                        'readonly'     => true,
                        'destructive'  => false,
                        'idempotent'   => true,
                    ),
                ),
            )
        );
    }

    /**
     * Get input schema for get-activity-summary-v1.
     *
     * @return array
     */
    private static function get_activity_summary_input_schema(): array {
        return array(
            'type'                 => array( 'object', 'null' ),
            'properties'           => array(
                'group_by'  => array(
                    'type'        => 'string',
                    'description' => __( 'Dimension to group counts by.', 'mainwp' ),
                    'enum'        => array( 'action', 'connector', 'context', 'site', 'user' ),
                    'default'     => 'action',
                ),
                'site_id'   => array(
                    'type'        => 'integer',
                    'description' => __( 'Filter to one site by ID. 0 or omitted = all sites.', 'mainwp' ),
                    'default'     => 0,
                    'minimum'     => 0,
                ),
                'client_id' => array(
                    'type'        => 'integer',
                    'description' => __( 'Filter to one client by ID. 0 or omitted = all clients.', 'mainwp' ),
                    'default'     => 0,
                    'minimum'     => 0,
                ),
                'date_from' => array(
                    'type'        => 'integer',
                    'description' => __( 'Range start, unix seconds. 0 = default window start.', 'mainwp' ),
                    'default'     => 0,
                    'minimum'     => 0,
                ),
                'date_to'   => array(
                    'type'        => 'integer',
                    'description' => __( 'Range end, unix seconds. 0 = now.', 'mainwp' ),
                    'default'     => 0,
                    'minimum'     => 0,
                ),
            ),
            'additionalProperties' => false,
        );
    }

    /**
     * Get output schema for get-activity-summary-v1.
     *
     * @return array
     */
    private static function get_activity_summary_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'groups'       => array(
                    'type'        => 'array',
                    'description' => __( 'Buckets sorted by count descending, top 50.', 'mainwp' ),
                    'items'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'key'   => array( 'type' => 'string' ),
                            'label' => array( 'type' => 'string' ),
                            'count' => array( 'type' => 'integer' ),
                        ),
                    ),
                ),
                'total_events' => array( 'type' => 'integer' ),
                'group_by'     => array( 'type' => 'string' ),
                'date_from'    => array( 'type' => 'integer' ),
                'date_to'      => array( 'type' => 'integer' ),
                'filters'      => array( 'type' => 'object' ),
                'note'         => array( 'type' => 'string' ),
            ),
        );
    }

    /**
     * Execute mainwp/get-activity-summary-v1.
     *
     * @param array $input Validated input.
     * @return array|\WP_Error
     */
    public static function execute_activity_summary( $input ) { //phpcs:ignore -- NOSONAR - complexity.
        if ( ! class_exists( 'MainWP\Dashboard\Module\Log\Log_Manager' ) ) {
            return new \WP_Error(
                'mainwp_module_not_available',
                __( 'Logs module is not active.', 'mainwp' ),
                array( 'status' => 501 )
            );
        }

        global $wpdb;

        $input    = MainWP_Abilities_Util::normalize_input( $input );
        $range    = MainWP_Abilities_Util::resolve_date_range( $input );
        $group_by = isset( $input['group_by'] ) ? (string) $input['group_by'] : 'action';

        $group_map = array(
            'action'    => 'lg.action',
            'connector' => 'lg.connector',
            'context'   => 'lg.context',
            'site'      => 'lg.site_id',
            'user'      => 'lg.user_id',
        );
        if ( ! isset( $group_map[ $group_by ] ) ) {
            $group_by = 'action';
        }
        $group_col = $group_map[ $group_by ];

        $table  = MainWP_DB::instance()->get_table_name( 'wp_logs' );
        $where  = ' lg.created >= %d AND lg.created <= %d AND lg.connector != "compact" ';
        $params = array( $range['from'] * 1000000, $range['to'] * 1000000 );

        $site_id             = (int) ( $input['site_id'] ?? 0 );
        $client_id           = (int) ( $input['client_id'] ?? 0 );
        $effective_client_id = $client_id;

        if ( $site_id > 0 ) {
            $site = MainWP_Abilities_Util::resolve_site( $site_id );
            if ( is_wp_error( $site ) ) {
                return $site;
            }
            $access_check = MainWP_Abilities_Util::check_site_access( $site );
            if ( is_wp_error( $access_check ) ) {
                return $access_check;
            }
            $where   .= ' AND lg.site_id = %d ';
            $params[] = (int) $site->id;
            // site_id takes precedence over client_id, so echo the effective
            // (ignored) client_id filter as 0 rather than the raw input.
            $effective_client_id = 0;
        } elseif ( $client_id > 0 ) {
            $client = MainWP_Abilities_Util::resolve_client( $client_id );
            if ( is_wp_error( $client ) ) {
                return $client;
            }
            $client_access = MainWP_Abilities_Util::check_client_access( $client, $input );
            if ( is_wp_error( $client_access ) ) {
                return $client_access;
            }
            $client_site_ids = array();
            // Raw fetch: the scoped DB path is a no-op under REST/cron and
            // would hide the caller's own slice under admin-ajax; the
            // intersection below applies the real per-user trim.
            $client_sites = MainWP_DB_Client::instance()->get_websites_by_client_ids( array( $client_id ), array( 'no_perm_check' => true ) );
            if ( is_array( $client_sites ) ) {
                foreach ( $client_sites as $client_site ) {
                    $client_site_ids[] = (int) $client_site->id;
                }
            }
            $allowed_sites = MainWP_Abilities_Util::get_allowed_site_ids();
            if ( 'all' !== $allowed_sites ) {
                $client_site_ids = array_values( array_intersect( $client_site_ids, $allowed_sites ) );
            }
            if ( empty( $client_site_ids ) ) {
                return array(
                    'groups'       => array(),
                    'total_events' => 0,
                    'group_by'     => $group_by,
                    'date_from'    => $range['from'],
                    'date_to'      => $range['to'],
                    'filters'      => array(
                        'site_id'   => 0,
                        'client_id' => $client_id,
                    ),
                    'note'         => __( 'The client has no sites, so there is no activity to summarize.', 'mainwp' ),
                );
            }
            $where .= ' AND lg.site_id IN ( ' . implode( ',', $client_site_ids ) . ' ) ';
        } else {
            // No site or client filter: a restricted caller's summary covers
            // only their allowed sites, never the whole network.
            $allowed_sites = MainWP_Abilities_Util::get_allowed_site_ids();
            if ( 'all' !== $allowed_sites ) {
                if ( empty( $allowed_sites ) ) {
                    return array(
                        'groups'       => array(),
                        'total_events' => 0,
                        'group_by'     => $group_by,
                        'date_from'    => $range['from'],
                        'date_to'      => $range['to'],
                        'filters'      => array(
                            'site_id'   => 0,
                            'client_id' => 0,
                        ),
                        'note'         => __( 'No activity events matched the applied filters and date range.', 'mainwp' ),
                    );
                }
                $where .= ' AND lg.site_id IN ( ' . implode( ',', array_map( 'intval', $allowed_sites ) ) . ' ) ';
            }
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- direct query needed for this ability's ad hoc grouping; table name and vetted column from fixed map, values prepared via $wpdb->prepare().
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT {$group_col} AS grp, COUNT(*) AS cnt FROM {$table} lg WHERE {$where} GROUP BY {$group_col} ORDER BY cnt DESC LIMIT 50",
                $params
            )
        );

        $total_events = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} lg WHERE {$where}",
                $params
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $groups = array();
        foreach ( (array) $rows as $row ) {
            $key   = (string) $row->grp;
            $label = $key;
            if ( 'site' === $group_by ) {
                $grp_site = MainWP_DB::instance()->get_website_by_id( (int) $row->grp );
                $label    = $grp_site && ! empty( $grp_site->name ) ? $grp_site->name : ( $grp_site->url ?? $key );
            } elseif ( 'user' === $group_by ) {
                $grp_user = get_user_by( 'id', (int) $row->grp );
                $label    = $grp_user ? $grp_user->user_login : __( '(unknown user)', 'mainwp' );
            }
            $groups[] = array(
                'key'   => $key,
                'label' => (string) $label,
                'count' => (int) $row->cnt,
            );
        }

        return array(
            'groups'       => $groups,
            'total_events' => $total_events,
            'group_by'     => $group_by,
            'date_from'    => $range['from'],
            'date_to'      => $range['to'],
            'filters'      => array(
                'site_id'   => $site_id,
                'client_id' => $effective_client_id,
            ),
            'note'         => empty( $groups ) ? __( 'No activity events matched the applied filters and date range.', 'mainwp' ) : '',
        );
    }

    /**
     * Register mainwp/get-network-snapshot-v1 ability.
     *
     * @return void
     */
    private static function register_network_snapshot(): void {
        wp_register_ability(
            'mainwp/get-network-snapshot-v1',
            array(
                'label'               => __( 'Get Network Snapshot', 'mainwp' ),
                'description'         => __( 'One-call overview of the whole network: site counts by state, stale syncs, pending update totals, monitors up/down, abandoned plugins, and last-24h activity. Call this first for broad "how is my network doing" questions.', 'mainwp' ),
                'category'            => 'mainwp-insights',
                'input_schema'        => array(
                    'type'                 => array( 'object', 'null' ),
                    'properties'           => array(),
                    'additionalProperties' => false,
                ),
                'output_schema'       => self::get_network_snapshot_output_schema(),
                'execute_callback'    => array( self::class, 'execute_network_snapshot' ),
                'permission_callback' => array( MainWP_Abilities_Util::class, 'check_rest_api_permission' ),
                'meta'                => array(
                    'mainwp_team_control' => true,
                    'show_in_rest'        => true,
                    'annotations'         => array(
                        'instructions' => 'Takes no input. activity_last_24h is null when the Logs module is disabled; monitoring totals are 0 when no monitors exist.',
                        'readonly'     => true,
                        'destructive'  => false,
                        'idempotent'   => true,
                    ),
                ),
            )
        );
    }

    /**
     * Get output schema for get-network-snapshot-v1.
     *
     * @return array
     */
    private static function get_network_snapshot_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'sites'             => array(
                    'type'       => 'object',
                    'properties' => array(
                        'total'          => array( 'type' => 'integer' ),
                        'connected'      => array( 'type' => 'integer' ),
                        'disconnected'   => array( 'type' => 'integer' ),
                        'suspended'      => array( 'type' => 'integer' ),
                        'stale_sync_24h' => array( 'type' => 'integer' ),
                    ),
                ),
                'updates'           => array(
                    'type'       => 'object',
                    'properties' => array(
                        'sites_with_core_update' => array( 'type' => 'integer' ),
                        'plugin_updates'         => array( 'type' => 'integer' ),
                        'theme_updates'          => array( 'type' => 'integer' ),
                    ),
                ),
                'monitoring'        => array(
                    'type'       => 'object',
                    'properties' => array(
                        'monitors_up'   => array( 'type' => 'integer' ),
                        'monitors_down' => array( 'type' => 'integer' ),
                    ),
                ),
                'abandoned_plugins' => array( 'type' => 'integer' ),
                'activity_last_24h' => array( 'type' => array( 'integer', 'null' ) ),
                'generated_at'      => array( 'type' => 'integer' ),
            ),
        );
    }

    /**
     * Execute mainwp/get-network-snapshot-v1.
     *
     * @param array $input Validated input (empty).
     * @return array|\WP_Error
     */
    public static function execute_network_snapshot( $input ) { //phpcs:ignore -- NOSONAR - complexity. phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
        $sites = array(
            'total'          => 0,
            'connected'      => 0,
            'disconnected'   => 0,
            'suspended'      => 0,
            'stale_sync_24h' => 0,
        );

        $sites_with_core_update = 0;
        $plugin_updates         = 0;
        $theme_updates          = 0;
        $abandoned_plugins      = 0;

        // Restricted callers get a snapshot of their allowed sites only. The
        // trim happens here against the direct-filter set ($for_manager true
        // fetches raw): the DB layer's own scoping is skipped under
        // REST/cron/CLI, so it cannot be the leg this relies on.
        $allowed_sites = MainWP_Abilities_Util::get_allowed_site_ids();

        $sql      = MainWP_DB::instance()->get_sql_websites_for_current_user( false, null, 'wp.url', false, false, null, true, array( 'wp_upgrades', 'plugins_outdate_info' ) );
        $websites = MainWP_DB::instance()->query( $sql );

        while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) { //phpcs:ignore -- NOSONAR - assignment in loop follows core pattern.
            if ( 'all' !== $allowed_sites && ! in_array( (int) $website->id, $allowed_sites, true ) ) {
                continue;
            }
            ++$sites['total'];

            if ( '' !== (string) $website->sync_errors ) {
                ++$sites['disconnected'];
            } else {
                ++$sites['connected'];
            }
            if ( ! empty( $website->suspended ) ) {
                ++$sites['suspended'];
            }
            if ( (int) $website->dtsSync < time() - DAY_IN_SECONDS ) {
                ++$sites['stale_sync_24h'];
            }

            $wp_upgrades = ! empty( $website->wp_upgrades ) ? json_decode( $website->wp_upgrades, true ) : array();
            if ( is_array( $wp_upgrades ) && ! empty( $wp_upgrades ) ) {
                ++$sites_with_core_update;
            }

            $plugin_upgrades = ! empty( $website->plugin_upgrades ) ? json_decode( $website->plugin_upgrades, true ) : array();
            if ( is_array( $plugin_upgrades ) ) {
                $plugin_updates += count( $plugin_upgrades );
            }

            $theme_upgrades = ! empty( $website->theme_upgrades ) ? json_decode( $website->theme_upgrades, true ) : array();
            if ( is_array( $theme_upgrades ) ) {
                $theme_updates += count( $theme_upgrades );
            }

            $outdated = ! empty( $website->plugins_outdate_info ) ? json_decode( $website->plugins_outdate_info, true ) : array();
            if ( is_array( $outdated ) ) {
                $abandoned_plugins += count( $outdated );
            }
        }

        // Use get_monitors() instead of get_count_up_down_monitors(): the latter
        // is a flat, unscoped count with no per-user site-access join, so it
        // would leak monitor counts for sites the current user can't access.
        $monitors      = MainWP_DB_Uptime_Monitoring::instance()->get_monitors( array( 'issub' => 0 ) );
        $monitors_up   = 0;
        $monitors_down = 0;
        if ( is_array( $monitors ) ) {
            foreach ( $monitors as $monitor ) {
                // Monitors link to sites via wpid; restricted callers only
                // count monitors on their allowed sites.
                if ( 'all' !== $allowed_sites ) {
                    $monitor_site = is_object( $monitor ) ? (int) ( $monitor->wpid ?? 0 ) : 0;
                    if ( ! in_array( $monitor_site, $allowed_sites, true ) ) {
                        continue;
                    }
                }
                $last_status = is_object( $monitor ) ? ( $monitor->last_status ?? null ) : null;
                if ( null === $last_status ) {
                    continue;
                }
                if ( 1 === (int) $last_status ) {
                    ++$monitors_up;
                } elseif ( 0 === (int) $last_status ) {
                    ++$monitors_down;
                }
            }
        }
        $monitoring = array(
            'monitors_up'   => $monitors_up,
            'monitors_down' => $monitors_down,
        );

        $activity_last_24h = null;
        if ( class_exists( 'MainWP\Dashboard\Module\Log\Log_Manager' ) ) {
            if ( 'all' !== $allowed_sites && empty( $allowed_sites ) ) {
                $activity_last_24h = 0;
            } else {
                $activity_args = array(
                    'timestart'        => time() - DAY_IN_SECONDS,
                    'timestop'         => time(),
                    'start'            => 0,
                    'records_per_page' => 1,
                    'optimize'         => true,
                );
                if ( 'all' !== $allowed_sites ) {
                    // Log_Query builds an IN clause from an ID-list wpid.
                    $activity_args['wpid']         = $allowed_sites;
                    $activity_args['check_access'] = false;
                }
                $log_db = \MainWP\Dashboard\Module\Log\Log_Manager::instance()->db;
                $log_db->query( $activity_args );
                $activity_last_24h = (int) $log_db->get_found_records_count();
            }
        }

        return array(
            'sites'             => $sites,
            'updates'           => array(
                'sites_with_core_update' => $sites_with_core_update,
                'plugin_updates'         => $plugin_updates,
                'theme_updates'          => $theme_updates,
            ),
            'monitoring'        => $monitoring,
            'abandoned_plugins' => $abandoned_plugins,
            'activity_last_24h' => $activity_last_24h,
            'generated_at'      => time(),
        );
    }

    /**
     * Register mainwp/list-upcoming-renewals-v1 ability.
     *
     * @return void
     */
    private static function register_upcoming_renewals(): void {
        wp_register_ability(
            'mainwp/list-upcoming-renewals-v1',
            array(
                'label'               => __( 'List Upcoming Renewals', 'mainwp' ),
                'description'         => __( 'Cost Tracker entries renewing within the next N days (default 30), soonest first, with a total amount. Answers "what renews next month and what will it cost". Requires the Cost Tracker module.', 'mainwp' ),
                'category'            => 'mainwp-insights',
                'input_schema'        => self::get_upcoming_renewals_input_schema(),
                'output_schema'       => self::get_upcoming_renewals_output_schema(),
                'execute_callback'    => array( self::class, 'execute_upcoming_renewals' ),
                'permission_callback' => array( MainWP_Abilities_Util::class, 'check_rest_api_permission' ),
                'meta'                => array(
                    'show_in_rest' => true,
                    'annotations'  => array(
                        'instructions' => 'days_ahead defaults to 30 and is capped at 365. total_amount sums the price of the returned items in the dashboard currency.',
                        'readonly'     => true,
                        'destructive'  => false,
                        'idempotent'   => true,
                    ),
                ),
            )
        );
    }

    /**
     * Get input schema for list-upcoming-renewals-v1.
     *
     * @return array
     */
    private static function get_upcoming_renewals_input_schema(): array {
        return array(
            'type'                 => array( 'object', 'null' ),
            'properties'           => array(
                'days_ahead' => array(
                    'type'        => 'integer',
                    'description' => __( 'Window size in days from now.', 'mainwp' ),
                    'default'     => 30,
                    'minimum'     => 1,
                    'maximum'     => 365,
                ),
            ),
            'additionalProperties' => false,
        );
    }

    /**
     * Get output schema for list-upcoming-renewals-v1.
     *
     * @return array
     */
    private static function get_upcoming_renewals_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'items'        => array(
                    'type'        => 'array',
                    'description' => __( 'Costs renewing in the window, soonest first (max 200).', 'mainwp' ),
                    'items'       => array(
                        'type'       => 'object',
                        'properties' => array(
                            'id'             => array( 'type' => 'integer' ),
                            'name'           => array( 'type' => 'string' ),
                            'type'           => array( 'type' => 'string' ),
                            'price'          => array( 'type' => 'number' ),
                            'renewal_type'   => array( 'type' => 'string' ),
                            'payment_method' => array( 'type' => 'string' ),
                            'product_type'   => array( 'type' => 'string' ),
                            'last_renewal'   => array( 'type' => array( 'string', 'null' ) ),
                            'next_renewal'   => array( 'type' => array( 'string', 'null' ) ),
                            'site_ids'       => array(
                                'type'  => 'array',
                                'items' => array( 'type' => 'integer' ),
                            ),
                        ),
                    ),
                ),
                'total'        => array( 'type' => 'integer' ),
                'total_amount' => array( 'type' => 'number' ),
                'days_ahead'   => array( 'type' => 'integer' ),
                'window_from'  => array( 'type' => 'integer' ),
                'window_to'    => array( 'type' => 'integer' ),
                'note'         => array( 'type' => 'string' ),
            ),
        );
    }

    /**
     * Execute mainwp/list-upcoming-renewals-v1.
     *
     * @param array $input Validated input.
     * @return array|\WP_Error
     */
    public static function execute_upcoming_renewals( $input ) {
        if ( ! class_exists( 'MainWP\Dashboard\Module\CostTracker\Cost_Tracker_Manager' ) ) {
            return new \WP_Error(
                'mainwp_module_not_available',
                __( 'Cost Tracker module is not active.', 'mainwp' ),
                array( 'status' => 501 )
            );
        }

        $input      = MainWP_Abilities_Util::normalize_input( $input );
        $days_ahead = max( 1, min( 365, (int) ( $input['days_ahead'] ?? 30 ) ) );
        $now        = time();
        $until      = $now + $days_ahead * DAY_IN_SECONDS;

        $allowed_sites = MainWP_Abilities_Util::get_allowed_site_ids();

        // The Cost Tracker's own renewal-window branch: active subscriptions
        // only, with recurrence roll-forward for stored next_renewal dates
        // that already passed. A raw next_renewal comparison misses both.
        $query_result = \MainWP\Dashboard\Module\CostTracker\Cost_Tracker_DB_Query::instance()->query_costs(
            array(
                'dtsstart' => gmdate( 'Y-m-d', $now ),
                'dtsstop'  => gmdate( 'Y-m-d', $until ),
            )
        );
        $costs        = is_array( $query_result ) && isset( $query_result['items'] ) ? $query_result['items'] : array();

        $renewals = array();
        foreach ( (array) $costs as $cost ) {
            $next     = isset( $cost->next_renewal ) ? (int) $cost->next_renewal : 0;
            $site_ids = self::resolve_cost_site_ids( $cost );

            if ( 'all' !== $allowed_sites ) {
                // Restricted callers see cost items backed by at least one
                // accessible site, trimmed to their slice. Items with no
                // site attachment (company-wide licenses) stay
                // unrestricted-only, and total_amount sums after this filter
                // so it never leaks out-of-scope spend.
                $site_ids = array_values( array_intersect( $site_ids, $allowed_sites ) );
                if ( empty( $site_ids ) ) {
                    continue;
                }
            }

            $item             = MainWP_Abilities_Util::format_cost_for_output( $cost );
            $item['site_ids'] = $site_ids;
            $item['_sort']    = $next;
            $renewals[]       = $item;
        }

        usort(
            $renewals,
            static function ( $a, $b ) {
                return $a['_sort'] <=> $b['_sort'];
            }
        );
        $renewals = array_slice( $renewals, 0, 200 );

        $total_amount = 0.0;
        foreach ( $renewals as &$renewal ) {
            $total_amount += (float) $renewal['price'];
            unset( $renewal['_sort'] );
        }
        unset( $renewal );

        return array(
            'items'        => $renewals,
            'total'        => count( $renewals ),
            'total_amount' => round( $total_amount, 2 ),
            'days_ahead'   => $days_ahead,
            'window_from'  => $now,
            'window_to'    => $until,
            'note'         => empty( $renewals ) ? __( 'No Cost Tracker entries renew in the applied window.', 'mainwp' ) : '',
        );
    }

    /**
     * Resolve every site a cost entry is attached to, the way the Cost
     * Tracker itself does: direct sites plus group and client expansions.
     *
     * @param object $cost Cost Tracker row.
     * @return array Site IDs.
     */
    private static function resolve_cost_site_ids( $cost ): array {
        $sites   = ! empty( $cost->sites ) ? json_decode( $cost->sites, true ) : array();
        $groups  = ! empty( $cost->groups ) ? json_decode( $cost->groups, true ) : array();
        $clients = ! empty( $cost->clients ) ? json_decode( $cost->clients, true ) : array();

        $sites   = is_array( $sites ) ? array_map( 'intval', $sites ) : array();
        $groups  = is_array( $groups ) ? $groups : array();
        $clients = is_array( $clients ) ? $clients : array();

        if ( empty( $groups ) && empty( $clients ) ) {
            return $sites;
        }

        $cost_sites = MainWP_DB::instance()->get_db_sites(
            array(
                'sites'   => $sites,
                'groups'  => $groups,
                'clients' => $clients,
            )
        );

        $site_ids = array();
        foreach ( (array) $cost_sites as $cost_site ) {
            $site_ids[] = (int) $cost_site->id;
        }

        return array_values( array_unique( $site_ids ) );
    }
}
