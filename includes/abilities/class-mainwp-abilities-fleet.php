<?php
/**
 * MainWP Fleet Abilities
 *
 * @package MainWP\Dashboard
 */

namespace MainWP\Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Abilities_Fleet
 *
 * Fleet-wide segment and distribution abilities:
 * - mainwp/query-sites-segment-v1: Sites matching version/health/sync threshold filters
 * - mainwp/get-fleet-versions-v1: WP / PHP / MySQL version distributions
 */
class MainWP_Abilities_Fleet { //phpcs:ignore -- NOSONAR - multi methods.

    /**
     * Register all fleet abilities.
     *
     * @return void
     */
    public static function register(): void {
        if ( ! function_exists( 'wp_register_ability' ) ) {
            return;
        }
        self::register_sites_segment();
        self::register_fleet_versions();
    }

    /**
     * Collect one normalized row per site for segment/distribution work.
     *
     * @return array[] Rows: id, url, name, php, wp, mysql, dts_sync, health_value, suspended, has_sync_error.
     */
    private static function collect_fleet_rows(): array {
        $rows = array();
        // Restricted callers get their allowed sites only. The DB layer's own
        // scoping is skipped under REST/cron/CLI, so the trim runs here against
        // the direct-filter set rather than relying on the query's WHERE.
        $allowed_sites = MainWP_Abilities_Util::get_allowed_site_ids();
        $sql           = MainWP_DB::instance()->get_sql_websites_for_current_user( false, null, 'wp.url', false, false, null, false, array( 'site_info', 'phpversion' ) );
        $websites      = MainWP_DB::instance()->query( $sql );

        while ( $websites && ( $website = MainWP_DB::fetch_object( $websites ) ) ) { //phpcs:ignore -- NOSONAR - assignment in loop follows core pattern.
            if ( 'all' !== $allowed_sites && ! in_array( (int) $website->id, $allowed_sites, true ) ) {
                continue;
            }
            $site_info = ! empty( $website->site_info ) ? json_decode( $website->site_info, true ) : array();
            if ( ! is_array( $site_info ) ) {
                $site_info = array();
            }
            $rows[] = array(
                'id'             => (int) $website->id,
                'url'            => (string) $website->url,
                'name'           => (string) $website->name,
                'php'            => ! empty( $website->phpversion ) ? (string) $website->phpversion : ( $site_info['phpversion'] ?? '' ),
                'wp'             => $site_info['wpversion'] ?? '',
                'mysql'          => $site_info['mysql_version'] ?? '',
                'dts_sync'       => (int) $website->dtsSync,
                'health_value'   => (int) $website->health_value,
                'suspended'      => ! empty( $website->suspended ),
                'has_sync_error' => '' !== (string) $website->sync_errors,
            );
        }

        return $rows;
    }

    /**
     * Register mainwp/query-sites-segment-v1 ability.
     *
     * @return void
     */
    private static function register_sites_segment(): void {
        wp_register_ability(
            'mainwp/query-sites-segment-v1',
            array(
                'label'               => __( 'Query Sites Segment', 'mainwp' ),
                'description'         => __( 'Find sites matching fleet thresholds: PHP/WP/MySQL version below X, site health below a score, not synced in N days, sync errors, suspended state. Answers "which sites are on PHP < 8.1" style questions.', 'mainwp' ),
                'category'            => 'mainwp-fleet',
                'input_schema'        => self::get_sites_segment_input_schema(),
                'output_schema'       => self::get_sites_segment_output_schema(),
                'execute_callback'    => array( self::class, 'execute_sites_segment' ),
                'permission_callback' => array( MainWP_Abilities_Util::class, 'check_rest_api_permission' ),
                'meta'                => array(
                    'mainwp_team_control' => true,
                    'show_in_rest'        => true,
                    'annotations'         => array(
                        'instructions' => 'Empty string / 0 / "any" means "no filter" for every property. Filters combine with AND. Sites with an unknown value for a version filter are excluded from matches and reported in unknown_counts.',
                        'readonly'     => true,
                        'destructive'  => false,
                        'idempotent'   => true,
                    ),
                ),
            )
        );
    }

