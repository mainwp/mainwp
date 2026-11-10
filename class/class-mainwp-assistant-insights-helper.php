<?php
/**
 * MainWP Assistant Insights Helper.
 *
 * This class handles data aggregation for the Assistant Insights dashboard,
 * querying logs and conversation data for metrics and activity display.
 *
 * @package MainWP/Dashboard
 */

namespace MainWP\Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class MainWP_Assistant_Insights_Helper
 *
 * Provides data aggregation methods for the Assistant Insights dashboard.
 *
 * @package MainWP\Dashboard
 */
class MainWP_Assistant_Insights_Helper extends MainWP_DB_Base {

    /**
     * Private static variable to hold the single instance of the class.
     *
     * @var MainWP_Assistant_Insights_Helper|null
     */
    private static $instance = null;

    /**
     * Cache group for insights data.
     *
     * @var string
     */
    private const CACHE_GROUP = 'mainwp_assistant_insights';

    /**
     * Cache expiration for summary metrics (5 minutes).
     *
     * @var int
     */
    private const SUMMARY_CACHE_EXPIRATION = 300;

    /**
     * Cache expiration for chart data (10 minutes).
     *
     * @var int
     */
    private const CHART_CACHE_EXPIRATION = 600;

    /**
     * Method instance()
     *
     * Create or return the single instance of the class.
     *
     * @return MainWP_Assistant_Insights_Helper The single instance of the class.
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    /**
     * Initialize hooks for cache invalidation.
     *
     * Should be called during plugin initialization.
     */
    public static function init() {
        add_action( 'mainwp_assistant_ability_executed', array( __CLASS__, 'invalidate_cache' ) );
        add_action( 'mainwp_assistant_ability_failed', array( __CLASS__, 'invalidate_cache' ) );
        add_action( 'mainwp_assistant_ability_confirmed', array( __CLASS__, 'invalidate_cache' ) );
        add_action( 'mainwp_assistant_chat_failed', array( __CLASS__, 'invalidate_cache' ) );
        add_action( 'mainwp_assistant_conversation_started', array( __CLASS__, 'invalidate_cache' ) );
    }

