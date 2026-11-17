<?php
/**
 * MainWP Monitoring Abilities
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Abilities_Monitoring
 *
 * Read-only uptime monitoring abilities:
 * - mainwp/get-uptime-stats-v1: Uptime percentage and response-time stats per monitor
 * - mainwp/list-monitor-incidents-v1: Down events over a date range
 */
class MainWP_Abilities_Monitoring { //phpcs:ignore -- NOSONAR - multi methods.

    /**
     * Ceiling on the per-monitor incident fetch window.
     *
     * The window grows with the requested page, and the `page` input schema is
     * a published contract that cannot gain a maximum, so a deep page would
     * otherwise become a multi-million-row LIMIT on every monitor. A monitor's
     * incidents past the ceiling are unreachable and the response says so.
     *
     * @var int
     */
    const MAX_INCIDENT_FETCH_WINDOW = 10000;

    /**
     * Register all monitoring abilities.
     *
     * @return void
     */
    public static function register(): void {
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return;
        }
        self::register_uptime_stats();
        self::register_monitor_incidents();
    }

    /**
     * Get primary monitors, optionally scoped to one site.
     *
     * Returns the structured disabled error when nothing is monitored and the
     * global setting is off, so callers never see a silent empty.
     *
     * @param int $site_id Site ID, 0 = all sites.
     * @return array|\WP_Error Monitor objects or error.
     */
    private static function get_scoped_monitors( int $site_id ) {
        $params = array( 'issub' => 0 );
        if ( $site_id > 0 ) {
            $site = MainWP_Abilities_Util::resolve_site( $site_id );
            if ( is_wp_error( $site ) ) {
                return $site;
            }
            $access_check = MainWP_Abilities_Util::check_site_access( $site );
            if ( is_wp_error( $access_check ) ) {
                return $access_check;
            }
            $params['wpid'] = (int) $site->id;
        }

        $monitors = MainWP_DB_Uptime_Monitoring::instance()->get_monitors( $params );
        if ( ! is_array( $monitors ) ) {
            $monitors = array();
        }

        // The single-site path is gated above by resolve_site + check_site_access.
        // The whole-fleet path ($site_id 0) fetches every monitor, so a restricted
        // caller must be trimmed to their allowed sites here: the monitor query has
        // no per-user scoping and the DB layer's own scoping is skipped under
        // REST/cron/CLI.
        if ( 0 === $site_id ) {
            $allowed_sites = MainWP_Abilities_Util::get_allowed_site_ids();
            if ( 'all' !== $allowed_sites ) {
                $monitors = array_values(
                    array_filter(
                        $monitors,
                        static function ( $monitor ) use ( $allowed_sites ) {
                            return in_array( (int) $monitor->wpid, $allowed_sites, true );
                        }
                    )
                );
            }
        }

        if ( empty( $monitors ) && ! MainWP_Uptime_Monitoring_Edit::is_enable_global_monitoring() ) {
            return new \WP_Error(
                'mainwp_monitoring_disabled',
                __( 'Uptime monitoring is disabled globally (Settings > Uptime Monitoring) and no per-site monitors are active.', 'mainwp' ),
                array( 'status' => 501 )
            );
        }

        return $monitors;
    }

    /**
     * Map a monitor last_status int to a string.
     *
     * @param int $status Raw last_status (1 up, 0 down; any other value, including
     *                     the 99 default, maps to pending).
     * @return string
     */
    private static function map_monitor_status( int $status ): string {
        if ( 1 === $status ) {
            return 'up';
        }
        if ( 0 === $status ) {
            return 'down';
        }
        return 'pending';
    }

    /**
     * Register mainwp/get-uptime-stats-v1 ability.
     *
     * @return void
     */
    private static function register_uptime_stats(): void {
        wp_register_ability(
            'mainwp/get-uptime-stats-v1',
            array(
                'label'               => __( 'Get Uptime Stats', 'mainwp' ),
                'description'         => __( 'Uptime percentage, check counts, and response-time stats per monitor over a date range (default last 7 days, max 30 — hourly stats are retained for 30 days). Filter to one site or get the whole fleet plus an overall summary.', 'mainwp' ),
                'category'            => 'mainwp-monitoring',
                'input_schema'        => self::get_uptime_stats_input_schema(),
                'output_schema'       => self::get_uptime_stats_output_schema(),
                'execute_callback'    => array( self::class, 'execute_uptime_stats' ),
                'permission_callback' => array( MainWP_Abilities_Util::class, 'check_rest_api_permission' ),
                'meta'                => array(
                    'mainwp_team_control' => true,
                    'show_in_rest'        => true,
                    'annotations'         => array(
                        'instructions' => 'site_id 0 or omitted = all monitors. uptime_percent is null for monitors with no checks in the range.',
                        'readonly'     => true,
                        'destructive'  => false,
                        'idempotent'   => true,
                    ),
                ),
            )
        );
    }

    /**
     * Get input schema for get-uptime-stats-v1.
     *
     * @return array
     */
    private static function get_uptime_stats_input_schema(): array {
        return array(
            'type'                 => array( 'object', 'null' ),
            'properties'           => array(
                'site_id'   => array(
                    'type'        => 'integer',
                    'description' => __( 'Filter to one site by ID. 0 or omitted = all monitored sites.', 'mainwp' ),
                    'default'     => 0,
                    'minimum'     => 0,
                ),
                'date_from' => array(
                    'type'        => 'integer',
                    'description' => __( 'Range start, unix seconds. 0 = 7 days before date_to.', 'mainwp' ),
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
     * Get output schema for get-uptime-stats-v1.
     *
     * @return array
     */
    private static function get_uptime_stats_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'items'     => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'monitor_id'     => array( 'type' => 'integer' ),
                            'site_id'        => array( 'type' => 'integer' ),
                            'url'            => array( 'type' => 'string' ),
                            'name'           => array( 'type' => 'string' ),
                            'last_status'    => array( 'type' => 'string' ),
                            'uptime_percent' => array( 'type' => array( 'number', 'null' ) ),
                            'checks_up'      => array( 'type' => 'integer' ),
                            'checks_down'    => array( 'type' => 'integer' ),
                            'avg_ping_ms'    => array( 'type' => array( 'number', 'null' ) ),
                            'min_ping_ms'    => array( 'type' => array( 'number', 'null' ) ),
                            'max_ping_ms'    => array( 'type' => array( 'number', 'null' ) ),
                        ),
                    ),
                ),
                'summary'   => array(
                    'type'       => 'object',
                    'properties' => array(
                        'monitors'       => array( 'type' => 'integer' ),
                        'uptime_percent' => array( 'type' => array( 'number', 'null' ) ),
                    ),
                ),
                'date_from' => array( 'type' => 'integer' ),
                'date_to'   => array( 'type' => 'integer' ),
                'filters'   => array( 'type' => 'object' ),
                'note'      => array( 'type' => 'string' ),
            ),
        );
    }

    /**
     * Execute mainwp/get-uptime-stats-v1.
     *
     * @param array $input Validated input.
     * @return array|\WP_Error
     */
    public static function execute_uptime_stats( $input ) { //phpcs:ignore -- NOSONAR - complexity.
        $input = MainWP_Abilities_Util::normalize_input( $input );
        // Hourly stats are pruned after 30 days; a wider window would just
        // average in silence, so the range clamps to what actually exists.
        $range   = MainWP_Abilities_Util::resolve_date_range( $input, 7, 30 );
        $site_id = (int) ( $input['site_id'] ?? 0 );

        $monitors = self::get_scoped_monitors( $site_id );
        if ( is_wp_error( $monitors ) ) {
            return $monitors;
        }

        $items      = array();
        $total_up   = 0;
        $total_down = 0;

        foreach ( $monitors as $monitor ) {
            $agg  = MainWP_DB_Uptime_Monitoring::instance()->get_uptime_stats_aggregate( (int) $monitor->monitor_id, $range['from'], $range['to'] );
            $up   = is_array( $agg ) && null !== $agg['sum_up'] ? (int) $agg['sum_up'] : 0;
            $down = is_array( $agg ) && null !== $agg['sum_down'] ? (int) $agg['sum_down'] : 0;

            $total_up   += $up;
            $total_down += $down;

            $items[] = array(
                'monitor_id'     => (int) $monitor->monitor_id,
                'site_id'        => (int) $monitor->wpid,
                'url'            => isset( $monitor->url ) ? (string) $monitor->url : '',
                'name'           => isset( $monitor->name ) ? (string) $monitor->name : '',
                'last_status'    => self::map_monitor_status( (int) $monitor->last_status ),
                'uptime_percent' => ( $up + $down ) > 0 ? round( $up / ( $up + $down ) * 100, 2 ) : null,
                'checks_up'      => $up,
                'checks_down'    => $down,
                'avg_ping_ms'    => is_array( $agg ) && null !== $agg['avg_ping'] ? round( (float) $agg['avg_ping'], 1 ) : null,
                'min_ping_ms'    => is_array( $agg ) && null !== $agg['min_ping'] ? (float) $agg['min_ping'] : null,
                'max_ping_ms'    => is_array( $agg ) && null !== $agg['max_ping'] ? (float) $agg['max_ping'] : null,
            );
        }

        return array(
            'items'     => $items,
            'summary'   => array(
                'monitors'       => count( $items ),
                'uptime_percent' => ( $total_up + $total_down ) > 0 ? round( $total_up / ( $total_up + $total_down ) * 100, 2 ) : null,
            ),
            'date_from' => $range['from'],
            'date_to'   => $range['to'],
            'filters'   => array( 'site_id' => $site_id ),
            'note'      => empty( $items ) ? __( 'No monitors matched the applied filters.', 'mainwp' ) : '',
        );
    }

    /**
     * Register mainwp/list-monitor-incidents-v1 ability.
     *
     * @return void
     */
    private static function register_monitor_incidents(): void {
        wp_register_ability(
            'mainwp/list-monitor-incidents-v1',
            array(
                'label'               => __( 'List Monitor Incidents', 'mainwp' ),
                'description'         => __( 'Downtime incidents (up-to-down transitions) across monitors over a date range (default last 7 days, max 90), newest first. Answers "which sites went down this week and why". At most 10,000 incidents per monitor are servable; when a monitor has more in the range, `truncated` is true and the range should be narrowed.', 'mainwp' ),
                'category'            => 'mainwp-monitoring',
                'input_schema'        => self::get_monitor_incidents_input_schema(),
                'output_schema'       => self::get_monitor_incidents_output_schema(),
                'execute_callback'    => array( self::class, 'execute_monitor_incidents' ),
                'permission_callback' => array( MainWP_Abilities_Util::class, 'check_rest_api_permission' ),
                'meta'                => array(
                    'mainwp_team_control' => true,
                    'show_in_rest'        => true,
                    'annotations'         => array(
                        'instructions' => 'site_id 0 or omitted = all monitors. An incident is an up-to-down transition; consecutive down heartbeats belong to the same incident. Paginate with page/per_page.',
                        'readonly'     => true,
                        'destructive'  => false,
                        'idempotent'   => true,
                    ),
                ),
            )
        );
    }

    /**
     * Get input schema for list-monitor-incidents-v1.
     *
     * @return array
     */
    private static function get_monitor_incidents_input_schema(): array {
        return array(
            'type'                 => array( 'object', 'null' ),
            'properties'           => array(
                'site_id'   => array(
                    'type'        => 'integer',
                    'description' => __( 'Filter to one site by ID. 0 or omitted = all monitored sites.', 'mainwp' ),
                    'default'     => 0,
                    'minimum'     => 0,
                ),
                'date_from' => array(
                    'type'        => 'integer',
                    'description' => __( 'Range start, unix seconds. 0 = 7 days before date_to.', 'mainwp' ),
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
     * Get output schema for list-monitor-incidents-v1.
     *
     * @return array
     */
    private static function get_monitor_incidents_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'items'     => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'monitor_id' => array( 'type' => 'integer' ),
                            'site_id'    => array( 'type' => 'integer' ),
                            'url'        => array( 'type' => 'string' ),
                            'time'       => array( 'type' => 'string' ),
                            'duration_s' => array( 'type' => 'integer' ),
                            'ping_ms'    => array( 'type' => array( 'number', 'null' ) ),
                            'http_code'  => array( 'type' => 'string' ),
                            'message'    => array( 'type' => 'string' ),
                        ),
                    ),
                ),
                'total'     => array( 'type' => 'integer' ),
                'page'      => array( 'type' => 'integer' ),
                'per_page'  => array( 'type' => 'integer' ),
                'date_from' => array( 'type' => 'integer' ),
                'date_to'   => array( 'type' => 'integer' ),
                'filters'   => array( 'type' => 'object' ),
                'truncated' => array( 'type' => 'boolean' ),
                'note'      => array( 'type' => 'string' ),
            ),
        );
    }

    /**
     * Execute mainwp/list-monitor-incidents-v1.
     *
     * @param array $input Validated input.
     * @return array|\WP_Error
     */
    public static function execute_monitor_incidents( $input ) { //phpcs:ignore -- NOSONAR - complexity.
        $input    = MainWP_Abilities_Util::normalize_input( $input );
        $range    = MainWP_Abilities_Util::resolve_date_range( $input, 7, 90 );
        $site_id  = (int) ( $input['site_id'] ?? 0 );
        $page     = max( 1, (int) ( $input['page'] ?? 1 ) );
        $per_page = max( 1, min( 100, (int) ( $input['per_page'] ?? 20 ) ) );

        $monitors = self::get_scoped_monitors( $site_id );
        if ( is_wp_error( $monitors ) ) {
            return $monitors;
        }

        $start_dt = gmdate( 'Y-m-d H:i:s', $range['from'] );
        $end_dt   = gmdate( 'Y-m-d H:i:s', $range['to'] );

        // An incident is an UP→DOWN transition, not every DOWN heartbeat.
        // Each monitor contributes its newest offset+limit transitions
        // (SQL-bounded); the global sort below then produces a correct page
        // even when incidents from different monitors interleave.
        $fetch_window = min( $page * $per_page, self::MAX_INCIDENT_FETCH_WINDOW );
        $total        = 0;
        $truncated    = false;
        $incidents    = array();

        foreach ( $monitors as $monitor ) {
            $monitor_db = MainWP_DB_Uptime_Monitoring::instance();
            $count      = $monitor_db->count_site_incidents_between( (int) $monitor->monitor_id, $start_dt, $end_dt );
            // The window bounds each monitor, so only a monitor with more
            // incidents than the window loses rows; the servable total counts
            // what the merged list can actually page through.
            if ( $count > self::MAX_INCIDENT_FETCH_WINDOW ) {
                $truncated = true;
                $count     = self::MAX_INCIDENT_FETCH_WINDOW;
            }
            $total += $count;
            if ( 0 === $count ) {
                continue;
            }
            $beats = $monitor_db->get_site_incidents_paginated( (int) $monitor->monitor_id, $start_dt, $end_dt, $fetch_window, 0 );
            foreach ( (array) $beats as $beat ) {
                $incidents[] = array(
                    'monitor_id' => (int) $monitor->monitor_id,
                    'site_id'    => (int) $monitor->wpid,
                    'url'        => isset( $monitor->url ) ? (string) $monitor->url : '',
                    'time'       => isset( $beat->time ) ? (string) $beat->time : '',
                    'duration_s' => isset( $beat->duration ) ? (int) $beat->duration : 0,
                    'ping_ms'    => isset( $beat->ping_ms ) ? (float) $beat->ping_ms : null,
                    'http_code'  => isset( $beat->http_code ) ? (string) $beat->http_code : '',
                    'message'    => isset( $beat->msg ) ? (string) $beat->msg : '',
                );
            }
        }

        usort(
            $incidents,
            static function ( $a, $b ) {
                return strcmp( $b['time'], $a['time'] );
            }
        );

        $items = array_slice( $incidents, ( $page - 1 ) * $per_page, $per_page );

        // Truncation is announced rather than silent so a caller can tell a
        // complete result from one with a monitor's older incidents cut off.
        $note = '';
        if ( 0 === $total ) {
            $note = __( 'No downtime incidents in the applied date range.', 'mainwp' );
        } elseif ( $truncated ) {
            $note = sprintf(
                /* translators: %s: maximum number of incidents served per monitor. */
                __( 'A monitor has more than %s incidents in this range; only its newest %s are servable. Narrow the date range to see older incidents.', 'mainwp' ),
                number_format_i18n( self::MAX_INCIDENT_FETCH_WINDOW ),
                number_format_i18n( self::MAX_INCIDENT_FETCH_WINDOW )
            );
        }

        return array(
            'items'     => $items,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $per_page,
            'date_from' => $range['from'],
            'date_to'   => $range['to'],
            'filters'   => array( 'site_id' => $site_id ),
            'truncated' => $truncated,
            'note'      => $note,
        );
    }
}