    /**
     * Get input schema for query-sites-segment-v1.
     *
     * @return array
     */
    private static function get_sites_segment_input_schema(): array {
        return array(
            'type'                 => array( 'object', 'null' ),
            'properties'           => array(
                'php_below'       => array(
                    'type'        => 'string',
                    'description' => __( 'Match sites with PHP version below this (e.g. "8.1"). Empty = no PHP filter.', 'mainwp' ),
                    'default'     => '',
                ),
                'wp_below'        => array(
                    'type'        => 'string',
                    'description' => __( 'Match sites with WordPress version below this (e.g. "6.5"). Empty = no WP filter.', 'mainwp' ),
                    'default'     => '',
                ),
                'mysql_below'     => array(
                    'type'        => 'string',
                    'description' => __( 'Match sites with MySQL/MariaDB version below this. Empty = no MySQL filter.', 'mainwp' ),
                    'default'     => '',
                ),
                'health_below'    => array(
                    'type'        => 'integer',
                    'description' => __( 'Match sites with a Site Health score below this (1-100). 0 = no health filter.', 'mainwp' ),
                    'default'     => 0,
                    'minimum'     => 0,
                    'maximum'     => 100,
                ),
                'not_synced_days' => array(
                    'type'        => 'integer',
                    'description' => __( 'Match sites not synced in this many days (never-synced sites match). 0 = no sync-age filter.', 'mainwp' ),
                    'default'     => 0,
                    'minimum'     => 0,
                ),
                'has_sync_error'  => array(
                    'type'        => 'string',
                    'description' => __( 'Filter by sync-error state. Use "any" for no filter.', 'mainwp' ),
                    'enum'        => array( 'any', 'yes', 'no' ),
                    'default'     => 'any',
                ),
                'suspended'       => array(
                    'type'        => 'string',
                    'description' => __( 'Filter by suspended state. Use "any" for no filter.', 'mainwp' ),
                    'enum'        => array( 'any', 'yes', 'no' ),
                    'default'     => 'any',
                ),
                'page'            => array(
                    'type'        => 'integer',
                    'description' => __( 'Page number (1-based).', 'mainwp' ),
                    'default'     => 1,
                    'minimum'     => 1,
                ),
                'per_page'        => array(
                    'type'        => 'integer',
                    'description' => __( 'Items per page.', 'mainwp' ),
                    'default'     => 50,
                    'minimum'     => 1,
                    'maximum'     => 100,
                ),
            ),
            'additionalProperties' => false,
        );
    }