    /**
     * Check if the logs table exists.
     *
     * Provides defensive check for environments where logs module is disabled.
     *
     * @return bool True if logs table exists.
     */
    private function logs_table_exists() {
        // Memoized for the life of the request. A logs table created after this first
        // ran is not seen until the next request.
        static $exists = null;
        if ( null === $exists ) {
            $logs_table = $this->wpdb->esc_like( $this->wpdb->mainwp_tbl_logs );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Using prepare() with internal table name.
            $exists = (bool) $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $logs_table ) );
        }
        return $exists;
    }

    /**
     * Check if the logs meta table exists.
     *
     * @return bool True if logs meta table exists.
     */
    private function logs_meta_table_exists() {
        // Memoized for the life of the request, same as logs_table_exists().
        static $exists = null;
        if ( null === $exists ) {
            $logs_meta_table = $this->wpdb->esc_like( $this->wpdb->mainwp_tbl_logs_meta );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Using prepare() with internal table name.
            $exists = (bool) $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $logs_meta_table ) );
        }
        return $exists;
    }

    /**
     * Build a date-range WHERE clause for date filtering.
     *
     * Returns 0 or 1 WHERE clause strings carrying %d placeholders plus the
     * matching values, so the caller can bind them when it prepares the full
     * query. Both-sided, from-only and to-only ranges are all supported;
     * passing 0 for both returns no clause.
     *
     * @param int    $date_from        Start date as Unix timestamp, 0 to skip.
     * @param int    $date_to          End date as Unix timestamp, 0 to skip.
     * @param string $column           Column name for the date comparison.
     * @param bool   $use_microseconds Whether to convert timestamps to microseconds (logs table)
     *                                 or use raw seconds (conversations table).
     *
     * @return array {
     *     @type string[] $clauses WHERE clause fragments with %d placeholders.
     *     @type int[]    $args    Values to bind, in placeholder order.
     * }
     */
    private function build_date_where_clause( $date_from, $date_to, $column = 'lg.created', $use_microseconds = true ) {
        $clauses = array();
        $args    = array();

        // Column names are internal literals from this class, never user input.
        if ( ! in_array( $column, array( 'lg.created', 'created_at' ), true ) ) {
            return array(
                'clauses' => $clauses,
                'args'    => $args,
            );
        }

        // Microsecond columns (logs) scale by 1e6 and use an exclusive upper
        // bound to include the full final second; seconds columns use <=.
        $from_val = $use_microseconds ? (int) $date_from * 1000000 : (int) $date_from;
        $to_val   = $use_microseconds ? ( (int) $date_to + 1 ) * 1000000 : (int) $date_to;
        $upper_op = $use_microseconds ? '<' : '<=';

        if ( $date_from > 0 && $date_to > 0 ) {
            $clauses[] = "{$column} >= %d AND {$column} {$upper_op} %d";
            $args[]    = $from_val;
            $args[]    = $to_val;
        } elseif ( $date_from > 0 ) {
            $clauses[] = "{$column} >= %d";
            $args[]    = $from_val;
        } elseif ( $date_to > 0 ) {
            $clauses[] = "{$column} {$upper_op} %d";
            $args[]    = $to_val;
        }

        return array(
            'clauses' => $clauses,
            'args'    => $args,
        );
    }

    /**
     * Build the user-filter WHERE clause.
     *
     * @param int|int[]|null $user_id User ID, array of user IDs, or null for all users.
     * @param string         $column  Column name for the comparison.
     *
     * @return array {
     *     @type string[] $clauses WHERE clause fragments with %d placeholders.
     *     @type int[]    $args    Values to bind, in placeholder order.
     * }
     */
    private function build_user_where_clause( $user_id, $column = 'lg.user_id' ) {
        $clauses = array();
        $args    = array();

        // Column names are internal literals from this class, never user input.
        if ( ! in_array( $column, array( 'lg.user_id', 'user_id' ), true ) ) {
            return array(
                'clauses' => $clauses,
                'args'    => $args,
            );
        }

        if ( is_array( $user_id ) ) {
            if ( ! empty( $user_id ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $user_id ), '%d' ) );
                $clauses[]    = "{$column} IN ({$placeholders})";
                $args         = array_values( $user_id );
            }
        } elseif ( null !== $user_id ) {
            $clauses[] = "{$column} = %d";
            $args[]    = absint( $user_id );
        }

        return array(
            'clauses' => $clauses,
            'args'    => $args,
        );
    }

    /**
     * Get summary metrics for the insights dashboard.
     *
     * @param int|int[]|null $user_id   User ID, array of user IDs, or null for all users.
     * @param int            $date_from Start date as Unix timestamp.
     * @param int            $date_to   End date as Unix timestamp.
     *
     * @return array Metrics array with keys: total_executions, failed_count, confirmed_count, conversation_count.
     */
    public function get_summary_metrics( $user_id = null, $date_from = 0, $date_to = 0 ) {
        if ( ! $this->logs_table_exists() ) {
            return array(
                'total_executions'   => 0,
                'failed_count'       => 0,
                'confirmed_count'    => 0,
                'conversation_count' => 0,
            );
        }

        $cache_key = 'summary_' . md5( wp_json_encode( array( $user_id, $date_from, $date_to ) ) );
        $cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
        if ( false !== $cached ) {
            return $cached;
        }

        if ( is_array( $user_id ) && empty( $user_id ) ) {
            return array(
                'total_executions'   => 0,
                'failed_count'       => 0,
                'confirmed_count'    => 0,
                'conversation_count' => 0,
            );
        }

        $logs_table = $this->wpdb->mainwp_tbl_logs;

        $where_clauses = array( 'lg.connector = %s' );
        $where_args    = array( 'assistant' );

        $user_where    = $this->build_user_where_clause( $user_id );
        $where_clauses = array_merge( $where_clauses, $user_where['clauses'] );
        $where_args    = array_merge( $where_args, $user_where['args'] );

        $date_where    = $this->build_date_where_clause( $date_from, $date_to );
        $where_clauses = array_merge( $where_clauses, $date_where['clauses'] );
        $where_args    = array_merge( $where_args, $date_where['args'] );

        $where_sql = implode( ' AND ', $where_clauses );

        // Single query with conditional aggregation instead of 3 separate COUNT queries.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $logs_table is the internal prefixed logs table; $where_sql carries placeholders only and every value is bound by prepare() below.
        $counts = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT
                    SUM( lg.action = 'executed' )  AS total_executions,
                    SUM( lg.action = 'failed' )    AS failed_count,
                    SUM( lg.action = 'confirmed' ) AS confirmed_count
                FROM {$logs_table} lg
                WHERE {$where_sql}
                    AND lg.action IN ( 'executed', 'failed', 'confirmed' )",
                ...$where_args
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

        $total_executions = $counts ? (int) ( $counts->total_executions ?? 0 ) : 0;
        $failed_count     = $counts ? (int) ( $counts->failed_count ?? 0 ) : 0;
        $confirmed_count  = $counts ? (int) ( $counts->confirmed_count ?? 0 ) : 0;

        $conversation_count = $this->get_conversation_count( $user_id, $date_from, $date_to );

        $metrics = array(
            'total_executions'   => $total_executions,
            'failed_count'       => $failed_count,
            'confirmed_count'    => $confirmed_count,
            'conversation_count' => $conversation_count,
        );

        wp_cache_set( $cache_key, $metrics, self::CACHE_GROUP, self::SUMMARY_CACHE_EXPIRATION );

        return $metrics;
    }

    /**
     * Build the activity search WHERE clause.
     *
     * The logged message and the conversation title carry prompt text. When
     * content access is off they are hidden from the table, so they must not be
     * matched either: otherwise the filtered count would reveal retained text.
     *
     * @param string $search          Raw search term.
     * @param bool   $include_content Whether to match message and title content.
     *
     * @return array {
     *     @type string   $clause WHERE fragment with %s placeholders; requires the wp_users join as `u`.
     *     @type string[] $args   Values to bind, in placeholder order.
     * }
     */
    private function build_search_where_clause( $search, $include_content ) {
        $like            = '%' . $this->wpdb->esc_like( sanitize_text_field( $search ) ) . '%';
        $logs_meta_table = $this->wpdb->mainwp_tbl_logs_meta;
        $meta_keys       = $include_content ? "'ability', 'title'" : "'ability'";
        $item_match      = $include_content ? 'lg.item LIKE %s OR ' : '';

        // $logs_meta_table is the internal prefixed meta table and $meta_keys an
        // internal literal; the search term is bound through the placeholders.
        $clause = "({$item_match}u.display_name LIKE %s OR u.user_login LIKE %s OR lg.log_id IN (SELECT meta_log_id FROM {$logs_meta_table} WHERE meta_key IN ({$meta_keys}) AND meta_value LIKE %s))";

        return array(
            'clause' => $clause,
            'args'   => array_fill( 0, $include_content ? 4 : 3, $like ),
        );
    }

    /**
     * Query activity logs for the insights table.
     *
     * @param array $args Query arguments.
     *
     * @return array Array with 'items' and 'count' keys.
     */
    public function query_activity( $args = array() ) {
        if ( ! $this->logs_table_exists() || ! $this->logs_meta_table_exists() ) {
            return array(
                'items'       => array(),
                'count'       => 0,
                'total_count' => 0,
            );
        }

        $defaults = array(
            'user_id'          => null,
            'date_from'        => 0,
            'date_to'          => 0,
            'search'           => '',
            'search_content'   => true,
            'start'            => 0,
            'records_per_page' => 25,
            'orderby'          => 'created',
            'order'            => 'DESC',
        );

        $args = wp_parse_args( $args, $defaults );

        if ( is_array( $args['user_id'] ) && empty( $args['user_id'] ) ) {
            return array(
                'items'       => array(),
                'count'       => 0,
                'total_count' => 0,
            );
        }

        $needs_user_join = ! empty( $args['search'] ) || 'display_name' === $args['orderby'];

        $logs_table = $this->wpdb->mainwp_tbl_logs;

        $where_clauses = array(
            'lg.connector = %s',
            "lg.action IN ('started', 'executed', 'failed', 'confirmed')",
        );
        $where_args    = array( 'assistant' );

        $user_where    = $this->build_user_where_clause( $args['user_id'] );
        $where_clauses = array_merge( $where_clauses, $user_where['clauses'] );
        $where_args    = array_merge( $where_args, $user_where['args'] );

        $date_where    = $this->build_date_where_clause( $args['date_from'], $args['date_to'] );
        $where_clauses = array_merge( $where_clauses, $date_where['clauses'] );
        $where_args    = array_merge( $where_args, $date_where['args'] );

        // Snapshot base WHERE (before search) for unfiltered total count.
        $base_where_sql  = implode( ' AND ', $where_clauses );
        $base_where_args = $where_args;

        if ( ! empty( $args['search'] ) ) {
            $search_where    = $this->build_search_where_clause( $args['search'], (bool) $args['search_content'] );
            $where_clauses[] = $search_where['clause'];
            $where_args      = array_merge( $where_args, $search_where['args'] );
        }

        $where_sql = implode( ' AND ', $where_clauses );

        // Pagination. Cap per_page at 100 for performance protection.
        $start      = absint( $args['start'] );
        $per_page   = min( max( 1, absint( $args['records_per_page'] ) ), 100 );
        $limits     = 'LIMIT %d, %d';
        $limit_args = array( $start, $per_page );

        $order       = 'DESC' === strtoupper( $args['order'] ) ? 'DESC' : 'ASC';
        $orderby_map = array(
            'created' => 'lg.created',
            'action'  => 'lg.action',
        );
        if ( $needs_user_join ) {
            $orderby_map['display_name'] = 'u.display_name';
        }
        $order_col = isset( $orderby_map[ $args['orderby'] ] ) ? $orderby_map[ $args['orderby'] ] : 'lg.created';
        $order_sql = "ORDER BY {$order_col} {$order}";

        // Only JOIN wp_users when searching or sorting by a user field.
        if ( $needs_user_join ) {
            $from_sql = "{$logs_table} lg LEFT JOIN {$this->wpdb->users} u ON lg.user_id = u.ID";
        } else {
            $from_sql = "{$logs_table} lg";
        }

        // Get filtered count (with search applied).
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $from_sql/$logs_table are internal prefixed table names; the WHERE fragments carry placeholders only and every value is bound by prepare() below.
        $count = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$from_sql} WHERE {$where_sql}",
                ...$where_args
            )
        );

        // Get unfiltered total (without search) for DataTables recordsTotal.
        if ( ! empty( $args['search'] ) ) {
            $total_count = (int) $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT COUNT(*) FROM {$logs_table} lg WHERE {$base_where_sql}",
                    ...$base_where_args
                )
            );
        } else {
            $total_count = $count;
        }
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

        // Note: lg.item is aliased as 'message' for backward compatibility with downstream code.
        // lg.duration is included directly from the table (not metadata) for efficiency.
        $query_args = array_merge( $where_args, $limit_args );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $from_sql/$order_sql come from internal prefixed table names and the orderby allowlist; $where_sql and $limits carry placeholders only and every value is bound by prepare() below.
        if ( $needs_user_join ) {
            $items = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT lg.log_id, lg.user_id, COALESCE(u.display_name, '') AS display_name,
                            lg.item AS message, lg.action, lg.created, lg.state, lg.duration
                     FROM {$from_sql}
                     WHERE {$where_sql}
                     {$order_sql}
                     {$limits}",
                    ...$query_args
                )
            );
        } else {
            $items = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT lg.log_id, lg.user_id,
                            lg.item AS message, lg.action, lg.created, lg.state, lg.duration
                     FROM {$from_sql}
                     WHERE {$where_sql}
                     {$order_sql}
                     {$limits}",
                    ...$query_args
                )
            );
        }
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

        // Batch-resolve display names when the JOIN was skipped.
        if ( ! $needs_user_join && $items ) {
            // array_filter drops user_id 0 (system/cron rows), which have no wp_users row.
            $user_ids = array_unique( array_filter( wp_list_pluck( $items, 'user_id' ) ) );
            $user_map = array();
            if ( ! empty( $user_ids ) ) {
                $users = get_users(
                    array(
                        'include' => $user_ids,
                        'fields'  => array( 'ID', 'display_name' ),
                    )
                );
                foreach ( $users as $user ) {
                    $user_map[ (int) $user->ID ] = $user->display_name;
                }
            }
            foreach ( $items as $item ) {
                $item->display_name = isset( $user_map[ (int) $item->user_id ] ) ? $user_map[ (int) $item->user_id ] : '';
            }
        }

        // Enrich items with metadata.
        // Only fetch the meta keys needed for display to avoid loading large params/result JSON.
        // Note: 'duration' is now fetched directly from the table column, not metadata.
        if ( $items ) {
            $log_ids  = wp_list_pluck( $items, 'log_id' );
            $metadata = $this->get_logs_metadata(
                $log_ids,
                array( 'ability', 'conversation_id', 'title' )
            );

            foreach ( $items as $item ) {
                $item->meta = isset( $metadata[ $item->log_id ] ) ? $metadata[ $item->log_id ] : array();
                // Convert created from microseconds to seconds for display.
                $item->created_timestamp = floor( $item->created / 1000000 );
            }
        }

        return array(
            'items'       => $items,
            'count'       => $count,
            'total_count' => $total_count,
        );
    }

    /**
     * Get metadata for multiple logs.
     *
     * Retrieves metadata for the specified log IDs. When $meta_keys is provided,
     * only those specific keys are fetched, which improves performance by avoiding
     * large metadata values (like params/result JSON) that aren't needed for display.
     *
     * @param array      $log_ids   Array of log IDs.
     * @param array|null $meta_keys Optional. Specific meta keys to retrieve. If null, retrieves all.
     *
     * @return array Associative array of log_id => metadata array.
     */
    private function get_logs_metadata( $log_ids, $meta_keys = null ) {
        if ( empty( $log_ids ) || ! $this->logs_meta_table_exists() ) {
            return array();
        }

        $log_ids         = array_map( 'absint', $log_ids );
        $placeholders    = implode( ',', array_fill( 0, count( $log_ids ), '%d' ) );
        $logs_meta_table = $this->wpdb->mainwp_tbl_logs_meta;

        $query = "SELECT meta_log_id, meta_key, meta_value FROM {$logs_meta_table} WHERE meta_log_id IN ({$placeholders})";

        // If specific meta keys requested, filter to only those keys for performance.
        // This avoids loading large params/result JSON blobs when not needed.
        if ( ! empty( $meta_keys ) && is_array( $meta_keys ) ) {
            $meta_keys        = array_map( 'sanitize_key', $meta_keys );
            $key_placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
            $query           .= " AND meta_key IN ({$key_placeholders})";

            $prepare_args = array_merge( $log_ids, $meta_keys );
        } else {
            $prepare_args = $log_ids;
        }

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Query is properly prepared with dynamic placeholders.
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                $query,
                ...$prepare_args
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        $metadata = array();
        foreach ( $results as $row ) {
            if ( ! isset( $metadata[ $row->meta_log_id ] ) ) {
                $metadata[ $row->meta_log_id ] = array();
            }
            $metadata[ $row->meta_log_id ][ $row->meta_key ] = $row->meta_value;
        }

        return $metadata;
    }

    /**
     * Get top abilities by usage count.
     *
     * @param int|int[]|null $user_id   User ID, array of user IDs, or null for all users.
     * @param int            $date_from Start date.
     * @param int            $date_to   End date.
     * @param int            $limit     Maximum number of results.
     *
     * @return array Array of ability usage data.
     */
    public function get_top_abilities( $user_id = null, $date_from = 0, $date_to = 0, $limit = 10 ) {
        if ( ! $this->logs_table_exists() || ! $this->logs_meta_table_exists() ) {
            return array();
        }

        $cache_key = 'top_abilities_' . md5( wp_json_encode( array( $user_id, $date_from, $date_to, $limit ) ) );
        $cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
        if ( false !== $cached ) {
            return $cached;
        }

        if ( is_array( $user_id ) && empty( $user_id ) ) {
            return array();
        }

        $logs_table      = $this->wpdb->mainwp_tbl_logs;
        $logs_meta_table = $this->wpdb->mainwp_tbl_logs_meta;

        // Count 'executed' and 'failed' to reflect total ability usage.
        // Exclude 'confirmed' to avoid double-counting (confirmed abilities also log 'executed').
        $where_clauses = array(
            'lg.connector = %s',
            "lg.action IN ('executed', 'failed')",
        );
        $where_args    = array( 'assistant' );

        $user_where    = $this->build_user_where_clause( $user_id );
        $where_clauses = array_merge( $where_clauses, $user_where['clauses'] );
        $where_args    = array_merge( $where_args, $user_where['args'] );

        $date_where    = $this->build_date_where_clause( $date_from, $date_to );
        $where_clauses = array_merge( $where_clauses, $date_where['clauses'] );
        $where_args    = array_merge( $where_args, $date_where['args'] );

        $where_sql    = implode( ' AND ', $where_clauses );
        $where_args[] = absint( $limit );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $logs_table/$logs_meta_table are the internal prefixed log tables; $where_sql carries placeholders only and every value, the limit included, is bound by prepare() below.
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT m.meta_value as ability, COUNT(*) as count
                 FROM {$logs_table} lg
                 INNER JOIN {$logs_meta_table} m ON lg.log_id = m.meta_log_id AND m.meta_key = 'ability'
                 WHERE {$where_sql}
                 GROUP BY m.meta_value
                 ORDER BY count DESC
                 LIMIT %d",
                ...$where_args
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

        $abilities = array();
        foreach ( $results as $row ) {
            $abilities[] = array(
                'ability' => $row->ability,
                'label'   => $this->format_ability_name( $row->ability ),
                'count'   => (int) $row->count,
            );
        }

        wp_cache_set( $cache_key, $abilities, self::CACHE_GROUP, self::CHART_CACHE_EXPIRATION );

        return $abilities;
    }

    /**
     * Get provider usage breakdown.
     *
     * @param int|int[]|null $user_id   User ID, array of user IDs, or null for all users.
     * @param int            $date_from Start date.
     * @param int            $date_to   End date.
     *
     * @return array Array of provider usage data.
     */
    public function get_provider_usage( $user_id = null, $date_from = 0, $date_to = 0 ) {
        if ( ! $this->logs_table_exists() || ! $this->logs_meta_table_exists() ) {
            return array();
        }

        $cache_key = 'provider_usage_' . md5( wp_json_encode( array( $user_id, $date_from, $date_to ) ) );
        $cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
        if ( false !== $cached ) {
            return $cached;
        }

        if ( is_array( $user_id ) && empty( $user_id ) ) {
            return array();
        }

        $logs_table      = $this->wpdb->mainwp_tbl_logs;
        $logs_meta_table = $this->wpdb->mainwp_tbl_logs_meta;

        // Count each interaction once: 'started' for stateful conversations,
        // 'executed'/'failed'/'confirmed' only for stateless calls (no conversation_id).
        // Stateless rows have no conversation_id meta (IS NULL) or a legacy value of '0'.
        // 'confirmed' is listed even though stateless calls do not emit it today, so the
        // count stays right if they start to.
        $where_clauses = array(
            'lg.connector = %s',
            "(lg.action = 'started' OR (lg.action IN ('executed', 'failed', 'confirmed') AND (m_conv.meta_value IS NULL OR m_conv.meta_value = '0')))",
        );
        $where_args    = array( 'assistant' );

        $user_where    = $this->build_user_where_clause( $user_id );
        $where_clauses = array_merge( $where_clauses, $user_where['clauses'] );
        $where_args    = array_merge( $where_args, $user_where['args'] );

        $date_where    = $this->build_date_where_clause( $date_from, $date_to );
        $where_clauses = array_merge( $where_clauses, $date_where['clauses'] );
        $where_args    = array_merge( $where_args, $date_where['args'] );

        $where_sql = implode( ' AND ', $where_clauses );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $logs_table/$logs_meta_table are the internal prefixed log tables; $where_sql carries placeholders only and every value is bound by prepare() below.
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT COALESCE(m.meta_value, 'unknown') as provider, COUNT(*) as count
                 FROM {$logs_table} lg
                 LEFT JOIN {$logs_meta_table} m ON lg.log_id = m.meta_log_id AND m.meta_key = 'provider_type'
                 LEFT JOIN {$logs_meta_table} m_conv ON lg.log_id = m_conv.meta_log_id AND m_conv.meta_key = 'conversation_id'
                 WHERE {$where_sql}
                 GROUP BY provider
                 ORDER BY count DESC",
                ...$where_args
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

        $total = 0;
        foreach ( $results as $row ) {
            $total += (int) $row->count;
        }

        $providers = array();
        foreach ( $results as $row ) {
            $count       = (int) $row->count;
            $percentage  = $total > 0 ? round( ( $count / $total ) * 100, 1 ) : 0;
            $providers[] = array(
                'provider'   => $row->provider,
                'label'      => $this->format_provider_name( $row->provider ),
                'count'      => $count,
                'percentage' => $percentage,
            );
        }

        wp_cache_set( $cache_key, $providers, self::CACHE_GROUP, self::CHART_CACHE_EXPIRATION );

        return $providers;
    }

    /**
     * Check if the conversations table exists.
     *
     * Memoized for the life of the request, same as logs_table_exists().
     *
     * @return bool True if the conversations table exists.
     */
    private function conversations_table_exists() {
        static $exists = null;
        if ( null === $exists ) {
            $conversations_table = $this->wpdb->esc_like( $this->table_name( 'assistant_conversations' ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is properly prepared.
            $exists = (bool) $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $conversations_table ) );
        }
        return $exists;
    }

    /**
     * Get conversation count for a user within date range.
     *
     * @param int|int[]|null $user_id   User ID, array of user IDs, or null for all users.
     * @param int            $date_from Start date.
     * @param int            $date_to   End date.
     *
     * @return int Conversation count.
     */
    private function get_conversation_count( $user_id = null, $date_from = 0, $date_to = 0 ) {
        if ( ! $this->conversations_table_exists() ) {
            return 0;
        }

        if ( is_array( $user_id ) && empty( $user_id ) ) {
            return 0;
        }

        $conversations_table = $this->table_name( 'assistant_conversations' );

        $where_clauses = array( '1=1' );
        $where_args    = array();

        $user_where    = $this->build_user_where_clause( $user_id, 'user_id' );
        $where_clauses = array_merge( $where_clauses, $user_where['clauses'] );
        $where_args    = array_merge( $where_args, $user_where['args'] );

        $date_where    = $this->build_date_where_clause( $date_from, $date_to, 'created_at', false );
        $where_clauses = array_merge( $where_clauses, $date_where['clauses'] );
        $where_args    = array_merge( $where_args, $date_where['args'] );

        $where_sql = implode( ' AND ', $where_clauses );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $conversations_table is the internal prefixed table; $where_sql carries placeholders only and every value is bound by prepare() below.
        $query = "SELECT COUNT(*) FROM {$conversations_table} WHERE {$where_sql}";

        // With no user or date filter the WHERE is the literal 1=1, so there is nothing
        // to bind and prepare() would warn about a query with no placeholder.
        $count = (int) $this->wpdb->get_var(
            empty( $where_args ) ? $query : $this->wpdb->prepare( $query, ...$where_args )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

        return $count;
    }

    /**
     * Format ability name for display.
     *
     * Converts 'mainwp/list-sites-v1' to 'List Sites'.
     *
     * @param string $ability_id Ability ID.
     *
     * @return string Formatted ability name.
     */
    public function format_ability_name( $ability_id ) {
        if ( empty( $ability_id ) ) {
            return esc_html__( 'Unknown', 'mainwp' );
        }

        $name = preg_replace( '/^mainwp\//', '', $ability_id );

        $name = preg_replace( '/-v\d+$/', '', $name );

        $name = str_replace( '-', ' ', $name );
        $name = ucwords( $name );

        return $name;
    }

    /**
     * Format provider name for display.
     *
     * @param string $provider Provider type.
     *
     * @return string Formatted provider name.
     */
    private function format_provider_name( $provider ) {
        $providers = array(
            'openai'    => 'OpenAI',
            'anthropic' => 'Anthropic',
            'azure'     => 'Azure OpenAI',
            'ollama'    => 'Ollama',
            'bridge'    => 'MainWP Docs',
            'auto'      => esc_html__( 'Auto (routed)', 'mainwp' ),
            'custom'    => esc_html__( 'Custom', 'mainwp' ),
            'unknown'   => esc_html__( 'Unknown', 'mainwp' ),
        );

        return isset( $providers[ $provider ] ) ? $providers[ $provider ] : ucfirst( $provider );
    }

    /**
     * Get conversation durations for a set of conversation IDs.
     *
     * Computes duration as (updated_at - created_at) in milliseconds from the
     * conversations table. Returns an associative array keyed by conversation ID.
     *
     * @param array $conv_ids Array of conversation IDs.
     *
     * @return array Associative array of conversation_id => duration_in_ms.
     */
    public function get_conversation_durations( $conv_ids ) {
        if ( empty( $conv_ids ) ) {
            return array();
        }

        if ( ! $this->conversations_table_exists() ) {
            return array();
        }

        $conversations_table = $this->table_name( 'assistant_conversations' );

        $conv_ids = array_map( 'absint', $conv_ids );
        $conv_ids = array_filter( $conv_ids ); // Remove zeroes (stateless entries).
        if ( empty( $conv_ids ) ) {
            return array();
        }
        $placeholders = implode( ',', array_fill( 0, count( $conv_ids ), '%d' ) );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Dynamic placeholders, properly prepared.
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT id, created_at, updated_at FROM {$conversations_table} WHERE id IN ({$placeholders})",
                ...$conv_ids
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

        $durations = array();
        foreach ( $results as $row ) {
            $durations[ $row->id ] = (int) ( ( $row->updated_at - $row->created_at ) * 1000 );
        }

        return $durations;
    }

    /**
     * Get abilities executed within a conversation.
     *
     * Queries the logs and logs_meta tables to find all ability executions
     * (executed, confirmed, failed) associated with a conversation ID.
     *
     * @param int $conversation_id Conversation ID.
     *
     * @return array Array of objects with ability, ability_label, action, duration_ms, created_timestamp.
     */
    public function get_conversation_abilities( $conversation_id ) {
        if ( empty( $conversation_id ) || ! $this->logs_table_exists() || ! $this->logs_meta_table_exists() ) {
            return array();
        }

        $logs_table      = $this->wpdb->mainwp_tbl_logs;
        $logs_meta_table = $this->wpdb->mainwp_tbl_logs_meta;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Internal table names from wpdb; values go through prepare().
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT lg.log_id, lg.action, lg.duration, lg.created,
                        m_ability.meta_value AS ability
                 FROM {$logs_table} lg
                 INNER JOIN {$logs_meta_table} m_conv
                     ON m_conv.meta_log_id = lg.log_id
                     AND m_conv.meta_key = 'conversation_id'
                     AND m_conv.meta_value = %s
                 LEFT JOIN {$logs_meta_table} m_ability
                     ON m_ability.meta_log_id = lg.log_id
                     AND m_ability.meta_key = 'ability'
                 WHERE lg.connector = 'assistant'
                     AND lg.context = 'abilities'
                     AND lg.action IN ('executed','confirmed','failed')
                 ORDER BY lg.created ASC",
                (string) $conversation_id
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

        $abilities = array();
        foreach ( $results as $row ) {
            $obj                    = new \stdClass();
            $obj->ability           = $row->ability ?? '';
            $obj->ability_label     = $this->format_ability_name( $obj->ability );
            $obj->action            = $row->action;
            $obj->duration_ms       = (int) round( ( $row->duration ?? 0 ) * 1000 );
            $obj->created_timestamp = (int) floor( $row->created / 1000000 );
            $abilities[]            = $obj;
        }

        return $abilities;
    }

    /**
     * Invalidate all insights caches.
     *
     * Called when new assistant activity is logged.
     */
    public static function invalidate_cache() {
        // Use wp_cache_flush_group if available (WordPress 6.1+).
        if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
            wp_cache_flush_group( self::CACHE_GROUP );
        }
        // Note: If flush_group is not supported, cached data will expire naturally.
        // This is acceptable for insights data which has short TTLs (5-10 minutes).
    }
}