    /**
     * Get output schema for query-sites-segment-v1.
     *
     * @return array
     */
    private static function get_sites_segment_output_schema(): array {
        return array(
            'type'       => 'object',
            'properties' => array(
                'items'          => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'id'             => array( 'type' => 'integer' ),
                            'url'            => array( 'type' => 'string' ),
                            'name'           => array( 'type' => 'string' ),
                            'php_version'    => array( 'type' => 'string' ),
                            'wp_version'     => array( 'type' => 'string' ),
                            'mysql_version'  => array( 'type' => 'string' ),
                            'health_value'   => array( 'type' => 'integer' ),
                            'last_sync'      => array( 'type' => array( 'string', 'null' ) ),
                            'suspended'      => array( 'type' => 'boolean' ),
                            'has_sync_error' => array( 'type' => 'boolean' ),
                        ),
                    ),
                ),
                'total'          => array( 'type' => 'integer' ),
                'page'           => array( 'type' => 'integer' ),
                'per_page'       => array( 'type' => 'integer' ),
                'filters'        => array( 'type' => 'object' ),
                'unknown_counts' => array(
                    'type'        => 'object',
                    'description' => __( 'Sites skipped per version filter because the value is unknown (never synced).', 'mainwp' ),
                ),
                'note'           => array( 'type' => 'string' ),
            ),
        );
    }

    /**
     * Execute mainwp/query-sites-segment-v1.
     *
     * @param array $input Validated input.
     * @return array|\WP_Error
     */
    public static function execute_sites_segment( $input ) { //phpcs:ignore -- NOSONAR - complexity.
        $input    = MainWP_Abilities_Util::normalize_input( $input );
        $page     = max( 1, (int) ( $input['page'] ?? 1 ) );
        $per_page = max( 1, min( 100, (int) ( $input['per_page'] ?? 50 ) ) );

        $php_below       = trim( (string) ( $input['php_below'] ?? '' ) );
        $wp_below        = trim( (string) ( $input['wp_below'] ?? '' ) );
        $mysql_below     = trim( (string) ( $input['mysql_below'] ?? '' ) );
        $health_below    = (int) ( $input['health_below'] ?? 0 );
        $not_synced_days = (int) ( $input['not_synced_days'] ?? 0 );
        $has_sync_error  = (string) ( $input['has_sync_error'] ?? 'any' );
        $suspended       = (string) ( $input['suspended'] ?? 'any' );

        $unknown = array(
            'php_below'   => 0,
            'wp_below'    => 0,
            'mysql_below' => 0,
        );

        $matched = array();
        foreach ( self::collect_fleet_rows() as $row ) {
            if ( '' !== $php_below ) {
                if ( '' === $row['php'] ) {
                    ++$unknown['php_below'];
                    continue;
                }
                if ( ! version_compare( $row['php'], $php_below, '<' ) ) {
                    continue;
                }
            }
            if ( '' !== $wp_below ) {
                if ( '' === $row['wp'] ) {
                    ++$unknown['wp_below'];
                    continue;
                }
                if ( ! version_compare( $row['wp'], $wp_below, '<' ) ) {
                    continue;
                }
            }
            if ( '' !== $mysql_below ) {
                if ( '' === $row['mysql'] ) {
                    ++$unknown['mysql_below'];
                    continue;
                }
                if ( ! version_compare( $row['mysql'], $mysql_below, '<' ) ) {
                    continue;
                }
            }
            if ( $health_below > 0 && $row['health_value'] >= $health_below ) {
                continue;
            }
            if ( $not_synced_days > 0 && $row['dts_sync'] >= time() - $not_synced_days * DAY_IN_SECONDS ) {
                continue;
            }
            if ( 'yes' === $has_sync_error && ! $row['has_sync_error'] ) {
                continue;
            }
            if ( 'no' === $has_sync_error && $row['has_sync_error'] ) {
                continue;
            }
            if ( 'yes' === $suspended && ! $row['suspended'] ) {
                continue;
            }
            if ( 'no' === $suspended && $row['suspended'] ) {
                continue;
            }

            $matched[] = array(
                'id'             => $row['id'],
                'url'            => $row['url'],
                'name'           => $row['name'],
                'php_version'    => $row['php'],
                'wp_version'     => $row['wp'],
                'mysql_version'  => $row['mysql'],
                'health_value'   => $row['health_value'],
                'last_sync'      => $row['dts_sync'] > 0 ? gmdate( 'c', $row['dts_sync'] ) : null,
                'suspended'      => $row['suspended'],
                'has_sync_error' => $row['has_sync_error'],
            );
        }

        $total = count( $matched );
        $items = array_slice( $matched, ( $page - 1 ) * $per_page, $per_page );

        $filters = array(
            'php_below'       => $php_below,
            'wp_below'        => $wp_below,
            'mysql_below'     => $mysql_below,
            'health_below'    => $health_below,
            'not_synced_days' => $not_synced_days,
            'has_sync_error'  => $has_sync_error,
            'suspended'       => $suspended,
        );

        return array(
            'items'          => $items,
            'total'          => $total,
            'page'           => $page,
            'per_page'       => $per_page,
            'filters'        => $filters,
            'unknown_counts' => $unknown,
            'note'           => 0 === $total ? __( 'No sites matched the applied filters.', 'mainwp' ) : '',
        );
    }

    /**
     * Register mainwp/get-fleet-versions-v1 ability.
     *
     * @return void
     */
    private static function register_fleet_versions(): void {
        wp_register_ability(
            'mainwp/get-fleet-versions-v1',
            array(
                'label'               => __( 'Get Fleet Versions', 'mainwp' ),
                'description'         => __( 'WordPress, PHP, and MySQL version distributions across all sites, bucketed by major.minor. Answers "what versions is my fleet running" questions in one call.', 'mainwp' ),
                'category'            => 'mainwp-fleet',
                'input_schema'        => array(
                    'type'                 => array( 'object', 'null' ),
                    'properties'           => array(),
                    'additionalProperties' => false,
                ),
                'output_schema'       => self::get_fleet_versions_output_schema(),
                'execute_callback'    => array( self::class, 'execute_fleet_versions' ),
                'permission_callback' => array( MainWP_Abilities_Util::class, 'check_rest_api_permission' ),
                'meta'                => array(
                    'mainwp_team_control' => true,
                    'show_in_rest'        => true,
                    'annotations'         => array(
                        'instructions' => 'Takes no input. Sites whose value is not yet known (never synced) are counted under version "unknown".',
                        'readonly'     => true,
                        'destructive'  => false,
                        'idempotent'   => true,
                    ),
                ),
            )
        );
    }

    /**
     * Get output schema for get-fleet-versions-v1.
     *
     * @return array
     */
    private static function get_fleet_versions_output_schema(): array {
        $bucket_list = array(
            'type'  => 'array',
            'items' => array(
                'type'       => 'object',
                'properties' => array(
                    'version' => array( 'type' => 'string' ),
                    'count'   => array( 'type' => 'integer' ),
                ),
            ),
        );
        return array(
            'type'       => 'object',
            'properties' => array(
                'wp'          => $bucket_list,
                'php'         => $bucket_list,
                'mysql'       => $bucket_list,
                'total_sites' => array( 'type' => 'integer' ),
            ),
        );
    }

    /**
     * Bucket a version string to major.minor, or "unknown".
     *
     * @param string $version Raw version string.
     * @return string
     */
    private static function minor_version_bucket( string $version ): string {
        $version = trim( $version );
        if ( '' === $version ) {
            return 'unknown';
        }
        $parts = explode( '.', $version );
        return implode( '.', array_slice( $parts, 0, 2 ) );
    }

    /**
     * Execute mainwp/get-fleet-versions-v1.
     *
     * @param array $input Validated input (empty).
     * @return array
     */
    public static function execute_fleet_versions( $input ) { //phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by callback signature.
        $buckets = array(
            'wp'    => array(),
            'php'   => array(),
            'mysql' => array(),
        );
        $total   = 0;

        foreach ( self::collect_fleet_rows() as $row ) {
            ++$total;
            foreach ( array( 'wp', 'php', 'mysql' ) as $metric ) {
                $bucket = self::minor_version_bucket( (string) $row[ $metric ] );
                if ( ! isset( $buckets[ $metric ][ $bucket ] ) ) {
                    $buckets[ $metric ][ $bucket ] = 0;
                }
                ++$buckets[ $metric ][ $bucket ];
            }
        }

        $output = array( 'total_sites' => $total );
        foreach ( $buckets as $metric => $counts ) {
            arsort( $counts );
            $list = array();
            foreach ( $counts as $version => $count ) {
                $list[] = array(
                    'version' => (string) $version,
                    'count'   => (int) $count,
                );
            }
            $output[ $metric ] = $list;
        }

        return $output;
    }
}
